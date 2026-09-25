-- db/migrations/2026-10-01_qf2_search.sql
--   Q:F-2 통합 검색 + 한글 색인 — 댓글 검색 색인 (사이트 PRD Q:F-2, 지시서 docs/qf2-unified-search-directive.md 2.3 · 3장 갈래 A)
--
-- 실행 계정: tbox_owner(표 소유자) 또는 postgres. **확장 설치 없음**(갈래 A — PG 14 내장 기능만).
-- 권한: 새 표가 없다. `community.comments` 는 이미 tbox_app 에 SELECT·INSERT·UPDATE(표 단위)가 있어 새 칸도 그대로 쓴다
--       (grants.sql 을 다시 돌릴 필요 없음 — 돌려도 결과가 같다).
--
-- 하는 일 (여러 번 돌려도 같다, 기존 행의 값 변경 0)
--   1. comments.search_tsv  TSVECTOR — 댓글 본문의 한글 「두 글자 조각 + 한 글자」 색인(값은 앱 `SearchText` 가 만든다).
--                           새 칸은 비어(NULL) 있다 — 기존 댓글은 `scripts/qf2_backfill_search.php` 가 채운다(묶음 500행).
--   2. comments_search_tsv_idx  GIN — 페이지의 `pages_search_tsv_idx` 와 같은 모양.
--   (페이지 `search_tsv` 칸·색인은 이미 있다 — 값만 새 규칙으로 바뀌므로 backfill 이 다시 채운다.)
--
-- 되돌리기 (앱을 옛 판으로 되돌린 뒤에만 — 새 앱은 댓글을 쓸 때 이 칸에 쓴다):
--   DROP INDEX IF EXISTS community.comments_search_tsv_idx;
--   ALTER TABLE community.comments DROP COLUMN IF EXISTS search_tsv;
--   (페이지 벡터는 옛 앱이 저장할 때마다 옛 규칙으로 덮으므로 따로 되돌릴 것이 없다 — 옛 검색은 벡터를 읽지 않는다.)

\set ON_ERROR_STOP on

BEGIN;

ALTER TABLE community.comments ADD COLUMN IF NOT EXISTS search_tsv TSVECTOR;
CREATE INDEX IF NOT EXISTS comments_search_tsv_idx ON community.comments USING GIN (search_tsv);

COMMIT;

-- 확인:
--   \d community.comments          -- search_tsv tsvector
--   \di community.comments_search_tsv_idx
