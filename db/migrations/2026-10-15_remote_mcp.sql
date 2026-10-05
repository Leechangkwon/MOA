-- Additive remote MCP authorization. No existing owner/ACL/data replacement.
\set ON_ERROR_STOP on
BEGIN;
SET LOCAL lock_timeout = '5s';
ALTER TABLE public.users ADD COLUMN IF NOT EXISTS mcp_security_epoch BIGINT NOT NULL DEFAULT 0;
CREATE OR REPLACE FUNCTION public.mcp_bump_security_epoch() RETURNS trigger
LANGUAGE plpgsql SET search_path = pg_catalog AS $$
BEGIN
 IF ROW(OLD.password_hash,OLD.password_changed_at,OLD.mfa_secret,OLD.mfa_enabled,OLD.must_change_password,
        OLD.mfa_recovery_required,OLD.is_active,OLD.expires_at,OLD.role,OLD.team_id)
    IS DISTINCT FROM
    ROW(NEW.password_hash,NEW.password_changed_at,NEW.mfa_secret,NEW.mfa_enabled,NEW.must_change_password,
        NEW.mfa_recovery_required,NEW.is_active,NEW.expires_at,NEW.role,NEW.team_id) THEN
  NEW.mcp_security_epoch := OLD.mcp_security_epoch + 1;
 END IF;
 RETURN NEW;
END $$;
-- Deliberately excludes mfa_last_step, last_seen, counters and display preferences.
DROP TRIGGER IF EXISTS mcp_security_epoch_change ON public.users;
CREATE TRIGGER mcp_security_epoch_change BEFORE UPDATE OF password_hash,password_changed_at,mfa_secret,mfa_enabled,
 must_change_password,mfa_recovery_required,is_active,expires_at,role,team_id
 ON public.users FOR EACH ROW EXECUTE FUNCTION public.mcp_bump_security_epoch();
REVOKE ALL ON FUNCTION public.mcp_bump_security_epoch() FROM PUBLIC;
CREATE TABLE IF NOT EXISTS public.mcp_clients (
 id TEXT PRIMARY KEY CHECK(id ~ '^mcp_client_[A-Za-z0-9_-]{43}$'),
 name TEXT NOT NULL CHECK(char_length(name) BETWEEN 1 AND 80),
 authorized_at TIMESTAMPTZ,
 redirect_uris JSONB NOT NULL CHECK(jsonb_typeof(redirect_uris)='array'),
 grant_types JSONB NOT NULL DEFAULT '["authorization_code","refresh_token"]' CHECK(jsonb_typeof(grant_types)='array'),
 created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
 expires_at TIMESTAMPTZ NOT NULL DEFAULT now()+interval '90 days'
);
ALTER TABLE public.mcp_clients ADD COLUMN IF NOT EXISTS grant_types JSONB NOT NULL DEFAULT '["authorization_code","refresh_token"]';
ALTER TABLE public.mcp_clients ADD COLUMN IF NOT EXISTS authorized_at TIMESTAMPTZ;
CREATE INDEX IF NOT EXISTS mcp_clients_expiry ON public.mcp_clients(expires_at);
CREATE TABLE IF NOT EXISTS public.mcp_pending (
 handle_hash TEXT PRIMARY KEY CHECK(handle_hash ~ '^[a-f0-9]{64}$'),
 client_id TEXT NOT NULL REFERENCES public.mcp_clients(id),
 request JSONB NOT NULL CHECK(jsonb_typeof(request)='object'),
 session_id TEXT, user_id BIGINT REFERENCES public.users(id),
 expires_at TIMESTAMPTZ NOT NULL DEFAULT now()+interval '10 minutes',
 CHECK ((session_id IS NULL) = (user_id IS NULL))
);
CREATE INDEX IF NOT EXISTS mcp_pending_expiry ON public.mcp_pending(expires_at);
CREATE INDEX IF NOT EXISTS mcp_pending_client ON public.mcp_pending(client_id);
CREATE INDEX IF NOT EXISTS mcp_pending_user ON public.mcp_pending(user_id);
CREATE TABLE IF NOT EXISTS public.mcp_grants (
 id UUID PRIMARY KEY, user_id BIGINT NOT NULL REFERENCES public.users(id),
 client_id TEXT NOT NULL REFERENCES public.mcp_clients(id),
 security_epoch BIGINT NOT NULL,
 scope TEXT NOT NULL, resource TEXT NOT NULL,
 created_at TIMESTAMPTZ NOT NULL DEFAULT now(), expires_at TIMESTAMPTZ NOT NULL DEFAULT now()+interval '30 days',
 revoked_at TIMESTAMPTZ, last_used_at TIMESTAMPTZ
);
CREATE INDEX IF NOT EXISTS mcp_grants_user ON public.mcp_grants(user_id,created_at DESC);
CREATE INDEX IF NOT EXISTS mcp_grants_client ON public.mcp_grants(client_id);
CREATE INDEX IF NOT EXISTS mcp_grants_expiry ON public.mcp_grants(expires_at);
CREATE TABLE IF NOT EXISTS public.mcp_codes (
 code_hash TEXT PRIMARY KEY CHECK(code_hash ~ '^[a-f0-9]{64}$'),
 grant_id UUID NOT NULL REFERENCES public.mcp_grants(id) ON DELETE CASCADE,
 redirect_uri TEXT NOT NULL, challenge TEXT NOT NULL CHECK(challenge ~ '^[A-Za-z0-9_-]{43}$'),
 expires_at TIMESTAMPTZ NOT NULL DEFAULT now()+interval '2 minutes', used_at TIMESTAMPTZ
);
CREATE INDEX IF NOT EXISTS mcp_codes_grant ON public.mcp_codes(grant_id);
CREATE TABLE IF NOT EXISTS public.mcp_tokens (
 token_hash TEXT PRIMARY KEY CHECK(token_hash ~ '^[a-f0-9]{64}$'),
 grant_id UUID NOT NULL REFERENCES public.mcp_grants(id) ON DELETE CASCADE,
 kind TEXT NOT NULL CHECK(kind IN ('access','refresh')),
 expires_at TIMESTAMPTZ NOT NULL, used_at TIMESTAMPTZ
);
CREATE INDEX IF NOT EXISTS mcp_tokens_grant ON public.mcp_tokens(grant_id);
CREATE INDEX IF NOT EXISTS mcp_tokens_expiry ON public.mcp_tokens(expires_at);
DO $$ BEGIN
 IF EXISTS(SELECT 1 FROM pg_roles WHERE rolname='tbox_app') THEN
  GRANT SELECT,INSERT,UPDATE,DELETE ON public.mcp_clients,public.mcp_pending,public.mcp_grants,public.mcp_codes,public.mcp_tokens TO tbox_app;
 END IF;
 IF EXISTS(SELECT 1 FROM pg_roles WHERE rolname='tbox_backup') THEN
  GRANT SELECT ON public.mcp_clients,public.mcp_pending,public.mcp_grants,public.mcp_codes,public.mcp_tokens TO tbox_backup;
 END IF;
END $$;
COMMIT;
