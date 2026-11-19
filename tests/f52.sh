#!/usr/bin/env bash
# tests/f52.sh — F-52 수락 기준 실행 (PRD 12.2 T-64)
#   (a) 단위   — Auth::nextStep · mfaRequired · mfaRecommended 를 배열 입력으로 (역할 4종 × 켜짐/꺼짐)
#   (b) 직원   — claude_employee01 을 끈 상태로: next null · /api/files 200 · 홈 권장 카드 · 「나중에」 · /settings 문구
#   (c) 관리자 — claude_admin01 을 끈 상태로: next mfa_setup · /api/files 403 · 감사 auth.gate · 홈 강제 상자
#   (d) 순차 2단계 — 끄면 코드 없이 로그인 200, 켜면 코드 없이 401 mfa_required ((b) 앞뒤에서)
#   (e) 문구   — 화면·도움말·README·위키에 「모든 역할」 0건
#   (f) 정적   — 린트 0 · php -l · wiki JSON 파싱 · 비-qa 감사 0 · 끝난 뒤 두 계정 mfa_enabled = t
#
# 규칙 (CLAUDE.md 3장)
#   · **검토 계정은 claude_employee01 · claude_admin01 둘만** 쓴다. 시드 계정(admin01 …)은 사용자의 자기 공격 전용이다.
#   · 모든 요청에 `-A "tbox-review/<날짜>"` 를 붙인다.
#   · 비밀번호·TOTP 비밀은 **변수에만** 담는다 — 화면·파일에 찍지 않는다 (SECURITY R-62).
#     명령 인자에도 싣지 않는다 — 표준입력·0600 임시 파일로만 넘긴다(tests/lib/secrets.sh · 점검 봇 R15 · C①).
#
# ★ 시험 안전 (SECURITY R-73)
#   ① 최근 10분 감사 로그에 비-qa actor 가 하나라도 있으면 **시작하지 않는다**(종료 코드 3).
#   ② 이 시험은 두 계정의 **2단계 인증을 껐다 켠다**. 끌 때마다 비밀이 새로 만들어지므로
#      마지막에 반드시 confirm 으로 다시 켜고 `/etc/tbox-v2/qa-totp.txt` 를 새 값으로 갱신한다
#      (다른 시험이 이 파일로 로그인한다). 다른 계정·파일·표에는 손대지 않는다.
#      ★ 운영 파일에 쓰는 것은 **대상이 운영일 때만**(BASE 운영 검토 주소 · DB tbox_re — tests/lib/prod_guard.sh).
#        회귀 틀이 BASE·DB 를 임시로 바꿔 돌리면 새 비밀은 이 실행의 메모리에만 두고(또는 QA_TOTP_FILE 로 준 자리에) 운영 파일은
#        건드리지 않는다. 대상을 가를 수 없으면 시작하지 않는다. 쓰기 직전에 BASE·DB·운영 .env 의 DB_NAME 을 다시 본다
#        (2026-09-26 13:52 사고 — 임시 대상 실행이 운영 qa-totp.txt 를 덮어씀 · 디버깅 묶음 T 9번).
#   ③ 바깥으로 나가는 요청이 없다.
#
# 준비물: sudo(/etc/tbox-v2 읽기·쓰기 · psql), php, curl, python3(+playwright)
#
# 사용: sudo -v && bash tests/f52.sh [출력 폴더]
#   출력 폴더 기본값은 ~/.claude/ui-shots/f52 (루트 디스크를 쓰지 않는다).
set -u

BASE="http://127.0.0.1:8082/tbox"
UA="tbox-review/$(date +%F)"
DB="tbox_re"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
# 검토 계정 판정 한 벌(qa_·claude_ — F-71 C). 못 읽으면 사전 검사가 빈 값으로 열리므로 시작하지 않는다.
. "$ROOT/tests/lib/review_accounts.sh" || { echo "tests/lib/review_accounts.sh 를 읽지 못했다 — 시험을 시작하지 않는다 (R-73)"; exit 3; }
. "$ROOT/tests/lib/secrets.sh" || { echo "tests/lib/secrets.sh 를 읽지 못했다 — 시험을 시작하지 않는다 (R-62)"; exit 3; }
. "$ROOT/tests/lib/db_read.sh" || { echo "tests/lib/db_read.sh 를 읽지 못했다 — 시험을 시작하지 않는다 (R-73)"; exit 3; }
OUT="${1:-$HOME/.claude/ui-shots/f52}"
JAR_DIR="$(mktemp -d)"
# JAR_DIR(0700)은 쿠키 jar 폴더다 — 어떻게 끝나도 지운다(trap 에 exit 가 없어 종료 코드는 그대로)
trap 'rm -rf "$JAR_DIR"' EXIT
PASS=0
FAIL=0
. "$ROOT/tests/lib/common.sh"   # result_line (점검 봇 1-A A-3) — 셸 설정을 바꾸지 않는다

EMP="claude_employee01"
ADM="claude_admin01"
SEED_FILE="/etc/tbox-v2/seed-accounts.txt"   # 읽기만
. "$ROOT/tests/lib/prod_guard.sh" || { echo "tests/lib/prod_guard.sh 를 읽지 못했다 — 시험을 시작하지 않는다(운영 파일 가드)"; exit 3; }
# 2단계 비밀 파일 — 읽는 자리 = QA_TOTP_FILE(주면) 아니면 운영 파일(읽기). 새 비밀을 **쓰는** 자리는 대상에 따라:
#   운영 대상 → 그 파일(쓰기 직전 prod_write_guard) · 임시 대상 → QA_TOTP_FILE 을 주었으면 그 파일, 아니면 쓰지 않고 메모리에만.
PROD_TOTP_FILE="/etc/tbox-v2/qa-totp.txt"
TOTP_FILE="${QA_TOTP_FILE:-$PROD_TOTP_FILE}"
TARGET_KIND=$(pg_target_kind)
case "$TARGET_KIND" in
  prod)
    # 운영 대상 — 운영 상태(운영 계정 2단계)를 **바꾸기 전에** 쓰기 가드를 한 번 먼저 본다. 쓰기 직전(save_secret)에만 보면 2단계를 이미
    #   바꾼 뒤 멈춰 운영 파일이 옛 값으로 남는다(13:52 의 반대 방향 · 독립 검토 중간 1). 운영 대상에 QA_TOTP_FILE 을 주면 새 비밀이
    #   그 파일로만 가고 운영 파일은 옛 값이 되므로 거부한다.
    [ -z "${QA_TOTP_FILE:-}" ] || { echo "운영 대상에는 QA_TOTP_FILE 을 줄 수 없다(운영 $PROD_TOTP_FILE 이 옛 값으로 남는다) — 시험을 시작하지 않는다"; exit 3; }
    prod_write_guard "$PROD_TOTP_FILE" || { echo "운영 파일 쓰기 가드가 막는다 — 운영 상태를 바꾸기 전에 시험을 시작하지 않는다"; exit 3; }
    TOTP_SAVE=file ;;
  temp) if [ -n "${QA_TOTP_FILE:-}" ]; then TOTP_SAVE=file; else TOTP_SAVE=memory; fi ;;
  *) echo "대상이 운영인지 임시인지 가를 수 없다(BASE=$BASE · DB=$DB) — 시험을 시작하지 않는다(운영 파일 가드)"; exit 3 ;;
esac
declare -A MEM_SECRET=()   # 임시 대상에서 계정별 새 비밀(값은 찍지 않는다)

mkdir -p "$OUT" "$OUT/shots"

psqlq() { sudo -n -u postgres psql -d "$DB" -t -A -c "$1"; }

hr()  { printf '\n════════════════════════════════════════════════════════════\n%s\n════════════════════════════════════════════════════════════\n' "$1"; }
sub() { printf '\n── %s\n' "$1"; }

# $1 설명  $2 기대  $3 실제
expect() {
  if [ "$2" = "$3" ]; then
    printf '  ✔ %s  (기대=%s 실제=%s)\n' "$1" "$2" "$3"; PASS=$((PASS+1))
  else
    printf '  ✖ %s  (기대=%s 실제=%s)\n' "$1" "$2" "$3"; FAIL=$((FAIL+1))
  fi
}

code_of() { printf '%s' "$1" | tail -1; }
body_of() { printf '%s' "$1" | sed '$d'; }

# 본문에서 JSON 값 하나를 꺼낸다 (없으면 '없음'). 비밀이 아닌 boolean·문자열만 쓴다.
jget() { python3 -c '
import json,sys
try: d=json.load(sys.stdin)
except Exception: print("파싱실패"); raise SystemExit
v=d.get(sys.argv[1], "없음")
print("null" if v is None else (str(v).lower() if isinstance(v,bool) else v))
' "$1"; }

# ── 비밀 다루기 (전부 변수에만 — 화면에 찍지 않는다) ─────────────
qa_secret()   { if [ -n "${MEM_SECRET[$1]-}" ]; then printf '%s\n' "${MEM_SECRET[$1]}"; else sudo -n awk -v u="$1" '$1==u{print $2}' "$TOTP_FILE"; fi; }
qa_password() { sudo -n awk -v u="$1" '$1==u{print $3}' "$SEED_FILE"; }

mfa_on() { psqlq "SELECT mfa_enabled FROM users WHERE username='$1'"; }

# 지금 코드 — 남은 시간이 3초 미만이면 다음 창까지 기다린다
now_code() {
  local raw left s1="$1"
  raw=$(totp_now s1)
  left=$(printf '%s' "$raw" | sed -n 's/^남은 시간: \([0-9]*\)초.*/\1/p')
  [ -n "$left" ] || left=30
  if [ "$left" -lt 3 ]; then
    sleep $((left + 1))
    raw=$(totp_now s1)
  fi
  printf '%s\n' "$raw" | head -1
}

# 다음 창의 코드 — TOTP 코드는 1회성(R-45)이라 같은 창에서 두 번 쓰면 거부되는 것이 **정상**이다
next_code() {
  local raw left s1="$1"
  raw=$(totp_now s1)
  left=$(printf '%s' "$raw" | sed -n 's/^남은 시간: \([0-9]*\)초.*/\1/p')
  [ -n "$left" ] || left=30
  printf '  · 다음 코드 창까지 %s초 기다림 (코드 1회성 R-45)\n' "$left" >&2
  sleep $((left + 1))
  raw=$(totp_now s1)
  printf '%s\n' "$raw" | head -1
}

# qa-totp.txt 의 한 계정 비밀을 새 값으로 (다른 시험이 이 파일로 로그인한다)
#   $2 = 새 비밀이 든 **변수 이름**(값이 아니다). 값은 py_secret 이 표준입력으로만 sudo awk 에 준다 —
#   `awk -v s=값` 이면 ps 와 auth.log 의 COMMAND= 에 새 비밀이 남는다(점검 봇 C① · R-62).
#   빈 값·줄바꿈(py_secret rc 90)·base32 가 아닌 값(awk rc 2)·대상 계정 줄이 1개가 아님(awk rc 3)이면
#   install 을 부르지 않는다 — 빈 값으로 qa-totp.txt 를 덮어쓰는 사고를 막는다.
save_secret() {  # $1 계정  $2 새 비밀이 든 변수 이름
  local tmp v
  if [ "$TOTP_SAVE" = memory ]; then
    v=${!2-}
    if [[ "$v" =~ ^[A-Z2-7]{16,64}$ ]]; then
      MEM_SECRET[$1]=$v
      echo "  · 대상이 임시(DB $DB)다 — $1 의 새 비밀은 이 실행의 메모리에만 둔다(운영 $PROD_TOTP_FILE 쓰기 0)"
      return 0
    fi
    echo "  ! $1 새 비밀 모양이 아니다 — 메모리에도 두지 않았다"
    return 1
  fi
  # 운영 자리면 쓰기 **직전에** 다시 확인 — 어긋나면 멈춘다(rc 3)
  prod_write_guard "$TOTP_FILE" || { echo "  ! 운영 파일 쓰기 가드가 막았다 — 시험을 멈춘다"; exit 3; }
  tmp=$(mktemp)
  if py_secret "$2" -- sudo -n awk -v u="$1" '
        BEGIN { if ((getline s < "/dev/stdin") <= 0 || s !~ /^[A-Z2-7]+$/) { bad = 1; exit 2 } }
        $1 == u { $2 = s; n++ } { print }
        END { if (bad) exit 2; if (n != 1) exit 3 }' "$TOTP_FILE" > "$tmp" \
     && if pg_is_prod_path "$TOTP_FILE"; then sudo -n install -o root -g root -m 600 "$tmp" "$TOTP_FILE"; else install -m 600 "$tmp" "$TOTP_FILE"; fi; then
    rm -f "$tmp"
    echo "  · $TOTP_FILE 의 $1 비밀을 새 값으로 갱신했다 (값은 찍지 않는다)"
    return 0
  fi
  rm -f "$tmp"
  echo "  ! $TOTP_FILE 갱신 실패 — 다음 시험이 로그인하지 못한다"
  return 1
}

# 로그인 → csrf 토큰을 찍는다. 그 계정의 2단계 인증이 **꺼져 있으면 코드 없이** 보낸다.
login() {  # $1 계정
  local who="$1" jar="$JAR_DIR/$1.jar" pw sec code out try body
  pw=$(qa_password "$who")
  if [ "$(mfa_on "$who")" != "t" ]; then
    printf -v body '{"username":"%s","password":"%s"}' "$who" "$pw"
    out=$(curl_json_secret body -s -c "$jar" -A "$UA" -H 'Content-Type: application/json' "$BASE/api/login")
    case "$out" in
      *'"ok":true'*)
        curl -s -b "$jar" -A "$UA" "$BASE/api/me" \
          | sed -n 's/.*"csrf_token":"\([0-9a-f]*\)".*/\1/p'
        return 0 ;;
    esac
    printf '  ! %s (2단계 인증 꺼짐) 로그인 실패\n' "$who" >&2
    return 1
  fi
  sec=$(qa_secret "$who")
  for try in 1 2 3; do
    code=$(now_code "$sec")
    printf -v body '{"username":"%s","password":"%s","otp":"%s"}' "$who" "$pw" "$code"
    out=$(curl_json_secret body -s -c "$jar" -A "$UA" -H 'Content-Type: application/json' "$BASE/api/login")
    case "$out" in
      *'"ok":true'*)
        curl -s -b "$jar" -A "$UA" "$BASE/api/me" \
          | sed -n 's/.*"csrf_token":"\([0-9a-f]*\)".*/\1/p'
        return 0 ;;
    esac
    code=$(next_code "$sec")
    printf -v body '{"username":"%s","password":"%s","otp":"%s"}' "$who" "$pw" "$code"
    out=$(curl_json_secret body -s -c "$jar" -A "$UA" -H 'Content-Type: application/json' "$BASE/api/login")
    case "$out" in
      *'"ok":true'*)
        curl -s -b "$jar" -A "$UA" "$BASE/api/me" \
          | sed -n 's/.*"csrf_token":"\([0-9a-f]*\)".*/\1/p'
        return 0 ;;
    esac
  done
  printf '  ! %s 로그인 실패\n' "$who" >&2
  return 1
}

# 2단계 인증을 **다시 켠다** — enroll → confirm → qa-totp.txt 갱신 (강제 절차 관문 중에도 허용)
reenable_mfa() {  # $1 계정
  local who="$1" jar="$JAR_DIR/$1.jar" tok body new code out
  tok=$(login "$who") || return 1
  body=$(curl -s -b "$jar" -A "$UA" -H 'Content-Type: application/json' \
         -H "X-CSRF-Token: $tok" -d '{}' "$BASE/api/me/mfa/enroll")
  new=$(printf '%s' "$body" | python3 -c 'import json,sys; print(json.load(sys.stdin)["secret"])' 2>/dev/null)
  if [ -z "${new:-}" ]; then
    echo "  ! $who 다시 켜기 실패 — enroll 응답에 secret 이 없다"
    return 1
  fi
  code=$(now_code "$new")
  out=$(curl -s -w '\n%{http_code}' -b "$jar" -A "$UA" -H 'Content-Type: application/json' \
        -H "X-CSRF-Token: $tok" -d "{\"otp\":\"$code\"}" "$BASE/api/me/mfa/confirm")
  if [ "$(code_of "$out")" != "200" ]; then
    echo "  ! $who confirm 실패: HTTP $(code_of "$out") $(body_of "$out")"
    return 1
  fi
  save_secret "$who" new || return 1
  echo "  · $who 의 2단계 인증을 다시 켰다 (HTTP 200)"
  return 0
}

# 2단계 인증을 **끈다** — 현재 비밀번호 + 지금 코드
disable_mfa() {  # $1 계정  $2 csrf
  local who="$1" tok="$2" jar="$JAR_DIR/$1.jar" pw sec code body
  pw=$(qa_password "$who"); sec=$(qa_secret "$who")
  code=$(next_code "$sec")
  printf -v body '{"current_password":"%s","otp":"%s"}' "$pw" "$code"
  curl_json_secret body -s -w '\n%{http_code}' -b "$jar" -A "$UA" -H 'Content-Type: application/json' \
    -H "X-CSRF-Token: $tok" "$BASE/api/me/mfa/disable"
}

# ─────────────────────────────────────────────────────────────
hr "0. 시험 안전 점검 (SECURITY R-73)"
# ─────────────────────────────────────────────────────────────
T0=$(psqlq "SELECT now();")
db_read_var NONQA_BEFORE "$DB" "SELECT count(*) FROM audit_logs WHERE happened_at > now() - interval '10 minutes' AND actor_name IS NOT NULL AND $REVIEW_NOT_SQL" \
  || { echo "  ✖ 운영 DB 를 읽지 못했다 — 시험을 시작하지 않는다 (R-73 · db_read rc 3)"; exit 3; }
echo "  최근 10분 비-qa 감사 행: $NONQA_BEFORE (읽기 경로 ${DB_READ_VIA})"
if [ "${NONQA_BEFORE:-1}" != "0" ]; then
  echo "  ✖ 사용자가 지금 서비스를 쓰고 있다 — 시험을 시작하지 않는다 (종료 3)"
  exit 3
fi
echo "  검토 계정: $EMP · $ADM (이 둘만 쓴다)"
echo "  시작 상태: $EMP mfa_enabled=$(mfa_on "$EMP") · $ADM mfa_enabled=$(mfa_on "$ADM")"

# ─────────────────────────────────────────────────────────────
hr "(a) 단위 — Auth::nextStep · mfaRequired · mfaRecommended (PRD T-64 (a))"
# ─────────────────────────────────────────────────────────────
# Auth.php 한 파일만 require 한다 — 세 함수는 순수 함수라 DB·Http 를 부르지 않는다.
php -r '
require $argv[1];
$rows = [];
foreach (["Admin", "Manager", "Employee", "Guest"] as $r) {
    foreach ([true, false] as $on) {
        $u = ["role" => $r, "mfa_enabled" => $on, "must_change_password" => false];
        $rows[] = [$r . "/" . ($on ? "on" : "off"),
                   Auth::nextStep($u) ?? "null",
                   Auth::mfaRequired($u) ? "true" : "false",
                   Auth::mfaRecommended($u) ? "true" : "false"];
    }
}
// 「역할로 보기」 — 흉내 역할(role)이 아니라 실제 역할(real_role)로 판정해야 한다
$act = ["real_role" => "Admin", "role" => "Employee", "mfa_enabled" => false, "must_change_password" => false];
$rows[] = ["보는중 Admin→Employee/off", Auth::nextStep($act) ?? "null",
           Auth::mfaRequired($act) ? "true" : "false", Auth::mfaRecommended($act) ? "true" : "false"];
$emp = ["real_role" => "Employee", "role" => "Employee", "mfa_enabled" => false, "must_change_password" => false];
$rows[] = ["실제 Employee/off", Auth::nextStep($emp) ?? "null",
           Auth::mfaRequired($emp) ? "true" : "false", Auth::mfaRecommended($emp) ? "true" : "false"];
// 비밀번호 변경이 먼저 — 역할과 무관
foreach (["Admin", "Guest"] as $r) {
    $p = ["role" => $r, "mfa_enabled" => false, "must_change_password" => true];
    $rows[] = ["$r/off+비번변경", Auth::nextStep($p) ?? "null",
               Auth::mfaRequired($p) ? "true" : "false", Auth::mfaRecommended($p) ? "true" : "false"];
}
foreach ($rows as $row) { echo implode("|", $row), "\n"; }
echo "ROLES=", implode(",", Auth::MFA_REQUIRED_ROLES), "\n";
' "$ROOT/src/Auth.php" > "$OUT/a-unit.txt" 2>&1
sed 's/^/    /' "$OUT/a-unit.txt"

ua() { sed -n "s/^$1|//p" "$OUT/a-unit.txt"; }
expect "필수 역할 목록이 Admin 하나" "ROLES=Admin" "$(grep '^ROLES=' "$OUT/a-unit.txt")"
expect "Admin/off    → mfa_setup·필수·권장아님"  "mfa_setup|true|false"  "$(ua 'Admin\/off')"
expect "Admin/on     → null·필수·권장아님"       "null|true|false"       "$(ua 'Admin\/on')"
expect "Manager/off  → null·필수아님·권장"       "null|false|true"       "$(ua 'Manager\/off')"
expect "Manager/on   → null·필수아님·권장아님"   "null|false|false"      "$(ua 'Manager\/on')"
expect "Employee/off → null·필수아님·권장"       "null|false|true"       "$(ua 'Employee\/off')"
expect "Employee/on  → null·필수아님·권장아님"   "null|false|false"      "$(ua 'Employee\/on')"
expect "Guest/off    → null·필수아님·권장"       "null|false|true"       "$(ua 'Guest\/off')"
expect "Guest/on     → null·필수아님·권장아님"   "null|false|false"      "$(ua 'Guest\/on')"
expect "역할로 보기 중에도 실제 역할로 판정"      "mfa_setup|true|false"  "$(ua '보는중 Admin→Employee\/off')"
expect "실제 Employee 는 막지 않고 권장"          "null|false|true"       "$(ua '실제 Employee\/off')"
expect "비밀번호 변경이 먼저 (Admin)"             "password_change|true|false"  "$(ua 'Admin\/off+비번변경')"
expect "비밀번호 변경이 먼저 (Guest)"             "password_change|false|true"  "$(ua 'Guest\/off+비번변경')"

# ─────────────────────────────────────────────────────────────
hr "(b)·(d) 직원 — $EMP (필수가 아닌 역할)"
# ─────────────────────────────────────────────────────────────
EJAR="$JAR_DIR/$EMP.jar"
ETOK=$(login "$EMP") || { echo "  ✖ $EMP 로그인 실패 — (b) 를 건너뛴다"; FAIL=$((FAIL+1)); ETOK=""; }

if [ -n "$ETOK" ]; then
  sub "① (d) 2단계 인증이 **켜진** 상태에서 코드 없이 로그인 → 401 mfa_required"
  PW=$(qa_password "$EMP")
  printf -v LBODY '{"username":"%s","password":"%s"}' "$EMP" "$PW"
  R=$(curl_json_secret LBODY -s -w '\n%{http_code}' -A "$UA" -H 'Content-Type: application/json' "$BASE/api/login")
  expect "켜진 상태 · 코드 없는 로그인 401" 401 "$(code_of "$R")"
  expect "본문 mfa_required true" "true" "$(body_of "$R" | jget mfa_required)"

  sub "② 끄기 — 현재 비밀번호 + 코드"
  R=$(disable_mfa "$EMP" "$ETOK")
  expect "disable 200" 200 "$(code_of "$R")"
  expect "DB mfa_enabled = f" "f" "$(mfa_on "$EMP")"
  expect "DB mfa_setup_required = t (플래그는 그대로 켜진다)" "t" \
    "$(psqlq "SELECT mfa_setup_required FROM users WHERE username='$EMP'")"

  sub "③ /api/me — 막히지 않고 권장 상태"
  ME=$(curl -s -b "$EJAR" -A "$UA" "$BASE/api/me")
  expect "next = null"            "null"  "$(printf '%s' "$ME" | jget next)"
  expect "mfa_required = false"   "false" "$(printf '%s' "$ME" | jget mfa_required)"
  expect "mfa_recommended = true" "true"  "$(printf '%s' "$ME" | jget mfa_recommended)"
  expect "mfa_enabled = false"    "false" "$(printf '%s' "$ME" | jget mfa_enabled)"

  sub "④ GET /api/files — 403 이 아니라 200"
  FC=$(curl -s -o /dev/null -w '%{http_code}' -b "$EJAR" -A "$UA" "$BASE/api/files")
  expect "/api/files 200" 200 "$FC"

  sub "⑤ (d) 끈 뒤 코드 **없이** 로그인 200 + 응답에 mfa_recommended"
  curl -s -o /dev/null -b "$EJAR" -A "$UA" -X POST -H "X-CSRF-Token: $ETOK" "$BASE/api/logout"
  printf -v LBODY '{"username":"%s","password":"%s"}' "$EMP" "$PW"
  R=$(curl_json_secret LBODY -s -w '\n%{http_code}' -c "$EJAR" -A "$UA" -H 'Content-Type: application/json' "$BASE/api/login")
  expect "코드 없는 로그인 200"          200     "$(code_of "$R")"
  expect "로그인 응답 next = null"       "null"  "$(body_of "$R" | jget next)"
  expect "로그인 응답 mfa_required false" "false" "$(body_of "$R" | jget mfa_required)"
  expect "로그인 응답 mfa_recommended true" "true" "$(body_of "$R" | jget mfa_recommended)"
  ETOK=$(curl -s -b "$EJAR" -A "$UA" "$BASE/api/me" | sed -n 's/.*"csrf_token":"\([0-9a-f]*\)".*/\1/p')
fi

# ─────────────────────────────────────────────────────────────
hr "(b) 화면 — 홈 권장 카드 · 「나중에」 · 「내 계정」 문구 (Playwright 390×844)"
# ─────────────────────────────────────────────────────────────
export PATH="$HOME/.local/bin:$PATH"

cat > "$OUT/b-screens.py" <<'PY'
import json, sys
base, ua, who, otp, shots = sys.argv[1:6]
pw = sys.stdin.readline().rstrip('\n')   # 비밀번호는 표준입력 첫 줄(py_secret) — 명령 인자에 싣지 않는다(R-62)
try:
    from playwright.sync_api import sync_playwright
except Exception as e:
    print(json.dumps({'PLAYWRIGHT_MISSING': str(e)})); sys.exit(0)

errs = []
bad = []          # 400 이상으로 끝난 요청 (status, url) — 무엇이 실패했는지 URL 로 가린다

def watch(page):
    page.on('console', lambda m: errs.append(m.text) if m.type == 'error' else None)
    page.on('pageerror', lambda e: errs.append('pageerror: ' + str(e)))
    page.on('response', lambda r: bad.append([r.status, r.url]) if r.status >= 400 else None)

def sign_in(page):
    page.goto(base + '/login', wait_until='networkidle')
    page.fill('#username', who)
    page.fill('#password', pw)
    page.click('#login-submit')
    page.wait_for_timeout(1200)
    if otp != '-' and page.is_visible('#otp'):
        page.fill('#otp', otp)
        page.wait_for_timeout(1500)   # 사용감 라운드 1 UX-9 — 여섯 자리에서 로그인 화면이 스스로 제출한다(옛 판은 아래 단추)
        if page.is_visible('#otp') and page.input_value('#otp') != '':
            page.click('#login-submit')
        page.wait_for_timeout(1500)

look = """() => {
  const box = document.getElementById('mfa-recommend');
  const cta = box ? box.querySelector('a.btn') : null;
  const later = document.getElementById('mfa-recommend-later');
  const gate = document.getElementById('forced-gate');
  return {
    exists: !!box,
    visible: box ? !box.hidden : false,
    cta_href: cta ? cta.getAttribute('href') : null,
    cta_text: cta ? cta.textContent.trim() : null,
    later_text: later ? later.textContent.trim() : null,
    text: box ? box.textContent.replace(/\\s+/g, ' ').trim() : null,
    gate_visible: gate ? !gate.hidden : false
  };
}"""

report = {}
with sync_playwright() as p:
    b = p.chromium.launch(channel='chromium')

    # ① 첫 세션 — 카드가 보인다
    ctx = b.new_context(viewport={'width': 390, 'height': 844}, user_agent=ua,
                        is_mobile=True, has_touch=True)
    page = ctx.new_page()
    watch(page)
    sign_in(page)
    page.goto(base + '/', wait_until='networkidle')
    page.wait_for_timeout(1200)
    report['first'] = page.evaluate(look)
    page.screenshot(path=shots + '/b-card-shown.png', full_page=True)

    # ② 「나중에」 → 숨김
    page.click('#mfa-recommend-later')
    page.wait_for_timeout(400)
    report['after_later'] = page.evaluate(look)
    page.screenshot(path=shots + '/b-card-hidden.png', full_page=True)

    # ③ 같은 세션에서 새로고침 — 여전히 숨김
    page.reload(wait_until='networkidle')
    page.wait_for_timeout(1200)
    report['after_reload'] = page.evaluate(look)
    report['session_flag'] = page.evaluate("() => { try { return window.sessionStorage.getItem('tbox-mfa-later'); } catch (e) { return 'ERR'; } }")

    # ④ 「내 계정」 상태 문구
    page.goto(base + '/settings', wait_until='networkidle')
    page.wait_for_timeout(1000)
    report['settings_state'] = page.evaluate("() => { const s = document.getElementById('mfa-state'); return s ? s.textContent.trim() : null; }")
    ctx.close()

    # ⑤ **새 브라우저 컨텍스트**(새 세션) — 다시 보인다
    ctx2 = b.new_context(viewport={'width': 390, 'height': 844}, user_agent=ua,
                         is_mobile=True, has_touch=True)
    page2 = ctx2.new_page()
    watch(page2)
    sign_in(page2)
    page2.goto(base + '/', wait_until='networkidle')
    page2.wait_for_timeout(1200)
    report['new_context'] = page2.evaluate(look)
    ctx2.close()

    # 파비콘(/favicon.ico)은 이 저장소에 원래 없다 — F-52 와 무관한 기존 404 라 갈라 센다.
    report['failed_requests'] = bad
    report['failed_requests_other'] = [x for x in bad if not x[1].endswith('/favicon.ico')]
    report['console_errors'] = errs
    report['console_errors_other'] = [e for e in errs if 'Failed to load resource' not in e]
    b.close()
print(json.dumps(report, ensure_ascii=False, indent=2))
PY

SKIP_B_SCREENS=0
if [ -n "${ETOK:-}" ]; then
  EMP_PW=$(qa_password "$EMP")
  py_secret EMP_PW -- python3 "$OUT/b-screens.py" "$BASE" "$UA" "$EMP" "-" "$OUT/shots" > "$OUT/b-screens.json" 2>&1
  sed 's/^/    /' "$OUT/b-screens.json"
  if grep -q PLAYWRIGHT_MISSING "$OUT/b-screens.json"; then
    echo "  ! playwright 가 없어 (b) 화면을 실행하지 못했다 — 미검증"
    SKIP_B_SCREENS=1
  else
    bq() { python3 -c "
import json,sys
d=json.load(open('$OUT/b-screens.json'))
v=d
for k in sys.argv[1:]:
    v=v[k]
print(int(v) if isinstance(v,bool) else ('null' if v is None else v))" "$@"; }
    expect "홈에 권장 카드가 있다"              1 "$(bq first exists)"
    expect "권장 카드가 보인다"                 1 "$(bq first visible)"
    expect "강제 상자는 열리지 않는다"          0 "$(bq first gate_visible)"
    expect "「지금 켜기」 href = /settings"  "/settings" "$(bq first cta_href)"
    expect "「지금 켜기」 글자"              "지금 켜기" "$(bq first cta_text)"
    expect "「나중에」 글자"                  "나중에"   "$(bq first later_text)"
    expect "「나중에」 누르면 숨는다"           0 "$(bq after_later visible)"
    expect "새로고침해도 숨어 있다"             0 "$(bq after_reload visible)"
    expect "sessionStorage 에만 남는다"       "1" "$(bq session_flag)"
    expect "새 세션에서는 다시 보인다"          1 "$(bq new_context visible)"
    STATE=$(bq settings_state)
    echo "    「내 계정」 상태 문구: $STATE"
    expect "「내 계정」 문구에 「권장」" 1 "$(printf '%s' "$STATE" | grep -c '권장')"
    expect "「내 계정」 문구에 「필수」 없음" 0 "$(printf '%s' "$STATE" | grep -c '필수')"
    expect "파비콘 밖 실패 요청 0건" 0 "$(python3 -c "
import json; print(len(json.load(open('$OUT/b-screens.json'))['failed_requests_other']))")"
    expect "자원 적재 밖 콘솔 오류 0건" 0 "$(python3 -c "
import json; print(len(json.load(open('$OUT/b-screens.json'))['console_errors_other']))")"
    echo "  (/favicon.ico 404 는 이 저장소에 파비콘 파일이 없어 전부터 나던 것으로, F-52 와 무관하다)"
  fi
else
  echo "  ! $EMP 로그인이 없어 화면 시험을 건너뛴다 — 미검증"
  SKIP_B_SCREENS=1
fi

# ─────────────────────────────────────────────────────────────
hr "(b) 마무리 — $EMP 의 2단계 인증을 **다시 켠다**"
# ─────────────────────────────────────────────────────────────
reenable_mfa "$EMP"
expect "$EMP mfa_enabled = t" "t" "$(mfa_on "$EMP")"
ETOK=$(login "$EMP") || ETOK=""
if [ -n "$ETOK" ]; then
  ME=$(curl -s -b "$EJAR" -A "$UA" "$BASE/api/me")
  expect "다시 켠 뒤 mfa_recommended = false" "false" "$(printf '%s' "$ME" | jget mfa_recommended)"
  expect "다시 켠 뒤 next = null"             "null"  "$(printf '%s' "$ME" | jget next)"
fi

cat > "$OUT/b-after.py" <<'PY'
import json, sys
base, ua, who, otp, shots = sys.argv[1:6]
pw = sys.stdin.readline().rstrip('\n')   # 비밀번호는 표준입력 첫 줄(py_secret) — 명령 인자에 싣지 않는다(R-62)
try:
    from playwright.sync_api import sync_playwright
except Exception as e:
    print(json.dumps({'PLAYWRIGHT_MISSING': str(e)})); sys.exit(0)
with sync_playwright() as p:
    b = p.chromium.launch(channel='chromium')
    ctx = b.new_context(viewport={'width': 390, 'height': 844}, user_agent=ua,
                        is_mobile=True, has_touch=True)
    page = ctx.new_page()
    page.goto(base + '/login', wait_until='networkidle')
    page.fill('#username', who)
    page.fill('#password', pw)
    page.click('#login-submit')
    page.wait_for_timeout(1200)
    if page.is_visible('#otp'):
        page.fill('#otp', otp)
        page.wait_for_timeout(1500)   # 사용감 라운드 1 UX-9 — 여섯 자리에서 로그인 화면이 스스로 제출한다(옛 판은 아래 단추)
        if page.is_visible('#otp') and page.input_value('#otp') != '':
            page.click('#login-submit')
        page.wait_for_timeout(1800)
    page.goto(base + '/', wait_until='networkidle')
    page.wait_for_timeout(1200)
    out = page.evaluate("""() => {
      const box = document.getElementById('mfa-recommend');
      return { url: location.pathname, visible: box ? !box.hidden : false };
    }""")
    page.screenshot(path=shots + '/b-card-none-after-enable.png', full_page=True)
    print(json.dumps(out, ensure_ascii=False, indent=2))
    b.close()
PY

if [ "$SKIP_B_SCREENS" = "0" ]; then
  sub "켠 뒤 홈에 카드가 없다 (Playwright)"
  EMP_SEC=$(qa_secret "$EMP")
  ACODE=$(next_code "$EMP_SEC")
  EMP_PW=$(qa_password "$EMP")
  py_secret EMP_PW -- python3 "$OUT/b-after.py" "$BASE" "$UA" "$EMP" "$ACODE" "$OUT/shots" > "$OUT/b-after.json" 2>&1
  sed 's/^/    /' "$OUT/b-after.json"
  AV=$(python3 -c "
import json
d=json.load(open('$OUT/b-after.json'))
print('%s|%d' % (d.get('url'), 1 if d.get('visible') else 0))" 2>/dev/null)
  expect "켠 뒤 홈이 열리고 카드는 없다" "/|0" "$AV"
fi

# ─────────────────────────────────────────────────────────────
hr "(c) 관리자 — $ADM (필수 역할)"
# ─────────────────────────────────────────────────────────────
AJAR="$JAR_DIR/$ADM.jar"
C_T0=$(psqlq "SELECT now();")
ATOK=$(login "$ADM") || { echo "  ✖ $ADM 로그인 실패 — (c) 를 건너뛴다"; FAIL=$((FAIL+1)); ATOK=""; }

if [ -n "$ATOK" ]; then
  sub "① 끄기"
  R=$(disable_mfa "$ADM" "$ATOK")
  expect "disable 200" 200 "$(code_of "$R")"
  expect "DB mfa_enabled = f" "f" "$(mfa_on "$ADM")"

  sub "② /api/me — 관문이 걸린다"
  ME=$(curl -s -b "$AJAR" -A "$UA" "$BASE/api/me")
  expect "next = mfa_setup"         "mfa_setup" "$(printf '%s' "$ME" | jget next)"
  expect "mfa_required = true"      "true"      "$(printf '%s' "$ME" | jget mfa_required)"
  expect "mfa_recommended = false"  "false"     "$(printf '%s' "$ME" | jget mfa_recommended)"

  sub "③ GET /api/files — 403 + next:mfa_setup"
  R=$(curl -s -w '\n%{http_code}' -b "$AJAR" -A "$UA" "$BASE/api/files")
  expect "/api/files 403" 403 "$(code_of "$R")"
  expect "본문 next = mfa_setup" "mfa_setup" "$(body_of "$R" | jget next)"

  sub "④ 감사 로그 — auth.gate deny mfa_setup_required"
  GATE=$(psqlq "SELECT count(*) FROM audit_logs WHERE happened_at > '$C_T0'::timestamptz AND action='auth.gate' AND result='deny' AND reason='mfa_setup_required' AND actor_name='$ADM'")
  expect "auth.gate deny mfa_setup_required 1건 이상" 1 "$([ "${GATE:-0}" -ge 1 ] && echo 1 || echo 0)"
  psqlq "SELECT happened_at, actor_name, action, result, reason, summary FROM audit_logs WHERE happened_at > '$C_T0'::timestamptz AND action='auth.gate' AND actor_name='$ADM' ORDER BY id DESC LIMIT 3" | sed 's/^/    /'
fi

# ─────────────────────────────────────────────────────────────
hr "(c) 화면 — 홈에 강제 상자, 권장 카드는 없다 (Playwright 390×844)"
# ─────────────────────────────────────────────────────────────
cat > "$OUT/c-screens.py" <<'PY'
import json, sys
base, ua, who, shots = sys.argv[1:5]
pw = sys.stdin.readline().rstrip('\n')   # 비밀번호는 표준입력 첫 줄(py_secret) — 명령 인자에 싣지 않는다(R-62)
try:
    from playwright.sync_api import sync_playwright
except Exception as e:
    print(json.dumps({'PLAYWRIGHT_MISSING': str(e)})); sys.exit(0)
errs = []
bad = []
with sync_playwright() as p:
    b = p.chromium.launch(channel='chromium')
    ctx = b.new_context(viewport={'width': 390, 'height': 844}, user_agent=ua,
                        is_mobile=True, has_touch=True)
    page = ctx.new_page()
    page.on('console', lambda m: errs.append(m.text) if m.type == 'error' else None)
    page.on('pageerror', lambda e: errs.append('pageerror: ' + str(e)))
    page.on('response', lambda r: bad.append([r.status, r.url]) if r.status >= 400 else None)
    page.goto(base + '/login', wait_until='networkidle')
    page.fill('#username', who)
    page.fill('#password', pw)
    page.click('#login-submit')
    page.wait_for_timeout(1800)
    page.goto(base + '/', wait_until='networkidle')
    page.wait_for_timeout(1500)
    out = page.evaluate("""() => {
      const gate = document.getElementById('forced-gate');
      const mfa = document.getElementById('forced-mfa');
      const box = document.getElementById('mfa-recommend');
      return {
        gate_visible: gate ? !gate.hidden : false,
        forced_mfa_visible: mfa ? !mfa.hidden : false,
        recommend_visible: box ? !box.hidden : false,
        forced_mfa_text: mfa ? mfa.textContent.replace(/\s+/g, ' ').trim().slice(0, 120) : null
      };
    }""")
    page.screenshot(path=shots + '/c-forced-gate.png', full_page=True)
    out['failed_requests'] = bad
    out['failed_requests_other'] = [x for x in bad if not x[1].endswith('/favicon.ico')]
    out['console_errors'] = errs
    out['console_errors_other'] = [e for e in errs if 'Failed to load resource' not in e]
    print(json.dumps(out, ensure_ascii=False, indent=2))
    b.close()
PY

if [ -n "${ATOK:-}" ] && [ "$SKIP_B_SCREENS" = "0" ]; then
  ADM_PW=$(qa_password "$ADM")
  py_secret ADM_PW -- python3 "$OUT/c-screens.py" "$BASE" "$UA" "$ADM" "$OUT/shots" > "$OUT/c-screens.json" 2>&1
  sed 's/^/    /' "$OUT/c-screens.json"
  cq() { python3 -c "
import json,sys
d=json.load(open('$OUT/c-screens.json'))
v=d[sys.argv[1]]
print(int(v) if isinstance(v,bool) else ('null' if v is None else v))" "$1"; }
  expect "강제 상자가 열린다"            1 "$(cq gate_visible)"
  expect "2단계 인증 걸음이 보인다"      1 "$(cq forced_mfa_visible)"
  expect "권장 카드는 보이지 않는다"     0 "$(cq recommend_visible)"
  CSTATE=$(cq forced_mfa_text)
  echo "    강제 상자의 2단계 인증 문구: $CSTATE"
  expect "강제 문구에 「필수」" 1 "$(printf '%s' "$CSTATE" | grep -c '필수')"
  expect "파비콘 밖 실패 요청 0건" 0 "$(python3 -c "
import json; print(len(json.load(open('$OUT/c-screens.json'))['failed_requests_other']))")"
  expect "자원 적재 밖 콘솔 오류 0건" 0 "$(python3 -c "
import json; print(len(json.load(open('$OUT/c-screens.json'))['console_errors_other']))")"
  echo "  (/favicon.ico 404 는 F-52 와 무관한 기존 상태다)"
else
  echo "  ! (c) 화면을 건너뛴다 — 미검증"
fi

# ─────────────────────────────────────────────────────────────
hr "(c) 마무리 — $ADM 의 2단계 인증을 **다시 켠다**"
# ─────────────────────────────────────────────────────────────
reenable_mfa "$ADM"
expect "$ADM mfa_enabled = t" "t" "$(mfa_on "$ADM")"
ATOK=$(login "$ADM") || ATOK=""
if [ -n "$ATOK" ]; then
  ME=$(curl -s -b "$AJAR" -A "$UA" "$BASE/api/me")
  expect "다시 켠 뒤 next = null"       "null" "$(printf '%s' "$ME" | jget next)"
  expect "다시 켠 뒤 mfa_required true" "true" "$(printf '%s' "$ME" | jget mfa_required)"
  FC=$(curl -s -o /dev/null -w '%{http_code}' -b "$AJAR" -A "$UA" "$BASE/api/files")
  expect "다시 켠 뒤 /api/files 200" 200 "$FC"
fi

# ─────────────────────────────────────────────────────────────
hr "(e) 문구 — 「모든 역할」 0건 (화면·도움말·README·위키)"
# ─────────────────────────────────────────────────────────────
cd "$ROOT"
HITS=$(grep -rn "모든 역할" src public README.md README.md wiki | tee "$OUT/e-grep.txt" | wc -l)
[ "$HITS" = "0" ] || sed 's/^/    /' "$OUT/e-grep.txt"
expect "「모든 역할」 0건" 0 "$HITS"
echo "  (과거 기록 STAGES·SECURITY 7장·CHANGELOG 는 검사 대상이 아니다)"

# ─────────────────────────────────────────────────────────────
hr "(f) 정적 검사 — 린트 · php -l · wiki JSON · 감사 · 마무리 상태"
# ─────────────────────────────────────────────────────────────
if bash "$ROOT/scripts/security_lint.sh" > "$OUT/f-lint.txt" 2>&1; then
  tail -2 "$OUT/f-lint.txt" | sed 's/^/    /'
  expect "린트 0건" 0 0
else
  cat "$OUT/f-lint.txt"; expect "린트 0건" 0 1
fi

LINT_FAIL=0
for f in src/Auth.php src/handlers/login.php src/handlers/me.php src/handlers/mfa_confirm.php \
         src/handlers/mfa_disable.php src/handlers/users_mfa_reset.php \
         src/handlers/home.php src/handlers/settings_page.php src/help/screens.php; do
  php -l "$ROOT/$f" > /dev/null 2>&1 || { php -l "$ROOT/$f"; LINT_FAIL=$((LINT_FAIL+1)); }
done
expect "php -l 실패 0" 0 "$LINT_FAIL"

JSON_FAIL=0
# 2026-09-23 — 위키가 책 셋으로 나뉘어 t-box 글은 `wiki/tbox/` 로 옮겼다(git mv, 내용 무변경).
for f in wiki/tbox/guide-risks.json wiki/tbox/guide-what.json wiki/tbox/stage-11.json wiki/tbox/stage-02.json; do
  python3 -m json.tool "$ROOT/$f" > /dev/null 2>&1 || { echo "    ✖ $f 파싱 실패"; JSON_FAIL=$((JSON_FAIL+1)); }
done
expect "wiki JSON 4파일 파싱 실패 0" 0 "$JSON_FAIL"

JSBAD=$(cat "$ROOT/public/assets/app.js" "$ROOT/public/assets/files-page.js" | grep -cE 'innerHTML|outerHTML|document\.write'); JSBAD=${JSBAD:-0}
expect "app.js·files-page.js 에 innerHTML·document.write 0건" 0 "$JSBAD"

NONQA_AFTER=$(db_read "$DB" "SELECT count(*) FROM audit_logs WHERE happened_at > '$T0'::timestamptz AND actor_name IS NOT NULL AND $REVIEW_NOT_SQL")
echo "  이 실행 중 비-qa 감사 행: $NONQA_AFTER (0 이어야 정상)"
expect "비-qa 감사 행 0" 0 "$NONQA_AFTER"

expect "끝난 뒤 $EMP 의 2단계 인증이 켜져 있다" "t" "$(mfa_on "$EMP")"
expect "끝난 뒤 $ADM 의 2단계 인증이 켜져 있다" "t" "$(mfa_on "$ADM")"

rm -rf "$JAR_DIR"

# ─────────────────────────────────────────────────────────────
hr "결과"
# ─────────────────────────────────────────────────────────────
printf '  통과 %d · 실패 %d\n' "$PASS" "$FAIL"
result_line "$PASS" "$FAIL" 0
printf '  스크린샷·출력: %s\n' "$OUT"
[ "$FAIL" -eq 0 ] || exit 1
