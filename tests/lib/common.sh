# shellcheck shell=bash
# tests/lib/common.sh — 시험 공용 함수 (점검 봇 1-A, docs/checkbot/stage1-directive.md A-1)
#
# 쓰는 법:  . "$(dirname "$0")/lib/common.sh"      (tests/*.sh)
#           . "$(dirname "$0")/../lib/common.sh"   (tests/community/*.sh)
#
# 이 파일은 source 해서 쓴다. 부르는 쪽의 셸 설정(set -e/-u, trap, umask)을 바꾸지 않는다.
# 함수는 부르는 시험의 종료 코드를 바꾸지 않도록 — 잘못 부르면 표준 오류에 알리고 끝난다.
# 1-A 에서는 A-3(result_line)·종료 코드 상수에 필요한 만큼만 둔다. 나머지(자격 주입·
# 임시 인스턴스)는 1-B·1-C 에서 옮긴다.

# 두 번 source 해도 같은 값이 되게 한다(값을 다시 정의할 뿐 부작용 없음).
_TBOX_TESTS_COMMON=1

# ─── 종료 코드 규약 (DESIGN 「판정 단위」, PRD N-4) ─────────────────────────────
RC_OK=0       # 전부 통과
RC_FAIL=1     # 실패 항목이 하나 이상 있다
RC_USAGE=2    # 사용법 오류(인자·환경이 잘못됨) — 시험을 시작하지 않았다
RC_REFUSE=3   # 시작 거부 — 안전 가드가 멈췄다(R-73 사용자 활동 · R-80 디스크 · 동시 실행 · QCHECK 규칙)
RC_PREP=4     # 준비 실패 — 임시 DB 복원·서버 기동·로그인 준비 같은 시험 전 단계가 실패했다
# 요약기(tests/run.sh)는 rc 3·4 를 「통과」도 「실패」도 아닌 ③ 미검증(시작 거부/준비 실패)으로 센다.

# 검토 계정 판정은 tests/lib/review_accounts.sh(F-71)

# ─── 기계용 합계 줄 (N-4) ─────────────────────────────────────────────────────
# result_line <pass> <fail> <skip>
#   기존 한국어 합계 줄 **다음에** 부른다. 기존 줄은 지우지 않는다(사람이 읽는 형식 유지).
#   찍는 것:  RESULT pass=<n> fail=<m> skip=<k>
#   인자가 음이 아닌 정수 셋이 아니면 아무것도 찍지 않고(요약기는 한국어 줄로 센다) 표준 오류에
#   알린다. 어느 경우든 반환은 0 — 합계 줄이 시험의 마지막 명령이어도 종료 코드를 바꾸지 않는다.
result_line() {
  if [ "$#" -ne 3 ]; then
    printf 'result_line: 인자 3개(pass fail skip)가 필요하다 (받은 수 %d)\n' "$#" >&2
    return 0
  fi
  local v
  for v in "$1" "$2" "$3"; do
    case "$v" in
      ''|*[!0-9]*) printf 'result_line: 음이 아닌 정수가 아니다: %q\n' "$v" >&2; return 0 ;;
    esac
  done
  # 앞의 0 을 떼어 10진수로 찍는다(08 같은 값을 8진수로 읽지 않게).
  printf 'RESULT pass=%d fail=%d skip=%d\n' "$((10#$1))" "$((10#$2))" "$((10#$3))"
  return 0
}
