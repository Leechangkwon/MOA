<?php
declare(strict_types=1);

/**
 * src/handlers/passkey_login.php — POST /api/login/passkey (정책: public) · T:F-72 지시서 1.4 「로그인」
 *
 * 본문(허용 키만 — 화면 `passkey.js` 가 만든다):
 *   { id, rawId, type:"public-key", clientDataJSON, authenticatorData, signature, userHandle, authenticatorAttachment? }
 *   base64url 은 엄격(WebPush::b64uDecode) · 상한 clientDataJSON ≤ 2KB · authenticatorData ≤ 4KB · 서명 ≤ 72 · rawId 16~1023 ·
 *   id 는 rawId 와 같은 글자 · userHandle 은 있어야 한다(아이디 없는 로그인 — 열쇠가 계정을 알려 준다).
 * 로그인 전 쿠키(`__Host-tbox_pk`)가 있고 모양이 맞아야 한다 — 없으면 챌린지를 소비할 수 없으니 `passkey_challenge`.
 *
 * 검증 순서·실패 집계·계정 상태는 `Auth::loginWithPasskey` 한 곳이다(비밀번호 로그인과 같은 세션 발급 함수).
 * 응답은 `/api/login` 과 **같은 모양**(`ok`·`next`·`mfa_required`·`mfa_recommended`) — 화면이 한 함수로 다음 걸음을 정한다.
 * 실패는 전부 401 한 문구(MSG_LOGIN_FAILED) — 「없는 열쇠」와 「서명 틀림」의 본문이 같다. 예외 둘: 잠금 423 · 꺼진 열쇠 401 문장.
 * 성공·「꺼진 열쇠」 때 로그인 전 쿠키를 지운다(다음 로그인은 새 쿠키).
 *
 * @var array<string,mixed>|null $user   public 이라 null
 * @var list<string>             $params 없음
 */

require_once __DIR__ . '/../Passkey.php';

$allowed = ['id', 'rawId', 'type', 'clientDataJSON', 'authenticatorData', 'signature', 'userHandle', 'authenticatorAttachment'];

$body = Http::jsonBody(16384, 3);

if (Passkey::config() === null || !Passkey::tablesReady()) {
    Http::error(503, Http::MSG_PASSKEY_UNAVAILABLE);
}

// ─── 1) 모양 — 틀리면 「요청이 잘못됐다」(400 · 실패로 세지 않는다 — 잘못 만든 화면이 계정을 잠그지 않게) ──
if ($body === [] || array_diff(array_keys($body), $allowed) !== []
    || ($body['type'] ?? null) !== 'public-key'
    || !is_string($body['id'] ?? null) || !is_string($body['rawId'] ?? null) || $body['id'] !== $body['rawId']
    || (array_key_exists('authenticatorAttachment', $body) && $body['authenticatorAttachment'] !== null
        && $body['authenticatorAttachment'] !== 'platform')
) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}
try {
    $rawId      = Passkey::field($body, 'rawId', Passkey::MAX_CREDENTIAL_ID, Passkey::MIN_CREDENTIAL_ID);
    $clientData = Passkey::field($body, 'clientDataJSON', Passkey::MAX_CLIENT_DATA);
    $authData   = Passkey::field($body, 'authenticatorData', Passkey::MAX_CBOR, 37);
    $signature  = Passkey::field($body, 'signature', Passkey::MAX_SIGNATURE);
    $userHandle = array_key_exists('userHandle', $body) && $body['userHandle'] !== null && $body['userHandle'] !== ''
        ? Passkey::field($body, 'userHandle', 64)
        : null;
} catch (PasskeyError $e) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}
unset($body);

$pre = Passkey::preCookie();
if ($pre === null) {
    Audit::deny('auth.login', 'passkey_challenge', ['target_type' => 'user', 'target_name' => '(알 수 없는 열쇠)']);
    Http::error(401, Http::MSG_LOGIN_FAILED);
}

try {
    $result = Auth::loginWithPasskey($rawId, $clientData, $authData, $signature, $userHandle, $pre);
} catch (AuthLockedException $e) {
    Http::error(423, Http::MSG_LOGIN_LOCKED);
} catch (AuthPasskeyRevokedException $e) {
    // 「꺼진 열쇠」 — 서명이 맞은 사람에게만 가는 문장. `passkey_off` 는 화면이 「이 기기 표시」를 끄는 신호(계정 정보 아님)
    Http::clearPasskeyPreCookie();
    Http::json(['error' => Http::MSG_PASSKEY_REVOKED, 'passkey_off' => true], 401);
    exit;
}
unset($clientData, $authData, $signature, $userHandle, $rawId, $pre);

if ($result === null) {
    Http::error(401, Http::MSG_LOGIN_FAILED);
}
Http::clearPasskeyPreCookie();

// `/api/login` 과 같은 모양 — 계산은 Auth 한 곳(화면이 역할을 비교하지 않는다)
Http::json([
    'ok'              => true,
    'next'            => Auth::nextStep($result),
    'mfa_required'    => Auth::mfaRequired($result),
    'mfa_recommended' => Auth::mfaRecommended($result),
]);
