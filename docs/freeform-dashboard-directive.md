# 개인·공간 대시보드 자유 배치 — 2026-10-11

목표: 사용자가 기존 위젯을 직접 드래그하여 위치와 가로·세로 크기를 조절하고 계정/공간에 저장한다. 사용자 요청이 종전의 small/wide·위아래 순서 방식보다 우선한다.

요구사항: Q:F-1, 제품 공간 API 홈 설정, ARCHITECTURE 회사·모임 제품 공간, DESIGN 홈 자유 배치. 개인 홈 4종과 공간 홈 5종의 현재 콘텐츠·권한을 보존하며 공통 편집 엔진을 만든다. 새 업무 기능/온보딩은 이 변경의 완료 범위가 아니다.

## 저장 계약 (구현 전 확정)

- 기존 v1을 계속 읽고 저장할 수 있다. GET은 기존 행을 변환하거나 만들지 않는다. 브라우저에서 v1을 변환하고 사용자가 저장할 때 v2로 저장한다.
- v2 root 정확히 `version,widgets,source,view`. version=2. 개인 IDs recent/services/tools/stories 정확4, 공간 notice/task/event/file/story 정확5. 숨겨진 위젯도 남겨 복원한다.
- 각 widget 정확히 `{id,visible,desktop,mobile}`. desktop/mobile 각각 정확히 `{x,y,w,h}` 정수만 허용한다. desktop 논리 폭1200, mobile 폭360. x/y≥0, desktop w=240..1200, mobile w=160..360, h=160..2000, x+w≤논리폭, y+h≤20000. JSON 전체8KiB 상한(v1은4KiB). unknown/중복/누락/float/string/bool geometry 거부.
- x/w는 컨테이너 폭에 비례해 표시하고 y/h는 CSS px로 표시한다. 760px 미만에서는 mobile 배치를 사용한다. 편집 시 현재 화면의 배치만 갱신하며 다른 화면 배치를 보존한다. 중간 브레이크포인트 전환/포인터 취소는 활성 제스처를 취소한다.
- 기존 source(all/files/pages), view(recent/favorites), CSRF·revision 충돌·본인/공간 편집 권한 유지. arbitrary style/HTML/URL 저장 없음. DB migration/새 패키지 없음.

## 화면/조작

- ‘대시보드 편집’을 누르면 실제 위젯이 보이는 화면에서 편집한다. 도구는 편집 중에만: 위젯 추가(숨긴 것 복원), 기본 배치, 취소, 저장. 현재 내 홈 또는 공유 공간 편집임을 표시한다.
- 위젯 이동 손잡이 + 우하단 resize handle, Pointer Events로 mouse/touch/pen. 콘텐츠 클릭·본문 스크롤은 유지하며 handle만 touch-action:none. 키보드 방향키/Shift 가속과 위치·크기 숫자 입력 중 최소 하나 제공, 한국어 accessible label와 결과 안내.
- 임의 x/y/w/h를 선택 가능(고정 열/크기 preset만 제공하면 미완료). 경계 밖을 clamp. 이동/크기 변경 후 겹친 다른 위젯은 아래로 밀어 읽을 수 있게 하되 빈 공간을 강제 압축하지 않는다. 캔버스 높이는 마지막 위젯까지 확보한다. 유한한 최대높이에서 배치 불가능 시 제스처를 되돌리고 안내한다.
- 축소한 위젯의 본문은 내부 스크롤/줄바꿈으로 접근 가능. 큰 서비스 카드/이야기도 좁은 카드에서 가로 넘침이 없어야 한다. 정상 화면의 여백·테마·서비스 색 유지. 숨김 위젯 제거는 데이터 삭제가 아니다.
- 편집 취소/ESC는 저장 전 배치를 복원, 저장 실패/409는 초안을 보존. 저장 중 닫기/재저장/제스처 차단. 개인 로컬 fallback은 명시 안내, 서버 실패를 성공으로 오인하지 않음. 기존 로컬 명시 가져오기 보존.
- 공간 전환/늦은 응답 가드·보관/뷰어 권한·기존 menu 2개·비밀 정리 유지. 편집 중 공간 전환으로 다른 공간 배치에 저장되는 일 금지.

## 수정 파일

- Backend: src/site/ProductSpaces.php, tests/product_spaces_backend.py, tests/product_spaces_backend.php, tests/product_spaces_layout.php, tests/community/product_spaces_http.py, 필요시 해당 격리 시험 전용 파일.
- Frontend: public/assets/dashboard-canvas.js, public/assets/dashboard-canvas.css, public/assets/home-dashboard.js, public/assets/space-page.js, public/assets/space-page.css, src/views/launcher.php, src/views/space_page.php, tests/home_dashboard.py, tests/home_dashboard_sync.py, tests/space_dashboard_ui.py, tests/space_dashboard_stale.py, tests/space_actions_ui.py, tests/freeform_dashboard_ui.py. 기존 product-home.js/start.js 수정 필요시 먼저 사유 보고.
- Root: DESIGN.md, ARCHITECTURE.md, docs/product-spaces-api.md, docs/work-items/freeform-dashboard.md, 본 지시서, SECURITY.md, STAGES.md, docs/server-context/STAGES.md, 기존 wiki 안내, 발행/운영 인계 기록.
- Auth/Authz/Db/Http/Storage/public index/db/deploy 동작 수정 없음. production-state 기록만 root 담당.

금지 패턴: SQL 문자열 결합, innerHTML, 사용자 입력 파일경로, 외부 HTTP·CDN/신규 패키지, 클라이언트 role 기반 권한대체, HTML 저장, 실제 업무자료 테스트변경, 운영DB reseed/delete/restore.

완료 기준: 실제 브라우저 개인/공간 mouse 이동+가로세로 resize+저장/reload+취소+숨김복원+모바일 touch+responsive 독립+키보드+넘침+stale/409; 서버 v1/v2 정상/비정상/권한/CSRF/revision; 기존 관련 UI와 격리 교차회귀; 독립검토, 보안lint·자산·위키. 운영반영은 root가 백업/복호화·고정후보 모의적용·drift·실제 HTTPS·원본보존 검증 후 수행.

보고: 변경 파일 / 실행 명령과 결과 / 미검증과 이유. 구현 에이전트는 commit/deploy 금지.
