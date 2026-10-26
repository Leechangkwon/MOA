# 보고 — 모아 Q:F-15 S3 과업 길잡이 1차 (구현 · 관문 ①, 2026-10-26)

지시서 `docs/qf15-s3-guide-directive.md`(확인할 것 S3-1 A · S3-2 A · S3-3 B · S3-4 권고) 그대로. 작업 트리 `/home/changkwon/qaredi-qf15s3-20261026`(가지 `feat/qf15-s3-guide` · 기준 `4997871`) · 결과 `/home/changkwon/qaredi-qf15s3-results-20261026/`. push · 발행 · 합치기 · 태그 0. 운영 트리 · 운영 DB · 운영 주소 요청 0 — 격리 틀은 연결 열쇠를 운영 트리 `.env` 대신 `QCHECK_ENV_SRC=/home/changkwon/qaredi-regress-results-20261013/env-src`(밤 회귀가 쓰는 저장소 밖 0600 파일 · 내용은 열지 않음)로 읽었다.

커밋: `51ff00c`(표 · 창구) · `b5d779a`(화면) · `7f73748`(시험 · 카탈로그 · 옛 시험 기대값) · 이 보고 커밋. 수락 시험은 `7f73748` 판에서 돌렸다.

## 1. 변경 파일

| 묶음 | 파일 |
|---|---|
| 보안 핵심(db/**) | `db/migrations/2026-10-27_qf15_s3_guide_journeys.sql`(새) · `db/community/grants.sql`(usage_guides DO 블록 안 IF 블록 하나) |
| 서버 | `src/community/GuideJourney.php`(새) · `src/community/handlers/guide_journeys_api.php`(새) · `src/community/handlers/usage_guide_api.php`(GET 확장) · `src/community/routes.php`(한 줄) |
| 화면 | `public/assets/usage-guide.js`(여정 모드) · `public/assets/usage-guide.css`(끝에 새 줄) · `public/assets/app.css`(`--z-guide` 한 줄) · `src/views/usage_guide.php`(aria-label · data-app-base) · `src/views/launcher.php`(과업 카드 줄) |
| 시험 | `tests/community/guide_journey_unit.php` · `guide_journeys_http.py` · `guide_journey_browser.py`(새) · `memories_harness.py`(choices 둘 · migrations 끝 · 소유자 갈음 목록) · `tests/catalog.json`(셋) · `tests/upgrade/flows.json`(동선 한 줄 — 형식에 맞음) |
| 옛 시험 기대값 | `tests/community/messenger_window_browser.py` · `tests/community/home_front_browser.py`(3절) |
| 보고 | `docs/qf15-s3-report.md` |

다른 앱 JS(app.js · work.js · editor.js · templates.js · messenger.js · member.js · moa.js) · `public/assets/community/` · Authz · CommunityAuthz · Audit · index.php · UsageGuide.php · schema 변경 0(static.log).

## 2. 수락 시험과 결과 (원문 꼬리 — 전문은 결과 폴더)

```
$ php tests/community/guide_journey_unit.php                                            → unit.log
PASS: ③ invite-colleague matches the DB CHECK pattern
RESULT pass=104 fail=0

$ QCHECK_ENV_SRC=… python3 tests/community/memories_harness.py --port 8768 --suite guide_journeys_http.py \
    --output /home/changkwon/qaredi-qf15s3-results-20261026/http                         → http.log
PASS ⑫ four successful PATCHes add no audit row (25 → 25)
RESULT pass=106 fail=0
OWNED_TEST_PROCESSES_STOPPED=1; SYNTHETIC_DB_DROPPED=1

$ QCHECK_ENV_SRC=… python3 tests/community/memories_harness.py --port 8768 --suite guide_journey_browser.py \
    --output /home/changkwon/qaredi-qf15s3-results-20261026/browser                      → browser.log
PASS ⑮ 412 work home (task step): card ≤ 30dvh · overflow 0
PASS ⑮ 412 task console 0
RESULT pass=275 fail=0
OWNED_TEST_PROCESSES_STOPPED=1; SYNTHETIC_DB_DROPPED=1
real    4m7.237s

$ CHROMIUM_EXECUTABLE=~/.cache/ms-playwright/chromium-1234/chrome-linux64/chrome python3 tests/community/usage_guide_browser.py   (고치지 않음) → fallback.log
{"suite": "usage_guide_browser", "passed": 124, "http": "synthetic", "php_database": "not executed"}

정적(static.log): security_lint 「결과: 통과 (금지 패턴 0건)」 · php -l 7파일 오류 0 · node --check usage-guide.js OK ·
wiki_check 「결과: 통과 (구조 문제 0건 · 빠진 글 0건, 글 70편)」 · git diff --check 4997871..HEAD clean ·
public/assets/community/ 0 · 다른 앱 JS 0 · 보호 파일 0 · 마이그레이션 두 번(틀) + 세 번째 적용 오류 0([http] ①)
```

포트 8768 · 8769 시작 전 비어 있음(`ss -ltn`) · 끝에 틀이 내 프로세스 · 합성 DB 를 치움.

## 3. 기대값을 바꾼 옛 시험 (그 줄만 · 돌리지 않음 — 밤 회귀)

| 파일 | 줄(지금) | 전 → 후 |
|---|---|---|
| `messenger_window_browser.py` | 663-666 | 「? 사용 안내」 → 곧바로 「업무 홈」 투어 → 질문 카드 「무엇을 하고 싶으세요?」 → 「이 화면 사용 안내」 → 「업무 홈」(뒤 단언 그대로) |
| 〃 | 674 | 제안 제목 「모아 사용 방법을 안내해 드릴까요?」 → 「무엇을 하고 싶으세요?」(「나중에 볼게요」 · dismissed 그대로) |
| `home_front_browser.py` | 43-50 | `EMP_ROWS` 꼬리 셋 + 한마디 `/community/?guide=message-send` · 공지 줄 뺌 · `ADMIN_ROWS` = ①②③ + ④ notice-post + ⑤ + ⑥ invite-colleague(꼬리) · `ACTING_ROWS` = ①②③ · `GUEST_ROWS` 그대로 |
| 〃 | 470 | `'?guide=' not in r.text` → `?guide=` 값 = 쓰기 줄 넷(task-add · file-share · minutes-write · message-send) |
| 〃 | 546 | 메신저 창 여는 줄 `['메신저']` → `[]` |
| 〃 | 562-567 | 「동료에게 한마디」 누름 → 창 열림 · 주소 `/` → 주소 `/community/` · 꼬리 0 · 진행 카드 · 끝에 그 계정 여정 행 지움(뒤 단언의 홈 줄 보호) |
| 〃 | 716(grep 으로 찾음) | [grid] 「five rows」 `len(rows) == 5` → `len(EMP_ROWS)`(넷 — S3-1 A) |

## 4. 여섯 여정 단계 (실제 선택자 · 넘김 신호 — 글은 E 표 그대로)

공통: 진행 머리 「n / m · <과업 글자>」 — 「· 다음: <화면 이름>」은 다음 단계가 다른 화면일 때 · 가기 카드만(6-3 · 화면 이름 = 업무 홈 · 자료실 · 내 문서 · 문서 · 「<게시판 이름>」 · 메신저 · 계정 관리). 「창 안」 = 그 앱 창의 `.modal__head` 다음 한 줄. 창 안 단계 도중 창이 사실 없이 닫히면 창을 여는 단계(아래 「돌아감」) + 「아직 결과가 없어요」 줄.

| 여정 | # | 대상 | 넘김 신호 · 다른 점 |
|---|---|---|---|
| ① task-add (4) | 1 · 2 | `#work-home-tasks` · `#work-home-add` | 읽기 · `#work-new-task-dialog[open]` |
| | 3 | `#work-new-task-form input[type=text]`(창 안) | `work:changed` → 사실 · 돌아감 2 |
| | 4 | `#work-home-all` | 읽기 「마치기」 → 완성 |
| ② file-share (6) | 1 · 2 | `#folder-pane` · `#upload-toggle` | 읽기 · `#upload-dialog[open]` |
| | 3 · 4 · 5 | `#upload-file` · `#upload-level` · `#upload-submit`(창 안) | `#upload-picked:not(:empty)` · change(앞질러 `#upload-submit`) · `#upload-dialog:not([open])` → 사실 · 돌아감 2 · 4 의 글은 `option[value=team]` 없으면 대체 문장 |
| | 6 | `#files-body` | 읽기 「마치기」 |
| ③ minutes-write (3+7) | 1 · 2 | `#community-more > summary` · `#community-template-new` | `#community-more[open]` · `#template-dialog[open]` |
| | 3 | `#template-body button[data-template-use][aria-label="회의록 으로 새 페이지 만들기"]`(창 안) | 그릴 때 `{page:1, step:0}` 미리 저장 · 빈 상태는 3초 뒤 **그 창의 한 줄**로 |
| | 4~7 | `#page-edit` · `#page-title-input` · `#page-editor .ProseMirror` · `#page-done` | `body[data-page-editing]` · 입력 0.8초(앞질러 본문) · 입력 0.8초 · `#page-done[hidden]` · 문서 판정 = `body[data-page=community-page]:not([data-project-view])` + `/p/<사실 ref>` |
| | 8~10 | `#page-visibility-edit`(보일 때만) · `#visibility-choices` · `#visibility-save`(창 안) | `#visibility-dialog[open]` · change(앞질러 `#visibility-save`) · `:not([open])` → 사실 · 8 이 안 보이면 9 · 10 도 건너뛰고 완성 판정 |
| ④ notice-post (1+2+2) | 1 | `#nav-community-boards a[href$="/b/<찾은 id>"]` | 미리 저장 `{1,0}` · **폭 <960 · `body[data-sidebar=collapsed]` · 닫힌 묶음이면 기다리지 않고** 「이 화면으로 가기 → 「<이름>」」, 그 밖은 3초 기다린 뒤 없으면 같은 카드 · 공지 없음 · 보관 = 빈 상태 · `can_write` 거짓 = 자격 카드 |
| | 2 · 3 | `#board-new` · `#board-new-input`(창 안) | `#board-new-dialog[open]` · 미리 저장 `{2,0}`(「만들고 쓰기」가 옮김 — 표의 앞질러 `#board-new-save` 는 뒤 단계가 없어 미리 저장으로 같은 효과) |
| | 4 · 5 | `#page-editor .ProseMirror` · `#page-done` | 입력 0.8초 · `#page-done[hidden]` → 사실 |
| ⑤ message-send (4) | 1 | 보이는 `[data-messenger-open]` | 읽기 — 「다음」만 `messengerWindow()`(이미 열렸으면 0) · `#messenger-window[open]` 이면 곧바로 통과 |
| | 2 | `#messenger-window[data-view=conversation] #messenger-back` 또는 첫 `#message-people .moa-person`(창 안) | `[data-view=conversation]:has(#message-ai[aria-pressed=false])` |
| | 3 · 4 | `#message-text` · `#message-send`(창 안) | 입력 0.8초(앞질러 보내기) · `#message-form` 제출(Enter 도 — 앞 단계에서 제출되면 4 로) → 사실 · 거짓이면 2 로 + 그 줄 「아직 결과가 없어요 — 동료를 골라 보내면 끝나요」 |
| ⑥ invite-colleague (3) | 1~3 | `#invite-form` · `#invite-copy` · `#invite-list` | `#invite-result:not([hidden])` → 사실 · `#invite-status` 글자가 들어온 뒤 바뀜 · 읽기 「마치기」 |

## 5. 못 한 것 · 미검증

- 관문 ③(사용자 공격 · Q:T-G16 사람 수락) · 반영 · 백업 · 모의 적용 · 밤 회귀 대기열 — 메인 · 사용자 몫(지시서대로 하지 않음). 옛 시험 둘과 「안 깨지는 것」 목록은 돌리지 않음(밤 회귀).
- 미검증: 강제 색 모드(`forced-colors`)의 테두리 색(CSS 한 줄만 · 실측 0) · 실제 iOS/Android 자판이 올라온 상태의 접기 칩(데스크톱 Chromium 터치 흉내로 입력 칸 초점만 확인) · 운영 크기 자료에서 사실 질의 속도(진행 중 여정이 있을 때만 GET 마다 한 번).
- `tests/upgrade/flows.json` 의 새 동선은 업그레이드 스모크가 운영 DB 덤프로 임시 DB 를 만들므로 **반영(표 생성) 뒤에야** PATCH 가 나간다 — 반영 전 판에서 돌리면 REQ0 로 실패한다.
- 되돌릴 수 없는 것(지시서 그대로): 새 표에 쌓이는 진행 기록(지울지는 사용자) · 여정을 한 번이라도 시작한 사람은 첫 로그인 제안을 다시 받지 않음 · S3-2 A 홈 「동료에게 한마디」가 업무 홈을 거침(코드로 되돌릴 수 있음).

## 6. DESIGN · 지시서와 다르게 한 것

1. 「결과가 이미 있어요」 카드에 「다시 하기」(= 처음부터) 하나를 더했다 — 지시서는 「마치기」만. DESIGN 5장 「안내 카드 단추」 집합 전부가 쓰이고 수락 [static] 이 그 집합과 같음을 센다. 그 카드 글은 새로 씀: 「시작한 뒤에 한 일이 이미 남아 있어요. 「마치기」를 누르면 끝낸 일로 남아요.」
2. 위 자리(`.is-top`)를 화면 위 72px 로 — 윗줄(☰ · 메신저 · 앱 · 내 계정)을 가리지 않게(16px 이면 폰에서 ☰ 가 눌리지 않음 — 시험으로 확인). 자리 고르기는 「아래 반 → 위」에 더해 **대상을 가리지 않는 자리**를 먼저 고른다(메신저 겹침 0 이 늘 우선).
3. 완성 카드에 `h2` 를 두지 않음(머리 줄 「m / m · <과업>」 + 완성 글) — 「다 했어요」 머리 0.
4. 폰 접기 칩: 카드 밖 입력 칸 · 편집기에 초점이 가면 저절로 「안내 n/m ▾」로 접히고 칩을 누르면 펼침(따로 「접기」 단추는 5장 단추 글자에 없어 두지 않음).
5. 빈 상태 · 「안 보여요」가 앱 창(showModal) 안 단계에서 나면 카드가 아니라 그 창의 한 줄로 보인다(진행 카드는 showModal 층 아래라 보이지 않음). 「이 단추가 지금 안 보여요」 카드의 단추 차례: 「건너뛰기」 · 「나중에」 · 검정 「이 화면으로 가기 → <화면>」(2.7 — 오른쪽 끝 주요 하나).
6. 길잡이의 「보임」 판정은 새 `shown()`(그려진 상자 + `checkVisibility`) — 옛 `visible()` 은 `[hidden]` 조상만 봐서, 넓은 화면에서 CSS 가 보이게 하는 사이드바(`aside hidden`) 안의 게시판 링크를 놓친다. 옛 투어는 옛 `visible()` 그대로.
7. `makeDialog()` 에 선택 인자 하나(`onCancel`, 기본 = 지금 동작) — 「? 사용 안내」로 연 질문 카드의 Esc 가 옛 안내 행에 「그만둠」을 쓰지 않게(자동 제안으로 연 질문 카드의 Esc 는 지금처럼 「그만둠」). 옛 투어 · 제안의 동작은 같다(fallback 124 통과).
8. 홈 「하던 일 이어 하기」 줄은 `GuideJourney::ready()` + `resume()` 두 질의(같은 HTML 요청 안 — HTTP 요청 수 그대로).
9. 수락 시험 해석: [static] 「usage-guide.js 에 'role' 0」은 역할 이름 문자열 · 역할 칸 읽기 0 으로 셈(ARIA `setAttribute('role', …)` 은 옛 코드에도 있어 제외) · [layout] 「재인증 · 확인 창 위 카드 0」은 화면 머리 「사용 방법」 도움말 창(showModal)으로 재고, 재인증 화면은 [invite] 에서 카드 0 · [missing] 「1초 뒤 채워지는 칸」은 템플릿 목록 창구 답을 1초 늦춰 확인 · [message] 합성 틀은 모아 AI 가 꺼져 있어 「보내기」가 꺼져 있으면 시험이 그 폼을 제출해 같은 신호를 만든다 · ProseMirror 의 CSS 확인 경고(앱 편집기)는 콘솔 셈에서 뺌(`qf12_w1_browser.py` 와 같은 예외) · 메신저 사람 목록이 claude_ 를 빼므로 로그인 없는 합성 구성원 둘(`synthetic_peer_a/b` — messenger_window_browser 와 같은 준비) · 재인증 통과는 합성 DB 의 그 관리자 비밀번호 해시를 잠시 아는 값으로 바꿔 실제 `/tbox/reauth` 폼으로 하고 되돌림.
10. 단위 시험은 여정 묶음을 PHP 의 작은 데이터 해석기로 읽는다(security_lint 가 `proc_open(` 을 막아 node 실행 대신).
11. (독립 검토 지적 3) GET 이 실패했거나 아직 끝나지 않은 문맥에서 셸 「? 사용 안내」를 누르면 `load()` 를 기다린 뒤 판단해 투어를 연다(길잡이가 켜졌는지 알아야 질문 카드 · 투어를 가를 수 있다) — 옛 코드는 곧바로 `start()`. 결과(여는 투어 · 이어 보기 여부)는 같고 GET 한 번만큼 늦다. 옛 목업 시험(`usage_guide_browser.py`)은 그대로 통과.

## 6-1. 검토 보정 (관문 ② 독립 검토 「보정 뒤 반영 가능」 · 기준 `c82b3f0` — DESIGN 2.37 「단추」 · 「층 · 자리(≥720)」 두 줄을 메인이 먼저 고침)

| # | 무엇을 | 어디를 |
|---|---|---|
| ① 검토 지적 1(중간) | `place()` 가 `floatEl.hidden` 을 같은 값이어도 매번 다시 써, `hidden` 이 관찰자 `attributeFilter` 안이라 대상을 품지 않은 showModal 창(재인증 · 확인 · 「사용 방법」)이 열린 동안 evaluate → place → 되쓰기가 프레임마다 돌았다(검토 재현 2초 121회). **값이 바뀔 때만 쓴다** — 같은 꼴(관찰자 필터 · 자식 목록에 걸리는 되쓰기)도 함께: 창 안 한 줄 글자(같으면 다시 그리지 않음) · 접기 칩 · 본문 `hidden` · 「? 사용 안내」 단추 `hidden` · 글자 · `aria-label`. 테두리 겹(`rect` x · y · 폭 · 높이)과 `class` 토글은 필터 밖이라 그대로 | `usage-guide.js` `setHidden` · `setText` · `setAttr`(새 도우미 셋) · `inset()` · `paint()` · `fold()` · `place()` · `paintLaunch()` |
| ② 화면 사진 `pc-progress-card-1440` | 하는 단계의 「나중에」 · 「건너뛰기」 둘 다 테두리 · 검정 0(주요 동작은 테두리를 친 진짜 단추). 주 단추가 없으니 「초점이 이미 카드 안이었을 때」는 마지막 단추로 옮김. 읽는 단계 「다음」 · 가기 카드 「이 화면으로 가기」 · 완성 「마치기」 · 「안 보여요」 카드의 검정은 그대로 | `usage-guide.js` `paintStep()` · `paint()` |
| ③ 화면 사진 `pc-messenger-beside-card-1280` | `.is-beside` = 카드 오른쪽 끝을 **메신저 왼쪽 − 16** 에 맞춤(메신저 기본 자리 2.17 — 오른쪽 16 · 폭 400 기준 `right: 432px`) · 폭 `min(540px, 100% − 448px)` = min(540, 메신저 왼쪽 − 32). 그 폭이 300px 미만(`BESIDE_MIN`)이거나 메신저를 옮겨 끝이 맞지 않으면(±1 밖) 쓰지 않고 가운데 위 · 아래로. 메신저 · 대상과 겹치지 않는 자리가 늘 먼저 | `usage-guide.css` `.is-beside`(+ <720 되돌림에 `right:auto`) · `usage-guide.js` `place()` |
| 단언 셋 | [file] 하는 단계 카드 안 `.usage-guide-primary` 0 · [layout] 진행 카드가 뜬 채 「사용 방법」 창(showModal)을 1초 열어 둔 동안 안내 관찰자 콜백 ≤ 5(잰 값 3 — 안내 스크립트가 만든 관찰자만 세는 계측을 `INSTRUMENT` 에 더함) · [layout] 960 · 1280 · 1440 × 밝게 · 어둡게 메신저를 연 채 카드 오른쪽 끝 = 메신저 왼쪽 − 16(528 / 848 / 1008) · 폭 min(540, 왼쪽 − 32) · ∩ 메신저 0 · ∩ 대상 0 | `tests/community/guide_journey_browser.py` |

시험(고친 곳에 닿는 묶음만 — 전체 수락 묶음은 메인이 최종 판에서): `GUIDE_ONLY=file,layout QCHECK_ENV_SRC=… python3 tests/community/memories_harness.py --port 8768 --suite guide_journey_browser.py --output /home/changkwon/qaredi-qf15s3-results-20261026/fix1`

```
PASS ⑮ 412 task console 0
RESULT pass=191 fail=0
OWNED_TEST_PROCESSES_STOPPED=1; SYNTHETIC_DB_DROPPED=1
```

정적(`fix1-static.log`): node --check OK · 바꾼 PHP 0 · security_lint 「결과: 통과 (금지 패턴 0건)」 · git diff --check clean · 새 줄 색값 · 숫자 z-index 0 · 옛 투어 함수(render · start · consent · position · makeDialog · messengerWindow · addModalHelp · visible) 변경 0.
새 화면 사진: `/home/changkwon/qaredi-qf15s3-results-20261026/fix1/shots/`(`pc-progress-card-1440.png` — 하는 단계 테두리 둘 · `pc-messenger-beside-card-1280.png` — 메신저 쪽에 붙은 카드).

검토의 나머지 지적(메인이 원문에서 옮김):
- 지적 2(참고) `?guide=` 를 지울 때 다른 주소 꼬리도 함께 지움(지시서 D 3 문장 그대로 — 동작 영향 0) → **2차**에서 `URLSearchParams.delete('guide')` 로 그 키만.
- 지적 3(참고) GET 실패 · 미완료 문맥에서 「? 사용 안내」가 `load()` 를 기다린 뒤 투어 → **받아들임**(6장 11).
- 지적 4(참고) 진행 중 file-share 사실 질의 `files(uploaded_by, created_at) WHERE deleted_at IS NULL` 에 맞는 색인 0 · `member_invites(created_by)` 색인 0 → **남는 것**(운영 크기에서 느리면 색인 하나 — 규모 D 다음 조각).
- 지적 5(참고) 시험의 「모아 AI 에게만 보낸」 부정 갈래가 꺼진 「보내기」 대신 폼을 직접 제출 → **받아들임**(부정 갈래만 · 긍정 갈래는 진짜 단추).
- 지적 6(참고) 홈 이어 하기 줄 질의 둘(`ready()` + `resume()`) → **받아들임**(HTTP 요청 수 그대로).
옮긴(움직이거나 크기를 바꾼) 메신저 옆 맞춤은 인라인 style 0 규칙 때문에 클래스로 따라갈 수 없어, 그때는 맞추지 않고 겹치지 않는 가운데 위 · 아래로 간다(미검증 — 시험은 기본 자리만).

## 6-2. 최종 판 수락 · 정적 — 메인이 한 번(CLAUDE.md 3.1 ② · `9ee4ad7` · 2026-10-26 14:11 ~ 14:16)

결과 `/home/changkwon/qaredi-qf15s3-results-20261026/final/`(`static.log` · `unit.log` · `http.log` · `browser.log` · `fallback.log` · `browser/shots/`).

```
bash scripts/security_lint.sh                  → 결과: 통과 (금지 패턴 0건)
php -l (바꾼 PHP 7)                             → No syntax errors detected × 7
node --check public/assets/usage-guide.js      → OK
php scripts/wiki_check.php                      → 결과: 통과 (구조 문제 0건 · 빠진 글 0건, 글 70편)
git diff --check 4997871 HEAD                   → clean
php tests/community/guide_journey_unit.php      → RESULT pass=104 fail=0
memories_harness --suite guide_journeys_http.py → SYNTHETIC_DB_CREATED=1; PRODUCTION_RECORDS_COPIED=0 · RESULT pass=106 fail=0 · SYNTHETIC_DB_DROPPED=1
memories_harness --suite guide_journey_browser.py → RESULT pass=296 fail=0 · SYNTHETIC_DB_DROPPED=1
python3 tests/community/usage_guide_browser.py → {"suite": "usage_guide_browser", "passed": 124} (고치지 않음 · 옛 목업 — 여정 기능 꺼짐)
```

## 6-3. 보정 — 진행 카드 머리 「다음:」은 다른 화면으로 이어질 때만 (사용자 2026-10-26 · DESIGN 2.37 「진행 카드」 `ad63bbf`)

전: 모든 카드 머리가 「· 다음: <지금 화면 이름>」(예 자료실에서 「다음: 자료실」). 후: 사람이 지금 있는 화면과 **다른 화면**에서 할 일이 이어질 때만.

| 함수(`usage-guide.js`) | 바뀐 것 |
|---|---|
| `nameOf()`(새) · `pageName()` | 화면 이름 한 곳 — 게시판 화면은 창구가 준 「<게시판 이름>」, 그 밖은 정의의 이름. `pageName()` 은 게시판을 찾은 뒤 `nameOf()` |
| `progressOf(page, at, next)` | 「n / m · <과업 제목>」 + `next` 가 있을 때만 「 · 다음: <next>」 |
| `nextScreen()`(새) | 지금 단계의 다음 단계가 다음 화면(page + 1)이면 그 화면 이름, 같은 화면(창 안 단계 포함)이거나 마지막 단계면 null |
| `paintStep()` | 머리 = `nextScreen()` — 지금 화면 이름을 넘기던 것 뺌 |
| `goCard()` | 그대로 — 가야 할 화면 이름(단추 「이 화면으로 가기 → <이름>」과 같은 값) |
| `missingCard()` · `emptyCard()` · `noResultCard()` · `alreadyCard()` | 머리에 「다음:」 0(`null`). 「안 보여요」 카드의 단추 글 「이 화면으로 가기 → <이름>」은 그대로 · 완성 카드 · 창 안 한 줄 · 접기 칩은 원래 「다음:」 0 |

이 규칙으로 1차 여섯 여정에서 단계 카드에 「다음:」이 붙는 자리는 각 화면의 마지막 단계뿐이다: notice-post 1 단계(→ 「<게시판 이름>」) · notice-post 3 단계(→ 문서) · minutes-write 3 단계(→ 문서). 뒤의 둘은 앱 창 안 단계라 **창 안 한 줄**로 보이므로(DESIGN ③ — 「다음:」 0) 화면에서 보이는 단계 카드는 notice-post 1 단계 하나 · 그 밖은 가기 카드(「다음: <가야 할 화면>」).

바꾼 기대값(`guide_journey_browser.py`): [file] ③ 1 단계 머리 `'1 / 6 · 파일 올려서 동료에게 보여 주기 · 다음: 자료실'` → `'1 / 6 · 파일 올려서 동료에게 보여 주기'`.
더한 단언: ⓐ 같은 화면 — [file] 2 단계 머리 「2 / 6 · …」 · [task] 2 단계 「2 / 4 · …」 · [minutes] 1 · 2 단계 「1 / 10 · 회의록 쓰기」 「2 / 10 · 회의록 쓰기」 · [notice] 게시판 화면 2 단계 「2 / 5 · 공지 올리기」 — 모두 「다음:」 0 ⓑ 다른 화면 — [notice] 1 단계 「1 / 5 · 공지 올리기 · 다음: 「S3 공지」」 · [minutes] 3 단계(다음이 문서 화면)는 창 안 한 줄이라 머리 없음 — 그 한 줄에 「다음:」 0(지시의 「3 단계 머리 「· 다음: 문서」」 예는 이 단계가 창 안이라 화면에 나오지 않아 이렇게 셈) ⓒ [notice] 접힌 사이드바 · 412 가기 카드 머리 「· 다음: 「S3 공지」」 = 단추 「이 화면으로 가기 → 「S3 공지」」의 이름 ⓓ [file] 완성 카드 「6 / 6 · …」 · [notice] 빈 상태(보관한 공지 게시판) 「1 / 5 · 공지 올리기」 · [missing] 「안 보여요」 「1 / 4 · 맡은 일 보기 · 할 일 적기」 — 「다음:」 0.

시험(고친 곳에 닿는 묶음만 — 지시의 file · minutes · notice 에 단언을 넣은 task · missing 을 더함): `GUIDE_ONLY=file,minutes,notice,task,missing QCHECK_ENV_SRC=… python3 tests/community/memories_harness.py --port 8768 --suite guide_journey_browser.py --output /home/changkwon/qaredi-qf15s3-results-20261026/fix2`

```
PASS ⑭ 「안 보여요」 card head — 「다음:」 0 (1 / 4 · 맡은 일 보기 · 할 일 적기)
RESULT pass=57 fail=0
OWNED_TEST_PROCESSES_STOPPED=1; SYNTHETIC_DB_DROPPED=1
```

정적(`fix2-static.log`): node --check OK · security_lint 「결과: 통과 (금지 패턴 0건)」 · git diff --check clean · CSS · PHP 변경 0 · 옛 투어 함수 변경 0.
새 사진 `/home/changkwon/qaredi-qf15s3-results-20261026/fix2/shots/`: `pc-progress-same-screen-1440.png`(자료실 file-share 2 단계 「「파일 올리기」 누르기」 — 머리 「2 / 6 · 파일 올려서 동료에게 보여 주기」) · `pc-progress-next-screen-1440.png`(업무 홈 notice-post 1 단계 — 머리 「1 / 5 · 공지 올리기 · 다음: 「S3 공지」」).

## 6-4. 「다음:」 보정 뒤 최종 판 — 메인이 한 번(사용자 2026-10-26 「브라우저 수락 시험은 한 번만 다시」 · `804d9a5` · 15:01 ~ 15:06)

바뀐 것이 JS · DESIGN · 시험뿐이라 단위 · HTTP 는 다시 돌리지 않았다(6-2 의 104/0 · 106/0 그대로 — PHP · SQL · 마이그레이션 변경 0). 결과 `/home/changkwon/qaredi-qf15s3-results-20261026/final2/`.

```
bash scripts/security_lint.sh                    → 결과: 통과 (금지 패턴 0건)
php -l (바꾼 PHP 7)                               → No syntax errors detected × 7
node --check public/assets/usage-guide.js        → OK
git diff --check 4997871 HEAD                     → clean
memories_harness --suite guide_journey_browser.py → SYNTHETIC_DB_CREATED=1; PRODUCTION_RECORDS_COPIED=0 · RESULT pass=307 fail=0 · SYNTHETIC_DB_DROPPED=1
```

진행 카드 사진 둘(`final2/browser/shots/`): `pc-progress-same-screen-1440.png`(자료실 file-share 2 단계 — 머리 「2 / 6 · 파일 올려서 동료에게 보여 주기」) · `pc-progress-next-screen-1440.png`(업무 홈 notice-post 1 단계 — 「1 / 5 · 공지 올리기 · 다음: 「S3 공지」」).

기록: 지시서 D 6(:110) · 기획 8장의 머리 표기 「n / m · <과업 제목> · 다음: <앱 이름>」은 DESIGN 2.37(ad63bbf)이 「다른 화면으로 이어질 때만」으로 바꿨다 — 문서 본문은 그대로 두고 이 줄과 STAGES 로 남긴다.

## 7. 스크린샷 (`/home/changkwon/qaredi-qf15s3-results-20261026/browser/shots/` — 최종 판은 `final/browser/shots/`)

관문 ③ 전에 보일 여섯 + 완성: **PC 진행 카드** `pc-progress-card-1440.png`(2단계 · 「파일 올리기」 테두리) · `pc-progress-card-1440-step1.png` · **창 안 한 줄** `pc-window-inset-1440.png`(올리기 창) · **메신저 옆 카드** `pc-messenger-beside-card-1280.png` · `pc-messenger-beside-card-1280-dark.png` · **질문 카드** `pc-question-card-1440.png`(이어 하기 줄) · **폰 메신저 시트** `phone-messenger-sheet-412.png` · **폰 작업 칸** `phone-work-task-412.png` · `phone-work-task-window-412.png`(새 작업 창 한 줄) · **완성 카드** `pc-done-card-1440.png` · 그 밖 `pc-messenger-done-1440.png` · `pc-messenger-beside-noresult-1440.png` · `phone-folded-chip-412.png` · `home-admin-1440-tasks.png`.
