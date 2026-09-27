<?php
declare(strict_types=1);

/**
 * src/handlers/me_passkeys_options.php — POST /api/me/passkeys/options (정책: authenticated + CSRF · nginx 속도 제한)
 * T:F-72 지시서 1.1 「등록」 2 · 1.3 · 결정 PK-D3 「등록할 때마다 비밀번호 + 2단계 코드(켠 사람만)」
 *
 * 본문(허용 키만): `{name, password, otp?}` — 이름은 1~40자(계정 이름과 같은 규칙) · 코드는 2단계를 켠 사람만(끈 사람이 보내면 400).
 * **비밀번호·코드를 먼저 확인**하고 통과해야만 등록용 챌린지를 준다. 틀리면 로그인과 같은 카운터로 실패 1회(5회면 잠금 + 세션 전부
 * 끊김 — R-48 · `countFailureAndRevoke`). 코드는 한 번만(R-45 — `verifyOtpOnce` 가 기준선을 곧바로 올린다).
 * 강제 절차(F-16) 중에는 라우터 관문 [3-1] 이 이 창구를 막는다(허용 목록 불변).
 *
 * 응답 `{publicKey:{…}, name}` — 브라우저 `navigator.credentials.create` 에 그대로 넣을 값(바이트는 base64url 글자):
 *   rp {id(APP_URL 호스트), name "Qaredi"} · user {id(user_handle), name(아이디), displayName(표시 이름 또는 아이디)} ·
 *   pubKeyCredParams [ES256] · timeout 120000 · attestation "none" ·
 *   authenticatorSelection {authenticatorAttachment "platform", residentKey "required", requireResidentKey true, userVerification "required"} ·
 *   excludeCredentials = 내 살아 있는 열쇠(같은 휴대폰 두 번 등록 막기 — 90일 꺼진 열쇠는 빼서 그 휴대폰을 다시 등록할 수 있게)
 * 챌린지는 이 세션 + 이 사람에 묶이고 2분. 감사: 거부만(`auth.passkey_register deny <사유>`) — 성공은 등록 창구가 남긴다.
 *
 * @var array<string,mixed> $user
 * @var list<string>        $params
 */

require_once __DIR__ . '/../Passkey.php';

$body   = Http::jsonBody();
$db     = Db::instance();
$userId = (int) $user['id'];

$auditTarget = [
    'target_type' => 'user',
    'target_id'   => $userId,
    'target_name' => (string) $user['username'],
];

/** 400 거부 한 건 — 기록 먼저(잠금으로 세션이 끊기면 actor 를 못 읽는다) · 필요하면 실패 1회 */
$reject = static function (string $reason, bool $count = false, ?string $message = null) use ($auditTarget, $userId): void {
    Audit::deny('auth.passkey_register', $reason, $auditTarget);
    if ($count) {
        Auth::countFailureAndRevoke($userId);
    }
    Http::error(400, $message ?? Http::reasonMessage($reason));
};

if (!Passkey::available()) {
    Http::error(503, Http::MSG_PASSKEY_UNAVAILABLE);
}

// ─── 모양 ──────────────────────────────────────────────────────
if ($body === [] || array_diff(array_keys($body), ['name', 'password', 'otp']) !== []) {
    $reject('bad_request');
}
$name = Passkey::cleanName($body['name'] ?? null);
$password = $body['password'] ?? null;
$otpRaw   = $body['otp'] ?? null;
$mfaOn    = ($user['mfa_enabled'] ?? false) === true;
if ($name === null
    || !is_string($password) || $password === '' || strlen($password) > Auth::MAX_PASSWORD_BYTES
    || ($mfaOn && (!is_string($otpRaw) || preg_match(Totp::CODE_RE, trim($otpRaw)) !== 1))
    || (!$mfaOn && $otpRaw !== null && $otpRaw !== '')
) {
    $reject('bad_request');
}

// ─── 비밀번호 — 요청마다(세션에 창을 남기지 않는다 · F-65·F-66 과 같은 helper) ──
if (!Auth::verifyCurrentPassword($user, $password)) {
    $reject('bad_credentials', true);
}
// ─── 2단계 코드 — 켠 사람만 · 한 번만 ────────────────────────────
if ($mfaOn) {
    $otpResult = Auth::verifyOtpOnce($user, trim((string) $otpRaw));
    if ($otpResult === 'mfa_not_enabled') {
        $reject('bad_request');   // 이 요청 사이에 다른 창에서 2단계를 껐다 — 추측이 아니다(세지 않는다)
    }
    if ($otpResult !== 'ok') {
        $reject($otpResult, true);   // bad_code · code_reused — 둘 다 센다(R-48)
    }
}
unset($password, $otpRaw, $body);

// ─── 5개 상한(살아 있고 90일 안의 열쇠) — 등록 창구가 사용자 행 잠금 뒤 한 번 더 본다 ──
$sqlLive = <<<'SQL'
    SELECT encode(credential_id, 'hex') AS cid, transports,
           (COALESCE(last_used_at, created_at) < now() - make_interval(days => CAST(:idle AS integer))) AS is_stale
      FROM passkeys
     WHERE user_id = :uid AND revoked_at IS NULL
     ORDER BY id
    SQL;
$exclude = [];
foreach ($db->run($sqlLive, [':uid' => $userId, ':idle' => Passkey::IDLE_DAYS])->fetchAll() as $r) {
    if ($r['is_stale'] === true) {
        continue;
    }
    $cid = hex2bin((string) $r['cid']);
    if (!is_string($cid)) {
        continue;
    }
    $tr = [];
    if (is_string($r['transports']) && preg_match('/^\{([a-z,-]*)\}$/', $r['transports'], $m) === 1 && $m[1] !== '') {
        $tr = array_values(array_intersect(explode(',', $m[1]), Passkey::TRANSPORTS));
    }
    $exclude[] = ['type' => 'public-key', 'id' => WebPush::b64uEncode($cid), 'transports' => $tr];
}
if (count($exclude) >= Passkey::MAX_PER_USER) {
    $reject('limit_exceeded', false, Http::MSG_PASSKEY_LIMIT);
}

// ─── 챌린지(이 세션 + 이 사람 · 2분) ─────────────────────────────
$cfg = Passkey::config();
Passkey::purge($db);
$challenge = Passkey::issueChallenge($db, 'register', $userId, (string) $user['session_id'], null, Passkey::CEREMONY_TTL);
$handle    = Passkey::userHandleFor($db, $userId);
$display   = isset($user['display_name']) && is_string($user['display_name']) && $user['display_name'] !== ''
    ? (string) $user['display_name'] : (string) $user['username'];

Http::json([
    'name'      => $name,
    'publicKey' => [
        'challenge'              => WebPush::b64uEncode($challenge),
        'rp'                     => ['id' => $cfg['rp_id'], 'name' => 'Qaredi'],
        'user'                   => [
            'id'          => WebPush::b64uEncode($handle),
            'name'        => (string) $user['username'],
            'displayName' => $display,
        ],
        'pubKeyCredParams'       => [['type' => 'public-key', 'alg' => Passkey::ALG_ES256]],
        'timeout'                => Passkey::CEREMONY_TIMEOUT_MS,
        'attestation'            => 'none',
        'authenticatorSelection' => [
            'authenticatorAttachment' => 'platform',
            'residentKey'             => 'required',
            'requireResidentKey'      => true,
            'userVerification'        => 'required',
        ],
        'excludeCredentials'     => $exclude,
    ],
]);
