#!/usr/bin/env bash
# tests/qf4b1.sh — Q:F-4 라운드 B1: P-2 DB 연결 재사용 · P-5c DB 는 필요할 때 연결 · P-4b heartbeat 를 가볍게
#
# 지시서: ../docs/qf4-quick-fixes-directive.md 라운드 B — P-2 ①~⑤ · P-5c ①~⑤ · P-4b ①~③
#         (ARCH 5.31 · SECURITY R-101)
#
# 틀: tests/community/stale_modules.sh 와 같다 — 운영 DB 를 **읽어** 임시 DB(tbox_qf4b1_test)를 만들고,
#     저장소를 임시 폴더로 복사한 PHP 내장 서버(127.0.0.1:8100)에서 잰다. 내장 서버는 **한 프로세스**라
#     FPM 으로 치면 「워커 1」이다 — 세션 수 기준도 워커 1 이다.
#   전(前) = BEFORE_REF(기본 9c20065 — 운영과 같은 커밋, 이 라운드 직전)의 코드 · 후(後) = 이 작업 트리.
#   두 인스턴스는 같은 임시 DB 를 쓰고 같은 포트를 **차례로** 쓴다(동시에 띄우지 않는다). 로그인은 한 번 —
#   세션 표가 같은 임시 DB 에 있으므로 전·후 인스턴스가 같은 쿠키를 받아들인다.
#
# ★ 운영 DB·운영 트리에는 쓰지 않는다. 연결을 끊는 시험(④)은 `datname = 임시 DB` 이고 앱 계정인 연결만 겨눈다.
# ★ 비밀(비밀번호·OTP 비밀)은 명령 인자에 싣지 않는다 — 로그인은 계정마다 파이썬 한 프로세스가 환경 변수로 받는다.
#   세션·CSRF 는 600 헤더 파일로만(`-H @파일`) 쓴다.
# ★ 시험 전용 경로 `/__qf4b/*` 는 **이 시험이 만드는 임시 인스턴스의 router.php 에만** 있다 — 제품 라우트 표
#   (public/index.php · src/community/routes.php)에는 없다. 트랜잭션을 열어 둔 채 끝나는 요청을 일부러 만든다.
# ★ 세션 수(`pg_stat_database.sessions`)는 임시 DB 한 줄만 읽는다. 읽는 psql 도 임시 DB 에 붙으므로 한 번 읽을
#   때마다 1 이 늘어난다 — 시작할 때 그 값을 재서(보정) 뺀다. 통계는 최대 0.5초 늦으므로 읽기 전에 1초 쉰다.
# 나가는 값: 0 통과 · 1 실패 · 3 준비물 없음 · 4 인스턴스 실패
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PORT=8100
SITE="http://127.0.0.1:$PORT"
BASE="$SITE/tbox"
CBASE="$SITE/community"
UA="tbox-review/$(date +%F)"
SRC_DB="tbox_re"              # 운영 — **읽기만** 한다 (pg_dump)
DB="tbox_qf4b1_test"          # 임시 — 여기에만 쓴다
NODB="tbox_qf4b1_nodb"        # 없는 DB 이름 — 「DB 가 내려가 있다」 흉내(만들지 않는다)
BEFORE_REF="${BEFORE_REF:-9dcd2e7}"
ACCOUNTS="claude_admin01 claude_manager01 claude_employee01 claude_employee02 claude_guest01"
N_REQ=100                     # P-2 ① 같은 워커로 보내는 요청 수
N_ROOT=20                     # P-5c ① 쿠키 없는 GET / 수
P50_N=30                      # p50 한 번에 보내는 요청 수
P50_ROUNDS=3                  # 같은 조건 반복 수(지시서 0장 「3회 이상」)
MISSING_UUID="00000000-0000-4000-8000-000000000b41"
WORK="$(mktemp -d)"
INST_OLD=""
INST_NEW=""
SERVER_PID=""
PASS=0
FAIL=0
SKIP=0

hr()  { printf '\n════════════════════════════════════════════════════════════\n%s\n════════════════════════════════════════════════════════════\n' "$1"; }
sub() { printf '\n── %s\n' "$1"; }
expect() {
  if [ "$2" = "$3" ]; then printf '  ✔ %s  (기대=%s 실제=%s)\n' "$1" "$2" "$3"; PASS=$((PASS+1))
  else printf '  ✖ %s  (기대=%s 실제=%s)\n' "$1" "$2" "$3"; FAIL=$((FAIL+1)); fi
}
note() { printf '  · %s\n' "$1"; }
skip() { printf '  ○ SKIP %s  (%s)\n' "$1" "$2"; SKIP=$((SKIP+1)); }

stop_server() {
  if [ -n "$SERVER_PID" ]; then kill "$SERVER_PID" 2>/dev/null; wait "$SERVER_PID" 2>/dev/null; SERVER_PID=""; fi
  for _ in 1 2 3 4 5 6 7 8 9 10; do
    ss -ltn 2>/dev/null | grep -q "127.0.0.1:$PORT " || return 0
    sleep 0.3
  done
}
cleanup() {
  stop_server
  sudo -n -u postgres dropdb --if-exists "$DB" >/dev/null 2>&1
  [ -n "$INST_OLD" ] && rm -rf "$INST_OLD"
  [ -n "$INST_NEW" ] && rm -rf "$INST_NEW"
  rm -rf "$WORK"
}
trap cleanup EXIT

psqlq() { sudo -n -u postgres psql -d "$DB" -t -A -c "$1" 2>/dev/null; }
code_of() { curl -s -o /dev/null -w '%{http_code}' -A "$UA" "$@"; }

# 임시 DB 세션 수 (읽는 psql 자신 1 포함 — OVERHEAD 로 뺀다)
sess() { sleep 1; psqlq "SELECT sessions FROM pg_stat_database WHERE datname = '$DB'"; }
app_conns() { psqlq "SELECT count(*) FROM pg_stat_activity WHERE datname = '$DB' AND usename = '$APP_DB_USER'"; }
idle_tx() { psqlq "SELECT count(*) FROM pg_stat_activity WHERE datname = '$DB' AND state LIKE 'idle in transaction%'"; }

# 인스턴스 하나 — $1 폴더, $2 new|old
make_inst() {
  local dir="$1" kind="$2"
  mkdir -p "$dir/app" "$dir/storage" "$dir/log"
  if [ "$kind" = new ]; then
    tar -C "$ROOT" -cf - --exclude=.git --exclude=jar --exclude=api-test.txt --exclude=./.env . | tar -C "$dir/app" -xf -
  else
    git -C "$ROOT" archive "$BEFORE_REF" | tar -C "$dir/app" -xf - || return 1
  fi
  sed -e "s#^DB_NAME=.*#DB_NAME=$DB#" \
      -e "s#^STORAGE_DIR=.*#STORAGE_DIR=$dir/storage#" \
      -e "s#^AUDIT_LOG_FILE=.*#AUDIT_LOG_FILE=$dir/log/audit.log#" \
      "$ROOT/.env" > "$dir/app/.env"
  chmod 600 "$dir/app/.env"
  grep -q "^DB_NAME=$DB\$" "$dir/app/.env" || return 1
  sed -e "s#^DB_NAME=.*#DB_NAME=$NODB#" "$dir/app/.env" > "$dir/app/.env.nodb"
  chmod 600 "$dir/app/.env.nodb"
  cp -p "$dir/app/.env" "$dir/app/.env.db"
  ln -s "$PLAN_DIR" "$dir/community" 2>/dev/null
  cat > "$dir/app/router.php" <<'PHP'
<?php
$root = __DIR__ . '/public';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (is_string($path) && strpos($path, '/tbox/assets/') === 0) {
    $rel = substr($path, strlen('/tbox/assets/'));
    if (strpos($rel, '..') === false) {
        $file = $root . '/assets/' . $rel;
        if (is_file($file)) {
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            $types = ['js' => 'text/javascript', 'css' => 'text/css', 'json' => 'application/json', 'svg' => 'image/svg+xml', 'png' => 'image/png'];
            header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
            readfile($file);
            return true;
        }
    }
    http_response_code(404);
    return true;
}
if (is_string($path) && strpos($path, '/__qf4b/') === 0 && is_file(__DIR__ . '/qf4b_probe.php')) {
    require __DIR__ . '/qf4b_probe.php';
    return true;
}
require $root . '/index.php';
PHP
  if [ "$kind" = new ]; then
    # 시험 전용 경로 — 이 임시 인스턴스에만 있다(제품 코드·라우트 표 밖). 트랜잭션을 열어 둔 채 끝나는 요청을 만든다.
    cat > "$dir/app/qf4b_probe.php" <<'PHP'
<?php
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';
$mode = substr((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), strlen('/__qf4b/'));
$db   = Db::instance();
header('Content-Type: application/json');
$probeSql = 'SELECT pg_backend_pid() AS pid, pg_current_xact_id_if_assigned()::text AS xid,'
    . ' (SELECT count(*) FROM pg_prepared_statements) AS prepared,'
    . " current_setting('application_name') AS app_name,"
    . " to_regclass('pg_temp.qf4b_leak') IS NOT NULL AS temp_table,"
    . " (SELECT count(*) FROM pg_locks WHERE locktype = 'advisory' AND pid = pg_backend_pid()) AS adv_locks";
$probe = static function () use ($db, $probeSql): array {
    $r = $db->run($probeSql)->fetch();
    return ['pid' => (int) $r['pid'], 'xid' => $r['xid'], 'prepared' => (int) $r['prepared'], 'in_tx' => $db->inTransaction(),
            'app_name' => (string) $r['app_name'], 'temp_table' => (bool) $r['temp_table'], 'adv_locks' => (int) $r['adv_locks']];
};
if ($mode === 'probe') {
    // 이 연결의 백엔드 · 물려받은 트랜잭션의 xid(없으면 null) · 세션에 남은 서버 prepare 수(자기 것 1 포함) · 세션 상태 셋
    echo json_encode($probe());
    return;
}
if ($mode === 'state') {
    // ★ 시험 전용 — 제품 코드에는 0 이어야 하는 세션 상태(③ grep)를 일부러 남기고 끝난다. 다음 요청이 물려받지 않는지 본다.
    $db->run("SELECT set_config('application_name', 'qf4b-leak', false)");
    $db->run('CREATE TEMP TABLE qf4b_leak (x int)');
    $db->run('SELECT pg_advisory_lock(4242)');
    echo json_encode($probe());
    return;
}
$modes = ['api-exception', 'raw-exception', 'api-exit', 'raw-exit', 'api-fatal', 'raw-fatal'];
if (!in_array($mode, $modes, true)) {
    http_response_code(404);
    echo '{}';
    return;
}
// raw-* 는 Db::begin 을 거치지 않은 트랜잭션(SQL 로 직접 BEGIN) — 제품 코드에는 0 이어야 하지만(③) 생겼을 때도 막히는지 본다
if (strpos($mode, 'raw-') === 0) {
    $db->run('BEGIN');
} else {
    $db->begin();
}
// xid 를 받아 두면(쓰기와 같다) 다음 요청이 이 트랜잭션을 물려받았는지 xid 로 가를 수 있다
$r = $db->run('SELECT pg_backend_pid() AS pid, pg_current_xact_id()::text AS xid')->fetch();
file_put_contents(dirname(__DIR__) . '/leak.txt', $mode . ' ' . $r['pid'] . ' ' . $r['xid'] . "\n", FILE_APPEND);
if (substr($mode, -10) === '-exception') {
    $db->run('SELECT 1/0');                 // 22012 — 트랜잭션 안의 예외 → bootstrap 예외 처리기
} elseif (substr($mode, -5) === '-exit') {
    echo '{"left":"open"}';
    exit;                                   // 트랜잭션 안의 exit (트랜잭션 안의 Http::error 와 같은 모양)
} else {
    ini_set('memory_limit', '16M');
    $x = str_repeat('x', 64 * 1024 * 1024); // 치명적 오류 — 예외 처리기가 돌지 않는다
}
PHP
  fi
  return 0
}

start_server() {
  local dir="$1"
  if ss -ltn 2>/dev/null | grep -q "127.0.0.1:$PORT "; then
    echo "포트 $PORT 이 이미 쓰이고 있다 — 앞선 시험이 남긴 서버를 먼저 내린다"; exit 3
  fi
  ( cd "$dir/app" && exec php -S "127.0.0.1:$PORT" -t public router.php >> "$dir/server.log" 2>&1 ) &
  SERVER_PID=$!
  local ready=""
  for _ in 1 2 3 4 5 6 7 8 9 10 11 12 13 14 15; do
    sleep 0.4
    ready=$(code_of "$SITE/")      # 런처 — 쿠키 없는 GET / (새 코드에서는 DB 에 닿지 않는다)
    [ "$ready" = "200" ] && break
  done
  [ "$ready" = "200" ] || { echo "인스턴스를 띄우지 못했다($dir) — 중단"; tail -5 "$dir/server.log"; exit 4; }
}

# p50(ms) — $P50_ROUNDS 번, 한 번에 $P50_N 요청. 출력 "r1 r2 r3"
p50() {
  local out="" r
  for r in $(seq 1 "$P50_ROUNDS"); do
    out="$out $(for _ in $(seq 1 "$P50_N"); do curl -s -o /dev/null -w '%{time_total}\n' -A "$UA" "$@"; done \
      | sort -n | awk '{a[NR]=$1} END{printf "%.1f", a[int((NR+1)/2)]*1000}')"
  done
  echo "${out# }"
}
median3() { tr ' ' '\n' <<<"$1" | sort -n | awk '{a[NR]=$1} END{print a[int((NR+1)/2)]}'; }
# 런처 HTML 지문 — CSS 주소의 `?v=<파일 시각>` 은 인스턴스마다 달라(복사 시각) 지우고 잰다
html_fp() { sed -E 's/\?v=[0-9]+/?v=N/g' "$1" | sha256sum | cut -c1-16; }

# ════════════════════════════════════════════════════════════════
hr "Q:F-4 B1 — 준비 (운영 DB 에는 쓰지 않는다)"

avail_kb=$(df -Pk / | awk 'NR==2{print $4}')
[ "$avail_kb" -ge 1048576 ] || { echo "루트 디스크 여유 1GB 미만 — 시험을 시작하지 않는다 (SECURITY R-80)"; exit 3; }
sudo -n -u postgres psql -d "$SRC_DB" -tAc "SELECT 1" >/dev/null 2>&1 \
  || { echo "psql(postgres) 을 쓸 수 없다 — 이 시험은 임시 DB 가 없으면 성립하지 않는다"; exit 3; }
git -C "$ROOT" rev-parse --verify -q "$BEFORE_REF^{commit}" >/dev/null \
  || { echo "BEFORE_REF=$BEFORE_REF 를 찾을 수 없다(얕은 클론?) — 전후 비교를 할 수 없다"; exit 3; }
APP_DB_USER=$(sed -n 's/^DB_USER=//p' "$ROOT/.env")
[ -n "$APP_DB_USER" ] || { echo ".env 에 DB_USER 가 없다"; exit 3; }
PLAN_DIR="$(cd "$ROOT/.." && pwd)/community"
[ -f "$PLAN_DIR/doc-schema.json" ] || PLAN_DIR=/var/www/qaredi/community
note "전 = $BEFORE_REF($(git -C "$ROOT" log -1 --format=%h "$BEFORE_REF")) · 후 = 작업 트리 · 앱 DB 계정 $APP_DB_USER"

sub "운영 DB 를 **읽어** 임시 DB 를 만든다 (파이프 — 평문 덤프 파일 0)"
sudo -n -u postgres dropdb --if-exists "$DB" >/dev/null 2>&1
sudo -n -u postgres createdb -O tbox_owner "$DB" >/dev/null 2>&1 || { echo "임시 DB 를 만들 수 없다"; exit 3; }
set -o pipefail
sudo -n -u postgres pg_dump -d "$SRC_DB" 2>/dev/null \
  | sudo -n -u postgres psql -q -d "$DB" -v ON_ERROR_STOP=0 >/dev/null 2>"$WORK/restore.err" \
  || { set +o pipefail; echo "운영 DB 를 읽어 임시 DB 로 옮기지 못했다 (pg_dump | psql)"; exit 3; }
set +o pipefail
sudo -n -u postgres psql -q -d "$DB" -v ON_ERROR_STOP=1 -f - < "$ROOT/db/community/grants.sql" 2>&1 \
  | grep -v 'could not change directory' | sed 's/^/    /'
n_pages=$(psqlq "SELECT count(*) FROM community.pages")
[ -n "$n_pages" ] || { echo "임시 DB 로 옮기지 못했다 — $(head -2 "$WORK/restore.err")"; exit 3; }
sudo -n -u postgres psql -d postgres -tAc "SELECT 1 FROM pg_database WHERE datname = '$NODB'" 2>/dev/null | grep -q 1 \
  && { echo "$NODB 가 실제로 있다 — 「DB 없음」 흉내가 성립하지 않는다"; exit 3; }
note "임시 DB $DB — 페이지 ${n_pages}장 · 사용자 $(psqlq "SELECT count(*) FROM public.users")명 · 가장 큰 본문 $(psqlq "SELECT max(octet_length(doc::text)) FROM community.pages")B"
# P-4b 를 따로 재려면 큰 본문이 있어야 한다(지시서 「실측 최대 99KB」 — 지금 운영 사본에는 그만한 본문이 없다).
# 임시 DB 에서만, 가장 큰 본문 페이지 하나의 doc 을 약 100KB 짜리로 바꾼다(판정은 doc 을 보지 않는다 — 판정 표에 영향 없음).
SYN_PID=$(psqlq "SELECT public_id FROM community.pages WHERE deleted_at IS NULL AND doc IS NOT NULL ORDER BY octet_length(doc::text) DESC LIMIT 1")
psqlq "UPDATE community.pages SET doc = jsonb_build_object('schema', 1, 'blocks',
         (SELECT jsonb_agg(jsonb_build_object('id', md5(g::text), 'type', 'paragraph',
                 'text', jsonb_build_array(jsonb_build_object('t', repeat('가나다라마바사아자차', 3), 'm', '{}'::jsonb)))) FROM generate_series(1, 700) g))
        WHERE public_id = '$SYN_PID'" >/dev/null
note "(임시 DB) 페이지 $SYN_PID 의 본문을 $(psqlq "SELECT octet_length(doc::text) FROM community.pages WHERE public_id = '$SYN_PID'")B 로 — P-4b 측정용"

sub "인스턴스 두 벌 (전 = $BEFORE_REF · 후 = 작업 트리)"
INST_OLD="$(mktemp -d)"; INST_NEW="$(mktemp -d)"
make_inst "$INST_OLD" old || { echo "전 인스턴스를 만들지 못했다"; exit 4; }
make_inst "$INST_NEW" new || { echo "후 인스턴스를 만들지 못했다"; exit 4; }
expect "전 인스턴스의 Db.php 는 영속 연결이 없다(비교 기준)" "0" "$(grep -c 'ATTR_PERSISTENT' "$INST_OLD/app/src/Db.php")"
expect "후 인스턴스의 Db.php 는 영속 연결을 쓴다" "yes" "$(grep -q 'PDO::ATTR_PERSISTENT' "$INST_NEW/app/src/Db.php" && echo yes || echo no)"
expect "시험 전용 경로는 후 인스턴스의 router 옆 파일에만 (제품 트리에 qf4b_probe 0)" "0" \
  "$(grep -rl 'qf4b_probe\|__qf4b' "$ROOT/src" "$ROOT/public" 2>/dev/null | wc -l | tr -d ' ')"

sub "세션 수 읽기 보정 (읽는 psql 자신이 몇을 더하나)"
c1=$(sess); c2=$(sess); c3=$(sess)
OVERHEAD=$((c2 - c1))
expect "보정 — 읽기 한 번에 +1, 두 번 연속 같은 값" "1|1" "$((c2 - c1))|$((c3 - c2))"
delta() { echo $(( $2 - $1 - OVERHEAD )); }

# ════════════════════════════════════════════════════════════════
hr "전(前) — $BEFORE_REF 코드 (비영속 · 부팅 때 연결)"
start_server "$INST_OLD"
s0=$(sess)
for _ in $(seq 1 "$N_ROOT"); do code_of "$SITE/" >/dev/null; done
s1=$(sess)
OLD_ROOT_DELTA=$(delta "$s0" "$s1")
note "쿠키 없는 GET / ${N_ROOT}회 → 세션 +$OLD_ROOT_DELTA (전: 요청마다 연결)"

sub "로그인 — 검토 계정 5개 (비밀은 계정마다 파이썬 한 프로세스의 환경 변수로만)"
mkdir -p "$WORK/hdr"; chmod 700 "$WORK/hdr"
for WHO in $ACCOUNTS; do
  PW=$(sudo -n awk -v u="$WHO" '$1==u{print $3}' /etc/tbox-v2/seed-accounts.txt 2>/dev/null)
  SEC=$(sudo -n awk -v u="$WHO" '$1==u{print $2}' /etc/tbox-v2/qa-totp.txt 2>/dev/null)
  [ -n "$PW" ] || { echo "  ✖ $WHO 비밀번호를 읽지 못했다(sudo) — 중단"; exit 3; }
  LOGIN_OUT=$(LOGIN_PW="$PW" LOGIN_SEC="$SEC" python3 - "$BASE" "$UA" "$WHO" <<'PY'
import base64, hashlib, hmac, json, os, re, struct, sys, time, urllib.request
base, ua, who = sys.argv[1], sys.argv[2], sys.argv[3]
pw, sec = os.environ.get("LOGIN_PW", ""), os.environ.get("LOGIN_SEC", "")
def totp(secret, t):
    key = base64.b32decode(secret.upper() + "=" * (-len(secret) % 8))
    mac = hmac.new(key, struct.pack(">Q", int(t) // 30), hashlib.sha1).digest()
    o = mac[-1] & 15
    return "%06d" % ((struct.unpack(">I", mac[o:o + 4])[0] & 0x7FFFFFFF) % 1000000)
for attempt in range(3):
    body = {"username": who, "password": pw}
    if sec:
        body["otp"] = totp(sec, time.time())
    req = urllib.request.Request(base + "/api/login", data=json.dumps(body).encode(), method="POST",
                                 headers={"Content-Type": "application/json", "User-Agent": ua})
    cookie, data = "", {}
    try:
        resp = urllib.request.urlopen(req, timeout=15)
        data = json.loads(resp.read().decode())
        for h in resp.headers.get_all("Set-Cookie") or []:
            m = re.match(r"tbox_session=([^;]+)", h)
            if m:
                cookie = m.group(1)
    except Exception:
        pass
    if data.get("ok") is True and cookie:
        raw = urllib.request.urlopen(urllib.request.Request(base + "/api/me", headers={"User-Agent": ua, "Cookie": "tbox_session=" + cookie}), timeout=15).read().decode()
        m = re.search(r'"csrf_token":"([0-9a-f]+)"', raw)
        nx = re.search(r'"next":(null|"[a-z_]*")', raw)
        print("SESSION=" + cookie)
        print("CSRF=" + (m.group(1) if m else ""))
        print("NEXT=" + (nx.group(1) if nx else "?"))
        sys.exit(0)
    wait = 31 - int(time.time()) % 30
    print("  · 로그인 재시도 — 다음 코드 창까지 %d초" % wait, file=sys.stderr)
    time.sleep(wait)
sys.exit(1)
PY
)
  unset PW SEC
  SESSION=$(printf '%s\n' "$LOGIN_OUT" | sed -n 's/^SESSION=//p')
  CSRF=$(printf '%s\n' "$LOGIN_OUT" | sed -n 's/^CSRF=//p')
  NEXT=$(printf '%s\n' "$LOGIN_OUT" | sed -n 's/^NEXT=//p')
  [ -n "$SESSION" ] && [ -n "$CSRF" ] || { echo "  ✖ $WHO 로그인 실패 — 중단"; exit 4; }
  ( umask 077; printf 'Cookie: tbox_session=%s\n' "$SESSION" > "$WORK/hdr/$WHO.cookie"
    printf 'Cookie: tbox_session=%s\nX-CSRF-Token: %s\n' "$SESSION" "$CSRF" > "$WORK/hdr/$WHO.write" )
  unset SESSION CSRF LOGIN_OUT
  expect "$WHO 로그인 (다음 단계 없음 — 관문에 막히지 않는다)" "null" "$NEXT"
done
E1C="$WORK/hdr/claude_employee01.cookie"

sub "P-2 전 — 같은 워커로 ${N_REQ}회"
s0=$(sess); for _ in $(seq 1 "$N_REQ"); do code_of "$BASE/api/health" >/dev/null; done; s1=$(sess)
OLD_HEALTH_DELTA=$(delta "$s0" "$s1")
s0=$(sess); for _ in $(seq 1 "$N_REQ"); do code_of -H @"$E1C" "$BASE/api/me" >/dev/null; done; s1=$(sess)
OLD_ME_DELTA=$(delta "$s0" "$s1")
note "GET /tbox/api/health ${N_REQ}회 → 세션 +$OLD_HEALTH_DELTA · GET /tbox/api/me(로그인) ${N_REQ}회 → 세션 +$OLD_ME_DELTA"

sub "p50 전 (ms, ${P50_ROUNDS}회 × ${P50_N}요청)"
OLD_P50_HEALTH=$(p50 "$BASE/api/health")
OLD_P50_ROOT=$(p50 "$SITE/")
OLD_P50_ME=$(p50 -H @"$E1C" "$BASE/api/me")
note "GET /tbox/api/health p50: $OLD_P50_HEALTH · GET / (쿠키 없음) p50: $OLD_P50_ROOT · GET /tbox/api/me p50: $OLD_P50_ME"

sub "P-4b 전 — heartbeat 판정 표 (검토 계정 5 × 페이지 ${n_pages} + 없는 UUID 1)"
psqlq "SELECT public_id FROM community.pages ORDER BY id" > "$WORK/pages.txt"
echo "$MISSING_UUID" >> "$WORK/pages.txt"
cat > "$WORK/hbmatrix.py" <<'PY'
# heartbeat 전수 — 계정마다 헤더 파일(600)에서 쿠키·CSRF 를 읽는다(명령 인자에 싣지 않는다)
import json, sys, urllib.request, urllib.error
site, ua, hdrdir, pagesfile = sys.argv[1:5]
accounts = sys.argv[5:]
pages = [l.strip() for l in open(pagesfile) if l.strip()]
for acct in accounts:
    h = {}
    for line in open(hdrdir + "/" + acct + ".write"):
        k, _, v = line.rstrip("\n").partition(": ")
        h[k] = v
    for pid in pages:
        req = urllib.request.Request(site + "/community/api/pages/" + pid + "/heartbeat", data=b"{}", method="POST",
                                     headers={"Content-Type": "application/json", "User-Agent": ua,
                                              "Cookie": h["Cookie"], "X-CSRF-Token": h["X-CSRF-Token"]})
        try:
            resp = urllib.request.urlopen(req, timeout=20)
            code, body = resp.status, resp.read().decode()
        except urllib.error.HTTPError as e:
            code, body = e.code, e.read().decode()
        if code == 200:
            d = json.loads(body)
            ek = sorted({k for e in d.get("editors", []) for k in e}) if isinstance(d.get("editors"), list) else ["?"]
            shape = "keys=" + ",".join(sorted(d)) + ";editors=" + type(d.get("editors")).__name__ + ";ekeys=" + (",".join(ek) or "-")
        else:
            shape = body.strip()
        print(acct, pid, code, shape)
PY
python3 "$WORK/hbmatrix.py" "$SITE" "$UA" "$WORK/hdr" "$WORK/pages.txt" $ACCOUNTS > "$WORK/hb_old.txt"
note "전 표: $(wc -l < "$WORK/hb_old.txt")칸 — $(awk '{print $3}' "$WORK/hb_old.txt" | sort | uniq -c | tr '\n' ' ' | tr -s ' ')"

# heartbeat p50 은 본문이 가장 큰 페이지 가운데 200 인 칸에서 잰다
read -r HB_ACCT HB_PID HB_DOC <<<"$(psqlq "SELECT public_id || ' ' || octet_length(doc::text) FROM community.pages WHERE doc IS NOT NULL ORDER BY octet_length(doc::text) DESC" \
  | while read -r pid sz; do a=$(awk -v p="$pid" '$2==p && $3==200 {print $1; exit}' "$WORK/hb_old.txt"); [ -n "$a" ] && { echo "$a $pid $sz"; break; }; done)"
if [ -n "$HB_ACCT" ]; then
  OLD_P50_HB=$(p50 -X POST -H 'Content-Type: application/json' -H @"$WORK/hdr/$HB_ACCT.write" -d '{}' "$CBASE/api/pages/$HB_PID/heartbeat")
  note "heartbeat p50 전 ($HB_ACCT · 본문 ${HB_DOC}B 페이지): $OLD_P50_HB"
fi
# 가장 큰 본문 페이지 — claude_admin01 은 읽을 수 있고(admin_all) 고칠 수 없어 403 이지만, 판정 전에 행을 읽는 비용은 같다
read -r BIG_PID BIG_DOC <<<"$(psqlq "SELECT public_id || ' ' || octet_length(doc::text) FROM community.pages WHERE doc IS NOT NULL ORDER BY octet_length(doc::text) DESC LIMIT 1")"
BIG_CODE=$(awk -v p="$BIG_PID" '$1=="claude_admin01" && $2==p {print $3}' "$WORK/hb_old.txt")
OLD_P50_BIG=$(p50 -X POST -H 'Content-Type: application/json' -H @"$WORK/hdr/claude_admin01.write" -d '{}' "$CBASE/api/pages/$BIG_PID/heartbeat")
note "heartbeat p50 전 (claude_admin01 · 가장 큰 본문 ${BIG_DOC}B 페이지 · 응답 $BIG_CODE): $OLD_P50_BIG"

sub "런처 — 로그인 쿠키가 있을 때의 화면(전)"
curl -s -A "$UA" -H @"$E1C" -o "$WORK/root_cookie_old.html" "$SITE/"
stop_server

# ════════════════════════════════════════════════════════════════
hr "후(後) — 작업 트리 (P-5c 필요할 때 연결 · P-2 영속)"
start_server "$INST_NEW"

sub "P-5c ① DB 가 필요 없는 요청은 연결하지 않는다 (새로 띄운 워커 — 아직 연결 0)"
expect "막 띄운 워커의 앱 연결 (준비 확인 GET / 뒤)" "0" "$(app_conns)"
s0=$(sess)
for _ in $(seq 1 "$N_ROOT"); do code_of "$SITE/" >/dev/null; done
s1=$(sess)
expect "쿠키 없는 GET / ${N_ROOT}회 → 세션 증가 (전 +$OLD_ROOT_DELTA)" "0" "$(delta "$s0" "$s1")"
s0=$(sess)
for p in /api/health /login /settings /api/me; do code_of "$SITE$p" >/dev/null; done
code_of -X POST -H 'Content-Type: application/json' -d '{}' "$SITE/api/login" >/dev/null
for p in /nope-qf4b /tbox/nope-qf4b /tbox/api/nope; do code_of "$SITE$p" >/dev/null; done
s1=$(sess)
expect "옛 주소 308 다섯 · 404 셋 → 세션 증가" "0" "$(delta "$s0" "$s1")"
expect "그 뒤에도 이 워커의 앱 연결" "0" "$(app_conns)"

sub "Q:T-1 (쿠키 없는 런처) · Q:T-3 (옛 주소 308) — 이 인스턴스에서"
curl -s -A "$UA" -D "$WORK/root.head" -o "$WORK/root.html" "$SITE/"
expect "GET / 상태" "200" "$(sed -n '1s/^HTTP[^ ]* \([0-9]*\).*/\1/p' "$WORK/root.head")"
expect "Set-Cookie 0건" "0" "$(grep -ci '^Set-Cookie:' "$WORK/root.head" | tr -d ' ')"
expect "<script 0건" "0" "$(grep -c '<script' "$WORK/root.html" | tr -d ' ')"
expect "미로그인 사용자 표시 없음 (page-head__actions 0)" "0" "$(grep -c 'page-head__actions' "$WORK/root.html" | tr -d ' ')"
expect "t-box 타일 /tbox/ · 커뮤니티 타일 /community/" "1|1" \
  "$(grep -c 'href="/tbox/"' "$WORK/root.html" | tr -d ' ')|$(grep -c 'href="/community/"' "$WORK/root.html" | tr -d ' ')"
expect "POST / 405" "405" "$(code_of -X POST "$SITE/")"
for p in '/api/health' '/api/health?probe=1&x=%2F' '/login' '/settings'; do
  curl -s -A "$UA" -D "$WORK/r.head" -o /dev/null "$SITE$p"
  expect "GET $p — 308 · Location /tbox$p" "308|/tbox$p" \
    "$(sed -n '1s/^HTTP[^ ]* \([0-9]*\).*/\1/p' "$WORK/r.head")|$(sed -n 's/^Location: *//Ip' "$WORK/r.head" | tr -d '\r')"
done
curl -s -A "$UA" -D "$WORK/r.head" -o /dev/null -X POST -H 'Content-Type: application/json' -d '{}' "$SITE/api/login"
expect "POST /api/login — 308 · Location /tbox/api/login" "308|/tbox/api/login" \
  "$(sed -n '1s/^HTTP[^ ]* \([0-9]*\).*/\1/p' "$WORK/r.head")|$(sed -n 's/^Location: *//Ip' "$WORK/r.head" | tr -d '\r')"
expect "GET /nope-qf4b 404" "404" "$(code_of "$SITE/nope-qf4b")"

sub "P-5c ② 로그인 쿠키가 있으면 사용자 칩이 전처럼"
curl -s -A "$UA" -H @"$E1C" -o "$WORK/root_cookie_new.html" "$SITE/"
expect "쿠키 런처에 사용자 배지 (page-head__actions 1)" "1" "$(grep -c 'page-head__actions' "$WORK/root_cookie_new.html" | tr -d ' ')"
expect "쿠키 런처 HTML 이 전과 같다 (CSS ?v= 파일 시각만 지우고)" "$(html_fp "$WORK/root_cookie_old.html")" "$(html_fp "$WORK/root_cookie_new.html")"

sub "P-5c ③ /tbox/api/health 는 여전히 DB 를 확인한다 (DB 가 있을 때)"
expect "GET /tbox/api/health → 200 · db ok" "200|ok" \
  "$(curl -s -A "$UA" -o "$WORK/h.json" -w '%{http_code}' "$BASE/api/health")|$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1])).get("db"))' "$WORK/h.json")"

sub "P-2 ① 같은 워커로 ${N_REQ}회 → 세션 증가 ≤ 1 (워커 수)"
s0=$(sess); for _ in $(seq 1 "$N_REQ"); do code_of "$BASE/api/health" >/dev/null; done; s1=$(sess)
NEW_HEALTH_DELTA=$(delta "$s0" "$s1")
expect "GET /tbox/api/health ${N_REQ}회 → 세션 증가 ≤ 1 (전 +$OLD_HEALTH_DELTA)" "yes" "$([ "$NEW_HEALTH_DELTA" -le 1 ] && echo yes || echo "no($NEW_HEALTH_DELTA)")"
s0=$(sess); for _ in $(seq 1 "$N_REQ"); do code_of -H @"$E1C" "$BASE/api/me" >/dev/null; done; s1=$(sess)
NEW_ME_DELTA=$(delta "$s0" "$s1")
expect "GET /tbox/api/me(로그인) ${N_REQ}회 → 세션 증가 ≤ 1 (전 +$OLD_ME_DELTA)" "yes" "$([ "$NEW_ME_DELTA" -le 1 ] && echo yes || echo "no($NEW_ME_DELTA)")"
expect "이 워커의 앱 연결 1개 (재사용 중)" "1" "$(app_conns)"
expect "요청 사이 idle in transaction 0" "0" "$(idle_tx)"

sub "P-2 ⑤ · P-5c p50 후 (ms, ${P50_ROUNDS}회 × ${P50_N}요청)"
NEW_P50_HEALTH=$(p50 "$BASE/api/health")
NEW_P50_ROOT=$(p50 "$SITE/")
NEW_P50_ME=$(p50 -H @"$E1C" "$BASE/api/me")
note "GET /tbox/api/health p50: $NEW_P50_HEALTH · GET / (쿠키 없음) p50: $NEW_P50_ROOT · GET /tbox/api/me p50: $NEW_P50_ME"

sub "P-4b ① heartbeat 판정 표 — 전과 같은가 (HTTP, 검토 계정 5 × 페이지 전부)"
python3 "$WORK/hbmatrix.py" "$SITE" "$UA" "$WORK/hdr" "$WORK/pages.txt" $ACCOUNTS > "$WORK/hb_new.txt"
note "후 표: $(wc -l < "$WORK/hb_new.txt")칸 — $(awk '{print $3}' "$WORK/hb_new.txt" | sort | uniq -c | tr '\n' ' ' | tr -s ' ')"
expect "상태 표(200/403/404) 전후 동일 — 어긋난 칸" "0" \
  "$(paste -d' ' <(awk '{print $1, $2, $3}' "$WORK/hb_old.txt") <(awk '{print $1, $2, $3}' "$WORK/hb_new.txt") | awk '$1!=$4 || $2!=$5 || $3!=$6' | wc -l | tr -d ' ')"
expect "칸 수 전후 동일" "$(wc -l < "$WORK/hb_old.txt" | tr -d ' ')" "$(wc -l < "$WORK/hb_new.txt" | tr -d ' ')"
n200=$(awk '$3==200' "$WORK/hb_new.txt" | wc -l); n403=$(awk '$3==403' "$WORK/hb_new.txt" | wc -l); n404=$(awk '$3==404' "$WORK/hb_new.txt" | wc -l)
expect "200 · 403 · 404 가 모두 대조에 들어 있다 (각 1칸 이상)" "yes" "$([ "$n200" -gt 0 ] && [ "$n403" -gt 0 ] && [ "$n404" -gt 0 ] && echo yes || echo no)"
sub "P-4b ③ 응답 형식 불변"
python3 - "$WORK/hb_old.txt" "$WORK/hb_new.txt" > "$WORK/shape.txt" <<'PY'
import sys
def load(p):
    return [l.rstrip("\n").split(" ", 3) for l in open(p)]
old, new = load(sys.argv[1]), load(sys.argv[2])
bad = 0
ekeys = set()
for o, n in zip(old, new):
    if o[2] != "200":
        if o[3] != n[3]:
            bad += 1
        continue
    # 200 — 키 모양이 같아야 한다. 편집자 목록은 전후 사이 신호로 바뀌므로 비어 있을 때(-)는 모양만 본다
    oa, na = o[3].split(";ekeys="), n[3].split(";ekeys=")
    if oa[0] != na[0]:
        bad += 1
    for x in (oa[1], na[1]):
        if x != "-":
            ekeys.add(x)
print("SHAPE_MISMATCH=%d" % bad)
print("EDITOR_KEYS=%s" % "|".join(sorted(ekeys)))
PY
expect "오류 본문·200 본문 키 모양 전후 동일 — 어긋난 칸" "SHAPE_MISMATCH=0" "$(grep '^SHAPE_MISMATCH=' "$WORK/shape.txt")"
expect "editors 항목의 키 (전후 모두)" "EDITOR_KEYS=display_name,last_seen_at,username" "$(grep '^EDITOR_KEYS=' "$WORK/shape.txt")"
expect "200 본문 최상위 키 = editors 하나" "keys=editors;editors=list" \
  "$(awk '$3==200 {split($4,a,";ekeys="); print a[1]; exit}' "$WORK/hb_new.txt")"
if [ -n "$HB_ACCT" ]; then
  NEW_P50_HB=$(p50 -X POST -H 'Content-Type: application/json' -H @"$WORK/hdr/$HB_ACCT.write" -d '{}' "$CBASE/api/pages/$HB_PID/heartbeat")
  note "heartbeat p50 후 ($HB_ACCT · 본문 ${HB_DOC}B 페이지): $NEW_P50_HB"
fi
NEW_P50_BIG=$(p50 -X POST -H 'Content-Type: application/json' -H @"$WORK/hdr/claude_admin01.write" -d '{}' "$CBASE/api/pages/$BIG_PID/heartbeat")
note "heartbeat p50 후 (claude_admin01 · 가장 큰 본문 ${BIG_DOC}B 페이지 · 응답 $BIG_CODE): $NEW_P50_BIG"

sub "P-4b 편집기로 눌러 보기 — 두 검토 계정이 같은 페이지에서 편집 → 서로 보인다 (헤드리스 브라우저)"
python3 -c 'import playwright' 2>/dev/null || export PATH="$HOME/.local/bin:$PATH"
BR_PID=$(awk '$1=="claude_employee01" && $3==200 {print $2}' "$WORK/hb_new.txt" | while read -r pid; do
  awk -v p="$pid" '$1=="claude_employee02" && $2==p && $3==200 {print p; exit}' "$WORK/hb_new.txt"; done | head -1)
if [ -z "$BR_PID" ]; then
  # 두 계정이 함께 고칠 수 있는 페이지가 사본에 없으면 — 임시 DB 에서만 employee01 의 페이지 하나를 employee02 에게 편집 공유(stage5a2.sh 와 같은 방식)
  BR_PID=$(awk '$1=="claude_employee01" && $3==200 {print $2; exit}' "$WORK/hb_new.txt")
  if [ -n "$BR_PID" ]; then
    psqlq "INSERT INTO community.page_shares (page_id, user_id, level, granted_by)
           SELECT p.id, (SELECT id FROM public.users WHERE username = 'claude_employee02'), 'edit', (SELECT id FROM public.users WHERE username = 'claude_employee01')
             FROM community.pages p WHERE p.public_id = '$BR_PID'" >/dev/null
    note "(임시 DB) employee01 의 페이지 $BR_PID 를 employee02 에게 편집 공유"
    expect "  공유 뒤 employee02 heartbeat 200" "200" \
      "$(code_of -X POST -H 'Content-Type: application/json' -H @"$WORK/hdr/claude_employee02.write" -d '{}' "$CBASE/api/pages/$BR_PID/heartbeat")"
  fi
fi
if ! python3 -c 'import playwright' 2>/dev/null; then
  skip "편집기 heartbeat 브라우저 확인" "playwright 없음"
elif [ -z "$BR_PID" ]; then
  skip "편집기 heartbeat 브라우저 확인" "employee01 이 고칠 수 있는 페이지가 임시 DB 에 없다"
else
  psqlq "DELETE FROM community.page_edit_sessions WHERE page_id = (SELECT id FROM community.pages WHERE public_id = '$BR_PID')" >/dev/null
  SMOKE_E1="$(sed -n 's/^Cookie: tbox_session=//p' "$WORK/hdr/claude_employee01.cookie")" \
  SMOKE_E2="$(sed -n 's/^Cookie: tbox_session=//p' "$WORK/hdr/claude_employee02.cookie")" \
    python3 "$ROOT/tests/browser/qf4b1_heartbeat.py" "$SITE" "$UA" "$BR_PID" claude_employee02 > "$WORK/browser.txt" 2>&1
  sed 's/^/    /' "$WORK/browser.txt"
  expect "브라우저 실패 0 (페이지 $BR_PID)" "0" "$(sed -n 's/^SMOKE_FAIL=//p' "$WORK/browser.txt" | tail -1)"
fi

sub "P-4b ① 행 전수 — 전 사용자 × 전 페이지: 본문 뺀 행 = 본문 있는 행 − doc, 판정 동일 · ② 쿼리 계획에 doc 없음"
cat > "$WORK/rowmatrix.php" <<'PHP'
<?php
declare(strict_types=1);
// 임시 인스턴스의 저장소에서 부른다(자기 .env = 임시 DB). 읽기만 한다.
$app = $argv[1];
require $app . '/scripts/lib/cli_bootstrap.php';
require_once $app . '/src/community/routes.php';
$db = Db::instance();
$users = $db->run('SELECT id, username, role, team_id, is_active FROM public.users ORDER BY id')->fetchAll();
$pages = $db->run('SELECT public_id FROM community.pages ORDER BY id')->fetchAll();
$pages[] = ['public_id' => $argv[2]];                      // 없는 UUID 도 한 줄
$hb = static function (array $u, ?array $row): string {   // pages_heartbeat.php 의 판정 순서 그대로
    if ($row === null) { return '404'; }
    [$ok, $code] = CommunityAuthz::decidePageRead($u, $row);
    if (!$ok) { return $code === CommunityAuthz::CODE_NOT_FOUND ? '404' : '403'; }
    [$ch] = CommunityAuthz::decidePageChange($u, $row);
    return $ch ? '200' : '403';
};
$pairs = 0; $rowBad = 0; $decBad = 0; $docKey = 0; $dist = []; $samples = [];
foreach ($users as $r) {
    $u = ['id' => (int) $r['id'], 'username' => (string) $r['username'], 'role' => (string) $r['role'],
          'team_id' => $r['team_id'] === null ? null : (int) $r['team_id']];
    foreach ($pages as $p) {
        $pid  = (string) $p['public_id'];
        $full = community_page_row($pid, $u['id']);
        $lite = community_page_row_nodoc($pid, $u['id']);
        $pairs++;
        if (is_array($lite) && array_key_exists('doc', $lite)) { $docKey++; }
        $cmp = $full;
        if (is_array($cmp)) { unset($cmp['doc']); }
        if ($cmp !== $lite) { $rowBad++; if (count($samples) < 5) { $samples[] = 'row ' . $u['username'] . ' × ' . $pid; } }
        $a = $hb($u, $full); $b = $hb($u, $lite);
        $dist[$a] = ($dist[$a] ?? 0) + 1;
        if ($a !== $b) { $decBad++; if (count($samples) < 5) { $samples[] = 'dec ' . $u['username'] . ' × ' . $pid . " $a/$b"; } }
    }
}
ksort($dist);
echo "PAIRS=$pairs\nROW_MISMATCH=$rowBad\nDECISION_MISMATCH=$decBad\nDOC_KEY_IN_LITE=$docKey\n";
echo 'DIST=' . json_encode($dist) . "\n";
foreach ($samples as $s) { echo "SAMPLE=$s\n"; }

// ② 쿼리 계획 — 가장 큰 본문 페이지로, 판정용 변형과 원래 질의를 나란히 뜬다
$big = (string) $db->run('SELECT public_id FROM community.pages WHERE doc IS NOT NULL ORDER BY octet_length(doc::text) DESC LIMIT 1')->fetchColumn();
foreach (['lite' => false, 'full' => true] as $name => $withDoc) {
    $plan = $db->run('EXPLAIN (VERBOSE, COSTS OFF) ' . community_page_row_sql($withDoc), [':pid' => $big, ':viewer' => (int) $users[0]['id']])->fetchAll(PDO::FETCH_COLUMN);
    $txt = implode("\n", $plan);
    echo 'PLAN_' . strtoupper($name) . '_DOC=' . preg_match_all('/\bp\.doc\b/', $txt) . "\n";
    echo 'PLAN_' . strtoupper($name) . '_DOCVERSION=' . preg_match_all('/\bp\.doc_version\b/', $txt) . "\n";
}
// 한 행의 크기(JSON 바이트) — 가장 큰 본문 페이지
$f = community_page_row($big, (int) $users[0]['id']);
$l = community_page_row_nodoc($big, (int) $users[0]['id']);
echo 'BYTES_FULL=' . strlen((string) json_encode($f)) . "\nBYTES_LITE=" . strlen((string) json_encode($l)) . "\n";
// P-4b 만 따로 — 같은 연결·같은 페이지에서 판정용 행 한 건 읽기(ms, 300회 p50). 연결 비용(P-2)과 섞이지 않는다
foreach (['full' => 'community_page_row', 'lite' => 'community_page_row_nodoc'] as $name => $fn) {
    $t = [];
    for ($i = 0; $i < 300; $i++) { $a = hrtime(true); $fn($big, (int) $users[0]['id']); $t[] = (hrtime(true) - $a) / 1e6; }
    sort($t);
    printf("ROWMS_%s=%.3f\n", strtoupper($name), $t[150]);
}
PHP
( cd "$INST_NEW/app" && php "$WORK/rowmatrix.php" "$INST_NEW/app" "$MISSING_UUID" ) > "$WORK/rowmatrix.txt" 2>&1
sed 's/^/    /' "$WORK/rowmatrix.txt"
expect "행 전수 — 본문 뺀 행 = 원래 행 − doc (어긋난 쌍)" "ROW_MISMATCH=0" "$(grep '^ROW_MISMATCH=' "$WORK/rowmatrix.txt")"
expect "판정 전수 — heartbeat 판정(200/403/404) 어긋난 쌍" "DECISION_MISMATCH=0" "$(grep '^DECISION_MISMATCH=' "$WORK/rowmatrix.txt")"
expect "본문 뺀 행에 doc 키 0" "DOC_KEY_IN_LITE=0" "$(grep '^DOC_KEY_IN_LITE=' "$WORK/rowmatrix.txt")"
expect "쿼리 계획 — 판정용 변형에 p.doc 0 (p.doc_version 은 있다)" "0|1" \
  "$(sed -n 's/^PLAN_LITE_DOC=//p' "$WORK/rowmatrix.txt")|$([ "$(sed -n 's/^PLAN_LITE_DOCVERSION=//p' "$WORK/rowmatrix.txt")" -ge 1 ] && echo 1 || echo 0)"
expect "쿼리 계획 대조군 — 원래 질의에는 p.doc 이 있다" "yes" \
  "$([ "$(sed -n 's/^PLAN_FULL_DOC=//p' "$WORK/rowmatrix.txt")" -ge 1 ] && echo yes || echo no)"

sub "P-2 ② 트랜잭션 누수 — 트랜잭션을 연 채 끝나는 요청 뒤, 다음 요청은 새 트랜잭션이다"
PID0=$(curl -s -A "$UA" "$SITE/__qf4b/probe" | python3 -c 'import json,sys; print(json.load(sys.stdin)["pid"])')
PREP0=$(curl -s -A "$UA" "$SITE/__qf4b/probe" | python3 -c 'import json,sys; print(json.load(sys.stdin)["prepared"])')
note "시작 — 워커의 백엔드 pid $PID0 · 세션의 서버 prepare $PREP0(탐침 자신 포함)"
: > "$INST_NEW/leak.txt"
L0=$(wc -l < "$INST_NEW/server.log")
for m in api-exception raw-exception api-exit raw-exit api-fatal raw-fatal; do
  c=$(code_of "$SITE/__qf4b/$m")
  it=$(idle_tx)
  P=$(curl -s -A "$UA" -w ' %{http_code}' "$SITE/__qf4b/probe")
  pc=${P##* }; pj=${P% *}
  lp=$(awk -v m="$m" '$1==m {print $2}' "$INST_NEW/leak.txt" | tail -1)
  got=$(python3 -c 'import json,sys; d=json.loads(sys.argv[1]); print("%s|%s|%s|%s" % (sys.argv[2], "same" if str(d["pid"])==sys.argv[3] else "other", d["xid"], d["in_tx"]))' "$pj" "$pc" "$lp")
  expect "$m (응답 $c) → idle in transaction · 다음 요청(상태|같은 연결|물려받은 xid|트랜잭션 중)" "0|200|same|None|False" "$it|$got"
done
PREP1=$(curl -s -A "$UA" "$SITE/__qf4b/probe" | python3 -c 'import json,sys; print(json.load(sys.stdin)["prepared"])')
expect "세션의 서버 prepare 가 늘지 않는다 (트랜잭션 안 예외 두 번 뒤 — DEALLOCATE 가 거부된 문장도 넘겨받을 때 DISCARD ALL 이 지운다)" "$PREP0" "$PREP1"

sub "P-2 ② 세션 상태 — 앞 요청이 남긴 세션 설정·임시 표·세션 advisory lock 을 다음 요청이 물려받지 않는다 (시험 전용 갈래)"
S=$(curl -s -A "$UA" "$SITE/__qf4b/state")
expect "앞 요청이 남긴 상태 (application_name|임시 표|advisory lock)" "qf4b-leak|True|1" \
  "$(python3 -c 'import json,sys; d=json.loads(sys.argv[1]); print("%s|%s|%s" % (d["app_name"], d["temp_table"], d["adv_locks"]))' "$S")"
P=$(curl -s -A "$UA" "$SITE/__qf4b/probe")
expect "다음 요청 — 같은 연결 · 상태 없음 (같은 연결|application_name|임시 표|advisory lock)" "same||False|0" \
  "$(python3 -c 'import json,sys; a=json.loads(sys.argv[1]); b=json.loads(sys.argv[2]); print("%s|%s|%s|%s" % ("same" if a["pid"]==b["pid"] else "other", b["app_name"], b["temp_table"], b["adv_locks"]))' "$S" "$P")"
expect "그 연결의 advisory lock 이 DB 에도 없다 (pg_locks)" "0" "$(psqlq "SELECT count(*) FROM pg_locks l JOIN pg_stat_activity a ON a.pid = l.pid WHERE l.locktype = 'advisory' AND a.datname = '$DB'")"
tail -n +"$((L0 + 1))" "$INST_NEW/server.log" > "$WORK/leak.log"
expect "여섯 요청과 시작 탐침이 모두 같은 영속 연결에서 났다 (재사용이 시험됐다)" "1" "$( { awk '{print $2}' "$INST_NEW/leak.txt"; echo "$PID0"; } | sort -u | wc -l | tr -d ' ')"
expect "요청 끝 정리가 되돌린 줄 (exit 둘 · raw 예외 · 치명적 오류 둘 = 5)" "5" "$(grep -c 'rolled back at request end' "$WORK/leak.log" | tr -d ' ')"
expect "넘겨받을 때 남은 트랜잭션 0 (요청 끝 정리가 모두 처리)" "0" "$(grep -c 'reused connection had an open transaction' "$INST_NEW/server.log" | tr -d ' ')"
expect "누수 시험의 uncaught 는 일부러 낸 두 예외 · fatal 은 두 치명적 오류" "2|2" \
  "$(grep -c 'uncaught' "$WORK/leak.log" | tr -d ' ')|$(grep -c 'request_id=[^ ]* fatal:' "$WORK/leak.log" | tr -d ' ')"
expect "그 뒤 일반 창구 정상 (GET /tbox/api/me 200)" "200" "$(code_of -H @"$E1C" "$BASE/api/me")"

sub "P-2 ③ 코드 전체에 BEGIN·COMMIT·ROLLBACK 을 SQL 문자열로 보내는 곳 0 (Db::begin/commit/rollback 만)"
grep -rnE "(['\"]|<<<'?SQL'?)[[:space:]]*(BEGIN|COMMIT|ROLLBACK|START[[:space:]]+TRANSACTION|SAVEPOINT|RELEASE[[:space:]]+SAVEPOINT|END)[[:space:]]*(;|['\"]|$)" \
  "$ROOT/src" "$ROOT/public" "$ROOT/scripts" --include='*.php' > "$WORK/rawtx.txt" 2>/dev/null
note "grep 결과 $(wc -l < "$WORK/rawtx.txt" | tr -d ' ')줄"; sed 's/^/    /' "$WORK/rawtx.txt" | head -5
expect "SQL 문자열 트랜잭션 제어 0" "0" "$(wc -l < "$WORK/rawtx.txt" | tr -d ' ')"
expect "  (grep 자기 확인) 같은 식이 시험 전용 파일의 run('BEGIN') 은 잡는다" "yes" \
  "$(grep -qE "(['\"]|<<<'?SQL'?)[[:space:]]*(BEGIN|COMMIT)[[:space:]]*(;|['\"]|$)" "$INST_NEW/app/qf4b_probe.php" && echo yes || echo no)"
expect "PDO 트랜잭션 메서드(beginTransaction·rollBack)를 부르는 파일은 src/Db.php 뿐" "src/Db.php" \
  "$(grep -rlE --include='*.php' -e '->(beginTransaction|rollBack)\(' "$ROOT/src" "$ROOT/public" "$ROOT/scripts" | sed "s#^$ROOT/##" | sort -u | tr '\n' ' ' | sed 's/ $//')"
grep -rnE --include='*.php' -e "set_config\(|pg_(try_)?advisory_lock(_shared)?\(|CREATE[[:space:]]+(TEMP|TEMPORARY)[[:space:]]|SET[[:space:]]+(SESSION[[:space:]]|ROLE[[:space:]]+[a-z_\"]|LOCAL[[:space:]]|search_path)|LISTEN[[:space:]]|WITH[[:space:]]+HOLD|DISCARD[[:space:]]" \
  "$ROOT/src" "$ROOT/public" "$ROOT/scripts" | grep -v '^[^:]*:[0-9]*:[[:space:]]*\(\*\|//\)' | grep -v "/src/Db.php:.*DISCARD ALL" > "$WORK/sessstate.txt"
note "세션 상태 SQL grep $(wc -l < "$WORK/sessstate.txt" | tr -d ' ')줄"; sed 's/^/    /' "$WORK/sessstate.txt" | head -5
expect "세션에 상태를 남기는 SQL 0 (SET 세션·SET ROLE·set_config·세션 advisory lock·임시 표·LISTEN·WITH HOLD — xact 잠금 제외)" "0" "$(wc -l < "$WORK/sessstate.txt" | tr -d ' ')"

sub "P-2 ④ 끊긴 영속 연결 — 임시 DB 의 앱 연결만 끊은 뒤 다음 요청 (DB 재시작 흉내)"
T=$(psqlq "SELECT count(pg_terminate_backend(pid)) FROM pg_stat_activity WHERE datname = '$DB' AND usename = '$APP_DB_USER' AND pid <> pg_backend_pid()")
expect "끊은 연결 (임시 DB · 앱 계정만)" "1" "$T"
sleep 0.5
expect "끊긴 뒤 첫 요청 GET /tbox/api/me → 500 없이 200" "200" "$(code_of -H @"$E1C" "$BASE/api/me")"
PID1=$(curl -s -A "$UA" "$SITE/__qf4b/probe" | python3 -c 'import json,sys; print(json.load(sys.stdin)["pid"])')
expect "새 백엔드로 다시 붙었다 (pid 바뀜)" "yes" "$([ "$PID1" != "$PID0" ] && echo yes || echo no)"
T=$(psqlq "SELECT count(pg_terminate_backend(pid)) FROM pg_stat_activity WHERE datname = '$DB' AND usename = '$APP_DB_USER' AND pid <> pg_backend_pid()")
sleep 0.5
expect "한 번 더 끊고 첫 요청 GET /tbox/api/health → 200" "1|200" "$T|$(code_of "$BASE/api/health")"
expect "서버 로그 — 다시 붙은 줄 2" "2" "$(grep -c 'reused connection is not usable, reconnecting once' "$INST_NEW/server.log" | tr -d ' ')"

sub "P-2 ⑤ 권한 매트릭스 쓰기 연결(perm)은 비영속 · 앱 연결은 영속 (독립 검토 M-2, 2026-09-24)"
# CLI 로 후 인스턴스의 코드를 그대로 불러 두 연결의 ATTR_PERSISTENT 를 읽는다(값·비밀은 찍지 않는다).
PERSIST=$( cd "$INST_NEW/app" && php -r '
require "scripts/lib/cli_bootstrap.php";
$rp = new ReflectionProperty(Db::class, "pdo"); $rp->setAccessible(true);
Db::ready();
$app = $rp->getValue(Db::instance());
$perm = $rp->getValue(Db::perm());
echo "perm=", var_export((bool) $perm->getAttribute(PDO::ATTR_PERSISTENT), true),
     " app=", var_export((bool) $app->getAttribute(PDO::ATTR_PERSISTENT), true);
' 2>&1 | tail -1 )
expect "perm 연결 비영속 · 앱 연결 영속" "perm=false app=true" "$PERSIST"
PERM_DB_USER=$(sed -n 's/^DB_PERM_USER=//p' "$INST_NEW/app/.env")
sleep 0.3
expect "CLI 가 끝난 뒤 임시 DB 에 perm 계정 연결 0" "0" \
  "$(psqlq "SELECT count(*) FROM pg_stat_activity WHERE datname = '$DB' AND usename = '$PERM_DB_USER'")"

sub "후 인스턴스 로그 — 뜻하지 않은 오류 0"
expect "uncaught 는 누수 시험이 일부러 낸 두 예외뿐" "2" "$(grep -c 'uncaught' "$INST_NEW/server.log" | tr -d ' ')"
expect "fatal 은 누수 시험이 일부러 낸 두 치명적 오류뿐" "2" "$(grep -c 'request_id=[^ ]* fatal:' "$INST_NEW/server.log" | tr -d ' ')"
expect "db connect failed 0 (DB 가 있을 때)" "0" "$(grep -c 'db connect failed' "$INST_NEW/server.log" | tr -d ' ')"
stop_server

# ════════════════════════════════════════════════════════════════
hr "P-5c ③⑤ DB 가 없을 때 — .env 의 DB_NAME 을 없는 이름($NODB)으로 바꾼 두 번째 실행 (전 · 후)"
for side in old new; do
  if [ "$side" = old ]; then INST="$INST_OLD"; else INST="$INST_NEW"; fi
  cp -p "$INST/app/.env.nodb" "$INST/app/.env"
  : > "$INST/server.log"
  start_server "$INST"
  cnt0=$(grep -c 'db connect failed' "$INST/server.log" | tr -d ' ')
  r_root=$(code_of "$SITE/")
  code_of "$SITE/" >/dev/null; code_of "$SITE/api/health" >/dev/null; code_of "$SITE/nope-qf4b" >/dev/null
  cnt_static=$(( $(grep -c 'db connect failed' "$INST/server.log" | tr -d ' ') - cnt0 ))
  curl -s -A "$UA" -o "$WORK/root_nodb_$side.html" "$SITE/"
  r_health=$(curl -s -A "$UA" -o "$WORK/health_nodb_$side.json" -D "$WORK/health_nodb_$side.head" -w '%{http_code}' "$BASE/api/health")
  line="/=$r_root /(쿠키)=$(code_of -H @"$E1C" "$SITE/") health=$r_health /api/health=$(code_of "$SITE/api/health") 404=$(code_of "$SITE/nope-qf4b")"
  line="$line login=$(code_of "$BASE/login") time=$(code_of "$BASE/api/time") me(쿠키)=$(code_of -H @"$E1C" "$BASE/api/me") me(없음)=$(code_of "$BASE/api/me")"
  echo "$line" > "$WORK/nodb_$side.txt"
  note "$side: $line · 정적 요청(/·308·404) 동안 연결 시도 로그 +$cnt_static"
  echo "$cnt_static" > "$WORK/nodb_static_$side.txt"
  stop_server
  cp -p "$INST/app/.env.db" "$INST/app/.env"
done
expect "DB 없음 — 창구별 상태가 전후 같다" "$(cat "$WORK/nodb_old.txt")" "$(cat "$WORK/nodb_new.txt")"
expect "⑤ 쿠키 없는 런처는 500 이 아니라 정적 화면 (200 · 제목 Qaredi · 타일 둘)" "200|1|1|1" \
  "$(sed -n 's/^\/=\([0-9]*\) .*/\1/p' "$WORK/nodb_new.txt")|$(grep -c '<h1 class="page-head__title">Qaredi</h1>' "$WORK/root_nodb_new.html" | tr -d ' ')|$(grep -c 'href="/tbox/"' "$WORK/root_nodb_new.html" | tr -d ' ')|$(grep -c 'href="/community/"' "$WORK/root_nodb_new.html" | tr -d ' ')"
expect "  쿠키 없는 런처 HTML 이 DB 있을 때와 같다" "$(html_fp "$WORK/root.html")" "$(html_fp "$WORK/root_nodb_new.html")"
expect "  / · 옛 주소 308 · 404 는 연결을 시도하지도 않는다 (후 — 연결 실패 로그 +0, 전은 +$(cat "$WORK/nodb_static_old.txt"))" "0" "$(cat "$WORK/nodb_static_new.txt")"
expect "③ /tbox/api/health → 503 connect_failed (본문 글자 그대로)" '503|{"status":"fail","db":"connect_failed"}' \
  "$(sed -n 's/.* health=\([0-9]*\) .*/\1/p' "$WORK/nodb_new.txt")|$(cat "$WORK/health_nodb_new.json")"
cat "$WORK/health_nodb_new.json" "$WORK/health_nodb_new.head" > "$WORK/health_nodb_new.all"
expect "  503 응답에 DB 이름·계정·SQLSTATE 0" "0" "$(grep -ciE "$NODB|$APP_DB_USER|SQLSTATE|PDOException|port=" "$WORK/health_nodb_new.all" | tr -d ' ')"
expect "  서버 로그에 db connect failed · health: connect_failed" "yes|yes" \
  "$(grep -q 'db connect failed' "$INST_NEW/server.log" && echo yes || echo no)|$(grep -q 'health: connect_failed' "$INST_NEW/server.log" && echo yes || echo no)"

# ════════════════════════════════════════════════════════════════
hr "전후 측정표 (워커 1 = php -S 한 프로세스 · 같은 기계 · 같은 임시 DB)"
printf '  %-46s %-26s %-26s\n' "항목" "전 ($BEFORE_REF)" "후 (작업 트리)"
printf '  %-46s %-26s %-26s\n' "쿠키 없는 GET / ${N_ROOT}회 → DB 세션 증가" "+$OLD_ROOT_DELTA" "+0"
printf '  %-46s %-26s %-26s\n' "GET /tbox/api/health ${N_REQ}회 → DB 세션 증가" "+$OLD_HEALTH_DELTA" "+$NEW_HEALTH_DELTA"
printf '  %-46s %-26s %-26s\n' "GET /tbox/api/me ${N_REQ}회 → DB 세션 증가" "+$OLD_ME_DELTA" "+$NEW_ME_DELTA"
printf '  %-46s %-26s %-26s\n' "GET /tbox/api/health p50 ms (3회)" "$OLD_P50_HEALTH" "$NEW_P50_HEALTH"
printf '  %-46s %-26s %-26s\n' "GET / (쿠키 없음) p50 ms (3회)" "$OLD_P50_ROOT" "$NEW_P50_ROOT"
printf '  %-46s %-26s %-26s\n' "GET /tbox/api/me p50 ms (3회)" "$OLD_P50_ME" "$NEW_P50_ME"
[ -n "$HB_ACCT" ] && printf '  %-46s %-26s %-26s\n' "heartbeat p50 ms (본문 ${HB_DOC}B, 200, 3회)" "$OLD_P50_HB" "$NEW_P50_HB"
printf '  %-46s %-26s %-26s\n' "heartbeat p50 ms (가장 큰 본문 ${BIG_DOC}B, $BIG_CODE, 3회)" "$OLD_P50_BIG" "$NEW_P50_BIG"
printf '  %-46s %-26s %-26s\n' "판정 행 JSON 바이트 (가장 큰 본문 페이지)" "$(sed -n 's/^BYTES_FULL=//p' "$WORK/rowmatrix.txt")" "$(sed -n 's/^BYTES_LITE=//p' "$WORK/rowmatrix.txt")"
printf '  %-46s %-26s %-26s\n' "판정 행 한 건 읽기 ms p50 (같은 연결, 300회)" "$(sed -n 's/^ROWMS_FULL=//p' "$WORK/rowmatrix.txt") (본문 포함)" "$(sed -n 's/^ROWMS_LITE=//p' "$WORK/rowmatrix.txt") (본문 뺌)"
note "p50 중앙값 — health $(median3 "$OLD_P50_HEALTH") → $(median3 "$NEW_P50_HEALTH") · / $(median3 "$OLD_P50_ROOT") → $(median3 "$NEW_P50_ROOT") · me $(median3 "$OLD_P50_ME") → $(median3 "$NEW_P50_ME")"
note "미검증: 실제 PostgreSQL 재시작(pg_ctl restart) — 임시 DB 가 운영과 같은 클러스터라 하지 않는다. 대신 ④ 는 임시 DB 의 앱 연결만 끊어서 봤다."

hr "결과"
printf '통과 %d · 실패 %d · 건너뜀 %d\n' "$PASS" "$FAIL" "$SKIP"
note "임시 DB($DB)·임시 폴더·임시 서버는 이 스크립트가 끝나며 지워진다. 운영에는 아무것도 남지 않는다."
[ "$FAIL" -eq 0 ] || exit 1
exit 0
