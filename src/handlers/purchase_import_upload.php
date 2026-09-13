<?php
declare(strict_types=1);

/**
 * src/handlers/purchase_import_upload.php — POST /api/purchase/import  (multipart/form-data)
 *   (정책: purchase.master.write → buyer·Admin 만, **재인증 대상**)
 *
 * ★ 19단계 엑셀 가져오기 **1단계: 파일** (PRD F-29 · T-44, 시안 ⑯, SECURITY R-67).
 *
 * 순서
 *   [1] 파일 필드 → [2] `Upload::validate` (t-box 업로드 검사 **그대로**: 확장자 화이트리스트·
 *       이중 확장자·MIME 짝·크기·sha256) → [3] 가져오기용 확장자(xlsx·csv)로 한 번 더 좁힘 →
 *       [4] 민감정보 검출 `Sensitive::scan` (F-22) → [5] 보관 폴더 「구매/가져오기」 확보 →
 *       [6] 트랜잭션(files INSERT + 파일 이동 + 감사) → [7] `Sheet::rows` 로 머리행·표본 5행 →
 *       [8] 저장된 매핑 후보와 함께 응답
 *
 * `.xlsm`·`.xls`·매크로 문서는 [2] 에서 끝난다 — `Upload::ALLOWED_EXT` 에 없는 확장자이고,
 * 확장자를 `.xlsx` 로 바꿔 올려도 finfo MIME 짝 검사(`mime_mismatch`)가 잡는다.
 * **가져오기를 위해 업로드 규칙을 완화하지 않았다** — 두 번째 입구를 만들지 않는다는 원칙
 * (ARCH 5.6 [8] 과 같은 판단).
 *
 * 왜 파일을 `files` 에 남기나 (지시서 보충 9)
 *   "무엇을 근거로 1.3만 행을 바꿨나" 가 나중에 필요하다. 원본이 남아 있어야 감사 로그의
 *   sha256 과 대조할 수 있다. 폴더는 **admin-only** 라 거래처 명세서·단가가 일반 사용자에게
 *   보이지 않는다 (SECURITY R-69).
 *
 * 지점 격리 예외 — 가져오기 파일은 지점에 묶이지 않는다. 보관 폴더는 admin-only 한 곳이고,
 *   이 파일의 `FROM p_` 조회는 저장된 **매핑 목록**(p_import_mappings, 지점 무관 마스터)뿐이다.
 *   행 판정·쓰기의 범위는 preview·commit 핸들러와 Importer 가 다룬다.
 *
 * @var array<string,mixed> $user
 * @var list<string>        $params 경로 변수 없음
 */

require_once __DIR__ . '/../Sensitive.php';
require_once __DIR__ . '/../purchase/Sheet.php';
require_once __DIR__ . '/../purchase/Importer.php';

/*
 * 보관 폴더 이름은 `Importer` 의 상수 하나를 쓴다 — 여기서 다시 적으면 두 벌이 되고,
 * 미리보기·확정이 파일을 찾는 조건(Importer::findFile)과 어긋나는 순간 "올렸는데 못 읽는"
 * 상태가 된다. 요청 값이 이 자리에 닿는 경로는 없다(코드 상수다).
 */

$reject = static function (string $reason, int $status, string $message, ?string $name = null): void {
    $entry = [
        'target_type'  => 'file',
        'target_name'  => $name,
        'access_level' => 'admin-only',
    ];
    /* R-71 (2026-09-13) — 위장 파일로 거부할 때는 **무엇으로 감지됐는지**를 함께 남긴다.
     * files_upload.php 와 같은 방식이다. 이 진단이 없으면 "이카운트 xlsx 가 거부됐다" 는
     * 신고를 받고도 원인을 알 수 없다(실제로 2026-09-13 에 그런 사고가 있었다).
     * 값은 finfo 가 돌려준 MIME 문자열과 앞 8바이트 16진수이며 비밀이 없다. */
    if ($reason === 'mime_mismatch' && Upload::$lastDetectedMime !== null) {
        $entry['detail'] = ['mime' => Upload::$lastDetectedMime];
    }
    Audit::deny('purchase.import.upload', $reason, $entry);
    Http::error($status, $message);
};

// ─── [1] 파일 필드 ───
$uploaded = $_FILES['file'] ?? null;
if (!is_array($uploaded)) {
    $reject('no_file', 400, Http::MSG_BAD_REQUEST);
}

/** 원본 파일명 — basename → 제어문자 제거 → 길이 제한. **경로에는 한 글자도 쓰이지 않는다** */
$rawName  = is_string($uploaded['name'] ?? null) ? (string) $uploaded['name'] : '';
$original = basename(str_replace('\\', '/', $rawName));
$original = (string) preg_replace('/[\x00-\x1f\x7f]/', '', $original);
if ($original === '' || preg_match('//u', $original) !== 1) {
    $reject('bad_encoding', 400, Http::MSG_BAD_REQUEST);
}
if (iconv_strlen($original, 'UTF-8') > 255) {
    $cut      = iconv_substr($original, 0, 255, 'UTF-8');
    $original = is_string($cut) ? $cut : $original;
}

// ─── [2] 업로드 검사 — 본체와 **같은 함수**다 (PRD S-06) ───
try {
    $checked = Upload::validate($uploaded);
} catch (RuntimeException $e) {
    $reject($e->getMessage(), 400, Http::MSG_BAD_REQUEST, $original);
    exit;
}

// ─── [3] 가져오기가 읽을 수 있는 형식인가 (xlsx · csv) ───
if (!in_array($checked['ext'], Sheet::SHEET_EXT, true)) {
    $reject('ext_not_allowed', 400, Http::MSG_BAD_REQUEST, $original);
}

$tmpPath = (string) $uploaded['tmp_name'];

// ─── [4] 민감정보 검출 (PRD F-22, ARCH 5.13) — 본체 업로드와 같은 자리·같은 함수 ───
// 보관 폴더가 admin-only 라 등급 때문에 막히는 일은 없다. 검출 사실만 남긴다.
$sensitive      = Sensitive::scan($tmpPath, $checked['ext']);
$sensitiveFlags = array_keys($sensitive);

$db = Db::instance();

// ─── [5] 보관 폴더 「구매 / 가져오기」 (admin-only). 없으면 만든다 ───
$sqlFindFolder = <<<'SQL'
    SELECT id, depth, access_level, team_id
      FROM folders
     WHERE parent_id IS NOT DISTINCT FROM CAST(:parent AS bigint)
       AND name = :name
    SQL;

$sqlMakeFolder = <<<'SQL'
    INSERT INTO folders (parent_id, name, depth, access_level, team_id, created_by)
    VALUES (CAST(:parent AS bigint), :name, CAST(:depth AS int), 'admin-only', NULL, :created_by)
    RETURNING id
    SQL;

/**
 * 폴더 하나를 확보한다 — 있으면 그 id, 없으면 만든다(만든 사실은 감사 `folder.create allow`
 * 로 남고 actor 는 요청자다, 지시서 보충 9).
 */
$ensureFolder = static function (?int $parentId, string $name, int $depth) use ($db, $sqlFindFolder, $sqlMakeFolder, $user): int {
    $row = $db->run($sqlFindFolder, [':parent' => $parentId, ':name' => $name])->fetch();
    if (is_array($row)) {
        return (int) $row['id'];
    }
    $made = $db->run($sqlMakeFolder, [
        ':parent'     => $parentId,
        ':name'       => $name,
        ':depth'      => $depth,
        ':created_by' => (int) $user['id'],
    ])->fetch();
    if (!is_array($made)) {
        throw new RuntimeException('가져오기 폴더를 만들지 못했습니다');
    }
    $newId = (int) $made['id'];
    Audit::allow('folder.create', [
        'target_type'  => 'folder',
        'target_id'    => $newId,
        'target_name'  => $name,
        'access_level' => 'admin-only',
        'detail'       => [
            'granted_by'  => Authz::CODE_ADMIN_ALL,
            'folder_id'   => $newId,
            'parent_id'   => $parentId,
            'depth'       => $depth,
            'owner_id'    => (int) $user['id'],
            'target_team' => null,
        ],
    ]);
    return $newId;
};

$storedName = Storage::newStoredName($checked['ext']);

$sqlInsertFile = <<<'SQL'
    INSERT INTO files
        (original_name, stored_name, ext, mime, size_bytes, sha256,
         description, access_level, team_id, uploaded_by, sensitive_flags, folder_id)
    VALUES
        (:original_name, :stored_name, :ext, :mime, :size_bytes, :sha256,
         :description, 'admin-only', NULL, :uploaded_by,
         CAST(:flags AS text[]), CAST(:folder_id AS bigint))
    RETURNING id, public_id
    SQL;

// ─── [6] 트랜잭션: 폴더 확보 → files INSERT → 파일 이동 → 감사 → COMMIT ───
$moved = false;
$db->begin();
try {
    $parentId = $ensureFolder(null, Importer::FOLDER_PARENT, 1);
    $folderId = $ensureFolder($parentId, Importer::FOLDER_CHILD, 2);

    $row = $db->run($sqlInsertFile, [
        ':original_name' => $original,
        ':stored_name'   => $storedName,
        ':ext'           => $checked['ext'],
        ':mime'          => $checked['mime'],
        ':size_bytes'    => $checked['size'],
        ':sha256'        => $checked['sha256'],
        ':description'   => '구매 기초정보 가져오기 원본',
        ':uploaded_by'   => (int) $user['id'],
        ':flags'         => '{' . implode(',', $sensitiveFlags) . '}',
        ':folder_id'     => $folderId,
    ])->fetch();

    if (!is_array($row)) {
        throw new RuntimeException('가져오기 파일을 저장하지 못했습니다');
    }
    $fileId   = (int) $row['id'];
    $publicId = (string) $row['public_id'];

    Storage::put($tmpPath, $storedName);
    $moved = true;

    Audit::allow('purchase.import.upload', [
        'target_type'  => 'file',
        'target_id'    => $fileId,
        'target_name'  => $original,
        'access_level' => 'admin-only',
        'detail'       => [
            'file_id'   => $fileId,
            'sha256'    => $checked['sha256'],
            'ext'       => $checked['ext'],
            'folder_id' => $folderId,
        ] + ($sensitive === [] ? [] : ['sensitive' => $sensitive]),
    ]);
    $db->commit();
} catch (Throwable $e) {
    $db->rollback();
    if ($moved) {
        try {
            Storage::removeUploaded($storedName);
        } catch (Throwable $ignored) {
            error_log('[tbox] request_id=' . TBOX_REQUEST_ID . ' 가져오기 업로드 롤백 중 파일 삭제 실패');
        }
    }
    throw $e;
}

// ─── [7] 머리행·표본 5행 (저장이 끝난 뒤 읽는다 — 추출 실패가 저장을 되돌리지 않게) ───
try {
    $sheet = Sheet::rows(Storage::pathFor($storedName), $checked['ext']);
} catch (Throwable $e) {
    Audit::error('purchase.import.upload', 'parse_failed', [
        'target_type' => 'file',
        'target_id'   => $fileId,
        'target_name' => $original,
        'detail'      => ['file_id' => $fileId],
    ]);
    Http::error(400, Http::MSG_BAD_REQUEST);
    exit;
}

/* ─── 머리행은 **서버가 감지한다** (1차 보정 2026-09-13) ───
 *
 * 19단계 본 판은 `rows[0]` 을 머리행으로 못박았다. 이카운트 품목 내보내기는 0행이 제목
 * 한 칸(`회사명 : …`)이고 진짜 머리행이 1행이라, 열 목록이 한 칸뿐이었고 사람이 매핑을
 * 채울 수 없었다 → 미리보기가 `mapping_incomplete` 400 (2026-09-13 05:37 실측).
 *
 * 감지는 **종류마다 다르다**(동의어 표가 다르므로). 화면이 종류를 바꿔도 재업로드 없이
 * 고쳐 볼 수 있게, 종류별 감지 결과와 앞 20행 원문(`head_rows`)을 함께 내려 준다.
 * `columns`·`sample` 은 **화면이 처음 고를 종류(items)의 감지 결과** 기준이다 —
 * 화면이 종류를 바꾸면 head_rows 로 다시 그린다.
 */
$headerRows = [];
foreach (Importer::KINDS as $k) {
    $headerRows[$k] = Importer::detectHeaderRow($k, $sheet['rows']);
}
$headRows  = Importer::headRows($sheet['rows']);
$headerRow = $headerRows[Importer::KINDS[0]];

$header = $sheet['rows'][$headerRow] ?? [];
$sample = array_slice($sheet['rows'], $headerRow + 1, 5);

// ─── [8] 저장된 매핑 — 같은 양식을 다시 올리면 2단계로 끝난다 (F-29) ───
// lint:no-branch-scope p_import_mappings 는 지점 무관 마스터다 (브랜드·거래처·분류와 같다).
$sqlMappings = <<<'SQL'
    SELECT id, kind, name, mapping_json, header_row
      FROM p_import_mappings
     WHERE is_active
     ORDER BY kind ASC, name ASC
     LIMIT 50
    SQL;

$saved = [];
foreach ($db->run($sqlMappings)->fetchAll() as $m) {
    $decoded = json_decode((string) $m['mapping_json'], true);
    $saved[] = [
        'id'      => (int) $m['id'],
        'kind'    => (string) $m['kind'],
        'name'    => (string) $m['name'],
        'mapping' => is_array($decoded) ? $decoded : [],
        // 저장된 매핑은 **머리행 위치도 함께** 기억한다 — 그래야 같은 양식이 두 걸음으로 끝난다
        'header_row' => (int) $m['header_row'],
    ];
}

// 머리행을 보고 만든 **후보** 매핑(종류별). 서버는 이 값을 판정에 쓰지 않는다.
// 종류마다 머리행 감지 결과가 다를 수 있으므로 **각자의 머리행**으로 추측한다.
$guess = [];
foreach (Importer::KINDS as $kind) {
    $guess[$kind] = Importer::guessMapping($kind, $sheet['rows'][$headerRows[$kind]] ?? []);
}

Http::json([
    'import_id' => $publicId,
    'file_name' => $original,
    'sha256'    => $checked['sha256'],
    // 데이터 행 수 — 머리행과 그 위의 제목 줄을 뺀 수다
    'rows'      => max(0, count($sheet['rows']) - $headerRow - 1),
    'truncated' => $sheet['truncated'],
    // 감지한 머리행(0기준). 화면이 셀렉트의 기본값으로 쓰고, 사람이 바꿀 수 있다.
    'header_row'  => $headerRow,
    'header_rows' => $headerRows,
    // 앞 20행 원문 — 화면이 **재업로드 없이** 머리행을 바꿔 볼 재료 (textContent 로만 그린다)
    'head_rows'   => $headRows,
    'columns'   => $header,
    'sample'    => $sample,
    'mapping'   => $guess,
    'saved'     => $saved,
    'targets'   => Importer::TARGETS,
    'required'  => Importer::REQUIRED,
], 201);
