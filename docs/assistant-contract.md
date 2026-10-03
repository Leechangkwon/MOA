# 비서 실행 계약 — 2026-10-01

로컬 연결용 JSON·인증·SSE의 기준은 [최소 계약](./assistant-minimum-contract.md)이다. 운영 미적용 독립 사본의 구현이며, 아래 실행 환경과 보장 범위를 함께 따른다.

## 실행 환경

기본 ASSISTANT_ENABLED=0. 검증 환경의 마이그레이션/ASSISTANT_ENABLED=1/별도 CLI 워커가 필요하다. 워커 명령은 php scripts/assistant_worker.php --once 또는 --drain. systemd/cron/운영 서비스는 등록하지 않는다. GET/events는 작업을 실행하지 않는다.

현재 운영 .env/세션 환경에서 OPENAI/ANTHROPIC/LLM/OLLAMA/GEMINI/ASSISTANT 관련 설정 이름이 없고 Ollama 서비스는 비활성이다. 비밀값을 출력하거나 모델에 요청하지 않았다. 외부 모델 클라이언트는 이 MVP에 없다. mode=chat은503 provider_unavailable, 명시적인 읽기 전용 tool 모드는 native이며 inference_available=false다.

테스트 어댑터는 ASSISTANT_TEST_ADAPTER=1과 DB_NAME의 tbox_assistant_test_ 접두가 동시에 필요하다. 사용자 JSON은 이 설정을 고를 수 없다. 테스트 코드에만 있으며 provider=test를 항상 표시한다. 실제 모델 추론의 성공으로 집계하지 않는다.

## 상태·확인·취소

queued → planning → awaiting_confirmation(선택) → queued → planning → running → succeeded/failed/cancelled. cancel_requested는 접수 상태이고, 워커가 cancelled를 확정한다. 같은 승인 ID/해시/결정 재송신은200 현재 상태; 다른 결정409. 승인 대기는10분이며 ID/계획 해시/본인/현재 상태를 트랜잭션 잠금 안에서 검증한다. 승인은 쓰기 도구를 허용하는 수단이 아니다. 허용 도구는 files.search/files.evidence 두 개로 고정한다.

워커는 FOR UPDATE SKIP LOCKED로 작업을 얻고 짧은 트랜잭션만 쓴다. 120초 리스, 최대3회 획득 후 실패로 확정한다. 살아 있는 리스를 다른 워커가 덮지 않는다. 각 단계에서 원래 개인 토큰의 scope/회수/만료, 계정 활성/만료/강제 절차와 현재 파일 권한을 재확인한다. 읽기 도구만 있으므로 리스 만료 재시작으로 파일 부작용이 발생하지 않는다.

## 데이터와 권한

본인 실행만 SQL WHERE로 조회하고 관리자도 남의 실행을 볼 수 없다. 멱등 키는 사용자별 해시와 본문 해시로 저장한다. 동일 사용자 작업 생성은 사용자 행 잠금으로 한도를 검사하고 유일 제약으로 중복을 막는다. 활성10개/분당30개 한도다. 파일 내용은 저장하지 않고 근거 파일 ID만 저장하며 GET/events에서 현재 권한으로 이름·설명을 다시 조회한다. 외부 공급자로 파일을 전송하지 않는다.

SSE는 실행별 순번을 저장하고 Last-Event-ID 뒤만 보낸다. 최대10초 연결 후 재접속; 최소 heartbeat와 retry 지시를 포함한다. 토큰이 중간에 만료/회수되면 stream.error로 닫는다. 근거가 현재 보이지 않으면 파일명/파일 ID/설명을 재생하지 않고 available:false를 보낸다. 검증 대상은 실제 임시 PHP 서버이며 운영 프록시의 버퍼링/연결 수/타임아웃은 별도 미검증이다.

## 인계

실측 JSON/SSE/검증 보고서와 패치·handoff는 시험 완료 후 생성한다. 로컬 담당의232개 시험 결과는 전달받은 결과이며 서버에서 실행한 것으로 집계하지 않는다. 운영 DB/파일/서비스/설정/토큰은 바꾸지 않는다.
