#!/usr/bin/env bash
# tests/dbg_s2_security.sh — 디버깅 묶음 S2 수락 시험 (사용자 결정 d72 ① 「불편 없는 것부터」 + 2026-09-26 검토에서 남은 보안 낮음 —
#                            구현 기록 docs/debug-0926-s2-implementation.md)
#
#   A 쿠키 이름     로그인 응답 Set-Cookie 한 줄 = `__Host-tbox_session` · 64자 hex · Path=/ · Secure · HttpOnly · SameSite=Lax ·
#                  Domain·Expires·Max-Age 없음 / 새 이름으로 t-box·커뮤니티 창구·화면 200 / **옛 이름**(`tbox_session`, 값은 살아 있는
#                  토큰)으로는 401·302 + 옛 이름 만료 머리(Max-Age=0 · 같은 속성) / 둘 다 실리면 새 이름으로 200 + 옛 이름만 치움 /
#                  이름 흉내(`__host-…` 소문자 · `__Secure-…`)는 세션 아님 / 로그아웃은 새 이름 만료 · 그 토큰 401 /
#                  옛 쿠키를 든 채 로그인하면 새 쿠키 + 옛 쿠키 만료 / 브라우저(Chromium)가 http 127.0.0.1 에서 받은 `__Host-` 쿠키를
#                  저장·전송(커뮤니티 화면까지) · 옛 쿠키만 있으면 로그인 화면으로 가고 옛 쿠키가 지워진다 / 시험 틀 전체가 같은 이름
#   B 시도 제한     2단계 코드 틀림 5회 → 5×401 · 6번째 423 · 잠금 행 `auth.lock` 정확히 1 / 쓴 코드 다시 → 401 code_reused · 실패 +1 /
#                  로그인 뒤 창구(재인증) 비밀번호 5회 → 잠금 + 그 계정 세션 전부 끊김 + `auth.lock`(actor 그 계정) 1 /
#                  **정확히 5번만 틀린 잠금 DoS** → 새 AL-2 가 잡는다(dry-run 1줄 · 실제 1줄 + alert.raised) · 기준 판 alerts.php 는 0줄 /
#                  nginx 지킴(값 확인만 — 저장소 deploy 와 /etc 설치본이 같은지 읽어서 대조)
#   C 런처          미로그인 `/` — 사이트 이름 + 로그인 단추만(앱 이름·설명·경로·타일 0 · Set-Cookie 0) · 로그인 뒤 타일 둘 그대로 ·
#                  손님도 로그인 뒤 타일 · 옛 쿠키만이면 로그인 전 화면 + 옛 쿠키 만료
#   D 낮음          손님(자기 공간 주인)의 커뮤니티 창구 쓰기 값 전부 거짓(페이지 · DB 행 · 공간 목록 · 페이지 목록 · 판 목록 · 공유 목록) —
#                  같은 계정이 직원일 때는 참(헛돌지 않는 대조) · 쓰기 창구는 여전히 403 /
#                  root·posix 없는 CLI 의 감사 파일 사본이 실패해도(링크) 경고 한 줄 · DB 감사 행 · 회차 계속 · 링크 끝 그대로 /
#                  posix 가 없으면 경보 상태 파일을 믿지 않고(경고) 경보 로그에도 쓰지 않는다(alerts_log_failed) · getmyuid 호출 0
#   정적            php -l · 보안 린트 · bash -n · 서버 로그 새 오류 0
#   회귀(선택)      REGRESS=1 — 같은 임시 인스턴스에서 REGRESS_LIST(기본 help_button api_whoami api_read api_folders f68 q_launcher)
#                  (카탈로그 writes_prod·manual_only 는 기본에서 빠지고 이름을 줘도 거부 — dbg_s_security.sh 와 같은 틀)
#                  REGRESS_TEMP=1 — 자기 임시 인스턴스를 띄우는 시험(REGRESS_TEMP_LIST 기본 qf3c_zip dbg_s_security dbg_u_ui
#                  community/stage6a community/stage7d1)을 **이 묶음 규칙**(포트 8262~8269 · 임시 DB 접두 tbox_d2s_)으로 고친 사본으로
#                  돌린다. 사본은 저장소를 --shared 로 복제한 임시 폴더(HEAD 판)에서 돈다 — 원래 복제본은 checkout·reset 0
#
# ★ 운영 DB·운영 포트를 건드리지 않는다 — 임시 DB `tbox_d2s_<포트>_test`(운영 pg_dump 를 **읽어** 파이프로 붓기만) · 임시 인스턴스
#   `php -S 127.0.0.1:<포트>`(8261~8269 만 — 기본 8261). 검토 계정 claude_ 다섯(F-71). 비밀은 표준입력·0600 임시 파일로만(R-62).
#   임시 .env 원본 — 복제본에는 .env 가 없다: mktemp 폴더(0700)의 0600 사본을 DBGS2_ENV_SRC 로 넘기고 끝에 지운다.
#   이 인스턴스의 .env 는 DB_NAME·STORAGE_DIR·AUDIT_LOG_FILE 을 임시로, SCAN_ENABLED=0. 띄우기 전에 DB_NAME 이 tbox_re 가 아닌지 본다.
#   경보 배치(alerts.php)는 **임시 사본**을 임시 .env 로만 돌린다(--state 임시 · 경보 로그 = 임시 감사 폴더). 운영 배치를 운영 설정으로 돌리지 않는다.
#   D-root 한 항목만 `sudo -n php`(root)로 **임시 앱의 작은 스크립트**를 돈다 — 임시 .env(임시 DB) · 쓰는 파일 0(감사 사본은 링크라 거부된다).
#   요청 출발지: 잠금·경보 항목은 루프백 다른 주소(127.0.0.2~5 — curl --interface)에서 보내 서로의 AL-2 창(IP 단위)이 섞이지 않게 한다.
#   운영 감사 max(id)·운영 alerts.log 줄 수·/etc/tbox-v2/qa-totp.txt mtime 을 시작·끝에 적는다(읽기만).
# ★ sudo psql 은 **명령 줄이 늘 같은 세 모양**뿐이다(취합 규칙 2026-09-26 21:4x — 운영 경보 AL-9 가 명령 요약마다 한 줄씩 나므로):
#   임시 DB `psql -X -q -At -d tbox_d2s_<포트>_test`(조회·복원·마이그레이션 전부 — SQL 은 표준입력) · DB 만들고 지우기
#   `psql -X -q -At -d postgres`(표준입력) · 운영 읽기 `psql -X -q -At -d tbox_re`(시작·끝 한 번씩 — 여러 조회를 한 번에). -c 로 SQL 을 싣지 않는다.
set -u
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
. "$ROOT/tests/lib/review_accounts.sh" || { echo "tests/lib/review_accounts.sh 를 읽지 못했다 — 시험을 시작하지 않는다"; exit 3; }
. "$ROOT/tests/lib/secrets.sh" || { echo "tests/lib/secrets.sh 를 읽지 못했다 — 시험을 시작하지 않는다 (R-62)"; exit 3; }
PORT="${DBGS2_PORT:-8261}"
case "$PORT" in 826[1-9]) ;; *) echo "포트는 8261~8269 만 쓴다(받은 값: $PORT)"; exit 3 ;; esac
SITE="http://127.0.0.1:$PORT"
BASE="$SITE/tbox"
UA="tbox-review/$(date +%F)"
SRC_DB="tbox_re"                     # 운영 — **읽기만** 한다(pg_dump)
DB="tbox_d2s_${PORT}_test"           # 임시 — 여기에만 쓴다(접두 tbox_d2s_)
case "$DB" in tbox_d2s_*_test) ;; *) echo "임시 DB 이름 규칙 밖 — 중단"; exit 3 ;; esac
BASE_REV="${DBGS2_BASE_REV:-4d256f6}"   # 운영 판(v2.30.0) — AL-2 옛 판 대조
PROD_ALERTS="/var/log/tbox-v2/alerts.log"
NEW_COOKIE="__Host-tbox_session"
OLD_COOKIE="tbox_session"
JAR_DIR="$(mktemp -d)"
WORK="$(mktemp -d)"
chmod 700 "$JAR_DIR" "$WORK"
INST=""
PIDS=()
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
  pkill -f "^php -S 127\.0\.0\.1:$PORT " 2>/dev/null
  pg_admin "DROP DATABASE IF EXISTS $DB;" >/dev/null 2>&1
  [ -n "$INST" ] && rm -rf "$INST"
  rm -rf "$JAR_DIR" "$WORK"
}
trap cleanup EXIT

# SQL 은 표준입력으로 — sudo 명령 줄(auth.log 의 COMMAND=)이 문장마다 달라지지 않게 한다(운영 cron 의 AL-9 가 명령 요약마다 한 줄씩
# 내는 소음을 줄인다 — 임시 DB 만 읽고 쓰는 줄이다)
psqlq()    { printf '%s\n' "$1" | sudo -n -u postgres psql -X -q -At -d "$DB" 2>/dev/null; }
pg_admin() { printf '%s\n' "$1" | sudo -n -u postgres psql -X -q -At -d postgres; }
prod_q()   { printf '%s\n' "$1" | sudo -n -u postgres psql -X -q -At -d "$SRC_DB" 2>/dev/null; }
review_password() { sudo -n awk -v u="$1" '$1==u{print $3}' /etc/tbox-v2/seed-accounts.txt 2>/dev/null; }
review_secret()   { sudo -n awk -v u="$1" '$1==u{print $2}' /etc/tbox-v2/qa-totp.txt 2>/dev/null; }
prod_alerts_lines() { sudo -n wc -l < "$PROD_ALERTS" 2>/dev/null | tr -d ' '; }
prod_totp_mtime()   { sudo -n stat -c %y /etc/tbox-v2/qa-totp.txt 2>/dev/null; }
# login <계정> [병 이름] [출발지 루프백 주소] — 로그인 응답 머리는 $JAR_DIR/<병>.head 에 남는다(쿠키 속성 확인용 · 0700 폴더)
login() {
  local who="$1" jar="$JAR_DIR/${2:-$1}.jar" head="$JAR_DIR/${2:-$1}.head" src="${3:-127.0.0.1}" pw sec raw code left out try body
  local bj=()
  [ -f "$jar" ] && bj=(-b "$jar")    # 병이 이미 있으면 그 쿠키를 싣고 간다(옛 쿠키를 든 로그인 시험)
  pw=$(review_password "$who")
  sec=$(review_secret "$who")
  [ -n "$pw" ] || return 2
  for try in 1 2 3; do
    if [ -n "$sec" ]; then
      raw=$(totp_now sec 2>/dev/null)
      code=${raw%%$'\n'*}
      printf -v body '{"username":"%s","password":"%s","otp":"%s"}' "$who" "$pw" "$code"
    else
      printf -v body '{"username":"%s","password":"%s"}' "$who" "$pw"
    fi
    out=$(curl_json_secret body -s --interface "$src" -D "$head" "${bj[@]}" -c "$jar" -A "$UA" -H 'Content-Type: application/json' "$BASE/api/login")
    case "$out" in *'"ok":true'*) return 0 ;; esac
    left=$(printf '%s' "$raw" | sed -n 's/^남은 시간: \([0-9]*\)초.*/\1/p')
    [ -n "$left" ] || left=31
    printf '  · %s 로그인 재시도 — 다음 코드 창까지 %s초\n' "$who" "$left" >&2
    sleep $((left + 1))
  done
  return 1
}
session_of() { awk -v n="$NEW_COOKIE" '$6==n{print $7}' "$JAR_DIR/$1.jar" 2>/dev/null | tail -1; }
csrf_of()    { curl -s -A "$UA" -b "$JAR_DIR/$1.jar" "$BASE/api/me" | sed -n 's/.*"csrf_token":"\([0-9a-f]*\)".*/\1/p'; }
# 이 계정의 지금 ±2 창 밖 6자리(틀린 코드) — 비밀은 표준입력으로만(py_secret) · 코드는 비밀이 아니다(틀린 값)
wrong_code() {
  local sec
  sec=$(review_secret "$1")
  py_secret sec -- php -r 'require $argv[1]; $s = trim((string) fgets(STDIN)); $t = time(); $bad = [];
    foreach ([-60, -30, 0, 30, 60, 90] as $d) { $bad[] = Totp::code($s, $t + $d); }
    for ($i = 0; ; $i++) { $c = sprintf("%06d", (123457 + $i * 7919) % 1000000); if (!in_array($c, $bad, true)) { echo $c; break; } }' "$INST/app/src/Totp.php"
}

# 임시 .env — DB 이름·저장·감사 경로를 임시로(+ 검사 끔). $1 원본 $2 대상 $3 저장 폴더 $4 감사 파일. 값은 출력하지 않는다.
make_env() {
  # qcheck-allow: R20 — 임시 인스턴스용 사본(0700 폴더 · 0600 · trap 정리) · kill -9 뒤 남는 창은 받아들인 위험(HO-B1-004)
  sed -e "s#^DB_NAME=.*#DB_NAME=$DB#" \
      -e "s#^STORAGE_DIR=.*#STORAGE_DIR=$3#" \
      -e "s#^AUDIT_LOG_FILE=.*#AUDIT_LOG_FILE=$4#" \
      -e "s#^SCAN_ENABLED=.*#SCAN_ENABLED=0#" \
      "$1" > "$2"
  chmod 600 "$2"
  grep -q "^DB_NAME=$DB\$" "$2" && ! grep -q '^DB_NAME=tbox_re$' "$2" \
    && grep -q "^STORAGE_DIR=$3\$" "$2" && grep -q "^AUDIT_LOG_FILE=$4\$" "$2"
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
        $types = ['css' => 'text/css', 'js' => 'text/javascript', 'svg' => 'image/svg+xml', 'png' => 'image/png', 'webmanifest' => 'application/manifest+json', 'json' => 'application/json', 'woff2' => 'font/woff2'];
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
wait_up() {   # $1 사이트 — 미로그인 GET /tbox/login 200(감사 행을 만들지 않는 화면)
  local i c=""
  for i in $(seq 1 30); do
    sleep 0.3
    c=$(curl -s -o /dev/null -w '%{http_code}' -A "$UA" "$1/tbox/login")
    [ "$c" = "200" ] && break
  done
  printf '%s' "$c"
}
# 머리 파일에서 Set-Cookie 줄만(끝 CR 제거)
setcookies() { tr -d '\r' < "$1" | grep -i '^Set-Cookie:' || true; }
status_of()  { sed -n 's#^HTTP/[0-9.]* \([0-9]*\).*#\1#p' "$1" | head -1 | tr -d '\r'; }

# ════════════════════════════════════════════════════════════════
hr "디버깅 묶음 S2 — 임시 인스턴스 준비 (운영 DB 에는 쓰지 않는다)"

if ss -ltn 2>/dev/null | grep -q "127.0.0.1:$PORT "; then echo "포트 $PORT 이 이미 쓰이고 있다 — 중단"; exit 3; fi
ENV_SRC="${DBGS2_ENV_SRC:-${QCHECK_ENV_SRC:-$ROOT/.env}}"
[ -f "$ENV_SRC" ] || { echo "임시 .env 원본이 없다 — DBGS2_ENV_SRC 로 0600 사본을 넘긴다"; exit 3; }
FREE_MB=$(df -Pm / | awk 'NR==2{print $4}')
[ "${FREE_MB:-0}" -ge 1200 ] || { echo "루트 여유가 1.2GB 미만(${FREE_MB}MB) — 시작하지 않는다"; exit 3; }
PROD_A0=$(prod_q "SELECT coalesce(max(id),0) FROM audit_logs")   # 운영 읽기 — 시작 한 번
[[ "$PROD_A0" =~ ^[0-9]+$ ]] || { echo "psql(postgres) 로 운영 DB 를 읽을 수 없다 — 이 시험은 임시 DB 가 없으면 성립하지 않는다"; exit 3; }
PROD_L0=$(prod_alerts_lines)
PROD_T0=$(prod_totp_mtime)
note "운영 감사 max(id) 시작 = ${PROD_A0:-?} · 운영 alerts.log 줄 수 시작 = ${PROD_L0:-?} · qa-totp mtime 시작 = ${PROD_T0:-?} (읽기만)"

sub "① 운영 DB 를 **읽어** 임시 DB 를 만든다(파이프) + 마이그레이션(있으면 그대로)"
pg_admin "DROP DATABASE IF EXISTS $DB;
CREATE DATABASE $DB OWNER tbox_owner;" >/dev/null 2>&1 || { echo "임시 DB 를 만들 수 없다"; exit 3; }
sudo -n -u postgres pg_dump -d "$SRC_DB" 2>/dev/null \
  | sudo -n -u postgres psql -X -q -At -d "$DB" >/dev/null 2>"$WORK/restore.err"
DUMP_PS=("${PIPESTATUS[@]}")
[ "${DUMP_PS[0]}" -eq 0 ] || { echo "운영 DB 를 읽지 못했다 (pg_dump)"; exit 3; }
[ -n "$(psqlq "SELECT count(*) FROM files")" ] || { echo "임시 DB 로 옮기지 못했다 — $(head -2 "$WORK/restore.err")"; exit 3; }
# 마이그레이션 다섯을 **한 번의 psql** 로(표준입력 · 오류에서 멈춤 — `\set ON_ERROR_STOP on`)
{ printf '\\set ON_ERROR_STOP on\n'
  for m in 2026-10-01_qf2_search.sql 2026-10-02_qf2b_files_search.sql 2026-10-03_qf3a_favorites_recent.sql 2026-10-04_users_temp_password_issued_at.sql 2026-10-05_qf3b_purge_favorites.sql; do
    [ -f "$ROOT/db/migrations/$m" ] && cat "$ROOT/db/migrations/$m" && printf '\n'
  done; } | sudo -n -u postgres psql -X -q -At -d "$DB" 2>&1 >/dev/null | grep -v 'could not change directory\|already exists, skipping' | sed 's/^/    /'
# 검토 계정의 실패 횟수·잠금을 비운다(**임시 DB 만** — 운영 값은 읽기만)
psqlq "UPDATE users SET failed_count = 0, locked_until = NULL WHERE username LIKE 'claude\\_%'" >/dev/null
note "임시 DB $DB — 파일 $(psqlq "SELECT count(*) FROM files") · 감사 $(psqlq "SELECT count(*) FROM audit_logs")"
if [ "${DBGS2_SYNC_TOTP:-0}" = "1" ]; then
  # 임시 DB 의 claude_ 2단계 비밀을 /etc/tbox-v2/qa-totp.txt 값에 맞춘다(**임시 DB 만** · 파일은 읽기만 · 값은 표준입력으로만)
  sudo -n awk 'BEGIN { print "\\set ON_ERROR_STOP on" } $1 ~ /^claude_[a-z0-9]+$/ && $2 ~ /^[A-Z2-7]+$/ { printf "UPDATE users SET mfa_secret = %c%s%c, mfa_last_step = NULL WHERE username = %c%s%c;\n", 39, $2, 39, 39, $1, 39 }' /etc/tbox-v2/qa-totp.txt \
    | sudo -n -u postgres psql -X -q -At -d "$DB" >/dev/null 2>&1
  note "DBGS2_SYNC_TOTP=1 — 임시 DB 의 claude_ 2단계 비밀을 파일 값에 맞췄다(종료 ${PIPESTATUS[1]:-?} · 값은 찍지 않는다)"
fi

sub "② 인스턴스 하나(포트 $PORT) — .env 는 사본(값은 출력하지 않는다)"
INST="$(mktemp -d)"; chmod 700 "$INST"
mkdir -p "$INST/app" "$INST/storage" "$INST/tmp" "$INST/log"
chmod 700 "$INST/tmp"
tar -C "$ROOT" -cf - --exclude=.git --exclude=jar --exclude=api-test.txt --exclude=./.env --exclude=./.claude --exclude=__pycache__ . | tar -C "$INST/app" -xf -
make_env "$ENV_SRC" "$INST/app/.env" "$INST/storage" "$INST/log/audit.log" || { echo "임시 .env 에 임시 값이 들어가지 않았다 — 중단"; exit 4; }
expect "임시 .env — DB_NAME 이 tbox_re 가 아니다(grep '^DB_NAME=')" "DB_NAME=$DB" "$(grep '^DB_NAME=' "$INST/app/.env")"
expect "임시 .env — 감사 파일(=경보 로그 폴더)이 임시 폴더 안" "AUDIT_LOG_FILE=$INST/log/audit.log" "$(grep '^AUDIT_LOG_FILE=' "$INST/app/.env")"
expect "임시 .env — 저장 폴더가 임시 폴더 안" "STORAGE_DIR=$INST/storage" "$(grep '^STORAGE_DIR=' "$INST/app/.env")"
: > "$INST/log/audit.log"; chmod 640 "$INST/log/audit.log"
ln -s /var/www/qaredi/community "$INST/community" 2>/dev/null
make_router "$INST/app"
( cd "$INST/app" && exec php -S "127.0.0.1:$PORT" -t public router.php > "$INST/server.log" 2>&1 ) &
PIDS+=("$!")
c=$(wait_up "$SITE")
expect "인스턴스가 떴다(로그인 화면 200)" "200" "$c"
[ "$c" = "200" ] || { echo "인스턴스를 띄우지 못했다 — 중단"; tail -5 "$INST/server.log"; exit 4; }
server_errs() { grep -iE 'PHP (Fatal|Warning|Notice|Deprecated)|SQLSTATE|Uncaught' "$INST/server.log" 2>/dev/null | wc -l; }
errs_before=$(server_errs)

sub "로그인 (claude_ 검토 계정 다섯, 순차, F-71)"
for who in claude_employee01 claude_employee02 claude_manager01 claude_admin01 claude_guest01; do
  login "$who" || { echo "  ✖ $who 로그인 실패 — 중단(2단계 비밀이 임시 DB 와 다르면 DBGS2_SYNC_TOTP=1)"; exit 4; }
done
S_E1=$(session_of claude_employee01); S_E2=$(session_of claude_employee02); S_M1=$(session_of claude_manager01)
S_A1=$(session_of claude_admin01);   S_G1=$(session_of claude_guest01)
expect "세션 다섯이 새 이름(__Host-tbox_session)으로 쿠키 병에 들어왔다(64자 hex)" "5" \
  "$(for s in "$S_E1" "$S_E2" "$S_M1" "$S_A1" "$S_G1"; do [[ "$s" =~ ^[0-9a-f]{64}$ ]] && echo y; done | wc -l | tr -d ' ')"

if [ "${DBGS2_SKIP_BODY:-0}" != "1" ]; then
# ════════════════════════════════════════════════════════════════
hr "A 쿠키 이름 — 로그인·로그아웃 응답의 머리(curl)"
SC=$(setcookies "$JAR_DIR/claude_employee01.head")
expect "로그인 응답 Set-Cookie 한 줄" "1" "$(printf '%s\n' "$SC" | grep -c . | tr -d ' ')"
expect "  이름 = __Host-tbox_session · 값 64자 hex" "1" "$(printf '%s\n' "$SC" | grep -cE '^Set-Cookie: __Host-tbox_session=[0-9a-f]{64};' | tr -d ' ')"
expect "  속성 path=/ · secure · HttpOnly · SameSite=Lax" "4" \
  "$(for a in '; path=/(;|$)' '; secure(;|$)' '; HttpOnly(;|$)' '; SameSite=Lax(;|$)'; do printf '%s\n' "$SC" | grep -qiE "$a" && echo y; done | wc -l | tr -d ' ')"
expect "  Domain·Expires·Max-Age 없음(호스트 한정 · 브라우저 세션 쿠키)" "0" "$(printf '%s\n' "$SC" | grep -ciE 'domain=|expires=|max-age=' | tr -d ' ')"
expect "  옛 이름 Set-Cookie 0(옛 쿠키를 들고 오지 않았으면 치우지 않는다)" "0" "$(printf '%s\n' "$SC" | grep -c "^Set-Cookie: $OLD_COOKIE=" | tr -d ' ')"

sub "옛 쿠키를 든 채 로그인 → 새 쿠키 + 옛 쿠키 만료(한 응답)"
# 병에는 옛 이름 쿠키 **하나만**(새 이름을 함께 실으면 로그인이 그 세션을 버린다 — 세션 고정 막기 · 위 S_E2 가 죽는다).
# 새 코드 창 첫머리에 보낸다 — 재시도가 나면 첫 응답이 이미 옛 쿠키를 치워 두 번째 응답의 머리만 남는다.
printf '127.0.0.1\tFALSE\t/\tTRUE\t0\t%s\t%s\n' "$OLD_COOKIE" "$(printf '0%.0s' $(seq 1 64))" > "$JAR_DIR/e2old.jar"
sleep $(( 31 - $(date +%s) % 30 ))
login claude_employee02 e2old || { echo "  ✖ 옛 쿠키 든 로그인 실패"; FAIL=$((FAIL+1)); }
SC=$(setcookies "$JAR_DIR/e2old.head")
expect "  Set-Cookie 둘 — 새 이름 발급 1 · 옛 이름 만료 1(Max-Age=0 · path=/ · secure · HttpOnly · SameSite=Lax)" "2 1 1" \
  "$(printf '%s\n' "$SC" | grep -c . | tr -d ' ') $(printf '%s\n' "$SC" | grep -cE '^Set-Cookie: __Host-tbox_session=[0-9a-f]{64};' | tr -d ' ') $(printf '%s\n' "$SC" | grep -ciE "^Set-Cookie: $OLD_COOKIE=(deleted)?; expires=[^;]*1970[^;]*; Max-Age=0; path=/; secure; HttpOnly; SameSite=Lax$" | tr -d ' ')"
expect "  쿠키 병에서 옛 이름이 사라졌다(curl 도 만료 머리를 따른다)" "0" "$(awk -v n="$OLD_COOKIE" '$6==n' "$JAR_DIR/e2old.jar" | wc -l | tr -d ' ')"

sub "로그아웃 — 새 이름 만료 · 그 토큰은 더는 세션이 아니다"
login claude_employee01 e1out || { echo "  ✖ 두 번째 세션 로그인 실패"; FAIL=$((FAIL+1)); }
S_OUT=$(session_of e1out); C_OUT=$(csrf_of e1out)
curl -s -o /dev/null -D "$JAR_DIR/logout.head" -A "$UA" -b "$JAR_DIR/e1out.jar" -X POST -H "X-CSRF-Token: $C_OUT" "$BASE/api/logout"
SC=$(setcookies "$JAR_DIR/logout.head")
expect "  POST /api/logout 200 · Set-Cookie 한 줄 = 새 이름 만료(Max-Age=0 · 같은 속성)" "200 1 1" \
  "$(status_of "$JAR_DIR/logout.head") $(printf '%s\n' "$SC" | grep -c . | tr -d ' ') $(printf '%s\n' "$SC" | grep -ciE "^Set-Cookie: __Host-tbox_session=(deleted)?; expires=[^;]*1970[^;]*; Max-Age=0; path=/; secure; HttpOnly; SameSite=Lax$" | tr -d ' ')"
S_OUT_HDR="$NEW_COOKIE=$S_OUT"
expect "  로그아웃한 토큰으로 /api/me → 401" "401" "$(curl_hdr_secret Cookie S_OUT_HDR -s -o /dev/null -w '%{http_code}' -A "$UA" "$BASE/api/me" || true)"
unset S_OUT C_OUT S_OUT_HDR

# ════════════════════════════════════════════════════════════════
hr "A · C · D-1 (파이썬 몸통 — 임시 인스턴스 HTTP)"
cat > "$WORK/drv.py" <<'PY'
import json, re, subprocess, sys, urllib.error, urllib.request, uuid
SITE, UA, DB = sys.argv[1:4]
S = {w: sys.stdin.readline().rstrip('\n') for w in ('e1', 'e2', 'm1', 'a1', 'g1')}
NEW, OLD = '__Host-tbox_session', 'tbox_session'
P = F = 0
def ok(name, cond, detail=''):
    global P, F
    if cond: P += 1; print('  ✔ ' + name)
    else: F += 1; print('  ✖ ' + name + ('  — ' + str(detail)[:600] if detail != '' else ''))
def sql(q):   # 문장은 표준입력으로(sudo 명령 줄이 문장마다 달라지지 않게 — 셸 psqlq 와 같은 까닭)
    r = subprocess.run(['sudo', '-n', '-u', 'postgres', 'psql', '-X', '-q', '-At', '-d', DB], input=q + '\n', capture_output=True, text=True, cwd='/')
    return r.stdout.strip()
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k): return None
OPENER = urllib.request.build_opener(NoRedirect)
CSRF = {}
def req(m, path, cookie=None, body=None, csrf_who=None):
    h = {'User-Agent': UA}
    if cookie: h['Cookie'] = cookie
    data = None
    if body is not None:
        data = json.dumps(body, ensure_ascii=False).encode('utf-8'); h['Content-Type'] = 'application/json'
    if csrf_who: h['X-CSRF-Token'] = CSRF.get(csrf_who, '')
    r = urllib.request.Request(SITE + path, data=data, headers=h, method=m)
    try:
        with OPENER.open(r, timeout=60) as x:
            b = x.read(); c = x.status; hd = x.headers
    except urllib.error.HTTPError as e:
        b = e.read(); c = e.code; hd = e.headers
    try: d = json.loads(b or b'{}')
    except Exception: d = None
    return c, d, b, hd
def ck(who, name=NEW): return name + '=' + S[who]
def sc(hd): return [v for v in (hd.get_all('Set-Cookie') or [])]
OLD_EXPIRE = re.compile(r'^tbox_session=(deleted)?; expires=[^;]*1970[^;]*; Max-Age=0; path=/; secure; HttpOnly; SameSite=Lax$', re.I)
def only_old_expire(hd):
    v = sc(hd)
    return len(v) == 1 and OLD_EXPIRE.match(v[0]) is not None
def api(who, m, path, body=None):
    return req(m, path, cookie=ck(who), body=body, csrf_who=who if m != 'GET' else None)
for w in S:
    CSRF[w] = (api(w, 'GET', '/tbox/api/me')[1] or {}).get('csrf_token', '')
ok('세션 다섯의 CSRF 토큰(새 이름으로 /tbox/api/me 200)', all(len(CSRF[w]) > 10 for w in S))

# ═══ A ═════════════════════════════════════════════════════════
print('\n── A 새 이름만 세션 · 옛 이름은 치운다')
for w in ('e1', 'g1'):
    c, d, _, hd = req('GET', '/tbox/api/me', cookie=ck(w))
    ok('%s 새 이름 /tbox/api/me 200 · Set-Cookie 0' % w, c == 200 and sc(hd) == [], (c, sc(hd)))
    c, _, _, hd = req('GET', '/community/api/me/space', cookie=ck(w))
    ok('%s 새 이름 /community/api/me/space 200(커뮤니티도 같은 쿠키)' % w, c == 200 and sc(hd) == [], (c, sc(hd)))
    c, _, b, hd = req('GET', '/community/', cookie=ck(w))
    ok('%s 새 이름 /community/ 화면 200' % w, c == 200, c)
c, d, _, hd = req('GET', '/tbox/api/me', cookie=ck('e1', OLD))
ok('옛 이름(살아 있는 토큰) /tbox/api/me → 401', c == 401, c)
ok('  응답 Set-Cookie = 옛 이름 만료 한 줄(Max-Age=0 · path=/ · secure · HttpOnly · SameSite=Lax)', only_old_expire(hd), sc(hd))
c, _, _, hd = req('GET', '/tbox/', cookie=ck('e1', OLD))
ok('옛 이름 /tbox/ 화면 → 302 /tbox/login + 옛 이름 만료', c == 302 and hd.get('Location') == '/tbox/login' and only_old_expire(hd), (c, hd.get('Location'), sc(hd)))
c, _, _, hd = req('GET', '/tbox/settings', cookie=ck('e1', OLD))
ok('옛 이름 /tbox/settings → 302 /tbox/login?to=%2Ftbox%2Fsettings(B1-1 보던 곳 꼬리표) + 옛 이름 만료', c == 302 and hd.get('Location') == '/tbox/login?to=%2Ftbox%2Fsettings' and only_old_expire(hd), (c, hd.get('Location'), sc(hd)))
c, _, _, hd = req('GET', '/community/', cookie=ck('e1', OLD))
ok('옛 이름 /community/ 화면 → 302 로그인으로 + 옛 이름 만료', c == 302 and '/tbox/login' in (hd.get('Location') or '') and only_old_expire(hd), (c, hd.get('Location'), sc(hd)))
c, _, _, hd = req('GET', '/community/api/me/space', cookie=ck('e1', OLD))
ok('옛 이름 /community/api/me/space → 401 + 옛 이름 만료', c == 401 and only_old_expire(hd), (c, sc(hd)))
c, _, _, hd = req('POST', '/tbox/api/folders', cookie=ck('e1', OLD), body={'name': 'x'}, csrf_who='e1')
ok('옛 이름 + 맞는 CSRF 로 쓰기 → 401(쓰기도 막힘) + 옛 이름 만료', c == 401 and only_old_expire(hd), (c, sc(hd)))
c, _, _, hd = req('GET', '/tbox/api/me', cookie=ck('e1', OLD) + '; ' + ck('e1'))
ok('둘 다 실림 → 새 이름으로 200 · Set-Cookie = 옛 이름 만료 한 줄뿐(새 쿠키는 그대로)', c == 200 and only_old_expire(hd), (c, sc(hd)))
c, _, _, hd = req('GET', '/tbox/api/me', cookie=OLD + '=not-a-token')
ok('옛 이름에 엉뚱한 값 → 401 + 옛 이름 만료(값은 보지 않는다)', c == 401 and only_old_expire(hd), (c, sc(hd)))
for fake in ('__host-tbox_session', '__Secure-tbox_session', 'Tbox_session', '__Host-tbox_session_'):
    c, _, _, hd = req('GET', '/tbox/api/me', cookie=fake + '=' + S['e1'])
    ok('이름 흉내 %s(살아 있는 토큰) → 401 · Set-Cookie 0' % fake, c == 401 and sc(hd) == [], (c, sc(hd)))
c, _, _, hd = req('GET', '/tbox/login')
ok('쿠키 없이 /tbox/login 200 · Set-Cookie 0', c == 200 and sc(hd) == [], (c, sc(hd)))

# ═══ C ═════════════════════════════════════════════════════════
print('\n── C 런처 — 로그인 전에는 앱 목록 0')
APP_WORDS = ['t-box', '커뮤니티', '사내 파일 공유', '개인 업무 공간', 'href="/tbox/"', 'href="/community/"', 'card-grid', 'card--link', 'card__title']
def launcher(cookie=None):
    c, _, b, hd = req('GET', '/', cookie=cookie)
    return c, b.decode('utf-8', 'replace'), hd
c, html, hd = launcher()
ok('미로그인 / 200 · Set-Cookie 0 · <script 0', c == 200 and sc(hd) == [] and '<script' not in html, (c, sc(hd)))
ok('  사이트 이름 Qaredi · 로그인 단추 하나(/tbox/login)', '>Qaredi</h1>' in html and html.count('<a class="btn" href="/tbox/login">로그인</a>') == 1, html[:400])
hits = [w for w in APP_WORDS if w in html]
ok('  앱 이름·설명·경로·타일 0(%d개 낱말 검사)' % len(APP_WORDS), hits == [], hits)
ok('  사용자 배지 없음', 'class="badge"' not in html)
for w, label in (('e1', '직원'), ('g1', '손님'), ('a1', '관리자')):
    c, html, hd = launcher(ck(w))
    ok('%s 로그인 뒤 / 200 · 타일 둘(/tbox/ · /community/) · 이름 둘 · 배지 · 로그인 단추 0 · Set-Cookie 0' % label,
       c == 200 and html.count('href="/tbox/"') == 1 and html.count('href="/community/"') == 1 and 't-box' in html and '커뮤니티' in html
       and 'class="badge"' in html and 'href="/tbox/login"' not in html and sc(hd) == [], (c, sc(hd)))
c, html, hd = launcher(ck('e1', OLD))
ok('옛 이름만 → 로그인 전 화면(앱 0 · 로그인 단추) + 옛 이름 만료', c == 200 and [w for w in APP_WORDS if w in html] == [] and 'href="/tbox/login"' in html and only_old_expire(hd), (c, sc(hd)))

# ═══ D-1 ═══════════════════════════════════════════════════════
print('\n── D-1 손님의 커뮤니티 창구 쓰기 값 — 같은 계정이 직원일 때(참)와 손님일 때(거짓)')
WRITE = ['can_edit', 'can_set_visibility', 'can_set_properties', 'can_manage', 'can_share', 'can_handover', 'can_tag',
         'can_publish', 'can_unpublish', 'can_hide', 'can_resolve', 'can_comment']
tag = uuid.uuid4().hex[:6]
c, d, _, _ = api('e1', 'POST', '/community/api/pages', {'title': 'dbgS2 쪽 %s' % tag})
PG = ((d or {}).get('page') or {}).get('id', '')
ok('직원 e1 — 자기 공간에 페이지 만들기 201', c == 201 and len(PG) == 36, (c, d))
c, d, _, _ = api('e1', 'POST', '/community/api/pages', {'title': 'dbgS2 DB %s' % tag, 'database': True})
PD = ((d or {}).get('page') or {}).get('id', '')
DBID = (((d or {}).get('page') or {}).get('database') or {}).get('id', '')
ok('직원 e1 — 자기 공간에 DB 페이지 만들기 201', c == 201 and len(PD) == 36 and len(DBID) == 36, (c, d))
def snapshot(who):
    out = {}
    c, d, _, _ = api(who, 'GET', '/community/api/pages/' + PG)
    out['page_status'] = c
    for k in WRITE: out['page.' + k] = (d or {}).get(k)
    out['page.template_scopes'] = (d or {}).get('template_scopes')
    out['page.can_view_reads'] = (d or {}).get('can_view_reads')
    c, d, _, _ = api(who, 'GET', '/community/api/pages/' + PD)
    dbv = (d or {}).get('database') or {}
    out['dbpage.can_add_rows'] = dbv.get('can_add_rows'); out['dbpage.can_manage_schema'] = dbv.get('can_manage_schema')
    c, d, _, _ = api(who, 'GET', '/community/api/databases/%s/rows' % DBID)
    out['rows_status'] = c
    for k in ('can_add_rows', 'can_edit_values', 'can_manage_schema'): out['rows.' + k] = (d or {}).get(k)
    c, d, _, _ = api(who, 'GET', '/community/api/spaces')
    pers = [s for s in ((d or {}).get('spaces') or []) if s.get('kind') == 'personal']
    out['spaces.personal.can_write'] = pers[0].get('can_write') if pers else None
    sid = pers[0].get('id') if pers else None
    c, d, _, _ = api(who, 'GET', '/community/api/pages?space=%s' % sid)
    out['pages_list.space.can_write'] = ((d or {}).get('space') or {}).get('can_write')
    c, d, _, _ = api(who, 'GET', '/community/api/pages/%s/versions' % PG)
    out['versions.can_edit'] = (d or {}).get('can_edit')
    c, d, _, _ = api(who, 'GET', '/community/api/pages/%s/shares' % PG)
    out['shares.can_manage'] = (d or {}).get('can_manage')
    out['shares.names'] = (d or {}).get('shares')   # 이름 목록(검토 M-1 부류 — 손님은 None)
    # 검토 M-1 — 사람 찾기 창구 셋(공유 후보 · 인계 후보 · 멘션 후보). 직원 200 · 손님 403
    out['cand.share'] = api(who, 'GET', '/community/api/share-candidates?page=%s&q=a' % PG)[:2]
    out['cand.handover'] = api(who, 'GET', '/community/api/share-candidates?for=handover&page=%s&q=a' % PG)[:2]
    out['cand.mention'] = api(who, 'GET', '/community/api/mention-candidates?page=%s&q=a' % PG)[:2]
    # 검토 L-1 — 내 개인 템플릿의 can_delete
    c, d, _, _ = api(who, 'GET', '/community/api/templates')
    mine = [t for t in ((d or {}).get('templates') or []) if t.get('id') == TPL]
    out['tpl.can_delete'] = mine[0].get('can_delete') if mine else None
    return out
c, d, _, _ = api('e1', 'POST', '/community/api/templates', {'page_id': PG, 'name': 'dbgS2 틀 %s' % tag, 'scope': 'personal'})
TPL = ((d or {}).get('template') or d or {}).get('id', '')
ok('직원 e1 — 자기 페이지로 개인 템플릿 만들기 201(검토 L-1 대조용)', c == 201 and len(TPL) == 36, (c, d))
before = snapshot('e1')
MUST_TRUE = ['page.can_edit', 'page.can_set_visibility', 'page.can_set_properties', 'page.can_manage', 'page.can_share', 'page.can_handover',
             'page.can_tag', 'page.can_comment', 'dbpage.can_add_rows', 'dbpage.can_manage_schema', 'rows.can_add_rows', 'rows.can_edit_values',
             'rows.can_manage_schema', 'spaces.personal.can_write', 'pages_list.space.can_write', 'versions.can_edit', 'shares.can_manage']
ok('직원일 때 — 쓰기 값 %d개가 참(대조군 · 헛돌지 않는다) · 템플릿 범위 1개 이상' % len(MUST_TRUE),
   all(before.get(k) is True for k in MUST_TRUE) and isinstance(before.get('page.template_scopes'), list) and len(before['page.template_scopes']) >= 1,
   {k: before.get(k) for k in MUST_TRUE + ['page.template_scopes']})
ok('직원일 때 — 사람 찾기 셋 200(공유 · 인계 · 멘션) · 공유 이름 목록(list) · 내 템플릿 can_delete 참(검토 M-1 · L-1 대조)',
   [before[k][0] for k in ('cand.share', 'cand.handover', 'cand.mention')] == [200, 200, 200]
   and isinstance(before['shares.names'], list) and before['tpl.can_delete'] is True,
   ({k: before[k][0] for k in ('cand.share', 'cand.handover', 'cand.mention')}, type(before['shares.names']).__name__, before['tpl.can_delete']))
ok('  직원 공유 후보(q=a)에 사람이 나온다(헛돌지 않는 대조 — 아이디·역할)', len(((before['cand.share'][1] or {}).get('candidates') or [])) >= 1, before['cand.share'][1])
sql("UPDATE users SET role = 'Guest', expires_at = now() + interval '1 day' WHERE username = 'claude_employee01'")
ok('임시 DB 에서 e1 을 손님으로(세션은 그대로 — 역할은 요청마다 DB 에서 읽는다)', sql("SELECT role FROM users WHERE username = 'claude_employee01'") == 'Guest')
after = snapshot('e1')
ok('손님일 때 — 페이지·DB 행 읽기는 그대로 200', after['page_status'] == 200 and after['rows_status'] == 200, (after['page_status'], after['rows_status']))
falses = {k: v for k, v in after.items() if k.startswith(('page.can_', 'dbpage.', 'rows.can', 'spaces.', 'pages_list.', 'versions.', 'shares.')) and k not in ('page.can_view_reads', 'shares.names')}
ok('손님일 때 — 쓰기 값 %d개 전부 거짓(pages_get · DB 페이지 · DB 행 · 공간 목록 · 페이지 목록 · 판 목록 · 공유 목록)' % len(falses),
   len(falses) == 21 and all(v is False for v in falses.values()), {k: v for k, v in falses.items() if v is not False})
ok('  템플릿 범위 [] (템플릿 저장도 쓰기 창구)', after.get('page.template_scopes') == [], after.get('page.template_scopes'))
ok('  검토 M-1 — 손님은 사람 찾기 셋 403(공유 후보 · 인계 후보 · 멘션 후보 — 본문에 사람 0) · 공유 이름 목록 null',
   [after[k][0] for k in ('cand.share', 'cand.handover', 'cand.mention')] == [403, 403, 403]
   and all('candidates' not in (after[k][1] or {}) for k in ('cand.share', 'cand.handover', 'cand.mention')) and after['shares.names'] is None,
   ({k: after[k][0] for k in ('cand.share', 'cand.handover', 'cand.mention')}, after['shares.names']))
ok('  검토 L-1 — 손님은 자기 템플릿도 can_delete 거짓(지우기는 쓰기 창구)', after['tpl.can_delete'] is False, after['tpl.can_delete'])
c, _, _, _ = api('e1', 'POST', '/community/api/pages', {'title': 'dbgS2 손님 쓰기 %s' % tag})
c2, _, _, _ = api('e1', 'PATCH', '/community/api/pages/' + PG, {'title': 'x'})
ok('  손님 쓰기 창구는 그대로 403(만들기 · 고치기) — 값만 맞췄다', (c, c2) == (403, 403), (c, c2))
sql("UPDATE users SET role = 'Employee', expires_at = NULL WHERE username = 'claude_employee01'")
back = snapshot('e1')
ok('직원으로 되돌리면 다시 참(같은 창구 · 같은 판정)', all(back.get(k) is True for k in MUST_TRUE), {k: back.get(k) for k in MUST_TRUE})
# 진짜 손님 계정 — 자기 공간의 페이지(시작하기)
c, d, _, _ = api('g1', 'GET', '/community/api/spaces')
gp = [s for s in ((d or {}).get('spaces') or []) if s.get('kind') == 'personal']
ok('claude_guest01 — 공간 목록의 자기 공간 can_write 거짓(전에는 늘 참)', len(gp) == 1 and gp[0].get('can_write') is False, gp)
if gp:
    c, d, _, _ = api('g1', 'GET', '/community/api/pages?space=%s' % gp[0]['id'])
    gpages = (d or {}).get('pages') or []
    ok('  자기 공간 페이지 목록 200 · space.can_write 거짓', c == 200 and ((d or {}).get('space') or {}).get('can_write') is False, (c, (d or {}).get('space')))
    if gpages:
        c, d, _, _ = api('g1', 'GET', '/community/api/pages/' + gpages[0]['id'])
        bad = {k: (d or {}).get(k) for k in WRITE if (d or {}).get(k) is not False}
        ok('  자기 공간 첫 페이지 — 쓰기 값 %d개 전부 거짓 · 템플릿 범위 []' % len(WRITE), c == 200 and bad == {} and (d or {}).get('template_scopes') == [], (c, bad))
        gc = [api('g1', 'GET', u % gpages[0]['id'])[0] for u in ('/community/api/share-candidates?page=%s&q=a', '/community/api/share-candidates?for=handover&page=%s&q=a', '/community/api/mention-candidates?page=%s&q=a')]
        ok('  검토 M-1 — 자기 「시작하기」 페이지 id 로 사람 찾기 셋 → 403 403 403(전에는 공유 후보가 활성 사용자를 줬다)', gc == [403, 403, 403], gc)
    else:
        print('  · claude_guest01 자기 공간에 페이지가 없다 — 위 역할 바꾸기 대조로 갈음')
print('PY_PASS=%d PY_FAIL=%d' % (P, F))
PY
py_secret S_E1 S_E2 S_M1 S_A1 S_G1 -- python3 "$WORK/drv.py" "$SITE" "$UA" "$DB" > "$WORK/drv.out" 2>&1
cat "$WORK/drv.out"
pp=$(sed -n 's/^PY_PASS=\([0-9]*\) PY_FAIL=\([0-9]*\)$/\1/p' "$WORK/drv.out"); pf=$(sed -n 's/^PY_PASS=\([0-9]*\) PY_FAIL=\([0-9]*\)$/\2/p' "$WORK/drv.out")
if [[ "$pp" =~ ^[0-9]+$ ]] && [[ "$pf" =~ ^[0-9]+$ ]]; then PASS=$((PASS + pp)); FAIL=$((FAIL + pf)); else echo "  ✖ 파이썬 몸통이 끝줄을 못 찍었다"; FAIL=$((FAIL+1)); fi
[ "${pp:-0}" -ge 35 ] 2>/dev/null && expect "몸통이 헛돌지 않았다(통과 35 이상)" "yes" "yes" || expect "몸통이 헛돌지 않았다(통과 35 이상)" "yes" "no(${pp:-?})"

# ════════════════════════════════════════════════════════════════
hr "B 로그인 시도 제한 — 2단계 코드 · 코드 재사용 · 로그인 뒤 창구 · 잠금 DoS 와 AL-2"
amax() { psqlq "SELECT coalesce(max(id),0) FROM audit_logs"; }
fc()   { psqlq "SELECT failed_count || '|' || (locked_until IS NOT NULL AND locked_until > now())::text FROM users WHERE username = '$1'"; }
unlock() { psqlq "UPDATE users SET failed_count = 0, locked_until = NULL WHERE username = '$1'" >/dev/null; }
expect "상수 — 실패 5회 · 잠금 10분(Auth::MAX_FAILED · LOCK_MINUTES)" "5 10" \
  "$(php -r 'require $argv[1] . "/src/Auth.php"; $r = new ReflectionClass("Auth"); echo $r->getConstant("MAX_FAILED"), " ", $r->getConstant("LOCK_MINUTES");' "$INST/app" 2>/dev/null)"

sub "B-1 2단계 코드 틀림(비밀번호는 맞음) — 출발지 127.0.0.3 · claude_manager01"
U=claude_manager01; PW_U=$(review_password "$U"); A0=$(amax); codes=""
for i in 1 2 3 4 5 6; do
  WC=$(wrong_code "$U")
  printf -v B1BODY '{"username":"%s","password":"%s","otp":"%s"}' "$U" "$PW_U" "$WC"
  codes="$codes $(curl_json_secret B1BODY -s -o /dev/null -w '%{http_code}' --interface 127.0.0.3 -A "$UA" -H 'Content-Type: application/json' "$BASE/api/login")"
done
unset B1BODY
expect "  여섯 번 — 401 ×5 뒤 423(잠김 — 비밀번호도 보지 않는다)" " 401 401 401 401 401 423" "$codes"
expect "  실패 5 · 잠김" "5|true" "$(fc "$U")"
expect "  감사 — auth.mfa deny bad_code 5 · auth.lock deny failed_limit 1 · auth.login deny locked 1(이번 요청들만)" "5 1 1" \
  "$(psqlq "SELECT count(*) FILTER (WHERE action='auth.mfa' AND reason='bad_code') || ' ' || count(*) FILTER (WHERE action='auth.lock' AND result='deny' AND reason='failed_limit') || ' ' || count(*) FILTER (WHERE action='auth.login' AND reason='locked') FROM audit_logs WHERE id > $A0 AND target_name = '$U' AND ip = '127.0.0.3'")"
expect "  잠금 행 — 로그인 전(actor 없음) · 대상 = 그 계정 · 문장 · detail.lock_minutes 10" "|$U|$U 계정이 인증 실패 5회로 10분 동안 잠겼습니다|10" \
  "$(psqlq "SELECT coalesce(actor_name,'') || '|' || target_name || '|' || summary || '|' || (detail->>'lock_minutes') FROM audit_logs WHERE id > $A0 AND action='auth.lock' ORDER BY id LIMIT 1")"
expect "  잠금 행은 다섯 번째 실패의 거부 행 바로 앞(Auth::login 은 세고 나서 기록) · 그 뒤 423 은 새 잠금 행을 만들지 않는다" "auth.lock auth.mfa auth.login" \
  "$(psqlq "SELECT string_agg(action, ' ' ORDER BY id) FROM (SELECT id, action FROM audit_logs WHERE id > $A0 AND target_name = '$U' AND ip = '127.0.0.3' ORDER BY id DESC LIMIT 3) t")"
expect "  감사 파일 사본에도 잠금 행(임시 audit.log)" "1" "$(grep -c '"action":"auth.lock"' "$INST/log/audit.log" | tr -d ' ')"
unlock "$U"

sub "B-2 쓴 코드 다시 — 출발지 127.0.0.5 · claude_employee02 (1회성 · 실패로 센다)"
U=claude_employee02; PW_U=$(review_password "$U"); SEC_U=$(review_secret "$U")
sleep $(( 31 - $(date +%s) % 30 ))   # 새 코드 창 첫머리 — 위 로그인들이 쓴 스텝과 겹치지 않게
RAW=$(totp_now SEC_U); CODE=${RAW%%$'\n'*}
printf -v B2BODY '{"username":"%s","password":"%s","otp":"%s"}' "$U" "$PW_U" "$CODE"
r1=$(curl_json_secret B2BODY -s -o /dev/null -w '%{http_code}' --interface 127.0.0.5 -c "$JAR_DIR/b2.jar" -A "$UA" -H 'Content-Type: application/json' "$BASE/api/login")
A0=$(amax)
r2=$(curl_json_secret B2BODY -s -o /dev/null -w '%{http_code}' --interface 127.0.0.5 -A "$UA" -H 'Content-Type: application/json' "$BASE/api/login")
unset B2BODY SEC_U RAW
expect "  처음 200 · 같은 코드 다시 401" "200 401" "$r1 $r2"
expect "  감사 auth.mfa deny code_reused 1 · 실패 1 · 잠기지 않음" "1 1|false" \
  "$(psqlq "SELECT count(*) FROM audit_logs WHERE id > $A0 AND action='auth.mfa' AND reason='code_reused' AND target_name='$U'") $(fc "$U")"
unlock "$U"

sub "B-3 로그인 뒤 창구(재인증) 비밀번호 5회 — 출발지 127.0.0.4 · claude_manager01 두 세션"
U=claude_manager01
login "$U" m1b 127.0.0.4 || { echo "  ✖ 두 번째 세션 로그인 실패"; FAIL=$((FAIL+1)); }
login "$U" m1c 127.0.0.4 || { echo "  ✖ 세 번째 세션 로그인 실패"; FAIL=$((FAIL+1)); }
C_M1B=$(csrf_of m1b); A0=$(amax); codes=""
WRONG_PW="dbgS2-wrong-$(date +%s)-password"
printf -v B3BODY '{"password":"%s"}' "$WRONG_PW"
for i in 1 2 3 4 5; do
  codes="$codes $(curl_json_secret B3BODY -s -o /dev/null -w '%{http_code}' --interface 127.0.0.4 -A "$UA" -b "$JAR_DIR/m1b.jar" -H 'Content-Type: application/json' -H "X-CSRF-Token: $C_M1B" "$BASE/api/me/reauth")"
done
unset B3BODY WRONG_PW
note "재인증 다섯 응답:$codes"
expect "  실패 5 · 잠김" "5|true" "$(fc "$U")"
expect "  그 계정 세션 전부 끊김 — 요청한 세션·다른 세션 둘 다 /api/me 401" "401 401" \
  "$(curl -s -o /dev/null -w '%{http_code}' -A "$UA" -b "$JAR_DIR/m1b.jar" "$BASE/api/me") $(curl -s -o /dev/null -w '%{http_code}' -A "$UA" -b "$JAR_DIR/m1c.jar" "$BASE/api/me")"
expect "  감사 — auth.reauth deny bad_credentials 5 · auth.lock 1(actor = 그 계정 — 로그인 뒤라 누가 했는지 남는다)" "5 1|$U" \
  "$(psqlq "SELECT count(*) FILTER (WHERE action='auth.reauth' AND reason='bad_credentials') || ' ' || count(*) FILTER (WHERE action='auth.lock') FROM audit_logs WHERE id > $A0 AND ip = '127.0.0.4'")|$(psqlq "SELECT coalesce(actor_name,'') FROM audit_logs WHERE id > $A0 AND action='auth.lock' ORDER BY id LIMIT 1")"
unlock "$U"

sub "B-4 잠금 DoS — 정확히 5번만 틀림(6번째 없음) · 출발지 127.0.0.2 · claude_employee02 → AL-2"
U=claude_employee02
WRONG_PW="dbgS2-dos-$(date +%s)-password"
printf -v B4BODY '{"username":"%s","password":"%s"}' "$U" "$WRONG_PW"
A0=$(amax); codes=""
for i in 1 2 3 4 5; do
  codes="$codes $(curl_json_secret B4BODY -s -o /dev/null -w '%{http_code}' --interface 127.0.0.2 -A "$UA" -H 'Content-Type: application/json' "$BASE/api/login")"
done
unset B4BODY WRONG_PW
expect "  다섯 번 모두 401(같은 문구) · 잠김" " 401 401 401 401 401 5|true" "$codes $(fc "$U")"
expect "  이 출발지의 감사 — bad_credentials 5 · auth.lock 1 · locked 0(6번째를 보내지 않았다)" "5 1 0" \
  "$(psqlq "SELECT count(*) FILTER (WHERE reason='bad_credentials') || ' ' || count(*) FILTER (WHERE action='auth.lock') || ' ' || count(*) FILTER (WHERE reason='locked') FROM audit_logs WHERE id > $A0 AND ip = '127.0.0.2'")"
run_alerts() { ( cd / && php "$1/scripts/alerts.php" "${@:2}" ); }
run_alerts "$INST/app" --rule AL-2 --dry-run --state "$WORK/al2-dry.json" > "$WORK/al2-new.out" 2>&1; rc=$?
expect "  새 판 AL-2 dry-run — 127.0.0.2 발동 한 줄(실패 수 5 · 잠금 행은 따로 lock_events 1 — 검토 L-3) · 종료 0" "1 1 0" \
  "$(grep -c '\[dry-run\] AL-2 127\.0\.0\.2 ' "$WORK/al2-new.out" | tr -d ' ') $(grep '\[dry-run\] AL-2 127\.0\.0\.2 ' "$WORK/al2-new.out" | grep '"count":5,' | grep '"locked":0,' | grep -c '"lock_events":1,' | tr -d ' ') $rc"
# 기준 판(운영 v2.30.0) alerts.php — 같은 임시 DB · 같은 창에서 이 잠금 DoS 를 못 본다는 대조
mkdir -p "$INST/base" "$INST/log-base"
if git -C "$ROOT" archive "$BASE_REV" src scripts config 2>/dev/null | tar -C "$INST/base" -xf - 2>/dev/null; then
  sed -e "s#^AUDIT_LOG_FILE=.*#AUDIT_LOG_FILE=$INST/log-base/audit.log#" "$INST/app/.env" > "$INST/base/.env"; chmod 600 "$INST/base/.env"
  : > "$INST/log-base/audit.log"
  run_alerts "$INST/base" --rule AL-2 --dry-run --state "$WORK/al2-base.json" > "$WORK/al2-base.out" 2>&1; rc=$?
  expect "  기준 판($BASE_REV) AL-2 dry-run — 127.0.0.2 발동 0줄(전에는 조용했다) · 종료 0" "0 0" "$(grep -c '\[dry-run\] AL-2 127\.0\.0\.2 ' "$WORK/al2-base.out" | tr -d ' ') $rc"
else
  expect "  기준 판($BASE_REV) 을 git archive 로 꺼냈다" "yes" "no"
fi
TA="$INST/log/alerts.log"; l0=$(cat "$TA" 2>/dev/null | wc -l); A0=$(amax)
run_alerts "$INST/app" --rule AL-2 --state "$WORK/al2.json" > "$WORK/al2-real.out" 2>&1; rc=$?
expect "  새 판 AL-2 실제 — 임시 경보 로그에 127.0.0.2 한 줄(count 5 · locked 0 · lock_events 1 · after_login 0) · 감사 alert.raised AL-2 1 · 종료 0" "1 1 0" \
  "$(tail -n +$((l0 + 1)) "$TA" | jq -r 'select(.rule=="AL-2" and .evidence.subject=="127.0.0.2" and .evidence.count==5 and .evidence.locked==0 and .evidence.lock_events==1 and .evidence.after_login==0) | .rule' 2>/dev/null | wc -l | tr -d ' ') $(psqlq "SELECT count(*) FROM audit_logs WHERE id > $A0 AND action='alert.raised' AND target_name='AL-2' AND detail->'evidence'->>'subject' = '127.0.0.2'") $rc"
unlock "$U"

sub "B-5 nginx 지킴(값 확인만 — 바꾸지 않는다: 제안안은 review-scripts/apply-nginx-login-limit-2026-09-26.sh)"
expect "  zone tbox_login = IP 단위 30r/m(저장소 deploy/sites/tbox-common.conf)" "1" "$(grep -c '^limit_req_zone \$binary_remote_addr zone=tbox_login:1m rate=30r/m;' "$ROOT/deploy/sites/tbox-common.conf" | tr -d ' ')"
# T:F-72(2026-09-27) 패스키 창구 다섯 × 끝 슬래시 = 10 자리를 같은 zone 에 더했다(지시서 1.6) — 12 → 22
expect "  tbox-v2.conf 의 tbox_login burst=10 nodelay 자리 22(login·password·mfa 셋·reauth × 끝 슬래시 = 12 + 패스키 다섯 × 끝 슬래시 = 10)" "22" "$(grep -c 'limit_req zone=tbox_login burst=10 nodelay;' "$ROOT/deploy/sites/tbox-v2.conf" | tr -d ' ')"
expect "  비밀번호·코드를 묻는 나머지 창구(api-tokens · exec-download · admin/permissions · fetch-hosts) — d75(2026-09-27 f106e30)로 비밀 창구 넷에 tbox_secret 제한 — 자리 7" "7" \
  "$(grep -cE 'location .*(api-tokens|exec-download|admin/permissions|fetch-hosts)' "$ROOT/deploy/sites/tbox-v2.conf" | tr -d ' ')"
for p in "conf.d/tbox-common.conf:tbox-common.conf" "sites-available/tbox-v2:tbox-v2.conf" "sites-available/tbox-v2-local:tbox-v2-local.conf"; do
  expect "  설치본 /etc/nginx/${p%%:*} = 저장소 deploy/sites/${p#*:}(읽기만)" "same" "$(cmp -s "/etc/nginx/${p%%:*}" "$ROOT/deploy/sites/${p#*:}" && echo same || echo diff)"
done

# ════════════════════════════════════════════════════════════════
hr "D-2 · D-3 root·posix 없는 CLI — 감사 파일 사본 실패에도 회차 계속 · getmyuid 대체 없음"
AUD="$INST/log/audit.log"
cp "$AUD" "$WORK/audit.keep"; printf 'X\n' > "$WORK/elsewhere.log"; rm -f "$AUD"; ln -s "$WORK/elsewhere.log" "$AUD"
AL9="$WORK/auth9.log"
printf 'Sep 26 19:00:01 box sudo: dbgs2user : TTY=pts/0 ; PWD=/x ; USER=root ; COMMAND=/usr/bin/rm -rf /srv/d2a\n'  > "$AL9"
printf 'Sep 26 19:00:02 box sudo: dbgs2user : TTY=pts/0 ; PWD=/x ; USER=root ; COMMAND=/usr/bin/rm -rf /srv/d2b\n' >> "$AL9"
A0=$(amax); lta=$(cat "$INST/log/alerts.log" 2>/dev/null | wc -l)
( cd / && php -d disable_functions=posix_geteuid "$INST/app/scripts/alerts.php" --rule AL-9 --auth-log "$AL9" --state "$WORK/d2-state.json" ) > "$WORK/d2a.out" 2>&1; rc=$?
sed 's/^/    | /' "$WORK/d2a.out" | grep -E '경고|AL-9' | head -8
expect "posix 없음(링크 심은 감사 파일) — 종료 0 · 경보 둘 다 감사 행(alerts_log_failed) · 감사 파일 경고 2 이상 · 경보 로그 경고 2" "0 2 yes 2" \
  "$rc $(psqlq "SELECT count(*) FROM audit_logs WHERE id > $A0 AND action = 'alert.raised' AND target_name = 'AL-9' AND (detail->>'alerts_log_failed')::boolean") $([ "$(grep -c '감사 파일 사본에 쓰지 못했다' "$WORK/d2a.out")" -ge 2 ] && echo yes || echo no) $(grep -c '경보 로그에 쓰지 못했다' "$WORK/d2a.out" | tr -d ' ')"
expect "  링크 끝 파일 그대로(X) · 경보 로그 줄 수 그대로(posix 없으면 쓰지 않는다)" "X $lta" "$(cat "$WORK/elsewhere.log") $(cat "$INST/log/alerts.log" 2>/dev/null | wc -l)"
( cd / && php -d disable_functions=posix_geteuid "$INST/app/scripts/alerts.php" --rule AL-9 --auth-log "$AL9" --state "$WORK/d2-state.json" ) > "$WORK/d2b.out" 2>&1; rc=$?
expect "posix 없음 두 번째 — 상태 파일을 믿지 않는다(경고 1) · 억제 없이 다시 둘 발동 · 종료 0" "1 2 0" \
  "$(grep -c 'posix 확장이 없어 이 배치를 돌리는 계정을 알 수 없다 — 경보 상태 파일을 믿지 않고' "$WORK/d2b.out" | tr -d ' ') $(grep -c '★ AL-9' "$WORK/d2b.out" | tr -d ' ') $rc"
cat > "$WORK/rootcopy.php" <<'PHP'
<?php
require $argv[1] . '/scripts/lib/cli_bootstrap.php';
echo 'euid=', posix_geteuid(), "\n";
Audit::allow('alert.raised', ['target_type' => 'system', 'target_name' => 'AL-TEST', 'detail' => ['rule' => 'AL-TEST', 'evidence' => ['subject' => 'dbgS2-root'], 'origin' => 'claude-test']]);
echo "after\n";
PHP
A0=$(amax)
( cd / && sudo -n php "$WORK/rootcopy.php" "$INST/app" ) > "$WORK/d2c.out" 2>&1; rc=$?
sed 's/^/    | /' "$WORK/d2c.out" | head -4
expect "root(euid 0) — 감사 파일이 링크여도 경고 한 줄 · DB 행 · 다음 줄로 계속(after) · 종료 0 · 링크 끝 그대로" "euid=0 1 1 yes 0 X" \
  "$(grep -o '^euid=[0-9]*' "$WORK/d2c.out") $(grep -c '감사 파일 사본에 쓰지 못했다' "$WORK/d2c.out" | tr -d ' ') $(psqlq "SELECT count(*) FROM audit_logs WHERE id > $A0 AND action='alert.raised' AND target_name='AL-TEST'") $(grep -qx 'after' "$WORK/d2c.out" && echo yes || echo no) $rc $(cat "$WORK/elsewhere.log")"
rm -f "$AUD"; cp "$WORK/audit.keep" "$AUD"; chmod 640 "$AUD"
nget=0
for f in $(cd "$INST/app" && grep -rl 'getmyuid' src scripts public 2>/dev/null); do
  n=$(php -r '$n = 0; foreach (token_get_all(file_get_contents($argv[1])) as $t) { if (is_array($t) && $t[0] === T_STRING && strcasecmp($t[1], "getmyuid") === 0) { $n++; } } echo $n;' "$INST/app/$f")
  nget=$((nget + n))
done
expect "getmyuid() 호출(주석 밖 토큰) 0 — src · scripts · public" "0" "$nget"
else
  note "DBGS2_SKIP_BODY=1 — A~D 몸통을 건너뛴다(회귀 전용 실행: 계정 상태를 바꾸지 않는다)"
fi

# ════════════════════════════════════════════════════════════════
if [ "${DBGS2_BROWSER:-1}" = "1" ] && [ "${DBGS2_SKIP_BODY:-0}" != "1" ]; then
  hr "A-브라우저 — Chromium(헤드리스)이 http 127.0.0.1 에서 받은 __Host- 쿠키를 저장·전송하고 옛 쿠키는 지운다"
  PW_BR=$(review_password claude_admin01); SEC_BR=$(review_secret claude_admin01)
  cat > "$WORK/br.py" <<'PY'
import base64, hashlib, hmac, json, struct, sys, time
from playwright.sync_api import sync_playwright
SITE, UA = sys.argv[1:3]
PW = sys.stdin.readline().rstrip('\n'); SEC = sys.stdin.readline().rstrip('\n')
P = F = 0
def ok(n, c, d=''):
    global P, F
    if c: P += 1; print('  ✔ ' + n)
    else: F += 1; print('  ✖ ' + n + ('  — ' + str(d)[:400] if d != '' else ''))
def totp(t):
    k = base64.b32decode(SEC + '=' * (-len(SEC) % 8)); h = hmac.new(k, struct.pack('>Q', int(t // 30)), hashlib.sha1).digest()
    o = h[-1] & 15; return '%06d' % ((struct.unpack('>I', h[o:o + 4])[0] & 0x7fffffff) % 1000000)
host = SITE.split('//')[1].split(':')[0]
with sync_playwright() as p:
    b = p.chromium.launch()
    ctx = b.new_context(user_agent=UA)
    pg = ctx.new_page()
    pg.goto(SITE + '/tbox/login')
    res = None
    for attempt in range(3):
        # 로그인 요청은 **페이지 안 fetch** — 응답의 Set-Cookie 를 Chromium 의 쿠키 저장소가 받는다(비밀은 인자가 아니라 CDP 로)
        res = pg.evaluate('''async ([u, p, o]) => { const r = await fetch('/tbox/api/login', {method: 'POST', headers: {'Content-Type': 'application/json'},
                               body: JSON.stringify({username: u, password: p, otp: o}), credentials: 'same-origin'}); return [r.status, await r.text()]; }''',
                          ['claude_admin01', PW, totp(time.time())])
        if res[0] == 200: break
        time.sleep(31 - time.time() % 30)
    ok('페이지 안 로그인 200', res and res[0] == 200, res and res[0])
    cs = {c['name']: c for c in ctx.cookies()}
    c = cs.get('__Host-tbox_session') or {}
    ok('Chromium 이 쿠키를 저장 — 이름 __Host-tbox_session · secure · httpOnly · path / · 호스트 한정(도메인 = %s) · SameSite Lax' % host,
       c.get('secure') is True and c.get('httpOnly') is True and c.get('path') == '/' and c.get('domain') == host and c.get('sameSite') == 'Lax' and len(c.get('value', '')) == 64,
       {k: v for k, v in c.items() if k != 'value'})
    ok('  옛 이름 쿠키 없음', 'tbox_session' not in cs, list(cs))
    r = pg.goto(SITE + '/community/')
    ok('커뮤니티 화면 — 로그인 화면으로 가지 않는다(쿠키가 실린다)', r.status == 200 and '/tbox/login' not in pg.url, (r.status, pg.url))
    r = pg.goto(SITE + '/')
    ok('런처 — 로그인 뒤 타일 둘', r.status == 200 and pg.locator('a.card--link').count() == 2, pg.locator('a.card--link').count())
    ctx.close()
    ctx2 = b.new_context(user_agent=UA)
    ctx2.add_cookies([{'name': 'tbox_session', 'value': '0' * 64, 'domain': host, 'path': '/', 'secure': True, 'httpOnly': True, 'sameSite': 'Lax'}])
    pg2 = ctx2.new_page()
    r = pg2.goto(SITE + '/tbox/')
    ok('옛 쿠키만 — /tbox/ 가 로그인 화면으로', '/tbox/login' in pg2.url, pg2.url)
    ok('  브라우저에서 옛 쿠키가 지워졌다(만료 머리를 따른다)', 'tbox_session' not in {c['name'] for c in ctx2.cookies()}, [c['name'] for c in ctx2.cookies()])
    r = pg2.goto(SITE + '/')
    ok('  런처 — 앱 타일 0 · 로그인 단추 1', pg2.locator('a.card--link').count() == 0 and pg2.locator('a.btn[href="/tbox/login"]').count() == 1)
    ctx2.close(); b.close()
print('BR_PASS=%d BR_FAIL=%d' % (P, F))
PY
  py_secret PW_BR SEC_BR -- env PATH="$HOME/.local/bin:$PATH" python3 "$WORK/br.py" "$SITE" "$UA" > "$WORK/br.out" 2>&1
  unset PW_BR SEC_BR
  cat "$WORK/br.out" | grep -v '^BR_'
  bp=$(sed -n 's/^BR_PASS=\([0-9]*\) BR_FAIL=\([0-9]*\)$/\1/p' "$WORK/br.out"); bf=$(sed -n 's/^BR_PASS=\([0-9]*\) BR_FAIL=\([0-9]*\)$/\2/p' "$WORK/br.out")
  if [[ "$bp" =~ ^[0-9]+$ ]] && [[ "$bf" =~ ^[0-9]+$ ]]; then PASS=$((PASS + bp)); FAIL=$((FAIL + bf)); else echo "  ✖ 브라우저 시험이 끝줄을 못 찍었다"; tail -5 "$WORK/br.out"; FAIL=$((FAIL+1)); fi
fi

# ════════════════════════════════════════════════════════════════
hr "정적 — php -l · 보안 린트 · bash -n · 시험 틀의 쿠키 이름 · 서버 로그"
nbad=0; nphp=0
for f in src/Auth.php src/Http.php src/Audit.php src/site/launcher_page.php src/views/launcher.php \
         src/community/handlers/pages_get.php src/community/handlers/database_rows.php src/community/handlers/pages_list.php \
         src/community/handlers/versions_list.php src/community/handlers/pages_shares_list.php src/community/handlers/spaces_list.php \
         src/help/screens.php scripts/alerts.php scripts/lib/cli_bootstrap.php scripts/review_account_setup.php; do
  nphp=$((nphp+1))
  php -l "$ROOT/$f" >/dev/null 2>&1 || { echo "  ✖ php -l $f"; nbad=$((nbad+1)); }
done
expect "바뀐 PHP ${nphp}개 php -l 실패" "0" "$nbad"
( cd "$ROOT" && bash scripts/security_lint.sh > "$WORK/lint.txt" 2>&1 ); rc=$?
expect "보안 린트 종료 코드" "0" "$rc"
[ "$rc" -eq 0 ] || tail -8 "$WORK/lint.txt" | sed 's/^/    /'
expect "bash -n 이 시험 · q_launcher" "0 0" "$(bash -n "$0"; echo $?) $(bash -n "$ROOT/tests/q_launcher.sh"; echo $?)"
expect "검토 L-4 — 감사 조회 「어떻게」 허용 목록·화면 선택지에 auth.lock · auth.reauth" "1 1 1 1" \
  "$(grep -c "'auth.lock', 'auth.reauth'," "$ROOT/src/handlers/audit_list.php" | tr -d ' ') $(grep -c "'auth.lock', 'auth.reauth'," "$ROOT/src/handlers/audit_list.php" | tr -d ' ') $(grep -c '<option value="auth.lock">' "$ROOT/src/handlers/audit_page.php" | tr -d ' ') $(grep -c '<option value="auth.reauth">' "$ROOT/src/handlers/audit_page.php" | tr -d ' ')"
expect "Auth::COOKIE_NAME = __Host-tbox_session · LEGACY = tbox_session" "__Host-tbox_session tbox_session" \
  "$(php -r 'require $argv[1] . "/src/Auth.php"; echo Auth::COOKIE_NAME, " ", Auth::LEGACY_COOKIE_NAME;' "$ROOT" 2>/dev/null)"
# 시험 틀 전체 — 접두 없는 옛 이름을 세션 이름으로 쓰는 줄 0(가림 규칙·그 자체 시험·견본, 옛 이름 시험 셋은 빼고 센다)
BARE=$(cd "$ROOT" && git grep -nE '(^|[^A-Za-z0-9_-])tbox_session' -- tests scripts \
        ':!tests/lib/results.py' ':!tests/lib/selftest.sh' ':!tests/lib/fixtures/*' ':!tests/static/*' ':!tests/dbg_s2_security.sh' ':!tests/q_launcher.sh' ':!tests/qf3c_zip.sh' 2>/dev/null | wc -l)
expect "시험 틀·스크립트에서 접두 없는 tbox_session 0줄(가림 규칙·옛 이름 시험·qf3c 기준 판 인스턴스 제외)" "0" "$BARE"
expect "  qf3c_zip — 옛 이름은 기준 판(옛 코드) 인스턴스로 가는 요청에만 한 줄" "1" \
  "$(cd "$ROOT" && git grep -nE "(^|[^A-Za-z0-9_-])tbox_session=" -- tests/qf3c_zip.sh 2>/dev/null | grep -c 'OSITE' | tr -d ' ')"
# Playwright 에 넣는 `__Host-` 쿠키 사전({…})마다 — secure 참 · url 로 넣지 않음(url 이면 http 에서 secure 가 거짓으로 덮여 거부된다)
cat > "$WORK/ckdict.py" <<'PYCK'
import re, subprocess, sys
files = subprocess.run(['git', 'grep', '-l', '__Host-tbox_session', '--', 'tests', 'scripts'], capture_output=True, text=True).stdout.split()
NAME = re.compile(r"([\"'])name\1\s*:\s*([\"'])__Host-tbox_session\2")
bad = url = n = 0
for f in files:
    s = open(f, encoding='utf-8').read()
    for m in NAME.finditer(s):
        st = s.rfind('{', 0, m.start()); i = st; depth = 0
        while True:
            if s[i] == '{': depth += 1
            elif s[i] == '}':
                depth -= 1
                if depth == 0: break
            i += 1
        d = s[st:i + 1]; n += 1
        if re.search(r"([\"'])url\1\s*:", d): url += 1
        if not re.search(r"([\"'])secure\1\s*:\s*True", d): bad += 1
print(n, bad, url)
PYCK
CK=$(cd "$ROOT" && python3 "$WORK/ckdict.py")
expect "Playwright 가 넣는 __Host- 쿠키 사전(${CK%% *}개) — secure 참이 아닌 것 0 · url 로 넣는 것 0" "0 0" "${CK#* }"
[ "${CK%% *}" -ge 25 ] 2>/dev/null && expect "  사전을 실제로 셌다(25개 이상 — 헛돌지 않는다)" "yes" "yes" || expect "  사전을 실제로 셌다(25개 이상 — 헛돌지 않는다)" "yes" "no(${CK:-?})"
errs_after=$(server_errs)
expect "서버 로그의 새 PHP 오류·SQLSTATE 줄" "0" "$((errs_after - errs_before))"
[ "$((errs_after - errs_before))" = "0" ] || grep -iE 'PHP (Fatal|Warning|Notice|Deprecated)|SQLSTATE|Uncaught' "$INST/server.log" | tail -5 | sed 's/^/    /'

# ════════════════════════════════════════════════════════════════
if [ "${REGRESS:-0}" = "1" ]; then
  hr "회귀 — 같은 임시 인스턴스에서(dbg_s_security.sh 의 회귀 틀 그대로)"
  note "각 스크립트를 임시 인스턴스 폴더로 복사해 SITE·DB 만 바꿔 돈다(운영 8082·tbox_re 에 닿지 않는다)."
  sed -i "s#http://127.0.0.1:8082#$SITE#g" "$INST/app/scripts/ui_snapshot.sh" 2>/dev/null
  grep -q "http://127.0.0.1:8082" "$INST/app/scripts/ui_snapshot.sh" && { echo "  ✖ ui_snapshot.sh 에 운영 주소가 남았다 — 회귀를 돌리지 않는다"; FAIL=$((FAIL+1)); REGRESS_LIST=" "; }
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
  for s in ${REGRESS_LIST:-help_button api_whoami api_read api_folders f68 q_launcher}; do
    src="$INST/app/tests/$s.sh"
    dst="$INST/app/tests/$s.temp.sh"
    [ -f "$src" ] || { echo "  ✖ $s — 스크립트가 없다"; FAIL=$((FAIL+1)); continue; }
    if grep -qxF "$s" "$CAT_BLOCK"; then
      echo "  ✖ $s — 카탈로그상 운영에 쓰거나(writes_prod) 사람이 도는(manual_only) 시험이라 이 틀로 돌리지 않는다"; FAIL=$((FAIL+1)); continue
    fi
    sed -e "s#http://127.0.0.1:8082#$SITE#g" -e "s#^DB=\"tbox_re\"#DB=\"$DB\"#" -e "s#-d tbox_re #-d $DB #g" \
        -e "s#^TMPDB=\"tbox_\([a-z0-9_]*\)\"#TMPDB=\"tbox_d2s_\1\"#" "$src" > "$dst"
    grep -v '^\s*#' "$dst" > "$WORK/nc_$s.txt" || true
    if grep -q '127\.0\.0\.1:8082\|localhost:8082\|DB="tbox_re"\|-d tbox_re' "$WORK/nc_$s.txt"; then
      echo "  ✖ $s — 운영 주소·DB 가 남아 있어 돌리지 않는다"; FAIL=$((FAIL+1)); continue
    fi
    if grep -qE 'sudo[^|;&]*[[:space:]](install|cp|mv|tee|rm|ln|chmod|chown|chattr|truncate|dd|touch|mkdir|rmdir|sed[[:space:]]+-i)[[:space:]][^|;&]*(/etc/|/var/lib/tbox-v2|/var/log/tbox-v2|/var/backups)|>>?[[:space:]]*"?(/etc/|/var/lib/tbox-v2|/var/log/tbox-v2|/var/backups)|tee[^|;&]*(/etc/|/var/lib/tbox-v2|/var/log/tbox-v2|/var/backups)' "$WORK/nc_$s.txt"; then
      echo "  ✖ $s — 운영 /etc·/var/lib/tbox-v2·/var/log/tbox-v2·/var/backups 에 쓰는 줄이 있어 이 틀로 돌리지 않는다"; FAIL=$((FAIL+1)); continue
    fi
    echo "  ▸ $s (시작 $(date +%T))"
    env REGRESS=0 REGRESS_TEMP=0 bash "$dst" > "$WORK/regress_$(basename "$s").txt" 2>&1
    rc=$?
    line=$(grep -E '통과 [0-9]+|PASS|통과:|결과' "$WORK/regress_$(basename "$s").txt" | tail -1)
    echo "    종료 코드 $rc · ${line:-결과 줄 없음}"
    grep -E '^\s*(✖|FAIL)' "$WORK/regress_$(basename "$s").txt" | head -12 | sed 's/^/      /'
    [ -n "${REGRESS_KEEP:-}" ] && cp "$WORK/regress_$(basename "$s").txt" "$REGRESS_KEEP/"
    [ "$rc" = "0" ] && PASS=$((PASS+1)) || FAIL=$((FAIL+1))
  done
fi

if [ "${REGRESS_TEMP:-0}" = "1" ]; then
  hr "회귀 — 자기 임시 인스턴스를 띄우는 시험(이 묶음 규칙으로 고친 사본 · 포트 8262~8269 · 임시 DB 접두 tbox_d2s_)"
  HEAD_REV=$(git -C "$ROOT" rev-parse --short HEAD)
  [ -z "$(git -C "$ROOT" status --porcelain -- src scripts public tests config 2>/dev/null)" ] \
    || note "복제본에 커밋 안 된 변경이 있다 — 사본은 HEAD($HEAD_REV) 판으로 돈다(작업 트리가 아니다)"
  TC="$WORK/tc"
  git clone -q --shared --no-checkout "$ROOT" "$TC" && git -C "$TC" checkout -q --detach "$HEAD_REV" \
    || { echo "  ✖ 임시 복제를 만들지 못했다"; FAIL=$((FAIL+1)); REGRESS_TEMP_LIST=" "; }
  # 사본들의 .env 원본 — 이 인스턴스의 임시 .env(DB 이름은 각 시험이 자기 임시 DB 로 다시 바꾼다)
  # qcheck-allow: R20 — 임시 .env 사본(0700 WORK 안 · 0600 · trap 정리) · kill -9 뒤 남는 창은 받아들인 위험(HO-B1-004 — make_env 와 같다)
  ( umask 077; cp "$INST/app/.env" "$TC/.env" 2>/dev/null ) && chmod 600 "$TC/.env"
  CP=0; DROPS=""
  for s in ${REGRESS_TEMP_LIST:-qf3c_zip dbg_s_security dbg_u_ui community/stage6a community/stage7d1}; do
    src="$TC/tests/$s.sh"; dst="$TC/tests/$s.d2s.sh"
    [ -f "$src" ] || { echo "  ✖ $s — 스크립트가 없다"; FAIL=$((FAIL+1)); continue; }
    case "$s" in
      qf3c_zip)
        sed -e 's#^DB="tbox_qf3c_${PORT}_test"#DB="tbox_d2s_qf3c_${PORT}_test"#' "$src" > "$dst"
        ENVS="QF3C_PORT=8262 QF3C_BURST_PORT=8263 QF3C_SMALL_PORT=8264 QF3C_OLD_PORT=8265 QF3C_TIME_PORT=8266 QF3C_SHM_PORT=8267" ;;
      dbg_s_security)
        sed -e 's#case "$PORT" in 821\[1-9\])#case "$PORT" in 826[1-9])#' -e 's#^DB="tbox_d1s_${PORT}_test"#DB="tbox_d2s_s_${PORT}_test"#' \
            -e 's#case "$DB" in tbox_d1s_\*_test)#case "$DB" in tbox_d2s_*_test)#' "$src" > "$dst"
        ENVS="DBGS_PORT=8268" ;;
      dbg_u_ui)
        sed -e 's#^DB="tbox_d1u_${PORT}_test"#DB="tbox_d2s_u_${PORT}_test"#' "$src" > "$dst"
        ENVS="DBGU_PORT=8269" ;;
      community/stage*)
        # 커뮤니티 단계 시험 — 고정 포트 한 줄(PORT=80xx)과 임시 DB 한 줄(DB="tbox_<이름>_test")만 바꾼다. 포트는 8264~8269 를 차례로
        CP=$((CP + 1)); NP=$((8263 + CP))
        [ "$NP" -le 8269 ] || { echo "  ✖ $s — 포트 8264~8269 를 다 썼다 — 돌리지 않는다"; FAIL=$((FAIL+1)); continue; }
        sed -E -e "s#^PORT=[0-9]+\$#PORT=$NP#" -e 's#^DB="tbox_([a-z0-9]+)_test"#DB="tbox_d2s_\1_test"#' "$src" > "$dst"
        grep -qx "PORT=$NP" "$dst" || { echo "  ✖ $s — 포트 줄을 바꾸지 못했다 — 돌리지 않는다"; FAIL=$((FAIL+1)); continue; }
        ENVS="REGRESS=0" ;;
      *) echo "  ✖ $s — 이 틀이 고치는 법을 모르는 시험"; FAIL=$((FAIL+1)); continue ;;
    esac
    # 고친 뒤 확인 — 임시 DB 이름은 tbox_d2s_ 로만 · 포트 기본값은 8262~8269 로만(운영 포트·다른 묶음 포트 0)
    grep -v '^\s*#' "$dst" > "$WORK/nct.txt" || true
    if grep -qE '^DB="tbox_(qf3c|d1s|d1u|6a|7d1)_' "$WORK/nct.txt" || ! grep -qE '^DB="tbox_d2s_' "$WORK/nct.txt"; then
      echo "  ✖ $s — 임시 DB 이름을 tbox_d2s_ 로 바꾸지 못했다 — 돌리지 않는다"; FAIL=$((FAIL+1)); continue
    fi
    DROPS="$DROPS $(sed -n 's#^DB="\(tbox_d2s_[a-z0-9_]*\)"$#\1#p' "$dst" | head -1)"
    echo "  ▸ $s (시작 $(date +%T) · $ENVS)"
    # 사본의 자기 회귀는 끈다(REGRESS=0 — qf3c 의 기본 회귀 목록에는 운영에 쓰는 stage26·f65 가 있다)
    ( cd "$TC" && env REGRESS=0 REGRESS_TEMP=0 REGRESS_LIST= $ENVS bash "$dst" ) > "$WORK/regt_$(basename "$s").txt" 2>&1
    rc=$?
    line=$(grep -E '통과 [0-9]+|PASS|통과:|결과' "$WORK/regt_$(basename "$s").txt" | tail -1)
    echo "    종료 코드 $rc · ${line:-결과 줄 없음}"
    grep -E '^\s*(✖|FAIL)' "$WORK/regt_$(basename "$s").txt" | head -15 | sed 's/^/      /'
    [ -n "${REGRESS_KEEP:-}" ] && cp "$WORK/regt_$(basename "$s").txt" "$REGRESS_KEEP/"
    [ "$rc" = "0" ] && PASS=$((PASS+1)) || FAIL=$((FAIL+1))
  done
  # 사본들이 쓴 임시 DB 를 한 번의 psql 로 지운다(이름은 사본에서 읽은 것 — 접두 tbox_d2s_ 만)
  DSQL=""
  for d in tbox_d2s_qf3c_8262_test tbox_d2s_s_8268_test tbox_d2s_u_8269_test $DROPS; do
    case "$d" in tbox_d2s_*) DSQL="$DSQL
DROP DATABASE IF EXISTS $d;" ;; esac
  done
  pg_admin "$DSQL" >/dev/null 2>&1
fi

hr "결과"
PROD_END=$(prod_q "SELECT coalesce(max(id),0) || ' ' || count(*) FILTER (WHERE id > ${PROD_A0:-0} AND user_agent = '$UA') FROM audit_logs")   # 운영 읽기 — 끝 한 번
PROD_A1=${PROD_END%% *}; PROD_MINE=${PROD_END#* }
PROD_L1=$(prod_alerts_lines)
PROD_T1=$(prod_totp_mtime)
note "운영 감사 max(id) 끝 = ${PROD_A1:-?} (시작 ${PROD_A0:-?}) · 운영 alerts.log 끝 = ${PROD_L1:-?}줄 (시작 ${PROD_L0:-?}) · qa-totp mtime 끝 = ${PROD_T1:-?} (시작 ${PROD_T0:-?})"
note "  (운영 감사·경보가 늘었다면 운영 cron 이 이 시험의 sudo psql(임시 DB)을 AL-9 로 적은 것일 수 있다 — 읽기만)"
expect "운영 감사에 이 시험의 표식(UA) 행 0 — 운영에는 요청하지 않는다" "0" "${PROD_MINE:-?}"
expect "운영 /etc/tbox-v2/qa-totp.txt mtime 그대로" "$PROD_T0" "$PROD_T1"
printf '통과 %d · 실패 %d\n' "$PASS" "$FAIL"
note "임시 DB($DB)·임시 폴더·임시 서버는 이 스크립트가 끝나며 지워진다. 운영에는 아무것도 남지 않는다."
[ "$FAIL" -eq 0 ] || exit 1
exit 0
