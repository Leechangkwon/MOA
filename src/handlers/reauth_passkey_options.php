<?php
declare(strict_types=1);

/**
 * src/handlers/reauth_passkey_options.php — POST /api/me/reauth/passkey/options (정책: authenticated + CSRF · nginx 속도 제한)
 * T:F-72 라운드 C · 지시서 1.1 「재인증」 · 1.3(재인증 allowCredentials = 지금 로그인한 사람의 살아 있는 열쇠)
 *
 * 관리 기능 재인증(15분 창)을 지문·얼굴로 하기 위한 문제(챌린지)를 낸다 — 이 세션 + 이 사람에 묶이고 2분.
 * allowCredentials 는 **내** 살아 있는 열쇠(해제·90일 제외)뿐이다(로그인한 본인에게만 가는 목록). 열쇠가 없으면 400
 * (화면은 `passkeys_active > 0` 일 때만 단추를 보인다). 본문은 빈 객체 `{}` 하나. 감사 없음(검증 창구가 남긴다).
 *
 * @var array<string,mixed> $user
 * @var list<string>        $params
 */

require_once __DIR__ . '/../Passkey.php';

$body = Http::jsonBody();
if ($body !== []) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}
if (!Passkey::available()) {
    Http::error(503, Http::MSG_PASSKEY_UNAVAILABLE);
}

$db     = Db::instance();
$userId = (int) $user['id'];
$sql = <<<'SQL'
    SELECT encode(credential_id, 'hex') AS cid, transports
      FROM passkeys
     WHERE user_id = :uid AND revoked_at IS NULL
       AND COALESCE(last_used_at, created_at) >= now() - make_interval(days => CAST(:idle AS integer))
     ORDER BY id
    SQL;
$allow = [];
foreach ($db->run($sql, [':uid' => $userId, ':idle' => Passkey::IDLE_DAYS])->fetchAll() as $r) {
    $cid = hex2bin((string) $r['cid']);
    if (!is_string($cid)) {
        continue;
    }
    $tr = [];
    if (is_string($r['transports']) && preg_match('/^\{([a-z,-]*)\}$/', $r['transports'], $m) === 1 && $m[1] !== '') {
        $tr = array_values(array_intersect(explode(',', $m[1]), Passkey::TRANSPORTS));
    }
    $allow[] = ['type' => 'public-key', 'id' => WebPush::b64uEncode($cid), 'transports' => $tr];
}
if ($allow === []) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}

$cfg = Passkey::config();
Passkey::purge($db);
$challenge = Passkey::issueChallenge($db, 'reauth', $userId, (string) $user['session_id'], null, Passkey::CEREMONY_TTL);

Http::json([
    'publicKey' => [
        'challenge'        => WebPush::b64uEncode($challenge),
        'rpId'             => $cfg['rp_id'],
        'timeout'          => Passkey::CEREMONY_TIMEOUT_MS,
        'userVerification' => 'required',
        'allowCredentials' => $allow,
    ],
]);
