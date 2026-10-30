# 보고 — Q:F-16 1차 업무 프로젝트에서 템플릿 골라 쓰기 (2026-10-30 · 구현 포크 · Opus 5.5)

지시서 `docs/qf16-work-templates-directive.md` · DESIGN 2.38 · 기획 `../../docs/work-templates-plan-2026-10-30.md` v1.0.
가지 `feat/qf16-work-templates` · 기준 main `8aa6980` · 구현 커밋 `77ea3e1` · 결과 `/home/changkwon/qaredi-qf16-results-20261030/final/`.
**서버 판정 · 창구 · DB · 마이그레이션 변경 0.** main 합치기 · push · 운영 반영 · 발행 0(메인 몫).

## 변경 파일

| 파일 | 무엇 |
|---|---|
| `src/community/handlers/page_view.php` | 머리 「작업 추가」 바로 뒤 꺾쇠 `details#project-add-task-pick`(hidden 시작) — 다른 줄 0 |
| `public/assets/community/work.js` | 꺾쇠 둘(머리 · 섹션 끝) · 목록 · 미리보기 · 만들기 · 작업 칸 더보기 「템플릿으로 저장」 · `import { repeatDialog }` |
| `public/assets/community/templates.js` | `createTemplateSave` 에 `open(id, data, opts)` · 페이지 값과 작업 값 분리 · 저장 뒤 「이 템플릿으로 반복 만들기」 · 성공 알림 `.msg--ok` |
| `public/assets/community/automations.js` | `repeatDialog` — 일반 템플릿이면 「만들 자리」 기본값 = `data.parent` |
| `public/assets/community/editor.js` | 「템플릿으로 저장」 창 한 벌을 `createWork` 에 getter 로(세 줄 — 아래 「다르게」 ①) |
| `public/assets/app.css` | 2.15 `.menu` 묶음 바로 뒤 — 새 클래스 일곱 · 폰 시트 · 미리보기 |
| `public/assets/community/{dashboard,database,suggestions,table-editor}.js` · `public/assets/messenger.js` | import `?v=` 줄만(2 · 5 · 1 · 2 · 1줄) — `scripts/community_asset_version.php` 가 찍음(지시서 ⑤) |
| `tests/community/qf16_templates_http.py` · `qf16_templates_browser.py` | 새 수락 시험(Q:T-44 ~ 50) |
| `tests/community/memories_harness.py` · `tests/catalog.json` | `--suite` 둘 · 카탈로그 둘(`community.qf16_templates_http` · `community.qf16_templates_browser`) |

## 수락 시험 (최종 판 `77ea3e1` · 한 번)

| 시험 | 명령 | 결과 |
|---|---|---|
| Q:T-45 · 46 · 47 · 49 · 50(정적) | `python3 tests/community/memories_harness.py --port 8768 --suite qf16_templates_http.py --output …/final/http` | `QF16_TEMPLATES_HTTP pass=96 fail=0` · rc=0 |
| Q:T-44 · 45 · 47 · 48 · 49 · 50 | `… --port 8778 --suite qf16_templates_browser.py --output …/final/browser` | `QF16_TEMPLATES_BROWSER pass=194 fail=0` · rc=0 |

## 옛 시험 둘 (고치지 않고)

| 시험 | 결과 |
|---|---|
| `QF12D_ONLY=u1 … --suite qf12_work_browser.py` | `RESULT pass=356 fail=0` · rc=0 — 머리 단추 셈은 꺾쇠 포함 3(공유 · 작업 추가 · 꺾쇠 — 프로젝트 문서는 본문이 있을 때만) ≤ 4 · 검정 1 |
| `… --suite project_sections.py` | `PROJECT_SECTIONS pass=64 fail=0` · rc=0 |

## 정적

`scripts/security_lint.sh` rc=0 · `php -l page_view.php` 오류 0 · `node --check` 바꾼 JS 넷 ok · `php scripts/community_asset_version.php --check` rc=0 · `bash tests/community/asset_version.sh` rc=0 · `php scripts/wiki_check.php` rc=0 · `git diff --check 8aa6980 HEAD` rc=0. 새 줄 금지 패턴 · 새 클래스 일곱 · 「양식」 0 은 http 시험 [static] 이 셈(통과).

## 기획 4장 · DESIGN 2.38 · 지시서와 다르게 한 것 (왜)

1. **editor.js 한 줄 → 세 줄**: `templateSave` 가 `initPage()` 의 지역 변수라 `boot()` 의 `createWork({...})` 에서 닿지 않는다 → 모듈 변수 `pageTemplateSave` · `initPage` 에서 대입 · `templateSave: () => pageTemplateSave`(창은 한 벌 그대로). work.js 는 부를 때 `open` 이 있는지 본다.
2. **목록 DOM**: `.menu__list` = 폰 시트 머리 + **목록 칸 `div`(클래스 0)** + `.menu__preview`. ≥960 두 칸은 `grid-row: 1 / -1` 대신 자식 둘로 — 그리고 **미리보기가 보일 때만** 두 칸(`:has(> .menu__preview:not([hidden]))`) · 폭 44rem. 열자마자 오른쪽 빈 칸이 생기지 않게.
3. **폰 시트 층**: 지시서는 「지금 메뉴와 같은 층(겹치면 사진)」. 360 ~ 820 에서 시트 마지막 줄(「회의록」 · 그 미리보기 단추)이 메뉴(40)보다 위 · 길잡이 층(`--z-guide`) 아래의 떠 있는 요소에 가려 「보이는 상자 어디를 눌러도 자기 자신」이 실패 → **폰 시트만 `z-index: var(--z-guide)`**(지금 토큰 · 숫자 0 · 새 토큰 0). 가리던 요소 이름은 보정 뒤 실패가 0 이라 측정에 남지 않았다(**미확인**).
4. **미리보기 바닥 단추 고정**: 본문이 길면 바닥 「만들기 · 반복 만들기」가 미리보기 스크롤 밖으로 잘려(≥960) 같은 단언이 실패 → `.menu__preview > .toolbar:last-child { position: sticky }`(새 클래스 0).
5. **`.menu__preview > p { margin: 0 }`** — 기본 여백으로 「제목 · 미리 채움 · 안내」 줄 사이가 50px 벌어졌다.
6. **`.menu__row` gap 2px → 8px** — 터치에서 미리보기 단추(32px)의 넓힌 누름 자리(한쪽 6px)가 줄 항목에 겹치지 않게.
7. **저장 성공 알림 `.msg--ok`** — 전엔 성공 문장이 빨강(DESIGN 2.21 「알림 색」). 페이지 더보기 저장에도 같이 적용된다.
8. **Q:T-44 ① 「열기 전 요청 0」 → 「꺾쇠가 부른 요청 0」**: 지금 데이터베이스 화면(`database.js createRowTemplatePicker`)이 행을 더할 수 있는 사람에게 `?database=` 를 한 번 부른다(이 기능 전부터). 꺾쇠는 그 위에 열 때 둘 · 다시 열면 +0.
9. **Q:T-45 ⑧ 보기 공유의 작업 칸 「템플릿으로 저장」**: 서버가 보기 공유 계정에도 개인 템플릿 저장을 허락한다(`template_scopes = ["personal"]` — `decideTemplateSave`). 화면은 창구 값대로 보인다(페이지 더보기와 같음 · DESIGN 2.38 「창구 답 template_scopes 뿐」). 지시서 기대값 「0」을 「= template_scopes」로 고쳐 단언. 「작업 추가」 · 꺾쇠 · 목록 요청은 0 그대로.
10. **Q:T-49 반복 실행**: 실행기는 「규칙을 만든 뒤의 슬롯」만 잡는다(`scripts/community_automations.php` `slot >= created_at`) → 방금 지난 슬롯을 쓰려고 **합성 DB 에서만** `created_at` 을 1시간 앞으로.
11. **반복 「만들 자리」 전달**: 지시서 두 길 중 둘째 — work.js 가 `open(id, data, { repeatParent, onSaved })`. `onSaved` 로 꺾쇠 목록을 다음에 다시 받는다(Q:T-48 「요청 다시 둘」).
12. **꺾쇠 목록 기억**: `enterProject` 는 행 목록 답마다 불리므로 거기서 비우면 「다시 열어도 +0」이 깨진다 → 프로젝트(DB) id 가 바뀔 때 비운다.
13. **목록 순서 · 범위 글자**: 지시서대로(「이 프로젝트 · 내 템플릿 · 팀 · 사이트 기본」). 「미리 채움」 끝의 이름은 묶음 칸이 `section` 이면 「섹션」, 아니면 그 칸 이름.
14. **독립 검토 보정 L-1 · 폰 시트 층**: 위 ③ 의 `z-index: var(--z-guide)` 를 빼고 시트 층은 `.menu__list` 그대로 — 대신 시트가 열린 동안 우하단 「? 사용 안내」(`.usage-guide-launch` · usage-guide.css z-index 45 > 메뉴 40 — ③ 의 「미확인」 가리던 요소)를 `body:has(details.menu--picker[open]) .usage-guide-launch { display: none }`(폰 @media 안 · DESIGN 2.38 폰 줄에 기록).
15. **독립 검토 보정 L-2 · 단추 글자**: 「이 템플릿으로 매주 만들기」 → **「이 템플릿으로 반복 만들기」**(기존 「행 템플릿으로 반복 만들기」와 같은 말 · 반복 창 기본 주기 「매월」과 어긋나지 않게) — 미리보기 바닥 · 저장 직후 · DESIGN 2.38 · 시험 · 카탈로그 · 이 보고서.
16. **독립 검토 보정 L-3 · 저장 직후 단추 자리**: 창 본문 전폭 → 성공 알림 **옆** 한 줄(지금 `.toolbar` 로 알림과 단추를 감쌈 · 다시 열면 풀어 알림 줄만 · 새 클래스 0).
17. **최종 판 실패 보정 · 낡은 미리보기**: 다른 템플릿의 미리보기를 고르면 본문을 받는 동안 앞 미리보기와 그 바닥 단추가 남아, 빨리 누르면 앞 템플릿으로 반복 창이 열렸다(재현: T-me 를 누른 직후 미리보기 머리 = 「주간재료비 보고」 · 단추 보임) → 받는 동안 미리보기를 비운다(work.js) · 시험은 그 템플릿 미리보기를 기다린 뒤 누른다.
18. **최종 판 실패 보정 · 새 클래스 셈**: http [static] 이 기준 판 app.css 만 보아 다른 CSS 의 기존 클래스(`usage-guide-launch`)를 새 클래스로 셌다 → 기준 판의 모든 CSS 파일에 있던 이름은 빼는 일반 규칙으로.

## 못 한 것

- `docs/design-guide.html` 2.38 견본 **미갱신**(지시서 — 메인이 묶음 끝에).
- PRD 9장 Q:T-44 ~ 50 등록 · SECURITY 7장 · STAGES · 위키(메인 몫).
- 지시서 「안 깨지는 것」(`dbg_u2_ui` · `browser_7b` · `stage89_ui` · `qf12_u4_browser` · `task_parent_browser`)은 돌리지 않았다 — CLAUDE.md 3.1 대로 반영 때 밤 회귀 대기열(지시서 「반영」 목록).
- 실물 휴대폰 · 실제 Safari 미확인(Chromium 에뮬레이션만).

## 갈림길 (서버를 고쳐야만 되는 것 — 구현 0)

새로 찾은 것 없음. 지시서의 둘 그대로: 작업 더보기의 **상시** 「이 템플릿으로 반복 만들기」(작업 → 템플릿 고리 없음) · 업무 홈 · 내 작업 칸의 「템플릿으로 저장」(그 화면에 창 없음 — 시험으로 항목 0 확인).

## 되돌릴 수 없는 것

없다 — 표 · 창구 · 마이그레이션 변경 0. 코드만 되돌리면 된다.

## 스크린샷

`/home/changkwon/qaredi-qf16-results-20261030/final/browser/shots/` — `pc-1440-preview.png`(두 칸 미리보기) · `pc-1440-dark-preview.png` · `phone-360-sheet.png`(시트 목록) · `phone-360-sheet-preview.png`(시트 미리보기) · `pc-1440-task-more.png`(작업 칸 더보기) · `pc-1440-saved-repeat.png`(저장 뒤 「반복 만들기」) · `pc-1440-repeat-parent.png`(반복 창 「만들 자리」).
