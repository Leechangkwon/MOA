#!/usr/bin/env bash
# tests/community/stage6b.sh — 6-B 수락 기준 C:T-140 ~ C:T-145 실행 (뷰 · 보드 · 계산 · 관계 · 파일 속성)
#                              (../../../community/stage6b-database-views-directive.md 「시험」)
#
#   C:T-140 뷰         저장·고치기·지우기 · 거르기가 스키마로 컴파일되지 않으면 400 · 10개 한도 · 409 · 자격(보기 공유 403)
#   C:T-141 보드       상태 옮기기 = 상태 칸(보기 공유자 403)
#   C:T-142 계산(T-29) 못 보는 행은 합계·개수·채움·체크·D-day 어디에도 들지 않는다 · 뷰·거르기 안만
#   C:T-143 관계       같은 공간의 볼 수 있는 DB 만 대상 · 없는/남의 DB/못 보는 행은 같은 400 · 제목은 보는 사람 권한으로
#   C:T-144 파일 속성  5-E 첨부 규칙 — 좁은 파일 400 · 넓히기 400 file_narrower · 이름은 보는 사람 권한으로
#   C:T-145 브라우저   browser_6b.py — 보드 끌기·키보드 · 리스트 · 뷰 저장 · 계산 줄 · 관계 고르기, 라이트·다크·390px
#   회귀 (REGRESS=1)
#
# ★ 운영 DB 를 건드리지 않는다 — stage6a.sh 와 같은 틀(임시 DB `tbox_6b_test`, 포트 8091).
# 사용: sudo -v && bash tests/community/stage6b.sh     (REGRESS=1 이면 회귀까지, SHOTS=<폴더> 면 스크린샷)
set -u

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
. "$ROOT/tests/lib/secrets.sh" || { echo "tests/lib/secrets.sh 를 읽지 못했다 — 시험을 시작하지 않는다 (R-62)"; exit 3; }
PORT=8091
SITE="http://127.0.0.1:$PORT"
BASE="$SITE/tbox"
CBASE="$SITE/community"
UA="tbox-review/$(date +%F)"
SRC_DB="tbox_re"          # 운영 — **읽기만** 한다
DB="tbox_6b_test"         # 임시 — 여기에만 쓴다
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
  local who="$1" jar="$JAR_DIR/$1.jar" pw sec raw code left out try body
  pw=$(qa_password "$who")
  sec=$(qa_secret "$who")
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
hr "6-B — 임시 인스턴스 준비 (운영 DB 에는 쓰지 않는다)"

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
# qcheck-allow: R20 — 임시 인스턴스용 사본(0700·0600 · trap 정리) · kill -9 뒤 남는 창은 받아들인 위험(HO-B1-004)
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
  pw=$(qa_password claude_admin01)
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

# ════════════════════════════════════════════════════════════════
# t-box 세션 업로드 — $1 계정 $2 파일 이름 $3 등급 [$4 팀 번호] → 새 파일 UUID (실패면 빈 글자)
upload() {
  local who="$1" name="$2" level="$3" team="${4:-}" jar="$JAR_DIR/$1.jar" csrf
  eval "csrf=\${CSRF_${who}:-}"
  case "$name" in
    *.png) python3 -c "
import struct,zlib,sys
def ch(t,d): return struct.pack('>I',len(d))+t+d+struct.pack('>I',zlib.crc32(t+d)&0xffffffff)
raw=b''.join(b'\x00'+b'\x40\x80\xc0'*8 for _ in range(8))
sys.stdout.buffer.write(b'\x89PNG\r\n\x1a\n'+ch(b'IHDR',struct.pack('>IIBBBBB',8,8,8,2,0,0,0))+ch(b'IDAT',zlib.compress(raw))+ch(b'IEND',b''))
" > "$WORK/$name" ;;
    *) printf 'C5E 시험 파일 %s\n' "$name" > "$WORK/$name" ;;
  esac
  if [ -n "$team" ]; then
    curl -s -o "$WORK/up.json" -A "$UA" -b "$jar" -H 'Expect:' -H "X-CSRF-Token: $csrf" \
      -F "file=@$WORK/$name" -F "access_level=$level" -F "team_id=$team" "$BASE/api/files"
  else
    curl -s -o "$WORK/up.json" -A "$UA" -b "$jar" -H 'Expect:' -H "X-CSRF-Token: $csrf" \
      -F "file=@$WORK/$name" -F "access_level=$level" "$BASE/api/files"
  fi
  jget "d['id']" < "$WORK/up.json"
}

views() {       # $1 계정 $2 DB id $3 파이썬 식 → 상태 코드
  jbody "$3"
  capi PATCH "/api/databases/$2/views" "$1" "$WORK/body.json"
}
summ() {        # $1 계정 $2 DB id [$3 query] → 상태 코드 ($WORK/b)
  capi GET "/api/databases/$2/summary${3:-}" "$1"
}

# ─── 준비: 사내 DB 하나(금액·완료·마감·분류) + 행 셋(하나는 비공개로 좁힘) ───
read D1P D1 <<< "$(mkdb claude_employee01 'C6B-업무 표')"
set_vis claude_employee01 "$D1P" internal
K_NUM=$(addprop claude_employee01 "$D1" '금액' number)
K_CHK=$(addprop claude_employee01 "$D1" '완료' checkbox)
K_DATE=$(addprop claude_employee01 "$D1" '마감' date)
K_SEL=$(addprop claude_employee01 "$D1" '분류' select "['A','B']")
newrow claude_employee01 "$D1P" 'C6B-공개 행1' "{'$K_NUM': 100, '$K_CHK': True, '$K_DATE': {'start': '2099-01-10'}, '$K_SEL': 'A'}" > /dev/null; R1=$(rid)
newrow claude_employee01 "$D1P" 'C6B-공개 행2' "{'$K_NUM': 50, '$K_SEL': 'B'}" > /dev/null; R2=$(rid)
newrow claude_employee01 "$D1P" 'C6B-비밀 행' "{'$K_NUM': 900, '$K_CHK': True, '$K_DATE': {'start': '2099-01-02'}, '$K_SEL': 'A'}" > /dev/null; RS=$(rid)
set_vis claude_employee01 "$RS" private
note "DB $D1 · 행 $R1 $R2 $RS(비공개)"

# ════════════════════════════════════════════════════════════════
hr "C:T-140  뷰 — 저장·고치기·지우기 · 검사 · 자격"
expect "뷰 더하기(보드, 거르기 A) → 200" "200" "$(views claude_employee01 "$D1" "{'add': {'name': 'A 보드', 'type': 'board', 'filter': {'and': [{'prop': '$K_SEL', 'op': 'eq', 'value': 'A'}]}}}")"
V1=$(jget "d['views'][0]['id']" < "$WORK/b")
expect "  뷰 id 모양(v + 8hex)" "yes" "$([[ "$V1" =~ ^v[0-9a-f]{8}$ ]] && echo yes)"
expect "모르는 종류(calendar) → 400" "400" "$(views claude_employee01 "$D1" "{'add': {'name': '달력', 'type': 'calendar'}}")"
expect "스키마에 없는 속성으로 거르는 뷰 → 400" "400" "$(views claude_employee01 "$D1" "{'add': {'name': '틀림', 'type': 'table', 'filter': {'and': [{'prop': 'p00000000', 'op': 'empty'}]}}}")"
expect "주입 글자 정렬 뷰 → 400" "400" "$(views claude_employee01 "$D1" "{'add': {'name': '주입', 'type': 'table', 'sort': [{'prop': \"x'; DROP\", 'dir': 'asc'}]}}")"
expect "같은 이름 → 400" "400" "$(views claude_employee01 "$D1" "{'add': {'name': 'A 보드', 'type': 'table'}}")"
expect "뷰로 행 목록(A 만, 볼 수 있는 것만) → 1행(주인은 2)" "2" "$(capi GET "/api/databases/$D1/rows?view=$V1" claude_employee01 > /dev/null; jget "d['total']" < "$WORK/b")"
expect "  다른 사원 → 1행(비밀 행 빠짐)" "1" "$(capi GET "/api/databases/$D1/rows?view=$V1" claude_employee02 > /dev/null; jget "d['total']" < "$WORK/b")"
expect "없는 뷰 → 400" "400" "$(capi GET "/api/databases/$D1/rows?view=v00000000" claude_employee01)"
expect "뷰 고치기(이름·종류) → 200" "200" "$(views claude_employee01 "$D1" "{'update': {'id': '$V1', 'name': 'A 리스트', 'type': 'list'}}")"
expect "  고친 값" "A 리스트 list" "$(jget "d['views'][0]['name']+' '+d['views'][0]['type']" < "$WORK/b")"
m0=$(mark)
expect "보기만 하는 다른 사원 뷰 더하기 → 403" "403" "$(views claude_employee02 "$D1" "{'add': {'name': '침입', 'type': 'table'}}")"
expect "  감사 database.views deny not_owner" "1" "$(audit_n "$m0" database.views deny not_owner)"
share claude_employee01 "$D1P" claude_employee02 edit > /dev/null
expect "편집 공유자 뷰 더하기 → 200 (결정 H-1)" "200" "$(views claude_employee02 "$D1" "{'add': {'name': '공유자 뷰', 'type': 'table'}}")"
OLDV=$(psqlq "SELECT updated_at FROM community.databases WHERE public_id='$D1'")
views claude_employee01 "$D1" "{'update': {'id': '$V1', 'name': 'A 리스트2'}}" > /dev/null
expect "옛 판(version)으로 뷰 고치기 → 409" "409" "$(views claude_employee01 "$D1" "{'update': {'id': '$V1', 'name': 'A 리스트3'}, 'version': '$OLDV'}")"
# 독립 검토 6-B M-1 — 값이 필요 없는 연산자의 value·큰 덩어리는 저장되지 않는다
views claude_employee01 "$D1" "{'add': {'name': '빈칸 뷰', 'type': 'table', 'filter': {'and': [{'prop': '$K_NUM', 'op': 'empty', 'value': {'x': ['y']*500}}]}}}" > /dev/null
expect "  빈칸 뷰의 조건에 value 가 남지 않았다" "0" "$(psqlq "SELECT count(*) FROM community.databases d, jsonb_array_elements(d.views) v, jsonb_array_elements(v->'filter'->'and') c WHERE d.public_id='$D1' AND v->>'name'='빈칸 뷰' AND c ? 'value'")"
expect "4KB 넘는 뷰(긴 거르기 값) → 400" "400" "$(views claude_employee01 "$D1" "{'add': {'name': '큰 뷰', 'type': 'table', 'filter': {'and': [{'prop': 'title', 'op': 'contains', 'value': '가'*190}]*10}}}")"
# 독립 검토 6-B L-2 — 이름 바꾸기도 같은 이름 금지
expect "뷰 이름을 다른 뷰 이름으로 바꾸기 → 400" "400" "$(views claude_employee01 "$D1" "{'update': {'id': '$V1', 'name': '공유자 뷰'}}")"
for i in $(seq 1 7); do views claude_employee01 "$D1" "{'add': {'name': '뷰$i', 'type': 'table'}}" > /dev/null; done
expect "11번째 뷰 → 400" "400" "$(views claude_employee01 "$D1" "{'add': {'name': '뷰11', 'type': 'table'}}")"
V2=$(psqlq "SELECT v->>'id' FROM community.databases d, jsonb_array_elements(d.views) v WHERE d.public_id='$D1' AND v->>'name'='뷰7'")
expect "뷰 지우기 → 200" "200" "$(views claude_employee01 "$D1" "{'remove': {'id': '$V2'}}")"
expect "  뷰 9개" "9" "$(jget "len(d['views'])" < "$WORK/b")"
unshare claude_employee01 "$D1P" claude_employee02 > /dev/null

# ════════════════════════════════════════════════════════════════
hr "C:T-141  보드 — 카드 옮기기 = 상태 칸"
expect "주인 상태 바꾸기(doing) → 200" "200" "$(capi PATCH "/api/pages/$R1/properties" claude_employee01 <(printf '{"status":"doing"}'))"
expect "보기만 하는 사원 상태 바꾸기 → 403" "403" "$(capi PATCH "/api/pages/$R1/properties" claude_employee02 <(printf '{"status":"done"}'))"
capi PATCH "/api/pages/$R2/properties" claude_employee01 <(printf '{"status":"done"}') > /dev/null

# ════════════════════════════════════════════════════════════════
hr "C:T-142  계산 (T-29) — 보이는 행만"
summ claude_employee01 "$D1" > /dev/null
expect "주인: 행 3 · 완료 1 · 금액 합 1050" "3 1 1050" "$(jget "str(d['total'])+' '+str(d['done'])+' '+str(d['props']['$K_NUM']['sum'])" < "$WORK/b")"
expect "  체크 2 · 가장 가까운 마감 2099-01-02(비밀 행)" "2 2099-01-02" "$(jget "str(d['props']['$K_CHK']['checked'])+' '+d['props']['$K_DATE']['next']" < "$WORK/b")"
summ claude_employee02 "$D1" > /dev/null
expect "다른 사원: 행 2 · 금액 합 150 (비밀 행 900 빠짐)" "2 150" "$(jget "str(d['total'])+' '+str(d['props']['$K_NUM']['sum'])" < "$WORK/b")"
expect "  체크 1 · 가장 가까운 마감 2099-01-10(비밀 행 날짜 없음)" "1 2099-01-10" "$(jget "str(d['props']['$K_CHK']['checked'])+' '+d['props']['$K_DATE']['next']" < "$WORK/b")"
lacks "  응답에 비밀 행 제목·값 없음" "C6B-비밀" "$(cat "$WORK/b")"
expect "거르기 안만(분류 = B) → 합 50" "50" "$(summ claude_employee01 "$D1" "?filter=$(python3 -c "import urllib.parse,json;print(urllib.parse.quote(json.dumps({'and':[{'prop':'$K_SEL','op':'eq','value':'B'}]})))")" > /dev/null; jget "d['props']['$K_NUM']['sum']" < "$WORK/b")"
expect "주입 거르기 → 400" "400" "$(summ claude_employee01 "$D1" "?filter=%7B%22and%22%3A%5B%7B%22prop%22%3A%22x%27%22%2C%22op%22%3A%22eq%22%7D%5D%7D")"
expect "못 보는 사람(manager01 은 사내라 봄 → guest)의 계산 → 403" "403" "$(summ claude_guest01 "$D1")"

# ════════════════════════════════════════════════════════════════
hr "C:T-143  관계 — 대상 · 같은 400 · 제목 권한"
read D2P D2 <<< "$(mkdb claude_employee01 'C6B-분류 표')"
newrow claude_employee01 "$D2P" 'C6B-분류 가' > /dev/null; C1=$(rid)
newrow claude_employee01 "$D2P" 'C6B-분류 비밀' > /dev/null; C2=$(rid)
read D9P D9 <<< "$(mkdb claude_employee02 'C6B-남의 표')"
newrow claude_employee02 "$D9P" 'C6B-남의 행' > /dev/null; X1=$(rid)
expect "남의 공간 DB 를 대상으로 → 400" "400" "$(schema claude_employee01 "$D1" "{'add': {'name': '엉뚱', 'type': 'relation', 'target': '$D9'}}")"
expect "없는 DB 를 대상으로 → 400" "400" "$(schema claude_employee01 "$D1" "{'add': {'name': '없음', 'type': 'relation', 'target': '00000000-0000-4000-8000-000000000000'}}")"
expect "관계에 선택지 → 400" "400" "$(schema claude_employee01 "$D1" "{'add': {'name': '틀림', 'type': 'relation', 'target': '$D2', 'options': ['a']}}")"
K_REL=$(addprop claude_employee01 "$D1" '분류 행' relation "" )
expect "target 없는 관계 → 400" "" "$K_REL"
schema claude_employee01 "$D1" "{'add': {'name': '분류 행', 'type': 'relation', 'target': '$D2'}}" > /dev/null
K_REL=$(jget "[p['key'] for p in d['database']['schema'] if p['name']=='분류 행'][0]" < "$WORK/b")
expect "  관계 속성 만들었다" "yes" "$([[ "$K_REL" =~ ^p[0-9a-f]{8}$ ]] && echo yes)"
expect "관계 값(대상 DB 의 행 둘) → 200" "200" "$(setv claude_employee01 "$R1" "{'$K_REL': ['$C1','$C2']}")"
s1=$(setv claude_employee01 "$R2" "{'$K_REL': ['$X1']}"); b1=$(cat "$WORK/b")
s2=$(setv claude_employee01 "$R2" "{'$K_REL': ['00000000-0000-4000-8000-000000000000']}"); b2=$(cat "$WORK/b")
s3=$(setv claude_employee01 "$R2" "{'$K_REL': ['$R1']}"); b3=$(cat "$WORK/b")
expect "남의 행(못 봄) → 400" "400" "$s1"
expect "없는 행 → 400" "400" "$s2"
expect "대상 DB 가 아닌 행(같은 DB 의 행) → 400" "400" "$s3"
expect "  세 응답 원문 같음" "$b1" "$b2"
expect "  (셋째도)" "$b1" "$b3"
expect "관계 21개 → 400" "400" "$(setv claude_employee01 "$R2" "{'$K_REL': [str(__import__('uuid').uuid4()) for _ in range(21)]}")"
capi GET "/api/databases/$D1/rows" claude_employee01 > /dev/null
contains "주인 — 관계 제목 지도에 둘 다" "C6B-분류 비밀" "$(jget "json.dumps(d['relation_titles'],ensure_ascii=False)" < "$WORK/b")"
capi PATCH "/api/pages/$D2P/visibility" claude_employee01 <(printf '{"visibility":"internal","cascade":true}') > /dev/null
set_vis claude_employee01 "$C2" private
capi GET "/api/databases/$D1/rows" claude_employee02 > /dev/null
contains "다른 사원 — 볼 수 있는 분류 제목은 있다" "C6B-분류 가" "$(jget "json.dumps(d['relation_titles'],ensure_ascii=False)" < "$WORK/b")"
lacks "  비밀 분류 제목은 없다" "C6B-분류 비밀" "$(cat "$WORK/b")"
capi GET "/api/pages/$R1" claude_employee02 > /dev/null
lacks "  행 페이지 창구에도 없다" "C6B-분류 비밀" "$(cat "$WORK/b")"
# 독립 검토 6-B L-3 — 대상 행 하나가 휴지통에 가도 그 칸의 다른 값은 고칠 수 있다
newrow claude_employee01 "$D2P" 'C6B-분류 나' > /dev/null; C3=$(rid)
capi DELETE "/api/pages/$C2" claude_employee01 > /dev/null
expect "지운 대상 행이 남은 채로 새 행 더하기 → 200" "200" "$(setv claude_employee01 "$R1" "{'$K_REL': ['$C1','$C2','$C3']}")"
expect "관계로 거르기(has 분류 가) → 1행" "1" "$(capi GET "/api/databases/$D1/rows?filter=$(python3 -c "import urllib.parse,json;print(urllib.parse.quote(json.dumps({'and':[{'prop':'$K_REL','op':'has','value':'$C1'}]})))")" claude_employee01 > /dev/null; jget "d['total']" < "$WORK/b")"
expect "처리 이력에는 행 id·제목 없이 개수만" "행 3개" "$(psqlq "SELECT e.to_value FROM community.page_property_events e JOIN community.pages p ON p.id=e.page_id WHERE p.public_id='$R1' AND e.prop='db:$K_REL' ORDER BY e.id DESC LIMIT 1")"
expect "같은 공간 DB 목록 창구(주인) — 둘 이상" "yes" "$(capi GET "/api/databases?space=$(psqlq "SELECT space_id FROM community.pages WHERE public_id='$D1P'")" claude_employee01 > /dev/null; [ "$(jget "len(d['databases'])" < "$WORK/b")" -ge 2 ] && echo yes)"
lacks "  남의 공간의 비공개 DB(employee02 의 표) 이름은 목록에 없다" "C6B-남의 표" "$(capi GET "/api/databases?space=$(psqlq "SELECT space_id FROM community.pages WHERE public_id='$D9P'")" claude_employee01 > /dev/null; cat "$WORK/b")"

# ════════════════════════════════════════════════════════════════
hr "C:T-144  파일 속성 — 5-E 첨부 규칙"
F_INT=$(upload claude_employee01 'C6B-사내.txt' internal)
F_T1=$(upload claude_employee01 'C6B-팀1.txt' team "$TEAM1")
F_T2=$(upload claude_employee02 'C6B-팀2.txt' team "$TEAM2")
psqlq "UPDATE public.files SET scan_status='clean' WHERE public_id IN ('$F_INT','$F_T1','$F_T2')" > /dev/null
schema claude_employee01 "$D1" "{'add': {'name': '첨부', 'type': 'file'}}" > /dev/null
K_FILE=$(jget "[p['key'] for p in d['database']['schema'] if p['name']=='첨부'][0]" < "$WORK/b")
expect "사내 행에 팀 파일 → 400" "400" "$(setv claude_employee01 "$R1" "{'$K_FILE': ['tbox:$F_T1']}")"
s1=$(setv claude_employee01 "$R1" "{'$K_FILE': ['tbox:$F_T2']}"); b1=$(cat "$WORK/b")
s2=$(setv claude_employee01 "$R1" "{'$K_FILE': ['tbox:$(python3 -c 'import uuid;print(uuid.uuid4())')']}"); b2=$(cat "$WORK/b")
expect "남의 팀 파일 → 400" "400" "$s1"
expect "없는 파일 → 400 · 원문 같음" "400 same" "$s2 $([ "$b1" = "$b2" ] && echo same)"
expect "tbox: 모양 아님 → 400" "400" "$(setv claude_employee01 "$R1" "{'$K_FILE': ['http://x/y']}")"
expect "사내 행에 사내 파일 → 200" "200" "$(setv claude_employee01 "$R1" "{'$K_FILE': ['tbox:$F_INT']}")"
expect "비공개 행에 내 팀 파일 → 200" "200" "$(setv claude_employee01 "$RS" "{'$K_FILE': ['tbox:$F_T1']}")"
expect "  그 행을 사내로 넓히기 → 400 file_narrower" "400" "$(capi PATCH "/api/pages/$RS/visibility" claude_employee01 <(printf '{"visibility":"internal"}'))"
contains "  사유" "file_narrower" "$(cat "$WORK/b")"
capi GET "/api/databases/$D1/rows" claude_employee02 > /dev/null
contains "다른 사원 — 사내 파일 이름은 보인다" "C6B-사내.txt" "$(jget "json.dumps(d['file_titles'],ensure_ascii=False)" < "$WORK/b")"
lacks "  팀 파일 이름은 없다" "C6B-팀1" "$(cat "$WORK/b")"

# ════════════════════════════════════════════════════════════════
hr "C:T-145  브라우저 — browser_6b.py"
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
  SMOKE_E1="$(cookie_of claude_employee01)" SMOKE_DBP="$D1P" SMOKE_SHOTS="${SHOTS:-}" \
    python3 "$ROOT/tests/community/browser_6b.py" "$SITE" "$UA" > "$WORK/smoke.txt" 2>&1
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
bash -n "$ROOT/tests/community/stage6b.sh" && expect "stage6b.sh 문법" "0" "0"
python3 -c "import ast,io;ast.parse(io.open('$ROOT/tests/community/browser_6b.py',encoding='utf-8').read())" \
  && expect "browser_6b.py 문법" "0" "0"
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
  for s in ${REGRESS_LIST:-community/stage3 community/stage4a community/stage4b community/stage4c community/stage4d community/stage5a community/stage5a2 community/stage5c community/stage5d community/stage5e community/stage6a stage26 help_button api_whoami api_read api_folders api_upload api_tokens f65 f66 f68}; do
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

