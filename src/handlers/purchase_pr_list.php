<?php
declare(strict_types=1);

/**
 * src/handlers/purchase_pr_list.php — GET /api/purchase/pr  (정책: purchase.read)
 *
 * ★ 20단계 발주요청 목록 (PRD F-30 「전표」 탭, 시안 ① Main · ⑧ Docs).
 *
 *   ?q=          전표 번호·거래처·품목 이름 검색 (LIKE, 와일드카드는 무력화)
 *   ?status=     unconfirmed | in_progress | partial | done | void (허용 목록 밖은 무시)
 *   ?from=&to=   전표 일자 범위 (YYYY-MM-DD, 형식이 틀리면 무시 — 400 이 아니다)
 *   ?mine=1      내가 담당자인 것만
 *   ?page=       1~500, 한 쪽 20건
 *
 * 지점 격리 (SECURITY R-65)
 *   `Authz::purchaseScopeWhere($user, 'd')` 를 목록·건수 **두 SQL 에 똑같이** 붙인다.
 *   requester 는 다른 지점 요청이 목록에도 `total` 에도 나타나지 않는다 (T-45).
 *   요청의 `?branch=` 는 이 조건과 **함께**(AND) 걸리므로, 남의 지점을 적으면 0건이다.
 *
 * 조회는 감사 로그를 남기지 않는다 (ARCH 5.1 마지막 줄).
 *
 * @var array<string,mixed> $user
 * @var list<string>        $params 경로 변수 없음
 */

require_once __DIR__ . '/../purchase/Doc.php';

const TBOX_PR_PER_PAGE = 20;

$q       = Http::queryText('q', 100);
$page    = Http::queryInt('page', 1, 1, 500);
$status  = Http::queryChoice('status', ['', 'unconfirmed', 'in_progress', 'partial', 'done', 'void'], '');
$mine    = Http::queryChoice('mine', ['0', '1'], '0') === '1';
$branch  = Http::queryInt('branch', 0, 1, 999999999);
$vendor  = Http::queryInt('vendor', 0, 1, 999999999);
$from    = Http::queryText('from', 10);
$to      = Http::queryText('to', 10);

$scope = Authz::purchaseScopeWhere($user, 'd');

$where = [$scope['sql']];
$bind  = $scope['params'];
$bind[':type'] = Doc::TYPE_PR;
$where[] = 'd.doc_type = :type';

if ($status !== '') {
    $where[]         = 'd.status = :status';
    $bind[':status'] = $status;
} else {
    // 기본은 취소를 빼고 본다 — 취소한 전표는 `?status=void` 로만 나온다.
    $where[] = "d.status <> 'void'";
}
if ($mine) {
    $where[]       = 'd.manager_id = CAST(:me AS bigint)';
    $bind[':me']   = (int) $user['id'];
}
if ($branch > 0) {
    $where[]           = 'd.branch_id = CAST(:branch AS bigint)';
    $bind[':branch']   = $branch;
}
if ($vendor > 0) {
    $where[]           = 'd.vendor_id = CAST(:vendor AS bigint)';
    $bind[':vendor']   = $vendor;
}
if (preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $from) === 1) {
    $where[]         = 'd.doc_date >= CAST(:from AS date)';
    $bind[':from']   = $from;
}
if (preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $to) === 1) {
    $where[]       = 'd.doc_date <= CAST(:to AS date)';
    $bind[':to']   = $to;
}
if ($q !== '') {
    $pattern         = '%' . str_replace(['#', '%', '_'], ['##', '#%', '#_'], $q) . '%';
    $where[]         = "(d.doc_no ILIKE :q ESCAPE '#'"
                     . " OR ven.name ILIKE :q ESCAPE '#'"
                     . " OR EXISTS (SELECT 1 FROM p_doc_lines ql JOIN p_items qi ON qi.id = ql.item_id"
                     . " WHERE ql.doc_id = d.id AND (qi.name ILIKE :q ESCAPE '#' OR qi.code ILIKE :q ESCAPE '#')))";
    $bind[':q']      = $pattern;
}

$filter = implode(' AND ', $where);

$sqlList = <<<'SQL'
    SELECT d.id, d.doc_no, CAST(d.doc_date AS text) AS doc_date, d.status,
           d.branch_id, br.name AS branch_name,
           d.vendor_id, ven.name AS vendor_name,
           d.to_location_id, loc.name AS location_name,
           d.manager_id, mgr.username AS manager_name,
           d.memo, d.split_from_id, sp.doc_no AS split_from_no,
           (SELECT count(*) FROM p_doc_lines x WHERE x.doc_id = d.id AND x.status <> 'void') AS line_count,
           (SELECT COALESCE(sum(x.amount), 0) FROM p_doc_lines x
             WHERE x.doc_id = d.id AND x.status <> 'void') AS amount
      FROM p_docs d
      LEFT JOIN p_branches  br  ON br.id  = d.branch_id
      LEFT JOIN p_vendors   ven ON ven.id = d.vendor_id
      LEFT JOIN p_locations loc ON loc.id = d.to_location_id
      LEFT JOIN users       mgr ON mgr.id = d.manager_id
      LEFT JOIN p_docs      sp  ON sp.id  = d.split_from_id
     WHERE
    SQL;

$sqlCount = <<<'SQL'
    SELECT count(*) AS total
      FROM p_docs d
      LEFT JOIN p_vendors ven ON ven.id = d.vendor_id
     WHERE
    SQL;

$orderSql = ' ORDER BY d.doc_date DESC, d.id DESC';

$db       = Db::instance();
$countRow = $db->run($sqlCount . ' ' . $filter, $bind)->fetch();
$total    = is_array($countRow) ? (int) $countRow['total'] : 0;

$listBind         = $bind;
$listBind[':lim'] = TBOX_PR_PER_PAGE;
$listBind[':off'] = ($page - 1) * TBOX_PR_PER_PAGE;

$rows = $db->run($sqlList . ' ' . $filter . $orderSql . ' LIMIT :lim OFFSET :off', $listBind)->fetchAll();

$items = [];
foreach ($rows as $row) {
    $items[] = [
        'id'            => (int) $row['id'],
        'doc_no'        => (string) $row['doc_no'],
        'doc_type'      => Doc::TYPE_PR,
        'doc_date'      => (string) $row['doc_date'],
        'status'        => (string) $row['status'],
        'branch_id'     => (int) $row['branch_id'],
        'branch_name'   => $row['branch_name'] === null ? null : (string) $row['branch_name'],
        'vendor_id'     => $row['vendor_id'] === null ? null : (int) $row['vendor_id'],
        'vendor_name'   => $row['vendor_name'] === null ? null : (string) $row['vendor_name'],
        'location_name' => $row['location_name'] === null ? null : (string) $row['location_name'],
        'manager_name'  => $row['manager_name'] === null ? null : (string) $row['manager_name'],
        'memo'          => $row['memo'] === null ? null : (string) $row['memo'],
        'split_from_no' => $row['split_from_no'] === null ? null : (string) $row['split_from_no'],
        'line_count'    => (int) $row['line_count'],
        'amount'        => (string) $row['amount'],
    ];
}

Http::json([
    'items'    => $items,
    'total'    => $total,
    'page'     => $page,
    'per_page' => TBOX_PR_PER_PAGE,
    // 화면 편의 값 — 「새 요청」 단추를 보일지. 막는 것은 라우트 정책이다 (PRD S-01).
    'can_write' => Authz::purchaseAuthorize('purchase.request.write', $user),
]);
