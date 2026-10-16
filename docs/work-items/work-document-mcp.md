# 업무 프로젝트 문서 MCP 갱신

- 상태: 서버 배포·실제 HTTPS 검증 완료 (원격 ChatGPT 연결 비활성)
- 작업 브랜치: `codex/work-document-mcp`
- 기준 main: `4d3e13097970d1864d14c96880671a27a25bb9bf`
- 환경: 서버의 격리 작업본, 운영 원문 시험 금지

## 목적과 범위

2026-10-16 사용자 「업무 프로젝트에 글을 업데이트해가며 개발 과정, 기능설명들을 기재」 → 「진행해」. 프로젝트 찾기, 하위 문서 목록, 원문/판 번호 읽기, 지정 블록의 문구 교체, 문서 끝에 개발 기록 추가를 로컬 stdio MCP와 웹 OAuth MCP에 제공한다. 새 문서 생성·삭제·이동·공유 변경·자동 배포·봇 예약 실행은 범위 밖이다. 문서는 웹에서 먼저 만들고 주소를 지정한다. 점검봇 연결은 이 문서 갱신 계약 다음의 별도 작업이며 이번에 봇을 실행/배포하지 않는다.

웹 MCP는 별도 `work.read`/`work.write` 동의. 기존 승인에는 새 권한을 자동 추가하지 않는다. 로컬 MCP는 기존 개인 토큰 `community.read`/`community.write`로 새 업무 전용 창구에 접근한다. 옛 페이지 생성 제한은 유지한다. 프로젝트/문서 목록은 기존 검색 권한 조건절(관리자 특권 제외), 본문은 같은 조건과 기존 행 판정, 수정은 기존 `community_save_doc` 경로를 쓴다. 게시판/게시된 문서는 제외한다.

본문 전체 교체·HTML·원격 첨부 가져오기 없이, 읽은 `doc_version` 필수와 블록 ID/기존 문구를 대조한다. 모든 변경은 한 트랜잭션, 충돌은 409이고 자동 재시도하지 않는다. 대상 밖 블록·표·그림·서식·부모·공간·공유·소유자 불변. 각 MCP 수정은 이전 판을 남긴다. 입력/본문/토큰은 추가 로그에 기록하지 않는다.

수정 허용: `src/community/WorkDocuments.php`, `src/community/SaveDoc.php`(MCP 오류 반환만), `src/community/routes.php`, 새 업무 문서 핸들러, `src/mcp/{McpOAuth,McpTools,RemoteMcp,dispatch}.php`, MCP 연결 화면/JS, 로컬 MCP client/server/tests, 관련 격리 HTTP 시험/하네스/카탈로그, API/ARCHITECTURE/SECURITY/STAGES/위키/안내/발행 및 운영 기록. DB/인증 핵심/Doc 스키마 변경 없음.

## 검사

수락: 프로젝트/하위 목록 페이지 넘김, 원문 읽기, 문구 교체+기록 추가, 서식/그림/표 불변, 버전 이력, stale/동시 요청, 중복 문구/모르는 키/블록/긴 입력 거부, 개인/팀/공유 편집/공유 읽기/비회원/관리자 특권/삭제·보관 거부, 세션 CSRF·개인 토큰 범위·OAuth 동의 범위, 정상 MCP 오류 형식, 감사 귀속. 실제 PHP+합성 DB로 검증한다. 정적 검사 및 로컬 client 검증. 비례 회귀는 밤 대기열.

## 서버 적용

- DB migration·패키지·cron 변경 없음. 웹 원격 MCP 활성화/실제 ChatGPT 연결은 기존 상태와 구분한다.
- 고정 후보/원격 드리프트 → 암호화 백업·복호화 → 모의 반영 → 코드/필요 FPM 반영 → 실제 HTTPS·기존 데이터 보존 확인.
- 실패 시 서비스/데이터 보존, 임의 DB 복원/삭제 없음.

## 배포 결과

v2.70.0 운영 반영과 실제 HTTPS 검증을 마쳤다. 최종 수치와 남은 연결 항목은 아래 운영 결과를 따른다.

## 수락 결과 (2026-10-16)

- 최종 실제 PHP·빈 합성 DB HTTP/OAuth/개인토큰/로컬 client: **201/0** (`acceptance-final/work-documents-results.json`). 앞선 기본 흐름181/0 후 canonical 검증 실패·팀·권한 회수 항목을 확장했다. 두 워커 동시 요청200/409·문서판1증가, 표/그림/자식/서식 보존, 이전 판, 읽기 공유403, 관리자 특권404, 기존 generic PATCH 토큰401, 기존 POST 위치400 확인. 운영 사용자 문서 수정0.
- 로컬 client SDK1.12.4 실제 설치는 `/tmp/qaredi-work-mcp-sdk-20261016/packages`에 한정. 앱 패키지 변경0. client suite **114/0**, SDK 없는 부품 검사도114/0. 로컬 배포 패키지0.2.0, 옛 패키지의 스키마 사본 불일치를 현재 웹 원본으로 동기화(새 스키마 기능 추가0).
- OAuth 단위141/0·기존 도구61/0·프로토콜94/0. 연결 UI **50/0**, 합성 API/PC1280·모바일390이며 실제 ChatGPT 연결 검증이 아니다.
- 최초 수락의 잘못된 API prefix·fixture 필수 marks/image width·token hint 길이 실패를 보존. 구현에서 새 문단 marks 누락도 보완했고 이후 최종 통과. 초기 client catalogue 기대8개를12개로 갱신. 기본 Chromium 실행파일 부재는 설치된 Playwright 브라우저로 해결. 시스템 venv ensurepip 부재로 전역 패키지를 바꾸지 않고 임시 SDK 경로를 사용했다.
- 증거 `/home/changkwon/qaredi-work-document-mcp-results-20261016/`. 보안 린트0, 위키70/0, 자산42/75 정상. 별도 컨텍스트 독립 검토는 수행하지 않았으며 본 세션의 코드 검토와 실제 검증으로 구분한다.
- 야간 회귀 계획: core.qf8_token_write, core.qf10_multi_scope, community.stage10_suggestions, community.remote_mcp_http. 토큰 허용 경로·저장공통경로·기존 원격 도구 영향으로 선택. 새 기능 수락/전체 UI/무관한 업로드·검색 부하 시험은 반복하지 않는다.

## 운영 결과

운영 v2.70.0 `1b27b82b276145338f715e27a2a1324c592d7f18` / 2026-10-16T16:23:31.556113+09:00. 고정35파일 모의 적용·암호화 DB/코드·설정 백업 전체 복호화·DB/tar 파싱·서버/원격 drift 검사 뒤 FF/FPM reload 완료. HTTPS health/자산3개와 신규 검토 문서 기반 실제 기능17개 통과. 새 QA 문서1개에 문구교체+기록추가/서식/409/이력 확인 후 그 문서만 휴지통 이동. 기존 업무 자료 수정0. 반영 직후 기존 100표 모든 원래행/ownerACL·원본798파일·설정10파일 보존. HTTPS 뒤 인증 상태가 바뀌는 users를 제외한 원래행·파일도 다시 보존 확인. 검토 세션 회수/쿠키 제거 완료. DB/패키지/설정/cron 변경0. 원격 MCP 실제 status=disabled, 활성화/실제 ChatGPT 계정 연결/실물 모바일은 미검증. 로컬 MCP 소스0.2.0 배포, 사용 PC의 설치·재시작은 별도. 실제 업무 대상 문서 지정과 점검봇 자동 게시는 후속. 회귀4개는 밤 대기열.

## 2026-10-16 실제 계정 연결 중 검증 정정

실제 stdio 초기화에서 SDK1.12.4/Pydantic2.14.1 호환 오류를 확인했다. 앞선 「SDK 실제 설치114/0」은 SDK 부품 설치 사실만 확인했고, test_server.py가 ImportError까지 흉내 서버로 바꾸므로 실제 SDK 실행 증거가 아니었다. HTTP201/0·UI50/0·운영HTTPS17/0의 별도 증거는 유지한다. 후속 token-scope-layout에서 호환 버전 고정, 시험의 숨김 fallback 제거, 실제 SDK import·단위 검사·stdio 계정/문서 호출로 보완한다. 검증 결과는 그 후속 기록을 따른다.
