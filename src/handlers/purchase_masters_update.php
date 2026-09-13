<?php
declare(strict_types=1);

/**
 * src/handlers/purchase_masters_update.php
 *   PATCH /api/purchase/{종류}/{num}   (정책: purchase.master.write → buyer·Admin 만, **재인증 대상**)
 *
 * ★ 19단계 기초정보 고치기 · **중단(is_active=false)** · 재개 (PRD F-28, ARCH 5.18).
 *
 * **삭제 라우트가 없다.** 기초정보를 그만 쓰는 것은 `{"is_active": false}` 이고, 앱 DB 계정에는
 * `p_*` 에 DELETE 권한 자체가 없다 (db/grants.sql). 전표가 기초정보를 참조하게 되는 20단계
 * 이후에도 "지워서 추적이 끊기는" 경로가 만들어지지 않는다 (팀·계정과 같은 원칙).
 *
 * 지점 격리 예외 — 쓰기 라우트다. 정책 `purchase.master.write` 는 buyer·Admin 에게만
 *   열려 있고 그 둘의 지점 범위는 언제나 전 지점(`1=1`)이다. 좁힐 지점이 존재하지 않는다.
 *   (읽기 라우트인 purchase_masters_list.php 는 반대로 반드시 purchaseScopeWhere 를 지난다.)
 *
 * 바뀐 칸은 `detail.before` / `detail.after` 로 감사 로그에 남는다 (PRD F-28 "변경은 before/after").
 * 바뀐 것이 하나도 없으면 400 이다 — "보냈는데 아무 일도 없었다" 를 성공으로 답하지 않는다.
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 * @var list<string>        $params $params[0] = 대상 id (정수 문자열)
 */

require_once __DIR__ . '/../purchase/Master.php';

const TBOX_PURCHASE_UPDATE_KIND = [
    'PATCH /api/purchase/brands/{num}'     => 'brands',
    'PATCH /api/purchase/branches/{num}'   => 'branches',
    'PATCH /api/purchase/locations/{num}'  => 'locations',
    'PATCH /api/purchase/vendors/{num}'    => 'vendors',
    'PATCH /api/purchase/categories/{num}' => 'categories',
    'PATCH /api/purchase/par-levels/{num}' => 'par-levels',
];

$kind = TBOX_PURCHASE_UPDATE_KIND[TBOX_ROUTE] ?? null;
if ($kind === null) {
    Http::error(404, Http::MSG_NOT_FOUND);
}

$idRaw = $params[0] ?? '';
if (!is_string($idRaw) || preg_match('/^[0-9]{1,9}$/', $idRaw) !== 1) {
    Http::error(404, Http::MSG_NOT_FOUND);
}
$id = (int) $idRaw;

$body = Http::jsonBody();

/* 거래처 이름 겹침 확인 — 만들기와 **같은 규칙**이다 (2차 보정 2026-09-13).
 * 이름을 바꾸다 다른 거래처와 같아지는 경우도 사람이 한 번 확인해야 한다. */
$allowDuplicateName = ($body['allow_duplicate_name'] ?? null) === true;
unset($body['allow_duplicate_name']);

$auditTarget = [
    'target_type' => 'purchase_master',
    'target_id'   => $id,
    'target_name' => $kind . ':' . $idRaw,
];

$reject = static function (string $reason, int $status, string $message) use ($auditTarget, $kind): void {
    Audit::deny('purchase.master.update', $reason, $auditTarget + ['detail' => ['kind' => $kind]]);
    Http::error($status, $message);
};

// ─── [1] 대상이 있나 (없으면 404 동일 문구) ───
$current = Master::find($kind, $id);
if ($current === null) {
    Http::error(404, Http::MSG_NOT_FOUND);
}

// ─── [2] 허용 키 (R-07) ───
if ($body === [] || array_diff(array_keys($body), Master::updatableKeys($kind)) !== []) {
    $reject('bad_request', 400, Http::MSG_BAD_REQUEST);
}

// ─── [3] 값 좁히기 — 고치기는 **온 키만** 본다(부분 수정) ───
try {
    $changes = Master::normalize($kind, $body, true);
    $active  = array_key_exists('is_active', $body) ? Master::flag($body['is_active']) : null;
} catch (RuntimeException $e) {
    $reject($e->getMessage(), 400, Http::MSG_BAD_REQUEST);
    exit;
}

$db = Db::instance();

// ─── [4] 참조 존재 검사 (만들기와 같은 규칙) ───
// lint:no-branch-scope 참조가 **있는가**만 묻는 조회다 — 결과는 응답에 나가지 않는다.
$sqlRef = [
    'brand'    => 'SELECT 1 AS ok FROM p_brands    WHERE id = CAST(:id AS bigint) AND is_active',
    'branch'   => 'SELECT 1 AS ok FROM p_branches  WHERE id = CAST(:id AS bigint) AND is_active',
    'location' => 'SELECT 1 AS ok FROM p_locations WHERE id = CAST(:id AS bigint) AND is_active',
    'item'     => 'SELECT 1 AS ok FROM p_items     WHERE id = CAST(:id AS bigint) AND is_active',
    'category' => 'SELECT 1 AS ok FROM p_categories WHERE id = CAST(:id AS bigint) AND is_active',
];
$mustExist = static function (string $what, mixed $refId) use ($db, $sqlRef, $reject): void {
    if ($refId === null) {
        return;
    }
    if (!is_array($db->run($sqlRef[$what], [':id' => (int) $refId])->fetch())) {
        $reject('bad_reference', 400, Http::MSG_BAD_REQUEST);
    }
};

if (array_key_exists('brand_id', $changes)) {
    $mustExist('brand', $changes['brand_id']);
}
if (array_key_exists('branch_id', $changes)) {
    $mustExist('branch', $changes['branch_id']);
}
if (array_key_exists('warehouse_location_id', $changes)) {
    $mustExist('location', $changes['warehouse_location_id']);
}
if (array_key_exists('central_location_id', $changes)) {
    $mustExist('location', $changes['central_location_id']);
}
if (array_key_exists('location_id', $changes)) {
    $mustExist('location', $changes['location_id']);
}
if (array_key_exists('item_id', $changes)) {
    $mustExist('item', $changes['item_id']);
}
if (array_key_exists('parent_id', $changes)) {
    if ($changes['parent_id'] !== null && (int) $changes['parent_id'] === $id) {
        $reject('bad_request', 400, Http::MSG_BAD_REQUEST);   // 자기 자신이 부모일 수 없다
    }
    $mustExist('category', $changes['parent_id']);
}

/* ─── [4-1] 거래처 이름 겹침 (2차 보정) — **자기 자신은 빼고** 센다 ─── */
if ($kind === 'vendors' && !$allowDuplicateName && array_key_exists('name', $changes)) {
    // lint:no-branch-scope 거래처는 지점 무관 마스터다 (이름 겹침만 본다).
    $sqlSameName = <<<'SQL'
        SELECT 1 AS ok FROM p_vendors
         WHERE lower(name) = lower(:name) AND id <> CAST(:id AS bigint)
         LIMIT 1
        SQL;
    if (is_array($db->run($sqlSameName, [':name' => (string) $changes['name'], ':id' => $id])->fetch())) {
        $reject('conflict', 409, Http::MSG_CONFLICT);
    }
}

// ─── [5] 트랜잭션: UPDATE → 감사 → COMMIT ───
$db->begin();
try {
    $snap = Master::update($kind, $id, $current, $changes, $active);

    if ($snap['before'] === [] && $snap['after'] === []) {
        // 아무것도 바뀌지 않았다 → 롤백하고 400. 성공으로 답하면 사람이 "바꿨다" 고 믿는다.
        $db->rollback();
        $reject('no_change', 400, Http::MSG_BAD_REQUEST);
    }

    // 중단·재개는 따로 갈라 기록한다 — 로그를 읽는 사람에게 성격이 다른 사건이다.
    $action = 'purchase.master.update';
    if (array_key_exists('is_active', $snap['after'])) {
        $action = $snap['after']['is_active'] === false
            ? 'purchase.master.deactivate'
            : 'purchase.master.update';
    }

    Audit::allow($action, $auditTarget + [
        'detail' => [
            'kind'      => $kind,
            'target_id' => $id,
            'before'    => $snap['before'],
            'after'     => $snap['after'],
            'branch_id' => $current['branch_id'] ?? null,
        ],
    ]);
    $db->commit();
} catch (PDOException $e) {
    $db->rollback();
    if ($e->getCode() === '23505') {
        $reject('conflict', 409, Http::MSG_CONFLICT);
    }
    if ($e->getCode() === '23503') {
        $reject('bad_reference', 400, Http::MSG_BAD_REQUEST);
    }
    throw $e;
} catch (Throwable $e) {
    $db->rollback();
    throw $e;
}

Http::json([
    'kind'   => $kind,
    'id'     => $id,
    'before' => $snap['before'],
    'after'  => $snap['after'],
]);
