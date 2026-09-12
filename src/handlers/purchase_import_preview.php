<?php
declare(strict_types=1);

/**
 * src/handlers/purchase_import_preview.php — POST /api/purchase/import/{id}/preview
 *   (정책: purchase.master.write → buyer·Admin 만, **재인증 대상**)
 *
 * ★ 19단계 엑셀 가져오기 **3단계: 미리보기** (PRD F-29 · T-44, 시안 ⑯, SECURITY R-67).
 *
 * 받는 값 (JSON, 허용 키 밖이면 400)
 *   kind        items | vendors | locations
 *   mapping     {엑셀 열 이름 → 우리 칸 이름}   ("" 또는 "skip" 은 건너뜀)
 *   header_row  (선택) 머리행의 위치 0~19. **그 다음 행부터 데이터**다.
 *               생략하면 서버가 `Importer::detectHeaderRow` 로 다시 감지한다 —
 *               화면이 값을 안 보내도 동작이 달라지지 않게 (1차 보정 2026-09-13).
 *
 * 돌려주는 값
 *   counts  {insert, update, review, error}  — **전체** 행 기준
 *   rows    앞 200행의 판정 (전부 내려 주면 1.3만 줄짜리 JSON 이 된다)
 *   errors  오류·확인 행만 따로 (사람이 고칠 목록)
 *
 * **DB 를 읽기만 한다.** 이 창구로는 아무것도 저장되지 않는다 — 확정은 commit 이다.
 *
 * ★ 파일을 고르는 규칙 (정보 노출 차단)
 *   `{id}` 는 `files.public_id` 다. 그런데 이 라우트에 닿는 사람(buyer)은 본체에서 Admin 이
 *   아닐 수 있고, 보관 폴더의 파일은 `admin-only` 다. 아무 UUID 나 넣어 남의 admin-only
 *   문서를 표로 뽑아 가는 창구가 되지 않도록, **「구매/가져오기」 폴더 안의 xlsx·csv 만**
 *   받는다. 폴더가 다르거나 없는 id 는 똑같이 404 다 (PRD S-09-5 — 있고 없고를 알려 주지 않는다).
 *
 * lint:no-branch-scope 가져오기 파일과 매핑은 지점에 묶이지 않는다 — 보관 폴더는 admin-only
 *   한 곳이고, 판정이 읽는 기초정보(거래처·분류·품목)는 지점 무관 마스터다. 장소 가져오기의
 *   지점은 **파일에 적힌 지점 이름**으로 찾으며, 그 쓰기 자격은 buyer·Admin(전 지점)뿐이다.
 *
 * @var array<string,mixed> $user
 * @var list<string>        $params $params[0] = files.public_id (UUID)
 */

require_once __DIR__ . '/../purchase/Sheet.php';
require_once __DIR__ . '/../purchase/Master.php';
require_once __DIR__ . '/../purchase/Importer.php';

/**
 * 거부 기록 후 끝낸다.
 *
 * ★ 사유 코드는 **ARCH 7.4 문장 표에 등록된 것만** 쓸 수 있다 — 없는 조합을 기록하면
 *   기록 자체가 실패하고 요청이 500 이 된다(그것이 설계다: 새 사유를 만든 사람이 표를
 *   빠뜨리면 첫 요청에서 드러난다). 1차 보정에서 더한 `bad_header_row` 는 아직 표에 없으므로
 *   **등록된 `bad_request` 로 남기고 구체적인 사유는 `detail.why` 에 적는다** —
 *   진단은 되고 문장 표는 깨지지 않는다. (표에 행을 더하려면 src/Audit.php 를 고쳐야 하는데,
 *   1차 보정의 허용 파일이 아니다.)
 */
$reject = static function (string $reason, int $status, string $message, array $detail = []): void {
    $entry = ['target_type' => 'file'];
    if ($detail !== []) {
        $entry['detail'] = $detail;
    }
    Audit::deny('purchase.import.preview', $reason, $entry);
    Http::error($status, $message);
};

/** 표에 등록된 사유만 통과시키고, 나머지는 `bad_request` 로 돌린다 (위 ★ 주석) */
$knownReason = static function (string $why): string {
    return in_array($why, ['bad_kind', 'bad_mapping', 'mapping_incomplete',
                           'too_many_rows', 'parse_failed'], true) ? $why : 'bad_request';
};

$file = Importer::findFile($params[0] ?? '');
if ($file === null) {
    Http::error(404, Http::MSG_NOT_FOUND);
}

$body = Http::jsonBody();
if ($body === [] || array_diff(array_keys($body), ['kind', 'mapping', 'header_row']) !== []) {
    $reject('bad_request', 400, Http::MSG_BAD_REQUEST);
}

$kind = $body['kind'] ?? '';
if (!is_string($kind) || !in_array($kind, Importer::KINDS, true)) {
    $reject('bad_kind', 400, Http::MSG_BAD_REQUEST);
}
$mapping = $body['mapping'] ?? null;
if (!is_array($mapping)) {
    $reject('bad_mapping', 400, Http::MSG_BAD_REQUEST);
}

// ─── 추출 → 매핑 확인 → 판정 ───
try {
    $sheet = Sheet::rows(Storage::pathFor((string) $file['stored_name']), (string) $file['ext']);
} catch (Throwable $e) {
    $reject('parse_failed', 400, Http::MSG_BAD_REQUEST);
    exit;
}

if ($sheet['truncated']) {
    // 상한에서 끊긴 파일은 미리보기는 보여 주되, 확정은 commit 이 400 으로 막는다.
    // 여기서 끊긴 사실을 숨기면 "13,482행이라더니 2만 행만 들어갔다" 가 된다.
    Audit::error('purchase.import.preview', 'truncated', [
        'target_type' => 'file',
        'target_id'   => (int) $file['id'],
        'target_name' => (string) $file['original_name'],
        'detail'      => ['file_id' => (int) $file['id'], 'kind' => $kind],
    ]);
}

try {
    // 머리행 — 요청이 정한 값이 우선이고, 없으면 서버가 감지한다. 0~19 밖은 예외(400).
    $headerRow = Importer::headerRowFrom($body['header_row'] ?? null, $kind, $sheet['rows']);
    $resolved  = Importer::resolve($kind, $sheet['rows'][$headerRow] ?? [], $mapping);
    $judged    = Importer::judge($kind, $sheet['rows'], $resolved, $headerRow);
} catch (RuntimeException $e) {
    $why = $e->getMessage();
    $reject($knownReason($why), 400, Http::MSG_BAD_REQUEST, ['why' => $why]);
    exit;
}

// 응답용으로 줄인다 — `values` 는 서버 내부 값이라 내려 주지 않는다(화면은 원본 칸만 본다).
$slim = static function (array $row): array {
    return [
        'row_no' => (int) $row['row_no'],
        'status' => (string) $row['status'],
        'reason' => $row['reason'] === null ? null : (string) $row['reason'],
        'data'   => is_array($row['data'] ?? null) ? $row['data'] : [],
    ];
};

$rows    = [];
$attention = [];
foreach ($judged['rows'] as $row) {
    if (count($rows) < Importer::PREVIEW_ROWS) {
        $rows[] = $slim($row);
    }
    if (($row['status'] === Importer::STATUS_ERROR || $row['status'] === Importer::STATUS_REVIEW)
        && count($attention) < Importer::PREVIEW_ROWS) {
        $attention[] = $slim($row);
    }
}

Http::json([
    'import_id'  => (string) $file['public_id'],
    'kind'       => $kind,
    // 어느 행을 머리행으로 보고 판정했나 — 화면이 「2행을 머리행으로 봤습니다」 를 보여 준다
    'header_row' => $headerRow,
    'total'      => $judged['total'],
    'truncated' => $sheet['truncated'],
    'counts'    => $judged['counts'],
    'rows'      => $rows,
    'attention' => $attention,
]);
