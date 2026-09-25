-- db/migrations/2026-10-04_users_temp_password_issued_at.sql — 임시 비밀번호 유효기간 72시간 (SECURITY R-39 · 보안 넷 ④)
--
-- 근거: 사용자 결정 d52 「① 넷 다 진행」(2026-09-25 23:22) · SECURITY R-39 「미적용: 임시 비밀번호 유효기간(예 72시간)」 ·
--       구현 기록 docs/sec4-implementation.md 4장
--
-- 무엇
--   · users.temp_password_issued_at — Admin 이 임시 비밀번호를 **발급한 때**(계정 만들기 `POST /api/users` ·
--     비밀번호 재발급 `POST /api/users/{num}/password/reset`). 본인이 비밀번호를 바꾸면 NULL 로 돌아간다
--     (Auth::changePassword 의 SQL_SET_PASSWORD 한 문장).
--   · 로그인(Auth::login)은 must_change_password 이고 이 값이 72시간보다 오래됐으면 비밀번호가 맞아도 401
--     「임시 비밀번호가 만료되었습니다 …」 + 감사 `auth.login deny temp_expired`.
--   · **기존 행은 NULL = 만료 없음**(데이터를 바꾸는 문장 0). 이 파일 전에 발급된 임시 비밀번호·시드 계정
--     (scripts/seed_users.php — CLI·root, 값은 /etc/tbox-v2/seed-accounts.txt)은 지금처럼 동작한다.
--
-- 잠금: NULL 허용·기본값 없는 ADD COLUMN 은 카탈로그만 바꿔 곧 끝나지만 COMMIT 까지 users 에 배타 잠금을 쥔다.
-- 긴 읽기 뒤에 줄을 서서 로그인을 통째로 멈추지 않게 `lock_timeout 5s`(못 잡으면 오류 → ROLLBACK — 바뀐 것 없음, 다시 돌리면 된다).
-- 표 단위 권한(db/grants.sql `GRANT SELECT, INSERT, UPDATE ON users TO tbox_app`)이라 새 칸에도 앱 계정 권한이 그대로 닿는다.
-- 여러 번 돌려도 같다(IF NOT EXISTS). 새로 설치하는 DB 는 db/schema.sql 이 같은 모양을 만든다.
--
-- 순서(반드시): 이 마이그레이션 → 코드. 새 코드는 계정 만들기·재발급·비밀번호 변경 때 이 칸에 쓴다(칸이 없으면 500).
--   옛 코드는 이 칸을 모른다 — 칸만 먼저 있어도 아무 일도 없다.
-- 실행: sudo -u postgres psql -d tbox_re -v ON_ERROR_STOP=1 -f db/migrations/2026-10-04_users_temp_password_issued_at.sql
-- 되돌리기(코드를 먼저 되돌린 뒤): ALTER TABLE public.users DROP COLUMN IF EXISTS temp_password_issued_at;

\set ON_ERROR_STOP on
BEGIN;
SET LOCAL lock_timeout = '5s';
ALTER TABLE public.users ADD COLUMN IF NOT EXISTS temp_password_issued_at TIMESTAMPTZ;
COMMIT;
