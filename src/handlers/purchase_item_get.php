<?php
declare(strict_types=1);

/**
 * src/handlers/purchase_item_get.php — GET /api/purchase/items/{num}  (정책: purchase.read)
 *
 * ★ 19단계 품목 상세 (PRD F-28, 시안 ⑮ Item). 사용 지점·지점별 인가량을 함께 준다.
 *
 * 지점 격리 (SECURITY R-65, T-42)
 *   품목 한 건은 브랜드 범위의 공개 마스터지만, **딸린 목록 둘은 지점에 묶여 있다**:
 *     · 사용 지점 (`p_item_branches`)  → `Authz::purchaseScopeWhere($user, 'ib')`
 *     · 지점별 인가량 (`p_par_levels`) → 장소를 조인해 `…($user, 'l')`
 *   requester 가 다른 지점의 인가량을 이 창구로 읽어 가지 못하게, 두 목록 모두 **SQL WHERE**
 *   에서 좁힌다. 품목 자체가 다른 지점에만 묶여 있으면 목록 API 와 같은 규칙으로 404 다 —
 *   "보이지 않는 것" 과 "없는 것" 을 구별해 주지 않는다 (PRD S-09-5).
 *
 * 조회는 감사 로그를 남기지 않는다.
 *
 * @var array<string,mixed> $user
 * @var list<string>        $params $params[0] = p_items.id
 */

$idRaw = $params[0] ?? '';
if (!is_string($idRaw) || preg_match('/^[0-9]{1,9}$/', $idRaw) !== 1) {
    Http::error(404, Http::MSG_NOT_FOUND);
}
$id = (int) $idRaw;

$scopeItem = Authz::purchaseScopeWhere($user, 'ib');
$scopeLoc  = Authz::purchaseScopeWhere($user, 'l');

$db = Db::instance();

// ─── 품목 한 건 — 지점 격리는 목록 API 와 **같은 EXISTS 조건**이다 ───
$sqlItem = <<<'SQL'
    SELECT i.id, i.code, i.name, i.spec, i.unit, i.pack_qty, i.barcode,
           i.stock_managed, i.safety_qty, i.lead_days, i.min_order_qty,
           CAST(i.price_in AS text) AS price_in, i.is_active,
           i.brand_id, br.name AS brand_name,
           i.vendor_id, v.name AS vendor_name,
           i.cat1_id, i.cat2_id, i.cat3_id,
           c1.name AS cat1_name, c2.name AS cat2_name, c3.name AS cat3_name,
           i.updated_at
      FROM p_items i
      JOIN p_vendors v ON v.id = i.vendor_id
      LEFT JOIN p_brands br ON br.id = i.brand_id
      LEFT JOIN p_categories c1 ON c1.id = i.cat1_id
      LEFT JOIN p_categories c2 ON c2.id = i.cat2_id
      LEFT JOIN p_categories c3 ON c3.id = i.cat3_id
     WHERE i.id = CAST(:id AS bigint) AND
    SQL;

$itemBind = [':id' => $id];
if ($scopeItem['sql'] === '1=0') {
    $visible = '1=0';
} elseif ($scopeItem['sql'] === '1=1') {
    $visible = '1=1';
} else {
    // 목록 API(purchase_items_search.php)와 **같은 모양의 조건**이다. 조각을 이어 붙이는
    // 줄에 값이 한 글자도 없고, 지점 번호는 `$scopeItem['params']` 로만 바인딩된다.
    // 23단계 — 「뺀」 사용 지점(`ib.is_active = false`)은 없는 것으로 본다(목록·담기와 같은 조건).
    $visible  = '(EXISTS (SELECT 1 FROM p_item_branches ib';
    $visible .= ' WHERE ib.item_id = i.id AND ib.is_active AND ';
    $visible .= $scopeItem['sql'];
    $visible .= ') OR NOT EXISTS (SELECT 1 FROM p_item_branches ibx';
    $visible .= ' WHERE ibx.item_id = i.id AND ibx.is_active))';
    $itemBind = $itemBind + $scopeItem['params'];
}

$row = $db->run($sqlItem . ' ' . $visible, $itemBind)->fetch();
if (!is_array($row)) {
    Http::error(404, Http::MSG_NOT_FOUND);
}

// ─── 사용 지점 — 내가 볼 수 있는 지점만 ───
$sqlBranches = <<<'SQL'
    SELECT ib.branch_id, b.name AS branch_name
      FROM p_item_branches ib
      JOIN p_branches b ON b.id = ib.branch_id
     WHERE ib.item_id = CAST(:id AS bigint) AND ib.is_active AND
    SQL;

$branchRows = $db->run(
    $sqlBranches . ' ' . $scopeItem['sql'] . ' ORDER BY b.name ASC',
    [':id' => $id] + $scopeItem['params']
)->fetchAll();

// ─── 지점별 인가량 — 장소를 조인해 장소의 지점으로 좁힌다 ───
$sqlPar = <<<'SQL'
    SELECT pl.id, pl.location_id, pl.qty, CAST(pl.effective_from AS text) AS effective_from,
           pl.is_active, l.name AS location_name, l.branch_id, b.name AS branch_name
      FROM p_par_levels pl
      JOIN p_locations l ON l.id = pl.location_id
      JOIN p_branches  b ON b.id = l.branch_id
     WHERE pl.item_id = CAST(:id AS bigint) AND pl.is_active AND
    SQL;

$parRows = $db->run(
    $sqlPar . ' ' . $scopeLoc['sql'] . ' ORDER BY b.name ASC, l.name ASC, pl.effective_from DESC',
    [':id' => $id] + $scopeLoc['params']
)->fetchAll();

$branches = [];
foreach ($branchRows as $b) {
    $branches[] = ['branch_id' => (int) $b['branch_id'], 'branch_name' => (string) $b['branch_name']];
}

$parLevels = [];
foreach ($parRows as $p) {
    $parLevels[] = [
        'id'             => (int) $p['id'],
        'location_id'    => (int) $p['location_id'],
        'location_name'  => (string) $p['location_name'],
        'branch_id'      => (int) $p['branch_id'],
        'branch_name'    => (string) $p['branch_name'],
        'qty'            => (int) $p['qty'],
        'effective_from' => (string) $p['effective_from'],
    ];
}

Http::json([
    'item' => [
        'id'            => (int) $row['id'],
        'code'          => (string) $row['code'],
        'name'          => (string) $row['name'],
        'spec'          => $row['spec'] === null ? null : (string) $row['spec'],
        'unit'          => $row['unit'] === null ? null : (string) $row['unit'],
        'pack_qty'      => (int) $row['pack_qty'],
        'barcode'       => $row['barcode'] === null ? null : (string) $row['barcode'],
        'brand_id'      => $row['brand_id'] === null ? null : (int) $row['brand_id'],
        'brand_name'    => $row['brand_name'] === null ? null : (string) $row['brand_name'],
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
        'updated_at'    => (string) $row['updated_at'],
        // 현재고·최근 입고는 원장이 생기는 21단계 값이다 (0 을 지어내지 않는다).
        'stock_qty'     => null,
    ],
    'branches'   => $branches,
    'par_levels' => $parLevels,
    'can_edit'   => Authz::canEditPurchaseMaster($user),
]);
