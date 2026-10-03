BEGIN;
SET LOCAL ROLE tbox_owner;
CREATE TABLE IF NOT EXISTS public.member_ai_settings (
 id integer PRIMARY KEY CHECK(id=1), enabled boolean NOT NULL DEFAULT false,
 user_daily integer NOT NULL DEFAULT 40 CHECK(user_daily BETWEEN 1 AND 500),
 site_daily integer NOT NULL DEFAULT 300 CHECK(site_daily BETWEEN 1 AND 5000),
 updated_at timestamptz NOT NULL DEFAULT now()
);
INSERT INTO public.member_ai_settings(id) VALUES(1) ON CONFLICT(id) DO NOTHING;
CREATE TABLE IF NOT EXISTS public.member_ai_threads (
 id bigserial PRIMARY KEY, user_id bigint NOT NULL REFERENCES public.users(id),
 title text NOT NULL CHECK(length(title) BETWEEN 1 AND 80),
 created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE(id,user_id)
);
CREATE INDEX IF NOT EXISTS member_ai_threads_owner_idx ON public.member_ai_threads(user_id,id DESC);
CREATE TABLE IF NOT EXISTS public.member_ai_turns (
 id bigserial PRIMARY KEY, user_id bigint NOT NULL REFERENCES public.users(id),
 thread_id bigint NOT NULL, request_id uuid NOT NULL,
 prompt text NOT NULL CHECK(length(prompt) BETWEEN 1 AND 4000),
 answer text CHECK(length(answer) BETWEEN 1 AND 20000),
 state text NOT NULL DEFAULT 'pending' CHECK(state IN ('pending','complete','failed')),
 error_code text, model text NOT NULL DEFAULT 'gemini-3.5-flash' CHECK(model='gemini-3.5-flash'),
 input_tokens integer CHECK(input_tokens>=0), output_tokens integer CHECK(output_tokens>=0),
 total_tokens integer CHECK(total_tokens>=0),
 created_at timestamptz NOT NULL DEFAULT now(), finished_at timestamptz,
 FOREIGN KEY(thread_id,user_id) REFERENCES public.member_ai_threads(id,user_id),
 UNIQUE(user_id,request_id), CHECK((state='complete')=(answer IS NOT NULL))
);
CREATE INDEX IF NOT EXISTS member_ai_turns_thread_idx ON public.member_ai_turns(user_id,thread_id,id DESC);
CREATE INDEX IF NOT EXISTS member_ai_turns_created_idx ON public.member_ai_turns(created_at,user_id);
CREATE INDEX IF NOT EXISTS member_ai_turns_pending_idx ON public.member_ai_turns(created_at) WHERE state='pending';
GRANT SELECT,INSERT,UPDATE ON public.member_ai_settings,public.member_ai_threads,public.member_ai_turns TO tbox_app;
GRANT USAGE,SELECT ON SEQUENCE public.member_ai_threads_id_seq,public.member_ai_turns_id_seq TO tbox_app;
GRANT SELECT ON public.member_ai_settings,public.member_ai_threads,public.member_ai_turns TO tbox_backup;
GRANT SELECT ON SEQUENCE public.member_ai_threads_id_seq,public.member_ai_turns_id_seq TO tbox_backup;
COMMIT;
