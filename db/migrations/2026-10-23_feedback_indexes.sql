-- FB-3/6: build outside a transaction to avoid blocking normal writes.
\set ON_ERROR_STOP on
SET lock_timeout = '5s';
SET statement_timeout = '15min';
CREATE INDEX CONCURRENTLY IF NOT EXISTS memory_comments_post_cursor ON community.memory_comments(post_id,created_at DESC,id DESC);
CREATE INDEX CONCURRENTLY IF NOT EXISTS memory_comments_author_rate ON community.memory_comments(user_id,created_at DESC);
CREATE INDEX CONCURRENTLY IF NOT EXISTS memory_posts_owner_cursor ON community.memory_posts(owner_user_id,created_at DESC,id DESC) WHERE deleted_at IS NULL;
