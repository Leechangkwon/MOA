-- ─────────────────────────────────────────────────────────────
-- 2026-10-21_privacy_retention.sql  (사이트 Q:F-13 지시서 P2 — 보관 기간 정리 · 탈퇴 · 감사 3년
--   기획 ../docs/privacy-terms-plan.md 5.1 표 · 5.2 「보관 기간 파기」 「감사 3년」 「탈퇴」 행)
--   (파일 이름의 날짜는 적용 **순서**용이다 — 2026-10-20_user_consents.sql 뒤. 작성 2026-10-07)
--
-- 하는 일 (여러 번 돌려도 같다)
--   1. public.account_deletion_requests — 탈퇴 요청(사람 · 요청 때 · 상태 · 처리자 · 처리 때 · 파기 때).
--      앱 계정은 SELECT · INSERT(user_id 한 칸) · UPDATE(status · processed_by · processed_at 세 칸) 만 — DELETE 없음.
--      트리거가 상태를 pending → processed → purged 한 방향으로만 움직이게 하고, processed_at 은 처리하는 순간의 DB 시각으로
--      고정한다(앱이 값을 보내도 덮는다 — 「30일」 시계를 앱이 당길 수 없다). purged 로 바꾸는 것은 소유자 함수뿐이다
--      (purged_at 칸에 앱 UPDATE 권한이 없고 CHECK 가 purged ⇔ purged_at 을 묶는다).
--   2. 파기 함수 넷 — 전부 **표 소유자(tbox_owner)만** 부를 수 있다(EXECUTE 를 PUBLIC 에서 회수 · 소유자 고정).
--      앱 계정 권한은 하나도 넓히지 않는다(audit_logs 는 그대로 SELECT·INSERT 만 — CLAUDE.md 4장 · 지시서 P2 금지 패턴).
--      부르는 것은 scripts/privacy_purge.php(root cron, 소유자 pgpass) 하나 — 결과는 앱 감사 privacy.purge · account.purge 로 남긴다.
--        public.privacy_purge_expired(dry, max)      보관 기간이 지난 줄(5.1 표):
--            모아 1:1 메시지 · 커뮤니티 대화 창 메시지      보낸 날부터 1년
--            모아 AI 대화(턴·빈 대화) · 모아 AI 도구 기록 · 커뮤니티 GPT · 커뮤니티 AI 입력·출력(ai_log · ai_requests)   만든 날부터 1년
--            지운 사진 기록(글 · 사진 행 · 좋아요 · 댓글 · 링크 · 여행 연결) · 지운 여행 계획     지운 뒤 30일
--            감사 기록 audit_logs                          3년 지난 줄만
--          파일(사진 기록 원본 · AI 그림)은 DB 가 지우지 않는다 — 지운 줄의 파일 이름을 `ref` 로 돌려주고 부르는 쪽이
--          **같은 트랜잭션이 열린 동안** 지운 뒤 커밋한다(파일만 남는 일 0 · 커밋 실패면 다음 실행이 줄을 다시 지운다).
--        public.privacy_purge_accounts(dry, max)     탈퇴 처리(processed) 30일이 지난 계정:
--            아이디 deleted-<번호> · 표시 이름 「탈퇴한 사용자 #<번호>」 · 개인 공간 이름도 같은 글자로 ·
--            개인 공간 문서(게시판에 게시한 글 제외) 내용 삭제(행은 남김 — 휴지통 30일 정리와 같은 방식) ·
--            그 사람의 사진 기록 전부(사진 파일 포함) · 여행 계획 · 팔로우 · 사진 설정 · 공유 링크 · 받은 알림 ·
--            로그인 수단의 남은 행(해제된 패스키 · 회수된 토큰 중 비서 실행 기록이 가리키지 않는 것 · 세션 · 일회용 문제) 삭제 ·
--            요청 줄 purged. **남기는 것**: 팀 공간·게시판 글 · 댓글 · 자료실 파일 · 1:1 메시지(1년 규칙) · 감사(3년 규칙) —
--            작성자는 users 행이 바뀌었으므로 「탈퇴한 사용자 #번호」로 보인다.
--            건너뛰는 것: 다시 켜진 계정(still_active) · `deleted-<번호>` 아이디를 다른 계정이 이미 쓰는 경우(name_conflict).
--        community.privacy_purge_memory_posts(ids)    사진 기록 글 묶음과 딸린 줄을 지우고 지운 사진 행 번호를 돌려준다(위 둘이 부른다)
--        community.privacy_purge_pages(ids)           페이지 내용 삭제 — community.purge_trash(2026-09-26)의 삭제 목록과 같고
--                                                     AI 색인(page_embeddings · embedding_queue · ai_page_permissions)도 지운다
--   3. 파기 조건을 받치는 인덱스(있으면 건너뜀 — 표가 없는 설치는 건너뜀).
--
-- 실행 계정: tbox_owner(표 소유자). 이 파일 뒤에 db/grants.sql 을 한 번 더(같은 권한을 다시 건다 — 확인용).
--
-- 잠금: 새 표 하나 · 함수 · 인덱스. CREATE INDEX 는 그 표의 쓰기를 잠깐 막는다(SHARE) — `lock_timeout 5s`
--   로 긴 줄 뒤에 서지 않는다(못 잡으면 오류 → ROLLBACK — 바뀐 것 없음). 운영 크기(메시지·사진 수천 줄)에서는 1초 안.
--
-- ★ 배포 순서: **이 마이그레이션 → 코드 → cron 한 줄**. 새 코드(탈퇴 요청·처리 창구)는 이 표를 쓴다 — 표가 없으면 그 창구만 500.
--   cron(scripts/privacy_purge.php --run)은 함수가 있어야 돈다. 첫 실행 전에 dry(인자 없이)로 건수를 한 번 본다.
-- ★ 행을 지우는 것은 함수가 **불릴 때만**이다 — 이 마이그레이션 자체는 어떤 업무 행도 고치거나 지우지 않는다.
--
-- 재실행 안전성(멱등): CREATE … IF NOT EXISTS · CREATE OR REPLACE · REVOKE 뒤 GRANT · DROP TRIGGER IF EXISTS 뒤 CREATE.
--
-- 실행: sudo -u postgres psql -d tbox_re -v ON_ERROR_STOP=1 -f db/migrations/2026-10-21_privacy_retention.sql
--
-- 확인:
--   \d account_deletion_requests                              → CHECK 둘 · 인덱스 둘 · 트리거 account_deletion_requests_guard
--   \dp account_deletion_requests                             → tbox_app=r/a(user_id)/w(status·processed_by·processed_at) · tbox_backup=r
--   SELECT p.oid::regprocedure, pg_get_userbyid(p.proowner), p.proacl FROM pg_proc p WHERE p.proname LIKE 'privacy_purge%';
--                                                             → 넷 · 소유자 tbox_owner · proacl 에 tbox_app·PUBLIC 없음
--   SELECT has_function_privilege('tbox_app','public.privacy_purge_expired(boolean,integer)','EXECUTE');   → f
--
-- 되돌리기(코드·cron 을 먼저 되돌린 뒤 — 요청 기록이 사라지므로 지우기 전에 사용자 판단):
--   DROP FUNCTION public.privacy_purge_accounts(boolean,integer); DROP FUNCTION public.privacy_purge_expired(boolean,integer);
--   DROP FUNCTION community.privacy_purge_memory_posts(uuid[]); DROP FUNCTION community.privacy_purge_pages(bigint[]);
--   DROP TABLE public.account_deletion_requests; DROP FUNCTION public.account_deletion_requests_guard();
-- ─────────────────────────────────────────────────────────────

\set ON_ERROR_STOP on

BEGIN;
SET LOCAL lock_timeout = '5s';

-- ─── 1. 탈퇴 요청 ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS public.account_deletion_requests (
    id           BIGSERIAL   PRIMARY KEY,
    user_id      BIGINT      NOT NULL REFERENCES public.users(id),
    status       TEXT        NOT NULL DEFAULT 'pending',
    requested_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    processed_by BIGINT      REFERENCES public.users(id),
    processed_at TIMESTAMPTZ,
    purged_at    TIMESTAMPTZ,
    CONSTRAINT account_deletion_requests_status CHECK (status IN ('pending', 'processed', 'purged')),
    CONSTRAINT account_deletion_requests_shape CHECK (
           (status = 'pending'   AND processed_by IS NULL     AND processed_at IS NULL     AND purged_at IS NULL)
        OR (status = 'processed' AND processed_by IS NOT NULL AND processed_at IS NOT NULL AND purged_at IS NULL)
        OR (status = 'purged'    AND processed_by IS NOT NULL AND processed_at IS NOT NULL AND purged_at IS NOT NULL))
);
-- 한 사람의 열린 요청(대기 · 처리됨)은 하나 — 두 번 누름이 두 줄이 되지 않게
CREATE UNIQUE INDEX IF NOT EXISTS account_deletion_requests_open_uidx
    ON public.account_deletion_requests (user_id) WHERE status IN ('pending', 'processed');
-- 파기 대상 찾기(처리됨 · 처리 때 순)
CREATE INDEX IF NOT EXISTS account_deletion_requests_due_idx
    ON public.account_deletion_requests (processed_at) WHERE status = 'processed';

ALTER TABLE public.account_deletion_requests OWNER TO tbox_owner;

-- 상태는 한 방향으로만 · 처리 시각은 DB 가 정한다 · 누구의 요청인지·언제 요청했는지는 바뀌지 않는다
CREATE OR REPLACE FUNCTION public.account_deletion_requests_guard()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = public, pg_temp
AS $fn$
BEGIN
    IF NEW.user_id IS DISTINCT FROM OLD.user_id OR NEW.requested_at IS DISTINCT FROM OLD.requested_at THEN
        RAISE EXCEPTION '탈퇴 요청의 사람·요청 때는 바꿀 수 없다';
    END IF;
    IF OLD.status = 'pending' AND NEW.status = 'processed' THEN
        NEW.processed_at := now();                 -- 앱이 보낸 값을 쓰지 않는다(30일 시계)
    ELSIF OLD.status = 'processed' AND NEW.status = 'purged' THEN
        IF NEW.processed_at IS DISTINCT FROM OLD.processed_at OR NEW.processed_by IS DISTINCT FROM OLD.processed_by THEN
            RAISE EXCEPTION '처리한 사람·때는 바꿀 수 없다';
        END IF;
    ELSIF NEW.status IS DISTINCT FROM OLD.status
       OR NEW.processed_at IS DISTINCT FROM OLD.processed_at
       OR NEW.processed_by IS DISTINCT FROM OLD.processed_by
       OR NEW.purged_at IS DISTINCT FROM OLD.purged_at THEN
        RAISE EXCEPTION '탈퇴 요청 상태는 대기 → 처리 → 파기 한 방향으로만 바뀐다';
    END IF;
    RETURN NEW;
END
$fn$;
ALTER FUNCTION public.account_deletion_requests_guard() OWNER TO tbox_owner;
REVOKE ALL ON FUNCTION public.account_deletion_requests_guard() FROM PUBLIC;
DROP TRIGGER IF EXISTS account_deletion_requests_guard ON public.account_deletion_requests;
CREATE TRIGGER account_deletion_requests_guard BEFORE UPDATE ON public.account_deletion_requests
    FOR EACH ROW EXECUTE FUNCTION public.account_deletion_requests_guard();

REVOKE ALL ON public.account_deletion_requests FROM PUBLIC, tbox_app, tbox_backup;
REVOKE ALL ON SEQUENCE public.account_deletion_requests_id_seq FROM PUBLIC, tbox_app, tbox_backup;
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'tbox_perm') THEN
        EXECUTE 'REVOKE ALL ON public.account_deletion_requests FROM tbox_perm';
    END IF;
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'tbox_push') THEN
        EXECUTE 'REVOKE ALL ON public.account_deletion_requests FROM tbox_push';
    END IF;
END
$$;
GRANT SELECT                                      ON public.account_deletion_requests TO tbox_app;
GRANT INSERT (user_id)                            ON public.account_deletion_requests TO tbox_app;   -- ★ 상태·처리 칸은 기본값만
GRANT UPDATE (status, processed_by, processed_at) ON public.account_deletion_requests TO tbox_app;   -- ★ purged_at 없음 · DELETE 없음
GRANT USAGE, SELECT ON SEQUENCE public.account_deletion_requests_id_seq TO tbox_app;
GRANT SELECT ON public.account_deletion_requests TO tbox_backup;
GRANT SELECT ON SEQUENCE public.account_deletion_requests_id_seq TO tbox_backup;

-- ─── 3. 인덱스 (표가 있을 때만) ─────────────────────────────────
CREATE INDEX IF NOT EXISTS member_messages_sent_idx ON public.member_messages (sent_at);
DO $$
BEGIN
    IF to_regclass('community.memory_posts') IS NOT NULL THEN
        EXECUTE 'CREATE INDEX IF NOT EXISTS memory_posts_deleted_idx ON community.memory_posts (deleted_at) WHERE deleted_at IS NOT NULL';
    END IF;
    IF to_regclass('community.travel_plans') IS NOT NULL THEN
        EXECUTE 'CREATE INDEX IF NOT EXISTS travel_plans_deleted_idx ON community.travel_plans (deleted_at) WHERE deleted_at IS NOT NULL';
    END IF;
    IF to_regclass('community.messages') IS NOT NULL THEN
        EXECUTE 'CREATE INDEX IF NOT EXISTS messages_created_idx ON community.messages (created_at)';
    END IF;
    IF to_regclass('community.gpt_turns') IS NOT NULL THEN
        EXECUTE 'CREATE INDEX IF NOT EXISTS gpt_turns_created_idx ON community.gpt_turns (created_at)';
    END IF;
    IF to_regclass('community.ai_log') IS NOT NULL THEN
        EXECUTE 'CREATE INDEX IF NOT EXISTS ai_log_started_idx ON community.ai_log (started_at)';
        EXECUTE 'CREATE INDEX IF NOT EXISTS ai_requests_started_idx ON community.ai_requests (started_at)';
    END IF;
END
$$;

-- ─── 2. 파기 함수 ───────────────────────────────────────────────

-- 사진 기록 글 묶음 지우기 — 딸린 줄(좋아요 · 댓글 · 공유 링크 · 여행 연결 · 사진 행)까지. 지운 사진 행 번호를 돌려준다(파일은 부르는 쪽).
CREATE OR REPLACE FUNCTION community.privacy_purge_memory_posts(p_ids uuid[])
RETURNS TABLE (media_id uuid)
LANGUAGE plpgsql
SECURITY INVOKER
SET search_path = community, public, pg_temp
AS $fn$
BEGIN
    IF p_ids IS NULL OR cardinality(p_ids) = 0 THEN
        RETURN;
    END IF;
    RETURN QUERY SELECT m.id FROM community.memory_media m WHERE m.post_id = ANY (p_ids) ORDER BY m.id;
    DELETE FROM community.memory_likes l    WHERE l.post_id = ANY (p_ids);
    DELETE FROM community.memory_comments c WHERE c.post_id = ANY (p_ids);
    DELETE FROM community.memory_links k    WHERE k.post_id = ANY (p_ids);
    IF to_regclass('community.travel_plan_records') IS NOT NULL THEN
        DELETE FROM community.travel_plan_records r WHERE r.post_id = ANY (p_ids);
    END IF;
    DELETE FROM community.memory_media m    WHERE m.post_id = ANY (p_ids);
    DELETE FROM community.memory_posts p    WHERE p.id = ANY (p_ids);
END
$fn$;

-- 페이지 내용 지우기 — community.purge_trash(2026-09-26_community_purge.sql)와 같은 삭제 목록(행은 남기고 내용만) + AI 색인.
-- 같은 목록을 두 곳에 두는 까닭: purge_trash 는 휴지통 30일 규칙(검토 끝난 함수)이라 이번에 고치지 않는다. 표가 늘면 둘 다 고친다.
CREATE OR REPLACE FUNCTION community.privacy_purge_pages(p_ids bigint[])
RETURNS bigint
LANGUAGE plpgsql
SECURITY INVOKER
SET search_path = community, public, pg_temp
AS $fn$
DECLARE
    done bigint;
BEGIN
    IF p_ids IS NULL OR cardinality(p_ids) = 0 THEN
        RETURN 0;
    END IF;
    IF to_regclass('community.synced_sources') IS NOT NULL THEN
        DELETE FROM community.synced_references sr WHERE sr.page_id = ANY (p_ids);
        DELETE FROM community.synced_sources ss WHERE ss.page_id = ANY (p_ids);
    END IF;
    IF to_regclass('community.page_embeddings') IS NOT NULL THEN
        DELETE FROM community.page_embeddings e     WHERE e.page_id = ANY (p_ids);
        DELETE FROM community.embedding_queue q     WHERE q.page_id = ANY (p_ids);
        DELETE FROM community.ai_page_permissions a WHERE a.page_id = ANY (p_ids);
    END IF;
    DELETE FROM community.notifications nt
     WHERE nt.page_id = ANY (p_ids)
        OR nt.comment_id IN (SELECT c.id FROM community.comments c WHERE c.page_id = ANY (p_ids));
    DELETE FROM community.comment_reactions r
     WHERE r.comment_id IN (SELECT c.id FROM community.comments c WHERE c.page_id = ANY (p_ids));
    DELETE FROM community.comments c WHERE c.page_id = ANY (p_ids) AND c.parent_comment_id IS NOT NULL;
    DELETE FROM community.comments c WHERE c.page_id = ANY (p_ids);
    DELETE FROM community.page_versions v      WHERE v.page_id = ANY (p_ids);
    DELETE FROM community.page_blocks b        WHERE b.page_id = ANY (p_ids);
    DELETE FROM community.page_links l         WHERE l.from_page_id = ANY (p_ids);
    DELETE FROM community.page_edit_sessions s WHERE s.page_id = ANY (p_ids);
    DELETE FROM community.page_shares s        WHERE s.page_id = ANY (p_ids);
    DELETE FROM community.page_tags t          WHERE t.page_id = ANY (p_ids);
    DELETE FROM community.page_views w         WHERE w.page_id = ANY (p_ids);
    DELETE FROM community.page_reads r         WHERE r.page_id = ANY (p_ids);
    DELETE FROM community.page_property_events e WHERE e.page_id = ANY (p_ids) AND e.prop LIKE 'db:%';
    UPDATE community.databases d SET schema = '[]'::jsonb, views = '[]'::jsonb, updated_at = now()
     WHERE d.page_id = ANY (p_ids);
    UPDATE community.pages p
       SET title = '영구 삭제된 페이지', icon = NULL,
           doc = '{"schema":1,"blocks":[]}'::jsonb, plain_text = '', search_tsv = NULL, block_count = 0,
           database_id = NULL, properties = NULL,
           deleted_at = coalesce(p.deleted_at, now()), purge_after = coalesce(p.purge_after, now()),
           purged_at = now()
     WHERE p.id = ANY (p_ids) AND p.purged_at IS NULL;
    GET DIAGNOSTICS done = ROW_COUNT;
    RETURN done;
END
$fn$;

-- 보관 기간이 지난 줄 — 5.1 표. 종류마다 한 줄(item · 건수) + 지운 파일마다 한 줄(item = assist_file|memory_file · ref = 파일 이름).
-- dry 는 같은 수를 세기만 한다(아무것도 바꾸지 않는다). 종류마다 한 번에 p_max 줄까지 — 남은 것은 다음 날.
CREATE OR REPLACE FUNCTION public.privacy_purge_expired(p_dry boolean DEFAULT true, p_max integer DEFAULT 5000)
RETURNS TABLE (item text, n bigint, ref text)
LANGUAGE plpgsql
SECURITY INVOKER
SET search_path = public, community, pg_temp
AS $fn$
DECLARE
    year_ago   timestamptz := now() - interval '1 year';
    month_ago  timestamptz := now() - interval '30 days';
    audit_cut  timestamptz := now() - interval '3 years';
    big_ids    bigint[];
    msg_ids    bigint[];
    turn_ids   bigint[];
    uuid_ids   uuid[];
BEGIN
    IF p_dry IS NULL OR p_max IS NULL OR p_max < 1 OR p_max > 50000 THEN
        RAISE EXCEPTION 'p_dry 는 참/거짓 · p_max 는 1~50000';
    END IF;

    -- ① 모아 1:1 메시지 — 보낸 날부터 1년
    SELECT coalesce(array_agg(x.id ORDER BY x.id), '{}') INTO msg_ids
      FROM (SELECT mm.id FROM public.member_messages mm WHERE mm.sent_at < year_ago
             ORDER BY mm.id LIMIT p_max FOR UPDATE SKIP LOCKED) x;
    item := 'member_messages'; n := cardinality(msg_ids); ref := NULL; RETURN NEXT;
    IF NOT p_dry AND cardinality(msg_ids) > 0 THEN
        -- 남는 AI 도구 기록이 지울 메시지를 가리키면 그 연결만 끊는다(도구 기록은 제 1년 규칙대로)
        UPDATE public.member_ai_assists aa SET source_message_id = NULL WHERE aa.source_message_id = ANY (msg_ids);
        DELETE FROM public.member_messages mm WHERE mm.id = ANY (msg_ids);
    END IF;

    -- ② 모아 AI 도구 기록 — 만든 날부터 1년. 남는 메시지에 붙은 그림은 그 메시지가 지워질 때까지 함께 남는다
    SELECT coalesce(array_agg(x.id ORDER BY x.id), '{}') INTO uuid_ids
      FROM (SELECT aa.id FROM public.member_ai_assists aa
             WHERE aa.created_at < year_ago
               AND NOT EXISTS (SELECT 1 FROM public.member_messages mm
                                WHERE mm.ai_attachment_id = aa.id AND NOT (mm.id = ANY (msg_ids)))
             ORDER BY aa.id LIMIT p_max FOR UPDATE OF aa SKIP LOCKED) x;
    item := 'member_ai_assists'; n := cardinality(uuid_ids); ref := NULL; RETURN NEXT;
    RETURN QUERY SELECT 'assist_file'::text, 1::bigint, aa.stored_name
                   FROM public.member_ai_assists aa
                  WHERE aa.id = ANY (uuid_ids) AND aa.stored_name IS NOT NULL ORDER BY aa.stored_name;
    IF NOT p_dry AND cardinality(uuid_ids) > 0 THEN
        DELETE FROM public.member_ai_assists aa WHERE aa.id = ANY (uuid_ids);
    END IF;

    -- ③ 모아 AI 대화 — 만든 날부터 1년(턴) · 턴이 하나도 남지 않고 1년 지난 대화 줄(제목)
    SELECT coalesce(array_agg(x.id ORDER BY x.id), '{}') INTO turn_ids
      FROM (SELECT tt.id FROM public.member_ai_turns tt WHERE tt.created_at < year_ago
             ORDER BY tt.id LIMIT p_max FOR UPDATE SKIP LOCKED) x;
    item := 'member_ai_turns'; n := cardinality(turn_ids); ref := NULL; RETURN NEXT;
    IF NOT p_dry AND cardinality(turn_ids) > 0 THEN
        DELETE FROM public.member_ai_turns tt WHERE tt.id = ANY (turn_ids);
    END IF;
    SELECT coalesce(array_agg(x.id ORDER BY x.id), '{}') INTO big_ids
      FROM (SELECT th.id FROM public.member_ai_threads th
             WHERE th.created_at < year_ago
               AND NOT EXISTS (SELECT 1 FROM public.member_ai_turns tt
                                WHERE tt.thread_id = th.id AND NOT (tt.id = ANY (turn_ids)))
             ORDER BY th.id LIMIT p_max FOR UPDATE OF th SKIP LOCKED) x;
    item := 'member_ai_threads'; n := cardinality(big_ids); ref := NULL; RETURN NEXT;
    IF NOT p_dry AND cardinality(big_ids) > 0 THEN
        DELETE FROM public.member_ai_threads th WHERE th.id = ANY (big_ids);
    END IF;

    -- ④ 커뮤니티 대화 창 메시지 · GPT 대화 — 1년 (표가 있는 설치만)
    IF to_regclass('community.messages') IS NOT NULL THEN
        SELECT coalesce(array_agg(x.id ORDER BY x.id), '{}') INTO big_ids
          FROM (SELECT cm.id FROM community.messages cm WHERE cm.created_at < year_ago
                 ORDER BY cm.id LIMIT p_max FOR UPDATE SKIP LOCKED) x;
        item := 'community_messages'; n := cardinality(big_ids); ref := NULL; RETURN NEXT;
        IF NOT p_dry AND cardinality(big_ids) > 0 THEN
            DELETE FROM community.messages cm WHERE cm.id = ANY (big_ids);
        END IF;
    END IF;
    IF to_regclass('community.gpt_turns') IS NOT NULL THEN
        SELECT coalesce(array_agg(x.id ORDER BY x.id), '{}') INTO big_ids
          FROM (SELECT gt.id FROM community.gpt_turns gt WHERE gt.created_at < year_ago
                 ORDER BY gt.id LIMIT p_max FOR UPDATE SKIP LOCKED) x;
        item := 'community_gpt_turns'; n := cardinality(big_ids); ref := NULL; RETURN NEXT;
        IF NOT p_dry AND cardinality(big_ids) > 0 THEN
            DELETE FROM community.gpt_turns gt WHERE gt.id = ANY (big_ids);
        END IF;
    END IF;

    -- ⑤ 커뮤니티 AI 입력·출력(ai_log) · 사용 횟수 줄(ai_requests) — 만든 날부터 1년
    IF to_regclass('community.ai_log') IS NOT NULL THEN
        SELECT coalesce(array_agg(x.id ORDER BY x.id), '{}') INTO big_ids
          FROM (SELECT al.id FROM community.ai_log al WHERE al.started_at < year_ago
                 ORDER BY al.id LIMIT p_max FOR UPDATE SKIP LOCKED) x;
        item := 'community_ai_log'; n := cardinality(big_ids); ref := NULL; RETURN NEXT;
        IF NOT p_dry AND cardinality(big_ids) > 0 THEN
            DELETE FROM community.ai_log al WHERE al.id = ANY (big_ids);
        END IF;
        SELECT count(*) INTO n FROM (
            SELECT ar.id FROM community.ai_requests ar
             WHERE ar.started_at < year_ago
               AND NOT EXISTS (SELECT 1 FROM community.ai_log al WHERE al.request_id = ar.id AND NOT (al.id = ANY (big_ids)))
             LIMIT p_max) x;
        item := 'community_ai_requests'; ref := NULL; RETURN NEXT;
        IF NOT p_dry THEN
            DELETE FROM community.ai_requests ar
             WHERE ar.id IN (SELECT r2.id FROM community.ai_requests r2
                              WHERE r2.started_at < year_ago
                                AND NOT EXISTS (SELECT 1 FROM community.ai_log al WHERE al.request_id = r2.id)
                              ORDER BY r2.id LIMIT p_max);
        END IF;
    END IF;

    -- ⑥ 지운 사진 기록 — 지운 뒤 30일(글 · 사진 행 · 딸린 줄 · 사진 파일)
    IF to_regclass('community.memory_posts') IS NOT NULL THEN
        SELECT coalesce(array_agg(x.id ORDER BY x.id), '{}') INTO uuid_ids
          FROM (SELECT mp.id FROM community.memory_posts mp
                 WHERE mp.deleted_at IS NOT NULL AND mp.deleted_at < month_ago
                 ORDER BY mp.id LIMIT p_max FOR UPDATE SKIP LOCKED) x;
        item := 'memory_posts'; n := cardinality(uuid_ids); ref := NULL; RETURN NEXT;
        IF p_dry THEN
            RETURN QUERY SELECT 'memory_file'::text, 1::bigint, md.id::text FROM community.memory_media md
                          WHERE md.post_id = ANY (uuid_ids) ORDER BY md.id;
        ELSE
            RETURN QUERY SELECT 'memory_file'::text, 1::bigint, pm.media_id::text
                           FROM community.privacy_purge_memory_posts(uuid_ids) pm;
        END IF;
    END IF;

    -- ⑦ 지운 여행 계획 — 지운 뒤 30일(계획 · 사진 기록 연결)
    IF to_regclass('community.travel_plans') IS NOT NULL THEN
        SELECT coalesce(array_agg(x.id ORDER BY x.id), '{}') INTO uuid_ids
          FROM (SELECT tp.id FROM community.travel_plans tp
                 WHERE tp.deleted_at IS NOT NULL AND tp.deleted_at < month_ago
                 ORDER BY tp.id LIMIT p_max FOR UPDATE SKIP LOCKED) x;
        item := 'travel_plans'; n := cardinality(uuid_ids); ref := NULL; RETURN NEXT;
        IF NOT p_dry AND cardinality(uuid_ids) > 0 THEN
            DELETE FROM community.travel_plan_records tr WHERE tr.plan_id = ANY (uuid_ids);
            DELETE FROM community.travel_plans tp WHERE tp.id = ANY (uuid_ids);
        END IF;
    END IF;

    -- ⑧ 감사 기록 — 3년 지난 줄만(앱 계정은 그대로 SELECT·INSERT 만 — 지우는 길은 이 함수 하나)
    SELECT coalesce(array_agg(x.id ORDER BY x.id), '{}') INTO big_ids
      FROM (SELECT au.id FROM public.audit_logs au WHERE au.happened_at < audit_cut
             ORDER BY au.id LIMIT p_max) x;
    item := 'audit_logs'; n := cardinality(big_ids); ref := NULL; RETURN NEXT;
    IF NOT p_dry AND cardinality(big_ids) > 0 THEN
        DELETE FROM public.audit_logs au WHERE au.id = ANY (big_ids) AND au.happened_at < audit_cut;
    END IF;
END
$fn$;

-- 탈퇴 처리 30일이 지난 계정 — 계정마다 줄 여럿(account_id · item · 건수) + 지운 사진 파일마다 한 줄(item = memory_file · ref).
CREATE OR REPLACE FUNCTION public.privacy_purge_accounts(p_dry boolean DEFAULT true, p_max integer DEFAULT 20)
RETURNS TABLE (account_id bigint, item text, n bigint, ref text)
LANGUAGE plpgsql
SECURITY INVOKER
SET search_path = public, community, pg_temp
AS $fn$
DECLARE
    q         record;
    post_ids  uuid[];
    plan_ids  uuid[];
    page_ids  bigint[];
    done      bigint;
    has_memo  boolean := to_regclass('community.memory_posts') IS NOT NULL;
    has_plan  boolean := to_regclass('community.travel_plans') IS NOT NULL;
    has_space boolean := to_regclass('community.pages') IS NOT NULL;
    has_runs  boolean := to_regclass('public.assistant_runs') IS NOT NULL;
BEGIN
    IF p_dry IS NULL OR p_max IS NULL OR p_max < 1 OR p_max > 500 THEN
        RAISE EXCEPTION 'p_dry 는 참/거짓 · p_max 는 1~500';
    END IF;

    FOR q IN
        SELECT dr.id AS request_no, dr.user_id AS uid, u.is_active AS active
          FROM public.account_deletion_requests dr
          JOIN public.users u ON u.id = dr.user_id
         WHERE dr.status = 'processed' AND dr.processed_at < now() - interval '30 days'
         ORDER BY dr.processed_at, dr.id
         LIMIT p_max
           FOR UPDATE OF dr, u SKIP LOCKED
    LOOP
        account_id := q.uid; ref := NULL;
        -- 처리 뒤 관리자가 다시 켠 계정은 지우지 않는다(사람 판단이 먼저) — 부르는 쪽이 오류 감사로 남긴다
        IF q.active THEN
            item := 'still_active'; n := 1; RETURN NEXT;
            CONTINUE;
        END IF;
        IF EXISTS (SELECT 1 FROM public.users ux WHERE ux.username = 'deleted-' || q.uid AND ux.id <> q.uid) THEN
            item := 'name_conflict'; n := 1; RETURN NEXT;
            CONTINUE;
        END IF;

        -- 사진 기록 전부(사진 파일 포함) — 붙지 않은 사진까지
        IF has_memo THEN
            SELECT coalesce(array_agg(mp.id ORDER BY mp.id), '{}') INTO post_ids
              FROM community.memory_posts mp WHERE mp.owner_user_id = q.uid;
            item := 'memory_posts'; n := cardinality(post_ids); RETURN NEXT;
            RETURN QUERY SELECT q.uid, 'memory_file'::text, 1::bigint, md.id::text
                           FROM community.memory_media md WHERE md.owner_user_id = q.uid ORDER BY md.id;
            IF NOT p_dry THEN
                PERFORM 1 FROM community.privacy_purge_memory_posts(post_ids);
                DELETE FROM community.memory_media md WHERE md.owner_user_id = q.uid;
                DELETE FROM community.memory_links mk WHERE mk.owner_user_id = q.uid;
                DELETE FROM community.memory_follows mf WHERE mf.follower_user_id = q.uid OR mf.followed_user_id = q.uid;
                DELETE FROM community.memory_preferences mr WHERE mr.user_id = q.uid;
            END IF;
        END IF;

        -- 여행 계획
        IF has_plan THEN
            SELECT coalesce(array_agg(tp.id ORDER BY tp.id), '{}') INTO plan_ids
              FROM community.travel_plans tp WHERE tp.owner_user_id = q.uid;
            item := 'travel_plans'; n := cardinality(plan_ids); ref := NULL; RETURN NEXT;
            IF NOT p_dry AND cardinality(plan_ids) > 0 THEN
                DELETE FROM community.travel_plan_records tr WHERE tr.plan_id = ANY (plan_ids);
                DELETE FROM community.travel_plans tp WHERE tp.id = ANY (plan_ids);
            END IF;
        END IF;

        -- 개인 공간 문서(게시판에 게시한 글은 게시판 글이라 남긴다) · 공간 이름 · 받은 알림
        IF has_space THEN
            SELECT coalesce(array_agg(pg.id ORDER BY pg.id), '{}') INTO page_ids
              FROM community.pages pg JOIN community.spaces sp ON sp.id = pg.space_id
             WHERE sp.kind = 'personal' AND sp.owner_user_id = q.uid
               AND pg.purged_at IS NULL AND pg.visibility <> 'published';
            item := 'personal_pages'; n := cardinality(page_ids); ref := NULL; RETURN NEXT;
            IF NOT p_dry THEN
                PERFORM community.privacy_purge_pages(page_ids);
                UPDATE community.spaces sp SET name = '탈퇴한 사용자 #' || q.uid || ' 의 공간', updated_at = now()
                 WHERE sp.kind = 'personal' AND sp.owner_user_id = q.uid;
                DELETE FROM community.notifications nt WHERE nt.user_id = q.uid;
            END IF;
        END IF;

        -- 로그인 수단의 남은 행 — 처리 때 이미 끊고 해제·회수했다(쓸 수 없는 상태). 여기서 행까지 지운다
        SELECT count(*) INTO n FROM public.passkeys pk WHERE pk.user_id = q.uid;
        item := 'device_keys'; ref := NULL; RETURN NEXT;
        SELECT count(*) INTO n FROM public.api_tokens tk
         WHERE tk.user_id = q.uid
           AND (NOT has_runs OR NOT EXISTS (SELECT 1 FROM public.assistant_runs ar WHERE ar.token_id = tk.id));
        item := 'program_keys'; ref := NULL; RETURN NEXT;
        IF NOT p_dry THEN
            DELETE FROM public.sessions ss WHERE ss.user_id = q.uid;
            DELETE FROM public.passkey_challenges pc WHERE pc.user_id = q.uid;
            DELETE FROM public.passkeys pk WHERE pk.user_id = q.uid;
            IF has_runs THEN
                DELETE FROM public.api_tokens tk
                 WHERE tk.user_id = q.uid
                   AND NOT EXISTS (SELECT 1 FROM public.assistant_runs ar WHERE ar.token_id = tk.id);
            ELSE
                DELETE FROM public.api_tokens tk WHERE tk.user_id = q.uid;
            END IF;

            -- 이름 바꾸기 — 감사 기록의 이름 칸은 그대로(3년 규칙)
            UPDATE public.users ux
               SET username = 'deleted-' || q.uid,
                   display_name = '탈퇴한 사용자 #' || q.uid,
                   mfa_secret = NULL, mfa_enabled = false, mfa_last_step = NULL,
                   updated_at = now()
             WHERE ux.id = q.uid;
            UPDATE public.account_deletion_requests dr SET status = 'purged', purged_at = now() WHERE dr.id = q.request_no;
        END IF;
        item := 'anonymized'; n := 1; ref := NULL; RETURN NEXT;
    END LOOP;
END
$fn$;

-- ─── 함수 권한 — 소유자만 ───────────────────────────────────────
REVOKE ALL ON FUNCTION community.privacy_purge_memory_posts(uuid[])      FROM PUBLIC;
REVOKE ALL ON FUNCTION community.privacy_purge_pages(bigint[])           FROM PUBLIC;
REVOKE ALL ON FUNCTION public.privacy_purge_expired(boolean, integer)    FROM PUBLIC;
REVOKE ALL ON FUNCTION public.privacy_purge_accounts(boolean, integer)   FROM PUBLIC;
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'tbox_owner') THEN
        ALTER FUNCTION community.privacy_purge_memory_posts(uuid[])    OWNER TO tbox_owner;
        ALTER FUNCTION community.privacy_purge_pages(bigint[])         OWNER TO tbox_owner;
        ALTER FUNCTION public.privacy_purge_expired(boolean, integer)  OWNER TO tbox_owner;
        ALTER FUNCTION public.privacy_purge_accounts(boolean, integer) OWNER TO tbox_owner;
    END IF;
END
$$;

COMMIT;
