-- db/migrations/2026-10-13_community_page_attachments.sql — 문서 전용 첨부 (사이트 Q:F-12 U3-1 · WK-25 · WK-27)
--
-- 요구사항: ../../../community/ARCHITECTURE.md 24장(24.2 표 · 권한 · 24.6 수명) · ../../../community/work-ux-plan.md 15.4 C ·
--           지시서 docs/qf12-u3-directive.md 「U3-1 보안 바닥」.
--
-- 추가 전용 · 되풀이 안전(CREATE … IF NOT EXISTS · CREATE OR REPLACE · REVOKE/GRANT 멱등). 기존 행을 지우거나 고치지 않는다.
-- 실행 계정: tbox_owner (표 소유자 — 2026-10-19_qf12_work.sql 과 같다). 슈퍼유저로 돌리면 아래 5 절이 소유자를 tbox_owner 로 고정한다.
-- 새 설치는 db/community/schema.sql 의 「문서 전용 첨부」 절이 같은 표를 만들고, 앱 권한은 db/community/grants.sql 끝 절이 다시 건다.
--
--   community.page_attachments              문서(페이지) 하나에 딸린 파일 — 원래 이름은 이 표에만, 디스크 이름은 난수 uuid.
--                                           앱(tbox_app): SELECT · INSERT · UPDATE(scan_status 한 칸만) — ★ DELETE 없음
--   community.page_attachment_purge_queue   영구 삭제가 지운 첨부 행의 디스크 이름 대기열(24.6 「행을 지우기 전에 경로 목록을
--                                           받아 두는 순서」). purge_trash 가 행을 지우며 이름을 여기 적고, scripts/community_purge.php
--                                           (소유자 연결)가 디스크 파일을 지운 뒤 줄을 지운다. 앱 권한 0.
--   community.purge_trash(dry, max)         지금 몸(2026-10-01_community_suggestions.sql)을 글자 그대로 + 첨부 두 줄
--                                           (대기열에 이름 적기 → 첨부 행 지우기). CREATE OR REPLACE 라 소유자 · EXECUTE 권한은 그대로다.
--
-- 되돌리기: 코드만 되돌리면 된다(표가 남아도 옛 코드는 읽지 않는다). 함수는 2026-10-01_community_suggestions.sql 의
--   함수 부분을 다시 돌리면 옛 몸으로 돌아간다. 표를 걷어 낼 때만, 소유자로(첨부 행 · 디스크 파일이 0 인지 먼저 본다):
--   DROP TABLE community.page_attachment_purge_queue, community.page_attachments;

\set ON_ERROR_STOP on
BEGIN;
SET LOCAL lock_timeout = '5s';

-- ── 1. 첨부 표 (ARCH 24.2) ──
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

-- ── 2. 영구 삭제 디스크 이름 대기열 (ARCH 24.6) ──
CREATE TABLE IF NOT EXISTS community.page_attachment_purge_queue (
    stored_name TEXT        PRIMARY KEY CONSTRAINT page_attachment_purge_queue_stored_check
                            CHECK (stored_name ~ '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\.[a-z0-9]{1,10}$'),
    queued_at   TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- ── 3. 30일 정리 함수 — 지금 몸 + 첨부 두 줄 (대기열에 이름 → 행 지우기, 같은 트랜잭션 · 같은 잠금) ──
CREATE OR REPLACE FUNCTION community.purge_trash(p_dry boolean DEFAULT true, p_max integer DEFAULT 500)
RETURNS TABLE (page_id bigint, page_public_id uuid, was_title text)
LANGUAGE plpgsql
SECURITY INVOKER
SET search_path = community, public
AS $fn$
DECLARE
    ids bigint[];
BEGIN
    IF p_max IS NULL OR p_max < 1 OR p_max > 5000 THEN
        RAISE EXCEPTION 'p_max 는 1~5000';
    END IF;
    -- 고른 행을 **잠근다**(독립 검토 M-1) — 되살리기와 겨루면 한쪽이 기다리고, 잠긴 것은 이번에 건너뛴다
    SELECT coalesce(array_agg(x.id ORDER BY x.id), '{}') INTO ids
      FROM (SELECT p.id FROM community.pages p
             WHERE p.deleted_at IS NOT NULL AND p.purged_at IS NULL
               AND p.purge_after IS NOT NULL AND p.purge_after < now()
             ORDER BY p.id LIMIT p_max
             FOR UPDATE SKIP LOCKED) x;

    RETURN QUERY SELECT p.id, p.public_id, p.title FROM community.pages p WHERE p.id = ANY (ids) ORDER BY p.id;
    IF p_dry OR cardinality(ids) = 0 THEN
        RETURN;
    END IF;

    DELETE FROM community.notifications n
     WHERE n.page_id = ANY (ids)
        OR n.comment_id IN (SELECT c.id FROM community.comments c WHERE c.page_id = ANY (ids));
    DELETE FROM community.comment_reactions r
     WHERE r.comment_id IN (SELECT c.id FROM community.comments c WHERE c.page_id = ANY (ids));
    -- 답글(부모를 가리킴)부터
    DELETE FROM community.comments c WHERE c.page_id = ANY (ids) AND c.parent_comment_id IS NOT NULL;
    DELETE FROM community.comments c WHERE c.page_id = ANY (ids);
    DELETE FROM community.page_suggestions s WHERE s.page_id = ANY (ids);
    DELETE FROM community.page_versions v      WHERE v.page_id = ANY (ids);
    DELETE FROM community.page_blocks b        WHERE b.page_id = ANY (ids);
    DELETE FROM community.page_links l         WHERE l.from_page_id = ANY (ids);
    DELETE FROM community.page_edit_sessions s WHERE s.page_id = ANY (ids);
    DELETE FROM community.page_shares s        WHERE s.page_id = ANY (ids);
    DELETE FROM community.page_tags t          WHERE t.page_id = ANY (ids);
    DELETE FROM community.page_views w         WHERE w.page_id = ANY (ids);
    DELETE FROM community.page_reads r         WHERE r.page_id = ANY (ids);
    -- Q:F-3a 라운드 B — 즐겨찾기·최근(개인 화면 설정)도 함께. 페이지 행은 남으므로 외래 키 CASCADE 가 불리지 않는다
    DELETE FROM community.page_favorites fv    WHERE fv.page_id = ANY (ids);
    DELETE FROM community.page_recent rc       WHERE rc.page_id = ANY (ids);
    DELETE FROM community.page_property_events e WHERE e.page_id = ANY (ids) AND e.prop LIKE 'db:%';
    -- Q:F-12 U3-1 (ARCH 24.6) — 문서 전용 첨부: 디스크 이름을 대기열에 **먼저** 적고 행을 지운다(한 트랜잭션).
    --   디스크 파일은 scripts/community_purge.php 가 대기열을 읽어 지운다 — 지운 뒤에야 대기열 줄을 지운다(중간에 죽어도 다음 실행이 잇는다).
    INSERT INTO community.page_attachment_purge_queue (stored_name)
         SELECT a.stored_name FROM community.page_attachments a WHERE a.page_id = ANY (ids)
    ON CONFLICT (stored_name) DO NOTHING;
    DELETE FROM community.page_attachments a   WHERE a.page_id = ANY (ids);
    UPDATE community.databases d SET schema = '[]'::jsonb, views = '[]'::jsonb, updated_at = now()
     WHERE d.page_id = ANY (ids);
    UPDATE community.pages p
       SET title = '영구 삭제된 페이지', icon = NULL,
           doc = '{"schema":1,"blocks":[]}'::jsonb, plain_text = '', search_tsv = NULL, block_count = 0,
           database_id = NULL, properties = NULL,
           purged_at = now()
     WHERE p.id = ANY (ids)
       AND p.deleted_at IS NOT NULL AND p.purged_at IS NULL;
    -- 감사는 이 함수가 아니라 부르는 쪽(scripts/community_purge.php)이 앱 감사(Audit — DB + 감사 파일 해시 체인)로 남긴다.
END
$fn$;

REVOKE ALL ON FUNCTION community.purge_trash(boolean, integer) FROM PUBLIC;

-- ── 4. 권한 (db/community/grants.sql 끝 절과 같은 줄) ──
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'tbox_app') THEN
        REVOKE ALL ON community.page_attachments, community.page_attachment_purge_queue FROM tbox_app;
        GRANT SELECT, INSERT           ON community.page_attachments TO tbox_app;
        GRANT UPDATE (scan_status)     ON community.page_attachments TO tbox_app;
        GRANT USAGE, SELECT ON SEQUENCE community.page_attachments_id_seq TO tbox_app;
    END IF;
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'tbox_backup') THEN
        REVOKE ALL ON community.page_attachments, community.page_attachment_purge_queue FROM tbox_backup;
        GRANT SELECT ON community.page_attachments, community.page_attachment_purge_queue TO tbox_backup;
        GRANT SELECT ON SEQUENCE community.page_attachments_id_seq TO tbox_backup;
    END IF;
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'tbox_perm') THEN
        EXECUTE 'REVOKE ALL ON community.page_attachments, community.page_attachment_purge_queue FROM tbox_perm';
    END IF;
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'tbox_push') THEN
        EXECUTE 'REVOKE ALL ON community.page_attachments, community.page_attachment_purge_queue FROM tbox_push';
    END IF;
END
$$;

-- ── 5. 소유자 — 슈퍼유저로 돌렸을 때만(tbox_owner 로 돌리면 이미 소유자다). purge_trash(INVOKER)를 부르는 tbox_owner 가
--      두 표를 지울 수 있어야 한다. 합성 시험 DB(소유자 tbox_testdb · 슈퍼유저 아님)에서는 건너뛴다.
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'tbox_owner')
       AND (SELECT rolsuper FROM pg_roles WHERE rolname = current_user) THEN
        ALTER TABLE community.page_attachments OWNER TO tbox_owner;
        ALTER TABLE community.page_attachment_purge_queue OWNER TO tbox_owner;
        ALTER FUNCTION community.purge_trash(boolean, integer) OWNER TO tbox_owner;
    END IF;
END
$$;

COMMIT;
