#!/usr/bin/env bash
# tests/community/qf3a.sh — 개발 대기열 6 · Q:F-3a 라운드 A 수락 시험, 커뮤니티 쪽 + 화면(두 앱)
#   지시서 /var/www/qaredi/docs/qf3a-favorites-recent-bulk-directive.md 3장
#
#   QF3A-T1 넣기 판정   claude_employee02 가 claude_employee01 의 **비공개** 페이지 id 로 PUT → 404 이고 본문이 없는 id 의 404 와 글자까지
#                       같다 · 사내 페이지 → 200 · Guest 는 사내 페이지 404 · CSRF 없이 403 · 미로그인 401 · 넣기·빼기·순서가 감사 행을
#                       늘리지 않는다(결정 M-2)
#   QF3A-T2 사라짐      employee02 가 즐겨찾기한 employee01 의 사내 페이지 → 주인이 비공개로 → employee02 목록 응답 **원문**에 제목·id 0 →
#                       사내로 되돌리면 다시 보임 · 휴지통·되살리기도 같음 · 순서 바꾸기(PATCH) · 잘못된 몸 400 · 한도 200(D-5)
#   QF3A-T3 관리자 특권  claude_admin01 이 남의 비공개 페이지를 주소로 열어(감사 `page.read allow admin_all` 한 줄) 즐겨찾기 →
#                       PUT 200(listed:false) · 즐겨찾기 목록·최근 목록 원문에는 없다(검색과 같은 조건절)
#   QF3A-T5 최근        5분 안 두 번 열어도 갱신 0(행 xmin 그대로) · 5분 지난 뒤 여는 첫 번에 UPDATE 1회 · 51번째 페이지를 열면 가장 오래된
#                       줄 삭제(50 유지) · 「지우기」 뒤 0(남의 줄은 그대로) · 못 보게 된 페이지 빠짐 · 대상 인자 없음
#   QF3A-T9 화면(라운드 A 몫)  tests/browser/qf3a_favorites.py — 두 앱 ☆ · 사이드바 「즐겨찾기」「최근」 · t-box 「즐겨찾기」「최근」 보기 ·
#                       라이트·다크·390px 스크린샷(QF3A_SHOTS 폴더 — 사람이 직접 연다) · pageerror·console.error·HTTP≥400 0
#   REGRESS=1           같은 임시 인스턴스에서 회귀 — 커뮤니티 stage3~4c · stage26 · help_button · q_launcher · f68(qf2_search.sh 의 회귀 틀)
#                       목록은 REGRESS_LIST 로 바꿀 수 있다(예: "q_launcher api_whoami api_read api_folders api_tokens").
#   REGRESS_ONLY=1      T 절·화면을 건너뛰고 회귀만(REGRESS_LIST 와 함께).
#
# ★ 운영 DB·운영 포트를 건드리지 않는다 — 임시 DB `tbox_qf3a_<포트>_test`, 임시 인스턴스 `php -S 127.0.0.1:<포트>`(기본 8161).
#   운영 DB 는 pg_dump 로 **읽어** 임시 DB 에 붓기만 한다(파이프). 검토 계정은 claude_ 다섯(F-71). 비밀은 표준입력으로만(R-62).
#   임시 .env 원본 — 복제본에서는 mktemp 폴더(0700)의 사본을 QF2_ENV_SRC 로 넘기고 끝에 지운다.
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
. "$ROOT/tests/lib/review_accounts.sh" || { echo "tests/lib/review_accounts.sh 를 읽지 못했다 — 시험을 시작하지 않는다"; exit 3; }
. "$ROOT/tests/lib/secrets.sh" || { echo "tests/lib/secrets.sh 를 읽지 못했다 — 시험을 시작하지 않는다 (R-62)"; exit 3; }
PORT="${QF3A_PORT:-8161}"
case "$PORT" in 80|8082|8081) echo "운영 포트($PORT)는 쓰지 않는다"; exit 3 ;; esac
SITE="http://127.0.0.1:$PORT"
BASE="$SITE/tbox"
CBASE="$SITE/community"
UA="tbox-review/$(date +%F)"
SRC_DB="tbox_re"                  # 운영 — **읽기만** 한다(pg_dump)
DB="tbox_qf3a_${PORT}_test"       # 임시 — 여기에만 쓴다
MIG="2026-10-03_qf3a_favorites_recent.sql"
JAR_DIR="$(mktemp -d)"
WORK="$(mktemp -d)"
chmod 700 "$JAR_DIR" "$WORK"
SHOTS="${QF3A_SHOTS:-$WORK/shots}"
mkdir -p "$SHOTS"
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
session_of() { awk '$6=="tbox_session"{print $7}' "$JAR_DIR/$1.jar" 2>/dev/null | tail -1; }

# ════════════════════════════════════════════════════════════════
hr "Q:F-3a 라운드 A (커뮤니티 · 화면) — 임시 인스턴스 준비 (운영 DB 에는 쓰지 않는다)"
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
DUMP_PS=("${PIPESTATUS[@]}")
[ "${DUMP_PS[0]}" -eq 0 ] || { echo "운영 DB 를 읽지 못했다 (pg_dump)"; exit 3; }
[ -n "$(psqlq "SELECT count(*) FROM community.pages")" ] || { echo "임시 DB 로 옮기지 못했다 — $(head -2 "$WORK/restore.err")"; exit 3; }
note "임시 DB $DB — 페이지 $(psqlq "SELECT count(*) FROM community.pages") · 파일 $(psqlq "SELECT count(*) FROM files")"

sub "② 마이그레이션(앞 둘은 이미 있으면 그대로) + 커뮤니티 권한 — 임시 DB 에만"
for m in 2026-10-01_qf2_search.sql 2026-10-02_qf2b_files_search.sql "$MIG"; do
  sudo -n -u postgres psql -X -q -d "$DB" -v ON_ERROR_STOP=1 -f - < "$ROOT/db/migrations/$m" 2>&1 | grep -v 'could not change directory\|already exists, skipping' | sed 's/^/    /'
done
sudo -n -u postgres psql -X -q -d "$DB" -v ON_ERROR_STOP=1 -f - < "$ROOT/db/community/grants.sql" 2>&1 | grep -v 'could not change directory' | sed 's/^/    /'
expect "커뮤니티 표 둘" "2" "$(psqlq "SELECT count(*) FROM pg_tables WHERE schemaname = 'community' AND tablename IN ('page_favorites','page_recent')")"

sub "③ 저장소를 임시 폴더로 복사하고 인스턴스를 띄운다(자산도 내준다 — 화면 시험)"
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
PLAN_DIR="${PLAN_OVERRIDE:-$(cd "$ROOT/.." && pwd)/community}"
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
( cd "$INST/app" && exec php -S "127.0.0.1:$PORT" -t public router.php > "$INST/server.log" 2>&1 ) &
SERVER_PID=$!
READY=""
for _ in $(seq 1 20); do
  sleep 0.5
  READY=$(curl -s -o /dev/null -w '%{http_code}' -A "$UA" "$CBASE/api/me/favorites")
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

if [ "${REGRESS_ONLY:-0}" != "1" ]; then
cat > "$WORK/drv.py" <<'PY'
import json, subprocess, sys, time, urllib.request, urllib.error, uuid
SITE, UA, DB, IDS_OUT = sys.argv[1], sys.argv[2], sys.argv[3], sys.argv[4]
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
def req(who, m, path, body=None, csrf=True, cookie=True, raw_body=None):
    h = {'User-Agent': UA}
    if cookie and who: h['Cookie'] = 'tbox_session=' + S[who]
    if m != 'GET' and csrf and who: h['X-CSRF-Token'] = CSRF.get(who, '')
    data = None
    if body is not None:
        data = json.dumps(body, ensure_ascii=False).encode('utf-8'); h['Content-Type'] = 'application/json'
    if raw_body is not None:
        data = raw_body; h['Content-Type'] = 'application/json'
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
C = '/community/api'
def new_page(who, title):
    req(who, 'GET', C + '/me/space')
    c, d, _ = req(who, 'POST', C + '/pages', {'title': title})
    return c, ((d or {}).get('page') or {}).get('id', '')
def favs(who):
    c, d, raw = req(who, 'GET', C + '/me/favorites')
    return c, [p['id'] for p in (d or {}).get('pages', [])], raw
def recents(who, qs=''):
    c, d, raw = req(who, 'GET', C + '/me/recent' + qs)
    return c, [p['id'] for p in (d or {}).get('pages', [])], raw, d
def audit_max():
    return sql("SELECT coalesce(max(id), 0) FROM audit_logs")
def audit_since(mark, names):
    # mark 뒤에 그 사람들로 남은 감사 행의 「행동|결과」 목록(쉼표) — 비었으면 ''
    return sql("SELECT coalesce(string_agg(action || '|' || result, ',' ORDER BY id), '') FROM audit_logs WHERE id > %s AND actor_name IN (%s)" % (mark, ','.join("'%s'" % n for n in names)))
uid = {n: sql("SELECT id FROM users WHERE username = 'claude_%s'" % n) for n in ('employee01', 'employee02', 'admin01', 'guest01')}

tag = uuid.uuid4().hex[:6]
print('\n── 준비 — employee01 이 페이지 넷(비공개 둘 · 사내 둘)을 앱 창구로 만든다(제목 표식 %s)' % tag)
c1, P_PRIV = new_page('e1', 'qf3a 비공개 %s' % tag)
c2, P_INT = new_page('e1', 'qf3a 사내 %s' % tag)
c3, P_INT2 = new_page('e1', 'qf3a 사내둘 %s' % tag)
c4, P_ADMIN = new_page('e1', 'qf3a 관리자가볼 %s' % tag)
ok('페이지 넷 만들기 201', [c1, c2, c3, c4] == [201] * 4, [c1, c2, c3, c4])
for p in (P_INT, P_INT2):
    c, _, raw = req('e1', 'PATCH', C + '/pages/%s/visibility' % p, {'visibility': 'internal'})
    ok('사내로 열기 200', c == 200, (c, raw[:120]))
NONE_ID = str(uuid.uuid4())

print('\n── QF3A-T1 넣기 판정 — 못 읽는 페이지는 없는 페이지와 같은 404')
a0 = audit_max()
c_priv, _, raw_priv = req('e2', 'PUT', C + '/pages/%s/favorite' % P_PRIV)
c_none, _, raw_none = req('e2', 'PUT', C + '/pages/%s/favorite' % NONE_ID)
ok('employee02 → employee01 의 비공개 페이지 PUT = 404', c_priv == 404, c_priv)
ok('  본문이 없는 id 의 404 와 글자까지 같다', raw_priv == raw_none and c_none == 404, (raw_priv, raw_none))
ok('  본문에 제목이 없다', ('qf3a 비공개 %s' % tag).encode() not in raw_priv)
c, _, _ = req('e2', 'PUT', C + '/pages/%s/favorite' % ('-' * 36))
ok('UUID 모양이 아니면 404', c == 404, c)
c, d, _ = req('e2', 'PUT', C + '/pages/%s/favorite' % P_INT)
ok('employee02 → 사내 페이지 PUT = 200 · listed true', c == 200 and (d or {}).get('listed') is True, (c, d))
c, _, _ = req('e2', 'PUT', C + '/pages/%s/favorite' % P_INT)
ok('  두 번 넣어도 200(줄 하나)', c == 200 and sql("SELECT count(*) FROM community.page_favorites f JOIN community.pages p ON p.id = f.page_id WHERE f.user_id = %s AND p.public_id = '%s'" % (uid['employee02'], P_INT)) == '1')
c, _, _ = req('e2', 'PUT', C + '/pages/%s/favorite' % P_INT2)
ok('employee02 → 사내 둘 PUT 200', c == 200, c)
c, _, _ = req('g1', 'PUT', C + '/pages/%s/favorite' % P_INT)
ok('Guest → 사내 페이지 404(Guest 는 사내를 못 읽는다)', c == 404, c)
c, _, _ = req('e2', 'PUT', C + '/pages/%s/favorite' % P_INT, csrf=False)
ok('CSRF 없이 PUT → 403', c == 403, c)
for m, path in (('GET', '/me/favorites'), ('GET', '/me/recent'), ('PUT', '/pages/%s/favorite' % P_INT), ('DELETE', '/me/recent')):
    c, _, _ = req(None, m, C + path, cookie=False)
    ok('미로그인 %s %s → 401' % (m, path.split('/')[1]), c == 401, c)
c, ids, raw = favs('e2')
ok('employee02 즐겨찾기 = [사내, 사내 둘] (넣은 순)', c == 200 and ids == [P_INT, P_INT2], (c, ids))
d = json.loads(raw)
ok('  응답에 전체 개수 칸이 없다', 'total' not in d and b'"total"' not in raw, list(d.keys()))
c, _, _ = req('e2', 'DELETE', C + '/pages/%s/favorite' % P_PRIV)
ok('넣지 않은(못 보는) 페이지 빼기도 200 — 있든 없든 같은 답', c == 200, c)

print('\n── 순서 바꾸기(PATCH /me/favorites) — 내 줄만 · 잘못된 몸 400')
c, _, _ = req('e2', 'PATCH', C + '/me/favorites', {'ids': [P_INT2, P_INT]})
c2, ids, _ = favs('e2')
ok('순서 [사내 둘, 사내] 로 바꿈 200 → 목록이 그 순서', c == 200 and ids == [P_INT2, P_INT], (c, ids))
bad = [({'ids': ['nope']}, 'UUID 아님'), ({'ids': [P_INT, P_INT]}, '겹침'), ({'ids': [P_INT], 'user_id': 1}, '모르는 키'),
       ({'ids': 'x'}, '배열 아님'), ({'ids': {'a': P_INT}}, '목록이 아닌 객체'), ({'ids': [str(uuid.uuid4()) for _ in range(201)]}, '201개')]
for body, why in bad:
    c, _, _ = req('e2', 'PATCH', C + '/me/favorites', body)
    ok('  잘못된 몸(%s) → 400' % why, c == 400, c)
e1_before = sql("SELECT string_agg(page_id || ':' || position, ',' ORDER BY page_id) FROM community.page_favorites WHERE user_id = %s" % uid['employee01'])
req('e1', 'PUT', C + '/pages/%s/favorite' % P_INT)
req('e1', 'PUT', C + '/pages/%s/favorite' % P_INT2)
e1_mid = sql("SELECT string_agg(page_id || ':' || position, ',' ORDER BY page_id) FROM community.page_favorites WHERE user_id = %s" % uid['employee01'])
req('e2', 'PATCH', C + '/me/favorites', {'ids': [P_INT, P_INT2]})
ok('employee02 의 순서 바꾸기가 employee01 의 줄을 바꾸지 않는다', e1_mid == sql("SELECT string_agg(page_id || ':' || position, ',' ORDER BY page_id) FROM community.page_favorites WHERE user_id = %s" % uid['employee01']) and e1_mid != e1_before, (e1_before, e1_mid))
got = audit_since(a0, ['claude_employee02', 'claude_guest01'])
ok('넣기·빼기·순서가 감사 행을 늘리지 않았다(결정 M-2) — 그 사이 employee02·Guest 의 감사 행은 CSRF 거부 하나(라우터 관문)뿐', got == 'csrf|deny', got)

print('\n── QF3A-T2 못 보게 되면 사라짐 — 응답 원문에 제목·id 0')
title_int = 'qf3a 사내 %s' % tag
c, _, raw = req('e1', 'PATCH', C + '/pages/%s/visibility' % P_INT, {'visibility': 'private'})
ok('주인이 사내 → 비공개 200', c == 200, (c, raw[:120]))
c, ids, raw = favs('e2')
ok('employee02 즐겨찾기 원문에 그 id 0', c == 200 and P_INT.encode() not in raw, raw[:200])
ok('  원문에 그 제목 0', title_int.encode() not in raw, raw[:200])
ok('  다른 즐겨찾기(사내 둘)는 그대로', P_INT2 in ids, ids)
c, _, _ = req('e1', 'PATCH', C + '/pages/%s/visibility' % P_INT, {'visibility': 'internal'})
c, ids, _ = favs('e2')
ok('사내로 되돌리면 다시 보인다(줄은 남아 있었다)', P_INT in ids, ids)
c, _, _ = req('e1', 'DELETE', C + '/pages/' + P_INT)
ok('주인이 휴지통으로 200', c == 200, c)
c, ids, raw = favs('e2')
ok('  휴지통 페이지는 원문에 id·제목 0', P_INT.encode() not in raw and title_int.encode() not in raw, raw[:200])
c, _, raw = req('e1', 'POST', C + '/pages/%s/restore' % P_INT, {})
ok('주인이 되살림 200', c == 200, (c, raw[:120]))
c, ids, _ = favs('e2')
ok('  다시 보인다', P_INT in ids, ids)

print('\n── QF3A-T3 관리자 특권 — admin_all 로 연 남의 비공개 페이지는 목록에 안 나온다')
n_admin0 = sql("SELECT count(*) FROM audit_logs WHERE actor_name = 'claude_admin01' AND action = 'page.read' AND result = 'allow' AND reason = 'admin_all'")
c, d, _ = req('a1', 'GET', C + '/pages/' + P_ADMIN)
ok('admin01 이 employee01 의 비공개 페이지를 주소로 연다 200 · granted_by admin_all', c == 200 and (d or {}).get('granted_by') == 'admin_all', (c, (d or {}).get('granted_by')))
ok('  감사 page.read allow admin_all 한 줄', int(sql("SELECT count(*) FROM audit_logs WHERE actor_name = 'claude_admin01' AND action = 'page.read' AND result = 'allow' AND reason = 'admin_all'")) == int(n_admin0) + 1)
c, d, _ = req('a1', 'PUT', C + '/pages/%s/favorite' % P_ADMIN)
ok('admin01 즐겨찾기 PUT 200 · listed false', c == 200 and (d or {}).get('listed') is False, (c, d))
ok('  줄은 생겼다', sql("SELECT count(*) FROM community.page_favorites f JOIN community.pages p ON p.id = f.page_id WHERE f.user_id = %s AND p.public_id = '%s'" % (uid['admin01'], P_ADMIN)) == '1')
c, ids, raw = favs('a1')
ok('  admin01 즐겨찾기 원문에 그 id·제목 0', c == 200 and P_ADMIN.encode() not in raw and ('qf3a 관리자가볼 %s' % tag).encode() not in raw, raw[:200])
c, ids, raw, _ = recents('a1')
ok('  admin01 최근 원문에도 그 id 0(열어서 줄은 남았어도)', c == 200 and P_ADMIN.encode() not in raw
   and sql("SELECT count(*) FROM community.page_recent r JOIN community.pages p ON p.id = r.page_id WHERE r.user_id = %s AND p.public_id = '%s'" % (uid['admin01'], P_ADMIN)) == '1', raw[:200])

print('\n── QF3A-T5 최근 — 5분에 한 번 · 50줄 · 지우기 · 못 보게 되면 빠짐')
c, _, _ = req('e2', 'DELETE', C + '/me/recent')
ok('시작 — employee02 최근 지우기 200 · 줄 0', c == 200 and sql("SELECT count(*) FROM community.page_recent WHERE user_id = %s" % uid['employee02']) == '0', c)
rowq = "SELECT r.xmin::text || '|' || r.last_viewed_at::text FROM community.page_recent r JOIN community.pages p ON p.id = r.page_id WHERE r.user_id = %s AND p.public_id = '%s'" % (uid['employee02'], P_INT2)
def upd_count():
    # PG14 통계는 백엔드가 트랜잭션을 끝낼 때 500ms 간격으로만 보낸다 — 앱 연결(php -S 한 워커)이 쌓아 둔 수를 내보내게
    # 0.7초 뒤 가벼운 요청 하나를 더 보내고, 수집기가 파일을 쓸 때까지 기다린 뒤 읽는다
    time.sleep(0.7)
    req('e2', 'GET', '/tbox/api/me')
    time.sleep(1.2)
    return int(sql("SELECT n_tup_upd FROM pg_stat_user_tables WHERE relid = 'community.page_recent'::regclass") or -1)
u0 = upd_count()
c, _, _ = req('e2', 'GET', C + '/pages/' + P_INT2)
r1 = sql(rowq)
c2, _, _ = req('e2', 'GET', C + '/pages/' + P_INT2)
r2 = sql(rowq)
ok('처음 열면 줄 하나(INSERT) — 5분 안에 다시 열어도 그 줄 그대로(xmin·시각 같음 — 갱신 0)', c == 200 and c2 == 200 and r1 != '' and r1 == r2, (r1, r2))
u1 = upd_count()
ok('  그동안 page_recent UPDATE 수 0', u1 == u0, (u0, u1))
sql("UPDATE community.page_recent SET last_viewed_at = now() - interval '6 minutes' WHERE user_id = %s" % uid['employee02'])   # 임시 DB — 6분 지난 흉내
u2 = upd_count()
r_old = sql(rowq)
req('e2', 'GET', C + '/pages/' + P_INT2)
r3 = sql(rowq)
req('e2', 'GET', C + '/pages/' + P_INT2)
r4 = sql(rowq)
u3 = upd_count()
ok('5분 지난 뒤 여는 첫 번에 시각을 민다(xmin 바뀜)', r3 != r_old and r3.split('|')[0] != r_old.split('|')[0], (r_old, r3))
ok('  바로 다시 열면 그대로(두 번째는 갱신 0)', r4 == r3, (r3, r4))
ok('  그 사이 page_recent UPDATE 수 = 1', u3 - u2 == 1, (u2, u3))
c, ids, raw, d = recents('e2')
ok('employee02 최근 = [사내 둘]', c == 200 and ids == [P_INT2], ids)
ok('  줄마다 last_viewed_at · 전체 개수 칸 없음', all(isinstance(p.get('last_viewed_at'), str) for p in d.get('pages', [])) and 'total' not in d)
c, ids2, _, _ = recents('e2', '?user_id=%s&user=claude_employee01' % uid['employee01'])
ok('대상 인자를 줘도 결과가 같다', ids2 == ids, (ids, ids2))
req('e2', 'GET', C + '/pages/' + P_INT)
c, ids, raw, _ = recents('e2')
ok('사내 페이지를 열면 최근 맨 위', ids[:1] == [P_INT], ids)
req('e1', 'PATCH', C + '/pages/%s/visibility' % P_INT, {'visibility': 'private'})
c, ids, raw, _ = recents('e2')
ok('주인이 비공개로 바꾸면 employee02 최근 원문에 id·제목 0', P_INT.encode() not in raw and title_int.encode() not in raw, raw[:200])
req('e1', 'PATCH', C + '/pages/%s/visibility' % P_INT, {'visibility': 'internal'})
c, ids, _, _ = recents('e2')
ok('  되돌리면 다시 나온다', P_INT in ids, ids)
print('    · employee02 가 자기 페이지 51장을 만들어 차례로 연다(약 1분)')
mine = []
for i in range(51):
    c, pid = new_page('e2', 'qf3a 최근 %s %02d' % (tag, i))
    if c == 201: mine.append(pid)
ok('employee02 페이지 51장 만들기', len(mine) == 51, len(mine))
req('e2', 'DELETE', C + '/me/recent')
for pid in mine[:50]:
    req('e2', 'GET', C + '/pages/' + pid)
n50 = sql("SELECT count(*) FROM community.page_recent WHERE user_id = %s" % uid['employee02'])
oldest = sql("SELECT p.public_id FROM community.page_recent r JOIN community.pages p ON p.id = r.page_id WHERE r.user_id = %s ORDER BY r.last_viewed_at ASC, r.page_id ASC LIMIT 1" % uid['employee02'])
ok('50장을 열면 줄 50 · 가장 오래된 줄 = 첫 페이지', n50 == '50' and oldest == mine[0], (n50, oldest == mine[0]))
req('e2', 'GET', C + '/pages/' + mine[50])
n51 = sql("SELECT count(*) FROM community.page_recent WHERE user_id = %s" % uid['employee02'])
has_first = sql("SELECT count(*) FROM community.page_recent r JOIN community.pages p ON p.id = r.page_id WHERE r.user_id = %s AND p.public_id = '%s'" % (uid['employee02'], mine[0]))
has_last = sql("SELECT count(*) FROM community.page_recent r JOIN community.pages p ON p.id = r.page_id WHERE r.user_id = %s AND p.public_id = '%s'" % (uid['employee02'], mine[50]))
ok('51번째를 열면 줄 50 그대로 · 가장 오래된 줄(첫 페이지) 삭제 · 51번째 있음', (n51, has_first, has_last) == ('50', '0', '1'), (n51, has_first, has_last))
c, ids, _, _ = recents('e2')
ok('  최근 창구 = 50줄 · 맨 위 = 51번째', c == 200 and len(ids) == 50 and ids[0] == mine[50], (c, len(ids)))
req('e1', 'GET', C + '/pages/' + P_INT)   # employee01 도 줄이 있게(남의 줄이 그대로인지 보려고)
e1_rows = sql("SELECT count(*) FROM community.page_recent WHERE user_id = %s" % uid['employee01'])
c, _, _ = req('e2', 'DELETE', C + '/me/recent')
c2, ids, _, _ = recents('e2')
ok('「지우기」 뒤 employee02 줄 0 · 목록 0', c == 200 and ids == [] and sql("SELECT count(*) FROM community.page_recent WHERE user_id = %s" % uid['employee02']) == '0', (c, ids))
ok('  employee01 의 줄은 그대로', sql("SELECT count(*) FROM community.page_recent WHERE user_id = %s" % uid['employee01']) == e1_rows and e1_rows != '0', e1_rows)
c, _, _ = req('e2', 'DELETE', C + '/me/recent', csrf=False)
ok('  CSRF 없이 지우기 → 403', c == 403, c)
ok('조회수(page_views)는 지우기와 무관 — employee02 의 조회 줄이 남아 있다', int(sql("SELECT count(*) FROM community.page_views WHERE user_id = %s" % uid['employee02'])) >= 51)

print('\n── 한도 200 (결정 D-5 — 가득 차면 목록에 안 나오는 내 줄을 먼저 걷는다)')
live_mine = "SELECT count(*) FROM community.pages p JOIN community.spaces s ON s.id = p.space_id WHERE s.kind = 'personal' AND s.owner_user_id = %s AND p.deleted_at IS NULL" % uid['employee02']
need = 200 - int(sql(live_mine) or 0)
print('    · employee02 페이지를 %d장 더 만든다(앱 창구 — 즐겨찾기 200 줄을 채울 재료)' % max(0, need))
for i in range(max(0, need)):
    new_page('e2', 'qf3a 한도 %s %03d' % (tag, i))
sql("DELETE FROM community.page_favorites WHERE user_id = %s" % uid['employee02'])
sql("INSERT INTO community.page_favorites (user_id, page_id, position) SELECT %s, p.id, row_number() OVER (ORDER BY p.id) FROM community.pages p JOIN community.spaces s ON s.id = p.space_id WHERE s.kind = 'personal' AND s.owner_user_id = %s AND p.deleted_at IS NULL ORDER BY p.id LIMIT 200" % (uid['employee02'], uid['employee02']))
n = sql("SELECT count(*) FROM community.page_favorites WHERE user_id = %s" % uid['employee02'])
ok('준비 — employee02 즐겨찾기 200 줄', n == '200', n)
c, d, _ = req('e2', 'PUT', C + '/pages/%s/favorite' % P_INT)
ok('201 번째 → 409 + 사람 문장', c == 409 and '200개까지' in ((d or {}).get('error') or ''), (c, d))
victim = sql("SELECT p.public_id FROM community.page_favorites f JOIN community.pages p ON p.id = f.page_id WHERE f.user_id = %s ORDER BY f.position LIMIT 1" % uid['employee02'])
sql("UPDATE community.pages SET deleted_at = now(), deleted_by = %s, purge_after = now() + interval '30 days' WHERE public_id = '%s'" % (uid['employee02'], victim))
c, _, _ = req('e2', 'PUT', C + '/pages/%s/favorite' % P_INT)
ok('그중 하나를 휴지통에 넣으면 → 넣기 200(목록에 안 나오는 줄을 걷었다)', c == 200, c)
ok('  줄 200 · 휴지통 페이지 줄 0', sql("SELECT count(*) FROM community.page_favorites WHERE user_id = %s" % uid['employee02']) == '200'
   and sql("SELECT count(*) FROM community.page_favorites f JOIN community.pages p ON p.id = f.page_id WHERE f.user_id = %s AND p.public_id = '%s'" % (uid['employee02'], victim)) == '0')
c, ids, _ = favs('e2')
ok('  목록 200 · 맨 뒤 = 방금 넣은 사내 페이지', c == 200 and len(ids) == 200 and ids[-1] == P_INT, (c, len(ids), ids[-1:] == [P_INT]))

print('\n── 화면 시험 준비 — employee01 이 받을 수 있는 파일 하나(앱 창구로 올리고, 임시 DB 에서만 검사 상태를 clean 으로)')
b = uuid.uuid4().hex
parts = [('--%s\r\nContent-Disposition: form-data; name="%s"\r\n\r\n%s\r\n' % (b, k, v)).encode() for k, v in (('access_level', 'internal'), ('description', 'qf3a 화면 시험'))]
parts.append(('--%s\r\nContent-Disposition: form-data; name="file"; filename="qf3a-화면-%s.txt"\r\nContent-Type: text/plain\r\n\r\n' % (b, tag)).encode() + b'qf3a\n\r\n')
parts.append(('--%s--\r\n' % b).encode())
h = {'User-Agent': UA, 'Cookie': 'tbox_session=' + S['e1'], 'X-CSRF-Token': CSRF['e1'], 'Content-Type': 'multipart/form-data; boundary=' + b}
try:
    with urllib.request.urlopen(urllib.request.Request(SITE + '/tbox/api/files', data=b''.join(parts), headers=h, method='POST'), timeout=60) as x:
        up = json.loads(x.read()); upc = x.status
except urllib.error.HTTPError as e:
    up = {}; upc = e.code
FILE_ID = ((up or {}).get('file') or up or {}).get('id', '')
sql("UPDATE files SET scan_status = 'clean' WHERE public_id = '%s'" % FILE_ID)
ok('화면 시험용 파일 올리기 201 · clean', upc == 201 and sql("SELECT scan_status FROM files WHERE public_id = '%s'" % FILE_ID) == 'clean', upc)
json.dump({'file_id': FILE_ID}, open(IDS_OUT, 'w'))

print('PY_PASS=%d PY_FAIL=%d' % (P, F))
PY

hr "QF3A-T1 · T2 · T3 · T5 (파이썬 몸통)"
py_secret S_E1 S_E2 S_M1 S_A1 S_G1 -- python3 "$WORK/drv.py" "$SITE" "$UA" "$DB" "$WORK/ids.json" > "$WORK/drv.out" 2>&1
cat "$WORK/drv.out"
pp=$(sed -n 's/^PY_PASS=\([0-9]*\) PY_FAIL=\([0-9]*\)$/\1/p' "$WORK/drv.out"); pf=$(sed -n 's/^PY_PASS=\([0-9]*\) PY_FAIL=\([0-9]*\)$/\2/p' "$WORK/drv.out")
if [[ "$pp" =~ ^[0-9]+$ ]] && [[ "$pf" =~ ^[0-9]+$ ]]; then PASS=$((PASS + pp)); FAIL=$((FAIL + pf)); else echo "  ✖ 파이썬 몸통이 끝줄을 못 찍었다"; FAIL=$((FAIL+1)); fi
[ "${pp:-0}" -ge 60 ] 2>/dev/null && expect "몸통이 헛돌지 않았다(통과 60 이상)" "yes" "yes" || expect "몸통이 헛돌지 않았다(통과 60 이상)" "yes" "no(${pp:-?})"

hr "QF3A-T9 — 화면(헤드리스 · 두 앱) — tests/browser/qf3a_favorites.py"
note "스크린샷 폴더: $SHOTS (사람이 직접 연다)"
py_secret S_E1 S_E2 -- env PATH="$HOME/.local/bin:$PATH" python3 "$ROOT/tests/browser/qf3a_favorites.py" "$SITE" "$UA" "$SHOTS" "$WORK/ids.json" > "$WORK/browser.out" 2>&1
rc=$?
cat "$WORK/browser.out"
expect "화면 시험 종료 코드" "0" "$rc"
bf=$(sed -n 's/^SMOKE_FAIL=\([0-9]*\)$/\1/p' "$WORK/browser.out")
expect "화면 시험 실패 수" "0" "${bf:-?}"
bp=$(grep -c '^  ✔' "$WORK/browser.out")
[ "$bp" -ge 25 ] && expect "화면 시험이 헛돌지 않았다(✔ 25 이상)" "yes" "yes" || expect "화면 시험이 헛돌지 않았다(✔ 25 이상)" "yes" "no($bp)"

hr "서버 로그"
errs_after=$(grep -ciE 'PHP (Fatal|Warning|Notice|Deprecated)|SQLSTATE|Uncaught' "$INST/server.log")
expect "서버 로그의 새 PHP 오류·SQLSTATE 줄" "0" "$((errs_after - errs_before))"
[ "$((errs_after - errs_before))" = "0" ] || grep -iE 'PHP (Fatal|Warning|Notice|Deprecated)|SQLSTATE|Uncaught' "$INST/server.log" | tail -5 | sed 's/^/    /'
fi   # REGRESS_ONLY

# ════════════════════════════════════════════════════════════════
if [ "${REGRESS:-0}" = "1" ] || [ "${REGRESS_ONLY:-0}" = "1" ]; then
  hr "회귀 — 같은 임시 인스턴스에서(qf2_search.sh · stage7d1.sh 의 회귀 틀 그대로)"
  note "각 스크립트를 임시 인스턴스 폴더로 복사해 SITE·DB 만 바꿔 돈다(운영 8082·tbox_re 에 닿지 않는다)."
  sed -i "s#http://127.0.0.1:8082#$SITE#g" "$INST/app/scripts/ui_snapshot.sh"
  if grep -v '^\s*#' "$INST/app/scripts/ui_snapshot.sh" | grep -q '8082'; then
    echo "  ✖ ui_snapshot.sh 에 운영 주소가 남았다 — 회귀를 돌리지 않는다"; FAIL=$((FAIL+1)); REGRESS_LIST=" "
  fi
  for s in ${REGRESS_LIST:-community/stage3 community/stage4a community/stage4b community/stage4c stage26 help_button q_launcher f68}; do
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
fi

hr "결과"
printf '통과 %d · 실패 %d\n' "$PASS" "$FAIL"
note "임시 DB($DB)·임시 폴더·임시 서버는 이 스크립트가 끝나며 지워진다. 운영에는 아무것도 남지 않는다."
[ "$FAIL" -eq 0 ] || exit 1
exit 0
