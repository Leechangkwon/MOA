# 지시서 Q:F-16 1차 — 업무 프로젝트에서 템플릿 골라 쓰기 (2026-10-30)

사용자(2026-10-30): 「업무 프로젝트에, 양식을 선택해서 쓸 수 있게 하면 어때??」 → 「기획서로 써 줘」 → 결정 **WT-1 ~ WT-7 「권고대로 할게」 · WT-8 「권고대로(A)」**(2026-10-30). 사이트 `PRD.md` Q:F-16.
기준 문서(**정답지**): 기획 `../../docs/work-templates-plan-2026-10-30.md` v1.0 **2장**(지금 있는 것 — 창구 · 판정) · **3장** G-1 · G-2 · G-3(1차 범위) · **4장**(화면 글 그림 (a)(b)(c)) · **5장** WT-1 ~ WT-8(확정 답) · **7장**(보안 영향) · 9장(쓰임 예 — 주간재료비 보고) · `DESIGN.md` **2.38**(새 절 — 이 지시서와 함께 씀) · 2.15(더보기 메뉴) · 2.20(「작업 추가」 줄) · 2.21(작업 상세 칸 더보기) · 2.22(프로젝트 머리) · 2.7(창 바닥 단추) · 5장 낱말 표 「템플릿 · 양식」 · 6장 · `public/assets/community/templates.js` 머리 주석(★ 둘) · `/var/www/qaredi/CLAUDE.md` 2 · 3.1(수락 시험만) · 4장(5대 방어).
보이는 모양의 기준: 기획 4장 (a)(b)(c)와 DESIGN 2.38. 둘이 다르면 **DESIGN 이 정답**이고 보고서에 적는다(기획 4장의 범위 글자 「내 것」은 DESIGN 5장 「같은 말」 규칙으로 홈 「템플릿에서」 창과 같은 「내 템플릿」으로 쓴다).
한 줄로: **서버 변경 0** — 창구 · 판정 · DB 표 · 마이그레이션 · `src/community/routes.php` · `COMMUNITY_API_TOKEN_ROUTES` · `src/community/handlers/**` 를 한 글자도 고치지 않는다. 바뀌는 것은 화면 JS 넷(`work.js` · `templates.js` · `automations.js` · `editor.js` 한 줄) · PHP 뷰 한 곳(`page_view.php` 머리 꺾쇠) · `app.css` 끝 새 클래스 일곱. **꺾쇠를 누르지 않은 「작업 추가」는 지금과 똑같이 돈다**(옛 시험이 그대로 통과해야 한다). 꼭 서버를 고쳐야만 되는 것을 찾으면 **구현하지 말고** 보고서 「갈림길」에 적는다(사용자 판단).

| 항목 | 내용 |
|---|---|
| 구현 자리 | 격리 worktree **`/home/changkwon/qaredi-qf16-<날짜>`**(브랜치 `feat/qf16-work-templates` — 메인이 만든다 · 날짜는 만든 날) · 결과 **`/home/changkwon/qaredi-qf16-results-<날짜>/`** · 운영 · 운영 DB 읽기 · 쓰기 0 |
| 격리 틀 | `python3 tests/community/memories_harness.py --port 8768 --suite qf16_templates_http.py --output <결과>/http` · 같은 틀 `--suite qf16_templates_browser.py --output <결과>/browser` — 합성 DB(`tbox_memories_test_*`) · 임시 복사본 · 로컬 `php -S` 만. 시작 전 `ss -ltn` 로 8768 · 8769 가 비었는지(쓰이면 `--port 8778`) · 끊으면 내 것만 치움. 계정은 fixture 의 `claude_` 다섯(employee = `claude_employee01` 팀 a · other = `claude_employee02` 팀 b · manager = `claude_manager01` 팀 a · admin = `claude_admin01` · guest = `claude_guest01`) · UA `tbox-review/<날짜>`. 시드 계정(`admin01` …) 0 |
| 커밋 | 파일 지정(`git add -A` 금지) · 첫 줄 `모아 Q:F-16 1차: …` · 끝 `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>` · push · 발행 · 합치기 0(메인이 한다) |
| 줄 번호 | 아래 줄 번호는 2026-10-30 main(`9350457` · 운영 v2.77.0) 기준 **참고**다. 고칠 자리는 **지금 글자 · 선택자**로 찾는다 |

```
목표        : 업무 프로젝트 화면의 「작업 추가」 오른쪽 꺾쇠(머리 · 섹션 끝)에서 템플릿(이 프로젝트 행 템플릿 + 내 템플릿 · 팀 · 사이트 기본)을
              미리 보고 고르면 그 자리(섹션)에 칸 값 · 본문이 채워진 작업이 생기고, 작업 상세 더보기에서 「템플릿으로 저장」하고,
              템플릿에서 「이 템플릿으로 매주 만들기」(기존 반복 창)로 바로 가는 입구 셋(G-1 · G-2 · G-3)을 화면만으로 만든다.
요구사항    : 기획 2 · 3 · 4 · 5 · 7장 · DESIGN 2.38 · 2.15 · 2.20 · 2.21 · 2.22 · 2.7 · 5장 · 6장 · templates.js 머리 주석 ·
              CLAUDE.md 4장(5대 방어) — 읽고 시작할 것. 새 클래스는 DESIGN 2.38 「새 클래스 · 토큰」 줄의 일곱만 · 새 색값 0 · 토큰 0.
              결정 WT-1 ~ WT-8 은 사용자 답(모두 권고) 그대로 — 다시 묻지 않는다.
수정 파일   : 고치는 파일: public/assets/community/work.js(꺾쇠 · 목록 · 미리보기 · 만들기 · 상세 더보기 항목 · import 한 줄) ·
                       public/assets/community/templates.js(createTemplateSave 에 open() · 저장 뒤 「매주 만들기」 단추) ·
                       public/assets/community/automations.js(repeatDialog 의 data.parent — 「만들 자리」 기본값 한 줄) ·
                       public/assets/community/editor.js(createWork 에 넘기는 deps 한 줄 — templateSave) ·
                       src/community/handlers/page_view.php(프로젝트 머리 꺾쇠 details 하나 — 다른 줄 0) ·
                       public/assets/app.css(끝 — 2.38 새 클래스 일곱 · 2.15 의 지금 줄 0)
              새 파일: tests/community/qf16_templates_http.py · tests/community/qf16_templates_browser.py · docs/qf16-work-templates-report.md
              시험 등록: tests/community/memories_harness.py(--suite choices 에 둘) · tests/catalog.json(둘)
              버전 줄만: public/assets/community/*.js · public/assets/messenger.js 의 import `?v=` — scripts/community_asset_version.php 가 찍는 줄(아래 ⑤)
              여기 없는 파일은 만들거나 고치지 않는다(특히 src/community/routes.php · src/community/handlers/** · src/community/CommunityAuthz.php · db/** · deploy/**).
금지 패턴   : CLAUDE.md 2장 공통(SQL 문자열 결합 · innerHTML · 사용자 입력이 든 파일 경로 · 외부 요청 · 역할 판단을 Authz 밖에서 · 요청의 role 값 · 브라우저 HTML 저장) ·
              work.js · templates.js 에 역할 · 공간 · 범위를 따지는 코드(보이고 안 보이고는 창구 값 — can_add_rows · template_scopes · 목록 — 뿐) ·
              보기 공유만 받은 문맥에서 GET /api/templates 호출 · 꺾쇠 없이 「작업 추가」의 동작 · 글자 · 선택자 바꾸기 · 「내 할 일」(my_tasks) 빠른 추가에 template_id 보내기(서버 400) ·
              innerHTML · outerHTML · insertAdjacentHTML · 인라인 style · 새 색값 · 숫자 z-index · DESIGN 2.38 에 없는 클래스 · 메뉴 안 검정 단추 ·
              브라우저 기본 창(alert · prompt · confirm) · 새 창구 · 새 라우트 · 새 마이그레이션 · src/Authz.php · src/Audit.php · public/index.php · git add -A.
완료 기준   : 수락 시험 둘(qf16_templates_http.py · qf16_templates_browser.py — 아래 Q:T-44 ~ 50) 전부 통과 ·
              옛 시험 둘을 고치지 않고 통과: python3 tests/community/memories_harness.py --suite qf12_work_browser.py(QF12D_ONLY=u1 — 「작업 추가」 줄 · 머리 단추 수 · 상세 칸) · --suite project_sections.py ·
              정적: scripts/security_lint.sh 0 · php -l(page_view.php) · node --check(바꾼 JS 전부) · php scripts/community_asset_version.php --check 0 ·
                    bash tests/community/asset_version.sh 통과 · php scripts/wiki_check.php 0 · git diff --check · 바꾼 JS 에 innerHTML · style= · 'Admin' · 'Guest' · 'role' 비교 0 · app.css 새 줄에 # 색값 · rgb( · 숫자 z-index 0
보고 형식   : docs/qf16-work-templates-report.md — 변경 파일 / 수락 시험과 결과(명령 · 출력 꼬리 원문 · 통과/실패 수) / 옛 시험 둘의 결과 /
              기획 4장 · DESIGN 2.38 · 이 지시서와 다르게 한 것(왜) / 못 한 것 / 갈림길(서버를 고쳐야만 되는 것 — 구현 0) / 스크린샷 경로. 짧게.
```

## 지시서 전 확인 다섯 — 코드 근거 (2026-10-30 · 이 값으로 지시서를 썼다 · 구현자는 다시 확인하고 다르면 보고)

| # | 물음 | 결론 | 근거 |
|---|---|---|---|
| ① | 「작업 추가」 줄로 만든 작업의 **담당 기본값** | **담당 없음(null)**. 「내 작업」에는 「담당 없음 + (팀 공간이면 작성자 = 나 · 개인 공간이면 공간 주인 = 나) + 상태 또는 기한 있음」 규칙으로 뜬다 — 그래서 지금 줄이 만든 직후 `PATCH …/properties {status:'todo'}` 를 보낸다. 템플릿으로 만들 때도 **같은 PATCH 를 보내야** 「내 작업」 · 보드 열에 든다 | `pages_create.php` 에 `assignee` 처리 0(grep) · `work.js:2266-2272`(POST → PATCH status todo) · `routes.php:3211-3214 community_my_tasks_sql`(`assignee_id = me OR (assignee_id IS NULL AND CASE personal→owner ELSE author END = me AND (status IS NOT NULL OR due_date IS NOT NULL))`) |
| ② | 템플릿 **반복**으로 만든 작업이 「내 작업」에 뜨나 | **뜬다 — 규칙 주인의 「내 작업」에, 규칙이 상태나 기한을 주었을 때**. 예약 실행자 = 규칙 `owner_user_id` → 새 페이지 `author_id` = 규칙 주인 · 담당 0 · 상태 = 규칙 `status`(반복 창 기본 「할 일」) · 기한 = 만든 날 + `due_in_days`. 상태 「없음」 + 기한 없음이면 안 뜬다(①의 같은 규칙) — 반복 창의 기본값(할 일)을 바꾸지 않는다 | `scripts/community_automations.php:58`(`community_automation_execute($r,(int)$r['owner_user_id'] …)`) · `routes.php:5010-5033 community_automation_create_page`(INSERT `author_id = :user` = 실행자 · 담당 열 0 · `status` · `due_date`) · `:4982-4985`(plan — status · due) |
| ③ | 일반(비 DB) 템플릿을 프로젝트 행으로 만들 때 서버가 **받나** | **받는다(코드 읽기 — 실제 실행은 Q:T-47 에서)**: `template_id` 의 부모 제한은 `scope === 'database'` 일 때만(`template_db_mismatch`) · 부모가 DB 페이지면 새 페이지는 행 · 칸 값 합치기는 「행 템플릿이고 같은 DB」일 때만 → 개인 · 팀 · 사이트 템플릿은 **본문(과 제목 · 아이콘)만**, 요청의 `properties`(섹션)는 그대로 들어간다. `template_id` 와 함께 못 오는 키는 `doc` · `database` 뿐 — `properties` 는 된다. **「내 할 일」 빠른 추가(`my_tasks: true`)는 `template_id` 를 받지 않는다(400)** → 1차 범위 밖(업무 홈 · 내 작업의 「작업 추가」 줄은 손대지 않는다) | `pages_create.php:133-166`(판정 → DB 템플릿만 부모 고정) · `:117`(my_tasks 키 셋만) · `:137`(doc · database 금지) · `:488-504`(행 · 칸 값 합치기 조건 `scope==='database' && database_id === $rowDbId`) · `templates_list.php:61-73`(사이트 템플릿은 쓰기 자격자에게) |
| ④ | 미리보기에 쓸 **읽기 렌더러** | `public/assets/community/doc-render.js` 의 `renderDoc(doc, ctx)` — work.js 가 이미 import 해 작업 칸 설명에 쓴다. 미리보기 ctx 는 그 `readCtx` 꼴에서 지도만 빈 것: `{ child_titles:{}, link_titles:{}, file_titles:{}, attachment_titles:{}, attachment_page:null, synced:{}, embeds: pageData.embeds || {hosts:[],frames:false}, base, tbox_base: tboxBase, mention_labels:{}, db_views:{}, buttons:{} }` — 임베드 허용 목록은 프로젝트 페이지 응답의 `embeds` 를 쓴다(요청 +0 · 홈 창은 `/api/embed-domains` 를 따로 불렀다) | `work.js:2`(import) · `:1115-1127`(readCtx · `renderDoc(page.doc, readCtx)` · 실패 시 한 줄) · `templates.js:14 · 105-121`(홈 창 미리보기 — `renderDoc(previewing.doc, { embeds })`) · `pages_get.php:445`(`embeds`) |
| ⑤ | JS 부품 import 의 **`?v=` 규칙** | 커뮤니티 부품끼리의 상대 import(`from './x.js?v=<12자리>'`)는 **부품 전부의 내용 sha256 앞 12자리** 한 값을 쓴다 — 한 파일을 부르는 import 는 어디서 부르든 같은 문자열(모듈 두 벌 금지). 우리 부품 한 글자가 바뀌면 **부품 전부**의 `?v=` 가 바뀐다. 손으로 쓰지 않는다: 바꾼 뒤 `php scripts/community_asset_version.php`(쓰기 — 바뀐 파일 이름을 찍는다) → `--check` 0. 새 import(`work.js` 에 `automations.js`)도 같은 값으로 찍힌다. 정적 검사(`tests/community/asset_version.sh` · `release.sh --dry-run` · 카탈로그 `community.asset_version`)가 버전 없음 · 낡음 · 없는 파일을 잡는다. 그래서 수정 파일 목록 밖의 `public/assets/community/*.js` · `messenger.js` 에 **import `?v=` 줄만** 바뀌는 diff 가 생기는 것이 정상이다(보고서에 그 줄 수를 적는다) | `scripts/community_asset_version.php` 머리 주석 · `tests/community/asset_version.sh` ① ~ ⑥ |

## 바꿀 것

### A. 꺾쇠 둘 — 프로젝트 머리 · 섹션 끝 (DESIGN 2.38 「자리 둘」 · `page_view.php` · `work.js`)

- **머리**(`src/community/handlers/page_view.php:202-213` `.page-head__actions`): 검정 `#project-add-task` **바로 뒤**에 하나 더:
  ```html
  <details id="project-add-task-pick" class="menu menu--picker" hidden>
    <summary class="btn btn--icon btn--quiet" aria-label="템플릿 골라 작업 추가"><span class="icon" data-icon="chevron"></span></summary>
    <div class="menu__list"></div>
  </details>
  ```
  `hidden` 으로 시작 · 목록 안은 work.js 가 그린다(이 파일은 판단하지 않는다 — 머리 주석 그대로). `#project-add-task` 의 글자 · 클래스 · 동작 0 변경.
- **섹션 끝**(`work.js:2257-2281 drawProjectList` — `db.can_add_rows === true` 갈래 안): `actions`(`div.task-list__actions`)에 `add`(「작업 추가」) 다음으로 같은 모양의 `details.menu.menu--picker`(`summary.btn.btn--icon.btn--quiet.btn--small` · `aria-label` 「템플릿 골라 <섹션 이름>에 작업 추가」 · `data-add-section-pick="<g.value>"`)를 **createElement 로** 더한다. `add` 의 글자 · 속성(`data-add-section`) · 처리 0 변경 — 머리 「작업 추가」가 `[data-add-section]` 첫 것을 `click()` 하는 길(`:2002`)이 그대로여야 한다.
- **보이는 조건**: `drawProjectHead()`(`:2027` `show($('project-add-task'), project.db.can_add_rows === true)`) 바로 옆에 `show($('project-add-task-pick'), project.db.can_add_rows === true && canWrite())`. 섹션 끝은 `add` 와 같은 `if (db.can_add_rows === true)` 안. **역할 · 공간 비교 0**. `asDoc`(「문서로 보기」)이면 머리와 함께 숨는다(지금 `#project-head` 가 통째로 숨는다 — 추가 코드 0).
- **열기**: `details` 의 `toggle` 이벤트(열릴 때만)에서 `pickerOpen(menu, sectionValue)` — 머리는 `sectionValue = 첫 섹션의 값`(지금 「작업 추가」가 여는 `#project-list:not([hidden]) [data-add-section]` 첫 것의 값 · 목록 보기가 아니면 `''`), 섹션 끝은 그 `g.value`. 여닫기 · 밖 누름 · Esc · Tab 밖 · 항목 누르면 닫힘은 **app.js `initMenus` 한 곳**(2.15) — 새 닫기 코드 0.

### B. 목록 (DESIGN 2.38 「목록」)

- 프로젝트 하나에 `picker` 상태 하나(`enterProject` 에서 비움): `{ rows: null|[], general: null|[], bodies: {id: template}, loading: Promise|null, msg }`. `pickerOpen` 은 `rows === null` 일 때만 창구를 부른다 — **둘을 나란히**: `api('GET', '/api/templates?database=' + encodeURIComponent(project.db.id))` · `api('GET', '/api/templates')`. 401 → `toLogin()`. 그 밖 200 이 아니면 그 묶음은 `[]` 로 두고 `p.menu__hint` 에 `errorMessage(r.data)`(서버 문장 그대로 — 5장 「오류」). 「템플릿으로 저장」(D) 이 201 이면 `rows = general = null`(다음 열 때 다시).
- `drawPicker(menu, sectionValue)` — `menu__list` 를 `clear` 하고 createElement 로:
  1. 폰 머리 `div.menu__sheet-head` = `strong` 「템플릿 골라 작업 추가」 + `iconButton('x','닫기')`(누르면 `menu.open = false` · 초점 summary) — ≥960 은 CSS 가 숨긴다(F).
  2. `p.msg[role=alert][aria-live=polite]`(만들기 실패 문장 자리 — 처음엔 빈 글자 · CSS 가 빈 것을 접는다면 그대로, 아니면 `hidden` 토글).
  3. 「빈 작업」 `button.menu__item[type=button]` + 아이콘 `plus` — 누르면(app.js 가 먼저 닫는다) 머리면 `$('project-add-task').click()`, 섹션 끝이면 그 섹션의 `add.click()`(= `host.querySelector('[data-add-section="…"]')`) — **지금 「작업 추가」와 같은 그 자리 입력 줄** 그 자체(새 입력 코드 0).
  4. 템플릿 줄들: ② `rows`(창구 순서 그대로 · 범위 글자 「이 프로젝트」) → ③ `general` 을 **범위 묶음 순서 personal → team → site** 로(묶음 안은 창구 순서 · 거르기 0 — 묶음 순서만 화면이 정한다. 홈 창의 범위 글자와 같은 말: `personal` 「내 템플릿」 · `team` 「팀」(+ 창구가 준 `team` 이름이 있으면 「팀 · <이름>」) · `site` 「사이트 기본」). 줄 = `div.menu__row` > `button.menu__item[type=button][data-template-id=<id>][data-template-scope=<scope>]`(`(icon ? icon + ' ' : '') + name` 을 `span` 에 + `span.menu__scope` 범위 글자) + `button.btn.btn--icon.btn--plain.menu__look`(아이콘 `search` · `aria-label` `<name> + ' 미리보기'`). 글자는 전부 `textContent`(템플릿 이름 · 제목은 사람이 쓴 글자다).
  5. `rows` 와 `general` 이 합쳐 0 이면(둘 다 200 인데 비어 있음) 「빈 작업」 아래 `p.menu__hint` 「자주 쓰는 작업을 템플릿으로 저장하면 여기서 골라 쓸 수 있어요.」(한 문장 — 2.37 글 규칙 동작 + 결과). 불러오는 동안은 「빈 작업」만 보이고, 답이 오면 같은 메뉴를 다시 그린다(열린 채).
  6. `hr.menu__sep` + 「지금 작업을 템플릿으로 저장」 `button.menu__item[aria-haspopup=dialog]` — `projectPane.current()` 가 있고 그 칸이 마지막으로 받은 창구 답(`pane` 이 기억하는 `data`)의 `template_scopes` 가 비어 있지 않을 때만(아니면 `hidden` — 2.15 비었을 때 규칙대로 구분선도 숨는다). 누르면 D 의 같은 `deps.templateSave.open(id, data)`.
  7. `section.menu__preview[role=region][aria-label="템플릿 미리보기"]` — 처음엔 `hidden`.
- **거르기 · 역할 비교 0**: 목록은 창구 답 그대로(`can_delete` 등 다른 값은 쓰지 않는다 — 지우기는 이 메뉴에 없다 · 홈 창 · DB 화면 그대로).

### C. 미리보기 · 만들기 (DESIGN 2.38 「미리보기」 · 「고르기 → 만들기」)

- **미리보기 트리거**: 줄의 `menu__item` 에 `focusin` · `pointerenter`(`matchMedia('(hover: hover) and (pointer: fine)')` 일 때만 · 150ms 타이머 · 떠나면 취소) · `menu__look` 의 `click`(모든 기기 — 폰의 길). `preview(t)`: `bodies[t.id]` 가 없으면 `api('GET', '/api/templates/' + t.id)` 한 번(201 아님 → 401 `toLogin` · 그 밖은 `menu__preview` 안 `.msg` 에 `errorMessage(r.data)`) → `menu__preview` 를 다시 그린다(`hidden = false`):
  - 머리 `div.toolbar`: `strong`(아이콘 + 이름) + `span.muted`(범위 글자) · 줄 `p.muted` 「제목: <template.title>」.
  - 행 템플릿(`scope === 'database'`)이면 `p.muted` 「미리 채움: <칸 이름> <값> · …」 — `template.properties` 의 키 가운데 `project.db.schema` 에 **있는** 칸만(`DbProps` 같은 함수 없음 — 스키마 배열 `find(p => p.key === k)`), 선택 값은 `optionLabel(prop, v)`, 다중 선택은 `optionLabel` 을 `·` 로 잇기, 날짜 · 글 · 숫자 · 체크는 `String(v)`(체크는 「예/아니요」). 없으면 이 줄 0. 섹션 칸(`section`)의 값은 **누른 자리로 바뀐다**는 뜻을 같은 줄 끝에: 「(섹션은 누른 자리 <섹션 이름>)」 — 그 자리가 `''`(섹션 없음)이면 생략.
  - `p.muted` 「상태 · 담당 · 기한은 만든 뒤 작업 상세에서 정해요.」(WT-8 — 늘).
  - 본문 `div`(새 클래스 0 — 2.38 새 클래스 일곱 밖의 이름을 만들지 않는다) 하나에 `renderDoc(template.doc, previewCtx)` 결과를 붙인다 — 렌더러가 제 클래스(2.14 문서 토큰)를 단다. `previewCtx` 는 ④ 그대로(한 번 만들어 재사용). 렌더러가 throw 하면 `p.muted` 「본문을 그릴 수 없어요 — 전체 화면으로 열어 보세요.」(작업 칸과 같은 문장). 빈 본문(`blocks` 0 또는 글자 0)이면 `p.muted` 「본문이 비어 있어요.」
  - 바닥 `div.toolbar`: `textButton('이 템플릿으로 만들기', 'btn btn--quiet btn--small')` → `make(t)` · `textButton('이 템플릿으로 매주 만들기', 'btn btn--quiet btn--small')`(`canWrite()` 일 때만) → E.
  - `aria-pressed`: 미리보기 중인 줄의 `menu__look` 에 `aria-pressed="true"`(다른 줄은 false).
- **만들기** `make(t, sectionValue)` — 줄 `menu__item` 의 `click`(app.js 가 먼저 닫는다 — 그 뒤 우리 처리) 과 「이 템플릿으로 만들기」(이때는 `menu.open = false` 를 우리가 하고 초점은 summary 로) 가 같은 함수:
  ```js
  const payload = { parent_id: project.db.page_id, template_id: t.id };
  if (gp && sectionValue !== '') { payload.properties = {}; payload.properties[gp.key] = sectionValue; }
  api('POST', '/api/pages', payload).then((r) => {
    if (r.status !== 201) { throw new Error(failMessage(r)); }
    return api('PATCH', '/api/pages/' + r.data.page.id + '/properties', { status: 'todo' }).then(() => r.data.page.id);
  }).then((id) => Promise.resolve(ctx.reload()).then(() => { if (projectPane) { projectPane.open(id, { push: true }); } }))
    .catch((err) => say(pickerMsg, err && err.message ? err.message : genericError));
  ```
  `gp` · `ctx` 는 `drawProjectList` 의 그것(섹션 칸 = `group_by` 가 정한 select 칸 · 기본 `section`) — 머리 꺾쇠도 **마지막으로 그린 목록의 `gp` · `ctx`** 를 쓴다(프로젝트 상태 `project` 에 `lastList = {gp, ctx, host}` 로 기억 — 목록 보기가 아니면(보드 · 달력) `properties` 0 · `ctx` 는 `project` 자신 — `project.reload()`). 제목은 보내지 않는다(서버가 템플릿 제목). 두 번 누름 방지: 요청 중엔 그 메뉴의 단추 전부 `disabled`(끝나면 되돌림). 실패 문장은 `pickerMsg`(목록 위 `.msg`)와 `#db-msg` 둘 다가 아니라 **`#db-msg` 하나**(메뉴는 이미 닫혔다 — `say($('db-msg') || msgEl(), …)`).
- **「빈 작업」** 은 B 3 — `make` 를 지나지 않는다.

### D. 작업 상세 더보기 「템플릿으로 저장」 (G-2 · DESIGN 2.38 「작업 상세 더보기」 · 2.21)

- `templates.js createTemplateSave(d)`(`:220-289`) — 지금 `button.addEventListener('click', …)` 의 본문을 `openDialog()` 로 떼고 단추는 그것을 부른다. 돌려주는 객체에 **`open(id, data)`** 하나 더: `pageId = id · title = data.page.title · scopes = data.template_scopes.filter(SCOPE_LABELS)` 를 세운 뒤(`#page-save-template` 의 `hidden` 은 건드리지 않는다 — 그것은 `draw` 의 몫) `openDialog()`. `scopes.length === 0` 이면 아무 일도 하지 않는다(화면이 범위를 따지지 않는다 — 창구 값이 비면 입구가 없다). `dialog` 가 없는 화면(업무 홈 · 내 작업 — `#template-save-dialog` 는 `page_view.php` 에만)에서는 지금처럼 `{ draw: () => {} }` 를 돌려주되 `open` 을 **넣지 않는다**(work.js 가 `typeof deps.templateSave?.open === 'function'` 으로 항목을 숨긴다).
- `editor.js:8731-8738` `createWork({...})` 에 `templateSave: templateSave` 한 줄(`:5674` 에서 이미 만든 그것 — 새로 만들지 않는다 · 창은 한 벌).
- `work.js createPane draw`(`:858-890` 더보기): `subItem` 다음 · `sep` 앞에 `tplItem = el('button', 'menu__item', '템플릿으로 저장')`(`type=button` · `aria-haspopup=dialog` · `data-task-save-template`) — `hidden = !(canWrite() && Array.isArray(data.template_scopes) && data.template_scopes.length > 0 && deps.templateSave && typeof deps.templateSave.open === 'function')`. 누르면 `deps.templateSave.open(id, data)`. `full`(전체 화면 작업 — `body[data-page-kind=task]` 가 페이지 머리를 숨겨 `#page-save-template` 에 닿을 수 없다) 에서도 같은 항목. 범위 고르개 항목은 창구가 준 `template_scopes` 뿐(templates.js 그대로).
- 저장 뒤(templates.js `confirm` 201 갈래): 알림 문장 옆에 `button.btn.btn--quiet.btn--small` 「이 템플릿으로 매주 만들기」(`data-template-repeat`) — 창을 닫고 `repeatDialog(d.api, null, { template: r.data.template, parent: d.repeatParent ? d.repeatParent() : null })`. `repeatParent` 는 선택 의존성(editor.js 가 넘긴다: 프로젝트 화면이면 `() => ({ id: 프로젝트 페이지 public_id, title: 프로젝트 제목 })`, 아니면 `null`) — work.js 가 `project` 를 알므로 editor.js 는 `work.repeatParent` 같은 함수를 묻거나, 더 단순하게 **work.js 가 `deps.templateSave.open(id, data, { repeatParent })`** 로 넘기고 templates.js 가 그 값을 `confirm` 갈래까지 기억한다 — 둘 중 하나로 하고 보고서에 적는다. `canWrite` 가 아니면 단추 0(창은 어차피 쓰기 자격자에게만 열린다 — `template_scopes` 가 `$mayWrite` 를 이미 안다 · `pages_get.php:270`). 다음에 창을 열면 단추는 사라진다(지금처럼 `confirm.disabled = false` 되돌릴 때 함께).

### E. 「이 템플릿으로 매주 만들기」 (G-3 · DESIGN 2.38 「반복 창」 · WT-7 B)

- 입구 둘: C 의 미리보기 바닥 · D 의 저장 직후. 둘 다 **기존 `repeatDialog(api, null, { template, parent })`**(`automations.js:97`) — 새 창 0. work.js 에 `import { repeatDialog } from './automations.js?v=…';` 한 줄(⑤ 로 버전을 찍는다).
- `automations.js repeatDialog`(`:108-110`) — `data.parent`(`{id, title}` · 없으면 지금과 같다) 를 **일반 템플릿**(`template.scope !== 'database'`)일 때만 쓴다: `parentChoices` 맨 앞에 `[data.parent.id, data.parent.title]` 을 넣고 `select(parentChoices, data.parent.id)` 로 기본 선택 · 뒤 비동기 `parents` 합치기(`:156-185`)는 그대로(`known` 에 이미 있어 두 번 붙지 않는다). 행 템플릿은 「이 템플릿의 데이터베이스」 고정 그대로(서버가 부모를 그 DB 로 고정 — `routes.php:4958-4961`). 다른 칸(주기 · 요일 · 시각 · 제목 틀 · 상태 「할 일」 · 기한 n일 · 알림)은 **한 글자도** 바꾸지 않는다(②가 기댄다).
- `parent.title` 은 「이 프로젝트 · <프로젝트 제목>」(textContent). 「시험 실행」 → 「켜기」 흐름 · 문장은 지금 그대로. 서버가 거부하면(`parent_denied` · `template_scope` …) 지금 문장 표가 보인다.

### F. 모양 — `app.css` 의 `.menu` 규칙 묶음(`.menu:not(:has(.menu__item:not([hidden])))` 줄 · `:379`) **바로 뒤**에 한 묶음(폰 시트 `@media` 도 그 자리 · 옛 줄 0 변경) · 새 클래스 일곱 (DESIGN 2.38 「새 클래스 · 토큰」)

- `.menu--picker > .menu__list { width: min(44rem, calc(100vw - 32px)); padding: 6px; }` + ≥960: `display: grid; grid-template-columns: minmax(14rem, 18rem) minmax(0, 1fr); gap: 6px 12px;` — 목록 쪽(`.menu__row` · 「빈 작업」 · `.menu__hint` · `.menu__sep` · 「지금 작업을…」)은 첫 칸, `.menu__preview` 는 둘째 칸 전체 세로(`grid-row: 1 / -1`). `@supports (anchor-name…)` 안의 지금 `position-area` · `position-try-fallbacks` 는 그대로 받는다(창 안에 머묾 — 2.15).
- `.menu__row { display: flex; align-items: center; gap: 2px; }` · `.menu__row > .menu__item { flex: 1 1 auto; width: auto; min-width: 0; }` · `.menu__row > .menu__item > span:first-child { overflow: hidden; text-overflow: ellipsis; }`.
- `.menu__scope { margin-left: auto; font-size: 12px; color: var(--muted); white-space: nowrap; }`.
- `.menu__look { flex-shrink: 0; }`(32px 아이콘 단추 그대로 · 폰 · 터치는 `app.css` 끝 터치 규칙 ①이 `.btn--icon` 을 44 로 넓힌다 — 새 선택자 필요 없는지 **확인**하고 필요하면 그 규칙 선택자에 `.menu__look` 을 더한다 · 4장 ①).
- `.menu__hint { padding: 6px 10px; font-size: 13px; color: var(--muted); white-space: normal; }`.
- `.menu__preview { display: flex; flex-direction: column; gap: 8px; min-width: 0; max-height: min(60vh, 32rem); overflow-y: auto; padding: 6px 8px; border-left: 1px solid var(--line); }` · 안의 본문 `div` 는 `font-size: 13px`(문서 토큰은 렌더러 클래스가).
- `.menu__sheet-head { display: none; }`.
- `@media (max-width: 959.98px)`: `.menu--picker > .menu__list { position: fixed; inset: auto 0 0 0; width: auto; max-width: none; max-height: 80dvh; margin: 0; border-radius: var(--r-card) var(--r-card) 0 0; display: flex; flex-direction: column; overflow-y: auto; }` · 앵커 끄기(`@supports` 안에서 `position-anchor: none; position-area: none; position-try-fallbacks: none; inset: auto 0 0 0;` — 지원하지 않는 값이면 보고서에 적고 `inset` 만으로) · `.menu__sheet-head { display: flex; align-items: center; justify-content: space-between; padding: 4px 4px 4px 10px; }` · `.menu__preview { border-left: 0; border-top: 1px solid var(--line); max-height: none; }`.
- 새 줄에는 `var(--x)` 만(대체 색값 · 새 색값 · 숫자 `z-index` 0). `.menu__list` 의 `z-index: 40`(앵커 지원 브라우저는 그 블록의 값) 그대로 — 폰 시트가 작업 칸(`aside.task-pane`) · 메신저(`--z-floating`) 와 겹치는 층은 지금 메뉴와 같다(겹치면 보고서에 사진).
- `docs/design-guide.html` 은 손대지 않는다(살아 있는 견본 갱신은 메인이 묶음 끝에 — 보고서에 「미갱신」 한 줄).

### G. 글 — 화면 글자 표 (5장 낱말 표 · 2.37 글 규칙 · 바꾸면 보고서에)

| 자리 | 글자 |
|---|---|
| 꺾쇠 `aria-label` | 머리 「템플릿 골라 작업 추가」 · 섹션 끝 「템플릿 골라 <섹션 이름>에 작업 추가」 |
| 폰 시트 머리 | 「템플릿 골라 작업 추가」 · ✕ 「닫기」 |
| 첫 항목 | 「빈 작업」 |
| 범위 글자 | 「이 프로젝트」 · 「내 템플릿」 · 「팀」(「팀 · <이름>」) · 「사이트 기본」 |
| 미리보기 단추 | `aria-label` 「<이름> 미리보기」 |
| 빈 상태 | 「자주 쓰는 작업을 템플릿으로 저장하면 여기서 골라 쓸 수 있어요.」 |
| 미리보기 안 | 「제목: <제목>」 · 「미리 채움: <칸> <값> · …」 · 「상태 · 담당 · 기한은 만든 뒤 작업 상세에서 정해요.」 · 「본문이 비어 있어요.」 · 「본문을 그릴 수 없어요 — 전체 화면으로 열어 보세요.」 |
| 미리보기 바닥 | 「이 템플릿으로 만들기」 · 「이 템플릿으로 매주 만들기」 |
| 메뉴 끝 | 「지금 작업을 템플릿으로 저장」 |
| 작업 상세 더보기 | 「템플릿으로 저장」(페이지 더보기와 같은 글자) · 저장 뒤 「이 템플릿으로 매주 만들기」 |
| 반복 창 「만들 자리」 기본값 | 「이 프로젝트 · <프로젝트 제목>」 |
| 금지 | 「양식」(틀 뜻으로) · 「서식」 · 「폼」 · 「템플릿 선택」 · 「취소」 · 「확인」 · 역할 이름 · 개발자 말(창구 · 판정) |

오류 문장은 전부 서버가 준 것(`errorMessage(r.data)` · `failMessage(r)`) — 화면이 짓지 않는다. 성공 알림은 작업 칸이 열리는 것으로 충분(성공 창 0 · 4장).

## 바꾸지 않는 것 (이 단계 밖 — 손대지 않는다)

- 서버 전부: `src/community/handlers/*`(templates_list · templates_get · templates_create · pages_create · pages_get · my_tasks · automations_*) · `routes.php`(`COMMUNITY_TEMPLATE_PROP_TYPES` · `community_template_out` · `community_automation_plan` · `community_my_tasks_sql`) · `CommunityAuthz`(decideTemplateRead · Save · Delete) · `db/**` · `COMMUNITY_API_TOKEN_ROUTES`. 새 창구 0 · 새 라우트 0 · 감사 문장 0.
- 꺾쇠 없이 누른 「작업 추가」(머리 `#project-add-task` · 섹션 끝 `[data-add-section]`) 의 글자 · 선택자 · 그 자리 입력 줄(`addLine`) · Enter 뒤 같은 칸 다시 열림 · 업무 홈 · 내 작업의 「작업 추가」 줄(`:468-475` · `:1694-1704` · `quickAdd` — `my_tasks: true` 는 `template_id` 를 받지 않는다 ③) · 「새 작업」 창(`openNewTask`).
- 홈 「템플릿에서」 창(`bindTemplateGallery`) · DB 화면 새 행 고르개(`createRowTemplatePicker` — 프로젝트 화면에서는 `#db-add-row` 가 숨어 있다) · 그 안의 「반복 만들기」 단추 · 템플릿 지우기 — 그대로.
- 반복 창의 칸 · 기본값 · 문장(「만들 자리」 기본값 하나만 E) · 자동 작업 화면(`/community/automations` 「규칙」 탭 링크 `:1939`).
- 2차 · 3차(기획 6장): MCP `template_id`(G-4 · WT-6) · 지도 블록(G-5) · 사이트 기본 템플릿 추가(WT-5 A — 시드 0) · 템플릿에 담당 · 상태(WT-8 B 아님) · 업무 홈 · 내 작업 칸의 「템플릿으로 저장」(창 없음) · 「내 할 일」에 템플릿 · 작업 더보기의 상시 「매주 만들기」(서버에 작업 → 템플릿 고리 없음) · 「페이지 → 문서」 낱말.
- DESIGN 2.15 의 지금 `.menu` 줄 · app.js `initMenus`(닫기 · 초점 · Esc 한 곳).

## 수락 시험 — 사이트 PRD 9장 Q:T-44 ~ Q:T-50 (기획 8장 초안을 확정 · 격리 · claude_ 계정 · UA `tbox-review/<날짜>` · 운영 0)

번호는 지금 마지막 `Q:T-43`(Q:F-12 D-2 — `qf12_work_browser.py`) 다음부터. PRD 9장 등록은 메인이 커밋 때(이 지시서가 정답지).

준비(두 시험 공통 — 창구로 만든다 · 창구가 막히면 합성 DB SQL · 보고서에): 팀 공간 프로젝트 P(employee · `POST /api/pages {database:true, preset:'project', space_id: 팀 공간, title:'주간 업무'}`) · 개인 공간 프로젝트 P2(employee) · P 의 행 템플릿 T-row(P 의 작업 하나에 섹션 「01. 진행」 + 본문(할 일 블록 · 표 블록) → `POST /api/templates {scope:'database'}`) · employee 개인 템플릿 T-me · other 의 개인 템플릿 T-other(other 의 개인 공간 문서에서) · 팀 템플릿 T-team(P 의 작업 하나 → `scope:'team'`) · 사이트 기본 「회의록」(마이그레이션 시드 `seed_key` — 합성 DB 에 있음을 `GET /api/templates` 로 확인) · P 를 other 에게 **보기 공유**(`PUT /api/pages/{P}/shares/claude_employee02 {level:'view'}`) · 모든 합성 계정 `usage_guides` dismissed(제안 창이 덮지 않게 — 옛 시험 `project_sections.py:42` 와 같은 방식).

```
[http]    tests/community/qf16_templates_http.py — memories_harness suite (진짜 PHP · 합성 DB · 브라우저 0)
  [list] Q:T-45
  ① employee: GET /api/templates?database=<P.db> 200 → T-row 하나(scope database · database = P.db) · GET /api/templates 200 → T-me · T-team · 「회의록」 있음 · T-other 0 · T-row 0(일반 목록엔 행 템플릿이 없다)
  ② other(보기 공유): GET /api/pages/{P} 200 · database.can_add_rows 거짓 · GET /api/templates?database=<P.db> 403 · 감사 표 audit_logs 에 action='template.list' AND result='deny' 1 (SQL — 시험 시작 전 수와 대조)
  ③ guest: GET /api/templates?database=<P.db> 404 또는 403(지금 판정 그대로 — 값을 바꾸지 않고 기록) · 세션 없음 401
  [make] Q:T-46 · Q:T-47
  ④ employee: POST /api/pages {parent_id:P, template_id:T-row, properties:{section:'02. 마무리'}} 201 → GET 그 페이지: row.values.section = '02. 마무리'(누른 자리가 이김 · WT-3) · 본문 블록 수 = T-row 본문 · 제목 = T-row title · 담당 null · 상태 null → PATCH …/properties {status:'todo'} 200 → GET /api/my-tasks 에 그 줄(① 규칙 — 작성자 = 나 · 담당 없음 · 상태 있음)
  ⑤ employee: POST /api/pages {parent_id:P, template_id:<「회의록」 id>, properties:{section:'01. 진행'}} 201 → row.values.section='01. 진행' · 그 밖 칸은 기본(비어 있음) · 본문 = 회의록 본문(블록 수 · 첫 블록 종류) — WT-4 B 「본문만」 ③ 실제 동작 (Q:T-47)
  ⑥ employee: T-me(개인) · T-team(팀) 도 ⑤ 와 같이 201 · 본문만 / other 의 팀(b)에서 저장한 팀 템플릿을 P(팀 a)에 → 403 또는 400(지금 판정 그대로 기록 — 값 변경 0)
  ⑦ employee: POST /api/pages {parent_id:P, template_id:T-other} → 403 · audit_logs action='template.read' result='deny' +1 · P 의 행 수 변동 0 (Q:T-46)
  ⑧ employee: POST /api/pages {parent_id:P, template_id:T-row, doc:{…}} 400 · {my_tasks:true, title:'x', template_id:T-row} 400(③ — 「내 할 일」은 받지 않는다) · T-row 를 P2 에 → 400 template_db_mismatch · CSRF 없음 403
  [repeat] Q:T-49
  ⑨ employee: POST /api/automations {kind:'recurring', name:'주간재료비 보고 반복', scope_page_id:null, trigger:{type:'schedule', freq:'weekly', weekday:<오늘 ISO 요일>, time:<지금 서울 시각에서 10분 전을 10분 단위로 내린 HH:M0>}, actions:[{type:'create_page', source:{template:T-row}, parent:null, title_pattern:'주간재료비 보고 — {YYYY}년 {주}주', status:'todo', due_in_days:0}, {type:'notify', to:'owner', text_key:'recurring_created'}]} 201 → POST …/dry-run 200 status ok · would_create[0].title = 「주간재료비 보고 — <올해>년 <ISO 주>주」 · parent_title = P 제목 → PATCH {is_enabled:true} 200
     → php scripts/community_automations.php(cwd = 인스턴스 · `--now` 0 — 이 틀의 DB 이름은 `--now` 를 받지 않는다 · 방금 지난 슬롯을 잡는다) → P 의 행 +1 · 제목 위 · section = T-row 값 · status todo · due = 오늘 · author = employee · 담당 null → **GET /api/my-tasks(employee) 에 그 줄**(② 확인 — 「오늘」 묶음) · 두 번째 실행은 +0(같은 슬롯)
  ⑩ 같은 규칙을 일반 템플릿(T-me)과 parent = P 로 → dry-run ok · parent_title = P 제목 · 실행 뒤 행 +1 · 본문만(section 비어 있음) — E 의 「만들 자리」 기본값이 서버에 통하는지
  [static] Q:T-50 (일부)
  ⑪ scripts/security_lint.sh 0 · php -l page_view.php · node --check 바꾼 JS 전부 · php scripts/community_asset_version.php --check 0 · bash tests/community/asset_version.sh 통과 ·
     work.js · templates.js · automations.js 새 줄에 innerHTML · insertAdjacentHTML · 'Admin' · 'Guest' · role 비교 · /community/ 글자 0 · app.css 새 줄에 #색값 · rgb( · 숫자 z-index · style= 0 ·
     DESIGN 2.38 「새 클래스」 일곱 밖의 새 클래스 0(바꾼 CSS 의 선택자 집합을 센다) · 글자 표 G 의 금지 낱말 0(「양식」은 보기 탭 글자 한 곳만 — 지금 수와 같음)

[browser] tests/community/qf16_templates_browser.py — memories_harness suite (진짜 화면 · 합성 DB · Chromium)
  [pick] Q:T-44
  ① employee 1440 /community/p/<P>: 머리 `#project-add-task` 검정 하나 + 바로 오른쪽 `#project-add-task-pick > summary[aria-label="템플릿 골라 작업 추가"]` 보임 · 머리 검정 단추 1 · 머리 단추 ≤ 4 ·
     각 섹션 끝 「작업 추가」 옆 `details.menu--picker` 하나 · 열기 전 네트워크에 /api/templates 요청 0 · 열면 요청 **둘**(?database= · 일반) 한 번씩 · 다시 열어도 +0 ·
     목록 순서: 「빈 작업」 → T-row(「이 프로젝트」) → T-me(「내 템플릿」) → T-team(「팀」) → 「회의록」(「사이트 기본」) · 각 줄에 「<이름> 미리보기」 단추 · 「지우기」 0 · 검정 단추 0 · 메뉴 안 `.menu__item` 글자에 역할 이름 0
  ② T-row 줄에 초점 → `section.menu__preview` 보임 · GET /api/templates/<T-row> 1(다시 초점 +0) · 「제목: …」 · 「미리 채움: 섹션 01. 진행 (섹션은 누른 자리 <첫 섹션>)」 · 「상태 · 담당 · 기한은 …」 · 본문에 할 일 · 표 블록(렌더러 클래스) · 바닥 「이 템플릿으로 만들기」 · 「이 템플릿으로 매주 만들기」 둘 다 테두리
  ③ 머리 꺾쇠에서 T-row 줄 누름 → 메뉴 닫힘 · POST /api/pages 본문이 정확히 {parent_id:P, template_id:T-row, properties:{section:<첫 섹션 값>}} · 이어 PATCH {status:'todo'} · 목록 다시 · 새 줄이 **첫 섹션**에 · 오른쪽 칸(aside#task-pane)에 그 작업 열림(?task=) · 칸의 설명 = 미리보기와 같은 블록 수 · 담당 「담당 없음」 · 상태 「할 일」 · 초점은 칸 머리
  ④ 섹션 「02. 마무리」 끝 꺾쇠에서 「회의록」 누름 → properties.section = '02. 마무리' · 새 줄이 그 섹션에 · 칸 설명 = 회의록 본문(Q:T-47 화면 쪽) · 「미리 채움」 줄 0(일반 템플릿)
  ⑤ 「빈 작업」 누름(머리 · 섹션 끝 각각) → 지금과 같은 `.task-add__input`(aria-label 「<섹션>에 작업 추가」) 이 서고 /api/templates 요청 +0 · Enter 로 저장 → 같은 칸 다시 열림(2.20 그대로) · **꺾쇠를 안 누르고** 머리 「작업 추가」 · 섹션 「작업 추가」 누름 → 지금과 같은 동작(요청 목록에 templates 0)
  ⑥ 빈 상태: 템플릿이 하나도 없는 프로젝트 P2(개인 공간 · 개인 템플릿도 지운 문맥 — 또는 other 계정의 새 프로젝트)에서 열면 「빈 작업」 + `.menu__hint` 「자주 쓰는 작업을 …」 · 「지금 작업을 템플릿으로 저장」 은 칸이 닫혀 있으면 0 · 칸에 작업을 열면(template_scopes 있음) 보임 → 누르면 `#template-save-dialog[open]`
  ⑦ 만들기 실패: 목록을 그린 뒤 합성 DB 에서 T-me 를 지움(DELETE /api/templates) → 줄 누름 → 404 문장이 `#db-msg` 에 서버 문장 그대로 · 행 +0
  [share] Q:T-45
  ⑧ other(보기 공유) 1440 /community/p/<P>: 프로젝트 화면이 열리고 「작업 추가」 0 · `#project-add-task-pick` hidden · 섹션 끝 꺾쇠 0 · 이 화면 동안 /api/templates 요청 0 · 작업 칸 더보기에 「템플릿으로 저장」 0 / guest 도 같음
  [save] Q:T-48
  ⑨ employee: 작업 칸 더보기 → 「템플릿으로 저장」(하위 작업 더하기 아래 · 구분선 위) → `#template-save-dialog[open]` · 범위 라디오 = 창구 답 template_scopes 와 **같은 집합 · 같은 순서**(employee · 팀 공간 행: personal · team · database — site 0) · 이름 기본값 = 작업 제목 · 「저장」 → 201 · 알림 문장 · 옆에 「이 템플릿으로 매주 만들기」 보임 ·
     창을 닫고 꺾쇠를 다시 열면 목록에 새 템플릿(요청 다시 둘) / manager(팀장 · P 에 편집 공유) 에서도 라디오 = 그 계정의 template_scopes / 전체 화면 작업(/community/p/<작업>)의 더보기에도 같은 항목 · 같은 창 / 업무 홈 · 내 작업의 칸 더보기에는 항목 0
  [repeat] Q:T-49 (화면 쪽 — 실행은 http ⑨)
  ⑩ 미리보기 바닥 「이 템플릿으로 매주 만들기」(T-row) → 메뉴 닫힘 · `dialog.modal[open]` 「반복·버튼 작업 만들기」 · 「작업」 고르개 = 「정해진 시각 반복」 하나 · 「만들 자리」 = 「이 템플릿의 데이터베이스」 · 「시험 실행」 → 「만들 문서: …」 · 「켜기」 활성 / T-me 에서는 「만들 자리」 **기본 선택 = 「이 프로젝트 · 주간 업무」**(첫 항목) · 저장 직후 단추(⑨)에서 열어도 같다
  [layout] Q:T-50
  ⑪ 360 · 412(터치 · 마우스) · 820 · 960 · 1280 · 1440 × 밝게 · 어둡게 + 고대비 한 번: 꺾쇠 열림 — <960 은 시트(`position: fixed` · bottom 0 · 폭 = 화면 폭 · 높이 ≤ 80dvh · `.menu__sheet-head` 보임 · 미리보기는 「미리보기」 단추를 누르기 전 hidden · 누르면 목록 아래) · ≥960 은 두 칸(미리보기가 목록 오른쪽 · 목록 전체가 화면 안 · 작업 칸 · 메신저 창과 겹친 넓이 0) ·
     가로 넘침 0 · 콘솔 오류 0 · 누름 자리 44(폰 · 터치 — 줄 · 미리보기 단추 · ✕ · 보이는 상자 가운데 · 끝을 눌러도 자기 자신 — dbg_u2 규칙) · 인라인 style 0 · 메뉴 안 검정 0 · 창 바닥(저장 창 · 반복 창) 2.7 그대로 ·
     스크린샷(PC 두 칸 미리보기 · 폰 시트 목록 · 폰 시트 미리보기 · 작업 칸 더보기 · 저장 뒤 「매주 만들기」 · 반복 창 「만들 자리」) — 결과 폴더 shots/
```

카탈로그(`tests/catalog.json` — `community.qf12_work_browser` 항목 꼴 그대로): `community.qf16_templates_http`(`cmd`: `python3 tests/community/memories_harness.py --port 8768 --suite qf16_templates_http.py --output /tmp/qaredi-qf16-templates-http` · `env: temp-instance` · `status: required` · `needs: []`) · `community.qf16_templates_browser`(`--suite qf16_templates_browser.py --output /tmp/qaredi-qf16-templates-browser` · `needs: ["browser"]`). `covers` 에 바꾼 파일 여섯 + `src/community/handlers/templates_list.php` · `templates_get.php` · `templates_create.php` · `pages_create.php` · `pages_get.php` · `my_tasks.php` · `automations_save.php` · `automations_dry_run.php` · `scripts/community_automations.php` · `src/community/routes.php` · 시험 파일 자신 · `tests/community/memories_harness.py` · `memories_http.py` · `memories_fixture.php`. `requirements` 에 Q:T 번호. `tests/community/memories_harness.py` 의 `--suite` choices 에 두 이름.

## 기대값만 바꿀 옛 시험 · 안 깨지는 것

- **고칠 옛 시험: 없어야 한다.** 꺾쇠는 `#project-add-task` 와 `[data-add-section]` 의 **옆**에 더해지고 그 둘의 글자 · 속성 · 동작은 그대로다. 아래 둘을 **고치지 않고** 돌려 확인한다(완료 기준):
  - `tests/community/qf12_work_browser.py`(`QF12D_ONLY=u1`) — Q:T-42 「머리 단추 ≤ 4 · 검정 ≤ 1」: 프로젝트 머리는 프로젝트 문서(조건부) · 공유 · 작업 추가 · **꺾쇠** = 4. 넘으면(그 시험이 `summary` 도 세면) 시험을 고치지 말고 보고서 「다르게 한 것」에 수와 선택자를 적는다 — 메인이 판단.
  - `tests/community/project_sections.py` — 섹션 머리 · 관리 단추 · 그 자리 추가.
- 안 깨지는 것으로 확인(손대지 않는다): `tests/browser/dbg_u2_ui.py`(창 바닥 셈 — 새 창 0 · 메뉴 안 검정 0 · 누름 자리 44 — [layout] 에서 같은 규칙으로 한 번 잰다) · `tests/community/browser_7b.py`(홈 「템플릿에서」 창 · 페이지 「템플릿으로 저장」 — `createTemplateSave` 의 `draw` · 단추 동작 그대로) · `browser_9_automation_ui.py`(반복 창 — `data.parent` 없을 때 지금과 같다) · `task_parent_browser.py` · `qf12_u4_browser.py`(전체 화면 작업 더보기 — 항목 하나 더해짐 · 기존 항목 순서 그대로). 이 밖에 grep(`project-add-task` · `data-add-section` · `menu__item` 수 · `template-save`)으로 찾은 단언이 바뀐 수 때문에 깨지면 그 줄만 고치고 보고서에 전 → 후.

## 되돌릴 수 없는 것 (보고서 「못 한 것」 옆에 그대로 적는다)

- 없다 — 표 · 창구 · 마이그레이션 변경 0. 되돌리면 코드만 되돌린다. 사람이 그 사이 만든 작업 · 템플릿 · 반복 규칙은 지금 화면(홈 「템플릿에서」 · 자동 작업 화면)에서 그대로 보이고 지울 수 있다.

## 반영 · 발행 (메인)

- 백업 규칙(DB + 코드 · 서버 최근 3개) — DB 변경 0 이라 마이그레이션 0 · 모의 적용 0. 코드 반영 + php-fpm reload(뷰 한 줄) · `?v=` 가 바뀐 부품은 nginx 7일 캐시를 새 주소로 비킨다(⑤).
- 운영 확인 한 번(`claude_` 계정 · UA 표식): 프로젝트 화면 꺾쇠 → 목록 둘 요청 · 미리보기 · 「빈 작업」 = 지금 줄 · 보기 공유 계정 꺾쇠 0 · 작업 칸 「템플릿으로 저장」 창의 라디오 = 창구 값 · 반복 창 「만들 자리」. **만들기 · 저장 · 켜기는 운영에서 하지 않는다**(격리 수락이 잼) — 관문 ③ 에서 사용자가 「주간재료비 보고」 템플릿을 직접 저장하고 매주 금요일 반복을 건다(기획 9장).
- 밤 회귀 대기열(`~/.cache/qaredi-check/nightly/queue/<날짜>-qf16.json`): `community.qf12_work_browser`(전체) · `community.browser_7b` · `community.stage89_ui`(반복 창 `browser_9_automation_ui.py` 가 이 묶음 안) · `community.qf12_u4_browser` · `tbox.dbg_u2_ui` · `community.asset_version` · `community.stale_modules` — 고른 목록 · 뺀 이유를 대기열 파일에. `project_sections.py` · `task_parent_browser.py` 는 카탈로그에 없다(격리 틀 `--suite` 로만) — 반영 전 최종 판에서 메인이 한 번 직접 돌리거나 카탈로그에 등록한다(보고서에 적는다).
- 기록: SECURITY 7장 「Q:F-16 1차 — 서버 변경 0 · 화면 입구 셋 · 목록은 행을 더할 수 있는 사람에게만(창구 값) · 남의 템플릿 403 + 감사 그대로(Q:T-46 실행 증거) · 회귀: 밤 회귀 대기(목록)」 · 사이트 STAGES · PRD 9장 Q:T-44 ~ 50 등록 · 위키 `wiki/qaredi` · `php scripts/wiki_check.php` 0 · `docs/design-guide.html` 2.38 견본.
- 발행 minor 한 번 — 제목 「프로젝트에서 템플릿 골라 작업 만들기」 · 변경 문장(사용자 말 · 「창구」 「판정」 0): ① 「업무 프로젝트의 「작업 추가」 옆 ▾ 를 누르면 템플릿을 미리 보고 골라 그 자리에 작업을 만들 수 있어요 — 회의록, 자주 쓰는 보고서 같은 틀을 한 번 저장해 두면 돼요」 ② 「작업 상세의 더보기에서 「템플릿으로 저장」, 저장한 템플릿은 「이 템플릿으로 매주 만들기」로 정해진 요일마다 새 작업을 만들어 줘요」 ③ 「▾ 를 누르지 않은 「작업 추가」는 전과 똑같아요」.
- 관문 ③(사용자 — 직접 해볼 공격 · 사람 수락): 보기 공유만 받은 계정으로 꺾쇠가 없는지와 주소 창에서 `/community/api/templates?database=…` 를 직접 쳐 403 인지 · 남의 개인 템플릿 id 를 개발자 도구로 넣어 만들기 → 403 · 「주간재료비 보고」 템플릿(팀 또는 이 프로젝트 행)을 저장하고 매주 금 09:00 반복 · 제목 틀 「주간재료비 보고 — {YYYY}년 {주}주」 · 기한 +0일을 걸어 다음 금요일 「내 작업」에 뜨는지(②).
