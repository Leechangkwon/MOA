# Q:F-17 회의 그림판 구현 지시서

기준: 사이트 `docs/meeting-board-plan-2026-11-05.md` v1.2, MB-1~23. 사용자 2026-11-18 「화이트보드 진행 해」.

- 목표: 업무 프로젝트 작업에서 Excalidraw 공동 그림판을 열고 작성자·순서·원본을 보존하며, 확정 판을 MCP 회의록의 근거로 사용한다.
- 요구사항: Q:F-17, 기획 13·14장; 권한은 CommunityAuthz/WorkDocuments의 기존 판정. DESIGN 2.21, 2.39 및 6장.
- 수정 허용: src/community/{Whiteboards,WhiteboardSync,WhiteboardGraph,WhiteboardSnapshot,WhiteboardAttachments}.php, WorkDocuments.php, routes.php, handlers/{whiteboard_*,pages_get,attachments_upload}.php, src/Audit.php; public/assets/community/{whiteboard.*,work.js,editor.js,vendor/excalidraw/**}, public/assets/community/*.js 및 public/assets/messenger.js의 자동 부품 버전 표시; tools/whiteboard/**; relay/**; scripts/{vendor_excalidraw.sh,whiteboard_chain.php,community_asset_version.php}; db/migrations/2026-11-18_community_whiteboards.sql, db/community/grants.sql; deploy/{DEPENDENCIES.md,whiteboard*,production-state.json}; clients/mcp/**; tests/catalog.json, tests/community/{whiteboard_*,memories_harness.py,export_router.php}; DESIGN.md, SECURITY.md, STAGES.md, docs/{work-items/meeting-whiteboard.md,meeting-to-prd.md,qf17-whiteboard-directive.md}; wiki/qaredi/**.
- 금지: 사용자 역할 직접 판정, SQL 값 결합, HTML 저장/innerHTML, 사용자 값으로 디스크 경로 구성, CDN, 외부 AI 호출, 운영 데이터 삭제·복원·재시드. 새 서버 외부 요청 없음. 교환실은 loopback 수신만 하며 DB·내용 로그 없음.
- 생성물 관리: `.gitattributes`에서 vendor/excalidraw의 생성된 문자열 후행 공백을 검사 대상에서 제외한다. 산출물 바이트는 정리하지 않고 고정 lockfile 재빌드와 대조한다.
- 수치: 판당 요소 5,000; 요소 64KiB; 장면 4MiB(메모리 보호); 요청 256KiB/200요소; 초당 쓰기 4회; PNG 10MiB/16MP/한 변 16000px; 배경 PNG/JPEG 10MiB/한 변 4096px.
- 충돌: version 큰 요소 우선, 같으면 versionNonce 작은 것. 일치한 재전송은 사건을 만들지 않는다. 요청마다 판 public_id를 요구해 새 판에 옛 요청이 섞이지 않게 한다.
- 기록: canonical JSON(재귀 키 정렬, 유한 숫자, -0 정규화), DB UTC 마이크로초 시각; 각 사건의 순번·계정·시각·data 지문으로 SHA-256 사슬. 원본 scene+PNG 지문. 앱에는 사건/스냅샷 UPDATE·DELETE 권한 없음.
- 순서: 1차 a 구현·격리 수락 후 1차 b 배경. 최종 수락·정적 검사 후 백업/드리프트/모의 적용을 거쳐 서버 반영. 기존 미커밋 파일 보존.
- 완료 기준: 기획 Q:T-51~70(해당 단계), 실제 PHP/Postgres·Chromium·WebSocket 수락, 보안 lint·자산/위키 검사; 실물 기기·사용자 회의는 미검증으로 구분.
- 보고: 변경 파일 / 실행 명령·원문 증거 / 미검증·이유. 현재 Codex 구현·검토는 같은 세션이며 별도 문맥 독립 검토라고 기록하지 않는다.
