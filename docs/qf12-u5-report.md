# U5 구현 보고 (2026-10-13 · 브랜치 feat/qf12-u5)

지시서 `docs/qf12-u5-directive.md` · 기획 17.2 · DESIGN 2.28. 결과 폴더 `/home/changkwon/qaredi-qf12u5-results-20261013/`(`run1/` 수락 · `static.log` 정적).

## 변경 파일
- U5-1 뼈대: `src/community/handlers/page_view.php`(`$viewKind` 가 project 면 `#db-wrap` 을 hidden 없이 그리고 맨 앞에 `.skeleton` · task 면 `#task-full` 안에 `.skeleton` · doc · 못 보는 사람은 그대로) · `public/assets/app.css`(U4 규칙 아래에 더함 — `data-project-view` 전 `#db-wrap` 안 뼈대 · `#db-msg` 밖 전부 숨김 · `.skeleton` / `.skeleton__bar` = `--surface-2` · `--r-ctl` · 움직임 0) · `public/assets/community/work.js`(`dropSkeleton()` — enterProject · 쓰임 아님 갈래 · 작업 showFull 뒤)
- U5-2 오른쪽 위: `src/views/shell_top.php`(summary `btn btn--quiet` + 글자 「앱」 · 칩 = 이니셜 + `span.shell-tools__name`(이름 + 「님」) · aria-label · href 그대로) · `app.css`(summary 높이 40 · 0 12px · 14px 500 · 아이콘 20 / 칩 높이 40 · 아바타 32 · 이름 최대 14em 말줄임 · 테두리 `--line`)
- U5-3 모아 홈: `src/views/launcher.php`(`$homeShortcuts` 에서 `tasks` 뺌 · 업무 홈 아이콘 `work` · 설명 한 문장 · 우리 공간 `spaces`) · `src/Apps.php`(ICON_PATHS `spaces` 고정 path 하나) · `public/assets/dashboard-widgets.css`(줄 `width: fit-content; max-width: 100%` + 좌우 auto — 넘치면 지금처럼 가로 밀기 · 설정의 tasks 줄 숨김)
- import 버전 줄(`php scripts/community_asset_version.php` — dashboard · database · editor · suggestions · table-editor · templates · work · `../messenger.js`)
- 시험: 새 `tests/community/qf12_u5_browser.py` · `memories_harness.py`(choices) · `tests/catalog.json`(한 항목)
- 고친 옛 단언: `tests/community/qf12_shell_browser.py` 292행 — summary 마크업 문자열 `btn btn--icon btn--plain` → `btn btn--quiet`(2.28 「앱 단추」가 테두리 단추로 바꿈). **돌리지 않음(미검증)** — 메인 최종 판에서.

## 수락 시험 (격리 틀 · 포트 8768/8769 · 1440 · 412 × 밝게 · 어둡게)
- `qf12_u5_browser` **140/0** (첫 실행) — `run1/`(results · metrics · shots · run.log)
  - ① 프로젝트 · 작업: 매 프레임 일반 부품(`#db-add-view` 글자 단추 · `#db-table-wrap` · 빈 `.db-widget` · 일반 문서 부품) **0 프레임** · 뼈대 35~68 프레임 보였다가 화면이 선 프레임 전에 사라짐 · 끝에 DOM `.skeleton` 0 · 창구를 막은 상태 = 뼈대만 · 일반 문서 = 뼈대 0(HTML · 프레임)
  - ② 「앱」 · 높이 40 · 아이콘 20 · 칩 「claude_employee01 님」 높이 40 · 아바타 32 · 누르면 /tbox/settings · 긴 이름(1440 · 960) 말줄임 · 넘침 0 · 412 줄 숨김 · 앱 이름 ▾ 그대로
  - ③ 바로가기 줄 여백 왼쪽 = 오른쪽(1440: 438/438 · 412: 16/16) · 내 작업 0 · 업무 홈 하나 + work 아이콘 · 우리 공간 spaces · 전체 서비스 메뉴 내 작업 0 · 홈 편집 설정 열고 취소 = 쓰기 0
- ④ 정적 — `static.log`: security_lint 0 · php -l 넷 · node --check(바뀐 JS 여덟) · asset_version --check · git diff --check 통과 · 더한 줄에 innerHTML · style= · 색값 0

## DESIGN 과 다르게 한 것 · 알아 둘 것
- 프로젝트 뼈대는 `main` 의 형제가 아니라 `#db-wrap` 안 — U4 의 `.main > :not(…)` 숨김(바꾸기 금지)이 새 형제를 숨기기 때문. 그래서 project 일 때만 `#db-wrap` 을 처음부터 연다(그 밖 페이지는 hidden 그대로). 알림 줄 `#db-msg` 는 숨기지 않는다(창구 실패 문구가 보이게).
- 「앱」 글자가 보이므로 CSS 툴팁 `data-tip="앱"` 은 뺐다(접근 이름은 aria-label 그대로 · 보이는 글자는 aria-hidden).
- 「최대 14자」는 이름 칸 `max-width: 14em`(한글 14자 · 영문은 더 많이)로 했다.
- **「내 작업」 id 는 지우지 않았다**: `home-dashboard.js`(허용 목록 밖)가 기본 바로가기에 `tasks` 를 갖고, 홈 편집 설정이 틀에서 각 id 의 이름을 찾는다(없으면 설정을 열 때 오류). 그래서 틀에 `hidden` 항목 하나로 남기고(화면 · 전체 서비스 메뉴 0) 설정의 그 줄은 CSS 로 숨겼다. 저장된 구성 · 서버 허용 목록(ProductSpaces · dashboard-canvas.js)은 그대로. 남는 흠: 기본 구성에서 「메신저 앞으로」를 처음 누르면 숨은 tasks 와 자리를 바꿔 화면이 한 번 그대로다. 깔끔히 하려면 home-dashboard.js 의 기본값에서 tasks 를 빼고 설정이 틀에 없는 id 를 건너뛰게(다음 지시).

## 못 한 것
- 다른 회귀는 지시대로 돌리지 않음. 카탈로그 **밖** 옛 시험 셋은 옛 결정(업무 홈 · 내 작업 따로 — 2026-10-12)을 단언해 이번 결정과 어긋난다 — 고치지도 돌리지도 않음: `tests/home_shortcuts_browser.py`(「clear separate names」 · 내 작업 클릭 · 전체 서비스 내 작업) · `tests/compact_home_ui.py`(「all services eight」 → 이제 7 · 아이콘 타일 높이 every(숨은 tasks 0 높이) · 설정에서 「내 작업」 해제) · `tests/compact_home_legacy_auth.py`(같은 틀).
- DESIGN 2.28 표에 「확인 결과」 줄은 넣지 않음(DESIGN.md 는 허용 목록 밖).
