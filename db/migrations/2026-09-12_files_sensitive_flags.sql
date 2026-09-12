-- ─────────────────────────────────────────────────────────────
-- 2026-09-12_files_sensitive_flags.sql  (15단계 15-3 — PRD F-22 민감정보 패턴 검출, ARCH 5.13)
--
-- 목적: files 에 `sensitive_flags TEXT[]` 한 칸을 더한다.
--   값은 **패턴 이름만**(`rrn`·`card`·`keyword`)이고, 검출된 숫자·문자열 원문은
--   어떤 형태로도 들어가지 않는다 (PRD S-07 "민감정보 금지", SECURITY R-58).
--   비어 있으면(`{}`) 검출되지 않았거나 검사 대상 형식이 아니었다는 뜻이다.
--
-- 이 칸을 보는 곳
--   · 업로드·URL 가져오기 — 검출되면 `public`·`internal` 로는 저장하지 않는다(400).
--     `team`·`admin-only` 면 저장하면서 이 칸을 함께 채운다.
--   · 등급 변경(PATCH) — 이 칸이 비어 있지 않으면 `public`·`internal` 로 **낮추는 변경도** 거부.
--     그때는 파일을 다시 읽지 않고 이 칸만 본다(ARCH 5.13).
--   · 목록 — `cardinality(sensitive_flags) > 0` 불리언만 내려 「민감」 배지를 그린다.
--     패턴 이름은 목록에 내리지 않는다.
--
-- 실행: sudo -u postgres psql -d tbox_re -v ON_ERROR_STOP=1 \
--            -f db/migrations/2026-09-12_files_sensitive_flags.sql
--       (새로 설치하는 DB 는 db/schema.sql 에 이미 들어 있어 이 파일이 필요 없다.)
--
-- 재실행 안전성(멱등): ADD COLUMN IF NOT EXISTS 하나뿐이다.
--   기존 행은 DEFAULT '{}' 로 채워지며 **값을 덮어쓰는 UPDATE 가 없다** —
--   이미 올라와 있는 파일을 소급해서 다시 검사하지 않는다(검사는 업로드 시점의 장치다).
-- 권한: GRANT/REVOKE 를 하지 않는다. 컬럼 추가는 기존 files 권한에 자동으로 포함된다.
-- ─────────────────────────────────────────────────────────────

\set ON_ERROR_STOP on

ALTER TABLE files ADD COLUMN IF NOT EXISTS sensitive_flags TEXT[] NOT NULL DEFAULT '{}';
