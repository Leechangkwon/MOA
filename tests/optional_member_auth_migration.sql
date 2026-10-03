-- Isolated transaction: run with psql on a disposable test DB after migration.
-- Exercise the exact production backfill against temp tables, never real users.
\set ON_ERROR_STOP on
BEGIN;
CREATE TEMP TABLE users (
 id bigint PRIMARY KEY, invited_account boolean NOT NULL DEFAULT true,
 mfa_enabled boolean NOT NULL DEFAULT false, mfa_setup_required boolean NOT NULL DEFAULT true,
 mfa_recovery_required boolean NOT NULL DEFAULT false
);
CREATE TEMP TABLE audit_logs (
 id bigint, target_type text DEFAULT 'user', target_id bigint, action text,
 result text DEFAULT 'allow', detail jsonb DEFAULT '{}'
);
INSERT INTO users(id) VALUES (1),(2),(3),(4),(5),(6),(7),(8);
-- 1 untouched signup; 2 pending reset; 3 reset followed by confirm; 4 reset
-- followed by self-disable; 5 missing audit; 6 enabled OTP; 7 noninvited hint;
-- 8 recovery flag already set and must not be relaxed by migration replay.
UPDATE users SET mfa_enabled=true WHERE id=6;
UPDATE users SET invited_account=false WHERE id=7;
UPDATE users SET mfa_recovery_required=true WHERE id=8;
INSERT INTO audit_logs(id,target_id,action,detail)
SELECT id,id,'user.create','{"reason_text":"초대 링크로 본인 가입"}'::jsonb FROM users WHERE id<>5;
INSERT INTO audit_logs(id,target_id,action) VALUES
 (20,2,'user.mfa_reset'),(30,3,'user.mfa_reset'),(31,3,'auth.mfa_enroll'),
 (40,4,'user.mfa_reset'),(41,4,'auth.mfa_disable');
-- Production SQL before COMMIT is included by the runner below, so this test
-- cannot silently drift to a duplicate implementation.
