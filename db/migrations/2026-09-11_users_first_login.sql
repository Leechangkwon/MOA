-- ─────────────────────────────────────────────────────────────
-- 2026-09-11_users_first_login.sql  (11단계 — 최초 로그인 강제 절차 · PRD F-16 · ARCH 4.0)
--
-- 목적: users 에 세 컬럼을 더하고, **패치 시점의 기존 활성 계정 전부**의 다음 로그인을
--       "최초 로그인" 으로 만든다 (F-16 ④). 행을 지우거나 다시 만들지 않는다 —
--       계정·파일·감사 기록은 그대로 두고 플래그만 켠다 (CLAUDE.md 4장 "운영 데이터 불가침").
--
--   · must_change_password — true 인 동안 라우터 관문(public/index.php)이 비밀번호 변경 외
--                            모든 요청을 403 으로 막는다. 임시 비밀번호를 발급한 Admin 이
--                            그 값을 계속 아는 상태를 첫 로그인에서 끝낸다 (SECURITY R-39 · R-33).
--   · mfa_setup_required   — 2단계 인증(TOTP)을 아직 등록하지 않았다. 비밀번호 관문을 통과하면
--                            이 관문이 이어진다. 2단계 인증은 모든 역할에 필수다 (R-05, F-16 ③).
--   · password_changed_at  — 본인이 비밀번호를 마지막으로 바꾼 시각. NULL 이면 한 번도 바꾸지 않은
--                            계정(= 설치·생성 때 받은 값을 그대로 쓰는 계정)이다.
--
-- 이행 UPDATE 는 아래 **한 문장뿐**이다.
--     UPDATE users SET must_change_password = true, mfa_setup_required = NOT mfa_enabled
--      WHERE is_active AND password_changed_at IS NULL;
--   · `password_changed_at IS NULL` 조건 덕에 **두 번 실행해도** 절차를 이미 마친 사람을
--     되돌리지 않는다(멱등). 비밀번호를 바꾼 계정은 password_changed_at 이 채워져 제외된다.
--   · `mfa_setup_required = NOT mfa_enabled` — 이미 TOTP 를 켠 계정에는 재등록을 요구하지 않는다.
--   · 비활성 계정은 건드리지 않는다(로그인 자체가 막혀 있고, 되살릴 때 Admin 이 판단한다).
--
-- 실행: sudo -u postgres psql -d tbox_re -v ON_ERROR_STOP=1 \
--            -f db/migrations/2026-09-11_users_first_login.sql
--       (새로 설치하는 DB 는 db/schema.sql 에 이미 들어 있어 이 파일이 필요 없다.)
--
-- 재실행 안전성(멱등): ADD COLUMN IF NOT EXISTS ×3 · 위 UPDATE 의 WHERE 조건.
-- 권한: GRANT/REVOKE 를 하지 않는다. 컬럼 추가는 기존 표 권한에 자동으로 포함된다 (ARCH 10장).
-- ─────────────────────────────────────────────────────────────

\set ON_ERROR_STOP on

BEGIN;

-- [1] 컬럼 세 개 (기본값은 false/NULL — 새로 만드는 계정의 값은 앱이 정한다)
ALTER TABLE users ADD COLUMN IF NOT EXISTS must_change_password BOOLEAN NOT NULL DEFAULT false;
ALTER TABLE users ADD COLUMN IF NOT EXISTS mfa_setup_required   BOOLEAN NOT NULL DEFAULT false;
ALTER TABLE users ADD COLUMN IF NOT EXISTS password_changed_at  TIMESTAMPTZ;

-- [2] 기존 활성 계정 이행 — 이 파일에서 데이터를 바꾸는 문장은 이것 하나뿐이다.
UPDATE users SET must_change_password = true, mfa_setup_required = NOT mfa_enabled WHERE is_active AND password_changed_at IS NULL;

COMMIT;
