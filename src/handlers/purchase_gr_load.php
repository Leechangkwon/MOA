<?php
declare(strict_types=1);

/**
 * src/handlers/purchase_gr_load.php — GET /api/purchase/gr/load  (정책: purchase.stock.write)
 *
 * ★ 21단계 **발주서 불러오기 → 입고** (PRD F-33, 시안 ⑥ GR).
 *
 *   ?po=<발주서 id>   그 발주서만
 *   ?vendor=&branch=&location=   걸개 (지점 격리 조건과 AND 로 걸린다)
 *   ?q=               발주서 번호·품목 검색
 *
 * 돌려주는 것은 **미입고 잔량이 남은 발주서 줄**뿐이다 — 뷰 `p_backorder` 가 그 정의다:
 *     미입고 = 발주 줄 qty − Σ(그 줄을 출처로 하는 입고 줄 qty, 취소 제외) > 0
 * 그리고 **「보낸」 발주서만**(`sent_at IS NOT NULL`) 나온다. 아직 거래처에 나가지 않은
 * 문서의 물건이 도착할 수는 없으므로, 화면 이전 단계에서 없앤다.
 * (그래도 입고 API 는 FOR UPDATE 뒤 잔량을 다시 본다 — 화면은 근거가 아니다.)
 *
 * 지점 격리 (SECURITY R-65)
 *   `Authz::purchaseScopeWhere($user, 'd')` 를 발주서(`p_docs`)에 건다. stock 역할은
 *   자기 지점 발주서만 보이고, `?branch=` 에 남의 지점을 적어도 조건은 바뀌지 않는다.
 *
 * 조회는 감사 로그를 남기지 않는다.
 *
 * @var array<string,mixed> $user
 * @var list<string>        $params 경로 변수 없음
 */

require_once __DIR__ . '/../purchase/Stock.php';

/** 한 번에 돌려주는 줄 수 상한 (화면은 발주서별로 묶어 보여 준다) */
const TBOX_GR_LOAD_MAX = 300;

$db = Db::instance();

$poId     = Http::queryInt('po', 0, 1, 999999999);
$vendor   = Http::queryInt('vendor', 0, 1, 999999999);
$branch   = Http::queryInt('branch', 0, 1, 999999999);
$location = Http::queryInt('location', 0, 1, 999999999);
$overdue  = Http::queryChoice('overdue', ['0', '1'], '0') === '1';
$q        = Http::queryText('q', 100);

$scope = Authz::purchaseScopeWhere($user, 'd');

$where = [$scope['sql']];
$bind  = $scope['params'];

if ($poId > 0) {
    $where[]     = 'bo.po_doc_id = CAST(:po AS bigint)';
    $bind[':po'] = $poId;
}
if ($vendor > 0) {
    $where[]         = 'bo.vendor_id = CAST(:vendor AS bigint)';
    $bind[':vendor'] = $vendor;
}
if ($branch > 0) {
    $where[]         = 'bo.branch_id = CAST(:branch AS bigint)';
    $bind[':branch'] = $branch;
}
if ($location > 0) {
    $where[]      = 'bo.to_location_id = CAST(:loc AS bigint)';
    $bind[':loc'] = $location;
}
if ($overdue) {
    // 「납기 지남」 — 머리 정보의 납기가 아니라 **보낸 날 + 품목 조달기간**으로 본다.
    // 납기는 buyer 가 고칠 수 있는 자유 값이라 판정의 근거로 쓰지 않는다.
    $where[] = "bo.sent_at + (i.lead_days || ' days')::interval < now()";
}
if ($q !== '') {
    $bind[':q'] = '%' . str_replace(['#', '%', '_'], ['##', '#%', '#_'], $q) . '%';
    $where[]    = "(bo.po_doc_no ILIKE :q ESCAPE '#' OR i.name ILIKE :q ESCAPE '#'"
                . " OR i.code ILIKE :q ESCAPE '#')";
}

/**
 * 미입고 뷰(`p_backorder`) + 전표·품목·장소 이름. 지점 격리는 `p_docs d` 에 건다 —
 * 뷰에는 권한 조건이 없고, 조건을 거는 곳은 언제나 Authz 한 곳이다 (ARCH 4.1).
 */
$sqlHead = <<<'SQL'
    SELECT bo.po_line_id, bo.po_doc_id, bo.po_doc_no, CAST(bo.po_date AS text) AS po_date,
           CAST(bo.sent_at AS text) AS sent_at,
           bo.branch_id, bo.to_location_id, bo.vendor_id, bo.item_id,
           CAST(bo.ordered_qty AS text)   AS ordered_qty,
           CAST(bo.received_qty AS text)  AS received_qty,
           CAST(bo.remaining_qty AS text) AS remaining_qty,
           CAST(bo.unit_price AS text)    AS unit_price,
           i.code, i.name AS item_name, i.spec, i.unit, i.lead_days, i.stock_managed,
           v.name AS vendor_name, br.name AS branch_name, loc.name AS location_name,
           mgr.username AS manager_name,
           (date_part('day', now() - bo.sent_at))::int AS days_elapsed
      FROM p_backorder bo
      JOIN p_docs      d   ON d.id = bo.po_doc_id
      JOIN p_items     i   ON i.id = bo.item_id
      LEFT JOIN p_vendors   v   ON v.id  = bo.vendor_id
      LEFT JOIN p_branches  br  ON br.id = bo.branch_id
      LEFT JOIN p_locations loc ON loc.id = bo.to_location_id
      LEFT JOIN users       mgr ON mgr.id = bo.manager_id
     WHERE
    SQL;

$bind[':lim'] = TBOX_GR_LOAD_MAX;

$rows = $db->run(
    $sqlHead . ' ' . implode(' AND ', $where)
        . ' ORDER BY bo.sent_at ASC, bo.po_doc_id ASC, bo.po_line_id ASC LIMIT :lim',
    $bind
)->fetchAll();

// ─── 발주서별로 묶는다 (시안 ⑥ — 한 전표가 한 묶음) ─────────
$orders = [];
$lineCount = 0;
foreach ($rows as $row) {
    $docId = (int) $row['po_doc_id'];
    if (!isset($orders[$docId])) {
        $orders[$docId] = [
            'po_doc_id'     => $docId,
            'po_doc_no'     => (string) $row['po_doc_no'],
            'po_date'       => (string) $row['po_date'],
            'sent_at'       => $row['sent_at'] === null ? null : (string) $row['sent_at'],
            'days_elapsed'  => (int) $row['days_elapsed'],
            'branch_id'     => (int) $row['branch_id'],
            'branch_name'   => $row['branch_name'] === null ? null : (string) $row['branch_name'],
            'location_id'   => $row['to_location_id'] === null ? null : (int) $row['to_location_id'],
            'location_name' => $row['location_name'] === null ? null : (string) $row['location_name'],
            'vendor_id'     => $row['vendor_id'] === null ? null : (int) $row['vendor_id'],
            'vendor_name'   => $row['vendor_name'] === null ? null : (string) $row['vendor_name'],
            'manager_name'  => $row['manager_name'] === null ? null : (string) $row['manager_name'],
            'lines'         => [],
        ];
    }
    $orders[$docId]['lines'][] = [
        'po_line_id'    => (int) $row['po_line_id'],
        'item_id'       => (int) $row['item_id'],
        'code'          => (string) $row['code'],
        'name'          => (string) $row['item_name'],
        'spec'          => $row['spec'] === null ? null : (string) $row['spec'],
        'unit'          => $row['unit'] === null ? null : (string) $row['unit'],
        'stock_managed' => $row['stock_managed'] === true,
        'lead_days'     => (int) $row['lead_days'],
        'ordered_qty'   => (string) $row['ordered_qty'],
        'received_qty'  => (string) $row['received_qty'],
        'remaining'     => (string) $row['remaining_qty'],
        // 단가는 **발주서 줄의 값**이다. 화면은 보여 주기만 하고, 입고 확정 때 서버가
        // 다시 읽는다 — 본문에 단가·금액을 실을 자리가 아예 없다 (SECURITY R-66).
        'unit_price'    => $row['unit_price'] === null ? null : (string) $row['unit_price'],
    ];
    $lineCount++;
}

Http::json([
    'orders'     => array_values($orders),
    'line_count' => $lineCount,
    'truncated'  => $lineCount >= TBOX_GR_LOAD_MAX,
]);
