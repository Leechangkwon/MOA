-- db/migrations/2026-10-03_qf3a_favorites_recent.sql — 즐겨찾기 · 최근 (개발 대기열 6 · Q:F-3a 라운드 A)
--   (파일 이름의 날짜는 적용 **순서**용이다 — 앞 마이그레이션 2026-10-02_qf2b_files_search.sql 뒤. 작성 2026-09-25)
--
-- 지시서: /var/www/qaredi/docs/qf3a-favorites-recent-bulk-directive.md 1.1 · 1.2 · 2장 · 결정 M-1~M-3 · M-8
-- 구현 기록: docs/qf3a-favorites-recent-implementation.md
--
-- 실행 계정: postgres (표를 만든 뒤 소유자를 다른 표와 같은 tbox_owner 로 넘긴다 — F-70 마이그레이션과 같은 방식).
-- 실행 예:  sudo -u postgres psql -d tbox_re -v ON_ERROR_STOP=1 -f - < db/migrations/2026-10-03_qf3a_favorites_recent.sql
--   ★ 운영 반영은 사용자 sudo 스크립트(review-scripts/apply-qf3a-db-2026-09-25.sh — 검토 뒤 /var/www/qaredi/scripts/)로 한다.
--   ★ 이 파일 뒤에 db/grants.sql · db/community/grants.sql 을 다시 돌려도 같은 권한이 된다(두 파일 끝에 같은 줄이 있다).
--
-- 하는 일 (여러 번 돌려도 같다 — CREATE … IF NOT EXISTS · GRANT 는 멱등 · 기존 행 변경 0 · 확장 0)
--   1. public.file_favorites(user_id, file_id, created_at)      — t-box 파일 즐겨찾기(사람당 200 — 앱이 센다)
--   2. public.folder_favorites(user_id, folder_id, created_at)  — t-box 폴더 즐겨찾기(사람당 50)
--   3. community.page_favorites(user_id, page_id, position, created_at) — 커뮤니티 즐겨찾기(사람당 200 · 끌어서 순서).
--      모양은 뒤에 들어올 「모음」(커뮤니티 F-52)의 기본 모음으로 옮길 수 있게 `user_id, page_id, position` 을 맞춘다.
--   4. community.page_recent(user_id, page_id, last_viewed_at)  — 커뮤니티 최근(읽기 판정을 지난 뒤 5분에 한 번 ·
--      사람당 50줄 — 넘으면 앱이 오래된 것부터 지운다). **기록이 아니라 화면 편의**다(결정 M-3) — 앱 계정에 DELETE 가 있다.
--      t-box 최근은 새 표가 없다 — 감사 로그의 `file.download`·`file.preview` allow 를 읽는다(새로 쓰는 행 0).
--   5. 대상(파일·폴더·페이지)을 가리키는 외래 키는 ON DELETE CASCADE — 빈 폴더는 행째 지워지므로(folders_delete) 폴더
--      즐겨찾기가 그 삭제를 막으면 안 된다. 파일·페이지 행은 지워지지 않는다(휴지통·30일 정리 모두 행을 남긴다 — purged_at)
--      → 지운 파일·페이지의 줄은 남되, 목록 창구가 **지금의 읽기 조건절**(지워진 것 제외 포함) 안에서만 돌려주므로 화면에
--      나오지 않는다(구현 기록 결정 D-2). 사람(users)을 가리키는 외래 키는 다른 표와 같이 ON DELETE 절이 없다 —
--      계정은 지우지 않고(비활성만), 지우려 하면 FK 가 막는 것이 곧 알림이다(db/community/schema.sql 머리글과 같은 판단).
--   6. 권한 — tbox_app: 즐겨찾기 SELECT · INSERT · DELETE(+ 페이지 즐겨찾기 position UPDATE) · 최근 SELECT · INSERT ·
--      DELETE · last_viewed_at UPDATE. tbox_backup: SELECT 만(백업 pg_dump 가 새 표에서 멈추지 않게). tbox_perm·tbox_push: 0.
--
-- 잠금: 외래 키를 걸 때 참조 표(users·files·folders·community.pages)에 짧은 SHARE ROW EXCLUSIVE 잠금을 쥔다. 긴 읽기 뒤에 줄을
--   서서 쓰기를 멈추지 않게 `lock_timeout 5s`(못 잡으면 오류 → ROLLBACK — 아무것도 바뀌지 않는다, 다시 돌리면 된다).
-- 새로 설치하는 DB 는 db/schema.sql · db/community/schema.sql 이 같은 모양을 만든다 — 그 뒤에 이 파일을 돌려도 아무 일도 하지 않는다.
--
-- 되돌리기 (코드를 먼저 되돌린 뒤 — 새 코드는 이 표들을 읽고 쓴다. 표의 줄은 개인 화면 설정뿐이라 지워도 잃는 기록이 없다):
--   DROP TABLE IF EXISTS community.page_recent; DROP TABLE IF EXISTS community.page_favorites;
--   DROP TABLE IF EXISTS public.folder_favorites; DROP TABLE IF EXISTS public.file_favorites;

\set ON_ERROR_STOP on
BEGIN;
SET LOCAL lock_timeout = '5s';
CREATE TABLE IF NOT EXISTS public.file_favorites (
    user_id    BIGINT      NOT NULL REFERENCES public.users(id),
    file_id    BIGINT      NOT NULL REFERENCES public.files(id) ON DELETE CASCADE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (user_id, file_id)
);
CREATE INDEX IF NOT EXISTS file_favorites_file_idx ON public.file_favorites (file_id);
CREATE TABLE IF NOT EXISTS public.folder_favorites (
    user_id    BIGINT      NOT NULL REFERENCES public.users(id),
    folder_id  BIGINT      NOT NULL REFERENCES public.folders(id) ON DELETE CASCADE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (user_id, folder_id)
);
CREATE INDEX IF NOT EXISTS folder_favorites_folder_idx ON public.folder_favorites (folder_id);
CREATE TABLE IF NOT EXISTS community.page_favorites (
    user_id    BIGINT      NOT NULL REFERENCES public.users(id),
    page_id    BIGINT      NOT NULL REFERENCES community.pages(id) ON DELETE CASCADE,
    position   INTEGER     NOT NULL DEFAULT 0 CHECK (position BETWEEN 0 AND 100000),
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (user_id, page_id)
);
CREATE INDEX IF NOT EXISTS page_favorites_page_idx ON community.page_favorites (page_id);
CREATE TABLE IF NOT EXISTS community.page_recent (
    user_id        BIGINT      NOT NULL REFERENCES public.users(id),
    page_id        BIGINT      NOT NULL REFERENCES community.pages(id) ON DELETE CASCADE,
    last_viewed_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (user_id, page_id)
);
CREATE INDEX IF NOT EXISTS page_recent_user_time_idx ON community.page_recent (user_id, last_viewed_at DESC);
CREATE INDEX IF NOT EXISTS page_recent_page_idx ON community.page_recent (page_id);
ALTER TABLE public.file_favorites OWNER TO tbox_owner;
ALTER TABLE public.folder_favorites OWNER TO tbox_owner;
ALTER TABLE community.page_favorites OWNER TO tbox_owner;
ALTER TABLE community.page_recent OWNER TO tbox_owner;
REVOKE ALL ON public.file_favorites, public.folder_favorites FROM tbox_app, tbox_backup;
REVOKE ALL ON community.page_favorites, community.page_recent FROM tbox_app, tbox_backup;
GRANT SELECT, DELETE ON public.file_favorites, public.folder_favorites TO tbox_app;
GRANT INSERT (user_id, file_id) ON public.file_favorites TO tbox_app;
GRANT INSERT (user_id, folder_id) ON public.folder_favorites TO tbox_app;
GRANT SELECT, DELETE ON community.page_favorites, community.page_recent TO tbox_app;
GRANT INSERT (user_id, page_id, position) ON community.page_favorites TO tbox_app;
GRANT UPDATE (position) ON community.page_favorites TO tbox_app;
GRANT INSERT (user_id, page_id) ON community.page_recent TO tbox_app;
GRANT UPDATE (last_viewed_at) ON community.page_recent TO tbox_app;
GRANT SELECT ON public.file_favorites, public.folder_favorites TO tbox_backup;
GRANT SELECT ON community.page_favorites, community.page_recent TO tbox_backup;
COMMIT;
