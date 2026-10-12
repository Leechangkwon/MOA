-- 규모 개선 A / SR-10. psql -v ON_ERROR_STOP=1, BEGIN/COMMIT 없이 실행.
-- 기존 부분 인덱스는 삭제하지 않는다. 실패 후 재시도 전 pg_index.indisvalid 확인.
CREATE INDEX CONCURRENTLY IF NOT EXISTS comments_page_created_all_idx
    ON community.comments (page_id, created_at, id);
