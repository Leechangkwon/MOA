<?php
declare(strict_types=1);

/**
 * src/handlers/purchase_gr_list.php — GET /api/purchase/gr  (정책: purchase.read)
 *
 * ★ F-45 입고 전표 목록 (PRD F-34 · F-45, 시안 ⑥ GR 「전표」 탭).
 *
 *   ?q= ?status= ?from= ?to= ?branch= ?vendor= ?page=
 *
 * 20단계 `purchase_po_list.php` 와 **같은 모양**이다 — 응답 봉투도 같은
 * `{items, total, page, per_page}` 다(구매 모듈의 모든 목록이 쓰는 한 벌).
 * 지시서 F-45 는 이 배열을 `docs` 로 적었지만, 그러면 같은 것에 이름이 둘이 된다.
 *
 * 입고 전표의 상태는 `done`(확정) 아니면 `void`(취소) 둘뿐이라, 걸개를 비우면
 * **둘 다** 나온다 — 발주서 목록과 달리 취소를 감추지 않는다. 취소한 입고는
 * "없던 일" 이 아니라 "되돌린 일" 이고(원장에 반대 부호 행이 남는다), 그것을
 * 화면에서 찾을 수 있어야 대사(22단계)가 된다.
 *
 * ★ 금액이 없다. 입고 전표가 세는 것은 **수량**이다(`qty_total`) — 단가는 발주서 줄에서
 *   서버가 읽어 줄에 적히고, 화면이 다시 더하지 않는다 (SECURITY R-66).
 *
 * 지점 격리 (SECURITY R-65): `Authz::purchaseScopeWhere($user, 'd')` 를 목록·건수 두 SQL 에.
 * 조회는 감사 로그를 남기지 않는다.
 *
 * @var array<string,mixed> $user
 * @var list<string>        $params 경로 변수 없음
 */

require_once __DIR__ . '/../purchase/Stock.php';

/** 한 쪽에 보여 주는 전표 수 — 발주서 목록과 같다 */
const TBOX_GR_PER_PAGE = 20;

$q      = Http::queryText('q', 100);
$page   = Http::queryInt('page', 1, 1, 500);
$status = Http::queryChoice('status', ['', 'done', 'void'], '');
$branch = Http::queryInt('branch', 0, 1, 999999999);
$vendor = Http::queryInt('vendor', 0, 1, 999999999);
$from   = Http::queryText('from', 10);
$to     = Http::queryText('to', 10);

$scope = Authz::purchaseScopeWhere($user, 'd');

$where         = [$scope['sql']];
$bind          = $scope['params'];
$bind[':type'] = Stock::TYPE_GR;
$where[]       = 'd.doc_type = :type';

if ($status !== '') {
    $where[]         = 'd.status = :status';
    $bind[':status'] = $status;
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
    // 찾는 글자는 언제나 **바인딩 값**이다. `#` 를 탈출 문자로 삼아 `%`·`_` 를 글자로 만든다.
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
           d.memo, d.closed_month, CAST(d.updated_at AS text) AS updated_at,
           (SELECT count(*) FROM p_doc_lines x WHERE x.doc_id = d.id AND x.status <> 'void') AS line_count,
           (SELECT CAST(COALESCE(sum(x.qty), 0) AS numeric(14,3))::text FROM p_doc_lines x
             WHERE x.doc_id = d.id AND x.status <> 'void') AS qty_total
      FROM p_docs d
      LEFT JOIN p_branches  br  ON br.id  = d.branch_id
      LEFT JOIN p_vendors   ven ON ven.id = d.vendor_id
      LEFT JOIN p_locations loc ON loc.id = d.to_location_id
      LEFT JOIN users       mgr ON mgr.id = d.manager_id
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
$listBind[':lim'] = TBOX_GR_PER_PAGE;
$listBind[':off'] = ($page - 1) * TBOX_GR_PER_PAGE;

$rows = $db->run(
    $sqlList . ' ' . $filter . ' ORDER BY d.doc_date DESC, d.id DESC LIMIT :lim OFFSET :off',
    $listBind
)->fetchAll();

$items = [];
foreach ($rows as $row) {
    $voided = (string) $row['status'] === Doc::ST_VOID;
    $items[] = [
        'id'            => (int) $row['id'],
        'doc_no'        => (string) $row['doc_no'],
        'doc_type'      => Stock::TYPE_GR,
        'doc_date'      => (string) $row['doc_date'],
        'status'        => (string) $row['status'],
        'branch_id'     => (int) $row['branch_id'],
        'branch_name'   => $row['branch_name'] === null ? null : (string) $row['branch_name'],
        'vendor_id'     => $row['vendor_id'] === null ? null : (int) $row['vendor_id'],
        'vendor_name'   => $row['vendor_name'] === null ? null : (string) $row['vendor_name'],
        'location_name' => $row['location_name'] === null ? null : (string) $row['location_name'],
        'manager_name'  => $row['manager_name'] === null ? null : (string) $row['manager_name'],
        'memo'          => $row['memo'] === null ? null : (string) $row['memo'],
        'closed_month'  => $row['closed_month'] === null ? null : (string) $row['closed_month'],
        'line_count'    => (int) $row['line_count'],
        'qty_total'     => (string) $row['qty_total'],
        /* 취소 시각은 따로 저장하는 칸이 없다 — 취소가 `p_docs` 를 건드리는 마지막
           수정이므로 `updated_at` 이 그 시각이다. 취소가 아닌 전표에는 null 로 둔다
           (있지도 않은 「취소 시각」을 만들어 보여 주지 않는다). */
        'voided_at'     => $voided ? (string) $row['updated_at'] : null,
    ];
}

Http::json([
    'items'    => $items,
    'total'    => $total,
    'page'     => $page,
    'per_page' => TBOX_GR_PER_PAGE,
    // 화면 편의 값 — 단추를 보일지. 막는 것은 라우트 정책이다 (PRD S-01).
    'can_void' => Authz::purchaseAuthorize('purchase.stock.write', $user),
]);
