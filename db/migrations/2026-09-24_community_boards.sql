-- db/migrations/2026-09-24_community_boards.sql
--   커뮤니티 5-C — 게시판 공간 · 새 글 · 게시 · 태그 · 조회수 (C:F-18 · C:F-26 · C:F-35, PRD 4장 · 4.1 게시 행)
--
-- 요구사항: ../../../community/stage5c-board-publish-directive.md 「데이터」,
--           ../../../community/PRD.md 4장(게시판 등급 = t-box 파일·폴더 네 값) · 11-1 ④(첫 게시판 셋)
--
-- 실행 계정: tbox_owner (표 소유자).
-- 실행 예:  PGPASSFILE=/etc/tbox-v2/owner.pgpass psql -h 127.0.0.1 -U tbox_owner -d tbox_re \
--             -v ON_ERROR_STOP=1 -f db/migrations/2026-09-24_community_boards.sql
--
-- ★ 이 파일 뒤에 `db/community/grants.sql` 을 **반드시 한 번 더** 돌린다(postgres 계정).
--   새 표 둘에는 아직 앱 계정 권한이 하나도 없다 — 그 파일이
--     `page_tags`  SIUD (태그는 바뀌는 값이다 — 바뀐 일은 감사 `page.tags` 가 남긴다)
--     `page_views` **SELECT·INSERT 만** (조회 기록은 고치지도 지우지도 않는다)
--   을 준다. 이 마이그레이션은 GRANT 를 쓰지 않는다(권한의 진실은 `grants.sql` 한 파일 — 4-C·4-D·5-A 와 같다).
--
-- 하는 일 (표·열 **삭제 0**, 데이터 손실 0, 여러 번 돌려도 같다)
--   1. spaces — `board_kind`(notice·discussion·qa) 열 + 게시판 행의 모양 CHECK 셋 + 게시판 이름 UNIQUE(대소문자 무시).
--   2. pages  — 게시(`published_board_id`·`published_from`·`published_at`·`published_by`) ·
--               숨김(`hidden_at`·`hidden_by`) · 질문답변 「해결됨」(`resolved`) 열 + CHECK 셋.
--   3. page_property_events — `prop` CHECK 에 `publish`·`hide`·`resolved` 를 더한다(게시·해제·숨김 시각이
--               「처리 이력」에 남는다 — 지울 수 없는 표를 새로 만들지 않고 4-D 의 기록 표를 쓴다).
--   4. page_tags  — (page_id, tag, tag_norm) · 한 페이지 안에서 대소문자 무시 한 번.
--   5. page_views — (page_id, user_id, day) UNIQUE — 한 사람 하루 한 번.
--   6. 시드 게시판 셋 — 공지사항(public·notice) · 자유게시판(internal·discussion) · 질문과 답변(internal·qa).
--      이미 같은 이름(대소문자 무시)의 게시판이 있으면 건너뛴다.
--
-- ★ **앱은 이 파일이 적용되기 전의 DB 에서 5-C 코드를 돌리지 못한다** — 페이지 한 건을 읽는 질의
--   (`community_page_row`)와 목록 조건절(`community_visible_pages_where`)이 새 열을 읽는다.
--   순서는 「이 마이그레이션 → grants.sql → 코드 배포」다(4-D·5-A 교훈).
--
-- 되돌리기 (아무도 새 기능을 쓰기 전 — 게시된 페이지·게시판 글이 없을 때만):
--   DELETE FROM community.spaces WHERE kind='board' AND NOT EXISTS (SELECT 1 FROM community.pages p WHERE p.space_id = spaces.id);
--   DROP TABLE community.page_views; DROP TABLE community.page_tags;
--   ALTER TABLE community.pages DROP CONSTRAINT pages_published_shape, DROP CONSTRAINT pages_published_from_check,
--         DROP CONSTRAINT pages_hidden_shape, DROP COLUMN published_board_id, DROP COLUMN published_from,
--         DROP COLUMN published_at, DROP COLUMN published_by, DROP COLUMN hidden_at, DROP COLUMN hidden_by,
--         DROP COLUMN resolved;
--   (page_property_events CHECK 는 넓힌 채 두어도 해가 없다 — 되돌리면 새 값이 든 행이 있을 때 실패한다)
--   ALTER TABLE community.spaces DROP CONSTRAINT spaces_board_shape, DROP CONSTRAINT spaces_board_kind_check,
--         DROP CONSTRAINT spaces_board_team, DROP COLUMN board_kind; DROP INDEX community.spaces_board_name_uidx;

\set ON_ERROR_STOP on

BEGIN;

-- ─── 1. 게시판 공간 ────────────────────────────────────────────
--   `kind='board'` 는 3단계 CHECK 에 이미 있다. 여기서는 모양만 더한다.
--   `board_kind`  게시판 종류 셋(PRD 11-1 ④). 개인·팀 공간은 NULL.
--   `notice`      3단계부터 있던 열 — 게시판에서는 `board_kind='notice'` 와 **같아야** 한다(CHECK).
--                 판정은 `board_kind` 하나만 본다(두 칸이 서로 다른 말을 하지 못하게 묶어 둔다).
--   등급 `access_level` 은 t-box 파일·폴더와 같은 네 값이고(3단계 CHECK), `team` 이면 `team_id` 필수.
ALTER TABLE community.spaces ADD COLUMN IF NOT EXISTS board_kind TEXT;

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint
                    WHERE conname = 'spaces_board_kind_check' AND conrelid = 'community.spaces'::regclass) THEN
        ALTER TABLE community.spaces ADD CONSTRAINT spaces_board_kind_check
            CHECK (board_kind IS NULL OR board_kind IN ('notice','discussion','qa'));
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_constraint
                    WHERE conname = 'spaces_board_shape' AND conrelid = 'community.spaces'::regclass) THEN
        -- 게시판이면 종류가 있고 공지 여부가 종류와 맞다 · 게시판이 아니면 종류가 없다.
        ALTER TABLE community.spaces ADD CONSTRAINT spaces_board_shape
            CHECK ((kind =  'board' AND board_kind IS NOT NULL AND notice = (board_kind = 'notice'))
                OR (kind <> 'board' AND board_kind IS NULL));
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_constraint
                    WHERE conname = 'spaces_board_team' AND conrelid = 'community.spaces'::regclass) THEN
        -- 팀 등급 게시판은 팀이 있어야 한다(없으면 t-box `decideRead` 가 누구에게도 열지 않는 그릇이 된다).
        ALTER TABLE community.spaces ADD CONSTRAINT spaces_board_team
            CHECK (kind <> 'board' OR access_level <> 'team' OR team_id IS NOT NULL);
    END IF;
END
$$;

-- 게시판 이름은 대소문자를 무시하고 하나뿐이다(PRD F-09 「대소문자 무시 중복 불가」).
CREATE UNIQUE INDEX IF NOT EXISTS spaces_board_name_uidx ON community.spaces (lower(name)) WHERE kind = 'board';

-- ─── 2. 게시 · 숨김 · 해결됨 ──────────────────────────────────
--   `published_board_id` 게시한 게시판(게시 중일 때만). `visibility='published'` 와 짝이다(CHECK).
--   `published_from`     게시 **전** 범위(private·team·internal) — 해제하면 이 값으로 돌아간다.
--                        게시 중에도 판정은 이 값을 「원래 범위」로 함께 본다(넓은 쪽 — 게시는 여는 일).
--   `published_at`·`published_by` 누가·언제 게시했나. (지울 수 없는 기록은 `page_property_events` 에 따로 남는다)
--   `hidden_at`·`hidden_by`       게시판 목록에서 내린 것(게시판 Manager·Admin). 원본은 그대로다.
--   `resolved`           질문답변 게시판의 「해결됨」 — 쓴 사람이 켜고 끈다.
ALTER TABLE community.pages
    ADD COLUMN IF NOT EXISTS published_board_id BIGINT REFERENCES community.spaces(id),
    ADD COLUMN IF NOT EXISTS published_from     TEXT,
    ADD COLUMN IF NOT EXISTS published_at       TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS published_by       BIGINT REFERENCES public.users(id),
    ADD COLUMN IF NOT EXISTS hidden_at          TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS hidden_by          BIGINT REFERENCES public.users(id),
    ADD COLUMN IF NOT EXISTS resolved           BOOLEAN NOT NULL DEFAULT false;

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint
                    WHERE conname = 'pages_published_from_check' AND conrelid = 'community.pages'::regclass) THEN
        ALTER TABLE community.pages ADD CONSTRAINT pages_published_from_check
            CHECK (published_from IS NULL OR published_from IN ('private','team','internal'));
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_constraint
                    WHERE conname = 'pages_published_shape' AND conrelid = 'community.pages'::regclass) THEN
        -- 게시 중이면 게시판·원래 범위·시각이 다 있고, 게시 중이 아니면 셋 다 없다.
        ALTER TABLE community.pages ADD CONSTRAINT pages_published_shape
            CHECK ((visibility =  'published' AND published_board_id IS NOT NULL
                                              AND published_from IS NOT NULL AND published_at IS NOT NULL)
                OR (visibility <> 'published' AND published_board_id IS NULL
                                              AND published_from IS NULL))
            NOT VALID;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_constraint
                    WHERE conname = 'pages_hidden_shape' AND conrelid = 'community.pages'::regclass) THEN
        ALTER TABLE community.pages ADD CONSTRAINT pages_hidden_shape
            CHECK ((hidden_at IS NULL) = (hidden_by IS NULL));
    END IF;
END
$$;
-- `NOT VALID` 로 붙인 뒤 한 번 검사한다 — 3~5-B 동안 `published` 는 화면·API 가 받지 않았으므로 그 값의
-- 행이 없어야 정상이다. 있다면 여기서 멈춘다(무엇이 있는지 사람이 보고 판단한다 — 고쳐 쓰지 않는다).
ALTER TABLE community.pages VALIDATE CONSTRAINT pages_published_shape;

CREATE INDEX IF NOT EXISTS pages_published_board_idx ON community.pages (published_board_id)
    WHERE deleted_at IS NULL AND published_board_id IS NOT NULL;

-- ─── 3. 처리 이력에 게시·숨김·해결됨 ──────────────────────────
--   4-D 표의 CHECK 를 넓힌다(값 삭제 0 — 옛 값 둘은 그대로 들어 있다).
--   옛 CHECK 는 4-D 가 이름 없이 만들었다(PostgreSQL 이 붙인 이름 `page_property_events_prop_check`).
--   이름이 달라졌을 경우까지 대비해 `prop` 을 보는 CHECK 를 이름으로가 아니라 **정의로** 찾아 뗀다.
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
    CHECK (prop IN ('status','assignee','publish','hide','resolved'));

-- ─── 4. 태그 ──────────────────────────────────────────────────
--   `tag`      보이는 글자 그대로(앞뒤 공백 뗀 1~30자 — 앱이 먼저 보고, DB 가 한 번 더 막는다).
--   `tag_norm` 같은 태그를 가르는 값 = lower(tag). 한 페이지 안에서 하나뿐이다(PK).
--   제어 문자 거부는 앱의 몫이다(`\p{C}` — DB CHECK 로 옮기면 정규식이 두 벌이 된다).
CREATE TABLE IF NOT EXISTS community.page_tags (
    page_id    BIGINT      NOT NULL REFERENCES community.pages(id),
    tag        TEXT        NOT NULL CHECK (length(tag) BETWEEN 1 AND 30 AND tag = btrim(tag)),
    tag_norm   TEXT        NOT NULL,
    created_by BIGINT      NOT NULL REFERENCES public.users(id),
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (page_id, tag_norm),
    CONSTRAINT page_tags_norm_check CHECK (tag_norm = lower(tag))
);
CREATE INDEX IF NOT EXISTS page_tags_norm_idx ON community.page_tags (tag_norm);

-- ─── 5. 조회 ──────────────────────────────────────────────────
--   한 사람 하루 한 줄. 게시판 목록의 조회수 = 이 표의 줄 수(누가 봤는지는 목록에 나가지 않는다).
--   `day` 는 DB 의 오늘(`current_date`) — 웹 서버 시계를 믿지 않는다.
CREATE TABLE IF NOT EXISTS community.page_views (
    page_id BIGINT NOT NULL REFERENCES community.pages(id),
    user_id BIGINT NOT NULL REFERENCES public.users(id),
    day     DATE   NOT NULL DEFAULT current_date,
    PRIMARY KEY (page_id, user_id, day)
);

-- ─── 6. 시드 게시판 셋 (PRD 11-1 ④) ───────────────────────────
--   `created_by` 는 NULL(시드). 같은 이름이 있으면 건너뛴다 — 여러 번 돌려도 셋뿐이다.
INSERT INTO community.spaces (kind, name, description, access_level, notice, board_kind, created_by)
SELECT v.kind, v.name, v.description, v.access_level, v.notice, v.board_kind, NULL
  FROM (VALUES
        ('board', '공지사항',     '회사 공지를 올립니다. 글은 관리자만 씁니다.',       'public',   true,  'notice'),
        ('board', '자유게시판',   '사내 구성원이 자유롭게 이야기를 나눕니다.',          'internal', false, 'discussion'),
        ('board', '질문과 답변',  '묻고 답합니다. 해결되면 쓴 사람이 「해결됨」을 켭니다.', 'internal', false, 'qa')
       ) AS v(kind, name, description, access_level, notice, board_kind)
 WHERE NOT EXISTS (SELECT 1 FROM community.spaces s WHERE s.kind = 'board' AND lower(s.name) = lower(v.name));

COMMIT;

-- ─── 확인 (적용한 사람이 눈으로 본다) ───
--   SELECT id, name, access_level, board_kind, notice, is_active FROM community.spaces WHERE kind='board' ORDER BY id;  -- 셋
--   \d community.page_tags
--   \d community.page_views
--   SELECT count(*) FROM community.pages WHERE visibility='published';   -- 0 이어야 한다
--   -- grants.sql 을 돌린 뒤: page_views 는 r=SELECT · a=INSERT 둘만 보여야 한다
--   SELECT relname, relacl FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
--    WHERE n.nspname='community' AND relname IN ('page_tags','page_views');
