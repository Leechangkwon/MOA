-- 기존/새 앱의 조회 INSERT가 동시에 들어와도 값 채움과 사이가 벌어지지 않게 한다.
-- 운영에서는 잠금 대기가 길면 취소하고 한산할 때 재시도한다. 데이터 삭제/재시드 없음.
BEGIN;
SET LOCAL lock_timeout = '3s';
SET LOCAL statement_timeout = '600s';
LOCK TABLE community.page_views IN SHARE ROW EXCLUSIVE MODE;
CREATE FUNCTION community.scale_page_view_count() RETURNS trigger
LANGUAGE plpgsql SECURITY INVOKER SET search_path=pg_catalog AS $$
BEGIN
    IF TG_OP='INSERT' THEN
        UPDATE community.pages SET view_count=view_count+1 WHERE id=NEW.page_id;
        RETURN NEW;
    END IF;
    -- 기존 영구삭제 정리 함수가 기록을 지우는 경우도 이전 count(*) 결과와 맞춘다.
    UPDATE community.pages SET view_count=view_count-1 WHERE id=OLD.page_id;
    RETURN OLD;
END $$;
REVOKE ALL ON FUNCTION community.scale_page_view_count() FROM PUBLIC;
CREATE TRIGGER scale_page_view_count_insert AFTER INSERT ON community.page_views
    FOR EACH ROW EXECUTE FUNCTION community.scale_page_view_count();
CREATE TRIGGER scale_page_view_count_delete AFTER DELETE ON community.page_views
    FOR EACH ROW EXECUTE FUNCTION community.scale_page_view_count();
-- 실제 차이가 있는 행만 갱신한다. 조회0인 대부분의 본문을 다시 쓰지 않는다.
WITH counts AS (SELECT page_id,count(*)::int AS n FROM community.page_views GROUP BY page_id)
UPDATE community.pages p SET view_count=c.n FROM counts c WHERE p.id=c.page_id AND p.view_count IS DISTINCT FROM c.n;
UPDATE community.pages p SET view_count=0 WHERE p.view_count<>0 AND NOT EXISTS (SELECT 1 FROM community.page_views v WHERE v.page_id=p.id);
COMMIT;
