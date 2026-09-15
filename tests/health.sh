#!/usr/bin/env bash
# tests/health.sh — F-48 「상태 확인 API 의 DB 확인」 수락 기준 실행 (PRD 12.2 T-60)
#
#   (a) 정상 — 로그인 없이 GET /api/health → 200 · 본문 키가 정확히 {status, db, time} ·
#       status=ok · db=ok · time 이 ISO 8601(DATE_ATOM, 시간대 포함)이고 서버 date +%s ±5초 ·
#       Content-Type: application/json · Cache-Control: no-store · Access-Control-* 0건 ·
#       본문에 VERSION 값 0건 · 「PHP」·「PostgreSQL」 0건 · POST 는 405 · 감사 로그 0행
#   (b) 실패 — 저장소의 src/·public/ 을 **임시 폴더로 복사**하고 가짜 .env(닿지 않는 DB 포트)를
#       놓은 뒤 PHP 내장 서버로 띄워서 본다. GET /api/health → 503 ·
#       본문이 정확히 {"status":"fail","db":"connect_failed"} ·
#       본문·헤더에 가짜 .env 의 호스트·포트·계정·비밀번호·SQLSTATE·PDOException 0건 ·
#       같은 서버에서 GET /api/me 는 여전히 500 {"error":"서버 오류"}
#   (c) 회귀 — scripts/security_lint.sh 0 · 바뀐 PHP 세 파일 php -l · 이 파일 bash -n
#
# 규칙 (CLAUDE.md 3장·4장)
#   · 로그인하지 않는다. 시드 계정도 qa_ 계정도 쓰지 않는다 — 이 창구는 public 이라 필요가 없다.
#   · 모든 요청에 `-A "tbox-review/<날짜>"` 를 붙인다.
#   · **운영 DB·.env·nginx·php-fpm·PostgreSQL 에 손대지 않는다.** (b) 의 실패 상황은
#     임시 폴더 복사본 + 가짜 .env + 내장 서버 안에서만 만든다. 실제 .env 는 읽지도 복사하지도 않는다.
#
# 준비물: curl · php(pdo_pgsql) · sudo -n -u postgres psql(감사 로그 확인, 없으면 SKIP)
# 사용: bash tests/health.sh
set -u

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BASE="http://127.0.0.1:8082"
UA="tbox-review/$(date +%F)"
DB="tbox_re"
WORK="$(mktemp -d)"
TMP="$(mktemp -d)"
PORT=18082
SRV_PID=""
PASS=0
FAIL=0
SKIP=0

cleanup() {
  [ -n "$SRV_PID" ] && kill "$SRV_PID" 2>/dev/null
  rm -rf "$WORK" "$TMP"
}
trap cleanup EXIT

# ─── 시작 전 관문: 루트 디스크 여유 (SECURITY R-80) ─────────────────────────
# 2026-09-15 사고 — 루트 파티션이 0 이 되어 PostgreSQL 이 PANIC 으로 멎고 사이트가 503 이 됐다.
# 이 시험은 임시 폴더에 src/·public/ 을 복사하고 내장 서버 로그를 쓴다. 여유가 없는 상태에서
# 시작하면 시험이 사고를 재현하는 쪽이 된다 — 그래서 1GB 미만이면 아무것도 하지 않고 멈춘다.
avail_kb=$(df -Pk / | awk 'NR==2{print $4}')
echo "루트 파티션 여유: $(df -Ph / | awk 'NR==2{print $4}') (${avail_kb}KB)"
[ "$avail_kb" -ge 1048576 ] || { echo "루트 디스크 여유 1GB 미만 — 시험을 시작하지 않는다 (SECURITY R-80)"; exit 3; }

psqlq() { sudo -n -u postgres psql -d "$DB" -t -A -c "$1" 2>/dev/null; }

hr()  { printf '\n════════════════════════════════════════════════════════════\n%s\n════════════════════════════════════════════════════════════\n' "$1"; }
sub() { printf '\n── %s\n' "$1"; }
expect() {
  if [ "$2" = "$3" ]; then printf '  ✔ %s  (기대=%s 실제=%s)\n' "$1" "$2" "$3"; PASS=$((PASS+1))
  else printf '  ✖ %s  (기대=%s 실제=%s)\n' "$1" "$2" "$3"; FAIL=$((FAIL+1)); fi
}
skip() { printf '  ○ SKIP %s  (%s)\n' "$1" "$2"; SKIP=$((SKIP+1)); }

# JSON 도우미 — 본문 파일을 읽어 키 목록 / 값 하나를 찍는다 (php 로 판다: 응답이 곧 사양이다)
cat > "$WORK/keys.php" <<'PHPEOF'
<?php
$d = json_decode(file_get_contents($argv[1]), true);
if (!is_array($d)) { echo "파싱실패\n"; exit; }
$k = array_keys($d);
echo implode(',', $k), "\n";
PHPEOF
cat > "$WORK/val.php" <<'PHPEOF'
<?php
$d = json_decode(file_get_contents($argv[1]), true);
echo is_array($d) && array_key_exists($argv[2], $d) ? (string) $d[$argv[2]] : '없음', "\n";
PHPEOF
# time 값이 DATE_ATOM 으로 파싱되나 + 서버 시각과의 차이(초)
cat > "$WORK/atom.php" <<'PHPEOF'
<?php
$t = $argv[1];
$dt = DateTimeImmutable::createFromFormat(DATE_ATOM, $t);
if ($dt === false) { echo "parse=no gap=-1 tz=없음\n"; exit; }
$gap = abs($dt->getTimestamp() - (int) $argv[2]);
echo 'parse=yes gap=' . $gap . ' tz=' . $dt->format('P') . "\n";
PHPEOF

# ════════════════════════════════════════════════════════════
hr "(a) 정상 — GET /api/health 가 DB 를 확인하고 200"

AUDIT_OK=yes
AUDIT_BEFORE=$(psqlq "SELECT count(*) FROM audit_logs;")
[ -z "$AUDIT_BEFORE" ] && AUDIT_OK=no

CODE=$(curl -s -o "$WORK/h.json" -D "$WORK/h.head" -w '%{http_code}' -A "$UA" "$BASE/api/health")
SRV_NOW=$(date +%s)
echo "  · 명령      : curl -s -A \"$UA\" $BASE/api/health"
echo "  · 응답 상태 : $CODE"
echo "  · 응답 본문 : $(cat "$WORK/h.json")"
echo "  · 응답 헤더 :"; sed 's/^/      /' "$WORK/h.head"

expect "HTTP 상태 200 (로그인 없이)" "200" "$CODE"
expect "본문 키가 정확히 {status, db, time}" "status,db,time" "$(php "$WORK/keys.php" "$WORK/h.json")"
expect "status = ok" "ok" "$(php "$WORK/val.php" "$WORK/h.json" status)"
expect "db = ok"     "ok" "$(php "$WORK/val.php" "$WORK/h.json" db)"

TIMEVAL=$(php "$WORK/val.php" "$WORK/h.json" time)
ATOM=$(php "$WORK/atom.php" "$TIMEVAL" "$SRV_NOW")
echo "  · time = $TIMEVAL · 서버 date +%s = $SRV_NOW · $ATOM"
expect "time 이 DATE_ATOM(ISO 8601, 시간대 포함)" "parse=yes" "$(echo "$ATOM" | sed 's/ .*//')"
GAP=$(echo "$ATOM" | sed -n 's/.*gap=\([0-9-]*\).*/\1/p')
expect "time 이 서버 시각 ±5초 (차이 ${GAP}초)" "yes" "$([ "$GAP" -ge 0 ] && [ "$GAP" -le 5 ] && echo yes || echo no)"

expect "Content-Type: application/json" "1" "$(grep -ci '^Content-Type: application/json' "$WORK/h.head")"
expect "Cache-Control: no-store"        "1" "$(grep -ci '^Cache-Control: no-store' "$WORK/h.head")"
expect "Access-Control-* 헤더 0건 (F-42)" "0" "$(grep -ci '^Access-Control-' "$WORK/h.head")"

VER=$(cat "$ROOT/VERSION")
expect "본문에 VERSION 값($VER) 0건 (F-47 ④)" "0" "$(grep -o "$VER" "$WORK/h.json" | wc -l)"
expect "본문에 「PHP」 0건"        "0" "$(grep -o 'PHP' "$WORK/h.json" | wc -l)"
expect "본문에 「PostgreSQL」 0건" "0" "$(grep -o 'PostgreSQL' "$WORK/h.json" | wc -l)"
expect "본문에 「pgsql」 0건"      "0" "$(grep -oi 'pgsql' "$WORK/h.json" | wc -l)"
expect "헤더에 Server 상세(nginx/x.y) 0건" "0" "$(grep -cE '^Server: .*[0-9]+\.[0-9]+' "$WORK/h.head")"

expect "POST /api/health → 405" "405" "$(curl -s -o /dev/null -w '%{http_code}' -X POST -A "$UA" "$BASE/api/health")"

if [ "$AUDIT_OK" = yes ]; then
  AUDIT_AFTER=$(psqlq "SELECT count(*) FROM audit_logs;")
  HEALTH_ROWS=$(psqlq "SELECT count(*) FROM audit_logs WHERE detail::text LIKE '%/api/health%';")
  echo "  · audit_logs 총 행수 $AUDIT_BEFORE → $AUDIT_AFTER (이 서버는 사용 중이라 다른 행이 늘 수 있다)"
  expect "감사 로그에 /api/health 행 0 (기록 대상 아님)" "0" "$HEALTH_ROWS"
else
  skip "감사 로그 0행 확인" "sudo -n -u postgres psql 을 쓸 수 없다"
fi

# ════════════════════════════════════════════════════════════
hr "(b) 실패 — 임시 복사본 + 가짜 .env + PHP 내장 서버 (운영은 건드리지 않는다)"

sub "임시 폴더 준비"
cp -r "$ROOT/src" "$ROOT/public" "$TMP/"
cp "$ROOT/VERSION" "$ROOT/CHANGELOG.json" "$TMP/" 2>/dev/null
mkdir -p "$TMP/storage"
# 가짜 .env — 키 이름은 .env.example 을 보고 적었다. 실제 .env 는 읽지도 복사하지도 않는다.
# DB_PORT=1 은 아무도 듣지 않는 포트라 연결이 즉시 거부된다(= Db::boot 실패).
cat > "$TMP/.env" <<ENVEOF
DB_HOST=127.0.0.1
DB_PORT=1
DB_NAME=fakedb
DB_USER=fakeuser
DB_PASS=fakepass-zz
DB_PERM_USER=fakepermuser
DB_PERM_PASS=fakepermpass-zz
APP_SECRET=0000000000000000000000000000000000000000000000000000000000000000
SESSION_IDLE_MIN=30
SESSION_MAX_HOURS=8
STORAGE_DIR=$TMP/storage
AUDIT_LOG_FILE=$TMP/audit.log
FETCH_ALLOWED_HOSTS=
ALERT_COMMAND=
APP_URL=http://127.0.0.1:$PORT
ENVEOF
echo "  · 임시 폴더: $TMP  (복사: src/ public/ VERSION CHANGELOG.json, 가짜 .env)"
echo "  · 가짜 .env 의 DB: 127.0.0.1:1 / fakedb / fakeuser"

sub "PHP 내장 서버 기동"
php -S "127.0.0.1:$PORT" -t "$TMP/public" "$TMP/public/index.php" > "$WORK/srv.log" 2>&1 &
SRV_PID=$!
READY=no
i=0
while [ "$i" -lt 25 ]; do
  C=$(curl -s -o /dev/null -w '%{http_code}' -A "$UA" "http://127.0.0.1:$PORT/api/health" 2>/dev/null)
  if [ -n "$C" ] && [ "$C" != "000" ]; then READY=yes; break; fi
  i=$((i+1))
  perl -e 'select(undef,undef,undef,0.2)' 2>/dev/null || true
done
expect "내장 서버 기동 (127.0.0.1:$PORT)" "yes" "$READY"

if [ "$READY" = yes ]; then
  BCODE=$(curl -s -o "$WORK/b.json" -D "$WORK/b.head" -w '%{http_code}' -A "$UA" "http://127.0.0.1:$PORT/api/health")
  echo "  · 응답 상태 : $BCODE"
  echo "  · 응답 본문 : $(cat "$WORK/b.json")"
  echo "  · 응답 헤더 :"; sed 's/^/      /' "$WORK/b.head"
  expect "GET /api/health → 503" "503" "$BCODE"
  expect "본문이 정확히 {\"status\":\"fail\",\"db\":\"connect_failed\"}" \
    '{"status":"fail","db":"connect_failed"}' "$(cat "$WORK/b.json")"
  expect "Cache-Control: no-store" "1" "$(grep -ci '^Cache-Control: no-store' "$WORK/b.head")"
  expect "Access-Control-* 0건"    "0" "$(grep -ci '^Access-Control-' "$WORK/b.head")"

  cat "$WORK/b.json" "$WORK/b.head" > "$WORK/b.all"
  for S in fakeuser fakepass-zz fakepermpass-zz fakedb 'port=1' SQLSTATE PDOException 'connection to server'; do
    expect "본문+헤더에 「$S」 0건" "0" "$(grep -oiF "$S" "$WORK/b.all" | wc -l)"
  done

  MCODE=$(curl -s -o "$WORK/m.json" -w '%{http_code}' -A "$UA" "http://127.0.0.1:$PORT/api/me")
  echo "  · GET /api/me → $MCODE  $(cat "$WORK/m.json")"
  expect "GET /api/me 는 여전히 500 (다른 창구는 전과 같다)" "500" "$MCODE"
  expect "GET /api/me 본문이 {\"error\":\"서버 오류\"}" '{"error":"서버 오류"}' "$(cat "$WORK/m.json")"

  sub "서버 로그 — 원문은 보이지 않는 곳에만 (S-09-7)"
  echo "  · error_log 에 남은 줄(요지):"
  grep -o '\[tbox\] request_id=[^ ]* [a-z: _]*' "$WORK/srv.log" | sed 's/^/      /' | head -10
  expect "로그에 db connect failed 가 남음" "yes" "$(grep -qF 'db connect failed' "$WORK/srv.log" && echo yes || echo no)"
  expect "로그에 health: connect_failed 가 남음" "yes" "$(grep -qF 'health: connect_failed' "$WORK/srv.log" && echo yes || echo no)"
fi

sub "정리"
[ -n "$SRV_PID" ] && kill "$SRV_PID" 2>/dev/null && SRV_PID=""
echo "  · 내장 서버 종료 · 임시 폴더는 trap 이 지운다"

# ════════════════════════════════════════════════════════════
hr "(c) 회귀"

sub "scripts/security_lint.sh"
if bash "$ROOT/scripts/security_lint.sh" > "$WORK/lint.txt" 2>&1; then
  expect "금지 패턴 0건" "0" "0"
else
  grep '✖' "$WORK/lint.txt" | sed 's/^/    /'; expect "금지 패턴 0건" "0" "1"
fi

sub "php -l (바뀐 PHP)"
LINT_FAIL=0
for f in "$ROOT/src/handlers/health.php" "$ROOT/src/Db.php" "$ROOT/src/bootstrap.php"; do
  if php -l "$f" > /dev/null 2>&1; then echo "    ✔ $f"; else echo "    ✖ $f"; LINT_FAIL=1; fi
done
expect "문법 오류 0" "0" "$LINT_FAIL"

sub "셸 문법 확인"
bash -n "$0" && expect "이 시험 파일 -n" "0" "$?"

sub "라이브가 여전히 정상인가 (b) 뒤 재확인"
expect "GET /api/health 200" "200" "$(curl -s -o /dev/null -w '%{http_code}' -A "$UA" "$BASE/api/health")"
expect "GET /login 200"      "200" "$(curl -s -o /dev/null -w '%{http_code}' -A "$UA" "$BASE/login")"

# ════════════════════════════════════════════════════════════
hr "결과"
echo "  통과 $PASS · 실패 $FAIL · 건너뜀 $SKIP"
[ "$FAIL" -eq 0 ] || exit 1
