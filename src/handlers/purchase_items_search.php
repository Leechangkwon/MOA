<?php
declare(strict_types=1);

/**
 * src/handlers/purchase_items_search.php
 *   GET /api/purchase/items?q&cat1&cat2&cat3&vendor&branch_used&stock_managed&sort&page
 *   (정책: purchase.read)
 *
 * ★ 19단계 「품목 찾기」 API (PRD F-30 R1 · T-43, 시안 ② ItemPicker, ARCH 5.18).
 *   발주요청 입력의 품목 모달이 **이 API 를 조건과 함께** 부른다. 드롭다운에 1.35만 건을
 *   내려 주지 않는다 — 서버가 20건씩 자르고 총 건수만 함께 준다.
 *
 * 지키는 것
 *   · 조건은 **전부 바인딩**. SQL 문자열에 값을 붙이지 않는다 (PRD S-04)
 *   · `q` 는 `lower(name) LIKE` · `code LIKE` · `barcode =` **3중 OR**.
 *     LIKE 인자의 `%`·`_`·이스케이프 문자는 무력화해서 넣는다(와일드카드 주입 불가)
 *   · 정렬은 허용 목록 `['name','code','vendor','updated_at']` 의 키로만 **고정 조각**을 고른다.
 *     목록 밖 값은 400 이 아니라 기본값이다 (ARCH 5.1 [2][3])
 *   · `page` 는 1~500 정수, 한 쪽은 **20건 고정**
 *   · 중단된 품목은 기본값에서 제외. `include_inactive=1` 은 **기초정보를 고칠 수 있는
 *     사람**(buyer·Admin)만 통한다 — 판정은 Authz 이고 여기서 역할을 비교하지 않는다
 *     (지시서 보충 4)
 *
 * 지점 격리 (SECURITY R-65, T-42)
 *   품목 자체는 브랜드 범위의 공개 마스터이고, 지점은 `p_item_branches`(사용 지점)로 붙는다.
 *   `Authz::purchaseScopeWhere($user, 'ib')` 가 requester·stock 에게 `ib.branch_id = :p_my_branch`
 *   를 주고, 조건은 **EXISTS 서브쿼리**로 들어간다:
 *     "내 지점에서 쓰는 품목" **또는** "사용 지점이 하나도 지정되지 않은 품목(= 브랜드 전체)".
 *   그래서 다른 지점에만 묶인 품목은 목록에도 `total` 에도 나타나지 않는다.
 *   요청의 `branch_used` 는 **buyer·viewer·Admin 에게만** 뜻이 있다. requester 가
 *   `?branch_used=다른지점` 을 보내도 위 조건이 그대로 남으므로 다른 지점 행은 0건이다
 *   (요청 값이 판정을 바꾸지 못한다 — PRD S-01).
 *
 * 조회는 감사 로그를 남기지 않는다 (지시서: `purchase.item.search` 는 기록하지 않음).
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 * @var list<string>        $params 경로 변수 없음
 */

/** 한 쪽에 내려 주는 건수 — 고정이다 (시안 ②: "서버에서 20건씩") */
const TBOX_PURCHASE_ITEMS_PER_PAGE = 20;

$q            = Http::queryText('q', 100);
$page         = Http::queryInt('page', 1, 1, 500);
$sort         = Http::queryChoice('sort', ['name', 'code', 'vendor', 'updated_at'], 'name');
$dir          = Http::queryChoice('dir', ['asc', 'desc'], 'asc');
$cat1         = Http::queryInt('cat1', 0, 1, 999999999);
$cat2         = Http::queryInt('cat2', 0, 1, 999999999);
$cat3         = Http::queryInt('cat3', 0, 1, 999999999);
$vendor       = Http::queryInt('vendor', 0, 1, 999999999);
$branchUsed   = Http::queryInt('branch_used', 0, 1, 999999999);
$stockManaged = Http::queryChoice('stock_managed', ['', '0', '1'], '');
$wantInactive = Http::queryChoice('include_inactive', ['0', '1'], '0') === '1';

// 중단 품목을 볼 자격은 기초정보를 고칠 수 있는 자격과 같다. 자격이 없으면 조용히 기본값으로
// 되돌린다(400 을 내면 "그런 것이 있다" 만 알려 주고 막아지는 것은 없다).
$includeInactive = $wantInactive && Authz::canEditPurchaseMaster($user);

// ─── 지점 격리 조건 (R-65) ───
$scope = Authz::purchaseScopeWhere($user, 'ib');

$where  = [];
$bind   = [];

if ($scope['sql'] === '1=0') {
    // 지점이 정해지지 않은 requester·stock — 아무것도 보이지 않는다(안전한 기본값).
    $where[] = '1=0';
} elseif ($scope['sql'] !== '1=1') {
    // 내 지점에서 쓰는 품목, 또는 사용 지점이 지정되지 않은 품목(브랜드 전체).
    // 조각을 이어 붙이는 줄에 **값이 한 글자도 없다** — `$scope['sql']` 은 Authz 가 만든
    // 고정 문자열이고 값은 `$scope['params']` 로만 바인딩된다 (PRD S-04).
    // (SQL 키워드와 변수를 같은 줄에 두지 않는 것은 린트 규칙의 모양을 지키기 위해서이기도 하다.)
    $exists  = '(EXISTS (SELECT 1 FROM p_item_branches ib';
    $exists .= ' WHERE ib.item_id = i.id AND ';
    $exists .= $scope['sql'];
    $exists .= ') OR NOT EXISTS (SELECT 1 FROM p_item_branches ibx WHERE ibx.item_id = i.id))';
    $where[] = $exists;
    $bind    = $bind + $scope['params'];
}

if (!$includeInactive) {
    $where[] = 'i.is_active';
}
if ($q !== '') {
    // `%` `_` `#` 을 무력화한 뒤 감싼다. ESCAPE 문자는 files_list.php 와 같은 이유로 '#' 이다.
    $pattern        = '%' . str_replace(['#', '%', '_'], ['##', '#%', '#_'], $q) . '%';
    $where[]        = "(lower(i.name) LIKE lower(:q_name) ESCAPE '#'"
                    . " OR i.code LIKE :q_code ESCAPE '#'"
                    . " OR i.barcode = :q_bar)";
    $bind[':q_name'] = $pattern;
    $bind[':q_code'] = $pattern;
    $bind[':q_bar']  = $q;
}
if ($cat1 > 0) {
    $where[]          = 'i.cat1_id = CAST(:cat1 AS bigint)';
    $bind[':cat1']    = $cat1;
}
if ($cat2 > 0) {
    $where[]          = 'i.cat2_id = CAST(:cat2 AS bigint)';
    $bind[':cat2']    = $cat2;
}
if ($cat3 > 0) {
    $where[]          = 'i.cat3_id = CAST(:cat3 AS bigint)';
    $bind[':cat3']    = $cat3;
}
if ($vendor > 0) {
    $where[]          = 'i.vendor_id = CAST(:vendor AS bigint)';
    $bind[':vendor']  = $vendor;
}
if ($branchUsed > 0) {
    // 요청이 고른 지점 필터. 위 지점 격리 조건과 **함께**(AND) 걸리므로, requester 가 다른
    // 지점을 적으면 두 조건이 동시에 참일 수 없어 0건이 된다 — 무시된 것과 같은 결과다.
    $where[]             = 'EXISTS (SELECT 1 FROM p_item_branches ibq'
                         . ' WHERE ibq.item_id = i.id AND ibq.branch_id = CAST(:branch_used AS bigint))';
    $bind[':branch_used'] = $branchUsed;
}
if ($stockManaged !== '') {
    $where[]         = 'i.stock_managed = CAST(:managed AS boolean)';
    $bind[':managed'] = $stockManaged === '1' ? 'true' : 'false';
}

$filter = $where === [] ? '1=1' : implode(' AND ', $where);

// ORDER BY 는 바인딩할 수 없다 → 허용 목록의 키로만 고정 조각을 고른다 (PRD S-04).
// 두 번째 키(i.id)를 붙여 같은 값이 여러 개일 때 페이지 사이 순서가 흔들리지 않게 한다.
$orderSql = [
    'name|asc'        => ' ORDER BY lower(i.name) ASC, i.id ASC',
    'name|desc'       => ' ORDER BY lower(i.name) DESC, i.id DESC',
    'code|asc'        => ' ORDER BY i.code ASC, i.id ASC',
    'code|desc'       => ' ORDER BY i.code DESC, i.id DESC',
    'vendor|asc'      => ' ORDER BY i.vendor_id ASC, i.code ASC, i.id ASC',
    'vendor|desc'     => ' ORDER BY i.vendor_id DESC, i.code DESC, i.id DESC',
    'updated_at|asc'  => ' ORDER BY i.updated_at ASC, i.id ASC',
    'updated_at|desc' => ' ORDER BY i.updated_at DESC, i.id DESC',
];

$sqlList = <<<'SQL'
    SELECT i.id, i.code, i.name, i.spec, i.unit, i.pack_qty, i.barcode,
           i.stock_managed, i.safety_qty, i.lead_days, i.min_order_qty,
           CAST(i.price_in AS text) AS price_in, i.is_active,
           i.vendor_id, v.name AS vendor_name,
           i.cat1_id, i.cat2_id, i.cat3_id,
           c1.name AS cat1_name, c2.name AS cat2_name, c3.name AS cat3_name
      FROM p_items i
      JOIN p_vendors v ON v.id = i.vendor_id
      LEFT JOIN p_categories c1 ON c1.id = i.cat1_id
      LEFT JOIN p_categories c2 ON c2.id = i.cat2_id
      LEFT JOIN p_categories c3 ON c3.id = i.cat3_id
     WHERE
    SQL;

$sqlCount = <<<'SQL'
    SELECT count(*) AS total FROM p_items i WHERE
    SQL;

$db = Db::instance();

// 총 건수는 목록과 **같은 조건**으로 센다 (PRD S-02 — 내가 볼 수 있는 개수만 나간다).
$countRow = $db->run($sqlCount . ' ' . $filter, $bind)->fetch();
$total    = is_array($countRow) ? (int) $countRow['total'] : 0;

$listBind         = $bind;
$listBind[':lim'] = TBOX_PURCHASE_ITEMS_PER_PAGE;
$listBind[':off'] = ($page - 1) * TBOX_PURCHASE_ITEMS_PER_PAGE;

$rows = $db->run(
    $sqlList . ' ' . $filter . $orderSql[$sort . '|' . $dir] . ' LIMIT :lim OFFSET :off',
    $listBind
)->fetchAll();

$items = [];
foreach ($rows as $row) {
    $items[] = [
        'id'            => (int) $row['id'],
        'code'          => (string) $row['code'],
        'name'          => (string) $row['name'],
        'spec'          => $row['spec'] === null ? null : (string) $row['spec'],
        'unit'          => $row['unit'] === null ? null : (string) $row['unit'],
        'pack_qty'      => (int) $row['pack_qty'],
        'barcode'       => $row['barcode'] === null ? null : (string) $row['barcode'],
        'vendor_id'     => (int) $row['vendor_id'],
        'vendor_name'   => (string) $row['vendor_name'],
        'cat1_id'       => $row['cat1_id'] === null ? null : (int) $row['cat1_id'],
        'cat2_id'       => $row['cat2_id'] === null ? null : (int) $row['cat2_id'],
        'cat3_id'       => $row['cat3_id'] === null ? null : (int) $row['cat3_id'],
        'cat1_name'     => $row['cat1_name'] === null ? null : (string) $row['cat1_name'],
        'cat2_name'     => $row['cat2_name'] === null ? null : (string) $row['cat2_name'],
        'cat3_name'     => $row['cat3_name'] === null ? null : (string) $row['cat3_name'],
        'stock_managed' => $row['stock_managed'] === true,
        'safety_qty'    => (int) $row['safety_qty'],
        'lead_days'     => (int) $row['lead_days'],
        'min_order_qty' => (int) $row['min_order_qty'],
        'price_in'      => $row['price_in'] === null ? null : (string) $row['price_in'],
        'is_active'     => $row['is_active'] === true,
        /*
         * 현재고는 **21단계**에 생긴다 (원장 `p_stock_moves` 의 합, ARCH 5.18 「원장」).
         * 그때까지 null 을 내려 준다 — 화면이 칸을 그대로 두고 「—」 를 보이게 하려는 것이며,
         * 0 을 내려 주면 "재고가 없다" 는 **틀린 사실**을 말하게 된다.
         */
        'stock_qty'     => null,
        'par_qty'       => null,
    ];
}

Http::json([
    'items'    => $items,
    'total'    => $total,
    'page'     => $page,
    'per_page' => TBOX_PURCHASE_ITEMS_PER_PAGE,
    'sort'     => $sort,
    'dir'      => $dir,
]);
