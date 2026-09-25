-- db/migrations/2026-10-02_qf2b_files_search.sql — 파일 전역 검색 색인 (개발 대기열 5b · Q:F-2 J-2 후속)
--
-- 지시서: /var/www/qaredi/docs/qf2-unified-search-directive.md 2.4 · 4장(S1 파일 전역 p95 ≤ 300ms) · 7장 J-2
--         「1차는 ILIKE 그대로 + S1 측정, 목표를 넘으면 `files.search_tsv`(같은 SearchText)」 — 1차 측정이 넘었다(구현 기록 U-2).
--
-- 무엇
--   · files.search_tsv — 이름 + 설명의 한글 「두 글자 조각 + 한 글자」(src/SearchText.php — 커뮤니티와 같은 함수).
--     앱이 파일을 올리고(업로드·URL 가져오기) 설명을 고칠 때 채운다. 기존 행은 scripts/qf2b_backfill_files.php 가 채운다.
--   · files_search_tsv_idx — GIN. 목록 창구가 한글이 든 검색어일 때 후보를 이 색인으로 먼저 좁힌다
--     (원문 ILIKE 는 그대로 — 결과 집합은 바뀌지 않는다).
--   · files_search_tsv_null_idx — 아직 채우지 않은 행(NULL)만 담는 부분 색인. 목록 창구는
--     `search_tsv @@ … OR search_tsv IS NULL` 로 좁히므로 채우기 전 행도 빠지지 않는다(반영 순서 사이의 틈 0).
--     다 채우면 비어 있다(크기 거의 0).
--
-- 잠금: ALTER·CREATE INDEX 는 COMMIT 까지 files 에 배타 잠금을 쥔다. 긴 읽기 뒤에 줄을 서서 files 를 통째로 멈추지 않게
-- `lock_timeout 5s`(못 잡으면 오류 → ROLLBACK — 아무것도 바뀌지 않는다, 다시 돌리면 된다 · 독립 검토 권고).
-- 확장 설치 없음(PG 14 내장 — 갈래 A). 표 단위 권한(db/grants.sql `GRANT SELECT, INSERT, UPDATE ON files`)이라
-- 새 칸에도 앱 계정 권한이 그대로 닿는다. 여러 번 돌려도 같다(IF NOT EXISTS).
-- 새로 설치하는 DB 는 db/schema.sql 이 같은 모양을 만든다 — 그 뒤에 이 파일을 돌려도 아무 일도 하지 않는다.
--
-- 실행: sudo -u postgres psql -d tbox_re -v ON_ERROR_STOP=1 -f db/migrations/2026-10-02_qf2b_files_search.sql
-- 코드만 되돌리고 칸을 둘 때: `UPDATE files SET search_tsv = NULL;` — 옛 코드는 설명을 고쳐도 벡터를 바꾸지 않으므로, 옛 값이
--   남으면 나중에 새 코드를 다시 반영했을 때 그 파일이 한글 검색에서 빠진다(NULL 은 빠지지 않는다 · 독립 검토 L-4).
-- 되돌리기(코드를 먼저 되돌린 뒤 — 새 코드는 파일을 올릴 때 이 칸에 쓴다):
--   DROP INDEX IF EXISTS files_search_tsv_null_idx; DROP INDEX IF EXISTS files_search_tsv_idx;
--   ALTER TABLE files DROP COLUMN IF EXISTS search_tsv;

\set ON_ERROR_STOP on
BEGIN;
SET LOCAL lock_timeout = '5s';
ALTER TABLE public.files ADD COLUMN IF NOT EXISTS search_tsv TSVECTOR;
CREATE INDEX IF NOT EXISTS files_search_tsv_idx ON public.files USING GIN (search_tsv);
CREATE INDEX IF NOT EXISTS files_search_tsv_null_idx ON public.files (id) WHERE search_tsv IS NULL;
COMMIT;
