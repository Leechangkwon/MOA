-- ─────────────────────────────────────────────────────────────
-- 2026-09-14_purchase_stock.sql  (21단계 — PRD F-33 입고·이동·사용·실사 ·
--                                 F-34 재고현황·수불부·미입고, ARCHITECTURE 5.18,
--                                 docs/purchase/06-stage21-directive.md)
--
-- 목적: **재고가 움직이는 자리**를 만든다 — 입고(GR)·이동(MV)·사용(IS)·실사(ADJ) 전표와,
--       그 전표가 남기는 **재고 원장** `p_stock_moves`.
--
-- ★ 이 파일이 지키는 것 하나: **원장은 추가만 한다.**
--   `tbox_app` 에 `p_stock_moves` 의 INSERT·SELECT 만 준다 — UPDATE·DELETE 권한이
--   아예 없다. 코드에 결함이 생겨도 이미 적힌 수량 변화를 고치거나 지울 수 없다
--   (감사 로그 `audit_logs` 와 같은 급의 보호다 — SECURITY R-66, ARCH 5.18 「원장」).
--   취소·정정은 **반대 부호 행 + `reverses_move_id`** 다.
--
-- ★ 현재고는 **컬럼이 아니다.** 뷰 `p_stock_balance` 의 `SUM(qty_delta)` 가 진실이다.
--   컬럼으로 두면 원장과 컬럼이 어긋나는 순간 무엇이 맞는지 알 수 없다
--   (20단계에서 잔량을 컬럼으로 두지 않은 것과 같은 판단).
--
-- 설계 요점
--   · `p_docs.doc_type` 에 `gr`·`mv`·`is`·`adj` 를 연다. 20단계에 미리 열어 두지 않은 이유가
--     "쓰이지 않는 값을 열면 그 상태의 행을 아무도 처리하지 않는 구멍이 된다" 였으므로,
--     처리하는 코드(`src/purchase/Stock.php`)가 생긴 오늘에야 연다. `p_doc_seq` 도 같다.
--   · `p_docs.from_location_id` — 이동(MV)·사용(IS)·실사(ADJ)의 **보내는 쪽**. 받는 쪽은
--     20단계의 `to_location_id` 를 그대로 쓴다.
--   · `p_docs.count_cycle` — 실사 주기 `daily|weekly|monthly` (Q-18③). 실사가 아닌 전표는 NULL.
--   · `p_doc_lines.lot_no`·`expiry_date` — 입고 줄의 로트·유효기간 (시안 GR).
--   · `p_doc_lines.counted_qty` — 실사 줄의 **실제 센 수량**. `qty` 는 실사 시작 시점의
--     **장부 스냅샷**이고, 원장에 남는 것은 둘의 차이뿐이다.
--   · 실사 스냅샷은 0 일 수 있다(장부에 없던 것을 세어 찾는 경우). 그래서 줄 수량의
--     `qty > 0` 제약을 **실사 줄에만** 풀어 준다: `qty >= 0` + `qty > 0 OR counted_qty IS NOT NULL`.
--     요청·발주·입고·이동·사용 줄에는 `counted_qty` 가 없으므로 규칙이 그대로 남는다.
--   · 뷰 `p_backorder` — 미입고. 「보낸」 발주서 줄의 수량 − 그 줄을 출처로 하는 입고 줄의 합.
--     여기에도 **권한 조건이 없다**. 지점 격리는 이 뷰를 쓰는 SQL 이 `p_docs` 를 조인해
--     `Authz::purchaseScopeWhere` 로 건다 (판정은 언제나 Authz 한 곳 — ARCH 4.1).
--
-- 실행: sudo -u postgres psql -d tbox_re -v ON_ERROR_STOP=1 \
--            -f db/migrations/2026-09-14_purchase_stock.sql
--
-- 재실행 안전성(멱등): CREATE TABLE/INDEX IF NOT EXISTS · ADD COLUMN IF NOT EXISTS ·
--   제약은 DROP CONSTRAINT IF EXISTS 뒤 다시 붙인다 · CREATE OR REPLACE VIEW · GRANT.
--   **행 값을 바꾸는 UPDATE·DELETE 가 한 줄도 없다** (CLAUDE.md 4장 "운영 데이터에 손대지 않는다").
--
-- 권한: tbox_app    p_stock_moves **SELECT·INSERT 만** (★ UPDATE·DELETE 없음)
--                   뷰 두 개 SELECT
--       tbox_backup SELECT 만 (PRD S-08)
--       tbox_perm   없음 (SECURITY R-60)
-- ─────────────────────────────────────────────────────────────

\set ON_ERROR_STOP on

BEGIN;

-- ─── 전표 종류를 넓힌다 (20단계 pr·po → 21단계 + gr·mv·is·adj) ───
ALTER TABLE p_docs    DROP CONSTRAINT IF EXISTS p_docs_doc_type_check;
ALTER TABLE p_docs    ADD  CONSTRAINT p_docs_doc_type_check
      CHECK (doc_type IN ('pr','po','gr','mv','is','adj'));

ALTER TABLE p_doc_seq DROP CONSTRAINT IF EXISTS p_doc_seq_doc_type_check;
ALTER TABLE p_doc_seq ADD  CONSTRAINT p_doc_seq_doc_type_check
      CHECK (doc_type IN ('pr','po','gr','mv','is','adj'));

-- ─── 전표 머리에 붙는 칸 ─────────────────────────────────────
--   from_location_id  이동·사용·실사의 **보내는(세는) 장소**
--   count_cycle       실사 주기 (Q-18③). 실사 전표만 값이 있다
ALTER TABLE p_docs ADD COLUMN IF NOT EXISTS from_location_id BIGINT REFERENCES p_locations(id);
ALTER TABLE p_docs ADD COLUMN IF NOT EXISTS count_cycle      TEXT;

ALTER TABLE p_docs DROP CONSTRAINT IF EXISTS p_docs_count_cycle_check;
ALTER TABLE p_docs ADD  CONSTRAINT p_docs_count_cycle_check
      CHECK (count_cycle IS NULL OR count_cycle IN ('daily','weekly','monthly'));

CREATE INDEX IF NOT EXISTS p_docs_from_loc_idx ON p_docs (from_location_id);
CREATE INDEX IF NOT EXISTS p_docs_to_loc_idx   ON p_docs (to_location_id);

-- ─── 전표 줄에 붙는 칸 ───────────────────────────────────────
--   lot_no·expiry_date  입고 줄의 로트·유효기간 (시안 GR)
--   counted_qty         실사 줄의 실제 센 수량. qty(장부 스냅샷)와의 차이만 원장에 간다
ALTER TABLE p_doc_lines ADD COLUMN IF NOT EXISTS lot_no      TEXT;
ALTER TABLE p_doc_lines ADD COLUMN IF NOT EXISTS expiry_date DATE;
ALTER TABLE p_doc_lines ADD COLUMN IF NOT EXISTS counted_qty NUMERIC(14,3);

ALTER TABLE p_doc_lines DROP CONSTRAINT IF EXISTS p_doc_lines_lot_len;
ALTER TABLE p_doc_lines ADD  CONSTRAINT p_doc_lines_lot_len
      CHECK (lot_no IS NULL OR length(lot_no) BETWEEN 1 AND 60);

ALTER TABLE p_doc_lines DROP CONSTRAINT IF EXISTS p_doc_lines_counted_check;
ALTER TABLE p_doc_lines ADD  CONSTRAINT p_doc_lines_counted_check
      CHECK (counted_qty IS NULL OR counted_qty >= 0);

-- 실사 스냅샷은 0 일 수 있다. 그 한 가지 경우에만 `qty > 0` 을 푼다.
ALTER TABLE p_doc_lines DROP CONSTRAINT IF EXISTS p_doc_lines_qty_check;
ALTER TABLE p_doc_lines DROP CONSTRAINT IF EXISTS p_doc_lines_qty_nonneg;
ALTER TABLE p_doc_lines ADD  CONSTRAINT p_doc_lines_qty_nonneg CHECK (qty >= 0);
ALTER TABLE p_doc_lines DROP CONSTRAINT IF EXISTS p_doc_lines_qty_positive;
ALTER TABLE p_doc_lines ADD  CONSTRAINT p_doc_lines_qty_positive
      CHECK (qty > 0 OR counted_qty IS NOT NULL);

-- ─── 재고 원장 — append-only (ARCH 5.18 「원장」, SECURITY R-66) ───
--   qty_delta        + 는 그 장소에 들어온 것, − 는 나간 것. **0 은 들어올 수 없다**
--                    (변화가 없는 행은 기록이 아니라 잡음이다)
--   doc_line_id      어느 전표 줄이 만든 변화인가. 원장 한 행은 반드시 전표 줄에 매달린다 —
--                    "근거 없는 재고 변화" 가 생길 자리를 없앤다
--   reverses_move_id 취소·정정이 되돌리는 **원본 행**. 값이 있으면 이 행은 반대 부호다
--   created_by       누가. 전표의 created_by 와 같지만, 원장만 읽어도 육하원칙이 서게 둔다
CREATE TABLE IF NOT EXISTS p_stock_moves (
    id               BIGSERIAL   PRIMARY KEY,
    doc_line_id      BIGINT      NOT NULL REFERENCES p_doc_lines(id),
    location_id      BIGINT      NOT NULL REFERENCES p_locations(id),
    item_id          BIGINT      NOT NULL REFERENCES p_items(id),
    qty_delta        NUMERIC(14,3) NOT NULL CHECK (qty_delta <> 0),
    moved_at         TIMESTAMPTZ NOT NULL DEFAULT now(),
    reverses_move_id BIGINT      REFERENCES p_stock_moves(id),
    created_by       BIGINT      NOT NULL REFERENCES users(id)
);
-- 현재고(뷰)와 재고현황이 훑는 인덱스
CREATE INDEX IF NOT EXISTS p_stock_moves_loc_item_idx ON p_stock_moves (location_id, item_id);
-- 전표 취소가 "이 전표가 남긴 행" 을 찾을 때
CREATE INDEX IF NOT EXISTS p_stock_moves_line_idx     ON p_stock_moves (doc_line_id);
-- 수불부·주평균 사용이 훑을 때
CREATE INDEX IF NOT EXISTS p_stock_moves_item_time_idx ON p_stock_moves (item_id, moved_at);
-- 같은 행을 두 번 되돌리지 못하게 (마지막 그물 — 앱도 막지만 DB 가 다시 막는다)
CREATE UNIQUE INDEX IF NOT EXISTS p_stock_moves_reverse_uidx
    ON p_stock_moves (reverses_move_id) WHERE reverses_move_id IS NOT NULL;

-- ─── 현재고 = 원장의 합 (ARCH 5.18 「원장」, 불변 규칙 2) ───
CREATE OR REPLACE VIEW p_stock_balance AS
SELECT location_id, item_id, sum(qty_delta) AS qty
  FROM p_stock_moves
 GROUP BY location_id, item_id;

-- ─── 미입고 = 「보낸」 발주서 줄 − 그 줄을 출처로 하는 입고 줄 (F-34 R6) ───
-- 취소된 전표·줄은 세지 않는다 — 발주서를 취소하면 미입고에서 사라진다.
CREATE OR REPLACE VIEW p_backorder AS
SELECT l.id           AS po_line_id,
       l.doc_id       AS po_doc_id,
       d.doc_no       AS po_doc_no,
       d.doc_date     AS po_date,
       d.sent_at      AS sent_at,
       d.branch_id    AS branch_id,
       d.vendor_id    AS vendor_id,
       d.to_location_id AS to_location_id,
       d.manager_id   AS manager_id,
       l.item_id      AS item_id,
       l.qty          AS ordered_qty,
       COALESCE(g.received, 0)          AS received_qty,
       l.qty - COALESCE(g.received, 0)  AS remaining_qty,
       l.unit_price   AS unit_price
  FROM p_doc_lines l
  JOIN p_docs      d ON d.id = l.doc_id
  LEFT JOIN LATERAL (
        SELECT sum(c.qty) AS received
          FROM p_doc_lines c
          JOIN p_docs      cd ON cd.id = c.doc_id
         WHERE c.src_line_id = l.id
           AND cd.doc_type = 'gr'
           AND c.status  <> 'void'
           AND cd.status <> 'void'
       ) g ON true
 WHERE d.doc_type = 'po'
   AND d.status  <> 'void'
   AND l.status  <> 'void'
   AND d.sent_at IS NOT NULL
   AND l.qty - COALESCE(g.received, 0) > 0;

-- ─── 권한 (ARCH 10장 · db/grants.sql 과 같은 내용) ───────────
-- ★ p_stock_moves 는 **INSERT·SELECT 만**이다. 원장을 고치거나 지우는 길이 없다.
REVOKE ALL ON p_stock_moves, p_stock_balance, p_backorder FROM tbox_app;
GRANT SELECT, INSERT ON p_stock_moves   TO tbox_app;
GRANT SELECT           ON p_stock_balance TO tbox_app;
GRANT SELECT           ON p_backorder     TO tbox_app;
GRANT USAGE, SELECT ON SEQUENCE p_stock_moves_id_seq TO tbox_app;

-- 백업 계정은 다른 표와 같게 읽기만 (PRD S-08)
GRANT SELECT ON p_stock_moves, p_stock_balance, p_backorder TO tbox_backup;
GRANT SELECT ON ALL SEQUENCES IN SCHEMA public TO tbox_backup;

-- tbox_perm 에는 아무것도 주지 않는다 (SECURITY R-60).
REVOKE ALL ON p_stock_moves, p_stock_balance, p_backorder FROM tbox_perm;

COMMIT;
