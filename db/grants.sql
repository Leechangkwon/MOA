-- db/grants.sql — DB 계정 최소 권한 (ARCHITECTURE 10장 · 7.2, PRD S-09-10, S-07)
--
-- 실행 계정: postgres (슈퍼유저). schema.sql · seed.sql 이 끝난 뒤 실행한다.
-- 실행 예:  sudo -u postgres psql -d tbox_re -v ON_ERROR_STOP=1 -f db/grants.sql
--
-- 원칙
--   · 앱 계정 tbox_app 은 "필요한 표에, 필요한 동작만".
--   · 파일 삭제는 논리 삭제(UPDATE deleted_at)이므로 files 에 DELETE 권한이 아예 필요 없다.
--   · permissions 는 SELECT 만  → 화면·API 로 권한 표를 바꿀 수 없다(권한 상승 경로 차단, ARCH 6.2-1).
--   · audit_logs 는 SELECT·INSERT 만 → 코드 버그가 있어도, 침입자가 앱 계정을 얻어도 지울 수 없다(Append-only).
--   · DROP / TRUNCATE / ALTER 는 어디에도 주지 않는다(테이블 소유자 tbox_owner 만 가능).
--   · 스키마에 표를 만들 권한(CREATE)과 이 DB 에 접속할 권한(CONNECT)은 PUBLIC 에서 회수하고
--     필요한 역할에만 준다(아래 ①~③).
--   · 역할의 **시간 제한**은 이 파일이 아니라 마이그레이션이 건다(권한이 아니라 역할 설정이다 — Q:F-4 P-7):
--     tbox_app 만 statement_timeout 15s · idle_in_transaction_session_timeout 60s
--     (db/migrations/2026-09-29_app_role_timeouts.sql, 확인 `SELECT rolname, rolconfig FROM pg_roles WHERE rolname LIKE 'tbox\_%'`).
--     tbox_owner · tbox_backup · tbox_perm 에는 걸지 않는다 — 마이그레이션·백업 pg_dump·권한 매트릭스 쓰기는 제한 밖이다.
--     F-70 보내는 배치 역할 tbox_push 에도 같은 두 값을 건다(db/migrations/2026-09-30_f70_push.sql).
--   · F-70 휴대폰 경보의 표 둘(alert_push_subscriptions · alert_push_outbox)과 역할 tbox_push 는 맨 아래 절 —
--     표·역할이 **있을 때만** 권한을 건다(마이그레이션 전 DB 에서 이 파일을 돌려도 실패하지 않게).

\set ON_ERROR_STOP on

-- 비서 MVP 표가 있는 설치에서만 적용. 기존 표/역할의 권한은 넓히지 않는다.
DO $$
BEGIN
    IF to_regclass('public.assistant_runs') IS NOT NULL AND to_regclass('public.assistant_events') IS NOT NULL THEN
        GRANT SELECT,INSERT,UPDATE ON public.assistant_runs TO tbox_app;
        GRANT SELECT,INSERT ON public.assistant_events TO tbox_app;
        GRANT SELECT ON public.assistant_runs,public.assistant_events TO tbox_backup;
    END IF;
END
$$;

-- 스키마 사용 권한.
-- `REVOKE ALL ON SCHEMA public FROM PUBLIC` 은 하지 않되(USAGE 를 통째로 끊으면 확장·도구 동작을
-- 예측 불가하게 바꾼다), CREATE 와 CONNECT 는 회수한다. 아래 두 REVOKE 가 그것이다.
GRANT USAGE ON SCHEMA public TO tbox_app;

-- ① 앱 계정(및 PUBLIC 에 속한 모든 역할)이 public 스키마에 표를 만들 수 없게 한다. — PRD S-09-10
--    PostgreSQL 14 는 public 스키마의 CREATE 를 PUBLIC 에 기본 부여한다. 그 결과 1단계 검토에서
--    tbox_app 으로 `CREATE TABLE hax(i int)` 가 성공했다(최소 권한 위반).
--    USAGE 는 남겨 두므로 기존 표 접근에는 영향이 없다.
REVOKE CREATE ON SCHEMA public FROM PUBLIC;

-- ②  마이그레이션 계정만 표를 만들 수 있다. tbox_owner 는 배포 시에만 쓰이고
--    비밀번호는 /etc/tbox/owner.pgpass (0600 root) 에만 있다. — ARCH 10장
--    (①로 PUBLIC 의 CREATE 를 회수했으므로, 이 GRANT 가 없으면 db/schema.sql 재적용이 실패한다.)
GRANT CREATE, USAGE ON SCHEMA public TO tbox_owner;

-- ③ 이 DB 에 접속할 수 있는 역할을 명시한 둘로 한정한다. — PRD S-09-10
--    같은 PostgreSQL 인스턴스에는 옛 t-box 의 역할(`tbox` 등)이 함께 있다.
--    PUBLIC 의 기본 CONNECT 를 그대로 두면 그 역할들로도 tbox_re 에 붙을 수 있다.
REVOKE CONNECT ON DATABASE tbox_re FROM PUBLIC;
GRANT  CONNECT ON DATABASE tbox_re TO tbox_app, tbox_owner;

-- ─── 먼저 전부 회수한다(재실행 시 과거 권한이 남지 않게) ───
REVOKE ALL ON teams, users, permissions, files, audit_logs, sessions, share_links, folders,
              fetch_hosts, api_tokens FROM tbox_app;

-- ─── 표별 권한 ───
GRANT SELECT, INSERT, UPDATE                 ON users     TO tbox_app;
GRANT SELECT, INSERT, UPDATE                 ON teams     TO tbox_app;
GRANT SELECT, INSERT, UPDATE                 ON files     TO tbox_app;
GRANT SELECT, INSERT, UPDATE, DELETE         ON sessions  TO tbox_app;   -- 로그아웃·세션 폐기(R-10)에 DELETE 필요
GRANT SELECT                                 ON permissions TO tbox_app; -- ★ SELECT 만
GRANT SELECT, INSERT                         ON audit_logs  TO tbox_app; -- ★ UPDATE/DELETE/TRUNCATE 없음 (PRD S-07)
-- 15-2 공유 링크 (F-13, ARCH 5.12 · 6.2-3). 회수도 UPDATE(revoked_at) 이므로 DELETE 가 필요 없다 —
-- 행이 남아야 "어떤 링크로 새어 나갔나" 를 감사 로그의 detail.share_id 로 되짚을 수 있다.
GRANT SELECT, INSERT, UPDATE                 ON share_links TO tbox_app; -- ★ DELETE 없음
-- 18-1 폴더 (F-26, ARCH 5.16 · 6.2-4). **DELETE 가 있는 두 번째 표**다(첫째는 sessions) —
-- 빈 폴더는 논리 삭제가 아니라 실제로 지운다. 지워도 잃을 기록이 없고(파일은 files 에 그대로),
-- 지운 사실은 감사 로그 folder.delete 에 남는다. 파일이 참조하는 폴더는 FK(ON DELETE RESTRICT)가 막는다.
GRANT SELECT, INSERT, UPDATE, DELETE         ON folders   TO tbox_app;
-- F-43 허용 사이트 목록 (PRD F-43, ARCH 5.22 · 10장, SECURITY R-76). 「URL 로 가져오기」가
-- 갈 수 있는 곳을 담은 표다. ★ DELETE 없음 — 허용에서 뺀 호스트도 "언제부터 언제까지 열려
-- 있었나" 가 조사에 필요해서 행을 지우지 않고 is_active=false 로만 내린다 (share_links 와 같은 방식).
GRANT SELECT, INSERT, UPDATE                 ON fetch_hosts TO tbox_app; -- ★ DELETE 없음
-- F-59 사용자별 프로그램 토큰 (PRD F-59 ①, ARCH 4.6 · 6.2-5, SECURITY R-82 · R-91).
-- 계정마다 발급하는 개인 토큰이고 서버에는 sha256 만 남는다. ★ DELETE 없음 — 회수는
-- revoked_at UPDATE 다. 행이 남아야 「그 토큰이 언제부터 언제까지 살아 있었나」 를 되짚을 수
-- 있다 (share_links 와 같은 방식).
GRANT SELECT, INSERT, UPDATE                 ON api_tokens  TO tbox_app; -- ★ DELETE 없음

-- 24단계 창고 대기표 (PRD F-37). 기초정보와 **같은 권한**이다 — DELETE 가 없다:
-- 제외도 되살리기도 `status` 값이고, 잘못 올린 행조차 앱이 지울 수 없다.

-- ─── 시퀀스 (INSERT 하는 표의 id 발급에 필요) ───
GRANT USAGE, SELECT ON SEQUENCE users_id_seq      TO tbox_app;
GRANT USAGE, SELECT ON SEQUENCE teams_id_seq      TO tbox_app;
GRANT USAGE, SELECT ON SEQUENCE files_id_seq      TO tbox_app;
GRANT USAGE, SELECT ON SEQUENCE audit_logs_id_seq TO tbox_app;
GRANT USAGE, SELECT ON SEQUENCE share_links_id_seq TO tbox_app;
GRANT USAGE, SELECT ON SEQUENCE folders_id_seq     TO tbox_app;
GRANT USAGE, SELECT ON SEQUENCE fetch_hosts_id_seq  TO tbox_app;   -- F-43 허용 사이트 목록
GRANT USAGE, SELECT ON SEQUENCE api_tokens_id_seq   TO tbox_app;   -- F-59 개인 프로그램 토큰
-- sessions.id 는 CHAR(64) 해시이므로 시퀀스가 없다. permissions 도 시퀀스가 없다.

-- ─── 앞으로 만들 표에 자동으로 권한이 새지 않게 한다 ───
-- (기본 권한을 설정하지 않는다 = 새 표는 명시적으로 GRANT 해야 앱이 쓸 수 있다)

-- ─── tbox_backup (읽기 전용 백업 계정) — PRD S-08 / ARCH 10장 · 11.1 (9단계, 2026-09-11)
--
-- 역할 **생성**과 비밀번호는 deploy/install.sh 가 한다(무작위 → /etc/tbox-v2/backup.pgpass 0600 root).
-- 이 파일은 권한만 다룬다 — 권한이 두 곳에 흩어지면 회수 누락이 생긴다.
--
-- 왜 읽기 전용인가: 백업은 "정리된 형태의 전체 데이터" 라 원본보다 위험하다 (PRD S-08 백업 보안).
-- 그 계정이 쓰기까지 되면, 백업 키가 새는 순간 데이터 유출이 아니라 데이터 **조작**이 된다.
-- pg_dump 에 필요한 것은 SELECT 뿐이다(시퀀스 현재값까지 뜨려면 시퀀스 SELECT 도 필요하다).
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'tbox_backup') THEN
        RAISE EXCEPTION 'tbox_backup 역할이 없다 — deploy/install.sh 가 먼저 만든다 (ARCH 10장)';
    END IF;
END
$$;

GRANT CONNECT ON DATABASE tbox_re TO tbox_backup;
GRANT USAGE   ON SCHEMA   public  TO tbox_backup;

-- 재실행 시 과거 권한이 남지 않게 먼저 전부 회수한다(위 tbox_app 과 같은 방식).
REVOKE ALL ON teams, users, permissions, files, audit_logs, sessions, share_links, folders,
              fetch_hosts, api_tokens FROM tbox_backup;

GRANT SELECT ON ALL TABLES    IN SCHEMA public TO tbox_backup;   -- ★ SELECT 만
GRANT SELECT ON ALL SEQUENCES IN SCHEMA public TO tbox_backup;   -- 시퀀스 현재값 (pg_dump)

-- 앞으로 만들 표에 자동으로 권한이 붙지 않게 기본 권한(ALTER DEFAULT PRIVILEGES)은 설정하지 않는다.
-- 새 표를 만들면 이 파일에 한 줄을 더해 다시 실행한다 — 백업에서 표가 빠지는 것은
-- 여기서 드러나야 하고, 조용히 권한이 따라붙는 것보다 낫다.

-- ─── tbox_perm (권한 매트릭스 편집 전용) — PRD F-25 ⑤ / ARCH 5.15 · 10장 (17-2, 2026-09-12)
--
-- 역할 **생성**과 비밀번호는 deploy/install.sh 가 한다(무작위 → .env 의 DB_PERM_USER/DB_PERM_PASS).
-- 이 파일은 권한만 다룬다 — 권한이 두 곳에 흩어지면 회수 누락이 생긴다(tbox_backup 과 같은 방식).
--
-- 왜 별도 롤인가 (SECURITY R-60 · R-43, PRD Q-12)
--   화면에서 권한 매트릭스를 고칠 수 있게 하면서도 "앱 런타임 롤은 이 표에 SELECT 만" 이라는
--   방어(위 tbox_app 의 ★ 줄)를 **그대로 지키기 위해서**다. 쓰기 자격은 이 롤에만 있고,
--   이 롤로 여는 연결은 핸들러 하나(src/handlers/admin_permissions_update.php 의 Db::perm())
--   에서만 만들어진다. 다른 핸들러에 SQL 인젝션 결함이 생겨도 그 연결은 tbox_app 이라
--   권한 표를 바꾸지 못한다.
--
--   · permissions 에 SELECT, UPDATE 만 — INSERT·DELETE 는 주지 않는다. 16행은 고정이며
--     행을 늘리거나 지우는 길 자체를 만들지 않는다 (ARCH 6.2-1).
--   · 다른 표 권한 0 — 이 자격이 새어도 계정·파일·감사 로그·세션에는 닿지 못한다.
--   · 시퀀스 권한 없음 (INSERT 가 없으므로 필요 없다).
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'tbox_perm') THEN
        RAISE EXCEPTION 'tbox_perm 역할이 없다 — deploy/install.sh 가 먼저 만든다 (ARCH 10장)';
    END IF;
END
$$;

GRANT CONNECT ON DATABASE tbox_re TO tbox_perm;
GRANT USAGE   ON SCHEMA   public  TO tbox_perm;

-- 재실행 시 과거 권한이 남지 않게 먼저 **전부** 회수한다(위 두 롤과 같은 방식).
REVOKE ALL ON teams, users, permissions, files, audit_logs, sessions, share_links, folders,
              fetch_hosts, api_tokens FROM tbox_perm;
REVOKE ALL ON ALL SEQUENCES IN SCHEMA public FROM tbox_perm;

GRANT SELECT, UPDATE ON permissions TO tbox_perm;   -- ★ 이 한 줄이 전부. INSERT·DELETE 없음

-- ─── F-70 휴대폰 경보 — 받기·시험 알림 대기표 · 보내는 배치 역할 tbox_push (지시서 docs/f70-push-alerts-directive.md D 표,
--     ARCH 5.32 · 10장, SECURITY R-99). 표를 만드는 것과 역할 tbox_push(NOLOGIN)를 만드는 것은
--     db/migrations/2026-09-30_f70_push.sql 이고, 이 절은 **같은 권한**을 다시 건다(재실행 시 과거 권한이 남지 않게 회수 뒤 GRANT).
--
--   tbox_app (웹)   받기 SELECT · INSERT(모양 칸만) · UPDATE(revoked_at·revoked_reason) — ★ DELETE 없음(해지 기록을 남긴다)
--                   대기표 SELECT · INSERT(user_id 만) — 웹은 행을 넣기만 하고 보내지 않는다(웹 요청 중 외부 요청 0)
--   tbox_push (배치) 받기 SELECT · UPDATE(last_ok_at·fail_count·fail_since·revoked_at·revoked_reason) · 대기표 SELECT · UPDATE(sent_at) ·
--                   users (id, role, is_active) 열 SELECT · audit_logs INSERT 만(append-only) · CONNECT · 그 밖 0.
--                   비밀번호·LOGIN 은 사용자 sudo 반영 스크립트가 정한다(비밀번호는 /etc/tbox-v2/push.pgpass 0600 tbox-push).
--   tbox_backup     SELECT 만 · tbox_perm 0
--   표가 아직 없으면(마이그레이션 전) 아무것도 하지 않고 NOTICE 만 남긴다.
DO $$
BEGIN
    IF to_regclass('public.alert_push_subscriptions') IS NULL OR to_regclass('public.alert_push_outbox') IS NULL THEN
        RAISE NOTICE 'F-70 표가 없다 — db/migrations/2026-09-30_f70_push.sql 을 먼저 돌린다(이 절은 건너뜀)';
        RETURN;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'tbox_push') THEN
        RAISE EXCEPTION 'tbox_push 역할이 없다 — db/migrations/2026-09-30_f70_push.sql 이 먼저 만든다(NOLOGIN)';
    END IF;

    EXECUTE format('GRANT CONNECT ON DATABASE %I TO tbox_push', current_database());
    GRANT USAGE ON SCHEMA public TO tbox_push;

    REVOKE ALL ON public.alert_push_subscriptions, public.alert_push_outbox FROM tbox_app, tbox_push, tbox_backup, tbox_perm;
    REVOKE ALL ON SEQUENCE public.alert_push_subscriptions_id_seq, public.alert_push_outbox_id_seq FROM tbox_app, tbox_push, tbox_backup, tbox_perm;

    GRANT SELECT                                                      ON public.alert_push_subscriptions TO tbox_app;
    GRANT INSERT (user_id, endpoint, host, p256dh, auth, device_hint) ON public.alert_push_subscriptions TO tbox_app;
    GRANT UPDATE (revoked_at, revoked_reason)                         ON public.alert_push_subscriptions TO tbox_app;
    GRANT USAGE, SELECT ON SEQUENCE public.alert_push_subscriptions_id_seq TO tbox_app;
    GRANT SELECT                                                      ON public.alert_push_outbox TO tbox_app;
    GRANT INSERT (user_id)                                            ON public.alert_push_outbox TO tbox_app;
    GRANT USAGE, SELECT ON SEQUENCE public.alert_push_outbox_id_seq   TO tbox_app;

    GRANT SELECT                                                                  ON public.alert_push_subscriptions TO tbox_push;
    GRANT UPDATE (last_ok_at, fail_count, fail_since, revoked_at, revoked_reason) ON public.alert_push_subscriptions TO tbox_push;
    GRANT SELECT                                                                  ON public.alert_push_outbox TO tbox_push;
    GRANT UPDATE (sent_at)                                                        ON public.alert_push_outbox TO tbox_push;
    -- users 는 세 칸만 — 비밀번호 해시·TOTP 비밀·이름 칸에는 닿지 않는다. 표 전체 권한을 먼저 걷고(재실행 대비) 칸만 준다
    REVOKE ALL ON public.users FROM tbox_push;
    GRANT SELECT (id, role, is_active)                                            ON public.users TO tbox_push;
    REVOKE ALL ON public.audit_logs FROM tbox_push;
    GRANT INSERT                                                                  ON public.audit_logs TO tbox_push;   -- ★ SELECT·UPDATE·DELETE 없음
    GRANT USAGE ON SEQUENCE public.audit_logs_id_seq                              TO tbox_push;

    GRANT SELECT ON public.alert_push_subscriptions, public.alert_push_outbox TO tbox_backup;
    GRANT SELECT ON SEQUENCE public.alert_push_subscriptions_id_seq, public.alert_push_outbox_id_seq TO tbox_backup;
END
$$;

-- ─── 즐겨찾기 (개발 대기열 6 · Q:F-3a 라운드 A, ARCH 5.33 · 10장) — 표 둘(file_favorites · folder_favorites).
--     표를 만드는 것은 db/schema.sql(새 설치)·db/migrations/2026-10-03_qf3a_favorites_recent.sql(기존 DB)이고, 이 절은 **같은 권한**을
--     다시 건다(재실행 시 과거 권한이 남지 않게 회수 뒤 GRANT). 표가 없으면(마이그레이션 전) 아무것도 하지 않고 NOTICE 만 남긴다.
--   tbox_app    SELECT · INSERT(user_id·대상 id 칸만 — created_at 은 기본값) · DELETE(빼기). UPDATE 없음 — 고칠 값이 없다.
--               개인 화면 설정이라 감사에 남기지 않는다(결정 M-2) — 이 표가 곧 그 사람의 설정이다.
--   tbox_backup SELECT 만 · tbox_perm · tbox_push 0
DO $$
BEGIN
    IF to_regclass('public.file_favorites') IS NULL OR to_regclass('public.folder_favorites') IS NULL THEN
        RAISE NOTICE '즐겨찾기 표가 없다 — db/migrations/2026-10-03_qf3a_favorites_recent.sql 을 먼저 돌린다(이 절은 건너뜀)';
        RETURN;
    END IF;
    REVOKE ALL ON public.file_favorites, public.folder_favorites FROM tbox_app, tbox_backup, tbox_perm;
    GRANT SELECT, DELETE              ON public.file_favorites, public.folder_favorites TO tbox_app;
    GRANT INSERT (user_id, file_id)   ON public.file_favorites   TO tbox_app;
    GRANT INSERT (user_id, folder_id) ON public.folder_favorites TO tbox_app;
    GRANT SELECT                      ON public.file_favorites, public.folder_favorites TO tbox_backup;
END
$$;

-- ─── 지문·얼굴 로그인 (T:F-72 패스키, ARCH 5.35 · 10장, SECURITY R-109) — 표 둘(passkeys · passkey_challenges).
--     표를 만드는 것은 db/schema.sql(새 설치)·db/migrations/2026-10-06_passkeys.sql(기존 DB)이고, 이 절은 **같은 권한**을
--     다시 건다(재실행 시 과거 권한이 남지 않게 회수 뒤 GRANT). 표가 없으면(마이그레이션 전) 아무것도 하지 않고 NOTICE 만 남긴다.
--   tbox_app    passkeys SELECT·INSERT·UPDATE(★ DELETE 없음 — 해제는 revoked_at) · passkey_challenges SELECT·INSERT·UPDATE·DELETE
--               (일회용 값 — 만료 정리) · 시퀀스 USAGE·SELECT
--   tbox_backup 둘 다 SELECT(공개 키라 비밀 아님) · tbox_perm · tbox_push 0
DO $$
BEGIN
    IF to_regclass('public.passkeys') IS NULL OR to_regclass('public.passkey_challenges') IS NULL THEN
        RAISE NOTICE '패스키 표가 없다 — db/migrations/2026-10-06_passkeys.sql 을 먼저 돌린다(이 절은 건너뜀)';
        RETURN;
    END IF;
    REVOKE ALL ON public.passkeys, public.passkey_challenges FROM tbox_app, tbox_backup, tbox_perm;
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'tbox_push') THEN
        REVOKE ALL ON public.passkeys, public.passkey_challenges FROM tbox_push;
    END IF;
    GRANT SELECT, INSERT, UPDATE         ON public.passkeys           TO tbox_app;
    GRANT SELECT, INSERT, UPDATE, DELETE ON public.passkey_challenges TO tbox_app;
    GRANT USAGE, SELECT ON SEQUENCE public.passkeys_id_seq, public.passkey_challenges_id_seq TO tbox_app;
    GRANT SELECT ON public.passkeys, public.passkey_challenges TO tbox_backup;
    GRANT SELECT ON SEQUENCE public.passkeys_id_seq, public.passkey_challenges_id_seq TO tbox_backup;
END
$$;

-- ─── 「이 PC 로그인 전용」 1회용 로그인 링크 (Q:F-9 · t-box F-75, ARCH 4.9 · 10장, SECURITY R-113) — 표 하나(session_links).
--     표를 만드는 것은 db/schema.sql(새 설치)·db/migrations/2026-10-12_session_open.sql(기존 DB)이고, 이 절은 **같은 권한**을
--     다시 건다(재실행 시 과거 권한이 남지 않게 회수 뒤 GRANT). 표가 없으면(마이그레이션 전) 아무것도 하지 않고 NOTICE 만 남긴다.
--   tbox_app    SELECT · INSERT · UPDATE(used_at 소비) · DELETE(일회용 값 — 만료 정리, passkey_challenges 와 같다) · 시퀀스 USAGE·SELECT
--   tbox_backup SELECT(코드의 sha256 뿐 — 원문 없음) · tbox_perm · tbox_push 0
--   sessions.origin·token_id 와 api_tokens.bound_ip 는 표 단위 권한(위 tbox_app 절)이 그대로 덮는다 — 여기서 따로 걸지 않는다.
DO $$
BEGIN
    IF to_regclass('public.session_links') IS NULL THEN
        RAISE NOTICE 'session_links 표가 없다 — db/migrations/2026-10-12_session_open.sql 을 먼저 돌린다(이 절은 건너뜀)';
        RETURN;
    END IF;
    REVOKE ALL ON public.session_links FROM tbox_app, tbox_backup, tbox_perm;
    REVOKE ALL ON SEQUENCE public.session_links_id_seq FROM tbox_app, tbox_backup, tbox_perm;
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'tbox_push') THEN
        REVOKE ALL ON public.session_links FROM tbox_push;
    END IF;
    GRANT SELECT, INSERT, UPDATE, DELETE ON public.session_links TO tbox_app;
    GRANT USAGE, SELECT ON SEQUENCE public.session_links_id_seq TO tbox_app;
    GRANT SELECT ON public.session_links TO tbox_backup;
    GRANT SELECT ON SEQUENCE public.session_links_id_seq TO tbox_backup;
END
$$;

-- Moa: invitation-only onboarding and member messages (2026-10-02).
GRANT SELECT,INSERT,UPDATE ON public.member_invites,public.member_rate_limits,public.member_settings,public.member_messages TO tbox_app;
GRANT USAGE,SELECT ON SEQUENCE public.member_invites_id_seq,public.member_messages_id_seq TO tbox_app;
GRANT SELECT ON public.member_invites,public.member_rate_limits,public.member_settings,public.member_messages TO tbox_backup;
GRANT SELECT ON SEQUENCE public.member_invites_id_seq,public.member_messages_id_seq TO tbox_backup;

-- Moa messenger AI (2026-10-02).
GRANT SELECT,INSERT,UPDATE ON public.member_ai_settings,public.member_ai_threads,public.member_ai_turns TO tbox_app;
GRANT USAGE,SELECT ON SEQUENCE public.member_ai_threads_id_seq,public.member_ai_turns_id_seq TO tbox_app;
GRANT SELECT ON public.member_ai_settings,public.member_ai_threads,public.member_ai_turns TO tbox_backup;
GRANT SELECT ON SEQUENCE public.member_ai_threads_id_seq,public.member_ai_turns_id_seq TO tbox_backup;

GRANT SELECT,INSERT,UPDATE ON public.member_ai_assists TO tbox_app;
GRANT SELECT ON public.member_ai_assists TO tbox_backup;

-- ─── G-0 「개인」 등급 · 사람 지정 권한 · 권한 요청 (PRD F-73, ARCH 4.1 · 5.36 · 10장) — 표 둘(access_grants · access_requests).
--     표를 만드는 것은 db/schema.sql(새 설치)·db/migrations/2026-10-10_access_grants.sql(기존 DB)이고, 이 절은 **같은 권한**을
--     다시 건다(재실행 시 과거 권한이 남지 않게 회수 뒤 GRANT). 표가 없으면(마이그레이션 전) 아무것도 하지 않고 NOTICE 만 남긴다.
--   tbox_app    둘 다 SELECT · INSERT · UPDATE · DELETE(권한 빼기는 줄을 지운다 — 누가 언제 뺐는지는 감사 grant.remove 에 남는다) ·
--               시퀀스 USAGE·SELECT
--   tbox_backup SELECT 만 · tbox_perm · tbox_push 0
DO $$
BEGIN
    IF to_regclass('public.access_grants') IS NULL OR to_regclass('public.access_requests') IS NULL THEN
        RAISE NOTICE '권한 표가 없다 — db/migrations/2026-10-10_access_grants.sql 을 먼저 돌린다(이 절은 건너뜀)';
        RETURN;
    END IF;
    REVOKE ALL ON public.access_grants, public.access_requests FROM tbox_app, tbox_backup, tbox_perm;
    REVOKE ALL ON SEQUENCE public.access_grants_id_seq, public.access_requests_id_seq FROM tbox_app, tbox_backup, tbox_perm;
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'tbox_push') THEN
        REVOKE ALL ON public.access_grants, public.access_requests FROM tbox_push;
    END IF;
    GRANT SELECT, INSERT, UPDATE, DELETE ON public.access_grants, public.access_requests TO tbox_app;
    GRANT USAGE, SELECT ON SEQUENCE public.access_grants_id_seq, public.access_requests_id_seq TO tbox_app;
    GRANT SELECT ON public.access_grants, public.access_requests TO tbox_backup;
    GRANT SELECT ON SEQUENCE public.access_grants_id_seq, public.access_requests_id_seq TO tbox_backup;
END
$$;
