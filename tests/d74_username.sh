#!/usr/bin/env bash
# tests/d74_username.sh — t-box d74 관리자 「로그인 아이디 바꾸기」 수락 시험 (사용자 결정 d74 2026-09-27 · SECURITY R-06 완화 ·
#                          구현 기록 docs/d74-username-change-implementation.md)
#
#   S 정적      바뀐 PHP php -l · 보안 린트 0 · 감사 대조(R2 — 코드 호출 ↔ 문장표 ↔ ARCH 7.4) · 더한 줄의 인라인 style·script·on* 0 ·
#               브라우저 기본 창 호출 0 · 라우트·판정·인증 파일(public/index.php · Authz · Auth · Http · Db) diff 0 ·
#               아이디 규칙이 한 곳(_username_common.php)이고 두 핸들러가 그것을 부른다 · 새 아이디 값을 적은 문서·코드 0(시험용 이름 밖)
#   A 창구      tests/d74_username_api.py — 양성·음성·공용 토큰 가드·「반영 전 확인」 항목(잠금·AL-1·AL-2·멘션·F-70·감사 거르기·노출·응답 시간)
#   D 시드      scripts/seed_users.php 재실행 가드 — 새 설치(빈 DB)는 전처럼 15계정 · 사람 계정이 이미 있는 DB 는 시드 사람 계정 0 ·
#               install.sh 7절과 같은 부르기(인자 없음 · 종료 코드 0 · 계정 줄 grep)가 새 설치에서 그대로 되는지(install.sh 는 돌리지 않는다)
#   B 화면      tests/browser/d74_username.py — 360 · 412(터치) · 1280 × 라이트 · 다크 (공용 잠금 flock 아래 · 메모리 여유 700MB 이상일 때만)
#   L 서버 로그 임시 인스턴스 로그에 새 PHP 오류 0
#   회귀(선택)  REGRESS=1 — 같은 임시 인스턴스에서 REGRESS_LIST(기본 help_button api_whoami q_launcher f68)를 사본으로(8082 → 이 인스턴스 · tbox_re → 임시 DB)
#
# ★ 운영 DB·운영 포트를 건드리지 않는다 — 임시 DB `tbox_d74_<포트>_test`(운영 pg_dump 를 **읽어** 파이프로 붓기만) · 시드 시험용 빈 DB
#   `tbox_d74_<포트>_seed_test` · 임시 인스턴스 `php -S 127.0.0.1:<포트>`(8341~8349 만 — 기본 8341) · 검토 계정 claude_ 다섯(F-71) ·
#   이름을 바꿔 보는 대상은 이 시험이 임시 DB 안에서 claude_admin01 로 새로 만드는 사람 계정(`d74…`)뿐 · 시드 계정 0.
#   임시 .env 원본 — 복제본에는 .env 가 없다: mktemp 폴더(0700)의 0600 사본을 D74_ENV_SRC 로 넘기고 끝에 지운다.
#   이 인스턴스의 .env 는 DB_NAME·STORAGE_DIR·AUDIT_LOG_FILE 을 임시로, SCAN_ENABLED=0, **공용 토큰 두 줄은 비운다**(운영 토큰 값을
#   임시 인스턴스에 두지 않는다 — 가드 시험은 시험이 만든 48자 값으로). 띄우기 전에 DB_NAME 이 tbox_re 가 아닌지 본다.
#   경보 배치(alerts.php)는 **임시 사본**을 임시 .env 로 --dry-run 만(상태 파일 = 작업 폴더). 운영 배치를 운영 설정으로 돌리지 않는다.
#   운영 감사 max(id)·운영 alerts.log 줄 수·/etc/tbox-v2/qa-totp.txt mtime 을 시작·끝에 적는다(읽기만).
# ★ sudo psql 은 명령 줄이 늘 같은 모양(SQL 은 표준입력 — 운영 경보 AL-9): 임시 DB `psql -X -q -At -d tbox_d74_<포트>_test` ·
#   시드 빈 DB `… -d tbox_d74_<포트>_seed_test` · 만들고 지우기 `… -d postgres` · 운영 읽기 `… -d tbox_re`(시작·끝 한 번씩).
# 손잡이: D74_PORT · D74_SKIP_BROWSER=1 · D74_COMBOS(화면 조합) · D74_SHOTS(스크린샷 폴더) · REGRESS=1 · REGRESS_LIST · REGRESS_KEEP ·
#         D74_REGRESS_ONLY=1(회귀만 — 창구 시험이 만든 사람 이름 계정이 회귀의 R-73 사전 검사를 멈추게 하므로 따로 돈다) ·
#         D74_APP_TREE=<폴더>(D74_REGRESS_ONLY=1 과 함께만 — 기준 판 트리를 띄워 회귀 실패가 새것인지 대조)
# 사용: sudo -v && D74_ENV_SRC=<0600 사본> bash tests/d74_username.sh
set -u
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
. "$ROOT/tests/lib/review_accounts.sh" || { echo "tests/lib/review_accounts.sh 를 읽지 못했다 — 시험을 시작하지 않는다"; exit 3; }
. "$ROOT/tests/lib/secrets.sh" || { echo "tests/lib/secrets.sh 를 읽지 못했다 — 시험을 시작하지 않는다 (R-62)"; exit 3; }
PORT="${D74_PORT:-8341}"
case "$PORT" in 834[1-9]) ;; *) echo "포트는 8341~8349 만 쓴다(받은 값: $PORT)"; exit 3 ;; esac
SITE="http://127.0.0.1:$PORT"
BASE="$SITE/tbox"
UA="tbox-review/$(date +%F)"
SRC_DB="tbox_re"                     # 운영 — **읽기만** 한다(pg_dump · max(id))
DB="tbox_d74_${PORT}_test"           # 임시 — 여기에만 쓴다
SEED_DB="tbox_d74_${PORT}_seed_test" # 시드 가드 시험용 빈 DB
case "$DB" in tbox_d74_*_test) ;; *) echo "임시 DB 이름 규칙 밖 — 중단"; exit 3 ;; esac
BASE_REV="${D74_BASE_REV:-f106e30}"  # 이 묶음을 얹은 운영 판(v2.31.0 fd96aca 위에 nginx 비밀 창구 속도 제한 f106e30)
PROD_ALERTS="/var/log/tbox-v2/alerts.log"
JAR_DIR="$(mktemp -d)"
WORK="$(mktemp -d)"
chmod 700 "$JAR_DIR" "$WORK"
SHOTS="${D74_SHOTS:-$WORK/shots}"
INST=""
SPID=""
PASS=0
FAIL=0

hr()  { printf '\n════════════════════════════════════════════════════════════\n%s\n════════════════════════════════════════════════════════════\n' "$1"; }
expect() {
  if [ "$2" = "$3" ]; then printf '  ✔ %s  (기대=%s 실제=%s)\n' "$1" "$2" "$3"; PASS=$((PASS+1))
  else printf '  ✖ %s  (기대=%s 실제=%s)\n' "$1" "$2" "$3"; FAIL=$((FAIL+1)); fi
}
note() { printf '  · %s\n' "$1"; }

psqlq()   { sudo -n -u postgres psql -X -q -At -d "$DB" 2>/dev/null <<< "$1"; }
seedq()   { sudo -n -u postgres psql -X -q -At -d "$SEED_DB" 2>/dev/null <<< "$1"; }
pg_admin(){ sudo -n -u postgres psql -X -q -At -d postgres 2>/dev/null <<< "$1"; }
prodq()   { sudo -n -u postgres psql -X -q -At -d "$SRC_DB" 2>/dev/null <<< "$1"; }   # 운영 — SELECT 만 · 시작·끝 한 번씩
review_password() { sudo -n awk -v u="$1" '$1==u{print $3}' /etc/tbox-v2/seed-accounts.txt 2>/dev/null; }
review_secret()   { sudo -n awk -v u="$1" '$1==u{print $2}' /etc/tbox-v2/qa-totp.txt 2>/dev/null; }

cleanup() {
  [ -n "$SPID" ] && { kill "$SPID" 2>/dev/null; wait "$SPID" 2>/dev/null; }
  pkill -f "^php -S 127\.0\.0\.1:$PORT " 2>/dev/null
  pg_admin "DROP DATABASE IF EXISTS $DB;" >/dev/null 2>&1
  pg_admin "DROP DATABASE IF EXISTS $SEED_DB;" >/dev/null 2>&1
  [ -n "$INST" ] && rm -rf "$INST"
  rm -rf "$JAR_DIR" "$WORK"
}
trap cleanup EXIT

make_env() {   # $1 원본 $2 대상 $3 저장 폴더 $4 감사 파일 $5 DB. 값은 출력하지 않는다.
  # qcheck-allow: R20 — 임시 인스턴스용 사본(0700 폴더 · 0600 · trap 정리)
  sed -e "s#^DB_NAME=.*#DB_NAME=$5#" \
      -e "s#^STORAGE_DIR=.*#STORAGE_DIR=$3#" \
      -e "s#^AUDIT_LOG_FILE=.*#AUDIT_LOG_FILE=$4#" \
      -e "s#^SCAN_ENABLED=.*#SCAN_ENABLED=0#" \
      -e "s#^API_TOKEN=.*#API_TOKEN=#" \
      -e "s#^API_TOKEN_USER=.*#API_TOKEN_USER=#" \
      "$1" > "$2"
  chmod 600 "$2"
  grep -q "^DB_NAME=$5\$" "$2" && ! grep -q '^DB_NAME=tbox_re$' "$2" \
    && grep -q "^STORAGE_DIR=$3\$" "$2" && grep -q "^AUDIT_LOG_FILE=$4\$" "$2" && grep -q '^API_TOKEN=$' "$2"
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
review_lines() {   # 검토 계정 다섯 줄 `아이디 비밀번호 2단계비밀` — 표준출력으로만(파이프로 바로 넘긴다 · 변수·인자에 남기지 않는다)
  local who pw sec
  for who in claude_admin01 claude_manager01 claude_employee01 claude_employee02 claude_guest01; do
    pw=$(review_password "$who"); sec=$(review_secret "$who")
    printf '%s %s %s\n' "$who" "$pw" "$sec"
  done
  pw=''; sec=''
}

# ════════════════════════════════════════════════════════════════
hr "d74 — 임시 인스턴스 준비 (운영 DB 에는 쓰지 않는다)"
if ss -ltn 2>/dev/null | grep -q "127.0.0.1:$PORT "; then echo "포트 $PORT 이 이미 쓰이고 있다 — 중단"; exit 3; fi
ENV_SRC="${D74_ENV_SRC:-}"
[ -n "$ENV_SRC" ] && [ -f "$ENV_SRC" ] || { echo "임시 .env 원본이 없다 — D74_ENV_SRC 로 0600 사본을 넘긴다"; exit 3; }
PROD_A0=$(prodq "SELECT coalesce(max(id),0) FROM audit_logs;")   # 운영 읽기 — 시작 한 번
[[ "$PROD_A0" =~ ^[0-9]+$ ]] || { echo "psql(postgres) 로 운영 감사 max(id) 를 읽지 못했다 — 중단"; exit 3; }
ALERTS_L0=$(sudo -n wc -l < "$PROD_ALERTS" 2>/dev/null || echo '?')
TOTP_M0=$(sudo -n stat -c %y /etc/tbox-v2/qa-totp.txt 2>/dev/null || echo '?')
note "운영 감사 max(id) 시작 = $PROD_A0 · 운영 alerts.log 줄 수 시작 = $ALERTS_L0 · qa-totp mtime 시작 = $TOTP_M0 (셋 다 읽기만)"

pg_admin "DROP DATABASE IF EXISTS $DB;" >/dev/null 2>&1
pg_admin "CREATE DATABASE $DB OWNER tbox_owner;" >/dev/null 2>&1
sudo -n -u postgres pg_dump -d "$SRC_DB" 2>/dev/null \
  | sudo -n -u postgres psql -X -q -At -d "$DB" >/dev/null 2>"$WORK/restore.err"
CNT=$(psqlq "SELECT (SELECT count(*) FROM users) || ' ' || (SELECT count(*) FROM community.pages);")
[ -n "$CNT" ] || { echo "임시 DB 로 옮기지 못했다 — $(head -2 "$WORK/restore.err")"; exit 3; }
note "임시 DB $DB — 계정 ${CNT%% *} · 페이지 ${CNT##* }"
# 임시 DB 의 claude_ 2단계 비밀을 /etc/tbox-v2/qa-totp.txt 값에 맞추고 잠금을 푼다(**임시 DB 만** · 파일은 읽기만 · 값은 표준입력으로만)
sudo -n awk 'BEGIN { print "\\set ON_ERROR_STOP on" } $1 ~ /^claude_[a-z0-9]+$/ && $2 ~ /^[A-Z2-7]+$/ { printf "UPDATE users SET mfa_secret = %c%s%c, mfa_last_step = NULL, failed_count = 0, locked_until = NULL WHERE username = %c%s%c;\n", 39, $2, 39, 39, $1, 39 }' /etc/tbox-v2/qa-totp.txt \
  | sudo -n -u postgres psql -X -q -At -d "$DB" >/dev/null 2>&1

INST="$(mktemp -d)"; chmod 700 "$INST"
mkdir -p "$INST/app" "$INST/storage" "$INST/tmp" "$INST/log"
APP_TREE="${D74_APP_TREE:-$ROOT}"   # 띄울 트리 — 기본은 이 복제본. 회귀 대조(기준 판)를 돌릴 때만 다른 폴더(D74_REGRESS_ONLY=1 과 함께)
[ "$APP_TREE" = "$ROOT" ] || [ "${D74_REGRESS_ONLY:-0}" = "1" ] || { echo "D74_APP_TREE 는 D74_REGRESS_ONLY=1 과 함께만"; exit 3; }
[ -d "$APP_TREE/public" ] && [ -d "$APP_TREE/src" ] || { echo "띄울 트리($APP_TREE)에 public·src 가 없다"; exit 3; }
[ "$APP_TREE" = "$ROOT" ] || note "띄우는 트리: $APP_TREE (D74_APP_TREE — 회귀 대조)"
tar -C "$APP_TREE" -cf - --exclude=.git --exclude=jar --exclude=api-test.txt --exclude=./.env --exclude=./.claude --exclude=__pycache__ . | tar -C "$INST/app" -xf -
make_env "$ENV_SRC" "$INST/app/.env" "$INST/storage" "$INST/log/audit.log" "$DB" || { echo "임시 .env 에 임시 값이 들어가지 않았다 — 중단"; exit 4; }
note "임시 .env 확인: $(grep '^DB_NAME=' "$INST/app/.env") · 공용 토큰 줄 비움"
ln -s /var/www/qaredi/community "$INST/community" 2>/dev/null
make_router "$INST/app"
( cd "$INST/app" && exec php -S "127.0.0.1:$PORT" -t public router.php > "$INST/server.log" 2>&1 ) &
SPID=$!
c=""
for _ in $(seq 1 30); do sleep 0.3; c=$(curl -s -o /dev/null -w '%{http_code}' -A "$UA" "$BASE/login"); [ "$c" = "200" ] && break; done
expect "인스턴스가 떴다(로그인 화면 200)" "200" "$c"
[ "$c" = "200" ] || { tail -5 "$INST/server.log"; exit 4; }

hr "S 정적 (이 복제본 — 기준 판 $BASE_REV 대비)"
PHPBAD=0
for f in $(git -C "$ROOT" diff --name-only "$BASE_REV" -- '*.php' 2>/dev/null) $(git -C "$ROOT" ls-files --others --exclude-standard -- '*.php' 2>/dev/null); do
  [ -f "$ROOT/$f" ] || continue
  php -l "$ROOT/$f" >/dev/null 2>&1 || { PHPBAD=$((PHPBAD+1)); echo "    php -l 실패: $f"; }
done
expect "바뀐·새 PHP php -l 실패 0" "0" "$PHPBAD"
bash "$ROOT/scripts/security_lint.sh" "$ROOT" > "$WORK/lint.txt" 2>&1
expect "보안 린트 통과(금지 패턴 0건)" "1" "$(grep -c '결과: 통과' "$WORK/lint.txt")"
python3 "$ROOT/tests/static/r2_audit_crosscheck.py" --root "$ROOT" --checks table > "$WORK/r2.txt" 2>&1; rc=$?
expect "감사 대조 R2 table(코드 호출 ↔ 문장표 ↔ ARCH 7.4) 발견 0 — $(tail -1 "$WORK/r2.txt")" "0" "$rc"
[ "$rc" = "0" ] || grep '^FINDING' "$WORK/r2.txt" | head -8 | sed 's/^/    /'
python3 "$ROOT/tests/static/r2_audit_crosscheck.py" --root "$ROOT" --checks deny > "$WORK/r2d.txt" 2>&1; rc=$?
R2D_NEW=$(grep '^FINDING' "$WORK/r2d.txt" | grep -cE 'users_update|users_create|_username_common')
expect "감사 대조 R2 deny — 이 묶음 파일(users_update · users_create · _username_common)의 거부 갈래 감사 누락 0" "0" "$R2D_NEW"
INL=$(git -C "$ROOT" diff "$BASE_REV" -- src public 2>/dev/null | grep '^+' | grep -v '^+++' | grep -cE 'style="|<script>|<script [^s]| on[a-z]+="')
expect "더한 줄에 인라인 style·script·on* 속성 0" "0" "$INL"
CALLS=$(grep -rnE 'window\.(prompt|confirm|alert)\(' "$ROOT/public/assets" --include=*.js | grep -vE '^[^:]+:[0-9]+:\s*(\*|//|/\*)' | wc -l)
expect "브라우저 기본 창 호출 0 (주석 제외)" "0" "$CALLS"
expect "라우트·판정·인증 파일 diff 0 — public/index.php · Authz · Auth · Http · Db" "0" \
  "$(git -C "$ROOT" diff "$BASE_REV" -- public/index.php src/Authz.php src/Auth.php src/Http.php src/Db.php 2>/dev/null | wc -l)"
expect "아이디 형식 검사가 두 핸들러에 직접 없다(preg_match(Auth::USERNAME_RE …) = _username_common 한 곳)" "0|1" \
  "$(cat "$ROOT/src/handlers/users_create.php" "$ROOT/src/handlers/users_update.php" | grep -c 'preg_match(Auth::USERNAME_RE')|$(grep -c 'preg_match(Auth::USERNAME_RE' "$ROOT/src/handlers/_username_common.php")"
expect "두 핸들러가 같은 아이디 규칙 파일을 싣는다" "2" \
  "$(grep -l "require_once __DIR__ . '/_username_common.php'" "$ROOT/src/handlers/users_create.php" "$ROOT/src/handlers/users_update.php" | wc -l)"
expect "두 핸들러가 같은 판정 함수(tbox_username_unavailable)를 부른다" "2" \
  "$(grep -l 'tbox_username_unavailable(\$db' "$ROOT/src/handlers/users_create.php" "$ROOT/src/handlers/users_update.php" | wc -l)"

ONLY_R="${D74_REGRESS_ONLY:-0}"   # 1 = 회귀만(창구·시드·화면을 건너뛴다 — 시험용 사람 계정이 R-73 사전 검사에 「쓰는 중」으로 잡히지 않게)
if [ "$ONLY_R" != "1" ]; then
hr "A 창구 — tests/d74_username_api.py"
review_lines | python3 -u "$ROOT/tests/d74_username_api.py" "$SITE" "$UA" "$DB" "$INST/app/.env" "$INST/app" "$WORK" 2>&1 | tee "$WORK/api.out"
af=$(sed -n 's/^SMOKE_FAIL=\([0-9]*\)$/\1/p' "$WORK/api.out" | tail -1)
ap=$(grep -c '^  ✔' "$WORK/api.out")
expect "창구 시험 실패 수" "0" "${af:-?}"
[ "$ap" -ge 110 ] && expect "창구 시험이 헛돌지 않았다(✔ 110 이상)" "yes" "yes" || expect "창구 시험이 헛돌지 않았다(✔ 110 이상)" "yes" "no($ap)"
PASS=$((PASS + ap)); FAIL=$((FAIL + ${af:-1}))

hr "D 시드 재실행 가드 — scripts/seed_users.php (빈 DB · 사람 계정이 있는 DB)"
pg_admin "DROP DATABASE IF EXISTS $SEED_DB;" >/dev/null 2>&1
pg_admin "CREATE DATABASE $SEED_DB OWNER tbox_owner;" >/dev/null 2>&1
sudo -n -u postgres pg_dump --schema-only -d "$SRC_DB" 2>/dev/null \
  | sudo -n -u postgres psql -X -q -At -d "$SEED_DB" >/dev/null 2>"$WORK/seed-restore.err"
seedq "INSERT INTO teams (name) VALUES ('개발팀'), ('영업팀');" >/dev/null
expect "시드 빈 DB 준비 — 계정 0 · 팀 2" "0 2" "$(seedq "SELECT (SELECT count(*) FROM users) || ' ' || (SELECT count(*) FROM teams);")"
mkdir -p "$INST/seedapp"
cp -a "$INST/app/scripts" "$INST/app/src" "$INST/seedapp/"
make_env "$ENV_SRC" "$INST/seedapp/.env" "$INST/storage" "$INST/log/seed-audit.log" "$SEED_DB" || { echo "시드용 .env 를 만들지 못했다"; exit 4; }
# install.sh 7절과 **같은 부르기**: 인자 없음 · 종료 코드 · 계정 줄 grep (install.sh 자체는 돌리지 않는다 — root · /etc 에 쓴다)
SEED_OUT="$( (cd / && php "$INST/seedapp/scripts/seed_users.php") 2>"$WORK/seed1.err")"; SEED_RC=$?
SEED_NEW="$(printf '%s\n' "$SEED_OUT" | grep -E '^[a-z0-9_.-]+ (Admin|Manager|Employee|Guest) [0-9a-f]{16}$' || true)"
expect "새 설치(빈 DB) — install.sh 7절 모양으로 부르면 종료 코드 0" "0" "$SEED_RC"
expect "  계정 줄 15(시드 5 · qa 5 · claude 5 — 전과 같다)" "15" "$(printf '%s\n' "$SEED_NEW" | grep -c .)"
expect "  DB 계정 15 · 시드 사람 5" "15 5" "$(seedq "SELECT count(*) || ' ' || count(*) FILTER (WHERE username NOT LIKE 'qa\_%' AND username NOT LIKE 'claude\_%') FROM users;")"
expect "  가드 알림 0(새 설치)" "0" "$(grep -c '시드 사람 계정은 만들지 않습니다' "$WORK/seed1.err")"
SEED_OUT=''; SEED_NEW=''
# 사람 계정이 있는 DB — 시드 이름 하나가 빠진 상태(아이디를 바꾼 뒤와 같은 꼴)를 빈 DB 에서 다시 만든다
pg_admin "DROP DATABASE IF EXISTS $SEED_DB;" >/dev/null 2>&1
pg_admin "CREATE DATABASE $SEED_DB OWNER tbox_owner;" >/dev/null 2>&1
sudo -n -u postgres pg_dump --schema-only -d "$SRC_DB" 2>/dev/null \
  | sudo -n -u postgres psql -X -q -At -d "$SEED_DB" >/dev/null 2>>"$WORK/seed-restore.err"
seedq "INSERT INTO teams (name) VALUES ('개발팀'), ('영업팀'); INSERT INTO users (username, password_hash, role) VALUES ('d74human', 'x', 'Admin');" >/dev/null
SEED_OUT="$( (cd / && php "$INST/seedapp/scripts/seed_users.php") 2>"$WORK/seed2.err")"; SEED_RC=$?
SEED_NEW="$(printf '%s\n' "$SEED_OUT" | grep -E '^[a-z0-9_.-]+ (Admin|Manager|Employee|Guest) [0-9a-f]{16}$' || true)"
expect "사람 계정이 있는 DB — 종료 코드 0(install.sh 가 7절에서 멈추지 않는다)" "0" "$SEED_RC"
expect "  시드 사람 계정 새로 0 · 검토 계정 10 만듦" "0 10" \
  "$(seedq "SELECT count(*) FILTER (WHERE username NOT LIKE 'qa\_%' AND username NOT LIKE 'claude\_%' AND username <> 'd74human') || ' ' || count(*) FILTER (WHERE username LIKE 'qa\_%' OR username LIKE 'claude\_%') FROM users;")"
expect "  표준오류에 가드 알림 한 줄(이름을 찍지 않는다)" "1" "$(grep -c '시드 사람 계정은 만들지 않습니다 — 이 DB 에 사람 계정이 이미 1개' "$WORK/seed2.err")"
expect "  계정 줄은 검토 계정 10 뿐" "10" "$(printf '%s\n' "$SEED_NEW" | grep -cE '^(qa|claude)_')"
SEED_OUT="$( (cd / && php "$INST/seedapp/scripts/seed_users.php" --no-qa) 2>"$WORK/seed3.err")"; SEED_RC=$?
expect "  --no-qa 도 종료 코드 0 · 새 계정 0 · 가드 알림" "0|건너뜀|1" \
  "$SEED_RC|$(printf '%s' "$SEED_OUT" | grep -o '건너뜀' | head -1)|$(grep -c '시드 사람 계정은 만들지 않습니다' "$WORK/seed3.err")"
SEED_OUT="$( (cd / && php "$INST/seedapp/scripts/seed_users.php" --claude-only) 2>"$WORK/seed4.err")"; SEED_RC=$?
expect "  --claude-only 는 전과 같다(종료 0 · 가드 알림 0)" "0|0" "$SEED_RC|$(grep -c '시드 사람 계정은' "$WORK/seed4.err")"
SEED_OUT=''; SEED_NEW=''

hr "B 화면 — tests/browser/d74_username.py (공용 잠금 아래 한 번에 하나)"
if [ "${D74_SKIP_BROWSER:-0}" = "1" ]; then
  note "D74_SKIP_BROWSER=1 — 화면 시험을 건너뛴다(통과로 세지 않는다)"
else
  LOCK=/tmp/claude-1001/-home-changkwon/810602d5-dec7-4514-b7a6-60a75cf245d5/scratchpad/browser.lock
  mkdir -p "$SHOTS"
  # 화면 시험용 관리자 세션 — 새로 로그인(재인증은 화면 시험 안에서)
  pwA=$(review_password claude_admin01); secA=$(review_secret claude_admin01)
  S_A=""
  for try in 1 2 3; do
    left=$(( 30 - $(date +%s) % 30 )); [ "$left" -lt 4 ] && sleep $((left + 1))
    raw=$(totp_now secA 2>/dev/null); code=${raw%%$'\n'*}
    printf -v LB '{"username":"claude_admin01","password":"%s","otp":"%s"}' "$pwA" "$code"
    out=$(curl_json_secret LB -s -c "$JAR_DIR/ba.jar" -A "$UA" -H 'Content-Type: application/json' "$BASE/api/login"); LB=''
    case "$out" in *'"ok":true'*) S_A=$(awk '$6=="__Host-tbox_session"{print $7}' "$JAR_DIR/ba.jar" | tail -1); break ;; esac
    sleep 31
  done
  # 재인증은 화면 시험이 잠금을 쥔 **뒤** 같은 세션으로 한다(비밀번호는 표준입력 — CSRF·비밀번호를 명령 인자에 싣지 않는다 · R15)
  expect "화면 시험용 관리자 로그인 200" "yes" "$([ -n "$S_A" ] && echo yes || echo no-session)"
  waited=0
  while [ "$(free -m | awk '/^Mem:/{print $7}')" -lt 700 ] && [ "$waited" -lt 15 ]; do
    note "메모리 여유 $(free -m | awk '/^Mem:/{print $7}')MB < 700MB — 1분 기다린다($waited/15)"; sleep 60; waited=$((waited+1))
  done
  if [ "$(free -m | awk '/^Mem:/{print $7}')" -lt 700 ]; then
    expect "화면 시험 — 메모리 여유 700MB 이상" "yes" "no"
  else
    { printf '%s\n%s\n' "$S_A" "$pwA" | flock "$LOCK" env PATH="$HOME/.local/bin:$PATH" D74_COMBOS="${D74_COMBOS:-}" \
        python3 -u "$ROOT/tests/browser/d74_username.py" "$SITE" "$UA" "$SHOTS" "$ROOT" 2>&1
      echo "RC=$?"; } | tee "$WORK/browser.out"
    rc=$(sed -n 's/^RC=\([0-9]*\)$/\1/p' "$WORK/browser.out" | tail -1)
    bf=$(sed -n 's/^SMOKE_FAIL=\([0-9]*\)$/\1/p' "$WORK/browser.out" | tail -1)
    bp=$(grep -c '^  ✔' "$WORK/browser.out")
    expect "화면 시험 종료 코드" "0" "$rc"
    expect "화면 시험 실패 수" "0" "${bf:-?}"
    [ "$bp" -ge 60 ] && expect "화면 시험이 헛돌지 않았다(✔ 60 이상)" "yes" "yes" || expect "화면 시험이 헛돌지 않았다(✔ 60 이상)" "yes" "no($bp)"
    note "스크린샷: $SHOTS (사람이 직접 연다)"
  fi
  pwA=''; secA=''
fi

fi   # ONLY_R

hr "L 서버 로그"
expect "임시 인스턴스 로그의 PHP 오류(Fatal·Warning·Notice·Deprecated)" "0" \
  "$(grep -cE 'PHP (Fatal|Warning|Notice|Deprecated|Parse)' "$INST/server.log")"

if [ "${REGRESS:-0}" = "1" ]; then
  hr "회귀 — 같은 임시 인스턴스에서 (dbg_u2_ui.sh 의 회귀 틀 그대로)"
  CAT="$ROOT/tests/catalog.json"
  sed -i "s#http://127.0.0.1:8082#$SITE#g" "$INST/app/scripts/ui_snapshot.sh" 2>/dev/null   # help_button (d) 가 부르는 화면 찍개 — 이 인스턴스로
  for s in ${REGRESS_LIST:-help_button api_whoami q_launcher f68}; do
    bad=$(python3 -c 'import json,sys; c=json.load(open(sys.argv[1])); t=[x for x in c["tests"] if x.get("cmd","").split(" ")[-1] == "tests/" + sys.argv[2] + ".sh"]; print("1" if (not t) or any(x.get("writes_prod") or x.get("status") == "manual_only" for x in t) else "0")' "$CAT" "$s" 2>/dev/null)
    [ "$bad" = "1" ] && { echo "  ✖ $s — 카탈로그에 없거나 writes_prod·manual_only 라 돌리지 않는다"; FAIL=$((FAIL+1)); continue; }
    src="$INST/app/tests/$s.sh"; dst="$INST/app/tests/$s.temp.sh"
    [ -f "$src" ] || { echo "  ✖ $s — 스크립트가 없다"; FAIL=$((FAIL+1)); continue; }
    sed -e "s#http://127.0.0.1:8082#$SITE#g" -e "s#^DB=\"tbox_re\"#DB=\"$DB\"#" -e "s#-d tbox_re #-d $DB #g" "$src" > "$dst"
    if grep -v '^\s*#' "$dst" | grep -q '8082\|DB="tbox_re"\|-d tbox_re'; then
      echo "  ✖ $s — 운영 주소·DB 가 남아 있어 돌리지 않는다"; FAIL=$((FAIL+1)); continue
    fi
    sleep $((31 - $(date +%s) % 30))
    echo "  ▸ $s (시작 $(date +%T))"
    needs_browser=$(python3 -c 'import json,sys; c=json.load(open(sys.argv[1])); print(any("browser" in x.get("needs",[]) for x in c["tests"] if x.get("cmd","").split(" ")[-1] == "tests/" + sys.argv[2] + ".sh"))' "$CAT" "$s" 2>/dev/null)
    if [ "$needs_browser" = "True" ]; then   # 브라우저를 띄우는 회귀는 공용 잠금 아래에서(한 번에 하나)
      flock /tmp/claude-1001/-home-changkwon/810602d5-dec7-4514-b7a6-60a75cf245d5/scratchpad/browser.lock bash "$dst" > "$WORK/regress_$s.txt" 2>&1
    else
      bash "$dst" > "$WORK/regress_$s.txt" 2>&1
    fi
    rc=$?
    line=$(grep -E '통과 [0-9]+|PASS|통과:|결과' "$WORK/regress_$s.txt" | tail -1)
    echo "    종료 코드 $rc · ${line:-결과 줄 없음}"
    grep -E '^\s*(✖|FAIL)' "$WORK/regress_$s.txt" | head -12 | sed 's/^/      /'
    [ -n "${REGRESS_KEEP:-}" ] && cp "$WORK/regress_$s.txt" "$REGRESS_KEEP/"
    [ "$rc" = "0" ] && PASS=$((PASS+1)) || FAIL=$((FAIL+1))
  done
fi

hr "운영 대조 (읽기만)"
END=$(prodq "SELECT coalesce(max(id),0) || '|' || (SELECT count(*) FROM audit_logs WHERE id > ${PROD_A0:-0} AND (target_name LIKE 'd74%' OR actor_name LIKE 'd74%')) FROM audit_logs;")
PROD_A1=${END%%|*}; D74_ROWS=${END##*|}
ALERTS_L1=$(sudo -n wc -l < "$PROD_ALERTS" 2>/dev/null || echo '?')
TOTP_M1=$(sudo -n stat -c %y /etc/tbox-v2/qa-totp.txt 2>/dev/null || echo '?')
note "운영 감사 max(id) 끝 = ${PROD_A1:-?} (시작 $PROD_A0) · 운영 alerts.log 끝 = $ALERTS_L1줄 (시작 $ALERTS_L0) · qa-totp mtime 끝 = $TOTP_M1"
note "운영 감사에 늘어난 행은 운영 사용·다른 작업자 몫이다 — 이 시험은 운영 포트에 요청하지 않는다(행 목록은 보고에서 actor·UA 로 가른다)"
expect "운영 /etc/tbox-v2/qa-totp.txt mtime 그대로" "$TOTP_M0" "$TOTP_M1"
expect "운영 감사에 이 시험의 대상 이름(d74…) 행 0" "0" "${D74_ROWS:-?}"

printf '\n통과 %d · 실패 %d\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ]
