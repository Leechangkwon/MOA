<?php
declare(strict_types=1);

/**
 * src/handlers/purchase_items_create.php — POST /api/purchase/items
 *   (정책: purchase.master.write → buyer·Admin 만, **재인증 대상**)
 *
 * ★ 19단계 품목 등록 (PRD F-28, 시안 ⑮ Item).
 *
 * 지점 격리 예외 — 쓰기 라우트다. `purchase.master.write` 는 buyer·Admin 에게만 열려
 *   있고 그 둘의 지점 범위는 언제나 전 지점(`1=1`)이다. 좁힐 지점이 존재하지 않는다.
 *   이 파일이 `FROM p_` 를 쓰는 곳은 참조 존재 검사뿐이며 그 결과는 응답에 나가지 않는다.
 *
 * 품목코드 규칙 (PRD F-28): **거래처 카탈로그 번호를 그대로** 쓰고, 같은 거래처 안에서만
 * 유일하다. 전역 유일로 두면 두 거래처가 같은 번호를 쓰는 순간 한쪽을 못 넣는다.
 * 중복은 DB 의 부분 UNIQUE 인덱스(`p_items_vendor_code_uidx`)가 마지막 그물이고 409 로 돌린다.
 *
 * @var array<string,mixed> $user
 * @var list<string>        $params 경로 변수 없음
 */

require_once __DIR__ . '/../purchase/Master.php';

/** 허용 키 — 여기 없는 키가 오면 요청 전체를 거부한다 (SECURITY R-07) */
const TBOX_ITEM_FIELDS = [
    'vendor_id', 'code', 'name', 'spec', 'unit', 'pack_qty', 'brand_id',
    'cat1_id', 'cat2_id', 'cat3_id', 'stock_managed',
    'safety_qty', 'lead_days', 'min_order_qty', 'price_in', 'barcode', 'branches',
];

$body = Http::jsonBody();

$reject = static function (string $reason, int $status, string $message, ?string $name = null): void {
    Audit::deny('purchase.master.create', $reason, [
        'target_type' => 'purchase_item',
        'target_name' => $name,
        'detail'      => ['kind' => 'items'],
    ]);
    Http::error($status, $message);
};

if ($body === [] || array_diff(array_keys($body), TBOX_ITEM_FIELDS) !== []) {
    $reject('bad_request', 400, Http::MSG_BAD_REQUEST);
}

try {
    $values = [
        'vendor_id'     => Master::id($body['vendor_id'] ?? null),
        'code'          => Master::name($body['code'] ?? null, 60),
        'name'          => Master::name($body['name'] ?? null, 200),
        'spec'          => Master::optionalText($body['spec'] ?? null, 200),
        'unit'          => Master::optionalText($body['unit'] ?? null, 40),
        'pack_qty'      => array_key_exists('pack_qty', $body) ? Master::count($body['pack_qty'], 1, 9999999) : 1,
        'brand_id'      => Master::optionalId($body['brand_id'] ?? null),
        'cat1_id'       => Master::optionalId($body['cat1_id'] ?? null),
        'cat2_id'       => Master::optionalId($body['cat2_id'] ?? null),
        'cat3_id'       => Master::optionalId($body['cat3_id'] ?? null),
        'stock_managed' => array_key_exists('stock_managed', $body) ? Master::flag($body['stock_managed']) : true,
        'safety_qty'    => array_key_exists('safety_qty', $body) ? Master::count($body['safety_qty'], 0, 9999999) : 0,
        'lead_days'     => array_key_exists('lead_days', $body) ? Master::count($body['lead_days'], 0, 3650) : 0,
        'min_order_qty' => array_key_exists('min_order_qty', $body) ? Master::count($body['min_order_qty'], 1, 9999999) : 1,
        'price_in'      => Master::optionalText($body['price_in'] ?? null, 20),
        'barcode'       => Master::optionalText($body['barcode'] ?? null, 60),
    ];
    // 사용 지점 — 비우면 브랜드 전체다 (시안 ⑮ "비우면 브랜드 전체").
    $branchIds = [];
    if (array_key_exists('branches', $body)) {
        if (!is_array($body['branches'])) {
            throw new RuntimeException('bad_request');
        }
        foreach ($body['branches'] as $raw) {
            $branchIds[] = Master::id($raw);
        }
        $branchIds = array_values(array_unique($branchIds));
    }
} catch (RuntimeException $e) {
    $reject($e->getMessage(), 400, Http::MSG_BAD_REQUEST);
    exit;
}

// 단가는 숫자 문자열만 — `23,750` 같은 표기는 화면이 풀어서 보낸다.
if ($values['price_in'] !== null && preg_match('/^[0-9]{1,10}(\.[0-9]{1,2})?$/', $values['price_in']) !== 1) {
    $reject('bad_request', 400, Http::MSG_BAD_REQUEST, $values['name']);
}

$db = Db::instance();

// ─── 참조 존재 검사 (활성 행만) ───
// lint:no-branch-scope 참조가 **있는가**만 묻는 조회다 — 결과는 응답에 나가지 않는다.
$sqlRef = [
    'vendor'   => 'SELECT 1 AS ok FROM p_vendors    WHERE id = CAST(:id AS bigint) AND is_active',
    'brand'    => 'SELECT 1 AS ok FROM p_brands     WHERE id = CAST(:id AS bigint) AND is_active',
    'category' => 'SELECT 1 AS ok FROM p_categories WHERE id = CAST(:id AS bigint) AND is_active',
    'branch'   => 'SELECT 1 AS ok FROM p_branches   WHERE id = CAST(:id AS bigint) AND is_active',
];
$mustExist = static function (string $what, ?int $refId) use ($db, $sqlRef, $reject, $values): void {
    if ($refId === null) {
        return;
    }
    if (!is_array($db->run($sqlRef[$what], [':id' => $refId])->fetch())) {
        $reject('bad_reference', 400, Http::MSG_BAD_REQUEST, $values['name']);
    }
};

$mustExist('vendor', $values['vendor_id']);
$mustExist('brand', $values['brand_id']);
$mustExist('category', $values['cat1_id']);
$mustExist('category', $values['cat2_id']);
$mustExist('category', $values['cat3_id']);
foreach ($branchIds as $branchId) {
    $mustExist('branch', $branchId);
}

$sqlInsert = <<<'SQL'
    INSERT INTO p_items
        (brand_id, vendor_id, code, name, spec, unit, pack_qty,
         cat1_id, cat2_id, cat3_id, stock_managed, safety_qty, lead_days,
         min_order_qty, price_in, barcode, created_by)
    VALUES
        (CAST(:brand_id AS bigint), CAST(:vendor_id AS bigint), :code, :name, :spec, :unit,
         CAST(:pack_qty AS int), CAST(:cat1_id AS bigint), CAST(:cat2_id AS bigint),
         CAST(:cat3_id AS bigint), CAST(:stock_managed AS boolean), CAST(:safety_qty AS int),
         CAST(:lead_days AS int), CAST(:min_order_qty AS int),
         CAST(:price_in AS numeric), :barcode, :created_by)
    RETURNING id
    SQL;

$sqlBranch = <<<'SQL'
    INSERT INTO p_item_branches (item_id, branch_id)
    VALUES (CAST(:item AS bigint), CAST(:branch AS bigint))
    SQL;

$db->begin();
try {
    $row = $db->run($sqlInsert, [
        ':brand_id'      => $values['brand_id'],
        ':vendor_id'     => $values['vendor_id'],
        ':code'          => $values['code'],
        ':name'          => $values['name'],
        ':spec'          => $values['spec'],
        ':unit'          => $values['unit'],
        ':pack_qty'      => $values['pack_qty'],
        ':cat1_id'       => $values['cat1_id'],
        ':cat2_id'       => $values['cat2_id'],
        ':cat3_id'       => $values['cat3_id'],
        ':stock_managed' => $values['stock_managed'] ? 'true' : 'false',
        ':safety_qty'    => $values['safety_qty'],
        ':lead_days'     => $values['lead_days'],
        ':min_order_qty' => $values['min_order_qty'],
        ':price_in'      => $values['price_in'],
        ':barcode'       => $values['barcode'],
        ':created_by'    => (int) $user['id'],
    ])->fetch();

    if (!is_array($row)) {
        throw new RuntimeException('품목을 만들지 못했습니다');
    }
    $newId = (int) $row['id'];

    foreach ($branchIds as $branchId) {
        $db->run($sqlBranch, [':item' => $newId, ':branch' => $branchId]);
    }

    Audit::allow('purchase.master.create', [
        'target_type' => 'purchase_item',
        'target_id'   => $newId,
        'target_name' => $values['code'] . ' ' . $values['name'],
        'detail'      => [
            'kind'      => 'items',
            'target_id' => $newId,
            'after'     => $values,
        ],
    ]);
    $db->commit();
} catch (PDOException $e) {
    $db->rollback();
    if ($e->getCode() === '23505') {
        $reject('conflict', 409, Http::MSG_CONFLICT, $values['name']);
    }
    if ($e->getCode() === '23503') {
        $reject('bad_reference', 400, Http::MSG_BAD_REQUEST, $values['name']);
    }
    throw $e;
} catch (Throwable $e) {
    $db->rollback();
    throw $e;
}

Http::json(['id' => $newId] + $values + ['branches' => $branchIds], 201);
