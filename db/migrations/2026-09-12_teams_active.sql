-- ─────────────────────────────────────────────────────────────
-- 2026-09-12_teams_active.sql  (12단계 12-3 — F-19 조직·권한 관리, ARCHITECTURE 5.9 · 6.2-1)
--
-- 목적: teams 에 세 컬럼과 이름 길이 제약을 더한다.
--   · is_active  — 팀을 **지우지 않고** 내리는 스위치. 비활성 팀에는 새 배정·새 업로드가
--                  막히고(400 team_inactive), 이미 그 팀에 있는 파일·계정은 그대로다.
--   · created_at — 팀을 만든 시각 (조직·권한 화면의 「만든 날」).
--   · updated_at — 이름·활성 상태가 마지막으로 바뀐 시각 (「마지막 변경」).
--
-- 왜 행을 지우지 않나 (12-3 결정 2026-09-12, PRD F-19 · ARCH 5.9)
--   · `files.team_id`·`users.team_id` 가 팀을 참조하고, 감사 로그(`target_type=team`)도
--     팀 이름을 가리킨다. 행을 지우면 "어느 팀의 파일이었나" 를 되짚을 수 없다.
--   · 그래서 삭제 API 를 만들지 않고 `is_active=false` 로만 내린다 — files 의 논리 삭제
--     (`deleted_at`)·계정의 비활성화(`users.is_active`)와 같은 방식이다.
--
-- 왜 이름 길이를 DB 가 강제하나
--   앱(`org_team_create`·`org_team_update`)이 1~50자를 검사하지만, 한 경로라도 빠지면
--   500자짜리 팀 이름이 감사 로그 문장·화면에 들어간다. `files.description` 의 CHECK 와
--   같은 방식으로 DB 를 마지막 그물로 둔다 (PRD 9장 "제약 조건").
--
-- 실행: sudo -u postgres psql -d tbox_re -v ON_ERROR_STOP=1 \
--            -f db/migrations/2026-09-12_teams_active.sql
--       (새로 설치하는 DB 는 db/schema.sql 에 이미 들어 있어 이 파일이 필요 없다.)
--
-- 재실행 안전성(멱등): ADD COLUMN IF NOT EXISTS · 제약은 pg_constraint 를 먼저 보고
--   없을 때만 건다. 값을 덮어쓰는 UPDATE 가 없다(기존 팀은 전부 활성으로 남는다).
-- 권한: GRANT/REVOKE 를 하지 않는다. 컬럼 추가는 기존 표 권한에 자동으로 포함되고,
--   tbox_app 은 이미 teams 에 SELECT/INSERT/UPDATE 를 갖는다 (db/grants.sql, ARCH 10장).
--   `permissions` 표의 권한은 이 마이그레이션에서 **한 글자도 건드리지 않는다** —
--   앱 계정 SELECT-only 가 권한 상승 방어의 핵심이다 (SECURITY R-43, PRD Q-12).
-- ─────────────────────────────────────────────────────────────

\set ON_ERROR_STOP on

BEGIN;

-- [1] 컬럼 세 개. 기존 팀은 DEFAULT 로 전부 활성이 되고, 시각은 마이그레이션 시각이 된다
--     (팀을 언제 만들었는지는 기록이 없어 되살릴 수 없다 — 지어내지 않고 now() 를 쓴다).
ALTER TABLE teams ADD COLUMN IF NOT EXISTS is_active  BOOLEAN     NOT NULL DEFAULT true;
ALTER TABLE teams ADD COLUMN IF NOT EXISTS created_at TIMESTAMPTZ NOT NULL DEFAULT now();
ALTER TABLE teams ADD COLUMN IF NOT EXISTS updated_at TIMESTAMPTZ NOT NULL DEFAULT now();

-- [2] 이름 길이 1~50자 (ARCH 5.9 형식 규칙과 같은 값).
--     ADD CONSTRAINT 에는 IF NOT EXISTS 가 없으므로 카탈로그를 먼저 본다.
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
         WHERE conname = 'teams_name_len'
           AND conrelid = 'public.teams'::regclass
    ) THEN
        ALTER TABLE teams
            ADD CONSTRAINT teams_name_len CHECK (length(name) BETWEEN 1 AND 50);
    END IF;
END
$$;

COMMIT;
