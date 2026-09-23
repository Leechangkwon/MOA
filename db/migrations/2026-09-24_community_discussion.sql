-- db/migrations/2026-09-24_community_discussion.sql
--   커뮤니티 5-D — 댓글 · 멘션 · 알림 · 공지 읽음 (C:F-21 · C:F-22 · C:F-30, ARCH 15장)
--
-- 요구사항: ../../../community/stage5d-discussion-directive.md 「데이터」 (결정 D-1~D-9 기본안, 2026-09-23)
--
-- 실행 계정: tbox_owner (표 소유자).
-- 실행 예:  PGPASSFILE=/etc/tbox-v2/owner.pgpass psql -h 127.0.0.1 -U tbox_owner -d tbox_re \
--             -v ON_ERROR_STOP=1 -f db/migrations/2026-09-24_community_discussion.sql
--
-- ★ 이 파일 뒤에 `db/community/grants.sql` 을 **반드시 한 번 더** 돌린다(postgres 계정). 권한의 진실은 그 파일 하나다:
--     comments          SELECT·INSERT·UPDATE  (지우기는 논리 삭제 — **DELETE 없음**)
--     comment_reactions SELECT·INSERT·DELETE  (「도움됐어요」 누르고 빼기)
--     notifications     SELECT·INSERT·UPDATE  (읽음 표시만 고친다 — DELETE 없음)
--     page_reads        SELECT·INSERT·UPDATE  (지울 수 없는 읽음 기록 — C:S-14, DELETE 없음)
-- ★ 5-C(`2026-09-24_community_boards.sql`) 뒤에 돌린다 — 공지 읽음은 게시판 종류(`board_kind`)를 본다.
-- ★ **앱은 이 파일이 적용되기 전의 DB 에서 5-D 코드를 돌리지 못한다**(페이지 화면이 댓글 수·알림을 읽는다).
--
-- 하는 일 (표·열 삭제 0, 여러 번 돌려도 같다)
--   1. comments          페이지 댓글 · 블록 앵커(block_id + 인용) · 답글 1단 · 해결됨 · 숨김 · 논리 삭제. 본문은 **평문**(D-1)
--   2. comment_reactions 「도움됐어요」 — 한 사람 한 번
--   3. notifications     멘션 · 답글 · 내 페이지에 댓글 · 인계 받음
--   4. page_reads        공지 읽음 — 사람마다 한 줄(처음·마지막 읽은 때)
--
-- 되돌리기 (아무도 쓰기 전): DROP TABLE community.page_reads, community.notifications,
--                              community.comment_reactions, community.comments;

\set ON_ERROR_STOP on

BEGIN;

CREATE TABLE IF NOT EXISTS community.comments (
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
    CONSTRAINT comments_resolved_shape CHECK ((resolved_at IS NULL) = (resolved_by IS NULL)),
    CONSTRAINT comments_hidden_shape   CHECK ((hidden_at IS NULL) = (hidden_by IS NULL)),
    CONSTRAINT comments_deleted_shape  CHECK ((deleted_at IS NULL) = (deleted_by IS NULL)),
    CONSTRAINT comments_not_self_parent CHECK (parent_comment_id IS NULL OR parent_comment_id <> id)
);
CREATE INDEX IF NOT EXISTS comments_page_idx   ON community.comments (page_id, created_at) WHERE deleted_at IS NULL;
CREATE INDEX IF NOT EXISTS comments_parent_idx ON community.comments (parent_comment_id) WHERE parent_comment_id IS NOT NULL;

CREATE TABLE IF NOT EXISTS community.comment_reactions (
    comment_id BIGINT      NOT NULL REFERENCES community.comments(id),
    user_id    BIGINT      NOT NULL REFERENCES public.users(id),
    kind       TEXT        NOT NULL CHECK (kind IN ('helpful')),
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (comment_id, user_id, kind)
);

CREATE TABLE IF NOT EXISTS community.notifications (
    id         BIGSERIAL   PRIMARY KEY,
    user_id    BIGINT      NOT NULL REFERENCES public.users(id),
    kind       TEXT        NOT NULL CHECK (kind IN ('mention','reply','comment_on_my_page','handover')),
    page_id    BIGINT      REFERENCES community.pages(id),
    comment_id BIGINT      REFERENCES community.comments(id),
    actor_id   BIGINT      REFERENCES public.users(id),
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    read_at    TIMESTAMPTZ
);
CREATE INDEX IF NOT EXISTS notifications_user_idx ON community.notifications (user_id, created_at DESC);
CREATE INDEX IF NOT EXISTS notifications_unread_idx ON community.notifications (user_id) WHERE read_at IS NULL;

CREATE TABLE IF NOT EXISTS community.page_reads (
    page_id       BIGINT      NOT NULL REFERENCES community.pages(id),
    user_id       BIGINT      NOT NULL REFERENCES public.users(id),
    first_read_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    last_read_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (page_id, user_id)
);

COMMIT;

-- ─── 확인 ───
--   \d community.comments
--   -- grants.sql 뒤: comments 에 tbox_app 은 arw(DELETE 없음), page_reads 도 arw
--   SELECT relname, relacl FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
--    WHERE n.nspname='community' AND relname IN ('comments','comment_reactions','notifications','page_reads');
