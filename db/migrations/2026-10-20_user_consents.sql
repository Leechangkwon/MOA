-- ─────────────────────────────────────────────────────────────
-- 2026-10-20_user_consents.sql  (사이트 Q:F-13 지시서 P1 — 처리방침·약관 동의 기록 · 기획 ../docs/privacy-terms-plan.md 5.2 「동의 기록」)
--   (파일 이름의 날짜는 적용 **순서**용이다 — 2026-10-19_qf12_work.sql 뒤. 작성 2026-10-07)
--
-- 목적
--   user_consents — 누가 · 어느 문서(terms · privacy · age14 · ai_overseas)의 · 어느 판에 · agree/withdraw 를 · 언제 · 어느 IP 에서.
--   **추가만 하는 기록**이다: 앱 계정(tbox_app)은 SELECT · INSERT 만 — UPDATE · DELETE · TRUNCATE 없음(audit_logs 와 같은 방식).
--   지금 상태 = 문서별 **마지막 줄**(src/Legal.php). 철회(withdraw)는 ai_overseas 하나만(CHECK — 약관 철회는 탈퇴, 지시서 P2).
--   판은 `config/legal.json` 의 판 글자(YYYY-MM-DD 모양)다.
--
-- 권한: tbox_app — SELECT · INSERT · 시퀀스 USAGE·SELECT / tbox_backup — SELECT / tbox_perm · tbox_push — 없음.
--       db/grants.sql 의 같은 절이 같은 권한을 다시 건다(재실행 안전).
--
-- 잠금: 새 표 하나와 인덱스뿐이다. users 에는 외래 키만 걸린다(SHARE ROW EXCLUSIVE — 로그인의 UPDATE 와 부딪히지 않지만 DDL 끝까지 쥔다).
--   `lock_timeout 5s` 로 긴 줄 뒤에 서지 않는다(못 잡으면 오류 → ROLLBACK — 바뀐 것 없음).
--
-- ★ 배포 순서: **이 마이그레이션 → 코드**. 새 코드는 세션이 있는 모든 요청에서 이 표를 읽는다(Auth::requireLogin 의 동의 관문) —
--   표가 없으면 로그인한 사람의 모든 요청이 500 이다. 마이그레이션만 먼저 올라간 상태는 안전하다(옛 코드는 이 표를 모른다).
-- ★ 기존 회원의 줄은 **만들지 않는다**(결정 PT-4 — 다음 로그인 때 한 번 화면에서 동의). 행을 고치는 UPDATE·DELETE 0(CLAUDE.md 4장).
--
-- 재실행 안전성(멱등): CREATE … IF NOT EXISTS · REVOKE 뒤 GRANT — 두 번 적용해도 결과가 같다.
--
-- 실행: sudo -u postgres psql -d tbox_re -v ON_ERROR_STOP=1 -f db/migrations/2026-10-20_user_consents.sql
--
-- 확인:
--   \d user_consents                                         → CHECK 셋 · 인덱스 user_consents_user_doc_idx
--   \dp user_consents                                        → tbox_app=ar · tbox_backup=r
--   SELECT count(*) FROM user_consents;                      → 0 (기존 회원 줄을 만들지 않는다)
--
-- 되돌리기(코드를 먼저 되돌린 뒤 — 동의 기록이 사라지므로 지우기 전에 사용자 판단):
--   DROP TABLE IF EXISTS public.user_consents;
-- ─────────────────────────────────────────────────────────────

\set ON_ERROR_STOP on

BEGIN;
SET LOCAL lock_timeout = '5s';

CREATE TABLE IF NOT EXISTS public.user_consents (
    id       BIGSERIAL   PRIMARY KEY,
    user_id  BIGINT      NOT NULL REFERENCES public.users(id),
    doc      TEXT        NOT NULL CHECK (doc IN ('terms', 'privacy', 'age14', 'ai_overseas')),
    version  TEXT        NOT NULL CHECK (version ~ '^[0-9]{4}-[0-9]{2}-[0-9]{2}$'),
    action   TEXT        NOT NULL CHECK (action IN ('agree', 'withdraw')),
    at       TIMESTAMPTZ NOT NULL DEFAULT now(),
    ip       INET,
    CONSTRAINT user_consents_withdraw_ai_only CHECK (action = 'agree' OR doc = 'ai_overseas')
);
CREATE INDEX IF NOT EXISTS user_consents_user_doc_idx ON public.user_consents (user_id, doc, id DESC);

ALTER TABLE public.user_consents OWNER TO tbox_owner;

REVOKE ALL ON public.user_consents FROM PUBLIC, tbox_app, tbox_backup;
REVOKE ALL ON SEQUENCE public.user_consents_id_seq FROM PUBLIC, tbox_app, tbox_backup;
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'tbox_perm') THEN
        EXECUTE 'REVOKE ALL ON public.user_consents FROM tbox_perm';
    END IF;
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'tbox_push') THEN
        EXECUTE 'REVOKE ALL ON public.user_consents FROM tbox_push';
    END IF;
END
$$;

GRANT SELECT, INSERT ON public.user_consents TO tbox_app;   -- ★ UPDATE · DELETE · TRUNCATE 없음 (추가만 하는 기록)
GRANT USAGE, SELECT ON SEQUENCE public.user_consents_id_seq TO tbox_app;
GRANT SELECT ON public.user_consents TO tbox_backup;
GRANT SELECT ON SEQUENCE public.user_consents_id_seq TO tbox_backup;

COMMIT;
