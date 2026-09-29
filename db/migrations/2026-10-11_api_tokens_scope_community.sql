-- ─────────────────────────────────────────────────────────────
-- 2026-10-11_api_tokens_scope_community.sql  (Q:F-8 — AI 글쓰기: 개인 토큰 범위 `community.write`,
--                              사이트 PRD 10장 Q:F-8 · t-box PRD F-74 · ARCHITECTURE 4.8 · 6.2-5,
--                              SECURITY R-112, docs/qf8-ai-writer-directive.md 조각 S)
--
-- 목적: `api_tokens.scope` 의 CHECK 에 값 **하나**(`community.write`)를 더한다. 그 밖은 바꾸지 않는다.
--       `community.write` 는 F-66 사다리(read → read+upload → read+upload+delete)의 넷째 칸이 아니라
--       **독립 값**이다 — 파일 창구를 하나도 열지 않고, 커뮤니티 창구 셋(내 공간 번호 · 페이지 만들기 ·
--       페이지 읽기)만 연다(`Authz::tokenScopeAllows`).
--
-- 방법: 이름이 같은 제약 `api_tokens_scope_check` 를 지우고 네 값으로 다시 건다 — 한 트랜잭션.
--       기존 행은 전부 세 값 중 하나이므로 새 CHECK 를 그대로 통과한다(행을 고치는 UPDATE 0).
--
-- 권한: GRANT 변경 **없음**(열도 표도 그대로 — 2026-09-22_api_tokens_scope_expiry.sql 과 같다).
--
-- 실행: sudo -u postgres psql -d tbox_re -v ON_ERROR_STOP=1 \
--            -f db/migrations/2026-10-11_api_tokens_scope_community.sql
--
-- ★ 배포 순서: **이 마이그레이션 → 코드**. 코드가 먼저 올라가면 「커뮤니티 글쓰기」 발급이 CHECK 에 걸려
--   500 이 된다(비밀번호·코드는 이미 확인된 뒤라 사용자는 코드 하나를 버린다). 나머지 경로는 영향 없다.
--
-- 재실행 안전성(멱등): DROP CONSTRAINT IF EXISTS → ADD CONSTRAINT 가 한 트랜잭션이라 두 번 적용해도
--   결과가 같다(네 값 CHECK 하나). DELETE·TRUNCATE·UPDATE 는 한 줄도 없다(CLAUDE.md 4장).
--
-- 확인:
--   SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conname = 'api_tokens_scope_check';
--   → CHECK ((scope = ANY (ARRAY['read'::text, 'read+upload'::text, 'read+upload+delete'::text, 'community.write'::text])))
--
-- 되돌리기(코드를 먼저 되돌린 뒤 · `community.write` 행이 남아 있으면 실패한다 — 그 토큰을 먼저 회수·정리할지는 사용자 판단):
--   BEGIN;
--   ALTER TABLE api_tokens DROP CONSTRAINT IF EXISTS api_tokens_scope_check;
--   ALTER TABLE api_tokens ADD CONSTRAINT api_tokens_scope_check
--       CHECK (scope IN ('read', 'read+upload', 'read+upload+delete'));
--   COMMIT;
-- ─────────────────────────────────────────────────────────────

\set ON_ERROR_STOP on

BEGIN;

ALTER TABLE api_tokens DROP CONSTRAINT IF EXISTS api_tokens_scope_check;
ALTER TABLE api_tokens
    ADD CONSTRAINT api_tokens_scope_check
    CHECK (scope IN ('read', 'read+upload', 'read+upload+delete', 'community.write'));

COMMENT ON COLUMN api_tokens.scope IS
  'F-66 · Q:F-8: 토큰의 범위 — read · read+upload · read+upload+delete(파일 창구 사다리) · community.write(커뮤니티 창구 셋 — 독립 값). 창구를 좁힐 뿐이고 행 단위 판정은 요청마다 Authz 가 한다 (ARCH 4.7 · 4.8)';

COMMIT;
