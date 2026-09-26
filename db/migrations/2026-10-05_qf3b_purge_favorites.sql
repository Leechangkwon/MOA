-- db/migrations/2026-10-05_qf3b_purge_favorites.sql — 30일 정리가 즐겨찾기·최근 줄도 함께 지운다 (개발 대기열 6 · Q:F-3a 라운드 B)
--   (파일 이름의 날짜는 적용 **순서**용이다 — 앞 마이그레이션 2026-10-03_qf3a_favorites_recent.sql · 2026-10-04_users_temp_password_issued_at.sql(보안 넷 d52 — 서로 무관) 뒤. 작성 2026-09-26)
--
-- 지시서: /var/www/qaredi/docs/qf3a-favorites-recent-bulk-directive.md 2장 「정리: … 커뮤니티 7단계 30일 정리 배치에
--   page_favorites·page_recent 먼저 지우기 한 줄」 · 라운드 A 구현 기록 결정 D-2 · 남은 일 U-2 · 취합 메모(라운드 A 독립 검토 권고)
-- 구현 기록: docs/qf3a-favorites-recent-implementation.md 11장(라운드 B)
--
-- 실행 계정: postgres (함수 소유자는 7-A 와 같이 tbox_owner 로 다시 고정한다).
-- 실행 예:  sudo -u postgres psql -d tbox_re -v ON_ERROR_STOP=1 -f - < db/migrations/2026-10-05_qf3b_purge_favorites.sql
--   ★ 운영 반영은 취합 세션(사용자 승인 뒤 sudo). 앞 마이그레이션(2026-09-26_community_purge · 2026-10-03_qf3a) 이 먼저 있어야 한다 —
--     없으면 아무것도 바꾸지 않고 멈춘다(아래 확인).
--
-- 하는 일 (여러 번 돌려도 같다 — CREATE OR REPLACE · DELETE 는 이미 지운 뒤면 0행 · REVOKE 는 멱등)
--   1. 함수 community.purge_trash(dry, max) 를 **같은 몸 + 두 줄**로 다시 만든다 — 7-A 몸(2026-09-26_community_purge.sql)을 글자 그대로
--      옮기고, 페이지 내용을 지우는 줄들 사이에
--          DELETE FROM community.page_favorites … WHERE page_id = ANY (ids);
--          DELETE FROM community.page_recent    … WHERE page_id = ANY (ids);
--      두 줄만 더했다. 같은 트랜잭션·같은 잠금(FOR UPDATE SKIP LOCKED 로 고른 행)이라 「내용은 지웠는데 줄은 남음」이 생기지 않는다.
--      dry 이면 전처럼 **아무것도 지우지 않는다**(두 줄도 dry 확인 뒤에 있다).
--      왜 지우나: 영구 삭제된 페이지는 행이 남고(purged_at — 7-A 결정 J-1) 외래 키 CASCADE 가 불리지 않아, 그 페이지를 가리키는
--      즐겨찾기·최근 줄이 **영영** 남았다(목록 조건절 `deleted_at IS NULL` 이 걸러 화면·응답에는 안 나왔지만, 최근 50줄·즐겨찾기
--      200줄 자리를 차지했다). 기록이 아니라 개인 화면 설정이라 지워도 잃는 기록이 없다(2026-10-03 마이그레이션 머리글 4).
--   2. 이미 영구 삭제된 페이지를 가리키는 줄을 한 번 걷는다(이 마이그레이션 전에 정리된 페이지 몫 — `purged_at IS NOT NULL` 인 것만).
--   3. 표 넷에서 tbox_perm · tbox_push 권한을 한 번 더 걷는다(독립 검토 권고 「마이그레이션에 tbox_perm REVOKE 누락」 확인 결과를
--      글자로 남김) — 2026-09-26 실측: 운영 tbox_re 에 pg_default_acl 0행 · 네 표 ACL 에 두 역할 없음 → **바뀌는 것은 없다**.
--      새로 설치하는 DB·기본 권한(ALTER DEFAULT PRIVILEGES)이 생긴 뒤 다시 도는 경우에도 두 역할이 새 표를 못 읽게 적어 둔다.
--      역할이 없는 DB(F-70 전)면 그 역할은 건너뛴다.
--
-- 잠금: 함수 교체는 짧은 카탈로그 잠금. 2 의 DELETE 는 두 표(사람당 수십~수백 줄)에서 정리된 페이지 줄만 — `lock_timeout 5s`.
-- 되돌리기: 2026-09-26_community_purge.sql 의 함수 부분(CREATE OR REPLACE FUNCTION … $fn$; REVOKE; 소유자 DO 블록)을 다시 돌리면
--   옛 몸으로 돌아간다(지운 즐겨찾기·최근 줄은 영구 삭제된 페이지의 것이라 되살릴 까닭이 없다).

\set ON_ERROR_STOP on

BEGIN;
SET LOCAL lock_timeout = '5s';

DO $$
BEGIN
    IF to_regprocedure('community.purge_trash(boolean, integer)') IS NULL THEN
        RAISE EXCEPTION 'community.purge_trash 가 없다 — db/migrations/2026-09-26_community_purge.sql 을 먼저 돌린다';
    END IF;
    IF to_regclass('community.page_favorites') IS NULL OR to_regclass('community.page_recent') IS NULL THEN
        RAISE EXCEPTION '즐겨찾기·최근 표가 없다 — db/migrations/2026-10-03_qf3a_favorites_recent.sql 을 먼저 돌린다';
    END IF;
END
$$;

CREATE OR REPLACE FUNCTION community.purge_trash(p_dry boolean DEFAULT true, p_max integer DEFAULT 500)
RETURNS TABLE (page_id bigint, page_public_id uuid, was_title text)
LANGUAGE plpgsql
SECURITY INVOKER
SET search_path = community, public
AS $fn$
DECLARE
    ids bigint[];
BEGIN
    IF p_max IS NULL OR p_max < 1 OR p_max > 5000 THEN
        RAISE EXCEPTION 'p_max 는 1~5000';
    END IF;
    -- 고른 행을 **잠근다**(독립 검토 M-1) — 되살리기와 겨루면 한쪽이 기다리고, 잠긴 것은 이번에 건너뛴다
    SELECT coalesce(array_agg(x.id ORDER BY x.id), '{}') INTO ids
      FROM (SELECT p.id FROM community.pages p
             WHERE p.deleted_at IS NOT NULL AND p.purged_at IS NULL
               AND p.purge_after IS NOT NULL AND p.purge_after < now()
             ORDER BY p.id LIMIT p_max
             FOR UPDATE SKIP LOCKED) x;

    RETURN QUERY SELECT p.id, p.public_id, p.title FROM community.pages p WHERE p.id = ANY (ids) ORDER BY p.id;
    IF p_dry OR cardinality(ids) = 0 THEN
        RETURN;
    END IF;

    DELETE FROM community.notifications n
     WHERE n.page_id = ANY (ids)
        OR n.comment_id IN (SELECT c.id FROM community.comments c WHERE c.page_id = ANY (ids));
    DELETE FROM community.comment_reactions r
     WHERE r.comment_id IN (SELECT c.id FROM community.comments c WHERE c.page_id = ANY (ids));
    -- 답글(부모를 가리킴)부터
    DELETE FROM community.comments c WHERE c.page_id = ANY (ids) AND c.parent_comment_id IS NOT NULL;
    DELETE FROM community.comments c WHERE c.page_id = ANY (ids);
    DELETE FROM community.page_versions v      WHERE v.page_id = ANY (ids);
    DELETE FROM community.page_blocks b        WHERE b.page_id = ANY (ids);
    DELETE FROM community.page_links l         WHERE l.from_page_id = ANY (ids);
    DELETE FROM community.page_edit_sessions s WHERE s.page_id = ANY (ids);
    DELETE FROM community.page_shares s        WHERE s.page_id = ANY (ids);
    DELETE FROM community.page_tags t          WHERE t.page_id = ANY (ids);
    DELETE FROM community.page_views w         WHERE w.page_id = ANY (ids);
    DELETE FROM community.page_reads r         WHERE r.page_id = ANY (ids);
    -- Q:F-3a 라운드 B — 즐겨찾기·최근(개인 화면 설정)도 함께. 페이지 행은 남으므로 외래 키 CASCADE 가 불리지 않는다
    DELETE FROM community.page_favorites fv    WHERE fv.page_id = ANY (ids);
    DELETE FROM community.page_recent rc       WHERE rc.page_id = ANY (ids);
    DELETE FROM community.page_property_events e WHERE e.page_id = ANY (ids) AND e.prop LIKE 'db:%';
    UPDATE community.databases d SET schema = '[]'::jsonb, views = '[]'::jsonb, updated_at = now()
     WHERE d.page_id = ANY (ids);
    UPDATE community.pages p
       SET title = '영구 삭제된 페이지', icon = NULL,
           doc = '{"schema":1,"blocks":[]}'::jsonb, plain_text = '', search_tsv = NULL, block_count = 0,
           database_id = NULL, properties = NULL,
           purged_at = now()
     WHERE p.id = ANY (ids)
       AND p.deleted_at IS NOT NULL AND p.purged_at IS NULL;
    -- 감사는 이 함수가 아니라 부르는 쪽(scripts/community_purge.php)이 앱 감사(Audit — DB + 감사 파일 해시 체인)로 남긴다.
END
$fn$;

REVOKE ALL ON FUNCTION community.purge_trash(boolean, integer) FROM PUBLIC;
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'tbox_owner') THEN
        ALTER FUNCTION community.purge_trash(boolean, integer) OWNER TO tbox_owner;
    END IF;
END
$$;

-- 2. 이 마이그레이션 전에 이미 영구 삭제된 페이지를 가리키는 줄(한 번)
DELETE FROM community.page_favorites fv USING community.pages p
 WHERE p.id = fv.page_id AND p.purged_at IS NOT NULL;
DELETE FROM community.page_recent rc USING community.pages p
 WHERE p.id = rc.page_id AND p.purged_at IS NOT NULL;

-- 3. tbox_perm · tbox_push — 네 표에 권한 0 (있는 역할만)
DO $$
DECLARE
    r text;
BEGIN
    FOREACH r IN ARRAY ARRAY['tbox_perm', 'tbox_push'] LOOP
        IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = r) THEN
            EXECUTE format('REVOKE ALL ON public.file_favorites, public.folder_favorites, community.page_favorites, community.page_recent FROM %I', r);
        END IF;
    END LOOP;
END
$$;

COMMIT;
