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
--   · 표    : `boards`(→ spaces 로 합쳤다) · `tags` · `page_tags` · `space_tags` · `palettes` · `page_reads`
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
    is_active     BOOLEAN     NOT NULL DEFAULT true,
    created_by    BIGINT      REFERENCES public.users(id),
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT spaces_personal_needs_owner CHECK (kind <> 'personal' OR owner_user_id IS NOT NULL),
    CONSTRAINT spaces_team_needs_team      CHECK (kind <> 'team'     OR team_id       IS NOT NULL)
);

-- 사람마다 개인 공간은 **하나**다. `GET /api/me/space` 가 없으면 만드는데, 같은 사람이 두 창을
-- 동시에 열어도 두 벌이 생기지 않게 하는 것은 앱의 순서가 아니라 이 인덱스다.
CREATE UNIQUE INDEX spaces_personal_owner_uidx ON community.spaces (owner_user_id) WHERE kind = 'personal';
CREATE UNIQUE INDEX spaces_team_uidx           ON community.spaces (team_id)       WHERE kind = 'team';

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
    prop       TEXT        NOT NULL CHECK (prop IN ('status','assignee')),
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
