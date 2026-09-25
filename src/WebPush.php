<?php
declare(strict_types=1);

/**
 * src/WebPush.php — 웹 푸시의 **계산만** 하는 곳 (PRD F-70 · 지시서 docs/f70-push-alerts-directive.md B·C·D, ARCH 5.32)
 *
 *   · RFC 8291 내용 암호화(`Content-Encoding: aes128gcm`) — 기기 키(p256dh)·auth 비밀로 알림 내용을 잠근다
 *   · RFC 8292 VAPID — 보내는 서버의 서명(JWT, ES256). 푸시 회사가 「이 받기를 만든 그 서버」인지 확인한다
 *   · 받기 값 검사 — p256dh 는 65바이트 · 첫 바이트 0x04 · **P-256 곡선 위의 점**(RFC 8291 7장 — 곡선 밖의 점을
 *     그대로 쓰면 비밀 키가 새는 공격이 있다), auth 는 16바이트
 *
 * ★ 이 파일은 **밖으로 한 번도 나가지 않는다**(네트워크 함수 0). 보내는 것은 `src/Fetch.php` 의 `pushSend()`
 *   하나뿐이다(S-05 ⑬ — curl 은 Fetch.php 에만, scripts/security_lint.sh). 파일도 읽지 않는다 — 키 파일은
 *   부르는 쪽이 경로를 정해 넘긴 것만 읽는다(`loadPrivateKey`·`readPublicKeyFile`, 요청 값이 섞이지 않는다).
 * ★ 새 라이브러리 없이 PHP 내장 openssl(prime256v1 · openssl_pkey_derive · aes-128-gcm)·hash_hkdf 만 쓴다
 *   (지시서 머리 실측: PHP 8.1 + OpenSSL 3.0.2).
 * ★ 비밀(VAPID 비밀 키 · 받기 auth · p256dh · endpoint)은 이 파일이 **돌려주는 값 말고는** 어디에도 적지 않는다 —
 *   로그·예외 문구에도 넣지 않는다. 예외는 사유 코드(`WebPushError::$reason`)만 갖는다.
 *
 * 시험: tests/f70_webpush_vectors.php — RFC 8291 5장 예시·부록 A 의 키·salt·평문으로 **바이트 단위 같은 암호문**,
 *       RFC 8292 2장 예시 JWT 의 서명을 예시 공개 키로 검증(r‖s → DER), 새로 서명한 JWT 의 왕복.
 */

/**
 * 계산 거부 — `$reason` 은 사유 코드뿐이다(값을 담지 않는다).
 *   bad_key     p256dh·auth·키 파일의 모양·곡선 검사 실패
 *   too_large   내용이 상한(1KB)을 넘음
 *   crypto      openssl 이 실패함(원문은 부르는 쪽이 모른다 — 서버 로그에도 값 없이)
 */
final class WebPushError extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}

final class WebPush
{
    /**
     * VAPID **공개** 키 파일 (구현 기록 2 — `.env` 가 아니라 이 파일). root 0644, base64url 한 줄(65바이트 점).
     * 웹(tbox-php)이 `GET /api/admin/push/key` 로 내보낼 때 읽는다. open_basedir 에 /etc/tbox-v2 가 있다.
     * 코드 상수다 — 요청 값·설정 값으로 바꾸는 길이 없다. 시험은 **임시 인스턴스 사본에서만** 이 줄을 바꾼다.
     */
    public const PUBLIC_KEY_FILE = '/etc/tbox-v2/vapid-public.txt';

    /** VAPID 연락처 `sub` — 사이트 주소 (L-9: 개인 메일을 외부 회사에 보내지 않는다). 코드 상수. */
    public const VAPID_SUBJECT = 'https://changkwon.mso.so';

    /** JWT 유효 시간 — 12시간 (지시서 C장 4 `exp` ≤ 12시간. RFC 8292 상한은 24시간) */
    public const JWT_LIFETIME = 43200;

    /** 알림 내용 JSON 의 상한 (C장 4 「≤ 1KB」). 암호화한 본문은 여기에 86 + 1 + 16 바이트가 붙는다 */
    public const MAX_PAYLOAD_BYTES = 1024;

    /** aes128gcm 레코드 크기 (RFC 8188 · 8291 4장 — 레코드 하나) */
    public const RECORD_SIZE = 4096;

    /** P-256 공개 키 SubjectPublicKeyInfo 머리 (id-ecPublicKey + prime256v1, BIT STRING 66바이트) */
    private const SPKI_P256_PREFIX = "\x30\x59\x30\x13\x06\x07\x2a\x86\x48\xce\x3d\x02\x01"
        . "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07\x03\x42\x00";

    /** base64url (패딩 선택) — 이 모양 밖이면 풀지 않는다 */
    private const B64U_RE = '/^[A-Za-z0-9_-]*={0,2}$/';

    /** 기기 요약 표 (D 표 `device_hint` — UA 원문은 저장하지 않고 이 고정 글자 중 하나만) */
    private const DEVICE_HINTS = [
        'iphone'   => 'iPhone Safari',
        'ipad'     => 'iPad Safari',
        'android'  => 'Android',
        'windows'  => 'Windows',
        'mac'      => 'Mac',
        'linux'    => 'Linux',
    ];

    // ─── base64url ───────────────────────────────────────────────

    public static function b64uEncode(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    /** 엄격한 풀기 — 모양이 아니거나 풀 수 없으면 null. 공백·줄바꿈도 받지 않는다. */
    public static function b64uDecode(string $s): ?string
    {
        if ($s === '' || strlen($s) > 4096 || preg_match(self::B64U_RE, $s) !== 1) {
            return null;
        }
        $raw = base64_decode(strtr(rtrim($s, '='), '-_', '+/'), true);
        return $raw === false ? null : $raw;
    }

    // ─── 키 ─────────────────────────────────────────────────────

    /**
     * 받기의 p256dh(65바이트 점) → openssl 공개 키. **곡선 위의 점**이어야 한다.
     * OpenSSL 은 SubjectPublicKeyInfo 를 읽을 때 점이 곡선 위에 있는지·좌표가 범위 안인지 본다
     * (RFC 8291 7장의 세 단계 — 무한원점은 0x04 모양으로 나타낼 수 없다). 시험이 곡선 밖 점으로 확인한다.
     */
    public static function publicKeyFromRaw(string $raw): OpenSSLAsymmetricKey
    {
        if (strlen($raw) !== 65 || $raw[0] !== "\x04") {
            throw new WebPushError('bad_key');
        }
        $pem = "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode(self::SPKI_P256_PREFIX . $raw), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
        $key = @openssl_pkey_get_public($pem);
        if ($key === false) {
            self::clearOpensslErrors();
            throw new WebPushError('bad_key');
        }
        $d = openssl_pkey_get_details($key);
        if (!is_array($d) || ($d['type'] ?? null) !== OPENSSL_KEYTYPE_EC
            || (($d['ec']['curve_name'] ?? '') !== 'prime256v1')
        ) {
            throw new WebPushError('bad_key');
        }
        return $key;
    }

    /**
     * 받기 등록 값 검사 (D 표 POST 행) — base64url 두 개를 풀어 모양·곡선을 본다.
     *
     * @return array{p256dh:string, auth:string} 원래 바이트(p256dh 65 · auth 16)
     */
    public static function checkSubscriptionKeys(string $p256dhB64, string $authB64): array
    {
        $p256dh = self::b64uDecode($p256dhB64);
        $auth   = self::b64uDecode($authB64);
        if ($p256dh === null || $auth === null || strlen($p256dh) !== 65 || strlen($auth) !== 16) {
            throw new WebPushError('bad_key');
        }
        self::publicKeyFromRaw($p256dh);   // 곡선 위의 점인가 — 아니면 bad_key
        return ['p256dh' => $p256dh, 'auth' => $auth];
    }

    /** 공개 키 → 65바이트 점(0x04 ‖ x ‖ y). 좌표 앞자리 0 이 빠져 오면 32바이트로 채운다. */
    public static function rawPublicOf(OpenSSLAsymmetricKey $key): string
    {
        $d = openssl_pkey_get_details($key);
        if (!is_array($d) || !isset($d['ec']['x'], $d['ec']['y'])
            || ($d['ec']['curve_name'] ?? '') !== 'prime256v1'
        ) {
            throw new WebPushError('bad_key');
        }
        $x = (string) $d['ec']['x'];
        $y = (string) $d['ec']['y'];
        if (strlen($x) > 32 || strlen($y) > 32) {
            throw new WebPushError('bad_key');
        }
        return "\x04" . str_pad($x, 32, "\x00", STR_PAD_LEFT) . str_pad($y, 32, "\x00", STR_PAD_LEFT);
    }

    /**
     * 32바이트 비밀 값 + 65바이트 공개 점 → openssl 비밀 키 (SEC 1 ECPrivateKey DER).
     * **시험 벡터 전용**이다 — RFC 가 적어 둔 키로 같은 암호문을 만들려면 그 키를 읽어야 한다.
     * 운영의 보내는 쪽 임시 키는 `openssl_pkey_new` 가 요청마다 새로 만든다(encrypt 의 `$asPrivate = null`).
     */
    public static function privateKeyFromRaw(string $d, string $publicRaw): OpenSSLAsymmetricKey
    {
        if (strlen($d) !== 32 || strlen($publicRaw) !== 65 || $publicRaw[0] !== "\x04") {
            throw new WebPushError('bad_key');
        }
        $der = "\x30\x77\x02\x01\x01\x04\x20" . $d
            . "\xa0\x0a\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07"
            . "\xa1\x44\x03\x42\x00" . $publicRaw;
        $pem = "-----BEGIN EC PRIVATE KEY-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . "-----END EC PRIVATE KEY-----\n";
        $key = @openssl_pkey_get_private($pem);
        if ($key === false) {
            self::clearOpensslErrors();
            throw new WebPushError('bad_key');
        }
        if (self::rawPublicOf($key) !== $publicRaw) {
            throw new WebPushError('bad_key');   // 비밀 값과 공개 점이 짝이 아니다
        }
        return $key;
    }

    /**
     * VAPID 비밀 키 파일(PEM) — 보내는 배치만 부른다(tbox-push 0600, 웹 계정은 읽지 못한다 — L-3).
     * 경로는 배치 설정(push.conf)의 고정 값이고 요청 값이 아니다. 곡선이 P-256 이 아니면 거부.
     */
    public static function loadPrivateKey(string $pemPath): OpenSSLAsymmetricKey
    {
        if (!is_file($pemPath) || !is_readable($pemPath)) {
            throw new WebPushError('bad_key');
        }
        $pem = file_get_contents($pemPath, false, null, 0, 8192);
        if (!is_string($pem) || $pem === '') {
            throw new WebPushError('bad_key');
        }
        $key = @openssl_pkey_get_private($pem);
        $pem = str_repeat("\0", strlen($pem));   // 읽은 글자를 곧바로 덮어 둔다(가능한 만큼)
        unset($pem);
        if ($key === false) {
            self::clearOpensslErrors();
            throw new WebPushError('bad_key');
        }
        self::rawPublicOf($key);   // P-256 인가
        return $key;
    }

    /** 서버 쪽이 아직 반영되지 않았을 때(표 없음) 웹 창구가 내는 503 문구 */
    public const NOT_READY_MESSAGE = '휴대폰 경보가 아직 준비되지 않았습니다 (서버 쪽 반영 전 — 관리자 작업이 끝나면 켤 수 있습니다)';

    /**
     * 휴대폰 경보의 표 둘(받기 · 대기표)이 있나 — 표는 서버 쪽 반영(apply-f70-host 의 마이그레이션)이 만든다. 네트워크 없음.
     * 저장소 쪽만 반영된 상태면 false — 웹 창구는 500 대신 503(NOT_READY_MESSAGE), 화면은 「준비 안 됨」 안내.
     */
    public static function tablesReady(Db $db): bool
    {
        $r = $db->run(
            "SELECT (to_regclass('public.alert_push_subscriptions') IS NOT NULL
                     AND to_regclass('public.alert_push_outbox') IS NOT NULL) AS ready"
        )->fetch();
        $v = is_array($r) ? $r['ready'] : null;
        return $v === true || $v === 't' || $v === 1 || $v === '1';
    }

    /**
     * VAPID 공개 키 파일 → base64url 글자(65바이트 점인지 확인한 뒤). 없거나 모양이 아니면 null.
     * 웹 `GET /api/admin/push/key` 와 보내는 배치(서명 키와 짝인지 대조)가 쓴다.
     */
    public static function readPublicKeyFile(string $path): ?string
    {
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }
        $text = file_get_contents($path, false, null, 0, 512);
        if (!is_string($text)) {
            return null;
        }
        $text = trim($text);
        $raw = self::b64uDecode($text);
        if ($raw === null || strlen($raw) !== 65 || $raw[0] !== "\x04") {
            return null;
        }
        try {
            self::publicKeyFromRaw($raw);
        } catch (WebPushError $e) {
            return null;
        }
        return self::b64uEncode($raw);
    }

    // ─── RFC 8291 내용 암호화 ────────────────────────────────────

    /**
     * 알림 내용을 기기 키로 잠근다 → `Content-Encoding: aes128gcm` 본문 (머리 86바이트 + 암호문 + 태그 16).
     *
     *   ecdh_secret = ECDH(as_private, ua_public)
     *   IKM   = HKDF(salt = auth_secret, IKM = ecdh_secret, info = "WebPush: info" ‖ 0x00 ‖ ua_public ‖ as_public, L = 32)
     *   CEK   = HKDF(salt, IKM, "Content-Encoding: aes128gcm" ‖ 0x00, 16)
     *   NONCE = HKDF(salt, IKM, "Content-Encoding: nonce" ‖ 0x00, 12)
     *   본문   = salt(16) ‖ rs(4, 4096) ‖ idlen(1, 65) ‖ as_public(65) ‖ AES-128-GCM(CEK, NONCE, 평문 ‖ 0x02)
     *
     * `$asPrivate`·`$salt` 는 **시험 벡터 전용** 자리다(RFC 의 값을 넣어 같은 바이트를 만든다). 운영에서는 null 로 부르고
     * 요청마다 새 임시 키 쌍·새 salt(random_bytes)를 쓴다 — 같은 키·salt 를 두 번 쓰지 않는다.
     */
    public static function encrypt(
        string $plaintext,
        string $uaPublicRaw,
        string $authSecret,
        ?OpenSSLAsymmetricKey $asPrivate = null,
        ?string $salt = null
    ): string {
        if (strlen($plaintext) > self::MAX_PAYLOAD_BYTES) {
            throw new WebPushError('too_large');
        }
        if (strlen($authSecret) !== 16) {
            throw new WebPushError('bad_key');
        }
        if ($salt !== null && strlen($salt) !== 16) {
            throw new WebPushError('crypto');
        }
        $uaKey = self::publicKeyFromRaw($uaPublicRaw);

        if ($asPrivate === null) {
            $asPrivate = openssl_pkey_new([
                'private_key_type' => OPENSSL_KEYTYPE_EC,
                'curve_name'       => 'prime256v1',
            ]);
            if ($asPrivate === false) {
                self::clearOpensslErrors();
                throw new WebPushError('crypto');
            }
        }
        $asPublicRaw = self::rawPublicOf($asPrivate);
        $salt = $salt ?? random_bytes(16);

        $ecdh = openssl_pkey_derive($uaKey, $asPrivate, 32);
        if (!is_string($ecdh) || strlen($ecdh) !== 32) {
            self::clearOpensslErrors();
            throw new WebPushError('crypto');
        }

        $keyInfo = "WebPush: info\x00" . $uaPublicRaw . $asPublicRaw;
        $ikm   = hash_hkdf('sha256', $ecdh, 32, $keyInfo, $authSecret);
        $cek   = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\x00", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\x00", $salt);

        // 레코드 하나 — 끝 레코드 구분 바이트 0x02 (RFC 8188 2장 · 8291 4장)
        $tag = '';
        $cipher = openssl_encrypt($plaintext . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        if (!is_string($cipher) || strlen($tag) !== 16) {
            self::clearOpensslErrors();
            throw new WebPushError('crypto');
        }

        $header = $salt . pack('N', self::RECORD_SIZE) . chr(strlen($asPublicRaw)) . $asPublicRaw;
        return $header . $cipher . $tag;
    }

    // ─── RFC 8292 VAPID ──────────────────────────────────────────

    /**
     * VAPID JWT — `{typ:"JWT", alg:"ES256"}` · `{aud, exp, sub}` · ES256 서명(r ‖ s 64바이트, JWS 모양).
     * `aud` 는 받기 주소의 **스킴 + 호스트**뿐이다(경로·토큰을 싣지 않는다 — audienceOf).
     */
    public static function vapidJwt(string $audience, int $expiresAt, OpenSSLAsymmetricKey $signKey, string $subject = self::VAPID_SUBJECT): string
    {
        if (preg_match('#^https://[a-z0-9.-]{1,253}$#', $audience) !== 1) {
            throw new WebPushError('crypto');
        }
        $head = self::b64uEncode((string) json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $body = self::b64uEncode((string) json_encode(
            ['aud' => $audience, 'exp' => $expiresAt, 'sub' => $subject],
            JSON_UNESCAPED_SLASHES
        ));
        $input = $head . '.' . $body;
        $der = '';
        if (!openssl_sign($input, $der, $signKey, OPENSSL_ALGO_SHA256)) {
            self::clearOpensslErrors();
            throw new WebPushError('crypto');
        }
        return $input . '.' . self::b64uEncode(self::derToRs($der));
    }

    /** `Authorization: vapid t=<JWT>, k=<공개 키 base64url>` 의 값 (RFC 8292 3장) */
    public static function vapidAuthorization(string $jwt, string $publicRaw): string
    {
        return 'vapid t=' . $jwt . ', k=' . self::b64uEncode($publicRaw);
    }

    /** 받기 주소의 `aud` — 검사를 통과한(Fetch::pushEndpoint) 주소의 스킴 + 호스트 */
    public static function audienceOf(string $endpoint): string
    {
        $host = parse_url($endpoint, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            throw new WebPushError('crypto');
        }
        return 'https://' . strtolower($host);
    }

    /**
     * ECDSA 서명 DER(SEQUENCE { INTEGER r, INTEGER s }) → r ‖ s (각 32바이트, JWS ES256 모양).
     * 모양이 어긋나면 crypto 로 거부한다(짧은 길이 형식만 — P-256 서명은 72바이트를 넘지 않는다).
     */
    public static function derToRs(string $der): string
    {
        $n = strlen($der);
        if ($n < 8 || $der[0] !== "\x30") {
            throw new WebPushError('crypto');
        }
        $i = 1;
        $seqLen = ord($der[$i++]);
        if ($seqLen & 0x80) {
            if ($seqLen !== 0x81 || $i >= $n) {
                throw new WebPushError('crypto');
            }
            $seqLen = ord($der[$i++]);
        }
        if ($i + $seqLen !== $n) {
            throw new WebPushError('crypto');
        }
        $out = '';
        for ($k = 0; $k < 2; $k++) {
            if ($i + 2 > $n || $der[$i] !== "\x02") {
                throw new WebPushError('crypto');
            }
            $len = ord($der[$i + 1]);
            $i += 2;
            if ($len < 1 || $len > 33 || $i + $len > $n) {
                throw new WebPushError('crypto');
            }
            $int = ltrim(substr($der, $i, $len), "\x00");
            $i += $len;
            if (strlen($int) > 32) {
                throw new WebPushError('crypto');
            }
            $out .= str_pad($int, 32, "\x00", STR_PAD_LEFT);
        }
        if ($i !== $n) {
            throw new WebPushError('crypto');
        }
        return $out;
    }

    /** r ‖ s(64바이트) → DER. 앞의 0 을 떼고, 맨 앞 비트가 서 있으면 0x00 을 붙인다(양수 INTEGER). */
    public static function rsToDer(string $rs): string
    {
        if (strlen($rs) !== 64) {
            throw new WebPushError('crypto');
        }
        $body = '';
        foreach ([substr($rs, 0, 32), substr($rs, 32, 32)] as $int) {
            $int = ltrim($int, "\x00");
            if ($int === '') {
                $int = "\x00";
            }
            if (ord($int[0]) & 0x80) {
                $int = "\x00" . $int;
            }
            $body .= "\x02" . chr(strlen($int)) . $int;
        }
        return "\x30" . chr(strlen($body)) . $body;
    }

    // ─── 알림 내용 · 기기 요약 ───────────────────────────────────

    /**
     * 알림 내용 JSON (C장 4) — `{v:1, kind, rule, name, origin, at, n}`. 1KB 를 넘으면 거부.
     * 값은 부르는 쪽(보내는 배치)이 **코드 표**에서 고른 것뿐이다 — 경보 근거(evidence)의 글자·계정명·IP·파일명·
     * 명령을 옮기지 않는다(L-1 · 금지 패턴). 여기서도 모양을 한 번 더 좁힌다.
     *
     * @param array{kind:string, rule:?string, name:?string, origin:?string, at:string, n:int} $f
     */
    public static function payload(array $f): string
    {
        $kinds = ['alert', 'digest', 'test', 'more'];
        $kind = (string) ($f['kind'] ?? '');
        if (!in_array($kind, $kinds, true)) {
            throw new WebPushError('crypto');
        }
        $rule = $f['rule'] ?? null;
        if ($rule !== null && preg_match('/^AL-[0-9]{1,2}$/', (string) $rule) !== 1) {
            throw new WebPushError('crypto');
        }
        $name = $f['name'] ?? null;
        if ($name !== null && (!is_string($name) || preg_match('//u', $name) !== 1 || iconv_strlen($name, 'UTF-8') > 40)) {
            throw new WebPushError('crypto');
        }
        $origin = $f['origin'] ?? null;
        if ($origin !== null && !in_array($origin, ['human', 'system', 'claude-review', 'claude-session'], true)) {
            throw new WebPushError('crypto');
        }
        $at = (string) ($f['at'] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}([+-]\d{2}:\d{2}|Z)$/', $at) !== 1) {
            throw new WebPushError('crypto');
        }
        $n = (int) ($f['n'] ?? 1);
        if ($n < 0 || $n > 100000) {
            throw new WebPushError('crypto');
        }
        $json = json_encode([
            'v'      => 1,
            'kind'   => $kind,
            'rule'   => $rule,
            'name'   => $name,
            'origin' => $origin,
            'at'     => $at,
            'n'      => $n,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || strlen($json) > self::MAX_PAYLOAD_BYTES) {
            throw new WebPushError('too_large');
        }
        return $json;
    }

    /**
     * UA → 기기 요약(고정 글자 중 하나, D 표 `device_hint` ≤ 40자). UA 원문은 어디에도 저장하지 않는다.
     * 판정은 편의값일 뿐이다(UA 는 요청이 정한다) — 권한에 쓰지 않고 화면의 기기 목록 이름으로만 보인다.
     */
    public static function deviceHint(?string $userAgent): string
    {
        $ua = strtolower((string) $userAgent);
        $browser = str_contains($ua, 'firefox') ? 'Firefox'
            : (str_contains($ua, 'edg/') ? 'Edge'
            : (str_contains($ua, 'samsungbrowser') ? 'Samsung Internet'
            : (str_contains($ua, 'chrome') || str_contains($ua, 'crios') ? 'Chrome'
            : (str_contains($ua, 'safari') ? 'Safari' : ''))));
        foreach (self::DEVICE_HINTS as $needle => $label) {
            if (str_contains($ua, $needle)) {
                if ($needle === 'iphone' || $needle === 'ipad') {
                    return $label;
                }
                return $browser === '' ? $label : $label . ' ' . $browser;
            }
        }
        return $browser === '' ? '기타 브라우저' : $browser;
    }

    /**
     * 받기 주소 검사 — **네트워크 없음**. 등록 창구(push_subscriptions.php)가 부른다.
     * 규칙 한 벌은 `Fetch::pushEndpoint` 다(Fetch::check 의 ①②④ + PUSH_HOSTS 정확 일치 — DNS 를 묻지 않는다).
     * 핸들러가 Fetch 를 직접 부르지 않게 여기서 한 번 감싼다 — 웹 핸들러에 `Fetch::` 가 0 이어야 「웹은 밖으로 나가지 않는다」를
     * grep 한 번으로 확인할 수 있다(지시서 G F70-T3). 이 함수도 보내지 않는다.
     *
     * @return array{ok:bool, reason:?string, host:?string, url:?string}
     *         reason: bad_request(길이·모양) · bad_endpoint(스킴·포트·사용자정보·IP·표기) · host_not_allowed(host = 정규화한 호스트)
     */
    public static function checkEndpoint(string $url): array
    {
        if (!class_exists('Fetch', false)) {
            require_once __DIR__ . '/Fetch.php';
        }
        try {
            $c = Fetch::pushEndpoint($url);
        } catch (FetchDenied $e) {
            if ($e->reason === 'host_not_allowed' && $e->host !== null) {
                return ['ok' => false, 'reason' => 'host_not_allowed', 'host' => $e->host, 'url' => null];
            }
            return ['ok' => false, 'reason' => $e->reason === 'bad_request' ? 'bad_request' : 'bad_endpoint', 'host' => null, 'url' => null];
        }
        return ['ok' => true, 'reason' => null, 'host' => $c['host'], 'url' => $c['url']];
    }

    /** openssl 오류 줄을 비운다 — 다음 openssl 호출이 앞의 실패 문구를 물려받지 않게(문구는 어디에도 적지 않는다) */
    private static function clearOpensslErrors(): void
    {
        while (openssl_error_string() !== false) {
            // 비우기만 한다
        }
    }
}
