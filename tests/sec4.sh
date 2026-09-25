#!/usr/bin/env bash
# tests/sec4.sh — 보안 넷 수락 시험 (사용자 결정 d52 「① 넷 다 진행」 2026-09-25 · 구현 기록 docs/sec4-implementation.md)
#
#   S4-M  마이그레이션(임시 DB) — users.temp_password_issued_at 칸 · 기존 행 NULL · 앱 계정 권한 · 다시 돌려도 오류 없음
#   S4-39 R-39 임시 비밀번호 유효기간 72시간 — claude_admin01 이 임시 DB 에 계정을 만들고(POST /api/users) 발급 시각을
#         SQL 로 돌려 가며 로그인: 71시간·71시간 59분 → 정상 흐름(next=password_change) · 72시간 1분·73시간 → 401 사람 문장 +
#         감사 auth.login deny temp_expired(요약 문장) · 세션 쿠키 없음 · 실패 횟수 그대로 · 틀린 비밀번호는 옛 동일 문구 ·
#         2단계 인증이 켜진 계정도 만료가 먼저 · 발급 시각 NULL(옛 발급)은 만료 없음 · 비밀번호를 바꾸면 NULL ·
#         재발급(POST /api/users/{id}/password/reset)이 옛 시각을 새로 · 로그인 화면(헤드리스)에 그 문장이 보인다
#   S4-79 R-79 GET /api/time 의 now_ms · 옛 생성기(335791d)와 새 생성기의 「경계 직후 +0.25초」 코드 대조(대조용 기록)
#         — 수락 판정은 회귀 묶음의 tests/totp_time_sync.sh (e)(이어지는 스텝 3개)
#   S4-100 R-100 AL-9 — 사본 auth.log(--auth-log) · 임시 상태 파일(--state) · --dry-run 으로 절대 경로 rm 경계 줄
#         (본 시험은 tests/f69.sh T3 — 여기는 같은 인스턴스에서 한 번 더 짧게)
#   S4-S  정적 — security_lint 0 · 바뀐 PHP php -l · bash -n · wiki_check 0
#   REGRESS=1  같은 임시 인스턴스에서 회귀(주소·DB·저장 경로만 바꾼 사본): stage26 · help_button · f68 · totp_time_sync · api_whoami
#              (REGRESS_LIST 로 바꿀 수 있다). S4 절보다 **먼저** 돈다 — S4-39 가 만드는 사람 계정의 감사 행이 회귀 시험의
#              R-73 사전 검사(최근 10분 비-검토 행)를 막지 않게. R-31(nginx)은 tests/sec4_r31_nginx.sh, AL-9 본 시험은 tests/f69.sh 가 따로 본다.
#   REGRESS_ONLY=1  S4 절을 건너뛰고 회귀만.
#
# ★ 운영 DB·운영 포트를 건드리지 않는다 — 임시 DB `tbox_sec4_<포트>_test`, 임시 인스턴스 `php -S 127.0.0.1:<포트>`(기본 8192).
#   운영 DB 는 pg_dump 로 **읽어** 임시 DB 에 붓기만 한다(파이프). 검토 계정은 claude_ 다섯(F-71), UA tbox-review/<날짜>.
#   비밀(검토 계정 비밀번호·TOTP·임시 비밀번호·세션·CSRF)은 표준입력으로만(tests/lib/secrets.sh — R-62). 화면에 찍지 않는다.
#   임시 .env 원본 — 복제본에서는 mktemp 폴더(0700)의 사본을 QF2_ENV_SRC 로 넘기고 끝에 지운다.
#   sudo 는 postgres(psql·pg_dump·createdb·dropdb)와 비밀 awk 뿐이다.
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
. "$ROOT/tests/lib/review_accounts.sh" || { echo "tests/lib/review_accounts.sh 를 읽지 못했다 — 시험을 시작하지 않는다"; exit 3; }
. "$ROOT/tests/lib/secrets.sh" || { echo "tests/lib/secrets.sh 를 읽지 못했다 — 시험을 시작하지 않는다 (R-62)"; exit 3; }
PORT="${SEC4_PORT:-8192}"
case "$PORT" in 80|8082|8081) echo "운영 포트($PORT)는 쓰지 않는다"; exit 3 ;; esac
SITE="http://127.0.0.1:$PORT"
BASE="$SITE/tbox"
UA="tbox-review/$(date +%F)"
SRC_DB="tbox_re"                  # 운영 — **읽기만** 한다(pg_dump)
DB="tbox_sec4_${PORT}_test"       # 임시 — 여기에만 쓴다
MIG="2026-10-04_users_temp_password_issued_at.sql"
OLD_REV="${SEC4_OLD_REV:-335791d}"   # 대조용 옛 생성기 판(보안 넷 앞 운영 HEAD)
TAG="$(date +%H%M%S)"
U="s4t${TAG}"                     # 이 시험이 임시 DB 에 만드는 사람 계정 이름(검토 접두 아님 — 2b reserved_prefix)
MSG_EXP='임시 비밀번호가 만료되었습니다(발급 뒤 72시간). 관리자에게 다시 발급을 요청하세요'
MSG_FAIL='아이디 또는 비밀번호가 올바르지 않습니다'
JAR_DIR="$(mktemp -d)"
WORK="$(mktemp -d)"
chmod 700 "$JAR_DIR" "$WORK"
SHOTS="${SEC4_SHOTS:-$WORK/shots}"
mkdir -p "$SHOTS"
INST=""
SERVER_PID=""
PASS=0
FAIL=0
export PATH="$HOME/.local/bin:$PATH"

hr()  { printf '\n════════════════════════════════════════════════════════════\n%s\n════════════════════════════════════════════════════════════\n' "$1"; }
sub() { printf '\n── %s\n' "$1"; }
expect() {
  if [ "$2" = "$3" ]; then printf '  ✔ %s  (기대=%s 실제=%s)\n' "$1" "$2" "$3"; PASS=$((PASS+1))
  else printf '  ✖ %s  (기대=%s 실제=%s)\n' "$1" "$2" "$3"; FAIL=$((FAIL+1)); fi
}
note() { printf '  · %s\n' "$1"; }

cleanup() {
  if [ -n "$SERVER_PID" ]; then kill "$SERVER_PID" 2>/dev/null; wait "$SERVER_PID" 2>/dev/null; fi
  sudo -n -u postgres dropdb --if-exists "$DB" >/dev/null 2>&1
  [ -n "$INST" ] && rm -rf "$INST"
  rm -rf "$JAR_DIR" "$WORK"
}
trap cleanup EXIT

psqlq() { sudo -n -u postgres psql -X -d "$DB" -t -A -c "$1" 2>/dev/null; }
review_password() { sudo -n awk -v u="$1" '$1==u{print $3}' /etc/tbox-v2/seed-accounts.txt 2>/dev/null; }
review_secret()   { sudo -n awk -v u="$1" '$1==u{print $2}' /etc/tbox-v2/qa-totp.txt 2>/dev/null; }
jget() { python3 -c '
import json,sys
try:
    d = json.loads(sys.stdin.read() or "null")
except Exception:
    print(""); sys.exit(0)
try:
    v = eval(sys.argv[1])
except Exception:
    v = ""
print("" if v is None else v)' "$1" 2>/dev/null; }
body_of() { printf '%s' "$1" | sed '$d'; }
code_of() { printf '%s' "$1" | tail -n1; }

login() {   # 검토 계정 — $1 계정 → 성공하면 0
  local who="$1" jar="$JAR_DIR/$1.jar" pw sec raw code left out try body
  pw=$(review_password "$who")
  sec=$(review_secret "$who")
  [ -n "$pw" ] || return 2
  for try in 1 2 3; do
    raw=$(totp_now sec 2>/dev/null)
    code=${raw%%$'\n'*}
    printf -v body '{"username":"%s","password":"%s","otp":"%s"}' "$who" "$pw" "$code"
    out=$(curl_json_secret body -s -c "$jar" -A "$UA" -H 'Content-Type: application/json' "$BASE/api/login")
    case "$out" in *'"ok":true'*) return 0 ;; esac
    left=$(printf '%s' "$raw" | sed -n 's/^남은 시간: \([0-9]*\)초.*/\1/p')
    [ -n "$left" ] || left=31
    printf '  · %s 로그인 재시도 — 다음 코드 창까지 %s초\n' "$who" "$left" >&2
    sleep $((left + 1))
  done
  return 1
}
csrf_of() { curl -s -b "$JAR_DIR/$1.jar" -A "$UA" "$BASE/api/me" | sed -n 's/.*"csrf_token":"\([0-9a-f]*\)".*/\1/p'; }
reauth() {   # $1 계정 $2 csrf → HTTP 코드
  local pw body
  pw=$(review_password "$1")
  printf -v body '{"password":"%s"}' "$pw"
  curl_json_secret body -s -o /dev/null -w '%{http_code}' -b "$JAR_DIR/$1.jar" -A "$UA" \
    -H 'Content-Type: application/json' -H "X-CSRF-Token: $2" "$BASE/api/me/reauth"
}
# 시험 계정 로그인 — $1 아이디  $2 비밀번호가 든 **변수 이름**  $3 jar 파일 → 「본문\n상태」(비밀번호는 표준입력으로만)
try_login() {
  local body _tl_pw
  _tl_pw="${!2}"
  printf -v body '{"username":"%s","password":"%s"}' "$1" "$_tl_pw"
  curl_json_secret body -s -w '\n%{http_code}' -c "$3" -A "$UA" -H 'Content-Type: application/json' "$BASE/api/login"
}
session_in() { awk '$6=="tbox_session"{n++} END{print n+0}' "$1" 2>/dev/null; }
set_issued() { psqlq "UPDATE users SET temp_password_issued_at = $2 WHERE username = '$1'" >/dev/null; }
mark() { psqlq "SELECT coalesce(max(id),0) FROM audit_logs"; }

# ════════════════════════════════════════════════════════════════
hr "보안 넷 — 임시 인스턴스 준비 (운영 DB 에는 쓰지 않는다)"
avail_kb=$(df -Pk / | awk 'NR==2{print $4}')
echo "루트 파티션 여유: $(df -Ph / | awk 'NR==2{print $4}')"
[ "$avail_kb" -ge 1048576 ] || { echo "루트 디스크 여유 1GB 미만 — 시험을 시작하지 않는다 (SECURITY R-80)"; exit 3; }
sudo -n -u postgres psql -X -d "$SRC_DB" -tAc "SELECT 1" >/dev/null 2>&1 \
  || { echo "psql(postgres) 을 쓸 수 없다 — 이 시험은 임시 DB 가 없으면 성립하지 않는다"; exit 3; }
if ss -ltn 2>/dev/null | grep -q "127.0.0.1:$PORT "; then echo "포트 $PORT 이 이미 쓰이고 있다 — 중단"; exit 3; fi
review_account claude_admin01 || { echo "claude_admin01 이 검토 계정 판정에 맞지 않는다 — 중단"; exit 3; }
review_account "$U" && { echo "시험 계정 이름 $U 가 검토 접두다 — 중단"; exit 3; }

sub "① 운영 DB 를 **읽어** 임시 DB 를 만든다(파이프)"
sudo -n -u postgres dropdb --if-exists "$DB" >/dev/null 2>&1
sudo -n -u postgres createdb -O tbox_owner "$DB" >/dev/null 2>&1 || { echo "임시 DB 를 만들 수 없다"; exit 3; }
sudo -n -u postgres pg_dump -d "$SRC_DB" 2>/dev/null \
  | sudo -n -u postgres psql -X -q -d "$DB" -v ON_ERROR_STOP=0 >/dev/null 2>"$WORK/restore.err"
DUMP_PS=("${PIPESTATUS[@]}")
[ "${DUMP_PS[0]}" -eq 0 ] || { echo "운영 DB 를 읽지 못했다 (pg_dump)"; exit 3; }
[ -n "$(psqlq "SELECT count(*) FROM users")" ] || { echo "임시 DB 로 옮기지 못했다 — $(head -2 "$WORK/restore.err")"; exit 3; }
NUSERS=$(psqlq "SELECT count(*) FROM users")
note "임시 DB $DB — 계정 $NUSERS · 파일 $(psqlq "SELECT count(*) FROM files")"

hr "S4-M — 마이그레이션 $MIG (임시 DB 에만)"
expect "마이그레이션 전 칸 없음" "0" "$(psqlq "SELECT count(*) FROM information_schema.columns WHERE table_schema='public' AND table_name='users' AND column_name='temp_password_issued_at'")"
for m in 2026-10-01_qf2_search.sql 2026-10-02_qf2b_files_search.sql 2026-10-03_qf3a_favorites_recent.sql; do
  sudo -n -u postgres psql -X -q -d "$DB" -v ON_ERROR_STOP=1 -f - < "$ROOT/db/migrations/$m" 2>&1 | grep -v 'could not change directory\|already exists, skipping' | sed 's/^/    /'
done
sudo -n -u postgres psql -X -q -d "$DB" -v ON_ERROR_STOP=1 -f - < "$ROOT/db/migrations/$MIG" > "$WORK/mig.out" 2>&1
expect "마이그레이션 종료 코드" "0" "$?"
grep -v 'could not change directory' "$WORK/mig.out" | sed 's/^/    /'
expect "users.temp_password_issued_at 칸 · 형(timestamptz) · NULL 허용 · 기본값 없음" "timestamp with time zone|YES|" \
  "$(psqlq "SELECT data_type || '|' || is_nullable || '|' || coalesce(column_default,'') FROM information_schema.columns WHERE table_schema='public' AND table_name='users' AND column_name='temp_password_issued_at'")"
expect "기존 행 전부 NULL(= 만료 없음 · 데이터 바꾸는 문장 0)" "$NUSERS|0" \
  "$(psqlq "SELECT count(*) || '|' || count(temp_password_issued_at) FROM users")"
expect "앱 계정이 새 칸을 읽고 넣고 고칠 수 있다(표 단위 권한)" "t|t|t" \
  "$(psqlq "SELECT has_column_privilege('tbox_app','public.users','temp_password_issued_at','SELECT')::text || '|' || has_column_privilege('tbox_app','public.users','temp_password_issued_at','INSERT')::text || '|' || has_column_privilege('tbox_app','public.users','temp_password_issued_at','UPDATE')::text" | sed 's/true/t/g')"
expect "다시 돌려도 오류 없음(IF NOT EXISTS)" "0" \
  "$(sudo -n -u postgres psql -X -q -d "$DB" -v ON_ERROR_STOP=1 -f - < "$ROOT/db/migrations/$MIG" >/dev/null 2>&1; echo $?)"
# 새 설치 모양(db/schema.sql)과 같은가 — users 표 정의 안에 같은 칸이 한 줄
expect "db/schema.sql 의 users 에 같은 칸" "1" "$(awk '/^CREATE TABLE users/{f=1} f&&/^\);/{f=0} f' "$ROOT/db/schema.sql" | grep -c '^    temp_password_issued_at TIMESTAMPTZ,')"

sub "② 저장소를 임시 폴더로 복사하고 인스턴스를 띄운다(자산도 내준다 — 화면 시험)"
INST="$(mktemp -d)"
mkdir -p "$INST/app" "$INST/storage" "$INST/log"
tar -C "$ROOT" -cf - --exclude=.git --exclude=jar --exclude=api-test.txt --exclude=./.env . | tar -C "$INST/app" -xf -
# qcheck-allow: R20 — 임시 인스턴스용 사본(0700·0600 · trap 정리) · kill -9 뒤 남는 창은 받아들인 위험(HO-B1-004)
sed -e "s#^DB_NAME=.*#DB_NAME=$DB#" \
    -e "s#^STORAGE_DIR=.*#STORAGE_DIR=$INST/storage#" \
    -e "s#^AUDIT_LOG_FILE=.*#AUDIT_LOG_FILE=$INST/log/audit.log#" \
    "${QF2_ENV_SRC:-$ROOT/.env}" > "$INST/app/.env"
chmod 600 "$INST/app/.env"
grep -q "^DB_NAME=$DB\$" "$INST/app/.env" || { echo "임시 .env 에 임시 DB 이름이 들어가지 않았다 — 중단"; exit 4; }
grep -q "^AUDIT_LOG_FILE=$INST/log/audit.log\$" "$INST/app/.env" || { echo "임시 .env 의 감사 로그 경로가 임시가 아니다 — 중단"; exit 4; }
touch "$INST/log/audit.log" "$INST/log/alerts.log"
PLAN_DIR="${PLAN_OVERRIDE:-$(cd "$ROOT/.." && pwd)/community}"
[ -f "$PLAN_DIR/doc-schema.json" ] || PLAN_DIR=/var/www/qaredi/community
ln -s "$PLAN_DIR" "$INST/community" 2>/dev/null
# 대조용 옛 생성기(보안 넷 앞) — 같은 자산 폴더에 다른 이름으로 둔다(../api/time 이 같은 서버를 가리키게)
git -C "$ROOT" show "$OLD_REV:public/assets/totp-generator.html" > "$INST/app/public/assets/totp-generator-old.html" 2>/dev/null \
  || rm -f "$INST/app/public/assets/totp-generator-old.html"
cat > "$INST/app/router.php" <<'PHP'
<?php
$root = __DIR__ . '/public';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (is_string($path) && strpos($path, '/tbox/assets/') === 0) {
    $rel = substr($path, strlen('/tbox/assets/'));
    if (strpos($rel, '..') === false) {
        $file = $root . '/assets/' . $rel;
        if (is_file($file)) {
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            $types = ['js' => 'text/javascript', 'mjs' => 'text/javascript', 'css' => 'text/css',
                      'json' => 'application/json', 'html' => 'text/html', 'svg' => 'image/svg+xml',
                      'png' => 'image/png', 'webmanifest' => 'application/manifest+json',
                      'map' => 'application/json', 'txt' => 'text/plain'];
            header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
            readfile($file);
            return true;
        }
    }
    http_response_code(404);
    return true;
}
require $root . '/index.php';
PHP
( cd "$INST/app" && exec php -S "127.0.0.1:$PORT" -t public router.php > "$INST/server.log" 2>&1 ) &
SERVER_PID=$!
READY=""
for _ in $(seq 1 20); do
  sleep 0.5
  READY=$(curl -s -o /dev/null -w '%{http_code}' -A "$UA" "$BASE/api/me")
  [ "$READY" = "401" ] && break
done
expect "임시 인스턴스가 떴다(미로그인 /api/me 401)" "401" "$READY"
[ "$READY" = "401" ] || { echo "인스턴스를 띄우지 못했다 — 중단"; tail -5 "$INST/server.log"; exit 4; }
errs_before=$(grep -ciE 'PHP (Fatal|Warning|Notice|Deprecated)|SQLSTATE|Uncaught' "$INST/server.log")

# ════════════════════════════════════════════════════════════════
if [ "${REGRESS:-0}" = "1" ] || [ "${REGRESS_ONLY:-0}" = "1" ]; then
  hr "회귀 — 같은 임시 인스턴스에서(qf3a.sh · stage7d1.sh 의 회귀 틀) · S4 절보다 먼저(S4 가 만드는 사람 계정 행이 R-73 사전 검사를 막지 않게)"
  note "각 스크립트를 임시 인스턴스 폴더로 복사해 SITE·DB·저장 경로만 바꿔 돈다(운영 8082·tbox_re·/var/lib/tbox-v2 에 닿지 않는다)."
  sed -i "s#http://127.0.0.1:8082#$SITE#g" "$INST/app/scripts/ui_snapshot.sh"
  if grep -v '^\s*#' "$INST/app/scripts/ui_snapshot.sh" | grep -q '8082'; then
    echo "  ✖ ui_snapshot.sh 에 운영 주소가 남았다 — 회귀를 돌리지 않는다"; FAIL=$((FAIL+1)); REGRESS_LIST=" "
  fi
  for s in ${REGRESS_LIST:-stage26 help_button f68 totp_time_sync api_whoami}; do
    src="$INST/app/tests/$s.sh"
    dst="$INST/app/tests/$s.temp.sh"
    [ -f "$src" ] || { echo "  ✖ $s — 스크립트가 없다"; FAIL=$((FAIL+1)); continue; }
    sed -e "s#http://127.0.0.1:8082#$SITE#g" -e "s#^DB=\"tbox_re\"#DB=\"$DB\"#" -e "s#-d tbox_re #-d $DB #g" \
        -e "s#sudo -n rm -f \"/var/lib/tbox-v2/storage/#rm -f \"$INST/storage/#g" "$src" > "$dst"
    if grep -v '^\s*#' "$dst" | grep -q '8082\|DB="tbox_re"\|-d tbox_re\|/var/lib/tbox-v2/storage'; then
      echo "  ✖ $s — 운영 주소·DB·저장 경로가 남아 있어 돌리지 않는다"; FAIL=$((FAIL+1)); continue
    fi
    echo "  ▸ $s (시작 $(date +%T))"
    bash "$dst" "$SHOTS/regress-$s" > "$WORK/regress_$s.txt" 2>&1
    rc=$?
    line=$(grep -E '통과 [0-9]+|PASS|통과:|결과' "$WORK/regress_$s.txt" | tail -1)
    echo "    종료 코드 $rc · ${line:-결과 줄 없음}"
    grep -E '^\s*(✖|FAIL)' "$WORK/regress_$s.txt" | head -12 | sed 's/^/      /'
    [ -n "${REGRESS_KEEP:-}" ] && cp "$WORK/regress_$s.txt" "$REGRESS_KEEP/"
    [ "$rc" = "0" ] && PASS=$((PASS+1)) || FAIL=$((FAIL+1))
  done
fi


if [ "${REGRESS_ONLY:-0}" != "1" ]; then
# ════════════════════════════════════════════════════════════════
hr "S4-39 — 임시 비밀번호 유효기간 72시간 (R-39)"
login claude_admin01 || { echo "  ✖ claude_admin01 로그인 실패 — 중단"; exit 4; }
ADM_CSRF=$(csrf_of claude_admin01)
[ -n "$ADM_CSRF" ] || { echo "  ✖ CSRF 를 받지 못했다 — 중단"; exit 4; }
expect "claude_admin01 재인증" "200" "$(reauth claude_admin01 "$ADM_CSRF")"

sub "1. 계정 만들기 — 발급 시각이 같은 문장에서 채워진다"
M0=$(mark)
OUT=$(curl -s -w '\n%{http_code}' -X POST -b "$JAR_DIR/claude_admin01.jar" -A "$UA" \
      -H 'Content-Type: application/json' -H "X-CSRF-Token: $ADM_CSRF" \
      -d "{\"username\":\"$U\",\"role\":\"Employee\",\"reason_text\":\"보안 넷 R-39 시험 — 임시 DB 에만 만든다\"}" \
      "$BASE/api/users")
expect "POST /api/users 201" "201" "$(code_of "$OUT")"
TPW=$(body_of "$OUT" | jget "d.get('temp_password','')")
OUT=""   # 임시 비밀번호가 든 응답을 남기지 않는다
expect "임시 비밀번호를 받았다(16자 — 값은 찍지 않는다)" "16" "${#TPW}"
expect "발급 시각 채움 · must_change_password · 1분 안" "t|t" \
  "$(psqlq "SELECT (temp_password_issued_at IS NOT NULL AND temp_password_issued_at > now() - interval '1 minute')::text || '|' || must_change_password::text FROM users WHERE username = '$U'" | sed 's/true/t/g')"
expect "감사 user.create allow 한 행(값 없이 사실만)" "1" \
  "$(psqlq "SELECT count(*) FROM audit_logs WHERE id > $M0 AND action='user.create' AND result='allow' AND target_name='$U' AND (detail->>'temp_password_issued')::boolean")"

sub "2. 72시간 안 — 정상 흐름(첫 로그인 → 비밀번호 변경 단계)"
for h in "71 hours" "71 hours 59 minutes"; do
  set_issued "$U" "now() - interval '$h'"
  J="$JAR_DIR/u-in.jar"; rm -f "$J"
  R=$(try_login "$U" TPW "$J")
  expect "발급 $h 전 → 200 · next=password_change" "200|password_change" "$(code_of "$R")|$(body_of "$R" | jget "d.get('next')")"
  expect "발급 $h 전 → 세션 쿠키 받음" "1" "$(session_in "$J")"
done

sub "3. 72시간 지남 — 비밀번호가 맞아도 401 · 사람 문장 · 감사 temp_expired · 세션 없음 · 실패 횟수 그대로"
psqlq "UPDATE users SET failed_count = 0, locked_until = NULL WHERE username = '$U'" >/dev/null
for h in "72 hours 1 minute" "73 hours"; do
  set_issued "$U" "now() - interval '$h'"
  M=$(mark)
  J="$JAR_DIR/u-exp.jar"; rm -f "$J"
  R=$(try_login "$U" TPW "$J")
  expect "발급 $h 전 → 401" "401" "$(code_of "$R")"
  expect "발급 $h 전 → 문장" "$MSG_EXP" "$(body_of "$R" | jget "d.get('error','')")"
  expect "발급 $h 전 → 본문 키는 error 하나(mfa_required·next 없음)" "error" "$(body_of "$R" | jget "','.join(sorted(d))")"
  expect "발급 $h 전 → 세션 쿠키 0" "0" "$(session_in "$J")"
  expect "발급 $h 전 → 감사 auth.login deny temp_expired 한 행(대상 = 이 계정)" "1" \
    "$(psqlq "SELECT count(*) FROM audit_logs WHERE id > $M AND action='auth.login' AND result='deny' AND reason='temp_expired' AND target_name='$U' AND target_id = (SELECT id FROM users WHERE username='$U')")"
  expect "발급 $h 전 → 이번 요청의 감사 행은 그 한 줄뿐(allow·세션 0)" "1" "$(psqlq "SELECT count(*) FROM audit_logs WHERE id > $M")"
  expect "발급 $h 전 → 실패 횟수 그대로(0)" "0" "$(psqlq "SELECT failed_count FROM users WHERE username='$U'")"
done
expect "감사 요약 문장(ARCH 7.4 = Audit.php)" "$U 계정으로 로그인을 시도했지만 임시 비밀번호의 유효기간(발급 뒤 72시간)이 지났습니다" \
  "$(psqlq "SELECT summary FROM audit_logs WHERE action='auth.login' AND reason='temp_expired' AND target_name='$U' ORDER BY id DESC LIMIT 1")"
expect "감사 로그 파일에도 같은 행(임시 인스턴스 audit.log)" "yes" \
  "$(grep -q "\"reason\":\"temp_expired\"" "$INST/log/audit.log" && echo yes || echo no)"
expect "감사 로그 어디에도 임시 비밀번호 값 0" "0" "$(py_secret TPW -- python3 -c '
import sys
s = sys.stdin.readline().rstrip("\n")
print(open(sys.argv[1], encoding="utf-8", errors="replace").read().count(s) if s else -1)' "$INST/log/audit.log")"
M=$(mark)
WRONG="wrong-${TAG}-password"
R=$(try_login "$U" WRONG "$JAR_DIR/u-wrong.jar")
expect "만료 계정 + 틀린 비밀번호 → 401 옛 동일 문구(만료 여부를 알려 주지 않는다)" "401|$MSG_FAIL" \
  "$(code_of "$R")|$(body_of "$R" | jget "d.get('error','')")"
expect "틀린 비밀번호는 bad_credentials · 실패 횟수 +1" "1|1" \
  "$(psqlq "SELECT count(*) FROM audit_logs WHERE id > $M AND action='auth.login' AND reason='bad_credentials' AND target_name='$U'")|$(psqlq "SELECT failed_count FROM users WHERE username='$U'")"
psqlq "UPDATE users SET failed_count = 0, locked_until = NULL WHERE username = '$U'" >/dev/null

sub "4. 2단계 인증이 켜진 계정도 만료가 먼저(코드를 묻지 않는다) · 72시간 안이면 옛 흐름(mfa_required)"
# 시험용 무작위 비밀 키(임시 DB 에만 — 실계정 비밀은 읽지 않는다)
FAKE_SECRET=$(php -r 'require "'"$ROOT"'/src/Totp.php"; echo Totp::generateSecret();')
# 값은 base32 글자뿐이라 SQL 문자열에 그대로 넣을 수 있다. printf 는 bash 내장 — 인자 목록에 비밀이 없고 psql 은 표준입력으로 받는다
printf "UPDATE users SET mfa_enabled = true, mfa_secret = '%s' WHERE username = '%s';\n" "$FAKE_SECRET" "$U" \
  | sudo -n -u postgres psql -X -q -d "$DB" -v ON_ERROR_STOP=1 -f - >/dev/null 2>&1
expect "시험 계정에 2단계 인증을 켰다(임시 DB)" "t" "$(psqlq "SELECT mfa_enabled FROM users WHERE username='$U'" | sed 's/true/t/')"
set_issued "$U" "now() - interval '73 hours'"
R=$(try_login "$U" TPW "$JAR_DIR/u-mfa.jar")
expect "2단계 켜짐 + 73시간 → 401 만료 문장(mfa_required 없음)" "401|$MSG_EXP|" \
  "$(code_of "$R")|$(body_of "$R" | jget "d.get('error','')")|$(body_of "$R" | jget "d.get('mfa_required')")"
set_issued "$U" "now() - interval '71 hours'"
R=$(try_login "$U" TPW "$JAR_DIR/u-mfa.jar")
expect "2단계 켜짐 + 71시간 → 401 mfa_required(옛 흐름 그대로)" "401|True" \
  "$(code_of "$R")|$(body_of "$R" | jget "d.get('mfa_required')")"
psqlq "UPDATE users SET mfa_enabled = false, mfa_secret = NULL, mfa_last_step = NULL, failed_count = 0 WHERE username = '$U'" >/dev/null
FAKE_SECRET=""

sub "5. 발급 시각 NULL(이 기능 전 발급·CLI 시드) — 만료 없음"
set_issued "$U" "NULL"
R=$(try_login "$U" TPW "$JAR_DIR/u-null.jar")
expect "NULL + must_change_password → 200 · next=password_change" "200|password_change" "$(code_of "$R")|$(body_of "$R" | jget "d.get('next')")"

sub "6. 비밀번호를 바꾸면 발급 시각이 NULL · 새 비밀번호로는 시각과 무관"
set_issued "$U" "now() - interval '70 hours'"
J="$JAR_DIR/u-chg.jar"; rm -f "$J"
R=$(try_login "$U" TPW "$J")
expect "70시간 → 200" "200" "$(code_of "$R")"
UCSRF=$(curl -s -b "$J" -A "$UA" "$BASE/api/me" | sed -n 's/.*"csrf_token":"\([0-9a-f]*\)".*/\1/p')
NPW="S4new-$(head -c 12 /dev/urandom | od -An -tx1 | tr -d ' \n')"
printf -v CHG '{"current":"%s","new":"%s"}' "$TPW" "$NPW"
expect "POST /api/me/password 200" "200" \
  "$(curl_json_secret CHG -s -o /dev/null -w '%{http_code}' -b "$J" -A "$UA" -H 'Content-Type: application/json' -H "X-CSRF-Token: $UCSRF" "$BASE/api/me/password")"
CHG=""
expect "바꾼 뒤 발급 시각 NULL · must_change_password false" "|f" \
  "$(psqlq "SELECT coalesce(temp_password_issued_at::text,'') || '|' || must_change_password::text FROM users WHERE username='$U'" | sed 's/false/f/')"
R=$(try_login "$U" NPW "$JAR_DIR/u-new.jar")
expect "새 비밀번호로 로그인 200(next 는 비밀번호 단계가 아님)" "200|no" \
  "$(code_of "$R")|$(body_of "$R" | jget "'yes' if d.get('next') == 'password_change' else 'no'")"

sub "7. 재발급 — 옛 시각을 새로(곧바로 만료되지 않는다) · 72시간 지나면 다시 401"
set_issued "$U" "now() - interval '100 hours'"
expect "claude_admin01 재인증" "200" "$(reauth claude_admin01 "$ADM_CSRF")"
UID_T=$(psqlq "SELECT id FROM users WHERE username='$U'")
OUT=$(curl -s -w '\n%{http_code}' -X POST -b "$JAR_DIR/claude_admin01.jar" -A "$UA" \
      -H 'Content-Type: application/json' -H "X-CSRF-Token: $ADM_CSRF" \
      -d '{"reason_text":"보안 넷 R-39 시험 — 재발급"}' "$BASE/api/users/$UID_T/password/reset")
expect "재발급 200" "200" "$(code_of "$OUT")"
TPW2=$(body_of "$OUT" | jget "d.get('temp_password','')")
OUT=""
expect "재발급 뒤 발급 시각이 1분 안 · must_change_password" "t|t" \
  "$(psqlq "SELECT (temp_password_issued_at > now() - interval '1 minute')::text || '|' || must_change_password::text FROM users WHERE username = '$U'" | sed 's/true/t/g')"
R=$(try_login "$U" TPW2 "$JAR_DIR/u-r1.jar")
expect "재발급 직후 로그인 200 · next=password_change" "200|password_change" "$(code_of "$R")|$(body_of "$R" | jget "d.get('next')")"
set_issued "$U" "now() - interval '73 hours'"
R=$(try_login "$U" TPW2 "$JAR_DIR/u-r2.jar")
expect "재발급 73시간 뒤 → 401 만료 문장" "401|$MSG_EXP" "$(code_of "$R")|$(body_of "$R" | jget "d.get('error','')")"

sub "8. 로그인 화면(헤드리스) — 만료 문장이 그대로 보인다 · 콘솔·페이지 오류 0"
cat > "$WORK/login_ui.py" <<'PY'
import json, sys, time
from playwright.sync_api import sync_playwright
base, user, shots = sys.argv[1], sys.argv[2], sys.argv[3]
pw = sys.stdin.readline().rstrip("\n")          # 비밀번호는 표준입력(py_secret) — 인자에 싣지 않는다
out = {"console": [], "pageerror": [], "http_err": []}
with sync_playwright() as p:
    b = p.chromium.launch(channel="chromium", args=["--no-sandbox"])
    for width, scheme in ((1440, "light"), (390, "dark")):
        ctx = b.new_context(viewport={"width": width, "height": 900}, color_scheme=scheme,
                            user_agent="tbox-review/ui")
        page = ctx.new_page()
        # 「Failed to load resource」 줄은 아래 응답 목록으로 따로 본다(로그인 401 은 기대한 것)
        page.on("console", lambda m: out["console"].append(m.text) if m.type == "error" and "Failed to load resource" not in m.text else None)
        page.on("pageerror", lambda e: out["pageerror"].append(str(e)))
        page.on("response", lambda r: out["http_err"].append("%d %s" % (r.status, r.url)) if r.status >= 400 and not r.url.endswith("/api/login") else None)
        page.goto(base + "/login")
        page.fill("#username", user)
        page.fill("#password", pw)
        page.click("#login-submit")
        # 화면 CSP(script-src 'self')가 문자열 평가를 막으므로 wait_for_function 대신 파이썬에서 기다린다
        text, end = "", time.time() + 10
        while time.time() < end:
            text = page.locator("#msg").inner_text()
            if text:
                break
            time.sleep(0.1)
        out["msg_%d" % width] = text
        out["url_%d" % width] = page.url
        page.screenshot(path="%s/r39-login-%d-%s.png" % (shots, width, scheme), full_page=True)
        ctx.close()
    b.close()
print(json.dumps(out, ensure_ascii=False))
PY
if py_secret TPW2 -- python3 "$WORK/login_ui.py" "$BASE" "$U" "$SHOTS" > "$WORK/login_ui.json" 2>"$WORK/login_ui.err"; then
  expect "화면 1440 라이트 — #msg 가 만료 문장" "$MSG_EXP" "$(jget "d.get('msg_1440','')" < "$WORK/login_ui.json")"
  expect "화면 390 다크 — #msg 가 만료 문장" "$MSG_EXP" "$(jget "d.get('msg_390','')" < "$WORK/login_ui.json")"
  expect "화면 — 로그인 화면에 머문다(이동 없음)" "yes" "$(jget "'yes' if d.get('url_1440','').endswith('/login') else d.get('url_1440')" < "$WORK/login_ui.json")"
  expect "화면 — 콘솔 오류·페이지 오류·HTTP≥400(로그인 401 제외) 0" "0|0|0" \
    "$(jget "str(len(d['console'])) + '|' + str(len(d['pageerror'])) + '|' + str(len(d['http_err']))" < "$WORK/login_ui.json")"
  jget "' / '.join(d['console'] + d['pageerror'] + d['http_err'])[:300]" < "$WORK/login_ui.json" | sed 's/^/    /'
  note "스크린샷(사람이 연다): $SHOTS/r39-login-1440-light.png · r39-login-390-dark.png"
else
  echo "  ✖ Playwright 실행 실패"; sed 's/^/    /' "$WORK/login_ui.err" | head -8; FAIL=$((FAIL+1))
fi
TPW=""; TPW2=""; NPW=""

# ════════════════════════════════════════════════════════════════
hr "S4-79 — GET /api/time 의 now_ms · 옛/새 생성기 경계 직후 대조 (R-79)"
T=$(curl -s -A "$UA" "$BASE/api/time")
expect "키 {now, now_ms, period}" "now,now_ms,period" "$(printf '%s' "$T" | jget "','.join(sorted(d))")"
expect "now = ⌊now_ms/1000⌋ · now_ms 정수" "True|True" \
  "$(printf '%s' "$T" | jget "str(d['now'] == d['now_ms'] // 1000) + '|' + str(isinstance(d['now_ms'], int))")"
cat > "$WORK/edge.py" <<'PY'
import json, sys, time
from playwright.sync_api import sync_playwright
base, page_path, rounds = sys.argv[1], sys.argv[2], int(sys.argv[3])
secret = sys.stdin.readline().rstrip("\n")
out = {"rows": [], "pageerror": []}
with sync_playwright() as p:
    b = p.chromium.launch(channel="chromium", args=["--no-sandbox"])
    page = b.new_page()
    page.on("pageerror", lambda e: out["pageerror"].append(str(e)))
    page.goto(base + page_path)
    page.wait_for_timeout(2500)
    out["status"] = page.locator("#utc").inner_text()
    out["offset"] = page.evaluate("localStorage.getItem('tbox-totp-offset')")   # 진단용 — 이 화면이 잰 「서버 − PC」(ms)
    page.fill("#secret", secret)
    page.click("#apply")
    page.wait_for_timeout(1200)
    nxt = (int(time.time()) // 30 + 1) * 30
    for _ in range(rounds):
        d = nxt + 0.25 - time.time()
        if d > 0:
            time.sleep(d)
        code = page.locator("#code").inner_text()
        now = time.time()
        out["rows"].append({"at": int(now), "ms": int(now * 1000) % 1000, "code": code})
        nxt += 30
    b.close()
print(json.dumps(out, ensure_ascii=False))
PY
EDGE_SECRET=$(php -r 'require "'"$ROOT"'/src/Totp.php"; echo Totp::generateSecret();')
edge_hits() {   # $1 JSON 파일 → 「맞은 수/전체」 (화면 코드 = 서버 Totp::code(at)). 줄마다 자세한 것은 $1.detail 에
  local n i at ms shown srv prev hit=0
  n=$(jget "len(d['rows'])" < "$1")
  : > "$1.detail"
  for i in $(seq 0 $((n - 1))); do
    at=$(jget "d['rows'][$i]['at']" < "$1"); ms=$(jget "d['rows'][$i].get('ms', 0)" < "$1"); shown=$(jget "d['rows'][$i]['code']" < "$1")
    srv=$(py_secret EDGE_SECRET -- php -r 'require "'"$ROOT"'/src/Totp.php"; $s = rtrim((string) fgets(STDIN), "\r\n"); echo Totp::code($s, (int) $argv[1]);' "$at")
    prev=$(py_secret EDGE_SECRET -- php -r 'require "'"$ROOT"'/src/Totp.php"; $s = rtrim((string) fgets(STDIN), "\r\n"); echo Totp::code($s, (int) $argv[1] - 30);' "$at")
    if [ "$shown" = "$srv" ]; then hit=$((hit + 1)); echo "스텝 안 $((at % 30)).$(printf '%03d' "$ms")초 — 새 스텝 코드" >> "$1.detail"
    elif [ "$shown" = "$prev" ]; then echo "스텝 안 $((at % 30)).$(printf '%03d' "$ms")초 — 이전 스텝 코드" >> "$1.detail"
    else echo "스텝 안 $((at % 30)).$(printf '%03d' "$ms")초 — 둘 다 아님" >> "$1.detail"; fi
  done
  printf '%s/%s' "$hit" "$n"
}
if py_secret EDGE_SECRET -- python3 "$WORK/edge.py" "$BASE" "/assets/totp-generator.html" 3 > "$WORK/edge-new.json" 2>"$WORK/edge-new.err"; then
  expect "새 생성기 — 경계 +0.25초에 새 스텝 코드(이어지는 스텝 3개)" "3/3" "$(edge_hits "$WORK/edge-new.json")"
  note "새 생성기가 잰 보정값: $(jget "d.get('offset')" < "$WORK/edge-new.json") · 줄마다: $(tr '\n' '/' < "$WORK/edge-new.json.detail")"
  expect "새 생성기 — 상태 줄 「서버와 동기화됨」 · 페이지 오류 0" "yes|0" \
    "$(jget "('yes' if d['status'].startswith('서버와 동기화됨') else d['status']) + '|' + str(len(d['pageerror']))" < "$WORK/edge-new.json")"
else
  echo "  ✖ Playwright(새 생성기) 실행 실패"; sed 's/^/    /' "$WORK/edge-new.err" | head -5; FAIL=$((FAIL+1))
fi
if [ -f "$INST/app/public/assets/totp-generator-old.html" ]; then
  if py_secret EDGE_SECRET -- python3 "$WORK/edge.py" "$BASE" "/assets/totp-generator-old.html" 3 > "$WORK/edge-old.json" 2>"$WORK/edge-old.err"; then
    note "대조(판정 아님) — 옛 생성기($OLD_REV) 경계 +0.25초 새 코드: $(edge_hits "$WORK/edge-old.json") (정수 초 보정·경계와 무관한 1초 박자) · 보정값 $(jget "d.get('offset')" < "$WORK/edge-old.json")"
  else
    note "대조 — 옛 생성기 실행 실패: $(head -1 "$WORK/edge-old.err")"
  fi
fi
EDGE_SECRET=""

# ════════════════════════════════════════════════════════════════
hr "S4-100 — AL-9 절대 경로 rm (사본 auth.log · 임시 상태 · --dry-run) (R-100)"
cat > "$WORK/auth.log.s4" <<LOG
Sep 26 01:00:01 s4host sudo:  s4a${TAG} : TTY=pts/0 ; PWD=/tmp ; USER=root ; COMMAND=/usr/bin/rm -rf /var/lib/tbox-v2/storage
Sep 26 01:00:02 s4host sudo:  s4b${TAG} : TTY=pts/0 ; PWD=/tmp ; USER=root ; COMMAND=/bin/rm -f /var/log/tbox-v2/audit.log
Sep 26 01:00:03 s4host sudo:  s4c${TAG} : TTY=pts/0 ; PWD=/tmp ; USER=root ; COMMAND=/usr/bin/rmdir /var/lib/x
Sep 26 01:00:04 s4host sudo:  s4d${TAG} : TTY=pts/0 ; PWD=/tmp ; USER=root ; COMMAND=/usr/bin/firmware --list
Sep 26 01:00:05 s4host sudo:  s4e${TAG} : TTY=pts/0 ; PWD=/tmp ; USER=root ; COMMAND=/usr/bin/systemctl status nginx
Sep 26 01:00:06 s4host sudo:  s4f${TAG} : TTY=pts/0 ; PWD=/tmp ; USER=root ; COMMAND=/usr/bin/rm
LOG
( cd "$INST/app" && php scripts/alerts.php --rule AL-9 --dry-run --auth-log "$WORK/auth.log.s4" --state "$WORK/al9-state.json" ) > "$WORK/al9.out" 2>&1
expect "alerts.php --rule AL-9 --dry-run 종료 코드" "0" "$?"
sed 's/^/    | /' "$WORK/al9.out"
GOT=$(grep -oE "\[dry-run\] AL-9 s4[a-f]${TAG} " "$WORK/al9.out" | sed -E "s/.*AL-9 s4([a-f])${TAG} /\1/" | sort | tr -d '\n')
expect "울린 줄 = /usr/bin/rm -rf(a) · /bin/rm -f(b) — rmdir(c)·firmware(d)·systemctl(e)·인자 없는 rm(f) 은 조용" "ab" "$GOT"
# 두 단계로 센다 — 이 서버의 grep(ugrep 7.8)은 -E 의 `.*` 가 한글이 섞인 줄에서 끝까지 닿지 않는 일이 있다
expect "절대 경로 rm 줄의 origin = human(DB 없는 명령)" "2" "$( (grep -E "\[dry-run\] AL-9 s4[ab]${TAG} " "$WORK/al9.out" || true) | grep -c ' origin=human$')"
expect "dry-run 은 상태 파일을 만들지 않는다" "no" "$([ -e "$WORK/al9-state.json" ] && echo yes || echo no)"
expect "dry-run 은 임시 alerts.log 에도 쓰지 않는다" "0" "$(grep -c "s4[a-f]${TAG}" "$INST/log/alerts.log")"
git -C "$ROOT" show "$OLD_REV:scripts/alerts.php" > "$INST/app/scripts/alerts-old.php" 2>/dev/null && {
  ( cd "$INST/app" && php scripts/alerts-old.php --rule AL-9 --dry-run --auth-log "$WORK/auth.log.s4" --state "$WORK/al9-old.json" ) > "$WORK/al9-old.out" 2>&1
  note "대조(판정 아님) — 옛 alerts.php($OLD_REV) 가 울린 줄: [$(grep -oE "\[dry-run\] AL-9 s4[a-f]${TAG} " "$WORK/al9-old.out" | sed -E "s/.*AL-9 s4([a-f])${TAG} /\1/" | sort | tr -d '\n')] (비어 있으면 절대 경로 rm 을 못 잡던 옛 틈)"
  rm -f "$INST/app/scripts/alerts-old.php"
}

# ════════════════════════════════════════════════════════════════
hr "S4-S — 정적"
bash "$ROOT/scripts/security_lint.sh" > "$WORK/lint.out" 2>&1
expect "security_lint.sh 종료 코드" "0" "$?"
tail -1 "$WORK/lint.out" | sed 's/^/    /'
PHPBAD=0
for f in src/Auth.php src/Audit.php src/handlers/login.php src/handlers/users_create.php src/handlers/users_password_reset.php \
         src/handlers/time.php scripts/alerts.php; do
  php -l "$ROOT/$f" >/dev/null 2>&1 || { echo "  ✖ php -l 실패: $f"; PHPBAD=1; }
done
expect "php -l (바뀐 PHP 7개)" "0" "$PHPBAD"
BASHBAD=0
for f in tests/sec4.sh tests/sec4_r31_nginx.sh tests/f69.sh tests/totp_time_sync.sh; do
  bash -n "$ROOT/$f" 2>/dev/null || { echo "  ✖ bash -n 실패: $f"; BASHBAD=1; }
done
expect "bash -n (시험 4개)" "0" "$BASHBAD"
# 위키 검사 ⑧ 은 저장소 바깥(부모 폴더)의 사이트·커뮤니티 STAGES 를 읽는다 — 복제본에는 부모 폴더가 없으므로
# 임시 인스턴스 사본(형제 community 링크 있음)에 사이트 STAGES 읽기 링크 하나를 더해 그 사본에서 돈다(읽기만)
[ -e "$INST/STAGES.md" ] || ln -s "$(dirname "$PLAN_DIR")/STAGES.md" "$INST/STAGES.md" 2>/dev/null
php "$INST/app/scripts/wiki_check.php" > "$WORK/wiki.out" 2>&1
expect "wiki_check.php 종료 코드(임시 사본 — 형제 STAGES 는 $(dirname "$PLAN_DIR") 것을 읽기만)" "0" "$?"
tail -1 "$WORK/wiki.out" | sed 's/^/    /'
errs_after=$(grep -ciE 'PHP (Fatal|Warning|Notice|Deprecated)|SQLSTATE|Uncaught' "$INST/server.log")
expect "임시 인스턴스 서버 로그 새 PHP 오류·SQLSTATE 0" "0" "$((errs_after - errs_before))"
fi   # REGRESS_ONLY

hr "결과"
printf '통과 %d · 실패 %d\n' "$PASS" "$FAIL"
note "임시 DB($DB)·임시 폴더·임시 서버는 이 스크립트가 끝나며 지워진다. 운영에는 아무것도 남지 않는다."
[ "$FAIL" -eq 0 ] || exit 1
exit 0
