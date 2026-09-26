#!/usr/bin/env bash
# tests/community/stage5c.sh — 5-C 수락 기준 C:T-80 ~ C:T-90 실행
#                              (../../../community/stage5c-board-publish-directive.md 「완료 기준」)
#
#   C:T-80 게시판 노출    Guest·다른 팀·Employee 로 `GET /api/boards`·게시판 목록·글 GET — 못 보는 것은 응답에
#                         없다(원문 grep) · 주소 직접 403 · 없는 번호도 403 · 공지 새 글 Employee 403(+감사) · 비활성 400 ·
#                         게시판 관리: Admin 재인증 없이 403 · Employee 403 · 등급 바꾸기 400 · 같은 이름(대소문자) 409
#   C:T-81 새 글 소유     쓴 사람 PATCH 200 · 남·같은 팀 Manager·Admin PATCH 403(+감사 not_author) · 삭제는 쓴 사람만 ·
#                         게시판 글 아래 하위 400 · 공유 400
#   C:T-82 게시           비공개 페이지를 internal 게시판에 → 다른 사원 GET 200·목록에 보임 · 원본 고치기가 그대로 ·
#                         해제 → 403(원래 범위 복원) · 못 쓰는 게시판 403(+감사) · 남이 게시 403 · 게시 중 범위 400 ·
#                         이미 게시 400 · 처리 이력 publish 두 줄
#   C:T-83 숨김           Manager(그 팀)·Admin 200, 쓴 사람이 아닌 Employee 403(+감사), 다른 팀 게시판의 Manager 403 ·
#                         숨긴 뒤 목록·검색에 없음 · 원본 무변경(doc_version) · 감사 · 주소로는 쓴 사람·관리자만 ·
#                         게시된 페이지 숨김 → 원래 범위로 · 관리자의 게시 해제
#   C:T-84 태그           11개 400 · 31자 400 · 제어 문자 400 · 대소문자 같은 태그 한 번 · 자동완성은 볼 수 있는 글만
#                         (남의 비공개·숨긴 글 태그가 새지 않는다) · 남이 태그 403 · 태그 거르개
#   C:T-85 조회수         같은 사람 하루 여러 번 → 1 · 다른 사람 → 2 · 목록 `views` · 누가 봤는지는 응답에 없다
#   C:T-86 검색(5-B)      게시판 글이 등급대로 · 숨긴 글은 검색에 없다(쓴 사람·관리자에게도)
#   C:T-87 판정 = SQL     전 계정 × 전 페이지 전수 대조(게시판 글·게시·숨김 포함) 어긋남 0
#   C:T-88 기록 불변      `page_views` UPDATE·DELETE 42501 · `page_property_events` 에 publish·hide·resolved
#   C:T-89 해결됨         질문답변 글의 쓴 사람 200 · 남 403 · 질문답변이 아니면 400
#   C:T-90 브라우저       browser_5c.py — 게시판 화면·새 글·게시·태그 입력·숨기기, 라이트·다크·390px 스크린샷
#   회귀 (REGRESS=1)     같은 임시 인스턴스에서 커뮤니티 3·4a~4d·5a·5a2 와 t-box stage26·help_button …
#
# ══════════════════════════════════════════════════════════════════════════
# ★ 이 시험은 **운영 DB 를 한 글자도 건드리지 않는다.** (stage5a2.sh 와 같은 방식)
#   ① 운영 DB(`tbox_re`)를 **읽어서**(pg_dump) 임시 DB `tbox_5c_test2` 를 만들고
#   ② 거기에만 5-C 마이그레이션(`db/migrations/2026-09-24_community_boards.sql`)을 두 번 적용하고
#   ③ 저장소를 임시 폴더로 복사해 자기 `.env`(DB 이름·저장 경로·로그 경로만 다르다)를 주고
#   ④ `php -S 127.0.0.1:8087` 로 두 번째 인스턴스를 띄워 그쪽에만 요청을 보낸다.
#   ⑤ 끝나면 서버를 내리고 임시 DB 와 임시 폴더를 **지운다**.
# ══════════════════════════════════════════════════════════════════════════
#
# 규칙 (CLAUDE.md 3장) — 검토 계정 `claude_` 다섯뿐(로그인은 순차) · 모든 요청에 `-A "tbox-review/<날짜>"` ·
#   `sudo -n` 은 읽기(pg_dump · seed 파일)와 임시 DB 에만. 만드는 것은 전부 `C5-` 접두.
# 사용: sudo -v && bash tests/community/stage5c.sh     (REGRESS=1 이면 회귀까지, SHOTS=<폴더> 면 스크린샷)
set -u

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
. "$ROOT/tests/lib/secrets.sh" || { echo "tests/lib/secrets.sh 를 읽지 못했다 — 시험을 시작하지 않는다 (R-62)"; exit 3; }
PORT=8087
SITE="http://127.0.0.1:$PORT"
BASE="$SITE/tbox"
CBASE="$SITE/community"
UA="tbox-review/$(date +%F)"
SRC_DB="tbox_re"          # 운영 — **읽기만** 한다
DB="tbox_5c_test2"         # 임시 — 여기에만 쓴다
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
hr "5-C — 임시 인스턴스 준비 (운영 DB 에는 쓰지 않는다)"

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

sub "② 마이그레이션 + 권한 (임시 DB 에만) — 앞 단계(이미 있으면 그대로) · **5-C 게시판을 두 번** 돌려 멱등을 본다"
for mig in 2026-09-23_community_page_properties.sql 2026-09-23_community_owner_share.sql \
           2026-09-23_users_display_name.sql 2026-09-24_community_boards.sql 2026-09-24_community_boards.sql \
           2026-09-24_community_discussion.sql 2026-09-25_community_databases.sql 2026-09-26_community_purge.sql 2026-09-27_community_templates.sql 2026-09-28_community_embeds.sql; do   # 5-D — 5-D 코드가 댓글·알림 표를 읽는다
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

B_NOTICE=$(bid '공지사항'); B_FREE=$(bid '자유게시판'); B_QA=$(bid '질문과 답변')
note "시드 게시판: 공지사항=$B_NOTICE · 자유게시판=$B_FREE · 질문과 답변=$B_QA"
expect "시드 게시판이 셋 (마이그레이션 두 번 — 멱등)" "3" "$(psqlq "SELECT count(*) FROM community.spaces WHERE kind='board'")"
expect "게시판 모양 CHECK — 종류 없는 게시판은 DB 가 막는다 (23514)" "23514" \
  "$(sudo -n -u postgres psql -d "$DB" -v VERBOSITY=verbose -t -A -c "INSERT INTO community.spaces (kind,name,access_level) VALUES ('board','C5-x','internal')" 2>&1 | sed -n 's/.*ERROR: *\([0-9A-Z]\{5\}\).*/\1/p' | head -1)"
expect "게시 모양 CHECK — 게시판 없이 published 는 DB 가 막는다 (23514)" "23514" \
  "$(sudo -n -u postgres psql -d "$DB" -v VERBOSITY=verbose -t -A -c "UPDATE community.pages SET visibility='published' WHERE id=(SELECT min(id) FROM community.pages)" 2>&1 | sed -n 's/.*ERROR: *\([0-9A-Z]\{5\}\).*/\1/p' | head -1)"

# ════════════════════════════════════════════════════════════════
hr "C:T-80  게시판 노출 · 쓰기 자격 · 게시판 관리"

sub "게시판 관리 — 재인증 · 자격 · 등급 고정 · 이름 중복"
jbody "{'name': 'C5-팀게시판', 'access_level': 'team', 'team_id': $TEAM1, 'board_kind': 'discussion', 'description': '5-C 시험'}"
expect "Admin 이 재인증 없이 게시판 만들기 → 403 next=reauth" "403|reauth" \
  "$(capi POST /api/boards claude_admin01 "$WORK/body.json")|$(jget "d.get('next','')" < "$WORK/b")"
expect "Employee 가 게시판 만들기 → 403 (라우트 정책 role:Admin)" "403" "$(capi POST /api/boards claude_employee01 "$WORK/body.json")"
expect "Admin 재인증 (t-box 창구) → 200" "200" "$(reauth_admin)"
expect "Admin 이 팀 게시판(팀 $TEAM1) 만들기 → 201" "201" "$(capi POST /api/boards claude_admin01 "$WORK/body.json")"
B_TEAM=$(jget "d['board']['id']" < "$WORK/b")
jbody "{'name': 'C5-관리자게시판', 'access_level': 'admin-only', 'board_kind': 'discussion'}"
expect "Admin 이 관리자 게시판 만들기 → 201" "201" "$(capi POST /api/boards claude_admin01 "$WORK/body.json")"
B_ADM=$(jget "d['board']['id']" < "$WORK/b")
jbody "{'name': 'C5-보관게시판', 'access_level': 'internal', 'board_kind': 'qa'}"
capi POST /api/boards claude_admin01 "$WORK/body.json" > /dev/null
B_OLD=$(jget "d['board']['id']" < "$WORK/b")
jbody "{'name': 'c5-팀게시판', 'access_level': 'internal', 'board_kind': 'discussion'}"
# 실행 경계(BF-0009) — 아래 「deny conflict 1」 이 기대하는 감사 행은 바로 다음 409 요청이 남긴다. 그 앞에서 잰다
# (임시 DB 는 운영 사본이라 운영의 옛 conflict 행이 섞일 수 있다).
CONFLICT_MARK=$(mark)
expect "같은 이름(대소문자만 다름) → 409" "409" "$(capi POST /api/boards claude_admin01 "$WORK/body.json")"
jbody "{'name': 'C5-y', 'access_level': 'team', 'board_kind': 'discussion'}"
expect "팀 등급인데 팀이 없음 → 400" "400" "$(capi POST /api/boards claude_admin01 "$WORK/body.json")"
jbody "{'name': 'C5-y', 'access_level': 'internal', 'board_kind': 'wiki'}"
expect "모르는 종류 → 400" "400" "$(capi POST /api/boards claude_admin01 "$WORK/body.json")"
jbody "{'name': 'C5-y', 'access_level': 'internal', 'board_kind': 'discussion', 'role': 'Admin'}"
expect "허용 키 밖 → 400" "400" "$(capi POST /api/boards claude_admin01 "$WORK/body.json")"
jbody "{'access_level': 'public'}"
expect "게시판 등급 바꾸기(PATCH access_level) → 400 (만든 뒤 고정)" "400" "$(capi PATCH "/api/boards/$B_TEAM" claude_admin01 "$WORK/body.json")"
jbody "{'is_active': False}"
expect "보관(비활성)으로 → 200" "200" "$(capi PATCH "/api/boards/$B_OLD" claude_admin01 "$WORK/body.json")"
expect "  감사 board.create allow 3 · board.update allow 1" "3|1" \
  "$(psqlq "SELECT count(*) FROM audit_logs WHERE action='board.create' AND result='allow' AND target_name LIKE 'C5-%'")|$(psqlq "SELECT count(*) FROM audit_logs WHERE action='board.update' AND result='allow' AND target_name LIKE 'C5-%'")"
expect "  감사 board.create deny conflict 1" "1" "$(psqlq "SELECT count(*) FROM audit_logs WHERE id > ${CONFLICT_MARK:-0} AND action='board.create' AND reason='conflict'")"

sub "목록 — 못 보는 게시판은 응답에 없다 (원문 grep)"
capi GET /api/boards claude_guest01 > /dev/null; G=$(cat "$WORK/b")
contains "Guest — 공지사항(public) 보임" "공지사항" "$G"
lacks "Guest — 자유게시판(internal) 없음" "자유게시판" "$G"
lacks "Guest — 팀 게시판 없음" "C5-팀게시판" "$G"
lacks "Guest — 관리자 게시판 없음" "C5-관리자" "$G"
lacks "Guest — 팀 게시판 번호도 없음" "\"id\":$B_TEAM," "$G"
capi GET /api/boards claude_employee02 > /dev/null; E2=$(cat "$WORK/b")
contains "다른 팀 Employee — 자유게시판 보임" "자유게시판" "$E2"
lacks "다른 팀 Employee — 팀 $TEAM1 게시판 없음" "C5-팀게시판" "$E2"
lacks "Employee — 관리자 게시판 없음" "C5-관리자" "$E2"
contains "Employee — 보관 게시판은 보인다(읽기 전용)" "C5-보관게시판" "$E2"
capi GET /api/boards claude_employee01 > /dev/null
contains "같은 팀 Employee — 팀 게시판 보임" "C5-팀게시판" "$(cat "$WORK/b")"
capi GET /api/boards claude_admin01 > /dev/null
contains "Admin — 관리자 게시판 보임" "C5-관리자게시판" "$(cat "$WORK/b")"
expect "  목록 창구의 can_manage — Admin 참 · Employee 거짓" "True|False" \
  "$(jget "d['can_manage']" < "$WORK/b")|$(capi GET /api/boards claude_employee01 >/dev/null; jget "d['can_manage']" < "$WORK/b")"

sub "주소 직접 — 403 동일 문구"
expect "다른 팀 Employee → 팀 게시판 목록 403" "403" "$(capi GET "/api/boards/$B_TEAM/pages" claude_employee02)"
expect "Guest → 자유게시판 목록 403" "403" "$(capi GET "/api/boards/$B_FREE/pages" claude_guest01)"
expect "Employee → 관리자 게시판 목록 403" "403" "$(capi GET "/api/boards/$B_ADM/pages" claude_employee01)"
expect "없는 번호 → 403 (404 로 가르지 않는다)" "403" "$(capi GET "/api/boards/999999/pages" claude_employee01)"
expect "Guest → 공지사항 목록 200" "200" "$(capi GET "/api/boards/$B_NOTICE/pages" claude_guest01)"
expect "게시판 화면 /b/{num} 은 셸만 — 200 (자료는 창구가 준다)" "200" "$(page_html "/community/b/$B_TEAM" claude_employee02)"
lacks "  못 보는 게시판 화면 HTML 에 그 이름이 없다" "C5-팀게시판" "$(cat "$WORK/html")"

sub "보관 게시판은 읽기 전용 — 쓴 사람의 지우기·고치기도 막힌다 (독립 검토)"
jbody "{'is_active': True}"; capi PATCH "/api/boards/$B_OLD" claude_admin01 "$WORK/body.json" > /dev/null
OP=$(new_post claude_employee01 "$B_OLD" 'C5-보관될 글')
jbody "{'is_active': False}"; capi PATCH "/api/boards/$B_OLD" claude_admin01 "$WORK/body.json" > /dev/null
expect "보관 뒤 쓴 사람 PATCH → 403" "403" "$(patch_title claude_employee01 "$OP" 'C5-보관 뒤 고침')"
expect "보관 뒤 쓴 사람 DELETE → 403" "403" "$(capi DELETE "/api/pages/$OP" claude_employee01)"
expect "보관 뒤 해결됨 → 403" "403" "$(capi PATCH "/api/pages/$OP/properties" claude_employee01 <(printf '{"resolved":true}'))"
expect "보관 뒤에도 읽기 → 200" "200" "$(capi GET "/api/pages/$OP" claude_employee02)"

sub "새 글 쓰기 자격"
A0=$(mark)
expect "Employee → 공지사항 새 글 403" "403" "$(capi POST "/api/boards/$B_NOTICE/pages" claude_employee01 <(printf '{"title":"C5-공지 시도"}'))"
expect "  감사 page.create deny role_not_permitted 1" "1" "$(audit_n "$A0" page.create deny role_not_permitted)"
expect "Guest → 공지사항 새 글 403 (라우트 정책 community.write)" "403" "$(capi POST "/api/boards/$B_NOTICE/pages" claude_guest01 <(printf '{"title":"C5-x"}'))"
expect "다른 팀 Employee → 팀 게시판 새 글 403" "403" "$(capi POST "/api/boards/$B_TEAM/pages" claude_employee02 <(printf '{"title":"C5-x"}'))"
expect "Employee → 보관 게시판 새 글 400 board_inactive" "400|board_inactive" \
  "$(capi POST "/api/boards/$B_OLD/pages" claude_employee01 <(printf '{"title":"C5-x"}'))|$(jget "d.get('reason','')" < "$WORK/b")"
NP=$(new_post claude_admin01 "$B_NOTICE" 'C5-공지 하나')
expect "Admin → 공지사항 새 글 201" "201" "$(cat "$WORK/st")"
FP1=$(new_post claude_employee01 "$B_FREE" 'C5-자유 첫 글' "['안내','Alpha']")
expect "Employee → 자유게시판 새 글 201" "201" "$(cat "$WORK/st")"
TP1=$(new_post claude_employee01 "$B_TEAM" 'C5-팀 글 비밀제목')
expect "같은 팀 Employee → 팀 게시판 새 글 201" "201" "$(cat "$WORK/st")"
expect "  새 글의 쓴 사람 = author_id (claude_employee01)" "claude_employee01" \
  "$(psqlq "SELECT u.username FROM community.pages p JOIN users u ON u.id=p.author_id WHERE p.public_id='$FP1'")"
expect "  새 글은 게시판 공간에 있다" "board" "$(psqlq "SELECT s.kind FROM community.pages p JOIN community.spaces s ON s.id=p.space_id WHERE p.public_id='$FP1'")"

sub "글 읽기 — 못 보는 글은 403"
expect "다른 팀 Employee → 팀 게시판 글 GET 403" "403" "$(capi GET "/api/pages/$TP1" claude_employee02)"
expect "Guest → 자유게시판 글 GET 403" "403" "$(capi GET "/api/pages/$FP1" claude_guest01)"
expect "Guest → 공지 글 GET 200" "200" "$(capi GET "/api/pages/$NP" claude_guest01)"
expect "다른 팀 Employee → 자유게시판 글 GET 200 · board.kind=post" "200|post" \
  "$(capi GET "/api/pages/$FP1" claude_employee02)|$(jget "d['board']['kind']" < "$WORK/b")"
capi GET "/api/boards/$B_TEAM/pages" claude_employee01 > /dev/null
contains "같은 팀 — 팀 게시판 목록에 그 글" "C5-팀 글 비밀제목" "$(cat "$WORK/b")"
capi GET "/api/boards/$B_FREE/pages" claude_employee02 > /dev/null
lacks "다른 팀 — 자유게시판 목록에 팀 글 제목이 섞이지 않는다" "비밀제목" "$(cat "$WORK/b")"

# ════════════════════════════════════════════════════════════════
hr "C:T-81  새 글 — 고치기·지우기는 쓴 사람만"
A1=$(mark)
expect "쓴 사람 PATCH → 200" "200" "$(patch_title claude_employee01 "$FP1" 'C5-자유 첫 글 (고침)')"
expect "남(다른 Employee) PATCH → 403" "403" "$(patch_title claude_employee02 "$FP1" 'C5-탈취')"
expect "같은 팀 Manager 가 팀 게시판 글 PATCH → 403" "403" "$(patch_title claude_manager01 "$TP1" 'C5-탈취')"
expect "Admin PATCH → 403 (관리자도 내용은 못 고친다)" "403" "$(patch_title claude_admin01 "$FP1" 'C5-탈취')"
expect "  감사 page.update deny not_author 3" "3" "$(audit_n "$A1" page.update deny not_author)"
expect "  제목 무변경" "C5-자유 첫 글 (고침)" "$(pg_page "$FP1" "p.title")"
DP=$(new_post claude_employee01 "$B_FREE" 'C5-지울 글')
expect "남이 DELETE → 403" "403" "$(capi DELETE "/api/pages/$DP" claude_employee02)"
expect "Admin 이 DELETE → 403 (숨김은 된다 — C:T-83)" "403" "$(capi DELETE "/api/pages/$DP" claude_admin01)"
expect "  감사 page.delete deny not_author 2" "2" "$(audit_n "$A1" page.delete deny not_author)"
expect "쓴 사람 DELETE → 200" "200" "$(capi DELETE "/api/pages/$DP" claude_employee01)"
jbody "{'title': 'C5-하위', 'parent_id': '$FP1'}"
expect "게시판 글 아래 하위 페이지 → 400 space_governed" "400|space_governed" \
  "$(capi POST /api/pages claude_employee01 "$WORK/body.json")|$(jget "d.get('reason','')" < "$WORK/b")"
jbody "{'title': 'C5-게시판에 직접', 'space_id': $B_FREE}"
expect "개인 창구로 게시판 공간에 쓰기 → 400" "400" "$(capi POST /api/pages claude_employee01 "$WORK/body.json")"
expect "게시판 글 공유 → 400 space_governed" "400" "$(share claude_employee01 "$FP1" claude_employee02 edit)"
expect "게시판 글 옮기기·공개 범위·넘기기 → 거부(403·400)" "yes" \
  "$(a=$(capi PATCH "/api/pages/$FP1/move" claude_employee01 <(printf '{"parent_id":null,"position":0}')); b=$(capi PATCH "/api/pages/$FP1/visibility" claude_employee01 <(printf '{"visibility":"internal"}')); c=$(capi POST "/api/pages/$FP1/handover" claude_employee01 <(printf '{"to":"claude_employee02","keep":"none"}')); [ "$a" != 200 ] && [ "$b" != 200 ] && [ "$c" != 200 ] && echo yes || echo "no $a $b $c")"

# ════════════════════════════════════════════════════════════════
hr "C:T-82  게시 — 개인 페이지를 게시판에 건다"
P1=$(make_page claude_employee01 'C5-개인 비공개 글')
expect "게시 전 다른 사원 GET 403 (비공개)" "403" "$(capi GET "/api/pages/$P1" claude_employee02)"
A2=$(mark)
expect "못 쓰는 게시판(공지)에 게시 → 403" "403" "$(publish claude_employee01 "$P1" "$B_NOTICE")"
expect "  감사 page.publish deny role_not_permitted 1" "1" "$(audit_n "$A2" page.publish deny role_not_permitted)"
expect "못 보는 게시판(관리자)에 게시 → 403" "403" "$(publish claude_employee01 "$P1" "$B_ADM")"
expect "없는 게시판에 게시 → 403" "403" "$(publish claude_employee01 "$P1" 999999)"
expect "보관 게시판에 게시 → 400" "400" "$(publish claude_employee01 "$P1" "$B_OLD")"
expect "남(볼 수 없는 사람)이 게시 → 403" "403" "$(publish claude_employee02 "$P1" "$B_FREE")"
share claude_employee01 "$P1" claude_employee02 edit > /dev/null
expect "편집 공유자가 게시 → 403 (게시는 주인만)" "403" "$(publish claude_employee02 "$P1" "$B_FREE")"
expect "  감사 page.publish deny not_owner 1" "1" "$(audit_n "$A2" page.publish deny not_owner)"
unshare claude_employee01 "$P1" claude_employee02 > /dev/null
# 실행 경계(BF-0009) — C:T-88 「처리 이력에 publish·hide·resolved」 가 기대하는 줄 가운데 이 실행의 **첫 줄**은 바로 다음
# 게시(200)가 남긴다(위의 게시 시도는 전부 거부라 줄이 없다 · hide 는 C:T-83 · resolved 는 C:T-89 — 모두 이 뒤). 그 앞에서 잰다.
PPE_MARK=$(psqlq "SELECT coalesce(max(id),0) FROM community.page_property_events")
expect "주인이 자유게시판(internal)에 게시 → 200" "200" "$(publish claude_employee01 "$P1" "$B_FREE")"
expect "  DB: published · 원래 범위 private · 게시판 번호" "published|private|$B_FREE" \
  "$(pg_page "$P1" "p.visibility||'|'||p.published_from||'|'||p.published_board_id")"
expect "다른 사원 GET → 200 (게시판 등급으로)" "200|board" \
  "$(capi GET "/api/pages/$P1" claude_employee02)|$(jget "d['granted_by']" < "$WORK/b")"
capi GET "/api/boards/$B_FREE/pages" claude_employee02 > /dev/null
contains "  자유게시판 목록에 보임 · kind=published" "C5-개인 비공개 글" "$(cat "$WORK/b")"
contains "  kind published" '"kind":"published"' "$(cat "$WORK/b")"
expect "Guest GET → 403 (internal 게시판)" "403" "$(capi GET "/api/pages/$P1" claude_guest01)"
expect "이미 게시 중 → 400 already_published" "400|already_published" \
  "$(publish claude_employee01 "$P1" "$B_QA")|$(jget "d.get('reason','')" < "$WORK/b")"
expect "게시 중 공개 범위 바꾸기 → 400 published" "400|published" \
  "$(capi PATCH "/api/pages/$P1/visibility" claude_employee01 <(printf '{"visibility":"team"}'))|$(jget "d.get('reason','')" < "$WORK/b")"
expect "게시 중 원본 고치기 → 200" "200" "$(patch_title claude_employee01 "$P1" 'C5-개인 비공개 글 (고친 제목)')"
capi GET "/api/boards/$B_FREE/pages" claude_employee02 > /dev/null
contains "  게시판 목록에 고친 제목이 그대로 (한 벌)" "C5-개인 비공개 글 (고친 제목)" "$(cat "$WORK/b")"
share claude_employee01 "$P1" claude_manager01 view > /dev/null
capi GET "/api/pages/$P1" claude_manager01 > /dev/null
expect "공유로 보는 사람에게 게시판 칸이 실린다(그 게시판을 읽을 수 있으므로)" "자유게시판" "$(jget "d['board']['name']" < "$WORK/b")"
unshare claude_employee01 "$P1" claude_manager01 > /dev/null
CH=$(make_page claude_employee01 'C5-게시글의 하위' "$P1")
expect "게시된 페이지의 하위는 기준 범위(private)로 시작 — 다른 사원 GET 403" "private|403" \
  "$(pg_page "$CH" "p.visibility")|$(capi GET "/api/pages/$CH" claude_employee02)"
expect "남(다른 사원)이 게시 해제 → 403" "403" "$(capi DELETE "/api/pages/$P1/publish" claude_employee02)"
expect "  감사 page.unpublish deny not_owner 1" "1" "$(audit_n "$A2" page.unpublish deny not_owner)"
expect "주인이 게시 해제 → 200" "200" "$(capi DELETE "/api/pages/$P1/publish" claude_employee01)"
expect "  DB: private 으로 복원 · 게시 칸 비움" "private||" \
  "$(pg_page "$P1" "p.visibility||'|'||coalesce(p.published_from,'')||'|'||coalesce(p.published_board_id::text,'')")"
expect "해제 뒤 다른 사원 GET → 403 (원래 범위 복원)" "403" "$(capi GET "/api/pages/$P1" claude_employee02)"
capi GET "/api/boards/$B_FREE/pages" claude_employee02 > /dev/null
lacks "  게시판 목록에서 사라짐" "C5-개인 비공개 글" "$(cat "$WORK/b")"
expect "해제 뒤 다시 해제 → 400 not_published" "400" "$(capi DELETE "/api/pages/$P1/publish" claude_employee01)"
expect "처리 이력 — publish 두 줄(게시·해제)" "2" \
  "$(psqlq "SELECT count(*) FROM community.page_property_events e JOIN community.pages p ON p.id=e.page_id WHERE p.public_id='$P1' AND e.prop='publish'")"
capi GET "/api/pages/$P1/history" claude_employee01 > /dev/null
contains "  「처리 이력」 창구에 publish 줄" '"prop":"publish"' "$(cat "$WORK/b")"
sub "게시 전 판은 게시판 독자에게 보이지 않는다 (USER-CHECKS 5-C #9 — 기본안: 게시 뒤 판만)"
P5=$(make_page claude_employee01 'C5-초안 있는 글')
patch_title claude_employee01 "$P5" 'C5-초안 비밀 제목' > /dev/null
expect "  게시 전 판 스냅샷이 있다" "1" "$(psqlq "SELECT count(*) FROM community.page_versions v JOIN community.pages p ON p.id=v.page_id WHERE p.public_id='$P5'")"
publish claude_employee01 "$P5" "$B_FREE" > /dev/null
capi GET "/api/pages/$P5/versions" claude_employee02 > /dev/null
lacks "  게시판 독자의 판 목록에 게시 전 판(초안 제목) 없음" "C5-초안 있는 글" "$(cat "$WORK/b")"
expect "  게시판 독자가 게시 전 판 직접 → 404" "404" "$(capi GET "/api/pages/$P5/versions/1" claude_employee02)"
capi GET "/api/pages/$P5/history" claude_employee02 > /dev/null
lacks "  처리 이력에도 게시 전 판(판 1) 없음 — 지금 판 줄만" '"version":1,' "$(cat "$WORK/b")"
expect "  주인은 게시 전 판을 본다 → 200" "200" "$(capi GET "/api/pages/$P5/versions/1" claude_employee01)"
capi DELETE "/api/pages/$P5/publish" claude_employee01 > /dev/null
TPP=$(make_page claude_employee01 'C5-팀공간 글' '' "$(psqlq "SELECT id FROM community.spaces WHERE kind='team' AND team_id=$TEAM1")")
expect "팀 공간 페이지 게시 → 400 space_governed" "400" "$(publish claude_employee01 "$TPP" "$B_FREE")"

# ════════════════════════════════════════════════════════════════
hr "C:T-83  숨김 — 게시판 관리자만, 원본 무변경"
TP2=$(new_post claude_employee01 "$B_TEAM" 'C5-팀 숨길 글' "['숨김태그Z']")
V_TP2=$(pg_page "$TP2" "p.doc_version")
A3=$(mark)
expect "쓴 사람(관리자가 아님)이 자기 글 숨기기 → 403" "403" "$(capi POST "/api/pages/$TP2/hide" claude_employee01)"
expect "쓴 사람이 아닌 Employee 가 남의 글 숨기기 → 403" "403" "$(capi POST "/api/pages/$FP1/hide" claude_employee02)"
expect "  감사 page.hide deny not_owner ≥1" "yes" "$([ "$(audit_n "$A3" page.hide deny not_owner)" -ge 1 ] && echo yes || echo no)"
expect "Manager 가 다른 게시판(internal 자유게시판) 글 숨기기 → 403" "403" "$(capi POST "/api/pages/$FP1/hide" claude_manager01)"
expect "Manager(그 팀)가 팀 게시판 글 숨기기 → 200 team_scope" "200|team_scope" \
  "$(capi POST "/api/pages/$TP2/hide" claude_manager01)|$(jget "d['granted_by']" < "$WORK/b")"
expect "  감사 page.hide allow 1" "1" "$(audit_n "$A3" page.hide allow '')"
expect "  원본 무변경 (doc_version 그대로)" "$V_TP2" "$(pg_page "$TP2" "p.doc_version")"
capi GET "/api/boards/$B_TEAM/pages" claude_employee01 > /dev/null
lacks "  숨긴 뒤 목록에 없다 (쓴 사람에게도)" "C5-팀 숨길 글" "$(cat "$WORK/b")"
expect "  주소로 — 쓴 사람 200" "200" "$(capi GET "/api/pages/$TP2" claude_employee01)"
expect "  주소로 — 게시판 관리자(Manager) 200 board_moderator" "200|board_moderator" \
  "$(capi GET "/api/pages/$TP2" claude_manager01)|$(jget "d['granted_by']" < "$WORK/b")"
expect "  주소로 — Admin 200" "200" "$(capi GET "/api/pages/$TP2" claude_admin01)"
expect "Admin 이 자유게시판 글 숨기기 → 200 admin_all" "200|admin_all" \
  "$(capi POST "/api/pages/$FP1/hide" claude_admin01)|$(jget "d['granted_by']" < "$WORK/b")"
expect "  숨긴 자유게시판 글 — 다른 사원 GET 403" "403" "$(capi GET "/api/pages/$FP1" claude_employee02)"
expect "  다시 숨기기 → 200 changed=false (기록 늘지 않음)" "200|False" \
  "$(capi POST "/api/pages/$FP1/hide" claude_admin01)|$(jget "d['changed']" < "$WORK/b")"
expect "숨김 풀기 → 200 · 다른 사원 다시 200" "200|200" \
  "$(capi DELETE "/api/pages/$FP1/hide" claude_admin01)|$(capi GET "/api/pages/$FP1" claude_employee02)"
expect "개인(게시 안 된) 페이지 숨기기 → 400" "400" "$(capi POST "/api/pages/$P1/hide" claude_admin01)"
sub "게시된 개인 페이지의 숨김 · 관리자의 게시 해제"
publish claude_employee01 "$P1" "$B_FREE" > /dev/null
expect "Admin 이 게시된 페이지 숨기기 → 200" "200" "$(capi POST "/api/pages/$P1/hide" claude_admin01)"
expect "  숨긴 뒤 다른 사원 GET 403 (원래 범위 private)" "403" "$(capi GET "/api/pages/$P1" claude_employee02)"
expect "  주인은 200" "200" "$(capi GET "/api/pages/$P1" claude_employee01)"
capi GET "/api/boards/$B_FREE/pages" claude_admin01 > /dev/null
lacks "  게시판 목록에 없다 (관리자에게도)" "C5-개인 비공개 글" "$(cat "$WORK/b")"
expect "Admin 이 게시 해제 → 200 admin_all" "200|admin_all" \
  "$(capi DELETE "/api/pages/$P1/publish" claude_admin01)|$(jget "d['granted_by']" < "$WORK/b")"
expect "  해제해도 숨김은 남는다 · 범위 private (독립 검토 — 해제→재게시로 숨김 우회 금지)" "private|true" "$(pg_page "$P1" "p.visibility||'|'||(p.hidden_at IS NOT NULL)")"
expect "주인이 다시 게시 → 200 · 그래도 숨김 그대로" "200|t" "$(publish claude_employee01 "$P1" "$B_FREE")|$(pg_page "$P1" "(p.hidden_at IS NOT NULL)")"
capi GET "/api/boards/$B_FREE/pages" claude_employee02 > /dev/null
lacks "  재게시해도 게시판 목록에 없다" "C5-개인 비공개 글" "$(cat "$WORK/b")"
expect "  숨김 풀기(관리자) → 목록에 보인다 · 정리로 다시 해제" "200|200" \
  "$(capi DELETE "/api/pages/$P1/hide" claude_admin01)|$(capi DELETE "/api/pages/$P1/publish" claude_employee01)"

# ════════════════════════════════════════════════════════════════
hr "C:T-84  태그"
expect "11개 → 400" "400" "$(put_tags claude_employee01 "$FP1" "['t%d'%i for i in range(11)]")"
expect "31자 → 400" "400" "$(put_tags claude_employee01 "$FP1" "['가'*31]")"
expect "30자 → 200" "200" "$(put_tags claude_employee01 "$FP1" "['가'*30]")"
expect "제어 문자 → 400" "400" "$(put_tags claude_employee01 "$FP1" "['a\\u0007b']")"
expect "줄바꿈 → 400" "400" "$(put_tags claude_employee01 "$FP1" "['a\\nb']")"
expect "빈 태그(공백만) → 400" "400" "$(put_tags claude_employee01 "$FP1" "['   ']")"
expect "목록 아님 → 400" "400" "$(put_tags claude_employee01 "$FP1" "'abc'")"
expect "대소문자 같은 태그 셋 → 200 · 한 번만" "200|1" \
  "$(put_tags claude_employee01 "$FP1" "['Alpha','alpha','ALPHA',' 공개태그Y ']")|$(psqlq "SELECT count(*) FROM community.page_tags t JOIN community.pages p ON p.id=t.page_id WHERE p.public_id='$FP1' AND t.tag_norm='alpha'")"
expect "  앞뒤 공백 제거" "1" "$(psqlq "SELECT count(*) FROM community.page_tags t JOIN community.pages p ON p.id=t.page_id WHERE p.public_id='$FP1' AND t.tag='공개태그Y'")"
A4=$(mark)
expect "남이 태그 바꾸기 → 403" "403" "$(put_tags claude_employee02 "$FP1" "['탈취']")"
expect "  감사 page.tags deny not_owner 1" "1" "$(audit_n "$A4" page.tags deny not_owner)"
expect "Admin(게시판 관리자)도 태그 → 403 (내용 고치기와 같은 자격)" "403" "$(put_tags claude_admin01 "$FP1" "['탈취']")"
P2=$(make_page claude_employee01 'C5-비밀 태그 페이지')
put_tags claude_employee01 "$P2" "['비밀태그X']" > /dev/null
HP=$(new_post claude_employee01 "$B_FREE" 'C5-숨길 태그 글' "['숨김태그W']")
capi POST "/api/pages/$HP/hide" claude_admin01 > /dev/null
capi GET "/api/boards/$B_FREE/tags?q=%ED%83%9C%EA%B7%B8" claude_employee02 > /dev/null; T=$(cat "$WORK/b")
contains "자동완성(q=태그) — 게시판 글의 태그" "공개태그Y" "$T"
lacks "  남의 비공개 페이지 태그는 없다" "비밀태그X" "$T"
lacks "  숨긴 글의 태그는 없다" "숨김태그W" "$T"
capi GET "/api/boards/$B_FREE/tags" claude_employee02 > /dev/null
contains "자동완성(빈 q) — 많이 쓰인 순" "alpha" "$(cat "$WORK/b" | tr 'A-Z' 'a-z')"
expect "  다른 게시판의 태그(팀 게시판)는 없다" "no" "$(grep -q '숨김태그Z' "$WORK/b" && echo yes || echo no)"
expect "못 보는 게시판의 자동완성 → 403" "403" "$(capi GET "/api/boards/$B_TEAM/tags" claude_employee02)"
capi GET "/api/boards/$B_FREE/pages?tag=ALPHA" claude_employee02 > /dev/null
expect "태그 거르개(대소문자 무시) — 1건" "1" "$(jget "d['total']" < "$WORK/b")"
capi GET "/api/boards/$B_FREE/pages?q=%27%20OR%20%271%27%3D%271&sort=id;DROP&tag=%25" claude_employee02 > /dev/null
expect "인젝션 모양 q·sort·tag → 200 정상 모양 · 0건" "0" "$(jget "d['total']" < "$WORK/b")"

# ════════════════════════════════════════════════════════════════
hr "C:T-85  조회수 — 한 사람 하루 한 번"
VP=$(new_post claude_employee01 "$B_FREE" 'C5-조회 글')
for _ in 1 2 3; do capi GET "/api/pages/$VP" claude_employee01 > /dev/null; done
expect "같은 사람 세 번 GET → 1" "1" "$(views_of "$VP")"
capi GET "/api/pages/$VP" claude_employee02 > /dev/null
expect "다른 사람 GET → 2 · 응답 view_count 2" "2|2" "$(views_of "$VP")|$(jget "d['view_count']" < "$WORK/b")"
expect "못 보는 사람의 GET 은 세지 않는다 (Guest 403)" "403|2" "$(capi GET "/api/pages/$VP" claude_guest01)|$(views_of "$VP")"
capi GET "/api/boards/$B_FREE/pages?sort=views" claude_employee02 > /dev/null
expect "게시판 목록 — 조회수 순 첫 줄이 그 글 · views=2" "C5-조회 글|2" "$(jget "d['pages'][0]['title']" < "$WORK/b")|$(jget "d['pages'][0]['views']" < "$WORK/b")"
lacks "  누가 봤는지는 목록에 없다" "claude_employee02" "$(jget "d['pages'][0]" < "$WORK/b")"

# ════════════════════════════════════════════════════════════════
hr "C:T-86  검색(5-B) — 게시판 글이 등급대로 · 숨긴 글은 없다"
capi GET "/api/search?q=C5-%EC%9E%90%EC%9C%A0" claude_employee02 > /dev/null
contains "다른 팀 사원 — 자유게시판 글이 검색된다" "C5-자유 첫 글" "$(cat "$WORK/b")"
# 응답에는 찾은 말(`q`)이 되돌아오므로, 원문 grep 은 **결과 목록**(`results`)에만 한다.
capi GET "/api/search?q=%EB%B9%84%EB%B0%80%EC%A0%9C%EB%AA%A9" claude_employee02 > /dev/null
lacks "다른 팀 사원 — 팀 게시판 글은 검색에 없다" "비밀제목" "$(jget "d['results']" < "$WORK/b")"
capi GET "/api/search?q=%EB%B9%84%EB%B0%80%EC%A0%9C%EB%AA%A9" claude_employee01 > /dev/null
contains "같은 팀 사원 — 팀 게시판 글이 검색된다" "비밀제목" "$(jget "d['results']" < "$WORK/b")"
for who in claude_employee01 claude_manager01 claude_admin01; do
  capi GET "/api/search?q=C5-%ED%8C%80%20%EC%88%A8%EA%B8%B8" "$who" > /dev/null
  lacks "숨긴 글은 검색에 없다 — $who" "C5-팀 숨길 글" "$(jget "d['results']" < "$WORK/b")"
done
capi GET "/api/search?q=C5-%EA%B3%B5%EC%A7%80" claude_guest01 > /dev/null
contains "Guest — 공개 게시판 글은 검색된다" "C5-공지 하나" "$(cat "$WORK/b")"

# ════════════════════════════════════════════════════════════════
hr "C:T-89  해결됨 — 질문답변 게시판의 쓴 사람만"
QP=$(new_post claude_employee01 "$B_QA" 'C5-질문 하나')
expect "쓴 사람 resolved=true → 200" "200" "$(capi PATCH "/api/pages/$QP/properties" claude_employee01 <(printf '{"resolved":true}'))"
expect "남 → 403" "403" "$(capi PATCH "/api/pages/$QP/properties" claude_employee02 <(printf '{"resolved":false}'))"
expect "Admin(게시판 관리자) → 403" "403" "$(capi PATCH "/api/pages/$QP/properties" claude_admin01 <(printf '{"resolved":false}'))"
expect "질문답변이 아닌 글 → 400 not_qa" "400" "$(capi PATCH "/api/pages/$FP1/properties" claude_employee01 <(printf '{"resolved":true}'))"
expect "resolved 가 참·거짓이 아님 → 400" "400" "$(capi PATCH "/api/pages/$QP/properties" claude_employee01 <(printf '{"resolved":"yes"}'))"
capi GET "/api/boards/$B_QA/pages" claude_employee02 > /dev/null
contains "  목록에 resolved=true" '"resolved":true' "$(cat "$WORK/b")"
expect "  상태 칸은 그대로 (resolved 만 보낸 요청)" "" "$(pg_page "$QP" "coalesce(p.status,'')")"

# ════════════════════════════════════════════════════════════════
hr "C:T-88  기록 불변 · 처리 이력"
expect "page_views UPDATE (tbox_app) → 42501" "42501" "$(appq "UPDATE community.page_views SET day = day" | sed -n 's/.*ERROR: *\([0-9A-Z]\{5\}\).*/\1/p')"
expect "page_views DELETE (tbox_app) → 42501" "42501" "$(appq "DELETE FROM community.page_views" | sed -n 's/.*ERROR: *\([0-9A-Z]\{5\}\).*/\1/p')"
expect "page_property_events DELETE (tbox_app) → 42501" "42501" "$(appq "DELETE FROM community.page_property_events" | sed -n 's/.*ERROR: *\([0-9A-Z]\{5\}\).*/\1/p')"
expect "처리 이력에 publish·hide·resolved 줄이 있다" "yes" \
  "$([ "$(psqlq "SELECT count(DISTINCT prop) FROM community.page_property_events WHERE id > ${PPE_MARK:-0} AND prop IN ('publish','hide','resolved')")" = 3 ] && echo yes || echo no)"

# ════════════════════════════════════════════════════════════════
hr "C:T-87  판정 함수 = 목록 SQL — 전 계정 × 전 페이지 (게시판 글 · 게시 · 숨김 포함)"

# 게시·숨김 조합을 하나 더 만들어 둔다 — 숨긴 채 게시된 페이지(주인 e1, 원래 범위 team)와 게시 중인 페이지.
P3=$(make_page claude_employee01 'C5-팀범위 게시 숨김')
capi PATCH "/api/pages/$P3/visibility" claude_employee01 <(printf '{"visibility":"team"}') > /dev/null
publish claude_employee01 "$P3" "$B_TEAM" > /dev/null
capi POST "/api/pages/$P3/hide" claude_manager01 > /dev/null
capi DELETE "/api/pages/$P3/hide" claude_manager01 > /dev/null
share claude_employee01 "$P3" claude_employee02 view > /dev/null
capi GET "/api/pages/$P3" claude_employee02 > /dev/null
expect "못 읽는 게시판(다른 팀)에 걸린 페이지를 공유로 볼 때 — 게시판 칸 없음 (이름 새지 않음, 독립 검토)" "200|" \
  "$(status_of "$WORK/h")|$(jget "d['board']" < "$WORK/b")"
lacks "  응답 원문에 팀 게시판 이름 없음" "C5-팀게시판" "$(cat "$WORK/b")"
unshare claude_employee01 "$P3" claude_employee02 > /dev/null
capi POST "/api/pages/$P3/hide" claude_manager01 > /dev/null
A9=$(mark)
capi GET "/api/pages/$P3" claude_admin01 > /dev/null
expect "숨긴 게시 페이지를 Admin 이 열면 admin_all 감사 (판정 순서)" "1" "$(audit_n "$A9" page.read allow admin_all)"
P4=$(make_page claude_employee02 'C5-다른팀 게시 중')
publish claude_employee02 "$P4" "$B_FREE" > /dev/null
note "게시 숨김(팀 게시판): $(pg_page "$P3" "p.visibility||'/'||p.published_from||'/'||(p.hidden_at IS NOT NULL)") · 게시 중(자유): $(pg_page "$P4" "p.visibility")"

cat > "$WORK/matrix.php" <<'PHP'
<?php
declare(strict_types=1);
// 임시 인스턴스의 저장소에서 부른다(자기 .env = 임시 DB). 읽기만 한다.
$app = $argv[1];
require $app . '/scripts/lib/cli_bootstrap.php';
require_once $app . '/src/community/routes.php';

$db = Db::instance();
$users = $db->run('SELECT id, username, role, team_id, is_active FROM public.users ORDER BY id')->fetchAll();
$pages = $db->run('SELECT public_id FROM community.pages ORDER BY id')->fetchAll();

$pairs = 0; $bad = 0; $allowed = 0; $codes = []; $samples = [];
foreach ($users as $r) {
    $u = ['id' => (int) $r['id'], 'username' => (string) $r['username'], 'role' => (string) $r['role'],
          'team_id' => $r['team_id'] === null ? null : (int) $r['team_id']];
    foreach (['list' => false, 'search' => true] as $mode => $isSearch) {
        [$where, $bind] = $isSearch ? community_search_pages_where($u) : community_visible_pages_where($u);
        $sql = 'SELECT p.public_id FROM community.pages p'
             . ' JOIN community.spaces s ON s.id = p.space_id'
             . ' LEFT JOIN public.users owner ON owner.id = s.owner_user_id'
             . ' WHERE p.deleted_at IS NULL AND ' . $where;
        $inSql = [];
        foreach ($db->run($sql, $bind)->fetchAll() as $v) { $inSql[(string) $v['public_id']] = true; }
        foreach ($pages as $p) {
            $pid = (string) $p['public_id'];
            $row = community_page_row($pid, (int) $u['id']);
            [$ok, $code] = CommunityAuthz::decidePageRead($u, $row);
            if ($isSearch) {
                // 검색은 특권 갈래 둘(관리자 admin_all · 게시판 관리자 board_moderator)을 끈 목록이다.
                if ($code === CommunityAuthz::CODE_ADMIN_ALL || $code === CommunityAuthz::CODE_BOARD_MODERATOR) { $ok = false; }
            } else {
                $pairs++;
                if ($ok) { $allowed++; $codes[$code] = ($codes[$code] ?? 0) + 1; }
            }
            if ($ok !== isset($inSql[$pid])) {
                $bad++;
                if (count($samples) < 8) { $samples[] = $mode . ' ' . $u['username'] . ' × ' . $pid . ' (판정=' . ($ok ? $code : 'X:' . $code) . ')'; }
            }
        }
    }
}
ksort($codes);
echo "PAIRS=$pairs\nALLOWED=$allowed\nMISMATCH=$bad\n";
foreach ($codes as $c => $n) { echo "CODE_$c=$n\n"; }
foreach ($samples as $s) { echo "SAMPLE=$s\n"; }
PHP

( cd "$INST/app" && php "$WORK/matrix.php" "$INST/app" ) > "$WORK/matrix.txt" 2>&1
sed 's/^/    /' "$WORK/matrix.txt"
expect "전수 대조(목록·검색 두 벌) — 어긋난 쌍 0" "MISMATCH=0" "$(grep '^MISMATCH=' "$WORK/matrix.txt")"
expect "  게시판 등급 갈래(board)가 대조에 들어 있다" "yes" "$([ "$(sed -n 's/^CODE_board=//p' "$WORK/matrix.txt")" -gt 0 ] 2>/dev/null && echo yes || echo no)"
expect "  숨긴 글 관리자 갈래(board_moderator)가 대조에 들어 있다" "yes" "$([ "$(sed -n 's/^CODE_board_moderator=//p' "$WORK/matrix.txt")" -gt 0 ] 2>/dev/null && echo yes || echo no)"

# ════════════════════════════════════════════════════════════════
hr "C:T-90  브라우저 — browser_5c.py (게시판 화면 · 새 글 · 게시 · 태그 · 숨기기)"

export PATH="$HOME/.local/bin:$PATH"
cookie_of() {
  python3 - "$JAR_DIR/$1.jar" <<'PY'
import sys
for line in open(sys.argv[1], encoding='utf-8', errors='replace'):
    parts = line.rstrip('\n').split('\t')
    if len(parts) == 7 and parts[5] == '__Host-tbox_session':
        print(parts[6]); break
PY
}
if ! python3 -c 'import playwright' 2>/dev/null; then
  skip "브라우저" "playwright 가 없다"
else
  expect "브라우저 앞 Admin 재인증 → 200" "200" "$(reauth_admin)"
  SA=$(cookie_of claude_admin01); S1=$(cookie_of claude_employee01); S2=$(cookie_of claude_employee02); SM=$(cookie_of claude_manager01)
  SMOKE_ADMIN="$SA" SMOKE_E1="$S1" SMOKE_E2="$S2" SMOKE_M1="$SM" SMOKE_FREE="$B_FREE" SMOKE_TEAM="$B_TEAM" \
  SMOKE_QA="$B_QA" SMOKE_SHOTS="${SHOTS:-}" python3 "$ROOT/tests/community/browser_5c.py" "$SITE" "$UA" > "$WORK/smoke.txt" 2>&1
  sed 's/^/    /' "$WORK/smoke.txt"
  expect "브라우저 실패 0" "0" "$(sed -n 's/^SMOKE_FAIL=//p' "$WORK/smoke.txt" | tail -1)"
  [ -n "${SMOKE_KEEP:-}" ] && cp "$WORK/smoke.txt" "$SMOKE_KEEP"
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
bash -n "$ROOT/tests/community/stage5c.sh" && expect "stage5c.sh 문법" "0" "0"
python3 -c "import ast,io;ast.parse(io.open('$ROOT/tests/community/browser_5c.py',encoding='utf-8').read())" \
  && expect "browser_5c.py 문법" "0" "0"
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
  for s in ${REGRESS_LIST:-community/stage3 community/stage4a community/stage4b community/stage4c community/stage4d community/stage5a community/stage5a2 stage26 help_button api_whoami api_read api_folders api_upload api_tokens f65 f66 f68}; do
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
