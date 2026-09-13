<?php
declare(strict_types=1);

/**
 * src/handlers/purchase_mv_void.php — POST /api/purchase/mv/{num}/void  (정책: purchase.stock.write)
 *
 * ★ 21단계 **이동 취소** (PRD F-33, ARCH 5.18 「원장」, 완료 기준 T-46).
 *
 * 취소는 **삭제가 아니다.** 전표와 줄은 `status = 'void'` 로 남고, 원장에는 원본 행마다
 * **반대 부호 행 + `reverses_move_id`** 가 새로 들어간다(`Stock::reverseDoc()`).
 * 그래서 현재고(= 원장의 합)는 취소 전으로 돌아가고, "언제 무엇이 왜 되돌려졌나" 가 남는다.
 * 앱 계정에는 `p_stock_moves` 의 UPDATE·DELETE 권한이 아예 없다 (SECURITY R-66).
 *
 * 되돌리면 재고가 음수가 되는 경우(이미 써 버린 뒤의 입고 취소 등)는 400 `negative_stock` 이다 —
 * 장부를 음수로 만드는 것보다 취소를 막는 편이 낫고, 그때는 실사로 맞춘다.
 *
 * 본문 { reason?: "…" }
 *
 * 지점 격리 (SECURITY R-65): `Authz::purchaseScopeWhere($user, 'd')`. 범위 밖은 404.
 *
 * @var array<string,mixed> $user
 * @var list<string>        $params $params[0] = p_docs.id
 */

require_once __DIR__ . '/../purchase/Stock.php';

$idRaw = $params[0] ?? '';
if (!is_string($idRaw) || preg_match('/^[0-9]{1,9}$/', $idRaw) !== 1) {
    Http::error(404, Http::MSG_NOT_FOUND);
}
$docId = (int) $idRaw;

$db   = Db::instance();
$body = Http::jsonBody();

$docNoForLog = '';
$reject = static function (string $reason) use ($docId, &$docNoForLog): void {
    Audit::deny('purchase.move.void', $reason, [
        'target_type' => 'purchase_doc',
        'target_id'   => $docId,
        'target_name' => $docNoForLog !== '' ? $docNoForLog : (string) $docId,
        'detail'      => ['doc_type' => Stock::TYPE_MV, 'doc_no' => $docNoForLog],
    ]);
    Http::error(400, Http::MSG_BAD_REQUEST);
};

if ($body !== [] && array_diff(array_keys($body), ['reason']) !== []) {
    $reject('bad_request');
}
$reason = $body['reason'] ?? null;
if ($reason !== null && !is_string($reason)) {
    $reject('bad_request');
}
if (is_string($reason) && (preg_match('//u', $reason) !== 1 || iconv_strlen($reason, 'UTF-8') > 300)) {
    $reject('bad_request');
}
$reason = ($reason === '' ? null : $reason);

$scopeD = Authz::purchaseScopeWhere($user, 'd');

$doc = Stock::doc($db, $scopeD, $docId, Stock::TYPE_MV);
if ($doc === null) {
    Http::error(404, Http::MSG_NOT_FOUND);
}
$docNoForLog = (string) $doc['doc_no'];

if ((string) $doc['status'] === Doc::ST_VOID) {
    $reject('status_invalid');
}
if ($doc['closed_month'] !== null) {
    // 마감된 달의 전표는 `purchase.close` 가 필요하다 (22단계) — 지금은 막는다.
    $reject('status_invalid');
}

$db->begin();
try {
    // ① 원장 되돌리기 — 반대 부호 행 (Stock.php 한 곳에서만 INSERT 된다)
    $undone = Stock::reverseDoc($db, $docId, (int) $user['id']);

    // ② 전표·줄을 취소 표시
    Stock::markVoid($db, $docId);

    Audit::allow('purchase.move.void', [
        'target_type' => 'purchase_doc',
        'target_id'   => $docId,
        'target_name' => $docNoForLog,
        'detail'      => [
            'doc_no'      => $docNoForLog,
            'doc_type'    => Stock::TYPE_MV,
            'branch_id'   => (int) $doc['branch_id'],
            'moves'       => $undone['moves'],
            'qty_delta'   => $undone['qty'],
            'reason_text' => $reason,
        ],
    ]);
    $db->commit();
} catch (StockError $e) {
    $db->rollback();
    $reject($e->getMessage());
} catch (Throwable $e) {
    $db->rollback();
    throw $e;
}

Http::json([
    'id'       => $docId,
    'doc_no'   => $docNoForLog,
    'doc_type' => Stock::TYPE_MV,
    'status'   => Doc::ST_VOID,
    'moves'    => $undone['moves'],
]);
