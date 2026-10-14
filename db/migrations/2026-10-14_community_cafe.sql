-- db/migrations/2026-10-14_community_cafe.sql — 「우리 공간」 카페 · 익명게시판 · 신고 · 작성자 확인 · 게시판 요청 (사이트 Q:F-12 카페 C1)
--
-- 요구사항: ../../../community/ARCHITECTURE.md 25장(25.2 스키마 · 25.4 별칭 · 25.5 신고 · 25.7 요청) · ../../../community/cafe-plan.md ·
--           지시서 docs/cafe-directive.md 「C1 — 보안 바닥」.
--
-- 추가 전용 · 되풀이 안전(ADD COLUMN IF NOT EXISTS · CREATE … IF NOT EXISTS · 제약은 이름으로 확인 · REVOKE/GRANT 멱등).
-- 지우는 것은 없다. 고치는 행은 하나 — 「자유게시판」 게시판 행의 area 를 'cafe' 로(글 · 주소 · 권한은 그대로).
-- 실행 계정: tbox_owner (표 소유자 — 2026-10-13_community_page_attachments.sql 과 같다). 슈퍼유저로 돌리면 아래 9 절이 소유자를 고정한다.
-- 새 설치는 db/community/schema.sql 끝 「카페」 절이 같은 모양을 만들고, 앱 권한은 db/community/grants.sql 끝 절이 다시 건다.
--
--   community.spaces.area          'work' | 'cafe' (기본 'work') · CHECK 게시판만 cafe 가 될 수 있다
--   community.spaces.board_kind    'anonymous' 더함(spaces_board_kind_check 를 다시 만든다) · 익명은 카페에만
--   community.anon_aliases         익명게시판 글마다 사람 → 번호(글쓴이 0 · 댓글 쓴 사람 1, 2, …) — 앱 SELECT · INSERT 만
--   community.reports              신고 — 앱 SELECT · INSERT · UPDATE(상태 열 넷) · ★ DELETE 없음
--   community.board_requests       게시판 만들어 주세요 — 앱 SELECT · INSERT · UPDATE(상태 열 다섯) · ★ DELETE 없음
--   community.notifications.kind   'moderated' · 'board_request' · 'board_request_done' (누적 CHECK — 2026-10-19 방식)
--   데이터                         자유게시판(discussion) → area='cafe' · 새 「익명게시판」(internal · anonymous · cafe)
--
-- 되돌리기(코드만 되돌리면 옛 코드는 새 열 · 표를 읽지 않는다 — 단, 옛 코드는 board_kind 'anonymous' 를 모른다:
--   BOARD_KINDS 밖 종류의 게시판은 옛 화면에서 종류 표시만 비고 판정은 discussion 과 같은 갈래가 없어 쓰기가 닫힌다).
--   익명게시판을 걷어 낼 때만, 소유자로(글이 0 인지 먼저 본다):
--     UPDATE community.spaces SET is_active = false WHERE kind='board' AND board_kind='anonymous';   -- 보관(지우지 않는다)
--     UPDATE community.spaces SET area='work' WHERE kind='board' AND lower(name)='자유게시판';        -- 자리 되돌리기
--   표 · 열을 걷어 낼 때만(신고 · 요청 기록을 먼저 백업한다):
--     DROP TABLE community.board_requests, community.reports, community.anon_aliases;
--     (spaces.area · board_kind CHECK 는 남겨도 해가 없다 — 지우려면 anonymous 행이 0 인지 먼저 본다)
--     (notifications 의 세 종류는 남겨도 해가 없다 — 지우려면 그 kind 의 행이 0 인지 먼저 본다)

\set ON_ERROR_STOP on
BEGIN;
SET LOCAL lock_timeout = '5s';

-- ── 1. 게시판 자리 (ARCH 25.2) ──
ALTER TABLE community.spaces ADD COLUMN IF NOT EXISTS area TEXT NOT NULL DEFAULT 'work';
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint
                    WHERE conname = 'spaces_area_check' AND conrelid = 'community.spaces'::regclass) THEN
        ALTER TABLE community.spaces ADD CONSTRAINT spaces_area_check CHECK (area IN ('work','cafe'));
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_constraint
                    WHERE conname = 'spaces_area_board' AND conrelid = 'community.spaces'::regclass) THEN
        -- 카페는 게시판만 — 개인 · 팀 공간은 언제나 업무 자리다.
        ALTER TABLE community.spaces ADD CONSTRAINT spaces_area_board CHECK (kind = 'board' OR area = 'work');
    END IF;
END
$$;

-- ── 2. 게시판 종류에 'anonymous' (spaces_board_kind_check 를 다시 만든다 — 2026-09-24 이주와 같은 이름) ──
DO $$
DECLARE c record;
BEGIN
    FOR c IN SELECT conname, pg_get_constraintdef(oid) AS definition FROM pg_constraint
              WHERE conrelid = 'community.spaces'::regclass AND contype = 'c'
                AND conname = 'spaces_board_kind_check'
    LOOP
        IF position('''anonymous''' in c.definition) = 0 THEN
            ALTER TABLE community.spaces DROP CONSTRAINT spaces_board_kind_check;
        END IF;
    END LOOP;
    IF NOT EXISTS (SELECT 1 FROM pg_constraint
                    WHERE conname = 'spaces_board_kind_check' AND conrelid = 'community.spaces'::regclass) THEN
        ALTER TABLE community.spaces ADD CONSTRAINT spaces_board_kind_check
            CHECK (board_kind IS NULL OR board_kind IN ('notice','discussion','qa','anonymous'));
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_constraint
                    WHERE conname = 'spaces_board_anon_cafe' AND conrelid = 'community.spaces'::regclass) THEN
        -- 익명게시판은 카페에만(업무 자리에 익명 글이 생기지 않게).
        ALTER TABLE community.spaces ADD CONSTRAINT spaces_board_anon_cafe
            CHECK (board_kind IS DISTINCT FROM 'anonymous' OR area = 'cafe');
    END IF;
END
$$;

-- ── 3. 자유게시판을 카페로 · 새 익명게시판 (ARCH 25.2 「이주가 할 일」) ──
--   자유게시판: kind='board' AND board_kind='discussion' AND lower(name)='자유게시판' — 0행이면 아무것도 안 함 · 1행 넘으면 중단.
DO $$
DECLARE n integer;
BEGIN
    SELECT count(*) INTO n FROM community.spaces
     WHERE kind = 'board' AND board_kind = 'discussion' AND lower(name) = lower('자유게시판');
    IF n > 1 THEN
        RAISE EXCEPTION '자유게시판이 % 개다 — 사람이 보고 정한다(이 이주는 중단)', n;
    END IF;
    IF n = 1 THEN
        UPDATE community.spaces SET area = 'cafe', updated_at = now()
         WHERE kind = 'board' AND board_kind = 'discussion' AND lower(name) = lower('자유게시판') AND area <> 'cafe';
    END IF;
END
$$;

INSERT INTO community.spaces (kind, name, description, access_level, notice, board_kind, area, created_by)
SELECT 'board', '익명게시판', '이름 없이 이야기를 나눕니다. 화면에서만 익명이에요 — 신고가 들어오면 관리자가 쓴 사람을 확인할 수 있어요.',
       'internal', false, 'anonymous', 'cafe', NULL
 WHERE NOT EXISTS (SELECT 1 FROM community.spaces s WHERE s.kind = 'board' AND lower(s.name) = lower('익명게시판'));

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

-- ── 8. 권한 (db/community/grants.sql 끝 절과 같은 줄) ──
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'tbox_app') THEN
        REVOKE ALL ON community.anon_aliases, community.reports, community.board_requests FROM tbox_app;
        GRANT SELECT, INSERT ON community.anon_aliases   TO tbox_app;
        GRANT SELECT, INSERT ON community.reports        TO tbox_app;
        GRANT UPDATE (status, resolved_by, resolution_note, resolved_at) ON community.reports TO tbox_app;
        GRANT SELECT, INSERT ON community.board_requests TO tbox_app;
        GRANT UPDATE (status, decided_by, decision_note, board_id, decided_at) ON community.board_requests TO tbox_app;
        GRANT USAGE, SELECT ON SEQUENCE community.reports_id_seq, community.board_requests_id_seq TO tbox_app;
    END IF;
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'tbox_backup') THEN
        REVOKE ALL ON community.anon_aliases, community.reports, community.board_requests FROM tbox_backup;
        GRANT SELECT ON community.anon_aliases, community.reports, community.board_requests TO tbox_backup;
        GRANT SELECT ON SEQUENCE community.reports_id_seq, community.board_requests_id_seq TO tbox_backup;
    END IF;
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'tbox_perm') THEN
        EXECUTE 'REVOKE ALL ON community.anon_aliases, community.reports, community.board_requests FROM tbox_perm';
    END IF;
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'tbox_push') THEN
        EXECUTE 'REVOKE ALL ON community.anon_aliases, community.reports, community.board_requests FROM tbox_push';
    END IF;
END
$$;

-- ── 9. 소유자 — 슈퍼유저로 돌렸을 때만(tbox_owner 로 돌리면 이미 소유자다). 합성 시험 DB(소유자 tbox_testdb)에서는 건너뛴다 ──
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'tbox_owner')
       AND (SELECT rolsuper FROM pg_roles WHERE rolname = current_user) THEN
        ALTER TABLE community.anon_aliases   OWNER TO tbox_owner;
        ALTER TABLE community.reports        OWNER TO tbox_owner;
        ALTER TABLE community.board_requests OWNER TO tbox_owner;
    END IF;
END
$$;

COMMIT;
