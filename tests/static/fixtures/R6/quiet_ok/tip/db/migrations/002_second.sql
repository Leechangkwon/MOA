-- 둘째 표
BEGIN;
CREATE TABLE t2 (id int);
DO $$ BEGIN RAISE NOTICE 'ok'; END $$;
COMMIT;
