-- ─────────────────────────────────────────────────────────────
-- 2026-09-12_sessions_reauth.sql  (16-1 — PRD F-23, ARCHITECTURE 4.0 「관리 기능 재인증 — sudo 모드」)
--
-- 목적: sessions 에 컬럼 하나를 더한다.
--   · reauth_at — 그 **세션**이 마지막으로 비밀번호를 다시 확인한 시각.
--                 한 번도 확인하지 않았으면 NULL(= 관리 기능을 아직 쓸 수 없음).
--
-- 왜 필요한가 (PRD F-23, 12단계 접수 사항 ③ · 사용자 확정 2026-09-12)
--   세션 쿠키 하나만 있으면 계정 관리(`user.manage`)·조직/권한(`org.manage`)·감사 로그
--   (`audit.read`)·휴지통·위키(`role:Admin`)까지 곧바로 닿았다. 자리를 비운 사이의 브라우저나
--   훔친 쿠키가 그대로 관리자 콘솔이 된다는 뜻이다. GitHub·AWS 의 sudo 모드와 같은 원리로,
--   관리 영역은 **최근 15분 안에 비밀번호를 다시 확인한 세션**만 쓸 수 있게 한다.
--
-- 왜 users 가 아니라 sessions 인가
--   확인은 "이 기기, 이 창" 에 대한 것이다. users 에 두면 사무실 PC 에서 한 번 확인한 값이
--   집 노트북·훔친 쿠키의 세션까지 함께 열어 준다. 세션 행에 두면 다른 기기는 영향이 없다.
--
-- 왜 시각(TIMESTAMPTZ)인가
--   불린 플래그로 두면 "언제 확인했나" 를 알 수 없어 15분 창을 판정할 수 없고, 만료를 지우는
--   배치가 따로 필요해진다. 시각 하나면 판정이 곧 비교식이다:
--     reauth_at IS NOT NULL AND reauth_at > now() - make_interval(mins => 15)
--   판정은 **DB 시계 하나**로만 한다 (앱·DB 시계가 어긋나도 결과가 흔들리지 않게).
--   이 비교식은 세션 조회 SQL(`Auth::SQL_LOOKUP`)에 `reauth_fresh` 로 함께 실린다 —
--   요청마다 쿼리를 하나 더 늘리지 않는다.
--
-- 값의 이행
--   기존 세션은 전부 NULL 로 남는다(= "아직 확인하지 않음"). 패치 뒤 관리 영역에 처음
--   들어갈 때 한 번 묻게 된다 — 로그인도 이 값을 놓지 않는다(PRD F-23 "로그인 직후에도
--   reauth_at 은 비어 있다"). 기존 행을 채우는 UPDATE 는 하지 않는다.
--
-- 채우는 곳 (앱)
--   · src/Auth.php  reauth()  — POST /api/me/reauth 가 현재 비밀번호를 확인한 **그 세션 행만**
--                               `reauth_at = now()` 로 갱신한다. 실패는 로그인 뒤 창구와 같은
--                               카운터(countFailureAndRevoke, R-48)로 센다.
--   지우는 곳은 없다 — 세션 행이 사라지면 값도 함께 사라진다.
--
-- 실행: sudo -u postgres psql -d tbox_re -v ON_ERROR_STOP=1 \
--            -f db/migrations/2026-09-12_sessions_reauth.sql
--       (새로 설치하는 DB 는 db/schema.sql 에 이미 들어 있어 이 파일이 필요 없다.)
--
-- 재실행 안전성(멱등): ADD COLUMN IF NOT EXISTS 하나뿐이다. 값을 덮어쓰는 UPDATE 가 없고
--   DEFAULT 도 없다 — 기존 행은 NULL 로 남는다(운영 데이터 불변, CLAUDE.md 4장).
-- 권한: GRANT/REVOKE 를 하지 않는다. 컬럼 추가는 기존 표 권한에 자동으로 포함되고,
--   tbox_app 은 이미 sessions 에 SELECT/INSERT/UPDATE/DELETE 를 갖는다 (db/grants.sql, ARCH 10장).
-- ─────────────────────────────────────────────────────────────

\set ON_ERROR_STOP on

BEGIN;

ALTER TABLE sessions ADD COLUMN IF NOT EXISTS reauth_at TIMESTAMPTZ;

COMMENT ON COLUMN sessions.reauth_at IS
    '이 세션이 마지막으로 비밀번호를 다시 확인한 시각. 15분 안이어야 관리 기능을 쓸 수 있다 (F-23)';

COMMIT;
