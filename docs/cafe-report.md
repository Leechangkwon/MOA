# 카페 보고서 (지시서 `docs/cafe-directive.md`)

## C1 — 보안 바닥 (서버) · 2026-10-14 · Opus 구현 에이전트

### 변경 파일
- DB: `db/migrations/2026-10-14_community_cafe.sql`(새 — spaces.area · board_kind anonymous · CHECK 셋 · anon_aliases · reports · board_requests · 알림 종류 누적 CHECK · GRANT · 자유게시판 → cafe · 익명게시판 행 · 되돌리기 주석) · `db/community/schema.sql` · `db/community/grants.sql`
- 판정: `src/community/CommunityAuthz.php` — BOARD_KINDS + anonymous · `decidePublish(…, $board)` 갈래 · `decideSuggestionCreate` 갈래 · `boardOfRow` 에 area · 새 `decideReportCreate` · `decideReportManage` · `decideAnonReveal` · `decideBoardRequest`
- 가림: `src/community/anon_helpers.php`(새) · `src/community/routes.php`(require · 라우트 8줄 · page_row 읽기 열 area 둘 · `community_board_create` · `community_page_hide_write` · `community_comment_hide_write` · 검색 댓글 SQL 이름표 재료) · `src/community/BoardCursor.php`
- 핸들러(고침): pages_get · board_pages_list · board_pages_create · comments_create · comments_list · search · notifications_list · history · versions_list · version_get · mention_candidates · procedures_list · pages_properties · pages_update · attachments_upload · pages_hide · comment_hide · boards_create · boards_list · `export_helpers.php` · (표식) pages_delete · page_restore · version_restore · pages_tags_put · comment_update · comment_delete · comment_resolve · (목록 밖 — 아래) pages_publish · `SaveDoc.php`
- 핸들러(새): reports_create · reports_list · report_resolve · report_reveal · board_requests_create · board_requests_list · board_request_decide · cafe_home
- 감사: `src/Audit.php`(새 동작 다섯의 틀 12줄 — report.create · report.resolve · anon.reveal · board_request.create · board_request.decide) · `src/handlers/audit_list.php`(가림 · 누가/IP 필터 제외 — 한 벌의 SQL 조각 + 함수)
- 그 밖: `src/community/Memories.php`(stripPhotoMetadata → public static 만)
- 시험: `tests/community/cafe_c1_http.py`(새) · `tests/community/memories_harness.py`(두 자리) · `tests/catalog.json`(community.cafe_c1_http)

### 감사 표식(`detail.anon_board`)을 단 커뮤니티 자리 — `community_anon_audit_detail`
board_pages_create(page.create) · SaveDoc(page.update — pages_update 의 몸) · pages_delete(page.delete) · page_restore(page.restore) · version_restore(page.restore_version) · pages_properties(page.property · page.assign) · pages_tags_put(page.tags) · comments_create(comment.create) · comment_update · comment_delete · comment_resolve(comment.resolve · unresolve) · attachments_upload(attachment.upload) · export_helpers(page.export) · reports_create(report.create — 관리자 화면에서는 가리지 않음).
일부러 안 단 것: page.hide/unhide · comment.hide/unhide(관리자의 행위) · attachment.download(읽는 사람) · page.read(게시판 글은 감사 대상 갈래 없음) · 거부 행(행위자가 쓴 사람이 아님). 합성 DB 실측 표식 동작: attachment.upload · comment.create · comment.resolve · page.create · page.export · page.property · page.update · report.create.

### 수락 시험
- 명령: `python3 tests/community/memories_harness.py --suite cafe_c1_http.py --output /home/changkwon/qaredi-cafe-results-20261014/c1-run2`
- 결과: **pass 209 · fail 0**(rc 0) — `c1-run2/cafe-c1-results.json`(단언 · 확정 값) · `cafe-c1-schema-shape.txt` · `stdout.txt` · `http.log`. 첫 실행 `c1-run1` 은 207/2(시험 순서 — 숨긴 글의 댓글 쓴 사람은 그 글을 못 봐 알림 0 이 맞음 · 질의 수 기대값 3 → 실제 3 + 권한 표 1)를 시험 쪽만 고쳐 다시 돌렸다.
- 확정 값: 별칭 W=0 · A=1 · B=2 · 이름표 익명1/익명2/글쓴이/익명1 · /api/cafe 질의 4 = 4(행 3 → 10, 셋 + Authz 권한 표 한 번) · tbox_app 권한 `false,false,false,false,false,true`.
- 정적(⑪): `security_lint` 통과 0건 · `php -l` 44 파일 0 오류 · `git diff --check` 0 — `c1-static.txt`. 마이그레이션 두 번은 틀이 하고, 시험이 첫 게시판 셋을 넣은 뒤 두 번 더 돌려 SAME.
- 다른 회귀(stage* · qf12_* 등)는 지시대로 돌리지 않았다.

### 못 한 것
- ARCH 7.4 감사 문장 표 · SECURITY C:R-18 · ARCH 25 의 문서 갱신 — 허용 목록 밖(메인 몫).
- AI 넣기(`ai_helpers.php` `ai.insert`)는 표식이 없다 — 목록 밖 파일. 행에 target_name 이 없어 글과 바로 잇지는 못하지만 `actor=<W>` 필터에 시각으로 남는다.
- 자료실 참조 거부는 `pages_update.php` 창구에만(지시대로) — AI 넣기 길은 같은 검사를 지나지 않는다(수정 제안은 익명게시판에서 꺼짐).
- 신고 · 요청의 거부(400/404/409, 손님 403)는 감사하지 않고 서버 로그만 — 새 동작 틀을 다섯 동작으로 묶느라 rate_limited 만 deny 행.

### ARCH 25장과 다르게 한 것
- **목록 밖 파일 둘**: `pages_publish.php`(익명게시판 게시 거부 — `decidePublish` 가 게시할 게시판을 알아야 함, 403 `anon_board`) · `SaveDoc.php`(page.update 감사 표식 한 줄 — pages_update 의 실제 몸).
- `anon_helpers.php` 의 헬퍼가 다섯 + 셋: `community_anon_page_sql`(목록 SQL 짝 — 절차 목록) · `community_anon_label`(이름표 규칙 하나) · `community_anon_is_label`(안전망). `community_anon_assign_alias` 가 다섯째.
- 응답에 더한 키: 이력 · 판의 `by_is_me`(익명 글만) · 알림 `moderation_note`(moderated 만) · boards_list 항목 `area` · `anonymous`(community_board_summary 는 그대로 — 업무 응답 키 0 변경).
- 감사 가림이 ARCH 보다 넓다: actor · ip · login_ref 에 더해 user_agent · actor_role · autoDetail 의 actor_team · token_id · acting_as · real_role 도 뺀다.
- 「하루」 상한은 24시간 굴림. `decideReportCreate` 는 관리자 특권 갈래(admin_all · board_moderator)로만 보는 글을 「못 봄」(404)으로 본다.
- board_request 알림은 페이지 없는 행(요청 번호를 잇는 열 없음 — 화면은 신고함 · 요청 목록으로).
- schema.sql 은 익명게시판 행을 넣지 않는다(다른 첫 게시판처럼 이주 파일만).
- 게시판 만들기 검사가 공용 함수로 옮겨져 400 검사가 트랜잭션 안에서 난다(응답 같음 · 요청 끝 되돌림 로그 한 줄).

## C1b — 검토 보정 · 2026-10-14 · Opus 구현 에이전트

### 변경 파일
- `src/community/ai_helpers.php` — `ai.insert` 감사 detail 을 `community_anon_audit_detail($p, …)` 로(한 줄).
- `tests/community/cafe_c1_http.py` — ② 카나리아 단언에 A · B 의 아이디 · 표시 이름(`no_names` — 준비에서 DB 로 읽음. 합성 fixture 의 A · B 는 표시 이름이 비어 있어 아이디만 셈: `wab_names_checked` W 2 · A 1 · B 1)을 더함: E · M 시점 모든 자리 · 알림(W/A/B 시점은 본인 이름만 빼고) · 신고함의 익명 대상 칸(신고자 이름은 설계상 보이므로 대상 칸만) · 확인 뒤 댓글 목록 · 익명 표식 감사 행. C1b① AI 넣기: 합성 `ai_log` 행(status ok · summary · output_text) → `POST /api/pages/{id}/ai-insert` 200 → `detail.anon_board = true` · `actor=<W>` 필터에 그 행 0 · 감사 화면에서 「익명(익명게시판)」 · ip null(`ai.insert` 는 「어떻게」 필터 목록 밖이라 최신 첫 쪽에서 찾음).

### 수락 시험
- `python3 tests/community/memories_harness.py --suite cafe_c1_http.py --output /home/changkwon/qaredi-cafe-results-20261014/c1b-run1` → **pass 215 · fail 0**(rc 0). 정적: security_lint 0 · php -l 0 · git diff --check 0 — `c1b-static.txt`.

### 못 한 것
- 없음(AI 넣기 길은 틀에서 막히지 않아 「미검증」 0).

## C2 — 화면 · 2026-10-14 · Opus 구현 에이전트

### 변경 파일
- 서버 화면: `src/views/space_page.php`(맨 위 `section.cafe-home` 뼈대 · 「내 공간」 h2 · `data-community-base` · `data-cafe-js`) · `src/views/shell_top.php`(받는 변수 `$shellArea` — 허용 값 `'cafe'` 하나 · `<body data-area="cafe">` · 「우리 공간」 모드 사이드바: 브랜드 「우리 공간」(아이콘 spaces · `/tbox/spaces`) · 카페 홈 · 받은 알림 · 검색 · 카페 게시판 · 관리(신고함 · 게시판 요청 — hidden, can_admin 일 때 화면이 연다) — 업무 트리 · 프로젝트 · 포트폴리오 · 팀 · 문서 · 즐겨찾기 · 최근 · 더보기 · 새로 만들기 0 · 셸 키 둘) · `src/community/handlers/board_view.php`(`community_board_row` + `decideBoardRead` 통과 때만 area 를 읽어 `$shellArea`) · `page_view.php`(`decidePageRead` 통과 때만 `boardOfRow` 의 area · 더보기 「신고」 항목 · 익명 안내 한 줄) · 새 `reports_page.php` · `board_requests_page.php`(뼈대만 — 탭 · 2.4 표 · 2.21 칸) · `src/community/routes.php`(라우트 2줄 `/reports` · `/board-requests` — role:Admin)
- JS: 새 `public/assets/community/cafe.js`(신고 모달 · 게시판 요청 모달 · 작성자 확인 모달 · 신고함 · 게시판 요청 화면 · 익명 이름표 `personInto` · `.anon-avatar` — 2.7 공용 창 한 벌, 닫히면 창째 DOM 에서 지움) · `editor.js`(import · `IS_CAFE` · 사이드바 `loadBoards` = `?area=work|cafe` · 관리 창 · 게시 창은 `allBoards`(전부 — 게시 창은 익명게시판 제외) · 게시판 목록 · 글 머리 · 댓글 이름표 · 안내 한 줄 · 신고(글 더보기 · 댓글 「…」 — 카페 · 쓰기 자격 · 내 것 아님) · 멘션 끔 · 그림/파일 창 자료실 탭 0 · 관리자 화면 둘 시작) · `work.js`(칸 댓글 이름표 「(나)」 · 칸이 익명게시판 글이면 멘션 끔) · `mention-input.js`(끄기 옵션 `disabled` 하나 — 목록 · 확인 색 · 후보 요청 0, 안내 한 줄) · `space-page.js`(카페 홈 — `GET /community/api/cafe` · 요청 모달은 cafe.js 동적 import)
- CSS: `space-page.css`(`.cafe-home` · `.cafe-boards` · `.cafe-board` · `.cafe-feed`) · `community.css`(`.anon-avatar` · 새 표 둘의 nth-child 폭 규칙) — 새 클래스는 2.29 의 다섯뿐 · 색값 0. `app.css` 는 C2 에서 손대지 않았다.
- 부품 버전: `php scripts/community_asset_version.php` 로 import 줄(dashboard · database · editor · suggestions · table-editor · templates · work · ../messenger.js)
- 시험: 새 `tests/community/cafe_c2_browser.py` · `memories_harness.py`(choices) · `tests/catalog.json`(community.cafe_c2_browser)
- 문서: 새 `wiki/community/guide-cafe.json`(「화면만 익명 — 신고가 들어오면 관리자가 확인할 수 있어요.」 문장 포함) · `wiki/community/index.json`
- 서버 판정 · 창구 · 보안 핵심 파일 변경 0(C1 창구가 준 값만 그린다 — 모자란 데이터 없음).

### 수락 시험
- 명령: `python3 tests/community/memories_harness.py --suite cafe_c2_browser.py --output /home/changkwon/qaredi-cafe-results-20261014/c2-run2` → **pass 298 · fail 0**(rc 0) — `c2-run2/cafe-c2-browser-results.json` · `cafe-c2-browser-metrics.json` · `shots/`(23장).
  묶음: [empty] 카드 · 빈 상태 · 손님 요청 단추 0 · 「내 공간」 아래 그대로 / [home] 새 글 · 인기 · 「글쓴이 (나)」 · 412 한 열 / [shell] data-area · 「우리 공간」 사이드바 · 업무 트리 0 · 관리 묶음 관리자만 · 업무 셸 게시판 = 공지 · 질문(자유 0) · 다른 팀 카페 게시판은 data-area 0 / [anon] 「글쓴이」 · 「익명1 (나)」 · 「익명2」 · 사람 아이콘 · 안내 한 줄 · @ 목록 0(후보 요청 0) · 자료실 탭 0(자유게시판 대조 — 탭 · @ 그대로) · 신고 모달(라디오 다섯 · 미선택 문구 · 201 · 409 「이미 신고한 글이에요」 · Esc 로 창 제거) · 댓글 「…」 신고 · 내 것 0 / [matrix] 360 · 412(터치) · 820 · 1199 · 1280 · 1440 × 밝게 · 어둡게 · 고대비 × 카페 홈 · 익명 글 · 신고함(칸 열림) 넘침 0 · 누름 자리 < 44 0 · 인라인 style 0 · 콘솔 0 / [reports] 직원 403 · 탭 셋 · 2.21 칸(fixed 400~480) · 메모 필수 · 재인증 창 → 사유 19자 막힘 → 20자 이상 200 · 이름은 창 안에만 · 저장소 · 주소 · 콘솔 0 · 닫으면 DOM 0 · 숨기기 → 숨김 탭 · 반려 → 반려 탭 / [request] 모달(40 · 500 · 익명 체크) · 201 · 관리자 반려(메모 필수) · 만들기(등급 기본 사내) → 만듦 탭 · 카페 홈 카드.
- 첫 실행 `c2-run1` 은 중간에 멈췄다(시험 쪽 둘: 행렬의 관리자 새 세션에 재인증을 달지 않음 · 테마 검정 단추 한계를 따로 세지 않음 — 시험만 고쳐 run2). run1 이 남긴 합성 DB · 임시 폴더 · php 서버는 손으로 지웠다(`dropdb --if-exists tbox_memories_test_8768_2866654` · `/tmp/qaredi-stage13-xb8_girx` 제거 · 포트 8768/8769 비움 확인).
- 정적: security_lint 0 · php -l 0 · node --check 0 · `community_asset_version.php --check` 0 · wiki_check 0 · git diff --check 0 — `c2-static.txt`.
- 다른 회귀는 돌리지 않았다. 옛 시험 단언 변경 0(검토: browser_5c 는 stage5c 인스턴스가 카페 이주를 돌리지 않아 자유게시판이 work 그대로 — 영향 없음으로 판단, 미실행).

### 못 한 것 · 한계
- 테마 design.css 의 검정 단추 「반짝」 `::before`(z-index -1 · inset 0)가 폰 폭의 터치 넓힘 `::before` 를 덮어 **검정 단추는 보이는 36px 그대로**다(예: 지금 있던 글 화면 「댓글 달기」 94×36). 이 조각 밖 파일(design.css)이라 고치지 않고, 시험은 이 한 종류를 `preexisting_black_btn` 으로 따로 센다(metrics). 메인 판단 필요.
- 접힌 사이드바(☰ 64px) · 960 미만 서랍에서의 「우리 공간」 모드는 넘침만 봤다(접힘 전용 단언 0).
- 작성자 확인 화면 흐름의 재인증은 합성 관리자 계정에 시험만 아는 비밀번호를 넣고 실제 공용 재인증 창(`/tbox/api/reauth`)을 지났다(fixture 비밀번호가 무작위라).

### DESIGN 과 다르게 한 것
- 신고 모달 바닥 「취소」 → **「닫기」**(2.7 「창 바닥 단추」 ② — 창 바닥에 「취소」를 쓰지 않는다 · dbg_u2_ui 가 센다). 요청 · 확인 · 결정 모달도 같다.
- 「신고했어요 — 관리자가 확인해요」 **토스트 부품이 없어** 화면 알림 줄(`#msg` / 댓글 알림 줄)에 성공 모양 `.msg--ok` 로 보인다(새 부품 · 클래스 0).
- 「우리 공간」 사이드바에 **받은 알림**을 둔다(2.29 목록에는 없음) — 익명 댓글 · 「관리자가 숨겼어요」 알림을 받는 길이고 종 · 창은 업무와 같은 한 벌.
- 신고함 표의 「대상」 칸은 `.btn--quiet.btn--small` 단추(키보드로 칸을 여는 자리) · 줄 전체 누름도 같은 칸.
- 「우리 공간」 화면의 머리글 순서: 카페 `h2` → 「내 공간」 `h2` → 지금 공간 이름 `h1`(지금 화면 그대로 두라는 지시라 h1 을 옮기지 않았다).
