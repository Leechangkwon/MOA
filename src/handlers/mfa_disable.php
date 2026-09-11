<?php
declare(strict_types=1);

/**
 * src/handlers/mfa_disable.php — POST /api/me/mfa/disable (정책: authenticated)
 *
 * 요구사항: SECURITY R-05, ARCH 7.4 `auth.mfa_disable|allow`
 *
 * 끄는 데에는 **두 가지를 함께** 요구한다: 현재 비밀번호와 지금의 2단계 인증 코드.
 * 세션 하나만으로 끌 수 있으면, 세션을 훔친 공격자가 가장 먼저 하는 일이 2단계 인증 끄기가 된다 —
 * 그 순간 2단계 인증이 있으나 마나가 된다.
 *
 * 받는 값: `{ "current_password": "…", "otp": "123456" }`
 *
 * 성공하면 `mfa_enabled = false` 이고 `mfa_secret` 은 **NULL 로 지운다** — 쓰지 않는 비밀을
 * 남겨 둘 이유가 없다(다시 켤 때는 새 비밀을 만든다).
 *
 * 비밀번호·코드·비밀은 응답·감사 로그·app.log 어디에도 남지 않는다 —
 * 남는 것은 **사유 코드**뿐이다 (ARCH 7.4 `auth.mfa_disable|deny|bad_credentials`·`bad_code`).
 * 2단계 인증을 끄려는 시도는 공격자가 가장 먼저 하는 일이므로, 실패도 반드시 기록한다.
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자 (대상은 언제나 본인)
 * @var list<string>        $params 경로 변수 없음
 */

$allowedFields = ['current_password', 'otp'];

$body = Http::jsonBody();
if ($body === [] || array_diff(array_keys($body), $allowedFields) !== []) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}

$password = $body['current_password'] ?? null;
$otp      = $body['otp'] ?? null;

if (!is_string($password) || $password === '' || strlen($password) > Auth::MAX_PASSWORD_BYTES
    || !is_string($otp) || preg_match(Totp::CODE_RE, trim($otp)) !== 1
) {
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
    SELECT password_hash, mfa_enabled, mfa_secret FROM users WHERE id = :id
    SQL;

$row = $db->run($sqlState, [':id' => $userId])->fetch();
if (!is_array($row) || $row['mfa_enabled'] !== true || !is_string($row['mfa_secret'])) {
    // 켜져 있지도 않은 것을 끄려는 요청 — 끊긴 상태가 아니므로 기록할 사유 코드가 없다
    // (ARCH 7.4 의 두 사유는 비밀번호·코드 오류뿐이다). 400 동일 문구만 돌려준다.
    Http::error(400, Http::MSG_BAD_REQUEST);
}

if (!password_verify($password, (string) $row['password_hash'])) {
    Audit::deny('auth.mfa_disable', 'bad_credentials', $auditTarget);
    Http::error(400, Http::MSG_LOGIN_FAILED);
}
if (!Totp::verify((string) $row['mfa_secret'], $otp)) {
    Audit::deny('auth.mfa_disable', 'bad_code', $auditTarget);
    Http::error(400, Http::MSG_BAD_REQUEST);
}
unset($password, $otp, $body);

$sqlDisable = <<<'SQL'
    UPDATE users
       SET mfa_enabled        = false,
           mfa_secret         = NULL,
           -- 11단계 (PRD F-16 ③) — 2단계 인증은 **모든 역할에 필수**다.
           -- 끄는 것 자체는 허용하되(비밀을 갈아 끼우는 유일한 길이다),
           -- 다음 요청부터 관문이 다시 등록을 요구한다. 같은 UPDATE 문장에서 켠다.
           mfa_setup_required = true,
           updated_at         = now()
     WHERE id = :id
    SQL;

$db->begin();
try {
    $db->run($sqlDisable, [':id' => $userId]);
    Audit::allow('auth.mfa_disable', $auditTarget);
    $db->commit();
} catch (Throwable $e) {
    $db->rollback();
    throw $e;
}

Http::json(['ok' => true, 'mfa_enabled' => false]);
