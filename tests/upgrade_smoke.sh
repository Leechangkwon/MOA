#!/usr/bin/env bash
# tests/upgrade_smoke.sh — 업그레이드 스모크 (점검 봇 · PRD 13.7 항목 4 의 동적 짝 · docs/checkbot/upgrade-smoke-design.md A 방식)
#
# 묻는 것: 직전 발행 N-1 을 캐시한 브라우저로 새 판 N 의 핵심 화면을 열면 단추가 도는가.
#   UR-0001(7-C v2.19.0) 은 옛 부품을 캐시한 폰에서 커뮤니티 JS 가 통째로 멈춘 사고다. 다른 브라우저 시험은
#   전부 캐시 없는 새 문맥이라 이 길이 원리상 비어 있었다. 정적 짝은 R17(버전 없는 상대 import × 긴 캐시).
#
# 어떻게(route 흉내 0 — 진짜 디스크 캐시):
#   ① 같은 포트에 N-1 트리를 띄우고 프로필 브라우저(launch_persistent_context)로 동선 화면을 돌아 캐시를 채운다
#   ② 서버만 내리고 **같은 출처·포트**에 N 트리를 띄운다
#   ③ 같은 프로필로 동선을 다시 연다 — upgrade 판정 · ④ 새 문맥으로 같은 동선 — fresh 판정(대조)
#   임시 서버 라우터는 운영 nginx(deploy/sites/tbox-v2.conf)의 `/tbox/assets/` 캐시 머리(expires ·
#   Last-Modified · ETag)와 `/assets/` 308 을 흉내 내고, 받은 자산 주소를 기록한다. ③ 에서 브라우저가 받았는데
#   서버 기록에 없는 자산 = 캐시가 답한 부품(CACHED 줄) — 실패의 원인 부품을 가리킨다.
#
# 입력(환경 변수)
#   N_REF    N 으로 쓸 커밋·태그. 비우면 지금 작업 트리(발행 직전 확인용 — 기본)
#   OLD_TAG  N-1. 비우면 N 에서 닿는 가장 최근 v* 태그 — 그 태그가 곧 N 커밋이면 그 앞 태그(설계 4장 · D-3 N-1 만)
#   FLOWS    동선 목록(기본 tests/upgrade/flows.json — D-4 화면을 만드는 단계가 한 줄씩)
#   PORT     기본 8104
# 결과(1-C 형식): 흐름마다 `RESULT_ITEM flow=<id> fresh=pass|fail upgrade=pass|fail reason=…` · 끝 줄 `RESULT pass= fail= skip=`
#   upgrade=fail · fresh=pass 가 이 시험의 본래 대상(배포 사이 호환성) → 실패로 센다.
#   fresh=fail 은 코드 결함이거나 준비가 틀린 것(세션 무효·스키마 어긋남)이라 upgrade 판정을 믿을 수 없다 →
#   RESULT_ITEM 에서는 skip 으로 세되 **시험 전체는 실패**로 끝낸다(모두 skip 인데 통과로 끝나는 헛통과를 막는다 · 독립 검토 H1).
# 캐시가 실제로 쓰였다는 양성 대조(검토 H2): upgrade 단계에서 서버를 거치지 않고 캐시가 답한 자산이 1건 이상이어야 한다.
#   fill 단계는 흐름마다 자산을 받았고 로그인 화면·오류 화면이 아니어야 한다(검토 M1).
# 판정은 사고와 같은 모양만(설계 7장): 모듈 연결 오류 · 기대한 요청 0. 그 밖의 오류는 WARN 으로만.
# D-2: 처음 두 단계는 경고만(catalog status=advisory) — 이 스크립트는 실패면 1 을 돌려주고, 막을지는 부르는 쪽이 정한다.
#
# ★ 운영 DB 를 건드리지 않는다 — stale_modules.sh 와 같은 틀: 운영 DB(tbox_re)는 pg_dump 로 **읽기만**(파이프 — 평문
#   덤프 파일 0), 임시 DB `tbox_upgrade_test_<PORT>` · 임시 폴더. 운영 8082 요청 0. 검토 계정 claude_employee01(F-71).
#   임시 DB 는 운영 스키마 하나로 두 판을 모두 돌린다(마이그레이션 기록 표가 없어 「N-1 까지 → N」 을 가를 수 없다).
#   N-1 뒤 새 SQL 파일은 이름만 알리고 계속한다 — 운영에 아직 없는 스키마가 N 에 필요하면 fresh 대조가 실패해
#   시험 전체가 실패로 끝난다(위 H1 규칙). 설계 4장의 「N-1 마이그레이션 → N 마이그레이션」 과 다른 점이다.
# ★ 캐시 흉내의 두 값은 운영에서 읽는다(검토 M3·M4):
#   - 자산 max-age 는 deploy/sites/tbox-v2.conf 의 `location /tbox/assets/` 블록 `expires` 에서 계산한다
#   - 셸은 app.js·app.css·editor.js 에 `?v=<파일 시각>` 을 붙인다. git archive 는 모든 파일에 커밋 시각을 찍으므로
#     그대로 두면 이 셋은 두 판 사이에서 늘 주소가 달라 캐시 대상에서 빠진다. 운영에서는 바뀌지 않은 파일의 시각이
#     그대로이므로, N-1 트리에서 N 과 내용이 같은 자산은 N 의 파일 시각으로 맞춘다.
# ★ 비밀(비밀번호·OTP 비밀·세션 쿠키)은 명령 인자·환경 변수에 싣지 않는다 — tests/lib/secrets.sh 의 py_secret(표준입력).
# 정리: trap EXIT 가 서버·임시 DB(이 실행이 만든 것만)·임시 폴더를 지운다. kill -9 로 끊기면 0700 임시 폴더(브라우저 프로필 ·
#   세션 헤더 파일)와 임시 DB 가 남는다 — 같은 PORT 의 다음 실행이 dropdb 로, 임시 폴더는 /tmp 정리가 치운다(검토 L5).
# 나가는 값: 0 통과 · 1 실패 · 3 준비물 없음 · 4 인스턴스 실패
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
. "$ROOT/tests/lib/review_accounts.sh" || { echo "tests/lib/review_accounts.sh 를 읽지 못했다 — 시작하지 않는다"; exit 3; }
. "$ROOT/tests/lib/secrets.sh" || { echo "tests/lib/secrets.sh 를 읽지 못했다 — 시작하지 않는다"; exit 3; }
PORT="${PORT:-8104}"
SITE="http://127.0.0.1:$PORT"
BASE="$SITE/tbox"
CBASE="$SITE/community"
UA="tbox-review/$(date +%F)"
SRC_DB="tbox_re"            # 운영 — **읽기만** 한다
DB="tbox_upgrade_test_$PORT"   # 임시 — 여기에만 쓴다. PORT 로 갈라 나란히 돌아도 남의 DB 를 지우지 않는다(검토 H3)
WHO="claude_employee01"
FLOWS="${FLOWS:-$ROOT/tests/upgrade/flows.json}"
N_REF="${N_REF:-}"
OLD_TAG="${OLD_TAG:-}"
WORK="$(mktemp -d)"
chmod 700 "$WORK"
SERVER_PID=""
DB_CREATED=0
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

stop_server() {
  if [ -n "$SERVER_PID" ]; then kill "$SERVER_PID" 2>/dev/null; wait "$SERVER_PID" 2>/dev/null; SERVER_PID=""; fi
}
cleanup() {
  stop_server
  [ "$DB_CREATED" = 1 ] && sudo -n -u postgres dropdb --if-exists "$DB" >/dev/null 2>&1
  rm -rf "$WORK"
}
trap cleanup EXIT

psqlq() { sudo -n -u postgres psql -d "$DB" -t -A -c "$1" 2>/dev/null; }

# ════════════════════════════════════════════════════════════════
hr "업그레이드 스모크 — 준비 (운영 DB 에는 쓰지 않는다)"

python3 -c 'import playwright' 2>/dev/null || { export PATH="$HOME/.local/bin:$PATH"; }
python3 -c 'import playwright' 2>/dev/null || { echo "playwright 가 없다 — 이 시험은 브라우저가 없으면 성립하지 않는다"; exit 3; }
[ -f "$FLOWS" ] || { echo "동선 목록이 없다: $FLOWS"; exit 3; }
python3 -c 'import json,sys; f=json.load(open(sys.argv[1]))["flows"]; assert f and all("id" in x and "url" in x for x in f)' "$FLOWS" 2>/dev/null \
  || { echo "동선 목록 모양이 틀렸다(flows[].id·url): $FLOWS"; exit 3; }

sub "N 과 N-1"
if [ -n "$N_REF" ]; then
  N_COMMIT=$(git -C "$ROOT" rev-parse --verify -q "$N_REF^{commit}") || { echo "N_REF=$N_REF 를 찾을 수 없다"; exit 3; }
  N_LABEL="$N_REF($(git -C "$ROOT" log -1 --format=%h "$N_COMMIT"))"
  N_EXACT=1
else
  N_COMMIT=$(git -C "$ROOT" rev-parse HEAD)
  N_LABEL="작업 트리(HEAD $(git -C "$ROOT" log -1 --format=%h HEAD))"
  # 커밋 안 된 변경이 있으면 작업 트리는 HEAD 와 다른 판이다
  if [ -z "$(git -C "$ROOT" status --porcelain --untracked-files=no)" ]; then N_EXACT=1; else N_EXACT=0; fi
fi
if [ -z "$OLD_TAG" ]; then
  OLD_TAG=$(git -C "$ROOT" describe --tags --abbrev=0 --match 'v*' "$N_COMMIT" 2>/dev/null)
  if [ -n "$OLD_TAG" ] && [ "$N_EXACT" = 1 ] && [ "$(git -C "$ROOT" rev-parse "$OLD_TAG^{commit}")" = "$N_COMMIT" ]; then
    OLD_TAG=$(git -C "$ROOT" describe --tags --abbrev=0 --match 'v*' "$OLD_TAG^" 2>/dev/null)
  fi
fi
[ -n "$OLD_TAG" ] && git -C "$ROOT" rev-parse --verify -q "$OLD_TAG^{commit}" >/dev/null \
  || { echo "N-1 태그를 찾을 수 없다(OLD_TAG=${OLD_TAG:-없음} · 얕은 클론?)"; exit 3; }
note "N = $N_LABEL · N-1 = $OLD_TAG($(git -C "$ROOT" log -1 --format=%h "$OLD_TAG"))"
# 모서리 알림(검토 L2): 같은 커밋에 태그가 둘이거나 가지에서 찍힌 태그는 describe 가 건너뛸 수 있다
TOP_MERGED=$(git -C "$ROOT" tag --merged "$N_COMMIT" --sort=-v:refname -l 'v*' 2>/dev/null | head -1)
[ -n "$TOP_MERGED" ] && [ "$TOP_MERGED" != "$OLD_TAG" ] && [ "$(git -C "$ROOT" rev-parse "$TOP_MERGED^{commit}")" != "$N_COMMIT" ] \
  && note "⚠ N 에 합쳐진 가장 높은 태그는 $TOP_MERGED — N-1 이 맞는지 확인(OLD_TAG 로 지정 가능)"
VER_FILE=$( [ -n "$N_REF" ] && git -C "$ROOT" show "$N_COMMIT:VERSION" 2>/dev/null || cat "$ROOT/VERSION" 2>/dev/null )
[ -n "$VER_FILE" ] && note "N 의 VERSION = $VER_FILE"

# N-1 뒤 새 마이그레이션 — 알리기만 한다(위 ★ · 검토 M2)
NEW_MIG=$(git -C "$ROOT" diff --name-only --diff-filter=A "$OLD_TAG" "$N_COMMIT" -- 'db/*.sql' 'db/**/*.sql' 2>/dev/null | grep -v 'grants\.sql$' || true)
[ -n "$NEW_MIG" ] && note "N-1 뒤 새 SQL 파일: $(echo $NEW_MIG) — 임시 DB(운영 스키마)에 이미 있는 것으로 보고 계속한다. 없으면 fresh 대조가 실패한다"

# 운영 nginx 의 자산 캐시 기간(검토 M4) — `location /tbox/assets/ {` 블록의 `expires <n><s|m|h|d>`
MAXAGE=$(awk '/location \/tbox\/assets\/ \{/{b=1} b && /expires/{gsub(/;/,"",$2); print $2; exit} b && /^ *\}/{b=0}' "$ROOT/deploy/sites/tbox-v2.conf" 2>/dev/null \
  | awk '{n=$0+0; u=substr($0,length($0)); m=(u=="d")?86400:(u=="h")?3600:(u=="m")?60:1; print n*m}')
[ -n "$MAXAGE" ] && [ "$MAXAGE" -gt 0 ] 2>/dev/null || { echo "deploy/sites/tbox-v2.conf 에서 자산 expires 를 읽지 못했다"; exit 3; }
note "운영 자산 캐시 max-age=$MAXAGE (deploy/sites/tbox-v2.conf)"

avail_kb=$(df -Pk / | awk 'NR==2{print $4}')
[ "$avail_kb" -ge 1048576 ] || { echo "루트 디스크 여유 1GB 미만 — 시험을 시작하지 않는다 (SECURITY R-80)"; exit 3; }
sudo -n -u postgres psql -d "$SRC_DB" -tAc "SELECT 1" >/dev/null 2>&1 \
  || { echo "psql(postgres) 을 쓸 수 없다 — 이 시험은 임시 DB 가 없으면 성립하지 않는다"; exit 3; }
if ss -ltn 2>/dev/null | grep -q "127.0.0.1:$PORT "; then
  echo "포트 $PORT 이 이미 쓰이고 있다 — 앞선 시험이 남긴 서버를 먼저 내린다"; exit 3
fi

sub "두 트리를 임시 폴더에 (작업 트리는 건드리지 않는다)"
mkdir -p "$WORK/old/app" "$WORK/new/app" "$WORK/storage" "$WORK/log" "$WORK/profile"
git -C "$ROOT" archive "$OLD_TAG" | tar -C "$WORK/old/app" -xf - || { echo "N-1 트리를 꺼내지 못했다"; exit 3; }
if [ -n "$N_REF" ]; then
  git -C "$ROOT" archive "$N_COMMIT" | tar -C "$WORK/new/app" -xf - || { echo "N 트리를 꺼내지 못했다"; exit 3; }
else
  tar -C "$ROOT" -cf - --exclude=.git --exclude=jar --exclude=api-test.txt --exclude=./.env . | tar -C "$WORK/new/app" -xf -
fi
PLAN_DIR="$(cd "$ROOT/.." && pwd)/community"
[ -f "$PLAN_DIR/doc-schema.json" ] || PLAN_DIR=/var/www/qaredi/community
for side in old new; do
  sed -e "s#^DB_NAME=.*#DB_NAME=$DB#" \
      -e "s#^STORAGE_DIR=.*#STORAGE_DIR=$WORK/storage#" \
      -e "s#^AUDIT_LOG_FILE=.*#AUDIT_LOG_FILE=$WORK/log/audit.log#" \
      "$ROOT/.env" > "$WORK/$side/app/.env"
  chmod 600 "$WORK/$side/app/.env"
  grep -q "^DB_NAME=$DB\$" "$WORK/$side/app/.env" || { echo "임시 .env($side) 에 임시 DB 이름이 들어가지 않았다 — 중단"; exit 4; }
  ln -s "$PLAN_DIR" "$WORK/$side/community" 2>/dev/null
  # 운영 nginx 흉내 — /tbox/assets/ 는 7일 캐시 머리 + 검증자, /assets/ 는 308, 그 밖은 index.php 한 입구
  cat > "$WORK/$side/app/router.php" <<'PHP'
<?php
$root = __DIR__ . '/public';
$uri  = $_SERVER['REQUEST_URI'];
$path = parse_url($uri, PHP_URL_PATH);
if (is_string($path) && strpos($path, '/assets/') === 0) {
    header('Location: /tbox' . $uri, true, 308);
    return true;
}
if (is_string($path) && strpos($path, '/tbox/assets/') === 0) {
    $log = getenv('UPGRADE_ASSET_LOG');
    if ($log) { @file_put_contents($log, $uri . "\n", FILE_APPEND | LOCK_EX); }
    $rel = substr($path, strlen('/tbox/assets/'));
    if (strpos($rel, '..') === false) {
        $file = $root . '/assets/' . $rel;
        if (is_file($file)) {
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            $types = ['js' => 'text/javascript', 'css' => 'text/css', 'json' => 'application/json', 'svg' => 'image/svg+xml',
                      'png' => 'image/png', 'woff2' => 'font/woff2', 'html' => 'text/html', 'webmanifest' => 'application/manifest+json'];
            $mtime = filemtime($file);
            $size = filesize($file);
            header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
            // nginx `expires` 가 내는 두 머리(값은 부르는 셸이 deploy/sites 에서 읽어 넘긴다) + nginx 정적 파일의 검증자(ETag 는 "<mtime 16진>-<크기 16진>")
            $age = (int) (getenv('UPGRADE_ASSET_MAXAGE') ?: 0);
            header('Cache-Control: max-age=' . $age);
            header('Expires: ' . gmdate('D, d M Y H:i:s', time() + $age) . ' GMT');
            header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
            header(sprintf('ETag: "%x-%x"', $mtime, $size));
            readfile($file);
            return true;
        }
    }
    http_response_code(404);
    return true;
}
require $root . '/index.php';
PHP
done
# 바뀌지 않은 자산은 운영처럼 파일 시각도 그대로 — N-1 에서 N 과 내용이 같은 자산은 N 의 시각으로(검토 M3)
same=0
while IFS= read -r -d '' f; do
  rel="${f#"$WORK/old/app/"}"
  if [ -f "$WORK/new/app/$rel" ] && cmp -s "$f" "$WORK/new/app/$rel"; then touch -r "$WORK/new/app/$rel" "$f"; same=$((same+1)); fi
done < <(find "$WORK/old/app/public/assets" -type f -print0)
note "두 판에서 내용이 같은 자산 ${same}개 — 파일 시각을 맞췄다"
note "N-1 트리 $(find "$WORK/old/app/public/assets" -type f | wc -l)개 자산 · N 트리 $(find "$WORK/new/app/public/assets" -type f | wc -l)개 자산"

sub "운영 DB 를 **읽어** 임시 DB 를 만든다"
sudo -n -u postgres dropdb --if-exists "$DB" >/dev/null 2>&1
sudo -n -u postgres createdb -O tbox_owner "$DB" >/dev/null 2>&1 || { echo "임시 DB 를 만들 수 없다"; exit 3; }
DB_CREATED=1
# 평문 덤프를 디스크에 두지 않는다 — 파이프로 곧바로 임시 DB 에(scripts/backup_db.sh 원칙 · 독립 검토 Q:F-4 A M-1)
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

start_server() {   # $1 = old|new — 같은 포트
  : > "$WORK/assets.$1.log"
  ( cd "$WORK/$1/app" && UPGRADE_ASSET_LOG="$WORK/assets.$1.log" UPGRADE_ASSET_MAXAGE="$MAXAGE" exec php -S "127.0.0.1:$PORT" -t public router.php > "$WORK/server.$1.log" 2>&1 ) &
  SERVER_PID=$!
  local ready=""
  for _ in 1 2 3 4 5 6 7 8 9 10; do
    sleep 1
    ready=$(curl -s -o /dev/null -w '%{http_code}' -A "$UA" "$CBASE/api/pages")
    [ "$ready" = "401" ] && break
  done
  expect "임시 인스턴스($1)가 떴다 (미로그인 커뮤니티 창구 401)" "401" "$ready"
  [ "$ready" = "401" ] || { echo "인스턴스를 띄우지 못했다 — 중단"; tail -5 "$WORK/server.$1.log"; exit 4; }
  local cc
  cc=$(curl -s -o /dev/null -D - -A "$UA" "$SITE/tbox/assets/app.js" | tr -d '\r' | sed -n 's/^[Cc]ache-[Cc]ontrol: //p')
  expect "  자산 캐시 머리가 운영과 같다(max-age=$MAXAGE)" "max-age=$MAXAGE" "$cc"
}

# ════════════════════════════════════════════════════════════════
hr "① N-1($OLD_TAG) 을 띄우고 로그인 ($WHO — 비밀은 표준입력으로만)"
start_server old

PW=$(sudo -n awk -v u="$WHO" '$1==u{print $3}' /etc/tbox-v2/seed-accounts.txt 2>/dev/null)
SEC=$(sudo -n awk -v u="$WHO" '$1==u{print $2}' /etc/tbox-v2/qa-totp.txt 2>/dev/null)
[ -n "$PW" ] || { echo "  ✖ $WHO 비밀번호를 읽지 못했다(sudo) — 중단"; exit 3; }
LOGIN_PY='
import base64, hashlib, hmac, json, re, struct, sys, time, urllib.request
base, ua, who = sys.argv[1], sys.argv[2], sys.argv[3]
pw = sys.stdin.readline().rstrip("\n"); sec = sys.stdin.readline().rstrip("\n")
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
        m = re.search(r"\"csrf_token\":\"([0-9a-f]+)\"", raw)
        print("SESSION=" + cookie)
        print("CSRF=" + (m.group(1) if m else ""))
        sys.exit(0)
    wait = 31 - int(time.time()) % 30
    print("  · 로그인 재시도 — 다음 코드 창까지 %d초" % wait, file=sys.stderr)
    time.sleep(wait)
sys.exit(1)
'
# TOTP 비밀이 없는 계정이면 둘째 줄 없이(파이썬은 빈 값으로 읽는다 — 검토 L1)
if [ -n "$SEC" ]; then LOGIN_OUT=$(py_secret PW SEC -- python3 -c "$LOGIN_PY" "$BASE" "$UA" "$WHO")
else LOGIN_OUT=$(py_secret PW -- python3 -c "$LOGIN_PY" "$BASE" "$UA" "$WHO"); fi
unset PW SEC
SESSION=$(printf '%s\n' "$LOGIN_OUT" | sed -n 's/^SESSION=//p')
CSRF=$(printf '%s\n' "$LOGIN_OUT" | sed -n 's/^CSRF=//p')
unset LOGIN_OUT
[ -n "$SESSION" ] || { echo "  ✖ $WHO 로그인 실패 — 중단"; exit 4; }
expect "로그인 세션 · CSRF 토큰을 받았다" "yes" "$([ -n "$SESSION" ] && [ -n "$CSRF" ] && echo yes || echo no)"
# 세션은 두 트리가 같은 임시 DB 의 sessions 행을 보므로 N 으로 바꿔도 그대로 쓴다(운영 배포와 같다)
( umask 077; printf 'Cookie: tbox_session=%s\nX-CSRF-Token: %s\n' "$SESSION" "$CSRF" > "$WORK/write.hdr" )
unset CSRF

browser() {   # $1 = 단계 · 결과를 $WORK/<단계>.txt 에
  py_secret SESSION -- python3 "$ROOT/tests/upgrade/browser_upgrade.py" "$SITE" "$UA" "$1" "$WORK/profile" "$FLOWS" "$2" \
    > "$WORK/$1.txt" 2>&1
  local rc=$?
  sed 's/^/    /' "$WORK/$1.txt"
  expect "  브라우저($1)가 끝까지 돌았다" "0" "$rc"
}

sub "캐시 채우기 — 프로필 브라우저로 N-1 화면을 돈다(단추는 누르지 않는다)"
browser fill "$WORK/assets.old.log"
n_old_assets=$(sort -u "$WORK/assets.old.log" | wc -l)
expect "N-1 자산을 서버에서 받아 캐시에 넣었다(1건 이상)" "yes" "$([ "$n_old_assets" -ge 1 ] && echo yes || echo no)"
note "N-1 에서 받은 자산 ${n_old_assets}종"
expect "캐시 채우기 — 흐름마다 자산을 받았고 로그인·오류 화면이 아니었다(FILLFAIL 0)" "0" "$(grep -c '^FILLFAIL ' "$WORK/fill.txt")"

# ════════════════════════════════════════════════════════════════
hr "② 같은 포트에서 N 으로 바꾼다 (새로고침·캐시 비우기 없이)"
stop_server
start_server new

hr "③ upgrade — N-1 캐시를 든 브라우저로 N"
browser upgrade "$WORK/assets.new.log"
n_hits=$(awk '$1=="CACHEHITS"{s+=$3} END{print s+0}' "$WORK/upgrade.txt")
expect "양성 대조 — 캐시가 답한 자산이 있다(서버를 거치지 않음, 1건 이상)" "yes" "$([ "$n_hits" -ge 1 ] && echo yes || echo no)"
note "캐시가 답한 자산 연인원 ${n_hits}건"
hr "④ fresh — 캐시 없는 새 문맥으로 N(대조)"
browser fresh "$WORK/assets.new.log"

# ════════════════════════════════════════════════════════════════
hr "판정 (설계 5·7장)"
ITEMS_PASS=0; ITEMS_FAIL=0; ITEMS_SKIP=0
for id in $(python3 -c 'import json,sys; print(" ".join(f["id"] for f in json.load(open(sys.argv[1]))["flows"]))' "$FLOWS"); do
  up=$(awk -v f="$id" '$1=="FLOW" && $2==f {print $3; exit}' "$WORK/upgrade.txt")
  fr=$(awk -v f="$id" '$1=="FLOW" && $2==f {print $3; exit}' "$WORK/fresh.txt")
  why=$(awk -v f="$id" '$1=="FLOW" && $2==f {$1=$2=$3=""; sub(/^ +/,""); print; exit}' "$WORK/upgrade.txt")
  cached=$(awk -v f="$id" '$1=="CACHED" && $2==f {print $3}' "$WORK/upgrade.txt" | head -6 | paste -sd, -)
  [ -n "$up" ] || up="fail"; [ -n "$fr" ] || fr="fail"
  if [ "$fr" = "pass" ] && [ "$up" = "pass" ]; then ITEMS_PASS=$((ITEMS_PASS+1)); reason="-"
  elif [ "$fr" = "pass" ]; then ITEMS_FAIL=$((ITEMS_FAIL+1)); reason="${why:-?} · 캐시가 답한 부품: ${cached:-없음}"
  else ITEMS_SKIP=$((ITEMS_SKIP+1)); reason="새 문맥에서도 실패 — 코드 결함(이 시험 몫 아님, 보고만): $(awk -v f="$id" '$1=="FLOW" && $2==f {$1=$2=$3=""; sub(/^ +/,""); print; exit}' "$WORK/fresh.txt")"
  fi
  printf 'RESULT_ITEM flow=%s fresh=%s upgrade=%s reason=%s\n' "$id" "$fr" "$up" "$reason"
done
printf 'RESULT pass=%d fail=%d skip=%d\n' "$ITEMS_PASS" "$ITEMS_FAIL" "$ITEMS_SKIP"
expect "업그레이드 뒤 끊긴 동선 0 (upgrade=fail · fresh=pass)" "0" "$ITEMS_FAIL"
expect "새 문맥 대조가 모두 통과(fresh=fail 은 코드 결함·준비 오류 — 판정을 믿을 수 없다)" "0" "$ITEMS_SKIP"

sub "만든 페이지는 앱 창구로 지운다"
for id in $(cat "$WORK/upgrade.txt" "$WORK/fresh.txt" "$WORK/fill.txt" | sed -n 's/^CREATED=//p' | sort -u); do
  code=$(curl -s -o /dev/null -w '%{http_code}' -A "$UA" -X DELETE -H 'Expect:' -H @"$WORK/write.hdr" "$CBASE/api/pages/$id")
  expect "DELETE /community/api/pages/$id" "yes" "$(case "$code" in 200|204) echo yes;; *) echo "no($code)";; esac)"
done
expect "인스턴스 로그에 uncaught 0" "0" "$(cat "$WORK"/server.*.log 2>/dev/null | grep -ci 'uncaught' | tr -d ' ')"
unset SESSION

hr "결과"
printf '통과 %d · 실패 %d · 건너뜀 %d\n' "$PASS" "$FAIL" "$SKIP"
note "임시 DB($DB)·임시 폴더(두 트리·브라우저 프로필)·임시 서버는 이 스크립트가 끝나며 지워진다. 운영에는 아무것도 남지 않는다."
[ "$FAIL" -eq 0 ] || exit 1
exit 0
