# Codex 승인 후 브라우저 복귀 차단 수정 — 2026-10-23

- 상태: 운영 v2.73.3 반영·실제 브라우저 검증 완료, 집Windows 사용자 재인증 확인 대기
- 기준 main: `d0fc2d2a8471`
- 브랜치: `cloud/moa-oauth-browser-return-20261023`
- 작업 시작 시 운영: v2.73.2. 현재 운영은 v2.73.3이며 개인 플러그인1.0.2는 유지한다.

## 원인과 범위

사용자 권한 승인은 정상 도착했으나 실제 Codex 요청의 callback `http://127.0.0.1:65203/callback`으로 브라우저가 돌아가지 않았다. 신규 승인2개는 codes.used_at/토큰발급/last_used가 없었다. 프런트 mcp-connect.js가 HTTPS만 허용하므로 서버가 정확히 검증한 native loopback callback도 거부했다. 앞선 HTTP/실제Codex 수락은 동의 API를 직접 호출해 브라우저의 이동 검사를 건너뛰었고 이 결함을 놓쳤다. 원본 사용자 토큰 만료로 단정하지 않는다.

서버의 OAuth/권한/동의/코드 검사는 유지한다. 웹 복귀 검사만 HTTPS 또는 literal `http://127.0.0.1[:1..65535]/callback`으로 제한한다. HTTP 다른host/사설망/IPv6/localhost·IP다른표기/잘못된port·다른path/정규화우회·사용자정보/fragment/공백/역슬래시를 거부한다. 성공/거부 응답 모두 같은 검사다. callback query는 서버가 만드는 code/error/state/iss를 보존한다. 임의 HTTP/와일드카드 허용은 없다.

변경: public/assets/mcp-connect.js, tests/mcp/connect_browser.py, 관련 문서·위키·배포/발행 기록. DB/schema·인증 핵심·nginx/설정/패키지 변경 없음. 지정 REA 원문은 읽기만 하고 시험 수정하지 않는다.

## 검증 계획

실제 JS의 브라우저 이동을 합성API로 확인하고 성공/거부의 literal loopback 및 URL혼동 거부를 시험한다. 실제 운영형 Chromium→정상claude_로그인→실제Codex 승인URL→체크박스/승인클릭→브라우저loopback복귀→실제Codex토큰교환·도구발견·읽기를 별도로 검증한다. 코드교환을 HTTP로 직접 대신하지 않는다. 모델호출/사용자본문쓰기0. 정적lint·자산·위키 검사 후 고정 후보·암호화백업 전체복호화/파싱·모의반영·드리프트/원본보존→운영반영→실제브라우저 확인. 비례회귀는 기존 밤 대기열로 보낸다.

실패시 코드만 이전판으로 복귀할 수 있다. DB복원/삭제·사용자grant취소·시드재생성은 하지 않는다. 검토grant만 정상API로 회수한다. 실제 집Windows 결과는 사용자 확인과 구분한다.

## 후보 결과

- 실제 UI 브라우저81/0: 기존50항목과 native 성공·거부·port없음의query보존6항목, 다른IP/localhost/IPv6/숫자·hex·축약·percent IP/잘못된port/다른path/정규화path/userinfo/fragment/공백/역슬래시/다른scheme25개 거부. 최초실행은 없는 `/usr/bin/chromium`으로 기동실패했으며 설치된Playwright Chromium을 지정해 실행했다. 이후 기본 설치브라우저 사용으로 테스트설정을 보정·실제기동확인했다.
- 실제 운영형 Chromium 수락46/0(`browser-e2e-candidate.json`): 정상claude_employee01 API비밀번호/OTP로그인, 실제Codex가 만든승인URL로페이지진입→업무갱신체크/읽기자동포함→실제승인버튼클릭→브라우저가자체적으로literalcallback이동→실제Codexcode교환/8도구발견/계정·업무목록호출. callback은실측 `http://127.0.0.1:44961/callback`. API동의를직접호출하거나callback을requests로대신열지않았다. 후보JS 한파일만응답대체하고나머지페이지/API는실제운영HTTPS다. 모델호출/본문쓰기0. 시험grant회수·logout·시험Codex자격정리·기존자격보존·nginx/app신규비밀값노출0.
- lint최초는JS RegExp.exec를셸exec로오인했고같은정규식의String.match로보정했다. 금지패턴0, JS구문, 자산43/import77, 위키70/0. 성공한시험을제외조건으로숨기지않는다.
- 운영반영전 백업 `/var/backups/tbox-v2/moa-oauth-browser-return-20261023`: DB+코드/설정AES256암호화·checksum·전체복호화·DB archive파싱·tar2333일반파일전체읽기 통과. 저장원본은기존정기백업정책 유지. 디스크여유12GB.
- 새회귀항목 `community.remote_mcp_connect_browser`를카탈로그에등록했다. 이번수락81을반영전전체회귀로반복하지않으며밤대기열로보낸다. 백엔드OAuth/문서저장코드는변하지않아remote_mcp_http와업무수락묶음 재실행은 제외한다.

증거 디렉터리: `/home/changkwon/qaredi-oauth-browser-results-20261023/`. 별도독립문맥검토는미수행으로유지한다.

## 운영 결과

운영 v2.73.3 `407fda99567e64a861398dc8aee60e0db60f8c3b` / 2026-10-23T15:02:45.167538+09:00. 고정10파일 모의반영·원격/운영drift·백업전체복호화/파싱 후FF. 실제기존103업무표의원래행(제외표 이외 비교항목 모두)·ownerACL·201저장원본·10설정·기존파일owner/mode보존. SQL직접쓰기/DBmigration/설정변경/서비스reload0. HTTPShealth/DB·수정JS정확바이트2/0.

실제 운영JS로 Chromium승인클릭→실제Codexcallback→code교환/8도구·계정/업무목록 호출 **46/0**(`browser-e2e-production.json`), 후보JS응답대체0. 실제callback은 `http://127.0.0.1:40669/callback`. 신규검토grant정상회수·로그아웃·시험Codex자격삭제·기존자격보존·nginx/app신규비밀노출0, 모델/본문쓰기0. 테스트를HTTP직접callback이나성공표시만으로대체하지않았다.

집PC에는기존승인화면이이미소비됐으므로닫고설정→MCP서버→기존moa→인증을다시시작하도록안내했다. 원래사용자승인목록/계정/문서를변경·회수하지않았다. 실제집Windows재인증과원문읽기는여전히확인대기이며서버Chromium통과와구분한다. 독립문맥검토/모바일도미검증이다.

## 후속: Windows 브라우저와 Qaredi의 localhost 불일치

새 사용자 화면은 이동 주소 거부 대신 `127.0.0.1:39381/callback`의 ERR_CONNECTION_REFUSED였다. 실제 요청의 callback과 `ss -ltnp`를 대조하여 Qaredi의 기존 Codex 프로세스가 그 포트를 듣고 있음을 확인했다. Windows 브라우저의 localhost는 Windows이므로 원격 Qaredi listener에 닿지 않는다. 2026-10-23 16:36:25 KST 승인은 memories.read/work.read/work.write였지만 code 미교환·access token 0·미사용이며 2분 만료를 확인했다. 이는 사용자 토큰 만료를 추정한 진단이 아니다. 앞선 서버 Chromium 수락은 같은 호스트 안의 이동이며 이 교차 호스트 경로를 검증하지 않았다.

운영 웹 코드·DB·기존 승인·사용자 문서는 그대로 두고 Qaredi의 `/home/changkwon/.codex/config.toml`에 공식 설정 `mcp_oauth_callback_port = 43173`만 추가했다. 포트가 비어 있음을 먼저 확인하고 기존 설정 전체를 비공개 0600 백업한 뒤 기존 바이트·owner/mode를 보존해 원자 저장했다. 비공개 증거는 `/home/changkwon/.local/state/qaredi-mcp-remote-auth-20261023/`(0700)에 있으며 설정 백업은 Git에 넣지 않는다. 새 실제 Codex app-server의 같은 계정 플러그인 읽기(installed/enabled, moa1/skill1)와 OAuth 요청의 실제 callback `http://127.0.0.1:43173/callback`을 확인했다. 동의/토큰 발급 없이 그 시험 프로세스만 종료했다. 기존 장기 실행 Codex는 종료하지 않았다.

Windows PowerShell에서 다음 명령으로 loopback만 SSH 전달하고 창을 유지한다.

```powershell
ssh -N -o ExitOnForwardFailure=yes -L 127.0.0.1:43173:127.0.0.1:43173 qaredi
```

그 뒤 Qaredi 호스트 Codex가 새 설정을 읽도록 MCP 설정 새로고침/필요 시 원격 호스트 재연결 후 기존 moa 인증을 새로 시작한다. 새 프로세스의 고정 포트 적용만 검증했으므로 기존 장기 실행 프로세스의 재로딩은 완료로 가정하지 않는다. 실제 새 callback이43173인지 확인해야 한다. 포트가 다르면 오래된 코드 URL을 고쳐 쓰거나 인증을 우회하지 말고 현재 인증 요청부터 다시 확인한다. 승인 범위는 위 세 개로 유지한다. 만료된 callback은 재사용하지 않는다. Windows 포트 선점·SSH 전달 성공·사용자 토큰 교환·기존 문서 실제 읽기는 아직 확인 대기다.

원복은 이번 설정 한 줄만 제거하며 이후 사용자 설정 변경을 전체 백업으로 덮어쓰지 않는다. 포워딩은 PowerShell에서 Ctrl+C로 종료한다. 공개 포트/방화벽 개방·callback relay·인증/PKCE/state 완화·계정/권한 변경은 없다. 웹 런타임은 v2.73.3 유지, 웹 배포/서비스 재시작/회귀 추가0. 문서 diff/보안 린트/위키 검사를 수행한다.

공식 근거: [MCP callback 포트 설정](https://learn.chatgpt.com/docs/extend/mcp), [원격 호스트별 설정과 자격](https://learn.chatgpt.com/docs/remote-connections), [SSH localhost 전달 방식](https://learn.chatgpt.com/docs/auth). auth 문서의 OpenAI 로그인 예시 포트는 모아의 실제 포트를 대신하지 않는다.

문서 보충 검증: security_lint 금지패턴0, wiki_check 70편/구조·누락0, git diff --check 통과. 최종 읽기 전용 재조회에서도 위 사용자 승인 code 미교환·활성 access0을 확인했다. 이번 문서/호스트 설정 조치를 사용자 연결 완료로 보고하지 않는다.

## 사용자 OAuth 완료 확인 — 2026-10-23 20:03 KST

사용자가 Authentication complete 화면을 보고했다. 읽기 전용 서버 조회로 20:03:59 승인(Codex, pkdkak1)의 code 교환 완료·활성 access1·memories.read/work.read/work.write를 확인했다. 실제 callback은 `http://127.0.0.1:50539/callback`이며 앞서 준비한43173과 다르므로 이번 성공을 고정 포트/SSH 전달 검증으로 간주하지 않는다. 아직 grant.last_used_at은 없었다.

같은 Qaredi 계정의 새 실제 Codex app-server에서 계정 플러그인1.0.2의 moa를 확인했지만 authStatus=notLoggedIn·tools0이었다. 로그인한 클라이언트의 자격이 이 Qaredi 실행 환경에 연결됐다는 증거는 없으며 현재 도구의 기존 qaredi STDIO 성공으로 대체하지 않는다. 사용자 로그인/코드 교환 성공은 확인됐지만 인증을 시작했던 Codex에서 업무 목록·지정 원문 실제 조회는 아직 대기다. 재인증/토큰 재발급은 요구하지 않고 같은 Codex 대화에서 업무 프로젝트 목록 조회로 이어가도록 안내한다. 기존 계정/권한/자격/문서 수정0, 추가 모델 호출0. 비공개 안전 요약은 user-auth-read-status.json에 저장했다.
