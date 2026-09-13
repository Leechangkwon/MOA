<?php
declare(strict_types=1);

/**
 * src/handlers/purchase_lists_create.php — POST /api/purchase/lists
 *   (정책: purchase.request.write → requester·buyer·Admin)
 *
 * ★ 20단계 「내 발주 리스트」 만들기 (PRD F-31, 시안 ③ MyList).
 *
 * 본문 { name, is_shared?, items?: [ { item_id, default_qty?, sort_no? } … ] }
 *
 *   · 소유자는 **언제나 본인**이다. 본문으로 남의 이름을 붙일 수 없다 (PRD S-01).
 *   · 「지점과 공유」를 켜면 그 리스트의 지점은 **내 범위의 지점**이다. 지점이 하나로
 *     정해지지 않는 사람(구매팀)은 공유를 켤 수 없다 — 누구와 공유하는지가 정해지지 않는다.
 *   · 담는 품목은 품목 찾기와 **같은 조건**으로 좁힌다 — 다른 지점 전용 품목은 담기지 않는다.
 *
 * @var array<string,mixed> $user
 * @var list<string>        $params 경로 변수 없음
 */

require_once __DIR__ . '/../purchase/Doc.php';

const TBOX_LIST_CREATE_KEYS = ['name', 'is_shared', 'items'];
const TBOX_LIST_ITEM_KEYS   = ['item_id', 'default_qty', 'sort_no'];
const TBOX_LIST_MAX_ITEMS   = 200;

$db   = Db::instance();
$body = Http::jsonBody();

$reject = static function (string $reason, int $status = 400): void {
    Audit::deny('purchase.list.create', $reason, [
        'target_type' => 'purchase_list',
        'target_name' => '',
    ]);
    Http::error($status, Http::MSG_BAD_REQUEST);
};

if ($body === [] || array_diff(array_keys($body), TBOX_LIST_CREATE_KEYS) !== []) {
    $reject('bad_request');
}

$name = $body['name'] ?? null;
if (!is_string($name) || preg_match('//u', $name) !== 1) {
    $reject('bad_request');
}
$name = trim($name);
if ($name === '' || iconv_strlen($name, 'UTF-8') > 60) {
    $reject('bad_request');
}

$isShared = ($body['is_shared'] ?? false) === true;

$scopeB  = Authz::purchaseScopeWhere($user, 'b');
$scopeIb = Authz::purchaseScopeWhere($user, 'ib');

$branchId = Doc::resolveBranch($db, $scopeB, null);
if ($isShared && $branchId === null) {
    // 지점이 하나로 정해지지 않는 사람은 「지점과 공유」를 켤 수 없다.
    $reject('branch_required');
}

$wanted = [];
if (array_key_exists('items', $body)) {
    if (!is_array($body['items']) || count($body['items']) > TBOX_LIST_MAX_ITEMS) {
        $reject('bad_request');
    }
    $seen = [];
    foreach ($body['items'] as $row) {
        if (!is_array($row) || array_diff(array_keys($row), TBOX_LIST_ITEM_KEYS) !== []) {
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
        $sortNo = max(1, (int) $sortRaw);

        $wanted[] = ['item_id' => $itemId, 'qty' => $qty, 'sort_no' => $sortNo];
    }
}

if ($wanted !== []) {
    $items = Doc::loadItems($db, $scopeIb, array_column($wanted, 'item_id'));
    foreach ($wanted as $w) {
        if (!isset($items[$w['item_id']])) {
            $reject('bad_reference');
        }
    }
}

$sqlInsertList = <<<'SQL'
    INSERT INTO p_lists (owner_id, branch_id, name, is_shared)
    VALUES (CAST(:owner AS bigint), CAST(:branch AS bigint), :name, CAST(:shared AS boolean))
    RETURNING id
    SQL;

$sqlInsertItem = <<<'SQL'
    INSERT INTO p_list_items (list_id, item_id, default_qty, sort_no)
    VALUES (CAST(:list AS bigint), CAST(:item AS bigint), CAST(:qty AS numeric), CAST(:sort AS int))
    SQL;

$db->begin();
try {
    $row = $db->run($sqlInsertList, [
        ':owner'  => (int) $user['id'],
        ':branch' => $branchId,
        ':name'   => $name,
        ':shared' => $isShared ? 'true' : 'false',
    ])->fetch();
    if (!is_array($row)) {
        throw new RuntimeException('리스트를 만들지 못했습니다');
    }
    $listId = (int) $row['id'];

    foreach ($wanted as $w) {
        $db->run($sqlInsertItem, [
            ':list' => $listId,
            ':item' => $w['item_id'],
            ':qty'  => $w['qty'],
            ':sort' => $w['sort_no'],
        ]);
    }

    Audit::allow('purchase.list.create', [
        'target_type' => 'purchase_list',
        'target_id'   => $listId,
        'target_name' => $name,
        'detail'      => [
            'target_id' => $listId,
            'branch_id' => $branchId,
            'lines'     => count($wanted),
            'shared'    => $isShared,
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
    'item_count' => count($wanted),
], 201);
