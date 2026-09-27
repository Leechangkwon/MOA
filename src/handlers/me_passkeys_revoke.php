<?php
declare(strict_types=1);

/**
 * src/handlers/me_passkeys_revoke.php — DELETE /api/me/passkeys/{num} (정책: authenticated + CSRF) · T:F-72 지시서 1.1 「관리」 · 1.8
 *
 * **내 것만** 해제한다 — 조건 `user_id = 세션의 본인` 이 UPDATE 문 안에 있다. 남의 번호·없는 번호·이미 해제한 번호는 **같은 404 본문**
 * (「찾을 수 없습니다」 — 남의 열쇠가 있는지 알 수 없다) + 감사 `auth.passkey_revoke deny not_found`.
 * 행을 지우지 않는다(revoked_at · revoked_by · revoke_reason `self`). 90일 꺼진 열쇠도 해제할 수 있다(목록에서 치우기).
 * 응답 `{ok, remaining}` — 남은 살아 있는 열쇠 수(화면이 0 이면 「이 기기 표시」를 끈다 — 계정 정보가 아니라 내 수).
 *
 * @var array<string,mixed> $user
 * @var list<string>        $params  [0] = 열쇠 행 번호
 */

require_once __DIR__ . '/../Passkey.php';

$userId = (int) $user['id'];
$num    = (int) ($params[0] ?? 0);
$target = [
    'target_type' => 'user',
    'target_id'   => $userId,
    'target_name' => (string) $user['username'],
];

if (!Passkey::available()) {
    Http::error(503, Http::MSG_PASSKEY_UNAVAILABLE);
}

$sql = <<<'SQL'
    UPDATE passkeys
       SET revoked_at = now(), revoked_by = :by, revoke_reason = 'self'
     WHERE id = :id AND user_id = :uid AND revoked_at IS NULL
    RETURNING id, name
    SQL;
$sqlLeft = <<<'SQL'
    SELECT count(*) AS n
      FROM passkeys
     WHERE user_id = :uid AND revoked_at IS NULL
       AND COALESCE(last_used_at, created_at) >= now() - make_interval(days => CAST(:idle AS integer))
    SQL;

$db = Db::instance();
$db->begin();
try {
    $row = $num > 0 ? $db->run($sql, [':by' => $userId, ':id' => $num, ':uid' => $userId])->fetch() : false;
    if (is_array($row)) {
        Audit::allow('auth.passkey_revoke', $target + ['detail' => [
            'credential_no'    => (int) $row['id'],
            'credential_label' => (string) $row['name'],
        ]], 'self');
    }
    $db->commit();
} catch (Throwable $e) {
    $db->rollback();
    throw $e;
}

if (!is_array($row)) {
    Audit::deny('auth.passkey_revoke', 'not_found', $target);
    Http::error(404, Http::MSG_NOT_FOUND);
}

$left = $db->run($sqlLeft, [':uid' => $userId, ':idle' => Passkey::IDLE_DAYS])->fetch();
Http::json(['ok' => true, 'remaining' => is_array($left) ? (int) $left['n'] : 0]);
