-- Q:F-17 / MB-1~23. Additive, repeatable. Run as postgres; no existing data writes.
\set ON_ERROR_STOP on
BEGIN;
SET LOCAL ROLE tbox_owner;
SET LOCAL lock_timeout = '5s';
CREATE TABLE IF NOT EXISTS community.whiteboards (
 id bigserial PRIMARY KEY,
 public_id uuid NOT NULL UNIQUE DEFAULT gen_random_uuid(),
 page_id bigint NOT NULL REFERENCES community.pages(id) ON DELETE CASCADE,
 version_no integer NOT NULL CHECK(version_no > 0),
 kind text NOT NULL DEFAULT 'meeting' CHECK(kind IN ('meeting')),
 state text NOT NULL DEFAULT 'open' CHECK(state IN ('open','locked')),
 last_seq bigint NOT NULL DEFAULT 0 CHECK(last_seq >= 0),
 created_by bigint NOT NULL REFERENCES public.users(id),
 created_at timestamptz NOT NULL DEFAULT now(),
 locked_by bigint REFERENCES public.users(id), locked_at timestamptz,
 UNIQUE(page_id,version_no)
);
CREATE UNIQUE INDEX IF NOT EXISTS whiteboards_one_open ON community.whiteboards(page_id) WHERE state='open';
CREATE TABLE IF NOT EXISTS community.whiteboard_elements (
 whiteboard_id bigint NOT NULL REFERENCES community.whiteboards(id) ON DELETE CASCADE,
 element_id text NOT NULL CHECK(element_id ~ '^[A-Za-z0-9_-]{1,64}$'),
 type text NOT NULL CHECK(type IN ('rectangle','diamond','ellipse','arrow','line','freedraw','text','frame','image')),
 version integer NOT NULL CHECK(version BETWEEN 1 AND 2147483646),
 version_nonce bigint NOT NULL CHECK(version_nonce BETWEEN 0 AND 4294967295),
 is_deleted boolean NOT NULL DEFAULT false,
 data jsonb NOT NULL CHECK(octet_length(data::text) <= 131072),
 created_by bigint NOT NULL REFERENCES public.users(id), created_seq bigint NOT NULL,
 updated_by bigint NOT NULL REFERENCES public.users(id), updated_seq bigint NOT NULL,
 updated_at timestamptz NOT NULL DEFAULT now(),
 PRIMARY KEY(whiteboard_id,element_id)
);
CREATE INDEX IF NOT EXISTS whiteboard_elements_changes ON community.whiteboard_elements(whiteboard_id,updated_seq);
CREATE TABLE IF NOT EXISTS community.whiteboard_events (
 whiteboard_id bigint NOT NULL REFERENCES community.whiteboards(id) ON DELETE CASCADE,
 seq bigint NOT NULL CHECK(seq>0), element_id text NOT NULL,
 op text NOT NULL CHECK(op IN ('create','update','delete','file_remove')),
 actor_id bigint NOT NULL REFERENCES public.users(id), at timestamptz NOT NULL DEFAULT now(),
 element_version integer NOT NULL, data jsonb NOT NULL,
 prev_hash text NOT NULL CHECK(prev_hash ~ '^[0-9a-f]{64}$'),
 hash text NOT NULL CHECK(hash ~ '^[0-9a-f]{64}$'),
 PRIMARY KEY(whiteboard_id,seq)
);
CREATE TABLE IF NOT EXISTS community.whiteboard_snapshots (
 whiteboard_id bigint PRIMARY KEY REFERENCES community.whiteboards(id) ON DELETE CASCADE,
 scene jsonb NOT NULL, scene_sha256 text NOT NULL CHECK(scene_sha256 ~ '^[0-9a-f]{64}$'),
 png_attachment_id bigint NOT NULL REFERENCES community.page_attachments(id) ON DELETE CASCADE,
 png_sha256 text NOT NULL CHECK(png_sha256 ~ '^[0-9a-f]{64}$'),
 element_count integer NOT NULL, last_seq bigint NOT NULL, chain_head text NOT NULL,
 locked_by bigint NOT NULL REFERENCES public.users(id), locked_at timestamptz NOT NULL DEFAULT now(),
 frame_pngs jsonb NOT NULL DEFAULT '{}'
);
CREATE TABLE IF NOT EXISTS community.whiteboard_presence (
 whiteboard_id bigint NOT NULL REFERENCES community.whiteboards(id) ON DELETE CASCADE,
 user_id bigint NOT NULL REFERENCES public.users(id) ON DELETE CASCADE,
 seen_at timestamptz NOT NULL DEFAULT now(), written_at timestamptz, PRIMARY KEY(whiteboard_id,user_id)
);
CREATE TABLE IF NOT EXISTS community.whiteboard_minutes_requests (
 request_id uuid PRIMARY KEY, whiteboard_id bigint NOT NULL REFERENCES community.whiteboards(id) ON DELETE CASCADE,
 actor_id bigint NOT NULL REFERENCES public.users(id), body_sha256 text NOT NULL,
 doc_version integer NOT NULL, created_at timestamptz NOT NULL DEFAULT now()
);
GRANT SELECT,INSERT,UPDATE ON community.whiteboards,community.whiteboard_elements TO tbox_app;
REVOKE UPDATE,DELETE ON community.whiteboard_events,community.whiteboard_snapshots,community.whiteboard_minutes_requests FROM tbox_app;
GRANT SELECT,INSERT ON community.whiteboard_events,community.whiteboard_snapshots,community.whiteboard_minutes_requests TO tbox_app;
GRANT SELECT,INSERT,UPDATE,DELETE ON community.whiteboard_presence TO tbox_app;
GRANT USAGE,SELECT ON SEQUENCE community.whiteboards_id_seq TO tbox_app;
GRANT SELECT ON SEQUENCE community.whiteboards_id_seq TO tbox_backup;
GRANT SELECT ON community.whiteboards,community.whiteboard_elements,community.whiteboard_events,
 community.whiteboard_snapshots,community.whiteboard_presence,community.whiteboard_minutes_requests TO tbox_backup;
COMMIT;
