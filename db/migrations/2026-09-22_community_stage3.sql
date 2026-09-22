-- db/migrations/2026-09-22_community_stage3.sql — 커뮤니티 3단계 스키마 적용
--
-- 요구사항: 커뮤니티 3단계 (../../../community/ARCHITECTURE.md 21.3, stage3-directive.md)
--
-- 하는 일: 스키마 `community` 안에 표 일곱 개를 만든다. 그게 전부다.
--   · 스키마 `community` 는 **이미 있고 비어 있다**(소유 tbox_owner) — CREATE SCHEMA 를 하지 않는다.
--   · `legacy_community`(v0.1~0.2 게시판의 잔재)는 **한 글자도 건드리지 않는다**.
--   · `public` 스키마의 t-box 표에는 아무 변경도 없다 — 이 마이그레이션은 t-box 를 고치지 않는다.
--
-- 실행 (사용자가 한다 — Opus·Fable 은 운영 DB 에 DDL 을 돌리지 않는다)
--   1) 표 만들기 (tbox_owner)
--      PGPASSFILE=/etc/tbox-v2/owner.pgpass psql -h 127.0.0.1 -U tbox_owner -d tbox_re \
--        -v ON_ERROR_STOP=1 -f db/migrations/2026-09-22_community_stage3.sql
--   2) 권한 (postgres)
--      sudo -u postgres psql -d tbox_re -v ON_ERROR_STOP=1 -f db/community/grants.sql
--   3) 확인
--      sudo -u postgres psql -d tbox_re -c '\dt community.*' -c '\dp community.*'
--
-- 되돌리기: `DROP SCHEMA community CASCADE; CREATE SCHEMA community AUTHORIZATION tbox_owner;`
--           (3단계 적용 직후, 아직 아무도 쓰지 않았을 때만. 쓰기 시작한 뒤에는 되돌리지 않는다.)

\set ON_ERROR_STOP on

-- 본문은 db/community/schema.sql 한 벌이다. 여기에 베껴 두면 두 벌이 되고 언젠가 한쪽만 고쳐진다.
-- `\ir` 은 **이 스크립트 파일 기준의 상대 경로**라 어느 디렉터리에서 실행해도 같은 파일을 읽는다.
\ir ../community/schema.sql
