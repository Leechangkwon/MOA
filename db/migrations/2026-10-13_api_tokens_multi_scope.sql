-- ─────────────────────────────────────────────────────────────
-- 2026-10-13_api_tokens_multi_scope.sql  (Q:F-10 — 토큰 하나에 범위 여럿 · 사이트 PRD Q:F-10 · t-box PRD F-76,
--                                          ARCHITECTURE 4.10 · 6.2-5, SECURITY R-114, docs/qf10-multi-scope-directive.md 조각 S-2)
--
-- 목적
--   api_tokens.scope 를 「다섯 값 중 하나」에서 「원자 집합을 + 로 이은 문자열」로 넓힌다. 열은 그대로 TEXT 한 열이다
--   (조회 경로 `WHERE token_hash = … AND revoked_at IS NULL` 불변 · 열 추가 0 · 권한 변경 0).
--   원자 여섯: read · upload · delete · community.read · community.write · session.open (순서 = 정규화 순서 — Authz::TOKEN_SCOPE_ATOMS).
--   ① api_tokens_scope_check  — 비어 있지 않고 원자가 전부 아는 것(포함 여부만). **순서·의존**(upload 는 read 필요 등)은 앱이
--      본다(Authz::normalizeScope) — DB 는 마지막 그물이다(ARCH 6.2-5). 의존이 깨진 행이 들어와도 판정(tokenScopeAllows)은
--      그 행이 가진 원자만큼만 연다.
--   ② api_tokens_bound_ip_check — 「session.open 원자가 들었다」 ⇔ 「IP 에 묶였다」.
--   ③ api_tokens_one_session_open — 한 사람의 살아 있는 「session.open 원자가 든」 토큰은 하나(1대 규칙 — 부분 유일 인덱스).
--   ④ 옛 행 정규화 — `community.write` 한 원자로 저장된 행을 `community.read+community.write` 로(쓰기는 읽기를 품는다).
--      다른 옛 값 넷(read · read+upload · read+upload+delete · session.open)은 정규형과 글자까지 같아 그대로다.
--
-- 앞 검사: 아는 모양(옛 다섯 값 또는 원자 집합)이 아닌 scope 가 하나라도 있으면 RAISE EXCEPTION → 트랜잭션 전체 ROLLBACK(바뀐 것 없음).
--
-- 잠금: api_tokens 에 CHECK 두 개 다시 걸기(행을 한 번 읽음) + 부분 유일 인덱스 다시 만들기 + 행 몇 개 UPDATE.
--   `lock_timeout 5s` 로 긴 줄 뒤에 서지 않는다(못 잡으면 오류 → ROLLBACK). 토큰 요청이 잠깐(수 ms) 기다린다.
--
-- ★ 배포 순서: **코드 → 이 마이그레이션**(바로 이어서). 새 코드는 옛 값 다섯을 그대로 읽는다(`community.write` 한 원자도 읽기로
--   인정 — 이중 안전) — 그 사이 막히는 것은 **묶음 발급**(새 모양 문자열이 옛 CHECK 에 걸려 500)과 `community.write` 발급(정규형
--   `community.read+community.write`)뿐이다. 거꾸로(마이그레이션 먼저)면 옛 코드가 `community.read+community.write` 를 모르는 값으로
--   보아 커뮤니티 글쓰기 토큰이 그 사이 403 이다.
--
-- 재실행 안전성(멱등): DROP CONSTRAINT IF EXISTS → ADD · DROP INDEX IF EXISTS → CREATE · UPDATE 는 `WHERE scope = 'community.write'`
--   (두 번째에는 0행). 두 번 적용해도 결과가 같다.
--
-- 실행: sudo -u postgres psql -d tbox_re -v ON_ERROR_STOP=1 -f db/migrations/2026-10-13_api_tokens_multi_scope.sql
--
-- 확인:
--   SELECT conname, pg_get_constraintdef(oid) FROM pg_constraint WHERE conname IN ('api_tokens_scope_check','api_tokens_bound_ip_check');
--   SELECT indexdef FROM pg_indexes WHERE indexname = 'api_tokens_one_session_open';
--   SELECT scope, count(*) FROM api_tokens GROUP BY scope ORDER BY 1;   → community.write 한 원자 행 0
--
-- 되돌리기(순서는 반영의 거꾸로 **DB → 코드** — 이 SQL 을 먼저, 그다음 코드를 되돌린다. 코드만 먼저 되돌리면 옛 코드가
--   `community.read+community.write` 를 모르는 값으로 보아 그 사이 커뮤니티 글쓰기 토큰이 403 이다(반영 스크립트 연습 ㉑ 실측 ·
--   검토 RV-QF10 ② 반영). 새 코드는 옛 값을 그대로 읽으므로 DB 를 먼저 되돌려도 막히지 않는다.
--   옛 다섯 값 밖의 행 — 묶음 토큰 — 이 남아 있으면 옛 CHECK 에서 실패한다. 그 행을 회수만 할지 지울지는 사용자 판단 ·
--   회수된 행도 CHECK 를 받는다):
--   BEGIN;
--   UPDATE api_tokens SET scope = 'community.write' WHERE scope = 'community.read+community.write';
--   DROP INDEX IF EXISTS api_tokens_one_session_open;
--   CREATE UNIQUE INDEX api_tokens_one_session_open ON api_tokens (user_id) WHERE scope = 'session.open' AND revoked_at IS NULL;
--   ALTER TABLE api_tokens DROP CONSTRAINT IF EXISTS api_tokens_bound_ip_check;
--   ALTER TABLE api_tokens ADD CONSTRAINT api_tokens_bound_ip_check CHECK ((scope = 'session.open') = (bound_ip IS NOT NULL));
--   ALTER TABLE api_tokens DROP CONSTRAINT IF EXISTS api_tokens_scope_check;
--   ALTER TABLE api_tokens ADD CONSTRAINT api_tokens_scope_check
--       CHECK (scope IN ('read', 'read+upload', 'read+upload+delete', 'community.write', 'session.open'));
--   COMMIT;
-- ─────────────────────────────────────────────────────────────

\set ON_ERROR_STOP on

BEGIN;
SET LOCAL lock_timeout = '5s';

-- 앞 검사 — 모르는 모양의 범위가 있으면 아무것도 바꾸지 않고 끝낸다.
--   아는 모양 = 옛 다섯 값(첫 적용 전) 또는 원자 집합(이미 적용한 뒤 — 두 번째 적용도 통과해야 멱등이다).
DO $$
DECLARE
    n_bad bigint;
BEGIN
    SELECT count(*) INTO n_bad
      FROM public.api_tokens
     WHERE scope NOT IN ('read', 'read+upload', 'read+upload+delete', 'community.write', 'session.open')
       AND NOT (scope <> ''
                AND string_to_array(scope, '+')
                    <@ ARRAY['read','upload','delete','community.read','community.write','session.open']::text[]);
    IF n_bad > 0 THEN
        RAISE EXCEPTION 'api_tokens.scope 에 모르는 값이 % 행 있다 — 마이그레이션을 멈춘다(바뀐 것 없음)', n_bad;
    END IF;
END
$$;

-- ① 범위 — 원자 집합(포함 여부만)
ALTER TABLE public.api_tokens DROP CONSTRAINT IF EXISTS api_tokens_scope_check;
ALTER TABLE public.api_tokens
    ADD CONSTRAINT api_tokens_scope_check
    CHECK (scope <> '' AND string_to_array(scope, '+')
           <@ ARRAY['read','upload','delete','community.read','community.write','session.open']::text[]);

-- ② session.open 원자 ⇔ 묶인 IP
ALTER TABLE public.api_tokens DROP CONSTRAINT IF EXISTS api_tokens_bound_ip_check;
ALTER TABLE public.api_tokens
    ADD CONSTRAINT api_tokens_bound_ip_check
    CHECK (('session.open' = ANY (string_to_array(scope, '+'))) = (bound_ip IS NOT NULL));

-- ③ 사람당 살아 있는 session.open 원자 토큰 하나
DROP INDEX IF EXISTS public.api_tokens_one_session_open;
CREATE UNIQUE INDEX api_tokens_one_session_open
    ON public.api_tokens (user_id)
    WHERE 'session.open' = ANY (string_to_array(scope, '+')) AND revoked_at IS NULL;

-- ④ 옛 행 정규화 — community.write 한 원자 → community.read+community.write (두 번째 적용에서는 0행)
UPDATE public.api_tokens SET scope = 'community.read+community.write' WHERE scope = 'community.write';

COMMENT ON COLUMN public.api_tokens.scope IS
  'Q:F-10: 토큰의 범위 — 원자 집합을 정규화 순서(read · upload · delete · community.read · community.write · session.open)로 + 로 이은 문자열(Authz::normalizeScope). CHECK 는 원자 포함 여부만 — 순서·의존(upload 는 read · delete 는 read+upload)은 앱이 본다. 창구를 좁힐 뿐이고 행 단위 판정은 요청마다 Authz 가 한다 (ARCH 4.7 · 4.10)';
COMMENT ON COLUMN public.api_tokens.bound_ip IS
  'Q:F-9 · Q:F-10: session.open 원자가 든 토큰이 묶인 공인 IP — 발급 때 서버가 본 Http::clientIp() 값(요청 값이 아니다). 그 토큰의 모든 창구가 이 IP 에서만 통한다. 다른 토큰은 NULL (ARCH 4.9 · 4.10)';

COMMIT;
