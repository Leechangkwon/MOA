-- db/migrations/2026-10-14_task_start_date.sql — 작업의 「시작 예정」 (사이트 Q:F-12 W1 · 결정 WK-29)
--
-- 요구사항: ../../../community/work-ux-plan.md 19.2 · DESIGN.md 2.34 · 지시서 docs/qf12-w1-directive.md.
--
-- 추가 전용 · 되풀이 안전(ADD COLUMN IF NOT EXISTS · 누적 CHECK 는 이미 'start' 가 있으면 아무것도 하지 않는다).
-- 지우거나 고치는 행 0. 실행 계정: tbox_owner (표 소유자). 새 설치는 db/community/schema.sql 끝 「Q:F-12 W1」 절이
-- 같은 모양을 만든다(같은 문장). 앱 권한은 표 단위(community.pages SELECT · INSERT · UPDATE · DELETE)라 새 열에 따로 걸 것 0.
--
--   community.pages.start_date            시작 예정일(선택 · DATE). 실제 시작 · 끝난 날은 저장하지 않는다 — 속성 사건
--                                          (`page_property_events` prop 'status')에서 계산한다(routes.php community_task_dates).
--   page_property_events prop CHECK       'start' 더함(누적 CHECK — 지금 정의를 읽어 OR 로 잇는다)
--
-- 되돌리기(코드만 되돌리면 옛 코드는 새 열 · 새 사건 값을 읽지 않는다). 열을 걷어 낼 때만, 소유자로:
--   ALTER TABLE community.pages DROP COLUMN start_date;
--   (사건 CHECK 의 'start' 는 남겨도 해가 없다 — 지울 수 없는 기록이라 prop='start' 행이 있으면 좁힐 수 없다)

\set ON_ERROR_STOP on
BEGIN;
SET LOCAL lock_timeout = '5s';

-- ── 1. 시작 예정일 ──
ALTER TABLE community.pages ADD COLUMN IF NOT EXISTS start_date DATE;

-- ── 2. 속성 사건 'start' (누적 CHECK — 지금 정의를 읽어 OR 로 잇는다. 이미 있으면 아무것도 하지 않는다) ──
DO $$
DECLARE c record;
BEGIN
    FOR c IN SELECT conname, pg_get_constraintdef(oid) AS definition FROM pg_constraint
              WHERE conrelid = 'community.page_property_events'::regclass AND contype = 'c'
                AND pg_get_constraintdef(oid) LIKE '%prop%'
    LOOP
        IF position('''start''' in c.definition) = 0 THEN
            EXECUTE format('ALTER TABLE community.page_property_events DROP CONSTRAINT %I', c.conname);
            EXECUTE format('ALTER TABLE community.page_property_events ADD CONSTRAINT %I CHECK ((%s) OR prop = ''start'')',
                c.conname, substring(c.definition from 8 for length(c.definition) - 8));
        END IF;
    END LOOP;
END
$$;

COMMIT;
