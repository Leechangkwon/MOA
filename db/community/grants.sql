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
              community.page_tags, community.page_views
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

-- ─── 시퀀스 (INSERT 하는 표의 id 발급) ────────────────────────
GRANT USAGE, SELECT ON SEQUENCE community.spaces_id_seq            TO tbox_app;
GRANT USAGE, SELECT ON SEQUENCE community.pages_id_seq             TO tbox_app;
GRANT USAGE, SELECT ON SEQUENCE community.page_versions_id_seq     TO tbox_app;
GRANT USAGE, SELECT ON SEQUENCE community.checklist_events_id_seq  TO tbox_app;
GRANT USAGE, SELECT ON SEQUENCE community.page_property_events_id_seq TO tbox_app;
GRANT USAGE, SELECT ON SEQUENCE community.page_shares_id_seq       TO tbox_app;
GRANT USAGE, SELECT ON SEQUENCE community.handovers_id_seq         TO tbox_app;
-- page_blocks·page_links·page_edit_sessions·page_tags·page_views 는 복합 기본키라 시퀀스가 없다.

-- ─── tbox_backup (읽기 전용 백업 계정) ────────────────────────
REVOKE ALL ON community.spaces, community.pages, community.page_versions,
              community.page_blocks, community.page_links,
              community.checklist_events, community.page_edit_sessions,
              community.page_property_events,
              community.page_shares, community.handovers,
              community.page_tags, community.page_views
       FROM tbox_backup;

GRANT SELECT ON ALL TABLES    IN SCHEMA community TO tbox_backup;   -- ★ SELECT 만
GRANT SELECT ON ALL SEQUENCES IN SCHEMA community TO tbox_backup;   -- 시퀀스 현재값 (pg_dump)

-- 앞으로 만들 표에 자동으로 권한이 붙지 않게 기본 권한(ALTER DEFAULT PRIVILEGES)은 두지 않는다.
-- 새 표를 만들면 이 파일에 한 줄을 더해 다시 실행한다 — 백업에서 표가 빠지는 것은 여기서
-- 드러나야 하고, 조용히 권한이 따라붙는 것보다 낫다 (t-box db/grants.sql 과 같은 판단).
