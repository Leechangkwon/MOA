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
 * 비밀번호는 맞았는데 그 계정에 2단계 인증이 켜져 있고 코드가 오지 않았을 때 던진다
 * (ARCH 4.0 "2단계 인증 로그인 흐름", 사용자 결정 2026-09-11).
 * 핸들러(src/handlers/login.php)가 잡아서 401 + `mfa_required:true` 로 답한다.
 *
 * 이것을 "실패" 로 세지 않는 이유: **비밀번호를 아는 본인**이 순차 로그인의 1단계를 끝낸
 * 정상 상태다. 여기서 실패 횟수를 올리면 코드를 한 번도 틀리지 않은 사용자가 잠긴다.
 * 코드를 **틀린** 경우(bad_code)는 그대로 실패로 세어 5회면 잠근다 — 비밀번호를 알아낸
 * 공격자가 6자리를 무제한으로 시험하지 못하게 하는 장치는 그쪽이다.
 *
 * 이 응답이 "이 계정은 2단계 인증을 쓴다" 를 알려 주는 것은 **비밀번호가 맞은 사람에게만**이다.
 * 비밀번호가 틀리면 401 동일 문구이고 mfa_required 는 붙지 않는다 (계정 정보 노출 방지).
 */
final class AuthMfaRequiredException extends RuntimeException
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

    /**
     * 아이디 형식 — db/schema.sql 의 users CHECK 제약과 같다 (소문자만).
     * 10단계에서 계정 생성(F-10)이 같은 규칙을 써야 하므로 public 으로 연다 —
     * 형식 규칙이 두 곳에 복사되면 언젠가 한쪽만 느슨해진다.
     */
    public const USERNAME_RE = '/^[a-z0-9_.-]{3,64}$/';

    /** PRD S-09-4 "최소 12자". 계정 생성·비밀번호 변경이 같은 값을 본다 */
    public const MIN_PASSWORD_CHARS = 12;

    /** 비밀번호 입력 상한 (Argon2id 계산 시간을 요청으로 늘리지 못하게) */
    public const MAX_PASSWORD_BYTES = 1024;

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
               u.is_active     AS is_active,
               u.mfa_enabled   AS mfa_enabled,
               (u.expires_at IS NOT NULL AND u.expires_at < now()) AS is_expired
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
               mfa_enabled, mfa_secret,
               (locked_until IS NOT NULL AND locked_until > now()) AS is_locked,
               (expires_at IS NOT NULL AND expires_at < now())     AS is_expired
          FROM users
         WHERE username = :username
        SQL;

    /** 비밀번호 변경(10단계) — 현재 비밀번호 확인용. 세션의 user_id 로만 찾는다 */
    private const SQL_LOAD_BY_ID = <<<'SQL'
        SELECT id, username, password_hash
          FROM users
         WHERE id = :id
        SQL;

    private const SQL_SET_PASSWORD = <<<'SQL'
        UPDATE users
           SET password_hash = :hash,
               failed_count  = 0,
               locked_until  = NULL,
               updated_at    = now()
         WHERE id = :id
        SQL;

    /** 비밀번호를 바꾼 **그 세션만** 남기고 나머지를 끊는다 (SECURITY R-10) */
    private const SQL_DROP_OTHER_SESSIONS = <<<'SQL'
        DELETE FROM sessions WHERE user_id = :user_id AND id <> :keep
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

        /*
         * 만료된 계정(Guest)의 살아 있는 세션도 즉시 파기한다 (SECURITY R-12, 10단계 검토 재지시).
         *
         * 로그인 때만 만료를 보면, 만료 **직전**에 들어온 Guest 가 최대 8시간(절대 만료)까지
         * 그대로 돌아다닌다 — 10단계 검토에서 실측된 실패다. 비활성화와 똑같이 다룬다:
         * 세션 행을 지우고 null 을 돌려주면 호출자(requireLogin)가 401 로 끝낸다.
         *
         * 기록은 세션을 지우기 **전에** 남긴다. Audit 은 actor 를 Auth::currentUser() 에서 읽는데
         * 지금은 그 함수가 아직 해석 중(self::$user = null)이라 actor_* 는 비어 있다 —
         * 그래서 문장 틀의 {actor} 는 `(로그인 전)` 이 되고, 누구였는지는 target_name 에 남는다
         * (ARCH 7.4 `user.expired|deny|session_expired`).
         */
        if ($row['is_expired'] === true) {
            Audit::deny('user.expired', 'session_expired', [
                'target_type' => 'user',
                'target_id'   => (int) $row['id'],
                'target_name' => (string) $row['username'],
            ]);
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
            // 10단계 — 화면이 "2단계 인증 켜기/끄기" 를 어떻게 보일지 정하는 값 (R-05).
            // 권한이 아니라 상태다. 실제 검증은 언제나 로그인 때 서버가 한다.
            'mfa_enabled' => $row['mfa_enabled'] === true,
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
     * 아이디·비밀번호(+ 2단계 인증 코드) 확인 (PRD F-01, S-09-4, ARCH 4.0, SECURITY R-05 · R-12).
     *
     * 순서 — 뒤로 갈수록 "앞 단계를 통과한 사람만" 도달한다.
     *   1) 아이디 형식 → 2) 계정 조회 → 3) 잠금 → 4) 비활성 → 5) 비밀번호
     *   → 6) **Guest 만료**(10단계 R-12) → 7) **2단계 인증 코드**(10단계 R-05) → 8) 세션 발급
     *
     * 만료·2단계 인증을 비밀번호 **뒤**에 두는 이유: 앞에 두면 비밀번호를 모르는 사람이
     * "이 계정은 만료됐다 / MFA 를 쓴다" 를 응답으로 알아낼 수 있다(계정 상태 탐색).
     * 실패 응답은 전부 401 동일 문구이며 사유는 감사 로그에만 남는다.
     *
     * @param string|null $otp 2단계 인증 코드 6자리. 켜지지 않은 계정에는 쓰이지 않는다
     * @return array<string,mixed>|null 성공 시 사용자, 실패 시 null (호출자가 401)
     * @throws AuthLockedException      계정이 잠겨 있을 때 (호출자가 423)
     * @throws AuthMfaRequiredException 비밀번호는 맞았고 코드가 더 필요할 때 (호출자가 401 + mfa_required)
     */
    public static function login(string $username, string $password, ?string $otp = null): ?array
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
            self::countFailure((int) $row['id']);
            self::denyLogin($name, 'bad_credentials');
            return null;
        }

        // 6) Guest 계정 만료 (SECURITY R-12, PRD 3장 "Guest 생명주기").
        //    DB CHECK 가 Guest 에 expires_at 을 강제하므로 만료일 없는 Guest 는 존재하지 않는다.
        //    만료된 계정은 비밀번호가 맞아도 들어올 수 없다. 응답은 401 동일 문구다.
        if ($row['is_expired'] === true) {
            Audit::deny('user.expired', 'guest_expired', [
                'target_type' => 'user',
                'target_id'   => (int) $row['id'],
                'target_name' => (string) $row['username'],
            ]);
            return null;
        }

        // 7) 2단계 인증 (SECURITY R-05, ARCH 4.0 "2단계 인증 로그인 흐름").
        //    여기에 닿았다는 것은 **비밀번호가 맞았다**는 뜻이다. 두 경우를 가른다.
        //      · 코드가 아직 없다  → 순차 로그인의 1단계 완료. 세션은 주지 않고 예외로 알린다.
        //        **실패 횟수를 올리지 않는다** — 코드를 틀린 적이 없는 본인이 잠기면 안 된다.
        //      · 코드가 틀렸다     → 401 동일 문구 + **실패 횟수 증가**(5회 잠금).
        //        비밀번호를 알아낸 공격자가 6자리를 무제한 시험하지 못하게 막는 것은 이쪽이다.
        //    두 경우 모두 감사 로그에 사유(code_required·bad_code)가 남는다.
        if ($row['mfa_enabled'] === true) {
            $secret = is_string($row['mfa_secret']) ? $row['mfa_secret'] : '';
            $code   = is_string($otp) ? trim($otp) : '';

            if ($code === '') {
                self::denyMfa($name, (int) $row['id'], 'code_required');
                throw new AuthMfaRequiredException('2단계 인증 코드가 필요합니다');
            }
            if ($secret === '' || !Totp::verify($secret, $code)) {
                self::countFailure((int) $row['id']);
                self::denyMfa($name, (int) $row['id'], 'bad_code');
                return null;
            }
        }

        // 8) 성공 — 세션 재발급 (ARCH 4.0)
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
            'id'          => $userId,
            'username'    => (string) $row['username'],
            'role'        => (string) $row['role'],
            'team_id'     => $row['team_id'] === null ? null : (int) $row['team_id'],
            'session_id'  => $newId,
            'mfa_enabled' => $row['mfa_enabled'] === true,
        ];
        return self::$user;
    }

    /**
     * 비밀번호 변경 (PRD 7.7 "인증 · 비밀번호 변경 · 다른 기기 세션 강제 종료", S-09-4, R-10).
     *
     * 하는 일
     *   1) **현재 비밀번호**를 다시 확인한다 — 세션이 잠깐 새어 나가도 비밀번호까지 바꾸지 못하게.
     *   2) 새 비밀번호가 12자 이상인지 본다 (PRD S-09-4).
     *   3) Argon2id 해시로 갈아 끼우고, **지금 이 세션만 남기고** 그 사용자의 세션을 전부 지운다.
     *      (바꾼 기기에서 다시 로그인하게 만들 이유는 없고, 다른 기기는 즉시 끊겨야 한다.)
     *   4) 결과를 감사 로그에 남긴다. 비밀번호는 옛것도 새것도 남기지 않는다.
     *
     * 실패해도 응답 문구는 호출자가 정하고, 여기서는 **사유 코드**만 돌려준다.
     *
     * @param array<string,mixed> $actor 현재 로그인한 사용자 (요청 값이 아니다)
     * @return array{0:bool,1:?string,2:int} [성공 여부, 사유 코드, 끊은 세션 수]
     */
    public static function changePassword(array $actor, string $current, string $new): array
    {
        $db     = Db::instance();
        $userId = (int) $actor['id'];

        $target = [
            'target_type' => 'user',
            'target_id'   => $userId,
            'target_name' => (string) $actor['username'],
        ];

        $row = $db->run(self::SQL_LOAD_BY_ID, [':id' => $userId])->fetch();
        if (!is_array($row) || !password_verify($current, (string) $row['password_hash'])) {
            Audit::deny('auth.password_change', 'bad_credentials', $target);
            return [false, 'bad_credentials', 0];
        }

        // 12자 이상 (PRD S-09-4). 글자 수로 센다 — 한글 12자도 12자다.
        if (preg_match('//u', $new) !== 1
            || strlen($new) > self::MAX_PASSWORD_BYTES
            || iconv_strlen($new, 'UTF-8') < self::MIN_PASSWORD_CHARS
        ) {
            Audit::deny('auth.password_change', 'weak_password', $target);
            return [false, 'weak_password', 0];
        }

        $hash = password_hash($new, PASSWORD_ARGON2ID);
        if (!is_string($hash)) {
            throw new RuntimeException('비밀번호 해시를 만들 수 없습니다');
        }

        $keep    = (string) ($actor['session_id'] ?? '');
        $revoked = 0;

        $db->begin();
        try {
            $db->run(self::SQL_SET_PASSWORD, [':hash' => $hash, ':id' => $userId]);
            $revoked = $db->run(self::SQL_DROP_OTHER_SESSIONS, [
                ':user_id' => $userId,
                ':keep'    => $keep,
            ])->rowCount();

            Audit::allow('auth.password_change', $target + [
                'detail' => ['sessions_revoked' => $revoked],
            ]);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollback();
            throw $e;
        }

        return [true, null, $revoked];
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
     * 그 사용자의 모든 세션을 끊는다 (SECURITY R-10) — 모든 기기에서 즉시 로그아웃된다.
     * 비활성화·역할/팀 변경에서 부른다 (10단계 F-10, T-22).
     *
     * 끊은 세션 수를 돌려준다 — 감사 로그의 `detail.sessions_revoked` (ARCH 7.5).
     */
    public static function revokeAllSessions(int $userId): int
    {
        return Db::instance()->run(self::SQL_DROP_USER_SESSIONS, [':user_id' => $userId])->rowCount();
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

    /**
     * 2단계 인증 실패 기록 (ARCH 7.4 `auth.mfa|deny|code_required`·`bad_code`).
     * 코드(otp)도 비밀(secret)도 넘기지 않는다 — 사유 코드만 남는다.
     */
    private static function denyMfa(string $name, int $userId, string $reason): void
    {
        Audit::deny('auth.mfa', $reason, [
            'target_type' => 'user',
            'target_id'   => $userId,
            'target_name' => $name,
        ]);
    }

    /**
     * 로그인 실패 1회 기록 (PRD S-09-4 "실패 5회 10분 잠금").
     * 비밀번호 오류와 2단계 인증 오류가 **같은 카운터**를 쓴다 — 비밀번호를 알아낸 공격자가
     * 코드 6자리를 무제한 시험하지 못하게 하는 것이 목적이다.
     */
    private static function countFailure(int $userId): void
    {
        Db::instance()->run(self::SQL_COUNT_FAILED, [
            ':id'         => $userId,
            ':max_failed' => self::MAX_FAILED,
            ':lock_min'   => self::LOCK_MINUTES,
        ]);
    }
}
