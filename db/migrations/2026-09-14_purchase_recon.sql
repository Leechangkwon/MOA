-- ─────────────────────────────────────────────────────────────
-- 2026-09-14_purchase_recon.sql  (22단계 — PRD F-35 거래처 월 대사 · 월 마감 ·
--                                 기공 명세서 자동 전표, F-34 주간 사용량,
--                                 ARCHITECTURE 5.18, docs/purchase/07-stage22-directive.md)
--
-- 목적: ① 거래처가 보내온 **명세서**를 표로 받아 우리 입고와 줄마다 맞춘 결과를 남기고,
--       ② 그 달의 전표를 **잠그는**(월 마감) 자리를 만든다.
--
-- ★ R-69 — 명세서에서 가져오는 열은 **품목·수량·단가·금액**뿐이다.
--   담당자 연락처·계좌번호 같은 칸은 `p_recon_lines` 에 **둘 자리가 없다**(컬럼이 없다).
--   원본 파일은 `files` 의 admin-only 폴더(「구매/명세서/거래처/월」)에만 남고, 그 파일은
--   업로드 때 F-22 민감정보 검출을 그대로 지난다.
--
-- ★ 이 파일에도 앱 계정의 DELETE 가 없다. 대사 결과를 다시 만들려면 명세서를 **다시 올린다**
--   (파일마다 한 장 = `UNIQUE(vendor_id, month, file_id)`). 지운 자리를 남기지 않는 것이
--   19~21단계와 같은 규칙이다.
--
-- 설계 요점
--   · p_recon_statements — 명세서 한 장. `status open|closed`, `mapping_json` 은 확정할 때
--     쓴 열 매핑(같은 거래처의 다음 달 명세서가 두 걸음으로 끝나게), `total_amount` 는
--     **명세서 쪽 합계**(파일에 적힌 값)다. 「우리 합계」는 저장하지 않는다 — 전표에서
--     언제든 다시 계산되는 값이고, 저장하면 원장과 어긋날 수 있다(현재고와 같은 판단).
--   · p_recon_lines — 명세서 줄 ↔ 우리 입고 줄. `matched_line_id` 가 우리 줄,
--     `diff_type` 이 판정(none·qty·price·missing_ours·missing_theirs)이다.
--     「우리만 있음」(missing_theirs) 줄은 명세서에 없던 줄이므로 `row_no` 가 명세서 행이
--     아니라 이어 붙인 번호이고, `item_name` 은 우리 품목명이다.
--   · p_month_close — 지점 마감(`branch_id`)과 전사 마감(`branch_id IS NULL`) 둘 다.
--     전사 마감이 있으면 지점 것도 잠긴다(판정은 앱의 Doc::closeGuard 한 곳).
--     `UNIQUE(branch_id, month)` 는 NULL 을 같은 값으로 보지 않으므로, 전사 행의 유일성은
--     부분 UNIQUE 인덱스로 따로 건다.
--   · p_docs.closed_month 는 20단계에 이미 있다 — 마감할 때 그 달 전표에 채운다(UPDATE).
--
-- 실행: sudo -u postgres psql -d tbox_re -v ON_ERROR_STOP=1 \
--            -f db/migrations/2026-09-14_purchase_recon.sql
--
-- 재실행 안전성(멱등): CREATE TABLE/INDEX IF NOT EXISTS · GRANT.
--   **행 값을 바꾸는 UPDATE·DELETE 가 한 줄도 없다** (CLAUDE.md 4장).
--
-- 권한: tbox_app    SELECT·INSERT·UPDATE (★ DELETE 없음)
--       tbox_backup SELECT 만 (PRD S-08)
--       tbox_perm   없음 (SECURITY R-60)
-- ─────────────────────────────────────────────────────────────

\set ON_ERROR_STOP on

BEGIN;

-- ─── 명세서 한 장 ────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS p_recon_statements (
    id           BIGSERIAL   PRIMARY KEY,
    vendor_id    BIGINT      NOT NULL REFERENCES p_vendors(id),
    month        CHAR(7)     NOT NULL,
    file_id      BIGINT      NOT NULL REFERENCES files(id),
    mapping_json JSONB,
    total_amount NUMERIC(16,2),
    status       TEXT        NOT NULL DEFAULT 'open',
    created_by   BIGINT      NOT NULL REFERENCES users(id),
    created_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT p_recon_statements_month_fmt CHECK (month ~ '^[0-9]{4}-[0-9]{2}$'),
    CONSTRAINT p_recon_statements_status_check CHECK (status IN ('open', 'closed')),
    CONSTRAINT p_recon_statements_total_check
        CHECK (total_amount IS NULL OR total_amount >= 0),
    CONSTRAINT p_recon_statements_uniq UNIQUE (vendor_id, month, file_id)
);
CREATE INDEX IF NOT EXISTS p_recon_statements_month_idx
    ON p_recon_statements (month, vendor_id);

-- ─── 명세서 줄 ↔ 우리 입고 줄 ───────────────────────────────
--   ★ 여기 있는 컬럼이 **명세서에서 가져오는 것의 전부**다 (R-69).
CREATE TABLE IF NOT EXISTS p_recon_lines (
    id              BIGSERIAL   PRIMARY KEY,
    statement_id    BIGINT      NOT NULL REFERENCES p_recon_statements(id),
    row_no          INT         NOT NULL CHECK (row_no >= 1),
    item_code       TEXT,
    item_name       TEXT        NOT NULL,
    qty             NUMERIC(14,3) NOT NULL,
    unit_price      NUMERIC(14,2),
    amount          NUMERIC(16,2),
    matched_line_id BIGINT      REFERENCES p_doc_lines(id),
    diff_type       TEXT,
    note            TEXT,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT p_recon_lines_code_len CHECK (item_code IS NULL OR length(item_code) BETWEEN 1 AND 60),
    CONSTRAINT p_recon_lines_name_len CHECK (length(item_name) BETWEEN 1 AND 200),
    CONSTRAINT p_recon_lines_note_len CHECK (note IS NULL OR length(note) <= 200),
    CONSTRAINT p_recon_lines_diff_check
        CHECK (diff_type IS NULL
               OR diff_type IN ('none', 'qty', 'price', 'missing_ours', 'missing_theirs')),
    CONSTRAINT p_recon_lines_row_uniq UNIQUE (statement_id, row_no)
);
CREATE INDEX IF NOT EXISTS p_recon_lines_stmt_idx    ON p_recon_lines (statement_id);
CREATE INDEX IF NOT EXISTS p_recon_lines_matched_idx ON p_recon_lines (matched_line_id);

-- ─── 월 마감 ────────────────────────────────────────────────
--   branch_id IS NULL = 전사 마감. 전사가 있으면 지점 것도 잠긴다(앱 판정).
CREATE TABLE IF NOT EXISTS p_month_close (
    id          BIGSERIAL   PRIMARY KEY,
    branch_id   BIGINT      REFERENCES p_branches(id),
    month       CHAR(7)     NOT NULL,
    closed_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
    closed_by   BIGINT      NOT NULL REFERENCES users(id),
    reason_text TEXT,
    CONSTRAINT p_month_close_month_fmt CHECK (month ~ '^[0-9]{4}-[0-9]{2}$'),
    CONSTRAINT p_month_close_reason_len CHECK (reason_text IS NULL OR length(reason_text) <= 300),
    CONSTRAINT p_month_close_uniq UNIQUE (branch_id, month)
);
-- 전사 마감(branch_id IS NULL)은 UNIQUE 가 잡지 못한다 — 부분 인덱스로 한 달에 하나만.
CREATE UNIQUE INDEX IF NOT EXISTS p_month_close_all_uidx
    ON p_month_close (month) WHERE branch_id IS NULL;
CREATE INDEX IF NOT EXISTS p_month_close_month_idx ON p_month_close (month);

-- ─── 권한 (ARCH 10장 · db/grants.sql 과 같은 내용) ───────────
REVOKE ALL ON p_recon_statements, p_recon_lines, p_month_close FROM tbox_app;
GRANT SELECT, INSERT, UPDATE ON p_recon_statements, p_recon_lines, p_month_close TO tbox_app;
GRANT USAGE, SELECT ON SEQUENCE p_recon_statements_id_seq TO tbox_app;
GRANT USAGE, SELECT ON SEQUENCE p_recon_lines_id_seq      TO tbox_app;
GRANT USAGE, SELECT ON SEQUENCE p_month_close_id_seq      TO tbox_app;

-- 백업 계정은 읽기만 (PRD S-08)
GRANT SELECT ON p_recon_statements, p_recon_lines, p_month_close TO tbox_backup;
GRANT SELECT ON ALL SEQUENCES IN SCHEMA public TO tbox_backup;

-- tbox_perm 에는 아무것도 주지 않는다 (SECURITY R-60).
REVOKE ALL ON p_recon_statements, p_recon_lines, p_month_close FROM tbox_perm;

COMMIT;
