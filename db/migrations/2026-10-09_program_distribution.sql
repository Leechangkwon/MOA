\set ON_ERROR_STOP on
BEGIN;
ALTER TABLE files ADD COLUMN IF NOT EXISTS program BOOLEAN NOT NULL DEFAULT false;
ALTER TABLE files ADD COLUMN IF NOT EXISTS program_version TEXT;
ALTER TABLE files ADD COLUMN IF NOT EXISTS program_current BOOLEAN NOT NULL DEFAULT false;
ALTER TABLE files ADD COLUMN IF NOT EXISTS program_published_at TIMESTAMPTZ;
DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conrelid='files'::regclass AND conname='files_program_check') THEN
        ALTER TABLE files ADD CONSTRAINT files_program_check CHECK (
            (NOT program AND NOT program_current AND program_version IS NULL AND program_published_at IS NULL)
            OR (program AND exec_content AND ext='zip' AND folder_id IS NULL
                AND access_level IN ('public','internal','team','admin-only')
                AND cardinality(sensitive_flags)=0
                AND program_version IS NOT NULL AND program_version ~ '^[A-Za-z0-9][A-Za-z0-9._+-]{0,63}$'
                AND program_published_at IS NOT NULL));
    END IF;
END $$;
-- MVP hosts one product: tbox-buddy. Old releases remain intact and cannot become current by deleting a new one.
CREATE UNIQUE INDEX IF NOT EXISTS files_program_current_idx ON files ((1)) WHERE program AND program_current;
CREATE INDEX IF NOT EXISTS files_program_history_idx ON files (program_published_at DESC) WHERE program;
COMMIT;
