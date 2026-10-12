# 지시서 U2 — 사용감 라운드: 사이드바 접기 · 앱 격자 · 업무함(중요 순 펼침) (2026-10-12)

사용자 요청(2026-10-12 「업무 관련 탭을 구글 메일처럼 — ☰ 로 접고, 상세 제목을 사이드바에 두지 않고, 중요 순으로 펼쳐가며」 + 「좌측 자료실~메신저는 구글처럼 우측 위 격자로」) ·
결정 WK-19 **A+ⓐ**(접으면 아이콘만 · 펼친 모양은 지금) · WK-20 **C**(홈 앞 10줄 + 내 작업 전체) · WK-21 **A**(줄 아래 펼침) · WK-22 **①**(기한 → 오늘 → 검토·멘션 → 이번 주 → 나중에 → 기한 없음, ★ 는 묶음 안) · WK-23 **B**(왼쪽 아이콘 줄을 없애고 오른쪽 위 ⋮⋮⋮ 앱 격자).
기준 문서(**정답지 — 읽고 시작한다**): `DESIGN.md` **2.18**(앱 격자) · **2.19 ⑨**(접기) · **2.20-A**(업무함) · 2.21(상세 칸 — 그대로 재사용) · 6장 체크리스트 · 기획 `/var/www/qaredi/community/work-ux-plan.md` **14장**(읽기만 — 그 폴더는 고치지 않는다) · `ARCHITECTURE.md` 13장 · `/var/www/qaredi/CLAUDE.md` 2·3·4장.
시안: 비공개 아티팩트 v2(메인 세션 보관) — 글 규칙이 시안보다 우선한다.
단위: `/var/www/qaredi/CLAUDE.md` 3.1 「따로 쓰이는 것」 — 사용감 라운드 한 묶음, 조각 하나(U2-a·b·c 한 지시서). 서버 PHP 변경 **0**(화면 PHP 뷰 2 + 셸 뷰 1 만).

## 공통

| 항목 | 내용 |
|---|---|
| 구현 자리 | 격리 worktree **`/home/changkwon/qaredi-qf12u2-20261012`**(브랜치 `feat/qf12-u2` — 메인 세션이 만들었고 `main` 의 DESIGN 2.18·2.19·2.20-A 가 들어 있다). 결과 폴더 **`/home/changkwon/qaredi-qf12u2-results-20261012/U2/`**. 운영 `/var/www/qaredi/tbox-v2` · 운영 DB · 다른 worktree 는 **읽기도 쓰기도 하지 않는다**(기획 문서 `/var/www/qaredi/community/work-ux-plan.md` 만 읽기 허용) |
| 격리 틀 | `python3 tests/community/memories_harness.py --suite <파일> --output <결과 폴더>` — 합성 DB · 로컬 PHP(포트 8768 고정 — **한 번에 하나만**. 다른 세션이 쓰고 있으면 기다린다: `ss -ltn | grep 8768`) |
| 브라우저 | `. /home/changkwon/qaredi-qf12u2-results-20261012/env.sh` 로 `CHROMIUM_EXECUTABLE` · `PYTHONPATH` · `PATH` 만 받는다. 다른 결과 폴더의 env.sh 금지 · `rm -rf` 는 자기 결과 폴더 안 절대 경로만 |
| 교차 회귀 | `python3 tests/stage89_cross_regression/run.py --output <결과 폴더>/stage89` — 끝에 한 번 |
| 계정 | 격리 틀의 fixture 계정만. 운영을 치는 시험 없음. 시드 계정 금지 |
| 커밋 | worktree 브랜치에 파일을 지정해 커밋(`git add -A` 금지 · 한글 · 첫 줄 `Qaredi Q:F-12 U2: …` · 끝에 `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`). push · 발행 · main 합치기는 하지 않는다(메인 몫) |
| 디스크 | 루트 여유 약 6GB — 결과 폴더에 스크린샷은 필요한 것만(PNG 20장 안팎), 영상·trace 금지 |

```
목표        : 업무 앱을 Gmail 받은편지함처럼 — ☰ 로 사이드바를 아이콘만 남게 접고, 앱 바꾸기는 오른쪽 위 ⋮⋮⋮ 격자로 옮기며,
              홈과 내 작업이 「중요 순 한 줄 흐름」 업무함이 되어 줄을 누르면 그 아래로 작업 상세가 펼쳐지게 한다.
요구사항    : DESIGN.md 2.18(앱 격자 · 표 전부) · 2.19 ⑨(접기) · 2.20-A(업무함 · 표 전부) · 2.21(상세 칸 안쪽 그대로 · createPane 재사용) ·
              2.9 「폰 윗줄」(앱 이름 ▾ 가 격자를 연다) · 6장 체크리스트(새 색값 0 · 인라인 style 0 · innerHTML 0 ·
              새 클래스는 DESIGN 에 적힌 것만: .shell-tools · .shell-tools__me · .task-inbox · .task-inbox__tag · .task-inbox__row ·
              .task-inbox__line · .task-inbox__star · .task-inbox__pane — 그 밖은 지금 클래스 재사용) ·
              기획 14.4 U2-a·b·c · 14.6 보안 영향(서버 0 · 판정 0 · 새 창구 0) — 해당 절을 읽고 시작할 것.
수정 파일   : src/views/shell_top.php(① nav.app-rail 삭제 ② .moa-shell-links 삭제 ③ 브랜드 details.appbar__apps 를 <main> 첫 줄 div.shell-tools 로 옮김 —
                 요소 하나를 옮기는 것이지 복제가 아니다. summary 는 ⋮⋮⋮ 아이콘 단추, 판은 3열 타일 + 메신저 타일(data-messenger-open ·
                 data-message-unread 배지) + 검색 타일 + 로그아웃 ④ 사이드바 머리에 button#sidebar-collapse ☰ ⑤ mobile-nav-dialog 의
                 「메뉴/앱·계정」 전환 단추 삭제 ⑥ 자료실·이야기 셸도 같은 마크업 한 벌 — 셸 분기(if $shellMod) 안의 앱 줄 코드는 전부 지운다) ·
              src/views/shell_bottom.php(shell-tools 가 main 안에서 닫히는 짝이 필요할 때만) ·
              src/community/handlers/work_home_page.php(#work-home-tasks 안: .work-cols 세 칸 → section.task-list.task-inbox 뼈대 + 「모두 보기」) ·
              src/community/handlers/my_tasks_page.php(#my-tasks-list → .task-inbox 뼈대 · aside#task-pane 은 그대로 둔다) ·
              public/assets/app.css(2.18 격자 · 2.19 ⑨ 접힘 · 2.20-A 업무함 · 옛 .app-rail 블록과 --rail-bg/--rail-fg 토큰 삭제 — --rail-w 는 남긴다) ·
              public/assets/app.js(initShell: #sidebar-collapse 토글 + localStorage 기억 `qaredi-sidebar-collapsed:v1:<username>` ·
                 initMobileSidebar: apps 모드 제거, #appbar-apps 는 details.appbar__apps 를 연다 · 메신저 안 읽은 점) ·
              public/assets/messenger.js(안 읽은 수를 ⋮⋮⋮ 점에도 — 지금 [data-message-unread] 반복에 새 자리가 자연히 들어오면 변경 0) ·
              public/assets/community/work.js(initHome · initMyTasks 를 업무함으로 — taskRow 는 kind 'inbox' 갈래 추가, createPane 은 호스트 div 를 받을 수 있게
                 인자 하나 넓힘(aside 가 기본) · briefing 합치기 · ★ 즐겨찾기 토글은 editor.js 의 지금 즐겨찾기 창구 함수 재사용 — 없으면 멈추고 보고) ·
              public/assets/community/editor.js(즐겨찾기 토글 함수를 work.js 가 부를 수 있게 노출하는 한 줄만 — 그 밖 0) ·
              커뮤니티 JS 의 import 버전 표시 줄(`php scripts/community_asset_version.php --write` 가 바꾸는 줄만 — 손으로 고치지 않는다) ·
              tests/community/qf12_shell_browser.py(아이콘 줄 단언 Q:T-38 → 격자 단언으로 바꿈 — 아래 완료 기준 ①) ·
              tests/community/qf12_work_browser.py(접기 · 업무함 단언 더하기 — ②) ·
              tests/catalog.json(covers·notes 갱신이 필요할 때만) · docs/qf12-u2-report.md(보고서 — 새 파일)
              여기 없는 파일 금지. ★ 보안 핵심 파일(src/Http.php · Authz.php · routes.php · public/index.php · db/** · deploy/**)과
              src/community/handlers/my_tasks.php · briefing.php · Briefing.php 는 고치지 않는다 — 필요해 보이면 멈추고 보고.
금지 패턴   : SQL 문자열 결합 · innerHTML(insertAdjacentHTML · outerHTML 포함) · 사용자 입력이 든 파일 경로 · 외부 요청 코드 ·
              역할 판단을 Authz/CommunityAuthz 밖에서 · 요청 파라미터의 role 값 · 브라우저 HTML 저장 · 인라인 style · 인라인 <script> · on* 속성(CSP) ·
              새 SVG 를 화면에 직접(아이콘은 data-icon 이름과 moa_icon.php 만) · 새 색값 · 화면이 작업을 거르거나 더하기(두 창구 답을 잇기만) ·
              쓰는 단추(완료 원 · ★ · 작업 추가)를 can_* / can_community_write 밖의 조건으로 숨기기 · 새 창구 · 응답 키 추가 · 앱 메뉴 두 벌.
세부(정해 둔 값 — DESIGN 과 충돌하면 DESIGN 이 우선, 충돌을 발견하면 보고):
  U2-c 앱 격자: div.shell-tools 는 <main id="main"> 의 첫 자식(모든 셸 화면 — 런처 · member_top 화면은 셸이 아니라 그대로 없음). 타일 순서: 모아 홈(/) → Apps::all() 순서(soon 은 .is-soon) → 메신저 → 내 계정 → 내 활동 → 관리자 페이지(#menu-admin hidden 규칙 그대로) → 검색 → (구분선) 로그아웃.
       기존 id 유지: #menu-settings · #menu-activity · #menu-admin · #logout. 메신저 타일 = button[data-messenger-open][aria-haspopup=dialog][aria-controls=messenger-window] (messenger.js 가 이미 모든 [data-messenger-open] 을 연다 — $shellMessenger 거짓이면 /tbox/messages 링크).
       검색 타일 = button 이 #nav-search.click() 과 같은 창(search.js — 같은 함수 호출, 창 두 벌 금지). ⋮⋮⋮ 점 = span[data-message-unread] 를 숫자 대신 점으로 그리는 CSS(글자는 화면 읽기용 「안 읽은 메시지 n」 aria-label).
       폰: #appbar-apps 클릭 → details.appbar__apps.open 토글 + aria-expanded. 판은 <960 에서 position: fixed; top: 윗줄 아래; left/right 0; max-height: calc(100dvh - 윗줄). 서랍(☰)은 nav 모드 하나.
  U2-a 접기: body[data-sidebar="collapsed"] 는 ≥960 에서만 효과(media 안). 첫 그리기: app.js 맨 위(모듈 평가 때)에서 localStorage 를 읽어 속성을 단다 — 사용자 이름이 필요하므로 키는 /api/me 뒤에 확정하되, 그 전엔 `qaredi-sidebar-collapsed:v1:*` 중 하나라도 collapsed 면 임시로 달아 깜빡임을 줄인다(값은 참/거짓뿐 — 민감 정보 0).
       접힌 상태의 .nav__item 글자 가림 = 2.9 「긴 단추 이름 줄이기」와 같은 방식(.narrow-hide 와 같은 가리기 — display:none 아님). 툴팁 = 2.8 CSS 툴팁(data-tip 이나 ::after — 지금 규칙 이름 그대로).
       접힌 상태에서 #nav-new-menu 의 메뉴 판은 사이드바 오른쪽 바깥으로(position 조정) — 사이드바 overflow 가 자르지 않게 확인.
  U2-b 업무함 — 데이터 합치기(work.js 안 순수 함수 buildInbox(myTasks, briefing, favorites, today) 로 두고 단위 시험 가능하게):
       rows = my-tasks buckets 순서(overdue → today → this_week → later → no_due) 그대로 → 각 bucket 안에서 ★(favorites 에 page id 있음) 먼저, 나머지는 창구 순서.
       briefing 의 top+missed+others 중 kind ∈ {review, mention} 인 것(page_id 있음)을 모아 — my-tasks 에 이미 있는 page_id 면 그 줄에 표식(reasons 의 kind 로 「검토」 badge / 「@」)만 더하고, 없으면 「검토 요청 · 멘션」 묶음(today 와 this_week 사이)에 줄로(출처 = briefing 이 주는 글자 중 있는 것: project/space 이름이 없으면 「문서」, 보조 글 = summary, 마감 = due). 같은 page_id 가 briefing 안에 둘이면 하나.
       공지(kind notice)는 지금 #work-home-notice 한 줄 그대로(내 작업 화면에는 없음). 홈 = 앞 10줄(묶음 경계를 지나며 센다) + 11번째부터는 「모두 보기」; 내 작업 = 전부 + 묶음 「더 보기」(next_cursor) 그대로.
       줄 펼침: createPane(hostEl, ctx) — hostEl 이 aside#task-pane 이면 지금 동작, div.task-inbox__pane 이면 그 안에 같은 내용(머리 줄 ✕ = 접힘). ≥1200 에서 업무함 화면은 줄 아래 펼침, 960~1199 와 <960 은 지금 aside 규칙(2.21) 그대로 — 폭에 따라 호스트를 고르는 갈래 하나(matchMedia) · 폭이 바뀌면 열린 작업을 같은 id 로 다른 호스트에 다시 연다.
       주소: pane.open(id,{push:true}) 그대로 ?task= · popstate 로 접힘/앞 작업(지금 코드). 완료 원 → setDone → 다음 줄 .task-inbox__line 에 focus → work:changed 로 다시 그림.
       ★ 토글: 지금 즐겨찾기 창구(editor.js 의 사이드바 즐겨찾기가 쓰는 PATCH/POST) 재사용 — 성공 뒤 목록 다시 그림. 창구 함수가 없으면 ★ 는 표시만(토글 없음)으로 두고 보고.
       빈 상태: 줄 0 이면 지금 문구(「맡은 작업이 없어요. …」) · 홈은 「오늘 할 일이 없어요.」 한 줄.
완료 기준   : ① qf12_shell_browser — 아이콘 줄 단언(rail_*) 을 **격자 단언**으로 바꾼다: 자료실 /tbox/ · 업무 /community/ · 이야기 /community/memories × 1440·1280·960:
              nav.app-rail 0 · div.shell-tools 1(main 첫 자식 · 오른쪽 정렬) · details.appbar__apps 가 문서에 **하나** · 닫힘 기본 · 누르면 판 열림(폭 300 ±8 · 단추 오른쪽 아래) ·
              타일 순서 = 모아 홈 · 자료실 · 업무 · 이야기 · 메신저 · 내 계정 · 내 활동 · (관리자 — can_admin 계정만) · 검색 · 로그아웃 · aria-current 는 지금 앱 하나 · Esc/바깥 누름 닫힘 ·
              타일 글자 대비 ≥ 4.5(테마 여섯 × 밝게·어둡게·고대비 — 지금 contrast 틀 그대로) / 959·820·412·360: shell-tools display none · #appbar-apps 누름 → 같은 details 열림(전체 폭) · 서랍 ☰ 는 nav 만 ·
              런처 · /tbox/welcome · /tbox/messages 는 shell-tools 0 · 손님 · 「역할로 보기」 같은 목록 · 가로 넘침 0(일곱 폭) · 콘솔 0.
              ② qf12_work_browser — 지금 단언 전부(바뀐 홈·내 작업 뼈대에 맞춰 고친 것 포함) + U2 단언:
              [접기] 1440: #sidebar-collapse 보임 · 누름 → body[data-sidebar=collapsed] · 사이드바 폭 64 · 보이는 .nav__item 글자 0 · 보이는 항목 = ＋ · 홈 · 내 작업 · 받은 알림(배지 겹침) · 검색 ·
              묶음 제목 · 문서 선택 · 테마 · 버전 · 사용자 칩 display none · main 왼쪽 x 가 176 줄어듦 · 툴팁(hover) 글자 = 항목 이름 · aria-expanded false · 새로고침 뒤 유지 · /community/my-tasks 로 가도 유지 ·
              다시 누름 → 240 · 959 에서는 #sidebar-collapse display none · 서랍 그대로.
              [업무함] 합성 데이터(fixture: 지난 기한 2 · 오늘 1 · 이번 주 3 · 기한 없음 1 · 검토 요청 1 · 멘션 1 — 그중 멘션 1 은 지난 기한 작업과 같은 페이지) 로:
              화면 줄 순서 = buildInbox 결과 = (지난 기한 오래된 순 → 오늘 → 검토·멘션 → 이번 주 → 기한 없음) · 꼬리표 글자·수 · 같은 페이지는 한 줄 + 「@」 · ★ 한 작업이 자기 묶음 맨 위 ·
              줄 누름 → 그 줄 aria-expanded true · 바로 아래 .task-inbox__pane 안에 2.21 요소(제목 h2 · dl.task-pane__props · 설명 · 하위 작업 · 댓글 칸) · 주소 ?task= · 다른 줄 누름 → 앞 줄 false ·
              같은 줄 다시 → 접힘 · ✕ · Esc · 뒤로 가기 → 접힘 · 완료 원 → 줄 .task-row--done + 초점 다음 줄 · work:changed 뒤 그 줄 사라짐 · ↓↑ 이동 ·
              홈 = 앞 10줄 + 「모두 보기」 href /community/my-tasks · 내 작업 = 전부 · 보드·달력·끝난 작업 탭 그대로 · briefing 을 403 으로 막아도(격리 틀에서 route 를 막는 방법이 없으면 fetch 를 가로채) 작업 줄은 그려짐 ·
              1199: 줄 누름 → aside#task-pane 이 목록을 대신 + 「‹ 목록」 · 412: 전체 화면 · 보기만 하는 사람·손님: 완료 원 0 · ★ 토글 0 · 작업 추가 0 ·
              폭 여섯(360 · 412 · 820 · 1199 · 1280 · 1440) × 밝게 · 어둡게 · 고대비: 가로 넘침 0 · 콘솔 0 · 누름 자리 < 44 0(폰·터치) · 인라인 style 0(CSSOM 진행 폭 제외).
              ③ qf12_server_http · qf12_floor_http 지금 수 그대로 통과(서버 변경 0 의 증거) ④ stage89 교차 회귀 ⑤ bash scripts/security_lint.sh 0 · php -l(바뀐 PHP 전부) ·
              node --check app.js · work.js · editor.js · php scripts/community_asset_version.php --check 0 · git diff --check · python3 tests/lib/contrast.py(격자 타일).
              스크린샷(1440: 사이드바 펼침·접힘 × 홈 업무함 한 줄 펼친 상태 · 격자 열림 · 412: 격자 열림 · 내 작업 · 1199: 「‹ 목록」 — 밝게·어둡게 각 1장)을 결과 폴더에 남기고 **직접 열어 본 뒤** 통과라고 쓴다.
보고 형식   : docs/qf12-u2-report.md 에 「공통」 — 변경 파일 목록 / 실행한 명령과 출력 원문(결과 폴더 경로 · 단언 수 통과/실패) / 못 한 것과 이유 /
              확정한 값(접힘 localStorage 키 · 첫 그리기 깜빡임 유무 · ★ 창구 함수 이름 · buildInbox 의 합치기 규칙 · 커밋 해시) / DESIGN 과 다르게 한 것(있으면 이유).
```
