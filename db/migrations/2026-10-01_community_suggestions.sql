-- C:F-46 / C:S-18. Add-only, rerunnable migration; no existing content is removed.
-- Rollback: revert application first; keep proposal rows for reapplication.
-- To restore the previous purge function, use the commented original body below.
\set ON_ERROR_STOP on
BEGIN;
SET LOCAL lock_timeout = '5s';
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
REVOKE ALL ON community.page_suggestions FROM tbox_app, tbox_backup;
GRANT SELECT, INSERT ON community.page_suggestions TO tbox_app;
GRANT UPDATE(status,decided_by,decided_at,decision_note,applied_version) ON community.page_suggestions TO tbox_app;
GRANT USAGE, SELECT ON SEQUENCE community.page_suggestions_id_seq TO tbox_app;
GRANT SELECT ON community.page_suggestions TO tbox_backup;
GRANT SELECT ON SEQUENCE community.page_suggestions_id_seq TO tbox_backup;
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
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'tbox_owner') THEN
        ALTER FUNCTION community.purge_trash(boolean, integer) OWNER TO tbox_owner;
    END IF;
END
$$;

COMMIT;

-- Previous purge body for rollback:
-- CREATE OR REPLACE FUNCTION community.purge_trash(p_dry boolean DEFAULT true, p_max integer DEFAULT 500)
-- RETURNS TABLE (page_id bigint, page_public_id uuid, was_title text)
-- LANGUAGE plpgsql
-- SECURITY INVOKER
-- SET search_path = community, public
-- AS $fn$
-- DECLARE
--     ids bigint[];
-- BEGIN
--     IF p_max IS NULL OR p_max < 1 OR p_max > 5000 THEN
--         RAISE EXCEPTION 'p_max 는 1~5000';
--     END IF;
--     -- 고른 행을 **잠근다**(독립 검토 M-1) — 되살리기와 겨루면 한쪽이 기다리고, 잠긴 것은 이번에 건너뛴다
--     SELECT coalesce(array_agg(x.id ORDER BY x.id), '{}') INTO ids
--       FROM (SELECT p.id FROM community.pages p
--              WHERE p.deleted_at IS NOT NULL AND p.purged_at IS NULL
--                AND p.purge_after IS NOT NULL AND p.purge_after < now()
--              ORDER BY p.id LIMIT p_max
--              FOR UPDATE SKIP LOCKED) x;
-- 
--     RETURN QUERY SELECT p.id, p.public_id, p.title FROM community.pages p WHERE p.id = ANY (ids) ORDER BY p.id;
--     IF p_dry OR cardinality(ids) = 0 THEN
--         RETURN;
--     END IF;
-- 
--     DELETE FROM community.notifications n
--      WHERE n.page_id = ANY (ids)
--         OR n.comment_id IN (SELECT c.id FROM community.comments c WHERE c.page_id = ANY (ids));
--     DELETE FROM community.comment_reactions r
--      WHERE r.comment_id IN (SELECT c.id FROM community.comments c WHERE c.page_id = ANY (ids));
--     -- 답글(부모를 가리킴)부터
--     DELETE FROM community.comments c WHERE c.page_id = ANY (ids) AND c.parent_comment_id IS NOT NULL;
--     DELETE FROM community.comments c WHERE c.page_id = ANY (ids);
--     DELETE FROM community.page_versions v      WHERE v.page_id = ANY (ids);
--     DELETE FROM community.page_blocks b        WHERE b.page_id = ANY (ids);
--     DELETE FROM community.page_links l         WHERE l.from_page_id = ANY (ids);
--     DELETE FROM community.page_edit_sessions s WHERE s.page_id = ANY (ids);
--     DELETE FROM community.page_shares s        WHERE s.page_id = ANY (ids);
--     DELETE FROM community.page_tags t          WHERE t.page_id = ANY (ids);
--     DELETE FROM community.page_views w         WHERE w.page_id = ANY (ids);
--     DELETE FROM community.page_reads r         WHERE r.page_id = ANY (ids);
--     -- Q:F-3a 라운드 B — 즐겨찾기·최근(개인 화면 설정)도 함께. 페이지 행은 남으므로 외래 키 CASCADE 가 불리지 않는다
--     DELETE FROM community.page_favorites fv    WHERE fv.page_id = ANY (ids);
--     DELETE FROM community.page_recent rc       WHERE rc.page_id = ANY (ids);
--     DELETE FROM community.page_property_events e WHERE e.page_id = ANY (ids) AND e.prop LIKE 'db:%';
--     UPDATE community.databases d SET schema = '[]'::jsonb, views = '[]'::jsonb, updated_at = now()
--      WHERE d.page_id = ANY (ids);
--     UPDATE community.pages p
--        SET title = '영구 삭제된 페이지', icon = NULL,
--            doc = '{"schema":1,"blocks":[]}'::jsonb, plain_text = '', search_tsv = NULL, block_count = 0,
--            database_id = NULL, properties = NULL,
--            purged_at = now()
--      WHERE p.id = ANY (ids)
--        AND p.deleted_at IS NOT NULL AND p.purged_at IS NULL;
--     -- 감사는 이 함수가 아니라 부르는 쪽(scripts/community_purge.php)이 앱 감사(Audit — DB + 감사 파일 해시 체인)로 남긴다.
-- END
-- $fn$;
-- 
-- REVOKE ALL ON FUNCTION community.purge_trash(boolean, integer) FROM PUBLIC;
-- DO $$
-- BEGIN
--     IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'tbox_owner') THEN
--         ALTER FUNCTION community.purge_trash(boolean, integer) OWNER TO tbox_owner;
--     END IF;
-- END
-- $$;
