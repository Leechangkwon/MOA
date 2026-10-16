# 설치한 모아 플러그인의 ChatGPT 연결 활성화

- 상태: 서버 검증 완료·배포 대기
- 작업 브랜치: `codex/mcp-chatgpt-activation`
- 기준 main: `f38b59201212` (v2.70.1)
- 담당 환경: 서버의 격리 작업본

## 목적과 범위

사용자는 전달한 ZIP을 정상 설치했지만 MCP 연결을 사용할 수 없다. 설치 캐시의 서버 URL/OAuth 설정은 ZIP과 같고, 운영 원격 MCP는 비활성(503), callback 허용 목록도 비어 있다. 플러그인 목록 표시는 설치 증거이며 인증 성공 증거가 아니다. 별도 MCP 탭 표시 원인은 이 서버 결과만으로 단정하지 않는다.

OpenAI 공식 인증 계약(2026-10-16 확인, https://developers.openai.com/plugins/build/auth)에 맞춰 RFC9207 issuer 지원을 광고하고 성공/거절 callback 모두 정확한 `iss`를 반환한다. 공식 안정 callback `https://chatgpt.com/connector_platform_oauth_redirect` 하나만 운영 허용한다. 기존 ZIP의 OAuth DCR/PKCE와 업무 권한을 유지하며 재업로드는 우선 요구하지 않는다. 기존 클라이언트의 metadata 갱신/실제 사용자 로그인 성공은 별도 확인한다.

요구사항: T:S-01/02/03/04/05/07/09, ARCHITECTURE 원격 MCP 절, API 원격 MCP OAuth 절. 런타임 허용 파일: **`src/mcp/McpOAuth.php`**. 시험: `tests/mcp/remote_http.py`, `tests/mcp/README.md`. 설정 예시/설명: `.env.example`, `docs/chatgpt-remote-mcp.md`, 이 작업서, STAGES/SECURITY/위키/발행/배포 기록. 운영 설정은 `.env`의 MCP 두 키만 변경한다. redirect 와일드카드, 임의 URL 조회, 인증 우회, 프로그램 토큰 삽입, 업무 자료 수정, DB migration, 패키지 추가는 범위 밖이다.

## 검사

격리 합성 DB의 실제 PHP/OAuth suite에서 discovery와 승인/거절의 issuer를 검증한다. 기존 잘못된 redirect/PKCE/resource 거부·권한·회수 시험도 같은 수락 suite에 포함된다. 보안 린트/문법/자산/위키 검사 뒤 고정 후보를 배포한다. 실제 HTTPS에서 metadata/401 challenge/DCR/검토 계정의 정상 로그인·승인·읽기·회수 경로를 확인한다. 실제 ChatGPT 사용자의 OAuth와 휴대폰은 성공을 확인하기 전까지 미검증이다.

## 서버 적용

- DB/패키지/cron 변경 없음. `.env`에 enabled=1과 정확한 callback 한 개만 설정, 다른 설정/파일 권한 보존. PHP-FPM reload.
- 암호화 DB·코드·설정 백업과 전체 복호화/파싱 → 커밋/파일/설정 전후 해시 고정 → 모의 적용 → 서버·원격 drift 확인 → FF/설정 → HTTPS/보존 확인 → 기록 push.
- 기존 업무 테이블 행·파일 원본·owner/ACL 보존. 검토는 claude_ 계정과 정상 인증만 사용한다. 실패 시 MCP만 비활성으로 돌리고 원인을 기록한다. DB 삭제/복원/재시드 금지. 디스크 3GB 미만이면 중단.

## 배포 결과

미반영. 수락 검사와 운영 결과는 실행 후 기록한다.

## 수락 결과 — 2026-10-16

- 실제 PHP/PostgreSQL 격리 suite **755/0**. issuer metadata/Host 바꿔치기·승인/거절 iss·PKCE·정확 callback·권한·회수 포함. 임시 DB/프로세스 정리 확인.
- 최초 실행은 257/1: 예전 사진 fixture가 업로드 전후 메타데이터까지 같아야 한다고 가정했다. 현재 G-2 사진 개인정보 제거와 충돌하므로 시험에서 tEXt만 없는 정확 PNG 바이트를 기대하도록 보정했다. 이미지 덩어리와 MCP 저장 후 원본 바이트 보존 검증은 유지. 운영 사진 코드는 변경하지 않았다. 전체 suite 재실행 위 결과.
- OAuth 단위141/0, protocol94/0, scripted DB tools61/0, PHP 문법 통과, 보안 린트0. 별도 컨텍스트 독립 검토·실제 ChatGPT 사용자 로그인·실물 휴대폰은 미검증. 직접 diff 검토: callback 전 사전 검증 실패는 로컬 JSON 오류를 유지하며 유효 URI로 돌아가는 승인/거절에만 iss를 붙인다. 새 외부 요청·권한 완화 없음.
- 증거 `/home/changkwon/qaredi-mcp-activation-results-20261016/`; 원문 자격/업무 자료를 출력하지 않는다. 비례 회귀는 밤 대기열에 넣는다.
