#!/usr/bin/env bash
# tests/fetch_hosts.sh — F-43 수락 기준 실행 (PRD 12.2 T-56)
#   (a) 권한·재인증·코드·사유 관문      (b) 추가·감사·AL-18·형식 거부
#   (c) /api/me 의 fetch_enabled·fetch_hosts_public
#   (d) 화면 — 홈 단추 숨김/보임 · 관리 화면 · 도움말 (Playwright)
#   (e) CloudLink 단위 시험              (f) 통제된 리다이렉트 단위 시험
#   (g) 실제 드라이브 공개 파일 — **하지 않는다**(아래 설명)
#   (h) 린트 · php -l · \dp fetch_hosts
#
# 규칙 (CLAUDE.md 3장)
#   · **검토 계정(qa_ 접두)만** 쓴다. 시드 계정(admin01 …)은 사용자의 자기 공격 전용이다.
#   · 모든 요청에 `-A "tbox-review/<날짜>"` 를 붙인다.
#   · 비밀번호·TOTP 비밀은 **필요한 필드만** awk 로 뽑고 변수에만 담는다 (SECURITY R-62).
#
# ★ 시험 안전 (SECURITY R-73). 19~26단계 스크립트와 **같은 규칙**이다.
#   ① 최근 10분 감사 로그에 비-qa actor 가 하나라도 있으면 **시작하지 않는다**(종료 코드 3).
#   ② 만드는 것은 전부 `s43-` 접두의 **존재하지 않는 도메인**(`.example.invalid`, RFC 6761)이다.
#      DNS 가 반드시 실패하므로 이 시험은 **바깥으로 한 바이트도 나가지 않는다**.
#   ③ 정리는 `fetch_hosts` 에서 **그 접두 행만** psql 로 지운다(앱 계정에는 DELETE 권한이 없다).
#      운영 행(관리자가 실제로 넣은 사이트)은 한 줄도 건드리지 않는다.
#   ④ 시작·끝에 「비-qa 행 수」를 세어 같은지 보고한다.
#
# ★ (g) 를 하지 않는 이유 — 실제 구글 드라이브 공개 파일 주소가 없다. 임의의 외부 주소로
#   시험하지 않는다(CLAUDE.md 4장 "새 외부 요청"). 리다이렉트 로직은 (f) 의 단위 시험으로
#   확인하고, 실제 네트워크 시험은 사용자가 허용 목록에 사이트를 넣은 뒤 관문 ③에서 한다.
#
# 준비물: sudo(로 /etc/tbox-v2 읽기 · psql · alerts.php 실행), php, curl, python3+playwright
#
# 사용: sudo -v && bash tests/fetch_hosts.sh [출력 폴더]
#   출력 폴더 기본값은 ~/.claude/ui-shots/f43 (루트 디스크를 쓰지 않는다).
set -u

BASE="http://127.0.0.1:8082"
UA="tbox-review/$(date +%F)"
DB="tbox_re"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="${1:-$HOME/.claude/ui-shots/f43}"
JAR_DIR="$(mktemp -d)"
WORK="$(mktemp -d)"
PASS=0
FAIL=0

# 픽스처 — 존재하지 않는 최상위 도메인(RFC 6761 `.invalid`). DNS 가 반드시 실패한다.
H1="s43-1.example.invalid"
H2="s43-2.example.invalid"

mkdir -p "$OUT"

psqlq() { sudo -n -u postgres psql -d "$DB" -t -A -c "$1"; }
psqlc() { sudo -n -u postgres psql -d "$DB" -v ON_ERROR_STOP=1 -q -c "$1"; }

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

# ── 로그인 ────────────────────────────────────────────────────
# TOTP 코드는 1회성이다(R-45) — 같은 창에서 두 번 쓰면 거부되는 것이 **정상**이라 기다렸다 다시 한다.
login() {
  local who="$1" jar="$JAR_DIR/$1.jar" pw sec raw code left out try
  pw=$(sudo -n awk -v u="$who" '$1==u{print $3}' /etc/tbox-v2/seed-accounts.txt)
  sec=$(sudo -n awk -v u="$who" '$1==u{print $2}' /etc/tbox-v2/qa-totp.txt)
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
  printf '  ! %s 로그인 실패: %s\n' "$who" "$out" >&2
  return 1
}

reauth() {
  local who="$1" token="$2" pw
  pw=$(sudo -n awk -v u="$who" '$1==u{print $3}' /etc/tbox-v2/seed-accounts.txt)
  curl -s -o /dev/null -w '%{http_code}' -b "$JAR_DIR/$who.jar" -A "$UA" \
    -H 'Content-Type: application/json' -H "X-CSRF-Token: $token" \
    -d "{\"password\":\"$pw\"}" "$BASE/api/me/reauth"
}

# 지금 쓸 수 있는 TOTP 코드 하나 (1회성이라 요청마다 새로 받는다)
otp_now() {
  local who="$1" sec
  sec=$(sudo -n awk -v u="$who" '$1==u{print $2}' /etc/tbox-v2/qa-totp.txt)
  php "$ROOT/scripts/totp_code.php" "$sec" | head -1
}

# 다음 TOTP 창까지 기다린다 (코드 1회성 때문에 연속 저장 시 필요)
wait_next_step() {
  local who="$1" sec left
  sec=$(sudo -n awk -v u="$who" '$1==u{print $2}' /etc/tbox-v2/qa-totp.txt)
  left=$(php "$ROOT/scripts/totp_code.php" "$sec" | sed -n 's/^남은 시간: \([0-9]*\)초.*/\1/p')
  [ -n "$left" ] || left=31
  sleep $((left + 1))
}

# $1 계정 $2 메서드 $3 경로 $4 토큰 $5 본문(선택) → "본문\nHTTP코드"
call() {
  local who="$1" method="$2" path="$3" token="$4" body="${5-}"
  if [ -n "$body" ]; then
    curl -s -w '\n%{http_code}' -X "$method" -b "$JAR_DIR/$who.jar" -A "$UA" \
      -H 'Content-Type: application/json' -H "X-CSRF-Token: $token" \
      -d "$body" "$BASE$path"
  else
    curl -s -w '\n%{http_code}' -X "$method" -b "$JAR_DIR/$who.jar" -A "$UA" \
      -H "X-CSRF-Token: $token" "$BASE$path"
  fi
}

code_of() { printf '%s' "$1" | tail -1; }
body_of() { printf '%s' "$1" | sed '$d'; }

# ─────────────────────────────────────────────────────────────
hr "0. 시험 안전 점검 (SECURITY R-73)"
# ─────────────────────────────────────────────────────────────
# 2026-09-15 Fable: 감사 단언은 「이 실행 시작 이후」만 센다 — 고정 창(2·10분)은 연속 실행 때 앞 실행의 행을 함께 세어 오탐했다
T0=$(psqlq "SELECT now();")
NONQA_BEFORE=$(psqlq "SELECT count(*) FROM audit_logs WHERE happened_at > now() - interval '10 minutes' AND actor_name IS NOT NULL AND actor_name NOT LIKE 'qa\\_%'")
echo "  최근 10분 비-qa 감사 행: $NONQA_BEFORE"
if [ "${NONQA_BEFORE:-1}" != "0" ]; then
  echo "  ✖ 사용자가 지금 서비스를 쓰고 있다 — 시험을 시작하지 않는다 (종료 3)"
  exit 3
fi
echo "  현재 fetch_hosts 행: $(psqlq "SELECT count(*) FROM fetch_hosts")"
echo "  그 중 s43- 접두(이전 시험 잔재): $(psqlq "SELECT count(*) FROM fetch_hosts WHERE host LIKE 's43-%'")"

# 이전 시험 잔재를 먼저 치운다 — **접두가 정확히 맞는 행만** (운영 행은 건드리지 않는다)
psqlc "DELETE FROM fetch_hosts WHERE host LIKE 's43-%.example.invalid'"

# ─────────────────────────────────────────────────────────────
hr "로그인 (qa_admin01 · qa_manager01 · qa_employee01)"
# ─────────────────────────────────────────────────────────────
TOK_ADMIN=$(login qa_admin01)   || exit 1
TOK_MGR=$(login qa_manager01)   || exit 1
TOK_EMP=$(login qa_employee01)  || exit 1
echo "  csrf 토큰 3개 확보"

# ─────────────────────────────────────────────────────────────
hr "(c-1) /api/me — 목록이 0건일 때 (fetch_enabled=false)"
# ─────────────────────────────────────────────────────────────
ME_MGR=$(curl -s -b "$JAR_DIR/qa_manager01.jar" -A "$UA" "$BASE/api/me")
expect "qa_manager01 can_fetch=true"      1 "$(printf '%s' "$ME_MGR" | grep -c '"can_fetch":true')"
expect "qa_manager01 fetch_enabled=false" 1 "$(printf '%s' "$ME_MGR" | grep -c '"fetch_enabled":false')"
expect "qa_manager01 fetch_hosts_public 키 있음" 1 "$(printf '%s' "$ME_MGR" | grep -c 'fetch_hosts_public')"

ME_EMP=$(curl -s -b "$JAR_DIR/qa_employee01.jar" -A "$UA" "$BASE/api/me")
expect "qa_employee01 can_fetch=false"           1 "$(printf '%s' "$ME_EMP" | grep -c '"can_fetch":false')"
expect "qa_employee01 응답에 fetch_hosts_public 키 없음" 0 "$(printf '%s' "$ME_EMP" | grep -c 'fetch_hosts_public')"

# ─────────────────────────────────────────────────────────────
hr "(a) 권한 · 재인증 · 코드 · 사유 관문"
# ─────────────────────────────────────────────────────────────
sub "Manager 는 창구 자체가 막힌다 (라우트 정책 role:Admin)"
R=$(call qa_manager01 GET  /api/fetch-hosts "$TOK_MGR")
expect "GET  /api/fetch-hosts → 403" 403 "$(code_of "$R")"
R=$(call qa_manager01 POST /api/fetch-hosts "$TOK_MGR" '{"changes":[{"host":"'"$H1"'","is_active":true}],"reason_text":"x","otp":"000000"}')
expect "POST /api/fetch-hosts → 403" 403 "$(code_of "$R")"
expect "감사 authz.route deny 남음" 1 "$(psqlq "SELECT count(*) > 0 FROM audit_logs WHERE action='authz.route' AND result='deny' AND actor_name='qa_manager01' AND happened_at > '$T0'::timestamptz" | grep -c '^t$')"

sub "Admin 이라도 재인증 창이 없으면 막힌다 (F-23)"
R=$(call qa_admin01 GET /api/fetch-hosts "$TOK_ADMIN")
expect "GET → 403" 403 "$(code_of "$R")"
expect "본문에 next:reauth" 1 "$(body_of "$R" | grep -c '"next":"reauth"')"

echo "  재인증: qa_admin01 → $(reauth qa_admin01 "$TOK_ADMIN")"

R=$(call qa_admin01 GET /api/fetch-hosts "$TOK_ADMIN")
expect "재인증 뒤 GET → 200" 200 "$(code_of "$R")"
expect "빈 목록"              1 "$(body_of "$R" | grep -c '"hosts":\[\]')"

sub "사유 없음 · 코드 없음 · 코드 틀림 (전부 400)"
R=$(call qa_admin01 POST /api/fetch-hosts "$TOK_ADMIN" '{"changes":[{"host":"'"$H1"'","is_active":true}],"otp":"123456"}')
expect "사유 없음 → 400" 400 "$(code_of "$R")"
R=$(call qa_admin01 POST /api/fetch-hosts "$TOK_ADMIN" '{"changes":[{"host":"'"$H1"'","is_active":true}],"reason_text":"시험"}')
expect "코드 없음 → 400" 400 "$(code_of "$R")"
R=$(call qa_admin01 POST /api/fetch-hosts "$TOK_ADMIN" '{"changes":[{"host":"'"$H1"'","is_active":true}],"reason_text":"시험","otp":"000000"}')
expect "코드 틀림 → 400" 400 "$(code_of "$R")"
expect "감사 fetch_host.change deny bad_code" 1 "$(psqlq "SELECT count(*) > 0 FROM audit_logs WHERE action='fetch_host.change' AND result='deny' AND reason='bad_code' AND actor_name='qa_admin01' AND happened_at > '$T0'::timestamptz" | grep -c '^t$')"
expect "표는 여전히 0행" 0 "$(psqlq "SELECT count(*) FROM fetch_hosts WHERE host LIKE 's43-%'")"

# ─────────────────────────────────────────────────────────────
hr "(b) 추가 2건 → 감사 · AL-18 · 형식 거부"
# ─────────────────────────────────────────────────────────────
sub "정상 저장 — 대문자로 보내고 소문자로 저장되는지 함께 본다"
wait_next_step qa_admin01
OTP=$(otp_now qa_admin01)
R=$(call qa_admin01 POST /api/fetch-hosts "$TOK_ADMIN" \
   '{"changes":[{"host":"'"$(printf '%s' "$H1" | tr a-z A-Z)"'","is_active":true,"note":"F-43 시험용"},{"host":"'"$H2"'","is_active":true}],"reason_text":"F-43 수락 기준 시험","otp":"'"$OTP"'"}')
expect "POST → 200"     200 "$(code_of "$R")"
expect "changed 2건"      2 "$(body_of "$R" | grep -o '"added":true' | wc -l)"
expect "표에 2행"         2 "$(psqlq "SELECT count(*) FROM fetch_hosts WHERE host LIKE 's43-%'")"
expect "소문자로 저장"    1 "$(psqlq "SELECT count(*) FROM fetch_hosts WHERE host = '$H1'")"
expect "감사 allow 2건"   2 "$(psqlq "SELECT count(*) FROM audit_logs WHERE action='fetch_host.change' AND result='allow' AND actor_name='qa_admin01' AND happened_at > '$T0'::timestamptz")"
echo "  감사 detail 원문:"
psqlq "SELECT target_name || ' :: ' || detail::text FROM audit_logs WHERE action='fetch_host.change' AND result='allow' AND happened_at > '$T0'::timestamptz ORDER BY id" | sed 's/^/    /'
expect "detail 에 before(null)·after·사유·코드확인" 2 "$(psqlq "SELECT count(*) FROM audit_logs WHERE action='fetch_host.change' AND result='allow' AND happened_at > '$T0'::timestamptz AND detail ? 'after' AND detail ? 'reason_text' AND detail->>'otp_verified' = 'true'")"

sub "같은 코드를 다시 쓰면 거부 (R-45)"
R=$(call qa_admin01 POST /api/fetch-hosts "$TOK_ADMIN" \
   '{"changes":[{"host":"'"$H1"'","is_active":false}],"reason_text":"코드 재사용 시험","otp":"'"$OTP"'"}')
expect "같은 코드 재사용 → 400" 400 "$(code_of "$R")"
expect "감사 code_reused" 1 "$(psqlq "SELECT count(*) > 0 FROM audit_logs WHERE action='fetch_host.change' AND result='deny' AND reason='code_reused' AND happened_at > '$T0'::timestamptz" | grep -c '^t$')"
expect "여전히 활성 2행" 2 "$(psqlq "SELECT count(*) FROM fetch_hosts WHERE host LIKE 's43-%' AND is_active")"

sub "AL-18 — 허용 사이트 변경은 1건이라도 즉시 경보"
ALERT_STATE="$WORK/alerts-state.json"
sudo -n php "$ROOT/scripts/alerts.php" --rule AL-18 --state "$ALERT_STATE" 2>&1 | sed 's/^/    /'
expect "AL-18 발동 1건" 1 "$(sudo -n php -r '
$s = json_decode(@file_get_contents($argv[1]) ?: "{}", true);
echo is_array($s) ? count(array_filter(array_keys($s), fn($k) => str_starts_with($k, "AL-18|"))) : 0;
' "$ALERT_STATE")"

sub "형식 거부 — 와일드카드 · IP · http · 경로"
for BAD in '*.example.invalid' '10.0.0.5' 'https://s43-3.example.invalid/x' 'localhost' 's43 4.example.invalid'; do
  wait_next_step qa_admin01
  OTPB=$(otp_now qa_admin01)
  R=$(call qa_admin01 POST /api/fetch-hosts "$TOK_ADMIN" \
     "$(printf '{"changes":[{"host":%s,"is_active":true}],"reason_text":"형식 시험","otp":"%s"}' "$(php -r 'echo json_encode($argv[1], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);' "$BAD")" "$OTPB")")
  expect "거부: $BAD → 400" 400 "$(code_of "$R")"
done
expect "형식 거부가 행을 만들지 않음" 2 "$(psqlq "SELECT count(*) FROM fetch_hosts WHERE host LIKE 's43-%'")"
expect "감사 invariant 5건" 5 "$(psqlq "SELECT count(*) FROM audit_logs WHERE action='fetch_host.change' AND result='deny' AND reason='invariant' AND happened_at > '$T0'::timestamptz")"

sub "DELETE 라우트가 없다 (405/404)"
R=$(call qa_admin01 DELETE /api/fetch-hosts "$TOK_ADMIN")
expect "DELETE /api/fetch-hosts → 405" 405 "$(code_of "$R")"

# ─────────────────────────────────────────────────────────────
hr "(c-2) /api/me — 목록이 활성 2건일 때 · 비활성화하면 다시 false"
# ─────────────────────────────────────────────────────────────
ME_MGR=$(curl -s -b "$JAR_DIR/qa_manager01.jar" -A "$UA" "$BASE/api/me")
expect "fetch_enabled=true" 1 "$(printf '%s' "$ME_MGR" | grep -c '"fetch_enabled":true')"
expect "fetch_hosts_public 에 픽스처 2개" 2 "$(printf '%s' "$ME_MGR" | grep -o 's43-[12]\.example\.invalid' | wc -l)"

sub "두 사이트를 비활성화 → 다시 false"
wait_next_step qa_admin01
OTP2=$(otp_now qa_admin01)
R=$(call qa_admin01 POST /api/fetch-hosts "$TOK_ADMIN" \
   '{"changes":[{"host":"'"$H1"'","is_active":false},{"host":"'"$H2"'","is_active":false}],"reason_text":"시험 정리 — 비활성화","otp":"'"$OTP2"'"}')
expect "비활성화 POST → 200" 200 "$(code_of "$R")"
expect "행은 그대로 2행 (지워지지 않음)" 2 "$(psqlq "SELECT count(*) FROM fetch_hosts WHERE host LIKE 's43-%'")"
expect "활성 0행" 0 "$(psqlq "SELECT count(*) FROM fetch_hosts WHERE host LIKE 's43-%' AND is_active")"
ME_MGR=$(curl -s -b "$JAR_DIR/qa_manager01.jar" -A "$UA" "$BASE/api/me")
expect "fetch_enabled 다시 false" 1 "$(printf '%s' "$ME_MGR" | grep -c '"fetch_enabled":false')"

sub "바뀌는 것이 없는 요청은 거부 (사유만 남기는 기록을 만들지 않는다)"
wait_next_step qa_admin01
OTP3=$(otp_now qa_admin01)
R=$(call qa_admin01 POST /api/fetch-hosts "$TOK_ADMIN" \
   '{"changes":[{"host":"'"$H1"'","is_active":false}],"reason_text":"변화 없음 시험","otp":"'"$OTP3"'"}')
expect "변화 없음 → 400" 400 "$(code_of "$R")"

# ─────────────────────────────────────────────────────────────
hr "가져오기 동작 — 허용 목록이 실제로 통로를 여닫는가"
# ─────────────────────────────────────────────────────────────
sub "비활성 상태: 픽스처 주소로 가져오기 → host_not_allowed"
R=$(call qa_manager01 POST /api/files/fetch "$TOK_MGR" '{"url":"https://'"$H1"'/a.csv","access_level":"internal"}')
expect "가져오기 → 400" 400 "$(code_of "$R")"
expect "감사 reason=host_not_allowed" 1 "$(psqlq "SELECT count(*) > 0 FROM audit_logs WHERE action='file.fetch' AND result='deny' AND reason='host_not_allowed' AND actor_name='qa_manager01' AND happened_at > '$T0'::timestamptz" | grep -c '^t$')"

sub "활성화하면 사유가 바뀐다 — 허용은 됐고 DNS 가 없어 private_ip (밖으로 나가지 않음)"
wait_next_step qa_admin01
OTP4=$(otp_now qa_admin01)
R=$(call qa_admin01 POST /api/fetch-hosts "$TOK_ADMIN" \
   '{"changes":[{"host":"'"$H1"'","is_active":true}],"reason_text":"가져오기 경로 시험","otp":"'"$OTP4"'"}')
expect "재활성 POST → 200" 200 "$(code_of "$R")"
R=$(call qa_manager01 POST /api/files/fetch "$TOK_MGR" '{"url":"https://'"$H1"'/a.csv","access_level":"internal"}')
expect "가져오기 → 400 (DNS 없음)" 400 "$(code_of "$R")"
LASTREASON=$(psqlq "SELECT reason FROM audit_logs WHERE action='file.fetch' AND result='deny' AND actor_name='qa_manager01' ORDER BY id DESC LIMIT 1")
expect "사유가 host_not_allowed 가 아니다 (허용 목록을 통과했다)" "yes" "$([ "$LASTREASON" != "host_not_allowed" ] && echo yes || echo "no($LASTREASON)")"
echo "    마지막 사유: $LASTREASON"

sub "SharePoint 링크 → download=1 로 변환(F-46) · 허용 목록에 없는 테넌트는 host_not_allowed (바깥으로 나가지 않는다)"
# F-46(2026-09-15) 전에는 주소 모양만 보고 needs_login 으로 미리 거절했다. 지금은 OneDrive 와 같이
# download=1 을 붙여 정상 경로로 보내고, 「공개가 아니다」는 돌아온 HTML 을 보고 판단한다.
# 그래서 이 가짜 테넌트는 허용 목록 검사(첫 요청 = 정확 일치)에서 막혀야 하고, 로그인 문구가 나오면 안 된다.
R=$(call qa_manager01 POST /api/files/fetch "$TOK_MGR" '{"url":"https://contoso-my.sharepoint.com/:x:/g/personal/a_b/EABCdefgh","access_level":"internal"}')
expect "host_not_allowed → 400" 400 "$(code_of "$R")"
expect "로그인 문구가 아니다 (주소 모양으로 미리 거절하지 않는다, F-46)" 0 "$(body_of "$R" | grep -c '로그인이 필요합니다')"
expect "감사 reason=host_not_allowed" 1 "$(psqlq "SELECT count(*) > 0 FROM audit_logs WHERE action='file.fetch' AND result='deny' AND reason='host_not_allowed' AND actor_name='qa_manager01' AND happened_at > '$T0'::timestamptz" | grep -c '^t$')"

# ─────────────────────────────────────────────────────────────
hr "(e) CloudLink 단위 시험 (네트워크 0)"
# ─────────────────────────────────────────────────────────────
php -r '
require $argv[1];
$id = "1AbC_dEfGhIjKlMnOpQ";
$cases = [
  ["https://drive.google.com/file/d/$id/view?usp=sharing", "https://drive.google.com/uc?export=download&id=$id", "google", false],
  ["https://drive.google.com/open?id=$id",                 "https://drive.google.com/uc?export=download&id=$id", "google", false],
  ["https://docs.google.com/document/d/$id/edit",          "https://docs.google.com/document/d/$id/export?format=docx", "google", false],
  ["https://docs.google.com/spreadsheets/d/$id/edit",      "https://docs.google.com/spreadsheets/d/$id/export?format=xlsx", "google", false],
  ["https://docs.google.com/presentation/d/$id/edit",      "https://docs.google.com/presentation/d/$id/export?format=pptx", "google", false],
  ["https://1drv.ms/w/s!AbCdEfGh",                          "https://1drv.ms/w/s!AbCdEfGh", "microsoft", false],
  ["https://onedrive.live.com/?resid=ABC&authkey=k",        "https://onedrive.live.com/?resid=ABC&authkey=k&download=1", "microsoft", false],
  ["https://contoso-my.sharepoint.com/:x:/g/personal/a/E1", "https://contoso-my.sharepoint.com/:x:/g/personal/a/E1?download=1", "microsoft", false],  // F-46: download=1 · needs_login 은 돌아온 것으로 판단
  ["https://s43-1.example.invalid/a.csv",                   "https://s43-1.example.invalid/a.csv", "", false],
  ["https://drive.google.com/file/d/bad id!/view",          "https://drive.google.com/file/d/bad id!/view", "", false],
  ["https://drive.google.com/file/d/short/view",            "https://drive.google.com/file/d/short/view", "", false],
];
$ok = 0; $no = 0;
foreach ($cases as [$in, $wantUrl, $wantProv, $wantLogin]) {
    $r = CloudLink::normalize($in);
    $good = $r["url"] === $wantUrl && (string) $r["provider"] === $wantProv && $r["needs_login"] === $wantLogin;
    printf("  %s %-58s -> %s | %s | %s\n", $good ? "OK  " : "FAIL", $in, $r["url"], (string) $r["provider"], $r["needs_login"] ? "needs_login" : "-");
    $good ? $ok++ : $no++;
}
echo "CLOUDLINK_OK=$ok\nCLOUDLINK_FAIL=$no\n";
' "$ROOT/src/CloudLink.php" > "$OUT/e-cloudlink.txt" 2>&1
cat "$OUT/e-cloudlink.txt"
expect "CloudLink 단위 시험 실패 0" 0 "$(sed -n 's/^CLOUDLINK_FAIL=//p' "$OUT/e-cloudlink.txt")"

# ─────────────────────────────────────────────────────────────
hr "(f) 통제된 리다이렉트 단위 시험 (네트워크 0 · Fetch::redirectStep)"
# ─────────────────────────────────────────────────────────────
php -r '
require $argv[1];
$A = ["a.example.com", "b.example.com"];
$cases = [
  ["허용 → 허용 1번째",        "https://b.example.com/x",      1, true,  null],
  ["허용 → 허용 2번째",        "https://a.example.com/y",      2, true,  null],
  ["허용 → 허용 3번째",        "https://a.example.com/y",      3, true,  null],
  ["4번째 → redirect 거부",    "https://a.example.com/y",      4, false, "redirect"],
  ["비허용 호스트 → 거부",     "https://evil.example.org/x",   1, false, "host_not_allowed"],
  ["Location 이 http → 거부",  "http://a.example.com/x",       1, false, "scheme"],
  ["포트 8080 → 거부",         "https://a.example.com:8080/x", 1, false, "port"],
  ["IP 리터럴 → 거부",         "https://127.0.0.1/x",          1, false, "host_not_allowed"],
  ["대문자 표기는 정규화",     "https://A.EXAMPLE.COM/x",      1, true,  null],
  ["Location 없음 → 거부",     "",                             1, false, "redirect"],
];
$ok = 0; $no = 0;
foreach ($cases as [$name, $loc, $hop, $wantOk, $wantReason]) {
    $r = Fetch::redirectStep($loc, $hop, $A);
    $good = $r["ok"] === $wantOk && ($wantOk || $r["reason"] === $wantReason);
    printf("  %s %-28s hop=%d ok=%s reason=%s url=%s\n", $good ? "OK  " : "FAIL", $name, $hop,
           var_export($r["ok"], true), (string) $r["reason"], (string) $r["url"]);
    $good ? $ok++ : $no++;
}
echo "MAX_REDIRECTS=", Fetch::MAX_REDIRECTS, "\nREDIRECT_OK=$ok\nREDIRECT_FAIL=$no\n";
' "$ROOT/src/Fetch.php" > "$OUT/f-redirect.txt" 2>&1
cat "$OUT/f-redirect.txt"
expect "리다이렉트 단위 시험 실패 0" 0 "$(sed -n 's/^REDIRECT_FAIL=//p' "$OUT/f-redirect.txt")"
expect "최대 3번"                    3 "$(sed -n 's/^MAX_REDIRECTS=//p' "$OUT/f-redirect.txt")"

sub "코드 자체 — FOLLOWLOCATION 은 false 로 남아 있는가"
expect "CURLOPT_FOLLOWLOCATION => false 한 줄" 1 "$(grep -c 'CURLOPT_FOLLOWLOCATION  => false' "$ROOT/src/Fetch.php")"
expect "FOLLOWLOCATION true 0건"                0 "$(grep -c 'CURLOPT_FOLLOWLOCATION[^,;]*true' "$ROOT/src/Fetch.php")"
expect "리다이렉트 뒤 check() 를 다시 부른다"   1 "$(grep -c 'self::check((string) \$decided\[.url.\], \$provider)' "$ROOT/src/Fetch.php")"

# ─────────────────────────────────────────────────────────────
hr "(g) 실제 드라이브 공개 파일 — 미검증 (외부 파일 없음)"
# ─────────────────────────────────────────────────────────────
cat <<'NOTE'
  · 공개 테스트 파일의 실제 주소가 없어 실행하지 않았다.
    임의의 외부 주소로 시험하지 않는다 (CLAUDE.md 4장 "새 외부 요청 코드").
  · 따라서 다음 셋은 **미검증**이다 — "안전" 이 아니라 "확인하지 못했다" 이다:
      ① drive.google.com → drive.usercontent.google.com 2단계 리다이렉트로 파일이 실제로 오는가
      ② 감사 detail.hops 에 호스트 2개가 남는가
      ③ 1drv.ms → onedrive.live.com 에 download=1 을 붙이는 followUp 이 실제로 먹히는가
  · 리다이렉트 판단 자체는 (f) 의 단위 시험으로 확인했다.
  · 실제 네트워크 시험은 사용자가 허용 목록에 drive.google.com ·
    drive.usercontent.google.com 을 넣은 뒤 관문 ③(자기 공격)에서 한다.
NOTE

# ─────────────────────────────────────────────────────────────
hr "(d) 화면 — 홈 단추 · 관리 화면 · 도움말 (Playwright)"
# ─────────────────────────────────────────────────────────────
# 지금 상태: 픽스처 1건이 활성이다 → 홈 단추가 보여야 한다.
mkdir -p "$OUT/d-shots"
export PATH="$HOME/.local/bin:$PATH"
python3 - "$BASE" "$UA" "$JAR_DIR/qa_admin01.jar" "$JAR_DIR/qa_manager01.jar" "$OUT/d-shots" <<'PY' > "$OUT/d-ui.txt" 2>&1
import json, sys, re
base, ua, admin_jar, mgr_jar, out = sys.argv[1:6]

def cookies(path):
    jar = []
    for line in open(path):
        if line.startswith('#') and not line.startswith('#HttpOnly_'):
            continue
        f = line.strip().split('\t')
        if len(f) >= 7:
            jar.append({'name': f[5], 'value': f[6], 'domain': '127.0.0.1', 'path': '/'})
    return jar

try:
    from playwright.sync_api import sync_playwright
except Exception as e:
    print('PLAYWRIGHT_MISSING', e); sys.exit(0)

report = {}
with sync_playwright() as p:
    browser = p.chromium.launch(channel='chromium')
    for who, jarfile, pages in (('manager', mgr_jar, ['/']), ('admin', admin_jar, ['/', '/admin/fetch-hosts'])):
        ctx = browser.new_context(viewport={'width': 1440, 'height': 1000}, user_agent=ua)
        ctx.add_cookies(cookies(jarfile))
        for path in pages:
            page = ctx.new_page()
            errs = []
            page.on('console', lambda m: errs.append(m.text) if m.type == 'error' else None)
            page.on('pageerror', lambda e: errs.append(str(e)))
            page.goto(base + path, wait_until='networkidle')
            page.wait_for_timeout(700)
            key = f'{who}{path}'
            info = {'console_errors': errs}
            if path == '/':
                btn = page.query_selector('#fetch-toggle')
                info['fetch_button_visible'] = bool(btn and btn.is_visible())
                # 도움말 모달을 열어 허용 목록이 채워졌는지 본다
                page.click('[data-help-open]')
                page.wait_for_timeout(400)
                ul = page.query_selector('[data-fetch-hosts]')
                info['help_host_list'] = [li.inner_text().strip() for li in ul.query_selector_all('li')] if ul else None
                page.screenshot(path=f'{out}/{who}-home-help.png')
                page.keyboard.press('Escape')
                page.wait_for_timeout(200)
            else:
                info['rows'] = len(page.query_selector_all('#fetch-hosts-table tr'))
                page.screenshot(path=f'{out}/{who}-fetch-hosts.png', full_page=True)
                # 저장 모달 — 체크 하나를 바꾸고 「저장」을 누른다
                box = page.query_selector('#fetch-hosts-table input[type=checkbox]')
                if box:
                    box.click()
                    page.wait_for_timeout(300)
                    page.click('#fetch-hosts-save')
                    page.wait_for_timeout(400)
                    dlg = page.query_selector('#fetch-hosts-dialog')
                    info['save_dialog_open'] = bool(dlg and dlg.get_attribute('open') is not None)
                    page.screenshot(path=f'{out}/{who}-fetch-hosts-modal.png')
            report[key] = info
            page.close()
        ctx.close()
    browser.close()
print(json.dumps(report, ensure_ascii=False, indent=2))
PY
cat "$OUT/d-ui.txt"
if grep -q PLAYWRIGHT_MISSING "$OUT/d-ui.txt"; then
  echo "  ! playwright 가 없어 (d) 를 실행하지 못했다 — 미검증"
else
  expect "Manager 홈: 단추 보임(활성 1건)" 1 "$(python3 -c "import json,sys;d=json.load(open(sys.argv[1]));print(1 if d.get('manager/',{}).get('fetch_button_visible') is True else 0)" "$OUT/d-ui.txt")"
  expect "도움말에 허용 목록이 채워짐"      1 "$(python3 -c "import json,sys;d=json.load(open(sys.argv[1]));print(1 if 's43-1.example.invalid' in d.get('manager/',{}).get('help_host_list',[]) else 0)" "$OUT/d-ui.txt")"
  expect "저장 모달이 열림"                 1 "$(grep -c '"save_dialog_open": true' "$OUT/d-ui.txt")"
  expect "콘솔 오류 0건"                    0 "$(python3 -c "
import json,sys
try: r=json.load(open('$OUT/d-ui.txt'))
except Exception: print(99); sys.exit()
print(sum(len(v.get('console_errors',[])) for v in r.values()))")"
fi

# ─────────────────────────────────────────────────────────────
hr "(h) 정적 검사 — 린트 · php -l · 표 권한"
# ─────────────────────────────────────────────────────────────
sub "scripts/security_lint.sh (규칙 (g) 포함)"
if bash "$ROOT/scripts/security_lint.sh" > "$OUT/h-lint.txt" 2>&1; then
  tail -3 "$OUT/h-lint.txt" | sed 's/^/    /'
  expect "린트 0건" 0 0
else
  cat "$OUT/h-lint.txt"; expect "린트 0건" 0 1
fi
grep -c '리다이렉트 통제' "$OUT/h-lint.txt" > /dev/null && echo "    (규칙 (g) 실행 확인)"

sub "php -l — 이번에 바꾼 PHP 전부"
CHANGED="src/Fetch.php src/CloudLink.php src/Audit.php src/handlers/files_fetch.php
         src/handlers/me.php src/handlers/fetch_hosts_list.php src/handlers/fetch_hosts_update.php
         src/handlers/admin_fetch_hosts_page.php src/handlers/admin_page.php
         src/views/shell_top.php src/help/screens.php public/index.php scripts/alerts.php"
LINT_FAIL=0
for f in $CHANGED; do
  php -l "$ROOT/$f" > /dev/null 2>&1 || { php -l "$ROOT/$f"; LINT_FAIL=$((LINT_FAIL+1)); }
done
expect "php -l 실패 0" 0 "$LINT_FAIL"

sub "표 권한 — tbox_app 은 arw (DELETE 없음)"
DP=$(psqlq "SELECT array_to_string(relacl, ' ') FROM pg_class WHERE relname = 'fetch_hosts'")
echo "    $DP"
expect "tbox_app=arw" 1 "$(printf '%s' "$DP" | grep -c 'tbox_app=arw/')"
expect "tbox_app 에 d(DELETE) 없음" 0 "$(printf '%s' "$DP" | grep -c 'tbox_app=[a-z]*d')"
expect "tbox_backup 은 r 만" 1 "$(printf '%s' "$DP" | grep -c 'tbox_backup=r/')"

# ─────────────────────────────────────────────────────────────
hr "정리 — 시험이 만든 행만 지운다"
# ─────────────────────────────────────────────────────────────
# 앱 계정에는 DELETE 권한이 없으므로 postgres 로 지운다. 조건은 **픽스처 접두 정확 일치**이며
# 운영 행(관리자가 실제로 넣은 사이트)은 한 줄도 건드리지 않는다.
BEFORE_DEL=$(psqlq "SELECT count(*) FROM fetch_hosts")
psqlc "DELETE FROM fetch_hosts WHERE host LIKE 's43-%.example.invalid'"
AFTER_DEL=$(psqlq "SELECT count(*) FROM fetch_hosts")
echo "  fetch_hosts: $BEFORE_DEL → $AFTER_DEL (픽스처 2행 제거)"
expect "정리 뒤 s43- 행 0" 0 "$(psqlq "SELECT count(*) FROM fetch_hosts WHERE host LIKE 's43-%'")"

NONQA_AFTER=$(psqlq "SELECT count(*) FROM audit_logs WHERE happened_at > now() - interval '30 minutes' AND actor_name IS NOT NULL AND actor_name NOT LIKE 'qa\\_%'")
echo "  최근 30분 비-qa 감사 행: 시작 $NONQA_BEFORE → 지금 $NONQA_AFTER (0 이어야 정상)"

rm -rf "$JAR_DIR" "$WORK"

# ─────────────────────────────────────────────────────────────
hr "결과"
# ─────────────────────────────────────────────────────────────
printf '  통과 %d · 실패 %d\n' "$PASS" "$FAIL"
printf '  스크린샷·출력: %s\n' "$OUT"
printf '  미검증: (g) 실제 드라이브 공개 파일 — 외부 테스트 파일 주소 없음\n'
[ "$FAIL" -eq 0 ] || exit 1
