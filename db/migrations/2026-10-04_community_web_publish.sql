-- Stage 12 D. Public forms use stage 11 form metadata and no stored raw token.
\set ON_ERROR_STOP on
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
