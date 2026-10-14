# 보고 — Q:F-12 W1 달력의 작업 기간 (2026-10-14 · Opus 구현)

지시서 `docs/qf12-w1-directive.md` · 기획 `community/work-ux-plan.md` 19장 · DESIGN 2.34 · 결정 WK-29.
격리 worktree `/home/changkwon/qaredi-w1-20261014`(브랜치 `feat/qf12-w1`) · 결과 `/home/changkwon/qaredi-w1-results-20261014/`. 운영 · 운영 DB · 다른 worktree 0 · push · 발행 · 합치기 0.

## W1 — 시작 예정 · 실제 시작 · 막대

### 변경 파일
| 갈래 | 파일 | 무엇 |
|---|---|---|
| DB | `db/migrations/2026-10-14_task_start_date.sql`(새) | `community.pages.start_date DATE` · 속성 사건 prop CHECK 에 `'start'`(누적 — 지금 정의를 읽어 `OR prop='start'`, 있으면 아무것도 안 함) · 되돌리기 주석 |
| DB | `db/community/schema.sql` | 끝에 「Q:F-12 W1」 절(마이그레이션과 같은 문장) |
| 서버 | `src/community/routes.php` | `community_page_row_sql` 에 `p.start_date` 읽기 열 · 새 `community_task_dates(public_id 목록)` — 시작 예정 · 실제 시작(첫 `doing` 사건 날, Asia/Seoul) · 끝난 날(지금 done 일 때 마지막 `done` 날)을 **한 질의**(GROUP BY · 바인딩 하나 · `page_property_events_page_at` 색인) |
| 서버 | `src/community/handlers/pages_properties.php` | 허용 키 `start` · 날짜/null 형식 400 · 판정 = due 와 같은 `decidePageChange` 갈래 · 판정 뒤 start ≤ due(바꾸는 쪽이 요청에 있을 때) 아니면 400 `{"error":"시작 예정일이 기한보다 늦어요","reason":"start_after_due"}` · UPDATE `start_date` · 사건 `start` + 감사 `page.property` |
| 서버 | `src/community/handlers/pages_get.php` | 응답 최상위 `start` · `started` · `finished`(`due` 옆, 같은 함수) |
| 서버 | `src/community/handlers/my_tasks.php` · `database_rows.php` | 줄마다 같은 세 키 — 위 함수 한 번 호출(**목록 밖 파일 — 아래 「다르게 한 것」 ①**) |
| 화면 | `public/assets/community/calendar.js` | 달 · 주 격자를 주 줄(`.cal-week` 7칸) 렌더러 한 벌로: 예정 · 실제 · 기한 넘김 · 기한 하루 막대, 층(예정 위 · 실제 아래, 4층 + 「+N」), 주 줄 넘김(`--more` · `--cont`), 기한 깃발, `aria-label` 문장, `options.open`(누름 → 오른쪽 칸), `drawLegend()`. 위치 · 폭은 `cal-col-N` · `cal-span-N` · `cal-lane-N` 클래스. 타임라인 갈래는 그대로 |
| 화면 | `public/assets/community/work.js` | 작업 칸 「마감」 앞 「시작 예정」(날짜 칸 · 읽기 줄 「실제 시작 … — 진행 중으로 바꾼 날」/「아직 시작 전」 · 「끝난 날 …」 · 칸 아래 `.msg`) · 활동 문장 `start` · 내 작업 달력에 막대 자료 · 범례 · 누름 → 칸 · 프로젝트 고리 `openTask` |
| 화면 | `public/assets/community/database.js` | 기한 달력이면 범례 · 프로젝트 화면이면 막대 누름 = 오른쪽 칸 · 기한 달력은 앞뒤 45일의 기한까지 받음(달 밖 기한의 막대가 이 달에 들어오게) |
| 화면 | `community.css` · `app.css` | 2.34 클래스(`.cal-week` · `.cal-bar` · `--plan` · `--actual` · `--late` · `--cont` · `--more` · `.cal-due` · `.cal-more` · `.cal-legend` …) · 작업 칸 읽기 줄 배치 3줄. 새 색값 0(토큰만). 폰 한 줄 목록 갈래는 걷음(7칸 유지 · 글자 0) |
| 화면 | 커뮤니티 JS import 버전 줄 | `php scripts/community_asset_version.php` 로 다시 찍음(dashboard · editor · suggestions · table-editor · templates · messenger 는 버전 줄만) |
| 시험 | `tests/community/qf12_w1_http.py` · `qf12_w1_browser.py`(새) · `memories_harness.py`(마이그레이션 끝 · 선택지 둘) · `tests/catalog.json`(두 항목) | |
| 문서 | `wiki/community/guide-work.json` | 「내 작업」 절에 달력 · 시작 예정 한 단락 |

### 수락 시험과 결과
- HTTP `python3 tests/community/memories_harness.py --suite qf12_w1_http.py --output …/http` → **RESULT pass=76 fail=0**
  (저장 200 · 날짜 아님 400 ×5 · start > due 400 문구 · due 를 앞으로 400 · 같은 값 사건 0 · null 지움 · 사건 'start' 1행 · 감사 · 보기 공유 403 + deny ×2 · 못 보는 사람 403 ·
  todo → doing → review → doing → done: started 첫 doing(UTC 16:30 → 서울 다음 날) · finished 마지막 done · done → doing = null · 바로 done = started null ·
  my-tasks · 프로젝트 행 · pages_get 같음 · 질의 수 my-tasks 6 = 6, 행 목록 7 = 7(작업 3 · 30) · 못 보는 사람 목록 0(담당을 DB 에 박아도) · 마이그레이션 두 번 + 세 번째 0 오류 · CHECK 'start' 한 번)
- 브라우저 `… --port 8778 --suite qf12_w1_browser.py` 전체 → pass=168 fail=2 — 둘은 시험 쪽 기대값(프로젝트 달력은 편집 가능이라 `aria-label` 끝에 「· 좌우 화살표로 하루 이동」이 붙는다)을 고친 뒤 `QF12W1_ONLY=project` 다시 → **pass=48 fail=0**.
  1440 · 412 × 밝게 · 어둡게 · 고대비: 예정 점선 · 실제 진한 칠(--accent) · 기한 넘김 --danger-soft · 깃발 12×12 · 주 줄 넘김 · 예정 위 실제 아래 · 6개 → 층 4 + 「+2」 · 범례 셋 · aria-label 문장 · 기한만 = 하루 ·
  412 글자 0 · 넘침 0 · 막대 글자 대비 최소 7.88(어둡게)/16.67(고대비) ≥ 4.5 · 인라인 style 0 · 콘솔 0 · 막대 누름 → 오른쪽 칸 · 작업 칸 저장 → 새로고침 뒤에도 · 실제 시작 줄 · 끝난 날 줄 · 시작 예정 > 마감 알림 + 되돌림 · 활동 문장.
  스크린샷 `browser/shots/calendar-my-tasks-1440-light.png` · `-1440-dark.png` · `-412-light.png` · `calendar-project-1440-light.png` · `pane-start-1440-light.png`.
- 정적: `scripts/security_lint.sh` 금지 패턴 0 · `php -l`(바뀐 PHP 전부) · `node --check`(바뀐 JS 전부) · `php scripts/community_asset_version.php --check` rc 0 · `php scripts/wiki_check.php` 통과 · `git diff --check` 0.

### 고친 옛 단언(돌리지 않음 — 3.1)
- `tests/community/qf12_server_http.py` Q:T-29 「item keys exactly」 — 열 키에 `start` · `started` · `finished` 더함.
- `tests/community/stage11_browser.py` :74 「mobile calendar uses single column list」 → 「폰도 7칸 주 줄」(DESIGN 2.34 「폰」 — 막대가 주를 이어 그린다).

### 못 한 것 · 남은 것
- 데이터베이스 행 값 창구(`page_values.php` — 달력에서 기한을 끌어 옮기기)는 start ≤ due 를 보지 않는다(목록 밖). 끌어서 기한을 시작 예정 앞으로 옮길 수 있고, 그때 달력은 예정 막대를 시작 예정 하루로만 그린다.
- 프로젝트 달력의 행 목록은 서버가 **기한** 범위로 거른다(`DbViews::dateRange` — 목록 밖). 화면이 앞뒤 45일로 넓혀 받지만, 기한이 그보다 먼 작업 · 기한 없이 시작 예정/실제만 있는 작업은 프로젝트 달력에 없다(내 작업 달력에는 있다).
- 「+N」은 글자 표시만(누름 0) — 숨은 작업은 목록 보기에서 본다.
- 폰 폭 막대는 높이 20(DESIGN 2.34) — 44px 터치 대상 규칙(DESIGN 4장)은 층이 붙어 있어 넓히지 않았다.
- 첫 HTTP 실행 때 포트 확인과 틀 시작 사이에 다른 에이전트(H2)의 서버가 8768 에 먼저 떠서, 내 요청 몇 개가 그 서버로 가 401 로 끝났다(쓰기 0 · 그 프로세스는 건드리지 않음). 그 뒤로는 8778 만 썼다.

### 기획 · DESIGN 과 다르게 한 것
1. **허용 목록 밖 두 파일** `my_tasks.php` · `database_rows.php` — 지시서는 「routes.php 작업 목록 helper」로 적었지만 목록 응답은 핸들러가 만든다. 각 파일에 helper 한 번 호출 + 키 세 줄만 넣었다(질의 · 판정 그대로).
2. **프로젝트 색 tint 생략** — 막대는 --accent 계열 토큰만. 프로젝트 색(`.moa-*`)은 테마와 상관없는 고정 색이라 어두운 화면에서 그 위 글자 대비 4.5 를 지킬 토큰이 없다. 실제 막대 글자는 `--primary-fg`(밝게 흰 글자 · 어둡게 진한 글자 — 대비 측정 통과).
3. 기한 깃발은 아이콘 템플릿(`icon_templates.php` — 목록 밖)에 깃발이 없어 CSS 도형(깃대 + 삼각, 12×12 · currentColor)으로 그렸다.
4. `aria-label` 은 DESIGN 예 문장 그대로에 상황에 따라 「· 기한 넘김 n일」(넘겼을 때) · 「· 기한 …」(시작 예정 없이 실제만 있을 때) · 「기한 M월 D일」(기한만)을 붙인다.
5. 날짜 칸 머리는 「10-14」 대신 날 수만(「14」) — 폰 7칸에서 잘리지 않게. 달은 도구 줄에 있다.

## W1b — 「더보기」 메뉴가 창 밖으로 잘림 (DESIGN 2.15 「창 안에 머묾」)

### 원인
작업 칸 · 프로젝트 머리의 메뉴 단추는 아이콘 단추(`summary.btn btn--icon[aria-label]`)다. 공용 메뉴 규칙(app.css 2.15 — `.menu > summary { anchor-name: --menu }`)
**뒤에** 있는 툴팁 규칙(2.8 — `.btn--icon[aria-label] { anchor-name: --tip; anchor-scope: --tip }`)이 같은 summary 의 `anchor-name` 을 `--tip` 하나로 덮어써,
목록(`position: fixed; position-anchor: --menu`)이 앵커를 잃었다 → `position-area` 가 무시되고 fixed 목록이 제자리(단추 왼끝)에서 오른쪽으로 펼쳐져 창 밖으로 나갔다.
칸(aside)의 transform · contain 은 없었다(넘침 `overflow-y: auto` 는 fixed 라 상관없음). 같은 summary 의 풍선(`::after`)도 열린 동안 hover 로 떠 목록과 겹쳤다.

### 변경 파일
- `public/assets/app.css` — 앵커 블록 하나 + 두 줄: `.menu > summary.btn--icon[aria-label] { anchor-name: --menu, --tip }`(접힌 사이드바 「＋」 와 같은 방식) ·
  `.menu__list { max-height: calc(100% - 14px); overflow-y: auto; position-try-order: most-height }`(항목 16줄인 화면 머리 「더보기」가 폰 412 에서 아래로 2px 넘던 것 — 자리가 큰 쪽에 펴고 그보다 길면 목록 안 스크롤) ·
  `.menu[open] > summary:is([aria-label], [data-tip])::after { display: none }` + 접힌 사이드바 갈래 한 줄. JS · 마크업 변경 0(work.js 메뉴 마크업은 2.15 그대로).
- `tests/community/qf12_w1_browser.py` — `[menu]` 묶음 · `tests/catalog.json` 설명.

### 수락 시험과 결과
- 고치기 전 판(HEAD 의 app.css 로 같은 시험) `QF12W1_ONLY=menu` → **fail 32** — 예: 「pane-1440: 목록 l 1352 · r 1544 > 1432」(사용자 스크린샷 그대로 재현) · 풍선 `::after` block.
- 고친 판 `QF12W1_ONLY=menu` → **pass=130 fail=0**: 1440 · 1280 · 960 · 412 × 작업 칸 · 넓게 보기(1200 이상) · 전체 화면 작업 · 프로젝트 머리 · 화면 머리 —
  목록 상자 왼 ≥ 8 · 오른 ≤ 폭-8 · 아래 ≤ 높이-8(화면 머리 412 는 아래 907 = 915-8, 목록 안 스크롤) · 항목 글자 잘림 0 · 열린 동안 풍선 0 · Esc 닫힘 · 밖 누름 닫힘 · 콘솔 0.
  스크린샷 `browser-w1b/shots/w1b-pane-menu-1440.png`.
- 정적: security_lint 0 · asset_version --check rc 0 · git diff --check 0.

### 고친 옛 단언 · 못 한 것 · 다르게 한 것
- 고친 옛 단언 0. 못 한 것 0.
- 다르게 한 것: 지시서의 「폴백」에 더해 `max-height` + `position-try-order: most-height` 를 공용 `.menu__list`(앵커 갈래)에 넣었다 — 항목이 많은 메뉴는 폰에서 위 · 아래 어느 쪽에도 다 들어가지 않아 그 둘 없이는 창 안을 지킬 수 없었다. 멘션 후보 목록(`.mention-field > .menu__list` — 자기 max-height 20rem)은 그 값이 이긴다.

## W1c — 글쓰기 한 기준: 작업 전체 화면 모양 (DESIGN 2.35)

### 원인
문서 · 게시판 글의 「가운데 떠 있는 좁은 상자」는 `public/assets/design.css` 의 `body[data-page="community-page"] :is(#page-doc,#page-editor) { max-width:88ch; margin-inline:auto; padding:32px 40px }`
(+ 960 · 600 미만 두 줄) 과, 편집 칸 기본 용지 A4(`#page-editor` 최소 794 × 1123 · 가운데) 때문이었다. 읽기 본문 · 댓글은 `.panel` 카드였다.

### 변경 파일
- `src/community/handlers/page_view.php` — `#page-doc` 의 `.panel` → `.page-doc`(상자 0) · 본문 머리 줄 `h2#page-body-head.task-pane__section-head`(편집 중에만 · 「본문」 + 오른쪽 `#page-done` 「편집 마침」 — 제목 줄 동작에서 옮김, id 그대로) · `#page-comments-wrap` 의 `.panel` → `.page-comments`. 판정 0.
- `public/assets/community/editor.js` — 빈 문서 읽기 줄(`drawEmptyRead` — 고칠 수 있으면 단추 「누르면 내용을 적을 수 있어요」 → `startEditing`(「편집」과 같은 함수) · 못 고치면 「아직 내용이 없어요」 · 데이터베이스 페이지 0) · 편집 시작/마침 때 본문 머리 줄 보이기/숨기기 · 용지 기본 = 없음(「기본」 — 열 폭 편집 칸, A4 · A3 · Letter 를 고른 사람만 종이 모드 · 옛 값 'wide' 는 기본으로).
- `public/assets/community/community.css` — W1c 절: 한 열(`body[data-page-kind="doc"]` 이고 데이터베이스 표가 열리지 않은 화면의 `.main` 부품 = 최대 960 · 가운데 — 작업 화면 길잡이도 같은 열) · 읽기 흐름 · 빈 문서 줄 · 댓글 흐름 · 종이 모드 규칙은 `[data-paper-mode="a4|a3|letter"]` 에만 · 빈 문서에서 편집 칸을 5rem 으로 낮추던 규칙 삭제(언제나 16rem) · 넓은 화면 멘션 줄 폭 상한 삭제(도구 줄이 열 폭이라 줄은 아래로).
- `public/assets/app.css` — 작업 칸 도구 줄 규칙(열 폭 · 색 · 배경 · 링크 · 멘션 줄 아래로)을 `#inline-toolbar` 에도(같은 규칙 한 줄).
- `public/assets/design.css` — 88ch 좁은 상자 규칙 셋 삭제(**목록 밖 파일 — 아래 다르게 한 것 ①**).
- 커뮤니티 JS import 버전 줄 · `tests/community/qf12_w1_browser.py` `[doc]` · `tests/catalog.json` 설명.

### 수락 시험과 결과
- `QF12W1_ONLY=doc` → **pass=202 fail=0**: 1440 · 1280 · 960 · 412 × 문서 · 업무 게시판 글 · 카페 글 · 익명 글 · 작업 전체 화면 — 열 x · 폭 다섯이 같음(1440 (272, 960) · 1280 (192, 960) …) · ≤ 960 · 본문 왼끝 = 제목 왼끝 ·
  읽기 본문 테두리 · 바탕 · 그림자 0 · 댓글 같은 열 · 카드 상자 0 · 길잡이 같은 열 · 빈 문서 안내(단추) 누름 → 편집: 도구 줄 상자 = 열 폭 · 편집 칸 = 열 폭 · 높이 ≥ 16rem · 테두리 · 용지 없음 ·
  「편집 마침」 본문 머리 줄 오른쪽 끝 · 제목 줄 「편집」 숨김 → 글 치고 마침 → DB 저장 · 읽기 흐름 · 「편집」 돌아옴 · 작업 전체 화면 편집 칸 · 도구 줄과 같은 폭 · 같은 왼끝 ·
  보기 공유 사람 「아직 내용이 없어요」(글자) · 편집 0 · A4 를 고른 사람 편집 칸 = 전과 같은 폭(메인 폭 · 높이 1123) · 데이터베이스 · 프로젝트 `#db-wrap` = 메인 폭(전과 같음) · 넘침 0 · 콘솔 0.
  스크린샷 `browser-w1c/shots/w1c-cafe-read-1440.png` · `w1c-doc-read-1440.png` · `w1c-doc-edit-1440.png`.
- 정적: security_lint 0 · php -l · node --check · asset_version --check rc 0 · wiki_check · git diff --check.

### 고친 옛 단언(돌리지 않음)
- `tests/browser/ux_r1_ui.py` Q:T-12 「머리 한 줄 — 상태 배지 · 더보기 · 편집 끝이 같은 줄」 → 「상태 배지 · 더보기 같은 줄 · 「편집 마침」은 본문 머리 줄(#page-body-head)」.
- 살펴보고 그대로 둔 것: `dbg_u_ui.py` H5(「편집 끝」 화면 안 — `#page-done` id 그대로 · 화면 안) · `table_density.py`(용지 a4 · a3 · letter 규칙 그대로, 'wide' 는 이제 규칙 0 = 열 폭) · `editor_toolbar_fit.py`(도구 줄 폭 · 넘침) · `#page-done` 을 누르는 옛 시험 전부(id 그대로).

### 못 한 것 · 남은 것
- **편집기 마운트는 한 함수로 합치지 않았다** — 문서 화면 편집기(`startEditing`)는 제안 모드 · 임시저장 · 함께 편집 신호 · 충돌 창 · 용지 · 블록 손잡이 · 빈 문서 안내를 들고 있고, 작업 칸 편집기(`mountPaneEditor`)는 그것들이 없다. 합치면 그 기능이 빠지거나 칸 편집기에 들어가야 해 이 묶음 범위를 넘는다. 대신 **같은 부품 · 같은 CSS**: 도구 줄은 원래 한 함수(`createToolbar`) · 편집 칸은 둘 다 `.panel` + `.doc.editor`(16rem) · 도구 줄 규칙 한 줄 · 본문 머리 줄은 같은 `.task-pane__section-head`. 메인 판단을 바란다.
- 댓글은 같은 열 · 같은 흐름 · 같은 멘션 입력 부품(2.27 — 원래 한 벌)이지만 목록 줄 모양은 문서 화면 것(`.list`) 그대로다 — 작업 칸의 `.task-comment` 줄로 바꾸면 답글 · 인용 · 고치기 · 신고 줄을 다시 짜야 한다.
- 편집 칸 안 여백은 두 화면 모두 `.panel` 그대로(24) — DESIGN 2.35 의 「24 32」와 다르다(작업 전체 화면을 기준으로 맞췄다).
- ProseMirror 가 편집기를 만들 때 한 번 내는 콘솔 경고(「CSS white-space … pre-wrap」 — 편집기 루트에 white-space 규칙이 없다)는 옛 판에도 같다. 시험은 그 한 문장만 세지 않는다 — 고치려면 `.editor { white-space: pre-wrap }`(공백 처리 바뀜 — 따로 결정).
- 데이터베이스 페이지(쓰임 없음)는 서버가 `data-page-kind="doc"` 을 달아, 첫 그림에서 표 칸이 열리기 전 한순간 열 폭이었다가 넓어진다(`:has(#db-wrap:not([hidden]))`).

### 기획 · DESIGN 과 다르게 한 것
1. `public/assets/design.css`(목록 밖) — 지시서의 「옛 #page-editor 가운데 좁은 규칙 삭제」 대상이 이 파일에 있었다. 그 세 줄만 지웠다(다른 디자인 규칙 0).
2. 용지 고르개 첫 값 「기본」(값 없음)을 더하고 「넓게」를 뺐다 — 기본이 곧 열 폭 편집 칸이라 「넓게」와 같은 일이다.
3. 위 「못 한 것」 첫째 · 셋째.

## W1d — 카페 댓글 「신고」를 글자 단추로 (DESIGN 2.29 「신고」)

### 변경 파일
- `public/assets/community/editor.js` `commentItem` — 댓글 줄 「…」(`details.menu` · 아이콘만) 메뉴를 걷고 `commentButton('신고', …)` 하나를 「답글」 · 「고치기」와 같은 동작 줄에.
  `data-comment-report` · `aria-haspopup="dialog"` · 같은 `cafe.openReport({pageId, commentId, onDone})` · 조건(카페 · 쓰기 자격 · 내 댓글 아님 · 숨긴 댓글 아님 · 본문 있음) 그대로 +
  **숨긴 글이면 0**(`state.board.hidden` — 글 머리 「신고」와 같은 조건. 옛 메뉴는 이 조건이 없어 숨긴 글을 볼 수 있는 사람(쓴 사람)에게 남의 댓글 신고가 떴다).
- 커뮤니티 JS import 버전 줄 · `tests/community/qf12_w1_browser.py` `[report]` · `tests/catalog.json` 설명.

### 수락 시험과 결과
- `QF12W1_ONLY=report` → **pass=74 fail=0**: 1440 · 412 × 자유 · 익명 글 — 남의 댓글 줄에 「신고」 글자 단추(메뉴 밖 · 같은 줄) · 412 누름 자리 52 × 46 ≥ 44 · 「…」 메뉴 0 ·
  누름 → 신고 모달 → 201(comment_id) · 「신고했어요 — 관리자가 확인해요」 · 내 댓글 · 숨긴 댓글 0 · 업무 게시판 글 0 · 숨긴 글(댓글 줄은 보임) 0 · 손님 0 · 콘솔 0.
  스크린샷 `browser-w1d/shots/w1d-anon-comment-report-1440.png`.

### 고친 옛 단언(돌리지 않음)
- `tests/community/cafe_c2_browser.py` 「comment report: 「…」 › 신고」 — 메뉴(`details.menu > summary`)를 먼저 열던 한 줄을 빼고 글자 단추를 바로 누른다(이름도 「신고」 text button).

### 못 한 것 · 다르게 한 것
- 손님 단언은 손님이 그 카페 글을 볼 수 없어(사내 게시판) 「댓글 줄 0 → 신고 0」으로만 확인됐다 — 손님이 볼 수 있는 카페 게시판이 합성 DB 에 없다.
- 「숨긴 글 0」 조건 한 줄을 더했다(지시서의 「조건 그대로」 + 완료 기준 「숨긴 글 0」을 함께 지키려면 필요했다).

## 최종 판 한 번 (W1 · W1b · W1c · W1d 모두 들어간 판)
- `python3 tests/community/memories_harness.py --port 8778 --suite qf12_w1_browser.py --output …/browser-final` → **RESULT pass=471 fail=0**(cal · pane · project · menu · doc · report 전부 · 콘솔 0).
- 서버 쪽은 W1 뒤로 바뀌지 않아 `qf12_w1_http` 76/76(W1 판) 그대로.
- 정적(최종 판): security_lint 금지 패턴 0 · php -l · node --check · `community_asset_version.php --check` rc 0 · wiki_check 통과 · git diff --check 0.
- 남긴 것 0: 합성 DB · 임시 폴더 · PHP 서버는 실행마다 틀이 지웠다(`OWNED_TEST_PROCESSES_STOPPED=1; SYNTHETIC_DB_DROPPED=1`). W1 의 첫 HTTP 실행 뒤로 8778 만 썼다.
