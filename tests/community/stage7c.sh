#!/usr/bin/env bash
# tests/community/stage7c.sh — 7-C 수락 기준 C:T-180 ~ C:T-186 실행 (임베드 허용 목록 — C:F-32 · T-33 · 결정 J-4 · K-1~K-6)
#
#   C:T-180 허용 목록  Admin 만(재인증) · 호스트 모양(https·경로·포트·IP·와일드카드·사용자 정보·비 ASCII 400) · 소문자로 · 20 한도 · 빼기
#   C:T-181 저장       https · 모양 · 목록 밖 **새** 임베드 400 embed_not_allowed(+감사) · 새 페이지 첫 본문
#   C:T-182 옛 임베드  목록에서 뺀 호스트의 옛 임베드는 저장을 막지 않는다 · 새로 넣기·되돌리기는 400
#   C:T-183 그리기     세 갈래(틀 · 허용 대기 · 목록 밖) · sandbox="" · 모양이 아닌 주소는 틀을 만들지 않는다
#   C:T-184 CSP 스위치 기본 끔 · 켜면 페이지 화면만 목록 호스트로 frame-src(임시 인스턴스 복사본에서만 켠다)
#   C:T-185 브라우저   browser_7c.py — 임베드 창 · 읽기 카드 · 관리자 창 · 렌더러 JS 동등성
#   C:T-186 권한·단계  앱 계정 DELETE 불가 · 단계 7 · mention 은 8
#
# ★ 운영 DB 를 건드리지 않는다 — stage7b.sh 와 같은 틀(임시 DB `tbox_7c_test`, 포트 8097).
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
. "$ROOT/tests/lib/secrets.sh" || { echo "tests/lib/secrets.sh 를 읽지 못했다 — 시험을 시작하지 않는다 (R-62)"; exit 3; }
PORT=8097
SITE="http://127.0.0.1:$PORT"
BASE="$SITE/tbox"
CBASE="$SITE/community"
UA="tbox-review/$(date +%F)"
SRC_DB="tbox_re"          # 운영 — **읽기만** 한다
DB="tbox_7c_test"         # 임시 — 여기에만 쓴다
JAR_DIR="$(mktemp -d)"
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
contains() {
  case "$3" in
    *"$2"*) printf '  ✔ %s  (「%s」 포함)\n' "$1" "$2"; PASS=$((PASS+1)) ;;
    *)      printf '  ✖ %s  (「%s」 없음 — 실제: %s)\n' "$1" "$2" "$3"; FAIL=$((FAIL+1)) ;;
  esac
}
lacks() {
  case "$3" in
    *"$2"*) printf '  ✖ %s  (「%s」 가 응답에 있다 — 실제: %s)\n' "$1" "$2" "$3"; FAIL=$((FAIL+1)) ;;
    *)      printf '  ✔ %s  (「%s」 없음)\n' "$1" "$2"; PASS=$((PASS+1)) ;;
  esac
}
note() { printf '  · %s\n' "$1"; }
skip() { printf '  ○ SKIP %s  (%s)\n' "$1" "$2"; SKIP=$((SKIP+1)); }

cleanup() {
  if [ -n "$SERVER_PID" ]; then kill "$SERVER_PID" 2>/dev/null; wait "$SERVER_PID" 2>/dev/null; fi
  sudo -n -u postgres dropdb --if-exists "$DB" >/dev/null 2>&1
  [ -n "$INST" ] && rm -rf "$INST"
  rm -rf "$JAR_DIR" "$WORK"
}
trap cleanup EXIT

# ─── 임시 DB 에만 쓰는 psql (운영 DB 이름은 여기에 들어가지 않는다) ───
psqlq() { sudo -n -u postgres psql -d "$DB" -t -A -c "$1" 2>/dev/null; }
mark()  { psqlq "SELECT coalesce(max(id),0) FROM audit_logs"; }

# 앱 계정(tbox_app)으로 한 문장 — C:T-59 전용. 값은 임시 .env 에서 읽고 출력하지 않는다.
appq() {
  local u p n h
  u=$(sed -n 's/^DB_USER=//p' "$INST/app/.env"); p=$(sed -n 's/^DB_PASS=//p' "$INST/app/.env")
  n=$(sed -n 's/^DB_NAME=//p' "$INST/app/.env"); h=$(sed -n 's/^DB_HOST=//p' "$INST/app/.env")
  [ -n "$u" ] || return 2
  PGPASSWORD="$p" psql -h "$h" -U "$u" -d "$n" -v VERBOSITY=verbose -t -A -c "$1" 2>&1 | head -1
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

status_of() { sed -n 's#^HTTP/[0-9.]* \([0-9]*\).*#\1#p' "$1" | tail -1 | tr -d '\r'; }

review_password() { sudo -n awk -v u="$1" '$1==u{print $3}' /etc/tbox-v2/seed-accounts.txt 2>/dev/null; }
review_secret()   { sudo -n awk -v u="$1" '$1==u{print $2}' /etc/tbox-v2/qa-totp.txt 2>/dev/null; }

login() {
  local who="$1" jar="$JAR_DIR/$1.jar" pw sec raw code left out try body
  pw=$(review_password "$who")
  sec=$(review_secret "$who")
  [ -n "$pw" ] || return 2
  for try in 1 2 3; do
    if [ -n "$sec" ]; then
      raw=$(totp_now sec 2>/dev/null)
      code=${raw%%$'\n'*}
      # 비밀번호가 든 본문은 인자가 아니라 표준입력으로 보낸다(R-62 · tests/lib/secrets.sh). 보내는 바이트는 같다.
      printf -v body '{"username":"%s","password":"%s","otp":"%s"}' "$who" "$pw" "$code"
      out=$(curl_json_secret body -s -c "$jar" -A "$UA" -H 'Content-Type: application/json' "$BASE/api/login")
    else
      printf -v body '{"username":"%s","password":"%s"}' "$who" "$pw"
      out=$(curl_json_secret body -s -c "$jar" -A "$UA" -H 'Content-Type: application/json' "$BASE/api/login")
    fi
    case "$out" in
      *'"ok":true'*)
        curl -s -b "$jar" -A "$UA" "$BASE/api/me" \
          | sed -n 's/.*"csrf_token":"\([0-9a-f]*\)".*/\1/p'
        return 0 ;;
    esac
    left=$(printf '%s' "$raw" | sed -n 's/^남은 시간: \([0-9]*\)초.*/\1/p')
    [ -n "$left" ] || left=31
    printf '  · %s 로그인 재시도 — 다음 코드 창까지 %s초\n' "$who" "$left" >&2
    sleep $((left + 1))
  done
  return 1
}

# 커뮤니티 창구 호출 — $1 메서드 $2 경로 $3 계정 $4 본문 파일(없으면 생략)
capi() {
  local method="$1" path="$2" who="$3" bodyfile="${4:-}"
  local jar="$JAR_DIR/$who.jar" csrf
  eval "csrf=\${CSRF_${who}:-}"
  if [ -z "$bodyfile" ]; then
    curl -s -D "$WORK/h" -o "$WORK/b" -A "$UA" -b "$jar" -X "$method" \
      -H 'Expect:' -H "X-CSRF-Token: $csrf" "$CBASE$path"
  else
    curl -s -D "$WORK/h" -o "$WORK/b" -A "$UA" -b "$jar" -X "$method" \
      -H 'Expect:' -H 'Content-Type: application/json' -H "X-CSRF-Token: $csrf" \
      --data-binary "@$bodyfile" "$CBASE$path"
  fi
  status_of "$WORK/h"
}

body() { printf '%s' "$1" > "$WORK/body.json"; }

make_page() {   # $1 계정 $2 제목 [$3 부모 id] [$4 공간 번호]
  python3 - "$2" "${3:-}" "${4:-}" > "$WORK/new.json" <<'PY'
import json, sys
b = {"title": sys.argv[1]}
if sys.argv[2]:
    b["parent_id"] = sys.argv[2]
if sys.argv[3]:
    b["space_id"] = int(sys.argv[3])
print(json.dumps(b, ensure_ascii=False))
PY
  capi POST /api/pages "$1" "$WORK/new.json" > /dev/null
  jget "d['page']['id']" < "$WORK/b"
}

share() {       # $1 주는 계정 $2 페이지 $3 받는 계정 $4 등급 → 상태 코드
  body "{\"level\":\"$4\"}"
  capi PUT "/api/pages/$2/shares/$3" "$1" "$WORK/body.json"
}

unshare() {     # $1 빼는 계정 $2 페이지 $3 빠질 계정 → 상태 코드
  capi DELETE "/api/pages/$2/shares/$3" "$1"
}

audit_n() {     # $1 기준 id $2 action $3 result $4 reason(없으면 '')
  if [ -z "$4" ]; then
    psqlq "SELECT count(*) FROM audit_logs WHERE id > ${1:-0} AND action='$2' AND result='$3'"
  else
    psqlq "SELECT count(*) FROM audit_logs WHERE id > ${1:-0} AND action='$2' AND result='$3' AND reason='$4'"
  fi
}

pg_page() {     # $1 public_id $2 칸 식
  psqlq "SELECT $2 FROM community.pages p WHERE p.public_id = '$1'"
}

# ════════════════════════════════════════════════════════════════
hr "7-C — 임시 인스턴스 준비 (운영 DB 에는 쓰지 않는다)"

avail_kb=$(df -Pk / | awk 'NR==2{print $4}')
echo "루트 파티션 여유: $(df -Ph / | awk 'NR==2{print $4}') (${avail_kb}KB)"
[ "$avail_kb" -ge 1048576 ] || { echo "루트 디스크 여유 1GB 미만 — 시험을 시작하지 않는다 (SECURITY R-80)"; exit 3; }

sudo -n -u postgres psql -d "$SRC_DB" -tAc "SELECT 1" >/dev/null 2>&1 \
  || { echo "psql(postgres) 을 쓸 수 없다 — 이 시험은 임시 DB 가 없으면 성립하지 않는다"; exit 3; }

sub "① 운영 DB 를 **읽어** 임시 DB 를 만든다"
sudo -n -u postgres dropdb --if-exists "$DB" >/dev/null 2>&1
sudo -n -u postgres createdb -O tbox_owner "$DB" >/dev/null 2>&1 \
  || { echo "임시 DB 를 만들 수 없다"; exit 3; }
# 대기열 2d(2026-09-24) — 운영 사본을 /tmp 파일로 두지 않고 파이프로 바로 넣는다(파일이 잠시라도 남지 않게)
sudo -n -u postgres pg_dump -d "$SRC_DB" 2>/dev/null \
  | sudo -n -u postgres psql -q -d "$DB" -v ON_ERROR_STOP=0 >/dev/null 2>"$WORK/restore.err"
DUMP_PS=("${PIPESTATUS[@]}")
[ "${DUMP_PS[0]}" -eq 0 ] || { echo "운영 DB 를 읽지 못했다 (pg_dump)"; exit 3; }
n_users=$(psqlq "SELECT count(*) FROM public.users")
n_pages=$(psqlq "SELECT count(*) FROM community.pages")
[ -n "$n_users" ] && [ -n "$n_pages" ] \
  || { echo "임시 DB 로 옮기지 못했다 — $(head -2 "$WORK/restore.err")"; exit 3; }
echo "  · 임시 DB $DB — 계정 ${n_users}명 · 페이지 ${n_pages}장"

sub "② 마이그레이션 + 권한 (임시 DB 에만) — 앞 단계(이미 있으면 그대로) · 5-C · 5-D (5-E 는 새 표가 없다)"
for mig in 2026-09-23_community_page_properties.sql 2026-09-23_community_owner_share.sql \
           2026-09-23_users_display_name.sql 2026-09-24_community_boards.sql \
           2026-09-24_community_discussion.sql 2026-09-25_community_databases.sql 2026-09-25_community_databases.sql 2026-09-26_community_purge.sql 2026-09-27_community_templates.sql 2026-09-28_community_embeds.sql 2026-09-28_community_embeds.sql; do
  echo "    ▸ $mig"
  sudo -n -u postgres psql -d "$DB" -v ON_ERROR_STOP=1 -f - < "$ROOT/db/migrations/$mig" 2>&1 \
    | grep -v 'could not change directory' | sed 's/^/      /'
done
sudo -n -u postgres psql -q -d "$DB" -v ON_ERROR_STOP=1 -f - < "$ROOT/db/community/grants.sql" 2>&1 \
  | grep -v 'could not change directory' | sed 's/^/    /'

sub "③④ 저장소를 임시 폴더로 복사하고 두 번째 인스턴스를 띄운다"
INST="$(mktemp -d)"
mkdir -p "$INST/app" "$INST/storage" "$INST/log"
tar -C "$ROOT" -cf - --exclude=.git --exclude=jar --exclude=api-test.txt --exclude=./.env . | tar -C "$INST/app" -xf -
# 임시 `.env` — 원본 `.env` 에서 **DB 이름·저장 경로·로그 경로만** 바꾼다. 값은 출력하지 않는다.
sed -e "s#^DB_NAME=.*#DB_NAME=$DB#" \
    -e "s#^STORAGE_DIR=.*#STORAGE_DIR=$INST/storage#" \
    -e "s#^AUDIT_LOG_FILE=.*#AUDIT_LOG_FILE=$INST/log/audit.log#" \
    "$ROOT/.env" > "$INST/app/.env"
chmod 600 "$INST/app/.env"
grep -q "^DB_NAME=$DB\$" "$INST/app/.env" || { echo "임시 .env 에 임시 DB 이름이 들어가지 않았다 — 중단"; exit 4; }
# 회귀 스크립트 일부가 `$ROOT/../community/doc-schema.json` 을 읽는다 — 기획 폴더를 **읽기 전용 링크**로 둔다.
PLAN_DIR="${PLAN_OVERRIDE:-$(cd "$ROOT/.." && pwd)/community}"   # 운영 반영 전에는 새 doc-schema 가 든 사본 폴더를 넘긴다
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

sub "로그인 (claude_ 검토 계정 다섯 — **순차**, F-71)"
for who in claude_employee01 claude_employee02 claude_manager01 claude_admin01 claude_guest01; do
  tok=$(login "$who")
  if [ -z "$tok" ]; then echo "  ✖ $who 로그인 실패 — 중단"; exit 4; fi
  eval "CSRF_${who}='$tok'"
  echo "  ✔ $who"
done
# 개인 공간을 연다(없으면 만든다) — 그 뒤의 목록·인계가 공간을 전제로 한다.
for who in claude_employee01 claude_employee02 claude_manager01 claude_admin01; do capi GET /api/me/space "$who" > /dev/null; done
TEAM1=$(psqlq "SELECT team_id FROM users WHERE username='claude_employee01'")
TEAM2=$(psqlq "SELECT team_id FROM users WHERE username='claude_employee02'")
note "팀: claude_employee01·claude_manager01=$TEAM1 · claude_employee02=$TEAM2"
DEAD=$(psqlq "SELECT username FROM public.users WHERE is_active = false ORDER BY id LIMIT 1")
note "비활성 계정: ${DEAD:-없음}"

# ════════════════════════════════════════════════════════════════

# t-box 창구 호출 — capi 와 같지만 t-box 주소(/tbox) 로 간다. $1 메서드 $2 경로 $3 계정 $4 본문 파일
tapi() {
  local method="$1" path="$2" who="$3" bodyfile="${4:-}"
  local jar="$JAR_DIR/$who.jar" csrf
  eval "csrf=\${CSRF_${who}:-}"
  if [ -z "$bodyfile" ]; then
    curl -s -D "$WORK/h" -o "$WORK/b" -A "$UA" -b "$jar" -X "$method" \
      -H 'Expect:' -H "X-CSRF-Token: $csrf" "$BASE$path"
  else
    curl -s -D "$WORK/h" -o "$WORK/b" -A "$UA" -b "$jar" -X "$method" \
      -H 'Expect:' -H 'Content-Type: application/json' -H "X-CSRF-Token: $csrf" \
      --data-binary "@$bodyfile" "$BASE$path"
  fi
  status_of "$WORK/h"
}
# 화면(HTML) 한 장 — 본문은 $WORK/html
page_html() {   # $1 주소(SITE 기준 경로) $2 계정
  curl -s -o "$WORK/html" -w '%{http_code}' -A "$UA" -b "$JAR_DIR/$2.jar" "$SITE$1"
}
uid() { psqlq "SELECT id FROM users WHERE username='$1'"; }
reauth_admin() {
  local pw rc
  pw=$(review_password claude_admin01)
  # 비밀번호는 표준입력 첫 줄로 파이썬에(py_secret) — 명령 인자에 싣지 않는다(R-62). 파이썬이 만든 본문(끝 줄바꿈까지)은
  # 파일을 거치지 않고 파이프로 curl 의 --data-binary @- 에 간다 — 옛 「파일 → @파일」 과 보내는 바이트가 같다.
  rc=$(py_secret pw -- python3 -c "import json,sys;print(json.dumps({'password':sys.stdin.readline().rstrip('\n')}))" \
       | curl -s -o /dev/null -w '%{http_code}' -b "$JAR_DIR/claude_admin01.jar" -A "$UA" -H 'Content-Type: application/json' \
        -H "X-CSRF-Token: $CSRF_claude_admin01" --data-binary @- "$BASE/api/me/reauth")
  printf '%s' "$rc"
}


# ─── 5-C 도우미 ───────────────────────────────────────────────
bid() { psqlq "SELECT id FROM community.spaces WHERE kind='board' AND name='$1'"; }
# JSON 본문은 **파이썬이** 만든다(셸 따옴표에 한글·특수 글자가 섞이지 않게). $1 = 파이썬 식
jbody() { python3 -c "import json;print(json.dumps($1, ensure_ascii=False))" > "$WORK/body.json"; }
new_post() {    # $1 계정 $2 게시판 번호 $3 제목 [$4 태그 파이썬 목록] → 새 글 id (실패면 빈 글자)
  jbody "{'title': '''$3''', 'tags': ${4:-[]}}"
  capi POST "/api/boards/$2/pages" "$1" "$WORK/body.json" > "$WORK/st"
  jget "d['page']['id']" < "$WORK/b"
}
publish() {     # $1 계정 $2 페이지 $3 게시판 번호 → 상태 코드
  jbody "{'board_id': $3}"
  capi POST "/api/pages/$2/publish" "$1" "$WORK/body.json"
}
put_tags() {    # $1 계정 $2 페이지 $3 파이썬 목록 → 상태 코드
  jbody "{'tags': $3}"
  capi PUT "/api/pages/$2/tags" "$1" "$WORK/body.json"
}
patch_title() { # $1 계정 $2 페이지 $3 제목 → 상태 코드 (판 번호는 지금 값을 psql 로 읽어 보낸다)
  local v; v=$(pg_page "$2" "p.doc_version")
  jbody "{'title': '''$3''', 'doc_version': ${v:-1}}"
  capi PATCH "/api/pages/$2" "$1" "$WORK/body.json"
}
views_of() { psqlq "SELECT count(*) FROM community.page_views v JOIN community.pages p ON p.id=v.page_id WHERE p.public_id='$1'"; }


# ─── 5-D 도우미 ───────────────────────────────────────────────
comment() {     # $1 계정 $2 페이지 $3 파이썬 식(dict) → 상태 코드 · 새 댓글 id 는 $WORK/cid
  jbody "$3"
  local st; st=$(capi POST "/api/pages/$2/comments" "$1" "$WORK/body.json")
  jget "d['comment']['id']" < "$WORK/b" > "$WORK/cid"
  printf '%s' "$st"
}
cid() { cat "$WORK/cid"; }
notes_of() {    # $1 계정 [$2 kind] → 알림 수(psql)
  if [ -z "${2:-}" ]; then psqlq "SELECT count(*) FROM community.notifications n JOIN users u ON u.id=n.user_id WHERE u.username='$1'"
  else psqlq "SELECT count(*) FROM community.notifications n JOIN users u ON u.id=n.user_id WHERE u.username='$1' AND n.kind='$2'"; fi
}
set_vis() { capi PATCH "/api/pages/$2/visibility" "$1" <(printf '{"visibility":"%s"}' "$3") > /dev/null; }

B_NOTICE=$(bid '공지사항'); B_FREE=$(bid '자유게시판')

# 문서 저장 — $1 계정 $2 페이지 $3 블록 목록 파이썬 식 → 상태 코드 (판 번호는 지금 값을 psql 로)
# ─── 6-A 도우미 ───────────────────────────────────────────────
mkdb() {        # $1 계정 $2 제목 [$3 부모] → "DB페이지id DBid"
  jbody "{'title': '''$2''', 'database': True$( [ -n "${3:-}" ] && printf ", 'parent_id': '%s'" "$3")}"
  capi POST /api/pages "$1" "$WORK/body.json" > "$WORK/st"
  printf '%s %s' "$(jget "d['page']['id']" < "$WORK/b")" "$(jget "d['page']['database']['id']" < "$WORK/b")"
}
schema() {      # $1 계정 $2 DB id $3 파이썬 식(본문) → 상태 코드
  jbody "$3"
  capi PATCH "/api/databases/$2/schema" "$1" "$WORK/body.json"
}
addprop() {     # $1 계정 $2 DB id $3 이름 $4 종류 [$5 선택지 파이썬 목록] → 새 key
  if [ -n "${5:-}" ]; then schema "$1" "$2" "{'add': {'name': '''$3''', 'type': '$4', 'options': $5}}" > "$WORK/st"
  else schema "$1" "$2" "{'add': {'name': '''$3''', 'type': '$4'}}" > "$WORK/st"; fi
  jget "[p['key'] for p in d['database']['schema'] if p['name']=='''$3'''][0]" < "$WORK/b"
}
newrow() {      # $1 계정 $2 DB 페이지 $3 제목 [$4 값 파이썬 dict] → 상태 코드 · 새 행 id 는 $WORK/rid
  jbody "{'title': '''$3''', 'parent_id': '$2'$( [ -n "${4:-}" ] && printf ", 'properties': %s" "$4")}"
  local st; st=$(capi POST /api/pages "$1" "$WORK/body.json")
  jget "d['page']['id']" < "$WORK/b" > "$WORK/rid"
  printf '%s' "$st"
}
rid() { cat "$WORK/rid"; }
setv() {        # $1 계정 $2 행 $3 값 파이썬 dict → 상태 코드
  jbody "{'values': $3}"
  capi PATCH "/api/pages/$2/values" "$1" "$WORK/body.json"
}
rows() {        # $1 계정 $2 DB id [$3 filter JSON] [$4 sort JSON] → 상태 코드
  local q=""
  [ -n "${3:-}" ] && q="?filter=$(python3 -c 'import sys,urllib.parse;print(urllib.parse.quote(sys.argv[1]))' "$3")"
  [ -n "${4:-}" ] && q="${q:-?}${q:+&}sort=$(python3 -c 'import sys,urllib.parse;print(urllib.parse.quote(sys.argv[1]))' "$4")"
  capi GET "/api/databases/$2/rows$q" "$1"
}

# ─── 7-C 도우미 ───────────────────────────────────────────────
# 문서 저장 — $1 계정 $2 페이지 $3 블록 목록 파이썬 식 → 상태 코드 (판 번호는 지금 값을 psql 로)
put_doc() {
  local v; v=$(pg_page "$2" "p.doc_version")
  python3 - "$3" "${v:-1}" > "$WORK/body.json" <<'PY'
import json, sys, uuid
def u(): return str(uuid.uuid4())
def seg(t): return [{"t": t, "m": {}}] if t else []
def P(t): return {"id": u(), "type": "paragraph", "text": seg(t)}
def EMB(url, cap=""):
    b = {"id": u(), "type": "embed", "url": url}
    if cap: b["caption"] = seg(cap)
    return b
blocks = eval(sys.argv[1])
print(json.dumps({"doc": {"schema": 1, "blocks": blocks}, "doc_version": int(sys.argv[2])}, ensure_ascii=False))
PY
  capi PATCH "/api/pages/$2" "$1" "$WORK/body.json"
}
errs() { jget "' '.join(e['reason'] for e in d.get('errors', []))" < "$WORK/b"; }
reason_of() { jget "d.get('reason','')" < "$WORK/b"; }
dom_add() {     # $1 계정 $2 호스트 → 상태 코드 · 새 줄 번호는 $WORK/did
  jbody "{'host': '''$2'''}"
  local st; st=$(capi POST /api/embed-domains "$1" "$WORK/body.json")
  jget "d['domain']['id']" < "$WORK/b" > "$WORK/did"
  printf '%s' "$st"
}
did() { cat "$WORK/did"; }
YT='https://www.youtube.com/embed/c7c-a'

# ════════════════════════════════════════════════════════════════
hr "C:T-180  허용 목록 — Admin 만 · 재인증 · 호스트 모양 · 한도 20"
expect "직원 목록 → 200 · 비어 있음 · 관리 못 함 · 틀 닫힘" "200 0 False False" \
  "$(capi GET /api/embed-domains claude_employee01) $(jget "len(d['domains'])" < "$WORK/b") $(jget "d['can_manage']" < "$WORK/b") $(jget "d['frames']" < "$WORK/b")"
expect "직원이 더하기 → 403(정책 role:Admin)" "403" "$(dom_add claude_employee01 www.youtube.com)"
# BF-0009 — 아래 「감사 embed.domain_add allow」 는 이번 실행의 행만 센다. 경계 m0 는 그 행을 남기는 관리자 더하기(아래 두 st=…)
# **앞**에서 잰다(옛 자리는 더하기 **뒤**라 SQL 에 쓰면 기대하는 행을 빠뜨렸고, 그래서 쓰이지 않은 채 남아 있었다).
m0=$(mark)
st=$(dom_add claude_admin01 www.youtube.com)
if [ "$st" = "403" ]; then
  expect "관리자 — 재인증 전에는 403 next=reauth" "reauth" "$(jget "d.get('next','')" < "$WORK/b")"
  expect "  재인증 → 200" "200" "$(reauth_admin)"
  st=$(dom_add claude_admin01 www.youtube.com)
fi
expect "관리자 — www.youtube.com 더하기 → 201" "201" "$st"
D_YT=$(did)
expect "  감사 embed.domain_add allow" "yes" "$([ "$(psqlq "SELECT count(*) FROM audit_logs WHERE id > ${m0:-0} AND action='embed.domain_add' AND result='allow' AND target_name='www.youtube.com'")" -ge 1 ] && echo yes)"
expect "앞뒤 빈칸·대문자는 소문자로 맞춘다 → 201 · www.example.com" "201 www.example.com" "$(dom_add claude_admin01 '  WWW.Example.COM ') $(jget "d['domain']['host']" < "$WORK/b")"
D_EX=$(did)
expect "같은 호스트 또 → 400 exists" "400 exists" "$(dom_add claude_admin01 www.youtube.com) $(reason_of)"
for bad in 'https://a.example.com' '1.2.3.4' 'a.example.com:8080' '*.example.com' 'localhost' '한글.example.com' 'a..example.com' 'user@a.example.com' 'a.example.com/path' '-a.example.com'; do
  expect "모양이 아닌 호스트 「$bad」 → 400 bad_host" "400 bad_host" "$(dom_add claude_admin01 "$bad") $(reason_of)"
done
expect "punycode 국제 도메인 → 201" "201" "$(dom_add claude_admin01 'xn--9n2bp8q.example')"
jbody "{'host': 'b.example.com', 'note': 'x'}"
expect "허용 키 밖 → 400" "400" "$(capi POST /api/embed-domains claude_admin01 "$WORK/body.json")"
ADM=$(uid claude_admin01)
psqlq "INSERT INTO community.embed_domains (host, added_by) SELECT 'fill' || g || '.example.com', $ADM FROM generate_series(1, 20 - (SELECT count(*) FROM community.embed_domains WHERE removed_at IS NULL)) g" > /dev/null
expect "20개가 찬 뒤 → 400 too_many_domains" "400 too_many_domains" "$(dom_add claude_admin01 'c.example.com') $(reason_of)"
psqlq "UPDATE community.embed_domains SET removed_at = now(), removed_by = $ADM WHERE host LIKE 'fill%'" > /dev/null
m0=$(mark)
expect "빼기 → 200" "200" "$(capi DELETE "/api/embed-domains/$D_EX" claude_admin01)"
expect "  감사 embed.domain_remove allow 1" "1" "$(audit_n "$m0" embed.domain_remove allow)"
expect "  두 번 빼기 → 404" "404" "$(capi DELETE "/api/embed-domains/$D_EX" claude_admin01)"
expect "  직원이 빼기 → 403" "403" "$(capi DELETE "/api/embed-domains/$D_YT" claude_employee01)"
expect "  뺐다가 다시 더하기 → 201(새 줄 — 옛 줄은 남는다)" "201 2" "$(dom_add claude_admin01 www.example.com) $(psqlq "SELECT count(*) FROM community.embed_domains WHERE host='www.example.com'")"
D_EX=$(did)
capi GET /api/embed-domains claude_employee01 > /dev/null
expect "직원 목록 — 호스트만(누가 넣었는지는 관리자에게만)" "www.example.com,www.youtube.com,xn--9n2bp8q.example False" \
  "$(jget "','.join(x['host'] for x in d['domains'])" < "$WORK/b") $(jget "any('added_by' in x or 'id' in x for x in d['domains'])" < "$WORK/b")"
bad=$(sudo -n -u postgres psql -d "$DB" -tAc "INSERT INTO community.embed_domains (host, added_by) VALUES ('Bad.Example.com', $ADM)" 2>&1)
contains "DB CHECK — 대문자 호스트는 들어가지 못한다" "embed_domains_host_shape" "$bad"
bad=$(sudo -n -u postgres psql -d "$DB" -tAc "INSERT INTO community.embed_domains (host, added_by) VALUES ('www.youtube.com', $ADM)" 2>&1)
contains "DB 고유 색인 — 살아 있는 같은 호스트 둘은 없다" "embed_domains_live_host" "$bad"

# ════════════════════════════════════════════════════════════════
hr "C:T-181  저장 — https · 모양 · 목록 밖 새 임베드 400"
P=$(make_page claude_employee01 'C7C-임베드 페이지')
expect "허용 목록 안 임베드 저장 → 200" "200" "$(put_doc claude_employee01 "$P" "[P('앞'), EMB('$YT', '영상 설명')]")"
expect "  색인·추출에 임베드 블록" "1" "$(psqlq "SELECT count(*) FROM community.page_blocks b JOIN community.pages p ON p.id=b.page_id WHERE p.public_id='$P' AND b.block_type='embed'")"
expect "http 주소 → 400 bad_scheme" "400 bad_scheme" "$(put_doc claude_employee01 "$P" "[EMB('http://www.youtube.com/embed/x')]") $(errs)"
expect "포트 → 400 bad_host" "400 bad_host" "$(put_doc claude_employee01 "$P" "[EMB('https://www.youtube.com:8443/x')]") $(errs)"
expect "사용자 정보 → 400 bad_host" "400 bad_host" "$(put_doc claude_employee01 "$P" "[EMB('https://u@www.youtube.com/x')]") $(errs)"
expect "대문자 호스트 → 400 bad_host(편집기는 소문자로 맞춰 보낸다)" "400 bad_host" "$(put_doc claude_employee01 "$P" "[EMB('https://WWW.YOUTUBE.COM/x')]") $(errs)"
expect "빈칸 든 주소 → 400 control_char" "400 control_char" "$(put_doc claude_employee01 "$P" "[EMB('https://www.youtube.com/a b')]") $(errs)"
expect "javascript: → 400 bad_scheme" "400 bad_scheme" "$(put_doc claude_employee01 "$P" "[EMB('javascript:alert(1)')]") $(errs)"
m0=$(mark)
expect "목록 밖 호스트 → 400 embed_not_allowed" "400 embed_not_allowed" "$(put_doc claude_employee01 "$P" "[P('앞'), EMB('$YT', '영상 설명'), EMB('https://evil.example.org/x')]") $(reason_of)"
expect "  감사 page.update deny embed_not_allowed 1" "1" "$(audit_n "$m0" page.update deny embed_not_allowed)"
expect "  본문은 그대로(임베드 하나)" "1" "$(pg_page "$P" "(SELECT count(*) FROM jsonb_array_elements(p.doc->'blocks') b WHERE b->>'type'='embed')")"
jbody "{'title': 'C7C-새 페이지 목록 밖', 'doc': {'schema': 1, 'blocks': [{'id': '$(python3 -c 'import uuid;print(uuid.uuid4())')', 'type': 'embed', 'url': 'https://evil.example.org/'}]}}"
expect "새 페이지 첫 본문에 목록 밖 임베드 → 400 embed_not_allowed" "400 embed_not_allowed" "$(capi POST /api/pages claude_employee01 "$WORK/body.json") $(reason_of)"
jbody "{'title': 'C7C-새 페이지 목록 안', 'doc': {'schema': 1, 'blocks': [{'id': '$(python3 -c 'import uuid;print(uuid.uuid4())')', 'type': 'embed', 'url': '$YT'}]}}"
expect "새 페이지 첫 본문에 목록 안 임베드 → 201" "201" "$(capi POST /api/pages claude_employee01 "$WORK/body.json")"

# ════════════════════════════════════════════════════════════════
hr "C:T-182  목록에서 뺀 호스트 — 옛 임베드는 저장을 막지 않는다 · 새로 넣기·되돌리기는 400"
expect "old.example.net 더하기 → 201" "201" "$(dom_add claude_admin01 old.example.net)"
D_OLD=$(did)
Q=$(make_page claude_employee01 'C7C-옛 임베드')
expect "old.example.net 임베드 저장 → 200" "200" "$(put_doc claude_employee01 "$Q" "[P('첫 판'), EMB('https://old.example.net/v/1')]")"
psqlq "UPDATE community.page_versions v SET edited_at = edited_at - interval '10 minutes' FROM community.pages p WHERE p.id=v.page_id AND p.public_id='$Q'" > /dev/null
expect "임베드를 뺀 판 저장 → 200" "200" "$(put_doc claude_employee01 "$Q" "[P('둘째 판')]")"
psqlq "UPDATE community.page_versions v SET edited_at = edited_at - interval '10 minutes' FROM community.pages p WHERE p.id=v.page_id AND p.public_id='$Q'" > /dev/null
expect "다시 넣은 판 저장 → 200" "200" "$(put_doc claude_employee01 "$Q" "[P('셋째 판'), EMB('https://old.example.net/v/1')]")"
jbody "{'page_id': '$Q', 'name': 'C7C-옛 임베드 틀', 'scope': 'personal'}"
expect "목록 안 임베드가 든 페이지를 템플릿으로 저장 → 201" "201" "$(capi POST /api/templates claude_employee01 "$WORK/body.json")"
TQ=$(jget "d['template']['id']" < "$WORK/b")
expect "old.example.net 빼기 → 200" "200" "$(capi DELETE "/api/embed-domains/$D_OLD" claude_admin01)"
jbody "{'template_id': '$TQ'}"
expect "빠진 호스트의 임베드가 든 템플릿으로 새 페이지 → 201(담을 때 견주었다 — 검토 L-1)" "201" "$(capi POST /api/pages claude_employee01 "$WORK/body.json")"
jbody "{'page_id': '$Q', 'name': 'x', 'scope': 'personal'}"
expect "빠진 호스트의 임베드가 든 페이지를 템플릿으로 저장 → 400 embed_not_allowed" "400 embed_not_allowed" "$(capi POST /api/templates claude_employee01 "$WORK/body.json") $(reason_of)"
expect "옛 임베드는 그대로 두고 글만 고치기 → 200(K-3 — 페이지가 잠기지 않는다)" "200" "$(put_doc claude_employee01 "$Q" "[P('셋째 판 고침'), EMB('https://old.example.net/v/1')]")"
expect "같은 호스트의 **새** 주소 넣기 → 400 embed_not_allowed" "400 embed_not_allowed" "$(put_doc claude_employee01 "$Q" "[P('x'), EMB('https://old.example.net/v/1'), EMB('https://old.example.net/v/2')]") $(reason_of)"
psqlq "UPDATE community.page_versions v SET edited_at = edited_at - interval '10 minutes' FROM community.pages p WHERE p.id=v.page_id AND p.public_id='$Q'" > /dev/null
expect "임베드를 빼고 저장 → 200" "200" "$(put_doc claude_employee01 "$Q" "[P('넷째 판')]")"
VNUM=$(psqlq "SELECT v.version FROM community.page_versions v JOIN community.pages p ON p.id=v.page_id WHERE p.public_id='$Q' AND v.doc::text LIKE '%old.example.net%' ORDER BY v.version LIMIT 1")
m0=$(mark)
expect "빠진 호스트의 임베드가 든 판으로 되돌리기 → 400 embed_not_allowed" "400 embed_not_allowed" "$(capi POST "/api/pages/$Q/versions/${VNUM:-1}/restore" claude_employee01 <(printf '{}')) $(reason_of)"
expect "  감사 page.restore_version deny embed_not_allowed 1" "1" "$(audit_n "$m0" page.restore_version deny embed_not_allowed)"

# ════════════════════════════════════════════════════════════════
hr "C:T-183  그리기 — 세 갈래 · 렌더러 두 벌 · 창구의 재료"
capi GET "/api/pages/$P" claude_employee01 > /dev/null
expect "페이지 응답 embeds — 허용 목록 · 틀 닫힘" "www.example.com,www.youtube.com,xn--9n2bp8q.example False" \
  "$(jget "','.join(d['embeds']['hosts'])" < "$WORK/b") $(jget "d['embeds']['frames']" < "$WORK/b")"
php -r '
require "'"$ROOT"'/src/bootstrap.php";
require "'"$ROOT"'/src/community/routes.php";
$b = ["id" => "11111111-1111-4111-8111-111111111111", "type" => "embed", "url" => "https://www.youtube.com/embed/x\"><script>", "caption" => [["t" => "설명", "m" => []]]];
$ok = ["id" => "22222222-2222-4222-8222-222222222222", "type" => "embed", "url" => "https://www.youtube.com/embed/x?a=1&b=2", "caption" => [["t" => "설명", "m" => []]]];
$out = [
  "frame" => DocRender::html(["schema" => 1, "blocks" => [$ok]], ["embeds" => ["hosts" => ["www.youtube.com"], "frames" => true]]),
  "wait"  => DocRender::html(["schema" => 1, "blocks" => [$ok]], ["embeds" => ["hosts" => ["www.youtube.com"], "frames" => false]]),
  "block" => DocRender::html(["schema" => 1, "blocks" => [$ok]], ["embeds" => ["hosts" => [], "frames" => true]]),
  "evil"  => DocRender::html(["schema" => 1, "blocks" => [$b]], ["embeds" => ["hosts" => ["www.youtube.com"], "frames" => true]]),
];
file_put_contents("'"$WORK"'/render7c.json", json_encode($out, JSON_UNESCAPED_UNICODE));
' 2>"$WORK/php7c.err"
R() { python3 -c "import json;print(json.load(open('$WORK/render7c.json'))['$1'])"; }
contains "서버 렌더러 — 목록 안 + 틀 열림 → iframe sandbox=\"\"" '<iframe class="doc-embed__frame" src="https://www.youtube.com/embed/x?a=1&amp;b=2" sandbox="" referrerpolicy="no-referrer" loading="lazy"' "$(R frame)"
lacks "  권한 토큰(allow-scripts 등) 없음" "allow-" "$(R frame)"
contains "  설명은 figcaption" '<figcaption class="doc-embed__caption">설명</figcaption>' "$(R frame)"
contains "서버 렌더러 — 목록 안 + 틀 닫힘 → 「허용 대기」 카드 + 새 탭 링크" 'target="_blank" rel="noopener noreferrer"' "$(R wait)"
contains "  문구" '허용 대기 · 새 탭에서 열기' "$(R wait)"
lacks "  iframe 없음" "<iframe" "$(R wait)"
contains "서버 렌더러 — 목록 밖 → 링크 없는 카드" '허용 목록 밖의 임베드' "$(R block)"
lacks "  링크·iframe 없음" "href=" "$(R block)$(R block | grep -c iframe | sed 's/^0$//')"
lacks "모양이 아닌 주소(따옴표·태그)는 목록 안 호스트여도 틀을 만들지 않는다" "<iframe" "$(R evil)"
lacks "  태그가 그대로 나가지 않는다" "<script>" "$(R evil)"
expect "경로의 유니코드 공백·비 ASCII 는 모양이 아니다(두 렌더러가 갈리지 않게 — 검토 L-2)" "400 bad_host" "$(put_doc claude_employee01 "$P" "[EMB('https://www.youtube.com/a\u00a0b')]") $(errs)"
expect "끝 줄바꿈 호스트는 모양이 아니다(검토 L-3 — 등록 창구는 앞뒤 빈칸을 떼지만 함수 자체도 막는다)" "false" "$(php -r 'require "'"$ROOT"'/src/community/Doc.php"; var_export(Doc::isEmbedHost("good.example.com\n"));')"

# ════════════════════════════════════════════════════════════════
hr "C:T-184  CSP 스위치(COMMUNITY_EMBED_FRAMES) — 끔이 기본 · 켜면 페이지 화면만 목록 호스트로"
csp_of() { curl -s -D - -o /dev/null -A "$UA" -b "$JAR_DIR/$2.jar" "$SITE$1" | tr -d '\r' | grep -i '^content-security-policy:'; }
expect "코드 상수 기본값 = 끔" "false" "$(php -r 'require "'"$ROOT"'/src/bootstrap.php"; var_export(COMMUNITY_EMBED_FRAMES);' 2>/dev/null)"
lacks "끔 — 페이지 화면 CSP 에 frame-src 없음" "frame-src" "$(csp_of "/community/p/$P" claude_employee01)"
# 임시 인스턴스의 복사본에서만 켠다(운영·저장소 파일은 그대로)
sed -i "s/^const COMMUNITY_EMBED_FRAMES = false;/const COMMUNITY_EMBED_FRAMES = true;/" "$INST/app/src/bootstrap.php"
note "임시 복사본 상수: $(grep -n '^const COMMUNITY_EMBED_FRAMES' "$INST/app/src/bootstrap.php")"
sleep 3   # 코드 캐시가 파일을 다시 읽을 때까지(revalidate 2초)
ON=$(csp_of "/community/p/$P" claude_employee01)
note "켠 뒤 머리글: $ON"
contains "켬 — 페이지 화면 CSP 에 목록 호스트만 frame-src" "frame-src https://www.example.com https://www.youtube.com https://xn--9n2bp8q.example" "$ON"
expect "  CSP 머리글은 하나(덧붙인 것이 옛 것을 바꾼다)" "1" "$(printf '%s\n' "$ON" | grep -c .)"
contains "  기본 정책은 그대로 남는다" "default-src 'self'; script-src 'self'" "$ON"
lacks "켬 — 홈 화면 CSP 는 그대로(frame-src 없음)" "frame-src" "$(csp_of "/community/" claude_employee01)"
lacks "켬 — t-box 화면 CSP 도 그대로" "frame-src" "$(csp_of "/tbox/" claude_employee01)"
expect "켬 — 페이지 응답 embeds.frames 참" "True" "$(capi GET "/api/pages/$P" claude_employee01 > /dev/null; jget "d['embeds']['frames']" < "$WORK/b")"
sed -i "s/^const COMMUNITY_EMBED_FRAMES = true;/const COMMUNITY_EMBED_FRAMES = false;/" "$INST/app/src/bootstrap.php"
sleep 3   # 코드 캐시가 파일을 다시 읽을 때까지(켤 때와 같다)
lacks "다시 끔 — frame-src 없음" "frame-src" "$(csp_of "/community/p/$P" claude_employee01)"

# ════════════════════════════════════════════════════════════════
hr "C:T-185  브라우저 — browser_7c.py (편집기 임베드 창 · 읽기 카드 · 관리자 창 · 렌더러 JS 동등성)"
export PATH="$HOME/.local/bin:$PATH"
cookie_of() {
  python3 - "$JAR_DIR/$1.jar" <<'PY2'
import sys
for line in open(sys.argv[1], encoding='utf-8', errors='replace'):
    parts = line.rstrip('\n').split('\t')
    if len(parts) == 7 and parts[5] == 'tbox_session':
        print(parts[6]); break
PY2
}
if ! python3 -c 'import playwright' 2>/dev/null; then
  skip "브라우저" "playwright 가 없다"
else
  PE=$(make_page claude_employee01 'C7C-브라우저 페이지')
  reauth_admin > /dev/null
  m0=$(mark)
  SMOKE_E1="$(cookie_of claude_employee01)" SMOKE_A1="$(cookie_of claude_admin01)" SMOKE_PE="$PE" SMOKE_RENDER="$WORK/render7c.json" SMOKE_SHOTS="${SHOTS:-}" \
    python3 "$ROOT/tests/community/browser_7c.py" "$SITE" "$UA" > "$WORK/smoke.txt" 2>&1
  sed 's/^/    /' "$WORK/smoke.txt"
  expect "브라우저 실패 0" "0" "$(sed -n 's/^SMOKE_FAIL=//p' "$WORK/smoke.txt" | tail -1)"
  expect "  브라우저가 저장한 임베드 — 호스트를 소문자로 맞춰 보냈다" "https://www.youtube.com/embed/C7C-browser" \
    "$(psqlq "SELECT b->>'url' FROM community.pages p, jsonb_array_elements(p.doc->'blocks') b WHERE p.public_id='$PE' AND b->>'type'='embed' LIMIT 1")"
  expect "  관리자 창 — 더하고 뺐다(감사 1 · 1)" "1 1" "$(audit_n "$m0" embed.domain_add allow) $(audit_n "$m0" embed.domain_remove allow)"
fi

# ════════════════════════════════════════════════════════════════
hr "C:T-186  권한 · 단계"
expect "tbox_app 이 embed_domains DELETE → 42501" "42501" "$(appq "DELETE FROM community.embed_domains WHERE false" | sed -n 's/.*ERROR: *\([0-9A-Z]\{5\}\).*/\1/p')"
expect "COMMUNITY_DOC_STAGE = 7" "7" "$(php -r 'require "'"$ROOT"'/src/bootstrap.php"; echo COMMUNITY_DOC_STAGE;' 2>/dev/null)"
expect "글 안 mention 마크는 8단계로 미뤘다(7-D 전까지 400)" "400 mark_not_in_stage" "$(put_doc claude_employee01 "$P" "[{'id': u(), 'type': 'paragraph', 'text': [{'t': 'x', 'm': {'mention': {'kind': 'user', 'id': 1}}}]}]") $(errs)"
expect "문서 스키마 사본 = 원본(기획 폴더)" "0" "$(diff "$ROOT/public/assets/community/doc-schema.json" "$INST/community/doc-schema.json" >/dev/null 2>&1; echo $?)"
expect "서버 로그에 잡히지 않은 예외 0" "0" "$(grep -c 'uncaught\|Fatal error' "$INST/server.log" 2>/dev/null | tr -d ' ')"

# ════════════════════════════════════════════════════════════════
hr "회귀 — 금지 패턴 · 문법"
out=$(bash "$ROOT/scripts/security_lint.sh" 2>&1 | tail -3)
contains "security_lint 0건" "0건" "$out"
bad=0
for f in $(cd "$ROOT" && git ls-files 'src/**.php' 'public/index.php' 2>/dev/null); do
  [ -f "$ROOT/$f" ] || continue
  php -l "$ROOT/$f" > /dev/null 2>&1 || { echo "  ✖ php -l 실패: $f"; bad=$((bad+1)); }
done
expect "php -l 전부 통과" "0" "$bad"
bash -n "$ROOT/tests/community/stage7c.sh" && expect "stage7c.sh 문법" "0" "0"
python3 -c "import ast,io;ast.parse(io.open('$ROOT/tests/community/browser_7c.py',encoding='utf-8').read())" \
  && expect "browser_7c.py 문법" "0" "0"
expect "커뮤니티 코드에 역할 문자열 비교 0" "0" \
  "$(grep -rnE "'(Admin|Manager|Employee|Guest)'" "$ROOT/src/community" | grep -vE '^[^:]+:[0-9]+:\s*(\*|//|/\*)' | wc -l | tr -d ' ')"
expect "창구(JSON) 코드가 「이름 (아이디)」를 이어 붙이지 않는다 — tbox_person_label 은 서버 화면 두 파일(셸·런처)만" "2" \
  "$(grep -rln 'tbox_person_label(' "$ROOT/src" | grep -v 'src/bootstrap.php' | wc -l | tr -d ' ')"

# ════════════════════════════════════════════════════════════════
if [ "${REGRESS:-0}" = "1" ]; then
  hr "회귀 — 같은 임시 인스턴스에서 커뮤니티 3·4a·4b·4c · t-box stage26·help_button·계정 관리 시험들"
  note "각 스크립트를 임시 인스턴스 폴더로 복사해 SITE·DB 만 바꿔 돌린다(운영 8082·tbox_re 에 닿지 않는다)."
  # help_button.sh 가 부르는 scripts/ui_snapshot.sh 도 주소를 품고 있다 — **임시 복사본만** 고친다.
  sed -i "s#http://127.0.0.1:8082#$SITE#g" "$INST/app/scripts/ui_snapshot.sh"
  if grep -v '^\s*#' "$INST/app/scripts/ui_snapshot.sh" | grep -q '8082'; then
    echo "  ✖ ui_snapshot.sh 에 운영 주소가 남았다 — 회귀를 돌리지 않는다"; FAIL=$((FAIL+1)); REGRESS_LIST=" "
  fi
  for s in ${REGRESS_LIST:-community/stage3 community/stage4a community/stage4b community/stage4c community/stage4d community/stage5a community/stage5a2 community/stage5c community/stage5d community/stage5e community/stage6a community/stage6b community/stage6c stage26 help_button api_whoami api_read api_folders api_upload api_tokens f65 f66 f68}; do
    src="$INST/app/tests/$s.sh"
    dst="$INST/app/tests/$s.temp.sh"
    [ -f "$src" ] || { echo "  ✖ $s — 스크립트가 없다"; FAIL=$((FAIL+1)); continue; }
    sed -e "s#http://127.0.0.1:8082#$SITE#g" -e "s#^DB=\"tbox_re\"#DB=\"$DB\"#" -e "s#-d tbox_re #-d $DB #g" "$src" > "$dst"
    if grep -v '^\s*#' "$dst" | grep -q '8082\|DB="tbox_re"\|-d tbox_re'; then
      echo "  ✖ $s — 운영 주소·DB 가 남아 있어 돌리지 않는다: $(grep -n '8082\|tbox_re' "$dst" | head -2 | tr '\n' ' ')"; FAIL=$((FAIL+1)); continue
    fi
    echo "  ▸ $s (시작 $(date +%T))"
    bash "$dst" > "$WORK/regress_$(basename "$s").txt" 2>&1
    rc=$?
    line=$(grep -E '통과 [0-9]+|PASS|통과:|결과' "$WORK/regress_$(basename "$s").txt" | tail -1)
    echo "    종료 코드 $rc · ${line:-결과 줄 없음}"
    grep -E '^\s*(✖|FAIL)' "$WORK/regress_$(basename "$s").txt" | head -12 | sed 's/^/      /'
    [ -n "${REGRESS_KEEP:-}" ] && cp "$WORK/regress_$(basename "$s").txt" "$REGRESS_KEEP/"
    [ "$rc" = "0" ] && PASS=$((PASS+1)) || FAIL=$((FAIL+1))
  done
fi

# ════════════════════════════════════════════════════════════════
hr "결과"
printf '통과 %d · 실패 %d · 건너뜀 %d\n' "$PASS" "$FAIL" "$SKIP"
note "임시 DB($DB)·임시 폴더·임시 서버는 이 스크립트가 끝나며 지워진다. 운영에는 아무것도 남지 않는다."
[ "$FAIL" -eq 0 ] || exit 1
exit 0

