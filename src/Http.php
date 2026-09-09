<?php
declare(strict_types=1);

/**
 * src/Http.php — 요청 파싱과 응답 통일 (PRD S-01 실패 응답, S-09-5, S-09-7)
 *
 * 오류 본문은 언제나 {"error":"..."} 한 가지 모양이다.
 * 특히 401/403/404 는 대상에 대한 어떤 정보도 담지 않는다
 * (파일이 있는데 권한이 없는 것인지, 아예 없는 것인지 구분되면 그것만으로 정보 유출이다).
 */
final class Http
{
    /** 권한 관련 실패에 쓰는 단일 문구 (PRD 7.1 / Q-6 결정) */
    public const MSG_FORBIDDEN    = '권한이 없습니다';
    public const MSG_UNAUTHORIZED = '로그인이 필요합니다';
    public const MSG_NOT_FOUND    = '찾을 수 없습니다';

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
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        if (!is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        return $ip;
    }

    public static function userAgent(): ?string
    {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
        if (!is_string($ua) || $ua === '') {
            return null;
        }
        return substr($ua, 0, 500);
    }

    /**
     * 상태를 바꾸는 요청(POST/PUT/PATCH/DELETE)의 CSRF 토큰 검사 (PRD S-09-3).
     *
     * 1단계에는 아직 세션과 토큰 발급이 없다. 그래서 지금은
     * "세션이 없으면 통과시키지 않는다"만 강제한다 — 세션 없이 상태 변경 요청이
     * 핸들러까지 도달하는 경로를 처음부터 만들지 않기 위함이다.
     * TODO(2단계): 세션에 저장한 토큰과 X-CSRF-Token 헤더를 hash_equals 로 비교, 불일치 403.
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
        // TODO(2단계): 토큰 비교
    }
}
