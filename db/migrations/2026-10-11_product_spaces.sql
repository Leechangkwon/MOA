-- Additive product boundaries. No legacy data imported and no existing grants widened.
\set ON_ERROR_STOP on
BEGIN;
SET LOCAL ROLE tbox_owner;
CREATE TABLE IF NOT EXISTS public.product_spaces (
 id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
 name TEXT NOT NULL CHECK(char_length(name) BETWEEN 1 AND 100),
 kind TEXT NOT NULL CHECK(kind IN ('company','group')),
 status TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('active','archived')),
 created_by BIGINT NOT NULL REFERENCES public.users(id),
 created_at TIMESTAMPTZ NOT NULL DEFAULT now(),updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE TABLE IF NOT EXISTS public.product_space_members (
 space_id UUID NOT NULL REFERENCES public.product_spaces(id),user_id BIGINT NOT NULL REFERENCES public.users(id),
 role TEXT NOT NULL CHECK(role IN ('owner','admin','editor','member','viewer')),
 active BOOLEAN NOT NULL DEFAULT true, joined_at TIMESTAMPTZ NOT NULL DEFAULT now(),
 PRIMARY KEY(space_id,user_id)
);
CREATE INDEX IF NOT EXISTS product_space_members_user ON public.product_space_members(user_id,space_id) WHERE active;
CREATE TABLE IF NOT EXISTS public.product_space_invites (
 id UUID PRIMARY KEY DEFAULT gen_random_uuid(),space_id UUID NOT NULL REFERENCES public.product_spaces(id),
 token_hash CHAR(64) NOT NULL UNIQUE,role TEXT NOT NULL CHECK(role IN ('admin','editor','member','viewer')),
 expires_at TIMESTAMPTZ NOT NULL,max_uses INTEGER NOT NULL CHECK(max_uses BETWEEN 1 AND 100),
 uses INTEGER NOT NULL DEFAULT 0 CHECK(uses>=0 AND uses<=max_uses),created_by BIGINT NOT NULL REFERENCES public.users(id),
 revoked_at TIMESTAMPTZ,created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE TABLE IF NOT EXISTS public.product_space_dashboards (
 space_id UUID PRIMARY KEY REFERENCES public.product_spaces(id),layout JSONB NOT NULL,
 revision INTEGER NOT NULL DEFAULT 1 CHECK(revision>0),updated_by BIGINT NOT NULL REFERENCES public.users(id),updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE TABLE IF NOT EXISTS public.product_personal_dashboards (
 user_id BIGINT PRIMARY KEY REFERENCES public.users(id),layout JSONB NOT NULL,
 revision INTEGER NOT NULL DEFAULT 1 CHECK(revision>0),updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE TABLE IF NOT EXISTS public.product_space_items (
 id UUID PRIMARY KEY DEFAULT gen_random_uuid(),space_id UUID NOT NULL REFERENCES public.product_spaces(id),
 kind TEXT NOT NULL CHECK(kind IN ('notice','task','event','story','file')),
 title TEXT NOT NULL CHECK(char_length(title) BETWEEN 1 AND 200),body TEXT NOT NULL DEFAULT '' CHECK(char_length(body)<=20000),
 status TEXT NOT NULL DEFAULT 'todo' CHECK(status IN ('todo','doing','done')),
 assignee_id BIGINT REFERENCES public.users(id),due_at TIMESTAMPTZ,starts_at TIMESTAMPTZ,ends_at TIMESTAMPTZ,
 file_id UUID REFERENCES public.files(public_id),created_by BIGINT NOT NULL REFERENCES public.users(id),
 revision INTEGER NOT NULL DEFAULT 1 CHECK(revision>0),created_at TIMESTAMPTZ NOT NULL DEFAULT now(),updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),deleted_at TIMESTAMPTZ,
 CHECK(ends_at IS NULL OR (starts_at IS NOT NULL AND ends_at>=starts_at)),
 CHECK((kind='file' AND file_id IS NOT NULL) OR (kind<>'file' AND file_id IS NULL)),
 CHECK(assignee_id IS NULL OR kind='task')
);
CREATE INDEX IF NOT EXISTS product_space_items_space ON public.product_space_items(space_id,kind,updated_at DESC) WHERE deleted_at IS NULL;
CREATE INDEX IF NOT EXISTS product_space_invites_space ON public.product_space_invites(space_id);
CREATE INDEX IF NOT EXISTS product_space_items_file ON public.product_space_items(file_id) WHERE file_id IS NOT NULL;
-- Preserve existing owner/ACL. Set ownership on new tables explicitly; roles are configured server roles.
ALTER TABLE public.product_spaces OWNER TO tbox_owner;
ALTER TABLE public.product_space_members OWNER TO tbox_owner;
ALTER TABLE public.product_space_invites OWNER TO tbox_owner;
ALTER TABLE public.product_space_dashboards OWNER TO tbox_owner;
ALTER TABLE public.product_personal_dashboards OWNER TO tbox_owner;
ALTER TABLE public.product_space_items OWNER TO tbox_owner;
REVOKE ALL ON public.product_spaces,public.product_space_members,public.product_space_invites,public.product_space_dashboards,public.product_personal_dashboards,public.product_space_items FROM PUBLIC;
GRANT SELECT,INSERT,UPDATE ON public.product_spaces,public.product_space_members,public.product_space_invites,public.product_space_dashboards,public.product_personal_dashboards,public.product_space_items TO tbox_app;
GRANT SELECT ON public.product_spaces,public.product_space_members,public.product_space_invites,public.product_space_dashboards,public.product_personal_dashboards,public.product_space_items TO tbox_backup;
COMMIT;
