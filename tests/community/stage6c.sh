#!/usr/bin/env bash
# tests/community/stage6c.sh — 6-C 수락 기준 C:T-150 ~ C:T-155 실행 (단 나누기 · 표 칸 병합)
#                              (../../../community/stage6c-columns-merge-directive.md 「시험」)
#
#   C:T-150 단 모양    2·3단 200 · 1·4단 400 · column 단독 400 · 단 목록에 다른 블록 400 · 단 안의 단 400
#   C:T-151 병합 (T-30) 가로·세로 병합 200 · 덮인 칸에 글자 · 표 밖 · 겹침 · 21 → 400 · mention 마크는 아직(7단계)
#   C:T-152 파생값     단 안의 글·링크도 찾기·역참조에 든다
#   C:T-153 렌더러     단·병합 표본을 DocRender.php · doc-render.js 가 같은 글자·클래스·병합 속성으로(브라우저 안)
#   C:T-154 XSS        단 안·병합 칸의 태그는 글자로
#   C:T-155 브라우저   browser_6c.py — 블록 추가 「단 나누기」 창 · 표 칸 합치기 · 읽기 화면 · 390px 세로로 쌓임
#   회귀 (REGRESS=1)
#
# ★ 운영 DB 를 건드리지 않는다 — stage6b.sh 와 같은 틀(임시 DB `tbox_6c_test`, 포트 8093).
# 사용: sudo -v && PLAN_OVERRIDE=<새 doc-schema 사본 폴더> bash tests/community/stage6c.sh

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
. "$ROOT/tests/lib/secrets.sh" || { echo "tests/lib/secrets.sh 를 읽지 못했다 — 시험을 시작하지 않는다 (R-62)"; exit 3; }
PORT=8093
SITE="http://127.0.0.1:$PORT"
BASE="$SITE/tbox"
CBASE="$SITE/community"
UA="tbox-review/$(date +%F)"
SRC_DB="tbox_re"          # 운영 — **읽기만** 한다
DB="tbox_6c_test"         # 임시 — 여기에만 쓴다
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
hr "6-C — 임시 인스턴스 준비 (운영 DB 에는 쓰지 않는다)"

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

# 문서 저장 — $1 계정 $2 페이지 $3 블록 목록 파이썬 식 → 상태 코드 (판 번호는 지금 값을 psql 로)
#   식 안에서 쓰는 짧은 이름: P(글) · TOG(요약, [글…]) · TAB(행 목록, hr=True) · LNK(페이지) · IMG(파일, 폭) · FIL(파일)
put_doc() {
  local v; v=$(pg_page "$2" "p.doc_version")
  python3 - "$3" "${v:-1}" > "$WORK/body.json" <<'PY'
import json, sys, uuid
def u(): return str(uuid.uuid4())
def seg(t): return [{"t": t, "m": {}}] if t else []
def P(t): return {"id": u(), "type": "paragraph", "text": seg(t)}
def TOG(s, kids): return {"id": u(), "type": "toggle", "text": seg(s), "children": [P(k) for k in kids]}
def TAB(rows, hr=True, **kw):
    b = {"id": u(), "type": "table", "header_row": hr, "header_col": False,
         "rows": [{"cells": [{"text": seg(c)} for c in r]} for r in rows]}
    b.update(kw); return b
def LNK(pid): return {"id": u(), "type": "link_to_page", "page_id": pid}
def IMG(fid, width="normal", cap=""):
    b = {"id": u(), "type": "image", "file_ref": fid if fid.startswith(("tbox:", "http")) or fid == "" else "tbox:" + fid, "width": width}
    if cap: b["caption"] = seg(cap)
    return b
def FIL(fid, cap=""):
    b = {"id": u(), "type": "file", "file_ref": fid if fid.startswith(("tbox:", "http")) or fid == "" else "tbox:" + fid}
    if cap: b["caption"] = seg(cap)
    return b
blocks = eval(sys.argv[1])
print(json.dumps({"doc": {"schema": 1, "blocks": blocks}, "doc_version": int(sys.argv[2])}, ensure_ascii=False))
PY
  capi PATCH "/api/pages/$2" "$1" "$WORK/body.json"
}
errs() { jget "' '.join(e['reason'] for e in d.get('errors', []))" < "$WORK/b"; }
uu() { python3 -c 'import uuid;print(uuid.uuid4())'; }
# 단·병합 블록을 만드는 파이썬 식 조각 — put_doc 의 eval 안에서 쓴다
COLS_PY="(lambda n, texts: {'id': u(), 'type': 'column_list', 'children': [{'id': u(), 'type': 'column', 'children': [P(t) for t in texts[i]]} for i in range(n)]})"
TABM_PY="(lambda rows: {'id': u(), 'type': 'table', 'header_row': False, 'header_col': False, 'rows': [{'cells': [dict({'text': seg(c[0])}, **({'rowspan': c[1]} if c[1] != 1 else {}), **({'colspan': c[2]} if c[2] != 1 else {})) for c in r]} for r in rows]})"

# ════════════════════════════════════════════════════════════════
hr "C:T-150  단 나누기 — 모양"
P0=$(make_page claude_employee01 'C6C-단 페이지')
expect "2단 → 200" "200" "$(put_doc claude_employee01 "$P0" "[$COLS_PY(2, [['왼쪽 한'], ['오른쪽 둘']])]")"
expect "3단 → 200" "200" "$(put_doc claude_employee01 "$P0" "[$COLS_PY(3, [['a'], ['b'], ['c']])]")"
expect "1단 → 400" "400" "$(put_doc claude_employee01 "$P0" "[$COLS_PY(1, [['a']])]")"
expect "4단 → 400" "400" "$(put_doc claude_employee01 "$P0" "[$COLS_PY(4, [['a'], ['b'], ['c'], ['d']])]")"
expect "column 단독 → 400" "400" "$(put_doc claude_employee01 "$P0" "[{'id': u(), 'type': 'column', 'children': [P('a')]}]")"
contains "  사유 column_outside_list" "column_outside_list" "$(errs)"
expect "단 목록에 문단 → 400" "400" "$(put_doc claude_employee01 "$P0" "[{'id': u(), 'type': 'column_list', 'children': [{'id': u(), 'type': 'column', 'children': [P('a')]}, P('b')]}]")"
contains "  사유 column_list_child" "column_list_child" "$(errs)"
expect "단 안의 단 → 400" "400" "$(put_doc claude_employee01 "$P0" "[{'id': u(), 'type': 'column_list', 'children': [{'id': u(), 'type': 'column', 'children': [$COLS_PY(2, [['x'], ['y']])]}, {'id': u(), 'type': 'column', 'children': [P('b')]}]}]")"
contains "  사유 nested_columns" "nested_columns" "$(errs)"
expect "단 안에 토글·할 일 → 200(보통 블록)" "200" "$(put_doc claude_employee01 "$P0" "[{'id': u(), 'type': 'column_list', 'children': [{'id': u(), 'type': 'column', 'children': [TOG('접기', ['안'])]}, {'id': u(), 'type': 'column', 'children': [{'id': u(), 'type': 'to_do', 'checked': False, 'text': seg('할 일')}]}]}]")"
expect "단에 ratio 음수 → 400" "400" "$(put_doc claude_employee01 "$P0" "[{'id': u(), 'type': 'column_list', 'children': [{'id': u(), 'type': 'column', 'ratio': -1, 'children': [P('a')]}, {'id': u(), 'type': 'column', 'children': [P('b')]}]}]")"

# ════════════════════════════════════════════════════════════════
hr "C:T-151  표 칸 병합 (T-30)"
expect "가로 병합(첫 행 2칸) → 200" "200" "$(put_doc claude_employee01 "$P0" "[$TABM_PY([[('머리',1,2),('',1,1)],[('a',1,1),('b',1,1)]])]")"
expect "세로 병합(첫 열 2행) → 200" "200" "$(put_doc claude_employee01 "$P0" "[$TABM_PY([[('세로',2,1),('x',1,1)],[('',1,1),('y',1,1)]])]")"
expect "2×2 전부 병합 → 200" "200" "$(put_doc claude_employee01 "$P0" "[$TABM_PY([[('전부',2,2),('',1,1)],[('',1,1),('',1,1)]])]")"
expect "덮인 칸에 글자 → 400" "400" "$(put_doc claude_employee01 "$P0" "[$TABM_PY([[('a',1,2),('숨김',1,1)],[('b',1,1),('c',1,1)]])]")"
contains "  사유 merge_overlap" "merge_overlap" "$(errs)"
expect "표 밖으로 → 400" "400" "$(put_doc claude_employee01 "$P0" "[$TABM_PY([[('a',1,3),('',1,1)],[('b',1,1),('c',1,1)]])]")"
contains "  사유 merge_out_of_table" "merge_out_of_table" "$(errs)"
expect "병합끼리 겹침 → 400" "400" "$(put_doc claude_employee01 "$P0" "[$TABM_PY([[('a',2,1),('b',2,1)],[('',1,2),('',1,1)]])]")"
expect "colspan 21 → 400" "400" "$(put_doc claude_employee01 "$P0" "[$TABM_PY([[('a',1,21)] + [('',1,1)]*20])]")"
expect "colspan 문자열 → 400" "400" "$(put_doc claude_employee01 "$P0" "[{'id': u(), 'type': 'table', 'header_row': False, 'header_col': False, 'rows': [{'cells': [{'text': [], 'colspan': '2'}, {'text': []}]}]}]")"
expect "mention 마크는 아직(7-D 까지 8단계) → 400" "400" "$(put_doc claude_employee01 "$P0" "[{'id': u(), 'type': 'paragraph', 'text': [{'t': 'x', 'm': {'mention': {'kind': 'user', 'id': 1}}}]}]")"
contains "  사유 mark_not_in_stage" "mark_not_in_stage" "$(errs)"

# ════════════════════════════════════════════════════════════════
hr "C:T-152  파생값 — 단 안의 글·링크"
PT=$(make_page claude_employee01 'C6C-참조받는'); set_vis claude_employee01 "$PT" internal
set_vis claude_employee01 "$P0" internal
put_doc claude_employee01 "$P0" "[$COLS_PY(2, [['단안의낱말쿼카'], ['오른쪽']]), {'id': u(), 'type': 'column_list', 'children': [{'id': u(), 'type': 'column', 'children': [LNK('$PT')]}, {'id': u(), 'type': 'column', 'children': [P('b')]}]}]" > /dev/null
capi GET "/api/search?q=%EB%8B%A8%EC%95%88%EC%9D%98%EB%82%B1%EB%A7%90%EC%BF%BC%EC%B9%B4" claude_employee02 > /dev/null
contains "단 안의 낱말로 찾기(다른 사원)" "C6C-단 페이지" "$(jget "json.dumps(d['results'],ensure_ascii=False)" < "$WORK/b")"
capi GET "/api/pages/$PT" claude_employee02 > /dev/null
contains "단 안의 페이지 링크도 역참조에" "C6C-단 페이지" "$(jget "json.dumps(d['backlinks'],ensure_ascii=False)" < "$WORK/b")"

# ════════════════════════════════════════════════════════════════
hr "C:T-153·154  렌더러 동등성 · XSS (PHP 쪽. JS 쪽은 브라우저에서)"
python3 - "$WORK" <<'PY'
import json, os, sys, uuid
work = sys.argv[1]
def u(): return str(uuid.uuid4())
def seg(t): return [{"t": t, "m": {}}] if t else []
def P(t): return {"id": u(), "type": "paragraph", "text": seg(t)}
def cols(n, texts): return {"id": u(), "type": "column_list", "children": [{"id": u(), "type": "column", "children": [P(t) for t in texts[i]]} for i in range(n)]}
def tab(rows): return {"id": u(), "type": "table", "header_row": True, "header_col": False, "rows": [{"cells": [dict({"text": seg(c[0])}, **({"rowspan": c[1]} if c[1] != 1 else {}), **({"colspan": c[2]} if c[2] != 1 else {})) for c in r]} for r in rows]}
S = [
  {"schema": 1, "blocks": [cols(2, [["왼쪽"], ["오른쪽", "둘째 줄"]])]},
  {"schema": 1, "blocks": [cols(3, [["가"], ["나"], ["다"]])]},
  {"schema": 1, "blocks": [tab([[("머리", 1, 2), ("", 1, 1)], [("a", 1, 1), ("b", 1, 1)]])]},
  {"schema": 1, "blocks": [tab([[("세로", 2, 1), ("x", 1, 1)], [("", 1, 1), ("y", 1, 1)], [("z", 1, 1), ("w", 1, 1)]])]},
  {"schema": 1, "blocks": [cols(2, [["<img src=x onerror=alert(1)>"], ["<script>alert(2)</script>"]])]},
  {"schema": 1, "blocks": [tab([[("<b>굵게</b>", 1, 2), ("", 1, 1)]])]},
]
json.dump(S, open(os.path.join(work, "samples6c.json"), "w", encoding="utf-8"), ensure_ascii=False)
PY
ROOT="$INST/app" WORK="$WORK" php -r '
define("TBOX_BASE", "/tbox"); define("COMMUNITY_BASE", "/community");
require getenv("ROOT") . "/src/Http.php";
require getenv("ROOT") . "/src/community/Doc.php";
require getenv("ROOT") . "/src/community/DocRender.php";
$samples = json_decode(file_get_contents(getenv("WORK") . "/samples6c.json"), true);
$out = []; $bad = 0;
foreach ($samples as $doc) {
    $v = Doc::validate($doc, 6);
    if (!$v["ok"]) { $bad++; fwrite(STDERR, json_encode($v["errors"]) . "\n"); }
    $html = DocRender::html($doc, []);
    $texts = [];
    foreach (preg_split("/<[^>]*>/", $html) as $chunk) { $t = trim(html_entity_decode($chunk, ENT_QUOTES, "UTF-8")); if ($t !== "") { $texts[] = $t; } }
    $classes = [];
    preg_match_all("/class=\"([^\"]*)\"/", $html, $m);
    foreach ($m[1] as $cl) { foreach (preg_split("/\s+/", $cl) as $k) { if ($k !== "") { $classes[] = $k; } } }
    $classes = array_values(array_unique($classes)); sort($classes);
    preg_match_all("/(rowspan|colspan)=\"(\d+)\"/", $html, $sp);
    $spans = []; foreach ($sp[1] as $i => $n) { $spans[] = $n . "=" . $sp[2][$i]; }
    $out[] = ["texts" => $texts, "classes" => $classes, "spans" => $spans, "cells" => substr_count($html, "doc-table__cell"), "html" => $html];
}
file_put_contents(getenv("WORK") . "/php6c.json", json_encode($out, JSON_UNESCAPED_UNICODE));
printf("표본 %d개 · 스키마를 통과하지 못한 것 %d개\n", count($samples), $bad);
' > "$WORK/parity6c.txt" 2>&1
cat "$WORK/parity6c.txt" | head -3
expect "단·병합 표본 6개가 모두 스키마를 지난다" "표본 6개 · 스키마를 통과하지 못한 것 0개" "$(tail -1 "$WORK/parity6c.txt")"
expect "  PHP — 가로 병합 표는 칸 셋(덮인 칸 없음) · colspan=2" "3 colspan=2" "$(jget "str(d[2]['cells'])+' '+' '.join(d[2]['spans'])" < "$WORK/php6c.json")"
lacks "  PHP — 단 안의 태그는 글자로(img 요소 0)" "<img src=x" "$(jget "d[4]['html']" < "$WORK/php6c.json")"
lacks "  PHP — 병합 칸 태그도 글자로" "<b>" "$(jget "d[5]['html']" < "$WORK/php6c.json")"
expect "doc-schema.json 사본이 원본(지시서 폴더 사본)과 같다" "0" \
  "$(diff "$ROOT/public/assets/community/doc-schema.json" "$INST/community/doc-schema.json" >/dev/null 2>&1; echo $?)"

# ════════════════════════════════════════════════════════════════
hr "C:T-155  브라우저 — browser_6c.py (렌더러 JS 쪽 동등성 포함)"
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
if ! python3 -c 'import playwright' 2>/dev/null; then
  skip "브라우저" "playwright 가 없다"
else
  PE=$(make_page claude_employee01 'C6C-브라우저 페이지')
  PR=$(make_page claude_employee01 'C6C-비율 단')
  put_doc claude_employee01 "$PR" "[{'id': u(), 'type': 'column_list', 'children': [{'id': u(), 'type': 'column', 'ratio': 2, 'children': [P('a')]}, {'id': u(), 'type': 'column', 'children': [P('b')]}]}]" > /dev/null
  SMOKE_PRATIO="$PR" SMOKE_E1="$(cookie_of claude_employee01)" SMOKE_PE="$PE" SMOKE_SAMPLES="$WORK/samples6c.json" SMOKE_PHP="$WORK/php6c.json" SMOKE_SHOTS="${SHOTS:-}" \
    python3 "$ROOT/tests/community/browser_6c.py" "$SITE" "$UA" > "$WORK/smoke.txt" 2>&1
  sed 's/^/    /' "$WORK/smoke.txt"
  expect "브라우저 실패 0" "0" "$(sed -n 's/^SMOKE_FAIL=//p' "$WORK/smoke.txt" | tail -1)"
  expect "  브라우저가 저장한 문서 — 단 나누기 · 병합 표" "column_list table" \
    "$(psqlq "SELECT string_agg(b->>'type', ' ' ORDER BY b->>'type') FROM community.pages p, jsonb_array_elements(p.doc->'blocks') b WHERE p.public_id='$PE' AND b->>'type' IN ('column_list','table')")"
  expect "  저장된 표에 colspan 2" "2" "$(psqlq "SELECT c->>'colspan' FROM community.pages p, jsonb_array_elements(p.doc->'blocks') b, jsonb_array_elements(b->'rows') r, jsonb_array_elements(r->'cells') c WHERE p.public_id='$PE' AND b->>'type'='table' AND c ? 'colspan' LIMIT 1")"
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
bash -n "$ROOT/tests/community/stage6c.sh" && expect "stage6c.sh 문법" "0" "0"
python3 -c "import ast,io;ast.parse(io.open('$ROOT/tests/community/browser_6c.py',encoding='utf-8').read())" \
  && expect "browser_6c.py 문법" "0" "0"
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
  for s in ${REGRESS_LIST:-community/stage3 community/stage4a community/stage4b community/stage4c community/stage4d community/stage5a community/stage5a2 community/stage5c community/stage5d community/stage5e community/stage6a community/stage6b stage26 help_button api_whoami api_read api_folders api_upload api_tokens f65 f66 f68}; do
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

