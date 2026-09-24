#!/usr/bin/env bash
# tests/community/qf4_draft_key.sh — Q:F-4 라운드 B2 「U-3 자동 요청은 세션을 늘리지 않는다 + B1-2 쓰던 글 임시저장」 수락 시험
#
# 지시서: /var/www/qaredi/docs/qf4-quick-fixes-directive.md 라운드 B — 「P-3 + U-3」 의 U-3 ①~⑦ · 「B1-2」 완료 기준 ①~⑪
#         (둘은 같은 커밋 — U-3 만 들어가면 무활동 로그아웃이 쓰던 글을 잃게 하므로). 더해서 P-3 검토 Low-3
#         (60초 안의 사람 요청은 act_last_seen_at 을 밀지 않는다 — 역할로 보기 세션).
#
#   [A] 새 창구 GET /community/api/me/draft-key — B1-2 ⑧
#       비로그인 401 · Cache-Control: no-store · ETag·Last-Modified 없음 · 같은 사람 두 번 같은 값 · 다른 사람 다른 값 ·
#       역할로 보기 중에도 진짜 사용자 값 · 감사 없음(허용 행 0) · app.log(인스턴스 server.log)·audit.log·감사 표에 열쇠 0 ·
#       APP_SECRET 이 짧으면 503 + 로그 한 줄(값 없음)
#   [B] U-3 — ① 사람 요청 두 번(5초) → 갱신 1번 ② 61초 지난 뒤 → 갱신 ③ 자동 요청만 3분 → 401 ④ 같은 3분에 사람 요청 1번 → 유지
#       ⑤ 역할로 보기 세션도 ③·④ 와 같음 + 자동 요청은 act_last_seen_at 도 밀지 않음 + Low-3 ⑥ 쓰기(저장·댓글)는 늘 연장
#       ⑦ 감사 문장 변화 0 · 대조군: 표식 없는 폴링은 세션을 늘린다(전과 같다)
#   [C] 헤드리스 브라우저 — tests/browser/qf4_drafts.py (B1-2 ①~⑦ · ⑨ · ⑩ 스크린샷, pageerror·console.error 0)
#
# 쓰는 법:  bash tests/community/qf4_draft_key.sh [스크린샷 폴더]
#   스크린샷 폴더를 주지 않으면 mktemp 로 만든다(시험이 끝나도 지우지 않는다 — 사람이 직접 본다).
#   SECTIONS="key u3 browser" 로 일부만 돌릴 수 있다(기본 셋 다). [B] 는 3분, [C] 는 약 5분 걸린다.
#
# ★ 운영 DB 를 건드리지 않는다 — tests/community/stale_modules.sh 와 같은 틀: 운영 DB(tbox_re)는 pg_dump 로 **읽기만**
#   (파이프 — 평문 덤프 파일 0), 임시 DB `tbox_qf4b2_test` · 포트 8102 · 임시 폴더. 임시 .env 는 SESSION_IDLE_MIN=2
#   (원본 .env 는 읽기만). 끝나면 서버·임시 DB·임시 폴더를 지운다.
# ★ 계정은 검토 계정 claude_ 만(F-71), 모든 요청 UA `tbox-review/<날짜>`.
# ★ 비밀(비밀번호·OTP 비밀)은 명령 인자에 싣지 않는다 — tests/lib/secrets.sh 의 py_secret(표준입력)·curl_json_secret.
#   세션·CSRF 는 600 헤더 파일(`-H @파일`)로만. 임시저장 열쇠(draft-key 응답)는 **찍지 않는다** — 0700 작업 폴더의 파일끼리
#   cmp 로 견주고, 로그·감사에 있는지는 grep -c -F -f 로 센다(값이 명령 인자·출력에 나오지 않는다).
# 나가는 값: 0 통과 · 1 실패 · 3 준비물 없음 · 4 인스턴스 실패
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
. "$ROOT/tests/lib/review_accounts.sh" || { echo "review_accounts.sh 를 읽지 못했다 — 시작하지 않는다"; exit 3; }
. "$ROOT/tests/lib/secrets.sh" || { echo "tests/lib/secrets.sh 를 읽지 못했다 — 시작하지 않는다 (R-62)"; exit 3; }
PORT=8102
SITE="http://127.0.0.1:$PORT"
BASE="$SITE/tbox"
CBASE="$SITE/community"
UA="tbox-review/$(date +%F)"
SRC_DB="tbox_re"            # 운영 — **읽기만** 한다
DB="tbox_qf4b2_test"        # 임시 — 여기에만 쓴다
IDLE_MIN=2                  # 임시 인스턴스의 무활동 창(분) — 지시서 「SESSION_IDLE_MIN 을 2 로 낮춘 .env 사본」
SECTIONS="${SECTIONS:-key u3 browser}"
WORK="$(mktemp -d)"
chmod 700 "$WORK"
SHOTS="${1:-}"
[ -n "$SHOTS" ] || SHOTS="$(mktemp -d -t qf4b2-shots.XXXXXX)"
mkdir -p "$SHOTS"
INST=""
SERVER_PID=""
PASS=0
FAIL=0
SKIP=0
E1=claude_employee01
E2=claude_employee02
M1=claude_manager01
A1=claude_admin01
BG_HDR='X-Qaredi-Background: 1'

hr()  { printf '\n════════════════════════════════════════════════════════════\n%s\n════════════════════════════════════════════════════════════\n' "$1"; }
sub() { printf '\n── %s\n' "$1"; }
expect() {
  if [ "$2" = "$3" ]; then printf '  ✔ %s  (기대=%s 실제=%s)\n' "$1" "$2" "$3"; PASS=$((PASS+1))
  else printf '  ✖ %s  (기대=%s 실제=%s)\n' "$1" "$2" "$3"; FAIL=$((FAIL+1)); fi
}
note() { printf '  · %s\n' "$1"; }
skip() { printf '  ○ SKIP %s  (%s)\n' "$1" "$2"; SKIP=$((SKIP+1)); }
want() { case " $SECTIONS " in *" $1 "*) return 0 ;; esac; return 1; }

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
  [ -n "$INST" ] && rm -rf "$INST"
  rm -rf "$WORK"
}
trap cleanup EXIT

psqlq() { sudo -n -u postgres psql -d "$DB" -t -A -c "$1" 2>/dev/null; }
jget() { python3 -c 'import json,sys
try:
    d = json.load(sys.stdin)
except Exception:
    d = {}
print(eval(sys.argv[1], {"d": d}))' "$1" 2>/dev/null; }

start_server() {
  if ss -ltn 2>/dev/null | grep -q "127.0.0.1:$PORT "; then
    echo "포트 $PORT 이 이미 쓰이고 있다 — 앞선 시험이 남긴 서버를 먼저 내린다"; exit 3
  fi
  ( cd "$INST/app" && exec php -S "127.0.0.1:$PORT" -t public router.php >> "$INST/server.log" 2>&1 ) &
  SERVER_PID=$!
  local ready=""
  for _ in $(seq 1 20); do
    sleep 0.5
    ready=$(curl -s -o /dev/null -w '%{http_code}' -A "$UA" "$CBASE/api/pages")
    [ "$ready" = "401" ] && break
  done
  [ "$ready" = "401" ] || { echo "인스턴스를 띄우지 못했다 — 중단"; tail -5 "$INST/server.log"; exit 4; }
}

# ─── 로그인 — 비밀은 py_secret 으로 파이썬의 표준입력에만. 같은 계정을 다시 넣을 때는 TOTP 한 칸(30초)을 넘긴다
#     (같은 칸의 코드는 code_reused 로 실패 횟수가 오른다 — R-45). 세션·CSRF 는 $WORK/<이름>.{cookie,write,sid} (600)
cat > "$WORK/login.py" <<'PY'
import base64, hashlib, hmac, json, re, struct, sys, time, urllib.request
base, ua, who = sys.argv[1], sys.argv[2], sys.argv[3]
pw = sys.stdin.readline().rstrip("\n")
sec = sys.stdin.readline().rstrip("\n")
def totp(secret, t):
    key = base64.b32decode(secret.upper() + "=" * (-len(secret) % 8))
    mac = hmac.new(key, struct.pack(">Q", int(t) // 30), hashlib.sha1).digest()
    o = mac[-1] & 15
    return "%06d" % ((struct.unpack(">I", mac[o:o + 4])[0] & 0x7FFFFFFF) % 1000000)
for attempt in range(3):
    body = {"username": who, "password": pw, "otp": totp(sec, time.time())}
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
        print("SESSION=" + cookie)
        print("CSRF=" + (m.group(1) if m else ""))
        sys.exit(0)
    wait = 31 - int(time.time()) % 30
    print("  · 로그인 재시도 — 다음 코드 창까지 %d초" % wait, file=sys.stderr)
    time.sleep(wait)
sys.exit(1)
PY

declare -A LAST_STEP
# $1 계정  $2 이 세션을 부를 이름(헤더 파일 이름)
login_as() {
  local who="$1" tag="$2" PW SEC out now_step session csrf
  review_account "$who" || { echo "  ✖ $who 는 검토 계정이 아니다 — 중단"; exit 3; }
  case "$who" in claude_*) ;; *) echo "  ✖ 새 시험은 claude_ 계정만 쓴다 — 중단"; exit 3 ;; esac
  now_step=$(( $(date +%s) / 30 ))
  if [ -n "${LAST_STEP[$who]:-}" ] && [ "$now_step" -le "${LAST_STEP[$who]}" ]; then
    local wait_s=$(( 31 - $(date +%s) % 30 ))
    note "$who 를 다시 넣는다 — 같은 TOTP 칸을 피해 ${wait_s}초 기다림"
    sleep "$wait_s"
  fi
  PW=$(sudo -n awk -v u="$who" '$1==u{print $3}' /etc/tbox-v2/seed-accounts.txt 2>/dev/null)
  SEC=$(sudo -n awk -v u="$who" '$1==u{print $2}' /etc/tbox-v2/qa-totp.txt 2>/dev/null)
  [ -n "$PW" ] && [ -n "$SEC" ] || { echo "  ✖ $who 비밀을 읽지 못했다(sudo) — 중단"; exit 3; }
  out=$(py_secret PW SEC -- python3 "$WORK/login.py" "$BASE" "$UA" "$who")
  unset PW SEC
  session=$(printf '%s\n' "$out" | sed -n 's/^SESSION=//p')
  csrf=$(printf '%s\n' "$out" | sed -n 's/^CSRF=//p')
  # 이 계정이 마지막으로 쓴 TOTP 칸 — 로그인 출력이 아니라 이 셸의 시계로(늦게 잡히면 더 기다릴 뿐이다)
  LAST_STEP[$who]=$(( $(date +%s) / 30 ))
  [ -n "$session" ] && [ -n "$csrf" ] || { echo "  ✖ $who 로그인 실패 — 중단"; exit 4; }
  ( umask 077
    printf 'Cookie: tbox_session=%s\n' "$session" > "$WORK/$tag.cookie"
    printf 'Cookie: tbox_session=%s\nX-CSRF-Token: %s\n' "$session" "$csrf" > "$WORK/$tag.write"
    # 세션 행 id(= 쿠키 토큰의 sha256) — DB 가 들고 있는 값이다(쿠키로 되돌릴 수 없다). 행을 계정이 아니라 세션으로 찾는다.
    printf '%s' "$session" | sha256sum | cut -c1-64 > "$WORK/$tag.sid" )
  unset session csrf out
  expect "$who 로그인 ($tag) — 세션 · CSRF 토큰을 받았다" "yes" "yes"
}
sid() { cat "$WORK/$1.sid"; }

# 창구 부르기 — $1 세션 이름 $2 메서드 $3 전체 URL [$4 본문 JSON 파일] [$5 bg 면 자동 요청 표식]. 본문 $WORK/r.body · 머리 $WORK/r.head
call() {
  local tag="$1" method="$2" url="$3" bodyf="${4:-}" bg="${5:-}"
  local extra=()
  [ "$bg" = bg ] && extra=(-H "$BG_HDR")
  if [ -n "$bodyf" ]; then
    curl -s -D "$WORK/r.head" -o "$WORK/r.body" -w '%{http_code}' -A "$UA" -X "$method" -H 'Expect:' \
      -H 'Content-Type: application/json' -H @"$WORK/$tag.write" "${extra[@]}" --data-binary @"$bodyf" "$url"
  elif [ "$method" = GET ]; then
    curl -s -D "$WORK/r.head" -o "$WORK/r.body" -w '%{http_code}' -A "$UA" -H @"$WORK/$tag.cookie" "${extra[@]}" "$url"
  else
    curl -s -D "$WORK/r.head" -o "$WORK/r.body" -w '%{http_code}' -A "$UA" -X "$method" -H 'Expect:' \
      -H 'Content-Type: application/json' -H @"$WORK/$tag.write" "${extra[@]}" --data-binary '{}' "$url"
  fi
}
seen_at() { psqlq "SELECT to_char(last_seen_at, 'YYYY-MM-DD HH24:MI:SS.US') FROM sessions WHERE id = '$(sid "$1")'"; }
act_seen_at() { psqlq "SELECT coalesce(to_char(act_last_seen_at, 'YYYY-MM-DD HH24:MI:SS.US'), 'NULL') FROM sessions WHERE id = '$(sid "$1")'"; }
backdate() { psqlq "UPDATE sessions SET last_seen_at = last_seen_at - interval '61 seconds',
                      act_last_seen_at = CASE WHEN act_last_seen_at IS NULL THEN NULL ELSE act_last_seen_at - interval '61 seconds' END
                    WHERE id = '$(sid "$1")'" >/dev/null; }

# ════════════════════════════════════════════════════════════════
hr "Q:F-4 B2 — 준비 (운영 DB 에는 쓰지 않는다)"

python3 -c 'import playwright' 2>/dev/null || { export PATH="$HOME/.local/bin:$PATH"; }
if want browser; then
  python3 -c 'import playwright' 2>/dev/null || { echo "playwright 가 없다 — 브라우저 절은 성립하지 않는다"; exit 3; }
fi
avail_kb=$(df -Pk / | awk 'NR==2{print $4}')
[ "$avail_kb" -ge 1048576 ] || { echo "루트 디스크 여유 1GB 미만 — 시험을 시작하지 않는다 (SECURITY R-80)"; exit 3; }
sudo -n -u postgres psql -d "$SRC_DB" -tAc "SELECT 1" >/dev/null 2>&1 \
  || { echo "psql(postgres) 을 쓸 수 없다 — 이 시험은 임시 DB 가 없으면 성립하지 않는다"; exit 3; }

sub "정적 — 부품 버전 · 표식 상수 한 곳 · 임시저장 모듈의 금지 모양"
expect "커뮤니티 부품 버전 표시가 지금 내용과 맞다(--check)" "0" \
  "$(php "$ROOT/scripts/community_asset_version.php" --check > /dev/null 2>&1; echo $?)"
expect "editor.js 가 drafts.js 를 버전 붙여 부른다" "1" \
  "$(grep -cE "^import \{ createDrafts \} from './drafts\.js\?v=[0-9a-f]{12}';" "$ROOT/public/assets/community/editor.js")"
expect "서버 표식 상수 — Auth.php 에 이름·값 한 번씩" "1|1" \
  "$(grep -c "public const BACKGROUND_HEADER = 'X-Qaredi-Background';" "$ROOT/src/Auth.php")|$(grep -c "public const BACKGROUND_VALUE  = '1';" "$ROOT/src/Auth.php")"
expect "화면 표식 상수 — editor.js 에 이름 글자가 한 번(상수 정의)뿐" "1" \
  "$(grep -c "X-Qaredi-Background" "$ROOT/public/assets/community/editor.js")"
expect "자동 요청 둘(폴링·heartbeat)에만 표식" "2" "$(grep -c "{ background: true }" "$ROOT/public/assets/community/editor.js")"
expect "Auth — 표식은 SQL_TOUCH 를 건너뛰는 한 곳에서만 본다" "1" "$(grep -c "isBackgroundRequest()" "$ROOT/src/Auth.php" | awk '{print $1-1}')"
expect "drafts.js — importKey extractable=false 한 곳 · true 0" "1|0" \
  "$(grep -c "importKey('raw', bytes, { name: 'AES-GCM' }, false, \['encrypt', 'decrypt'\])" "$ROOT/public/assets/community/drafts.js")|$(grep -cE "importKey\([^)]*true" "$ROOT/public/assets/community/drafts.js")"
expect "drafts.js — 네트워크·콘솔·HTML·쿠키·전역 0 (fetch·XMLHttpRequest·sendBeacon·console·innerHTML·document.cookie·window.<이름> =)" "0" \
  "$(grep -cE "fetch\(|XMLHttpRequest|sendBeacon|console\.|innerHTML|document\.cookie|window\.[A-Za-z_]+ *=[^=]" "$ROOT/public/assets/community/drafts.js")"
expect "app.js — 로그아웃 지우기는 drafts.js 를 부르지 않는다(createDrafts·import 0) · 지우는 함수는 로그아웃 한 곳에서" "0|1" \
  "$(grep -cE "createDrafts|import[^;]*drafts" "$ROOT/public/assets/app.js")|$(grep -c "clearMyDrafts(me.username)" "$ROOT/public/assets/app.js")"
expect "감사 문장 파일(Audit.php) 변경 0 (U-3 ⑦)" "0" "$(git -C "$ROOT" diff HEAD --stat -- src/Audit.php | wc -l | tr -d ' ')"

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
note "임시 DB $DB — 페이지 ${n_pages}장"

sub "저장소를 임시 폴더로 복사하고 두 번째 인스턴스를 띄운다 (SESSION_IDLE_MIN=$IDLE_MIN)"
INST="$(mktemp -d)"
mkdir -p "$INST/app" "$INST/storage" "$INST/log"
tar -C "$ROOT" -cf - --exclude=.git --exclude=jar --exclude=api-test.txt --exclude=./.env . | tar -C "$INST/app" -xf -
sed -e "s#^DB_NAME=.*#DB_NAME=$DB#" \
    -e "s#^STORAGE_DIR=.*#STORAGE_DIR=$INST/storage#" \
    -e "s#^AUDIT_LOG_FILE=.*#AUDIT_LOG_FILE=$INST/log/audit.log#" \
    -e "s#^SESSION_IDLE_MIN=.*#SESSION_IDLE_MIN=$IDLE_MIN#" \
    "$ROOT/.env" > "$INST/app/.env"
chmod 600 "$INST/app/.env"
grep -q "^DB_NAME=$DB\$" "$INST/app/.env" || { echo "임시 .env 에 임시 DB 이름이 들어가지 않았다 — 중단"; exit 4; }
expect "임시 .env 의 무활동 창 = ${IDLE_MIN}분 (원본 .env 는 읽기만)" "1" "$(grep -c "^SESSION_IDLE_MIN=$IDLE_MIN\$" "$INST/app/.env")"
cp -p "$INST/app/.env" "$WORK/env.good"
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
start_server
expect "임시 인스턴스가 떴다 (미로그인 커뮤니티 창구 401)" "yes" "yes"
AUD0=$(psqlq "SELECT coalesce(max(id), 0) FROM audit_logs")

sub "로그인 — 검토 계정 (비밀은 py_secret 표준입력으로만)"
login_as "$E1" e1a
login_as "$E2" e2a
login_as "$A1" a1a
login_as "$M1" m1

# 계정마다 쓸 페이지 하나(사람 요청 — 준비). heartbeat 가 닿을 자리다.
mkpage() {
  local tag="$1" code
  printf '{"title":"qf4b2 %s %s"}' "$tag" "$(date +%H%M%S)" > "$WORK/p.json"
  call "$tag" GET "$CBASE/api/me/space" >/dev/null
  code=$(call "$tag" POST "$CBASE/api/pages" "$WORK/p.json")
  [ "$code" = 201 ] || { echo "  ✖ $tag 페이지를 만들지 못했다($code) — 중단"; exit 4; }
  jget "d['page']['id']" < "$WORK/r.body"
}
PG_E1=$(mkpage e1a)
PG_E2=$(mkpage e2a)
note "페이지 — $E1 $PG_E1 · $E2 $PG_E2"

# 열쇠 하나를 받아 파일로 — 값은 찍지 않는다. $1 세션 $2 파일 이름. 찍는 값: HTTP 상태
key_to_file() {
  local code
  code=$(call "$1" GET "$CBASE/api/me/draft-key")
  cp "$WORK/r.head" "$WORK/$2.head"
  python3 - "$WORK/r.body" "$WORK/$2.key" <<'PY'
import json, re, sys, os
try:
    d = json.load(open(sys.argv[1], encoding="utf-8"))
except Exception:
    d = {}
k = d.get("key") if isinstance(d, dict) else None
old = os.umask(0o077)
with open(sys.argv[2], "w") as f:
    f.write(k if isinstance(k, str) else "")
os.umask(old)
PY
  printf '%s' "$code"
}
# 열쇠 파일의 모양 — v=1, base64url 43자(32바이트, 끝 = 없음). 찍는 값 ok/bad 뿐
key_shape() { python3 - "$WORK/r.body" "$WORK/$1.key" <<'PY'
import base64, json, re, sys
try:
    d = json.load(open(sys.argv[1], encoding="utf-8"))
except Exception:
    d = {}
k = open(sys.argv[2]).read()
ok = isinstance(d, dict) and d.get("v") == 1 and sorted(d.keys()) == ["key", "v"] and re.fullmatch(r"[A-Za-z0-9_-]{43}", k or "") is not None
if ok:
    ok = len(base64.urlsafe_b64decode(k + "=")) == 32
print("ok" if ok else "bad")
PY
}
# 열쇠 파일이 어떤 파일에 몇 번 나오나 — 값이 인자에 오르지 않게 -f 파일로
key_hits() { [ -s "$WORK/$1.key" ] || { echo "nokey"; return; }; grep -c -F -f "$WORK/$1.key" "$2" 2>/dev/null || true; }

# ════════════════════════════════════════════════════════════════
if want key; then
hr "[A] GET /community/api/me/draft-key — B1-2 ⑧"

sub "⑧ 비로그인 401 (본문에 열쇠 없음)"
code=$(curl -s -D "$WORK/r.head" -o "$WORK/r.body" -w '%{http_code}' -A "$UA" "$CBASE/api/me/draft-key")
expect "쿠키 없이 → 401" "401" "$code"
expect "  본문에 key 칸 없음" "False" "$(jget "'key' in d" < "$WORK/r.body")"

sub "⑧ 본인 — 200 · no-store · ETag/Last-Modified 없음 · 모양"
expect "$E1 → 200" "200" "$(key_to_file e1a k_e1)"
expect "  모양 {v:1, key: base64url 32바이트}" "ok" "$(key_shape k_e1)"
expect "  Cache-Control: no-store" "1" "$(grep -ci '^cache-control: no-store' "$WORK/k_e1.head" | tr -d ' ')"
expect "  ETag · Last-Modified 없음" "0" "$(grep -ciE '^(etag|last-modified):' "$WORK/k_e1.head" | tr -d ' ')"
expect "$E1 두 번째 → 200" "200" "$(key_to_file e1a k_e1b)"
expect "  같은 사람 두 번 같은 값" "same" "$(cmp -s "$WORK/k_e1.key" "$WORK/k_e1b.key" && echo same || echo diff)"
expect "$E2 → 200" "200" "$(key_to_file e2a k_e2)"
expect "  다른 사람 다른 값" "diff" "$(cmp -s "$WORK/k_e1.key" "$WORK/k_e2.key" && echo same || echo diff)"
expect "$A1(역할로 보기 전) → 200" "200" "$(key_to_file a1a k_a1)"

sub "⑧ 역할로 보기 중에도 진짜 사용자의 열쇠 ($A1 → Employee)"
A1_PW=$(sudo -n awk -v u="$A1" '$1==u{print $3}' /etc/tbox-v2/seed-accounts.txt 2>/dev/null)
printf -v REAUTH_BODY '{"password":"%s"}' "$A1_PW"
unset A1_PW
code=$(curl_json_secret REAUTH_BODY -s -o "$WORK/r.body" -w '%{http_code}' -A "$UA" -H 'Expect:' \
  -H 'Content-Type: application/json' -H @"$WORK/a1a.write" "$BASE/api/me/reauth")
expect "$A1 재인증 200 (비밀번호는 표준입력으로)" "200" "$code"
printf '{"role":"Employee","team_id":1}' > "$WORK/act.json"
expect "$A1 역할로 보기 켜기(Employee·팀 1) 200" "200" "$(call a1a POST "$BASE/api/me/act" "$WORK/act.json")"
call a1a GET "$BASE/api/me" >/dev/null
expect "  /tbox/api/me acting=true · role=Employee" "True|Employee" "$(jget "str(d.get('acting')) + '|' + str(d.get('role'))" < "$WORK/r.body")"
expect "$A1(보는 중) → 200" "200" "$(key_to_file a1a k_a1_act)"
expect "  보는 중에도 같은 값(진짜 사용자)" "same" "$(cmp -s "$WORK/k_a1.key" "$WORK/k_a1_act.key" && echo same || echo diff)"
expect "  관리자 열쇠는 직원 열쇠와 다르다" "diff" "$(cmp -s "$WORK/k_a1.key" "$WORK/k_e1.key" && echo same || echo diff)"

sub "⑧ 감사 없음 · 열쇠가 로그·감사 어디에도 없다 (값은 찍지 않고 센다)"
AUD_DK=$(psqlq "SELECT result || ':' || count(*) FROM audit_logs WHERE id > $AUD0 AND (target_name LIKE '%draft-key%' OR detail::text LIKE '%draft-key%') GROUP BY result ORDER BY result")
expect "draft-key 감사 행 — 허용 0 · 거부는 비로그인 401 한 줄(기존 인증 흐름)" "deny:1" "$(printf '%s' "$AUD_DK" | tr '\n' ' ' | sed 's/ $//')"
sudo -n -u postgres psql -d "$DB" -t -A -c "COPY (SELECT * FROM audit_logs WHERE id > $AUD0) TO STDOUT" > "$WORK/audit_rows.txt" 2>/dev/null
for k in k_e1 k_e2 k_a1; do
  expect "  $k — 감사 표(이번 시험 행 $(wc -l < "$WORK/audit_rows.txt" | tr -d ' ')줄)에 0" "0" "$(key_hits "$k" "$WORK/audit_rows.txt")"
  expect "  $k — 인스턴스 app.log(server.log)에 0" "0" "$(key_hits "$k" "$INST/server.log")"
  expect "  $k — audit.log 파일에 0" "0" "$(key_hits "$k" "$INST/log/audit.log")"
done

sub "⑧ APP_SECRET 이 32바이트 미만이면 503 + 로그 한 줄(값 없음) — 임시 .env 만 바꿔 다시 띄운다"
stop_server
sed -e 's#^APP_SECRET=.*#APP_SECRET=too-short-for-drafts#' "$WORK/env.good" > "$INST/app/.env"
chmod 600 "$INST/app/.env"
LOG_BEFORE=$(grep -c 'draft key unavailable' "$INST/server.log" | tr -d ' ')
start_server
code=$(call e1a GET "$CBASE/api/me/draft-key")
expect "짧은 APP_SECRET → 503" "503" "$code"
expect "  본문은 오류 문구뿐(key 칸 없음)" "False|True" "$(jget "str('key' in d) + '|' + str(isinstance(d.get('error'), str))" < "$WORK/r.body")"
expect "  로그에 「draft key unavailable」 한 줄" "1" "$(( $(grep -c 'draft key unavailable' "$INST/server.log") - LOG_BEFORE ))"
expect "  그 줄에 APP_SECRET 값 0" "0" "$(grep 'draft key unavailable' "$INST/server.log" | grep -c 'too-short-for-drafts' | tr -d ' ')"
stop_server
cp -p "$WORK/env.good" "$INST/app/.env"
start_server
expect "원래 .env 로 다시 띄운 뒤 $E1 → 200 · 전과 같은 값" "200|same" "$(key_to_file e1a k_e1c)|$(cmp -s "$WORK/k_e1.key" "$WORK/k_e1c.key" && echo same || echo diff)"
fi

# ════════════════════════════════════════════════════════════════
if want u3; then
hr "[B] U-3 — 자동 요청(X-Qaredi-Background: 1)은 세션을 확인만 한다 (무활동 창 ${IDLE_MIN}분)"

sub "U-3 ① ② 사람 요청 — 60초 규칙 그대로 (61초 지난 세션은 갱신 · 5초 뒤는 그대로)"
backdate e1a; T0=$(seen_at e1a)
call e1a GET "$BASE/api/me" >/dev/null; T1=$(seen_at e1a)
expect "② 61초 지난 세션 → 사람 요청 한 번에 갱신" "yes" "$([ -n "$T1" ] && [ "$T1" != "$T0" ] && echo yes || echo no)"
sleep 5
call e1a GET "$BASE/api/me" >/dev/null; T2=$(seen_at e1a)
expect "① 5초 뒤 사람 요청 → 갱신 안 함(UPDATE 1회뿐)" "same" "$([ "$T2" = "$T1" ] && echo same || echo changed)"

sub "U-3 자동 요청은 61초 지난 세션도 밀지 않는다 — GET 폴링 · POST heartbeat(쓰기 모양이어도 자동)"
backdate e1a; T0=$(seen_at e1a)
expect "표식 단 GET /community/api/notifications?unread=1 → 200" "200" "$(call e1a GET "$CBASE/api/notifications?unread=1" "" bg)"
expect "  last_seen_at 그대로" "same" "$([ "$(seen_at e1a)" = "$T0" ] && echo same || echo changed)"
expect "표식 단 POST heartbeat → 200" "200" "$(call e1a POST "$CBASE/api/pages/$PG_E1/heartbeat" "" bg)"
expect "  last_seen_at 그대로" "same" "$([ "$(seen_at e1a)" = "$T0" ] && echo same || echo changed)"
code=$(curl -s -o /dev/null -w '%{http_code}' -A "$UA" -H @"$WORK/e1a.cookie" -H 'X-Qaredi-Background: yes' "$CBASE/api/notifications?unread=1")
expect "값이 1 이 아닌 표식(yes) → 사람 요청으로 본다(갱신)" "200|changed" "$code|$([ "$(seen_at e1a)" = "$T0" ] && echo same || echo changed)"

sub "U-3 ⑥ 쓰기 요청(저장·댓글)은 늘 연장 — 사람 행동"
call e1a GET "$CBASE/api/pages/$PG_E1" >/dev/null
V=$(jget "d['page']['doc_version']" < "$WORK/r.body")
python3 - "$WORK/r.body" "$WORK/save.json" <<'PY'
import json, sys
d = json.load(open(sys.argv[1], encoding="utf-8"))
p = d["page"]
json.dump({"doc_version": p["doc_version"], "title": p["title"] + " (저장)", "doc": p["doc"]}, open(sys.argv[2], "w"), ensure_ascii=False)
PY
backdate e1a; T0=$(seen_at e1a)
expect "⑥ 저장 PATCH → 200" "200" "$(call e1a PATCH "$CBASE/api/pages/$PG_E1" "$WORK/save.json")"
expect "  last_seen_at 갱신" "changed" "$([ "$(seen_at e1a)" = "$T0" ] && echo same || echo changed)"
backdate e1a; T0=$(seen_at e1a)
printf '{"body":"qf4b2 U-3 댓글 %s"}' "$(date +%H%M%S)" > "$WORK/c.json"
expect "⑥ 댓글 POST → 201" "201" "$(call e1a POST "$CBASE/api/pages/$PG_E1/comments" "$WORK/c.json")"
expect "  last_seen_at 갱신" "changed" "$([ "$(seen_at e1a)" = "$T0" ] && echo same || echo changed)"
note "(판 번호 $V → $(psqlq "SELECT doc_version FROM community.pages WHERE public_id = '$PG_E1'"))"

sub "U-3 ⑤ 역할로 보기 세션 — 자동 요청은 act_last_seen_at 도 밀지 않는다 · Low-3 (60초 안 사람 요청은 밀지 않음)"
login_as "$A1" a1b
A1_PW=$(sudo -n awk -v u="$A1" '$1==u{print $3}' /etc/tbox-v2/seed-accounts.txt 2>/dev/null)
printf -v REAUTH_BODY '{"password":"%s"}' "$A1_PW"
unset A1_PW
code=$(curl_json_secret REAUTH_BODY -s -o "$WORK/r.body" -w '%{http_code}' -A "$UA" -H 'Expect:' \
  -H 'Content-Type: application/json' -H @"$WORK/a1b.write" "$BASE/api/me/reauth")
unset REAUTH_BODY
expect "$A1 (a1b) 재인증 200" "200" "$code"
printf '{"role":"Employee","team_id":1}' > "$WORK/act.json"
expect "$A1 (a1b) 역할로 보기 켜기 200" "200" "$(call a1b POST "$BASE/api/me/act" "$WORK/act.json")"
if ! want key; then
  # [A] 를 건너뛰었으면 a1a 도 여기서 보는 중으로 만든다(③·⑤ 의 자동 요청만 세션)
  A1_PW=$(sudo -n awk -v u="$A1" '$1==u{print $3}' /etc/tbox-v2/seed-accounts.txt 2>/dev/null)
  printf -v REAUTH_BODY '{"password":"%s"}' "$A1_PW"
  unset A1_PW
  curl_json_secret REAUTH_BODY -s -o /dev/null -A "$UA" -H 'Expect:' -H 'Content-Type: application/json' \
    -H @"$WORK/a1a.write" "$BASE/api/me/reauth"
  unset REAUTH_BODY
  call a1a POST "$BASE/api/me/act" "$WORK/act.json" >/dev/null
fi
backdate a1b; S0=$(seen_at a1b); A0=$(act_seen_at a1b)
expect "  준비 — a1b 는 보는 중(act_last_seen_at 있음)" "yes" "$([ "$A0" != NULL ] && [ -n "$A0" ] && echo yes || echo no)"
call a1b GET "$CBASE/api/notifications?unread=1" "" bg >/dev/null
expect "⑤ 자동 요청 → last_seen_at · act_last_seen_at 둘 다 그대로" "same|same" \
  "$([ "$(seen_at a1b)" = "$S0" ] && echo same || echo changed)|$([ "$(act_seen_at a1b)" = "$A0" ] && echo same || echo changed)"
call a1b GET "$BASE/api/me" >/dev/null
S1=$(seen_at a1b); A1S=$(act_seen_at a1b)
expect "⑤ 사람 요청 → 둘 다 갱신" "changed|changed" \
  "$([ "$S1" = "$S0" ] && echo same || echo changed)|$([ "$A1S" = "$A0" ] && echo same || echo changed)"
expect "  보는 중 그대로(acting=true)" "True" "$(jget "str(d.get('acting'))" < "$WORK/r.body")"
call a1b GET "$BASE/api/me" >/dev/null
expect "Low-3 60초 안의 사람 요청 → act_last_seen_at 을 밀지 않는다(last_seen_at 도)" "same|same" \
  "$([ "$(act_seen_at a1b)" = "$A1S" ] && echo same || echo changed)|$([ "$(seen_at a1b)" = "$S1" ] && echo same || echo changed)"

sub "U-3 ③ ④ ⑤ + 대조군 — 3분 창 (30초마다 자동 요청, 90초에 사람 요청 1번)"
note "e1b($E1) 자동만 · e2a($E2) 자동+사람 · a1a($A1 보는 중) 자동만 · a1b($A1 보는 중) 자동+사람 · m1($M1) 표식 없는 폴링(대조군)"
login_as "$E1" e1b
# 창의 시작을 맞춘다(임시 DB) — 다섯 세션의 마지막 활동 = 지금. 앞 절의 사람 요청이 언제였는지에 따라 창이 짧아지지 않게
# (첫 실행에서 e2a 가 앞 절의 마지막 요청 뒤 이미 35초가 지나 90초 칸 전에 만료됐다). 보는 중 시각(act_last_seen_at)은 그대로 둔다.
psqlq "UPDATE sessions SET last_seen_at = now() WHERE id IN ('$(sid e1b)', '$(sid e2a)', '$(sid a1a)', '$(sid a1b)', '$(sid m1)')" >/dev/null
AUD_W=$(psqlq "SELECT coalesce(max(id), 0) FROM audit_logs")
W_E1B=$(seen_at e1b); W_A1A=$(seen_at a1a); W_A1A_ACT=$(act_seen_at a1a)
T_START=$(date +%s)
: > "$WORK/ticks.txt"
for tick in 0 1 2 3 4 5 6; do
  target=$(( T_START + tick * 30 ))
  now=$(date +%s); [ "$now" -lt "$target" ] && sleep $(( target - now ))
  line="t+$((tick * 30))s"
  for tag in e1b e2a a1a a1b; do
    c=$(call "$tag" GET "$CBASE/api/notifications?unread=1" "" bg)
    line="$line $tag=$c"
  done
  line="$line hb(e1b)=$(call e1b POST "$CBASE/api/pages/$PG_E1/heartbeat" "" bg)"
  line="$line hb(e2a)=$(call e2a POST "$CBASE/api/pages/$PG_E2/heartbeat" "" bg)"
  line="$line m1(표식없음)=$(call m1 GET "$CBASE/api/notifications?unread=1")"
  if [ "$tick" = 3 ]; then
    line="$line | 사람: e2a=$(call e2a GET "$CBASE/api/pages") a1b=$(call a1b GET "$CBASE/api/pages")"
  fi
  printf '    %s\n' "$line" | tee -a "$WORK/ticks.txt"
done
now=$(date +%s); [ "$now" -lt $(( T_START + 185 )) ] && sleep $(( T_START + 185 - now ))
expect "③ 자동 요청만 3분 → e1b 사람 요청 401(로그아웃됨)" "401" "$(call e1b GET "$BASE/api/me")"
expect "③ 그동안 e1b 의 last_seen_at 은 한 번도 밀리지 않았다" "same" "$([ "$(seen_at e1b)" = "$W_E1B" ] && echo same || echo changed)"
expect "④ 같은 3분에 사람 요청 1번 → e2a 로그인 유지(200)" "200" "$(call e2a GET "$BASE/api/me")"
expect "⑤ 보는 중 · 자동 요청만 → a1a 401" "401" "$(call a1a GET "$BASE/api/me")"
expect "⑤ a1a 의 last_seen_at · act_last_seen_at 은 그대로" "same|same" \
  "$([ "$(seen_at a1a)" = "$W_A1A" ] && echo same || echo changed)|$([ "$(act_seen_at a1a)" = "$W_A1A_ACT" ] && echo same || echo changed)"
code=$(call a1b GET "$BASE/api/me")
expect "⑤ 보는 중 · 사람 요청 1번 → a1b 유지(200) · 여전히 보는 중" "200|True" "$code|$(jget "str(d.get('acting'))" < "$WORK/r.body")"
expect "대조군 표식 없는 폴링 → m1 유지(200) — 전과 같은 동작" "200" "$(call m1 GET "$BASE/api/me")"
expect "③ e1b 의 자동 요청도 창이 지난 뒤에는 401 (끝 칸)" "yes" "$(tail -1 "$WORK/ticks.txt" | grep -q 'e1b=401' && echo yes || echo no)"

sub "U-3 ⑦ 감사 — 문장 변화 0 · 3분 창에서 생긴 행은 기존 인증 거부 모양뿐"
KINDS=$(psqlq "SELECT DISTINCT action || '|' || result || '|' || coalesce(reason, '') FROM audit_logs WHERE id > $AUD_W ORDER BY 1" | tr '\n' ' ' | sed 's/ $//')
expect "3분 창 뒤 감사 종류 = auth.required|deny|not_authenticated 하나(만료된 세션의 요청)" "auth.required|deny|not_authenticated" "$KINDS"
expect "  act.expire 0 (보는 중 창은 30분 — 3분 창에서 풀리지 않음)" "0" "$(psqlq "SELECT count(*) FROM audit_logs WHERE id > $AUD_W AND action = 'act.expire'")"
expect "  그 거부 행의 요약 문장 = 쿠키 없는 거부와 같은 틀" "1" \
  "$(psqlq "SELECT count(DISTINCT replace(summary, target_name, '{route}')) FROM audit_logs WHERE action = 'auth.required' AND id > $AUD0")"
fi

# ════════════════════════════════════════════════════════════════
if want browser; then
hr "[C] 헤드리스 브라우저 — tests/browser/qf4_drafts.py (B1-2 ①~⑦ · ⑨ · ⑩)"
E1_PW=$(sudo -n awk -v u="$E1" '$1==u{print $3}' /etc/tbox-v2/seed-accounts.txt 2>/dev/null)
E1_SEC=$(sudo -n awk -v u="$E1" '$1==u{print $2}' /etc/tbox-v2/qa-totp.txt 2>/dev/null)
E2_PW=$(sudo -n awk -v u="$E2" '$1==u{print $3}' /etc/tbox-v2/seed-accounts.txt 2>/dev/null)
E2_SEC=$(sudo -n awk -v u="$E2" '$1==u{print $2}' /etc/tbox-v2/qa-totp.txt 2>/dev/null)
# 앞 절에서 넣은 두 계정의 마지막 TOTP 칸 — 파이썬이 같은 칸을 피한다
py_secret E1_PW E1_SEC E2_PW E2_SEC -- python3 "$ROOT/tests/browser/qf4_drafts.py" "$SITE" "$UA" "$SHOTS" \
  "${LAST_STEP[$E1]:-0}" "${LAST_STEP[$E2]:-0}" > "$WORK/browser.txt" 2>&1
unset E1_PW E1_SEC E2_PW E2_SEC
sed 's/^/    /' "$WORK/browser.txt"
expect "브라우저 실패 0" "0" "$(sed -n 's/^SMOKE_FAIL=//p' "$WORK/browser.txt" | tail -1)"
note "스크린샷: $SHOTS"
fi

sub "인스턴스 로그 — 뜻하지 않은 오류 0"
expect "server.log 에 uncaught 0" "0" "$(grep -ci 'uncaught' "$INST/server.log" | tr -d ' ')"

hr "결과"
printf '통과 %d · 실패 %d · 건너뜀 %d\n' "$PASS" "$FAIL" "$SKIP"
note "임시 DB($DB)·임시 폴더·임시 서버는 이 스크립트가 끝나며 지워진다. 운영에는 아무것도 남지 않는다."
[ "$FAIL" -eq 0 ] || exit 1
exit 0
