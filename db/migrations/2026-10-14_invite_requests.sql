-- db/migrations/2026-10-14_invite_requests.sql — 초대 요청 (사이트 H2 · 결정 IR-1 ~ IR-4)
--
-- 요구사항: ../../docs/invite-request-plan.md 3장(보안 바닥 — 표 · 창구 · 감사 · 보관) · 지시서 docs/invite-request-directive.md.
--
-- 추가 전용 · 되풀이 안전(CREATE … IF NOT EXISTS · 제약은 표와 함께 · REVOKE 뒤 GRANT). 기존 행을 지우거나 고치지 않는다.
-- 실행 계정: tbox_owner (표 소유자 — 2026-10-14_community_cafe.sql 과 같다). 슈퍼유저로 돌리면 아래 3 절이 소유자를 고정한다.
-- 새 설치는 db/schema.sql 끝 「초대 요청」 절이 같은 모양을 만들고, 앱 권한은 db/grants.sql 끝 절이 다시 건다.
--
--   public.invite_requests   로그인 전 공개 폼으로 남긴 초대 요청 — 이름 · 소속 · 이유 · 접속 IP · 상태 · 결정 · 발급
--     status_hash            요청 확인 코드(32바이트 난수)의 sha256 만 — 원문은 응답에 한 번(주소 `#` 뒤)뿐 · 저장 · 로그 · 감사 0
--     status                 open → approved | rejected (한 번만 — 앱은 `WHERE status='open'` 으로만 바꾼다)
--     invite_id · issued_count  승인된 요청의 확인 주소가 열릴 때 만든 초대(member_invites — 1회용 · 7일) · 발급 횟수 0~3
--   앱 계정 tbox_app: SELECT · INSERT(name · org · reason · status_hash · ip 다섯 칸 — 상태 · 시각은 기본값만) ·
--                     UPDATE(상태 · 결정 · 발급 칸: status · team_id · decided_by · decision_note · decided_at · invite_id · issued_count) ·
--                     시퀀스 USAGE · SELECT — ★ DELETE 없음(보관 기한 삭제는 scripts/privacy_purge.php 가 표 소유자로)
--   백업 계정 tbox_backup: SELECT 만 · tbox_perm · tbox_push 0
--
-- 보관(처리방침 2026-10-14 판 제3조): 결정한 요청은 결정 30일 뒤 · 결정하지 않은 요청은 접수 30일 뒤 행 삭제 —
--   scripts/privacy_purge.php --run (cron 04:40 · 소유자 pgpass) 의 「초대 요청」 갈래. 이 마이그레이션은 행을 지우지 않는다.
--
-- 잠금: 새 표 하나 · 인덱스 넷(빈 표라 즉시). FK 셋(teams · users · member_invites)이 그 표들에 짧은 SHARE ROW EXCLUSIVE — `lock_timeout 5s` 로 긴 줄 뒤에 서지 않는다.
--
-- 실행: sudo -u postgres psql -d tbox_re -v ON_ERROR_STOP=1 -f db/migrations/2026-10-14_invite_requests.sql
--
-- 확인:
--   \d invite_requests                       → CHECK(이름 · 소속 · 이유 · 해시 · 메모 길이 · 상태 · 발급 수 · 모양 invite_requests_shape) · UNIQUE(status_hash) · 인덱스 넷
--   \dp invite_requests                      → tbox_app=r + a(다섯 칸) + w(일곱 칸) · tbox_backup=r · DELETE 0
--   SELECT has_table_privilege('tbox_app','public.invite_requests','DELETE');   → f
--
-- 되돌리기(코드를 먼저 되돌린 뒤 — 요청 기록이 사라지므로 지우기 전에 사용자 판단):
--   DROP TABLE public.invite_requests;
--   (처리방침 2026-10-14 판은 config/legal.json 의 끝 줄 — 판은 지우지 않는다 · 되돌릴 때도 새 판을 더하는 방식)

\set ON_ERROR_STOP on
BEGIN;
SET LOCAL lock_timeout = '5s';

-- ── 1. 표 (기획 3장 「표」) ──
CREATE TABLE IF NOT EXISTS public.invite_requests (
    id            BIGSERIAL   PRIMARY KEY,
    name          TEXT        NOT NULL CHECK (char_length(name) BETWEEN 1 AND 40),
    org           TEXT        NOT NULL CHECK (char_length(org) BETWEEN 1 AND 80),
    reason        TEXT        NOT NULL CHECK (char_length(reason) BETWEEN 1 AND 500),
    status_hash   TEXT        NOT NULL UNIQUE CHECK (status_hash ~ '^[0-9a-f]{64}$'),
    ip            INET,
    status        TEXT        NOT NULL DEFAULT 'open' CHECK (status IN ('open', 'approved', 'rejected')),
    team_id       BIGINT      REFERENCES public.teams(id),
    decided_by    BIGINT      REFERENCES public.users(id),
    decision_note TEXT        CHECK (decision_note IS NULL OR char_length(decision_note) BETWEEN 1 AND 300),
    invite_id     BIGINT      REFERENCES public.member_invites(id) ON DELETE SET NULL,
    issued_count  INTEGER     NOT NULL DEFAULT 0 CHECK (issued_count BETWEEN 0 AND 3),
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    decided_at    TIMESTAMPTZ,
    CONSTRAINT invite_requests_shape CHECK (
           (status = 'open'     AND decided_by IS NULL     AND decided_at IS NULL     AND team_id IS NULL
                                AND decision_note IS NULL  AND invite_id IS NULL      AND issued_count = 0)
        OR (status = 'rejected' AND decided_by IS NOT NULL AND decided_at IS NOT NULL AND team_id IS NULL
                                AND invite_id IS NULL      AND issued_count = 0)
        OR (status = 'approved' AND decided_by IS NOT NULL AND decided_at IS NOT NULL))
);
-- 관리자 목록(열린 것 먼저 · 새것 먼저) · 열린 요청 수(200 상한)
CREATE INDEX IF NOT EXISTS invite_requests_status_idx  ON public.invite_requests (status, id DESC);
-- IP 마다 1시간 3건 · 24시간 5건
CREATE INDEX IF NOT EXISTS invite_requests_ip_idx      ON public.invite_requests (ip, created_at);
-- 보관 기한(결정 30일 · 결정 없으면 접수 30일)
CREATE INDEX IF NOT EXISTS invite_requests_created_idx ON public.invite_requests (created_at) WHERE decided_at IS NULL;
CREATE INDEX IF NOT EXISTS invite_requests_decided_idx ON public.invite_requests (decided_at) WHERE decided_at IS NOT NULL;

-- ── 2. 권한 (db/grants.sql 끝 절과 같은 줄) ──
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'tbox_app') THEN
        REVOKE ALL ON public.invite_requests FROM tbox_app;
        REVOKE ALL ON SEQUENCE public.invite_requests_id_seq FROM tbox_app;
        GRANT SELECT ON public.invite_requests TO tbox_app;
        GRANT INSERT (name, org, reason, status_hash, ip) ON public.invite_requests TO tbox_app;
        GRANT UPDATE (status, team_id, decided_by, decision_note, decided_at, invite_id, issued_count) ON public.invite_requests TO tbox_app;
        GRANT USAGE, SELECT ON SEQUENCE public.invite_requests_id_seq TO tbox_app;
    END IF;
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'tbox_backup') THEN
        REVOKE ALL ON public.invite_requests FROM tbox_backup;
        REVOKE ALL ON SEQUENCE public.invite_requests_id_seq FROM tbox_backup;
        GRANT SELECT ON public.invite_requests TO tbox_backup;
        GRANT SELECT ON SEQUENCE public.invite_requests_id_seq TO tbox_backup;
    END IF;
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'tbox_perm') THEN
        EXECUTE 'REVOKE ALL ON public.invite_requests FROM tbox_perm';
    END IF;
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'tbox_push') THEN
        EXECUTE 'REVOKE ALL ON public.invite_requests FROM tbox_push';
    END IF;
END
$$;

-- ── 3. 소유자 — 슈퍼유저로 돌렸을 때만(tbox_owner 로 돌리면 이미 소유자다). 합성 시험 DB(소유자 tbox_testdb)에서는 건너뛴다 ──
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'tbox_owner')
       AND (SELECT rolsuper FROM pg_roles WHERE rolname = current_user) THEN
        ALTER TABLE public.invite_requests OWNER TO tbox_owner;
    END IF;
END
$$;

COMMIT;
