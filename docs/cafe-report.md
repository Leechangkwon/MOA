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

## C3 — 오른쪽 위 「앱」 단추 · 2026-10-14 · Opus 구현 에이전트 (메인 추가 지시 · 기준 main 의 DESIGN 2.28 「앱 단추」 · 지시서 C3)

### 변경 파일
- `src/Apps.php` — `ICON_PATHS` 에 `apps`(2×2 둥근 네모 넷 · 각 8×8 · 반경 2.5 · 간격 4 · 채움용 path). 옛 `grid` 그대로.
- `src/views/shell_top.php` — 앱 단추 summary 의 `data-icon="apps"` · 그 자리에 `moa_icon.php`(Apps::ICON_PATHS 한 벌)로 svg 를 그린다(클래스 `btn btn--quiet` · aria-label 그대로).
- `public/assets/app.css` — 2.28 값: summary 높이 44 · 안 여백 0 16 0 12 · 15px 600 · 반경 --r-ctl · 바탕 --accent-soft · 테두리 --accent-line · 글자 · 아이콘 --accent-dark · 올림 --accent-line · 열림 --accent 위 --primary-fg · 초점 --ring-soft 3px · 아이콘 24 **채움 규칙**(fill currentColor · stroke none) · `.shell-tools` 줄 44 · 이름 칩 44(아바타 34). 색값 0(토큰뿐).
- `tests/community/cafe_c2_browser.py` — [apps] 묶음 더함. 옛 시험 단언 바꿈: `tests/community/qf12_u5_browser.py` [shell] 의 셋(앱 단추 40 · 14px 500 · 아이콘 20 → 44 · 15px 600 · 24 / 줄 40 → 44 / 이름 칩 40 · 아바타 32 → 44 · 34) — DESIGN 2.28 이 바뀐 값이라(그 시험은 돌리지 않음).

### 수락 시험
- `CAFE_C2_ONLY=apps` 개발 실행 `c3-dev1` pass 102 · fail 0 → 최종 판(C2 + C3 전부) 한 번: `python3 tests/community/memories_harness.py --suite cafe_c2_browser.py --output /home/changkwon/qaredi-cafe-results-20261014/c3-run1` → **pass 380 · fail 0**(rc 0).
- [apps] 1440 · 1280 · 960 × 밝게 · 어둡게 · 고대비: summary 높이 44 · 아이콘 apps 24×24 채움(fill = 글자색 · stroke none) · 바탕 = --accent-soft 계산값 · 글자 --accent-dark · 테두리 --accent-line · 15/600 · 여백 12/16 · 대비 닫힘 5.85 / 7.00 / 16.67 · 열림(--accent 위 --primary-fg) 6.70 / 7.88 / 18.88(밝게 / 어둡게 / 고대비 — 모두 ≥ 4.5) · 단추 아이콘 24 > 타일 아이콘 20 · 이름 칩 44 · 아바타 34 · 안 읽은 점 오른쪽 위 3px · 8px 그대로 · 넘침 0 · 콘솔 0 / 412: 줄 숨김(summary 0) · 윗줄 앱 이름 ▾ 그대로. 스크린샷 셋: `c3-run1/shots/apps-button-light.png` · `apps-button-dark.png` · `apps-button-open.png`.
- 정적: security_lint 0 · php -l 0 · asset_version --check 0 · wiki_check 0 · git diff --check 0 · Apps::all() 읽기 정상 — `c3-static.txt`.

### DESIGN 과 다르게 한 것 · 못 한 것
- 지시서는 shell_top 에서 「data-icon 과 클래스만」이었지만, 아이콘 틀(`<template id="tpl-icon-…">`)은 허용 목록 밖 `shell_bottom.php` 에 고정 목록으로 있어 `apps` 틀이 없다 — summary 안에서 `moa_icon.php` 로 svg 를 바로 그렸다(채운 자리는 app.js `paintIcons` 가 건너뛴다 · app.js 무변경).
- summary 클래스는 `btn btn--quiet` 를 그대로 둔다 — 빼면 테마(design.css)의 검정 단추 꾸밈(반짝 ::before · overflow hidden · 들림)이 붙는다. 보이는 모양은 app.css 가 덮는다(qf12_shell_browser 의 summary 클래스 문자열 단언도 그대로 성립).

## C4 — 편집 중 그림 「검사 중」 · 남긴 댓글의 멘션 표시 · 2026-10-14 · Opus 구현 에이전트 (DESIGN 2.26 「검사 중 미리보기」 · 2.27 「남긴 댓글의 멘션」 · 지시서 C4)

### 변경 파일
- 서버: 새 `src/community/handlers/attachments_list.php`(`GET /api/pages/{id}/attachments` — `community_page_row` → `decideAttachmentRead` → `{attachment_titles}` 한 칸 · 지도는 pages_get 과 같은 함수 `community_attachment_titles` · 거부는 전부 404 · 감사 0 · 서버 로그 한 줄) · `src/community/routes.php`(라우트 한 줄 `community.read` + 주석 한 줄) · `src/community/handlers/comments_list.php`(`mention_status` — 아래).
- 화면: `public/assets/community/editor.js`(① `readPreview` — 올린 그림 파일 → FileReader `data:image/(png|jpeg|gif);base64,` 사본 · 20MB 까지 · 이 탭 메모리 `attachmentPreviews` 에만 ② `createUploads` 가 사본을 첨부 id 로 기억하고 `cfg.onUploaded` 로 다시 묻기를 켬(붙여넣기 · 끌어놓기 · 「이 문서에 올리기」 세 입구 모두 같은 함수) ③ `imageViews` 가 렌더러에 `attachment_previews` 를 넘김(편집기에서만) · 살아 있는 그림 부품 목록 `liveImageViews` + `redrawAttachmentViews` ④ `watchAttachments` — pending 이 있으면 5초 · 최대 3분 · 창이 숨으면 멈춤 · 다시 보이면 이어서 · 저장 200 뒤 곧바로 한 번 · 401/403/404 면 멈춤 ⑤ `mentionInto` — 댓글 글자 조각의 `@아이디` 를 `mention-input.js` 의 `mentionTokens`(= 서버 `community_mentions_in`) 로 끊어 `.mention-mark--ok/--miss` + title · aria-label · 지도에 없는 토큰은 글자 그대로 ⑥ 페이지 화면 — `pageWatch`(첫 읽기 · 올리기 · 저장 뒤) · `linkifyInto` 의 글자 조각이 `mentionInto` 를 지남 ⑦ `mountPaneEditor` 에 `opts.onUploaded` · work 에 deps 셋) · `public/assets/community/work.js`(칸 — 같은 `watchAttachments`(칸을 다시 그리거나 닫으면 멈춤 · 끝나면 편집 중이면 그 그림만, 읽기면 설명 다시 · 「파일 첨부」 줄 배지도 `drawAttRow` 로 다시) · 칸 댓글이 같은 `mentionInto`) · `public/assets/community/doc-render.js`(사본 지도 인자 하나 `ctx.attachment_previews` — 검사 중 그림에 사본이 있으면 같은 `figure.doc-image`(폭 클래스 그대로) + `data-preview` + `.badge` 「검사 중 · 나만 보여요」 · 감염은 「올릴 수 없는 파일이에요」 카드) · `public/assets/community/community.css`(남긴 댓글 표시 두 줄 · 배지 왼쪽 위 한 줄 — 색값 0) · 부품 버전 줄(`php scripts/community_asset_version.php` — dashboard · database · editor · suggestions · table-editor · templates · work · ../messenger.js).
- 시험: 새 `tests/community/cafe_c4_browser.py` · `memories_harness.py`(choices) · `tests/catalog.json`(community.cafe_c4_browser).
- `app.css` 는 C4 에서 손대지 않았다.

### `mention_status` 판정 (comments_list.php)
- 이 목록에 **보이는 본문**에 나온 아이디만(`community_mentions_in` · 댓글당 10 · 목록 전체 판정 상한 20명 — 멘션 후보 창구 D-7 과 같은 「앞에서부터 · 한 명씩」). 계정은 이름 목록으로 질의 **한 번**(`ANY(string_to_array(:names))` — 아이디 글자 `[a-z0-9_.-]` 뿐 · 바인딩) → 활성 · 쓰기 자격(`Authz::canCommunityWrite`)인 사람만 그 사람 몫 문서 행(`community_page_row_nodoc`) + `decideMentionTarget`(댓글 알림과 같은 줄기). 없는 아이디 · 못 보는 사람 · 특권으로만 보는 관리자 · 손님은 모두 `{ok:false}`(이름 0).
- 보는 사람이 쓰기 자격이 없으면(손님) `{}` · 익명게시판 글은 `{}`.

### 수락 시험
- 개발 실행: `c4-dev1`(http 85/0) · `c4-dev2`(image · mention 79/16 — 시험 쪽 셋: 넷째 그림이 문서 끝이 아닌 자리에 들어가 「마지막 줄 = 상자」 가정이 틀림 · 412 에서 끌어놓기 자리가 달라 첨부 순서 가정이 틀림 · 대비 계산이 `color(srgb …)`(color-mix 계산값)를 읽지 못해 1.00 — 시험만 고침) · `c4-dev3`(image · mention 95/0).
- 최종: `python3 tests/community/memories_harness.py --suite cafe_c4_browser.py --output /home/changkwon/qaredi-cafe-results-20261014/c4-run1` → **pass 174 · fail 0**(rc 0) — `c4-run1/cafe-c4-browser-results.json` · `cafe-c4-browser-metrics.json` · `stdout.txt` · `shots/`(셋).
  - [http] 주인 · 보기 공유 200(본문 키 `attachment_titles` 하나 · pages_get 지도와 같음 · 다른 문서 첨부 0) · 다른 팀 · 손님 · 관리자(특권만) · 없는 문서 404 · 세션 없음 401/403 · pending raw/download 423 그대로 · clean · infected 반영 / mention_status: 공유받은 A · 주인 W ok+이름 · 못 보는 E · 없는 아이디 · `@A.te`(한 토큰) · 관리자 · 손님 `{ok:false}` 만 · `@ab` · `x@y` 0 · 다른 읽는 사람 같은 지도 · 손님 시점 `{}` · 익명게시판 `{}` · **질의 수 댓글 3 → 6 · 30 → 6**(리플렉션 PDO 문장 수).
  - [image] 1440 · 412: 붙여넣기 · 끌어놓기 · 「이 문서에 올리기」 → 201 pending · 셋 다 즉시 `img data:image/png` 그려짐 · 배지 「검사 중 · 나만 보여요」 왼쪽 위(8,8) · 상자 0 · 손잡이 → p40 · raw/download 요청 0 → DB clean → 서버 raw 그림(1440 1.6초 · 412 1.8초 — 5초 묻기 한 번 안) · 배지 0 · p40 그대로 · 저장 블록 att: 셋(width_pct 40) · 다시 열기 그림 셋 · 새로 고침 뒤 pending 하나 → 「검사 중이에요」 상자 · 보기 공유 A 도 상자(data: 0) → clean → 둘 다 그림(올린 사람 5.3 · 5.1초) · 넘침 0 · 콘솔 0 / 작업 칸 편집기: 붙여넣기 → 사본 + 배지 → clean → 서버 그림(1.9초).
  - [mention] 1440 · 412 × 밝게 · 어둡게 · 고대비: 전체 화면 `@A` ok(--accent-dark · 500 · aria-label 「@A — A — 알림이 갔어요」) · `@E` · `@A.te` · `@nobody` miss(물결 · 「찾을 수 없는 사람 — 알림이 가지 않았어요」) · 대비 최소 밝게 5.55 · 어둡게 5.05 · 고대비 7.52 · 넘침 0 · 작업 칸 댓글 같은 표시(밝게).
- 정적: security_lint 통과 0건 · php -l 0 · node --check 0 · asset_version --check 0 · wiki_check 0 · git diff --check 0 — `c4-static.txt`.

### 못 한 것 · 한계
- 사본은 이 탭 메모리뿐 — 다른 탭 · 새로 고침에는 없다(설계대로 「검사 중이에요」 상자 → 끝나면 바뀜). 20MB 넘는 그림은 사본 없이 상자.
- 자기 자신을 부른 `@내아이디` 는 ok(파랑)로 보인다 — 판정은 대상 기준(알림 창구는 자기 자신에게 알림을 만들지 않지만 지도는 보는 사람마다 같은 값).
- 목록 판정 상한 20명 밖 아이디 · 3자 미만 토큰은 표시 없이 글자 그대로.
- 「파일 첨부」 칸 줄은 배지만 다시 그린다(칸을 다시 열지 않음).

### DESIGN 과 다르게 한 것
- 못 찾음 글자색: `--danger` 가 밝은 기본 테마에서 `--danger-soft` 위 **4.41:1**(4.5 미달)이라 `color-mix(in srgb, var(--danger) 82%, var(--fg))`(토큰 섞기 · 새 색값 0) — 물결 밑줄은 `--danger` 그대로.
- 감염 첨부를 「올릴 수 없는 파일이에요」 카드로 그리는 갈래는 공용 렌더러에 두어 읽기 화면에도 같다(전에는 받기 카드 → 403).
- `mention_status` 의 판정 질의는 「댓글 수와 무관 · 판정 대상 수(상한 20)에 비례」 — 지시서의 「질의 고정」을 댓글 3 · 30 같음으로 확인했다.

## C5 — 접힌 사이드바 「＋」 풍선 · 기본 접힘 · 2026-10-14 · Opus 구현 에이전트 (DESIGN 2.19 ⑨ 「2026-10-14 사용자 지적 보정」 · 지시서 C5)

### 원인 (「＋」 안에 잘린 글자)
- 접힌 「＋」(`#nav-new-menu > summary.btn` — 검정 단추)에 테마 `design.css` 의 「들림」(`:hover { transform: translateY(-2px) }`) · `overflow:hidden` 이 걸린다. transform 이 생긴 순간 그 단추가 고정 위치(fixed) 조각의 기준 상자가 되어, 화면 기준으로 오른쪽에 띄우던 풍선(`::after` 「새로 만들기」 — 2.8 규칙)이 40px 단추 **안**에 갇혀 잘린 글자(「로 만들」)로 보였다. 다른 줄(홈 · 내 작업 …)은 `.btn` 이 아니라 들림이 없다.

### 변경 파일
- `src/views/shell_top.php` — `$shellSidebarOpen = $_COOKIE['qaredi_sidebar'] === 'open'`(비교만 · 값 출력 0) → 아니면 `<body … data-sidebar="collapsed">` · ☰ `aria-expanded` / `aria-label` 첫 값(접힘 = false · 「메뉴 펼치기」). 이 셸 한 벌을 업무 · 이야기 · 자료실 · 카페 셸이 모두 쓴다.
- `public/assets/app.js` — 첫 그리기 즉시 함수(localStorage 를 읽어 임시 속성을 다는 갈래) 지움 · `initSidebarCollapse()` = 옛 키 `qaredi-sidebar-collapsed:v1:*` 한 번 지움(읽지 않음) · 첫 값은 서버가 그린 속성 · ☰ 누름 → 펼치면 `qaredi_sidebar=open; Path=/; Max-Age=31536000; SameSite=Lax`(+ https 면 Secure) · 접으면 같은 이름 `Max-Age=0`(지움).
- `public/assets/app.css` — 접힌 레일의 글자 단추(「＋」 · 이야기 「＋ 새 이야기」) `:hover { transform: none }` 한 줄(id 선택자라 테마 규칙보다 무겁다) — 들림만 끄고 칠 · 반짝임 그대로.
- 이 셸 말고 body 를 따로 그리는 화면(grep `<body`): `space_page.php`(「우리 공간」 — 사이드바 없음) · `launcher.php` · `member_top.php` · `login_page.php` · `legal_page.php` · `not_found_page.php` · 공개 글(`pub_view` · `pub_form` · `page_print` · `DocExport`) · `memories_page.php` 의 손님 공개 판(`threads-public`) · routes.php 의 빈 셸 조각 — 모두 접는 사이드바가 없어 바꿀 것 0. `page_view.php` 는 셸이 낸 `<body data-service=` 앞에 속성을 끼우는 방식이라 그대로 맞물린다.
- 시험: `tests/community/cafe_c4_browser.py` 에 [c5] · `tests/catalog.json`(covers · notes).

### 옛 시험 — 접힘 기본이 깨뜨리는 자리 (돌리지 않음 · 바꾼 것만)
- 시작 때 펼침 쿠키 `qaredi_sidebar=open` 한 줄(쿠키 만드는 함수 · 목록에): `tests/community/cafe_c2_browser.py`(context) · `qf12_shell_browser.py`(cookie) · `qf12_work_browser.py`(context — `sidebar_open` 인자 · 「역할로 보기」 문맥) · `qf12_u4_browser.py` · `messenger_window_browser.py` · `browser_smoke.py`(두 문맥) · `browser_5c.py` · `browser_7d1.py` · `browser_5a2.py` · `tests/browser/qf3a_favorites.py` · `qf3a_bulk.py` · `qf4_restore_owner.py` · `ux_r1_ui.py` · `dbg_u_ui.py` · `dbg_u2_ui.py` · `dbg_u3_ui.py` · `passkey.py` · `tests/qf13_consent_http.py` · `tests/wiki_books_browser.py` · `tests/design_live_browser.py` · `tests/stage26.sh`(세 자리). 고른 기준: 접힌 레일에서 숨는 사이드바 부품(즐겨찾기 · 최근 · 트리 · 게시판 · 팀 · 휴지통 · 관리 묶음 · 사용자 칩 · 약관 줄 · 브랜드 글자)을 ≥960 에서 읽거나 누르는 시험. `#nav-search` · 받은 알림 · 내 작업 · 「＋」 메뉴 · 폰 서랍만 쓰는 시험(browser_5d · browser_9d · browser_9_automation_ui · qf2_search_modal · perf/qf2_search_perf · design_themes_browser)은 접혀도 보이는 자리라 그대로 뒀다.
- 단언 자체를 새 규칙으로 바꾼 것: `qf12_shell_browser.py` 「C-2 … sidebar head ☰ #sidebar-collapse」(쿠키 없는 원문 HTML → `aria-expanded="false" aria-label="메뉴 펼치기"`) · `qf12_work_browser.py` [collapse] 「U2 collapse kept after reload」(localStorage 키 → 쿠키 지워짐 · 옛 키 0) · 「U2 959 …」(옛 키를 심던 줄 → 쿠키 없는 문맥) · 「U2 first paint …」(옛 키 → 쿠키 없는 문맥 — 서버가 그려 0프레임) · [shots] 접힌 판(옛 키 → 쿠키 없는 문맥).

### 수락 시험
- `CAFE_C4_ONLY=c5 python3 tests/community/memories_harness.py --suite cafe_c4_browser.py --output /home/changkwon/qaredi-cafe-results-20261014/c5-run1` → **pass 124 · fail 0**(rc 0 · 이 판 그대로 — 그 뒤 화면 코드 변경 0) — `c5-run1/cafe-c4-browser-results.json` · `cafe-c4-browser-metrics.json` · `shots/c5-plus-hover-1440.png` · `c5-collapsed-1440.png`.
  - 원문 HTML(requests · 세 셸): 쿠키 없음 → `data-sidebar="collapsed"` · ☰ false/「메뉴 펼치기」 · `open` → 속성 0 · true · 다른 값(`<b>open`) → 접힘 · 값이 HTML 에 나오지 않음.
  - 업무 · 이야기 · 자료실 × 1440 · 1280 · 960: 첫 프레임부터 collapsed(펼친 프레임 0) · 레일 64 · 보이는 글자 0 · 옛 키(값 0 을 심어 둠)는 펼치지 않고 지워짐 · 넘침 0 / ☰ → 240 · 쿠키 open → 다음 화면 첫 HTML 속성 0 · 다음 화면 · 새로 고침 240 · 다시 ☰ → 쿠키 지움 · 새로 고침 64 · 콘솔 0.
  - 접힌 「＋」(업무 × 세 폭): 올리기 — 40×40 · 글자 1px 가림 · transform none · 풍선 `::after`(CDP 상자) left 67.5 = 단추 오른쪽 51.5 + 16 = 「홈」 풍선과 같은 간격 · 세로 가운데 · 폭 80 / 키보드 초점 — 같은 풍선 · 글자 0 / 누름 — 메뉴가 오른쪽(left ≥ 64) · 「문서」 / 펼침 240 — 「새로 만들기」 글자 보임.
  - 412(터치) 쿠키 없음 · open 둘 다: 서랍 폭 > 200 · 「홈」 글자 · ☰(접기) 숨김 · 넘침 0.
- 정적: security_lint 통과 0건 · php -l 0 · node --check 0 · 바꾼 시험 py_compile · bash -n 0 · asset_version --check 0 · wiki_check 0 · git diff --check 0 — `c5-static.txt`.

### 못 한 것 · 한계 · DESIGN 과 다르게 한 것
- 쿠키는 브라우저 하나에 하나다(전의 사용자별 localStorage 와 달리 같은 브라우저의 다른 계정도 같은 값) — DESIGN 2.19 ⑨ 의 결정 그대로.
- 앵커 위치 지정을 모르는 브라우저는 풍선이 옛 absolute 규칙이라 단추의 `overflow:hidden` 에 잘릴 수 있다(2.8 「받아들인 한계」 그대로 — 사내 기본 Chromium 은 해당 없음).
- 「들림」을 끈 것은 접힌 레일의 글자 단추뿐 — 펼친 240 의 「＋ 새로 만들기」는 테마 모양 그대로.
