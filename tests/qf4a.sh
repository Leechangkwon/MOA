#!/usr/bin/env bash
# tests/qf4a.sh — Q:F-4 라운드 A(화면만) 수락 시험 — 임시 인스턴스 틀
#   (지시서 /var/www/qaredi/docs/qf4-quick-fixes-directive.md 「라운드 A」)
#
#   U-2a  파일 삭제 전 확인 창 + 결과 알림 · 폴더 삭제도 같은 창   → tests/browser/qf4_delete_confirm.py
#   U-5   새로 만들어지는 「시작하기」 문구                         → tests/community/qf4_onboarding.sh
#   P-4a  숨은 탭에서 알림 폴링·편집 heartbeat 멈춤                 → tests/browser/qf4_round_a.py
#   P-5a  한 화면에 GET /tbox/api/me 한 번                         → tests/browser/qf4_round_a.py
#
# 쓰는 법:  bash tests/qf4a.sh [all|delete|onboarding|round] [스크린샷 폴더]
#   all(기본) 은 먼저 **고치기 전 코드(git HEAD)** 로 같은 인스턴스를 띄워 전후 측정의 「전」을 재고,
#   내린 뒤 지금 작업 트리로 다시 띄워 수락 기준을 본다. 스크린샷 폴더를 주지 않으면 mktemp 로 만든다
#   (시험이 끝나도 지우지 않는다 — 사람이 직접 본다).
#
# ★ 운영 DB 를 건드리지 않는다 — stale_modules.sh 와 같은 틀: 운영 DB(tbox_re)는 pg_dump 로 **읽기만**,
#   임시 DB `tbox_qf4a_test` · 포트 8092 · 임시 폴더. 끝나면 서버·임시 DB·임시 폴더를 지운다.
# ★ 계정은 검토 계정 claude_ 만(F-71), 모든 요청 UA `tbox-review/<날짜>`.
# ★ 비밀(비밀번호·OTP 비밀)은 명령 인자에 싣지 않는다 — 파이썬 한 프로세스가 환경 변수로 받는다.
#   세션·CSRF 는 curl 인자가 아니라 600 헤더 파일(`-H @파일`)로만.
# 나가는 값: 0 통과 · 1 실패 · 3 준비물 없음 · 4 인스턴스 실패
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
. "$ROOT/tests/lib/review_accounts.sh" || { echo "review_accounts.sh 를 읽지 못했다 — 시작하지 않는다"; exit 3; }
MODE="${1:-all}"
case "$MODE" in all|delete|onboarding|round) ;; *) echo "쓰는 법: bash tests/qf4a.sh [all|delete|onboarding|round] [스크린샷 폴더]"; exit 3 ;; esac
PORT=8092
SITE="http://127.0.0.1:$PORT"
BASE="$SITE/tbox"
CBASE="$SITE/community"
UA="tbox-review/$(date +%F)"
SRC_DB="tbox_re"            # 운영 — **읽기만** 한다
DB="tbox_qf4a_test"         # 임시 — 여기에만 쓴다
WORK="$(mktemp -d)"
SHOTS="${2:-}"
[ -n "$SHOTS" ] || SHOTS="$(mktemp -d -t qf4a-shots.XXXXXX)"
mkdir -p "$SHOTS"
INST=""
SERVER_PID=""
PASS=0
FAIL=0
SKIP=0
STAMP="$(date +%H%M%S)"

hr()  { printf '\n════════════════════════════════════════════════════════════\n%s\n════════════════════════════════════════════════════════════\n' "$1"; }
sub() { printf '\n── %s\n' "$1"; }
expect() {
  if [ "$2" = "$3" ]; then printf '  ✔ %s  (기대=%s 실제=%s)\n' "$1" "$2" "$3"; PASS=$((PASS+1))
  else printf '  ✖ %s  (기대=%s 실제=%s)\n' "$1" "$2" "$3"; FAIL=$((FAIL+1)); fi
}
note() { printf '  · %s\n' "$1"; }
skip() { printf '  ○ SKIP %s  (%s)\n' "$1" "$2"; SKIP=$((SKIP+1)); }

stop_instance() {
  if [ -n "$SERVER_PID" ]; then kill "$SERVER_PID" 2>/dev/null; wait "$SERVER_PID" 2>/dev/null; SERVER_PID=""; fi
  [ -n "$INST" ] && rm -rf "$INST"
  INST=""
}
cleanup() {
  stop_instance
  sudo -n -u postgres dropdb --if-exists "$DB" >/dev/null 2>&1
  rm -rf "$WORK"
}
trap cleanup EXIT

psqlq() { sudo -n -u postgres psql -d "$DB" -t -A -c "$1" 2>/dev/null; }

jget() {
  python3 -c '
import json,sys
try:
    d = json.load(sys.stdin)
except Exception:
    print(""); sys.exit(0)
try:
    v = eval(sys.argv[1])
except Exception:
    v = ""
print("" if v is None else v)
' "$1" 2>/dev/null
}

# ─── 인스턴스 하나를 띄운다: $1 = 앱 트리(작업 트리 또는 HEAD 를 푼 폴더) ───
start_instance() {
  local src="$1" ready="" _
  INST="$(mktemp -d)"
  mkdir -p "$INST/app" "$INST/storage" "$INST/log"
  tar -C "$src" -cf - --exclude=.git --exclude=jar --exclude=api-test.txt --exclude=./.env . | tar -C "$INST/app" -xf -
  # 임시 `.env` — 원본 `.env` 에서 DB 이름·저장 경로·로그 경로만 바꾼다. 값은 출력하지 않는다.
  # qcheck-allow: R20 — 임시 인스턴스용 사본(0700·0600 · trap 정리) · kill -9 뒤 남는 창은 받아들인 위험(HO-B1-004)
  sed -e "s#^DB_NAME=.*#DB_NAME=$DB#" \
      -e "s#^STORAGE_DIR=.*#STORAGE_DIR=$INST/storage#" \
      -e "s#^AUDIT_LOG_FILE=.*#AUDIT_LOG_FILE=$INST/log/audit.log#" \
      "$ROOT/.env" > "$INST/app/.env"
  chmod 600 "$INST/app/.env"
  grep -q "^DB_NAME=$DB\$" "$INST/app/.env" || { echo "임시 .env 에 임시 DB 이름이 들어가지 않았다 — 중단"; exit 4; }
  local plan
  plan="$(cd "$ROOT/.." && pwd)/community"
  [ -f "$plan/doc-schema.json" ] || plan=/var/www/qaredi/community
  ln -s "$plan" "$INST/community" 2>/dev/null
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
    ready=$(curl -s -o /dev/null -w '%{http_code}' -A "$UA" "$CBASE/api/pages")
    [ "$ready" = "401" ] && break
  done
  expect "임시 인스턴스가 떴다 (미로그인 커뮤니티 창구 401)" "401" "$ready"
  [ "$ready" = "401" ] || { echo "인스턴스를 띄우지 못했다 — 중단"; tail -5 "$INST/server.log"; exit 4; }
}

# ─── 로그인: $1 = 검토 계정. 세션·CSRF 는 $WORK/<계정>.{session,csrf,cookie.hdr,write.hdr} (600) ───
login_as() {
  local who="$1" pw sec out
  review_account "$who" || { echo "  ✖ $who 는 검토 계정이 아니다 — 중단"; exit 3; }
  case "$who" in claude_*) ;; *) echo "  ✖ 새 시험은 claude_ 계정만 쓴다 — 중단"; exit 3 ;; esac
  pw=$(sudo -n awk -v u="$who" '$1==u{print $3}' /etc/tbox-v2/seed-accounts.txt 2>/dev/null)
  sec=$(sudo -n awk -v u="$who" '$1==u{print $2}' /etc/tbox-v2/qa-totp.txt 2>/dev/null)
  [ -n "$pw" ] || { echo "  ✖ $who 비밀번호를 읽지 못했다(sudo) — 중단"; exit 3; }
  out=$(LOGIN_PW="$pw" LOGIN_SEC="$sec" python3 - "$BASE" "$UA" "$who" <<'PY'
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
            m = re.match(r"__Host-tbox_session=([^;]+)", h)
            if m:
                cookie = m.group(1)
    except Exception:
        pass
    if data.get("ok") is True and cookie:
        raw = urllib.request.urlopen(urllib.request.Request(base + "/api/me", headers={"User-Agent": ua, "Cookie": "__Host-tbox_session=" + cookie}), timeout=10).read().decode()
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
  unset pw sec
  local session csrf
  session=$(printf '%s\n' "$out" | sed -n 's/^SESSION=//p')
  csrf=$(printf '%s\n' "$out" | sed -n 's/^CSRF=//p')
  [ -n "$session" ] && [ -n "$csrf" ] || { echo "  ✖ $who 로그인 실패 — 중단"; exit 4; }
  ( umask 077
    printf '%s' "$session" > "$WORK/$who.session"
    printf '%s' "$csrf" > "$WORK/$who.csrf"
    printf 'Cookie: __Host-tbox_session=%s\n' "$session" > "$WORK/$who.cookie.hdr"
    printf 'Cookie: __Host-tbox_session=%s\nX-CSRF-Token: %s\n' "$session" "$csrf" > "$WORK/$who.write.hdr" )
  expect "$who 로그인 — 세션 · CSRF 토큰을 받았다" "yes" "yes"
}

# 업로드 창구로 시험 파일 하나 — $1 계정 $2 파일 이름. 찍는 값: 파일 id
upload_as() {
  local who="$1" name="$2"
  printf 'Q:F-4 라운드 A 시험 파일 %s\n' "$name" > "$WORK/$name"
  curl -s -o "$WORK/up.json" -w '%{http_code}' -A "$UA" -H 'Expect:' -H @"$WORK/$who.write.hdr" \
    -F "file=@$WORK/$name" -F "access_level=internal" "$BASE/api/files" > "$WORK/up.code"
  jget "d.get('id') or d.get('file',{}).get('id')" < "$WORK/up.json"
}

# ════════════════════════════════════════════════════════════════
hr "Q:F-4 라운드 A — 준비 (운영 DB 에는 쓰지 않는다) · 모드 $MODE"

python3 -c 'import playwright' 2>/dev/null || { export PATH="$HOME/.local/bin:$PATH"; }
python3 -c 'import playwright' 2>/dev/null || { echo "playwright 가 없다 — 이 시험은 브라우저가 없으면 성립하지 않는다"; exit 3; }
expect "커뮤니티 부품 버전 표시가 지금 내용과 맞다(--check)" "0" \
  "$(php "$ROOT/scripts/community_asset_version.php" --check > /dev/null 2>&1; echo $?)"
avail_kb=$(df -Pk / | awk 'NR==2{print $4}')
[ "$avail_kb" -ge 1048576 ] || { echo "루트 디스크 여유 1GB 미만 — 시험을 시작하지 않는다 (SECURITY R-80)"; exit 3; }
sudo -n -u postgres psql -d "$SRC_DB" -tAc "SELECT 1" >/dev/null 2>&1 \
  || { echo "psql(postgres) 을 쓸 수 없다 — 이 시험은 임시 DB 가 없으면 성립하지 않는다"; exit 3; }
note "스크린샷 폴더: $SHOTS"

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

# U-5 ③ — 이미 있는 「시작하기」 페이지의 판 번호·고친 시각을 **맨 처음에** 찍어 둔다(끝에 다시 대조)
psqlq "SELECT id || '|' || doc_version || '|' || updated_at FROM community.pages WHERE title = '시작하기' ORDER BY id" > "$WORK/start_before.txt"
note "이미 있는 「시작하기」 페이지 $(wc -l < "$WORK/start_before.txt" | tr -d ' ')장 — 판 번호·고친 시각을 찍어 두었다"

# ════════════════════════════════════════════════════════════════
if [ "$MODE" = "all" ]; then
  hr "전 — 고치기 전 코드(git HEAD $(git -C "$ROOT" rev-parse --short HEAD))로 같은 측정"
  mkdir -p "$WORK/base"
  git -C "$ROOT" archive HEAD | tar -x -C "$WORK/base" \
    || { echo "HEAD 를 풀지 못했다 — 중단"; exit 3; }
  start_instance "$WORK/base"
  login_as claude_employee01
  curl -s -o /dev/null -A "$UA" -H @"$WORK/claude_employee01.cookie.hdr" "$CBASE/api/me/space"
  printf '{"title":"QF4A 시험 페이지 %s"}' "$STAMP" > "$WORK/page.json"
  curl -s -o "$WORK/page.out" -A "$UA" -H 'Content-Type: application/json' -H @"$WORK/claude_employee01.write.hdr" \
    --data-binary @"$WORK/page.json" "$CBASE/api/pages"
  TEST_PAGE=$(jget "d['page']['id']" < "$WORK/page.out")
  expect "시험 페이지를 만들었다(POST /community/api/pages — claude_employee01 개인 공간)" "yes" "$([ -n "$TEST_PAGE" ] && echo yes || echo no)"
  BOARD=$(psqlq "SELECT id FROM community.spaces WHERE kind = 'board' AND is_active AND access_level = 'internal' ORDER BY id LIMIT 1")
  BASE_FILE_NAME="qf4a-base-$STAMP.txt"
  BASE_FILE=$(upload_as claude_employee01 "$BASE_FILE_NAME")
  expect "「전」 측정용 시험 파일을 올렸다(업로드 창구 201)" "201" "$(cat "$WORK/up.code")"
  BASE_FOLDER_NAME="QF4A 전 측정 폴더 $STAMP"
  printf '{"name":"%s","access_level":"internal"}' "$BASE_FOLDER_NAME" > "$WORK/folder.json"
  curl -s -o /dev/null -w '%{http_code}' -A "$UA" -H 'Content-Type: application/json' \
    -H @"$WORK/claude_employee01.write.hdr" --data-binary @"$WORK/folder.json" "$BASE/api/folders" > "$WORK/folder.code"
  expect "「전」 측정용 시험 폴더를 만들었다(POST /api/folders 201)" "201" "$(cat "$WORK/folder.code")"
  SMOKE_E1="$(cat "$WORK/claude_employee01.session")" TEST_PAGE="$TEST_PAGE" BOARD="$BOARD" \
  BASE_FILE="$BASE_FILE" BASE_FILE_NAME="$BASE_FILE_NAME" BASE_FOLDER_NAME="$BASE_FOLDER_NAME" \
    python3 "$ROOT/tests/browser/qf4_round_a.py" "$SITE" "$UA" measure > "$WORK/before.txt" 2>&1
  sed 's/^/    /' "$WORK/before.txt"
  stop_instance
fi

hr "지금 작업 트리로 인스턴스를 띄운다"
start_instance "$ROOT"
login_as claude_employee01
curl -s -o /dev/null -A "$UA" -H @"$WORK/claude_employee01.cookie.hdr" "$CBASE/api/me/space"
if [ -z "${TEST_PAGE:-}" ]; then
  printf '{"title":"QF4A 시험 페이지 %s"}' "$STAMP" > "$WORK/page.json"
  curl -s -o "$WORK/page.out" -A "$UA" -H 'Content-Type: application/json' -H @"$WORK/claude_employee01.write.hdr" \
    --data-binary @"$WORK/page.json" "$CBASE/api/pages"
  TEST_PAGE=$(jget "d['page']['id']" < "$WORK/page.out")
  expect "시험 페이지를 만들었다(POST /community/api/pages — claude_employee01 개인 공간)" "yes" "$([ -n "$TEST_PAGE" ] && echo yes || echo no)"
fi
[ -n "${BOARD:-}" ] || BOARD=$(psqlq "SELECT id FROM community.spaces WHERE kind = 'board' AND is_active AND access_level = 'internal' ORDER BY id LIMIT 1")

# ════════════════════════════════════════════════════════════════
if [ "$MODE" = "all" ] || [ "$MODE" = "delete" ]; then
  hr "U-2a 파일 삭제 전 확인 창 + 결과 알림 · 폴더 삭제도 같은 창 — qf4_delete_confirm.py"
  FILE_NAME="qf4a-delete-$STAMP.txt"
  FILE_ID=$(upload_as claude_employee01 "$FILE_NAME")
  expect "시험 파일을 올렸다(업로드 창구 201 — claude_employee01 자기 파일)" "201" "$(cat "$WORK/up.code")"
  GONE_NAME="qf4a-gone-$STAMP.txt"
  GONE_ID=$(upload_as claude_employee01 "$GONE_NAME")
  expect "거부 흐름용 시험 파일을 올렸다(업로드 창구 201)" "201" "$(cat "$WORK/up.code")"
  FOLDER_NAME="QF4A 시험 폴더 $STAMP"
  printf '{"name":"%s","access_level":"internal"}' "$FOLDER_NAME" > "$WORK/folder.json"
  curl -s -o "$WORK/folder.out" -w '%{http_code}' -A "$UA" -H 'Content-Type: application/json' \
    -H @"$WORK/claude_employee01.write.hdr" --data-binary @"$WORK/folder.json" "$BASE/api/folders" > "$WORK/folder.code"
  FOLDER_ID=$(jget "d.get('id') or d.get('folder',{}).get('id')" < "$WORK/folder.out")
  expect "시험 폴더를 만들었다(POST /api/folders 201)" "201" "$(cat "$WORK/folder.code")"

  SMOKE_E1="$(cat "$WORK/claude_employee01.session")" SMOKE_CSRF="$(cat "$WORK/claude_employee01.csrf")" \
  FILE_ID="$FILE_ID" FILE_NAME="$FILE_NAME" GONE_ID="$GONE_ID" GONE_NAME="$GONE_NAME" \
  FOLDER_ID="$FOLDER_ID" FOLDER_NAME="$FOLDER_NAME" SHOTS="$SHOTS" \
    python3 "$ROOT/tests/browser/qf4_delete_confirm.py" "$SITE" "$UA" > "$WORK/delete.txt" 2>&1
  sed 's/^/    /' "$WORK/delete.txt"
  expect "브라우저 실패 0 (qf4_delete_confirm.py)" "0" "$(sed -n 's/^SMOKE_FAIL=//p' "$WORK/delete.txt" | tail -1)"

  sub "DB 로 결과 대조 (임시 DB)"
  expect "확인 뒤 지운 파일은 휴지통에 있다(deleted_at 있음)" "t" "$(psqlq "SELECT deleted_at IS NOT NULL FROM files WHERE public_id = '$FILE_ID'")"
  expect "그 파일의 file.delete allow 감사 1건" "1" "$(psqlq "SELECT count(*) FROM audit_logs WHERE action = 'file.delete' AND result = 'allow' AND target_id = (SELECT id FROM files WHERE public_id = '$FILE_ID')")"
  expect "시험 폴더는 지워졌다" "0" "$(psqlq "SELECT count(*) FROM folders WHERE id = ${FOLDER_ID:-0}")"
fi

# ════════════════════════════════════════════════════════════════
if [ "$MODE" = "all" ] || [ "$MODE" = "onboarding" ]; then
  hr "U-5 새로 만들어지는 「시작하기」 — qf4_onboarding.sh"
  # 개인 공간이 **없는** 검토 계정을 고른다(임시 DB 에서 읽기만). 없으면 이 절은 성립하지 않는다 — 지어내지 않는다.
  NEW_WHO=""
  for cand in claude_employee02 claude_manager01 claude_guest01 claude_admin01; do
    has=$(psqlq "SELECT count(*) FROM community.spaces s JOIN users u ON u.id = s.owner_user_id WHERE s.kind = 'personal' AND u.username = '$cand'")
    active=$(psqlq "SELECT count(*) FROM users WHERE username = '$cand' AND is_active")
    note "$cand — 개인 공간 ${has:-?}개 · 활성 ${active:-?}"
    if [ "$has" = "0" ] && [ "$active" = "1" ] && [ -z "$NEW_WHO" ]; then NEW_WHO="$cand"; fi
  done
  if [ -z "$NEW_WHO" ]; then
    skip "U-5 ① 새 계정 첫 진입" "개인 공간이 없는 검토 계정이 임시 DB 에 없다"
  else
    login_as "$NEW_WHO"
    QF4_DB="$DB" QF4_CBASE="$CBASE" QF4_SITE="$SITE" QF4_UA="$UA" QF4_ROOT="$ROOT" QF4_SHOTS="$SHOTS" \
    QF4_NEW_WHO="$NEW_WHO" QF4_NEW_HDR="$WORK/$NEW_WHO.cookie.hdr" QF4_NEW_SESSION="$(cat "$WORK/$NEW_WHO.session")" \
    QF4_OLD_HDR="$WORK/claude_employee01.cookie.hdr" \
      bash "$ROOT/tests/community/qf4_onboarding.sh" > "$WORK/onboarding.txt" 2>&1
    sed 's/^/    /' "$WORK/onboarding.txt"
    expect "U-5 실패 0 (qf4_onboarding.sh)" "0" "$(sed -n 's/^ONB_FAIL=//p' "$WORK/onboarding.txt" | tail -1)"
  fi
fi

# ════════════════════════════════════════════════════════════════
if [ "$MODE" = "all" ] || [ "$MODE" = "round" ]; then
  hr "P-4a 숨은 탭 · P-5a /api/me 한 번 — qf4_round_a.py"
  login_as claude_admin01
  ADMIN_PW=$(sudo -n awk -v u="claude_admin01" '$1==u{print $3}' /etc/tbox-v2/seed-accounts.txt 2>/dev/null)
  [ -n "$BOARD" ] || note "사내 게시판이 임시 DB 에 없다 — 게시판 화면 측정·재인증 흐름은 SKIP 으로 나온다"
  SMOKE_E1="$(cat "$WORK/claude_employee01.session")" SMOKE_A1="$(cat "$WORK/claude_admin01.session")" \
  ADMIN_PW="$ADMIN_PW" TEST_PAGE="$TEST_PAGE" BOARD="$BOARD" SHOTS="$SHOTS" BOARD_NAME="QF4A 시험 게시판 $STAMP" \
    python3 "$ROOT/tests/browser/qf4_round_a.py" "$SITE" "$UA" check > "$WORK/round.txt" 2>&1
  unset ADMIN_PW
  sed 's/^/    /' "$WORK/round.txt"
  expect "브라우저 실패 0 (qf4_round_a.py)" "0" "$(sed -n 's/^SMOKE_FAIL=//p' "$WORK/round.txt" | tail -1)"
fi

# ════════════════════════════════════════════════════════════════
hr "U-5 ③ — 이미 있던 「시작하기」 페이지는 그대로인가 (처음 찍은 값과 대조)"
psqlq "SELECT id || '|' || doc_version || '|' || updated_at FROM community.pages WHERE title = '시작하기' AND id IN ($(cut -d'|' -f1 "$WORK/start_before.txt" | paste -sd, - | sed 's/^$/0/')) ORDER BY id" > "$WORK/start_after.txt"
expect "이미 있던 「시작하기」 페이지의 doc_version·updated_at 불변(전 $(wc -l < "$WORK/start_before.txt" | tr -d ' ')장)" \
  "$(md5sum < "$WORK/start_before.txt" | cut -c1-12)" "$(md5sum < "$WORK/start_after.txt" | cut -c1-12)"
expect "인스턴스 로그에 uncaught 0" "0" "$(grep -ci 'uncaught' "$INST/server.log" 2>/dev/null | tr -d ' ' || echo 0)"

if [ "$MODE" = "all" ] && [ -f "$WORK/before.txt" ]; then
  hr "전후 측정표 (같은 임시 DB · 같은 계정 · 같은 화면)"
  printf '  %-58s %8s %8s\n' "항목" "전" "후"
  for key in $(sed -n 's/^MEASURE \([^=]*\)=.*/\1/p' "$WORK/before.txt"); do
    b=$(sed -n "s/^MEASURE $key=//p" "$WORK/before.txt" | tail -1)
    a=$(cat "$WORK/delete.txt" "$WORK/round.txt" 2>/dev/null | sed -n "s/^MEASURE $key=//p" | tail -1)
    printf '  %-58s %8s %8s\n' "$key" "$b" "${a:--}"
  done
fi

hr "결과"
printf '통과 %d · 실패 %d · 건너뜀 %d\n' "$PASS" "$FAIL" "$SKIP"
note "스크린샷: $SHOTS (지우지 않는다 — 직접 본다)"
note "임시 DB($DB)·임시 폴더·임시 서버는 이 스크립트가 끝나며 지워진다. 운영에는 아무것도 남지 않는다."
[ "$FAIL" -eq 0 ] || exit 1
# 건너뛴 항목이 있으면 초록이 아니다 — U-5 ① 은 개인 공간 없는 검토 계정이 있어야 선다(독립 검토 Q:F-4 A L-5)
[ "$SKIP" -eq 0 ] || { note "건너뜀 $SKIP 건 — 통과로 세지 않는다(종료 코드 2)"; exit 2; }
exit 0
