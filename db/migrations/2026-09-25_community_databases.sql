-- db/migrations/2026-09-25_community_databases.sql
--   커뮤니티 6-A — 데이터베이스 핵심 (C:F-27 일부, ARCH 21.16)
--
-- 요구사항: ../../../community/stage6a-database-directive.md 「데이터」 (결정 G-1~G-12 기본안, 2026-09-23)
--
-- 실행 계정: tbox_owner (표 소유자).
-- 실행 예:  PGPASSFILE=/etc/tbox-v2/owner.pgpass psql -h 127.0.0.1 -U tbox_owner -d tbox_re \
--             -v ON_ERROR_STOP=1 -f db/migrations/2026-09-25_community_databases.sql
--
-- ★ 이 파일 뒤에 `db/community/grants.sql` 을 **반드시 한 번 더** 돌린다(postgres 계정):
--     databases  SELECT·INSERT·UPDATE  (지우기는 DB 페이지를 휴지통에 — DELETE 없음)
-- ★ 5-D(`2026-09-24_community_discussion.sql`) 뒤에 돌린다.
--
-- 하는 일 (표·열 삭제 0, 여러 번 돌려도 같다)
--   1. databases        DB 한 개 = 그것을 품은 **DB 페이지** 하나(page_id UNIQUE). 스키마(속성 목록)·뷰(6-B)
--   2. pages 열 둘       database_id(이 페이지가 **행**이면 그 DB) · properties(행의 속성 값, 객체)
--                        규칙: 「DB 페이지 바로 아래 페이지 = 행」 — 앱이 만들기·옮기기·되살리기에서 맞춘다
--   3. 처리 이력 prop    `db:<key>` 모양을 더한다(속성 값 바꾼 기록 — 지울 수 없음)
--
-- 되돌리기 (아무도 쓰기 전): ALTER TABLE community.pages DROP COLUMN properties, DROP COLUMN database_id;
--                              DROP TABLE community.databases; (prop CHECK 는 앞 정의로)

\set ON_ERROR_STOP on

BEGIN;

-- ─── 1. databases ─────────────────────────────────────────────
--   schema = [{key, name, type, options?}] — 모양은 앱(`DbProps`)이 먼저 보고, 여기서는 배열인지만 본다.
--   views  = 6-B 에서 쓴다(지금은 빈 배열).
CREATE TABLE IF NOT EXISTS community.databases (
    id         BIGSERIAL   PRIMARY KEY,
    public_id  UUID        NOT NULL UNIQUE DEFAULT gen_random_uuid(),
    page_id    BIGINT      NOT NULL UNIQUE REFERENCES community.pages(id),
    schema     JSONB       NOT NULL DEFAULT '[]'::jsonb,
    views      JSONB       NOT NULL DEFAULT '[]'::jsonb,
    created_by BIGINT      NOT NULL REFERENCES public.users(id),
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT databases_schema_is_array CHECK (jsonb_typeof(schema) = 'array'),
    CONSTRAINT databases_views_is_array  CHECK (jsonb_typeof(views) = 'array')
);

-- ─── 2. pages 열 ──────────────────────────────────────────────
ALTER TABLE community.pages ADD COLUMN IF NOT EXISTS database_id BIGINT REFERENCES community.databases(id);
ALTER TABLE community.pages ADD COLUMN IF NOT EXISTS properties  JSONB;
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'pages_properties_shape'
                      AND conrelid = 'community.pages'::regclass) THEN
        ALTER TABLE community.pages ADD CONSTRAINT pages_properties_shape
            CHECK ((database_id IS NULL AND properties IS NULL)
                OR (database_id IS NOT NULL AND properties IS NOT NULL AND jsonb_typeof(properties) = 'object'));
    END IF;
END
$$;
CREATE INDEX IF NOT EXISTS pages_database_idx ON community.pages (database_id, position, id)
    WHERE deleted_at IS NULL AND database_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS pages_properties_gin ON community.pages USING GIN (properties jsonb_path_ops)
    WHERE database_id IS NOT NULL;

-- ─── 3. 처리 이력에 `db:<key>` ────────────────────────────────
--   5-C 와 같은 방법 — `prop` 을 보는 CHECK 를 정의로 찾아 떼고 넓혀 다시 붙인다(옛 값 그대로 들어 있다).
DO $$
DECLARE c record;
BEGIN
    FOR c IN SELECT conname FROM pg_constraint
              WHERE conrelid = 'community.page_property_events'::regclass AND contype = 'c'
                AND pg_get_constraintdef(oid) LIKE '%prop%'
    LOOP
        EXECUTE format('ALTER TABLE community.page_property_events DROP CONSTRAINT %I', c.conname);
    END LOOP;
END
$$;
ALTER TABLE community.page_property_events ADD CONSTRAINT page_property_events_prop_check
    CHECK (prop IN ('status','assignee','publish','hide','resolved') OR prop ~ '^db:[a-z0-9_]{1,30}$');

COMMIT;
