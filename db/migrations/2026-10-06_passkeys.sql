-- db/migrations/2026-10-06_passkeys.sql — T:F-72 휴대폰 지문·얼굴로 로그인(패스키, WebAuthn) · 표 둘
--
-- 근거: 지시서 /var/www/qaredi/docs/passkey-mobile-directive.md 1.7(DDL 그대로) · 1.3(챌린지) · 1.6(90일·5개·해제)
--       구현 기록 docs/passkey-implementation.md · ARCH 새 절 5.35 · SECURITY R-109
--
-- 무엇
--   · passkeys            — 사람마다 등록한 휴대폰 열쇠(공개 키만 — SPKI DER). **행을 지우지 않는다**(앱 계정에 DELETE 없음) —
--                           해제는 revoked_at·revoked_by·revoke_reason. 90일 안 쓴 열쇠는 찾는 SQL 조건으로 꺼진다(배치 없음).
--   · passkey_challenges  — 서버가 매번 새로 내는 일회용 문제의 **sha256 만**(원문 저장 0). 한 문장으로 소비(used_at).
--                           로그인 챌린지는 로그인 전 쿠키(`__Host-tbox_pk`)의 sha256 에, 등록·재인증은 세션+사람에 묶인다.
--                           일회용 값이라 앱 계정이 정리(DELETE)할 수 있다.
--   · 권한: tbox_app — passkeys SELECT·INSERT·UPDATE(★ DELETE 없음) · passkey_challenges SELECT·INSERT·UPDATE·DELETE ·
--           시퀀스 USAGE·SELECT / tbox_backup — 둘 다 SELECT(공개 키라 비밀 아님) / tbox_perm·tbox_push — 없음
--
-- 잠금: 새 표 둘과 인덱스뿐이다. users·sessions 에는 외래 키만 걸리고(SHARE ROW EXCLUSIVE — 로그인의 INSERT·UPDATE 와는
--   부딪히지 않지만 DDL 끝까지 쥔다) `lock_timeout 5s` 로 긴 줄 뒤에 서지 않는다(못 잡으면 오류 → ROLLBACK — 바뀐 것 없음).
-- 여러 번 돌려도 같다(IF NOT EXISTS · REVOKE 뒤 GRANT). 새로 설치하는 DB 는 db/schema.sql · db/grants.sql 이 같은 모양을 만든다.
--
-- 순서(반드시): 이 마이그레이션 → nginx(새 창구 속도 제한) → 코드. 새 코드는 표가 있어야 창구가 돈다(없으면
--   `/api/me` 의 passkey_available 이 거짓 — 단추가 숨고 창구는 503. 자동 해제는 표가 없으면 건너뛴다).
-- 실행: sudo -u postgres psql -d tbox_re -v ON_ERROR_STOP=1 -f db/migrations/2026-10-06_passkeys.sql
-- 되돌리기(코드를 먼저 되돌린 뒤): DROP TABLE IF EXISTS public.passkey_challenges; DROP TABLE IF EXISTS public.passkeys;

\set ON_ERROR_STOP on
BEGIN;
SET LOCAL lock_timeout = '5s';

CREATE TABLE IF NOT EXISTS public.passkeys (
    id              BIGSERIAL   PRIMARY KEY,
    user_id         BIGINT      NOT NULL REFERENCES public.users(id),
    credential_id   BYTEA       NOT NULL UNIQUE CHECK (octet_length(credential_id) BETWEEN 16 AND 1023),
    public_key      BYTEA       NOT NULL CHECK (octet_length(public_key) BETWEEN 64 AND 1024),  -- SPKI DER (ES256 = 91)
    alg             INTEGER     NOT NULL CHECK (alg IN (-7)),          -- PC 단계에서 -257
    sign_count      BIGINT      NOT NULL DEFAULT 0 CHECK (sign_count BETWEEN 0 AND 4294967295),
    user_handle     BYTEA       NOT NULL CHECK (octet_length(user_handle) = 32),
    backup_eligible BOOLEAN     NOT NULL,
    backed_up       BOOLEAN     NOT NULL,
    transports      TEXT[]      NOT NULL DEFAULT '{}'
                    CHECK (transports <@ ARRAY['internal','hybrid','usb','nfc','ble','smart-card']::text[]),
    aaguid          UUID,                                              -- 기록만 (신뢰 근거 아님)
    name            TEXT        NOT NULL CHECK (char_length(name) BETWEEN 1 AND 40
                                AND name = btrim(name) AND name !~ '[[:cntrl:]]'),
    device_hint     TEXT        NOT NULL CHECK (char_length(device_hint) <= 40),
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    last_used_at    TIMESTAMPTZ,
    revoked_at      TIMESTAMPTZ,
    revoked_by      BIGINT      REFERENCES public.users(id),
    revoke_reason   TEXT        CHECK (revoke_reason IN
                    ('self','password_change','password_reset','mfa_reset','role_change','deactivate')),
    CHECK ((revoked_at IS NULL) = (revoke_reason IS NULL))
);
CREATE INDEX IF NOT EXISTS passkeys_user_live_idx ON public.passkeys (user_id) WHERE revoked_at IS NULL;

CREATE TABLE IF NOT EXISTS public.passkey_challenges (
    id             BIGSERIAL   PRIMARY KEY,
    challenge_hash CHAR(64)    NOT NULL UNIQUE,                        -- sha256 hex, 원문 저장 안 함
    purpose        TEXT        NOT NULL CHECK (purpose IN ('register','login','reauth')),
    user_id        BIGINT      REFERENCES public.users(id) ON DELETE CASCADE,
    session_id     CHAR(64)    REFERENCES public.sessions(id) ON DELETE CASCADE,
    binding_hash   CHAR(64),                                           -- 로그인 전 쿠키의 sha256
    created_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
    expires_at     TIMESTAMPTZ NOT NULL,
    used_at        TIMESTAMPTZ,
    CHECK ( (purpose = 'login' AND binding_hash IS NOT NULL AND user_id IS NULL AND session_id IS NULL)
         OR (purpose <> 'login' AND binding_hash IS NULL AND user_id IS NOT NULL AND session_id IS NOT NULL) )
);
CREATE INDEX IF NOT EXISTS passkey_challenges_expires_idx ON public.passkey_challenges (expires_at);

ALTER TABLE public.passkeys OWNER TO tbox_owner;
ALTER TABLE public.passkey_challenges OWNER TO tbox_owner;

REVOKE ALL ON public.passkeys, public.passkey_challenges FROM tbox_app, tbox_backup;
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'tbox_perm') THEN
        EXECUTE 'REVOKE ALL ON public.passkeys, public.passkey_challenges FROM tbox_perm';
    END IF;
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'tbox_push') THEN
        EXECUTE 'REVOKE ALL ON public.passkeys, public.passkey_challenges FROM tbox_push';
    END IF;
END
$$;

GRANT SELECT, INSERT, UPDATE         ON public.passkeys           TO tbox_app;   -- ★ DELETE 없음 (해제는 revoked_at)
GRANT SELECT, INSERT, UPDATE, DELETE ON public.passkey_challenges TO tbox_app;   -- 일회용 값 — 정리 허용
GRANT USAGE, SELECT ON SEQUENCE public.passkeys_id_seq           TO tbox_app;
GRANT USAGE, SELECT ON SEQUENCE public.passkey_challenges_id_seq TO tbox_app;

GRANT SELECT ON public.passkeys, public.passkey_challenges TO tbox_backup;      -- 공개 키라 비밀 아님
GRANT SELECT ON SEQUENCE public.passkeys_id_seq, public.passkey_challenges_id_seq TO tbox_backup;

COMMIT;
