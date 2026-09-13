-- ─────────────────────────────────────────────────────────────
-- 2026-09-15_purchase_location_inbox.sql  (24단계 — PRD F-37 「브랜드·지점·창고」 한 페이지 ·
--                                          창고 대기표 · 연결/제외, ARCHITECTURE 5.18,
--                                          docs/purchase/09-stage24-directive.md)
--
-- 목적: **지점이 정해지기 전의 창고**가 머무는 자리를 만든다 — `p_location_inbox`(창고 대기표).
--
-- ★ 왜 본 표(p_locations)에 넣지 않나 (지시서 1장)
--   `p_locations.branch_id` 는 NOT NULL 이고, 지점 격리(Authz::purchaseScopeWhere, R-65)·
--   전표·재고·주간 사용량이 전부 그 열로 훑는다. 「지점 없는 창고」를 본 표에 넣으려면 그 열을
--   NULL 허용으로 풀어야 하고, 그 순간 격리·전표·현황 코드가 전부 NULL 을 다시 다뤄야 한다.
--   대신 **연결되기 전의 창고는 본 표 밖(대기표)에** 두고, 사람이 지점을 고르는 순간에만
--   `p_locations` 행을 만든다. 전표·재고 쪽 코드는 한 줄도 바뀌지 않고 격리도 그대로다.
--   (이 파일은 `p_locations` 의 제약을 **한 글자도 건드리지 않는다**.)
--
-- 설계 요점
--   · `ecount_code` 가 정체다 — UNIQUE. 같은 파일을 다시 올려도 행이 늘지 않는다(멱등).
--   · `name` 은 **이카운트 창고명 원문**(trim 만). 「플란치과_강남점」 을 다듬지 않는다.
--   · `status` 셋: unlinked(대기) · linked(연결됨) · excluded(제외). **행을 지우지 않는다.**
--   · `location_id` 는 linked 일 때만 채워지고 `p_locations(id)` 를 가리킨다.
--   · `source_file_id` — 이 행을 마지막으로 만들거나 갱신한 업로드 원본(files.id).
--
-- 권한: tbox_app  SELECT · INSERT · UPDATE (★ DELETE 없음 — 기초정보와 같다, db/grants.sql)
--       tbox_backup SELECT 만 (PRD S-08) · tbox_perm 없음 (SECURITY R-60)
--
-- 실행: sudo -u postgres psql -d tbox_re -v ON_ERROR_STOP=1 \
--            -f db/migrations/2026-09-15_purchase_location_inbox.sql
--
-- 재실행 안전성(멱등): CREATE TABLE/INDEX IF NOT EXISTS · GRANT.
--   **행 값을 바꾸는 UPDATE·DELETE 가 한 줄도 없다** (CLAUDE.md 4장 "운영 데이터에 손대지 않는다").
-- ─────────────────────────────────────────────────────────────

\set ON_ERROR_STOP on

BEGIN;

CREATE TABLE IF NOT EXISTS p_location_inbox (
    id             BIGSERIAL   PRIMARY KEY,
    ecount_code    TEXT        NOT NULL,               -- 창고코드 (원문 trim) — 이 행의 정체
    name           TEXT        NOT NULL,               -- 창고명 원문 그대로 (다듬지 않는다)
    kind_text      TEXT,                               -- 「구분」 원문 (창고·기타 …)
    site_name      TEXT,                               -- 「추가사업장명」 원문 (표시용)
    ecount_active  BOOLEAN     NOT NULL DEFAULT true,  -- 「사용」 Yes/No
    status         TEXT        NOT NULL DEFAULT 'unlinked'
                   CHECK (status IN ('unlinked','linked','excluded')),
    location_id    BIGINT      REFERENCES p_locations(id),   -- linked 일 때만
    source_file_id BIGINT      REFERENCES files(id),         -- 마지막 업로드 원본
    created_by     BIGINT      NOT NULL REFERENCES users(id),
    created_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
    linked_by      BIGINT      REFERENCES users(id),
    linked_at      TIMESTAMPTZ,
    CONSTRAINT p_location_inbox_name_len CHECK (length(name) BETWEEN 1 AND 80),
    CONSTRAINT p_location_inbox_code_len CHECK (length(ecount_code) BETWEEN 1 AND 40)
);

-- 같은 창고코드는 한 행뿐이다 — 같은 파일을 두 번 올려도 「변동 없음」이 되는 근거.
CREATE UNIQUE INDEX IF NOT EXISTS p_location_inbox_code_uidx ON p_location_inbox (ecount_code);
-- 화면이 늘 「미연결」부터 읽는다.
CREATE INDEX IF NOT EXISTS p_location_inbox_status_idx ON p_location_inbox (status);

COMMIT;

-- ─── 권한 (db/grants.sql 과 같은 내용) ───
REVOKE ALL ON p_location_inbox FROM tbox_app;
GRANT SELECT, INSERT, UPDATE ON p_location_inbox TO tbox_app;   -- ★ DELETE 없음
GRANT USAGE, SELECT ON SEQUENCE p_location_inbox_id_seq TO tbox_app;
GRANT SELECT ON p_location_inbox TO tbox_backup;
GRANT SELECT ON SEQUENCE p_location_inbox_id_seq TO tbox_backup;
