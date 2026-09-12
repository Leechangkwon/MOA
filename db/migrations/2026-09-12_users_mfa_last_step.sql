-- ─────────────────────────────────────────────────────────────
-- 2026-09-12_users_mfa_last_step.sql  (13단계 — SECURITY R-45, PRD T-31, ARCHITECTURE 4.0)
--
-- 목적: users 에 컬럼 하나를 더한다.
--   · mfa_last_step — 그 계정이 **마지막으로 성공시킨 TOTP 스텝 번호**
--                     (= 유닉스 시각 ÷ 30 의 몫). 한 번도 쓴 적이 없으면 NULL.
--
-- 왜 필요한가 (2026-09-12 전면 점검 실측)
--   `Totp::verify` 는 ±1 창(약 90초)의 코드를 "맞다/틀리다" 로만 판정하고 한 번 쓴 코드를
--   기억하지 않았다. 그래서 같은 6자리로 90초 안에 **여러 번** 로그인할 수 있었다
--   (qa_guest01 로 같은 코드 2회 연속 200). RFC 6238 §5.2 는 "한 번 쓴 OTP 는 거부" 를
--   요구한다. 어깨너머로 코드를 본 사람에게 그 90초가 그대로 기회였다.
--
-- 왜 코드가 아니라 "스텝 번호" 인가
--   쓴 코드 6자리를 저장하면 그것은 곧 인증 값을 저장하는 것이다(비밀 저장 금지, PRD 7.7).
--   스텝은 시각에서 나오는 **단조 증가 정수**라 그 값만으로는 코드를 만들 수 없고
--   (비밀이 있어야 한다), "이 스텝 이하는 이미 썼다" 는 판정 하나로 재사용을 전부 막는다.
--   BIGINT 인 이유: 스텝은 대략 5,860만(2026년)이고 앞으로도 계속 커진다 — INT 로도 수백 년
--   버티지만, 시간에서 파생되는 값에 상한을 좁게 잡을 이유가 없다.
--
-- 값의 이행
--   기존 계정은 전부 NULL 로 남는다(= "아직 쓴 적 없음"). 지금까지 어떤 코드를 썼는지는
--   기록이 없고, 지어내지 않는다. 각 계정의 **다음 로그인 성공**이 첫 기준선을 놓는다.
--   그 한 번 동안만 옛 코드가 통할 수 있는데, 그 창은 어차피 90초짜리라 실질적 차이가 없다.
--
-- 채우는 곳 (앱)
--   · src/Auth.php        login() 성공 시 (세션 발급과 같은 트랜잭션)
--   · src/handlers/mfa_confirm.php   2단계 인증 확정 성공 시 — 새 비밀을 저장하는 enroll 이
--                                    아니라 **확정** 시점이다(enroll 은 실패할 수 있다)
--   · src/handlers/mfa_disable.php   해제 성공 시 NULL 로 되돌린다 (비밀을 지우므로)
--   전부 GREATEST(COALESCE(mfa_last_step,0), :step) 으로 올려, 기준선이 뒤로 가지 않는다.
--
-- 실행: sudo -u postgres psql -d tbox_re -v ON_ERROR_STOP=1 \
--            -f db/migrations/2026-09-12_users_mfa_last_step.sql
--       (새로 설치하는 DB 는 db/schema.sql 에 이미 들어 있어 이 파일이 필요 없다.)
--
-- 재실행 안전성(멱등): ADD COLUMN IF NOT EXISTS 하나뿐이다. 값을 덮어쓰는 UPDATE 가 없고
--   DEFAULT 도 없다 — 기존 행은 NULL 로 남는다(운영 데이터 불변, CLAUDE.md 4장).
-- 권한: GRANT/REVOKE 를 하지 않는다. 컬럼 추가는 기존 표 권한에 자동으로 포함되고,
--   tbox_app 은 이미 users 에 SELECT/INSERT/UPDATE 를 갖는다 (db/grants.sql, ARCH 10장).
--   `audit_logs`(=ar)·`permissions`(=r) 의 권한은 한 글자도 건드리지 않는다.
-- ─────────────────────────────────────────────────────────────

\set ON_ERROR_STOP on

BEGIN;

ALTER TABLE users ADD COLUMN IF NOT EXISTS mfa_last_step BIGINT;

COMMENT ON COLUMN users.mfa_last_step IS
    '마지막으로 성공시킨 TOTP 스텝(유닉스 시각/30). 이 값 이하의 스텝은 재사용으로 거부 (R-45)';

COMMIT;
