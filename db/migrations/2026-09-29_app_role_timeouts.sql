-- db/migrations/2026-09-29_app_role_timeouts.sql
--   Qaredi Q:F-4 P-7 — 앱 계정 `tbox_app` 의 문장 시간 제한 · 트랜잭션 안 유휴 시간 제한
--   (파일 이름의 날짜는 적용 **순서**용이다 — 앞 마이그레이션 2026-09-28_community_embeds.sql 뒤. 작성 2026-09-24)
--
-- 요구사항: ../../../docs/qf4-quick-fixes-directive.md 「P-7」 · ARCH 10장 · SECURITY R-103 · R-101
--
-- 실행 계정: postgres (역할 설정은 슈퍼유저 또는 CREATEROLE — tbox_owner 로는 하지 않는다).
-- 실행 예:  sudo -u postgres psql -d tbox_re -v ON_ERROR_STOP=1 -f db/migrations/2026-09-29_app_role_timeouts.sql
--   ★ 운영 반영은 `review-scripts/apply-db-p7-2026-09-24.sh`(사전 확인 · 사후 확인 · 틀리면 RESET)로 한다.
--
-- 하는 일 (표·자료 변경 0, 여러 번 돌려도 같다)
--   1. statement_timeout = '15s' — tbox_app 으로 보낸 **한 문장**이 15초를 넘으면 서버가 취소한다
--      (SQLSTATE 57014 `canceling statement due to statement timeout`). 요청은 500 으로 끝나고 워커는 풀린다.
--      근거(2026-09-24 실측, 운영 복사 임시 DB): cron 배치 가운데 tbox_app 으로 도는 것(purge_trash · consistency_check ·
--      audit_chain · alerts · scan_pending · audit_cli)은 **스크립트 전체**가 1초 안(최대 0.71초)이고, 가장 긴 문장은
--      첫 연결을 포함해 64ms. 웹 요청 가운데 가장 긴 것(URL 가져오기 29~39초)은 네트워크 시간이지 SQL 이 아니다.
--   2. idle_in_transaction_session_timeout = '60s' — 트랜잭션을 연 채 60초 동안 아무 문장도 오지 않으면 서버가
--      그 연결을 끊는다(열린 트랜잭션은 되돌려진다). P-2 연결 재사용(요청 끝 롤백 `Db::endRequest` · 넘겨받을 때
--      `Db::adopt`)이 어떤 이유로든 못 돈 경우의 DB 쪽 안전망이다(R-101). 앱의 트랜잭션은 네트워크·스캐너를 기다리지
--      않는다(URL 내려받기·검증·clamscan 은 트랜잭션 **밖** — 저장 rename 과 감사 한 줄만 안에 있다).
--
-- 범위: `ALTER ROLE … SET` 은 **클러스터 전체**에서 이 역할의 **새 연결**에 걸린다(모든 DB). 이미 열린 연결
--   (FPM 워커가 들고 있는 영속 연결 — P-2)에는 걸리지 않는다 — 워커가 새로 연결할 때부터다(FPM reload 가 워커를
--   새로 띄우므로 그때 모두 새 값). `DISCARD ALL` 은 세션 값을 「연결할 때의 값」으로 돌릴 뿐 역할 기본값을 다시
--   읽지 않는다. 다른 역할(tbox_owner · tbox_perm · tbox_backup · tbox_testread)은 바꾸지 않는다 — 백업 pg_dump·
--   마이그레이션·권한 매트릭스 쓰기는 이 제한을 받지 않는다. DB 에 따로 건 역할 설정(`ALTER ROLE … IN DATABASE`)이
--   있으면 그쪽이 이긴다 — 적용 스크립트가 tbox_re 에 그런 줄이 없는지 먼저 본다.
--   배치 하나가 더 긴 문장을 써야 하면 **그 스크립트의 연결에서만** `SET statement_timeout` 을 늘린다(지금은 없음).
--
-- 권한: GRANT/REVOKE 없음. db/grants.sql 머리글에 이 파일을 가리키는 주석이 있다.
--
-- 되돌리기:
--   ALTER ROLE tbox_app RESET statement_timeout;
--   ALTER ROLE tbox_app RESET idle_in_transaction_session_timeout;
--   (새 연결부터 서버 기본값 0 = 제한 없음. 확인: SELECT rolconfig FROM pg_roles WHERE rolname = 'tbox_app' → NULL)

\set ON_ERROR_STOP on

BEGIN;

ALTER ROLE tbox_app SET statement_timeout = '15s';
ALTER ROLE tbox_app SET idle_in_transaction_session_timeout = '60s';

COMMIT;
