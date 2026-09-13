<?php
declare(strict_types=1);

/**
 * src/handlers/purchase_structure_exclude.php — POST /api/purchase/structure/exclude
 *   (정책: purchase.master.write → buyer·Admin 만, **재인증 대상**)
 *
 * ★ 24단계 — 지점이 없는 창고를 **제외**하거나 되살린다 (PRD F-37, ARCH 5.18).
 *   제외된 창고는 연결하지 않아도 나머지 진행을 막지 않는다(사용자 결정 2026-09-13 ④).
 *
 * 본문 `{inbox_ids:[…], excluded:true|false}` — 허용 키 밖은 400, 1~200건, 중복 금지.
 * `excluded:true` 는 unlinked → excluded, `false` 는 excluded → unlinked 이고
 * 그 밖의 상태는 400 이다(`not_unlinked` · `not_excluded`).
 *
 * **행을 지우지 않는다** — 상태값만 바뀐다. 앱 계정에는 `p_location_inbox` 의 DELETE 권한이
 * 아예 없다 (db/grants.sql, 기초정보와 같은 규칙).
 *
 * 지점 격리 — buyer·Admin 전용 라우트이고 `p_location_inbox` 에는 지점 축이 없다.
 *   조회마다의 표식은 `src/purchase/Structure.php` 에 있다 (SECURITY R-65).
 *
 * @var array<string,mixed> $user
 * @var list<string>        $params 경로 변수 없음
 */

require_once __DIR__ . '/../purchase/Master.php';
require_once __DIR__ . '/../purchase/Importer.php';
require_once __DIR__ . '/../purchase/Structure.php';

const TBOX_STRUCTURE_EXCLUDE_KEYS = ['inbox_ids', 'excluded'];

$reject = static function (string $reason, int $status, string $message): void {
    Audit::deny('purchase.structure.exclude', $reason, [
        'target_type' => 'purchase_master',
        'target_name' => 'locations',
    ]);
    Http::error($status, $message);
};

$body = Http::jsonBody();

if ($body === [] || array_diff(array_keys($body), TBOX_STRUCTURE_EXCLUDE_KEYS) !== []) {
    $reject('bad_request', 400, Http::MSG_BAD_REQUEST);
}

try {
    $ids      = Structure::normalizeIds($body['inbox_ids'] ?? null);
    $excluded = Master::flag($body['excluded'] ?? null);
} catch (RuntimeException $e) {
    $reject('bad_request', 400, Http::MSG_BAD_REQUEST);
    exit;   // $reject 안에서 끝나지만 정적 분석을 위해 남긴다
}

$db = Db::instance();
$db->begin();
try {
    $changed = Structure::exclude($ids, $excluded);

    Audit::allow('purchase.structure.exclude', [
        'target_type' => 'purchase_master',
        'target_name' => 'locations',
        'detail'      => [
            'inbox_ids' => $ids,
            'excluded'  => $excluded,
        ],
    ]);
    $db->commit();
} catch (RuntimeException $e) {
    $db->rollback();
    $reason = $e->getMessage();
    if ($reason === 'not_unlinked') {
        $reject('not_unlinked', 400, Structure::MSG_NOT_UNLINKED);
    }
    if ($reason === 'not_excluded') {
        $reject('not_excluded', 400, Structure::MSG_NOT_EXCLUDED);
    }
    throw $e;
} catch (Throwable $e) {
    $db->rollback();
    throw $e;
}

Http::json(['changed' => $changed, 'excluded' => $excluded], 200);
