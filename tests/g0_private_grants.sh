#!/usr/bin/env bash
# tests/g0_private_grants.sh — G-0 수락 시험 T-G0-1 ~ T-G0-12 (t-box 「개인」 등급 · 사람 지정 권한 · 권한 요청, PRD F-73)
#
#   T-G0-1  스키마       마이그레이션 두 번(멱등) · CHECK 넓힘 · 개인 4행(읽기 0 · 손님 올리기 0) · 개인 읽기 켜기 = CHECK 거부 ·
#                        표 둘 권한(tbox_app SIUD · tbox_backup SELECT · tbox_perm 0) · 알림 kind·칸
#   T-G0-2  짝 전수 대조  역할 넷 × 권한 자리 열 × 깊이 1~5 × 항목 열넷 — decideRead · canSeeInList · visibleWhere(파일 f · 폴더 d) ·
#                        decideEdit · privateReach 를 **따로 계산한 기대값**과 대조(C:T-56 방식). 불일치 0
#   T-G0-3  읽기 경로 전수 권한 없는 계정(직원2 · 팀장 · 손님)이 개인 파일 이름·바이트를 하나도 못 얻음 — 목록 · 폴더 트리(개수 포함) ·
#                        전역 검색 · 받기 · 미리보기 · raw · 실행 확인 받기 · zip(skipped 개수가 없는 id 와 같음) · 즐겨찾기 · 최근 ·
#                        API 토큰(목록·받기) · 공유 링크 · 커뮤니티 파일 지도 · 폴더 경로·breadcrumb · 알림
#   T-G0-4  즉시 반영    주기 → 바로 200 · 빼기 → 바로 403 (캐시 0)
#   T-G0-5  편집 권한자  올리기·설명·폴더 이름 ○ / 등급·옮기기·지우기·공유 링크·권한 주기·폴더 등급·폴더 지우기 ✕ · 손님 편집 불가 ·
#                        꺼진 계정·없는 계정·주인 자신 같은 400 · 같은 이름 여럿 400
#   T-G0-6  요청         403 화면 → 요청 → 주인 알림 1 → 허락 → 보임 · 거절(요청자 알림에 이름 없음) · 중복 · 없는 id = 못 보는 id(답 글자까지 같음) ·
#                        하루 한도(저장 안 한 것도 셈) · 손님 편집 요청 400 · 호스트 아닌 사람 결정 404 · 거두기
#   T-G0-7  넓히기·좁히기 직원 주인 넓히기 403 · 관리자 주인 넓히기(재인증 → 200 · before/after 감사) · 권한 줄 남음 · 다시 좁히면 되살아남 ·
#                        남의 파일을 개인으로 403 · 민감정보 개인 허용(공개는 400)
#   T-G0-8  커뮤니티     개인 파일을 내 비공개 페이지 ○ · 내 사내 페이지 ✕(이유 문장) · 남의 개인 파일(권한 있음)을 내 비공개 페이지 ✕
#   T-G0-9  Admin 특권   주소로 열기 200 + 감사 granted_by admin_override · 목록·검색·개수에 없음 · 권한 창구 재인증 · 고치기·지우기·공유 링크 ✕
#   T-G0-10 자기 공격     주소 추측(폴더 번호 연속 — 없는 번호와 같은 403) · 폴더 옮겨 상속 우회 · 남의 개인 폴더로 옮기기 · 등급 바꾸기 경쟁 ·
#                        요청 폭주 · 매트릭스 편집으로 개인 읽기 켜기(DB CHECK)
#   T-G0-11 정적         php -l · 보안 린트 0 · 부품 버전 --check · wiki_check 0 · innerHTML·인라인 style 더함 0 · 토큰 창구 목록 그대로
#   T-G0-12 브라우저     tests/browser/g0_private_grants.py(360·412 터치·820 터치·1280 × 라이트·다크)
#
# ★ 운영 DB 를 건드리지 않는다 — stage7d4.sh 와 같은 틀(임시 DB `tbox_g0_test`, 포트 8413) · 검토 계정 claude_(F-71).
#   운영 tbox_re 는 pg_dump 로 **읽기만** 한다(파이프 — 덤프 파일 0). 임시 `.env` 세 줄(DB_NAME·STORAGE_DIR·AUDIT_LOG_FILE)이 임시를
#   가리키는지 서버를 띄우기 **전에** 보고, 아니면 exit 3. 임시 DB 에 G-0 마이그레이션을 **원본 그대로** 붓는다(postgres — 운영과 같은 계정).
# 쓰는 법: bash tests/g0_private_grants.sh   (복제본에는 .env 가 없으므로 부르는 쪽이 0600 사본을 트리 뿌리에 두고 부른다 — 끝나면 지운다)
#          환경 REGRESS=1 이면 끝에 회귀 목록을 같은 임시 인스턴스에서(8082 → 임시로 sed 치환 · writes_prod 0)
#               SHOTS=<폴더>(기본 ~/.claude/ui-shots/g0) · BROWSER_LOCK=<잠금 파일>(있으면 flock -x 아래에서 브라우저 단계) · NO_BROWSER=1
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
. "$ROOT/tests/lib/secrets.sh" || { echo "tests/lib/secrets.sh 를 읽지 못했다 — 시험을 시작하지 않는다 (R-62)"; exit 3; }
PORT="${G0_PORT:-8413}"
case "$PORT" in 8411|8412|8413|8414|8415) ;; *) echo "이 조각의 포트는 8411~8415 만 — $PORT 거부"; exit 3 ;; esac
SITE="http://127.0.0.1:$PORT"
BASE="$SITE/tbox"
CBASE="$SITE/community"
UA="tbox-review/$(date +%F)"
SRC_DB="tbox_re"                 # 운영 — **읽기만** 한다
DB="tbox_g0_${PORT}_test"        # 임시 — 여기에만 쓴다
BASE_REV="${BASE_REV:-c2b4d76}"  # 이 조각의 기준 판
SHOTS="${SHOTS:-$HOME/.claude/ui-shots/g0}"
JAR_DIR="$(mktemp -d)"
WORK="$(mktemp -d)"
chmod 700 "$JAR_DIR" "$WORK"
INST=""
SERVER_PID=""
PASS=0
FAIL=0
SKIP=0
TAG="g0$(date +%H%M%S)"

hr()  { printf '\n════════════════════════════════════════════════════════════\n%s\n════════════════════════════════════════════════════════════\n' "$1"; }
sub() { printf '\n── %s\n' "$1"; }
expect() {
  if [ "$2" = "$3" ]; then printf '  ✔ %s  (기대=%s 실제=%s)\n' "$1" "$2" "$3"; PASS=$((PASS+1))
  else printf '  ✖ %s  (기대=%s 실제=%s)\n' "$1" "$2" "$3"; FAIL=$((FAIL+1)); fi
}
contains() {
  case "$3" in
    *"$2"*) printf '  ✔ %s  (「%s」 포함)\n' "$1" "$2"; PASS=$((PASS+1)) ;;
    *)      printf '  ✖ %s  (「%s」 없음 — 실제: %s)\n' "$1" "$2" "$(printf '%s' "$3" | head -c 400)"; FAIL=$((FAIL+1)) ;;
  esac
}
lacks() {
  case "$3" in
    *"$2"*) printf '  ✖ %s  (「%s」 가 응답에 있다 — 실제: %s)\n' "$1" "$2" "$(printf '%s' "$3" | head -c 400)"; FAIL=$((FAIL+1)) ;;
    *)      printf '  ✔ %s  (「%s」 없음)\n' "$1" "$2"; PASS=$((PASS+1)) ;;
  esac
}
note() { printf '  · %s\n' "$1"; }
skip() { printf '  ○ SKIP %s  (%s)\n' "$1" "$2"; SKIP=$((SKIP+1)); }
browser_lock_on() {
  [ -n "${BROWSER_LOCK:-}" ] || return 0
  exec 9>>"$BROWSER_LOCK"; flock -x 9
  local i a
  for i in $(seq 1 15); do
    a=$(free -m | awk '/^Mem:/{print $7}'); [ "${a:-0}" -ge 700 ] && break
    echo "  · 메모리 여유 ${a}MB < 700MB — 1분 기다림($i/15)"; sleep 60
  done
  echo "  · 브라우저 잠금 안 · 메모리 여유 $(free -m | awk '/^Mem:/{print $7}')MB · $(date +%T)"
}
browser_lock_off() { [ -n "${BROWSER_LOCK:-}" ] || return 0; flock -u 9; exec 9>&-; }

cleanup() {
  if [ -n "$SERVER_PID" ]; then kill "$SERVER_PID" 2>/dev/null; wait "$SERVER_PID" 2>/dev/null; fi
  ( cd / && sudo -n -u postgres dropdb --if-exists "$DB" >/dev/null 2>&1 )
  [ -n "$INST" ] && rm -rf "$INST"
  rm -rf "$JAR_DIR" "$WORK"
}
trap cleanup EXIT

# ─── 임시 DB 에만 쓰는 psql — SQL 은 표준입력으로(명령 줄 한 모양 — AL-9) ───
psqlq() { ( cd / && printf '%s\n' "$1" | sudo -n -u postgres psql -X -q -d "$DB" -t -A 2>&1 ); }
mark()  { psqlq "SELECT coalesce(max(id),0) FROM audit_logs"; }
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
review_password() { sudo -n awk -v u="$1" '$1==u{print $3}' /etc/tbox-v2/seed-accounts.txt 2>/dev/null; }
review_secret()   { sudo -n awk -v u="$1" '$1==u{print $2}' /etc/tbox-v2/qa-totp.txt 2>/dev/null; }

login() {
  local who="$1" jar="$JAR_DIR/$1.jar" pw sec raw code left out try body
  pw=$(review_password "$who")
  sec=$(review_secret "$who")
  [ -n "$pw" ] || return 2
  for try in 1 2 3; do
    if [ -n "$sec" ]; then
      raw=$(totp_now sec 2>/dev/null)
      code=${raw%%$'\n'*}
      printf -v body '{"username":"%s","password":"%s","otp":"%s"}' "$who" "$pw" "$code"
    else
      printf -v body '{"username":"%s","password":"%s"}' "$who" "$pw"
    fi
    out=$(curl_json_secret body -s -c "$jar" -A "$UA" -H 'Content-Type: application/json' "$BASE/api/login")
    case "$out" in
      *'"ok":true'*)
        chmod 600 "$jar"
        curl -s -b "$jar" -A "$UA" "$BASE/api/me" | sed -n 's/.*"csrf_token":"\([0-9a-f]*\)".*/\1/p'
        return 0 ;;
    esac
    left=$(printf '%s' "$raw" | sed -n 's/^남은 시간: \([0-9]*\)초.*/\1/p')
    [ -n "$left" ] || left=31
    printf '  · %s 로그인 재시도 — 다음 코드 창까지 %s초\n' "$who" "$left" >&2
    sleep $((left + 1))
  done
  return 1
}

# 창구 호출 — $1 메서드 $2 경로(/tbox·/community 뒤) $3 계정 $4 본문 파일(없으면 생략). 머리 $WORK/h · 본문 $WORK/b · 상태를 찍는다
_call() {
  local base="$1" method="$2" path="$3" who="$4" bodyfile="${5:-}" jar csrf
  jar="$JAR_DIR/$who.jar"
  eval "csrf=\${CSRF_${who}:-}"
  if [ -z "$bodyfile" ]; then
    curl -s -D "$WORK/h" -o "$WORK/b" -A "$UA" -b "$jar" -X "$method" -H 'Expect:' -H "X-CSRF-Token: $csrf" "$base$path"
  else
    curl -s -D "$WORK/h" -o "$WORK/b" -A "$UA" -b "$jar" -X "$method" -H 'Expect:' -H 'Content-Type: application/json' \
      -H "X-CSRF-Token: $csrf" --data-binary "@$bodyfile" "$base$path"
  fi
  status_of "$WORK/h"
}
tapi() { _call "$BASE" "$@"; }
capi() { _call "$CBASE" "$@"; }
# JSON 본문은 파이썬이 만든다(셸 따옴표에 한글이 섞이지 않게). $1 = 파이썬 식
jbody() { python3 -c "import json;print(json.dumps($1, ensure_ascii=False))" > "$WORK/body.json"; }
# 올리기 — $1 계정 $2 파일 이름 $3 등급 $4 폴더 번호(없으면 루트) $5 내용(없으면 이름) → 상태(본문은 $WORK/b)
upload() {
  local who="$1" name="$2" level="$3" folder="${4:-}" content="${5:-$2}" jar csrf
  jar="$JAR_DIR/$who.jar"; eval "csrf=\${CSRF_${who}:-}"
  printf '%s\n' "$content" > "$WORK/up.txt"
  if [ -n "$folder" ]; then
    curl -s -D "$WORK/h" -o "$WORK/b" -A "$UA" -b "$jar" -H 'Expect:' -H "X-CSRF-Token: $csrf" \
      -F "file=@$WORK/up.txt;filename=$name" -F "access_level=$level" -F "folder_id=$folder" "$BASE/api/files"
  else
    curl -s -D "$WORK/h" -o "$WORK/b" -A "$UA" -b "$jar" -H 'Expect:' -H "X-CSRF-Token: $csrf" \
      -F "file=@$WORK/up.txt;filename=$name" -F "access_level=$level" "$BASE/api/files"
  fi
  status_of "$WORK/h"
}
uid() { psqlq "SELECT id FROM users WHERE username='$1'"; }
fid_of() { psqlq "SELECT public_id FROM files WHERE original_name='$1' AND deleted_at IS NULL ORDER BY id DESC LIMIT 1"; }
fnum_of() { psqlq "SELECT id FROM folders WHERE name='$1' ORDER BY id DESC LIMIT 1"; }
rows_since() { psqlq "SELECT count(*) FROM audit_logs WHERE id > ${1:-0} AND $2"; }
reauth() {
  local who="$1" pw rc
  pw=$(review_password "$who")
  eval "csrf=\${CSRF_${who}:-}"
  rc=$(py_secret pw -- python3 -c "import json,sys;print(json.dumps({'password':sys.stdin.readline().rstrip('\n')}))" \
       | curl -s -o /dev/null -w '%{http_code}' -b "$JAR_DIR/$who.jar" -A "$UA" -H 'Content-Type: application/json' \
        -H "X-CSRF-Token: $csrf" --data-binary @- "$BASE/api/me/reauth")
  pw=''
  printf '%s' "$rc"
}

# ════════════════════════════════════════════════════════════════
hr "G-0 — 임시 인스턴스 준비 (운영 DB 에는 쓰지 않는다)"
avail_kb=$(df -Pk / | awk 'NR==2{print $4}')
echo "루트 파티션 여유: $(df -Ph / | awk 'NR==2{print $4}') (${avail_kb}KB)"
[ "$avail_kb" -ge 1258291 ] || { echo "루트 디스크 여유 1.2GB 미만 — 시험을 시작하지 않는다 (SECURITY R-80)"; exit 3; }
( cd / && printf 'SELECT 1;\n' | sudo -n -u postgres psql -X -q -d "$SRC_DB" -tA >/dev/null 2>&1 ) \
  || { echo "psql(postgres) 을 쓸 수 없다 — 이 시험은 임시 DB 가 없으면 성립하지 않는다"; exit 3; }
if ss -ltn 2>/dev/null | grep -qE "127\.0\.0\.1:$PORT "; then
  echo "포트 $PORT 이 이미 쓰이고 있다 — 앞선 시험이 남긴 서버를 먼저 내린다"; exit 3
fi

sub "① 운영 DB 를 **읽어** 임시 DB 를 만든다(파이프 — 덤프 파일 0)"
( cd / && sudo -n -u postgres dropdb --if-exists "$DB" >/dev/null 2>&1 )
( cd / && sudo -n -u postgres createdb -O tbox_owner "$DB" >/dev/null 2>&1 ) || { echo "임시 DB 를 만들 수 없다"; exit 3; }
( cd / && sudo -n -u postgres pg_dump -d "$SRC_DB" 2>/dev/null \
  | sudo -n -u postgres psql -q -d "$DB" -v ON_ERROR_STOP=0 >/dev/null 2>"$WORK/restore.err"; exit "${PIPESTATUS[0]}" )
[ "$?" -eq 0 ] || { echo "운영 DB 를 읽지 못했다 (pg_dump)"; exit 3; }
n_users=$(psqlq "SELECT count(*) FROM public.users")
[ -n "$n_users" ] || { echo "임시 DB 로 옮기지 못했다"; exit 3; }
echo "  · 임시 DB $DB — 계정 ${n_users}명"

hr "T-G0-1  스키마 — 마이그레이션(원본 그대로 · postgres) 두 번 · CHECK · 행 · 권한"
MIG="$ROOT/db/migrations/2026-10-10_access_grants.sql"
expect "적용 전 — access_grants 없음 · private 줄 0" "- 0" "$(psqlq "SELECT coalesce(to_regclass('public.access_grants')::text,'-'), (SELECT count(*) FROM permissions WHERE access_level='private')" | tr '|' ' ')"
( cd / && sudo -n -u postgres psql -X -q -d "$DB" -v ON_ERROR_STOP=1 -f - ) < "$MIG" > "$WORK/mig1.txt" 2>&1; rc1=$?
( cd / && sudo -n -u postgres psql -X -q -d "$DB" -v ON_ERROR_STOP=1 -f - ) < "$MIG" > "$WORK/mig2.txt" 2>&1; rc2=$?
expect "마이그레이션 첫 번 · 두 번째(멱등) 종료 코드" "0 0" "$rc1 $rc2"
grep -iE 'error|오류' "$WORK/mig1.txt" "$WORK/mig2.txt" | head -3 | sed 's/^/    /'
expect "CHECK 셋 — 등급 다섯 값(permissions·files·folders)" "3" \
  "$(psqlq "SELECT count(*) FROM pg_constraint WHERE conname IN ('permissions_access_level_check','files_access_level_check','folders_access_level_check') AND pg_get_constraintdef(oid) LIKE '%private%'")"
expect "개인 줄 4 · 읽기 참 0 · 올리기 참 3(손님 거짓)" "4|0|3" \
  "$(psqlq "SELECT count(*), count(*) FILTER (WHERE can_read), count(*) FILTER (WHERE can_upload) FROM permissions WHERE access_level='private'")"
expect "권한 표 모두 20행(16 + 개인 4)" "20" "$(psqlq "SELECT count(*) FROM permissions")"
out=$(psqlq "UPDATE permissions SET can_read = true WHERE access_level='private' AND role='Admin'")
contains "개인 읽기 켜기(매트릭스 편집이 하려 해도) → CHECK permissions_private_by_people 거부" "permissions_private_by_people" "$out"
out=$(psqlq "INSERT INTO folders (name, depth, access_level, team_id, created_by) SELECT 'g0-chk', 1, 'private', (SELECT min(id) FROM teams), (SELECT min(id) FROM users)")
contains "개인 폴더에 팀 → CHECK folders_private_no_team 거부" "folders_private_no_team" "$out"
expect "권한 — tbox_app 표 둘 SIUD · tbox_backup SELECT · tbox_perm 0" "t t t t t t t t|t f|f f" \
  "$(psqlq "SELECT has_table_privilege('tbox_app','public.access_grants','SELECT'), has_table_privilege('tbox_app','public.access_grants','INSERT'), has_table_privilege('tbox_app','public.access_grants','UPDATE'), has_table_privilege('tbox_app','public.access_grants','DELETE'), has_table_privilege('tbox_app','public.access_requests','SELECT'), has_table_privilege('tbox_app','public.access_requests','INSERT'), has_table_privilege('tbox_app','public.access_requests','UPDATE'), has_table_privilege('tbox_app','public.access_requests','DELETE')" | tr '|' ' ')|$(psqlq "SELECT has_table_privilege('tbox_backup','public.access_grants','SELECT'), has_table_privilege('tbox_backup','public.access_grants','INSERT')" | tr '|' ' ')|$(psqlq "SELECT has_table_privilege('tbox_perm','public.access_grants','SELECT'), has_table_privilege('tbox_perm','public.access_requests','SELECT')" | tr '|' ' ')"
expect "tbox_app 은 permissions 에 여전히 SELECT 만(UPDATE 거짓)" "t f" \
  "$(psqlq "SELECT has_table_privilege('tbox_app','public.permissions','SELECT'), has_table_privilege('tbox_app','public.permissions','UPDATE')" | tr '|' ' ')"
contains "알림 kind CHECK 에 access_request·access_decided" "access_decided" \
  "$(psqlq "SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conname='notifications_kind_check'")"
expect "알림 칸 access_request_id(→ access_requests · ON DELETE SET NULL)" "access_request_id|n" \
  "$(psqlq "SELECT a.attname, c.confdeltype FROM pg_constraint c JOIN pg_attribute a ON a.attrelid=c.conrelid AND a.attnum = ANY(c.conkey) WHERE c.conrelid='community.notifications'::regclass AND c.contype='f' AND c.confrelid='public.access_requests'::regclass")"
# 커뮤니티 grants.sql 은 재실행해도 새 칸 권한이 그대로다(표 단위 권한)
( cd / && sudo -n -u postgres psql -X -q -d "$DB" -v ON_ERROR_STOP=1 -f - ) < "$ROOT/db/grants.sql" > "$WORK/grants.txt" 2>&1
expect "db/grants.sql 재실행 — 오류 0 · 권한 그대로(tbox_app 표 INSERT)" "0 t" "$(grep -ciE '^ERROR|오류:' "$WORK/grants.txt" | tr -d ' ') $(psqlq "SELECT has_table_privilege('tbox_app','public.access_grants','INSERT')")"

sub "② 저장소를 임시 폴더로 복사하고 인스턴스를 띄운다"
INST="$(mktemp -d)"
chmod 700 "$INST"
mkdir -p "$INST/app" "$INST/storage" "$INST/log"
tar -C "$ROOT" -cf - --exclude=.git --exclude=jar --exclude=api-test.txt --exclude=./.env . | tar -C "$INST/app" -xf -
ENV_SRC="${G0_ENV_SRC:-$ROOT/.env}"
[ -f "$ENV_SRC" ] || { echo "$ENV_SRC 가 없다 — 임시 .env 를 만들 원본이 없어 시험을 시작하지 않는다"; exit 3; }
# qcheck-allow: R20 — 임시 인스턴스용 사본(0700·0600 · trap 정리)
sed -e "s#^DB_NAME=.*#DB_NAME=$DB#" \
    -e "s#^STORAGE_DIR=.*#STORAGE_DIR=$INST/storage#" \
    -e "s#^AUDIT_LOG_FILE=.*#AUDIT_LOG_FILE=$INST/log/audit.log#" \
    -e "s#^SCAN_ENABLED=.*#SCAN_ENABLED=0#" \
    "$ENV_SRC" > "$INST/app/.env"
chmod 600 "$INST/app/.env"
env_db=$(sed -n 's/^DB_NAME=//p' "$INST/app/.env" | tail -1)
env_st=$(sed -n 's/^STORAGE_DIR=//p' "$INST/app/.env" | tail -1)
env_au=$(sed -n 's/^AUDIT_LOG_FILE=//p' "$INST/app/.env" | tail -1)
if [ "$env_db" != "$DB" ] || [ "$env_st" != "$INST/storage" ] || [ "$env_au" != "$INST/log/audit.log" ]; then
  echo "임시 .env 의 DB_NAME·STORAGE_DIR·AUDIT_LOG_FILE 이 임시를 가리키지 않는다 — 중단"; exit 3
fi
[ "$(grep -c '^DB_NAME=' "$INST/app/.env")" = "1" ] || { echo "임시 .env 에 DB_NAME 줄이 하나가 아니다 — 중단"; exit 3; }
echo "  · 임시 .env: DB_NAME=임시($DB) · STORAGE_DIR=임시 · AUDIT_LOG_FILE=임시 — 확인"
ln -s /var/www/qaredi/community "$INST/community" 2>/dev/null
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
                      'map' => 'application/json', 'txt' => 'text/plain', 'woff2' => 'font/woff2'];
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
( cd "$INST/app" && exec php -S "127.0.0.1:$PORT" -t public router.php > "$INST/server.log" 2>&1 ) &
SERVER_PID=$!
for _ in 1 2 3 4 5 6 7 8 9 10; do
  sleep 1
  READY=$(curl -s -o /dev/null -w '%{http_code}' -A "$UA" "$BASE/login")
  [ "$READY" = "200" ] && break
done
expect "임시 인스턴스가 떴다 (로그인 화면 200)" "200" "$READY"
[ "$READY" = "200" ] || { echo "인스턴스를 띄우지 못했다 — 중단"; tail -5 "$INST/server.log"; exit 4; }

sub "로그인 (claude_ 검토 계정 다섯 — **순차**, F-71)"
for who in claude_employee01 claude_employee02 claude_manager01 claude_admin01 claude_guest01; do
  tok=$(login "$who")
  if [ -z "$tok" ]; then echo "  ✖ $who 로그인 실패 — 중단"; exit 4; fi
  eval "CSRF_${who}='$tok'"
  echo "  ✔ $who"
done
U_E1=$(uid claude_employee01); U_E2=$(uid claude_employee02); U_M1=$(uid claude_manager01); U_A1=$(uid claude_admin01); U_G1=$(uid claude_guest01)
note "계정 번호: employee01=$U_E1 employee02=$U_E2 manager01=$U_M1 admin01=$U_A1 guest01=$U_G1"
# 이름(표시 이름) — 임시 DB 에서만: 직원2 에게 이름을 준다(이름으로 권한 주기 · 같은 이름 여럿)
psqlq "UPDATE users SET display_name = '검토지공이' WHERE username = 'claude_employee02'" > /dev/null
M0=$(mark)
note "시작 감사 번호 $M0"

# ════════════════════════════════════════════════════════════════
hr "T-G0-2  판정 ↔ 목록 조건절 짝 전수 대조 (C:T-56 방식 — 기대값은 따로 계산)"
cat > "$WORK/pair.php" <<'PHP'
<?php
declare(strict_types=1);
// 임시 인스턴스 폴더(cwd)의 앱 연결 = 임시 DB. 시나리오마다 BEGIN … ROLLBACK — 이 파일이 남기는 줄은 없다(고정 틀만 COMMIT).
$app = getcwd();
require $app . '/scripts/lib/cli_bootstrap.php';
$db  = Db::instance();
$in  = json_decode((string) stream_get_contents(STDIN), true);
$tag = (string) $in['tag'];
function u(string $name): array {
    $r = Db::instance()->run('SELECT id, username, role, team_id, is_active FROM users WHERE username = :u', [':u' => $name])->fetch();
    if (!is_array($r)) { fwrite(STDERR, "계정 없음 $name\n"); exit(3); }
    return ['id' => (int) $r['id'], 'username' => (string) $r['username'], 'role' => (string) $r['role'],
            'team_id' => $r['team_id'] === null ? null : (int) $r['team_id'], 'is_active' => true];
}
$owner   = u((string) $in['owner']);
$viewers = array_map('u', $in['viewers']);
// ── 고정 틀(한 번 · COMMIT): 개인 사슬 P1⊃P2⊃P3⊃P4⊃P5 · 섞인 사슬 I1(사내)⊃Q2⊃Q3(개인) · 파일 f0(맨 위) f1..f5 · h1(I1) g2 g3 ──
$mkFolder = static function (string $name, ?int $parent, int $depth, string $level) use ($db, $owner): int {
    $r = $db->run('INSERT INTO folders (parent_id, name, depth, access_level, created_by) VALUES (CAST(:p AS bigint), :n, :d, :l, :o) RETURNING id',
        [':p' => $parent, ':n' => $name, ':d' => $depth, ':l' => $level, ':o' => $owner['id']])->fetch();
    return (int) $r['id'];
};
$mkFile = static function (string $name, ?int $folder, string $level) use ($db, $owner): int {
    $r = $db->run("INSERT INTO files (original_name, stored_name, ext, mime, size_bytes, sha256, access_level, uploaded_by, folder_id)
                   VALUES (:n, :s, 'txt', 'text/plain', 1, repeat('0', 64), :l, :o, CAST(:f AS bigint)) RETURNING id",
        [':n' => $name, ':s' => bin2hex(random_bytes(16)) . '.txt', ':l' => $level, ':o' => $owner['id'], ':f' => $folder])->fetch();
    return (int) $r['id'];
};
$F = []; $parent = null;
for ($d = 1; $d <= 5; $d++) { $F[$d] = $mkFolder("$tag-P$d", $parent, $d, 'private'); $parent = $F[$d]; }
$I1 = $mkFolder("$tag-I1", null, 1, 'internal');
$Q2 = $mkFolder("$tag-Q2", $I1, 2, 'private');
$Q3 = $mkFolder("$tag-Q3", $Q2, 3, 'private');
$files = ['f0' => $mkFile("$tag-f0", null, 'private')];
for ($d = 1; $d <= 5; $d++) { $files["f$d"] = $mkFile("$tag-f$d", $F[$d], 'private'); }
$files['h1'] = $mkFile("$tag-h1", $I1, 'private');
$files['g2'] = $mkFile("$tag-g2", $Q2, 'private');
$files['g3'] = $mkFile("$tag-g3", $Q3, 'private');
$folders = []; for ($d = 1; $d <= 5; $d++) { $folders["P$d"] = $F[$d]; }
$folders['I1'] = $I1; $folders['Q2'] = $Q2; $folders['Q3'] = $Q3;
// 구조(기대값 계산용 — SQL 조각을 쓰지 않는다)
$fParent = []; $fLevel = [];
for ($d = 1; $d <= 5; $d++) { $fParent["P$d"] = $d === 1 ? null : 'P' . ($d - 1); $fLevel["P$d"] = 'private'; }
$fParent['I1'] = null; $fLevel['I1'] = 'internal'; $fParent['Q2'] = 'I1'; $fLevel['Q2'] = 'private'; $fParent['Q3'] = 'Q2'; $fLevel['Q3'] = 'private';
$fileFolder = ['f0' => null, 'h1' => 'I1', 'g2' => 'Q2', 'g3' => 'Q3'];
for ($d = 1; $d <= 5; $d++) { $fileFolder["f$d"] = "P$d"; }

$positions = ['none', 'owner_item', 'chain_owner', 'folder_view', 'folder_write', 'ancestor_view', 'file_view', 'file_write',
              'np_top_grant', 'np_top_owner'];
$total = 0; $bad = 0; $table = [];
foreach ($viewers as $v) {
    foreach ($positions as $pos) {
        foreach ([1, 2, 3, 4, 5] as $d) {
            $db->begin();
            // 시나리오 → DB 에 쓰고, 같은 사실로 기대값 모델을 만든다
            $ownerOf = [];  // 노드키 => 주인 id (기본 owner)
            foreach ($folders as $k => $id) { $ownerOf["d:$k"] = $owner['id']; }
            foreach ($files as $k => $id) { $ownerOf["f:$k"] = $owner['id']; }
            $grants = [];   // 노드키 => write?
            $setOwner = static function (string $key) use ($db, &$ownerOf, $v, $folders, $files): void {
                $ownerOf[$key] = $v['id'];
                [$t, $k] = explode(':', $key, 2);
                if ($t === 'd') { $db->run('UPDATE folders SET created_by = CAST(:u AS bigint) WHERE id = CAST(:i AS bigint)', [':u' => $v['id'], ':i' => $folders[$k]]); }
                else { $db->run('UPDATE files SET uploaded_by = CAST(:u AS bigint) WHERE id = CAST(:i AS bigint)', [':u' => $v['id'], ':i' => $files[$k]]); }
            };
            $grant = static function (string $key, bool $w) use ($db, &$grants, $v, $folders, $files, $owner): void {
                $grants[$key] = $w;
                [$t, $k] = explode(':', $key, 2);
                if ($t === 'd') { $db->run('INSERT INTO access_grants (folder_id, user_id, can_write, granted_by) VALUES (CAST(:i AS bigint), CAST(:u AS bigint), CAST(:w AS boolean), CAST(:g AS bigint))', [':i' => $folders[$k], ':u' => $v['id'], ':w' => $w ? 'true' : 'false', ':g' => $owner['id']]); }
                else { $db->run('INSERT INTO access_grants (file_id, user_id, can_write, granted_by) VALUES (CAST(:i AS bigint), CAST(:u AS bigint), CAST(:w AS boolean), CAST(:g AS bigint))', [':i' => $files[$k], ':u' => $v['id'], ':w' => $w ? 'true' : 'false', ':g' => $owner['id']]); }
            };
            switch ($pos) {
                case 'owner_item': $setOwner("d:P$d"); $setOwner("f:f$d"); break;
                case 'chain_owner': $setOwner("d:P$d"); break;
                case 'folder_view': $grant("d:P$d", false); break;
                case 'folder_write': $grant("d:P$d", true); break;
                case 'ancestor_view': $grant('d:P' . max(1, $d - 1), false); if ($d <= 3) { $grant('d:Q' . max(2, $d), false); } break;
                case 'file_view': $grant("f:f$d", false); break;
                case 'file_write': $grant("f:f$d", true); break;
                case 'np_top_grant': $grant('d:I1', $d % 2 === 0); break;
                case 'np_top_owner': $setOwner('d:I1'); break;
            }
            // 기대값 — 사슬: 항목 자신 + 위로 개인 폴더인 동안
            $chain = static function (string $key) use ($fParent, $fLevel, $fileFolder): array {
                [$t, $k] = explode(':', $key, 2);
                $out = [];
                if ($t === 'f') { $out[] = $key; $cur = $fileFolder[$k]; }
                else { if ($fLevel[$k] !== 'private') { return []; } $out[] = $key; $cur = $fParent[$k]; }
                while ($cur !== null && $fLevel[$cur] === 'private') { $out[] = "d:$cur"; $cur = $fParent[$cur]; }
                return $out;
            };
            $expectReach = static function (string $key) use ($chain, $ownerOf, $grants, $v): ?string {
                $c = $chain($key);
                foreach ($c as $n) { if ($ownerOf[$n] === $v['id']) { return 'owner'; } }
                foreach ($c as $n) { if (($grants[$n] ?? null) === true) { return 'write'; } }
                foreach ($c as $n) { if (array_key_exists($n, $grants)) { return 'view'; } }
                return null;
            };
            $matrixInternal = in_array($v['role'], ['Admin', 'Manager', 'Employee'], true);   // 사내 읽기(운영 표 그대로)
            $isAdmin = $v['role'] === 'Admin';
            // 목록 조건절 — 파일·폴더 각각 한 문장
            [$wf, $bf] = Authz::visibleWhere($v, 'f');
            $bf[':ids'] = '{' . implode(',', $files) . '}';
            $listF = array_map('intval', array_column($db->run('SELECT f.id FROM files f WHERE f.id = ANY(CAST(:ids AS bigint[])) AND ' . $wf, $bf)->fetchAll(), 'id'));
            [$wd, $bd] = Authz::visibleWhere($v, 'd');
            $bd[':ids'] = '{' . implode(',', $folders) . '}';
            $listD = array_map('intval', array_column($db->run('SELECT d.id FROM folders d WHERE d.id = ANY(CAST(:ids AS bigint[])) AND ' . $wd, $bd)->fetchAll(), 'id'));
            $items = [];
            foreach ($files as $k => $id) { $items["f:$k"] = ['id' => $id, 'item_kind' => 'file', 'access_level' => 'private', 'team_id' => null, 'uploaded_by' => $ownerOf["f:$k"]]; }
            foreach ($folders as $k => $id) { $items["d:$k"] = ['id' => $id, 'item_kind' => 'folder', 'access_level' => $fLevel[$k], 'team_id' => null, 'created_by' => $ownerOf["d:$k"]]; }
            foreach ($items as $key => $row) {
                $private = $row['access_level'] === 'private';
                $reach = $private ? $expectReach($key) : null;
                $expList = $private ? $reach !== null : $matrixInternal;
                $expRead = $expList || ($private && $isAdmin);
                $expCode = !$private ? ($matrixInternal ? 'matrix' : 'role_not_permitted') : ($reach === 'owner' ? 'owner' : ($reach !== null ? 'grant' : ($isAdmin ? 'admin_override' : 'role_not_permitted')));
                $expEdit = $private ? ($reach === 'owner' || $reach === 'write') : null;
                $inList = $row['item_kind'] === 'file' ? in_array($row['id'], $listF, true) : in_array($row['id'], $listD, true);
                [$rd, $code] = Authz::decideRead($v, $row);
                $seeList = Authz::canSeeInList($v, $row);
                $gotReach = Authz::privateReach($v, $row['item_kind'], $row['id']);
                $edit = $private ? Authz::decideEdit($v, $row)[0] : null;
                $ok = $inList === $expList && $seeList === $expList && $rd === $expRead && $code === $expCode
                    && $gotReach === $reach && $edit === $expEdit;
                $total++;
                $cell = $v['role'] . '|' . $pos;
                $table[$cell] = ($table[$cell] ?? 0) + ($ok ? 0 : 1);
                if (!$ok) {
                    $bad++;
                    if ($bad <= 12) {
                        fwrite(STDOUT, sprintf("  불일치 %s %s d=%d %s — 목록 %s/%s 판정 %s(%s)/%s(%s) reach %s/%s edit %s/%s\n",
                            $v['role'], $pos, $d, $key, var_export($inList, true), var_export($expList, true),
                            var_export($rd, true), $code, var_export($expRead, true), $expCode,
                            var_export($gotReach, true), var_export($reach, true), var_export($edit, true), var_export($expEdit, true)));
                    }
                }
            }
            $db->rollback();
        }
    }
}
foreach ($table as $cell => $n) { fwrite(STDOUT, sprintf("  표 %-28s 불일치 %d\n", $cell, $n)); }
fwrite(STDOUT, "PAIR_TOTAL=$total\nPAIR_BAD=$bad\n");
PHP
# 고정 틀은 커밋된다(임시 DB — 시험이 끝나면 통째로 지운다). 시나리오 쓰기는 전부 되돌린다.
printf '{"tag":"%s","owner":"claude_employee01","viewers":["claude_admin01","claude_manager01","claude_employee02","claude_guest01"]}' "$TAG-pair" \
  | ( cd "$INST/app" && php "$WORK/pair.php" ) > "$WORK/pair.txt" 2>&1
grep -E '^  (불일치|표 )' "$WORK/pair.txt" | head -60
PAIR_TOTAL=$(sed -n 's/^PAIR_TOTAL=//p' "$WORK/pair.txt")
PAIR_BAD=$(sed -n 's/^PAIR_BAD=//p' "$WORK/pair.txt")
note "사례 수(계정 4 × 자리 10 × 깊이 5 × 항목 17) = ${PAIR_TOTAL:-?}"
expect "짝 대조 사례 수 3400" "3400" "${PAIR_TOTAL:-0}"
expect "불일치 0 (목록 조건절 = canSeeInList = 기대값 · decideRead·코드 = 기대값 · privateReach · decideEdit)" "0" "${PAIR_BAD:-x}"
[ -n "$PAIR_TOTAL" ] || tail -5 "$WORK/pair.txt" | sed 's/^/    /'

# ════════════════════════════════════════════════════════════════
hr "준비 — 직원1 의 개인 폴더 A ⊃ B · 개인 파일 · 팀 파일 (창구로)"
FA="$TAG-A"; FB="$TAG-B"
jbody "{'name': '$FA', 'access_level': 'private'}"; expect "개인 폴더 A 만들기 201" "201" "$(tapi POST /api/folders claude_employee01 "$WORK/body.json")"
A=$(fnum_of "$FA")
jbody "{'name': '$FB', 'access_level': 'private', 'parent_id': $A}"; expect "A 안에 개인 폴더 B 201" "201" "$(tapi POST /api/folders claude_employee01 "$WORK/body.json")"
B=$(fnum_of "$FB")
jbody "{'name': '$TAG-bad', 'access_level': 'team', 'team_id': 1, 'parent_id': $A}"
expect "개인 폴더 안에 팀 폴더 → 400 parent_narrower(불변 ①이 rank 로)" "400" "$(tapi POST /api/folders claude_employee01 "$WORK/body.json")"
SECRET="G0SECRET$TAG"
expect "개인 파일 P(B 안) 올리기 201" "201" "$(upload claude_employee01 "$SECRET-P.txt" private "$B" "비밀 내용 $SECRET")"
P=$(fid_of "$SECRET-P.txt")
expect "개인 파일 R(맨 위) 올리기 201" "201" "$(upload claude_employee01 "$SECRET-R.txt" private "" "맨 위 $SECRET")"
R=$(fid_of "$SECRET-R.txt")
expect "개인 폴더에 팀 등급 파일 → 400(폴더보다 넓다)" "400" "$(upload claude_employee01 "$TAG-team-in-private.txt" team "$A")"
expect "손님 개인 올리기 → 403(라우트 file.upload)" "403" "$(upload claude_guest01 "$TAG-guest.txt" private)"
note "A=$A B=$B P=$P R=$R"

# ════════════════════════════════════════════════════════════════
hr "T-G0-3  읽기 경로 전수 — 권한 없는 계정이 이름·바이트를 하나도 못 얻는다"
: > "$WORK/leak.txt"
leakcheck() {   # $1 설명 $2 계정 — 본문에 SECRET·폴더 이름이 없어야
  cat "$WORK/b" >> "$WORK/leak.txt"
  lacks "$1 — 개인 파일 이름 없음" "$SECRET" "$(cat "$WORK/b")"
  lacks "$1 — 개인 폴더 이름 없음" "$FA" "$(cat "$WORK/b")"
}
for who in claude_employee02 claude_manager01 claude_guest01; do
  sub "$who"
  st=$(tapi GET "/api/folders?parent=0" "$who"); expect "폴더 트리 맨 위 200" "200" "$st"; leakcheck "폴더 트리" "$who"
  st=$(tapi GET "/api/folders?parent=$A" "$who"); expect "개인 폴더 A 펼치기 → 403" "403" "$st"
  st=$(tapi GET "/api/folders?parent=999999998" "$who"); B403=$(cat "$WORK/b"); expect "없는 폴더 번호 → 같은 403 · 같은 본문" "403 1" "$st $([ "$B403" = '{"error":"권한이 없습니다"}' ] && echo 1 || echo 0)"
  st=$(tapi GET "/api/files?folder=$B" "$who"); expect "개인 폴더 B 목록 → 403" "403" "$st"
  st=$(tapi GET "/api/files?folder=0" "$who"); leakcheck "맨 위 파일 목록" "$who"
  st=$(tapi GET "/api/files?q=$SECRET" "$who"); expect "전역 검색 200 · 0건" "200 0" "$st $(jget "d['total']" < "$WORK/b")"; leakcheck "전역 검색" "$who"
  for path in download preview preview/raw; do
    st=$(tapi GET "/api/files/$P/$path" "$who"); expect "$path → 403" "403" "$st"; leakcheck "$path" "$who"
  done
  jbody "{'password': 'x', 'otp': '000000'}"; st=$(tapi POST "/api/files/$P/exec-download" "$who" "$WORK/body.json"); expect "실행 확인 받기 → 403(읽기 판정이 먼저)" "403" "$st"
  jbody "{'ids': ['$P']}"; st=$(tapi POST /api/files/zip "$who" "$WORK/body.json"); zp="$st $(jget "d.get('skipped')" < "$WORK/b")"; leakcheck "zip" "$who"
  jbody "{'ids': ['00000000-0000-4000-8000-000000000000']}"; st=$(tapi POST /api/files/zip "$who" "$WORK/body.json"); zn="$st $(jget "d.get('skipped')" < "$WORK/b")"
  expect "zip — 개인 파일과 없는 파일이 같은 답(상태·skipped 개수)" "$zn" "$zp"
  st=$(tapi PUT "/api/files/$P/favorite" "$who"); expect "즐겨찾기 넣기 → 404(없는 것과 같다)" "404" "$st"
  st=$(tapi GET /api/files/favorites "$who"); leakcheck "즐겨찾기 목록" "$who"
  st=$(tapi GET /api/files/recent "$who"); leakcheck "최근" "$who"
  st=$(tapi GET /api/grants/mine "$who"); leakcheck "공유받음" "$who"
  st=$(tapi GET "/api/files/$P/grants" "$who"); expect "권한 줄 보기 → 403" "403" "$st"
  st=$(tapi GET "/api/folders/$A/grants" "$who"); expect "폴더 권한 줄 보기 → 403" "403" "$st"
done
sub "개수가 새지 않는다 — 맨 위 폴더의 child_count·file_count 가 개인 항목을 세지 않는다"
C_BEFORE=$(tapi GET "/api/folders?parent=0" claude_employee02 >/dev/null; jget "len(d['folders'])" < "$WORK/b")
jbody "{'name': '$TAG-cnt', 'access_level': 'private'}"; tapi POST /api/folders claude_employee01 "$WORK/body.json" > /dev/null
C_AFTER=$(tapi GET "/api/folders?parent=0" claude_employee02 >/dev/null; jget "len(d['folders'])" < "$WORK/b")
expect "직원1 이 맨 위에 개인 폴더를 더 만들어도 직원2 의 맨 위 폴더 수 그대로" "$C_BEFORE" "$C_AFTER"

sub "API 토큰(프로그램) — 목록·받기"
TOKEN=$(python3 -c "import secrets,string;print(''.join(secrets.choice(string.ascii_letters+string.digits) for _ in range(48)))")
THASH=$(printf '%s' "$TOKEN" | sha256sum | cut -d' ' -f1)
psqlq "INSERT INTO api_tokens (user_id, name, token_hash, hint, created_by, scope, expires_at) VALUES ($U_E2, 'g0 시험', '$THASH', '${TOKEN:0:6}', $U_E2, 'read', now() + interval '1 day')" > /dev/null
tok_get() { printf 'X-API-Token: %s\n' "$TOKEN" | curl -s -D "$WORK/h" -o "$WORK/b" -A "$UA" -H @- "$BASE$1"; status_of "$WORK/h"; }
expect "토큰 — 목록 200" "200" "$(tok_get '/api/files?limit=100')"; leakcheck "토큰 목록" tok
expect "토큰 — 검색 200" "200" "$(tok_get "/api/files?q=$SECRET")"; leakcheck "토큰 검색" tok
expect "토큰 — 개인 파일 받기 403" "403" "$(tok_get "/api/files/$P/download")"
expect "토큰 — 개인 폴더 목록 403" "403" "$(tok_get "/api/folders?parent=$A")"
TOKEN=''

# ════════════════════════════════════════════════════════════════
hr "T-G0-4  권한 주기·빼기 즉시 반영 (캐시 0)"
jbody "{'user': 'claude_employee02', 'write': False}"
expect "A 에 직원2 보기 주기 200" "200 True" "$(tapi POST "/api/folders/$A/grants" claude_employee01 "$WORK/body.json") $(jget "d['changed']" < "$WORK/b")"
expect "같은 값 다시 → 200 changed False(감사 늘지 않음)" "200 False" "$(tapi POST "/api/folders/$A/grants" claude_employee01 "$WORK/body.json") $(jget "d['changed']" < "$WORK/b")"
expect "곧바로 — B 안의 P 받기 200(폴더 권한이 하위로 이어진다)" "200" "$(tapi GET "/api/files/$P/download" claude_employee02)"
contains "  받은 바이트가 그 내용" "$SECRET" "$(cat "$WORK/b")"
expect "B 목록 200 · P 보임" "200 1" "$(tapi GET "/api/files?folder=$B" claude_employee02) $(jget "len(d['files'])" < "$WORK/b")"
expect "B breadcrumb 는 A·B 둘 다(둘 다 볼 수 있다)" "2" "$(jget "len(d['folder']['breadcrumb'])" < "$WORK/b")"
expect "권한 없는 R(맨 위 개인)은 여전히 403" "403" "$(tapi GET "/api/files/$R/download" claude_employee02)"
expect "빼기(사람 번호) 200" "200" "$(tapi DELETE "/api/folders/$A/grants/$U_E2" claude_employee01)"
expect "곧바로 — P 받기 403" "403" "$(tapi GET "/api/files/$P/download" claude_employee02)"
expect "없는 줄 빼기 → 404" "404" "$(tapi DELETE "/api/folders/$A/grants/$U_E2" claude_employee01)"

sub "파일 권한만 받은 사람 — 파일은 보이고 위 폴더 이름은 새지 않는다"
jbody "{'user': 'claude_guest01'}"; expect "P 에 손님 보기 200" "200" "$(tapi POST "/api/files/$P/grants" claude_employee01 "$WORK/body.json")"
st=$(tapi GET "/api/files?q=$SECRET" claude_guest01)
expect "손님 검색 — P 한 건 · folder_id null · 경로 「…」" "1 None …" "$(jget "d['total']" < "$WORK/b") $(jget "d['files'][0]['folder_id']" < "$WORK/b") $(jget "d['files'][0]['folder_path']" < "$WORK/b")"
lacks "  손님 검색 응답에 폴더 A 이름 없음" "$FA" "$(cat "$WORK/b")"
lacks "  손님 검색 응답에 폴더 B 이름 없음" "$FB" "$(cat "$WORK/b")"
expect "손님 P 받기 200" "200" "$(tapi GET "/api/files/$P/download" claude_guest01)"
st=$(tapi GET /api/grants/mine claude_guest01); contains "손님 공유받음 목록에 P" "$SECRET-P.txt" "$(cat "$WORK/b")"
st=$(tapi GET "/api/files/$P/grants" claude_guest01)
expect "손님 — 권한 줄 보기 200 · 고치기 없음(can_manage False)" "200 False" "$st $(jget "d['can_manage']" < "$WORK/b")"
jbody "{'user': 'claude_employee02'}"; expect "손님 — 남에게 주기 → 403(라우트 file.update)" "403" "$(tapi POST "/api/files/$P/grants" claude_guest01 "$WORK/body.json")"
jbody "{'user': 'claude_guest01', 'write': True}"; expect "손님에게 편집 → 400" "400" "$(tapi POST "/api/files/$P/grants" claude_employee01 "$WORK/body.json")"

# ════════════════════════════════════════════════════════════════
hr "T-G0-5  편집 권한자 — 올리기·이름·설명 ○ / 등급·옮기기·지우기·공유 링크·권한 ✕"
jbody "{'user': '검토지공이', 'write': True}"
expect "이름(표시 이름)으로 직원2 에게 A 편집 주기 200" "200 claude_employee02" "$(tapi POST "/api/folders/$A/grants" claude_employee01 "$WORK/body.json") $(jget "d['grant']['username']" < "$WORK/b")"
expect "직원2 — B 에 개인 파일 올리기 201" "201" "$(upload claude_employee02 "$TAG-by-e2.txt" private "$B")"
E2F=$(fid_of "$TAG-by-e2.txt")
expect "직원2 — B 에 공개 파일 → 400(개인 폴더 안은 개인만)" "400" "$(upload claude_employee02 "$TAG-pub.txt" public "$B")"
expect "주인(직원1)도 직원2 가 올린 파일을 본다(위 개인 폴더 주인)" "200" "$(tapi GET "/api/files/$E2F/download" claude_employee01)"
jbody "{'description': '편집 권한으로 고친 설명'}"; expect "직원2 — P 설명 고치기 200" "200" "$(tapi PATCH "/api/files/$P" claude_employee02 "$WORK/body.json")"
jbody "{'name': '$FB-rn'}"; expect "직원2 — 폴더 B 이름 바꾸기 200" "200" "$(tapi PATCH "/api/folders/$B" claude_employee02 "$WORK/body.json")"
jbody "{'name': '$FB'}"; tapi PATCH "/api/folders/$B" claude_employee02 "$WORK/body.json" > /dev/null
jbody "{'access_level': 'internal'}"; expect "직원2 — P 등급 → 403" "403" "$(tapi PATCH "/api/files/$P" claude_employee02 "$WORK/body.json")"
jbody "{'folder_id': None}"; expect "직원2 — P 옮기기 → 403" "403" "$(tapi PATCH "/api/files/$P" claude_employee02 "$WORK/body.json")"
expect "직원2 — P 지우기 → 403" "403" "$(tapi DELETE "/api/files/$P" claude_employee02)"
jbody "{'expires_hours': 1, 'max_downloads': 1}"; expect "직원2 — P 공유 링크 → 403" "403" "$(tapi POST "/api/files/$P/share" claude_employee02 "$WORK/body.json")"
jbody "{'user': 'claude_manager01'}"; expect "직원2 — A 권한 주기 → 403" "403" "$(tapi POST "/api/folders/$A/grants" claude_employee02 "$WORK/body.json")"
jbody "{'access_level': 'admin-only'}"; expect "직원2 — 폴더 B 등급 → 403" "403" "$(tapi PATCH "/api/folders/$B" claude_employee02 "$WORK/body.json")"
expect "직원2 — 폴더 B 지우기 → 403" "403" "$(tapi DELETE "/api/folders/$B" claude_employee02)"
jbody "{'parent_id': None}"; expect "직원2 — 폴더 B 옮기기 → 403" "403" "$(tapi PATCH "/api/folders/$B" claude_employee02 "$WORK/body.json")"
expect "직원2 — 자기가 올린 파일은 지운다(주인)" "200" "$(tapi DELETE "/api/files/$E2F" claude_employee02)"
sub "받을 사람 — 꺼진 계정·없는 계정·주인 자신·같은 이름 여럿"
psqlq "INSERT INTO users (username, password_hash, role, is_active, display_name) VALUES ('g0off$TAG', 'x', 'Employee', false, '검토지공이'), ('g0dup$TAG', 'x', 'Employee', true, '동명이인$TAG'), ('g0dup2$TAG', 'x', 'Employee', true, '동명이인$TAG')" > /dev/null
jbody "{'user': 'g0off$TAG'}"; st=$(tapi POST "/api/folders/$A/grants" claude_employee01 "$WORK/body.json"); OFF="$st $(cat "$WORK/b")"
jbody "{'user': 'nobody-$TAG'}"; st=$(tapi POST "/api/folders/$A/grants" claude_employee01 "$WORK/body.json"); NONE="$st $(cat "$WORK/b")"
jbody "{'user': 'claude_employee01'}"; st=$(tapi POST "/api/folders/$A/grants" claude_employee01 "$WORK/body.json"); SELF="$st $(cat "$WORK/b")"
expect "꺼진 계정 = 없는 계정 = 주인 자신 — 같은 400 같은 글자" "1 1 400" "$([ "$OFF" = "$NONE" ] && echo 1 || echo 0) $([ "$SELF" = "$NONE" ] && echo 1 || echo 0) ${NONE%% *}"
jbody "{'user': '동명이인$TAG'}"; expect "같은 이름 여럿 → 400(아이디로)" "400" "$(tapi POST "/api/folders/$A/grants" claude_employee01 "$WORK/body.json")"
contains "  문장" "아이디로" "$(cat "$WORK/b")"
jbody "{'user': 'claude_employee02'}"; expect "개인이 아닌 항목(팀 파일)에 주기 → 400" "400" \
  "$(upload claude_employee01 "$TAG-team.txt" team >/dev/null; tapi POST "/api/files/$(fid_of "$TAG-team.txt")/grants" claude_employee01 "$WORK/body.json")"
st=$(tapi GET "/api/access/people?q=" claude_employee01); expect "사람 후보 — 빈 검색어는 빈 목록" "200 0" "$st $(jget "len(d['candidates'])" < "$WORK/b")"
st=$(tapi GET "/api/access/people?q=claude_e" claude_employee01); PEOPLE=$(jget "sorted(c['username'] for c in d['candidates'])" < "$WORK/b")
contains "사람 후보 — 직원2 · 부른 사람(직원1) 빠짐" "claude_employee02" "$PEOPLE"; lacks "  부른 사람 빠짐" "claude_employee01" "$PEOPLE"
expect "사람 후보 — 손님은 라우트 file.update 403" "403" "$(tapi GET "/api/access/people?q=a" claude_guest01)"

# ════════════════════════════════════════════════════════════════
hr "T-G0-6  권한 요청 — 403 화면 → 요청 → 알림 → 허락 · 거절 · 중복 · 한도 · 존재 노출"
M6=$(mark)
N_E1_0=$(capi GET "/api/notifications?unread=1" claude_employee01 >/dev/null; jget "d['unread']" < "$WORK/b")
expect "팀장 — 폴더 A 목록 403(요청 화면이 뜨는 자리)" "403" "$(tapi GET "/api/files?folder=$A" claude_manager01)"
jbody "{'folder': $A, 'write': False, 'message': '9월 기공료 확인'}"; st=$(tapi POST /api/access-requests claude_manager01 "$WORK/body.json"); REAL="$st $(cat "$WORK/b")"
jbody "{'folder': 999999997, 'write': False, 'message': '9월 기공료 확인'}"; st=$(tapi POST /api/access-requests claude_manager01 "$WORK/body.json"); GHOST="$st $(cat "$WORK/b")"
expect "없는 폴더 요청 = 진짜 요청 — 같은 200 · 글자까지 같음" "1 200" "$([ "$REAL" = "$GHOST" ] && echo 1 || echo 0) ${REAL%% *}"
jbody "{'file': '00000000-0000-4000-8000-00000000aaaa', 'write': False}"; st=$(tapi POST /api/access-requests claude_manager01 "$WORK/body.json"); GFILE="$st $(cat "$WORK/b")"
expect "없는 파일 요청도 같은 답" "1" "$([ "$GFILE" = "$REAL" ] && echo 1 || echo 0)"
jbody "{'folder': $A, 'write': False, 'message': '다시'}"; st=$(tapi POST /api/access-requests claude_manager01 "$WORK/body.json"); DUP="$st $(cat "$WORK/b")"
expect "같은 항목 다시(중복 pending) — 같은 답 · 저장 줄 1" "1 1" "$([ "$DUP" = "$REAL" ] && echo 1 || echo 0) $(psqlq "SELECT count(*) FROM access_requests WHERE folder_id=$A AND requester_id=$U_M1 AND status='pending'")"
expect "없는 항목 요청은 저장 0" "0" "$(psqlq "SELECT count(*) FROM access_requests WHERE folder_id=999999997")"
expect "감사 — allow 1 · not_found 2 · duplicate 1" "1 2 1" "$(rows_since "$M6" "action='access.request' AND result='allow'") $(rows_since "$M6" "action='access.request' AND reason='not_found'") $(rows_since "$M6" "action='access.request' AND reason='duplicate'")"
N_E1_1=$(capi GET "/api/notifications?unread=1" claude_employee01 >/dev/null; jget "d['unread']" < "$WORK/b")
expect "주인(직원1) 알림 +1" "$((N_E1_0 + 1))" "$N_E1_1"
capi GET /api/notifications claude_employee01 > /dev/null
expect "알림 줄 — kind access_request · 항목 이름 · 받은 요청 주소" "access_request $FA /tbox/?requests=in" \
  "$(jget "' '.join([d['notifications'][0]['kind'], d['notifications'][0]['access']['item']['name'], d['notifications'][0]['access']['href']])" < "$WORK/b")"
st=$(tapi GET /api/me claude_employee01); expect "/api/me 의 기다리는 요청 수 1" "1" "$(jget "d['access_requests_pending']" < "$WORK/b")"
st=$(tapi GET "/api/access-requests?box=in" claude_employee01); RQ=$(jget "d['requests'][0]['id']" < "$WORK/b")
expect "받은 요청 목록 — 팀장 · 기다리는 중 · 메모" "claude_manager01 pending 9월 기공료 확인" "$(jget "' '.join([d['requests'][0]['requester']['username'], d['requests'][0]['status'], d['requests'][0]['message']])" < "$WORK/b")"
st=$(tapi GET "/api/access-requests?box=out" claude_manager01); expect "보낸 요청 목록 — 못 보는 항목의 기다리는 요청은 줄이 없다(없는 요청과 갈리지 않게)" "200 0" "$st $(jget "len(d['requests'])" < "$WORK/b")"
jbody "{'decision': 'approve_view'}"
expect "호스트가 아닌 사람(직원2)의 결정 → 404" "404" "$(tapi POST "/api/access-requests/$RQ" claude_employee02 "$WORK/body.json")"
expect "주인 허락(보기) 200" "200" "$(tapi POST "/api/access-requests/$RQ" claude_employee01 "$WORK/body.json")"
expect "곧바로 — 팀장 A 목록 200 · P 받기 200" "200 200" "$(tapi GET "/api/files?folder=$A" claude_manager01) $(tapi GET "/api/files/$P/download" claude_manager01)"
N_M=$(capi GET /api/notifications claude_manager01 >/dev/null; jget "' '.join([d['notifications'][0]['kind'], d['notifications'][0]['access']['status'], d['notifications'][0]['access']['href']])" < "$WORK/b")
expect "요청자 알림 — 허락 · 그 폴더 주소" "access_decided approved /tbox/?folder=$A" "$N_M"
expect "이미 끝난 요청을 다시 결정 → 400" "400" "$(tapi POST "/api/access-requests/$RQ" claude_employee01 "$WORK/body.json")"
sub "거절 — 요청자 알림에 이름이 없다"
jbody "{'file': '$R', 'write': False}"; tapi POST /api/access-requests claude_guest01 "$WORK/body.json" > /dev/null
RQ2=$(psqlq "SELECT id FROM access_requests WHERE requester_id=$U_G1 AND status='pending' ORDER BY id DESC LIMIT 1")
jbody "{'decision': 'reject'}"; expect "주인 거절 200" "200" "$(tapi POST "/api/access-requests/$RQ2" claude_employee01 "$WORK/body.json")"
capi GET /api/notifications claude_guest01 > /dev/null
expect "손님 알림 — 거절 · 항목 이름 없음(볼 수 없는 항목)" "access_decided rejected None" "$(jget "' '.join([d['notifications'][0]['kind'], d['notifications'][0]['access']['status'], str(d['notifications'][0]['access']['item'])])" < "$WORK/b")"
lacks "  손님 알림 본문에 R 이름 없음" "$SECRET-R" "$(cat "$WORK/b")"
jbody "{'file': '$R', 'write': True}"; expect "손님 편집 요청 → 400(항목과 무관 — 역할로)" "400" "$(tapi POST /api/access-requests claude_guest01 "$WORK/body.json")"
sub "거두기"
jbody "{'file': '$R', 'write': False}"; tapi POST /api/access-requests claude_manager01 "$WORK/body.json" > /dev/null
RQ3=$(psqlq "SELECT id FROM access_requests WHERE requester_id=$U_M1 AND file_id=(SELECT id FROM files WHERE public_id='$R') AND status='pending'")
expect "남의 요청 거두기 → 404" "404" "$(tapi DELETE "/api/access-requests/$RQ3" claude_employee02)"
expect "자기 요청 거두기 200 → cancelled" "200 cancelled" "$(tapi DELETE "/api/access-requests/$RQ3" claude_manager01) $(psqlq "SELECT status FROM access_requests WHERE id=$RQ3")"
sub "하루 한도 — 저장하지 않은 요청도 센다(존재가 갈리지 않게)"
USED=$(psqlq "SELECT count(*) FROM audit_logs WHERE actor_id=$U_E2 AND action='access.request' AND happened_at > now() - interval '24 hours'")
for i in $(seq $((USED + 1)) 20); do
  jbody "{'folder': $((999990000 + i))}"; tapi POST /api/access-requests claude_employee02 "$WORK/body.json" > /dev/null
done
jbody "{'folder': $B}"; expect "21번째(없는 번호만 스물 보낸 뒤 진짜 항목) → 429" "429" "$(tapi POST /api/access-requests claude_employee02 "$WORK/body.json")"
jbody "{'folder': 999999999}"; expect "없는 번호도 같은 429" "429" "$(tapi POST /api/access-requests claude_employee02 "$WORK/body.json")"

# ════════════════════════════════════════════════════════════════
hr "T-G0-7  넓히기·좁히기 · 민감정보"
jbody "{'access_level': 'internal'}"; expect "직원 주인이 자기 개인 파일 넓히기 → 403(R-20 — 넓히기는 관리자만)" "403" "$(tapi PATCH "/api/files/$R" claude_employee01 "$WORK/body.json")"
expect "관리자 주인의 개인 파일 R2 올리기 201" "201" "$(upload claude_admin01 "$TAG-R2.txt" private)"
R2=$(fid_of "$TAG-R2.txt")
jbody "{'user': 'claude_employee02'}"; tapi POST "/api/files/$R2/grants" claude_admin01 "$WORK/body.json" > /dev/null
M7=$(mark)
jbody "{'access_level': 'internal', 'reason_text': '사내에 알림'}"
st=$(tapi PATCH "/api/files/$R2" claude_admin01 "$WORK/body.json"); NEXT=$(jget "d.get('next')" < "$WORK/b")
expect "관리자 넓히기 — 재인증 전 403 next reauth" "403 reauth" "$st $NEXT"
expect "재인증 200" "200" "$(reauth claude_admin01)"
expect "관리자 넓히기 200" "200" "$(tapi PATCH "/api/files/$R2" claude_admin01 "$WORK/body.json")"
expect "감사 file.level_change allow · before private → after internal · 사유" "private internal 사내에 알림" \
  "$(psqlq "SELECT detail->'before'->>'access_level', detail->'after'->>'access_level', detail->>'reason_text' FROM audit_logs WHERE id > $M7 AND action='file.level_change' AND result='allow' ORDER BY id DESC LIMIT 1" | tr '|' ' ')"
expect "넓힌 뒤에도 권한 줄은 남는다" "1" "$(psqlq "SELECT count(*) FROM access_grants g JOIN files f ON f.id=g.file_id WHERE f.public_id='$R2'")"
jbody "{'access_level': 'private'}"; expect "다시 좁히기(올린 사람) 200" "200" "$(tapi PATCH "/api/files/$R2" claude_admin01 "$WORK/body.json")"
expect "되살아남 — 직원2 R2 받기 200 · 팀장 403" "200 403" "$(tapi GET "/api/files/$R2/download" claude_employee02) $(tapi GET "/api/files/$R2/download" claude_manager01)"
TEAMF=$(fid_of "$TAG-team.txt")
jbody "{'access_level': 'private'}"; expect "남(직원1)의 팀 파일을 팀장이 개인으로 → 403(올린 사람만)" "403" "$(tapi PATCH "/api/files/$TEAMF" claude_manager01 "$WORK/body.json")"
expect "감사 file.level_change deny not_owner 한 줄" "1" "$(rows_since "$M7" "action='file.level_change' AND reason='not_owner'")"
expect "민감정보(주민번호 낱말) 개인으로 올리기 201" "201" "$(upload claude_employee01 "$TAG-sens.txt" private "" "주민번호 900101-1234567")"
expect "같은 내용 공개로 → 400(민감정보)" "400" "$(upload claude_employee01 "$TAG-sens2.txt" public "" "주민번호 900101-1234567")"

# ════════════════════════════════════════════════════════════════
hr "T-G0-8  커뮤니티 — 개인 파일은 내 비공개 페이지에만"
capi GET /api/me/space claude_employee01 > /dev/null
jbody "{'title': '$TAG 비공개 페이지'}"; capi POST /api/pages claude_employee01 "$WORK/body.json" > /dev/null; PG1=$(jget "d['page']['id']" < "$WORK/b")
put_file_doc() {   # $1 계정 $2 페이지 $3 파일 UUID → 상태
  local v; v=$(psqlq "SELECT doc_version FROM community.pages WHERE public_id='$2'")
  python3 -c "
import json,sys,uuid
print(json.dumps({'doc': {'schema': 1, 'blocks': [{'id': str(uuid.uuid4()), 'type': 'file', 'file_ref': 'tbox:' + sys.argv[1]}]}, 'doc_version': int(sys.argv[2])}))" "$3" "${v:-1}" > "$WORK/body.json"
  capi PATCH "/api/pages/$2" "$1" "$WORK/body.json"
}
expect "내 개인 파일 P 를 내 비공개 페이지에 → 200" "200" "$(put_file_doc claude_employee01 "$PG1" "$P")"
jbody "{'title': '$TAG 사내 페이지'}"; capi POST /api/pages claude_employee01 "$WORK/body.json" > /dev/null; PG2=$(jget "d['page']['id']" < "$WORK/b")
jbody "{'visibility': 'internal'}"; capi PATCH "/api/pages/$PG2/visibility" claude_employee01 "$WORK/body.json" > /dev/null
expect "사내 페이지 범위 internal" "internal" "$(psqlq "SELECT visibility FROM community.pages WHERE public_id='$PG2'")"
st=$(put_file_doc claude_employee01 "$PG2" "$P")
expect "내 개인 파일을 사내 페이지에 → 400 file_ref" "400 file_ref" "$st $(jget "d.get('reason')" < "$WORK/b")"
contains "  이유 문장(개인 파일은 비공개 페이지에만)" "비공개 페이지에만" "$(cat "$WORK/b")"
capi GET /api/me/space claude_employee02 > /dev/null
jbody "{'title': '$TAG 직원2 비공개'}"; capi POST /api/pages claude_employee02 "$WORK/body.json" > /dev/null; PG3=$(jget "d['page']['id']" < "$WORK/b")
jbody "{'user': 'claude_employee02'}"; tapi POST "/api/files/$P/grants" claude_employee01 "$WORK/body.json" > /dev/null
expect "직원2(권한 있음)가 남(직원1)의 개인 파일을 자기 비공개 페이지에 → 400(파일 주인 ≠ 페이지 주인)" "400" "$(put_file_doc claude_employee02 "$PG3" "$P")"
tapi DELETE "/api/files/$P/grants/$U_E2" claude_employee01 > /dev/null
jbody "{'level': 'view'}"; capi PUT "/api/pages/$PG1/shares/claude_manager01" claude_employee01 "$WORK/body.json" > /dev/null
tapi DELETE "/api/folders/$A/grants/$U_M1" claude_employee01 > /dev/null
capi GET "/api/pages/$PG1" claude_manager01 > /dev/null
expect "페이지를 공유받은 팀장(파일 권한 없음) — 페이지 200 · 파일 지도에 P 없음" "False" "$(jget "'$P' in (d.get('file_titles') or {})" < "$WORK/b")"
lacks "  팀장 페이지 응답에 P 이름 없음" "$SECRET" "$(cat "$WORK/b")"

# ════════════════════════════════════════════════════════════════
hr "T-G0-9  Admin 특권 — 주소로 열 때만 · 감사 · 목록에 없음 · 고치기 없음"
M9=$(mark)
expect "관리자 — 남의 개인 파일 R 받기 200" "200" "$(tapi GET "/api/files/$R/download" claude_admin01)"
expect "감사 file.download allow granted_by admin_override" "admin_override" "$(psqlq "SELECT detail->>'granted_by' FROM audit_logs WHERE id > $M9 AND action='file.download' AND result='allow' ORDER BY id DESC LIMIT 1")"
st=$(tapi GET "/api/files?q=$SECRET" claude_admin01); expect "관리자 검색 — 0건(목록에는 없다)" "0" "$(jget "d['total']" < "$WORK/b")"
st=$(tapi GET "/api/folders?parent=0" claude_admin01); lacks "관리자 폴더 트리에 A 없음" "$FA" "$(cat "$WORK/b")"
st=$(tapi GET "/api/files/recent" claude_admin01); lacks "관리자 최근에도 없음(목록 조건절)" "$SECRET" "$(cat "$WORK/b")"
jbody "{'description': '관리자'}"; expect "관리자 — R 설명 고치기 403(읽기 특권뿐)" "403" "$(tapi PATCH "/api/files/$R" claude_admin01 "$WORK/body.json")"
expect "관리자 — R 지우기 403" "403" "$(tapi DELETE "/api/files/$R" claude_admin01)"
jbody "{'expires_hours': 1, 'max_downloads': 1}"; expect "관리자 — R 공유 링크 403" "403" "$(tapi POST "/api/files/$R/share" claude_admin01 "$WORK/body.json")"
# 재인증 창이 열려 있으면 닫는다(임시 DB — 이 세션만)
psqlq "UPDATE sessions SET reauth_at = NULL WHERE user_id = $U_A1" > /dev/null
jbody "{'user': 'claude_employee02'}"
st=$(tapi POST "/api/files/$R/grants" claude_admin01 "$WORK/body.json"); expect "관리자 — 남의 R 권한 주기: 재인증 전 403 next reauth" "403 reauth" "$st $(jget "d.get('next')" < "$WORK/b")"
reauth claude_admin01 > /dev/null
expect "재인증 뒤 200 · 감사 grant.add granted_by admin_override" "200 admin_override" "$(tapi POST "/api/files/$R/grants" claude_admin01 "$WORK/body.json") $(psqlq "SELECT detail->>'granted_by' FROM audit_logs WHERE action='grant.add' AND result='allow' ORDER BY id DESC LIMIT 1")"
tapi DELETE "/api/files/$R/grants/$U_E2" claude_admin01 > /dev/null
sub "공유 링크 — 주인은 만들고, 뒤에 개인으로 좁혀진 남의 링크는 끝난다"
jbody "{'expires_hours': 1, 'max_downloads': 3}"; expect "주인 — R 공유 링크 201" "201" "$(tapi POST "/api/files/$R/share" claude_employee01 "$WORK/body.json")"
LINK=$(jget "d.get('url') or ''" < "$WORK/b"); LPATH="/${LINK#*://*/}"
expect "링크로 받기(로그인 없이) 200" "200" "$(curl -s -o /dev/null -w '%{http_code}' -A "$UA" "$SITE$LPATH")"
upload claude_employee01 "$TAG-teamlink.txt" team > /dev/null; TL=$(fid_of "$TAG-teamlink.txt")
jbody "{'expires_hours': 1, 'max_downloads': 3}"; tapi POST "/api/files/$TL/share" claude_manager01 "$WORK/body.json" > /dev/null
TLINK=$(jget "d.get('url') or ''" < "$WORK/b"); TPATH="/${TLINK#*://*/}"
expect "팀장(팀 범위)이 만든 링크로 받기 200" "200" "$(curl -s -o /dev/null -w '%{http_code}' -A "$UA" "$SITE$TPATH")"
jbody "{'access_level': 'private'}"; expect "올린 사람(직원1)이 개인으로 좁히기 200" "200" "$(tapi PATCH "/api/files/$TL" claude_employee01 "$WORK/body.json")"
expect "팀장이 만든 옛 링크 → 404(만든 사람이 주인이 아니다)" "404" "$(curl -s -o /dev/null -w '%{http_code}' -A "$UA" "$SITE$TPATH")"

# ════════════════════════════════════════════════════════════════
hr "T-G0-10  자기 공격 (SECURITY 7장 한 행)"
sub "① 주소 추측 — 폴더 번호를 이어 세어도 못 보는 개인 폴더와 없는 번호가 같은 403"
same=0; for n in $A $B 999999990 999999991; do tapi GET "/api/folders?parent=$n" claude_guest01 >/dev/null; [ "$(cat "$WORK/b")" = '{"error":"권한이 없습니다"}' ] && same=$((same+1)); done
expect "넷 모두 같은 본문" "4" "$same"
sub "② 폴더 옮기기로 상속 우회 — 편집 권한자가 남의 하위 폴더를 자기 개인 폴더로"
jbody "{'name': '$TAG-e2own', 'access_level': 'private'}"; tapi POST /api/folders claude_employee02 "$WORK/body.json" > /dev/null; E2OWN=$(fnum_of "$TAG-e2own")
jbody "{'parent_id': $E2OWN}"; expect "직원2(A 편집) — B 를 자기 폴더 아래로 → 403(옮기기는 주인만)" "403" "$(tapi PATCH "/api/folders/$B" claude_employee02 "$WORK/body.json")"
sub "③ 파일을 남의 개인 폴더로 — 보기 권한만 있을 때"
tapi DELETE "/api/folders/$A/grants/$U_E2" claude_employee01 > /dev/null
jbody "{'user': 'claude_employee02', 'write': False}"; tapi POST "/api/folders/$A/grants" claude_employee01 "$WORK/body.json" > /dev/null
upload claude_employee02 "$TAG-e2mine.txt" private "$E2OWN" > /dev/null; E2M=$(fid_of "$TAG-e2mine.txt")
jbody "{'folder_id': $B}"; expect "직원2(보기) — 자기 개인 파일을 B 로 옮기기 → 400 bad_folder" "400" "$(tapi PATCH "/api/files/$E2M" claude_employee02 "$WORK/body.json")"
expect "직원2(보기) — B 에 올리기 → 400" "400" "$(upload claude_employee02 "$TAG-push.txt" private "$B")"
jbody "{'name': '$TAG-push-dir', 'access_level': 'private', 'parent_id': $B}"; expect "직원2(보기) — B 안에 폴더 만들기 → 403" "403" "$(tapi POST /api/folders claude_employee02 "$WORK/body.json")"
sub "④ 등급 바꾸기 경쟁 — 두 요청을 동시에(넓히기·좁히기) — 최종 상태가 한쪽 규칙을 넘지 않는다"
jbody "{'access_level': 'internal'}"; cp "$WORK/body.json" "$WORK/wide.json"
jbody "{'description': '경쟁'}"; cp "$WORK/body.json" "$WORK/desc.json"
( tapi PATCH "/api/files/$R" claude_employee01 "$WORK/wide.json" > "$WORK/race1" ) & ( tapi PATCH "/api/files/$R" claude_employee02 "$WORK/desc.json" > "$WORK/race2" ) & wait
expect "직원 넓히기 403 · 권한 없는 사람 설명 403 · R 은 그대로 개인" "private" "$(psqlq "SELECT access_level FROM files WHERE public_id='$R'")"
sub "⑤ 요청 폭주 — 위 T-G0-6 한도(21번째 429) · ⑥ 매트릭스로 개인 읽기 켜기 — T-G0-1 CHECK 거부"
note "⑤⑥ 는 위 항목의 결과를 가리킨다(같은 줄을 두 번 세지 않는다)"

# ════════════════════════════════════════════════════════════════
hr "T-G0-11  정적"
nbad=0
for f in $(git -C "$ROOT" diff --name-only "$BASE_REV" -- '*.php'; git -C "$ROOT" ls-files --others --exclude-standard -- '*.php'); do
  [ -f "$ROOT/$f" ] || continue
  php -l "$ROOT/$f" >/dev/null 2>&1 || { echo "  ✖ php -l $f"; nbad=$((nbad+1)); }
done
expect "바뀐 PHP php -l 실패" "0" "$nbad"
( cd "$ROOT" && bash scripts/security_lint.sh > "$WORK/lint.txt" 2>&1 ); expect "보안 린트 종료 코드" "0" "$?"
( cd "$ROOT" && php scripts/community_asset_version.php --check > "$WORK/asset.txt" 2>&1 ); expect "부품 버전 --check" "0" "$?"
( cd "$ROOT" && php scripts/wiki_check.php > "$WORK/wiki.txt" 2>&1 ); expect "wiki_check" "0" "$?"
expect "esprima — app.js · editor.js 구문" "ok ok" "$(python3 -c 'import esprima,sys; esprima.parseScript(open(sys.argv[1],encoding="utf-8").read()); esprima.parseModule(open(sys.argv[2],encoding="utf-8").read()); print("ok ok")' "$ROOT/public/assets/app.js" "$ROOT/public/assets/community/editor.js" 2>&1 | tail -1)"
expect "더한 줄에 innerHTML·인라인 style·on* 속성 0" "0" \
  "$(git -C "$ROOT" diff "$BASE_REV" -- public src | grep '^+' | grep -cE 'innerHTML|outerHTML| style="|\.style\.| on[a-z]+="' | tr -d ' ')"
expect "새 권한 창구는 토큰 창구 집합 밖(TBOX_API_TOKEN_ROUTES 줄 변경 0)" "0" \
  "$(git -C "$ROOT" diff "$BASE_REV" -- public/index.php | grep -E '^[+-]' | grep -c "TBOX_API_TOKEN" | tr -d ' ')"
expect "새 색값 0(app.css 더한 줄에 #rgb·rgb( 없음)" "0" "$(git -C "$ROOT" diff "$BASE_REV" -- public/assets/app.css | grep '^+' | grep -cE '#[0-9a-fA-F]{3,8}\b|rgba?\(' | tr -d ' ')"

# ════════════════════════════════════════════════════════════════
hr "T-G0-12  브라우저 (tests/browser/g0_private_grants.py)"
if [ "${NO_BROWSER:-0}" = "1" ]; then
  skip "브라우저" "NO_BROWSER=1"
else
  mkdir -p "$SHOTS"
  cookie_of() { python3 - "$JAR_DIR/$1.jar" <<'PY2'
import sys
for line in open(sys.argv[1], encoding='utf-8', errors='replace'):
    parts = line.rstrip('\n').split('\t')
    if len(parts) == 7 and parts[5] == '__Host-tbox_session':
        print(parts[6]); break
PY2
  }
  printf '{"A":%s,"B":%s,"P":"%s","R":"%s","secret":"%s","folderA":"%s","tag":"%s"}' "$A" "$B" "$P" "$R" "$SECRET" "$FA" "$TAG" > "$WORK/ids.json"
  browser_lock_on
  { cookie_of claude_employee01; cookie_of claude_employee02; cookie_of claude_guest01; } \
    | python3 "$ROOT/tests/browser/g0_private_grants.py" "$SITE" "$UA" "$SHOTS" "$WORK/ids.json" > "$WORK/browser.txt" 2>&1
  brc=$?
  browser_lock_off
  grep -E '^\s+(✔|✖)' "$WORK/browser.txt" | sed 's/^/  /' | tail -80
  expect "브라우저 시험 종료 코드 · SMOKE_FAIL" "0 0" "$brc $(sed -n 's/^SMOKE_FAIL=//p' "$WORK/browser.txt")"
fi

sub "끝 — 서버 로그에 PHP 경고·오류 0"
expect "PHP Warning·Fatal·Deprecated 0" "0" "$(grep -cE 'PHP (Warning|Fatal|Deprecated|Parse)' "$INST/server.log" | tr -d ' ')"
grep -E 'PHP (Warning|Fatal|Deprecated|Parse)' "$INST/server.log" | head -5 | sed 's/^/    /'

# ════════════════════════════════════════════════════════════════
if [ "${REGRESS:-0}" = "1" ]; then
  hr "회귀 — 같은 임시 인스턴스에서(8082 → 임시로 sed 치환 · writes_prod 0)"
  sed -i "s#http://127.0.0.1:8082#$SITE#g" "$INST/app/scripts/ui_snapshot.sh" 2>/dev/null
  browser_lock_on
  for s in ${REGRESS_LIST:-help_button api_whoami api_read api_folders q_launcher f68}; do
    src="$INST/app/tests/$s.sh"
    dst="$INST/app/tests/$s.temp.sh"
    [ -f "$src" ] || { echo "  ✖ $s — 스크립트가 없다"; FAIL=$((FAIL+1)); continue; }
    sed -e "s#http://127.0.0.1:8082#$SITE#g" -e "s#^DB=\"tbox_re\"#DB=\"$DB\"#" -e "s#-d tbox_re #-d $DB #g" "$src" > "$dst"
    if grep -v '^\s*#' "$dst" | grep -q '8082\|DB="tbox_re"\|-d tbox_re'; then
      echo "  ✖ $s — 운영 주소·DB 가 남아 있어 돌리지 않는다"; FAIL=$((FAIL+1)); continue
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
  browser_lock_off
fi

hr "결과"
printf '통과 %d · 실패 %d · 건너뜀 %d\n' "$PASS" "$FAIL" "$SKIP"
note "임시 DB($DB)·임시 폴더·임시 서버는 이 스크립트가 끝나며 지워진다. 운영에는 아무것도 남지 않는다."
[ "$FAIL" -eq 0 ] || exit 1
exit 0
