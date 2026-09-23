#!/usr/bin/env bash
# tests/community/stage5a.sh — 커뮤니티 5-A 수락 기준 C:T-53 ~ C:T-60 실행
#                              (../../../community/stage5a-owner-share-directive.md 「완료 기준」)
#
#   C:T-53 관리 분리     **편집 공유자**가 삭제 · 옮기기 · 되살리기 · 공개 범위 · 공유(추가·남의 줄 빼기) ·
#                        인계 · 후보 목록 → 핸들러마다 403 (+ 감사) · DB 불변. 하위 페이지 만들기는 201
#   C:T-54 내용 고치기   보기 공유자의 저장 · 할 일 체크 · 상태 · 하위 만들기 · 편집 신호 → 403 ·
#                        편집 공유자는 200 · Guest 는 편집 공유를 받아도 저장 403
#   C:T-55 하위 전파     부모에 준 보기 → 손자 GET 200 · 공유를 빼면 **그 자리에서** 403 ·
#                        경로 줄에 공유하지 않은 조상의 제목 0
#   C:T-56 판정 = SQL    전 계정 × 전 페이지 조합에서 `decidePageRead` 와 목록 조건절이 **한 쌍도**
#                        어긋나지 않는다 (C:T-37 방식의 전수 대조) · `?shared=me` 수 = psql 로 센 수 ·
#                        하위 목록 수 = psql 로 센 수
#   C:T-57 비활성 계정   비활성 계정에게 준 공유는 효력 0 (창구로는 줄 수도 없다 — 400)
#   C:T-58 인계          다른 팀 200 · Guest·비활성·주인 자신 400 · keep 없으면 400 · 하위까지 새 공간 뿌리로 ·
#                        옛 공간 목록에서 사라짐 · 「보기」면 옛 주인 GET 200 · PATCH 403 · 「없음」이면 GET 403 ·
#                        이력·체크 기록 불변 · 받는 사람의 옛 공유 삭제 · 공간 없는 사람에게는 공간을 만든다 ·
#                        Manager 는 자기 팀원 것만 · Admin 은 재인증 없이 403 · 팀 공간 400
#   C:T-59 기록 불변     앱 계정으로 `handovers` UPDATE · DELETE · TRUNCATE → 42501 (page_shares 는 SIUD)
#   C:T-60 담당자 멈춤   `PATCH …/properties {assignee}` 400 · `GET /api/assignees` 404 · 옛 담당자 기록은 이력에 남음
#   브라우저             browser_smoke.py 33~35 (사람 추가·등급 바꾸기·빼기 · 보기 공유자 화면 · 인계 창)
#   회귀 (REGRESS=1)     같은 임시 인스턴스에서 stage3 · 4a · 4b · 4c 와 t-box stage26 을 돌린다
#
# ══════════════════════════════════════════════════════════════════════════
# ★ 이 시험은 **운영 DB 를 한 글자도 건드리지 않는다.** (stage4d.sh 와 같은 방식)
#
#   5-A 코드는 마이그레이션(`db/migrations/2026-09-23_community_owner_share.sql`)이 만든 표를
#   **읽는다** — 페이지 한 건을 읽는 질의부터 `page_shares` 를 본다. 그래서
#     ① 운영 DB(`tbox_re`)를 **읽어서**(pg_dump) 임시 DB `tbox_5a_test` 를 만들고
#     ② 거기에만 4-D·5-A 마이그레이션과 `db/community/grants.sql` 을 적용하고
#     ③ 저장소를 임시 폴더로 복사해 **자기 `.env`**(DB 이름·저장 경로·로그 경로만 다르다)를 주고
#     ④ `php -S 127.0.0.1:8084` 로 **두 번째 인스턴스**를 띄워 그쪽에만 요청을 보낸다.
#     ⑤ 끝나면 서버를 내리고 임시 DB 와 임시 폴더를 **지운다**.
#   운영(8082)에는 요청을 한 번도 보내지 않는다. 운영 적용은 메인 세션이 한다.
# ══════════════════════════════════════════════════════════════════════════
#
# 규칙 (CLAUDE.md 3장) — stage4d.sh 와 같다
#   · 검토 계정 `qa_` 다섯뿐(로그인은 **순차** — TOTP 30초 창). 시드 계정은 사용자 몫.
#   · 모든 요청에 `-A "tbox-review/<날짜>"`.
#   · `sudo -n` 은 **읽기**(pg_dump · seed 파일)와 **임시 DB** 에만 쓴다.
#   · 임시 DB 에만 psql 로 직접 넣는 줄이 둘 있다 — 비활성 계정의 공유 줄(C:T-57, 창구가 막으므로)과
#     공간 없는 수신자 계정 하나(C:T-58). 둘 다 임시 DB 와 함께 사라진다.
#
# 준비물: curl · php · python3 · sudo -n(seed-accounts.txt · qa-totp.txt · psql · pg_dump) · playwright
# 사용: sudo -v && bash tests/community/stage5a.sh            (REGRESS=1 이면 회귀까지, SHOTS=<폴더> 면 스크린샷)
set -u

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
PORT=8084
SITE="http://127.0.0.1:$PORT"
BASE="$SITE/tbox"
CBASE="$SITE/community"
UA="tbox-review/$(date +%F)"
SRC_DB="tbox_re"          # 운영 — **읽기만** 한다
DB="tbox_5a_test"         # 임시 — 여기에만 쓴다
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
hr "커뮤니티 5-A — 임시 인스턴스 준비 (운영 DB 에는 쓰지 않는다)"

avail_kb=$(df -Pk / | awk 'NR==2{print $4}')
echo "루트 파티션 여유: $(df -Ph / | awk 'NR==2{print $4}') (${avail_kb}KB)"
[ "$avail_kb" -ge 1048576 ] || { echo "루트 디스크 여유 1GB 미만 — 시험을 시작하지 않는다 (SECURITY R-80)"; exit 3; }

sudo -n -u postgres psql -d "$SRC_DB" -tAc "SELECT 1" >/dev/null 2>&1 \
  || { echo "psql(postgres) 을 쓸 수 없다 — 이 시험은 임시 DB 가 없으면 성립하지 않는다"; exit 3; }

sub "① 운영 DB 를 **읽어** 임시 DB 를 만든다"
DUMP="/tmp/tbox5a_dump.$$.sql"
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

sub "② 마이그레이션 + 권한 (임시 DB 에만) — 4-D(이미 있으면 그대로) · 5-A 를 **두 번** 돌려 멱등을 본다"
# 5-A″ — 이름 칸(`users.display_name`)도 먼저 적용한다. 5-A″ 이후의 코드는 세션 질의부터 그 칸을 읽는다.
for mig in 2026-09-23_community_page_properties.sql 2026-09-23_community_owner_share.sql 2026-09-23_community_owner_share.sql \
           2026-09-23_users_display_name.sql \
           2026-09-24_community_boards.sql \
           2026-09-24_community_discussion.sql 2026-09-25_community_databases.sql 2026-09-26_community_purge.sql 2026-09-27_community_templates.sql; do   # 5-C·5-D — 코드가 새 칸·표를 읽는다
  echo "    ▸ $mig"
  sudo -n -u postgres psql -d "$DB" -v ON_ERROR_STOP=1 -f - < "$ROOT/db/migrations/$mig" 2>&1 \
    | grep -v 'could not change directory' | sed 's/^/      /'
done
# 파일은 **표준 입력으로** 넘긴다 — postgres 계정이 저장소 폴더를 읽지 못해도(권한 700) 된다.
sudo -n -u postgres psql -q -d "$DB" -v ON_ERROR_STOP=1 -f - < "$ROOT/db/community/grants.sql" 2>&1 \
  | grep -v 'could not change directory' | sed 's/^/    /'
echo "  · 새 표의 앱 계정 권한 (handovers 는 r=SELECT · a=INSERT 만이어야 한다)"
psqlq "SELECT c.relname || ' : ' || coalesce(array_to_string(c.relacl, ' '), '(없음)')
         FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
        WHERE n.nspname='community' AND c.relname IN ('page_shares','handovers')
        ORDER BY c.relname" | sed 's/^/    /'
expect "마이그레이션 두 번 뒤에도 표는 하나씩 (멱등)" "2" \
  "$(psqlq "SELECT count(*) FROM pg_tables WHERE schemaname='community' AND tablename IN ('page_shares','handovers')")"
expect "pages.assignee_id 칸은 그대로 있다 (열 삭제 0)" "1" \
  "$(psqlq "SELECT count(*) FROM information_schema.columns WHERE table_schema='community' AND table_name='pages' AND column_name='assignee_id'")"

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
hr "준비 — employee01 의 비공개 가지 R › C › G 와 편집 공유 (R → employee02 edit)"

R=$(make_page qa_employee01 "5A-뿌리")
C=$(make_page qa_employee01 "5A-자식" "$R")
G=$(make_page qa_employee01 "5A-손자" "$C")
[ -n "$R" ] && [ -n "$C" ] && [ -n "$G" ] || { echo "시험 페이지를 만들지 못했다 — 중단"; exit 4; }
note "R=$R · C=$C · G=$G (셋 다 비공개)"
expect "공유 전 employee02 는 R 을 못 본다 (403)" "403" "$(capi GET "/api/pages/$R" qa_employee02)"

A0=$(mark)
expect "employee01 이 R 을 employee02 에게 **편집**으로 → 200" "200" "$(share qa_employee01 "$R" qa_employee02 edit)"
expect "  응답 changed=True" "True" "$(jget "d['changed']" < "$WORK/b")"
expect "  감사 page.share allow 한 줄" "1" "$(audit_n "$A0" page.share allow '')"
expect "  같은 등급을 다시 보내면 200 · changed=False" "200|False" \
  "$(share qa_employee01 "$R" qa_employee02 edit)|$(jget "d['changed']" < "$WORK/b")"
expect "  감사도 늘지 않는다" "1" "$(audit_n "$A0" page.share allow '')"
expect "employee01 이 R 을 guest 에게 보기로 (남의 줄 빼기 시험용)" "200" "$(share qa_employee01 "$R" qa_guest01 view)"

for bad in '{"level":"admin"}' '{"level":"VIEW"}' '{}' '{"level":"view","x":1}' '{"level":1}'; do
  body "$bad"
  expect "공유 허용 밖 값 $bad → 400" "400" "$(capi PUT "/api/pages/$R/shares/qa_manager01" qa_employee01 "$WORK/body.json")"
done
expect "주인 자신에게 공유 → 400" "400" "$(share qa_employee01 "$R" qa_employee01 view)"
expect "  사유 target_not_permitted" "target_not_permitted" "$(jget "d['reason']" < "$WORK/b")"
expect "없는 계정에게 공유 → 400" "400" "$(share qa_employee01 "$R" "nobody_$$" view)"
expect "형태가 아닌 계정명(대문자) → 라우트 밖 404" "404" "$(share qa_employee01 "$R" "QA_EMP" view)"

# ════════════════════════════════════════════════════════════════
hr "C:T-53  편집 공유자의 **관리** — 핸들러마다 403 · DB 불변"

expect "편집 공유자 employee02 가 R 을 읽는다 → 200" "200" "$(capi GET "/api/pages/$R" qa_employee02)"
expect "  granted_by = shared" "shared" "$(jget "d['granted_by']" < "$WORK/b")"
expect "  can_edit=True · can_manage=False · can_share=False · can_handover=False" "True|False|False|False" \
  "$(jget "'%s|%s|%s|%s' % (d['can_edit'], d['can_manage'], d['can_share'], d['can_handover'])" < "$WORK/b")"
expect "  share_level = edit" "edit" "$(jget "d['share_level']" < "$WORK/b")"
expect "  소유자는 employee01" "qa_employee01" "$(jget "d['page']['owner']['username']" < "$WORK/b")"

A1=$(mark)
expect "① 삭제 DELETE → 403" "403" "$(capi DELETE "/api/pages/$R" qa_employee02)"
expect "   DB 에서 R 이 살아 있다" "t" "$(pg_page "$R" "p.deleted_at IS NULL")"
expect "   감사 page.delete deny not_owner" "1" "$(audit_n "$A1" page.delete deny not_owner)"

body '{"parent_id":null}'
expect "② 옮기기(C 를 뿌리로) → 403" "403" "$(capi PATCH "/api/pages/$C/move" qa_employee02 "$WORK/body.json")"
expect "   C 의 부모는 그대로 R" "$R" "$(psqlq "SELECT par.public_id FROM community.pages p JOIN community.pages par ON par.id = p.parent_page_id WHERE p.public_id='$C'")"

# 되살리기 — employee01 이 지운 하위를 employee02 가 되살리려 한다.
X=$(make_page qa_employee01 "5A-지울-하위" "$R")
expect "   (준비) 주인이 하위 X 를 지운다 → 200" "200" "$(capi DELETE "/api/pages/$X" qa_employee01)"
body '{}'
expect "③ 되살리기 POST …/restore → 403" "403" "$(capi POST "/api/pages/$X/restore" qa_employee02 "$WORK/body.json")"
expect "   X 는 아직 휴지통에 있다" "t" "$(pg_page "$X" "p.deleted_at IS NOT NULL")"
expect "   감사 page.restore deny not_owner" "1" "$(audit_n "$A1" page.restore deny not_owner)"

body '{"visibility":"internal"}'
expect "④ 공개 범위 PATCH → 403" "403" "$(capi PATCH "/api/pages/$R/visibility" qa_employee02 "$WORK/body.json")"
expect "   범위는 그대로 private" "private" "$(pg_page "$R" "p.visibility")"
expect "   감사 page.visibility deny not_owner" "1" "$(audit_n "$A1" page.visibility deny not_owner)"

expect "⑤ 공유 추가(남에게 다시 열어 주기) PUT → 403" "403" "$(share qa_employee02 "$R" qa_manager01 view)"
expect "   manager01 줄이 생기지 않았다" "0" \
  "$(psqlq "SELECT count(*) FROM community.page_shares sh JOIN users u ON u.id=sh.user_id JOIN community.pages p ON p.id=sh.page_id WHERE p.public_id='$R' AND u.username='qa_manager01'")"
expect "⑤' 공유 등급 올리기(자기 줄 edit→edit 도 관리다) PUT → 403" "403" "$(share qa_employee02 "$R" qa_employee02 edit)"
expect "⑤'' 남의 공유 빼기 DELETE …/shares/qa_guest01 → 403" "403" "$(unshare qa_employee02 "$R" qa_guest01)"
expect "   guest 줄은 그대로" "1" \
  "$(psqlq "SELECT count(*) FROM community.page_shares sh JOIN users u ON u.id=sh.user_id JOIN community.pages p ON p.id=sh.page_id WHERE p.public_id='$R' AND u.username='qa_guest01'")"
expect "   감사 page.share deny not_owner 세 줄" "3" "$(audit_n "$A1" page.share deny not_owner)"

body '{"to":"qa_manager01","keep":"none"}'
expect "⑥ 인계 POST …/handover → 403" "403" "$(capi POST "/api/pages/$R/handover" qa_employee02 "$WORK/body.json")"
expect "   R 의 공간은 그대로 employee01 의 것" "qa_employee01" \
  "$(psqlq "SELECT u.username FROM community.pages p JOIN community.spaces s ON s.id=p.space_id JOIN users u ON u.id=s.owner_user_id WHERE p.public_id='$R'")"
expect "   감사 page.handover deny not_owner" "1" "$(audit_n "$A1" page.handover deny not_owner)"
expect "⑦ 공유 후보 목록 → 403" "403" "$(capi GET "/api/share-candidates?page=$R&q=qa" qa_employee02)"
expect "⑦' 인계 후보 목록 → 403" "403" "$(capi GET "/api/share-candidates?for=handover&page=$R" qa_employee02)"

sub "편집 공유자의 **하위 페이지 만들기**는 권한대로 된다 (사용자 결정 3) — 주인은 공간의 주인"
K=$(make_page qa_employee02 "5A-공유자가-만든-하위" "$R")
expect "employee02 가 R 아래에 하위를 만든다 → 201" "201" "$(status_of "$WORK/h")"
expect "  그 하위는 employee01 의 공간에 있다" "qa_employee01" \
  "$(psqlq "SELECT u.username FROM community.pages p JOIN community.spaces s ON s.id=p.space_id JOIN users u ON u.id=s.owner_user_id WHERE p.public_id='$K'")"
expect "  작성자는 employee02" "qa_employee02" \
  "$(psqlq "SELECT u.username FROM community.pages p JOIN users u ON u.id=p.author_id WHERE p.public_id='$K'")"
expect "  범위는 부모와 같다(private)" "private" "$(pg_page "$K" "p.visibility")"
expect "  감사 page.create 의 granted_by = shared_edit" "shared_edit" \
  "$(psqlq "SELECT detail->>'granted_by' FROM audit_logs WHERE action='page.create' AND target_name='5A-공유자가-만든-하위' ORDER BY id DESC LIMIT 1")"

# ════════════════════════════════════════════════════════════════
hr "C:T-54  내용 고치기 — 보기 403 · 편집 200 · Guest 편집 공유도 저장 403"

P2=$(make_page qa_employee01 "5A-보기-편집-시험")
BLK=$(python3 -c 'import uuid;print(uuid.uuid4())')
doc_body() {   # $1 doc_version $2 checked(true|false)
  python3 - "$BLK" "$1" "$2" > "$WORK/doc.json" <<'PY'
import json, sys
doc = {"schema": 1, "blocks": [{"id": sys.argv[1], "type": "to_do", "checked": sys.argv[3] == "true",
                                "text": [{"t": "5A 할 일", "m": {}}]}]}
print(json.dumps({"doc_version": int(sys.argv[2]), "doc": doc}, ensure_ascii=False))
PY
}
doc_body 1 false
expect "주인이 할 일 한 줄로 저장 (판 1→2)" "200" "$(capi PATCH "/api/pages/$P2" qa_employee01 "$WORK/doc.json")"
expect "manager01 에게 **보기** 공유 → 200" "200" "$(share qa_employee01 "$P2" qa_manager01 view)"
expect "employee02 에게 **편집** 공유 → 200" "200" "$(share qa_employee01 "$P2" qa_employee02 edit)"
expect "guest 에게 **편집** 공유 → 200 (받을 수는 있다)" "200" "$(share qa_employee01 "$P2" qa_guest01 edit)"

sub "보기 공유자 (manager01 — 주인과 같은 팀이지만 페이지는 비공개)"
expect "GET → 200 · can_edit=False · granted_by=shared" "200|False|shared" \
  "$(capi GET "/api/pages/$P2" qa_manager01)|$(jget "d['can_edit']" < "$WORK/b")|$(jget "d['granted_by']" < "$WORK/b")"
V0=$(pg_page "$P2" "p.doc_version")
doc_body "$V0" true
expect "저장(할 일 체크) PATCH → 403" "403" "$(capi PATCH "/api/pages/$P2" qa_manager01 "$WORK/doc.json")"
body '{"status":"doing"}'
expect "상태 PATCH …/properties → 403" "403" "$(capi PATCH "/api/pages/$P2/properties" qa_manager01 "$WORK/body.json")"
python3 -c "import json;print(json.dumps({'title':'보기-하위','parent_id':'$P2'}))" > "$WORK/new.json"
expect "하위 만들기 POST → 403" "403" "$(capi POST /api/pages qa_manager01 "$WORK/new.json")"
body '{}'
expect "편집 중 신호 POST …/heartbeat → 403" "403" "$(capi POST "/api/pages/$P2/heartbeat" qa_manager01 "$WORK/body.json")"
expect "지난 판 되돌리기 POST …/versions/1/restore → 403" "403" "$(capi POST "/api/pages/$P2/versions/1/restore" qa_manager01 "$WORK/body.json")"
expect "  DB: 판 번호·체크·상태 불변" "$V0|false|" \
  "$(pg_page "$P2" "p.doc_version")|$(psqlq "SELECT (p.doc->'blocks'->0->>'checked') FROM community.pages p WHERE p.public_id='$P2'")|$(pg_page "$P2" "coalesce(p.status,'')")"

sub "편집 공유자 (employee02)"
doc_body "$V0" true
expect "저장(할 일 체크) PATCH → 200" "200" "$(capi PATCH "/api/pages/$P2" qa_employee02 "$WORK/doc.json")"
expect "  체크 기록이 employee02 이름으로 남았다" "qa_employee02" \
  "$(psqlq "SELECT u.username FROM community.checklist_events e JOIN users u ON u.id=e.by_user_id JOIN community.pages p ON p.id=e.page_id WHERE p.public_id='$P2' ORDER BY e.id DESC LIMIT 1")"
body '{"status":"review"}'
expect "상태 PATCH → 200" "200" "$(capi PATCH "/api/pages/$P2/properties" qa_employee02 "$WORK/body.json")"
body '{}'
expect "편집 중 신호 → 200" "200" "$(capi POST "/api/pages/$P2/heartbeat" qa_employee02 "$WORK/body.json")"
expect "지난 판 되돌리기(판 1) → 200" "200" "$(capi POST "/api/pages/$P2/versions/1/restore" qa_employee02 "$WORK/body.json")"
expect "감사 page.update 의 granted_by = shared_edit" "shared_edit" \
  "$(psqlq "SELECT detail->>'granted_by' FROM audit_logs WHERE action='page.update' AND result='allow' ORDER BY id DESC LIMIT 1")"

sub "Guest 는 편집 공유를 받아도 **보기만** (쓰기 자격 없는 역할)"
expect "guest GET → 200 · can_edit=False" "200|False" \
  "$(capi GET "/api/pages/$P2" qa_guest01)|$(jget "d['can_edit']" < "$WORK/b")"
V1=$(pg_page "$P2" "p.doc_version")
doc_body "$V1" false
expect "guest 저장 PATCH → 403" "403" "$(capi PATCH "/api/pages/$P2" qa_guest01 "$WORK/doc.json")"
expect "  판 번호 불변" "$V1" "$(pg_page "$P2" "p.doc_version")"

# ════════════════════════════════════════════════════════════════
hr "C:T-55  공유는 하위에 미친다 — 부모에 준 보기 → 손자 200 · 빼면 그 자리에서 403"

expect "공유 전 manager01 은 손자 G 를 못 본다" "403" "$(capi GET "/api/pages/$G" qa_manager01)"
expect "R 을 manager01 에게 보기로 → 200" "200" "$(share qa_employee01 "$R" qa_manager01 view)"
expect "  손자 G GET → 200 (조상에서 물려받음)" "200" "$(capi GET "/api/pages/$G" qa_manager01)"
expect "  G 의 share_level = view · can_edit=False" "view|False" "$(jget "'%s|%s' % (d['share_level'], d['can_edit'])" < "$WORK/b")"
code=$(capi GET "/api/pages/$G/shares" qa_manager01)
expect "  G 의 공유 목록 — 보기 공유자에게는 **수만** (shares=None · count≥1)" "200|True|True" \
  "$code|$(jget "d['shares'] is None" < "$WORK/b")|$(jget "d['count'] >= 1" < "$WORK/b")"
expect "  주인에게는 물려받은 줄까지 이름이 보인다" "True" \
  "$(capi GET "/api/pages/$G/shares" qa_employee01 >/dev/null; jget "any(s['username']=='qa_manager01' and s['inherited'] for s in d['shares'])" < "$WORK/b")"

sub "경로 줄 — 공유받은 조상까지만 (공유하지 않은 제목이 새지 않는다)"
expect "employee02(R 편집 공유)가 G 를 열면 경로는 R › C › G 셋" "3" \
  "$(capi GET "/api/pages/$G" qa_employee02 >/dev/null; jget "len(d['breadcrumb'])" < "$WORK/b")"
H0=$(make_page qa_employee01 "5A-비밀-부모")
H0C=$(make_page qa_employee01 "5A-공유-자식" "$H0")
expect "자식만 employee02 에게 보기 공유 → 200" "200" "$(share qa_employee01 "$H0C" qa_employee02 view)"
expect "  자식 GET → 200" "200" "$(capi GET "/api/pages/$H0C" qa_employee02)"
expect "  경로 줄은 자기 하나뿐" "1" "$(jget "len(d['breadcrumb'])" < "$WORK/b")"
lacks  "  응답 원문에 비공개 부모 제목 0" "5A-비밀-부모" "$(cat "$WORK/b")"
expect "  부모 GET 은 여전히 403" "403" "$(capi GET "/api/pages/$H0" qa_employee02)"

sub "공유를 빼면 **그 자리에서** 403"
expect "주인이 R 의 manager01 공유를 뺀다 → 200" "200" "$(unshare qa_employee01 "$R" qa_manager01)"
expect "  손자 G GET → 403" "403" "$(capi GET "/api/pages/$G" qa_manager01)"
expect "  R GET → 403" "403" "$(capi GET "/api/pages/$R" qa_manager01)"
expect "  감사 page.share allow 에 뺀 줄(share_level null)" "view|" \
  "$(psqlq "SELECT (detail->>'share_from') || '|' || coalesce(detail->>'share_level','') FROM audit_logs WHERE action='page.share' AND result='allow' ORDER BY id DESC LIMIT 1")"

sub "받은 사람이 **자기 공유**를 뺀다 (관리 자격 없이)"
expect "guest 가 P2 의 자기 공유를 뺀다 → 200" "200" "$(unshare qa_guest01 "$P2" qa_guest01)"
expect "  guest P2 GET → 403" "403" "$(capi GET "/api/pages/$P2" qa_guest01)"
expect "  다시 빼면 404 (뺄 줄이 없다)" "404" "$(unshare qa_employee01 "$P2" qa_guest01)"

# ════════════════════════════════════════════════════════════════
hr "C:T-56  판정 함수 = 목록 SQL — 전 계정 × 전 페이지 전수 대조 (C:T-37 방식)"

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

$pairs = 0; $bad = 0; $allowed = 0; $sharedHits = 0; $samples = [];
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
        if ($ok && $code === CommunityAuthz::CODE_SHARED) { $sharedHits++; }
        if ($ok !== isset($inSql[$pid])) {
            $bad++;
            if (count($samples) < 5) { $samples[] = $u['username'] . ' × ' . $pid . ' (판정=' . ($ok ? $code : 'X:' . $code) . ')'; }
        }
    }
}
echo "PAIRS=$pairs\nALLOWED=$allowed\nSHARED=$sharedHits\nMISMATCH=$bad\n";
foreach ($samples as $s) { echo "SAMPLE=$s\n"; }
PHP

sub "비활성 계정에게 준 공유는 효력 0 (C:T-57 을 대조에 넣는다 — 임시 DB 에 한 줄 직접 넣는다)"
if [ -n "$DEAD" ]; then
  expect "창구로는 비활성 계정에게 줄 수 없다 → 400" "400" "$(share qa_employee01 "$R" "$DEAD" view)"
  psqlq "INSERT INTO community.page_shares (page_id, user_id, level, granted_by)
         SELECT p.id, (SELECT id FROM users WHERE username='$DEAD'), 'edit', (SELECT id FROM users WHERE username='qa_employee01')
           FROM community.pages p WHERE p.public_id='$R'" >/dev/null
  expect "  (임시 DB) 비활성 계정의 편집 줄 1개가 있다" "1" \
    "$(psqlq "SELECT count(*) FROM community.page_shares sh JOIN users u ON u.id=sh.user_id WHERE u.username='$DEAD'")"
else
  skip "비활성 계정" "임시 DB 에 비활성 계정이 없다"
fi

( cd "$INST/app" && php "$WORK/matrix.php" "$INST/app" ) > "$WORK/matrix.txt" 2>&1
sed 's/^/    /' "$WORK/matrix.txt"
expect "전수 대조 — 어긋난 쌍 0" "MISMATCH=0" "$(grep '^MISMATCH=' "$WORK/matrix.txt")"
contains "  대조한 쌍이 있다" "PAIRS=" "$(grep '^PAIRS=' "$WORK/matrix.txt")"
contains "  공유 갈래로 열린 쌍이 대조에 들어 있다" "SHARED=" "$(grep '^SHARED=' "$WORK/matrix.txt")"
expect "  공유 갈래로 열린 쌍이 0 이 아니다" "no" "$(grep -q '^SHARED=0$' "$WORK/matrix.txt" && echo yes || echo no)"
if [ -n "$DEAD" ]; then
  cat > "$WORK/dead.php" <<'PHP'
<?php
declare(strict_types=1);
$app = $argv[1];
require $app . '/scripts/lib/cli_bootstrap.php';
require_once $app . '/src/community/routes.php';
$r = Db::instance()->run('SELECT id, username, role, team_id FROM public.users WHERE username = :n', [':n' => $argv[2]])->fetch();
$u = ['id' => (int) $r['id'], 'role' => (string) $r['role'], 'team_id' => $r['team_id'] === null ? null : (int) $r['team_id']];
$row = community_page_row($argv[3], (int) $u['id']);
[$ok, $code] = CommunityAuthz::decidePageRead($u, $row);
[$ch]        = CommunityAuthz::decidePageChange($u, $row);
echo ($ok ? 'READ' : 'NOREAD') . '|' . $code . '|' . ($ch ? 'EDIT' : 'NOEDIT') . '|' . var_export($row['share_level'], true) . "\n";
PHP
  expect "  비활성 계정 × R — 읽기 거짓 · 고치기 거짓 · 등급 없음" "NOREAD|not_owner|NOEDIT|NULL" \
    "$( cd "$INST/app" && php "$WORK/dead.php" "$INST/app" "$DEAD" "$R" 2>&1 | tail -1)"
fi

sub "`?shared=me` — 응답 수 = psql 로 센 수 (SQL WHERE)"
for who in qa_employee02 qa_manager01 qa_guest01; do
  capi GET "/api/pages?shared=me" "$who" > /dev/null
  api_n=$(jget "d['total']" < "$WORK/b")
  db_n=$(psqlq "SELECT count(*) FROM community.page_shares sh
                  JOIN community.pages p ON p.id = sh.page_id
                  JOIN users u ON u.id = sh.user_id
                 WHERE u.username = '$who' AND u.is_active AND p.deleted_at IS NULL
                   AND p.visibility IN ('private','team','internal')")
  expect "$who 의 공유받은 페이지 수 = psql" "$db_n" "$api_n"
done
capi GET "/api/pages?shared=me" qa_employee02 > /dev/null
contains "  employee02 의 묶음에 R 이 있다" "$R" "$(jget "','.join(p['id'] for p in d['pages'])" < "$WORK/b")"
expect "  R 의 줄은 편집 공유 · can_edit=True · 공간 이름은 employee01 의 공간" "edit|True|qa_employee01 의 공간" \
  "$(jget "[ '%s|%s|%s' % (p['share_level'], p['can_edit'], p['space']['name']) for p in d['pages'] if p['id']=='$R'][0]" < "$WORK/b")"
expect "  H0C 의 줄은 보기 공유 · can_edit=False" "view|False" \
  "$(jget "[ '%s|%s' % (p['share_level'], p['can_edit']) for p in d['pages'] if p['id']=='$H0C'][0]" < "$WORK/b")"
lacks "  묶음 원문에 공유하지 않은 부모 제목 0" "5A-비밀-부모" "$(cat "$WORK/b")"

sub "하위 목록 — 공유받은 사람이 R 을 열면 볼 수 있는 하위만 (psql 과 같은 수)"
capi GET "/api/pages/$R" qa_employee02 > /dev/null
expect "  employee02 가 받은 R 의 하위 수 = psql (지운 X 제외)" \
  "$(psqlq "SELECT count(*) FROM community.pages c JOIN community.pages p ON p.id=c.parent_page_id WHERE p.public_id='$R' AND c.deleted_at IS NULL")" \
  "$(jget "len(d['children'])" < "$WORK/b")"
expect "공유받은 사람도 남의 **공간 목록**은 여전히 403 (C:T-35 그대로)" "403" \
  "$(capi GET "/api/pages?space=$(psqlq "SELECT space_id FROM community.pages WHERE public_id='$R'")&tree=1" qa_employee02)"

# ════════════════════════════════════════════════════════════════
hr "C:T-58  인계 — 다른 팀 · 하위까지 · 남길 권한 · 이력 불변"

HV=$(make_page qa_employee01 "5A-인계-보기")
HV1=$(make_page qa_employee01 "5A-인계-보기-하위" "$HV")
doc_body 1 false
capi PATCH "/api/pages/$HV" qa_employee01 "$WORK/doc.json" > /dev/null
doc_body 2 true
capi PATCH "/api/pages/$HV" qa_employee01 "$WORK/doc.json" > /dev/null
body '{"status":"doing"}'
capi PATCH "/api/pages/$HV/properties" qa_employee01 "$WORK/body.json" > /dev/null
count_hist() { psqlq "SELECT (SELECT count(*) FROM community.page_versions v JOIN community.pages p ON p.id=v.page_id WHERE p.public_id='$1')
                        || '|' || (SELECT count(*) FROM community.checklist_events e JOIN community.pages p ON p.id=e.page_id WHERE p.public_id='$1')
                        || '|' || (SELECT count(*) FROM community.page_property_events e JOIN community.pages p ON p.id=e.page_id WHERE p.public_id='$1')
                        || '|' || (SELECT u.username FROM community.pages p JOIN users u ON u.id=p.author_id WHERE p.public_id='$1')"; }
HIST0=$(count_hist "$HV")
note "인계 전 기록(판|체크|속성|작성자) = $HIST0"

sub "형식 — 남길 권한은 **필수**, 기본값 없음"
for bad in '{"to":"qa_employee02"}' '{"to":"qa_employee02","keep":"all"}' '{"to":"qa_employee02","keep":null}' \
           '{"keep":"view"}' '{"to":"qa_employee02","keep":"view","x":1}' '{}'; do
  body "$bad"
  expect "$bad → 400" "400" "$(capi POST "/api/pages/$HV/handover" qa_employee01 "$WORK/body.json")"
done

sub "받는 사람 — Guest · 비활성 · 주인 자신 · 없는 계정 → 400 + 감사"
A2=$(mark)
for who in qa_guest01 ${DEAD:+"$DEAD"} qa_employee01 "nobody_$$"; do
  body "{\"to\":\"$who\",\"keep\":\"view\"}"
  expect "→ $who : 400 target_not_permitted" "400|target_not_permitted" \
    "$(capi POST "/api/pages/$HV/handover" qa_employee01 "$WORK/body.json")|$(jget "d['reason']" < "$WORK/b")"
done
expect "  감사 page.handover deny target_not_permitted" "$([ -n "$DEAD" ] && echo 4 || echo 3)" "$(audit_n "$A2" page.handover deny target_not_permitted)"
expect "  페이지는 그대로 employee01 공간" "qa_employee01" \
  "$(psqlq "SELECT u.username FROM community.pages p JOIN community.spaces s ON s.id=p.space_id JOIN users u ON u.id=s.owner_user_id WHERE p.public_id='$HV'")"

sub "다른 팀(employee02)에게 「보기」를 남기고 넘긴다"
share qa_employee01 "$HV1" qa_employee02 edit > /dev/null   # 받는 사람이 미리 받아 둔 공유 — 인계 뒤 지워져야 한다
A3=$(mark)
body '{"to":"qa_employee02","keep":"view"}'
expect "인계 → 200" "200" "$(capi POST "/api/pages/$HV/handover" qa_employee01 "$WORK/body.json")"
expect "  응답 page_count=2 · kept=view" "2|view" "$(jget "'%s|%s' % (d['page_count'], d['kept'])" < "$WORK/b")"
E2SPACE=$(psqlq "SELECT id FROM community.spaces WHERE kind='personal' AND owner_user_id=(SELECT id FROM users WHERE username='qa_employee02')")
expect "  뿌리 HV 는 employee02 공간의 **뿌리**" "$E2SPACE|t" "$(pg_page "$HV" "p.space_id")|$(pg_page "$HV" "p.parent_page_id IS NULL")"
expect "  하위 HV1 도 employee02 공간 · 부모는 HV" "$E2SPACE|$HV" \
  "$(psqlq "SELECT p.space_id || '|' || par.public_id FROM community.pages p JOIN community.pages par ON par.id=p.parent_page_id WHERE p.public_id='$HV1'")"
expect "  기록(판|체크|속성|작성자)이 그대로" "$HIST0" "$(count_hist "$HV")"
expect "  받는 사람의 옛 공유 줄은 지워졌다" "0" \
  "$(psqlq "SELECT count(*) FROM community.page_shares sh JOIN users u ON u.id=sh.user_id JOIN community.pages p ON p.id=sh.page_id WHERE u.username='qa_employee02' AND p.public_id IN ('$HV','$HV1')")"
expect "  옛 주인에게 보기 한 줄(뿌리에)" "view" \
  "$(psqlq "SELECT sh.level FROM community.page_shares sh JOIN users u ON u.id=sh.user_id JOIN community.pages p ON p.id=sh.page_id WHERE u.username='qa_employee01' AND p.public_id='$HV'")"
expect "  handovers 한 줄 (옛→새·누가·쪽수·남긴 권한)" "qa_employee01|qa_employee02|qa_employee01|2|view" \
  "$(psqlq "SELECT f.username||'|'||t.username||'|'||b.username||'|'||h.page_count||'|'||coalesce(h.kept_level,'') FROM community.handovers h JOIN users f ON f.id=h.from_user_id JOIN users t ON t.id=h.to_user_id JOIN users b ON b.id=h.by_user_id JOIN community.pages p ON p.id=h.root_page_id WHERE p.public_id='$HV' ORDER BY h.id DESC LIMIT 1")"
expect "  감사 page.handover allow 한 줄" "1" "$(audit_n "$A3" page.handover allow '')"

capi GET "/api/pages?tree=1" qa_employee01 > /dev/null
lacks "  옛 공간(employee01) 트리에서 HV 가 사라졌다" "$HV" "$(cat "$WORK/b")"
lacks "  옛 공간 트리에서 HV1 도 사라졌다" "$HV1" "$(cat "$WORK/b")"
capi GET "/api/pages?tree=1" qa_employee02 > /dev/null
contains "  새 공간(employee02) 트리에 HV 가 있다" "$HV" "$(cat "$WORK/b")"
expect "옛 주인 GET HV → 200 (보기로 남겼다)" "200|shared|False" \
  "$(capi GET "/api/pages/$HV" qa_employee01)|$(jget "d['granted_by']" < "$WORK/b")|$(jget "d['can_edit']" < "$WORK/b")"
expect "  소유자는 이제 employee02" "qa_employee02" "$(jget "d['page']['owner']['username']" < "$WORK/b")"
V2=$(pg_page "$HV" "p.doc_version")
doc_body "$V2" false
expect "옛 주인 PATCH HV → 403" "403" "$(capi PATCH "/api/pages/$HV" qa_employee01 "$WORK/doc.json")"
expect "옛 주인 GET 하위 HV1 → 200 (물려받은 보기)" "200" "$(capi GET "/api/pages/$HV1" qa_employee01)"
expect "옛 주인은 이제 넘기지 못한다 → 403" "403" \
  "$(body '{"to":"qa_manager01","keep":"none"}'; capi POST "/api/pages/$HV/handover" qa_employee01 "$WORK/body.json")"
code=$(capi GET "/api/pages/$HV/history" qa_employee02)
expect "새 주인의 「처리 이력」에 인계 줄" "1" "$(jget "sum(1 for e in d['events'] if e['kind']=='handover')" < "$WORK/b")"
expect "  인계 줄의 내용(옛→새·남긴 권한)" "qa_employee01|qa_employee02|view" \
  "$(jget "[ '%s|%s|%s' % (e['from'], e['to'], e['kept']) for e in d['events'] if e['kind']=='handover'][0]" < "$WORK/b")"

sub "「없음」으로 넘기면 옛 주인은 볼 수 없다"
HN=$(make_page qa_employee01 "5A-인계-없음")
body '{"to":"qa_employee02","keep":"none"}'
expect "인계(keep=none) → 200" "200" "$(capi POST "/api/pages/$HN/handover" qa_employee01 "$WORK/body.json")"
expect "  옛 주인 GET → 403" "403" "$(capi GET "/api/pages/$HN" qa_employee01)"
expect "  handovers 의 kept_level 은 NULL" "t" \
  "$(psqlq "SELECT h.kept_level IS NULL FROM community.handovers h JOIN community.pages p ON p.id=h.root_page_id WHERE p.public_id='$HN'")"

sub "하위 페이지 하나만 넘기면 — 옛 부모는 옛 공간에 남는다"
HP=$(make_page qa_employee01 "5A-남는-부모")
HPC=$(make_page qa_employee01 "5A-떠나는-하위" "$HP")
body '{"to":"qa_employee02","keep":"edit"}'
expect "하위만 인계(keep=edit) → 200" "200" "$(capi POST "/api/pages/$HPC/handover" qa_employee01 "$WORK/body.json")"
expect "  옛 부모는 employee01 공간에 그대로" "qa_employee01" \
  "$(psqlq "SELECT u.username FROM community.pages p JOIN community.spaces s ON s.id=p.space_id JOIN users u ON u.id=s.owner_user_id WHERE p.public_id='$HP'")"
expect "  떠난 하위는 새 공간의 뿌리" "t" "$(pg_page "$HPC" "p.parent_page_id IS NULL")"
doc_body "$(pg_page "$HPC" "p.doc_version")" false
expect "  옛 주인은 편집으로 남았다 — PATCH 200" "200" "$(capi PATCH "/api/pages/$HPC" qa_employee01 "$WORK/doc.json")"
expect "  그래도 옛 주인은 지우지 못한다 — DELETE 403" "403" "$(capi DELETE "/api/pages/$HPC" qa_employee01)"

sub "받는 사람에게 개인 공간이 없으면 만든다 (임시 DB 에 수신 전용 계정 하나)"
psqlq "INSERT INTO public.users (username, password_hash, role, team_id, is_active)
       VALUES ('qa_tmp5a_recv', 'x-not-a-login', 'Employee', $TEAM2, true)" >/dev/null
expect "  (임시 DB) 그 계정에게는 공간이 없다" "0" \
  "$(psqlq "SELECT count(*) FROM community.spaces WHERE owner_user_id=(SELECT id FROM users WHERE username='qa_tmp5a_recv')")"
HS=$(make_page qa_employee01 "5A-새공간으로")
A4=$(mark)
body '{"to":"qa_tmp5a_recv","keep":"none"}'
expect "인계 → 200" "200" "$(capi POST "/api/pages/$HS/handover" qa_employee01 "$WORK/body.json")"
expect "  개인 공간이 하나 생겼고 페이지가 그 안에 있다" "1|t" \
  "$(psqlq "SELECT count(*) FROM community.spaces WHERE kind='personal' AND owner_user_id=(SELECT id FROM users WHERE username='qa_tmp5a_recv')")|$(psqlq "SELECT s.owner_user_id = (SELECT id FROM users WHERE username='qa_tmp5a_recv') FROM community.pages p JOIN community.spaces s ON s.id=p.space_id WHERE p.public_id='$HS'")"
expect "  감사 space.create allow handover" "1" "$(audit_n "$A4" space.create allow handover)"

sub "Manager — 자기 팀원의 페이지만 (내용은 보지 않는다)"
HM=$(make_page qa_employee01 "5A-팀장이-넘김")
expect "manager01 은 그 비공개 페이지를 읽지 못한다 (비열람)" "403" "$(capi GET "/api/pages/$HM" qa_manager01)"
expect "manager01 의 인계 후보 목록 → 200 · 손님 없음" "200|False" \
  "$(capi GET "/api/share-candidates?for=handover&page=$HM&q=qa_" qa_manager01)|$(jget "any(c['username']=='qa_guest01' for c in d['candidates'])" < "$WORK/b")"
A5=$(mark)
body '{"to":"qa_employee02","keep":"none"}'
expect "manager01 이 팀원(employee01)의 페이지를 넘긴다 → 200" "200" "$(capi POST "/api/pages/$HM/handover" qa_manager01 "$WORK/body.json")"
lacks  "  응답에 본문이 없다" '"doc"' "$(cat "$WORK/b")"
expect "  감사 page.handover allow team_scope" "1" "$(audit_n "$A5" page.handover allow team_scope)"
HO=$(make_page qa_employee02 "5A-다른팀-페이지")
body '{"to":"qa_employee01","keep":"none"}'
expect "manager01 이 다른 팀(employee02)의 페이지를 → 403" "403" "$(capi POST "/api/pages/$HO/handover" qa_manager01 "$WORK/body.json")"

sub "Admin — 재인증 없이 403, 재인증 뒤 200 (감사 admin_all)"
HA=$(make_page qa_employee01 "5A-관리자가-넘김")
A6=$(mark)
body '{"to":"qa_employee02","keep":"view"}'
expect "admin 이 재인증 없이 → 403 next=reauth" "403|reauth" \
  "$(capi POST "/api/pages/$HA/handover" qa_admin01 "$WORK/body.json")|$(jget "d['next']" < "$WORK/b")"
expect "  감사 page.handover deny reauth_required" "1" "$(audit_n "$A6" page.handover deny reauth_required)"
expect "  페이지는 그대로" "qa_employee01" \
  "$(psqlq "SELECT u.username FROM community.pages p JOIN community.spaces s ON s.id=p.space_id JOIN users u ON u.id=s.owner_user_id WHERE p.public_id='$HA'")"
PW=$(qa_password qa_admin01)
python3 -c "import json,sys;print(json.dumps({'password':sys.argv[1]}))" "$PW" > "$WORK/reauth.json"
RA=$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR_DIR/qa_admin01.jar" -A "$UA" -H 'Content-Type: application/json' \
      -H "X-CSRF-Token: $CSRF_qa_admin01" --data-binary "@$WORK/reauth.json" "$BASE/api/me/reauth")
rm -f "$WORK/reauth.json"
expect "  admin 재인증 (t-box 창구 /api/me/reauth) → 200" "200" "$RA"
body '{"to":"qa_employee02","keep":"view"}'
expect "admin 이 재인증 뒤 → 200" "200" "$(capi POST "/api/pages/$HA/handover" qa_admin01 "$WORK/body.json")"
expect "  감사 page.handover allow admin_all" "1" "$(audit_n "$A6" page.handover allow admin_all)"
expect "  옛 주인(employee01)에게 보기가 남았다" "view" \
  "$(psqlq "SELECT sh.level FROM community.page_shares sh JOIN users u ON u.id=sh.user_id JOIN community.pages p ON p.id=sh.page_id WHERE u.username='qa_employee01' AND p.public_id='$HA'")"

sub "팀 공간의 페이지는 인계 대상이 아니다"
TSPACE=$(psqlq "SELECT id FROM community.spaces WHERE kind='team' AND team_id=$TEAM1")
if [ -n "$TSPACE" ]; then
  HT=$(make_page qa_employee01 "5A-팀공간-페이지" "" "$TSPACE")
  body '{"to":"qa_employee02","keep":"none"}'
  expect "팀 공간 페이지 인계 → 400 space_governed" "400|space_governed" \
    "$(capi POST "/api/pages/$HT/handover" qa_employee01 "$WORK/body.json")|$(jget "d['reason']" < "$WORK/b")"
  body '{"level":"view"}'
  # 5-A″ (사용자 결정 2026-09-23 「팀 공간 페이지도 공유」) — 5-A 의 400 space_governed 가 풀렸다.
  #   자세한 갈래는 tests/community/stage5a2.sh C:T-64 가 본다. 여기서는 팀원이 줄 수 있다는 것만.
  expect "팀 공간 페이지 공유 → 200 (5-A″ 에서 열림)" "200" \
    "$(capi PUT "/api/pages/$HT/shares/qa_employee02" qa_employee01 "$WORK/body.json")"
  expect "  팀 공간 페이지의 소유자 표시는 「팀」" "team" \
    "$(capi GET "/api/pages/$HT" qa_employee01 >/dev/null; jget "d['page']['owner']['kind']" < "$WORK/b")"
else
  skip "팀 공간" "임시 DB 에 팀 공간이 없다"
fi

sub "인계 뒤 다시 — 판정 함수 = 목록 SQL 전수 대조 (옮겨 간 가지 · 남긴 공유 · 새로 만든 공간까지)"
( cd "$INST/app" && php "$WORK/matrix.php" "$INST/app" ) > "$WORK/matrix2.txt" 2>&1
sed 's/^/    /' "$WORK/matrix2.txt"
expect "인계 뒤 전수 대조 — 어긋난 쌍 0" "MISMATCH=0" "$(grep '^MISMATCH=' "$WORK/matrix2.txt")"

# ════════════════════════════════════════════════════════════════
hr "C:T-59  기록 불변 — 앱 계정으로 handovers 를 고치거나 지울 수 없다"

expect "SELECT 는 된다" "0" "$(appq "SELECT 0 FROM community.handovers LIMIT 1" | tr -d ' ')"
for stmt in "UPDATE community.handovers SET page_count=0 WHERE false" \
            "DELETE FROM community.handovers WHERE false" \
            "TRUNCATE community.handovers" \
            "ALTER TABLE community.handovers DROP COLUMN kept_level"; do
  out=$(appq "$stmt")
  contains "앱 계정 → 42501: ${stmt%% *} ..." "42501" "$out"
done
expect "INSERT 는 있다" "t" "$(appq "SELECT has_table_privilege('community.handovers','INSERT')" | tr -d ' ')"
expect "page_shares 는 SIUD (바뀌는 값)" "true|true|true|true" \
  "$(appq "SELECT has_table_privilege('community.page_shares','SELECT')||'|'||has_table_privilege('community.page_shares','INSERT')||'|'||has_table_privilege('community.page_shares','UPDATE')||'|'||has_table_privilege('community.page_shares','DELETE')" | tr -d ' ')"
expect "page_shares CHECK — 모르는 등급은 DB 도 막는다" "23514" \
  "$(appq "INSERT INTO community.page_shares (page_id,user_id,level,granted_by) VALUES (1,1,'owner',1)" | grep -o '23514' | head -1)"

# ════════════════════════════════════════════════════════════════
hr "C:T-60  담당자 지정 멈춤 — 창구 400·404 · 옛 기록은 이력에 남는다"

body '{"assignee":"qa_employee01"}'
expect "PATCH …/properties {assignee} → 400" "400" "$(capi PATCH "/api/pages/$P2/properties" qa_employee01 "$WORK/body.json")"
body '{"status":"done","assignee":null}'
expect "PATCH …/properties {status, assignee} → 400 (status 도 바뀌지 않는다)" "400|review" \
  "$(capi PATCH "/api/pages/$P2/properties" qa_employee01 "$WORK/body.json")|$(pg_page "$P2" "coalesce(p.status,'')")"
expect "GET /api/assignees → 404 (창구가 없다)" "404" "$(capi GET "/api/assignees?page=$P2" qa_employee01)"
# 4-D 때 남은 기록을 흉내 낸다 — 임시 DB 에만 한 줄.
psqlq "INSERT INTO community.page_property_events (page_id, prop, from_value, to_value, by_user_id)
       SELECT p.id, 'assignee', NULL, 'qa_employee01', (SELECT id FROM users WHERE username='qa_employee01')
         FROM community.pages p WHERE p.public_id='$P2'" >/dev/null
capi GET "/api/pages/$P2/history" qa_employee01 > /dev/null
expect "옛 담당자 줄이 「처리 이력」에 그대로 나온다" "1" \
  "$(jget "sum(1 for e in d['events'] if e['kind']=='property' and e['prop']=='assignee')" < "$WORK/b")"

# ════════════════════════════════════════════════════════════════
hr "브라우저 — browser_smoke.py (33 사람 추가·등급·빼기 · 34 보기 공유자 화면 · 35 인계 창)"

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
  skip "브라우저 스모크" "playwright 가 없다"
else
  S1=$(cookie_of qa_employee01); S2=$(cookie_of qa_employee02)
  if [ -z "$S1" ] || [ -z "$S2" ]; then
    skip "브라우저 스모크" "세션 쿠키를 꺼내지 못했다"
  else
    SMOKE_SESSION2="$S2" SMOKE_USER2=qa_employee02 SMOKE_SHOTS="${SHOTS:-}" \
      python3 "$ROOT/tests/community/browser_smoke.py" "$SITE" "$UA" "$S1" > "$WORK/smoke.txt" 2>&1
    sed -n '/── 32\./,$p' "$WORK/smoke.txt" | sed 's/^/    /'
    SMOKE_FAIL=$(sed -n 's/^SMOKE_FAIL=//p' "$WORK/smoke.txt" | tail -1)
    expect "브라우저 스모크 실패 0 (1~35 전부)" "0" "${SMOKE_FAIL:-없음}"
    expect "  콘솔 오류 0" "CONSOLEERR=0" "$(grep '^CONSOLEERR=' "$WORK/smoke.txt" | tail -1)"
    expect "  pageerror 0" "PAGEERROR=0" "$(grep '^PAGEERROR=' "$WORK/smoke.txt" | tail -1)"
    expect "  HTTP 400 이상 0" "HTTP4XX=0" "$(grep '^HTTP4XX=' "$WORK/smoke.txt" | tail -1)"
    [ -n "${SMOKE_KEEP:-}" ] && cp "$WORK/smoke.txt" "$SMOKE_KEEP"
  fi
fi

# ════════════════════════════════════════════════════════════════
hr "회귀 — 금지 패턴 · 문법"

out=$(bash "$ROOT/scripts/security_lint.sh" 2>&1 | tail -3)
contains "security_lint 0건" "0건" "$out"
bad=0
for f in $(cd "$ROOT" && git ls-files 'src/**.php' 'public/index.php' 2>/dev/null) \
         src/community/handlers/pages_shares_list.php src/community/handlers/pages_share_put.php \
         src/community/handlers/pages_share_delete.php src/community/handlers/share_candidates.php \
         src/community/handlers/pages_handover.php; do
  [ -f "$ROOT/$f" ] || continue
  php -l "$ROOT/$f" > /dev/null 2>&1 || { echo "  ✖ php -l 실패: $f"; bad=$((bad+1)); }
done
expect "php -l 전부 통과" "0" "$bad"
bash -n "$ROOT/tests/community/stage5a.sh" && expect "stage5a.sh 문법" "0" "0"
python3 -c "import ast,io;ast.parse(io.open('$ROOT/tests/community/browser_smoke.py',encoding='utf-8').read())" \
  && expect "browser_smoke.py 문법" "0" "0"
expect "커뮤니티 코드에 역할 문자열 비교 0 (4-D 교훈 — Authz 에 묻는다)" "0" \
  "$(grep -rnE "'(Admin|Manager|Employee|Guest)'" "$ROOT/src/community" | grep -vE '^[^:]+:[0-9]+:\s*(\*|//|/\*)' | wc -l | tr -d ' ')"

# ════════════════════════════════════════════════════════════════
if [ "${REGRESS:-0}" = "1" ]; then
  hr "회귀 — 같은 임시 인스턴스에서 stage3 · 4a · 4b · 4c · t-box stage26"
  note "각 스크립트를 임시 인스턴스 폴더로 복사해 SITE·DB 만 바꿔 돌린다(운영 8082·tbox_re 에 닿지 않는다)."
  for s in community/stage3 community/stage4a community/stage4b community/stage4c stage26; do
    src="$INST/app/tests/$s.sh"
    dst="$INST/app/tests/$s.temp.sh"
    sed -e "s#http://127.0.0.1:8082#$SITE#g" -e "s#^DB=\"tbox_re\"#DB=\"$DB\"#" "$src" > "$dst"
    if grep -q '8082\|DB="tbox_re"' "$dst"; then
      echo "  ✖ $s — 운영 주소가 남아 있어 돌리지 않는다"; FAIL=$((FAIL+1)); continue
    fi
    echo "  ▸ $s (시작 $(date +%T))"
    bash "$dst" > "$WORK/regress_$(basename "$s").txt" 2>&1
    rc=$?
    line=$(grep -E '통과 [0-9]+|PASS|통과:' "$WORK/regress_$(basename "$s").txt" | tail -1)
    echo "    종료 코드 $rc · ${line:-결과 줄 없음}"
    grep -E '^\s*✖' "$WORK/regress_$(basename "$s").txt" | head -12 | sed 's/^/      /'
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
