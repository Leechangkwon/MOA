#!/usr/bin/env bash
# tests/community/stale_modules.sh — 「옛 부품 캐시」 헤드리스 회귀 (2026-09-24 핫픽스)
#
# 사고: `/tbox/assets/` 는 7일 캐시인데 셸은 진입 파일 editor.js 만 버전을 달았다. 7-C 부터 editor.js 가
#       doc-render.js 의 새 export(embedHost)를 부르자, 옛 doc-render.js 를 캐시한 브라우저에서 모듈 연결이
#       실패해 커뮤니티 JS 가 0 실행 — 새 페이지·휴지통 단추가 전부 먹통(사용자가 폰에서 겪음).
# 고침: 부품 import 마다 `?v=<버전>` (scripts/community_asset_version.php · 정적 시험 asset_version.sh).
#
# 이 시험: 임시 인스턴스에서 브라우저가 v2.18.0 부품을 캐시에 든 상태를 route 로 흉내 내고
#   A 고친 코드는 단추가 돈다 · B 대조군(옛 부품을 그대로 쓰면)은 먹통이 재현된다 — browser_stale_modules.py
#
# ★ 운영 DB 를 건드리지 않는다 — stage7d1.sh 와 같은 틀(임시 DB `tbox_stalejs_test`, 포트 8096) · 검토 계정 claude_employee01(F-71).
# ★ 비밀(비밀번호·OTP 비밀)은 명령 인자에 싣지 않는다 — 로그인은 파이썬 한 프로세스가 환경 변수로 받아 처리한다.
# 나가는 값: 0 통과 · 1 실패 · 3 준비물 없음 · 4 인스턴스 실패
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
PORT=8096
SITE="http://127.0.0.1:$PORT"
BASE="$SITE/tbox"
CBASE="$SITE/community"
UA="tbox-review/$(date +%F)"
SRC_DB="tbox_re"            # 운영 — **읽기만** 한다
DB="tbox_stalejs_test"      # 임시 — 여기에만 쓴다
WHO="claude_employee01"
OLD_TAG="${OLD_TAG:-v2.18.0}"   # 사용자가 겪은 캐시 — 7-C(embedHost) 직전 발행
WORK="$(mktemp -d)"
INST=""
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

cleanup() {
  if [ -n "$SERVER_PID" ]; then kill "$SERVER_PID" 2>/dev/null; wait "$SERVER_PID" 2>/dev/null; fi
  sudo -n -u postgres dropdb --if-exists "$DB" >/dev/null 2>&1
  [ -n "$INST" ] && rm -rf "$INST"
  rm -rf "$WORK"
}
trap cleanup EXIT

psqlq() { sudo -n -u postgres psql -d "$DB" -t -A -c "$1" 2>/dev/null; }

# ════════════════════════════════════════════════════════════════
hr "옛 부품 캐시 — 준비 (운영 DB 에는 쓰지 않는다)"

python3 -c 'import playwright' 2>/dev/null || { export PATH="$HOME/.local/bin:$PATH"; }
python3 -c 'import playwright' 2>/dev/null || { echo "playwright 가 없다 — 이 시험은 브라우저가 없으면 성립하지 않는다"; exit 3; }

sub "옛 부품($OLD_TAG) 과 지금 부품 버전"
mkdir -p "$WORK/old"
for f in doc-render.js database.js templates.js editor-schema.js; do
  git -C "$ROOT" show "$OLD_TAG:public/assets/community/$f" > "$WORK/old/$f" 2>/dev/null \
    || { echo "  ✖ $OLD_TAG 의 $f 를 꺼내지 못했다(태그가 없는 클론?)"; exit 3; }
done
expect "옛 doc-render.js 에는 embedHost 가 없다(사고 조건)" "0" "$(grep -c 'embedHost' "$WORK/old/doc-render.js")"
expect "지금 editor.js 는 embedHost 를 부른다" "1" "$(grep -c "import { renderDoc, embedHost } from './doc-render.js" "$ROOT/public/assets/community/editor.js")"
APP_VER=$(php "$ROOT/scripts/community_asset_version.php" --print | sed -n 's/^app=//p')
expect "버전 표시가 지금 내용과 맞다(--check)" "0" "$(php "$ROOT/scripts/community_asset_version.php" --check > /dev/null 2>&1; echo $?)"
note "부품 버전 $APP_VER"

avail_kb=$(df -Pk / | awk 'NR==2{print $4}')
[ "$avail_kb" -ge 1048576 ] || { echo "루트 디스크 여유 1GB 미만 — 시험을 시작하지 않는다 (SECURITY R-80)"; exit 3; }
sudo -n -u postgres psql -d "$SRC_DB" -tAc "SELECT 1" >/dev/null 2>&1 \
  || { echo "psql(postgres) 을 쓸 수 없다 — 이 시험은 임시 DB 가 없으면 성립하지 않는다"; exit 3; }

sub "운영 DB 를 **읽어** 임시 DB 를 만든다"
sudo -n -u postgres dropdb --if-exists "$DB" >/dev/null 2>&1
sudo -n -u postgres createdb -O tbox_owner "$DB" >/dev/null 2>&1 || { echo "임시 DB 를 만들 수 없다"; exit 3; }
# 평문 덤프를 디스크(/tmp)에 두지 않는다 — 파이프로 곧바로 임시 DB 에(scripts/backup_db.sh 원칙 · 독립 검토 Q:F-4 A M-1)
set -o pipefail
sudo -n -u postgres pg_dump -d "$SRC_DB" 2>/dev/null \
  | sudo -n -u postgres psql -q -d "$DB" -v ON_ERROR_STOP=0 >/dev/null 2>"$WORK/restore.err" \
  || { set +o pipefail; echo "운영 DB 를 읽어 임시 DB 로 옮기지 못했다 (pg_dump | psql)"; exit 3; }
set +o pipefail
sudo -n -u postgres psql -q -d "$DB" -v ON_ERROR_STOP=1 -f - < "$ROOT/db/community/grants.sql" 2>&1 \
  | grep -v 'could not change directory' | sed 's/^/    /'
n_pages=$(psqlq "SELECT count(*) FROM community.pages")
[ -n "$n_pages" ] || { echo "임시 DB 로 옮기지 못했다 — $(head -2 "$WORK/restore.err")"; exit 3; }
note "임시 DB $DB — 페이지 ${n_pages}장"

sub "저장소를 임시 폴더로 복사하고 두 번째 인스턴스를 띄운다"
INST="$(mktemp -d)"
mkdir -p "$INST/app" "$INST/storage" "$INST/log"
tar -C "$ROOT" -cf - --exclude=.git --exclude=jar --exclude=api-test.txt --exclude=./.env . | tar -C "$INST/app" -xf -
sed -e "s#^DB_NAME=.*#DB_NAME=$DB#" \
    -e "s#^STORAGE_DIR=.*#STORAGE_DIR=$INST/storage#" \
    -e "s#^AUDIT_LOG_FILE=.*#AUDIT_LOG_FILE=$INST/log/audit.log#" \
    "$ROOT/.env" > "$INST/app/.env"
chmod 600 "$INST/app/.env"
grep -q "^DB_NAME=$DB\$" "$INST/app/.env" || { echo "임시 .env 에 임시 DB 이름이 들어가지 않았다 — 중단"; exit 4; }
PLAN_DIR="$(cd "$ROOT/.." && pwd)/community"
[ -f "$PLAN_DIR/doc-schema.json" ] || PLAN_DIR=/var/www/qaredi/community
ln -s "$PLAN_DIR" "$INST/community" 2>/dev/null
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
            $types = ['js' => 'text/javascript', 'css' => 'text/css', 'json' => 'application/json', 'svg' => 'image/svg+xml', 'png' => 'image/png'];
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
if ss -ltn 2>/dev/null | grep -q "127.0.0.1:$PORT "; then
  echo "포트 $PORT 이 이미 쓰이고 있다 — 앞선 시험이 남긴 서버를 먼저 내린다"; exit 3
fi
( cd "$INST/app" && exec php -S "127.0.0.1:$PORT" -t public router.php > "$INST/server.log" 2>&1 ) &
SERVER_PID=$!
for _ in 1 2 3 4 5 6 7 8 9 10; do
  sleep 1
  READY=$(curl -s -o /dev/null -w '%{http_code}' -A "$UA" "$CBASE/api/pages")
  [ "$READY" = "401" ] && break
done
expect "임시 인스턴스가 떴다 (미로그인 커뮤니티 창구 401)" "401" "$READY"
[ "$READY" = "401" ] || { echo "인스턴스를 띄우지 못했다 — 중단"; tail -5 "$INST/server.log"; exit 4; }

sub "로그인 ($WHO — 비밀은 환경 변수로만)"
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
        resp = urllib.request.urlopen(req, timeout=10)
        data = json.loads(resp.read().decode())
        for h in resp.headers.get_all("Set-Cookie") or []:
            m = re.match(r"tbox_session=([^;]+)", h)
            if m:
                cookie = m.group(1)
    except Exception:
        pass
    if data.get("ok") is True and cookie:
        raw = urllib.request.urlopen(urllib.request.Request(base + "/api/me", headers={"User-Agent": ua, "Cookie": "tbox_session=" + cookie}), timeout=10).read().decode()
        m = re.search(r'"csrf_token":"([0-9a-f]+)"', raw)
        print("SESSION=" + cookie)
        print("CSRF=" + (m.group(1) if m else ""))
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
[ -n "$SESSION" ] || { echo "  ✖ $WHO 로그인 실패 — 중단"; exit 4; }
expect "로그인 세션 · CSRF 토큰을 받았다" "yes" "$([ -n "$SESSION" ] && [ -n "$CSRF" ] && echo yes || echo no)"
# 세션·CSRF 는 curl 인자에 싣지 않는다 — 600 헤더 파일로만(`-H @파일`, 독립 검토 L-5)
( umask 077; printf 'Cookie: tbox_session=%s\n' "$SESSION" > "$WORK/cookie.hdr"
  printf 'Cookie: tbox_session=%s\nX-CSRF-Token: %s\n' "$SESSION" "$CSRF" > "$WORK/write.hdr" )
curl -s -o /dev/null -A "$UA" -H @"$WORK/cookie.hdr" "$CBASE/api/me/space"

# ════════════════════════════════════════════════════════════════
hr "브라우저 — browser_stale_modules.py (A 고친 코드 · B 대조군)"
SMOKE_E1="$SESSION" OLD_DIR="$WORK/old" APP_VER="$APP_VER" \
  python3 "$ROOT/tests/community/browser_stale_modules.py" "$SITE" "$UA" > "$WORK/smoke.txt" 2>&1
sed 's/^/    /' "$WORK/smoke.txt"
expect "브라우저 실패 0" "0" "$(sed -n 's/^SMOKE_FAIL=//p' "$WORK/smoke.txt" | tail -1)"

sub "만든 페이지는 앱 창구로 지운다"
for id in $(sed -n 's/^CREATED=//p' "$WORK/smoke.txt"); do
  code=$(curl -s -o /dev/null -w '%{http_code}' -A "$UA" -X DELETE \
         -H 'Expect:' -H @"$WORK/write.hdr" "$CBASE/api/pages/$id")
  expect "DELETE /community/api/pages/$id" "yes" "$(case "$code" in 200|204) echo yes;; *) echo "no($code)";; esac)"
done
expect "인스턴스 로그에 uncaught 0" "0" "$(grep -ci 'uncaught' "$INST/server.log" 2>/dev/null | tr -d ' ' || echo 0)"

hr "결과"
printf '통과 %d · 실패 %d · 건너뜀 %d\n' "$PASS" "$FAIL" "$SKIP"
note "임시 DB($DB)·임시 폴더·임시 서버는 이 스크립트가 끝나며 지워진다. 운영에는 아무것도 남지 않는다."
[ "$FAIL" -eq 0 ] || exit 1
exit 0
