# 비서 실행 MVP 인계 — 2026-10-01

## 1. 구현·계약·검증 결과

운영 v2.40.1, `a39ec1542412533df19a1a1f8fa703cc1ab47ab7`에서 독립 사본 `/home/changkwon/qaredi-assistant-mvp-20261001/tbox-v2`, 브랜치 `codex/assistant-mvp-20261001`에 구현했다. 커밋/스테이징/push/운영 적용은 하지 않았다. 운영 Git main과 원격 main이 같은 커밋이고 운영 작업 트리는 깨끗하다. 기존 표 편집 인계의 패치2개/앱 파일29개/사이트 문서4개 해시도 보존했다.

| 변경 파일 | 결과 |
|---|---|
| `src/assistant/*.php` | 엄격한 요청/오류, 두 읽기 도구, 공급자 구분, DB 상태/이벤트, 사용자 소유권, 멱등·승인·취소, 리스 워커 |
| `src/handlers/assistant_runs.php`, `public/index.php` | API 여섯 창구, 개인 토큰 전용, 유한 SSE/재개/권한 회수 |
| `src/Authz.php`, `src/Http.php`, `src/Audit.php` | 독립 assistant.run, 기존 권한 유지, 해당 API의 구조화 오류, 상태 변경과 감사의 같은 트랜잭션, 등록된 거부 문장 |
| `scripts/assistant_worker.php` | --once/--drain CLI. GET 요청은 실행하지 않음 |
| `db/{schema,grants,assistant}.sql`, 새 마이그레이션 | 실행/append-only 이벤트, 인덱스/제약/최소 권한. 임시 DB에만 적용 |
| `.env.example`, `public/assets/app.js` | 기본 비활성 설정, 토큰 설명 한 줄. 새 CSS/화면 없음 |
| PRD/ARCHITECTURE/API/SECURITY/STAGES·docs | 요구사항·허용 목록·계약·보안 범위 |
| `tests/assistant/*`, `tests/catalog.json` | 합성 DB·PHP·실제 requests 수락 시험, 테스트 어댑터, 시험 등록 |

정확한 전체 목록과 sha256은 외부 `handoff.json`, 적용 가능한 수정은 `assistant-mvp.patch`에 있다. `.env`, 인증 파일, 운영 기록은 패치에 넣지 않았다.

### 연동 가능한 경로

Base `/tbox`, 개인 `X-API-Token` assistant.run. POST `/api/assistant/runs`; GET 목록·`/{id}`·`/{id}/events`; POST `/{id}/cancel`·`/{id}/confirm`. 요청·성공/오류·인증/발급은 [최소 계약](./assistant-minimum-contract.md), 실행 환경은 [전체 계약](./assistant-contract.md).

mode=tool은 files.search/files.evidence 두 개만 실제 실행한다. 둘 다 파일명·설명 메타데이터이며 `content_read:false`, `inference_available:false`. 파일 다운로드에는 기존 파일 토큰을 쓴다. mode=chat은 **503 provider_unavailable이며 run_id도 생성하지 않는다**. 운영 .env/세션 환경의 LLM 관련 설정 이름은 없고 Ollama는 비활성이다. 실제 외부 공급자 요청0, 비밀값 출력0. 테스트 어댑터는 flag+임시 DB 접두가 모두 맞을 때만 별도 test 표기로 동작한다.

같은 사용자/키/정규화 본문은 같은 실행, 다른 본문은409. 다른 사용자에게 같은 키는 독립 실행이다. Admin도 남의 실행을 읽지 못한다. 현재 파일 권한으로 GET/list/events의 근거를 다시 구성하므로 접근이 사라진 이름·설명·파일 ID는 재생하지 않는다.

cancel_requested는 취소 접수, cancelled는 워커가 확정한 완료다. 승인 ID/계획 해시/소유권/기한/상태/상반된 결정은 잠금 안에서 검사한다. 이미 처리한 동일 승인은200 현재 상태만 반환한다. 취소 후 같은 옛 승인 재송신도 작업을 재개하지 않는다. 발행한 approval은 결정 이력으로 유지하므로 새 승인 UI는 status=awaiting_confirmation에서만 연다.

워커는120초 리스, 최대3회 획득, skip locked, 이전 리스 소유자 차단을 사용한다. 원래 토큰/사용자/강제 절차/파일 권한을 단계마다 다시 검사한다. SSE는 최대10초, 순번 저장/Last-Event-ID, heartbeat/retry, 중간 회수 시 stream.error 후 종료다.

### 실행한 명령과 출력

출력 원문은 `/home/changkwon/qaredi-assistant-mvp-20261001/results/`와 `/home/changkwon/qaredi-assistant-mvp-20261001/mvp-run.log`에 있다. 첫 두 시험 실패는 테스트의 CLI 문자열/계약 오류 코드 기대값을 고쳐 해결했으며 서버 성공을 위장하지 않았다. 마지막 상태의 결과만 아래에 집계한다.

```text
ASSISTANT_TEST_OUTPUT=/home/changkwon/qaredi-assistant-mvp-20261001/results bash tests/assistant/mvp.sh
ASSISTANT_UNIT_CHECKS=52
SYNTHETIC_DB_CREATED=1; MIGRATION_REPEAT_IDENTICAL=1
ASSISTANT_HTTP_CHECKS=376; FAILURES=0
OWNED_TEST_PROCESSES_STOPPED=1; SYNTHETIC_DB_DROPPED=1

bash scripts/security_lint.sh
결과: 통과 (금지 패턴 0건)

bash tests/community/asset_version.sh
통과 37 · 실패 0

php -l <변경 PHP17개> / node --check public/assets/app.js / bash -n tests/assistant/mvp.sh
Python AST2개 / tests/catalog.json 파싱 / git diff --check
SYNTAX_VALIDATIONS=22

R1/R2/R15/R16/R20/R5/R9 정적 규칙: 후보와 수정 없는 기준 판 대조
new_findings=[] (7개 모두)

git -C /var/www/qaredi/tbox-v2 status --porcelain
출력 없음
git -C /var/www/qaredi/tbox-v2 rev-parse HEAD
a39ec1542412533df19a1a1f8fa703cc1ab47ab7
git -C /var/www/qaredi/tbox-v2 ls-remote origin refs/heads/main
a39ec1542412533df19a1a1f8fa703cc1ab47ab7 refs/heads/main
```

PG14.24/PHP8.1/Python requests2.25.1로 **운영 덤프 없이** 합성 자료만 만들었다. 소유권/독립 범위/실제 MFA 발급/파일 배열·다운로드 회귀/멱등 병렬 요청/커서/현재 파일 권한/승인·취소/토큰 회수·만료·계정 관문/SSE/리스 재시작/한도/감사/DB 최소 권한을 확인했다. 마이그레이션 두 번의 제약·인덱스가 같았다. 전체 정적 규칙에는 기준 판에도 있던 발견이 남아 있으므로 전부0건이라고 주장하지 않는다. 비교 원문은 static-baseline-comparison.json 및 candidate-/baseline- 로그다.

실제 JSON/SSE는 results/create-search.json, create-evidence.json, get-queued.json, get-succeeded.json, get-awaiting-confirmation.json, confirm-approved.json, cancel-requested.json, provider-unavailable.json, events-search.sse. 토큰 발급 응답 샘플은 token/hint를 뺀 sanitized 파일이다.

## 2. 연결 환경·후속 요구·남은 확인

### 로컬 실제 연결 시험

운영과 분리한 합성 DB/읽기 도구만 제공하는 localhost 서버를 열었다. Base **http://127.0.0.1:8498/tbox**, 자동 워커, 테스트 공급자 비활성. **2026-10-01 17:06:08 KST**에 종료하며 자신의 PHP 프로세스·DB·인증 파일만 제거한다. Windows에서는 기존 SSH 연결의 `-L 8498:127.0.0.1:8498` 전달을 사용한다.

인증은 `/home/changkwon/qaredi-assistant-mvp-20261001/connection-test/private/assistant-test-auth.json`(0600)에만 있다. base_url/token/file_token/가짜 파일 ID를 읽는다. 토큰 값은 채팅/로그/명령 인자로 출력하지 않는다. 샘플 DB의 ID는 results의 종료한 DB와 다르므로 연결 환경 파일에서 읽는다.

연결 서버에서도 실제 requests로 검색 성공·events·계획 승인 뒤 성공·취소 접수 뒤 완료·근거 조회·공급자503을 확인했다. 새 샘플은 `connection-test/samples/`, 검증은 `connection-test/live-verification.json`. 조기 종료는 `python3 /home/changkwon/qaredi-assistant-mvp-20261001/connection-test/stop-test-service.py`. 프로세스 신원을 검사하고 자신의 supervisor에만 종료를 요청한다.

**Windows AssistantClient 소스가 이 서버에 전달되지 않아 그 클래스 자체를 직접 실행하지는 못했다.** 실제 requests 프로토콜 시험과 위 연결 환경을 제공했다. 로컬 담당의 UI/월마감/232개 시험 결과는 수신한 회신이며 서버 시험으로 집계하지 않는다.

### 커뮤니티·월마감 계약

현행 조사: `/home/changkwon/qaredi-assistant-mvp-20261001/community-current-contract-20261001.md`.
최종 후속 요구: `/home/changkwon/qaredi-assistant-mvp-20261001/monthly-reconciliation-server-requirements-20261001.md`.

첫 도구 월마감 대사, 요약의 결론/핵심수치/검토결과/조치/원본과 상세 Excel, owner-only 글·Excel 동일 ACL, 질문/답변/재검토 판 연결을 명시했다. 현행 일반 파일에는 owner-only가 없고 커뮤니티 토큰의 최초 생성은 첨부를 거부한다. 따라서 자동 업로드/게시를 하지 않는 로컬 경계를 유지한다. 기존 private 글의 Admin 관리 읽기도 후속 owner-only 보고서에 자동 승계하지 않는 계약이 필요하다.

자료질문 awaiting_input과 실행 승인 awaiting_confirmation은 별개다. 현행 confirm에 품목/금액 답변을 넣지 않는다. needs_review/reviewed_actions는 최종 verified 불가. reviewed도 선택 범위/원본·매핑·파서·검토 지문/독립 검산 manifest/미해결0/정정 조치0를 다시 검증해야 한다. 전표 수정·월마감 확정을 뜻하지 않는다. 잘못된9월 결과는 정답/재사용 근거에서 제외한다. 이 후속 도구·권한·API는 이번 MVP에서 구현하지 않았다.

### 미검증 항목과 확인 순서

| 순서 | 환경/확인 | 현재 상태 |
|---|---|---|
| 1 | 격리 서버에서 본인 로그인·MFA·새 토큰 설명/발급 UI | HTTP 발급은 통과. 실제 브라우저 UI 미검증 |
| 2 | 로컬 AssistantClient로 접수/GET/목록 밖 단건/승인/취소 | requests 검증은 통과. 실제 Windows 클래스 연결은 로컬 담당 확인 |
| 3 | 실제 프록시에서 SSE 버퍼링·재연결·중간 토큰 회수 | 임시 PHP HTTP는 통과. 운영 nginx/실제 앱 연결 미검증 |
| 4 | 배포 전 워커 실행 방식·기록 보존 기간·표 패치와 겹친 문서/시험 목록 병합 | 운영 서비스/DDL/버전/커밋을 적용하지 않음 |
| 5 | owner-only 보고서/Excel 및 분석 질문의 별도 범위 합의 후 구현 | 제안만 작성. 본문 분석/운영 장부·수집 사이트/글 게시 미검증 |

기존 대규모 운영 계정 기반 f66/QF9 셸 회귀는 운영 데이터 변경 없이 호출할 수 있는 그대로의 합성 환경이 아니므로 이번에 실행하지 않았다. 대신 새 합성 HTTP에서 기존 범위5개·파일 응답/다운로드·개인 발급과 관련 권한을 실제 검증했다. 기존 표 편집 전체 회귀의 미해결 관문은 원래 인계에 그대로 남아 있으며 이번 보고서를 표 기능 배포 승인으로 사용하지 않는다.

편집 거부 문구/기존 커뮤니티 렌더러·편집기·원본 스키마는 이번 MVP에서 변경0. 공급자 미연결/읽기 도구/승인/취소 오류만 최소 계약의 구조로 응답한다. 운영 배포를 수행하지 않았다.
