-- 규모 D2a 보안 바닥 ① — d1_seed.sql(S) 위에 **일부러 섞는** 합성 자료. tests/scale/d2a_equivalence.py 가 합성 DB(tbox_scale_d_<pid>)에만 실행한다.
-- 공유(사람 · 그룹 · 지운 그룹 · 비활성 회원) · 개인 폴더 사슬(깊이 1~5 · 권한 자리 여럿) · 익명 게시판 글 · 댓글 · 별칭 · 알림 ·
-- 숨김 · 지운 글 · 휴지통 뿌리와 하위 · 게시된 페이지 · 공지 읽음 · 검사 중 파일 · 할 일 블록 · 마감 · 검토 · 검증 만료 ·
-- 상한(1,000 · 100) 경계를 넘는 묶음 · 손님(외부) · 비활성 계정. 값은 모두 결정적이다(난수 0) — 파일 0 · 개인정보 0 · 운영 사본 0.
-- 매개변수(psql -v): users teams boards
\set ON_ERROR_STOP 1
SET client_min_messages = warning;
SET statement_timeout = 0;
DO $$ BEGIN
    IF current_database() NOT LIKE 'tbox\_scale\_d\_%' THEN
        RAISE EXCEPTION 'synthetic DB guard: refusing %', current_database();
    END IF;
END $$;
SELECT :users + :teams AS bb \gset

-- ─── 사람 — 손님(외부) 둘 · 비활성 하나 ───
UPDATE public.users SET role = 'Guest', team_id = NULL WHERE id IN (7, 27);
UPDATE public.users SET is_active = false WHERE id = 57;

-- ─── 자료실: 개인 폴더 사슬(깊이 1~5) · 권한 자리 · 상한 경계 묶음 ───
-- 주인 o 마다 개인 사슬 P1 ⊃ P2 ⊃ … ⊃ P5 (만든 사람: 1·2·4 = 주인 · 3 = o+1 · 5 = o+2)
INSERT INTO public.folders (id, parent_id, name, depth, access_level, team_id, created_by)
SELECT 1000 + o * 10 + d, CASE WHEN d = 1 THEN NULL ELSE 1000 + o * 10 + d - 1 END,
       'd2a 개인 ' || o || '-' || d, d, 'private', NULL,
       CASE d WHEN 3 THEN o + 1 WHEN 5 THEN o + 2 ELSE o END
  FROM unnest(ARRAY[2, 5, 8, 11, 30]) o, generate_series(1, 5) d
 ORDER BY d;
-- 공개 폴더 아래 개인 폴더(개인 사슬이 중간에 끊긴 모양) · 큰 묶음(상한 경계) · 작은 폴더 · 관리자 전용
INSERT INTO public.folders (id, parent_id, name, depth, access_level, team_id, created_by) VALUES
    (1900, NULL, 'd2a 공개 위', 1, 'public', NULL, 9),
    (1901, 1900, 'd2a 공개 아래 개인', 2, 'private', NULL, 9),
    (1902, 1901, 'd2a 공개 아래 개인 둘', 3, 'private', NULL, 14),
    (1910, NULL, 'd2a 많음', 1, 'public', NULL, 1),
    (1911, NULL, 'd2a 작은', 1, 'internal', NULL, 1),
    (1912, NULL, 'd2a 관리자 전용', 1, 'admin-only', NULL, 1);
SELECT setval('public.folders_id_seq', 2000);
-- 권한 자리: 폴더(깊이마다 · 보기 · 편집) · 파일 하나
INSERT INTO public.access_grants (folder_id, user_id, can_write, granted_by) VALUES
    (1023, 4, false, 2), (1051, 6, true, 5), (1085, 12, false, 8), (1112, 13, true, 11), (1302, 2, false, 30),
    (1901, 4, false, 9), (1902, 6, false, 14), (1024, 3, false, 2), (1301, 57, false, 30);
-- 개인 폴더 안 파일(올린 사람: 주인 · 권한 받은 사람 · 남) · 지운 것 섞음
INSERT INTO public.files (public_id, original_name, stored_name, ext, mime, size_bytes, sha256, description, access_level,
                          team_id, uploaded_by, created_at, deleted_at, deleted_by, folder_id, scan_status)
SELECT md5('d2a-pf' || f.id || '-' || k)::uuid, 'd2a 개인 파일 ' || f.id || '-' || k || '.txt', 'd2a-pf-' || f.id || '-' || k || '.txt',
       'txt', 'text/plain', 1000 + k * 7, md5('a' || f.id || k) || md5('b' || f.id || k), '개인 사슬 파일', 'private', NULL,
       CASE k % 4 WHEN 0 THEN f.created_by WHEN 1 THEN 4 WHEN 2 THEN 6 ELSE 10 + (k % 7) END,
       now() - (f.id % 50 + k) * interval '1 hour',
       CASE WHEN k = 7 THEN now() - interval '1 day' END, CASE WHEN k = 7 THEN f.created_by END,
       f.id, CASE WHEN k = 3 THEN 'pending' ELSE 'clean' END
  FROM public.folders f, generate_series(1, 12) k
 WHERE f.access_level = 'private';
-- 파일 하나에 직접 준 권한
INSERT INTO public.access_grants (file_id, user_id, can_write, granted_by)
SELECT id, 13, false, uploaded_by FROM public.files WHERE public_id = md5('d2a-pf1083-2')::uuid;
-- 상한 경계: 「많음」 1,200개(보이는 것 300 + 관리자 전용 900) · 「작은」 50개 · 「관리자 전용」 폴더 1,100개
INSERT INTO public.files (public_id, original_name, stored_name, ext, mime, size_bytes, sha256, description, access_level,
                          team_id, uploaded_by, created_at, folder_id)
SELECT md5('d2a-many' || k)::uuid, 'd2a 많음 ' || k || '.pdf', 'd2a-many-' || k || '.pdf', 'pdf', 'application/pdf',
       5000 + (k % 97) * 13, md5('m' || k) || md5('n' || k), '많음', CASE WHEN k % 4 = 0 THEN 'internal' ELSE 'admin-only' END,
       NULL, 1, now() - k * interval '10 minutes', 1910
  FROM generate_series(1, 1200) k;
INSERT INTO public.files (public_id, original_name, stored_name, ext, mime, size_bytes, sha256, description, access_level,
                          team_id, uploaded_by, created_at, folder_id)
SELECT md5('d2a-small' || k)::uuid, 'd2a 작은 ' || (k % 9) || '.txt', 'd2a-small-' || k || '.txt', 'txt', 'text/plain',
       100 + (k % 5), md5('s' || k) || md5('t' || k), '작은', 'internal', NULL, 2 + (k % 5), now() - k * interval '1 minute', 1911
  FROM generate_series(1, 50) k;
INSERT INTO public.files (public_id, original_name, stored_name, ext, mime, size_bytes, sha256, description, access_level,
                          team_id, uploaded_by, created_at, folder_id)
SELECT md5('d2a-adm' || k)::uuid, 'd2a 관리 ' || k || '.txt', 'd2a-adm-' || k || '.txt', 'txt', 'text/plain',
       100 + k, md5('x' || k) || md5('y' || k), '관리', 'admin-only', NULL, 1, now() - k * interval '1 minute', 1912
  FROM generate_series(1, 1100) k;
-- 검사 중인 내 파일(브리핑 — 20개 상한을 넘는 사람 하나)
UPDATE public.files SET scan_status = 'pending'
 WHERE deleted_at IS NULL AND ((uploaded_by = 2 AND id % 3 = 0) OR (uploaded_by IN (3, 4, 5, 6) AND id % 11 = 0));

-- ─── 커뮤니티: 하위 페이지 사슬(공유 상속 · 휴지통) ───
-- 개인 공간 2 · 10 · 15 의 글 일부를 앞 글의 하위로(깊이 3까지)
WITH c AS (
    SELECT id, space_id, row_number() OVER (PARTITION BY space_id ORDER BY id) AS rn
      FROM community.pages WHERE space_id IN (2, 10, 15) AND deleted_at IS NULL AND database_id IS NULL
)
UPDATE community.pages p SET parent_page_id = (SELECT c2.id FROM c c2 WHERE c2.space_id = c.space_id AND c2.rn = c.rn - 1)
  FROM c WHERE p.id = c.id AND c.rn % 3 <> 1 AND c.rn <= 60;

-- ─── 공유 그룹(관리자 · 팀장 · 지운 그룹) · 회원(비활성 포함) ───
INSERT INTO community.share_groups (id, name, created_by, kind, manager_team_id, deleted_at) VALUES
    (9001, 'd2a 그룹 하나', 1, 'admin', NULL, NULL),
    (9002, 'd2a 지운 그룹', 1, 'admin', NULL, now() - interval '1 day'),
    (9003, 'd2a 팀장 그룹', 3, 'manager', 1, NULL);
INSERT INTO community.share_group_members (group_id, user_id, added_by) VALUES
    (9001, 2, 1), (9001, 4, 1), (9001, 57, 1), (9001, 7, 1), (9002, 2, 1), (9002, 6, 1), (9003, 6, 3), (9003, 8, 3), (9003, 2, 3);
SELECT setval('community.share_groups_id_seq', 9100);
-- 사람 공유: 10 의 글 → 2(보기) · 그중 반 → 4(편집) · 지운 글 · 손님 · 비활성 사람에게도
INSERT INTO community.page_shares (page_id, user_id, level, granted_by)
SELECT id, 2, 'view', 10 FROM community.pages WHERE space_id = 10 AND id % 2 = 0 ORDER BY id LIMIT 40;
INSERT INTO community.page_shares (page_id, user_id, level, granted_by)
SELECT id, 4, CASE WHEN id % 4 = 0 THEN 'edit' ELSE 'view' END, 10 FROM community.pages WHERE space_id = 10 ORDER BY id LIMIT 30;
INSERT INTO community.page_shares (page_id, user_id, level, granted_by)
SELECT id, u, 'view', 15 FROM community.pages, unnest(ARRAY[7, 57, 3]) u WHERE space_id = 15 ORDER BY id LIMIT 45;
-- 그룹 공유(같은 글에 사람 · 그룹 둘 다 — 가장 센 등급) · 지운 그룹 · 팀 공간 글
INSERT INTO community.page_shares (page_id, group_id, level, granted_by)
SELECT id, 9001, 'edit', 15 FROM community.pages WHERE space_id = 15 ORDER BY id LIMIT 30;
INSERT INTO community.page_shares (page_id, group_id, level, granted_by)
SELECT id, 9002, 'edit', 10 FROM community.pages WHERE space_id = 10 ORDER BY id DESC LIMIT 10;
INSERT INTO community.page_shares (page_id, group_id, level, granted_by)
SELECT id, 9003, 'view', author_id FROM community.pages WHERE space_id = :users + 3 ORDER BY id LIMIT 15;
-- 상한 경계: 사람 5 에게 직접 공유 1,050개(여러 사람의 개인 공간 — 볼 수 있는 범위)
INSERT INTO community.page_shares (page_id, user_id, level, granted_by)
SELECT p.id, 5, 'view', p.author_id
  FROM community.pages p JOIN community.spaces s ON s.id = p.space_id AND s.kind = 'personal' AND s.owner_user_id NOT IN (5)
 WHERE p.deleted_at IS NULL AND p.visibility IN ('private', 'team', 'internal')
 ORDER BY p.id LIMIT 1050;

-- ─── 휴지통: 사람 2 · 4 가 최근에 지운 뿌리 + 함께 내려간 하위 · 따로 지운 하위 · 30일 넘은 것 ───
WITH roots AS (
    SELECT p.id, row_number() OVER (ORDER BY p.id) AS rn
      FROM community.pages p
     WHERE p.space_id = 2 AND p.deleted_at IS NULL AND p.parent_page_id IS NULL
       AND EXISTS (SELECT 1 FROM community.pages c WHERE c.parent_page_id = p.id)
     LIMIT 8
), t AS (SELECT id, now() - rn * interval '2 days' - CASE WHEN rn = 8 THEN interval '40 days' ELSE interval '0' END AS at FROM roots)
UPDATE community.pages p SET deleted_at = t.at, deleted_by = 2, purge_after = t.at + interval '30 days'
  FROM t WHERE p.id = t.id OR p.parent_page_id = t.id;
UPDATE community.pages SET deleted_at = now() - interval '1 hour', deleted_by = 4, purge_after = now() + interval '29 days'
 WHERE id IN (SELECT id FROM community.pages WHERE space_id = :users + 1 AND deleted_at IS NULL ORDER BY id LIMIT 6);

-- ─── 카페: 최근 글(24시간 · 30일 창) · 익명 게시판 글 · 댓글 · 별칭 · 조회 · 게시된 페이지 ───
INSERT INTO community.pages (space_id, position, title, visibility, doc, plain_text, author_id, last_edited_by, created_at, updated_at,
                             hidden_at, hidden_by)
SELECT :bb + b, 0, 'd2a 최근 ' || b || '-' || k, 'private', '{"schema":1,"blocks":[]}'::jsonb, '',
       2 + (k * 7 + b) % 40, 2 + (k * 7 + b) % 40,
       now() - (k * 3 + b) * interval '1 hour', now() - (k * 3 + b) * interval '1 hour',
       CASE WHEN k = 5 THEN now() END, CASE WHEN k = 5 THEN 1 END
  FROM unnest(ARRAY[1, 4, 8, 10, 2, 3]) b, generate_series(1, 14) k;
-- 같은 시각 글 둘(정렬 동률 — 번호로 가른다)
INSERT INTO community.pages (space_id, position, title, visibility, doc, plain_text, author_id, last_edited_by, created_at, updated_at)
SELECT :bb + b, 0, 'd2a 동률 ' || b, 'private', '{"schema":1,"blocks":[]}'::jsonb, '', 3, 3, date_trunc('hour', now()) - interval '2 hours',
       date_trunc('hour', now()) - interval '2 hours'
  FROM unnest(ARRAY[1, 1, 4, 8]) b;
-- 개인 페이지를 카페 · 공지 · 익명 게시판에 게시(최근 · 숨김 하나)
UPDATE community.pages p SET visibility = 'published', published_from = 'private', published_board_id = :bb + x.b,
       published_at = now() - x.h * interval '1 hour', published_by = p.author_id,
       hidden_at = CASE WHEN x.h = 3 THEN now() END, hidden_by = CASE WHEN x.h = 3 THEN 1 END
  FROM (SELECT p2.id, (ARRAY[1, 8, 2, 4, 10])[1 + (row_number() OVER (ORDER BY p2.id))::int % 5] AS b,
               row_number() OVER (ORDER BY p2.id) AS h
          FROM community.pages p2 JOIN community.spaces s ON s.id = p2.space_id AND s.kind = 'personal'
         WHERE p2.deleted_at IS NULL AND p2.visibility = 'private' AND p2.space_id BETWEEN 20 AND 60 AND p2.id % 13 = 0
         ORDER BY p2.id LIMIT 25) x
 WHERE p.id = x.id;
-- 조회(7일 안 · 밖)
INSERT INTO community.page_views (page_id, user_id, day)
SELECT p.id, u, current_date - (u % 9)
  FROM community.pages p, generate_series(2, 40) u
 WHERE p.title LIKE 'd2a 최근 %' AND (p.id + u) % 3 = 0
ON CONFLICT DO NOTHING;
UPDATE community.pages p SET view_count = (SELECT count(*) FROM community.page_views v WHERE v.page_id = p.id)
 WHERE p.title LIKE 'd2a 최근 %';
-- 익명 게시판 글의 댓글 · 별칭(글쓴이 0 · 댓글 쓴 사람 1..)
INSERT INTO community.comments (page_id, body, author_id, created_at, updated_at)
SELECT p.id, 'd2a 익명 댓글 ' || k, 2 + (p.id + k) % 30, now() - k * interval '5 minutes', now() - k * interval '5 minutes'
  FROM community.pages p, generate_series(1, 3) k
 WHERE p.space_id = :bb + 4 AND p.title LIKE 'd2a 최근 %';
INSERT INTO community.anon_aliases (page_id, user_id, alias_no)
SELECT p.id, p.author_id, 0 FROM community.pages p WHERE p.space_id = :bb + 4 AND p.title LIKE 'd2a 최근 %'
ON CONFLICT DO NOTHING;
INSERT INTO community.anon_aliases (page_id, user_id, alias_no)
SELECT c.page_id, c.author_id, row_number() OVER (PARTITION BY c.page_id ORDER BY min(c.id))
  FROM community.comments c JOIN community.pages p ON p.id = c.page_id
 WHERE p.space_id = :bb + 4 AND p.title LIKE 'd2a 최근 %' AND c.author_id <> p.author_id
 GROUP BY c.page_id, c.author_id
ON CONFLICT DO NOTHING;

-- ─── 알림: 시험 사람마다 최근 60개(익명 글 · 지운 글 · 남의 비공개 글 · 공유 받은 글 섞음) ───
INSERT INTO community.notifications (user_id, kind, page_id, comment_id, actor_id, created_at, read_at)
SELECT u, (ARRAY['reply', 'comment_on_my_page', 'mention'])[1 + k % 3],
       CASE k % 5 WHEN 0 THEN (SELECT id FROM community.pages WHERE space_id = :bb + 4 AND title LIKE 'd2a 최근 %' ORDER BY id OFFSET (k % 10) LIMIT 1)
                  WHEN 1 THEN (SELECT id FROM community.pages WHERE deleted_at IS NOT NULL ORDER BY id OFFSET k LIMIT 1)
                  WHEN 2 THEN (SELECT id FROM community.pages WHERE space_id = 10 ORDER BY id OFFSET k LIMIT 1)
                  WHEN 3 THEN (SELECT id FROM community.pages WHERE space_id = :bb + 1 ORDER BY id DESC OFFSET k LIMIT 1)
                  ELSE (SELECT id FROM community.pages WHERE space_id = u ORDER BY id OFFSET k LIMIT 1) END,
       NULL, 2 + (u + k) % 30, now() - k * interval '7 minutes', CASE WHEN k % 4 = 0 THEN now() END
  FROM unnest(ARRAY[1, 2, 3, 4, 5, 6, 7, 8, 12, 13, 21, 40, 100]) u, generate_series(1, 60) k;
-- 익명 글 댓글을 가리키는 알림(댓글 번호 있음)
INSERT INTO community.notifications (user_id, kind, page_id, comment_id, actor_id, created_at)
SELECT p.author_id, 'comment_on_my_page', c.page_id, c.id, c.author_id, now() - interval '1 minute'
  FROM community.comments c JOIN community.pages p ON p.id = c.page_id
 WHERE p.space_id = :bb + 4 AND p.title LIKE 'd2a 최근 %';

-- ─── 브리핑: 공지 읽음 · 마감 · 검토 · 검증 만료 · 질문 · 할 일 블록 ───
INSERT INTO community.page_reads (page_id, user_id)
SELECT p.id, u FROM community.pages p, unnest(ARRAY[2, 3]) u
 WHERE p.space_id = :bb + 2 AND p.deleted_at IS NULL AND p.id % 2 = 0
ON CONFLICT DO NOTHING;
UPDATE community.pages SET updated_at = now() - (id % 50) * interval '1 hour'
 WHERE space_id = :bb + 2 AND id IN (SELECT id FROM community.pages WHERE space_id = :bb + 2 ORDER BY id DESC LIMIT 40);
UPDATE community.pages p SET status = x.st, due_date = x.due
  FROM (SELECT id, (ARRAY['todo', 'doing', 'review', 'done', 'hold'])[1 + (row_number() OVER (ORDER BY id))::int % 5] AS st,
               current_date + ((row_number() OVER (ORDER BY id))::int % 4 - 2) AS due
          FROM community.pages WHERE deleted_at IS NULL AND (space_id IN (2, 3, 4, 5, 6) OR (author_id IN (2, 3, 4) AND space_id > :users))
         ORDER BY id DESC LIMIT 400) x
 WHERE p.id = x.id;
UPDATE community.pages SET verified_at = now() - interval '40 days', verified_by = author_id, verify_expires_at = now() - interval '1 day'
 WHERE id IN (SELECT id FROM community.pages WHERE deleted_at IS NULL AND space_id IN (2, 4, :users + 1) ORDER BY id LIMIT 30);
UPDATE community.pages SET resolved = false, updated_at = now() - interval '3 hours'
 WHERE id IN (SELECT id FROM community.pages WHERE space_id = :bb + 3 AND author_id IN (2, 3, 4, 6) ORDER BY id DESC LIMIT 30);
UPDATE community.pages SET doc = jsonb_build_object('schema', 1, 'blocks', jsonb_build_array(
        jsonb_build_object('id', md5('todo-a' || id)::uuid, 'type', 'to_do', 'checked', false,
                           'text', jsonb_build_array(jsonb_build_object('t', '할 일 ' || id, 'm', '{}'::jsonb))),
        jsonb_build_object('id', md5('todo-b' || id)::uuid, 'type', 'to_do', 'checked', id % 2 = 0,
                           'text', jsonb_build_array(jsonb_build_object('t', '두 번째 ' || id, 'm', '{}'::jsonb))))),
       updated_at = now() - (id % 30) * interval '1 hour'
 WHERE id IN (SELECT id FROM community.pages WHERE deleted_at IS NULL AND (space_id IN (2, 4) OR (author_id = 2 AND space_id > :users))
               ORDER BY id DESC LIMIT 25);

ANALYZE public.users, public.folders, public.files, public.access_grants, community.spaces, community.pages,
        community.page_shares, community.share_groups, community.share_group_members, community.comments,
        community.anon_aliases, community.notifications, community.page_reads, community.page_views;
SELECT 'mix ok';
