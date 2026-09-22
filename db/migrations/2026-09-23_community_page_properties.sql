-- db/migrations/2026-09-23_community_page_properties.sql
--   커뮤니티 4-D — 상태·담당자 칸 (C:F-37 · C:S-14)
--
-- 요구사항: ../../../community/PRD.md F-37 · 11-1 ⑤,
--           ../../../community/ARCHITECTURE.md 13.1(표) · 「게시판 종류(F-35)」 줄,
--           ../../../community/stage4d-status-assignee-directive.md 「데이터 — 마이그레이션」
--
-- 실행 계정: tbox_owner (표 소유자).
-- 실행 예:  PGPASSFILE=/etc/tbox-v2/owner.pgpass psql -h 127.0.0.1 -U tbox_owner -d tbox_re \
--             -v ON_ERROR_STOP=1 -f db/migrations/2026-09-23_community_page_properties.sql
--
-- ★ 이 파일 뒤에 `db/community/grants.sql` 을 **반드시 한 번 더** 돌린다(postgres 계정).
--   새 표 `page_property_events` 에는 아직 앱 계정 권한이 하나도 없다 — 그 파일이
--   SELECT·INSERT **둘만** 준다(C:S-14). 이 마이그레이션은 GRANT 를 쓰지 않는다:
--   권한의 진실은 한 파일(`grants.sql`)에만 있어야 나중에 「어디서 준 권한인가」를
--   찾아 헤매지 않는다(4-C 마이그레이션과 같은 판단).
--
-- 하는 일 (표·열 **삭제 0**, 데이터 손실 0, 여러 번 돌려도 같다)
--   1. `pages.status`      — 다섯 값 또는 NULL. **NULL 이 기본**이다: 모든 페이지가 업무는 아니다.
--   2. `pages.assignee_id` — t-box 사용자 하나 또는 NULL. `ON DELETE` 절이 없다 —
--                            t-box 는 계정을 지우지 않는다(비활성만). 지우려 하면 이 FK 가
--                            막고, 그 사실이 곧 알림이다(`spaces.owner_user_id` 와 같은 태도).
--   3. `page_property_events` — **지울 수 없는 변경 기록**(누가·언제·이전→새값).
--   4. 인덱스 둘 — 이력 읽기(page_id, at DESC) · 「내가 담당인 페이지」(assignee_id).
--
-- ★ **앱은 이 파일을 적용하기 전에 멈추지 않는다.** 4-D 코드가 들어오기 전의 런타임은
--   이 칸들을 읽지도 쓰지도 않았다. 반대로 4-D 코드는 이 칸들이 **있어야** 동작한다 —
--   그래서 순서는 「마이그레이션 → grants.sql → 코드 배포」다.
--
-- 되돌리기 (아무도 새 칸·새 표를 쓰기 전):
--   DROP TABLE community.page_property_events;
--   ALTER TABLE community.pages DROP COLUMN assignee_id, DROP COLUMN status;

\set ON_ERROR_STOP on

BEGIN;

-- ─── 1·2. 페이지의 고정 속성 둘 ───────────────────────────────
-- NULL 을 허용한 채로 붙인다 — 큰 표에서도 즉시 끝나고, 기존 행은 한 줄도 바뀌지 않는다.
ALTER TABLE community.pages
      ADD COLUMN IF NOT EXISTS status      TEXT,
      ADD COLUMN IF NOT EXISTS assignee_id BIGINT;

-- 제약은 따로 붙인다(`ADD COLUMN ... CHECK` 는 IF NOT EXISTS 와 함께 쓰면 두 번째 실행에서
-- 이름이 겹친다). 이미 있으면 아무 일도 하지 않는다.
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'pages_status_check') THEN
        ALTER TABLE community.pages
              ADD CONSTRAINT pages_status_check
              CHECK (status IS NULL OR status IN ('todo','doing','review','done','hold'));
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'pages_assignee_id_fkey') THEN
        ALTER TABLE community.pages
              ADD CONSTRAINT pages_assignee_id_fkey
              FOREIGN KEY (assignee_id) REFERENCES public.users(id);
    END IF;
END
$$;

-- ─── 3. 변경 기록 — append-only (C:S-14) ──────────────────────
--   `prop`       바뀐 칸 이름 (`status` · `assignee`)
--   `from_value` 이전 값 · `to_value` 새 값. 둘 다 **사람이 읽는 문자열**이다:
--                상태는 코드값(`todo`…), 담당자는 **계정명**. 계정 id 를 적지 않는 이유는
--                이력을 보여 줄 때 users 를 다시 읽지 않아도 되게 하려는 것이고, 지워지지
--                않는 기록이 「그때 누구였나」를 스스로 들고 있어야 하기 때문이다.
--                값이 없음(담당자 없음·상태 없음)은 NULL 이다.
--   앱 계정에 UPDATE·DELETE 가 **없다**(`db/community/grants.sql`). 한 번 남은 줄은
--   코드에 결함이 있어도, 앱 계정을 얻은 사람도 고치거나 지울 수 없다.
CREATE TABLE IF NOT EXISTS community.page_property_events (
    id         BIGSERIAL   PRIMARY KEY,
    page_id    BIGINT      NOT NULL REFERENCES community.pages(id),
    prop       TEXT        NOT NULL CHECK (prop IN ('status','assignee')),
    from_value TEXT,
    to_value   TEXT,
    by_user_id BIGINT      NOT NULL REFERENCES public.users(id),
    at         TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- ─── 4. 인덱스 ────────────────────────────────────────────────
-- 이력 창이 한 페이지의 줄을 최근 것부터 읽는다(`checklist_events_page_idx` 와 같은 모양).
CREATE INDEX IF NOT EXISTS page_property_events_page_at
    ON community.page_property_events (page_id, at DESC);

-- 「내가 담당인 페이지」 목록. 살아 있는 행만 보므로 부분 인덱스다
-- (`pages_space_live_idx`·`pages_parent_idx` 와 같은 방식).
CREATE INDEX IF NOT EXISTS pages_assignee
    ON community.pages (assignee_id) WHERE deleted_at IS NULL;

COMMIT;

-- ─── 확인 (적용한 사람이 눈으로 본다) ───
--   \d community.pages
--   \d community.page_property_events
--   SELECT count(*) FROM community.pages WHERE status IS NOT NULL;      -- 0 이어야 한다
--   SELECT indexname FROM pg_indexes
--    WHERE schemaname='community' AND indexname IN ('pages_assignee','page_property_events_page_at');
--   -- grants.sql 을 돌린 뒤: r=SELECT · a=INSERT 둘만 보여야 한다
--   SELECT relname, relacl FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
--    WHERE n.nspname='community' AND relname='page_property_events';
