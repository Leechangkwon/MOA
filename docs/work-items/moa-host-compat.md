# 집 PC ChatGPT Codex·Work의 모아 연결 보정 — 2026-10-18

## 요청과 기준

사용자가 집 PC의 ChatGPT 앱 안 Codex·Work에서 같은 플러그인을 지정했으나 실제 업무 조회·저장 도구가 없고 `unknown MCP server 'moa'`가 발생했다고 재보고했다. 기준 main `2f88565cd036996ee43b1c3ff72b84ea11b8b2aa`, 운영 웹 v2.73.1 `9ce41657dde002c4237df60bd00ff3c2701acfba`. 기존 Qaredi STDIO 연결은 다른 호스트의 연결 증거가 아니다.

## 재현과 변경 범위

- 실제 codex-cli 0.160.0 app-server `plugin/read`에서 원본 portable ZIP 및 설치 캐시는 스킬 1개·서버 0개. 같은 파일의 Codex native manifest와 discovery 설정으로 서버 1개. transport 문자열만 바꾸면 여전히 0개. portable 최소 서버 설정은 인식되지만 서버 `extensions`를 추가하면 빈 객체여도 누락되므로, 전체 portable 형식을 지원하지 않는다는 주장 대신 서버 확장 설정의 native 로더 호환 결함으로 구분한다.
- 실제 Codex OAuth DCR 시도는 HTTP400 `invalid_redirect_uri`. 현재 운영 허용 목록은 정확한 ChatGPT HTTPS callback만 포함한다. native loopback 복귀는 아직 지원하지 않는다.
- 기존 플러그인 이름·표시·프롬프트·아이콘·기능 범위를 보존한 native 1.0.1 패키지로 변환한다. 계정의 설치 상태를 자동 변경한 것으로 보고하지 않는다. 기존 portable 패키지/캐시는 보존한다.
- OAuth에는 별도 `MCP_OAUTH_LOOPBACK_ENABLED=0` 기본 닫힘 옵션을 추가한다. 운영에서 명시적으로 켠 경우에만 문자 그대로 `http://127.0.0.1[:port]/callback`을 허용한다. RFC8252에 따라 등록 client와 승인 단계의 포트 차이만 허용하고 host/path/query/fragment/userinfo 변경은 거부한다. 실제 승인 URI와 code 교환 URI의 완전 일치·S256·issuer/state/resource·범위·동의는 유지한다. 기존 HTTPS 목록은 정확 일치를 유지한다. localhost/다른 127.*·IPv6·사설망·임의 HTTP callback·wildcard·URL fetch는 추가하지 않는다.
- 도구를 실제로 발견해서 호출하도록 스킬을 보완한다. 임의 `server='moa'` 리소스 조회의 오류를 서버 장애로 해석하지 않는다.

## 검증·반영 계획

원본/수정본 실제 로더 비교, callback 우회/기본 닫힘 단위 검사, 격리 PHP/PostgreSQL OAuth 수락, 보안 린트/자산/위키 검사 후 고정 후보를 반영한다. 암호화 코드·설정·DB 백업의 전체 복호화/파싱, 모의 적용, 원격/운영 drift, 원래 행·owner/ACL·저장 파일·설정 보존을 확인한다. DB migration/새 패키지/방화벽·nginx 변경은 없다. 운영 반영 뒤 정상 claude_ 검토 계정으로 실제 Codex OAuth와 업무 읽기를 확인하고 검토 연결을 회수한다. 사용자 문서는 시험으로 수정하지 않는다.

회귀는 기존 밤 회귀에 합류하고, 실제 집 Windows·사용자 로그인·모바일·별도 문맥 독립 검토는 각각 미검증으로 구분한다. imported MCP ZIP은 Desktop only이며 웹/모바일 연결 완료가 아니다. 잘못된 적용 시 새 loopback 옵션만 끄고 이전 코드로 복귀할 수 있으며, 사용자 DB 복원/삭제/재시드를 하지 않는다.

## 결과

후보 검사: OAuth141 + loopback94×3=423/0, guard24/0, 실제 격리 PHP/PostgreSQL823/0. 실제 Codex 로더는 원본 확장 구성 서버0·native 수정본 서버1/스킬1. 보안 lint0, 자산43개/상대import77개·위키70편/0, Python compile·diff check 통과. 사용자 계정의 실제 소유 개인 플러그인 ID를 조회했으며 설치판은1.0.0이다. 제공된 계정 공유 체크아웃 기능은 이 imported 플러그인을 지원하지 않아 계정 업데이트는 수행하지 않았다. 새 ZIP은 기존 ID의 새 버전 업로드용이며 계정 배포 완료로 보고하지 않는다.

ZIP: native1.0.1, 8,290bytes, SHA256 `efc46d2cfb1a3dba03391af6e729a6984b6e0deb95fdcd20c3527eaf05baa8f5`, 고정4파일·원본아이콘·프롬프트 보존/비밀0. 공개 다운로드는 `/tbox/assets/plugins/moa-community-records-1.0.1.zip`에 포함한다. 암호화 백업 `/var/backups/tbox-v2/moa-host-compat-20261018`: 전체 복호화·DB archive 파싱·tar 일반파일2318개 전체읽기 완료. 실제 배포 결과는 반영 뒤 덧붙인다.
