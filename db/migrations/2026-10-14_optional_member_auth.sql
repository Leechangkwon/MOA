-- Invitation membership no longer requires OTP. Keep explicit administrator recovery
-- separate from the old setup hint, which was true for every invited account.
-- db/grants.sql grants users table SELECT/INSERT/UPDATE to tbox_app; no new grant needed.
-- Apply before Auth.php; no secret, credential, session or user content is removed.
\set ON_ERROR_STOP on
BEGIN;
SET LOCAL lock_timeout = '5s';
ALTER TABLE users ADD COLUMN IF NOT EXISTS mfa_recovery_required BOOLEAN NOT NULL DEFAULT false;

-- Preserve a pending reset for an invited user. An enrollment/disable after that
-- reset ends the historical requirement. Existing recovery=true is never relaxed.
UPDATE users u SET mfa_recovery_required = true
WHERE u.invited_account AND NOT u.mfa_enabled AND u.mfa_setup_required
  AND (EXISTS (
    SELECT 1 FROM audit_logs a
    WHERE a.target_type = 'user' AND a.target_id = u.id
      AND a.action = 'user.mfa_reset' AND a.result = 'allow'
      AND NOT EXISTS (
        SELECT 1 FROM audit_logs newer
        WHERE newer.target_type = 'user' AND newer.target_id = u.id
          AND newer.result = 'allow' AND newer.id > a.id
          AND newer.action IN ('auth.mfa_enroll', 'auth.mfa_disable')
      )
  ) OR NOT EXISTS (
    -- Missing audit history cannot establish that this is an untouched signup.
    -- Keep it protected for administrator-assisted recovery instead of guessing.
    SELECT 1 FROM audit_logs known
    WHERE known.target_type = 'user' AND known.target_id = u.id AND known.result = 'allow'
      AND (known.action IN ('auth.mfa_enroll', 'auth.mfa_disable')
        OR (known.action = 'user.create' AND known.detail->>'reason_text' = '초대 링크로 본인 가입'))
  ));
COMMIT;
