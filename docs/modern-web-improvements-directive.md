# 전체 기능 성능과 사용성 개선 묶음

사용자 승인: 2026-11-19 성능 평가 Page의 개선안을 가능하면 전체에 대해 한 번에 구현하고 신규 GitHub `Leechangkwon/MOA` 저장소를 사용한다.

기준: d575fed6144a / 운영 v2.79.1, 기존 운영 모바일 nowrap 수정 포함. 격리 작업본 `/home/changkwon/moa-modernization-20261119`, 결과 `/home/changkwon/moa-modernization-results-20261119`. 기본 문서: AGENTS.md, CLAUDE.md, PRD.md, ARCHITECTURE.md, API.md, DESIGN.md, SECURITY.md 관련 절. 실제 사이트 공통 지침은 `/var/www/qaredi/CLAUDE.md`이다. Claude 전용 모델명은 사용할 수 없으므로 Codex 구현 에이전트와 메인 검토를 별도 컨텍스트로 분리한다. 모델 이름을 가장하거나 독립 검토를 했다고 허위 기록하지 않는다.

## 범위

1. 업무 보기·편집기 코드의 초기 의존성 분리와 자산 버전 일관성.
2. 문서·이야기 레이아웃 이동과 명암비, 안내를 접고 본문을 우선 배치.
3. 작업 머리의 회의 그림판 진입, 긴 제목과 좁은 화면, 첫 안내 선택형 전환.
4. 그림판 변경 직렬화·장면 갱신 비용, 진단, 전체 보기·DOM 텍스트 대안·상태.
5. 사진·영상 크기와 우선순위, 화면 밖 긴 목록의 렌더 비용.
6. 업로드 전송·검사 상태, 한글 IME, 오류 초점·폼 의미 구조.
7. 개인 내용을 보내지 않는 성능 진단과 반복 가능한 합성·실제 HTTPS 비교.
8. 새 GitHub 전달 경로, 기획·운영·위키 기록, 원본 보존과 최종 검증 뒤 한 번 통합 반영.

Google 가이드 252개를 기능에 무관하게 모두 도입하지 않는다. 원래 보고서의 113 적용 대상과 117 조건부 항목에 대한 현재 적용 상태를 연결한다. 초안 8개·현재 비대상 14개는 제외한다. 신규 AI 모델·브라우저 WebMCP·결제·SPA 전면 교체·비공개 업무 오프라인 캐시는 이 성능 개선에 포함하지 않는다.

## 구현 경계

- 코드 분리 담당: `public/assets/community/editor.js`, 새 `public/assets/community/*` 편집기/부트스트랩 모듈, `src/views/shell_top.php`, 코드 분리에 필요한 기존 의존 모듈과 자체 단위 검사. 공통 CSS·업무 work.js·그림판은 다른 담당 소유다.
- 화면·미디어 담당: `public/assets/app.css`, `public/assets/moa.css`, `public/assets/community/community.css`, `public/assets/community/memories.css`, `public/assets/community/memories.js`, `public/assets/community/doc-render.js`, `public/assets/community/work.js`, `public/assets/usage-guide.js`, `public/assets/moa-usage.js`, 해당 홈/자료실 PHP 핸들러·화면. 기존 모바일 nowrap 수정은 이미 작업본에 복사했으며 반드시 보존한다.
- 그림판 담당: `public/assets/community/whiteboard.js`, `whiteboard.css`, `src/community/handlers/whiteboard_page.php`, `clients/whiteboard-relay/**` 중 현재 구현과 필요한 단위 검사. 서버 권한·저장·해시 체인·LWW·검증 계약은 바꾸지 않는다.
- 메인: 공통 app.js·업로드·폼·성능 계측, 문서·Git 이전·통합 검사·자산 버전 재생성·배포. 파일 소유 경계를 넓혀야 하면 먼저 메인에게 알린다.

## 금지와 검증

운영 .env·키·세션·DB 덤프·사용자 원문·실제 사진은 복사/출력/커밋하지 않는다. 합성 DB는 memories_harness.py와 tbox_testdb로 생성한다. 운영 SQL 변경·DB 삭제·복원·재시드·직접 사용자 데이터 변경 금지. 외부 CDN·인라인 이벤트·innerHTML·권한 분기 복제·CSP 완화 금지. 불필요한 새 패키지는 넣지 않는다. 성능 수치를 만들거나 정식 INP와 미리보기 지연을 혼동하지 않는다.

각 담당은 자신의 변경에 필요한 검증만 수행하고 명령·결과·미검증을 결과 경로에 남긴다. 공통 대형 harness는 메인이 최종 통합판에서 한 번 실행한다. 공통 자산 버전 재생성·release·커밋·push·운영 변경은 메인만 수행한다. 기존 테스트의 의미를 약화시키지 않는다. 구현 완료 시 변경 파일·동작·주의점·필요한 회귀를 메인에게 전달한다.
