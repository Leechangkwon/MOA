<?php
declare(strict_types=1);

/**
 * src/handlers/purchase_suggest_apply.php — POST /api/purchase/suggest/apply
 *   (정책: purchase.request.write → requester·buyer·Admin)
 *
 * ★ 23단계 **고른 제안을 발주요청 전표로** (PRD F-36, 시안 ⑩ 의 「요청에 담기」).
 *
 * 본문
 *   { location_id, doc_date?, memo?, lines: [ { item_id, qty? } … ] }
 *     qty 를 적지 않으면 **서버가 계산한 제안값** 그대로 담는다.
 *     qty 를 적으면 담당자가 고친 값이며, 서버가 **최소구매단위의 배수인지 다시 본다**
 *     (배수가 아니면 400 `not_multiple` — 완료 기준 T-48 b).
 *
 * 지키는 것
 *   · **제안을 다시 계산한다.** 화면이 보낸 제안값은 읽지 않는다 — 화면을 본 뒤 다른 창에서
 *     입고가 들어왔을 수 있고, 무엇보다 수량의 근거가 요청 본문이면 그것은 근거가 아니다
 *     (SECURITY R-66 과 같은 원칙: 금액도 수량의 근거도 서버가 만든 값만 쓴다).
 *   · **제안이 0 인 줄은 담지 않는다** — 이 창구는 "부족한 것을 시키는" 길이고,
 *     아무 품목이나 요청 전표로 넣는 우회로가 되면 `POST /api/purchase/pr` 의 계약이 흐려진다.
 *   · **장소 하나 = 전표 하나.** 요청 전표의 지점은 **장소에서 나온다**(요청 값이 아니다).
 *     범위 밖 장소면 `Doc::locationBranch` 가 null 을 돌려주고 400 으로 끝난다 (R-65).
 *   · **단가·금액을 받지 않는다.** 줄의 단가는 서버가 `p_items.price_in` 에서 읽는다.
 *   · 재고는 움직이지 않는다 — 여기서 만드는 것은 **요청**이다 (원장은 `Stock.php` 뿐).
 *
 * 실패 응답은 400 **동일 문구** 하나이고 사유는 감사 로그에만 남는다 (PRD S-09-5).
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자 (created_by·manager_id 는 이 값만)
 * @var list<string>        $params 경로 변수 없음
 */

require_once __DIR__ . '/../purchase/Suggest.php';

/** 본문에서 받을 수 있는 키 — 목록 밖이 하나라도 있으면 요청 전체를 거부 (SECURITY R-07) */
const TBOX_SUGGEST_APPLY_KEYS = ['location_id', 'doc_date', 'memo', 'lines'];
const TBOX_SUGGEST_LINE_KEYS  = ['item_id', 'qty'];

$db   = Db::instance();
$body = Http::jsonBody();

$reject = static function (string $reason, int $status = 400): void {
    Audit::deny('purchase.suggest.apply', $reason, [
        'target_type' => 'purchase_doc',
        'target_name' => 'pr',
        'detail'      => ['doc_type' => 'pr'],
    ]);
    Http::error($status, Http::MSG_BAD_REQUEST);
};

if ($body === [] || array_diff(array_keys($body), TBOX_SUGGEST_APPLY_KEYS) !== []) {
    $reject('bad_request');
}

$asId = static function ($v): ?int {
    if ($v === null || $v === '') {
        return null;
    }
    if (!is_int($v) && !(is_string($v) && preg_match('/^[0-9]{1,9}$/', $v) === 1)) {
        return -1;   // 형식 오류 표식
    }
    $n = (int) $v;
    return $n >= 1 ? $n : -1;
};

$locationId = $asId($body['location_id'] ?? null);
if ($locationId === null || $locationId === -1) {
    $reject('bad_request');
}

$docDate = $body['doc_date'] ?? null;
if ($docDate === null) {
    $docDate = date('Y-m-d');
}
if (!is_string($docDate) || preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $docDate) !== 1) {
    $reject('bad_request');
}

// 이 서버에는 mbstring 이 없다(php -m: iconv 만). UTF-8 확인은 PCRE 의 `//u` 로 한다.
$memo = $body['memo'] ?? null;
if ($memo !== null && !is_string($memo)) {
    $reject('bad_request');
}
if (is_string($memo) && (preg_match('//u', $memo) !== 1 || iconv_strlen($memo, 'UTF-8') > 500)) {
    $reject('bad_request');
}
if ($memo === null || $memo === '') {
    // 지시서 보충 2 — 「발주 제안 YYYY-MM-DD」. 어디서 온 요청인지가 전표에 남는다.
    $memo = Suggest::memo($docDate);
}

$lines = $body['lines'] ?? null;
if (!is_array($lines) || $lines === [] || count($lines) > Suggest::MAX_APPLY_LINES) {
    $reject('bad_request');
}

/** @var array<int,?string> 품목 id => 담당자가 고친 수량(없으면 null = 제안값 그대로) */
$wanted = [];
foreach ($lines as $line) {
    if (!is_array($line) || array_diff(array_keys($line), TBOX_SUGGEST_LINE_KEYS) !== []) {
        $reject('bad_request');
    }
    $itemId = $asId($line['item_id'] ?? null);
    if ($itemId === null || $itemId === -1) {
        $reject('bad_request');
    }
    if (isset($wanted[$itemId])) {
        $reject('bad_request');   // 같은 품목을 두 번 담지 않는다
    }
    $qtyRaw = $line['qty'] ?? null;
    if (is_int($qtyRaw) || is_float($qtyRaw)) {
        $qtyRaw = (string) $qtyRaw;
    }
    if ($qtyRaw !== null && $qtyRaw !== '') {
        if (!is_string($qtyRaw) || preg_match('/^[0-9]{1,10}(\.[0-9]{1,3})?$/', $qtyRaw) !== 1) {
            $reject('bad_request');
        }
        if (Doc::milli($qtyRaw) <= 0) {
            $reject('bad_request');
        }
    } else {
        $qtyRaw = null;
    }
    $wanted[$itemId] = $qtyRaw;
}

// ─── 지점 격리 (SECURITY R-65) ───────────────────────────────
// 지점은 **장소에서 나온다.** 요청이 적은 값이 이 판정을 바꾸지 못한다.
$scopeL   = Authz::purchaseScopeWhere($user, 'l');
$branchId = Doc::locationBranch($db, $scopeL, $locationId);
if ($branchId === null) {
    $reject('not_in_scope');
}

// ─── 제안을 **다시** 계산한다 (화면이 보낸 값은 읽지 않는다) ──
$found = Suggest::rows($db, $scopeL, [
    'location_id' => $locationId,
    'branch_id'   => 0,
    'cat1'        => 0,
    'q'           => '',
    'gap_short'   => false,
    'only_short'  => true,                 // 제안이 있는 줄만
    'page'        => 1,
    'per_page'    => Suggest::MAX_APPLY_LINES,
]);

$byItem = [];
foreach ($found['items'] as $row) {
    $byItem[$row['item_id']] = $row;
}

$plan       = [];
$suggestSum = 0;
$appliedSum = 0;
foreach ($wanted as $itemId => $qtyRaw) {
    if (!isset($byItem[$itemId])) {
        // 그 장소에 제안이 없는 품목이다 — 없는 것과 보이지 않는 것을 구별해 주지 않는다.
        $reject('no_suggestion');
    }
    $row      = $byItem[$itemId];
    $suggest  = (string) $row['suggest_qty'];
    $applied  = $qtyRaw ?? $suggest;

    if (!Suggest::isMultiple($applied, (int) $row['min_order_qty'])) {
        $reject('not_multiple');
    }
    if (Doc::milli($applied) <= 0) {
        $reject('bad_request');
    }

    $suggestSum += Doc::milli($suggest);
    $appliedSum += Doc::milli($applied);
    $plan[] = [
        'item_id'  => (int) $itemId,
        'qty'      => $applied,
        'suggest'  => $suggest,
        // 근거를 줄의 비고에 남긴다 — 나중에 "왜 이 수량이었나" 를 전표만 보고 되짚는다.
        'remark'   => '제안 ' . $suggest . ' (인가 ' . (string) ($row['par_qty'] ?? 0)
                    . ' · 안전 ' . (string) $row['safety_qty']
                    . ' · 현재고 ' . (string) $row['on_hand']
                    . ' · 미입고 ' . (string) $row['incoming']
                    . ' · 조달 ' . (string) $row['lead_days'] . '일 예상 ' . (string) $row['lead_use']
                    . ' · 최소 ' . (string) $row['min_order_qty'] . ')',
    ];
}

// 줄의 단가는 **서버가 DB 에서 읽는다** (SECURITY R-66). 범위 밖 품목이면 여기서 사라진다.
$scopeIb = Authz::purchaseScopeWhere($user, 'ib');
$items   = Doc::loadItems($db, $scopeIb, array_column($plan, 'item_id'));
foreach ($plan as $p) {
    if (!isset($items[$p['item_id']])) {
        $reject('bad_reference');
    }
}

// ─── 트랜잭션: 채번 → 전표 → 줄 → 감사 → COMMIT ─────────────
$db->begin();
try {
    $docNo = Doc::nextDocNo($db, Doc::TYPE_PR);
    $docId = Doc::insertDoc($db, [
        'doc_type'       => Doc::TYPE_PR,
        'doc_no'         => $docNo,
        'doc_date'       => $docDate,
        'branch_id'      => $branchId,
        // 제안은 품목마다 거래처가 다를 수 있다 — 거래처는 발주서를 만들 때 갈린다.
        'vendor_id'      => null,
        'to_location_id' => $locationId,
        // 요청의 담당자는 **언제나 본인**이다 (`purchase_pr_create.php` 와 같은 규칙).
        'manager_id'     => (int) $user['id'],
        'status'         => Doc::ST_UNCONFIRMED,
        'memo'           => $memo,
        'split_from_id'  => null,
        'created_by'     => (int) $user['id'],
    ]);

    $lineNo    = 1;
    $amountSum = 0.0;
    foreach ($plan as $p) {
        $item  = $items[$p['item_id']];
        $price = $item['price_in'] === null ? null : (string) $item['price_in'];
        Doc::insertLine($db, $docId, $lineNo, $p['item_id'], $p['qty'], $price, $p['remark'], null);
        $calc = Doc::lineAmount($p['qty'], $price);
        if ($calc !== null) {
            $amountSum += (float) $calc;
        }
        $lineNo++;
    }

    Audit::allow('purchase.suggest.apply', [
        'target_type' => 'purchase_doc',
        'target_id'   => $docId,
        'target_name' => $docNo,
        'detail'      => [
            'doc_no'        => $docNo,
            'doc_type'      => Doc::TYPE_PR,
            'branch_id'     => $branchId,
            'location_id'   => $locationId,
            'lines'         => count($plan),
            // 지시서 보충 2 — 제안값과 실제로 담은 값을 **둘 다** 남긴다.
            // 담당자가 제안을 얼마나 고쳤는지가 이 두 숫자 사이에 있다.
            'suggested_qty' => Doc::qtyText($suggestSum),
            'applied_qty'   => Doc::qtyText($appliedSum),
            'amount'        => number_format($amountSum, 2, '.', ''),
        ],
    ]);
    $db->commit();
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

$rows = Doc::lines($db, $docId);
Http::json([
    'id'            => $docId,
    'doc_no'        => $docNo,
    'doc_type'      => Doc::TYPE_PR,
    'doc_date'      => $docDate,
    'branch_id'     => $branchId,
    'status'        => Doc::ST_UNCONFIRMED,
    'memo'          => $memo,
    'suggested_qty' => Doc::qtyText($suggestSum),
    'applied_qty'   => Doc::qtyText($appliedSum),
    'lines'         => $rows,
    'totals'        => Doc::totals($rows),
], 201);
