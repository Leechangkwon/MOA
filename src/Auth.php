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

    /** PRD S-09-4 "최소 10자". 계정 생성·비밀번호 변경이 같은 값을 본다 */
    public const MIN_PASSWORD_CHARS = 10;

    /** 비밀번호 입력 상한 (Argon2id 계산 시간을 요청으로 늘리지 못하게) */
    public const MAX_PASSWORD_BYTES = 1024;

    /** PRD S-09-4: 실패 5회 → 10분 잠금 */
    private const MAX_FAILED   = 5;
    private const LOCK_MINUTES = 10;

    /**
     * 관리 기능 재인증(sudo 모드)의 창 길이 — 분 (16-1, PRD F-23, ARCH 4.0).
     *
     * 이 값 안에 비밀번호를 다시 확인한 세션만 관리 영역을 쓸 수 있다. 창을 늘리는 요청
     * 파라미터·연장 API 는 만들지 않는다 — 늘릴 수 있는 창은 창이 아니다(SECURITY R-59).
     * 판정은 SQL_LOOKUP 의 `reauth_fresh` 가 **DB 시계 하나**로 한다.
     */
    public const REAUTH_MINUTES = 15;

    /**
     * 「역할로 보기」가 저절로 풀리는 **활동 없는** 시간 — 분 (26단계, PRD F-39, ARCH 5.20).
     *
     * 「시작 뒤 30분」이 아니다. 요청마다 `sessions.act_last_seen_at` 을 밀고(SQL_TOUCH),
     * 그 값이 이 창보다 오래됐을 때만 해제한다 — 쓰는 중에 화면이 갑자기 원래 역할로 돌아가면
     * 관리자는 자기가 무엇을 보고 있는지 알 수 없게 된다 (사용자 결정 2026-09-15 ③).
     * 판정은 SQL_LOOKUP 의 `act_fresh` 가 **DB 시계 하나**로 한다 (reauth_fresh 와 같다).
     * 늘리는 요청 파라미터·연장 API 는 만들지 않는다 (R-59 와 같은 원칙).
     */
    public const ACT_IDLE_MINUTES = 30;

    /** 감사 `act.expire` 의 사유 — 활동 없는 창이 지남 / 실제 역할이 Admin 이 아니게 됨 */
    private const ACT_EXPIRE_IDLE      = 'idle';
    private const ACT_EXPIRE_ROLE_LOST = 'role_lost';

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
               -- 26단계 (PRD F-39) — 구매 모듈의 두 축을 세션 조회에 함께 싣는다.
               -- 19단계에는 Authz::purchaseFields 가 요청마다 users 를 한 번 더 읽었는데,
               -- 「역할로 보기」는 이 두 칸도 바꿔 줘야 하므로 값을 만드는 자리가 한 곳이어야 한다.
               -- purchaseFields 는 "호출자가 두 칸을 이미 들고 있으면 그것을 쓴다" 는 계약이라
               -- 판정 코드는 그대로이고, 쿼리는 오히려 하나 줄어든다.
               u.purchase_role AS purchase_role,
               u.branch_id     AS branch_id,
               u.is_active     AS is_active,
               u.mfa_enabled   AS mfa_enabled,
               -- 11단계 (PRD F-16) — 최초 로그인 강제 절차의 두 플래그.
               -- 라우터 관문이 요청마다 보는 값이라 세션 조회에 함께 싣는다(쿼리 1회 유지).
               u.must_change_password AS must_change_password,
               u.mfa_setup_required   AS mfa_setup_required,
               (u.expires_at IS NOT NULL AND u.expires_at < now()) AS is_expired,
               -- 16-1 (PRD F-23) — 관리 기능 재인증(sudo 모드).
               -- 판정을 앱이 아니라 **DB 시계 하나**로 한다: 앱 서버와 DB 의 시계가 어긋나도
               -- 창의 길이가 흔들리지 않고, 요청마다 쿼리를 하나 더 늘리지도 않는다.
               s.reauth_at    AS reauth_at,
               (s.reauth_at IS NOT NULL
                AND s.reauth_at > now() - make_interval(mins => CAST(:reauth_min AS integer)))
                              AS reauth_fresh,
               -- 26단계 (PRD F-39, ARCH 5.20) — 「역할로 보기」. 이 세션이 지금 어떤 역할로 보나.
               -- 판정에 필요한 두 가지를 **DB 가 계산해서** 실어 준다 (reauth_fresh 와 같은 방식):
               --   act_role_ok  보기를 시작할 자격(실제 역할 Admin)이 **지금도** 있나 — 강등되면 거짓
               --   act_fresh    활동 없는 30분이 지나지 않았나 — 시계는 DB 하나뿐이다
               -- 역할 문자열을 견주는 자리가 SQL 한 줄이므로, PHP 쪽에는 역할 비교가 생기지 않는다.
               s.act_role          AS act_role,
               s.act_team_id       AS act_team_id,
               s.act_purchase_role AS act_purchase_role,
               s.act_branch_id     AS act_branch_id,
               s.act_started_at    AS act_started_at,
               (s.act_role IS NOT NULL AND u.role = 'Admin')            AS act_role_ok,
               (s.act_last_seen_at IS NOT NULL
                AND s.act_last_seen_at > now() - make_interval(mins => CAST(:act_idle_min AS integer)))
                              AS act_fresh
          FROM sessions s
          JOIN users   u ON u.id = s.user_id
         WHERE s.id = :id
           AND s.expires_at   > now()
           AND s.last_seen_at > now() - make_interval(mins => CAST(:idle_min AS integer))
        SQL;

    /**
     * 요청 1회당 한 번 도는 활동 갱신.
     *
     * 26단계 (PRD F-39) — 「역할로 보기」의 만료는 **활동 없는 30분**이다. 그래서 보는 중인
     * 세션(`act_role IS NOT NULL`)은 여기서 `act_last_seen_at` 도 함께 민다. 기존 문장에 한 열을
     * 더한 것이라 **쿼리 수가 늘지 않는다**. 보는 중이 아니면 NULL 그대로 둔다 — 값이 있는 것
     * 자체가 "보는 중" 의 흔적이 되면 안 된다.
     */
    private const SQL_TOUCH = <<<'SQL'
        UPDATE sessions
           SET last_seen_at     = now(),
               act_last_seen_at = CASE WHEN act_role IS NULL THEN NULL ELSE now() END
         WHERE id = :id
        SQL;

    /**
     * 「역할로 보기」 해제 (26단계, PRD F-39).
     * 만료(idle)·강등(role_lost)일 때 Auth 가, 사용자가 껐을 때 me_act_stop.php 가 같은 모양으로 쓴다.
     */
    private const SQL_CLEAR_ACT = <<<'SQL'
        UPDATE sessions
           SET act_role          = NULL,
               act_team_id       = NULL,
               act_purchase_role = NULL,
               act_branch_id     = NULL,
               act_started_at    = NULL,
               act_last_seen_at  = NULL
         WHERE id = :id
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
               mfa_enabled, mfa_secret, mfa_last_step,
               must_change_password, mfa_setup_required,
               (locked_until IS NOT NULL AND locked_until > now()) AS is_locked,
               (expires_at IS NOT NULL AND expires_at < now())     AS is_expired
          FROM users
         WHERE username = :username
        SQL;

    /** 비밀번호 변경(10단계) — 현재 비밀번호 확인용. 세션의 user_id 로만 찾는다 */
    private const SQL_LOAD_BY_ID = <<<'SQL'
        SELECT id, username, password_hash, must_change_password
          FROM users
         WHERE id = :id
        SQL;

    private const SQL_SET_PASSWORD = <<<'SQL'
        UPDATE users
           SET password_hash        = :hash,
               failed_count         = 0,
               locked_until         = NULL,
               -- 11단계 (PRD F-16): 비밀번호를 바꾼 **그 문장**에서 강제 플래그를 내린다.
               -- 두 문장으로 나누면 사이에서 실패했을 때 "바뀌었는데 계속 막히는" 상태가 남는다.
               must_change_password = false,
               password_changed_at  = now(),
               updated_at           = now()
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
     * 관리 기능 재인증 성공 — **지금 이 세션 행만** 창을 연다 (16-1, PRD F-23, ARCH 4.0).
     *
     * user_id 로 갱신하지 않는 이유: 사무실 PC 에서 한 번 확인한 것이 집 노트북·훔친 쿠키의
     * 세션까지 함께 열어 주면 그것은 세션마다 묻는 뜻이 사라진다. 창의 길이는 여기서 정하지
     * 않는다 — `now()` 만 놓고, 15분 판정은 SQL_LOOKUP 의 reauth_fresh 가 한다.
     */
    private const SQL_SET_REAUTH = <<<'SQL'
        UPDATE sessions SET reauth_at = now() WHERE id = :id
        RETURNING (reauth_at + make_interval(mins => CAST(:reauth_min AS integer))) AS reauth_until
        SQL;

    /**
     * 실패 1회 기록. 한 문장으로 처리해 "읽고 나서 쓰는" 사이에 끼어드는 동시 요청이
     * 카운트를 건너뛰지 못하게 한다 (값은 전부 바인딩).
     *
     * ★ 잠금이 **이미 풀린** 계정은 카운터를 1 부터 다시 센다 (13단계, SECURITY R-49).
     *   전에는 `failed_count + 1` 만 했기 때문에, 10분이 지나 잠금이 풀려도 카운터 5 가 그대로
     *   남아 **다음 실패 한 번**이 곧바로 다시 10분 잠금이었다(2026-09-12 실측: `t10_lock`
     *   failed_count 5 · locked_until 만료 그대로). 아이디만 아는 사람이 10분에 요청 하나로
     *   남의 계정(Admin 포함)을 영구 잠금 상태에 둘 수 있는 서비스 거부였다(R-06 의 악화 형태).
     *   `locked_until < now()` 는 NULL 이면 NULL(=거짓 취급)이라 잠긴 적 없는 계정은 그대로
     *   `failed_count + 1` 로 간다. 카운터를 되돌릴 때 `locked_until` 도 NULL 로 치워
     *   "만료된 잠금 시각" 이 표에 남지 않게 한다.
     *
     * RETURNING 으로 **갱신된 뒤**의 잠금 여부를 돌려준다 — 호출자가 다시 SELECT 하지 않고
     * "이번 실패로 잠겼나" 를 알 수 있다(잠겼으면 세션 회수, SECURITY R-48).
     */
    private const SQL_COUNT_FAILED = <<<'SQL'
        UPDATE users
           SET failed_count = CASE
                   WHEN locked_until IS NOT NULL AND locked_until < now() THEN 1
                   ELSE failed_count + 1
               END,
               locked_until = CASE
                   WHEN locked_until IS NOT NULL AND locked_until < now() THEN NULL
                   WHEN failed_count + 1 >= CAST(:max_failed AS integer)
                        THEN now() + make_interval(mins => CAST(:lock_min AS integer))
                   ELSE locked_until
               END
         WHERE id = :id
        RETURNING (locked_until IS NOT NULL AND locked_until > now()) AS is_locked
        SQL;

    /**
     * 2단계 인증 코드의 **마지막으로 쓴 스텝**을 올린다 (13단계, SECURITY R-45, PRD T-31).
     * 뒤로 가지 않게 GREATEST 로 덮어쓴다 — 동시에 들어온 두 요청 중 늦은 쪽이 기준선을
     * 낮추면 그 틈으로 같은 코드가 한 번 더 통과한다.
     */
    private const SQL_SET_MFA_STEP = <<<'SQL'
        UPDATE users
           SET mfa_last_step = GREATEST(COALESCE(mfa_last_step, 0), CAST(:step AS bigint))
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
            ':id'           => $id,
            ':idle_min'     => Env::getInt('SESSION_IDLE_MIN'),
            ':reauth_min'   => self::REAUTH_MINUTES,
            ':act_idle_min' => self::ACT_IDLE_MINUTES,
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

        /*
         * ── 26단계 「역할로 보기」 (PRD F-39, ARCH 5.20, SECURITY R-74) ─────────────────
         *
         * 여기가 이 기능의 **전부**다. 유효 역할(role·team_id·purchase_role·branch_id)을 이 한
         * 곳에서 만들어 두면 Authz·purchaseScopeWhere·모든 핸들러가 읽는 `$u` 가 그대로 그 역할이
         * 된다 — 판정 코드는 한 줄도 고치지 않았고, 그래서 **화면 흉내가 아니라 서버 판정**이다.
         *
         * 실제 값은 `real_*` 로 함께 내려 준다. 감사 로그의 `actor_role` 은 그쪽을 쓴다 —
         * 「누가」 했는지는 사람이고, 「어떤 역할로 보였나」는 detail.acting_as 다 (ARCH 7.5).
         *
         * 켜짐 조건은 세 가지가 **모두** 참일 때뿐이다:
         *   ① 세션에 act_role 이 있다          — 값은 sessions 열에서만 온다(요청 파라미터 아님)
         *   ② 실제 역할이 지금도 Admin 이다     — act_role_ok. 강등되면 그 자리에서 풀린다
         *   ③ 활동 없는 30분이 지나지 않았다    — act_fresh
         * ②③ 은 위 SQL 이 DB 시계 하나로 계산했다. 아래에는 역할 문자열 비교가 없다.
         */
        $actRole  = $row['act_role'] === null ? null : (string) $row['act_role'];
        $actValid = $actRole !== null && $row['act_role_ok'] === true && $row['act_fresh'] === true;
        $actGone  = $actRole !== null && !$actValid;

        self::$user = [
            'id'         => (int) $row['id'],
            'username'   => (string) $row['username'],
            // 유효 역할 — 보는 중이면 보는 값, 아니면 실제 값. 아래 real_* 가 언제나 실제 값이다.
            'role'       => $actValid ? $actRole : (string) $row['role'],
            'team_id'    => $actValid
                ? ($row['act_team_id'] === null ? null : (int) $row['act_team_id'])
                : ($row['team_id'] === null ? null : (int) $row['team_id']),
            /*
             * 19단계 구매 모듈의 두 축. 전에는 Authz::purchaseFields 가 users 를 한 번 더 읽었다 —
             * 이제 세션 조회가 함께 싣고(쿼리 1회 감소), 보는 중이면 보는 값으로 바뀐다.
             * purchaseFields 는 "두 키가 **모두** 있으면 그것을 쓴다" 는 계약이라 둘 다 언제나 넣는다.
             */
            'purchase_role' => $actValid
                ? ($row['act_purchase_role'] === null ? null : (string) $row['act_purchase_role'])
                : ($row['purchase_role'] === null ? null : (string) $row['purchase_role']),
            'branch_id'     => $actValid
                ? ($row['act_branch_id'] === null ? null : (int) $row['act_branch_id'])
                : ($row['branch_id'] === null ? null : (int) $row['branch_id']),
            // 실제 값 — **언제나** users 행 그대로다. 감사·띠·「원래대로」가 이 값을 본다.
            'real_role'          => (string) $row['role'],
            'real_team_id'       => $row['team_id'] === null ? null : (int) $row['team_id'],
            'real_purchase_role' => $row['purchase_role'] === null ? null : (string) $row['purchase_role'],
            'real_branch_id'     => $row['branch_id'] === null ? null : (int) $row['branch_id'],
            'acting'             => $actValid,
            'acting_since'       => $actValid && $row['act_started_at'] !== null
                ? (string) $row['act_started_at'] : null,
            'session_id' => (string) $row['session_id'],
            // 10단계 — 화면이 "2단계 인증 켜기/끄기" 를 어떻게 보일지 정하는 값 (R-05).
            // 권한이 아니라 상태다. 실제 검증은 언제나 로그인 때 서버가 한다.
            'mfa_enabled' => $row['mfa_enabled'] === true,
            // 11단계 — 최초 로그인 강제 절차 (PRD F-16). 라우터 관문이 이 두 값만 본다.
            // **요청에서 읽지 않는다** — 언제나 DB 의 현재 값이다 (PRD S-01).
            'must_change_password' => $row['must_change_password'] === true,
            'mfa_setup_required'   => $row['mfa_setup_required'] === true,
            /*
             * 16-1 — 관리 기능 재인증 (PRD F-23, ARCH 4.0). 라우터 관문 [3-2] 가 보는 값이다.
             *   reauth_fresh — 15분 창 안인가. 판정은 위 SQL 이 DB 시계로 했고 앱은 참/거짓만 읽는다.
             *   reauth_at    — 마지막 확인 시각(없으면 null). 화면이 "언제까지 열려 있나" 를
             *                  보여 주는 데만 쓰고 **판정에는 쓰지 않는다**.
             * 요청에서 읽지 않는다 — 언제나 DB 의 현재 값이다 (PRD S-01).
             */
            'reauth_fresh' => $row['reauth_fresh'] === true,
            'reauth_at'    => $row['reauth_at'] === null ? null : (string) $row['reauth_at'],
        ];

        /*
         * 만료(활동 없는 30분)·강등(실제 역할이 Admin 이 아니게 됨)이면 **그 자리에서** 되돌린다
         * (26단계, PRD F-39). 배치를 따로 두지 않는 이유는 reauth 창과 같다 — 다음 요청이
         * 판정하는 순간이 곧 만료되는 순간이어야 "지났는데 아직 열려 있는" 틈이 생기지 않는다.
         *
         * 기록을 **먼저** 남기고 지운다: Audit 은 actor 를 Auth::currentUser() 에서 읽는데
         * self::$user 는 위에서 이미 실제 역할(acting=false)로 채워졌으므로, 이 한 줄은
         * `actor_role = 실제 역할` 로 남고 detail.acting_as 는 붙지 않는다(이미 해제된 상태다).
         * UPDATE 는 이때만 한 번 더 돈다 — 평소 요청의 쿼리 수는 그대로다.
         */
        if ($actGone) {
            Audit::allow('act.expire', [
                'target_type' => 'user',
                'target_id'   => (int) $row['id'],
                'target_name' => (string) $row['username'],
                'detail'      => [
                    'reason'       => $row['act_role_ok'] === true
                        ? self::ACT_EXPIRE_IDLE
                        : self::ACT_EXPIRE_ROLE_LOST,
                    'was_acting_as' => $actRole,
                    'idle_minutes'  => self::ACT_IDLE_MINUTES,
                ],
            ]);
            $db->run(self::SQL_CLEAR_ACT, [':id' => $id]);
        }

        return self::$user;
    }

    /**
     * 세션 캐시를 버리고 **다시 읽는다** (26단계, PRD F-39).
     *
     * 쓰는 곳은 `POST /api/me/act` · `DELETE /api/me/act` 두 핸들러뿐이다. 그 둘은 자기 요청
     * 안에서 세션 행을 바꾸고 **바뀐 뒤의 `/api/me`** 를 그대로 돌려줘야 하는데, currentUser 는
     * 요청 1회당 한 번만 읽도록 캐시돼 있어 바꾸기 **전**의 값이 남는다.
     *
     * 이 함수는 권한을 만들지 않는다 — 하는 일은 캐시를 버리는 것뿐이고, 값은 언제나 DB 를
     * 다시 읽어서 온다 (PRD S-01). 요청 값은 한 글자도 섞이지 않는다.
     *
     * @return array<string,mixed>|null
     */
    public static function reload(): ?array
    {
        self::$resolved = false;
        self::$user     = null;
        return self::currentUser();
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

    /** 프로그램용 업로드 토큰의 최소 길이 (PRD F-49 ①, ARCH 4.2 「설정 확인」) */
    public const API_TOKEN_MIN_CHARS = 32;

    /**
     * 프로그램용 업로드 `POST /api/upload` 의 인증 (PRD F-49, ARCH 4.2, S-09-11).
     *
     * 사람의 로그인(requireLogin)과 **다른 길**이다. 세션도 쿠키도 CSRF 도 없고, 대신
     * `.env` 의 정적 토큰 하나를 헤더 `X-API-Token` 과 견준다. 이 함수를 부르는 곳은
     * 라우터 [2] 의 `POST /api/upload` 가지 하나뿐이다 — **다른 라우트는 이 헤더를 읽지도
     * 않으므로**, 토큰을 쥔 사람도 그 창구 밖에서는 아무것도 할 수 없다.
     *
     * 실패는 네 가지이고 전부 `auth.token deny` 로 남는다. 감사의 대상은 **라우트 패턴**이다
     * (`target_type=system`) — 어느 계정에 귀속되는지는 요청자에게도 로그에도 흘리지 않는다.
     *   not_configured  `.env` 에 토큰이 없다·짧다 → 503. **닫힌 실패**다: 설정이 안 된 서버는
     *                   "아무나 올릴 수 있는 서버" 가 아니라 "아무도 못 올리는 서버" 여야 한다
     *   missing_header  헤더가 없다 → 401
     *   bad_token       헤더가 다르다 → 401. 비교는 **hash_equals 만** 쓴다(타이밍 안전).
     *                   `==`·`===`·`strcmp` 는 앞에서부터 다른 자리를 만나면 바로 끝나므로,
     *                   응답 시간 차이를 재서 한 글자씩 맞혀 볼 수 있다
     *   user_invalid    귀속 계정(`API_TOKEN_USER`)이 없다·비활성·만료·강제 절차 중 → 503.
     *                   요청이 잘못된 것이 아니라 **서버 설정**이 잘못된 것이므로 4xx 가 아니다
     *
     * 성공하면 그 계정을 `currentUser()` 가 돌려주는 것과 **같은 자리**(self::$user)에 앉힌다.
     * 그래야 감사 로그의 `actor_*` 가 귀속 계정 이름으로 남고(PRD F-49 ②), 업로드 공통 함수는
     * "누가 불렀는지" 를 모른 채 웹과 똑같은 코드를 실행한다. 비밀번호 해시·TOTP 비밀은
     * 배열에서 **빼고** 앉힌다 — 업로드 경로가 그 값을 볼 이유가 없다.
     *
     * 토큰 값은 이 함수 어디에서도 로그·감사·응답에 나가지 않는다 (PRD S-09-11, SECURITY R-82).
     *
     * @return array<string,mixed>
     */
    public static function requireApiToken(): array
    {
        // ① 서버 설정 — 없거나 짧으면 아무도 못 들어온다 (닫힌 실패)
        $configured = Env::getOrEmpty('API_TOKEN');
        if (strlen($configured) < self::API_TOKEN_MIN_CHARS) {
            self::denyApiToken('not_configured');
            Http::error(503, 'API 토큰이 서버에 설정되지 않았습니다');
        }

        // ② 헤더
        $sent = Http::apiTokenHeader();
        if ($sent === null) {
            self::denyApiToken('missing_header');
            Http::error(401, 'X-API-Token 헤더가 없습니다');
        }

        // ③ 비교 — hash_equals 만. 길이가 달라도 false 이고 시간이 값에 따라 변하지 않는다.
        if (!hash_equals($configured, $sent)) {
            self::denyApiToken('bad_token');
            Http::error(401, 'API 토큰이 올바르지 않습니다');
        }

        // ④ 귀속 계정 — 로그인과 **같은 조회**를 쓴다(비밀번호 검증만 없다).
        $name = Env::getOrEmpty('API_TOKEN_USER');
        $row  = null;
        if ($name !== '' && preg_match(self::USERNAME_RE, $name) === 1) {
            $row = Db::instance()->run(self::SQL_LOAD_USER, [':username' => $name])->fetch();
        }
        if (!is_array($row)
            || $row['is_active'] !== true
            || $row['is_expired'] === true
            || self::nextStep($row) !== null
        ) {
            self::denyApiToken('user_invalid');
            // 계정 이름도, 넷 중 무엇이 틀렸는지도 응답에 넣지 않는다 (PRD S-09-5).
            Http::error(503, 'API 토큰 귀속 계정이 올바르지 않습니다(서버 설정)');
        }

        // 인증 값은 들고 다니지 않는다 — 업로드 경로가 쓰는 것은 id·username·role·team_id 다.
        unset($row['password_hash'], $row['mfa_secret'], $row['mfa_last_step']);

        // ⑤ 감사의 「누가」 가 귀속 계정이 되도록 현재 사용자 자리에 앉힌다 (Audit::actor).
        //    세션이 없으므로 session_id 는 없다 → detail.login_ref 도 생기지 않는다(맞는 동작).
        self::$user     = $row;
        self::$resolved = true;

        return $row;
    }

    /**
     * 토큰 경로의 거부 기록 (ARCH 4.2 「감사의 구분」, 7.4 `auth.token`).
     * 대상은 **라우트 패턴**뿐이다 — 계정 정보도 토큰 값도 남기지 않는다.
     */
    private static function denyApiToken(string $reason): void
    {
        Audit::deny('auth.token', $reason, [
            'target_type' => 'system',
            'target_name' => defined('TBOX_ROUTE')
                ? (string) constant('TBOX_ROUTE')
                : Http::method() . ' ' . Http::path(),
        ]);
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
        $mfaStep = null;   // 이번 로그인에서 실제로 쓴 TOTP 스텝 (R-45). 세션 발급과 같은 트랜잭션에서 기록한다
        if ($row['mfa_enabled'] === true) {
            $secret = is_string($row['mfa_secret']) ? $row['mfa_secret'] : '';
            $code   = is_string($otp) ? trim($otp) : '';

            if ($code === '') {
                self::denyMfa($name, (int) $row['id'], 'code_required');
                throw new AuthMfaRequiredException('2단계 인증 코드가 필요합니다');
            }

            $step = $secret === '' ? null : Totp::verifyStep($secret, $code);
            if ($step === null) {
                self::countFailure((int) $row['id']);
                self::denyMfa($name, (int) $row['id'], 'bad_code');
                return null;
            }

            /*
             * 맞았지만 **이미 쓴 코드** 인가 (13단계 — SECURITY R-45, RFC 6238 §5.2, PRD T-31).
             * ±1 창(약 90초) 안에서는 같은 6자리가 여러 번 통과했다(2026-09-12 실측: 연속 2회 200).
             * 어깨너머로 코드를 본 사람에게 그 90초가 그대로 기회였다. 성공한 스텝을
             * `users.mfa_last_step` 에 남기고, 그 이하 스텝은 맞아도 거부한다.
             * 창(±1)은 그대로 둔다 — 좁히면 시계가 조금 어긋난 휴대폰이 못 들어온다.
             * 재사용도 **실패로 센다**: 맞은 코드를 반복해 던지는 것은 정상 사용이 아니다.
             */
            $lastStep = $row['mfa_last_step'] === null ? null : (int) $row['mfa_last_step'];
            if (self::isMfaStepUsed($lastStep, $step)) {
                self::countFailure((int) $row['id']);
                self::denyMfa($name, (int) $row['id'], 'code_reused');
                return null;
            }
            $mfaStep = $step;
        }

        // 8) 성공 — 세션 재발급 (ARCH 4.0)
        $userId = (int) $row['id'];
        $oldId  = self::cookieSessionId();
        $token  = bin2hex(random_bytes(32));
        $newId  = hash('sha256', $token);

        $db->begin();
        try {
            $db->run(self::SQL_CLEAR_FAILED, [':id' => $userId]);
            // 쓴 코드의 스텝을 기준선으로 올린다 — 같은 트랜잭션이라 "세션은 났는데 기준선은
            // 안 올라간" 틈이 생기지 않는다 (SECURITY R-45).
            if ($mfaStep !== null) {
                self::markMfaStep($userId, $mfaStep);
            }
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
            // 11단계 — 로그인 응답의 `next` 를 계산하는 값 (PRD F-16, ARCH 4.0)
            'must_change_password' => $row['must_change_password'] === true,
            'mfa_setup_required'   => $row['mfa_setup_required'] === true,
            /*
             * 16-1 (PRD F-23) — **로그인은 재인증 창을 열지 않는다.**
             * SQL_INSERT_SESSION 에 reauth_at 이 없으므로 새 세션 행의 값은 NULL 이고,
             * 여기서도 거짓으로 고정한다. "관리 영역 첫 진입에 반드시 한 번 묻는다" 는
             * 사용자 요구(ARCH 4.0 재인증 행)가 이 두 줄로 지켜진다.
             */
            'reauth_fresh' => false,
            'reauth_at'    => null,
        ];
        return self::$user;
    }

    /**
     * 최초 로그인 강제 절차에서 **다음에 무엇을 해야 하나** (11단계, PRD F-16, ARCH 4.0).
     *
     * 순서가 있다: 비밀번호 변경 → 2단계 인증 등록 → 끝(null).
     * 이 함수는 **판단을 한 곳에 모으기 위한 것**이다 — 로그인 응답(`next`)·`/api/me`·
     * 라우터 관문이 전부 같은 값을 보고 움직여야 화면과 서버가 어긋나지 않는다.
     * 값은 언제나 DB 에서 읽은 사용자 배열에서 나오며 요청 값은 섞이지 않는다 (PRD S-01).
     *
     * @param array<string,mixed> $user Auth::currentUser() 또는 Auth::login() 이 돌려준 배열
     * @return 'password_change'|'mfa_setup'|null
     */
    public static function nextStep(array $user): ?string
    {
        if (($user['must_change_password'] ?? false) === true) {
            return 'password_change';
        }
        if (($user['mfa_setup_required'] ?? false) === true) {
            return 'mfa_setup';
        }
        return null;
    }

    /**
     * 임시 비밀번호 생성 (11단계, PRD F-16 ①, ARCH 4.0, SECURITY R-39).
     *
     * 16자 · 무작위는 `random_int` 만 쓴다(`mt_rand`·`rand`·`uniqid` 는 예측 가능하다).
     * 알파벳에서 `0 O 1 l I` 를 뺀 이유는 사람이 **입으로 전하고 손으로 옮겨 적기** 때문이다 —
     * 헷갈리는 글자가 있으면 "안 된다" 는 문의가 늘고, 그때마다 재발급이 오가면서
     * 임시 비밀번호가 더 많은 경로로 돌아다닌다.
     *
     * 이 값은 만든 자리(생성·재발급 응답)에서 딱 한 번 나가고 어디에도 저장하지 않는다 —
     * 저장되는 것은 Argon2id 해시뿐이고, 감사 로그에는 `temp_password_issued: true` 만 남는다.
     */
    public static function generateTempPassword(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
        $max      = strlen($alphabet) - 1;
        $out      = '';
        for ($i = 0; $i < 16; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }
        return $out;
    }

    /**
     * 비밀번호 변경 (PRD 7.7 "인증 · 비밀번호 변경 · 다른 기기 세션 강제 종료", S-09-4, R-10).
     *
     * 하는 일
     *   1) **현재 비밀번호**를 다시 확인한다 — 세션이 잠깐 새어 나가도 비밀번호까지 바꾸지 못하게.
     *   2) 새 비밀번호가 10자 이상인지, **현재와 다른 값**인지 본다 (PRD S-09-4, F-16 `same_password`).
     *   3) Argon2id 해시로 갈아 끼우고, **지금 이 세션만 남기고** 그 사용자의 세션을 전부 지운다.
     *      (바꾼 기기에서 다시 로그인하게 만들 이유는 없고, 다른 기기는 즉시 끊겨야 한다.)
     *   4) 결과를 감사 로그에 남긴다. 비밀번호는 옛것도 새것도 남기지 않는다.
     *
     * 실패해도 응답 문구는 호출자가 정하고, 여기서는 **사유 코드**만 돌려준다.
     *
     * @param array<string,mixed> $actor 현재 로그인한 사용자 (요청 값이 아니다)
     * @return array{0:bool,1:?string,2:int} [성공 여부, 사유 코드(bad_credentials·weak_password·same_password), 끊은 세션 수]
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
            /*
             * 13단계 (SECURITY R-48, PRD T-30) — 로그인 뒤 창구도 **같은 카운터**로 센다.
             * 여기에 집계가 없던 동안, 세션 쿠키를 훔친 사람은 현재 비밀번호를 무제한으로
             * 추측할 수 있었다(2026-09-12 실측: 3회 시도 → 400 ×3, failed_count 0 유지).
             * 5회에 잠기고, 잠기는 순간 그 계정의 세션이 전부 끊긴다 — 훔친 쿠키도 함께 죽는다.
             * 기록(Audit::deny)을 먼저 하는 이유: 세션을 끊은 뒤에는 actor 를 읽을 수 없다.
             */
            self::countFailureAndRevoke($userId);
            return [false, 'bad_credentials', 0];
        }

        /*
         * 지금 이 변경이 **최초 로그인 강제 절차** 안에서 일어난 것인가 (11단계, PRD F-16).
         * 값은 DB 의 현재 플래그이고 요청이 정하지 않는다. 감사 detail 의 `forced` 로만 쓴다
         * (ARCH 7.5 `forced`) — "임시 비밀번호를 받은 사람이 실제로 첫 로그인에서 바꿨나" 를
         * 나중에 로그만으로 셀 수 있게 하는 값이다 (SECURITY R-39).
         */
        $forced = $row['must_change_password'] === true;

        /*
         * 현재와 **같은 값**으로 바꾸는 것은 거부한다 (11단계, PRD 8장 `/api/me/password` 행).
         * 강제 절차의 핵심은 "발급자가 아는 값을 무효로 만드는 것" 인데, 같은 값을 다시 넣으면
         * 플래그만 내려가고 임시 비밀번호는 그대로 살아 있다 — 절차가 형식만 남는다.
         * 비교는 해시로만 한다(평문끼리 비교하지 않는다).
         */
        if (password_verify($new, (string) $row['password_hash'])) {
            Audit::deny('auth.password_change', 'same_password', $target);
            return [false, 'same_password', 0];
        }

        // 10자 이상 (PRD S-09-4). 글자 수로 센다 — 한글 10자도 10자다.
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

            $detail = ['sessions_revoked' => $revoked];
            if ($forced) {
                $detail['forced'] = true;     // ARCH 7.5 `forced`
            }
            Audit::allow('auth.password_change', $target + ['detail' => $detail]);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollback();
            throw $e;
        }

        return [true, null, $revoked];
    }

    /**
     * 관리 기능 재인증 — sudo 모드 (16-1, PRD F-23 · 8장 `/api/me/reauth` 행, ARCH 4.0).
     *
     * 하는 일
     *   1) **현재 비밀번호**를 다시 확인한다. 2단계 인증 코드는 다시 묻지 않는다 —
     *      로그인에서 이미 거쳤고(순차 2단계), 관리 영역에서 더 묻는 것은 사용자 결정 밖이다.
     *   2) 맞으면 **지금 이 세션 행**의 `reauth_at = now()` 를 놓는다(다른 기기 세션은 그대로).
     *   3) 틀리면 로그인 뒤 창구와 **같은 카운터**로 센다 — 5회면 계정이 잠기고 그 순간
     *      그 계정의 세션이 전부 끊긴다 (13단계 규칙, SECURITY R-48 · R-59).
     *   4) 성공·실패 모두 감사 로그에 남는다 (`auth.reauth allow` / `deny bad_credentials`).
     *
     * **권한을 만들지 않는다.** 이 함수는 "비밀번호를 다시 확인했다" 는 사실만 기록한다.
     * 무엇을 할 수 있는지는 여전히 Authz 가 판단하고, 관문 [3-2] 는 인가 **뒤**에 있다 —
     * Employee 가 재인증해도 `/api/users` 는 `authz.route deny` 다 (PRD T-36).
     *
     * 비밀번호는 응답·감사 로그·app.log 어디에도 남지 않는다 (PRD 7.7).
     *
     * @param array<string,mixed> $user 현재 로그인한 사용자 (요청 값이 아니다)
     * @return array{0:bool,1:?string} [성공 여부, 창이 열려 있는 끝 시각(ISO) 또는 null]
     */
    public static function reauth(array $user, string $password): array
    {
        $db        = Db::instance();
        $userId    = (int) $user['id'];
        $sessionId = (string) ($user['session_id'] ?? '');

        $target = [
            'target_type' => 'user',
            'target_id'   => $userId,
            'target_name' => (string) $user['username'],
        ];

        $row = $db->run(self::SQL_LOAD_BY_ID, [':id' => $userId])->fetch();
        if (!is_array($row) || !password_verify($password, (string) $row['password_hash'])) {
            // 기록을 먼저 남긴다 — 세션이 끊긴 뒤에는 actor 를 읽을 수 없다
            // (Auth::changePassword 와 같은 순서).
            Audit::deny('auth.reauth', 'bad_credentials', $target);
            self::countFailureAndRevoke($userId);
            return [false, null];
        }

        if ($sessionId === '') {
            // 세션 없이 여기 닿는 경로는 없다(라우터가 requireLogin 을 먼저 통과시킨다).
            // 그래도 빈 값으로 UPDATE 를 던지지 않는다 — 0행 갱신을 성공으로 보고할 수는 없다.
            throw new RuntimeException('재인증할 세션이 없습니다');
        }

        $updated = $db->run(self::SQL_SET_REAUTH, [
            ':id'         => $sessionId,
            ':reauth_min' => self::REAUTH_MINUTES,
        ])->fetch();
        if (!is_array($updated)) {
            throw new RuntimeException('재인증 시각을 기록하지 못했습니다');
        }

        /*
         * 맞혔으면 실패 카운터를 0 으로 되돌린다 — 로그인 성공(SQL_CLEAR_FAILED)·비밀번호 변경
         * 성공(SQL_SET_PASSWORD)과 **같은 규칙**이다. 두 번 잘못 치고 세 번째에 맞힌 사람이
         * 며칠 뒤 세 번 더 잘못 치면 잠기는 상태를 남기지 않는다. 카운터를 지우려면 **현재
         * 비밀번호를 맞혀야** 하므로, 쿠키만 훔친 사람에게는 이 경로가 열리지 않는다.
         */
        $db->run(self::SQL_CLEAR_FAILED, [':id' => $userId]);

        Audit::allow('auth.reauth', $target + ['detail' => ['window_minutes' => self::REAUTH_MINUTES]]);

        return [true, (string) $updated['reauth_until']];
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
     * 인증 실패 1회 기록 (PRD S-09-4 "실패 5회 10분 잠금").
     * 비밀번호 오류와 2단계 인증 오류가 **같은 카운터**를 쓴다 — 비밀번호를 알아낸 공격자가
     * 코드 6자리를 무제한 시험하지 못하게 하는 것이 목적이다.
     *
     * 13단계(SECURITY R-48)에서 **public** 이 되었다. 로그인(`/api/login`)만이 아니라
     * **로그인 뒤** 비밀번호·코드를 다시 묻는 창구(`/api/me/password`·`/api/me/mfa/confirm`·
     * `/api/me/mfa/disable`)도 같은 카운터를 써야 하기 때문이다. 그 창구들은 세션만 있으면
     * 닿을 수 있어서, 세션을 훔친 사람이 비밀번호와 OTP 를 무제한으로 시험할 수 있었다
     * (2026-09-12 실측: 틀린 현재 비밀번호 3회 → 400 ×3, `failed_count` 0 유지).
     *
     * @return bool 이번 실패로 **계정이 잠겼나**. 잠겼으면 호출자가 revokeAllSessions() 로
     *              그 계정의 세션을 회수한다 — 잠긴 계정의 창이 계속 열려 있으면 잠금이
     *              "다음 로그인부터" 가 되어 이미 들어와 있는 공격자를 막지 못한다.
     */
    public static function countFailure(int $userId): bool
    {
        $row = Db::instance()->run(self::SQL_COUNT_FAILED, [
            ':id'         => $userId,
            ':max_failed' => self::MAX_FAILED,
            ':lock_min'   => self::LOCK_MINUTES,
        ])->fetch();

        return is_array($row) && $row['is_locked'] === true;
    }

    /**
     * 실패 1회를 세고, 그 실패로 잠겼으면 **그 계정의 모든 세션을 끊는다**
     * (13단계 — SECURITY R-48, PRD T-30).
     *
     * 로그인 뒤 창구(비밀번호 변경·2단계 인증 확정/해제)의 실패는 이 함수로 센다.
     * 잠긴 순간 세션이 사라지므로 다음 요청은 401 이 되고, 다시 들어오려면 10분을 기다려야 한다.
     * 자기 세션도 함께 끊긴다 — 본인이 비밀번호를 5번 틀린 경우도 같은 취급이다(예외를 두면
     * 그 예외가 곧 공격 경로다).
     *
     * @return bool 잠겼나
     */
    public static function countFailureAndRevoke(int $userId): bool
    {
        $locked = self::countFailure($userId);
        if ($locked) {
            self::revokeAllSessions($userId);
        }
        return $locked;
    }

    /**
     * 2단계 인증 코드의 재사용을 막는 기준선을 올린다 (13단계, SECURITY R-45).
     * 성공한 검증 뒤에만 부른다 — 실패한 코드는 기준선을 올리지 않는다.
     */
    public static function markMfaStep(int $userId, int $step): void
    {
        Db::instance()->run(self::SQL_SET_MFA_STEP, [':id' => $userId, ':step' => $step]);
    }

    /**
     * 이 스텝의 코드를 **이미 썼나** (13단계, SECURITY R-45, RFC 6238 §5.2).
     *
     * @param int|null $lastStep users.mfa_last_step (한 번도 쓴 적이 없으면 null)
     */
    public static function isMfaStepUsed(?int $lastStep, int $step): bool
    {
        return $lastStep !== null && $step <= $lastStep;
    }
}
