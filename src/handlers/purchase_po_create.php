<?php
declare(strict_types=1);

/**
 * src/handlers/purchase_po_create.php — POST /api/purchase/po  (정책: purchase.buy.write)
 *
 * ★ 20단계 **불러오기 → 발주서** (PRD F-32, ARCH 5.18 「연결」, 완료 기준 T-45).
 *
 * 본문
 *   { to_location_id?, vendor_id?, manager_id?, doc_date?, memo?, split?,
 *     lines: [ { src_line_id, qty, vendor_id? } | { item_id, qty, location_id?, vendor_id? } … ] }
 *
 * ★ F-62 **발주서 담당자 · 3축 분할** (2026-09-17, ARCH 5.18 「발주서 담당자·3축 분할」,
 *   완료 기준 T-74)
 *   나누는 **열쇠가 (거래처, 입고 장소, 담당자)** 세 축이 된다. 담당자 판정은 장소와 같은
 *   모양이다: 머리 `manager_id` 명시가 우선이고, 없으면 요청 줄은 **그 발주요청 전표의**
 *   `manager_id`(아래 `$sqlSrcHead` 가 함께 읽는다), 직접 줄·부족 산출 줄은 로그인 사용자다.
 *   묶음마다 기존 `Doc::managerOk` 를 지난다(새 판정·새 사유 코드 0). `split` 없는 옛 요청은
 *   **머리 `??` 로그인 사용자 하나** 그대로다 — 묶음을 만들기 직전에 그 값으로 덮어써서
 *   담당자 축이 옛 경로에 닿지 않게 한다(계약 불변).
 *
 * ★ F-61 **줄이 정하는 입고 장소 · 직접 줄** (2026-09-17, ARCH 5.18 「발주서 세 갈래·장소 규칙」,
 *   완료 기준 T-73)
 *   ① **장소**: `split:true` 면 머리 `to_location_id` 는 **선택**이다. 없으면 줄이 정한다 —
 *      요청 줄은 **그 발주요청 전표의** `to_location_id`(아래 `$sqlSrcHead` 가 함께 읽는다),
 *      직접 줄은 줄의 `location_id`. 머리값이 있으면 그것이 우선이다(`vendor_id` 와 같은 규칙).
 *      정할 수 없는 줄이 하나라도 있으면 400 `bad_request`.
 *      나누는 **열쇠는 (거래처, 입고 장소)** 가 된다 — F-60 의 거래처 분할을 한 축 넓힌 것이다.
 *      묶음의 장소는 묶음마다 기존 `Doc::locationBranch`·`Doc::locationOk` 를 지난다(새 판정 0).
 *      `split` 없는 옛 요청은 **머리 필수 그대로**이고 지점도 예전처럼 머리 장소에서 나온다.
 *   ② **직접 줄**: 줄에 `item_id` 를 적으면 발주요청 없이 바로 담긴다. 한 줄은 `src_line_id`·
 *      `item_id` 중 **정확히 하나**다(둘 다·둘 다 없음 → 400). 품목은 `Doc::loadItems`(활성·범위,
 *      기존 함수)로 확인하고, 거래처는 줄 `vendor_id` ?? 품목 거래처, 단가는 **품목의** `price_in`
 *      이다 — 본문의 단가는 어느 경로로도 들어오지 않는다 (R-66). `src_line_id` 는 NULL 이라
 *      요청 잔량·잠금·상태 되짚기가 **없다**(잠글 요청 줄이 아예 없다).
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
/** F-61 — 줄은 `src_line_id`(요청 줄) 또는 `item_id`(직접 줄) 중 **하나**를 적는다. */
const TBOX_PO_LINE_KEYS   = ['src_line_id', 'item_id', 'qty', 'vendor_id', 'location_id'];

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

/**
 * F-60 — **나눠 저장할까**. 참/거짓만 받는다(문자열 `"true"` 도 400 이다).
 * 없으면 `false` 이고, 그때 이 요청은 20단계와 똑같이 흐른다.
 *
 * F-61 에서 이 판정이 **머리 검사보다 앞으로** 왔다 — 머리 `to_location_id` 가 필수인지
 * 아닌지가 이 값에 달렸기 때문이다(값을 읽는 순서만 바뀌었고 규칙은 그대로다).
 */
$splitRaw = $body['split'] ?? null;
if ($splitRaw !== null && !is_bool($splitRaw)) {
    $reject('bad_request');
}
$split = ($splitRaw === true);

$locId     = $asId($body['to_location_id'] ?? null);
$vendorReq = $asId($body['vendor_id'] ?? null);
$mgrReq    = $asId($body['manager_id'] ?? null);
if ($locId === -1 || $vendorReq === -1 || $mgrReq === -1) {
    $reject('bad_request');
}
/*
 * F-61 — 머리 장소는 `split:true` 일 때만 생략할 수 있다. `split` 없는 옛 요청에는
 * 20단계와 **같은 검사**가 그대로 걸린다(계약 불변: 머리 장소 없이 부르면 400).
 */
if (!$split && $locId === null) {
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
if (!is_array($lines) || $lines === [] || count($lines) > Doc::MAX_LINES) {
    $reject('bad_request');
}

$wanted    = [];
$seen      = [];
$seenItem  = [];
foreach ($lines as $line) {
    if (!is_array($line) || array_diff(array_keys($line), TBOX_PO_LINE_KEYS) !== []) {
        $reject('bad_request');
    }
    $srcId  = $asId($line['src_line_id'] ?? null);
    $itemId = $asId($line['item_id'] ?? null);
    if ($srcId === -1 || $itemId === -1) {
        $reject('bad_request');
    }
    /*
     * F-61 — 한 줄의 출처는 **정확히 하나**다: 발주요청 줄이거나(`src_line_id`) 직접 고른
     * 품목이거나(`item_id`). 둘을 함께 적으면 "이 줄이 요청의 잔량을 쓰는가" 가 두 갈래가 되고,
     * 둘 다 없으면 무엇을 담으라는 말인지 알 수 없다 — 둘 다 400 이다.
     */
    if (($srcId === null) === ($itemId === null)) {
        $reject('bad_request');
    }
    if ($srcId !== null) {
        if (isset($seen[$srcId])) {
            // 같은 요청 줄을 한 발주서에 두 번 적으면 잔량 판정이 두 갈래가 된다 — 먼저 막는다.
            $reject('bad_request');
        }
        $seen[$srcId] = true;
    } else {
        if (isset($seenItem[$itemId])) {
            // 같은 품목을 직접 줄로 두 번 (요청 줄 중복과 같은 규칙)
            $reject('bad_request');
        }
        $seenItem[$itemId] = true;
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
    $lineVendor = $asId($line['vendor_id'] ?? null);
    if ($lineVendor === -1) {
        $reject('bad_request');
    }
    $lineLoc = $asId($line['location_id'] ?? null);
    if ($lineLoc === -1) {
        $reject('bad_request');
    }
    /*
     * 요청 줄의 입고 장소는 **그 발주요청 전표가** 정한다(또는 머리값). 줄에 적어 보내면
     * 어느 쪽이 이겼는지 사람이 알 수 없으므로 조용히 무시하지 않고 400 으로 되돌린다.
     */
    if ($srcId !== null && $lineLoc !== null) {
        $reject('bad_request');
    }
    $wanted[] = [
        'src'         => $srcId,
        'item_id'     => $itemId,
        'qty'         => $qtyRaw,
        'vendor_id'   => $lineVendor,
        'location_id' => $lineLoc,
    ];
}

// ─── 지점 격리·참조 ─────────────────────────────────────────
$scopeD  = Authz::purchaseScopeWhere($user, 'd');
$scopeL  = Authz::purchaseScopeWhere($user, 'l');
/** 직접 줄의 품목을 볼 수 있는 범위 — 발주요청 만들기와 **같은 조각**이다 (F-61) */
$scopeIb = Authz::purchaseScopeWhere($user, 'ib');

/*
 * `split` 없는 요청 — 20단계 그대로다: 지점은 **머리 장소**에서 나오고, 그 장소가 범위 밖이면
 * `bad_reference` 로 끝난다. `split:true` 인 요청의 장소는 묶음마다 아래에서 확인한다
 * (머리값을 적었든 줄에서 왔든 같은 자리·같은 함수다 — F-61).
 */
$branchId = null;
if (!$split) {
    $branchId = Doc::locationBranch($db, $scopeL, $locId);
    if ($branchId === null) {
        $reject('bad_reference');
    }
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
           d.vendor_id AS doc_vendor_id, d.to_location_id AS doc_location_id,
           d.manager_id AS doc_manager_id,
           i.vendor_id AS item_vendor_id, i.is_active AS item_active,
           CAST(i.price_in AS text) AS price_in
      FROM p_doc_lines l
      JOIN p_docs  d ON d.id = l.doc_id
      JOIN p_items i ON i.id = l.item_id
     WHERE l.id IN
    SQL;

/**
 * 요청 줄과 직접 줄을 갈라 둔다 — 아래 조회·잠금은 **요청 줄에만** 걸린다 (F-61).
 * 직접 줄만으로 이루어진 저장에서는 `$srcIds` 가 비고, 그때 이 조회는 아예 돌지 않는다.
 *
 * @var list<int> $srcIds
 * @var list<int> $directItemIds
 */
$srcIds        = [];
$directItemIds = [];
foreach ($wanted as $w) {
    if ($w['src'] !== null) {
        $srcIds[] = $w['src'];
    } else {
        $directItemIds[] = $w['item_id'];
    }
}

$names = [];
$bind  = [];
foreach ($srcIds as $i => $id) {
    $name        = ':s' . (string) $i;
    $names[]     = $name;
    $bind[$name] = $id;
}
$inList = '(' . implode(', ', $names) . ')';

$sqlSrcTail = " AND d.doc_type = 'pr' AND ";

// ─── 트랜잭션 — 잠그고, 다시 세고, 넣는다 ───────────────────
$db->begin();
try {
    // ① 출처 줄을 **먼저 잠근다** (id 오름차순 — 교착 방지). 직접 줄은 잠글 대상이 없다.
    $locked = Doc::lockLines($db, $srcIds);

    // ② 잠근 뒤에 전표·품목 정보를 읽는다 (지점 격리 포함)
    $src = [];
    if ($srcIds !== []) {
        $srcRows = $db->run(
            $sqlSrcHead . ' ' . $inList . $sqlSrcTail . $scopeD['sql'],
            $bind + $scopeD['params']
        )->fetchAll();
        foreach ($srcRows as $row) {
            $src[(int) $row['id']] = $row;
        }
    }

    /*
     * ②-2 직접 줄의 품목 — **활성·범위 안**인 것만 돌아온다 (기존 `Doc::loadItems`,
     * 발주요청 만들기와 같은 함수·같은 조각). 사유를 가르려고 범위 없이 한 번 더 읽는다:
     * 범위를 빼고도 없으면 없는·중단된 품목(`bad_request`), 범위를 빼면 있으면 남의 지점
     * 품목(`not_in_scope`)이다. 어느 쪽이든 사람에게 나가는 응답은 400 동일 문구 하나다.
     */
    $directItems = Doc::loadItems($db, $scopeIb, $directItemIds);
    $activeItems = [];
    if ($directItemIds !== []) {
        $activeItems = Doc::loadItems($db, ['sql' => '1=1', 'params' => []], $directItemIds);
    }

    // ③ 줄마다 확인 — 존재·범위·상태·잔량
    $vendors = [];
    $prepared = [];
    foreach ($wanted as $w) {
        /*
         * ③-0 **직접 줄** (F-61) — 요청 줄이 아니므로 잔량도 잠금도 없다. 확인하는 것은
         * 품목이 살아 있고 내 범위 안인가와, 이 줄의 입고 장소를 정할 수 있는가 둘뿐이다.
         * 단가는 **품목의 값**을 그대로 옮긴다 (본문의 단가는 허용 키에 없다 — R-66).
         * 아래 요청 줄 경로 코드는 20단계·F-60 그대로이며 이 갈래가 그 앞에서 끝난다.
         */
        if ($w['src'] === null) {
            $item = $directItems[$w['item_id']] ?? null;
            if ($item === null) {
                $db->rollback();
                $reject(isset($activeItems[$w['item_id']]) ? 'not_in_scope' : 'bad_request');
            }
            $lineLoc = $locId ?? $w['location_id'];
            if ($lineLoc === null) {
                $db->rollback();
                $reject('bad_request');
            }
            $vendorOfLine           = $w['vendor_id'] ?? (int) $item['vendor_id'];
            $vendors[$vendorOfLine] = true;
            $prepared[] = [
                'src'         => null,
                'qty'         => $w['qty'],
                'item_id'     => (int) $w['item_id'],
                'price_in'    => $item['price_in'] === null ? null : (string) $item['price_in'],
                'doc_id'      => null,
                'doc_no'      => null,
                'want_qty'    => null,
                'vendor_id'   => $vendorOfLine,
                'location_id' => (int) $lineLoc,
                /*
                 * F-62 ② — 직접 줄의 담당자. 머리 명시가 우선이고, 없으면 **로그인 사용자**다:
                 * 발주요청서가 없으니 물려받을 담당자가 없다(부족 산출 줄도 같은 갈래다).
                 */
                'manager_id'  => $mgrReq ?? (int) $user['id'],
            ];
            continue;
        }

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

        /*
         * 입고 장소 — 머리값이 있으면 그것, 없으면 **이 줄이 온 발주요청 전표의 장소**다
         * (F-61 ②: 「요청서와 같은 창고」). 둘 다 없으면 이 줄은 어디로 받을지 알 수 없다.
         */
        $lineLoc = $locId
            ?? ($row['doc_location_id'] === null ? null : (int) $row['doc_location_id']);
        if ($lineLoc === null) {
            $db->rollback();
            $reject('bad_request');
        }

        $prepared[] = [
            'src'         => $w['src'],
            'qty'         => $w['qty'],
            'item_id'     => (int) $row['item_id'],
            'price_in'    => $row['price_in'] === null ? null : (string) $row['price_in'],
            'doc_id'      => (int) $row['doc_id'],
            'doc_no'      => (string) $row['doc_no'],
            'want_qty'    => (string) $row['qty'],
            // 위에서 **이미 판정한** 거래처를 줄에 적어 둔다 — 아래에서 다시 판정하지 않는다.
            'vendor_id'   => $vendorOfLine,
            'location_id' => (int) $lineLoc,
            /*
             * F-62 ② — 담당자. 머리 명시가 우선이고(거래처·장소와 **같은 규칙**), 없으면
             * **이 줄이 온 발주요청 전표의 담당자**다. `p_docs.manager_id` 는 NOT NULL 이라
             * 위 조회가 늘 값을 준다 — 「정할 수 없는 줄」 갈래가 없다.
             */
            'manager_id'  => $mgrReq ?? (int) $row['doc_manager_id'],
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
     * F-62 — `split` 이 아닌 요청의 담당자는 **옛 계약 하나**다: 머리 `manager_id` ?? 로그인
     * 사용자. 위 ③ 이 줄마다 적어 둔 값을 여기서 그 하나로 덮어써서, 아래 묶음·머리·응답이
     * 20단계와 **한 글자도 다르지 않게** 굴도록 한다(줄이 정하는 담당자는 split 의 기능이다).
     */
    if ($split !== true) {
        foreach ($prepared as $i => $p) {
            $prepared[$i]['manager_id'] = $managerId;
        }
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
        // F-61 — 열쇠가 **(거래처, 입고 장소)** 로 넓어졌다. F-60 에서 장소가 하나뿐이던
        // 저장은 열쇠의 뒤 칸이 늘 같으므로 그때와 **같은 수의 묶음**이 나온다.
        // F-62 — 여기에 **담당자**가 더해져 열쇠가 세 축이 됐다. 담당자가 하나뿐인 저장은
        // 뒤 칸이 늘 같으므로 F-61 과 **같은 수의 묶음**이 나온다(같은 방식의 확장이다).
        $key = (string) $p['vendor_id'] . ':' . (string) $p['location_id']
             . ':' . (string) $p['manager_id'];
        if (!isset($buckets[$key])) {
            $buckets[$key] = [
                'vendor_id'   => $p['vendor_id'],
                'location_id' => $p['location_id'],
                'manager_id'  => $p['manager_id'],
                'lines'       => [],
            ];
        }
        $buckets[$key]['lines'][] = $p;
    }
    $splitCount = count($buckets);

    // ⑤ 발주서 만들기 — **묶음마다** 채번·머리·줄·되짚기·감사. 잔량·격리·상태는 위에서 끝났다.
    $made = [];
    foreach ($buckets as $group) {
        $vendorId  = (int) $group['vendor_id'];
        $bucketLoc = (int) $group['location_id'];
        $bucketMgr = (int) $group['manager_id'];
        $bucket    = $group['lines'];
        if ($vendorReq !== null && $vendorReq !== $vendorId) {
            $db->rollback();
            $reject('mixed_vendor');
        }
        if (!Doc::vendorOk($db, $vendorId)) {
            $db->rollback();
            $reject('bad_reference');
        }

        /*
         * 묶음의 입고 장소 — **기존 함수 둘**을 지난다 (F-61, 새 판정 없음).
         * `split` 없는 요청은 위에서 이미 지점을 얻었으므로 그 값을 그대로 쓴다(거동 불변).
         */
        $bucketBranch = $branchId;
        if ($bucketBranch === null) {
            $bucketBranch = Doc::locationBranch($db, $scopeL, $bucketLoc);
            if ($bucketBranch === null) {
                $db->rollback();
                $reject('not_in_scope');
            }
        }
        if (!Doc::locationOk($db, $scopeL, $bucketLoc, $bucketBranch)) {
            $db->rollback();
            $reject('not_in_scope');
        }

        /*
         * 묶음의 담당자 — **기존 함수** `Doc::managerOk` 하나를 지난다 (F-62 ③, 새 판정 0).
         * 요청 전표가 이미 통과한 값이지만 그 사이 계정이 중단될 수 있으므로 묶음마다 본다.
         * 실패는 기존 사유 `bad_reference` 다(새 사유 코드 0).
         *
         * ★ 다시 보는 것은 **물려받은 값**뿐이다. 머리 명시(`$mgrReq`)는 위 머리 검사에서
         *   이미 같은 함수를 지났고, 로그인 사용자는 20단계부터 이 경로의 기본값이라 검사
         *   대상이 아니었다 — 그 둘에 검사를 새로 붙이면 모듈 역할 없는 Admin(라우트 정책
         *   `purchase.buy.write` 는 통과한다)의 저장이 갑자기 400 이 되어, `split` 없는 옛
         *   요청의 거동이 바뀐다. F-62 는 담당자 축을 **넓히는** 일이고 좁히는 일이 아니다.
         */
        if ($bucketMgr !== $mgrReq && $bucketMgr !== (int) $user['id']
            && !Doc::managerOk($db, $bucketMgr)
        ) {
            $db->rollback();
            $reject('bad_reference');
        }

        $docNo = Doc::nextDocNo($db, Doc::TYPE_PO);
        $docId = Doc::insertDoc($db, [
            'doc_type'       => Doc::TYPE_PO,
            'doc_no'         => $docNo,
            'doc_date'       => $docDate,
            'branch_id'      => $bucketBranch,
            'vendor_id'      => $vendorId,
            'to_location_id' => $bucketLoc,
            // F-62 — 묶음의 담당자. `split` 이 아닌 요청에서는 위에서 덮어쓴 $managerId 다.
            'manager_id'     => $bucketMgr,
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
            //    직접 줄에는 되짚을 요청 줄이 없다 — 잔량도 잠금도 없는 줄이다 (F-61).
            if ($p['src'] === null) {
                continue;
            }
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
            'doc_no'    => $docNo,
            'doc_type'  => Doc::TYPE_PO,
            'branch_id' => $bucketBranch,
            'lines'     => count($bucket),
            'amount'    => number_format($amountSum, 2, '.', ''),
        ];
        if ($srcDocNos !== []) {
            // 직접 줄만으로 만든 발주서에는 출처 요청이 **없다** — 빈 칸을 남기지 않는다 (F-61).
            $detail['src_doc_no'] = implode(',', array_keys($srcDocNos));
        }
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
            // F-61 — 어느 장소로 받는 장인지. 머리값을 비우고 저장했을 때 화면·시험이
            // 「요청서와 같은 창고로 갔는가」 를 눈으로 확인할 수 있어야 한다.
            'to_location_id' => $bucketLoc,
            // F-62 — 어느 담당자의 장인지. **번호만** 낸다(이름을 내려면 새 조회가 필요하고,
            // 화면은 담은 줄에서 이미 담당자 이름을 들고 있다).
            'manager_id'     => $bucketMgr,
            'line_count'     => count($bucket),
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
