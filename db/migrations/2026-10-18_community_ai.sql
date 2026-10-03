\set ON_ERROR_STOP on
BEGIN;
SET LOCAL lock_timeout='5s';

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

-- AI history is append-only for every application connection.
REVOKE ALL ON community.ai_settings,community.ai_page_permissions,community.ai_requests,community.ai_log,community.page_embeddings,community.embedding_queue FROM PUBLIC,tbox_app,tbox_backup;
GRANT SELECT,UPDATE ON community.ai_settings TO tbox_app;
GRANT SELECT,INSERT,UPDATE,DELETE ON community.ai_page_permissions,community.page_embeddings,community.embedding_queue TO tbox_app;
GRANT SELECT,INSERT ON community.ai_requests,community.ai_log TO tbox_app;
GRANT USAGE,SELECT ON SEQUENCE community.ai_requests_id_seq,community.ai_log_id_seq TO tbox_app;
GRANT SELECT ON community.ai_settings,community.ai_page_permissions,community.ai_requests,community.ai_log,community.page_embeddings,community.embedding_queue TO tbox_backup;
GRANT SELECT ON SEQUENCE community.ai_requests_id_seq,community.ai_log_id_seq TO tbox_backup;

-- Owner-only trigger execution blanks retained AI content at actual page/file purge.
-- It extends the existing purge lifecycle without replacing other stages' purge function.
CREATE OR REPLACE FUNCTION community.ai_scrub_purged() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,community,public AS $fn$
BEGIN
    IF OLD.purged_at IS NULL AND NEW.purged_at IS NOT NULL THEN
        IF TG_TABLE_SCHEMA='community' THEN
            UPDATE community.ai_log SET input_text='',output_text='',scrubbed_at=now()
             WHERE page_refs @> jsonb_build_array(jsonb_build_object('page_id',NEW.public_id::text));
            DELETE FROM community.page_embeddings WHERE page_id=NEW.id;
            DELETE FROM community.embedding_queue WHERE page_id=NEW.id;
            DELETE FROM community.ai_page_permissions WHERE page_id=NEW.id;
        ELSE
            UPDATE community.ai_log SET input_text='',output_text='',scrubbed_at=now()
             WHERE file_refs @> jsonb_build_array(jsonb_build_object('file_id',NEW.public_id::text));
        END IF;
    END IF;
    RETURN NEW;
END
$fn$;
REVOKE ALL ON FUNCTION community.ai_scrub_purged() FROM PUBLIC,tbox_app,tbox_backup;
ALTER FUNCTION community.ai_scrub_purged() OWNER TO tbox_owner;
DROP TRIGGER IF EXISTS ai_page_purge ON community.pages;
CREATE TRIGGER ai_page_purge AFTER UPDATE OF purged_at ON community.pages FOR EACH ROW EXECUTE FUNCTION community.ai_scrub_purged();
DROP TRIGGER IF EXISTS ai_file_purge ON public.files;
CREATE TRIGGER ai_file_purge AFTER UPDATE OF purged_at ON public.files FOR EACH ROW EXECUTE FUNCTION community.ai_scrub_purged();

COMMIT;
