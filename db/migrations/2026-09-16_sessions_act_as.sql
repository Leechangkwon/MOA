-- ─────────────────────────────────────────────────────────────
-- 2026-09-16_sessions_act_as.sql  (26단계 — PRD F-39, ARCHITECTURE 5.20 「역할로 보기」)
--
-- 목적: sessions 에 컬럼 여섯 개를 더한다 — 이 **세션**이 지금 어떤 역할로 보고 있나.
--   · act_role          보는 t-box 역할 (Manager|Employee|Guest). NULL 이면 보는 중이 아니다.
--   · act_team_id       보는 팀 (NULL 이면 팀 없음)
--   · act_purchase_role 보는 구매 모듈 역할 (requester|buyer|stock|viewer, NULL 이면 없음)
--   · act_branch_id     보는 지점 (NULL 이면 없음)
--   · act_started_at    보기 시작한 시각 (화면 표시·감사용. 만료 판정에는 쓰지 않는다)
--   · act_last_seen_at  이 세션이 **보는 중에 마지막으로 요청을 보낸** 시각 → 만료 판정의 기준
--
-- 왜 필요한가 (PRD F-39, 사용자 결정 2026-09-15 A안)
--   관리자가 "Employee 에게는 이 화면이 어떻게 보이나" 를 확인할 방법이 없었다. 계정을 하나 더
--   만들어 로그인하는 방식은 감사 로그에서 사람이 갈라지지 않고(누가 했는지 흐려진다), 화면만
--   흉내 내는 방식은 **서버 판정과 어긋나** 「화면엔 안 보이는데 API 는 되는」 상태를 만든다.
--   그래서 유효 역할 자체를 세션 안에서 바꾼다 — 판정 코드는 한 줄도 고치지 않는다.
--
-- 왜 users 가 아니라 sessions 인가 (B안 기각)
--   users.role 을 바꾸면 **실제 권한**이 바뀐다. 되돌리기 전에 세션이 끊기거나 브라우저를 닫으면
--   그 계정은 Employee 인 채로 남고, 되돌릴 사람(Admin)이 사라질 수도 있다. 세션 행에 두면
--   그 브라우저의 그 로그인에만 붙고, 로그아웃·세션 만료와 함께 저절로 사라진다.
--   reauth_at 을 sessions 에 둔 것(2026-09-12)과 같은 판단이다.
--
-- 왜 act_last_seen_at 인가 — 「활동 없는 30분」 (사용자 결정 2026-09-15 ③)
--   「시작 뒤 30분」이면 쓰는 중에 끊긴다. 요청마다 이 값을 now() 로 밀고, 판정은
--     act_role IS NOT NULL AND act_last_seen_at > now() - make_interval(mins => 30)
--   하나로 한다 — **DB 시계 하나**로 보는 것도 reauth_fresh 와 같다.
--   갱신은 기존 `UPDATE sessions SET last_seen_at = now()` 문장에 한 열을 더한 것이라
--   요청당 쿼리 수가 늘지 않는다.
--
-- 왜 CHECK 두 개인가 — **아래로만** (PRD F-39 「위로 가는 조합 없음」)
--   act_role 에 'Admin' 을 넣을 수 없다. 앱(me_act_start.php)이 먼저 막지만, 앱을 우회한
--   어떤 경로로도 권한이 **늘어나는** 조합이 표에 들어가지 못하게 마지막 그물을 둔다.
--   같은 이유로 act_purchase_role 도 모듈 역할 4값으로 좁힌다 (users_purchase_role_check 와 같은 값).
--
-- 값의 이행
--   기존 세션은 전부 NULL 로 남는다(= 보는 중이 아님). 기존 행을 채우는 UPDATE 는 하지 않는다.
--
-- 채우는 곳 / 지우는 곳 (앱)
--   · src/handlers/me_act_start.php  POST   /api/me/act — 여섯 열을 함께 놓는다 (재인증 관문 뒤)
--   · src/handlers/me_act_stop.php   DELETE /api/me/act — 여섯 열을 NULL 로 되돌린다
--   · src/Auth.php currentUser()                       — 만료(idle)·강등(role_lost)이면 NULL 로 되돌리고
--                                                        감사 `act.expire allow` 한 줄을 남긴다
--   세션 행이 사라지면 값도 함께 사라진다.
--
-- 실행: sudo -u postgres psql -d tbox_re -v ON_ERROR_STOP=1 \
--            -f db/migrations/2026-09-16_sessions_act_as.sql
--       (새로 설치하는 DB 는 db/schema.sql 에 이미 들어 있어 이 파일이 필요 없다.)
--
-- 재실행 안전성(멱등): ADD COLUMN IF NOT EXISTS 여섯 개와, 이미 있으면 건너뛰는 제약 두 개뿐이다.
--   값을 덮어쓰는 UPDATE 가 없고 DEFAULT 도 없다 — 기존 행은 NULL 로 남는다 (CLAUDE.md 4장).
-- 권한: GRANT/REVOKE 를 하지 않는다. 컬럼 추가는 기존 표 권한에 자동으로 포함되고,
--   tbox_app 은 이미 sessions 에 SELECT/INSERT/UPDATE/DELETE 를 갖는다 (db/grants.sql, ARCH 10장).
-- ─────────────────────────────────────────────────────────────

\set ON_ERROR_STOP on

BEGIN;

ALTER TABLE sessions ADD COLUMN IF NOT EXISTS act_role          TEXT;
ALTER TABLE sessions ADD COLUMN IF NOT EXISTS act_team_id       BIGINT;
ALTER TABLE sessions ADD COLUMN IF NOT EXISTS act_purchase_role TEXT;
ALTER TABLE sessions ADD COLUMN IF NOT EXISTS act_branch_id     BIGINT;
ALTER TABLE sessions ADD COLUMN IF NOT EXISTS act_started_at    TIMESTAMPTZ;
ALTER TABLE sessions ADD COLUMN IF NOT EXISTS act_last_seen_at  TIMESTAMPTZ;

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'sessions_act_role_check') THEN
        ALTER TABLE sessions ADD CONSTRAINT sessions_act_role_check
            CHECK (act_role IS NULL OR act_role IN ('Manager', 'Employee', 'Guest'));
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'sessions_act_purchase_role_check') THEN
        ALTER TABLE sessions ADD CONSTRAINT sessions_act_purchase_role_check
            CHECK (act_purchase_role IS NULL
                   OR act_purchase_role IN ('requester', 'buyer', 'stock', 'viewer'));
    END IF;
END
$$;

COMMENT ON COLUMN sessions.act_role IS
    '이 세션이 「역할로 보기」 중인 t-box 역할. NULL 이면 보는 중이 아니다 (F-39). Admin 은 넣을 수 없다';
COMMENT ON COLUMN sessions.act_team_id IS
    '「역할로 보기」의 팀 (NULL 이면 팀 없음). 판정은 Auth::currentUser 가 유효 team_id 로 바꿔 준다';
COMMENT ON COLUMN sessions.act_purchase_role IS
    '「역할로 보기」의 구매 모듈 역할 (NULL 이면 없음) — users.purchase_role 과 같은 4값';
COMMENT ON COLUMN sessions.act_branch_id IS
    '「역할로 보기」의 지점 (NULL 이면 없음). requester·stock 으로 볼 때는 앱이 필수로 요구한다';
COMMENT ON COLUMN sessions.act_started_at IS
    '「역할로 보기」를 시작한 시각. 표시·감사용이며 만료 판정에는 쓰지 않는다';
COMMENT ON COLUMN sessions.act_last_seen_at IS
    '보는 중 마지막 요청 시각. 이 값이 30분보다 오래되면 자동 해제한다 (활동 없는 30분 — F-39)';

COMMIT;
