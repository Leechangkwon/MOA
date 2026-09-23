#!/usr/bin/env bash
# tests/community/stage7a.sh — 7-A 수락 기준 C:T-160 ~ C:T-163 실행 (휴지통 30일 정리 — T-32)
#
#   C:T-160 dry        무엇이 지워질지만 돌려주고 아무것도 바꾸지 않는다
#   C:T-161 정리       30일 지난 휴지통만 · 내용(판·댓글·답글·반응·파생·공유·태그·조회·편집 중·DB 값 이력)은 지우고
#                      메타 기록(상태 기록·감사)은 남긴다 · 두 번째는 0 · 되살린 페이지는 그대로 · 전역 불변식 · p_max 경계
#   C:T-162 앱에서     휴지통 목록·되살리기·읽기 어디에도 없다(404) · 30일 안 된 것은 그대로
#   C:T-163 권한       앱 계정은 정리 함수를 부를 수 없다(42501) · 함수 소유자 tbox_owner · cron 줄 · 정리 래퍼 사용법 오류
#
# ★ 운영 DB 를 건드리지 않는다 — stage6c.sh 와 같은 틀(임시 DB `tbox_7a_test`, 포트 8094). 정리 함수는 임시 DB 에서 tbox_owner 역할로 부른다.
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
PORT=8094
SITE="http://127.0.0.1:$PORT"
BASE="$SITE/tbox"
CBASE="$SITE/community"
UA="tbox-review/$(date +%F)"
SRC_DB="tbox_re"          # 운영 — **읽기만** 한다
DB="tbox_7a_test"         # 임시 — 여기에만 쓴다
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

qa_password() { sudo -n awk -v u="$1" '$1==u{print $3}' /etc/tbox-v2/seed-accounts.txt 2>/dev/null; }
qa_secret()   { sudo -n awk -v u="$1" '$1==u{print $2}' /etc/tbox-v2/qa-totp.txt 2>/dev/null; }

login() {
  local who="$1" jar="$JAR_DIR/$1.jar" pw sec raw code left out try
  pw=$(qa_password "$who")
  sec=$(qa_secret "$who")
  [ -n "$pw" ] || return 2
  for try in 1 2 3; do
    if [ -n "$sec" ]; then
      raw=$(php "$ROOT/scripts/totp_code.php" "$sec" 2>/dev/null)
      code=${raw%%$'\n'*}
      out=$(curl -s -c "$jar" -A "$UA" -H 'Content-Type: application/json' \
            -d "{\"username\":\"$who\",\"password\":\"$pw\",\"otp\":\"$code\"}" "$BASE/api/login")
    else
      out=$(curl -s -c "$jar" -A "$UA" -H 'Content-Type: application/json' \
            -d "{\"username\":\"$who\",\"password\":\"$pw\"}" "$BASE/api/login")
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
hr "7-A — 임시 인스턴스 준비 (운영 DB 에는 쓰지 않는다)"

avail_kb=$(df -Pk / | awk 'NR==2{print $4}')
echo "루트 파티션 여유: $(df -Ph / | awk 'NR==2{print $4}') (${avail_kb}KB)"
[ "$avail_kb" -ge 1048576 ] || { echo "루트 디스크 여유 1GB 미만 — 시험을 시작하지 않는다 (SECURITY R-80)"; exit 3; }

sudo -n -u postgres psql -d "$SRC_DB" -tAc "SELECT 1" >/dev/null 2>&1 \
  || { echo "psql(postgres) 을 쓸 수 없다 — 이 시험은 임시 DB 가 없으면 성립하지 않는다"; exit 3; }

sub "① 운영 DB 를 **읽어** 임시 DB 를 만든다"
DUMP="/tmp/tbox7a_dump.$$.sql"
sudo -n -u postgres dropdb --if-exists "$DB" >/dev/null 2>&1
sudo -n -u postgres createdb -O tbox_owner "$DB" >/dev/null 2>&1 \
  || { echo "임시 DB 를 만들 수 없다"; exit 3; }
sudo -n -u postgres pg_dump -d "$SRC_DB" -f "$DUMP" 2>/dev/null \
  || { echo "운영 DB 를 읽지 못했다 (pg_dump)"; exit 3; }
sudo -n -u postgres psql -q -d "$DB" -v ON_ERROR_STOP=0 -f "$DUMP" >/dev/null 2>"$WORK/restore.err"
sudo -n rm -f "$DUMP"
n_users=$(psqlq "SELECT count(*) FROM public.users")
n_pages=$(psqlq "SELECT count(*) FROM community.pages")
[ -n "$n_users" ] && [ -n "$n_pages" ] \
  || { echo "임시 DB 로 옮기지 못했다 — $(head -2 "$WORK/restore.err")"; exit 3; }
echo "  · 임시 DB $DB — 계정 ${n_users}명 · 페이지 ${n_pages}장"

sub "② 마이그레이션 + 권한 (임시 DB 에만) — 앞 단계(이미 있으면 그대로) · 5-C · 5-D (5-E 는 새 표가 없다)"
for mig in 2026-09-23_community_page_properties.sql 2026-09-23_community_owner_share.sql \
           2026-09-23_users_display_name.sql 2026-09-24_community_boards.sql \
           2026-09-24_community_discussion.sql 2026-09-25_community_databases.sql 2026-09-25_community_databases.sql 2026-09-26_community_purge.sql 2026-09-26_community_purge.sql 2026-09-27_community_templates.sql; do
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

sub "로그인 (qa 계정 다섯 — **순차**)"
for who in qa_employee01 qa_employee02 qa_manager01 qa_admin01 qa_guest01; do
  tok=$(login "$who")
  if [ -z "$tok" ]; then echo "  ✖ $who 로그인 실패 — 중단"; exit 4; fi
  eval "CSRF_${who}='$tok'"
  echo "  ✔ $who"
done
# 개인 공간을 연다(없으면 만든다) — 그 뒤의 목록·인계가 공간을 전제로 한다.
for who in qa_employee01 qa_employee02 qa_manager01 qa_admin01; do capi GET /api/me/space "$who" > /dev/null; done
TEAM1=$(psqlq "SELECT team_id FROM users WHERE username='qa_employee01'")
TEAM2=$(psqlq "SELECT team_id FROM users WHERE username='qa_employee02'")
note "팀: qa_employee01·qa_manager01=$TEAM1 · qa_employee02=$TEAM2"
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
  pw=$(qa_password qa_admin01)
  python3 -c "import json,sys;print(json.dumps({'password':sys.argv[1]}))" "$pw" > "$WORK/reauth.json"
  rc=$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR_DIR/qa_admin01.jar" -A "$UA" -H 'Content-Type: application/json' \
        -H "X-CSRF-Token: $CSRF_qa_admin01" --data-binary "@$WORK/reauth.json" "$BASE/api/me/reauth")
  rm -f "$WORK/reauth.json"
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
events_db() { psqlq "SELECT count(*) FROM community.page_property_events e JOIN community.pages p ON p.id=e.page_id WHERE p.public_id='$1' AND e.prop LIKE 'db:%'"; }

# 정리는 **표 소유자 역할로** 부른다(cron 과 같은 계정 — 독립 검토 M-2: 함수 소유자가 tbox_owner 여야 부를 수 있다)
purge() { sudo -n -u postgres psql -d "$DB" -q -t -A -c "SET ROLE tbox_owner; SELECT count(*) FROM community.purge_trash($1, ${2:-500})" 2>&1 | tail -1; }
pid_of() { psqlq "SELECT id FROM community.pages WHERE public_id='$1'"; }

# ─── 준비: 내용이 든 페이지(판 둘·댓글·답글·반응·태그·조회·공유·편집 중·상태) + DB 하나를 지우고 30일 전으로 당긴다 ───
PD=$(make_page qa_employee01 'C7A-지울 페이지')
set_vis qa_employee01 "$PD" internal
patch_title qa_employee01 "$PD" 'C7A-지울 페이지 2' > /dev/null
psqlq "UPDATE community.page_versions v SET edited_at = edited_at - interval '10 minutes' FROM community.pages p WHERE p.id=v.page_id AND p.public_id='$PD'" > /dev/null
patch_title qa_employee01 "$PD" 'C7A-지울 페이지 3' > /dev/null
comment qa_employee02 "$PD" "{'body':'C7A-댓글 @qa_employee01'}" > /dev/null; C0=$(cid)
comment qa_employee01 "$PD" "{'body':'C7A-답글','parent_id':'$C0'}" > /dev/null
capi POST "/api/comments/$C0/helpful" qa_employee01 > /dev/null
capi POST "/api/pages/$PD/heartbeat" qa_employee01 <(printf '{}') > /dev/null
put_tags qa_employee01 "$PD" "['c7a']" > /dev/null
capi GET "/api/pages/$PD" qa_employee02 > /dev/null
share qa_employee01 "$PD" qa_manager01 view > /dev/null
capi PATCH "/api/pages/$PD/properties" qa_employee01 <(printf '{"status":"doing"}') > /dev/null
read DBP DBI <<< "$(mkdb qa_employee01 'C7A-지울 표')"
K=$(addprop qa_employee01 "$DBI" '금액' number)
newrow qa_employee01 "$DBP" 'C7A-행' "{'$K': 5}" > /dev/null; RW=$(rid)
setv qa_employee01 "$RW" "{'$K': 7}" > /dev/null
PK=$(make_page qa_employee01 'C7A-남길 페이지(최근 삭제)')
PRS=$(make_page qa_employee01 'C7A-되살린 페이지')
capi DELETE "/api/pages/$PRS" qa_employee01 > /dev/null
expect "지웠다가 바로 되살림(정리 시각 전) → 200" "200" "$(capi POST "/api/pages/$PRS/restore" qa_employee01 <(printf '{}'))"
ID_PD=$(pid_of "$PD"); ID_RW=$(pid_of "$RW")
expect "준비 — DB 행의 값 이력이 있다" "yes" "$([ "$(psqlq "SELECT count(*) FROM community.page_property_events WHERE page_id=$ID_RW AND prop LIKE 'db:%'")" -ge 1 ] && echo yes || echo no)"
# 정리 전에 지울 표마다 줄이 **있는지** 먼저 본다(없던 것을 「0」으로 증명하지 않게 — 독립 검토)
for t in page_versions page_blocks page_edit_sessions page_shares page_tags page_views comments; do
  expect "정리 전 $t 에 줄이 있다" "yes" "$([ "$(psqlq "SELECT count(*) FROM community.$t WHERE page_id=$ID_PD")" -ge 1 ] && echo yes || echo no)"
done
expect "정리 전 답글·도움됐어요도 있다" "yes" "$([ "$(psqlq "SELECT count(*) FROM community.comment_reactions r JOIN community.comments c ON c.id=r.comment_id WHERE c.page_id=$ID_PD")" -ge 1 ] && [ "$(psqlq "SELECT count(*) FROM community.comments WHERE page_id=$ID_PD AND parent_comment_id IS NOT NULL")" -ge 1 ] && echo yes || echo no)"
N_ST=$(psqlq "SELECT count(*) FROM community.page_property_events WHERE page_id=$ID_PD AND prop='status'")
expect "정리 전 상태 기록 1" "1" "$N_ST"
for p in "$PD" "$DBP" "$PK"; do capi DELETE "/api/pages/$p" qa_employee01 > /dev/null; done
for p in "$PD" "$DBP" "$RW"; do psqlq "UPDATE community.pages SET purge_after = now() - interval '1 hour' WHERE public_id = '$p'" > /dev/null; done

# ════════════════════════════════════════════════════════════════
hr "C:T-160  dry — 목록만, 바뀐 것 0"
# 운영 사본에는 이미 영구 삭제된 페이지가 있을 수 있다(7-A 반영 때 휴지통을 비웠다) — 기준값과 견준다
PURGED0=$(psqlq "SELECT count(*) FROM community.pages WHERE purged_at IS NOT NULL")
DRY=$(sudo -n -u postgres psql -d "$DB" -q -t -A -c "SET ROLE tbox_owner; SELECT string_agg(page_public_id::text, ' ') FROM community.purge_trash(true, 500)" 2>&1 | tail -1)
contains "dry 목록에 지울 페이지" "$PD" "$DRY"
contains "  DB 의 행도(같이 지워진 하위)" "$RW" "$DRY"
lacks "  30일 안 된 것은 목록에 없다" "$PK" "$DRY"
lacks "  되살린 페이지는 목록에 없다" "$PRS" "$DRY"
expect "  dry 뒤 영구 삭제 표시 그대로(기준 $PURGED0)" "$PURGED0" "$(psqlq "SELECT count(*) FROM community.pages WHERE purged_at IS NOT NULL")"

# ════════════════════════════════════════════════════════════════
hr "C:T-161  정리 — 내용은 지우고 메타 기록은 남긴다"
m0=$(psqlq "SELECT count(*) FROM audit_logs")
N=$(purge false)
expect "정리한 페이지 수 ≥ 3(페이지·DB·행) — tbox_owner 로 부름" "yes" "$([ "${N:-0}" -ge 3 ] 2>/dev/null && echo yes)"
expect "  제목·본문을 비웠다" "영구 삭제된 페이지 0" "$(psqlq "SELECT title || ' ' || jsonb_array_length(doc->'blocks') FROM community.pages WHERE id=$ID_PD")"
for t in page_versions page_blocks page_edit_sessions page_shares page_tags page_views page_reads comments; do
  expect "  $t 0" "0" "$(psqlq "SELECT count(*) FROM community.$t WHERE page_id=$ID_PD")"
done
expect "  반응 0 · 그 페이지 알림 0" "0 0" "$(psqlq "SELECT count(*) FROM community.comment_reactions r JOIN community.comments c ON c.id=r.comment_id WHERE c.page_id=$ID_PD") $(psqlq "SELECT count(*) FROM community.notifications WHERE page_id=$ID_PD")"
expect "  찾기 글·찾기 색인·아이콘 비움" "t" "$(psqlq "SELECT (plain_text = '' AND search_tsv IS NULL AND icon IS NULL) FROM community.pages WHERE id=$ID_PD")"
expect "  남기는 것: 상태 기록 그대로" "$N_ST" "$(psqlq "SELECT count(*) FROM community.page_property_events WHERE page_id=$ID_PD AND prop='status'")"
expect "  DB 값 이력(db:*)은 지웠다" "0" "$(psqlq "SELECT count(*) FROM community.page_property_events WHERE page_id=$ID_RW AND prop LIKE 'db:%'")"
expect "  행의 속성 값·DB 연결 비움" "t" "$(psqlq "SELECT (database_id IS NULL AND properties IS NULL) FROM community.pages WHERE id=$ID_RW")"
expect "  DB 스키마·뷰 비움(줄은 남음)" "0 0" "$(psqlq "SELECT jsonb_array_length(schema) || ' ' || jsonb_array_length(views) FROM community.databases WHERE public_id='$DBI'")"
expect "  감사 줄 수가 줄지 않았다" "yes" "$([ "$(psqlq "SELECT count(*) FROM audit_logs")" -ge "$m0" ] && echo yes)"
expect "  되살린 페이지는 정리되지 않았다" "" "$(psqlq "SELECT purged_at FROM community.pages WHERE public_id='$PRS'")"
expect "  전역 불변식 — 영구 삭제됐는데 살아 있는 페이지 0" "0" "$(psqlq "SELECT count(*) FROM community.pages WHERE purged_at IS NOT NULL AND deleted_at IS NULL")"
expect "두 번째 정리 → 0" "0" "$(purge false)"
perr() { sudo -n -u postgres psql -d "$DB" -q -t -A -c "SET ROLE tbox_owner; SELECT count(*) FROM community.purge_trash(false, $1)" 2>&1; }
expect "p_max 0 → 오류(아무것도 안 함)" "yes" "$(perr 0 | grep -q 'p_max' && echo yes)"
expect "p_max 5001 → 오류" "yes" "$(perr 5001 | grep -q 'p_max' && echo yes)"

# ════════════════════════════════════════════════════════════════
hr "C:T-162  앱에서 — 목록·되살리기·읽기"
capi GET /api/me/trash qa_employee01 > /dev/null
lacks "휴지통 목록에 정리한 페이지 없음" "$PD" "$(cat "$WORK/b")"
contains "  30일 안 된 페이지는 휴지통에 있다" "$PK" "$(cat "$WORK/b")"
expect "정리한 페이지 되살리기 → 404" "404" "$(capi POST "/api/pages/$PD/restore" qa_employee01 <(printf '{}'))"
expect "정리한 페이지 읽기 → 404" "404" "$(capi GET "/api/pages/$PD" qa_employee01)"
PDUE=$(make_page qa_employee01 'C7A-정리 시각 지난 페이지')
capi DELETE "/api/pages/$PDUE" qa_employee01 > /dev/null
psqlq "UPDATE community.pages SET purge_after = now() - interval '1 minute' WHERE public_id='$PDUE'" > /dev/null
expect "정리 시각이 지났지만 아직 정리 전 → 되살리기 404(정리와 겨루지 않는다 — M-1)" "404" "$(capi POST "/api/pages/$PDUE/restore" qa_employee01 <(printf '{}'))"
expect "30일 안 된 페이지 되살리기 → 200" "200" "$(capi POST "/api/pages/$PK/restore" qa_employee01 <(printf '{}'))"

# ════════════════════════════════════════════════════════════════
hr "C:T-163  권한 — 앱 계정은 부를 수 없다"
expect "tbox_app 이 purge_trash → 42501" "42501" "$(appq "SELECT * FROM community.purge_trash(true, 1)" | sed -n 's/.*ERROR: *\([0-9A-Z]\{5\}\).*/\1/p')"
expect "함수 소유자 = tbox_owner" "tbox_owner" "$(psqlq "SELECT pg_get_userbyid(proowner) FROM pg_proc WHERE proname='purge_trash'")"
expect "cron 표에 정리 줄(--run, 04:10)" "yes" "$(grep -q '^10 *4 .*community_purge.php --run' "$ROOT/deploy/cron.d/tbox-v2" && echo yes)"
php "$INST/app/scripts/community_purge.php" --max 0 > /dev/null 2>&1; rc=$?
expect "정리 래퍼 — 잘못된 --max 는 사용법 오류로 끝난다" "yes" "$([ $rc -ne 0 ] && echo yes)"
TBOX_OWNER_PGPASS=/nonexistent php "$INST/app/scripts/community_purge.php" > /dev/null 2>&1; rc=$?
expect "정리 래퍼 — 소유자 pgpass 가 없으면 멈춘다(아무것도 안 함)" "yes" "$([ $rc -ne 0 ] && echo yes)"
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
bash -n "$ROOT/tests/community/stage7a.sh" && expect "stage7a.sh 문법" "0" "0"
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

