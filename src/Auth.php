<?php
declare(strict_types=1);

/**
 * 로그인 시도가 계정 잠금(PRD F-01, S-09-4)에 걸렸을 때 던진다.
 * 핸들러(src/handlers/login.php)가 잡아서 423 으로 답한다.
 * "비밀번호가 틀렸다" 와 "잠겼다" 는 구분되지만, 잠금은 잠금 사실만 알려 준다
 * (비밀번호가 맞았는지 여부는 알려 주지 않는다 — 잠금 중에는 검증조차 하지 않는다).
 */
final class AuthLockedException extends RuntimeException
{
}

/**
 * src/Auth.php — "누구인가" (ARCH 4장 [1] · 4.0, PRD S-01, S-09-2, S-09-4, SECURITY R-10)
 *
 * 원칙
 *  1) 역할·팀은 요청에서 절대 읽지 않는다. 쿠키에는 무작위 토큰만 있고,
 *     그 토큰으로 DB sessions → users 를 매 요청 다시 조회해 role·team_id 를 얻는다.
 *  2) 쿠키 토큰 원문은 DB 에 저장하지 않는다. DB 에는 SHA-256 만 둔다
 *     (DB 가 유출돼도 세션을 위조할 수 없다 — ARCH 6.2-2).
 *  3) is_active = false 면 그 자리에서 세션 행을 지우고 401 — 비활성화가 즉시 반영된다.
 *  4) 로그인 성공 시 기존 세션 행을 지우고 새 토큰을 발급한다 — 세션 고정 공격 차단 (ARCH 4.0).
 *  5) 실패 응답은 아이디 없음·비밀번호 틀림·비활성 계정이 모두 같다. 아이디가 없을 때도
 *     더미 해시로 password_verify 를 한 번 돌려 "계정이 있는지" 를 응답 시간으로 알 수 없게 한다.
 */
final class Auth
{
    public const COOKIE_NAME = 'tbox_session';

    /** 쿠키 토큰: random_bytes(32) 의 hex → 정확히 64자의 소문자 16진수 */
    private const TOKEN_RE = '/^[0-9a-f]{64}$/';

    /** 아이디 형식 — db/schema.sql 의 users CHECK 제약과 같다 (소문자만) */
    private const USERNAME_RE = '/^[a-z0-9_.-]{3,64}$/';

    /** PRD S-09-4: 실패 5회 → 10분 잠금 */
    private const MAX_FAILED   = 5;
    private const LOCK_MINUTES = 10;

    /**
     * 아이디가 없을 때 대신 검증하는 고정 Argon2id 해시.
     * 평문은 만들 때 쓰고 버린 무작위 24바이트이며 어디에도 남기지 않았다 — 이 해시로는 로그인할 수 없다.
     * 부팅 시 password_hash() 로 만들지 않는 이유: 요청마다 해시 1회가 더 늘어나고,
     * 그 비용이 "계정 있음/없음" 에 따라 달라지면 오히려 시간 차가 생긴다.
     */
    private const DUMMY_HASH = '$argon2id$v=19$m=65536,t=4,p=1$eHVSN0djZTlZeC9OcHY4MA$hx0kZPZH6VaxrrYlTD4T0T5tfYucPaD++tayUFWP8Z4';

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

    private const SQL_DROP_USER_SESSIONS = <<<'SQL'
        DELETE FROM sessions WHERE user_id = :user_id
        SQL;

    /** 로그인 때 자연 정리 (ARCH 4.0 "만료 정리") — 별도 배치가 필요 없다 */
    private const SQL_PURGE_EXPIRED = <<<'SQL'
        DELETE FROM sessions
         WHERE expires_at   < now()
            OR last_seen_at < now() - make_interval(mins => CAST(:idle_min AS integer))
        SQL;

    private const SQL_LOAD_USER = <<<'SQL'
        SELECT id, username, password_hash, role, team_id, is_active,
               failed_count, locked_until,
               (locked_until IS NOT NULL AND locked_until > now()) AS is_locked
          FROM users
         WHERE username = :username
        SQL;

    private const SQL_CLEAR_FAILED = <<<'SQL'
        UPDATE users SET failed_count = 0, locked_until = NULL WHERE id = :id
        SQL;

    /**
     * 실패 1회 기록. 한 문장으로 처리해 "읽고 나서 쓰는" 사이에 끼어드는 동시 요청이
     * 카운트를 건너뛰지 못하게 한다 (값은 전부 바인딩).
     */
    private const SQL_COUNT_FAILED = <<<'SQL'
        UPDATE users
           SET failed_count = failed_count + 1,
               locked_until = CASE
                   WHEN failed_count + 1 >= CAST(:max_failed AS integer)
                        THEN now() + make_interval(mins => CAST(:lock_min AS integer))
                   ELSE locked_until
               END
         WHERE id = :id
        SQL;

    private const SQL_INSERT_SESSION = <<<'SQL'
        INSERT INTO sessions (id, user_id, expires_at, ip, user_agent)
        VALUES (:id, :user_id,
                now() + make_interval(hours => CAST(:max_hours AS integer)),
                CAST(:ip AS inet), :user_agent)
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

        $id = self::cookieSessionId();
        if ($id === null) {
            return null;
        }
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
                // 실제 경로가 아니라 라우트 패턴을 남긴다 (SECURITY R-36, ARCH 7.5 route).
                // 경로 원문을 남기면 감사 로그가 파일 UUID 목록이 되어 열거 재료가 된다.
                // 라우터가 매칭 직후 정의하는 TBOX_ROUTE 를 쓰고, 없으면(라우팅 전) 메서드+경로로 되돌린다.
                'target_name' => defined('TBOX_ROUTE')
                    ? (string) constant('TBOX_ROUTE')
                    : Http::method() . ' ' . Http::path(),
            ]);
            Http::error(401, Http::MSG_UNAUTHORIZED);
        }
        return $u;
    }

    /**
     * CSRF 토큰 (ARCH 4.0). 세션 해시에 APP_SECRET 으로 HMAC 을 걸어 계산한다 —
     * 저장하지 않으므로 스키마 변경이 없고, 세션이 죽으면 토큰도 함께 무효가 된다.
     * 토큰 자체는 로그·감사 detail 에 남기지 않는다 (PRD 7.7 민감정보 금지).
     */
    public static function csrfToken(): string
    {
        $u = self::currentUser();
        if ($u === null) {
            throw new RuntimeException('세션이 없어 CSRF 토큰을 만들 수 없습니다');
        }
        return hash_hmac('sha256', 'csrf:' . (string) $u['session_id'], Env::get('APP_SECRET'));
    }

    /**
     * 아이디·비밀번호 확인 (PRD F-01, S-09-4, ARCH 4.0).
     *
     * @return array<string,mixed>|null 성공 시 사용자, 실패 시 null (호출자가 401)
     * @throws AuthLockedException 계정이 잠겨 있을 때 (호출자가 423)
     */
    public static function login(string $username, string $password): ?array
    {
        $db   = Db::instance();
        $name = trim($username);

        // 1) 형식이 어긋나면 조회 자체가 무의미하다. 그래도 더미 검증은 돌려
        //    "형식이 틀렸다" 와 "계정이 없다" 가 응답 시간으로 구분되지 않게 한다.
        if (preg_match(self::USERNAME_RE, $name) !== 1) {
            password_verify($password, self::DUMMY_HASH);
            self::denyLogin($name, 'bad_credentials');
            return null;
        }

        // 2) 계정 조회
        $row = $db->run(self::SQL_LOAD_USER, [':username' => $name])->fetch();
        if (!is_array($row)) {
            password_verify($password, self::DUMMY_HASH);
            self::denyLogin($name, 'bad_credentials');
            return null;
        }

        // 3) 잠금 — 잠긴 동안에는 비밀번호를 검증하지 않는다 (맞았는지도 알려 주지 않는다)
        if ($row['is_locked'] === true) {
            self::denyLogin($name, 'locked');
            throw new AuthLockedException('계정이 잠겨 있습니다');
        }

        // 4) 비활성 계정 — 401 동일 문구. 더미 검증으로 시간 차를 없앤다
        if ($row['is_active'] !== true) {
            password_verify($password, self::DUMMY_HASH);
            self::denyLogin($name, 'inactive');
            return null;
        }

        // 5) 비밀번호 확인 (PASSWORD_ARGON2ID. 알고리즘이 하나뿐이므로 rehash 는 다루지 않는다)
        if (!password_verify($password, (string) $row['password_hash'])) {
            $db->run(self::SQL_COUNT_FAILED, [
                ':id'          => (int) $row['id'],
                ':max_failed'  => self::MAX_FAILED,
                ':lock_min'    => self::LOCK_MINUTES,
            ]);
            self::denyLogin($name, 'bad_credentials');
            return null;
        }

        // 6) 성공 — 세션 재발급 (ARCH 4.0)
        $userId = (int) $row['id'];
        $oldId  = self::cookieSessionId();
        $token  = bin2hex(random_bytes(32));
        $newId  = hash('sha256', $token);

        $db->begin();
        try {
            $db->run(self::SQL_CLEAR_FAILED, [':id' => $userId]);
            if ($oldId !== null) {
                // 로그인 전에 들고 온 쿠키의 세션은 버린다 — 공격자가 미리 심어 둔 값이 살아남지 않는다
                $db->run(self::SQL_DROP_SESSION, [':id' => $oldId]);
            }
            $db->run(self::SQL_PURGE_EXPIRED, [':idle_min' => Env::getInt('SESSION_IDLE_MIN')]);
            $db->run(self::SQL_INSERT_SESSION, [
                ':id'         => $newId,
                ':user_id'    => $userId,
                ':max_hours'  => Env::getInt('SESSION_MAX_HOURS'),
                ':ip'         => Http::clientIp(),
                ':user_agent' => Http::userAgent(),
            ]);
            Audit::allow('auth.login', [
                'target_type' => 'user',
                'target_id'   => $userId,
                'target_name' => (string) $row['username'],
            ]);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollback();
            throw $e;
        }

        // 쿠키는 커밋이 끝난 뒤에만 내려간다 — 저장되지 않은 세션의 쿠키를 주지 않는다
        Http::setSessionCookie($token);

        self::$resolved = true;
        self::$user = [
            'id'         => $userId,
            'username'   => (string) $row['username'],
            'role'       => (string) $row['role'],
            'team_id'    => $row['team_id'] === null ? null : (int) $row['team_id'],
            'session_id' => $newId,
        ];
        return self::$user;
    }

    /** 로그아웃 (PRD F-02): 서버측 세션 행 삭제가 본체이고, 쿠키 삭제는 정리다. */
    public static function logout(): void
    {
        $u = self::currentUser();
        if ($u === null) {
            return;
        }
        Db::instance()->run(self::SQL_DROP_SESSION, [':id' => (string) $u['session_id']]);
        Audit::allow('auth.logout', [
            'target_type' => 'user',
            'target_id'   => (int) $u['id'],
            'target_name' => (string) $u['username'],
        ]);
        Http::clearSessionCookie();
    }

    /**
     * 그 사용자의 모든 세션을 끊는다 (SECURITY R-10).
     * 비밀번호 변경·비활성화·역할 변경에서 부른다 — F-10(10단계)에서 쓰인다.
     */
    public static function revokeAllSessions(int $userId): void
    {
        Db::instance()->run(self::SQL_DROP_USER_SESSIONS, [':user_id' => $userId]);
    }

    /** 쿠키에 실려 온 토큰의 세션 해시. 형식이 어긋나면 null (DB 를 건드리지 않는다). */
    private static function cookieSessionId(): ?string
    {
        $token = $_COOKIE[self::COOKIE_NAME] ?? null;
        if (!is_string($token) || preg_match(self::TOKEN_RE, $token) !== 1) {
            return null;
        }
        return hash('sha256', $token);
    }

    /**
     * 로그인 실패 기록 (PRD S-07 "인증 · 로그인 · 실패 시").
     * 비밀번호는 어떤 형태로도 넘기지 않는다 — 사유(reason)만 남는다.
     */
    private static function denyLogin(string $name, string $reason): void
    {
        Audit::deny('auth.login', $reason, [
            'target_type' => 'user',
            'target_name' => $name,
        ]);
    }
}
