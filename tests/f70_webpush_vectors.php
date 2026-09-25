<?php
declare(strict_types=1);

/**
 * tests/f70_webpush_vectors.php — F70-T1: 웹 푸시 계산이 RFC 와 **바이트 단위로** 같은가 (지시서 G 표 F70-T1)
 *
 *   ① RFC 8291 5장 예시 + 부록 A 중간값 — 같은 키·salt·평문으로 ecdh_secret · PRK_key · key_info · IKM · PRK · CEK · NONCE ·
 *      86바이트 머리 · 암호문이 한 바이트도 다르지 않다. `WebPush::encrypt` 결과 = 5장의 본문 전체.
 *      받는 쪽(ua_private)으로 풀어 평문이 돌아오는지도 본다(따로 짠 풀기 — 양쪽 계산이 같은 곳을 가리키는지).
 *   ② RFC 8292 2장 예시 — JWT 머리·몸 모양, 그 서명(r ‖ s)을 DER 로 바꿔 예시 공개 키 `k` 로 `openssl_verify` 가 1.
 *      k 의 좌표 = 예시 JWK 의 x·y.
 *   ③ 새로 만든 **시험용 키**(실행할 때마다 openssl 로 새로 — 저장소에 키 파일 0)로 `WebPush::vapidJwt` → 서명 64바이트 →
 *      DER → openssl_verify 1 · DER ↔ r‖s 왕복 · 서명 한 바이트 바꾸면 0 · 여러 번(앞자리 0 이 나오는 경우까지)
 *   ④ 받기 값 검사(`checkSubscriptionKeys`) — 64바이트·첫 바이트 0x03·**곡선 밖 점**·좌표 ≥ p·auth 15바이트·모양 밖 글자 → bad_key
 *   ⑤ 알림 내용(`payload`) 모양 — 1KB 상한 · 이름 40자 · 모르는 종류 거부
 *
 * ★ 여기 적힌 키·값은 전부 **RFC 공개 예시**다(RFC 8291 5장·부록 A, RFC 8292 2장 — 비밀 아님). 글자 그대로 옮겼고
 *   RFC 가 줄바꿈·공백을 넣은 곳도 그대로 두었다(쓸 때 공백을 지운다). 우리 서버의 키는 한 글자도 없다.
 * ★ 네트워크 0 · DB 0 · 파일 쓰기 0. 사용: php tests/f70_webpush_vectors.php  (끝 줄 「통과 n · 실패 m」 + RESULT)
 */

require dirname(__DIR__) . '/src/WebPush.php';

$pass = 0;
$fail = 0;
function ok(string $what, bool $cond, string $note = ''): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo '  ✔ ', $what, $note === '' ? '' : '  (' . $note . ')', "\n";
    } else {
        $fail++;
        echo '  ✖ ', $what, $note === '' ? '' : '  (' . $note . ')', "\n";
    }
}
/** RFC 의 base64url 글자(공백·줄바꿈 섞임 그대로) → 바이트 */
function rfc(string $text): string
{
    $raw = WebPush::b64uDecode((string) preg_replace('/\s+/', '', $text));
    if ($raw === null) {
        throw new RuntimeException('RFC 값 풀기 실패');
    }
    return $raw;
}
function rejects(callable $fn, string $reason): bool
{
    try {
        $fn();
    } catch (WebPushError $e) {
        return $e->reason === $reason;
    }
    return false;
}

// ════════════════════════════════════════════════════════════════
echo "① RFC 8291 5장 예시 · 부록 A 중간값 (RFC 공개 예시 — 글자 그대로)\n";
// ── 입력 (부록 A 「The following are inputs to the calculation」) ──
$plaintext = rfc('V2hlbiBJIGdyb3cgdXAsIEkgd2FudCB0byBiZSBhIHdhdGVybWVsb24');
$asPublic  = rfc('BP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIg
      Dll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8');
$asPrivate = rfc('yfWPiYE-n46HLnH0KqZOF1fJJU3MYrct3AELtAQ-oRw');
$uaPublic  = rfc('BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-
      JvLexhqUzORcx aOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4');
$uaPrivate = rfc('q1dXpw3UpT5VOmu_cf_v6ih07Aems3njxI-JWgLcM94');
$salt      = rfc('DGv6ra1nlYgDCS1FRnbzlw');
$authSec   = rfc('BTBZMqHH6r4Tts7J_aSIgg');
// ── 중간값 (부록 A 「This produces the following intermediate values」) ──
$wantEcdh    = rfc('kyrL1jIIOHEzg3sM2ZWRHDRB62YACZhhSlknJ672kSs');
$wantPrkKey  = rfc('Snr3JMxaHVDXHWJn5wdC52WjpCtd2EIEGBykDcZW32k');
$wantKeyInfo = rfc('V2ViUHVzaDogaW5mbwAEJXGyvs3942BVG
      q8e0PTNNmwR zr5VX4m8t7GGpTM5FzFo7OLr4BhZe9MEebhuPI-OztV3
      ylkYfpJGmQ22ggCLDgT-M_SrDepxkU21WCP3O1SUj0Ew
      bZIHMtu5pZpTKGSCIA5Zent7wmC6HCJ5mFgJkuk5cwAv MBKiiujwa7t45ewP');
$wantIkm     = rfc('S4lYMb_L0FxCeq0WhDx813KgSYqU26kOyzWUdsXYyrg');
$wantPrk     = rfc('09_eUZGrsvxChDCGRCdkLiDXrReGOEVeSCdCcPBSJSc');
$wantCekInfo = rfc('Q29udGVudC1FbmNvZGluZzogYWVzMTI4Z2NtAA');
$wantCek     = rfc('oIhVW04MRdy2XN9CiKLxTg');
$wantNInfo   = rfc('Q29udGVudC1FbmNvZGluZzogbm9uY2UA');
$wantNonce   = rfc('4h_95klXJ5E_qnoN');
$wantHeader  = rfc('DGv6ra1nlYgDCS1FRnbzlwAAEABBBP4z 9KsN6nGRTbVYI_c7VJSPQTBtkgcy27ml
   mlMoZIIgDll6e3vCYLocInmYWAmS6Tlz AC8wEqKK6PBru3jl7A8');
$wantPadded  = rfc('V2hlbiBJIGdyb3cgdXAsIEkgd2FudCB0 byBiZSBhIHdhdGVybWVsb24C');
$wantCipher  = rfc('8pfeW0KbunFT06SuDKoJH9Ql87S1QUrd irN6GcG7sFz1y1sqLgVi1VhjVkHsUoEs
   bI_0LpXMuGvnzQ');
// ── 5장의 본문 전체 (POST 본문, Content-Length 145) ──
$wantBody    = rfc('DGv6ra1nlYgDCS1FRnbzlwAAEABBBP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27ml
   mlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A_yl95bQpu6cVPT
   pK4Mqgkf1CXztLVBSt2Ks3oZwbuwXPXLWyouBWLVWGNWQexSgSxsj_Qulcy4a-fN');
// 5장 목록의 같은 키(두 번 적힌 값이 같은가 — 옮겨 적기 확인)
ok('5장 목록의 수신·발신 키 = 부록 A 값', rfc('BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcx
                     aOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4') === $uaPublic
    && rfc('BP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIg
                     Dll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8') === $asPublic
    && rfc('BTBZMqHH6r4Tts7J_aSIgg') === $authSec);
ok('평문 = "When I grow up, I want to be a watermelon"', $plaintext === 'When I grow up, I want to be a watermelon');
// RFC 5장 머리의 `Content-Length: 145` 는 같은 절의 본문(base64url)과 어긋난다 — 본문을 풀면 144바이트다(머리 86 + 평문 41 + 구분 1 + 태그 16).
// 본문 글자와 부록 A 의 머리·암호문이 서로 맞으므로 **본문 쪽**을 기준으로 삼는다(RFC 글자 그대로의 모순 — F-70 첫 시험에서 발견).
ok('본문 길이 144 = 머리 86 + 평문 41 + 구분 1 + 태그 16 (RFC 5장 Content-Length 145 는 본문과 어긋남)', strlen($wantBody) === 86 + 41 + 1 + 16, (string) strlen($wantBody));

$asKey = WebPush::privateKeyFromRaw($asPrivate, $asPublic);
$uaKey = WebPush::privateKeyFromRaw($uaPrivate, $uaPublic);
ok('as_private ↔ as_public 한 쌍 · ua_private ↔ ua_public 한 쌍', WebPush::rawPublicOf($asKey) === $asPublic && WebPush::rawPublicOf($uaKey) === $uaPublic);

$ecdh1 = openssl_pkey_derive(WebPush::publicKeyFromRaw($uaPublic), $asKey, 32);
$ecdh2 = openssl_pkey_derive(WebPush::publicKeyFromRaw($asPublic), $uaKey, 32);
ok('ecdh_secret (보내는 쪽 as_private × ua_public)', $ecdh1 === $wantEcdh);
ok('ecdh_secret (받는 쪽 ua_private × as_public) 같은 값', $ecdh2 === $wantEcdh);
ok('PRK_key = HMAC-SHA-256(auth_secret, ecdh_secret)', hash_hmac('sha256', $wantEcdh, $authSec, true) === $wantPrkKey);
$keyInfo = "WebPush: info\x00" . $uaPublic . $asPublic;
ok('key_info = "WebPush: info" ‖ 0 ‖ ua_public ‖ as_public', $keyInfo === $wantKeyInfo, strlen($keyInfo) . '바이트');
$ikm = hash_hkdf('sha256', $wantEcdh, 32, $keyInfo, $authSec);
ok('IKM = HKDF(auth_secret, ecdh_secret, key_info, 32)', $ikm === $wantIkm);
ok('PRK = HMAC-SHA-256(salt, IKM)', hash_hmac('sha256', $wantIkm, $salt, true) === $wantPrk);
ok('cek_info · nonce_info 글자', "Content-Encoding: aes128gcm\x00" === $wantCekInfo && "Content-Encoding: nonce\x00" === $wantNInfo);
ok('CEK = HKDF(salt, IKM, cek_info, 16)', hash_hkdf('sha256', $wantIkm, 16, $wantCekInfo, $salt) === $wantCek);
ok('NONCE = HKDF(salt, IKM, nonce_info, 12)', hash_hkdf('sha256', $wantIkm, 12, $wantNInfo, $salt) === $wantNonce);
ok('머리 86바이트 = salt ‖ rs 4096 ‖ idlen 65 ‖ as_public', $salt . pack('N', 4096) . chr(65) . $asPublic === $wantHeader && strlen($wantHeader) === 86);
ok('평문 ‖ 0x02', $plaintext . "\x02" === $wantPadded);
$tag = '';
$ct = openssl_encrypt($wantPadded, 'aes-128-gcm', $wantCek, OPENSSL_RAW_DATA, $wantNonce, $tag, '', 16);
ok('AES-128-GCM 암호문 ‖ 태그 = 부록 A', is_string($ct) && $ct . $tag === $wantCipher);
ok('머리 ‖ 암호문 = 5장 본문', $wantHeader . $wantCipher === $wantBody);

$body = WebPush::encrypt($plaintext, $uaPublic, $authSec, $asKey, $salt);
ok('WebPush::encrypt(RFC 키·salt) = 5장 본문 **바이트 단위로 같다**', $body === $wantBody,
    'sha256 ' . substr(hash('sha256', $body), 0, 16) . ' / RFC ' . substr(hash('sha256', $wantBody), 0, 16));

/** 받는 쪽 풀기 — 위 계산과 따로(머리에서 salt·키를 읽어) */
function ua_decrypt(string $body, OpenSSLAsymmetricKey $uaKey, string $uaPublic, string $auth): ?string
{
    if (strlen($body) < 86 + 17) {
        return null;
    }
    $salt = substr($body, 0, 16);
    $rs = unpack('N', substr($body, 16, 4))[1];
    $idlen = ord($body[20]);
    if ($rs !== 4096 || $idlen !== 65) {
        return null;
    }
    $asPub = substr($body, 21, 65);
    $ecdh = openssl_pkey_derive(WebPush::publicKeyFromRaw($asPub), $uaKey, 32);
    $ikm = hash_hkdf('sha256', $ecdh, 32, "WebPush: info\x00" . $uaPublic . $asPub, $auth);
    $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\x00", $salt);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\x00", $salt);
    $rec = substr($body, 86);
    $pt = openssl_decrypt(substr($rec, 0, -16), 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, substr($rec, -16));
    if (!is_string($pt) || substr($pt, -1) !== "\x02") {
        return null;   // 끝 레코드 구분 바이트가 0x02 가 아니면 버린다(RFC 8291 4장)
    }
    return substr($pt, 0, -1);
}
ok('받는 쪽(ua_private)으로 5장 본문을 풀면 평문', ua_decrypt($wantBody, $uaKey, $uaPublic, $authSec) === $plaintext);
$r1 = WebPush::encrypt($plaintext, $uaPublic, $authSec);
$r2 = WebPush::encrypt($plaintext, $uaPublic, $authSec);
ok('운영 모양(임시 키·salt 새로) — 풀리고, 두 번 부르면 salt·키가 다르다',
    ua_decrypt($r1, $uaKey, $uaPublic, $authSec) === $plaintext && ua_decrypt($r2, $uaKey, $uaPublic, $authSec) === $plaintext
    && substr($r1, 0, 16) !== substr($r2, 0, 16) && substr($r1, 21, 65) !== substr($r2, 21, 65));
$flip = $r1;
$flip[strlen($flip) - 1] = chr(ord($flip[strlen($flip) - 1]) ^ 1);
ok('태그 한 비트를 바꾸면 풀리지 않는다', ua_decrypt($flip, $uaKey, $uaPublic, $authSec) === null);
ok('내용 1KB 초과 → too_large', rejects(static fn() => WebPush::encrypt(str_repeat('a', 1025), $uaPublic, $authSec), 'too_large'));

// ════════════════════════════════════════════════════════════════
echo "② RFC 8292 2장 예시 JWT (RFC 공개 예시 — 글자 그대로)\n";
$rfcT = 'eyJ0eXAiOiJKV1QiLCJhbGciOiJFUzI1NiJ9.eyJhdWQiOiJodHRwczovL3
        B1c2guZXhhbXBsZS5uZXQiLCJleHAiOjE0NTM1MjM3NjgsInN1YiI6Im1ha
        Wx0bzpwdXNoQGV4YW1wbGUuY29tIn0.i3CYb7t4xfxCDquptFOepC9GAu_H
        LGkMlMuCGSK2rpiUfnK9ojFwDXb1JrErtmysazNjjvW2L9OkSSHzvoD1oA';
$rfcK = 'BA1Hxzyi1RUM1b5wjxsn7nGxAszw2u61m164i3MrAIxHF6YK5h4SDYic-dR
        uU_RCPCfA5aq9ojSwk5Y2EmClBPs';
$jwt = (string) preg_replace('/\s+/', '', $rfcT);
$parts = explode('.', $jwt);
ok('JWT 는 세 조각', count($parts) === 3);
$head = json_decode((string) WebPush::b64uDecode($parts[0]), true);
$claims = json_decode((string) WebPush::b64uDecode($parts[1]), true);
ok('머리 = {"typ":"JWT","alg":"ES256"}', $head === ['typ' => 'JWT', 'alg' => 'ES256']);
ok('몸 = aud https://push.example.net · exp 1453523768 · sub mailto:push@example.com',
    $claims === ['aud' => 'https://push.example.net', 'exp' => 1453523768, 'sub' => 'mailto:push@example.com']);
$sig = WebPush::b64uDecode($parts[2]);
ok('서명 64바이트(r ‖ s)', is_string($sig) && strlen($sig) === 64);
$k = rfc($rfcK);
ok('k = 0x04 ‖ JWK x ‖ JWK y', $k === "\x04" . rfc('DUfHPKLVFQzVvnCPGyfucbECzPDa7rWbXriLcysAjEc') . rfc('F6YK5h4SDYic-dRuU_RCPCfA5aq9ojSwk5Y2EmClBPs'));
$kKey = WebPush::publicKeyFromRaw($k);
ok('예시 서명을 예시 공개 키로 openssl_verify = 1 (r‖s → DER)',
    openssl_verify($parts[0] . '.' . $parts[1], WebPush::rsToDer((string) $sig), $kKey, OPENSSL_ALGO_SHA256) === 1);
ok('DER → r‖s 왕복 = 원래 서명', WebPush::derToRs(WebPush::rsToDer((string) $sig)) === $sig);
ok('몸 한 글자를 바꾸면 검증 0',
    openssl_verify($parts[0] . '.' . $parts[1] . 'x', WebPush::rsToDer((string) $sig), $kKey, OPENSSL_ALGO_SHA256) === 0);

// ════════════════════════════════════════════════════════════════
echo "③ 시험용 키(실행할 때 새로 — 저장소에 키 파일 0)로 서명 · 왕복\n";
$tk = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
$tkPub = WebPush::rawPublicOf($tk);
$tkPubKey = WebPush::publicKeyFromRaw($tkPub);
$mine = WebPush::vapidJwt('https://fcm.googleapis.com', 1900000000, $tk);
$mp = explode('.', $mine);
ok('우리 JWT 머리·몸 모양 (sub = 사이트 주소, 코드 상수)',
    json_decode((string) WebPush::b64uDecode($mp[0]), true) === ['typ' => 'JWT', 'alg' => 'ES256']
    && json_decode((string) WebPush::b64uDecode($mp[1]), true) === ['aud' => 'https://fcm.googleapis.com', 'exp' => 1900000000, 'sub' => 'https://changkwon.mso.so']);
$ms = WebPush::b64uDecode($mp[2]);
ok('우리 서명 64바이트 → DER → openssl_verify = 1', strlen((string) $ms) === 64
    && openssl_verify($mp[0] . '.' . $mp[1], WebPush::rsToDer((string) $ms), $tkPubKey, OPENSSL_ALGO_SHA256) === 1);
$bad = (string) $ms;
$bad[10] = chr(ord($bad[10]) ^ 0x40);
ok('서명 한 바이트 바꾸면 0', openssl_verify($mp[0] . '.' . $mp[1], WebPush::rsToDer($bad), $tkPubKey, OPENSSL_ALGO_SHA256) === 0);
$roundOk = true;
$shortSeen = 0;
for ($i = 0; $i < 200; $i++) {
    $der = '';
    openssl_sign('m' . $i, $der, $tk, OPENSSL_ALGO_SHA256);
    $rs = WebPush::derToRs($der);
    // 앞자리 0x00 이 붙거나(맨 앞 비트) r·s 가 32바이트보다 짧아 DER 이 70·71바이트가 되는 경우를 센다
    if (strlen($der) !== 72) {
        $shortSeen++;
    }
    if (strlen($rs) !== 64 || WebPush::rsToDer($rs) !== $der
        || openssl_verify('m' . $i, WebPush::rsToDer($rs), $tkPubKey, OPENSSL_ALGO_SHA256) !== 1
    ) {
        $roundOk = false;
    }
}
ok('서명 200번 — DER ↔ r‖s 왕복이 원래 DER 와 바이트까지 같고 전부 검증 1', $roundOk, '72바이트가 아닌 DER ' . $shortSeen . '번');
ok('Authorization 모양 "vapid t=<JWT>, k=<65바이트 base64url>"',
    WebPush::vapidAuthorization($mine, $tkPub) === 'vapid t=' . $mine . ', k=' . WebPush::b64uEncode($tkPub)
    && strlen(WebPush::b64uEncode($tkPub)) === 87);
ok('aud 는 스킴 + 호스트뿐(경로·토큰 없음)', WebPush::audienceOf('https://fcm.googleapis.com/fcm/send/abc:def') === 'https://fcm.googleapis.com');
ok('aud 모양 밖(http) → 서명 거부', rejects(static fn() => WebPush::vapidJwt('http://fcm.googleapis.com', 1, $tk), 'crypto'));
ok('DER 모양 밖 → crypto', rejects(static fn() => WebPush::derToRs("\x30\x02\x02\x00"), 'crypto'));

// ════════════════════════════════════════════════════════════════
echo "④ 받기 값 검사 (checkSubscriptionKeys)\n";
$uaB64 = WebPush::b64uEncode($uaPublic);
$authB64 = WebPush::b64uEncode($authSec);
$okKeys = WebPush::checkSubscriptionKeys($uaB64, $authB64);
ok('RFC 수신 키 · auth → 통과(원래 바이트)', $okKeys['p256dh'] === $uaPublic && $okKeys['auth'] === $authSec);
ok('패딩 붙은 base64url 도 같은 값', WebPush::checkSubscriptionKeys($uaB64 . '=', $authB64 . '==')['auth'] === $authSec);
ok('p256dh 64바이트 → bad_key', rejects(static fn() => WebPush::checkSubscriptionKeys(WebPush::b64uEncode(substr($uaPublic, 0, 64)), $authB64), 'bad_key'));
ok('p256dh 첫 바이트 0x03(압축 모양) → bad_key', rejects(static fn() => WebPush::checkSubscriptionKeys(WebPush::b64uEncode("\x03" . substr($uaPublic, 1)), $authB64), 'bad_key'));
$off = $uaPublic;
$off[64] = chr(ord($off[64]) ^ 0x01);   // y 의 끝 비트 — 곡선 위에 있지 않은 점
ok('곡선 밖 점(y 한 비트) → bad_key', rejects(static fn() => WebPush::checkSubscriptionKeys(WebPush::b64uEncode($off), $authB64), 'bad_key'));
$bigX = "\x04" . str_repeat("\xff", 32) . substr($uaPublic, 33);
ok('x 좌표 ≥ p → bad_key', rejects(static fn() => WebPush::checkSubscriptionKeys(WebPush::b64uEncode($bigX), $authB64), 'bad_key'));
ok('모두 0 인 점 → bad_key', rejects(static fn() => WebPush::checkSubscriptionKeys(WebPush::b64uEncode("\x04" . str_repeat("\x00", 64)), $authB64), 'bad_key'));
ok('auth 15바이트 → bad_key', rejects(static fn() => WebPush::checkSubscriptionKeys($uaB64, WebPush::b64uEncode(substr($authSec, 0, 15))), 'bad_key'));
ok('auth 17바이트 → bad_key', rejects(static fn() => WebPush::checkSubscriptionKeys($uaB64, WebPush::b64uEncode($authSec . 'x')), 'bad_key'));
ok('base64url 밖 글자(+ / 공백) → bad_key', rejects(static fn() => WebPush::checkSubscriptionKeys(strtr($uaB64, '-_', '+/') . ' ', $authB64), 'bad_key'));
ok('빈 값 → bad_key', rejects(static fn() => WebPush::checkSubscriptionKeys('', $authB64), 'bad_key'));

// ════════════════════════════════════════════════════════════════
echo "⑤ 알림 내용 모양 (payload)\n";
$pl = WebPush::payload(['kind' => 'alert', 'rule' => 'AL-1', 'name' => '권한 우회 시도', 'origin' => 'human', 'at' => '2026-09-24T14:05:00+09:00', 'n' => 1]);
$pd = json_decode($pl, true);
ok('내용 JSON 키 = v · kind · rule · name · origin · at · n', array_keys($pd) === ['v', 'kind', 'rule', 'name', 'origin', 'at', 'n'] && $pd['v'] === 1);
ok('1KB 이하', strlen($pl) <= 1024, strlen($pl) . '바이트');
ok('이름 41자 → 거부', rejects(static fn() => WebPush::payload(['kind' => 'alert', 'rule' => 'AL-1', 'name' => str_repeat('가', 41), 'origin' => 'human', 'at' => '2026-09-24T14:05:00+09:00', 'n' => 1]), 'crypto'));
ok('모르는 종류 → 거부', rejects(static fn() => WebPush::payload(['kind' => 'shell', 'rule' => null, 'name' => null, 'origin' => null, 'at' => '2026-09-24T14:05:00+09:00', 'n' => 1]), 'crypto'));
ok('규칙 번호 모양 밖 → 거부', rejects(static fn() => WebPush::payload(['kind' => 'alert', 'rule' => 'AL-1; x', 'name' => null, 'origin' => 'human', 'at' => '2026-09-24T14:05:00+09:00', 'n' => 1]), 'crypto'));
ok('origin 넷 밖 → 거부', rejects(static fn() => WebPush::payload(['kind' => 'alert', 'rule' => 'AL-1', 'name' => null, 'origin' => 'robot', 'at' => '2026-09-24T14:05:00+09:00', 'n' => 1]), 'crypto'));
ok('기기 요약은 고정 글자(UA 원문 아님)', WebPush::deviceHint('Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 Chrome/128.0 Mobile Safari/537.36') === 'Android Chrome'
    && WebPush::deviceHint('Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 Version/17.5 Mobile/15E148 Safari/604.1') === 'iPhone Safari'
    && WebPush::deviceHint('<script>') === '기타 브라우저');

printf("\n통과 %d · 실패 %d\n", $pass, $fail);
printf("RESULT pass=%d fail=%d skip=0\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
