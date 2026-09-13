<?php
declare(strict_types=1);

/**
 * src/handlers/purchase_po_list.php — GET /api/purchase/po  (정책: purchase.read)
 *
 * ★ 20단계 발주서 목록 (PRD F-32, 시안 ⑧ Docs).
 *
 *   ?q= ?status= ?from= ?to= ?branch= ?vendor= ?sent=0|1 ?page=
 *
 * 지점 격리 (SECURITY R-65): `Authz::purchaseScopeWhere($user, 'd')` 를 목록·건수 두 SQL 에.
 * 조회는 감사 로그를 남기지 않는다.
 *
 * @var array<string,mixed> $user
 * @var list<string>        $params 경로 변수 없음
 */

require_once __DIR__ . '/../purchase/Doc.php';

const TBOX_PO_PER_PAGE = 20;

$q      = Http::queryText('q', 100);
$page   = Http::queryInt('page', 1, 1, 500);
$status = Http::queryChoice('status', ['', 'unconfirmed', 'in_progress', 'partial', 'done', 'void'], '');
$sent   = Http::queryChoice('sent', ['', '0', '1'], '');
$branch = Http::queryInt('branch', 0, 1, 999999999);
$vendor = Http::queryInt('vendor', 0, 1, 999999999);
$from   = Http::queryText('from', 10);
$to     = Http::queryText('to', 10);

$scope = Authz::purchaseScopeWhere($user, 'd');

$where         = [$scope['sql']];
$bind          = $scope['params'];
$bind[':type'] = Doc::TYPE_PO;
$where[]       = 'd.doc_type = :type';

if ($status !== '') {
    $where[]         = 'd.status = :status';
    $bind[':status'] = $status;
} else {
    $where[] = "d.status <> 'void'";
}
if ($sent === '1') {
    $where[] = 'd.sent_at IS NOT NULL';
} elseif ($sent === '0') {
    $where[] = 'd.sent_at IS NULL';
}
if ($branch > 0) {
    $where[]         = 'd.branch_id = CAST(:branch AS bigint)';
    $bind[':branch'] = $branch;
}
if ($vendor > 0) {
    $where[]         = 'd.vendor_id = CAST(:vendor AS bigint)';
    $bind[':vendor'] = $vendor;
}
if (preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $from) === 1) {
    $where[]       = 'd.doc_date >= CAST(:from AS date)';
    $bind[':from'] = $from;
}
if (preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $to) === 1) {
    $where[]     = 'd.doc_date <= CAST(:to AS date)';
    $bind[':to'] = $to;
}
if ($q !== '') {
    $bind[':q'] = '%' . str_replace(['#', '%', '_'], ['##', '#%', '#_'], $q) . '%';
    $where[]    = "(d.doc_no ILIKE :q ESCAPE '#' OR ven.name ILIKE :q ESCAPE '#'"
                . " OR EXISTS (SELECT 1 FROM p_doc_lines ql JOIN p_items qi ON qi.id = ql.item_id"
                . " WHERE ql.doc_id = d.id AND (qi.name ILIKE :q ESCAPE '#' OR qi.code ILIKE :q ESCAPE '#')))";
}

$filter = implode(' AND ', $where);

$sqlList = <<<'SQL'
    SELECT d.id, d.doc_no, CAST(d.doc_date AS text) AS doc_date, d.status,
           d.branch_id, br.name AS branch_name,
           d.vendor_id, ven.name AS vendor_name,
           d.to_location_id, loc.name AS location_name,
           d.manager_id, mgr.username AS manager_name,
           d.memo, d.sent_at, sb.username AS sent_by_name,
           (SELECT count(*) FROM p_doc_lines x WHERE x.doc_id = d.id AND x.status <> 'void') AS line_count,
           (SELECT COALESCE(sum(x.amount), 0) FROM p_doc_lines x
             WHERE x.doc_id = d.id AND x.status <> 'void') AS amount
      FROM p_docs d
      LEFT JOIN p_branches  br  ON br.id  = d.branch_id
      LEFT JOIN p_vendors   ven ON ven.id = d.vendor_id
      LEFT JOIN p_locations loc ON loc.id = d.to_location_id
      LEFT JOIN users       mgr ON mgr.id = d.manager_id
      LEFT JOIN users       sb  ON sb.id  = d.sent_by
     WHERE
    SQL;

$sqlCount = <<<'SQL'
    SELECT count(*) AS total
      FROM p_docs d
      LEFT JOIN p_vendors ven ON ven.id = d.vendor_id
     WHERE
    SQL;

$db       = Db::instance();
$countRow = $db->run($sqlCount . ' ' . $filter, $bind)->fetch();
$total    = is_array($countRow) ? (int) $countRow['total'] : 0;

$listBind         = $bind;
$listBind[':lim'] = TBOX_PO_PER_PAGE;
$listBind[':off'] = ($page - 1) * TBOX_PO_PER_PAGE;

$rows = $db->run(
    $sqlList . ' ' . $filter . ' ORDER BY d.doc_date DESC, d.id DESC LIMIT :lim OFFSET :off',
    $listBind
)->fetchAll();

$items = [];
foreach ($rows as $row) {
    $items[] = [
        'id'            => (int) $row['id'],
        'doc_no'        => (string) $row['doc_no'],
        'doc_type'      => Doc::TYPE_PO,
        'doc_date'      => (string) $row['doc_date'],
        'status'        => (string) $row['status'],
        'branch_id'     => (int) $row['branch_id'],
        'branch_name'   => $row['branch_name'] === null ? null : (string) $row['branch_name'],
        'vendor_id'     => $row['vendor_id'] === null ? null : (int) $row['vendor_id'],
        'vendor_name'   => $row['vendor_name'] === null ? null : (string) $row['vendor_name'],
        'location_name' => $row['location_name'] === null ? null : (string) $row['location_name'],
        'manager_name'  => $row['manager_name'] === null ? null : (string) $row['manager_name'],
        'memo'          => $row['memo'] === null ? null : (string) $row['memo'],
        'sent_at'       => $row['sent_at'] === null ? null : (string) $row['sent_at'],
        'sent_by_name'  => $row['sent_by_name'] === null ? null : (string) $row['sent_by_name'],
        'line_count'    => (int) $row['line_count'],
        'amount'        => (string) $row['amount'],
    ];
}

Http::json([
    'items'     => $items,
    'total'     => $total,
    'page'      => $page,
    'per_page'  => TBOX_PO_PER_PAGE,
    // 화면 편의 값 — 단추를 보일지. 막는 것은 라우트 정책이다 (PRD S-01).
    'can_write' => Authz::purchaseAuthorize('purchase.buy.write', $user),
    'can_send'  => Authz::purchaseAuthorize('purchase.send', $user),
]);
