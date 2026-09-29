-- ─────────────────────────────────────────────────────────────
-- 2026-10-12_session_open.sql  (Q:F-9 — 등록 PC 에서 토큰으로 바로 로그인 · t-box F-75 · 사이트 PRD 10장 Q:F-9,
--                               ARCHITECTURE 4.9 · 6.2-2 · 6.2-5, SECURITY R-113, docs/qf9-pc-login-directive.md 조각 S)
--
-- 목적
--   ① api_tokens.bound_ip INET — 「이 PC 로그인 전용」 토큰(범위 session.open)이 발급 때 서버가 본 공인 IP.
--      CHECK `(scope = 'session.open') = (bound_ip IS NOT NULL)` — 로그인 전용 토큰은 언제나 IP 에 묶이고,
--      다른 범위의 토큰에는 IP 가 없다(표 수준의 마지막 그물).
--   ② api_tokens.scope CHECK 에 다섯째 값 `session.open`(독립 값 — 파일·커뮤니티 창구 0 · 링크 창구 하나와 whoami 만).
--   ③ sessions.origin TEXT NOT NULL DEFAULT 'login' · sessions.token_id — 이 세션이 어디서 났나.
--      CHECK `(origin='login' AND token_id IS NULL) OR (origin='token' AND token_id IS NOT NULL)`.
--      기존 세션 행은 전부 'login' · NULL 이 된다(기본값) — 일반 로그인 세션의 동작은 한 글자도 바뀌지 않는다.
--   ④ session_links — 1회용 30초 로그인 링크. **코드의 sha256 만** 둔다(원문 저장 0 — code 열이 없다).
--      한 문장으로 소비(`used_at` — UPDATE … WHERE used_at IS NULL). 만료 30초는 앱이 넣고 CHECK 가 마지막 그물이다.
--
-- 권한: tbox_app — session_links SELECT·INSERT·UPDATE·DELETE(일회용 값 — 만료 정리 허용, passkey_challenges 와 같다) ·
--       시퀀스 USAGE·SELECT / tbox_backup — SELECT(코드 해시뿐 — 원문 없음) / tbox_perm·tbox_push — 없음.
--       sessions·api_tokens 는 표 단위 권한이 이미 있어 새 열도 그대로 따라간다(GRANT 변경 0).
--
-- 잠금: api_tokens·sessions 에 ADD COLUMN(기본값 상수 — PG11+ 는 표를 다시 쓰지 않는다)과 CHECK 검사(행을 한 번 읽음).
--   `lock_timeout 5s` 로 긴 줄 뒤에 서지 않는다(못 잡으면 오류 → ROLLBACK — 바뀐 것 없음). 로그인이 잠깐(수 ms) 기다린다.
--
-- ★ 배포 순서: **이 마이그레이션 → 코드**. 코드가 먼저 올라가면 세션 조회(SQL_LOOKUP 의 origin·token_id)가 없는 열을 읽어
--   **모든 로그인 요청이 500** 이다. 마이그레이션만 먼저 올라간 상태는 안전하다(옛 코드는 새 열을 읽지 않고, 기본값이 채운다).
--
-- 재실행 안전성(멱등): ADD COLUMN IF NOT EXISTS · DROP CONSTRAINT IF EXISTS → ADD CONSTRAINT · CREATE … IF NOT EXISTS ·
--   REVOKE 뒤 GRANT — 두 번 적용해도 결과가 같다. 행을 고치는 UPDATE·DELETE·TRUNCATE 는 한 줄도 없다(CLAUDE.md 4장).
--
-- 실행: sudo -u postgres psql -d tbox_re -v ON_ERROR_STOP=1 -f db/migrations/2026-10-12_session_open.sql
--
-- 확인:
--   SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conname IN
--          ('api_tokens_scope_check','api_tokens_bound_ip_check','sessions_origin_check','sessions_token_id_fkey');
--   SELECT origin, count(*) FROM sessions GROUP BY origin;          → login 만
--   \d session_links                                                 → code 열 없음 · code_hash CHAR(64) UNIQUE
--
-- 되돌리기(코드를 먼저 되돌린 뒤 · session.open 토큰·token 세션 행이 남아 있으면 CHECK 에서 실패한다 — 그 행을 먼저
--   회수·정리할지는 사용자 판단):
--   BEGIN;
--   DROP INDEX IF EXISTS api_tokens_one_session_open;
--   DROP TABLE IF EXISTS session_links;
--   ALTER TABLE sessions DROP CONSTRAINT IF EXISTS sessions_origin_check;
--   ALTER TABLE sessions DROP CONSTRAINT IF EXISTS sessions_token_id_fkey;
--   ALTER TABLE sessions DROP COLUMN IF EXISTS token_id;
--   ALTER TABLE sessions DROP COLUMN IF EXISTS origin;
--   ALTER TABLE api_tokens DROP CONSTRAINT IF EXISTS api_tokens_bound_ip_check;
--   ALTER TABLE api_tokens DROP COLUMN IF EXISTS bound_ip;
--   ALTER TABLE api_tokens DROP CONSTRAINT IF EXISTS api_tokens_scope_check;
--   ALTER TABLE api_tokens ADD CONSTRAINT api_tokens_scope_check
--       CHECK (scope IN ('read', 'read+upload', 'read+upload+delete', 'community.write'));
--   COMMIT;
-- ─────────────────────────────────────────────────────────────

\set ON_ERROR_STOP on

BEGIN;
SET LOCAL lock_timeout = '5s';

-- ① ② api_tokens — 묶인 IP · 범위 다섯 값
ALTER TABLE public.api_tokens ADD COLUMN IF NOT EXISTS bound_ip INET;

ALTER TABLE public.api_tokens DROP CONSTRAINT IF EXISTS api_tokens_scope_check;
ALTER TABLE public.api_tokens
    ADD CONSTRAINT api_tokens_scope_check
    CHECK (scope IN ('read', 'read+upload', 'read+upload+delete', 'community.write', 'session.open'));

ALTER TABLE public.api_tokens DROP CONSTRAINT IF EXISTS api_tokens_bound_ip_check;
ALTER TABLE public.api_tokens
    ADD CONSTRAINT api_tokens_bound_ip_check
    CHECK ((scope = 'session.open') = (bound_ip IS NOT NULL));

-- 검토 RV-QF9-2 — 1대 규칙을 DB 가 지킨다: 한 사람의 살아 있는 로그인 전용 토큰은 **하나**(부분 유일 인덱스). 발급 둘이 겹쳐
--   (READ COMMITTED — 뒤 트랜잭션의 회수 UPDATE 가 앞의 새 행을 못 본다) 둘 다 살아남던 틈을 닫는다. 핸들러는 위반(23505)을
--   한 번 다시 시도하고 그래도면 409. 기존 행에는 session.open 이 없으므로 인덱스 만들기가 실패할 수 없다.
CREATE UNIQUE INDEX IF NOT EXISTS api_tokens_one_session_open
    ON public.api_tokens (user_id) WHERE scope = 'session.open' AND revoked_at IS NULL;

COMMENT ON COLUMN public.api_tokens.scope IS
  'F-66 · Q:F-8 · Q:F-9: 토큰의 범위 — read · read+upload · read+upload+delete(파일 창구 사다리) · community.write(커뮤니티 창구 셋 — 독립 값) · session.open(이 PC 로그인 전용 — 링크 창구 하나 · 독립 값). 창구를 좁힐 뿐이고 행 단위 판정은 요청마다 Authz 가 한다 (ARCH 4.7 · 4.8 · 4.9)';
COMMENT ON COLUMN public.api_tokens.bound_ip IS
  'Q:F-9: 범위 session.open 토큰이 묶인 공인 IP — 발급 때 서버가 본 Http::clientIp() 값(요청 값이 아니다). 다른 범위는 NULL (ARCH 4.9)';

-- ③ sessions — 어디서 난 세션인가
ALTER TABLE public.sessions ADD COLUMN IF NOT EXISTS origin TEXT NOT NULL DEFAULT 'login';
ALTER TABLE public.sessions ADD COLUMN IF NOT EXISTS token_id BIGINT;

ALTER TABLE public.sessions DROP CONSTRAINT IF EXISTS sessions_token_id_fkey;
ALTER TABLE public.sessions
    ADD CONSTRAINT sessions_token_id_fkey
    FOREIGN KEY (token_id) REFERENCES public.api_tokens(id) ON DELETE CASCADE;

ALTER TABLE public.sessions DROP CONSTRAINT IF EXISTS sessions_origin_check;
ALTER TABLE public.sessions
    ADD CONSTRAINT sessions_origin_check
    CHECK ((origin = 'login' AND token_id IS NULL) OR (origin = 'token' AND token_id IS NOT NULL));

COMMENT ON COLUMN public.sessions.origin IS
  'Q:F-9: login = 비밀번호(+2단계)·지문 로그인 · token = 「이 PC 로그인 전용」 토큰의 1회용 링크. token 세션은 유휴 10분 · 민감 동작 재인증 필수 (ARCH 4.9)';

-- ④ session_links — 1회용 30초 로그인 링크 (코드 원문 저장 0)
CREATE TABLE IF NOT EXISTS public.session_links (
    id          BIGSERIAL   PRIMARY KEY,
    code_hash   CHAR(64)    NOT NULL UNIQUE,                                   -- 32바이트 코드의 sha256 hex. 원문은 저장하지 않음
    token_id    BIGINT      NOT NULL REFERENCES public.api_tokens(id) ON DELETE CASCADE,
    user_id     BIGINT      NOT NULL REFERENCES public.users(id) ON DELETE CASCADE,
    ip          INET        NOT NULL,                                          -- 링크를 받은 IP(= 그 토큰의 bound_ip)
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    expires_at  TIMESTAMPTZ NOT NULL,
    used_at     TIMESTAMPTZ,
    CONSTRAINT session_links_expiry CHECK (expires_at > created_at AND expires_at <= created_at + interval '30 seconds')
);
CREATE INDEX IF NOT EXISTS session_links_token_idx ON public.session_links (token_id, created_at);

-- 표 주인 — 운영(postgres 로 실행)에서는 tbox_owner 로 옮긴다. 임시 DB(tbox_testdb)처럼 그 역할의 구성원이 아니면 건너뛴다.
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'tbox_owner')
       AND pg_has_role(current_user, 'tbox_owner', 'MEMBER') THEN
        EXECUTE 'ALTER TABLE public.session_links OWNER TO tbox_owner';
    END IF;
END
$$;

REVOKE ALL ON public.session_links FROM tbox_app, tbox_backup;
REVOKE ALL ON SEQUENCE public.session_links_id_seq FROM tbox_app, tbox_backup;
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'tbox_perm') THEN
        EXECUTE 'REVOKE ALL ON public.session_links FROM tbox_perm';
    END IF;
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'tbox_push') THEN
        EXECUTE 'REVOKE ALL ON public.session_links FROM tbox_push';
    END IF;
END
$$;

GRANT SELECT, INSERT, UPDATE, DELETE ON public.session_links TO tbox_app;   -- 일회용 값 — 만료 정리 허용
GRANT USAGE, SELECT ON SEQUENCE public.session_links_id_seq TO tbox_app;
GRANT SELECT ON public.session_links TO tbox_backup;                        -- 코드 해시뿐(원문 없음)
GRANT SELECT ON SEQUENCE public.session_links_id_seq TO tbox_backup;

COMMIT;
