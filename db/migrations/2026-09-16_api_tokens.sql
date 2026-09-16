-- ─────────────────────────────────────────────────────────────
-- 2026-09-16_api_tokens.sql  (F-59 — 사용자별 프로그램 토큰(설계 B),
--                              PRD F-59 ① · 8장 `/api/users/{uid}/api-tokens` 세 행,
--                              ARCHITECTURE 4.6 · 6.2-5, SECURITY R-82 · R-91,
--                              docs/f59-personal-tokens-directive.md)
--
-- 목적: 프로그램용 토큰을 「서버에 하나뿐인 공용 값(.env)」에서 **계정마다 발급하는 개인
--       토큰**으로 옮긴다. 지우기를 프로그램에 열려면 「누가 지웠나」가 사람 단위로 남아야
--       하기 때문이다(F-58 검토 결론 — R-82 의 핵심 결손이 귀속·회수의 부재였다).
--
-- ★ 왜 원문을 저장하지 않나
--   `sessions`·`share_links` 와 같은 방식이다. 토큰 원문은 발급 응답에 **한 번** 나가고
--   서버에는 sha256 만 남는다. DB 가 통째로 새어도 그 값으로 요청을 만들 수 없다.
--   화면에서 어느 토큰인지 알아보라고 앞 6자(`hint`)만 따로 둔다 — 48자 중 6자이고
--   남은 42자의 무작위성으로 추측이 성립하지 않는다(ARCH 4.6).
--
-- ★ 왜 DELETE 가 없나
--   회수는 `revoked_at`·`revoked_by` UPDATE 이고 행은 지우지 않는다. 「그 토큰이 언제부터
--   언제까지 살아 있었나」 가 조사에 필요하기 때문이다(share_links·fetch_hosts 와 같은 원칙).
--   앱 계정에 DELETE 자격 자체를 주지 않아 지우는 길을 없앤다.
--
-- ★ 왜 5개 상한이 DB 에 없나
--   「살아 있는(revoked_at IS NULL) 토큰이 계정당 5개」 는 앱(api_tokens_issue.php)이 본다.
--   조건이 다른 행들의 상태에 달려 있어 CHECK 로 표현되지 않는다(ARCH 6.2-5).
--
-- 열
--   user_id      토큰의 주인. 이 계정의 권한으로 요청이 판정된다(요청이 정하지 않는다)
--   name         사람이 붙이는 라벨 「사무실 PC」 — 1~50자
--   token_hash   48자 토큰의 sha256 hex. **원문은 저장하지 않는다**
--   hint         토큰 앞 6자 — 화면에서 어느 토큰인지 알아보는 용도
--   created_by   발급한 관리자 (users.id)
--   last_used_at 마지막으로 이 토큰이 통한 시각 (60초에 한 번만 갱신 — ARCH 4.6)
--   revoked_at   회수 시각. NULL 이 곧 「살아 있음」이고 조회 조건이다
--   revoked_by   회수한 관리자
--   reason_text  발급 사유 (PRD S-07 관리자 행동 사유). 토큰 값은 여기에도 들어가지 않는다
--
-- 권한: tbox_app  SELECT · INSERT · UPDATE (★ DELETE 없음)
--       tbox_backup SELECT 만 (PRD S-08) · tbox_perm 없음 (SECURITY R-60)
--
-- 실행: sudo -u postgres psql -d tbox_re -v ON_ERROR_STOP=1 \
--            -f db/migrations/2026-09-16_api_tokens.sql
--       (새로 설치하는 DB 는 db/schema.sql 에 이미 들어 있어 이 파일이 필요 없다.)
--
-- 재실행 안전성(멱등): CREATE TABLE/INDEX IF NOT EXISTS · GRANT 뿐이다.
--   기존 행을 고치는 UPDATE 도, DELETE·TRUNCATE 도 한 줄이 없다
--   (CLAUDE.md 4장 "운영 데이터에 손대지 않는다").
-- ─────────────────────────────────────────────────────────────

\set ON_ERROR_STOP on

BEGIN;

CREATE TABLE IF NOT EXISTS api_tokens (
    id           BIGSERIAL   PRIMARY KEY,
    user_id      BIGINT      NOT NULL REFERENCES users(id),
    name         TEXT        NOT NULL CHECK (length(name) BETWEEN 1 AND 50),   -- 라벨 「사무실 PC」
    token_hash   CHAR(64)    NOT NULL UNIQUE,   -- 48자 토큰의 sha256. 원문은 저장하지 않음
    hint         CHAR(6)     NOT NULL,          -- 토큰 앞 6자 — 화면에서 어느 토큰인지 알아보는 용도
    created_by   BIGINT      NOT NULL REFERENCES users(id),
    created_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
    last_used_at TIMESTAMPTZ,
    revoked_at   TIMESTAMPTZ,
    revoked_by   BIGINT      REFERENCES users(id),
    reason_text  TEXT        CHECK (reason_text IS NULL OR length(reason_text) <= 500)
);

-- token_hash 의 UNIQUE 가 조회 인덱스를 겸한다
-- (`WHERE token_hash = … AND revoked_at IS NULL` 이 유일한 조회 경로 — ARCH 6.2-5).
CREATE INDEX IF NOT EXISTS api_tokens_user_idx ON api_tokens (user_id);

COMMIT;

-- ─── 권한 (db/grants.sql 과 같은 내용) ───
REVOKE ALL ON api_tokens FROM tbox_app;
GRANT SELECT, INSERT, UPDATE ON api_tokens TO tbox_app;   -- ★ DELETE 없음 (회수는 revoked_at)
GRANT USAGE, SELECT ON SEQUENCE api_tokens_id_seq TO tbox_app;
GRANT SELECT ON api_tokens TO tbox_backup;
GRANT SELECT ON SEQUENCE api_tokens_id_seq TO tbox_backup;
