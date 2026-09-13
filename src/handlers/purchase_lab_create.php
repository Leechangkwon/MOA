<?php
declare(strict_types=1);

/**
 * src/handlers/purchase_lab_create.php — POST /api/purchase/lab  (정책: purchase.buy.write)
 *
 * ★ 22단계 **기공 명세서 → 입고 전표 자동 생성** (PRD F-35, 완료 기준 T-47 (d),
 *   ARCH 5.18 「재고관리 안 하는 품목」).
 *
 * 기공소 명세서는 "물건" 이 아니라 **용역**이다. 그래서 재고를 늘리면 안 되지만, 산 것은
 * 맞으므로 대사와 사용 통계에는 잡혀야 한다. 21단계가 이미 그 규칙을 갖고 있다:
 * **재고관리를 하지 않는 품목**은 입고 확정 때 원장에 `+qty`·`−qty` **두 행**이 들어가
 * 순변화가 0 이다(현재고는 그대로, 사용 통계에는 잡힘).
 *
 * 그래서 이 창구가 하는 일은 "명세서 줄 → 입고(GR) 전표" 한 가지다. 새 전표 종류를 만들지
 * 않는다 — 종류를 늘리면 그 상태를 처리하지 않는 코드가 생긴다(21단계 마이그레이션의 판단).
 *
 * 본문
 *   { vendor_id, to_location_id, doc_date?, memo?, manager_id?,
 *     statement_id?,                       ← 대사 표에 연결할 명세서 (선택)
 *     lines: [ { item_id, qty, remark? } … ] }
 *
 * 지키는 것
 *   ① **재고관리 안 하는 품목만** — 하나라도 재고관리 품목이면 400 `stock_managed_item`
 *      (지시서 보충 4).
 *   ② **단가·금액은 서버 것만** — 단가는 `p_items.price_in`, 금액은 수량 × 단가다.
 *      본문에 `unit_price`·`amount` 를 적으면 허용 키 검사에서 400 이다 (SECURITY R-66).
 *   ③ **발주서 없이 입고**이므로 줄에 `src_line_id` 가 없다 (지시서 보충 4).
 *   ④ **원장 기록은 `Stock::apply()` 한 곳** — 원장에 행을 넣는 SQL 문장은 이 파일에 없다
 *      (린트 규칙 (e): 그 문장은 `src/purchase/Stock.php` 에만 있을 수 있다).
 *   ⑤ **마감된 달에는 만들지 않는다** — `Doc::closeGuard` 가 400 `month_closed`.
 *   ⑥ `statement_id` 를 주면 **그 명세서의 대사 줄을 다시 맞춘다**(「명세서엔 있는데 우리
 *      입고가 없음」이 이 전표로 채워진다). 거래처·달이 다르면 400 `bad_reference`.
 *
 * 지점 격리 (SECURITY R-65)
 *   입고 장소를 `Authz::purchaseScopeWhere($user, 'l')` 로 보고, 전표의 `branch_id` 는
 *   요청 값이 아니라 **그 장소의 지점**에서 나온다 (입고 확정과 같은 규칙).
 *
 * @var array<string,mixed> $user
 * @var list<string>        $params 경로 변수 없음
 */

require_once __DIR__ . '/../purchase/Stock.php';
require_once __DIR__ . '/../purchase/Recon.php';

const TBOX_LAB_KEYS      = ['vendor_id', 'to_location_id', 'doc_date', 'memo',
                            'manager_id', 'statement_id', 'lines'];
const TBOX_LAB_LINE_KEYS = ['item_id', 'qty', 'remark'];

$db   = Db::instance();
$body = Http::jsonBody();

$reject = static function (string $reason, int $status = 400): void {
    Audit::deny('purchase.lab.create', $reason, [
        'target_type' => 'purchase_doc',
        'target_name' => 'lab',
        'detail'      => ['doc_type' => Stock::TYPE_GR],
    ]);
    Http::error($status, Http::MSG_BAD_REQUEST);
};

if ($body === [] || array_diff(array_keys($body), TBOX_LAB_KEYS) !== []) {
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

$vendorId    = $asId($body['vendor_id'] ?? null);
$locId       = $asId($body['to_location_id'] ?? null);
$mgrReq      = $asId($body['manager_id'] ?? null);
$statementId = $asId($body['statement_id'] ?? null);
if ($vendorId === null || $vendorId === -1 || $locId === null || $locId === -1
    || $mgrReq === -1 || $statementId === -1
) {
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
    if (!is_array($line) || array_diff(array_keys($line), TBOX_LAB_LINE_KEYS) !== []) {
        $reject('bad_request');
    }
    $itemId = $asId($line['item_id'] ?? null);
    if ($itemId === null || $itemId === -1) {
        $reject('bad_request');
    }
    if (isset($seen[$itemId])) {
        $reject('bad_request');   // 같은 품목을 한 전표에 두 번 적으면 대사 대조가 두 갈래가 된다
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

    $wanted[] = [
        'item_id' => $itemId,
        'qty'     => (string) $qtyRaw,
        'remark'  => ($remark === '' ? null : $remark),
    ];
}

$scopeL    = Authz::purchaseScopeWhere($user, 'l');
$managerId = $mgrReq ?? (int) $user['id'];
if ($mgrReq !== null && !Doc::managerOk($db, $mgrReq)) {
    $reject('bad_reference');
}
if (!Doc::vendorOk($db, $vendorId)) {
    $reject('bad_reference');
}

$locRow = Stock::location($db, $scopeL, $locId);
if ($locRow === null) {
    $reject('not_in_scope');
}
$branchId = (int) $locRow['branch_id'];

// ─── 마감 검사 (ARCH 5.18 「마감」) — 사유 없이 마감된 달에 새 전표를 만들 수 없다 ───
if (Doc::closeGuard($db, $user, $branchId, $docDate, null, null) !== Doc::CLOSE_OPEN) {
    $reject('month_closed');
}

// ─── 품목 — **재고관리 안 하는 것만** ───
$items = Stock::items($db, array_column($wanted, 'item_id'));
foreach ($wanted as $w) {
    $row = $items[$w['item_id']] ?? null;
    if ($row === null) {
        $reject('bad_reference');
    }
    if ($row['stock_managed'] === true) {
        $reject('stock_managed_item');
    }
    if ((int) $row['vendor_id'] !== $vendorId) {
        // 기공소 명세서 한 장은 한 거래처의 것이다 — 섞이면 대사가 서지 않는다.
        $reject('bad_reference');
    }
}

// ─── 명세서 연결(선택) — 같은 거래처·같은 달이어야 한다 ───
$statement = null;
if ($statementId !== null) {
    $statement = Recon::statement($db, $statementId);
    if ($statement === null
        || (int) $statement['vendor_id'] !== $vendorId
        || (string) $statement['month'] !== substr($docDate, 0, 7)
    ) {
        $reject('bad_reference');
    }
}

$sqlRelink = <<<'SQL'
    UPDATE p_recon_lines
       SET matched_line_id = CAST(:line AS bigint),
           diff_type       = :diff,
           note            = :note,
           updated_at      = now()
     WHERE statement_id = CAST(:st AS bigint)
       AND id = CAST(:id AS bigint)
    SQL;

$db->begin();
try {
    $docNo = Stock::nextDocNo($db, Stock::TYPE_GR);
    $docId = Stock::insertDoc($db, [
        'doc_type'         => Stock::TYPE_GR,
        'doc_no'           => $docNo,
        'doc_date'         => $docDate,
        'branch_id'        => $branchId,
        'vendor_id'        => $vendorId,
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
    $newLines  = [];
    foreach ($wanted as $w) {
        $item  = $items[$w['item_id']];
        $price = $item['price_in'] === null ? null : (string) $item['price_in'];

        $lineId = Stock::insertLine($db, $docId, $lineNo, [
            'item_id'     => $w['item_id'],
            'qty'         => $w['qty'],
            'unit_price'  => $price,          // ★ DB 값. 본문에서 온 값이 아니다 (R-66)
            'remark'      => $w['remark'],
            'src_line_id' => null,            // 발주서 없이 입고 (지시서 보충 4)
            'status'      => Doc::LINE_DONE,
            'lot_no'      => null,
            'expiry_date' => null,
            'counted_qty' => null,
        ]);
        $calc = Doc::lineAmount($w['qty'], $price);
        if ($calc !== null) {
            $amountSum += (float) $calc;
        }
        $newLines[$w['item_id']] = $lineId;
        $lineNo++;

        // 재고관리를 하지 않는 품목이므로 **+·− 두 행**(순변화 0) — 21단계 규칙 그대로
        $moves[] = [
            'doc_line_id' => $lineId,
            'location_id' => $locId,
            'item_id'     => $w['item_id'],
            'qty_delta'   => Doc::qtyText(Doc::milli($w['qty'])),
        ];
        $moves[] = [
            'doc_line_id' => $lineId,
            'location_id' => $locId,
            'item_id'     => $w['item_id'],
            'qty_delta'   => Doc::qtyText(-Doc::milli($w['qty'])),
        ];
    }

    // 원장 기록 — **Stock.php 한 곳**에서만 들어간다 (린트 규칙 (e))
    $moveIds = Stock::apply($db, $moves, (int) $user['id']);

    /*
     * 대사 표에 연결 — 이 전표가 채운 「명세서엔 있는데 우리 입고가 없음」 줄을 다시 맞춘다.
     * 명세서 줄의 값(수량·단가)은 **그대로 두고** 판정만 다시 쓴다 — 명세서에 적힌 값을
     * 우리 값으로 덮는 순간 그것은 대사가 아니라 베끼기다 (SECURITY R-66).
     */
    $relinked = 0;
    if ($statement !== null) {
        $sqlOpenLines = <<<'SQL'
            SELECT r.id, r.item_code, r.item_name, CAST(r.qty AS text) AS qty,
                   CAST(r.unit_price AS text) AS unit_price
              FROM p_recon_lines r
             WHERE r.statement_id = CAST(:st AS bigint)
               AND r.diff_type = 'missing_ours'
             ORDER BY r.row_no ASC
             LIMIT 2000
            SQL;

        $openRows = $db->run($sqlOpenLines, [':st' => $statementId])->fetchAll();
        foreach ($openRows as $open) {
            foreach ($wanted as $w) {
                $item = $items[$w['item_id']];
                $same = ($open['item_code'] !== null
                         && Recon::squeeze((string) $open['item_code']) === Recon::squeeze((string) $item['code']))
                     || Recon::normalizeName((string) $open['item_name'])
                        === Recon::normalizeName((string) $item['name']);
                if (!$same) {
                    continue;
                }
                $price = $item['price_in'] === null ? null : (float) $item['price_in'];
                $diff  = 'none';
                if (Doc::milli((string) $open['qty']) !== Doc::milli($w['qty'])) {
                    $diff = 'qty';
                } elseif ($open['unit_price'] !== null && $price !== null
                          && abs($price - (float) $open['unit_price']) > Recon::PRICE_EPS) {
                    $diff = 'price';
                }
                $db->run($sqlRelink, [
                    ':line' => $newLines[$w['item_id']],
                    ':diff' => $diff,
                    ':note' => '기공 명세서 전표 ' . $docNo,
                    ':st'   => $statementId,
                    ':id'   => (int) $open['id'],
                ]);
                $relinked++;
                break;
            }
        }
    }

    Audit::allow('purchase.lab.create', [
        'target_type' => 'purchase_doc',
        'target_id'   => $docId,
        'target_name' => $docNo,
        'detail'      => [
            'doc_no'       => $docNo,
            'doc_type'     => Stock::TYPE_GR,
            'branch_id'    => $branchId,
            'vendor_id'    => $vendorId,
            'location_id'  => $locId,
            'month'        => substr($docDate, 0, 7),
            'lines'        => count($wanted),
            'moves'        => count($moveIds),
            'amount'       => number_format($amountSum, 2, '.', ''),
            'statement_id' => $statementId,
            'relinked'     => $relinked,
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
    'id'           => $docId,
    'doc_no'       => $docNo,
    'doc_type'     => Stock::TYPE_GR,
    'doc_date'     => $docDate,
    'branch_id'    => $branchId,
    'vendor_id'    => $vendorId,
    'location_id'  => $locId,
    'status'       => Doc::ST_DONE,
    'lines'        => Stock::lines($db, $docId),
    'moves'        => count($moveIds),
    'statement_id' => $statementId,
    'relinked'     => $relinked,
], 201);
