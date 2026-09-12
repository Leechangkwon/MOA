<?php
declare(strict_types=1);

/**
 * src/handlers/purchase_masters_list.php
 *   GET /api/purchase/{brands|branches|locations|vendors|categories|par-levels}  (정책: purchase.read)
 *
 * ★ 19단계 기초정보 목록 (PRD F-28, ARCH 5.18, SECURITY R-65).
 *
 * 지점 격리는 **SQL WHERE 에서** 한다 (PRD S-02 와 같은 원칙).
 *   `Authz::purchaseScopeWhere($user, 별칭)` 이 돌려준 조각을 목록·건수 두 SQL 에 똑같이 붙인다.
 *   권한 없는 행은 응답에서 지우는 것이 아니라 **DB 가 애초에 돌려주지 않는다** —
 *   응답 뒤 필터는 권한 처리로 인정하지 않는다(총 건수·페이지 수만으로도 규모가 샌다).
 *   요청의 `?branch=` 는 **읽지 않는다**: 지점은 언제나 DB 의 `users.branch_id` 이고,
 *   requester 가 URL 에 다른 지점을 적어도 조건은 바뀌지 않는다 (PRD S-01, T-42).
 *
 * 종류별 지점 관계
 *   locations   `l.branch_id`            — 지점 격리 대상
 *   par-levels  `l.branch_id` (장소 조인) — 인가량은 장소에 달려 있으므로 장소로 좁힌다
 *   branches    `b.branch_id`            — 지점 자신. 아래 CTE 가 `id AS branch_id` 를 내준다
 *   brands · vendors · categories        — **지점 무관 마스터**다. 전 지점이 같은 브랜드·거래처·
 *                                          분류를 쓰므로 좁힐 기준이 없다. 그래도 이 파일은
 *                                          purchaseScopeWhere 를 부르므로 린트 규칙 (a)를
 *                                          표식 없이 통과한다(한 파일이 여섯 종류를 다룬다).
 *
 * 목록 조회는 감사 로그를 남기지 않는다 (ARCH 5.1 마지막 줄, PRD S-07 — 조회는 사건이 아니다).
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자 (요청 파라미터가 아니다 — PRD S-01)
 * @var list<string>        $params 경로 변수 없음
 */

require_once __DIR__ . '/../purchase/Master.php';

/**
 * 라우트 패턴 → 종류. 값은 **코드에 적힌 고정 문자열**이고 요청 문자열이 아니다.
 * `TBOX_ROUTE` 는 라우터가 라우트 표에서 만든 값이라(실제 경로가 아니다) 사용자 입력이
 * 이 표의 열쇠가 되는 경로가 없다 — 표에 없으면 404 로 끝난다.
 */
const TBOX_PURCHASE_LIST_KIND = [
    'GET /api/purchase/brands'     => 'brands',
    'GET /api/purchase/branches'   => 'branches',
    'GET /api/purchase/locations'  => 'locations',
    'GET /api/purchase/vendors'    => 'vendors',
    'GET /api/purchase/categories' => 'categories',
    'GET /api/purchase/par-levels' => 'par-levels',
];

$kind = TBOX_PURCHASE_LIST_KIND[TBOX_ROUTE] ?? null;
if ($kind === null) {
    Http::error(404, Http::MSG_NOT_FOUND);
}

// ─── 조회 파라미터 (형식이 틀리면 400 이 아니라 기본값 — ARCH 5.1 [2]) ───
$q       = Http::queryText('q', 100);
$page    = Http::queryInt('page', 1, 1, 500);
$perPage = 50;
// 중단된 행까지 볼지. 기본은 사용 중인 것만이다 (지시서 보충 4).
$withInactive = Http::queryChoice('include_inactive', ['0', '1'], '0') === '1';

// ─── 지점 격리 조건 (SECURITY R-65) ───
// 별칭은 종류마다 다르지만 **함수는 하나**다. 조각에는 값이 없고 자리표시자 이름만 있다.
//
// ★ 브랜드·거래처·분류는 `branch_id` 컬럼 자체가 없는 **지점 무관 마스터**다. 그 셋에
//   `l.branch_id = …` 를 붙이면 SQL 이 성립하지 않는다 — 좁힐 기준이 없다는 뜻이다.
//   그래서 그 셋만 언제나 참인 조각을 쓴다. 조건을 "없애는" 것이 아니라 "그 표에는 지점이라는
//   축이 없다" 를 코드로 적는 것이며, 지점이 붙는 세 종류는 아래에서 반드시 함수를 지난다.
//   lint:no-branch-scope 브랜드·거래처·분류(지점 무관 마스터) — 세 종류만 해당
$branchScoped = in_array($kind, ['branches', 'locations', 'par-levels'], true);
$scopeAlias   = $kind === 'branches' ? 'b' : 'l';
$scope        = $branchScoped
    ? Authz::purchaseScopeWhere($user, $scopeAlias)
    : ['sql' => '1=1', 'params' => []];

/**
 * 종류별 고정 SQL. `WHERE` 에서 끝나고 바로 뒤에 조건 조각이 붙는다(files_list.php 와 같은 모양).
 *
 * `branches` 만 CTE 를 쓴다 — p_branches 에는 `branch_id` 컬럼이 없고 자기 `id` 가 곧 지점이다.
 * 조건 함수(purchaseScopeWhere)가 언제나 `<별칭>.branch_id` 한 모양을 내도록, 조회 쪽에서
 * `id AS branch_id` 를 함께 내주는 것이 표별로 조건 모양을 갈라 두는 것보다 안전하다.
 */
$sqlList = [
    'brands' => <<<'SQL'
        SELECT l.id, l.name, l.biz_no, l.ceo_name, l.address, l.tel, l.logo_file_id, l.is_active
          FROM p_brands l
         WHERE
        SQL,
    'branches' => <<<'SQL'
        WITH b AS (SELECT p.*, p.id AS branch_id FROM p_branches p)
        SELECT b.id, b.brand_id, b.name, b.warehouse_location_id, b.central_location_id,
               b.is_active, br.name AS brand_name
          FROM b
          LEFT JOIN p_brands br ON br.id = b.brand_id
         WHERE
        SQL,
    'locations' => <<<'SQL'
        SELECT l.id, l.branch_id, l.kind, l.name, l.code, l.legacy_name, l.is_active,
               bn.name AS branch_name
          FROM p_locations l
          LEFT JOIN p_branches bn ON bn.id = l.branch_id
         WHERE
        SQL,
    'vendors' => <<<'SQL'
        SELECT l.id, l.code, l.name, l.biz_no, l.is_active
          FROM p_vendors l
         WHERE
        SQL,
    'categories' => <<<'SQL'
        SELECT l.id, l.parent_id, l.name, l.level, l.is_active,
               pc.name AS parent_name
          FROM p_categories l
          LEFT JOIN p_categories pc ON pc.id = l.parent_id
         WHERE
        SQL,
    'par-levels' => <<<'SQL'
        SELECT pl.id, pl.location_id, pl.item_id, pl.qty,
               CAST(pl.effective_from AS text) AS effective_from, pl.is_active,
               l.name AS location_name, l.branch_id,
               it.code AS item_code, it.name AS item_name
          FROM p_par_levels pl
          JOIN p_locations l ON l.id = pl.location_id
          JOIN p_items     it ON it.id = pl.item_id
         WHERE
        SQL,
];

$sqlCount = [
    'brands'     => "SELECT count(*) AS total FROM p_brands l WHERE",
    'branches'   => "WITH b AS (SELECT p.*, p.id AS branch_id FROM p_branches p) SELECT count(*) AS total FROM b WHERE",
    'locations'  => "SELECT count(*) AS total FROM p_locations l WHERE",
    'vendors'    => "SELECT count(*) AS total FROM p_vendors l WHERE",
    'categories' => "SELECT count(*) AS total FROM p_categories l WHERE",
    'par-levels' => "SELECT count(*) AS total FROM p_par_levels pl JOIN p_locations l ON l.id = pl.location_id WHERE",
];

// 정렬은 바인딩할 수 없다 → 허용 목록의 키로만 고정 조각을 고른다 (PRD S-04, ARCH 5.1 [3]).
$orderSql = [
    'brands'     => ' ORDER BY l.name ASC, l.id ASC',
    'branches'   => ' ORDER BY b.name ASC, b.id ASC',
    'locations'  => ' ORDER BY l.branch_id ASC, l.name ASC, l.id ASC',
    'vendors'    => ' ORDER BY l.name ASC, l.id ASC',
    'categories' => ' ORDER BY l.level ASC, l.name ASC, l.id ASC',
    'par-levels' => ' ORDER BY l.name ASC, it.name ASC, pl.id ASC',
];

// 중단 행 제외 조각. par-levels 는 표가 pl 별칭이다.
$aliveSql = [
    'brands'     => ' AND l.is_active',
    'branches'   => ' AND b.is_active',
    'locations'  => ' AND l.is_active',
    'vendors'    => ' AND l.is_active',
    'categories' => ' AND l.is_active',
    'par-levels' => ' AND pl.is_active',
];

// 이름 검색 조각. ESCAPE 문자는 files_list.php 와 같은 이유로 '#' 을 쓴다.
$searchSql = [
    'brands'     => " AND l.name ILIKE :q ESCAPE '#'",
    'branches'   => " AND b.name ILIKE :q ESCAPE '#'",
    'locations'  => " AND l.name ILIKE :q ESCAPE '#'",
    'vendors'    => " AND l.name ILIKE :q ESCAPE '#'",
    'categories' => " AND l.name ILIKE :q ESCAPE '#'",
    'par-levels' => " AND it.name ILIKE :q ESCAPE '#'",
];

$filter = $withInactive ? '' : $aliveSql[$kind];
$bind   = $scope['params'];

if ($q !== '') {
    $filter      = $filter . $searchSql[$kind];
    $bind[':q']  = '%' . str_replace(['#', '%', '_'], ['##', '#%', '#_'], $q) . '%';
}

$db = Db::instance();

// 총 건수는 목록과 **같은 조건**으로 센다 — 내가 볼 수 있는 개수만 나간다 (PRD S-02).
// par-levels 의 건수 SQL 에는 p_items 조인이 없으므로 이름 검색이 붙는 경우만 조인을 더한다.
$countSql = $sqlCount[$kind];
if ($kind === 'par-levels' && $q !== '') {
    $countSql = "SELECT count(*) AS total FROM p_par_levels pl"
        . " JOIN p_locations l ON l.id = pl.location_id"
        . " JOIN p_items it ON it.id = pl.item_id WHERE";
}

$countRow = $db->run($countSql . ' ' . $scope['sql'] . $filter, $bind)->fetch();
$total    = is_array($countRow) ? (int) $countRow['total'] : 0;

$listBind         = $bind;
$listBind[':lim'] = $perPage;
$listBind[':off'] = ($page - 1) * $perPage;

$rows = $db->run(
    $sqlList[$kind] . ' ' . $scope['sql'] . $filter . $orderSql[$kind] . ' LIMIT :lim OFFSET :off',
    $listBind
)->fetchAll();

// 값 모양 다듬기 — DB 는 정수를 문자열로 돌려줄 수 있다. 화면이 숫자로 다루게 맞춰 준다.
$items = [];
foreach ($rows as $row) {
    $item = [];
    foreach ($row as $column => $value) {
        if ($value === null) {
            $item[$column] = null;
            continue;
        }
        if (in_array($column, ['id', 'brand_id', 'branch_id', 'parent_id', 'level', 'qty',
                               'location_id', 'item_id', 'warehouse_location_id',
                               'central_location_id', 'logo_file_id'], true)) {
            $item[$column] = (int) $value;
            continue;
        }
        if ($column === 'is_active') {
            $item[$column] = $value === true;
            continue;
        }
        $item[$column] = (string) $value;
    }
    $items[] = $item;
}

Http::json([
    'kind'     => $kind,
    'items'    => $items,
    'total'    => $total,
    'page'     => $page,
    'per_page' => $perPage,
    // 화면 편의 값 — 「새로 만들기」 단추를 보일지. 판정은 서버가 다시 한다 (PRD S-01).
    'can_edit' => Authz::canEditPurchaseMaster($user),
]);
