-- 규모 D1 — 단계별 합성 데이터(S · M · L). tests/scale/d1_measure.py 가 합성 DB(tbox_scale_d_<pid>)에만 실행한다.
-- 파일 0 · 개인정보 0 · 운영 사본 0 — 모두 generate_series 로 만든 값이다. 미디어 · 자료실 행은 디스크 파일이 없다.
-- 매개변수(psql -v): users teams boards pages comments prop_events notifications audit stories media story_comments
--                   files reports invites consent_terms consent_privacy consent_age14 consent_ai
-- 단계마다 처음부터 다시 만든다(TRUNCATE) — id 와 시각이 같이 늘어나는 운영과 같은 상관(correlation)을 지키려고.
\set ON_ERROR_STOP 1
SET client_min_messages = warning;
SET statement_timeout = 0;
DO $$ BEGIN
    IF current_database() NOT LIKE 'tbox\_scale\_d\_%' THEN
        RAISE EXCEPTION 'synthetic DB guard: refusing %', current_database();
    END IF;
END $$;

-- 사람 · 팀을 참조하는 표는 CASCADE 로 함께 비고, 참조가 없는 표(감사 로그 · 초대 요청)는 따로 비운다
-- (D1 첫 실행에서 S 의 감사 10만 행이 M 에 남아 M 감사가 110만이 된 것을 고침).
TRUNCATE public.users, public.teams, public.audit_logs, public.invite_requests RESTART IDENTITY CASCADE;
SELECT setseed(0.4242);

-- 결정적 의사난수(행 번호 → 0..m-1) — 표끼리 같은 값을 다시 계산하려고(예: 미디어 주인 = 글 주인)
CREATE FUNCTION pg_temp.h(k bigint, salt bigint, m bigint) RETURNS bigint LANGUAGE sql IMMUTABLE AS
$$ SELECT ((k * 2654435761 + salt * 40503) % 4294967291) % m $$;
-- 사람의 팀(측정 계정 1~3 은 팀 1)
CREATE FUNCTION pg_temp.team_of(u bigint, teams bigint) RETURNS bigint LANGUAGE sql IMMUTABLE AS
$$ SELECT CASE WHEN u <= 3 THEN 1 ELSE 1 + (u % teams) END $$;
-- 행 번호 → 시각(3년에 걸쳐 고르게, id 와 같이 늘어난다)
CREATE FUNCTION pg_temp.ts(k bigint, n bigint) RETURNS timestamptz LANGUAGE sql IMMUTABLE AS
$$ SELECT timestamptz '2023-10-14 09:00+09' + (k::float8 / greatest(n, 1)) * interval '1095 days' $$;

-- 낱말 표 — 앞쪽 낱말이 자주 나온다(power(random,2)). toks = SearchText::indexTokens 와 같은 1·2글자 토큰
CREATE TEMP TABLE d1_vocab (n int PRIMARY KEY, w text NOT NULL, toks text NOT NULL);
INSERT INTO d1_vocab (n, w, toks)
SELECT o, w, (SELECT string_agg(DISTINCT t, ' ') FROM (
          SELECT substr(w, g, 1) AS t FROM generate_series(1, length(w)) g
          UNION ALL SELECT substr(w, g, 2) FROM generate_series(1, length(w) - 1) g) x)
  FROM unnest(ARRAY[
    '회의','보고서','일정','프로젝트','계약','고객','예산','검토','승인','출장','교육','안전','점검','품질','개발',
    '디자인','마케팅','영업','인사','회계','구매','납품','견적','설계','시험','배포','장애','복구','백업','보안',
    '서버','네트워크','데이터','분석','기획','전략','목표','성과','평가','면접','채용','휴가','근무','주간',
    '월간','분기','연간','결산','세금','청구','지급','입금','출금','재고','물류','배송','반품','교환','문의',
    '답변','공지','안내','변경','취소','요청','완료','진행','대기','보류','긴급','중요','참고','첨부','자료',
    '문서','양식','서식','절차','규정','지침','매뉴얼','사례','후기','제안','개선','아이디어','토론','질문',
    '해결','방법','이유','결과','원인','대책','계획','실행','확인','정리','공유','협업','팀장','부장','이사',
    '대표','담당자','고객사','협력사','본사','지사','현장','사무실','회의실','점심','저녁','행사','워크숍',
    '세미나','발표','자료실','게시판','카페','이야기','사진','여행','맛집','운동','건강','취미'
  ]) WITH ORDINALITY AS v(w, o);
SELECT count(*) AS vocab_size FROM d1_vocab \gset
-- 배열 한 줄 — 행마다 낱말을 배열 첨자로 고른다(조인 조건의 random() 은 계획에 따라 여러 번 불려 쓰지 않는다)
CREATE TEMP TABLE d1_arr AS SELECT array_agg(w ORDER BY n) AS ws, array_agg(toks ORDER BY n) AS tk FROM d1_vocab;

-- ─── 사람 · 팀 ───
INSERT INTO public.teams (id, name)
SELECT t, 'D1 합성팀 ' || t FROM generate_series(1, :teams) t;

INSERT INTO public.users (id, username, display_name, password_hash, role, team_id, mfa_secret, mfa_enabled,
                          expires_at, created_at, updated_at, password_changed_at)
SELECT u,
       CASE u WHEN 1 THEN 'd1_admin' WHEN 2 THEN 'd1_emp' WHEN 3 THEN 'd1_mgr' ELSE 'd1_u' || lpad(u::text, 5, '0') END,
       '합성 ' || u,
       'synthetic-no-login',
       CASE WHEN u = 1 THEN 'Admin' WHEN u = 3 THEN 'Manager' WHEN u % 100 = 0 THEN 'Admin'
            WHEN u % 20 = 0 THEN 'Manager' ELSE 'Employee' END,
       pg_temp.team_of(u, :teams),
       'JBSWY3DPEHPK3PXP', true,
       now() + interval '30 days', now() - interval '400 days', now() - interval '400 days', now() - interval '30 days'
  FROM generate_series(1, :users) u;

INSERT INTO public.user_consents (user_id, doc, version, action, ip)
SELECT u, d.doc, d.v, 'agree', '127.0.0.1'
  FROM generate_series(1, :users) u
 CROSS JOIN (VALUES ('terms', :'consent_terms'), ('privacy', :'consent_privacy'),
                    ('age14', :'consent_age14'), ('ai_overseas', :'consent_ai')) d(doc, v);

INSERT INTO public.permissions (role, access_level, can_read, can_upload)
SELECT r, l, (r = 'Admin') OR (r IN ('Manager','Employee') AND l <> 'admin-only') OR (r = 'Guest' AND l = 'public'),
             (r = 'Admin') OR (r IN ('Manager','Employee') AND l <> 'admin-only')
  FROM unnest(ARRAY['Admin','Manager','Employee','Guest']) r, unnest(ARRAY['public','internal','team','admin-only']) l
ON CONFLICT DO NOTHING;

-- ─── 공간: 개인(id = 사람 id) · 팀 · 게시판 ───
INSERT INTO community.spaces (id, kind, owner_user_id, name, description, access_level, created_by, created_at)
SELECT u, 'personal', u, '합성 ' || u || ' 의 공간', '나만 보는 개인 업무 공간입니다.', 'admin-only', u, now() - interval '400 days'
  FROM generate_series(1, :users) u;
INSERT INTO community.spaces (id, kind, team_id, name, description, access_level, created_by, created_at)
SELECT :users + t, 'team', t, 'D1 합성팀 ' || t, '팀이 함께 쓰는 공간입니다.', 'team', 1, now() - interval '400 days'
  FROM generate_series(1, :teams) t;
-- 게시판 b=1 은 큰 게시판(게시판 글의 40%), 2 공지, 3 질문, 4 익명(카페), 5·6 팀 게시판, 나머지 토론(반은 카페)
INSERT INTO community.spaces (id, kind, name, description, access_level, team_id, notice, board_kind, area, created_by, created_at)
SELECT :users + :teams + b, 'board',
       CASE b WHEN 1 THEN '자유게시판 D1' WHEN 2 THEN '공지 D1' WHEN 3 THEN '질문 D1' WHEN 4 THEN '익명 D1' ELSE '게시판 D1-' || b END,
       '합성 게시판', CASE WHEN b IN (5, 6) THEN 'team' ELSE 'internal' END,
       CASE WHEN b IN (5, 6) THEN b - 4 END,
       b = 2,
       CASE b WHEN 2 THEN 'notice' WHEN 3 THEN 'qa' WHEN 4 THEN 'anonymous' ELSE 'discussion' END,
       CASE WHEN b IN (1, 4) OR (b > 6 AND b % 2 = 0) THEN 'cafe' ELSE 'work' END,
       1, now() - interval '400 days'
  FROM generate_series(1, :boards) b;
SELECT :users + :teams AS board_base \gset

-- 규모 D2a — 미리 센 게시판 글 수 트리거(있으면)는 대량 넣기 동안 끄고, 넣은 뒤 이주와 같은 식으로 한 번 채운다
-- (행마다 게시판 행을 두 번 고치면 넣기가 느려지고 spaces 가 부푼다 — 측정 데이터 · 결과는 같다).
DO $$ BEGIN
    IF EXISTS (SELECT 1 FROM pg_trigger WHERE tgrelid = 'community.pages'::regclass AND tgname = 'scale_board_post_count_insert') THEN
        ALTER TABLE community.pages DISABLE TRIGGER scale_board_post_count_insert;
    END IF;
END $$;

-- ─── 페이지(게시판 글 60% · 문서 30% · 작업 10%) ───
-- i % 10: 0..5 게시판 글 · 6..8 문서 · 9 작업.  i % 50 = 7 지움(휴지통) · 게시판 글 i % 100 = 13 숨김.
INSERT INTO community.pages (id, space_id, position, title, visibility, doc, doc_version, plain_text, search_tsv, block_count,
                             author_id, last_edited_by, created_at, updated_at, deleted_at, deleted_by, purge_after, view_count,
                             status, assignee_id, due_date, start_date, published_board_id, published_from, published_at, published_by,
                             hidden_at, hidden_by)
SELECT i,
       CASE WHEN k < 6 THEN :board_base + CASE WHEN random() < 0.4 THEN 1 ELSE 1 + floor(random() * :boards)::int END
            WHEN pers THEN author ELSE :users + pg_temp.team_of(author, :teams) END,
       (i % 1000)::int,
       left(tw.words || ' ' || i, 200),
       CASE WHEN k < 6 THEN 'private' WHEN pub THEN 'published' WHEN NOT pers THEN 'team'
            WHEN random() < 0.6 THEN 'private' WHEN random() < 0.5 THEN 'team' ELSE 'internal' END,
       jsonb_build_object('schema', 1, 'blocks', jsonb_build_array(jsonb_build_object(
           'id', gen_random_uuid(), 'type', 'paragraph', 'text', jsonb_build_array(jsonb_build_object('t', bw.words, 'm', '{}'::jsonb))))),
       1 + (i % 5)::int,
       bw.words,
       strip(to_tsvector('simple', tw.toks || ' ' || bw.toks)),
       1,
       author, author,
       pg_temp.ts(i, :pages), least(pg_temp.ts(i, :pages) + (random() * 20) * interval '1 day', now()),
       CASE WHEN i % 50 = 7 THEN pg_temp.ts(i, :pages) + interval '2 days' END,
       CASE WHEN i % 50 = 7 THEN author END,
       CASE WHEN i % 50 = 7 THEN pg_temp.ts(i, :pages) + interval '32 days' END,
       floor(power(random(), 3) * 500)::int,
       CASE WHEN k = 9 THEN (ARRAY['todo','doing','review','done','hold'])[1 + (i % 5)] END,
       CASE WHEN k = 9 THEN 1 + pg_temp.h(i, 3, :users) END,
       CASE WHEN k = 9 THEN (pg_temp.ts(i, :pages) + interval '14 days')::date END,
       CASE WHEN k = 9 THEN pg_temp.ts(i, :pages)::date END,
       CASE WHEN pub THEN :board_base + 1 + (i % :boards) END,
       CASE WHEN pub THEN 'private' END,
       CASE WHEN pub THEN pg_temp.ts(i, :pages) + interval '1 hour' END,
       CASE WHEN pub THEN author END,
       CASE WHEN k < 6 AND i % 100 = 13 THEN pg_temp.ts(i, :pages) + interval '1 day' END,
       CASE WHEN k < 6 AND i % 100 = 13 THEN 1 END
  FROM (SELECT i, (i % 10)::int AS k,
               CASE WHEN i % 97 = 0 THEN 2 ELSE 1 + pg_temp.h(i, 1, :users) END AS author,  -- 직원(2)은 글을 많이 쓰는 사람(개인 공간 깊은 쪽)
               (i % 10) BETWEEN 6 AND 9 AND (i % 7) <> 0 AS pers,
               (i % 10) BETWEEN 6 AND 8 AND (i % 7) <> 0 AND (i % 53) = 0 AS pub
          FROM generate_series(1, :pages) i) s
 CROSS JOIN LATERAL (SELECT string_agg(a.ws[x.n], ' ') AS words, string_agg(a.tk[x.n], ' ') AS toks
                       FROM (SELECT 1 + floor(power(random(), 2) * :vocab_size)::int + 0 * s.i AS n
                               FROM generate_series(1, 2 + (s.i % 3)::int) g) x CROSS JOIN d1_arr a) tw
 CROSS JOIN LATERAL (SELECT string_agg(a.ws[x.n], ' ') AS words, string_agg(a.tk[x.n], ' ') AS toks
                       FROM (SELECT 1 + floor(power(random(), 2) * :vocab_size)::int + 0 * s.i AS n
                               FROM generate_series(1, 10 + (s.i % 15)::int) g) x CROSS JOIN d1_arr a) bw;

DO $$ BEGIN
    IF EXISTS (SELECT 1 FROM pg_trigger WHERE tgrelid = 'community.pages'::regclass AND tgname = 'scale_board_post_count_insert') THEN
        ALTER TABLE community.pages ENABLE TRIGGER scale_board_post_count_insert;
        UPDATE community.spaces b SET post_count = (SELECT count(*) FROM community.pages p
              WHERE p.deleted_at IS NULL AND p.hidden_at IS NULL
                AND (p.space_id = b.id OR (p.visibility = 'published' AND p.published_board_id = b.id)))
         WHERE b.kind = 'board';
    END IF;
END $$;

-- 게시판 글 30% 에 태그 하나
INSERT INTO community.page_tags (page_id, tag, tag_norm, created_by, created_at)
SELECT i, t, t, 1 + pg_temp.h(i, 1, :users), pg_temp.ts(i, :pages)
  FROM generate_series(1, :pages) i
 CROSS JOIN LATERAL (SELECT (ARRAY['공지','질문','후기','정보','잡담','건의','회의','일정','사진','모집','판매','기타'])[1 + (i % 12)] AS t) x
 WHERE i % 10 < 6 AND i % 10 IN (0, 3, 5);

-- ─── 댓글 — 시각은 번호와 같이 늘고(운영처럼), 글은 그 시각 이전 게시판 글 중 최근 쪽으로 몰린다(power 3) ───
-- 「게시판 글」 고르기: 아무 번호 x → x/10*10 + (0..5)
INSERT INTO community.comments (page_id, body, author_id, created_at, updated_at, deleted_at, deleted_by, search_tsv)
SELECT pid, bw.words, a, c_at, c_at,
       CASE WHEN j % 100 = 5 THEN c_at + interval '1 hour' END, CASE WHEN j % 100 = 5 THEN a END,
       strip(to_tsvector('simple', bw.toks))
  FROM (SELECT j, 1 + pg_temp.h(j, 5, :users) AS a, pg_temp.ts(j, :comments) AS c_at,
               CASE WHEN j % 50 = 0 AND :pages >= 500 THEN 10 * (1 + (j / 50) % 50)  -- 2% 는 인기 글 50개에(댓글 긴 글)
                    ELSE greatest(1, least(:pages, (top - floor(power(random(), 3) * top))::bigint / 10 * 10 + (j % 6))) END AS pid
          FROM (SELECT j, greatest(10, (j::float8 / :comments * :pages)::bigint) AS top
                  FROM generate_series(1, :comments) j) q0) s
 CROSS JOIN LATERAL (SELECT string_agg(a.ws[x.n], ' ') AS words, string_agg(a.tk[x.n], ' ') AS toks
                       FROM (SELECT 1 + floor(power(random(), 2) * :vocab_size)::int + 0 * s.j AS n
                               FROM generate_series(1, 3 + (s.j % 6)::int) g) x CROSS JOIN d1_arr a) bw;

-- ─── 속성 사건(작업 페이지 i%10=9 — 그 시각 이전 작업 중 최근 쪽) ───
INSERT INTO community.page_property_events (page_id, prop, from_value, to_value, by_user_id, at)
SELECT pid, (ARRAY['status','assignee','due'])[1 + (e % 3)],
       CASE e % 3 WHEN 0 THEN 'todo' WHEN 1 THEN 'd1_u00010' ELSE NULL END,
       CASE e % 3 WHEN 0 THEN 'doing' WHEN 1 THEN 'd1_u00011' ELSE '2026-10-30' END,
       1 + pg_temp.h(e, 7, :users), pg_temp.ts(e, :prop_events)
  FROM (SELECT e, least(:pages, greatest(19, (top - floor(power(random(), 3) * top))::bigint / 10 * 10 - 1)) AS pid
          FROM (SELECT e, greatest(20, (e::float8 / :prop_events * :pages)::bigint) AS top
                  FROM generate_series(1, :prop_events) e) q0) s;

-- ─── 알림 ───
INSERT INTO community.notifications (user_id, kind, page_id, actor_id, created_at, read_at)
SELECT 1 + pg_temp.h(n, 11, :users),
       (ARRAY['mention','reply','comment_on_my_page','assigned','due_tomorrow'])[1 + (n % 5)],
       greatest(1, least(:pages, (pg_temp.h(n, 13, :pages) / 10) * 10 + (n % 6))),
       1 + pg_temp.h(n, 17, :users),
       pg_temp.ts(n, :notifications),
       CASE WHEN n % 5 <> 0 THEN pg_temp.ts(n, :notifications) + interval '3 hours' END
  FROM generate_series(1, :notifications) n;

-- ─── 감사 로그 ───
INSERT INTO public.audit_logs (happened_at, request_id, actor_id, actor_name, actor_role, action, target_type, target_id,
                               target_name, access_level, result, reason, ip, user_agent, detail, summary)
SELECT pg_temp.ts(a, :audit), gen_random_uuid(), u, 'd1_u' || lpad(u::text, 5, '0'), 'Employee',
       act, CASE WHEN act LIKE 'file.%' THEN 'file' WHEN act LIKE 'page.%' THEN 'page' ELSE 'user' END,
       1 + pg_temp.h(a, 19, 100000), '합성 대상 ' || (a % 1000), 'internal',
       CASE WHEN a % 40 = 0 THEN 'deny' ELSE 'allow' END, CASE WHEN a % 40 = 0 THEN 'no_permission' END,
       ('10.0.' || (a % 250) || '.' || (a % 199))::inet, 'tbox-d1-synthetic', '{}'::jsonb,
       act || ' 합성 기록 ' || a
  FROM (SELECT a, 1 + pg_temp.h(a, 23, :users) AS u,
               (ARRAY['login','file.download','file.upload','page.view','file.preview','logout','page.update','file.delete'])[1 + (a % 8)] AS act
          FROM generate_series(1, :audit) a) s;

-- ─── 이야기(글 · 미디어 행 · 댓글 · 팔로우) — 미디어 파일 0 ───
INSERT INTO community.memory_posts (id, owner_user_id, caption, original_caption, location, trip, taken_on, visibility,
                                    media_ids, original_media_ids, request_id, created_at, updated_at, deleted_at, discoverable)
SELECT md5('p' || k)::uuid, o, cw.words, cw.words, '서울', '', (pg_temp.ts(k, :stories) - interval '1 day')::date, vis,
       CASE WHEN k <= :media THEN jsonb_build_array(md5('m' || k)::uuid::text) ELSE '[]'::jsonb END,
       CASE WHEN k <= :media THEN jsonb_build_array(md5('m' || k)::uuid::text) ELSE '[]'::jsonb END,
       md5('r' || k)::uuid, pg_temp.ts(k, :stories), pg_temp.ts(k, :stories),
       CASE WHEN k % 60 = 7 THEN pg_temp.ts(k, :stories) + interval '1 day' END,
       vis IN ('community', 'shared') AND k % 2 = 0
  FROM (SELECT k, 1 + pg_temp.h(k, 29, :users) AS o,
               (ARRAY['private','followers','community','community','shared','private','followers','community','community','community'])[1 + (k % 10)] AS vis
          FROM generate_series(1, :stories) k) s
 CROSS JOIN LATERAL (SELECT string_agg(a.ws[x.n], ' ') AS words
                       FROM (SELECT 1 + floor(power(random(), 2) * :vocab_size)::int + 0 * s.k AS n
                               FROM generate_series(1, 4 + (s.k % 8)::int) g) x CROSS JOIN d1_arr a) cw;
INSERT INTO community.memory_media (id, owner_user_id, post_id, mime, filename, sha256, bytes, created_at)
SELECT md5('m' || k)::uuid, 1 + pg_temp.h(k, 29, :users), CASE WHEN k <= :stories THEN md5('p' || k)::uuid END,
       CASE WHEN k % 9 = 0 THEN 'video/mp4' ELSE 'image/jpeg' END, 'd1-' || k || '.jpg', md5('m' || k) || md5('n' || k),
       200000 + (k % 5000), pg_temp.ts(k, greatest(:media, 1))
  FROM generate_series(1, :media) k;
INSERT INTO community.memory_comments (id, post_id, user_id, body, created_at)
SELECT md5('c' || c)::uuid, md5('p' || p)::uuid, 1 + pg_temp.h(c, 31, :users), '합성 댓글 ' || c,
       pg_temp.ts(p, :stories) + (c % 48) * interval '1 hour'
  FROM (SELECT c, 1 + pg_temp.h(c, 37, :stories) AS p FROM generate_series(1, :story_comments) c) s;
INSERT INTO community.memory_follows (follower_user_id, followed_user_id, created_at)
SELECT DISTINCT u, 1 + pg_temp.h(u * 100 + f, 41, :users), now() - interval '100 days'
  FROM generate_series(1, :users) u, generate_series(1, CASE WHEN u <= 3 THEN 30 ELSE 10 END) f
 WHERE 1 + pg_temp.h(u * 100 + f, 41, :users) <> u
ON CONFLICT DO NOTHING;

-- ─── 자료실(폴더 · 파일 행 — 디스크 파일 0) ───
INSERT INTO public.folders (id, name, depth, access_level, team_id, created_by)
SELECT f, '합성 폴더 ' || f, 1, CASE WHEN f % 4 = 0 THEN 'team' WHEN f % 4 = 1 THEN 'public' ELSE 'internal' END,
       CASE WHEN f % 4 = 0 THEN 1 + (f % :teams) END, 1
  FROM generate_series(1, 20 + :teams * 3) f;
INSERT INTO public.files (public_id, original_name, stored_name, ext, mime, size_bytes, sha256, description, access_level,
                          team_id, uploaded_by, created_at, deleted_at, deleted_by, folder_id, search_tsv)
SELECT md5('f' || i)::uuid, nm.words || ' ' || i || '.' || ext, 'd1-' || i || '.' || ext, ext,
       CASE ext WHEN 'pdf' THEN 'application/pdf' WHEN 'xlsx' THEN 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
                ELSE 'text/plain' END,
       1000 + (i % 900000), md5('f' || i) || md5('g' || i), nm.words, lvl,
       CASE WHEN lvl = 'team' THEN pg_temp.team_of(u, :teams) END, u, pg_temp.ts(i, :files),
       CASE WHEN i % 33 = 4 THEN pg_temp.ts(i, :files) + interval '5 days' END, CASE WHEN i % 33 = 4 THEN u END,
       CASE WHEN i % 3 = 0 THEN NULL ELSE 1 + (i % (20 + :teams * 3)) END,
       strip(to_tsvector('simple', nm.toks))
  FROM (SELECT i, 1 + pg_temp.h(i, 43, :users) AS u, (ARRAY['pdf','xlsx','txt'])[1 + (i % 3)] AS ext,
               (ARRAY['public','internal','internal','team','admin-only','private'])[1 + (i % 6)] AS lvl
          FROM generate_series(1, :files) i) s
 CROSS JOIN LATERAL (SELECT string_agg(a.ws[x.n], ' ') AS words, string_agg(a.tk[x.n], ' ') AS toks
                       FROM (SELECT 1 + floor(power(random(), 2) * :vocab_size)::int + 0 * s.i AS n
                               FROM generate_series(1, 3) g) x CROSS JOIN d1_arr a) nm;
UPDATE public.files SET team_id = NULL WHERE access_level = 'private';

-- ─── 신고 · 초대 요청 ───
INSERT INTO community.reports (page_id, reporter_id, reason_kind, detail, status, resolved_by, resolution_note, created_at, resolved_at)
SELECT greatest(1, least(:pages, (pg_temp.h(r, 47, :pages) / 10) * 10 + (r % 6))), 1 + (r % :users),
       (ARRAY['abuse','defamation','privacy','spam','other'])[1 + (r % 5)], '합성 신고 ' || r,
       CASE WHEN r % 3 = 0 THEN 'open' WHEN r % 3 = 1 THEN 'hidden' ELSE 'rejected' END,
       CASE WHEN r % 3 <> 0 THEN 1 END, CASE WHEN r % 3 <> 0 THEN '처리' END,
       pg_temp.ts(r, :reports), CASE WHEN r % 3 <> 0 THEN pg_temp.ts(r, :reports) + interval '1 day' END
  FROM generate_series(1, :reports) r
ON CONFLICT DO NOTHING;
INSERT INTO public.invite_requests (name, org, reason, status_hash, ip, status, decided_by, decided_at, created_at)
SELECT '합성 요청자 ' || r, '합성 조직', '가입 요청 사유 ' || r, md5('ir' || r) || md5('is' || r),
       ('10.1.' || (r % 250) || '.' || (r % 199))::inet,
       CASE WHEN r % 10 < 3 THEN 'open' WHEN r % 10 < 7 THEN 'approved' ELSE 'rejected' END,
       CASE WHEN r % 10 >= 3 THEN 1 END, CASE WHEN r % 10 >= 3 THEN pg_temp.ts(r, :invites) + interval '1 day' END,
       pg_temp.ts(r, :invites)
  FROM generate_series(1, :invites) r;

-- ─── 측정 계정(1 관리자 · 2 직원 · 3 매니저)의 최근 · 즐겨찾기 ───
INSERT INTO community.page_recent (user_id, page_id, last_viewed_at)
SELECT u, p, now() - (p % 500) * interval '1 hour'
  FROM generate_series(1, 3) u, (SELECT DISTINCT greatest(1, least(:pages, pg_temp.h(x, 53, :pages) / 10 * 10 + 1)) AS p
                                   FROM generate_series(1, 100) x) q
ON CONFLICT DO NOTHING;
INSERT INTO community.page_favorites (user_id, page_id, position)
SELECT u, p, row_number() OVER (PARTITION BY u ORDER BY p)
  FROM generate_series(1, 3) u, (SELECT DISTINCT greatest(1, least(:pages, pg_temp.h(x, 59, :pages) / 10 * 10 + 2)) AS p
                                   FROM generate_series(1, 20) x) q
ON CONFLICT DO NOTHING;

-- 순번을 마지막 값 뒤로(앱의 새 행이 부딪히지 않게)
SELECT setval('public.teams_id_seq', :teams), setval('public.users_id_seq', :users),
       setval('community.spaces_id_seq', :users + :teams + :boards), setval('community.pages_id_seq', greatest(:pages, 1)),
       setval('public.folders_id_seq', 20 + :teams * 3);
