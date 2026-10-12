# 보고 — 지시서 U2 사용감 라운드: 사이드바 접기 · 앱 격자 · 업무함 (2026-10-12)

구현: Opus 에이전트 · worktree `/home/changkwon/qaredi-qf12u2-20261012`(브랜치 `feat/qf12-u2`) · 결과 폴더 `/home/changkwon/qaredi-qf12u2-results-20261012/U2/`.
서버 PHP 변경 **0**(화면 뷰 2 + 셸 뷰 1). push · 발행 · main 합치기 안 함. 커밋 해시는 이 파일이 든 커밋 — 마지막 답에 적는다.

## 1. 변경 파일

| 파일 | 내용 |
|---|---|
| `src/views/shell_top.php` | ① `nav.app-rail` 삭제 ② `.moa-shell-links` 삭제 ③ `details.appbar__apps`(문서에 하나)를 사이드바 브랜드 줄에서 `<main>` 첫 자식 `div.shell-tools` 로 **옮김** — summary = ⋮⋮⋮ `.btn--icon`(aria-label 「앱 — 앱 바꾸기와 계정」 · 툴팁 「앱」) + 안 읽은 점 `span[data-message-unread]` · 판 = 3열 타일(모아 홈 → `Apps::all()` → 메신저 → 내 계정 → 내 활동 → 관리자 페이지 → 검색) + 「로그아웃」 한 줄 · 오른쪽 `a.shell-tools__me` ④ 사이드바 머리 `button#sidebar-collapse` ☰ ⑤ 서랍의 「메뉴/앱·계정」 전환 단추 삭제 ⑥ 세 셸 같은 마크업 한 벌. 접힘에서 남는 항목에 고정 글자 `data-tip` |
| `src/community/handlers/work_home_page.php` | `.work-cols` 세 칸 → `section.task-list.task-inbox` 뼈대(`#work-home-rows` · `#work-home-empty`) + 「모두 보기」(`#work-home-all`) · **`aside#task-pane` 추가**(아래 6절) |
| `src/community/handlers/my_tasks_page.php` | `#my-tasks-list` → `.task-inbox` 뼈대(`ul#my-tasks-rows`) · `aside#task-pane` 그대로 |
| `public/assets/app.css` | 2.18 격자 · 2.19 ⑨ 접힘 · 2.20-A 업무함 블록 추가 · 옛 `.app-rail` 블록 두 곳과 `--rail-bg`/`--rail-fg`(밝게 · OS 다크 · 다크 세 자리) 삭제 — `--rail-w` 남김 · 서랍 apps 모드 규칙 삭제 · 2.8 툴팁 규칙의 선택자 목록에 접힌 사이드바 `[data-tip]` 를 더함(새 툴팁 규칙 0) · `.task-pane X` 자손 규칙을 `:is(.task-pane, .task-inbox__pane) X` 로 · 프로젝트 화면 · 1199 이하 칸 열림의 「main 다른 칸 숨김」 규칙에서 `.shell-tools` 제외 |
| `public/assets/app.js` | 맨 위 즉시 함수(첫 그리기) + `initSidebarCollapse`(☰ 토글 · `localStorage` 기억) · `initMobileSidebar` apps 모드 제거 · `#appbar-apps` 는 같은 details 를 연다(aria-expanded 동기화) · `initMenus` 가 격자도 닫는다(타일 누름 · 바깥 · Esc · Tab 밖) · 검색 타일 = `#nav-search.click()` |
| `public/assets/community/work.js` | 순수 함수 `buildInbox(myTasks, briefing, favorites, today)`(export) · `taskRow` 의 kind `'inbox'` 갈래(`inboxRow`) · `inboxView`(꼬리표 · 줄 · 줄 아래 펼침 · ↓↑ · Esc · 폭 전환) · `createPane` 첫 인자를 요소 **또는** `(id) => 요소` 로 넓힘(aside 가 기본 · 안쪽 draw 한 벌) · `initHome` · `initMyTasks` 를 업무함으로 |
| `public/assets/community/editor.js` | `createWork` deps 에 한 줄 — `favorites: { star: navStar, ids: () => favoriteIds, onChange: … favoriteListeners.push }` (그 밖 0 · import 버전 줄 제외) |
| 커뮤니티 JS import 버전 줄 | `dashboard.js` · `database.js` · `editor.js` · `suggestions.js` · `table-editor.js` · `templates.js` · `work.js` · `../messenger.js` — `php scripts/community_asset_version.php`(쓰기)가 바꾼 `?v=` 줄만(diff 확인: 그 밖 0) |
| `tests/community/qf12_shell_browser.py` | 아이콘 줄 단언(Q:T-38 · Q:T-40 · 대비) → 격자 단언 · 서랍 apps 모드 → 앱 이름 ▾ 격자 · C-2 메신저 단추 자리 |
| `tests/community/qf12_work_browser.py` | 홈 · 내 작업 뼈대에 맞춘 기존 단언 고침 + U2 묶음 `u2` · `collapse` · 보고용 사본 `shots` |
| `tests/catalog.json` | 두 시험 notes · covers(`app.js` · `messenger.js` · `search.js`) |
| `docs/qf12-u2-report.md` | 이 보고서(새 파일) |

`src/views/shell_bottom.php` · `public/assets/messenger.js`(버전 줄 외) 는 고칠 필요가 없어 0. 보안 핵심 파일 · `my_tasks.php` · `briefing.php` · `Briefing.php` 0.

## 2. 실행한 명령과 결과(원문은 결과 폴더)

| 명령 | 결과 | 폴더/로그 |
|---|---|---|
| `python3 tests/community/memories_harness.py --suite qf12_shell_browser.py --output …/U2/shell-full` | **pass 611 · fail 13** — 13건은 **기준 판(HEAD `8c36f8b` 복사본)에서도 같은 13건**(아래) | `shell-full.log` · `baseline/shell-head.log`(pass 552 · fail 13) |
| `… --suite qf12_work_browser.py --output …/U2/work-full`(전 묶음) | **pass 1375 · fail 1** — 1건 = 접힌 「＋」 메뉴가 사이드바 경계에서 4px 겹침(left 60 < 64) → 여백 8→20 고친 뒤 아래 재실행 | `work-full.log`(U2 단언 255 통과) |
| `QF12D_ONLY=collapse … --output …/U2/collapse-final` | **pass 49 · fail 0** | `collapse-final.log` |
| `QF12D_ONLY=shots … --output …/U2/shots-final` | **pass 40 · fail 0** · 사본 12장 | `shots-final/shots/` |
| `… --suite qf12_server_http.py` | **pass 204 · fail 0**(기준 판 204/0 과 같은 수) | `server.log` · `baseline/server-head.log` |
| `… --suite qf12_floor_http.py` | **pass 267 · fail 0**(작업 시작 직후 첫 실행 267/0 과 같은 수 — 서버만 치는 시험) | `floor.log` · `baseline/floor.log` |
| `python3 tests/stage89_cross_regression/run.py --output …/U2/stage89` | **PASS=196 FAIL=0** | `stage89.log` |
| `bash scripts/security_lint.sh` | 결과: 통과 (금지 패턴 0건) | — |
| `php -l` shell_top.php · work_home_page.php · my_tasks_page.php | No syntax errors detected ×3 | — |
| `node --check` app.js · work.js · editor.js · messenger.js | 0 | — |
| `php scripts/community_asset_version.php --check` | ✖ 0 줄 | — |
| `git diff --check` | 0 | — |
| `python3 tests/lib/contrast.py` | CONTRAST_FAIL=0 | — |

**기준 판에서도 실패하는 13건(U2 와 무관 — 고치지 않았다)**: Q:T-38 런처 타일 · 링크 · 머리 링크 5 · Q:T-39 허용 목록 밖 돌아갈 주소 → `/` 3 · 서랍 시험 360/412/820 의 「폭을 1280 으로 늘리면 『문서』 묶음이 펼쳐져 있다」 3(v2.61.0 묶음 접힘 기본 이후) · C-2 업무 사이드바 머리 순서(「문서 선택」이 끼었다) 1 · C-2 「더보기」 접힘 목록 1. 런처 · 사이드바 문서 도구 쪽 변경이 시험보다 먼저 들어간 것으로 보인다 — 메인 판단.

### 완료 기준 대조
- ① 격자: 세 앱 × 1440·1280·960 `nav.app-rail` 0 · `div.shell-tools` 1(main 첫 자식 · 오른쪽 끝 = main 안쪽 오른쪽 ±2) · details 하나 · 닫힘 기본 · 누르면 판 폭 300(±8) · 단추 오른쪽 아래 · 타일 순서(직원 8 · 관리자 9 「관리자 페이지」) · aria-current 하나 · Esc(초점 ⋮⋮⋮) · 바깥 누름 / 959·820·412·360 shell-tools display none · `#appbar-apps` → 같은 details 전체 폭(top 52) · aria-expanded · 서랍 ☰ 는 내비만 · 런처 · `/tbox/welcome` · `/tbox/messages` shell-tools 0 · 손님 · 역할로 보기 같은 목록 · 일곱 폭 넘침 0 · 콘솔 0 · 검색 타일 = 같은 검색 창 · 메신저 타일 = 떠 있는 창. **타일 글자 대비**(테마 여섯 × 밝게 · 어둡게 · 고대비 밝게 · 고대비 어둡게 × 세 화면, 로그아웃 줄 포함): 최소 **5.92**(지금 앱 타일) — 원문 `shell-full/qf12-shell-contrast.json`.
- ② 업무 시험: 기존 단언 전부(뼈대 맞춘 것 포함) 통과 + U2 — [접기] 1440 ☰ 보임 → `body[data-sidebar=collapsed]` · 폭 64 · 보이는 글자 0 · 보이는 항목 = ☰ · ＋ · 홈 · 내 작업 · 받은 알림 · 검색 · 배지 겹침 · 묶음 제목 · 문서 선택 · 테마 · 버전 · 약관 · 사용자 칩 · 브랜드 · Ctrl K 0 · main 왼쪽 176 줄어듦 · 툴팁 「홈」 · aria-expanded false · 새로고침 · `/community/my-tasks` 유지 · 자료실 · 이야기 폭 64 넘침 0 · 다시 → 240 · 959 ☰ display none · 서랍 그대로. [업무함] 합성 데이터(claude_employee02: 지난 기한 2 · 오늘 1 · 이번 주 3 · 나중에 3 · 기한 없음 1 · 검토 요청 1(직원에게 맡긴 내 페이지 — briefing 에만) · 멘션 1(지난 기한 작업과 같은 페이지)): **화면 줄 = JS buildInbox(지금 창구 답) = 시험의 Python 같은 규칙** · buildInbox 단위 사례 셋 · 꼬리표 글자·수 · 같은 페이지 한 줄 + 「@」 · ★ 작업이 묶음 맨 위 · 줄 아래 펼침에 2.21 요소(h2 · `dl.task-pane__props` · 설명 · 하위 작업 · 활동 · 댓글 칸 · 들여쓰기 68) · `?task=` · 다른 줄 → 앞 줄 false · 같은 줄 · ✕ · Esc · 뒤로 → 접힘 · 완료 원 → `.task-row--done` + 다음 줄 초점 → `work:changed` 뒤 빠짐 · ↓↑ · Tab 순서 완료 원 → ★ → 줄 · ★ 토글(PUT/DELETE favorite) 뒤 다시 그림 · 홈 앞 10줄 + 「모두 보기」 · 내 작업 전부 · 보드 · 달력 · 끝난 작업 · briefing 403(route 가로채기)에도 작업 줄 · 1199 aside + 「‹ 목록」 · 412 전체 화면 · 손님 · 역할로 보기(관리자 → 손님) 완료 원 0 · ★ 토글 0 · 작업 추가 0 · 폭 여섯 × 밝게 · 어둡게 · 고대비 × 홈 · 내 작업 × (목록 · 열림) 넘침 0 · 콘솔 0 · 누름 자리 < 44 0 · 인라인 style 0.
- ③ server 204/0 · floor 267/0(기준 판과 같은 수) ④ stage89 196/0 ⑤ 정적 검사 전부 0.
- 스크린샷(직접 열어 봄): `shots-final/shots/final-1440-{open,collapsed}-{light,dark}-home-expanded.png` · `final-1440-{light,dark}-grid.png` · `final-412-{light,dark}-{grid,my-tasks}.png` · `final-1199-{light,dark}-pane.png` — 사이드바 펼침 · 접힘, 홈 첫 줄 펼침, 격자 판(지금 앱 검정 타일), 폰 격자 전체 폭, 1199 「‹ 목록」 모두 의도대로. 그 밖 `work-full/shots/u2-*` · `shell-full/shots/grid-*`.

## 3. 못 한 것과 이유 · 미검증
- **⋮⋮⋮ 안 읽은 점의 실제 표시는 미검증** — 마크업(`span[data-message-unread]`)과 CSS(8px 점)만 넣었고, 안 읽은 메시지를 만드는 시험을 돌리지 않았다. messenger.js 는 버전 줄 외 0(지금 `[data-message-unread]` 반복이 새 자리에도 숫자를 넣는다 — 코드 확인뿐). 지시서의 「aria-label 『안 읽은 메시지 n』」은 **넣지 않았다**: 넣으려면 messenger.js 를 고쳐야 하고, summary 의 aria-label 이 접근 이름을 덮어 점의 글자는 어차피 읽히지 않는다. 수는 메신저 타일 배지(글자)로 읽힌다.
- 접힌 「＋」 메뉴 여백 수정(8→20) 뒤에는 `collapse` 묶음(49/0)과 `shots` 묶음만 다시 돌렸다 — 전 묶음 재실행은 하지 않았다(바뀐 줄은 접힌 상태 전용 CSS 두 값).
- 「보기만 하는 사람」 문맥은 손님과 「역할로 보기(손님)」으로 쟀다. 보기 공유받은 직원은 쓰기 자격(can_community_write)이 참이라 자기 작업에는 완료 원이 보이는 것이 규칙대로다.
- 앵커 위치 지정을 모르는 브라우저(옛 방식 absolute)의 접힌 툴팁 · 「＋」 메뉴는 사이드바 스크롤 칸에 잘릴 수 있다 — 2.8 의 받아들인 한계와 같다(Chromium 151 에서만 확인).

## 4. 확정한 값
- **접힘 localStorage 키**: `qaredi-sidebar-collapsed:v1:<username>` — 값 `'1'`(접힘) / `'0'`(펼침). 첫 그리기: app.js 평가 때 이 접두의 키 중 하나라도 `'1'` 이면 임시로 접고, `/api/me` 뒤 그 사람 키로 바로잡는다.
- **첫 그리기 깜빡임**: **있음 — 1프레임**(rAF 기록: `['open','collapsed',…]`). 머리 스크립트를 쓸 수 없어(CSP) 본문 끝 app.js 가 돌기 전 한 프레임은 펼친 사이드바가 그려질 수 있다. 시험은 「≤ 2프레임 · 그 뒤 펼침 0」으로 재고 값(1)을 남긴다. 줄이려면 사이드바 앞에 작은 외부 스크립트를 따로 싣는 수가 있으나 새 파일이라 하지 않았다.
- **★ 창구 함수**: editor.js `navStar(page)`(사이드바 즐겨찾기 ☆ 단추를 만드는 함수 — `PUT`/`DELETE /api/pages/{id}/favorite` 뒤 `loadFavoritesNav()`) · `favoriteIds`(목록) · `favoriteListeners`(새로 받았을 때 알림 → 업무함 다시 그림). work.js 는 그 단추를 받아 클래스만 `task-inbox__star` 로 바꾼다. 쓰기 자격이 없으면 ★ 는 표시만.
- **buildInbox 합치기 규칙**: ① my-tasks `buckets` 순서 그대로(overdue → today → this_week → later → no_due) · 묶음 안 ★(favorites 에 page id) 먼저 · 나머지 창구 순서 ② briefing `top`·`missed`·`others` 에서 `page_id` 가 있고 사유(`kind` **또는 `reasons[].kind`**)에 `review`·`mention` 이 있는 줄만 ③ 그 page_id 가 my-tasks 에 있으면 그 줄 `marks` 에 더함(「@」 · 「검토」 배지) ④ 없으면 「검토 요청 · 멘션」 묶음(키 `review_mention`, my-tasks 의 overdue · today 다음 자리)에 한 줄 — 출처 「문서」 · 보조 글 `summary` · 마감 `due` · briefing 안 같은 page_id 는 하나(표식 합침) ⑤ 공지 · 기한 · 체크 · 파일 줄은 잇지 않는다(공지는 홈 `#work-home-notice` 그대로) ⑥ briefing 이 null(403 · 404)이면 묶음만 빈다. 끝난 작업 보기에서는 briefing 을 잇지 않고 꼬리표도 없다. 홈은 앞 10줄(묶음 경계를 지나며 셈).
- 커밋 해시: 마지막 답에 적는다(이 파일이 든 커밋).

## 5. 판단해 정한 것(지시서에 값이 없던 것)
- 접힌 자료실 셸은 ☰ · 파일 · 검색만, 이야기 셸은 ☰ · 「＋ 새 이야기」 · 이야기 넷 · 검색(시험은 넘침 0 · 폭 64 만).
- `#appbar-apps` 의 `aria-controls` 를 `sidebar` → `app-grid`(details 에 `id="app-grid"` — class 를 앞에 두어 `details class="appbar__apps"` 글자 그대로).
- 메신저 타일 id 는 옛 아이콘 줄 단추의 `messenger-open` 을 이어받음. 옛 메뉴의 「이름 · 역할」 줄(`.app-menu__me`)은 격자에 없다 — 「내 계정」 타일 설명(화면 읽기용)과 사이드바 사용자 칩에 남는다.
- 닫힌 격자 판은 `display:none`(화면 점검 `ui_snapshot` 이 닫힌 details 안을 보이는 것으로 세어 결함 9건을 냈다).
- briefing 에서만 온 줄(검토 · 멘션 문서)에는 완료 원 대신 빈 칸 — 작업이 아닐 수 있고 창구가 그 줄의 can_edit 을 주지 않는다(숨김 조건이 권한 판단이 아니라 「줄의 출처」).
- 꼬리표 수는 모든 묶음에 붙인다(「나중에 3」 · 「기한 없음 1」).

## 6. DESIGN · 지시서와 다르게 한 것(이유)
1. **홈에도 `aside#task-pane` 을 더했다**(`work_home_page.php`, 지시서는 「#work-home-tasks 안」만 적음) — DESIGN 2.20-A 「폭」이 업무함 화면의 960~1199 · <960 을 2.21 칸으로 정했는데 홈에는 칸이 없었다. 내 작업과 같은 aside 한 칸이다.
2. 지시서의 `php scripts/community_asset_version.php --write` 는 그 옵션이 없다(사용법: 인자 없음 = 쓰기 · `--check` · `--print`) — 인자 없이 돌렸다.
3. 「검토 · 멘션」 대상을 `kind` 뿐 아니라 `reasons[].kind` 까지 본다 — Briefing 은 같은 페이지의 사유를 한 줄로 합쳐(예: 지난 기한 + 검토) `kind` 에는 앞선 것 하나만 남기므로, `kind` 만 보면 검토 표식이 빠진다.
4. ⋮⋮⋮ 점의 aria-label 은 넣지 않음(3절).
5. 폰 격자 판의 아래 모서리: moa 테마의 `.panel` 둥글기(18px)가 이겨 둥글게 보인다(색 · 배치 영향 0).

## 7. 메인 세션에 알릴 것(이 지시서 밖 파일)
- `tests/q_launcher.sh`(운영을 치는 시험)의 Q:T-8 줄 343 · 344 가 아이콘 줄(`nav.app-rail`)을 기대한다 — 반영 뒤 실패한다(수정 파일 목록 밖이라 고치지 않음). 같은 이유로 `tests/community/messenger_rail_browser.py`(아이콘 줄 메신저 시험 전체) · `messenger_window_browser.py:735`(아이콘 줄 글자 읽기)도 기대값을 바꿔야 한다. `details.appbar__apps > summary` → `#logout` 를 누르는 시험(messenger_window · messenger_store · qf13h_privacy)은 그대로 맞는다.
- `public/assets/moa.css` 의 `.moa-shell-links` · `.moa-brand .appbar__apps` · `.moa-brand-menu` · `.moa-brand .app-menu` 규칙과 `public/assets/messenger-access.js` 의 `.app-rail__messenger` 선택자는 이제 닿는 요소가 없다(죽은 규칙 — 해 없음). 메신저 자격이 없는 사람의 격자 「메신저」 타일에는 messenger-access.js 가 「이용 제한」 작은 글자를 붙인다.
- `app.css` 의 `.work-cols` · `.task-list--compact` 와 work.js taskRow 의 `'compact'` 갈래는 이제 쓰는 곳이 없다(남겨 둠).

## 8. RV-U2-1 — 옛 아이콘 줄을 기대하던 시험 셋 · 죽은 선택자 정리 (2026-10-12)

메인 세션 검토 시험(`review/summary.txt` 마지막 줄 `DONE`)과 포트 8768 이 빈 것을 확인한 뒤에 고쳤다. 앱 동작 변경 0(시험 기대값 · 죽은 CSS 규칙 · 죽은 선택자뿐). 결과 폴더 `/home/changkwon/qaredi-qf12u2-results-20261012/RV1/`.

### 8.1 변경 파일

| 파일 | 내용 |
|---|---|
| `tests/q_launcher.sh` | Q:T-8 — 설명 글자 「사이드바 brand 줄」 → 「본문 오른쪽 위 앱 격자」 · 「앱 아이콘 줄 1개」 · 「아이콘 줄 지금 앱 = 자료실」 두 expect 를 격자 넷으로: `<div class="shell-tools">` 1 · `details class="appbar__apps" id="app-grid"` 1 · `<a class="app-menu__app" href="/tbox/" aria-current="page">` 1 · `<nav class="app-rail"` 0 |
| `tests/community/messenger_rail_browser.py` | 아이콘 줄 메신저 시험 → 격자 메신저 타일 시험(같은 3 셸 × 직원 · 손님 · 역할로 보기 × 1280 · 960 · 959 · 390). 잰 성질: 사이드바 중복 0 · 옛 줄 0 · 격자 1벌 · 보이는 공통 입구 1(넓으면 ⋮⋮⋮ · 폰은 윗줄 말풍선) · 타일 `button#messenger-open[data-messenger-open]` 이 격자 안에 1 · 앱 타일 바로 뒤 · 배지 `[data-message-unread]` 타일 + ⋮⋮⋮ summary 둘 다 「3」 · 누르면 떠 있는 창(비모달) · 타일 누르면 격자 닫힘 · Esc 닫힘 · 초점 · 자격 없는 사람: 타일 `a[href=/tbox/messages]` + 「이용 제한」 작은 글자 · 안내 창(모달) · 메신저 창구 요청 0. 실패는 적고 계속 잰다(끝에 `RESULT pass= fail=` · 실패 있으면 종료 1) · results.json 에 실패 이름 · 초점 자리 metrics |
| `tests/community/messenger_window_browser.py` | ① C-6(735행) `rail:` 읽기 → 격자 타일 이름(`grid`) · 격자 안 창 여는 단추 수(`gridOpeners`) · 옛 줄 수 — 단언을 「사이드바 글자 · 단추 0 · 옛 줄 0 · 격자 「메신저」 1 · 여는 단추 1」로 ② 아래 8.4 의 이유로 **735행 밖 넷**: `Tab.reveal()`(닫힌 격자 안 `#messenger-open` 이면 ⋮⋮⋮ 를 먼저 누름) — `Tab.open()` · Q:T-11 여는 단추 누름이 씀 / Q:T-11 손님 · 역할로 보기 「opener 0」 문자열 검사에서 HTML 주석을 뺌 / Q:T-13 배지 읽기 `inner_text` → `text_content` / Q:T-16 폰 「서랍이 시트 위」 → 「앱 이름 ▾ 로 연 격자가 시트 위(로그아웃 누를 수 있음)」 · 닫기 = Esc |
| `public/assets/moa.css` | 삭제만: `.moa-shell-links` · `.moa-shell-links a` · `.moa-shell-links a:hover`(85행 안) · `.moa-brand .appbar__apps` · `.moa-brand .moa-brand-menu` · `.moa-brand .app-menu` · `@media(max-width:959.98px)` 한 줄(안의 세 규칙 모두 `.moa-brand .appbar__apps`/`.app-menu` — 닿는 요소 0). 다른 규칙 0 |
| `public/assets/messenger-access.js` | `link.matches('.app-rail__messenger, #appbar-messenger')` → `link.matches('#appbar-messenger')` + 주석 2줄(8.4 ⑤) |
| `docs/qf12-u2-report.md` | 이 절 |

`tests/catalog.json` 은 바꾸지 않았다 — `messenger_rail_browser.py` 는 카탈로그 항목이 없고, `launcher.q_launcher` · `tbox.messenger_window_browser` 의 notes · covers 에는 아이콘 줄 낱말이 없다. U2 본체(shell_top.php · app.css · app.js · work.js) 0.

### 8.2 실행한 명령과 결과(원문은 결과 폴더)

| 명령 | 결과 | 폴더/로그 |
|---|---|---|
| `python3 tests/community/memories_harness.py --suite messenger_rail_browser.py --output …/RV1/rail` (지시서 그대로) | **못 돎** — rc 2 `argument --suite: invalid choice: 'messenger_rail_browser.py'`(하네스 선택지에 없다 · 이 시험은 DB · 서버 없이 PHP fixture + 가로챈 합성 창구만 쓴다) | `rail-harness.log` |
| `MESSENGER_RAIL_RESULTS=…/RV1/rail python3 tests/community/messenger_rail_browser.py` | **pass 602 · fail 12** (rc 1) — 12 = 아래 8.3 ① | `rail.log` · `rail/results.json` |
| 같은 새 시험을 **고치기 전**(작업 트리 = `1a15bb4`, 시험 파일만 scratch 사본) | pass 602 · fail 12 — 같은 12건(내 변경과 무관 증거) | `rail-dryrun-before-edits.log` · `.results.json` |
| 기준 판 `8c36f8b`(git archive 사본)의 **옛** rail 시험 | 470 checks passed · rc 0 — 옛 「restricted information focus returns」 24건 통과 | `rail-base-8c36f8b.log` · `rail-base-8c36f8b/` |
| `… --suite messenger_window_browser.py --output …/RV1/window` (전체 · 기본) | **pass 138 · fail 17 + 중단**(Q:T-11 「런처 타일」 `/` 의 `a#messenger-open` 이 안 보여 click 30초 초과) | `window.log` · `window/` |
| 기준 판 `8c36f8b` 같은 명령 | **pass 138 · fail 17 + 같은 자리 중단** — 이름 단위로 위와 같음 | `window-base-8c36f8b.log` · `window-base-8c36f8b/` |
| `QF11B_ONLY=t12,t13,t14,t16,t17,t18,t20,c3,c5,c6,snap` 같은 명령(중단 뒤 묶음들) → `…/RV1/window-rest` | **pass 275 · fail 26** = snap 16(기준 판 같음) + Q:T-16 10(아래 8.3 ②) · C-6 6건 통과 | `window-rest.log` · `window-rest/` |
| 기준 판 `8c36f8b` 같은 묶음 | pass 282 · fail 19 = snap 16 + **C-6 「메신저」 text once 3**(기준 판에서부터 실패 — 이번에 고침) | `window-rest-base-8c36f8b.log` |
| `bash -n tests/q_launcher.sh` · `node --check public/assets/messenger-access.js` · `bash scripts/security_lint.sh` · `git diff --check` | 0 · 0 · 0(「결과: 통과 (금지 패턴 0건)」) · 0 | `static.log` |
| `grep -rn "moa-shell-links\|moa-brand-menu" src public` | 1줄 — `src/views/shell_top.php:59` 의 **PHP 문서 주석**(「…`.moa-shell-links` 를 없앴다」 이력 글 · 화면 출력 0). CSS · 마크업 · JS 참조 0. shell_top.php 는 이번 수정 파일 밖이라 그대로 | `static.log` |
| `php scripts/community_asset_version.php --check` | rc 0(moa.css · messenger-access.js 는 filemtime 버전) | — |

중간 실행(덮어쓴 것 포함): `window-run1.log`(Tab.reveal 뒤 · Q:T-13/16 고치기 전 전체) · `window-rest-run1.log`(pass 69 · fail 1 Q:T-13 배지 + Q:T-16 `details.appbar__apps > summary` click 중단) · `window-c6.log`(C-6 만 16/0 뒤 `Tab.open` 「no visible messenger opener」 중단 — reveal 넣기 전). 가장 첫 전체 실행(reveal 넣기 전: pass 118 · fail 25 · 「셸 사이드바」 click 중단)의 로그는 다시 돌리며 지웠다(수치만 이 줄에 남김).

q_launcher.sh 는 writes_prod(운영 8082) 시험이라 **실행하지 않았다** — `bash -n` 과, 기대 문자열 넷을 `src/views/shell_top.php` 소스 마크업과 맞춰 봄(768 `<div class="shell-tools">` · 769 `<details class="appbar__apps" id="app-grid">` · 783 타일 `href` = `config/apps.json` 의 `"/tbox/"` + 지금 앱 `' aria-current="page"'` · `<nav class="app-rail"` 0)만.

### 8.3 실패로 남은 것 — U2 가 바꾼 동작(기준 판에서는 통과) · 앱 고침은 U2 본체 몫이라 손대지 않음

① **넓은 화면 · 자격 없는 사람(손님 · 역할로 보기)**: 격자 「메신저」 타일(`/tbox/messages` 링크)을 눌러 「메신저 이용 안내」 창을 열고 Esc 로 닫으면 **초점이 `<body>` 로 떨어진다**(rail 12건 = 3 셸 × 2 × 1280 · 960 · 측정 `{"tag":"BODY"}`). 타일을 누르면 app.js 가 격자를 먼저 닫아 타일이 안 보이고, messenger-access.js 의 `opener.focus()` 는 안 보이는 요소라 듣지 않는다. 기준 판(아이콘 줄 — 늘 보임)에서는 24건 통과. 고칠 자리 후보: messenger-access.js close 처리에서 opener 가 안 보이면 ⋮⋮⋮(`#app-grid > summary`, 폰은 `#appbar-apps`)로 — 앱 동작 변경이라 이번엔 하지 않았다.
② **폰(390 · 412 · 412 터치) · 메신저 아래 시트가 열린 채**: 윗줄 앱 이름 ▾ 를 눌러도 앱 격자에 닿지 못한다 — 실제 셸(window Q:T-16 10건)에서는 `grid.open` 거짓 · 로그아웃 누름 자리 0, 합성 fixture(probe)에서는 격자는 열리지만 **시트 아래**에 깔린다(격자 판 z 40 < 시트 z 60 · 로그아웃 가운데를 누르면 시트의 `.moa-people`). 기준 판은 서랍이 모달(top layer)이라 시트 위였다(10건 통과). 이어지는 「닫으면 시트가 다시 보인다」 5건은 격자가 안 열린 채 Esc 가 시트를 닫은 결과다. 고칠 자리: app.css(격자 판 z) · app.js(시트가 열렸을 때 ▾) — U2 본체.
③ 참고(통과): 넓은 화면 직원이 격자 타일로 연 창을 Esc 로 닫으면 초점은 ⋮⋮⋮ 가 아니라 옆 아바타 `a.shell-tools__me` 로 간다(messenger.js `firstFocusable(main)` — 선택자에 `summary` 가 없다). 시험은 「격자 줄(.shell-tools) 안」으로 잰다 — ⋮⋮⋮ 로 돌려야 하면 messenger.js 몫.

**기준 판에서부터 실패하던 것(이번 변경과 무관 · 고치지 않음)**: Q:T-19 「GPT/OpenAI 글자 0」 17건(셸 변경 이력 「GPT 간편 연결」 · 메신저 창 「GPT · 내 ChatGPT」 등 — metrics `gpt-*`) · Q:T-11 「런처 타일」 `/` 의 `a#messenger-open` 안 보임 → 기본 전체 실행이 그 자리에서 멈춤(그래서 뒤 묶음은 `QF11B_ONLY` 로 따로 돌렸다) · snap AUDIT_JS 16건(`span#messenger-move-help.sr-only` 등 sr-only 글이 「clipped」로 잡힘) · (기준 판의 C-6 3건은 이번에 기대값을 고쳐 통과).

### 8.4 지시서와 다르게 · 넘어서 한 것(이유)

④ messenger_window_browser.py 를 735행 밖 넷 더 고쳤다 — 모두 「옛 아이콘 줄(늘 보이는 `#messenger-open`) · 옛 서랍」을 기대하던 자리다. 안 고치면 Q:T-11 첫 「셸 사이드바」 click 에서 시험 전체가 멈춰 C-6 까지 가지 못한다(첫 실행 118/25 + 중단). 손님 · 역할로 보기 8건은 U2 셸의 **HTML 주석**(`<!-- … [data-messenger-open] … -->`, shell_top.php 765행 — 브라우저로 나가는 주석)이 문자열 검사에 걸린 것이라 주석을 빼고 센다(마크업 속성은 그대로 0 확인).
⑤ messenger-access.js: 지시서 「선택자 → 격자 메신저 타일을 가리키게」와 「격자 타일에 작은 글자가 붙는 지금 동작 유지」는 함께 될 수 없다 — 그 선택자는 **글자를 붙이지 않을** 요소 목록이라, 격자 타일을 넣으면 「이용 제한」이 사라진다. 지금 동작 유지를 따르고 죽은 `.app-rail__messenger` 만 뺐다(격자 타일은 주석에 이름으로 적음). rail 시험이 「격자 타일에 `small.messenger-access-note` 「이용 제한」 1 · `#appbar-messenger` 0」을 잰다(전부 통과).
⑥ rail 시험의 check 를 「실패 적고 계속」으로 바꿨다 — 첫 실패에서 멈추면 ①이 뒤 단언 수백 개를 가린다. 종료 코드는 실패가 있으면 1 그대로.
