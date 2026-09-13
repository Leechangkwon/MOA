<?php
declare(strict_types=1);

/**
 * src/handlers/purchase_lists_list.php — GET /api/purchase/lists  (정책: purchase.read)
 *
 * ★ 20단계 「내 발주 리스트」 (PRD F-31, 시안 ③ MyList).
 *
 * 누구의 리스트가 보이나 (SECURITY R-68)
 *   · 내가 만든 것                        — 언제나
 *   · 남이 만들었지만 **지점과 공유**한 것 — `is_shared` 이고 그 지점이 내 범위 안일 때만
 *   두 조건 모두 SQL WHERE 다. 응답 뒤 필터가 아니다 — 총 건수만으로도 남의 리스트 수가 샌다.
 *
 * 리스트에 담긴 품목은 **지금 볼 수 있는 품목만** 내려간다. 공유 리스트에 다른 지점 전용
 * 품목이 섞여 있어도, 그 줄은 품목 찾기와 **같은 조건**으로 걸러진다.
 *
 * 조회는 감사 로그를 남기지 않는다.
 *
 * @var array<string,mixed> $user
 * @var list<string>        $params 경로 변수 없음
 */

require_once __DIR__ . '/../purchase/Doc.php';

$scopePl = Authz::purchaseScopeWhere($user, 'pl');
$scopeIb = Authz::purchaseScopeWhere($user, 'ib');

$db = Db::instance();

$sqlLists = <<<'SQL'
    SELECT pl.id, pl.owner_id, pl.branch_id, pl.name, pl.is_shared,
           u.username AS owner_name, b.name AS branch_name,
           (SELECT count(*) FROM p_list_items li
             WHERE li.list_id = pl.id AND li.is_active) AS item_count
      FROM p_lists pl
      LEFT JOIN users      u ON u.id = pl.owner_id
      LEFT JOIN p_branches b ON b.id = pl.branch_id
     WHERE pl.is_active AND
    SQL;

// 내 것 **또는** (공유이면서 내 범위 안). 값은 바인딩뿐이다.
$visible = '(pl.owner_id = CAST(:me AS bigint) OR (pl.is_shared AND (' . $scopePl['sql'] . ')))';
$bind    = [':me' => (int) $user['id']] + $scopePl['params'];

$rows = $db->run(
    $sqlLists . ' ' . $visible . ' ORDER BY pl.name ASC, pl.id ASC LIMIT 100',
    $bind
)->fetchAll();

$lists = [];
$ids   = [];
foreach ($rows as $row) {
    $id         = (int) $row['id'];
    $ids[]      = $id;
    $lists[$id] = [
        'id'          => $id,
        'name'        => (string) $row['name'],
        'owner_id'    => (int) $row['owner_id'],
        'owner_name'  => $row['owner_name'] === null ? null : (string) $row['owner_name'],
        'branch_id'   => $row['branch_id'] === null ? null : (int) $row['branch_id'],
        'branch_name' => $row['branch_name'] === null ? null : (string) $row['branch_name'],
        'is_shared'   => $row['is_shared'] === true,
        'is_mine'     => (int) $row['owner_id'] === (int) $user['id'],
        'item_count'  => (int) $row['item_count'],
        'items'       => [],
    ];
}

if ($ids !== []) {
    $names = [];
    $iBind = [];
    foreach ($ids as $i => $id) {
        $name         = ':k' . (string) $i;
        $names[]      = $name;
        $iBind[$name] = $id;
    }
    $inList = '(' . implode(', ', $names) . ')';

    $sqlItems = <<<'SQL'
        SELECT li.list_id, li.item_id, CAST(li.default_qty AS text) AS default_qty, li.sort_no,
               i.code, i.name, i.spec, i.unit, CAST(i.price_in AS text) AS price_in,
               v.name AS vendor_name
          FROM p_list_items li
          JOIN p_items   i ON i.id = li.item_id
          JOIN p_vendors v ON v.id = i.vendor_id
         WHERE i.is_active AND li.is_active AND li.list_id IN
        SQL;

    // 품목 찾기와 **같은** 지점 격리 조건 (R-65).
    $itemVisible = '1=1';
    if ($scopeIb['sql'] === '1=0') {
        $itemVisible = '1=0';
    } elseif ($scopeIb['sql'] !== '1=1') {
        $itemVisible  = '(EXISTS (SELECT 1 FROM p_item_branches ib';
        $itemVisible .= ' WHERE ib.item_id = i.id AND ib.is_active AND ';
        $itemVisible .= $scopeIb['sql'];
        $itemVisible .= ') OR NOT EXISTS (SELECT 1 FROM p_item_branches ibx';
        $itemVisible .= ' WHERE ibx.item_id = i.id AND ibx.is_active))';
        $iBind        = $iBind + $scopeIb['params'];
    }

    $itemRows = $db->run(
        $sqlItems . ' ' . $inList . ' AND ' . $itemVisible . ' ORDER BY li.sort_no ASC, i.name ASC',
        $iBind
    )->fetchAll();

    foreach ($itemRows as $row) {
        $listId = (int) $row['list_id'];
        if (!isset($lists[$listId])) {
            continue;
        }
        $lists[$listId]['items'][] = [
            'item_id'     => (int) $row['item_id'],
            'code'        => (string) $row['code'],
            'name'        => (string) $row['name'],
            'spec'        => $row['spec'] === null ? null : (string) $row['spec'],
            'unit'        => $row['unit'] === null ? null : (string) $row['unit'],
            'vendor_name' => (string) $row['vendor_name'],
            'price_in'    => $row['price_in'] === null ? null : (string) $row['price_in'],
            'default_qty' => (string) $row['default_qty'],
            'sort_no'     => (int) $row['sort_no'],
        ];
    }
}

Http::json([
    'items'     => array_values($lists),
    'total'     => count($lists),
    'can_write' => Authz::purchaseAuthorize('purchase.request.write', $user),
]);
