-- ─────────────────────────────────────────────────────────────
-- 2026-10-22_owner_fix.sql  (사이트 Q:F-13 재지시 P2-R3 — SECURITY R-120 · ARCHITECTURE 10장 끝 「마이그레이션 적용 계정」)
--   (파일 이름의 날짜는 적용 **순서**용이다 — 2026-10-21_privacy_retention.sql 뒤. 작성 2026-10-07)
--
-- 왜
--   운영 tbox_re 에서 아래 표 15 · 시퀀스 4 · 함수 1 의 소유자가 `postgres` 다(운영 실측 2026-10-07 — 그 객체를 만든
--   마이그레이션에 소유자 줄이 없고 `postgres` 로 적용돼서). 소유자 계정(tbox_owner)으로 도는 privacy_purge(P2)가
--   그중 여행 둘 · 팔로우 · 선호 · api_tokens 를 지워야 하는데 tbox_owner 에 DELETE · UPDATE 가 없다.
--
-- 하는 일 (여러 번 돌려도 같다 — 두 번째부터는 아무것도 바꾸지 않는다)
--   아래 목록의 객체만, **있고 · 지금 소유자가 `postgres` 일 때만** 소유자를 tbox_owner 로 바꾼다.
--     표 15     community.memory_follows · community.memory_preferences · community.travel_plan_records ·
--               community.travel_plans · community.usage_guide_versions · community.usage_guides ·
--               public.api_tokens · public.fetch_hosts · public.folders · public.mcp_clients · public.mcp_codes ·
--               public.mcp_grants · public.mcp_pending · public.mcp_tokens · public.share_links
--     시퀀스 4  public.api_tokens_id_seq · public.fetch_hosts_id_seq · public.folders_id_seq · public.share_links_id_seq
--     함수 1    public.mcp_bump_security_epoch()   (인자 없는 그 하나 — 같은 이름의 다른 인자 함수는 건드리지 않는다)
--   표를 먼저 바꾼다 — 표의 열에 딸린 시퀀스(BIGSERIAL)와 인덱스는 PostgreSQL 이 표와 함께 옮긴다.
--   시퀀스 칸은 그 뒤에도 `postgres` 로 남은 것(표에 딸리지 않은 것)만 바꾼다.
--   이름은 스키마까지 맞춘다 — 다른 스키마의 같은 이름 객체는 목록 밖이다.
--
--   ★ 권한: GRANT · REVOKE 를 하지 않는다. 소유자를 바꾸면 PostgreSQL 이 권한 목록에서 옛 소유자 항목(받은 사람 · 준 사람)을
--     새 소유자로 바꿔 적는다 — tbox_app · tbox_backup 이 가진 권한의 종류는 그대로이고, 준 사람만 `tbox_owner` 로 보인다.
--     함수의 PUBLIC 실행 회수(2026-10-15_remote_mcp.sql)도 그대로다.
--   ★ 행: 건드리지 않는다(카탈로그의 소유자 칸만 바뀐다 — 데이터 다시 쓰기 0).
--
-- 실행 계정: **postgres 그대로 — `SET ROLE tbox_owner` 없이.** `postgres` 소유 객체를 넘기는 것은 슈퍼유저만 할 수 있다.
--   ARCH 10장 「첫 줄 SET ROLE tbox_owner」 규칙의 예외는 이 파일 하나다. 바꿀 것이 남았는데 슈퍼유저가 아니면 멈춘다
--   (RAISE — 한 트랜잭션이라 아무것도 안 바뀐다). 바꿀 것이 없으면(새 설치 · 이미 바로잡음 · 시험 틀처럼 소유자가 다른 DB)
--   어떤 계정으로 돌려도 아무 일도 하지 않는다.
--
-- 잠금: 표마다 ACCESS EXCLUSIVE(카탈로그 갱신뿐 — 1초 안). `lock_timeout 5s` 로 긴 트랜잭션 뒤에 서지 않는다
--   (못 잡으면 오류 → ROLLBACK — 바뀐 것 없음). 한 트랜잭션 — 전부 바뀌거나 하나도 안 바뀐다.
--
-- ★ 배포 순서: 이 마이그레이션 → privacy_purge cron. 앱 코드와 무관하다(앱 · 백업 계정 권한이 바뀌지 않는다).
--
-- 실행: sudo -u postgres psql -d tbox_re -v ON_ERROR_STOP=1 -f db/migrations/2026-10-22_owner_fix.sql
--   (NOTICE 로 바꾼 객체와 수를 알린다. 두 번째 실행은 「바꾼 것 0」)
--
-- 확인(바꾼 뒤):
--   SELECT n.nspname||'.'||c.relname, c.relkind, pg_get_userbyid(c.relowner), c.relacl FROM pg_class c
--     JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname IN ('public','community')
--      AND pg_get_userbyid(c.relowner) = 'postgres' AND c.relkind IN ('r','S');          → 목록의 것 0줄
--   SELECT has_table_privilege('tbox_owner','community.travel_plans','DELETE');          → t (정리 대상 다섯 모두)
--   SELECT pg_get_userbyid(proowner), proacl FROM pg_proc WHERE oid = 'public.mcp_bump_security_epoch()'::regprocedure;
--                                                                                         → tbox_owner · {tbox_owner=X/tbox_owner}
-- 시험: tests/qf13_owner_fix.py (스크래치 DB — 운영 모양 흉내 · 2회 적용 · 목록 밖 postgres 소유 객체 그대로)
--
-- 되돌리기(필요할 때만 — 사용자 판단): 같은 목록에 `ALTER … OWNER TO postgres` (권한 목록은 같은 방식으로 다시 옮겨진다).
-- ─────────────────────────────────────────────────────────────

\set ON_ERROR_STOP on

BEGIN;
SET LOCAL lock_timeout = '5s';

DO $owner_fix$
DECLARE
    r        record;
    pending  integer;
    changed  integer := 0;
    fn       regprocedure := to_regprocedure('public.mcp_bump_security_epoch()');
BEGIN
    -- 바꿀 것이 남았는지 먼저 센다(있으면 슈퍼유저 · tbox_owner 역할이 있어야 한다)
    SELECT count(*) INTO pending
      FROM (VALUES ('community','memory_follows','r'), ('community','memory_preferences','r'),
                   ('community','travel_plan_records','r'), ('community','travel_plans','r'),
                   ('community','usage_guide_versions','r'), ('community','usage_guides','r'),
                   ('public','api_tokens','r'), ('public','fetch_hosts','r'), ('public','folders','r'),
                   ('public','mcp_clients','r'), ('public','mcp_codes','r'), ('public','mcp_grants','r'),
                   ('public','mcp_pending','r'), ('public','mcp_tokens','r'), ('public','share_links','r'),
                   ('public','api_tokens_id_seq','S'), ('public','fetch_hosts_id_seq','S'),
                   ('public','folders_id_seq','S'), ('public','share_links_id_seq','S')) AS want(nsp, rel, kind)
      JOIN pg_namespace n ON n.nspname = want.nsp
      JOIN pg_class c ON c.relnamespace = n.oid AND c.relname = want.rel AND c.relkind = want.kind::"char"
     WHERE pg_get_userbyid(c.relowner) = 'postgres';
    IF fn IS NOT NULL AND (SELECT pg_get_userbyid(p.proowner) FROM pg_proc p WHERE p.oid = fn) = 'postgres' THEN
        pending := pending + 1;
    END IF;

    IF pending > 0 THEN
        IF NOT (SELECT rolsuper FROM pg_roles WHERE rolname = current_user) THEN
            RAISE EXCEPTION 'owner_fix: postgres 소유 객체 %개를 넘기려면 슈퍼유저(postgres)로 SET ROLE 없이 적용한다 (지금 %)',
                pending, current_user;
        END IF;
        IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'tbox_owner') THEN
            RAISE EXCEPTION 'owner_fix: tbox_owner 역할이 없다 — deploy/install.sh 가 먼저 만든다 (ARCH 10장)';
        END IF;
    END IF;

    -- 1) 표 — 딸린 시퀀스 · 인덱스는 PostgreSQL 이 함께 옮긴다
    FOR r IN
        SELECT want.nsp, want.rel
          FROM (VALUES ('community','memory_follows'), ('community','memory_preferences'),
                       ('community','travel_plan_records'), ('community','travel_plans'),
                       ('community','usage_guide_versions'), ('community','usage_guides'),
                       ('public','api_tokens'), ('public','fetch_hosts'), ('public','folders'),
                       ('public','mcp_clients'), ('public','mcp_codes'), ('public','mcp_grants'),
                       ('public','mcp_pending'), ('public','mcp_tokens'), ('public','share_links')) AS want(nsp, rel)
          JOIN pg_namespace n ON n.nspname = want.nsp
          JOIN pg_class c ON c.relnamespace = n.oid AND c.relname = want.rel AND c.relkind = 'r'
         WHERE pg_get_userbyid(c.relowner) = 'postgres'
         ORDER BY want.nsp, want.rel
    LOOP
        EXECUTE format('ALTER TABLE %I.%I OWNER TO tbox_owner', r.nsp, r.rel);
        changed := changed + 1;
        RAISE NOTICE 'owner_fix: 표 %.% postgres → tbox_owner', r.nsp, r.rel;
    END LOOP;

    -- 2) 시퀀스 — 위에서 표와 함께 옮겨지지 않고 아직 postgres 인 것만(새 문장이라 1)의 결과를 본다)
    FOR r IN
        SELECT want.nsp, want.rel
          FROM (VALUES ('public','api_tokens_id_seq'), ('public','fetch_hosts_id_seq'),
                       ('public','folders_id_seq'), ('public','share_links_id_seq')) AS want(nsp, rel)
          JOIN pg_namespace n ON n.nspname = want.nsp
          JOIN pg_class c ON c.relnamespace = n.oid AND c.relname = want.rel AND c.relkind = 'S'
         WHERE pg_get_userbyid(c.relowner) = 'postgres'
         ORDER BY want.rel
    LOOP
        EXECUTE format('ALTER SEQUENCE %I.%I OWNER TO tbox_owner', r.nsp, r.rel);
        changed := changed + 1;
        RAISE NOTICE 'owner_fix: 시퀀스 %.% postgres → tbox_owner', r.nsp, r.rel;
    END LOOP;

    -- 3) 함수 — 인자 없는 public.mcp_bump_security_epoch() 하나
    IF fn IS NOT NULL AND (SELECT pg_get_userbyid(p.proowner) FROM pg_proc p WHERE p.oid = fn) = 'postgres' THEN
        ALTER FUNCTION public.mcp_bump_security_epoch() OWNER TO tbox_owner;
        changed := changed + 1;
        RAISE NOTICE 'owner_fix: 함수 public.mcp_bump_security_epoch() postgres → tbox_owner';
    END IF;

    RAISE NOTICE 'owner_fix: 바꾼 것 % (표에 딸려 함께 옮겨진 시퀀스 · 인덱스는 세지 않음)', changed;
END
$owner_fix$;

COMMIT;
