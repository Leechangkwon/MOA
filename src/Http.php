<?php
declare(strict_types=1);

/**
 * src/Http.php — 요청 파싱과 응답 통일 (PRD S-01 실패 응답, S-09-3, S-09-5, S-09-7)
 *
 * 오류 본문은 언제나 {"error":"..."} 한 가지 모양이다.
 * 특히 401/403/404 는 대상에 대한 어떤 정보도 담지 않는다
 * (파일이 있는데 권한이 없는 것인지, 아예 없는 것인지 구분되면 그것만으로 정보 유출이다).
 * 문구는 아래 상수뿐이다 — 예외 메시지·DB 오류·경로가 본문에 섞여 나가는 경로를 만들지 않는다.
 */
final class Http
{
    /** 권한 관련 실패에 쓰는 단일 문구 (PRD 7.1 / Q-6 결정) */
    public const MSG_FORBIDDEN    = '권한이 없습니다';
    public const MSG_UNAUTHORIZED = '로그인이 필요합니다';
    public const MSG_NOT_FOUND    = '찾을 수 없습니다';
    public const MSG_BAD_REQUEST  = '잘못된 요청입니다';

    /**
     * URL 로 가져오기(F-14) 실패 — 스킴·포트·호스트·리다이렉트·크기·검증·속도 제한이
     * **전부 이 문구 하나**다 (PRD S-05 ⑩ "응답 비노출"). 사유는 감사 로그에만 남고,
     * 상태 코드만 400 / 403 / 429 로 갈린다. 본문으로 사유가 갈라지면 그 자체가
     * "내부에 무엇이 있는지" 를 알려 주는 신호가 된다.
     */
    public const MSG_FETCH_FAILED = '가져올 수 없습니다';

    /** 로그인 실패 — 아이디 없음·비밀번호 틀림·비활성 계정이 모두 이 문구 하나다 (PRD 10장) */
    public const MSG_LOGIN_FAILED = '아이디 또는 비밀번호가 올바르지 않습니다';
    /** 계정 잠금 (PRD F-01, S-09-4, ARCH 4.0) */
    public const MSG_LOGIN_LOCKED = '로그인 시도가 너무 많습니다. 잠시 후 다시 시도하세요';

    /** JSON 본문 상한 — 로그인·삭제 같은 작은 요청만 JSON 으로 받는다 */
    private const MAX_JSON_BYTES = 65536;

    /** @param array<string,mixed> $data */
    public static function json(array $data, int $code = 200): void
    {
        if (!headers_sent()) {
            http_response_code($code);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
        }
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** 오류 응답 후 즉시 종료한다. 호출한 쪽이 실수로 계속 진행하는 것을 막는다. */
    public static function error(int $code, string $message): void
    {
        self::json(['error' => $message], $code);
        exit;
    }

    public static function method(): string
    {
        $m = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        return preg_match('/^[A-Z]{3,7}$/', $m) === 1 ? $m : 'GET';
    }

    /**
     * 요청 경로. 쿼리스트링을 떼고, 퍼센트 디코딩은 하지 않는다.
     * (디코딩하면 %2e%2e 같은 값이 경로 매칭을 통과할 여지가 생긴다)
     */
    public static function path(): string
    {
        $uri  = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = (string) (parse_url($uri, PHP_URL_PATH) ?? '/');
        if ($path === '') {
            $path = '/';
        }
        if (strlen($path) > 1) {
            $path = rtrim($path, '/');
            if ($path === '') {
                $path = '/';
            }
        }
        return $path;
    }

    public static function clientIp(): ?string
    {
        // 프록시 헤더(X-Forwarded-For)는 위조 가능하므로 읽지 않는다.
        // 실제 IP 복원은 nginx 가 신뢰하는 프록시 IP 에 대해서만 한다 (SECURITY R-30 · R-31).
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        if (!is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        return $ip;
    }

    /**
     * 요청을 보낸 도구 이름. **요청이 준 값이므로 언제나 위조 가능한 참고값**이다
     * (검토 행동 표식 `tbox-review/…` 도 그래서 "표식" 이지 인증이 아니다 — CLAUDE.md 3장).
     *
     * CLI(9단계 운영 배치)에는 HTTP 헤더가 아예 없다. null 로 두면 감사 로그에서 배치 행과
     * "UA 를 안 보낸 브라우저" 가 구별되지 않으므로, CLI 일 때만 `cli:스크립트명` 을 넣는다
     * (ARCH 7.5 route 키의 `cli:스크립트명` 과 같은 표기). 스크립트 이름은 서버 파일명이지
     * 사용자 입력이 아니고, 그래도 basename 으로 경로를 떼고 형태를 좁혀 쓴다.
     */
    public static function userAgent(): ?string
    {
        if (PHP_SAPI === 'cli') {
            $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ($_SERVER['argv'][0] ?? '')));
            $script = (string) preg_replace('/[^A-Za-z0-9._-]/', '', $script);
            return $script === '' ? 'cli' : 'cli:' . substr($script, 0, 100);
        }
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
        if (!is_string($ua) || $ua === '') {
            return null;
        }
        return substr($ua, 0, 500);
    }

    /**
     * 화면 경로용 리다이렉트 (ARCH 4.0 "미로그인 처리": /api/* 는 401, 화면은 302 /login).
     *
     * $path 는 **코드에 적힌 고정 문자열만** 넘긴다. 사용자 입력을 넣을 수 없도록
     * 허용 형태(슬래시로 시작하는 소문자 경로)를 여기서 한 번 더 검사한다 —
     * 오픈 리다이렉트(`//evil.example`, `http://…`)가 생길 통로를 없앤다.
     */
    public static function redirect(string $path): void
    {
        if (preg_match('#^/[a-z0-9/_.-]*$#', $path) !== 1 || strpos($path, '//') !== false) {
            throw new RuntimeException('허용되지 않는 리다이렉트 경로입니다');
        }
        if (!headers_sent()) {
            http_response_code(302);
            header('Location: ' . $path);
            header('Cache-Control: no-store');
        }
        exit;
    }

    /**
     * JSON 본문 파싱. 폼 인코딩(application/x-www-form-urlencoded, multipart)은 받지 않는다 —
     * 크로스사이트에서 자동 전송할 수 있는 형식을 상태 변경 API 가 받지 않게 하는 것이 목적이다
     * (ARCH 4.0 "로그인 CSRF"). 형식이 어긋나면 전부 400 동일 문구다.
     *
     * @return array<string,mixed>
     */
    public static function jsonBody(): array
    {
        $ctype = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
        if (stripos($ctype, 'application/json') !== 0) {
            self::error(400, self::MSG_BAD_REQUEST);
        }
        $raw = file_get_contents('php://input', false, null, 0, self::MAX_JSON_BYTES + 1);
        if ($raw === false) {
            self::error(400, self::MSG_BAD_REQUEST);
        }
        if (strlen($raw) > self::MAX_JSON_BYTES) {
            self::error(400, self::MSG_BAD_REQUEST);
        }
        $data = json_decode($raw, true, 8, JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_array($data)) {
            self::error(400, self::MSG_BAD_REQUEST);
        }
        return $data;
    }

    /**
     * 세션 쿠키 발급 (PRD S-09-2, ARCH 4.0).
     *   HttpOnly  — JS 가 읽지 못한다 (XSS 로 토큰을 훔쳐 갈 수 없다)
     *   Secure    — 평문 http 로는 전송되지 않는다
     *   SameSite=Lax — 크로스사이트 POST 에 쿠키가 실리지 않는다
     *   Path=/    — 도메인 안 모든 경로
     *   만료 없음 — 브라우저 세션 쿠키. 수명 판단은 언제나 DB(sessions)가 한다
     */
    public static function setSessionCookie(string $token): void
    {
        self::sendCookie(Auth::COOKIE_NAME, $token, false);
    }

    /** 쿠키 즉시 만료 (Max-Age=0). 속성은 발급 때와 같아야 브라우저가 같은 쿠키로 인식한다. */
    public static function clearSessionCookie(): void
    {
        self::sendCookie(Auth::COOKIE_NAME, '', true);
    }

    private static function sendCookie(string $name, string $value, bool $expire): void
    {
        if (headers_sent()) {
            throw new RuntimeException('헤더가 이미 전송되어 쿠키를 설정할 수 없습니다');
        }
        $options = [
            'path'     => '/',
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ];
        if ($expire) {
            $options['expires'] = 1;   // 1970-01-01 → 브라우저가 즉시 버린다
        }
        setcookie($name, $value, $options);
    }

    /**
     * 상태를 바꾸는 요청(POST/PUT/PATCH/DELETE)의 CSRF 토큰 검사 (PRD S-09-3, ARCH 4.0).
     *
     * 토큰은 저장하지 않고 세션 해시 + APP_SECRET 으로 계산한다(Auth::csrfToken).
     * 클라이언트는 GET /api/me 로 받아 X-CSRF-Token 헤더에 실어 보낸다.
     * 누락·불일치는 대상 정보를 담지 않는 403 동일 문구다.
     */
    public static function requireCsrf(): void
    {
        $method = self::method();
        if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return;
        }
        if (Auth::currentUser() === null) {
            self::error(401, self::MSG_UNAUTHORIZED);
        }

        $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        // hash_equals 는 길이가 다르면 false 를 낸다. 시간차 비교(==)를 쓰지 않는다.
        if (!is_string($sent) || !hash_equals(Auth::csrfToken(), $sent)) {
            Audit::deny('csrf', 'token_mismatch', [
                'target_type' => 'system',
                // 실제 경로가 아니라 라우트 패턴을 남긴다 (SECURITY R-36, ARCH 7.5 route).
                // 문장 틀(ARCH 7.4 `csrf|deny|token_mismatch`)은 그대로이고 값만 패턴으로 바뀐다.
                'target_name' => defined('TBOX_ROUTE')
                    ? (string) constant('TBOX_ROUTE')
                    : $method . ' ' . self::path(),
            ]);
            self::error(403, self::MSG_FORBIDDEN);
        }
    }

    /* ─── 쿼리스트링 읽기 (3단계, 목록·검색용) ─────────────────────
     * 세 함수의 공통 원칙: **거부하지 않고 안전한 값으로 좁힌다.**
     *   검색·정렬·페이지 파라미터는 사용자가 URL 을 손으로 고치기 쉬운 자리다.
     *   여기서 400 을 내면 화면이 깨질 뿐이고 막아지는 것은 없다. 대신
     *   길이를 자르고, 허용 목록 밖 값은 기본값으로 바꾸고, 정수 형태만 인정한다
     *   (ARCH 5.1 [2][3], PRD S-04 "ORDER BY 는 허용 목록").
     */

    /** 문자열 쿼리 — 문자열이 아니면 '', 제어문자 제거, $maxChars 자로 자른다 */
    public static function queryText(string $key, int $maxChars): string
    {
        $raw = $_GET[$key] ?? '';
        if (!is_string($raw) || $raw === '') {
            return '';
        }
        // 잘못된 UTF-8 은 DB(UTF8)·JSON 모두에서 문제를 만든다 → 값을 버린다
        if (preg_match('//u', $raw) !== 1) {
            return '';
        }
        $clean = (string) preg_replace('/[\x00-\x1f\x7f]+/u', ' ', $raw);
        $clean = trim($clean);
        if ($clean === '') {
            return '';
        }
        if (iconv_strlen($clean, 'UTF-8') > $maxChars) {
            $clean = (string) iconv_substr($clean, 0, $maxChars, 'UTF-8');
        }
        return $clean;
    }

    /**
     * 허용 목록 안의 값만 통과시킨다. 밖의 값·누락은 $default (400 이 아니다).
     *
     * @param list<string> $allowed 코드에 적힌 고정 목록
     */
    public static function queryChoice(string $key, array $allowed, string $default): string
    {
        $raw = $_GET[$key] ?? null;
        if (is_string($raw) && in_array($raw, $allowed, true)) {
            return $raw;
        }
        return $default;
    }

    /** 정수 쿼리 — 숫자 형태가 아니거나 범위를 벗어나면 $default */
    public static function queryInt(string $key, int $default, int $min, int $max): int
    {
        $raw = $_GET[$key] ?? null;
        if (!is_string($raw) || preg_match('/^[0-9]{1,9}$/', $raw) !== 1) {
            return $default;
        }
        $value = (int) $raw;
        if ($value < $min || $value > $max) {
            return $default;
        }
        return $value;
    }
}
