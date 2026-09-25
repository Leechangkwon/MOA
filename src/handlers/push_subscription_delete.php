<?php
declare(strict_types=1);

/**
 * src/handlers/push_subscription_delete.php — DELETE /api/admin/push/subscriptions/{num}
 *   (정책: role:Admin + 재인증 + CSRF, PRD F-70 · 지시서 D 표 · R-99 ⑥ 「기기별 해지」)
 *
 * 하는 일: **내** 살아 있는 받기 하나를 해지한다(`revoked_reason='user'`). 행은 지우지 않는다 — 앱 계정에는
 * 이 표의 DELETE 자격이 없다(db/grants.sql). 해지한 받기로는 다음 실행부터 배치가 보내지 않는다.
 * 휴대폰을 잃었을 때 다른 기기(PC)에서 이 목록으로 그 기기를 끊는다.
 *
 * ★ 대상은 언제나 세션의 본인(`WHERE user_id = :uid`). 남의 받기 번호·없는 번호·이미 해지한 번호는 **같은 404
 *   문구**다(IDOR — 그 번호가 있는지도 새지 않는다) + 감사 `alert.push_unsubscribe deny not_found`.
 * ★ 감사 detail 에는 호스트·기기 요약만 — 주소(endpoint)·키는 읽지도 않는다.
 * ★ 외부 요청 0.
 *
 * @var array<string,mixed> $user
 * @var list<string>        $params $params[0] = alert_push_subscriptions.id
 */

$idRaw = $params[0] ?? '';
if (!is_string($idRaw) || preg_match('/^[0-9]{1,9}$/', $idRaw) !== 1) {
    Http::error(404, Http::MSG_NOT_FOUND);
}
$subId  = (int) $idRaw;
$userId = (int) $user['id'];
$db     = Db::instance();

require_once __DIR__ . '/../WebPush.php';
// 서버 쪽 반영 전(표 없음)이면 500 이 아니라 503
if (!WebPush::tablesReady($db)) {
    Http::error(503, WebPush::NOT_READY_MESSAGE);
}

$db->begin();
try {
    // 조건에 revoked_at IS NULL 을 넣어 같은 순간의 두 요청 중 하나만 해지 행을 쓴다
    $row = $db->run(
        "UPDATE alert_push_subscriptions
            SET revoked_at = now(), revoked_reason = 'user'
          WHERE id = :id AND user_id = :uid AND revoked_at IS NULL
          RETURNING host, device_hint",
        [':id' => $subId, ':uid' => $userId]
    )->fetch();

    if (!is_array($row)) {
        $db->rollback();
        Audit::deny('alert.push_unsubscribe', 'not_found', ['target_type' => 'push_subscription']);
        Http::error(404, Http::MSG_NOT_FOUND);
    }

    Audit::allow('alert.push_unsubscribe', [
        'target_type' => 'push_subscription',
        'target_id'   => $subId,
        'target_name' => (string) $row['host'],
        'detail'      => [
            'host'           => (string) $row['host'],
            'device_hint'    => (string) $row['device_hint'],
            'revoked_reason' => 'user',
        ],
    ]);
    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollback();
    }
    throw $e;
}

Http::json(['ok' => true]);
