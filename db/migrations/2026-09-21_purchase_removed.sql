-- ─────────────────────────────────────────────────────────────
-- 2026-09-21_purchase_removed.sql  (F-64 — 구매·재고 모듈을 t-box 에서 떼어낸다,
--                                   PRD F-64 · Q-22 · T-76, ARCHITECTURE 5.18(묘비),
--                                   SECURITY R-93, docs/tbox/f64-purchase-removal-directive.md)
--
-- 목적: 19~25단계에 붙었던 모듈의 표 19개·뷰 3개·시퀀스 16개와, 본체 두 표에 얹혀 있던
--       네 칸(`users.branch_id`·`users.purchase_role`·`sessions.act_branch_id`·
--       `sessions.act_purchase_role`)을 내린다. 코드 쪽은 같은 날 저장소에서 지웠으므로,
--       이 파일을 돌리기 전까지는 **쓰지 않는 표·열이 DB 에만 남아 있는 상태**다
--       (SECURITY R-93 ◐ — 이 파일을 돌리고 `\dt p_*` 가 0 이면 ✅).
--
-- ★ 되돌릴 수 없다. 돌리기 전에 백업이 있는지 확인한다:
--     · git 태그 `v1.13.0-with-purchase` (소스 원본·설계 문서)
--     · `~/tbox-v2-purchase-backup-2026-09-21.zip`
--       (git bundle · `p_*` 덤프 19표·3뷰·16시퀀스 · users 두 열 CSV ·
--        `purchase.*` 감사 5,931행 CSV) sha256 `2e56ce19…7fb01`
--
-- ★ `audit_logs` 의 `purchase.*` 행은 **지우지 않는다** (R-93 ②). 해시 체인이 끊기고,
--   요약 문장은 기록 시점에 `summary` 열로 저장되므로 문장 표를 지워도 표시는 그대로다.
--
-- ★ `files` 에 남은 구매 업로드 원본(admin-only 폴더)도 이 파일이 손대지 않는다 (R-93 ③).
--
-- 실행 (사용자가 한다 — CLAUDE.md 4장 「구현자는 운영 데이터에 손대지 않는다」)
--   sudo -u postgres psql -d tbox_re -v ON_ERROR_STOP=1 -f db/migrations/2026-09-21_purchase_removed.sql
--
-- 확인
--   sudo -u postgres psql -d tbox_re -c '\dt p_*'        → 0 rows
--   sudo -u postgres psql -d tbox_re -c '\d users'       → branch_id·purchase_role 없음
--   sudo -u postgres psql -d tbox_re -c '\d sessions'    → act_branch_id·act_purchase_role 없음
--
-- 순서: 뷰 → 표 → 본체 두 표의 열. 표는 참조하는 쪽부터 적었고, 서로 참조하는 짝
--       (p_branches ↔ p_locations · p_docs 자기참조 · p_doc_lines 자기참조 ·
--        p_stock_moves 자기참조 · users.branch_id → p_branches)이 있어 CASCADE 를 붙인다.
--       시퀀스 16개는 BIGSERIAL 이 소유한 것이라 표와 함께 사라진다.
-- ─────────────────────────────────────────────────────────────

BEGIN;

DROP VIEW IF EXISTS p_backorder, p_stock_balance, p_line_chain;

DROP TABLE IF EXISTS
    p_recon_lines,
    p_recon_statements,
    p_month_close,
    p_stock_moves,
    p_list_items,
    p_lists,
    p_doc_lines,
    p_docs,
    p_doc_seq,
    p_location_inbox,
    p_import_mappings,
    p_par_levels,
    p_item_branches,
    p_items,
    p_categories,
    p_vendors,
    p_locations,
    p_branches,
    p_brands
    CASCADE;

ALTER TABLE users
    DROP COLUMN IF EXISTS branch_id,
    DROP COLUMN IF EXISTS purchase_role;

ALTER TABLE sessions
    DROP COLUMN IF EXISTS act_branch_id,
    DROP COLUMN IF EXISTS act_purchase_role;

COMMIT;
