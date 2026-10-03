-- db/migrations/2026-10-10_access_grants.sql — G-0 t-box 「개인」 등급 · 사람 지정 권한 · 권한 요청 (PRD F-73)
--   (파일 이름의 날짜는 적용 **순서**용이다 — 앞 마이그레이션 2026-10-08_users_high_contrast.sql 뒤. 작성 2026-09-28, claude.
--    다음 조각 G-1 은 2026-10-11_community_page_folders.sql 을 쓴다.)
--
-- 지시서: ~/.local/share/qaredi-dev/prompt-g0-private-grants.txt 6장 · 구현 기록 docs/g0-implementation.md
--
-- 실행 계정: postgres (표를 만든 뒤 소유자를 다른 표와 같은 tbox_owner 로 넘긴다 — 2026-10-03_qf3a_… 와 같은 방식).
-- 실행 예:  sudo -u postgres psql -d tbox_re -v ON_ERROR_STOP=1 -f - < db/migrations/2026-10-10_access_grants.sql
--   ★ 운영 반영은 서버에서 검증·백업·모의 적용·드리프트 확인 후 수행한다(docs/CLOUD-HANDOFF.md).
--   ★ **코드보다 먼저** 적용한다. 새 코드는 목록 조건절부터 access_grants 를 읽는다 — 표가 없는 DB 에서 새 코드를 돌리면
--     파일 목록이 500 이다. 반대로 이 파일만 먼저 적용하고 옛 코드가 도는 동안은 거의 아무 일도 없다 — 옛 코드는
--     `private` 행을 만들지 않고, 옛 읽기 판정은 can_read 가 참인 줄만 쓴다(새 줄은 전부 거짓). **예외 하나**: 옛 권한
--     매트릭스 화면(F-25 `GET·PATCH /api/admin/permissions` · `GET /api/org/permissions`)은 16행을 기대한다 — 저장소를
--     반영할 때까지(반영 스크립트 두 개 사이 몇 분) 매트릭스 편집이 500, 조회 화면에 칸이 늘어 보인다. 그 사이 편집하지 않는다.
--   ★ 이 파일 뒤에 db/grants.sql · db/community/grants.sql 을 다시 돌려도 같은 권한이 된다(두 파일에 같은 줄이 있다).
--
-- 하는 일 (여러 번 돌려도 같다 — DROP CONSTRAINT IF EXISTS 뒤 같은 이름으로 ADD · CREATE … IF NOT EXISTS ·
--         INSERT … ON CONFLICT DO NOTHING · ADD COLUMN IF NOT EXISTS · REVOKE 뒤 GRANT · 기존 행 변경 0 · 확장 0)
--   1. 등급 CHECK 넓히기 — permissions · files · folders 의 access_level 에 'private' 을 더한다(이름은 그대로).
--      + permissions_private_by_people : 'private' 줄은 can_read = false 만(개인은 표가 아니라 **사람**으로 판정 —
--        권한 매트릭스 편집(F-25, tbox_perm)으로도 켤 수 없다. 켜면 그 역할 전원이 남의 개인 파일을 읽게 된다)
--      + files_private_no_team · folders_private_no_team : 'private' 은 팀이 없다(team_id NULL)
--   2. permissions 에 줄 넷(역할별) — can_read 전부 false · can_upload = Admin·Manager·Employee true · Guest false.
--   3. public.access_grants — 사람 지정 권한(폴더 또는 파일 하나 · 한 사람 · 보기/편집). 폴더 줄은 안의 모든 하위에 이어진다
--      (판정은 앱 — Authz::privateChainSql 한 벌). 개인 등급 항목에만 뜻이 있다(다른 등급이면 줄이 남아도 무시).
--      폴더 외래 키 ON DELETE CASCADE(빈 폴더는 행째 지워진다) · 파일 외래 키는 절 없음(파일 행은 지워지지 않는다 — purged_at).
--   4. public.access_requests — 권한 요청(요청자 · 항목 · 보기/편집 · 메모 200자 · 상태 넷). 같은 (항목, 요청자) pending 하나.
--   5. community.notifications — kind CHECK 에 'access_request'·'access_decided' 를 더하고 칸 access_request_id(페이지 없이)를 둔다.
--      요청이 지워지면(빈 폴더를 지워 요청이 따라 지워질 때) 알림 줄은 남고 칸만 비운다(ON DELETE SET NULL — 화면은 「지워진 항목」).
--   6. 권한 — tbox_app: 표 둘 SELECT · INSERT · UPDATE · DELETE + 시퀀스 USAGE·SELECT · tbox_backup: SELECT ·
--      tbox_perm · tbox_push: 0. (permissions 는 그대로 — tbox_app SELECT 만, tbox_perm SELECT·UPDATE 만.)
--
-- 잠금: CHECK 를 다시 걸 때 files·folders·permissions 를 한 번 훑는다(ACCESS EXCLUSIVE — 운영 행 수가 적어 1초 미만).
--   외래 키를 걸 때 참조 표에 짧은 SHARE ROW EXCLUSIVE. 긴 읽기 뒤에 줄을 서서 쓰기를 멈추지 않게 `lock_timeout 5s`
--   (못 잡으면 오류 → ROLLBACK — 아무것도 바뀌지 않는다, 다시 돌리면 된다).
-- 새로 설치하는 DB 는 db/schema.sql · db/seed.sql · db/community/schema.sql 이 같은 모양을 만든다 — 그 뒤에 이 파일을 돌려도
--   아무 일도 하지 않는다.
--
-- 실패 대응: 신규 표·권한 요청·감사·알림을 삭제하지 않는다. 현재 상태를 확인하고 전진 보정한다.
-- 개인 등급 행이나 새 공유가 생긴 뒤에는 구버전 코드로 단순 되돌릴 수 없다.
-- 광범위 등급으로 바꾸거나 DB를 재시드/복원하는 자동 조치는 하지 않는다.

\set ON_ERROR_STOP on
BEGIN;
SET LOCAL lock_timeout = '5s';

-- ── 1. 등급 CHECK 넓히기 (이름은 PostgreSQL 이 처음에 붙인 것 그대로 — 운영 DB 에서 확인한 이름) ──
ALTER TABLE public.permissions DROP CONSTRAINT IF EXISTS permissions_access_level_check;
ALTER TABLE public.permissions ADD CONSTRAINT permissions_access_level_check
    CHECK (access_level IN ('public','internal','team','admin-only','private'));
ALTER TABLE public.permissions DROP CONSTRAINT IF EXISTS permissions_private_by_people;
ALTER TABLE public.permissions ADD CONSTRAINT permissions_private_by_people
    CHECK (access_level <> 'private' OR can_read = false);

ALTER TABLE public.files DROP CONSTRAINT IF EXISTS files_access_level_check;
ALTER TABLE public.files ADD CONSTRAINT files_access_level_check
    CHECK (access_level IN ('public','internal','team','admin-only','private'));
ALTER TABLE public.files DROP CONSTRAINT IF EXISTS files_private_no_team;
ALTER TABLE public.files ADD CONSTRAINT files_private_no_team
    CHECK (access_level <> 'private' OR team_id IS NULL);

ALTER TABLE public.folders DROP CONSTRAINT IF EXISTS folders_access_level_check;
ALTER TABLE public.folders ADD CONSTRAINT folders_access_level_check
    CHECK (access_level IN ('public','internal','team','admin-only','private'));
ALTER TABLE public.folders DROP CONSTRAINT IF EXISTS folders_private_no_team;
ALTER TABLE public.folders ADD CONSTRAINT folders_private_no_team
    CHECK (access_level <> 'private' OR team_id IS NULL);

-- ── 2. 권한 표 줄 넷 (있으면 그대로 — 운영에서 누가 먼저 넣었어도 값을 덮지 않는다. 값은 아래 확인이 본다) ──
INSERT INTO public.permissions (role, access_level, can_read, can_upload) VALUES
    ('Admin',    'private', false, true),
    ('Manager',  'private', false, true),
    ('Employee', 'private', false, true),
    ('Guest',    'private', false, false)
ON CONFLICT (role, access_level) DO NOTHING;
DO $$
BEGIN
    IF (SELECT count(*) FROM public.permissions WHERE access_level = 'private') <> 4
       OR EXISTS (SELECT 1 FROM public.permissions WHERE access_level = 'private' AND can_read)
       OR EXISTS (SELECT 1 FROM public.permissions WHERE access_level = 'private' AND role = 'Guest' AND can_upload) THEN
        RAISE EXCEPTION 'permissions 의 private 줄이 기대(4줄 · 읽기 0 · 손님 올리기 0)와 다르다 — 멈춘다';
    END IF;
END
$$;

-- ── 3. 사람 지정 권한 ──
CREATE TABLE IF NOT EXISTS public.access_grants (
    id         BIGSERIAL   PRIMARY KEY,
    folder_id  BIGINT      REFERENCES public.folders(id) ON DELETE CASCADE,
    file_id    BIGINT      REFERENCES public.files(id),
    user_id    BIGINT      NOT NULL REFERENCES public.users(id),
    can_write  BOOLEAN     NOT NULL DEFAULT false,
    granted_by BIGINT      NOT NULL REFERENCES public.users(id),
    granted_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT access_grants_one_target CHECK (num_nonnulls(folder_id, file_id) = 1)
);
CREATE UNIQUE INDEX IF NOT EXISTS access_grants_folder_user_uidx ON public.access_grants (folder_id, user_id) WHERE folder_id IS NOT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS access_grants_file_user_uidx   ON public.access_grants (file_id, user_id)   WHERE file_id IS NOT NULL;
CREATE INDEX        IF NOT EXISTS access_grants_user_idx         ON public.access_grants (user_id);
ALTER TABLE public.access_grants OWNER TO tbox_owner;

-- ── 4. 권한 요청 ──
CREATE TABLE IF NOT EXISTS public.access_requests (
    id           BIGSERIAL   PRIMARY KEY,
    folder_id    BIGINT      REFERENCES public.folders(id) ON DELETE CASCADE,
    file_id      BIGINT      REFERENCES public.files(id),
    requester_id BIGINT      NOT NULL REFERENCES public.users(id),
    want_write   BOOLEAN     NOT NULL DEFAULT false,
    message      TEXT        NOT NULL DEFAULT '',
    status       TEXT        NOT NULL DEFAULT 'pending',
    decided_by   BIGINT      REFERENCES public.users(id),
    decided_at   TIMESTAMPTZ,
    created_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT access_requests_one_target  CHECK (num_nonnulls(folder_id, file_id) = 1),
    CONSTRAINT access_requests_message_len CHECK (char_length(message) <= 200),
    CONSTRAINT access_requests_status      CHECK (status IN ('pending','approved','rejected','cancelled')),
    CONSTRAINT access_requests_decided_pair CHECK ((status = 'pending') = (decided_at IS NULL))
);
CREATE UNIQUE INDEX IF NOT EXISTS access_requests_folder_pending_uidx ON public.access_requests (folder_id, requester_id)
    WHERE folder_id IS NOT NULL AND status = 'pending';
CREATE UNIQUE INDEX IF NOT EXISTS access_requests_file_pending_uidx   ON public.access_requests (file_id, requester_id)
    WHERE file_id IS NOT NULL AND status = 'pending';
CREATE INDEX IF NOT EXISTS access_requests_requester_idx ON public.access_requests (requester_id, created_at DESC);
CREATE INDEX IF NOT EXISTS access_requests_folder_idx    ON public.access_requests (folder_id) WHERE folder_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS access_requests_file_idx      ON public.access_requests (file_id)   WHERE file_id IS NOT NULL;
ALTER TABLE public.access_requests OWNER TO tbox_owner;

-- ── 5. 커뮤니티 알림 두 종류 + 칸 ──
-- Extend the currently installed contract; later trust/automation/suggestion kinds stay valid.
DO $$
DECLARE definition text;
BEGIN
    SELECT pg_get_constraintdef(oid) INTO definition FROM pg_constraint
     WHERE conrelid='community.notifications'::regclass AND conname='notifications_kind_check';
    IF definition IS NULL THEN
        RAISE EXCEPTION 'notification kind constraint missing';
    END IF;
    IF position('access_request' in definition)=0 THEN
        ALTER TABLE community.notifications DROP CONSTRAINT notifications_kind_check;
        EXECUTE 'ALTER TABLE community.notifications ADD CONSTRAINT notifications_kind_check CHECK (('
            || substr(definition, 7) || ') OR kind IN (''access_request'',''access_decided''))';
    END IF;
END
$$;
ALTER TABLE community.notifications ADD COLUMN IF NOT EXISTS access_request_id BIGINT
    REFERENCES public.access_requests(id) ON DELETE SET NULL;
CREATE INDEX IF NOT EXISTS notifications_access_request_idx ON community.notifications (access_request_id)
    WHERE access_request_id IS NOT NULL;

-- ── 6. 권한 (db/grants.sql 끝 절과 같은 줄) ──
REVOKE ALL ON public.access_grants, public.access_requests FROM tbox_app, tbox_backup;
REVOKE ALL ON SEQUENCE public.access_grants_id_seq, public.access_requests_id_seq FROM tbox_app, tbox_backup;
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'tbox_perm') THEN
        EXECUTE 'REVOKE ALL ON public.access_grants, public.access_requests FROM tbox_perm';
    END IF;
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'tbox_push') THEN
        EXECUTE 'REVOKE ALL ON public.access_grants, public.access_requests FROM tbox_push';
    END IF;
END
$$;
GRANT SELECT, INSERT, UPDATE, DELETE ON public.access_grants, public.access_requests TO tbox_app;
GRANT USAGE, SELECT ON SEQUENCE public.access_grants_id_seq, public.access_requests_id_seq TO tbox_app;
GRANT SELECT ON public.access_grants, public.access_requests TO tbox_backup;
GRANT SELECT ON SEQUENCE public.access_grants_id_seq, public.access_requests_id_seq TO tbox_backup;
COMMIT;
