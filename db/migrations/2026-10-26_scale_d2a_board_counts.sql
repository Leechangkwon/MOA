-- 규모 D2a A2 — 게시판 글 수를 미리 센다(community.spaces.post_count). 정의는 게시판 목록 · 카페 홈의 옛 하위 질의와 **정확히 같다**:
--   deleted_at IS NULL AND hidden_at IS NULL AND (space_id = b.id OR (visibility='published' AND published_board_id = b.id)) — 한 글이 둘 다면 1번.
-- 문장 전체의 이전/이후 행으로 게시판별 증감을 모아 게시판 id 순서로 갱신한다(반대 방향 이동 · 여러 글 삭제의 교착 방지).
-- PostgreSQL 14 는 transition table 과 UPDATE OF 를 함께 허용하지 않는다. UPDATE 트리거는 한 문장에 한 번 들어오되,
-- 관련 칸이 그대로면 바로 끝낸다(본문 자동 저장: spaces UPDATE 실행 0 · 행 xmin 불변).
-- 열 추가의 ACCESS EXCLUSIVE 는 첫 짧은 트랜잭션에서 풀고, 두 번째 트랜잭션은 pages 쓰기를 막고 트리거 · 값을 함께 채운다.
-- 두 번째 단계에서는 spaces 읽기가 가능하다. 첫 단계 뒤 실패해도 재실행하면 채움이 완료된다 — 두 단계 성공 후에만 새 코드를 반영한다.
-- 두 번 돌려도 같다(IF NOT EXISTS · CREATE OR REPLACE · 채움은 차이 있는 행만). 데이터 삭제 · 재시드 0.
BEGIN;
SET LOCAL lock_timeout = '3s';
SET LOCAL statement_timeout = '600s';
ALTER TABLE community.spaces ADD COLUMN IF NOT EXISTS post_count integer NOT NULL DEFAULT 0;
COMMIT;
BEGIN;
SET LOCAL lock_timeout = '3s';
SET LOCAL statement_timeout = '600s';
LOCK TABLE community.pages IN SHARE ROW EXCLUSIVE MODE;
CREATE OR REPLACE FUNCTION community.scale_board_post_count() RETURNS trigger
LANGUAGE plpgsql SECURITY INVOKER SET search_path=pg_catalog AS $$
DECLARE
    old_counts jsonb := '{}';
    new_counts jsonb := '{}';
    delta record;
BEGIN
    IF TG_OP = 'UPDATE' THEN
        IF NOT EXISTS (
            SELECT 1 FROM d2a_old_pages o JOIN d2a_new_pages n ON n.id = o.id
             WHERE o.space_id IS DISTINCT FROM n.space_id
                OR o.published_board_id IS DISTINCT FROM n.published_board_id
                OR o.visibility IS DISTINCT FROM n.visibility
                OR (o.deleted_at IS NULL) <> (n.deleted_at IS NULL)
                OR (o.hidden_at IS NULL) <> (n.hidden_at IS NULL)
        ) THEN
            RETURN NULL;
        END IF;
    END IF;
    -- 게시판만 집계한다. 한 글의 space_id 와 published_board_id 가 같으면 한 번만.
    IF TG_OP <> 'INSERT' THEN
        SELECT COALESCE(jsonb_object_agg(c.board_id, c.n), '{}'::jsonb) INTO old_counts
          FROM (
            SELECT b.board_id, count(*) AS n
              FROM d2a_old_pages p
              CROSS JOIN LATERAL (
                SELECT p.space_id AS board_id
                UNION ALL
                SELECT p.published_board_id WHERE p.visibility = 'published'
                   AND p.published_board_id IS NOT NULL AND p.published_board_id <> p.space_id
              ) b
              JOIN community.spaces s ON s.id = b.board_id AND s.kind = 'board'
             WHERE p.deleted_at IS NULL AND p.hidden_at IS NULL
             GROUP BY b.board_id
          ) c;
    END IF;
    IF TG_OP <> 'DELETE' THEN
        SELECT COALESCE(jsonb_object_agg(c.board_id, c.n), '{}'::jsonb) INTO new_counts
          FROM (
            SELECT b.board_id, count(*) AS n
              FROM d2a_new_pages p
              CROSS JOIN LATERAL (
                SELECT p.space_id AS board_id
                UNION ALL
                SELECT p.published_board_id WHERE p.visibility = 'published'
                   AND p.published_board_id IS NOT NULL AND p.published_board_id <> p.space_id
              ) b
              JOIN community.spaces s ON s.id = b.board_id AND s.kind = 'board'
             WHERE p.deleted_at IS NULL AND p.hidden_at IS NULL
             GROUP BY b.board_id
          ) c;
    END IF;
    -- 문장 전체에서 상쇄된 게시판은 건드리지 않는다. UPDATE 를 한 행씩 명시적으로 id 순서로 실행해 잠금 순서를 고정한다.
    -- 한 트랜잭션에서 서로 다른 문장을 반대 게시판 순서로 실행하는 임의 SQL 까지 직렬화하지는 않는다(보고서 한계).
    FOR delta IN
        SELECT d.board_id, sum(d.n) AS n FROM (
            SELECT key::bigint AS board_id, -(value::bigint) AS n FROM jsonb_each_text(old_counts)
            UNION ALL
            SELECT key::bigint AS board_id, value::bigint AS n FROM jsonb_each_text(new_counts)
        ) d
        GROUP BY d.board_id HAVING sum(d.n) <> 0 ORDER BY d.board_id
    LOOP
        UPDATE community.spaces SET post_count = post_count + delta.n
         WHERE id = delta.board_id AND kind = 'board';
    END LOOP;
    RETURN NULL;
END $$;
REVOKE ALL ON FUNCTION community.scale_board_post_count() FROM PUBLIC;
CREATE OR REPLACE TRIGGER scale_board_post_count_insert AFTER INSERT ON community.pages
    REFERENCING NEW TABLE AS d2a_new_pages
    FOR EACH STATEMENT EXECUTE FUNCTION community.scale_board_post_count();
CREATE OR REPLACE TRIGGER scale_board_post_count_delete AFTER DELETE ON community.pages
    REFERENCING OLD TABLE AS d2a_old_pages
    FOR EACH STATEMENT EXECUTE FUNCTION community.scale_board_post_count();
CREATE OR REPLACE TRIGGER scale_board_post_count_update AFTER UPDATE ON community.pages
    REFERENCING OLD TABLE AS d2a_old_pages NEW TABLE AS d2a_new_pages
    FOR EACH STATEMENT EXECUTE FUNCTION community.scale_board_post_count();
-- 한 번 채움 — 옛 하위 질의 그대로 센 값과 다른 행만 고친다.
WITH live AS (
    SELECT b.id,
           (SELECT count(*) FROM community.pages p
             WHERE p.deleted_at IS NULL AND p.hidden_at IS NULL
               AND (p.space_id = b.id OR (p.visibility = 'published' AND p.published_board_id = b.id)))::int AS n
      FROM community.spaces b
     WHERE b.kind = 'board'
)
UPDATE community.spaces s SET post_count = l.n FROM live l WHERE s.id = l.id AND s.post_count IS DISTINCT FROM l.n;
UPDATE community.spaces SET post_count = 0 WHERE kind <> 'board' AND post_count <> 0;
COMMIT;
