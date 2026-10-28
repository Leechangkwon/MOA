-- 모아 운영 자료 일부 지우기 (2026-10-26 사용자 요청) — 한 트랜잭션
-- 범위(사용자 결정 2026-10-26):
--   ① T-box 파일 행 전부(휴지통·영구삭제 뒤 남은 행 포함) + 공유 링크·즐겨찾기 + 폴더 18개
--      :keep_programs = 1 이면 지금 판 PC 프로그램(files.program AND program_current) 1개만 남긴다.
--      사용자 결정(2026-10-26 「전부 초기화해 더 최신본이 있어」) = keep_programs=0 — 프로그램 배포본 7개도 모두 지운다
--   ② 이야기(스레드): 글·사진·댓글·좋아요·공유 링크·여행 연결 + AI 사진 글쓰기 기록
--   ③ 게시판 글 · 업무 프로젝트 밖 개인·팀 문서(지운 상태 포함) — 업무 프로젝트(MCP) 나무는 남긴다
--   옛 게시판(legacy_community) 글·댓글
-- 남기는 것: 계정·설정·팀·공간(게시판 틀)·업무 프로젝트 나무·감사 기록·MCP 연결·메신저·제품 공간·여행 계획
-- 실행: sudo -u postgres psql -X -v ON_ERROR_STOP=1 -v keep_programs=1 -d <DB> -f delete-plan.sql
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

-- 4. 확인 ---------------------------------------------------------------------
SELECT '남은 문서(=업무 프로젝트)' AS 항목, count(*) FROM community.pages
UNION ALL SELECT '남은 문서 중 업무 프로젝트 밖', count(*) FROM community.pages WHERE id NOT IN (SELECT id FROM keep_pages)
UNION ALL SELECT 'files 남음', count(*) FROM public.files
UNION ALL SELECT 'files 남음(지금 판 프로그램 아님)', count(*) FROM public.files WHERE NOT (program AND program_current)
UNION ALL SELECT 'folders', count(*) FROM public.folders
UNION ALL SELECT 'share_links', count(*) FROM public.share_links
UNION ALL SELECT 'memory_posts', count(*) FROM community.memory_posts
UNION ALL SELECT 'memory_media', count(*) FROM community.memory_media
UNION ALL SELECT 'comments', count(*) FROM community.comments
UNION ALL SELECT 'legacy posts', count(*) FROM legacy_community.posts
UNION ALL SELECT '게시판 post_count 합', coalesce(sum(post_count), 0) FROM community.spaces;

COMMIT;
