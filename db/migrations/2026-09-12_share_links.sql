-- ─────────────────────────────────────────────────────────────
-- 2026-09-12_share_links.sql  (15단계 15-2 — PRD F-13 만료 공유 링크, ARCHITECTURE 5.12 · 6.2-3)
--
-- 목적: 파일 하나를 **로그인 없이** 토큰만으로 받는 링크를 저장할 표를 만든다.
--
-- 설계 요점 (ARCH 6.2-3 컬럼 표 그대로)
--   · token_hash — 토큰 32바이트 난수(base64url 43자)의 **sha256 만** 저장한다.
--     원문은 만들 때 응답에 한 번 나가고 서버 어디에도 남지 않는다 — 세션(6.2-2)과 같은 방식이다.
--     DB 가 통째로 유출돼도 이 값으로는 링크를 되살릴 수 없다.
--   · expires_at  — CHECK 로 **최대 7일**을 DB 가 강제한다. 앱(share_create)이 1~168시간을
--     먼저 보지만, 한 경로라도 빠지면 영구 링크가 생긴다 → 마지막 그물을 DB 에 둔다.
--   · max_downloads — 1~10 (같은 이유로 DB CHECK).
--   · download_count — 받을 때마다 +1. 앱은 트랜잭션 안에서 조건부 UPDATE
--     (`WHERE id = … AND download_count < max_downloads`)로 올리므로 동시 요청이
--     횟수를 넘기지 못한다. DB 도 `download_count <= max_downloads` 를 CHECK 한다.
--   · revoked_at / revoked_by — 회수. **행은 지우지 않는다** (감사 추적, 앱 계정에 DELETE 없음).
--
-- 왜 행을 지우지 않나: 감사 로그(`share.create`·`share.revoke`·`share.download`)가
--   detail.share_id 로 이 행을 가리킨다. 행이 사라지면 "어떤 링크로 새어 나갔나" 를
--   되짚을 수 없다 — files 의 논리 삭제(deleted_at)와 같은 원칙이다.
--
-- 실행: sudo -u postgres psql -d tbox_re -v ON_ERROR_STOP=1 \
--            -f db/migrations/2026-09-12_share_links.sql
--       (새로 설치하는 DB 는 db/schema.sql 에 이미 들어 있어 이 파일이 필요 없다.)
--
-- 재실행 안전성(멱등): CREATE TABLE IF NOT EXISTS · CREATE INDEX IF NOT EXISTS · GRANT.
--   값을 덮어쓰는 UPDATE·DELETE 가 한 줄도 없다.
-- 권한: 새 표이므로 여기서 GRANT 를 함께 준다(기존 표 권한이 자동으로 따라붙지 않는다).
--   앱 계정은 SELECT·INSERT·UPDATE 만이다 — **DELETE 없음**(회수도 UPDATE 다, ARCH 10장).
--   같은 내용이 db/grants.sql 에도 들어간다(재실행 시 권한이 어긋나지 않게 두 곳을 맞춘다).
-- ─────────────────────────────────────────────────────────────

\set ON_ERROR_STOP on

BEGIN;

CREATE TABLE IF NOT EXISTS share_links (
    id             BIGSERIAL   PRIMARY KEY,
    token_hash     CHAR(64)    NOT NULL UNIQUE,           -- 토큰의 sha256. 원문은 저장하지 않는다
    file_id        BIGINT      NOT NULL REFERENCES files(id),
    created_by     BIGINT      NOT NULL REFERENCES users(id),
    expires_at     TIMESTAMPTZ NOT NULL,
    max_downloads  INT         NOT NULL CHECK (max_downloads BETWEEN 1 AND 10),
    download_count INT         NOT NULL DEFAULT 0 CHECK (download_count >= 0),
    revoked_at     TIMESTAMPTZ,
    revoked_by     BIGINT      REFERENCES users(id),
    reason_text    TEXT        CHECK (reason_text IS NULL OR length(reason_text) <= 500),
    created_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
    -- 만료는 만든 시각으로부터 최대 7일 (PRD F-13, ARCH 6.2-3)
    CONSTRAINT share_links_expiry_max CHECK (expires_at <= created_at + interval '7 days'),
    -- 만료가 과거인 링크는 만들 수 없다
    CONSTRAINT share_links_expiry_min CHECK (expires_at > created_at),
    -- 허용 횟수를 넘겨 세지 않는다 (앱의 조건부 UPDATE 와 같은 규칙을 DB 도 본다)
    CONSTRAINT share_links_count_max  CHECK (download_count <= max_downloads)
);

-- token_hash 의 UNIQUE 제약이 조회용 인덱스를 겸한다(`WHERE token_hash = …` 가 유일한 조회 경로).
CREATE INDEX IF NOT EXISTS share_links_created_by_idx ON share_links (created_by);
CREATE INDEX IF NOT EXISTS share_links_file_idx       ON share_links (file_id);

-- ─── 권한 (ARCH 10장 · db/grants.sql 과 같은 내용) ───
REVOKE ALL ON share_links FROM tbox_app;
GRANT SELECT, INSERT, UPDATE ON share_links TO tbox_app;         -- ★ DELETE 없음
GRANT USAGE, SELECT ON SEQUENCE share_links_id_seq TO tbox_app;

-- 백업 계정은 다른 표와 같게 읽기만 (PRD S-08)
GRANT SELECT ON share_links TO tbox_backup;
GRANT SELECT ON SEQUENCE share_links_id_seq TO tbox_backup;

COMMIT;
