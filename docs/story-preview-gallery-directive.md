# 요약 카드 사진 넘기기 지시서

- 목표: 사용자가 만족한 요약 카드 높이·제목·본문·동작과 펼친 상세 화면을 유지하며 요약 카드의 사진만 좌우로 넘겨 볼 수 있게 한다.
- 기준: 최신 main `6dc6ec2` (v2.47.1 및 이전 글 요약 오류 수정 배포 기록 포함), `cloud/story-preview-gallery`.
- 요구사항: `AGENTS.md`, `CLAUDE.md`, `docs/server-context/site-rules.md`, `DESIGN.md` 회원 경험·터치·접근성, `ARCHITECTURE.md`/`API.md` 회원 경험, `docs/server-context/community/PRD.md`를 읽는다. 이번 사용자 지시가 기존 대표 사진 한 장 규칙을 확장한다. 본문을 새로 한 줄로 자르는 추가 변경은 하지 않는다(사용자는 현재 표시를 승인했다).
- 구현자 수정 파일: `public/assets/community/memories.js`, `public/assets/community/memories.css`, `tests/community/memories_social_browser.py`, 새 `tests/community/memories_gallery_browser.py`. 다른 시험의 대표 사진 한 장 가정이 깨지면 파일과 이유를 먼저 보고한다.
- 부모 수정 파일: 지시서/작업 인계, `DESIGN.md`, `SECURITY.md`, `STAGES.md`, `docs/server-context/community/STAGES.md`, `wiki/community/guide-memories.json`, `wiki/community/index.json`. 자산 계산에 따른 `dashboard.js`, `database.js`, `editor.js`, `suggestions.js`, `templates.js`는 import 버전 문자열만 갱신한다.
- 동작: 사진/영상이 여러 개면 요약 카드의 기존 높이와 object-fit:contain을 유지한 한 칸에서 순서대로 가로 넘김. 모바일 기본 스와이프와 가로 scroll snap, PC 이전/다음 버튼(이름·44px 터치·경계 상태), 키보드 접근, 현재 번호/전체 수. 자동 재생/순환 없음. 한 장이면 불필요한 넘김 제어 숨김, 첨부0은 현재 그대로. 현재 전체 보기 진입과 내용 더 보기 유지. 기존 공통 클래스/테마 토큰과 scoped data 속성 사용, 외부 라이브러리/새 전역 이벤트 누수 없음.
- 보존: 피드의 여러 글 모두 요약, 제목·본문 요약 줄 수·좋아요·댓글·공유·상세 모달의 전체 이야기 구성은 유지. 원본/첨부 순서/공개 범위/API/DB 변화 없음. 상세용 렌더링과 CSS는 수정하지 않는다. 화면 밖으로 넘어간 영상은 재생을 멈추며 영상 컨트롤과 세로 페이지 스크롤을 방해하지 않는다. 이미지 lazy loading과 영상 자동재생 금지를 유지한다.
- 금지: innerHTML/HTML 문자열 출력, 실제 사용자 사진/데이터 fixture, 새 외부 요청·패키지·보안 핵심 파일 수정, 커밋·push·브랜치 변경.
- 완료 기준: 실제 Chromium/JS와 합성 HTTP로 0/1/다수(최대10) 첨부, 여러 피드 글, 내 목록/팔로잉/발견/회원/익명 공유, 모바일390·데스크톱1440/작은폭320, 이전/다음과 마지막 경계, 네이티브 가로 스크롤 또는 CDP 터치 스와이프, 번호·높이·가로 페이지 넘침·키보드·사진 로딩/순서·재정렬/더 불러오기·모달 열기/닫기 확인. 혼합 영상은 컨트롤과 이전 영상 중지 확인. 기존 social 12시나리오/40테마·너비 검사를 의미 있게 유지(전체 DOM 이미지 수가 늘어도 한 화면에는 한 장만 보이고 카드 높이는 제한됨을 확인). 상세 DOM/구성 보존도 검증. 증거는 /tmp에 저장.
- 검사: 새 gallery 및 기존 social browser, JS/Python 구문·공백. 별도 문맥 검토자가 재현·보안 린트를 수행한다. PHP 공식 자산/위키·실제 서버·실물 기기는 없는 실행 환경을 통과로 기재하지 않는다.
- 보고: 변경 파일 / 실행 명령·출력 / 미검증·이유. 저장소 지정 Claude 역할 도구는 없으므로 현재 Codex 구현 에이전트와 다른 문맥 검토로 수행한다.
