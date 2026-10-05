# ChatGPT 원격 MCP — 내 기록·사진·여행 연결

- 상태: 클라우드 검증 완료·서버 검증 대기
- 작업 브랜치: `cloud/chatgpt-remote-mcp`
- 기준 main 커밋: `b4e2ad6` (v2.47.0 서버 검증 기록 포함)
- 후보 커밋: PR head로 고정
- 담당 환경: 클라우드, Git2 전용 네트워크

## 목적과 범위

사용자가 “1번!”으로 선택한 원격 MCP를 구현한다. ChatGPT에서 모아 계정으로 OAuth 승인 후 본인 기록·첨부 사진·여행 계획에 접근한다. 모아 안의 OpenAI API 메신저나 Windows 비서 구독 추론을 켜는 작업이 아니다.

인증은 별도 OAuth grant이며 기존 세션·프로그램 토큰과 섞지 않는다. 읽기/사진/쓰기/여행 범위를 각각 승인한다. 원격 도구는 본인 소유 자료만 대상으로 하며 다른 회원의 공개/팔로우 글을 읽지 않는다. 새 글은 비공개이고 기존 글의 공개 범위·미디어·원문 보존 필드는 유지한다. 사진은 검증된 현재 첨부의 축소·재인코딩본만 MCP image content로 전달한다. 기존 문서용 stdio MCP와 Gemini 도구는 보존한다.

지시서/수정 파일 목록: `docs/chatgpt-remote-mcp-directive.md`. 사용 안내: `docs/chatgpt-remote-mcp.md`. 실제 최종 변경 파일은 PR diff로 고정한다. VERSION/CHANGELOG/production-state는 변경하지 않는다.

## 검사

현재 PHP/PostgreSQL/nginx가 설치되어 있지 않고 로컬 Docker에 사전 제공 이미지도 없다. 네트워크는 git2.tla.so만 허용한다. PHP/실제 DB/운영 HTTPS/공식 최신 온라인 문서/실제 ChatGPT 계정 연동은 이 환경에서 검증할 수 없다. 합성 브라우저 검사는 PHP·OAuth·ChatGPT 실행을 대신하지 않는다.

| 명령/검사 | 현재 결과 |
|---|---|
| `PYTHONPATH=clients/mcp python3 -m unittest discover -s clients/mcp/tests` | 기존 stdio MCP 114개 통과 |
| `CHROMIUM_EXECUTABLE=/usr/bin/chromium python3 tests/optional_member_auth_browser.py` | 합성 로그인 선택/가입 36개 통과 |
| `CHROMIUM_EXECUTABLE=/usr/bin/chromium python3 tests/mcp/connect_browser.py` | 합성 연결 UI 48개 통과, 구현자·독립 검토자 재실행 |
| `CHROMIUM_EXECUTABLE=/usr/bin/chromium python3 tests/community/travel_plans_browser.py` | 합성 여행 UI 15개 통과; 자기 연결 변경 뒤 폼 보존 및 다른 창 수정 충돌 |
| `node tests/mcp/oauth_return_paths.js` | 실제 app.js의 공유 return path 벡터 85개 통과 |
| `python3 tests/mcp/remote_http.py --guard-selftest` | 운영 접근 방지 23개 통과, HTTP/DB 호출 없음 |
| `python3 tests/mcp/protocol_sdk.py` | PHP 부재로 exit77 SKIP; SDK 상호 운용 통과로 세지 않음 |
| 변경 JS 구문, Python 구문, 위키 JSON 및 diff 공백 검사 | 통과; PHP 구문·공식 위키 검사는 별도 미검증 |

보안 린트 금지 패턴 0건, 별도 문맥 독립 정적 검토에서 남은 차단 지적 없음. 검토 중 익명 미승인 client의 장기 잔류, 원격 읽기/거부의 감사 누락, 웹→MCP 여행 연결 충돌을 보완했다. 동시 code 교환/refresh 재사용, 다른 계정 쿠키가 섞인 Bearer 감사 귀속, 웹 연결 변경 뒤 MCP 충돌의 **실제 서버 검사 코드를 추가했으나 아직 실행하지 못했다**.

커뮤니티 JS 해시는 공식 PHP 스크립트와 같은 정렬·import query 제거·SHA256 계산을 로컬 보조 스크립트로 수행했다. `app=38bbaf960686`, `pm=5823578fa61a`, 두 번째 계산 변경0. 공식 `php scripts/community_asset_version.php --check`와 `php scripts/wiki_check.php`는 PHP 부재로 미검증이며 서버에서 실행한다.

서버 실제 통합 검사 명령: `python3 tests/community/memories_harness.py --suite remote_http.py --output /tmp/qaredi-remote-mcp-acceptance`. 전제·생성 범위·운영 접근 방지·출력은 `tests/mcp/README.md`. 새 PHP 단위/사진/프로토콜 검사는 같은 디렉터리의 `oauth_unit.php`, `protocol_unit.php`, `tools_unit.php`, `tools_photo.php`. PHP/GD가 설치된 서버에서 실행해야 한다. 실제 OAuth/PKCE/SQL/사진 처리를 합성 UI 결과로 대체하지 않는다.

쓰기 전 사용자 확인은 도구 설명·ChatGPT 클라이언트의 확인 절차에 의존한다. 서버는 승인 범위·소유권·입력·revision을 강제하지만 매 호출에 별도의 사람 승인 토큰을 요구하지 않는다. 쓰기 범위를 부여한 클라이언트는 유효한 저장 요청을 실행할 수 있다. 민감한 계정은 읽기만 승인하고 필요할 때 다시 연결해 쓰기 범위를 선택할 수 있다.

## 서버 적용

1. 후보 커밋을 고정한 격리 작업본에서 기존 DB owner/ACL을 유지한 합성 DB로 시험한다. 신규 migration `db/migrations/2026-10-15_remote_mcp.sql`을 두 번 적용해 재적용·권한을 확인한다. 기존 v2.47.0 migration이 선행되어야 한다. OAuth 관련 새 표 및 사용자 보안 epoch는 additive이다.
2. 관련 PHP 단위·HTTP 시험, 기존 인증/패스키/토큰/메모리/여행·공통 회귀, 보안 린트, 위키, 자산 검사를 통과한다. 클라우드 정적/모의 시험 통과만으로 병합/운영 적용 판정을 하지 않는다.
3. 백업·복호화 확인, 변경 파일/설정/migration 목록 고정, 모의 적용과 원격/서버 드리프트 확인 후 DB → 설정/코드 → nginx 구문검사/반영 순서로 적용한다. 최초에는 원격 MCP 비활성 상태로 둔다.
4. 운영 설정은 `MCP_REMOTE_ENABLED=0`(기본), `MCP_OAUTH_REDIRECT_URIS=[]`(기본 닫힘)이며 준비 뒤 `MCP_REMOTE_ENABLED=1`과 정확한 callback의 JSON 배열로 설정한다. 새 웹 패키지·cron·외부 공급자 호출은 없고 기존 PHP/GD를 사용한다. 운영 APP_URL은 정확한 HTTPS origin으로 고정한다. 실제 ChatGPT 등록에서 사용하는 callback URI를 확인해 정확한 허용 목록에 넣는다. Host/X-Forwarded-Host나 임의 요청 URI로 issuer/resource/redirect를 만들지 않는다. 원격 등록 메타데이터 URL을 가져오는 기능은 없다.
5. nginx 및 앞단 TLS 프록시에서 OAuth code/state/token/Authorization/request body가 로그에 남지 않음을 확인한다. 새 창구의 요청 크기·속도 제한과 기존 internal/download/CSP 보호를 확인한다.
6. 기능 활성화 후 실제 외부 HTTPS로 resource/authorization metadata, DCR, PKCE + resource bound code exchange, refresh rotation/replay revocation, 제한된 scope 도구 호출을 확인한다. 모아 연결 승인 화면과 연결 해제까지 실제 계정에선 사용자가 진행한다.
7. ChatGPT 웹에서 앱 등록 → 계정 확인 → 본인 글·사진 읽기 → 확인한 문장 저장 → 여행 계획 수정 → 충돌 처리 → 연결 해제/재연결을 확인한다. 실제 휴대폰 ChatGPT에 같은 앱이 나타나고 도구가 동작하는지 별도 확인한다. 최신 클라이언트가 요구하는 기능과 본 구현 지원 프로토콜을 대조한다.
8. 기존 DB/사진/문서·ACL·원문 보존을 확인한 뒤에만 버전 발행/production-state/배포 결과를 기록한다.

자격 수명: code 2분, pending 승인 요청 10분, access 최대10분, grant/refresh 최대30일(등록 client 만료 이내), client 등록90일. 승인받지 않은 익명 client는 10분 뒤 이후 등록 시 정리하며 진행 중 pending/grant는 보존한다. client2000/pending5000/사용자당 활성grant100 상한; nginx DCR5회/분, OAuth30회/분, MCP240회/분(IP 기준)이다. 만료된 client를 ChatGPT가 재사용해 실패하면 앱 제거/재등록이 필요할 수 있다. 익명 등록의 과도한 요청은 운영 모니터링 대상이다.

MCP DB migration은 사용자 보안 epoch와 새 표를 추가한다. 비밀번호/MFA/복구/활성/만료/역할·팀 상태 변화가 epoch를 증가시켜 이전 자격이 다시 살아나지 않게 한다. OTP 사용 step·접속 시각 변경은 연결을 끊지 않는다. `TravelPlans::record`는 웹/로컬/MCP 모두 연결·해제 후 revision을 증가시킨다. 브라우저는 자기 연결 결과만 안전하게 판 번호를 따라가고 기존 미저장 폼을 보존한다. 기존 여행 HTTP 검사도 새 충돌 계약으로 보완했으며 서버 재실행 대상이다.

실패 시 먼저 MCP 기능을 비활성화해 새 접근을 막는다. 기존 회원 기능과 사진·문서는 유지한다. 생성된 OAuth 기록 및 새 비공개 글·여행을 삭제/복원하지 않는다. 이전 v2.47.0 코드 복귀는 additive schema와 사용자 epoch trigger의 호환성을 격리 사본에서 확인한 뒤 시행한다. 원격 token/grant는 이전 코드에서 사용되지 않지만 이후 재활성화 시 회수 여부를 유지해야 한다.

## 배포 결과 (서버에서만 작성)

미배포. 실제 ChatGPT OAuth/도구/휴대폰 확인은 미검증.
