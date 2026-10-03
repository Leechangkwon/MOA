# 로컬 연결용 최소 계약 — 2026-10-01

구현 담당 간 확정한 인터페이스다. **운영 서버에는 아직 적용되지 않았다.** 아래 요청은 패치 적용 후 검증용 서버에서 연결한다. 기본 주소: `https://<검증용 호스트>/tbox`.

## 1. 인증과 발급

실행 API 여섯 개 모두 `X-API-Token: <개인 assistant.run 토큰>` 필요. Bearer/쿠키/session.open/파일 토큰으로 대체할 수 없다. 기존 파일 토큰과 session.open은 그대로 별도 유지한다. assistant.run은 기존 파일·로그인·커뮤니티 API를 열지 않으며 공통 `GET /api/whoami`만 허용한다.

발급은 검증용 웹에서 본인 로그인 후 기존 `POST /api/me/api-tokens`를 사용한다. 웹 세션 쿠키 + `X-CSRF-Token` + JSON:

```json
{"name":"T-Box Buddy 비서","purpose":"읽기 전용 파일 검색 및 실행 상태 조회","scope":"assistant.run","password":"<현재 비밀번호>","otp":"<현재 6자리 코드>"}
```

MFA 활성화/현재 비밀번호·OTP 확인, 기존 계정당 활성 토큰5개·180일 규칙을 그대로 따른다. 201 `{id,name,hint,scope,expires_at,token}`에서 평문 token은 한 번만 받는다. 토큰으로 토큰을 발급하는 기능은 없다. **현재 운영 발급 화면/DB는 assistant.run을 지원하지 않는다.**

## 2. 파일 검색 접수

`POST /api/assistant/runs`, JSON, `Idempotency-Key: buddy-search-<새 UUID>`.

```json
{"mode":"tool","tool":{"name":"files.search","arguments":{"query":"재료비","limit":20}}}
```

query1~200자, limit1~100. 파일명·설명 검색이며 현재 사용자가 읽을 수 있는 파일만 반환한다. 폴더를 생략한 전역 검색이다.

## 3. 근거 조회 접수

같은 메서드·경로, 별도 `Idempotency-Key: buddy-evidence-<새 UUID>`.

```json
{"mode":"tool","tool":{"name":"files.evidence","arguments":{"file_id":"11111111-1111-4111-8111-111111111111"}}}
```

근거는 **파일명·설명 메타데이터**다. 본문을 추출하거나 읽었다고 표시하지 않는다. 실제 다운로드는 기존 파일 토큰/기존 다운로드 경로를 사용한다. 업로드·삭제·장부 정정 도구는 허용되지 않는다.

두 요청의 202 응답:

```json
{"run_id":"22222222-2222-4222-8222-222222222222","status":"queued","events_url":"/tbox/api/assistant/runs/22222222-2222-4222-8222-222222222222/events","created_at":"2026-10-01T00:00:00+00:00","provider":"native","inference_available":false,"idempotent_replay":false}
```

동일 사용자·같은 키·같은 정규화 본문은 같은 run_id. 같은 키·다른 본문은409 idempotency_conflict. 응답이 끊기면 새 키를 만들지 말고 같은 키/본문으로 재시도한다. 접수 후 별도 워커가 실행한다. GET/events 요청 자체는 작업을 실행하지 않는다.

## 4. 단건 상태/결과

`GET /api/assistant/runs/{run_id}` →200, 최상위 실행 객체:

```json
{
  "run_id":"22222222-2222-4222-8222-222222222222",
  "conversation_id":null,
  "mode":"tool",
  "status":"succeeded",
  "phase":"completed",
  "provider":"native",
  "inference_available":false,
  "message":"",
  "steps":[{"step_id":"33333333-3333-4333-8333-333333333333","tool":"files.search","status":"succeeded"}],
  "artifacts":[{
    "artifact_id":"44444444-4444-4444-8444-444444444444",
    "kind":"file_evidence",
    "file":{"id":"11111111-1111-4111-8111-111111111111","name":"재료비.xlsx","level":"team","size":1234,"uploaded_at":"2026-09-30T00:00:00+00:00","exec_content":false},
    "evidence":{"kind":"metadata","description":"9월 재료비","content_read":false},
    "download_url":"/tbox/api/files/11111111-1111-4111-8111-111111111111/download"
  }],
  "result":{"kind":"file_search","files":[{"id":"11111111-1111-4111-8111-111111111111","name":"재료비.xlsx","level":"team","size":1234,"uploaded_at":"2026-09-30T00:00:00+00:00","exec_content":false}],"count":1,"content_read":false},
  "approval":null,
  "error":null,
  "created_at":"2026-10-01T00:00:00+00:00",
  "updated_at":"2026-10-01T00:00:01+00:00",
  "started_at":"2026-10-01T00:00:01+00:00",
  "finished_at":"2026-10-01T00:00:01+00:00",
  "last_event_id":5
}
```

검색 결과가 없으면 files/artifacts는 빈 배열이고 count는0이다. 근거 조회의 result.kind는 `file_evidence`, 나머지 구조는 같다. queued/running에서는 result/error는 null이고 artifacts는 빈 배열이다. 발행 전 approval은 null이며 발행한 계획은 승인/거절 후에도 decision과 함께 이력으로 유지한다. 확인 UI는 status=awaiting_confirmation인 경우에만 새 결정을 받는다. 실패는 status failed + error 객체, 취소는 cancelled. 남의/없는 실행은 같은404 run_not_found.

상태: queued/planning/awaiting_confirmation/running/cancel_requested/succeeded/failed/cancelled. phase: queued/planning/approval/running/cancelling/completed/failed/cancelled. GET/list/events마다 현재 파일 권한을 재검사하여 접근이 없어진 근거는 제외한다.

## 5. 취소

`POST /api/assistant/runs/{run_id}/cancel`, JSON `{}`.

```json
{"run_id":"22222222-2222-4222-8222-222222222222","status":"cancel_requested"}
```

미종료 상태는202이며 아직 취소 완료가 아니다. 워커 처리 후 GET에서 cancelled. 이미 종료됐다면200 현재 최종 상태(succeeded/failed/cancelled)를 반환한다. 반복 취소로 새 실행이 생기지 않는다.

## 6. 미연결 및 오류

자연어 요청 `{"mode":"chat","message":"이번 주 재료비를 정리해 줘"}`는 실제 공급자 미연결 시503, **run_id 없음**:

```json
{"error":{"code":"provider_unavailable","message":"AI 공급자가 연결되지 않았습니다. 파일 검색·근거 조회를 명시적으로 실행하세요.","retryable":false,"request_id":"55555555-5555-4555-8555-555555555555"}}
```

실행 중 실패는 같은 error 구조를 GET.error에 보관한다. API 오류는 최상위 `{error:{code,message,retryable,request_id,run_id?}}`. 401 authentication_required,403 forbidden,404 run_not_found,409 idempotency_conflict/approval_*,429 rate_limited,503 service_disabled/provider_unavailable. 기본 서비스 설정은 무효(ASSISTANT_ENABLED 없거나0), 검증 환경에서만1로 켠다.

## 7. 목록·SSE·확인

- `GET /api/assistant/runs?limit=20&cursor=<next_cursor>` → `{runs:[<단건 구조>],next_cursor:null|문자열,capabilities:{provider:"unconfigured",inference_available:false,tools:["files.search","files.evidence"],worker_required:true}}`.
- `GET /api/assistant/runs/{id}/events`, `Accept:text/event-stream`, 선택 `Last-Event-ID: <정수>`; 각 event는 단조 증가 id, event 이름, JSON data `{run_id,at,payload}`. 최대10초의 유한 SSE 후 마지막 ID로 재연결. 끊김은 작업 실패가 아니다.
- 기본 이벤트: run.created/phase.changed/tool.started/tool.finished/artifact.created/run.completed/run.failed/run.cancelled/approval.required. 예: `event: phase.changed`, `data: {"run_id":"…","at":"…","payload":{"status":"running","phase":"running"}}`. artifact.created.payload는 `{artifact:<위 근거 객체>}`, 권한 소실 시 `{artifact_id,available:false}`. 원문 토큰은 URL에 넣지 않는다.
- POST에 `require_confirmation:true`를 추가하면 확인 대기. GET.approval `{approval_id,plan_hash,expires_at,decision:null,plan:{steps:[{step_id,tool,arguments}]}}`를 그대로 표시한다.
- `POST /api/assistant/runs/{id}/confirm` JSON `{approval_id,plan_hash,decision:"approve"|"reject"}`; approve202 queued, reject200 cancelled. ID/해시/10분 기한/소유권 검증. 같은 승인 재송신200 현재 상태, 상충 decision/계획 또는 취소 뒤 처음 하는 승인은409. 이미 처리한 동일 결정은 취소 후에도200 현재 상태만 반환하고 실행을 재개하지 않는다.

UUID/시각/파일은 설명용 샘플이다. 전체 구현·HTTP 실측 검증 결과는 후속 handoff로 전달한다. 로컬 시험 결과는 로컬 담당의 회신이며 서버에서 재실행한 결과가 아니다.
