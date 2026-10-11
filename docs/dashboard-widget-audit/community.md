# 커뮤니티 기능 실사와 위젯 콘텐츠 기획

조사 기준: `work/dashboard-usability` HEAD `93668ff`(부모가 지정한 운영 코드 기준과 동일). 2026-10-11 읽기 전용 소스 조사. 앱 코드 변경, API 호출, 사용자 데이터 열람, 회귀 실행, 배포는 하지 않았다. 아래의 **구현됨**은 실제 라우트+핸들러+화면/자산 연결을 확인했다는 뜻이며 운영 설정·현재 데이터량·외부 연결의 실행 성공을 새로 확인했다는 뜻이 아니다.

## 먼저 구분할 데이터 체계

1. `/community`의 문서 공간은 개인·t-box 팀·게시판 공간이다. 문서 페이지와 그 DB 행이 업무/프로젝트/담당/기한의 원본이다. 실제 `/community/`는 업무 홈이고 `/community/workspace`가 문서 탐색 화면이다. [C1][C2][C3]
2. 새 회사·모임 **제품 공간**의 notice/task/event/file/story는 별도 API·테이블 체계다. 커뮤니티 내 작업/프로젝트가 제품 공간 task로 자동 합쳐진다고 볼 근거는 없다. 홈에서 같은 ‘할 일’ 제목으로 섞으면 출처·담당·권한이 다르게 보인다. 목록에 ‘업무 문서 / 회사·모임 공간’을 구분하고 통합은 별도 계약으로 결정해야 한다.
3. 추억/스레드는 `memory_posts`이며 일반 문서/게시판 글과 다르다. 여행 계획은 이 화면에 붙은 **본인만 보는 계획**이다. 회사·모임 공동 여행 계획 기능이라고 소개하면 현재 권한 모델과 맞지 않는다. [C12][C13]
4. S/M/L은 단순 글자 크기나 고정 열 수가 아니라 **정보량과 행동의 깊이**다. S는 핵심 상태+한 행동, M은 우선순위 목록+요약, L은 탐색/세부 문맥. 사용자가 확정한 위젯별 위치·크기 잠금과 전체 배치 저장에 콘텐츠를 맞추며, 아래는 기획안이지 구현 완료 기능이 아니다.

## 기능별 실제 상태와 위젯 판단

표의 URL은 `/community`를 포함한 실제 경로다. `{page}`, `{db}`, `{portfolio}`는 각 API가 받는 UUID이며 내부 숫자 ID와 바꿔 쓰면 안 된다.

| 사용자 기능 | 실제 화면·데이터 원본 | 현재 권한·구현 범위 | 위젯 판단 |
|---|---|---|---|
| 업무 홈 | `/community/`; 내 작업 API·브리핑·`community_work_projects()` 서버 목록 | 본인이 보통 권한으로 볼 수 있는 작업/프로젝트. 지난 기한·오늘·이번 주, 내 프로젝트, 브리핑 화면 연결됨. [C2][C3][C4] | 홈 전체를 다시 넣지 말고 아래 세 개를 독립 위젯으로 사용 |
| 내 작업 | `/community/my-tasks`; `GET /api/my-tasks?done=0&bucket=…&cursor=…` | 담당이 나, 또는 담당 없는 내 문서 중 상태/기한 있는 것. 지난 기한·오늘·이번 주·나중에·기한 없음. 완료 목록, 상세 패널, 빠른 추가/하위 작업 있음. 담당 지정은 읽기/수정 권한을 주지 않음. [C3][C5] | 최우선. 개인 홈 위젯이며 공간 구성원 전체 작업 수로 바꿔 쓰지 않음 |
| 작업 상세·체크 | `/p/{page}`; `GET /api/pages/{page}`, `PATCH …/properties`, 댓글·history | status=todo/doing/review/done/hold, due, assignee; 담당 후보 서버 조회; `can_set_properties/can_assign` 등. 빠른 체크도 서버가 다시 판정. [C5][C6] | 위젯은 작업 열기 중심. 직접 완료는 해당 작업 capability 확인 뒤 별도 승인된 동작으로 |
| 내 프로젝트 | DB 목적 `project`; `/p/{page}`; 업무 홈의 `community_work_projects()` | 프로젝트는 DB 페이지. 섹션·목록·보드·마감·담당·하위 작업 진행률 연결됨. 내 프로젝트 목록은 서버 함수 기반, 독립 프로젝트 목록 API로 가정하면 안 됨. [C2][C7] | 우선. 선택한 프로젝트 또는 서버 목록 어댑터 필요 |
| 프로젝트 상태 보고 | `GET/POST /api/projects/{page}/status`; `project_status_updates` | 읽기=페이지 읽기, 쓰기=페이지 변경. 상태 기록 추가 가능; 기존 기록 수정/삭제 라우트 없음. 최근 상태+상태 글 이력 존재. [C1:255][C7] | 프로젝트 위젯의 요약 부분. 작성은 전체 화면에서 |
| 포트폴리오 | `/portfolios/{portfolio}`; `GET /api/spaces/{num}/portfolios`, `GET /api/portfolios/{portfolio}` | 프로젝트 모음, 상태별 수·진행률·담당·마감·최근 상태 글. 볼 수 있는 프로젝트만 합산; 숨긴 프로젝트 수 노출 없음. `can_manage`로 추가/제거/이름 변경. [C8] | 관리자/팀 리더에게 유용한 선택형 위젯 |
| 개인 작업 캘린더 | `/my-tasks`의 캘린더 보기; `/api/my-tasks`의 due | 기한 있는 **내 작업**을 달력으로 렌더링. 외부 캘린더 또는 모임 event 캘린더가 아님. 페이지 단위로 받은 항목 제한 존재. [C5][C9] | 내 작업 위젯 L의 다른 보기 또는 별도 ‘내 작업 일정’ |
| 문서 공간·트리 탐색 | `/workspace`; `GET /api/spaces`, `/api/pages?space=&tree=1` | 개인·팀·게시판 공간, 페이지·하위 페이지·이동·트리·공개 범위. 쓰기는 전역 쓰기 권한+행별 판정. `/api/me/space` GET은 필요 시 개인 공간 생성 동작이 있으므로 단순 읽기 위젯 원본으로 무분별 호출하지 않음. [C1:189][C6] | 특정 문서 공간 바로가기/선택 문서 목록은 유용, 전체 트리는 전체 화면 |
| 문서·블록 에디터 | `/p/{page}`; pages CRUD, doc_version, heartbeat, 브라우저 임시저장 | 글·목록·할 일·토글·표·단·이미지·파일·페이지 링크·멘션·임베드·버튼·동기화 블록. 인계/공유/관리 권한과 편집 권한 분리. 충돌/버전/임시저장 존재. [C6][C10] | 문서 전체 에디터는 넣지 않음. 읽기 발췌/문서 열기만 |
| 즐겨찾기·최근 문서 | `GET /api/me/favorites`, `/api/me/recent`; 개인 기록 | 본인 목록, 현재 문서 읽기 권한 재필터. 즐겨찾기 순서 변경·등록/제거, 최근 기록 삭제. [C1:348] | 높은 가치. 자료실 최근/즐겨찾기와 출처 표시 후 결합 가능 |
| 개인 모음 | `/workspace`의 모음; `GET /api/collections`, `/api/collections/{num}` | 소유자 본인 정리 폴더/문서 링크, 부모·순서. 권한을 확장하는 공유 폴더 아님. 한 사람 모음50, 모음 항목500 제한. [C11] | 선택한 모음을 보여 주는 즐겨찾기 확장형 |
| 통합 문서 검색 | `/workspace` 검색; `GET /api/search` | 현재 보이는 문서 조건으로 검색. 관리자 예외 읽기 권한을 통합 검색 결과에 무조건 적용하지 않음. [C1:224][C14] | 검색 입력 위젯은 유용. 검색 결과와 복잡한 필터는 전체 화면 |
| 문서 데이터베이스 | `/p/{DB 페이지}`; `/api/databases/{db}/rows`, `/summary`, `/views`, `/schema`; 행은 pages | 표·목록·보드, 속성 스키마/값, 저장한 필터·정렬·뷰, 관계·파일·집계. DB 관리와 행 편집 분리. rows는 현재 보이는 행, capability 제공. [C15] | 재사용 가치 높음. DB+저장 뷰를 선택한 ‘업무 목록’ 위젯 |
| DB 캘린더·타임라인 | 저장 뷰 calendar/timeline; rows?view=&from=&to=; `calendar.js` | 날짜 속성 선택, 주/월 및 타임라인·선후행 관계. 날짜 범위 검증과 행 필터. 작업 due와 사용자 날짜 속성은 출처를 설명해야 함. [C9][C15] | 날짜 중심 DB에 선택형 위젯. 타임라인 편집은 전체 화면 |
| DB 차트·수치 | `/api/databases/{db}/chart?view=…`, `/summary`; `charts.js` | 차트 kind number/bar/line/donut, count/sum/avg, 그룹·기간. 보이는 행만 집계. 무슨 지표인지 스키마/뷰로 결정됨; 임의 ‘매출/성과’ 수치 없음. [C16] | 수치형 S와 비교형 M/L에 매우 유용 |
| 문서 안 DB 대시보드 | `/p/{page}`의 db_view 블록; `dashboard.js`가 DB rows/chart 호출 | 여러 저장 DB 뷰를 본문에 삽입, 소유자/사람·날짜 전체 필터. 해당 속성 없으면 ‘거르개 적용 안됨’, 500개 제한 안내. [C17] | 이미 존재하는 재사용 기반. 현재 freeform 홈과 자동 동기화된 것은 아님 |
| DB 폼·응답 | `/f/{db}/{view}`; `/api/forms/{db}/{view}`, `…/submit`; 게시된 `/pub/{token}/form/{view}` | 저장 폼 질문·공개/익명/열림 설정, 응답을 DB 행 생성. 일반회원 폼과 공개 게시 폼 관문 다름. [C18] | 작성창은 전체 화면. 선택한 폼 링크/응답 요약 위젯 가능 |
| 게시판·공지·질문답변 | `/b/{num}`; `/api/boards/{num}/pages`, tags; pages publish/unpublish/hide | 게시판 등급+문서 행 공개 범위. 게시·숨김·질문 해결 capability, 태그/조회수/공지 읽음. 게시판 관리자 생성/설정은 Admin. [C1:327][C6] | 선택 게시판 새 글/미읽음 공지/미해결 질문은 유용 |
| 댓글·멘션·알림 | pages/comments; `/api/notifications`, `/api/notifications/read` | 페이지를 볼 수 있는 사람의 댓글/답글·해결·도움됨, 본인 알림. 권한 회수 시 현재 원문 권한 재판정. 문서/파일 접근 요청 알림도 포함. [C1:330][C19] | ‘나에게 온 알림’ 또는 브리핑의 하위 보기. 댓글 편집·숨김은 전체 화면 |
| 오늘 브리핑 | `/api/briefing`; `Briefing::collect()` | 지난/오늘 기한·검토·검증 만료·미해결 내 질문·반복 생성·안 읽은 공지·답변 안한 멘션/댓글·본문 미완료 체크·파일 검사/공유 만료를 현재 권한 안에서 분류. 쓰기 없는 projection. [C4] | 내 작업과 별도로 ‘놓치면 안 되는 것’ 최우선 |
| 절차서·검증 만료 | `/procedures`; `/api/procedures?verify=…&q=…`; properties kind/verify | 절차서 검색, 검증됨/만료/미검증, 검증자·만료, 일반 문서 가시성. [C20][C6] | 절차서를 쓰는 회사에서 유용. 범용 모임 기본 위젯은 아님 |
| 자동 작업·반복 | `/automations`; `/api/automations`, `…/runs`, dry-run/run/undo | 반복 페이지 생성, review 상태/기한 알림, 버튼 실행. 내 규칙 목록·다음 실행·최근 결과·실패 연속/자동 중지; 실행마다 현재 권한. 예약 실행은 운영 작업기 설정 확인이 별도 필요. [C21] | 개인 자동 작업 상태 요약만. 실행/되돌리기/규칙 편집은 전체 화면 |
| 수정 제안 | `/suggestions`; `/api/me/suggestions`, pages/suggestions; accept/reject/withdraw | 원문을 덮지 않고 제안 생성, 작성자 회수/검토자 승인·거절, 문서 권한 및 버전 재판정. [C1:90][C22] | 내게 필요한 검토 목록 위젯. diff·승인/거절은 전체 화면 |
| 문서 템플릿 | `/workspace`·페이지의 템플릿 UI; `/api/templates`; POST pages{template_id} | 개인·팀·사이트 템플릿, 본문 재사용. 첨부·하위 페이지·댓글은 복제되지 않는다고 UI 명시. [C23] | ‘빠르게 새 문서’ 선택형. 템플릿 관리 자체는 전체 화면 |
| 추억·스레드·사진 | `/memories`, `/memories/u/{username}`, `/memories/p/{id}`; `/api/memories` | 본인 기록·팔로잉·발견·검색·피드/앨범, 사진 업로드·날짜/장소·좋아요·댓글·프로필·팔로우. private/followers/community/shared, discoverable 별도. [C12] | 모임/개인 핵심 위젯. 제품 공간 story와 출처 분리 |
| 추억 링크 공유·AI 캡션 | memories links, `/memories/s/{token}`; `/api/memories/{id}/caption` | 공유 링크는 shared 기록만, 읽기 전용·유효기간/회수. AI는 설정/사용권한이 있어야 함. [C12][C24] | 카드에는 공유 가능 상태·기록 열기만. 토큰/링크 관리·AI 작성은 전체 화면 |
| 여행 계획·여행 기록 | memories 안 travel-dialog; `/api/travel-plans`, `/{id}/records` | 본인 계획 CRUD, 목적지·기간·단계·완료 상태·내 스레드 연결, revision 충돌. 계획은 항상 private; 연결 글의 공개 범위 유지. AI 요청문 복사+MCP 여행 read/write 권한 안내, 자동 일정 생성으로 오해 금지. [C13] | 개인 여행 위젯 가치 높음. 공유 홈에 기본 노출 금지 |
| AI 비서·검색·요약·음성·브리핑 | `/ai`; `/api/ai/status`, assistant/search/transcribe/briefing; pages ai-summary/ai-insert | 서버 설정에 따라 기능별 활성화, external_data_mode·문서 AI허용 범위·일일 사용한도/로그. 실제 제공 여부는 설정/키에 조건부. [C24] | 상태+진입/최근 필요 작업 제안 정도. 대화/음성/검토 없이 본문 삽입은 전체 화면 |
| 외부 알림·데이터 연결 | `/integrations`; `/api/me/messenger`, `/api/admin/messenger-hooks`, `/api/admin/connectors` | 본인 알림 종류 선택, Admin webhook/connector 설정. 외부 데이터 연결 DB의 external_key 행은 사용자 편집 제한. 실제 연동은 활성화·허용 서버·비밀 설정/작업기에 조건부. [C25][C6:424] | 일반 홈은 연결 진입만. 관리 상태 모니터링은 관리자 전용 선택형 |
| 웹 게시·권한 그룹 | pages/web-publish; `/pub/{token}`; `/api/groups`·group-shares | 웹 게시 범위·기한/회수·폼, Admin 공간 게시 허용; 공유 그룹 관리와 문서 접근 별도 판정. 공개 링크는 회사·모임 멤버십을 만드는 기능이 아님. [C1:109][C26] | 공개 문서 링크/내 게시 현황 선택형. 공개·권한 변경은 전체 화면 |
| 문서 버전·처리 이력·휴지통·인계·내보내기 | versions/history/trash/restore/handover/export; `/p/{page}/print` | 현재 문서 권한 기반 버전 읽기/복원; 30일 휴지통, 소유권 인계, MD/HTML/하위 트리 내보내기·인쇄. Admin 예외 열람이 일괄 내보내기 허용으로 확대되지 않음. [C1:231][C27] | 문서 상세에 유지. ‘최근 편집’ 링크 이상을 홈에 반복하지 않음 |
| 옛 커뮤니티 GPT 대화 | `/api/messenger/gpt` GET/POST; `messenger_api.php` | **휴면**. 실제 핸들러 주석은 화면 호출 없음, 키 없으면 POST503 명시. 옛 커뮤니티 사람 대화 라우트는 제거되어 `/tbox/api/messages*`로 통합. 구현 파일 존재를 현재 활성 서비스로 오해하면 안 됨. [C29] | 위젯 후보에서 제외. 새 문서 AI와 중복 진입 만들지 않음 |
| 사용 안내·편집기 보조·관리 도구 | `/api/me/guide`; embed-domains/palettes/adminpopular/adminAI | 본인 안내 상태; 허용 임베드·팔레트·관리자 조회수 통계/AI 설정. 관리 도구는 해당 Admin 권한/재인증. [C28][C1:286] | 사용 안내는 접을 수 있는 도움말. 일반 업무 위젯에 운영 설정/조회수 순위 넣지 않음 |

일반 권한 공통: `community.read`는 로그인 관문이며 개별 페이지 접근을 보장하지 않는다. `community.write`는 `canCommunityWrite`와 행별 판정을 함께 적용한다(Guest 쓰기 불가, 역할로 보기에서 좁힌 권한 유지). 예외적으로 **travel.read와 travel.write 둘 다 canCommunityWrite를 요구**하므로 여행은 일반 community.read와 다르다. public 게시/폼/추억 링크는 전용 토큰 관문이며 로그인 회원 목록 API와 섞지 않는다. 관리자 도구는 일반 위젯의 전역 상태 수집원으로 사용하지 않는다. [C30]

## 추천 위젯의 S/M/L 콘텐츠

개수는 기획상의 최대 표시량이며 실제 API total을 의미하지 않는다. 크기가 작아질수록 표시 항목 상한과 전체 보기로 읽기 부담을 줄인다. 긴 내부 스크롤을 기본 탐색 방식으로 삼지 않는다. L에서도 모바일은 읽기 쉬운 날짜순 agenda/list로 바꾸고 복잡한 월 달력·표·타임라인은 전체 페이지에서 연다.

| 제안 위젯 / 출처 | S | M | L | 기본 주행동 |
|---|---|---|---|---|
| 내 작업 / community my-tasks | 가장 급한 작업 1개, 기한·상태 | 지난 기한/오늘 중심 3~5개, 프로젝트 이름 | 5개 bucket 전환, 8~12개, 담당·하위 작업 진행·더 있음 | ‘내 작업 보기’; 권한 있는 경우 ‘작업 추가’ 보조 |
| 오늘 챙길 것 / briefing | 우선순위 높은 항목 1개+이유 | 기한/공지/멘션 등 상위 4~6개 | 종류 필터·놓친 것 구분·8~12개, 원문 앵커 | 가장 급한 원문 열기 |
| 선택한 프로젝트 / DB purpose project | 이름·최근 상태·보이는 작업 진행률 | 기한·최근 상태 글·급한 작업 3개 | 상태+진행률+섹션별 작업 6~10개 | 프로젝트 열기 |
| 프로젝트 모음 / portfolio | 상태별 요약 1~2개 | 3~5 프로젝트, 상태·기한·진행률 | 프로젝트 비교표 6~10개, 최근 상태 글 | 포트폴리오 열기 |
| 문서 바로가기 / favorites or collection | 핀 문서 1~2개 | 문서 4~6개+공간 | 선택 모음/최근/즐겨찾기 전환+8~12개 | 문서 열기 |
| 업무 목록 / selected DB+saved view | 선택 뷰의 첫 1~2행 | 4~6행, 핵심 속성 2개 | 목록/보드 탐색, 필터 상태·8~12행 | 데이터베이스 열기 |
| 업무 지표 / selected DB chart | number 수치 1개+집계 정의 | 간단 bar/donut + 범례 | 기간 선택, line/bar, 필터·빈값·집계 정의 | 원본 DB/뷰 보기 |
| 내 작업 일정 / my-tasks due | 다음 기한 1개 | 이번 주 날짜순 3~5개 | 월 보기+선택일 작업 | 해당 작업 열기 |
| DB 일정 / saved calendar | 다음 날짜 항목 1개 | 주간 일정·3~5개 | 월/타임라인, 날짜 속성·기간 표시 | 원본 일정 뷰 열기 |
| 선택 게시판 / boards | 최신 공지/질문 1개 | 새 글 3~5개, 게시판 종류·날짜 | 태그/미읽음/질문 상태+6~10개 | 게시판 또는 글 열기 |
| 나에게 온 알림 / notifications | 최근 알림 1개 | 미읽음 중심 4~6개 | 유형 필터·8~12개, 현재 접근 가능 문맥 | 알림 원문 열기 |
| 검토 요청 / me-suggestions | 열린 제안 1개 | 문서·작성자·상태 3~5개 | 필터+8~12개, 요약만 | 제안 diff 화면 열기 |
| 절차서 확인 / procedures | 가장 최근/만료 절차서 1개 | 3~5개, 검증 상태·공간 | 검증 필터+목록 8~12개 | 절차서 읽기 |
| 자동 작업 상태 / automations | 다음 실행 또는 최근 실패 1개 | 내 규칙 3~5개, enabled/다음/최근 결과 | 규칙별 상태표+최근 실행 링크 | 자동 작업 관리 열기 |
| 우리 이야기 / memories filtered feed | 기록 1개, 작은 사진·날짜 | 2~3개 피드, 장소·작성자 | 4~6개 앨범/피드 전환, 원문 열기 | 이야기 보기; 작성 권한 있으면 ‘기록 남기기’ 보조 |
| 내 여행 / owner travel-plans | 가까운 여행 이름·기간 | 목적지·완료/전체 단계·다음 단계 | 단계 4~6개·연결 기록 2~3개 | 이야기 화면의 여행 계획 열기(전용 딥링크 추가 필요) |
| 빠른 문서 만들기 / templates | 자주 쓰는 템플릿 1개 | 허용 템플릿 3~4개 | 유형/범위별 6개, 제목+설명 | 선택 템플릿으로 만들기(전체 페이지에서 위치·범위 결정) |
| 폼 바로가기 / saved form | 이름·응답하기 | 설명·공개/닫힘 상태 | 간단 미리보기+응답 열기; 관리자는 권한 있는 응답 DB 요약 | 폼 열기 |
| AI 도구 / ai status | 사용 가능 기능+AI 열기 | 질문/요약/음성 등 활성화 기능 진입 | 사용 범위 안내·남은 한도+기능별 진입 | AI 비서 열기 |

S에서도 제목·출처·현재 상태·주행동을 남긴다. 공간 이름/DB뷰 이름은 긴 경우 줄바꿈하며 권한이나 원문을 잃은 카드는 제목을 비롯한 오래된 내용을 비운다. L을 ‘전체 편집기를 작은 상자에 넣기’로 해석하지 않는다.

## 기존 API로 바로 가능한 것과 추가 계약이 필요한 것

**기존 읽기 원본 활용 가능:** 내 작업, 브리핑, 선택 포트폴리오, 선택 DB행/차트/캘린더, 즐겨찾기·최근·모음, 특정 게시판 목록, 내 알림/제안, 절차서, 내 자동작업 상태, memories feed, 내 여행 목록. 기능마다 API 가시성 조건과 페이징을 그대로 사용해야 한다.

**추가 설계/어댑터 필요:** 내 프로젝트 목록은 현재 서버 렌더 함수이므로 JSON 어댑터 없이 새 API를 상상하면 안 된다. 여행은 native dialog로 열리는 기능이므로 홈에서 특정 여행을 바로 여는 진입 계약이 필요하다. template create는 대상 위치·공개 범위를 결정해야 하므로 카드 클릭만으로 임의 생성하지 않는다. 별도 문서 공간과 회사·모임 제품 공간의 공통 일정/작업 집계는 새 계약이 필요하다. 현재 개인 API를 공유 홈에서 재사용해 개인 항목을 공간 전체 요약이라고 표시하면 안 된다.

**수치 주의:** my-tasks `buckets.items.length`는 받은 수이고 `next_cursor`가 있으면 더 존재한다. 정확한 전체 개수 API는 이 응답에 없다. ‘총 할 일 100개’ 식 KPI로 만들지 말고 ‘100+ / 더 있음’ 또는 표시 항목 수를 쓰거나 합계 API를 별도로 설계한다. DB summary/chart와 포트폴리오의 total/progress는 실제 권한 필터를 적용한 합계이므로 정의를 붙여 사용할 수 있다. 브리핑은 제한된 우선 항목 projection이지 전사 업무 현황 통계가 아니다.

**읽기 부작용:** 페이지 GET은 공지 읽음/최근/조회수 기록과 연결된다. 매 위젯 refresh마다 문서 상세 GET을 여러 번 호출해 ‘읽음’을 발생시키지 않도록 목록·summary 기반 설계를 우선한다. `/api/me/space`도 개인 공간 초기 생성 기능이 있으므로 ‘읽기만 하는 모든 위젯’의 공통 초기화로 호출하지 않는다. 소스 감사에서는 어떠한 API도 호출하지 않았다.

## 권장 우선순위와 전체 화면 유지

1. 개인 기본: 내 작업, 오늘 챙길 것, 문서 바로가기. 모임 중심 사용자는 우리 이야기와 내 여행을 선택해서 추가한다.
2. 회사 구성용 선택: 선택 프로젝트, 포트폴리오, DB업무 목록/지표/일정, 공지, 절차서. 각 ‘누구의/어느 공간의/어느 DB뷰인지’를 고정 표시한다.
3. 선택형: 내 알림/검토 요청/자동작업/템플릿/폼. 기본 홈을 관리자 기능과 보조 설정으로 채우지 않는다.
4. 전체 화면 유지: 본문 편집, DB스키마 편집·복잡한 필터, 프로젝트 상세·포트폴리오 관리, 여행 편집, 제안 diff 승인, 자동작업 실행/되돌리기, 공개/공유/인계/삭제·복구, 관리자 AI/커넥터 설정.

위젯별 잠금은 콘텐츠·원문 권한을 바꾸는 기능이 아니라 geometry 편집 방지다. 전체 배치 저장은 한 revision 충돌 단위로 유지한다. 내용 숨김은 데이터 삭제·권한 회수가 아니다. 이 조사 단계에서는 구현하지 않는다.

## 코드 근거와 문서 교차 확인

아래 경로의 줄 번호는 조사한 HEAD 기준이다. 문서의 과거 ‘설계/운영 적용 대기’ 표현은 현재 코드 연결 여부와 구분했다. 특히 PRD의 과거 별도앱 설명과 ARCH의 미구현 단계 설명을 현재 기능으로 단정하지 않았다.


- **C1**: [src/community/routes.php:77](/var/www/qaredi/tbox-v2/src/community/routes.php:77), [src/community/routes.php:163](/var/www/qaredi/tbox-v2/src/community/routes.php:163)
- **C2**: [src/community/handlers/work_home_page.php:10](/var/www/qaredi/tbox-v2/src/community/handlers/work_home_page.php:10), [src/community/handlers/work_home_page.php:48](/var/www/qaredi/tbox-v2/src/community/handlers/work_home_page.php:48)
- **C3**: [src/community/handlers/my_tasks.php:8](/var/www/qaredi/tbox-v2/src/community/handlers/my_tasks.php:8), [src/community/handlers/my_tasks.php:180](/var/www/qaredi/tbox-v2/src/community/handlers/my_tasks.php:180)
- **C4**: [src/community/Briefing.php:10](/var/www/qaredi/tbox-v2/src/community/Briefing.php:10), [src/community/Briefing.php:139](/var/www/qaredi/tbox-v2/src/community/Briefing.php:139), [src/community/handlers/briefing.php:4](/var/www/qaredi/tbox-v2/src/community/handlers/briefing.php:4)
- **C5**: [src/community/handlers/my_tasks_page.php:5](/var/www/qaredi/tbox-v2/src/community/handlers/my_tasks_page.php:5), [public/assets/community/work.js:1087](/var/www/qaredi/tbox-v2/public/assets/community/work.js:1087), [public/assets/community/work.js:1168](/var/www/qaredi/tbox-v2/public/assets/community/work.js:1168)
- **C6**: [src/community/handlers/pages_get.php:334](/var/www/qaredi/tbox-v2/src/community/handlers/pages_get.php:334), [src/community/handlers/pages_get.php:415](/var/www/qaredi/tbox-v2/src/community/handlers/pages_get.php:415), [src/community/handlers/pages_properties.php:13](/var/www/qaredi/tbox-v2/src/community/handlers/pages_properties.php:13)
- **C7**: [src/community/routes.php:2959](/var/www/qaredi/tbox-v2/src/community/routes.php:2959), [src/community/handlers/project_status.php:1](/var/www/qaredi/tbox-v2/src/community/handlers/project_status.php:1), [public/assets/community/work.js:1254](/var/www/qaredi/tbox-v2/public/assets/community/work.js:1254)
- **C8**: [src/community/handlers/portfolio_page.php:5](/var/www/qaredi/tbox-v2/src/community/handlers/portfolio_page.php:5), [src/community/handlers/portfolios.php:233](/var/www/qaredi/tbox-v2/src/community/handlers/portfolios.php:233), [src/community/routes.php:2762](/var/www/qaredi/tbox-v2/src/community/routes.php:2762)
- **C9**: [public/assets/community/work.js:1204](/var/www/qaredi/tbox-v2/public/assets/community/work.js:1204), [public/assets/community/calendar.js:5](/var/www/qaredi/tbox-v2/public/assets/community/calendar.js:5), [public/assets/community/calendar.js:23](/var/www/qaredi/tbox-v2/public/assets/community/calendar.js:23)
- **C10**: [public/assets/community/doc-schema.json:104](/var/www/qaredi/tbox-v2/public/assets/community/doc-schema.json:104), [public/assets/community/editor.js:3643](/var/www/qaredi/tbox-v2/public/assets/community/editor.js:3643), [public/assets/community/drafts.js:1](/var/www/qaredi/tbox-v2/public/assets/community/drafts.js:1)
- **C11**: [src/community/handlers/collections.php:5](/var/www/qaredi/tbox-v2/src/community/handlers/collections.php:5), [src/community/handlers/collections.php:20](/var/www/qaredi/tbox-v2/src/community/handlers/collections.php:20)
- **C12**: [src/community/handlers/memories_page.php:74](/var/www/qaredi/tbox-v2/src/community/handlers/memories_page.php:74), [src/community/handlers/memories_page.php:94](/var/www/qaredi/tbox-v2/src/community/handlers/memories_page.php:94), [src/community/Memories.php:205](/var/www/qaredi/tbox-v2/src/community/Memories.php:205)
- **C13**: [src/community/TravelPlans.php:4](/var/www/qaredi/tbox-v2/src/community/TravelPlans.php:4), [src/community/TravelPlans.php:91](/var/www/qaredi/tbox-v2/src/community/TravelPlans.php:91), [src/community/handlers/travel_plans_panel.php:3](/var/www/qaredi/tbox-v2/src/community/handlers/travel_plans_panel.php:3), [src/community/handlers/memories_page.php:131](/var/www/qaredi/tbox-v2/src/community/handlers/memories_page.php:131)
- **C14**: [src/community/handlers/search.php:10](/var/www/qaredi/tbox-v2/src/community/handlers/search.php:10), [src/community/handlers/search.php:24](/var/www/qaredi/tbox-v2/src/community/handlers/search.php:24)
- **C15**: [src/community/DbViews.php:9](/var/www/qaredi/tbox-v2/src/community/DbViews.php:9), [src/community/handlers/database_rows.php:162](/var/www/qaredi/tbox-v2/src/community/handlers/database_rows.php:162), [src/community/handlers/database_summary.php:55](/var/www/qaredi/tbox-v2/src/community/handlers/database_summary.php:55), [src/community/handlers/page_view.php:307](/var/www/qaredi/tbox-v2/src/community/handlers/page_view.php:307)
- **C16**: [src/community/handlers/database_chart.php:4](/var/www/qaredi/tbox-v2/src/community/handlers/database_chart.php:4), [src/community/DbViews.php:83](/var/www/qaredi/tbox-v2/src/community/DbViews.php:83), [public/assets/community/charts.js:5](/var/www/qaredi/tbox-v2/public/assets/community/charts.js:5)
- **C17**: [public/assets/community/dashboard.js:3](/var/www/qaredi/tbox-v2/public/assets/community/dashboard.js:3), [public/assets/community/dashboard.js:21](/var/www/qaredi/tbox-v2/public/assets/community/dashboard.js:21), [src/community/handlers/pages_get.php:372](/var/www/qaredi/tbox-v2/src/community/handlers/pages_get.php:372)
- **C18**: [src/community/handlers/form_get.php:3](/var/www/qaredi/tbox-v2/src/community/handlers/form_get.php:3), [src/community/handlers/form_submit.php:1](/var/www/qaredi/tbox-v2/src/community/handlers/form_submit.php:1), [src/community/handlers/pub_form_submit.php:4](/var/www/qaredi/tbox-v2/src/community/handlers/pub_form_submit.php:4), [src/community/DbViews.php:29](/var/www/qaredi/tbox-v2/src/community/DbViews.php:29)
- **C19**: [src/community/handlers/notifications_list.php:50](/var/www/qaredi/tbox-v2/src/community/handlers/notifications_list.php:50), [src/community/handlers/notifications_list.php:90](/var/www/qaredi/tbox-v2/src/community/handlers/notifications_list.php:90), [src/community/routes.php:334](/var/www/qaredi/tbox-v2/src/community/routes.php:334)
- **C20**: [src/community/handlers/procedures_list.php:3](/var/www/qaredi/tbox-v2/src/community/handlers/procedures_list.php:3), [src/community/handlers/pages_properties.php:227](/var/www/qaredi/tbox-v2/src/community/handlers/pages_properties.php:227)
- **C21**: [src/community/Automation.php:24](/var/www/qaredi/tbox-v2/src/community/Automation.php:24), [src/community/Automation.php:44](/var/www/qaredi/tbox-v2/src/community/Automation.php:44), [src/community/handlers/automations_list.php:5](/var/www/qaredi/tbox-v2/src/community/handlers/automations_list.php:5), [src/community/routes.php:4290](/var/www/qaredi/tbox-v2/src/community/routes.php:4290)
- **C22**: [src/community/handlers/me_suggestions.php:1](/var/www/qaredi/tbox-v2/src/community/handlers/me_suggestions.php:1), [src/community/handlers/suggestion_accept.php:1](/var/www/qaredi/tbox-v2/src/community/handlers/suggestion_accept.php:1), [src/community/routes.php:90](/var/www/qaredi/tbox-v2/src/community/routes.php:90)
- **C23**: [src/community/handlers/home_page.php:417](/var/www/qaredi/tbox-v2/src/community/handlers/home_page.php:417), [src/community/handlers/page_view.php:625](/var/www/qaredi/tbox-v2/src/community/handlers/page_view.php:625), [src/community/routes.php:279](/var/www/qaredi/tbox-v2/src/community/routes.php:279)
- **C24**: [src/community/ai_helpers.php:151](/var/www/qaredi/tbox-v2/src/community/ai_helpers.php:151), [src/community/ai_helpers.php:57](/var/www/qaredi/tbox-v2/src/community/ai_helpers.php:57), [src/community/handlers/ai_page.php:8](/var/www/qaredi/tbox-v2/src/community/handlers/ai_page.php:8), [src/community/routes.php:148](/var/www/qaredi/tbox-v2/src/community/routes.php:148)
- **C25**: [src/community/handlers/integration_settings.php:7](/var/www/qaredi/tbox-v2/src/community/handlers/integration_settings.php:7), [src/community/handlers/integration_settings.php:23](/var/www/qaredi/tbox-v2/src/community/handlers/integration_settings.php:23), [src/community/handlers/pages_get.php:424](/var/www/qaredi/tbox-v2/src/community/handlers/pages_get.php:424)
- **C26**: [src/community/routes.php:109](/var/www/qaredi/tbox-v2/src/community/routes.php:109), [src/community/CommunityAuthz.php:76](/var/www/qaredi/tbox-v2/src/community/CommunityAuthz.php:76), [src/community/handlers/pub_form_submit.php:22](/var/www/qaredi/tbox-v2/src/community/handlers/pub_form_submit.php:22)
- **C27**: [src/community/routes.php:231](/var/www/qaredi/tbox-v2/src/community/routes.php:231), [src/community/handlers/pages_export.php:4](/var/www/qaredi/tbox-v2/src/community/handlers/pages_export.php:4), [src/community/CommunityAuthz.php:100](/var/www/qaredi/tbox-v2/src/community/CommunityAuthz.php:100)
- **C28**: [src/community/handlers/usage_guide_api.php:3](/var/www/qaredi/tbox-v2/src/community/handlers/usage_guide_api.php:3), [src/community/routes.php:294](/var/www/qaredi/tbox-v2/src/community/routes.php:294), [src/community/routes.php:307](/var/www/qaredi/tbox-v2/src/community/routes.php:307)
- **C29**: [src/community/handlers/messenger_api.php:4](/var/www/qaredi/tbox-v2/src/community/handlers/messenger_api.php:4), [src/community/handlers/messenger_api.php:15](/var/www/qaredi/tbox-v2/src/community/handlers/messenger_api.php:15)
- **C1:109**: [src/community/routes.php:109](/var/www/qaredi/tbox-v2/src/community/routes.php:109)
- **C1:189**: [src/community/routes.php:189](/var/www/qaredi/tbox-v2/src/community/routes.php:189)
- **C1:224**: [src/community/routes.php:224](/var/www/qaredi/tbox-v2/src/community/routes.php:224)
- **C1:231**: [src/community/routes.php:231](/var/www/qaredi/tbox-v2/src/community/routes.php:231)
- **C1:255**: [src/community/routes.php:255](/var/www/qaredi/tbox-v2/src/community/routes.php:255)
- **C1:286**: [src/community/routes.php:286](/var/www/qaredi/tbox-v2/src/community/routes.php:286)
- **C1:327**: [src/community/routes.php:327](/var/www/qaredi/tbox-v2/src/community/routes.php:327)
- **C1:330**: [src/community/routes.php:330](/var/www/qaredi/tbox-v2/src/community/routes.php:330)
- **C1:348**: [src/community/routes.php:348](/var/www/qaredi/tbox-v2/src/community/routes.php:348)

문서 교차 근거(현재 동작 확정은 위 코드 우선):
- [docs/server-context/community/ARCHITECTURE.md:438](/var/www/qaredi/tbox-v2/docs/server-context/community/ARCHITECTURE.md:438)
- [docs/server-context/community/ARCHITECTURE.md:748](/var/www/qaredi/tbox-v2/docs/server-context/community/ARCHITECTURE.md:748)
- [docs/server-context/community/ARCHITECTURE.md:924](/var/www/qaredi/tbox-v2/docs/server-context/community/ARCHITECTURE.md:924)
- [docs/server-context/community/ARCHITECTURE.md:1039](/var/www/qaredi/tbox-v2/docs/server-context/community/ARCHITECTURE.md:1039)
- [docs/server-context/community/ARCHITECTURE.md:1061](/var/www/qaredi/tbox-v2/docs/server-context/community/ARCHITECTURE.md:1061)
- [docs/server-context/community/ARCHITECTURE.md:1085](/var/www/qaredi/tbox-v2/docs/server-context/community/ARCHITECTURE.md:1085)
- [docs/server-context/community/PRD.md:1](/var/www/qaredi/tbox-v2/docs/server-context/community/PRD.md:1)

구현 상태의 다음 확인이 필요한 항목: 운영 DB 스키마 준비 상태(라우터503 관문), 예약 작업기·외부 커넥터 활성 여부, AI 키/기능·문서 AI허용 범위, 현재 실제 데이터가 있는 DB/프로젝트/포트폴리오. 이 조사에서는 모두 읽기 전용 코드 상태까지 확인했으며 이를 운영 성공으로 표시하지 않았다.

- **C6:424**: [src/community/handlers/pages_get.php:424](/var/www/qaredi/tbox-v2/src/community/handlers/pages_get.php:424)
- **C30**: [src/Authz.php:1665](/var/www/qaredi/tbox-v2/src/Authz.php:1665), [src/Authz.php:1826](/var/www/qaredi/tbox-v2/src/Authz.php:1826), [src/community/CommunityAuthz.php:279](/var/www/qaredi/tbox-v2/src/community/CommunityAuthz.php:279)
