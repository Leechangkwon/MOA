#!/usr/bin/env bash
# tests/community/stage7d1.sh — 7-D1 수락 기준 C:T-190 ~ C:T-193 실행 (많이 읽힌 문서 — N-34 · 결정 J-6)
#
#   C:T-190 권한     관리자만(재인증) · 직원·Guest 403 · 감사 page.popular
#   C:T-191 범위     사내 전체에 열린 페이지만 — 비공개·팀 범위·팀 공간·숨긴 글·지운 것 제목 0 · 게시된 것은 게시판 등급대로
#   C:T-192 세기     지난 30일만 · 검토 계정(qa_·claude_) 조회는 빼고 · 사람 × 날 · 읽은 사람 수 · 순서 · 누가 읽었는지 없음
#   C:T-193 브라우저 browser_7d1.py — 관리자 단추·창 · 직원에게는 단추 없음
#
# ★ 운영 DB 를 건드리지 않는다 — stage7c.sh 와 같은 틀(임시 DB `tbox_7d1_test`, 포트 8099) · 검토 계정은 claude_(F-71).
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
. "$ROOT/tests/lib/secrets.sh" || { echo "tests/lib/secrets.sh 를 읽지 못했다 — 시험을 시작하지 않는다 (R-62)"; exit 3; }
PORT=8099
SITE="http://127.0.0.1:$PORT"
BASE="$SITE/tbox"
CBASE="$SITE/community"
UA="tbox-review/$(date +%F)"
SRC_DB="tbox_re"          # 운영 — **읽기만** 한다
DB="tbox_7d1_test"         # 임시 — 여기에만 쓴다
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
hr "7-D1 — 임시 인스턴스 준비 (운영 DB 에는 쓰지 않는다)"

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
           2026-09-24_community_discussion.sql 2026-09-25_community_databases.sql 2026-09-25_community_databases.sql 2026-09-26_community_purge.sql 2026-09-27_community_templates.sql 2026-09-28_community_embeds.sql; do
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

# ─── 7-D1 도우미 ───────────────────────────────────────────────
HUMAN=$(psqlq "SELECT id FROM users WHERE username='employee01'")
note "사람 조회자(임시 DB 에만 줄을 넣는다): employee01 id=${HUMAN:-없음}"
view_as_human() {   # $1 페이지 public_id $2 며칠 전(0=오늘)
  psqlq "INSERT INTO community.page_views (page_id, user_id, day) SELECT p.id, $HUMAN, current_date - $2 FROM community.pages p WHERE p.public_id='$1' ON CONFLICT DO NOTHING" > /dev/null
}
popular() { capi GET /api/admin/popular "$1"; }
in_list() { jget "'yes' if any(p['id']=='$1' for p in d.get('pages',[])) else 'no'" < "$WORK/b"; }
row_of() { jget "[str(p['views'])+' '+str(p['readers']) for p in d['pages'] if p['id']=='$1'][0]" < "$WORK/b"; }

capi GET /api/spaces claude_employee01 > /dev/null
TS1=$(psqlq "SELECT id FROM community.spaces WHERE kind='team' AND team_id=${TEAM1:-0}")

# ─── 준비 (관리자는 먼저 재인증 — 시험용 게시판을 만든다) ───
# 재인증 전 첫 호출은 무조건 403 next=reauth 여야 한다(관문이 사라져도 조용히 지나가지 않게 — 검토 Low)
expect "관리자 — 재인증 전 첫 호출 403" "403" "$(popular claude_admin01)"
expect "  next=reauth" "reauth" "$(jget "d.get('next','')" < "$WORK/b")"
expect "  재인증 → 200" "200" "$(reauth_admin)"
mkboard() {     # $1 이름 $2 파이썬 dict 조각 → 게시판 번호
  jbody "{'name': '''$1''', 'board_kind': 'discussion', $2}"
  capi POST /api/boards claude_admin01 "$WORK/body.json" > /dev/null
  jget "d['board']['id']" < "$WORK/b"
}
B_TEAMB=$(mkboard 'C7D1-팀게시판' "'access_level': 'team', 'team_id': ${TEAM1:-1}")
B_ADMB=$(mkboard 'C7D1-관리자게시판' "'access_level': 'admin-only'")
B_PUBB=$(mkboard 'C7D1-공개게시판' "'access_level': 'public'")
B_ARCH=$(mkboard 'C7D1-보관게시판' "'access_level': 'internal'")
note "시험 게시판: 팀 ${B_TEAMB:-?} · 관리자 ${B_ADMB:-?} · 공개 ${B_PUBB:-?} · 보관 ${B_ARCH:-?}"

P_INT=$(make_page claude_employee01 'C7D1-사내 공개'); set_vis claude_employee01 "$P_INT" internal
P_INT2=$(make_page claude_employee01 'C7D1-사내 공개(검토 계정만 읽음)'); set_vis claude_employee01 "$P_INT2" internal
P_PRIV=$(make_page claude_employee01 'C7D1-비공개 제목')
P_TEAMVIS=$(make_page claude_employee01 'C7D1-팀 범위 제목'); set_vis claude_employee01 "$P_TEAMVIS" team
P_TEAMSP=$(make_page claude_employee01 'C7D1-팀 공간 제목' '' "${TS1:-0}")
P_OLD=$(make_page claude_employee01 'C7D1-오래전에만 읽힘'); set_vis claude_employee01 "$P_OLD" internal
P_B29=$(make_page claude_employee01 'C7D1-29일 전 읽힘'); set_vis claude_employee01 "$P_B29" internal
P_B30=$(make_page claude_employee01 'C7D1-30일 전 읽힘'); set_vis claude_employee01 "$P_B30" internal
P_DEL=$(make_page claude_employee01 'C7D1-지운 페이지'); set_vis claude_employee01 "$P_DEL" internal
P_PAR=$(make_page claude_employee01 'C7D1-지운 부모'); set_vis claude_employee01 "$P_PAR" internal
P_KID=$(make_page claude_employee01 'C7D1-부모와 함께 지운 하위' "$P_PAR")
BP=$(new_post claude_employee01 "$B_FREE" 'C7D1-자유게시판 글')
BH=$(new_post claude_employee01 "$B_FREE" 'C7D1-숨긴 게시판 글')
BT=$(new_post claude_employee01 "$B_TEAMB" 'C7D1-팀게시판 글')
BA=$(new_post claude_admin01 "$B_ADMB" 'C7D1-관리자게시판 글')
BU=$(new_post claude_employee01 "$B_PUBB" 'C7D1-공개게시판 글')
BR=$(new_post claude_employee01 "$B_ARCH" 'C7D1-보관게시판 글')
P_PUB=$(make_page claude_employee01 'C7D1-비공개에서 게시'); publish claude_employee01 "$P_PUB" "$B_FREE" > /dev/null
P_PUBT=$(make_page claude_employee01 'C7D1-팀게시판에 게시한 비공개'); publish claude_employee01 "$P_PUBT" "$B_TEAMB" > /dev/null
P_PUBA=$(make_page claude_admin01 'C7D1-관리자게시판에 게시한 비공개'); publish claude_admin01 "$P_PUBA" "$B_ADMB" > /dev/null
P_PUBH=$(make_page claude_employee01 'C7D1-게시 뒤 숨긴 비공개'); publish claude_employee01 "$P_PUBH" "$B_FREE" > /dev/null
P_PUBHI=$(make_page claude_employee01 'C7D1-게시 뒤 숨긴 사내 공개'); set_vis claude_employee01 "$P_PUBHI" internal; publish claude_employee01 "$P_PUBHI" "$B_FREE" > /dev/null
P_UNPUB=$(make_page claude_employee01 'C7D1-게시 해제한 비공개'); publish claude_employee01 "$P_UNPUB" "$B_FREE" > /dev/null
capi DELETE "/api/pages/$P_UNPUB/publish" claude_employee01 > /dev/null
P_XSS=$(make_page claude_employee01 '<img src=x onerror=alert(7)>C7D1'); set_vis claude_employee01 "$P_XSS" internal
ALL_NAMES="P_INT P_PRIV P_TEAMVIS P_TEAMSP P_DEL P_KID BP BH BT BA BU BR P_PUB P_PUBT P_PUBA P_PUBH P_PUBHI P_UNPUB P_XSS"
n_empty=0; ALLP=""; for v in $ALL_NAMES; do [ -z "${!v}" ] && { n_empty=$((n_empty+1)); note "빈 id: $v"; }; ALLP="$ALLP ${!v}"; done
expect "준비 — 페이지·글 19개 모두 만들어졌다(빈 id 0 — 「없음」 검사가 공짜로 통과하지 않게)" "0" "$n_empty"
for p in $ALLP; do view_as_human "$p" 0; done
view_as_human "$P_INT" 1; view_as_human "$P_INT" 2
view_as_human "$P_OLD" 40; view_as_human "$P_B29" 29; view_as_human "$P_B30" 30
missing=0; for p in $ALLP $P_OLD $P_B29 $P_B30; do [ "$(views_of "$p")" -ge 1 ] || missing=$((missing+1)); done
expect "준비 — 사람 조회 줄이 모두 들어갔다" "0" "$missing"
# 검토 계정 조회(세지 않아야): claude_ 는 창구로, qa_ 는 임시 DB 에 줄로
capi GET "/api/pages/$P_INT2" claude_employee02 > /dev/null
QAE=$(psqlq "SELECT id FROM users WHERE username='qa_employee01'")
psqlq "INSERT INTO community.page_views (page_id, user_id) SELECT p.id, $QAE FROM community.pages p WHERE p.public_id='$P_INT2' ON CONFLICT DO NOTHING" > /dev/null
# 밑줄 이스케이프 확인 — 「qa」 로 시작하지만 밑줄이 아닌 **사람** 이름은 센다(임시 DB 의 시드 계정 이름만 바꾼다)
psqlq "UPDATE users SET username='qaxemployee02' WHERE username='employee02'" > /dev/null
QX=$(psqlq "SELECT id FROM users WHERE username='qaxemployee02'")
psqlq "INSERT INTO community.page_views (page_id, user_id) SELECT p.id, $QX FROM community.pages p WHERE p.public_id='$P_INT' ON CONFLICT DO NOTHING" > /dev/null
expect "준비 — P_INT2 는 검토 계정 조회만 2줄 이상" "yes" "$([ "$(views_of "$P_INT2")" -ge 2 ] && echo yes)"
capi POST "/api/pages/$BH/hide" claude_admin01 > /dev/null
capi POST "/api/pages/$P_PUBH/hide" claude_admin01 > /dev/null
capi POST "/api/pages/$P_PUBHI/hide" claude_admin01 > /dev/null
capi DELETE "/api/pages/$P_DEL" claude_employee01 > /dev/null
capi DELETE "/api/pages/$P_PAR" claude_employee01 > /dev/null
jbody "{'is_active': False}"; capi PATCH "/api/boards/$B_ARCH" claude_admin01 "$WORK/body.json" > /dev/null
expect "준비 — 숨김 셋 · 삭제 셋(하위 포함) · 게시 해제 · 보관" "3 3 f f" "$(psqlq "SELECT count(*) FROM community.pages WHERE public_id IN ('$BH','$P_PUBH','$P_PUBHI') AND hidden_at IS NOT NULL") $(psqlq "SELECT count(*) FROM community.pages WHERE public_id IN ('$P_DEL','$P_PAR','$P_KID') AND deleted_at IS NOT NULL") $(pg_page "$P_UNPUB" "(p.visibility='published')::text::char") $(psqlq "SELECT is_active::text::char FROM community.spaces WHERE id=${B_ARCH:-0}")"

# ════════════════════════════════════════════════════════════════
hr "C:T-190  권한 — 관리자만(재인증) · 감사"
m0=$(mark)
expect "직원 → 403" "403" "$(popular claude_employee01)"
expect "Guest → 403" "403" "$(popular claude_guest01)"
expect "매니저 → 403" "403" "$(popular claude_manager01)"
expect "  세 거부 모두 감사 authz.route deny" "3" "$(audit_n "$m0" authz.route deny)"
m0=$(mark)
expect "관리자(재인증 뒤) → 200" "200" "$(popular claude_admin01)"
expect "  감사 page.popular allow 1" "1" "$(audit_n "$m0" page.popular allow)"
cp "$WORK/b" "$WORK/pop.json"

# ════════════════════════════════════════════════════════════════
hr "C:T-191  범위 — 사내 전체에 열린 페이지만"
B() { cp "$WORK/pop.json" "$WORK/b"; }
for pair in "P_INT:yes:사내 공개" "BP:yes:사내 게시판 글" "BU:yes:공개 게시판 글" "BR:yes:보관(비활성) 사내 게시판 글 — 판정처럼 읽힘" \
            "P_PUB:yes:비공개를 사내 게시판에 게시" "P_PUBHI:yes:숨긴 게시 페이지라도 원래 범위가 사내" \
            "P_PRIV:no:비공개" "P_TEAMVIS:no:팀 범위" "P_TEAMSP:no:팀 공간" "BH:no:숨긴 게시판 글" "BT:no:팀 게시판 글" "BA:no:관리자 게시판 글" \
            "P_PUBT:no:비공개를 팀 게시판에 게시" "P_PUBA:no:비공개를 관리자 게시판에 게시" "P_PUBH:no:게시 뒤 숨긴 비공개" "P_UNPUB:no:게시 해제한 비공개" \
            "P_DEL:no:지운 페이지" "P_KID:no:부모와 함께 지운 하위"; do
  var=${pair%%:*}; rest=${pair#*:}; want=${rest%%:*}; label=${rest#*:}
  B; expect "$label → $want" "$want" "$(in_list "${!var}")"
done
for t in 'C7D1-비공개 제목' 'C7D1-팀 범위 제목' 'C7D1-팀 공간 제목' 'C7D1-숨긴 게시판 글' 'C7D1-팀게시판 글' 'C7D1-관리자게시판 글' 'C7D1-팀게시판에 게시한 비공개' 'C7D1-관리자게시판에 게시한 비공개' 'C7D1-게시 뒤 숨긴 비공개' 'C7D1-게시 해제한 비공개' 'C7D1-지운 페이지' 'C7D1-부모와 함께 지운 하위'; do
  lacks "  응답에 「$t」 제목 없음" "$t" "$(cat "$WORK/pop.json")"
done

# ════════════════════════════════════════════════════════════════
hr "C:T-192  세기 — 30일 · 검토 계정 제외 · 사람 × 날"
B; expect "사내 공개 페이지 — 조회 4(사람 × 날) · 읽은 사람 2(밑줄 아닌 qax… 는 사람)" "4 2" "$(row_of "$P_INT")"
B; expect "29일 전 조회 → 있음(30일 창 안)" "yes" "$(in_list "$P_B29")"
B; expect "30일 전 조회 → 없음" "no" "$(in_list "$P_B30")"
B; expect "40일 전에만 읽힌 페이지 없음" "no" "$(in_list "$P_OLD")"
B; expect "검토 계정(claude_·qa_)만 읽은 페이지 없음" "no" "$(in_list "$P_INT2")"
B; expect "조회 많은 순 — 차례대로 줄어든다" "yes" "$(jget "'yes' if all(d['pages'][i]['views']>=d['pages'][i+1]['views'] for i in range(len(d['pages'])-1)) else 'no'" < "$WORK/b")"
for k in employee01 qaxemployee02 user_id username viewer; do
  lacks "  응답에 누가 읽었는지($k) 없음" "$k" "$(cat "$WORK/pop.json")"
done
expect "검토 계정 접두가 판정 한 곳(Origin)과 같다(qa_·claude_ — 검토 L-4)" "yes" \
  "$(grep -q "REVIEW_ACCOUNT_PREFIXES = \['qa_', 'claude_'\]" "$ROOT/src/Origin.php" && grep -qF "NOT LIKE 'qa\_%'" "$ROOT/src/community/handlers/admin_popular.php" && grep -qF "NOT LIKE 'claude\_%'" "$ROOT/src/community/handlers/admin_popular.php" && echo yes)"

# ════════════════════════════════════════════════════════════════
hr "C:T-193  브라우저 — browser_7d1.py"
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
  # 재인증 **안 한** 관리자 세션 하나 더 — 화면의 재인증 흐름(셸 창 → 다시 읽기)을 누르려고(검토 Low). 옛 쿠키는 잠시 비켜 둔다.
  mv "$JAR_DIR/claude_admin01.jar" "$JAR_DIR/claude_admin01.fresh.jar"
  login claude_admin01 > /dev/null || note "두 번째 관리자 로그인 실패"
  mv "$JAR_DIR/claude_admin01.jar" "$JAR_DIR/claude_admin01.stale.jar"; mv "$JAR_DIR/claude_admin01.fresh.jar" "$JAR_DIR/claude_admin01.jar"
  STALE=$(python3 - "$JAR_DIR/claude_admin01.stale.jar" <<'PY3'
import sys
for line in open(sys.argv[1], encoding='utf-8', errors='replace'):
    parts = line.rstrip('\n').split('\t')
    if len(parts) == 7 and parts[5] == 'tbox_session':
        print(parts[6]); break
PY3
)
  SMOKE_A1="$(cookie_of claude_admin01)" SMOKE_A1_STALE="$STALE" SMOKE_A1_PW="$(review_password claude_admin01)" \
  SMOKE_E1="$(cookie_of claude_employee01)" SMOKE_M1="$(cookie_of claude_manager01)" SMOKE_TITLE='C7D1-사내 공개' SMOKE_SHOTS="${SHOTS:-}" \
    python3 "$ROOT/tests/community/browser_7d1.py" "$SITE" "$UA" > "$WORK/smoke.txt" 2>&1
  sed 's/^/    /' "$WORK/smoke.txt"
  expect "브라우저 실패 0" "0" "$(sed -n 's/^SMOKE_FAIL=//p' "$WORK/smoke.txt" | tail -1)"
fi
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
bash -n "$ROOT/tests/community/stage7d1.sh" && expect "stage7d1.sh 문법" "0" "0"
python3 -c "import ast,io;ast.parse(io.open('$ROOT/tests/community/browser_7d1.py',encoding='utf-8').read())" \
  && expect "browser_7d1.py 문법" "0" "0"
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

