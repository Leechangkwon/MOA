-- ─────────────────────────────────────────────────────────────
-- 2026-09-21_files_exec_content.sql  (F-65 — 실행 파일이 든 압축본의 관리자 확인 업로드·
--                                      확인 다운로드, 사용자 결정 Q-23 2026-09-21,
--                                      PRD F-65 ① · 7.6 S-06 「관리자 확인 예외」 ·
--                                      8장 `POST /api/files/{id}/exec-download` · 7.11 AL-21 · T-77,
--                                      ARCHITECTURE 5.29 · 6.2, SECURITY R-94,
--                                      docs/f65-exec-upload-directive.md)
--
-- 목적: files 에 `exec_content BOOLEAN NOT NULL DEFAULT false` 한 칸을 더한다.
--   참이면 「F-53 이름 검사에 걸리는 실행 파일이 들어 있는데, 관리자가 확인하고 올린 압축본」
--   이라는 뜻이다. 거짓(기본값)이 지금까지의 모든 파일이고, 그 동작은 한 글자도 바뀌지 않는다.
--
-- 왜 예외가 아니라 표식인가 (ARCH 5.29)
--   F-53 은 zip 안에 차단 확장자(`.php`·`.sh`·`.js`·`.html` …)가 하나라도 있으면 거부한다.
--   F-65 는 그 한 갈래를 **푸는** 것이 아니라 내용 검사를 더 무거운 신원 보증으로 바꾼다 —
--   Admin 이 admin-only 등급으로 웹 세션에서, 확인 문구와 **요청마다** 비밀번호+OTP 를
--   통과했을 때만 들어온다. 그렇게 들어온 파일은 나중에도 「그런 파일」로 다뤄져야 한다:
--   공유 링크·프로그램 토큰 다운로드·미리보기가 막히고, 등급 변경이 막히며, 받을 때도
--   요청마다 비밀번호+OTP 를 묻는다(`POST /api/files/{id}/exec-download`).
--   그 판정의 근거가 이 한 칸이다 — 화면의 배지도, 서버의 관문 여섯도 이 값만 본다.
--
-- 값의 성질
--   · 업로드 트랜잭션(ARCH 5.2 [5])에서만 true 가 되고 **다시 false 가 되지 않는다.**
--     되돌리는 창구를 만들지 않는다 — false 로 내리는 순간 「Admin 만·OTP 필수」 전제가
--     통째로 풀리기 때문이다(등급 변경을 막는 이유와 같다, ARCH 5.29 「받기」 표).
--   · 기존 행은 DEFAULT false 로 채워진다. 이미 올라와 있는 zip 을 소급해 다시 열어 보지
--     않는다 — 검사는 업로드 시점의 장치다(sensitive_flags 와 같은 원칙).
--   · NOT NULL 이라 앱이 「모름」 상태를 다룰 필요가 없다(`$row['exec_content'] === true`).
--
-- 실행: sudo -u postgres psql -d tbox_re -v ON_ERROR_STOP=1 \
--            -f db/migrations/2026-09-21_files_exec_content.sql
--       (새로 설치하는 DB 는 db/schema.sql 에 이미 들어 있어 이 파일이 필요 없다.)
--   ★ 배포 순서는 「마이그레이션 → 코드」다. 새 코드는 SELECT 에 `f.exec_content` 를 넣으므로
--     이 열이 없는 DB 에서는 조회가 실패한다. 이 파일을 먼저 적용한 뒤 코드를 올린다.
--
-- 재실행 안전성(멱등): ADD COLUMN IF NOT EXISTS 와 COMMENT 뿐이다.
--   기존 행의 값을 덮어쓰는 UPDATE 도, DELETE·TRUNCATE 도 한 줄이 없다
--   (CLAUDE.md 4장 "운영 데이터에 손대지 않는다"). 두 번 적용해도 결과가 같다.
-- 권한: GRANT/REVOKE 를 하지 않는다. 컬럼 추가는 기존 files 표 권한에 자동으로 포함되고,
--   tbox_app 은 이미 files 에 SELECT/INSERT/UPDATE 를 갖는다 (db/grants.sql, ARCH 10장).
--   `audit_logs`·`permissions` 의 권한은 한 글자도 건드리지 않는다.
-- ─────────────────────────────────────────────────────────────

\set ON_ERROR_STOP on

BEGIN;

ALTER TABLE files ADD COLUMN IF NOT EXISTS exec_content BOOLEAN NOT NULL DEFAULT false;

COMMENT ON COLUMN files.exec_content IS
    '실행 파일이 든 압축본 — 관리자가 확인 문구·비밀번호·OTP 로 확인하고 올린 파일 (F-65, ARCH 5.29). 참이면 공유·토큰·미리보기·등급 변경이 막히고 받을 때도 요청마다 비밀번호+OTP 를 묻는다. 한 번 참이 되면 거짓으로 되돌아가지 않는다';

COMMIT;
