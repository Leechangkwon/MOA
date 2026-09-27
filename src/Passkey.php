<?php
declare(strict_types=1);

require_once __DIR__ . '/WebPush.php';

/**
 * 패스키 검증 실패 — 메시지는 **사유 코드**(`passkey_invalid`·`passkey_challenge` …) 하나다.
 * 부르는 쪽이 사유를 감사에만 남기고 응답은 통일 문구로 끝낸다(열쇠 id·서명·본문은 어디에도 싣지 않는다).
 */
final class PasskeyError extends RuntimeException
{
}

/** CBOR 바이트 문자열 — 글자 문자열(PHP string)과 갈라 두려고 감싼다 */
final class PasskeyBytes
{
    public function __construct(public readonly string $v)
    {
    }
}

/**
 * CBOR 맵 — 키를 `i:<정수>` · `s:<글자>` 로 적어 둔다. 정수 1 과 글자 "1" 이 PHP 배열에서 한 키로 합쳐지지 않게
 * (같은 키 두 번 거부가 정확하려면 둘이 달라야 한다).
 */
final class PasskeyMap
{
    /** @param array<string,mixed> $items */
    public function __construct(public readonly array $items)
    {
    }

    public function get(string $key): mixed
    {
        return array_key_exists($key, $this->items) ? $this->items[$key] : null;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->items);
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->items);
    }
}

/**
 * src/Passkey.php — 휴대폰 지문·얼굴로 로그인(패스키 · WebAuthn)의 **서버 검증** (T:F-72, ARCH 5.35, SECURITY R-109)
 *
 * ★ 보안 핵심 파일(CLAUDE.md 1장 목록에 한 줄) — 지시서 「수정 파일」에 있을 때만 고친다.
 * 외부 라이브러리 0 (`deploy/DEPENDENCIES.md` 4장 「서버는 0」). 쓰는 것은 openssl·hash 와 이미 검토를 지난
 * `WebPush::publicKeyFromRaw`(65바이트 점 → P-256 공개 키, 곡선 위 점 확인) · `WebPush::b64uDecode`(엄격 base64url) —
 * **고치지 않고 부른다**.
 *
 * 하는 일(지시서 1.3 · 1.4 · 1.5)
 *   ① 설정 검사 `config()` — RP ID·origin 을 **`.env` APP_URL 에서만** 만든다(요청 머리 Host·Origin·Referer·X-Forwarded-* 0).
 *      `https://<호스트 이름>`(경로·쿼리·사용자정보 없음 · 포트 없거나 443) 또는 시험용 `http://localhost:<포트>` 만.
 *      IP 주소·그 밖은 **기능 꺼짐**(null — 창구 503 · `/api/me` passkey_available 거짓).
 *   ② CBOR 부분집합 해석 `cborDecode()` — 입력 ≤ 4KB · 주 형식 0·1·2·3·4·5 와 7 의 false/true/null 만 · 무한 길이·태그·실수·
 *      8바이트 길이·예약 값 거부 · 길이가 남은 입력보다 크면 거부 · 컨테이너 깊이 ≤ 4 · 맵 키는 정수·글자만, 같은 키 두 번 거부 ·
 *      UTF-8 이 아닌 글자 거부 · 소비한 바이트 수를 돌려주고 부르는 쪽이 「남는 바이트 0」 을 본다 · 실패는 PasskeyError 하나
 *   ③ authData 자르기 `parseAuthData()` — rpIdHash · 플래그(UP·UV·BE·BS·AT·ED) · 서명 횟수 · (AT) AAGUID·열쇠 id·COSE 키 ·
 *      (ED) 확장 맵은 읽고 버림 · 남는 바이트 0 · BS=1 이면 BE=1
 *   ④ COSE EC2 → 공개 키 `coseToSpki()` — kty 2 · alg -7 · crv 1 · x·y 32바이트 → 0x04‖x‖y → 곡선 위 점 확인 → SPKI DER 91바이트
 *   ⑤ 서명 `verifySignature()` — `openssl_verify(authData ‖ sha256(clientDataJSON), 서명, 공개 키, SHA256) === 1` **만** 통과
 *   ⑥ clientDataJSON `parseClientData()` — JSON(≤ 2KB · 깊이 ≤ 3) · type · challenge(base64url 32바이트) · origin · crossOrigin
 *   ⑦ 챌린지 표 — 32바이트 무작위 · 표에는 sha256 만 · **한 문장으로 소비**(used_at IS NULL AND 만료 전 AND 묶음 조건) ·
 *      만료 정리 · 살아 있는 로그인 챌린지 상한(5,000)
 *
 * 하지 않는 것: 증명(attestation) 검사 — 형식 `none` 만 받고 그 밖은 거부한다(검사 흉내 0) · 휴대폰인지 가리기(PK-D1 — 서버는
 * 가릴 수 없다: 증명 none · UA 는 요청이 정함 · 휴대폰 열쇠가 같은 계정의 PC 에도 동기화된다).
 * 기록 금지: 열쇠 id 원문·공개 키·서명·clientDataJSON·attestationObject·챌린지·로그인 전 쿠키 → 감사·app.log·응답 0.
 */
final class Passkey
{
    /** 한 사람이 가질 수 있는 살아 있는 열쇠 수 (지시서 1.6) */
    public const MAX_PER_USER = 5;
    /** 마지막 사용(없으면 등록일)이 이 날수를 넘으면 꺼진 열쇠 (지시서 1.6 — 찾는 SQL 조건, 배치 없음) */
    public const IDLE_DAYS = 90;
    /** 챌린지 수명(초) — 로그인 5분 · 등록·재인증 2분 (지시서 1.3) */
    public const LOGIN_TTL = 300;
    public const CEREMONY_TTL = 120;
    /** 브라우저에 주는 timeout(밀리초) — 수명과 같다 */
    public const LOGIN_TIMEOUT_MS = 300000;
    public const CEREMONY_TIMEOUT_MS = 120000;
    /** 살아 있는 로그인 챌린지 상한 — 넘으면 503(로그인 전 요청으로 표가 끝없이 자라지 않게) */
    public const MAX_LIVE_LOGIN = 5000;
    /** 로그인 전 쿠키 — `__Host-`(Secure · Path=/ · Domain 없음) · HttpOnly · SameSite=Strict · Max-Age 600 */
    public const PRE_COOKIE = '__Host-tbox_pk';
    public const PRE_COOKIE_MAX_AGE = 600;
    /** 받는 알고리즘 — ES256 하나 (RS256 -257 은 PC 단계) */
    public const ALG_ES256 = -7;
    /** transports 허용 낱말 */
    public const TRANSPORTS = ['internal', 'hybrid', 'usb', 'nfc', 'ble', 'smart-card'];

    /** 입력 상한(바이트) — clientDataJSON 2KB · attestationObject 4KB(CBOR 해석기 상한과 같다) */
    public const MAX_CLIENT_DATA = 2048;
    public const MAX_CBOR = 4096;
    /** 서명(DER) 상한 — P-256 ECDSA 는 72바이트 이하 */
    public const MAX_SIGNATURE = 72;
    /** 열쇠 id 길이(바이트) — DB CHECK 와 같다 */
    public const MIN_CREDENTIAL_ID = 16;
    public const MAX_CREDENTIAL_ID = 1023;

    private const FLAG_UP = 0x01;
    private const FLAG_UV = 0x04;
    private const FLAG_BE = 0x08;
    private const FLAG_BS = 0x10;
    private const FLAG_AT = 0x40;
    private const FLAG_ED = 0x80;

    private const CBOR_MAX_DEPTH = 4;

    /** @var array{rp_id:string,origin:string}|null|false false = 아직 안 읽음 */
    private static array|null|false $config = false;
    private static ?bool $ready = null;

    private const SQL_TABLES_READY = <<<'SQL'
        SELECT (to_regclass('public.passkeys') IS NOT NULL
                AND to_regclass('public.passkey_challenges') IS NOT NULL) AS ok
        SQL;

    private const SQL_PURGE = <<<'SQL'
        DELETE FROM passkey_challenges WHERE expires_at < now() - interval '10 minutes'
        SQL;

    private const SQL_LIVE_LOGIN = <<<'SQL'
        SELECT count(*) AS n
          FROM passkey_challenges
         WHERE purpose = 'login' AND used_at IS NULL AND expires_at > now()
        SQL;

    private const SQL_INSERT_CHALLENGE = <<<'SQL'
        INSERT INTO passkey_challenges (challenge_hash, purpose, user_id, session_id, binding_hash, expires_at)
        VALUES (:h, :p, :u, :s, :b, now() + make_interval(secs => CAST(:ttl AS integer)))
        SQL;

    /** 로그인 챌린지 소비 — 한 문장(지시서 1.3). 묶음 = 로그인 전 쿠키의 sha256 */
    private const SQL_CONSUME_LOGIN = <<<'SQL'
        UPDATE passkey_challenges
           SET used_at = now()
         WHERE challenge_hash = :h
           AND purpose = 'login'
           AND used_at IS NULL
           AND expires_at > now()
           AND binding_hash = :b
        RETURNING id
        SQL;

    /** 등록·재인증 챌린지 소비 — 한 문장. 묶음 = 그 세션 + 그 사람 */
    private const SQL_CONSUME_SESSION = <<<'SQL'
        UPDATE passkey_challenges
           SET used_at = now()
         WHERE challenge_hash = :h
           AND purpose = :p
           AND used_at IS NULL
           AND expires_at > now()
           AND user_id = :u
           AND session_id = :s
        RETURNING id
        SQL;

    /** 이 사람의 user_handle(첫 등록 뒤 재사용) — 해제된 열쇠의 값도 같은 사람의 것이다 */
    private const SQL_USER_HANDLE = <<<'SQL'
        SELECT encode(user_handle, 'hex') AS uh FROM passkeys WHERE user_id = :u ORDER BY id LIMIT 1
        SQL;

    /**
     * 설정 — RP ID·origin (지시서 1.3 「설정 검사」). **APP_URL 한 값**에서만 만들고, 요청 머리는 보지 않는다.
     *
     * @return array{rp_id:string,origin:string}|null  null = 기능 꺼짐(닫힌 쪽)
     */
    public static function config(): ?array
    {
        if (self::$config !== false) {
            return self::$config;
        }
        self::$config = self::configFrom(Env::getOrEmpty('APP_URL'));
        return self::$config;
    }

    /**
     * APP_URL 한 값 → 설정(시험이 값만 바꿔 부른다 — 상태 0).
     *
     * @return array{rp_id:string,origin:string}|null
     */
    public static function configFrom(string $url): ?array
    {
        if ($url === '' || strlen($url) > 255 || preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
            return null;
        }
        $p = parse_url($url);
        if (!is_array($p) || !isset($p['scheme'], $p['host'])
            || isset($p['user']) || isset($p['pass']) || isset($p['query']) || isset($p['fragment'])
            || (isset($p['path']) && $p['path'] !== '' && $p['path'] !== '/')
        ) {
            return null;
        }
        $scheme = (string) $p['scheme'];
        $host   = (string) $p['host'];
        $port   = isset($p['port']) ? (int) $p['port'] : null;
        // 호스트는 소문자 이름만(대문자·IP·괄호 IPv6·밑줄·빈 이름표 0)
        if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false
            || preg_match('/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$/', $host) !== 1
        ) {
            return null;
        }
        if ($scheme === 'https') {
            if ($port !== null && $port !== 443) {
                return null;
            }
            if (strpos($host, '.') === false || preg_match('/\.[0-9]+$/', $host) === 1) {
                return null;   // 한 이름표 호스트·숫자로 끝나는 이름은 받지 않는다(IP 흉내)
            }
            return ['rp_id' => $host, 'origin' => 'https://' . $host];
        }
        if ($scheme === 'http' && $host === 'localhost' && $port !== null && $port >= 1 && $port <= 65535) {
            return ['rp_id' => 'localhost', 'origin' => 'http://localhost:' . $port];
        }
        return null;
    }

    /** 표 둘이 있나 — 요청 1회에 한 번(코드가 표보다 먼저 나가도 창구가 500 이 아니라 「꺼짐」이 되게) */
    public static function tablesReady(): bool
    {
        if (self::$ready !== null) {
            return self::$ready;
        }
        try {
            $row = Db::instance()->run(self::SQL_TABLES_READY)->fetch();
            self::$ready = is_array($row) && $row['ok'] === true;
        } catch (Throwable $e) {
            self::$ready = false;
        }
        return self::$ready;
    }

    /** 이 서버에서 지문·얼굴 로그인을 쓸 수 있나 — 설정 + 표 */
    public static function available(): bool
    {
        return self::config() !== null && self::tablesReady();
    }

    // ─── CBOR 부분집합 (지시서 1.5 「CBOR 부분집합 규칙」) ─────────────────────

    /**
     * @return array{0:mixed,1:int}  [값, 소비한 바이트 수]
     * @throws PasskeyError
     */
    public static function cborDecode(string $bin): array
    {
        if ($bin === '' || strlen($bin) > self::MAX_CBOR) {
            throw new PasskeyError('passkey_invalid');
        }
        $pos = 0;
        $v = self::cborItem($bin, $pos, 0);
        return [$v, $pos];
    }

    private static function cborItem(string $b, int &$pos, int $depth): mixed
    {
        $len = strlen($b);
        if ($pos >= $len) {
            throw new PasskeyError('passkey_invalid');
        }
        $ib    = ord($b[$pos]);
        $pos++;
        $major = $ib >> 5;
        $ai    = $ib & 0x1f;

        if ($major === 7) {
            // false(20)·true(21)·null(22) 만 — 실수(25·26·27)·undefined(23)·단순값(24)·예약(28~30)·break(31) 거부
            if ($ai === 20) {
                return false;
            }
            if ($ai === 21) {
                return true;
            }
            if ($ai === 22) {
                return null;
            }
            throw new PasskeyError('passkey_invalid');
        }
        if ($major === 6) {
            throw new PasskeyError('passkey_invalid');   // 태그
        }
        if ($ai < 24) {
            $arg = $ai;
        } elseif ($ai === 24) {
            $arg = self::cborUint($b, $pos, 1);
        } elseif ($ai === 25) {
            $arg = self::cborUint($b, $pos, 2);
        } elseif ($ai === 26) {
            $arg = self::cborUint($b, $pos, 4);
        } else {
            throw new PasskeyError('passkey_invalid');   // 27(8바이트) · 28~30(예약) · 31(무한 길이)
        }

        switch ($major) {
            case 0:
                return $arg;
            case 1:
                return -1 - $arg;
            case 2:
            case 3:
                if ($arg > $len - $pos) {
                    throw new PasskeyError('passkey_invalid');
                }
                $s = substr($b, $pos, $arg);
                $pos += $arg;
                if ($major === 2) {
                    return new PasskeyBytes($s);
                }
                if (preg_match('//u', $s) !== 1) {
                    throw new PasskeyError('passkey_invalid');   // 깨진 UTF-8
                }
                return $s;
            case 4:
                if ($depth + 1 > self::CBOR_MAX_DEPTH || $arg > $len - $pos) {
                    throw new PasskeyError('passkey_invalid');
                }
                $list = [];
                for ($i = 0; $i < $arg; $i++) {
                    $list[] = self::cborItem($b, $pos, $depth + 1);
                }
                return $list;
            case 5:
                if ($depth + 1 > self::CBOR_MAX_DEPTH || $arg * 2 > $len - $pos) {
                    throw new PasskeyError('passkey_invalid');
                }
                $items = [];
                for ($i = 0; $i < $arg; $i++) {
                    $k = self::cborItem($b, $pos, $depth + 1);
                    if (is_int($k)) {
                        $key = 'i:' . $k;
                    } elseif (is_string($k)) {
                        $key = 's:' . $k;
                    } else {
                        throw new PasskeyError('passkey_invalid');   // 맵 키는 정수·글자만
                    }
                    if (array_key_exists($key, $items)) {
                        throw new PasskeyError('passkey_invalid');   // 같은 키 두 번
                    }
                    $items[$key] = self::cborItem($b, $pos, $depth + 1);
                }
                return new PasskeyMap($items);
        }
        throw new PasskeyError('passkey_invalid');
    }

    private static function cborUint(string $b, int &$pos, int $n): int
    {
        if ($n > strlen($b) - $pos) {
            throw new PasskeyError('passkey_invalid');
        }
        $v = 0;
        for ($i = 0; $i < $n; $i++) {
            $v = ($v << 8) | ord($b[$pos + $i]);
        }
        $pos += $n;
        return $v;
    }

    // ─── authData (지시서 1.4 등록 4 · 로그인 5) ─────────────────────────────

    /**
     * @return array{rp_id_hash:string,up:bool,uv:bool,be:bool,bs:bool,at:bool,ed:bool,sign_count:int,
     *               aaguid:?string,credential_id:?string,cose:mixed}
     * @throws PasskeyError
     */
    public static function parseAuthData(string $ad): array
    {
        $len = strlen($ad);
        if ($len < 37 || $len > self::MAX_CBOR) {
            throw new PasskeyError('passkey_invalid');
        }
        $flags = ord($ad[32]);
        $count = unpack('N', substr($ad, 33, 4));
        $out = [
            'rp_id_hash'    => substr($ad, 0, 32),
            'up'            => ($flags & self::FLAG_UP) !== 0,
            'uv'            => ($flags & self::FLAG_UV) !== 0,
            'be'            => ($flags & self::FLAG_BE) !== 0,
            'bs'            => ($flags & self::FLAG_BS) !== 0,
            'at'            => ($flags & self::FLAG_AT) !== 0,
            'ed'            => ($flags & self::FLAG_ED) !== 0,
            'sign_count'    => is_array($count) ? (int) $count[1] : 0,
            'aaguid'        => null,
            'credential_id' => null,
            'cose'          => null,
        ];
        if ($out['bs'] && !$out['be']) {
            throw new PasskeyError('passkey_invalid');   // 동기화됨(BS)인데 동기화 가능(BE)이 아니다
        }
        $pos = 37;
        if ($out['at']) {
            if ($len < $pos + 18) {
                throw new PasskeyError('passkey_invalid');
            }
            $out['aaguid'] = substr($ad, $pos, 16);
            $pos += 16;
            $idLen = unpack('n', substr($ad, $pos, 2));
            $idLen = is_array($idLen) ? (int) $idLen[1] : 0;
            $pos += 2;
            if ($idLen < self::MIN_CREDENTIAL_ID || $idLen > self::MAX_CREDENTIAL_ID || $idLen > $len - $pos) {
                throw new PasskeyError('passkey_invalid');
            }
            $out['credential_id'] = substr($ad, $pos, $idLen);
            $pos += $idLen;
            if ($pos >= $len) {
                throw new PasskeyError('passkey_invalid');
            }
            [$cose, $used] = self::cborDecode(substr($ad, $pos));
            $out['cose'] = $cose;
            $pos += $used;
        }
        if ($out['ed']) {
            if ($pos >= $len) {
                throw new PasskeyError('passkey_invalid');
            }
            [$ext, $used] = self::cborDecode(substr($ad, $pos));
            if (!$ext instanceof PasskeyMap) {
                throw new PasskeyError('passkey_invalid');
            }
            $pos += $used;   // 확장은 읽고 버린다 — 쓰는 확장이 없다
        }
        if ($pos !== $len) {
            throw new PasskeyError('passkey_invalid');   // 남는 바이트
        }
        return $out;
    }

    /**
     * COSE EC2 P-256 ES256 → SPKI DER(91바이트). 곡선 위의 점인지는 `WebPush::publicKeyFromRaw` 가 본다(고치지 않고 부른다).
     * 다섯 칸(kty·alg·crv·x·y)이 꼭 있어야 하고 값이 하나라도 다르면 거부한다. 그 밖의 칸은 공개 키를 만드는 데 쓰지 않는다.
     *
     * @throws PasskeyError
     */
    public static function coseToSpki(mixed $cose): string
    {
        if (!$cose instanceof PasskeyMap) {
            throw new PasskeyError('passkey_invalid');
        }
        $x = $cose->get('i:-2');
        $y = $cose->get('i:-3');
        if ($cose->get('i:1') !== 2 || $cose->get('i:3') !== self::ALG_ES256 || $cose->get('i:-1') !== 1
            || !$x instanceof PasskeyBytes || !$y instanceof PasskeyBytes
            || strlen($x->v) !== 32 || strlen($y->v) !== 32
        ) {
            throw new PasskeyError('passkey_invalid');
        }
        try {
            $key = WebPush::publicKeyFromRaw("\x04" . $x->v . $y->v);
        } catch (Throwable $e) {
            throw new PasskeyError('passkey_invalid');   // 곡선 밖의 점
        }
        $d = openssl_pkey_get_details($key);
        $pem = is_array($d) ? (string) ($d['key'] ?? '') : '';
        $b64 = preg_replace('/-----[A-Z ]+-----|\s+/', '', $pem);
        $der = is_string($b64) ? base64_decode($b64, true) : false;
        if (!is_string($der) || strlen($der) !== 91) {
            throw new PasskeyError('passkey_invalid');
        }
        return $der;
    }

    /** SPKI DER → PEM (openssl_verify 가 받는 모양) */
    public static function spkiToPem(string $der): string
    {
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    /**
     * 서명 확인 — `openssl_verify(...) === 1` **만** 참(0·-1·false 는 거짓). PHP 경고·openssl 오류 대기열을 남기지 않는다.
     */
    public static function verifySignature(string $spkiDer, string $authData, string $clientDataJson, string $sig): bool
    {
        if ($sig === '' || strlen($sig) > self::MAX_SIGNATURE || strlen($spkiDer) !== 91) {
            return false;
        }
        $key = @openssl_pkey_get_public(self::spkiToPem($spkiDer));
        if ($key === false) {
            self::clearOpensslErrors();
            return false;
        }
        $r = @openssl_verify($authData . hash('sha256', $clientDataJson, true), $sig, $key, OPENSSL_ALGO_SHA256);
        self::clearOpensslErrors();
        return $r === 1;
    }

    /**
     * clientDataJSON — 모양만 보고 챌린지 원문(32바이트)과 origin 을 돌려준다. 챌린지 **소비**와 origin **대조**는 부르는 쪽
     * (소비를 먼저 해야 틀린 origin 으로 같은 문제를 다시 쓸 수 없다).
     *
     * @return array{challenge:string,origin:string}
     * @throws PasskeyError
     */
    public static function parseClientData(string $json, string $expectType): array
    {
        if ($json === '' || strlen($json) > self::MAX_CLIENT_DATA || preg_match('//u', $json) !== 1) {
            throw new PasskeyError('passkey_invalid');
        }
        $d = json_decode($json, true, 3);
        if (!is_array($d) || ($d !== [] && array_is_list($d))) {
            throw new PasskeyError('passkey_invalid');
        }
        if (($d['type'] ?? null) !== $expectType) {
            throw new PasskeyError('passkey_invalid');
        }
        $ch = $d['challenge'] ?? null;
        $raw = is_string($ch) ? WebPush::b64uDecode($ch) : null;
        if (!is_string($raw) || strlen($raw) !== 32 || !is_string($d['origin'] ?? null)) {
            throw new PasskeyError('passkey_invalid');
        }
        $cross = $d['crossOrigin'] ?? false;
        if ($cross !== false || array_key_exists('topOrigin', $d)) {
            throw new PasskeyError('passkey_invalid');   // 다른 사이트 틀 안에서 부른 것 — 받지 않는다
        }
        return ['challenge' => $raw, 'origin' => (string) $d['origin']];
    }

    /** origin 정확 일치(설정 한 값) */
    public static function originOk(string $origin): bool
    {
        $cfg = self::config();
        return $cfg !== null && hash_equals($cfg['origin'], $origin);
    }

    /** rpIdHash = sha256(RP ID) */
    public static function rpIdHashOk(string $rpIdHash): bool
    {
        $cfg = self::config();
        return $cfg !== null && hash_equals(hash('sha256', $cfg['rp_id'], true), $rpIdHash);
    }

    /**
     * 등록 응답의 attestationObject — `{fmt:"none", attStmt:{}, authData}` 만 · 남는 바이트 0 · rpIdHash · UP·UV·AT ·
     * COSE → SPKI. 증명 검사는 없다(형식 none 만 받는다).
     *
     * @return array{credential_id:string,public_key:string,sign_count:int,backup_eligible:bool,backed_up:bool,aaguid:?string}
     * @throws PasskeyError
     */
    public static function verifyAttestation(string $attObj): array
    {
        [$top, $used] = self::cborDecode($attObj);
        if ($used !== strlen($attObj) || !$top instanceof PasskeyMap) {
            throw new PasskeyError('passkey_invalid');
        }
        $keys = $top->keys();
        sort($keys);
        if ($keys !== ['s:attStmt', 's:authData', 's:fmt']) {
            throw new PasskeyError('passkey_invalid');
        }
        $stmt = $top->get('s:attStmt');
        $ad   = $top->get('s:authData');
        if ($top->get('s:fmt') !== 'none' || !$stmt instanceof PasskeyMap || $stmt->keys() !== []
            || !$ad instanceof PasskeyBytes
        ) {
            throw new PasskeyError('passkey_invalid');
        }
        $a = self::parseAuthData($ad->v);
        if (!self::rpIdHashOk($a['rp_id_hash']) || !$a['up'] || !$a['uv'] || !$a['at']
            || !is_string($a['credential_id'])
        ) {
            throw new PasskeyError('passkey_invalid');
        }
        $der = self::coseToSpki($a['cose']);
        $aaguid = null;
        if (is_string($a['aaguid']) && strlen($a['aaguid']) === 16) {
            $h = bin2hex($a['aaguid']);
            $aaguid = substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20);
        }
        return [
            'credential_id'   => $a['credential_id'],
            'public_key'      => $der,
            'sign_count'      => $a['sign_count'],
            'backup_eligible' => $a['be'],
            'backed_up'       => $a['bs'],
            'aaguid'          => $aaguid,
        ];
    }

    // ─── 챌린지 표 (지시서 1.3 · 1.7) ────────────────────────────────────────

    /** 만료 정리 — 옵션 창구마다 한 번(10분 넘게 지난 것) */
    public static function purge(Db $db): void
    {
        $db->run(self::SQL_PURGE);
    }

    /** 살아 있는 로그인 챌린지가 상한을 넘었나 */
    public static function loginBusy(Db $db): bool
    {
        $row = $db->run(self::SQL_LIVE_LOGIN)->fetch();
        return is_array($row) && (int) $row['n'] >= self::MAX_LIVE_LOGIN;
    }

    /**
     * 새 챌린지 — 32바이트 무작위를 돌려주고 표에는 sha256 만 넣는다.
     * 로그인: binding(로그인 전 쿠키 원문 — 표에는 sha256) · 등록·재인증: 사람 + 세션.
     */
    public static function issueChallenge(Db $db, string $purpose, ?int $userId, ?string $sessionId, ?string $binding, int $ttl): string
    {
        $raw = random_bytes(32);
        $db->run(self::SQL_INSERT_CHALLENGE, [
            ':h'   => hash('sha256', $raw),
            ':p'   => $purpose,
            ':u'   => $userId,
            ':s'   => $sessionId,
            ':b'   => $binding === null ? null : hash('sha256', $binding),
            ':ttl' => $ttl,
        ]);
        return $raw;
    }

    /** 로그인 챌린지 소비 — 쿠키 원문의 sha256 과 묶인 것만 · 한 번만 */
    public static function consumeLogin(Db $db, string $rawChallenge, string $preCookie): bool
    {
        $row = $db->run(self::SQL_CONSUME_LOGIN, [
            ':h' => hash('sha256', $rawChallenge),
            ':b' => hash('sha256', $preCookie),
        ])->fetch();
        return is_array($row);
    }

    /** 등록·재인증 챌린지 소비 — 이 세션 + 이 사람 · 한 번만 */
    public static function consumeSession(Db $db, string $rawChallenge, string $purpose, int $userId, string $sessionId): bool
    {
        if (!in_array($purpose, ['register', 'reauth'], true) || $sessionId === '') {
            return false;
        }
        $row = $db->run(self::SQL_CONSUME_SESSION, [
            ':h' => hash('sha256', $rawChallenge),
            ':p' => $purpose,
            ':u' => $userId,
            ':s' => $sessionId,
        ])->fetch();
        return is_array($row);
    }

    /**
     * 이 사람의 user_handle(열쇠 안의 계정 표지, 32바이트) — 이미 등록한 열쇠가 있으면 그 값, 없으면 서버 비밀의 HMAC.
     * 계정 번호·아이디가 아니고(비밀 없이는 되짚을 수 없다) 사람마다 고정이라, 같은 휴대폰에 같은 계정 열쇠가 겹겹이 쌓이지 않는다.
     * 옵션과 등록 두 요청이 **같은 값**을 얻는다(따로 저장할 자리가 없어도 된다).
     */
    public static function userHandleFor(Db $db, int $userId): string
    {
        $row = $db->run(self::SQL_USER_HANDLE, [':u' => $userId])->fetch();
        if (is_array($row) && is_string($row['uh']) && strlen($row['uh']) === 64) {
            $bin = hex2bin($row['uh']);
            if (is_string($bin)) {
                return $bin;
            }
        }
        return hash_hmac('sha256', 'qaredi-passkey-user-handle:' . $userId, Env::get('APP_SECRET'), true);
    }

    /** 로그인 전 쿠키 값 — 모양(64자 소문자 hex)이 맞을 때만 */
    public static function preCookie(): ?string
    {
        $v = $_COOKIE[self::PRE_COOKIE] ?? null;
        return is_string($v) && preg_match('/^[0-9a-f]{64}$/', $v) === 1 ? $v : null;
    }

    /**
     * 요청 본문의 base64url 칸 하나 — 엄격 해독(WebPush::b64uDecode) + 바이트 상한.
     *
     * @throws PasskeyError
     */
    public static function field(array $body, string $key, int $maxBytes, int $minBytes = 1): string
    {
        $v = $body[$key] ?? null;
        $raw = is_string($v) ? WebPush::b64uDecode($v) : null;
        if (!is_string($raw) || strlen($raw) < $minBytes || strlen($raw) > $maxBytes) {
            throw new PasskeyError('bad_request');
        }
        return $raw;
    }

    /** 화면에 보여 줄 이름(기기 이름) — 계정 이름과 같은 규칙(tbox_clean_display_name: 앞뒤 공백 지움 · 제어·서식 문자 거부 · 1~40자) */
    public static function cleanName(mixed $raw): ?string
    {
        [$ok, $name] = tbox_clean_display_name($raw);
        return $ok && is_string($name) && $name !== '' ? $name : null;
    }

    private static function clearOpensslErrors(): void
    {
        while (openssl_error_string() !== false) {
            // 대기열 비우기
        }
    }
}
