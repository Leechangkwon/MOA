# 지시서 Q:F-15 S2 — 앞세우기 · 접기 (2026-10-26)

사용자(2026-10-24): 「기획안의 권고대로 D-0~D-12 모두 진행해」 → 1단계 낱말 · 브랜드(v2.74.0 · v2.74.1 운영) → (2026-10-25) 「2단계 앞세우기·접기의 홈 시안을 만들어 줘」 → (2026-10-26) 「확인할 것」 답 **Q1 B · Q2 A · Q3 B · Q4 A · Q5 A** · 「**2단계 홈 시안 v4 승인(D-3). 지시서 작성으로 넘어가도 돼**」.
기준 문서(**정답지**): 기획 `../docs/identity-plan-2026-10-24.md` **7.4**(앞세우기 · 뒤로 표) · **9.1**(D-3 · D-4 · D-5 · D-6 · D-8 · D-9) · **9.2**(Q1~Q5 답) · `DESIGN.md` **2.31**(바로가기 기본 · 새 행 「과업 카드」 · 새 행 「대시보드에서 숨김」) · **2.30**(앱 판 순서 · 구분줄) · **2.19 ⑧ ⑨**(문서 AI 줄 · 첫 방문 펼침) · 5장 낱말 표 · 6장 체크리스트 · `/var/www/qaredi/CLAUDE.md` 2 · 3 · 4장(3.1 — 수락 시험만).
보이는 모양의 기준: 승인 시안 v4(비공개 캔버스) https://claude.ai/artifact/U5T3UeiwWB8965qMX9zqWf — 판 이름 「바뀐 뒤 …」 · 「관리자 / 손님 — 펼친 모양」 · 「처음 모양 — 접힘」 · 「앱 판 — 바뀐 뒤」 · 「업무 첫 방문 — 바뀐 뒤」. 시안과 DESIGN 이 다르면 **DESIGN 이 정답**이고 보고서에 적는다.
한 줄로: **지우지 않고 앞에서 치운다.** 바로가기 넷 · 홈 과업 카드 · 앱 판 순서와 이름 없는 구분줄 · 대시보드 카드 · 위젯 숨김 · 문서 AI 줄 숨김 · 가입 직후 모아 홈 · 사이드바 첫 방문 펼침 · 「자료」 단독 글자 열(Q5 둘 + I 여덟). 라우트 · 창구 · 판정 · 표 구조 · 주소 · 기능 삭제 0.

| 항목 | 내용 |
|---|---|
| 구현 자리 | 격리 worktree **`/home/changkwon/qaredi-qf15s2-20261026`**(브랜치 `feat/qf15-s2-front` — 메인이 만든다) · 결과 **`/home/changkwon/qaredi-qf15s2-results-20261026/`** · 운영 · 운영 DB 읽기 · 쓰기 0 |
| 격리 틀 | `python3 tests/community/memories_harness.py --port 8768 --suite home_front_browser.py --output <결과>/accept` — 시작 전 `ss -ltn` 로 8768 · 8769 가 비었는지(쓰이면 8778) · 끊으면 내 것만 치움 |
| 커밋 | 파일 지정(`git add -A` 금지) · 첫 줄 `모아 Q:F-15 S2: …` · 끝 `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>` · push · 발행 · 합치기 0(메인이 한다) |
| 줄 번호 | 아래 줄 번호는 2026-10-26 main(`545acc6`) 기준 **참고**다. 고칠 자리는 **지금 글자 · 선택자**로 찾는다 |

```
목표        : 로그인 뒤 모아 홈 · 앱 판 · 업무 사이드바 · 가입 직후 길에서 처음 온 동료가 「이게 뭐야?」 할 입구를 앞에서 치우고,
              바로가기 아래에 「무엇을 하고 싶으세요?」 과업 카드(기본 접힘 · 펼치면 기억 · 자격 값으로 가린 링크 2~6줄)를 둔다.
              저장된 개인 홈 구성의 상단 바로가기는 반영 때 한 번 지워 모두가 새 기본 넷을 본다(Q3 B — 데이터 마이그레이션 하나).
요구사항    : 기획 7.4 · 9.1 · 9.2 · DESIGN 2.31 · 2.30 · 2.19 ⑧⑨ · 5장 · 6장 — 읽고 시작할 것.
              새 클래스는 DESIGN 이 정한 셋만(.home-tasks · .home-tasks__list · .app-menu__sep) · 새 색값 0(토큰만) · 새 쿠키는 qaredi_home_tasks 하나.
수정 파일   : src/views/launcher.php · src/views/shell_tools.php · src/views/shell_top.php · src/Apps.php · config/apps.json ·
              src/site/ProductSpaces.php(개인 홈 기본 구성 한 줄 — 판정 · SQL 0) · src/handlers/member_register.php(응답 redirect 값 한 줄) ·
              public/assets/home-dashboard.js · public/assets/dashboard-widgets.js · public/assets/member.js · public/assets/app.js(사이드바 쿠키) ·
              public/assets/moa.css(.home-tasks) · public/assets/app.css(.app-menu__sep)
              보안 핵심: **db/migrations/2026-10-26_qf15_s2_shortcuts.sql 새 파일 하나만**(데이터만 — DDL · 권한 · 다른 표 0)
              새 파일: tests/community/home_front_browser.py(새 수락) · docs/qf15-s2-report.md(보고)
              시험 등록: tests/community/memories_harness.py(choices 에 home_front_browser.py · migrations 목록 끝에 새 마이그레이션) · tests/catalog.json(항목 하나)
              바뀐 동작을 단언하던 옛 시험: 「기대값만 바꿀 옛 시험」 표의 그 줄만(다른 단언 0 · 그 시험은 돌리지 않는다 — 밤 회귀) ·
              표에 적은 도우미 한 줄(qf12_shell_browser GRID_STATE_JS) · 문맥 옮김(cafe_c4 접힌 모양 · qf12_work 첫 그리기) · 기준 판 상수(home_h1 :516) 만 예외
금지 패턴   : 라우트 · 창구 · 판정 함수(Authz) · 표 구조 · 권한 블록 · 주소 바꾸기 · 지우기(「숨김」은 hidden 속성이나 그리지 않기 — 줄 · 파일 · 창구 · 저장값은 남긴다,
              Q3 B 의 shortcuts 하나만 예외) · 역할 이름 비교(화면이 role 을 보고 가리기) · 요청 값으로 자격 정하기 · innerHTML · 인라인 style · 새 색값 ·
              DESIGN 에 없는 클래스 · usage-guide.js · `?guide=` 꼬리 · 질문 카드(3단계) · src/Authz.php · src/Auth.php · public/index.php · deploy/** ·
              db/schema.sql · db/grants.sql · git add -A.
완료 기준   : home_front_browser.py(격리 · 아래 「수락 시험」) 전부 통과 ·
              정적: scripts/security_lint.sh 0 · php -l(바꾼 PHP 전부) · node --check(바꾼 JS 전부) · php scripts/wiki_check.php 0 · git diff --check ·
                    마이그레이션을 빈 합성 DB 에 두 번 돌려 오류 0(격리 틀이 이미 두 번 돈다)
보고 형식   : docs/qf15-s2-report.md — 변경 파일 / 수락 시험과 결과 / 기대값을 바꾼 옛 시험(파일 · 줄 · 전 → 후) / Apps::all() 을 쓰는 다른 자리 확인 결과 /
              /api/product-spaces 를 홈이 더 부르는지 확인 결과 / 못 한 것 / DESIGN · 시안과 다르게 한 것 / 스크린샷 경로. 짧게.
```

## 바꿀 것

### A. 홈 바로가기 기본 넷 (D-4 · DESIGN 2.31 ③)

| 파일(참고 줄) | 지금 | 바꾼 뒤 |
|---|---|---|
| `public/assets/home-dashboard.js:54` `shortcutDefaults` | `['spaces','work','library','messages','assistant']` | `['work','library','messages','spaces']` |
| 같은 줄 `shortcutIds`(홈 편집 「상단 바로가기」 설정 목록 순서) | `['spaces','work','tasks','library','story','messages','assistant','account']` | `['work','library','messages','spaces','story','assistant','account','tasks']` — 값 · 허용 목록(`ProductSpaces.php` `$allowed`)은 그대로, 순서만 |
| `src/views/launcher.php:24` `$homeShortcuts`(틀 — 그리는 순서와 무관) | spaces · work · library · story · messages · assistant · account | 위와 같은 순서로(읽기 쉽게 — 동작 0) |

### B. 저장된 바로가기 한 번 지우기 (Q3 B — 데이터 마이그레이션 · 보안 핵심 `db/**`)

새 파일 `db/migrations/2026-10-26_qf15_s2_shortcuts.sql`:

- 하는 일 하나: `public.product_personal_dashboards` 에서 `layout ? 'shortcuts'` 인 행만 `layout = layout - 'shortcuts'` · `revision = revision + 1` · `updated_at = now()`. **지운 뒤 화면은 기본값(A)을 그린다** — 「저장한 적 없는 계정」과 같은 상태가 된다.
- `revision + 1` 은 이유가 있다: 반영 순간 홈 편집을 열어 둔 탭이 옛 바로가기로 덮어쓰지 못하고 409(`revision_conflict`)를 받게 한다(`ProductSpaces::dashboardSave`).
- `BEGIN` · `SET LOCAL lock_timeout = '3s'` · `COMMIT` 한 묶음 · **두 번 돌려도 같다**(두 번째는 바뀌는 행 0) · 다른 키 · 다른 표(`product_space_dashboards` 포함) · DDL · 권한 0 · 머리 주석에 무엇을 · 왜(Q3 B) · 되돌리는 길(반영 전 백업에서 그 칸만 — 사용자 판단).
- 적용 계정은 `ARCHITECTURE.md` 10장 끝 「마이그레이션 적용 계정」 규칙 그대로(지금 마이그레이션들과 같은 계정). 판단이 서지 않으면 멈추고 보고.
- `tests/community/memories_harness.py` 의 migrations 목록 **끝에** 이 파일을 더한다(빈 합성 DB 에서 두 번 — 오류 0 확인용).
- **반영 순서(메인 — 운영 저장값을 바꾸는 반영이므로 이 순서를 건너뛰지 않는다 · 구현 에이전트는 운영 DB 0)**:
  1. **반영 전 백업** — 반영 전 백업 규칙대로 `/var/backups/tbox-v2/<묶음>-<날짜>` 에 DB 덤프 + 코드 · 설정(암호화). 그 백업이 끝났는지(크기 · 복호 시험 한 번) 확인하기 전에는 마이그레이션 0.
  2. **바뀔 행 따로 남기기** — 같은 백업 폴더에 운영 읽기 전용으로 `SELECT user_id, layout->'shortcuts' AS shortcuts, revision FROM public.product_personal_dashboards WHERE layout ? 'shortcuts' ORDER BY user_id` 결과를 파일 하나로(같이 암호화). 이 줄 수가 **바뀔 행 수**다 — 보고 · SECURITY 7장에 수만 적는다(아이디 목록은 적지 않는다).
  3. **모의 적용** — 1 의 덤프를 복원한 임시 DB(운영 아님 · 이름 `tbox_s2_mock_<날짜>` 처럼 따로)에 이 파일을 두 번 적용: 첫 번째 바뀐 행 수 = 2 의 줄 수 · 두 번째 0 · `shortcuts` 남은 행 0 · 다른 칸(`widgets` · `source` · `view` · `guide`) 무변경을 `jsonb` 비교로 확인 → 임시 DB 지움.
  4. **적용** — 코드 반영과 같은 창에서 이 파일 한 번(ARCH 10장 계정) → 운영 읽기 전용으로 `shortcuts` 남은 행 0 확인 → 운영 확인(바로가기를 저장해 두었던 claude_ 계정이 있으면 그 계정으로 새 기본 넷이 보이는지).
  5. **되돌리는 법(사용자가 정할 때만 — 운영 데이터 규칙상 메인이 스스로 하지 않는다)**: 2 의 파일로 그 행들의 `layout` 에 `shortcuts` 만 다시 넣고 `revision + 1`(다른 칸은 그 사이 사용자가 바꿨을 수 있으니 건드리지 않는다). 2 의 파일이 없으면 1 의 덤프에서 그 칸만 꺼낸다. 전체 복원은 하지 않는다.

### C. 홈 과업 카드 「무엇을 하고 싶으세요?」 (D-3 · Q1 B · Q2 A · DESIGN 2.31 「과업 카드」 행)

`src/views/launcher.php` 의 `$launcherLive` 갈래, `section.home-hero` 안 바로가기 틀(`template#home-shortcut-template`) **뒤**에 `details#home-tasks.home-tasks`. 모양 · 글자 · 줄 · 순서는 DESIGN 2.31 「과업 카드」 행 그대로.

- **기본 접힘**(Q1 B). 펼침 기억 = 쿠키 `qaredi_home_tasks` — 값이 정확히 `open` 일 때만 서버가 `<details … open>` 을 그린다(첫 HTML 부터 · 깜빡임 0 · 요청 값은 그 비교 하나에만 · 출력에 쿠키 값을 쓰지 않는다). 쓰기는 `home-dashboard.js` 가 `#home-tasks` 의 `toggle` 에서: 펼침 → `qaredi_home_tasks=open; Path=/; Max-Age=31536000; SameSite=Lax`(+ https 면 `; Secure`) · 접힘 → 같은 이름 `Max-Age=0`. 사이드바 쿠키(`app.js initSidebarCollapse`)와 같은 모양으로. 스크립트가 없어도 `<details>` 는 열리고 닫힌다(기억만 빠짐).
- **줄은 서버가 판정 함수 결과로만** 그린다(`$launcherLive` 블록에서 한 번 계산 · 이스케이프 한 번 · 새 요청 0 · 역할 이름 비교 0):

| # | 머리(가는 곳) | 글자 | 보이는 조건(판정) | 링크 | `data-service` |
|---|---|---|---|---|---|
| ① | 업무 홈 | 맡은 일 보기 · 할 일 적기 / (거짓이면) 맡은 일 보기 | 늘 — 글자만 `Authz::canCommunityWrite($launcherUser)` 로 | `Http::communityUrl('/')` | `work` |
| ② | 자료실 | 파일 올려서 동료에게 보여 주기 / (비면) 파일 찾기 | 늘 — 글자만 `Authz::uploadableLevels($launcherUser) !== []` 로 | `Http::url('/')` | `library` |
| ③ | 업무 홈 › 문서 | 회의록 쓰기 | `Authz::canCommunityWrite` | `Http::communityUrl('/workspace')` | `work` |
| ④ | 업무 홈 › 게시판 | 공지 올리기 | `Authz::canCommunityWrite` | `Http::communityUrl('/')` | `work` |
| ⑤ | 메신저 | 동료에게 한마디 | `$launcherMessenger`(지금 값 그대로) | `Http::url('/messages')` + 바로가기와 같은 `data-messenger-open aria-haspopup="dialog" aria-controls="messenger-window" aria-expanded="false"` | 없음 |
| ⑥ | 계정 관리 | 동료 초대하기 | `Authz::canManageUsers($launcherUser)` | `Http::url('/users')`(재인증 관문 그대로) | 없음 |

  직원 다섯 · 관리자 여섯 · 손님 둘(①② 읽기 글자). 끝의 「↗」 는 `aria-hidden`. 링크에 `?guide=` 꼬리를 붙이지 않는다(3단계).
- CSS `public/assets/moa.css` 의 홈 블록(2.31 새 클래스 주석 근처)에 `.home-tasks` · `.home-tasks__list` — 토큰만 · 2열(≥720 · 열 사이 28) / 1열(<720) · 줄 글자 색 `var(--service-color, var(--primary))` · 고대비(2.16)에서 `[data-service]` 가 `--fg` 로 바뀌는 지금 규칙을 그대로 받는지 확인 · `forced-colors` 에서 테두리 보임.
- 1440 × 900 · 밝게 · 카드 접힘에서 「내 대시보드 ▾」 단추가 첫 화면 안(시안 「바뀐 뒤 · 1440」과 같은 자리).

### D. 앱 판 순서 · 이름 없는 구분줄 (D-4 · DESIGN 2.30 「앱 판」 행)

- `config/apps.json`: `order` — 업무(`community`) 1 · 자료실(`tbox`) 2 · 우리 공간(`spaces`) 3 · 이야기(`story`) 4, 이야기에 `"group": "more"`. 이름 · 설명 · 주소 · 아이콘 그대로.
- `src/Apps.php`: `OPTIONAL` 에 `group` · 값은 `'more'` 하나만 허용(다르면 지금처럼 예외 — 조용히 넘기지 않는다). 주석의 반환 모양에 `group?:string`.
- `src/views/shell_tools.php`: 앱 줄을 그리는 반복에서 `group === 'more'` 인 **첫** 앱 앞에(그 앞에 앱이 하나라도 있을 때만) `<li class="app-menu__sep" role="separator"></li>` 한 번. 관리자 페이지 타일은 지금처럼 반복 뒤(= 구분줄 아래 이야기 다음). 글자 0.
- `public/assets/app.css` 의 앱 판 블록에 `.app-menu__apps > li.app-menu__sep` — 격자 한 줄 전체(`grid-column: 1 / -1`) · 높이 1 · 바탕 `--line` · 위아래 6 · `li` 의 `display:flex` 를 이기게.
- `Apps::all()` 을 쓰는 다른 자리(`shell_top.php` 지금 앱 찾기 · `src/site/launcher_page.php`)는 순서만 바뀐다 — 동작이 달라지지 않는지 확인해 보고서에.
- 구분줄은 반드시 **class 가 붙은 `li`** 로(`<li class="app-menu__sep" role="separator"></li>`) — 타일 `li` 는 지금처럼 속성 없는 `<li>`. 두 번째 `ul.app-menu__apps` 로 나누지 않는다(옛 시험 `grid_tiles()` 가 첫 `ul` 만 읽는다). 옛 시험 `qf12_shell_browser.py` 의 `GRID_STATE_JS` 한 줄은 아래 표대로 고친다.

### E. 대시보드에서 숨김 · 글자 (7.4 · D-5 · Q5 A · DESIGN 2.31 「대시보드에서 숨김」 행)

| 무엇 | 파일 | 바꾸는 법 |
|---|---|---|
| 서비스 카드 「PC 비서」 · 「이야기」 | `launcher.php:71-72`(`a.moa-service-card[data-service="assistant"]` · `[data-service="story"]`) | `$launcherLive` 일 때만 `hidden` 속성(줄은 남김 — 같은 줄을 그리는 동의 전 갈래는 그대로 넷) — 보이는 카드 업무 홈 · 자료실 둘. 홈 위젯 안에서는 넓은 위젯이어도 **2열**로 두 카드가 칸을 꽉 채운다(`moa.css:377` 의 4열은 홈 위젯 밖 그대로 · 600 미만 1열 그대로) |
| 「필요한 자료를 함께 써요」(Q5 A) | `launcher.php:70` 자료실 카드 h3 | 「팀 파일을 함께 써요」 |
| 「내 공간의 최근 활동」 | `launcher.php:74` `section#product-home` | `hidden` · `home-dashboard.js` 가 `loadScript('productScript')` 를 부르지 않는다(파일 · 창구 그대로) |
| 회사 · 모임 공간 위젯 | `home-dashboard.js` | 타입이 `space_` 로 시작하는 위젯은 그리지 않는다(저장 구성에서는 **지우지 않는다** — 홈 편집 저장 뒤에도 서버 구성에 남아야 한다) · 위젯 추가 고르기 목록에서 `space_*` 다섯 모두 뺀다(지금은 `space_file` · `space_story` 둘만 뺌) · `recommendation()` 은 공간이 있어도 `['recent','favorites','briefing']` |
| 개인 홈 기본 구성(서버) | `src/site/ProductSpaces.php:170-174` `defaultWidgetLayout` | `$shared` 가 거짓이면 공간 수와 무관하게 `['recent','favorites','briefing']` — 공간 홈(공유 대시보드) 기본은 그대로 · 공간을 세는 SELECT 가 더 쓰이지 않으면 남겨도 되나 보고 |
| 「모아 홈 시작 안내」의 공간 문장 · 링크 | `home-dashboard.js:51` `renderGuide` | 「여러 공간에 참여 중입니다 …」 · 「공간에 참여하고, 첫 자료를 올리고, …」 → **「첫 파일을 올리고, 필요한 위젯을 골라 보세요.」** 한 문장 · 링크 「공간 보기」(`/tbox/spaces`) → **「우리 공간」**(`/community/cafe`) · 「첫 자료 올리기」 → **「첫 파일 올리기」**(올릴 등급이 있을 때 — 지금 조건 그대로) · 「나중에」 · 「안내 완료」 그대로 |
| 위젯 이름 「최근 자료」(Q5 A) | `dashboard-widgets.js:4` `titles.recent` | 「최근 파일 · 문서」 |
| 공간 목록 요청 | `home-dashboard.js:62` `openDashboard` 의 `/api/product-spaces` | 위 바꿈 뒤 쓰는 곳이 남지 않으면 부르지 않는다 · 남으면 그대로 두고 어디서 쓰는지 보고(창구 그대로) |

맨 아래 「읽을거리 · 게시판 새 글」(D-5)은 자리 · 접힘 · 글자 그대로다(읽을거리를 소유자 글로 채우는 것은 운영 일 — 코드 0).

### F. 업무 사이드바 「더보기」의 「문서 AI」 줄 (D-6 · DESIGN 2.19 ⑧)

`src/views/shell_top.php:680` 의 `a.nav__item[href$="/ai"]` 에 `hidden`(줄 남김). 관리자 설정 · `/community/ai` 주소 · 도움말 그대로. 파일 머리 주석(:670 「자동 작업 · 내 제안 · 공유·연동 설정 · 문서 AI · 내 휴지통」)을 맞춘다.

### G. 가입 직후 모아 홈 (D-8)

- `public/assets/member.js:46`: `registered.next === 'login'` → `/tbox/login`(그대로) · `registered.next` 가 그 밖의 값(`password_change` · `mfa_setup` — 그 단계를 시작 안내 화면이 맡는다) → `/tbox/welcome`(그대로) · **값이 없으면(null) → 사이트 첫 화면 `/`**(`base + '/'` 가 아니다 — `base` 는 `/tbox`).
- `src/handlers/member_register.php:52`: 응답의 `redirect` 를 같은 규칙으로(값이 없을 때 `/`) — 화면이 이 값을 쓰지 않아도 어긋나지 않게. 판정 · 세션 · 감사 · 가입 검사 0.
- 시작 안내 링크는 이미 내 계정에 있다(`settings_page.php:60` 「시작 안내 · OTP · PC 비서 연결 →」) — 그대로.

### H. 사이드바 첫 방문 펼침 (D-9 · Q4 A · DESIGN 2.19 ⑨ 2026-10-26 줄)

- `src/views/shell_top.php:322`: `$shellSidebarOpen` = 쿠키 `qaredi_sidebar` 가 정확히 `collapsed` **가 아니면** 참(없음 · `open` · 다른 값 → 펼침). 머리 주석(:318) · :450 주석을 맞춘다.
- `public/assets/app.js` `initSidebarCollapse`: 접을 때 `qaredi_sidebar=collapsed`(1년) · 펼 때 `qaredi_sidebar=open`(1년) — 지우지 않는다 · 같은 속성(`Path=/; SameSite=Lax` · https 면 `Secure`) · 파일 머리 주석(:64) · 함수 주석(:12205)을 맞춘다. 옛 localStorage 키 지우기는 그대로.
- 모든 셸(업무 · 자료실 · 이야기 · 우리 공간)이 같은 값을 읽는다(Q4 A). 폰(<960) 서랍 그대로.

### I. 「자료」 단독 여덟 자리 (사용자 2026-10-26 「「자료」 여덟 자리도 제안대로 2단계에 넣고, 2단계 구현 진행해」 · DESIGN 5장 「파일 앱과 그 안의 것」)

같은 규칙(「자료」 단독 0 — 슬로건만 예외)이 걸리는 홈 대시보드 · 홈 편집 창 · 로그인 전 첫 화면 글자다. 아래 글자 **그대로** 바꾼다(사람에게 보이는 글자만 — 값 · 키 · 변수 0).

| 자리(참고 줄) | 지금 | 바꾼 뒤 |
|---|---|---|
| `dashboard-widgets.js:52` 최근 위젯 빈 글 | 최근 30일 동안 연 자료가 없습니다. | 최근 30일 동안 연 파일 · 문서가 없습니다. |
| 같은 줄 즐겨찾기 빈 글 | 자료나 문서를 즐겨찾기에 담아 보세요. | 파일이나 문서를 즐겨찾기에 담아 보세요. |
| `dashboard-widgets.js:57` 위젯 설명(홈 편집 창) | 최근 사용한 자료와 문서 · … / 즐겨찾기에 담은 자료와 문서 · … | 최근 사용한 파일과 문서 · … / 즐겨찾기에 담은 파일과 문서 · …(「 · 소 최대 …」 뒤는 그대로) |
| `dashboard-widgets.js:32` 오류 글 | 모든 자료 출처를 불러오지 못했습니다. | 모든 출처를 불러오지 못했습니다. |
| `dashboard-widgets.js:48` 줄 표시 | 민감 자료 | 민감 파일 |
| `home-dashboard.js:42 · :46` 홈 편집 고르개 | 최근 작업 자료 선택 · 자료 출처 · 자료와 문서 | 최근 작업에 보일 것 · 출처 · 파일과 문서 |
| `launcher.php:63` 로그인 전 자주 묻는 질문(DESIGN 2.32 ⑤) | 내 자료는 누가 보나요? | 내 파일과 글은 누가 보나요? |
| `launcher.php:84` 로그인 전 미리보기 | 함께 쓰는 글과 자료는 로그인 후에 볼 수 있어요. | 함께 쓰는 글과 파일은 로그인 후에 볼 수 있어요. |

`home-dashboard.js` 고르개 글자는 `aria-label` 로도 쓰인다(`selectField` 의 첫 인자) — 같은 글자로 바뀌는 것이 맞다. 공간 위젯 전용 글자(「공간 자료」 · 「연결된 자료가 없습니다.」 · 「선택한 공간의 자료」)는 E 에서 그 위젯이 화면에서 빠지므로 남긴다.

## 바꾸지 않는 것 (이 단계 밖 — 손대지 않는다)

- 3단계 길잡이 전부: `?guide=` · 질문 카드 · 「하던 일 이어 하기」 · `usage-guide.js` · 새 표 `community.guide_journeys`.
- 자료실 사이드바 「PC 비서 받기」 · 내 계정 「연결(선택)」 묶음(D-7 — 4단계) · 「업무 홈과 내 작업 합치기」 · 「페이지 → 문서」 · 이야기 공개 범위 낱말.
- 로그인 전 · 동의 전 런처 갈래(「어디서 시작할지 고민이라면」 줄 포함) · 바닥 · 오른쪽 위 셋 · 검색 칸 · 「모아.」 글자 로고 · 「내 대시보드」 접힘 · 홈 편집.
- 공간 홈(공유 대시보드 `product_space_dashboards`) 기본 구성 · 공간 화면 · 창구 · 초대 · 이야기 · 문서 AI · PC 비서의 기능 · 주소.
- 기술 식별자: 쿠키 `qaredi_sidebar`(이름 그대로 — 값만 하나 늘어남) · 바로가기 id(`assistant` 등) · 앱 key · 경로 · 표 이름.

## 수락 시험 — `tests/community/home_front_browser.py`(새 · 격리 · claude_ 계정 · UA `tbox-review/<날짜>`)

```
[static]  (파일만 읽음 · 서버 0)
  ① home-dashboard.js 의 shortcutDefaults 가 정확히 ['work','library','messages','spaces'] · shortcutIds 의 값 집합이 ProductSpaces.php $allowed 와 같다
  ② php -r 로 Apps::all() — key 순서 community · tbox · spaces · story · story 만 group 'more' · 임시 사본에 group 'x' 를 넣으면 예외(원본 무변경)
  ③ 새 마이그레이션: BEGIN/COMMIT 한 묶음 · UPDATE 대상이 product_personal_dashboards 하나 · DDL · GRANT · DELETE · TRUNCATE 0
  ④ 바뀐 CSS 줄에 #색값 0 · 바뀐 PHP/JS 줄에 innerHTML · style= · 역할 이름 문자열('Admin' 'Guest' …) 비교 0
[db]      (격리 틀의 임시 DB)
  ⑤ 구성 셋을 넣는다 — shortcuts 있음 둘(하나는 ['assistant','story']) · 없음 하나 → 마이그레이션 파일을 두 번 돌림 →
     shortcuts 있는 행 0 · 그 둘의 revision 은 정확히 +1(두 번째 실행에서 그대로) · widgets · source · view · guide 무변경 · 없던 행 revision 그대로
  ⑥ ⑤ 뒤 그 계정으로 홈을 열면 바로가기 업무 홈 · 자료실 · 메신저 · 우리 공간 · ＋ — 마이그레이션 전 열어 둔 구성(옛 revision)으로 PATCH 하면 409
[pages]   (php -S 격리 · 1440 · 1280 · 412 · 360 × 밝게 · 어둡게 · 고대비 한 번 · 직원 · 관리자 · 손님 · 역할로 보기 한 번)
  홈 `/`
  ⑦ 바로가기: 저장 구성 없는 계정 = 업무 홈 · 자료실 · 메신저 · 우리 공간 · ＋ 바로가기 · 저장 구성(shortcuts)이 있는 계정은 그 구성 그대로
  ⑧ #home-tasks: 쿠키 없음 → 응답 HTML 에 open 0 · summary 「무엇을 하고 싶으세요?」 · 「펼치기 ▾」 · 펼치면 쿠키 qaredi_home_tasks=open(Path=/ · SameSite=Lax) →
     새로 고침 응답 HTML 에 open(스크립트 끈 요청으로도 확인) → 접으면 쿠키 사라짐 → 다시 open 0 · 쿠키 값이 open 이 아니면(예: <script>) open 0 · 응답에 그 값 0
  ⑨ 줄: 직원 = 표 ①~⑤ 글자 · 순서 · href · data-service 그대로 · 관리자 = ⑥ 더해 여섯(/tbox/users) · 손님 = 「맡은 일 보기」(/community/) · 「파일 찾기」(/tbox/) 둘 ·
     손님 문맥에 「할 일 적기」 「올려서」 「회의록」 「공지」 「한마디」 「초대」 0 · ⑤ 를 누르면 #messenger-window 가 열린다 · 메신저 자격이 없는 계정은 ⑤ 0
  ⑩ 1440×900 밝게 · 카드 접힘: 「내 대시보드」 단추 아래 끝 ≤ 900 · 카드 접힘에서 1440×900 · 1920×1080 · 412×900 홈이 짧은 화면(바닥이 창 맨 아래 — home_h4 [foot] 기준) ·
     카드 열고 닫기에 네트워크 요청 0 · 홈 첫 로드(대시보드 접힘) 요청 목록에 새 주소 0
  ⑪ 대시보드 펼침: 보이는 서비스 카드 = 업무 홈 · 자료실 · 「팀 파일을 함께 써요」 · #product-home 보이지 않음 · product-home.js 요청 0 ·
     위젯 추가 목록에 space_* 0 · 위젯 이름 「최근 파일 · 문서」 · 시작 안내에 「공간」 낱말 0 · 링크 「우리 공간」 → /community/cafe ·
     space_* 위젯이 저장된 계정: 보이는 위젯에 0 → 홈 편집 → 저장 → GET /tbox/api/me/home-dashboard 의 widgets 에 그 위젯 그대로 ·
     공간 하나에 든 새 계정(구성 없음)의 기본 위젯 = recent · favorites · briefing
  앱 판 (홈 · /tbox/ · /community/ · /community/cafe · /community/memories × 1440 · 412)
  ⑫ 타일 순서 모아 홈 · 업무 · 자료실 · 우리 공간 · [li.app-menu__sep role=separator 하나 · 글자 0] · 이야기 · (관리자만) 관리자 페이지 · aria-current 는 지금 앱 하나 그대로
  업무 사이드바
  ⑬ 「더보기」를 펼쳐도 「문서 AI」 줄이 보이지 않는다 · /community/ai 응답 상태가 고치기 전과 같다
  사이드바 (≥960 · 업무 · 자료실 · 이야기 · 카페)
  ⑭ 쿠키 없음 → 응답 HTML 에 data-sidebar 0(펼침 · 폭 240) · ☰ → 접힘 · 쿠키 collapsed → 새로 고침 응답 HTML 에 data-sidebar="collapsed" ·
     ☰ → 펼침 · 쿠키 open · 옛 쿠키 open → 펼침 · 959 이하 서랍 그대로
  가입
  ⑮ 시험 초대로 /tbox/join 가입: 2단계 인증이 필수가 아닌 설정 → 가입 뒤 주소 `/`(모아 홈 · 카드 접힘) · /api/register 응답 redirect 가 '/' ·
     2단계 필수 설정이 틀에서 가능하면 → /tbox/welcome 그대로(불가능하면 「미검증」으로 보고)
  「자료」 단독 (I · Q5)
  ⑯ 홈(대시보드 펼침 · 홈 편집 창 열림 · 위젯 고르기 창 열림) · 로그인 전 첫 화면(자주 묻는 질문 · 읽을거리 펼침)의 body innerText · [aria-label] 에
     「자료」 뒤에 「실」이 오지 않는 곳 0 — 예외: 슬로건 「우리 회사의 일과 자료를 한곳에 모아.」 · 공간 위젯 전용 글자(E 로 화면에 없음) · I 와 Q5 의 새 글자 열이 그대로 보인다
  공통: 넘침 0(360 · 412 · 1280 · 1440) · 누름 자리 44(카드 줄 · summary · 구분줄은 누름 자리 아님) · 대비 4.5(카드 머리 · 줄 글자 · 밝게 · 어둡게 · 고대비) ·
        콘솔 오류 0 · 스크린샷(홈 1440 밝게 접힘 · 펼침 · 412 어둡게 펼침 · 손님 1440 펼침 · 앱 판 1440 관리자 · 업무 첫 방문 1280 · 가입 직후 1280)
```

## 기대값만 바꿀 옛 시험 (그 줄만 · 돌리지 않는다 — 메인이 밤 회귀 대기열에 넣는다)

| 시험(카탈로그 id) | 줄(참고) | 전 → 후 | 닿는 것 |
|---|---|---|---|
| `tests/community/qf12_shell_browser.py`(community.qf12_shell_browser) | :251-254 | `Apps::all()` key · 이름 · 주소 · 아이콘 순서 `tbox · community · story · spaces` → `community · tbox · spaces · story`(이름 업무 · 자료실 · 우리 공간 · 이야기 — 인덱스를 맞춤) | D |
| 〃 | :281 · :287 · :290 · :312 · :449 · :474 · :492 · :525 | `TILES` · `TILES_EMP` · 관리자 이름 · href 목록을 모아 홈 · 업무 · 자료실 · 우리 공간 · 이야기 (· 관리자 페이지) 순서로 | D |
| 〃 | :173 `GRID_STATE_JS` | **도우미 한 줄** — `.app-menu__apps > li` 에서 `li.app-menu__sep` 를 건너뛴다(`:not(.app-menu__sep)`) — 안 그러면 `firstElementChild` 가 없어 TypeError 로 Q:T-40 묶음 전부가 실패한다. `grid_tiles()`(:213 — `<li>` 그대로만 잡는 정규식)는 class 가 붙은 구분줄을 잡지 않아 그대로 | D |
| 〃 | :305 | 쿠키 없는 첫 HTML 의 `aria-expanded="false" aria-label="메뉴 펼치기"` → `aria-expanded="true" aria-label="메뉴 접기"` | H |
| 〃 | :612 | 그대로 — 「문서 AI」 는 `hidden` 이라 닫힌 `<details>` 안 줄 목록에는 남는다(F 를 `hidden` 으로 하는 이유 하나) | F |
| `tests/community/qf12_u5_browser.py`(community.qf12_u5_browser) | :323 · :354 | 기본 `['spaces','work','library','messages','assistant']` → `['work','library','messages','spaces']` · 「메신저 앞으로」 뒤 → `['work','messages','library','spaces']` | A |
| `tests/community/home_h1_browser.py`(community.home_h1_browser) | :36 · :235 · :443 | `DEFAULT` → `['work','library','messages','spaces']` | A |
| 〃 | :238-239 | `want_rows = [4,2] if phone else [6]` → `[4,1] if phone else [5]` | A |
| 〃 | :290 | `ap['tiles'][:4]` → `['모아 홈','업무','자료실','우리 공간']` | D |
| 〃 | :423 · :429 | 「메신저 앞으로」 뒤 `['work','messages','library','spaces','+']` · 이야기 · 계정을 더 켠 뒤 수 7 · 줄 `[7]`(PC 비서도 켜는 시험이면 8 그대로 — 시험이 무엇을 켜는지 보고 맞춤) | A |
| 〃 | :516 `LAUNCHER_SNAPSHOT_REF` | 동의 전 갈래 HTML 이 Q5 글자로 바뀌므로 ⑤ 바이트 비교의 기준 판을 **이 단계 launcher.php 최종 커밋**으로(S1c 와 같은 방식 — 마지막에 따로 한 커밋 · 해시를 보고서에) | E |
| `tests/community/cafe_c4_browser.py`(community.cafe_c4_browser) | :590 · :596 · :642 · :658 · :663 · :678 | 쿠키 없음 · 다른 값 → **펼침**(240 · `aria-expanded="true"`) · 접힌 모양 단언(:642~:678)은 쿠키 `qaredi_sidebar=collapsed` 를 넣은 문맥으로 옮긴다(단언 내용 그대로) | H |
| 〃 | :690 · :698 · :701 · :703 · :710 | 첫 ☰ 는 이제 **접기** — 쿠키 `collapsed` · 다음 화면 · 새로 고침 접힘 → 다시 ☰ 펼침 · 쿠키 `open`(지우지 않음 — :710 「쿠키 없음」 → 「open」) | H |
| 〃 | :798-799 | 앱 판(우리 공간 뺀) `['모아 홈','자료실','업무','이야기']` → `['모아 홈','업무','자료실','이야기']`(+ 관리자 페이지) | D |
| `tests/community/qf12_work_browser.py`(community.qf12_work_browser) | :1541 | 접고 새로 고침 뒤 `ck == []` → 쿠키 `qaredi_sidebar=collapsed` 하나 | H |
| 〃 | :1587 | 첫 그리기 깜빡임 단언은 쿠키 `qaredi_sidebar=collapsed` 를 넣은 문맥으로 옮겨 그대로(첫 프레임부터 접힘 · 깜빡임 ≤ 2) — 쿠키 없는 문맥은 첫 프레임부터 펼침(속성 없음)으로 | H |
| `tests/community/invite_request_browser.py`(tbox.invite_request_browser) | :290 | 자주 묻는 질문 셋째 `'내 자료는 누가 보나요?'` → `'내 파일과 글은 누가 보나요?'` | I |
| `tests/community/home_h4_browser.py`(community.home_h4_browser) | :347 | [h5] 쿠키 없음 `collapsed · rail 64` → 펼침 · 240 | H |

**안 깨지는 것으로 확인(손대지 않는다)**: `cafe_h3_browser.py`(우리 공간 타일을 이름으로 · `[data-home-shortcut=spaces]` — 그대로 · :122 는 공간 위젯 코드를 **지우지 않으므로** 통과) · `tests/upgrade/flows.json`(390px · 홈 · 앱 판 · 문서 AI · 가입 0) · `service_home.py`(카드를 `hidden` 으로 두므로 수 · 색 그대로) · `invite_request_browser.py:242`(작업 판을 main 과 비교 — 합친 뒤 통과 · 격리 작업 중엔 다름이 정상) · `q_launcher.sh`(운영 · known_fail 2026-11-12 까지 — 그대로).

**지켜야 할 제약(깨지지는 않지만 새 글자 · 높이가 닿는 곳)**: `brand_words_browser.py` [pages] 는 홈의 모든 `<details>` 를 열어 금지 낱말(:39)을 찾는다 — 카드 글자에 그 낱말 0 · `home-dashboard.js` 의 ⑥ 바늘(:369)은 남긴다 · `design_themes_browser.py`(런처 `[style]` · 인라인 스크립트 0 · id 중복 0 — 새 id 는 `home-tasks` 하나) · `home_h1_browser.py:245`(첫 화면 창구 요청 목록 — 카드가 요청 0 이면 그대로) · `home_h4_browser.py:283` [foot](1440×900 · 1920×1080 · 412×900 에서 홈이 짧은 화면 — **카드 접힘 기본이면** 그대로, 수락 ⑩ 에서 같은 세 크기를 잰다) · `story_s1_browser.py:188 · :193`(쿠키 없이 960 — 펼친 240 사이드바에서 가운데 열 · 한 줄 통계가 버티는지 수락 ⑭ 에서 한 번 본다).

**카탈로그 밖(돌리는 묶음 아님 — 고치지 말고 보고서 「카탈로그 밖으로 깨짐」에 줄만 적는다)**: `home_shortcuts_browser.py:36` · `compact_home_ui.py:62 · :79`(기본 바로가기) · `dashboard_widgets_ui.py:50 · :58-59 · :63 · :75-85` · `dashboard_inspector_integration.py:52 · :75-76` · `dashboard_inspector_ui.py:12-14` · `home_dashboard_sync.py:45`(홈의 `space_*` 위젯 · 「내 공간의 최근 활동」 — E 로 숨김) · `product_spaces_widgets.php:20 · :26 · :30`(개인 기본 구성) · `home_dashboard.py:53` · `dashboard_inspector_integration.py:61` · `dashboard_widgets_ui.py:56`(I 의 고르개 · 줄 표시 글자) · 원래 깨진 것: `neutral_home_entry.py:53` · `compact_home_ui.py:84` · `qf12_shell_browser.py:262 · :267 · :277`(`nav.moa-shortcuts` — 지금 main 에도 없음).

이 밖에 grep 으로 찾은 단언이 있으면 같은 방식으로(그 줄만) 고치고 보고서에 더한다. 주석 · 설명 글 · 시험 이름 속 옛 순서는 그대로 둔다.

## 되돌릴 수 없는 것 (보고서 「못 한 것」 옆에 그대로 적는다)

- **Q3 B**: 운영의 저장된 개인 홈 바로가기 구성은 반영 때 지워진다. 되돌리기는 B 「반영 순서」 5 대로 따로 남긴 목록에서 그 칸만 — 사용자가 정할 때만(운영 데이터 규칙상 메인도 스스로 복원하지 않는다). 반영 뒤 사용자가 홈 편집에서 새로 저장한 바로가기는 되돌리면 덮인다.
- 사이드바: 지금 「쿠키 없음 = 접힘」으로 쓰던 사람은 바뀐 날 한 번 펼친 모양을 본다(다시 접으면 기억).

## 반영 · 발행 (메인)

- 반영: **B 「반영 순서」 1~4 그대로**(백업 → 바뀔 행 따로 남기기 · 수 세기 → 모의 적용 → 적용) · 코드는 모의 반영 · 드리프트 확인 뒤 같은 창에서 → 운영 확인(홈 · 앱 판 · 사이드바 · 가입 화면을 claude_ 계정으로) · 밤 회귀 대기열에 위 「옛 시험」 목록 · SECURITY 7장에 「운영 저장값 변경 — 개인 홈 구성 shortcuts n행 지움(Q3 B) · 되돌리는 목록 위치」.
- 발행 minor 한 번 — 제목 「모아 홈 정리」 · 변경 문장(사용자 말): ① 「모아 홈 바로가기가 업무 홈 · 자료실 · 메신저 · 우리 공간 넷으로 정리됐어요 — PC 비서 · 이야기는 「＋ 바로가기」에서 다시 켤 수 있어요」 ② 「바로가기 아래 「무엇을 하고 싶으세요?」를 펼치면 자주 하는 일로 바로 갈 수 있어요」 ③ 「오른쪽 위 「앱」에서 이야기를 줄 아래로 옮겼어요」 ④ 「가입하면 바로 모아 홈에서 시작해요」 ⑤ 「업무 · 자료실 왼쪽 메뉴가 처음엔 펼쳐져 있어요 — 접으면 기억해요」.
