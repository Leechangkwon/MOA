# 지시서 Q:F-15 S3b — 밤 회귀(2026-10-26)가 잡은 2 · 3단계 뒤 시험 깨짐 보정 · 원인 조사 (2026-10-28)

사용자(2026-10-28): 「계속 진행하자」 — 3단계 운영 v2.76.0(`50ece0d`) 뒤 다음 할 일은 CLAUDE.md 3.1 「회귀가 나오면 다음 날 핫픽스」.
근거: 밤 회귀 `~/.cache/qaredi-check/nightly/report-2026-10-26.md`(S1c · S2 · S3 세 묶음을 합친 25개 · 후보 운영 main `5d0f574` · 기준 `ede6c1f`(S2 전) · run #48 후보 / #49 기준 · 원문 `~/.cache/qaredi-check/runs/48/raw/`).
메인이 가린 결과: 후보에서 실패 11 가운데 **기준에서는 통과했거나 3단계와 닿는 것 셋**, 나머지는 전부터 깨진 것(SAME · 기준도 실패 — 이 지시서 밖).

| 시험 | 후보 실패 | 원인(메인 확인) | 이 지시서 |
|---|---|---|---|
| `design.browser`(`tests/design_themes_browser.py`) | 0/0 Traceback — `expect(html).to_have_attribute('data-design', 'moa')` 가 null | 시험 전용 라우터 `tests/design_fixture.php` 가 `Authz` 를 메서드 셋만 흉내 낸다. 2단계 런처가 `Authz::uploadableLevels()` 를, 3단계 런처가 `GuideJourney::ready()`(→ `Db`)를 부르면서 `PHP Fatal error: Call to undefined method Authz::uploadableLevels()`(메인이 같은 틀을 띄워 재현 · 2026-10-28) — 앱 결함 아님, 흉내 빠짐 | **A — 고침** |
| `community.home_front_browser`(2단계 수락) | 「③ harness: the new migration is last in the twice-applied list」 | 2단계 시험이 「2단계 마이그레이션이 목록 **맨 끝**」을 단언하는데, 3단계가 그 뒤에 새 마이그레이션을 더했다 — 3단계가 놓친 기대값 | **B — 고침** |
| `tbox.dbg_u3_ui` | 「1 게시판 글(속성 여섯)도 본문 첫 줄이 첫 화면 위쪽 절반 안 360-light · 360-dark」 — `{'top': 402, 'bottom': 420, 'h': 780}`(절반 390) · 기준에서는 통과 · 412 는 통과 | 모름. 기준 → 후보 사이 게시판 글 화면에 닿는 파일은 `src/views/shell_top.php` · `shell_tools.php`(2단계 — 사이드바 첫 방문 펼침 · 앱 판 구분줄) · `public/assets/app.css`(2단계 구분줄 · 3단계 `--z-guide`) · `moa.css`(2단계) · `usage-guide.css` · `usage_guide.php`(3단계) — `page_view.php` · `public/assets/community/` 변경 0 | **C — 조사만** |
| 전부터 깨진 것(손대지 않음) | `upgrade.smoke` · `qf12_shell_browser` · `qf12_work_browser` · `messenger_window_browser`(Q:T-19 에서 멈춤 — 3단계가 바꾼 :663-674 기대값은 아직 한 번도 닿지 않음) · `memory_ai_http` · `stage7d3`(옛 기준 판 대조 — 기준도 실패) · `invite_request_browser`(합치기 전 비교 단언) · `dbg_u_ui`(기준이 더 나쁨) · `core.ux_r1_ui`(미검증) | — | 0 |

기준 문서: `/var/www/qaredi/CLAUDE.md` 2 · 3 · 4장(3.1 — 기대값 보정은 그 시험만) · S1c 지시서 `docs/qf15-s1c-home-h1-snapshot-directive.md`(같은 꼴의 보정 선례) · S3 지시서 `docs/qf15-s3-guide-directive.md`.

| 항목 | 내용 |
|---|---|
| 구현 자리 | 격리 worktree **`/home/changkwon/qaredi-qf15s3b-20261028`**(가지 `fix/qf15-s3b-tests` · main `184273c` 에서 — 메인이 만듦) · 결과 **`/home/changkwon/qaredi-qf15s3b-results-20261028/`** · 운영 · 운영 DB 읽기 · 쓰기 0 |
| 격리 틀 | A: `python3 tests/design_themes_browser.py`(자체 php -S) · B: `QCHECK_ENV_SRC=/home/changkwon/qaredi-regress-results-20261013/env-src python3 tests/community/memories_harness.py --port 8768 --suite home_front_browser.py --output <결과>/home-front`(8768 · 8769 가 쓰이면 8778) · C: 아래 |
| 커밋 | 파일 지정(`git add -A` 금지) · 첫 줄 `모아 Q:F-15 S3b: …` · 끝 `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>` · push · 발행 · 합치기 · 태그 0(메인이 한다) |

```
목표        : 2 · 3단계 뒤 밤 회귀에서 깨진 시험 둘을 「앱이 의도해 바뀐 것」에 맞게 고치고(앱 코드 0), 셋째(dbg_u3 360 게시판 글 첫 줄)의 원인을 가린다.
요구사항    : CLAUDE.md 3.1 · S1c 선례 — 그 단언 · 그 흉내만. 앱 코드 · 다른 시험 · catalog 0.
수정 파일   : tests/design_fixture.php(흉내 더하기 — A) · tests/community/home_front_browser.py(:300 한 줄 — B) · docs/qf15-s3b-report.md(새 · 보고).
              C 에서 원인이 「시험 쪽」(데이터 · 기준값)이고 고칠 자리가 tests/browser/dbg_u3_ui.py 한 단언이면 그 줄만 — 앱 쪽이면 고치지 않고 멈춰 보고.
금지 패턴   : src/ · public/ · db/ · deploy/ · config/ 변경 0 · 단언을 약하게(지우기 · 항상 참) 0 · 흉내가 진짜 판정을 대신해 시험 뜻을 바꾸기 0 · git add -A.
완료 기준   : A design.browser 통과(지금 기준 판 1355 단언 — 수가 바뀌면 이유) · B home_front_browser 통과 · C 원인 한 줄 + 증거(두 판의 측정값 · 화면 사진) ·
              정적: php -l tests/design_fixture.php · python3 -m py_compile(바꾼 .py) · bash scripts/security_lint.sh 0 · git diff --check.
보고 형식   : docs/qf15-s3b-report.md — 바꾼 줄(전 → 후) / 돌린 명령과 출력 꼬리 원문 / C 조사 결과(원인 · 증거 · 고칠 곳 제안) / 못 한 것. 짧게.
```

## A. `tests/design_fixture.php` — 흉내 둘 더하기

- `final class Authz` 에 `uploadableLevels(array $user): array` — 손님 문맥(`($_GET['guest'] ?? '') === '1'`)이면 `[]`, 아니면 `['internal', 'team', 'private']`(지금 `canCommunityWrite` 흉내와 같은 갈래 기준). 그 밖 메서드는 런처가 실제로 부르는 것만(`GuideJourney::allowed()` 가 부르는 `canCommunityWrite` · `uploadableLevels` · `canAdmin` · `canManageUsers` — 이미 셋은 있음).
- `Db` 흉내 — 이 라우터는 「DB 없는 화면 그리기」다. `GuideJourney::ready()` 가 `Db::instance()->run(…)->fetchColumn()` 을 부르므로, `fetchColumn()` 이 거짓을 돌려주는 최소 흉내 하나(→ 「표 없음」 갈래 · `resume()` 안 부름 · 이어 하기 줄 0). `src/community/GuideJourney.php` 는 `Db.php` 를 require 하지 않는다(메인 확인) — 이름 충돌 0. 다른 화면(login · files · settings · community)이 `Db` 를 부르게 되면 그 갈래가 흉내 때문에 조용히 통과하지 않는지 보고서에 한 줄.
- 머리 주석에 「2단계 · 3단계 런처가 부르는 판정 · 표 확인 — 화면 그리기만」 한 줄.

## B. `tests/community/home_front_browser.py` :300

`check(names[-1:] == ['2026-10-26_qf15_s2_shortcuts.sql'], '③ harness: the new migration is last in the twice-applied list')`
→ 2단계 마이그레이션이 두 번 도는 목록 **안에 있고** 그보다 앞 판 마이그레이션(`2026-10-26_scale_d2a_indexes.sql`)보다 뒤에 있다는 뜻으로(뒤에 다른 묶음 마이그레이션이 더해져도 깨지지 않게): 문장 「③ harness: the S2 migration is in the twice-applied list (after scale_d2a)」. 그 밖 단언 0.

## C. `tbox.dbg_u3_ui` 360 게시판 글 첫 줄 — 조사만

1. 가장 싼 방법으로: 같은 격리 틀에서 **기준 `ede6c1f` · 2단계 `10443d9` · 지금 main `184273c`** 세 판(임시 detached worktree — 끝나면 지움)의 게시판 글(속성 여섯 — 상태 · 쓴 사람 · 공개 범위 · 게시판 · 태그 · 조회) 화면을 360 × 780(밝게)으로 그려 `dbg_u3_ui.py` 의 `FIRST_LINE` 과 같은 측정(본문 첫 줄 top · bottom)과 그 위 요소들(머리 · 경로 줄 · 제목 · 속성 줄)의 높이를 표로. `bash tests/dbg_u3_ui.sh` 전체(약 900초 · sudo)는 꼭 필요할 때만 한 번.
2. 18px(한 줄) 차이가 어느 요소에서 생겼는지 · 어느 판(2단계 / 3단계)에서 생겼는지 · 의도한 변경의 결과인지(예: 사이드바 첫 방문 펼침의 좁은 폭 머리) 아니면 결함인지.
3. 결론 셋 중 하나로 보고: ① 앱 결함 → **고치지 않고** 원인 파일 · 줄 · 고칠 방법 제안 ② 의도한 변경의 결과(시험 기대값이 옛 화면 기준) → 단언 한 줄 보정안(고쳐도 됨 — 그 한 줄만 · 이유를 보고서에) ③ 데이터 · 우연(제목 길이 등) → 증거와 함께 보고.

## 바꾸지 않는 것

앱 코드 전부 · 3단계 수락 시험 · 다른 옛 시험 · catalog · 밤 대기열(메인) · 전부터 깨진 시험(위 표 마지막 줄).
