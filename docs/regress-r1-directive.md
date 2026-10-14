# 지시서 R1 — 밤 회귀(2026-10-14) 보정 (2026-10-15)

밤 회귀 보고 `~/.cache/qaredi-check/nightly/report-2026-10-14.md`(후보 run #22 · 기준 run #23 · 원문 `~/.cache/qaredi-check/runs/22/raw/`) — 후보 34 중 실패 13 · 기준 판 대조 **DIFF(기준 통과 → 후보 실패) 5**: `community.lib_contrast`(app.css `data-contrast` 선택자 3 → 5) · `design.allowlist`(design_themes.php:7 「invalid preference escaped allowlist」) ·
`community.qf12_floor_http`(fresh install schema.sql+grants ↔ 이주 결과 다름) · `tbox.sec4`(POST /api/users 201 → 403) · `design.browser`(design_themes_browser.py:71 테마 창 「nova」 누름 실패) · **멈춤 1**: `community.qf12_server_http`(「board 360」 통과 뒤 30분 출력 0 — 끊김) · **돌지 못함 13**(후보 실행이 끊겨서).
규칙: `/var/www/qaredi/CLAUDE.md` 3.1 「밤 회귀」 — 회귀가 나오면 다음 날 핫픽스 · 2 · 4장.

| 항목 | 내용 |
|---|---|
| 구현 자리 | 격리 worktree **`/home/changkwon/qaredi-r1-20261015`**(브랜치 `fix/regress-r1` — 메인이 만든다) · 결과 **`/home/changkwon/qaredi-r1-results-20261015/`** · 운영 0 |
| 실행 | `bash tests/run.sh run --select id:<id> --skip-known-fail --env-src /home/changkwon/qaredi-regress-results-20261013/env-src --fresh`(env-src 파일은 열거나 찍지 않는다) · 기준 판 비교는 `tests/run.sh compare` · 디스크 남은 공간 확인(≥ 3GB) |
| 커밋 | 파일 지정 · 첫 줄 `Qaredi R1: …` · 끝 `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>` · push · 발행 · 합치기 0 |

```
목표        : 회귀 후보 다섯과 멈춤 하나의 원인을 「제품 문제」/「시험의 낡은 기대값」으로 가르고 고친 뒤, 그 여섯과 돌지 못한 열셋을 다시 돌려 통과(또는 기준 판과 같은 실패)를 보인다.
요구사항    : 위 보고 · 각 시험 원문 · 관련 DESIGN 절(2.16 고대비 · 2.33 글자 로고) · 처리방침 판 동의 관문(Legal::needsConsent — 시험 계정이 새 판 2026-10-14 에 동의하지 않아 403 인지 확인).
수정 파일   : 제품 문제일 때 — 그 원인 파일만(예: public/assets/app.css 의 고대비 블록 셋 안으로 글자 로고 값을 옮김 · db/schema.sql 이나 db/grants.sql 을 이주 결과와 같게 — 이주 파일은 바꾸지 않는다(운영 적용됨)) ·
              시험 문제일 때 — 그 시험 파일의 그 단언 · 준비(합성 계정의 동의 판 등)만 · 멈춤은 원인(시험 틀 · 서버 · 브라우저 대기)을 찾아 고침 · docs/regress-r1-report.md(새)
              보안 핵심 파일(Authz · Auth · Http · Db · Upload · Doc · public/index.php) 0 — 필요하면 멈추고 보고.
금지 패턴   : 실패를 known_fail 로 덮기(이번 회귀가 만든 실패는 고친다 — 기준 판에서도 같은 실패인 것만 그대로) · 단언을 지우기(기대값을 새 동작에 맞게 바꾸되 이유를 보고서에) · git add -A.
완료 기준   : 여섯 각각 — 원인 · 분류 · 고친 내용 · 다시 돈 결과(rc · 합계) · 기준 판과 대조 · 돌지 못한 열셋 — 다시 돈 결과(실패면 기준 판에서 같은지 compare) · 남은 실패 목록과 이유 · 보고서 docs/regress-r1-report.md
```
