-- ─────────────────────────────────────────────────────────────
-- 2026-09-11_users_mfa_expiry.sql  (10단계 — F-10 계정 관리 · R-12 Guest 만료 · R-05 2단계 인증)
--
-- 목적: users 에 네 컬럼을 더한다.
--   · expires_at   — 계정 만료 시각. **Guest 는 필수**(SECURITY R-12 "협력사 계정에 만료가 없어
--                    프로젝트가 끝나도 영구 접근"). 만료된 뒤의 로그인은 401 + `user.expired`.
--   · mfa_secret   — TOTP(RFC 6238) 공유 비밀(base32). **켜져 있을 때만** 값이 있고,
--                    끄면 NULL 로 되돌린다. 감사 로그·응답(enroll 응답 제외)에 나가지 않는다.
--   · mfa_enabled  — 2단계 인증을 켰나 (SECURITY R-05 "Admin 계정 탈취 — 비밀번호 단일 요소").
--   · updated_at   — 계정 정보가 마지막으로 바뀐 시각 (역할·활성·비밀번호·MFA 변경 시 갱신).
--
-- 왜 CHECK 로 "Guest 는 expires_at 필수" 를 거나 (10단계 결정 2026-09-11)
--   앱 코드가 한 군데라도 검사를 빠뜨리면 만료 없는 Guest 가 생긴다. DB 가 막으면
--   어떤 경로(psql 직접 조작 포함)로도 만료 없는 Guest 행이 존재할 수 없다 — PRD 9장의
--   "제약 조건" 이 파일 레벨(team_requires_team_id)에 쓰인 것과 같은 방식이다.
--   기존 Guest 행(guest01 · qa_guest01)은 제약을 걸기 **전에** now() + 90일로 채운다
--   (SECURITY A-8 "Guest expires_at").
--
-- 실행: sudo -u postgres psql -d tbox_re -v ON_ERROR_STOP=1 \
--            -f db/migrations/2026-09-11_users_mfa_expiry.sql
--       (새로 설치하는 DB 는 db/schema.sql 에 이미 들어 있어 이 파일이 필요 없다.)
--
-- 재실행 안전성(멱등): ADD COLUMN IF NOT EXISTS · UPDATE 의 WHERE expires_at IS NULL ·
--   제약은 pg_constraint 를 먼저 보고 없을 때만 건다.
-- 권한: GRANT/REVOKE 를 하지 않는다. 컬럼 추가는 기존 표 권한에 자동으로 포함된다 (ARCH 10장).
-- ─────────────────────────────────────────────────────────────

\set ON_ERROR_STOP on

BEGIN;

-- [1] 컬럼 네 개
ALTER TABLE users ADD COLUMN IF NOT EXISTS expires_at  TIMESTAMPTZ;
ALTER TABLE users ADD COLUMN IF NOT EXISTS mfa_secret  TEXT;
ALTER TABLE users ADD COLUMN IF NOT EXISTS mfa_enabled BOOLEAN NOT NULL DEFAULT false;

-- updated_at 은 "계정이 마지막으로 바뀐 시각" 이다. DEFAULT now() 로 그냥 더하면 기존 행의
-- updated_at 이 **마이그레이션 시각**이 되어, 최초 비밀번호를 한 번도 바꾸지 않은 계정까지
-- "바꿨다" 로 보인다 (README 의 R-33 절차가 이 비교를 쓴다).
-- 그래서 컬럼이 **없을 때만** 더하고, 그 자리에서 created_at 으로 되돌린다.
-- (이미 있으면 아무것도 하지 않는다 — 재실행이 실제 변경 이력을 지우지 않게.)
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
         WHERE table_schema = 'public' AND table_name = 'users' AND column_name = 'updated_at'
    ) THEN
        ALTER TABLE users ADD COLUMN updated_at TIMESTAMPTZ NOT NULL DEFAULT now();
        UPDATE users SET updated_at = created_at;
    END IF;
END
$$;

-- [2] 기존 Guest 행 채우기 — 제약을 걸기 전에 해야 한다(그러지 않으면 ALTER 가 실패한다).
UPDATE users
   SET expires_at = now() + interval '90 days'
 WHERE role = 'Guest'
   AND expires_at IS NULL;

-- [3] "Guest 는 만료일 필수" 를 DB 가 강제한다 (SECURITY R-12).
--     ALTER TABLE ... ADD CONSTRAINT 에는 IF NOT EXISTS 가 없으므로 카탈로그를 먼저 본다.
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
         WHERE conrelid = 'public.users'::regclass
           AND conname  = 'guest_requires_expires_at'
    ) THEN
        ALTER TABLE users
            ADD CONSTRAINT guest_requires_expires_at
            CHECK (role <> 'Guest' OR expires_at IS NOT NULL);
    END IF;
END
$$;

-- [4] 로그인 때마다 보는 조건이 아니므로 인덱스는 만들지 않는다 —
--     만료 검사는 이미 username 으로 찾은 행 한 건에 대해서만 한다.

COMMIT;
