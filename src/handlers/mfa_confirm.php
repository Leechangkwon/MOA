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
 * 남는 것은 **사유 코드**뿐이다
 * (ARCH 7.4 `auth.mfa_enroll|deny|bad_code`·`already_enabled`·`code_reused`).
 * 응답은 어느 쪽이든 400 동일 문구(`MSG_BAD_REQUEST`)다.
 *
 * 13단계 (SECURITY R-48 · R-45, PRD T-30 · T-31)
 *   · 코드 오류·코드 재사용은 `Auth::countFailureAndRevoke` 로 **실패 1회**를 센다.
 *     5회면 계정이 잠기고 그 계정의 세션이 전부 끊긴다 — 세션만 훔친 사람이 6자리를
 *     무제한으로 시험하지 못한다. (`already_enabled` 는 자격 증명 실패가 아니라 세지 않는다.)
 *   · 맞은 코드라도 `users.mfa_last_step` 이하의 스텝이면 이미 쓴 코드라 거부한다(RFC 6238 §5.2).
 *     켜는 데 성공하면 **그 스텝**을 기준선으로 놓는다 — 같은 코드로 곧바로 로그인할 수 없다.
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
    SELECT mfa_enabled, mfa_secret, mfa_setup_required, mfa_last_step FROM users WHERE id = :id
    SQL;

$row = $db->run($sqlState, [':id' => $userId])->fetch();
if (!is_array($row)) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}
if ($row['mfa_enabled'] === true) {
    Audit::deny('auth.mfa_enroll', 'already_enabled', $auditTarget);
    Http::error(400, Http::MSG_BAD_REQUEST);
}
/**
 * 실패 한 건 — 기록하고(사유는 갈라 둔다), 실패로 세고, 같은 문구로 끝낸다 (13단계 R-48).
 * 기록을 먼저 하는 이유: 잠금으로 세션이 끊기면 감사 로그의 actor 를 읽을 수 없다.
 */
$reject = static function (string $reason) use ($auditTarget, $userId): void {
    Audit::deny('auth.mfa_enroll', $reason, $auditTarget);
    Auth::countFailureAndRevoke($userId);
    Http::error(400, Http::MSG_BAD_REQUEST);
};

if (!is_string($row['mfa_secret']) || $row['mfa_secret'] === '') {
    // enroll 을 건너뛰고 confirm 만 보낸 경우 — 확인할 비밀이 없다.
    // "코드가 틀렸다" 와 같은 자리이므로 같은 사유로 남긴다 (응답도 같은 400).
    $reject('bad_code');
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
unset($otp, $body);

$sqlEnable = <<<'SQL'
    UPDATE users
       SET mfa_enabled        = true,
           -- 11단계 (PRD F-16 ②) — 강제 절차의 마지막 걸음. 켜지는 **그 문장**에서 함께 내린다.
           -- 두 문장으로 나누면 사이에서 실패했을 때 "켰는데 계속 막히는" 상태가 남는다.
           mfa_setup_required = false,
           -- 13단계 (SECURITY R-45): 확정에 쓴 코드의 스텝이 곧 재사용 기준선이다.
           -- 새 비밀을 저장하는 enroll 이 아니라 **여기**에서 놓는다 — enroll 은 실패할 수 있고,
           -- 실제로 쓰인 코드가 무엇인지는 확정 시점에만 알 수 있다.
           mfa_last_step      = GREATEST(COALESCE(mfa_last_step, 0), CAST(:step AS bigint)),
           updated_at         = now()
     WHERE id = :id
       AND mfa_secret IS NOT NULL
    SQL;

/*
 * 지금 이 등록이 **최초 로그인 강제 절차** 안에서 일어난 것인가 (11단계, ARCH 7.5 `forced`).
 * DB 의 현재 플래그이고 요청이 정하지 않는다 — 감사 detail 로만 쓴다.
 *
 * F-52 (2026-09-16, PRD F-52 ④) — 플래그만으로는 모자란다. 플래그는 해제·초기화가 역할과
 * 무관하게 켜 두는 값이라, **권장 카드를 보고 스스로 켠** 비-Admin 도 플래그가 true 다.
 * 그것은 강제가 아니므로 `forced` 로 남기지 않는다 — 필수 역할일 때만 강제다.
 * 역할 판정은 Auth 안에서만 한다(이 파일은 역할 문자열을 비교하지 않는다).
 */
$forced = $row['mfa_setup_required'] === true && Auth::mfaRequired($user);

$db->begin();
try {
    $db->run($sqlEnable, [':id' => $userId, ':step' => (int) $step]);
    Audit::allow('auth.mfa_enroll', $auditTarget + ($forced ? ['detail' => ['forced' => true]] : []));
    $db->commit();
} catch (Throwable $e) {
    $db->rollback();
    throw $e;
}

Http::json(['ok' => true, 'mfa_enabled' => true]);
