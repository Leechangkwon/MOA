-- db/migrations/2026-10-08_users_high_contrast.sql
--   커뮤니티 7-D3 — 계정의 **화면 취향** 「고대비 화면」 칸 (사이트 공통 — t-box 계정 표)
--   (파일 이름의 날짜는 적용 **순서**용이다 — 패스키 2026-10-06_passkeys.sql · 7-D2 팔레트 2026-10-07 뒤. 작성 2026-09-27)
--
-- 요구사항: ../../../community/stage7d-palette-contrast-mention-directive.md 2장 H-1 · 2.2 (결정 J-5 · L-3 「사용자 설정(내 계정)」)
--           DESIGN.md 2.16 「고대비 토큰 한 벌」
--
-- 실행 계정: tbox_owner (표 소유자) 또는 postgres.
-- 실행 예:  sudo -u postgres psql -d tbox_re -v ON_ERROR_STOP=1 -f - < db/migrations/2026-10-08_users_high_contrast.sql
--   ★ 운영 반영은 사용자 sudo 스크립트(review-scripts/apply-7d3-db-2026-09-27.sh — 검토 뒤 /var/www/qaredi/scripts/)로 한다.
--
-- 하는 일 (표·열 **삭제 0**, 데이터 손실 0, 여러 번 돌려도 같다)
--   1. `users.high_contrast BOOLEAN NOT NULL DEFAULT false` — 켜면 셸·런처가 `<html data-contrast="high">` 를 그린다.
--      지금 있는 계정은 전부 `false`(끔)로 채워진다 — 화면은 지금과 한 픽셀도 다르지 않다.
--      **권한 판단에 쓰지 않는다**(화면 취향 — `Auth::SQL_LOOKUP` 이 세션 조회 한 번에 함께 읽을 뿐이다).
--      쓰기는 **본인 창구 하나**(`PATCH /api/me/settings`, 정책 authenticated — 대상은 언제나 세션의 본인).
--
-- 권한: GRANT/REVOKE 를 하지 않는다. 열 추가는 **표 단위 권한에 자동으로 포함**된다
--   (`db/grants.sql` — `users` 는 tbox_app 에 SELECT·INSERT·UPDATE, tbox_backup 에 SELECT. 2026-09-23_users_display_name.sql 머리글과 같은 근거).
--   `tbox_push` 의 **열 단위** SELECT 셋(id·role·is_active)에는 넣지 않는다 — 그 계정이 볼 까닭이 없다(`\dp users` 로 이전과 같은지 본다).
--
-- ★ 코드보다 **먼저** 적용한다. 7-D3 코드는 세션을 읽는 질의(`Auth::SQL_LOOKUP`)부터 이 칸을 읽는다 —
--   적용 전의 DB 에서 7-D3 코드를 돌리면 **모든 요청이 500** 이다(5-A″ display_name 과 같은 교훈).
--
-- 되돌리기 (코드를 먼저 되돌린 뒤 — 칸의 값은 화면 취향뿐이라 지워도 잃는 기록이 없다):
--   ALTER TABLE users DROP COLUMN high_contrast;

\set ON_ERROR_STOP on

BEGIN;
SET LOCAL lock_timeout = '5s';

ALTER TABLE users ADD COLUMN IF NOT EXISTS high_contrast BOOLEAN NOT NULL DEFAULT false;

COMMIT;
