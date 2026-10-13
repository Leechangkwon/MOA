# 보고 RC — 회귀 카탈로그 · 실행기 정리 (2026-10-13)

지시서 `docs/regress-cleanup-directive.md` RC-1 ~ RC-7. 시험 틀만 바꿨다 — 앱 코드(src/ · public/ · db/ · deploy/) 0 · 시험 단언(기대값) 0 · 운영을 치는 시험 실행 0.
worktree `/home/changkwon/qaredi-regress-20261013`(브랜치 `feat/regress-cleanup`) · 결과 폴더 `/home/changkwon/qaredi-regress-results-20261013/RC/`.
커밋: `01e8294`(코드·카탈로그·자체 시험) · 이 보고서는 다음 커밋.

## 1. 변경 파일

| 파일 | 무엇 |
|---|---|
| `tests/lib/results.py` | RC-1 시간 제한 · RC-2 묶음(세션)·남은 프로세스 정리 · RC-3 꼬리 줄 · RC-5 `--skip-known-fail` · RC-6 `--env-src` · RC-7 `compare` · 머리 주석 |
| `tests/run.sh` | 사용법 줄(새 인자 · `compare` · 회귀 권장 한 줄) · 사용법을 찍는 `sed` 줄 범위 · 첫 인자 허용 목록에 `compare` · 종료 코드 주석 |
| `tests/catalog.json` | `fields`(covers 규칙 · known_fail 뜻 · 새 칸 `timeout_sec`) · 19항목 `known_fail` · 8항목 `covers` 좁힘 |
| `tests/lib/selftest.sh` | 새 사례 50(247 → 297) |
| `tests/lib/contrast.py` | 끝에 `RESULT pass=N fail=M skip=0` 한 줄(세 출구 모두) — 지금 출력 그대로 |
| `tests/design_themes.php` (design.allowlist) | 끝에 `RESULT pass=$count fail=0 skip=0` 한 줄 |
| `tests/design_themes_browser.py` (design.browser) | 끝에 `RESULT pass=checks−실패 fail=실패 skip=0` 한 줄 |
| `$ROOT/.env` 를 직접 읽던 시험 22개 | 원본 경로만 `"${QCHECK_ENV_SRC:-$ROOT/.env}"` (목록 RC-6) |
| `docs/regress-cleanup-report.md` | 이 보고서(새 파일) |

## 2. RC 항목마다 한 일

### RC-1 시험마다 시간 제한
- 한도 = 카탈로그 `timeout_sec`(있으면) 아니면 `max(--timeout-min 600, est_sec × --timeout-factor 3)`. `--no-timeout` 이면 한도 없음(옛 동작). `--timeout-min`·`--timeout-factor` 가 0 이하면 rc 2.
- 넘으면 그 시험의 묶음(같은 세션 — RC-2)에 SIGTERM → 120초 기다림 → 남은 것만 SIGKILL. 판정 `unverified` · 코드 `timeout`(③ — `CODE_ORDER` 맨 앞 · 요약 ③ 줄 · `report --md` ④ 「timeout: 멈춘 시험 — 원문 끝 줄과 남은 프로세스를 보고 원인을 적는다」).
- 원문 끝에 실행기가 한 줄: `run.sh: 시간 제한 5초 넘음 — SIGTERM(묶음 1개) → 120초 안에 모두 끝남` (SIGKILL 을 썼으면 `→ 120초 뒤 남은 n개 SIGKILL`).
- 기록에 `time_limit` 칸. 시간 제한은 ③ 이라 실행 rc 를 1 로 만들지 않는다(③ 은 지금처럼 rc 에 넣지 않음).

### RC-2 시험이 남긴 프로세스 정리
- 시험을 `start_new_session=True` 로 띄운다(sid = 시험 pid). 끝나면(통과·실패·시간 제한·중단 모두 — `finally`) `/proc/<pid>/stat` 의 sid 가 같은 남은 프로세스에 SIGTERM → 10초 → SIGKILL. 좀비 · 실행기 자신 · 부모 · pid 1 · 실행기의 세션은 대상이 아니다. 보내기 직전에 sid 를 다시 읽어 확인한다(pid 재사용).
- 기록 `leftover: {count, names(명령 이름만 — 인자 0), cleaned, sigterm, sigkill, survived, outside_session, report_only, ports_open}`. count > 0 이면 요약 줄 `· 남은 프로세스 n 정리 — <id> (php×4)`, `report --md` ④ 에 「시험의 정리(trap) 결함 후보」. 판정은 바꾸지 않는다.
- **세션 밖 손주(memories_harness 의 `start_new_session` 등) — 지시서와 다르게 했다(7장 ①)**: 포트 차이 대신 **환경 표식**으로 찾는다. 실행기가 시험 자식 환경에 무작위 `QCHECK_TEST_TOKEN` 을 넣고, 끝난 뒤 `/proc/<pid>/environ` 에 그 값이 있는 **우리 소유** 프로세스 가운데 이름이 `php`·`python3`·`chrome`·`chromium`·`headless_shell` 인 것만 같은 순서로 정리한다(이름 밖은 「보고만」). 포트 차이(시험 전후 127.0.0.1 LISTEN)는 **보고만**(`ports_open` · 요약 「끝난 뒤 열린 127.0.0.1 포트 … 표식 없음, 보고만」).
- 중단(SIGTERM·Ctrl-C): 새 세션이라 터미널의 Ctrl-C 가 시험에 닿지 않으므로 같은 신호를 `killpg` 로 묶음에 넘기고 지금처럼 30초 → SIGTERM → 120초를 기다린 뒤 위 정리. SIGKILL 은 늘 SIGTERM 뒤에만.

### RC-3 통과를 통과로 세기
- `parse_counts`: 끝에서 거꾸로 볼 때 꼬리 줄(`^[A-Z][A-Z0-9_]*=[^ ]*(; [A-Z][A-Z0-9_]*=[^ ]*)*$`)·빈 줄은 건너뛴다. 합계 줄보다 **뒤에** `Traceback (most recent call last)` 가 있으면 건너뛰지 않고 「합계 줄 없음 — 합계 전에 멈춤」.
- 실제 원문의 합계가 지시서 예와 모양이 달라 둘을 더 받는다(7장 ②): `RESULT pass=75 fail=0`(skip 생략 — memories_harness 계열) · `PASS=196 FAIL=0 LEGACY_COMMON8_PASSED=not_claimed`(stage89_cross_regression — 형식 `pass_fail_upper`).
- `contrast.py`(통과 = ✔ 줄 수 146 · 실패 = CONTRAST_FAIL) · `design_themes.php`(21) · `design_themes_browser.py`(checks − 실패 / 실패, console 이 있으면 실패 ≥ 1) 끝에 `RESULT` 한 줄. 지금 출력은 그대로(stage7d3 의 `sed -n 's/^CONTRAST_FAIL=//p'` 그대로 동작).
- 옛 원문 다시 세기(run #1~#13 원문을 옛·새 `judge` 로 — 결과 폴더 `rejudge.py`, 결과 폴더 `06-rejudge-old-new.txt`): 실패 → 통과 12건(messenger_http #3·#4·#6 · stage89_cross_regression #4·#6 · qf12_server_http #6 · qf13h_privacy_browser #6 · qf12_shell_browser #4 · qf12_work_browser #4 · messenger_store_browser #4·#7 · messenger_window_browser #4). lib_contrast · design.allowlist · design.browser 는 옛 원문에 RESULT 줄이 없어 다시 세기로는 안 바뀌고 새 실행 #12 에서 통과(④), 판정이 나빠진 것 0. `community.stage10_suggestions` 는 판정 실패 그대로, 합계가 7/1 → 「합계 전에 멈춤」(안쪽 RESULT 뒤 하네스 트레이스백 — 지시서의 트레이스백 규칙대로).

### RC-4 `covers` 좁히기 — 표는 3장
- 규칙을 `fields.covers` 에 적었다. 대상 여섯(`src/views/shell_top.php` · `public/assets/app.js` · `public/assets/app.css` · `public/assets/community/editor.js` · `public/assets/community/work.js` · `public/assets/messenger.js`)을 덮던 61항목을 하나씩 원문과 대조했다.
- 뺀 것 8항목(11경로): 머리 주석 · `php -l` 목록 · `node --check` · 절대 경로(APP_BASE) grep 뿐이고 브라우저·HTTP 로 그 화면을 재지 않는 것.
- 둔 것: 그 파일의 특정 내용을 단언 · 그 파일이 그리는 셸·커뮤니티 화면을 브라우저로 잰다 · 그 파일이 실린 응답·창구를 HTTP 로 친다. **브라우저 시험은 정적 근거가 공통 검사뿐이어도 두었다**(셸·app.css·app.js 는 모든 셸 화면에 실리고, editor.js 는 커뮤니티 화면에 실린다 — 「그 파일이 그리는 화면을 브라우저로 잰다」). 더 좁히려면 이 판단을 메인이 바꾼다(3장 「브라우저 근거로만 둠」 칸).
- 확인(`03-list-u2-diff.txt`): `tests/run.sh list --select diff:8c36f8b..0ed47f0` — 이 커밋 전 카탈로그 **고름 69 · 돌릴 것 52** → 지금 **고름 62 · 돌릴 것 46** → `--skip-known-fail` 이면 **돌릴 것 31**(known_fail 건너뜀 15). 지시서의 「전 48」은 이 worktree 에서 다시 재지 못했다 — 같은 diff 로 잰 수가 52다(차이의 까닭 미확인).
- 계속 골라져야 하는 것 8개(`qf12_work_browser` · `qf12_shell_browser` · `messenger_store_browser` · `messenger_window_browser` · `design.browser` · `lib_contrast` · `security_lint` · `asset_version`) 전부 골라짐. **단 `tbox.messenger_window_browser` 는 RC-5 표에 있어 `--skip-known-fail` 이면 돌지 않는다** — 7장 ③.

### RC-5 원래 깨진 시험 — known_fail + 건너뛰기
- 지시서 표 19항목을 `status: known_fail` · `known_fail: {reason(표의 글 그대로), since 2026-10-13, expires 2026-11-12, evidence}` 로. evidence: q_launcher `q_launcher-post.log · run6-run7-compare.json`, qf9_pc_login `qf9-direct-candidate.log · qf9-direct-base.log`, 나머지 `run6-run7-compare.json`. 사용자 승인(2026-10-13 「회귀 카탈로그 정리 진행해」)은 `fields.known_fail` 에 적었다.
- `run --skip-known-fail`(그리고 `list --skip-known-fail`): 만료 전 known_fail 은 돌리지 않고 ③ `known_fail_skipped`. 요약 **머리 줄**(40줄 자르기에 걸리지 않는 자리)에 「③ known_fail 건너뜀 n — id … · 만료 …」, 몸통 ③ 줄에 하나하나 「사유」 만료, `report --md` ③·④. 만료가 지났거나 만료일이 없으면 건너뛰지 않는다(지금처럼 required 로 돈다). 인자가 없으면 동작 변화 0(자체 시험으로 확인).
- `run.sh` 사용법 줄과 `results.py` 머리 주석에 「회귀 권장: `run --select diff:<기준>..<후보> --skip-known-fail`」.

### RC-6 `.env` 원본 한 곳
- `run --env-src <파일>`: `lstat` 로 있는 일반 파일 · 링크 아님 · 내 소유 · 모드 정확히 0600 · 저장소 밖일 때만(아니면 rc 2 — 실행 기록도 만들지 않음). 시험 자식 환경에 `QCHECK_ENV_SRC` · `QF2_ENV_SRC` · `DBGS_ENV_SRC` · `STAGE7C_ENV_SRC` · `STAGE7D3_ENV_SRC` · `D74_ENV_SRC` · `PK_ENV_SRC` 를 같은 경로로(카탈로그 cmd 의 `X=…` 가 있으면 그것이 이긴다). 실행기는 파일을 열지 않는다. 요약 기록엔 경로만(`env_src`).
- `$ROOT/.env` 를 직접 읽던 시험 — 조사(임시 인스턴스 · helper 제외 카탈로그 전체) 결과 **22개**, 원본 경로만 바꿨다:
  `community/stage4d.sh:240` · `stage5a.sh:268` · `stage5a2.sh:261` · `stage5c.sh:247` · `stage5d.sh:227` · `stage5e.sh:226` · `stage6a.sh:226` · `stage6b.sh:224` · `stage6c.sh:223` · `stage7a.sh:218` · `stage7b.sh:221` · `stage7d1.sh:217` · `stage7d2.sh:347` · `stage7d4.sh:255` · `stale_modules.sh:97` · `qf4_draft_key.sh:250` · `tests/upgrade_smoke.sh:205` · `qf4a.sh:92` · `qf4_restore_owner.sh:102` · `scan_pending_dryrun.sh:31·44`(존재 확인 줄과 쓰는 줄 둘 — 둘 다 원본 경로) · `g0_private_grants.sh:266`(`${G0_ENV_SRC:-${QCHECK_ENV_SRC:-$ROOT/.env}}` — 자기 변수가 먼저) · `dbg_s2_security.sh:178`(`${DBGS2_ENV_SRC:-${QCHECK_ENV_SRC:-…}}`).
- 이미 여섯 이름 가운데 하나로 읽던 것(편집 0): qf2_search · qf2b · qf3a(두 모듈) · qf3b(두 모듈) · qf3c_zip · qf4b1 · qf4_p7 · f70 · sec4 · verify_backup_fresh · perf.qf2_search(QF2) · dbg_s_security(DBGS→QF2) · stage7c · stage7d3 · d74 · passkey · dbg_u/u2/u3(QF2).
- 정적 규칙 R20(비밀 사본)·R15(비밀 인자)를 이 커밋 전 트리와 견줘 새 발견 0(시험 스크립트 부분 — `07-static-r20-r15.txt`).

### RC-7 `tests/run.sh compare <run_a> <run_b> [--md]`
- 두 실행 모두에 있는 시험마다 판정 · 코드 · rc · 합계(pass/fail/skip — 두 실행의 summary.json 기록 그대로) · 실패 단언 이름 집합(원문 전체에서 `first_mark` 와 같은 `RE_FAIL_MARK`, 기대값 꼬리 ` (기대=…` 부터 끝까지 뗌, 그 실행의 저장소 뿌리 경로 → `‹root›`, `redact` 뒤).
- 줄: `SAME` · `DIFF <id>  verdict a→b · code a→b · rc a→b · 합계 a→b · +단언 n / −단언 m`(+/− 이름 4개까지 들여 찍음) · `ONLY_A` · `ONLY_B`. 맨 위 한 줄 `DIFF n · SAME m · ONLY_A x · ONLY_B y — A #… → B #…`. `--md` 는 표 + 단언 이름 차이(12개까지). rc: 0 모두 SAME · 1 다름 있음 · 2 없는 실행.
- **받아들이기 확인**(출력 원문 4장 ②): `compare 7 6` 이 `tbox.messenger_store_browser` 를 `DIFF … rc 0→1 · +단언 1 / −단언 0` 으로, `core.qf9_pc_login` 을 `DIFF … verdict unverified→fail · code refused→- · rc 3→1 · …` 으로 낸다.

## 3. RC-4 표 — 대상 여섯을 덮던 항목

근거는 이 worktree 의 `파일:줄`. 「공통」 = 정적 공통 검사(node --check·esprima·php -l·innerHTML grep·절대 경로 grep·새 색값 grep·import 버전). 셸 = `shell_top.php`, css = `app.css`.

### 3-1. 뺀 것 (8항목 · 11경로)

| 시험 | 뺀 경로 | 근거(그 이름이 나오는 줄 전부) | 둔 경로와 근거 |
|---|---|---|---|
| tbox.qf3a | 셸 | `tests/qf3a_tbox.sh:431-434` 바뀐 PHP `php -l` 목록뿐 · 브라우저·HTML 응답 단언 0(needs sudo 만) | — |
| tbox.qf3b | 셸 · app.js | 셸: `tests/qf3b_tbox.sh:378-382` `php -l` 목록 · app.js: `:389-390` `'/api/'` 줄이 APP_BASE 로 감싸였나(절대 경로 grep) · 브라우저 0 | — |
| tbox.changelog_admin_only | app.js | `tests/changelog_admin_only.sh:8` 머리 주석 · `:138` `node --check` | 셸 둠: `:193-219` 셸이 그린 `/tbox/login`·`/tbox/` 응답 본문을 센다(HTTP) |
| core.qf8_token_write | app.js | `tests/qf8_token_write.sh:6` 머리 주석 · `:240` `node --check` | — |
| core.qf9_pc_login | app.js | `tests/qf9_pc_login.sh:7` 머리 주석 · `:293` `node --check` | — |
| core.qf10_multi_scope | app.js | `tests/qf10_multi_scope.sh:7` 머리 주석 · `:273` `node --check` | — |
| design.allowlist | 셸 | `tests/design_themes.php` 17줄 어디에도 이름 0(`src/Design.php` 만 부름) | — |
| tbox.passkey_api (helper) | 셸 | `tests/passkey_api.py` 에 이름 0 — 부르는 셸 `tests/passkey.sh:157` 의 `php -l` 목록뿐 · 창구(HTTP) 시험 | — |

### 3-2. 둔 것 — 특정 내용 단언 · HTTP 근거가 있는 것

| 시험 | 둔 경로 | 근거 | 공통 검사로만 나오는 줄(참고) |
|---|---|---|---|
| tbox.changelog | 셸 · app.js | app.js `tests/changelog.sh:341` initChangelogDialog 하나 · 브라우저 `:260` `/tbox/` | `:320` php -l · `:342` innerHTML |
| tbox.help_button | 셸 · app.js | app.js `tests/help_button.sh:147` initHelpDialog · 셸 `:154-156` ui_snapshot 9화면 1440(브라우저) | `:50` php -l · `:148` innerHTML |
| tbox.stage26 | app.js | `tests/stage26.sh:528` app.js 의 프롬프트 글을 읽어 대조 · 브라우저 `:595` | — |
| launcher.q_launcher | app.js | `tests/q_launcher.sh:278` `var APP_BASE =` 정의 1곳 | `:275-276` 절대 경로 grep |
| community.stage7d4 | editor.js | `tests/community/stage7d4.sh:937` 멘션 `@` 키 · `:939` mentionList 클래스 · 브라우저 `browser_7d4.py:226` | `:933` 색값 grep |
| community.asset_version | 셸 | `tests/community/asset_version.sh:155` 셸이 editor.js 를 `type=module …?v=` 로 부른다 | — |
| community.stale_modules | editor.js | `tests/community/stale_modules.sh:64` embedHost import · 브라우저 `browser_stale_modules.py:91` | — |
| community.qf4_draft_key | app.js · editor.js | editor.js `tests/community/qf4_draft_key.sh:211-217` · app.js `:223-224` clearMyDrafts 한 곳 | — |
| community.qf2_search | 셸 | `tests/community/qf2_search.sh:803` tbox_person_label 은 셸·런처·routes 셋 · 브라우저 `qf2_search_modal.py:111` | `:797` php -l |
| tbox.dbg_u2_ui | 셸 · app.js · css · editor.js | app.js `tests/dbg_u2_ui.sh:212·214` 역할 표기 · 브라우저 `tests/browser/dbg_u2_ui.py:366`(셸) `:629`(`/community/p/`) | — |
| tbox.dbg_u3_ui | 셸 · app.js · css · editor.js | editor.js `tests/dbg_u3_ui.sh:272-274` 댓글 링크 · css `:285-286` 터치 규칙 · 브라우저 `dbg_u3_ui.py:371·409` | — |
| community.stage7d3 | 셸 · app.js · css | css `tests/community/stage7d3.sh:480-500` 대비·돌연변이 · 셸 `:292` `<html>` 줄 diff · 브라우저 `tests/browser/hc_screens.py:158` | app.js 는 `:254` 허용 목록뿐 — 브라우저(고대비 화면)로 둠 |
| community.lib_contrast | css | `tests/lib/contrast.py:183` app.css 대비 값 | — |
| design.browser | 셸 | `tests/design_fixture.php:46` 셸을 require 해 그림 · 브라우저 `tests/design_themes_browser.py:49` | — |
| tbox.stage89_cross_regression | 셸 · app.js · css | HTTP `tests/stage89_cross_regression/suite.py:71` GET `/tbox/assets/app.css` · 브라우저 `:243` | — |
| core.ux_r1_ui | 셸 · app.js · css · editor.js | `tests/ux_r1_ui.sh:225-229` app.js probeSession · 편집기 background · 브라우저 `ux_r1_ui.py:359·453` | — |
| tbox.messenger_http | messenger.js | `tests/community/messenger_http.py:60-61` messenger.js 의 drafts.js import 버전 | — |
| community.qf12_shell_browser | 셸 · app.js · css · editor.js · messenger.js | U2 주 시험 — `qf12_shell_browser.py:192`(css `.sidebar[hidden]`) · `:257·284`(메신저 타일) · `:620·630`(editor.js) | — |
| community.qf12_work_browser | 셸 · app.js · css · editor.js · work.js | `qf12_work_browser.py:1085-1220` work.js 를 import 해 같은 규칙 대조 · `:1571` app.js 첫 그리기 | — |
| tbox.messenger_store_browser | app.js · messenger.js | `messenger_store_browser.py:130` messenger.js 순서 · `:264-273` app.js 로그아웃 | — |
| tbox.messenger_window_browser | 셸 · app.js · css · messenger.js | `messenger_window_browser.py:190-257` 셸 응답의 messenger.js 한 번 · `:441-442` app.js focusout | — |
| community.qf13h_privacy_browser | 셸 · app.js | `qf13h_privacy_browser.py:135-156` app.js 로그아웃이 지운다 | — |
| tbox.qf13_consent_http | 셸 · css · editor.js · messenger.js | 브라우저 `tests/qf13_consent_http.py:696` · `:864`(메신저 AI 동의 창) · `:906-953`(editor.js AI) | — |

### 3-3. 둔 것 — 브라우저 근거로만 둠 (정적 근거는 공통 검사뿐 · 메인 검토 후보)

| 시험 | 둔 경로 | 브라우저 근거(띄우는 화면) | 공통 검사로만 나오는 줄 |
|---|---|---|---|
| tbox.f51 (manual_only · writes_prod) | app.js | `tests/f51.sh:443` t-box 화면 | `:422` innerHTML |
| tbox.f52 (manual_only · writes_prod) | app.js | `tests/f52.sh:367·405` `/login` · `/` | `:700` innerHTML |
| tbox.fetch_hosts (writes_prod) | 셸 | `tests/fetch_hosts.sh:441` t-box 셸 화면 | `:505` php -l |
| community.stage3 | editor.js | `tests/community/browser_smoke.py:242` 편집기를 진짜 브라우저로 누른다 | `stage3.sh:669` 절대 경로 |
| community.stage4b | 셸 · editor.js | 위 `browser_smoke.py` | `stage4b.sh:562` php -l · `:574` esprima |
| community.stage4c | editor.js | 위 `browser_smoke.py` | `stage4c.sh:617` esprima |
| community.stage7d2 · browser_7d2 | 셸(7d2) · editor.js | `tests/community/browser_7d2.py:225` `/community/` | `stage7d2.sh:1037` 색값 |
| community.browser_7d4 · browser_stale_modules (helper) | editor.js | `browser_7d4.py:226` · `browser_stale_modules.py:91` | — |
| tbox.qf3c_zip | app.js | `tests/browser/qf3c_zip.py:170` `/tbox/` 파일 목록 zip | `qf3c_zip.sh:831` APP_BASE |
| tbox.dbg_s_security | app.js | `tests/browser/dbg_s_screens.py:75` `/activity` · `/settings` | `dbg_s_security.sh:756` esprima |
| tbox.dbg_u_ui · browser_dbg_u_ui | 셸 · app.js · css · editor.js | `tests/browser/dbg_u_ui.py:269`(셸) · `:497`(`/community/p/`) | — |
| tbox.d74_username · browser_d74_username | app.js | `tests/browser/d74_username.py:162` `/tbox/users` | — |
| tbox.browser_dbg_u2_ui · browser_dbg_u3_ui · browser_ux_r1_ui · browser_hc_screens (helper) | 부르는 셸과 같음 | 위 3-2 의 같은 브라우저 파일 | — |
| tbox.passkey · browser_passkey | 셸 · app.js · editor.js | `tests/browser/passkey.py:208`(`/tbox/settings`) · `:284`(`/community/`) · `:324`(editor.js toLogin) · `:331`(app.js returnPath) | `passkey.sh:157` php -l |
| design.live | 셸 | `tests/design_live_browser.py:39·52` 운영 셸 화면 테마 | — |
| community.stage8b | editor.js | `tests/community/browser_8b.py:42-49` `/community/p/` 내보내기 단추 | — |
| community.stage89_ui | editor.js | `tests/community/browser_9_automation_ui.py:42` `/community/…` | — |
| community.stage9d | 셸 | `tests/community/browser_9d.py:41` 커뮤니티 셸 화면 | — |
| community.stage10_suggestions | 셸 · editor.js | `tests/community/browser_10.py:20` `/community/p/` | — |
| tbox.g0_private_grants · browser_g0_private_grants | (셸) · app.js · css | `tests/browser/g0_private_grants.py:139` `/tbox/?folder=` | `g0_private_grants.sh:847` esprima · `:853` 새 색값 |
| tbox.qf13_retention_http | app.js | `tests/qf13_retention_http.py:987·1023` `/tbox/settings` · `/admin` | — |

손대지 않은 것: `core.security_lint`(`public/assets/*.js` 등 glob) · `core.wiki_check`(`wiki/**`).

## 4. 실행한 명령과 출력 원문 (결과 폴더 `/home/changkwon/qaredi-regress-results-20261013/RC/`)

| 파일 | 명령 |
|---|---|
| `01-selftest.txt` | `bash tests/lib/selftest.sh` |
| `02-compare-7-6.txt` · `02-compare-7-6.md` | `bash tests/run.sh compare 7 6` · `--md` |
| `03-list-u2-diff.txt` | 이 커밋 전 카탈로그와 지금 카탈로그로 `list --select diff:8c36f8b..0ed47f0` · `--skip-known-fail` |
| `04-run-static.txt` | `bash tests/run.sh run --select id:community.lib_contrast --select id:design.allowlist --select id:core.security_lint` (#12) + `report 12 --md` |
| `05-env-src-run.txt` | `bash tests/run.sh run --select id:tbox.qf3b --select id:community.stale_modules --env-src …/env-src` (#13) |
| `06-rejudge-old-new.txt` | run #3~#7 원문을 옛·새 `judge` 로 다시 셈 |
| `07-static-r20-r15.txt` · `08-checks.txt` | R20·R15 전후 대조 · ⑥ 문법·린트 |

실행 기록 `~/.cache/qaredi-check/runs/` 에 이번에 더한 번호: #11(커밋 전 dirty 트리에서 ④ 와 같은 선택 — 같은 결과, 증거는 #12) · #12 · #13. #6 · #7 은 읽기만.
그 밖 손 확인(결과 파일 없음 — 세션 임시 폴더의 따로 둔 실행 폴더·잠금): 실행기 python 에 SIGTERM → 시험(`trap … TERM`)이 `trapped-TERM` 을 찍고 rc 7 로 끝남 · 같은 묶음의 `sleep 100` 도 끝남 · 요약 「중단됨」.

### ① 자체 시험
```
$ bash tests/lib/selftest.sh   (커밋 01e8294 · 깨끗한 트리)
③-2 꼬리 줄 · 트레이스백 · 대문자 합계 (RC-3)
  ✔ RESULT 뒤 꼬리 줄 → 통과로 셈(messenger_http 형)  (기대=verdict=pass code=- pass=75 fail=0 skip=0 format=result violation=0 실제=verdict=pass code=- pass=75 fail=0 skip=0 format=result violation=0)
  ✔ RESULT(skip 포함) 뒤 꼬리 줄 → 통과  (기대=verdict=pass code=- pass=3 fail=0 skip=0 format=result violation=0 실제=verdict=pass code=- pass=3 fail=0 skip=0 format=result violation=0)
  ✔ 꼬리 줄 뒤 트레이스백(rc 1) → 실패 · 합계 전에 멈춤(messenger_store_browser #6 형)  (기대=verdict=fail code=- pass=0 fail=0 skip=0 format=- violation=0 실제=verdict=fail code=- pass=0 fail=0 skip=0 format=- violation=0)
  ✔ 꼬리 줄 뒤 트레이스백(rc 0) → 실패(형식 위반) — 트레이스백은 건너뛰지 않는다  (기대=verdict=fail code=- pass=0 fail=0 skip=0 format=- violation=1 실제=verdict=fail code=- pass=0 fail=0 skip=0 format=- violation=1)
  ✔ PASS=196 FAIL=0 + 꼬리 → 통과(stage89_cross_regression 형)  (기대=verdict=pass code=- pass=196 fail=0 skip=0 format=pass_fail_upper violation=0 실제=verdict=pass code=- pass=196 fail=0 skip=0 format=pass_fail_upper violation=0)
  ✔ PASS=5 FAIL=2 + 꼬리 · rc 1 → 실패 2  (기대=verdict=fail code=- pass=5 fail=2 skip=0 format=pass_fail_upper violation=0 실제=verdict=fail code=- pass=5 fail=2 skip=0 format=pass_fail_upper violation=0)
  ✔ 꼬리 줄만 있고 합계 없음(stage9d 형) → 여전히 형식 위반  (기대=verdict=fail code=- pass=0 fail=0 skip=0 format=- violation=1 실제=verdict=fail code=- pass=0 fail=0 skip=0 format=- violation=1)
  ✔ CONTRAST_FAIL=0 뒤 RESULT 줄(contrast.py) → 통과  (기대=verdict=pass code=- pass=146 fail=0 skip=0 format=result violation=0 실제=verdict=pass code=- pass=146 fail=0 skip=0 format=result violation=0)

── RC-1 시간 제한 — sleep 999 · est_sec 1 · --timeout-min 5 → 약 5초 뒤 ③ timeout
  ✔ 약 5초 뒤 끝남 (5초)
  ✔ 요약에 ③ timeout
  ✔ 시간 제한은 ③ — 실패 아님 rc 0  (기대=0 실제=0)
  ✔ 원문 끝에 실행기 한 줄
  ✔ 기록: verdict unverified · code timeout · time_limit 5  (기대=unverified timeout 5.0 실제=unverified timeout 5.0)
  ✔ 그 sleep 999 가 남지 않음  (기대=0 실제=0)
  ✔ report --md 다음 조치에 timeout 한 줄
  ✔ 카탈로그 timeout_sec 가 있으면 그것(1초)
  ✔ --no-timeout 이면 시간 제한 없음
  ✔ --timeout-min 0 → rc 2  (기대=2 실제=2)

── RC-2 남은 프로세스 — 같은 세션 · 새 세션 손주(표식) · 이름 제한
  ✔ 판정은 그대로 통과 rc 0  (기대=0 실제=0)
  ✔ 요약 「남은 프로세스 n 정리」
  ✔ 기록 leftover: count 3 · names · 정리 2 · 세션 밖 2 · 보고만 sleep  (기대=3 python3,sleep,sleep 2 python3,sleep sleep 실제=3 python3,sleep,sleep 2 python3,sleep sleep)
  ✔ 같은 세션의 sleep 600 이 실제로 없어짐(pid 2699256)
  ✔ 새 세션 손주 python3(표식)도 없어짐(pid 2699257)
  ✔ 새 세션 sleep(이름 밖)은 보고만 — 남아 있음(pid 2699258) → 자체 시험이 치움
  ✔ report --md 다음 조치에 남은 프로세스
  ✔ 실행기를 부른 셸(자체 시험)은 살아 있음

── RC-5 --skip-known-fail — 만료 전 건너뜀 · 만료 뒤 돎 · 인자 없으면 돎
  ✔ 요약 머리에 ③ known_fail 건너뜀(늘 보임)
  ✔ ③ 줄에 사유·만료
  ✔ 만료 전 known_fail 은 돌지 않음(원문 0)
  ✔ 만료 지난 known_fail 은 --skip-known-fail 이어도 돎
  ✔   만료 → required 로 실패
  ✔ 인자가 없으면 known_fail 도 돎(옛 동작)
  ✔ list --skip-known-fail 도 셈

── RC-6 --env-src — 0600 · 내 소유 · 저장소 밖만 · 경로만 넘김
  ✔ 0644 → rc 2  (기대=2 실제=2)
  ✔   사유
  ✔ 없는 파일 → rc 2  (기대=2 실제=2)
  ✔ 저장소 안 → rc 2  (기대=2 실제=2)
  ✔   사유
  ✔ 심볼릭 링크 → rc 2  (기대=2 실제=2)
  ✔ 거부된 실행은 실행 기록을 만들지 않음  (기대=19 실제=19)
  ✔ 0600 · 저장소 밖 → 돈다 rc 0  (기대=0 실제=0)
  ✔ 시험 자식 환경에 일곱 이름 모두 같은 경로  (기대=7 실제=7)
  ✔ 원문·요약에 파일 내용 없음

── RC-7 compare — rc 0→1 · 꼬리 줄 뒤 트레이스백도 DIFF
  ✔ 맨 위 한 줄  (기대=DIFF 1 · SAME 1 · ONLY_A 0 · ONLY_B 0 실제=DIFF 1 · SAME 1 · ONLY_A 0 · ONLY_B 0)
  ✔ DIFF rc 0→1 · verdict pass→fail
  ✔ SAME
  ✔ DIFF 가 있으면 rc 1  (기대=1 실제=1)
  ✔ 같은 실행끼리 rc 0  (기대=0 실제=0)
  ✔ --md 표
  ✔ 없는 실행 → rc 2  (기대=2 실제=2)

… (① ~ ⑩ 의 옛 사례 247 은 결과 폴더 01-selftest.txt 원문)
════════════════════════════════════════════════════════════
통과 297 · 실패 0
RESULT pass=297 fail=0 skip=0
rc=0
```

### ② `tests/run.sh compare 7 6` (받아들이기 확인)
```
$ bash tests/run.sh compare 7 6
DIFF 3 · SAME 19 · ONLY_A 0 · ONLY_B 43 — A #7(88dafcd) → B #6(15babdb)
DIFF   launcher.q_launcher  합계 89/14/1→86/23/1 · +단언 9 / −단언 0
         + ✖ GET /tbox/ 격자 줄(shell-tools) 1개
         + ✖ GET /tbox/ 앱 격자 details#app-grid 1개
         + ✖ GET /tbox/ 옛 앱 아이콘 줄 0개
         + ✖ GET /tbox/activity 격자 줄(shell-tools) 1개
         + … 외 5
DIFF   tbox.messenger_store_browser  rc 0→1 · +단언 1 / −단언 0
         + Traceback (most recent call last):
DIFF   core.qf9_pc_login  verdict unverified→fail · code refused→- · rc 3→1 · 합계 0/0/0→108/38/0 · +단언 46 / −단언 0
         + ✖   (값) token_scopes — 새 판은 원자 여섯(Q:F-10) · 기준 판은 옛 사다리 넷
         + ✖   같은 링크를 로그인 안 된 브라우저로 → 302 /tbox/(아직 쓸 수 있다)
         + ✖   그 목록의 로그인 전용 토큰에 bound_ip 127.0.0.1 · 다른 범위는 null
         + ✖   그 브라우저는 여전히 claude_employee02 · 세션 행 수 그대로
         + … 외 42
ONLY_B tbox.f51
ONLY_B tbox.f52
ONLY_B tbox.fetch_hosts
ONLY_B tbox.stage26
ONLY_B community.browser_7c_nginx
ONLY_B community.browser_7d2
ONLY_B community.browser_7d4
ONLY_B community.browser_stale_modules
ONLY_B tbox.browser_dbg_u_ui
ONLY_B tbox.browser_d74_username
ONLY_B tbox.browser_dbg_u2_ui
ONLY_B tbox.passkey_api
ONLY_B tbox.browser_passkey
ONLY_B tbox.browser_dbg_u3_ui
ONLY_B community.browser_hc_screens
ONLY_B tbox.browser_g0_private_grants
ONLY_B core.browser_ux_r1_ui
ONLY_B core.security_lint
ONLY_B community.asset_version
ONLY_B community.lib_contrast
ONLY_B design.allowlist
ONLY_B tbox.qf3c_zip
ONLY_B tbox.dbg_s_security
ONLY_B tbox.messenger_http
ONLY_B community.stage7c
ONLY_B community.stage7d2
ONLY_B community.stage7d4
ONLY_B community.stage7d3
ONLY_B design.browser
ONLY_B community.stage9d
ONLY_B tbox.stage89_cross_regression
ONLY_B tbox.g0_private_grants
ONLY_B community.qf12_server_http
ONLY_B community.qf12_shell_browser
ONLY_B community.qf12_work_browser
ONLY_B tbox.messenger_window_browser
ONLY_B community.qf13h_privacy_browser
ONLY_B tbox.dbg_u_ui
ONLY_B tbox.d74_username
ONLY_B tbox.dbg_u2_ui
ONLY_B tbox.passkey
ONLY_B tbox.dbg_u3_ui
ONLY_B core.ux_r1_ui
SAME   tbox.qf3a
SAME   tbox.qf3b
SAME   tbox.changelog_admin_only
SAME   tbox.changelog
SAME   tbox.help_button
SAME   community.stage3
SAME   community.stage4b
SAME   community.stage4c
SAME   community.stale_modules
SAME   community.qf2_search
SAME   design.live
SAME   community.stage8b
SAME   community.stage89_ui
SAME   community.stage10_suggestions
SAME   tbox.qf13_consent_http
SAME   tbox.qf13_retention_http
SAME   core.qf8_token_write
SAME   core.qf10_multi_scope
SAME   community.qf4_draft_key
» 단언 이름 전부: tests/run.sh compare 7 6 --md · 원문: tests/run.sh show <실행> <id>
로컬 요약 — 독립 검토(②)·관문 ③ 을 대신하지 않음
rc=1
```

### ③ U2 diff 고르기
```
$ git stash 없이 — 이 커밋 전(HEAD 4185eb7) 카탈로그로: git show HEAD:tests/catalog.json > /tmp/… ; tests/run.sh list --catalog <그것> --select diff:8c36f8b..0ed47f0 | head -1
카탈로그 /tmp/claude-1001/-var-www/1a149d2b-f094-4a85-98c3-4825824abda4/scratchpad/catalog-head.json · 전체 163 · 고름 69 · 돌릴 것 52 · 기본 제외 13 · 선택 diff:8c36f8b..0ed47f0
$ bash tests/run.sh list --select diff:8c36f8b..0ed47f0
카탈로그 ~/qaredi-regress-20261013/tests/catalog.json · 전체 163 · 고름 62 · 돌릴 것 46 · 기본 제외 12 · 선택 diff:8c36f8b..0ed47f0
diff 8c36f8b..0ed47f0 · 바뀐 파일 31
$ bash tests/run.sh list --select diff:8c36f8b..0ed47f0 --skip-known-fail
카탈로그 ~/qaredi-regress-20261013/tests/catalog.json · 전체 163 · 고름 62 · 돌릴 것 31 · 기본 제외 12 · known_fail 건너뜀 15 · 선택 diff:8c36f8b..0ed47f0
diff 8c36f8b..0ed47f0 · 바뀐 파일 31
known_fail 건너뜀(--skip-known-fail · 만료 전) 15:
$ (위 둘째 출력에서) 계속 골라져야 하는 여덟:
  community.qf12_work_browser 골라짐
  community.qf12_shell_browser 골라짐
  tbox.messenger_store_browser 골라짐
  tbox.messenger_window_browser 골라짐
  design.browser 골라짐
  community.lib_contrast 골라짐
  core.security_lint 골라짐
  community.asset_version 골라짐
(전체 목록은 결과 폴더 03-list-u2-diff.txt)
```

### ④ RC-3 정적 셋
```
$ bash tests/run.sh run --select id:community.lib_contrast --select id:design.allowlist --select id:core.security_lint   (커밋 01e8294 · 깨끗한 트리)
점검(로컬) #12 · 22cbebc · 2초 · 3/163
시험 통과 3 · 실패 0 · ③ 0 │ 항목 통과 168 · 실패 0 · SKIP 0
원문 ~/.cache/qaredi-check/runs/12/raw/ (0600) · 보고 » tests/run.sh report 12 --md
로컬 요약 — 독립 검토(②)·관문 ③ 을 대신하지 않음
rc=0
$ tests/run.sh report 12 --md
### 점검(로컬) #12 보고 조각

- 트리 `22cbebc` · 2026-10-13 15:22 · 2초 · 카탈로그 `~/qaredi-regress-20261013/tests/catalog.json`(sha256 24b6603) · 선택 `id:community.lib_contrast`, `id:design.allowlist`, `id:core.security_lint` · 고름 3/163
- 숫자는 `tests/run.sh` 가 원문 끝의 합계 줄과 종료 코드로 센 것이다. 로컬 요약 — 독립 검토(②)·관문 ③ 을 대신하지 않음.

**① 안전한 이유(증거)**
- 통과 3개 · 항목 통과 168 · SKIP 0 (요약 합계)
  - `core.security_lint` 통과 1 · 실패 0 · SKIP 0 (rc 0, 형식 static) — 원문 `~/.cache/qaredi-check/runs/12/raw/core.security_lint.log`
  - `community.lib_contrast` 통과 146 · 실패 0 · SKIP 0 (rc 0, 형식 result) — 원문 `~/.cache/qaredi-check/runs/12/raw/community.lib_contrast.log`
  - `design.allowlist` 통과 21 · 실패 0 · SKIP 0 (rc 0, 형식 result) — 원문 `~/.cache/qaredi-check/runs/12/raw/design.allowlist.log`

**② 불안전한 이유**
- (실패 0)

**③ 미검증**
- (없음)

**④ 다음 조치**
- (없음)
```

### ⑤ RC-6 env-src 로 qf3b · stale_modules
```
$ bash tests/run.sh run --select id:tbox.qf3b --select id:community.stale_modules --env-src /home/changkwon/qaredi-regress-results-20261013/env-src   (커밋 01e8294 · 포트 8166·8168·8096·8768·8421~8425 비어 있음 확인 뒤)
점검(로컬) #13 · 22cbebc · 1분 39초 · 2/163
시험 통과 0 · 실패 2 · ③ 0 │ 항목 통과 53 · 실패 4 · SKIP 0
✖ tbox.qf3b 실패 3 (rc 1) — ✖ public/index.php — 기준 판(a0d5503) 대비 바뀐 줄 0(새 라우트 0 · 라운드 C zip 한 줄·주석 제외)  (기대=0 실제=168)   » tests/run.sh show 13 tbox.qf3b
✖ community.stale_modules 실패 1 (rc 1) — ✖ 브라우저 실패 0  (기대=0 실제=)   » tests/run.sh show 13 community.stale_modules
원문 ~/.cache/qaredi-check/runs/13/raw/ (0600) · 보고 » tests/run.sh report 13 --md
로컬 요약 — 독립 검토(②)·관문 ③ 을 대신하지 않음
rc=1
$ (summary.json #13) tbox.qf3b rc 1 · 47/3 · 53.9초 · 한도 900 · leftover 0 / community.stale_modules rc 1 · 6/1 · 44.5초 · 한도 720 · leftover 0
$ show 13 tbox.qf3b --grep ✖ → 기준 판(a0d5503) 대비 「바뀐 줄 0」 셋 · show 13 community.stale_modules → 브라우저 #community-more > summary 30초 시간 초과(셸 바뀜 — 옛 기대값)
```

### ⑥ 문법 · 린트
```
$ PYTHONPYCACHEPREFIX=<scratch> python3 -m py_compile tests/lib/results.py tests/lib/contrast.py tests/design_themes_browser.py
rc=0
$ bash -n tests/run.sh tests/lib/selftest.sh + 바꾼 시험 스크립트 22개
rc=0 (본 파일 24개)
$ php -l tests/design_themes.php
No syntax errors detected in tests/design_themes.php
$ python3 -m json.tool tests/catalog.json >/dev/null
rc=0
$ bash scripts/security_lint.sh | tail -1
결과: 통과 (금지 패턴 0건)
$ git diff --check 4185eb7 HEAD
rc=0
$ git diff --name-only 4185eb7 HEAD -- src public db deploy | wc -l
0
$ python3 tests/static/r20_secret_tmp_copy.py --root <4185eb7 풀어 놓은 폴더> --walk   vs   --root . --walk   (FINDING 줄의 「n행」 숫자는 지우고 견줌)
전 2건 · 뒤 2건
FINDING 같음
$ python3 tests/static/r15_secret_args.py --root <4185eb7 풀어 놓은 폴더> --walk   vs   --root . --walk   (FINDING 줄의 「n행」 숫자는 지우고 견줌)
전 25건 · 뒤 25건
FINDING 같음
```

## 5. 못 한 것과 이유

- **완료 기준 ⑤ 는 「시작하는지만」 확인했다**: #13 에서 `tbox.qf3b`(rc 1 · 47/3) · `community.stale_modules`(rc 1 · 6/1) 모두 rc 3·4 가 아니다(임시 .env 단계 통과 — 원문 「임시 인스턴스가 떴다(401)」). 둘 다 **실패**다 — qf3b 는 고정 기준 판(a0d5503) 대비 「차이 0」 셋, stale_modules 는 셸 메뉴(`#community-more > summary`)를 30초 못 찾음. 옛 기대값 문제로 보이나 이번 범위(시험 기대값) 밖이라 고치지 않았고, known_fail 로도 넣지 않았다(지시서 표에 없음 — 넣을지는 메인·사용자 판단).
- **나머지 RC-6 시험 20개(qf3b · stale_modules 밖)는 돌리지 않았다** — 지시서가 임시 인스턴스 시험은 이 둘만 허락. 원본 경로 한 줄 편집은 `bash -n` 과 R20·R15 전후 대조로만 확인 — 실제로 시작하는지는 **미검증**.
- **운영을 치는 시험 · writes_prod · 회귀 전체(U2 diff 46개)는 돌리지 않았다**(지시서 금지). 그래서 RC-1·RC-2 가 run #6 의 qf9 「동시 넷」 멈춤을 실제로 끊는지는 **미검증** — 자체 시험의 흉내(sleep 999 · 남긴 sleep · 새 세션 python3)로만 확인했다.
- RC-2 포트 갈래는 「보고만」(7장 ①) — 표식 없이 새 세션으로 뜬 손주는 정리하지 않는다.
- `community.stage9d` 형식 위반은 남는다(원문에 합계 줄이 없다 — 수정 파일 밖, 6장).
- 요약의 「남은 프로세스 n 정리」 줄은 몸통 줄이라 40줄 상한에 걸리면 잘릴 수 있다(`summary <n> --all` · `report --md` ④ 에는 늘 있다). known_fail 건너뜀은 머리 줄이라 잘리지 않는다.
- 지시서 「전 48」을 다시 재지 못했다 — 이 worktree 에서 같은 diff 로 잰 이 커밋 전 수는 고름 69 · 돌릴 것 52.

## 6. 다음 조치(메인)

- 3-3 「브라우저 근거로만 둠」을 더 좁힐지 결정 — 그 표의 항목은 정적 근거가 공통 검사뿐이다. 좁히면 U2 같은 셸 변경에서 더 빠진다.
- `tbox.messenger_window_browser` 를 known_fail 로 둔 채 회귀 권장 명령(`--skip-known-fail`)을 쓰면 U2 를 재던 이 시험이 돌지 않는다 — 셸·메신저를 바꾸는 묶음에서는 `--select id:tbox.messenger_window_browser` 를 따로 돌리거나 known_fail 을 풀 것.
- `community.stage9d` 는 여전히 「합계 줄 없음 → 형식 위반」(원문에 PASS 줄만 있고 합계가 없다) — `tests/community/briefing_serve.py`(또는 `browser_9d.py`) 끝에 RESULT 한 줄이 필요(이번 수정 파일 밖).
- 옛 실행(#1~#13 가운데 이 커밋 전)의 기록은 옛 셈법이다 — `compare` 는 기록 그대로 견주므로 새 셈법과 섞어 볼 때 판정 줄을 조심.

## 7. DESIGN · 지시서와 다르게 한 것

① **RC-2 세션 밖 손주**: 지시서는 「시험 전후 127.0.0.1 LISTEN 차이로 찾은 포트의 주인」을 정리하라고 했지만, 이 사용자 아래에서 메인 세션이 같은 때 격리 틀(8768 · 8421~8425)을 띄울 수 있어 포트 차이만으로는 남의 서버를 죽일 수 있다(「구현이 위험해 보이면 찾아서 보고만」). 그래서 **정리는 환경 표식(QCHECK_TEST_TOKEN)을 지닌 우리 소유 php·python3·chrome 만**, 포트 차이는 **보고만**으로 했다. 표식이 없는 손주(환경을 새로 짜서 띄운 것)는 잡지 못한다 — 보고의 `ports_open` 으로만 드러난다.
② **RC-3 합계 모양 둘 더**: 지시서의 사례(「PASS=196 FAIL=0 + 꼬리 → 통과」)와 실제 원문(`RESULT pass=75 fail=0` — skip 없음)을 통과로 세려면 꼬리 줄 건너뛰기만으로는 모자라 `RESULT` 의 skip 생략과 `PASS=n FAIL=m [KEY=값…]` 꼴을 더했다.
③ **RC-4 와 RC-5 의 겹침**: `tbox.messenger_window_browser` 는 「계속 골라져야 하는 것」이면서 RC-5 표(known_fail)에 있다. 지시서 그대로 둘 다 했다 — 고르기는 되지만 `--skip-known-fail` 이면 돌지 않는다(6장).
④ **RC-5 `community.stage4c` 사유**: 표의 글 그대로 「위와 같음」 — 요약 줄에서 혼자 읽히지 않는다. 바꾸지 않았다(지시서 「표의 글 그대로」).
⑤ **run.sh**: 「사용법 줄만」이지만 `compare` 를 받으려면 첫 인자 허용 목록(`case`)과 사용법을 찍는 `sed` 줄 범위(7,13 → 7,16)를 함께 고쳐야 했다. 종료 코드 주석에 compare 한 줄.
⑥ **RC-6 원본 경로 두 줄**: `scan_pending_dryrun.sh` 는 존재 확인 줄(31)과 쓰는 줄(44)이 따로 원본을 가리켜 두 줄을 바꿨다. `g0_private_grants.sh` · `dbg_s2_security.sh` 는 자기 변수(`G0_ENV_SRC` · `DBGS2_ENV_SRC`)가 있어 그 뒤에 `QCHECK_ENV_SRC` 를 이었다. 지시서 후보 가운데 `qf3b` · `qf2_search` 는 이미 `QF2_ENV_SRC` 로 읽어 편집이 필요 없었다(그 둘의 rc 4 는 worktree 에 `.env` 가 없고 변수도 없어서).
⑦ **`--skip-known-fail` 을 `list` 에도**: 완료 기준 ③ 「run 의 실행 대상 수」를 실행 없이 보이려고.
⑧ **지시서 근거 파일 `qf9-hang-evidence.txt`** 는 결과 폴더에 없었다 — P-1 은 run #6 기록(15시간 51분)과 지시서 서술로만 확인했다.
