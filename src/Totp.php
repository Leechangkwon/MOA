<?php
declare(strict_types=1);

/**
 * src/Totp.php — 2단계 인증 코드 (TOTP, RFC 6238 / HOTP, RFC 4226) — 10단계, SECURITY R-05
 *
 * 왜 직접 구현하나
 *   외부 라이브러리를 넣지 않는다(PRD S-10-C 공급망 최소화). TOTP 는 표준 해시 함수
 *   `hash_hmac('sha1')` 한 줄이 본체라, 의존성을 늘리는 것보다 40줄을 읽을 수 있게 두는 편이 낫다.
 *
 * 규격 (인증 앱 — Google Authenticator·Aegis·1Password 등이 기본으로 쓰는 값)
 *   · 비밀: 20바이트 무작위 → base32(RFC 4648, 패딩 없음) 32자
 *   · 해시: HMAC-SHA1, 시간 단위 30초, 6자리
 *   · 허용 오차: 없음(현재 구간만). 2026-09-15 까지는 앞뒤 1스텝(±30초)이었다 — 사용자 결정으로 좁힘.
 *     더 넓히면 훔쳐본 코드의 유효 시간이 길어진다.
 *
 * 이 파일이 하지 않는 것
 *   · 외부 요청. QR 이미지도 만들지 않는다 — 화면은 `otpauthUri()` 문자열을 **글자 그대로**
 *     보여 주고 사용자가 인증 앱에 손으로 옮겨 적는다(QR 라이브러리를 넣지 않기 위해).
 *   · 비밀 저장·검증 순서 판단. 저장은 `users.mfa_secret`, 로그인 검증 순서는 `src/Auth.php` 다.
 *   · 로그 기록. 비밀도 코드도 어디에도 남기지 않는다 (PRD 7.7 민감정보 금지).
 */
final class Totp
{
    /** 코드가 바뀌는 주기(초) — RFC 6238 권장값 */
    public const PERIOD = 30;

    /** 코드 자릿수 */
    public const DIGITS = 6;

    /** 비밀 길이(바이트). RFC 4226 은 최소 16, 권장 20 */
    private const SECRET_BYTES = 20;

    /** 인증 앱 화면에 보이는 발급자 이름 */
    private const ISSUER = 't-box';

    /** RFC 4648 base32 알파벳 */
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** 입력으로 들어오는 코드의 형태 — 6자리 숫자만 */
    public const CODE_RE = '/^[0-9]{6}$/';

    /** 저장·전달되는 비밀의 형태 — base32 대문자 16~64자 */
    public const SECRET_RE = '/^[A-Z2-7]{16,64}$/';

    /**
     * 새 비밀을 만든다. 20바이트 무작위 → base32 32자.
     * 만들어진 값은 호출자가 DB 에 넣고 **한 번만** 사용자에게 보여 준다.
     */
    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(self::SECRET_BYTES));
    }

    /**
     * 지금(또는 주어진 시각)의 코드 6자리.
     *
     * @param string   $secret base32 비밀
     * @param int|null $time   유닉스 시각. null 이면 현재
     */
    public static function code(string $secret, ?int $time = null): string
    {
        $key = self::base32Decode($secret);
        if ($key === '') {
            throw new RuntimeException('TOTP 비밀 형식이 올바르지 않습니다');
        }
        $counter = intdiv($time ?? time(), self::PERIOD);
        return self::hotp($key, $counter);
    }

    /**
     * 코드가 맞나. 앞뒤 $window 스텝까지 인정한다.
     * 기본 0 = **현재 30초 구간만** (사용자 결정 2026-09-15: 생성기가 서버 시각과 동기화되므로
     * 화면에 보이는 남은 초와 서버가 받아 주는 구간을 똑같이 한다. 시계가 어긋난 기기는 실패한다).
     *
     * 비교는 `hash_equals` 로 한다 — `===` 는 첫 글자가 다르면 바로 끝나 응답 시간이 달라지고,
     * 그 차이로 코드를 한 자리씩 맞춰 갈 수 있다.
     * 후보를 **전부** 돌린 뒤 결과를 돌려주어(중간에 return 하지 않는다) 몇 번째에서 맞았는지도
     * 시간으로 드러나지 않게 한다.
     */
    public static function verify(string $secret, string $code, int $window = 0, ?int $time = null): bool
    {
        return self::verifyStep($secret, $code, $window, $time) !== null;
    }

    /**
     * 코드가 맞으면 **맞은 스텝 번호**(= 유닉스 시각 ÷ 30 의 몫)를, 틀리면 null 을 돌려준다
     * (13단계 — SECURITY R-45, PRD T-31).
     *
     * 왜 스텝을 돌려주나
     *   `verify()` 는 "맞다/틀리다" 만 알려 주므로, 같은 코드를 90초(±1 창) 안에 몇 번이든
     *   다시 쓸 수 있었다(2026-09-12 실측: 같은 코드로 연속 2회 로그인 200). RFC 6238 §5.2 는
     *   "한 번 쓴 OTP 는 거부" 를 요구한다. 거부하려면 **무엇을 썼는지**를 기억해야 하고,
     *   코드 6자리를 저장하는 것은 비밀을 저장하는 것과 같으므로 대신 **스텝 번호**를 남긴다
     *   (`users.mfa_last_step`). 스텝은 시각에서 나오는 단조 증가 정수라 되돌릴 수 없고,
     *   그 값만으로는 코드를 만들 수 없다(비밀이 있어야 한다).
     *
     * 비교는 `verify()` 와 같은 이유로 `hash_equals` 이고, 맞은 뒤에도 후보를 **전부** 돌린다 —
     * 몇 번째에서 맞았는지가 응답 시간으로 드러나지 않게 한다.
     * 창 안에 맞는 스텝은 하나뿐이다(코드 6자리가 같은 창에서 겹칠 확률은 무시한다).
     * 여러 개가 맞으면 **가장 큰 스텝**을 돌려준다 — 재사용 판정을 느슨한 쪽이 아니라
     * 엄격한 쪽(더 높은 기준선)으로 남기기 위해서다.
     */
    public static function verifyStep(string $secret, string $code, int $window = 0, ?int $time = null): ?int
    {
        if (preg_match(self::CODE_RE, $code) !== 1) {
            return null;
        }
        if (preg_match(self::SECRET_RE, $secret) !== 1) {
            return null;
        }
        if ($window < 0 || $window > 10) {
            $window = 0;
        }

        $key = self::base32Decode($secret);
        if ($key === '') {
            return null;
        }

        $now  = intdiv($time ?? time(), self::PERIOD);
        $step = null;
        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals(self::hotp($key, $now + $i), $code)) {
                $step = $now + $i;
            }
        }
        return $step;
    }

    /**
     * 인증 앱에 넣을 문자열 (otpauth URI). 화면은 이 값을 글자 그대로 보여 준다.
     * 사용자 이름은 DB 에서 읽은 값이고 `^[a-z0-9_.-]+$` 형태지만, URI 에 넣기 전에
     * rawurlencode 를 거친다 — 값이 구분자(`:`·`?`·`&`)로 해석될 여지를 없앤다.
     */
    public static function otpauthUri(string $username, string $secret): string
    {
        if (preg_match(self::SECRET_RE, $secret) !== 1) {
            throw new RuntimeException('TOTP 비밀 형식이 올바르지 않습니다');
        }
        $label = rawurlencode(self::ISSUER) . ':' . rawurlencode($username);
        return 'otpauth://totp/' . $label
            . '?secret=' . rawurlencode($secret)
            . '&issuer=' . rawurlencode(self::ISSUER)
            . '&algorithm=SHA1'
            . '&digits=' . self::DIGITS
            . '&period=' . self::PERIOD;
    }

    /**
     * HOTP (RFC 4226 §5.3) — HMAC-SHA1 결과에서 4바이트를 골라 6자리로 자른다.
     *
     * @param string $key     원본(디코딩된) 비밀
     * @param int    $counter 시간 스텝 번호
     */
    private static function hotp(string $key, int $counter): string
    {
        // 8바이트 big-endian. PHP 8.1 의 pack('J') 는 64비트 big-endian 이다.
        $mac    = hash_hmac('sha1', pack('J', $counter), $key, true);
        $offset = ord($mac[19]) & 0x0f;
        $value  = ((ord($mac[$offset])     & 0x7f) << 24)
                | ((ord($mac[$offset + 1]) & 0xff) << 16)
                | ((ord($mac[$offset + 2]) & 0xff) << 8)
                |  (ord($mac[$offset + 3]) & 0xff);
        return str_pad((string) ($value % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /** 바이트열 → base32 (패딩 없음). 20바이트면 정확히 32자가 나온다. */
    private static function base32Encode(string $bytes): string
    {
        $bits = '';
        $len  = strlen($bytes);
        for ($i = 0; $i < $len; $i++) {
            $bits .= str_pad(decbin(ord($bytes[$i])), 8, '0', STR_PAD_LEFT);
        }
        $out   = '';
        $total = strlen($bits);
        for ($i = 0; $i + 5 <= $total; $i += 5) {
            $out .= self::ALPHABET[bindec(substr($bits, $i, 5))];
        }
        $rest = $total % 5;
        if ($rest !== 0) {
            $out .= self::ALPHABET[bindec(str_pad(substr($bits, $total - $rest), 5, '0', STR_PAD_RIGHT))];
        }
        return $out;
    }

    /** base32 → 바이트열. 알파벳 밖의 글자가 있으면 빈 문자열(= 실패)을 돌려준다. */
    private static function base32Decode(string $text): string
    {
        $text = rtrim($text, '=');
        if ($text === '') {
            return '';
        }
        $bits = '';
        $len  = strlen($text);
        for ($i = 0; $i < $len; $i++) {
            $pos = strpos(self::ALPHABET, $text[$i]);
            if ($pos === false) {
                return '';
            }
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $out   = '';
        $total = strlen($bits);
        for ($i = 0; $i + 8 <= $total; $i += 8) {
            $out .= chr(bindec(substr($bits, $i, 8)));
        }
        return $out;
    }
}
