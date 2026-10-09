# 트레이 비서의 공용 Gemini 연결과 T-box 배포함

- 상태: 서버 격리 검증 완료·운영 배포 대기 (완성 ZIP 미게시)
- 브랜치: `cloud/buddy-server-contract`
- 기준 main: `748d06a8d88eca8af8a0103d38416fdcb9d463a9`
- 담당: 서버 Codex. Windows 원본 보존·통합·실물 검증은 별도 로컬 세션 담당.

## 목적과 범위

기존 로컬 AI 비서의 트레이·Ctrl+Alt+Space 동작에 기본 Gemini와 명시적 GPT 선택을 통합한다. 서버는 기존 Google 설정·동의·사용 한도를 개인 `assistant.run` 토큰으로 재사용하는 전용 API를 제공한다. GPT 계정·토큰을 서버로 보내거나 다른 제공자로 자동 전환하지 않는다. 운영 Google 프로젝트의 과금 설정 자체를 검증한 것은 아니며 무료 사용을 보장하는 기능은 아니다.

사용자는 Git 다운로드 대신 T-box 다운로드를 요청했다. F-67 배포함은 기존 기획이며 아직 코드·스키마가 없다. 일반 실행 ZIP 제한을 풀거나 파일을 위장하지 않는다. 최소 배포함은 관리자 확인·비밀번호·OTP, 기존 접근 등급, clean 검사, 해시·버전 표시, 세션 전용 받기를 유지한다. 로컬에서 검증 ZIP과 SHA256을 받기 전 실제 프로그램을 게시하지 않는다.

수정 허용: `public/index.php`, `src/assistant/Chat.php`, `src/handlers/assistant_chat.php`, `tests/assistant/{serve.py,chat.py,chat_fixture.php}`, 관련 API/ARCHITECTURE/PRD/DESIGN/SECURITY/STAGES·위키·이 문서. 배포함 추가 파일·보안 핵심 변경은 구현 전 아래에 명시한다. DB/패키지/외부 HTTP 변경 없이 Gemini 연결을 먼저 검증한다. 루트 `/var/www/qaredi`와 PC 원본은 수정하지 않는다.

## PC Gemini 계약 v1 (구현된 고정 규격)

- 주소: 사이트의 `/tbox/api/assistant/chat`. HTTPS, 같은 origin만 허용하고 redirect를 따라 토큰을 보내지 않는다.
- 인증: `X-API-Token: <개인 assistant.run 토큰>` 단독. 파일용 토큰·공용 토큰·브라우저 쿠키 불가. GPT 페어링 코드와 다른 자격이다.
- `GET` (query 없음): `{provider:"gemini",model:"gemini-3.5-flash",user_id:int,ready:bool,consent_required:bool,consent_url:"/tbox/consent",remaining:int,user_daily:int,max_text_chars:4000}`. ready는 설정·키·동의 상태이며 쿼터/동시 제한은 POST에서 최종 판단한다.
- `POST`, JSON 정확히 세 키: `{"request_id":"소문자 UUIDv4","text":"질문 1~4000자","thread_id":null}`. 다음 질문만 응답의 정수 thread_id를 넣는다. 새 대화·사용자/서버 변경은 null. GPT 대화 기록을 넘기지 않는다. 전체 본문 16384바이트 이내.
- HTTP200 `{provider,model,user_id,turn:{id,thread_id,request_id,prompt,answer,state,created_at,finished_at,error,error_code}}`. state는 `complete|failed|pending`. 공급자 실패도 저장된 turn이므로 HTTP200/failed일 수 있다. pending은 HTTP202. answer는 문자열/null. error/error_code는 실패 때만 문자열, 그 외 null.
- `GET ?request_id=<같은 UUIDv4>`: 본인 요청 결과만 위와 같은 응답. 없으면404. 전송 타임아웃에는 새 UUID로 자동 재전송하지 말고 이 조회로 복구한다. pending은 간격을 두어 조회한다. 서버 공급자 대기는 최대55초, PC 읽기 제한은75초 이상 권장. 120초 넘은 pending은 failed/interrupted로 표시한다.
- POST의 같은 UUID/같은 내용은 기존 결과를 돌려준다. 같은 UUID/다른 내용은409 `request_conflict`. PC는 실패/불확실 때 자동 재시도·유료 모델 전환을 하지 않는다.
- 핸들러 오류: `{error:"한국어 설명",code:"기계 코드"}`. invalid_request400, not_found404, request_conflict409, user_limit/site_limit429, busy409/site_busy429, unavailable/storage503, authorization_lost403. ai_consent_required409는 notice 추가; 사용자가 웹 `/tbox/consent`에서 직접 동의한다. 토큰·역할·일반 rate limit 등 공통 관문은 `{error:문자열}`일 수 있어 code 누락도 처리한다. 401/403이면 토큰 재설정 안내,429면 자동 재전송 없이 대기 안내.
- 기존 웹과 같은 개인/사이트 사용량·최근 동일 대화10턴을 사용한다. 파일·PC·메신저 자동 접근이나 도구 실행은 없다. `/api/assistant/runs`의 메타데이터 도구 계약은 바꾸지 않는다.

## 검사·적용 계획

합성 PostgreSQL/HTTP로 성공·실패·동의·권한·횟수·멱등·타인 격리를 검증하며 실제 Google 요청은 하지 않는다. 기존 assistant 및 공통 교차 회귀·보안/자산/위키 검사를 수행한다. Windows 실제 동작과 실공급자 응답은 로컬 세션 결과와 구분한다.

서버 적용: 격리 검증 → 암호화 백업/복호화 확인 → 후보 고정·모의 적용·드리프트 검사 → 코드/필요 migration → HTTPS 검증·기존 데이터 보존 → 배포 기록. 실패 시 이전 코드로 복귀 가능한지 확인하며 운영 DB 삭제·복원은 하지 않는다.

## 배포함 최소 구현 지시 (T:F-67)

허용 파일 추가: `db/schema.sql`, `db/migrations/2026-10-09_program_distribution.sql`, `src/Programs.php`, `src/handlers/{programs_page.php,programs.php,files_exec_download.php}`, `src/views/{shell_top.php,member_local_buddy.php}`, `src/Audit.php`, `scripts/alerts.php`, `public/assets/programs.js`, `tests/assistant/{programs.py,program_fixture.php,serve.py}`, 관련 검사·위키·발행/배포 기록.

최소 제품은 tbox-buddy 하나. 관리자가 기존 파일 올리기에서 F-65 절차로 root/admin-only에 올린 실행 ZIP을 검사 완료 뒤 `/programs`에서 선택한다. 버전·SHA256·배포 등급(public/internal/team/admin-only)을 확인하고 비밀번호·OTP를 다시 제출해 게시한다. 업로드 검사를 우회하는 새 바이너리 입구는 만들지 않는다. `files.program`, 버전, 현재 판 표식, 게시 시각을 더하고 기존 바이트·소유자·해시는 유지한다. 배포 파일은 exec_content=true를 유지해 기존 공유·토큰·미리보기·일반 다운로드/수정 제한이 계속 적용된다. 새 세션 전용 다운로드는 사용자 경고 확인+CSRF·Authz·clean·해시 대조를 거치며 다운로드 OTP는 요구하지 않는다. 같은 제품의 새 판 게시 때 이전 판의 current만 false로 바꾸며 삭제하지 않는다. 목록/받기는 현재 판만, 관리자는 본인이 올린 준비 파일을 볼 수 있다. 검사 비활성 환경에서는 게시 거부한다. 감사 program.publish/program.download, AL-21 포함. 페이지·API는 세션 전용이며 개인 파일 토큰과 공유 링크로 받을 수 없다.

## 구현·검증 기록

구현된 계약은 위 v1과 같다. 기준 URL은 `https://changkwon.mso.so/tbox`; 호출은 `https://changkwon.mso.so/tbox/api/assistant/chat`. 로컬 사용자의 assistant.run 토큰은 로컬 설정에서 직접 등록하며 서버 세션이 발급해 채팅으로 전달하지 않는다.

배포함은 `/tbox/programs`, 기존 파일 메뉴와 모아 PC 안내에서 진입한다. 프로그램을 일반 파일로 위장하거나 기존 업로드 제한을 완화하지 않았다. `SCAN_ENABLED=1`뿐 아니라 해당 파일 SHA256의 `file.scan allow` 감사 기록도 요구한다. 한 제품 최소 구현이며 다제품 카탈로그는 별도 범위다.

증거 디렉터리: `/home/changkwon/qaredi-buddy-server-results-20261009/`. Gemini38, 배포함45(실제 합성 ZIP 업로드·브라우저 받기 포함), assistant 단위52, 기존 assistant HTTP376, 모아 안내/연결73, 공통 회귀164를 검사한다. 최종 성공 실행은 아래 결과에 기록한다. PHP12/JS/보안린트/자산/위키/diff 검사를 수행한다.

초기 실패도 보존했다: 새 화면에서 Http::url('')를 호출해 본문 중단 → '/'로 수정. 나머지는 격리 시험 구성 오류(루트 folder_id=0, 기존 재인증 관문 미충족, 테스트 URL 오타, PHP 멀티워커와 Chromium 연결 대기, 배포함 시험이 기존 파일 개수 단언 전에 파일을 추가함)였으며 검사를 생략하지 않고 수정/분리했다. 브라우저는 별도 단일 워커에서 실제 PHP/정적 자산을 사용하고, 기존 회귀를 새 파일 추가 전에 수행한다. 원본 운영 데이터·실행 제한을 바꿔 통과시키지 않았다.

최종 검증: `accepted/` Gemini38/0·배포함45/0·기존 assistant HTTP376/0, 단위52/0. `cross/` 공통164/0, `moa2/` 안내/연결73/0. PHP12·JS·보안린트·자산버전·위키·diff 검사 통과. 공급자 호출은 합성 응답뿐이며 운영 프로그램 게시0.
