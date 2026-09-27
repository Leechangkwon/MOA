<?php
declare(strict_types=1);

/**
 * src/handlers/passkey_login_options.php — POST /api/login/passkey/options (정책: public) · T:F-72 지시서 1.3 · 1.8
 *
 * 지문·얼굴 로그인의 **문제(챌린지)** 를 낸다. 로그인 전 사람이 부르는 창구라 세 겹으로 막는다:
 *   ① JSON 본문만(빈 객체 `{}` 하나 — 크로스사이트 폼으로는 못 보낸다) ② nginx `tbox_login` 속도 제한(30r/m · burst 10)
 *   ③ 살아 있는 로그인 챌린지 상한(5,000 — 넘으면 503 「잠시 뒤 다시 시도하세요」) + 옵션마다 만료 정리.
 *
 * 로그인 전 쿠키(`__Host-tbox_pk`) — 이미 있고 모양이 맞으면 그 값을 다시 쓴다(탭 여럿). 없으면 새로 만든다. 챌린지는 그 쿠키의
 * sha256 에 묶이고(로그인 창구가 같은 쿠키로만 소비), 쿠키는 SameSite=Strict · 10분.
 *
 * 응답: `{publicKey:{challenge, rpId, timeout, userVerification:"required", allowCredentials:[]}}` — **열쇠 목록 0**(로그인 전 사람에게
 * 「이 아이디에 열쇠가 있다」를 알리지 않는다 · 아이디를 받지도 않는다). 감사 없음(잡음 — 지시서 1.8).
 * 설정이 꺼져 있으면(APP_URL 이 https 이름·시험용 localhost 가 아님 · 표 없음) 503.
 *
 * @var array<string,mixed>|null $user   public 이라 null
 * @var list<string>             $params 없음
 */

require_once __DIR__ . '/../Passkey.php';

$body = Http::jsonBody();
if ($body !== []) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}

$cfg = Passkey::config();
if ($cfg === null || !Passkey::tablesReady()) {
    Http::error(503, Http::MSG_PASSKEY_UNAVAILABLE);
}

$db = Db::instance();
Passkey::purge($db);
if (Passkey::loginBusy($db)) {
    Http::error(503, Http::MSG_PASSKEY_BUSY);
}

$pre = Passkey::preCookie() ?? bin2hex(random_bytes(32));
$challenge = Passkey::issueChallenge($db, 'login', null, null, $pre, Passkey::LOGIN_TTL);
Http::setPasskeyPreCookie($pre);
unset($pre);

Http::json([
    'publicKey' => [
        'challenge'        => WebPush::b64uEncode($challenge),
        'rpId'             => $cfg['rp_id'],
        'timeout'          => Passkey::LOGIN_TIMEOUT_MS,
        'userVerification' => 'required',
        'allowCredentials' => [],
    ],
]);
