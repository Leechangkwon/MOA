-- db/community/schema.sql — 커뮤니티 모듈 스키마 `community` (3단계)
--
-- 요구사항: 커뮤니티 `C:F-15`·`C:F-17`·`C:S-13`·`C:S-14`,
--           ../../../community/ARCHITECTURE.md 13.1(표)·21.2(한 앱 안에서의 권한)·21.3(3단계 범위)
--
-- 실행 계정: tbox_owner (표 소유자). 앱 계정 tbox_app 은 db/community/grants.sql 로 최소 권한만 받는다.
-- 실행 예:  PGPASSWORD=... psql -h 127.0.0.1 -U tbox_owner -d tbox_re -v ON_ERROR_STOP=1 -f db/community/schema.sql
--
-- 스키마 `community` 는 **이미 있고 비어 있다**(소유 tbox_owner). 이 파일은 그 안에만 표를 만든다.
-- `legacy_community`(v0.1~0.2 게시판의 잔재)는 한 글자도 건드리지 않는다.
--
-- 3단계에 **만들지 않는 것** (4단계 이후 마이그레이션이 더한다)
--   · spaces: `board_kind`
--   · pages : `cover_ref` · `published_space_id` · `published_at` · `database_id` · `properties`
--             (4-D 에 `status`·`assignee_id` 가 더해졌다 — 새 설치는 이 파일 하나로 끝나고,
--              이미 있는 DB 는 db/migrations/2026-09-23_community_page_properties.sql 이 같은 모양으로 만든다)
--   · 표    : `boards`(→ spaces 로 합쳤다) · `tags` · `page_tags` · `space_tags` · `page_reads`
--             (`palettes` 는 7-D2 에 이 파일 끝에 더해졌다 — 이미 있는 DB 는 db/migrations/2026-10-07_community_palettes.sql)
--
-- 멱등하지 않다(초기 구축 전용). gen_random_uuid() 는 PostgreSQL 13+ 내장 함수다 —
-- pgcrypto 확장을 설치하지 않는다(공급망 최소화, t-box PRD S-10-C 와 같은 판단).

\set ON_ERROR_STOP on

SET search_path = community, public;

-- ─────────────────────────────────────────────────────────────
-- spaces — 페이지가 담기는 「공간」 (ARCH 13.1)
--
--   kind='personal' 개인 업무 공간 — 사람마다 하나(부분 UNIQUE). 3단계는 이것만 채운다.
--   kind='team'     팀 공간 · kind='board' 게시판 — 4단계 이후. CHECK 는 셋 그대로 둔다
--                   (값이 늘어날 때 제약을 고치는 것보다, 처음부터 셋을 적어 두는 편이 낫다).
--
--   `access_level` 은 t-box 파일·폴더와 **같은 4종**이다 — 4단계에서 팀 공간·게시판이
--   들어올 때 t-box 의 같은 판정 함수를 그대로 쓰기 위해서다.
--   개인 공간은 소유자 규칙으로만 판정하므로 이 칸을 `admin-only` 로 둔다(가장 좁은 값).
--
--   외부 키는 t-box 표를 가리키되 `ON DELETE` 절이 없다 — t-box 는 계정·팀을 지우지 않는다
--   (비활성만). 지우려 하면 이 FK 가 막고, 그 사실이 곧 알림이다.
-- ─────────────────────────────────────────────────────────────
CREATE TABLE community.spaces (
    id            BIGSERIAL   PRIMARY KEY,
    kind          TEXT        NOT NULL CHECK (kind IN ('personal','team','board')),
    owner_user_id BIGINT      REFERENCES public.users(id),
    team_id       BIGINT      REFERENCES public.teams(id),
    name          TEXT        NOT NULL CHECK (length(name) BETWEEN 1 AND 50),
    description   TEXT        NOT NULL DEFAULT '' CHECK (length(description) <= 200),
    access_level  TEXT        NOT NULL CHECK (access_level IN ('public','internal','team','admin-only')),
    notice        BOOLEAN     NOT NULL DEFAULT false,
    -- 5-C 게시판 종류(notice·discussion·qa). 게시판만 값이 있고 `notice` 와 짝이다.
    -- 카페 C1(ARCH 25.2) — 'anonymous'(익명게시판) 더함 · 이름은 2026-10-14_community_cafe.sql 과 같은 spaces_board_kind_check.
    board_kind    TEXT        CONSTRAINT spaces_board_kind_check
                              CHECK (board_kind IS NULL OR board_kind IN ('notice','discussion','qa','anonymous')),
    -- 카페 C1(ARCH 25.2) — 게시판 자리: 업무('work') · 카페('cafe'). 게시판만 카페가 될 수 있다.
    area          TEXT        NOT NULL DEFAULT 'work' CONSTRAINT spaces_area_check CHECK (area IN ('work','cafe')),
    is_active     BOOLEAN     NOT NULL DEFAULT true,
    created_by    BIGINT      REFERENCES public.users(id),
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT spaces_personal_needs_owner CHECK (kind <> 'personal' OR owner_user_id IS NOT NULL),
    CONSTRAINT spaces_team_needs_team      CHECK (kind <> 'team'     OR team_id       IS NOT NULL),
    -- 5-C (db/migrations/2026-09-24_community_boards.sql 와 같은 모양)
    CONSTRAINT spaces_board_shape CHECK ((kind =  'board' AND board_kind IS NOT NULL AND notice = (board_kind = 'notice'))
                                      OR (kind <> 'board' AND board_kind IS NULL)),
    CONSTRAINT spaces_board_team  CHECK (kind <> 'board' OR access_level <> 'team' OR team_id IS NOT NULL),
    -- 카페 C1(ARCH 25.2 — 2026-10-14_community_cafe.sql 과 같은 이름)
    CONSTRAINT spaces_area_board      CHECK (kind = 'board' OR area = 'work'),
    CONSTRAINT spaces_board_anon_cafe CHECK (board_kind IS DISTINCT FROM 'anonymous' OR area = 'cafe')
);

-- 사람마다 개인 공간은 **하나**다. `GET /api/me/space` 가 없으면 만드는데, 같은 사람이 두 창을
-- 동시에 열어도 두 벌이 생기지 않게 하는 것은 앱의 순서가 아니라 이 인덱스다.
CREATE UNIQUE INDEX spaces_personal_owner_uidx ON community.spaces (owner_user_id) WHERE kind = 'personal';
CREATE UNIQUE INDEX spaces_team_uidx           ON community.spaces (team_id)       WHERE kind = 'team';
CREATE UNIQUE INDEX spaces_board_name_uidx     ON community.spaces (lower(name))   WHERE kind = 'board';

-- ─────────────────────────────────────────────────────────────
-- pages — 페이지 하나 = 블록 JSON 하나 (ARCH 12.1 · 13.1)
--
--   doc         본문. **블록 JSON 만** 들어간다(HTML 0). 모양은 src/community/Doc.php 가 본다.
--   doc_version 낙관적 잠금. 저장할 때마다 1 씩 오르고, 클라이언트가 보낸 값과 다르면 409 다.
--   plain_text / search_tsv / block_count — `doc` 에서 뽑은 **파생값**이다. 언제든 다시 만들 수 있다.
--   public_id   URL 에 나오는 id(UUID). 순차 정수를 주소로 쓰지 않는다(열거 방지, t-box S-09-5).
--   visibility  CHECK 는 넷 그대로 두되 **3단계 코드는 `private` 만 쓴다**(공개 범위는 4단계).
--   deleted_at  논리 삭제. 지운 페이지는 목록·조회에서 빠지고 30일 뒤 배치가 지운다(7단계).
-- ─────────────────────────────────────────────────────────────
CREATE TABLE community.pages (
    id             BIGSERIAL   PRIMARY KEY,
    public_id      UUID        NOT NULL UNIQUE DEFAULT gen_random_uuid(),
    space_id       BIGINT      NOT NULL REFERENCES community.spaces(id),
    parent_page_id BIGINT      REFERENCES community.pages(id),
    position       INT         NOT NULL DEFAULT 0,
    title          TEXT        NOT NULL CHECK (length(title) BETWEEN 1 AND 200),
    icon           TEXT        CHECK (icon IS NULL OR length(icon) <= 8),
    font           TEXT        NOT NULL DEFAULT 'default' CHECK (font IN ('default','serif','mono')),
    small_text     BOOLEAN     NOT NULL DEFAULT false,
    full_width     BOOLEAN     NOT NULL DEFAULT false,
    visibility     TEXT        NOT NULL DEFAULT 'private'
                               CHECK (visibility IN ('private','team','internal','published')),
    doc            JSONB       NOT NULL,
    doc_version    INT         NOT NULL DEFAULT 1 CHECK (doc_version >= 1),
    plain_text     TEXT        NOT NULL DEFAULT '',
    search_tsv     TSVECTOR,
    block_count    INT         NOT NULL DEFAULT 0,
    author_id      BIGINT      NOT NULL REFERENCES public.users(id),
    last_edited_by BIGINT      NOT NULL REFERENCES public.users(id),
    created_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at     TIMESTAMPTZ,
    deleted_by     BIGINT      REFERENCES public.users(id),
    purge_after    TIMESTAMPTZ,
    view_count     INT         NOT NULL DEFAULT 0,
    -- 4-D 고정 속성 둘 (C:F-37). 둘 다 **NULL 이 기본**이다 — 모든 페이지가 업무는 아니다.
    status         TEXT,
    assignee_id    BIGINT,
    -- 5-C 게시 · 숨김 · 해결됨 (db/migrations/2026-09-24_community_boards.sql)
    published_board_id BIGINT REFERENCES community.spaces(id),
    published_from TEXT CHECK (published_from IS NULL OR published_from IN ('private','team','internal')),
    published_at   TIMESTAMPTZ,
    published_by   BIGINT REFERENCES public.users(id),
    hidden_at      TIMESTAMPTZ,
    hidden_by      BIGINT REFERENCES public.users(id),
    resolved       BOOLEAN     NOT NULL DEFAULT false,
    CONSTRAINT pages_published_shape CHECK ((visibility =  'published' AND published_board_id IS NOT NULL
                                                                       AND published_from IS NOT NULL AND published_at IS NOT NULL)
                                         OR (visibility <> 'published' AND published_board_id IS NULL AND published_from IS NULL)),
    CONSTRAINT pages_hidden_shape CHECK ((hidden_at IS NULL) = (hidden_by IS NULL)),
    CONSTRAINT pages_doc_is_object CHECK (jsonb_typeof(doc) = 'object'),
    CONSTRAINT pages_status_check  CHECK (status IS NULL OR status IN ('todo','doing','review','done','hold')),
    CONSTRAINT pages_assignee_id_fkey FOREIGN KEY (assignee_id) REFERENCES public.users(id)
);

CREATE INDEX pages_space_live_idx  ON community.pages (space_id, position, id) WHERE deleted_at IS NULL;
CREATE INDEX pages_parent_idx      ON community.pages (parent_page_id)         WHERE deleted_at IS NULL;
CREATE INDEX pages_search_tsv_idx  ON community.pages USING GIN (search_tsv);
-- 「내가 담당인 페이지」 목록 (4-D)
CREATE INDEX pages_assignee        ON community.pages (assignee_id)            WHERE deleted_at IS NULL;

-- ─────────────────────────────────────────────────────────────
-- page_versions — 저장마다 남는 스냅샷 (C:S-14 「기록 불변」)
--   앱 계정에 UPDATE·DELETE 가 **없다**. 한 번 남은 판은 앱이 고칠 수 없다.
-- ─────────────────────────────────────────────────────────────
CREATE TABLE community.page_versions (
    id        BIGSERIAL   PRIMARY KEY,
    page_id   BIGINT      NOT NULL REFERENCES community.pages(id),
    version   INT         NOT NULL,
    title     TEXT        NOT NULL,
    doc       JSONB       NOT NULL,
    edited_by BIGINT      NOT NULL REFERENCES public.users(id),
    edited_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    summary   TEXT,
    CONSTRAINT page_versions_unique UNIQUE (page_id, version)
);

-- ─────────────────────────────────────────────────────────────
-- page_blocks · page_links — `doc` 에서 뽑은 파생 표 (ARCH 12.1)
--   언제든 지우고 다시 만들 수 있다. 그래서 앱 계정에 DELETE 가 있다.
-- ─────────────────────────────────────────────────────────────
CREATE TABLE community.page_blocks (
    page_id    BIGINT NOT NULL REFERENCES community.pages(id),
    block_id   UUID   NOT NULL,
    block_type TEXT   NOT NULL,
    ord        INT    NOT NULL,
    excerpt    TEXT   NOT NULL DEFAULT '',
    PRIMARY KEY (page_id, block_id)
);
CREATE INDEX page_blocks_ord_idx ON community.page_blocks (page_id, ord);

CREATE TABLE community.page_links (
    from_page_id  BIGINT NOT NULL REFERENCES community.pages(id),
    from_block_id UUID   NOT NULL,
    to_page_id    UUID   NOT NULL,
    PRIMARY KEY (from_page_id, from_block_id, to_page_id)
);
CREATE INDEX page_links_to_idx ON community.page_links (to_page_id);

-- ─────────────────────────────────────────────────────────────
-- checklist_events — 체크 상자를 켜고 끈 기록 (C:F-20, C:S-14)
--   노션에는 없는 것이고, 지울 수 없다(앱 계정 SELECT·INSERT 만).
-- ─────────────────────────────────────────────────────────────
CREATE TABLE community.checklist_events (
    id         BIGSERIAL   PRIMARY KEY,
    page_id    BIGINT      NOT NULL REFERENCES community.pages(id),
    block_id   UUID        NOT NULL,
    checked    BOOLEAN     NOT NULL,
    by_user_id BIGINT      NOT NULL REFERENCES public.users(id),
    at         TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX checklist_events_page_idx ON community.checklist_events (page_id, at DESC);

-- ─────────────────────────────────────────────────────────────
-- page_property_events — 상태·담당자를 바꾼 기록 (4-D, C:F-37, C:S-14)
--   `from_value`·`to_value` 는 사람이 읽는 문자열이다 — 상태는 코드값(`todo`…),
--   담당자는 **계정명**. 「값 없음」은 NULL 이다.
--   지울 수 없다(앱 계정 SELECT·INSERT 만) — `checklist_events` 와 같은 취급.
-- ─────────────────────────────────────────────────────────────
CREATE TABLE community.page_property_events (
    id         BIGSERIAL   PRIMARY KEY,
    page_id    BIGINT      NOT NULL REFERENCES community.pages(id),
    prop       TEXT        NOT NULL CONSTRAINT page_property_events_prop_check
                           CHECK (prop IN ('status','assignee','publish','hide','resolved') OR prop ~ '^db:[a-z0-9_]{1,30}$'),
    from_value TEXT,
    to_value   TEXT,
    by_user_id BIGINT      NOT NULL REFERENCES public.users(id),
    at         TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX page_property_events_page_at ON community.page_property_events (page_id, at DESC);

-- ─────────────────────────────────────────────────────────────
-- page_shares — 사람 지정 공유 (5-A, PRD NG-8 해제)
--   페이지 하나를 한 사람에게 `view`(보기) 또는 `edit`(편집)으로 준다. **하위 페이지에도
--   미친다** — 판정이 조상을 따라 오른다(`community_share_rank_sql`). 비활성 계정의 줄은
--   효력 0 이다. 바뀌는 값이라 앱 계정에 SIUD 가 있고, 바뀐 일은 감사가 남긴다.
-- ─────────────────────────────────────────────────────────────
CREATE TABLE community.page_shares (
    id         BIGSERIAL   PRIMARY KEY,
    page_id    BIGINT      NOT NULL REFERENCES community.pages(id),
    user_id    BIGINT      NOT NULL REFERENCES public.users(id),
    level      TEXT        NOT NULL CHECK (level IN ('view','edit')),
    granted_by BIGINT      NOT NULL REFERENCES public.users(id),
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (page_id, user_id)
);
CREATE INDEX page_shares_user ON community.page_shares (user_id);

-- ─────────────────────────────────────────────────────────────
-- handovers — 인계 기록 (5-A, C:F-28, C:S-14)
--   페이지(와 하위)가 새 주인의 개인 공간으로 옮겨 간 일. 지울 수 없다(앱 계정 SELECT·INSERT 만).
--   `kept_level` — 옛 주인에게 남긴 권한(`view`·`edit`), 없음이면 NULL.
-- ─────────────────────────────────────────────────────────────
CREATE TABLE community.handovers (
    id           BIGSERIAL   PRIMARY KEY,
    root_page_id BIGINT      NOT NULL REFERENCES community.pages(id),
    from_user_id BIGINT      NOT NULL REFERENCES public.users(id),
    to_user_id   BIGINT      NOT NULL REFERENCES public.users(id),
    by_user_id   BIGINT      NOT NULL REFERENCES public.users(id),
    page_count   INT         NOT NULL,
    kept_level   TEXT        CHECK (kept_level IN ('view','edit')),
    at           TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX handovers_root_at ON community.handovers (root_page_id, at DESC);

-- ─────────────────────────────────────────────────────────────
-- page_edit_sessions — 「지금 편집 중」 표시 (30초 heartbeat)
--   값이 사라져도 잃는 것이 없는 표라 앱 계정에 DELETE 가 있다.
-- ─────────────────────────────────────────────────────────────
CREATE TABLE community.page_edit_sessions (
    page_id      BIGINT      NOT NULL REFERENCES community.pages(id),
    user_id      BIGINT      NOT NULL REFERENCES public.users(id),
    last_seen_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (page_id, user_id)
);

-- ─────────────────────────────────────────────────────────────
-- page_tags · page_views — 태그 · 조회 (5-C, db/migrations/2026-09-24_community_boards.sql)
--   태그는 바뀌는 값(앱 SIUD), 조회는 한 사람 하루 한 줄(앱 SELECT·INSERT 만).
-- ─────────────────────────────────────────────────────────────
CREATE TABLE community.page_tags (
    page_id    BIGINT      NOT NULL REFERENCES community.pages(id),
    tag        TEXT        NOT NULL CHECK (length(tag) BETWEEN 1 AND 30 AND tag = btrim(tag)),
    tag_norm   TEXT        NOT NULL,
    created_by BIGINT      NOT NULL REFERENCES public.users(id),
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (page_id, tag_norm),
    CONSTRAINT page_tags_norm_check CHECK (tag_norm = lower(tag))
);
CREATE INDEX page_tags_norm_idx ON community.page_tags (tag_norm);

CREATE TABLE community.page_views (
    page_id BIGINT NOT NULL REFERENCES community.pages(id),
    user_id BIGINT NOT NULL REFERENCES public.users(id),
    day     DATE   NOT NULL DEFAULT current_date,
    PRIMARY KEY (page_id, user_id, day)
);
CREATE INDEX pages_published_board_idx ON community.pages (published_board_id)
    WHERE deleted_at IS NULL AND published_board_id IS NOT NULL;

-- ─────────────────────────────────────────────────────────────
-- 5-D 댓글 · 반응 · 알림 · 공지 읽음 (db/migrations/2026-09-24_community_discussion.sql 과 같은 모양)
-- ─────────────────────────────────────────────────────────────
CREATE TABLE community.comments (
    id                BIGSERIAL   PRIMARY KEY,
    public_id         UUID        NOT NULL UNIQUE DEFAULT gen_random_uuid(),
    page_id           BIGINT      NOT NULL REFERENCES community.pages(id),
    -- 답글은 **1단**: 부모는 부모가 없는 댓글이어야 한다(앱이 보고, 아래 트리거 없이 판정에서 400).
    parent_comment_id BIGINT      REFERENCES community.comments(id),
    -- 블록 앵커: 그 블록 id(본문 JSON 의 블록) + 고른 문구 인용(≤300자, 평문)
    block_id          UUID,
    quote             TEXT        CHECK (quote IS NULL OR length(quote) BETWEEN 1 AND 300),
    body              TEXT        NOT NULL CHECK (length(body) BETWEEN 1 AND 2000),
    author_id         BIGINT      NOT NULL REFERENCES public.users(id),
    created_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
    resolved_at       TIMESTAMPTZ,
    resolved_by       BIGINT      REFERENCES public.users(id),
    hidden_at         TIMESTAMPTZ,
    hidden_by         BIGINT      REFERENCES public.users(id),
    deleted_at        TIMESTAMPTZ,
    deleted_by        BIGINT      REFERENCES public.users(id),
    -- 검색 색인(Q:F-2) — `body` 에서 뽑은 **파생값**(SearchText 한글 음절 겹침 + 단어). 앱이 댓글을 쓰고 고칠 때 채운다.
    -- 이미 있는 DB 는 db/migrations/2026-10-01_qf2_search.sql 이 같은 모양으로 더한다(새 설치는 이 파일 하나로 끝난다)
    search_tsv        TSVECTOR,
    CONSTRAINT comments_resolved_shape CHECK ((resolved_at IS NULL) = (resolved_by IS NULL)),
    CONSTRAINT comments_hidden_shape   CHECK ((hidden_at IS NULL) = (hidden_by IS NULL)),
    CONSTRAINT comments_deleted_shape  CHECK ((deleted_at IS NULL) = (deleted_by IS NULL)),
    CONSTRAINT comments_not_self_parent CHECK (parent_comment_id IS NULL OR parent_comment_id <> id)
);
CREATE INDEX comments_page_idx   ON community.comments (page_id, created_at) WHERE deleted_at IS NULL;
CREATE INDEX comments_parent_idx ON community.comments (parent_comment_id) WHERE parent_comment_id IS NOT NULL;
CREATE INDEX comments_search_tsv_idx ON community.comments USING GIN (search_tsv);

CREATE TABLE community.comment_reactions (
    comment_id BIGINT      NOT NULL REFERENCES community.comments(id),
    user_id    BIGINT      NOT NULL REFERENCES public.users(id),
    kind       TEXT        NOT NULL CHECK (kind IN ('helpful')),
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (comment_id, user_id, kind)
);

CREATE TABLE community.notifications (
    id         BIGSERIAL   PRIMARY KEY,
    user_id    BIGINT      NOT NULL REFERENCES public.users(id),
    -- G-0 (t-box PRD F-73) — 'access_request'(주인에게: 누가 권한을 요청했다) · 'access_decided'(요청자에게: 허락·거절).
    --   이 둘은 페이지가 없고 access_request_id 로 t-box 요청 줄을 가리킨다(migrations/2026-10-10_access_grants.sql).
    kind       TEXT        NOT NULL CONSTRAINT notifications_kind_check
                           CHECK (kind IN ('mention','reply','comment_on_my_page','handover','access_request','access_decided')),
    page_id    BIGINT      REFERENCES community.pages(id),
    comment_id BIGINT      REFERENCES community.comments(id),
    actor_id   BIGINT      REFERENCES public.users(id),
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    read_at    TIMESTAMPTZ,
    access_request_id BIGINT REFERENCES public.access_requests(id) ON DELETE SET NULL
);
CREATE INDEX notifications_access_request_idx ON community.notifications (access_request_id)
    WHERE access_request_id IS NOT NULL;
CREATE INDEX notifications_user_idx ON community.notifications (user_id, created_at DESC);
CREATE INDEX notifications_unread_idx ON community.notifications (user_id) WHERE read_at IS NULL;

CREATE TABLE community.page_reads (
    page_id       BIGINT      NOT NULL REFERENCES community.pages(id),
    user_id       BIGINT      NOT NULL REFERENCES public.users(id),
    first_read_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    last_read_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (page_id, user_id)
);

-- ─────────────────────────────────────────────────────────────
-- 6-A 데이터베이스 (db/migrations/2026-09-25_community_databases.sql 과 같은 모양)
--   DB 한 개 = DB 페이지 하나. 「DB 페이지 바로 아래 페이지 = 행」 — 행은 database_id·properties 를 가진다.
-- ─────────────────────────────────────────────────────────────
CREATE TABLE community.databases (
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
ALTER TABLE community.pages ADD COLUMN database_id BIGINT REFERENCES community.databases(id);
ALTER TABLE community.pages ADD COLUMN properties  JSONB;
ALTER TABLE community.pages ADD CONSTRAINT pages_properties_shape
    CHECK ((database_id IS NULL AND properties IS NULL)
        OR (database_id IS NOT NULL AND properties IS NOT NULL AND jsonb_typeof(properties) = 'object'));
CREATE INDEX pages_database_idx ON community.pages (database_id, position, id)
    WHERE deleted_at IS NULL AND database_id IS NOT NULL;
CREATE INDEX pages_properties_gin ON community.pages USING GIN (properties jsonb_path_ops)
    WHERE database_id IS NOT NULL;

-- 7-A 휴지통 영구 삭제 표시 (db/migrations/2026-09-26_community_purge.sql 과 같은 열 — 정리 함수 community.purge_trash 는 그 파일이 만든다)
ALTER TABLE community.pages ADD COLUMN purged_at TIMESTAMPTZ;
CREATE INDEX pages_purge_due_idx ON community.pages (purge_after) WHERE deleted_at IS NOT NULL AND purged_at IS NULL;

-- 7-B 템플릿 (db/migrations/2026-09-27_community_templates.sql 과 같은 모양 — 사이트 기본 셋 시드는 그 파일이 넣는다)
CREATE TABLE community.templates (
    id            BIGSERIAL   PRIMARY KEY,
    public_id     UUID        NOT NULL UNIQUE DEFAULT gen_random_uuid(),
    scope         TEXT        NOT NULL,
    owner_user_id BIGINT      REFERENCES public.users(id),
    team_id       BIGINT      REFERENCES public.teams(id),
    database_id   BIGINT      REFERENCES community.databases(id),
    name          TEXT        NOT NULL,
    title         TEXT        NOT NULL,
    icon          TEXT,
    doc           JSONB       NOT NULL,
    properties    JSONB       NOT NULL DEFAULT '{}'::jsonb,
    seed_key      TEXT        UNIQUE,
    created_by    BIGINT      REFERENCES public.users(id),
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at    TIMESTAMPTZ,
    deleted_by    BIGINT      REFERENCES public.users(id),
    CONSTRAINT templates_scope_check CHECK (scope IN ('personal', 'team', 'site', 'database')),
    -- 범위마다 채우는 칸이 하나다 — 둘 이상 채워진 줄(범위가 모호한 줄)은 들어오지 못한다
    CONSTRAINT templates_scope_shape CHECK (
           (scope = 'personal' AND owner_user_id IS NOT NULL AND team_id IS NULL AND database_id IS NULL)
        OR (scope = 'team'     AND team_id IS NOT NULL AND owner_user_id IS NULL AND database_id IS NULL)
        OR (scope = 'site'     AND owner_user_id IS NULL AND team_id IS NULL AND database_id IS NULL)
        OR (scope = 'database' AND database_id IS NOT NULL AND owner_user_id IS NULL AND team_id IS NULL)),
    CONSTRAINT templates_name_len  CHECK (char_length(name) BETWEEN 1 AND 60),
    CONSTRAINT templates_title_len CHECK (char_length(title) BETWEEN 1 AND 200),
    CONSTRAINT templates_icon_len  CHECK (icon IS NULL OR char_length(icon) BETWEEN 1 AND 8),
    CONSTRAINT templates_doc_shape CHECK (jsonb_typeof(doc) = 'object' AND jsonb_typeof(doc -> 'blocks') = 'array'),
    -- jsonb 의 글자 모양은 `": "`·`", "` 로 공백이 붙어 앱의 1MB(빈칸 없는 JSON)보다 크다 — 앱이 먼저 1MB 로 400 을 주고, 여기는 두 배 여유
    CONSTRAINT templates_doc_size  CHECK (octet_length(doc::text) <= 2097152),
    CONSTRAINT templates_props_shape CHECK (jsonb_typeof(properties) = 'object'
                                            AND (scope = 'database' OR properties = '{}'::jsonb)),
    -- 시드만 만든 사람이 없다(사이트 기본). 사람이 만든 것은 누가 만들었는지 늘 남는다
    CONSTRAINT templates_creator CHECK (created_by IS NOT NULL OR (scope = 'site' AND seed_key IS NOT NULL)),
    CONSTRAINT templates_deleted_pair CHECK ((deleted_at IS NULL) = (deleted_by IS NULL))
);

CREATE INDEX templates_personal_idx ON community.templates (owner_user_id, created_at)
    WHERE deleted_at IS NULL AND scope = 'personal';
CREATE INDEX templates_team_idx ON community.templates (team_id, created_at)
    WHERE deleted_at IS NULL AND scope = 'team';
CREATE INDEX templates_database_idx ON community.templates (database_id, created_at)
    WHERE deleted_at IS NULL AND scope = 'database';

-- 7-C 임베드 허용 목록 (db/migrations/2026-09-28_community_embeds.sql 과 같은 모양)
CREATE TABLE community.embed_domains (
    id         BIGSERIAL   PRIMARY KEY,
    host       TEXT        NOT NULL,
    added_by   BIGINT      NOT NULL REFERENCES public.users(id),
    added_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
    removed_at TIMESTAMPTZ,
    removed_by BIGINT      REFERENCES public.users(id),
    CONSTRAINT embed_domains_host_shape CHECK (
        char_length(host) BETWEEN 4 AND 253
        AND host ~ '^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]([a-z0-9-]{0,61}[a-z0-9])?$'),
    CONSTRAINT embed_domains_removed_pair CHECK ((removed_at IS NULL) = (removed_by IS NULL))
);
-- 살아 있는 줄에서 호스트는 하나 — 뺐다가 다시 넣으면 새 줄(누가 언제 넣고 뺐는지가 줄마다 남는다)
CREATE UNIQUE INDEX embed_domains_live_host ON community.embed_domains (host) WHERE removed_at IS NULL;

-- 즐겨찾기 · 최근 (개발 대기열 6 · Q:F-3a 라운드 A — db/migrations/2026-10-03_qf3a_favorites_recent.sql 과 같은 모양)
--   page_favorites — 사람당 200 · 끌어서 순서(position). 뒤에 들어올 「모음」(F-52)의 기본 모음으로 옮길 수 있게 모양을 맞춘다.
--   page_recent    — 읽기 판정을 지난 뒤 5분에 한 번 · 사람당 50줄. **기록이 아니라 화면 편의**다(결정 M-3 — 앱 DELETE 있음).
--   목록은 둘 다 검색과 같은 조건절(`community_search_pages_where` — 관리자 특권 갈래 끔) 안에서만 나간다.
CREATE TABLE community.page_favorites (
    user_id    BIGINT      NOT NULL REFERENCES public.users(id),
    page_id    BIGINT      NOT NULL REFERENCES community.pages(id) ON DELETE CASCADE,
    position   INTEGER     NOT NULL DEFAULT 0 CHECK (position BETWEEN 0 AND 100000),
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (user_id, page_id)
);
CREATE INDEX page_favorites_page_idx ON community.page_favorites (page_id);
CREATE TABLE community.page_recent (
    user_id        BIGINT      NOT NULL REFERENCES public.users(id),
    page_id        BIGINT      NOT NULL REFERENCES community.pages(id) ON DELETE CASCADE,
    last_viewed_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (user_id, page_id)
);
CREATE INDEX page_recent_user_time_idx ON community.page_recent (user_id, last_viewed_at DESC);
CREATE INDEX page_recent_page_idx ON community.page_recent (page_id);

-- 7-D2 관리자 팔레트 (db/migrations/2026-10-07_community_palettes.sql 과 같은 모양 — 결정 L-2 · 지시서 1.2)
--   이름 하나에 라이트·다크 값 둘(배경값은 앱이 계산 — 저장하지 않는다). 줄은 남고 빼기는 removed_at · 살아 있는 이름은 하나.
--   예약 이름(기본 9색 · none)과 끝 `-bg` 금지는 앱만 막는다(이름 목록이 두 곳에 있지 않게).
CREATE TABLE community.palettes (
    id         BIGSERIAL   PRIMARY KEY,
    name       TEXT        NOT NULL,
    light      TEXT        NOT NULL,
    dark       TEXT        NOT NULL,
    added_by   BIGINT      NOT NULL REFERENCES public.users(id),
    added_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_by BIGINT      REFERENCES public.users(id),
    updated_at TIMESTAMPTZ,
    removed_by BIGINT      REFERENCES public.users(id),
    removed_at TIMESTAMPTZ,
    CONSTRAINT palettes_name_shape  CHECK (name ~ '^[a-z0-9-]{1,20}$'),
    CONSTRAINT palettes_light_shape CHECK (light ~ '^#[0-9a-f]{6}$'),
    CONSTRAINT palettes_dark_shape  CHECK (dark ~ '^#[0-9a-f]{6}$'),
    CONSTRAINT palettes_removed_pair CHECK ((removed_at IS NULL) = (removed_by IS NULL)),
    CONSTRAINT palettes_updated_pair CHECK ((updated_at IS NULL) = (updated_by IS NULL))
);
CREATE UNIQUE INDEX palettes_live_name ON community.palettes (name) WHERE removed_at IS NULL;

-- 8-A fresh-install trust metadata (same as the additive migration).
-- C:F-38/39, stage8-trust-handover-directive: additive, idempotent.
-- Rollback: retain columns/history, revert application files first. Do not erase verification events.
ALTER TABLE community.pages
 ADD COLUMN IF NOT EXISTS verified_by BIGINT REFERENCES public.users(id),
 ADD COLUMN IF NOT EXISTS verified_at TIMESTAMPTZ,
 ADD COLUMN IF NOT EXISTS verify_expires_at TIMESTAMPTZ,
 ADD COLUMN IF NOT EXISTS page_kind TEXT NOT NULL DEFAULT 'page';
DO $$ DECLARE r record; BEGIN
 IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conrelid='community.pages'::regclass AND conname='pages_verify_shape') THEN
  ALTER TABLE community.pages ADD CONSTRAINT pages_verify_shape CHECK
   ((verified_by IS NULL) = (verified_at IS NULL) AND
    (verify_expires_at IS NULL OR (verified_at IS NOT NULL AND verify_expires_at > verified_at)));
 END IF;
 IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conrelid='community.pages'::regclass AND conname='pages_page_kind_check') THEN
  ALTER TABLE community.pages ADD CONSTRAINT pages_page_kind_check CHECK(page_kind IN ('page','procedure'));
 END IF;
 FOR r IN SELECT conname FROM pg_constraint WHERE conrelid='community.page_property_events'::regclass AND contype='c' AND pg_get_constraintdef(oid) LIKE '%prop%' LOOP
  EXECUTE format('ALTER TABLE community.page_property_events DROP CONSTRAINT %I',r.conname);
 END LOOP;
 ALTER TABLE community.page_property_events ADD CONSTRAINT page_property_events_prop_check
  CHECK(prop IN ('status','assignee','publish','hide','resolved','verified','kind') OR prop ~ '^db:[a-z0-9_]{1,30}$');
 FOR r IN SELECT conname FROM pg_constraint WHERE conrelid='community.notifications'::regclass AND contype='c' AND pg_get_constraintdef(oid) LIKE '%kind%' LOOP
  EXECUTE format('ALTER TABLE community.notifications DROP CONSTRAINT %I',r.conname);
 END LOOP;
 ALTER TABLE community.notifications ADD CONSTRAINT notifications_kind_check
  CHECK(kind IN ('mention','reply','comment_on_my_page','handover','access_request','access_decided','verify_expired'));
END $$;
CREATE INDEX IF NOT EXISTS pages_verify_expiry_idx ON community.pages(verify_expires_at) WHERE verify_expires_at IS NOT NULL AND deleted_at IS NULL;
CREATE INDEX IF NOT EXISTS pages_kind_idx ON community.pages(page_kind) WHERE page_kind <> 'page';

-- 9-A fresh install.
-- Stage 9: additive/idempotent. Rollback application first; preserve rules and immutable run history.
ALTER TABLE community.pages ADD COLUMN IF NOT EXISTS due_date DATE;
ALTER TABLE community.notifications ADD COLUMN IF NOT EXISTS retracted_at TIMESTAMPTZ;
CREATE TABLE IF NOT EXISTS community.automations (
 id BIGSERIAL PRIMARY KEY, public_id UUID NOT NULL UNIQUE,
 owner_user_id BIGINT NOT NULL REFERENCES public.users(id),
 kind TEXT NOT NULL CHECK(kind IN ('recurring','on_status','due_reminder','button')),
 name TEXT NOT NULL CHECK(char_length(name) BETWEEN 1 AND 100),
 scope_page_id BIGINT REFERENCES community.pages(id), scope_space_id BIGINT REFERENCES community.spaces(id),
 trigger JSONB NOT NULL CHECK(jsonb_typeof(trigger)='object'),
 actions JSONB NOT NULL CHECK(jsonb_typeof(actions)='array' AND jsonb_array_length(actions) BETWEEN 1 AND 5),
 press_scope TEXT CHECK(press_scope IN ('owner','editors')),
 is_enabled BOOLEAN NOT NULL DEFAULT false, last_dry_run_at TIMESTAMPTZ,
 denied_streak INTEGER NOT NULL DEFAULT 0 CHECK(denied_streak>=0),
 created_at TIMESTAMPTZ NOT NULL DEFAULT now(), updated_at TIMESTAMPTZ NOT NULL DEFAULT now(), deleted_at TIMESTAMPTZ,
 CONSTRAINT automations_press_shape CHECK ((kind='button' AND press_scope IS NOT NULL) OR (kind<>'button' AND press_scope IS NULL)),
 CONSTRAINT automations_scope_shape CHECK (scope_page_id IS NULL OR scope_space_id IS NULL)
);
CREATE TABLE IF NOT EXISTS community.automation_runs (
 id BIGSERIAL PRIMARY KEY, public_id UUID NOT NULL UNIQUE,
 automation_id BIGINT NOT NULL REFERENCES community.automations(id), run_by BIGINT NOT NULL REFERENCES public.users(id),
 trigger_kind TEXT NOT NULL, slot TIMESTAMPTZ, dry_run BOOLEAN NOT NULL,
 started_at TIMESTAMPTZ NOT NULL DEFAULT now(), finished_at TIMESTAMPTZ NOT NULL DEFAULT now(),
 status TEXT NOT NULL CHECK(status IN ('ok','partial','denied','failed')),
 steps JSONB NOT NULL CHECK(jsonb_typeof(steps)='array'),
 created_page_ids BIGINT[] NOT NULL DEFAULT '{}', notification_ids BIGINT[] NOT NULL DEFAULT '{}',
 undone_at TIMESTAMPTZ, undone_by BIGINT REFERENCES public.users(id),
 CHECK((undone_at IS NULL)=(undone_by IS NULL))
);
CREATE UNIQUE INDEX IF NOT EXISTS automation_runs_slot_uidx ON community.automation_runs(automation_id,slot) WHERE NOT dry_run AND slot IS NOT NULL;
CREATE INDEX IF NOT EXISTS automation_runs_rule_idx ON community.automation_runs(automation_id,started_at DESC);
CREATE TABLE IF NOT EXISTS community.automation_queue (
 id BIGSERIAL PRIMARY KEY, automation_id BIGINT NOT NULL REFERENCES community.automations(id),
 page_id BIGINT NOT NULL REFERENCES community.pages(id), event_id BIGINT NOT NULL REFERENCES community.page_property_events(id),
 created_at TIMESTAMPTZ NOT NULL DEFAULT now(), taken_at TIMESTAMPTZ,
 UNIQUE(automation_id,event_id)
);
CREATE INDEX IF NOT EXISTS automation_queue_pending_idx ON community.automation_queue(id) WHERE taken_at IS NULL;
CREATE INDEX IF NOT EXISTS automations_enabled_idx ON community.automations(is_enabled,kind) WHERE deleted_at IS NULL;
CREATE INDEX IF NOT EXISTS pages_due_idx ON community.pages(due_date) WHERE due_date IS NOT NULL AND deleted_at IS NULL;
DO $$ DECLARE r record; BEGIN
 FOR r IN SELECT conname FROM pg_constraint WHERE conrelid='community.page_property_events'::regclass AND contype='c' AND pg_get_constraintdef(oid) LIKE '%prop%' LOOP
  EXECUTE format('ALTER TABLE community.page_property_events DROP CONSTRAINT %I',r.conname);
 END LOOP;
 ALTER TABLE community.page_property_events ADD CONSTRAINT page_property_events_prop_check
  CHECK(prop IN ('status','assignee','publish','hide','resolved','verified','kind','due') OR prop ~ '^db:[a-z0-9_]{1,30}$');
 FOR r IN SELECT conname FROM pg_constraint WHERE conrelid='community.notifications'::regclass AND contype='c' AND pg_get_constraintdef(oid) LIKE '%kind%' LOOP
  EXECUTE format('ALTER TABLE community.notifications DROP CONSTRAINT %I',r.conname);
 END LOOP;
 ALTER TABLE community.notifications ADD CONSTRAINT notifications_kind_check
  CHECK(kind IN ('mention','reply','comment_on_my_page','handover','access_request','access_decided','verify_expired','recurring_created','review_requested','due_tomorrow','automation_denied','automation_disabled'));
END $$;

-- C:F-46: proposal content is immutable to the application after insertion.
CREATE TABLE IF NOT EXISTS community.page_suggestions (
    id BIGSERIAL PRIMARY KEY,
    public_id UUID NOT NULL UNIQUE,
    page_id BIGINT NOT NULL REFERENCES community.pages(id),
    author_id BIGINT NOT NULL REFERENCES public.users(id),
    base_version INT NOT NULL CHECK (base_version > 0),
    ops JSONB NOT NULL CHECK (jsonb_typeof(ops) = 'array' AND jsonb_array_length(ops) BETWEEN 1 AND 50),
    base_blocks JSONB NOT NULL CHECK (jsonb_typeof(base_blocks) = 'object'),
    note TEXT CHECK (length(note) <= 500),
    status TEXT NOT NULL DEFAULT 'open' CHECK (status IN ('open','accepted','rejected','stale','withdrawn')),
    decided_by BIGINT REFERENCES public.users(id),
    decided_at TIMESTAMPTZ,
    decision_note TEXT CHECK (length(decision_note) <= 500),
    applied_version INT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    CHECK (octet_length(ops::text) + octet_length(base_blocks::text) <= 1048576)
);
CREATE INDEX IF NOT EXISTS page_suggestions_page_status ON community.page_suggestions(page_id,status);
CREATE INDEX IF NOT EXISTS page_suggestions_author_status ON community.page_suggestions(author_id,status);
DO $$
DECLARE item record; definition text;
BEGIN
    FOR item IN SELECT conname, pg_get_constraintdef(oid) AS def
                  FROM pg_constraint WHERE conrelid='community.notifications'::regclass
                   AND contype='c' AND pg_get_constraintdef(oid) LIKE '%kind%'
    LOOP
        definition := item.def;
        IF position('suggestion_new' in definition) = 0 THEN
            EXECUTE format('ALTER TABLE community.notifications DROP CONSTRAINT %I', item.conname);
            EXECUTE format('ALTER TABLE community.notifications ADD CONSTRAINT %I CHECK ((%s) OR kind IN (''suggestion_new'',''suggestion_accepted'',''suggestion_rejected'',''suggestion_stale''))',
                item.conname, substring(definition from 7));
        END IF;
    END LOOP;
END
$$;

-- Stage 11 adds fixed response anonymity and dashboard kind.
-- Stage 11: additive and idempotent. Roll back code only; preserve responses and their anonymity.
ALTER TABLE community.pages ADD COLUMN IF NOT EXISTS form_view_id TEXT;
ALTER TABLE community.pages ADD COLUMN IF NOT EXISTS form_anonymous BOOLEAN;
DO $$ DECLARE c record; expression text; BEGIN
 IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conrelid='community.pages'::regclass AND conname='pages_form_shape_check') THEN
  ALTER TABLE community.pages ADD CONSTRAINT pages_form_shape_check
   CHECK ((form_view_id IS NULL AND form_anonymous IS NULL) OR (form_view_id IS NOT NULL AND form_view_id ~ '^v[0-9a-f]{8}$' AND form_anonymous IS NOT NULL));
 END IF;
 FOR c IN SELECT conname, pg_get_constraintdef(oid) AS definition FROM pg_constraint
           WHERE conrelid='community.pages'::regclass AND contype='c' AND pg_get_constraintdef(oid) LIKE '%page_kind%'
 LOOP
  IF position('dashboard' in c.definition) = 0 THEN
   expression := substring(c.definition from 8 for length(c.definition)-8);
   EXECUTE format('ALTER TABLE community.pages DROP CONSTRAINT %I', c.conname);
   EXECUTE format('ALTER TABLE community.pages ADD CONSTRAINT %I CHECK ((%s) OR page_kind = ''dashboard'')', c.conname, expression);
  END IF;
 END LOOP;
 FOR c IN SELECT conname, pg_get_constraintdef(oid) AS definition FROM pg_constraint
           WHERE conrelid='community.notifications'::regclass AND contype='c' AND pg_get_constraintdef(oid) LIKE '%kind%'
 LOOP
  IF position('form_response' in c.definition) = 0 THEN
   expression := substring(c.definition from 8 for length(c.definition)-8);
   EXECUTE format('ALTER TABLE community.notifications DROP CONSTRAINT %I', c.conname);
   EXECUTE format('ALTER TABLE community.notifications ADD CONSTRAINT %I CHECK ((%s) OR kind = ''form_response'')', c.conname, expression);
  END IF;
 END LOOP;
END $$;
CREATE INDEX IF NOT EXISTS pages_form_rate_idx
 ON community.pages(database_id,form_view_id,author_id,created_at) WHERE form_view_id IS NOT NULL;

-- Stage 12 current fresh-install shape.

-- Stage 12 A/B/C. Run as tbox_owner; repeated application is safe.

BEGIN;
CREATE TABLE IF NOT EXISTS community.collections (
 id bigserial PRIMARY KEY, owner_user_id bigint NOT NULL REFERENCES public.users(id),
 name text NOT NULL CHECK(length(name) BETWEEN 1 AND 50), parent_id bigint REFERENCES community.collections(id) ON DELETE CASCADE,
 position integer NOT NULL DEFAULT 0, created_at timestamptz NOT NULL DEFAULT now());
CREATE INDEX IF NOT EXISTS collections_owner ON community.collections(owner_user_id);
CREATE TABLE IF NOT EXISTS community.collection_items (
 collection_id bigint REFERENCES community.collections(id) ON DELETE CASCADE, page_id bigint REFERENCES community.pages(id) ON DELETE CASCADE,
 position integer NOT NULL DEFAULT 0, added_at timestamptz NOT NULL DEFAULT now(), PRIMARY KEY(collection_id,page_id));
CREATE TABLE IF NOT EXISTS community.share_groups (
 id bigserial PRIMARY KEY,public_id uuid NOT NULL UNIQUE DEFAULT gen_random_uuid(),name text NOT NULL CHECK(length(name) BETWEEN 1 AND 50),
 created_by bigint NOT NULL REFERENCES public.users(id),kind text NOT NULL CHECK(kind IN ('admin','manager')),
 manager_team_id bigint REFERENCES public.teams(id),created_at timestamptz NOT NULL DEFAULT now(),deleted_at timestamptz);
CREATE UNIQUE INDEX IF NOT EXISTS share_groups_name ON community.share_groups(lower(name)) WHERE deleted_at IS NULL;
CREATE TABLE IF NOT EXISTS community.share_group_members (
 group_id bigint REFERENCES community.share_groups(id),user_id bigint REFERENCES public.users(id),added_by bigint NOT NULL REFERENCES public.users(id),
 added_at timestamptz NOT NULL DEFAULT now(),PRIMARY KEY(group_id,user_id));
CREATE TABLE IF NOT EXISTS community.group_member_events (
 id bigserial PRIMARY KEY,group_id bigint NOT NULL REFERENCES community.share_groups(id),user_id bigint NOT NULL REFERENCES public.users(id),
 action text NOT NULL CHECK(action IN ('add','remove')),by_user_id bigint NOT NULL REFERENCES public.users(id),at timestamptz NOT NULL DEFAULT now());
ALTER TABLE community.page_shares ALTER COLUMN user_id DROP NOT NULL;
ALTER TABLE community.page_shares ADD COLUMN IF NOT EXISTS group_id bigint REFERENCES community.share_groups(id);
DO $$ BEGIN IF NOT EXISTS(SELECT 1 FROM pg_constraint WHERE conrelid='community.page_shares'::regclass AND conname='page_shares_one_target') THEN
 ALTER TABLE community.page_shares ADD CONSTRAINT page_shares_one_target CHECK ((user_id IS NULL) <> (group_id IS NULL)); END IF; END $$;
CREATE UNIQUE INDEX IF NOT EXISTS page_shares_group ON community.page_shares(page_id,group_id);
CREATE TABLE IF NOT EXISTS community.synced_sources (
 id bigserial PRIMARY KEY,public_id uuid NOT NULL UNIQUE,page_id bigint NOT NULL REFERENCES community.pages(id),blocks jsonb NOT NULL CHECK(jsonb_typeof(blocks)='array'),
 version integer NOT NULL DEFAULT 1,updated_by bigint NOT NULL REFERENCES public.users(id),updated_at timestamptz NOT NULL DEFAULT now(),deleted_at timestamptz);
CREATE TABLE IF NOT EXISTS community.synced_references (
 page_id bigint REFERENCES community.pages(id),source_id uuid NOT NULL,PRIMARY KEY(page_id,source_id));
COMMIT;

-- Stage 12 D. Public forms use stage 11 form metadata and no stored raw token.

BEGIN;
ALTER TABLE community.spaces ADD COLUMN IF NOT EXISTS web_publish_allowed boolean NOT NULL DEFAULT false;
CREATE TABLE IF NOT EXISTS community.web_publications (
 id bigserial PRIMARY KEY, token_hash char(64) NOT NULL UNIQUE,page_id bigint NOT NULL REFERENCES community.pages(id),
 created_by bigint NOT NULL REFERENCES public.users(id),created_at timestamptz NOT NULL DEFAULT now(),expires_at timestamptz,revoked_at timestamptz,
 revoked_by bigint REFERENCES public.users(id),allow_index boolean NOT NULL DEFAULT false,view_count integer NOT NULL DEFAULT 0,last_viewed_at timestamptz);
CREATE TABLE IF NOT EXISTS community.web_publication_pages (
 publication_id bigint REFERENCES community.web_publications(id),page_id bigint REFERENCES community.pages(id),PRIMARY KEY(publication_id,page_id));
CREATE TABLE IF NOT EXISTS community.public_form_uses (
 nonce_hash char(64) PRIMARY KEY,publication_id bigint NOT NULL REFERENCES community.web_publications(id),used_at timestamptz NOT NULL DEFAULT now());
-- Only hashes of IPs appear in this rate bucket; raw IP is audit-only.
CREATE TABLE IF NOT EXISTS community.public_rate_buckets (
 key_hash char(64) PRIMARY KEY,bucket bigint NOT NULL,hits integer NOT NULL DEFAULT 0);
ALTER TABLE community.pages ADD COLUMN IF NOT EXISTS form_view_id text;
ALTER TABLE community.pages ADD COLUMN IF NOT EXISTS public_form_response boolean NOT NULL DEFAULT false;
ALTER TABLE community.pages ALTER COLUMN author_id DROP NOT NULL;
ALTER TABLE community.pages ALTER COLUMN last_edited_by DROP NOT NULL;
DO $$ BEGIN IF NOT EXISTS(SELECT 1 FROM pg_constraint WHERE conrelid='community.pages'::regclass AND conname='pages_anonymous_form_only') THEN
 ALTER TABLE community.pages ADD CONSTRAINT pages_anonymous_form_only CHECK (author_id IS NOT NULL OR (form_view_id IS NOT NULL AND public_form_response AND (database_id IS NOT NULL OR purged_at IS NOT NULL))); END IF; END $$;
COMMIT;

-- Stage 12 E/F. Secret URLs/keys are fixed server files, never database columns.

BEGIN;
CREATE TABLE IF NOT EXISTS community.messenger_hooks (
 id bigserial PRIMARY KEY,name text NOT NULL CHECK(length(name) BETWEEN 1 AND 50),url_host text NOT NULL CHECK(url_host ~ '^[a-z0-9][a-z0-9.-]+$'),
 is_active boolean NOT NULL DEFAULT false,created_by bigint NOT NULL REFERENCES public.users(id),created_at timestamptz NOT NULL DEFAULT now());
CREATE TABLE IF NOT EXISTS community.user_messenger (
 user_id bigint REFERENCES public.users(id),hook_id bigint REFERENCES community.messenger_hooks(id),kinds text[] NOT NULL DEFAULT '{}',PRIMARY KEY(user_id,hook_id));
CREATE TABLE IF NOT EXISTS community.notification_outbox (
 id bigserial PRIMARY KEY,notification_id bigint REFERENCES community.notifications(id) ON DELETE CASCADE,hook_id bigint REFERENCES community.messenger_hooks(id),
 status text NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','sent','skipped','failed')),tries integer NOT NULL DEFAULT 0,
 next_at timestamptz NOT NULL DEFAULT now(),sent_at timestamptz,last_reason text,UNIQUE(notification_id,hook_id));
CREATE TABLE IF NOT EXISTS community.data_connectors (
 id bigserial PRIMARY KEY,public_id uuid NOT NULL UNIQUE DEFAULT gen_random_uuid(),name text NOT NULL CHECK(length(name) BETWEEN 1 AND 50),
 database_id bigint NOT NULL REFERENCES community.databases(id),url_host text NOT NULL,url_path text NOT NULL,
 runner_user_id bigint NOT NULL REFERENCES public.users(id),schedule jsonb NOT NULL,field_map jsonb NOT NULL,
 is_active boolean NOT NULL DEFAULT false,created_by bigint NOT NULL REFERENCES public.users(id),created_at timestamptz NOT NULL DEFAULT now(),last_run_at timestamptz,last_status text);
ALTER TABLE community.pages ADD COLUMN IF NOT EXISTS external_key text;
ALTER TABLE community.pages ADD COLUMN IF NOT EXISTS external_missing boolean NOT NULL DEFAULT false;
ALTER TABLE community.pages ADD COLUMN IF NOT EXISTS external_connector_id bigint REFERENCES community.data_connectors(id);
CREATE UNIQUE INDEX IF NOT EXISTS pages_external_key ON community.pages(database_id,external_key);
CREATE OR REPLACE FUNCTION community.enqueue_messenger() RETURNS trigger LANGUAGE plpgsql SECURITY INVOKER SET search_path=pg_catalog,community AS $$
 BEGIN INSERT INTO community.notification_outbox(notification_id,hook_id)
 SELECT NEW.id,m.hook_id FROM community.user_messenger m JOIN community.messenger_hooks h ON h.id=m.hook_id
 WHERE m.user_id=NEW.user_id AND NEW.kind=ANY(m.kinds) AND h.is_active;
 RETURN NEW; END $$;
DROP TRIGGER IF EXISTS notifications_messenger ON community.notifications;
CREATE TRIGGER notifications_messenger AFTER INSERT ON community.notifications FOR EACH ROW EXECUTE FUNCTION community.enqueue_messenger();
REVOKE ALL ON FUNCTION community.enqueue_messenger() FROM PUBLIC;
COMMIT;

-- Stage 13: external AI defaults off; no existing document is implicitly approved.
ALTER TABLE community.databases ADD COLUMN IF NOT EXISTS tool TEXT;
CREATE TABLE IF NOT EXISTS community.ai_settings (
    singleton BOOLEAN PRIMARY KEY DEFAULT true CHECK(singleton),
    external_data_mode TEXT NOT NULL DEFAULT 'unconfigured' CHECK(external_data_mode IN ('unconfigured','free_public','paid_private')),
    search_enabled BOOLEAN NOT NULL DEFAULT false,
    summary_enabled BOOLEAN NOT NULL DEFAULT false,
    assistant_enabled BOOLEAN NOT NULL DEFAULT false,
    transcribe_enabled BOOLEAN NOT NULL DEFAULT false,
    briefing_enabled BOOLEAN NOT NULL DEFAULT false,
    updated_by BIGINT REFERENCES public.users(id), updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
INSERT INTO community.ai_settings(singleton) VALUES(true) ON CONFLICT DO NOTHING;
CREATE TABLE IF NOT EXISTS community.ai_page_permissions (
    page_id BIGINT PRIMARY KEY REFERENCES community.pages(id), version INTEGER NOT NULL CHECK(version>0),
    allowed_by BIGINT NOT NULL REFERENCES public.users(id), allowed_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE TABLE IF NOT EXISTS community.ai_requests (
    id BIGSERIAL PRIMARY KEY, user_id BIGINT NOT NULL REFERENCES public.users(id),
    day DATE NOT NULL DEFAULT ((now() AT TIME ZONE 'Asia/Seoul')::date),
    feature TEXT NOT NULL CHECK(feature IN ('search','index','summary','assistant','transcribe','briefing')),
    started_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS ai_requests_quota_idx ON community.ai_requests(user_id,day);
CREATE TABLE IF NOT EXISTS community.ai_log (
    id BIGSERIAL PRIMARY KEY, public_id UUID NOT NULL UNIQUE DEFAULT gen_random_uuid(),
    request_id BIGINT REFERENCES community.ai_requests(id), user_id BIGINT NOT NULL REFERENCES public.users(id),
    feature TEXT NOT NULL CHECK(feature IN ('search','index','summary','assistant','transcribe','briefing')),
    page_refs JSONB NOT NULL DEFAULT '[]', file_refs JSONB NOT NULL DEFAULT '[]',
    input_text TEXT NOT NULL DEFAULT '', output_text TEXT NOT NULL DEFAULT '',
    model TEXT NOT NULL, started_at TIMESTAMPTZ NOT NULL, finished_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    status TEXT NOT NULL, chars_in INTEGER NOT NULL, chars_out INTEGER NOT NULL,
    scrubbed_at TIMESTAMPTZ,
    CHECK(jsonb_typeof(page_refs)='array' AND jsonb_typeof(file_refs)='array')
);
CREATE INDEX IF NOT EXISTS ai_log_user_idx ON community.ai_log(user_id,id DESC);
CREATE TABLE IF NOT EXISTS community.page_embeddings (
    page_id BIGINT NOT NULL REFERENCES community.pages(id), chunk_no INTEGER NOT NULL CHECK(chunk_no>=0),
    version INTEGER NOT NULL CHECK(version>0), vec REAL[] NOT NULL CHECK(array_length(vec,1)=768),
    PRIMARY KEY(page_id,chunk_no)
);
CREATE TABLE IF NOT EXISTS community.embedding_queue (
    page_id BIGINT PRIMARY KEY REFERENCES community.pages(id), version INTEGER NOT NULL,
    next_chunk INTEGER NOT NULL DEFAULT 0, total_chunks INTEGER NOT NULL DEFAULT 0,
    state TEXT NOT NULL DEFAULT 'pending' CHECK(state IN ('pending','done','blocked')),
    available_at TIMESTAMPTZ NOT NULL DEFAULT now(), updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS embedding_queue_pending_idx ON community.embedding_queue(available_at,page_id) WHERE state='pending';


-- ─────────────────────────────────────────────────────────────
-- Q:F-12 U3-1 문서 전용 첨부 (커뮤니티 ARCHITECTURE 24.2 · 24.6 — 사이트 지시서 docs/qf12-u3-directive.md)
--   이미 있는 DB 는 db/migrations/2026-10-13_community_page_attachments.sql 이 **같은 모양**을 만든다(이 절은 그 파일의 1~2 절과
--   같은 문장 — 30일 정리 함수의 첨부 두 줄은 그 파일 3 절, 권한은 db/community/grants.sql 끝 절).
--   ★ 이 절은 Q:F-12 업무 화면 절 **앞**에 둔다 — qf12_floor_http 의 「Q:F-12 전 schema + 마이그레이션 = 새 설치」 비교가
--     그 절 앞까지를 기준 판으로 자르기 때문이다(두 쪽 모두 이 표를 가져 비교가 그대로 SAME).
--   ★ 앱 계정은 SELECT · INSERT · UPDATE(scan_status) 만 — DELETE 는 소유자의 영구 삭제 함수만(grants.sql).
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS community.page_attachments (
    id              BIGSERIAL   PRIMARY KEY,
    public_id       UUID        NOT NULL UNIQUE DEFAULT gen_random_uuid(),
    page_id         BIGINT      NOT NULL REFERENCES community.pages(id),
    uploaded_by     BIGINT      NOT NULL REFERENCES public.users(id),
    original_name   TEXT        NOT NULL CONSTRAINT page_attachments_name_check
                                CHECK (char_length(original_name) BETWEEN 1 AND 255),
    ext             TEXT        NOT NULL CONSTRAINT page_attachments_ext_check CHECK (ext ~ '^[a-z0-9]{1,10}$'),
    mime            TEXT        NOT NULL CONSTRAINT page_attachments_mime_check CHECK (char_length(mime) BETWEEN 1 AND 255),
    size_bytes      BIGINT      NOT NULL CONSTRAINT page_attachments_size_check CHECK (size_bytes BETWEEN 1 AND 104857600),
    sha256          TEXT        NOT NULL CONSTRAINT page_attachments_sha256_check CHECK (sha256 ~ '^[0-9a-f]{64}$'),
    stored_name     TEXT        NOT NULL UNIQUE CONSTRAINT page_attachments_stored_check
                                CHECK (stored_name ~ '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\.[a-z0-9]{1,10}$'),
    scan_status     TEXT        NOT NULL DEFAULT 'pending' CONSTRAINT page_attachments_scan_check
                                CHECK (scan_status IN ('pending','clean','infected')),
    sensitive_flags TEXT[]      NOT NULL DEFAULT '{}',
    exec_content    BOOLEAN     NOT NULL DEFAULT false,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS page_attachments_page_idx  ON community.page_attachments (page_id);
CREATE INDEX IF NOT EXISTS page_attachments_quota_idx ON community.page_attachments (uploaded_by, created_at);

CREATE TABLE IF NOT EXISTS community.page_attachment_purge_queue (
    stored_name TEXT        PRIMARY KEY CONSTRAINT page_attachment_purge_queue_stored_check
                            CHECK (stored_name ~ '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\.[a-z0-9]{1,10}$'),
    queued_at   TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- U3-1b — 정합성 검사(앱 계정)가 「저장소에서 찾은 이 이름들 중 영구 삭제 대기열에 있는 것」만 묻는 읽기 함수(마이그레이션 7 절과 같은 문장).
--   대기열 표의 앱 권한은 0 그대로 — 이미 아는 이름의 소속만 확인할 수 있다. EXECUTE 는 db/community/grants.sql 끝 절(tbox_app 하나).
CREATE OR REPLACE FUNCTION community.page_attachment_queued(p_names text[])
RETURNS SETOF text
LANGUAGE sql
STABLE
SECURITY DEFINER
SET search_path = community, pg_temp
AS $fn$
    SELECT q.stored_name FROM community.page_attachment_purge_queue q
     WHERE q.stored_name = ANY (p_names)
     ORDER BY q.stored_name
$fn$;
REVOKE ALL ON FUNCTION community.page_attachment_queued(text[]) FROM PUBLIC;

-- ─────────────────────────────────────────────────────────────
-- Q:F-12 업무 화면 — 담당자 · 프로젝트 · 포트폴리오 (지시서 A · 커뮤니티 ARCHITECTURE 23.2)
--   이미 있는 DB 는 db/migrations/2026-10-19_qf12_work.sql 이 **같은 모양**을 만든다(이 절은 그 파일의 1~5 절과
--   같은 문장이다 — 권한 6 절은 db/community/grants.sql 끝 절). `pages.assignee_id` 는 4-D 의 열 그대로 다시 쓴다.
--   ★ project_status_updates · portfolio_events 는 앱 계정 SELECT · INSERT 만(지울 수 없는 기록 — grants.sql).
-- ─────────────────────────────────────────────────────────────
ALTER TABLE community.databases ADD COLUMN IF NOT EXISTS purpose TEXT
    CONSTRAINT databases_purpose_check CHECK (purpose IN ('project','my_tasks'));
ALTER TABLE community.databases ADD COLUMN IF NOT EXISTS color TEXT
    CONSTRAINT databases_color_check CHECK (color IN ('peach','lilac','mint','sand'));

CREATE TABLE IF NOT EXISTS community.my_tasks_projects (
    user_id BIGINT PRIMARY KEY REFERENCES public.users(id),
    page_id BIGINT NOT NULL UNIQUE REFERENCES community.pages(id)
);

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

-- 알림 종류 'assigned' — 누적 CHECK 의 다음 판(지금 정의를 읽어 OR 로 잇는다. 마이그레이션 5 절과 같은 문장)
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

-- ─────────────────────────────────────────────────────────────
-- 「우리 공간」 카페 C1 (사이트 Q:F-12 카페 · 커뮤니티 ARCHITECTURE 25장) — 새 설치 모양.
--   이미 있는 DB 는 db/migrations/2026-10-14_community_cafe.sql 이 **같은 모양**을 만든다(이 절은 그 파일의 4~7 절과 같은 문장).
--   spaces.area · board_kind 'anonymous' 는 위 CREATE TABLE community.spaces 에 있다. 첫 게시판 행(자유게시판 이동 · 익명게시판)은
--   다른 첫 게시판과 같이 이주 파일만 만든다(이 파일은 행을 넣지 않는다). 앱 권한은 db/community/grants.sql 끝 절.
-- ─────────────────────────────────────────────────────────────
-- ── 4. 별칭 (ARCH 25.4) — 글쓴이 0 · 댓글 쓴 사람마다 다음 번호. 바뀌지 않는다(앱 UPDATE · DELETE 0) ──
CREATE TABLE IF NOT EXISTS community.anon_aliases (
    page_id    BIGINT      NOT NULL REFERENCES community.pages(id),
    user_id    BIGINT      NOT NULL REFERENCES public.users(id),
    alias_no   INT         NOT NULL CONSTRAINT anon_aliases_no_check CHECK (alias_no >= 0),
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (page_id, user_id),
    CONSTRAINT anon_aliases_page_no_uniq UNIQUE (page_id, alias_no)
);

-- ── 5. 신고 (ARCH 25.5) ──
CREATE TABLE IF NOT EXISTS community.reports (
    id              BIGSERIAL   PRIMARY KEY,
    public_id       UUID        NOT NULL UNIQUE DEFAULT gen_random_uuid(),
    page_id         BIGINT      NOT NULL REFERENCES community.pages(id),
    comment_id      BIGINT      REFERENCES community.comments(id),
    reporter_id     BIGINT      NOT NULL REFERENCES public.users(id),
    reason_kind     TEXT        NOT NULL CONSTRAINT reports_reason_kind_check
                                CHECK (reason_kind IN ('abuse','defamation','privacy','spam','other')),
    detail          TEXT        CONSTRAINT reports_detail_check CHECK (char_length(detail) <= 500),
    status          TEXT        NOT NULL DEFAULT 'open' CONSTRAINT reports_status_check
                                CHECK (status IN ('open','hidden','rejected')),
    resolved_by     BIGINT      REFERENCES public.users(id),
    resolution_note TEXT        CONSTRAINT reports_resolution_note_check CHECK (char_length(resolution_note) <= 500),
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    resolved_at     TIMESTAMPTZ,
    CONSTRAINT reports_resolved_shape CHECK ((status = 'open') = (resolved_at IS NULL))
);
CREATE INDEX IF NOT EXISTS reports_status_created_idx   ON community.reports (status, created_at);
CREATE INDEX IF NOT EXISTS reports_reporter_created_idx ON community.reports (reporter_id, created_at);
-- 사람마다 같은 대상(글 · 댓글)에 열린 신고 하나
CREATE UNIQUE INDEX IF NOT EXISTS reports_open_once_uidx
    ON community.reports (reporter_id, page_id, coalesce(comment_id, 0)) WHERE status = 'open';

-- ── 6. 게시판 요청 (ARCH 25.7) ──
CREATE TABLE IF NOT EXISTS community.board_requests (
    id            BIGSERIAL   PRIMARY KEY,
    public_id     UUID        NOT NULL UNIQUE DEFAULT gen_random_uuid(),
    requester_id  BIGINT      NOT NULL REFERENCES public.users(id),
    name          TEXT        NOT NULL CONSTRAINT board_requests_name_check CHECK (char_length(name) BETWEEN 1 AND 40),
    purpose       TEXT        NOT NULL CONSTRAINT board_requests_purpose_check CHECK (char_length(purpose) BETWEEN 1 AND 500),
    anonymous     BOOLEAN     NOT NULL DEFAULT false,
    status        TEXT        NOT NULL DEFAULT 'open' CONSTRAINT board_requests_status_check
                              CHECK (status IN ('open','created','rejected')),
    decided_by    BIGINT      REFERENCES public.users(id),
    decision_note TEXT        CONSTRAINT board_requests_note_check CHECK (char_length(decision_note) <= 500),
    board_id      BIGINT      REFERENCES community.spaces(id),
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    decided_at    TIMESTAMPTZ,
    CONSTRAINT board_requests_decided_shape CHECK ((status = 'open') = (decided_at IS NULL)),
    CONSTRAINT board_requests_board_shape   CHECK ((status = 'created') = (board_id IS NOT NULL))
);
CREATE INDEX IF NOT EXISTS board_requests_status_created_idx    ON community.board_requests (status, created_at);
CREATE INDEX IF NOT EXISTS board_requests_requester_created_idx ON community.board_requests (requester_id, created_at);

-- ── 7. 알림 종류 셋 (누적 CHECK — 지금 정의를 읽어 OR 로 잇는다. 이미 있으면 아무것도 하지 않는다) ──
DO $$
DECLARE c record;
BEGIN
    FOR c IN SELECT conname, pg_get_constraintdef(oid) AS definition FROM pg_constraint
              WHERE conrelid = 'community.notifications'::regclass AND contype = 'c'
                AND pg_get_constraintdef(oid) LIKE '%kind%'
    LOOP
        IF position('''moderated''' in c.definition) = 0 THEN
            EXECUTE format('ALTER TABLE community.notifications DROP CONSTRAINT %I', c.conname);
            EXECUTE format('ALTER TABLE community.notifications ADD CONSTRAINT %I CHECK ((%s) OR kind IN (''moderated'',''board_request'',''board_request_done''))',
                c.conname, substring(c.definition from 8 for length(c.definition) - 8));
        END IF;
    END LOOP;
END
$$;

-- ─────────────────────────────────────────────────────────────
-- Q:F-12 W1 작업의 「시작 예정」 (결정 WK-29 · 기획 work-ux-plan.md 19.2 · DESIGN 2.34)
--   이미 있는 DB 는 db/migrations/2026-10-14_task_start_date.sql 이 **같은 모양**을 만든다(이 절은 그 파일의 1~2 절과 같은 문장).
--   실제 시작 · 끝난 날은 저장하지 않는다 — 속성 사건 prop 'status' 에서 계산(routes.php community_task_dates).
-- ─────────────────────────────────────────────────────────────
ALTER TABLE community.pages ADD COLUMN IF NOT EXISTS start_date DATE;

-- 속성 사건 'start' — 누적 CHECK(지금 정의를 읽어 OR 로 잇는다. 이미 있으면 아무것도 하지 않는다)
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

-- ─────────────────────────────────────────────────────────────
-- 규모 D2a (docs/scale-d2a-directive.md A1 · A2 · A5~A8) — 미리 센 게시판 글 수 · 목록 모양 색인
--   이미 있는 DB 는 db/migrations/2026-10-26_scale_d2a_board_counts.sql(칸 · 트리거 · 채움) ·
--   db/migrations/2026-10-26_scale_d2a_indexes.sql(CONCURRENTLY)이 **같은 정의**를 만든다(이 절은 그 두 파일과 같은 문장 — 새로 깔 때는 빈 표라 채움 0).
--   post_count 정의 = 옛 하위 질의: deleted_at IS NULL AND hidden_at IS NULL AND (space_id = b.id OR (visibility='published' AND published_board_id = b.id)).
-- ─────────────────────────────────────────────────────────────
ALTER TABLE community.spaces ADD COLUMN IF NOT EXISTS post_count integer NOT NULL DEFAULT 0;
CREATE OR REPLACE FUNCTION community.scale_board_post_count() RETURNS trigger
LANGUAGE plpgsql SECURITY INVOKER SET search_path=pg_catalog AS $$
DECLARE
    old_counts jsonb := '{}';
    new_counts jsonb := '{}';
    delta record;
BEGIN
    IF TG_OP = 'UPDATE' THEN
        IF NOT EXISTS (
            SELECT 1 FROM d2a_old_pages o JOIN d2a_new_pages n ON n.id = o.id
             WHERE o.space_id IS DISTINCT FROM n.space_id
                OR o.published_board_id IS DISTINCT FROM n.published_board_id
                OR o.visibility IS DISTINCT FROM n.visibility
                OR (o.deleted_at IS NULL) <> (n.deleted_at IS NULL)
                OR (o.hidden_at IS NULL) <> (n.hidden_at IS NULL)
        ) THEN
            RETURN NULL;
        END IF;
    END IF;
    -- 게시판만 집계한다. 한 글의 space_id 와 published_board_id 가 같으면 한 번만.
    IF TG_OP <> 'INSERT' THEN
        SELECT COALESCE(jsonb_object_agg(c.board_id, c.n), '{}'::jsonb) INTO old_counts
          FROM (
            SELECT b.board_id, count(*) AS n
              FROM d2a_old_pages p
              CROSS JOIN LATERAL (
                SELECT p.space_id AS board_id
                UNION ALL
                SELECT p.published_board_id WHERE p.visibility = 'published'
                   AND p.published_board_id IS NOT NULL AND p.published_board_id <> p.space_id
              ) b
              JOIN community.spaces s ON s.id = b.board_id AND s.kind = 'board'
             WHERE p.deleted_at IS NULL AND p.hidden_at IS NULL
             GROUP BY b.board_id
          ) c;
    END IF;
    IF TG_OP <> 'DELETE' THEN
        SELECT COALESCE(jsonb_object_agg(c.board_id, c.n), '{}'::jsonb) INTO new_counts
          FROM (
            SELECT b.board_id, count(*) AS n
              FROM d2a_new_pages p
              CROSS JOIN LATERAL (
                SELECT p.space_id AS board_id
                UNION ALL
                SELECT p.published_board_id WHERE p.visibility = 'published'
                   AND p.published_board_id IS NOT NULL AND p.published_board_id <> p.space_id
              ) b
              JOIN community.spaces s ON s.id = b.board_id AND s.kind = 'board'
             WHERE p.deleted_at IS NULL AND p.hidden_at IS NULL
             GROUP BY b.board_id
          ) c;
    END IF;
    -- 문장 전체에서 상쇄된 게시판은 건드리지 않는다. UPDATE 를 한 행씩 명시적으로 id 순서로 실행해 잠금 순서를 고정한다.
    -- 한 트랜잭션에서 서로 다른 문장을 반대 게시판 순서로 실행하는 임의 SQL 까지 직렬화하지는 않는다(보고서 한계).
    FOR delta IN
        SELECT d.board_id, sum(d.n) AS n FROM (
            SELECT key::bigint AS board_id, -(value::bigint) AS n FROM jsonb_each_text(old_counts)
            UNION ALL
            SELECT key::bigint AS board_id, value::bigint AS n FROM jsonb_each_text(new_counts)
        ) d
        GROUP BY d.board_id HAVING sum(d.n) <> 0 ORDER BY d.board_id
    LOOP
        UPDATE community.spaces SET post_count = post_count + delta.n
         WHERE id = delta.board_id AND kind = 'board';
    END LOOP;
    RETURN NULL;
END $$;
REVOKE ALL ON FUNCTION community.scale_board_post_count() FROM PUBLIC;
CREATE OR REPLACE TRIGGER scale_board_post_count_insert AFTER INSERT ON community.pages
    REFERENCING NEW TABLE AS d2a_new_pages
    FOR EACH STATEMENT EXECUTE FUNCTION community.scale_board_post_count();
CREATE OR REPLACE TRIGGER scale_board_post_count_delete AFTER DELETE ON community.pages
    REFERENCING OLD TABLE AS d2a_old_pages
    FOR EACH STATEMENT EXECUTE FUNCTION community.scale_board_post_count();
CREATE OR REPLACE TRIGGER scale_board_post_count_update AFTER UPDATE ON community.pages
    REFERENCING OLD TABLE AS d2a_old_pages NEW TABLE AS d2a_new_pages
    FOR EACH STATEMENT EXECUTE FUNCTION community.scale_board_post_count();
CREATE INDEX IF NOT EXISTS pages_space_updated_idx      ON community.pages (space_id, updated_at DESC, id DESC) WHERE deleted_at IS NULL;
CREATE INDEX IF NOT EXISTS pages_author_updated_idx     ON community.pages (author_id, updated_at DESC, id DESC) WHERE deleted_at IS NULL;
CREATE INDEX IF NOT EXISTS pages_author_due_idx         ON community.pages (author_id, due_date) WHERE deleted_at IS NULL AND due_date IS NOT NULL;
CREATE INDEX IF NOT EXISTS pages_author_status_idx      ON community.pages (author_id, status) WHERE deleted_at IS NULL AND status IS NOT NULL;
CREATE INDEX IF NOT EXISTS pages_trash_by_idx           ON community.pages (deleted_by, deleted_at DESC) WHERE deleted_at IS NOT NULL;
CREATE INDEX IF NOT EXISTS pages_trash_parent_idx       ON community.pages (parent_page_id, deleted_at) WHERE deleted_at IS NOT NULL;
CREATE INDEX IF NOT EXISTS page_shares_group_idx        ON community.page_shares (group_id) WHERE group_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS share_group_members_user_idx ON community.share_group_members (user_id, group_id);
