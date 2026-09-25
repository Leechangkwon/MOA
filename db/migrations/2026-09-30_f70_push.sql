-- db/migrations/2026-09-30_f70_push.sql
--   t-box F-70 — 휴대폰 경보(설치형 웹 앱 + 웹 푸시)의 표 둘 · 보내는 배치 DB 역할 tbox_push · 권한
--   (파일 이름의 날짜는 적용 **순서**용이다 — 앞 마이그레이션 2026-09-29_app_role_timeouts.sql 뒤. 작성 2026-09-24)
--
-- 요구사항: docs/f70-push-alerts-directive.md D 장(표와 창구) · L-3(전용 계정) · L-6(기기 3대) ·
--           docs/f70-push-alerts-implementation.md(구현 기록) · ARCH 5.32 · 10장 · SECURITY R-99
--
-- 실행 계정: postgres (역할 만들기·역할 설정은 슈퍼유저 — tbox_owner 로는 하지 않는다).
-- 실행 예:  sudo -u postgres psql -d tbox_re -v ON_ERROR_STOP=1 -f db/migrations/2026-09-30_f70_push.sql
--   ★ 운영 반영은 사용자 sudo 스크립트(review-scripts/apply-f70-host-2026-09-24.sh — 검토 뒤 /var/www/qaredi/scripts/)로 한다.
--   ★ 이 파일 뒤에 db/grants.sql 을 다시 돌려도 같은 권한이 된다(grants.sql 에 같은 줄이 있다).
--
-- 하는 일 (여러 번 돌려도 같다 — CREATE … IF NOT EXISTS · 역할이 있으면 건너뜀 · GRANT 는 멱등, 기존 행 변경 0)
--   1. 역할 tbox_push — **NOLOGIN** 으로만 만든다(이미 있으면 건너뜀). 비밀번호를 정하고 LOGIN 으로 바꾸는 일은
--      사용자 sudo 반영 스크립트가 한다(무작위 값 → 표준입력으로 psql → /etc/tbox-v2/push.pgpass 0600 tbox-push).
--      이 파일에는 비밀번호가 없다.
--   2. public.alert_push_subscriptions — 관리자 휴대폰의 「받기」(Push API 구독). 행을 지우지 않는다 — 해지는
--      revoked_at·revoked_reason(user · not_admin · gone). 주소(endpoint)·기기 키(p256dh)·auth 는 받기를 만든
--      브라우저가 준 값이고, 보낼 때 내용 암호화에만 쓴다. 감사·로그·응답에는 이 셋을 남기지 않는다(금지 패턴).
--      fail_since — 연속 실패가 시작된 시각(성공하면 NULL). 경보 AL-22(24시간 실패)의 판정 재료(구현 기록 6).
--   3. public.alert_push_outbox — 「시험 알림」 대기표. 웹은 행 하나만 넣는다(보내지 않는다 — 원칙 1).
--   4. 권한 (D 표) — 아래 「권한」 절. tbox_push 에 역할 시간 제한 statement_timeout 15s · idle_in_transaction 60s
--      (tbox_app 과 같은 안전망 — Q:F-4 P-7. 보내는 배치는 트랜잭션을 연 채 밖으로 나가지 않는다: 문장마다 자동 커밋).
--
-- 되돌리기 (아무도 쓰기 전 — 운영에서는 사용자 판단):
--   DROP TABLE public.alert_push_outbox; DROP TABLE public.alert_push_subscriptions;
--   REVOKE ALL ON audit_logs FROM tbox_push; REVOKE ALL (id, role, is_active) ON users FROM tbox_push; … ; DROP ROLE tbox_push;

\set ON_ERROR_STOP on

BEGIN;

-- ─── 1. 역할 — NOLOGIN (비밀번호 없음) ───
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'tbox_push') THEN
        CREATE ROLE tbox_push NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;
    END IF;
END
$$;

-- ─── 2. 받기 ───
CREATE TABLE IF NOT EXISTS public.alert_push_subscriptions (
    id             BIGSERIAL   PRIMARY KEY,
    user_id        BIGINT      NOT NULL REFERENCES public.users(id),
    endpoint       TEXT        NOT NULL,
    host           TEXT        NOT NULL,
    p256dh         BYTEA       NOT NULL,
    auth           BYTEA       NOT NULL,
    device_hint    TEXT        NOT NULL,
    created_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
    last_ok_at     TIMESTAMPTZ,
    fail_count     INTEGER     NOT NULL DEFAULT 0,
    fail_since     TIMESTAMPTZ,
    revoked_at     TIMESTAMPTZ,
    revoked_reason TEXT,
    -- 주소는 https 이고 1,024자 이하 (D 표). 모양 검사는 앱(Fetch::pushEndpoint)이 먼저 하고, 여기서 한 번 더
    CONSTRAINT alert_push_subs_endpoint CHECK (char_length(endpoint) BETWEEN 9 AND 1024 AND endpoint LIKE 'https://%'),
    -- 호스트는 PUSH_HOSTS 셋 중 하나 (src/Fetch.php 의 코드 상수와 같은 글자 — 넓히려면 둘을 함께 고친다)
    CONSTRAINT alert_push_subs_host CHECK (host IN ('fcm.googleapis.com', 'web.push.apple.com', 'updates.push.services.mozilla.com')),
    -- 주소의 호스트 = host 칸 (다른 곳을 가리키는 주소가 허용 호스트 이름표를 달고 들어오지 못하게)
    CONSTRAINT alert_push_subs_endpoint_host CHECK (endpoint LIKE 'https://' || host || '/%'),
    -- 기기 키는 65바이트 비압축 점(0x04 로 시작), auth 는 16바이트 (곡선 위의 점인지는 앱이 openssl 로 본다)
    CONSTRAINT alert_push_subs_p256dh CHECK (octet_length(p256dh) = 65 AND get_byte(p256dh, 0) = 4),
    CONSTRAINT alert_push_subs_auth CHECK (octet_length(auth) = 16),
    -- 기기 요약은 고정 글자(「Android Chrome」 류, 40자 이하) — UA 원문은 저장하지 않는다
    CONSTRAINT alert_push_subs_hint CHECK (char_length(device_hint) BETWEEN 1 AND 40),
    CONSTRAINT alert_push_subs_fail CHECK (fail_count >= 0),
    CONSTRAINT alert_push_subs_revoked_pair CHECK ((revoked_at IS NULL) = (revoked_reason IS NULL)),
    CONSTRAINT alert_push_subs_revoked_reason CHECK (revoked_reason IS NULL OR revoked_reason IN ('user', 'not_admin', 'gone'))
);
-- 살아 있는 받기에서 주소는 하나 — 해지한 줄은 남기고(기록) 같은 주소를 다시 등록할 수 있게 부분 색인으로 둔다(구현 기록 6)
CREATE UNIQUE INDEX IF NOT EXISTS alert_push_subs_live_endpoint
    ON public.alert_push_subscriptions (endpoint) WHERE revoked_at IS NULL;
-- 「내 받기」·기기 3대 세기·보낼 대상 읽기
CREATE INDEX IF NOT EXISTS alert_push_subs_live_user
    ON public.alert_push_subscriptions (user_id) WHERE revoked_at IS NULL;

-- ─── 3. 시험 알림 대기표 ───
CREATE TABLE IF NOT EXISTS public.alert_push_outbox (
    id           BIGSERIAL   PRIMARY KEY,
    user_id      BIGINT      NOT NULL REFERENCES public.users(id),
    requested_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    sent_at      TIMESTAMPTZ
);
CREATE INDEX IF NOT EXISTS alert_push_outbox_user_time ON public.alert_push_outbox (user_id, requested_at DESC);
CREATE INDEX IF NOT EXISTS alert_push_outbox_waiting ON public.alert_push_outbox (id) WHERE sent_at IS NULL;

-- 표 소유자는 다른 표와 같은 tbox_owner (DROP·ALTER 는 소유자만 — db/grants.sql 머리글)
ALTER TABLE public.alert_push_subscriptions OWNER TO tbox_owner;
ALTER TABLE public.alert_push_outbox OWNER TO tbox_owner;

-- ─── 4. 권한 (D 표 · db/grants.sql 과 같은 내용) ───
-- 이 DB 에 붙는 권한 — DB 이름을 글자로 적지 않는다(같은 파일을 임시 DB 에서 시험한다)
DO $$
BEGIN
    EXECUTE format('GRANT CONNECT ON DATABASE %I TO tbox_push', current_database());
END
$$;
GRANT USAGE ON SCHEMA public TO tbox_push;

REVOKE ALL ON public.alert_push_subscriptions, public.alert_push_outbox FROM tbox_app, tbox_push;

-- tbox_app (웹): 받기 SELECT · INSERT(모양 칸만) · UPDATE(해지 두 칸만) — ★ DELETE 없음(기록) / 대기표 SELECT · INSERT(user_id 만)
GRANT SELECT                                                          ON public.alert_push_subscriptions TO tbox_app;
GRANT INSERT (user_id, endpoint, host, p256dh, auth, device_hint)     ON public.alert_push_subscriptions TO tbox_app;
GRANT UPDATE (revoked_at, revoked_reason)                             ON public.alert_push_subscriptions TO tbox_app;
GRANT USAGE, SELECT ON SEQUENCE public.alert_push_subscriptions_id_seq TO tbox_app;
GRANT SELECT                                                          ON public.alert_push_outbox TO tbox_app;
GRANT INSERT (user_id)                                                ON public.alert_push_outbox TO tbox_app;
GRANT USAGE, SELECT ON SEQUENCE public.alert_push_outbox_id_seq       TO tbox_app;

-- tbox_push (보내는 배치, L-3): 받기 SELECT · UPDATE(전달 결과·해지 칸만) / 대기표 SELECT · UPDATE(sent_at) /
--   users 는 (id, role, is_active) 세 칸만 SELECT / audit_logs 는 INSERT 만(append-only — 기존 감사 규칙) / 그 밖 0
GRANT SELECT                                                              ON public.alert_push_subscriptions TO tbox_push;
GRANT UPDATE (last_ok_at, fail_count, fail_since, revoked_at, revoked_reason) ON public.alert_push_subscriptions TO tbox_push;
GRANT SELECT                                                              ON public.alert_push_outbox TO tbox_push;
GRANT UPDATE (sent_at)                                                    ON public.alert_push_outbox TO tbox_push;
GRANT SELECT (id, role, is_active)                                        ON public.users TO tbox_push;
GRANT INSERT                                                              ON public.audit_logs TO tbox_push;
GRANT USAGE ON SEQUENCE public.audit_logs_id_seq                          TO tbox_push;

-- tbox_backup (백업, 읽기 전용)
GRANT SELECT ON public.alert_push_subscriptions, public.alert_push_outbox TO tbox_backup;
GRANT SELECT ON SEQUENCE public.alert_push_subscriptions_id_seq, public.alert_push_outbox_id_seq TO tbox_backup;

-- 역할 시간 제한 — tbox_app 과 같은 값(Q:F-4 P-7). 새 연결부터 걸린다. 보내는 배치의 문장은 짧고(받기 몇 줄),
-- 밖으로 나가는 동안 트랜잭션을 열어 두지 않는다 — 15초·60초에 걸릴 정상 경로가 없다(구현 기록 7).
ALTER ROLE tbox_push SET statement_timeout = '15s';
ALTER ROLE tbox_push SET idle_in_transaction_session_timeout = '60s';

COMMIT;
