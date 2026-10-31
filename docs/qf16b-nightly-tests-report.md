# 보고 Q:F-16 1차 b — 밤 회귀(2026-10-30) 시험 쪽 깨짐 넷 보정 (2026-10-31)

지시서 `docs/qf16b-nightly-tests-directive.md`(c53c3a3). 구현 = 포크(Opus 5.5). 작업 트리 `/home/changkwon/qaredi-qf16b-20261031`(가지 `fix/qf16-nightly-tests`) · 결과 `/home/changkwon/qaredi-qf16b-results-20261031/`. 앱 코드 · catalog 변경 0 · 운영 · 운영 DB 0.

## 바꾼 줄 (전 → 후)

| # | 파일 | 전 | 후 |
|---|---|---|---|
| A | `~/.cache/qaredi-check/nightly/nightly.py`(저장소 밖 · 사본 `nightly.py.bak-20261031`) | `run_select` 의 `subprocess.Popen(cmd, cwd=root, …, start_new_session=True)` — cron 환경 그대로 | 새 `node_env()`: `node` 가 PATH 에 있으면 환경 그대로, 없으면 `~/.nvm/versions/node/v*/bin` 가운데 **판 번호를 숫자 튜플로** 비교해 가장 높은 bin(실행 가능한 `node` 있는 것만)을 PATH 앞에 · 로그 한 줄. `Popen(…, env=node_env())` — 후보 · 기준 두 실행 모두 같은 `run_select` 라 갈래 0 |
| B | `tests/community/qf16_templates_http.py:264` | `print('QF16_TEMPLATES_HTTP pass=%d fail=%d' % …)` | `print('RESULT pass=%d fail=%d' % …)` |
| B | `tests/community/qf16_templates_browser.py:489` | `print('QF16_TEMPLATES_BROWSER pass=%d fail=%d' % …)` | `print('RESULT pass=%d fail=%d' % …)` |
| C | `tests/community/qf12_work_browser.py` Q:T-41 `writes` | `menuItems: [...pane.querySelectorAll('.menu__item')].filter(b => !b.hidden).length` | `menuItems: [...pane.querySelectorAll('.menu__item:not([data-task-save-template])')]…` + 주석 한 줄(자기 개인 템플릿 사본 · SECURITY ④ · qf16 ⑧) + `saveTemplate: … .some(b => !b.hidden)`(metrics 에 남김). 단언 문장 · 다른 셈 그대로 |
| D | `tests/community/qf12_work_browser.py` `AUDIT_JS` 누름 자리 | `if (!vis(el) \|\| el.closest('.doc') \|\| el.closest('dialog:not([open])')) continue;` 만 | 그 앞에: 닫힌 `details` 조상을 바깥으로 차례로 보며, 어느 하나라도 `el` 이 그 details 의 **직속 summary** 안이 아니면 건너뜀(중첩 — 바깥이 닫혔으면 안쪽 summary 도 건너뜀) · 주석 두 줄 |

- PATH 전달 확인: `tests/run.sh` · `tests/lib/*.sh` 에 `env -i` 0 · `memories_harness.py` 는 `os.environ` 을 그대로 쓰거나(`dict(os.environ, …)`) `PG*` · `PHP_CLI_SERVER_WORKERS` 만 뺀다 — PATH 는 시험까지 간다(아래 ① 실측).
- U1 · U2 · U3 누름 자리(`:1028 · :1470 · :2042`)는 **같은 `AUDIT_JS`** 를 부른다 — D 가 그대로 적용된다(닫힌 details 내용만 빠짐 · 이번 실행 범위 밖이라 그 묶음은 돌리지 않음).
- `guide_journey_browser.py:274 · 306` 의 맨 `node` 도 A 로 함께 풀린다(확인만 · 손대지 않음).

## 돌린 명령과 출력 꼬리 (원문)

① cron 같은 환경 + A 의 환경으로 `tests/run.sh` — `env -i HOME=$HOME PATH=/usr/bin:/bin /usr/bin/python3 <결과>/run/cron_like_run.py`. 스크립트는 `nightly.py` 를 import 해 `node_env()` 를 그대로 부르고(`nightly.log` 만 print 로 바꿔 오늘 밤 로그 파일 0) 그 env 로 `bash tests/run.sh run --fresh --env-src /home/changkwon/qaredi-regress-results-20261013/env-src --select id:community.qf16_templates_http --select id:community.qf16_templates_browser`.
```
NODE_ENV_LOG node not on PATH — added /home/changkwon/.nvm/versions/node/v24.22.1/bin
PATH_BEFORE /usr/bin:/bin
PATH_AFTER /home/changkwon/.nvm/versions/node/v24.22.1/bin:/usr/bin:/bin
WHICH_NODE /home/changkwon/.nvm/versions/node/v24.22.1/bin/node v24.22.1
… 1/2 community.qf16_templates_http (시작 16:43:44)
  ← community.qf16_templates_http pass · rc 0 · 96/0 · 32초
… 2/2 community.qf16_templates_browser (시작 16:44:17)
  ← community.qf16_templates_browser pass · rc 0 · 194/0 · 138초
점검(로컬) #54 · 504d223+dirty · 2분 51초 · 2/195
시험 통과 2 · 실패 0 · ③ 0 │ 항목 통과 290 · 실패 0 · SKIP 0
RUN_SH_RC 0
```
원문 `~/.cache/qaredi-check/runs/54/raw/` — `RESULT pass=96 fail=0` · `RESULT pass=194 fail=0` · `PASS Q:T-50 ⑪ node --check 바꾼 JS 넷`. 합계 수 = 원문 RESULT 줄. 로그 `<결과>/run/qf16-runsh.log`.

② `QCHECK_ENV_SRC=… QF12D_ONLY=t41,t42 python3 tests/community/memories_harness.py --port 8818 --suite qf12_work_browser.py --output <결과>/qf12`
```
RESULT pass=666 fail=0
PASS Q:T-41 other pane: write buttons 0 (완료 · 바꾸기 · 마감/상태 칸 · 더보기 항목 · 하위 완료)
PASS Q:T-41 guest pane: write buttons 0 (완료 · 바꾸기 · 마감/상태 칸 · 더보기 항목 · 하위 완료)
PASS Q:T-42 project-list-360-light tap targets ≥ 44 (0 small: [])
PASS Q:T-42 project-list-412t-hc-dark tap targets ≥ 44 (0 small: [])
```
Q:T-42 누름 자리 단언 72개 모두 통과 · Q:T-41/42 실패 0. metrics(`<결과>/qf12/qf12-work-browser-metrics.json`): `t41_reader_other` = `{done 0, assign 0, inputs 0, menuItems 0, saveTemplate true, checks 0}` · `t41_reader_guest` = `{… menuItems 0, saveTemplate false …}` — 보기 공유 other 에게만 「템플릿으로 저장」이 보이고(허용된 설계) 그 밖 더보기 항목 0. 로그 `<결과>/run/qf12-t41-t42.log`.

③ 정적: `python3 -m py_compile` 바꾼 .py 셋 + `nightly.py` 통과 · `bash scripts/security_lint.sh` → 「결과: 통과 (금지 패턴 0건)」(rc 0) · `git diff --check` 0.

B 읽힘 확인(`tests/lib/results.py`): #52 원문 browser 로그의 합계 줄만 바꾼 사본 → `parse_counts` = `{pass 194, fail 0, format result}` · `judge` = pass(원문 그대로는 「형식 위반: 합계 줄 없음」).

## 못 한 것 · 이상한 것

- #52 원문 http 로그의 합계 줄만 바꾼 사본은 여전히 「합계 줄 없음(rc 1) — 합계 전에 멈춤」으로 읽힌다: 그 실행은 실제 실패(node)라 하네스의 `RuntimeError` Traceback 이 합계 뒤에 붙고, `results.py`(RC-3)는 합계 뒤 Traceback 이면 합계를 쓰지 않는다. `memories_harness.py` 로 도는 시험이 **실패할 때** 모두 같은 꼴(qf12_work_browser 도 「합계 줄 없음(rc 1)」) — 전부터의 규칙 · 지시서 밖. 통과 판은 ① 실측으로 셈이 잡힌다.
- `qf16_templates_http` 의 정적 단언(새 줄 innerHTML · 새 클래스 일곱 등)은 `git merge-base HEAD main` 과의 diff 를 본다. 밤 회귀처럼 **main 자체**에서 돌면 비교할 새 줄이 없어 그 단언들은 늘 통과(빈 비교) — 깨짐은 아니고 뜻이 「가지에서만 의미 있음」. 손대지 않음.
- ① 실행 끝 「열린 포트 8818 · 8819」 보고는 같은 시각 ②(포트 8818)를 따로 돌렸기 때문 — 끝난 뒤 닫힘.
- 같은 사본 이름 비교: `nightly.py.bak-20261031` 과 지금 판의 차이는 `node_env()` 19줄 + `Popen` 한 줄뿐.

## 독립 검토 (2026-10-31 · Fable · 새 문맥)

판정 **「반영 가능」** — 범위 밖 변경 0 · 단언 약화 0 · 보고 숫자 = 원문 로그(run #54 · qf12 666/0 · 진단 20x44 ×4). 낮음 둘은 메인이 고침: ① `nightly.py` PATH 가 아예 없을 때 빈 항목(= 현재 폴더)이 남던 것 → 있을 때만 이어 붙임(실측: `/usr/bin:/bin` → nvm bin 앞 · PATH 없음 → nvm bin 하나) ② 이 보고서의 U1 · U2 · U3 줄 번호(:1028 · :1470 · :2042).
