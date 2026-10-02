-- Community modal messenger. Only participants can read a conversation through the API.
\set ON_ERROR_STOP on
BEGIN;
CREATE TABLE IF NOT EXISTS community.conversations (
 id UUID PRIMARY KEY,
 user_low BIGINT NOT NULL REFERENCES public.users(id),
 user_high BIGINT NOT NULL REFERENCES public.users(id),
 low_read BIGINT NOT NULL DEFAULT 0, high_read BIGINT NOT NULL DEFAULT 0,
 created_at TIMESTAMPTZ NOT NULL DEFAULT now(), updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
 CHECK (user_low < user_high), UNIQUE(user_low,user_high)
);
CREATE INDEX IF NOT EXISTS conversations_high ON community.conversations(user_high,updated_at DESC);
CREATE INDEX IF NOT EXISTS conversations_low ON community.conversations(user_low,updated_at DESC);
CREATE TABLE IF NOT EXISTS community.messages (
 id BIGSERIAL PRIMARY KEY, conversation_id UUID NOT NULL REFERENCES community.conversations(id),
 sender_id BIGINT NOT NULL REFERENCES public.users(id), request_id UUID NOT NULL,
 body TEXT NOT NULL CHECK (char_length(body) BETWEEN 1 AND 4000),
 created_at TIMESTAMPTZ NOT NULL DEFAULT now(), UNIQUE(sender_id,request_id)
);
CREATE INDEX IF NOT EXISTS messages_conversation ON community.messages(conversation_id,id DESC);
CREATE INDEX IF NOT EXISTS messages_sender ON community.messages(sender_id,created_at DESC);
CREATE TABLE IF NOT EXISTS community.gpt_turns (
 id BIGSERIAL PRIMARY KEY, user_id BIGINT NOT NULL REFERENCES public.users(id), request_id UUID NOT NULL,
 prompt TEXT NOT NULL CHECK (char_length(prompt) BETWEEN 1 AND 6000),
 reply TEXT NOT NULL CHECK (char_length(reply) BETWEEN 1 AND 20000),
 created_at TIMESTAMPTZ NOT NULL DEFAULT now(), UNIQUE(user_id,request_id)
);
CREATE INDEX IF NOT EXISTS gpt_turns_user ON community.gpt_turns(user_id,id DESC);
DO $$ BEGIN
 IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname='tbox_app') THEN
  GRANT SELECT,INSERT,UPDATE,DELETE ON community.conversations,community.messages,community.gpt_turns TO tbox_app;
  GRANT USAGE,SELECT ON SEQUENCE community.messages_id_seq,community.gpt_turns_id_seq TO tbox_app;
 END IF;
 IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname='tbox_backup') THEN
  GRANT SELECT ON community.conversations,community.messages,community.gpt_turns TO tbox_backup;
  GRANT SELECT ON SEQUENCE community.messages_id_seq,community.gpt_turns_id_seq TO tbox_backup;
 END IF;
END $$;
COMMIT;
