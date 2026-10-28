-- 모아 운영 자료 지우기 v2 (2026-10-28) — 한 트랜잭션. v1(delete-plan.sql, 10-26 범위)을 그대로 품고 범위를 넓힌다.
-- 사용자 결정 2026-10-28:
--   「업무 프로젝트 2개 및 하위 제외한 모든걸 지워」 — 남는 문서는 Rea_ERP·모아 나무뿐
--   계정: 켜진 계정 11개 남김, 꺼진 계정 24개 지움
--   감사 기록: 행은 남기고 지운 자료의 이름·요약만 비움(디스크 audit.log 는 봉인돼 그대로)
-- 남기는 틀(내용 아님): 계정 11·팀·공간(업무 프로젝트·남는 계정 개인·팀·게시판 틀)·권한표·설정(AI·진단 정책·팔레트·
--   임베드·가져오기 허용)·사이트 기본 템플릿 3·pkdkak1 의 MCP 연결·살아 있는 API 토큰·패스키·관리자 휴대폰 경보 구독
-- 1~3 장은 v1 과 같다(keep_programs=0 = 프로그램 배포본까지 전부). 4~6 장이 넓힌 부분이다.
-- 실행: sudo -u postgres psql -X -v ON_ERROR_STOP=1 -v keep_programs=0 -d <DB> -f delete-plan-v2.sql
\set ON_ERROR_STOP on
\if :{?keep_programs}
\else
  \echo 'keep_programs 를 0 또는 1 로 넘겨야 한다'
  \quit
\endif

BEGIN;

-- 0. 대상 집합 ----------------------------------------------------------------
-- 남길 문서 = 살아 있는 업무 프로젝트(databases.purpose='project') 문서와 그 아래 전부
CREATE TEMP TABLE keep_pages ON COMMIT DROP AS
WITH RECURSIVE proj AS (
  SELECT d.page_id AS root FROM community.databases d JOIN community.pages p ON p.id = d.page_id
   WHERE d.purpose = 'project' AND p.deleted_at IS NULL),
tree AS (
  SELECT p.id FROM community.pages p JOIN proj r ON p.id = r.root
  UNION
  SELECT c.id FROM community.pages c JOIN tree t
    ON c.parent_page_id = t.id OR c.database_id IN (SELECT id FROM community.databases WHERE page_id = t.id))
SELECT id FROM tree;

CREATE TEMP TABLE del_pages    ON COMMIT DROP AS SELECT id, public_id FROM community.pages WHERE id NOT IN (SELECT id FROM keep_pages);
CREATE TEMP TABLE del_comments ON COMMIT DROP AS SELECT id FROM community.comments WHERE page_id IN (SELECT id FROM del_pages);
CREATE TEMP TABLE del_dbs      ON COMMIT DROP AS SELECT id FROM community.databases WHERE page_id IN (SELECT id FROM del_pages);
CREATE TEMP TABLE del_autos    ON COMMIT DROP AS SELECT id FROM community.automations WHERE scope_page_id IN (SELECT id FROM del_pages);
CREATE TEMP TABLE del_files    ON COMMIT DROP AS SELECT id, public_id FROM public.files WHERE NOT (program AND program_current AND :keep_programs = 1);
CREATE TEMP TABLE del_ai_turns ON COMMIT DROP AS SELECT id, thread_id FROM public.member_ai_turns WHERE prompt ~ '^스레드 글쓰기';

-- 남길 문서가 지울 쪽에 매달려 있으면 멈춘다(부모·DB)
DO $$ BEGIN
  IF EXISTS (SELECT 1 FROM community.pages p WHERE p.id IN (SELECT id FROM keep_pages)
              AND (p.parent_page_id IN (SELECT id FROM del_pages) OR p.database_id IN (SELECT id FROM del_dbs))) THEN
    RAISE EXCEPTION '남길 업무 문서가 지울 문서에 매달려 있다 — 중단';
  END IF;
END $$;

SELECT 'keep_pages' AS 집합, count(*) FROM keep_pages
UNION ALL SELECT 'del_pages', count(*) FROM del_pages
UNION ALL SELECT 'del_comments', count(*) FROM del_comments
UNION ALL SELECT 'del_dbs', count(*) FROM del_dbs
UNION ALL SELECT 'del_autos', count(*) FROM del_autos
UNION ALL SELECT 'del_files', count(*) FROM del_files
UNION ALL SELECT 'del_ai_turns', count(*) FROM del_ai_turns;

-- 1. 커뮤니티 문서·게시판 ------------------------------------------------------
DELETE FROM community.comment_reactions WHERE comment_id IN (SELECT id FROM del_comments);
DELETE FROM community.notifications     WHERE comment_id IN (SELECT id FROM del_comments) OR page_id IN (SELECT id FROM del_pages);
DELETE FROM community.reports           WHERE comment_id IN (SELECT id FROM del_comments) OR page_id IN (SELECT id FROM del_pages);
DELETE FROM community.comments          WHERE id IN (SELECT id FROM del_comments);

DELETE FROM community.automation_queue  WHERE automation_id IN (SELECT id FROM del_autos) OR page_id IN (SELECT id FROM del_pages);
DELETE FROM community.automation_runs   WHERE automation_id IN (SELECT id FROM del_autos);
DELETE FROM community.automations       WHERE id IN (SELECT id FROM del_autos);

DELETE FROM community.public_form_uses  WHERE publication_id IN (SELECT id FROM community.web_publications WHERE page_id IN (SELECT id FROM del_pages));
DELETE FROM community.web_publication_pages WHERE page_id IN (SELECT id FROM del_pages)
   OR publication_id IN (SELECT id FROM community.web_publications WHERE page_id IN (SELECT id FROM del_pages));
DELETE FROM community.web_publications  WHERE page_id IN (SELECT id FROM del_pages);

DELETE FROM community.portfolio_events  WHERE page_id IN (SELECT id FROM del_pages);
DELETE FROM community.portfolio_items   WHERE page_id IN (SELECT id FROM del_pages);

DELETE FROM community.page_versions        WHERE page_id IN (SELECT id FROM del_pages);
DELETE FROM community.page_blocks          WHERE page_id IN (SELECT id FROM del_pages);
DELETE FROM community.page_links           WHERE from_page_id IN (SELECT id FROM del_pages) OR to_page_id IN (SELECT public_id FROM del_pages);  -- to_page_id 는 public_id(uuid)
DELETE FROM community.checklist_events     WHERE page_id IN (SELECT id FROM del_pages);
DELETE FROM community.page_edit_sessions   WHERE page_id IN (SELECT id FROM del_pages);
DELETE FROM community.page_property_events WHERE page_id IN (SELECT id FROM del_pages);
DELETE FROM community.page_shares          WHERE page_id IN (SELECT id FROM del_pages);
DELETE FROM community.handovers            WHERE root_page_id IN (SELECT id FROM del_pages);
DELETE FROM community.page_tags            WHERE page_id IN (SELECT id FROM del_pages);
DELETE FROM community.page_views           WHERE page_id IN (SELECT id FROM del_pages);
DELETE FROM community.page_reads           WHERE page_id IN (SELECT id FROM del_pages);
DELETE FROM community.page_suggestions     WHERE page_id IN (SELECT id FROM del_pages);
DELETE FROM community.synced_references    WHERE page_id IN (SELECT id FROM del_pages);
DELETE FROM community.synced_sources       WHERE page_id IN (SELECT id FROM del_pages);
DELETE FROM community.ai_page_permissions  WHERE page_id IN (SELECT id FROM del_pages);
DELETE FROM community.page_embeddings      WHERE page_id IN (SELECT id FROM del_pages);
DELETE FROM community.embedding_queue      WHERE page_id IN (SELECT id FROM del_pages);
DELETE FROM community.my_tasks_projects    WHERE page_id IN (SELECT id FROM del_pages);
DELETE FROM community.project_status_updates WHERE page_id IN (SELECT id FROM del_pages);
DELETE FROM community.anon_aliases         WHERE page_id IN (SELECT id FROM del_pages);
DELETE FROM community.page_attachment_purge_queue
 WHERE stored_name IN (SELECT stored_name FROM community.page_attachments WHERE page_id IN (SELECT id FROM del_pages));
DELETE FROM community.page_attachments     WHERE page_id IN (SELECT id FROM del_pages);
-- ai_log 은 지운 문서를 가리키는 기록의 본문만 비운다(트리거 ai_scrub_purged 와 같은 방식, 오늘 0행)
UPDATE community.ai_log SET input_text = '', output_text = '', scrubbed_at = now()
 WHERE EXISTS (SELECT 1 FROM del_pages d WHERE community.ai_log.page_refs @> jsonb_build_array(jsonb_build_object('page_id', d.public_id::text)));

-- 문서 ↔ DB 는 서로 가리킨다(databases.page_id · pages.database_id, 둘 다 NO ACTION).
-- pages_properties_shape 검사 때문에 database_id 만 비울 수 없으니 한 문장(쓰기 CTE)으로 함께 지운다 — 외래 키는 문장 끝에 검사된다.
DELETE FROM community.templates       WHERE database_id IN (SELECT id FROM del_dbs);
DELETE FROM community.data_connectors WHERE database_id IN (SELECT id FROM del_dbs);
WITH gone_dbs AS (DELETE FROM community.databases WHERE id IN (SELECT id FROM del_dbs) RETURNING id)
DELETE FROM community.pages WHERE id IN (SELECT id FROM del_pages);   -- page_favorites·page_recent·collection_items 는 CASCADE

-- 옛 게시판(1·2단계 legacy_community) 글·댓글 — 게시판 틀(boards)은 남긴다
DELETE FROM legacy_community.comments;
DELETE FROM legacy_community.posts;

-- 2. 이야기(스레드) -----------------------------------------------------------
DELETE FROM community.travel_plan_records;   -- 여행 계획 ↔ 글 연결만(계획 자체는 남김)
DELETE FROM community.memory_links;
DELETE FROM community.memory_likes;
DELETE FROM community.memory_comments;
DELETE FROM community.memory_media;
DELETE FROM community.memory_posts;
DELETE FROM public.member_ai_turns   WHERE id IN (SELECT id FROM del_ai_turns);
DELETE FROM public.member_ai_threads WHERE id IN (SELECT thread_id FROM del_ai_turns)
   AND NOT EXISTS (SELECT 1 FROM public.member_ai_turns t WHERE t.thread_id = member_ai_threads.id);

-- 3. T-box 파일·폴더 ----------------------------------------------------------
DELETE FROM public.share_links WHERE file_id IN (SELECT id FROM del_files);
UPDATE public.product_space_items SET file_id = NULL WHERE file_id IN (SELECT public_id FROM del_files);  -- file_id 는 public_id(uuid)
DELETE FROM public.access_grants   WHERE file_id IN (SELECT id FROM del_files);
DELETE FROM public.access_requests WHERE file_id IN (SELECT id FROM del_files);
UPDATE community.ai_log SET input_text = '', output_text = '', scrubbed_at = now()
 WHERE EXISTS (SELECT 1 FROM public.files f JOIN del_files d ON d.id = f.id
                WHERE community.ai_log.file_refs @> jsonb_build_array(jsonb_build_object('file_id', f.public_id::text)));
DELETE FROM public.files WHERE id IN (SELECT id FROM del_files);   -- file_favorites 는 CASCADE

-- 폴더: 남은 파일이 폴더를 쓰지 않을 때만, 깊은 것부터(folders.parent_id 는 RESTRICT)
DO $$
DECLARE d int;
BEGIN
  IF EXISTS (SELECT 1 FROM public.files WHERE folder_id IS NOT NULL) THEN
    RAISE EXCEPTION '남는 파일이 폴더 안에 있다 — 폴더를 지우지 않고 중단';
  END IF;
  FOR d IN SELECT DISTINCT depth FROM public.folders ORDER BY depth DESC LOOP
    DELETE FROM public.folders WHERE depth = d;   -- folder_favorites·access_grants·access_requests(folder) 는 CASCADE
  END LOOP;
END $$;

-- 4. 넓힌 범위 — 남은 내용 표 전부 ---------------------------------------------
-- 커뮤니티: 알림·신고·신청·인계·여행·이야기 팔로우·설정·길잡이·사용 안내 진행·개인 템플릿
DELETE FROM community.notifications;
DELETE FROM community.reports;
DELETE FROM community.board_requests;
DELETE FROM community.handovers;
DELETE FROM community.travel_plans;
DELETE FROM community.memory_follows;
DELETE FROM community.memory_preferences;
DELETE FROM community.guide_journeys;
DELETE FROM community.usage_guides;
DELETE FROM community.templates WHERE scope <> 'site' OR deleted_at IS NOT NULL;   -- 사이트 기본 틀 3개는 남김
DELETE FROM community.checklist_events WHERE page_id NOT IN (SELECT id FROM keep_pages);

-- 메신저·AI 대화(AI 연결 설정 member_ai_settings 는 남김)
DELETE FROM public.member_messages;
DELETE FROM public.member_ai_assists;
DELETE FROM public.member_ai_turns;
DELETE FROM public.member_ai_threads;

-- 제품 공간
DELETE FROM public.product_personal_dashboards;
DELETE FROM public.product_space_dashboards;
DELETE FROM public.product_space_invites;
DELETE FROM public.product_space_items;
DELETE FROM public.product_space_members;
DELETE FROM public.product_spaces;

-- 가입 신청·초대·화면 진단·요청 제한·옛 게시판 세션
DELETE FROM public.invite_requests;
DELETE FROM public.member_invites;
DELETE FROM public.client_diagnostic_events;
DELETE FROM public.client_diagnostic_rollups;
DELETE FROM public.member_rate_limits;
DELETE FROM public.passkey_challenges;
DELETE FROM legacy_community.sessions;

-- 끝난 토큰 — 살아 있는 API 토큰과 pkdkak1 의 MCP 연결(회수 안 된 grant)·만료 전 토큰은 남김
DELETE FROM public.api_tokens WHERE NOT (revoked_at IS NULL AND (expires_at IS NULL OR expires_at > now()));
DELETE FROM public.mcp_grants WHERE revoked_at IS NOT NULL;   -- mcp_codes·mcp_tokens 는 CASCADE
DELETE FROM public.mcp_tokens WHERE expires_at <= now();
DELETE FROM public.mcp_codes  WHERE expires_at <= now();

-- 5. 꺼진 계정 지우기 ------------------------------------------------------------
CREATE TEMP TABLE del_users ON COMMIT DROP AS SELECT id, username FROM public.users WHERE NOT is_active;
DO $$ BEGIN
  IF EXISTS (SELECT 1 FROM del_users WHERE username = 'pkdkak1') THEN
    RAISE EXCEPTION 'pkdkak1 이 지울 계정에 들어 있다 — 중단';
  END IF;
  IF EXISTS (SELECT 1 FROM public.mcp_grants WHERE user_id IN (SELECT id FROM del_users)) THEN
    RAISE EXCEPTION '살아 있는 MCP 연결이 꺼진 계정에 있다 — 중단';
  END IF;
END $$;
DELETE FROM community.spaces WHERE kind = 'personal' AND owner_user_id IN (SELECT id FROM del_users);
UPDATE community.spaces          SET created_by = NULL WHERE created_by IN (SELECT id FROM del_users);   -- 팀 공간 틀
UPDATE legacy_community.boards   SET created_by = NULL WHERE created_by IN (SELECT id FROM del_users);
UPDATE public.passkeys           SET revoked_by = NULL WHERE revoked_by IN (SELECT id FROM del_users);
UPDATE public.api_tokens         SET revoked_by = NULL WHERE revoked_by IN (SELECT id FROM del_users);
DELETE FROM public.passkeys      WHERE user_id IN (SELECT id FROM del_users);
DELETE FROM public.user_consents WHERE user_id IN (SELECT id FROM del_users);
DELETE FROM public.users         WHERE id IN (SELECT id FROM del_users);   -- sessions·session_links·guide_journeys·usage_guides 등은 CASCADE

-- 6. 감사 기록 — 행은 남기고 지운 자료의 이름만 비운다 ---------------------------
-- 남는 것: 누가·언제·IP·브라우저·동작·결과와 보안 판단 키. 업무 프로젝트 문서를 가리키는 행은 그대로 둔다.
CREATE TEMP TABLE scrub_keep_keys (k text) ON COMMIT DROP;
INSERT INTO scrub_keep_keys VALUES ('route'),('login_ref'),('actor_team'),('granted_by'),('auth'),('real_role'),('acting_as'),
  ('token_id'),('token_kind'),('token_scope'),('issued_by'),('source_ip'),('otp_verified'),('mode'),
  ('scan_status'),('signature'),('signatures'),('reason'),('cause'),('http_status');

UPDATE public.audit_logs a SET
  target_name = CASE WHEN a.target_name IS NULL THEN NULL ELSE '(지움)' END,
  summary     = regexp_replace(a.summary, '「[^」]*」', '「(지움)」', 'g'),
  detail      = coalesce((SELECT jsonb_object_agg(e.key, e.value)
                            FROM jsonb_each(CASE WHEN jsonb_typeof(a.detail) = 'object' THEN a.detail ELSE '{}'::jsonb END) e
                           WHERE e.key IN (SELECT k FROM scrub_keep_keys)), '{}'::jsonb)
                || jsonb_build_object('scrubbed', '2026-10-28 자료 초기화')
 WHERE a.target_type IN ('file','page','page_attachment','folder','purchase_doc','purchase_master','purchase_item','purchase_list',
                         'purchase_recon','purchase_close','product_space','automation','automation_run','template')
   AND NOT (a.target_type = 'page' AND a.target_id IN (SELECT id FROM keep_pages));

-- 다른 종류 행에 섞인 문서 이름 키
UPDATE public.audit_logs a SET detail = a.detail - '{page_title,parent_title,template_name,prop_name,view_name}'::text[]
 WHERE jsonb_typeof(a.detail) = 'object' AND a.detail ?| '{page_title,parent_title,template_name,prop_name,view_name}'::text[]
   AND NOT (a.target_type = 'page' AND a.target_id IN (SELECT id FROM keep_pages));

-- 옛 게시판 감사 기록: 글·댓글 행
UPDATE legacy_community.audit_logs a SET
  target_name = CASE WHEN a.target_name IS NULL THEN NULL ELSE '(지움)' END,
  summary     = regexp_replace(a.summary, '「[^」]*」', '「(지움)」', 'g'),
  detail      = coalesce((SELECT jsonb_object_agg(e.key, e.value)
                            FROM jsonb_each(CASE WHEN jsonb_typeof(a.detail) = 'object' THEN a.detail ELSE '{}'::jsonb END) e
                           WHERE e.key IN (SELECT k FROM scrub_keep_keys)), '{}'::jsonb)
                || jsonb_build_object('scrubbed', '2026-10-28 자료 초기화')
 WHERE a.target_type IN ('post','comment');

-- 7. 확인 ---------------------------------------------------------------------
SELECT '남은 문서(=업무 프로젝트)' AS 항목, count(*) FROM community.pages
UNION ALL SELECT '남은 문서 중 업무 프로젝트 밖', count(*) FROM community.pages WHERE id NOT IN (SELECT id FROM keep_pages)
UNION ALL SELECT 'files', count(*) FROM public.files
UNION ALL SELECT 'folders', count(*) FROM public.folders
UNION ALL SELECT 'memory_posts', count(*) FROM community.memory_posts
UNION ALL SELECT 'comments', count(*) FROM community.comments
UNION ALL SELECT 'member_messages', count(*) FROM public.member_messages
UNION ALL SELECT 'product_spaces', count(*) FROM public.product_spaces
UNION ALL SELECT 'legacy posts', count(*) FROM legacy_community.posts
UNION ALL SELECT '게시판 post_count 합', coalesce(sum(post_count), 0) FROM community.spaces
UNION ALL SELECT '계정(모두 켜짐이어야)', count(*) FROM public.users
UNION ALL SELECT '꺼진 계정', count(*) FROM public.users WHERE NOT is_active
UNION ALL SELECT 'pkdkak1 MCP 연결', count(*) FROM public.mcp_grants g JOIN public.users u ON u.id = g.user_id WHERE u.username = 'pkdkak1' AND g.revoked_at IS NULL
UNION ALL SELECT '살아 있는 API 토큰', count(*) FROM public.api_tokens
UNION ALL SELECT '감사 기록 행', count(*) FROM public.audit_logs
UNION ALL SELECT '감사: 내용 행 중 이름 남은 것(업무 프로젝트 제외)', count(*) FROM public.audit_logs a
   WHERE a.target_type IN ('file','page','page_attachment','folder','purchase_doc','purchase_master','purchase_item','purchase_list',
                           'purchase_recon','purchase_close','product_space','automation','automation_run','template')
     AND NOT (a.target_type = 'page' AND a.target_id IN (SELECT id FROM keep_pages))
     AND (coalesce(a.target_name, '(지움)') <> '(지움)' OR a.summary ~ '「(?!\(지움\)」)' OR NOT a.detail ? 'scrubbed');

COMMIT;
