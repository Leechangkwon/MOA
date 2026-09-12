-- ─────────────────────────────────────────────────────────────
-- 2026-09-13_purchase_vendor_identity.sql
--   (19단계 2차 보정 — PRD F-28 기초정보 · F-29 엑셀 가져오기, ARCHITECTURE 5.18)
--
-- 목적 셋
--   ① 거래처의 **정체를 이름에서 코드로** 옮긴다 (`p_vendors_name_uidx` 삭제).
--   ② 이카운트 거래처 파일이 주는 칸 둘을 받는다 (`ceo_name` · `tel`).
--   ③ 지점에도 이카운트 코드를 담을 칸을 둔다 (`p_branches.code`).
--
-- ① 왜 이름 유일 제약을 버리나 (2026-09-13 실측)
--   사용자가 올린 실제 이카운트 거래처 내보내기(ESA001M.xlsx, 184행)에는
--   **이름이 같은데 코드가 다른 거래처가 3쌍(6행)** 있다(「메디코디」·「신흥」·「케이기공소」).
--   회사가 같은 상호로 사업자를 나눠 두었거나 지점이 따로 등록된 실제 상황이며,
--   **거래처의 정체는 이카운트 코드**다. 이름을 유일 열쇠로 붙들고 있으면 184행 중 3행 때문에
--   `23505 unique_violation` → 확정이 통째로 롤백되고(한 트랜잭션이므로 정상 동작이다),
--   사용자는 409 「이미 있는 이름입니다」 만 보고 **어느 행이 문제인지 알 수 없다**
--   (감사 07:40~07:41 conflict 4회).
--
--   그래서 제약을 **코드 기준**으로 옮긴다:
--     · `p_vendors_code_uidx` (code 유일) — **그대로 둔다.** 이것이 거래처의 정체다.
--     · `p_vendors_name_uidx` — **삭제.** 대신 같은 이름은 **앱이 「확인 필요」로 걸러** 사람이
--       판단하게 한다(Importer::judgeVendor 의 `duplicate_name_in_file`·`name_exists`,
--       화면 수동 등록은 409 + 「그래도 등록」 확인).
--     · 조회 성능을 위해 **유일하지 않은** 인덱스를 같은 식(`lower(name)`)에 새로 둔다 —
--       가져오기가 이름으로 거래처를 찾는 경로(Importer::lookups)가 그대로 빠르게 남는다.
--
--   ★ 느슨해지는 것: 이제 DB 는 같은 이름의 거래처를 막지 않는다. 막는 자리는 앱의 판정과
--     화면 확인 두 곳이며, 그 둘은 **사람에게 무엇이 겹치는지 보여 준 뒤** 통과시킨다.
--     "조용히 두 개가 생기는" 경로는 만들지 않았다(가져오기는 review, 수동은 409 + 확인).
--
-- ② ceo_name · tel — 이카운트 열 「대표자명」·「전화」(없으면 「모바일」). 둘 다 **회사 정보**이며
--    개인 연락처가 아니다(거래처 대표·대표번호). 그래도 값이 **감사 detail 의 before/after 에
--    남는다**는 점은 브랜드의 회사 정보와 같다 — 거래처 담당자의 개인 연락처는 받지 않는다
--    (SECURITY R-69 「명세서·가져오기 파일의 개인정보 최소화」).
--
-- ③ p_branches.code — 이카운트 창고/사업장 코드를 지점에 붙여 둔다. 선택값이고 유일 제약을
--    두지 않는다(지점은 수가 적고 사람이 직접 만든다).
--
-- 실행: sudo -u postgres psql -d tbox_re -v ON_ERROR_STOP=1 \
--            -f db/migrations/2026-09-13_purchase_vendor_identity.sql
--       (새로 설치하는 DB 는 db/schema.sql 에 이미 반영돼 있어 이 파일이 필요 없다.)
--
-- 재실행 안전성(멱등): DROP INDEX IF EXISTS · ADD COLUMN IF NOT EXISTS · CREATE INDEX IF NOT EXISTS.
--   **값을 바꾸는 UPDATE·DELETE 가 한 줄도 없다** (CLAUDE.md 4장).
-- 권한: 기존 표에 칸·인덱스를 더하는 것뿐이라 GRANT 가 바뀌지 않는다(권한은 표 단위다).
-- ─────────────────────────────────────────────────────────────

\set ON_ERROR_STOP on

BEGIN;

-- ① 이름 유일 제약 → 같은 식의 **일반** 인덱스로 교체
DROP INDEX IF EXISTS p_vendors_name_uidx;
CREATE INDEX IF NOT EXISTS p_vendors_name_idx ON p_vendors (lower(name));

-- ② 이카운트 거래처 파일이 주는 회사 정보 두 칸
ALTER TABLE p_vendors ADD COLUMN IF NOT EXISTS ceo_name TEXT;
ALTER TABLE p_vendors ADD COLUMN IF NOT EXISTS tel      TEXT;

-- ③ 지점의 이카운트 코드 (선택값, 유일 제약 없음)
ALTER TABLE p_branches ADD COLUMN IF NOT EXISTS code TEXT;

COMMIT;
