-- superuser 실행, PostgreSQL 전역 role/표 설정. 내용 행 변경 없음.
CREATE EXTENSION IF NOT EXISTS pg_stat_statements WITH SCHEMA public;
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_extension e JOIN pg_namespace n ON n.oid=e.extnamespace
                   WHERE e.extname='pg_stat_statements' AND n.nspname='public') THEN
        RAISE EXCEPTION 'pg_stat_statements schema mismatch; stop without moving an existing extension';
    END IF;
END $$;
-- FPM8 + 전역 parallel workers8 × 64MB ≈ 1GB. 측정 DB 유지 중 여유4.8GB에 맞춘다.
ALTER ROLE tbox_app SET temp_file_limit = '64MB';
-- 삽입/갱신 빈도에 맞춰 기본0.2보다 일찍 정리/통계 갱신. 최소50행 문턱 유지.
ALTER TABLE community.pages SET (autovacuum_vacuum_scale_factor=0.02, autovacuum_analyze_scale_factor=0.01);
ALTER TABLE community.page_blocks SET (autovacuum_vacuum_scale_factor=0.02, autovacuum_analyze_scale_factor=0.01);
ALTER TABLE community.page_views SET (autovacuum_vacuum_scale_factor=0.02, autovacuum_analyze_scale_factor=0.01, autovacuum_vacuum_insert_scale_factor=0.02);
ALTER TABLE community.notifications SET (autovacuum_vacuum_scale_factor=0.02, autovacuum_analyze_scale_factor=0.01);
-- 다른 사용자의 쿼리/입력을 앱에 노출하지 않는다. 기본 PUBLIC view 권한도 명시 회수.
REVOKE ALL ON TABLE public.pg_stat_statements, public.pg_stat_statements_info FROM PUBLIC;

-- 뷰뿐 아니라 같은 통계를 돌려주는 함수의 기본 PUBLIC 실행 권한도 회수한다.
REVOKE EXECUTE ON FUNCTION public.pg_stat_statements(boolean) FROM PUBLIC;
REVOKE EXECUTE ON FUNCTION public.pg_stat_statements_info() FROM PUBLIC;
