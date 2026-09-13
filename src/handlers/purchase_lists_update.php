<?php
declare(strict_types=1);

/**
 * src/handlers/purchase_lists_update.php — PATCH /api/purchase/lists/{num}
 *   (정책: purchase.request.write)
 *
 * ★ 20단계 「내 발주 리스트」 고치기 (PRD F-31, 시안 ③ MyList).
 *
 * 본문 { name?, is_shared?, items? }
 *   `items` 를 보내면 **그 리스트의 품목이 보낸 목록 그대로**가 된다(담기·빼기·순서 한 번에).
 *   부분 수정 API 를 여럿 두는 것보다 단순하고, 중간 상태가 없어 순서가 어긋나지 않는다.
 *
 * 「빼기」도 삭제가 아니다 — `p_list_items` 에도 앱 계정의 DELETE 권한이 없다.
 * 목록에서 빠진 품목은 `is_active = false` 로 남고, 다시 담으면 같은 행이 되살아난다
 * (`INSERT … ON CONFLICT DO UPDATE`). 기초정보의 「중단」과 같은 방식이다.
 *
 * 고칠 수 있는 사람은 **소유자 본인**뿐이다 (SECURITY R-68). 공유 리스트를 남이 고칠 수
 * 있게 하면 "내 리스트" 라는 말이 성립하지 않는다. 내 것이 아니면 404 다 —
 * 남의 리스트가 있다는 사실조차 알려 주지 않는다 (PRD S-09-5).
 *
 * @var array<string,mixed> $user
 * @var list<string>        $params $params[0] = p_lists.id
 */

require_once __DIR__ . '/../purchase/Doc.php';

const TBOX_LIST_PATCH_KEYS = ['name', 'is_shared', 'items'];
const TBOX_LIST_PATCH_ITEM = ['item_id', 'default_qty', 'sort_no'];
const TBOX_LIST_PATCH_MAX  = 200;

$idRaw = $params[0] ?? '';
if (!is_string($idRaw) || preg_match('/^[0-9]{1,9}$/', $idRaw) !== 1) {
    Http::error(404, Http::MSG_NOT_FOUND);
}
$listId = (int) $idRaw;

$db   = Db::instance();
$body = Http::jsonBody();

$reject = static function (string $reason) use ($listId): void {
    Audit::deny('purchase.list.update', $reason, [
        'target_type' => 'purchase_list',
        'target_id'   => $listId,
        'target_name' => (string) $listId,
    ]);
    Http::error(400, Http::MSG_BAD_REQUEST);
};

if ($body === [] || array_diff(array_keys($body), TBOX_LIST_PATCH_KEYS) !== []) {
    $reject('bad_request');
}

$scopeB  = Authz::purchaseScopeWhere($user, 'b');
$scopeIb = Authz::purchaseScopeWhere($user, 'ib');
// 소유자 본인 것만 — `pl` 별칭 조건도 함께 건다(한 화면의 규칙을 두 곳으로 가르지 않는다).
$scopePl = Authz::purchaseScopeWhere($user, 'pl');

$sqlList = <<<'SQL'
    SELECT pl.id, pl.name, pl.is_shared, pl.branch_id
      FROM p_lists pl
     WHERE pl.id = CAST(:id AS bigint) AND pl.is_active
       AND pl.owner_id = CAST(:me AS bigint) AND
    SQL;

$list = $db->run(
    $sqlList . ' (' . $scopePl['sql'] . ' OR pl.branch_id IS NULL)',
    [':id' => $listId, ':me' => (int) $user['id']] + $scopePl['params']
)->fetch();
if (!is_array($list)) {
    Http::error(404, Http::MSG_NOT_FOUND);
}

$before = [
    'name'      => (string) $list['name'],
    'is_shared' => $list['is_shared'] === true,
];

$name = (string) $list['name'];
if (array_key_exists('name', $body)) {
    if (!is_string($body['name']) || preg_match('//u', $body['name']) !== 1) {
        $reject('bad_request');
    }
    $name = trim($body['name']);
    if ($name === '' || iconv_strlen($name, 'UTF-8') > 60) {
        $reject('bad_request');
    }
}

$isShared = $list['is_shared'] === true;
$branchId = $list['branch_id'] === null ? null : (int) $list['branch_id'];
if (array_key_exists('is_shared', $body)) {
    if (!is_bool($body['is_shared'])) {
        $reject('bad_request');
    }
    $isShared = $body['is_shared'];
    if ($isShared) {
        $branchId = $branchId ?? Doc::resolveBranch($db, $scopeB, null);
        if ($branchId === null) {
            // 지점이 하나로 정해지지 않는 사람은 「지점과 공유」를 켤 수 없다.
            $reject('branch_required');
        }
    }
}

$wanted = null;
if (array_key_exists('items', $body)) {
    if (!is_array($body['items']) || count($body['items']) > TBOX_LIST_PATCH_MAX) {
        $reject('bad_request');
    }
    $wanted = [];
    $seen   = [];
    foreach ($body['items'] as $row) {
        if (!is_array($row) || array_diff(array_keys($row), TBOX_LIST_PATCH_ITEM) !== []) {
            $reject('bad_request');
        }
        $itemRaw = $row['item_id'] ?? null;
        if (!is_int($itemRaw) && !(is_string($itemRaw) && preg_match('/^[0-9]{1,9}$/', $itemRaw) === 1)) {
            $reject('bad_request');
        }
        $itemId = (int) $itemRaw;
        if ($itemId < 1 || isset($seen[$itemId])) {
            $reject('bad_request');
        }
        $seen[$itemId] = true;

        $qty = $row['default_qty'] ?? '1';
        if (is_int($qty) || is_float($qty)) {
            $qty = (string) $qty;
        }
        if (!is_string($qty) || preg_match('/^[0-9]{1,10}(\.[0-9]{1,3})?$/', $qty) !== 1
            || Doc::milli($qty) <= 0
        ) {
            $reject('bad_request');
        }
        $sortRaw = $row['sort_no'] ?? (count($wanted) + 1);
        if (!is_int($sortRaw) && !(is_string($sortRaw) && preg_match('/^[0-9]{1,6}$/', $sortRaw) === 1)) {
            $reject('bad_request');
        }
        $wanted[] = ['item_id' => $itemId, 'qty' => $qty, 'sort_no' => max(1, (int) $sortRaw)];
    }
    if ($wanted !== []) {
        $items = Doc::loadItems($db, $scopeIb, array_column($wanted, 'item_id'));
        foreach ($wanted as $w) {
            if (!isset($items[$w['item_id']])) {
                $reject('bad_reference');
            }
        }
    }
}

$sqlUpdateList = <<<'SQL'
    UPDATE p_lists
       SET name = :name, is_shared = CAST(:shared AS boolean),
           branch_id = CAST(:branch AS bigint), updated_at = now()
     WHERE id = CAST(:id AS bigint)
    SQL;

$sqlUpsertItem = <<<'SQL'
    INSERT INTO p_list_items (list_id, item_id, default_qty, sort_no, is_active)
    VALUES (CAST(:list AS bigint), CAST(:item AS bigint),
            CAST(:qty AS numeric), CAST(:sort AS int), true)
    ON CONFLICT (list_id, item_id)
    DO UPDATE SET default_qty = EXCLUDED.default_qty,
                  sort_no     = EXCLUDED.sort_no,
                  is_active   = true
    SQL;

/** 이번 목록에 없는 품목은 「빼기」 — 지우지 않고 is_active 를 내린다. */
$sqlDeactivateHead = <<<'SQL'
    UPDATE p_list_items SET is_active = false
     WHERE list_id = CAST(:list AS bigint) AND is_active
    SQL;

$db->begin();
try {
    $db->run($sqlUpdateList, [
        ':name'   => $name,
        ':shared' => $isShared ? 'true' : 'false',
        ':branch' => $branchId,
        ':id'     => $listId,
    ]);

    if ($wanted !== null) {
        $keepNames = [];
        $deBind    = [':list' => $listId];
        foreach ($wanted as $i => $w) {
            $n             = ':keep' . (string) $i;
            $keepNames[]   = $n;
            $deBind[$n]    = $w['item_id'];
        }
        // 자리표시자 이름만 이어 붙인다 — 값은 $deBind 로만 간다 (PRD S-04).
        $notIn = $keepNames === []
            ? ''
            : ' AND item_id NOT IN (' . implode(', ', $keepNames) . ')';
        $db->run($sqlDeactivateHead . $notIn, $deBind);

        foreach ($wanted as $w) {
            $db->run($sqlUpsertItem, [
                ':list' => $listId,
                ':item' => $w['item_id'],
                ':qty'  => $w['qty'],
                ':sort' => $w['sort_no'],
            ]);
        }
    }

    Audit::allow('purchase.list.update', [
        'target_type' => 'purchase_list',
        'target_id'   => $listId,
        'target_name' => $name,
        'detail'      => [
            'target_id' => $listId,
            'branch_id' => $branchId,
            'lines'     => $wanted === null ? null : count($wanted),
            'before'    => $before,
            'after'     => ['name' => $name, 'is_shared' => $isShared],
        ],
    ]);
    $db->commit();
} catch (PDOException $e) {
    $db->rollback();
    if ($e->getCode() === '23503') {
        $reject('bad_reference');
    }
    throw $e;
} catch (Throwable $e) {
    $db->rollback();
    throw $e;
}

Http::json([
    'id'         => $listId,
    'name'       => $name,
    'is_shared'  => $isShared,
    'branch_id'  => $branchId,
    'item_count' => $wanted === null ? null : count($wanted),
]);
