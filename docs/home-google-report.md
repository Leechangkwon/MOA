# 보고서 H1 — 모아 홈 구글 첫 화면 방식 (지시서 `docs/home-google-directive.md` · DESIGN 2.31 · 2.30)

2026-10-14 · Opus 구현 에이전트 · worktree `/home/changkwon/qaredi-home-20261014`(브랜치 `feat/home-google`) · 결과 `/home/changkwon/qaredi-home-results-20261014/`

## 변경 파일
- `src/views/shell_tools.php`(새) — 셸의 `div.shell-tools`(메신저 · 앱 · 내 계정 칩 · 판 둘)와 통합 검색 창 `dialog#search-dialog` 를 그대로 옮긴 한 파일. 받는 값: `$shellMessenger · $shellUser · $shellApps · $shellCurrentApp · $shellActive · $shellMeLabel · $shellInitial · $shellRole`. 고친 것 둘: 앱 판 「모아 홈」 타일 `aria-current`(`$shellCurrentApp === 'home'` — 셸에서는 그대로 없음) · 내 계정 판 타일 「테마」(`data-design-open` · 모든 화면).
- `src/views/shell_top.php` — 그 두 덩이를 지우고 `require shell_tools.php` 한 줄(아래 「다르게 한 것」 ②).
- `src/views/launcher.php` — `$launcherLive` 갈래만: 셸 값 계산(세션 사용자 · 판정 함수 결과 · 앱 목록 — 역할 비교 0) · 머리 = `header.moa-nav` 안 shell_tools.php · 가운데 `section.home-hero`(h1 화면 읽기 「모아 홈」 · `p.home-wordmark` 「Qaredi」 aria-hidden · `form.home-search` · `nav#dashboard-services` · 바로가기 template — 메신저는 `data-messenger-open` · 끝 `button.home-shortcut--add`) · `details.home-dashboard`(summary 「내 대시보드 ▾ / 접기 ▴」 · 머리 줄 h2 + 홈 편집 · 상태 줄 · 지금 위젯 격자 그대로) · 머리에 search.js · app.js(defer) · 바닥에 아이콘 template. 도구 위젯 링크의 `id="messenger-open"` 은 오른쪽 위 단추로 넘김(id 하나). 로그인 전 · 동의 전 갈래 0 변경.
- `public/assets/home-dashboard.js` — 검색 칸(Enter → `qarediSearchOpen(글자)` · `/` 키 초점 · 자동 초점 0) · 접힌 대시보드(펼침 기억 `qaredi-home-dashboard:v1:<아이디>` · 첫 화면은 `/api/me` + `/api/me/home-dashboard` 만 — 공간 목록 · 위젯 · 최근 작업 스크립트는 처음 펼칠 때 `openDashboard` 한 번 · 편집 중 접기 막음 + summary `aria-disabled`) · 「＋ 바로가기」(펼침 → 홈 편집 → 설정 칸/창 → 「상단 바로가기」 열고 초점) · 옛 「전체 서비스」 메뉴 코드 삭제.
- `public/assets/search.js` — `window.qarediSearchOpen(text)` 하나(입력 칸에 글자 → 지금 open()).
- `public/assets/app.js` — 로그아웃 처리를 `armLogout()` 로 떼어 셸 · 모아 홈이 같이 씀(본문 그대로) · 셸 없는 화면 갈래에 「div.shell-tools #logout 이 있으면」 `/api/me` → 관리자 타일(can_admin) · 로그아웃만 · 앱 판 「검색」 타일은 `#nav-search` 가 없으면 `qarediSearchOpen()`.
- `public/assets/moa.css` — 2.31 의 다섯 클래스 + 모아 홈 머리의 shell-tools(<960 에서도 보임 · <720 아이콘만 44 · 판 전체 폭) · 옛 바로가기 모양(dashboard-widgets.css)을 `.home-hero` 안에서 덮음. 색값 0.
- `src/views/icon_templates.php`(새) · `src/views/shell_bottom.php` — 아이콘 `<template>` 묶음을 그대로 옮기고 require(아래 「다르게 한 것」 ③).
- 시험: `tests/community/home_h1_browser.py`(새) · `tests/community/memories_harness.py`(choices) · `tests/catalog.json`(community.home_h1_browser). 문서: `wiki/qaredi/guide-start.json`(「내 대시보드 꾸미기」 둘째 단락 한 개) · 이 보고서.
- 보안 핵심 파일 0 · 창구 · 판정 · 라우트 0 · `src/handlers/home.php` 0.

## 수락 시험과 결과
- 명령: `. /home/changkwon/qaredi-home-results-20261014/env.sh && python3 tests/community/memories_harness.py --suite home_h1_browser.py --output /home/changkwon/qaredi-home-results-20261014/accept`
- 결과: **pass 377 · fail 0**(rc 0) — `accept/home-h1-browser-results.json` · `accept/home-h1-browser-metrics.json` · `accept/shots/`(1440 밝게 · 어둡게 · 412 밝게 · 어둡게 · 360 고대비 · 720 · 펼친 대시보드 1440 · 412 · 검색 · 바로가기 설정 · 내 계정 판 · 로그인 전 · 동의 전). 실행 뒤 8768/8769 리슨 0 · `/tmp/qaredi-stage13-*` 0 · 합성 DB 지움(`OWNED_TEST_PROCESSES_STOPPED=1; SYNTHETIC_DB_DROPPED=1`).
- 앞선 실행 `run1`(357/20) · `run2`(359/18)는 **시험 쪽** 잘못만(summary 글자에 ▾ · ▴ 포함 / 닫힌 details 안 요소를 getBoundingClientRect 로 「보임」으로 셈 → checkVisibility) — 코드 변경 없이 시험만 고쳐 `accept` 한 번.
- 확정 값: ① div.shell-tools 문서에 하나 · 서버 HTML 의 shell-tools 가 `/` = `/tbox/` = `/community/`(aria-current 만 다름) · 검색 창 · `#messenger-open` 각 하나 · 순서 메신저 · 앱 · 칩(44) · 「모아 홈」 aria-current · 내 계정 판 = 내 계정 · 내 활동 · 테마 + 로그아웃(셸 두 화면도 같음 · 「테마」 → q-design-dialog) · 관리자 타일 관리자만 · 412 판 left 0 · 폭 412 · top 64 ② 「Qaredi」 64px 600(412 · 360 은 44) · h1 「모아 홈」 폭 1 · 검색 칸 52 · 반경 26 · 폭 = min(720, 본문) · 아이콘 20 · 자동 초점 0 · `/` 초점 · 칸 안의 `/` 는 글자 · Enter → 같은 창에 그 글자 · 「페이지」 · 「파일」 묶음 ③ 원 56 · 아이콘 24 · 이름 13px · 기본 다섯 + 「＋」 · 줄 [6](≥720) · [4, 2](<720) · max-width 824(한 줄 최대 8) · 일곱 + 「＋」 = 한 줄 8 · 메신저 바로가기 → 메신저 창(주소 `/` 그대로) · 「＋ 바로가기」 → 펼침 + 편집 + 설정(1440 옆 칸 · 412 창) · 설정 순서가 곧바로 줄에 ④ 첫 화면 접힘 · 접힌 동안 부른 창구 = `/tbox/api/me` · `/tbox/api/me/home-dashboard` · `/tbox/api/messages`(안 읽은 수)뿐 — 위젯 창구 0 · 펼친 뒤 product-spaces · files/recent · favorites · folders/favorites · community me/recent · favorites · briefing · 새로 고침 뒤 펼침 기억 · 편집 중 접기 막힘 · 접고 새로 고침 → 접힘 · 취소 쓰기 0 ⑤ 로그인 전 · 동의 전 서버 HTML 이 H1 직전 launcher.php(34cfd3d)와 **바이트 같음**(13 630 · 13 372 바이트) · 실제 서버에서도 머리 로고 · 소개 화면 · shell-tools 0 · 18 조합 넘침 0 · 누름 자리 < 44 0 · 대비 최소 5.55 · 콘솔 0 · 모아 홈 「로그아웃」 → `/tbox/login`.
- 정적: `security_lint` 0건 · `php -l` 5 파일 0 오류 · `node --check` 3 · `community_asset_version.php --check` rc 0 · `wiki_check` 0건 · catalog JSON · `git diff --check` 0(새 파일 포함) — `static.txt`.
- 다른 시험은 지시대로 돌리지 않았다(밤 회귀).

## 고친 옛 단언 (돌리지 않음)
| 파일 | 단언 | 바꾼 것 |
|---|---|---|
| `tests/community/qf12_u5_browser.py` [home] | `home %s: 전체 서비스 menu has 업무 홈 once · 내 작업 0` | → `전체 서비스 menu gone (H1 — shared top-right tools)` · 「home settings」 흐름 앞에 대시보드 펼치기 한 줄(홈 편집이 접힌 칸 안) |
| `tests/home_shortcuts_browser.py` | `all-services seven routes · 업무 홈 once · 내 작업 0` | → `H1 all-services menu replaced by the shared top-right tools` |
| `tests/compact_home_ui.py` | `all services seven real routes (내 작업 0)` · `account ellipsis and accessible full name` | → 메뉴 0 + shell-tools 하나 · 내 계정 칩 이름 말줄임 + aria-label 「내 계정 메뉴 — 」 |
| `tests/community/qf12_shell_browser.py` [t38] | `launcher (…) has no rail · no shell-tools grid row` · `launcher header links 업무 · 이야기 (커뮤니티 0)` | → 로그인 뒤 shell-tools 하나 · 로그인 전 0 / 머리 링크 → 앱 판 타일 업무 · 이야기 |
| `tests/community/cafe_c4_browser.py` [c6] | `account tiles 내 계정 · 내 활동 + 로그아웃 row` · `avatar → account panel full width under the top bar` | 타일 기대값에 「테마」 |
| `tests/q_launcher.sh` | `로그인 상태 런처에 사용자 표시 (moa-account 1)` | → `shell-tools 1 · moa-account 0` |

## 못 한 것 · 남은 것
- **밤 회귀에서 깨질 것으로 보이는 옛 시험**(단언이 아니라 「대시보드가 첫 화면에 펼쳐져 있다」는 전제 — 지시서 범위 밖이라 고치지 않음): `tests/compact_home_ui.py`(첫 위젯 y ≤ 300/340 · `#home-edit` 흐름) · `tests/home_dashboard.py` · `tests/home_dashboard_sync.py` · `tests/dashboard_inspector_ui.py` · `tests/dashboard_inspector_integration.py` · `tests/dashboard_widgets_ui.py` · `tests/freeform_dashboard_ui.py` · `tests/compact_home_legacy_auth.py` · `tests/neutral_home_entry.py` · `tests/community/briefing_integration.py`(`#home-edit` · `.dashboard-card` · `#home-widgets` 를 곧바로 누르거나 잰다). 고치는 길은 하나 — 페이지를 열고 `details.home-dashboard > summary` 를 먼저 누르거나 `localStorage['qaredi-home-dashboard:v1:<아이디>']='true'` 를 넣고 연다. `tests/q_launcher.sh` 의 `<script` 줄 수(5)와 `moa-shortcuts` 단언, `qf12_shell_browser.py` 의 `moa-shortcuts` 타일 단언은 H1 전부터 어긋나 있던 것(이번에 손대지 않음).
- 옛 CSS `.home-heading` · `.home-nav` · `.home-services-menu` · `.moa-account`(모아 홈 갈래)는 `dashboard-widgets.css` 에 있다 — 허용 목록 밖이라 지우지 않았다(모아 홈은 더는 쓰지 않음).
- 메신저를 못 쓰는 사람(손님 · 역할로 보기)의 메신저 바로가기는 지금처럼 `/tbox/messages` 링크 — messenger-access.js 가 처음 읽을 때 있는 링크에만 안내 창을 걸어 나중에 그려지는 바로가기는 안내 창 대신 그 화면으로 간다(H1 전과 같음).
- 「테마」 타일로 연 테마 창을 닫으면 design.js 가 초점을 연 타일(닫힌 판 안)로 돌려 초점이 사라진다 — design.js 는 목록 밖.
- 검색 칸 돋보기와 자리 글 사이가 디자인 값(12)보다 넓게 보인다(브라우저 search 칸 장식) — 재지는 않았다.

## DESIGN 과 다르게 한 것
1. **`src/handlers/home.php` 를 고치지 않았다** — 그 파일은 `/tbox/` 자료실 화면이다. 런처는 `src/site/launcher_page.php` → `src/views/launcher.php` 이고, 셸 값 · 테마 창 · 검색 창 · 메신저 자리는 전부 `launcher.php`(허용 목록) 안에서 했다. launcher_page.php 0 변경.
2. **검색 창 마크업도 shell_tools.php 로 옮겼다**(shell_top.php 의 변화가 require 하나 + 사이드바 안 `dialog#search-dialog` 10줄 삭제) — 앱 판 「검색」 타일과 모아 홈 검색 칸이 같은 창을 열어야 하고 두 벌은 금지라서. search.js 가 열 때 창을 본문 끝으로 옮기므로 자리 차이는 화면에 없다(시험: 셸 HTML 의 창 하나 · 모아 홈 하나).
3. **목록 밖 파일 둘**: `src/views/icon_templates.php`(새) · `src/views/shell_bottom.php`(아이콘 `<template>` 38줄을 그대로 옮기고 require 한 줄 — 셸의 출력 글자 같음). shell_tools.php 의 `[data-icon]` 자리를 app.js 가 이 template 으로 칠하는데 모아 홈에는 template 이 없었다. SVG 를 모아 홈에 한 벌 더 두는 것은 두 벌 금지에 걸려 이 길을 골랐다.
4. 모아 홈에 app.js 를 싣는다(아이콘 · 판 둘 열고 닫기 · 로그아웃 · 관리자 타일이 셸과 같은 함수). 셸 없는 화면 갈래에서 위 둘만 돌고 다른 화면 기능은 돌지 않는다.
5. 새 클래스는 2.31 의 다섯만. 머리는 기존 `header.moa-nav` · 대시보드 머리 줄은 기존 `.moa-section-head` + `.toolbar` · h1 은 기존 `.sr-only` · summary 글자 바꿈은 속성 `[data-when]`.
6. 공간 목록(`/api/product-spaces`)도 위젯 자료로 보고 처음 펼칠 때 부른다(안내 · 위젯 출처에만 쓰임).
7. 720~959 폭(DESIGN 은 <720 만 정함)에서는 모아 홈 머리 셋을 글자까지 보인다 — 셸의 폰 윗줄이 모아 홈에는 없어서.
8. 「＋ 바로가기」 접근 이름은 「바로가기 추가」(보이는 글자 「바로가기」 포함).

## H1b — 메인 검토 뒤 보정 둘 · 2026-10-14

### 변경 파일
- `public/assets/design.js` — 테마 창을 닫을 때 연 단추가 **닫힌 판(details) 안**이면(내 계정 판 「테마」 타일) 그 판을 여는 보이는 단추로 초점을 돌린다: 판의 `summary`(넓은 화면 · 모아 홈) → 없으면 `aria-controls` 가 그 판인 단추(셸 폰 윗줄 `#appbar-account`) → 그 밖은 지금처럼 연 단추. 사이드바 바닥 · 내 계정 화면 · 로그인 화면의 테마 단추는 판 밖이라 그대로.
- `tests/community/home_h1_browser.py` — 단언 넷: 모아 홈 1440 · 412 와 셸 `/tbox/` · `/community/` 1440 에서 「테마」 타일 → 창 → Esc → `document.activeElement` = `.shell-tools .shell-account > summary`.
- 옛 대시보드 시험 9개(돌리지 않음 · `py_compile` 만) — 페이지 열기 전 init script 한 줄 `readystatechange`(interactive — defer 스크립트 **전**)에서 `details.home-dashboard` 를 연다(localStorage 를 막는 갈래가 있는 `home_dashboard.py` 에도 그대로 듣는 길): `compact_home_ui.py` · `home_dashboard.py` · `home_dashboard_sync.py`(두 페이지) · `dashboard_inspector_ui.py` · `dashboard_inspector_integration.py` · `dashboard_widgets_ui.py` · `freeform_dashboard_ui.py` · `compact_home_legacy_auth.py` · `neutral_home_entry.py`.
- `tests/community/briefing_integration.py` 는 **고칠 것 없음** — HTTP 만 보고 `id="home-widgets"` 는 지금도 HTML 에 있다(브라우저 0).
- 펼쳐도 깨지는 옛 단언(같이 고침 · 돌리지 않음): `compact_home_ui.py` 「first widget within compact target」 · 「high contrast compact position」, `neutral_home_entry.py` 「first default widget near top」(y ≤ 300/340 → 첫 위젯이 펼친 대시보드 summary 아래) · `freeform_dashboard_ui.py` 의 `header a[href="#services"]` 흐름(모아 홈 머리에서 링크가 없어져 `home_dashboard.py` 처럼 시험 HTML 에 고정 링크를 넣음 — 옛 저장 구성의 링크 처리 시험은 그대로).

### 수락 시험과 결과
- `python3 tests/community/memories_harness.py --suite home_h1_browser.py --output /home/changkwon/qaredi-home-results-20261014/accept-h1b` → **pass 381 · fail 0**(rc 0 · H1 377 + H1b 4). 실행 뒤 8768/8769 리슨 0 · 임시 폴더 0 · 합성 DB 지움.
- 정적(`static-h1b.txt`): security_lint 0 · node --check 4 · php -l 5 · py_compile 11 · asset_version --check rc 0 · wiki_check 0 · git diff --check 0.

### 남은 것
- 위 「못 한 것」의 테마 창 초점 · 옛 대시보드 시험 둘은 H1b 로 닫았다. 나머지(dashboard-widgets.css 의 옛 클래스 · 메신저 못 쓰는 사람의 바로가기 · 검색 칸 간격 · `q_launcher.sh` 의 script 줄 수 · `moa-shortcuts` 의 H1 전부터 어긋난 단언)는 그대로.
