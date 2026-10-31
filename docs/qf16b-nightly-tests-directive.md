# 지시서 Q:F-16 1차 b — 밤 회귀(2026-10-30)가 잡은 시험 쪽 깨짐 넷 보정 (2026-10-31)

사용자(2026-10-31): 「고쳐 줘」 — 1차 운영 v2.78.0(`d19c136`) 뒤 밤 회귀가 잡은 실패 넷(CLAUDE.md 3.1 「회귀가 나오면 다음 날 핫픽스」).
근거: 밤 회귀 `~/.cache/qaredi-check/nightly/report-2026-10-30.md`(후보 운영 main `fcc78ee` run #52 · 기준 `8aa6980` run #53 · 원문 `~/.cache/qaredi-check/runs/52/raw/`).
메인이 가린 결과: 넷 모두 **앱 결함이 아니라 시험 · 시험 환경 쪽**. 나머지(작업글 셋 합계 줄 꼴 · `dbg_u2_ui` 23/9 · qf12 ui_snapshot · U2 툴팁 · `stage89_ui` known_fail)는 기준에서도 같음 — 이 지시서 밖.

| # | 시험 | 후보 실패 | 원인(메인 확인) | 이 지시서 |
|---|---|---|---|---|
| ① | `community.qf16_templates_http` | 89/1 · `FileNotFoundError: 'node'` | 밤 회귀는 cron(PATH `/usr/bin:/bin`)으로 돈다. node 는 nvm(`~/.nvm/versions/node/v24.22.1/bin`)에만 있다. `nightly.py` `run_select` 의 `Popen` 이 환경을 그대로 물려줌. 같은 꼴: `guide_journey_browser.py:274 · 306` | **A — 고침(저장소 밖)** |
| ② | `qf16_templates_http` · `qf16_templates_browser` | 「형식 위반: 합계 줄 없음」 — browser 는 실제 194/0 | 합계 줄이 `QF16_TEMPLATES_… pass=%d fail=%d` 꼴. `tests/lib/results.py` 는 `RESULT pass= fail=` · `PASS=n FAIL=m` · 한국어 다섯 변형만 읽는다. 선례 `brand_words_browser.py:683` | **B — 고침** |
| ③ | `qf12_work_browser` Q:T-41 | 「other pane: write buttons 0 (… 더보기 항목 …)」 · guest 는 통과 · 기준은 통과 | 더보기 `.menu__item` 을 전부 쓰기 단추로 센다(`:378-383`). Q:F-16 이 더한 작업 칸 「템플릿으로 저장」(`data-task-save-template`)은 보기 공유 사람에게도 보인다 — 공유받은 작업을 바꾸지 않고 **자기 개인 템플릿에 사본**(SECURITY.md 「2026-10-30 Q:F-16 1차」 ④ 허용된 설계 · `qf16_templates_browser.py` ⑧ 이 「= 창구 template_scopes」로 따로 단언) | **C — 고침** |
| ④ | `qf12_work_browser` Q:T-42 | project-list 360 · 412 · 412t 모든 밝기 「4 small: menu__item 20x44」 | 메인 진단 실행(2026-10-31 · `~/qaredi-qf16b-results-20261031/diag/t42.log` · 진단 꼬리표 임시 — 되돌림): 4개 모두 **닫힌 `details.menu.menu--picker` 안 「빈 작업」**. 닫힌 details 의 내용은 그려지지 않는데 `AUDIT_JS`(`:129-156`)는 크기 가둠 상태의 rect 를 잰다. 이미 `dialog:not([open])` 는 빼고 있다(`:142`) | **D — 고침** |

기준 문서: `/var/www/qaredi/CLAUDE.md` 2 · 3 · 4장(3.1 — 기대값 보정은 그 시험만) · 선례 `docs/qf15-s3b-regression-directive.md` · 1차 지시서 `docs/qf16-work-templates-directive.md` :218(「grep 으로 찾은 단언이 바뀐 수 때문에 깨지면 그 줄만 고치고 보고서에 전 → 후」).

| 항목 | 내용 |
|---|---|
| 구현 자리 | 격리 worktree **`/home/changkwon/qaredi-qf16b-20261031`**(가지 `fix/qf16-nightly-tests` · main `fcc78ee` 에서 — 메인이 만듦) · 결과 **`/home/changkwon/qaredi-qf16b-results-20261031/`** · 운영 · 운영 DB 읽기 · 쓰기 0 |
| 격리 틀 | `QCHECK_ENV_SRC=/home/changkwon/qaredi-regress-results-20261013/env-src` + `tests/run.sh run --fresh --env-src <같은 곳> --select id:<시험>` 또는 `python3 tests/community/memories_harness.py --port <8768|8778> --suite <파일> --output <결과>/<이름>` |
| 커밋 | 파일 지정(`git add -A` 금지) · 첫 줄 `모아 Q:F-16 1차 b: …` · 끝 `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>` · push · 발행 · 합치기 · 태그 0(메인이 한다) · `nightly.py` 는 저장소 밖이라 커밋 대상 아님 |

```
목표        : 밤 회귀(10-30)에서 Q:F-16 때문에 깨진 시험 넷을 「앱이 의도해 바뀐 것 · 시험 환경」에 맞게 고친다(앱 코드 0).
요구사항    : CLAUDE.md 3.1 · S3b 선례 — 그 단언 · 그 합계 줄 · 그 감사 한 줄 · 실행기 환경만.
수정 파일   : tests/community/qf16_templates_http.py(:264 합계 줄 — B) · tests/community/qf16_templates_browser.py(:489 합계 줄 — B) ·
              tests/community/qf12_work_browser.py(:378-383 Q:T-41 셈 — C · :142 AUDIT_JS 누름 자리 — D) · docs/qf16b-nightly-tests-report.md(새 · 보고) ·
              저장소 밖 ~/.cache/qaredi-check/nightly/nightly.py(A — 고치기 전 nightly.py.bak-20261031 사본).
금지 패턴   : src/ · public/ · db/ · deploy/ · config/ · catalog 변경 0 · 단언을 약하게(지우기 · 항상 참) 0 · 「템플릿으로 저장」 말고 다른 더보기 항목을 셈에서 빼기 0 ·
              닫힌 details 의 summary(누르는 자리)까지 빼기 0 · node 경로 하드코딩(버전 고정) 0 · git add -A.
완료 기준   : cron 과 같은 환경(env -i HOME=$HOME PATH=/usr/bin:/bin)에서 A 의 환경으로 tests/run.sh 를 거쳐 qf16_templates_http 통과로 셈(합계 수 = 원문 RESULT 줄) ·
              qf16_templates_browser 통과로 셈 · qf12_work_browser QF12D_ONLY=t41,t42 의 Q:T-41 · Q:T-42 실패 0(그 묶음의 다른 실패는 원문 그대로 보고) ·
              정적: python3 -m py_compile(바꾼 .py 셋 + nightly.py) · bash scripts/security_lint.sh 0 · git diff --check.
보고 형식   : docs/qf16b-nightly-tests-report.md — 바꾼 줄(전 → 후) / 돌린 명령과 출력 꼬리 원문 / 못 한 것 · 이상한 것. 짧게.
```

## A. `~/.cache/qaredi-check/nightly/nightly.py` — node 경로 (저장소 밖)

- `run_select` 의 `Popen` 에 `env=` — 지금 환경 그대로에 `node` 가 PATH 에 없을 때만 `~/.nvm/versions/node/v*/bin` 가운데 **숫자로 가장 높은 판**의 bin 을 PATH 앞에 더한다(문자열 정렬 0 — v9 > v24 사고). 없으면 그대로(로그 한 줄).
- 기준 판 다시 돌리기(같은 `run_select`)에도 같은 환경 — 따로 갈래 0.
- `tests/run.sh` · `memories_harness.py` · 시험이 PATH 를 지우지 않고 넘기는지 확인해 보고서에 한 줄(지우면 멈춰 보고).

## B. 합계 줄

`print('QF16_TEMPLATES_HTTP pass=%d fail=%d' % (…))` → `print('RESULT pass=%d fail=%d' % (…))`(browser 도 같은 꼴). 셈 · 종료 코드 그대로. `tests/lib/results.py` 로 두 원문(`~/.cache/qaredi-check/runs/52/raw/community.qf16_templates_*.log` 의 줄을 바꾼 사본)을 읽혀 합계가 잡히는지 확인.

## C. Q:T-41 `menuItems`

`[...pane.querySelectorAll('.menu__item')].filter(b => !b.hidden)` 에서 `[data-task-save-template]` 하나만 뺀다 — 주석 한 줄(왜: 자기 개인 템플릿 사본 · SECURITY ④ · qf16 ⑧ 이 따로 단언). 단언 문장 · 다른 셈 그대로. `metrics['t41_reader_' + key]` 에 뺀 항목이 보였는지(참 · 거짓) 하나 더해 보고에 남긴다.

## D. `AUDIT_JS` 누름 자리

`if (!vis(el) || el.closest('.doc') || el.closest('dialog:not([open])')) continue;` 에 「닫힌 `details` 의 **내용**(그 details 의 직속 summary 가 아닌 것)」을 더한다 — 닫힌 details 안 요소 `el` 에서 `const d = el.closest('details:not([open])')` 가 있고 `el` 이 `d` 의 직속 `summary`(또는 그 안)가 아니면 건너뜀. 중첩 details 도 맞게. 주석 한 줄(2026-10-31 · 닫힌 「작업 추가 ▾」 「빈 작업」 20x44 · dialog 와 같은 규칙).
같은 파일 U1 · U2 · U3 누름 자리 셈(`:1027 · :1468 · :2041`)이 같은 `AUDIT_JS` 를 쓰는지 확인 — 다른 감사 함수면 손대지 않고 보고에 한 줄.

## 바꾸지 않는 것

앱 코드 전부 · 다른 시험 · catalog · 밤 대기열(메인) · 전부터 깨진 시험(위 「이 지시서 밖」) · `guide_journey_browser.py`(A 로 함께 풀림 — 확인만).
