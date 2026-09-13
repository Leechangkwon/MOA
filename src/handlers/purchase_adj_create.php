<?php
declare(strict_types=1);

/**
 * src/handlers/purchase_adj_create.php — POST /api/purchase/adj  (정책: purchase.stock.write)
 *
 * ★ 21단계 **실사 확정** (PRD F-33 · Q-18③ 실사 주기 3종, 시안 ⑦ MoveUse 「실사」 탭).
 *
 * 본문
 *   { location_id, count_cycle, doc_date?, memo?, manager_id?,
 *     lines: [ { item_id, book_qty, counted_qty, remark? } … ] }
 *
 * 실사는 **차이만** 원장에 적는다.
 *   ① `book_qty` 는 「실사 시작」(`GET /api/purchase/adj/start`)이 준 **장부 스냅샷**이다.
 *      확정 시 서버가 장소를 잠그고 그 값을 **다시 센다**. 그 사이 다른 전표가 재고를 바꿨으면
 *      400 `stale_snapshot` — 화면을 다시 열게 한다. 남이 옮긴 물건을 「차이」로 오해해
 *      원장에 적는 일을 막는 장치다.
 *   ② 차이 = `counted_qty − book_qty`. **0 이 아닌 줄만** 원장 한 행(+ 또는 −)이다.
 *      0 인 줄도 전표에는 남는다 — "세어 보았고 맞았다" 도 기록이다.
 *   ③ 실사로 재고가 음수가 되는 일은 없다(센 수량은 0 이상이고 결과가 곧 그 값이다).
 *      그래도 원장 기록은 `Stock::apply()` 를 지나므로 같은 검사를 다시 받는다.
 *   ④ 재고관리를 하지 않는 품목은 셀 재고가 없다 → 400 `not_stock_managed`.
 *
 * 지점 격리 (SECURITY R-65): 장소가 `Authz::purchaseScopeWhere($user,'l')` 를 지난다.
 *
 * @var array<string,mixed> $user
 * @var list<string>        $params 경로 변수 없음
 */

require_once __DIR__ . '/../purchase/Stock.php';

const TBOX_ADJ_CREATE_KEYS = ['location_id', 'count_cycle', 'doc_date', 'memo',
                              'manager_id', 'lines'];
const TBOX_ADJ_LINE_KEYS   = ['item_id', 'book_qty', 'counted_qty', 'remark'];

$db   = Db::instance();
$body = Http::jsonBody();

$reject = static function (string $reason, int $status = 400): void {
    Audit::deny('purchase.count.create', $reason, [
        'target_type' => 'purchase_doc',
        'target_name' => 'adj',
        'detail'      => ['doc_type' => Stock::TYPE_ADJ],
    ]);
    Http::error($status, Http::MSG_BAD_REQUEST);
};

if ($body === [] || array_diff(array_keys($body), TBOX_ADJ_CREATE_KEYS) !== []) {
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

$locId  = $asId($body['location_id'] ?? null);
$mgrReq = $asId($body['manager_id'] ?? null);
if ($locId === null || $locId === -1 || $mgrReq === -1) {
    $reject('bad_request');
}

$cycle = $body['count_cycle'] ?? null;
if (!is_string($cycle) || !in_array($cycle, Stock::CYCLES, true)) {
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
    if (!is_array($line) || array_diff(array_keys($line), TBOX_ADJ_LINE_KEYS) !== []) {
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

    foreach (['book_qty', 'counted_qty'] as $key) {
        $raw = $line[$key] ?? null;
        if (is_int($raw) || is_float($raw)) {
            $raw = (string) $raw;
        }
        // 장부·실제 둘 다 **0 이 허용된다** (장부에 없던 것을 찾는 경우, 다 쓴 경우).
        if (!Stock::isQty($raw, true)) {
            $reject('bad_request');
        }
        $line[$key] = (string) $raw;
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

    $wanted[] = [
        'item_id' => $itemId,
        'book'    => (string) $line['book_qty'],
        'counted' => (string) $line['counted_qty'],
        'remark'  => $remark,
    ];
}

$scopeL = Authz::purchaseScopeWhere($user, 'l');

$managerId = $mgrReq ?? (int) $user['id'];
if ($mgrReq !== null && !Doc::managerOk($db, $mgrReq)) {
    $reject('bad_reference');
}

$db->begin();
try {
    $loc = Stock::location($db, $scopeL, $locId);
    if ($loc === null) {
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

    // ① 장소를 잠근 **뒤** 스냅샷을 다시 센다 (지시서 보충 1·4)
    Stock::lockLocations($db, [$locId]);
    foreach ($wanted as $w) {
        $now = Doc::milli(Stock::balance($db, $locId, $w['item_id']));
        if ($now !== Doc::milli($w['book'])) {
            $db->rollback();
            $reject('stale_snapshot');
        }
    }

    $docNo = Stock::nextDocNo($db, Stock::TYPE_ADJ);
    $docId = Stock::insertDoc($db, [
        'doc_type'         => Stock::TYPE_ADJ,
        'doc_no'           => $docNo,
        'doc_date'         => $docDate,
        'branch_id'        => (int) $loc['branch_id'],
        'vendor_id'        => null,
        'from_location_id' => $locId,
        'to_location_id'   => null,
        'manager_id'       => $managerId,
        'status'           => Doc::ST_DONE,
        'memo'             => $memo,
        'count_cycle'      => $cycle,
        'created_by'       => (int) $user['id'],
    ]);

    $lineNo  = 1;
    $moves   = [];
    $diffUp  = 0;
    $diffDn  = 0;
    foreach ($wanted as $w) {
        $item   = $items[$w['item_id']];
        $lineId = Stock::insertLine($db, $docId, $lineNo, [
            'item_id'     => $w['item_id'],
            // qty = 장부 스냅샷, counted_qty = 실제 센 수량.
            'qty'         => $w['book'],
            'unit_price'  => $item['price_in'] === null ? null : (string) $item['price_in'],
            'remark'      => $w['remark'],
            'src_line_id' => null,
            'status'      => Doc::LINE_DONE,
            'lot_no'      => null,
            'expiry_date' => null,
            'counted_qty' => $w['counted'],
        ]);
        $lineNo++;

        $delta = Doc::milli($w['counted']) - Doc::milli($w['book']);
        if ($delta === 0) {
            continue;   // 맞은 줄은 원장에 적지 않는다 — 변화가 없는 행은 기록이 아니다
        }
        if ($delta > 0) {
            $diffUp += $delta;
        } else {
            $diffDn += -$delta;
        }
        $moves[] = [
            'doc_line_id' => $lineId,
            'location_id' => $locId,
            'item_id'     => $w['item_id'],
            'qty_delta'   => Doc::qtyText($delta),
        ];
    }

    $moveIds = Stock::apply($db, $moves, (int) $user['id']);

    Audit::allow('purchase.count.create', [
        'target_type' => 'purchase_doc',
        'target_id'   => $docId,
        'target_name' => $docNo,
        'detail'      => [
            'doc_no'      => $docNo,
            'doc_type'    => Stock::TYPE_ADJ,
            'branch_id'   => (int) $loc['branch_id'],
            'location_id' => $locId,
            'count_cycle' => $cycle,
            'lines'       => count($wanted),
            'moves'       => count($moveIds),
            // 차이의 크기만 남긴다(품목별 내용은 전표에 있다).
            'qty_delta'   => Doc::qtyText($diffUp) . '/-' . Doc::qtyText($diffDn),
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
    'id'          => $docId,
    'doc_no'      => $docNo,
    'doc_type'    => Stock::TYPE_ADJ,
    'doc_date'    => $docDate,
    'location_id' => $locId,
    'count_cycle' => $cycle,
    'status'      => Doc::ST_DONE,
    'lines'       => Stock::lines($db, $docId),
    'moves'       => count($moveIds),
], 201);
