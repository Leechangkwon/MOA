BEGIN;
SET LOCAL ROLE tbox_owner;
ALTER TABLE public.member_ai_settings
 ADD COLUMN IF NOT EXISTS image_enabled boolean NOT NULL DEFAULT false,
 ADD COLUMN IF NOT EXISTS image_user_daily integer NOT NULL DEFAULT 3 CHECK(image_user_daily BETWEEN 1 AND 20),
 ADD COLUMN IF NOT EXISTS image_site_daily integer NOT NULL DEFAULT 20 CHECK(image_site_daily BETWEEN 1 AND 200);
CREATE TABLE IF NOT EXISTS public.member_ai_assists (
 id uuid PRIMARY KEY, user_id bigint NOT NULL REFERENCES public.users(id),
 peer_id bigint NOT NULL REFERENCES public.users(id), request_id uuid NOT NULL,
 action text NOT NULL CHECK(action IN ('polish','spelling','translate','image')),
 source_message_id bigint REFERENCES public.member_messages(id),
 input text NOT NULL CHECK(length(input) BETWEEN 1 AND 4000),
 language text NOT NULL CHECK(language IN ('ko','en','ja','zh','vi','es')),
 aspect text NOT NULL CHECK(aspect IN ('1:1','16:9','9:16')),
 state text NOT NULL DEFAULT 'pending' CHECK(state IN ('pending','complete','failed')),
 result text CHECK(length(result) BETWEEN 1 AND 4000), error_code text,
 stored_name text UNIQUE, image_mime text CHECK(image_mime IN ('image/png','image/jpeg','image/webp')),
 image_bytes integer CHECK(image_bytes BETWEEN 1 AND 6291456), image_sha256 char(64),
 model text NOT NULL CHECK(model IN ('gemini-3.5-flash','gemini-3.1-flash-lite-image')),
 input_tokens integer CHECK(input_tokens>=0), output_tokens integer CHECK(output_tokens>=0), total_tokens integer CHECK(total_tokens>=0),
 created_at timestamptz NOT NULL DEFAULT now(), finished_at timestamptz,
 UNIQUE(user_id,request_id), CHECK(user_id<>peer_id),
 CHECK((action='image')=(model='gemini-3.1-flash-lite-image')),
 CHECK((state='complete')=(result IS NOT NULL OR stored_name IS NOT NULL)),
 CHECK((stored_name IS NOT NULL)=(image_mime IS NOT NULL)),
 CHECK((stored_name IS NOT NULL)=(image_bytes IS NOT NULL)),
 CHECK((stored_name IS NOT NULL)=(image_sha256 IS NOT NULL)),
 CHECK(stored_name IS NULL OR (action='image' AND stored_name ~ '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\.(png|jpg|webp)$'))
);
CREATE INDEX IF NOT EXISTS member_ai_assists_owner_idx ON public.member_ai_assists(user_id,peer_id,created_at DESC);
CREATE INDEX IF NOT EXISTS member_ai_assists_created_idx ON public.member_ai_assists(created_at,user_id);
CREATE INDEX IF NOT EXISTS member_ai_assists_pending_idx ON public.member_ai_assists(created_at) WHERE state='pending';
ALTER TABLE public.member_messages ADD COLUMN IF NOT EXISTS ai_attachment_id uuid REFERENCES public.member_ai_assists(id);
CREATE UNIQUE INDEX IF NOT EXISTS member_messages_ai_attachment_idx ON public.member_messages(ai_attachment_id) WHERE ai_attachment_id IS NOT NULL;
GRANT SELECT,INSERT,UPDATE ON public.member_ai_assists TO tbox_app;
GRANT SELECT ON public.member_ai_assists TO tbox_backup;
COMMIT;
