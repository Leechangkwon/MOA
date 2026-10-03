-- Optional, per-member product tour. No existing member or content rows change.
\set ON_ERROR_STOP on
BEGIN;
SET LOCAL lock_timeout = '5s';
CREATE TABLE IF NOT EXISTS community.usage_guide_versions (
    version integer PRIMARY KEY CHECK (version > 0),
    activated_at timestamptz NOT NULL DEFAULT now()
);
INSERT INTO community.usage_guide_versions(version) VALUES (1) ON CONFLICT DO NOTHING;
CREATE TABLE IF NOT EXISTS community.usage_guides (
    user_id bigint NOT NULL REFERENCES public.users(id) ON DELETE CASCADE,
    version integer NOT NULL REFERENCES community.usage_guide_versions(version),
    status text NOT NULL CHECK (status IN ('active', 'dismissed', 'completed')),
    context text NOT NULL CHECK (context IN ('overview','threads','workspace','files','account','messenger','composer','story','travel','today')),
    step smallint NOT NULL DEFAULT 0 CHECK (step BETWEEN 0 AND 30),
    updated_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (user_id, version)
);
DO $$ BEGIN
 IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname='tbox_app') THEN
  GRANT SELECT ON community.usage_guide_versions TO tbox_app;
  GRANT SELECT,INSERT,UPDATE ON community.usage_guides TO tbox_app;
 END IF;
 IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname='tbox_backup') THEN
  GRANT SELECT ON community.usage_guide_versions,community.usage_guides TO tbox_backup;
 END IF;
END $$;
COMMIT;
