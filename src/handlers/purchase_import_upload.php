<?php
declare(strict_types=1);

/**
 * src/handlers/purchase_import_upload.php — POST /api/purchase/import  (multipart/form-data)
 *   (정책: purchase.master.write → buyer·Admin 만, **재인증 대상**)
 *
 * ★ 19단계 엑셀 가져오기 **1단계: 파일** (PRD F-29 · T-44, 시안 ⑯, SECURITY R-67).
 *
 * 순서
 *   [1~6] `Importer::storeUploaded()` — 파일 필드 → `Upload::validate` (t-box 업로드 검사
 *         **그대로**: 확장자 화이트리스트·이중 확장자·MIME 짝·크기·sha256) → 가져오기용
 *         확장자(xlsx·csv)로 한 번 더 좁힘 → 민감정보 검출 `Sensitive::scan` (F-22) →
 *         보관 폴더 「구매/가져오기」 확보 → 트랜잭션(files INSERT + 파일 이동 + 감사)
 *   [7] `Sheet::rows` 로 머리행·표본 5행 → [8] 저장된 매핑 후보와 함께 응답
 *
 * ★ 24단계(PRD F-37) — [1~6] 은 **창고 대기표 업로드와 같은 한 함수**다
 *   (`Importer::storeUploaded`, 지시서 보충 1). 두 벌로 두면 언젠가 한쪽만 고쳐지고,
 *   그 한쪽이 곧 두 번째 입구다. 거부 사유 문자열도 그 함수 하나에서만 나온다.
 *
 * `.xlsm`·`.xls`·매크로 문서는 `Upload::validate` 에서 끝난다 — `Upload::ALLOWED_EXT` 에 없는
 * 확장자이고, 확장자를 `.xlsx` 로 바꿔 올려도 finfo MIME 짝 검사(`mime_mismatch`)가 잡는다.
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

// ─── [1~6] 업로드 검사·보관 (두 입구가 함께 쓰는 함수) ───
$stored = Importer::storeUploaded($_FILES['file'] ?? null, $user, 'purchase.import.upload');

$fileId   = $stored['file_id'];
$publicId = $stored['public_id'];
$original = $stored['original'];

// ─── [7] 머리행·표본 5행 (저장이 끝난 뒤 읽는다 — 추출 실패가 저장을 되돌리지 않게) ───
try {
    $sheet = Sheet::rows(Storage::pathFor($stored['stored_name']), $stored['ext']);
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

$db = Db::instance();

// ─── [8] 저장된 매핑 — 같은 양식을 다시 올리면 2단계로 끝난다 (F-29) ───
// lint:no-branch-scope p_import_mappings 는 지점 무관 마스터다 (브랜드·거래처·분류와 같다).
$sqlMappings = <<<'SQL'
    SELECT id, kind, name, mapping_json, header_row, options_json
      FROM p_import_mappings
     WHERE is_active
     ORDER BY kind ASC, name ASC
     LIMIT 50
    SQL;

$saved = [];
foreach ($db->run($sqlMappings)->fetchAll() as $m) {
    $decoded = json_decode((string) $m['mapping_json'], true);
    // 9차 보정 — 그때 켜 두었던 옵션도 함께 내려 준다(화면이 체크까지 되살린다).
    $options = json_decode((string) $m['options_json'], true);
    $saved[] = [
        'id'      => (int) $m['id'],
        'kind'    => (string) $m['kind'],
        'name'    => (string) $m['name'],
        'mapping' => is_array($decoded) ? $decoded : [],
        // 저장된 매핑은 **머리행 위치도 함께** 기억한다 — 그래야 같은 양식이 두 걸음으로 끝난다
        'header_row' => (int) $m['header_row'],
        'options'    => (object) (is_array($options) ? $options : []),
    ];
}

/* ─── 자동 적용 후보 `match` (9차 보정 2026-09-14) ───
 *
 * 사용자 결정: 「앞으로 해당 양식으로 자동 마이그레이션 가능하도록」. 매달 같은 이카운트
 * 파일을 올리면서 종류·머리행·열 15개·체크 둘을 매번 다시 고르는 것은 두 걸음이 아니다.
 *
 * 고르는 규칙 — 저장된 매핑의 **원본 열 이름이 그 매핑의 머리행에 전부 있으면** 후보다.
 * (열이 하나라도 빠진 파일은 후보가 아니다 — 그대로 적용하면 `bad_mapping` 400 이 난다.)
 * 둘 이상이면 **이어 준 열이 가장 많은** 것을 고른다(더 구체적인 양식이 이긴다).
 *
 * ★ 서버는 이 값을 **판정에 쓰지 않는다.** 화면 편의일 뿐이고, 실제 미리보기·확정은 언제나
 *   요청이 보낸 매핑·머리행·옵션으로만 돈다. 그래서 이 계산이 틀려도 저장되는 것은 달라지지
 *   않는다 — 사람이 2 걸음(「열 맞추기 보기」)에서 언제든 고쳐 볼 수 있다.
 */
$matchId    = null;
$matchScore = -1;
foreach ($saved as $m) {
    $row = $sheet['rows'][$m['header_row']] ?? [];
    if (!is_array($row) || $m['mapping'] === []) {
        continue;
    }
    $labels = [];
    foreach ($row as $label) {
        $text = trim((string) $label);
        if ($text !== '') {
            $labels[$text] = true;
        }
    }
    $all   = true;
    $score = 0;
    foreach (array_keys($m['mapping']) as $column) {
        if (!isset($labels[(string) $column])) {
            $all = false;
            break;
        }
        $score++;
    }
    if ($all && $score > $matchScore) {
        $matchId    = $m['id'];
        $matchScore = $score;
    }
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
    'sha256'    => $stored['sha256'],
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
    /* 9차 보정 — 이 파일에 **그대로 맞는** 저장 매핑의 id (없으면 null).
     * 화면은 이 값이 있으면 매핑·머리행·옵션을 적용하고 미리보기로 바로 간다. 서버는 쓰지 않는다. */
    'match'     => $matchId,
    'targets'   => Importer::TARGETS,
    'required'  => Importer::REQUIRED,
], 201);
