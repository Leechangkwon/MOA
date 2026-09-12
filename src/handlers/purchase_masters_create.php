<?php
declare(strict_types=1);

/**
 * src/handlers/purchase_masters_create.php
 *   POST /api/purchase/{brands|branches|locations|vendors|categories|par-levels}
 *   (정책: purchase.master.write → buyer·Admin 만, **재인증 대상**)
 *
 * ★ 19단계 기초정보 만들기 (PRD F-28, ARCH 5.18).
 *
 * lint:no-branch-scope 쓰기 라우트다 — 정책 `purchase.master.write` 는 buyer·Admin 에게만
 *   열려 있고(Authz::purchaseAuthorize), 그 둘의 지점 범위는 언제나 전 지점(`1=1`)이다.
 *   즉 여기서 좁힐 지점이 존재하지 않는다. 이 파일이 `FROM p_` 를 쓰는 곳은 참조 존재 검사
 *   (브랜드·지점·장소·품목이 실제로 있고 사용 중인가)뿐이고, 그 결과는 응답에 나가지 않는다
 *   (있으면 진행, 없으면 400 동일 문구). 모듈 역할이 넓어지는 날 이 표식을 지우고
 *   `Authz::purchaseScopeWhere` 를 붙이는 것이 검토 지점이다.
 *
 * 검사 순서
 *   [1] 종류 확인(라우트 표에서 온 고정 값) → [2] 허용 키(SECURITY R-07) →
 *   [3] 값 좁히기(Master::normalize) → [4] 참조 존재 검사 → [5] 트랜잭션(INSERT + 감사) → 201
 *
 * 실패 응답은 400 **동일 문구** 하나이고 사유는 감사 로그에만 남는다 (PRD S-09-5).
 * 중복 이름은 409 다 — 사람이 "이미 있다" 를 알아야 다시 만들지 않는다(폴더와 같은 판단).
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자 (created_by 는 이 값만 쓴다)
 * @var list<string>        $params 경로 변수 없음
 */

require_once __DIR__ . '/../purchase/Master.php';

const TBOX_PURCHASE_CREATE_KIND = [
    'POST /api/purchase/brands'     => 'brands',
    'POST /api/purchase/branches'   => 'branches',
    'POST /api/purchase/locations'  => 'locations',
    'POST /api/purchase/vendors'    => 'vendors',
    'POST /api/purchase/categories' => 'categories',
    'POST /api/purchase/par-levels' => 'par-levels',
];

$kind = TBOX_PURCHASE_CREATE_KIND[TBOX_ROUTE] ?? null;
if ($kind === null) {
    Http::error(404, Http::MSG_NOT_FOUND);
}

$body = Http::jsonBody();

/** 거부 기록 후 끝낸다. 사유는 로그에만, 사용자에게는 동일 문구만. */
$reject = static function (string $reason, int $status, string $message) use ($kind): void {
    Audit::deny('purchase.master.create', $reason, [
        'target_type' => 'purchase_master',
        'target_name' => $kind,
        'detail'      => ['kind' => $kind],
    ]);
    Http::error($status, $message);
};

// ─── [2] 허용 키 — 목록 밖의 키가 하나라도 있으면 요청 전체를 거부 (R-07) ───
if ($body === [] || array_diff(array_keys($body), Master::createKeys($kind)) !== []) {
    $reject('bad_request', 400, Http::MSG_BAD_REQUEST);
}

// ─── [3] 값 좁히기 — 형식이 틀리면 예외(사유 코드) ───
try {
    $values = Master::normalize($kind, $body, false);
} catch (RuntimeException $e) {
    $reject($e->getMessage(), 400, Http::MSG_BAD_REQUEST);
    exit;   // $reject 안에서 끝나지만 정적 분석을 위해 남긴다
}

$db = Db::instance();

/**
 * ─── [4] 참조 존재 검사 ───
 * 없는 브랜드·지점·장소·품목을 가리키는 기초정보를 만들지 않는다. FK 가 마지막 그물이지만,
 * 앱이 먼저 보아야 사유(`bad_reference`)가 감사 로그에 남는다.
 * **활성 행만** 있는 것으로 본다 — 중단된 지점 아래에 새 장소를 만들 이유가 없다(팀과 같은 규칙).
 */
$sqlRef = [
    'brand'    => 'SELECT 1 AS ok FROM p_brands    WHERE id = CAST(:id AS bigint) AND is_active',
    'branch'   => 'SELECT 1 AS ok FROM p_branches  WHERE id = CAST(:id AS bigint) AND is_active',
    'location' => 'SELECT 1 AS ok FROM p_locations WHERE id = CAST(:id AS bigint) AND is_active',
    'item'     => 'SELECT 1 AS ok FROM p_items     WHERE id = CAST(:id AS bigint) AND is_active',
    'category' => 'SELECT 1 AS ok FROM p_categories WHERE id = CAST(:id AS bigint) AND is_active',
];

$mustExist = static function (string $what, ?int $id) use ($db, $sqlRef, $reject): void {
    if ($id === null) {
        return;
    }
    if (!is_array($db->run($sqlRef[$what], [':id' => $id])->fetch())) {
        $reject('bad_reference', 400, Http::MSG_BAD_REQUEST);
    }
};

switch ($kind) {
    case 'branches':
        $mustExist('brand', $values['brand_id'] ?? null);
        $mustExist('location', $values['warehouse_location_id'] ?? null);
        $mustExist('location', $values['central_location_id'] ?? null);
        break;
    case 'locations':
        $mustExist('branch', $values['branch_id'] ?? null);
        break;
    case 'categories':
        $parent = $values['parent_id'] ?? null;
        $level  = (int) ($values['level'] ?? 0);
        // 1단계는 부모가 없고 2·3단계는 있어야 한다 (DB CHECK 와 같은 규칙, 앱이 먼저 본다)
        if (($level === 1 && $parent !== null) || ($level > 1 && $parent === null)) {
            $reject('bad_request', 400, Http::MSG_BAD_REQUEST);
        }
        $mustExist('category', $parent);
        break;
    case 'par-levels':
        $mustExist('location', $values['location_id'] ?? null);
        $mustExist('item', $values['item_id'] ?? null);
        break;
    default:
        break;   // brands · vendors 는 참조가 없다
}

// ─── [5] 트랜잭션: INSERT → 감사 → COMMIT (기록이 실패하면 행도 남지 않는다, PRD S-07) ───
$db->begin();
try {
    $newId = Master::insert($kind, $values, (int) $user['id']);

    Audit::allow('purchase.master.create', [
        'target_type' => 'purchase_master',
        'target_id'   => $newId,
        // 사람이 로그만 보고 무엇이 생겼는지 알 수 있게 종류와 이름을 함께 남긴다.
        'target_name' => $kind . ':' . (string) ($values['name'] ?? $newId),
        'detail'      => [
            'kind'      => $kind,
            'target_id' => $newId,
            'after'     => $values,
            // 지점이 붙는 종류면 어느 지점의 기초정보인지 (ARCH 7.5 등록부 branch_id)
            'branch_id' => $values['branch_id'] ?? null,
        ],
    ]);
    $db->commit();
} catch (PDOException $e) {
    $db->rollback();
    // 23505 = unique_violation — 같은 이름·코드가 이미 있다
    if ($e->getCode() === '23505') {
        $reject('conflict', 409, Http::MSG_CONFLICT);
    }
    // 23503 = foreign_key_violation — [4] 와 INSERT 사이에 참조가 사라진 경우
    if ($e->getCode() === '23503') {
        $reject('bad_reference', 400, Http::MSG_BAD_REQUEST);
    }
    throw $e;
} catch (Throwable $e) {
    $db->rollback();
    throw $e;   // bootstrap 예외 핸들러 → 500 + {"error":"서버 오류"}
}

Http::json(['kind' => $kind, 'id' => $newId] + $values, 201);
