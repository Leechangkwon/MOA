-- Private, flexible itineraries. Existing memories and original media are never changed.
\set ON_ERROR_STOP on
BEGIN;
SET LOCAL lock_timeout='5s';
CREATE TABLE IF NOT EXISTS community.travel_plans (
 id UUID PRIMARY KEY,
 owner_user_id BIGINT NOT NULL REFERENCES public.users(id),
 title TEXT NOT NULL CHECK(char_length(title) BETWEEN 1 AND 120),
 destination TEXT NOT NULL DEFAULT '' CHECK(char_length(destination)<=160),
 starts_on DATE, ends_on DATE,
 notes TEXT NOT NULL DEFAULT '' CHECK(char_length(notes)<=5000),
 steps JSONB NOT NULL DEFAULT '[]' CHECK(jsonb_typeof(steps)='array' AND jsonb_array_length(steps)<=100),
 request_id UUID NOT NULL,
 revision INTEGER NOT NULL DEFAULT 1 CHECK(revision>0),
 created_at TIMESTAMPTZ NOT NULL DEFAULT now(), updated_at TIMESTAMPTZ NOT NULL DEFAULT now(), deleted_at TIMESTAMPTZ,
 CHECK(starts_on IS NULL OR ends_on IS NULL OR starts_on<=ends_on),
 UNIQUE(owner_user_id,request_id)
);
CREATE INDEX IF NOT EXISTS travel_plans_owner ON community.travel_plans(owner_user_id,updated_at DESC,id DESC) WHERE deleted_at IS NULL;
CREATE TABLE IF NOT EXISTS community.travel_plan_records (
 plan_id UUID NOT NULL REFERENCES community.travel_plans(id),
 post_id UUID NOT NULL REFERENCES community.memory_posts(id),
 step_id UUID,
 created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
 PRIMARY KEY(plan_id,post_id)
);
CREATE INDEX IF NOT EXISTS travel_plan_records_post ON community.travel_plan_records(post_id);
DO $$ BEGIN
 IF EXISTS(SELECT 1 FROM pg_roles WHERE rolname='tbox_app') THEN
  GRANT SELECT,INSERT,UPDATE,DELETE ON community.travel_plans,community.travel_plan_records TO tbox_app;
 END IF;
 IF EXISTS(SELECT 1 FROM pg_roles WHERE rolname='tbox_backup') THEN
  GRANT SELECT ON community.travel_plans,community.travel_plan_records TO tbox_backup;
 END IF;
END $$;
COMMIT;
