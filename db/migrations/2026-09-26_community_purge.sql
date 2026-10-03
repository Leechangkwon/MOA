-- db/migrations/2026-09-26_community_purge.sql
--   커뮤니티 7-A — 휴지통 30일 정리 (C:F-29 · T-32, ARCH 21.19)
--
-- 요구사항: ../../../community/stage7-finish-directive.md 「7-A」 · 결정 J-1(내용은 지우고 메타 기록은 남긴다)·J-2
--
-- 실행 계정: tbox_owner (표 소유자). 이 파일 뒤에 `db/community/grants.sql` 을 한 번 더(권한 변화 없음 — 확인용).
--
-- 하는 일 (여러 번 돌려도 같다)
--   1. pages.purged_at — 영구 삭제한 때. 페이지 **행은 남긴다**(기록 표의 FK 가 끊기지 않게) — 제목·본문·속성은 비운다
--   2. 함수 community.purge_trash(dry, max) — `purge_after` 가 지난 휴지통 페이지를 정리한다. **앱 계정은 부를 수 없다**
--      (EXECUTE 는 PUBLIC 에서 회수 · 소유자 tbox_owner 고정). 부르는 것은 `scripts/community_purge.php`(root cron,
--      소유자 pgpass) — 결과를 앱 감사 `page.purge` 로 남긴다(DB 줄 + 감사 파일, 해시 체인 그대로).
--
--   지우는 것(내용이 든 것): page_versions · comments(+ comment_reactions · 그 댓글·페이지의 notifications) · page_blocks ·
--     page_links(이 페이지에서 나가는 것) · page_edit_sessions · page_shares · page_tags · page_views · page_reads ·
--     page_property_events 중 DB 속성 값 줄(`db:%` — 값이 글자로 남아 있다)
--   남기는 것(메타 기록 — C:S-14): checklist_events · page_property_events(상태·게시·숨김·해결됨) · handovers · 감사
--   DB 페이지면 databases 줄의 스키마·뷰를 비운다(줄은 남긴다 — 행들의 FK). 행이면 database_id·properties 를 비운다.
--
-- 되돌리기: 영구 삭제는 되돌릴 수 없다(그래서 dry 가 기본값이다). 함수·열만 없애려면
--   DROP FUNCTION community.purge_trash(boolean, integer); ALTER TABLE community.pages DROP COLUMN purged_at;

\set ON_ERROR_STOP on

BEGIN;

ALTER TABLE community.pages ADD COLUMN IF NOT EXISTS purged_at TIMESTAMPTZ;
CREATE INDEX IF NOT EXISTS pages_purge_due_idx ON community.pages (purge_after)
    WHERE deleted_at IS NOT NULL AND purged_at IS NULL;

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

    IF to_regclass('community.synced_sources') IS NOT NULL THEN
        DELETE FROM community.synced_references WHERE page_id=ANY(ids);
        DELETE FROM community.synced_sources WHERE page_id=ANY(ids);
    END IF;
    DELETE FROM community.notifications n
     WHERE n.page_id = ANY (ids)
        OR n.comment_id IN (SELECT c.id FROM community.comments c WHERE c.page_id = ANY (ids));
    DELETE FROM community.comment_reactions r
     WHERE r.comment_id IN (SELECT c.id FROM community.comments c WHERE c.page_id = ANY (ids));
    -- 답글(부모를 가리킴)부터
    DELETE FROM community.comments c WHERE c.page_id = ANY (ids) AND c.parent_comment_id IS NOT NULL;
    DELETE FROM community.comments c WHERE c.page_id = ANY (ids);
    DELETE FROM community.page_versions v      WHERE v.page_id = ANY (ids);
    DELETE FROM community.page_blocks b        WHERE b.page_id = ANY (ids);
    DELETE FROM community.page_links l         WHERE l.from_page_id = ANY (ids);
    DELETE FROM community.page_edit_sessions s WHERE s.page_id = ANY (ids);
    DELETE FROM community.page_shares s        WHERE s.page_id = ANY (ids);
    DELETE FROM community.page_tags t          WHERE t.page_id = ANY (ids);
    DELETE FROM community.page_views w         WHERE w.page_id = ANY (ids);
    DELETE FROM community.page_reads r         WHERE r.page_id = ANY (ids);
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
-- 소유자를 표 소유자로 고정한다(독립 검토 M-2) — postgres 로 돌려도 cron(tbox_owner)이 부를 수 있게
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'tbox_owner') THEN
        ALTER FUNCTION community.purge_trash(boolean, integer) OWNER TO tbox_owner;
    END IF;
END
$$;

COMMIT;
