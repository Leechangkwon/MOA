-- Additive: existing records, original media and link capabilities are preserved.
\set ON_ERROR_STOP on
BEGIN;
SET LOCAL lock_timeout = '5s';
CREATE TABLE IF NOT EXISTS community.memory_follows (
 follower_user_id BIGINT NOT NULL REFERENCES public.users(id),
 followed_user_id BIGINT NOT NULL REFERENCES public.users(id),
 created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
 PRIMARY KEY (follower_user_id, followed_user_id),
 CHECK (follower_user_id <> followed_user_id)
);
CREATE INDEX IF NOT EXISTS memory_follows_incoming ON community.memory_follows(followed_user_id,follower_user_id);
CREATE TABLE IF NOT EXISTS community.memory_preferences (
 user_id BIGINT PRIMARY KEY REFERENCES public.users(id),
 default_visibility TEXT NOT NULL DEFAULT 'private'
   CHECK (default_visibility IN ('private','followers','community','shared')),
 updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
ALTER TABLE community.memory_preferences ADD COLUMN IF NOT EXISTS follow_window_started_at TIMESTAMPTZ NOT NULL DEFAULT now();
ALTER TABLE community.memory_preferences ADD COLUMN IF NOT EXISTS follow_window_count INTEGER NOT NULL DEFAULT 0 CHECK (follow_window_count BETWEEN 0 AND 60);
ALTER TABLE community.memory_posts DROP CONSTRAINT IF EXISTS memory_posts_visibility_check;
ALTER TABLE community.memory_posts ADD CONSTRAINT memory_posts_visibility_check
 CHECK (visibility IN ('private','followers','community','shared'));
ALTER TABLE community.memory_posts ADD COLUMN IF NOT EXISTS discoverable BOOLEAN NOT NULL DEFAULT false;
ALTER TABLE community.memory_posts DROP CONSTRAINT IF EXISTS memory_posts_discoverable_check;
ALTER TABLE community.memory_posts ADD CONSTRAINT memory_posts_discoverable_check
 CHECK (NOT discoverable OR visibility IN ('community','shared'));
CREATE INDEX IF NOT EXISTS memory_posts_discover ON community.memory_posts(created_at DESC,id DESC)
 WHERE deleted_at IS NULL AND discoverable AND visibility IN ('community','shared');
DO $$ BEGIN
 IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname='tbox_app') THEN
  GRANT SELECT,INSERT,UPDATE,DELETE ON community.memory_follows,community.memory_preferences TO tbox_app;
 END IF;
 IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname='tbox_backup') THEN
  GRANT SELECT ON community.memory_follows,community.memory_preferences TO tbox_backup;
 END IF;
END $$;
COMMIT;
