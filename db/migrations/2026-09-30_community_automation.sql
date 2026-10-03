-- Stage 9: additive/idempotent. Rollback application first; preserve rules and immutable run history.
\set ON_ERROR_STOP on
BEGIN;
ALTER TABLE community.pages ADD COLUMN IF NOT EXISTS due_date DATE;
ALTER TABLE community.notifications ADD COLUMN IF NOT EXISTS retracted_at TIMESTAMPTZ;
CREATE TABLE IF NOT EXISTS community.automations (
 id BIGSERIAL PRIMARY KEY, public_id UUID NOT NULL UNIQUE,
 owner_user_id BIGINT NOT NULL REFERENCES public.users(id),
 kind TEXT NOT NULL CHECK(kind IN ('recurring','on_status','due_reminder','button')),
 name TEXT NOT NULL CHECK(char_length(name) BETWEEN 1 AND 100),
 scope_page_id BIGINT REFERENCES community.pages(id), scope_space_id BIGINT REFERENCES community.spaces(id),
 trigger JSONB NOT NULL CHECK(jsonb_typeof(trigger)='object'),
 actions JSONB NOT NULL CHECK(jsonb_typeof(actions)='array' AND jsonb_array_length(actions) BETWEEN 1 AND 5),
 press_scope TEXT CHECK(press_scope IN ('owner','editors')),
 is_enabled BOOLEAN NOT NULL DEFAULT false, last_dry_run_at TIMESTAMPTZ,
 denied_streak INTEGER NOT NULL DEFAULT 0 CHECK(denied_streak>=0),
 created_at TIMESTAMPTZ NOT NULL DEFAULT now(), updated_at TIMESTAMPTZ NOT NULL DEFAULT now(), deleted_at TIMESTAMPTZ,
 CONSTRAINT automations_press_shape CHECK ((kind='button' AND press_scope IS NOT NULL) OR (kind<>'button' AND press_scope IS NULL)),
 CONSTRAINT automations_scope_shape CHECK (scope_page_id IS NULL OR scope_space_id IS NULL)
);
CREATE TABLE IF NOT EXISTS community.automation_runs (
 id BIGSERIAL PRIMARY KEY, public_id UUID NOT NULL UNIQUE,
 automation_id BIGINT NOT NULL REFERENCES community.automations(id), run_by BIGINT NOT NULL REFERENCES public.users(id),
 trigger_kind TEXT NOT NULL, slot TIMESTAMPTZ, dry_run BOOLEAN NOT NULL,
 started_at TIMESTAMPTZ NOT NULL DEFAULT now(), finished_at TIMESTAMPTZ NOT NULL DEFAULT now(),
 status TEXT NOT NULL CHECK(status IN ('ok','partial','denied','failed')),
 steps JSONB NOT NULL CHECK(jsonb_typeof(steps)='array'),
 created_page_ids BIGINT[] NOT NULL DEFAULT '{}', notification_ids BIGINT[] NOT NULL DEFAULT '{}',
 undone_at TIMESTAMPTZ, undone_by BIGINT REFERENCES public.users(id),
 CHECK((undone_at IS NULL)=(undone_by IS NULL))
);
CREATE UNIQUE INDEX IF NOT EXISTS automation_runs_slot_uidx ON community.automation_runs(automation_id,slot) WHERE NOT dry_run AND slot IS NOT NULL;
CREATE INDEX IF NOT EXISTS automation_runs_rule_idx ON community.automation_runs(automation_id,started_at DESC);
CREATE TABLE IF NOT EXISTS community.automation_queue (
 id BIGSERIAL PRIMARY KEY, automation_id BIGINT NOT NULL REFERENCES community.automations(id),
 page_id BIGINT NOT NULL REFERENCES community.pages(id), event_id BIGINT NOT NULL REFERENCES community.page_property_events(id),
 created_at TIMESTAMPTZ NOT NULL DEFAULT now(), taken_at TIMESTAMPTZ,
 UNIQUE(automation_id,event_id)
);
CREATE INDEX IF NOT EXISTS automation_queue_pending_idx ON community.automation_queue(id) WHERE taken_at IS NULL;
CREATE INDEX IF NOT EXISTS automations_enabled_idx ON community.automations(is_enabled,kind) WHERE deleted_at IS NULL;
CREATE INDEX IF NOT EXISTS pages_due_idx ON community.pages(due_date) WHERE due_date IS NOT NULL AND deleted_at IS NULL;
DO $$ DECLARE r record; BEGIN
 FOR r IN SELECT conname FROM pg_constraint WHERE conrelid='community.page_property_events'::regclass AND contype='c' AND pg_get_constraintdef(oid) LIKE '%prop%' LOOP
  EXECUTE format('ALTER TABLE community.page_property_events DROP CONSTRAINT %I',r.conname);
 END LOOP;
 ALTER TABLE community.page_property_events ADD CONSTRAINT page_property_events_prop_check
  CHECK(prop IN ('status','assignee','publish','hide','resolved','verified','kind','due') OR prop ~ '^db:[a-z0-9_]{1,30}$');
 -- Extend the existing constraint so reapplying this migration preserves newer kinds.
 FOR r IN SELECT conname, pg_get_constraintdef(oid) AS definition FROM pg_constraint
  WHERE conrelid='community.notifications'::regclass AND contype='c' AND pg_get_constraintdef(oid) LIKE '%kind%' LOOP
  IF position('automation_disabled' in r.definition) = 0 OR position('verify_expired' in r.definition) = 0 THEN
   EXECUTE format('ALTER TABLE community.notifications DROP CONSTRAINT %I',r.conname);
   EXECUTE format('ALTER TABLE community.notifications ADD CONSTRAINT %I CHECK ((%s) OR kind IN (''handover'',''verify_expired'',''recurring_created'',''review_requested'',''due_tomorrow'',''automation_denied'',''automation_disabled''))',
     r.conname, substring(r.definition from 7));
  END IF;
 END LOOP;
END $$;
COMMIT;
