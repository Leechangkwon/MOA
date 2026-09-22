-- ─────────────────────────────────────────────────────────────
-- 2026-09-22_api_tokens_scope_expiry.sql  (F-66 — 프로그램 토큰 본인 발급의 범위·만료,
--                              PRD F-66 ③ ④ · 8장 `/api/me/api-tokens` 세 행 · T-78,
--                              ARCHITECTURE 4.7 · 6.2-5, SECURITY R-97 · R-91,
--                              docs/tbox/f66-self-tokens-directive.md)
--
-- 목적: 개인 프로그램 토큰에 **범위**(scope)와 **만료**(expires_at)를 준다. F-59 의 토큰은
--       계정 권한 전부 · 회수 전까지 영구였다(R-91). F-66 은 「본인이 · 필요한 만큼만 ·
--       180일까지」로 좁힌다.
--
-- ★ 왜 기존 행을 `read+upload+delete` 로 채우나
--   그때는 범위라는 것이 없어 토큰 창구 열이 전부 열려 있었다. 좁혀서 채우면 이미 그
--   토큰을 심어 둔 사용자 프로그램이 그 순간 멎는다(ARCH 4.7 「표 api_tokens」).
--   좁히는 것은 사용자가 새 토큰을 발급하며 스스로 고르는 일이다.
--
-- ★ 왜 기존 행의 만료를 `created_at + 180일` 로 두나
--   기존 토큰도 180일 규칙을 따른다는 뜻이다. 발급 시각을 기준으로 삼으므로, 오래된
--   토큰은 이 마이그레이션 직후 이미 만료 상태일 수 있다 — 그것이 규칙의 뜻이고,
--   401 `token_expired` 는 「내 계정에서 새로 발급하세요」 를 알려 준다(ARCH 4.7).
--   (적용 전에 `SELECT id, user_id, created_at, created_at + interval '180 days' …` 로
--    어느 토큰이 곧 만료되는지 미리 볼 수 있다 — 아래 「확인」 참고.)
--
-- ★ 왜 CHECK 를 나중에 거나
--   NOT NULL·CHECK 를 먼저 걸면 기존 행이 있는 표에서 ALTER 가 실패한다. 그래서
--   ① 널 허용으로 열을 더하고 ② 기존 행을 채우고 ③ NOT NULL ④ CHECK 순서다.
--
-- 열
--   scope       `read` · `read+upload` · `read+upload+delete` 셋 중 하나. 창구를 좁힐 뿐이고
--               파일별 판정(decideRead·decideUploadAs·decideDelete)은 요청마다 그대로 한다
--               — 토큰이 계정보다 커질 수 없다(ARCH 4.7)
--   expires_at  발급 + 180일 고정. 지난 토큰은 401 `token_expired`(회수의 401 `bad_token` 과
--               문구가 다르다 — 만료는 시간의 결과라 숨길 이유가 없다)
--
-- 권한: GRANT 변경 **없음**. 열을 더하는 것은 표 권한을 그대로 따른다
--       (tbox_app SELECT·INSERT·UPDATE, DELETE 없음 — 2026-09-16_api_tokens.sql 과 같다).
--
-- 실행: sudo -u postgres psql -d tbox_re -v ON_ERROR_STOP=1 \
--            -f db/migrations/2026-09-22_api_tokens_scope_expiry.sql
--       (새로 설치하는 DB 는 db/schema.sql 에 이미 들어 있어 이 파일이 필요 없다.)
--
-- ★ 배포 순서: **이 마이그레이션 → 코드**. `Auth::requireApiToken` 의 조회가 두 열을 함께
--   읽으므로, 코드가 먼저 올라가면 토큰 경로가 전부 500 이 된다(F-59 때의 1분 500 사고).
--
-- 확인:
--   \d api_tokens
--   SELECT id, user_id, scope, created_at, expires_at FROM api_tokens ORDER BY id;
--
-- 재실행 안전성(멱등): ADD COLUMN IF NOT EXISTS · `WHERE … IS NULL` UPDATE ·
--   SET NOT NULL(이미 걸려 있으면 아무 일도 하지 않는다) · CHECK 는 없을 때만 더한다.
--   DELETE·TRUNCATE 는 한 줄도 없고, 이미 값이 있는 행은 UPDATE 가 건드리지 않는다
--   (CLAUDE.md 4장 "운영 데이터에 손대지 않는다").
-- ─────────────────────────────────────────────────────────────

\set ON_ERROR_STOP on

BEGIN;

-- ① 열 추가 — 먼저는 널 허용이다(기존 행이 있으면 NOT NULL 로 바로 걸 수 없다).
ALTER TABLE api_tokens ADD COLUMN IF NOT EXISTS scope      TEXT;
ALTER TABLE api_tokens ADD COLUMN IF NOT EXISTS expires_at TIMESTAMPTZ;

-- ② 기존 행 채우기 — 그때는 범위가 없어 전부 열려 있었다 (ARCH 4.7).
--    이미 값이 있는 행(재실행)은 건드리지 않는다.
UPDATE api_tokens SET scope      = 'read+upload+delete'          WHERE scope      IS NULL;
UPDATE api_tokens SET expires_at = created_at + interval '180 days' WHERE expires_at IS NULL;

-- ③ 이제 비어 있는 행이 없다 → NOT NULL.
ALTER TABLE api_tokens ALTER COLUMN scope      SET NOT NULL;
ALTER TABLE api_tokens ALTER COLUMN expires_at SET NOT NULL;

-- ④ CHECK — 세 값만. `ADD CONSTRAINT` 에는 IF NOT EXISTS 가 없어 있는지 먼저 본다.
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'api_tokens_scope_check'
    ) THEN
        ALTER TABLE api_tokens
            ADD CONSTRAINT api_tokens_scope_check
            CHECK (scope IN ('read', 'read+upload', 'read+upload+delete'));
    END IF;
END
$$;

COMMENT ON COLUMN api_tokens.scope IS
  'F-66: 토큰의 범위 — read · read+upload · read+upload+delete. 창구를 좁힐 뿐이고 파일별 판정은 요청마다 Authz 가 한다 (ARCH 4.7)';
COMMENT ON COLUMN api_tokens.expires_at IS
  'F-66: 발급 + 180일 고정. 지난 토큰은 401 token_expired (ARCH 4.7)';

COMMIT;
