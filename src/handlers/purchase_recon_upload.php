<?php
declare(strict_types=1);

/**
 * src/handlers/purchase_recon_upload.php — POST /api/purchase/recon  (multipart/form-data)
 *   (정책: purchase.buy.write → buyer·Admin)
 *
 * ★ 22단계 거래처 월 대사 **1단계: 명세서 파일** (PRD F-35 · T-47 (b), 시안 Recon,
 *   SECURITY R-69).
 *
 * 받는 값 (multipart)
 *   vendor_id  거래처 id
 *   month      'YYYY-MM'
 *   file       명세서 (xlsx · csv · pdf)
 *
 * 순서 — 19단계 가져오기(`purchase_import_upload.php`)와 **같은 길**이다. 업로드 검사도
 * 보관 폴더도 새로 만들지 않는다 (두 번째 입구를 만들지 않는다는 원칙).
 *   [1] 값 → [2] `Upload::validate` → [3] 대사가 읽을 수 있는 확장자로 좁힘 →
 *   [4] `Sensitive::scan`(F-22) → [5] 보관 폴더 「구매/명세서/거래처/월」(admin-only) 확보 →
 *   [6] 트랜잭션(files INSERT + 파일 이동 + p_recon_statements INSERT + 감사) →
 *   [7] 표 읽기(`Recon::rows`) → [8] 머리행·열 후보 응답
 *
 * ★ R-69 — 여기서 **저장되는 것은 파일과 「어느 거래처의 몇 월 명세서인가」뿐**이다.
 *   줄 값(품목·수량·단가·금액)은 확정(commit) 때 들어가고, 그 다섯 열 말고는 담을 컬럼이
 *   `p_recon_lines` 에 없다. 보관 폴더는 admin-only 라 거래처 단가가 일반 사용자에게
 *   보이지 않는다.
 *
 * lint:no-branch-scope 명세서는 **거래처**에 묶이고 지점에 묶이지 않는다(한 장에 여러 지점
 *   입고가 섞여 있는 것이 정상이다 — 시안 Recon 의 「지점」 열). 이 파일의 `FROM p_` 조회는
 *   거래처가 있는지 보는 한 문장뿐이고(지점 무관 마스터, 19단계 매핑과 같은 성격),
 *   **대사 결과에 들어갈 우리 입고 줄의 지점 격리는** 미리보기·확정 핸들러가
 *   `Recon::ourLines($db, Authz::purchaseScopeWhere(...), …)` 로 건다.
 *
 * @var array<string,mixed> $user
 * @var list<string>        $params 경로 변수 없음
 */

require_once __DIR__ . '/../Sensitive.php';
require_once __DIR__ . '/../purchase/Recon.php';

/** 대사가 읽을 수 있는 파일 (PDF 는 Preview 가 뽑아 주는 **글자**만 본다 — 새 라이브러리 없음) */
const TBOX_RECON_EXT = ['xlsx', 'csv', 'pdf'];

$reject = static function (string $reason, int $status, ?string $name = null): void {
    $entry = [
        'target_type'  => 'file',
        'target_name'  => $name,
        'access_level' => 'admin-only',
    ];
    // R-71 과 같은 이유로 위장 파일 거부에는 감지값을 함께 남긴다 (진단 불가를 막는다).
    if ($reason === 'mime_mismatch' && Upload::$lastDetectedMime !== null) {
        $entry['detail'] = ['mime' => Upload::$lastDetectedMime];
    }
    Audit::deny('purchase.recon.upload', $reason, $entry);
    Http::error($status, Http::MSG_BAD_REQUEST);
};

// ─── [1] 값 ───
$vendorRaw = $_POST['vendor_id'] ?? null;
$monthRaw  = $_POST['month'] ?? null;

if (!is_string($vendorRaw) || preg_match('/^[0-9]{1,9}$/', $vendorRaw) !== 1 || (int) $vendorRaw < 1) {
    $reject('bad_request', 400);
}
if (!is_string($monthRaw) || preg_match('/^[0-9]{4}-(0[1-9]|1[0-2])$/', $monthRaw) !== 1) {
    $reject('bad_request', 400);
}
$vendorId = (int) $vendorRaw;
$month    = (string) $monthRaw;

$uploaded = $_FILES['file'] ?? null;
if (!is_array($uploaded)) {
    $reject('no_file', 400);
}

/** 원본 파일명 — basename → 제어문자 제거 → 길이 제한. **경로에는 한 글자도 쓰이지 않는다** */
$rawName  = is_string($uploaded['name'] ?? null) ? (string) $uploaded['name'] : '';
$original = basename(str_replace('\\', '/', $rawName));
$original = (string) preg_replace('/[\x00-\x1f\x7f]/', '', $original);
if ($original === '' || preg_match('//u', $original) !== 1) {
    $reject('bad_encoding', 400);
}
if (iconv_strlen($original, 'UTF-8') > 255) {
    $cut      = iconv_substr($original, 0, 255, 'UTF-8');
    $original = is_string($cut) ? $cut : $original;
}

// ─── [2] 업로드 검사 — 본체와 **같은 함수**다 (PRD S-06) ───
try {
    $checked = Upload::validate($uploaded);
} catch (RuntimeException $e) {
    $reject($e->getMessage(), 400, $original);
    exit;
}

// ─── [3] 대사가 읽을 수 있는 형식인가 ───
if (!in_array($checked['ext'], TBOX_RECON_EXT, true)) {
    $reject('ext_not_allowed', 400, $original);
}

$tmpPath = (string) $uploaded['tmp_name'];

// ─── [4] 민감정보 검출 (PRD F-22, ARCH 5.13) — 본체 업로드와 같은 자리·같은 함수 ───
// 명세서에 담당자 연락처가 섞여 오는 일이 R-69 의 출발점이다. 보관 폴더가 admin-only 라
// 등급 때문에 막히지는 않지만, **검출 사실**은 감사 로그에 남는다.
$sensitive      = Sensitive::scan($tmpPath, $checked['ext']);
$sensitiveFlags = array_keys($sensitive);

$db = Db::instance();

// ─── 거래처가 있는가 (지점 무관 마스터) ───
$sqlVendor = <<<'SQL'
    SELECT id, name FROM p_vendors WHERE id = CAST(:id AS bigint) AND is_active
    SQL;
$vendorRow = $db->run($sqlVendor, [':id' => $vendorId])->fetch();
if (!is_array($vendorRow)) {
    $reject('bad_reference', 400, $original);
}
$vendorName = (string) $vendorRow['name'];

/**
 * 폴더 이름 칸은 50자다(`folders_name_len`). 긴 거래처 이름은 폴더 이름으로 쓸 때만 자른다 —
 * 표에 저장되는 거래처 이름은 건드리지 않는다. 자르기는 UTF-8 경계로 한다(Audit 과 같은 규칙).
 */
$folderName = $vendorName;
if (iconv_strlen($folderName, 'UTF-8') > 50) {
    $shorter    = iconv_substr($folderName, 0, 50, 'UTF-8');
    $folderName = is_string($shorter) ? $shorter : $folderName;
}

// ─── [5] 보관 폴더 「구매 / 명세서 / 거래처 / 월」 (admin-only). 없으면 만든다 ───
$sqlFindFolder = <<<'SQL'
    SELECT id FROM folders
     WHERE parent_id IS NOT DISTINCT FROM CAST(:parent AS bigint)
       AND name = :name
    SQL;

$sqlMakeFolder = <<<'SQL'
    INSERT INTO folders (parent_id, name, depth, access_level, team_id, created_by)
    VALUES (CAST(:parent AS bigint), :name, CAST(:depth AS int), 'admin-only', NULL, :created_by)
    RETURNING id
    SQL;

/**
 * 폴더 하나를 확보한다 — 19단계 가져오기와 **같은 모양**이다.
 * 이름은 DB 에서 읽은 거래처 이름과 검사를 마친 월 문자열이며, **파일 경로에는 쓰이지 않는다**
 * (실제 저장 이름은 `Storage::newStoredName` 이 만든다 — PRD S-06).
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
        throw new RuntimeException('명세서 보관 폴더를 만들지 못했습니다');
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
         description, access_level, team_id, uploaded_by, sensitive_flags, folder_id,
         scan_status)
    VALUES
        (:original_name, :stored_name, :ext, :mime, :size_bytes, :sha256,
         :description, 'admin-only', NULL, :uploaded_by,
         CAST(:flags AS text[]), CAST(:folder_id AS bigint),
         :scan_status)
    RETURNING id
    SQL;

$sqlInsertStatement = <<<'SQL'
    INSERT INTO p_recon_statements (vendor_id, month, file_id, status, created_by)
    VALUES (CAST(:vendor AS bigint), CAST(:month AS char(7)), CAST(:file AS bigint),
            'open', CAST(:by AS bigint))
    RETURNING id
    SQL;

// ─── [6] 트랜잭션 ───
$moved = false;
$db->begin();
try {
    $rootId   = $ensureFolder(null, Recon::FOLDER_ROOT, 1);
    $stmtId   = $ensureFolder($rootId, Recon::FOLDER_STATEMENT, 2);
    $vendorFd = $ensureFolder($stmtId, $folderName, 3);
    $folderId = $ensureFolder($vendorFd, $month, 4);

    $fileRow = $db->run($sqlInsertFile, [
        ':original_name' => $original,
        ':stored_name'   => $storedName,
        ':ext'           => $checked['ext'],
        ':mime'          => $checked['mime'],
        ':size_bytes'    => $checked['size'],
        ':sha256'        => $checked['sha256'],
        ':description'   => '거래처 명세서 원본',
        ':uploaded_by'   => (int) $user['id'],
        ':flags'         => '{' . implode(',', $sensitiveFlags) . '}',
        ':folder_id'     => $folderId,
        // F-54 ① (ARCH 5.28) — 명세서 원본도 같은 규칙으로 저장한다.
        ':scan_status'   => Upload::initialScanStatus(),
    ])->fetch();
    if (!is_array($fileRow)) {
        throw new RuntimeException('명세서 파일을 저장하지 못했습니다');
    }
    $fileId = (int) $fileRow['id'];

    Storage::put($tmpPath, $storedName);
    $moved = true;

    $stRow = $db->run($sqlInsertStatement, [
        ':vendor' => $vendorId,
        ':month'  => $month,
        ':file'   => $fileId,
        ':by'     => (int) $user['id'],
    ])->fetch();
    if (!is_array($stRow)) {
        throw new RuntimeException('명세서를 등록하지 못했습니다');
    }
    $statementId = (int) $stRow['id'];

    Audit::allow('purchase.recon.upload', [
        'target_type'  => 'file',
        'target_id'    => $fileId,
        'target_name'  => $original,
        'access_level' => 'admin-only',
        'detail'       => [
            'statement_id' => $statementId,
            'vendor_id'    => $vendorId,
            'month'        => $month,
            'file_id'      => $fileId,
            'sha256'       => $checked['sha256'],
            'ext'          => $checked['ext'],
            'folder_id'    => $folderId,
        ] + ($sensitive === [] ? [] : ['sensitive' => $sensitive]),
    ]);
    $db->commit();
} catch (Throwable $e) {
    $db->rollback();
    if ($moved) {
        try {
            Storage::removeUploaded($storedName);
        } catch (Throwable $ignored) {
            error_log('[tbox] request_id=' . TBOX_REQUEST_ID . ' 명세서 업로드 롤백 중 파일 삭제 실패');
        }
    }
    throw $e;
}

// ─── [7] 표 읽기 (저장이 끝난 뒤 — 추출 실패가 저장을 되돌리지 않게) ───
try {
    $sheet = Recon::rows(Storage::pathFor($storedName), $checked['ext']);
} catch (Throwable $e) {
    Audit::error('purchase.recon.upload', 'parse_failed', [
        'target_type' => 'file',
        'target_id'   => $fileId,
        'target_name' => $original,
        'detail'      => ['statement_id' => $statementId, 'file_id' => $fileId],
    ]);
    Http::error(400, Http::MSG_BAD_REQUEST);
    exit;
}

$headerRow = Recon::detectHeaderRow($sheet['rows']);
$header    = $sheet['rows'][$headerRow] ?? [];

// ─── [8] 머리행·열 후보 ───
Http::json([
    'statement_id' => $statementId,
    'vendor_id'    => $vendorId,
    'vendor_name'  => $vendorName,
    'month'        => $month,
    'file_name'    => $original,
    'sha256'       => $checked['sha256'],
    'kind'         => $sheet['kind'],
    'rows'         => max(0, count($sheet['rows']) - $headerRow - 1),
    'truncated'    => $sheet['truncated'],
    'header_row'   => $headerRow,
    // 앞 20줄 원문 — 화면이 재업로드 없이 머리행을 바꿔 볼 재료 (textContent 로만 그린다)
    'head_rows'    => array_slice($sheet['rows'], 0, Recon::MAX_HEADER_ROW),
    'columns'      => $header,
    'sample'       => array_slice($sheet['rows'], $headerRow + 1, 5),
    'mapping'      => Recon::guessMapping($header),
    'targets'      => Recon::TARGETS,
    'required'     => Recon::REQUIRED,
    // 글자가 한 자도 안 나온 PDF 는 여기서 알린다 — "0건 일치" 로 보고하지 않는다.
    'text_empty'   => $sheet['rows'] === [],
], 201);
