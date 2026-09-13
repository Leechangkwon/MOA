<?php
declare(strict_types=1);

/**
 * src/handlers/purchase_items_update.php — PATCH /api/purchase/items/{num}
 *   (정책: purchase.master.write → buyer·Admin 만, **재인증 대상**)
 *
 * ★ 19단계 품목 고치기 · **중단**(is_active=false) · 재개 (PRD F-28, 시안 ⑮).
 *   삭제 라우트가 없다 — 전표가 품목을 참조하므로 중단만 있다 (지시서 보충 4).
 * ★ 23단계 **사용 지점 빼기**(`branches_off`) — `p_item_branches.is_active = false`.
 *   그 표에도 DELETE 권한이 없으므로 「빼기」는 지우기가 아니라 **끄기**다. 지난 전표에는
 *   그대로 남고, 같은 지점을 `branches` 로 다시 넣으면 되살아난다 (아래 SQL 두 개가 짝이다).
 *
 * 지점 격리 예외 — 이 파일은 `no-branch-scope` 표식을 **조회마다** 달아 스스로 선언한다
 *   (23단계에 파일 단위 → 줄 단위로 좁혔다, SECURITY R-65 잔여). 쓰기 라우트이고
 *   `purchase.master.write` 는 buyer·Admin 만이라 그 둘의 지점 범위는 언제나 전 지점(`1=1`)
 *   이다 — 좁힐 지점이 존재하지 않는다.
 *
 * 바뀐 칸만 `detail.before` / `detail.after` 로 감사 로그에 남는다 (PRD F-28).
 * 단가·분류 변경이 이력에 남아야 나중에 "왜 이 값이 됐나" 를 되짚을 수 있다(시안 ⑮ 저장 규칙).
 *
 * @var array<string,mixed> $user
 * @var list<string>        $params $params[0] = p_items.id
 */

require_once __DIR__ . '/../purchase/Master.php';

/** 고칠 수 있는 키. 품목코드·거래처는 바꿀 수 없다 — 둘이 바뀌면 그것은 **다른 품목**이다. */
const TBOX_ITEM_PATCH_FIELDS = [
    'name', 'spec', 'unit', 'pack_qty', 'brand_id',
    'cat1_id', 'cat2_id', 'cat3_id', 'stock_managed',
    'safety_qty', 'lead_days', 'min_order_qty', 'price_in', 'barcode',
    'branches', 'branches_off', 'is_active',
];

$idRaw = $params[0] ?? '';
if (!is_string($idRaw) || preg_match('/^[0-9]{1,9}$/', $idRaw) !== 1) {
    Http::error(404, Http::MSG_NOT_FOUND);
}
$id = (int) $idRaw;

$body = Http::jsonBody();

$auditTarget = [
    'target_type' => 'purchase_item',
    'target_id'   => $id,
    'target_name' => 'items:' . $idRaw,
];

$reject = static function (string $reason, int $status, string $message) use ($auditTarget): void {
    Audit::deny('purchase.master.update', $reason, $auditTarget + ['detail' => ['kind' => 'items']]);
    Http::error($status, $message);
};

$db = Db::instance();

// lint:no-branch-scope 품목 한 건 읽기 — 쓰기 라우트(buyer·Admin)라 좁힐 지점이 없다.
$sqlFind = <<<'SQL'
    SELECT id, code, name, spec, unit, pack_qty, brand_id, cat1_id, cat2_id, cat3_id,
           stock_managed, safety_qty, lead_days, min_order_qty,
           CAST(price_in AS text) AS price_in, barcode, is_active
      FROM p_items
     WHERE id = CAST(:id AS bigint)
    SQL;

$current = $db->run($sqlFind, [':id' => $id])->fetch();
if (!is_array($current)) {
    Http::error(404, Http::MSG_NOT_FOUND);
}

if ($body === [] || array_diff(array_keys($body), TBOX_ITEM_PATCH_FIELDS) !== []) {
    $reject('bad_request', 400, Http::MSG_BAD_REQUEST);
}

// ─── 값 좁히기 — 온 키만 본다 ───
$next = [
    'name'          => (string) $current['name'],
    'spec'          => $current['spec'] === null ? null : (string) $current['spec'],
    'unit'          => $current['unit'] === null ? null : (string) $current['unit'],
    'pack_qty'      => (int) $current['pack_qty'],
    'brand_id'      => $current['brand_id'] === null ? null : (int) $current['brand_id'],
    'cat1_id'       => $current['cat1_id'] === null ? null : (int) $current['cat1_id'],
    'cat2_id'       => $current['cat2_id'] === null ? null : (int) $current['cat2_id'],
    'cat3_id'       => $current['cat3_id'] === null ? null : (int) $current['cat3_id'],
    'stock_managed' => $current['stock_managed'] === true,
    'safety_qty'    => (int) $current['safety_qty'],
    'lead_days'     => (int) $current['lead_days'],
    'min_order_qty' => (int) $current['min_order_qty'],
    'price_in'      => $current['price_in'] === null ? null : (string) $current['price_in'],
    'barcode'       => $current['barcode'] === null ? null : (string) $current['barcode'],
];
$before  = [];
$after   = [];
$wasActive = $current['is_active'] === true;

try {
    if (array_key_exists('name', $body)) {
        $next['name'] = Master::name($body['name'], 200);
    }
    if (array_key_exists('spec', $body)) {
        $next['spec'] = Master::optionalText($body['spec'], 200);
    }
    if (array_key_exists('unit', $body)) {
        $next['unit'] = Master::optionalText($body['unit'], 40);
    }
    if (array_key_exists('pack_qty', $body)) {
        $next['pack_qty'] = Master::count($body['pack_qty'], 1, 9999999);
    }
    if (array_key_exists('brand_id', $body)) {
        $next['brand_id'] = Master::optionalId($body['brand_id']);
    }
    if (array_key_exists('cat1_id', $body)) {
        $next['cat1_id'] = Master::optionalId($body['cat1_id']);
    }
    if (array_key_exists('cat2_id', $body)) {
        $next['cat2_id'] = Master::optionalId($body['cat2_id']);
    }
    if (array_key_exists('cat3_id', $body)) {
        $next['cat3_id'] = Master::optionalId($body['cat3_id']);
    }
    if (array_key_exists('stock_managed', $body)) {
        $next['stock_managed'] = Master::flag($body['stock_managed']);
    }
    if (array_key_exists('safety_qty', $body)) {
        $next['safety_qty'] = Master::count($body['safety_qty'], 0, 9999999);
    }
    if (array_key_exists('lead_days', $body)) {
        $next['lead_days'] = Master::count($body['lead_days'], 0, 3650);
    }
    if (array_key_exists('min_order_qty', $body)) {
        $next['min_order_qty'] = Master::count($body['min_order_qty'], 1, 9999999);
    }
    if (array_key_exists('price_in', $body)) {
        $next['price_in'] = Master::optionalText($body['price_in'], 20);
        if ($next['price_in'] !== null
            && preg_match('/^[0-9]{1,10}(\.[0-9]{1,2})?$/', $next['price_in']) !== 1) {
            throw new RuntimeException('bad_request');
        }
    }
    if (array_key_exists('barcode', $body)) {
        $next['barcode'] = Master::optionalText($body['barcode'], 60);
    }

    $branchIds = null;
    if (array_key_exists('branches', $body)) {
        if (!is_array($body['branches'])) {
            throw new RuntimeException('bad_request');
        }
        $branchIds = [];
        foreach ($body['branches'] as $raw) {
            $branchIds[] = Master::id($raw);
        }
        $branchIds = array_values(array_unique($branchIds));
    }

    /*
     * 23단계 「빼기」 — 끌 지점 목록. `branches`(더하기)와 **같은 요청에 같은 지점**이 오면
     * 무엇을 원하는지 알 수 없으므로 요청 전체를 거부한다. 조용히 한쪽을 고르지 않는다.
     */
    $branchesOff = [];
    if (array_key_exists('branches_off', $body)) {
        if (!is_array($body['branches_off'])) {
            throw new RuntimeException('bad_request');
        }
        foreach ($body['branches_off'] as $raw) {
            $branchesOff[] = Master::id($raw);
        }
        $branchesOff = array_values(array_unique($branchesOff));
        if ($branchIds !== null && array_intersect($branchesOff, $branchIds) !== []) {
            throw new RuntimeException('bad_request');
        }
    }

    $nextActive = array_key_exists('is_active', $body) ? Master::flag($body['is_active']) : $wasActive;
} catch (RuntimeException $e) {
    $reject($e->getMessage(), 400, Http::MSG_BAD_REQUEST);
    exit;
}

// 바뀐 칸 모으기 — numeric 은 `23750.00` 으로 돌아오므로 문자열로 견준다.
foreach ($next as $field => $value) {
    $now = $current[$field] ?? null;
    if ($field === 'stock_managed') {
        $now = $current['stock_managed'] === true;
    } elseif (in_array($field, ['pack_qty', 'safety_qty', 'lead_days', 'min_order_qty'], true)) {
        $now = (int) $now;
    } elseif (in_array($field, ['brand_id', 'cat1_id', 'cat2_id', 'cat3_id'], true)) {
        $now = $now === null ? null : (int) $now;
    } elseif ($now !== null) {
        $now = (string) $now;
    }
    if ($value !== $now) {
        $before[$field] = $now;
        $after[$field]  = $value;
    }
}
if ($nextActive !== $wasActive) {
    $before['is_active'] = $wasActive;
    $after['is_active']  = $nextActive;
}

// ─── 참조 존재 검사 ───
// lint:no-branch-scope 참조가 **있는가**만 묻는 조회다 — 결과는 응답에 나가지 않는다.
$sqlRef = [
    'brand'    => 'SELECT 1 AS ok FROM p_brands     WHERE id = CAST(:id AS bigint) AND is_active',
    'category' => 'SELECT 1 AS ok FROM p_categories WHERE id = CAST(:id AS bigint) AND is_active',
    'branch'   => 'SELECT 1 AS ok FROM p_branches   WHERE id = CAST(:id AS bigint) AND is_active',
];
$mustExist = static function (string $what, ?int $refId) use ($db, $sqlRef, $reject): void {
    if ($refId === null) {
        return;
    }
    if (!is_array($db->run($sqlRef[$what], [':id' => $refId])->fetch())) {
        $reject('bad_reference', 400, Http::MSG_BAD_REQUEST);
    }
};
$mustExist('brand', $next['brand_id']);
$mustExist('category', $next['cat1_id']);
$mustExist('category', $next['cat2_id']);
$mustExist('category', $next['cat3_id']);
if ($branchIds !== null) {
    foreach ($branchIds as $branchId) {
        $mustExist('branch', $branchId);
    }
}
foreach ($branchesOff as $branchId) {
    $mustExist('branch', $branchId);
}

if ($before === [] && $branchIds === null && $branchesOff === []) {
    $reject('no_change', 400, Http::MSG_BAD_REQUEST);
}

$sqlUpdate = <<<'SQL'
    UPDATE p_items
       SET name = :name, spec = :spec, unit = :unit,
           pack_qty = CAST(:pack_qty AS int),
           brand_id = CAST(:brand_id AS bigint),
           cat1_id  = CAST(:cat1_id AS bigint),
           cat2_id  = CAST(:cat2_id AS bigint),
           cat3_id  = CAST(:cat3_id AS bigint),
           stock_managed = CAST(:stock_managed AS boolean),
           safety_qty    = CAST(:safety_qty AS int),
           lead_days     = CAST(:lead_days AS int),
           min_order_qty = CAST(:min_order_qty AS int),
           price_in      = CAST(:price_in AS numeric),
           barcode       = :barcode,
           is_active     = CAST(:is_active AS boolean),
           updated_at    = now()
     WHERE id = CAST(:id AS bigint)
    SQL;

/*
 * 사용 지점 **더하기**. `p_item_branches` 에는 DELETE 권한이 없으므로 행을 지우지 않는다 —
 * 한 번 뺀 지점을 다시 넣는 것은 `is_active` 를 되살리는 일이다(23단계에 칸이 채워졌다).
 */
$sqlAddBranch = <<<'SQL'
    INSERT INTO p_item_branches (item_id, branch_id, is_active)
    VALUES (CAST(:item AS bigint), CAST(:branch AS bigint), true)
    ON CONFLICT (item_id, branch_id) DO UPDATE SET is_active = true
    SQL;

/*
 * 사용 지점 **빼기** (23단계, 20단계 마이그레이션의 `p_item_branches.is_active`).
 * 지우기가 아니라 끄기다 — 지난 전표는 그대로 남고, 품목 찾기의 `branch_used` 와
 * `Doc::loadItems` 의 「보이는 품목」에서 빠진다. 없는 (품목, 지점) 짝이면 0행이 바뀔 뿐이다.
 */
$sqlOffBranch = <<<'SQL'
    UPDATE p_item_branches
       SET is_active = false
     WHERE item_id = CAST(:item AS bigint) AND branch_id = CAST(:branch AS bigint)
    SQL;

$db->begin();
try {
    $db->run($sqlUpdate, [
        ':id'            => $id,
        ':name'          => $next['name'],
        ':spec'          => $next['spec'],
        ':unit'          => $next['unit'],
        ':pack_qty'      => $next['pack_qty'],
        ':brand_id'      => $next['brand_id'],
        ':cat1_id'       => $next['cat1_id'],
        ':cat2_id'       => $next['cat2_id'],
        ':cat3_id'       => $next['cat3_id'],
        ':stock_managed' => $next['stock_managed'] ? 'true' : 'false',
        ':safety_qty'    => $next['safety_qty'],
        ':lead_days'     => $next['lead_days'],
        ':min_order_qty' => $next['min_order_qty'],
        ':price_in'      => $next['price_in'],
        ':barcode'       => $next['barcode'],
        ':is_active'     => $nextActive ? 'true' : 'false',
    ]);

    $addedBranches = [];
    if ($branchIds !== null) {
        foreach ($branchIds as $branchId) {
            $db->run($sqlAddBranch, [':item' => $id, ':branch' => $branchId]);
            $addedBranches[] = $branchId;
        }
    }
    foreach ($branchesOff as $branchId) {
        $db->run($sqlOffBranch, [':item' => $id, ':branch' => $branchId]);
    }

    /*
     * 한 요청 = 한 행. 무엇을 한 요청인지 **문장으로** 갈린다:
     *   중단      품목 자체를 껐다
     *   빼기      바꾼 칸도 더한 지점도 없이 사용 지점만 뺐다 (23단계)
     *   고치기    그 밖
     */
    if ($nextActive !== $wasActive && !$nextActive) {
        $action = 'purchase.master.deactivate';
    } elseif ($before === [] && $addedBranches === [] && $branchesOff !== []) {
        $action = 'purchase.master.branch_off';
    } else {
        $action = 'purchase.master.update';
    }

    Audit::allow($action, $auditTarget + [
        'target_name' => (string) $current['code'] . ' ' . $next['name'],
        'detail'      => [
            'kind'      => 'items',
            'target_id' => $id,
            'before'    => $before,
            'after'     => $after,
        ] + ($addedBranches === [] ? [] : ['branches' => $addedBranches])
          + ($branchesOff === []   ? [] : ['branches_off' => $branchesOff]),
    ]);
    $db->commit();
} catch (PDOException $e) {
    $db->rollback();
    if ($e->getCode() === '23503') {
        $reject('bad_reference', 400, Http::MSG_BAD_REQUEST);
    }
    throw $e;
} catch (Throwable $e) {
    $db->rollback();
    throw $e;
}

Http::json([
    'id'           => $id,
    'before'       => $before,
    'after'        => $after,
    'branches_off' => $branchesOff,
]);
