-- db/migrations/2026-10-19_qf12_work.sql — Q:F-12 업무 화면의 표 한 벌 (지시서 A · 커뮤니티 ARCHITECTURE 23.2)
--
-- 요구사항: Q:F-12 WK-3 · WK-12 · WK-13 · WK-14, ../../../community/ARCHITECTURE.md 23.2, SECURITY C:R-15 · C:R-16.
--
-- 추가 전용 · 되풀이 안전. **기존 행을 지우거나 고치지 않는다** — `community.pages.assignee_id`(5-A 이전 옛 값 포함)는
-- 그대로 두고 다시 쓴다(ARCH 23.3 「옛 assignee_id 값」 — 정리 여부는 운영 건수를 본 뒤 사용자가 정한다).
-- 실행 계정: tbox_owner (표 소유자). 새 설치는 db/community/schema.sql 끝 절이 같은 모양을 만들고,
-- 앱 권한은 db/community/grants.sql 끝 절이 다시 건다(이 파일 끝 권한 절과 같은 줄).
--
--   databases.purpose        프로젝트로 쓰는 데이터베이스('project') · 사람마다 「내 할 일」('my_tasks') — NULL 은 그냥 표
--   databases.color          프로젝트 색(DESIGN 2.22 — peach·lilac·mint·sand)
--   my_tasks_projects        사람마다 「내 할 일」 하나(채우는 것은 B) — 앱 SELECT · INSERT · DELETE(지운 페이지 정리)
--   project_status_updates   프로젝트 상태 기록 — ★ 앱 SELECT · INSERT 만(지울 수 없는 기록)
--   portfolios               팀 공간의 포트폴리오 — 앱 SELECT · INSERT · UPDATE(지우기 = deleted_at, DELETE 없음)
--   portfolio_items          포트폴리오에 넣은 프로젝트 — 앱 SIUD(빼기 = DELETE, 사건은 아래 표)
--   portfolio_events         포트폴리오 변경 기록 — ★ 앱 SELECT · INSERT 만
--   notifications.kind       'assigned' 추가(누적 CHECK 의 다음 판 — 지금 정의를 읽어 OR 로 잇는다)
--
-- 되돌리기(코드만 되돌리면 된다 — 표가 남아도 옛 코드는 읽지 않는다). 표를 걷어 낼 때만, 소유자로:
--   DROP TABLE community.portfolio_events, community.portfolio_items, community.portfolios,
--              community.project_status_updates, community.my_tasks_projects;
--   ALTER TABLE community.databases DROP COLUMN purpose, DROP COLUMN color;
--   (notifications 의 'assigned' 는 남겨도 해가 없다 — 지우려면 그 kind 의 행이 0 인지 먼저 본다)

\set ON_ERROR_STOP on
BEGIN;
SET LOCAL lock_timeout = '5s';

-- ── 1. 데이터베이스의 쓰임 · 프로젝트 색 ──
ALTER TABLE community.databases ADD COLUMN IF NOT EXISTS purpose TEXT
    CONSTRAINT databases_purpose_check CHECK (purpose IN ('project','my_tasks'));
ALTER TABLE community.databases ADD COLUMN IF NOT EXISTS color TEXT
    CONSTRAINT databases_color_check CHECK (color IN ('peach','lilac','mint','sand'));

-- ── 2. 사람마다 「내 할 일」 하나 (B 가 채운다) ──
CREATE TABLE IF NOT EXISTS community.my_tasks_projects (
    user_id BIGINT PRIMARY KEY REFERENCES public.users(id),
    page_id BIGINT NOT NULL UNIQUE REFERENCES community.pages(id)
);

-- ── 3. 프로젝트 상태 기록 (지울 수 없다 — 권한으로 막는다, 트리거 없음) ──
CREATE TABLE IF NOT EXISTS community.project_status_updates (
    id         BIGSERIAL   PRIMARY KEY,
    page_id    BIGINT      NOT NULL REFERENCES community.pages(id),
    status     TEXT        NOT NULL CONSTRAINT project_status_updates_status_check
                           CHECK (status IN ('on_track','at_risk','off_track','on_hold','complete')),
    note       TEXT        NOT NULL DEFAULT '' CONSTRAINT project_status_updates_note_check
                           CHECK (char_length(note) <= 280),
    by_user_id BIGINT      NOT NULL REFERENCES public.users(id),
    at         TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS project_status_updates_page_at ON community.project_status_updates (page_id, at DESC);

-- ── 4. 포트폴리오 (팀 공간 — 공간이 team 인지는 핸들러가 CommunityAuthz 판정으로 본다) ──
CREATE TABLE IF NOT EXISTS community.portfolios (
    id         BIGSERIAL   PRIMARY KEY,
    public_id  UUID        NOT NULL UNIQUE DEFAULT gen_random_uuid(),
    space_id   BIGINT      NOT NULL REFERENCES community.spaces(id),
    name       TEXT        NOT NULL CONSTRAINT portfolios_name_check CHECK (char_length(name) BETWEEN 1 AND 80),
    created_by BIGINT      NOT NULL REFERENCES public.users(id),
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at TIMESTAMPTZ
);
CREATE UNIQUE INDEX IF NOT EXISTS portfolios_space_name ON community.portfolios (space_id, lower(name)) WHERE deleted_at IS NULL;

CREATE TABLE IF NOT EXISTS community.portfolio_items (
    portfolio_id BIGINT      NOT NULL REFERENCES community.portfolios(id),
    page_id      BIGINT      NOT NULL REFERENCES community.pages(id),
    position     INT         NOT NULL DEFAULT 0,
    added_by     BIGINT      NOT NULL REFERENCES public.users(id),
    added_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (portfolio_id, page_id)
);

CREATE TABLE IF NOT EXISTS community.portfolio_events (
    id           BIGSERIAL   PRIMARY KEY,
    portfolio_id BIGINT      NOT NULL REFERENCES community.portfolios(id),
    kind         TEXT        NOT NULL CONSTRAINT portfolio_events_kind_check
                             CHECK (kind IN ('create','rename','add','remove','delete')),
    page_id      BIGINT      REFERENCES community.pages(id),
    from_value   TEXT,
    to_value     TEXT,
    by_user_id   BIGINT      NOT NULL REFERENCES public.users(id),
    at           TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS portfolio_events_portfolio_at ON community.portfolio_events (portfolio_id, at DESC);

-- ── 5. 알림 종류 'assigned' (누적 CHECK — 지금 정의를 읽어 OR 로 잇는다. 이미 있으면 아무것도 하지 않는다) ──
DO $$
DECLARE c record;
BEGIN
    FOR c IN SELECT conname, pg_get_constraintdef(oid) AS definition FROM pg_constraint
              WHERE conrelid = 'community.notifications'::regclass AND contype = 'c'
                AND pg_get_constraintdef(oid) LIKE '%kind%'
    LOOP
        IF position('''assigned''' in c.definition) = 0 THEN
            EXECUTE format('ALTER TABLE community.notifications DROP CONSTRAINT %I', c.conname);
            EXECUTE format('ALTER TABLE community.notifications ADD CONSTRAINT %I CHECK ((%s) OR kind = ''assigned'')',
                c.conname, substring(c.definition from 8 for length(c.definition) - 8));
        END IF;
    END LOOP;
END
$$;

-- ── 6. 권한 (db/community/grants.sql 끝 절과 같은 줄) ──
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'tbox_app') THEN
        REVOKE ALL ON community.my_tasks_projects, community.project_status_updates, community.portfolios,
                      community.portfolio_items, community.portfolio_events FROM tbox_app;
        GRANT SELECT, INSERT, DELETE         ON community.my_tasks_projects      TO tbox_app;
        GRANT SELECT, INSERT                 ON community.project_status_updates TO tbox_app;
        GRANT SELECT, INSERT, UPDATE         ON community.portfolios             TO tbox_app;
        GRANT SELECT, INSERT, UPDATE, DELETE ON community.portfolio_items        TO tbox_app;
        GRANT SELECT, INSERT                 ON community.portfolio_events       TO tbox_app;
        GRANT USAGE, SELECT ON SEQUENCE community.project_status_updates_id_seq, community.portfolios_id_seq,
                                        community.portfolio_events_id_seq TO tbox_app;
    END IF;
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'tbox_backup') THEN
        REVOKE ALL ON community.my_tasks_projects, community.project_status_updates, community.portfolios,
                      community.portfolio_items, community.portfolio_events FROM tbox_backup;
        GRANT SELECT ON community.my_tasks_projects, community.project_status_updates, community.portfolios,
                        community.portfolio_items, community.portfolio_events TO tbox_backup;
        GRANT SELECT ON SEQUENCE community.project_status_updates_id_seq, community.portfolios_id_seq,
                                 community.portfolio_events_id_seq TO tbox_backup;
    END IF;
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'tbox_perm') THEN
        EXECUTE 'REVOKE ALL ON community.my_tasks_projects, community.project_status_updates, community.portfolios, community.portfolio_items, community.portfolio_events FROM tbox_perm';
    END IF;
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'tbox_push') THEN
        EXECUTE 'REVOKE ALL ON community.my_tasks_projects, community.project_status_updates, community.portfolios, community.portfolio_items, community.portfolio_events FROM tbox_push';
    END IF;
END
$$;

COMMIT;
