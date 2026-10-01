# 파일명 변경 인계 — 2026-10-01

PATCH `/tbox/api/files/{uuid}`의 `name` + `expected_name` 구현·격리 검증·운영 코드 반영을 완료했다. 서버 측 차단 요인은 없다. 로컬 `SUPPORTS_FILE_RENAME=true`로 기존 F2/명시적 명령을 연결할 수 있다. 실제 운영 토큰 발급이나 업무 파일 이름 변경은 실행하지 않았다. 로컬 담당이 준비한 통합 검사의 실행 결과는 별도로 확인한다.

## 확정 계약

개인 `X-API-Token`, 최소 `read+upload`(`read+upload+delete`도 허용), JSON 정확히 두 필드:

```json
{"name":"8월_보고서.xlsx","expected_name":"보고서.xlsx"}
```

성공200은 정확히 `id`(기존 UUID)와 `name`이다. 현재 이름이 `expected_name`과 다르면409 `code:"name_conflict"`. 폴더/등급/설명과 혼합400, 확장자 변경400 `code:"extension_changed"`. UTF-8 최대255자, 빈 이름/앞뒤 공백/경로/제어문자/위험 이중 확장자400. 소유권·scope·개인 토큰403, 인증/만료/회수401, 없는/삭제된 UUID404. 라우터 인증·scope 거부는 기존 문자열 error만 있을 수 있다. 전체 계약은 `docs/file-rename-contract.md`.

## 코드·검증·운영 단계

| 단계 | 실제 상태/근거 |
|---|---|
| 코드 | 독립 사본 `/home/changkwon/qaredi-file-rename-20261001/tbox-v2`, 브랜치 `codex/file-rename-validated-20261001`, 기준 a39ec15(v2.40.1) |
| 격리 HTTP | 신규 빈 PostgreSQL 16 DB + 합성 claude_ 계정/가짜 파일만 사용. 123 통과/0 실패. 운영 레코드/바이트/토큰 복사0. 자체 테스트 서버/DB 제거 완료 |
| 운영 코드 | `/var/www/qaredi/tbox-v2`의 PHP5개 파일 반영. 검증본 SHA256과 모두 일치. PHP-FPM 설정 검사·graceful reload·active 확인 |
| 운영 접근 | 내부/공개 health200, 무인증 PATCH401. 운영의 실제 파일 변경은 미실행 |
| 보존 | 운영 DB 스키마/업무 데이터·설정 파일·기존 비서 MVP·표 편집 인계 변경 없음 |
| Git | 운영 HEAD a39ec15 유지, 해당5파일만 미커밋 변경. 커밋/push/발행 버전 갱신 미실행. 배포 원복본·해시 manifest·전체 소스 패치 보관 |
| 남은 서버 차단 | 없음. 운영 인증을 사용한 로컬 F2 통합 실행 결과는 미수신 |

운영에 반영한 파일:

- `src/FileRename.php`: 소유 파일 행 잠금/CAS/현재 계정·토큰·scope 재검사/표시 이름과 색인 변경/감사와 원자적 commit.
- `src/handlers/files_update.php`: 별도 이름 변경 분기. 기존 설명 PATCH는 잠긴 현재 이름으로 색인을 갱신.
- `src/Authz.php`: 기존 읽기/수정 권한과 정확한 업로더 소유권의 동시 검사.
- `src/Audit.php`: file.rename 허용/거부의 이유별 문장.
- `src/bootstrap.php`: 고정 helper require.

사본의 추가 변경: `API.md`, `ARCHITECTURE.md`, `PRD.md`, `SECURITY.md`, `STAGES.md`, `tests/catalog.json`, `tests/file_rename.sh`, `tests/file_rename/{serve.py,fixture.php,integration.py}`, `docs/file-rename-{directive,contract,handoff}.md`. 테스트/기록은 운영 런타임으로 복사하지 않았다.

## 실행 명령과 출력 원문

검증 폴더 `/home/changkwon/qaredi-file-rename-20261001/validation`에 전체 출력과 비밀 없는 실제 HTTP JSON이 있다.

```text
FILE_RENAME_OUTPUT=/home/changkwon/qaredi-file-rename-20261001/validation bash tests/file_rename.sh
SYNTHETIC_DB_CREATED=1; PRODUCTION_RECORDS_COPIED=0
FILE_RENAME_CHECKS_PASS=123; FAIL=0
OWNED_TEST_PROCESSES_STOPPED=1; SYNTHETIC_DB_DROPPED=1

bash scripts/security_lint.sh
결과: 통과 (금지 패턴 0건)

php -l <수정 PHP5개 + tests/file_rename/fixture.php 각각>
PHP_LINT_PASS=6

bash tests/community/asset_version.sh
통과 37 · 실패 0

bash tests/wiki_check.sh /var/www/qaredi
결과: 통과 (구조 문제 0건 · 빠진 글 0건, 글 65편)
RESULT pass=1 fail=0 skip=0

python3 tests/community/embed_nginx_check.py deploy/sites .
CHECK_FAIL=0

python3 tests/lib/passkey_forge.py --samples localhost http://localhost:8495 > <validation>/passkey-samples.json
php tests/passkey_unit.php <validation>/passkey-samples.json
통과 137 · 실패 0

php tests/design_themes.php
Design allowlist: 19 passed

python3 /home/changkwon/qaredi-file-rename-20261001/apply-runtime.py apply
RUNTIME_APPLIED=5; FPM_ACTIVE=1; PRODUCTION_DB_CHANGED=0; USER_FILE_BYTES_CHANGED=0
DEPLOYED_CHECKSUM_MATCH=5
```

SQL/HTTP 검증은 별도 DB의 실제 값과 응답을 대조했다: 소유권(scope와 독립), 공용/쿠키/읽기/로그인/커뮤니티 토큰 거부, CAS 동시성200/409, 동명 UUID 지정, UUID/원본/ACL/폴더/확장자 불변, 한글 검색과 다운로드 filename, 이름과 설명 동시 변경 시 색인 일치, 만료·회수·scope 축소를 파일 잠금 **대기 중** 적용한 후 재검사, 감사 INSERT 실패500 후 전체 이름/색인 원복. 기존 토큰 폴더 이동·쿠키 설명 수정도 실제 HTTP로 회귀 검사했다.

샘플: `rename-success.json`, `name-conflict.json`, `extension-changed.json`, `download-headers.json`, `production-activation.json`. 앞의 세 JSON은 `http_status`와 실제 응답 `body`를 묶은 증거 파일이며 API 자체 응답은 내부 `body`다. 검증 토큰·쿠키·비밀번호는 샘플/명령/로그에 포함하지 않았다.

## 미검증과 다음 순서

안전한 이유: 위 실제 HTTP/행 비교와 원복 시험, 고정 bound SQL, 기존 Authz/Upload 규칙 재사용, 사용자 이름을 물리 경로로 사용하지 않음. 확인된 기능 결함 없음.

서버 세션에서 브라우저/Windows F2 및 운영의 실제 파일 이름 변경은 미검증이다. 로컬31개 F2 시험은 로컬 담당의 보고이며 서버123개와 합산하지 않는다. 운영 계정/업무 파일을 쓰는 기존 `stage26/api_read/api_folders/f66/f68` 전체 셸은 실행하지 않았고, 변경에 닿는 폴더·설명·검색·다운로드·권한 회귀는 합성 HTTP로 확인했다. PHP 코드만 변경해 JS 문법/자산 버전 재생성은 필요 없다.

| 로컬 확인 순서 | 기대 |
|---|---|
| 개인 파일 token scope 확인, 기능 flag 활성화 | read+upload 이상, 로그인 토큰과 분리 |
| 준비한 임시 파일의 선택 UUID로 F2→Enter | 200 응답 id/name이 요청과 일치 |
| 검색 후 다운로드 | 새 표시명, 동일 원본 bytes/hash |
| 이전 expected_name으로 다시 요청 | 409, 현재 이름 덮어쓰기 없음 |
| Esc/실패 표시/임시 파일 정리 | 취소 저장0, 명확한 충돌 표시 |

이름 변경 요청에는 별도 실행 확인창을 서버에서 추가하지 않는다. 명시적 F2/명령으로 선택 UUID와 사용자 확정 이름을 그대로 보낸다. assistant.run/files.rename과 자동 이름 제안·일괄 변경은 후속 범위다.

## 원복

`/home/changkwon/qaredi-file-rename-20261001/runtime-deployment.json`에 배포 시각·기준/적용 SHA256·원복본 경로가 있다. 원복 명령:

```bash
python3 /home/changkwon/qaredi-file-rename-20261001/apply-runtime.py rollback
```

현재 파일이 배포 해시와 다르면 후속 편집을 덮어쓰지 않고 중단한다. handler부터 이전 코드를 복구하고 새 helper를 제거한 뒤 PHP-FPM reload한다. DB/업무 파일 bytes/기존 표시명 값을 되돌리는 작업은 하지 않는다.
