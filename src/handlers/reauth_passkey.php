<?php
declare(strict_types=1);

/**
 * src/handlers/reauth_passkey.php — POST /api/me/reauth/passkey (정책: authenticated + CSRF · nginx 속도 제한)
 * T:F-72 라운드 C · 지시서 1.4 「재인증」
 *
 * 본문은 로그인 창구와 같은 모양(허용 키만): `{id, rawId, type, clientDataJSON, authenticatorData, signature, userHandle?, authenticatorAttachment?}`.
 * 검증은 `Auth::reauthWithPasskey` 한 곳 — 로그인의 2~7 + 열쇠 주인 = 세션의 사람 + 챌린지 묶음(이 세션). 성공이면 비밀번호 재인증과
 * **같은 15분 창**(지금 세션만) · 응답도 `POST /api/me/reauth` 와 같은 모양 `{ok, reauth_until}`. 실패는 400 통일 문구 ·
 * 로그인 뒤 창구 규칙(R-48 — 서명·주소·횟수가 틀리면 실패 1회, 5회면 잠금 + 세션 전부 끊김).
 * 이 창구는 **권한을 만들지 않는다** — 관문 [3-2] 는 인가 뒤다(Employee 가 재인증해도 관리 창구는 403).
 *
 * @var array<string,mixed> $user
 * @var list<string>        $params
 */

require_once __DIR__ . '/../Passkey.php';

$allowed = ['id', 'rawId', 'type', 'clientDataJSON', 'authenticatorData', 'signature', 'userHandle', 'authenticatorAttachment'];
$body = Http::jsonBody(16384, 3);

if (!Passkey::available()) {
    Http::error(503, Http::MSG_PASSKEY_UNAVAILABLE);
}
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
} catch (PasskeyError $e) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}
unset($body);

[$ok, $until] = Auth::reauthWithPasskey($user, $rawId, $clientData, $authData, $signature);
unset($clientData, $authData, $signature, $rawId);

if (!$ok) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}
Http::json(['ok' => true, 'reauth_until' => $until]);
