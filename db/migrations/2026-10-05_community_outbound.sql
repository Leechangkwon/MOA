-- Stage 12 E/F. Secret URLs/keys are fixed server files, never database columns.
\set ON_ERROR_STOP on
BEGIN;
CREATE TABLE IF NOT EXISTS community.messenger_hooks (
 id bigserial PRIMARY KEY,name text NOT NULL CHECK(length(name) BETWEEN 1 AND 50),url_host text NOT NULL CHECK(url_host ~ '^[a-z0-9][a-z0-9.-]+$'),
 is_active boolean NOT NULL DEFAULT false,created_by bigint NOT NULL REFERENCES public.users(id),created_at timestamptz NOT NULL DEFAULT now());
CREATE TABLE IF NOT EXISTS community.user_messenger (
 user_id bigint REFERENCES public.users(id),hook_id bigint REFERENCES community.messenger_hooks(id),kinds text[] NOT NULL DEFAULT '{}',PRIMARY KEY(user_id,hook_id));
CREATE TABLE IF NOT EXISTS community.notification_outbox (
 id bigserial PRIMARY KEY,notification_id bigint REFERENCES community.notifications(id) ON DELETE CASCADE,hook_id bigint REFERENCES community.messenger_hooks(id),
 status text NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','sent','skipped','failed')),tries integer NOT NULL DEFAULT 0,
 next_at timestamptz NOT NULL DEFAULT now(),sent_at timestamptz,last_reason text,UNIQUE(notification_id,hook_id));
CREATE TABLE IF NOT EXISTS community.data_connectors (
 id bigserial PRIMARY KEY,public_id uuid NOT NULL UNIQUE DEFAULT gen_random_uuid(),name text NOT NULL CHECK(length(name) BETWEEN 1 AND 50),
 database_id bigint NOT NULL REFERENCES community.databases(id),url_host text NOT NULL,url_path text NOT NULL,
 runner_user_id bigint NOT NULL REFERENCES public.users(id),schedule jsonb NOT NULL,field_map jsonb NOT NULL,
 is_active boolean NOT NULL DEFAULT false,created_by bigint NOT NULL REFERENCES public.users(id),created_at timestamptz NOT NULL DEFAULT now(),last_run_at timestamptz,last_status text);
ALTER TABLE community.pages ADD COLUMN IF NOT EXISTS external_key text;
ALTER TABLE community.pages ADD COLUMN IF NOT EXISTS external_missing boolean NOT NULL DEFAULT false;
ALTER TABLE community.pages ADD COLUMN IF NOT EXISTS external_connector_id bigint REFERENCES community.data_connectors(id);
CREATE UNIQUE INDEX IF NOT EXISTS pages_external_key ON community.pages(database_id,external_key);
CREATE OR REPLACE FUNCTION community.enqueue_messenger() RETURNS trigger LANGUAGE plpgsql SECURITY INVOKER SET search_path=pg_catalog,community AS $$
 BEGIN INSERT INTO community.notification_outbox(notification_id,hook_id)
 SELECT NEW.id,m.hook_id FROM community.user_messenger m JOIN community.messenger_hooks h ON h.id=m.hook_id
 WHERE m.user_id=NEW.user_id AND NEW.kind=ANY(m.kinds) AND h.is_active;
 RETURN NEW; END $$;
DROP TRIGGER IF EXISTS notifications_messenger ON community.notifications;
CREATE TRIGGER notifications_messenger AFTER INSERT ON community.notifications FOR EACH ROW EXECUTE FUNCTION community.enqueue_messenger();
REVOKE ALL ON FUNCTION community.enqueue_messenger() FROM PUBLIC;
COMMIT;
