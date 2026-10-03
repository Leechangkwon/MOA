\set ON_ERROR_STOP on
BEGIN;
ALTER TABLE community.memory_posts ADD COLUMN IF NOT EXISTS story JSONB DEFAULT NULL CHECK (story IS NULL OR (jsonb_typeof(story)='object' AND octet_length(story::text)<=50000));
COMMIT;
