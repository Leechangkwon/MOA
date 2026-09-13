<?php
declare(strict_types=1);

/**
 * src/handlers/purchase_is_create.php — POST /api/purchase/is  (정책: purchase.stock.write)
 *
 * ★ 21단계 **사용(출고) 확정** (PRD F-33, 시안 ⑦ MoveUse 「사용」 탭).
 *
 * 본문
 *   { from_location_id, doc_date?, memo?, manager_id?,
 *     lines: [ { item_id, qty, remark? } … ] }
 *
 * 수술방 등 **한 장소에서 쓴 만큼 재고를 뺀다**. 받는 장소가 없다(이동과 다른 점).
 * 원장에는 `−qty` 한 행만 남고, 이 행이 「주평균 사용」 통계의 근거가 된다 (F-34 현황).
 *
 *   ① **음수 재고 금지** — `Stock::apply()` 안에서 장소 잠금 뒤 합 재계산, 0 미만이면
 *      400 `negative_stock`.
 *   ② **지점 격리** — 장소가 `Authz::purchaseScopeWhere($user,'l')` 를 지난다.
 *   ③ **재고관리 품목만** — `stock_managed = false` 인 품목은 뺄 재고가 없다
 *      (그 품목의 사용량은 입고(GR)의 `+` 행으로 센다 — ARCH 5.18).
 *
 * @var array<string,mixed> $user
 * @var list<string>        $params 경로 변수 없음
 */

require_once __DIR__ . '/../purchase/Stock.php';

const TBOX_IS_CREATE_KEYS = ['from_location_id', 'doc_date', 'memo', 'manager_id', 'lines'];
const TBOX_IS_LINE_KEYS   = ['item_id', 'qty', 'remark'];

$db   = Db::instance();
$body = Http::jsonBody();

$reject = static function (string $reason, int $status = 400): void {
    Audit::deny('purchase.use.create', $reason, [
        'target_type' => 'purchase_doc',
        'target_name' => 'is',
        'detail'      => ['doc_type' => Stock::TYPE_IS],
    ]);
    Http::error($status, Http::MSG_BAD_REQUEST);
};

if ($body === [] || array_diff(array_keys($body), TBOX_IS_CREATE_KEYS) !== []) {
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
$mgrReq = $asId($body['manager_id'] ?? null);
if ($fromId === null || $fromId === -1 || $mgrReq === -1) {
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
    if (!is_array($line) || array_diff(array_keys($line), TBOX_IS_LINE_KEYS) !== []) {
        $reject('bad_request');
    }
    $itemId = $asId($line['item_id'] ?? null);
    if ($itemId === null || $itemId === -1) {
        $reject('bad_request');
    }
    if (isset($seen[$itemId])) {
        $reject('bad_request');
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

$scopeL = Authz::purchaseScopeWhere($user, 'l');

$managerId = $mgrReq ?? (int) $user['id'];
if ($mgrReq !== null && !Doc::managerOk($db, $mgrReq)) {
    $reject('bad_reference');
}

$db->begin();
try {
    $fromLoc = Stock::location($db, $scopeL, $fromId);
    if ($fromLoc === null) {
        $db->rollback();
        $reject('not_in_scope');
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

    $docNo = Stock::nextDocNo($db, Stock::TYPE_IS);
    $docId = Stock::insertDoc($db, [
        'doc_type'         => Stock::TYPE_IS,
        'doc_no'           => $docNo,
        'doc_date'         => $docDate,
        'branch_id'        => (int) $fromLoc['branch_id'],
        'vendor_id'        => null,
        'from_location_id' => $fromId,
        'to_location_id'   => null,
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

        $moves[] = [
            'doc_line_id' => $lineId,
            'location_id' => $fromId,
            'item_id'     => $w['item_id'],
            'qty_delta'   => Doc::qtyText(-Doc::milli($w['qty'])),
        ];
    }

    $moveIds = Stock::apply($db, $moves, (int) $user['id']);

    Audit::allow('purchase.use.create', [
        'target_type' => 'purchase_doc',
        'target_id'   => $docId,
        'target_name' => $docNo,
        'detail'      => [
            'doc_no'           => $docNo,
            'doc_type'         => Stock::TYPE_IS,
            'branch_id'        => (int) $fromLoc['branch_id'],
            'from_location_id' => $fromId,
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
    'doc_type'         => Stock::TYPE_IS,
    'doc_date'         => $docDate,
    'from_location_id' => $fromId,
    'status'           => Doc::ST_DONE,
    'lines'            => Stock::lines($db, $docId),
    'moves'            => count($moveIds),
], 201);
