#!/usr/bin/env bash
# tests/community/stage5d.sh — 5-D 수락 기준 C:T-100 ~ C:T-105 실행 (댓글 · 멘션 · 알림 · 공지 읽음)
#                              (../../../community/stage5d-discussion-directive.md 「완료 기준」)
#
#   C:T-100 댓글       페이지를 따른다(못 보면 GET·POST 403) · 평문(2,001자·제어·서식 문자·허용 키 밖 400, 줄바꿈 보존) ·
#                      답글 1단(답글의 답글 400) · 블록 앵커(없는 블록·인용만 400) · 고치기·지우기는 쓴 사람만(+감사) ·
#                      논리 삭제 · 숨김(Admin·게시판 관리자, 본문 보존·남에게 본문 없음) · 해결됨 · 도움됐어요 · 보관 게시판 400
#   C:T-101 멘션·알림  볼 수 없는 사람에게 알림 0(특권만 있는 Admin 포함) · 후보는 볼 수 있는 사람만 · 공유를 빼면 알림 제목이
#                      사라진다 · 답글·내 페이지·인계 알림 · 남의 알림 읽음 0 · 모두 읽음
#   C:T-102 공지 읽음  한 사람 한 줄 · n/N 수만 · 쓴 사람·Admin 만 · 공지가 아니면 400
#   C:T-103 게시 전 댓글은 게시판 독자에게 없다(5-C #9 와 같은 자르기)
#   C:T-104 기록 불변  comments·notifications·page_reads DELETE 42501
#   C:T-105 브라우저   browser_5d.py — 댓글·@ 후보·인용·답글·고치기·지우기 확인·알림 종·공지 읽음, 라이트·다크·390px
#   회귀 (REGRESS=1)
#
# ★ 이 시험은 **운영 DB 를 한 글자도 건드리지 않는다** — stage5c.sh 와 같은 틀(임시 DB `tbox_5d_test`, 포트 8088).
# 사용: sudo -v && bash tests/community/stage5d.sh     (REGRESS=1 이면 회귀까지, SHOTS=<폴더> 면 스크린샷)
set -u

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
. "$ROOT/tests/lib/secrets.sh" || { echo "tests/lib/secrets.sh 를 읽지 못했다 — 시험을 시작하지 않는다 (R-62)"; exit 3; }
PORT=8088
SITE="http://127.0.0.1:$PORT"
BASE="$SITE/tbox"
CBASE="$SITE/community"
UA="tbox-review/$(date +%F)"
SRC_DB="tbox_re"          # 운영 — **읽기만** 한다
DB="tbox_5d_test"         # 임시 — 여기에만 쓴다
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
hr "5-D — 임시 인스턴스 준비 (운영 DB 에는 쓰지 않는다)"

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

sub "② 마이그레이션 + 권한 (임시 DB 에만) — 앞 단계(이미 있으면 그대로) · 5-C · **5-D 댓글을 두 번** 돌려 멱등을 본다"
for mig in 2026-09-23_community_page_properties.sql 2026-09-23_community_owner_share.sql \
           2026-09-23_users_display_name.sql 2026-09-24_community_boards.sql \
           2026-09-24_community_discussion.sql 2026-09-24_community_discussion.sql 2026-09-25_community_databases.sql 2026-09-26_community_purge.sql 2026-09-27_community_templates.sql 2026-09-28_community_embeds.sql; do
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
    "${QCHECK_ENV_SRC:-$ROOT/.env}" > "$INST/app/.env"
chmod 600 "$INST/app/.env"
grep -q "^DB_NAME=$DB\$" "$INST/app/.env" || { echo "임시 .env 에 임시 DB 이름이 들어가지 않았다 — 중단"; exit 4; }
# 회귀 스크립트 일부가 `$ROOT/../community/doc-schema.json` 을 읽는다 — 기획 폴더를 **읽기 전용 링크**로 둔다.
PLAN_DIR="$(cd "$ROOT/.." && pwd)/community"
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

sub "로그인 (검토 계정 claude_ 다섯 — **순차**)"
for who in claude_employee01 claude_employee02 claude_manager01 claude_admin01 claude_guest01; do
  tok=$(login "$who")
  if [ -z "$tok" ]; then echo "  ✖ $who 로그인 실패 — 중단"; exit 4; fi
  eval "CSRF_${who}='$tok'"
  echo "  ✔ $who"
done
# 개인 공간을 연다(없으면 만든다) — 그 뒤의 목록·인계가 공간을 전제로 한다.
for who in claude_employee01 claude_employee02 claude_manager01; do capi GET /api/me/space "$who" > /dev/null; done
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
  # 비밀번호는 인자가 아니라 표준입력 첫 줄로(R-62 · py_secret). 파일에 쓰는 바이트(json.dumps + 줄바꿈)는 같다.
  py_secret pw -- python3 -c 'import json,sys; pw=sys.stdin.readline().rstrip("\n"); print(json.dumps({"password":pw}))' > "$WORK/reauth.json"
  rc=$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR_DIR/claude_admin01.jar" -A "$UA" -H 'Content-Type: application/json' \
        -H "X-CSRF-Token: $CSRF_claude_admin01" --data-binary "@$WORK/reauth.json" "$BASE/api/me/reauth")
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

# ════════════════════════════════════════════════════════════════
hr "C:T-100  댓글 — 페이지를 따른다 · 평문 · 답글 1단 · 앵커 · 쓴 사람만 고치기"
PP=$(make_page claude_employee01 'C5D-비공개 페이지')
PI=$(make_page claude_employee01 'C5D-사내 페이지'); set_vis claude_employee01 "$PI" internal
expect "비공개 페이지 — 남 GET 댓글 403" "403" "$(capi GET "/api/pages/$PP/comments" claude_employee02)"
expect "비공개 페이지 — 남 POST 댓글 403" "403" "$(comment claude_employee02 "$PP" "{'body':'C5D-침입'}")"
expect "주인 댓글 → 201" "201" "$(comment claude_employee01 "$PP" "{'body':'C5D-주인 메모'}")"
expect "Admin 이 남의 비공개 페이지 댓글 GET → 200 (admin_all, 감사)" "200" "$(capi GET "/api/pages/$PP/comments" claude_admin01)"
expect "Guest 댓글 → 403 (라우트 정책)" "403" "$(comment claude_guest01 "$PI" "{'body':'C5D-손님'}")"
expect "Guest 가 사내 페이지 댓글 GET → 403" "403" "$(capi GET "/api/pages/$PI/comments" claude_guest01)"
expect "사내 페이지 — 다른 사원 댓글 201" "201" "$(comment claude_employee02 "$PI" "{'body':'C5D-첫 댓글\n둘째 줄'}")"
C1=$(cid)
expect "  줄바꿈은 그대로 저장" "2" "$(psqlq "SELECT array_length(string_to_array(body, E'\n'),1) FROM community.comments WHERE public_id='$C1'")"
expect "2,001자 → 400" "400" "$(comment claude_employee02 "$PI" "{'body':'가'*2001}")"
expect "빈 본문(공백만) → 400" "400" "$(comment claude_employee02 "$PI" "{'body':'   '}")"
expect "제어 문자 → 400" "400" "$(comment claude_employee02 "$PI" "{'body':'a\u0007b'}")"
expect "서식 문자(U+202E) → 400" "400" "$(comment claude_employee02 "$PI" "{'body':'a‮b'}")"
expect "허용 키 밖 → 400" "400" "$(comment claude_employee02 "$PI" "{'body':'x','html':'<b>'}")"
expect "스크립트 모양 본문 → 201 · 글자 그대로 저장" "201" "$(comment claude_employee02 "$PI" "{'body':'<script>alert(1)</script>'}")"
capi GET "/api/pages/$PI/comments" claude_employee01 > /dev/null
contains "  목록 JSON 에 글자 그대로(문자열 — 화면은 textContent 로만 넣는다)" '<script>alert(1)</script>' "$(cat "$WORK/b")"
expect "답글(첫 댓글에) → 201" "201" "$(comment claude_employee01 "$PI" "{'body':'C5D-답글','parent_id':'$C1'}")"
R1=$(cid)
expect "답글의 답글 → 400 reply_depth" "400|reply_depth" "$(comment claude_employee02 "$PI" "{'body':'x','parent_id':'$R1'}")|$(jget "d.get('reason','')" < "$WORK/b")"
comment claude_employee01 "$PP" "{'body':'C5D-다른 페이지 댓글'}" > /dev/null; OTHER=$(cid)
expect "다른 페이지 댓글을 부모로 → 400" "400" "$(comment claude_employee02 "$PI" "{'body':'x','parent_id':'$OTHER'}")"
BLK=$(psqlq "SELECT b.block_id FROM community.page_blocks b JOIN community.pages p ON p.id=b.page_id WHERE p.public_id='$PI' LIMIT 1")
expect "블록 앵커 + 인용 → 201" "201" "$(comment claude_employee02 "$PI" "{'body':'C5D-이 줄','block_id':'$BLK','quote':'인용 문구'}")"
expect "인용만(블록 없이) → 400" "400" "$(comment claude_employee02 "$PI" "{'body':'x','quote':'q'}")"
expect "이 페이지에 없는 블록 → 400" "400" "$(comment claude_employee02 "$PI" "{'body':'x','block_id':'00000000-0000-4000-8000-000000000000'}")"
expect "301자 인용 → 400" "400" "$(comment claude_employee02 "$PI" "{'body':'x','block_id':'$BLK','quote':'가'*301}")"
A0=$(mark)
expect "남이 고치기 → 403" "403" "$(capi PATCH "/api/comments/$C1" claude_employee01 <(printf '{"body":"C5D-탈취"}'))"
expect "남이 지우기 → 403" "403" "$(capi DELETE "/api/comments/$C1" claude_employee01)"
expect "  감사 comment.update·delete deny not_author" "1|1" "$(audit_n "$A0" comment.update deny not_author)|$(audit_n "$A0" comment.delete deny not_author)"
expect "Admin 도 고치기 → 403 (숨김만)" "403" "$(capi PATCH "/api/comments/$C1" claude_admin01 <(printf '{"body":"C5D-탈취"}'))"
expect "쓴 사람 고치기 → 200" "200" "$(capi PATCH "/api/comments/$C1" claude_employee02 <(printf '{"body":"C5D-첫 댓글 (고침)"}'))"
expect "쓴 사람 지우기 → 200 · 행은 남는다(논리 삭제)" "200|1" \
  "$(capi DELETE "/api/comments/$C1" claude_employee02)|$(psqlq "SELECT count(*) FROM community.comments WHERE public_id='$C1' AND deleted_at IS NOT NULL")"
capi GET "/api/pages/$PI/comments" claude_employee02 > /dev/null
expect "  답글이 달린 첫 댓글 — 「지운 댓글」 자리(본문 없음) · 답글은 보인다" "True|None" \
  "$(jget "[c for c in d['comments'] if c['id']=='$C1'][0]['deleted']" < "$WORK/b")|$(jget "repr([c for c in d['comments'] if c['id']=='$C1'][0]['body'])" < "$WORK/b")"
lacks "  지운 본문은 응답에 없다" "C5D-첫 댓글 (고침)" "$(cat "$WORK/b")"
expect "지운 댓글 다시 지우기·고치기 → 404" "404|404" \
  "$(capi DELETE "/api/comments/$C1" claude_employee02)|$(capi PATCH "/api/comments/$C1" claude_employee02 <(printf '{"body":"x"}'))"

sub "숨김 · 해결됨 · 도움됐어요"
comment claude_employee02 "$PI" "{'body':'C5D-숨길 댓글'}" > /dev/null; CH=$(cid)
expect "쓴 사람이 아닌 사원 숨기기 → 403" "403" "$(capi POST "/api/comments/$CH/hide" claude_employee01)"
expect "Admin 숨기기 → 200 admin_all" "200|admin_all" "$(capi POST "/api/comments/$CH/hide" claude_admin01)|$(jget "d['granted_by']" < "$WORK/b")"
capi GET "/api/pages/$PI/comments" claude_employee01 > /dev/null
expect "  다른 사람에게 본문 없음 · hidden" "None|True" \
  "$(jget "repr([c for c in d['comments'] if c['id']=='$CH'][0]['body'])" < "$WORK/b")|$(jget "[c for c in d['comments'] if c['id']=='$CH'][0]['hidden']" < "$WORK/b")"
lacks "  원문에 숨긴 본문 없음" "C5D-숨길 댓글" "$(cat "$WORK/b")"
expect "  숨긴 댓글은 쓴 사람·앵커도 없다(한 줄만 — 독립 검토)" "None" "$(jget "repr([c for c in d['comments'] if c['id']=='$CH'][0]['author'])" < "$WORK/b")"
capi GET "/api/pages/$PI/comments" claude_employee02 > /dev/null
contains "  쓴 사람은 본문을 본다" "C5D-숨길 댓글" "$(cat "$WORK/b")"
expect "  DB 본문 보존" "C5D-숨길 댓글" "$(psqlq "SELECT body FROM community.comments WHERE public_id='$CH'")"
expect "  숨긴 댓글은 쓴 사람도 고치지 못한다 → 403" "403" "$(capi PATCH "/api/comments/$CH" claude_employee02 <(printf '{"body":"되살리기"}'))"
comment claude_employee02 "$PI" "{'body':'C5D-질문 스레드'}" > /dev/null; CQ=$(cid)
expect "스레드 첫 댓글 쓴 사람 해결됨 → 200" "200" "$(capi POST "/api/comments/$CQ/resolve" claude_employee02)"
expect "관리 자격 없는 Admin 해결됨 빼기 → 403" "403" "$(capi DELETE "/api/comments/$CQ/resolve" claude_admin01)"
expect "페이지 주인 해결됨 빼기 → 200" "200" "$(capi DELETE "/api/comments/$CQ/resolve" claude_employee01)"
expect "답글에 해결됨 → 404" "404" "$(capi POST "/api/comments/$R1/resolve" claude_employee01)"
expect "도움됐어요 → 1 · 두 번 눌러도 1 · 빼면 0" "1|1|0" \
  "$(capi POST "/api/comments/$CQ/helpful" claude_employee01 >/dev/null; jget "d['comment']['helpful']" < "$WORK/b")|$(capi POST "/api/comments/$CQ/helpful" claude_employee01 >/dev/null; jget "d['comment']['helpful']" < "$WORK/b")|$(capi DELETE "/api/comments/$CQ/helpful" claude_employee01 >/dev/null; jget "d['comment']['helpful']" < "$WORK/b")"

sub "게시판 — 게시판 관리자 숨김 · 보관 게시판 · 공지 댓글"
jbody "{'name': 'C5D-팀게시판', 'access_level': 'team', 'team_id': $TEAM1, 'board_kind': 'discussion'}"
reauth_admin > /dev/null; capi POST /api/boards claude_admin01 "$WORK/body.json" > /dev/null; B_TEAM=$(jget "d['board']['id']" < "$WORK/b")
TP=$(new_post claude_employee01 "$B_TEAM" 'C5D-팀 게시판 글')
comment claude_employee01 "$TP" "{'body':'C5D-팀 글 댓글'}" > /dev/null; TC=$(cid)
expect "팀 게시판 Manager(그 팀) 댓글 숨기기 → 200 team_scope" "200|team_scope" "$(capi POST "/api/comments/$TC/hide" claude_manager01)|$(jget "d['granted_by']" < "$WORK/b")"
NP=$(new_post claude_admin01 "$B_NOTICE" 'C5D-공지 하나')
expect "공지 게시판 글에 사원 댓글 → 201 (결정 D-2)" "201" "$(comment claude_employee01 "$NP" "{'body':'C5D-공지 댓글'}")"
jbody "{'name': 'C5D-보관', 'access_level': 'internal', 'board_kind': 'discussion'}"
capi POST /api/boards claude_admin01 "$WORK/body.json" > /dev/null; B_OLD=$(jget "d['board']['id']" < "$WORK/b")
OP=$(new_post claude_employee01 "$B_OLD" 'C5D-보관될 글')
jbody "{'is_active': False}"; capi PATCH "/api/boards/$B_OLD" claude_admin01 "$WORK/body.json" > /dev/null
expect "보관 게시판 글에 댓글 → 400" "400" "$(comment claude_employee02 "$OP" "{'body':'x'}")"

# ════════════════════════════════════════════════════════════════
hr "C:T-101  멘션 · 알림 — 볼 수 있는 사람에게만"
N2=$(notes_of claude_employee02); NM=$(notes_of claude_manager01); NA=$(notes_of claude_admin01)
expect "비공개 페이지에서 @ 부르기 → 201" "201" "$(comment claude_employee01 "$PP" "{'body':'@claude_employee02 @claude_manager01 @claude_admin01 봐 주세요'}")"
expect "  못 보는 사람(다른 팀 사원·같은 팀 Manager·특권만 있는 Admin)에게 알림 0" "$N2|$NM|$NA" \
  "$(notes_of claude_employee02)|$(notes_of claude_manager01)|$(notes_of claude_admin01)"
lacks "  응답에 알림 수·대상 없음" "notified" "$(cat "$WORK/b")"
capi GET "/api/mention-candidates?page=$PP&q=claude_" claude_employee01 > /dev/null; MC=$(cat "$WORK/b")
lacks "후보(비공개) — 다른 팀 사원 없음" "claude_employee02" "$MC"
lacks "후보(비공개) — 같은 팀 Manager 없음" "claude_manager01" "$MC"
lacks "후보(비공개) — Admin 없음(특권만)" "claude_admin01" "$MC"
lacks "후보 — 자기 자신 없음" "claude_employee01" "$MC"
share claude_employee01 "$PP" claude_employee02 view > /dev/null
capi GET "/api/mention-candidates?page=$PP&q=claude_" claude_employee01 > /dev/null
contains "공유받은 사람은 후보에 나온다" "claude_employee02" "$(cat "$WORK/b")"
lacks "  Guest 는 후보가 아니다" "claude_guest01" "$(cat "$WORK/b")"
# Q:F-12 U4-4 (DESIGN 2.27 · 기획 16.4) — 빈 q 도 후보를 준다(그 문서를 볼 수 있는 쓰기 자격 사람만 · 판정 그대로 · 최대 10). 옛 기대 「빈 목록」을 새 규칙으로
expect "  빈 q → 후보(공유받은 사람 claude_employee02 있음 · Guest 없음 · 최대 10)" "True True True" "$(capi GET "/api/mention-candidates?page=$PP&q=%20" claude_employee01 >/dev/null; jget "' '.join(str(x) for x in ['claude_employee02' in [c['username'] for c in d['candidates']], 'claude_guest01' not in [c['username'] for c in d['candidates']], len(d['candidates']) <= 10])" < "$WORK/b")"
expect "못 보는 페이지의 후보 → 403" "403" "$(capi GET "/api/mention-candidates?page=$PP&q=claude" claude_manager01)"
expect "공유받은 사람 @ → 알림 1 (mention)" "1" \
  "$(comment claude_employee01 "$PP" "{'body':'@claude_employee02 다시 봐 주세요'}" >/dev/null; notes_of claude_employee02 mention)"
capi GET /api/notifications claude_employee02 > /dev/null
contains "알림 목록에 페이지 제목" "C5D-비공개 페이지" "$(cat "$WORK/b")"
unshare claude_employee01 "$PP" claude_employee02 > /dev/null
capi GET /api/notifications claude_employee02 > /dev/null
lacks "공유를 빼면 알림에서 제목이 사라진다(읽을 때 다시 판정)" "C5D-비공개 페이지" "$(cat "$WORK/b")"
contains "  그 줄은 page=null" '"page":null' "$(cat "$WORK/b")"
share claude_employee01 "$PP" claude_admin01 view > /dev/null
comment claude_employee01 "$PP" "{'body':'@claude_admin01 관리자 확인 부탁'}" > /dev/null
expect "공유받은 Admin 은 멘션 알림 1" "1" "$(notes_of claude_admin01 mention)"
unshare claude_employee01 "$PP" claude_admin01 > /dev/null
capi GET /api/notifications claude_admin01 > /dev/null
lacks "공유를 빼면 Admin 알림에서도 제목이 사라진다(관리자 특권으로 다시 보이지 않는다 — 독립 검토)" "C5D-비공개 페이지" "$(cat "$WORK/b")"
N1=$(notes_of claude_employee01 comment_on_my_page)
comment claude_employee02 "$PI" "{'body':'C5D-주인에게 알릴 댓글'}" > /dev/null; C2=$(cid)
expect "내 페이지에 댓글 → 주인 알림 +1" "$((N1+1))" "$(notes_of claude_employee01 comment_on_my_page)"
R2N=$(notes_of claude_employee02 reply)
comment claude_employee01 "$PI" "{'body':'C5D-답글 알림','parent_id':'$C2'}" > /dev/null
expect "답글 → 첫 댓글 쓴 사람 알림 +1" "$((R2N+1))" "$(notes_of claude_employee02 reply)"
expect "자기 페이지에 자기 댓글 → 자기 알림 없음" "$((N1+1))" "$(notes_of claude_employee01 comment_on_my_page)"
E1N=$(psqlq "SELECT min(n.id) FROM community.notifications n JOIN users u ON u.id=n.user_id WHERE u.username='claude_employee01'")
jbody "{'ids': [$E1N]}"
expect "남의 알림 읽음 표시 → 200 · 바뀐 것 0" "200|0" "$(capi POST /api/notifications/read claude_employee02 "$WORK/body.json")|$(jget "d['changed']" < "$WORK/b")"
jbody "{'ids': ['1']}"
expect "번호가 아닌 값 → 400" "400" "$(capi POST /api/notifications/read claude_employee02 "$WORK/body.json")"
jbody "{'all': True}"
capi POST /api/notifications/read claude_employee02 "$WORK/body.json" > /dev/null
expect "모두 읽음 → 안 읽은 수 0" "0" "$(capi GET '/api/notifications?unread=1' claude_employee02 >/dev/null; jget "d['unread']" < "$WORK/b")"
HP=$(make_page claude_employee01 'C5D-넘길 페이지')
jbody "{'to': 'claude_employee02', 'keep': 'none'}"
expect "인계 → 받는 사람 알림 handover 1" "200|1" "$(capi POST "/api/pages/$HP/handover" claude_employee01 "$WORK/body.json")|$(notes_of claude_employee02 handover)"

# ════════════════════════════════════════════════════════════════
hr "C:T-102  공지 읽음 — 수만 · 쓴 사람·Admin 만"
capi GET "/api/pages/$NP" claude_employee01 > /dev/null; capi GET "/api/pages/$NP" claude_employee01 > /dev/null
capi GET "/api/pages/$NP" claude_employee02 > /dev/null; capi GET "/api/pages/$NP" claude_guest01 > /dev/null
expect "읽음 기록 — 같은 사람 두 번 → 한 줄 · 셋이 열어 세 줄" "3" \
  "$(psqlq "SELECT count(*) FROM community.page_reads r JOIN community.pages p ON p.id=r.page_id WHERE p.public_id='$NP'")"
AUD=$(psqlq "SELECT count(*) FROM users WHERE is_active AND username <> 'claude_admin01'")
expect "Admin(쓴 사람) GET reads → 200 · 3/활성 사용자 수(쓴 사람 뺌)" "200|3|$AUD" \
  "$(capi GET "/api/pages/$NP/reads" claude_admin01)|$(jget "d['reads']['read']" < "$WORK/b")|$(jget "d['reads']['total']" < "$WORK/b")"
lacks "  이름 목록은 없다 (결정 D-8 — 수만)" "claude_employee01" "$(cat "$WORK/b")"
expect "Employee GET reads → 403" "403" "$(capi GET "/api/pages/$NP/reads" claude_employee01)"
expect "공지가 아닌 글 reads → 400" "400" "$(capi GET "/api/pages/$PI/reads" claude_admin01)"
capi GET "/api/pages/$NP" claude_admin01 > /dev/null
expect "  페이지 창구 can_view_reads — Admin 참" "True" "$(jget "d['can_view_reads']" < "$WORK/b")"

# ════════════════════════════════════════════════════════════════
hr "C:T-103  게시 전 댓글은 게시판 독자에게 없다 (5-C #9 와 같은 자르기)"
PB=$(make_page claude_employee01 'C5D-게시할 페이지')
comment claude_employee01 "$PB" "{'body':'C5D-게시 전 비밀 메모'}" > /dev/null; OLD=$(cid)
sleep 1
publish claude_employee01 "$PB" "$B_FREE" > /dev/null
capi GET "/api/pages/$PB/comments" claude_employee02 > /dev/null
lacks "게시판 독자의 댓글 목록에 게시 전 댓글 없음" "C5D-게시 전 비밀 메모" "$(cat "$WORK/b")"
expect "  그 댓글에 직접 도움됐어요 → 404" "404" "$(capi POST "/api/comments/$OLD/helpful" claude_employee02)"
expect "게시 뒤 댓글은 보인다" "201" "$(comment claude_employee02 "$PB" "{'body':'C5D-게시 뒤 댓글'}")"
capi GET "/api/pages/$PB/comments" claude_employee01 > /dev/null
contains "  주인은 게시 전 댓글도 본다" "C5D-게시 전 비밀 메모" "$(cat "$WORK/b")"

# ════════════════════════════════════════════════════════════════
hr "C:T-104  기록 불변 · 권한"
for t in comments notifications page_reads; do
  expect "$t DELETE (tbox_app) → 42501" "42501" "$(appq "DELETE FROM community.$t" | sed -n 's/.*ERROR: *\([0-9A-Z]\{5\}\).*/\1/p')"
done
expect "comment_reactions 는 DELETE 가 있다(누르고 빼기) — 권한 문자열에 d" "yes" \
  "$(psqlq "SELECT array_to_string(relacl,' ') FROM pg_class WHERE oid='community.comment_reactions'::regclass" | grep -q 'tbox_app=ard' && echo yes || echo no)"

# ════════════════════════════════════════════════════════════════
hr "C:T-105  브라우저 — browser_5d.py"
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
  SMOKE_E1="$(cookie_of claude_employee01)" SMOKE_E2="$(cookie_of claude_employee02)" SMOKE_ADMIN="$(cookie_of claude_admin01)" \
  SMOKE_PI="$PI" SMOKE_NP="$NP" SMOKE_SHOTS="${SHOTS:-}" \
    python3 "$ROOT/tests/community/browser_5d.py" "$SITE" "$UA" > "$WORK/smoke.txt" 2>&1
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
bash -n "$ROOT/tests/community/stage5d.sh" && expect "stage5d.sh 문법" "0" "0"
python3 -c "import ast,io;ast.parse(io.open('$ROOT/tests/community/browser_5d.py',encoding='utf-8').read())" \
  && expect "browser_5d.py 문법" "0" "0"
expect "커뮤니티 코드에 역할 문자열 비교 0" "0" \
  "$(grep -rnE "'(Admin|Manager|Employee|Guest)'" "$ROOT/src/community" | grep -vE '^[^:]+:[0-9]+:\s*(\*|//|/\*)' | wc -l | tr -d ' ')"
expect "창구(JSON) 코드가 「이름 (아이디)」를 이어 붙이지 않는다 — tbox_person_label 을 부르는 곳은 서버 화면 두 파일(셸·런처) + 7-D4 멘션 이름 지도(routes.php — 지시서 M-4 · 두 렌더러가 같은 글자)뿐" "src/community/routes.php src/views/launcher.php src/views/shell_top.php" \
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
  for s in ${REGRESS_LIST:-community/stage3 community/stage4a community/stage4b community/stage4c community/stage4d community/stage5a community/stage5a2 community/stage5c stage26 help_button api_whoami api_read api_folders api_upload api_tokens f65 f66 f68}; do
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

