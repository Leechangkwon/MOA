<?php
declare(strict_types=1);

/**
 * src/handlers/purchase_recon_preview.php — POST /api/purchase/recon/{num}/preview
 *   (정책: purchase.buy.write)
 *
 * ★ 22단계 거래처 월 대사 **2단계: 열 매핑 → 대조 결과 미리보기**
 *   (PRD F-35 · T-47 (b), 시안 Recon).
 *
 * 본문 { mapping: {item_code?,item_name,qty,unit_price?,amount?}, header_row?: int }
 *
 * **아무것도 저장하지 않는다.** 사람이 열 매핑을 바꿔 가며 결과를 보는 단계이고, 확정은
 * `…/commit` 이 한 트랜잭션으로 한다 (19단계 가져오기 미리보기와 같은 모양).
 *
 * ★ R-69 — 매핑에 올 수 있는 키는 `Recon::TARGETS` 다섯뿐이다. 「담당자」·「연락처」·
 *   「계좌」 같은 열 이름을 적으면 `cleanMapping` 이 **요청 전체를 거부**한다 (400 bad_mapping).
 *
 * 지점 격리 (SECURITY R-65)
 *   우리 입고 줄은 `Recon::ourLines($db, Authz::purchaseScopeWhere($user, 'd'), …)` 로만
 *   읽는다 — 범위 밖 지점의 입고는 애초에 목록에 없으므로, 그 지점 줄은 명세서 쪽에서 보면
 *   `missing_ours` 가 된다(남의 지점 금액이 새지 않는다).
 *
 * @var array<string,mixed> $user
 * @var list<string>        $params $params[0] = p_recon_statements.id
 */

require_once __DIR__ . '/../purchase/Recon.php';

$idRaw = $params[0] ?? '';
if (!is_string($idRaw) || preg_match('/^[0-9]{1,9}$/', $idRaw) !== 1) {
    Http::error(404, Http::MSG_NOT_FOUND);
}
$statementId = (int) $idRaw;

$db   = Db::instance();
$body = Http::jsonBody();

$reject = static function (string $reason) use ($statementId): void {
    Audit::deny('purchase.recon.preview', $reason, [
        'target_type' => 'purchase_recon',
        'target_id'   => $statementId,
        'target_name' => (string) $statementId,
        'detail'      => ['statement_id' => $statementId],
    ]);
    Http::error(400, Http::MSG_BAD_REQUEST);
};

if ($body === [] || array_diff(array_keys($body), ['mapping', 'header_row']) !== []) {
    $reject('bad_request');
}

$mapping = Recon::cleanMapping($body['mapping'] ?? null);
if ($mapping === null) {
    $reject('bad_mapping');
}

$headerRow = $body['header_row'] ?? 0;
if (is_string($headerRow) && preg_match('/^[0-9]{1,2}$/', $headerRow) === 1) {
    $headerRow = (int) $headerRow;
}
if (!is_int($headerRow) || $headerRow < 0 || $headerRow >= Recon::MAX_HEADER_ROW) {
    $reject('bad_header_row');
}

$statement = Recon::statement($db, $statementId);
if ($statement === null) {
    Http::error(404, Http::MSG_NOT_FOUND);
}

try {
    $sheet = Recon::rows(Storage::pathFor((string) $statement['stored_name']), (string) $statement['ext']);
} catch (Throwable $e) {
    $reject('parse_failed');
    exit;
}

$theirs = Recon::parse($sheet['rows'], $mapping, $headerRow);
$ours   = Recon::ourLines(
    $db,
    Authz::purchaseScopeWhere($user, 'd'),
    (int) $statement['vendor_id'],
    (string) $statement['month']
);
$result = Recon::match($theirs, $ours);

Audit::allow('purchase.recon.preview', [
    'target_type' => 'purchase_recon',
    'target_id'   => $statementId,
    'target_name' => (string) $statement['original_name'],
    'detail'      => [
        'statement_id' => $statementId,
        'vendor_id'    => (int) $statement['vendor_id'],
        'month'        => (string) $statement['month'],
        'lines'        => count($result['lines']),
        'diff_counts'  => $result['diff_counts'],
    ],
]);

Http::json([
    'statement_id' => $statementId,
    'vendor_id'    => (int) $statement['vendor_id'],
    'vendor_name'  => (string) $statement['vendor_name'],
    'month'        => (string) $statement['month'],
    'status'       => (string) $statement['status'],
    'committed'    => ((int) $statement['line_count']) > 0,
    'header_row'   => $headerRow,
    'mapping'      => $mapping,
    'truncated'    => $sheet['truncated'],
    'lines'        => $result['lines'],
    'totals'       => $result['totals'],
    'diff_counts'  => $result['diff_counts'],
]);
