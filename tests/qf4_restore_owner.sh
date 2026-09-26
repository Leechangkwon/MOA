#!/usr/bin/env bash
# tests/qf4_restore_owner.sh — Q:F-4 U-2b 「본인이 지운 파일은 본인이 되살린다」 수락 시험 — 임시 인스턴스 틀
#   (지시서 /var/www/qaredi/docs/qf4-quick-fixes-directive.md 라운드 B 「U-2b」, t-box PRD 5.4 각주 ¹ · F-11 · 8장,
#    ARCH 7.4 `file.restore` 두 줄 · `auth.gate|deny|reauth_required`)
#
#   ① claude_employee01 이 자기 파일 삭제 → 「내 휴지통」에 보임 → 복구 200 + 감사 granted_by owner
#   ② claude_employee02 의 목록 응답 원문에 그 파일 0건 · 복구 시도 404 (없는 파일과 같은 상태·본문·감사)
#   ③ claude_manager01(같은 팀) 복구 404 · Manager(또는 Admin)가 지운 employee01 의 파일을 employee01 이 복구 → 404
#   ④ Admin 은 전처럼 전부(재인증 뒤) · 재인증 없으면 403 next:reauth / 화면 302 — 전과 같다
#   ⑤ 30일 배치가 지운(purged_at) 파일 404 — 임시 DB 에서만 그 열을 채워 흉내 낸다
#   ⑥ 폴더가 지워진 파일(루트로 옮겨진 휴지통 파일)의 복구 규칙은 기존과 같다 — 루트로 되살아난다
#   ⑦ 본인 휴지통은 재인증 없이(세션 reauth_at 이 빈 채로 목록·화면·복구 200)
#   + 화면: 비관리자에게 「내 휴지통」 링크·화면 · 라이트/다크/390px 스크린샷 · pageerror·console.error 0
#           → tests/browser/qf4_restore_owner.py
#
# 쓰는 법:  bash tests/qf4_restore_owner.sh [스크린샷 폴더]
#   스크린샷 폴더를 주지 않으면 mktemp 로 만든다(시험이 끝나도 지우지 않는다 — 사람이 직접 본다).
#
# ★ 운영 DB 를 건드리지 않는다 — tests/community/stale_modules.sh 와 같은 틀: 운영 DB(tbox_re)는 pg_dump 로
#   **읽기만**(파이프 — /tmp 에 덤프 파일 0), 임시 DB `tbox_qf4u2b_test` · 포트 8101 · 임시 폴더.
#   끝나면 서버·임시 DB·임시 폴더를 지운다.
# ★ 계정은 검토 계정 claude_ 만(F-71), 모든 요청 UA `tbox-review/<날짜>`.
# ★ 비밀(비밀번호·OTP 비밀)은 명령 인자에 싣지 않는다 — 로그인은 파이썬 한 프로세스가 환경 변수로,
#   재인증 본문은 tests/lib/secrets.sh 의 curl_json_secret(표준입력)로. 세션·CSRF 는 600 헤더 파일(`-H @파일`)로만.
# 나가는 값: 0 통과 · 1 실패 · 3 준비물 없음 · 4 인스턴스 실패
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
. "$ROOT/tests/lib/review_accounts.sh" || { echo "review_accounts.sh 를 읽지 못했다 — 시작하지 않는다"; exit 3; }
. "$ROOT/tests/lib/secrets.sh" || { echo "tests/lib/secrets.sh 를 읽지 못했다 — 시작하지 않는다 (R-62)"; exit 3; }
PORT=8101
SITE="http://127.0.0.1:$PORT"
BASE="$SITE/tbox"
CBASE="$SITE/community"
UA="tbox-review/$(date +%F)"
SRC_DB="tbox_re"            # 운영 — **읽기만** 한다
DB="tbox_qf4u2b_test"       # 임시 — 여기에만 쓴다
WORK="$(mktemp -d)"
SHOTS="${1:-}"
[ -n "$SHOTS" ] || SHOTS="$(mktemp -d -t qf4u2b-shots.XXXXXX)"
mkdir -p "$SHOTS"
INST=""
SERVER_PID=""
PASS=0
FAIL=0
SKIP=0
STAMP="$(date +%H%M%S)"
E1=claude_employee01
E2=claude_employee02
M1=claude_manager01
A1=claude_admin01
G1=claude_guest01

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
# 임시 DB 에만 쓴다 — ⑤ purged_at 흉내 (DB 이름이 임시 이름인지 다시 본다)
psqlw() {
  [ "$DB" = "tbox_qf4u2b_test" ] || { echo "  ✖ 임시 DB 가 아닌 곳에 쓰려 했다 — 중단"; exit 4; }
  sudo -n -u postgres psql -d "$DB" -v ON_ERROR_STOP=1 -q -c "$1" 2>&1 | grep -v 'could not change directory'
}

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

# ─── 인스턴스 ───────────────────────────────────────────────────
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
    ready=$(curl -s -o /dev/null -w '%{http_code}' -A "$UA" "$BASE/api/files/trash")
    [ "$ready" = "401" ] && break
  done
  expect "임시 인스턴스가 떴다 (미로그인 GET /tbox/api/files/trash → 401)" "401" "$ready"
  [ "$ready" = "401" ] || { echo "인스턴스를 띄우지 못했다 — 중단"; tail -5 "$INST/server.log"; exit 4; }
}

# ─── 로그인: $1 = 검토 계정, $2 = optional 이면 실패해도 멈추지 않는다(돌려주는 값 1) ───
#     세션·CSRF 는 $WORK/<계정>.{session,cookie.hdr,write.hdr} (600) 에만 남는다.
login_as() {
  local who="$1" optional="${2:-}" pw sec out
  review_account "$who" || { echo "  ✖ $who 는 검토 계정이 아니다 — 중단"; exit 3; }
  case "$who" in claude_*) ;; *) echo "  ✖ 새 시험은 claude_ 계정만 쓴다 — 중단"; exit 3 ;; esac
  pw=$(sudo -n awk -v u="$who" '$1==u{print $3}' /etc/tbox-v2/seed-accounts.txt 2>/dev/null)
  sec=$(sudo -n awk -v u="$who" '$1==u{print $2}' /etc/tbox-v2/qa-totp.txt 2>/dev/null)
  if [ -z "$pw" ]; then
    [ "$optional" = optional ] && return 1
    echo "  ✖ $who 비밀번호를 읽지 못했다(sudo) — 중단"; exit 3
  fi
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
  if [ -z "$session" ] || [ -z "$csrf" ]; then
    [ "$optional" = optional ] && return 1
    echo "  ✖ $who 로그인 실패 — 중단"; exit 4
  fi
  ( umask 077
    printf '%s' "$session" > "$WORK/$who.session"
    printf 'Cookie: __Host-tbox_session=%s\n' "$session" > "$WORK/$who.cookie.hdr"
    printf 'Cookie: __Host-tbox_session=%s\nX-CSRF-Token: %s\n' "$session" "$csrf" > "$WORK/$who.write.hdr" )
  expect "$who 로그인 — 세션 · CSRF 토큰을 받았다" "yes" "yes"
  return 0
}

# 창구 부르기 — $1 계정 $2 메서드 $3 경로(/tbox 뒤) [$4 JSON 본문 파일]. 본문은 $WORK/r.body, 머리는 $WORK/r.head.
# 찍는 값: HTTP 상태
api() {
  local who="$1" method="$2" path="$3" bodyf="${4:-}"
  if [ -n "$bodyf" ]; then
    curl -s -D "$WORK/r.head" -o "$WORK/r.body" -w '%{http_code}' -A "$UA" -X "$method" -H 'Expect:' \
      -H 'Content-Type: application/json' -H @"$WORK/$who.write.hdr" --data-binary @"$bodyf" "$BASE$path"
  else
    curl -s -D "$WORK/r.head" -o "$WORK/r.body" -w '%{http_code}' -A "$UA" -X "$method" -H 'Expect:' \
      -H @"$WORK/$who.write.hdr" "$BASE$path"
  fi
}
restore_as() { api "$1" POST "/api/files/$2/restore" "$WORK/empty.json"; }
ctype() { tr -d '\r' < "$WORK/r.head" | sed -n 's/^[Cc]ontent-[Tt]ype: //p' | head -1; }

# 업로드 창구로 시험 파일 하나 — $1 계정 $2 파일 이름 $3 등급 [$4 team_id] [$5 folder_id]. 찍는 값: 파일 UUID
upload_as() {
  local who="$1" name="$2" level="$3" team="${4:-}" folder="${5:-}" extra=()
  printf 'Q:F-4 U-2b 시험 파일 %s\n' "$name" > "$WORK/$name"
  [ -n "$team" ] && extra+=(-F "team_id=$team")
  [ -n "$folder" ] && extra+=(-F "folder_id=$folder")
  curl -s -o "$WORK/up.json" -w '%{http_code}' -A "$UA" -H 'Expect:' -H @"$WORK/$who.write.hdr" \
    -F "file=@$WORK/$name" -F "access_level=$level" "${extra[@]}" "$BASE/api/files" > "$WORK/up.code"
  jget "d.get('id')" < "$WORK/up.json"
}
# 응답 원문에서 글자 그대로 몇 번 나오나 (JSON 을 풀지 않고 **원문**으로 센다 — 화면이 아니라 응답에 없어야 한다)
raw_count() { grep -o -F -- "$1" "$WORK/r.body" | wc -l | tr -d ' '; }

# ════════════════════════════════════════════════════════════════
hr "Q:F-4 U-2b — 준비 (운영 DB 에는 쓰지 않는다)"

python3 -c 'import playwright' 2>/dev/null || { export PATH="$HOME/.local/bin:$PATH"; }
python3 -c 'import playwright' 2>/dev/null || { echo "playwright 가 없다 — 이 시험은 브라우저가 없으면 성립하지 않는다"; exit 3; }
avail_kb=$(df -Pk / | awk 'NR==2{print $4}')
[ "$avail_kb" -ge 1048576 ] || { echo "루트 디스크 여유 1GB 미만 — 시험을 시작하지 않는다 (SECURITY R-80)"; exit 3; }
sudo -n -u postgres psql -d "$SRC_DB" -tAc "SELECT 1" >/dev/null 2>&1 \
  || { echo "psql(postgres) 을 쓸 수 없다 — 이 시험은 임시 DB 가 없으면 성립하지 않는다"; exit 3; }
note "스크린샷 폴더: $SHOTS"
printf '{}' > "$WORK/empty.json"

sub "운영 DB 를 **읽어** 임시 DB 를 만든다 (파이프 — 덤프 파일 0)"
sudo -n -u postgres dropdb --if-exists "$DB" >/dev/null 2>&1
sudo -n -u postgres createdb -O tbox_owner "$DB" >/dev/null 2>&1 || { echo "임시 DB 를 만들 수 없다"; exit 3; }
set -o pipefail
sudo -n -u postgres pg_dump -d "$SRC_DB" 2>/dev/null \
  | sudo -n -u postgres psql -q -d "$DB" -v ON_ERROR_STOP=0 >/dev/null 2>"$WORK/restore.err" \
  || { set +o pipefail; echo "운영 DB 를 읽어 임시 DB 로 옮기지 못했다 (pg_dump | psql)"; exit 3; }
set +o pipefail
sudo -n -u postgres psql -q -d "$DB" -v ON_ERROR_STOP=1 -f - < "$ROOT/db/community/grants.sql" 2>&1 \
  | grep -v 'could not change directory' | sed 's/^/    /'
n_files=$(psqlq "SELECT count(*) FROM files")
[ -n "$n_files" ] || { echo "임시 DB 로 옮기지 못했다 — $(head -2 "$WORK/restore.err")"; exit 3; }
note "임시 DB $DB — files ${n_files}행"

sub "스키마·계정 관계 확인 (임시 DB)"
expect "files.deleted_by 열이 있다 (지운 사람 칸 — 없으면 이 기능이 성립하지 않는다)" "1" \
  "$(psqlq "SELECT count(*) FROM information_schema.columns WHERE table_name='files' AND column_name='deleted_by'")"
expect "files.purged_at 열이 있다" "1" \
  "$(psqlq "SELECT count(*) FROM information_schema.columns WHERE table_name='files' AND column_name='purged_at'")"
psqlq "SELECT username || ' ' || role || ' team=' || coalesce(team_id::text,'-') || ' active=' || is_active
         FROM users WHERE username IN ('$E1','$E2','$M1','$A1','$G1') ORDER BY username" | sed 's/^/    /'
E1_ID=$(psqlq "SELECT id FROM users WHERE username='$E1'")
E2_ID=$(psqlq "SELECT id FROM users WHERE username='$E2'")
M1_ID=$(psqlq "SELECT id FROM users WHERE username='$M1'")
A1_ID=$(psqlq "SELECT id FROM users WHERE username='$A1'")
E1_TEAM=$(psqlq "SELECT team_id FROM users WHERE username='$E1'")
M1_TEAM=$(psqlq "SELECT team_id FROM users WHERE username='$M1'")
[ -n "$E1_ID" ] && [ -n "$E2_ID" ] && [ -n "$M1_ID" ] && [ -n "$A1_ID" ] || { echo "  ✖ 검토 계정 넷이 임시 DB 에 없다 — 중단"; exit 3; }
if [ -n "$E1_TEAM" ] && [ "$E1_TEAM" = "$M1_TEAM" ]; then
  note "$M1 과 $E1 은 같은 팀(team_id=$E1_TEAM)"
  SAME_TEAM=yes
else
  note "$M1(team=$M1_TEAM) 과 $E1(team=$E1_TEAM) 은 같은 팀이 아니다 — ③ 의 「Manager 가 지운」 갈래는 Admin 이 지운 경우로 본다"
  SAME_TEAM=no
fi

sub "지금 작업 트리로 인스턴스를 띄운다"
start_instance "$ROOT"
# 운영에서 옮겨 온 옛 감사 행과 섞이지 않게 — 이 시험이 남긴 행만 본다(id > AUD0)
AUD0=$(psqlq "SELECT coalesce(max(id),0) FROM audit_logs")
note "감사 기준 id $AUD0 (이 뒤의 행만 이 시험의 것)"

sub "로그인 (비밀은 환경 변수로만)"
login_as "$E1"; login_as "$E2"; login_as "$M1"; login_as "$A1"
HAVE_GUEST=no
if login_as "$G1" optional; then HAVE_GUEST=yes; else note "$G1 로그인 불가 — Guest 갈래는 건너뛴다"; fi

session_reauth() { psqlq "SELECT coalesce(reauth_at::text,'NULL') FROM sessions WHERE user_id=$1 ORDER BY created_at DESC LIMIT 1"; }
expect "⑦ 준비 — $E1 의 세션은 재인증한 적이 없다(reauth_at NULL)" "NULL" "$(session_reauth "$E1_ID")"
expect "④ 준비 — $A1 의 세션도 아직 재인증 전(reauth_at NULL)" "NULL" "$(session_reauth "$A1_ID")"

# 없는 파일(무작위 UUID) 복구의 **모양** — 남의 파일 복구와 견줄 기준
RANDOM_ID=$(python3 -c 'import uuid;print(uuid.uuid4())')
code=$(restore_as "$E2" "$RANDOM_ID")
NF_CODE="$code"; NF_BODY="$(cat "$WORK/r.body")"; NF_CTYPE="$(ctype)"
note "기준 — 없는 UUID 복구: $NF_CODE · $NF_BODY · $NF_CTYPE"
expect "기준 — 없는 파일 복구는 404" "404" "$NF_CODE"

# ════════════════════════════════════════════════════════════════
hr "① $E1 이 자기 파일을 지우고 「내 휴지통」에서 되살린다 — 재인증 없이(⑦)"
A_NAME="u2b-A-$STAMP.txt"
A_ID=$(upload_as "$E1" "$A_NAME" internal)
expect "A 올리기 201" "201" "$(cat "$WORK/up.code")"
expect "A 지우기 200 (DELETE /api/files/{id})" "200" "$(api "$E1" DELETE "/api/files/$A_ID")"
expect "A 의 deleted_by = $E1 (지운 사람 칸이 실제로 기록된다)" "$E1_ID" "$(psqlq "SELECT deleted_by FROM files WHERE public_id='$A_ID'")"
expect "① $E1 GET /api/files/trash → 200 (재인증 없이)" "200" "$(api "$E1" GET /api/files/trash)"
expect "① 목록 원문에 A 의 UUID 1번" "1" "$(raw_count "$A_ID")"
cp "$WORK/r.body" "$WORK/e1_list.json"
OWN_ONLY=$(python3 -c '
import json,sys
d=json.load(open(sys.argv[1]))
fs=d.get("files",[])
print("yes" if all(f.get("uploaded_by")==sys.argv[2] and f.get("deleted_by")==sys.argv[2] for f in fs) else "no")' "$WORK/e1_list.json" "$E1")
expect "① 목록의 모든 행이 「올린 사람 = 지운 사람 = $E1」" "yes" "$OWN_ONLY"
E1_DB_TOTAL=$(psqlq "SELECT count(*) FROM files WHERE uploaded_by=$E1_ID AND deleted_by=$E1_ID AND deleted_at IS NOT NULL AND purged_at IS NULL")
expect "① 목록 total = DB 의 (uploaded_by=나 AND deleted_by=나 AND 휴지통) 수" "$E1_DB_TOTAL" "$(jget "d.get('total')" < "$WORK/e1_list.json")"
code=$(curl -s -o "$WORK/page.html" -w '%{http_code}' -A "$UA" -H @"$WORK/$E1.cookie.hdr" "$BASE/trash")
expect "① $E1 GET /trash → 200 (302 재인증 아님)" "200" "$code"
expect "① 화면 제목 「내 휴지통」" "1" "$(grep -c '<h1 class="page-head__title">내 휴지통</h1>' "$WORK/page.html")"
expect "① 화면 표에 「올린 사람」 칸 없음(본인 휴지통)" "0" "$(grep -c '<th scope="col">올린 사람</th>' "$WORK/page.html")"
expect "① 화면 표 tbody 에 data-trash-own" "1" "$(grep -c 'id="trash-body" data-trash-own' "$WORK/page.html")"
expect "① A 복구 200" "200" "$(restore_as "$E1" "$A_ID")"
expect "① A 가 살아났다(deleted_at·deleted_by NULL)" "NULL|NULL" \
  "$(psqlq "SELECT coalesce(deleted_at::text,'NULL')||'|'||coalesce(deleted_by::text,'NULL') FROM files WHERE public_id='$A_ID'")"
AUD_A=$(psqlq "SELECT detail->>'granted_by' || '|' || coalesce(target_name,'') || '|' || actor_name || '|' || (detail->>'route')
                 FROM audit_logs WHERE action='file.restore' AND result='allow'
                  AND target_id=(SELECT id FROM files WHERE public_id='$A_ID') ORDER BY id DESC LIMIT 1")
expect "① 감사 file.restore allow — granted_by owner · 대상 이름 · 행위자 · 라우트 패턴" \
  "owner|$A_NAME|$E1|POST /api/files/{id}/restore" "$AUD_A"
expect "⑦ 복구 뒤에도 $E1 세션은 재인증 없음(reauth_at NULL)" "NULL" "$(session_reauth "$E1_ID")"
code=$(curl -s -o "$WORK/r.body" -w '%{http_code}' -A "$UA" -X POST -H 'Expect:' -H 'Content-Type: application/json' \
  -H @"$WORK/$E1.cookie.hdr" --data-binary @"$WORK/empty.json" "$BASE/api/files/$A_ID/restore")
expect "① CSRF 토큰 없는 복구 요청 → 403 (라우터 [3] 그대로)" "403" "$code"
AUD_RG=$(psqlq "SELECT count(*) FROM audit_logs WHERE id > $AUD0 AND action='auth.gate' AND reason='reauth_required' AND actor_name='$E1'")
expect "⑦ $E1 의 auth.gate reauth_required 기록 0 (본인 휴지통은 묻지 않는다)" "0" "$AUD_RG"

# ════════════════════════════════════════════════════════════════
hr "② $E2 — 목록 원문에 남의 파일 0건 · 복구 404 는 없는 파일과 같은 모양"
B_NAME="u2b-B-$STAMP.txt"
if [ -n "$E1_TEAM" ]; then B_ID=$(upload_as "$E1" "$B_NAME" team "$E1_TEAM"); B_LEVEL=team; else B_ID=$(upload_as "$E1" "$B_NAME" internal); B_LEVEL=internal; fi
expect "B($B_LEVEL) 올리기 201" "201" "$(cat "$WORK/up.code")"
expect "B 지우기 200 ($E1 본인)" "200" "$(api "$E1" DELETE "/api/files/$B_ID")"
expect "② $E2 GET /api/files/trash → 200" "200" "$(api "$E2" GET /api/files/trash)"
cp "$WORK/r.body" "$WORK/e2_list.json"
expect "② $E2 목록 원문에 B 의 UUID 0번" "0" "$(raw_count "$B_ID")"
expect "② $E2 목록 원문에 B 의 이름 0번" "0" "$(raw_count "$B_NAME")"
expect "② $E2 목록 원문에 「$E1」 0번(남의 행이 한 줄도 없다)" "0" "$(raw_count "\"$E1\"")"
OWN2=$(python3 -c '
import json,sys
d=json.load(open(sys.argv[1]))
fs=d.get("files",[])
print("yes" if all(f.get("uploaded_by")==sys.argv[2] and f.get("deleted_by")==sys.argv[2] for f in fs) else "no")' "$WORK/e2_list.json" "$E2")
expect "② $E2 목록의 모든 행이 「올린 사람 = 지운 사람 = $E2」" "yes" "$OWN2"
code=$(restore_as "$E2" "$B_ID")
expect "② $E2 가 B 복구 → 404" "404" "$code"
expect "② 404 본문이 없는 파일과 바이트까지 같다" "$NF_BODY" "$(cat "$WORK/r.body")"
expect "② 404 Content-Type 이 없는 파일과 같다" "$NF_CTYPE" "$(ctype)"
expect "② B 는 여전히 휴지통(복구되지 않았다)" "1" "$(psqlq "SELECT count(*) FROM files WHERE public_id='$B_ID' AND deleted_at IS NOT NULL")"
AUD2=$(psqlq "SELECT reason||'|'||coalesce(target_type,'-')||'|'||coalesce(target_id::text,'-')||'|'||coalesce(target_name,'-')||'|'||summary
                FROM audit_logs WHERE id > $AUD0 AND action='file.restore' AND result='deny' AND actor_name='$E2' ORDER BY id DESC LIMIT 2")
note "$E2 의 file.restore deny 두 줄(남의 파일 · 없는 파일):"; printf '%s\n' "$AUD2" | sed 's/^/      /'
expect "② 감사 두 줄(남의 파일 · 없는 파일)이 글자까지 같다" "1" "$(printf '%s\n' "$AUD2" | sort -u | wc -l | tr -d ' ')"
expect "② 감사 사유는 not_found_in_trash · 대상 이름 없음" "not_found_in_trash|file|-|-" "$(printf '%s\n' "$AUD2" | head -1 | cut -d'|' -f1-4)"
expect "② $E2 GET /api/me/activity → 200" "200" "$(api "$E2" GET /api/me/activity)"
ACT2=$(python3 -c '
import json,sys
d=json.load(open(sys.argv[1]))
rows=[r for r in (d.get("items") or d.get("rows") or d.get("activity") or []) if r.get("action")=="file.restore"][:2]
print(len(rows), len({(r.get("summary"), r.get("target_name"), r.get("reason")) for r in rows}))' "$WORK/r.body")
expect "② 「내 활동」의 두 복구 거부 줄도 같은 글(2줄 · 서로 다른 모양 1가지)" "2 1" "$ACT2"
expect "② 「내 활동」 원문에 B 의 이름 0번" "0" "$(raw_count "$B_NAME")"

# ════════════════════════════════════════════════════════════════
hr "③ $M1(같은 팀) 복구 404 · 남이 지운 내 파일은 본인도 404"
expect "③ $M1 GET /api/files/trash → 200" "200" "$(api "$M1" GET /api/files/trash)"
expect "③ $M1 목록 원문에 B 0번(팀원 파일도 안 보인다)" "0" "$(raw_count "$B_ID")"
code=$(restore_as "$M1" "$B_ID")
expect "③ $M1 이 B(팀원이 스스로 지운 것) 복구 → 404" "404" "$code"
expect "③ 404 본문이 없는 파일과 같다" "$NF_BODY" "$(cat "$WORK/r.body")"

C_NAME="u2b-C-$STAMP.txt"
if [ "$SAME_TEAM" = yes ]; then
  C_ID=$(upload_as "$E1" "$C_NAME" team "$E1_TEAM")
  expect "C(team) 올리기 201" "201" "$(cat "$WORK/up.code")"
  code=$(api "$M1" DELETE "/api/files/$C_ID")
  if [ "$code" = "200" ]; then
    expect "③ $M1 이 팀원 $E1 의 C 를 지웠다(team_scope) 200" "200" "$code"
    expect "③ C 의 deleted_by = $M1" "$M1_ID" "$(psqlq "SELECT deleted_by FROM files WHERE public_id='$C_ID'")"
    C_BY="$M1"
  else
    note "$M1 이 C 를 지우지 못했다($code) — Admin 이 지운 D 갈래로만 본다"
    C_ID=""
  fi
else
  C_ID=""
fi
if [ -n "$C_ID" ]; then
  expect "③ $E1 GET /api/files/trash — C 0번(남이 지운 내 파일)" "0" "$(api "$E1" GET /api/files/trash >/dev/null; raw_count "$C_ID")"
  code=$(restore_as "$E1" "$C_ID")
  expect "③ $E1 이 Manager 가 지운 자기 파일 C 복구 → 404" "404" "$code"
  expect "③ 404 본문이 없는 파일과 같다" "$NF_BODY" "$(cat "$WORK/r.body")"
  code=$(restore_as "$M1" "$C_ID")
  expect "③ 지운 $M1 도 C 복구 → 404 (올린 사람이 아니다)" "404" "$code"
  expect "③ $M1 목록 원문에 C 0번" "0" "$(api "$M1" GET /api/files/trash >/dev/null; raw_count "$C_ID")"
else
  skip "③ Manager 가 지운 갈래" "같은 팀이 아니거나 Manager 삭제 불가 — 아래 D(Admin 이 지움)로 같은 규칙을 본다"
fi

D_NAME="u2b-D-$STAMP.txt"
D_ID=$(upload_as "$E1" "$D_NAME" internal)
expect "D 올리기 201" "201" "$(cat "$WORK/up.code")"
expect "③ $A1 이 $E1 의 D 를 지웠다 200 (파일 삭제는 재인증 대상이 아니다)" "200" "$(api "$A1" DELETE "/api/files/$D_ID")"
expect "③ D 의 deleted_by = $A1" "$A1_ID" "$(psqlq "SELECT deleted_by FROM files WHERE public_id='$D_ID'")"
expect "③ $E1 목록 원문에 D 0번(관리자가 지운 내 파일)" "0" "$(api "$E1" GET /api/files/trash >/dev/null; raw_count "$D_ID")"
code=$(restore_as "$E1" "$D_ID")
expect "③ $E1 이 Admin 이 지운 자기 파일 D 복구 → 404" "404" "$code"
expect "③ 404 본문이 없는 파일과 같다" "$NF_BODY" "$(cat "$WORK/r.body")"

if [ "$HAVE_GUEST" = yes ]; then
  expect "Guest $G1 GET /api/files/trash → 200" "200" "$(api "$G1" GET /api/files/trash)"
  expect "Guest 목록 total 0 (올릴 수 없는 역할 — SQL FALSE)" "0" "$(jget "d.get('total')" < "$WORK/r.body")"
  expect "Guest 가 B 복구 → 404 · 같은 본문" "404|$NF_BODY" "$(restore_as "$G1" "$B_ID")|$(cat "$WORK/r.body")"
else
  skip "Guest 갈래" "claude_guest01 로그인 불가"
fi

# ════════════════════════════════════════════════════════════════
hr "④ Admin — 재인증 없으면 전과 같이 막히고, 재인증 뒤에는 전부"
code=$(api "$A1" GET /api/files/trash)
expect "④ 재인증 전 $A1 GET /api/files/trash → 403" "403" "$code"
expect "④ 본문 next:reauth" "reauth" "$(jget "d.get('next')" < "$WORK/r.body")"
code=$(curl -s -o /dev/null -D "$WORK/r.head" -w '%{http_code}' -A "$UA" -H @"$WORK/$A1.cookie.hdr" "$BASE/trash")
LOC=$(tr -d '\r' < "$WORK/r.head" | sed -n 's/^[Ll]ocation: //p' | head -1)
expect "④ 재인증 전 $A1 GET /trash → 302 /tbox/reauth?to=trash" "302 /tbox/reauth?to=trash" "$code $LOC"
code=$(restore_as "$A1" "$RANDOM_ID")
expect "④ 재인증 전 $A1 복구(없는 UUID) → 403 next:reauth (행을 찾기 전에 막는다 — 전과 같은 순서)" "403 reauth" "$code $(jget "d.get('next')" < "$WORK/r.body")"
code=$(restore_as "$A1" "$B_ID")
expect "④ 재인증 전 $A1 복구(휴지통의 B) → 403 next:reauth" "403 reauth" "$code $(jget "d.get('next')" < "$WORK/r.body")"
AUDG=$(psqlq "SELECT string_agg(DISTINCT (detail->>'route') || '|' || coalesce(target_name,''), ',' ORDER BY (detail->>'route') || '|' || coalesce(target_name,''))
                FROM audit_logs WHERE id > $AUD0 AND action='auth.gate' AND result='deny' AND reason='reauth_required' AND actor_name='$A1'")
expect "④ 감사 auth.gate deny reauth_required — 세 창구 라우트 패턴 · 대상 본인" \
  "GET /api/files/trash|$A1,GET /trash|$A1,POST /api/files/{id}/restore|$A1" "$AUDG"
A1_PW=$(sudo -n awk -v u="$A1" '$1==u{print $3}' /etc/tbox-v2/seed-accounts.txt 2>/dev/null)
printf -v REAUTH_BODY '{"password":"%s"}' "$A1_PW"
unset A1_PW
code=$(curl_json_secret REAUTH_BODY -s -o "$WORK/r.body" -w '%{http_code}' -A "$UA" -H 'Expect:' \
  -H 'Content-Type: application/json' -H @"$WORK/$A1.write.hdr" "$BASE/api/me/reauth")
unset REAUTH_BODY
expect "④ $A1 재인증 200 (POST /api/me/reauth — 비밀번호는 표준입력으로)" "200" "$code"
expect "④ 재인증 뒤 $A1 GET /api/files/trash → 200" "200" "$(api "$A1" GET /api/files/trash)"
cp "$WORK/r.body" "$WORK/a1_list.json"
A1_TOTAL=$(jget "d.get('total')" < "$WORK/a1_list.json")
ALL_DB=$(psqlq "SELECT count(*) FROM files WHERE deleted_at IS NOT NULL AND purged_at IS NULL")
expect "④ Admin 목록 total = DB 휴지통 전체 수 (범위 조건 없음)" "$ALL_DB" "$A1_TOTAL"
expect "④ Admin 목록 첫 쪽에 B·D 가 보인다(남의 파일 전부)" "1 1" "$(raw_count "$B_ID") $(raw_count "$D_ID")"
code=$(curl -s -o "$WORK/page.html" -w '%{http_code}' -A "$UA" -H @"$WORK/$A1.cookie.hdr" "$BASE/trash")
expect "④ 재인증 뒤 $A1 GET /trash → 200 · 제목 「휴지통」 · 올린 사람 칸 있음" "200 1 1" \
  "$code $(grep -c '<h1 class="page-head__title">휴지통</h1>' "$WORK/page.html") $(grep -c '<th scope="col">올린 사람</th>' "$WORK/page.html")"
code=$(restore_as "$A1" "$D_ID")
expect "④ Admin 이 D(남의 파일, Admin 이 지움) 복구 200" "200" "$code"
if [ -n "$C_ID" ]; then
  expect "④ Admin 이 C(Manager 가 지운 팀원 파일) 복구 200" "200" "$(restore_as "$A1" "$C_ID")"
fi
AUD_D=$(psqlq "SELECT detail->>'granted_by' FROM audit_logs WHERE action='file.restore' AND result='allow'
                 AND target_id=(SELECT id FROM files WHERE public_id='$D_ID') ORDER BY id DESC LIMIT 1")
expect "④ 감사 granted_by admin_all" "admin_all" "$AUD_D"

# ════════════════════════════════════════════════════════════════
hr "⑤ 30일 배치가 지운(purged_at) 파일 — 임시 DB 에서만 흉내"
E_NAME="u2b-E-$STAMP.txt"
E_ID=$(upload_as "$E1" "$E_NAME" internal)
expect "E 올리기 201" "201" "$(cat "$WORK/up.code")"
expect "E 지우기 200" "200" "$(api "$E1" DELETE "/api/files/$E_ID")"
psqlw "UPDATE files SET purged_at = now() WHERE public_id = '$E_ID' AND deleted_at IS NOT NULL" | sed 's/^/    /'
expect "⑤ (임시 DB) E 의 purged_at 을 채웠다" "1" "$(psqlq "SELECT count(*) FROM files WHERE public_id='$E_ID' AND purged_at IS NOT NULL")"
expect "⑤ $E1 목록 원문에 E 0번" "0" "$(api "$E1" GET /api/files/trash >/dev/null; raw_count "$E_ID")"
code=$(restore_as "$E1" "$E_ID")
expect "⑤ $E1 이 E 복구 → 404 · 같은 본문" "404|$NF_BODY" "$code|$(cat "$WORK/r.body")"
code=$(restore_as "$A1" "$E_ID")
expect "⑤ Admin 도 E 복구 → 404 (기존 규칙)" "404" "$code"

# ════════════════════════════════════════════════════════════════
hr "⑥ 폴더가 지워진 휴지통 파일 — 복구 규칙은 기존과 같다(루트로)"
printf '{"name":"U2B 폴더 %s","access_level":"internal"}' "$STAMP" > "$WORK/folder.json"
code=$(api "$E1" POST /api/folders "$WORK/folder.json")
F_ID=$(jget "d['folder']['id']" < "$WORK/r.body")
expect "⑥ $E1 폴더 만들기 201" "201" "$code"
G_NAME="u2b-G-$STAMP.txt"
G_ID=$(upload_as "$E1" "$G_NAME" internal "" "$F_ID")
expect "⑥ 폴더 안에 G 올리기 201" "201" "$(cat "$WORK/up.code")"
expect "⑥ G 지우기 200" "200" "$(api "$E1" DELETE "/api/files/$G_ID")"
expect "⑥ 휴지통 파일만 남은 폴더를 확인 삭제(?move_trashed=1) 200" "200" "$(api "$E1" DELETE "/api/folders/$F_ID?move_trashed=1")"
expect "⑥ G 는 루트로 옮겨진 채 휴지통(folder_id NULL · deleted_at 있음)" "NULL|yes" \
  "$(psqlq "SELECT coalesce(folder_id::text,'NULL')||'|'||CASE WHEN deleted_at IS NOT NULL THEN 'yes' ELSE 'no' END FROM files WHERE public_id='$G_ID'")"
expect "⑥ $E1 목록에 G 1번" "1" "$(api "$E1" GET /api/files/trash >/dev/null; raw_count "$G_ID")"
expect "⑥ $E1 G 복구 200" "200" "$(restore_as "$E1" "$G_ID")"
expect "⑥ G 가 루트로 되살아났다(folder_id NULL · deleted_at NULL)" "NULL|NULL" \
  "$(psqlq "SELECT coalesce(folder_id::text,'NULL')||'|'||coalesce(deleted_at::text,'NULL') FROM files WHERE public_id='$G_ID'")"

# ════════════════════════════════════════════════════════════════
hr "② 마무리 — 남이 못 되살린 B 는 올린 본인이 되살린다(404 는 「없어서」가 아니었다)"
expect "B 복구 200 ($E1 본인)" "200" "$(restore_as "$E1" "$B_ID")"

# ════════════════════════════════════════════════════════════════
hr "화면 — tests/browser/qf4_restore_owner.py (라이트·다크·390px · pageerror·console.error 0)"
H_NAME="u2b-H-$STAMP.txt"
H_ID=$(upload_as "$E1" "$H_NAME" internal)
expect "(화면 준비) H 올리기 201" "201" "$(cat "$WORK/up.code")"
expect "(화면 준비) H 지우기 200 — 내 휴지통에서 누를 파일" "200" "$(api "$E1" DELETE "/api/files/$H_ID")"
I_NAME="u2b-I-$STAMP.txt"
I_ID=$(upload_as "$E1" "$I_NAME" internal)
expect "(화면 준비) I 올리기 201" "201" "$(cat "$WORK/up.code")"
expect "(화면 준비) I 는 $A1 이 지운다 — 내 휴지통에 보이면 안 되는 파일" "200" "$(api "$A1" DELETE "/api/files/$I_ID")"
SMOKE_E1="$(cat "$WORK/$E1.session")" SMOKE_A1="$(cat "$WORK/$A1.session")" \
OWN_ID="$H_ID" OWN_NAME="$H_NAME" OTHER_NAME="$I_NAME" SHOTS="$SHOTS" \
  python3 "$ROOT/tests/browser/qf4_restore_owner.py" "$SITE" "$UA" > "$WORK/browser.txt" 2>&1
sed 's/^/    /' "$WORK/browser.txt"
expect "브라우저 실패 0 (qf4_restore_owner.py)" "0" "$(sed -n 's/^SMOKE_FAIL=//p' "$WORK/browser.txt" | tail -1)"
expect "(화면) H 가 화면의 복구 단추로 살아났다" "NULL" "$(psqlq "SELECT coalesce(deleted_at::text,'NULL') FROM files WHERE public_id='$H_ID'")"
ls -1 "$SHOTS" | sed 's/^/    스크린샷 /'

expect "인스턴스 로그에 PHP Fatal·Warning·uncaught 0" "0" \
  "$(grep -ciE 'fatal|warning|uncaught' "$INST/server.log" 2>/dev/null | tr -d ' ' || echo 0)"

hr "결과"
printf '통과 %d · 실패 %d · 건너뜀 %d\n' "$PASS" "$FAIL" "$SKIP"
note "임시 DB($DB)·임시 폴더·임시 서버는 이 스크립트가 끝나며 지워진다. 운영에는 아무것도 남지 않는다. 스크린샷: $SHOTS"
[ "$FAIL" -eq 0 ] || exit 1
exit 0
