# 보고 Q:F-15 S2 — 앞세우기 · 접기 (관문 ① 구현 · 2026-10-26)

지시서 `docs/qf15-s2-front-directive.md` · 브랜치 `feat/qf15-s2-front`(main `310bbc3` 에서) · 격리 worktree `/home/changkwon/qaredi-qf15s2-20261026` · 결과 `/home/changkwon/qaredi-qf15s2-results-20261026/`.
운영 · 운영 DB 읽기 · 쓰기 0 · 시드 계정 0 · push · 발행 · 합치기 0.

커밋(순서): `c2c78d0` 기능 · 마이그레이션 → `038ac39` 수락 시험 · 등록 · 옛 시험 기대값 → `77652f8` home_h1 `LAUNCHER_SNAPSHOT_REF = 'c2c78d0'` → (이 보고서 커밋).

## 변경 파일

| 파일 | 무엇 |
|---|---|
| `config/apps.json` | order 업무 1 · 자료실 2 · 우리 공간 3 · 이야기 4 · 이야기 `"group": "more"`(이름 · 설명 · 주소 · 아이콘 그대로) |
| `src/Apps.php` | `OPTIONAL` 에 `group` · 허용 값 `GROUPS = ['more']`(다르면 예외) · 반환 모양 주석 `group?:string` |
| `src/views/shell_tools.php` | 앱 줄 반복에서 `group === 'more'` 인 첫 앱 앞(그 앞에 앱이 그려졌을 때만) `<li class="app-menu__sep" role="separator"></li>` 한 번 · 관리자 타일은 반복 뒤 그대로 |
| `public/assets/app.css` | `.app-menu__apps > li.app-menu__sep`(격자 한 줄 전체 · 높이 1 · 바탕 `--line` · 위아래 6) + 강제 색 모드 한 줄 |
| `src/views/launcher.php` | 바로가기 틀 순서(동작 0) · 과업 카드 `details#home-tasks.home-tasks`(판정 함수로 줄 계산 · 쿠키 `qaredi_home_tasks === 'open'` 비교 하나) · 서비스 카드 PC 비서 · 이야기 `hidden`(`$launcherLive` 만) · 자료실 카드 h3 「팀 파일을 함께 써요」 · `#product-home hidden` · 로그인 전 글자 둘(I) |
| `public/assets/moa.css` | `.home-tasks` · `.home-tasks__list`(토큰만 · 2열/1열 · 강제 색 테두리) · 홈 위젯 안 서비스 카드 2열(넓은 위젯 포함 — 선택자 `:not(.home-widget--wide)` 를 뺌) |
| `public/assets/home-dashboard.js` | 기본 바로가기 넷 · 설정 목록 순서 · 과업 카드 펼침 쿠키 쓰기(`toggle`) · `space_*` 위젯 화면에서 빼고 저장 때 계정 구성의 공간 위젯을 다시 붙임(`shown` · `keepSpace`) · 위젯 추가 목록 `space_*` 다섯 0 · 추천 셋 고정 · 시작 안내 문장 · 링크 · product-home.js 안 실음 · `/api/product-spaces` 안 부름 · 고르개 글자(I) |
| `public/assets/dashboard-widgets.js` | 「최근 파일 · 문서」(Q5) · 빈 글 둘 · 설명 둘 · 오류 · 「민감 파일」(I) |
| `src/site/ProductSpaces.php` | `defaultWidgetLayout`: `$shared` 가 거짓이면 `['recent','favorites','briefing']`(공간 홈 기본 그대로 · SQL 0) |
| `src/handlers/member_register.php` | 응답 `redirect` — `next` 가 없으면 `'/'`, 있으면 `/tbox/welcome`(판정 · 세션 · 감사 · 가입 검사 0) |
| `public/assets/member.js` | 가입 뒤 `next` 없음 → `/`(사이트 첫 화면) · `login` → `/tbox/login` · 그 밖 → `/tbox/welcome` |
| `src/views/shell_top.php` | `$shellSidebarOpen` = 쿠키가 정확히 `collapsed` 가 아니면 참 · 머리 · :450 주석 · 「문서 AI」 줄 `hidden` · 더보기 머리 주석 |
| `public/assets/app.js` | `initSidebarCollapse`: 접으면 `qaredi_sidebar=collapsed`(1년) · 펼치면 `open`(1년) — 지우지 않음 · 주석 둘 |
| `db/migrations/2026-10-26_qf15_s2_shortcuts.sql`(새 · 보안 핵심) | `BEGIN` · `SET LOCAL lock_timeout='3s'` · `UPDATE public.product_personal_dashboards SET layout = layout - 'shortcuts', revision = revision + 1, updated_at = now() WHERE layout ? 'shortcuts'` · `COMMIT` — 머리 주석에 무엇을 · 왜(Q3 B) · 적용 계정(ARCH 10장) · 되돌리는 길 |
| `tests/community/home_front_browser.py`(새) | 수락 시험 ①~⑯ |
| `tests/community/memories_harness.py` | choices 에 `home_front_browser.py` · migrations 목록 **끝**에 새 마이그레이션(두 번 적용) |
| `tests/catalog.json` | `community.home_front_browser` 한 항목(`env: temp-instance` · `writes_prod: false` · `status: required` · `needs: browser`) |
| 옛 시험 7개 | 아래 「기대값을 바꾼 옛 시험」 |

## 수락 시험과 결과

```
cd /home/changkwon/qaredi-qf15s2-20261026
python3 tests/community/memories_harness.py --port 8768 --suite home_front_browser.py --output /home/changkwon/qaredi-qf15s2-results-20261026/accept
```

HEAD `77652f8`(작업 트리 깨끗) · 실행 2026-10-26 · 2분 52초 · 결과 `/home/changkwon/qaredi-qf15s2-results-20261026/accept/`(`home-front-browser-results.json` · `-metrics.json` · `shots/`) · 로그 `accept-final.log`

```
SYNTHETIC_DB_CREATED=1; PRODUCTION_RECORDS_COPIED=0
RESULT pass=275 fail=0
OWNED_TEST_PROCESSES_STOPPED=1; SYNTHETIC_DB_DROPPED=1
```

(275 = fixture 준비 단언 5 + 수락 270. 묶음: [static] ①②③④ · ⑯s / [db] ⑤ 마이그레이션 두 번 `UPDATE 2` → `UPDATE 0` · revision manager 4→5→5 · guest 2→3→3 · admin 3 그대로 · ⑥ 409 / [home] ⑦~⑪ /
[grid] 1440 · 1280 · 412 · 360 × 밝게 · 어둡게 + 820 · 고대비 1440 · 360 · 강제 색 — 대비 최소 밝게 5.55 · 어둡게 7.85 · 고대비 10.89 / [apps] ⑫ 다섯 화면 × 1440 · 412 + 관리자 / [work] ⑬ / [side] ⑭ 네 셸 · 959 · 960 / [join] ⑮ / [words] ⑯.)
조각 확인 중 실행(같은 코드 · 커밋 전): 첫 실행 265/1(가입 응답 본문을 이동 뒤에 읽은 시험 쪽 잘못 — 고침) · 둘째 269/0 · 마지막(커밋 뒤) 275/0.

정적 검사(HEAD `77652f8` 의 작업 트리):

```
$ bash scripts/security_lint.sh                          → 결과: 통과 (금지 패턴 0건)
$ php -l (바꾼 PHP 6)                                    → No syntax errors detected × 6 (Apps.php · member_register.php · ProductSpaces.php · launcher.php · shell_tools.php · shell_top.php)
$ node --check (바꾼 JS 4)                               → OK × 4 (app.js · dashboard-widgets.js · home-dashboard.js · member.js)
$ php scripts/wiki_check.php                             → 결과: 통과 (구조 문제 0건 · 빠진 글 0건, 글 70편)
$ git diff --check 310bbc3 HEAD                          → 0건
public/assets/community/ 변경 0 → community_asset_version 해당 없음
```

마이그레이션을 빈 합성 DB 에 두 번 — 격리 틀이 schema · 기능 마이그레이션을 두 번 돌릴 때 끝에서 두 번 적용(오류 0 · 틀이 멈추지 않음) + 수락 ⑤ 가 구성 셋을 넣고 파일을 다시 두 번(`UPDATE 2` → `UPDATE 0`).

## 기대값을 바꾼 옛 시험 (그 줄만 · 돌리지 않음 — 밤 회귀 대기열은 메인)

| 시험 | 줄 | 전 → 후 |
|---|---|---|
| `qf12_shell_browser.py` | :173 `GRID_STATE_JS` | `.app-menu__apps > li` → `.app-menu__apps > li:not(.app-menu__sep)`(도우미 한 줄) |
| 〃 | :251 | key `tbox · community · story · spaces` → `community · tbox · spaces · story` |
| 〃 | :252 | 이름 `자료실 · 업무 · 이야기 · 우리 공간` · `apps[3]` cafe → `업무 · 자료실 · 우리 공간 · 이야기` · `apps[2]` cafe |
| 〃 | :253 | `apps[2]` memories · `apps[1]` /community/ · `apps[0]` /tbox/ → `apps[3]` · `apps[0]` · `apps[1]` |
| 〃 | :254 | `apps[1]` work · `apps[2]` community → `apps[0]` · `apps[3]` |
| 〃 | :281 `TILES` | `모아 홈 · 자료실 · 업무 · 이야기 · 우리 공간 · 관리자 페이지` → `모아 홈 · 업무 · 자료실 · 우리 공간 · 이야기 · 관리자 페이지`(:287 · :312 는 이 상수를 씀 — 줄 무변경) |
| 〃 | :290 | href `/ · /tbox/ · /community/ · /community/memories · /community/cafe` → `/ · /community/ · /tbox/ · /community/cafe · /community/memories` |
| 〃 | :305 | `aria-expanded="false" aria-label="메뉴 펼치기"` → `aria-expanded="true" aria-label="메뉴 접기"` |
| 〃 | :449 `TILES_EMP` | `모아 홈 · 자료실 · 업무 · 이야기 · 우리 공간` → `모아 홈 · 업무 · 자료실 · 우리 공간 · 이야기`(:474 · :492 는 이 상수 — 줄 무변경) |
| 〃 | :525 | 관리자 타일 목록 같은 순서로 |
| 〃 | :612 | 그대로(「문서 AI」 는 `hidden` 이라 닫힌 더보기 줄 목록에 남음 — `SIDEBAR_JS` :201 이 닫힌 details 안을 hidden 과 무관하게 셈) |
| `qf12_u5_browser.py` | :323 | 기본 `['spaces','work','library','messages','assistant']` → `['work','library','messages','spaces']` |
| 〃 | :354 | 「메신저 앞으로」 전 · 뒤 → `['work','library','messages','spaces']` · `['work','messages','library','spaces']` |
| `home_h1_browser.py` | :36 `DEFAULT` | `['spaces','work','library','messages','assistant']` → `['work','library','messages','spaces']`(:235 · :443 은 상수 — 줄 무변경) |
| 〃 | :238 | `[4, 2] if phone else [6]` → `[4, 1] if phone else [5]` |
| 〃 | :290 | `ap['tiles'][:4] == ['모아 홈','자료실','업무','이야기']` → `['모아 홈','업무','자료실','우리 공간']` |
| 〃 | :423 | `['spaces','work','messages','library','assistant','+']` → `['work','messages','library','spaces','+']` |
| 〃 | :429 | `len == 8 · rows [8]` → `len == 7 · rows [7]`(그 시험은 이야기 · 계정 둘만 켠다 → 넷 + 둘 + ＋) |
| 〃 | :516 | `LAUNCHER_SNAPSHOT_REF = '2afc533'` → `'c2c78d0'`(커밋 `77652f8`) |
| `cafe_c4_browser.py` | :590 | 쿠키 없음 → `data-sidebar="collapsed"` · `false` · 「메뉴 펼치기」 → `data-sidebar` 없음 · `true` · 「메뉴 접기」 |
| 〃 | :596 | 다른 값 → collapsed → `data-sidebar` 없음(값 출력 0 단언은 그대로) |
| 〃 | :629 | `context('W', width, sidebar_open=False)` → `…, extra={'qaredi_sidebar': 'collapsed'}`(접힌 모양 :642 · :658 · :663 · :678 을 쿠키 collapsed 문맥으로 — 단언 무변경) |
| 〃 | :710 | `'qaredi_sidebar' not in ck` → `ck.get('qaredi_sidebar') == 'collapsed'` |
| 〃 | :797 `want_tiles` | `['모아 홈','자료실','업무','이야기']` → `['모아 홈','업무','자료실','이야기']`(지시서 :798 — 실제 줄 797) |
| `qf12_work_browser.py` | :1541 | 접고 다른 화면 뒤 `ck == []` → `ck == ['collapsed']` |
| 〃 | :1574 | 첫 그리기 문맥 `sidebar_open=False` → `+ extra={'qaredi_sidebar': 'collapsed'}`(:1587 단언 그대로) |
| 〃 | +:1590~1602 | 쿠키 없는 문맥 첫 그리기 — 프레임에 `collapsed` 0 · 끝 `data-sidebar` 없음(지시서 「쿠키 없는 문맥은 첫 프레임부터 펼침으로」) |
| `invite_request_browser.py` | :290 | `'내 자료는 누가 보나요?'` → `'내 파일과 글은 누가 보나요?'` |
| `home_h4_browser.py` | :347 | 쿠키 없음 `collapsed · rail 64 · not linkVisible` → `None · 240 · linkVisible`(펼친 머리는 ☰ + 앱 아이콘 링크가 보인다 — :316 펼침 단언과 같은 값) |

grep 으로 더 찾은 단언: 카탈로그 시험 가운데 위 표 밖에서 깨지는 단언은 찾지 못했다(`qaredi_sidebar=open` 을 넣는 시험들은 펼침 그대로 · `/tbox/welcome` · `product-spaces` 를 부르는 시험은 공간 화면 · 시작 안내 화면을 직접 친다).

카탈로그 밖(돌리는 묶음 아님 — 고치지 않음 · 줄만): `home_shortcuts_browser.py:36` · `compact_home_ui.py:62 · :79` · `dashboard_widgets_ui.py:50 · :58-59 · :63 · :75-85 · :56` · `dashboard_inspector_integration.py:52 · :61 · :75-76` · `dashboard_inspector_ui.py:12-14` · `home_dashboard_sync.py:45` · `product_spaces_widgets.php:20 · :26 · :30` · `home_dashboard.py:53` · 원래 깨진 것 `neutral_home_entry.py:53` · `compact_home_ui.py:84` · `qf12_shell_browser.py:262 · :267 · :277`.

## Apps::all() 을 쓰는 다른 자리

- `src/views/shell_top.php:116 · :129` — 지금 앱 줄을 **key 로** 찾아 이름 · 설명 · 아이콘을 읽는다(순서 무관) — 셸 이름 · 브랜드 그대로.
- `src/site/launcher_page.php:34` — 목록을 읽어 모양 검사만 하고(예외 → 500 그대로) 로그인 전엔 비운다. 뷰는 이 값을 쓰지 않는다(`launcher.php` 는 `$shellApps`) — 동작 0.
- `src/views/launcher.php:33` → `shell_tools.php` 앱 판 — 순서 바뀜 + 구분줄(의도).
- `public/assets/app.js:12139`(initMenus) — 판을 열 때 초점이 가는 「지금 앱 아닌 첫 타일」이 순서를 따라 바뀐다(모아 홈 · 자료실에서는 업무, 업무에서는 자료실) — 동작 그대로.
- 그 밖 `apps.json` · `Apps::all()` 소비자 0(grep).

## /api/product-spaces 를 홈이 더 부르는지

- 부르지 않는다. `openDashboard` 의 요청을 뺐다 — 쓰던 곳(공간 위젯 · 공간 추천 · 시작 안내의 「여러 공간에 참여 중」 문장)이 모두 홈에서 빠졌다. 남은 `spaces` 참조는 빈 목록을 읽는 코드(denyHome · 위젯 렌더러 · 공간 위젯 설정 갈래 — 공간 위젯이 홈에 없어 닿지 않음)뿐이다. 창구 · 핸들러 · 공간 화면(`/tbox/spaces`)은 그대로.
- 증거: 수락 ⑪ — 공간 위젯을 저장한 계정의 세션 전체(대시보드 펼침 · 홈 편집 · 위젯 고르기 · 저장)와 공간에 든 직원 세션에서 `/api/product-spaces` 0 · `product-home.js` 0.
- `ProductSpaces::dashboard()` 의 공간 세기 SELECT 는 남겼다(지시서 「SQL 0」) — 그 결과는 이제 개인 기본 구성을 바꾸지 않는다(구성 없는 개인 홈 GET 에 질의 하나가 남음).

## 못 한 것

- ⑮ 2단계 인증 **필수** 갈래(`next` 가 `mfa_setup` → `/tbox/welcome`): **미검증**. 필수 여부가 역할 상수(`Auth::MFA_REQUIRED_ROLES = ['Admin']`) · `mfa_recovery_required` 로 정해지고, 초대 가입은 늘 직원 · 필수 0 이라 이 틀(설정 값)로 켤 수 없다. 코드는 `next` 가 그 밖의 값이면 `/tbox/welcome`(member.js · member_register.php 같은 규칙).
- 옛 시험은 돌리지 않았다(지시서 · CLAUDE.md 3.1 — 밤 회귀).
- 가입 화면 아이디 칸 `pattern="[a-z0-9_.-]{3,64}"`(`member_join_page.php:16` — 이 단계 밖 · 손대지 않음)이 글자를 넣으면 Chromium 에서 「v 깃발로 올바른 정규식이 아님」 콘솔 경고 한 줄을 낸다(main 에도 있는 것). 수락 ⑮ 는 그 한 줄만 따로 세어 결과에 남기고 나머지 콘솔 0 을 단언했다 — 고칠지는 메인 판단.
- 되돌릴 수 없는 것(지시서 그대로): **Q3 B** — 운영의 저장된 개인 홈 바로가기 구성은 반영 때 지워진다. 되돌리기는 B 「반영 순서」 5 대로 따로 남긴 목록에서 그 칸만 — 사용자가 정할 때만. 반영 뒤 사용자가 새로 저장한 바로가기는 되돌리면 덮인다. **사이드바** — 지금 「쿠키 없음 = 접힘」으로 쓰던 사람은 바뀐 날 한 번 펼친 모양을 본다(다시 접으면 기억).

## DESIGN · 시안 · 지시서와 다르게 한 것

- 화면: DESIGN 2.31 「과업 카드」 · 2.30 「앱 판」 행대로 했고 시안은 볼 수 없어 DESIGN 을 따랐다(다른 점 없음으로 안다 — 스크린샷 확인 부탁).
- `cafe_c4_browser.py` :690 · :698 · :701 · :703: 지시서 표는 「첫 ☰ 는 접기」 흐름(쿠키 없는 문맥)이지만, 그 흐름이면 표에 없는 :694(첫 ☰ 뒤 「＋ 새로 만들기」 글자가 보인다 — 펼침 단언)가 깨진다. 그래서 접힌 모양 문맥(쿠키 collapsed)에서 ☰ 차례를 그대로 이어 **첫 ☰ = 펼침(쿠키 open) → 다시 ☰ = 접힘(쿠키 collapsed — 지우지 않음)** 으로 두었다: :690 · :698 · :701 · :703 무변경, :710 만 `collapsed`. 쿠키 없는 첫 방문의 브라우저 흐름(펼침 → 접기 collapsed → 새로 고침 → 펼치기 open)은 새 수락 ⑭ 가 네 셸에서 잰다.
- `home_h4_browser.py:347`: 표의 「collapsed · 64 → 펼침 · 240」에 더해 같은 줄의 `not linkVisible` 을 `linkVisible` 로(펼친 머리의 아이콘 링크는 보인다).
- `ProductSpaces::defaultWidgetLayout`: 개인 갈래 셋(`$space===null` · company · group)을 `else` 한 줄로 합쳤다(닿지 않는 두 줄을 남기면 문법상 `else` 뒤 `elseif` 가 안 된다). 공간 홈(`$shared`) 줄 · SQL 무변경.
- 앱 판 구분줄 강제 색 모드: `background: CanvasText`(시스템 색 낱말 — 새 색값 아님. 강제 색에서 바탕이 지워져 1px 바탕 줄이 사라지므로).
- 홈 위젯 안 서비스 카드 2열: 새 줄을 더하지 않고 기존 두 선택자에서 `:not(.home-widget--wide)` 를 뺐다(캔버스 모드는 `dashboard-canvas.css` 의 auto-fit 이 이미 두 카드를 채운다 — 수락 ⑪ 에서 잼).

## 스크린샷 (`/home/changkwon/qaredi-qf15s2-results-20261026/accept/shots/`)

- 홈 1440 밝게 접힘 `home-1440-light-collapsed.png` · 펼침 `home-1440-light-open.png` · 412 어둡게 펼침 `home-412-dark-open.png`
- 손님 1440 펼침 `home-guest-1440-open.png` · 관리자 1440 펼침 `home-admin-1440-open.png`
- 앱 판 1440 관리자 `apps-1440-admin.png`
- 업무 첫 방문 1280 `work-first-visit-1280.png`
- 가입 직후 1280 `join-after-1280.png`
- 대시보드 펼침 `home-1440-light-dashboard-open.png` · 위젯 고르기 창 `home-1440-widget-picker.png`
