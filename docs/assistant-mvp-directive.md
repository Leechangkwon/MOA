# 비서 실행 서비스 MVP 지시서 — 2026-10-01

목표: 로컬 T-Box Buddy가 개인 비서 토큰으로 읽기 전용 작업의 상태·근거·취소·확인을 다루게 한다. 사용자 최신 요청에 따라 Codex가 독립 사본에서 구현하고 운영 적용 없이 패치로 인계한다.

기준: 운영 v2.40.1, a39ec1542412533df19a1a1f8fa703cc1ab47ab7. 표 편집 트리/패치는 보존하고 병합하지 않는다.

요구사항: T:S-01/02/03/04/07/09, T:F-03/04/59/66, ARCHITECTURE 4.0/4.1/4.6/4.7/13. 이전 계약 tbox-buddy-api-contract-20261001.md의 실행 경로 여섯 개와 별도 assistant.run 범위를 구현한다. 자연어 추론 공급자는 확인 결과 운영 설정에서 없으며 외부 요청 코드를 추가하지 않는다.

수정 파일 허용 목록:
- src/assistant/{bootstrap,Error,Request,Provider,Files,Store,Service,Worker}.php
- src/handlers/assistant_runs.php, scripts/assistant_worker.php
- public/index.php, src/Authz.php, src/Http.php, src/Audit.php(등록된 문장·detail 키만), public/assets/app.js(토큰 설명 한 줄)
- db/schema.sql, db/grants.sql, db/migrations/2026-10-13_assistant_runs.sql, db/assistant.sql
- .env.example, PRD.md, ARCHITECTURE.md, API.md, SECURITY.md, STAGES.md
- docs/assistant-{mvp-directive,minimum-contract,contract,report}.md
- tests/assistant/{mvp.sh,integration.py,serve.py,unit.php,TestAdapter.php,fixture.php}, tests/catalog.json

금지: 운영 DB/서비스/설정 변경, 운영 토큰 발급, 시드 계정, 커밋/스테이징/push, 새 외부 호출/의존성, 파일 업로드·삭제·장부 변경 도구, 기존 토큰의 권한 확장. SQL 값 결합·HTML 저장·innerHTML 금지. 역할 판정은 Authz/기존 Auth만 사용한다.

완료 기준:
1. 실제 임시 PostgreSQL·PHP 라우터로 API 여섯 개, 개인 토큰 발급/범위/소유권/회수/만료/비활성화 확인.
2. 멱등 키 중복/본문 충돌/병렬 생성/사용자 격리, 목록 커서, SSE 순번/재개/소유권/권한 회수 확인.
3. queued/planning/awaiting_confirmation/running/최종 상태, 취소 접수/완료, 승인 ID·해시·기한·중복·충돌 확인.
4. 실제 파일 권한 SQL로 검색·근거 조회. 완료 후 파일 등급/삭제/팀/역할 변경 시 GET/events에서 근거 재검사.
5. 공급자 없음 503, 명시적 tool 모드, 테스트 어댑터 분리/운영 DB 이름에서 비활성, 워커 임대·재시작 검증.
6. 기존 파일/로그인 토큰에 새 API 권한 없음, 비서 토큰에 기존 파일/로그인/쓰기 권한 없음. 정적 검사/관련 회귀를 실행하고 미검증은 명시.

보고: 변경 파일/명령·출력/계약·샘플/검증·남은 항목. 테스트 DB만 마이그레이션·수정하고 운영에 적용하지 않는다.
