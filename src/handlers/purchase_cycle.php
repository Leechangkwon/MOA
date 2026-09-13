<?php
declare(strict_types=1);

/**
 * src/handlers/purchase_cycle.php — GET /api/purchase/cycle  (정책: purchase.read)
 *
 * ★ 20단계 **사이클 추적** (PRD F-32 R4, 시안 ⑨ Cycle, ARCH 5.18 「연결」).
 *
 *   ?pr=<요청 전표 id>   그 요청의 줄만
 *   ?po=<발주서 id>      그 발주서가 가져간 요청 줄만
 *   ?item=<품목 id>      그 품목의 요청 줄만
 *   ?from=&to=          요청 일자 범위
 *
 * **요청 줄 하나가 한 행**이다: 누가·언제 요청했고, 어느 발주서로 얼마가 갔고, 미발주 잔량이
 * 얼마인가. 입고(GR) 칸은 21단계 것이라 **자리만 있고 값은 null** 이다 — 0 을 지어내지 않는다.
 *
 * 연결은 `p_line_chain` 뷰(재귀 CTE)로 따라간다. 20단계에는 층이 둘(PR→PO)뿐이지만,
 * 21단계에 입고 줄이 붙으면 **같은 뷰가 세 층을 그대로** 돌려준다.
 *
 * 지점 격리 (SECURITY R-65)
 *   요청 줄은 `Authz::purchaseScopeWhere($user, 'd')` 로 좁힌다. 자식(발주서) 줄은
 *   **부모가 보이는 줄의 자식만** 읽는다 — 부모가 안 보이면 자식도 목록에 없다.
 *
 * 조회는 감사 로그를 남기지 않는다.
 *
 * @var array<string,mixed> $user
 * @var list<string>        $params 경로 변수 없음
 */

require_once __DIR__ . '/../purchase/Doc.php';

const TBOX_CYCLE_MAX_ROWS = 200;

$prId   = Http::queryInt('pr', 0, 1, 999999999);
$poId   = Http::queryInt('po', 0, 1, 999999999);
$itemId = Http::queryInt('item', 0, 1, 999999999);
$from   = Http::queryText('from', 10);
$to     = Http::queryText('to', 10);

$scope = Authz::purchaseScopeWhere($user, 'd');

$where   = [$scope['sql']];
$bind    = $scope['params'];
$where[] = "d.doc_type = 'pr'";
$where[] = "d.status <> 'void'";
$where[] = "l.status <> 'void'";

if ($prId > 0) {
    $where[]     = 'd.id = CAST(:pr AS bigint)';
    $bind[':pr'] = $prId;
}
if ($itemId > 0) {
    $where[]       = 'l.item_id = CAST(:item AS bigint)';
    $bind[':item'] = $itemId;
}
if ($poId > 0) {
    $where[]     = 'EXISTS (SELECT 1 FROM p_doc_lines pl2'
                 . ' WHERE pl2.src_line_id = l.id AND pl2.doc_id = CAST(:po AS bigint)'
                 . " AND pl2.status <> 'void')";
    $bind[':po'] = $poId;
}
if (preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $from) === 1) {
    $where[]       = 'd.doc_date >= CAST(:from AS date)';
    $bind[':from'] = $from;
}
if (preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $to) === 1) {
    $where[]     = 'd.doc_date <= CAST(:to AS date)';
    $bind[':to'] = $to;
}

$sqlHead = <<<'SQL'
    SELECT l.id AS line_id, CAST(l.qty AS text) AS qty, l.locked_at, l.status,
           d.id AS doc_id, d.doc_no, CAST(d.doc_date AS text) AS doc_date, d.status AS doc_status,
           d.branch_id, br.name AS branch_name,
           loc.name AS location_name,
           mgr.username AS manager_name, d.created_at,
           i.id AS item_id, i.code, i.name AS item_name, i.spec, i.unit,
    SQL;

$sqlTail = <<<'SQL'
    AS taken
      FROM p_doc_lines l
      JOIN p_docs  d ON d.id = l.doc_id
      JOIN p_items i ON i.id = l.item_id
      LEFT JOIN p_branches  br  ON br.id = d.branch_id
      LEFT JOIN p_locations loc ON loc.id = d.to_location_id
      LEFT JOIN users       mgr ON mgr.id = d.manager_id
     WHERE
    SQL;

$bind[':lim'] = TBOX_CYCLE_MAX_ROWS;

$db   = Db::instance();
$rows = $db->run(
    $sqlHead . ' ' . Doc::SQL_TAKEN . ' ' . $sqlTail . ' ' . implode(' AND ', $where)
        . ' ORDER BY d.doc_date DESC, d.id DESC, l.line_no ASC LIMIT :lim',
    $bind
)->fetchAll();

$byLine = [];
$out    = [];
foreach ($rows as $row) {
    $want  = Doc::milli((string) $row['qty']);
    $taken = Doc::milli((string) $row['taken']);
    $lineId = (int) $row['line_id'];
    $out[$lineId] = [
        'line_id'       => $lineId,
        'pr_doc_id'     => (int) $row['doc_id'],
        'pr_doc_no'     => (string) $row['doc_no'],
        'pr_date'       => (string) $row['doc_date'],
        'pr_status'     => (string) $row['doc_status'],
        'requested_at'  => (string) $row['created_at'],
        'requester'     => $row['manager_name'] === null ? null : (string) $row['manager_name'],
        'branch_id'     => (int) $row['branch_id'],
        'branch_name'   => $row['branch_name'] === null ? null : (string) $row['branch_name'],
        'location_name' => $row['location_name'] === null ? null : (string) $row['location_name'],
        'item_id'       => (int) $row['item_id'],
        'code'          => (string) $row['code'],
        'name'          => (string) $row['item_name'],
        'spec'          => $row['spec'] === null ? null : (string) $row['spec'],
        'unit'          => $row['unit'] === null ? null : (string) $row['unit'],
        'qty'           => (string) $row['qty'],
        'ordered_qty'   => Doc::qtyText($taken),
        'not_ordered'   => Doc::qtyText(max(0, $want - $taken)),
        'line_status'   => (string) $row['status'],
        'locked_at'     => $row['locked_at'] === null ? null : (string) $row['locked_at'],
        'orders'        => [],
        // 21단계 입고가 붙을 자리. 값을 지어내지 않는다 — 아직 원장이 없다.
        'received_qty'  => null,
        'not_received'  => null,
    ];
    $byLine[] = $lineId;
}

if ($byLine !== []) {
    $names = [];
    $cBind = [];
    foreach ($byLine as $i => $id) {
        $n            = ':r' . (string) $i;
        $names[]      = $n;
        $cBind[$n]    = $id;
    }
    $inList = '(' . implode(', ', $names) . ')';

    // 재귀 CTE 뷰 — 요청 줄에서 시작해 src_line_id 를 따라 내려간 **모든 자손**.
    $sqlChildren = <<<'SQL'
        SELECT ch.root_id, ch.depth,
               cl.id AS line_id, CAST(cl.qty AS text) AS qty, cl.status,
               cd.id AS doc_id, cd.doc_no, cd.doc_type, CAST(cd.doc_date AS text) AS doc_date,
               cd.status AS doc_status, cd.sent_at,
               mg.username AS manager_name
          FROM p_line_chain ch
          JOIN p_doc_lines cl ON cl.id = ch.line_id
          JOIN p_docs      cd ON cd.id = cl.doc_id
          LEFT JOIN users  mg ON mg.id = cd.manager_id
         WHERE ch.depth > 0 AND cl.status <> 'void' AND cd.status <> 'void'
           AND ch.root_id IN
        SQL;

    $children = $db->run(
        $sqlChildren . ' ' . $inList . ' ORDER BY ch.depth ASC, cd.doc_date ASC, cl.id ASC',
        $cBind
    )->fetchAll();

    foreach ($children as $child) {
        $root = (int) $child['root_id'];
        if (!isset($out[$root])) {
            continue;
        }
        $out[$root]['orders'][] = [
            'depth'        => (int) $child['depth'],
            'doc_id'       => (int) $child['doc_id'],
            'doc_no'       => (string) $child['doc_no'],
            'doc_type'     => (string) $child['doc_type'],
            'doc_date'     => (string) $child['doc_date'],
            'doc_status'   => (string) $child['doc_status'],
            'sent_at'      => $child['sent_at'] === null ? null : (string) $child['sent_at'],
            'manager_name' => $child['manager_name'] === null ? null : (string) $child['manager_name'],
            'qty'          => (string) $child['qty'],
        ];
    }
}

Http::json([
    'rows'      => array_values($out),
    'total'     => count($out),
    'truncated' => count($out) >= TBOX_CYCLE_MAX_ROWS,
]);
