-- Travel memories. Additive and re-runnable; deploy this before enabling the new feed.
\set ON_ERROR_STOP on
BEGIN;
CREATE TABLE IF NOT EXISTS community.memory_posts (
 id UUID PRIMARY KEY,
 owner_user_id BIGINT NOT NULL REFERENCES public.users(id),
 caption TEXT NOT NULL CHECK (char_length(caption) <= 5000),
 original_caption TEXT NOT NULL CHECK (char_length(original_caption) <= 5000),
 location TEXT NOT NULL DEFAULT '' CHECK (char_length(location) <= 100),
 trip TEXT NOT NULL DEFAULT '' CHECK (char_length(trip) <= 80),
 taken_on DATE NOT NULL,
 visibility TEXT NOT NULL DEFAULT 'private' CHECK (visibility IN ('private','community','shared')),
 media_ids JSONB NOT NULL DEFAULT '[]' CHECK (jsonb_typeof(media_ids)='array' AND jsonb_array_length(media_ids)<=10),
 original_media_ids JSONB NOT NULL DEFAULT '[]' CHECK (jsonb_typeof(original_media_ids)='array' AND jsonb_array_length(original_media_ids)<=10),
 request_id UUID NOT NULL,
 revision INTEGER NOT NULL DEFAULT 1 CHECK (revision > 0),
 created_at TIMESTAMPTZ NOT NULL DEFAULT now(), updated_at TIMESTAMPTZ NOT NULL DEFAULT now(), deleted_at TIMESTAMPTZ,
 UNIQUE (owner_user_id, request_id)
);
CREATE INDEX IF NOT EXISTS memory_posts_feed ON community.memory_posts(created_at DESC,id DESC) WHERE deleted_at IS NULL;
CREATE INDEX IF NOT EXISTS memory_posts_owner ON community.memory_posts(owner_user_id,created_at DESC) WHERE deleted_at IS NULL;
CREATE TABLE IF NOT EXISTS community.memory_media (
 id UUID PRIMARY KEY, owner_user_id BIGINT NOT NULL REFERENCES public.users(id),
 post_id UUID REFERENCES community.memory_posts(id),
 mime TEXT NOT NULL CHECK (mime IN ('image/jpeg','image/png','image/webp','image/gif','video/mp4','video/webm','video/quicktime')),
 filename TEXT NOT NULL CHECK (char_length(filename) BETWEEN 1 AND 200),
 sha256 CHAR(64) NOT NULL CHECK (sha256 ~ '^[0-9a-f]{64}$'),
 bytes BIGINT NOT NULL CHECK (bytes BETWEEN 1 AND 104857600),
 created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS memory_media_owner ON community.memory_media(owner_user_id);
CREATE INDEX IF NOT EXISTS memory_media_post ON community.memory_media(post_id);
CREATE TABLE IF NOT EXISTS community.memory_likes (
 post_id UUID NOT NULL REFERENCES community.memory_posts(id),
 user_id BIGINT NOT NULL REFERENCES public.users(id), created_at TIMESTAMPTZ NOT NULL DEFAULT now(), PRIMARY KEY(post_id,user_id)
);
CREATE TABLE IF NOT EXISTS community.memory_comments (
 id UUID PRIMARY KEY, post_id UUID NOT NULL REFERENCES community.memory_posts(id),
 user_id BIGINT NOT NULL REFERENCES public.users(id),
 body TEXT NOT NULL CHECK (char_length(body) BETWEEN 1 AND 1000), created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS memory_comments_post ON community.memory_comments(post_id,created_at);
CREATE TABLE IF NOT EXISTS community.memory_links (
 token_hash CHAR(64) PRIMARY KEY, owner_user_id BIGINT NOT NULL REFERENCES public.users(id),
 post_id UUID REFERENCES community.memory_posts(id),
 created_at TIMESTAMPTZ NOT NULL DEFAULT now(), expires_at TIMESTAMPTZ NOT NULL DEFAULT now() + interval '30 days', revoked_at TIMESTAMPTZ
);
CREATE INDEX IF NOT EXISTS memory_links_owner ON community.memory_links(owner_user_id) WHERE revoked_at IS NULL;
-- Match the installation's application role; no grants to PUBLIC.
DO $$ BEGIN
 IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname='tbox_app') THEN
  GRANT SELECT,INSERT,UPDATE,DELETE ON community.memory_posts,community.memory_media,community.memory_likes,community.memory_comments,community.memory_links TO tbox_app;
 END IF;
 IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname='tbox_backup') THEN
  GRANT SELECT ON community.memory_posts,community.memory_media,community.memory_likes,community.memory_comments,community.memory_links TO tbox_backup;
 END IF;
END $$;
COMMIT;
