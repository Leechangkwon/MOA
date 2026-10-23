# Codex 승인 후 브라우저 복귀 차단 수정 — 2026-10-23

- 상태: 후보 검증 완료·운영 반영 준비
- 기준 main: `d0fc2d2a8471`
- 브랜치: `cloud/moa-oauth-browser-return-20261023`
- 현재 운영: v2.73.2. 개인 플러그인1.0.2는 유지한다.

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
