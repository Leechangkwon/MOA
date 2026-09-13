-- ─────────────────────────────────────────────────────────────
-- 2026-09-14_purchase_docs.sql  (20단계 — PRD F-30 발주요청 · F-31 추천/내 리스트 ·
--                                F-32 불러오기→발주서, ARCHITECTURE 5.18 · 10장,
--                                docs/purchase/05-stage20-directive.md)
--
-- 목적: **전표**가 들어올 자리를 만든다 — 발주요청(PR)과 발주서(PO), 그 둘을 잇는 줄 연결,
--       전표 번호 채번, 그리고 「내 발주 리스트」.
--
-- ★ 재고 원장(p_stock_moves)·입고(GR)·이동·사용·실사는 여기 **없다** — 21단계다.
--   `p_docs.doc_type` 의 CHECK 도 오늘은 `pr`·`po` 둘뿐이다. 쓰이지 않는 값을 미리 열어 두면
--   "그 상태로 들어온 행을 아무도 처리하지 않는" 구멍이 된다.
--
-- 설계 요점
--   · **삭제가 없다.** 취소는 `status = 'void'` 다(19단계 기초정보의 `is_active = false` 와 같은 원칙).
--     앱 계정에는 이 표들에 DELETE 권한 자체가 없다(아래 GRANT) — 전표를 지워 추적이 끊기는
--     경로가 코드 결함으로도 만들어지지 않는다 (SECURITY R-66).
--   · **연결은 `p_doc_lines.src_line_id` 하나**다 (ARCH 5.18 「연결」). 발주서 줄 → 요청 줄,
--     21단계의 입고 줄 → 발주서 줄. 미발주 잔량은 컬럼이 아니라 **계산값**이다:
--        잔량 = 요청 줄 qty − Σ(자식 줄 qty, status <> 'void')
--     컬럼으로 두면 두 곳이 어긋나는 순간 무엇이 진실인지 알 수 없다(현재고를 합으로 두는 것과 같은 판단).
--   · **요청 수정 창**(Q-18①)의 근거는 `p_doc_lines.locked_at` 이다. 구매실이 그 줄을 발주서에
--     담는 순간(FOR UPDATE 안에서) 시각이 박히고, 그 뒤 요청자의 수정은 앱이 400 으로 막는다.
--   · **채번**은 `p_doc_seq` 행 하나를 잠가 올린다. `doc_no` 에 UNIQUE 가 있으므로 경쟁이
--     생겨도 두 전표가 같은 번호를 가질 수 없다(마지막 그물).
--   · 지점 격리(R-65)의 근거 컬럼은 `p_docs.branch_id` 다. 판정은 DB 가 아니라
--     `Authz::purchaseScopeWhere($user, 'd')` 한 곳이 만든다 — 여기서는 그 조건이 훑을 인덱스만 둔다.
--   · `p_item_branches.is_active` — 19단계 접수 사항("사용 지점 빼기 불가")의 답이다.
--     이 표에도 DELETE 가 없으므로 지점을 빼는 것은 `is_active = false` 다.
--
-- 실행: sudo -u postgres psql -d tbox_re -v ON_ERROR_STOP=1 \
--            -f db/migrations/2026-09-14_purchase_docs.sql
--
-- 재실행 안전성(멱등): CREATE TABLE/INDEX/VIEW IF NOT EXISTS · ADD COLUMN IF NOT EXISTS ·
--   제약은 pg_constraint 를 먼저 확인하고 붙인다 · GRANT.
--   **값을 바꾸는 UPDATE·DELETE 가 한 줄도 없다** (CLAUDE.md 4장 "운영 데이터에 손대지 않는다").
--
-- 권한: tbox_app  SELECT·INSERT·UPDATE (★ DELETE 없음) + 시퀀스 USAGE
--       tbox_backup SELECT 만 (PRD S-08)
--       tbox_perm  없음 (SECURITY R-60)
-- ─────────────────────────────────────────────────────────────

\set ON_ERROR_STOP on

BEGIN;

-- ─── 전표 머리 ───────────────────────────────────────────────
--   doc_type  pr = 발주요청, po = 발주서
--   status    unconfirmed 미확인 · in_progress 진행중 · partial 일부 발주 · done 완료 · void 취소
--             (PRD F-30 「상태 미확인→진행중→일부 발주→완료」)
--   header_json  발주서 머리 정보 (Q-18②). 서버가 채우고 buyer 가 허용 키만 고친다.
--   split_from_id 발주서가 만들어진 뒤 요청자가 더 적은 내역을 담는 **새 PR** 의 출처 (Q-18①)
--   sent_at·sent_by 「보냄」 (Q-18④). 한 번만이고 재송부는 사유가 필요하다
--   closed_month  월 마감 (22단계). 값이 있으면 수정·취소에 `purchase.close` 가 필요하다
CREATE TABLE IF NOT EXISTS p_docs (
    id             BIGSERIAL   PRIMARY KEY,
    doc_type       TEXT        NOT NULL CHECK (doc_type IN ('pr','po')),
    doc_no         TEXT        NOT NULL,
    doc_date       DATE        NOT NULL DEFAULT CURRENT_DATE,
    branch_id      BIGINT      NOT NULL REFERENCES p_branches(id),
    vendor_id      BIGINT      REFERENCES p_vendors(id),
    to_location_id BIGINT      REFERENCES p_locations(id),
    manager_id     BIGINT      NOT NULL REFERENCES users(id),
    status         TEXT        NOT NULL DEFAULT 'unconfirmed'
                   CHECK (status IN ('unconfirmed','in_progress','partial','done','void')),
    memo           TEXT,
    header_json    JSONB,
    split_from_id  BIGINT      REFERENCES p_docs(id),
    sent_at        TIMESTAMPTZ,
    sent_by        BIGINT      REFERENCES users(id),
    closed_month   TEXT,
    created_by     BIGINT      NOT NULL REFERENCES users(id),
    created_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT p_docs_memo_len  CHECK (memo IS NULL OR length(memo) <= 500),
    CONSTRAINT p_docs_month_fmt CHECK (closed_month IS NULL OR closed_month ~ '^[0-9]{4}-[0-9]{2}$')
);
-- 채번의 마지막 그물 — 경쟁이 생겨도 같은 번호가 두 번 들어갈 수 없다.
CREATE UNIQUE INDEX IF NOT EXISTS p_docs_doc_no_uidx ON p_docs (doc_no);
-- ★ 지점 격리(R-65)와 전표 목록이 함께 훑는 인덱스.
CREATE INDEX IF NOT EXISTS p_docs_branch_type_status_idx ON p_docs (branch_id, doc_type, status);
CREATE INDEX IF NOT EXISTS p_docs_manager_idx ON p_docs (manager_id);
CREATE INDEX IF NOT EXISTS p_docs_vendor_idx  ON p_docs (vendor_id);
CREATE INDEX IF NOT EXISTS p_docs_date_idx    ON p_docs (doc_date);

-- ─── 전표 줄 ─────────────────────────────────────────────────
--   qty         낱개 수량. 0 이나 음수는 들어올 수 없다(취소는 status='void')
--   unit_price  **서버가 p_items.price_in 에서 채운다.** 클라이언트가 보낸 단가는 읽지 않는다
--   amount      qty × unit_price — 역시 서버 계산값이다 (SECURITY R-66)
--   src_line_id 출처 줄 (발주서 줄 → 요청 줄). 미발주 잔량은 이 연결로 계산한다
--   locked_at   구매실이 이 요청 줄을 발주서에 담은 시각 (Q-18① 수정 창의 근거)
--   status      unconfirmed 미확인 · ordered 일부 담김 · done 전량 담김 · void 취소
CREATE TABLE IF NOT EXISTS p_doc_lines (
    id          BIGSERIAL   PRIMARY KEY,
    doc_id      BIGINT      NOT NULL REFERENCES p_docs(id),
    line_no     INT         NOT NULL CHECK (line_no >= 1),
    item_id     BIGINT      NOT NULL REFERENCES p_items(id),
    qty         NUMERIC(14,3) NOT NULL CHECK (qty > 0),
    unit_price  NUMERIC(14,2) CHECK (unit_price IS NULL OR unit_price >= 0),
    amount      NUMERIC(16,2) CHECK (amount IS NULL OR amount >= 0),
    remark      TEXT,
    src_line_id BIGINT      REFERENCES p_doc_lines(id),
    locked_at   TIMESTAMPTZ,
    status      TEXT        NOT NULL DEFAULT 'unconfirmed'
                CHECK (status IN ('unconfirmed','ordered','done','void')),
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT p_doc_lines_remark_len CHECK (remark IS NULL OR length(remark) <= 200),
    CONSTRAINT p_doc_lines_doc_line_uniq UNIQUE (doc_id, line_no)
);
CREATE INDEX IF NOT EXISTS p_doc_lines_doc_idx  ON p_doc_lines (doc_id);
CREATE INDEX IF NOT EXISTS p_doc_lines_src_idx  ON p_doc_lines (src_line_id);
CREATE INDEX IF NOT EXISTS p_doc_lines_item_idx ON p_doc_lines (item_id);

-- ─── 채번 ────────────────────────────────────────────────────
-- 하루·종류마다 한 행. `Doc::nextDocNo()` 가 이 행을 잠그고 1 올린다.
CREATE TABLE IF NOT EXISTS p_doc_seq (
    doc_type TEXT NOT NULL CHECK (doc_type IN ('pr','po')),
    seq_date DATE NOT NULL,
    last_no  INT  NOT NULL DEFAULT 0 CHECK (last_no >= 0),
    PRIMARY KEY (doc_type, seq_date)
);

-- ─── 내 발주 리스트 (F-31, 시안 ③ MyList) ────────────────────
--   owner_id  만든 사람. 남의 리스트는 공유된 것만 보인다 (SECURITY R-68)
--   branch_id 공유 범위. NULL 이면 공유하지 않는다는 뜻이고, is_shared 가 참일 때만 쓰인다
CREATE TABLE IF NOT EXISTS p_lists (
    id         BIGSERIAL   PRIMARY KEY,
    owner_id   BIGINT      NOT NULL REFERENCES users(id),
    branch_id  BIGINT      REFERENCES p_branches(id),
    name       TEXT        NOT NULL,
    is_shared  BOOLEAN     NOT NULL DEFAULT false,
    is_active  BOOLEAN     NOT NULL DEFAULT true,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT p_lists_name_len CHECK (length(name) BETWEEN 1 AND 60)
);
CREATE INDEX IF NOT EXISTS p_lists_owner_idx  ON p_lists (owner_id);
CREATE INDEX IF NOT EXISTS p_lists_branch_idx ON p_lists (branch_id);

CREATE TABLE IF NOT EXISTS p_list_items (
    list_id     BIGINT NOT NULL REFERENCES p_lists(id),
    item_id     BIGINT NOT NULL REFERENCES p_items(id),
    default_qty NUMERIC(14,3) NOT NULL DEFAULT 1 CHECK (default_qty > 0),
    sort_no     INT    NOT NULL DEFAULT 1 CHECK (sort_no >= 1),
    -- 「빼기」도 삭제가 아니다. 이 표에도 DELETE 권한이 없으므로 뺀 품목은 is_active = false 로
    -- 남고, 같은 품목을 다시 담으면 그 행이 되살아난다(UPSERT). 그래서 "언제 뺐다가 다시
    -- 담았나" 가 행으로 남는다.
    is_active   BOOLEAN NOT NULL DEFAULT true,
    PRIMARY KEY (list_id, item_id)
);
CREATE INDEX IF NOT EXISTS p_list_items_item_idx ON p_list_items (item_id);
ALTER TABLE p_list_items ADD COLUMN IF NOT EXISTS is_active BOOLEAN NOT NULL DEFAULT true;

-- ─── 사용 지점 빼기 (19단계 접수 사항) ───────────────────────
-- 이 표에도 DELETE 가 없으므로 "이 지점에서는 더 쓰지 않는다" 는 is_active = false 다.
ALTER TABLE p_item_branches ADD COLUMN IF NOT EXISTS is_active BOOLEAN NOT NULL DEFAULT true;

-- ─── 줄 연결 사슬 (ARCH 5.18 「연결」) ────────────────────────
-- 요청 줄 하나에서 시작해 src_line_id 를 따라 내려가는 재귀 CTE 를 뷰로 굳혀 둔다.
-- 20단계의 사이클 추적은 PR → PO 두 층만 쓰지만, 21단계 입고(GR)가 붙으면 같은 뷰가
-- 세 층을 그대로 돌려준다 — 그때 SQL 을 다시 짜지 않게 하려는 것이다.
-- 뷰에는 **권한 조건이 없다.** 지점 격리는 이 뷰를 쓰는 SQL 이 `p_docs` 를 조인해
-- `Authz::purchaseScopeWhere` 로 건다 (판정은 언제나 Authz 한 곳 — ARCH 4.1).
CREATE OR REPLACE VIEW p_line_chain AS
WITH RECURSIVE chain AS (
    SELECT l.id AS root_id, l.id, l.src_line_id, l.doc_id, 0 AS depth
      FROM p_doc_lines l
     WHERE l.src_line_id IS NULL
    UNION ALL
    SELECT c.root_id, l.id, l.src_line_id, l.doc_id, c.depth + 1
      FROM p_doc_lines l
      JOIN chain c ON l.src_line_id = c.id
)
SELECT root_id, id AS line_id, src_line_id, doc_id, depth FROM chain;

-- ─── 권한 (ARCH 10장 · db/grants.sql 과 같은 내용) ───────────
-- ★ DELETE 가 어디에도 없다 — 취소는 status = 'void' 다.
REVOKE ALL ON p_docs, p_doc_lines, p_doc_seq, p_lists, p_list_items, p_line_chain FROM tbox_app;
GRANT SELECT, INSERT, UPDATE ON p_docs       TO tbox_app;
GRANT SELECT, INSERT, UPDATE ON p_doc_lines  TO tbox_app;
GRANT SELECT, INSERT, UPDATE ON p_doc_seq    TO tbox_app;
GRANT SELECT, INSERT, UPDATE ON p_lists      TO tbox_app;
GRANT SELECT, INSERT, UPDATE ON p_list_items TO tbox_app;
GRANT SELECT                 ON p_line_chain TO tbox_app;

GRANT USAGE, SELECT ON SEQUENCE p_docs_id_seq      TO tbox_app;
GRANT USAGE, SELECT ON SEQUENCE p_doc_lines_id_seq TO tbox_app;
GRANT USAGE, SELECT ON SEQUENCE p_lists_id_seq     TO tbox_app;
-- p_doc_seq 는 (doc_type, seq_date) 복합 PK, p_list_items 는 (list_id, item_id) 복합 PK 라
-- 시퀀스가 없다.

-- 백업 계정은 다른 표와 같게 읽기만 (PRD S-08)
GRANT SELECT ON p_docs, p_doc_lines, p_doc_seq, p_lists, p_list_items, p_line_chain TO tbox_backup;
GRANT SELECT ON ALL SEQUENCES IN SCHEMA public TO tbox_backup;

-- tbox_perm 에는 아무것도 주지 않는다 — 그 롤은 permissions 표 하나만 본다 (SECURITY R-60).
REVOKE ALL ON p_docs, p_doc_lines, p_doc_seq, p_lists, p_list_items, p_line_chain FROM tbox_perm;

COMMIT;
