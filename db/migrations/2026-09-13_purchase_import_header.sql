-- ─────────────────────────────────────────────────────────────
-- 2026-09-13_purchase_import_header.sql
--   (19단계 1차 보정 — PRD F-29 엑셀 가져오기, ARCHITECTURE 5.18 「가져오기」)
--
-- 목적: 저장된 열 매핑이 **머리행이 몇 번째 행인지**도 함께 기억하게 한다.
--
-- 왜 필요한가 (2026-09-13 실측 사고)
--   이카운트 품목 내보내기(ESA009M.xlsx, 8,102행)의 0행은 제목 한 칸(`회사명 : …`)이고
--   **진짜 머리행은 1행**이다. 19단계 본 판은 rows[0] 을 머리행으로 못박아 두어, 열 목록이
--   한 칸뿐이라 사람이 매핑을 채울 수 없었고 미리보기가 `mapping_incomplete` 400 으로 끝났다
--   (감사 05:37:13 · 05:37:42). 서버가 머리행을 감지하도록 고치면서, **같은 양식을 다시 올릴
--   때 두 걸음으로 끝난다**는 F-29 의 약속을 지키려면 매핑과 함께 이 값도 저장해야 한다.
--   저장하지 않으면 매핑은 복원되는데 머리행은 매번 다시 골라야 한다.
--
-- 설계
--   · `smallint NOT NULL DEFAULT 0` — 0 은 「첫 행이 머리행」이고 보정 전 동작과 같다.
--     그래서 이미 저장된 매핑 행은 **값을 채우지 않아도 뜻이 달라지지 않는다**
--     (이행 UPDATE 가 한 줄도 없는 이유다).
--   · CHECK 0..19 — 앱의 `Importer::HEAD_ROWS`(20행까지 훑는다)와 같은 범위다. 앱이 먼저
--     보지만(`headerRowFrom`), 한 경로라도 빠지면 엉뚱한 행이 머리행이 되므로 DB 에 마지막
--     그물을 둔다 (users_purchase_role_check 와 같은 원칙).
--
-- 실행: sudo -u postgres psql -d tbox_re -v ON_ERROR_STOP=1 \
--            -f db/migrations/2026-09-13_purchase_import_header.sql
--       (새로 설치하는 DB 는 db/schema.sql 에 이미 들어 있어 이 파일이 필요 없다.)
--
-- 재실행 안전성(멱등): ADD COLUMN IF NOT EXISTS · 제약은 pg_constraint 를 먼저 확인.
--   **값을 바꾸는 UPDATE·DELETE 가 한 줄도 없다** (CLAUDE.md 4장).
-- 권한: 기존 표에 칸을 더하는 것뿐이라 GRANT 가 바뀌지 않는다(표 단위 권한이다).
-- ─────────────────────────────────────────────────────────────

\set ON_ERROR_STOP on

BEGIN;

ALTER TABLE p_import_mappings
    ADD COLUMN IF NOT EXISTS header_row SMALLINT NOT NULL DEFAULT 0;

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'p_import_mappings_header_row_check') THEN
        ALTER TABLE p_import_mappings ADD CONSTRAINT p_import_mappings_header_row_check
            CHECK (header_row BETWEEN 0 AND 19);
    END IF;
END
$$;

COMMIT;
