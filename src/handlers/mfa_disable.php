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
 * 남는 것은 **사유 코드**뿐이다 (ARCH 7.4 `auth.mfa_disable|deny|bad_credentials`·`bad_code`·`code_reused`).
 * 2단계 인증을 끄려는 시도는 공격자가 가장 먼저 하는 일이므로, 실패도 반드시 기록한다.
 *
 * 13단계 (SECURITY R-48 · R-45, PRD T-30 · T-31)
 *   · 실패 응답 문구는 사유가 무엇이든 `MSG_BAD_REQUEST` **하나**다. 전에는 비밀번호 오류만
 *     `MSG_LOGIN_FAILED` 로 갈라져, 응답만 보고 "비밀번호는 맞혔다" 를 알 수 있었다(오라클).
 *   · 비밀번호 오류·코드 오류·코드 재사용은 전부 `Auth::countFailureAndRevoke` 로 **실패 1회**를
 *     센다. 5회면 계정이 잠기고 그 계정의 세션이 전부 끊긴다 — 훔친 세션 쿠키도 함께 죽는다.
 *   · 맞은 코드라도 `users.mfa_last_step` 이하의 스텝이면 **이미 쓴 코드**라 거부한다
 *     (RFC 6238 §5.2). 끄기에 성공하면 비밀을 지우므로 기준선도 NULL 로 되돌린다.
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
    SELECT password_hash, mfa_enabled, mfa_secret, mfa_last_step FROM users WHERE id = :id
    SQL;

$row = $db->run($sqlState, [':id' => $userId])->fetch();
if (!is_array($row) || $row['mfa_enabled'] !== true || !is_string($row['mfa_secret'])) {
    // 켜져 있지도 않은 것을 끄려는 요청 — 끊긴 상태가 아니므로 기록할 사유 코드가 없다
    // (ARCH 7.4 의 두 사유는 비밀번호·코드 오류뿐이다). 400 동일 문구만 돌려준다.
    Http::error(400, Http::MSG_BAD_REQUEST);
}

/**
 * 실패 한 건 — 기록하고(사유는 갈라 둔다), 실패로 세고, 같은 문구로 끝낸다 (13단계 R-48).
 * 기록을 먼저 하는 이유: 잠금으로 세션이 끊기면 감사 로그의 actor 를 읽을 수 없다.
 */
$reject = static function (string $reason) use ($auditTarget, $userId): void {
    Audit::deny('auth.mfa_disable', $reason, $auditTarget);
    Auth::countFailureAndRevoke($userId);
    Http::error(400, Http::MSG_BAD_REQUEST);
};

if (!password_verify($password, (string) $row['password_hash'])) {
    $reject('bad_credentials');
}

$step = Totp::verifyStep((string) $row['mfa_secret'], $otp);
if ($step === null) {
    $reject('bad_code');
}
// 맞았지만 이미 쓴 코드인가 (SECURITY R-45, RFC 6238 §5.2)
$lastStep = $row['mfa_last_step'] === null ? null : (int) $row['mfa_last_step'];
if (Auth::isMfaStepUsed($lastStep, (int) $step)) {
    $reject('code_reused');
}
unset($password, $otp, $body);

$sqlDisable = <<<'SQL'
    UPDATE users
       SET mfa_enabled        = false,
           mfa_secret         = NULL,
           -- 11단계 (PRD F-16 ③ → F-52 2026-09-16) — 2단계 인증이 **필수인 것은 Admin 만**이고
           -- 나머지 역할은 선택(권장)이다. 끄는 것 자체는 어느 역할이든 허용하되(비밀을
           -- 갈아 끼우는 유일한 길이다) 같은 UPDATE 문장에서 이 플래그를 다시 켠다 —
           -- 필수 역할이면 다음 요청부터 관문이 등록을 요구하고(ARCH 5.26), 아니면 관문 없이
           -- 홈의 권장 카드가 다시 나온다. **관문의 판단은 이 플래그가 아니라 mfa_enabled** 다.
           mfa_setup_required = true,
           -- 13단계 (SECURITY R-45): 비밀을 지우므로 "마지막으로 쓴 스텝" 기준선도 함께 치운다.
           -- 다시 켤 때는 새 비밀이라 옛 스텝이 기준선으로 남아 있을 이유가 없다.
           mfa_last_step      = NULL,
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
