#!/usr/bin/env bash
# tests/community/stage6a.sh — 6-A 수락 기준 C:T-130 ~ C:T-137 실행 (데이터베이스 핵심)
#                              (../../../community/stage6a-database-directive.md 「완료 기준」)
#
#   C:T-130 스키마     속성 더하기·이름·선택지·지우기 · 모르는 종류·31번째·같은 이름·기본 열 이름 400 · 관리 자격(편집 공유자 403)
#   C:T-131 값         종류별 모양 400 · 모르는 속성 400 · 사람 값은 그 행을 볼 수 있는 사람만 · 값마다 처리 이력 · 기록 불변
#   C:T-132 행 = 페이지 DB 페이지 아래 새 페이지 = 행 · 트리·하위 목록에 행 없음 · 비공개 DB 의 행은 남에게 목록·검색·행 목록 어디에도 없다
#   C:T-133 (T-28)     거르기·정렬 퍼징 — 모르는 prop·op·중첩·주입 글자 400, SQL 에 사용자 글자 0, 결과 정확
#   C:T-134 옮기기     DB 밖으로 옮기면 행이 아니고(값 버림 + 이력) 안으로 옮기면 행 · DB 안 DB 400 · 게시 400
#   C:T-135 한도       행 5,000 · 속성 30 · 다중 선택 20
#   C:T-136 경합       스키마 동시 고치기 409 · 값은 칸 단위로 합쳐진다
#   C:T-137 브라우저   browser_6a.py — DB 만들기 · 속성 더하기 · 칸 편집 · 새 행 · 거르기·정렬 · 행 페이지 속성 줄, 라이트·다크·390px
#   회귀 (REGRESS=1)
#
# ★ 이 시험은 **운영 DB 를 한 글자도 건드리지 않는다** — stage5e.sh 와 같은 틀(임시 DB `tbox_6a_test`, 포트 8090).
# 사용: sudo -v && bash tests/community/stage6a.sh     (REGRESS=1 이면 회귀까지, SHOTS=<폴더> 면 스크린샷)
set -u

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
. "$ROOT/tests/lib/secrets.sh" || { echo "tests/lib/secrets.sh 를 읽지 못했다 — 시험을 시작하지 않는다 (R-62)"; exit 3; }
PORT=8090
SITE="http://127.0.0.1:$PORT"
BASE="$SITE/tbox"
CBASE="$SITE/community"
UA="tbox-review/$(date +%F)"
SRC_DB="tbox_re"          # 운영 — **읽기만** 한다
DB="tbox_6a_test"         # 임시 — 여기에만 쓴다
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
hr "6-A — 임시 인스턴스 준비 (운영 DB 에는 쓰지 않는다)"

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
hr "C:T-130  스키마 — 속성 더하기·이름·선택지·지우기 · 관리 자격"
read D1P D1 <<< "$(mkdb claude_employee01 'C6A-업무 표')"
expect "DB 만들기 → 201" "201" "$(cat "$WORK/st")"
note "DB 페이지 $D1P · DB $D1"
expect "  databases 한 줄" "1" "$(psqlq "SELECT count(*) FROM community.databases d JOIN community.pages p ON p.id=d.page_id WHERE p.public_id='$D1P'")"
capi GET "/api/pages/$D1P" claude_employee01 > /dev/null
expect "  페이지 창구에 database(스키마 빈 목록)" "0" "$(jget "len(d['database']['schema'])" < "$WORK/b")"
# BF-0009 — 아래 「database.schema allow ≥ 10」 은 이번 실행의 행만 센다. 그 행을 남기는 첫 요청(속성 더하기) **바로 앞**에서 잰다
# (임시 DB 는 운영 사본이라 옛 행이 이미 있다 — 경계가 없으면 이번 요청이 기록을 남기지 않아도 통과한다).
SCHEMA_MARK=$(mark)
K_NUM=$(addprop claude_employee01 "$D1" '금액' number)
K_SEL=$(addprop claude_employee01 "$D1" '분류' select "['A','B','C']")
K_MUL=$(addprop claude_employee01 "$D1" '태그' multi_select "['x','y','z']")
K_PER=$(addprop claude_employee01 "$D1" '담당' person)
K_DATE=$(addprop claude_employee01 "$D1" '마감' date)
K_CHK=$(addprop claude_employee01 "$D1" '완료' checkbox)
K_TXT=$(addprop claude_employee01 "$D1" '메모' text)
K_URL=$(addprop claude_employee01 "$D1" '링크' url)
expect "속성 여덟 — key 는 서버가 만든 모양(p + 8hex)" "8" \
  "$(for k in $K_NUM $K_SEL $K_MUL $K_PER $K_DATE $K_CHK $K_TXT $K_URL; do [[ "$k" =~ ^p[0-9a-f]{8}$ ]] && echo ok; done | wc -l | tr -d ' ')"
expect "모르는 종류(formula) → 400" "400" "$(schema claude_employee01 "$D1" "{'add': {'name': '수식', 'type': 'formula'}}")"
expect "기본 열 이름(상태) → 400" "400" "$(schema claude_employee01 "$D1" "{'add': {'name': '상태', 'type': 'text'}}")"
expect "같은 이름(금액) → 400" "400" "$(schema claude_employee01 "$D1" "{'add': {'name': '금액', 'type': 'text'}}")"
expect "빈 이름 → 400" "400" "$(schema claude_employee01 "$D1" "{'add': {'name': '  ', 'type': 'text'}}")"
expect "선택지 없는 select → 400" "400" "$(schema claude_employee01 "$D1" "{'add': {'name': '선택2', 'type': 'select'}}")"
expect "text 에 선택지 → 400" "400" "$(schema claude_employee01 "$D1" "{'add': {'name': '글2', 'type': 'text', 'options': ['a']}}")"
expect "겹치는 선택지 → 400" "400" "$(schema claude_employee01 "$D1" "{'add': {'name': '선택3', 'type': 'select', 'options': ['a','a']}}")"
expect "요청이 key 를 정하려 함(허용 키 밖) → 400" "400" "$(schema claude_employee01 "$D1" "{'add': {'name': '키', 'type': 'text', 'key': 'x'}}")"
expect "두 가지를 한꺼번에 → 400" "400" "$(schema claude_employee01 "$D1" "{'add': {'name': '둘', 'type': 'text'}, 'remove': {'key': '$K_TXT'}}")"
expect "이름 바꾸기 → 200" "200" "$(schema claude_employee01 "$D1" "{'rename': {'key': '$K_NUM', 'name': '금액(원)'}}")"
expect "선택지 바꾸기 → 200" "200" "$(schema claude_employee01 "$D1" "{'options': {'key': '$K_SEL', 'options': ['A','B','C','D']}}")"
expect "  선택지 넷" "4" "$(jget "len([p for p in d['database']['schema'] if p['key']=='$K_SEL'][0]['options'])" < "$WORK/b")"
expect "없는 key 지우기 → 400" "400" "$(schema claude_employee01 "$D1" "{'remove': {'key': 'p00000000'}}")"
m0=$(mark)
expect "남(볼 수 없음) 스키마 고치기 → 403" "403" "$(schema claude_employee02 "$D1" "{'add': {'name': '침입', 'type': 'text'}}")"
share claude_employee01 "$D1P" claude_employee02 edit > /dev/null
expect "편집 공유자 스키마 고치기 → 403 (관리 자격만)" "403" "$(schema claude_employee02 "$D1" "{'add': {'name': '침입', 'type': 'text'}}")"
expect "  감사 database.schema deny not_owner" "1" "$(audit_n "$m0" database.schema deny not_owner)"
expect "  감사 database.schema allow ≥ 10" "yes" "$([ "$(psqlq "SELECT count(*) FROM audit_logs WHERE id > ${SCHEMA_MARK:-0} AND action='database.schema' AND result='allow'")" -ge 10 ] && echo yes || echo no)"
# 속성 30개 한도 — 따로 DB
read D2P D2 <<< "$(mkdb claude_employee01 'C6A-한도 표')"
for i in $(seq 1 30); do schema claude_employee01 "$D2" "{'add': {'name': '칸$i', 'type': 'text'}}" > /dev/null; done
expect "31번째 속성 → 400" "400" "$(schema claude_employee01 "$D2" "{'add': {'name': '칸31', 'type': 'text'}}")"
# 경합 — 읽은 판이 옛것이면 409
capi GET "/api/pages/$D1P" claude_employee01 > /dev/null
OLDV=$(psqlq "SELECT updated_at FROM community.databases WHERE public_id='$D1'")
schema claude_employee01 "$D1" "{'rename': {'key': '$K_TXT', 'name': '메모 1'}}" > /dev/null
expect "스키마 옛 판(version)으로 고치기 → 409 (C:T-136)" "409" "$(schema claude_employee01 "$D1" "{'rename': {'key': '$K_TXT', 'name': '메모 2'}, 'version': '$OLDV'}")"

# ════════════════════════════════════════════════════════════════
hr "C:T-131  값 — 모양 · 사람 · 처리 이력"
expect "새 행(값 둘) → 201" "201" "$(newrow claude_employee01 "$D1P" 'C6A-행1' "{'$K_NUM': 1200, '$K_SEL': 'A'}")"; R1=$(rid)
expect "  행 값 확인" "1200 A" "$(jget "str(d['page']['values']['$K_NUM'])+' '+d['page']['values']['$K_SEL']" < "$WORK/b")"
expect "  행은 database_id 를 가진다" "t" "$(pg_page "$R1" "(p.database_id IS NOT NULL)")"
for bad in "{'$K_NUM': 'abc'}" "{'$K_NUM': 1e300}" "{'$K_SEL': 'Z'}" "{'$K_MUL': ['x','x']}" "{'$K_MUL': 'x'}" \
           "{'$K_DATE': {'start': '2026-02-30'}}" "{'$K_DATE': {'start': '2026-03-02', 'end': '2026-03-01'}}" \
           "{'$K_DATE': {'start': '2026-03-01', 'x': 1}}" "{'$K_URL': 'javascript:alert(1)'}" "{'$K_TXT': 'a\u0007b'}" \
           "{'$K_CHK': 'yes'}" "{'p00000000': 1}" "{'title': 'x'}"; do
  expect "잘못된 값 $bad → 400" "400" "$(setv claude_employee01 "$R1" "$bad")"
done
st1=$(setv claude_employee01 "$R1" "{'$K_PER': 'claude_employee02'}"); b1=$(cat "$WORK/b")
expect "사람 값 — 이 행을 볼 수 없는 사람(공유 전 employee02는 DB 편집 공유라 봄 → manager 로)" "200" "$st1"
st2=$(setv claude_employee01 "$R1" "{'$K_PER': 'claude_guest01'}"); b2=$(cat "$WORK/b")
st3=$(setv claude_employee01 "$R1" "{'$K_PER': 'claude_nobody_zz'}"); b3=$(cat "$WORK/b")
expect "사람 값 — 못 보는 사람(claude_guest01) → 400" "400" "$st2"
expect "사람 값 — 없는 사람 → 400" "400" "$st3"
expect "  두 응답 원문이 같다" "$b2" "$b3"
e0=$(events_db "$R1")
expect "여러 값 한꺼번에 → 200" "200" "$(setv claude_employee01 "$R1" "{'$K_MUL': ['x','z'], '$K_DATE': {'start': '2026-10-01', 'end': '2026-10-05'}, '$K_CHK': True, '$K_TXT': '첫 줄\n둘째 줄', '$K_URL': 'https://example.com/a?b=1'}")"
expect "  처리 이력 다섯 줄 늘었다" "$((e0+5))" "$(events_db "$R1")"
setv claude_employee01 "$R1" "{'$K_DATE': {'end': '2026-10-05', 'start': '2026-10-01'}}" > /dev/null
expect "  같은 값을 다시(키 순서만 다름) → 이력 그대로" "$((e0+5))" "$(events_db "$R1")"
expect "값 지우기(null) → 200 · 키 사라짐" "200 False" "$(setv claude_employee01 "$R1" "{'$K_CHK': None}") $(jget "'$K_CHK' in d['values']" < "$WORK/b")"
expect "  이력 한 줄(예 → 없음)" "1" "$(psqlq "SELECT count(*) FROM community.page_property_events e JOIN community.pages p ON p.id=e.page_id WHERE p.public_id='$R1' AND e.prop='db:$K_CHK' AND e.from_value='예' AND e.to_value IS NULL")"
expect "행이 아닌 페이지 값 → 400" "400" "$(setv claude_employee01 "$D1P" "{'$K_NUM': 1}")"
PN=$(make_page claude_employee01 'C6A-그냥 페이지')
expect "DB 가 아닌 부모 아래 새 페이지에 properties → 400" "400" "$(jbody "{'title':'x','parent_id':'$PN','properties':{}}"; capi POST /api/pages claude_employee01 "$WORK/body.json")"
share claude_employee01 "$D1P" claude_manager01 view > /dev/null
m1=$(mark)
expect "보기 공유자 값 바꾸기 → 403" "403" "$(setv claude_manager01 "$R1" "{'$K_NUM': 1}")"
expect "  감사 page.values deny not_owner" "1" "$(audit_n "$m1" page.values deny not_owner)"
expect "편집 공유자(employee02) 값 바꾸기 → 200" "200" "$(setv claude_employee02 "$R1" "{'$K_NUM': 1500}")"
expect "처리 이력(page_property_events) DELETE (tbox_app) → 42501" "42501" "$(appq "DELETE FROM community.page_property_events" | sed -n 's/.*ERROR: *\([0-9A-Z]\{5\}\).*/\1/p')"
expect "databases DELETE (tbox_app) → 42501" "42501" "$(appq "DELETE FROM community.databases" | sed -n 's/.*ERROR: *\([0-9A-Z]\{5\}\).*/\1/p')"

# ════════════════════════════════════════════════════════════════
hr "C:T-132  행 = 페이지 — 트리·목록·검색·행 목록"
capi GET "/api/pages?tree=1" claude_employee01 > /dev/null
lacks "트리에 행 없음" "C6A-행1" "$(cat "$WORK/b")"
contains "  트리에 DB 페이지는 있음" "C6A-업무 표" "$(cat "$WORK/b")"
capi GET "/api/pages/$D1P" claude_employee01 > /dev/null
lacks "DB 페이지의 하위 목록에 행 없음" "C6A-행1" "$(jget "json.dumps(d['children'],ensure_ascii=False)" < "$WORK/b")"
expect "행 목록(주인) → 200 · 1행" "200 1" "$(rows claude_employee01 "$D1") $(jget "d['total']" < "$WORK/b")"
unshare claude_employee01 "$D1P" claude_manager01 > /dev/null
expect "볼 수 없는 사람(manager01, 공유 뺌) 행 목록 → 403" "403" "$(rows claude_manager01 "$D1")"
expect "없는 DB → 404" "404" "$(rows claude_employee01 "00000000-0000-4000-8000-000000000000")"
capi GET "/api/search?q=C6A-%ED%96%891" claude_manager01 > /dev/null
lacks "  검색 결과에도 없음" "C6A-행1" "$(jget "json.dumps(d['results'],ensure_ascii=False)" < "$WORK/b")"
# 사내 DB + 좁힌 행 하나
read D3P D3 <<< "$(mkdb claude_employee01 'C6A-사내 표')"
set_vis claude_employee01 "$D3P" internal
newrow claude_employee01 "$D3P" 'C6A-사내 행' > /dev/null; R_PUB=$(rid)
newrow claude_employee01 "$D3P" 'C6A-비밀 행' > /dev/null; R_PRIV=$(rid)
expect "  새 행은 DB 와 같은 범위로 시작(internal)" "internal" "$(pg_page "$R_PUB" "p.visibility")"
set_vis claude_employee01 "$R_PRIV" private
rows claude_manager01 "$D3" > /dev/null
expect "다른 사원 — 사내 행만 · 수도 1" "1" "$(jget "d['total']" < "$WORK/b")"
lacks "  비밀 행 제목 없음" "C6A-비밀 행" "$(cat "$WORK/b")"
expect "  can_add_rows 거짓(남의 DB)" "False" "$(jget "d['can_add_rows']" < "$WORK/b")"
expect "남의 DB 에 새 행 → 403" "403" "$(newrow claude_manager01 "$D3P" 'C6A-침입 행')"

# ════════════════════════════════════════════════════════════════
hr "C:T-133  거르기·정렬 (T-28) — 허용 목록 · 정확한 결과 · 주입 400"
newrow claude_employee01 "$D1P" 'C6A-행2' "{'$K_NUM': 300, '$K_SEL': 'B', '$K_MUL': ['y'], '$K_CHK': True, '$K_DATE': {'start': '2026-09-01'}}" > /dev/null; R2=$(rid)
newrow claude_employee01 "$D1P" 'C6A-행3' "{'$K_NUM': 5000, '$K_SEL': 'A', '$K_MUL': ['x','y'], '$K_DATE': {'start': '2026-12-25'}}" > /dev/null; R3=$(rid)
fcount() { rows claude_employee01 "$D1" "$1" "${2:-}" > /dev/null; jget "d['total']" < "$WORK/b"; }
expect "금액 > 1000 → 2행(1500·5000)" "2" "$(fcount "{\"and\":[{\"prop\":\"$K_NUM\",\"op\":\"gt\",\"value\":1000}]}")"
expect "분류 = A → 2행" "2" "$(fcount "{\"and\":[{\"prop\":\"$K_SEL\",\"op\":\"eq\",\"value\":\"A\"}]}")"
expect "태그에 y → 2행" "2" "$(fcount "{\"and\":[{\"prop\":\"$K_MUL\",\"op\":\"has\",\"value\":\"y\"}]}")"
expect "완료 = 참 → 1행" "1" "$(fcount "{\"and\":[{\"prop\":\"$K_CHK\",\"op\":\"is\",\"value\":true}]}")"
expect "마감 < 2026-10-01 → 1행" "1" "$(fcount "{\"and\":[{\"prop\":\"$K_DATE\",\"op\":\"before\",\"value\":\"2026-10-01\"}]}")"
expect "제목에 '행3' → 1행" "1" "$(fcount "{\"and\":[{\"prop\":\"title\",\"op\":\"contains\",\"value\":\"행3\"}]}")"
expect "메모 비어 있음 → 2행" "2" "$(fcount "{\"and\":[{\"prop\":\"$K_TXT\",\"op\":\"empty\"}]}")"
expect "조건 둘(AND) — A 이고 > 2000 → 1행" "1" "$(fcount "{\"and\":[{\"prop\":\"$K_SEL\",\"op\":\"eq\",\"value\":\"A\"},{\"prop\":\"$K_NUM\",\"op\":\"gt\",\"value\":2000}]}")"
rows claude_employee01 "$D1" "" "[{\"prop\":\"$K_NUM\",\"dir\":\"desc\"}]" > /dev/null
expect "금액 내림차순" "C6A-행3 C6A-행1 C6A-행2" "$(jget "' '.join(r['title'] for r in d['rows'])" < "$WORK/b")"
rows claude_employee01 "$D1" "" "[{\"prop\":\"title\",\"dir\":\"asc\"}]" > /dev/null
expect "제목 오름차순" "C6A-행1 C6A-행2 C6A-행3" "$(jget "' '.join(r['title'] for r in d['rows'])" < "$WORK/b")"
for badf in "{\"and\":[{\"prop\":\"p'; DROP TABLE community.pages;--\",\"op\":\"eq\",\"value\":\"x\"}]}" \
            "{\"and\":[{\"prop\":\"$K_NUM\",\"op\":\"gt OR 1=1\",\"value\":1}]}" \
            "{\"and\":[{\"prop\":\"$K_NUM\",\"op\":\"gt\",\"value\":\"1 OR 1=1\"}]}" \
            "{\"and\":[{\"prop\":\"$K_NUM\",\"op\":\"gt\",\"value\":{\"a\":1}}]}" \
            "{\"and\":[{\"and\":[{\"prop\":\"title\",\"op\":\"eq\",\"value\":\"x\"}]}]}" \
            "{\"or\":[]}" "not json" "{\"and\":[{\"prop\":\"properties\",\"op\":\"eq\",\"value\":\"x\"}]}" \
            "{\"and\":[{\"prop\":\"$K_SEL\",\"op\":\"eq\",\"value\":\"없는 선택지\"}]}" \
            "{\"and\":[{\"prop\":\"$K_NUM\",\"op\":\"contains\",\"value\":\"1\"}]}" \
            "{\"and\":[{\"prop\":\"p00000000\",\"op\":\"empty\"}]}"; do
  expect "거르기 거부 ${badf:0:60} → 400" "400" "$(rows claude_employee01 "$D1" "$badf")"
done
expect "조건 11개 → 400" "400" "$(rows claude_employee01 "$D1" "$(python3 -c "import json;print(json.dumps({'and':[{'prop':'title','op':'contains','value':'a'}]*11}))")")"
for bads in "[{\"prop\":\"$K_NUM\",\"dir\":\"sideways\"}]" "[{\"prop\":\"$K_NUM; DROP\",\"dir\":\"asc\"}]" "{\"prop\":\"title\"}"; do
  expect "정렬 거부 ${bads:0:40} → 400" "400" "$(rows claude_employee01 "$D1" "" "$bads")"
done
expect "주입 뒤에도 pages 표는 그대로" "yes" "$([ "$(psqlq "SELECT count(*) FROM community.pages")" -gt 0 ] && echo yes)"
lacks "서버 기록에 SQL 오류 없음" "SQLSTATE" "$(tail -n 200 "$INST/server.log" 2>/dev/null)"

# ════════════════════════════════════════════════════════════════
hr "C:T-134  옮기기 · DB 안 DB · 게시"
e1=$(events_db "$R3")
expect "행을 DB 밖(뿌리)으로 옮기기 → 200" "200" "$(capi PATCH "/api/pages/$R3/move" claude_employee01 <(printf '{"parent_id":null}'))"
expect "  이제 행이 아니다(database_id·properties 비움)" "t" "$(pg_page "$R3" "(p.database_id IS NULL AND p.properties IS NULL)")"
expect "  버린 값 넷이 이력에(→ 없음)" "$((e1+4))" "$(events_db "$R3")"
expect "  DB 행 목록에서 빠졌다" "2" "$(rows claude_employee01 "$D1" > /dev/null; jget "d['total']" < "$WORK/b")"
expect "다시 DB 안으로 → 200 · 행(빈 값)" "200 {}" "$(capi PATCH "/api/pages/$R3/move" claude_employee01 <(printf '{"parent_id":"%s"}' "$D1P")) $(pg_page "$R3" "p.properties")"
expect "DB 페이지 아래에 DB 만들기 → 400" "400" "$(mkdb claude_employee01 'C6A-안쪽 DB' "$D1P" > /dev/null; cat "$WORK/st")"
expect "DB 를 다른 DB 안으로 옮기기 → 400" "400" "$(capi PATCH "/api/pages/$D2P/move" claude_employee01 <(printf '{"parent_id":"%s"}' "$D1P"))"
# 독립 검토 M-1 — 못 쓰는 사람에게는 DB 여부·속성·행 수가 새지 않는다(부모 판정이 먼저 403)
expect "남(manager01)이 남의 DB 를 부모로 DB 만들기 → 403 (db_in_db 400 아님)" "403" "$(mkdb claude_manager01 'C6A-떠보기' "$D1P" > /dev/null; cat "$WORK/st")"
expect "남이 남의 DB 에 속성 값 든 새 행 → 403 (bad_value 400 아님)" "403" "$(newrow claude_manager01 "$D1P" 'C6A-떠보기 행' "{'$K_NUM': 'x'}")"
lacks "  응답에 속성 이름 없음" "금액" "$(cat "$WORK/b")"
# 독립 검토 L-2 — 게시 중인 페이지는 DB 안으로 옮길 수 없다
PPUB=$(make_page claude_employee01 'C6A-게시 페이지'); set_vis claude_employee01 "$PPUB" internal
publish claude_employee01 "$PPUB" "$B_FREE" > /dev/null
expect "게시 중인 페이지를 DB 안으로 옮기기 → 400" "400" "$(capi PATCH "/api/pages/$PPUB/move" claude_employee01 <(printf '{"parent_id":"%s"}' "$D3P"))"
expect "DB 페이지 게시 → 400" "400" "$(publish claude_employee01 "$D1P" "$B_FREE")"
expect "행 게시 → 400" "400" "$(publish claude_employee01 "$R1" "$B_FREE")"
capi GET "/api/pages/$D1P" claude_employee01 > /dev/null
expect "  페이지 창구 can_publish 거짓" "False" "$(jget "d['can_publish']" < "$WORK/b")"

# ════════════════════════════════════════════════════════════════
hr "C:T-135  한도 · C:T-136 값 합치기"
MANY=$(python3 -c "print([str(i) for i in range(25)])")
K_M2=$(addprop claude_employee01 "$D1" '많은 태그' multi_select "$MANY")
expect "다중 선택 21개 → 400" "400" "$(setv claude_employee01 "$R1" "{'$K_M2': [str(i) for i in range(21)]}")"
expect "다중 선택 20개 → 200" "200" "$(setv claude_employee01 "$R1" "{'$K_M2': [str(i) for i in range(20)]}")"
psqlq "INSERT INTO community.pages (space_id, parent_page_id, title, doc, author_id, last_edited_by, visibility, position, database_id, properties)
       SELECT p.space_id, p.id, 'C6A-채움 '||g, '{\"schema\":1,\"blocks\":[]}'::jsonb, p.author_id, p.author_id, 'private', g, d.id, '{}'::jsonb
         FROM community.pages p JOIN community.databases d ON d.page_id = p.id, generate_series(1, 5000) g
        WHERE p.public_id = '$D2P'" > /dev/null
expect "행 5,000개 찬 DB 에 새 행 → 400 (임시 DB 에서 채움)" "400" "$(newrow claude_employee01 "$D2P" 'C6A-5001')"
rows claude_employee01 "$D2" "{\"and\":[{\"prop\":\"title\",\"op\":\"contains\",\"value\":\"4999\"}]}" > /dev/null
expect "  5,000행에서 거르기 → 1행" "1" "$(jget "d['total']" < "$WORK/b")"
t0=$(date +%s%N); rows claude_employee01 "$D2" "" "[{\"prop\":\"title\",\"dir\":\"desc\"}]" > /dev/null; t1=$(date +%s%N)
note "5,000행 정렬 한 쪽 응답 $(( (t1-t0)/1000000 ))ms"
expect "  5,000행 정렬 응답 < 1500ms(임시 인스턴스)" "yes" "$([ $(( (t1-t0)/1000000 )) -lt 1500 ] && echo yes || echo no)"
setv claude_employee01 "$R2" "{'$K_TXT': '가'}" > /dev/null
setv claude_employee02 "$R2" "{'$K_NUM': 777}" > /dev/null
expect "다른 사람이 다른 칸을 고쳐도 둘 다 남는다" "가 777" "$(pg_page "$R2" "(p.properties->>'$K_TXT')||' '||(p.properties->>'$K_NUM')")"

# ════════════════════════════════════════════════════════════════
hr "C:T-137  브라우저 — browser_6a.py"
export PATH="$HOME/.local/bin:$PATH"
cookie_of() {
  python3 - "$JAR_DIR/$1.jar" <<'PY2'
import sys
for line in open(sys.argv[1], encoding='utf-8', errors='replace'):
    parts = line.rstrip('\n').split('\t')
    if len(parts) == 7 and parts[5] == '__Host-tbox_session':
        print(parts[6]); break
PY2
}
if [ ! -f "$ROOT/tests/community/browser_6a.py" ]; then
  skip "브라우저" "browser_6a.py 아직 없음"
elif ! python3 -c 'import playwright' 2>/dev/null; then
  skip "브라우저" "playwright 가 없다"
else
  SMOKE_E1="$(cookie_of claude_employee01)" SMOKE_PARENT="$PN" SMOKE_SHOTS="${SHOTS:-}" \
    python3 "$ROOT/tests/community/browser_6a.py" "$SITE" "$UA" > "$WORK/smoke.txt" 2>&1
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
bash -n "$ROOT/tests/community/stage6a.sh" && expect "stage6a.sh 문법" "0" "0"
python3 -c "import ast,io;ast.parse(io.open('$ROOT/tests/community/browser_6a.py',encoding='utf-8').read())" \
  && expect "browser_6a.py 문법" "0" "0"
expect "커뮤니티 코드에 역할 문자열 비교 0" "0" \
  "$(grep -rnE "'(Admin|Manager|Employee|Guest)'" "$ROOT/src/community" | grep -vE '^[^:]+:[0-9]+:\s*(\*|//|/\*)' | wc -l | tr -d ' ')"
expect "창구(JSON) 코드가 「이름 (아이디)」를 이어 붙이지 않는다 — tbox_person_label 은 서버 화면 두 파일(셸·런처)만 — 7-D4 멘션 이름 지도(routes.php — 지시서 M-4 · 두 렌더러가 같은 글자)까지 셋뿐" "src/community/routes.php src/views/launcher.php src/views/shell_top.php" \
  "$(cd "$ROOT" && grep -rln 'tbox_person_label(' src | grep -vx 'src/bootstrap.php' | LC_ALL=C sort | tr '\n' ' ' | sed 's/ $//')"

# ════════════════════════════════════════════════════════════════
if [ "${REGRESS:-0}" = "1" ]; then
  hr "회귀 — 같은 임시 인스턴스에서 커뮤니티 3·4a·4b·4c · t-box stage26·help_button·계정 관리 시험들"
  note "각 스크립트를 임시 인스턴스 폴더로 복사해 SITE·DB 만 바꿔 돌린다(운영 8082·tbox_re 에 닿지 않는다)."
  # help_button.sh 가 부르는 scripts/ui_snapshot.sh 도 주소를 품고 있다 — **임시 복사본만** 고친다.
  sed -i "s#http://127.0.0.1:8082#$SITE#g" "$INST/app/scripts/ui_snapshot.sh"
  if grep -v '^\s*#' "$INST/app/scripts/ui_snapshot.sh" | grep -q '8082'; then
    echo "  ✖ ui_snapshot.sh 에 운영 주소가 남았다 — 회귀를 돌리지 않는다"; FAIL=$((FAIL+1)); REGRESS_LIST=" "
  fi
  for s in ${REGRESS_LIST:-community/stage3 community/stage4a community/stage4b community/stage4c community/stage4d community/stage5a community/stage5a2 community/stage5c community/stage5d community/stage5e stage26 help_button api_whoami api_read api_folders api_upload api_tokens f65 f66 f68}; do
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

