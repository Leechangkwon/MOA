#!/usr/bin/env bash
# tests/run.sh — 로컬 요약기 (점검 봇 1-A, docs/checkbot/stage1-directive.md A-6)
#
# AI 세션이 지금 권한 그대로 쓰는 것이다. 시험을 고치거나 대상(BASE·DB·계정)을 바꾸지 않는다.
# 카탈로그(tests/catalog.json)에서 고르고 · 돌리고 · 세고 · 줄여서 보인다. 몸체는 tests/lib/results.py.
#
#   tests/run.sh list    [--select …] [--include-manual] [--allow-writes-prod] [--skip-known-fail] [--catalog <경로>]   고른 목록만(실행 0)
#   tests/run.sh run     [--select …] [--include-manual] [--allow-writes-prod] [--fail-fast] [--fresh] [--catalog <경로>]
#                        [--skip-known-fail] [--env-src <0600 파일>] [--timeout-factor 3] [--timeout-min 600] [--no-timeout]
#   tests/run.sh show    <run|last> <id> [--context 8 | --lines a-b | --grep PAT]  실패 조각(200줄 이하)
#   tests/run.sh summary <run|last> [--all]                                     요약 다시 보기
#   tests/run.sh report  <run|last> --md                                        CLAUDE.md 3장 ①~④ 조각
#   tests/run.sh compare <run_a> <run_b> [--md]                                 두 실행 대조(SAME·DIFF·ONLY_A·ONLY_B)
#   선택자(여러 번 = 합집합): id:<id|glob> · module:<m> · env:<e> · status:<s> · path:<glob>(covers 와 겹침)
#                            · diff:<a>..<b> · diff:auto
#   회귀 권장: tests/run.sh run --select diff:<기준>..<후보> --skip-known-fail
#
# 실행 폴더: ${QCHECK_RUNS:-$HOME/.cache/qaredi-check/runs}/<번호>/ (0700) — raw/<id>.log(0600, 원문)
#            · summary.json · summary.txt. 저장소 안이면 거부한다. 출력은 모두 가림 뒤다.
# 기본 제외: manual_only · helper · orphan (--include-manual 로 포함).
# 기본 거부: 운영에 쓰는 시험(writes_prod: true · 또는 cmd 가 저장소 카탈로그의 writes_prod 시험 파일을 부름) — ③ writes_prod.
#   --allow-writes-prod 를 명시하고 그 스크립트의 대상(BASE·DB)이 tests/lib/prod_guard.sh 로 「prod」일 때만 돈다(stage26·f66·fetch_hosts 등).
#   대상이 temp·unknown 이면 이 인자가 있어도 거부(디버깅 묶음 T 9번, 2026-09-26 사고).
# 캐시: 작업 트리가 깨끗할 때만. (HEAD^{tree} + id + 카탈로그 항목 해시)가 같고 24시간 안 통과면 건너뜀.
#       --fresh 는 캐시를 무시. env=prod 항목은 캐시하지 않는다.
# 동시성: run 은 flock 으로 한 번에 하나. 다른 실행이 있으면 rc 3. 잠금은 실행 폴더가 아니라 사용자 단위
#         한 곳 ${XDG_RUNTIME_DIR:-$HOME/.cache}/qaredi-check/run.lock (폴더 0700) — QCHECK_RUNS 를 달리 줘도 막는다.
# 종료 코드: 0 전부 통과(③ 제외) · 1 실패 있음 · 2 사용법(선택자가 0개를 고름 포함) · 3 거부.
#          compare: 0 모두 SAME · 1 DIFF·ONLY 있음 · 2 사용법(없는 실행 번호).
set -u
# 요약기(실행 폴더·원문·요약·잠금)는 umask 077. 시험 자식은 부른 쪽 umask 그대로 돈다(results.py execute).
QCHECK_CHILD_UMASK=$(umask); export QCHECK_CHILD_UMASK
umask 077

HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P) || exit 2
ROOT=$(cd "$HERE/.." && pwd -P) || exit 2
PY="$HERE/lib/results.py"

usage() { sed -n '7,16p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'; }

command -v python3 >/dev/null 2>&1 || { echo "run.sh: python3 가 없다" >&2; exit 2; }
[ -f "$PY" ] || { echo "run.sh: $PY 가 없다" >&2; exit 2; }
[ "$#" -ge 1 ] || { usage >&2; exit 2; }
case "$1" in
  -h|--help|help) usage; exit 0 ;;
  list|run|show|summary|report|compare) ;;
  *) echo "run.sh: 첫 인자는 list|run|show|summary|report|compare 중 하나다: $1" >&2; usage >&2; exit 2 ;;
esac

if [ "$1" = run ]; then
  RUNS="${QCHECK_RUNS:-$HOME/.cache/qaredi-check/runs}"
  # 링크를 모두 푼 실제 경로로 **만들기 전에** 판정한다 — 저장소 안이면 빈 폴더도 만들지 않는다.
  REAL=$(python3 -c 'import os,sys; print(os.path.realpath(os.path.abspath(sys.argv[1])))' "$RUNS") || exit 3
  case "$REAL/" in
    "$ROOT"/*) echo "run.sh: 실행 폴더가 저장소 안이다 — 거부: $REAL (원문은 저장소 밖에만 쓴다)" >&2; exit 3 ;;
  esac
  mkdir -p -- "$REAL" || { echo "run.sh: 실행 폴더를 만들지 못함: $REAL" >&2; exit 3; }
  if [ -L "$REAL" ] || [ ! -d "$REAL" ]; then echo "run.sh: 실행 폴더가 폴더가 아니다 — 거부: $REAL" >&2; exit 3; fi
  [ -O "$REAL" ] || { echo "run.sh: 실행 폴더가 내 소유가 아니다 — 거부: $REAL" >&2; exit 3; }
  [ "$(cd "$REAL" && pwd -P)" = "$REAL" ] || { echo "run.sh: 실행 폴더 경로가 바뀌었다 — 거부: $REAL" >&2; exit 3; }
  chmod 700 -- "$REAL" || exit 3
  command -v flock >/dev/null 2>&1 || { echo "run.sh: flock 이 없다" >&2; exit 3; }
  # 잠금은 사용자 단위 한 곳(실행 폴더 밖) — 실행 폴더를 달리 준 두 실행이 같은 운영 8082·qa 계정·
  # 임시 DB 이름을 동시에 쓰지 않게 한다. 식은 results.py lock_path() 와 같다.
  LOCKDIR="${XDG_RUNTIME_DIR:-$HOME/.cache}/qaredi-check"
  case "$LOCKDIR" in
    /*) ;;
    *) echo "run.sh: 잠금 폴더가 절대 경로가 아니다(XDG_RUNTIME_DIR·HOME 확인) — 거부: $LOCKDIR" >&2; exit 3 ;;
  esac
  mkdir -p -- "$LOCKDIR" || { echo "run.sh: 잠금 폴더를 만들지 못함: $LOCKDIR" >&2; exit 3; }
  if [ -L "$LOCKDIR" ] || [ ! -d "$LOCKDIR" ]; then echo "run.sh: 잠금 폴더가 폴더가 아니다 — 거부: $LOCKDIR" >&2; exit 3; fi
  [ -O "$LOCKDIR" ] || { echo "run.sh: 잠금 폴더가 내 소유가 아니다 — 거부: $LOCKDIR" >&2; exit 3; }
  chmod 700 -- "$LOCKDIR" || exit 3
  if [ -L "$LOCKDIR/run.lock" ]; then echo "run.sh: 잠금 파일이 링크다 — 거부: $LOCKDIR/run.lock" >&2; exit 3; fi
  # 잠금은 이 프로세스(뒤이어 exec 되는 python)가 fd 9 로 쥔다. 시험 자식에게는 넘기지 않는다(close_fds).
  exec 9>>"$LOCKDIR/run.lock" || { echo "run.sh: 잠금 파일을 열지 못함: $LOCKDIR/run.lock" >&2; exit 3; }
  if ! flock -n 9; then
    echo "run.sh: 다른 실행이 진행 중이다(flock $LOCKDIR/run.lock) — 거부" >&2
    exit 3
  fi
  export QCHECK_RUNS="$REAL" QCHECK_LOCK_FD=9
fi

exec python3 "$PY" cli --root "$ROOT" "$@"
