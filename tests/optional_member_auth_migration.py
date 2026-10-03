"""Execute the real backfill in temporary tables on an explicitly isolated DB.

OPTIONAL_AUTH_TEST_DB=tbox_optional_auth_<suffix> python3 tests/optional_member_auth_migration.py
Uses psql's configured role/connection; never connects to a default production DB.
"""
import os
import re
import subprocess
from pathlib import Path
root=Path(__file__).resolve().parents[1]
database=os.environ.get('OPTIONAL_AUTH_TEST_DB','')
if not re.fullmatch(r'tbox_optional_auth_[a-z0-9_]+',database):
    raise SystemExit('Set OPTIONAL_AUTH_TEST_DB to an isolated tbox_optional_auth_<suffix> database')
migration=(root/'db/migrations/2026-10-14_optional_member_auth.sql').read_text()
backfill=migration[migration.index('UPDATE users u'):migration.rindex('COMMIT;')]
setup=(root/'tests/optional_member_auth_migration.sql').read_text()
assertion="""
DO $$ BEGIN
 IF (SELECT array_agg(id ORDER BY id) FROM users WHERE mfa_recovery_required) IS DISTINCT FROM ARRAY[2,5,8]::bigint[]
 THEN RAISE EXCEPTION 'Recovery backfill mismatch'; END IF;
END $$;
ROLLBACK;
"""
# Apply twice to check idempotence while protecting previously recorded recovery.
result=subprocess.run(['psql','-X','-v','ON_ERROR_STOP=1','-d',database],input=setup+backfill+backfill+assertion,text=True,capture_output=True)
if result.returncode: raise SystemExit(result.stderr)
print('OPTIONAL_AUTH_BACKFILL 8 cases + replay PASS; transaction rolled back')
