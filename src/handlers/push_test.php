<?php
declare(strict_types=1);

/**
 * src/handlers/push_test.php — POST /api/admin/push/test (정책: role:Admin + 재인증 + CSRF, PRD F-70 · 지시서 D 표)
 *
 * 하는 일: 「시험 알림」 대기표(`alert_push_outbox`)에 **한 행만** 넣는다. 보내는 것은 1분 안에 도는 배치
 * scripts/alert_push.php 다 — ★ 웹 요청 처리 중에는 밖으로 한 번도 나가지 않는다(원칙 1, 금지 패턴
 * 「시험 알림도 대기표만」). 배치는 그 사람의 살아 있는 받기 전부에 kind `test` 알림을 보내고 `sent_at` 을 적는다.
 *
 * 거부
 *   400 no_subscription — 살아 있는 받기가 0 (보낼 곳이 없다)
 *   429 rate_limited    — 사람당 10분에 1회. 응답에 `retry_after`(초) · `Retry-After` 머리
 * 사람 행을 잠근 뒤 세므로 겹친 두 요청이 둘 다 들어가지 못한다.
 *
 * @var array<string,mixed> $user
 * @var list<string>        $params
 */

/** 사람당 시험 알림 간격(초) — 10분 */
$interval = 600;

$db     = Db::instance();
$userId = (int) $user['id'];
$target = ['target_type' => 'user', 'target_id' => $userId, 'target_name' => (string) $user['username']];

require_once __DIR__ . '/../WebPush.php';
// 서버 쪽 반영 전(표 없음)이면 500 이 아니라 503
if (!WebPush::tablesReady($db)) {
    Http::error(503, WebPush::NOT_READY_MESSAGE);
}

/** 같은 사람의 겹친 요청을 줄 세우는 잠금 · 대기표 한 행 */
$sqlLockUser = <<<'SQL'
    SELECT id FROM users WHERE id = :uid FOR UPDATE
    SQL;
$sqlQueue = <<<'SQL'
    INSERT INTO alert_push_outbox (user_id) VALUES (:uid)
    SQL;

$db->begin();
try {
    $db->run($sqlLockUser, [':uid' => $userId]);

    $alive = (int) $db->run(
        'SELECT count(*) AS n FROM alert_push_subscriptions WHERE user_id = :uid AND revoked_at IS NULL',
        [':uid' => $userId]
    )->fetch()['n'];
    if ($alive === 0) {
        $db->rollback();
        Audit::deny('alert.push_test', 'no_subscription', $target);
        Http::error(400, '이 계정에 등록된 기기가 없습니다 — 먼저 「알림 받기 켜기」를 누르세요');
    }

    // 지난 요청에서 몇 초 지났나 — DB 시계 하나로 잰다
    $last = $db->run(
        'SELECT CAST(extract(epoch FROM (now() - max(requested_at))) AS bigint) AS ago
           FROM alert_push_outbox
          WHERE user_id = :uid',
        [':uid' => $userId]
    )->fetch();
    $ago = is_array($last) && $last['ago'] !== null ? (int) $last['ago'] : null;
    if ($ago !== null && $ago < $interval) {
        $db->rollback();
        $wait = max(1, $interval - $ago);
        Audit::deny('alert.push_test', 'rate_limited', $target + ['detail' => ['retry_after' => $wait]]);
        if (!headers_sent()) {
            header('Retry-After: ' . $wait);
        }
        Http::json(['error' => '시험 알림은 10분에 한 번 보낼 수 있습니다', 'retry_after' => $wait], 429);
        exit;
    }

    $db->run($sqlQueue, [':uid' => $userId]);
    Audit::allow('alert.push_test', $target + ['detail' => ['subscriptions' => $alive]]);
    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollback();
    }
    throw $e;
}

Http::json(['ok' => true, 'queued' => true, 'subscriptions' => $alive], 202);
