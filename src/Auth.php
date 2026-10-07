<?php
declare(strict_types=1);

// 사이트 Q:F-13 P1 — 처리방침·약관 동의 관문(requireLogin)이 판정 값을 읽는 곳. 부트스트랩 목록에 줄을 더하지 않고 여기서 읽는다.
require_once __DIR__ . '/Legal.php';

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
 * 비밀번호는 맞았는데 그것이 **발급 뒤 72시간이 지난 임시 비밀번호**일 때 던진다
 * (SECURITY R-39 — 보안 넷 ④ 2026-09-26, 사용자 결정 d52).
 * 핸들러(src/handlers/login.php)가 잡아서 401 + Auth::MSG_TEMP_PASSWORD_EXPIRED 로 답한다.
 *
 * AuthMfaRequiredException 과 같은 자리의 정보다 — **비밀번호가 맞은 사람에게만** 나간다(틀리면 401 동일 문구).
 * 만료된 임시 비밀번호는 그 값을 아는 사람에게도 쓸모가 없으므로 이 문장이 새로 알려 주는 것은 없다.
 * 실패 횟수를 올리지 않는다 — 본인이 늦게 들어온 것이지 추측이 아니다(추측은 5)의 비밀번호 틀림에서 센다).
 */
final class AuthTempPasswordExpiredException extends RuntimeException
{
}

/**
 * 지문·얼굴 로그인에서 **열쇠의 주인임이 확인된 뒤** 그 열쇠가 해제됐거나 90일 동안 쓰지 않아 꺼졌을 때 던진다 (T:F-72 1.4 로그인 8).
 * 핸들러(src/handlers/passkey_login.php)가 잡아서 401 + Http::MSG_PASSKEY_REVOKED 로 답한다 — 실패 응답 가운데 이것만 문장이 다르다.
 * 서명이 맞은 사람(그 휴대폰을 쥔 사람)에게만 가는 정보라 새로 새는 것이 없고, 화면은 이 답을 보고 「이 기기 표시」를 끈다.
 */
final class AuthPasskeyRevokedException extends RuntimeException
{
}

/**
 * Q:F-9 — 1회용 로그인 링크를 소비하려는 순간 이미 쓰였거나 30초가 지났을 때(동시에 두 번 연 경우의 뒤 요청) 던진다.
 * Auth::openSessionFromLink 안에서만 던지고 잡는다 — 세션 발급 트랜잭션을 되돌리는 신호다(밖으로 나가지 않는다).
 */
final class AuthSessionLinkUsedException extends RuntimeException
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
    /**
     * 세션 쿠키 이름 — `__Host-` 접두 (디버깅 묶음 S2 · 사용자 결정 d72 ① · SECURITY 6.7 「세션 쿠키 `__Host-`」).
     *
     * 접두가 브라우저에게 약속을 강제한다: 이 이름의 쿠키는 **Secure · Path=/ · Domain 없음**(발급한 호스트 한 곳)일 때만
     * 받아들여진다. 그래서 다른 `*.mso.so` 하위 도메인(인터넷 사이트)이 장악돼도 `Domain=mso.so` 로 **같은 이름의 쿠키를
     * 심어**(세션 고정 · 로그인 바꿔치기) 이 사이트에 실어 보낼 수 없다 — 그쪽이 심을 수 있는 이름은 접두 없는 것뿐이고,
     * 서버는 이 이름만 읽는다. 속성은 `Http::sendCookie` 한 곳이 늘 이 셋을 붙인다(Domain 은 붙이지 않는다).
     * 이 값은 시험 틀(`tests/**`)도 같은 글자로 쓴다 — 바꾸면 `tests/dbg_s2_security.sh` 의 정적 대조가 알린다.
     */
    public const COOKIE_NAME = '__Host-tbox_session';

    /**
     * v2.30 까지의 옛 이름 — **읽지 않는다**. 실려 오면 만료 머리(Max-Age=0)만 보내 브라우저에서 치운다
     * (`dropLegacyCookie`). 옛 쿠키로 로그인 상태가 이어지지 않으므로 반영 순간 모두 한 번 다시 로그인한다(d72 ① 받아들임).
     * 옛 세션 행은 지우지 않는다 — 유휴 30분·절대 8시간에 스스로 끝나고, 다음 로그인의 만료 행 정리(SQL_PURGE_EXPIRED)가 치운다.
     */
    public const LEGACY_COOKIE_NAME = 'tbox_session';

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

    /**
     * 2단계 인증이 **필수**인 역할 (PRD F-52, 사용자 결정 2026-09-16 「Admin 만」).
     * Manager 를 넣으려면 여기 한 단어 — 문서(PRD F-52·SECURITY R-85) 먼저.
     */
    public const MFA_REQUIRED_ROLES = ['Admin'];

    /** PRD S-09-4: 실패 5회 → 10분 잠금 */
    private const MAX_FAILED   = 5;
    private const LOCK_MINUTES = 10;

    /**
     * 임시 비밀번호 유효기간(시간) — SECURITY R-39, 보안 넷 ④(2026-09-26, 사용자 결정 d52).
     * Admin 이 발급한(계정 만들기·재발급) 임시 비밀번호는 이 시간 안에 첫 로그인해서 바꿔야 한다.
     * 지나면 비밀번호가 맞아도 401 — 관리자가 다시 발급한다. 판정은 SQL 이 한다(PHP·DB 시계를 섞지 않는다).
     */
    public const TEMP_PASSWORD_HOURS = 72;

    /** 만료된 임시 비밀번호로 로그인했을 때의 문장 — 비밀번호가 맞은 사람에게만 나간다(MSG_MFA_REQUIRED 와 같은 자리) */
    public const MSG_TEMP_PASSWORD_EXPIRED = '임시 비밀번호가 만료되었습니다(발급 뒤 72시간). 관리자에게 다시 발급을 요청하세요';

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
     * 「시작 뒤 30분」이 아니다. 요청이 오면 `sessions.act_last_seen_at` 을 밀고(SQL_TOUCH — Q:F-4 P-3 부터 60초에 한 번,
     * U-3 부터 사람이 누른 요청만 — 자동 요청 `BACKGROUND_HEADER` 는 밀지 않는다),
     * 그 값이 이 창보다 오래됐을 때만 해제한다 — 쓰는 중에 화면이 갑자기 원래 역할로 돌아가면
     * 관리자는 자기가 무엇을 보고 있는지 알 수 없게 된다 (사용자 결정 2026-09-15 ③).
     * 판정은 SQL_LOOKUP 의 `act_fresh` 가 **DB 시계 하나**로 한다 (reauth_fresh 와 같다).
     * 늘리는 요청 파라미터·연장 API 는 만들지 않는다 (R-59 와 같은 원칙).
     */
    public const ACT_IDLE_MINUTES = 30;

    /**
     * Q:F-9 — **토큰 출신 세션**(`sessions.origin = 'token'`)의 유휴 한도(분) (t-box F-75 ⑤, ARCH 4.9).
     *
     * 「이 PC 로그인 전용」 토큰의 1회용 링크로 만든 세션은 비밀번호·2단계 없이 생기므로 자리 비움의 창을 짧게 둔다.
     * `.env SESSION_IDLE_MIN`(일반 세션 30분)과 **따로** 코드 상수다 — 운영 설정 한 줄로 이 창이 넓어지지 않게.
     * 판정은 SQL_LOOKUP 이 DB 시계로 한다(origin 이 'token' 일 때만 이 값). 절대 만료(SESSION_MAX_HOURS 8시간)는 같다.
     */
    public const TOKEN_SESSION_IDLE_MIN = 10;

    /** Q:F-9 — 1회용 로그인 링크 코드의 모양(32바이트 난수의 base64url 43자 — 공유 링크 `{token}` 과 같은 모양) */
    public const SESSION_LINK_CODE_RE = '/^[A-Za-z0-9_-]{43}$/';

    /** Q:F-9 — 1회용 로그인 링크의 수명(초). session_links 의 CHECK(30초 상한)와 같은 값 — 늘리려면 둘을 함께 */
    public const SESSION_LINK_TTL_SECONDS = 30;

    /** Q:F-9 — 한 토큰이 1분에 받을 수 있는 링크 수. 넘으면 429 (`session.link deny rate_limited`) */
    public const SESSION_LINK_PER_MINUTE = 5;

    /**
     * Q:F-10 (ARCH 4.9 · 4.10) — 묶인 IP 판정을 **스스로 답하는** 창구 하나: 1회용 로그인 링크(`me_session_link.php`).
     *
     * 이 창구는 IP 가 틀린 요청을 분당 셈(RV-QF9-4)에 넣은 뒤 403 `ip_mismatch` + `session.link deny ip_mismatch`(경보 AL-24 의 재료)로
     * 답한다 — 비서가 그 답을 보고 「이 PC 다시 등록」을 안내한다(Q:F-9 계약). 그래서 `requireApiToken` 은 이 라우트에서만 IP 불일치로
     * 끝내지 않고 판정 값(`apiTokenIpOk()`)만 넘긴다. 비교는 같은 SQL(`SQL_FIND_API_TOKEN` 의 `ip_ok`) 한 번이다 — 판정 코드는 한 벌.
     * 값은 라우터가 매칭한 **라우트 패턴**(`TBOX_ROUTE` — 코드가 정한 표의 글자)과 견주며 요청 값이 아니다.
     */
    public const SESSION_LINK_ROUTE = 'POST /api/me/session-link';

    /** Q:F-10 — 묶인 IP 밖에서 쓴 토큰의 401 문장(비서가 사람에게 그대로 보인다 · 무엇을 하면 되나). 코드 `token_ip_mismatch`. */
    public const MSG_TOKEN_IP_MISMATCH = '이 토큰이 묶인 IP 가 아닌 곳에서 요청했습니다 — 이 PC 를 다시 등록하세요(내 계정 → 프로그램 토큰)';

    /**
     * 「사람이 누르지 않은 주기 요청」의 표식 — 헤더 이름과 값 (Q:F-4 U-3, 2026-09-24, ARCH 4.0 · 5.20).
     *
     * 알림 폴링·편집 heartbeat 처럼 화면이 **저절로** 보내는 요청은 이 헤더를 단다(`editor.js`).
     * 그 요청은 세션을 **확인만** 하고 `last_seen_at`·`act_last_seen_at` 을 밀지 않는다(SQL_TOUCH 를 건너뛴다) —
     * 그래야 켜 둔 탭이 「활동 없는 30분 로그아웃」·「역할로 보기 30분」을 끝없이 늘리지 않는다.
     * 헤더를 **빼는** 것은 자기 세션을 늘리는 것뿐이라(사람이 누른 요청과 같다) 권한 상승이 아니고,
     * **다는** 것은 자기 세션을 줄이는 것뿐이다. 값은 이 두 상수와만 견주고 SQL 에 들어가지 않는다.
     * 쓰기 요청(저장·댓글)은 화면이 이 헤더를 달지 않으므로 늘 연장된다(사람 행동).
     */
    public const BACKGROUND_HEADER = 'X-Qaredi-Background';
    public const BACKGROUND_VALUE  = '1';

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
               -- 5-A″ — 사람이 알아보는 이름(없으면 NULL). 셸의 사용자 칸이 보인다. 권한 판단에 쓰지 않는다.
               u.display_name  AS display_name,
               -- 7-D3 — 화면 취향(고대비 화면 · DESIGN 2.16). 셸·런처가 <html data-contrast> 를 그린다. 권한 판단에 쓰지 않는다.
               u.high_contrast AS high_contrast,
               u.role          AS role,
               u.team_id       AS team_id,
               u.is_active     AS is_active,
               u.mfa_enabled   AS mfa_enabled,
               -- 11단계 (PRD F-16) — 최초 로그인 강제 절차의 두 플래그.
               -- 라우터 관문이 요청마다 보는 값이라 세션 조회에 함께 싣는다(쿼리 1회 유지).
               u.must_change_password AS must_change_password,
               u.mfa_setup_required   AS mfa_setup_required,
               u.invited_account AS invited_account,
               u.mfa_recovery_required AS mfa_recovery_required,
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
               s.act_started_at    AS act_started_at,
               (s.act_role IS NOT NULL AND u.role = 'Admin')            AS act_role_ok,
               (s.act_last_seen_at IS NOT NULL
                AND s.act_last_seen_at > now() - make_interval(mins => CAST(:act_idle_min AS integer)))
                              AS act_fresh,
               -- Q:F-9 (ARCH 4.9) — 이 세션이 어디서 났나(login · token)와 token 이면 그 토큰의 행 번호.
               -- 감사 분류(auth=token_session · token_id)와 재인증 집합(Authz::requiresReauth)이 이 두 값을 본다.
               s.origin        AS origin,
               s.token_id      AS token_id
          FROM sessions s
          JOIN users   u ON u.id = s.user_id
          -- Q:F-9 — token 세션만 그 토큰을 함께 본다(login 세션은 token_id 가 NULL 이라 붙는 행이 없다).
          LEFT JOIN api_tokens t ON t.id = s.token_id
         WHERE s.id = :id
           AND s.expires_at   > now()
           -- 유휴 한도: login 세션은 `.env SESSION_IDLE_MIN` 그대로, token 세션만 코드 상수 TOKEN_SESSION_IDLE_MIN(10분).
           AND s.last_seen_at > now() - make_interval(mins => CASE WHEN s.origin = 'token'
                                                                   THEN CAST(:token_idle_min AS integer)
                                                                   ELSE CAST(:idle_min AS integer) END)
           -- Q:F-9 — login 세션은 이 줄의 앞쪽이 참이라 전과 같다. token 세션은 ① 그 토큰이 살아 있고(회수·만료 아님 ·
           -- 범위 session.open) ② 세션을 만든 IP 가 그 토큰의 묶인 IP 이고 ③ **지금 요청의 IP** 도 그 IP 일 때만 조회된다.
           -- 토큰을 회수하면(1대 규칙의 자동 회수 · 비밀번호 변경 · 관리자 회수) 그 토큰으로 연 세션도 다음 요청에서 끝난다.
           AND (s.origin = 'login'
                OR (s.origin = 'token'
                    AND t.revoked_at IS NULL
                    AND t.expires_at > now()
                    -- Q:F-10 — 범위는 원자 집합(ARCH 4.10). 「이 PC 로그인 전용」 원자가 들어 있으면 된다.
                    AND 'session.open' = ANY (string_to_array(t.scope, '+'))
                    AND t.bound_ip = s.ip
                    AND s.ip = CAST(:cur_ip AS inet)
                    -- 검토 RV-QF9-3 — 2단계를 끄면 이미 연 토큰 세션도 끝난다(로그인 전용 토큰의 자격 = 2단계 켜짐).
                    AND u.mfa_enabled))
        SQL;

    /**
     * 요청 1회당 한 번 도는 활동 갱신.
     *
     * 26단계 (PRD F-39) — 「역할로 보기」의 만료는 **활동 없는 30분**이다. 그래서 보는 중인
     * 세션(`act_role IS NOT NULL`)은 여기서 `act_last_seen_at` 도 함께 민다. 기존 문장에 한 열을
     * 더한 것이라 **쿼리 수가 늘지 않는다**. 보는 중이 아니면 NULL 그대로 둔다 — 값이 있는 것
     * 자체가 "보는 중" 의 흔적이 되면 안 된다.
     *
     * Q:F-4 P-3 (2026-09-24) — **60초에 한 번만** 쓴다(`last_seen_at` 이 60초보다 오래됐을 때만).
     * 전에는 인증된 요청마다 UPDATE 했다(폴링·heartbeat 까지). 조건은 SQL 에만 있고 요청 값은 0이다.
     * 대가: 기록(`last_seen_at`)이 실제 마지막 활동보다 최대 60초 늦을 수 있어, 무활동 만료(30분)와 역할로 보기 만료(30분)가
     * 실제 무활동보다 **최대 60초 일찍** 닫힐 수 있다(늦게 닫히는 길은 없다 — 보수적. SECURITY 수용 근거, 독립 검토 P-3 Low-1).
     * 두 열은 같은 문장이라 함께 움직인다 — 역할로 보기를 시작하는 문장이 act_last_seen_at 을 따로 채운다.
     *
     * Q:F-4 U-3 (2026-09-24) — **자동 요청**(`BACKGROUND_HEADER`)은 이 문장을 아예 부르지 않는다(currentUser).
     * 세션 판정(SQL_LOOKUP)은 그대로라 무활동 창이 지난 세션은 자동 요청에도 401 이다.
     */
    private const SQL_TOUCH = <<<'SQL'
        UPDATE sessions
           SET last_seen_at     = now(),
               act_last_seen_at = CASE WHEN act_role IS NULL THEN NULL ELSE now() END
         WHERE id = :id
           AND last_seen_at < now() - interval '60 seconds'
        SQL;

    /**
     * 「역할로 보기」 해제 (26단계, PRD F-39).
     * 만료(idle)·강등(role_lost)일 때 Auth 가, 사용자가 껐을 때 me_act_stop.php 가 같은 모양으로 쓴다.
     */
    private const SQL_CLEAR_ACT = <<<'SQL'
        UPDATE sessions
           SET act_role          = NULL,
               act_team_id       = NULL,
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
               must_change_password, mfa_setup_required, invited_account, mfa_recovery_required,
               (locked_until IS NOT NULL AND locked_until > now()) AS is_locked,
               (expires_at IS NOT NULL AND expires_at < now())     AS is_expired
          FROM users
         WHERE username = :username
        SQL;

    /**
     * 개인 프로그램 토큰의 주인을 **로그인과 같은 열 목록**으로 읽는다 (F-59, ARCH 4.6).
     *
     * SQL_LOAD_USER 와 열이 한 글자도 다르지 않다 — 다르면 토큰 경로의 `$user` 가 세션의
     * `$user` 와 다른 모양이 되고, 그날부터 두 경로의 판정이 갈린다. 다른 것은 찾는 열쇠
     * (아이디 ↔ id)뿐이다.
     */
    private const SQL_LOAD_USER_BY_ID = <<<'SQL'
        SELECT id, username, password_hash, role, team_id, is_active,
               failed_count, locked_until,
               mfa_enabled, mfa_secret, mfa_last_step,
               must_change_password, mfa_setup_required, invited_account, mfa_recovery_required,
               (locked_until IS NOT NULL AND locked_until > now()) AS is_locked,
               (expires_at IS NOT NULL AND expires_at < now())     AS is_expired
          FROM users
         WHERE id = :id
        SQL;

    /**
     * 개인 토큰 조회 (F-59 → **F-66**, ARCH 4.6 · 4.7 · 6.2-5) — **해시로만** 찾는다.
     * 원문은 서버 어디에도 없으므로 이 조회가 유일한 경로이고, 회수된 행(`revoked_at`)은
     * 애초에 나오지 않는다(회수된 토큰은 「틀린 토큰」과 같은 답을 받는다).
     *
     * F-66 — `scope`·`expires_at` 을 **함께** 읽는다. 만료 판정은 `(expires_at <= now())` 을
     * **SQL 이** 해서 `is_expired` 로 돌려준다 — PHP 시계와 DB 시계를 섞지 않는다
     * (`SQL_LOAD_USER` 의 계정 만료 판정과 같은 방식).
     *
     * ★ 이 문장은 두 열이 **있어야** 돈다. 그래서 배포 순서가
     *   **마이그레이션(`2026-09-22_api_tokens_scope_expiry.sql`) → 코드** 다 —
     *   순서를 뒤집으면 토큰 경로 전체가 500 이다(F-59 때의 1분 500 사고).
     */
    private const SQL_FIND_API_TOKEN = <<<'SQL'
        SELECT id, user_id, scope,
               (expires_at <= now()) AS is_expired,
               -- Q:F-10 (ARCH 4.9 · 4.10) — 묶인 IP 가 있는 토큰(「이 PC 로그인 전용」 원자가 든 토큰)은 **지금 요청의 IP** 가
               -- 그 IP 일 때만 참. 비교는 DB 가 한다(INET = INET — IPv6 표기 차이 없음). 묶인 IP 가 없으면 언제나 참.
               -- 요청 IP 를 알 수 없으면(NULL) 묶인 토큰은 거짓(COALESCE — 닫힌 쪽).
               (bound_ip IS NULL OR COALESCE(bound_ip = CAST(:cur_ip AS inet), false)) AS ip_ok
          FROM api_tokens
         WHERE token_hash = :hash AND revoked_at IS NULL
        SQL;

    /**
     * 「마지막으로 쓴 시각」 갱신 (F-59, ARCH 4.6). 요청마다 UPDATE 하지 않는다 —
     * NULL 이거나 60초가 지났을 때만 쓴다(프로그램이 초당 여러 번 부르는 창구다).
     */
    private const SQL_TOUCH_API_TOKEN = <<<'SQL'
        UPDATE api_tokens
           SET last_used_at = now()
         WHERE id = :id
           AND (last_used_at IS NULL OR last_used_at < now() - interval '60 seconds')
        SQL;

    /**
     * 디버깅 묶음 S-5 — 한 계정의 살아 있는 토큰 전부 회수 (revokeAllApiTokens). 행을 지우지 않는다.
     * 돌려받는 것은 감사 한 행씩에 쓸 번호·이름뿐이다 — token_hash 는 읽지 않는다.
     */
    private const SQL_REVOKE_USER_API_TOKENS = <<<'SQL'
        UPDATE api_tokens
           SET revoked_at = now(),
               revoked_by = :by
         WHERE user_id = :uid
           AND revoked_at IS NULL
        RETURNING id, name
        SQL;

    /** revokeAllApiTokens 의 사유 — ARCH 7.4 `api_token.revoke|allow|<사유>` 세 행과 같은 값 */
    private const API_TOKEN_REVOKE_CAUSES = ['password_change', 'password_reset', 'mfa_reset'];

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
               -- 보안 넷 ④ (SECURITY R-39): 임시 비밀번호의 발급 시각도 같은 문장에서 지운다.
               temp_password_issued_at = NULL,
               password_changed_at  = now(),
               updated_at           = now()
         WHERE id = :id
        SQL;

    /**
     * 임시 비밀번호가 유효기간을 넘겼나 (SECURITY R-39, 보안 넷 ④). 판정은 SQL 이 한다 —
     * `SQL_LOAD_USER` 의 Guest 만료(`is_expired`)와 같은 방식(PHP 시계와 DB 시계를 섞지 않는다).
     * 발급 시각이 NULL 이면(이 기능 전 발급·CLI 시드) 만료 없음이다.
     * `SQL_LOAD_USER`·`SQL_LOAD_USER_BY_ID`(두 경로가 같은 열 목록이어야 한다)는 건드리지 않고,
     * must_change_password 인 계정의 로그인에서만 따로 한 번 읽는다.
     */
    private const SQL_TEMP_EXPIRED = <<<'SQL'
        SELECT (temp_password_issued_at IS NOT NULL
                AND temp_password_issued_at < now() - make_interval(hours => CAST(:hours AS integer))) AS temp_expired
          FROM users
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
     * 디버깅 묶음 S2 — 갱신된 뒤의 `failed_count` 와 이름도 함께 돌려준다: 카운터가 **정확히 한도**(MAX_FAILED)에 닿은
     * 이번 실패가 곧 「잠금이 걸린 순간」이다(countFailure 가 감사 `auth.lock` 한 행을 남긴다 — AL-2).
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
        RETURNING (locked_until IS NOT NULL AND locked_until > now()) AS is_locked,
                  failed_count, username
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

    /**
     * Q:F-9 — **토큰 출신 세션** 한 행 (ARCH 4.9). 일반 로그인의 SQL_INSERT_SESSION 과 다른 것은 `origin='token'` · `token_id` 둘뿐이다
     * (절대 만료·IP·UA 는 같은 규칙). reauth_at 은 넣지 않는다 — NULL(로그인과 같다: 링크는 재인증 창을 열지 않는다).
     */
    private const SQL_INSERT_TOKEN_SESSION = <<<'SQL'
        INSERT INTO sessions (id, user_id, expires_at, ip, user_agent, origin, token_id)
        VALUES (:id, :user_id,
                now() + make_interval(hours => CAST(:max_hours AS integer)),
                CAST(:ip AS inet), :user_agent, 'token', :token_id)
        SQL;

    /**
     * Q:F-9 — 1회용 로그인 링크 한 줄을 **코드의 sha256 으로만** 찾는다 (코드 원문은 서버 어디에도 없다).
     * 판정에 쓰는 참/거짓은 전부 **DB 가 계산**한다(시계·IP 비교 — PHP 시계·문자열 비교를 섞지 않는다):
     *   is_used   이미 쓴 링크 · is_expired 30초가 지났다 · token_ok 그 토큰이 지금도 살아 있고(회수·만료 아님 · 범위 session.open)
     *   링크를 받은 IP 가 그 토큰의 묶인 IP 다 · ip_ok 지금 요청의 IP(Http::clientIp)가 링크를 받은 IP 다.
     */
    private const SQL_FIND_SESSION_LINK = <<<'SQL'
        SELECT l.id, l.token_id, l.user_id, u.username,
               (l.used_at IS NOT NULL)      AS is_used,
               (l.expires_at <= now())      AS is_expired,
               (t.revoked_at IS NULL AND t.expires_at > now()
                AND 'session.open' = ANY (string_to_array(t.scope, '+'))   -- Q:F-10 원자 집합(ARCH 4.10)
                AND t.bound_ip = l.ip)      AS token_ok,
               (l.ip = CAST(:cur_ip AS inet)) AS ip_ok
          FROM session_links l
          JOIN api_tokens t ON t.id = l.token_id
          JOIN users      u ON u.id = l.user_id
         WHERE l.code_hash = :hash
        SQL;

    /**
     * Q:F-9 — 링크 **소비**. 한 문장이라 같은 코드로 동시에 두 번 열어도 하나만 행을 받는다(뒤 요청은 앞 트랜잭션이 끝난 뒤
     * `used_at IS NULL` 을 다시 보고 0행). 만료도 같은 문장에서 다시 본다 — 조회와 소비 사이에 30초가 지나면 소비되지 않는다.
     */
    private const SQL_CONSUME_SESSION_LINK = <<<'SQL'
        UPDATE session_links
           SET used_at = now()
         WHERE id = :id
           AND used_at IS NULL
           AND expires_at > now()
        RETURNING id
        SQL;

    /** @var array<string,mixed>|null */
    private static ?array $user = null;
    private static bool $resolved = false;

    /** Q:F-10 — 이 요청의 개인 토큰이 묶인 IP 판정을 통과했나(`requireApiToken` 이 한 번 세운다 · 묶인 IP 없는 토큰은 참). */
    private static bool $apiTokenIpOk = false;

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
            ':id'             => $id,
            ':idle_min'       => Env::getInt('SESSION_IDLE_MIN'),
            ':reauth_min'     => self::REAUTH_MINUTES,
            ':act_idle_min'   => self::ACT_IDLE_MINUTES,
            // Q:F-9 — token 세션만 쓰는 두 값(login 세션의 판정에는 닿지 않는다 — SQL 의 CASE·OR 앞쪽).
            ':token_idle_min' => self::TOKEN_SESSION_IDLE_MIN,
            ':cur_ip'         => Http::clientIp(),
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

        // Q:F-4 U-3 — 자동 요청은 확인만 한다(두 활동 시각을 밀지 않는다). 사람이 누른 요청만 60초 규칙으로 민다.
        if (!self::isBackgroundRequest()) {
            $db->run(self::SQL_TOUCH, [':id' => $id]);
        }

        /*
         * ── 26단계 「역할로 보기」 (PRD F-39, ARCH 5.20, SECURITY R-74) ─────────────────
         *
         * 여기가 이 기능의 **전부**다. 유효 역할(role·team_id)을 이 한
         * 곳에서 만들어 두면 Authz·모든 핸들러가 읽는 `$u` 가 그대로 그 역할이
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
            // 5-A″ — 표시용 이름(없으면 null). **권한이 아니다** — 판정은 id·역할·팀만 본다.
            'display_name' => $row['display_name'] === null ? null : (string) $row['display_name'],
            // 7-D3 — 화면 취향(고대비 화면 · DESIGN 2.16). **권한이 아니다** — 셸·런처가 속성 하나를 그릴 뿐이다.
            //   「역할로 보기」 중에도 실제 사람의 users 행 값이다(H-8 — 따로 코드 0).
            'high_contrast' => $row['high_contrast'] === true,
            // 유효 역할 — 보는 중이면 보는 값, 아니면 실제 값. 아래 real_* 가 언제나 실제 값이다.
            'role'       => $actValid ? $actRole : (string) $row['role'],
            'team_id'    => $actValid
                ? ($row['act_team_id'] === null ? null : (int) $row['act_team_id'])
                : ($row['team_id'] === null ? null : (int) $row['team_id']),
            // 실제 값 — **언제나** users 행 그대로다. 감사·띠·「원래대로」가 이 값을 본다.
            'real_role'          => (string) $row['role'],
            'real_team_id'       => $row['team_id'] === null ? null : (int) $row['team_id'],
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
            'invited_account' => ($row['invited_account'] ?? false) === true,
            'mfa_recovery_required' => ($row['mfa_recovery_required'] ?? false) === true,
            /*
             * 16-1 — 관리 기능 재인증 (PRD F-23, ARCH 4.0). 라우터 관문 [3-2] 가 보는 값이다.
             *   reauth_fresh — 15분 창 안인가. 판정은 위 SQL 이 DB 시계로 했고 앱은 참/거짓만 읽는다.
             *   reauth_at    — 마지막 확인 시각(없으면 null). 화면이 "언제까지 열려 있나" 를
             *                  보여 주는 데만 쓰고 **판정에는 쓰지 않는다**.
             * 요청에서 읽지 않는다 — 언제나 DB 의 현재 값이다 (PRD S-01).
             */
            'reauth_fresh' => $row['reauth_fresh'] === true,
            'reauth_at'    => $row['reauth_at'] === null ? null : (string) $row['reauth_at'],
            /*
             * Q:F-9 (ARCH 4.9) — 이 세션이 어디서 났나. `login`(비밀번호·지문) 또는 `token`(「이 PC 로그인 전용」 링크).
             * 권한이 아니라 **출신**이다: 라우터가 재인증 집합을 고르는 데(Authz::requiresReauth)와 감사 분류
             * (Audit::autoDetail — auth=token_session · token_id)에만 쓴다. 요청에서 읽지 않는다 — DB 의 세션 행 값이다.
             */
            'session_origin'   => (string) $row['origin'] === 'token' ? 'token' : 'login',
            'session_token_id' => $row['token_id'] === null ? null : (int) $row['token_id'],
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
        self::requireConsent($u);
        return $u;
    }

    /**
     * 사이트 Q:F-13 P1 — 처리방침·약관 동의 관문이 열어 두는 **세션 창구**(라우트 패턴 = TBOX_ROUTE 와 같은 표기).
     * 동의하러 가는 화면 · 동의 창구 · 지금 상태(`/api/me`) · 나가는 길(로그아웃)만. 커뮤니티 라우트는 하나도 없다(`/community…` 패턴).
     */
    public const CONSENT_GATE_ALLOW = [
        'GET /consent',
        'GET /api/me',
        'GET /api/me/consents',
        'POST /api/me/consents',
        'POST /api/logout',
        'GET /api/me/delete-request',    // Q:F-13 P2-R1(RV-P2-1) — 동의하지 않은 사람도 탈퇴는 요청한다(약관 제3조 · ARCH 4.11 「탈퇴」) · 재인증은 핸들러가 묻는다
        'POST /api/me/delete-request',
        'POST /api/me/reauth',           // Q:F-13 P2-R2 — 동의 화면 탈퇴 요청의 비밀번호 재인증 · 권한을 주지 않는다(창 표시만 — ARCH 4.11 「순서」) · 패스키 재인증 창구는 열지 않는다
    ];

    /**
     * 처리방침·약관 동의 관문 (사이트 Q:F-13 P1 · 기획 5.2 「기존 회원」 · 결정 PT-4).
     *
     * 자리가 requireLogin 인 이유: **세션 경로의 두 라우터**(t-box `public/index.php` [2] · 커뮤니티 `routes.php` [2])가 모두 이 함수
     * 하나를 부른다 — 「계정 절차」 넘김(최초 로그인 관문 [3-1])은 두 라우터에 따로 있어, 그 자리에 두면 한쪽을 빠뜨린다.
     * **프로그램 토큰 창구는 이 함수를 지나지 않는다**(두 라우터의 [2-T] 는 requireApiToken 만 부른다) — 토큰은 웹에서 만든 것이라
     * 다음 웹 로그인 때 동의한다(기획 5.2). 런처(`/`)와 공개 화면은 requireLogin 을 부르지 않는다.
     *
     * 순서: 최초 로그인 절차(비밀번호 변경 · 2단계 등록)가 남은 사람은 **그 관문이 먼저**다 — 여기서는 지나가고 [3-1] 이 막는다
     * (두 관문이 서로를 /consent ↔ / 로 돌려보내는 고리를 만들지 않는다). 절차를 마친 다음 요청부터 이 관문이 본다.
     * 판정 값은 언제나 DB(user_consents 의 문서별 마지막 줄 — Legal::needsConsent)이고 요청 값은 한 글자도 섞이지 않는다.
     *
     * 막힌 것이 **화면**이면 `/tbox/consent`(보던 곳이 허용 목록 안이면 `?to=` 꼬리표 — Http::returnPath 그대로)로 302,
     * API 면 403 `{error, next:"consent", code:"consent_required"}`. 감사 `auth.gate|deny|consent_required`(다른 관문과 같은 모양).
     *
     * @param array<string,mixed> $u currentUser() 가 돌려준 값
     */
    private static function requireConsent(array $u): void
    {
        if (self::nextStep($u) !== null) {
            return;
        }
        $route = defined('TBOX_ROUTE') ? (string) constant('TBOX_ROUTE') : '';
        if (in_array($route, self::CONSENT_GATE_ALLOW, true)) {
            return;
        }
        if (!Legal::needsConsent((int) $u['id'])) {
            return;
        }
        // Q:F-13 P2-a — 감사는 **사람이 누른 요청만**. 배경 폴링(`X-Qaredi-Background: 1` — 알림·편집 heartbeat)은 같은 403 을 받되
        // 줄을 남기지 않는다(동의 전 탭을 켜 두면 몇 초마다 거부 줄이 쌓였다). 헤더를 빼면 감사가 남을 뿐 — 막는 판정은 같다.
        if (!self::isBackgroundRequest()) {
            Audit::deny('auth.gate', 'consent_required', [
                'target_type' => 'user',
                'target_id'   => (int) $u['id'],
                'target_name' => (string) $u['username'],
                'detail'      => ['next' => 'consent'],
            ]);
        }
        // 라우트 패턴의 경로 쪽이 API 인가 — t-box 는 `/api/…`, 커뮤니티는 `/community/api/…`(두 라우터의 `/api/` 갈림과 같은 기준).
        // 라우트를 모르면(TBOX_ROUTE 없음) API 로 본다 — 302 보다 403 이 닫힌 쪽이다.
        $isApi = $route === '' || preg_match('#\A[A-Z]+ (?:' . preg_quote(COMMUNITY_BASE, '#') . ')?/api/#', $route) === 1;
        if (!$isApi) {
            $back = Http::returnPath((string) ($_SERVER['REQUEST_URI'] ?? ''));
            Http::redirect(Http::url('/consent')
                . ($back !== null && $back !== Http::url('/') ? '?to=' . rawurlencode($back) : ''));
        }
        Http::json([
            'error' => Legal::MSG_CONSENT_REQUIRED,
            'next'  => 'consent',
            'code'  => 'consent_required',
        ], 403);
        exit;
    }

    /** 프로그램용 업로드 토큰의 최소 길이 (PRD F-49 ①, ARCH 4.2 「설정 확인」) */
    public const API_TOKEN_MIN_CHARS = 32;

    /**
     * 개인 토큰의 수명 (F-66 ④, PRD F-66, ARCH 4.7 「만료」).
     *
     * 발급 핸들러(`me_tokens_issue.php`)가 `now() + make_interval(days => :ttl)` 에 이 값을
     * **바인딩으로** 넘긴다 — 180 을 두 곳에 적지 않는다. 마이그레이션이 기존 행을 채운
     * `created_at + 180일` 도 같은 규칙이다(그쪽은 SQL 리터럴이라 값이 둘로 보이지만,
     * 한 번 지나가는 일회성 문장이고 이 상수를 바꾸면 새 토큰부터 따라간다).
     *
     * 지난 토큰은 401 `token_expired` 이고, 목록의 `expires_soon`(14일 안)이 화면에 안내를
     * 띄운다. 창을 늘리거나 줄이려면 여기 한 곳만 고친다.
     */
    public const API_TOKEN_TTL_DAYS = 180;

    /**
     * 프로그램용 토큰 인증 — 개인 토큰 우선, 공용 토큰은 이행용
     * (PRD F-49 · F-50 · F-56 · F-58 · **F-59**, ARCH 4.2 · 4.6, S-09-11, SECURITY R-82 · R-91).
     *
     * 사람의 로그인(requireLogin)과 **다른 길**이다. 세션도 쿠키도 CSRF 도 없고, 헤더
     * `X-API-Token` 하나로 판정한다. 이 함수를 부르는 곳은 라우터 [2-T] 한 곳이고,
     * 그 가지에 들어오는 라우트는 `TBOX_API_TOKEN_ROUTES` 의 **열뿐**이다 — 토큰을 쥔
     * 사람도 그 창구 밖에서는 아무것도 열지 못한다.
     *
     * 판정 순서는 ARCH 4.6 의 표 그대로다.
     *   ① 헤더 없음                      → 401 `missing_header` (F-49 불변)
     *   ② sha256(헤더값)으로 `api_tokens` 조회(회수되지 않은 것)
     *      있으면 **개인 토큰**: 먼저 **만료**를 본다(F-66) — `expires_at <= now()` 면 401
     *      `token_expired` 로 끝낸다. 계정 4조건보다 **앞**이다: 만료된 토큰으로는 계정을
     *      읽지 않는다. 회수는 숨기고(틀린 토큰과 같은 답) 만료는 알려 준다 —
     *      회수는 사람의 결정이고 만료는 시간의 결과라 숨길 이유가 없다(ARCH 4.7).
     *      그다음 그 계정을 로그인과 같은 열 목록으로 읽어 4조건(존재·활성·
     *      미만료·강제 절차 없음)을 본다. 실패 → 403 `user_disabled`(계정을 비활성화하면
     *      그 계정의 토큰이 자동으로 막히는 경로다 — 회수를 따로 하지 않아도 된다).
     *      성공 → `TBOX_API_TOKEN_KIND='personal'`·`TBOX_API_TOKEN_ID=<id>`·
     *      `TBOX_API_TOKEN_SCOPE=<범위>` + `last_used_at` 갱신
     *   ③ 없으면 **공용 토큰**(`.env`): 32자 이상이고 `hash_equals` 일 때만. 귀속 계정의 4조건
     *      실패는 요청이 아니라 **서버 설정** 문제라 503 `user_invalid` 다(F-49 그대로).
     *      → `TBOX_API_TOKEN_KIND='shared'` (`TBOX_API_TOKEN_ID` 는 없다 — 사람 단위 귀속이 없다).
     *      범위 열이 없으므로 `TBOX_API_TOKEN_SCOPE='read+upload'` 로 본다(F-66, 이행용 —
     *      삭제 둘은 이미 개인 전용 창구라 공용 토큰으로는 애초에 열리지 않는다)
     *   ④ 둘 다 아님                      → 401 `bad_token`. **회수된 개인 토큰도 이 갈래다**
     *      (회수됐다는 사실을 요청자에게 알리지 않는다)
     *   ⑤ `$personalOnly` 인 창구를 공용 토큰으로 불렀으면 → 403 `shared_token_not_permitted`
     *
     * `not_configured` 503 은 **더 나오지 않는다** — 개인 토큰이 있는 서버에서 공용 토큰
     * 미설정은 정상 상태다(문장 틀은 ARCH 7.4 표에 남되 쓰이지 않는다). 공용 토큰은
     * 이행용이며, `.env` 의 두 줄을 지우면 공용 경로가 닫힌다(R-82 ✅ 조건).
     *
     * 비교는 언제나 `hash_equals`(타이밍 안전)이고, 개인 토큰은 해시로만 조회한다 —
     * `==`·`===`·`strcmp` 는 앞에서부터 다른 자리를 만나면 바로 끝나므로 응답 시간 차이로
     * 한 글자씩 맞혀 볼 수 있다.
     *
     * 성공하면 그 계정을 `currentUser()` 가 돌려주는 것과 **같은 자리**(self::$user)에 앉힌다.
     * 그래야 감사 로그의 `actor_*` 가 **토큰을 쓴 사람**으로 남고(F-59 ④), 핸들러는 누가
     * 불렀는지 모른 채 웹과 똑같은 코드를 실행한다. 비밀번호 해시·TOTP 비밀은 배열에서
     * **빼고** 앉힌다 — 토큰 경로가 그 값을 볼 이유가 없다.
     *
     * 토큰 값·해시·hint 는 이 함수 어디에서도 로그·감사·응답에 나가지 않는다 (PRD S-09-11).
     *
     * @param bool $personalOnly 라우터가 `TBOX_API_TOKEN_PERSONAL_ROUTES` 로 판단한다.
     *                           **요청 값이 정하지 않는다**(라우트 패턴만 본다).
     * @return array<string,mixed>
     */
    public static function requireApiToken(bool $personalOnly = false): array
    {
        // ① 헤더 (F-49 불변)
        $sent = Http::apiTokenHeader();
        if ($sent === null) {
            self::denyApiToken('missing_header');
            Http::error(401, 'X-API-Token 헤더가 없습니다');
        }

        $db      = Db::instance();
        $row     = null;
        $kind    = '';
        $tokenId = null;
        $scope   = '';

        // ② 개인 토큰 — 해시로만 찾는다 (F-59, ARCH 4.6)
        $found = $db->run(self::SQL_FIND_API_TOKEN, [
            ':hash'   => hash('sha256', $sent),
            // Q:F-10 — 묶인 IP 비교에 쓰는 지금 요청의 IP(REMOTE_ADDR — nginx 가 믿는 프록시에 대해서만 복원). 요청 머리를 읽지 않는다.
            ':cur_ip' => Http::clientIp(),
        ])->fetch();

        if (is_array($found)) {
            /* F-66 — **만료**는 계정보다 먼저 본다 (ARCH 4.7 판정 순서).
             * 만료된 토큰으로는 계정을 읽지 않는다: 그 토큰은 이미 자격이 아니고,
             * 「계정이 어떤 상태인가」는 요청자가 알 필요가 없다.
             * 판정은 SQL 이 한 `is_expired` 를 그대로 쓴다 — PHP 시계를 섞지 않는다.
             *
             * 거부 행은 `denyApiToken` 과 **같은 target 모양**(라우트 패턴)에 detail 로
             * 「어느 토큰이었나」를 더한다. 토큰 값·hint 는 넣지 않는다(PRD S-09-11). */
            if ($found['is_expired'] === true) {
                Audit::deny('auth.token', 'token_expired', [
                    'target_type' => 'system',
                    'target_name' => defined('TBOX_ROUTE')
                        ? (string) constant('TBOX_ROUTE')
                        : Http::method() . ' ' . Http::path(),
                    'detail'      => [
                        'token_id'    => (int) $found['id'],
                        'token_scope' => (string) $found['scope'],
                    ],
                ]);
                // 틀린 토큰의 401 과 **문구가 다르다** — 쥔 쪽이 우리 프로그램이라 이유를 준다.
                Http::error(401, Http::MSG_TOKEN_EXPIRED);
            }

            /* Q:F-10 (ARCH 4.9 · 4.10, SECURITY R-114) — 묶인 IP 가 있는 토큰(「이 PC 로그인 전용」 원자가 든 토큰)은 그 IP 밖에서
             * **어느 창구도** 열지 않는다(사용자 결정 「고정 PC 에서만」의 확장 — 범위를 묶어도 IP 묶음이 전부에 걸린다).
             * 만료 다음 · 계정 읽기 앞이다: 틀린 곳에서 온 요청으로는 계정 상태를 읽지 않는다. 판정 값은 SQL 의 `ip_ok` 하나.
             * 예외는 링크 창구 하나(SESSION_LINK_ROUTE) — 그 핸들러가 같은 값(`apiTokenIpOk()`)으로 분당 셈 뒤에 스스로 답한다. */
            self::$apiTokenIpOk = $found['ip_ok'] === true;
            if (!self::$apiTokenIpOk
                && !(defined('TBOX_ROUTE') && constant('TBOX_ROUTE') === self::SESSION_LINK_ROUTE)
            ) {
                Audit::deny('auth.token', 'ip_mismatch', [
                    'target_type' => 'system',
                    'target_name' => defined('TBOX_ROUTE')
                        ? (string) constant('TBOX_ROUTE')
                        : Http::method() . ' ' . Http::path(),
                    // 요청 IP 는 감사 행의 ip 칸(Audit 가 Http::clientIp 로 채운다) — detail 에는 어느 토큰이었나만.
                    'detail'      => [
                        'token_id'    => (int) $found['id'],
                        'token_scope' => (string) $found['scope'],
                    ],
                ]);
                Http::json(['error' => self::MSG_TOKEN_IP_MISMATCH, 'code' => 'token_ip_mismatch'], 401);
                exit;
            }

            $row = $db->run(self::SQL_LOAD_USER_BY_ID, [':id' => (int) $found['user_id']])->fetch();
            if (!is_array($row)
                || $row['is_active'] !== true
                || $row['is_expired'] === true
                || self::nextStep($row) !== null
            ) {
                // 토큰은 맞았지만 그 계정이 쓸 수 없는 상태다. 넷 중 무엇이 틀렸는지는
                // 응답에 넣지 않는다 (PRD S-09-5) — 통일 문구 403 이다.
                self::denyApiToken('user_disabled');
                Http::error(403, Http::MSG_FORBIDDEN);
            }
            $kind    = 'personal';
            $tokenId = (int) $found['id'];
            // F-66 — 이 토큰이 열 수 있는 창구의 넓이. 값은 DB 의 세 값 중 하나다(CHECK).
            $scope   = (string) $found['scope'];
        } else {
            // ③ 공용 토큰 — 이행용 (F-49 ④ 그대로). 32자 미만이면 비교조차 하지 않는다.
            $configured = Env::getOrEmpty('API_TOKEN');
            if (strlen($configured) < self::API_TOKEN_MIN_CHARS
                || !hash_equals($configured, $sent)
            ) {
                // ④ 둘 다 아니다 — 회수된 개인 토큰도 여기로 온다.
                self::denyApiToken('bad_token');
                Http::error(401, 'API 토큰이 올바르지 않습니다');
            }

            // 귀속 계정 — 로그인과 **같은 조회**를 쓴다(비밀번호 검증만 없다).
            $name = Env::getOrEmpty('API_TOKEN_USER');
            if ($name !== '' && preg_match(self::USERNAME_RE, $name) === 1) {
                $row = $db->run(self::SQL_LOAD_USER, [':username' => $name])->fetch();
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
            $kind = 'shared';
            // F-66 — 공용 토큰에는 범위 열이 없다. 이행용이므로 `read+upload` 로 본다
            // (삭제 둘은 그보다 앞의 관문 ⑤ 가 이미 막는다 — ARCH 4.7).
            $scope = 'read+upload';
        }

        if ($kind === 'shared') {
            // 공용 토큰에는 묶인 IP 가 없다(범위 열도 없다 — 위 ③).
            self::$apiTokenIpOk = true;
        }

        // 인증 값은 들고 다니지 않는다 — 토큰 경로가 쓰는 것은 id·username·role·team_id 다.
        unset($row['password_hash'], $row['mfa_secret'], $row['mfa_last_step']);

        // 감사의 「누가」 가 토큰의 주인이 되도록 현재 사용자 자리에 앉힌다 (Audit::actor).
        // 세션이 없으므로 session_id 는 없다 → detail.login_ref 도 생기지 않는다(맞는 동작).
        self::$user     = $row;
        self::$resolved = true;

        // 감사 로그가 모든 행에 자동으로 붙이는 값 (Audit::autoDetail, ARCH 4.6 「감사」).
        // 값은 코드가 정한 분류어와 행 번호뿐이고 요청이 정하지 않는다.
        define('TBOX_API_TOKEN_KIND', $kind);
        if ($tokenId !== null) {
            define('TBOX_API_TOKEN_ID', $tokenId);
        }
        // F-66 — 라우터의 범위 관문([2-S])과 `Audit::autoDetail` 이 함께 읽는다.
        // 여기 한 곳에서만 세운다 — 요청이 정하지 않고 핸들러가 고칠 수도 없다(상수).
        define('TBOX_API_TOKEN_SCOPE', $scope);

        // ⑤ 개인 토큰 전용 창구(삭제 둘) — 공용 토큰은 여기서 끝난다.
        //    「누가 지웠나」 가 사람 단위로 남지 않는 자격으로는 지울 수 없게 한 것이다(F-59 ⑤).
        if ($personalOnly && $kind === 'shared') {
            self::denyApiToken('shared_token_not_permitted');
            Http::error(403, Http::MSG_PERSONAL_TOKEN_REQUIRED);
        }

        // 마지막으로 쓴 시각 — 60초에 한 번만 (요청마다 UPDATE 하지 않는다).
        if ($tokenId !== null) {
            $db->run(self::SQL_TOUCH_API_TOKEN, [':id' => $tokenId]);
        }

        return $row;
    }

    /**
     * Q:F-10 — 이 요청의 개인 토큰이 **묶인 IP 판정**을 통과했나(`requireApiToken` 이 `SQL_FIND_API_TOKEN` 의 `ip_ok` 로 세운 값).
     * 묶인 IP 가 없는 토큰·공용 토큰은 참. 토큰 갈래가 아닌 요청에서는 거짓(닫힌 쪽).
     * 쓰는 곳: 링크 창구(`me_session_link.php`) — `SESSION_LINK_ROUTE` 에서만 requireApiToken 이 끝내지 않고 이 값을 넘긴다.
     */
    public static function apiTokenIpOk(): bool
    {
        return defined('TBOX_API_TOKEN_KIND') && self::$apiTokenIpOk;
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
     * @throws AuthTempPasswordExpiredException 비밀번호는 맞았지만 발급 72시간이 지난 임시 비밀번호일 때 (호출자가 401)
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

        // 6-2) 임시 비밀번호 유효기간 (SECURITY R-39 — 보안 넷 ④ 2026-09-26, 사용자 결정 d52).
        //    Admin 이 발급한 임시 비밀번호(계정 만들기·재발급)는 72시간 안에 첫 로그인해서 바꿔야 한다.
        //    여기는 **비밀번호가 맞은 뒤**다 — 틀린 비밀번호는 위 5)의 동일 문구로 끝나므로 만료 여부가 새지 않는다.
        //    2단계 인증보다 먼저 본다: 만료된 값으로는 코드까지 맞아도 들어올 수 없으므로 코드를 묻는 것이 무의미하다.
        //    실패 횟수는 올리지 않는다(추측이 아니다). 세션은 만들지 않는다.
        if ($row['must_change_password'] === true) {
            $temp = $db->run(self::SQL_TEMP_EXPIRED, [
                ':id'    => (int) $row['id'],
                ':hours' => self::TEMP_PASSWORD_HOURS,
            ])->fetch();
            if (is_array($temp) && $temp['temp_expired'] === true) {
                Audit::deny('auth.login', 'temp_expired', [
                    'target_type' => 'user',
                    'target_id'   => (int) $row['id'],
                    'target_name' => (string) $row['username'],
                ]);
                throw new AuthTempPasswordExpiredException(self::MSG_TEMP_PASSWORD_EXPIRED);
            }
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

        // 8) 성공 — 세션 재발급 (ARCH 4.0). T:F-72 — 발급은 issueSession() 한 함수(지문·얼굴 로그인과 같이 부른다 — 두 벌 금지).
        //    쓴 코드의 스텝을 기준선으로 올리는 일은 **같은 트랜잭션** 안에서 한다 — "세션은 났는데 기준선은 안 올라간" 틈이 없다 (R-45).
        $userId = (int) $row['id'];
        return self::issueSession($row, static function () use ($userId, $mfaStep): void {
            if ($mfaStep !== null) {
                self::markMfaStep($userId, $mfaStep);
            }
        }, null, []);
    }

    /**
     * 로그인 성공 — **세션 발급 한 벌** (Auth::login 8) 을 뽑은 것 · T:F-72 지시서 1.4 로그인 9 「두 벌 금지」).
     *
     * 한 트랜잭션: 실패 횟수 0 · (부르는 쪽 일 — 2단계 코드 기준선 / 열쇠의 서명 횟수·마지막 사용) · 로그인 전에 들고 온 쿠키의
     * 세션 버림(세션 고정 막기) · 만료 세션 정리 · 새 세션 · 감사 `auth.login allow`(지문이면 사유 `passkey`). 쿠키는 커밋 뒤에만.
     * **로그인은 재인증 창을 열지 않는다** — 새 세션 행의 reauth_at 은 NULL 이고 아래 배열도 거짓으로 고정한다(ARCH 4.0).
     *
     * @param array<string,mixed> $row      SQL_LOAD_USER / SQL_LOAD_USER_BY_ID 의 한 줄(두 길이 같은 열 목록)
     * @param callable            $inTx     트랜잭션 안에서 부를 일(없으면 빈 함수)
     * @param string|null         $reason   감사 allow 사유(null · `passkey`)
     * @param array<string,mixed> $detail   감사 detail(등록부 키만 — 지문이면 credential_no)
     * @param int|null            $tokenId  Q:F-9 — 「이 PC 로그인 전용」 링크로 여는 세션이면 그 토큰 행 번호(아니면 null = 일반 로그인).
     *                                      null 이면 아래 코드는 **전과 한 글자도 다르지 않은 길**만 지난다(origin='login' 불변).
     * @return array<string,mixed>
     */
    private static function issueSession(array $row, callable $inTx, ?string $reason, array $detail, ?int $tokenId = null): array
    {
        $db     = Db::instance();
        $userId = (int) $row['id'];
        $oldId  = self::cookieSessionId();
        $token  = bin2hex(random_bytes(32));
        $newId  = hash('sha256', $token);

        $db->begin();
        try {
            if ($tokenId === null) {
                $db->run(self::SQL_CLEAR_FAILED, [':id' => $userId]);
            }
            // Q:F-9 — 링크 세션은 실패 횟수를 0 으로 돌리지 **않는다**: 비밀번호를 맞힌 일이 아니다(토큰을 쥔 사람이 링크로
            //   잠금 직전의 카운터를 지우는 길을 만들지 않는다). 그 대신 $inTx 가 링크를 소비한다(같은 트랜잭션).
            $inTx();
            if ($oldId !== null) {
                // 로그인 전에 들고 온 쿠키의 세션은 버린다 — 공격자가 미리 심어 둔 값이 살아남지 않는다
                $db->run(self::SQL_DROP_SESSION, [':id' => $oldId]);
            }
            $db->run(self::SQL_PURGE_EXPIRED, [':idle_min' => Env::getInt('SESSION_IDLE_MIN')]);
            if ($tokenId === null) {
                $db->run(self::SQL_INSERT_SESSION, [
                    ':id'         => $newId,
                    ':user_id'    => $userId,
                    ':max_hours'  => Env::getInt('SESSION_MAX_HOURS'),
                    ':ip'         => Http::clientIp(),
                    ':user_agent' => Http::userAgent(),
                ]);
            } else {
                $db->run(self::SQL_INSERT_TOKEN_SESSION, [
                    ':id'         => $newId,
                    ':user_id'    => $userId,
                    ':max_hours'  => Env::getInt('SESSION_MAX_HOURS'),
                    ':ip'         => Http::clientIp(),
                    ':user_agent' => Http::userAgent(),
                    ':token_id'   => $tokenId,
                ]);
                /* 감사의 「누가」 가 **토큰 주인**이 되도록 새 세션의 사용자를 먼저 앉힌다(Audit::actor = currentUser).
                   브라우저에 다른 사람의 옛 쿠키가 있었어도 그 사람이 행위자로 남지 않는다. autoDetail 이 이 배열의
                   session_origin 을 보고 auth=token_session · token_id 를 붙인다(login_ref 는 새 세션에서 파생). */
                self::$resolved = true;
                self::$user     = self::sessionUserArray($row, $newId, $tokenId);
            }
            $ctx = [
                'target_type' => 'user',
                'target_id'   => $userId,
                'target_name' => (string) $row['username'],
            ];
            if ($detail !== []) {
                $ctx['detail'] = $detail;
            }
            if ($tokenId === null) {
                Audit::allow('auth.login', $ctx, $reason);
            } else {
                Audit::allow('session.open', $ctx);
            }
            $db->commit();
        } catch (Throwable $e) {
            $db->rollback();
            if ($tokenId !== null) {
                self::$resolved = false;
                self::$user     = null;
            }
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
            'invited_account' => ($row['invited_account'] ?? false) === true,
            'mfa_recovery_required' => ($row['mfa_recovery_required'] ?? false) === true,
            /*
             * 16-1 (PRD F-23) — **로그인은 재인증 창을 열지 않는다.**
             * SQL_INSERT_SESSION 에 reauth_at 이 없으므로 새 세션 행의 값은 NULL 이고,
             * 여기서도 거짓으로 고정한다. "관리 영역 첫 진입에 반드시 한 번 묻는다" 는
             * 사용자 요구(ARCH 4.0 재인증 행)가 이 두 줄로 지켜진다. 지문·얼굴 로그인도 같다(T:F-72 1.6).
             */
            'reauth_fresh' => false,
            'reauth_at'    => null,
        ];
        if ($tokenId !== null) {
            self::$user = self::sessionUserArray($row, $newId, $tokenId);
        }
        return self::$user;
    }

    /**
     * Q:F-9 — 링크로 연 세션의 사용자 배열(issueSession 의 일반 배열 + 출신 두 칸). 로그인 배열과 같은 규칙이다 —
     * 재인증 창은 열지 않는다(reauth_fresh 거짓 · reauth_at null).
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function sessionUserArray(array $row, string $sessionId, int $tokenId): array
    {
        return [
            'id'          => (int) $row['id'],
            'username'    => (string) $row['username'],
            'role'        => (string) $row['role'],
            'team_id'     => $row['team_id'] === null ? null : (int) $row['team_id'],
            'session_id'  => $sessionId,
            'mfa_enabled' => $row['mfa_enabled'] === true,
            'must_change_password' => $row['must_change_password'] === true,
            'mfa_setup_required'   => $row['mfa_setup_required'] === true,
            'invited_account' => ($row['invited_account'] ?? false) === true,
            'mfa_recovery_required' => ($row['mfa_recovery_required'] ?? false) === true,
            'reauth_fresh' => false,
            'reauth_at'    => null,
            'session_origin'   => 'token',
            'session_token_id' => $tokenId,
        ];
    }

    /**
     * Q:F-9 — 1회용 30초 로그인 링크를 **세션으로 바꾼다** (t-box F-75 ④, ARCH 4.9, API.md 20장, SECURITY R-113).
     *
     * 부르는 곳은 `GET /session/open`(session_open_page.php, 정책 public) 하나다. 성공이면 쿠키를 내리고 사용자 배열을,
     * 실패면 null 을 돌려준다(부르는 쪽은 어느 쪽이든 **고정 주소**로 302 — 실패 사유는 응답에 넣지 않는다).
     *
     * 판정 순서 — 뒤로 갈수록 앞 단계를 통과한 요청만 닿는다. 판정 값은 전부 DB 가 계산한다(SQL_FIND_SESSION_LINK).
     *   ① 코드 모양(base64url 43자) — 아니면 null(조회·감사 0: 모양이 틀린 값은 링크가 아니다)
     *   ② sha256(코드)로 한 줄 — 없으면 null(감사 0: 아무나 두드리는 공개 GET 이 감사 표를 채우지 않게. 코드는 256비트라 맞힐 수 없다)
     *   ③ 이미 씀 → deny `link_used` · ④ 30초 지남 → deny `link_expired` · ⑤ 토큰 회수·만료·범위·IP 어긋남 → deny `token_invalid`
     *   ⑥ 지금 요청 IP ≠ 링크를 받은 IP(= 묶인 IP) → deny `ip_mismatch`
     *   ⑦ 계정: 없음·비활성·만료·강제 절차 중·2단계 꺼짐·Guest(Authz::canOpenSessionByToken) → deny `user_disabled`
     *   ⑧ 한 트랜잭션: 링크 소비(UPDATE … WHERE used_at IS NULL — 동시 두 번 중 하나만) → 옛 쿠키 세션 버림 → 새 세션
     *      (`origin='token'` · `token_id` · reauth_at NULL) → 감사 `session.open allow`(행위자 = 토큰 주인 · auth=token_session ·
     *      token_id — autoDetail) → 커밋 뒤 쿠키. 소비가 0행이면(동시에 먼저 쓴 요청이 있었다) 롤백하고 deny `link_used`.
     *
     * ★ 코드 원문·해시는 어디에도 남기지 않는다(감사 detail·로그·응답 0) — 감사 행에는 대상 계정과 링크의 토큰 번호뿐이다.
     * ★ IP 는 `Http::clientIp()` 한 곳에서만 읽는다(요청 머리·본문 0).
     */
    /**
     * 검토 RV-QF9-1 — 브라우저에 **유효한 세션이 이미 있을 때** 링크가 열렸다. 갈아타지 않는다: 링크를 **소비하지 않고**
     * (행 그대로 — 30초 뒤 만료) 감사 `session.open deny already_logged_in` 한 행만 남긴다(행위자 = 지금 쿠키의 주인 ·
     * 대상 = 링크의 토큰 주인). 같은 공인 IP 안의 동료가 제 링크를 남의 브라우저에 열게 해 그 사람의 세션을 끊고 제 계정으로
     * 갈아태우는 로그인 CSRF 를 막는다. 비서의 쓰임(이미 로그인된 브라우저면 그냥 열린다)에는 영향이 없다.
     * 모양이 틀린·없는 코드는 감사 0(openSessionFromLink 와 같은 규칙). 코드 원문은 남기지 않는다.
     */
    public static function refuseLinkWhileLoggedIn(string $code): void
    {
        if (preg_match(self::SESSION_LINK_CODE_RE, $code) !== 1) {
            return;
        }
        $link = Db::instance()->run(self::SQL_FIND_SESSION_LINK, [
            ':hash'   => hash('sha256', $code),
            ':cur_ip' => Http::clientIp(),
        ])->fetch();
        unset($code);
        if (!is_array($link)) {
            return;
        }
        Audit::deny('session.open', 'already_logged_in', [
            'target_type' => 'user',
            'target_id'   => (int) $link['user_id'],
            'target_name' => (string) $link['username'],
            'detail'      => ['token_id' => (int) $link['token_id']],
        ]);
    }

    public static function openSessionFromLink(string $code): ?array
    {
        if (preg_match(self::SESSION_LINK_CODE_RE, $code) !== 1) {
            return null;
        }
        $db   = Db::instance();
        $link = $db->run(self::SQL_FIND_SESSION_LINK, [
            ':hash'   => hash('sha256', $code),
            ':cur_ip' => Http::clientIp(),
        ])->fetch();
        unset($code);   // 원문은 여기서 끝 — 아래 코드는 행 번호만 다룬다
        if (!is_array($link)) {
            return null;
        }

        $linkId  = (int) $link['id'];
        $tokenId = (int) $link['token_id'];
        $deny = static function (string $reason) use ($link, $tokenId): void {
            Audit::deny('session.open', $reason, [
                'target_type' => 'user',
                'target_id'   => (int) $link['user_id'],
                'target_name' => (string) $link['username'],
                // 「어느 토큰의 링크였나」 — 행 번호뿐이다(토큰 원문·hint·코드 0 · ARCH 7.5 token_id).
                'detail'      => ['token_id' => $tokenId],
            ]);
        };

        if ($link['is_used'] === true) {
            $deny('link_used');
            return null;
        }
        if ($link['is_expired'] === true) {
            $deny('link_expired');
            return null;
        }
        if ($link['token_ok'] !== true) {
            $deny('token_invalid');
            return null;
        }
        if ($link['ip_ok'] !== true) {
            $deny('ip_mismatch');
            return null;
        }

        $row = $db->run(self::SQL_LOAD_USER_BY_ID, [':id' => (int) $link['user_id']])->fetch();
        if (!is_array($row)
            || $row['is_active'] !== true
            || $row['is_expired'] === true
            || self::nextStep($row) !== null
            || !Authz::canOpenSessionByToken($row)
        ) {
            $deny('user_disabled');
            return null;
        }

        try {
            return self::issueSession($row, static function () use ($db, $linkId): void {
                $used = $db->run(self::SQL_CONSUME_SESSION_LINK, [':id' => $linkId])->fetch();
                if (!is_array($used)) {
                    throw new AuthSessionLinkUsedException('링크가 이미 쓰였거나 만료됐습니다');
                }
            }, null, [], $tokenId);
        } catch (AuthSessionLinkUsedException $e) {
            // 조회와 소비 사이에 다른 요청이 먼저 썼거나 30초가 지났다 — 트랜잭션은 이미 되돌려졌다(세션 0 · 쿠키 0).
            $deny('link_used');
            return null;
        }
    }

    /** 90일 판정·해제 여부를 **DB 시계**로 함께 읽는다 — 열쇠 id 는 바이트로 비교(decode) · 원문은 읽기만(로그 0) */
    private const SQL_FIND_PASSKEY = <<<'SQL'
        SELECT id, user_id, name, encode(public_key, 'hex') AS pk_hex, sign_count,
               encode(user_handle, 'hex') AS uh_hex, backup_eligible,
               (revoked_at IS NOT NULL) AS is_revoked,
               (COALESCE(last_used_at, created_at) < now() - make_interval(days => CAST(:idle_days AS integer))) AS is_stale
          FROM passkeys
         WHERE credential_id = decode(:cid, 'hex')
        SQL;

    /**
     * 지문 로그인·재인증 성공 — 서명 횟수·마지막 사용·동기화 표시 갱신(해제되지 않은 행만).
     * **조건부**(`sign_count = :stored` — SQL_FIND_PASSKEY 로 읽어 7) 을 비교한 그 값일 때만): 같은 저장값을 읽은 두 요청이
     * 함께 통과하는 창(TOCTOU)을 닫는다 — 먼저 커밋한 쪽이 값을 바꿨으면 뒤쪽은 0 행(독립 검토 RV-PK-1, 2026-09-27).
     */
    private const SQL_TOUCH_PASSKEY = <<<'SQL'
        UPDATE passkeys
           SET sign_count = :n, last_used_at = now(), backed_up = CAST(:bs AS boolean)
         WHERE id = :id AND revoked_at IS NULL AND sign_count = :stored
        RETURNING id
        SQL;

    /**
     * T:F-72 — 휴대폰 지문·얼굴로 로그인 (지시서 1.4 「로그인」 1~9 · 1.6 · ARCH 5.35).
     *
     * 아이디 없이(열쇠가 계정을 알려 준다) — 순서(뒤로 갈수록 앞을 통과한 요청만 닿는다):
     *   2) clientDataJSON: type `webauthn.get` → 챌린지 **소비**(로그인 전 쿠키 sha256 에 묶인 것 · 한 번) → origin 정확 일치 · crossOrigin
     *   3) 열쇠 찾기(credential_id 바이트) → 없으면 `passkey_unknown`
     *   4) 계정 — **SQL_LOAD_USER_BY_ID**(비밀번호 로그인의 SQL_LOAD_USER 와 한 글자도 다르지 않은 열 목록 · F-59 와 같은 규칙) ·
     *      userHandle 이 있고 행의 user_handle 과 같아야(hash_equals)
     *   5) authData: 정확히 37바이트(확장이면 끝까지 · 남는 바이트 0) · rpIdHash · UP · UV · AT 없음 · BE 가 등록 때와 같음
     *   6) 서명 `openssl_verify === 1` 만
     *   7) 서명 횟수: 둘 다 0 이면 통과 · 아니면 새 값 > 저장값 · 역행이면 `passkey_counter`(AL-23)
     *      ─ 5)~7) 의 실패는 그 계정 실패 1회(Auth::countFailure — 잠긴 동안은 세지 않는다: 잠금을 늘리지 않게, 비밀번호 길과 같다)
     *   8) 여기까지 = 열쇠의 주인. 해제·90일 → `passkey_revoked`(예외 — 화면에 「꺼진 열쇠」, 열쇠 주인에게만 가는 정보) ·
     *      잠금 → 423 · 비활성 → 401 · Guest 만료 → `user.expired guest_expired` · **must_change_password → `forced_step`**
     *      (임시 비밀번호 72시간 규칙 R-39 는 비밀번호 길에서만 걸리므로 지문 길로 우회하지 못하게)
     *   9) 성공 — issueSession(비밀번호 로그인과 한 함수) + 같은 트랜잭션에서 서명 횟수·마지막 사용·BS 갱신 · 감사 `auth.login allow passkey`
     * 실패 응답은 전부 401 한 문구(사유는 감사에만) — 「꺼진 열쇠」만 예외. 지문 로그인은 **2단계 인증을 충족**한다(F-52 — UV 필수).
     *
     * @return array<string,mixed>|null 성공이면 사용자, 실패면 null(호출자가 401)
     * @throws AuthLockedException             잠긴 계정(열쇠 주인임이 확인된 뒤)
     * @throws AuthPasskeyRevokedException     해제됐거나 90일 동안 쓰지 않아 꺼진 열쇠(열쇠 주인임이 확인된 뒤)
     */
    public static function loginWithPasskey(string $credentialId, string $clientDataJson, string $authData,
                                            string $signature, ?string $userHandle, string $preCookie): ?array
    {
        require_once __DIR__ . '/Passkey.php';   // 쓰는 요청에서만 싣는다(부트스트랩 목록은 그대로)
        $db      = Db::instance();
        $unknown = '(알 수 없는 열쇠)';

        // 2) clientDataJSON — type → 챌린지 소비(한 번) → origin
        try {
            $cd = Passkey::parseClientData($clientDataJson, 'webauthn.get');
        } catch (PasskeyError $e) {
            self::denyLogin($unknown, 'passkey_invalid');
            return null;
        }
        if (!Passkey::consumeLogin($db, $cd['challenge'], $preCookie)) {
            self::denyLogin($unknown, 'passkey_challenge');
            return null;
        }
        if (!Passkey::originOk($cd['origin'])) {
            self::denyLogin($unknown, 'passkey_invalid');
            return null;
        }

        // 3) 열쇠
        $pk = $db->run(self::SQL_FIND_PASSKEY, [
            ':cid'       => bin2hex($credentialId),
            ':idle_days' => Passkey::IDLE_DAYS,
        ])->fetch();
        if (!is_array($pk)) {
            self::denyLogin($unknown, 'passkey_unknown');
            return null;
        }

        // 4) 계정 — 비밀번호 로그인과 같은 열 목록(F-59 규칙) · userHandle
        $row = $db->run(self::SQL_LOAD_USER_BY_ID, [':id' => (int) $pk['user_id']])->fetch();
        if (!is_array($row)) {
            self::denyLogin($unknown, 'passkey_unknown');
            return null;
        }
        $name = (string) $row['username'];
        $uh   = hex2bin((string) $pk['uh_hex']);
        if ($userHandle === null || !is_string($uh) || !hash_equals($uh, $userHandle)) {
            self::denyPasskeyLogin($row, 'passkey_invalid', (int) $pk['id'], false);
            return null;
        }

        // 5) authData
        try {
            $ad = Passkey::parseAuthData($authData);
        } catch (PasskeyError $e) {
            self::denyPasskeyLogin($row, 'passkey_invalid', (int) $pk['id'], true);
            return null;
        }
        if (!Passkey::rpIdHashOk($ad['rp_id_hash']) || !$ad['up'] || !$ad['uv'] || $ad['at']
            || $ad['be'] !== ($pk['backup_eligible'] === true)
        ) {
            self::denyPasskeyLogin($row, 'passkey_invalid', (int) $pk['id'], true);
            return null;
        }

        // 6) 서명 — === 1 만
        $der = hex2bin((string) $pk['pk_hex']);
        if (!is_string($der) || !Passkey::verifySignature($der, $authData, $clientDataJson, $signature)) {
            self::denyPasskeyLogin($row, 'passkey_invalid', (int) $pk['id'], true);
            return null;
        }

        // 7) 서명 횟수 — 둘 다 0 이면 통과(동기화되는 휴대폰 열쇠는 대개 늘 0) · 아니면 늘어야
        $stored = (int) $pk['sign_count'];
        $new    = (int) $ad['sign_count'];
        if (!($stored === 0 && $new === 0) && $new <= $stored) {
            self::denyPasskeyLogin($row, 'passkey_counter', (int) $pk['id'], true);
            return null;
        }

        // 8) 열쇠 주인이다 — 그 뒤 열쇠·계정 상태
        if ($pk['is_revoked'] === true || $pk['is_stale'] === true) {
            self::denyPasskeyLogin($row, 'passkey_revoked', (int) $pk['id'], false);
            throw new AuthPasskeyRevokedException('꺼진 열쇠');
        }
        if ($row['is_locked'] === true) {
            self::denyLogin($name, 'locked');
            throw new AuthLockedException('계정이 잠겨 있습니다');
        }
        if ($row['is_active'] !== true) {
            self::denyLogin($name, 'inactive');
            return null;
        }
        if ($row['is_expired'] === true) {
            Audit::deny('user.expired', 'guest_expired', [
                'target_type' => 'user',
                'target_id'   => (int) $row['id'],
                'target_name' => $name,
            ]);
            return null;
        }
        if ($row['must_change_password'] === true) {
            self::denyPasskeyLogin($row, 'forced_step', (int) $pk['id'], false);
            return null;
        }

        // 9) 성공 — 세션 발급 한 벌 + 같은 트랜잭션에서 열쇠 갱신(조건부 — 읽은 뒤 해제됐거나 다른 요청이 먼저 썼으면 되돌림)
        $pkId = (int) $pk['id'];
        try {
            return self::issueSession($row, static function () use ($db, $pkId, $new, $stored, $ad): void {
                $t = $db->run(self::SQL_TOUCH_PASSKEY, [
                    ':n'      => $new,
                    ':bs'     => $ad['bs'] ? 'true' : 'false',
                    ':id'     => $pkId,
                    ':stored' => $stored,
                ])->fetch();
                if (!is_array($t)) {
                    throw new AuthPasskeyRevokedException('꺼졌거나 방금 쓰인 열쇠');
                }
            }, 'passkey', ['credential_no' => $pkId]);
        } catch (AuthPasskeyRevokedException $e) {
            // issueSession 이 이미 되돌렸다(세션·실패 초기화·감사 allow 0). 거부 한 행은 트랜잭션 밖에서 — 응답은 호출자의 401 통일 문구,
            // 사유는 감사에만(RV-PK-1 — 7) 의 서명 횟수 검사를 쓰는 순간에 다시 한 것이라 같은 사유 · 같은 셈)
            self::denyPasskeyLogin($row, 'passkey_counter', $pkId, true);
            return null;
        }
    }

    /**
     * 지문 로그인 거부 한 행 — 대상은 **열쇠의 주인**(열쇠 id 원문 0) · 필요하면 그 계정 실패 1회.
     * 잠긴 동안에는 세지 않는다(잠금 시각을 늘리지 않는다 — 비밀번호 길은 잠긴 계정에 닿지 않는 것과 같게).
     *
     * @param array<string,mixed> $row
     */
    private static function denyPasskeyLogin(array $row, string $reason, int $credentialNo, bool $count): void
    {
        Audit::deny('auth.login', $reason, [
            'target_type' => 'user',
            'target_id'   => (int) $row['id'],
            'target_name' => (string) $row['username'],
            'detail'      => ['credential_no' => $credentialNo],
        ]);
        if ($count && $row['is_locked'] !== true) {
            self::countFailure((int) $row['id']);
        }
    }

    /** 해제·재인증 사유 — ARCH 7.4 `auth.passkey_revoke|allow|<사유>` 와 같은 값 */
    private const PASSKEY_REVOKE_CAUSES = ['password_change', 'password_reset', 'mfa_reset', 'role_change', 'deactivate'];

    private const SQL_REVOKE_USER_PASSKEYS = <<<'SQL'
        UPDATE passkeys
           SET revoked_at = now(), revoked_by = :by, revoke_reason = :cause
         WHERE user_id = :uid AND revoked_at IS NULL
        RETURNING id, name
        SQL;

    /**
     * T:F-72 — 그 계정의 **살아 있는 열쇠 전부** 해제 (지시서 1.6 「자동 해제」 — S 의 revokeAllApiTokens 바로 옆, 같은 트랜잭션).
     *
     * 본인 비밀번호 변경 · 관리자 임시 비밀번호 재발급 · 2단계 초기화 · 역할 변경 · 비활성화 = 「자격이 새었을 수 있다」 신호.
     * 역할 변경은 직원일 때 비밀번호만으로 등록한 열쇠가 관리자 열쇠로 넘어가지 않게. 팀 변경·2단계 끄기는 해제하지 않는다.
     * 행을 지우지 않는다(앱 계정에 DELETE 없음). 열쇠 하나에 감사 한 행 `auth.passkey_revoke allow <사유>` ·
     * detail `credential_no`·`credential_label`(열쇠 id 원문·공개 키 0). 표가 아직 없으면(마이그레이션 전) 0 — 해제할 열쇠도 없다.
     *
     * @return int 해제한 열쇠 수
     */
    public static function revokeAllPasskeys(int $userId, string $targetName, int $byUserId, string $cause): int
    {
        require_once __DIR__ . '/Passkey.php';   // 쓰는 요청에서만 싣는다(부트스트랩 목록은 그대로)
        if (!in_array($cause, self::PASSKEY_REVOKE_CAUSES, true)) {
            throw new RuntimeException('열쇠 해제 사유가 아닙니다');
        }
        if (!Passkey::tablesReady()) {
            return 0;
        }
        $rows = Db::instance()->run(self::SQL_REVOKE_USER_PASSKEYS, [
            ':by'    => $byUserId,
            ':cause' => $cause,
            ':uid'   => $userId,
        ])->fetchAll();
        foreach ($rows as $r) {
            Audit::allow('auth.passkey_revoke', [
                'target_type' => 'user',
                'target_id'   => $userId,
                'target_name' => $targetName,
                'detail'      => [
                    'credential_no'    => (int) $r['id'],
                    'credential_label' => (string) $r['name'],
                ],
            ], $cause);
        }
        return count($rows);
    }

    /**
     * T:F-72 라운드 C — 관리 기능 재인증을 **지문·얼굴로** (지시서 1.4 「재인증」 · ARCH 4.0).
     *
     * 로그인의 2~7 과 같은 검사 + **열쇠 주인 = 세션의 사람**(아니면 거부) + 챌린지 묶음(이 세션 + 이 사람, 한 번).
     * 성공이면 `SQL_SET_REAUTH`(지금 세션만 — 비밀번호 재인증과 같은 15분 창) · 실패 카운터 0 · 감사 `auth.reauth allow passkey`.
     * 실패는 로그인 뒤 창구 규칙(R-48) — `countFailureAndRevoke`(5회면 잠금 + 그 계정 세션 전부 끊김). 해제·90일 열쇠도 거부.
     * **권한을 만들지 않는다** — 관문 [3-2] 는 인가 뒤다(비밀번호 재인증과 같다).
     *
     * @param array<string,mixed> $user 지금 로그인한 사용자
     * @return array{0:bool,1:?string} [성공, 창 끝 시각]
     */
    public static function reauthWithPasskey(array $user, string $credentialId, string $clientDataJson, string $authData,
                                             string $signature): array
    {
        require_once __DIR__ . '/Passkey.php';   // 쓰는 요청에서만 싣는다(부트스트랩 목록은 그대로)
        $db        = Db::instance();
        $userId    = (int) $user['id'];
        $sessionId = (string) ($user['session_id'] ?? '');
        $target    = [
            'target_type' => 'user',
            'target_id'   => $userId,
            'target_name' => (string) $user['username'],
        ];
        $fail = static function (string $reason, ?int $credentialNo, bool $count) use ($target, $userId): array {
            Audit::deny('auth.reauth', $reason, $target + ($credentialNo === null ? [] : ['detail' => ['credential_no' => $credentialNo]]));
            if ($count) {
                self::countFailureAndRevoke($userId);
            }
            return [false, null];
        };
        if ($sessionId === '') {
            throw new RuntimeException('재인증할 세션이 없습니다');
        }

        try {
            $cd = Passkey::parseClientData($clientDataJson, 'webauthn.get');
        } catch (PasskeyError $e) {
            return $fail('passkey_invalid', null, false);   // 모양이 깨진 요청 — 추측이 아니다(세지 않는다)
        }
        if (!Passkey::consumeSession($db, $cd['challenge'], 'reauth', $userId, $sessionId)) {
            return $fail('passkey_challenge', null, false);  // 시간이 지났거나 이미 쓴 문제 — 세지 않는다(서명을 보기 전이다)
        }
        if (!Passkey::originOk($cd['origin'])) {
            return $fail('passkey_invalid', null, true);
        }
        $pk = $db->run(self::SQL_FIND_PASSKEY, [
            ':cid'       => bin2hex($credentialId),
            ':idle_days' => Passkey::IDLE_DAYS,
        ])->fetch();
        // 없는 열쇠 · 남의 열쇠 · 해제·90일 열쇠 — 모두 같은 사유(재인증 창구는 로그인한 사람만 닿는다)
        if (!is_array($pk) || (int) $pk['user_id'] !== $userId || $pk['is_revoked'] === true || $pk['is_stale'] === true) {
            return $fail('passkey_invalid', is_array($pk) && (int) $pk['user_id'] === $userId ? (int) $pk['id'] : null, true);
        }
        try {
            $ad = Passkey::parseAuthData($authData);
        } catch (PasskeyError $e) {
            return $fail('passkey_invalid', (int) $pk['id'], true);
        }
        $der = hex2bin((string) $pk['pk_hex']);
        if (!Passkey::rpIdHashOk($ad['rp_id_hash']) || !$ad['up'] || !$ad['uv'] || $ad['at']
            || $ad['be'] !== ($pk['backup_eligible'] === true)
            || !is_string($der) || !Passkey::verifySignature($der, $authData, $clientDataJson, $signature)
        ) {
            return $fail('passkey_invalid', (int) $pk['id'], true);
        }
        $stored = (int) $pk['sign_count'];
        $new    = (int) $ad['sign_count'];
        if (!($stored === 0 && $new === 0) && $new <= $stored) {
            return $fail('passkey_counter', (int) $pk['id'], true);
        }

        $db->begin();
        try {
            // 조건부 갱신(읽은 저장값 그대로일 때만 — RV-PK-1) · 0 행이면 아래 passkey_invalid 갈래로 되돌림
            $t = $db->run(self::SQL_TOUCH_PASSKEY, [
                ':n'      => $new,
                ':bs'     => $ad['bs'] ? 'true' : 'false',
                ':id'     => (int) $pk['id'],
                ':stored' => $stored,
            ])->fetch();
            $updated = is_array($t) ? $db->run(self::SQL_SET_REAUTH, [
                ':id'         => $sessionId,
                ':reauth_min' => self::REAUTH_MINUTES,
            ])->fetch() : false;
            if (!is_array($updated)) {
                $db->rollback();
                return $fail('passkey_invalid', (int) $pk['id'], false);
            }
            $db->run(self::SQL_CLEAR_FAILED, [':id' => $userId]);
            Audit::allow('auth.reauth', $target + ['detail' => [
                'window_minutes' => self::REAUTH_MINUTES,
                'credential_no'  => (int) $pk['id'],
            ]], 'passkey');
            $db->commit();
        } catch (Throwable $e) {
            $db->rollback();
            throw $e;
        }
        return [true, (string) $updated['reauth_until']];
    }

    /**
     * 이 사용자에게 2단계 인증이 **필수**인가 — 관리자 역할 또는 관리자가 초기화한 복구 절차. 초대 가입 자체는 선택이다. (PRD F-52, ARCH 5.26).
     *
     * 판정은 **실제 역할**로 한다 — 「역할로 보기」(5.20) 중에는 `role` 이 흉내 역할이고
     * `real_role` 이 진짜다. Employee 를 흉내 내는 Admin 이 관문을 빠져나가면 안 된다.
     * `login()` 이 돌려주는 배열에는 `real_role` 이 없고 `role` 만 있다 — 로그인 시점에는
     * 흉내 역할이 없으므로 그 `role` 이 곧 실제 역할이다. 아래 `??` 순서가 둘을 함께 받는다.
     *
     * @param array<string,mixed> $user Auth::currentUser() 또는 Auth::login() 이 돌려준 배열
     */
    public static function mfaRequired(array $user): bool
    {
        $role = (string) ($user['real_role'] ?? $user['role'] ?? '');
        return in_array($role, self::MFA_REQUIRED_ROLES, true) || ($user['mfa_recovery_required'] ?? false) === true;
    }

    /**
     * 필수는 아니지만 아직 안 켠 상태 — 화면이 **권장 카드**를 보일지 고르는 값
     * (PRD F-52 ③, ARCH 5.26). 권한이 아니라 상태다.
     *
     * @param array<string,mixed> $user Auth::currentUser() 또는 Auth::login() 이 돌려준 배열
     */
    public static function mfaRecommended(array $user): bool
    {
        return !self::mfaRequired($user) && (($user['mfa_enabled'] ?? false) !== true);
    }

    /**
     * 최초 로그인 강제 절차에서 **다음에 무엇을 해야 하나** (11단계, PRD F-16, ARCH 4.0).
     *
     * 순서가 있다: 비밀번호 변경 → 2단계 인증 등록 → 끝(null).
     * 이 함수는 **판단을 한 곳에 모으기 위한 것**이다 — 로그인 응답(`next`)·`/api/me`·
     * 라우터 관문이 전부 같은 값을 보고 움직여야 화면과 서버가 어긋나지 않는다.
     * 값은 언제나 DB 에서 읽은 사용자 배열에서 나오며 요청 값은 섞이지 않는다 (PRD S-01).
     *
     * F-52 (2026-09-16, ARCH 5.26) — `mfa_setup` 갈래는 **필수 역할**(MFA_REQUIRED_ROLES)
     * 과 명시적인 `mfa_recovery_required` 복구 계정에 걸린다. 일반 `mfa_setup_required` 힌트만으로 강제하지 않는다:
     * 플래그는 생성·해제·초기화가 켜고 확정이 끄는 값이라 보통 `!mfa_enabled` 와 같지만,
     * 어긋난 순간(마이그레이션·수동 수정)에 관문이 **열리면** 안 된다. 켜져 있는지는
     * `mfa_enabled` 가 진실이다. 플래그는 그대로 남아 감사 `forced` 와 안내 문구에 쓰인다.
     * `must_change_password` 갈래는 역할과 무관하게 그대로다 (F-16 ②).
     *
     * @param array<string,mixed> $user Auth::currentUser() 또는 Auth::login() 이 돌려준 배열
     * @return 'password_change'|'mfa_setup'|null
     */
    public static function nextStep(array $user): ?string
    {
        if (($user['must_change_password'] ?? false) === true) {
            return 'password_change';
        }
        if (self::mfaRequired($user) && (($user['mfa_enabled'] ?? false) !== true)) {
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
     *   5) (디버깅 묶음 S-5) 그 계정의 개인 프로그램 토큰을 **전부 회수**한다 — 같은 트랜잭션, 토큰마다
     *      `api_token.revoke allow password_change` 한 행(revokeAllApiTokens).
     *   6) (T:F-72) 그 계정의 지문·얼굴 로그인 열쇠를 **전부 해제**한다 — 같은 트랜잭션, 열쇠마다
     *      `auth.passkey_revoke allow password_change` 한 행(revokeAllPasskeys).
     *
     * 실패해도 응답 문구는 호출자가 정하고, 여기서는 **사유 코드**만 돌려준다.
     *
     * @param array<string,mixed> $actor 현재 로그인한 사용자 (요청 값이 아니다)
     * @return array{0:bool,1:?string,2:int,3:int,4:int} [성공 여부, 사유 코드(bad_credentials·weak_password·same_password), 끊은 세션 수, 회수한 토큰 수, 해제한 지문·얼굴 열쇠 수]
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
            return [false, 'bad_credentials', 0, 0, 0];
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
            return [false, 'same_password', 0, 0, 0];
        }

        // 10자 이상 (PRD S-09-4). 글자 수로 센다 — 한글 10자도 10자다.
        if (preg_match('//u', $new) !== 1
            || strlen($new) > self::MAX_PASSWORD_BYTES
            || iconv_strlen($new, 'UTF-8') < self::MIN_PASSWORD_CHARS
        ) {
            Audit::deny('auth.password_change', 'weak_password', $target);
            return [false, 'weak_password', 0, 0, 0];
        }

        $hash = password_hash($new, PASSWORD_ARGON2ID);
        if (!is_string($hash)) {
            throw new RuntimeException('비밀번호 해시를 만들 수 없습니다');
        }

        $keep     = (string) ($actor['session_id'] ?? '');
        $revoked  = 0;
        $tokens   = 0;
        $passkeys = 0;

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
            // S-5 — 개인 프로그램 토큰도 전부 회수한다(같은 트랜잭션 · 토큰마다 감사 한 행).
            //        회수한 수는 응답(`tokens_revoked`)으로만 돌려준다 — 위 감사 detail 은 그대로 둔다.
            $tokens = self::revokeAllApiTokens($userId, (string) $actor['username'], $userId, 'password_change');
            // T:F-72 — 지문·얼굴 로그인 열쇠도 전부 해제한다(같은 트랜잭션 · 열쇠마다 감사 한 행). 이 세션은 남는다.
            $passkeys = self::revokeAllPasskeys($userId, (string) $actor['username'], $userId, 'password_change');
            $db->commit();
        } catch (Throwable $e) {
            $db->rollback();
            throw $e;
        }

        return [true, null, $revoked, $tokens, $passkeys];
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

    /**
     * F-65 helper ① — **지금 이 요청에 실린 비밀번호가 맞나** 만 본다 (ARCH 5.29 「검증 helper」).
     *
     * `reauth()` 의 앞부분(`SQL_LOAD_BY_ID` + `password_verify`)과 **같은 규칙**이고,
     * 다른 점은 셋이다:
     *   · 세션에 `reauth_at` 을 **놓지 않는다** — F-65 는 「요청마다 확인」이 명세라
     *     15분 창을 열면 그 창이 곧 우회 경로가 된다 (PRD F-65 ②, ARCH 5.29 ④).
     *   · 감사·실패 집계(`countFailureAndRevoke`)를 **하지 않는다** — 같은 실패라도
     *     action 이 부르는 쪽마다 다르다(`file.upload_exec`·`file.download_exec`).
     *     그래서 「무엇을 하다 틀렸나」를 아는 호출자가 기록한다.
     *   · 성공해도 실패 카운터를 0 으로 돌리지 않는다 — 이 함수는 상태를 만들지 않는다.
     *
     * 비밀번호는 여기서 비교에만 쓰이고 응답·감사·`app.log` 어디에도 남지 않는다 (PRD 7.7).
     *
     * @param array<string,mixed> $user 현재 로그인한 사용자 (요청 값이 아니다 — PRD S-01)
     */
    public static function verifyCurrentPassword(array $user, string $password): bool
    {
        $row = Db::instance()->run(self::SQL_LOAD_BY_ID, [':id' => (int) $user['id']])->fetch();
        if (!is_array($row)) {
            return false;
        }
        return password_verify($password, (string) $row['password_hash']);
    }

    /**
     * F-65 helper ② — **2단계 인증 코드 한 번**을 확인하고 기준선을 올린다 (ARCH 5.29).
     *
     * ★ `admin_permissions_update.php` ② 와 **같은 규칙 두 벌**이다 — 같은 규칙이 두 곳에
     *   있다는 것은 알고 있고, 다음 정리 대상으로 남겨 두었다 (SECURITY R-94 비고).
     *   이번에 그 파일을 고치지 않는 이유: 권한 매트릭스 편집 창구는 F-65 와 무관한 보안
     *   핵심 경로이고, 지시서의 허용 목록 밖이다.
     *
     * 규칙(그쪽 151~170행과 글자 그대로 같다)
     *   · `mfa_enabled` 가 참이 아니거나 비밀이 없다 → `'mfa_not_enabled'`
     *     (코드 추측이 아니므로 **실패로 세지 않는다** — 집계 여부도 호출자가 정한다)
     *   · `Totp::verifyStep` 이 null → `'bad_code'`
     *   · 그 스텝을 이미 썼다(`mfa_last_step` 이상) → `'code_reused'` (R-45, RFC 6238 §5.2)
     *   · 통과 → `markMfaStep` 으로 기준선을 **바로** 올리고 `'ok'`
     *     맞은 코드는 뒤에서 거부되더라도 다시 쓸 수 없다 — 그래야 1회성이 뜻을 갖는다.
     *
     * 코드의 **형식**(`Totp::CODE_RE`, 6자리)은 호출자가 먼저 본다 — 형식 오류와 인증 실패는
     * 감사 사유가 다르기 때문이다. 코드 값·비밀·스텝 번호는 어디에도 남기지 않는다 (PRD 7.7).
     *
     * @param array<string,mixed> $user
     * @return string `ok` | `mfa_not_enabled` | `bad_code` | `code_reused`
     */
    public static function verifyOtpOnce(array $user, string $otp): string
    {
        $userId = (int) $user['id'];

        $sqlMfa = <<<'SQL'
            SELECT mfa_enabled, mfa_secret, mfa_last_step FROM users WHERE id = :id
            SQL;

        $row = Db::instance()->run($sqlMfa, [':id' => $userId])->fetch();
        if (!is_array($row) || $row['mfa_enabled'] !== true || !is_string($row['mfa_secret'])) {
            return 'mfa_not_enabled';
        }

        $step = Totp::verifyStep((string) $row['mfa_secret'], $otp);
        if ($step === null) {
            return 'bad_code';
        }

        $lastStep = $row['mfa_last_step'] === null ? null : (int) $row['mfa_last_step'];
        if (self::isMfaStepUsed($lastStep, (int) $step)) {
            return 'code_reused';
        }

        self::markMfaStep($userId, (int) $step);
        return 'ok';
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

    /**
     * 디버깅 묶음 S-5 — 그 계정의 **살아 있는 개인 프로그램 토큰 전부**를 회수한다 (ARCH 4.7 「자동 회수」).
     *
     * 왜: 비밀번호를 바꾸거나(본인) 관리자가 임시 비밀번호를 재발급·2단계 인증을 초기화하는 것은 대개
     * 「이 계정의 자격이 새었을 수 있다」는 뜻이다. 세션은 이미 끊었지만(R-10) 개인 토큰은 살아 있어,
     * 훔친 사람이 미리 발급해 둔 토큰으로 계속 올리고·지울 수 있었다(2026-09-26 보안 점검 낮음 9 · R-91).
     *
     * 부르는 쪽의 **트랜잭션 안에서** 부른다 — 비밀번호·2단계 변경과 토큰 회수·감사가 한 덩어리로 되거나
     * 안 된다. 행은 지우지 않는다(`revoked_at`·`revoked_by` UPDATE — 앱 계정에는 DELETE 권한이 없다, 6.2-5).
     * 토큰 하나에 감사 한 행: `api_token.revoke allow <사유>` · detail `token_id`·`token_name`(ARCH 7.5 등록부 키 —
     * 토큰 값도 해시도 hint 도 읽지 않는다). 사유는 코드가 정한 셋 중 하나다(요청 값이 아니다).
     *
     * @param int    $userId     토큰을 잃는 계정
     * @param string $targetName 그 계정의 아이디 (감사 문장의 {target})
     * @param int    $byUserId   회수한 사람 — 본인 변경이면 본인, 관리자 재발급·초기화면 그 관리자
     * @param string $cause      password_change | password_reset | mfa_reset
     * @return int   회수한 토큰 수
     */
    public static function revokeAllApiTokens(int $userId, string $targetName, int $byUserId, string $cause): int
    {
        if (!in_array($cause, self::API_TOKEN_REVOKE_CAUSES, true)) {
            throw new RuntimeException('토큰 회수 사유가 아닙니다');
        }
        $rows = Db::instance()->run(self::SQL_REVOKE_USER_API_TOKENS, [
            ':by'  => $byUserId,
            ':uid' => $userId,
        ])->fetchAll();

        foreach ($rows as $row) {
            Audit::allow('api_token.revoke', [
                'target_type' => 'user',
                'target_id'   => $userId,
                'target_name' => $targetName,
                'detail'      => [
                    'token_id'   => (int) $row['id'],
                    'token_name' => (string) $row['name'],
                ],
            ], $cause);
        }
        return count($rows);
    }

    /**
     * 쿠키에 실려 온 토큰의 세션 해시. 형식이 어긋나면 null (DB 를 건드리지 않는다).
     * 읽는 이름은 `COOKIE_NAME`(`__Host-`) 하나뿐이다 — 옛 이름은 값이 맞아도 세션으로 보지 않는다(S2).
     */
    private static function cookieSessionId(): ?string
    {
        self::dropLegacyCookie();
        $token = $_COOKIE[self::COOKIE_NAME] ?? null;
        if (!is_string($token) || preg_match(self::TOKEN_RE, $token) !== 1) {
            return null;
        }
        return hash('sha256', $token);
    }

    /** 이 요청에서 옛 이름 쿠키의 만료 머리를 이미 보냈나 (한 요청에 한 번) */
    private static bool $legacyDropped = false;

    /**
     * 옛 이름(`LEGACY_COOKIE_NAME`) 쿠키가 실려 왔으면 **만료 머리**를 보내 브라우저에서 치운다 (디버깅 묶음 S2).
     *
     * 값은 보지 않는다(형식·DB 조회 0) — 이름이 있으면 그뿐이다. 세션으로 쓰지 않으므로 이 쿠키로는 로그인 상태가
     * 이어지지 않는다(반영 뒤 모두 한 번 다시 로그인). 부르는 곳은 `cookieSessionId()` 하나이고, 그 함수는
     * `currentUser()`(요청마다 한 번 — 런처·로그인 화면·모든 로그인 필요 창구)와 `login()` 이 부른다.
     * 머리를 이미 보낸 뒤면(본문 출력 뒤) 조용히 건너뛴다 — 치우기는 정리이고, 그 때문에 요청이 500 이 되면 안 된다.
     */
    private static function dropLegacyCookie(): void
    {
        if (self::$legacyDropped || !array_key_exists(self::LEGACY_COOKIE_NAME, $_COOKIE)) {
            return;
        }
        self::$legacyDropped = true;
        if (!headers_sent()) {
            Http::clearLegacySessionCookie();
        }
    }

    /**
     * 이 요청이 「사람이 누르지 않은 주기 요청」인가 (Q:F-4 U-3) — `BACKGROUND_HEADER` 가 정확히 `BACKGROUND_VALUE` 일 때만.
     *
     * 헤더 이름은 위 상수 한 곳이고, PHP 가 넘겨주는 `$_SERVER` 이름(`HTTP_X_QAREDI_BACKGROUND`)은 그 상수에서 만든다.
     * 값은 상수와 `===` 로만 견준다 — SQL·로그·감사 어디에도 들어가지 않는다. 다른 값·빈 값·배열은 「사람 요청」이다
     * (연장되는 쪽 — 판정은 언제나 SQL_LOOKUP 이 하므로 어느 쪽이든 권한이 늘지 않는다).
     */
    private static function isBackgroundRequest(): bool
    {
        $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', self::BACKGROUND_HEADER));
        $sent      = $_SERVER[$serverKey] ?? null;
        return is_string($sent) && $sent === self::BACKGROUND_VALUE;
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

        $locked = is_array($row) && $row['is_locked'] === true;

        /*
         * 디버깅 묶음 S2 (d72 ① 로그인 시도 제한 점검) — **잠금이 걸린 순간**을 감사에 한 행 남긴다.
         *
         * 전에는 잠금 자체가 기록되지 않았다. 경보 AL-2 의 「계정 잠금 발생」은 **잠긴 계정으로 다시 로그인을 시도한**
         * 행(`auth.login deny locked`)으로만 보였으므로, 아이디만 아는 사람이 **정확히 5번** 틀리고 10분 쉬기를 되풀이하면
         * (잠금 DoS — R-06) 한 IP 가 10분에 5행이라 20회 기준에도 닿지 않아 경보가 조용했다. 로그인 뒤 창구(토큰 발급·
         * 실행 파일 받기 등)의 코드 추측으로 잠긴 경우도 같다(세션이 끊겨 다음 요청이 없다).
         * 이제 카운터가 한도에 **막 닿은** 실패(`failed_count = MAX_FAILED` — 잠긴 동안의 추가 실패는 6·7… 이라 다시 남지 않는다)마다
         * `auth.lock deny failed_limit` 한 행이 남고, AL-2 가 그 행을 「잠금 발생」으로 센다(scripts/alerts.php).
         * 누가 했나(actor)는 부른 자리의 세션이다 — 로그인 전이면 비어 있고 대상 계정은 target_name 이다(auth.login 과 같다).
         * 실패 원인(비밀번호·코드)은 이 행에 넣지 않는다 — 앞선 거부 행에 이미 있다.
         */
        if ($locked && (int) $row['failed_count'] === self::MAX_FAILED) {
            Audit::deny('auth.lock', 'failed_limit', [
                'target_type' => 'user',
                'target_id'   => $userId,
                'target_name' => (string) $row['username'],
                'detail'      => ['lock_minutes' => self::LOCK_MINUTES],
            ]);
        }

        return $locked;
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
