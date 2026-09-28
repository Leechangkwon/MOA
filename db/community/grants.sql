-- db/community/grants.sql — 스키마 `community` 의 최소 권한 (3단계)
--
-- 요구사항: 커뮤니티 `C:S-14`(기록 불변), ../../../community/ARCHITECTURE.md 21.2,
--           t-box `db/grants.sql` 과 같은 원칙(ARCH 10장, PRD S-09-10)
--
-- 실행 계정: postgres (슈퍼유저). db/community/schema.sql 이 끝난 뒤 실행한다.
-- 실행 예:  sudo -u postgres psql -d tbox_re -v ON_ERROR_STOP=1 -f db/community/grants.sql
--
-- 원칙
--   · 한 앱·한 로그인이므로 런타임 계정은 t-box 와 **같은 `tbox_app`** 이다
--     (v0.1 설계의 `community_app`·`comm_session` 은 폐기 — ARCH 21.2).
--   · 「필요한 표에, 필요한 동작만」:
--       spaces · pages · page_edit_sessions · page_blocks · page_links   SELECT·INSERT·UPDATE·DELETE
--       page_versions · checklist_events · page_property_events          SELECT·INSERT 만 ★
--   · ★ 이 **세 표**에 UPDATE·DELETE 를 주지 않는 것이 C:S-14 「지울 수 없는 기록」의 전부다.
--     코드에 결함이 있어도, 앱 계정을 얻은 사람도 판 이력·체크 기록·상태/담당자 변경 기록을
--     고치거나 지울 수 없다 (4-D 에 `page_property_events` 가 합류했다).
--   · DROP / TRUNCATE / ALTER 는 어디에도 주지 않는다(표 소유자 tbox_owner 만).
--   · 스키마 `community` 에 표를 만들 권한(CREATE)은 PUBLIC 에서 회수한다.
--   · `tbox_backup` — t-box 백업이 DB 전체를 pg_dump 한다. 이 권한이 없으면 새 스키마에서
--     `permission denied` 로 백업이 통째로 실패한다(설계 5.2 의 경고 그대로).
--   · 데이터베이스 수준 권한(CONNECT)은 건드리지 않는다 — t-box `db/grants.sql` 이 이미 정했고,
--     두 파일이 서로의 권한을 지우지 않게 하려는 것이다.

\set ON_ERROR_STOP on

-- 역할이 없으면 조용히 넘어가지 않고 멈춘다 (t-box grants.sql 의 tbox_backup 검사와 같은 태도).
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'tbox_app') THEN
        RAISE EXCEPTION 'tbox_app 역할이 없다 — deploy/install.sh 가 먼저 만든다 (ARCH 10장)';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'tbox_backup') THEN
        RAISE EXCEPTION 'tbox_backup 역할이 없다 — deploy/install.sh 가 먼저 만든다 (ARCH 10장)';
    END IF;
END
$$;

-- ─── 스키마 ───────────────────────────────────────────────────
REVOKE CREATE ON SCHEMA community FROM PUBLIC;
GRANT  USAGE  ON SCHEMA community TO tbox_app;
GRANT  USAGE  ON SCHEMA community TO tbox_backup;

-- ─── 먼저 전부 회수한다(재실행 시 과거 권한이 남지 않게) ───
REVOKE ALL ON community.spaces, community.pages, community.page_versions,
              community.page_blocks, community.page_links,
              community.checklist_events, community.page_edit_sessions,
              community.page_property_events,
              community.page_shares, community.handovers,
              community.page_tags, community.page_views,
              community.comments, community.comment_reactions, community.notifications, community.page_reads,
              community.databases, community.templates, community.embed_domains
       FROM tbox_app;

-- ─── 표별 권한 (앱 런타임) ────────────────────────────────────
GRANT SELECT, INSERT, UPDATE, DELETE ON community.spaces             TO tbox_app;
GRANT SELECT, INSERT, UPDATE, DELETE ON community.pages              TO tbox_app;
GRANT SELECT, INSERT, UPDATE, DELETE ON community.page_edit_sessions TO tbox_app;
GRANT SELECT, INSERT, UPDATE, DELETE ON community.page_blocks        TO tbox_app;
GRANT SELECT, INSERT, UPDATE, DELETE ON community.page_links         TO tbox_app;
-- 5-A 사람 지정 공유 — **바뀌는 값**이다(등급 바꾸기·빼기). 바뀐 일은 감사 `page.share` 가 남긴다.
GRANT SELECT, INSERT, UPDATE, DELETE ON community.page_shares        TO tbox_app;
-- 5-C 태그 — 바뀌는 값이다(고쳐 쓰면 그 페이지의 줄을 지우고 다시 넣는다). 바뀐 일은 감사 `page.tags` 가 남긴다.
GRANT SELECT, INSERT, UPDATE, DELETE ON community.page_tags          TO tbox_app;
-- ★ 기록 불변 (C:S-14) — 이 셋은 SELECT·INSERT 뿐이다.
GRANT SELECT, INSERT                 ON community.page_versions        TO tbox_app;
GRANT SELECT, INSERT                 ON community.checklist_events     TO tbox_app;
GRANT SELECT, INSERT                 ON community.page_property_events TO tbox_app;
-- 5-A 인계 기록 (C:S-14) — 한 번 남은 인계는 고치지도 지우지도 못한다.
GRANT SELECT, INSERT                 ON community.handovers            TO tbox_app;
-- 5-C 조회 기록 — 한 사람 하루 한 줄(`ON CONFLICT DO NOTHING`). 고치지도 지우지도 않는다.
GRANT SELECT, INSERT                 ON community.page_views           TO tbox_app;
-- 5-D 댓글 — 지우기는 논리 삭제(`deleted_at`)라 DELETE 가 없다. 고치기·해결됨·숨김은 UPDATE.
GRANT SELECT, INSERT, UPDATE         ON community.comments             TO tbox_app;
-- 5-D 「도움됐어요」 — 누르고 빼는 값이라 DELETE 가 있다.
GRANT SELECT, INSERT, DELETE         ON community.comment_reactions    TO tbox_app;
-- 5-D 알림 — 읽음 표시만 고친다(DELETE 없음, 정리 배치는 소유자 계정으로).
GRANT SELECT, INSERT, UPDATE         ON community.notifications        TO tbox_app;
-- 5-D 공지 읽음 (C:S-14) — 한 줄을 고쳐 「마지막 읽은 때」만 민다. 지울 수 없다.
GRANT SELECT, INSERT, UPDATE         ON community.page_reads           TO tbox_app;
-- 6-A 데이터베이스 — 스키마(속성 목록)를 고친다. DB 를 없애는 일은 DB 페이지를 휴지통에 넣는 것이라 DELETE 가 없다.
GRANT SELECT, INSERT, UPDATE         ON community.databases            TO tbox_app;
-- 7-B 템플릿 — 지우기는 논리 삭제(`deleted_at`)라 DELETE 가 없다. 만든 일·지운 일은 감사 `template.*` 가 남긴다.
GRANT SELECT, INSERT, UPDATE         ON community.templates            TO tbox_app;
-- 7-C 임베드 허용 목록 — 빼기는 removed_at(DELETE 없음). 더한 일·뺀 일은 감사 `embed.domain` 이 남긴다.
GRANT SELECT, INSERT, UPDATE         ON community.embed_domains        TO tbox_app;

-- ─── 시퀀스 (INSERT 하는 표의 id 발급) ────────────────────────
GRANT USAGE, SELECT ON SEQUENCE community.spaces_id_seq            TO tbox_app;
GRANT USAGE, SELECT ON SEQUENCE community.pages_id_seq             TO tbox_app;
GRANT USAGE, SELECT ON SEQUENCE community.page_versions_id_seq     TO tbox_app;
GRANT USAGE, SELECT ON SEQUENCE community.checklist_events_id_seq  TO tbox_app;
GRANT USAGE, SELECT ON SEQUENCE community.page_property_events_id_seq TO tbox_app;
GRANT USAGE, SELECT ON SEQUENCE community.page_shares_id_seq       TO tbox_app;
GRANT USAGE, SELECT ON SEQUENCE community.handovers_id_seq         TO tbox_app;
GRANT USAGE, SELECT ON SEQUENCE community.comments_id_seq          TO tbox_app;
GRANT USAGE, SELECT ON SEQUENCE community.notifications_id_seq     TO tbox_app;
GRANT USAGE, SELECT ON SEQUENCE community.databases_id_seq         TO tbox_app;
GRANT USAGE, SELECT ON SEQUENCE community.templates_id_seq         TO tbox_app;
GRANT USAGE, SELECT ON SEQUENCE community.embed_domains_id_seq     TO tbox_app;
-- page_blocks·page_links·page_edit_sessions·page_tags·page_views 는 복합 기본키라 시퀀스가 없다.

-- ─── tbox_backup (읽기 전용 백업 계정) ────────────────────────
REVOKE ALL ON community.spaces, community.pages, community.page_versions,
              community.page_blocks, community.page_links,
              community.checklist_events, community.page_edit_sessions,
              community.page_property_events,
              community.page_shares, community.handovers,
              community.page_tags, community.page_views,
              community.comments, community.comment_reactions, community.notifications, community.page_reads,
              community.databases, community.templates, community.embed_domains
       FROM tbox_backup;

GRANT SELECT ON ALL TABLES    IN SCHEMA community TO tbox_backup;   -- ★ SELECT 만
GRANT SELECT ON ALL SEQUENCES IN SCHEMA community TO tbox_backup;   -- 시퀀스 현재값 (pg_dump)

-- 앞으로 만들 표에 자동으로 권한이 붙지 않게 기본 권한(ALTER DEFAULT PRIVILEGES)은 두지 않는다.
-- 새 표를 만들면 이 파일에 한 줄을 더해 다시 실행한다 — 백업에서 표가 빠지는 것은 여기서
-- 드러나야 하고, 조용히 권한이 따라붙는 것보다 낫다 (t-box db/grants.sql 과 같은 판단).

-- ─── 즐겨찾기 · 최근 (개발 대기열 6 · Q:F-3a 라운드 A — 커뮤니티 ARCH 21.24) — 표 둘(page_favorites · page_recent).
--     표를 만드는 것은 db/community/schema.sql(새 설치)·db/migrations/2026-10-03_qf3a_favorites_recent.sql(기존 DB)이고, 이 절은 **같은
--     권한**을 다시 건다. 표가 없으면(마이그레이션 전) 아무것도 하지 않고 NOTICE 만 남긴다(위 목록에 넣지 않은 까닭 — 없는 표를
--     REVOKE 하면 이 파일 전체가 멈춘다).
--   page_favorites  SELECT · INSERT(user_id·page_id·position) · UPDATE(position — 끌어서 순서만) · DELETE(빼기)
--   page_recent     SELECT · INSERT(user_id·page_id — 시각은 기본값) · UPDATE(last_viewed_at — 5분에 한 번) · DELETE
--                   (★ 기록이 아니라 화면 편의 — 50줄 넘으면 오래된 것부터 · 「최근 기록 지우기」 — 결정 M-3)
--   tbox_backup     SELECT 만
DO $$
BEGIN
    IF to_regclass('community.page_favorites') IS NULL OR to_regclass('community.page_recent') IS NULL THEN
        RAISE NOTICE '즐겨찾기·최근 표가 없다 — db/migrations/2026-10-03_qf3a_favorites_recent.sql 을 먼저 돌린다(이 절은 건너뜀)';
        RETURN;
    END IF;
    REVOKE ALL ON community.page_favorites, community.page_recent FROM tbox_app, tbox_backup;
    GRANT SELECT, DELETE                      ON community.page_favorites, community.page_recent TO tbox_app;
    GRANT INSERT (user_id, page_id, position) ON community.page_favorites TO tbox_app;
    GRANT UPDATE (position)                   ON community.page_favorites TO tbox_app;
    GRANT INSERT (user_id, page_id)           ON community.page_recent    TO tbox_app;
    GRANT UPDATE (last_viewed_at)             ON community.page_recent    TO tbox_app;
    GRANT SELECT                              ON community.page_favorites, community.page_recent TO tbox_backup;
END
$$;

-- ─── 7-D2 관리자 팔레트 (커뮤니티 결정 L-2 · 지시서 1.2) — 표 하나(palettes).
--     표를 만드는 것은 db/community/schema.sql(새 설치)·db/migrations/2026-10-07_community_palettes.sql(기존 DB)이고, 이 절은 **같은
--     권한**을 다시 건다(마이그레이션 끝과 같은 줄 — 어느 쪽을 다시 돌려도 같은 권한). 표가 없으면(마이그레이션 전) 아무것도 하지 않고
--     NOTICE 만 남긴다(위 목록에 넣지 않은 까닭 — 없는 표를 REVOKE 하면 이 파일 전체가 멈춘다).
--   palettes  tbox_app SELECT · INSERT · UPDATE(★ DELETE 없음 — 빼기는 removed_at) + 시퀀스 USAGE·SELECT · tbox_backup SELECT 만 · tbox_perm·tbox_push 0
DO $$
BEGIN
    IF to_regclass('community.palettes') IS NULL THEN
        RAISE NOTICE '팔레트 표가 없다 — db/migrations/2026-10-07_community_palettes.sql 을 먼저 돌린다(이 절은 건너뜀)';
        RETURN;
    END IF;
    REVOKE ALL ON community.palettes FROM tbox_app, tbox_backup;
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'tbox_perm') THEN
        EXECUTE 'REVOKE ALL ON community.palettes FROM tbox_perm';
    END IF;
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'tbox_push') THEN
        EXECUTE 'REVOKE ALL ON community.palettes FROM tbox_push';
    END IF;
    GRANT SELECT, INSERT, UPDATE ON community.palettes TO tbox_app;
    GRANT USAGE, SELECT ON SEQUENCE community.palettes_id_seq TO tbox_app;
    GRANT SELECT ON community.palettes TO tbox_backup;
    GRANT SELECT ON SEQUENCE community.palettes_id_seq TO tbox_backup;
END
$$;
