-- Stage 11: additive and idempotent. Roll back code only; preserve responses and their anonymity.
\set ON_ERROR_STOP on
BEGIN;
ALTER TABLE community.pages ADD COLUMN IF NOT EXISTS form_view_id TEXT;
ALTER TABLE community.pages ADD COLUMN IF NOT EXISTS form_anonymous BOOLEAN;
DO $$ DECLARE c record; expression text; BEGIN
 IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conrelid='community.pages'::regclass AND conname='pages_form_shape_check') THEN
  ALTER TABLE community.pages ADD CONSTRAINT pages_form_shape_check
   CHECK ((form_view_id IS NULL AND form_anonymous IS NULL) OR (form_view_id IS NOT NULL AND form_view_id ~ '^v[0-9a-f]{8}$' AND form_anonymous IS NOT NULL));
 END IF;
 FOR c IN SELECT conname, pg_get_constraintdef(oid) AS definition FROM pg_constraint
           WHERE conrelid='community.pages'::regclass AND contype='c' AND pg_get_constraintdef(oid) LIKE '%page_kind%'
 LOOP
  IF position('dashboard' in c.definition) = 0 THEN
   expression := substring(c.definition from 8 for length(c.definition)-8);
   EXECUTE format('ALTER TABLE community.pages DROP CONSTRAINT %I', c.conname);
   EXECUTE format('ALTER TABLE community.pages ADD CONSTRAINT %I CHECK ((%s) OR page_kind = ''dashboard'')', c.conname, expression);
  END IF;
 END LOOP;
 FOR c IN SELECT conname, pg_get_constraintdef(oid) AS definition FROM pg_constraint
           WHERE conrelid='community.notifications'::regclass AND contype='c' AND pg_get_constraintdef(oid) LIKE '%kind%'
 LOOP
  IF position('form_response' in c.definition) = 0 THEN
   expression := substring(c.definition from 8 for length(c.definition)-8);
   EXECUTE format('ALTER TABLE community.notifications DROP CONSTRAINT %I', c.conname);
   EXECUTE format('ALTER TABLE community.notifications ADD CONSTRAINT %I CHECK ((%s) OR kind = ''form_response'')', c.conname, expression);
  END IF;
 END LOOP;
END $$;
CREATE INDEX IF NOT EXISTS pages_form_rate_idx
 ON community.pages(database_id,form_view_id,author_id,created_at) WHERE form_view_id IS NOT NULL;
COMMIT;
