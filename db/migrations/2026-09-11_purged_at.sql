-- ─────────────────────────────────────────────────────────────
-- 2026-09-11_purged_at.sql
--
-- 목적: files 에 `purged_at` 컬럼을 추가한다 — 30일 지난 휴지통 파일의 **실제 바이트**를
--       배치가 지운 시각이다 (PRD 7.8 S-08 "논리 삭제 안전망", ARCHITECTURE 11.1 04:00).
--
-- 왜 행을 지우지 않고 컬럼을 더하나 (9단계 결정 2026-09-11)
--   · 감사 로그(audit_logs.target_id)와 파일 행이 1:1 로 이어져 있다. 행을 지우면
--     "누가 언제 무엇을 내려받았나" 를 나중에 되짚을 때 파일 이름조차 남지 않는다.
--   · `stored_name` 을 남겨 두어야 저장소에 남은 고아 파일을 나중에 대조할 수 있다
--     (scripts/consistency_check.php 의 "고아" 판정).
--   · 앱 계정 tbox_app 은 files 에 UPDATE 권한이 있으므로 배치를 앱 계정으로 돌릴 수 있다
--     (DELETE 권한은 어디에도 주지 않는다 — ARCH 10장).
--
-- 실행: sudo -u postgres psql -d tbox_re -v ON_ERROR_STOP=1 -f db/migrations/2026-09-11_purged_at.sql
--       (새로 설치하는 DB 는 db/schema.sql 에 이미 컬럼이 있어 이 파일이 필요 없다.)
--
-- 재실행 안전성: ADD COLUMN IF NOT EXISTS · CREATE INDEX IF NOT EXISTS 는 멱등이다.
-- 권한: GRANT/REVOKE 를 하지 않는다. 컬럼 추가는 기존 표 권한에 자동으로 포함된다.
-- ─────────────────────────────────────────────────────────────

\set ON_ERROR_STOP on

BEGIN;

-- [1] 컬럼 — NULL 이면 "실제 바이트가 아직 저장소에 있다" 는 뜻이다.
ALTER TABLE files ADD COLUMN IF NOT EXISTS purged_at TIMESTAMPTZ;

-- [2] 배치가 매일 찾는 조건(삭제됐고·아직 안 지웠고·30일 지난 행)만 훑게 하는 부분 인덱스.
CREATE INDEX IF NOT EXISTS files_purge_idx
    ON files (deleted_at)
    WHERE deleted_at IS NOT NULL AND purged_at IS NULL;

COMMIT;
