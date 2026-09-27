#!/usr/bin/env bash
# tests/passkey.sh — T:F-72 휴대폰 지문·얼굴로 로그인(패스키) + Q:F-7 B1-1 로그인하면 보던 곳으로 — 수락 시험
#                     (지시서 /var/www/qaredi/docs/passkey-mobile-directive.md 4장 PK-T1~T14 · 구현 기록 docs/passkey-implementation.md)
#
#   정적      php -l(바뀐 파일) · 보안 린트 · bash -n · 커뮤니티 부품 버전 · passkey.js 에 innerHTML·prompt·confirm 0 · Fetch:: 새 호출 0 ·
#             창구 표(라우트 여덟 · 정책) · 토큰 창구 집합·강제 절차 허용 목록 불변 · nginx 다섯 창구 × 끝 슬래시 × 두 사이트 파일
#   PK-T1     php tests/passkey_unit.php(파이썬 cryptography 표본 + 가상 인증기 표본)
#   PK-T2~T9  python3 tests/passkey_api.py — 창구(등록·로그인·재인증·해제·자동 해제·챌린지 표·권한·경보 dry-run) · B1-1 서버 쪽
#   PK-T10~13 python3 tests/browser/passkey.py — 크롬 가상 인증기(CDP WebAuthn) · 화면 360·412·1280 × 라이트·다크 · 누름 수
#   회귀      REGRESS=1 — 같은 임시 인스턴스에서 REGRESS_LIST(기본 help_button api_whoami api_read f68 q_launcher)
#             REGRESS_TEMP=1 — 자기 임시 인스턴스를 띄우는 시험(REGRESS_TEMP_LIST 기본 dbg_s2_security dbg_s_security qf3c_zip dbg_u_ui
#             community/stage7d1 — dbg_u2_ui · community/stage5a2 · community/stage7b 도 안다)을 **이 묶음 규칙**(포트 8304~8309 · 임시 DB 접두 tbox_d3p_)으로
#             고친 사본으로 · 카탈로그 writes_prod·manual_only 거부
#   손잡이    PK_BROWSER_WRAP="<명령 앞말>" — 브라우저 단계와 회귀 한 건마다 앞에 붙인다(예: 공용 잠금 flock + 메모리 기다림 — 취합 규칙 2026-09-27
#             「헤드리스 브라우저는 한 번에 하나」) · PK_SKIP_MAIN=1 — PK-T2~T13(창구·화면 몸통)을 건너뛰고 정적·PK-T1·회귀만(통과한 몸통을 다시 안 돌림)
#
# ★ 운영 DB·운영 포트를 건드리지 않는다 — 임시 DB `tbox_d3p_<포트>_test`(운영 pg_dump 를 **읽어** 파이프로 붓기만) · 임시 인스턴스
#   `php -S 127.0.0.1:<포트>`(8301~8309 만 — 기본 8301 · IP 설정 인스턴스 8302 · 다른 origin 인스턴스 8303). 주소는 **http://localhost:<포트>**
#   (IP 주소는 RP ID 가 될 수 없다 — 지시서 4장). 검토 계정 claude_ 다섯(F-71) · UA tbox-review/<날짜> · 비밀은 표준입력·0600 임시 파일로만(R-62).
#   임시 .env 원본 — 복제본에는 .env 가 없다: mktemp 폴더(0700)의 0600 사본을 PK_ENV_SRC 로 넘기고 끝에 지운다.
#   이 인스턴스의 .env 는 DB_NAME·STORAGE_DIR·AUDIT_LOG_FILE 을 임시로, SCAN_ENABLED=0, APP_URL=http://localhost:<포트>. 띄우기 전에
#   DB_NAME 이 tbox_re 가 아닌지 본다. 경보 배치(alerts.php)는 **임시 사본**을 임시 .env 로만 --dry-run(--state 임시 · --auth-log 빈 파일).
#   운영 감사 max(id)·운영 alerts.log 줄 수·/etc/tbox-v2/qa-totp.txt mtime 을 시작·끝에 적는다(읽기만).
# ★ sudo psql 은 명령 줄이 늘 같은 세 모양뿐이다(취합 규칙 2026-09-26): 임시 DB `psql -X -q -At -d tbox_d3p_<포트>_test` · DB 만들고 지우기
#   `psql -X -q -At -d postgres` · 운영 읽기 `psql -X -q -At -d tbox_re`(시작·끝 한 번씩). -c 로 SQL 을 싣지 않는다(표준입력만).
set -u
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
. "$ROOT/tests/lib/review_accounts.sh" || { echo "tests/lib/review_accounts.sh 를 읽지 못했다 — 시험을 시작하지 않는다"; exit 3; }
. "$ROOT/tests/lib/secrets.sh" || { echo "tests/lib/secrets.sh 를 읽지 못했다 — 시험을 시작하지 않는다 (R-62)"; exit 3; }
PORT="${PK_PORT:-8301}"
PORT_IP="${PK_PORT_IP:-8302}"
PORT_ALT="${PK_PORT_ALT:-8303}"
for p in "$PORT" "$PORT_IP" "$PORT_ALT"; do
  case "$p" in 830[1-9]) ;; *) echo "포트는 8301~8309 만 쓴다(받은 값: $p)"; exit 3 ;; esac
done
SITE="http://localhost:$PORT"
BASE="$SITE/tbox"
UA="tbox-review/$(date +%F)"
SRC_DB="tbox_re"                     # 운영 — **읽기만** 한다(pg_dump)
DB="tbox_d3p_${PORT}_test"           # 임시 — 여기에만 쓴다(접두 tbox_d3p_)
case "$DB" in tbox_d3p_*_test) ;; *) echo "임시 DB 이름 규칙 밖 — 중단"; exit 3 ;; esac
PROD_ALERTS="/var/log/tbox-v2/alerts.log"
JAR_DIR="$(mktemp -d)"
WORK="$(mktemp -d)"
SHOTS="${PK_SHOTS:-$WORK/shots}"
chmod 700 "$JAR_DIR" "$WORK"
INST=""
PIDS=()
read -r -a BWRAP <<< "${PK_BROWSER_WRAP:-}"
SKIP_MAIN="${PK_SKIP_MAIN:-0}"
PASS=0
FAIL=0

hr()  { printf '\n════════════════════════════════════════════════════════════\n%s\n════════════════════════════════════════════════════════════\n' "$1"; }
sub() { printf '\n── %s\n' "$1"; }
expect() {
  if [ "$2" = "$3" ]; then printf '  ✔ %s  (기대=%s 실제=%s)\n' "$1" "$2" "$3"; PASS=$((PASS+1))
  else printf '  ✖ %s  (기대=%s 실제=%s)\n' "$1" "$2" "$3"; FAIL=$((FAIL+1)); fi
}
note() { printf '  · %s\n' "$1"; }

cleanup() {
  local pid
  for pid in "${PIDS[@]}"; do
    [ -n "$pid" ] && { pkill -P "$pid" 2>/dev/null; kill "$pid" 2>/dev/null; wait "$pid" 2>/dev/null; }
  done
  for p in "$PORT" "$PORT_IP" "$PORT_ALT"; do pkill -f "^php -S 127\.0\.0\.1:$p " 2>/dev/null; done
  pg_admin "DROP DATABASE IF EXISTS $DB;" >/dev/null 2>&1
  [ -n "$INST" ] && rm -rf "$INST"
  [ -n "${PK_KEEP_SHOTS:-}" ] || rm -rf "$WORK"
  rm -rf "$JAR_DIR"
}
trap cleanup EXIT

psqlq()    { printf '%s\n' "$1" | sudo -n -u postgres psql -X -q -At -d "$DB" 2>/dev/null; }
pg_admin() { printf '%s\n' "$1" | sudo -n -u postgres psql -X -q -At -d postgres; }
prod_q()   { printf '%s\n' "$1" | sudo -n -u postgres psql -X -q -At -d "$SRC_DB" 2>/dev/null; }
review_password() { sudo -n awk -v u="$1" '$1==u{print $3}' /etc/tbox-v2/seed-accounts.txt 2>/dev/null; }
review_secret()   { sudo -n awk -v u="$1" '$1==u{print $2}' /etc/tbox-v2/qa-totp.txt 2>/dev/null; }
prod_alerts_lines() { sudo -n wc -l < "$PROD_ALERTS" 2>/dev/null | tr -d ' '; }
prod_totp_mtime()   { sudo -n stat -c %y /etc/tbox-v2/qa-totp.txt 2>/dev/null; }
# 로그인 — 2단계 기준선은 **임시 DB 에서** 비우고 지금 코드(같은 30초에 여러 계정)
login() {
  local who="$1" jar="$JAR_DIR/${2:-$1}.jar" pw sec raw code body out
  pw=$(review_password "$who"); sec=$(review_secret "$who")
  [ -n "$pw" ] || return 2
  psqlq "UPDATE users SET mfa_last_step = NULL, failed_count = 0, locked_until = NULL WHERE username = '$who'" >/dev/null
  if [ -n "$sec" ] && [ "$(psqlq "SELECT mfa_enabled FROM users WHERE username = '$who'")" = "t" ]; then
    # 30초 창 끝 4초 안이면 다음 창까지 기다린다(창 0 — 경계를 넘으면 코드가 틀린 것이 된다)
    [ $(( $(date +%s) % 30 )) -ge 26 ] && sleep $(( 31 - $(date +%s) % 30 ))
    raw=$(totp_now sec 2>/dev/null); code=${raw%%$'\n'*}
    printf -v body '{"username":"%s","password":"%s","otp":"%s"}' "$who" "$pw" "$code"
  else
    printf -v body '{"username":"%s","password":"%s"}' "$who" "$pw"
  fi
  out=$(curl_json_secret body -s -c "$jar" -A "$UA" -H 'Content-Type: application/json' "$BASE/api/login")
  case "$out" in *'"ok":true'*) return 0 ;; esac
  return 1
}
session_of() { awk '$6=="__Host-tbox_session"{print $7}' "$JAR_DIR/$1.jar" 2>/dev/null | tail -1; }

make_env() {   # $1 원본 $2 대상 $3 저장 폴더 $4 감사 파일 $5 APP_URL
  # qcheck-allow: R20 — 임시 인스턴스용 사본(0700 폴더 · 0600 · trap 정리) · kill -9 뒤 남는 창은 받아들인 위험(HO-B1-004)
  sed -e "s#^DB_NAME=.*#DB_NAME=$DB#" \
      -e "s#^STORAGE_DIR=.*#STORAGE_DIR=$3#" \
      -e "s#^AUDIT_LOG_FILE=.*#AUDIT_LOG_FILE=$4#" \
      -e "s#^SCAN_ENABLED=.*#SCAN_ENABLED=0#" \
      -e "s#^APP_URL=.*#APP_URL=$5#" \
      "$1" > "$2"
  chmod 600 "$2"
  grep -q "^DB_NAME=$DB\$" "$2" && ! grep -q '^DB_NAME=tbox_re$' "$2" \
    && grep -q "^STORAGE_DIR=$3\$" "$2" && grep -q "^AUDIT_LOG_FILE=$4\$" "$2" && grep -q "^APP_URL=$5\$" "$2"
}
make_router() {
  cat > "$1/router.php" <<'PHP'
<?php
$root = __DIR__ . '/public';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (is_string($path) && strpos($path, '/tbox/assets/') === 0) {
    $rel = substr($path, strlen('/tbox/assets/'));
    $file = realpath($root . '/assets/' . $rel);
    if ($file !== false && strpos($file, realpath($root . '/assets') . '/') === 0 && is_file($file)) {
        $types = ['css' => 'text/css', 'js' => 'text/javascript', 'svg' => 'image/svg+xml', 'png' => 'image/png', 'webmanifest' => 'application/manifest+json', 'json' => 'application/json', 'woff2' => 'font/woff2', 'html' => 'text/html'];
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
        readfile($file);
        return true;
    }
    http_response_code(404);
    return true;
}
require $root . '/index.php';
PHP
}
wait_up() {
  local i c=""
  for i in $(seq 1 30); do
    sleep 0.3
    c=$(curl -s -o /dev/null -w '%{http_code}' -A "$UA" "$1/tbox/login")
    [ "$c" = "200" ] && break
  done
  printf '%s' "$c"
}
start_inst() {   # $1 앱 폴더 $2 포트 $3 로그 파일
  ( cd "$1" && exec php -S "127.0.0.1:$2" -t public router.php > "$3" 2>&1 ) &
  PIDS+=("$!")
}

# ════════════════════════════════════════════════════════════════
hr "T:F-72 패스키 — 정적 확인(복제본 트리)"
BADLINT=0
for f in src/Passkey.php src/Auth.php src/Http.php src/Audit.php public/index.php src/community/routes.php scripts/alerts.php \
         src/handlers/passkey_login_options.php src/handlers/passkey_login.php src/handlers/me_passkeys_list.php \
         src/handlers/me_passkeys_options.php src/handlers/me_passkeys_register.php src/handlers/me_passkeys_revoke.php \
         src/handlers/reauth_passkey_options.php src/handlers/reauth_passkey.php src/handlers/site_manifest.php src/handlers/me.php \
         src/handlers/login_page.php src/handlers/settings_page.php src/handlers/password_change.php src/handlers/users_update.php \
         src/handlers/users_password_reset.php src/handlers/users_mfa_reset.php src/views/shell_top.php src/views/launcher.php \
         src/community/handlers/home_page.php tests/passkey_unit.php; do
  php -l "$ROOT/$f" >/dev/null 2>&1 || { BADLINT=$((BADLINT+1)); echo "    php -l 실패: $f"; }
done
expect "php -l — 바뀐·새 PHP 27개 오류 0" "0" "$BADLINT"
expect "bash -n tests/passkey.sh" "0" "$(bash -n "$ROOT/tests/passkey.sh" 2>&1 | wc -l | tr -d ' ')"
python3 -m py_compile "$ROOT/tests/passkey_api.py" "$ROOT/tests/lib/passkey_forge.py" "$ROOT/tests/browser/passkey.py" 2>/dev/null
expect "파이썬 셋 컴파일" "0" "$?"
LINT_OUT=$(cd "$ROOT" && bash scripts/security_lint.sh 2>&1); LINT_RC=$?
expect "보안 린트(저장소 전체) 종료 0" "0" "$LINT_RC"
[ "$LINT_RC" = "0" ] || printf '%s\n' "$LINT_OUT" | tail -8 | sed 's/^/    /'
CAV=$(cd "$ROOT" && php scripts/community_asset_version.php --check 2>&1); rc=$?
expect "커뮤니티 부품 import 버전 확인(--check) 0" "0" "$rc"
[ "$rc" = "0" ] || printf '%s\n' "$CAV" | tail -4 | sed 's/^/    /'
expect "passkey.js — innerHTML·outerHTML·insertAdjacentHTML·document.write·window.prompt·window.confirm·alert( 0" "0" \
  "$(grep -v '^\s*\(//\|\*\|/\*\)' "$ROOT/public/assets/passkey.js" | grep -cE 'innerHTML|outerHTML|insertAdjacentHTML|document\.write|window\.prompt\(|window\.confirm\(|[^.a-zA-Z]alert\(|[^a-zA-Z]confirm\(|[^a-zA-Z]prompt\(' | tr -d ' ')"
expect "새 코드에 Fetch:: 호출 0(외부 요청 0)" "0" \
  "$(cd "$ROOT" && cat src/Passkey.php src/handlers/passkey_*.php src/handlers/me_passkeys_*.php src/handlers/reauth_passkey*.php src/handlers/site_manifest.php | grep -c 'Fetch::' | tr -d ' ')"
expect "RP ID·origin 을 요청 머리에서 만들지 않는다 — Passkey.php 에 HTTP_HOST·HTTP_ORIGIN·HTTP_REFERER·X_FORWARDED 0" "0" \
  "$(grep -cE 'HTTP_HOST|HTTP_ORIGIN|HTTP_REFERER|X_FORWARDED|SERVER_NAME' "$ROOT/src/Passkey.php" | tr -d ' ')"
expect "감사 detail 키 이름에 passkey·token·session·hash 0(새 키 다섯)" "0" \
  "$(grep -oE "'(credential_no|credential_label|device_hint|backed_up|credentials_revoked)'" "$ROOT/src/Audit.php" | grep -ciE 'passkey|token|session|hash' | tr -d ' ')"
ROUTES=$(php -r 'define("TBOX_BASE","/tbox"); $s = file_get_contents($argv[1]); preg_match_all("#\[\x27(GET|POST|DELETE|PATCH)\x27,\s*\x27([^\x27]+)\x27,\s*\x27([a-z_]+\.php)\x27,\s*([A-Za-z:_\x27]+)\]#", $s, $m, PREG_SET_ORDER); foreach ($m as $r) { if (preg_match("#passkey|^/app\.webmanifest$#", $r[2])) { echo $r[1], " ", $r[2], " ", $r[4], "\n"; } }' "$ROOT/public/index.php")
expect "라우트 아홉(로그인 둘 public · 내 것 넷 · 재인증 둘 authenticated · 매니페스트 public)" \
  "POST /api/login/passkey/options Authz::POLICY_PUBLIC|POST /api/login/passkey Authz::POLICY_PUBLIC|GET /api/me/passkeys Authz::POLICY_AUTHENTICATED|POST /api/me/passkeys/options Authz::POLICY_AUTHENTICATED|POST /api/me/passkeys Authz::POLICY_AUTHENTICATED|DELETE /api/me/passkeys/{num} Authz::POLICY_AUTHENTICATED|POST /api/me/reauth/passkey/options Authz::POLICY_AUTHENTICATED|POST /api/me/reauth/passkey Authz::POLICY_AUTHENTICATED|GET /app.webmanifest Authz::POLICY_PUBLIC" \
  "$(printf '%s' "$ROUTES" | paste -sd'|')"
BASE_REV="${PK_BASE_REV:-f106e30}"   # 운영 판(2026-09-27 — fd96aca 위에 d75 nginx 비밀 창구 넷)
for c in TBOX_API_TOKEN_ROUTES TBOX_API_TOKEN_ONLY_ROUTES TBOX_API_TOKEN_PERSONAL_ROUTES TBOX_GATE_ALLOW_PASSWORD_CHANGE TBOX_GATE_ALLOW_MFA_SETUP; do
  a=$(git -C "$ROOT" show "$BASE_REV:public/index.php" 2>/dev/null | awk -v c="const $c = \\\\[" '$0 ~ c{f=1} f{print} f && /^\];/{exit}' | md5sum)
  b=$(awk -v c="const $c = \\\\[" '$0 ~ c{f=1} f{print} f && /^\];/{exit}' "$ROOT/public/index.php" | md5sum)
  expect "  $c 불변(기준 판 $BASE_REV 과 글자 같음)" "$a" "$b"
done
for f in tbox-v2.conf tbox-v2-local.conf; do
  n=0
  for p in /tbox/api/login/passkey/options /tbox/api/login/passkey /tbox/api/me/passkeys/options /tbox/api/me/reauth/passkey/options /tbox/api/me/reauth/passkey; do
    for q in "$p" "$p/"; do
      awk -v loc="location = $q {" 'index($0, loc) {f=1; next} f && /limit_req zone=tbox_login burst=10 nodelay;/ {print "y"; exit} f && /^    }/ {exit}' "$ROOT/deploy/sites/$f" | grep -q y && n=$((n+1))
    done
  done
  expect "nginx $f — 다섯 창구 × 끝 슬래시 = 10 자리에 tbox_login burst=10" "10" "$n"
done

# ════════════════════════════════════════════════════════════════
hr "PK-T1 해석기 단위(파이썬 cryptography 표본 — 구현과 다른 코드)"
python3 "$ROOT/tests/lib/passkey_forge.py" --samples localhost "$SITE" > "$WORK/forge-samples.json" 2>"$WORK/forge.err"
expect "파이썬 표본 파일(등록 3 · 로그인 3 · 거부 목록)" "3 3" "$(python3 -c 'import json,sys; d=json.load(open(sys.argv[1])); print(len(d["register"]), len(d["login"]))' "$WORK/forge-samples.json" 2>/dev/null)"
php "$ROOT/tests/passkey_unit.php" "$WORK/forge-samples.json" > "$WORK/unit.out" 2>&1; rc=$?
expect "tests/passkey_unit.php 종료 0 · $(tail -1 "$WORK/unit.out")" "0" "$rc"
grep '✖' "$WORK/unit.out" | head -10 | sed 's/^/    /'

# ════════════════════════════════════════════════════════════════
hr "임시 인스턴스 준비 (운영 DB 에는 쓰지 않는다)"
for p in "$PORT" "$PORT_IP" "$PORT_ALT"; do
  if ss -ltn 2>/dev/null | grep -q "127.0.0.1:$p "; then echo "포트 $p 이 이미 쓰이고 있다 — 중단"; exit 3; fi
done
ENV_SRC="${PK_ENV_SRC:-$ROOT/.env}"
[ -f "$ENV_SRC" ] || { echo "임시 .env 원본이 없다 — PK_ENV_SRC 로 0600 사본을 넘긴다"; exit 3; }
FREE_MB=$(df -Pm / | awk 'NR==2{print $4}')
[ "${FREE_MB:-0}" -ge 1200 ] || { echo "루트 여유가 1.2GB 미만(${FREE_MB}MB) — 시작하지 않는다"; exit 3; }
PROD_A0=$(prod_q "SELECT coalesce(max(id),0) FROM audit_logs")
[[ "$PROD_A0" =~ ^[0-9]+$ ]] || { echo "psql(postgres) 로 운영 DB 를 읽을 수 없다"; exit 3; }
PROD_L0=$(prod_alerts_lines)
PROD_T0=$(prod_totp_mtime)
note "운영 감사 max(id) 시작 = ${PROD_A0:-?} · 운영 alerts.log 줄 수 시작 = ${PROD_L0:-?} · qa-totp mtime 시작 = ${PROD_T0:-?} (읽기만)"

sub "① 운영 DB 를 **읽어** 임시 DB 를 만든다(파이프) + 마이그레이션(2026-10-01~10-06 — 패스키 표 포함)"
pg_admin "DROP DATABASE IF EXISTS $DB;
CREATE DATABASE $DB OWNER tbox_owner;" >/dev/null 2>&1 || { echo "임시 DB 를 만들 수 없다"; exit 3; }
sudo -n -u postgres pg_dump -d "$SRC_DB" 2>/dev/null | sudo -n -u postgres psql -X -q -At -d "$DB" >/dev/null 2>"$WORK/restore.err"
[ -n "$(psqlq "SELECT count(*) FROM files")" ] || { echo "임시 DB 로 옮기지 못했다 — $(head -2 "$WORK/restore.err")"; exit 3; }
{ printf '\\set ON_ERROR_STOP on\n'
  for m in 2026-10-01_qf2_search.sql 2026-10-02_qf2b_files_search.sql 2026-10-03_qf3a_favorites_recent.sql 2026-10-04_users_temp_password_issued_at.sql 2026-10-05_qf3b_purge_favorites.sql 2026-10-06_passkeys.sql; do
    [ -f "$ROOT/db/migrations/$m" ] && cat "$ROOT/db/migrations/$m" && printf '\n'
  done; } | sudo -n -u postgres psql -X -q -At -d "$DB" > "$WORK/mig.out" 2>&1
grep -v 'could not change directory\|already exists, skipping\|^$' "$WORK/mig.out" | head -5 | sed 's/^/    /'
expect "마이그레이션 뒤 패스키 표 둘" "t|t" "$(psqlq "SELECT (to_regclass('public.passkeys') IS NOT NULL)::text || '|' || (to_regclass('public.passkey_challenges') IS NOT NULL)::text" | sed 's/true/t/g')"
# 마이그레이션을 한 번 더(여러 번 돌려도 같다)
printf '\\set ON_ERROR_STOP on\n%s\n' "$(cat "$ROOT/db/migrations/2026-10-06_passkeys.sql")" | sudo -n -u postgres psql -X -q -At -d "$DB" > /dev/null 2>"$WORK/mig2.err"; rc=$?
expect "마이그레이션 두 번째 실행도 종료 0(IF NOT EXISTS · 회수 뒤 GRANT)" "0" "$rc"
psqlq "UPDATE users SET failed_count = 0, locked_until = NULL, mfa_last_step = NULL WHERE username LIKE 'claude\\_%'" >/dev/null
sudo -n awk 'BEGIN { print "\\set ON_ERROR_STOP on" } $1 ~ /^claude_[a-z0-9]+$/ && $2 ~ /^[A-Z2-7]+$/ { printf "UPDATE users SET mfa_secret = %c%s%c, mfa_last_step = NULL WHERE username = %c%s%c;\n", 39, $2, 39, 39, $1, 39 }' /etc/tbox-v2/qa-totp.txt \
  | sudo -n -u postgres psql -X -q -At -d "$DB" >/dev/null 2>&1
note "임시 DB $DB — 파일 $(psqlq "SELECT count(*) FROM files") · 감사 $(psqlq "SELECT count(*) FROM audit_logs") · claude_ 2단계 비밀은 파일 값에 맞춤(값은 찍지 않는다)"

sub "② 인스턴스 셋 — $SITE(APP_URL 같은 값) · http://127.0.0.1:$PORT_IP(APP_URL 이 IP — 기능 꺼짐) · http://localhost:$PORT_ALT(APP_URL 은 $SITE — 다른 origin)"
INST="$(mktemp -d)"; chmod 700 "$INST"
mkdir -p "$INST/app" "$INST/storage" "$INST/log" "$INST/ip" "$INST/alt"
tar -C "$ROOT" -cf - --exclude=.git --exclude=jar --exclude=api-test.txt --exclude=./.env --exclude=./.claude --exclude=__pycache__ . | tar -C "$INST/app" -xf -
make_env "$ENV_SRC" "$INST/app/.env" "$INST/storage" "$INST/log/audit.log" "$SITE" || { echo "임시 .env 에 임시 값이 들어가지 않았다 — 중단"; exit 4; }
expect "임시 .env — DB_NAME 이 tbox_re 가 아니다(grep '^DB_NAME=')" "DB_NAME=$DB" "$(grep '^DB_NAME=' "$INST/app/.env")"
expect "임시 .env — 감사 파일(=경보 로그 폴더)이 임시 폴더 안" "AUDIT_LOG_FILE=$INST/log/audit.log" "$(grep '^AUDIT_LOG_FILE=' "$INST/app/.env")"
expect "임시 .env — APP_URL = $SITE" "APP_URL=$SITE" "$(grep '^APP_URL=' "$INST/app/.env")"
: > "$INST/log/audit.log"; chmod 640 "$INST/log/audit.log"
ln -s /var/www/qaredi/community "$INST/community" 2>/dev/null
make_router "$INST/app"
# IP 설정 · 다른 origin 인스턴스 — 같은 앱의 사본(복사 — 링크면 bootstrap 의 __DIR__ 가 원래 폴더의 .env 를 읽는다) · .env 만 다르다(DB 는 같은 임시 DB)
for d in ip alt; do
  mkdir -p "$INST/$d/app"
  tar -C "$INST/app" -cf - --exclude=./.env . | tar -C "$INST/$d/app" -xf -
done
ln -s /var/www/qaredi/community "$INST/ip/community" 2>/dev/null; ln -s /var/www/qaredi/community "$INST/alt/community" 2>/dev/null
make_env "$ENV_SRC" "$INST/ip/app/.env" "$INST/storage" "$INST/log/audit.log" "http://127.0.0.1:$PORT_IP" || { echo "IP 인스턴스 .env — 중단"; exit 4; }
make_env "$ENV_SRC" "$INST/alt/app/.env" "$INST/storage" "$INST/log/audit.log" "$SITE" || { echo "다른 origin 인스턴스 .env — 중단"; exit 4; }
start_inst "$INST/app" "$PORT" "$INST/server.log"
start_inst "$INST/ip/app" "$PORT_IP" "$INST/server-ip.log"
start_inst "$INST/alt/app" "$PORT_ALT" "$INST/server-alt.log"
expect "인스턴스 $PORT 가 떴다(localhost 로 로그인 화면 200)" "200" "$(wait_up "$SITE")"
expect "인스턴스 $PORT_IP 가 떴다" "200" "$(wait_up "http://127.0.0.1:$PORT_IP")"
expect "인스턴스 $PORT_ALT 가 떴다" "200" "$(wait_up "http://localhost:$PORT_ALT")"
server_errs() { cat "$INST"/server*.log 2>/dev/null | grep -ciE 'PHP (Fatal|Warning|Notice|Deprecated)|SQLSTATE|Uncaught'; }
errs_before=$(server_errs)

sub "로그인 (claude_ 검토 계정 다섯, F-71)"
for who in claude_employee01 claude_employee02 claude_manager01 claude_admin01 claude_guest01; do
  login "$who" || { echo "  ✖ $who 로그인 실패 — 중단"; exit 4; }
done
S_E1=$(session_of claude_employee01); S_E2=$(session_of claude_employee02); S_M1=$(session_of claude_manager01)
S_A1=$(session_of claude_admin01);   S_G1=$(session_of claude_guest01)
PW_E1=$(review_password claude_employee01); PW_E2=$(review_password claude_employee02); PW_M1=$(review_password claude_manager01)
PW_A1=$(review_password claude_admin01);   PW_G1=$(review_password claude_guest01)
SC_E1=$(review_secret claude_employee01); SC_E2=$(review_secret claude_employee02); SC_M1=$(review_secret claude_manager01)
SC_A1=$(review_secret claude_admin01);   SC_G1=$(review_secret claude_guest01)
expect "세션 다섯(64자 hex)" "5" "$(for s in "$S_E1" "$S_E2" "$S_M1" "$S_A1" "$S_G1"; do [[ "$s" =~ ^[0-9a-f]{64}$ ]] && echo y; done | wc -l | tr -d ' ')"

# ════════════════════════════════════════════════════════════════
if [ "$SKIP_MAIN" != "1" ]; then
hr "PK-T2~T9 · B1-1 서버 — 창구 몸통(tests/passkey_api.py · 소프트웨어 인증기)"
py_secret S_E1 S_E2 S_M1 S_A1 S_G1 PW_E1 PW_E2 PW_M1 PW_A1 PW_G1 SC_E1 SC_E2 SC_M1 SC_A1 SC_G1 -- \
  python3 "$ROOT/tests/passkey_api.py" "$SITE" "$UA" "$DB" "$INST" "$WORK" "http://127.0.0.1:$PORT_IP" > "$WORK/api.out" 2>&1
rc=$?
grep -E '^(  [✔✖]|── )' "$WORK/api.out"
line=$(grep '^API_PASS=' "$WORK/api.out" | tail -1)
[ -n "$line" ] || { echo "    (결과 줄 없음 — 끝부분)"; tail -15 "$WORK/api.out" | sed 's/^/    /'; }
AP=$(sed -n 's/^API_PASS=\([0-9]*\).*/\1/p' <<<"$line"); AF=$(sed -n 's/.*API_FAIL=\([0-9]*\).*/\1/p' <<<"$line")
PASS=$((PASS + ${AP:-0})); FAIL=$((FAIL + ${AF:-1}))
expect "창구 몸통 종료 0" "0" "$rc"

# ════════════════════════════════════════════════════════════════
hr "PK-T10~T13 · B1-1 화면 — 크롬 가상 인증기(tests/browser/passkey.py)"
# 브라우저 쪽 세션은 새로 — 위 창구 시험이 비밀번호를 바꾸고 되돌리며 세션을 끊었다
for who in claude_employee01 claude_employee02 claude_admin01 claude_guest01; do login "$who" "b-$who" || echo "  ✖ $who 다시 로그인 실패"; done
S_E1=$(session_of b-claude_employee01); S_E2=$(session_of b-claude_employee02); S_A1=$(session_of b-claude_admin01); S_G1=$(session_of b-claude_guest01)
mkdir -p "$SHOTS"
py_secret S_E1 S_E2 S_A1 S_G1 PW_E1 PW_E2 PW_A1 SC_E1 SC_E2 SC_A1 -- \
  "${BWRAP[@]}" python3 "$ROOT/tests/browser/passkey.py" "$SITE" "$UA" "$SHOTS" "$DB" "$WORK" "http://localhost:$PORT_ALT" > "$WORK/browser.out" 2>&1
rc=$?
grep -E '^(  [✔✖·]|── )' "$WORK/browser.out"
BF=$(sed -n 's/^SMOKE_FAIL=\([0-9]*\).*/\1/p' "$WORK/browser.out" | tail -1)
BP=$(sed -n 's/^SMOKE_PASS=\([0-9]*\).*/\1/p' "$WORK/browser.out" | tail -1)
[ -n "$BF" ] || { echo "    (결과 줄 없음 — 끝부분)"; tail -15 "$WORK/browser.out" | sed 's/^/    /'; }
PASS=$((PASS + ${BP:-0})); FAIL=$((FAIL + ${BF:-1}))
expect "브라우저 몸통 종료 0" "0" "$rc"
note "스크린샷: $SHOTS (PK_KEEP_SHOTS=1 이면 남긴다)"

if [ -s "$WORK/va-samples.json" ]; then
  php "$ROOT/tests/passkey_unit.php" "$WORK/va-samples.json" "$WORK/forge-samples.json" > "$WORK/unit-va.out" 2>&1; rc=$?
  expect "PK-T1 가상 인증기 표본(등록·로그인)을 PHP 해석기가 통과 — $(tail -1 "$WORK/unit-va.out")" "0" "$rc"
  grep '✖' "$WORK/unit-va.out" | head -8 | sed 's/^/    /'
else
  expect "PK-T1 가상 인증기 표본 파일이 만들어졌다" "yes" "no"
fi
else
  note "PK_SKIP_MAIN=1 — PK-T2~T13(창구·화면 몸통)을 건너뛴다(정적·PK-T1·회귀만)"
fi

errs_after=$(server_errs)
expect "서버 로그의 새 PHP 오류·SQLSTATE 줄(의도한 500 한 건 — 트랜잭션 흉내 트리거 — 을 뺀 수)" "0" \
  "$(cat "$INST"/server*.log 2>/dev/null | grep -iE 'PHP (Fatal|Warning|Notice|Deprecated)|SQLSTATE|Uncaught' | grep -vc 'pk test fail' | tr -d ' ')"
cat "$INST"/server*.log 2>/dev/null | grep -iE 'PHP (Fatal|Warning|Notice|Deprecated)|SQLSTATE|Uncaught' | grep -v 'pk test fail' | tail -5 | sed 's/^/    /'

# ════════════════════════════════════════════════════════════════
if [ "${REGRESS:-0}" = "1" ]; then
  hr "회귀 — 같은 임시 인스턴스에서(dbg_s2_security.sh 의 회귀 틀 그대로 · 주소는 http://127.0.0.1:$PORT)"
  RSITE="http://127.0.0.1:$PORT"
  sed -i "s#http://127.0.0.1:8082#$RSITE#g" "$INST/app/scripts/ui_snapshot.sh" 2>/dev/null
  CAT_BLOCK="$WORK/catalog_block.txt"
  python3 - "$INST/app/tests/catalog.json" > "$CAT_BLOCK" <<'PYCAT'
import json, sys
d = json.load(open(sys.argv[1], encoding='utf-8'))
for t in d.get('tests', []):
    if t.get('writes_prod') is True or t.get('status') == 'manual_only':
        c = t.get('cmd', '')
        if c.startswith('bash tests/') and c.endswith('.sh'):
            print(c[len('bash tests/'):-3])
PYCAT
  [ -s "$CAT_BLOCK" ] || { echo "  ✖ 카탈로그에서 거부 목록을 못 읽었다 — 회귀를 돌리지 않는다"; FAIL=$((FAIL+1)); REGRESS_LIST=" "; }
  note "카탈로그 거부 목록(writes_prod·manual_only): $(tr '\n' ' ' < "$CAT_BLOCK")"
  psqlq "UPDATE users SET failed_count = 0, locked_until = NULL WHERE username LIKE 'claude\\_%'" >/dev/null
  for s in ${REGRESS_LIST:-help_button api_whoami api_read f68 q_launcher}; do
    src="$INST/app/tests/$s.sh"; dst="$INST/app/tests/$s.temp.sh"
    [ -f "$src" ] || { echo "  ✖ $s — 스크립트가 없다"; FAIL=$((FAIL+1)); continue; }
    if grep -qxF "$s" "$CAT_BLOCK"; then echo "  ✖ $s — 카탈로그상 writes_prod·manual_only — 돌리지 않는다"; FAIL=$((FAIL+1)); continue; fi
    sed -e "s#http://127.0.0.1:8082#$RSITE#g" -e "s#^DB=\"tbox_re\"#DB=\"$DB\"#" -e "s#-d tbox_re #-d $DB #g" \
        -e "s#^TMPDB=\"tbox_\([a-z0-9_]*\)\"#TMPDB=\"tbox_d3p_\1\"#" "$src" > "$dst"
    grep -v '^\s*#' "$dst" > "$WORK/nc_$s.txt" || true
    if grep -q '127\.0\.0\.1:8082\|localhost:8082\|DB="tbox_re"\|-d tbox_re' "$WORK/nc_$s.txt"; then
      echo "  ✖ $s — 운영 주소·DB 가 남아 있어 돌리지 않는다"; FAIL=$((FAIL+1)); continue
    fi
    if grep -qE 'sudo[^|;&]*[[:space:]](install|cp|mv|tee|rm|ln|chmod|chown|chattr|truncate|dd|touch|mkdir|rmdir|sed[[:space:]]+-i)[[:space:]][^|;&]*(/etc/|/var/lib/tbox-v2|/var/log/tbox-v2|/var/backups)|>>?[[:space:]]*"?(/etc/|/var/lib/tbox-v2|/var/log/tbox-v2|/var/backups)|tee[^|;&]*(/etc/|/var/lib/tbox-v2|/var/log/tbox-v2|/var/backups)' "$WORK/nc_$s.txt"; then
      echo "  ✖ $s — 운영 /etc·/var 에 쓰는 줄 — 돌리지 않는다"; FAIL=$((FAIL+1)); continue
    fi
    echo "  ▸ $s (시작 $(date +%T))"
    "${BWRAP[@]}" env REGRESS=0 REGRESS_TEMP=0 bash "$dst" > "$WORK/regress_$s.txt" 2>&1; rc=$?
    line=$(grep -E '통과 [0-9]+|PASS|통과:|결과' "$WORK/regress_$s.txt" | tail -1)
    echo "    종료 코드 $rc · ${line:-결과 줄 없음}"
    grep -E '^\s*(✖|FAIL)' "$WORK/regress_$s.txt" | head -12 | sed 's/^/      /'
    [ -n "${REGRESS_KEEP:-}" ] && cp "$WORK/regress_$s.txt" "$REGRESS_KEEP/"
    [ "$rc" = "0" ] && PASS=$((PASS+1)) || FAIL=$((FAIL+1))
  done
fi

if [ "${REGRESS_TEMP:-0}" = "1" ]; then
  hr "회귀 — 자기 임시 인스턴스를 띄우는 시험(이 묶음 규칙으로 고친 사본 · 포트 8304~8309 · 임시 DB 접두 tbox_d3p_)"
  HEAD_REV=$(git -C "$ROOT" rev-parse --short HEAD)
  [ -z "$(git -C "$ROOT" status --porcelain -- src scripts public tests config 2>/dev/null)" ] \
    || note "복제본에 커밋 안 된 변경이 있다 — 사본은 HEAD($HEAD_REV) 판으로 돈다"
  TC="$WORK/tc"
  git clone -q --shared --no-checkout "$ROOT" "$TC" && git -C "$TC" checkout -q --detach "$HEAD_REV" \
    || { echo "  ✖ 임시 복제를 만들지 못했다"; FAIL=$((FAIL+1)); REGRESS_TEMP_LIST=" "; }
  # qcheck-allow: R20 — 임시 .env 사본(0700 WORK 안 · 0600 · trap 정리)
  ( umask 077; cp "$INST/app/.env" "$TC/.env" 2>/dev/null ) && chmod 600 "$TC/.env"
  DROPS=""
  for s in ${REGRESS_TEMP_LIST:-dbg_s2_security dbg_s_security qf3c_zip dbg_u_ui community/stage7d1}; do
    src="$TC/tests/$s.sh"; dst="$TC/tests/$s.d3p.sh"
    [ -f "$src" ] || { echo "  ✖ $s — 스크립트가 없다"; FAIL=$((FAIL+1)); continue; }
    case "$s" in
      dbg_s2_security)
        sed -e 's#case "$PORT" in 826\[1-9\])#case "$PORT" in 830[4-9])#' -e 's#^DB="tbox_d2s_${PORT}_test"#DB="tbox_d3p_s2_${PORT}_test"#' \
            -e 's#case "$DB" in tbox_d2s_\*_test)#case "$DB" in tbox_d3p_*_test)#' "$src" > "$dst"
        ENVS="DBGS2_PORT=8304 DBGS2_ENV_SRC=$TC/.env DBGS2_SYNC_TOTP=1" ;;
      dbg_s_security)
        sed -e 's#case "$PORT" in 821\[1-9\])#case "$PORT" in 830[4-9])#' -e 's#^DB="tbox_d1s_${PORT}_test"#DB="tbox_d3p_s_${PORT}_test"#' \
            -e 's#case "$DB" in tbox_d1s_\*_test)#case "$DB" in tbox_d3p_*_test)#' "$src" > "$dst"
        ENVS="DBGS_PORT=8305" ;;
      qf3c_zip)
        sed -e 's#^DB="tbox_qf3c_${PORT}_test"#DB="tbox_d3p_qf3c_${PORT}_test"#' "$src" > "$dst"
        ENVS="QF3C_PORT=8304 QF3C_BURST_PORT=8305 QF3C_SMALL_PORT=8306 QF3C_OLD_PORT=8307 QF3C_TIME_PORT=8308 QF3C_SHM_PORT=8309" ;;
      dbg_u_ui)
        sed -e 's#^DB="tbox_d1u_${PORT}_test"#DB="tbox_d3p_u_${PORT}_test"#' "$src" > "$dst"
        ENVS="DBGU_PORT=8306" ;;
      dbg_u2_ui)
        sed -e 's#^DB="tbox_d2u_${PORT}_test"#DB="tbox_d3p_u2_${PORT}_test"#' "$src" > "$dst"
        ENVS="DBGU2_PORT=8308 QF2_ENV_SRC=$TC/.env DBGU2_SYNC_TOTP=1" ;;
      community/stage*)
        sed -E -e "s#^PORT=[0-9]+\$#PORT=8307#" -e 's#^DB="tbox_([a-z0-9]+)_test"#DB="tbox_d3p_\1_test"#' "$src" > "$dst"
        grep -qx "PORT=8307" "$dst" || { echo "  ✖ $s — 포트 줄을 바꾸지 못했다"; FAIL=$((FAIL+1)); continue; }
        ENVS="REGRESS=0" ;;
      *) echo "  ✖ $s — 이 틀이 고치는 법을 모르는 시험"; FAIL=$((FAIL+1)); continue ;;
    esac
    grep -v '^\s*#' "$dst" > "$WORK/nct.txt" || true
    if grep -qE '^DB="tbox_(qf3c|d1s|d1u|d2s|d2u|7d1|5a2|7b)_' "$WORK/nct.txt" || ! grep -qE '^DB="tbox_d3p_' "$WORK/nct.txt"; then
      echo "  ✖ $s — 임시 DB 이름을 tbox_d3p_ 로 바꾸지 못했다 — 돌리지 않는다"; FAIL=$((FAIL+1)); continue
    fi
    DROPS="$DROPS $(sed -n 's#^DB="\(tbox_d3p_[a-z0-9_${}]*\)"$#\1#p' "$dst" | head -1)"
    echo "  ▸ $s (시작 $(date +%T) · ${ENVS%% DBGS2_ENV_SRC*})"
    ( cd "$TC" && "${BWRAP[@]}" env REGRESS=0 REGRESS_TEMP=0 REGRESS_LIST= $ENVS bash "$dst" ) > "$WORK/regt_$(basename "$s").txt" 2>&1; rc=$?
    line=$(grep -E '통과 [0-9]+|PASS|통과:|결과' "$WORK/regt_$(basename "$s").txt" | tail -1)
    echo "    종료 코드 $rc · ${line:-결과 줄 없음}"
    grep -E '^\s*(✖|FAIL)' "$WORK/regt_$(basename "$s").txt" | head -15 | sed 's/^/      /'
    [ -n "${REGRESS_KEEP:-}" ] && cp "$WORK/regt_$(basename "$s").txt" "$REGRESS_KEEP/"
    [ "$rc" = "0" ] && PASS=$((PASS+1)) || FAIL=$((FAIL+1))
  done
  DSQL=""
  for d in tbox_d3p_s2_8304_test tbox_d3p_s_8305_test tbox_d3p_qf3c_8304_test tbox_d3p_u_8306_test tbox_d3p_u2_8308_test tbox_d3p_7d1_test tbox_d3p_5a2_test tbox_d3p_7b_test; do
    DSQL="$DSQL
DROP DATABASE IF EXISTS $d;"
  done
  pg_admin "$DSQL" >/dev/null 2>&1
fi

hr "결과"
# 운영 읽기(끝 한 번) — 이 시험이 운영에 닿았다면 생길 행: 패스키 행동·사유(운영 코드에는 아직 없다). 같은 날 검토 UA 로 일한
# 다른 세션의 행은 따로 센다(그 수는 이 시험과 무관 — 2026-09-26 23:3x 가이드 올리기 세션이 같은 UA 를 썼다).
PROD_END=$(prod_q "SELECT coalesce(max(id),0) || ' ' || count(*) FILTER (WHERE id > ${PROD_A0:-0} AND (action LIKE 'auth.passkey%' OR reason LIKE 'passkey%' OR reason = 'forced_step')) || ' ' || count(*) FILTER (WHERE id > ${PROD_A0:-0} AND user_agent = '$UA') FROM audit_logs")
PROD_A1=${PROD_END%% *}; PROD_REST=${PROD_END#* }; PROD_MINE=${PROD_REST%% *}; PROD_OTHERS=${PROD_REST#* }
PROD_L1=$(prod_alerts_lines)
PROD_T1=$(prod_totp_mtime)
note "운영 감사 max(id) 끝 = ${PROD_A1:-?} (시작 ${PROD_A0:-?}) · 운영 alerts.log 끝 = ${PROD_L1:-?}줄 (시작 ${PROD_L0:-?}) · qa-totp mtime 끝 = ${PROD_T1:-?} (시작 ${PROD_T0:-?})"
note "  (운영 감사·경보가 늘었다면 운영 cron 이 이 시험의 sudo psql(임시 DB)을 AL-9 로 적은 것일 수 있다 — 읽기만)"
expect "운영 감사에 패스키 행(auth.passkey_* · 사유 passkey_* · forced_step) 0 — 이 시험은 운영에 요청하지 않는다" "0" "${PROD_MINE:-?}"
note "  같은 날 검토 UA($UA)의 운영 행 ${PROD_OTHERS:-?}줄 — 다른 세션(운영 스모크·가이드 올리기 등)의 것, 이 시험의 창구(패스키)는 0"
expect "운영 /etc/tbox-v2/qa-totp.txt mtime 그대로" "$PROD_T0" "$PROD_T1"
printf '통과 %d · 실패 %d\n' "$PASS" "$FAIL"
note "임시 DB($DB)·임시 폴더·임시 서버는 이 스크립트가 끝나며 지워진다. 운영에는 아무것도 남지 않는다."
[ "$FAIL" -eq 0 ] || exit 1
exit 0
