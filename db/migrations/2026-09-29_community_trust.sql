-- C:F-38/39, stage8-trust-handover-directive: additive, idempotent.
-- Keep the bundled stage-9 values when reapplying stage 8 after automation data exists.
-- Rollback: retain columns/history, revert application files first. Do not erase verification events.
\set ON_ERROR_STOP on
BEGIN;
ALTER TABLE community.pages
 ADD COLUMN IF NOT EXISTS verified_by BIGINT REFERENCES public.users(id),
 ADD COLUMN IF NOT EXISTS verified_at TIMESTAMPTZ,
 ADD COLUMN IF NOT EXISTS verify_expires_at TIMESTAMPTZ,
 ADD COLUMN IF NOT EXISTS page_kind TEXT NOT NULL DEFAULT 'page';
DO $$ DECLARE r record; BEGIN
 IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conrelid='community.pages'::regclass AND conname='pages_verify_shape') THEN
  ALTER TABLE community.pages ADD CONSTRAINT pages_verify_shape CHECK
   ((verified_by IS NULL) = (verified_at IS NULL) AND
    (verify_expires_at IS NULL OR (verified_at IS NOT NULL AND verify_expires_at > verified_at)));
 END IF;
 IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conrelid='community.pages'::regclass AND conname='pages_page_kind_check') THEN
  ALTER TABLE community.pages ADD CONSTRAINT pages_page_kind_check CHECK(page_kind IN ('page','procedure'));
 END IF;
 FOR r IN SELECT conname FROM pg_constraint WHERE conrelid='community.page_property_events'::regclass AND contype='c' AND pg_get_constraintdef(oid) LIKE '%prop%' LOOP
  EXECUTE format('ALTER TABLE community.page_property_events DROP CONSTRAINT %I',r.conname);
 END LOOP;
 ALTER TABLE community.page_property_events ADD CONSTRAINT page_property_events_prop_check
  CHECK(prop IN ('status','assignee','publish','hide','resolved','verified','kind','due') OR prop ~ '^db:[a-z0-9_]{1,30}$');
 -- Extend the existing constraint so reapplying this migration preserves newer kinds.
 FOR r IN SELECT conname, pg_get_constraintdef(oid) AS definition FROM pg_constraint
  WHERE conrelid='community.notifications'::regclass AND contype='c' AND pg_get_constraintdef(oid) LIKE '%kind%' LOOP
  IF position('automation_disabled' in r.definition) = 0 OR position('verify_expired' in r.definition) = 0 THEN
   EXECUTE format('ALTER TABLE community.notifications DROP CONSTRAINT %I',r.conname);
   EXECUTE format('ALTER TABLE community.notifications ADD CONSTRAINT %I CHECK ((%s) OR kind IN (''handover'',''verify_expired'',''recurring_created'',''review_requested'',''due_tomorrow'',''automation_denied'',''automation_disabled''))',
     r.conname, substring(r.definition from 7));
  END IF;
 END LOOP;
END $$;
CREATE INDEX IF NOT EXISTS pages_verify_expiry_idx ON community.pages(verify_expires_at) WHERE verify_expires_at IS NOT NULL AND deleted_at IS NULL;
CREATE INDEX IF NOT EXISTS pages_kind_idx ON community.pages(page_kind) WHERE page_kind <> 'page';
COMMIT;
