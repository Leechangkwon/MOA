<?php
declare(strict_types=1);

/**
 * src/handlers/purchase_gr_create.php — POST /api/purchase/gr  (정책: purchase.stock.write)
 *
 * ★ 21단계 **입고 확정** (PRD F-33, ARCH 5.18 「원장」·「연결」, 완료 기준 T-46).
 *
 * 본문
 *   { to_location_id?, doc_date?, memo?, manager_id?,
 *     lines: [ { src_line_id, qty, lot_no?, expiry_date?, remark? } … ] }
 *
 * 이 파일이 지키는 것 — 21단계에서 **재고를 늘리는 유일한 경로**다.
 *   ① **잔량 초과 거부**: 발주서 줄을 `SELECT … FOR UPDATE` 로 잠근 **뒤**에 미입고 잔량을
 *      다시 세고, 그보다 많이 받으면 400 `remaining_exceeded` (20단계 담기와 같은 사유 코드).
 *   ② **보낸 발주서만**: `sent_at IS NULL` 인 발주서는 400 `status_invalid`.
 *   ③ **금액은 서버 것만**: 단가는 **발주서 줄의 단가**(없으면 `p_items.price_in`)이고,
 *      금액은 `수량 × 단가`. 본문에 `unit_price`·`amount` 를 적으면 허용 키 검사에서 400 이다.
 *   ④ **원장 기록은 `Stock::apply()` 한 곳**: 재고관리 품목은 `+qty` 한 행, 재고관리를 하지
 *      않는 품목(기공료 등)은 `+qty`·`−qty` **두 행**(순변화 0)이다 — 사용 통계에는 잡히고
 *      현재고는 늘지 않는다 (ARCH 5.18 「재고관리 안 하는 품목」).
 *   ⑤ 발주서 줄·전표 상태를 다시 계산한다 (일부 입고 → 완료).
 *
 * 지점 격리 (SECURITY R-65)
 *   `Authz::purchaseScopeWhere($user, 'd')` 로 **출처 발주서 줄이 내 범위 안인지** 보고,
 *   `…($user, 'l')` 로 입고 장소를 본다. 입고 전표의 `branch_id` 는 요청 값이 아니라
 *   **입고 장소의 지점**에서 나온다.
 *
 * @var array<string,mixed> $user
 * @var list<string>        $params 경로 변수 없음
 */

require_once __DIR__ . '/../purchase/Stock.php';

const TBOX_GR_CREATE_KEYS = ['to_location_id', 'doc_date', 'memo', 'manager_id', 'lines'];
const TBOX_GR_LINE_KEYS   = ['src_line_id', 'qty', 'lot_no', 'expiry_date', 'remark'];

$db   = Db::instance();
$body = Http::jsonBody();

$reject = static function (string $reason, int $status = 400): void {
    Audit::deny('purchase.receipt.create', $reason, [
        'target_type' => 'purchase_doc',
        'target_name' => 'gr',
        'detail'      => ['doc_type' => Stock::TYPE_GR],
    ]);
    Http::error($status, Http::MSG_BAD_REQUEST);
};

if ($body === [] || array_diff(array_keys($body), TBOX_GR_CREATE_KEYS) !== []) {
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

$locReq = $asId($body['to_location_id'] ?? null);
$mgrReq = $asId($body['manager_id'] ?? null);
if ($locReq === -1 || $mgrReq === -1) {
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
    if (!is_array($line) || array_diff(array_keys($line), TBOX_GR_LINE_KEYS) !== []) {
        $reject('bad_request');
    }
    $srcId = $asId($line['src_line_id'] ?? null);
    if ($srcId === null || $srcId === -1) {
        $reject('bad_request');
    }
    if (isset($seen[$srcId])) {
        // 같은 발주서 줄을 한 입고 전표에 두 번 적으면 잔량 판정이 두 갈래가 된다.
        $reject('bad_request');
    }
    $seen[$srcId] = true;

    $qtyRaw = $line['qty'] ?? null;
    if (is_int($qtyRaw) || is_float($qtyRaw)) {
        $qtyRaw = (string) $qtyRaw;
    }
    if (!Stock::isQty($qtyRaw)) {
        $reject('bad_request');
    }

    $lot = $line['lot_no'] ?? null;
    if ($lot !== null && !is_string($lot)) {
        $reject('bad_request');
    }
    if (is_string($lot) && (preg_match('//u', $lot) !== 1 || iconv_strlen($lot, 'UTF-8') > 60)) {
        $reject('bad_request');
    }
    if ($lot === '') {
        $lot = null;
    }

    $exp = $line['expiry_date'] ?? null;
    if ($exp === '') {
        $exp = null;
    }
    if ($exp !== null && (!is_string($exp) || preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $exp) !== 1)) {
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

    $wanted[] = [
        'src'    => $srcId,
        'qty'    => (string) $qtyRaw,
        'lot'    => $lot,
        'exp'    => $exp,
        'remark' => $remark,
    ];
}

// ─── 지점 격리·참조 ─────────────────────────────────────────
$scopeD = Authz::purchaseScopeWhere($user, 'd');
$scopeL = Authz::purchaseScopeWhere($user, 'l');

$managerId = $mgrReq ?? (int) $user['id'];
if ($mgrReq !== null && !Doc::managerOk($db, $mgrReq)) {
    $reject('bad_reference');
}

/**
 * 출처 발주서 줄. **지점 격리 조건이 붙는다** — 범위 밖 줄은 여기서 사라지고
 * 아래 확인에서 `not_in_scope` 로 끝난다.
 */
$sqlSrcHead = <<<'SQL'
    SELECT l.id, l.doc_id, l.item_id, CAST(l.qty AS text) AS qty, l.status,
           CAST(l.unit_price AS text) AS unit_price,
           d.doc_no, d.doc_type, d.status AS doc_status, d.closed_month, d.sent_at,
           d.to_location_id, d.vendor_id AS doc_vendor_id, d.branch_id,
           i.is_active AS item_active, i.stock_managed,
           CAST(i.price_in AS text) AS price_in
      FROM p_doc_lines l
      JOIN p_docs  d ON d.id = l.doc_id
      JOIN p_items i ON i.id = l.item_id
     WHERE l.id IN
    SQL;

$names = [];
$bind  = [];
foreach (array_column($wanted, 'src') as $i => $id) {
    $name        = ':s' . (string) $i;
    $names[]     = $name;
    $bind[$name] = $id;
}
$inList = '(' . implode(', ', $names) . ')';
$sqlSrcTail = " AND d.doc_type = 'po' AND ";

// ─── 트랜잭션 — 잠그고, 다시 세고, 넣는다 ───────────────────
$db->begin();
try {
    // ① 출처 줄을 **먼저 잠근다** (id 오름차순 — 교착 방지)
    $locked = Doc::lockLines($db, array_column($wanted, 'src'));

    // ② 잠근 뒤에 전표·품목 정보를 읽는다 (지점 격리 포함)
    $srcRows = $db->run(
        $sqlSrcHead . ' ' . $inList . $sqlSrcTail . $scopeD['sql'],
        $bind + $scopeD['params']
    )->fetchAll();
    $src = [];
    foreach ($srcRows as $row) {
        $src[(int) $row['id']] = $row;
    }

    // ③ 줄마다 확인 — 존재·범위·상태·잔량
    $prepared  = [];
    $locations = [];
    $vendors   = [];
    $srcDocNos = [];
    $srcDocs   = [];
    foreach ($wanted as $w) {
        $row = $src[$w['src']] ?? null;
        if ($row === null || !isset($locked[$w['src']])) {
            $db->rollback();
            $reject('not_in_scope');
        }
        if ((string) $row['status'] === Doc::LINE_VOID
            || (string) $row['doc_status'] === Doc::ST_VOID
        ) {
            $db->rollback();
            $reject('status_invalid');
        }
        if ($row['sent_at'] === null) {
            // 보내지 않은 발주서의 물건이 도착할 수는 없다 (시안 ⑥ 「불러오기는 보낸 것만」).
            $db->rollback();
            $reject('status_invalid');
        }
        if ($row['closed_month'] !== null) {
            $db->rollback();
            $reject('status_invalid');
        }
        if ($row['item_active'] !== true) {
            $db->rollback();
            $reject('bad_reference');
        }

        $taken     = Doc::takenQty($db, $w['src']);
        $remaining = Doc::milli((string) $row['qty']) - Doc::milli($taken);
        if (Doc::milli($w['qty']) > $remaining) {
            $db->rollback();
            $reject('remaining_exceeded');
        }

        // 입고 장소: 요청이 적었으면 그것, 아니면 **발주서의 입고 장소**를 따른다.
        $lineLoc = $locReq ?? ($row['to_location_id'] === null ? null : (int) $row['to_location_id']);
        if ($lineLoc === null) {
            $db->rollback();
            $reject('bad_reference');
        }
        $locations[$lineLoc] = true;
        if ($row['doc_vendor_id'] !== null) {
            $vendors[(int) $row['doc_vendor_id']] = true;
        }

        $prepared[] = [
            'src'        => $w['src'],
            'qty'        => $w['qty'],
            'lot'        => $w['lot'],
            'exp'        => $w['exp'],
            'remark'     => $w['remark'],
            'item_id'    => (int) $row['item_id'],
            'managed'    => $row['stock_managed'] === true,
            'unit_price' => $row['unit_price'] !== null
                ? (string) $row['unit_price']
                : ($row['price_in'] === null ? null : (string) $row['price_in']),
            'location'   => $lineLoc,
            'want_qty'   => (string) $row['qty'],
            'doc_id'     => (int) $row['doc_id'],
            'doc_no'     => (string) $row['doc_no'],
        ];
        $srcDocs[(int) $row['doc_id']]   = true;
        $srcDocNos[(string) $row['doc_no']] = true;
    }

    // ④ 입고 장소는 한 전표에 하나 — 여러 곳에 동시에 들어오는 입고는 전표를 나눈다.
    if (count($locations) !== 1) {
        $db->rollback();
        $reject('bad_request');
    }
    $locId = (int) array_key_first($locations);

    $locRow = Stock::location($db, $scopeL, $locId);
    if ($locRow === null) {
        $db->rollback();
        $reject('not_in_scope');
    }
    $branchId = (int) $locRow['branch_id'];

    // ⑤ 입고 전표 만들기
    $docNo = Stock::nextDocNo($db, Stock::TYPE_GR);
    $docId = Stock::insertDoc($db, [
        'doc_type'         => Stock::TYPE_GR,
        'doc_no'           => $docNo,
        'doc_date'         => $docDate,
        'branch_id'        => $branchId,
        'vendor_id'        => count($vendors) === 1 ? (int) array_key_first($vendors) : null,
        'from_location_id' => null,
        'to_location_id'   => $locId,
        'manager_id'       => $managerId,
        'status'           => Doc::ST_DONE,
        'memo'             => $memo,
        'count_cycle'      => null,
        'created_by'       => (int) $user['id'],
    ]);

    $lineNo    = 1;
    $amountSum = 0.0;
    $moves     = [];
    $lots      = [];
    foreach ($prepared as $p) {
        $lineId = Stock::insertLine($db, $docId, $lineNo, [
            'item_id'     => $p['item_id'],
            'qty'         => $p['qty'],
            'unit_price'  => $p['unit_price'],
            'remark'      => $p['remark'],
            'src_line_id' => $p['src'],
            'status'      => Doc::LINE_DONE,
            'lot_no'      => $p['lot'],
            'expiry_date' => $p['exp'],
            'counted_qty' => null,
        ]);
        $calc = Doc::lineAmount($p['qty'], $p['unit_price']);
        if ($calc !== null) {
            $amountSum += (float) $calc;
        }
        if ($p['lot'] !== null) {
            $lots[$p['lot']] = true;
        }
        $lineNo++;

        // ⑥ 원장 — 재고관리 품목은 한 행, 아닌 품목은 +·− 두 행(순변화 0)
        $moves[] = [
            'doc_line_id' => $lineId,
            'location_id' => $p['location'],
            'item_id'     => $p['item_id'],
            'qty_delta'   => Doc::qtyText(Doc::milli($p['qty'])),
        ];
        if (!$p['managed']) {
            $moves[] = [
                'doc_line_id' => $lineId,
                'location_id' => $p['location'],
                'item_id'     => $p['item_id'],
                'qty_delta'   => Doc::qtyText(-Doc::milli($p['qty'])),
            ];
        }

        // ⑦ 발주서 줄 상태 (일부 입고 / 완료)
        Doc::retouchRequestLine($db, $p['src'], $p['want_qty'], Doc::takenQty($db, $p['src']));
    }

    // ⑧ 원장 기록 — **Stock.php 한 곳**에서만 들어간다 (린트 규칙 (e))
    $moveIds = Stock::apply($db, $moves, (int) $user['id']);

    // ⑨ 출처 발주서의 상태를 다시 계산한다
    foreach (array_keys($srcDocs) as $srcDocId) {
        Stock::recalcOrderStatus($db, (int) $srcDocId);
    }

    Audit::allow('purchase.receipt.create', [
        'target_type' => 'purchase_doc',
        'target_id'   => $docId,
        'target_name' => $docNo,
        'detail'      => [
            'doc_no'      => $docNo,
            'doc_type'    => Stock::TYPE_GR,
            'branch_id'   => $branchId,
            'location_id' => $locId,
            'lines'       => count($prepared),
            'moves'       => count($moveIds),
            'amount'      => number_format($amountSum, 2, '.', ''),
            'src_doc_no'  => implode(',', array_keys($srcDocNos)),
            'lot_no'      => $lots === [] ? null : implode(',', array_keys($lots)),
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
    'doc_type'    => Stock::TYPE_GR,
    'doc_date'    => $docDate,
    'branch_id'   => $branchId,
    'location_id' => $locId,
    'status'      => Doc::ST_DONE,
    'lines'       => Stock::lines($db, $docId),
    'moves'       => count($moveIds),
], 201);
