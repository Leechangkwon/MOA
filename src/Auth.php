<?php
declare(strict_types=1);

/**
 * src/Auth.php — "누구인가" (ARCH 4장 [1], PRD S-01, S-09-2, SECURITY R-10)
 *
 * 원칙
 *  1) 역할·팀은 요청에서 절대 읽지 않는다. 쿠키에는 무작위 토큰만 있고,
 *     그 토큰으로 DB sessions → users 를 매 요청 다시 조회해 role·team_id 를 얻는다.
 *  2) 쿠키 토큰 원문은 DB 에 저장하지 않는다. DB 에는 SHA-256 만 둔다
 *     (DB 가 유출돼도 세션을 위조할 수 없다 — ARCH 6.2-2).
 *  3) is_active = false 면 그 자리에서 세션 행을 지우고 401 — 비활성화가 즉시 반영된다.
 */
final class Auth
{
    public const COOKIE_NAME = 'tbox_session';

    /** 쿠키 토큰: random_bytes(32) 의 hex → 정확히 64자의 소문자 16진수 */
    private const TOKEN_RE = '/^[0-9a-f]{64}$/';

    private const SQL_LOOKUP = <<<'SQL'
        SELECT s.id            AS session_id,
               s.expires_at    AS expires_at,
               u.id            AS id,
               u.username      AS username,
               u.role          AS role,
               u.team_id       AS team_id,
               u.is_active     AS is_active
          FROM sessions s
          JOIN users   u ON u.id = s.user_id
         WHERE s.id = :id
           AND s.expires_at   > now()
           AND s.last_seen_at > now() - make_interval(mins => CAST(:idle_min AS integer))
        SQL;

    private const SQL_TOUCH = <<<'SQL'
        UPDATE sessions SET last_seen_at = now() WHERE id = :id
        SQL;

    private const SQL_DROP_SESSION = <<<'SQL'
        DELETE FROM sessions WHERE id = :id
        SQL;

    /** @var array<string,mixed>|null */
    private static ?array $user = null;
    private static bool $resolved = false;

    /**
     * 지금 요청의 사용자. 없으면 null. 요청 1회당 DB 조회 1회로 캐시한다.
     *
     * @return array<string,mixed>|null
     */
    public static function currentUser(): ?array
    {
        if (self::$resolved) {
            return self::$user;
        }
        self::$resolved = true;
        self::$user     = null;

        $token = $_COOKIE[self::COOKIE_NAME] ?? null;
        if (!is_string($token) || preg_match(self::TOKEN_RE, $token) !== 1) {
            return null;
        }
        $id  = hash('sha256', $token);
        $db  = Db::instance();
        $row = $db->run(self::SQL_LOOKUP, [
            ':id'       => $id,
            ':idle_min' => Env::getInt('SESSION_IDLE_MIN'),
        ])->fetch();

        if (!is_array($row)) {
            return null;
        }
        if ($row['is_active'] !== true) {
            // 비활성화된 계정의 살아 있는 세션은 즉시 파기 (SECURITY R-10)
            $db->run(self::SQL_DROP_SESSION, [':id' => $id]);
            return null;
        }

        $db->run(self::SQL_TOUCH, [':id' => $id]);

        self::$user = [
            'id'         => (int) $row['id'],
            'username'   => (string) $row['username'],
            'role'       => (string) $row['role'],
            'team_id'    => $row['team_id'] === null ? null : (int) $row['team_id'],
            'session_id' => (string) $row['session_id'],
        ];
        return self::$user;
    }

    /**
     * 로그인되어 있지 않으면 401 로 끝낸다 (ARCH 4장 [1]).
     *
     * @return array<string,mixed>
     */
    public static function requireLogin(): array
    {
        $u = self::currentUser();
        if ($u === null) {
            Audit::deny('auth.required', 'not_authenticated', [
                'target_type' => 'system',
                'target_name' => Http::method() . ' ' . Http::path(),
            ]);
            Http::error(401, Http::MSG_UNAUTHORIZED);
        }
        return $u;
    }

    /**
     * TODO(2단계 · F-01, S-09-4): 아이디/비밀번호 확인 → 실패 5회 10분 잠금 →
     * 성공 시 기존 세션 폐기 후 random_bytes(32) 새 토큰 발급(세션 재발급) →
     * sessions INSERT(해시만) → 쿠키 HttpOnly/Secure/SameSite=Lax 설정 → audit auth.login.
     *
     * @return array<string,mixed>|null 성공 시 사용자, 실패 시 null
     */
    public static function login(string $username, string $password): ?array
    {
        throw new RuntimeException('아직 구현되지 않았습니다');
    }

    /**
     * TODO(2단계 · F-02): 현재 세션 행 DELETE + 쿠키 즉시 만료 + audit auth.logout.
     */
    public static function logout(): void
    {
        throw new RuntimeException('아직 구현되지 않았습니다');
    }

    /**
     * TODO(2단계 · SECURITY R-10): 비밀번호 변경·비활성화·역할 변경 시
     * 그 사용자의 sessions 행 전체 삭제 → 모든 기기 즉시 로그아웃.
     */
    public static function revokeAllSessions(int $userId): void
    {
        throw new RuntimeException('아직 구현되지 않았습니다');
    }
}
