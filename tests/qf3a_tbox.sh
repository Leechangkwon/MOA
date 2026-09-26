#!/usr/bin/env bash
# tests/qf3a_tbox.sh — 개발 대기열 6 · Q:F-3a 라운드 A 수락 시험, t-box 쪽 (지시서 /var/www/qaredi/docs/qf3a-favorites-recent-bulk-directive.md 3장)
#
#   A-T0 표        마이그레이션이 표 넷·색인·소유자·권한(앱 SELECT·INSERT(칸)·DELETE · 백업 SELECT)을 만든다 · 두 번 돌려도 오류 0 ·
#                  db/grants.sql 의 새 절(그 절만 — 파일 전체는 운영 DB 이름을 적은 CONNECT 문장이 있어 임시 DB 에서 돌리지 않는다) ·
#                  **새 설치 모양 = 마이그레이션 모양**(빈 임시 DB 에 db/schema.sql · db/community/schema.sql 을 붓고 네 표의 정의를 견준다)
#   QF3A-T1 넣기 판정(파일·폴더)  claude_employee02 가 claude_employee01 의 **팀 파일**(팀 1)·팀 폴더 id 로 PUT → 404 이고 본문이
#                  **없는 id 의 404 와 글자까지 같다** · 볼 수 있는 파일·폴더 → 200 · Guest 는 사내 파일 404 · 공개 파일 200 ·
#                  CSRF 없이 403 · 미로그인 401 · 넣기·빼기가 감사 행을 늘리지 않는다(결정 M-2)
#   QF3A-T2 못 보게 되면 사라짐  주인이 파일 등급을 팀으로 좁힘 → employee02 의 즐겨찾기 응답 **원문**에 그 id·이름 0 → 관리자가
#                  되돌리면 다시 보임 · 휴지통에 넣으면 빠지고 되살리면 돌아옴 · 폴더도 같음 · 빈 폴더를 지우면(즐겨찾기가 있어도) 200
#                  · 한도: 200 줄이면 409 · 그중 하나가 안 보이게 되면 넣기가 그 줄을 걷고 200(결정 D-5) · 폴더 50 도 같음
#   QF3A-T4 최근   미리보기·받기 뒤 최근에 나옴(순서 = 마지막 연 때) · 최근 창구는 **새로 쓰는 행 0**(감사·세션 밖의 표 쓰기 수가 그대로) ·
#                  권한을 잃은 파일 빠짐(원문에 id·이름 0) · 대상 인자(`?user_id=` 등)를 줘도 결과가 같고 남의 최근이 섞이지 않음
#   A-T6 정적      바뀐 PHP php -l · 보안 린트 0 · 토큰 창구 목록 불변(`TBOX_API_TOKEN_ROUTES` diff 0) · 서버 로그 새 PHP 오류·SQLSTATE 0
#
# ★ 운영 DB·운영 포트를 건드리지 않는다 — 임시 DB `tbox_qf3a_<포트>_test`(+ 새 설치 비교용 `…_fresh`), 임시 인스턴스 `php -S 127.0.0.1:<포트>`
#   (기본 8160). 운영 DB 는 pg_dump 로 **읽어** 임시 DB 에 붓기만 한다(파이프). 검토 계정은 claude_ 다섯(F-71).
#   비밀(비밀번호·TOTP·세션·CSRF)은 표준입력으로만 넘긴다(tests/lib/secrets.sh — R-62).
#   임시 .env 의 원본은 트리의 .env — 복제본에서는 시험하는 쪽이 mktemp 폴더(0700)의 사본을 QF2_ENV_SRC 로 넘기고 끝에 지운다.
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
. "$ROOT/tests/lib/review_accounts.sh" || { echo "tests/lib/review_accounts.sh 를 읽지 못했다 — 시험을 시작하지 않는다"; exit 3; }
. "$ROOT/tests/lib/secrets.sh" || { echo "tests/lib/secrets.sh 를 읽지 못했다 — 시험을 시작하지 않는다 (R-62)"; exit 3; }
PORT="${QF3A_PORT:-8160}"
case "$PORT" in 80|8082|8081) echo "운영 포트($PORT)는 쓰지 않는다"; exit 3 ;; esac
SITE="http://127.0.0.1:$PORT"
BASE="$SITE/tbox"
UA="tbox-review/$(date +%F)"
SRC_DB="tbox_re"                   # 운영 — **읽기만** 한다(pg_dump)
DB="tbox_qf3a_${PORT}_test"        # 임시 — 여기에만 쓴다
DB2="tbox_qf3a_${PORT}_fresh"      # 새 설치 모양 비교용 빈 DB
MIG="2026-10-03_qf3a_favorites_recent.sql"
JAR_DIR="$(mktemp -d)"
WORK="$(mktemp -d)"
chmod 700 "$JAR_DIR" "$WORK"
INST=""
SERVER_PID=""
PASS=0
FAIL=0

hr()  { printf '\n════════════════════════════════════════════════════════════\n%s\n════════════════════════════════════════════════════════════\n' "$1"; }
sub() { printf '\n── %s\n' "$1"; }
expect() {
  if [ "$2" = "$3" ]; then printf '  ✔ %s  (기대=%s 실제=%s)\n' "$1" "$2" "$3"; PASS=$((PASS+1))
  else printf '  ✖ %s  (기대=%s 실제=%s)\n' "$1" "$2" "$3"; FAIL=$((FAIL+1)); fi
}
note() { printf '  · %s\n' "$1"; }

cleanup() {
  if [ -n "$SERVER_PID" ]; then kill "$SERVER_PID" 2>/dev/null; wait "$SERVER_PID" 2>/dev/null; fi
  sudo -n -u postgres dropdb --if-exists "$DB" >/dev/null 2>&1
  sudo -n -u postgres dropdb --if-exists "$DB2" >/dev/null 2>&1
  [ -n "$INST" ] && rm -rf "$INST"
  rm -rf "$JAR_DIR" "$WORK"
}
trap cleanup EXIT

psqlq() { sudo -n -u postgres psql -X -d "$DB" -t -A -c "$1" 2>/dev/null; }
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
    case "$out" in *'"ok":true'*) return 0 ;; esac
    left=$(printf '%s' "$raw" | sed -n 's/^남은 시간: \([0-9]*\)초.*/\1/p')
    [ -n "$left" ] || left=31
    printf '  · %s 로그인 재시도 — 다음 코드 창까지 %s초\n' "$who" "$left" >&2
    sleep $((left + 1))
  done
  return 1
}
session_of() { awk '$6=="__Host-tbox_session"{print $7}' "$JAR_DIR/$1.jar" 2>/dev/null | tail -1; }

# ════════════════════════════════════════════════════════════════
hr "Q:F-3a 라운드 A (t-box) — 임시 인스턴스 준비 (운영 DB 에는 쓰지 않는다)"
avail_kb=$(df -Pk / | awk 'NR==2{print $4}')
echo "루트 파티션 여유: $(df -Ph / | awk 'NR==2{print $4}')"
[ "$avail_kb" -ge 1048576 ] || { echo "루트 디스크 여유 1GB 미만 — 시험을 시작하지 않는다 (SECURITY R-80)"; exit 3; }
sudo -n -u postgres psql -X -d "$SRC_DB" -tAc "SELECT 1" >/dev/null 2>&1 \
  || { echo "psql(postgres) 을 쓸 수 없다 — 이 시험은 임시 DB 가 없으면 성립하지 않는다"; exit 3; }
if ss -ltn 2>/dev/null | grep -q "127.0.0.1:$PORT "; then echo "포트 $PORT 이 이미 쓰이고 있다 — 중단"; exit 3; fi

sub "① 운영 DB 를 **읽어** 임시 DB 를 만든다(파이프)"
sudo -n -u postgres dropdb --if-exists "$DB" >/dev/null 2>&1
sudo -n -u postgres createdb -O tbox_owner "$DB" >/dev/null 2>&1 || { echo "임시 DB 를 만들 수 없다"; exit 3; }
sudo -n -u postgres pg_dump -d "$SRC_DB" 2>/dev/null \
  | sudo -n -u postgres psql -X -q -d "$DB" -v ON_ERROR_STOP=0 >/dev/null 2>"$WORK/restore.err"
[ -n "$(psqlq "SELECT count(*) FROM files")" ] || { echo "임시 DB 로 옮기지 못했다 — $(head -2 "$WORK/restore.err")"; exit 3; }
note "임시 DB $DB — 파일 $(psqlq "SELECT count(*) FROM files") · 폴더 $(psqlq "SELECT count(*) FROM folders") · 페이지 $(psqlq "SELECT count(*) FROM community.pages")"

hr "A-T0 — 마이그레이션 · 권한 · 새 설치 모양"
for m in 2026-10-01_qf2_search.sql 2026-10-02_qf2b_files_search.sql "$MIG"; do
  sudo -n -u postgres psql -X -q -d "$DB" -v ON_ERROR_STOP=1 -f - < "$ROOT/db/migrations/$m" 2>&1 | grep -v 'could not change directory\|already exists, skipping' | sed 's/^/    /'
done
sudo -n -u postgres psql -X -q -d "$DB" -v ON_ERROR_STOP=1 -f - < "$ROOT/db/community/grants.sql" 2>&1 | grep -v 'could not change directory' | sed 's/^/    /'
expect "표 넷이 생겼다" "4" "$(psqlq "SELECT count(*) FROM pg_tables WHERE (schemaname, tablename) IN (('public','file_favorites'),('public','folder_favorites'),('community','page_favorites'),('community','page_recent'))")"
expect "소유자는 넷 다 tbox_owner" "tbox_owner" "$(psqlq "SELECT string_agg(DISTINCT tableowner, ',') FROM pg_tables WHERE tablename IN ('file_favorites','folder_favorites','page_favorites','page_recent')")"
expect "색인(기본키 넷 + 보조 다섯)" "9" "$(psqlq "SELECT count(*) FROM pg_indexes WHERE tablename IN ('file_favorites','folder_favorites','page_favorites','page_recent')")"
expect "대상 외래 키는 CASCADE · 사람 외래 키는 ON DELETE 없음(NO ACTION)" "c,c,c,c|a,a,a,a" \
  "$(psqlq "SELECT string_agg(confdeltype::text, ',' ORDER BY conrelid::regclass::text) FILTER (WHERE confrelid::regclass::text <> 'users') || '|' || string_agg(confdeltype::text, ',' ORDER BY conrelid::regclass::text) FILTER (WHERE confrelid::regclass::text = 'users') FROM pg_constraint WHERE contype = 'f' AND conrelid::regclass::text IN ('file_favorites','folder_favorites','community.page_favorites','community.page_recent')")"
privs() {   # $1 역할 $2 표 — SELECT/INSERT/UPDATE/DELETE 표 단위 + 칸 단위 INSERT·UPDATE 가 있는 칸
  psqlq "SELECT has_table_privilege('$1','$2','SELECT')::text || ',' || has_table_privilege('$1','$2','INSERT')::text || ',' || has_table_privilege('$1','$2','UPDATE')::text || ',' || has_table_privilege('$1','$2','DELETE')::text || '|ins:' || coalesce((SELECT string_agg(attname, '+' ORDER BY attname) FROM pg_attribute WHERE attrelid = '$2'::regclass AND attnum > 0 AND has_column_privilege('$1','$2',attname,'INSERT')), '') || '|upd:' || coalesce((SELECT string_agg(attname, '+' ORDER BY attname) FROM pg_attribute WHERE attrelid = '$2'::regclass AND attnum > 0 AND has_column_privilege('$1','$2',attname,'UPDATE')), '')" | sed 's/true/t/g; s/false/f/g'
}
expect "tbox_app · file_favorites" "t,f,f,t|ins:file_id+user_id|upd:" "$(privs tbox_app public.file_favorites)"
expect "tbox_app · folder_favorites" "t,f,f,t|ins:folder_id+user_id|upd:" "$(privs tbox_app public.folder_favorites)"
expect "tbox_app · page_favorites" "t,f,f,t|ins:page_id+position+user_id|upd:position" "$(privs tbox_app community.page_favorites)"
expect "tbox_app · page_recent" "t,f,f,t|ins:page_id+user_id|upd:last_viewed_at" "$(privs tbox_app community.page_recent)"
expect "tbox_backup · 넷 다 SELECT 만" "t,f,f,f|t,f,f,f|t,f,f,f|t,f,f,f" \
  "$(for t in public.file_favorites public.folder_favorites community.page_favorites community.page_recent; do privs tbox_backup "$t" | cut -d'|' -f1; done | paste -sd'|')"
expect "tbox_perm · tbox_push 는 0" "f|f|f|f|f|f|f|f" \
  "$(for r in tbox_perm tbox_push; do for t in public.file_favorites public.folder_favorites community.page_favorites community.page_recent; do psqlq "SELECT has_table_privilege('$r','$t','SELECT')" | sed 's/true/t/;s/false/f/'; done; done | paste -sd'|')"
expect "마이그레이션을 한 번 더 돌려도 오류 없음(IF NOT EXISTS)" "0" \
  "$(sudo -n -u postgres psql -X -q -d "$DB" -v ON_ERROR_STOP=1 -f - < "$ROOT/db/migrations/$MIG" >/dev/null 2>&1; echo $?)"
# db/grants.sql 의 새 절만(파일 전체에는 운영 DB 이름을 적은 CONNECT 문장이 있다 — 임시 DB 에서 돌리면 운영 DB 의 CONNECT 권한을 건드린다)
sed -n '/^-- ─── 즐겨찾기 (개발 대기열 6/,$p' "$ROOT/db/grants.sql" > "$WORK/grants_block.sql"
expect "db/grants.sql 새 절을 잘라 냈다(DO 블록 하나)" "1" "$(grep -c '^DO \$\$' "$WORK/grants_block.sql")"
expect "db/grants.sql 새 절에 DATABASE 문장 없음" "0" "$(grep -ci 'DATABASE' "$WORK/grants_block.sql")"
expect "db/grants.sql 새 절이 임시 DB 에서 돈다" "0" "$(sudo -n -u postgres psql -X -q -d "$DB" -v ON_ERROR_STOP=1 -f - < "$WORK/grants_block.sql" >/dev/null 2>&1; echo $?)"
expect "  그 뒤 권한 그대로(tbox_app · file_favorites)" "t,f,f,t|ins:file_id+user_id|upd:" "$(privs tbox_app public.file_favorites)"
expect "db/community/grants.sql 을 다시 돌려도 권한 그대로(page_recent)" "t,f,f,t|ins:page_id+user_id|upd:last_viewed_at" "$(privs tbox_app community.page_recent)"

sub "새 설치 모양 — 빈 임시 DB 에 schema.sql 둘을 붓고 네 표의 정의를 마이그레이션 쪽과 견준다"
sudo -n -u postgres dropdb --if-exists "$DB2" >/dev/null 2>&1
sudo -n -u postgres createdb -O tbox_owner "$DB2" >/dev/null 2>&1 || { echo "  ✖ 새 설치 비교용 DB 를 만들 수 없다"; FAIL=$((FAIL+1)); }
{ echo 'SET ROLE tbox_owner;'; cat "$ROOT/db/schema.sql"; echo 'RESET ROLE; CREATE SCHEMA IF NOT EXISTS community AUTHORIZATION tbox_owner; SET ROLE tbox_owner;'; cat "$ROOT/db/community/schema.sql"; } \
  | sudo -n -u postgres psql -X -q -d "$DB2" -v ON_ERROR_STOP=1 -f - > "$WORK/fresh.out" 2>&1
rc=$?
expect "새 설치 schema.sql 둘이 오류 없이 돈다" "0" "$rc"
[ "$rc" = "0" ] || grep -v 'could not change directory' "$WORK/fresh.out" | tail -5 | sed 's/^/    /'
shape() {   # $1 DB — 네 표의 칸(이름·형·NULL·기본값)·제약(정의)·색인(정의) — 이름순
  sudo -n -u postgres psql -X -d "$1" -t -A -c "
    SELECT 'col ' || table_schema || '.' || table_name || '.' || column_name || ' ' || data_type || ' ' || is_nullable || ' ' || coalesce(column_default, '-')
      FROM information_schema.columns WHERE table_name IN ('file_favorites','folder_favorites','page_favorites','page_recent')
    UNION ALL
    SELECT 'con ' || conrelid::regclass::text || ' ' || pg_get_constraintdef(oid)
      FROM pg_constraint WHERE conrelid::regclass::text IN ('file_favorites','folder_favorites','community.page_favorites','community.page_recent')
    UNION ALL
    SELECT 'idx ' || schemaname || '.' || indexname || ' ' || regexp_replace(indexdef, '^CREATE (UNIQUE )?INDEX [a-z_]+ ON ', '')
      FROM pg_indexes WHERE tablename IN ('file_favorites','folder_favorites','page_favorites','page_recent')
    ORDER BY 1" 2>/dev/null
}
shape "$DB" > "$WORK/shape_mig.txt"; shape "$DB2" > "$WORK/shape_fresh.txt"
expect "모양 줄이 읽혔다(비지 않음 — 칸 13 + 제약 13 + 색인 9)" "35|35" "$(wc -l < "$WORK/shape_mig.txt" | tr -d ' ')|$(wc -l < "$WORK/shape_fresh.txt" | tr -d ' ')"
expect "새 설치 모양 = 마이그레이션 모양(칸·제약·색인)" "same" "$(cmp -s "$WORK/shape_mig.txt" "$WORK/shape_fresh.txt" && echo same || echo diff)"
cmp -s "$WORK/shape_mig.txt" "$WORK/shape_fresh.txt" || diff "$WORK/shape_mig.txt" "$WORK/shape_fresh.txt" | head -10 | sed 's/^/    /'
sudo -n -u postgres dropdb --if-exists "$DB2" >/dev/null 2>&1

sub "② 저장소를 임시 폴더로 복사(임시 .env — DB 이름·저장·감사 경로만 바꾼다, 값은 출력하지 않는다)"
INST="$(mktemp -d)"
mkdir -p "$INST/app" "$INST/storage" "$INST/log"
tar -C "$ROOT" -cf - --exclude=.git --exclude=jar --exclude=api-test.txt --exclude=./.env . | tar -C "$INST/app" -xf -
# qcheck-allow: R20 — 임시 인스턴스용 사본(0700·0600 · trap 정리) · kill -9 뒤 남는 창은 받아들인 위험(HO-B1-004)
sed -e "s#^DB_NAME=.*#DB_NAME=$DB#" \
    -e "s#^STORAGE_DIR=.*#STORAGE_DIR=$INST/storage#" \
    -e "s#^AUDIT_LOG_FILE=.*#AUDIT_LOG_FILE=$INST/log/audit.log#" \
    "${QF2_ENV_SRC:-$ROOT/.env}" > "$INST/app/.env"
chmod 600 "$INST/app/.env"
grep -q "^DB_NAME=$DB\$" "$INST/app/.env" || { echo "임시 .env 에 임시 DB 이름이 들어가지 않았다 — 중단"; exit 4; }
ln -s /var/www/qaredi/community "$INST/community" 2>/dev/null
cat > "$INST/app/router.php" <<'PHP'
<?php
$root = __DIR__ . '/public';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (is_string($path) && strpos($path, '/tbox/assets/') === 0) {
    http_response_code(404);
    return true;
}
require $root . '/index.php';
PHP
( cd "$INST/app" && exec php -S "127.0.0.1:$PORT" -t public router.php > "$INST/server.log" 2>&1 ) &
SERVER_PID=$!
READY=""
for _ in $(seq 1 20); do
  sleep 0.5
  READY=$(curl -s -o /dev/null -w '%{http_code}' -A "$UA" "$BASE/api/files/favorites")
  [ "$READY" = "401" ] && break
done
expect "임시 인스턴스가 떴다(미로그인 즐겨찾기 창구 401)" "401" "$READY"
[ "$READY" = "401" ] || { echo "인스턴스를 띄우지 못했다 — 중단"; tail -5 "$INST/server.log"; exit 4; }

sub "로그인 (claude_ 검토 계정 다섯 — 순차, F-71)"
for who in claude_employee01 claude_employee02 claude_manager01 claude_admin01 claude_guest01; do
  login "$who" || { echo "  ✖ $who 로그인 실패 — 중단"; exit 4; }
done
S_E1=$(session_of claude_employee01); S_E2=$(session_of claude_employee02); S_M1=$(session_of claude_manager01)
S_A1=$(session_of claude_admin01);   S_G1=$(session_of claude_guest01)
note "세션 다섯 받음(값은 출력하지 않는다)"
errs_before=$(grep -ciE 'PHP (Fatal|Warning|Notice|Deprecated)|SQLSTATE|Uncaught' "$INST/server.log")

# ─── 시험 몸통(파이썬) — 세션 다섯은 표준입력 한 줄씩(R-62). 임시 DB 는 sudo psql 로만 본다(쓰는 것은 시험 준비·상한 흉내뿐). ─────
cat > "$WORK/drv.py" <<'PY'
import json, subprocess, sys, time, urllib.request, urllib.error, urllib.parse, uuid
SITE, UA, DB = sys.argv[1], sys.argv[2], sys.argv[3]
S = {w: sys.stdin.readline().rstrip('\n') for w in ('e1', 'e2', 'm1', 'a1', 'g1')}
P = F = 0
def ok(name, cond, detail=''):
    global P, F
    if cond: P += 1; print('  ✔ ' + name)
    else: F += 1; print('  ✖ ' + name + ('  — ' + str(detail)[:300] if detail != '' else ''))
def sql(q):
    r = subprocess.run(['sudo', '-n', '-u', 'postgres', 'psql', '-X', '-d', DB, '-t', '-A', '-c', q], capture_output=True, text=True, cwd='/')
    return r.stdout.strip()
CSRF = {}
def req(who, m, path, body=None, raw=None, ctype=None, csrf=True, cookie=True):
    h = {'User-Agent': UA}
    if cookie and who: h['Cookie'] = '__Host-tbox_session=' + S[who]
    if m != 'GET' and csrf and who: h['X-CSRF-Token'] = CSRF.get(who, '')
    data = raw
    if body is not None:
        data = json.dumps(body, ensure_ascii=False).encode('utf-8'); h['Content-Type'] = 'application/json'
    if ctype: h['Content-Type'] = ctype
    r = urllib.request.Request(SITE + path, data=data, headers=h, method=m)
    try:
        with urllib.request.urlopen(r, timeout=60) as x:
            b = x.read(); c = x.status
    except urllib.error.HTTPError as e:
        b = e.read(); c = e.code
    try: d = json.loads(b or b'{}')
    except Exception: d = None
    return c, d, b
for w in S:
    CSRF[w] = (req(w, 'GET', '/tbox/api/me')[1] or {}).get('csrf_token', '')
def upload(who, name, level, team=None):
    b = uuid.uuid4().hex; parts = []
    fields = [('access_level', level), ('description', 'qf3a 시험 파일')]
    if team is not None: fields.append(('team_id', str(team)))
    for k, v in fields:
        parts.append(('--%s\r\nContent-Disposition: form-data; name="%s"\r\n\r\n%s\r\n' % (b, k, v)).encode())
    parts.append(('--%s\r\nContent-Disposition: form-data; name="file"; filename="%s"\r\nContent-Type: text/plain\r\n\r\n' % (b, name)).encode() + ('본문 ' + name + '\n').encode() + b'\r\n')
    parts.append(('--%s--\r\n' % b).encode())
    c, d, raw = req(who, 'POST', '/tbox/api/files', raw=b''.join(parts), ctype='multipart/form-data; boundary=' + b)
    fid = ((d or {}).get('file') or d or {}).get('id', '')
    return c, fid
def fav_files(who):
    c, d, raw = req(who, 'GET', '/tbox/api/files/favorites')
    return c, [f['id'] for f in (d or {}).get('files', [])], raw
def fav_folders(who):
    c, d, raw = req(who, 'GET', '/tbox/api/folders/favorites')
    return c, [f['id'] for f in (d or {}).get('folders', [])], raw
def recent(who, qs=''):
    c, d, raw = req(who, 'GET', '/tbox/api/files/recent' + qs)
    return c, [f['id'] for f in (d or {}).get('files', [])], raw, d
def audit_max():
    return sql("SELECT coalesce(max(id), 0) FROM audit_logs")
def audit_since(mark, names):
    # mark 뒤에 그 사람들로 남은 감사 행의 「행동|결과」 목록(쉼표) — 비었으면 ''
    return sql("SELECT coalesce(string_agg(action || '|' || result, ',' ORDER BY id), '') FROM audit_logs WHERE id > %s AND actor_name IN (%s)" % (mark, ','.join("'%s'" % n for n in names)))

tag = uuid.uuid4().hex[:6]
print('\n── 준비 — claude_employee01 이 파일 셋·폴더 둘을 앱 창구로 만든다(이름에 표식 %s)' % tag)
c1, F_INT = upload('e1', 'qf3a-사내-%s.txt' % tag, 'internal')
c2, F_TEAM = upload('e1', 'qf3a-팀1-%s.txt' % tag, 'team', 1)
c3, F_PUB = upload('e1', 'qf3a-공개-%s.txt' % tag, 'public')
c4, F_TRASH = upload('e1', 'qf3a-휴지통-%s.txt' % tag, 'internal')
ok('파일 넷 올리기 201', [c1, c2, c3, c4] == [201] * 4, [c1, c2, c3, c4])
# 검사 파이프라인이 켜져 있으면 pending 으로 시작한다 — 미리보기·받기 시험을 위해 임시 DB 에서만 clean 으로
sql("UPDATE files SET scan_status = 'clean' WHERE public_id IN ('%s','%s','%s','%s')" % (F_INT, F_TEAM, F_PUB, F_TRASH))
c, d, _ = req('e1', 'POST', '/tbox/api/folders', {'name': 'qf3a-사내폴더-' + tag, 'access_level': 'internal'})
D_INT = (d or {}).get('folder', {}).get('id') if isinstance((d or {}).get('folder'), dict) else (d or {}).get('id')
c5 = c
c, d, _ = req('e1', 'POST', '/tbox/api/folders', {'name': 'qf3a-팀1폴더-' + tag, 'access_level': 'team', 'team_id': 1})
D_TEAM = (d or {}).get('folder', {}).get('id') if isinstance((d or {}).get('folder'), dict) else (d or {}).get('id')
c, d, _ = req('e1', 'POST', '/tbox/api/folders', {'name': 'qf3a-빈폴더-' + tag, 'access_level': 'internal'})
D_EMPTY = (d or {}).get('folder', {}).get('id') if isinstance((d or {}).get('folder'), dict) else (d or {}).get('id')
ok('폴더 셋 만들기(201·id)', c5 in (200, 201) and all(isinstance(x, int) for x in (D_INT, D_TEAM, D_EMPTY)), (c5, D_INT, D_TEAM, D_EMPTY))
NONE_ID = str(uuid.uuid4())

print('\n── QF3A-T1 넣기 판정 — 못 보는 것은 없는 것과 같은 404')
a0 = audit_max()
c_team, _, raw_team = req('e2', 'PUT', '/tbox/api/files/%s/favorite' % F_TEAM)
c_none, _, raw_none = req('e2', 'PUT', '/tbox/api/files/%s/favorite' % NONE_ID)
ok('employee02 → employee01 의 팀(1) 파일 PUT = 404', c_team == 404, c_team)
ok('  본문이 없는 id 의 404 와 글자까지 같다', raw_team == raw_none and c_none == 404, (raw_team, raw_none))
ok('  본문에 파일 이름·등급이 없다', b'qf3a' not in raw_team and b'team' not in raw_team, raw_team)
c, _, _ = req('e2', 'PUT', '/tbox/api/files/%s/favorite' % F_INT)
ok('employee02 → 사내 파일 PUT = 200', c == 200, c)
c, _, _ = req('e2', 'PUT', '/tbox/api/files/%s/favorite' % F_INT)
ok('  두 번 넣어도 200(줄 하나)', c == 200 and sql("SELECT count(*) FROM file_favorites ff JOIN files f ON f.id = ff.file_id JOIN users u ON u.id = ff.user_id WHERE u.username = 'claude_employee02' AND f.public_id = '%s'" % F_INT) == '1', c)
c, _, _ = req('e2', 'PUT', '/tbox/api/files/not-a-uuid-not-a-uuid-not-a-uuid-xx/favorite')
c2, _, raw_dash = req('e2', 'PUT', '/tbox/api/files/%s/favorite' % ('-' * 36))
ok('UUID 모양이 아니면 404(라우터 · 창구의 UUID 검사 — 붙임표 36자도)', (c, c2) == (404, 404) and raw_dash == raw_none, (c, c2, raw_dash))
c_ft, _, raw_ft = req('e2', 'PUT', '/tbox/api/folders/%d/favorite' % D_TEAM)
c_fn, _, raw_fn = req('e2', 'PUT', '/tbox/api/folders/999999/favorite')
ok('employee02 → 팀(1) 폴더 PUT = 404 · 없는 폴더와 본문이 같다', c_ft == 404 and c_fn == 404 and raw_ft == raw_fn, (c_ft, c_fn, raw_ft, raw_fn))
c, _, _ = req('e2', 'PUT', '/tbox/api/folders/%d/favorite' % D_INT)
ok('employee02 → 사내 폴더 PUT = 200', c == 200, c)
c, _, _ = req('e1', 'PUT', '/tbox/api/files/%s/favorite' % F_TEAM)
ok('employee01(같은 팀) → 팀 파일 PUT = 200', c == 200, c)
c_g1, _, _ = req('g1', 'PUT', '/tbox/api/files/%s/favorite' % F_INT)
c_g2, _, _ = req('g1', 'PUT', '/tbox/api/files/%s/favorite' % F_PUB)
ok('Guest → 사내 파일 404 · 공개 파일 200', (c_g1, c_g2) == (404, 200), (c_g1, c_g2))
c, _, _ = req('e2', 'PUT', '/tbox/api/files/%s/favorite' % F_PUB, csrf=False)
ok('CSRF 없이 PUT → 403', c == 403, c)
c, _, _ = req(None, 'PUT', '/tbox/api/files/%s/favorite' % F_PUB, cookie=False)
ok('미로그인 PUT → 401', c == 401, c)
for path in ('/tbox/api/files/favorites', '/tbox/api/folders/favorites', '/tbox/api/files/recent'):
    c, _, _ = req(None, 'GET', path, cookie=False)
    ok('미로그인 GET %s → 401' % path, c == 401, c)
c, ids, raw = fav_files('e2')
ok('employee02 목록 = 사내 파일 하나(넣은 것만)', c == 200 and F_INT in ids and F_TEAM not in ids, (c, ids))
d = json.loads(raw)
ok('  응답에 전체 개수 칸이 없다', 'total' not in d and b'"total"' not in raw, list(d.keys()))
c, ids, _ = fav_folders('e2')
ok('employee02 폴더 목록 = 사내 폴더', c == 200 and D_INT in ids and D_TEAM not in ids, (c, ids))
c, _, _ = req('e2', 'DELETE', '/tbox/api/files/%s/favorite' % F_PUB)
ok('넣지 않은 파일 빼기도 200(있든 없든 같은 답)', c == 200, c)
c, _, _ = req('e2', 'DELETE', '/tbox/api/files/%s/favorite' % F_TEAM)
ok('못 보는 파일 빼기도 200(내 줄만 — 존재가 갈리지 않는다)', c == 200, c)
got = audit_since(a0, ['claude_employee02', 'claude_guest01'])
ok('넣기·빼기·목록이 감사 행을 늘리지 않았다(결정 M-2) — 그 사이 employee02·Guest 의 감사 행은 CSRF 거부 하나(라우터 관문)뿐', got == 'csrf|deny', got)

print('\n── QF3A-T2 못 보게 되면 사라짐 — 응답 원문에 id·이름 0')
name_int = 'qf3a-사내-%s' % tag
c, _, _ = req('e1', 'PATCH', '/tbox/api/files/' + F_INT, {'access_level': 'team', 'team_id': 1})
ok('주인(employee01)이 사내 → 팀(1)으로 좁힘 200', c == 200, c)
c, ids, raw = fav_files('e2')
ok('employee02 목록 원문에 그 id 0', c == 200 and F_INT.encode() not in raw, raw[:200])
ok('  원문에 그 이름 0', name_int.encode() not in raw, raw[:200])
c, _, _ = req('a1', 'PATCH', '/tbox/api/files/' + F_INT, {'access_level': 'internal'})
ok('관리자가 사내로 되돌림 200', c == 200, c)
c, ids, raw = fav_files('e2')
ok('  다시 보인다(줄은 그대로 남아 있었다)', F_INT in ids and name_int.encode() in raw, ids)
c, _, _ = req('e1', 'DELETE', '/tbox/api/files/' + F_INT)
ok('주인이 휴지통으로 200', c == 200, c)
c, ids, raw = fav_files('e2')
ok('  휴지통 파일은 원문에 id·이름 0', F_INT.encode() not in raw and name_int.encode() not in raw, raw[:200])
c, _, _ = req('e1', 'POST', '/tbox/api/files/%s/restore' % F_INT, {})
ok('주인이 되살림 200', c == 200, c)
c, ids, _ = fav_files('e2')
ok('  다시 보인다', F_INT in ids, ids)
c, _, _ = req('e1', 'PATCH', '/tbox/api/folders/%d' % D_INT, {'access_level': 'team', 'team_id': 1})
ok('주인이 사내 폴더를 팀(1)으로 좁힘 200', c == 200, c)
c, ids, raw = fav_folders('e2')
ok('  employee02 폴더 목록 원문에 id·이름 0', D_INT not in ids and ('qf3a-사내폴더-' + tag).encode() not in raw, raw[:200])
c, _, _ = req('a1', 'PATCH', '/tbox/api/folders/%d' % D_INT, {'access_level': 'internal'})
ok('관리자가 되돌림 200', c == 200, c)
c, ids, _ = fav_folders('e2')
ok('  다시 보인다', D_INT in ids, ids)
c, _, _ = req('e2', 'PUT', '/tbox/api/folders/%d/favorite' % D_EMPTY)
n_before = sql("SELECT count(*) FROM folder_favorites WHERE folder_id = %d" % D_EMPTY)
c2, _, _ = req('e1', 'DELETE', '/tbox/api/folders/%d' % D_EMPTY)
ok('즐겨찾기가 걸린 빈 폴더를 주인이 지움 → 200(외래 키 CASCADE)', c == 200 and n_before == '1' and c2 == 200, (c, n_before, c2))
ok('  그 폴더의 즐겨찾기 줄 0', sql("SELECT count(*) FROM folder_favorites WHERE folder_id = %d" % D_EMPTY) == '0')

print('\n── 한도 — 파일 200 · 폴더 50 (결정 D-5: 가득 차면 안 보이는 내 줄을 먼저 걷는다)')
uid_e2 = sql("SELECT id FROM users WHERE username = 'claude_employee02'")
# 임시 DB 에서만 — 볼 수 있는 사내 파일 행 210 개를 SQL 로 만들고(운영 사본에는 사내·공개 파일이 적다), 그중 199 줄을 더 채운다
uid_e1 = sql("SELECT id FROM users WHERE username = 'claude_employee01'")
sql("INSERT INTO files (original_name, stored_name, ext, mime, size_bytes, sha256, description, access_level, uploaded_by) SELECT 'qf3a-한도-%s-' || g || '.txt', 'qf3a-lim-%s-' || g || '.txt', 'txt', 'text/plain', 10, repeat('0', 64), '', 'internal', %s FROM generate_series(1, 210) g" % (tag, tag, uid_e1))
sql("INSERT INTO file_favorites (user_id, file_id) SELECT %s, f.id FROM files f WHERE f.stored_name LIKE 'qf3a-lim-%s-%%' ORDER BY f.id LIMIT 199" % (uid_e2, tag))
n = sql("SELECT count(*) FROM file_favorites WHERE user_id = %s" % uid_e2)
ok('준비 — employee02 즐겨찾기 200 줄', n == '200', n)
c, d, raw = req('e2', 'PUT', '/tbox/api/files/%s/favorite' % F_PUB)
ok('201 번째 넣기 → 409 + 사람 문장', c == 409 and '200개까지' in ((d or {}).get('error') or ''), (c, raw[:200]))
victim = sql("SELECT f.public_id FROM file_favorites ff JOIN files f ON f.id = ff.file_id WHERE ff.user_id = %s AND f.public_id <> '%s' ORDER BY f.id LIMIT 1" % (uid_e2, F_INT))
sql("UPDATE files SET access_level = 'admin-only' WHERE public_id = '%s'" % victim)   # 임시 DB 에서만 — 한 줄을 못 보게
c, _, _ = req('e2', 'PUT', '/tbox/api/files/%s/favorite' % F_PUB)
ok('그중 하나가 안 보이게 되면 → 넣기 200(안 보이는 줄을 걷었다)', c == 200, c)
ok('  줄 수 200 그대로 · 안 보이는 줄 0', sql("SELECT count(*) FROM file_favorites WHERE user_id = %s" % uid_e2) == '200'
   and sql("SELECT count(*) FROM file_favorites ff JOIN files f ON f.id = ff.file_id WHERE ff.user_id = %s AND f.public_id = '%s'" % (uid_e2, victim)) == '0')
c, ids, _ = fav_files('e2')
ok('  목록 200 줄 · 순서 = 넣은 순(첫 줄 = 처음 넣은 사내 파일)', c == 200 and len(ids) == 200 and ids[0] == F_INT, (c, len(ids), ids[:2]))
sql("INSERT INTO folders (name, depth, access_level, created_by) SELECT 'qf3a-한도-%s-' || g, 1, 'internal', %s FROM generate_series(1, 55) g" % (tag, sql("SELECT id FROM users WHERE username = 'claude_employee01'")))
sql("INSERT INTO folder_favorites (user_id, folder_id) SELECT %s, d.id FROM folders d WHERE d.name LIKE 'qf3a-한도-%s-%%' AND NOT EXISTS (SELECT 1 FROM folder_favorites x WHERE x.user_id = %s AND x.folder_id = d.id) ORDER BY d.id LIMIT 49" % (uid_e2, tag, uid_e2))
ok('준비 — employee02 폴더 즐겨찾기 50 줄', sql("SELECT count(*) FROM folder_favorites WHERE user_id = %s" % uid_e2) == '50')
spare = sql("SELECT d.id FROM folders d WHERE d.name LIKE 'qf3a-한도-%s-%%' AND NOT EXISTS (SELECT 1 FROM folder_favorites x WHERE x.user_id = %s AND x.folder_id = d.id) ORDER BY d.id LIMIT 1" % (tag, uid_e2))
c, d, _ = req('e2', 'PUT', '/tbox/api/folders/%s/favorite' % spare)
ok('51 번째 폴더 → 409', c == 409 and '50개까지' in ((d or {}).get('error') or ''), c)

print('\n── QF3A-T4 최근 — 감사 로그에서 읽기 · 새로 쓰는 행 0 · 권한을 잃으면 빠짐 · 남의 것 0')
c1, _, _ = req('e2', 'GET', '/tbox/api/files/%s/preview' % F_INT)
time.sleep(1.1)
c2, _, _ = req('e2', 'GET', '/tbox/api/files/%s/download' % F_PUB)
ok('employee02 미리보기(사내)·받기(공개) 200', (c1, c2) == (200, 200), (c1, c2))
c, ids, raw, d = recent('e2')
ok('최근 = [받기 한 공개, 미리보기 한 사내] 순(마지막 연 때 내림)', c == 200 and ids[:2] == [F_PUB, F_INT], (c, ids[:3]))
ok('  줄마다 last_used_at 이 있다', all(isinstance(f.get('last_used_at'), str) and f['last_used_at'] != '' for f in d.get('files', [])))
ok('  20개 이하 · 전체 개수 칸 없음', len(ids) <= 20 and 'total' not in d, (len(ids), list(d.keys())))
c, ids_e1, _, _ = recent('e1')
ok('employee01 의 최근에는 employee02 가 연 것이 섞이지 않는다(공개 파일은 employee01 이 연 적 없음)', F_PUB not in ids_e1, ids_e1[:5])
def writes():
    subprocess.run(['sudo', '-n', '-u', 'postgres', 'psql', '-X', '-d', DB, '-t', '-A', '-c', 'SELECT pg_stat_clear_snapshot()'], capture_output=True, cwd='/')
    return sql("SELECT coalesce(sum(n_tup_ins + n_tup_upd + n_tup_del), 0) FROM pg_stat_user_tables WHERE relname NOT IN ('audit_logs', 'sessions')")
time.sleep(1.5); w0 = writes(); a0 = sql("SELECT count(*) FROM audit_logs")
for _ in range(5):
    recent('e2'); recent('a1')
time.sleep(1.5); w1 = writes(); a1 = sql("SELECT count(*) FROM audit_logs")
ok('최근 창구 10번 — 감사·세션 밖 표 쓰기 수 그대로(새로 쓰는 행 0)', w0 == w1 and w0 != '', (w0, w1))
ok('  감사 행도 그대로(목록 조회는 남기지 않는다)', a0 == a1, (a0, a1))
_, base_ids, _, _ = recent('e2')
c, other_ids, raw, _ = recent('e2', '?user_id=32&actor_id=32&user=claude_employee01&who=claude_admin01')
ok('대상 인자를 줘도 결과가 같다(창구에 대상 인자가 없다)', c == 200 and other_ids == base_ids, (other_ids[:3], base_ids[:3]))
c, _, _ = req('e1', 'PATCH', '/tbox/api/files/' + F_INT, {'access_level': 'team', 'team_id': 1})
c, ids, raw, _ = recent('e2')
ok('권한을 잃은 파일(팀으로 좁힘)은 employee02 최근 원문에 id·이름 0', F_INT.encode() not in raw and name_int.encode() not in raw, raw[:200])
req('a1', 'PATCH', '/tbox/api/files/' + F_INT, {'access_level': 'internal'})
c, ids, _, _ = recent('e2')
ok('  되돌리면 다시 나온다', F_INT in ids, ids[:3])
c1, _, _ = req('e1', 'GET', '/tbox/api/files/%s/download' % F_TEAM)
c, ids, raw, _ = recent('e2')
ok('employee01 이 받은 팀 파일은 employee02 최근에 0', c1 == 200 and F_TEAM.encode() not in raw, (c1, raw[:120]))
c, ids_a, _, _ = recent('a1')
ok('관리자의 최근에도 남의 것 0(자기가 연 것만)', F_PUB not in ids_a and F_INT not in ids_a, ids_a[:3])

print('PY_PASS=%d PY_FAIL=%d' % (P, F))
PY

hr "QF3A-T1 · T2 · T4 (파이썬 몸통)"
py_secret S_E1 S_E2 S_M1 S_A1 S_G1 -- python3 "$WORK/drv.py" "$SITE" "$UA" "$DB" > "$WORK/drv.out" 2>&1
cat "$WORK/drv.out"
pp=$(sed -n 's/^PY_PASS=\([0-9]*\) PY_FAIL=\([0-9]*\)$/\1/p' "$WORK/drv.out"); pf=$(sed -n 's/^PY_PASS=\([0-9]*\) PY_FAIL=\([0-9]*\)$/\2/p' "$WORK/drv.out")
if [[ "$pp" =~ ^[0-9]+$ ]] && [[ "$pf" =~ ^[0-9]+$ ]]; then PASS=$((PASS + pp)); FAIL=$((FAIL + pf)); else echo "  ✖ 파이썬 몸통이 끝줄을 못 찍었다"; FAIL=$((FAIL+1)); fi
[ "${pp:-0}" -ge 55 ] 2>/dev/null && expect "몸통이 헛돌지 않았다(통과 55 이상 — 몸통 단언 57)" "yes" "yes" || expect "몸통이 헛돌지 않았다(통과 55 이상 — 몸통 단언 57)" "yes" "no(${pp:-?})"

hr "A-T6 — 정적 · 서버 로그"
errs_after=$(grep -ciE 'PHP (Fatal|Warning|Notice|Deprecated)|SQLSTATE|Uncaught' "$INST/server.log")
expect "서버 로그의 새 PHP 오류·SQLSTATE 줄" "0" "$((errs_after - errs_before))"
[ "$((errs_after - errs_before))" = "0" ] || grep -iE 'PHP (Fatal|Warning|Notice|Deprecated)|SQLSTATE|Uncaught' "$INST/server.log" | tail -5 | sed 's/^/    /'
nbad=0
for f in public/index.php src/handlers/favorites_files.php src/handlers/favorites_folders.php src/handlers/files_recent.php src/handlers/home.php \
         src/help/screens.php src/help/community.php src/views/shell_top.php src/community/routes.php src/community/handlers/favorites.php \
         src/community/handlers/recent_list.php src/community/handlers/recent_clear.php src/community/handlers/pages_get.php src/community/handlers/page_view.php; do
  php -l "$ROOT/$f" >/dev/null 2>&1 || { echo "  ✖ php -l $f"; nbad=$((nbad+1)); }
done
expect "바뀐 PHP php -l 실패" "0" "$nbad"
if [ -f "$ROOT/scripts/security_lint.sh" ]; then
  ( cd "$ROOT" && bash scripts/security_lint.sh > "$WORK/lint.txt" 2>&1 ); rc=$?
  expect "보안 린트 종료 코드" "0" "$rc"
  [ "$rc" -eq 0 ] || tail -8 "$WORK/lint.txt" | sed 's/^/    /'
fi
if git -C "$ROOT" rev-parse -q --verify eaf09e8 >/dev/null 2>&1; then
  tokconst() { sed -n '/^const TBOX_API_TOKEN_\(ROUTES\|PERSONAL_ROUTES\|ONLY_ROUTES\) = \[/,/^\];/p'; }
  git -C "$ROOT" show eaf09e8:public/index.php | tokconst > "$WORK/tok_base.txt"
  tokconst < "$ROOT/public/index.php" > "$WORK/tok_now.txt"
  expect "토큰 창구 목록 셋(TBOX_API_TOKEN_*) 읽힘(비지 않음)" "yes" "$([ -s "$WORK/tok_base.txt" ] && [ "$(grep -c '^const' "$WORK/tok_base.txt")" = 3 ] && echo yes || echo no)"
  expect "토큰 창구 목록 셋 = 기준 eaf09e8 글자 그대로(새 창구는 토큰으로 못 부른다)" "same" "$(cmp -s "$WORK/tok_base.txt" "$WORK/tok_now.txt" && echo same || echo diff)"
  expect "새 라우트 일곱 모두 정책 file.read" "7" "$(git -C "$ROOT" diff eaf09e8 -- public/index.php | grep -cE "^\+    \['(GET|PUT|DELETE)', +'/api/(files|folders)/[^']*(favorite|recent)[^']*', +'[a-z_]+\.php', +'file\.read'\]")"
fi

hr "결과"
printf '통과 %d · 실패 %d\n' "$PASS" "$FAIL"
note "임시 DB($DB · $DB2)·임시 폴더·임시 서버는 이 스크립트가 끝나며 지워진다. 운영에는 아무것도 남지 않는다."
[ "$FAIL" -eq 0 ] || exit 1
exit 0
