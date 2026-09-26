#!/usr/bin/env bash
# tests/community/stage4d.sh — 커뮤니티 4-D 수락 기준 C:T-46 ~ C:T-52 실행
#                              (../../../community/stage4d-status-assignee-directive.md 「완료 기준」)
#
#   C:T-46 상태 5값 + 없음   각각 200 · `GET` 에 반영 · `page_property_events` 가 한 줄씩 늘고
#                            `from_value`→`to_value` 가 맞다
#   C:T-47 허용 밖 값        `status:"urgent"` 400 · 허용 키 밖(`{due}`) 400 · 빈 몸통 400 · DB 불변
#   C:T-48 담당자 (5-A 로 바뀜) **지정을 멈췄다** — `{assignee}` 는 누구든 400 · DB 불변 · 감사 0 ·
#                            후보 창구 `GET /api/assignees` 404. (4-D 때는 「볼 수 있는 사람 200 · 볼 수
#                            없는 사람 400 assignee_not_permitted」였다 — 5-A 지시서 「4-D 담당자」 줄)
#   C:T-49 기록 불변         **앱 계정(tbox_app)으로** `UPDATE`·`DELETE`·`TRUNCATE` → 전부 42501
#   C:T-50 (5-A 로 바뀜)     「내가 담당인 페이지」 → 「공유받은 페이지」. `?assignee=me` 는 **무시되는
#                            꼬리표**가 됐다(기본 목록이 나간다) · 이미 든 담당자 값은 DB 에 그대로 남고
#                            창구는 읽기 전용 옛 값으로만 싣는다. 새 묶음 `?shared=me` 는 stage5a.sh C:T-56
#   C:T-51 남이 바꾸기       소유자가 아닌 사람의 `PATCH …/properties` → 403 (사내 공개여도 · Admin 도)
#   C:T-52 이력 합치기       「처리 이력」에 버전·체크·상태·(옛)담당자가 한 줄씩 **시간순**으로 섞여 나온다
#                            (5-A — 담당자 줄은 창구로 만들 수 없어 **임시 DB 에 한 줄 넣어** 옛 기록을 흉내 낸다)
#   브라우저                 browser_smoke.py 32 (상태 배지·새로 고침 유지·「나에게」·목록 배지·묶음)
#   회귀                     security_lint 0 · php -l · bash -n
#
# ══════════════════════════════════════════════════════════════════════════
# ★ 이 시험은 **운영 DB 를 한 글자도 건드리지 않는다.**
#
#   4-D 코드는 마이그레이션(`db/migrations/2026-09-23_community_page_properties.sql`)이
#   만든 칸을 **읽고 쓴다**(5-A 부터는 `2026-09-23_community_owner_share.sql` 의 표도 읽는다 —
#   페이지 한 건을 읽는 질의가 `page_shares` 를 본다. 그래서 ② 에서 둘 다 적용한다) — 4-C 와 달리 「적용 전에도 결과가 같다」가 아니다. 그런데 구현
#   에이전트는 운영 DB 에 DDL 을 돌리지 않는다(CLAUDE.md 4장). 그래서 이 스크립트는
#
#     ① 운영 DB(`tbox_re`)를 **읽어서**(pg_dump) 임시 DB `tbox_4d_test` 를 만들고
#     ② 거기에만 마이그레이션과 `db/community/grants.sql` 을 적용하고
#     ③ 저장소를 임시 폴더로 복사해 **자기 `.env`**(DB 이름·저장 경로·로그 경로만 다르다)를 주고
#     ④ `php -S 127.0.0.1:8083` 으로 **두 번째 인스턴스**를 띄워 그쪽에만 요청을 보낸다.
#     ⑤ 끝나면 서버를 내리고 임시 DB 와 임시 폴더를 **지운다**.
#
#   운영(8082)에는 요청을 한 번도 보내지 않는다. 운영 적용은 메인 세션이 한다(보고 ④).
# ══════════════════════════════════════════════════════════════════════════
#
# 규칙 (CLAUDE.md 3장) — stage3.sh·stage4a.sh·stage4b.sh·stage4c.sh 와 같다
#   · 검토 계정 `claude_` 다섯뿐. 시드 계정은 사용자 몫.
#   · 모든 요청에 `-A "tbox-review/<날짜>"`.
#   · 운영 DB 에 DDL 을 실행하지 않는다. `sudo` 는 **읽기**(pg_dump·seed 파일)와 **임시 DB** 에만.
#   · 만든 페이지는 임시 DB 와 함께 통째로 사라진다 — 운영에 남는 것이 없다.
#
# 준비물: curl · php · python3 · sudo -n(seed-accounts.txt · qa-totp.txt · psql · pg_dump) · playwright
# 사용: sudo -v && bash tests/community/stage4d.sh
set -u

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
. "$ROOT/tests/lib/secrets.sh" || { echo "tests/lib/secrets.sh 를 읽지 못했다 — 시험을 시작하지 않는다 (R-62)"; exit 3; }
PORT=8083
SITE="http://127.0.0.1:$PORT"
BASE="$SITE/tbox"
CBASE="$SITE/community"
UA="tbox-review/$(date +%F)"
SRC_DB="tbox_re"          # 운영 — **읽기만** 한다
DB="tbox_4d_test"         # 임시 — 여기에만 쓴다
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

# 앱 계정(tbox_app)으로 한 문장 — C:T-49 전용. 값은 임시 .env 에서 읽고 출력하지 않는다.
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

make_page() {   # $1 계정 $2 제목
  python3 - "$2" > "$WORK/new.json" <<'PY'
import json, sys
print(json.dumps({"title": sys.argv[1]}, ensure_ascii=False))
PY
  capi POST /api/pages "$1" "$WORK/new.json" > /dev/null
  jget "d['page']['id']" < "$WORK/b"
}

body() { printf '%s' "$1" > "$WORK/body.json"; }

ev_count() { psqlq "SELECT count(*) FROM community.page_property_events e JOIN community.pages p ON p.id = e.page_id WHERE p.public_id = '$1'"; }

# ════════════════════════════════════════════════════════════════
hr "커뮤니티 4-D — 임시 인스턴스 준비 (운영 DB 에는 쓰지 않는다)"

avail_kb=$(df -Pk / | awk 'NR==2{print $4}')
echo "루트 파티션 여유: $(df -Ph / | awk 'NR==2{print $4}') (${avail_kb}KB)"
[ "$avail_kb" -ge 1048576 ] || { echo "루트 디스크 여유 1GB 미만 — 시험을 시작하지 않는다 (SECURITY R-80)"; exit 3; }

sudo -n -u postgres psql -d "$SRC_DB" -tAc "SELECT 1" >/dev/null 2>&1 \
  || { echo "psql(postgres) 을 쓸 수 없다 — 이 시험은 임시 DB 가 없으면 성립하지 않는다"; exit 3; }

sub "① 운영 DB 를 **읽어** 임시 DB 를 만든다"
# 덤프 파일은 **postgres 가 만든다** — 미리 만들어 두면(mktemp, 모드 600) 그 계정이 쓰지 못한다.
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

sub "② 마이그레이션 + 권한 (임시 DB 에만) — 4-D · 5-A"
# 5-A″ — 이름 칸(`users.display_name`)도 적용한다. 5-A″ 이후의 코드는 세션 질의부터 그 칸을 읽는다.
for mig in 2026-09-23_community_page_properties.sql 2026-09-23_community_owner_share.sql \
           2026-09-23_users_display_name.sql \
           2026-09-24_community_boards.sql \
           2026-09-24_community_discussion.sql 2026-09-25_community_databases.sql 2026-09-26_community_purge.sql 2026-09-27_community_templates.sql 2026-09-28_community_embeds.sql; do   # 5-C·5-D — 코드가 새 칸·표를 읽는다
  sudo -n -u postgres psql -d "$DB" -v ON_ERROR_STOP=1 \
    -f - < "$ROOT/db/migrations/$mig" 2>&1 \
    | grep -v 'could not change directory' | sed 's/^/    /'
done
# 파일은 **표준 입력으로** 넘긴다 — postgres 계정이 저장소 폴더를 읽지 못해도(권한 700) 된다.
sudo -n -u postgres psql -q -d "$DB" -v ON_ERROR_STOP=1 -f - < "$ROOT/db/community/grants.sql" 2>&1 \
  | grep -v 'could not change directory' | sed 's/^/    /'
echo "  · 기록 표의 앱 계정 권한 (C:S-14 — r=SELECT · a=INSERT 만이어야 한다)"
psqlq "SELECT c.relname || ' : ' || coalesce(array_to_string(c.relacl, ' '), '(없음)')
         FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
        WHERE n.nspname='community'
          AND c.relname IN ('page_versions','checklist_events','page_property_events')
        ORDER BY c.relname" | sed 's/^/    /'

sub "③④ 저장소를 임시 폴더로 복사하고 두 번째 인스턴스를 띄운다"
INST="$(mktemp -d)"
mkdir -p "$INST/app" "$INST/storage" "$INST/log"
tar -C "$ROOT" -cf - --exclude=.git --exclude=jar --exclude=api-test.txt --exclude=./.env . | tar -C "$INST/app" -xf -
# 임시 `.env` — 운영 `.env` 에서 **DB 이름·저장 경로·로그 경로만** 바꾼다. 값은 출력하지 않는다.
# qcheck-allow: R20 — 임시 인스턴스용 사본(0700·0600 · trap 정리) · kill -9 뒤 남는 창은 받아들인 위험(HO-B1-004)
sed -e "s#^DB_NAME=.*#DB_NAME=$DB#" \
    -e "s#^STORAGE_DIR=.*#STORAGE_DIR=$INST/storage#" \
    -e "s#^AUDIT_LOG_FILE=.*#AUDIT_LOG_FILE=$INST/log/audit.log#" \
    "$ROOT/.env" > "$INST/app/.env"
chmod 600 "$INST/app/.env"

# nginx 의 두 갈래(자산·단일 입구)만 흉내 내는 라우터. 운영에는 쓰이지 않는다.
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

# `exec` 로 서브셸을 php 로 **바꿔** 띄운다 — 그래야 `$!` 가 서버 프로세스 자신이고,
# 끝날 때 `kill` 이 포트를 진짜로 놓는다(서브셸만 죽이면 서버가 남는다).
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

AUDIT_START=$(mark); AUDIT_START=${AUDIT_START:-0}

sub "로그인 (qa 계정 넷 — 주인 · 남 · 관리자 · 손님)"
for who in claude_employee01 claude_employee02 claude_admin01 claude_guest01; do
  tok=$(login "$who")
  if [ -z "$tok" ]; then echo "  ✖ $who 로그인 실패 — 중단"; exit 4; fi
  eval "CSRF_${who}='$tok'"
  echo "  ✔ $who"
done

P1=$(make_page claude_employee01 "4-D 상태 시험")
[ -n "$P1" ] || { echo "시험용 페이지를 만들지 못했다 — 중단"; exit 4; }
note "시험 페이지(비공개, employee01) = $P1"

# ════════════════════════════════════════════════════════════════
hr "C:T-46  상태 5값 + 없음 — 각각 200 · GET 반영 · 기록 한 줄씩"

prev="NULL"
n0=$(ev_count "$P1")
i=0
for st in todo doing review done hold; do
  body "{\"status\":\"$st\"}"
  code=$(capi PATCH "/api/pages/$P1/properties" claude_employee01 "$WORK/body.json")
  expect "상태를 $st 로 → 200" "200" "$code"
  expect "  응답의 status 가 $st" "$st" "$(jget "d['page']['status']" < "$WORK/b")"
  code=$(capi GET "/api/pages/$P1" claude_employee01)
  expect "  GET 에도 $st 로 반영" "$st" "$(jget "d['page']['status']" < "$WORK/b")"
  i=$((i+1))
  expect "  page_property_events 가 한 줄 늘었다" "$((n0+i))" "$(ev_count "$P1")"
  got=$(psqlq "SELECT coalesce(from_value,'NULL') || '→' || coalesce(to_value,'NULL')
                 FROM community.page_property_events e JOIN community.pages p ON p.id = e.page_id
                WHERE p.public_id = '$P1' AND e.prop='status' ORDER BY e.id DESC LIMIT 1")
  expect "  이전→새값이 맞다" "$prev→$st" "$got"
  prev="$st"
done

body '{"status":null}'
code=$(capi PATCH "/api/pages/$P1/properties" claude_employee01 "$WORK/body.json")
expect "상태를 「없음」으로 → 200" "200" "$code"
expect "  응답의 status 가 비어 있다" "" "$(jget "d['page']['status']" < "$WORK/b")"
expect "  DB 의 status 가 NULL 이다" "t" "$(psqlq "SELECT status IS NULL FROM community.pages WHERE public_id='$P1'")"
i=$((i+1))
expect "  기록이 한 줄 더 늘었다" "$((n0+i))" "$(ev_count "$P1")"
expect "  마지막 줄이 hold→NULL 이다" "hold→NULL" \
  "$(psqlq "SELECT coalesce(from_value,'NULL') || '→' || coalesce(to_value,'NULL')
              FROM community.page_property_events e JOIN community.pages p ON p.id = e.page_id
             WHERE p.public_id = '$P1' AND e.prop='status' ORDER BY e.id DESC LIMIT 1")"

sub "같은 값을 다시 보내면 200 이지만 **기록은 늘지 않는다** (장부는 「바뀐 일」만 적는다)"
before=$(ev_count "$P1")
body '{"status":null}'
code=$(capi PATCH "/api/pages/$P1/properties" claude_employee01 "$WORK/body.json")
expect "같은 값 다시 → 200" "200" "$code"
expect "  기록 수 그대로" "$before" "$(ev_count "$P1")"
expect "  changed 가 빈 목록" "0" "$(jget "len(d['changed'])" < "$WORK/b")"

# ════════════════════════════════════════════════════════════════
hr "C:T-47  허용 밖 값 — 400 · DB 불변"

body '{"status":"doing"}'
capi PATCH "/api/pages/$P1/properties" claude_employee01 "$WORK/body.json" > /dev/null
snapshot=$(psqlq "SELECT coalesce(status,'NULL') || '|' || coalesce(assignee_id::text,'NULL') FROM community.pages WHERE public_id='$P1'")
events_before=$(ev_count "$P1")

for bad in '{"status":"urgent"}' '{"status":"TODO"}' '{"status":123}' '{"due":"2026-10-01"}' \
           '{"status":"doing","due":"x"}' '{}' '{"assignee":""}' '{"assignee":123}' ; do
  body "$bad"
  code=$(capi PATCH "/api/pages/$P1/properties" claude_employee01 "$WORK/body.json")
  expect "$bad → 400" "400" "$code"
done

expect "DB 의 status·assignee 가 그대로다" "$snapshot" \
  "$(psqlq "SELECT coalesce(status,'NULL') || '|' || coalesce(assignee_id::text,'NULL') FROM community.pages WHERE public_id='$P1'")"
expect "기록도 늘지 않았다" "$events_before" "$(ev_count "$P1")"

sub "DB 도 마지막 그물을 갖고 있다 (CHECK — 앱을 지나지 않아도 막힌다)"
out=$(sudo -n -u postgres psql -d "$DB" -t -A -v VERBOSITY=terse \
        -c "UPDATE community.pages SET status='urgent' WHERE public_id='$P1'" 2>&1 | grep -v 'could not change directory' | head -1)
contains "psql 로 직접 넣어도 CHECK 가 막는다" "pages_status_check" "$out"

# ════════════════════════════════════════════════════════════════
hr "C:T-48  담당자 — **지정을 멈췄다** (5-A: 담당자 = 소유자, 바꾸는 일은 인계)"

A0=$(mark)
DEAD=$(psqlq "SELECT username FROM public.users WHERE is_active = false ORDER BY id LIMIT 1")
for who in claude_employee01 claude_employee02 claude_guest01 "없는계정_$$" ${DEAD:+"$DEAD"}; do
  body "{\"assignee\":\"$who\"}"
  code=$(capi PATCH "/api/pages/$P1/properties" claude_employee01 "$WORK/body.json")
  expect "{assignee: $who} → 400 (허용 키가 아니다)" "400" "$code"
done
body '{"assignee":null}'
expect "{assignee: null} 도 400" "400" "$(capi PATCH "/api/pages/$P1/properties" claude_employee01 "$WORK/body.json")"
body '{"status":"todo","assignee":"claude_employee01"}'
expect "상태와 함께 보내도 400 — 상태도 바뀌지 않는다" "400|doing" \
  "$(capi PATCH "/api/pages/$P1/properties" claude_employee01 "$WORK/body.json")|$(psqlq "SELECT coalesce(status,'NULL') FROM community.pages WHERE public_id='$P1'")"
expect "DB 의 assignee_id 는 그대로 NULL" "t" "$(psqlq "SELECT assignee_id IS NULL FROM community.pages WHERE public_id='$P1'")"
expect "형식 거부라 감사는 남지 않는다" "0" \
  "$(psqlq "SELECT count(*) FROM audit_logs WHERE id > ${A0:-0} AND action='page.property'")"
expect "후보 창구 GET /api/assignees → 404 (창구가 없다)" "404" "$(capi GET "/api/assignees?page=$P1" claude_employee01)"

# ════════════════════════════════════════════════════════════════
hr "C:T-49  기록 불변 — 앱 계정으로는 고칠 수도 지울 수도 없다"

expect "SELECT 는 된다 (앱이 이력을 읽는다)" "0" "$(appq "SELECT 0 FROM community.page_property_events LIMIT 1" | tr -d ' ')"
for stmt in "UPDATE community.page_property_events SET to_value='x' WHERE false" \
            "DELETE FROM community.page_property_events WHERE false" \
            "TRUNCATE community.page_property_events" \
            "ALTER TABLE community.page_property_events DROP COLUMN to_value"; do
  out=$(appq "$stmt")
  contains "앱 계정 → 42501: ${stmt%% *} ..." "42501" "$out"
done
expect "INSERT 는 있다 (앱이 기록을 남긴다)" "t" \
  "$(appq "SELECT has_table_privilege('community.page_property_events','INSERT')" | tr -d ' ')"

# ════════════════════════════════════════════════════════════════
hr "C:T-50  (5-A) 「내가 담당인 페이지」 자리 — 옛 담당자 값은 남고, 꼬리표는 무시된다"

P2=$(make_page claude_employee02 "4-D 영업팀의 사내 페이지")
[ -n "$P2" ] || { echo "employee02 의 페이지를 만들지 못했다 — 중단"; exit 4; }
body '{"visibility":"internal"}'
expect "employee02 가 자기 페이지를 「사내」로 연다" "200" \
  "$(capi PATCH "/api/pages/$P2/visibility" claude_employee02 "$WORK/body.json")"
body '{"status":"review"}'
expect "employee02 가 상태를 검토로 → 200" "200" "$(capi PATCH "/api/pages/$P2/properties" claude_employee02 "$WORK/body.json")"
# 4-D 때 지정해 둔 담당자를 흉내 낸다 — 임시 DB 에만 한 칸.
psqlq "UPDATE community.pages SET assignee_id = (SELECT id FROM users WHERE username='claude_employee01') WHERE public_id='$P2'" >/dev/null
code=$(capi GET "/api/pages/$P2" claude_employee02)
expect "옛 담당자 값은 창구에 **읽기 전용**으로 남는다" "claude_employee01" "$(jget "d['page']['assignee']['username']" < "$WORK/b")"
expect "  속성 줄의 소유자는 공간 주인(employee02)" "claude_employee02" "$(jget "d['page']['owner']['username']" < "$WORK/b")"
code=$(capi GET "/api/pages?assignee=me" claude_employee01)
expect "?assignee=me 는 무시되는 꼬리표 — 기본 목록(내 개인 공간)이 나간다" "200|personal" \
  "$code|$(jget "d['space']['kind']" < "$WORK/b")"
lacks "  그 목록에 남의 공간 페이지는 없다" "$P2" "$(cat "$WORK/b")"

# ════════════════════════════════════════════════════════════════
hr "C:T-51  남이 바꾸기 — 403 (사내 공개여도 · Admin 도)"

A0=$(mark)
body '{"status":"done"}'
expect "employee01 이 employee02 의 **사내** 페이지 상태를 → 403" "403" \
  "$(capi PATCH "/api/pages/$P2/properties" claude_employee01 "$WORK/body.json")"
expect "Admin 이 남의 개인 페이지 상태를 → 403 (볼 수는 있어도 고치지 못한다)" "403" \
  "$(capi PATCH "/api/pages/$P2/properties" claude_admin01 "$WORK/body.json")"
expect "  DB 의 상태는 그대로다" "review" "$(psqlq "SELECT status FROM community.pages WHERE public_id='$P2'")"
expect "  감사에 deny not_owner 두 줄" "2" \
  "$(psqlq "SELECT count(*) FROM audit_logs WHERE id > ${A0:-0} AND action='page.property' AND result='deny' AND reason='not_owner'")"

sub "볼 수도 없는 사람은 403 이고, 그 줄은 page.read 로 남는다"
expect "employee01 이 employee02 의 **비공개** 페이지 상태를 → 403" "403" \
  "$(capi PATCH "/api/pages/$P1/properties" claude_employee02 "$WORK/body.json")"

# ════════════════════════════════════════════════════════════════
hr "C:T-52  이력 합치기 — 버전 · 체크 · 상태 · 담당자가 한 줄씩 시간순"

sub "같은 페이지에 네 가지를 모두 만든다"
BLK=$(python3 -c 'import uuid;print(uuid.uuid4())')
python3 - "$BLK" > "$WORK/todo.json" <<'PY'
import json, sys
doc = {"schema": 1, "blocks": [{"id": sys.argv[1], "type": "to_do", "checked": False,
                                "text": [{"t": "보고서 마무리", "m": {}}]}]}
print(json.dumps({"doc_version": 1, "doc": doc}, ensure_ascii=False))
PY
P3=$(make_page claude_employee01 "4-D 이력 합치기")
expect "본문 저장(판 1 → 2)" "200" "$(capi PATCH "/api/pages/$P3" claude_employee01 "$WORK/todo.json")"
python3 - "$BLK" > "$WORK/check.json" <<'PY'
import json, sys
print(json.dumps({"block_id": sys.argv[1], "checked": True}))
PY
body "{\"block_id\":\"$BLK\",\"checked\":true}"
python3 - "$BLK" > "$WORK/todo2.json" <<'PY'
import json, sys
doc = {"schema": 1, "blocks": [{"id": sys.argv[1], "type": "to_do", "checked": True,
                                "text": [{"t": "보고서 마무리", "m": {}}]}]}
print(json.dumps({"doc_version": 2, "doc": doc}, ensure_ascii=False))
PY
expect "체크를 켠다(판 2 → 3)" "200" "$(capi PATCH "/api/pages/$P3" claude_employee01 "$WORK/todo2.json")"
body '{"status":"doing"}'
expect "상태를 진행으로" "200" "$(capi PATCH "/api/pages/$P3/properties" claude_employee01 "$WORK/body.json")"
# 5-A — 담당자 줄은 창구로 만들 수 없다. 4-D 때 남은 옛 기록을 **임시 DB 에 한 줄** 흉내 낸다.
psqlq "INSERT INTO community.page_property_events (page_id, prop, from_value, to_value, by_user_id)
       SELECT p.id, 'assignee', NULL, 'claude_employee01', (SELECT id FROM users WHERE username='claude_employee01')
         FROM community.pages p WHERE p.public_id='$P3'" >/dev/null
expect "(임시 DB) 옛 담당자 줄 하나" "1" \
  "$(psqlq "SELECT count(*) FROM community.page_property_events e JOIN community.pages p ON p.id=e.page_id WHERE p.public_id='$P3' AND e.prop='assignee'")"

code=$(capi GET "/api/pages/$P3/history" claude_employee01)
expect "처리 이력 200" "200" "$code"
kinds=$(jget "','.join(e['kind'] for e in d['events'])" < "$WORK/b")
note "줄 종류(새 것부터): $kinds"
contains "  버전 줄이 있다" "version" "$kinds"
contains "  체크 줄이 있다" "check" "$kinds"
contains "  속성 줄이 있다" "property" "$kinds"
expect "  상태 줄 하나" "1" "$(jget "sum(1 for e in d['events'] if e['kind']=='property' and e['prop']=='status')" < "$WORK/b")"
expect "  담당자 줄 하나" "1" "$(jget "sum(1 for e in d['events'] if e['kind']=='property' and e['prop']=='assignee')" < "$WORK/b")"
expect "  상태 줄의 이전→새값" "None→doing" \
  "$(jget "[str(e['from'])+'→'+str(e['to']) for e in d['events'] if e['kind']=='property' and e['prop']=='status'][0]" < "$WORK/b")"
expect "  **시간순(내림차순)이 지켜진다**" "True" \
  "$(jget "all(d['events'][i]['at'] >= d['events'][i+1]['at'] for i in range(len(d['events'])-1))" < "$WORK/b")"
lacks "  이력에 본문 글자가 새지 않는다" "보고서 마무리" "$(cat "$WORK/b")"

sub "이력은 볼 수 있는 사람만 (4-C 규칙 그대로)"
expect "employee02 가 남의 비공개 페이지 이력을 → 403" "403" \
  "$(capi GET "/api/pages/$P3/history" claude_employee02)"

# ════════════════════════════════════════════════════════════════
hr "브라우저 — browser_smoke.py 32 (상태 배지 · 「나에게」 · 목록 배지 · 묶음)"

export PATH="$HOME/.local/bin:$PATH"
if ! python3 -c 'import playwright' 2>/dev/null; then
  skip "브라우저 스모크" "playwright 가 없다"
else
  SESSION=$(python3 - "$JAR_DIR/claude_employee01.jar" <<'PY'
import sys
for line in open(sys.argv[1], encoding='utf-8', errors='replace'):
    parts = line.rstrip('\n').split('\t')
    if len(parts) == 7 and parts[5] == '__Host-tbox_session':
        print(parts[6])
        break
PY
)
  if [ -z "$SESSION" ]; then
    skip "브라우저 스모크" "세션 쿠키를 꺼내지 못했다"
  else
    # 세션 값은 표준입력 첫 줄로 넘긴다(명령 인자에 싣지 않는다 — tests/lib/secrets.sh py_secret · R-62)
    py_secret SESSION -- python3 "$ROOT/tests/community/browser_smoke.py" "$SITE" "$UA" > "$WORK/smoke.txt" 2>&1
    sed -n '/── 32\./,$p' "$WORK/smoke.txt" | sed 's/^/    /'
    SMOKE_FAIL=$(sed -n 's/^SMOKE_FAIL=//p' "$WORK/smoke.txt" | tail -1)
    expect "브라우저 스모크 실패 0" "0" "${SMOKE_FAIL:-없음}"
    expect "  콘솔 오류 0" "CONSOLEERR=0" "$(grep '^CONSOLEERR=' "$WORK/smoke.txt" | tail -1)"
    expect "  pageerror 0" "PAGEERROR=0" "$(grep '^PAGEERROR=' "$WORK/smoke.txt" | tail -1)"
    expect "  HTTP 400 이상 0" "HTTP4XX=0" "$(grep '^HTTP4XX=' "$WORK/smoke.txt" | tail -1)"
  fi
fi

# ════════════════════════════════════════════════════════════════
hr "회귀 — 금지 패턴 · 문법"

out=$(bash "$ROOT/scripts/security_lint.sh" 2>&1 | tail -3)
contains "security_lint 0건" "0건" "$out"

bad=0
for f in $(cd "$ROOT" && git ls-files 'src/**.php' 'public/index.php' 2>/dev/null); do
  php -l "$ROOT/$f" > /dev/null 2>&1 || { echo "  ✖ php -l 실패: $f"; bad=$((bad+1)); }
done
expect "php -l 전부 통과" "0" "$bad"
bash -n "$ROOT/tests/community/stage4d.sh" && expect "stage4d.sh 문법" "0" "0"
python3 -c "import ast,io;ast.parse(io.open('$ROOT/tests/community/browser_smoke.py',encoding='utf-8').read())" \
  && expect "browser_smoke.py 문법" "0" "0"

# ════════════════════════════════════════════════════════════════
hr "결과"
printf '통과 %d · 실패 %d · 건너뜀 %d\n' "$PASS" "$FAIL" "$SKIP"
note "임시 DB($DB)·임시 폴더·임시 서버는 이 스크립트가 끝나며 지워진다. 운영에는 아무것도 남지 않는다."
[ "$FAIL" -eq 0 ] || exit 1
exit 0
