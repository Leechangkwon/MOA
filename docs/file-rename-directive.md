# T:F-08 파일명 변경 구현 지시서 — 2026-10-01

목표: 개인 파일 토큰으로 본인이 올린 파일의 표시 이름만 변경한다.

요구: PATCH `/api/files/{id}`, `name` + `expected_name` 정확히 두 키. 개인 토큰, 기존 `file.update` 범위(`read+upload` 이상), 현재 읽기/수정 권한과 소유권을 재검사한다. 원본 UUID·바이트·저장 이름·확장자·폴더·등급은 유지한다. 잠근 행의 이전 이름이 다르면409. 빈 이름/경로/제어문자/확장자 변경/업로드의 위험 확장자 규칙 위반은400. 성공200 `{id,name}`. 이름 검색 색인과 다운로드 표시명을 반영하고 `file.rename` 감사를 기록한다. 일반 설명 PATCH와 동시에 실행돼도 색인에 옛 이름이 다시 저장되지 않아야 한다.

읽을 절: ARCHITECTURE 4.1·4.7·5.7·13, API 12·13, PRD 5.3, SECURITY R-07·R-20·R-90.

수정 허용: `src/FileRename.php`, `src/handlers/files_update.php`, `src/bootstrap.php`, `src/Authz.php`, `src/Audit.php`, `tests/file_rename/`, `tests/file_rename.sh`, `tests/catalog.json`, `docs/file-rename-*.md`, `API.md`, `ARCHITECTURE.md`, `PRD.md`, `SECURITY.md`, `STAGES.md`.

금지: 사용자/운영 파일의 임의 변경, 운영 DB DDL/DML/시드·토큰 생성, SQL 문자열 결합, 요청 값의 물리 경로 사용, 새 외부 클라이언트, innerHTML, assistant.run/files.rename 신설, 운영 설정 변경, git add -A.

완료: 임시 DB + 가짜 파일만 이용해 성공/소유권/범위/개인 토큰/이름 검증/확장자/409 동시성/동명 UUID 지정/바이트·ACL 불변/검색/다운로드/감사/기존 폴더 이동 회귀를 실제 HTTP로 검증한다. PHP 문법, 보안 린트, 관련 기존 시험, 패치 적용 검사를 실행한다. 운영 반영 여부는 검증 상태와 구분한다.
