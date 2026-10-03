# 가입·팔로우·여행 기록의 회원 경험 개선

- 상태: v2.47.0 운영 배포 완료 (2026-10-04)
- 작업 브랜치: `cloud/community-member-experience`
- 기준 main 커밋: `7c874502ef4026a052380eb05ce68d706c92d8e5`
- 후보 커밋: 이 문서를 포함한 PR의 마지막 커밋으로 고정한다.
- 담당 환경: 클라우드, 외부 연결 허용 도메인은 Git2뿐

## 목적과 범위

사용자의 8가지 요청을 가입부터 개인 기록까지 이어지는 기능 묶음으로 구현한다. 상세 결정/파일 분담은 `docs/community-member-experience-directive.md`.

1. 초대 회원 OTP 강제를 선택으로 바꾸고 OTP 앱 공식 설치 링크·패스키 등록을 제공한다. 패스키 로그인은 버튼을 눌러 시작한다. 등록된 OTP를 사용하는 비밀번호 로그인, 관리자 필수 정책, 관리자 초기화 뒤 복구 절차는 유지한다.
2. 비서 설치·토큰 설정은 선택이다. 모바일/PC 웹앱 설치 안내와 지원 브라우저 설치 버튼을 제공한다. 같은 출처 로그인으로 연결하므로 웹앱용 토큰을 생성하거나 붙여 넣지 않는다. Windows 독립 비서에는 공개 설치 파일이 없고 Git2 권한이 필요한 개발용 소스 ZIP만 확인됐다. 일반 회원용 APK/EXE나 독립 비서의 실제 ChatGPT 연결을 완료했다고 표시하지 않는다. 상세 `member-installation-2026-10-03.md`.
3. `/community/`는 전체 기능 입구, 스레드는 `/community/memories`. 기존 업무 공간/스레드 쿼리 바로가기는 보존한다.
4. 팔로우/팔로워·기본 공개 범위·글별 팔로워 공개를 추가한다. 팔로잉은 본인과 팔로우한 사람의 허용된 글, 발견은 명시적으로 게시한 커뮤니티/링크 공개 글만 제공한다. 최근성에 작성자·열람자·날짜의 작은 고정 순위 가중치를 더하고 커서로 다음 페이지를 읽는다. 관심사를 학습하는 AI 알고리즘은 아니다.
5. 다크 모드 카드 배경과 글자를 공통 테마의 색 쌍으로 수정한다.
6. 신규 가입자는 선택형 강조 안내를 받는다. 진행 상태는 본인 계정에 저장하고, 페이지/열린 창에서 다시 볼 수 있다. 안내는 게시/메시지 발송/권한 변경을 실행하지 않는다.
7. 본인 여행 계획의 작성·수정·일정 순서/날짜/완료/건너뜀·기록 연결을 제공한다. 계획은 비공개이며 글 공개와 별개다. MCP 여행 계획 도구는 별도 `travel.read`/`travel.write` 권한만 허용한다. 이 채팅에 MCP 연결을 설치한 것은 아니다.
8. 피드는 대표 사진과 요약, 상세 모달은 전체 사진/이야기/기존 메모를 표시한다. 더 보기·댓글·좋아요·공유를 이름 있는 아이콘으로 제공한다.

변경 범주: 인증/회원 핸들러·JS, `Auth`/`Authz`/`Http`, 커뮤니티 Memories/MemorySocial/TravelPlans/UsageGuide와 라우트·화면·자산, MCP 클라이언트, 추가 마이그레이션, 합성 시험·문서·위키. 실제 파일 목록은 PR diff로 고정한다. `VERSION` 및 `deploy/production-state.json`은 바꾸지 않는다.

## 검사

브라우저 시험은 Chromium + 합성 HTTP/WebAuthn/설치 이벤트다. PHP 렌더링·실제 서버의 인증/SQL 실행을 대신하지 않는다. 아래 검사는 종료 0으로 통과했다.

| 명령/검사 | 결과 |
|---|---|
| `python3 tests/optional_member_auth_browser.py` | 36개 |
| `python3 tests/member_install_browser.py` | 14개 |
| `python3 tests/community/usage_guide_browser.py` | 144개, 390px 다크/1280px 라이트·동의/키보드/실제 작성·여행 부분 템플릿 |
| `python3 tests/community/memories_browser.py` | 12개 |
| `python3 tests/community/memories_attachments_browser.py` | 9개 |
| `python3 tests/community/memory_ai_browser.py` | 8개, 공급자 응답 합성 |
| `python3 tests/community/memories_social_browser.py` | 9개, 이 안에서 40개 화면 폭/테마 조합의 대비·크기 확인 |
| `python3 tests/community/messenger_browser.py` | 9개, 새 발견 탭/정규 주소에 맞춰 검사 갱신 후 통과 |
| `python3 tests/community/travel_plans_browser.py` | 13개, 다중 기기 판 충돌·지연/시간 초과·입력 보존·창 안 확인 |
| `PYTHONPATH=clients/mcp python3 -m unittest discover -s clients/mcp/tests` | 114개, 기존 105 + 여행 9; 로컬 HTTP 시험 |
| `python3 tests/community/memory_social_predicates.py` | 248개, 실제 SQL 조건문을 SQLite 합성 자료로 실행 |
| `tests/lib/return_path_vectors.txt`의 현재 JS 함수 실행 | 79개, PHP와의 실행 대조는 미검증 |
| 변경 JS 12개 `node --check`, 변경 Python compile, 관련 shell `bash -n` | 통과 |
| `bash scripts/security_lint.sh`, `git diff --check` | 금지 패턴 0/공백 오류 0 |
| 변경 위키 JSON 구조 파싱·차례 파일 링크 점검 | 통과, 공식 PHP 위키 검사와 구분 |

커뮤니티 모듈 import의 자산 해시는 `scripts/community_asset_version.php`와 같은 정규화/순서/SHA-256 계산을 하는 임시 Python 도구로 갱신했다(앱 자산 import 5파일의 질의 버전만 변경). 공식 PHP `--check`는 서버에서 수행해야 한다. 단독 파일은 기존 filemtime 버전 경로를 사용한다.

구현과 별도 컨텍스트의 코드 검토에서 팔로워/직접 미디어/여행 교차 계정/토큰 범위/복구 조건을 확인했다. 여행 기록 연결이 편집 중 판 번호를 바꾸던 문제, 지연 응답의 입력 덮어쓰기, feed 탭의 이전 정규 주소, 가입 후 세션 실패의 null 분기를 수정하고 관련 브라우저 회귀를 추가했다. 모바일 안내와 스토리 화면 합성 스크린샷도 직접 확인했다. 실제 사용자 사진은 시험이나 커밋에 사용하지 않았다.

PHP·PostgreSQL·nginx 실행 환경이 없고 운영 서버 도메인으로 접속할 수 없으므로 PHP 구문/단위, 격리 DB HTTP/마이그레이션, 공식 PHP 자산/위키, 운영 HTTPS·실제 Gemini·실물 휴대폰·Windows는 미검증이다. 서버 측 필수 검사 전 병합/배포하지 않는다.

## 서버 적용

- DB 변경 순서(각각 트랜잭션, 적용 계정은 기존 표 owner/ACL 유지):
  1. `db/migrations/2026-10-14_optional_member_auth.sql` — 새 복구 구분 열 및 과거 미완료 관리자 초기화 분류. 기존 키/토큰/세션 삭제 없음.
  2. `db/migrations/2026-10-14_memory_social.sql` — 관계/기본 공개 범위 표, 팔로워 범위, `discoverable=false` 기본값. 기존 글 자동 공개 없음.
  3. `db/migrations/2026-10-14_travel_plans.sql` — 비공개 계획/기록 연결 표.
  4. `db/migrations/2026-10-14_travel_scopes.sql` — 새 범위 허용, 기존 토큰 값은 변경하지 않음.
  5. `db/migrations/2026-10-14_usage_guide.sql` — 사용자별 안내 상태·안내 시작 버전. 기존 회원에게 자동 팝업을 소급하지 않음.
- 패키지/설정/cron/서비스: 새 운영 패키지·외부 HTTP 통로·cron·서비스 없음. 기존 웹앱 매니페스트 재사용. MCP 사용자는 최신 클라이언트와 명시적으로 발급한 여행 범위 토큰을 설정한다.
- 격리 후보 DB에서 기존 owner/ACL을 보존한 채 각 마이그레이션을 두 번 적용하고 신규·기존 계정/토큰·원본 글/사진을 대조한다. 코드는 DB 적용 뒤 전환한다. 백업/복호화·모의 적용·서버/원격 드리프트 확인을 먼저 수행한다.
- 필수 서버 검사: 신규 초대 선택 가입/OTP 등록·로그인/패스키 등록·로그인·취소/관리자 초기화 복구/기존 장기 토큰, 팔로우 해제 직후 목록·직접 글·미디어·댓글 차단, 비공개·팔로워 글의 발견 및 링크 접근 거부, 남의 여행 UUID/기록 연결 차단, 여행 전용 토큰과 기존 문서 토큰 경계, CSRF, 저장 중 충돌/재전송, 가이드 동의/거절/재접속, 공식 보안 린트/자산/위키, 기존 회귀 묶음.
- 준비된 서버 수락 명령: `php tests/optional_member_auth_unit.php`, 격리 `OPTIONAL_AUTH_TEST_DB=tbox_optional_auth_<suffix> python3 tests/optional_member_auth_migration.py`(8상태·재적용), `php tests/community/memory_social_unit.php`, `php tests/community/travel_plans_unit.php`, `php tests/community/usage_guide_unit.php`, `python3 tests/community/memories_harness.py --suite memory_social_http.py --output <private-temp-dir>` 및 `--suite travel_plans_http.py`. 기존 패스키·회원 가입·QF10·사진 이야기·첨부·메신저·다른 모듈의 회귀도 실행한다. 시험 DB/역할은 기존 인계 조건을 따른다.
- 원본 보존: 기존 메모/사진/스토리·업무 문서 유지. 여행 일정 제거는 연결 글을 지우지 않고 여행 전체 연결로 남긴다. 계획 삭제는 소프트 삭제다.
- 실패 시 대응: 새 데이터는 보존한다. 신규 `followers` 공개 글이 생성된 뒤 예전 Memories 코드로만 복귀하면 예전 `visibility != private` 판정이 더 넓게 열 수 있다. 구버전 앱으로의 단순 복귀를 금지하고 장애 시 해당 스레드 경로를 잠근 뒤 수정 후보를 적용한다. DB 삭제/복원·재시드는 자동 실행하지 않는다.

## 배포 결과 (서버에서만 작성)

2026-10-04 서버 검증: 후보 `029f753a2a3377928630a68e7c3c7c41ea3b63fc`를 운영 경로 밖 `/home/changkwon/qaredi-member-review-20261004`에서 검증했다. PHP 8.1.2, PostgreSQL, nginx/PHP-FPM/PostgreSQL active 확인. 운영 checkout은 `7c87450`, 앱 버전 v2.46.0을 유지한다. 코드 전환·운영 DB migration·서비스 재시작·main 병합은 수행하지 않았다.

| 실행한 검사 | 결과 |
|---|---|
| 변경 PHP `php -l` | 32파일 통과 |
| optional_member_auth / memory_social / travel_plans / usage_guide PHP 단위 | 18 / 36 / 18 / 11 통과 |
| `optional_member_auth_migration.py` | 격리 DB 8상태·backfill 재적용 통과 |
| `memories_harness.py --suite memory_social_http.py` | 실제 PHP/PostgreSQL HTTP 134/134 |
| 같은 harness `travel_plans_http.py` | 59/59, 교차 계정·토큰 범위·충돌·원본 보존 |
| 같은 harness `memories_http.py` | 기존 사진·첨부·공개 범위 138/138 |
| 같은 harness `memory_story_http.py` | 시험 보정 후 65/65, 실제 PHP/DB·390px·합성 AI 공급자 |
| 같은 harness `memories_runtime_browser.py` | 시험 보정 후 19/19, 실제 PHP·1280/390px·사진 저장·메신저 |
| `member_onboarding.py` | 운영의 격리 DB 사본에서 53/53, 가입·OTP 선택/등록·토큰 보호·메신저; PHP runtime 오류 0 |
| `passkey_forge.py --samples` + `php tests/passkey_unit.php` | 137/137, 서명/해석기 검증; 실제 인증기 시험과 구분 |
| MCP unittest | 114/114 |
| `bash tests/qf10_multi_scope.sh` | 시험 기준/격리 초기 상태 보정 후 123/123, 기존 토큰 발급·IP·회수·문서/파일 범위 |
| `tests/passkey.sh`의 실제 HTTP API 묶음 | 157/157, 소프트웨어 인증기·초기화 복구·권한 변경/회수·실패 롤백 |
| 같은 패스키 틀의 브라우저 묶음 분리 실행 | Chromium 가상 인증기 화면 67/67, 화면 실행 틀 100/100, 가상 인증기 표본 PHP 검증 171/171; 실물 인증기와 구분 |
| `Http::returnPath()` 공통 벡터 | PHP 79/79 |
| 보안 린트·공식 PHP 자산 버전·위키 검사 | 금지 패턴 0, 자산 일치, 구조/누락 0 |

운영 DB를 읽어 만든 격리 사본에서 신규 migration 5개를 순서대로 각각 두 번 적용했다. 기존 users/files/folders/api_tokens/sessions/community.pages/memory_posts/memory_media의 행 수·내용 해시(추가 열 제외)가 보존됐다. 기존 표/시퀀스 owner·ACL, 신규 표 앱 권한, 기존 글 discoverable=false도 확인했다. 운영 DB에는 쓰지 않았으며 임시 DB/서버/자격 파일은 종료 시 제거했다. 원문 로그와 재현 runner는 서버 비공개 `/home/changkwon/qaredi-member-results-20261004`에 있다. 운영 데이터·비밀은 Git에 넣지 않는다.

실행 중 발견한 시험 문제: 신규 합성 회원에게 표시되는 첫 방문 안내창이 사진 시험의 클릭을 가로막았다. 두 실제 화면 시험에서 안내창의 실제 ‘나중에 볼게요’ 버튼을 누르고 닫힘을 확인하도록 보정했다. 앱 동작을 우회하거나 운영 코드를 변경하지 않았다. 원본 실패 로그도 별도 보존했다.

추가 회귀 보정: Q:F-10 시험의 기준을 이 PR의 main으로 고정하고 여행 토큰 경로를 포함했다. 과거 Q:F-9 제약을 시험하는 격리 DB의 복사된 토큰만 회수/정규화해 역사적 초기 상태를 만들었다. 실제 토큰 보존은 위 migration 사본 검사에서 별도로 검증했다. 최초 토큰 실행은 이전 기대값 실패와 실행 중 스크립트 수정으로 인한 중단을 포함하므로 통과 증거에서 제외하고, 수정 완료 뒤 전체 123개를 다시 실행했다.

패스키 시험은 후보 migration을 적용하도록 하고, 관리자 OTP 초기화 뒤 실제 enroll/confirm을 거쳐야 다음 검사를 진행하도록 보정했다. 복구 전 일반 기능/패스키 등록 차단, 복구 후 접근 복원 5개 검사를 추가했다. API 최종 157/157이며 화면 시험은 독립 실행해 결과를 분리한다. 문서 첫 화면 시험은 새 둘러보기에서 문서 공간 링크를 누르는 실제 경로(총4회) 및 `/community/workspace`를 사용한다. 웹앱 이름 기대값도 기존 운영 명칭인 ‘모아’로 맞췄다. 최종 화면67/67·실행 틀100/100·표본171/171 통과, 기본 대화창/콘솔 오류0. 실행용 runner와 실패/성공 로그는 위 비공개 결과 폴더에 보존한다. 임시 인스턴스/DB/자격 파일은 모두 정리했다.

2026-10-04 사용자 ‘배포 해’ 지시로 운영 반영을 진행한다. 추가 공통 경로 교차 회귀162/162 통과(실제 PHP/DB, 역할보기·재인증·파일/폴더·토큰·오류/감사·안내·모바일). 새 둘러보기 화면은 기존 도움말 대신 계정별 사용 안내 열기/닫기/저장으로 검증한다. 이 검사는 옛 common8 원본 스크립트 전체 항목을 실행했다는 뜻은 아니다. 백업 `/var/backups/tbox-v2/member-20261004`: DB·코드/설정·storage 암호화, SHA-256·복호화 및 DB/압축 아카이브 구조 확인 완료. 배포 증거는 `/home/changkwon/qaredi-member-deploy-20261004`에 기록한다.

### 운영 반영 완료 — 2026-10-04

사용자 배포 지시에 따라 v2.47.0 `d75a5d511d0bd38a54cbab1c348a30d08a4e3818`을 main·버전 태그에 전송하고 실제 운영에 반영했다. 운영/원격 드리프트 없음, 변경108파일의 모의 적용·PHP 구문·해시 일치 확인 후 신규 migration5개를 순서대로 적용하고 코드를 전환해 PHP-FPM을 reload했다. nginx 설정 검사와 nginx/PHP-FPM/PostgreSQL active 확인.

기존 users/files/folders/api_tokens/sessions/permissions/access_grants/access_requests/community.pages/memory_posts/memory_media의 행 수·내용 해시(추가 열 제외), 기존 표/시퀀스 소유권·ACL, storage 원본1018파일의 SHA-256 모두 보존됐다. DB/사진 복원이나 재시드는 하지 않았다. 백업은 위 암호화 경로에 있고, 임시 검증 DB와 소유 서버는 정리했다.

실제 HTTPS70/70 통과: 익명/인증 경계·새 자산 바이트·검토 계정 로그인/로그아웃·기존 토큰 범위+여행 범위·팔로우/여행/가이드 API·기존 회원에게 자동 안내를 소급하지 않음·전체 기능 입구/스레드/업무/설정·PC1280/모바일390px·브라우저 JS 오류0/5xx0. 기존 Gemini 준비 상태는 정상이며 이번 배포에서 실제 생성 요청은 하지 않았다. 운영 데이터와 비밀을 Git에 넣지 않고 `/home/changkwon/qaredi-member-deploy-20261004`에 비공개 증거를 남겼다. `deploy/production-state.json`을 실제 결과로 갱신했다.

잔여: 실물 휴대폰·Windows 확인, Windows 일반 설치파일, 이 채팅의 MCP 연결. 이 기능 배포는 독립 비서 설치/채팅 MCP 연결 완료를 뜻하지 않는다. 옛 common8 원본 전체가 아닌 변경 관련 공통 경로162개를 실행했다.

Git2 상태: 기능 브랜치 `cd333fd`를 포함하는 발행 커밋 `d75a5d5`를 main에 fast-forward push했다. Git2의 수동 병합 표시는 저장소에서 허용되지 않았고 일반 병합 API도405를 반환하여, main에 포함됨을 ancestry로 확인한 뒤 PR #3을 closed로 정리했다. Git2 merged 플래그는 false이며 코드의 main/운영 반영과 구분한다.
