-- 옛 표 정리. 보통은 정리 스크립트가 대신 돌린다:
--     sudo bash deploy/cleanup_legacy.sh
BEGIN;
DROP TABLE IF EXISTS legacy;
COMMIT;
