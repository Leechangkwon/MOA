#!/usr/bin/env bash
# tests/community/stage5a2.sh — 5-A″ 수락 기준 C:T-61 ~ C:T-66 실행
#                               (../../../community/stage5a2-names-teamshare-directive.md 「완료 기준」)
#
#   C:T-61 이름 칸      마이그레이션 두 번(멱등)·CHECK·열 권한 · Admin 만 쓴다(Employee·Manager 창구 403 ·
#                       재인증 관문) · 41자·제어·서식 문자 400 · 앞뒤 공백 제거 · null 로 지우기 ·
#                       자기 이름 400 self_change · 감사 user.update(바뀐 칸 이름만 — 값 0)
#   C:T-62 보이는 자리  창구는 username·display_name 을 **따로**(이어 붙인 글자 0) — 소유자 · 공유한 사람 ·
#                       후보(이름으로 찾기) · 판 · 이력 · 편집 중 · 공유받은 목록 · 인계 응답 ·
#                       서버가 그리는 셸·런처·「내 계정」은 「이름 (아이디)」, `<script>` 이름은 이스케이프
#   C:T-63 후보          `q` 가 공백 제거 뒤 비면 200 `[]` · 한 글자면 결과 · `%`·`_` 는 글자로만
#   C:T-64 팀 공간 공유 팀원이 팀 밖 사람에게 보기 → GET 200·PATCH 403 · 편집 → 저장 200·삭제 403 ·
#                       옮기기·다시 공유·후보·인계 거부 · 팀원 아닌 사람의 공유 주기 403 · 하위 전파 ·
#                       빼면 그 자리에서 403
#   C:T-65 판정 = SQL    전 계정 × 전 페이지 전수 대조(팀 공간 공유 포함) 어긋남 0
#   C:T-66 브라우저      browser_5a2.py — 계정 관리·셸·커뮤니티 창들을 눌러 보고 라이트·다크·390px 스크린샷
#   회귀 (REGRESS=1)     같은 임시 인스턴스에서 커뮤니티 stage3·4a·4b·4c 와 t-box stage26·help_button·
#                        계정 관리를 부르는 시험들(`grep -rln users tests`)
#
# ══════════════════════════════════════════════════════════════════════════
# ★ 이 시험은 **운영 DB 를 한 글자도 건드리지 않는다.** (stage5a.sh 와 같은 방식)
#   ① 운영 DB(`tbox_re`)를 **읽어서**(pg_dump) 임시 DB `tbox_5a2_test` 를 만들고
#   ② 거기에만 5-A″ 마이그레이션(`db/migrations/2026-09-23_users_display_name.sql`)을 두 번 적용하고
#   ③ 저장소를 임시 폴더로 복사해 자기 `.env`(DB 이름·저장 경로·로그 경로만 다르다)를 주고
#   ④ `php -S 127.0.0.1:8085` 로 두 번째 인스턴스를 띄워 그쪽에만 요청을 보낸다.
#   ⑤ 끝나면 서버를 내리고 임시 DB 와 임시 폴더를 **지운다**.
#   이름을 넣는 것도 계정을 하나 만드는 것도 **임시 DB 에서만**이다.
# ══════════════════════════════════════════════════════════════════════════
#
# 규칙 (CLAUDE.md 3장) — 검토 계정 `qa_` 다섯뿐(로그인은 순차) · 모든 요청에 `-A "tbox-review/<날짜>"` ·
#   `sudo -n` 은 읽기(pg_dump · seed 파일)와 임시 DB 에만.
# 사용: sudo -v && bash tests/community/stage5a2.sh     (REGRESS=1 이면 회귀까지, SHOTS=<폴더> 면 스크린샷)
set -u

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
PORT=8085
SITE="http://127.0.0.1:$PORT"
BASE="$SITE/tbox"
CBASE="$SITE/community"
UA="tbox-review/$(date +%F)"
SRC_DB="tbox_re"          # 운영 — **읽기만** 한다
DB="tbox_5a2_test"         # 임시 — 여기에만 쓴다
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
hr "5-A″ — 임시 인스턴스 준비 (운영 DB 에는 쓰지 않는다)"

avail_kb=$(df -Pk / | awk 'NR==2{print $4}')
echo "루트 파티션 여유: $(df -Ph / | awk 'NR==2{print $4}') (${avail_kb}KB)"
[ "$avail_kb" -ge 1048576 ] || { echo "루트 디스크 여유 1GB 미만 — 시험을 시작하지 않는다 (SECURITY R-80)"; exit 3; }

sudo -n -u postgres psql -d "$SRC_DB" -tAc "SELECT 1" >/dev/null 2>&1 \
  || { echo "psql(postgres) 을 쓸 수 없다 — 이 시험은 임시 DB 가 없으면 성립하지 않는다"; exit 3; }

sub "① 운영 DB 를 **읽어** 임시 DB 를 만든다"
DUMP="/tmp/tbox5a2_dump.$$.sql"
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

sub "② 마이그레이션 + 권한 (임시 DB 에만) — 4-D·5-A(이미 있으면 그대로) · **5-A″ 이름 칸을 두 번** 돌려 멱등을 본다"
for mig in 2026-09-23_community_page_properties.sql 2026-09-23_community_owner_share.sql \
           2026-09-23_users_display_name.sql 2026-09-23_users_display_name.sql \
           2026-09-24_community_boards.sql \
           2026-09-24_community_discussion.sql 2026-09-25_community_databases.sql; do   # 5-C·5-D — 코드가 새 칸·표를 읽는다
  echo "    ▸ $mig"
  sudo -n -u postgres psql -d "$DB" -v ON_ERROR_STOP=1 -f - < "$ROOT/db/migrations/$mig" 2>&1 \
    | grep -v 'could not change directory' | sed 's/^/      /'
done
sudo -n -u postgres psql -q -d "$DB" -v ON_ERROR_STOP=1 -f - < "$ROOT/db/community/grants.sql" 2>&1 \
  | grep -v 'could not change directory' | sed 's/^/    /'

hr "C:T-61 (가)  이름 칸 — 마이그레이션 · 열 권한"
expect "users.display_name 열이 하나 있다 (두 번 돌려도)" "1" \
  "$(psqlq "SELECT count(*) FROM information_schema.columns WHERE table_schema='public' AND table_name='users' AND column_name='display_name'")"
expect "CHECK users_display_name_shape 가 하나 있다" "1" \
  "$(psqlq "SELECT count(*) FROM pg_constraint WHERE conname='users_display_name_shape' AND conrelid='public.users'::regclass")"
expect "기존 계정의 이름은 전부 NULL (데이터 변경 0)" "0" \
  "$(psqlq "SELECT count(*) FROM users WHERE display_name IS NOT NULL")"
note "users 표 권한(\\dp): $(psqlq "SELECT array_to_string(relacl, ' ') FROM pg_class WHERE oid='public.users'::regclass")"
contains "앱 계정(tbox_app)은 표 단위 SELECT·INSERT·UPDATE (arw) — 새 열도 그대로 읽는다" "tbox_app=arw/" \
  "$(psqlq "SELECT array_to_string(relacl, ' ') FROM pg_class WHERE oid='public.users'::regclass")"
expect "열 단위 GRANT 는 없다 (display_name 에 따로 줄 권한 없음)" "0" \
  "$(psqlq "SELECT count(*) FROM information_schema.column_privileges WHERE table_schema='public' AND table_name='users' AND grantee='tbox_app' AND column_name='display_name' AND privilege_type NOT IN ('SELECT','INSERT','UPDATE','REFERENCES')")"
expect "DB CHECK — 앞 공백 이름은 DB 가 막는다 (23514)" "23514" \
  "$(sudo -n -u postgres psql -d "$DB" -v VERBOSITY=verbose -t -A -c "UPDATE users SET display_name=' x' WHERE username='qa_guest01'" 2>&1 | sed -n 's/.*ERROR: *\([0-9A-Z]\{5\}\).*/\1/p' | head -1)"
expect "DB CHECK — 41자 이름은 DB 가 막는다 (23514)" "23514" \
  "$(sudo -n -u postgres psql -d "$DB" -v VERBOSITY=verbose -t -A -c "UPDATE users SET display_name=repeat('가',41) WHERE username='qa_guest01'" 2>&1 | sed -n 's/.*ERROR: *\([0-9A-Z]\{5\}\).*/\1/p' | head -1)"

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

sub "로그인 (qa 계정 다섯 — **순차**)"
for who in qa_employee01 qa_employee02 qa_manager01 qa_admin01 qa_guest01; do
  tok=$(login "$who")
  if [ -z "$tok" ]; then echo "  ✖ $who 로그인 실패 — 중단"; exit 4; fi
  eval "CSRF_${who}='$tok'"
  echo "  ✔ $who"
done
# 개인 공간을 연다(없으면 만든다) — 그 뒤의 목록·인계가 공간을 전제로 한다.
for who in qa_employee01 qa_employee02 qa_manager01; do capi GET /api/me/space "$who" > /dev/null; done
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
# 이름 바꾸기 요청 본문 — 값은 JSON 으로 **파이썬이** 만든다(셸 따옴표에 이름이 섞이지 않게)
name_body() {   # $1 이름(파이썬 식 — 예: "'이둘'" · "None" · "'가'*41")
  python3 -c "import json;print(json.dumps({'display_name': $1, 'reason_text': '5-A″ 이름 시험'}, ensure_ascii=False))" > "$WORK/name.json"
}
set_name() {    # $1 대상 계정 $2 파이썬 식 → 상태 코드 (qa_admin01 이 보낸다)
  name_body "$2"
  tapi PATCH "/api/users/$(uid "$1")" qa_admin01 "$WORK/name.json"
}
reauth_admin() {
  local pw rc
  pw=$(qa_password qa_admin01)
  python3 -c "import json,sys;print(json.dumps({'password':sys.argv[1]}))" "$pw" > "$WORK/reauth.json"
  rc=$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR_DIR/qa_admin01.jar" -A "$UA" -H 'Content-Type: application/json' \
        -H "X-CSRF-Token: $CSRF_qa_admin01" --data-binary "@$WORK/reauth.json" "$BASE/api/me/reauth")
  rm -f "$WORK/reauth.json"
  printf '%s' "$rc"
}

N1='김하나<script>alert(1)</script>'
N2='이둘'
NM='박관리'

# ════════════════════════════════════════════════════════════════
hr "C:T-61 (나)  이름 쓰기는 Admin 만 · 검증 · 감사(바뀐 칸 이름만)"

sub "Employee·Manager 는 창구로 이름을 바꿀 수 없다 → 403"
name_body "'$N2'"
expect "employee01 → PATCH /api/users/{employee02} → 403" "403" "$(tapi PATCH "/api/users/$(uid qa_employee02)" qa_employee01 "$WORK/name.json")"
expect "employee02 → PATCH /api/users/{자기} → 403" "403" "$(tapi PATCH "/api/users/$(uid qa_employee02)" qa_employee02 "$WORK/name.json")"
expect "manager01 → PATCH /api/users/{employee01} → 403" "403" "$(tapi PATCH "/api/users/$(uid qa_employee01)" qa_manager01 "$WORK/name.json")"
python3 -c "import json;print(json.dumps({'username':'t5a2_x','role':'Employee','display_name':'새사람','reason_text':'x'}, ensure_ascii=False))" > "$WORK/cu.json"
expect "manager01 → POST /api/users {display_name} → 403" "403" "$(tapi POST /api/users qa_manager01 "$WORK/cu.json")"
expect "  DB: employee02 이름은 그대로 NULL" "t" "$(psqlq "SELECT display_name IS NULL FROM users WHERE username='qa_employee02'")"

sub "Admin — 재인증 관문 그대로"
expect "재인증 없이 PATCH → 403 next=reauth" "403|reauth" \
  "$(set_name qa_employee02 "'$N2'")|$(jget "d.get('next')" < "$WORK/b")"
expect "재인증 → 200" "200" "$(reauth_admin)"

A0=$(mark)
expect "employee02 에게 「$N2」 → 200" "200" "$(set_name qa_employee02 "'$N2'")"
expect "  응답은 username·display_name 을 **따로** 준다" "qa_employee02|$N2" \
  "$(jget "d['username'] + '|' + d['display_name']" < "$WORK/b")"
expect "  DB 값" "$N2" "$(psqlq "SELECT display_name FROM users WHERE username='qa_employee02'")"
expect "  감사 user.update allow 1줄" "1" "$(audit_n "$A0" user.update allow '')"
expect "  감사 detail.changed_fields = [\"display_name\"]" '["display_name"]' \
  "$(psqlq "SELECT detail->'changed_fields' FROM audit_logs WHERE id > $A0 AND action='user.update' ORDER BY id DESC LIMIT 1")"
expect "  감사 어디에도 이름의 **값**이 없다 (detail·summary)" "0" \
  "$(psqlq "SELECT count(*) FROM audit_logs WHERE id > $A0 AND (detail::text LIKE '%$N2%' OR summary LIKE '%$N2%')")"
expect "  세션은 끊지 않는다 (이름은 권한이 아니다) — employee02 GET /api/me 200" "200" "$(tapi GET /api/me qa_employee02)"

A1=$(mark)
expect "41자 → 400" "400" "$(set_name qa_employee02 "'가'*41")"
expect "  감사 user.update deny bad_request" "1" "$(audit_n "$A1" user.update deny bad_request)"
expect "제어 문자(\\u0007) → 400" "400" "$(set_name qa_employee02 "'이\\u0007둘'")"
expect "방향 바꾸기(\\u202E) → 400" "400" "$(set_name qa_employee02 "'이\\u202e둘'")"
expect "폭 없는 공백(\\u200B) → 400" "400" "$(set_name qa_employee02 "'이\\u200b둘'")"
expect "줄바꿈 → 400" "400" "$(set_name qa_employee02 "'이\\n둘'")"
expect "숫자(문자열 아님) → 400" "400" "$(set_name qa_employee02 "12")"
expect "  DB 값은 그대로" "$N2" "$(psqlq "SELECT display_name FROM users WHERE username='qa_employee02'")"
expect "40자 → 200 (경계)" "200" "$(set_name qa_employee02 "'가'*40")"
expect "  다시 「$N2」 → 200" "200" "$(set_name qa_employee02 "'$N2'")"
expect "같은 이름을 다시 → 400 (바뀐 것 없음)" "400" "$(set_name qa_employee02 "'$N2'")"
expect "앞뒤 공백(전각 포함)은 지운다 — manager01 「　 $NM  」 → 200" "200" "$(set_name qa_manager01 "'\\u3000 $NM  '")"
expect "  DB 값은 「$NM」" "$NM" "$(psqlq "SELECT display_name FROM users WHERE username='qa_manager01'")"
expect "employee01 에게 「$N1」(<script> 포함 28자) → 200" "200" "$(set_name qa_employee01 "'$N1'")"
expect "  DB 값은 글자 그대로" "$N1" "$(psqlq "SELECT display_name FROM users WHERE username='qa_employee01'")"
expect "guest01 에게 「임시」 → 200" "200" "$(set_name qa_guest01 "'임시'")"
expect "  null 로 지우기 → 200 · DB NULL" "200|t" "$(set_name qa_guest01 "None")|$(psqlq "SELECT display_name IS NULL FROM users WHERE username='qa_guest01'")"
expect "  공백뿐이면 지우기와 같다 — 이미 NULL 이라 400(바뀐 것 없음)" "400" "$(set_name qa_guest01 "'   '")"

A2=$(mark)
expect "Admin 이 **자기** 이름 → 400 (self_change — 본인은 보기만)" "400" "$(set_name qa_admin01 "'관리자'")"
expect "  감사 user.update deny self_change" "1" "$(audit_n "$A2" user.update deny self_change)"

sub "함께 바뀌면 그 칸의 action 이고 changed_fields 에 둘 다 — manager01 만료일 + 이름"
python3 -c "import json;print(json.dumps({'display_name':'박관리자','expires_at':'2030-12-31','reason_text':'5-A″ 함께'}, ensure_ascii=False))" > "$WORK/both.json"
A3=$(mark)
expect "PATCH {display_name, expires_at} → 200" "200" "$(tapi PATCH "/api/users/$(uid qa_manager01)" qa_admin01 "$WORK/both.json")"
expect "  감사 user.expiry_change · changed_fields = expires_at,display_name" "user.expiry_change|expires_at,display_name" \
  "$(psqlq "SELECT action || '|' || (SELECT string_agg(x, ',') FROM jsonb_array_elements_text(detail->'changed_fields') x) FROM audit_logs WHERE id > $A3 AND action LIKE 'user.%' ORDER BY id DESC LIMIT 1")"
python3 -c "import json;print(json.dumps({'display_name':'$NM','expires_at':None,'reason_text':'5-A″ 되돌림'}, ensure_ascii=False))" > "$WORK/both.json"
expect "  되돌림 → 200" "200" "$(tapi PATCH "/api/users/$(uid qa_manager01)" qa_admin01 "$WORK/both.json")"

sub "계정 만들기의 이름 (임시 DB 에만 계정이 생긴다)"
python3 -c "import json;print(json.dumps({'username':'t5a2_long','role':'Employee','display_name':'나'*41,'reason_text':'5-A″'}, ensure_ascii=False))" > "$WORK/cu.json"
expect "POST /api/users 이름 41자 → 400 · 계정 없음" "400|0" \
  "$(tapi POST /api/users qa_admin01 "$WORK/cu.json")|$(psqlq "SELECT count(*) FROM users WHERE username='t5a2_long'")"
python3 -c "import json;print(json.dumps({'username':'t5a2_new','role':'Employee','display_name':' 새사람 ','reason_text':'5-A″'}, ensure_ascii=False))" > "$WORK/cu.json"
A4=$(mark)
expect "POST /api/users 이름 「 새사람 」 → 201 · 응답 display_name=새사람" "201|새사람" \
  "$(tapi POST /api/users qa_admin01 "$WORK/cu.json")|$(jget "d['display_name']" < "$WORK/b")"
expect "  감사 user.create detail.display_name_set = true · 값 없음" "true|0" \
  "$(psqlq "SELECT detail->>'display_name_set' FROM audit_logs WHERE id > $A4 AND action='user.create' ORDER BY id DESC LIMIT 1")|$(psqlq "SELECT count(*) FROM audit_logs WHERE id > $A4 AND detail::text LIKE '%새사람%'")"

sub "계정 관리 목록 — 이름은 따로, 이름 없는 계정은 null"
expect "GET /api/users → 200" "200" "$(tapi GET /api/users qa_admin01)"
expect "  employee02 → display_name=$N2 · guest01 → None" "$N2|None" \
  "$(jget "'|'.join(str(next(x['display_name'] for x in d['users'] if x['username']==n)) for n in ('qa_employee02','qa_guest01'))" < "$WORK/b")"
lacks "  목록 응답에 「이름 (아이디)」로 이은 글자가 없다" "$N2 (qa_employee02)" "$(cat "$WORK/b")"

# ════════════════════════════════════════════════════════════════
hr "C:T-62  보이는 자리 — 창구는 따로 주고, 서버가 그리는 화면은 「이름 (아이디)」"

sub "셸·런처·「내 계정」 (서버가 그린다 — htmlspecialchars)"
expect "employee02 t-box 홈 → 200" "200" "$(page_html /tbox/ qa_employee02)"
contains "  사용자 칸 「$N2 (qa_employee02)」" "$N2 (qa_employee02)" "$(cat "$WORK/html")"
expect "employee01 t-box 홈 → 200" "200" "$(page_html /tbox/ qa_employee01)"
contains "  <script> 이름은 이스케이프되어 글자로 나간다" "김하나&lt;script&gt;alert(1)&lt;/script&gt; (qa_employee01)" "$(cat "$WORK/html")"
lacks "  날 <script>alert(1) 이 HTML 에 없다" "<script>alert(1)" "$(cat "$WORK/html")"
expect "  머리글자는 이름의 첫 글자(김)" "2" "$(grep -c 'avatar" aria-hidden="true">김<' "$WORK/html")"
expect "guest01(이름 없음) t-box 홈 → 200" "200" "$(page_html /tbox/ qa_guest01)"
contains "  아이디만" 'userchip__name">qa_guest01<' "$(cat "$WORK/html")"
lacks "  「(qa_guest01)」 괄호가 없다" "(qa_guest01)" "$(cat "$WORK/html")"
expect "employee02 런처(/) → 200" "200" "$(page_html / qa_employee02)"
contains "  런처의 「이름 (아이디) · 역할」" "$N2 (qa_employee02) · Employee" "$(cat "$WORK/html")"
expect "employee02 「내 계정」 → 200" "200" "$(page_html /tbox/settings qa_employee02)"
contains "  이름 칸(보기만)" ">$N2<" "$(cat "$WORK/html")"
page_html /tbox/settings qa_guest01 > /dev/null
contains "  이름 없는 guest 는 안내 한 줄" "관리자가 정합니다" "$(cat "$WORK/html")"
lacks "  「내 계정」에 이름을 바꾸는 입력이 없다" 'id="me-name"' "$(cat "$WORK/html")"
expect "employee02 커뮤니티 홈 → 200" "200" "$(page_html /community/ qa_employee02)"
contains "  커뮤니티 셸도 같은 모양" "$N2 (qa_employee02)" "$(cat "$WORK/html")"

sub "커뮤니티 창구 — 소유자 · 공유한 사람 · 후보 · 이력 · 판 · 편집 중 · 공유받은 목록"
P=$(make_page qa_employee01 "5A2-이름")
[ -n "$P" ] || { echo "시험 페이지를 만들지 못했다 — 중단"; exit 4; }
expect "employee02 에게 편집 공유 → 200" "200" "$(share qa_employee01 "$P" qa_employee02 edit)"
expect "  PUT 응답의 share.display_name" "$N2" "$(jget "d['share']['display_name']" < "$WORK/b")"
expect "GET 페이지 (employee02) → 200" "200" "$(capi GET "/api/pages/$P" qa_employee02)"
expect "  owner = qa_employee01 · display_name 글자 그대로" "qa_employee01|$N1" \
  "$(jget "d['page']['owner']['username'] + '|' + d['page']['owner']['display_name']" < "$WORK/b")"
lacks "  창구가 이어 붙인 글자를 주지 않는다" "(qa_employee01)" "$(cat "$WORK/b")"
expect "공유 목록 (employee01) → 200 · display_name" "200|$N2" \
  "$(capi GET "/api/pages/$P/shares" qa_employee01)|$(jget "d['shares'][0]['display_name']" < "$WORK/b")"
expect "후보를 **이름**으로 찾는다 q=둘 → qa_employee02" "200|qa_employee02|$N2" \
  "$(capi GET "/api/share-candidates?page=$P&q=%EB%91%98" qa_employee01)|$(jget "','.join(c['username'] for c in d['candidates'])" < "$WORK/b")|$(jget "d['candidates'][0]['display_name']" < "$WORK/b")"
expect "  q=관리 → qa_manager01 (이름으로)" "qa_manager01" \
  "$(capi GET "/api/share-candidates?page=$P&q=%EA%B4%80%EB%A6%AC" qa_employee01 >/dev/null; jget "','.join(c['username'] for c in d['candidates'])" < "$WORK/b")"
expect "  이름 없는 계정은 아이디로 찾힌다 q=qa_guest → qa_guest01 · display_name None" "qa_guest01|None" \
  "$(capi GET "/api/share-candidates?page=$P&q=qa_guest" qa_employee01 >/dev/null; jget "','.join(c['username'] for c in d['candidates']) + '|' + str(d['candidates'][0]['display_name'])" < "$WORK/b")"
expect "  q=<script> 로 찾아도 주인(employee01)은 후보에 없다" "0" \
  "$(capi GET "/api/share-candidates?page=$P&q=%3Cscript%3E" qa_employee01 >/dev/null; jget "len(d['candidates'])" < "$WORK/b")"

BLK=$(python3 -c 'import uuid;print(uuid.uuid4())')
python3 - "$BLK" "$(pg_page "$P" "p.doc_version")" > "$WORK/doc.json" <<'PY'
import json, sys
doc = {"schema": 1, "blocks": [{"id": sys.argv[1], "type": "to_do", "checked": True, "text": [{"t": "5A2 할 일", "m": {}}]}]}
print(json.dumps({"doc_version": int(sys.argv[2]), "doc": doc}, ensure_ascii=False))
PY
expect "employee02 가 저장(체크) → 200" "200" "$(capi PATCH "/api/pages/$P" qa_employee02 "$WORK/doc.json")"
expect "판 목록 — 지금 판의 바꾼 사람 qa_employee02 · $N2" "200|qa_employee02|$N2" \
  "$(capi GET "/api/pages/$P/versions" qa_employee01)|$(jget "d['versions'][0]['by'] + '|' + d['versions'][0]['by_display_name']" < "$WORK/b")"
expect "  지난 판(1)의 바꾼 사람 qa_employee01 · 이름" "qa_employee01|$N1" \
  "$(jget "d['versions'][1]['by'] + '|' + d['versions'][1]['by_display_name']" < "$WORK/b")"
expect "판 하나 (1) → by_display_name" "200|$N1" \
  "$(capi GET "/api/pages/$P/versions/1" qa_employee01)|$(jget "d['version']['by_display_name']" < "$WORK/b")"
expect "처리 이력 — 체크 줄의 by·by_display_name" "200|qa_employee02|$N2" \
  "$(capi GET "/api/pages/$P/history" qa_employee01)|$(jget "[e for e in d['events'] if e['kind']=='check'][0]['by'] + '|' + [e for e in d['events'] if e['kind']=='check'][0]['by_display_name']" < "$WORK/b")"
body '{}'
expect "employee02 편집 중 신호 → 200" "200" "$(capi POST "/api/pages/$P/heartbeat" qa_employee02 "$WORK/body.json")"
expect "  employee01 이 보는 editors[0] = qa_employee02 · $N2" "qa_employee02|$N2" \
  "$(capi GET "/api/pages/$P" qa_employee01 >/dev/null; jget "d['editors'][0]['username'] + '|' + d['editors'][0]['display_name']" < "$WORK/b")"
expect "공유받은 페이지 목록(employee02)의 owner.display_name" "$N1" \
  "$(capi GET "/api/pages?shared=me" qa_employee02 >/dev/null; jget "[x for x in d['pages'] if x['id']=='$P'][0]['owner']['display_name']" < "$WORK/b")"

H=$(make_page qa_employee01 "5A2-인계-이름")
body '{"to":"qa_employee02","keep":"none"}'
expect "인계 → 200 · 응답 to_display_name" "200|$N2" \
  "$(capi POST "/api/pages/$H/handover" qa_employee01 "$WORK/body.json")|$(jget "d['to_display_name']" < "$WORK/b")"
expect "  이력 인계 줄 — from/to 와 이름" "qa_employee01|$N1|qa_employee02|$N2" \
  "$(capi GET "/api/pages/$H/history" qa_employee02 >/dev/null; jget "(lambda e: '|'.join([e['from'], e['from_display_name'], e['to'], e['to_display_name']]))([x for x in d['events'] if x['kind']=='handover'][0])" < "$WORK/b")"

# ════════════════════════════════════════════════════════════════
hr "C:T-63  후보는 한 글자부터 — 빈 값·공백은 빈 목록(200)"

for q in "" "%20%20%20" "%E3%80%80" "%09"; do
  expect "q='$q' → 200 · candidates []" "200|0" \
    "$(capi GET "/api/share-candidates?page=$P&q=$q" qa_employee01)|$(jget "len(d['candidates'])" < "$WORK/b")"
done
expect "q 없음 → 200 · []" "200|0" "$(capi GET "/api/share-candidates?page=$P" qa_employee01)|$(jget "len(d['candidates'])" < "$WORK/b")"
expect "인계 후보 q 없음 → 200 · []" "200|0" \
  "$(capi GET "/api/share-candidates?for=handover&page=$P" qa_employee01)|$(jget "len(d['candidates'])" < "$WORK/b")"
N_Q=$(capi GET "/api/share-candidates?page=$P&q=q" qa_employee01 >/dev/null; jget "len(d['candidates'])" < "$WORK/b")
expect "한 글자 q=q → 결과가 있다" "yes" "$([ "${N_Q:-0}" -gt 0 ] && echo yes || echo no)"
expect "  %·_ 는 글자로만 — q=% → 0" "0" "$(capi GET "/api/share-candidates?page=$P&q=%25" qa_employee01 >/dev/null; jget "len(d['candidates'])" < "$WORK/b")"
expect "  q=_ 의 결과는 전부 아이디나 이름에 「_」 가 있다" "True" \
  "$(capi GET "/api/share-candidates?page=$P&q=_" qa_employee01 >/dev/null; jget "all('_' in c['username'] or '_' in (c['display_name'] or '') for c in d['candidates'])" < "$WORK/b")"

# ════════════════════════════════════════════════════════════════
hr "C:T-64  팀 공간 페이지 공유 — 팀원이 팀 밖 사람에게"

capi GET /api/spaces qa_employee01 > /dev/null
TEAM_SPACE=$(jget "[s['id'] for s in d['spaces'] if s['kind']=='team'][0]" < "$WORK/b")
note "employee01 의 팀 공간 번호: ${TEAM_SPACE:-없음} (employee02 는 다른 팀 $TEAM2)"
[ -n "$TEAM_SPACE" ] || { echo "팀 공간을 찾지 못했다 — 중단"; exit 4; }
TP=$(make_page qa_employee01 "5A2-팀-뿌리" "" "$TEAM_SPACE")
TPC=$(make_page qa_employee01 "5A2-팀-하위" "$TP")
[ -n "$TP" ] && [ -n "$TPC" ] || { echo "팀 공간 페이지를 만들지 못했다 — 중단"; exit 4; }
note "TP=$TP · TPC=$TPC"
expect "공유 전 employee02(팀 밖) GET → 403" "403" "$(capi GET "/api/pages/$TP" qa_employee02)"
expect "팀원 employee01 의 GET — can_share=True · can_set_visibility=False · can_handover=False" "200|True|False|False" \
  "$(capi GET "/api/pages/$TP" qa_employee01)|$(jget "'%s|%s|%s' % (d['can_share'], d['can_set_visibility'], d['can_handover'])" < "$WORK/b")"
expect "  공유 목록 can_manage=True" "200|True" "$(capi GET "/api/pages/$TP/shares" qa_employee01)|$(jget "d['can_manage']" < "$WORK/b")"
expect "  팀원의 후보 찾기 q=둘 → 200 · qa_employee02" "200|qa_employee02" \
  "$(capi GET "/api/share-candidates?page=$TP&q=%EB%91%98" qa_employee01)|$(jget "','.join(c['username'] for c in d['candidates'])" < "$WORK/b")"

A5=$(mark)
expect "팀원이 employee02 에게 **보기** → 200 (5-A 의 400 space_governed 가 풀렸다)" "200" "$(share qa_employee01 "$TP" qa_employee02 view)"
expect "  감사 page.share allow (granted_by=team_member)" "1|team_member" \
  "$(audit_n "$A5" page.share allow '')|$(psqlq "SELECT detail->>'granted_by' FROM audit_logs WHERE id > $A5 AND action='page.share' ORDER BY id DESC LIMIT 1")"
expect "employee02 GET → 200 · granted_by=shared · can_edit=False · can_share=False" "200|shared|False|False" \
  "$(capi GET "/api/pages/$TP" qa_employee02)|$(jget "'%s|%s|%s' % (d['granted_by'], d['can_edit'], d['can_share'])" < "$WORK/b")"
expect "  하위 TPC 도 → 200 (물려받음)" "200" "$(capi GET "/api/pages/$TPC" qa_employee02)"
V=$(pg_page "$TP" "p.doc_version")
python3 - "$BLK" "$V" > "$WORK/doc.json" <<'PY'
import json, sys
doc = {"schema": 1, "blocks": [{"id": sys.argv[1], "type": "paragraph", "text": [{"t": "팀 공유 저장", "m": {}}]}]}
print(json.dumps({"doc_version": int(sys.argv[2]), "doc": doc}, ensure_ascii=False))
PY
expect "  보기 공유자 저장 PATCH → 403" "403" "$(capi PATCH "/api/pages/$TP" qa_employee02 "$WORK/doc.json")"
expect "  공유받은 페이지 목록에 TP 가 있다" "1" \
  "$(capi GET "/api/pages?shared=me" qa_employee02 >/dev/null; jget "sum(1 for x in d['pages'] if x['id']=='$TP')" < "$WORK/b")"

expect "등급을 **편집**으로 → 200" "200" "$(share qa_employee01 "$TP" qa_employee02 edit)"
expect "  편집 공유자 저장 → 200" "200" "$(capi PATCH "/api/pages/$TP" qa_employee02 "$WORK/doc.json")"
expect "  감사 page.update granted_by=shared_edit" "shared_edit" \
  "$(psqlq "SELECT detail->>'granted_by' FROM audit_logs WHERE action='page.update' AND result='allow' ORDER BY id DESC LIMIT 1")"
K2=$(make_page qa_employee02 "5A2-팀-공유자가-만든-하위" "$TP")
expect "  편집 공유자가 하위 만들기 → 201 · 그 하위는 팀 공간에 있다" "201|team" \
  "$(status_of "$WORK/h")|$(psqlq "SELECT s.kind FROM community.pages p JOIN community.spaces s ON s.id=p.space_id WHERE p.public_id='$K2'")"
A6=$(mark)
expect "  편집 공유자 삭제 DELETE → 403" "403" "$(capi DELETE "/api/pages/$TP" qa_employee02)"
expect "    DB: TP 살아 있다" "t" "$(pg_page "$TP" "p.deleted_at IS NULL")"
body '{"parent_id":null}'
expect "  편집 공유자 옮기기(TPC 를 뿌리로) → 403" "403" "$(capi PATCH "/api/pages/$TPC/move" qa_employee02 "$WORK/body.json")"
expect "  편집 공유자가 남에게 다시 공유 → 403" "403" "$(share qa_employee02 "$TP" qa_guest01 view)"
expect "  편집 공유자의 후보 찾기 → 403" "403" "$(capi GET "/api/share-candidates?page=$TP&q=q" qa_employee02)"
body '{"visibility":"internal"}'
V_CODE=$(capi PATCH "/api/pages/$TP/visibility" qa_employee02 "$WORK/body.json")
expect "  편집 공유자 공개 범위 → 거부(4xx)" "yes" "$(case "$V_CODE" in 4*) echo yes;; *) echo "no:$V_CODE";; esac)"
body '{"to":"qa_manager01","keep":"none"}'
H_CODE=$(capi POST "/api/pages/$TP/handover" qa_employee02 "$WORK/body.json")
expect "  편집 공유자 인계 → 거부(4xx) · 팀 공간은 인계 대상 아님" "yes" "$(case "$H_CODE" in 4*) echo yes;; *) echo "no:$H_CODE";; esac)"
expect "  감사 page.delete deny not_member(팀 밖 — 5-A″ 새 줄) · page.share deny not_owner" "1|1" \
  "$(audit_n "$A6" page.delete deny not_member)|$(audit_n "$A6" page.share deny not_owner)"

sub "팀원 아닌 사람이 공유 주기 → 403"
expect "guest01(팀 밖·공유 없음) PUT → 403" "403" "$(share qa_guest01 "$TP" qa_manager01 view)"
expect "admin01(팀 밖 — 볼 수는 있다) PUT → 403" "403" "$(share qa_admin01 "$TP" qa_guest01 view)"
expect "  admin01 GET 은 200 (admin_all)" "200|admin_all" "$(capi GET "/api/pages/$TP" qa_admin01)|$(jget "d['granted_by']" < "$WORK/b")"
expect "팀원 manager01 은 PUT → 200 (관리 자격 = 그 팀원)" "200" "$(share qa_manager01 "$TP" qa_guest01 edit)"
V=$(pg_page "$TP" "p.doc_version")
python3 - "$BLK" "$V" > "$WORK/doc2.json" <<'PY'
import json, sys
doc = {"schema": 1, "blocks": [{"id": sys.argv[1], "type": "paragraph", "text": [{"t": "guest", "m": {}}]}]}
print(json.dumps({"doc_version": int(sys.argv[2]), "doc": doc}, ensure_ascii=False))
PY
expect "  Guest 는 편집 공유를 받아도 GET 200 · 저장 403" "200|403" \
  "$(capi GET "/api/pages/$TP" qa_guest01)|$(capi PATCH "/api/pages/$TP" qa_guest01 "$WORK/doc2.json")"

sub "빼면 그 자리에서 403"
expect "팀원이 employee02 공유를 뺀다 → 200" "200" "$(unshare qa_employee01 "$TP" qa_employee02)"
expect "  employee02 GET TP · TPC → 403 · 403" "403|403" "$(capi GET "/api/pages/$TP" qa_employee02)|$(capi GET "/api/pages/$TPC" qa_employee02)"
expect "대조용으로 다시 보기 → 200" "200" "$(share qa_employee01 "$TP" qa_employee02 view)"

# ════════════════════════════════════════════════════════════════
hr "C:T-65  판정 함수 = 목록 SQL — 전 계정 × 전 페이지 (팀 공간 공유 포함)"

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

$pairs = 0; $bad = 0; $allowed = 0; $sharedHits = 0; $teamShared = 0; $samples = [];
foreach ($users as $r) {
    $u = ['id' => (int) $r['id'], 'username' => (string) $r['username'], 'role' => (string) $r['role'],
          'team_id' => $r['team_id'] === null ? null : (int) $r['team_id']];
    [$where, $bind] = community_visible_pages_where($u);
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
        $pairs++;
        if ($ok) { $allowed++; }
        if ($ok && $code === CommunityAuthz::CODE_SHARED) {
            $sharedHits++;
            if ((string) $row['space_kind'] === 'team') { $teamShared++; }
        }
        if ($ok !== isset($inSql[$pid])) {
            $bad++;
            if (count($samples) < 5) { $samples[] = $u['username'] . ' × ' . $pid . ' (판정=' . ($ok ? $code : 'X:' . $code) . ')'; }
        }
    }
}
echo "PAIRS=$pairs\nALLOWED=$allowed\nSHARED=$sharedHits\nTEAMSHARED=$teamShared\nMISMATCH=$bad\n";
foreach ($samples as $s) { echo "SAMPLE=$s\n"; }
PHP

if [ -n "$DEAD" ]; then
  psqlq "INSERT INTO community.page_shares (page_id, user_id, level, granted_by)
         SELECT p.id, (SELECT id FROM users WHERE username='$DEAD'), 'edit', (SELECT id FROM users WHERE username='qa_employee01')
           FROM community.pages p WHERE p.public_id='$TP'" >/dev/null
  note "(임시 DB) 비활성 계정 $DEAD 에게 팀 페이지 편집 줄 1개 — 효력 0 이어야 한다"
fi
( cd "$INST/app" && php "$WORK/matrix.php" "$INST/app" ) > "$WORK/matrix.txt" 2>&1
sed 's/^/    /' "$WORK/matrix.txt"
expect "전수 대조 — 어긋난 쌍 0" "MISMATCH=0" "$(grep '^MISMATCH=' "$WORK/matrix.txt")"
TS=$(sed -n 's/^TEAMSHARED=//p' "$WORK/matrix.txt")
expect "  팀 공간 공유로 열린 쌍이 대조에 들어 있다 (TEAMSHARED > 0)" "yes" "$([ "${TS:-0}" -gt 0 ] && echo yes || echo no)"
if [ -n "$DEAD" ]; then
  expect "  비활성 계정의 팀 공유 줄은 목록 SQL 에도 없다" "0" \
    "$(psqlq "SELECT count(*) FROM community.pages p JOIN community.spaces s ON s.id=p.space_id
               JOIN community.page_shares sh ON sh.page_id=p.id JOIN users u ON u.id=sh.user_id AND u.is_active
              WHERE p.public_id='$TP' AND u.username='$DEAD'")"
fi

# ════════════════════════════════════════════════════════════════
hr "C:T-66  브라우저 — browser_5a2.py (계정 관리 · 셸 · 사람 추가 · 인계 창 · 이력 · 팀 공간)"

export PATH="$HOME/.local/bin:$PATH"
cookie_of() {
  python3 - "$JAR_DIR/$1.jar" <<'PY'
import sys
for line in open(sys.argv[1], encoding='utf-8', errors='replace'):
    parts = line.rstrip('\n').split('\t')
    if len(parts) == 7 and parts[5] == 'tbox_session':
        print(parts[6]); break
PY
}
if ! python3 -c 'import playwright' 2>/dev/null; then
  skip "브라우저" "playwright 가 없다"
else
  expect "브라우저 앞 Admin 재인증 → 200" "200" "$(reauth_admin)"
  SA=$(cookie_of qa_admin01); S1=$(cookie_of qa_employee01); S2=$(cookie_of qa_employee02)
  SMOKE_ADMIN="$SA" SMOKE_E1="$S1" SMOKE_E2="$S2" SMOKE_P="$P" SMOKE_TP="$TP" SMOKE_N1="$N1" SMOKE_N2="$N2" \
  SMOKE_SHOTS="${SHOTS:-}" python3 "$ROOT/tests/community/browser_5a2.py" "$SITE" "$UA" > "$WORK/smoke.txt" 2>&1
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
bash -n "$ROOT/tests/community/stage5a2.sh" && expect "stage5a2.sh 문법" "0" "0"
python3 -c "import ast,io;ast.parse(io.open('$ROOT/tests/community/browser_5a2.py',encoding='utf-8').read())" \
  && expect "browser_5a2.py 문법" "0" "0"
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
  for s in ${REGRESS_LIST:-community/stage3 community/stage4a community/stage4b community/stage4c stage26 help_button api_whoami api_read api_folders api_upload api_tokens f65 f66 f68}; do
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
