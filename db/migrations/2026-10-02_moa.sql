BEGIN;
SET LOCAL ROLE tbox_owner;
ALTER TABLE public.users ADD COLUMN IF NOT EXISTS invited_account boolean NOT NULL DEFAULT false;
CREATE TABLE IF NOT EXISTS public.member_invites (
 id bigserial PRIMARY KEY, code_hash text NOT NULL UNIQUE CHECK(length(code_hash)=64),
 label text NOT NULL CHECK(length(label) BETWEEN 1 AND 80), team_id bigint REFERENCES public.teams(id),
 created_by bigint NOT NULL REFERENCES public.users(id), created_at timestamptz NOT NULL DEFAULT now(),
 expires_at timestamptz NOT NULL, max_uses integer NOT NULL CHECK(max_uses BETWEEN 1 AND 100),
 used_count integer NOT NULL DEFAULT 0 CHECK(used_count>=0 AND used_count<=max_uses), revoked_at timestamptz
);
CREATE TABLE IF NOT EXISTS public.member_rate_limits (
 key_hash text PRIMARY KEY CHECK(length(key_hash)=64), window_start timestamptz NOT NULL DEFAULT now(), hits integer NOT NULL DEFAULT 1
);
CREATE TABLE IF NOT EXISTS public.member_settings (
 key text PRIMARY KEY CHECK(key IN ('buddy_download_url')), value text NOT NULL, updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE IF NOT EXISTS public.member_messages (
 id bigserial PRIMARY KEY, sender_id bigint NOT NULL REFERENCES public.users(id),
 recipient_id bigint NOT NULL REFERENCES public.users(id), body text NOT NULL CHECK(length(body) BETWEEN 1 AND 4000),
 sent_at timestamptz NOT NULL DEFAULT now(), read_at timestamptz, CHECK(sender_id<>recipient_id)
);
CREATE INDEX IF NOT EXISTS member_messages_pair_idx ON public.member_messages(sender_id,recipient_id,id DESC);
CREATE INDEX IF NOT EXISTS member_messages_unread_idx ON public.member_messages(recipient_id,sender_id) WHERE read_at IS NULL;
GRANT SELECT,INSERT,UPDATE ON public.member_invites,public.member_rate_limits,public.member_settings,public.member_messages TO tbox_app;
GRANT USAGE,SELECT ON SEQUENCE public.member_invites_id_seq,public.member_messages_id_seq TO tbox_app;
GRANT SELECT ON public.member_invites,public.member_rate_limits,public.member_settings,public.member_messages TO tbox_backup;
GRANT SELECT ON SEQUENCE public.member_invites_id_seq,public.member_messages_id_seq TO tbox_backup;
COMMIT;
