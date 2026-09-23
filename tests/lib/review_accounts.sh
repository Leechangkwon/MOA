# tests/lib/review_accounts.sh — 「검토 계정」 판정 한 벌 (F-71 C-1~C-4 · SECURITY R-73 · ARCH 4.0)
#
# 검토 계정은 이름 접두 **둘**이다 (PRD S-07 「검토 요청 표식」, 2026-09-24 F-71).
#   qa_      옛 시험(2026-09-10~). 계정은 지우지 않는다 — 옛 기록 확인용
#   claude_  새로 쓰거나 고치는 시험(2026-09-24~)
# 판정을 시험 파일마다 따로 적으면 한쪽만 넓어지는 날이 생긴다 — 그러면 claude_ 시험이 도는 동안
# 옛 시험의 R-73 사전 검사가 그 행을 「사람이 쓰는 중」으로 세어 멈추고, 거꾸로 사람 행동을 검토로
# 잘못 세면 안전장치가 풀린다(지시서 C 머리). 그래서 판정은 이 파일 **한 곳**이고 시험은 source 해 쓴다.
#
# 쓰는 법 — 각 시험의 ROOT 계산 바로 뒤에 한 줄
#     . "$ROOT/tests/lib/review_accounts.sh" || { echo "…"; exit 3; }
#   회귀 때 시험이 `$INST/app/tests/<이름>.temp.sh`(커뮤니티는 `…/tests/community/<이름>.temp.sh`)로
#   복사돼 돌아도 ROOT 는 그 사본의 `$INST/app` 이고, 임시 인스턴스는 트리를 통째로 복사하므로
#   이 파일도 `$INST/app/tests/lib/` 에 있다 — 같은 경로가 맞는다.
#   **읽지 못하면 시작하지 않는다**(`|| exit 3`): 조각이 비면 사전 검사 쿼리가 깨져 건수가 빈 값이
#   되고, `${BUSY:-0}` 이 0 으로 읽혀 관문이 열린다. 그 길을 막는 것이 이 한 줄의 몫이다.
#
# 주는 것
#   REVIEW_NOT_SQL           actor_name NOT LIKE 'qa\_%' AND actor_name NOT LIKE 'claude\_%'
#                            (C-1 R-73 사전 검사 · C-2 「사용자 행동 0」 대조)
#   review_not_sql <열>      <열> NOT LIKE 'qa\_%' AND <열> NOT LIKE 'claude\_%'
#   review_like_sql <열>     (<열> LIKE 'qa\_%' OR <열> LIKE 'claude\_%')      (C-4 정리·집계)
#   review_account <이름>    종료 코드 0 = 검토 계정, 1 = 아님                    (bash 판정)
#   C-3 의 `case … in qa_*|claude_*)` 는 bash 문법상 변수로 넘길 수 없어 각 파일에 글자로 적는다 —
#   규칙은 review_account 와 같다.
#
# ★ 이스케이프 — 결과 SQL 에는 반드시 `'qa\_%'`(역슬래시 **하나**)가 들어가야 한다.
#   LIKE 에서 `_` 는 「아무 글자 하나」라 `'qa_%'` 로 쓰면 `qaX…` 같은 사람 계정도 검토로 센다.
#   PostgreSQL 은 standard_conforming_strings=on(기본)이라 '…\_…' 의 역슬래시가 글자 그대로 LIKE 에
#   가고, LIKE 의 기본 이스케이프 문자가 `\` 라 `\_` 가 「밑줄 한 글자」가 된다.
#   bash 큰따옴표 안에서는 `\\` 가 `\` 하나가 되므로 아래 정의는 `\\_` 로 적는다. 옛 시험에
#   `'qa\_%'` 와 `'qa\\_%'` 가 섞여 있던 것은 둘 다 큰따옴표 안이라 결과가 같았기 때문이다
#   (`\_` 는 bash 가 건드리지 않는 조합). 맨 아래 review_accounts_selfcheck 가 source 할 때마다
#   작은따옴표 글자(역슬래시를 해석하지 않는다)와 한 글자씩 대조한다.
#
# ★ 열 이름 인자는 **코드에 적힌 고정 문자열**만 넘긴다(actor_name · username · u.username 등).
#   사용자 입력·DB 값을 넣지 않는다. 모양(`식별자` 또는 `별칭.식별자`)이 아니면 **안전한 쪽**으로
#   떨어진다 — review_not_sql 은 `TRUE`(모든 행을 사람으로 세어 사전 검사가 멈춘다),
#   review_like_sql 은 `FALSE`(검토 계정 행이 하나도 없는 것으로 — 정리가 아무것도 지우지 않는다).
#
# ★ 이 판정은 **계정 이름만** 본다(지시서 C 끝 문단). User-Agent 표식은 보지 않는다 — R-73 사전
#   검사는 「사람이 쓰는 중이면 멈춘다」 쪽이 안전하다. 감사 화면의 「Claude」 배지는 계정 **그리고**
#   UA 로 `src/Origin.php` 가 따로 판정한다(보여 주기 전용).
#
# 이 파일은 실행하지 않고 source 만 한다(실행 비트·shebang 없음). DB·네트워크에 닿지 않는다.

_review_col_ok() {
  case "${1:-}" in
    ''|*[!a-z0-9_.]*|.*|*.|*..*|[0-9]*) return 1 ;;
  esac
  return 0
}

review_not_sql() {
  local c="${1:-}"
  if ! _review_col_ok "$c"; then
    echo "review_not_sql: 열 이름 모양이 아니다 — TRUE(전부 사람으로 센다)로 떨어진다" >&2
    printf '%s' "TRUE"
    return 1
  fi
  printf '%s' "$c NOT LIKE 'qa\\_%' AND $c NOT LIKE 'claude\\_%'"
}

review_like_sql() {
  local c="${1:-}"
  if ! _review_col_ok "$c"; then
    echo "review_like_sql: 열 이름 모양이 아니다 — FALSE(검토 계정 행 없음)로 떨어진다" >&2
    printf '%s' "FALSE"
    return 1
  fi
  printf '%s' "($c LIKE 'qa\\_%' OR $c LIKE 'claude\\_%')"
}

review_account() {
  case "${1:-}" in
    qa_*|claude_*) return 0 ;;
  esac
  return 1
}

REVIEW_NOT_SQL="$(review_not_sql actor_name)"

review_accounts_selfcheck() {
  # 작은따옴표 안은 역슬래시를 해석하지 않는다 — 그래서 「기대한 글자」의 기준으로 쓴다.
  local want_not want_like
  want_not='actor_name NOT LIKE '\''qa\_%'\'' AND actor_name NOT LIKE '\''claude\_%'\'''
  want_like='(u.username LIKE '\''qa\_%'\'' OR u.username LIKE '\''claude\_%'\'')'
  [ "$REVIEW_NOT_SQL" = "$want_not" ] || return 1
  [ "$(review_like_sql u.username)" = "$want_like" ] || return 1
  review_account qa_employee01 && review_account claude_employee01 || return 1
  ! review_account employee01 && ! review_account qaemployee01 && ! review_account claudeX || return 1
  return 0
}

if ! review_accounts_selfcheck; then
  echo "tests/lib/review_accounts.sh: 판정 조각의 모양이 기대와 다르다(이스케이프 확인) — 시험을 시작하지 않는다" >&2
  return 1 2>/dev/null || exit 1
fi
