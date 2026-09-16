#!/usr/bin/env bash
# tests/api_whoami.sh — F-56 「프로그램용 자기 확인」 수락 기준 실행 (PRD 12.2 T-68)
#
#   (a) 토큰으로 GET /api/whoami → 200 · 최상위가 **객체** · 키가 정확히
#       {username,role,team_id,upload_levels} 넷(csrf_token·can_*·next 없음) ·
#       username == .env 의 API_TOKEN_USER.
#   (b) 그 upload_levels 가 **같은 계정 세션** GET /api/me 의 upload_levels 와
#       원소·순서까지 같다(중복 구현이 없다는 증거 — Authz::uploadableLevels 하나).
#   (c) 헤더 없음 → 401 {"error":"X-API-Token 헤더가 없습니다"} ·
#       **세션 쿠키만** 보내도 같은 401(토큰 전용 창구라 세션으로 되돌아가지 않는다) ·
#       틀린 토큰 → 401 {"error":"API 토큰이 올바르지 않습니다"}. 문구는 7장과 같다.
#   (d) 감사 — (c) 세 건이 auth.token deny(missing_header·missing_header·bad_token),
#       성공 (a) 는 감사 행 **없음**(대상 없는 조회, /api/me 와 같다).
#   (e) 회귀 — 세션 GET /api/me 응답 **키 집합 불변** · 토큰 헤더로 GET /api/users 는
#       여전히 401 「로그인이 필요합니다」 · scripts/security_lint.sh 0 ·
#       php -l (public/index.php · src/handlers/api_whoami.php) · bash -n.
#
# ★ 토큰 항목의 조건 (a)(b)(d 성공 쪽)
#   진짜 토큰은 `.env` 의 `API_TOKEN_USER` 계정으로 동작한다. 그 귀속이 **검토 계정(qa_)이
#   아니면** 이 항목들을 돌리지 않는다(SKIP) — 시드·운영 계정으로 요청을 보내면 감사 로그에서
#   사용자의 자기 공격과 검토 요청이 갈라지지 않는다(CLAUDE.md 3장).
#   토큰 값은 변수로만 다루고 **어디에도 출력하지 않는다**(SECURITY R-62 · PRD S-09-11).
#
# 규칙 (CLAUDE.md 3장) — tests/api_read.sh 와 같다
#   · 검토 계정(qa_ 접두)만. 시드 계정(admin01 …)은 사용자의 자기 공격 전용이다.
#   · 모든 요청에 `-A "tbox-review/<날짜>"`.
#   · 운영 DB·.env·nginx·php-fpm 에 손대지 않는다. .env 에서 읽는 것은 API_TOKEN_USER **계정 이름**과
#     API_TOKEN **값(변수로만)** 뿐이고, 고치지 않는다.
#   · 이 시험은 아무것도 올리거나 지우지 않는다 — 조회뿐이다.
#
# ★ 시험 안전 (SECURITY R-73 · R-80)
#   ① 최근 10분 감사 로그에 비-qa actor 가 있으면 시작하지 않는다(종료 코드 3)
#   ② 루트 파티션 여유가 1GB 미만이면 시작하지 않는다
#
# 준비물: curl · php(TOTP) · python3(JSON) · sudo -n(seed-accounts.txt · psql). 없으면 해당 항목 SKIP.
# 사용: sudo -v && bash tests/api_whoami.sh
set -u

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BASE="http://127.0.0.1:8082"
UA="tbox-review/$(date +%F)"
DB="tbox_re"
JAR_DIR="$(mktemp -d)"
WORK="$(mktemp -d)"
PASS=0
FAIL=0
SKIP=0

cleanup() { rm -rf "$JAR_DIR" "$WORK"; }
trap cleanup EXIT

hr()  { printf '\n════════════════════════════════════════════════════════════\n%s\n════════════════════════════════════════════════════════════\n' "$1"; }
expect() {
  if [ "$2" = "$3" ]; then printf '  ✔ %s  (기대=%s 실제=%s)\n' "$1" "$2" "$3"; PASS=$((PASS+1))
  else printf '  ✖ %s  (기대=%s 실제=%s)\n' "$1" "$2" "$3"; FAIL=$((FAIL+1)); fi
}
note() { printf '  · %s\n' "$1"; }
skip() { printf '  ○ SKIP %s  (%s)\n' "$1" "$2"; SKIP=$((SKIP+1)); }

psqlq() { sudo -n -u postgres psql -d "$DB" -t -A -c "$1" 2>/dev/null; }
mark()  { psqlq "SELECT coalesce(max(id),0) FROM audit_logs" 2>/dev/null; }

code_of() { printf '%s' "$1" | tail -1; }
body_of() { printf '%s' "$1" | sed '$d'; }

# JSON 판독기 (tests/api_read.sh 와 같다). 표준입력의 JSON 을 `d` 로 놓고 파이썬 식을 평가한다.
# 토큰 값은 이 경로에 오지 않는다(응답 본문만 넣는다).
jq_py() { python3 -c 'import sys,json
d=json.load(sys.stdin)
print(eval(sys.argv[1]))' "$1" 2>/dev/null; }

# ─── 시작 전 관문 ────────────────────────────────────────────────
hr "F-56 프로그램용 자기 확인 — 시험 시작 전 확인"

avail_kb=$(df -Pk / | awk 'NR==2{print $4}')
echo "루트 파티션 여유: $(df -Ph / | awk 'NR==2{print $4}') (${avail_kb}KB)"
[ "$avail_kb" -ge 1048576 ] || { echo "루트 디스크 여유 1GB 미만 — 시험을 시작하지 않는다 (SECURITY R-80)"; exit 3; }

HAVE_PSQL=0
if psqlq "SELECT 1" >/dev/null 2>&1; then HAVE_PSQL=1; fi
if [ "$HAVE_PSQL" -eq 1 ]; then
  BUSY=$(psqlq "SELECT count(*) FROM audit_logs WHERE happened_at > now() - interval '10 minutes' AND actor_name IS NOT NULL AND actor_name NOT LIKE 'qa\_%'")
  echo "최근 10분 비-qa 감사 행: ${BUSY:-?}"
  if [ "${BUSY:-0}" -gt 0 ] 2>/dev/null; then
    echo "사용자가 지금 서비스를 쓰고 있다 — 시험을 시작하지 않는다 (SECURITY R-73 ①)"; exit 3
  fi
else
  echo "psql 을 쓸 수 없다 — 감사 로그 확인 항목은 SKIP 된다"
fi

# ─── 토큰 귀속 확인 — 계정 **이름만** 읽는다 ─────────────────────
TOKEN_USER=$(grep -oE '^API_TOKEN_USER=.*' "$ROOT/.env" 2>/dev/null | cut -d= -f2- | tr -d '"'"'"' \r')
TOKEN_SET=$(grep -c '^API_TOKEN=..*$' "$ROOT/.env" 2>/dev/null); TOKEN_SET=${TOKEN_SET:-0}
echo ".env 의 API_TOKEN 값 있음: $TOKEN_SET · 귀속 계정: ${TOKEN_USER:-없음}"

USE_TOKEN=0
case "${TOKEN_USER:-}" in
  qa_*) if [ "$TOKEN_SET" -eq 1 ]; then USE_TOKEN=1; fi ;;
esac
if [ "$USE_TOKEN" -eq 1 ]; then
  # 값은 변수에만 담고 절대 출력하지 않는다.
  API_TOKEN=$(grep -oE '^API_TOKEN=.*' "$ROOT/.env" | cut -d= -f2- | tr -d '"'"'"' \r')
  echo "→ 귀속이 검토 계정이다. (a)(b) 와 (d) 성공 쪽을 실행한다. (토큰 값은 출력하지 않는다)"
else
  API_TOKEN=''
  echo "→ 귀속이 검토 계정(qa_)이 아니다 — (a)(b)·(d) 성공 쪽은 **SKIP**. Fable 이 검토에서 귀속을 바꿔 돌린다."
fi
# 어떤 서버에서도 맞을 수 없는 고정 문자열. (c) 의 「틀린 토큰」과 (e) 에서 쓴다.
WRONG_TOKEN='wrong-token-0000000000000000000000000000000000'

# ─── 로그인 헬퍼 (tests/api_read.sh 와 같다) ─────────────────────
login() {
  local who="$1" jar="$JAR_DIR/$1.jar" pw sec raw code left out try
  pw=$(sudo -n awk -v u="$who" '$1==u{print $3}' /etc/tbox-v2/seed-accounts.txt 2>/dev/null)
  sec=$(sudo -n awk -v u="$who" '$1==u{print $2}' /etc/tbox-v2/qa-totp.txt 2>/dev/null)
  [ -n "$pw" ] && [ -n "$sec" ] || return 2
  for try in 1 2 3; do
    raw=$(php "$ROOT/scripts/totp_code.php" "$sec")
    code=${raw%%$'\n'*}
    out=$(curl -s -c "$jar" -A "$UA" -H 'Content-Type: application/json' \
          -d "{\"username\":\"$who\",\"password\":\"$pw\",\"otp\":\"$code\"}" "$BASE/api/login")
    case "$out" in
      *'"ok":true'*)
        curl -s -b "$jar" -A "$UA" "$BASE/api/me" \
          | sed -n 's/.*"csrf_token":"\([0-9a-f]*\)".*/\1/p'
        return 0 ;;
    esac
    left=$(printf '%s' "$raw" | sed -n 's/^남은 시간: \([0-9]*\)초.*/\1/p')
    [ -n "$left" ] || left=31
    printf '  · %s 코드 재사용으로 거부됨 — 다음 창까지 %s초 기다림 (R-45 가 의도대로 막은 것)\n' "$who" "$left" >&2
    sleep $((left + 1))
  done
  return 1
}

# ─── 준비: 세션 하나 (c)(e) 용, 그리고 귀속 계정 세션 (b) 용 ─────
hr "준비 — 검토 계정 세션"

EMP_CSRF=$(login qa_employee01)
if [ -n "${EMP_CSRF:-}" ]; then note "qa_employee01 세션 확보"; else note "qa_employee01 로그인 실패 — (c) 세션 항목·(e) /api/me 회귀는 SKIP 된다"; fi

# (b) 는 **토큰 귀속 계정 그 자신**의 세션이어야 한다.
OWN_CSRF=''
if [ "$USE_TOKEN" -eq 1 ]; then
  if [ "$TOKEN_USER" = "qa_employee01" ]; then
    OWN_CSRF="${EMP_CSRF:-}"
  else
    OWN_CSRF=$(login "$TOKEN_USER")
  fi
  [ -n "${OWN_CSRF:-}" ] && note "귀속 계정($TOKEN_USER) 세션 확보" || note "귀속 계정($TOKEN_USER) 로그인 실패 — (b) 는 SKIP"
fi

# ─── (a) 토큰으로 whoami — 키가 정확히 넷 ────────────────────────
hr "(a) 토큰으로 GET /api/whoami"
if [ "$USE_TOKEN" -eq 0 ]; then
  skip "(a)" "토큰 귀속이 검토 계정(qa_)이 아님 — 값은 읽지 않았다"
else
  T0=$(mark)
  OUT=$(curl -s -w '\n%{http_code}' -A "$UA" -H "X-API-Token: $API_TOKEN" "$BASE/api/whoami")
  CODE=$(code_of "$OUT"); BODY=$(body_of "$OUT")
  printf '%s' "$BODY" > "$WORK/a.json"
  echo "  응답: $BODY"
  expect "상태" "200" "$CODE"
  expect "최상위가 객체" "dict" "$(jq_py 'type(d).__name__' < "$WORK/a.json")"
  expect "키가 정확히 넷" "role,team_id,upload_levels,username" \
    "$(jq_py '",".join(sorted(d.keys()))' < "$WORK/a.json")"
  expect "키 순서(문서와 같은 순서)" "username,role,team_id,upload_levels" \
    "$(jq_py '",".join(d.keys())' < "$WORK/a.json")"
  expect "username == .env API_TOKEN_USER" "$TOKEN_USER" "$(jq_py 'd["username"]' < "$WORK/a.json")"
  note "role=$(jq_py 'd["role"]' < "$WORK/a.json")  team_id=$(jq_py 'd["team_id"]' < "$WORK/a.json")  upload_levels=$(jq_py 'json.dumps(d["upload_levels"])' < "$WORK/a.json")"

  # (d) 성공 쪽 — 조회는 감사 행을 남기지 않는다.
  if [ "$HAVE_PSQL" -eq 1 ]; then
    N=$(psqlq "SELECT count(*) FROM audit_logs WHERE id > ${T0:-0}")
    expect "(d) 성공 요청이 남긴 감사 행" "0" "${N:-?}"
  else
    skip "(d) 성공 쪽" "psql 을 쓸 수 없음"
  fi
fi

# ─── (b) upload_levels 는 /api/me 와 같은 함수다 ─────────────────
hr "(b) upload_levels == 같은 계정 세션 GET /api/me 의 값 (원소·순서)"
if [ "$USE_TOKEN" -eq 0 ]; then
  skip "(b)" "토큰 귀속이 검토 계정(qa_)이 아님"
elif [ -z "${OWN_CSRF:-}" ]; then
  skip "(b)" "귀속 계정($TOKEN_USER) 세션 없음"
else
  curl -s -b "$JAR_DIR/$TOKEN_USER.jar" -A "$UA" "$BASE/api/me" > "$WORK/b_me.json"
  WHO_LV=$(jq_py 'json.dumps(d["upload_levels"])' < "$WORK/a.json")
  ME_LV=$(jq_py 'json.dumps(d["upload_levels"])' < "$WORK/b_me.json")
  echo "  whoami: $WHO_LV"
  echo "  me    : $ME_LV"
  expect "두 값이 JSON 배열로 완전히 같다" "$ME_LV" "$WHO_LV"
fi

# ─── (c) 토큰 전용 창구 — 헤더 없음·세션만·틀린 토큰은 전부 401 ──
hr "(c) 인증 실패 세 가지 — 문구는 7장과 같다"
T0=$(mark)

OUT=$(curl -s -w '\n%{http_code}' -A "$UA" "$BASE/api/whoami")
echo "  헤더 없음 → [$(code_of "$OUT")] $(body_of "$OUT")"
expect "헤더 없음 상태" "401" "$(code_of "$OUT")"
expect "헤더 없음 문구" '{"error":"X-API-Token 헤더가 없습니다"}' "$(body_of "$OUT")"

if [ -z "${EMP_CSRF:-}" ]; then
  skip "(c) 세션 쿠키만" "qa_employee01 세션 없음"
  SESSION_TRIED=0
else
  OUT=$(curl -s -w '\n%{http_code}' -b "$JAR_DIR/qa_employee01.jar" -A "$UA" "$BASE/api/whoami")
  echo "  세션 쿠키만 → [$(code_of "$OUT")] $(body_of "$OUT")"
  expect "세션 쿠키만 상태(토큰 전용이라 세션으로 열리지 않는다)" "401" "$(code_of "$OUT")"
  expect "세션 쿠키만 문구" '{"error":"X-API-Token 헤더가 없습니다"}' "$(body_of "$OUT")"
  SESSION_TRIED=1
fi

OUT=$(curl -s -w '\n%{http_code}' -A "$UA" -H "X-API-Token: $WRONG_TOKEN" "$BASE/api/whoami")
echo "  틀린 토큰 → [$(code_of "$OUT")] $(body_of "$OUT")"
expect "틀린 토큰 상태(세션으로 되돌아가지 않는다)" "401" "$(code_of "$OUT")"
expect "틀린 토큰 문구" '{"error":"API 토큰이 올바르지 않습니다"}' "$(body_of "$OUT")"

# ─── (d) 감사 — 실패 세 건이 auth.token deny 로 남는다 ───────────
hr "(d) 감사 — auth.token deny (새 문장·새 조합 없음)"
if [ "$HAVE_PSQL" -eq 0 ]; then
  skip "(d) 실패 쪽" "psql 을 쓸 수 없음"
else
  GOT=$(psqlq "SELECT string_agg(result||' '||coalesce(reason,'없음'), ' | ' ORDER BY id) FROM audit_logs WHERE action='auth.token' AND id > ${T0:-0}")
  if [ "${SESSION_TRIED:-0}" -eq 1 ]; then
    WANT='deny missing_header | deny missing_header | deny bad_token'
  else
    WANT='deny missing_header | deny bad_token'
  fi
  expect "auth.token 행" "$WANT" "${GOT:-없음}"
  TGT=$(psqlq "SELECT DISTINCT target_name FROM audit_logs WHERE action='auth.token' AND id > ${T0:-0}")
  expect "대상은 라우트 패턴뿐(계정·토큰 값 없음)" "GET /api/whoami" "${TGT:-없음}"
fi

# ─── (e) 회귀 ────────────────────────────────────────────────────
hr "(e) 회귀 — /api/me 불변 · 토큰은 다른 창구를 열지 못한다 · 린트"
if [ -z "${EMP_CSRF:-}" ]; then
  skip "(e) /api/me 키 집합" "qa_employee01 세션 없음"
else
  curl -s -b "$JAR_DIR/qa_employee01.jar" -A "$UA" "$BASE/api/me" > "$WORK/e_me.json"
  # F-56 이전과 같은 키 집합이어야 한다(Employee 세션 기준 — can_fetch 가 false 라
  # fetch_hosts_public 는 애초에 없다. me.php 는 이번 변경에서 손대지 않았다).
  expect "세션 /api/me 키 집합 불변" \
    "acting,acting_as,acting_idle_minutes,branch_id,can_admin,can_fetch,can_manage_org,can_manage_users,can_purchase,can_purchase_master,can_stop_acting,can_view_audit,can_view_trash,can_view_wiki,csrf_token,fetch_enabled,mfa_enabled,mfa_recommended,mfa_required,mfa_setup_required,must_change_password,next,purchase_role,real_role,reauth_fresh,role,team_id,team_name,upload_levels,username" \
    "$(jq_py '",".join(sorted(d.keys()))' < "$WORK/e_me.json")"
fi

if [ "$USE_TOKEN" -eq 1 ]; then TOK="$API_TOKEN"; note "진짜 토큰으로 확인한다(값은 출력하지 않는다)"
else TOK="$WRONG_TOKEN"; note "귀속이 검토 계정이 아니라 **틀린 토큰**으로 확인한다 — 이 항목이 보는 것은 「헤더를 아예 읽지 않는가」다"; fi

T1=$(mark)
OUT=$(curl -s -w '\n%{http_code}' -A "$UA" -H "X-API-Token: $TOK" "$BASE/api/users")
echo "  GET /api/users → [$(code_of "$OUT")] $(body_of "$OUT")"
expect "GET /api/users 상태" "401" "$(code_of "$OUT")"
expect "GET /api/users 문구(세션 경로 — 헤더를 읽지 않는다)" '{"error":"로그인이 필요합니다"}' "$(body_of "$OUT")"
if [ "$HAVE_PSQL" -eq 1 ]; then
  N=$(psqlq "SELECT count(*) FROM audit_logs WHERE action='auth.token' AND id > ${T1:-0}")
  expect "그 사이 auth.token 감사 행" "0" "${N:-?}"
fi

bash "$ROOT/scripts/security_lint.sh" >"$WORK/lint.out" 2>&1
expect "scripts/security_lint.sh" "0" "$?"
tail -1 "$WORK/lint.out" | sed 's/^/  /'

PHPBAD=0
for f in public/index.php src/handlers/api_whoami.php; do
  php -l "$ROOT/$f" >/dev/null 2>&1 || { echo "  ✖ php -l 실패: $f"; PHPBAD=1; }
done
expect "바뀐 PHP 2개 php -l" "0" "$PHPBAD"

SHBAD=0
bash -n "$ROOT/tests/api_whoami.sh" 2>/dev/null || SHBAD=1
bash -n "$ROOT/tests/api_read.sh"   2>/dev/null || SHBAD=1
expect "bash -n (시험 스크립트 둘)" "0" "$SHBAD"

# ─── 마무리 ─────────────────────────────────────────────────────
hr "결과"
printf '통과 %d · 실패 %d · 건너뜀/미검증 %d\n' "$PASS" "$FAIL" "$SKIP"
if [ "$USE_TOKEN" -eq 0 ]; then
  echo "(a)(b) 와 (d) 성공 쪽은 토큰 귀속이 검토 계정(qa_)이 아니라 실행하지 않았다 — **미검증**이다."
fi
[ "$FAIL" -eq 0 ] || exit 1
exit 0
