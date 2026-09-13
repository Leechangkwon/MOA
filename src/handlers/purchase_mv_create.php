<?php
declare(strict_types=1);

/**
 * src/handlers/purchase_mv_create.php — POST /api/purchase/mv  (정책: purchase.stock.write)
 *
 * ★ 21단계 **이동 확정** (PRD F-33, 시안 ⑦ MoveUse, 완료 기준 T-46).
 *
 * 본문
 *   { from_location_id, to_location_id, doc_date?, memo?, manager_id?,
 *     lines: [ { item_id, qty, remark? } … ] }
 *
 * 이 파일이 지키는 것
 *   ① **음수 재고 금지**: 원장 기록은 `Stock::apply()` 한 곳을 지나고, 그 안에서
 *      보내는 장소 행을 `FOR UPDATE` 로 잠근 뒤 합을 다시 세어 0 미만이면 400 `negative_stock`.
 *      같은 장소의 동시 요청은 잠금 때문에 줄을 서므로, 재고 3 에서 2 씩 동시에 빼면
 *      **한쪽만** 통과한다 (T-46 동시성 항목).
 *   ② **지점 격리**: 두 장소 모두 `Authz::purchaseScopeWhere($user,'l')` 를 지난다.
 *      **지점을 건너뛰는 이동은 `purchase.buy.write` 가 있는 사람만**이다(구매실·Admin) —
 *      창고 담당(stock)은 자기 지점 안에서만 옮긴다. 그 판정도 `Authz` 함수의 참/거짓만 본다
 *      (역할 문자열을 여기서 견주지 않는다 — SECURITY R-70).
 *   ③ **재고관리 품목만**: `stock_managed = false` 인 품목(기공료 등)은 옮길 재고가 없다 →
 *      400 `not_stock_managed`.
 *   ④ 금액을 받지 않는다 — 이동은 장부상 자리만 바뀌는 일이고, 단가는 `p_items.price_in` 이다.
 *
 * @var array<string,mixed> $user
 * @var list<string>        $params 경로 변수 없음
 */

require_once __DIR__ . '/../purchase/Stock.php';

const TBOX_MV_CREATE_KEYS = ['from_location_id', 'to_location_id', 'doc_date', 'memo',
                             'manager_id', 'lines'];
const TBOX_MV_LINE_KEYS   = ['item_id', 'qty', 'remark'];

$db   = Db::instance();
$body = Http::jsonBody();

$reject = static function (string $reason, int $status = 400): void {
    Audit::deny('purchase.move.create', $reason, [
        'target_type' => 'purchase_doc',
        'target_name' => 'mv',
        'detail'      => ['doc_type' => Stock::TYPE_MV],
    ]);
    Http::error($status, Http::MSG_BAD_REQUEST);
};

if ($body === [] || array_diff(array_keys($body), TBOX_MV_CREATE_KEYS) !== []) {
    $reject('bad_request');
}

$asId = static function ($v): ?int {
    if ($v === null || $v === '') {
        return null;
    }
    if (!is_int($v) && !(is_string($v) && preg_match('/^[0-9]{1,9}$/', $v) === 1)) {
        return -1;
    }
    $n = (int) $v;
    return $n >= 1 ? $n : -1;
};

$fromId = $asId($body['from_location_id'] ?? null);
$toId   = $asId($body['to_location_id'] ?? null);
$mgrReq = $asId($body['manager_id'] ?? null);
if ($fromId === null || $fromId === -1 || $toId === null || $toId === -1 || $mgrReq === -1) {
    $reject('bad_request');
}
if ($fromId === $toId) {
    $reject('bad_request');
}

$docDate = $body['doc_date'] ?? null;
if ($docDate === null) {
    $docDate = date('Y-m-d');
}
if (!is_string($docDate) || preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $docDate) !== 1) {
    $reject('bad_request');
}

$memo = $body['memo'] ?? null;
if ($memo !== null && !is_string($memo)) {
    $reject('bad_request');
}
if (is_string($memo) && (preg_match('//u', $memo) !== 1 || iconv_strlen($memo, 'UTF-8') > 500)) {
    $reject('bad_request');
}
if ($memo === '') {
    $memo = null;
}

$lines = $body['lines'] ?? null;
if (!is_array($lines) || $lines === [] || count($lines) > Stock::MAX_LINES) {
    $reject('bad_request');
}

$wanted = [];
$seen   = [];
foreach ($lines as $line) {
    if (!is_array($line) || array_diff(array_keys($line), TBOX_MV_LINE_KEYS) !== []) {
        $reject('bad_request');
    }
    $itemId = $asId($line['item_id'] ?? null);
    if ($itemId === null || $itemId === -1) {
        $reject('bad_request');
    }
    if (isset($seen[$itemId])) {
        $reject('bad_request');   // 같은 품목을 두 줄로 적으면 음수 판정이 두 갈래가 된다
    }
    $seen[$itemId] = true;

    $qtyRaw = $line['qty'] ?? null;
    if (is_int($qtyRaw) || is_float($qtyRaw)) {
        $qtyRaw = (string) $qtyRaw;
    }
    if (!Stock::isQty($qtyRaw)) {
        $reject('bad_request');
    }

    $remark = $line['remark'] ?? null;
    if ($remark !== null && !is_string($remark)) {
        $reject('bad_request');
    }
    if (is_string($remark)
        && (preg_match('//u', $remark) !== 1 || iconv_strlen($remark, 'UTF-8') > 200)
    ) {
        $reject('bad_request');
    }
    if ($remark === '') {
        $remark = null;
    }

    $wanted[] = ['item_id' => $itemId, 'qty' => (string) $qtyRaw, 'remark' => $remark];
}

// ─── 지점 격리·참조 ─────────────────────────────────────────
$scopeL = Authz::purchaseScopeWhere($user, 'l');

$managerId = $mgrReq ?? (int) $user['id'];
if ($mgrReq !== null && !Doc::managerOk($db, $mgrReq)) {
    $reject('bad_reference');
}

$db->begin();
try {
    $fromLoc = Stock::location($db, $scopeL, $fromId);
    $toLoc   = Stock::location($db, $scopeL, $toId);
    if ($fromLoc === null || $toLoc === null) {
        $db->rollback();
        $reject('not_in_scope');
    }
    // 지점을 건너뛰는 이동은 구매실·Admin 만. 판정은 Authz 한 곳의 참/거짓이다.
    if ((int) $fromLoc['branch_id'] !== (int) $toLoc['branch_id']
        && !Authz::purchaseAuthorize('purchase.buy.write', $user)
    ) {
        $db->rollback();
        $reject('not_in_scope', 403);
    }

    $items = Stock::items($db, array_column($wanted, 'item_id'));
    foreach ($wanted as $w) {
        if (!isset($items[$w['item_id']])) {
            $db->rollback();
            $reject('bad_reference');
        }
        if ($items[$w['item_id']]['stock_managed'] !== true) {
            $db->rollback();
            $reject('not_stock_managed');
        }
    }

    $docNo = Stock::nextDocNo($db, Stock::TYPE_MV);
    $docId = Stock::insertDoc($db, [
        'doc_type'         => Stock::TYPE_MV,
        'doc_no'           => $docNo,
        'doc_date'         => $docDate,
        // 전표의 지점은 **보내는 장소**의 지점이다 (요청 값이 아니다).
        'branch_id'        => (int) $fromLoc['branch_id'],
        'vendor_id'        => null,
        'from_location_id' => $fromId,
        'to_location_id'   => $toId,
        'manager_id'       => $managerId,
        'status'           => Doc::ST_DONE,
        'memo'             => $memo,
        'count_cycle'      => null,
        'created_by'       => (int) $user['id'],
    ]);

    $lineNo = 1;
    $moves  = [];
    foreach ($wanted as $w) {
        $item   = $items[$w['item_id']];
        $lineId = Stock::insertLine($db, $docId, $lineNo, [
            'item_id'     => $w['item_id'],
            'qty'         => $w['qty'],
            'unit_price'  => $item['price_in'] === null ? null : (string) $item['price_in'],
            'remark'      => $w['remark'],
            'src_line_id' => null,
            'status'      => Doc::LINE_DONE,
            'lot_no'      => null,
            'expiry_date' => null,
            'counted_qty' => null,
        ]);
        $lineNo++;

        $milli = Doc::milli($w['qty']);
        $moves[] = [
            'doc_line_id' => $lineId,
            'location_id' => $fromId,
            'item_id'     => $w['item_id'],
            'qty_delta'   => Doc::qtyText(-$milli),
        ];
        $moves[] = [
            'doc_line_id' => $lineId,
            'location_id' => $toId,
            'item_id'     => $w['item_id'],
            'qty_delta'   => Doc::qtyText($milli),
        ];
    }

    // 원장 기록 — **Stock.php 한 곳**. 음수 검사·장소 잠금이 그 안에 있다 (린트 규칙 (e)).
    $moveIds = Stock::apply($db, $moves, (int) $user['id']);

    Audit::allow('purchase.move.create', [
        'target_type' => 'purchase_doc',
        'target_id'   => $docId,
        'target_name' => $docNo,
        'detail'      => [
            'doc_no'           => $docNo,
            'doc_type'         => Stock::TYPE_MV,
            'branch_id'        => (int) $fromLoc['branch_id'],
            'from_location_id' => $fromId,
            'to_location_id'   => $toId,
            'lines'            => count($wanted),
            'moves'            => count($moveIds),
        ],
    ]);
    $db->commit();
} catch (StockError $e) {
    $db->rollback();
    $reject($e->getMessage());
} catch (PDOException $e) {
    $db->rollback();
    if ($e->getCode() === '23503') {
        $reject('bad_reference');
    }
    if ($e->getCode() === '23505') {
        $reject('conflict', 409);
    }
    throw $e;
} catch (Throwable $e) {
    $db->rollback();
    throw $e;
}

Http::json([
    'id'               => $docId,
    'doc_no'           => $docNo,
    'doc_type'         => Stock::TYPE_MV,
    'doc_date'         => $docDate,
    'from_location_id' => $fromId,
    'to_location_id'   => $toId,
    'status'           => Doc::ST_DONE,
    'lines'            => Stock::lines($db, $docId),
    'moves'            => count($moveIds),
], 201);
