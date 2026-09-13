<?php
declare(strict_types=1);

/**
 * src/handlers/purchase_pr_create.php — POST /api/purchase/pr  (정책: purchase.request.write)
 *
 * ★ 20단계 발주요청 만들기 (PRD F-30, ARCH 5.18, 시안 ① Main).
 *
 * 본문
 *   { doc_date?, branch_id?, to_location_id?, vendor_id?, memo?,
 *     lines: [ { item_id, qty, remark? } … ] }
 *
 * 지키는 것
 *   · **금액·단가를 받지 않는다.** 줄의 단가는 서버가 `p_items.price_in` 에서 읽고
 *     금액은 `수량 × 단가` 로 계산한다 (SECURITY R-66, 완료 기준 「클라이언트 amount 무시」).
 *   · 지점은 `Authz::purchaseScopeWhere` 로 확인한다 — 요청자가 본문에 남의 지점을 적으면
 *     `Doc::resolveBranch` 가 null 을 돌려주고 400 으로 끝난다 (PRD S-01, R-65).
 *   · 품목도 같은 조건으로 좁힌다 — 다른 지점 전용 품목의 id 를 적어 보내도 목록에서 사라진다.
 *   · 재고는 **움직이지 않는다**. 원장(`p_stock_moves`)은 21단계다.
 *
 * 실패 응답은 400 **동일 문구** 하나이고 사유는 감사 로그에만 남는다 (PRD S-09-5).
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자 (created_by·manager_id 는 이 값만)
 * @var list<string>        $params 경로 변수 없음
 */

require_once __DIR__ . '/../purchase/Doc.php';

/** 본문에서 받을 수 있는 키 — 목록 밖이 하나라도 있으면 요청 전체를 거부 (SECURITY R-07) */
const TBOX_PR_CREATE_KEYS = ['doc_date', 'branch_id', 'to_location_id', 'vendor_id', 'memo', 'lines'];
const TBOX_PR_LINE_KEYS   = ['item_id', 'qty', 'remark'];

$db   = Db::instance();
$body = Http::jsonBody();

$reject = static function (string $reason, int $status = 400): void {
    Audit::deny('purchase.request.create', $reason, [
        'target_type' => 'purchase_doc',
        'target_name' => 'pr',
        'detail'      => ['doc_type' => 'pr'],
    ]);
    Http::error($status, Http::MSG_BAD_REQUEST);
};

if ($body === [] || array_diff(array_keys($body), TBOX_PR_CREATE_KEYS) !== []) {
    $reject('bad_request');
}

// ─── 값 좁히기 ───────────────────────────────────────────────
$docDate = $body['doc_date'] ?? null;
if ($docDate === null) {
    $docDate = date('Y-m-d');
}
if (!is_string($docDate) || preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $docDate) !== 1) {
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

$branchReq = $asId($body['branch_id'] ?? null);
$locReq    = $asId($body['to_location_id'] ?? null);
$vendorReq = $asId($body['vendor_id'] ?? null);
if ($branchReq === -1 || $locReq === -1 || $vendorReq === -1) {
    $reject('bad_request');
}

// 이 서버에는 mbstring 이 없다(php -m: iconv 만). UTF-8 확인은 PCRE 의 `//u` 로 한다 —
// Audit::cut 이 쓰는 것과 같은 방법이다.
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
if (!is_array($lines) || $lines === [] || count($lines) > Doc::MAX_LINES) {
    $reject('bad_request');
}

$wanted = [];
foreach ($lines as $line) {
    if (!is_array($line) || array_diff(array_keys($line), TBOX_PR_LINE_KEYS) !== []) {
        $reject('bad_request');
    }
    $itemId = $asId($line['item_id'] ?? null);
    if ($itemId === null || $itemId === -1) {
        $reject('bad_request');
    }
    $qtyRaw = $line['qty'] ?? null;
    if (is_int($qtyRaw) || is_float($qtyRaw)) {
        $qtyRaw = (string) $qtyRaw;
    }
    if (!is_string($qtyRaw) || preg_match('/^[0-9]{1,10}(\.[0-9]{1,3})?$/', $qtyRaw) !== 1) {
        $reject('bad_request');
    }
    if (Doc::milli($qtyRaw) <= 0) {
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
        'qty'     => $qtyRaw,
        'remark'  => $remark === '' ? null : $remark,
    ];
}

// ─── 지점 격리 (SECURITY R-65) ───────────────────────────────
// 세 별칭 모두 같은 함수 하나에서 나온다. 요청의 값은 이 판정을 바꾸지 못한다.
$scopeB  = Authz::purchaseScopeWhere($user, 'b');
$scopeL  = Authz::purchaseScopeWhere($user, 'l');
$scopeIb = Authz::purchaseScopeWhere($user, 'ib');

$branchId = Doc::resolveBranch($db, $scopeB, $branchReq);
if ($branchId === null) {
    // 지점을 못 정했다 — 남의 지점을 적었거나(격리), 구매팀이 지점을 비웠거나.
    $reject($branchReq === null ? 'branch_required' : 'not_in_scope');
}

if ($locReq !== null && !Doc::locationOk($db, $scopeL, $locReq, $branchId)) {
    $reject('bad_reference');
}
if ($vendorReq !== null && !Doc::vendorOk($db, $vendorReq)) {
    $reject('bad_reference');
}

$items = Doc::loadItems($db, $scopeIb, array_column($wanted, 'item_id'));
foreach ($wanted as $w) {
    if (!isset($items[$w['item_id']])) {
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
        'vendor_id'      => $vendorReq,
        'to_location_id' => $locReq,
        // 요청의 담당자는 **언제나 본인**이다. 본문으로 남을 담당자로 적게 하면
        // 추천(R-68)의 집계 기준이 흔들린다 — 발주서와 달리 대리 작성이 없다.
        'manager_id'     => (int) $user['id'],
        'status'         => Doc::ST_UNCONFIRMED,
        'memo'           => $memo,
        'split_from_id'  => null,
        'created_by'     => (int) $user['id'],
    ]);

    $lineNo = 1;
    $amountSum = 0.0;
    foreach ($wanted as $w) {
        $item  = $items[$w['item_id']];
        $price = $item['price_in'] === null ? null : (string) $item['price_in'];
        Doc::insertLine($db, $docId, $lineNo, $w['item_id'], $w['qty'], $price, $w['remark'], null);
        $calc = Doc::lineAmount($w['qty'], $price);
        if ($calc !== null) {
            $amountSum += (float) $calc;
        }
        $lineNo++;
    }

    Audit::allow('purchase.request.create', [
        'target_type' => 'purchase_doc',
        'target_id'   => $docId,
        'target_name' => $docNo,
        'detail'      => [
            'doc_no'    => $docNo,
            'doc_type'  => Doc::TYPE_PR,
            'branch_id' => $branchId,
            'lines'     => count($wanted),
            'amount'    => number_format($amountSum, 2, '.', ''),
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
    'id'        => $docId,
    'doc_no'    => $docNo,
    'doc_type'  => Doc::TYPE_PR,
    'doc_date'  => $docDate,
    'branch_id' => $branchId,
    'status'    => Doc::ST_UNCONFIRMED,
    'lines'     => $rows,
    'totals'    => Doc::totals($rows),
], 201);
