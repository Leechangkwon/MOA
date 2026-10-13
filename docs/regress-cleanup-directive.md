# 지시서 RC — 회귀 카탈로그 · 실행기 정리 (2026-10-13)

사용자 요청(2026-10-13 「회귀 카탈로그 정리 진행해」) — U2 회귀(run #6)가 15시간 넘게 걸리고, 기준 판 대조 한 번이 헛돌고, 통과한 시험 8개가 실패로 세어지고, 대조에서 실제 결함 하나를 놓친 일에서 나왔다.
기준 문서: `tests/lib/results.py` 머리 주석(세는 법 · 거부 규칙) · `tests/catalog.json` 의 `fields` · `../../CLAUDE.md` 3.1(검토·회귀 단위) · 2·4장.
근거 자료: `/home/changkwon/qaredi-regress-results-20261013/`(run #6 · #7 대조 `run6-run7-compare.json` · qf9 직접 실행 로그 둘 · 반영 뒤 `q_launcher-post.log`) · 원문 `~/.cache/qaredi-check/runs/{6,7}/raw/`.
단위: `../../CLAUDE.md` 3.1 「따로 쓰이는 것」 — 시험 틀만 바꾼다. 앱 코드 · 운영 0 · 발행 없음.

## 무엇이 문제였나 (2026-10-13 run #6 · #7 실측)

| # | 사실 | 증거 |
|---|---|---|
| P-1 | 시간 제한이 없다 — `execute()` 가 `proc.wait()` 로 무한정 기다린다. `core.qf9_pc_login` 의 「동시 넷」 사례에서 PHP 내장 서버가 연결 하나를 받고 읽지 않아 시험이 15시간 멈췄다 | `results.py` `execute()` · `qf9-hang-evidence.txt` |
| P-2 | 시험이 띄운 프로세스가 끝난 뒤에도 남는다 — `qf9_pc_login` 의 `php -S` 워커(PHP_CLI_SERVER_WORKERS=4 의 자식들)가 남아 다음 실행이 「포트 8421 사용 중 — 중단」(rc 3)으로 거부됐고, 임시 DB 를 잡아 `dropdb` 가 실패해 마이그레이션 단언 9건이 연쇄로 틀렸다 | run #7 · #9 로그 |
| P-3 | 통과한 시험이 실패로 세어진다 — 합계 줄 뒤에 `OWNED_TEST_PROCESSES_STOPPED=1; SYNTHETIC_DB_DROPPED=1` 꼬리 줄이 붙거나(`stage89_cross_regression` PASS=196 · `qf12_server_http` · `messenger_http` · `stage9d` · `qf13h_privacy_browser`), 합계 형식이 다르다(`lib_contrast` 「CONTRAST_FAIL=0」 · `design.allowlist` 「21 passed」 · `design.browser` JSON). 8개가 「형식 위반 → 실패」 | run #6 요약 |
| P-4 | `covers` 가 넓다 — 서버·DB 시험 다수가 `app.js` · `shell_top.php` · `editor.js` · `app.css` 를 덮는다고 적혀 있다. 실제로는 머리 주석에 이름만 나오거나 `node --check` · esprima 구문 · innerHTML grep · 절대 경로 grep · 「새 색값 0」 grep 한 줄(이미 `core.security_lint` · `community.asset_version` 등 정적 시험이 하는 것). 셸만 바꿔도 48개가 골라졌다 | 메인 조사 표(아래 RC-4) |
| P-5 | 옛 시험이 늘 같은 이유로 실패한다 — 기준 판(U2 전 운영 `8c36f8b`)에서도 실패 이름이 같다. `known_fail` 은 있지만 표시해도 **돈다**(시간이 줄지 않는다) | `run6-run7-compare.json` |
| P-6 | worktree 에 `.env` 가 없어 12개가 시작 거부(rc 3)·준비 실패(rc 4) — 시험마다 원본 변수 이름이 다르다(`QF2_ENV_SRC` · `DBGS_ENV_SRC` · `STAGE7C_ENV_SRC` · `STAGE7D3_ENV_SRC` · `D74_ENV_SRC` · `PK_ENV_SRC`)거나 `$ROOT/.env` 를 직접 읽는다 | run #6 요약 ③ |
| P-7 | 대조를 사람이 즉석 스크립트로 했다 — 「✖/FAIL 줄」 집합만 비교해서, 실패 줄 없이 트레이스백으로 끝난 `tbox.messenger_store_browser`(후보 rc 1 · 기준 rc 0)를 「같음」으로 놓쳤다. 그 결함(넓은 화면에서 메신저 창이 격자 판을 덮음)이 v2.63.0 으로 운영에 나갔다 | run #6 · #7 `messenger_store_browser` |

## 공통

| 항목 | 내용 |
|---|---|
| 구현 자리 | 격리 worktree **`/home/changkwon/qaredi-regress-20261013`**(브랜치 `feat/regress-cleanup` — 메인이 만들었다). 결과 폴더 **`/home/changkwon/qaredi-regress-results-20261013/RC/`**. 운영 `/var/www/qaredi/tbox-v2` · 운영 DB · 다른 worktree 는 읽기도 쓰기도 하지 않는다(근거 자료 폴더와 `~/.cache/qaredi-check/runs/{6,7}` 은 **읽기만**) |
| 계정 · 운영 | 운영을 치는 시험은 돌리지 않는다(`env: prod` · `writes_prod`). 시드 계정 0 |
| 커밋 | worktree 브랜치에 파일을 지정해(`git add -A` 금지) 한글 · 첫 줄 `Qaredi 시험 틀 RC: …` · 끝에 `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. push · main 합치기 없음 |

```
목표        : 회귀가 멈추지 않고(시간 제한 · 남은 프로세스 정리), 통과한 것을 통과로 세고, 상관없는 시험을 고르지 않고,
              원래 깨진 시험을 따로 빼 두고, 두 실행을 기계로 대조하게 한다 — 앱 코드 0.
요구사항    : 이 문서 RC-1 ~ RC-7 · tests/lib/results.py 머리 주석과 tests/catalog.json fields 를 같은 커밋에서 고친다(문서와 동작이 한 벌).
수정 파일   : tests/lib/results.py · tests/run.sh(사용법 줄만) · tests/catalog.json · tests/lib/selftest.sh(새 사례) ·
              tests/lib/contrast.py · design.allowlist 와 design.browser 의 cmd 가 부르는 스크립트(합계 줄 한 줄 더하기만 — RC-3) ·
              RC-6 의 `$ROOT/.env` 를 직접 읽는 시험 스크립트(원본 경로 한 줄 바꾸기만 — 목록은 조사해 보고서에) ·
              docs/regress-cleanup-report.md(보고서 — 새 파일).
              그 밖 0 — 특히 src/ · public/ · db/ · deploy/ · 시험의 단언(기대값) 0. 시험 기대값을 고치는 것은 이번 범위가 아니다(옛 시험 수리는 따로).
금지 패턴   : 시험 원문·비밀(.env 내용 · 비밀번호 · TOTP)을 요약·보고에 찍기(지금 가림 규칙 그대로) · writes_prod 거부를 약하게 하기 ·
              SIGKILL 을 첫 수단으로 쓰기(시험의 trap 정리가 먼저 — 지금 주석 「SIGKILL 은 쓰지 않는다」의 취지: 정리 기회를 준 뒤에만) ·
              자기 자신(실행기 · 잠금 · 부모 셸)을 죽이기 · git add -A.
```

### RC-1 시험마다 시간 제한

- 한도 = `max(timeout_min, est_sec × timeout_factor)` — 기본 `timeout_min = 600` 초 · `timeout_factor = 3`. 카탈로그 항목의 `timeout_sec` 가 있으면 그것을 쓴다(새 선택 칸 — `fields` 에 뜻 적기). `run` 에 `--timeout-factor F` · `--timeout-min S` · `--no-timeout`.
- 한도를 넘으면: 그 시험의 프로세스 묶음(RC-2)에 SIGTERM → 120초 기다림 → 남으면 SIGKILL(묶음 안만). 판정 `unverified` · 코드 **`timeout`**(③ — `CODE_ORDER` · 요약 · `report --md` 의 「다음 조치」에 「멈춘 시험 — 원문 끝 줄과 남은 프로세스를 보고 원인을 적는다」).
- 원문 끝에 실행기가 한 줄 덧붙인다: `run.sh: 시간 제한 N초 넘음 — SIGTERM(묶음 n개) …`.

### RC-2 시험이 남긴 프로세스 정리

- 시험을 새 세션으로 띄운다(`start_new_session=True` — 그 시험과 자식이 한 묶음). 시험이 끝나면(정상 · 실패 · 시간 제한 · 중단 모두) 같은 세션 id 를 가진 남은 프로세스를 `/proc/<pid>/stat` 의 sid 로 찾아 SIGTERM → 10초 → SIGKILL. 실행기 자신 · 부모는 대상이 아니다.
- 기록에 `leftover: {"count": n, "names": [...]}`(명령 이름만 — 인자 0, 비밀이 섞일 수 있다). n > 0 이면 요약 줄에 「남은 프로세스 n 정리」(판정은 바꾸지 않는다 — 시험의 정리 결함을 드러낼 뿐).
- 주의: `memories_harness.py` 처럼 시험이 스스로 `start_new_session=True` 로 띄운 손주는 다른 세션이라 이 방법으로 안 잡힌다 — 그 경우는 끝난 뒤 **그 시험이 쓰던 포트**(시험 시작 전 LISTEN 목록과 끝난 뒤 목록의 차이 중 127.0.0.1)를 잡은 프로세스로 찾는다. 이 갈래는 우리 사용자 소유 프로세스이고 `php`·`python3`·`chrome` 이름일 때만 정리한다. 구현이 위험해 보이면 「찾아서 보고만」으로 두고 보고서에 적는다.

### RC-3 통과를 통과로 세기

- `parse_counts`: 끝에서 합계 줄을 찾을 때 **꼬리 줄** — `^[A-Z][A-Z0-9_]*=[^ ]*(; [A-Z][A-Z0-9_]*=[^ ]*)*$` 꼴(예: `OWNED_TEST_PROCESSES_STOPPED=1; SYNTHETIC_DB_DROPPED=1`) · 빈 줄 — 은 건너뛴다. 트레이스백 줄은 건너뛰지 않는다(트레이스백이면 지금처럼 「합계 전에 멈춤」).
- `lib_contrast`(`tests/lib/contrast.py`) · `design.allowlist` · `design.browser` 는 끝에 `RESULT pass=N fail=M skip=0` 한 줄을 **더한다**(지금 출력은 그대로 두고). 단언 수를 세는 법은 그 스크립트의 지금 출력에서 그대로(예: contrast 는 검사한 쌍 수 / 실패 쌍 수).
- `selftest.sh` 에 사례: 꼬리 줄 있는 RESULT → 통과 · 꼬리 줄 뒤 트레이스백 → 실패 · PASS=196 FAIL=0 + 꼬리 → 통과.

### RC-4 `covers` 좁히기 (자료 정리 — 규칙을 `fields.covers` 에 적고 그 규칙대로)

- **빼는 것**: 그 시험이 그 파일을 (a) 머리 주석에서만 말하거나 (b) 아래 「정적 공통 검사」로만 보는 경우. 정적 공통 검사 = `node --check`/esprima 구문 · `php -l` · innerHTML/outerHTML/document.write grep · 절대 경로 리터럴 grep · `#rgb`/`rgb(` 새 색값 grep · import 버전 줄. 이것들은 `core.security_lint` · `community.asset_version` · 정적 시험이 덮는다.
- **두는 것**: 그 파일의 **특정 내용**을 단언(예: `help_button` 「app.js 위임 하나(initHelpDialog)」) · 그 파일이 그리는 화면을 브라우저로 잰다 · 그 파일이 부르는 창구를 HTTP 로 친다.
- 대상: 공통 셸·화면 자산 여섯 — `src/views/shell_top.php` · `public/assets/app.js` · `public/assets/app.css` · `public/assets/community/editor.js` · `public/assets/community/work.js` · `public/assets/messenger.js`. 다른 경로는 이번에 손대지 않는다.
- 보고서에 표: 시험 id · 뺀 경로 · 근거 줄(파일:줄 — 그 이름이 나오는 줄들) · 둔 경로와 근거. 메인이 이 표로 검토한다.
- 확인: `tests/run.sh list --select diff:8c36f8b..0ed47f0`(U2 diff)로 고른 수가 몇 개로 줄었는지 보고(전 48). U2 를 실제로 재던 시험(`qf12_work_browser` · `qf12_shell_browser` · `messenger_*_browser` · `design.browser` · `lib_contrast` · `security_lint` · `asset_version`)은 계속 골라져야 한다 — 빠지면 실패.

### RC-5 원래 깨진 시험 — `known_fail` + 건너뛰기

- 아래 표의 항목을 `status: known_fail` 로 바꾸고 `known_fail: {reason, since: "2026-10-13", expires: "2026-11-12", evidence}` — reason 은 표의 글 그대로(사람 말 한 문장) · evidence 는 근거 자료 파일 이름. **사용자 승인**: 2026-10-13 「회귀 카탈로그 정리 진행해」(직전 Fable 제안 「이미 깨진 옛 시험을 known_fail 로 표시해 회귀에서 빼기」에 대한 답).
- `run` 에 **`--skip-known-fail`**: 만료 전 known_fail 항목을 돌리지 않고 요약에 「③ known_fail 건너뜀 n — id · 사유 · 만료」로 **늘 보인다**. 만료가 지났으면 건너뛰지 않는다(지금 규칙처럼 required 로 돈다). 기본값은 지금처럼 돈다(이 인자가 없으면 동작 변화 0).
- `run.sh` 사용법 줄과 `results.py` 머리 주석에 「회귀 권장: `run --select diff:<기준>..<후보> --skip-known-fail`」 한 줄.

| id | 사유 (reason) |
|---|---|
| launcher.q_launcher | 런처 새 디자인(Qaredi 제목 · 바로가기 격자 · 타일 경로) 뒤 옛 기대값 — 기준 판에서도 같은 실패, v2.63.0 반영 뒤 11건은 기준 판 14건의 부분집합 |
| tbox.changelog | 변경 이력 태그 v1.0.1 · 1440 합계 · 등급 표기 옛 기대값 — 기준 판과 같은 실패 |
| tbox.changelog_admin_only | 임시 DB 에 새 마이그레이션 파일(2026-10-12~25)이 적용되지 않음 — 기준 판과 같은 실패 |
| tbox.help_button | 「사용 방법」 단추 수 16 → 18(화면이 늘었다) · 1440 합계 옛 기대값 — 기준 판과 같은 실패 |
| community.stage3 | 감사 건수 · 손님 홈 · 절대 경로 · doc-schema 사본 옛 기대값 — 기준 판과 같은 실패 |
| community.stage4b | editor.js esprima 구문(최신 문법) · doc-schema 사본 — 기준 판과 같은 실패 |
| community.stage4c | 위와 같음 |
| tbox.qf3a | grants.sql 절 잘라 내기 기대값(DO 블록 수) — 기준 판과 같은 실패 |
| tbox.qf13_consent_http | 「main 에 동의 표 없음」 가정 — 기준 판과 같은 실패 |
| tbox.qf13_retention_http | 하네스가 끝에서 실패(rc 1) — 기준 판과 같음 |
| core.qf8_token_write | 토큰 범위 표(Q:F-10 원자 여섯) 뒤 옛 기대값 — 기준 판과 같은 실패 |
| core.qf9_pc_login | Q:F-9 고정 기준 커밋 대비 「차이 0」 · token_scopes · 응답 모양 · 마이그레이션 적용 — 후보·기준 판 같은 포트 직접 실행 둘 다 134/12 같은 집합 |
| core.qf10_multi_scope | 고정 기준 커밋(7c87450) 대비 「차이 0」 · Guest 갈래 — 기준 판과 같은 실패 |
| community.qf4_draft_key | Auth 표식 자리 수 1 → 2 — 기준 판과 같은 실패 |
| community.stage8b | 시험 의존성 `markdown_it` 없음 — 기준 판과 같음 |
| community.stage89_ui | 하네스가 끝에서 실패(rc 1) — 기준 판과 같음 |
| community.stage10_suggestions | 브라우저 묶음 30초 시간 초과 1건 — 기준 판과 같음 |
| design.live | 운영 테마 다섯 확인이 합계 전에 멈춤(rc 1) — 기준 판과 같음 |
| tbox.messenger_window_browser | Q:T-19 「GPT/OpenAI 글자 0」 17 · snap AUDIT_JS 16 · Q:T-11 런처 타일에서 멈춤 — RV-U2-1 · RV-U2-2 에서 기준 판과 이름까지 같음 |

`community.qf12_shell_browser`(기준 판부터 13건)는 **넣지 않는다** — 셸을 재는 주 시험이라 회귀에서 빼면 안 된다. 13건의 기대값 수리는 따로.

### RC-6 `.env` 원본 한 곳

- `run` 에 **`--env-src <파일>`**: 파일이 있고 · 우리 소유 · 모드 0600 · 저장소 밖일 때만 받는다(아니면 사용법 오류 rc 2). 시험 자식 환경에 `QCHECK_ENV_SRC` 와 지금 이름 여섯(`QF2_ENV_SRC` · `DBGS_ENV_SRC` · `STAGE7C_ENV_SRC` · `STAGE7D3_ENV_SRC` · `D74_ENV_SRC` · `PK_ENV_SRC`)을 같은 경로로 넣는다. 경로만 — 내용은 실행기가 읽지 않는다.
- `$ROOT/.env` 를 직접 읽는 시험(조사: `stage7d2` · `stage7d4` · `g0_private_grants` · `qf3b` · `stale_modules` · `qf2_search` 가 후보 — 「임시 .env 에 임시 DB 이름이 들어가지 않았다」 · 「.env 가 없다」)은 원본 경로를 `"${QCHECK_ENV_SRC:-$ROOT/.env}"` 꼴 한 줄로만 바꾼다. 그 밖 단언 0.
- 확인: 결과 폴더 안 0600 사본(메인이 `env-src` 로 만들어 둔다 — 아래)으로 `--env-src` 를 주고 위 시험 중 둘(`qf3b` · `stale_modules`)을 돌려 「시작 거부/준비 실패」가 아니게 되는지(통과 여부는 묻지 않는다 — 시작하는지만) 보고.

### RC-7 두 실행을 기계로 대조 — `tests/run.sh compare <run_a> <run_b>`

- 두 실행 모두에 있는 시험마다: 판정(verdict) · 코드 · rc · 합계(pass/fail/skip) · 실패 단언 이름 집합(지금 `first_mark` 를 쓰는 정규식으로 원문 전체에서 — 기대값 꼬리 ` (기대=…)` 는 떼고 비교).
- 결과 줄: `SAME`(넷이 모두 같음) · **`DIFF`**(하나라도 다름 — 무엇이 다른지: `rc 0→1` · `verdict pass→fail` · `+단언 3 / −단언 1`) · `ONLY_A` · `ONLY_B`. 맨 위 한 줄 「DIFF n · SAME m」. `--md` 로 보고서 조각.
- **받아들이기 확인(꼭)**: `compare 7 6` 이 `tbox.messenger_store_browser` 를 **DIFF(rc 0→1)** 로 낸다 — 2026-10-13 사람이 놓친 것. `core.qf9_pc_login` 도 DIFF(run #7 은 포트 거부로 실행 0).
- 요약·원문 가림 규칙 그대로(단언 이름은 원문의 ✖ 줄 글자 — 비밀이 들어갈 자리 없음, 그래도 `redact` 를 거친다).

```
완료 기준   : ① bash tests/lib/selftest.sh 전부 통과(새 사례 포함: 시간 제한 — 가짜 카탈로그 `--catalog` 로 `sleep 999` 항목 · est_sec 1 · `--timeout-min 5`
              → 약 5초 뒤 ③ timeout · 남은 프로세스 — 자식으로 `sleep 600 &` 를 남기고 끝나는 항목 → leftover 1 정리 · 그 sleep 이 실제로 없어짐 ·
              꼬리 줄 세 사례(RC-3) · --skip-known-fail(만료 전 건너뜀 · 만료 뒤 돎) · --env-src 거부(0644 · 저장소 안 · 없는 파일 → rc 2))
              ② tests/run.sh compare 7 6 — messenger_store_browser DIFF(rc 0→1) · qf9_pc_login DIFF · 출력 원문을 보고서에
              ③ tests/run.sh list --select diff:8c36f8b..0ed47f0 — 고른 수(전 48)와 RC-4 의 「계속 골라져야 하는 것」 전부 포함 · --skip-known-fail 로 run 의 실행 대상 수
              ④ RC-3: `tests/run.sh run --select id:community.lib_contrast --select id:design.allowlist --select id:core.security_lint` 통과로 셈(정적 — 운영 0)
              ⑤ RC-6: env-src 로 qf3b · stale_modules 가 시작한다(rc 3·4 아님)
              ⑥ python3 -m py_compile tests/lib/results.py · bash -n tests/run.sh · json 문법(catalog) · bash scripts/security_lint.sh 0 · git diff --check 0
보고 형식   : docs/regress-cleanup-report.md — 변경 파일 / RC 항목마다 한 일 / RC-4 표 / 실행한 명령과 출력 원문(결과 폴더) / 못 한 것과 이유 /
              DESIGN·이 문서와 다르게 한 것. 마지막 답에 커밋 해시 · 결과 폴더.
```

`.env` 사본(RC-6 확인용): 메인이 `/home/changkwon/qaredi-regress-results-20261013/env-src`(0600)로 미리 둔다. 내용을 열어 보거나 찍지 않는다.
