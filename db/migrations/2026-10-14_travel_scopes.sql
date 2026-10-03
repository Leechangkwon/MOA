-- Opt-in travel access. Existing tokens gain no scopes; IP binding and revocation are unchanged.
\set ON_ERROR_STOP on
BEGIN;
SET LOCAL lock_timeout='5s';
ALTER TABLE public.api_tokens DROP CONSTRAINT IF EXISTS api_tokens_scope_check;
ALTER TABLE public.api_tokens ADD CONSTRAINT api_tokens_scope_check CHECK (
 scope = 'assistant.run' OR (scope<>'' AND string_to_array(scope,'+') <@
 ARRAY['read','upload','delete','community.read','community.write','session.open','travel.read','travel.write']::text[])
);
COMMIT;
