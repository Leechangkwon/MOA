# ChatGPT 원격 MCP 연결 지시서 — 2026-10-04

## 목표·기준

사용자 선택 “1번”: ChatGPT에서 모아의 본인 글·사진을 읽고, 글을 다듬어 저장하고, 여행 계획을 읽고 수정하는 원격 MCP 연결. 기존 모아 로그인으로 권한을 승인하고 연결을 회수한다. API 모델 호출이나 개인 ChatGPT 구독 대리 사용은 이 기능이 아니다.

기준 main `b4e2ad6` (v2.47.0 배포 기록), 브랜치 `cloud/chatgpt-remote-mcp`. Git2만 접근 가능한 클라우드에서 구현·검증·push하며 운영 배포/ChatGPT 실제 등록은 검증 전 주장하지 않는다. 현재 OpenAI/MCP 최신 웹 문서는 네트워크 정책상 조회하지 못한다. 구현 프로토콜은 MCP 2025-03-26/2025-06-18의 stateless Streamable HTTP(JSON 응답), OAuth 2.1 Authorization Code + PKCE S256, RFC 8414/7591/8707/9728를 명시하고 실제 클라이언트 호환성을 서버 인계에서 확인한다.

요구사항: PRD S-01/02/03/04/05/07/09, ARCHITECTURE 4.0/4.1/13, DESIGN 6, SECURITY 4, 기존 Memories/TravelPlans 원문·사진·소유권·revision 계약. `docs/server-context/site-rules.md`의 구현 분담·독립 검토 적용. 지정 Claude 모델은 이 환경에 없어 현재 Codex 구현 에이전트와 별도 문맥 검토자로 역할을 구분한다.

## 계약

- 고정 진입점 `/mcp` (GET은 인증 후 405, POST JSON-RPC, OPTIONS 필요시 제한적 처리). Bearer 전용이며 세션/기존 X-API-Token으로 대체하지 않는다. Origin은 없거나 APP_URL의 정확한 origin만. 외부 네트워크 호출 없음.
- 검색 메타데이터: `/.well-known/oauth-protected-resource/mcp` 및 root alias, `/.well-known/oauth-authorization-server`. APP_URL 기반 고정 issuer/resource/endpoint, Host 헤더 사용 금지. 비활성/설정 부재는 닫힌 실패.
- OAuth `/tbox/oauth/register`, `/tbox/oauth/authorize`, `/tbox/oauth/token`, `/tbox/oauth/revoke`; 동적 등록 redirect_uri는 운영 설정의 정확한 HTTPS URI만 허용. client metadata URL 조회 없음, arbitrary redirect/CIMD 조회 없음. PKCE S256 필수, resource=`APP_URL + /mcp` 필수, single-use code, 짧은 access token, 회전 refresh token 및 재사용 시 연결 회수. secret/code/token은 해시만 DB에 저장.
- 기본 읽기 `memories.read`; 선택 `photos.read`, `memories.write`, `travel.read`, `travel.write`. 쓰기는 해당 읽기를 포함하고 사진은 별도. 계정 권한을 넘지 않는다. 요청한 scope 이하만 동의 가능. session consent는 CSRF + 본인 정상 로그인 + 초기 인증 관문 충족. 역할 보기/토큰 로그인은 발급 금지. 비밀번호·복구/MFA 상태 변경, 비활성/만료는 연결 사용 차단.
- `McpOAuth::authenticate()` → `['user'=>array, 'scopes'=>list<string>, 'grant_id'=>string]`; 오류는 OAuth 인증 응답으로 종료. `McpOAuth::resource()`는 정확한 리소스 URL. `McpOAuth::enabled()`는 설정/기능 상태. 이름 변경은 에이전트 간 계약 먼저 맞춘다.
- `RemoteMcp::handle()`는 위 인증 뒤 JSON-RPC 처리. initialize/notifications/initialized/ping/tools/list/tools/call; protocol/version/body/id/arguments 제한, unknown/batch 거부, 일반 tool 오류는 isError 콘텐츠로 변환. 명시 고정 로더만 사용. 요청/응답 본문·사진·token은 로그 금지.
- `McpTools::definitions(array $scopes)` 및 `McpTools::call(string $name,array $arguments,array $context)`는 권한 재확인 후 MCP result 배열. 도구: whoami, list/read/create/update 본인 memories, read_memory_photo(본인 글에 현재 첨부한 사진만), 기존 travel list/read/save/link. 문서 페이지 도구는 기존 로컬 MCP를 보존한다.
- 쓰기는 revision 충돌 검사, original_caption/original_media/미디어 파일 보존, 본문 저장 전에 읽고 사용자 확인할 것을 도구 설명에 명시. 공개 범위·팔로우·공유·삭제는 도구에서 변경 불가. 새 글은 비공개. 기왕 공개한 글의 본문 수정은 공개 내용이 바뀐다는 설명 포함. 등록 idempotency UUID.
- 사진은 기존 검증된 파일만 읽어 GD로 축소·재인코딩한 MCP image content, 메타데이터 제거, 최대 크기 제한. 파일 URL/public capability/서버 경로/원본 바이트 반환 금지. 영상은 메타데이터만, 사진 편집/영상 분석 구현 완료로 표시하지 않는다.
- `/tbox/mcp-connect`: 연결 주소·준비 상태·승인 요청·권한별 설명·연결 목록/해제·ChatGPT 등록 안내. 읽기 전용 기본, 사진/쓰기 선택, 계정 이름 표시. 외부 서비스의 UI/요금제/모바일 지원은 실제 계정에서 확인해야 한다고 정확히 안내.

## 수정 파일·담당

1. 인증: `src/mcp/McpOAuth.php`, `src/mcp/dispatch.php`, `src/mcp/routes.php`, `src/mcp/handlers/oauth.php`, `src/mcp/handlers/connections.php`, `public/index.php`, `src/Http.php`, `src/Audit.php`(OAuth 귀속 계정의 명시적 요청 단위 감사 분류만), `src/Auth.php`(필요시 세션과 섞이지 않는 OAuth principal 어댑터만), `public/assets/app.js`, `public/assets/member.js`, `.env.example`, `db/migrations/2026-10-15_remote_mcp.sql`, `db/grants.sql`(필요할 때), `deploy/sites/tbox-common.conf`, `deploy/sites/tbox-v2.conf`, `deploy/sites/tbox-v2-local.conf`, `tests/mcp/oauth_*`, `tests/lib/return_path_vectors.txt`.
2. 도구·전송: `src/mcp/RemoteMcp.php`, `src/mcp/McpTools.php`, `src/community/Memories.php`(기존 저장 검증 공유용 선택적 입력 배열만), `src/community/TravelPlans.php`(listing의 기존 기본값을 유지하며 MCP 응답에는 세션 CSRF를 넣지 않는 선택 인자, 웹/로컬/MCP 기록 연결 변경의 revision 일관성), `public/assets/community/travel-plans.js`와 `tests/community/travel_plans_browser.py`(자신의 연결 응답을 반영할 때 폼의 다른 변경을 덮어쓰지 않는 revision 동기화가 필요한 경우), `tests/community/travel_plans_http.py`(연결 뒤 revision 증가·오래된 판 충돌 수락 시험), `tests/mcp/tools_*`, `tests/mcp/protocol_*`. 인증 담당이 고정 require를 연결한다.
3. 연결 UI: `src/mcp/handlers/connect_page.php`, `public/assets/mcp-connect.js`, `public/assets/mcp-connect.css`, `src/handlers/settings_page.php`, `src/handlers/member_welcome_page.php`, `src/views/member_top.php`(이 화면만 고정 자산 로드), `tests/mcp/connect_browser.py`. 인증 API 계약을 담당자와 먼저 맞춘다.
4. 메인 문서·검토·검증: 이 지시서, `docs/work-items/chatgpt-remote-mcp.md`, `docs/chatgpt-remote-mcp.md`, `ARCHITECTURE.md`, `API.md`, `PRD.md`, `DESIGN.md`, `SECURITY.md`, `STAGES.md`, 해당 `docs/server-context/` 사본, `wiki/qaredi/guide-chatgpt.json`, `wiki/qaredi/index.json`, `clients/mcp/README.md`. 커뮤니티 자산 해시가 변경되면 공식 스크립트로 import 버전 갱신. 기존 VERSION/CHANGELOG/production-state는 변경하지 않는다.
5. 통합 검사: `tests/mcp/remote_http.py`, `tests/mcp/README.md`, `tests/community/memories_harness.py`(격리 DB 대상 새 마이그레이션과 시험 목록만). 인증/도구 담당과 실제 라우트·필드 계약을 맞추고 모의 서버가 아니라 실제 PHP/DB를 검사한다. 로컬 환경 부재로 못 돌리면 실행 명령과 제한을 명시한다.

## 금지·완료 조건

SQL 문자열 결합, innerHTML, arbitrary 파일 경로/remote URL, Fetch 밖 outbound, 비밀값 로그/소스, 공개 범위 확장, 기존 프로그램 토큰 재사용/노출 금지. request input roles 미신뢰. 실제 계정/사진을 합성 시험 데이터로 사용하지 않는다. 필요 파일이 목록 밖이면 먼저 메인에게 알린다.

OAuth(redirect/PKCE/resource/회수/refresh재사용/CSRF/다중계정), MCP wire/범위/모든 쓰기 충돌·보존, 합성 모바일/데스크톱 UI, lint/syntax/wiki/assets/기존 MCP regression을 실행. PHP/DB 부재면 서버 runnable tests를 만들고 미검증 명시; 합성 모형을 실제 PHP/DB 실행으로 표현 금지. 별도 문맥 독립 검토 후 커밋. 서버 인계는 백업·마이그레이션 재적용·nginx 로그 차단·실제 ChatGPT OAuth 및 도구 실행 검증까지 포함한다.
