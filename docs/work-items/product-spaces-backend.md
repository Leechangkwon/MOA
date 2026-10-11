# 회사·모임 다중 공간 백엔드

- 상태: 격리 검증 완료·독립 검토/서버 반영 대기
- 브랜치: cloud/group-dashboard
- 기준: 개인 홈 후보 4f12a42, 운영 기준 e1cece55 / runtime97cc6d8(v2.53.8)
- 환경: 격리 work/group-dashboard. 운영 DB/checkout/서비스 수정 없음.

## 목적과 범위

`docs/product-spaces-api.md` 계약대로 제품 공간(UUID), 다중 멤버십(owner/admin/editor/member/viewer), 초대, 보관/복구/소유권 이전, 개인·공간 홈 revision 설정, 공지/작업/일정/이야기/원본 자료 링크와 실제 위젯 API를 구현했다. 기존 users.team_id/community.spaces/share_groups와 다른 경계이며 기존 데이터 자동이전/공개0. 전역 Admin은 멤버십 없는 공간을 읽을 수 없다.

파일 목록은 중앙 Authz SQL WHERE + 공간 멤버십, 다운로드는 원본 Authz/scan/exec/integrity/audit/stream 재사용. 원본 권한 상실 시 링크 제목/본문/UUID도 목록·단건에서 제외된다. owner/admin/editor home편집, member 본인 기록(공지는 editor 이상), 전역 Guest 쓰기금지(개인 화면 취향저장만 허용).

초대 비밀은 생성 응답 한 번, DB SHA256/만료/최대횟수/회수, 사용 시 발급자 현재 계정/멤버십/권한 재검사. 소유권 이전은 현재 비밀번호+MFA 활성 계정 freshOTP, 실패집계/세션회수, 대상 활성멤버+전역쓰기자격. 마지막 owner 변경은 공간행 잠금 아래 보존. 감사 등록부에 고정 문장/대상계정·역할·기록UUID만 추가, 비밀/본문0.

## 변경 파일

- 신규: src/site/ProductSpaceAuthz.php, ProductSpaces.php, src/handlers/product_spaces.php.
- public/index.php: 인증 정책 API 라우트 선언만. 페이지 라우트는 root/프런트 구현 소유.
- src/Audit.php: 신규 product.* 고정 요약문장·허용 상세키(member_id/role_before/role_after/record_id).
- db/migrations/2026-10-11_product_spaces.sql: 신규표6개·인덱스·owner/ACL. db/schema.sql 같은 fresh-install DDL; db/grants.sql 신규표만.
- tests/product_spaces_backend.php/.py, tests/community/product_spaces_http.py, memories_harness.py 신규suite/migration/동적포트, memories_http.py 동적포트.
- docs/product-spaces-api.md, product-spaces-backend-directive.md, 본 기록.

## 검사

- `python3 tests/product_spaces_backend.py <outputs/product-spaces-backend>`: 실제 Db/Authz + 새 합성 PostgreSQL DB, 51/0. Auth/Audit는 직접검사의 테스트더블임을 명시. 정확한 기존 permissions 매트릭스 사용. 중앙 공간권한, 역할/초대발급자탈퇴·비활성·강등, 전역관리자격리, lastowner, revision, 원본private파일소유·grant·grant회수, 보관, 기록논리삭제. 새 DB 생성/폐기.
- `python3 tests/community/memories_harness.py --suite product_spaces_http.py --output <outputs/product-spaces-http-final>`: 실제 bootstrap/router/Auth/CSRF/Legal/Audit/PostgreSQL/스토리지, 72/0. 초대1회 경쟁200/404·uses1, owner동시탈퇴200/409·잔여owner1, sharedlayout동시저장200/409. 최초계정/동의/비활성관문, 사용자/공간격리, 원본파일실제바이트/hash검사 다운로드·권한회수, 실제비밀번호+OTP이전·OTP재사용거부/실패집계, 실제 allow/deny/이전상세감사. fixtureowner migration 두 번 적용. 운영 원본 복제0.
- 위 HTTP검사는 8768/8769 사용, 독립 검토는 `--port 8778` (8778/8779) 가능. 둘 다 점유 시 기존프로세스 종료하지 않음.
- 변경 PHP6파일 문법/전체 security_lint 금지패턴0/gitdiffcheck 통과. 프런트 화면 검사는 별도 담당.

초기 실패(핸들러 외부변수 범위, 누락 감사요약, 합성guest만료/oldconsent형식)는 보정 후 위 최종검사에서 통과했다. 실제서비스 데이터/Windows/휴대폰 검증을 합성검사로 대신했다고 주장하지 않는다.

## 서버 적용/보존

- migration은 SET LOCAL ROLE tbox_owner로 신규표 생성/owner, tbox_app SELECT/INSERT/UPDATE(DELETE/DDL0), tbox_backup SELECT. 기존표 owner/ACL·파일내용 변경0.
- Fresh schema동일구조; migration두번검증. 시험DB는 tbox_testdb owner로 한정변환, 운영 migration의 owner/grants는 원본 유지. root는 실제owner/ACL 모의적용을 별도 확인해야 함.
- 신규 외부라이브러리/패키지/cron/nginx설정0.
- 후보 고정·백업/복호화·모의코드/SQL적용·운영드리프트·HTTPS/데이터보존검증을 root 수행. 신규표/작성된 공간자료를 코드원복때 삭제하지 않는다. 운영 DB 복원/재시드0.

## 미검증/경계

독립 검토 결과·전체모듈회귀·실제HTTPS·실물기기는 root 단계. 기존커뮤니티 기록자동연동/사진업로드/원본파일권한자동확대/GoogleDrive/AI공간연동은 추가하지 않았다. 공간plain-text 기록과 안전한 원본파일 링크는 완성된 신규 데이터경로다.
