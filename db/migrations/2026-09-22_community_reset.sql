-- ─────────────────────────────────────────────────────────────
-- 2026-09-22_community_reset.sql  (Qaredi D — 옛 커뮤니티 시험 앱이 만든 표를 비우고
--                                  스키마 `community` 를 t-box 쪽으로 넘긴다.
--                                  docs/integration-plan.md D,
--                                  docs/launcher/d-cleanup-directive.md)
--
-- 목적: 2026-09-21~22 의 시험 앱(`/var/www/community`, 1·2단계)이 스키마 `community` 에
--       만든 표 5개(`audit_logs`·`boards`·`comments`·`posts`·`sessions`)를 내리고,
--       스키마 자체는 **남긴 채** 소유를 `tbox_owner` 로 옮겨 커뮤니티 3단계 스키마가
--       들어올 빈자리를 만든다. 기획이 바뀌어 1·2단계 표는 쓰지 않는다.
--
-- ★ 스키마는 지우지 않는다 — `DROP SCHEMA community` 는 이 파일에 없다(3단계가 이 자리를 쓴다).
-- ★ 표는 만들지 않는다 — 3단계 스키마 파일(`db/community/schema.sql`)이 만든다.
-- ★ t-box 의 표·역할·권한은 한 글자도 바뀌지 않는다. 이 파일이 손대는 것은
--   스키마 `community` 와 그 안의 표뿐이다.
--
-- ★ 되돌릴 수 없다(표와 시험 데이터가 사라진다). 되돌리는 법 — 원본은 옛 앱 저장소에 있다:
--     · `/var/www/community` 태그 `v0.2.0` 의 `db/schema.sql`(표 정의) + `db/seed.sql`(시험 데이터)
--     · `/var/www/community/db/grants.sql`(옛 권한 — 역할 `community_app`·`community_owner` 가
--       살아 있을 때만 의미가 있다. 역할은 `deploy/uninstall_community_app.sh` 3단계가 지운다)
--     · 되살리려면: 역할 둘을 다시 만들고(`/var/www/community/deploy/install.sh` ③),
--       `CREATE SCHEMA` 없이 이 스키마에 `schema.sql` → `seed.sql` → `grants.sql` 순서로 적용.
--     · 데이터(게시판 3 · 글 3 · 댓글 2 · 세션 6 · 감사 61행)는 시험용이라 따로 백업하지 않는다.
--       남겨야 한다면 이 파일을 돌리기 **전에**:
--         sudo -u postgres pg_dump -n community tbox_re > ~/community-1-2stage-2026-09-22.sql
--
-- 실행 (사용자가 한다 — CLAUDE.md 4장 「구현자는 운영 DB DDL 을 돌리지 않는다」).
-- 보통은 정리 스크립트가 이 파일을 대신 돌린다:
--     sudo bash deploy/uninstall_community_app.sh
-- 직접 돌린다면:
--     sudo -u postgres psql -v ON_ERROR_STOP=1 -d tbox_re -f db/migrations/2026-09-22_community_reset.sql
--
-- 확인
--   sudo -u postgres psql -d tbox_re -c '\dt community.*'   → 0 rows ("Did not find any relation")
--   sudo -u postgres psql -d tbox_re -c '\dn+ community'    → Owner = tbox_owner,
--                                                             Access privileges 에 tbox_app·tbox_backup 의 U
--
-- 멱등: 두 번 돌려도 안전하다. 표가 없으면 ①의 행 수 확인을 건너뛰고, ②는 IF EXISTS 로 지나가며,
--       ③④⑤는 이미 그 상태여도 같은 결과가 된다.
-- ─────────────────────────────────────────────────────────────

BEGIN;

-- ─── ① 사전 확인 — 운영 데이터를 모르고 지우지 않는다 ───────────────────────
--
-- 이 스키마는 「시험 데이터만 있는 1·2단계 표」라는 전제 위에서만 지워도 된다.
-- 전제가 깨졌다면(누군가 실제로 쓰기 시작했다면) 여기서 멈춘다. 기준은
-- `deploy/uninstall_community_app.sh` 1단계와 같은 값이다 — 글·댓글 각 10행 이하.
-- `tbox_owner` 가 없으면 t-box 가 정상인 서버가 아니므로 역시 멈춘다.
DO $$
DECLARE
  max_rows  CONSTANT integer := 10;
  n_posts    integer;
  n_comments integer;
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'tbox_owner') THEN
    RAISE EXCEPTION '역할 tbox_owner 가 없다 — t-box 가 설치된 서버에서만 돌린다';
  END IF;

  IF NOT EXISTS (SELECT 1 FROM pg_namespace WHERE nspname = 'community') THEN
    RAISE EXCEPTION '스키마 community 가 없다 — 이 파일은 스키마를 만들지 않는다';
  END IF;

  IF to_regclass('community.posts') IS NOT NULL THEN
    EXECUTE 'SELECT count(*) FROM community.posts' INTO n_posts;
    IF n_posts > max_rows THEN
      RAISE EXCEPTION 'community.posts 가 %행이다 (상한 %행) — 시험 데이터가 아닐 수 있다. 사람이 확인하기 전엔 지우지 않는다',
        n_posts, max_rows;
    END IF;
    RAISE NOTICE '① community.posts % 행 (상한 % 행) — 시험 데이터 범위', n_posts, max_rows;
  ELSE
    RAISE NOTICE '① community.posts 없음 — 이미 정리된 상태';
  END IF;

  IF to_regclass('community.comments') IS NOT NULL THEN
    EXECUTE 'SELECT count(*) FROM community.comments' INTO n_comments;
    IF n_comments > max_rows THEN
      RAISE EXCEPTION 'community.comments 가 %행이다 (상한 %행) — 시험 데이터가 아닐 수 있다. 사람이 확인하기 전엔 지우지 않는다',
        n_comments, max_rows;
    END IF;
    RAISE NOTICE '① community.comments % 행 (상한 % 행) — 시험 데이터 범위', n_comments, max_rows;
  ELSE
    RAISE NOTICE '① community.comments 없음 — 이미 정리된 상태';
  END IF;
END
$$;

-- ─── ② 1·2단계 표 내리기 ─────────────────────────────────────────────
--
-- 다섯 개뿐이다(2026-09-22 `pg_dump -s -n community tbox_re` 로 실측). 이름을 하나씩 적는다 —
-- 「스키마 안의 모든 표」 같은 반복문을 쓰지 않는 이유는, 나중에 3단계 표가 생긴 뒤
-- 이 파일을 실수로 다시 돌려도 **여기 적힌 다섯 개만** 없어지게 하기 위해서다.
-- CASCADE 는 이 다섯 표 사이의 외래 키와 딸린 시퀀스·인덱스를 함께 내리려는 것이다
-- (`public.users`·`public.teams` 쪽으로는 번지지 않는다 — 참조 방향이 반대다).
DROP TABLE IF EXISTS
  community.sessions,
  community.comments,
  community.posts,
  community.boards,
  community.audit_logs
CASCADE;

-- ─── ③ 스키마 소유를 t-box 쪽으로 ───────────────────────────────────
--
-- 소유가 바뀌면 PostgreSQL 이 이 스키마 ACL 안의 `community_owner` 표기를
-- `tbox_owner` 로 함께 바꿔 준다(aclnewowner). 그래서 ④의 REVOKE 가 통한다.
ALTER SCHEMA community OWNER TO tbox_owner;

-- ─── ④ 옛 역할의 스키마 권한 회수 ───────────────────────────────────
--
-- 역할은 `deploy/uninstall_community_app.sh` 3단계가 지운다. 이 파일은 그보다 먼저 돌 수도,
-- 역할이 이미 없는 상태에서 다시 돌 수도 있으므로 존재할 때만 회수한다.
DO $$
BEGIN
  IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'community_app') THEN
    EXECUTE 'REVOKE ALL ON SCHEMA community FROM community_app';
    RAISE NOTICE '④ community_app 의 스키마 권한 회수';
  ELSE
    RAISE NOTICE '④ community_app 없음 — 건너뜀';
  END IF;

  IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'community_owner') THEN
    EXECUTE 'REVOKE ALL ON SCHEMA community FROM community_owner';
    RAISE NOTICE '④ community_owner 의 스키마 권한 회수';
  ELSE
    RAISE NOTICE '④ community_owner 없음 — 건너뜀';
  END IF;
END
$$;

-- ─── ⑤ t-box 역할에 스키마 사용 권한 ────────────────────────────────
--
-- USAGE 만이다(표 권한이 아니다 — 표가 0개다). 3단계 표가 생길 때 그쪽
-- `grants.sql` 이 표 단위 권한을 준다. `tbox_backup` 은 백업 범위를 유지하기 위해서고
-- (ARCHITECTURE 10장 「전체 SELECT」), `tbox_app` 은 3단계 앱 런타임의 자리다.
GRANT USAGE ON SCHEMA community TO tbox_app, tbox_backup;

COMMIT;

-- 돌린 뒤 스스로 보는 값 (psql 이 그대로 출력한다)
\echo '── 정리 후 상태 ──'
\dn+ community
\dt community.*
