#!/usr/bin/env bash
# tests/community/stage7b.sh — 7-B 수락 기준 C:T-170 ~ C:T-177 실행 (템플릿 — C:F-31 · 결정 J-3)
#
#   C:T-170 기본 셋    사이트 기본 셋 셋(회의록·인수인계서·업무 체크리스트) · 마이그레이션 두 번에도 셋 · Guest 는 목록 0
#   C:T-171 저장       첨부·하위 페이지 블록을 빼고 담는다 · 이름·범위·키 검사 · 못 보는 원본 403 · 범위 규칙(팀·사이트·DB) · 한도
#   C:T-172 쓰기       만든 사람 공간 · 비공개로 시작 · 블록 id 새로 · 첨부 따라오지 않음 · 남의 템플릿 403(+감사) · 형식 오류
#   C:T-173 DB 행      속성 값(사람·관계·파일 빼고) · 목록은 행을 더할 수 있는 사람만 · 부모 고정 · 스키마가 바뀌면 다시 거른다
#   C:T-174 지우기     개인=만든 사람 · 팀=만든 사람·Admin · 사이트=Admin · 지운 템플릿 404 · 만든 페이지는 그대로 · 시드는 다시 살아나지 않는다
#   C:T-175 범위 목록  페이지 응답의 `template_scopes` 가 저장 창구 판정과 같다
#   C:T-176 권한·모양  앱 계정 DELETE 불가 · 범위 모양 CHECK · 계정 id 가 응답에 없다
#   C:T-177 브라우저   browser_7b.py — 홈 「템플릿에서」 창 · 「템플릿으로 저장」 창 · DB 새 행 고르개 · 390px
#
# ★ 운영 DB 를 건드리지 않는다 — stage7a.sh 와 같은 틀(임시 DB `tbox_7b_test`, 포트 8095).
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
PORT=8095
SITE="http://127.0.0.1:$PORT"
BASE="$SITE/tbox"
CBASE="$SITE/community"
UA="tbox-review/$(date +%F)"
SRC_DB="tbox_re"          # 운영 — **읽기만** 한다
DB="tbox_7b_test"         # 임시 — 여기에만 쓴다
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
hr "7-B — 임시 인스턴스 준비 (운영 DB 에는 쓰지 않는다)"

avail_kb=$(df -Pk / | awk 'NR==2{print $4}')
echo "루트 파티션 여유: $(df -Ph / | awk 'NR==2{print $4}') (${avail_kb}KB)"
[ "$avail_kb" -ge 1048576 ] || { echo "루트 디스크 여유 1GB 미만 — 시험을 시작하지 않는다 (SECURITY R-80)"; exit 3; }

sudo -n -u postgres psql -d "$SRC_DB" -tAc "SELECT 1" >/dev/null 2>&1 \
  || { echo "psql(postgres) 을 쓸 수 없다 — 이 시험은 임시 DB 가 없으면 성립하지 않는다"; exit 3; }

sub "① 운영 DB 를 **읽어** 임시 DB 를 만든다"
DUMP="/tmp/tbox7b_dump.$$.sql"
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
           2026-09-24_community_discussion.sql 2026-09-25_community_databases.sql 2026-09-25_community_databases.sql 2026-09-26_community_purge.sql 2026-09-27_community_templates.sql 2026-09-27_community_templates.sql 2026-09-28_community_embeds.sql; do
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

# ─── 7-B 도우미 ───────────────────────────────────────────────
tsave() {       # $1 계정 $2 페이지 $3 이름 $4 범위 → 상태 코드 · 새 템플릿 id 는 $WORK/tid
  jbody "{'page_id': '$2', 'name': '''$3''', 'scope': '$4'}"
  local st; st=$(capi POST /api/templates "$1" "$WORK/body.json")
  jget "d['template']['id']" < "$WORK/b" > "$WORK/tid"
  printf '%s' "$st"
}
tid() { cat "$WORK/tid"; }
tlist() {       # $1 계정 [$2 DB id] → 상태 코드 (본문 $WORK/b)
  if [ -n "${2:-}" ]; then capi GET "/api/templates?database=$2" "$1"; else capi GET /api/templates "$1"; fi
}
tuse() {        # $1 계정 $2 템플릿 [$3 파이썬 dict 조각(앞에 쉼표 없이)] → 상태 코드 · 새 페이지 id 는 $WORK/np
  jbody "{'template_id': '$2'$( [ -n "${3:-}" ] && printf ', %s' "$3")}"
  local st; st=$(capi POST /api/pages "$1" "$WORK/body.json")
  jget "d['page']['id']" < "$WORK/b" > "$WORK/np"
  printf '%s' "$st"
}
np() { cat "$WORK/np"; }
scopes_of() {   # $1 계정 $2 페이지 → template_scopes 를 쉼표로
  capi GET "/api/pages/$2" "$1" > /dev/null
  jget "','.join(d.get('template_scopes', ['?']))" < "$WORK/b"
}
tpl_col() { psqlq "SELECT $2 FROM community.templates t WHERE t.public_id = '$1'"; }
reason_of() { jget "d.get('reason','')" < "$WORK/b"; }
detail_n() {    # $1 기준 id $2 action $3 detail 키 $4 값
  psqlq "SELECT count(*) FROM audit_logs WHERE id > ${1:-0} AND action='$2' AND detail->>'$3' = '$4'"
}

capi GET /api/spaces qa_employee01 > /dev/null
TS1=$(psqlq "SELECT id FROM community.spaces WHERE kind='team' AND team_id=${TEAM1:-0}")
note "팀 공간(qa_employee01 팀): ${TS1:-없음}"
MEET=$(psqlq "SELECT public_id FROM community.templates WHERE seed_key='meeting'")
HAND=$(psqlq "SELECT public_id FROM community.templates WHERE seed_key='handover'")
CHKL=$(psqlq "SELECT public_id FROM community.templates WHERE seed_key='checklist'")

# ════════════════════════════════════════════════════════════════
hr "C:T-170  사이트 기본 셋"
expect "시드 셋 — 마이그레이션 두 번 돌린 뒤에도 셋" "3" "$(psqlq "SELECT count(*) FROM community.templates WHERE seed_key IS NOT NULL")"
expect "  이름" "업무 체크리스트,인수인계서,회의록" "$(psqlq "SELECT string_agg(name, ',' ORDER BY seed_key) FROM community.templates WHERE seed_key IS NOT NULL")"
expect "  셋 다 사이트 범위 · 만든 사람 없음" "3" "$(psqlq "SELECT count(*) FROM community.templates WHERE seed_key IS NOT NULL AND scope='site' AND created_by IS NULL")"
expect "직원 목록 → 200" "200" "$(tlist qa_employee01)"
contains "  회의록이 있다" "$MEET" "$(cat "$WORK/b")"
contains "  인수인계서가 있다" "$HAND" "$(cat "$WORK/b")"
contains "  기본 셋은 만든 사람이 없다(null)" '"created_by":null' "$(cat "$WORK/b")"
expect "  기본 셋은 직원이 지울 수 없다(can_delete 거짓)" "False" "$(jget "[t['can_delete'] for t in d['templates'] if t['id']=='$MEET'][0]" < "$WORK/b")"
expect "Guest 목록 → 200 · 0건(쓸 곳이 없다)" "200 0" "$(tlist qa_guest01) $(jget "len(d['templates'])" < "$WORK/b")"
expect "기본 셋 본문이 지금 규칙을 지난다 — 셋 다 페이지로 만들 수 있다" "201 201 201" \
  "$(tuse qa_employee01 "$MEET") $(tuse qa_employee01 "$HAND") $(tuse qa_employee01 "$CHKL")"
NCH=$(np)
expect "  체크리스트로 만든 페이지 — 할 일 블록 7" "7" "$(psqlq "SELECT count(*) FROM community.page_blocks b JOIN community.pages p ON p.id=b.page_id WHERE p.public_id='$NCH' AND b.block_type='to_do'")"

# ════════════════════════════════════════════════════════════════
hr "C:T-171  저장 — 첨부·하위 페이지는 담지 않는다 · 범위 규칙"
P1=$(make_page qa_employee01 'C7B-원본 페이지')
CHILD=$(make_page qa_employee01 'C7B-하위' "$P1")
psqlq "UPDATE community.pages SET icon='🧪', doc = '$(python3 - "$CHILD" <<'PY'
import json, sys, uuid
u = lambda: str(uuid.uuid4())
t = lambda s: [{"t": s, "m": {}}]
doc = {"schema": 1, "blocks": [
  {"id": u(), "type": "paragraph", "text": t("C7B-본문 첫 줄")},
  {"id": u(), "type": "image", "file_ref": "tbox:00000000-0000-4000-8000-000000000001", "width": "normal"},
  {"id": u(), "type": "file", "file_ref": "tbox:00000000-0000-4000-8000-000000000002"},
  {"id": u(), "type": "child_page", "page_id": sys.argv[1]},
  {"id": u(), "type": "toggle", "text": t("C7B-접기"), "children": [
      {"id": u(), "type": "paragraph", "text": t("C7B-접힌 안")},
      {"id": u(), "type": "image", "file_ref": "tbox:00000000-0000-4000-8000-000000000003", "width": "small"}]},
  {"id": u(), "type": "to_do", "checked": True, "text": t("C7B-끝낸 할 일")},
]}
print(json.dumps(doc, ensure_ascii=False))
PY
)'::jsonb WHERE public_id='$P1'" > /dev/null
m0=$(mark)
expect "내 템플릿으로 저장 → 201" "201" "$(tsave qa_employee01 "$P1" 'C7B-내 틀' personal)"
TP=$(tid)
expect "  뺀 블록 4(그림·파일·하위 페이지·접기 안의 그림)" "4" "$(jget "d['dropped_blocks']" < "$WORK/b")"
expect "  템플릿 본문에 첨부·하위 페이지 없음" "0" "$(tpl_col "$TP" "(doc::text LIKE '%file_ref%')::int + (doc::text LIKE '%child_page%')::int")"
expect "  글·접기 안의 글은 남았다" "t" "$(tpl_col "$TP" "(doc::text LIKE '%C7B-본문 첫 줄%' AND doc::text LIKE '%C7B-접힌 안%')")"
expect "  할 일 체크를 풀었다(빈 양식)" "f" "$(tpl_col "$TP" "(doc::text LIKE '%\"checked\": true%')")"
expect "  제목·아이콘을 담았다" "C7B-원본 페이지 🧪" "$(tpl_col "$TP" "title || ' ' || icon")"
expect "  감사 template.create allow 1(범위 personal)" "1" "$(detail_n "$m0" template.create scope personal)"
expect "이름 빈 글자 → 400" "400" "$(tsave qa_employee01 "$P1" ' ' personal)"
expect "이름 61자 → 400" "400" "$(tsave qa_employee01 "$P1" "$(python3 -c "print('가'*61)")" personal)"
expect "모르는 범위 → 400" "400" "$(tsave qa_employee01 "$P1" 'x' everyone)"
jbody "{'page_id': '$P1', 'name': 'x', 'scope': 'personal', 'owner_user_id': 1}"
expect "허용 키 밖(owner_user_id) → 400" "400" "$(capi POST /api/templates qa_employee01 "$WORK/body.json")"
m0=$(mark)
expect "남의 비공개 페이지를 저장 → 403" "403" "$(tsave qa_employee02 "$P1" 'C7B-훔친 틀' personal)"
expect "  감사 page.read deny not_owner 1" "1" "$(audit_n "$m0" page.read deny not_owner)"
expect "  템플릿이 생기지 않았다" "0" "$(psqlq "SELECT count(*) FROM community.templates WHERE name='C7B-훔친 틀'")"
share qa_employee01 "$P1" qa_employee02 view > /dev/null
expect "보기 공유를 받은 사람은 내 템플릿으로 저장할 수 있다(복사와 같다) → 201" "201" "$(tsave qa_employee02 "$P1" 'C7B-공유받아 저장' personal)"
TP2=$(tid)
expect "  그러나 팀 템플릿으로는 안 된다(개인 공간 페이지) → 400 team_scope" "400 team_scope" "$(tsave qa_employee02 "$P1" 'x' team) $(reason_of)"
m0=$(mark)
expect "직원이 사이트 기본으로 저장 → 403" "403" "$(tsave qa_employee01 "$P1" 'x' site)"
expect "  감사 template.create deny role_not_permitted 1" "1" "$(audit_n "$m0" template.create deny role_not_permitted)"
PA=$(make_page qa_admin01 'C7B-관리자 비공개')
expect "관리자 — 비공개 페이지를 사이트 기본으로 → 400 too_narrow" "400 too_narrow" "$(tsave qa_admin01 "$PA" 'x' site) $(reason_of)"
PAI=$(make_page qa_admin01 'C7B-관리자 사내 공개')
set_vis qa_admin01 "$PAI" internal
expect "관리자 — 사내 공개 페이지를 사이트 기본으로 → 201" "201" "$(tsave qa_admin01 "$PAI" 'C7B-사이트 틀' site)"
TS=$(tid)
tlist qa_employee02 > /dev/null
contains "  다른 팀 직원 목록에도 보인다" "$TS" "$(cat "$WORK/b")"
# 독립 검토 M-1 — 숨긴 게시판 글은 원본을 읽는 사람이 좁다(쓴 사람·관리자) → 사이트 템플릿이 되면 뒷문이다
BP=$(new_post qa_employee01 "$B_FREE" 'C7B-숨길 글')
expect "사내 게시판 글(숨기기 전) — 관리자 범위 목록에 site" "personal,site" "$(scopes_of qa_admin01 "$BP")"
expect "관리자가 그 글을 숨김 → 200" "200" "$(capi POST "/api/pages/$BP/hide" qa_admin01)"
expect "숨긴 글을 사이트 기본으로 → 400 too_narrow(M-1)" "400 too_narrow" "$(tsave qa_admin01 "$BP" 'x' site) $(reason_of)"
expect "  숨긴 글의 범위 목록에 site 없음" "personal" "$(scopes_of qa_admin01 "$BP")"
PAO=$(make_page qa_employee01 'C7B-남의 비공개(관리자 특권으로만 읽힘)')
expect "특권(admin_all)으로만 읽는 원본을 사이트 기본으로 → 400 too_narrow" "400 too_narrow" "$(tsave qa_admin01 "$PAO" 'x' site) $(reason_of)"
if [ -n "$TS1" ]; then
  PT=$(make_page qa_employee01 'C7B-팀 페이지' '' "$TS1")
  expect "팀 공간 페이지를 팀 템플릿으로 → 201" "201" "$(tsave qa_employee01 "$PT" 'C7B-팀 틀' team)"
  TT=$(tid)
  tlist qa_manager01 > /dev/null
  contains "  같은 팀 매니저 목록에 있다" "$TT" "$(cat "$WORK/b")"
  tlist qa_employee02 > /dev/null
  lacks "  다른 팀 직원 목록에는 없다" "$TT" "$(cat "$WORK/b")"
  share qa_employee01 "$PT" qa_employee02 view > /dev/null
  m0=$(mark)
  expect "팀 밖 사람(보기 공유)이 팀 템플릿으로 저장 → 403" "403" "$(tsave qa_employee02 "$PT" 'x' team)"
  expect "  감사 template.create deny not_member 1" "1" "$(audit_n "$m0" template.create deny not_member)"
else
  skip "팀 템플릿" "qa_employee01 팀 공간 없음"
fi
read DBP DBI <<< "$(mkdb qa_employee01 'C7B-표')"
expect "DB 페이지 자체는 템플릿이 되지 않는다 → 400 db_page" "400 db_page" "$(tsave qa_employee01 "$DBP" 'x' personal) $(reason_of)"
expect "DB 행이 아닌 페이지를 행 템플릿으로 → 400 not_row" "400 not_row" "$(tsave qa_employee01 "$P1" 'x' database) $(reason_of)"
tlist qa_employee01 > /dev/null
lacks "내 목록에 남의 개인 템플릿(공유받아 저장한 것)은 없다" "$TP2" "$(cat "$WORK/b")"
# 한도 — qa_employee02 의 개인 템플릿을 50 까지 채운다(임시 DB)
E2=$(uid qa_employee02)
psqlq "INSERT INTO community.templates (scope, owner_user_id, name, title, doc, created_by)
       SELECT 'personal', $E2, 'C7B-채움 ' || g, 't', '{\"schema\":1,\"blocks\":[]}'::jsonb, $E2
         FROM generate_series(1, 50 - (SELECT count(*) FROM community.templates WHERE scope='personal' AND owner_user_id=$E2 AND deleted_at IS NULL)) g" > /dev/null
expect "한도(개인 50) — 51번째 → 400 too_many_templates" "400 too_many_templates" "$(tsave qa_employee02 "$P1" 'C7B-넘침' personal) $(reason_of)"

# ════════════════════════════════════════════════════════════════
hr "C:T-172  쓰기 — 만든 사람 공간 · 비공개 · 새 블록 id · 남의 템플릿 403"
m0=$(mark)
expect "회의록으로 새 페이지 → 201" "201" "$(tuse qa_employee01 "$MEET")"
N1=$(np)
expect "  내 개인 공간" "personal:qa_employee01" "$(psqlq "SELECT s.kind || ':' || u.username FROM community.pages p JOIN community.spaces s ON s.id=p.space_id JOIN users u ON u.id=s.owner_user_id WHERE p.public_id='$N1'")"
expect "  비공개로 시작" "private" "$(pg_page "$N1" "p.visibility")"
expect "  제목·아이콘은 템플릿 것" "회의록 📝" "$(pg_page "$N1" "p.title || ' ' || p.icon")"
expect "  블록 수는 템플릿과 같다" "$(psqlq "SELECT jsonb_array_length(doc->'blocks') FROM community.templates WHERE seed_key='meeting'")" "$(pg_page "$N1" "jsonb_array_length(p.doc->'blocks')")"
expect "  블록 id 는 모두 새것(템플릿과 겹침 0)" "0" "$(psqlq "SELECT count(*) FROM jsonb_array_elements((SELECT doc->'blocks' FROM community.pages WHERE public_id='$N1')) a JOIN jsonb_array_elements((SELECT doc->'blocks' FROM community.templates WHERE seed_key='meeting')) b ON a->>'id' = b->>'id'")"
tuse qa_employee01 "$MEET" > /dev/null; N1B=$(np)
expect "  같은 템플릿으로 만든 두 페이지도 블록 id 겹침 0" "0" "$(psqlq "SELECT count(*) FROM jsonb_array_elements((SELECT doc->'blocks' FROM community.pages WHERE public_id='$N1')) a JOIN jsonb_array_elements((SELECT doc->'blocks' FROM community.pages WHERE public_id='$N1B')) b ON a->>'id' = b->>'id'")"
expect "  블록 색인도 만들었다(page_blocks > 0)" "yes" "$([ "$(psqlq "SELECT count(*) FROM community.page_blocks b JOIN community.pages p ON p.id=b.page_id WHERE p.public_id='$N1'")" -gt 0 ] && echo yes)"
expect "  감사 page.create 에 템플릿 이름" "yes" "$([ "$(detail_n "$m0" page.create template_name 회의록)" -ge 1 ] && echo yes)"
expect "제목을 주면 그 제목 → 201 · 제목" "201 C7B-주간 회의" "$(tuse qa_employee01 "$MEET" "'title': 'C7B-주간 회의'") $(pg_page "$(np)" "p.title")"
expect "첨부를 뺀 내 템플릿으로 → 201" "201" "$(tuse qa_employee01 "$TP")"
N2=$(np)
expect "  새 페이지에 첨부·하위 페이지 없음" "0" "$(pg_page "$N2" "(p.doc::text LIKE '%file_ref%')::int + (p.doc::text LIKE '%child_page%')::int")"
expect "  하위 페이지 0" "0" "$(psqlq "SELECT count(*) FROM community.pages c JOIN community.pages p ON p.id=c.parent_page_id WHERE p.public_id='$N2'")"
if [ -n "$TS1" ]; then
  expect "팀 공간을 주면 팀 공간에 → 201 · team" "201 team" "$(tuse qa_employee01 "$TT" "'space_id': $TS1") $(psqlq "SELECT s.kind FROM community.pages p JOIN community.spaces s ON s.id=p.space_id WHERE p.public_id='$(np)'")"
  expect "같은 팀 매니저가 팀 템플릿으로 → 201(자기 개인 공간)" "201 personal:qa_manager01" "$(tuse qa_manager01 "$TT") $(psqlq "SELECT s.kind || ':' || u.username FROM community.pages p JOIN community.spaces s ON s.id=p.space_id JOIN users u ON u.id=s.owner_user_id WHERE p.public_id='$(np)'")"
  m0=$(mark)
  expect "다른 팀 직원이 팀 템플릿으로 → 403" "403" "$(tuse qa_employee02 "$TT")"
  expect "  감사 template.read deny not_member 1" "1" "$(audit_n "$m0" template.read deny not_member)"
fi
n_before=$(psqlq "SELECT count(*) FROM community.pages WHERE author_id=$E2")
m0=$(mark)
expect "남의 개인 템플릿으로 → 403" "403" "$(tuse qa_employee02 "$TP")"
expect "  감사 template.read deny not_owner 1" "1" "$(audit_n "$m0" template.read deny not_owner)"
expect "  페이지가 생기지 않았다" "$n_before" "$(psqlq "SELECT count(*) FROM community.pages WHERE author_id=$E2")"
expect "  남의 개인 템플릿 읽기도 403" "403" "$(capi GET "/api/templates/$TP" qa_employee02)"
lacks "  403 응답에 템플릿 이름이 없다" "C7B-내 틀" "$(cat "$WORK/b")"
expect "관리자도 남의 개인 템플릿은 못 쓴다(J-3) → 403" "403" "$(tuse qa_admin01 "$TP")"
expect "관리자도 남의 개인 템플릿은 못 읽는다 → 403" "403" "$(capi GET "/api/templates/$TP" qa_admin01)"
expect "내 템플릿 읽기 → 200 · 본문 있음" "200 yes" "$(capi GET "/api/templates/$TP" qa_employee01) $(jget "'yes' if d['template']['doc']['blocks'] else 'no'" < "$WORK/b")"
jbody "{'template_id': '$MEET', 'doc': {'schema': 1, 'blocks': []}}"
expect "template_id + doc → 400" "400" "$(capi POST /api/pages qa_employee01 "$WORK/body.json")"
jbody "{'template_id': '$MEET', 'database': True}"
expect "template_id + database → 400" "400" "$(capi POST /api/pages qa_employee01 "$WORK/body.json")"
jbody "{'template_id': 'abc'}"
expect "template_id 형식 오류 → 400" "400" "$(capi POST /api/pages qa_employee01 "$WORK/body.json")"
expect "없는 템플릿 → 404" "404" "$(tuse qa_employee01 "$(python3 -c 'import uuid;print(uuid.uuid4())')")"
expect "Guest 가 기본 셋으로 → 403(쓰기 정책)" "403" "$(tuse qa_guest01 "$MEET")"

# ════════════════════════════════════════════════════════════════
hr "C:T-173  DB 행 템플릿"
KT=$(addprop qa_employee01 "$DBI" '메모' text)
KS=$(addprop qa_employee01 "$DBI" '분류' select "['가','나']")
KP=$(addprop qa_employee01 "$DBI" '담당' person)
newrow qa_employee01 "$DBP" 'C7B-행' "{'$KT': '글값', '$KS': '가', '$KP': 'qa_employee01'}" > /dev/null; RW=$(rid)
expect "행을 행 템플릿으로 → 201" "201" "$(tsave qa_employee01 "$RW" 'C7B-행 틀' database)"
TR=$(tid)
expect "  담은 값 — 글·선택(사람은 뺀다)" "글값 가 false" "$(tpl_col "$TR" "(properties->>'$KT') || ' ' || (properties->>'$KS') || ' ' || (properties ? '$KP')")"
expect "행을 내 템플릿으로 → 201 · 속성 값은 담지 않는다" "201 {}" "$(tsave qa_employee01 "$RW" 'C7B-행 개인 틀' personal) $(tpl_col "$(tid)" "properties::text")"
expect "DB 목록 → 200 · 행 템플릿 있음" "200 yes" "$(tlist qa_employee01 "$DBI") $(jget "'yes' if any(t['id']=='$TR' for t in d['templates']) else 'no'" < "$WORK/b")"
tlist qa_employee01 > /dev/null
lacks "  일반 목록에는 행 템플릿이 없다" "$TR" "$(cat "$WORK/b")"
expect "DB 를 못 보는 사람의 DB 목록 → 404" "404" "$(tlist qa_employee02 "$DBI")"
share qa_employee01 "$DBP" qa_employee02 view > /dev/null
m0=$(mark)
expect "보기만 하는 사람의 DB 목록 → 403" "403" "$(tlist qa_employee02 "$DBI")"
expect "  감사 template.list deny not_owner 1(목록 전용 문장 — 검토 L-3)" "1" "$(audit_n "$m0" template.list deny not_owner)"
expect "보기만 하는 사람이 행 템플릿으로 → 403" "403" "$(tuse qa_employee02 "$TR")"
expect "행 템플릿으로(부모 없이) → 201 · 부모는 그 DB 페이지" "201 $DBP" "$(tuse qa_employee01 "$TR") $(psqlq "SELECT par.public_id FROM community.pages p JOIN community.pages par ON par.id=p.parent_page_id WHERE p.public_id='$(np)'")"
R2=$(np)
expect "  행의 값 = 템플릿 값" "글값 가" "$(pg_page "$R2" "(p.properties->>'$KT') || ' ' || (p.properties->>'$KS')")"
expect "  사람 값은 따라오지 않았다" "f" "$(pg_page "$R2" "p.properties ? '$KP'")"
expect "요청 값이 템플릿 값 위에 → 201 · 나" "201 나" "$(tuse qa_employee01 "$TR" "'properties': {'$KS': '나'}") $(pg_page "$(np)" "p.properties->>'$KS'")"
OTHER=$(make_page qa_employee01 'C7B-다른 부모')
m0=$(mark)
expect "행 템플릿 + 다른 공간(space_id) → 400 template_db_mismatch(검토 L-2)" "400 template_db_mismatch" "$(tuse qa_employee01 "$TR" "'space_id': ${TS1:-999999}") $(reason_of)"
expect "  「남의 페이지」 감사가 남지 않았다" "0" "$(audit_n "$m0" page.read deny not_owner)"
expect "다른 부모를 주면 → 400 template_db_mismatch" "400 template_db_mismatch" "$(tuse qa_employee01 "$TR" "'parent_id': '$OTHER'") $(reason_of)"
schema qa_employee01 "$DBI" "{'remove': {'key': '$KS'}}" > /dev/null
expect "스키마에서 분류를 지운 뒤 → 201 · 분류 값 없음" "201 f" "$(tuse qa_employee01 "$TR") $(pg_page "$(np)" "p.properties ? '$KS'")"
set_vis qa_employee01 "$DBP" internal
newrow qa_employee01 "$DBP" 'C7B-좁힌 행' > /dev/null; RN=$(rid)
set_vis qa_employee01 "$RN" private
expect "DB 보다 좁게 닫은 행 → 400 too_narrow" "400 too_narrow" "$(tsave qa_employee01 "$RN" 'x' database) $(reason_of)"
newrow qa_employee01 "$DBP" 'C7B-같은 범위 행' > /dev/null; RS=$(rid)
expect "DB 와 같은 범위의 행 → 201" "201" "$(tsave qa_employee01 "$RS" 'C7B-행 틀 2' database)"

# ════════════════════════════════════════════════════════════════
hr "C:T-174  지우기"
m0=$(mark)
expect "남의 개인 템플릿 지우기 → 403" "403" "$(capi DELETE "/api/templates/$TP" qa_employee02)"
expect "  감사 template.read deny 1(볼 수도 없다)" "1" "$(audit_n "$m0" template.read deny not_owner)"
if [ -n "$TS1" ]; then
  m0=$(mark)
  expect "팀 템플릿 — 팀 밖 사람(보기 공유)이 지우기 → 403" "403" "$(capi DELETE "/api/templates/$TT" qa_employee02)"
  expect "  감사 template.read deny not_member 1(볼 수도 없다)" "1" "$(audit_n "$m0" template.read deny not_member)"
fi
expect "보기 공유만 받은 사람이 행 템플릿 지우기 → 403" "403" "$(capi DELETE "/api/templates/$TR" qa_employee02)"
m0=$(mark)
expect "기본 셋을 직원이 지우기 → 403" "403" "$(capi DELETE "/api/templates/$CHKL" qa_employee01)"
expect "  감사 template.delete deny role_not_permitted 1" "1" "$(audit_n "$m0" template.delete deny role_not_permitted)"
m0=$(mark)
expect "내 템플릿 지우기 → 200" "200" "$(capi DELETE "/api/templates/$TP" qa_employee01)"
expect "  감사 template.delete allow 1" "1" "$(audit_n "$m0" template.delete allow)"
expect "  지운 뒤 읽기 404 · 쓰기 404" "404 404" "$(capi GET "/api/templates/$TP" qa_employee01) $(tuse qa_employee01 "$TP")"
expect "  그 템플릿으로 만든 페이지는 그대로" "200" "$(capi GET "/api/pages/$N2" qa_employee01)"
expect "  두 번 지우기 → 404" "404" "$(capi DELETE "/api/templates/$TP" qa_employee01)"
expect "  줄은 남고 지운 사람이 적혔다" "t" "$(tpl_col "$TP" "(deleted_at IS NOT NULL AND deleted_by = $(uid qa_employee01))")"
share qa_employee01 "$DBP" qa_manager01 edit > /dev/null
m0=$(mark)
expect "행 템플릿 — 편집 공유자(행은 더하지만 만들지도 관리하지도 않음)가 지우기 → 403" "403" "$(capi DELETE "/api/templates/$TR" qa_manager01)"
expect "  감사 template.delete deny not_owner 1" "1" "$(audit_n "$m0" template.delete deny not_owner)"
expect "행 템플릿 — 만든 사람이 지우기 → 200" "200" "$(capi DELETE "/api/templates/$TR" qa_employee01)"
if [ -n "$TS1" ]; then
  expect "팀 템플릿 — 만들지 않은 팀원(매니저)도 지울 수 있다 → 200(검토 L-4: 만든 사람이 팀을 옮겨도 정리된다)" "200" "$(capi DELETE "/api/templates/$TT" qa_manager01)"
fi
expect "기본 셋을 관리자가 지우기 → 200" "200" "$(capi DELETE "/api/templates/$CHKL" qa_admin01)"
sudo -n -u postgres psql -d "$DB" -q -v ON_ERROR_STOP=1 -f - < "$ROOT/db/migrations/2026-09-27_community_templates.sql" > /dev/null 2>&1
expect "  마이그레이션을 다시 돌려도 지운 기본 템플릿은 살아나지 않는다(살아 있는 셋 2)" "2" "$(psqlq "SELECT count(*) FROM community.templates WHERE seed_key IS NOT NULL AND deleted_at IS NULL")"

# ════════════════════════════════════════════════════════════════
hr "C:T-175  페이지 응답의 template_scopes = 저장 창구 판정"
expect "내 비공개 페이지 → personal" "personal" "$(scopes_of qa_employee01 "$P1")"
expect "보기 공유받은 사람 → personal" "personal" "$(scopes_of qa_employee02 "$P1")"
share qa_employee01 "$P1" qa_guest01 view > /dev/null
expect "보기 공유받은 Guest → (없음 — 쓸 수 없는 역할, 검토 L-5)" "" "$(scopes_of qa_guest01 "$P1")"
[ -n "$TS1" ] && expect "팀 공간 페이지(팀원) → personal,team" "personal,team" "$(scopes_of qa_manager01 "$PT")"
expect "관리자의 사내 공개 페이지 → personal,site" "personal,site" "$(scopes_of qa_admin01 "$PAI")"
expect "관리자의 비공개 페이지 → personal" "personal" "$(scopes_of qa_admin01 "$PA")"
expect "DB 페이지 → (없음)" "" "$(scopes_of qa_employee01 "$DBP")"
expect "DB 행(같은 범위) → personal,database" "personal,database" "$(scopes_of qa_employee01 "$RS")"
expect "DB 보다 좁힌 행 → personal" "personal" "$(scopes_of qa_employee01 "$RN")"

# ════════════════════════════════════════════════════════════════
hr "C:T-176  권한 · 모양"
expect "tbox_app 이 templates DELETE → 42501" "42501" "$(appq "DELETE FROM community.templates WHERE false" | sed -n 's/.*ERROR: *\([0-9A-Z]\{5\}\).*/\1/p')"
bad=$(sudo -n -u postgres psql -d "$DB" -tAc "INSERT INTO community.templates (scope, owner_user_id, team_id, name, title, doc, created_by) VALUES ('personal', $E2, ${TEAM1:-1}, 'x', 'x', '{\"blocks\":[]}', $E2)" 2>&1)
contains "범위 모양 CHECK — 개인인데 팀도 채운 줄은 들어가지 못한다" "templates_scope_shape" "$bad"
bad=$(sudo -n -u postgres psql -d "$DB" -tAc "INSERT INTO community.templates (scope, name, title, doc) VALUES ('site', 'x', 'x', '{\"blocks\":[]}')" 2>&1)
contains "만든 사람 CHECK — 시드가 아닌 사이트 템플릿은 만든 사람이 있어야 한다" "templates_creator" "$bad"
tlist qa_employee01 > /dev/null
for k in owner_user_id team_id database_id seed_key; do
  lacks "목록 응답에 내부 칸($k)이 없다" "\"$k\"" "$(cat "$WORK/b")"
done
expect "서버 로그에 잡히지 않은 예외 0" "0" "$(grep -c 'uncaught\|Fatal error' "$INST/server.log" 2>/dev/null | tr -d ' ')"

# ════════════════════════════════════════════════════════════════
hr "C:T-177  브라우저 — browser_7b.py (홈 창 · 저장 창 · DB 새 행 고르개)"
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
  PSRC=$(make_page qa_employee01 'C7B-브라우저 원본')
  read BDBP BDBI <<< "$(mkdb qa_employee01 'C7B-브라우저 표')"
  BK=$(addprop qa_employee01 "$BDBI" '메모' text)
  newrow qa_employee01 "$BDBP" 'C7B-브라우저 행' "{'$BK': '틀 값'}" > /dev/null
  tsave qa_employee01 "$(rid)" 'C7B-브라우저 행 틀' database > /dev/null
  m0=$(mark)
  SMOKE_E1="$(cookie_of qa_employee01)" SMOKE_PSRC="$PSRC" SMOKE_DBP="$BDBP" SMOKE_ROWTPL='C7B-브라우저 행 틀' SMOKE_SHOTS="${SHOTS:-}" \
    python3 "$ROOT/tests/community/browser_7b.py" "$SITE" "$UA" > "$WORK/smoke.txt" 2>&1
  sed 's/^/    /' "$WORK/smoke.txt"
  expect "브라우저 실패 0" "0" "$(sed -n 's/^SMOKE_FAIL=//p' "$WORK/smoke.txt" | tail -1)"
  expect "  브라우저가 남긴 감사 — 템플릿 저장 1 · 지우기 2(내 템플릿 · 행 템플릿)" "1 2" "$(audit_n "$m0" template.create allow) $(audit_n "$m0" template.delete allow)"
  expect "  브라우저가 만든 행 — 템플릿 값이 들어갔다" "1" "$(psqlq "SELECT count(*) FROM community.pages p JOIN community.pages par ON par.id=p.parent_page_id WHERE par.public_id='$BDBP' AND p.properties->>'$BK' = '틀 값' AND p.title = 'C7B-브라우저 행'" | tr -d ' ' | awk '{print ($1>=2)?1:0}')"
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
bash -n "$ROOT/tests/community/stage7b.sh" && expect "stage7b.sh 문법" "0" "0"
python3 -c "import ast,io;ast.parse(io.open('$ROOT/tests/community/browser_7b.py',encoding='utf-8').read())" \
  && expect "browser_7b.py 문법" "0" "0"
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

