<?php
declare(strict_types=1);

/**
 * src/handlers/purchase_structure_link.php — POST /api/purchase/structure/link
 *   (정책: purchase.master.write → buyer·Admin 만, **재인증 대상**)
 *
 * ★ 24단계 — 대기표의 창고를 지점에 **연결**한다 (PRD F-37, ARCH 5.18).
 *   연결하는 이 순간에만 `p_locations` 행이 생긴다 — 그 전까지 창고는 본 표 밖에 있다.
 *
 * 본문 `{links:[{inbox_id,branch_id}], kind?}` — 허용 키 밖은 400(SECURITY R-07),
 * 1~200건, 같은 창고가 두 번 오면 400.
 *
 * 검사 순서
 *   [1] 허용 키 → [2] 값 좁히기(Structure::normalizeLinks · Master::choice) →
 *   [3] **한 트랜잭션**: 행마다 FOR UPDATE → status=unlinked 확인 → 지점 활성 확인 →
 *       p_locations INSERT → 대기표 UPDATE → 감사 **한 줄** → COMMIT
 *
 * 한 건이라도 막히면 **묶음 전체**가 되돌아간다(409 conflict 포함). 반쯤 연결된 상태를
 * 만들지 않는 것이 이 화면의 약속이다(가져오기 확정과 같은 판단, SECURITY R-67).
 *
 * 지점 격리 — 이 라우트는 buyer·Admin 전용이라 좁힐 지점이 없다. 조회마다의 표식은
 *   `src/purchase/Structure.php` 에 있다 (23단계 규칙, SECURITY R-65).
 *
 * @var array<string,mixed> $user
 * @var list<string>        $params 경로 변수 없음
 */

require_once __DIR__ . '/../purchase/Master.php';
require_once __DIR__ . '/../purchase/Importer.php';
require_once __DIR__ . '/../purchase/Structure.php';

const TBOX_STRUCTURE_LINK_KEYS = ['links', 'kind'];

/** 거부 기록 후 끝낸다 — 사유는 감사 로그에, 사람에게는 무엇을 하면 되는지 한 문장. */
$reject = static function (string $reason, int $status, string $message): void {
    Audit::deny('purchase.structure.link', $reason, [
        'target_type' => 'purchase_master',
        'target_name' => 'locations',
    ]);
    Http::error($status, $message);
};

$body = Http::jsonBody();

// ─── [1] 허용 키 (R-07) ───
if ($body === [] || array_diff(array_keys($body), TBOX_STRUCTURE_LINK_KEYS) !== []) {
    $reject('bad_request', 400, Http::MSG_BAD_REQUEST);
}

// ─── [2] 값 좁히기 ───
try {
    $links = Structure::normalizeLinks($body['links'] ?? null);
    $kind  = array_key_exists('kind', $body)
        ? Master::choice($body['kind'], Master::LOCATION_KINDS)
        : null;
} catch (RuntimeException $e) {
    $reject('bad_request', 400, Http::MSG_BAD_REQUEST);
    exit;   // $reject 안에서 끝나지만 정적 분석을 위해 남긴다
}

// ─── [3] 한 트랜잭션 ───
$db = Db::instance();
$db->begin();
try {
    $result = Structure::link($links, $kind, (int) $user['id']);

    Audit::allow('purchase.structure.link', [
        'target_type' => 'purchase_master',
        'target_name' => 'locations',
        'detail'      => [
            'links'        => $result['links'],
            'branch_ids'   => $result['branch_ids'],
            'location_ids' => $result['location_ids'],
            'kind'         => $kind,
        ],
    ]);
    $db->commit();
} catch (RuntimeException $e) {
    $db->rollback();
    $reason = $e->getMessage();
    if ($reason === 'not_unlinked') {
        $reject('not_unlinked', 400, Structure::MSG_NOT_UNLINKED);
    }
    if ($reason === 'bad_reference') {
        $reject('bad_reference', 400, Structure::MSG_BAD_REFERENCE);
    }
    if ($reason === 'conflict') {
        $reject('conflict', 409, Structure::MSG_CONFLICT);
    }
    throw $e;
} catch (Throwable $e) {
    $db->rollback();
    throw $e;   // bootstrap 예외 핸들러 → 500 + {"error":"서버 오류"}
}

Http::json([
    'links'        => $result['links'],
    'branch_ids'   => $result['branch_ids'],
    'location_ids' => $result['location_ids'],
], 201);
