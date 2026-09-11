<?php
declare(strict_types=1);

/**
 * src/handlers/mfa_confirm.php — POST /api/me/mfa/confirm (정책: authenticated)
 *
 * 요구사항: SECURITY R-05, ARCH 7.4 `auth.mfa_enroll|allow`
 *
 * enroll 로 받은 비밀을 인증 앱에 넣고 만든 코드가 **맞을 때만** 2단계 인증이 켜진다.
 * 이 걸음을 통과해야 `users.mfa_enabled = true` 가 되고, 그때부터 로그인에 코드가 필요하다.
 *
 * 받는 값: `{ "otp": "123456" }` (6자리 숫자)
 *
 * 코드(otp)와 비밀(secret)은 응답·감사 로그·app.log 어디에도 남지 않는다 —
 * 남는 것은 **사유 코드**뿐이다 (ARCH 7.4 `auth.mfa_enroll|deny|bad_code`·`already_enabled`).
 * 응답은 어느 쪽이든 400 동일 문구다.
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자 (대상은 언제나 본인)
 * @var list<string>        $params 경로 변수 없음
 */

$allowedFields = ['otp'];

$body = Http::jsonBody();
if ($body === [] || array_diff(array_keys($body), $allowedFields) !== []) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}

$otp = $body['otp'] ?? null;
if (!is_string($otp) || preg_match(Totp::CODE_RE, trim($otp)) !== 1) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}
$otp = trim($otp);

$userId = (int) $user['id'];
$db     = Db::instance();

/** 감사 로그의 "무엇을" 칸 — 대상은 언제나 본인이다 */
$auditTarget = [
    'target_type' => 'user',
    'target_id'   => $userId,
    'target_name' => (string) $user['username'],
];

$sqlState = <<<'SQL'
    SELECT mfa_enabled, mfa_secret FROM users WHERE id = :id
    SQL;

$row = $db->run($sqlState, [':id' => $userId])->fetch();
if (!is_array($row)) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}
if ($row['mfa_enabled'] === true) {
    Audit::deny('auth.mfa_enroll', 'already_enabled', $auditTarget);
    Http::error(400, Http::MSG_BAD_REQUEST);
}
if (!is_string($row['mfa_secret']) || $row['mfa_secret'] === '') {
    // enroll 을 건너뛰고 confirm 만 보낸 경우 — 확인할 비밀이 없다.
    // "코드가 틀렸다" 와 같은 자리이므로 같은 사유로 남긴다 (응답도 같은 400).
    Audit::deny('auth.mfa_enroll', 'bad_code', $auditTarget);
    Http::error(400, Http::MSG_BAD_REQUEST);
}

if (!Totp::verify((string) $row['mfa_secret'], $otp)) {
    Audit::deny('auth.mfa_enroll', 'bad_code', $auditTarget);
    Http::error(400, Http::MSG_BAD_REQUEST);
}
unset($otp, $body);

$sqlEnable = <<<'SQL'
    UPDATE users
       SET mfa_enabled = true,
           updated_at  = now()
     WHERE id = :id
       AND mfa_secret IS NOT NULL
    SQL;

$db->begin();
try {
    $db->run($sqlEnable, [':id' => $userId]);
    Audit::allow('auth.mfa_enroll', $auditTarget);
    $db->commit();
} catch (Throwable $e) {
    $db->rollback();
    throw $e;
}

Http::json(['ok' => true, 'mfa_enabled' => true]);
