# 모아 MCP·기존 개인 플러그인 복구 — 2026-10-23

- 상태: 계정 플러그인1.0.2 반영·서버/실제 Codex CLI 검증 완료, 집 PC 사용자 연결 확인 대기
- 작업 브랜치: `cloud/moa-mcp-recovery-20261023`
- 기준 main: `a84c62f5441f` (기존 다른 세션의 미게시 문서 커밋 보존)
- 운영 앱: v2.73.2 / `c8c7a28275a5d9942166aa894fefb8ea2a52465b`, 변경하지 않음
- 담당 환경: Qaredi 서버. 집 Windows·실물 모바일 접근 없음.

## 목적과 경계

기존 계정·문서·권한을 유지하면서 Codex의 기존 업무 문서 읽기·갱신을 복구한다. 지정 문서는 `d3ba3bd1-a5bd-4e60-8fa6-5064a3da3cb5`(REA. Ecount 복제 테스트)이며 점검용 쓰기를 금지한다. 읽기·판 번호·can_edit만 확인하고 본문은 보고서/공개 로그에 복사하지 않는다. 새 페이지 생성으로 기존 문서 갱신을 대체하지 않는다. 쓰기 시험은 정상 claude_ 계정의 새 시험 프로젝트/문서에서만 한다.

## 원인과 수정

1. 계정의 기존 개인 플러그인은 여전히1.0.0이었다. 10/18의1.0.1 ZIP 공개만으로 계정 설치판이 바뀌지 않았다.
2. 실제 Codex0.160.0 `plugin/read`에서 원본은 스킬1/서버0. 서버의 `extensions.com.openai.auth`가 있으면 transport만 바꾸거나 native overlay를 추가해도 서버0. 확장 없는 portable 서버는 `moa`1개를 읽는다. 다섯 후보 비교와 최종 패키지 로더로 확인했다. 전체 portable 형식이나 streamable-http 자체가 미지원이라는 뜻은 아니다.
3. 서버에는 필요한 네 도구가 모두 구현·등록돼 있었다. OAuth는 Bearer 전용이며 동의한 범위로 목록을 거른다. 무인증401/invalid_token/challenge는 기존 사용자의 토큰 만료를 입증하지 않는다. 운영 서버의 인증/권한 코드는 수정할 필요가 없었다.
4. 기존 **같은** 개인 플러그인 `Plugin_edeafadb681c81919018eea0d1b96167`을1.0.2로 갱신했다. USER/PRIVATE·이름·설명·표시·기본 프롬프트·아이콘 유지. 예상 release `pluginrel_6ad1e6b0953c8191bd60bb85f884d14c`를 사용한 guarded update 후 `pluginrel_6adaf1595714819188152aa5e50900a6`를 재조회했다. 중복 플러그인 생성0, 공유 변경0.
5. portable `mcp.json`은 이름`moa`·기존HTTPS URL·streamable-http만 유지한다. 서버 OAuth discovery/DCR를 사용한다. 계정 서비스가 호환 `.mcp.json`을 생성한 사실까지 재조회했고 Git 원본에 맞췄다. 고정 토큰/비밀번호 없음. 스킬은 실제 도구 발견, 서버 별칭 오판 방지,401 해석, 기존 페이지 생성으로 우회 금지, 충돌/원문 보존을 보완했다.

## 범위·OAuth·콜백

서버 metadata와 OAuth 검증은 `memories.read`, `work.read`, `work.write`를 모두 지원한다. 현재 OAuth scope 검증은 `memories.read`를 기본으로 요구하고 `work.write`에는 `work.read`를 함께 요구한다. 개인 토큰의 `community.read/community.write`와 원격 OAuth scope 이름을 혼동하지 않는다. 기존 승인을 자동 확대하지 않았다.

원래 portable auth의 base/defaultScopes override는 로더 결함을 유발하므로 제거했다. 새 패키지는 OAuth discovery를 사용하며 명시적인 세 범위 요청/좁힌 동의를 사용한다. 기본 로그인에서 서버의 다른 지원 범위를 보여 줄 수 있으므로 사진·이야기 쓰기·여행을 자동 승인하면 안 된다. CLI 명령은 `codex mcp login <실제 서버 이름> --scopes memories.read,work.read,work.write`다. 이 변경은 인증을 끄거나 모든 범위의 승인을 강제하지 않는다.

이번 실제 Codex가 보낸 `redirect_uri`는 `http://127.0.0.1:43093/callback`이었다. authorize URL에서 직접 수집했고, 정확한 URI·S256·state·iss·resource·정상 비밀번호/OTP·동의·실제 loopback listener·code교환을 확인했다. 새 callback을 추측해 allowlist에 추가하지 않았다. 기존 명시 loopback 옵션과 HTTPS 정확 일치 정책을 유지했다. 집 PC의 실제 callback은 아직 관찰하지 못했다.

## 실제 인증 후 도구 목록

`memories.read work.read work.write` 승인 후 `/mcp`의 실제 `tools/list`와 Codex가 발견한 목록은 다음8개였다.

- `qaredi_whoami`
- `qaredi_list_work_projects`
- `qaredi_list_work_documents`
- `qaredi_read_work_document`
- `qaredi_update_work_document`
- `qaredi_create_work_document`
- `qaredi_list_memories`
- `qaredi_read_memory`

업무 갱신에 필요한 네 도구가 포함됐다. 추가3개는 기존 서버 기능·기본 memories.read의 결과이며 이번에 만들지 않았다. 사용자 원문 대신 생성 도구를 호출하지 않았다. `qaredi_2`의 오래된 세 페이지 도구는 이번 원격 응답에 없었다. 집 PC의 qaredi_2 실제 설정은 이 서버에서 조회할 수 없으므로 그 호스트의 URL/계정까지 확인했다고 주장하지 않는다. 현재 서버의 별도 `qaredi`는 로컬 STDIO/개인 토큰 연결이고 업무 도구5개를 제공한다. 그 성공을 원격 플러그인 성공으로 대체하지 않았다.

## 검증

증거 디렉터리: `/home/changkwon/qaredi-mcp-recovery-20261023/`.

- 기존 지정 문서: 본인용 로컬 MCP로 원본 블록31개, doc_version23, can_edit=true 읽기 성공. 점검 쓰기0. 마지막 재조회에서도 원본 블록 JSON 전체·판23·편집 가능 상태가 정확히 같았다.
- `python3 tests/community/memories_harness.py --suite work_documents_http.py --output .../isolated-work-documents`: **204/0**, 실제 PHP/PostgreSQL 빈 합성 DB. 원본 표/폭/서식/이미지/자식 보존, 판 이력, stale409, 두 워커 동시200/409·판1증가, 타인/읽기 공유/관리자 우회 거부, scope 없는 기존 승인 거부, 토큰 회수 후 거부, 잘못된 입력 전체 rollback. 운영 DB 복사0·테스트 DB 정리 완료.
- `live_acceptance.py`: **94/0**, 운영 HTTPS의 정상 claude_employee01 로그인·OAuth·토큰·서버 API. 새 프로젝트 `6e5b69ef-9791-4f3c-be18-1d736473defc`의 새 문서 `c39e4c83-972e-4903-8802-6169c28421cb`만 읽기→교체/추가→판1→2→재조회. 표/폭/굵게/자식/나머지 문단 정확 보존, 오래된 판409 후 전체 문서 동일. 다른 계정의 지정 원문 읽기는404로 정상 차단.
- 같은 운영 시험에서 refresh scope 확대400/invalid_scope, 정상 rotation과 같은 도구목록, 옛 refresh replay400/invalid_grant 및 관련 새 access401 확인. 새 시험 프로젝트/자식만 정상 API로 휴지통 이동. 시험 grant 회수·logout·시험용 Codex 자격 삭제, 기존 Codex 자격 전체 보존. 실제 비밀번호/토큰/OTP secret/code/state/세션값의 nginx/app 신규 로그 노출0.
- 실제 Codex app-server 시험은 모델 호출0이며 일회성 별도 MCP 설정의 OAuth/도구 로딩이다. HTTP 문서 저장을 모델의 Codex 도구 호출이라고 표시하지 않는다.
- 계정 수정 후 `plugin/list(forceRefetch=true)`→`plugin/read`: 같은 remotePluginId, version1.0.2, installed/enabled=true, 서버`moa`·스킬`work-records`. 별도 MCP 설정 없이 `mcpServerStatus/list`에서 실제 플러그인 서버`moa`, notLoggedIn을 확인했다. 집 PC 캐시는 별도다.
- 이어 `live_plugin_acceptance.py`는 별도 서버 설정 없이 계정 플러그인 자체의 `moa`로 정상 OAuth·실제 Codex 도구8개·업무 목록 호출을 확인했다(모델 호출0). 이때 실제 callback은 `http://127.0.0.1:45359/callback`. 초기 결과는 성공46/정리 실패2를 그대로 보존한다. CLI logout이 플러그인 전용 서버 이름을 찾지 못했으며 이미 서버 grant는 정상 회수됐다. 같은 URL의 일회성 CLI 옵션을 준 지원 logout으로 시험 자격1개만 삭제하고 다른 자격 보존/파일 부재를 확인했다(`account-plugin-cleanup.json`). 이 정리 보강을 위해 OAuth 시험 전체를 다시 실행하지 않았다.
- 실제 Codex 도구 호출 경계까지 추가 검증했다. `codex_tool_acceptance.py`: **78/0**, 기존 계정 플러그인1.0.2/실제서버`moa`/별도서버설정0, ephemeral thread에서 `mcpServer/tool/call`로 계정·프로젝트·직계목록·원본읽기→문구교체+추가→재조회→stale409→재조회를 직접 실행했다. 새 시험문서 `353c723e-3b3b-42e1-9de3-501d230f5ce1`만 판1→2, 표/폭/서식/자식/다른문단 불변·충돌후전체불변. 실제callback `http://127.0.0.1:38219/callback`, 모델 호출0·지속 대화 생성0. 새 검토 프로젝트/자식만 휴지통 이동·grant회수·시험자격삭제·기존자격보존·비밀로그0을 확인했다. 이는 HTTP만의 시험과 달리 실제 Codex가 도구를 호출한 증거이며 집 Windows의 사용자 인증과는 여전히 구분한다. 앞선 정리 실패 원본은 덮어쓰지 않았다.
- `clients/plugins/build.py`의 전체ZIP은 여섯파일·9726bytes·SHA256 `4a1664ab6d0236a4b6cbc0a22391a176f7f84983500fc85a7d005693e25bbd9d`. 계정 update는 다섯 수정 텍스트만 포함한 별도5195bytesZIP·SHA256 `9144bd6105aeec6c94f2e174697c6f7cf9b0d3f9fe94f763b950687a2855e88a`로 기존 아이콘을 보존했다. 전체ZIP을 운영 다운로드에 새로 게시하지 않았다.

## 서버 적용과 보존

웹 runtime/DB migration/패키지/설정/cron/nginx/FPM 변경·재시작0. 기존 계정·ACL 변경0, 기존 사용자 문서 쓰기0. 새 검토 자료·정상 OAuth/로그인 감사 기록은 생성됐으므로 DB 전체 무변경이라고 말하지 않는다. 운영 DB 직접 SQL쓰기/삭제/재시드/복원0. 이 기능에는 런타임 배포·DB백업 복구가 필요하지 않으며 계정 플러그인 release만 갱신했다. 원본1.0.0 텍스트·releaseID와 수정 후 원본을 로컬에 보존했다. 실패 시 기존 계정 플러그인의 같은ID 새 release로 되돌릴 수 있으며 사용자 문서·DB를 복원하지 않는다.

## 사용자 조작과 미검증

1. ChatGPT에서 플러그인 → 모아 커뮤니티 기록 → 새로고침. 버전1.0.2 확인. 해당 화면: https://chatgpt.com/plugins/Plugin_edeafadb681c81919018eea0d1b96167
2. 집 PC 앱 완전 종료·재실행, 새 Codex 대화의 `/mcp`에서 실제 모아 확인. 로그인 필요 시 해당 연결의 로그인으로 모아 본인 계정 승인. 세 범위만 선택. CLI 로그인 시 실제 이름과 위 scopes 명령 사용.
3. 네 업무 도구가 노출되면 지정 주소 문서를 읽게 한다. 처음에는 쓰기 요청 없이 제목·판 번호만 확인한다. 정상 읽기 확인 후 실제로 원하는 문구 갱신을 요청한다.

현재 대화의 실행 도구 목록·집 PC 앱의 도구 목록은 계정 release 저장만으로 소급 교체되지 않는다. 사용자가 보낸2026-10-23 집 PC 화면에서 `moa · 사용함 · 인증되지 않음`이 확인됐고, 이어 본인pkdkak1 계정의 정상 Codex 연결 동의 화면에 진입한 것도 확인됐다. 서버 미등록 상태는 해소됐지만 승인 후 OAuth완료·실제 집Windows의 기존문서 도구호출, 모바일, 별도 독립문맥 검토는 미검증이다. `업무 문서 갱신`과 `업무 프로젝트·문서 읽기`를 선택하고 기본 `내 글 읽기`를 유지하며 나머지는 선택하지 않도록 안내했다. 서버의 성공으로 실제 집 PC의 완료를 대신하지 않는다.

## 최종 정적 검사

수정 원본은 계정 read-back의 다섯 텍스트 파일과 일치한다. `python3 clients/plugins/build.py`의 여섯파일 구성·JSON/아이콘 참조·고정서버/URL·반복메타데이터 일치 확인, `python3 tests/mcp/plugin_loader.py` 실제 원본0/수정1, Python compile, `bash scripts/security_lint.sh` 금지패턴0, `php scripts/community_asset_version.php` 파일43/import77/변경0, `php scripts/wiki_check.php` 글70/문제0, `git diff --check` 통과. 웹기능/인증핵심/SQL을 바꾸지 않아 같은 업무 수락시험이나 무관한 웹회귀를 다시 실행하지 않는다.
