<?php
declare(strict_types=1);

/**
 * src/handlers/purchase_po_create.php — POST /api/purchase/po  (정책: purchase.buy.write)
 *
 * ★ 20단계 **불러오기 → 발주서** (PRD F-32, ARCH 5.18 「연결」, 완료 기준 T-45).
 *
 * 본문
 *   { to_location_id, vendor_id?, manager_id?, doc_date?, memo?, split?,
 *     lines: [ { src_line_id, qty, vendor_id? } … ] }
 *
 * ★ F-60 **거래처별 분할 저장** (2026-09-17, ARCH 5.18 「분할 저장」, 완료 기준 T-72)
 *   `split: true` 면 담긴 줄을 **판정된 거래처별로 나눠** 발주서를 N 장 만든다.
 *   나누는 것은 ⑤ 이후의 *만드는* 일뿐이고, **판정은 하나도 갈리지 않는다** —
 *   잠금·격리·상태·마감·잔량 검사는 아래 ③ 에서 **전 줄에 대해 한 번** 돌고,
 *   거래처 판정식도 그대로 한 곳이다. 만드는 블록만 묶음마다 반복된다(같은 코드다).
 *   한 트랜잭션·한 commit 이므로 어느 묶음에서 거부돼도 **반 장이 남지 않는다**.
 *   `split` 이 없으면 이 파일은 예전과 한 글자도 다르게 굴지 않는다(응답·상태·감사 불변).
 *
 * 이 파일이 지키는 것 — 여기가 20단계에서 **가장 위험한 쓰기 경로**다.
 *   ① **잔량 초과 거부**: 요청 줄을 `SELECT … FOR UPDATE` 로 잠근 **뒤**에 잔량을 다시 세고,
 *      그보다 많이 담으려 하면 400 `remaining_exceeded`. 잠금 덕분에 동시에 들어온 두 요청
 *      중 한쪽만 통과한다(T-45 동시성 항목).
 *   ② **금액은 서버 것만**: 단가는 `p_items.price_in`, 금액은 `수량 × 단가`.
 *      본문에 `unit_price`·`amount` 를 적어 보내면 허용 키 검사에서 요청 전체가 400 이다 (R-66).
 *   ③ **거래처 하나**: 한 발주서는 한 거래처에 보내는 문서다. 줄들의 거래처가 섞이면
 *      400 `mixed_vendor`.
 *   ④ **담긴 줄은 잠근다**: 요청 줄에 `locked_at` 을 박고 상태를 다시 계산한다 → 그 뒤
 *      요청자의 수정은 400 `line_locked` (Q-18①).
 *   ⑤ 재고는 움직이지 않는다 — 원장은 21단계다.
 *
 * 지점 격리 (SECURITY R-65)
 *   `Authz::purchaseScopeWhere($user, 'd')` 로 **출처 요청 줄이 내 범위 안인지** 보고,
 *   `…($user, 'l')` 로 입고 장소를 본다. 발주서의 `branch_id` 는 요청 값이 아니라
 *   **입고 장소의 지점**에서 나온다.
 *
 * @var array<string,mixed> $user
 * @var list<string>        $params 경로 변수 없음
 */

require_once __DIR__ . '/../purchase/Doc.php';

const TBOX_PO_CREATE_KEYS = ['to_location_id', 'vendor_id', 'manager_id', 'doc_date', 'memo', 'split', 'lines'];
const TBOX_PO_LINE_KEYS   = ['src_line_id', 'qty', 'vendor_id'];

$db   = Db::instance();
$body = Http::jsonBody();

$reject = static function (string $reason, int $status = 400): void {
    Audit::deny('purchase.order.create', $reason, [
        'target_type' => 'purchase_doc',
        'target_name' => 'po',
        'detail'      => ['doc_type' => 'po'],
    ]);
    Http::error($status, Http::MSG_BAD_REQUEST);
};

if ($body === [] || array_diff(array_keys($body), TBOX_PO_CREATE_KEYS) !== []) {
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

$locId     = $asId($body['to_location_id'] ?? null);
$vendorReq = $asId($body['vendor_id'] ?? null);
$mgrReq    = $asId($body['manager_id'] ?? null);
if ($locId === null || $locId === -1 || $vendorReq === -1 || $mgrReq === -1) {
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

/**
 * F-60 — **나눠 저장할까**. 참/거짓만 받는다(문자열 `"true"` 도 400 이다).
 * 없으면 `false` 이고, 그때 이 요청은 20단계와 똑같이 흐른다.
 */
$splitRaw = $body['split'] ?? null;
if ($splitRaw !== null && !is_bool($splitRaw)) {
    $reject('bad_request');
}
$split = ($splitRaw === true);

$lines = $body['lines'] ?? null;
if (!is_array($lines) || $lines === [] || count($lines) > Doc::MAX_LINES) {
    $reject('bad_request');
}

$wanted = [];
$seen   = [];
foreach ($lines as $line) {
    if (!is_array($line) || array_diff(array_keys($line), TBOX_PO_LINE_KEYS) !== []) {
        $reject('bad_request');
    }
    $srcId = $asId($line['src_line_id'] ?? null);
    if ($srcId === null || $srcId === -1) {
        $reject('bad_request');
    }
    if (isset($seen[$srcId])) {
        // 같은 요청 줄을 한 발주서에 두 번 적으면 잔량 판정이 두 갈래가 된다 — 먼저 막는다.
        $reject('bad_request');
    }
    $seen[$srcId] = true;

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
    $lineVendor = $asId($line['vendor_id'] ?? null);
    if ($lineVendor === -1) {
        $reject('bad_request');
    }
    $wanted[] = ['src' => $srcId, 'qty' => $qtyRaw, 'vendor_id' => $lineVendor];
}

// ─── 지점 격리·참조 ─────────────────────────────────────────
$scopeD = Authz::purchaseScopeWhere($user, 'd');
$scopeL = Authz::purchaseScopeWhere($user, 'l');

$branchId = Doc::locationBranch($db, $scopeL, $locId);
if ($branchId === null) {
    $reject('bad_reference');
}
if ($vendorReq !== null && !Doc::vendorOk($db, $vendorReq)) {
    $reject('bad_reference');
}
$managerId = $mgrReq ?? (int) $user['id'];
if ($mgrReq !== null && !Doc::managerOk($db, $mgrReq)) {
    $reject('bad_reference');
}

/**
 * 출처 줄의 전표·품목 정보. **지점 격리 조건이 붙는다** — 범위 밖 요청 줄은 여기서 사라지고
 * 아래 확인에서 `not_in_scope` 로 끝난다.
 */
$sqlSrcHead = <<<'SQL'
    SELECT l.id, l.doc_id, l.item_id, CAST(l.qty AS text) AS qty, l.status,
           d.doc_no, d.doc_type, d.status AS doc_status, d.closed_month,
           d.vendor_id AS doc_vendor_id,
           i.vendor_id AS item_vendor_id, i.is_active AS item_active,
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

$sqlSrcTail = " AND d.doc_type = 'pr' AND ";

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
    $vendors = [];
    $prepared = [];
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

        // 거래처: 줄이 적은 것 → 요청 전표가 적은 것 → 품목의 기본 거래처
        $vendorOfLine = $w['vendor_id']
            ?? ($row['doc_vendor_id'] === null ? (int) $row['item_vendor_id'] : (int) $row['doc_vendor_id']);
        $vendors[$vendorOfLine] = true;

        $prepared[] = [
            'src'       => $w['src'],
            'qty'       => $w['qty'],
            'item_id'   => (int) $row['item_id'],
            'price_in'  => $row['price_in'] === null ? null : (string) $row['price_in'],
            'doc_id'    => (int) $row['doc_id'],
            'doc_no'    => (string) $row['doc_no'],
            'want_qty'  => (string) $row['qty'],
            // 위에서 **이미 판정한** 거래처를 줄에 적어 둔다 — 아래에서 다시 판정하지 않는다.
            'vendor_id' => $vendorOfLine,
        ];
    }

    /*
     * ④ 한 발주서 = 한 거래처. 그 규칙은 그대로다 — F-60 이 바꾼 것은 **거래처가 섞였을 때
     *    무엇을 하느냐**뿐이다: `split:true` 면 나눠 담고, 아니면 예전처럼 거절한다.
     *    `vendor_id` 머리값은 「이 거래처로 한 장」이라는 **명시**라 split 보다 우선한다
     *    (둘을 함께 보내면 섞인 순간 여기서 끝난다 — PRD F-60 ③).
     */
    if (count($vendors) !== 1 && ($split !== true || $vendorReq !== null)) {
        $db->rollback();
        $reject('mixed_vendor');
    }

    /*
     * 거래처별 묶음 — **담긴 순서 그대로**다. 위 ③ 에서 이미 판정해 줄에 적어 둔 값으로만
     * 가르므로 여기에는 새 판정이 한 줄도 없다. split 이 아니면 묶음은 언제나 하나이고
     * 아래 반복문은 한 바퀴만 돈다(20단계와 같은 코드가 같은 횟수만큼 돈다).
     *
     * @var array<int,list<array<string,mixed>>> $buckets
     */
    $buckets = [];
    foreach ($prepared as $p) {
        $buckets[$p['vendor_id']][] = $p;
    }
    $splitCount = count($buckets);

    // ⑤ 발주서 만들기 — **묶음마다** 채번·머리·줄·되짚기·감사. 잔량·격리·상태는 위에서 끝났다.
    $made = [];
    foreach ($buckets as $bucketVendor => $bucket) {
        $vendorId = (int) $bucketVendor;
        if ($vendorReq !== null && $vendorReq !== $vendorId) {
            $db->rollback();
            $reject('mixed_vendor');
        }
        if (!Doc::vendorOk($db, $vendorId)) {
            $db->rollback();
            $reject('bad_reference');
        }

        $docNo = Doc::nextDocNo($db, Doc::TYPE_PO);
        $docId = Doc::insertDoc($db, [
            'doc_type'       => Doc::TYPE_PO,
            'doc_no'         => $docNo,
            'doc_date'       => $docDate,
            'branch_id'      => $branchId,
            'vendor_id'      => $vendorId,
            'to_location_id' => $locId,
            'manager_id'     => $managerId,
            'status'         => Doc::ST_IN_PROGRESS,
            'memo'           => $memo,
            'split_from_id'  => null,
            'created_by'     => (int) $user['id'],
        ]);

        $lineNo    = 1;
        $amountSum = 0.0;
        $srcDocs   = [];
        $srcDocNos = [];
        foreach ($bucket as $p) {
            Doc::insertLine(
                $db,
                $docId,
                $lineNo,
                $p['item_id'],
                $p['qty'],
                $p['price_in'],
                null,
                $p['src'],
                Doc::LINE_UNCONFIRMED
            );
            $calc = Doc::lineAmount($p['qty'], $p['price_in']);
            if ($calc !== null) {
                $amountSum += (float) $calc;
            }
            $lineNo++;

            // ⑥ 담긴 요청 줄을 잠그고 상태를 다시 계산한다 (Q-18①)
            Doc::retouchRequestLine($db, $p['src'], $p['want_qty'], Doc::takenQty($db, $p['src']));
            $srcDocs[$p['doc_id']]   = true;
            $srcDocNos[$p['doc_no']] = true;
        }

        // ⑦ 출처 요청 전표의 상태를 다시 계산한다 (일부 발주 / 완료)
        foreach (array_keys($srcDocs) as $srcDocId) {
            Doc::recalcRequestStatus($db, (int) $srcDocId);
        }

        // ⑧ 발주서 머리 — 서버가 채운다 (Q-18②)
        Doc::saveHeader($db, $docId, Doc::buildHeader($db, $docId, $docNo));

        $detail = [
            'doc_no'     => $docNo,
            'doc_type'   => Doc::TYPE_PO,
            'branch_id'  => $branchId,
            'lines'      => count($bucket),
            'amount'     => number_format($amountSum, 2, '.', ''),
            'src_doc_no' => implode(',', array_keys($srcDocNos)),
        ];
        if ($split) {
            // 나눠 달라고 한 저장에만 붙인다 — `split` 없는 요청의 감사 행은 예전 그대로다.
            $detail['split_count'] = $splitCount;
        }
        Audit::allow('purchase.order.create', [
            'target_type' => 'purchase_doc',
            'target_id'   => $docId,
            'target_name' => $docNo,
            'detail'      => $detail,
        ]);

        $made[] = [
            'id'          => $docId,
            'doc_no'      => $docNo,
            'vendor_id'   => $vendorId,
            'vendor_name' => Doc::vendorName($db, $vendorId),
            'line_count'  => count($bucket),
        ];
    }
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

/*
 * ⑨ 응답. `split:true` 로 부른 요청에는 **언제나 같은 모양**을 돌려준다 —
 * 한 장뿐이어도 `docs` 배열이다(화면이 갈래를 두지 않게 하려는 것이다).
 * `split` 이 없으면 20단계 응답 그대로다: 그때 묶음은 하나뿐이라 아래 변수들이
 * 그 한 장을 가리킨다.
 */
if ($split) {
    $docs = [];
    foreach ($made as $m) {
        $mLines = Doc::lines($db, (int) $m['id']);
        $m['totals'] = Doc::totals($mLines);
        $docs[] = $m;
    }
    Http::json(['docs' => $docs, 'count' => count($docs)], 201);
    return;   // 핸들러는 index.php 의 클로저 안에서 require 된다 — 여기서 끝난다
}

$poLines = Doc::lines($db, $docId);
Http::json([
    'id'        => $docId,
    'doc_no'    => $docNo,
    'doc_type'  => Doc::TYPE_PO,
    'doc_date'  => $docDate,
    'branch_id' => $branchId,
    'vendor_id' => $vendorId,
    'status'    => Doc::ST_IN_PROGRESS,
    'lines'     => $poLines,
    'totals'    => Doc::totals($poLines),
], 201);
