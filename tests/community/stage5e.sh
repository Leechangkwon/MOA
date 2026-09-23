#!/usr/bin/env bash
# tests/community/stage5e.sh — 5-E 수락 기준 C:T-110 ~ C:T-115 실행 (첨부 · 토글 · 표 · 페이지 링크 · 역참조)
#                              (../../../community/stage5e-attach-blocks-directive.md 「완료 기준」)
#
#   C:T-110 검증       모르는 모양·`tbox:` 형태 아님·병합 칸·행마다 칸 수 다름·201행·21열·열 폭 범위 밖 400 ·
#                      `embed` 는 허용 목록 밖이면 400 embed_not_allowed(7-C 부터 열림) · 새 블록 다섯이 든 문서 200 · 새 페이지에 파일 400
#   C:T-111 첨부 규칙  없는 파일과 못 보는 파일은 **같은 400**(응답 원문 동일) · 사내 페이지에 팀 파일 400 ·
#                      비공개 페이지엔 내 팀 파일 200 · 넓히기(범위·하위까지 함께·게시) 때 좁은 파일 400 · 판 되살리기도 같은 규칙
#   C:T-112 그리기     파일 이름은 볼 수 있는 사람에게만(다른 팀 사원 응답 원문에 이름 0) · 그림 주소는 t-box 창구
#                      (볼 수 있으면 200, 못 보면 t-box 가 막는다)
#   C:T-113 역참조     볼 수 있는 페이지만 · 링크 카드 제목도 볼 수 있는 것만
#   C:T-114 렌더러     새 블록 표본을 DocRender.php · doc-render.js 가 같은 글자·클래스로(브라우저 안에서 잰다)
#   C:T-115 브라우저   browser_5e.py — 블록 추가 다섯 · 표 창 · 파일 고르기 · 토글 펼침 · 역참조, 라이트·다크·390px
#   회귀 (REGRESS=1)
#
# ★ 이 시험은 **운영 DB 를 한 글자도 건드리지 않는다** — stage5d.sh 와 같은 틀(임시 DB `tbox_5e_test`, 포트 8089).
# 사용: sudo -v && bash tests/community/stage5e.sh     (REGRESS=1 이면 회귀까지, SHOTS=<폴더> 면 스크린샷)
set -u

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
PORT=8089
SITE="http://127.0.0.1:$PORT"
BASE="$SITE/tbox"
CBASE="$SITE/community"
UA="tbox-review/$(date +%F)"
SRC_DB="tbox_re"          # 운영 — **읽기만** 한다
DB="tbox_5e_test"         # 임시 — 여기에만 쓴다
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
hr "5-E — 임시 인스턴스 준비 (운영 DB 에는 쓰지 않는다)"

avail_kb=$(df -Pk / | awk 'NR==2{print $4}')
echo "루트 파티션 여유: $(df -Ph / | awk 'NR==2{print $4}') (${avail_kb}KB)"
[ "$avail_kb" -ge 1048576 ] || { echo "루트 디스크 여유 1GB 미만 — 시험을 시작하지 않는다 (SECURITY R-80)"; exit 3; }

sudo -n -u postgres psql -d "$SRC_DB" -tAc "SELECT 1" >/dev/null 2>&1 \
  || { echo "psql(postgres) 을 쓸 수 없다 — 이 시험은 임시 DB 가 없으면 성립하지 않는다"; exit 3; }

sub "① 운영 DB 를 **읽어** 임시 DB 를 만든다"
DUMP="/tmp/tbox5e_dump.$$.sql"
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
           2026-09-24_community_discussion.sql 2026-09-25_community_databases.sql 2026-09-26_community_purge.sql 2026-09-27_community_templates.sql 2026-09-28_community_embeds.sql; do
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

# ─── 5-E 도우미 ───────────────────────────────────────────────
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
space_of() { psqlq "SELECT s.id FROM community.spaces s WHERE s.kind='team' AND s.team_id=$1 ORDER BY s.id LIMIT 1"; }

# ─── 시험 파일 (t-box 창구로 올린다 — 임시 인스턴스의 임시 저장소) ───
F_INT=$(upload qa_employee01 'C5E-사내파일.txt' internal)
F_T1=$(upload qa_employee01 'C5E-팀1파일.txt' team "$TEAM1")
F_T2=$(upload qa_employee02 'C5E-팀2파일.txt' team "$TEAM2")
F_ADM=$(upload qa_admin01 'C5E-관리자파일.txt' admin-only)
F_IMG=$(upload qa_employee01 'C5E-그림.png' internal)
for f in F_INT F_T1 F_T2 F_ADM F_IMG; do
  eval "v=\$$f"
  [ -n "$v" ] && echo "  ✔ $f 올림" || { echo "  ✖ $f 업로드 실패 — $(cat "$WORK/up.json")"; exit 4; }
done
# 이 인스턴스에는 악성코드 검사 작업자가 없다 — 그림 미리보기가 「검사 중」 423 이 되지 않게 **임시 DB 에서만** 깨끗함으로.
psqlq "UPDATE public.files SET scan_status='clean' WHERE public_id IN ('$F_INT','$F_T1','$F_T2','$F_ADM','$F_IMG')" > /dev/null
NOFILE=$(python3 -c 'import uuid;print(uuid.uuid4())')

# ════════════════════════════════════════════════════════════════
hr "C:T-110  검증 — 새 블록 다섯의 모양"
P0=$(make_page qa_employee01 'C5E-검증 페이지')
PL=$(make_page qa_employee01 'C5E-링크 대상')
expect "새 블록 다섯(토글·표·링크·그림·파일)이 든 문서 → 200" "200" \
  "$(put_doc qa_employee01 "$P0" "[TOG('펼치기',['안쪽 하나','안쪽 둘']), TAB([['항목','담당'],['계획','김']]), LNK('$PL'), IMG('$F_IMG','wide','설명'), FIL('$F_INT','첨부')]")"
expect "  저장된 본문에 토글 하위 문단 둘" "2" "$(psqlq "SELECT jsonb_array_length(doc->'blocks'->0->'children') FROM community.pages WHERE public_id='$P0'")"
expect "file_ref 가 tbox: 형태가 아님(http) → 400" "400" "$(put_doc qa_employee01 "$P0" "[IMG('http://evil.example/x.png')]")"
contains "  사유 bad_ref" "bad_ref" "$(errs)"
expect "file_ref 가 tbox:UUID 아님 → 400" "400" "$(put_doc qa_employee01 "$P0" "[FIL('tbox:../../etc/passwd')]")"
expect "표 칸 병합이 글자 든 칸을 덮음(6-C 부터 병합은 되지만 덮이는 칸은 비어야) → 400" "400" "$(put_doc qa_employee01 "$P0" "[{**TAB([['a','b'],['c','d']]), 'rows':[{'cells':[{'text':[],'rowspan':2},{'text':[]}]},{'cells':[{'text':[{'t':'c','m':{}}]},{'text':[]}]}]}]")"
expect "행마다 칸 수가 다름 → 400" "400" "$(put_doc qa_employee01 "$P0" "[TAB([['a','b'],['c']])]")"
contains "  사유 ragged_row" "ragged_row" "$(errs)"
expect "201행 → 400" "400" "$(put_doc qa_employee01 "$P0" "[TAB([['x']]*201)]")"
expect "21열 → 400" "400" "$(put_doc qa_employee01 "$P0" "[TAB([['x']*21])]")"
expect "200행 × 1열 → 200 (한도 안)" "200" "$(put_doc qa_employee01 "$P0" "[TAB([['x']]*200)]")"
expect "열 폭 39px → 400" "400" "$(put_doc qa_employee01 "$P0" "[TAB([['a','b']], col_widths=[39,100])]")"
expect "열 폭 수가 열 수보다 많음 → 400" "400" "$(put_doc qa_employee01 "$P0" "[TAB([['a','b']], col_widths=[100,100,100])]")"
expect "모르는 칸(표에 onclick) → 400" "400" "$(put_doc qa_employee01 "$P0" "[TAB([['a']], onclick='x')]")"
expect "토글에 하위가 없다 → 400" "400" "$(put_doc qa_employee01 "$P0" "[{**TOG('s',[]), 'children': None}]")"
expect "link_to_page 가 UUID 아님 → 400" "400" "$(put_doc qa_employee01 "$P0" "[{**LNK('$PL'), 'page_id':'x'}]")"
expect "그림 폭이 목록 밖 → 400" "400" "$(put_doc qa_employee01 "$P0" "[IMG('$F_IMG','huge')]")"
expect "허용 목록 밖 embed → 400(7-C)" "400" "$(put_doc qa_employee01 "$P0" "[{'id':'$(python3 -c 'import uuid;print(uuid.uuid4())')','type':'embed','url':'https://example.com/'}]")"
contains "  사유 embed_not_allowed" "embed_not_allowed" "$(jget "d.get('reason','')" < "$WORK/b")"
jbody "{'title':'C5E-새 페이지 파일','doc':{'schema':1,'blocks':[{'id':'$(python3 -c 'import uuid;print(uuid.uuid4())')','type':'file','file_ref':'tbox:$F_INT'}]}}"
st=$(capi POST /api/pages qa_employee01 "$WORK/body.json")
expect "새 페이지 만들 때 본문에 파일 → 400 (만든 뒤 편집으로 붙인다)" "400" "$st"

# ════════════════════════════════════════════════════════════════
hr "C:T-111  첨부 규칙 — 같은 400 · 범위보다 좁은 파일 400"
PP=$(make_page qa_employee01 'C5E-비공개 페이지')
m0=$(mark)
st1=$(put_doc qa_employee01 "$PP" "[FIL('$NOFILE')]"); b1=$(cat "$WORK/b")
st2=$(put_doc qa_employee01 "$PP" "[FIL('$F_T2')]");   b2=$(cat "$WORK/b")
expect "없는 파일 → 400" "400" "$st1"
expect "남의 팀 파일(못 봄) → 400" "400" "$st2"
expect "  두 응답 원문이 한 글자도 다르지 않다(결정 E-5)" "$b1" "$b2"
expect "  감사 page.update deny file_ref 2건" "2" "$(audit_n "$m0" page.update deny file_ref)"
lacks "  감사 detail 에 파일 UUID 가 없다" "$F_T2" "$(psqlq "SELECT string_agg(coalesce(detail::text,''),' ') FROM audit_logs WHERE id > $m0")"
expect "비공개 페이지에 내 팀 파일 → 200" "200" "$(put_doc qa_employee01 "$PP" "[FIL('$F_T1'), P('본문')]")"
expect "  그 페이지를 사내로 넓히기 → 400 file_narrower" "400" "$(capi PATCH "/api/pages/$PP/visibility" qa_employee01 <(printf '{"visibility":"internal"}'))"
contains "  사유 file_narrower" "file_narrower" "$(cat "$WORK/b")"
expect "  팀으로 넓히기(내 팀 = 파일 팀) → 200" "200" "$(capi PATCH "/api/pages/$PP/visibility" qa_employee01 <(printf '{"visibility":"team"}'))"
expect "  팀 범위 페이지에 남의 팀 파일은 여전히 400" "400" "$(put_doc qa_employee01 "$PP" "[FIL('$F_T1'), FIL('$F_T2')]")"
PI=$(make_page qa_employee01 'C5E-사내 페이지'); set_vis qa_employee01 "$PI" internal
expect "사내 페이지에 팀 파일 → 400" "400" "$(put_doc qa_employee01 "$PI" "[FIL('$F_T1')]")"
expect "사내 페이지에 사내 파일 → 200" "200" "$(put_doc qa_employee01 "$PI" "[FIL('$F_INT','사내 첨부'), IMG('$F_IMG','normal','그림 설명')]")"
expect "관리자 전용 파일은 관리자도 비공개 페이지에만" "400" \
  "$(PA=$(make_page qa_admin01 'C5E-관리자 사내'); set_vis qa_admin01 "$PA" internal; put_doc qa_admin01 "$PA" "[FIL('$F_ADM')]")"
PAP=$(make_page qa_admin01 'C5E-관리자 비공개')
expect "  관리자 비공개 페이지에 관리자 전용 파일 → 200" "200" "$(put_doc qa_admin01 "$PAP" "[FIL('$F_ADM')]")"
expect "사원이 관리자 전용 파일(못 봄) → 400" "400" "$(put_doc qa_employee01 "$PP" "[FIL('$F_T1'), FIL('$F_ADM')]")"
# 하위까지 함께 넓히기 — 하위의 좁은 파일도 본다
PR=$(make_page qa_employee01 'C5E-부모'); PK=$(make_page qa_employee01 'C5E-자식' "$PR")
put_doc qa_employee01 "$PK" "[FIL('$F_T1')]" > /dev/null
expect "하위까지 함께 사내로 — 자식의 팀 파일 때문에 400" "400" \
  "$(capi PATCH "/api/pages/$PR/visibility" qa_employee01 <(printf '{"visibility":"internal","cascade":true}'))"
expect "  부모는 그대로 비공개" "private" "$(pg_page "$PR" "p.visibility")"
expect "하위까지 함께 팀으로(같은 팀 파일) → 200" "200" \
  "$(capi PATCH "/api/pages/$PR/visibility" qa_employee01 <(printf '{"visibility":"team","cascade":true}'))"
# 게시 — 자유게시판(사내)에 팀 파일이 붙은 페이지
PB=$(make_page qa_employee01 'C5E-게시할 페이지')
put_doc qa_employee01 "$PB" "[FIL('$F_T1')]" > /dev/null
m1=$(mark)
expect "게시(자유게시판)하려는데 팀 파일 → 400" "400" "$(publish qa_employee01 "$PB" "$B_FREE")"
contains "  사유 file_narrower" "file_narrower" "$(cat "$WORK/b")"
expect "  감사 page.publish deny file_narrower 1건" "1" "$(audit_n "$m1" page.publish deny file_narrower)"
expect "  페이지는 게시되지 않았다" "private" "$(pg_page "$PB" "p.visibility")"
put_doc qa_employee01 "$PB" "[FIL('$F_INT')]" > /dev/null
expect "사내 파일로 바꾸면 게시 → 200" "200" "$(publish qa_employee01 "$PB" "$B_FREE")"
expect "게시 중 페이지에 팀 파일을 새로 붙이기 → 400" "400" "$(put_doc qa_employee01 "$PB" "[FIL('$F_INT'), FIL('$F_T1')]")"
# 판 되살리기 — 예전 판에 팀 파일, 지금은 사내
PV=$(make_page qa_employee01 'C5E-되살리기')
put_doc qa_employee01 "$PV" "[FIL('$F_T1')]" > /dev/null
# 같은 사람이 5분 안에 고치면 판을 합친다 — **임시 DB 에서만** 마지막 판 시각을 10분 앞으로 당겨 새 판이 남게 한다.
psqlq "UPDATE community.page_versions v SET edited_at = v.edited_at - interval '10 minutes' FROM community.pages p WHERE p.id=v.page_id AND p.public_id='$PV'" > /dev/null
put_doc qa_employee01 "$PV" "[P('파일 뺌')]" > /dev/null
set_vis qa_employee01 "$PV" internal
VN=$(psqlq "SELECT v.version FROM community.page_versions v JOIN community.pages p ON p.id=v.page_id WHERE p.public_id='$PV' AND v.doc::text LIKE '%$F_T1%' ORDER BY v.version DESC LIMIT 1")
if [ -n "$VN" ]; then
  m2=$(mark)
  expect "사내 페이지에 팀 파일이 든 예전 판 되살리기 → 400" "400" "$(capi POST "/api/pages/$PV/versions/$VN/restore" qa_employee01 <(printf '{}'))"
  expect "  감사 page.restore_version deny file_ref 1건" "1" "$(audit_n "$m2" page.restore_version deny file_ref)"
else
  skip "판 되살리기" "팀 파일이 든 판을 찾지 못했다(판 저장 간격)"
fi

# ════════════════════════════════════════════════════════════════
hr "C:T-112  그리기 — 파일 이름은 볼 수 있는 사람에게만 · 그림은 t-box 창구"
capi GET "/api/pages/$PI" qa_employee02 > /dev/null
contains "사내 페이지 — 다른 팀 사원도 사내 파일 이름을 본다" "C5E-사내파일.txt" "$(cat "$WORK/b")"
expect "  file_titles 의 그림 표시(image) 참" "True" "$(jget "d['file_titles']['$F_IMG']['image']" < "$WORK/b")"
expect "  그림 원본 — t-box 창구가 employee02 에게 200" "200" "$(tapi GET "/api/files/$F_IMG/preview/raw" qa_employee02)"
# 비공개 페이지 + 팀 파일 + 다른 팀 사원에게 보기 공유 → 페이지는 보지만 파일 이름은 0
PS=$(make_page qa_employee01 'C5E-공유 페이지')
put_doc qa_employee01 "$PS" "[FIL('$F_T1','팀 첨부'), IMG('$F_T1')]" > /dev/null
share qa_employee01 "$PS" qa_employee02 view > /dev/null
expect "공유받은 다른 팀 사원이 페이지 GET → 200" "200" "$(capi GET "/api/pages/$PS" qa_employee02)"
lacks "  응답 원문에 팀 파일 이름 없음" "C5E-팀1파일" "$(cat "$WORK/b")"
expect "  file_titles 는 빈 지도" "0" "$(jget "len(d['file_titles'])" < "$WORK/b")"
code=$(tapi GET "/api/files/$F_T1/preview/raw" qa_employee02)
expect "  그 파일 원본을 직접 부르면 t-box 가 막는다(403)" "403" "$code"
capi GET "/api/pages/$PS" qa_employee01 > /dev/null
contains "주인에게는 이름이 보인다" "C5E-팀1파일.txt" "$(cat "$WORK/b")"
# 판 이력 응답도 이름을 흘리지 않는다
capi GET "/api/pages/$PS/versions" qa_employee02 > /dev/null
lacks "  판 목록에 팀 파일 이름 없음" "C5E-팀1파일" "$(cat "$WORK/b")"

# ════════════════════════════════════════════════════════════════
hr "C:T-113  역참조 — 볼 수 있는 페이지만"
PT=$(make_page qa_employee01 'C5E-참조받는 페이지'); set_vis qa_employee01 "$PT" internal
PX=$(make_page qa_employee01 'C5E-비밀 참조 페이지')
put_doc qa_employee01 "$PX" "[LNK('$PT')]" > /dev/null
PY_=$(make_page qa_employee02 'C5E-공개 참조 페이지'); set_vis qa_employee02 "$PY_" internal
put_doc qa_employee02 "$PY_" "[P('링크 마크 말고 카드로'), LNK('$PT')]" > /dev/null
capi GET "/api/pages/$PT" qa_employee02 > /dev/null
contains "다른 사원 — 사내 참조 페이지는 보인다" "C5E-공개 참조 페이지" "$(cat "$WORK/b")"
lacks "  비공개 참조 페이지 제목은 없다" "C5E-비밀 참조 페이지" "$(cat "$WORK/b")"
expect "  역참조 수 1 (가려진 수를 말하지 않는다)" "1" "$(jget "len(d['backlinks'])" < "$WORK/b")"
capi GET "/api/pages/$PT" qa_employee01 > /dev/null
contains "주인 — 자기 비공개 참조 페이지도 본다" "C5E-비밀 참조 페이지" "$(cat "$WORK/b")"
# 링크 카드 제목: 사내 페이지가 비공개 페이지를 가리키면 남에게 제목 없음
PZ=$(make_page qa_employee01 'C5E-링크 카드 페이지'); set_vis qa_employee01 "$PZ" internal
put_doc qa_employee01 "$PZ" "[LNK('$PX'), LNK('$PT')]" > /dev/null
capi GET "/api/pages/$PZ" qa_employee02 > /dev/null
lacks "링크 카드 — 못 보는 페이지 제목 없음" "C5E-비밀 참조 페이지" "$(cat "$WORK/b")"
contains "  볼 수 있는 페이지 제목은 있다" "C5E-참조받는 페이지" "$(cat "$WORK/b")"
PCELL=$(make_page qa_employee02 'C5E-표 칸 링크 페이지'); set_vis qa_employee02 "$PCELL" internal
put_doc qa_employee02 "$PCELL" "[{**TAB([['a']]), 'rows':[{'cells':[{'text':[{'t':'칸 링크','m':{'link':{'href':'page:$PT'}}}]}]}]}]" > /dev/null
capi GET "/api/pages/$PT" qa_employee01 > /dev/null
contains "표 칸 안의 페이지 링크도 역참조에 (독립 검토 L4)" "C5E-표 칸 링크 페이지" "$(cat "$WORK/b")"
capi GET "/api/pages/$PZ" qa_employee02 > /dev/null
lacks "  역참조 목록에 자기 자신은 없다" "\"id\":\"$PZ\"" "$(jget "json.dumps(d['backlinks'])" < "$WORK/b")"

# ════════════════════════════════════════════════════════════════
hr "C:T-114  렌더러 동등성 — 새 블록 표본 (PHP 쪽. JS 쪽은 브라우저에서)"
python3 - "$WORK" "$F_INT" "$F_IMG" "$F_T1" "$PT" "$PX" <<'PY'
import json, os, sys, uuid
work, fint, fimg, fteam, pt, px = sys.argv[1:7]
def u(): return str(uuid.uuid4())
def seg(t): return [{"t": t, "m": {}}]
S = []
S.append({"schema":1,"blocks":[{"id":u(),"type":"toggle","text":seg("요약 줄"),"children":[{"id":u(),"type":"paragraph","text":seg("안쪽")}]}]})
S.append({"schema":1,"blocks":[{"id":u(),"type":"table","header_row":True,"header_col":True,"rows":[{"cells":[{"text":seg("가")},{"text":seg("나")}]},{"cells":[{"text":seg("다")},{"text":[]}]}]}]})
S.append({"schema":1,"blocks":[{"id":u(),"type":"table","header_row":False,"header_col":False,"rows":[{"color":"blue_bg","cells":[{"text":seg("색 행"),"color":"red"}]}]}]})
S.append({"schema":1,"blocks":[{"id":u(),"type":"link_to_page","page_id":pt},{"id":u(),"type":"link_to_page","page_id":px}]})
S.append({"schema":1,"blocks":[{"id":u(),"type":"image","file_ref":"tbox:"+fimg,"width":"wide","caption":seg("그림 설명")}]})
S.append({"schema":1,"blocks":[{"id":u(),"type":"file","file_ref":"tbox:"+fint,"caption":seg("파일 설명")}]})
S.append({"schema":1,"blocks":[{"id":u(),"type":"file","file_ref":"tbox:"+fteam},{"id":u(),"type":"image","file_ref":"tbox:"+fteam,"width":"small"}]})
S.append({"schema":1,"blocks":[{"id":u(),"type":"toggle","text":seg("<img src=x onerror=alert(1)>"),"children":[{"id":u(),"type":"paragraph","text":seg("<b>글자</b>")}]}]})
ctx = {"base": "", "tbox_base": "/tbox",
       "link_titles": {pt: {"title": "참조받는", "icon": None}},
       "file_titles": {fint: {"name": "사내.txt", "size": 1536, "image": False}, fimg: {"name": "그림.png", "size": 70, "image": True}}}
json.dump(S, open(os.path.join(work, "samples5e.json"), "w", encoding="utf-8"), ensure_ascii=False)
json.dump(ctx, open(os.path.join(work, "ctx5e.json"), "w", encoding="utf-8"), ensure_ascii=False)
PY
ROOT="$INST/app" WORK="$WORK" php -r '
define("TBOX_BASE", "/tbox"); define("COMMUNITY_BASE", "/community");
require getenv("ROOT") . "/src/Http.php";
require getenv("ROOT") . "/src/community/Doc.php";
require getenv("ROOT") . "/src/community/DocRender.php";
$samples = json_decode(file_get_contents(getenv("WORK") . "/samples5e.json"), true);
$ctx = json_decode(file_get_contents(getenv("WORK") . "/ctx5e.json"), true);
$out = []; $bad = 0;
foreach ($samples as $doc) {
    $v = Doc::validate($doc, 5);
    if (!$v["ok"]) { $bad++; fwrite(STDERR, json_encode($v["errors"]) . "\n"); }
    $html = DocRender::html($doc, $ctx);
    $texts = [];
    foreach (preg_split("/<[^>]*>/", $html) as $chunk) {
        $t = trim(html_entity_decode($chunk, ENT_QUOTES, "UTF-8"));
        if ($t !== "") { $texts[] = $t; }
    }
    $classes = [];
    preg_match_all("/class=\"([^\"]*)\"/", $html, $m);
    foreach ($m[1] as $cl) { foreach (preg_split("/\s+/", $cl) as $k) { if ($k !== "") { $classes[] = $k; } } }
    $classes = array_values(array_unique($classes)); sort($classes);
    $out[] = ["texts" => $texts, "classes" => $classes, "img" => substr_count($html, "<img"), "html" => $html];
}
file_put_contents(getenv("WORK") . "/php5e.json", json_encode($out, JSON_UNESCAPED_UNICODE));
printf("표본 %d개 · 스키마를 통과하지 못한 것 %d개\n", count($samples), $bad);
' > "$WORK/parity5e.txt" 2>&1
cat "$WORK/parity5e.txt" | head -5
expect "새 블록 표본 8개가 모두 스키마를 지난다" "표본 8개 · 스키마를 통과하지 못한 것 0개" "$(tail -1 "$WORK/parity5e.txt")"
expect "  PHP — 지도에 없는 파일(팀 파일)은 <img> 를 만들지 않는다" "0" "$(jget "d[6]['img']" < "$WORK/php5e.json")"
contains "  PHP — 지도에 없는 페이지는 「볼 수 없는 페이지」" "볼 수 없는 페이지" "$(jget "' '.join(d[3]['texts'])" < "$WORK/php5e.json")"
lacks "  PHP — 토글 제목의 태그는 글자로(img 요소 0)" "<img src=x" "$(jget "d[7]['html']" < "$WORK/php5e.json")"
expect "doc-schema.json 사본이 원본(지시서 폴더 사본)과 같다" "0" \
  "$(diff "$ROOT/public/assets/community/doc-schema.json" "$INST/community/doc-schema.json" >/dev/null 2>&1; echo $?)"

# ════════════════════════════════════════════════════════════════
hr "C:T-115  브라우저 — browser_5e.py (렌더러 JS 쪽 동등성 포함)"
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
  PE=$(make_page qa_employee01 'C5E-브라우저 페이지'); set_vis qa_employee01 "$PE" internal
  PCOL=$(make_page qa_employee01 'C5E-꾸민 표')
  put_doc qa_employee01 "$PCOL" "[{**TAB([['색 칸']]), 'rows':[{'cells':[{'text':[{'t':'색 칸','m':{}}],'color':'red'}]}]}]" > /dev/null
  SMOKE_PCOL="$PCOL" SMOKE_E1="$(cookie_of qa_employee01)" SMOKE_E2="$(cookie_of qa_employee02)" \
  SMOKE_PE="$PE" SMOKE_PT="$PT" SMOKE_PI="$PI" SMOKE_FILE_NAME='C5E-사내파일.txt' SMOKE_IMG_NAME='C5E-그림.png' \
  SMOKE_SAMPLES="$WORK/samples5e.json" SMOKE_CTX="$WORK/ctx5e.json" SMOKE_PHP="$WORK/php5e.json" SMOKE_SHOTS="${SHOTS:-}" \
    python3 "$ROOT/tests/community/browser_5e.py" "$SITE" "$UA" > "$WORK/smoke.txt" 2>&1
  sed 's/^/    /' "$WORK/smoke.txt"
  expect "브라우저 실패 0" "0" "$(sed -n 's/^SMOKE_FAIL=//p' "$WORK/smoke.txt" | tail -1)"
  expect "  브라우저가 저장한 문서에 표·토글·파일" "file table toggle" \
    "$(psqlq "SELECT string_agg(b->>'type', ' ' ORDER BY b->>'type') FROM community.pages p, jsonb_array_elements(p.doc->'blocks') b WHERE p.public_id='$PE' AND b->>'type' IN ('table','toggle','file')")"
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
bash -n "$ROOT/tests/community/stage5e.sh" && expect "stage5e.sh 문법" "0" "0"
python3 -c "import ast,io;ast.parse(io.open('$ROOT/tests/community/browser_5e.py',encoding='utf-8').read())" \
  && expect "browser_5e.py 문법" "0" "0"
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
  for s in ${REGRESS_LIST:-community/stage3 community/stage4a community/stage4b community/stage4c community/stage4d community/stage5a community/stage5a2 community/stage5c community/stage5d stage26 help_button api_whoami api_read api_folders api_upload api_tokens f65 f66 f68}; do
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

