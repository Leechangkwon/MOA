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

-- Stage 9 immutable execution log; undo is the only mutable run metadata.
REVOKE ALL ON community.automations, community.automation_runs, community.automation_queue FROM tbox_app;
GRANT SELECT, INSERT, UPDATE ON community.automations TO tbox_app;
GRANT SELECT, INSERT ON community.automation_runs, community.automation_queue TO tbox_app;
GRANT UPDATE(undone_at,undone_by) ON community.automation_runs TO tbox_app;
GRANT UPDATE(taken_at) ON community.automation_queue TO tbox_app;
GRANT USAGE, SELECT ON SEQUENCE community.automations_id_seq, community.automation_runs_id_seq, community.automation_queue_id_seq TO tbox_app;
GRANT SELECT ON community.automations, community.automation_runs, community.automation_queue TO tbox_backup;
GRANT SELECT ON SEQUENCE community.automations_id_seq, community.automation_runs_id_seq, community.automation_queue_id_seq TO tbox_backup;

REVOKE ALL ON community.page_suggestions FROM tbox_app, tbox_backup;
GRANT SELECT, INSERT ON community.page_suggestions TO tbox_app;
GRANT UPDATE(status,decided_by,decided_at,decision_note,applied_version) ON community.page_suggestions TO tbox_app;
GRANT USAGE, SELECT ON SEQUENCE community.page_suggestions_id_seq TO tbox_app;
GRANT SELECT ON community.page_suggestions TO tbox_backup;
GRANT SELECT ON SEQUENCE community.page_suggestions_id_seq TO tbox_backup;

-- Stage 12: immutable events/publications use restricted grants.
GRANT SELECT,INSERT,UPDATE,DELETE ON community.collections,community.collection_items,community.share_group_members,community.synced_references,community.user_messenger TO tbox_app;
GRANT SELECT,INSERT ON community.group_member_events,community.web_publication_pages,community.public_form_uses TO tbox_app;
GRANT SELECT,INSERT,UPDATE ON community.web_publications,community.public_rate_buckets,community.messenger_hooks,community.notification_outbox,community.data_connectors TO tbox_app;
GRANT USAGE,SELECT ON SEQUENCE community.collections_id_seq,community.share_groups_id_seq,community.group_member_events_id_seq,community.synced_sources_id_seq,community.web_publications_id_seq,community.messenger_hooks_id_seq,community.notification_outbox_id_seq,community.data_connectors_id_seq TO tbox_app;
REVOKE UPDATE,DELETE ON community.group_member_events,community.public_form_uses,community.web_publication_pages FROM tbox_app;
REVOKE DELETE ON community.web_publications FROM tbox_app;

REVOKE DELETE ON community.share_groups,community.synced_sources FROM tbox_app;
GRANT SELECT,INSERT,UPDATE ON community.share_groups,community.synced_sources TO tbox_app;
GRANT SELECT ON community.collections,community.collection_items,community.share_groups,community.share_group_members,community.group_member_events,community.synced_sources,community.synced_references,community.web_publications,community.web_publication_pages,community.public_form_uses,community.public_rate_buckets,community.messenger_hooks,community.user_messenger,community.notification_outbox,community.data_connectors TO tbox_backup;
GRANT SELECT ON SEQUENCE community.collections_id_seq,community.share_groups_id_seq,community.group_member_events_id_seq,community.synced_sources_id_seq,community.web_publications_id_seq,community.messenger_hooks_id_seq,community.notification_outbox_id_seq,community.data_connectors_id_seq TO tbox_backup;

-- AI history is append-only for every application connection.
REVOKE ALL ON community.ai_settings,community.ai_page_permissions,community.ai_requests,community.ai_log,community.page_embeddings,community.embedding_queue FROM PUBLIC,tbox_app,tbox_backup;
GRANT SELECT,UPDATE ON community.ai_settings TO tbox_app;
GRANT SELECT,INSERT,UPDATE,DELETE ON community.ai_page_permissions,community.page_embeddings,community.embedding_queue TO tbox_app;
GRANT SELECT,INSERT ON community.ai_requests,community.ai_log TO tbox_app;
GRANT USAGE,SELECT ON SEQUENCE community.ai_requests_id_seq,community.ai_log_id_seq TO tbox_app;
GRANT SELECT ON community.ai_settings,community.ai_page_permissions,community.ai_requests,community.ai_log,community.page_embeddings,community.embedding_queue TO tbox_backup;
GRANT SELECT ON SEQUENCE community.ai_requests_id_seq,community.ai_log_id_seq TO tbox_backup;
-- Travel records. Conditional so existing installations can apply grants before the feature migration.
DO $$ BEGIN
 IF to_regclass('community.memory_links') IS NOT NULL THEN
  GRANT SELECT,INSERT,UPDATE,DELETE ON community.memory_posts,community.memory_media,community.memory_likes,community.memory_comments,community.memory_links TO tbox_app;
  GRANT SELECT ON community.memory_posts,community.memory_media,community.memory_likes,community.memory_comments,community.memory_links TO tbox_backup;
 END IF;
END $$;

-- Follow, travel and guide data use the same narrow application/backup grants as migrations.
DO $$ BEGIN
 IF to_regclass('community.memory_follows') IS NOT NULL THEN
  GRANT SELECT,INSERT,UPDATE,DELETE ON community.memory_follows,community.memory_preferences TO tbox_app;
  GRANT SELECT ON community.memory_follows,community.memory_preferences TO tbox_backup;
 END IF;
 IF to_regclass('community.travel_plans') IS NOT NULL THEN
  GRANT SELECT,INSERT,UPDATE,DELETE ON community.travel_plans,community.travel_plan_records TO tbox_app;
  GRANT SELECT ON community.travel_plans,community.travel_plan_records TO tbox_backup;
 END IF;
 IF to_regclass('community.usage_guides') IS NOT NULL THEN
  GRANT SELECT ON community.usage_guide_versions TO tbox_app;
  GRANT SELECT,INSERT,UPDATE ON community.usage_guides TO tbox_app;
  GRANT SELECT ON community.usage_guide_versions,community.usage_guides TO tbox_backup;
 END IF;
END $$;

-- Apply messenger migration before these optional grants.
DO $$ BEGIN
 IF to_regclass('community.gpt_turns') IS NOT NULL THEN
  GRANT SELECT,INSERT,UPDATE,DELETE ON community.conversations,community.messages,community.gpt_turns TO tbox_app;
  GRANT USAGE,SELECT ON SEQUENCE community.messages_id_seq,community.gpt_turns_id_seq TO tbox_app;
  GRANT SELECT ON community.conversations,community.messages,community.gpt_turns TO tbox_backup;
  GRANT SELECT ON SEQUENCE community.messages_id_seq,community.gpt_turns_id_seq TO tbox_backup;
 END IF;
END $$;

-- ─── Q:F-12 업무 화면 (지시서 A · 커뮤니티 ARCHITECTURE 23.2) — 표 다섯.
--     표를 만드는 것은 db/community/schema.sql(새 설치)·db/migrations/2026-10-19_qf12_work.sql(기존 DB)이고, 이 절은 **같은
--     권한**을 다시 건다(마이그레이션 6 절과 같은 줄). 표가 없으면(마이그레이션 전) NOTICE 만 남기고 건너뛴다.
--   my_tasks_projects       SELECT · INSERT · DELETE(지운 페이지 정리 — UPDATE 없음)
--   project_status_updates  ★ SELECT · INSERT 만(지울 수 없는 기록 — page_property_events 와 같은 취급)
--   portfolios              SELECT · INSERT · UPDATE(이름 · deleted_at) — ★ DELETE 없음(지우기 = deleted_at)
--   portfolio_items         SELECT · INSERT · UPDATE(position) · DELETE(빼기 — 사건은 portfolio_events)
--   portfolio_events        ★ SELECT · INSERT 만
--   tbox_backup             SELECT 만 · tbox_perm · tbox_push 0
DO $$
BEGIN
    IF to_regclass('community.portfolio_events') IS NULL OR to_regclass('community.my_tasks_projects') IS NULL THEN
        RAISE NOTICE 'Q:F-12 표가 없다 — db/migrations/2026-10-19_qf12_work.sql 을 먼저 돌린다(이 절은 건너뜀)';
        RETURN;
    END IF;
    REVOKE ALL ON community.my_tasks_projects, community.project_status_updates, community.portfolios,
                  community.portfolio_items, community.portfolio_events FROM tbox_app, tbox_backup;
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'tbox_perm') THEN
        EXECUTE 'REVOKE ALL ON community.my_tasks_projects, community.project_status_updates, community.portfolios, community.portfolio_items, community.portfolio_events FROM tbox_perm';
    END IF;
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'tbox_push') THEN
        EXECUTE 'REVOKE ALL ON community.my_tasks_projects, community.project_status_updates, community.portfolios, community.portfolio_items, community.portfolio_events FROM tbox_push';
    END IF;
    GRANT SELECT, INSERT, DELETE         ON community.my_tasks_projects      TO tbox_app;
    GRANT SELECT, INSERT                 ON community.project_status_updates TO tbox_app;
    GRANT SELECT, INSERT, UPDATE         ON community.portfolios             TO tbox_app;
    GRANT SELECT, INSERT, UPDATE, DELETE ON community.portfolio_items        TO tbox_app;
    GRANT SELECT, INSERT                 ON community.portfolio_events       TO tbox_app;
    GRANT USAGE, SELECT ON SEQUENCE community.project_status_updates_id_seq, community.portfolios_id_seq,
                                    community.portfolio_events_id_seq TO tbox_app;
    GRANT SELECT ON community.my_tasks_projects, community.project_status_updates, community.portfolios,
                    community.portfolio_items, community.portfolio_events TO tbox_backup;
    GRANT SELECT ON SEQUENCE community.project_status_updates_id_seq, community.portfolios_id_seq,
                             community.portfolio_events_id_seq TO tbox_backup;
END
$$;

-- ─── Q:F-12 U3-1 문서 전용 첨부 (커뮤니티 ARCHITECTURE 24.2) — 표 둘.
--     표를 만드는 것은 db/community/schema.sql(새 설치)·db/migrations/2026-10-13_community_page_attachments.sql(기존 DB)이고,
--     이 절은 **같은 권한**을 다시 건다(마이그레이션 4 절과 같은 줄). 표가 없으면(마이그레이션 전) NOTICE 만 남기고 건너뛴다.
--   page_attachments             SELECT · INSERT · UPDATE(scan_status 한 칸 — 검사 배치) — ★ DELETE 없음(영구 삭제 함수만)
--   page_attachment_purge_queue  앱 권한 0 (소유자의 purge_trash · scripts/community_purge.php 만)
--   tbox_backup                  SELECT 만 · tbox_perm · tbox_push 0
DO $$
BEGIN
    IF to_regclass('community.page_attachments') IS NULL OR to_regclass('community.page_attachment_purge_queue') IS NULL THEN
        RAISE NOTICE '문서 전용 첨부 표가 없다 — db/migrations/2026-10-13_community_page_attachments.sql 을 먼저 돌린다(이 절은 건너뜀)';
        RETURN;
    END IF;
    REVOKE ALL ON community.page_attachments, community.page_attachment_purge_queue FROM tbox_app, tbox_backup;
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'tbox_perm') THEN
        EXECUTE 'REVOKE ALL ON community.page_attachments, community.page_attachment_purge_queue FROM tbox_perm';
    END IF;
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'tbox_push') THEN
        EXECUTE 'REVOKE ALL ON community.page_attachments, community.page_attachment_purge_queue FROM tbox_push';
    END IF;
    GRANT SELECT, INSERT       ON community.page_attachments TO tbox_app;
    GRANT UPDATE (scan_status) ON community.page_attachments TO tbox_app;
    GRANT USAGE, SELECT ON SEQUENCE community.page_attachments_id_seq TO tbox_app;
    GRANT SELECT ON community.page_attachments, community.page_attachment_purge_queue TO tbox_backup;
    GRANT SELECT ON SEQUENCE community.page_attachments_id_seq TO tbox_backup;
END
$$;

-- ─── Q:F-12 U3-1b — 대기열 확인 함수 community.page_attachment_queued(text[]) (마이그레이션 7 절과 같은 줄).
--     정합성 검사(scripts/consistency_check.php — 앱 계정)만 부른다. 대기열 표 자체의 앱 권한은 위 절 그대로 0.
--     탈퇴 파기 함수(community.privacy_purge_pages)의 권한은 db/grants.sql 「탈퇴 요청 · 보관 기간 파기」 절이 그대로 건다(소유자만).
DO $$
BEGIN
    IF to_regprocedure('community.page_attachment_queued(text[])') IS NULL THEN
        RAISE NOTICE '대기열 확인 함수가 없다 — db/migrations/2026-10-13_community_page_attachments.sql 을 먼저 돌린다(이 절은 건너뜀)';
        RETURN;
    END IF;
    REVOKE ALL ON FUNCTION community.page_attachment_queued(text[]) FROM PUBLIC;
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'tbox_backup') THEN
        REVOKE ALL ON FUNCTION community.page_attachment_queued(text[]) FROM tbox_backup;
    END IF;
    GRANT EXECUTE ON FUNCTION community.page_attachment_queued(text[]) TO tbox_app;
END
$$;

-- ─── 「우리 공간」 카페 C1 (커뮤니티 ARCHITECTURE 25.4 · 25.5 · 25.7) — 표 셋 (마이그레이션 8 절과 같은 줄).
--     표를 만드는 것은 db/community/schema.sql(새 설치) · db/migrations/2026-10-14_community_cafe.sql(기존 DB)이고 이 절은 같은 권한을 다시 건다.
--   anon_aliases    SELECT · INSERT 만(별칭은 바뀌지 않는다 — UPDATE · DELETE 0)
--   reports         SELECT · INSERT · UPDATE(status · resolved_by · resolution_note · resolved_at) — ★ DELETE 0
--   board_requests  SELECT · INSERT · UPDATE(status · decided_by · decision_note · board_id · decided_at) — ★ DELETE 0
--   tbox_backup     SELECT 만 · tbox_perm · tbox_push 0
DO $$
BEGIN
    IF to_regclass('community.anon_aliases') IS NULL OR to_regclass('community.reports') IS NULL
       OR to_regclass('community.board_requests') IS NULL THEN
        RAISE NOTICE '카페 표가 없다 — db/migrations/2026-10-14_community_cafe.sql 을 먼저 돌린다(이 절은 건너뜀)';
        RETURN;
    END IF;
    REVOKE ALL ON community.anon_aliases, community.reports, community.board_requests FROM tbox_app, tbox_backup;
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'tbox_perm') THEN
        EXECUTE 'REVOKE ALL ON community.anon_aliases, community.reports, community.board_requests FROM tbox_perm';
    END IF;
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'tbox_push') THEN
        EXECUTE 'REVOKE ALL ON community.anon_aliases, community.reports, community.board_requests FROM tbox_push';
    END IF;
    GRANT SELECT, INSERT ON community.anon_aliases   TO tbox_app;
    GRANT SELECT, INSERT ON community.reports        TO tbox_app;
    GRANT UPDATE (status, resolved_by, resolution_note, resolved_at) ON community.reports TO tbox_app;
    GRANT SELECT, INSERT ON community.board_requests TO tbox_app;
    GRANT UPDATE (status, decided_by, decision_note, board_id, decided_at) ON community.board_requests TO tbox_app;
    GRANT USAGE, SELECT ON SEQUENCE community.reports_id_seq, community.board_requests_id_seq TO tbox_app;
    GRANT SELECT ON community.anon_aliases, community.reports, community.board_requests TO tbox_backup;
    GRANT SELECT ON SEQUENCE community.reports_id_seq, community.board_requests_id_seq TO tbox_backup;
END
$$;
