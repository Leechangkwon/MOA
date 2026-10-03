#!/usr/bin/env bash
# tests/f51.sh — F-51 수락 기준 실행 (PRD 12.2 T-63)
#   (a) 오라클 대조 — 문자열 6종 × 마스크 0~7 = 48, 파이썬 qrcode 행렬과 비트 단위 일치
#   (b) 응답      — enroll 응답의 qr 모양 · 기존 네 필드 불변 · otpauth 재인코딩 일치 · confirm 으로 원상복구
#   (c) 비노출    — no-store · 감사 로그 · app.log · Qr.php 의 금지 패턴
#   (d) 화면      — Playwright 390×844 · 1440×900, /settings 슬롯과 강제 절차 슬롯 (스크린샷 4장)
#   (e) 폴백      — 214바이트 → RuntimeException, 핸들러 qr:null 갈래(코드 읽기)
#   (f) 린트 · php -l
#
# 규칙 (CLAUDE.md 3장)
#   · **검토 계정은 claude_employee01 하나만** 쓴다. 시드 계정(admin01 …)은 사용자의 자기 공격 전용이다.
#   · 모든 요청에 `-A "tbox-review/<날짜>"` 를 붙인다.
#   · 비밀번호·TOTP 비밀은 **변수에만** 담는다 — 화면·파일에 찍지 않는다 (SECURITY R-62).
#     명령 인자에도 싣지 않는다 — 표준입력·0600 임시 파일로만 넘긴다(tests/lib/secrets.sh · 점검 봇 R15 · C①).
#
# ★ 시험 안전 (SECURITY R-73)
#   ① 최근 10분 감사 로그에 비-qa actor 가 하나라도 있으면 **시작하지 않는다**(종료 코드 3).
#   ② 이 시험은 claude_employee01 의 **2단계 인증을 껐다 켠다**. 끌 때마다 비밀이 새로 만들어지므로
#      마지막에 반드시 confirm 으로 다시 켜고 `/etc/tbox-v2/qa-totp.txt` 를 새 값으로 갱신한다
#      (다른 시험이 이 파일로 로그인한다). 다른 계정·파일·표에는 손대지 않는다.
#      ★ 운영 파일에 쓰는 것은 **대상이 운영일 때만**(BASE 운영 검토 주소 · DB tbox_re — tests/lib/prod_guard.sh).
#        회귀 틀이 BASE·DB 를 임시로 바꿔 돌리면 새 비밀은 이 실행의 메모리에만 두고(또는 QA_TOTP_FILE 로 준 자리에) 운영 파일은
#        건드리지 않는다. 대상을 가를 수 없으면 시작하지 않는다. 쓰기 직전에 BASE·DB·운영 .env 의 DB_NAME 을 다시 본다
#        (2026-09-26 13:52 사고 — 임시 대상 실행이 운영 qa-totp.txt 를 덮어씀 · 디버깅 묶음 T 9번).
#   ③ 바깥으로 나가는 요청이 없다. 파이썬 `qrcode` 는 **시험 전용**이고 앱은 부르지 않는다.
#
# 준비물: sudo(/etc/tbox-v2 읽기·쓰기 · psql), php, curl, python3(+qrcode, +playwright)
#
# 사용: sudo -v && bash tests/f51.sh [출력 폴더]
#   출력 폴더 기본값은 ~/.claude/ui-shots/f51 (루트 디스크를 쓰지 않는다).
set -u

BASE="http://127.0.0.1:8082/tbox"
UA="tbox-review/$(date +%F)"
DB="tbox_re"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
# 검토 계정 판정 한 벌(qa_·claude_ — F-71 C). 못 읽으면 사전 검사가 빈 값으로 열리므로 시작하지 않는다.
. "$ROOT/tests/lib/review_accounts.sh" || { echo "tests/lib/review_accounts.sh 를 읽지 못했다 — 시험을 시작하지 않는다 (R-73)"; exit 3; }
. "$ROOT/tests/lib/secrets.sh" || { echo "tests/lib/secrets.sh 를 읽지 못했다 — 시험을 시작하지 않는다 (R-62)"; exit 3; }
. "$ROOT/tests/lib/db_read.sh" || { echo "tests/lib/db_read.sh 를 읽지 못했다 — 시험을 시작하지 않는다 (R-73)"; exit 3; }
OUT="${1:-$HOME/.claude/ui-shots/f51}"
JAR_DIR="$(mktemp -d)"
# JAR_DIR(0700)은 쿠키 jar 와 secret_file 의 0600 임시 파일 폴더다 — 어떻게 끝나도 지운다(trap 에 exit 가 없어 종료 코드는 그대로)
trap 'rm -rf "$JAR_DIR"' EXIT
PASS=0
FAIL=0

WHO="claude_employee01"
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
MEM_SECRET=""   # 임시 대상에서 새 비밀(값은 찍지 않는다)

mkdir -p "$OUT" "$OUT/a" "$OUT/d-shots"

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

# ── 비밀 다루기 (전부 변수에만 — 화면에 찍지 않는다) ─────────────
qa_secret()   { if [ -n "$MEM_SECRET" ]; then printf '%s\n' "$MEM_SECRET"; else sudo -n awk -v u="$WHO" '$1==u{print $2}' "$TOTP_FILE"; fi; }
qa_password() { sudo -n awk -v u="$WHO" '$1==u{print $3}' /etc/tbox-v2/seed-accounts.txt; }

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
  printf '%s' "$raw" | head -1
}

# 다음 창의 코드 — TOTP 코드는 1회성(R-45)이라 같은 창에서 두 번 쓰면 거부되는 것이 **정상**이다
next_code() {
  local raw left s1="$1"
  raw=$(totp_now s1)
  left=$(printf '%s' "$raw" | sed -n 's/^남은 시간: \([0-9]*\)초.*/\1/p')
  [ -n "$left" ] || left=30
  printf '  · 다음 코드 창까지 %s초 기다림 (코드 1회성 R-45)\n' "$left" >&2
  sleep $((left + 1))
  totp_now s1 | head -1
}

# /etc/tbox-v2/qa-totp.txt 의 claude_employee01 비밀을 새 값으로 (다른 시험이 이 파일로 로그인한다)
#   $1 = 새 비밀이 든 **변수 이름**(값이 아니다). 값은 py_secret 이 표준입력으로만 sudo awk 에 준다 —
#   `awk -v s=값` 이면 ps 와 auth.log 의 COMMAND= 에 새 비밀이 남는다(점검 봇 C① · R-62).
#   빈 값·줄바꿈(py_secret rc 90)·base32 가 아닌 값(awk rc 2)·대상 계정 줄이 1개가 아님(awk rc 3)이면
#   install 을 부르지 않는다 — 빈 값으로 qa-totp.txt 를 덮어쓰는 사고를 막는다.
save_secret() {
  local tmp v
  if [ "$TOTP_SAVE" = memory ]; then
    v=${!1-}
    if [[ "$v" =~ ^[A-Z2-7]{16,64}$ ]]; then
      MEM_SECRET=$v
      echo "  · 대상이 임시(DB $DB)다 — 새 비밀은 이 실행의 메모리에만 둔다(운영 $PROD_TOTP_FILE 쓰기 0)"
      return 0
    fi
    echo "  ! 새 비밀 모양이 아니다 — 메모리에도 두지 않았다"
    return 1
  fi
  # 운영 자리면 쓰기 **직전에** 다시 확인 — 어긋나면 멈춘다(rc 3)
  prod_write_guard "$TOTP_FILE" || { echo "  ! 운영 파일 쓰기 가드가 막았다 — 시험을 멈춘다"; exit 3; }
  tmp=$(mktemp)
  if py_secret "$1" -- sudo -n awk -v u="$WHO" '
        BEGIN { if ((getline s < "/dev/stdin") <= 0 || s !~ /^[A-Z2-7]+$/) { bad = 1; exit 2 } }
        $1 == u { $2 = s; n++ } { print }
        END { if (bad) exit 2; if (n != 1) exit 3 }' "$TOTP_FILE" > "$tmp" \
     && if pg_is_prod_path "$TOTP_FILE"; then sudo -n install -o root -g root -m 600 "$tmp" "$TOTP_FILE"; else install -m 600 "$tmp" "$TOTP_FILE"; fi; then
    rm -f "$tmp"
    echo "  · $TOTP_FILE 의 $WHO 비밀을 새 값으로 갱신했다 (값은 찍지 않는다)"
    return 0
  fi
  rm -f "$tmp"
  echo "  ! $TOTP_FILE 갱신 실패 — 다음 시험이 로그인하지 못한다"
  return 1
}

# 로그인 (2단계 인증 켜진 상태) → csrf 토큰을 찍는다
login() {
  local jar="$JAR_DIR/$WHO.jar" pw sec code out try body
  pw=$(qa_password); sec=$(qa_secret)
  for try in 1 2 3; do
    code=$(now_code "$sec")
    printf -v body '{"username":"%s","password":"%s","otp":"%s"}' "$WHO" "$pw" "$code"
    out=$(curl_json_secret body -s -c "$jar" -A "$UA" -H 'Content-Type: application/json' "$BASE/api/login")
    case "$out" in
      *'"ok":true'*)
        curl -s -b "$jar" -A "$UA" "$BASE/api/me" \
          | sed -n 's/.*"csrf_token":"\([0-9a-f]*\)".*/\1/p'
        return 0 ;;
    esac
    code=$(next_code "$sec")
    printf -v body '{"username":"%s","password":"%s","otp":"%s"}' "$WHO" "$pw" "$code"
    out=$(curl_json_secret body -s -c "$jar" -A "$UA" -H 'Content-Type: application/json' "$BASE/api/login")
    case "$out" in
      *'"ok":true'*)
        curl -s -b "$jar" -A "$UA" "$BASE/api/me" \
          | sed -n 's/.*"csrf_token":"\([0-9a-f]*\)".*/\1/p'
        return 0 ;;
    esac
  done
  printf '  ! %s 로그인 실패: %s\n' "$WHO" "$out" >&2
  return 1
}

# 2단계 인증이 **꺼진**(등록 대기) 상태에서 비밀번호만으로 로그인 → csrf 토큰
login_nomfa() {
  local jar="$JAR_DIR/$WHO-nomfa.jar" pw out body
  pw=$(qa_password)
  printf -v body '{"username":"%s","password":"%s"}' "$WHO" "$pw"
  out=$(curl_json_secret body -s -c "$jar" -A "$UA" -H 'Content-Type: application/json' "$BASE/api/login")
  case "$out" in
    *'"ok":true'*)
      curl -s -b "$jar" -A "$UA" "$BASE/api/me" \
        | sed -n 's/.*"csrf_token":"\([0-9a-f]*\)".*/\1/p'
      return 0 ;;
  esac
  printf '  ! %s (2단계 인증 꺼짐) 로그인 실패: %s\n' "$WHO" "$out" >&2
  return 1
}

# 등록 대기 상태를 **다시 켠다** — enroll → confirm → qa-totp.txt 갱신 (강제 절차 관문 중에도 허용)
reenable_mfa() {
  local jar="$JAR_DIR/$WHO-nomfa.jar" tok body new code out
  tok=$(login_nomfa) || return 1
  body=$(curl -s -b "$jar" -A "$UA" -H 'Content-Type: application/json' \
         -H "X-CSRF-Token: $tok" -d '{}' "$BASE/api/me/mfa/enroll")
  new=$(printf '%s' "$body" | python3 -c 'import json,sys; print(json.load(sys.stdin)["secret"])' 2>/dev/null)
  if [ -z "${new:-}" ]; then
    echo "  ! 다시 켜기 실패 — enroll 응답에 secret 이 없다"
    return 1
  fi
  code=$(now_code "$new")
  out=$(curl -s -w '\n%{http_code}' -b "$jar" -A "$UA" -H 'Content-Type: application/json' \
        -H "X-CSRF-Token: $tok" -d "{\"otp\":\"$code\"}" "$BASE/api/me/mfa/confirm")
  if [ "$(code_of "$out")" != "200" ]; then
    echo "  ! confirm 실패: HTTP $(code_of "$out") $(body_of "$out")"
    return 1
  fi
  save_secret new || return 1
  echo "  · 2단계 인증을 다시 켰다 (HTTP 200)"
  return 0
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
echo "  검토 계정: $WHO (이 계정 하나만 쓴다)"

# ─────────────────────────────────────────────────────────────
hr "(a) 오라클 대조 — 문자열 6종 × 마스크 0~7 (파이썬 qrcode)"
# ─────────────────────────────────────────────────────────────
cat > "$OUT/a/oracle.py" <<'PY'
import sys, qrcode, qrcode.util as u
s, m = sys.argv[1], sys.argv[2]
kw = {} if m == 'auto' else {'mask_pattern': int(m)}
q = qrcode.QRCode(error_correction=qrcode.constants.ERROR_CORRECT_M, border=0, **kw)
q.add_data(u.QRData(s.encode('utf-8'), mode=u.MODE_8BIT_BYTE)); q.make(fit=True)
print(q.version); print("\n".join("".join("1" if c else "0" for c in row) for row in q.get_matrix()))
PY

# 시험용 비밀은 **이 시험 안에서** 만든 값이다 (실제 계정의 비밀이 아니다)
TSEC=$(php -r 'require $argv[1]; echo Totp::generateSecret();' "$ROOT/src/Totp.php")
LONGU=$(python3 -c 'print("a"*64)')
S1="A"
S2="HELLO WORLD"
S3=$(php -r 'require $argv[1]; echo Totp::otpauthUri("abc", $argv[2]);' "$ROOT/src/Totp.php" "$TSEC")
S4=$(php -r 'require $argv[1]; echo Totp::otpauthUri($argv[3], $argv[2]);' "$ROOT/src/Totp.php" "$TSEC" "$LONGU")
S5="한글 시험 문자열입니다"
S6='?&=%:/+#'

echo "  문자열 길이(바이트): S1=${#S1} S2=${#S2} S3=${#S3} S4=${#S4} S5=${#S5} S6=${#S6}"

php_qr() {  # $1 문자열  $2 마스크(숫자 또는 auto)
  if [ "$2" = "auto" ]; then
    php -r 'require $argv[1]; $r = Qr::encode($argv[2]); echo $r["version"], "\n", implode("\n", $r["rows"]), "\n";' "$ROOT/src/Qr.php" "$1"
  else
    php -r 'require $argv[1]; $r = Qr::encode($argv[2], (int) $argv[3]); echo $r["version"], "\n", implode("\n", $r["rows"]), "\n";' "$ROOT/src/Qr.php" "$1" "$2"
  fi
}

MATCH=0
TRIED=0
AUTO_OK=0
i=0
for S in "$S1" "$S2" "$S3" "$S4" "$S5" "$S6"; do
  i=$((i+1))
  LINE=""
  for m in 0 1 2 3 4 5 6 7; do
    TRIED=$((TRIED+1))
    php_qr "$S" "$m" > "$OUT/a/php-$i-$m.txt"
    python3 "$OUT/a/oracle.py" "$S" "$m" > "$OUT/a/py-$i-$m.txt" 2>&1
    if diff -q "$OUT/a/php-$i-$m.txt" "$OUT/a/py-$i-$m.txt" > /dev/null 2>&1; then
      MATCH=$((MATCH+1)); LINE="$LINE ✔$m"
    else
      LINE="$LINE ✖$m"
      FIRST=$(diff "$OUT/a/php-$i-$m.txt" "$OUT/a/py-$i-$m.txt" | head -1)
      printf '    ✖ 문자열%d 마스크%s — 첫 다른 곳: %s\n' "$i" "$m" "$FIRST"
    fi
  done
  V=$(head -1 "$OUT/a/php-$i-0.txt")
  printf '    문자열%d (버전 %s):%s\n' "$i" "$V" "$LINE"

  # 자동 마스크 — 같은 문자열의 8종 중 하나와 같아야 한다
  php_qr "$S" auto > "$OUT/a/php-$i-auto.txt"
  HIT="없음"
  for m in 0 1 2 3 4 5 6 7; do
    if diff -q "$OUT/a/php-$i-auto.txt" "$OUT/a/php-$i-$m.txt" > /dev/null 2>&1; then HIT="$m"; break; fi
  done
  [ "$HIT" != "없음" ] && AUTO_OK=$((AUTO_OK+1))
  python3 "$OUT/a/oracle.py" "$S" auto > "$OUT/a/py-$i-auto.txt" 2>&1
  PYHIT="없음"
  for m in 0 1 2 3 4 5 6 7; do
    if diff -q "$OUT/a/py-$i-auto.txt" "$OUT/a/py-$i-$m.txt" > /dev/null 2>&1; then PYHIT="$m"; break; fi
  done
  printf '      자동 마스크: PHP=%s · 파이썬=%s  (벌점 동률 처리가 달라도 실패가 아니다 — 정보)\n' "$HIT" "$PYHIT"
done

expect "오라클과 비트 단위 일치 48/48" 48 "$MATCH"
expect "대조한 짝의 수" 48 "$TRIED"
expect "자동 마스크가 8종 중 하나와 같음 (6/6)" 6 "$AUTO_OK"

# ─────────────────────────────────────────────────────────────
hr "(b) 응답 — disable → enroll → confirm (claude_employee01)"
# ─────────────────────────────────────────────────────────────
JAR="$JAR_DIR/$WHO.jar"
TOK=$(login) || { echo "  ✖ 로그인 실패 — 이후 시험을 건너뛴다"; FAIL=$((FAIL+1)); TOK=""; }

if [ -n "$TOK" ]; then
  PW=$(qa_password)
  SEC=$(qa_secret)
  sub "① 끄기 — 현재 비밀번호 + 코드 (로그인에 쓴 코드와 같은 창이면 거부되는 것이 정상)"
  DCODE=$(next_code "$SEC")
  printf -v DBODY '{"current_password":"%s","otp":"%s"}' "$PW" "$DCODE"
  R=$(curl_json_secret DBODY -s -w '\n%{http_code}' -b "$JAR" -A "$UA" -H 'Content-Type: application/json' \
      -H "X-CSRF-Token: $TOK" "$BASE/api/me/mfa/disable")
  expect "disable 200" 200 "$(code_of "$R")"

  sub "② 등록 — 응답에 qr 가 붙는다"
  EH="$OUT/b-enroll-headers.txt"
  R=$(curl -s -D "$EH" -w '\n%{http_code}' -b "$JAR" -A "$UA" -H 'Content-Type: application/json' \
      -H "X-CSRF-Token: $TOK" -d '{}' "$BASE/api/me/mfa/enroll")
  ECODE=$(code_of "$R")
  BODY=$(body_of "$R")
  expect "enroll 200" 200 "$ECODE"

  # 응답 원문은 **비밀을 담고 있어** 파일에도 화면에도 내보내지 않는다 — 검사 결과만 본다
  CHECK=$(printf '%s' "$BODY" | python3 -c '
import json, re, sys
d = json.load(sys.stdin)
qr = d.get("qr")
ok = {}
ok["qr_is_object"] = 1 if isinstance(qr, dict) else 0
if isinstance(qr, dict):
    v, s, rows = qr.get("version"), qr.get("size"), qr.get("rows")
    ok["version_1_10"] = 1 if isinstance(v, int) and 1 <= v <= 10 else 0
    ok["size_eq_17_4v"] = 1 if isinstance(s, int) and isinstance(v, int) and s == 17 + 4 * v else 0
    ok["rows_len_eq_size"] = 1 if isinstance(rows, list) and rows and len(rows) == s else 0
    ok["rows_shape"] = 1 if isinstance(rows, list) and all(isinstance(r, str) and len(r) == s and re.fullmatch("[01]+", r) for r in rows) else 0
    print("QRINFO version=%s size=%s rows=%s" % (v, s, len(rows) if isinstance(rows, list) else "?"))
ok["secret_32_base32"] = 1 if re.fullmatch("[A-Z2-7]{32}", d.get("secret", "")) else 0
ok["otpauth_prefix"] = 1 if str(d.get("otpauth", "")).startswith("otpauth://totp/") else 0
ok["digits_6"] = 1 if d.get("digits") == 6 else 0
ok["period_30"] = 1 if d.get("period") == 30 else 0
for k, v in ok.items():
    print("%s=%d" % (k, v))
')
  printf '%s\n' "$CHECK" | sed 's/^/    /'
  g() { printf '%s\n' "$CHECK" | sed -n "s/^$1=//p"; }
  expect "qr 가 객체"                    1 "$(g qr_is_object)"
  expect "qr.version 이 1~10"            1 "$(g version_1_10)"
  expect "qr.size = 17 + 4×version"      1 "$(g size_eq_17_4v)"
  expect "rows 줄 수 = size"             1 "$(g rows_len_eq_size)"
  expect "각 줄이 size 글자 '0'/'1' 만"  1 "$(g rows_shape)"
  expect "secret 이 base32 32자"         1 "$(g secret_32_base32)"
  expect "otpauth 가 otpauth://totp/ 로 시작" 1 "$(g otpauth_prefix)"
  expect "digits = 6"                    1 "$(g digits_6)"
  expect "period = 30"                   1 "$(g period_30)"

  sub "③ 응답의 otpauth 를 Qr::encode 에 다시 넣으면 rows 가 같다 (파이프로만 — 비밀을 파일에 쓰지 않는다)"
  SAME=$(printf '%s' "$BODY" | php -r '
$in = json_decode(stream_get_contents(STDIN), true);
require $argv[1];
$r = Qr::encode((string) $in["otpauth"]);
echo ($r["rows"] === $in["qr"]["rows"] && $r["version"] === $in["qr"]["version"] && $r["size"] === $in["qr"]["size"]) ? "SAME" : "DIFF";
' "$ROOT/src/Qr.php")
  expect "재인코딩 결과가 응답의 rows 와 같다" "SAME" "$SAME"

  sub "④ 확정 — 새 비밀로 만든 코드로 다시 켠다"
  NEW=$(printf '%s' "$BODY" | python3 -c 'import json,sys; print(json.load(sys.stdin)["secret"])')
  CCODE=$(now_code "$NEW")
  R=$(curl -s -w '\n%{http_code}' -b "$JAR" -A "$UA" -H 'Content-Type: application/json' \
      -H "X-CSRF-Token: $TOK" -d "{\"otp\":\"$CCODE\"}" "$BASE/api/me/mfa/confirm")
  expect "confirm 200" 200 "$(code_of "$R")"
  if [ "$(code_of "$R")" = "200" ]; then
    save_secret NEW
    expect "qa-totp.txt 갱신" 0 "$?"
  fi
  MFA_ON=$(psqlq "SELECT mfa_enabled FROM users WHERE username='$WHO'")
  expect "DB 의 mfa_enabled 가 다시 t" "t" "$MFA_ON"
fi

# ─────────────────────────────────────────────────────────────
hr "(c) 비노출 — no-store · 감사 로그 · app.log · Qr.php 의 금지 패턴"
# ─────────────────────────────────────────────────────────────
if [ -n "${TOK:-}" ]; then
  CC=$(sed -n 's/^[Cc]ache-[Cc]ontrol: *//p' "$OUT/b-enroll-headers.txt" | tr -d '\r')
  echo "    Cache-Control: $CC"
  expect "enroll 응답이 no-store" 1 "$(printf '%s' "$CC" | grep -c 'no-store')"

  # 새 비밀을 SQL 에 넣지 않는다 — `psql -c` 인자(sudo 기록)에 남는다(C①). detail 글을 읽어 **로컬에서** 센다.
  # 원래 정규식 '(rows|otpauth|NEW)' 는 고정 문자열 셋의 「또는」이고 NEW 는 base32(A-Z2-7)라 정규식 특수 문자가
  # 없다 — 그래서 `grep -cF -f <세 줄 파일>` 과 같다(jsonb::text 는 한 행이 한 줄이라 줄 수 = 행 수).
  # 읽기·세기가 실패하면 빈 값(0 으로 읽지 않는다) → 아래 expect 가 원래처럼 실패로 센다. grep 의 rc 1 은 「0건」이다.
  PATF=$(umask 077; mktemp "$JAR_DIR/.pat.XXXXXX") && printf '%s\n' rows otpauth "${NEW:-__no_secret__}" > "$PATF" || PATF=""
  AUD=""
  if [ -n "$PATF" ] && AUDTXT=$(psqlq "SELECT detail::text FROM audit_logs WHERE happened_at > '$T0'::timestamptz AND detail IS NOT NULL"); then
    AUD=$(printf '%s\n' "$AUDTXT" | grep -cF -f "$PATF"); [ "$?" -le 1 ] || AUD=""
  fi
  unset AUDTXT
  expect "시험 구간 감사 detail 에 rows·otpauth·비밀 0건" 0 "$AUD"

  if sudo -n test -r /var/log/tbox-v2/app.log; then
    # 새 비밀은 0600 파일(JAR_DIR 안)로 grep 에 준다 — `grep -c "$NEW"` 이면 누출을 확인하는 검사가 auth.log 에 비밀을 남긴다(C①).
    # 새 비밀이 비어 파일을 만들지 못하면 검증할 수 없으므로 통과시키지 않는다(원래도 빈 패턴은 모든 줄을 세어 실패였다).
    if NEWF=$(secret_file NEW "$JAR_DIR"); then
      LOGHIT=$(sudo -n grep -c -F -f "$NEWF" /var/log/tbox-v2/app.log 2>/dev/null); LOGHIT=${LOGHIT:-0}
    else
      LOGHIT="미검증(새 비밀 없음)"
    fi
    expect "app.log 에 비밀 0건" 0 "$LOGHIT"
    LOGHIT2=$(sudo -n grep -c 'otpauth' /var/log/tbox-v2/app.log 2>/dev/null); LOGHIT2=${LOGHIT2:-0}
    expect "app.log 에 otpauth 0건" 0 "$LOGHIT2"
  else
    echo "  ! /var/log/tbox-v2/app.log 를 읽지 못했다 — 미검증"
  fi
fi

QRBAD=$(grep -cE 'curl_|file_get_contents|fopen\(|imagecreate|exec\(|require|include' "$ROOT/src/Qr.php"); QRBAD=${QRBAD:-0}
expect "src/Qr.php 에 외부 요청·이미지·코드 실행·외부 코드 끌어오기 0건" 0 "$QRBAD"
QRUSE=$(grep -cE '^\s*use\s+' "$ROOT/src/Qr.php"); QRUSE=${QRUSE:-0}
expect "src/Qr.php 에 use 로 끌어오는 것 0건" 0 "$QRUSE"
JSBAD=$(grep -cE 'innerHTML|outerHTML|document\.write|data:image' "$ROOT/public/assets/app.js"); JSBAD=${JSBAD:-0}
expect "app.js 에 innerHTML·document.write·data:image 0건" 0 "$JSBAD"

# ─────────────────────────────────────────────────────────────
hr "(d) 화면 — /settings 슬롯 · 강제 절차 슬롯 (Playwright)"
# ─────────────────────────────────────────────────────────────
export PATH="$HOME/.local/bin:$PATH"

# 로그인만 하는 단계와 화면 단계를 나눈다 — 끄기에 쓸 코드가 **다음 창**이어야 하기 때문이다.
cat > "$OUT/d-login.py" <<'PY'
import sys
base, ua, who, code, state, width, height = sys.argv[1:8]
pw = sys.stdin.readline().rstrip('\n')   # 비밀번호는 표준입력 첫 줄(py_secret) — 명령 인자에 싣지 않는다(R-62)
try:
    from playwright.sync_api import sync_playwright
except Exception as e:
    print('PLAYWRIGHT_MISSING', e); sys.exit(0)
with sync_playwright() as p:
    b = p.chromium.launch(channel='chromium')
    ctx = b.new_context(viewport={'width': int(width), 'height': int(height)}, user_agent=ua)
    page = ctx.new_page()
    page.goto(base + '/login', wait_until='networkidle')
    page.fill('#username', who)
    page.fill('#password', pw)
    page.click('#login-submit')
    page.wait_for_timeout(1200)
    if page.is_visible('#otp'):
        page.fill('#otp', code)
        page.wait_for_timeout(1500)   # 사용감 라운드 1 UX-9 — 여섯 자리에서 로그인 화면이 스스로 제출한다(옛 판은 아래 단추)
        if page.is_visible('#otp') and page.input_value('#otp') != '':
            page.click('#login-submit')
        page.wait_for_timeout(1500)
    ctx.storage_state(path=state)
    print('URL=%s' % page.url)
    print('MSG=%s' % page.evaluate("() => { const m = document.querySelector('#login-msg, #msg, .login__msg'); return m ? m.textContent.trim() : ''; }"))
    b.close()
PY

cat > "$OUT/d-screens.py" <<'PY'
import json, sys
base, ua, code, state, out, name, width, height = sys.argv[1:9]
pw = sys.stdin.readline().rstrip('\n')   # 비밀번호는 표준입력 첫 줄(py_secret) — 명령 인자에 싣지 않는다(R-62)
try:
    from playwright.sync_api import sync_playwright
except Exception as e:
    print('PLAYWRIGHT_MISSING', e); sys.exit(0)

report = {}
with sync_playwright() as p:
    b = p.chromium.launch(channel='chromium')
    ctx = b.new_context(viewport={'width': int(width), 'height': int(height)}, user_agent=ua,
                        storage_state=state, is_mobile=(name == 'mobile'), has_touch=(name == 'mobile'))
    page = ctx.new_page()
    errs = []
    page.on('console', lambda m: errs.append(m.text) if m.type == 'error' else None)
    page.on('pageerror', lambda e: errs.append('pageerror: ' + str(e)))

    measure = """() => {
      const c = document.getElementById('mfa-qr');
      const r = c ? c.getBoundingClientRect() : null;
      const secret = document.getElementById('mfa-secret');
      const link = document.getElementById('mfa-uri-link');
      return {
        exists: !!c,
        hidden: c ? !!c.hidden : null,
        css_width: r ? Math.round(r.width) : 0,
        canvas_width: c ? c.width : 0,
        canvas_height: c ? c.height : 0,
        secret_len: secret ? secret.textContent.replace(/\\s/g, '').length : 0,
        link_visible: link ? !link.hidden : false,
        link_is_otpauth: link ? (link.getAttribute('href') || '').indexOf('otpauth://') === 0 : false
      };
    }"""

    # ① 「내 계정」 슬롯 — 끄기 → 켜기
    page.goto(base + '/settings', wait_until='networkidle')
    page.wait_for_timeout(600)
    page.fill('#mfa-off-password', pw)
    page.fill('#mfa-off-code', code)
    page.click('#mfa-disable-form button[type=submit]')
    page.wait_for_timeout(1500)
    report['disable_msg'] = page.evaluate("() => { const m = document.getElementById('msg'); return m ? m.textContent.trim() : null; }")
    page.click('#mfa-enroll')
    page.wait_for_timeout(1800)
    report['settings'] = page.evaluate(measure)
    page.screenshot(path='%s/%s-settings.png' % (out, name), full_page=True)

    # ② 강제 절차 슬롯 — 끈 상태이므로 홈에 상자가 열린다
    page.goto(base + '/', wait_until='networkidle')
    page.wait_for_timeout(1200)
    report['forced_gate_visible'] = page.is_visible('#forced-mfa')
    page.click('#forced-mfa-slot #mfa-enroll')
    page.wait_for_timeout(1800)
    report['forced'] = page.evaluate(measure)
    page.screenshot(path='%s/%s-forced.png' % (out, name), full_page=True)

    report['console_errors'] = errs
    b.close()
print(json.dumps(report, ensure_ascii=False, indent=2))
PY

run_screens() {  # $1 이름  $2 가로  $3 세로
  local name="$1" w="$2" h="$3" pw sec code state
  pw=$(qa_password); sec=$(qa_secret)
  state="$OUT/d-state-$name.json"
  sub "$name ${w}×${h} — 로그인"
  # 바로 앞 confirm 이 쓴 코드와 같은 창이면 1회성(R-45)에 걸린다 — 늘 다음 창의 코드로 들어간다
  local try
  for try in 1 2 3; do
    code=$(next_code "$sec")
    py_secret pw -- python3 "$OUT/d-login.py" "$BASE" "$UA" "$WHO" "$code" "$state" "$w" "$h" > "$OUT/d-$name-login.txt" 2>&1
    cat "$OUT/d-$name-login.txt" | sed 's/^/    /'
    if grep -q PLAYWRIGHT_MISSING "$OUT/d-$name-login.txt"; then
      echo "  ! playwright 가 없어 (d) 를 실행하지 못했다 — 미검증"
      return 1
    fi
    grep -q 'URL=.*/login' "$OUT/d-$name-login.txt" || break
    echo "  · 로그인이 아직 /login 에 있다 — 다음 코드 창에서 다시 시도 ($try/3)"
  done
  if grep -q 'URL=.*/login' "$OUT/d-$name-login.txt"; then
    echo "  ✖ $name 브라우저 로그인 실패 — 이 뷰포트를 건너뛴다"
    FAIL=$((FAIL+1))
    return 1
  fi
  sub "$name — 끄기 → 켜기 → QR"
  code=$(next_code "$sec")
  py_secret pw -- python3 "$OUT/d-screens.py" "$BASE" "$UA" "$code" "$state" "$OUT/d-shots" "$name" "$w" "$h" > "$OUT/d-$name.json" 2>&1
  cat "$OUT/d-$name.json" | sed 's/^/    /'
  q() { python3 -c "
import json
d = json.load(open('$OUT/d-$name.json'))
v = d['$1']['$2'] if '$2' else d['$1']
print(int(v) if isinstance(v, bool) else v)"; }
  expect "$name /settings — canvas 가 보임(hidden 아님)" 0 "$(q settings hidden)"
  expect "$name /settings — 그린 크기 ≥ 180px" 1 "$([ "$(q settings css_width)" -ge 180 ] && echo 1 || echo 0)"
  expect "$name /settings — 비밀 키 32자 그대로 있음" 32 "$(q settings secret_len)"
  expect "$name /settings — otpauth 링크 그대로 있음" 1 "$(q settings link_is_otpauth)"
  expect "$name 강제 절차 상자가 열림" 1 "$(q forced_gate_visible '')"
  expect "$name 강제 절차 — canvas 가 보임" 0 "$(q forced hidden)"
  expect "$name 강제 절차 — 그린 크기 ≥ 180px" 1 "$([ "$(q forced css_width)" -ge 180 ] && echo 1 || echo 0)"
  expect "$name 콘솔 오류 0건" 0 "$(python3 -c "
import json; d = json.load(open('$OUT/d-$name.json')); print(len(d['console_errors']))")"
  sub "$name — 끝났으니 2단계 인증을 **다시 켠다**"
  reenable_mfa
  expect "$name 뒤 다시 켜짐" "t" "$(psqlq "SELECT mfa_enabled FROM users WHERE username='$WHO'")"
}

if [ -n "${TOK:-}" ]; then
  run_screens mobile 390 844
  run_screens desktop 1440 900
else
  echo "  ! 로그인하지 못해 (d) 를 건너뛴다 — 미검증"
fi

# ─────────────────────────────────────────────────────────────
hr "(e) 폴백 — 너무 긴 문자열"
# ─────────────────────────────────────────────────────────────
THROWN=$(php -r '
require $argv[1];
try { Qr::encode(str_repeat("x", 214)); echo "NO_THROW"; }
catch (RuntimeException $e) { echo "RUNTIME_EXCEPTION"; }
catch (Throwable $e) { echo "OTHER:" . get_class($e); }
' "$ROOT/src/Qr.php")
expect "214바이트 → RuntimeException" "RUNTIME_EXCEPTION" "$THROWN"
BAD=$(php -r '
require $argv[1];
try { Qr::encode("A", 8); echo "NO_THROW"; }
catch (InvalidArgumentException $e) { echo "INVALID_ARGUMENT"; }
' "$ROOT/src/Qr.php")
expect "마스크 8 → InvalidArgumentException" "INVALID_ARGUMENT" "$BAD"
OK213=$(php -r 'require $argv[1]; $r = Qr::encode(str_repeat("x", 213)); echo $r["version"];' "$ROOT/src/Qr.php")
expect "213바이트는 버전 10 으로 들어간다" 10 "$OK213"
sub "핸들러의 qr:null 갈래 — 코드 읽기"
grep -n -A 3 'catch (RuntimeException' "$ROOT/src/handlers/mfa_enroll.php" | sed 's/^/    /'
echo "    (코드 읽기: RuntimeException 을 잡아 \$qr = null 로 두고 나머지 네 필드는 그대로 내려간다 — PRD F-51 ⑤)"

# ─────────────────────────────────────────────────────────────
hr "(f) 정적 검사 — 린트 · php -l"
# ─────────────────────────────────────────────────────────────
if bash "$ROOT/scripts/security_lint.sh" > "$OUT/f-lint.txt" 2>&1; then
  tail -2 "$OUT/f-lint.txt" | sed 's/^/    /'
  expect "린트 0건" 0 0
else
  cat "$OUT/f-lint.txt"; expect "린트 0건" 0 1
fi

LINT_FAIL=0
for f in src/Qr.php src/handlers/mfa_enroll.php src/views/mfa_enroll.php src/bootstrap.php; do
  php -l "$ROOT/$f" > /dev/null 2>&1 || { php -l "$ROOT/$f"; LINT_FAIL=$((LINT_FAIL+1)); }
done
expect "php -l 실패 0" 0 "$LINT_FAIL"

NONQA_AFTER=$(db_read "$DB" "SELECT count(*) FROM audit_logs WHERE happened_at > '$T0'::timestamptz AND actor_name IS NOT NULL AND $REVIEW_NOT_SQL")
echo "  이 실행 중 비-qa 감사 행: $NONQA_AFTER (0 이어야 정상)"
expect "비-qa 감사 행 0" 0 "$NONQA_AFTER"

MFA_FINAL=$(psqlq "SELECT mfa_enabled FROM users WHERE username='$WHO'")
expect "끝난 뒤 $WHO 의 2단계 인증이 켜져 있다" "t" "$MFA_FINAL"

rm -rf "$JAR_DIR"
rm -f "$OUT"/d-state-*.json

# ─────────────────────────────────────────────────────────────
hr "결과"
# ─────────────────────────────────────────────────────────────
printf '  통과 %d · 실패 %d\n' "$PASS" "$FAIL"
printf '  스크린샷·출력: %s\n' "$OUT"
[ "$FAIL" -eq 0 ] || exit 1
