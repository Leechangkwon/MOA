-- 반드시 트랜잭션 밖. 재시도 전 같은 이름의 invalid 인덱스/정의 차이를 점검한다.
CREATE INDEX CONCURRENTLY IF NOT EXISTS pages_board_new_idx ON community.pages(space_id,created_at DESC,id DESC) WHERE deleted_at IS NULL AND hidden_at IS NULL;
CREATE INDEX CONCURRENTLY IF NOT EXISTS pages_published_new_idx ON community.pages(published_board_id,published_at DESC,id DESC) WHERE deleted_at IS NULL AND hidden_at IS NULL AND visibility='published';
CREATE INDEX CONCURRENTLY IF NOT EXISTS pages_board_updated_idx ON community.pages(space_id,updated_at DESC,id DESC) WHERE deleted_at IS NULL AND hidden_at IS NULL;
CREATE INDEX CONCURRENTLY IF NOT EXISTS pages_published_updated_idx ON community.pages(published_board_id,updated_at DESC,id DESC) WHERE deleted_at IS NULL AND hidden_at IS NULL AND visibility='published';
CREATE INDEX CONCURRENTLY IF NOT EXISTS pages_board_views_idx ON community.pages(space_id,view_count DESC,created_at DESC,id DESC) WHERE deleted_at IS NULL AND hidden_at IS NULL;
CREATE INDEX CONCURRENTLY IF NOT EXISTS pages_published_views_idx ON community.pages(published_board_id,view_count DESC,published_at DESC,id DESC) WHERE deleted_at IS NULL AND hidden_at IS NULL AND visibility='published';
