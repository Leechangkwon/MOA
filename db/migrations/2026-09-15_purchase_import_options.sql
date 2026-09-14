-- ─────────────────────────────────────────────────────────────
-- 2026-09-15_purchase_import_options.sql
--   (19단계 9차 보정 — PRD F-29 엑셀 가져오기, ARCHITECTURE 5.18 「가져오기」)
--
-- 목적: 저장된 열 매핑이 **그때 켜 두었던 옵션**까지 함께 기억하게 한다.
--
-- 왜 필요한가 (2026-09-14 실측)
--   이카운트 품목 내보내기(ESA009M, 8,100행)를 가져오려면 매핑만으로는 모자라다 —
--   「파일의 분류를 함께 등록」·「없는 거래처를 함께 등록」 두 체크가 켜져 있어야 8,100행 중
--   대부분이 오류를 벗어난다. 매핑만 복원하고 이 둘을 잊으면, 사람이 같은 체크를 **매달**
--   다시 켜야 하고 한 번 잊으면 그 달 가져오기가 통째로 오류가 된다. F-29 의 「같은 양식은
--   두 걸음으로 끝난다」 는 약속은 **매핑 + 머리행 + 옵션** 셋이 함께 남아야 성립한다.
--
-- 설계
--   · `jsonb NOT NULL DEFAULT '{}'` — 빈 객체는 「아무 옵션도 켜지 않음」이고, 이는 보정 전
--     동작과 **정확히 같다**. 그래서 이미 저장된 매핑 행은 값을 채우지 않아도 뜻이 달라지지
--     않는다(이행 UPDATE 가 한 줄도 없는 이유다).
--   · 담기는 것은 **화면이 고른 스위치와 id 뿐**이다(create_categories · create_vendors ·
--     create_brands · create_branches · derive · default_branch · default_brand). 파일 내용도,
--     사람 이름도, 비밀도 들어가지 않는다. 허용 키·값 타입은 저장 창구
--     (`src/handlers/purchase_import_mappings.php`)가 먼저 좁히고, 여기 CHECK 는 **객체인가**
--     만 본다 — 키 목록을 DB 에 박아 두면 앱에 옵션이 하나 늘 때마다 마이그레이션이 필요해진다.
--   · 크기 상한 CHECK 를 둔다(2KB). 옵션은 열쇠 몇 개짜리 작은 객체이며, 이 창구로 큰 덩어리가
--     들어오는 것은 오용이다(감사 detail 크기를 제한하는 것과 같은 판단).
--
-- 실행: sudo -u postgres psql -d tbox_re -v ON_ERROR_STOP=1 \
--            -f db/migrations/2026-09-15_purchase_import_options.sql
--       (새로 설치하는 DB 는 db/schema.sql 에 이미 들어 있어 이 파일이 필요 없다.)
--
-- 재실행 안전성(멱등): ADD COLUMN IF NOT EXISTS · 제약은 pg_constraint 를 먼저 확인.
--   **값을 바꾸는 UPDATE·DELETE 가 한 줄도 없다** (CLAUDE.md 4장).
-- 권한: 기존 표에 칸을 더하는 것뿐이라 GRANT 가 바뀌지 않는다(표 단위 권한이다).
-- ─────────────────────────────────────────────────────────────

\set ON_ERROR_STOP on

BEGIN;

ALTER TABLE p_import_mappings
    ADD COLUMN IF NOT EXISTS options_json JSONB NOT NULL DEFAULT '{}';

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'p_import_mappings_options_obj') THEN
        ALTER TABLE p_import_mappings ADD CONSTRAINT p_import_mappings_options_obj
            CHECK (jsonb_typeof(options_json) = 'object'
                   AND length(options_json::text) <= 2048);
    END IF;
END
$$;

COMMIT;
