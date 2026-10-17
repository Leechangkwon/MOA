-- Opt-in summaries only. Existing tokens, files, business drafts and grants stay unchanged.
\set ON_ERROR_STOP on
BEGIN;
SET LOCAL lock_timeout='5s';
ALTER TABLE public.api_tokens DROP CONSTRAINT IF EXISTS api_tokens_scope_check;
ALTER TABLE public.api_tokens ADD CONSTRAINT api_tokens_scope_check CHECK (
 scope='assistant.run' OR (scope<>'' AND string_to_array(scope,'+') <@
 ARRAY['read','upload','delete','community.read','community.write','session.open','travel.read','travel.write','diagnostics.write']::text[])
);
CREATE TABLE IF NOT EXISTS public.client_diagnostic_events (
 id BIGSERIAL PRIMARY KEY,
 owner_id BIGINT NOT NULL REFERENCES public.users(id) ON DELETE CASCADE,
 event_id UUID NOT NULL,
 installation_id UUID NOT NULL,
 run_id UUID NOT NULL,
 request_id UUID,
 app_version TEXT NOT NULL CHECK (length(app_version)<=32 AND app_version~'^20[0-9]{2}\.[0-9]{2}\.[0-9]{2}\.[1-9][0-9]*$'),
 code_id TEXT NOT NULL CHECK(code_id~'^[a-f0-9]{64}$'),
 event TEXT NOT NULL CHECK(event IN ('run_started','request_started','response_received','validation_completed','cache_reused','checkpoint_reused','result_saved','run_completed','run_failed')),
 stage TEXT NOT NULL CHECK(stage IN ('prepare','request','checkpoint','validate','save','complete')),
 status TEXT NOT NULL CHECK(status IN ('running','success','failure','waiting')),
 error_code TEXT NOT NULL CHECK(length(error_code)<=48),
 attempt_kind TEXT NOT NULL CHECK(attempt_kind IN ('initial','resume','replay')),
 elapsed_ms BIGINT NOT NULL CHECK(elapsed_ms BETWEEN 0 AND 604800000),
 occurred_at TIMESTAMPTZ NOT NULL,
 received_at TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(),
 payload JSONB NOT NULL CHECK(jsonb_typeof(payload)='object' AND octet_length(payload::text)<=4096),
 payload_sha256 TEXT NOT NULL CHECK(payload_sha256~'^[a-f0-9]{64}$'),
 UNIQUE(owner_id,event_id)
);
CREATE INDEX IF NOT EXISTS client_diagnostic_events_owner_time ON public.client_diagnostic_events(owner_id,received_at);
CREATE INDEX IF NOT EXISTS client_diagnostic_events_time_kind ON public.client_diagnostic_events(received_at,app_version,event,error_code);
CREATE TABLE IF NOT EXISTS public.client_diagnostic_policy (
 singleton BOOLEAN PRIMARY KEY DEFAULT true CHECK(singleton),
 retention_days INTEGER NOT NULL DEFAULT 30 CHECK(retention_days BETWEEN 1 AND 30),
 daily_enabled BOOLEAN NOT NULL DEFAULT true,
 weekly_enabled BOOLEAN NOT NULL DEFAULT true,
 daily_hour INTEGER NOT NULL DEFAULT 2 CHECK(daily_hour BETWEEN 0 AND 23),
 weekly_weekday INTEGER NOT NULL DEFAULT 1 CHECK(weekly_weekday BETWEEN 1 AND 7)
);
INSERT INTO public.client_diagnostic_policy(singleton) VALUES(true) ON CONFLICT DO NOTHING;
CREATE TABLE IF NOT EXISTS public.client_diagnostic_rollups (
 kind TEXT NOT NULL CHECK(kind IN ('day','week')),
 period_start DATE NOT NULL,
 period_end DATE NOT NULL CHECK(period_end>period_start),
 created_at TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(),
 summary JSONB NOT NULL CHECK(jsonb_typeof(summary)='object' AND octet_length(summary::text)<=262144),
 PRIMARY KEY(kind,period_start)
);
GRANT SELECT,INSERT,DELETE ON public.client_diagnostic_events TO tbox_app;
GRANT USAGE,SELECT ON SEQUENCE public.client_diagnostic_events_id_seq TO tbox_app;
GRANT SELECT,UPDATE ON public.client_diagnostic_policy TO tbox_app;
GRANT SELECT,INSERT,UPDATE,DELETE ON public.client_diagnostic_rollups TO tbox_app;
GRANT SELECT ON public.client_diagnostic_events,public.client_diagnostic_policy,public.client_diagnostic_rollups TO tbox_backup;
GRANT SELECT ON SEQUENCE public.client_diagnostic_events_id_seq TO tbox_backup;
COMMIT;
