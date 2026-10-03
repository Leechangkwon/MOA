-- Stage 12 A/B/C. Run as tbox_owner; repeated application is safe.
\set ON_ERROR_STOP on
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
