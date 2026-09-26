#!/usr/bin/env bash
# tests/community/qf3b.sh — 개발 대기열 6 · Q:F-3a 라운드 B 수락 시험, 커뮤니티 쪽 + 화면(두 앱)
#   지시서 /var/www/qaredi/docs/qf3a-favorites-recent-bulk-directive.md 1.3 · 3장 QF3A-T6 · T7 · T9 · 4장 M-4 · M-6 · M-7
#   + 라운드 A 독립 검토·취합 메모(L-1 · L-2 · L-3 · 관리자 특권 열람 최근 · D-2 30일 정리 · tbox_perm REVOKE)
#
#   D-2 정리          ① 마이그레이션 2026-10-05 전에 이미 영구 삭제된 페이지를 가리키던 즐겨찾기·최근 줄 → 마이그레이션이 한 번 걷는다 ·
#                     두 번 돌려도 오류 0 ② 새 정리 함수 = 7-A 몸 + 두 줄(글자 대조) · 소유자 tbox_owner · 앱 계정 EXECUTE 없음
#                     ③ 휴지통 페이지(하위 포함)가 30일이 지나 정리되면 그 페이지들의 즐겨찾기·최근 줄 0 · dry 는 아무것도 안 지움 ·
#                     아직 기한 전인 휴지통 페이지·살아 있는 페이지의 줄은 그대로 ④ 새 표 넷에 tbox_perm·tbox_push 권한 0
#   L-1 역할로 보기   admin01 이 「Guest 로 보기」 중 즐겨찾기 200 줄(남의 사내 페이지 — Guest 로는 안 보임)에서 자기 페이지를 넣으면 409 ·
#                     관리자 자신의 200 줄 그대로 · 보기를 끝내면 전처럼 안 보이는 줄만 걷고 200
#   L-2 동시 넣기     employee02 190 줄에서 서로 다른 페이지 15개를 동시에 PUT(워커 넷 인스턴스) → 200 ×10 · 409 ×5 · 줄 200 · 순서(position) 겹침 0
#   L-3 행 잠금       최근 줄이 5분 안이면 그 줄을 다른 트랜잭션이 잠가 둬도 페이지 열기가 기다리지 않는다(읽기만 — < 2초) ·
#                     5분이 지난 줄이면(쓰기 갈래) 잠금이 풀릴 때까지 기다린다(≥ 3초 — 두 갈래가 실제로 다르다는 증거)
#   권고 특권 열람    admin01 이 employee01 의 비공개 페이지를 `admin_all` 로 열어도 최근 줄 0(목록과 같은 조건절) · 사내 페이지는 1
#   QF3A-T6 커뮤니티  부모 A(+자식 A1) · X(+X1+X2 — 옮기면 깊이 9) · 잎 B 를 깊이 6 페이지 아래로 — 화면과 같은 순서로 한 건 창구
#                     (`PATCH …/move` — A · X · B, A1 은 보내지 않음) → A 200 · X 400(한 건 옮기기와 **같은 거부 본문**) · B 200 · A1 은 A 와
#                     함께 옮겨짐 · 감사 page.move 3행(allow 2 · deny 1)
#   QF3A-T9 화면      tests/browser/qf3a_bulk.py — 두 앱 선택·일괄 옮기기/휴지통/즐겨찾기·되돌리기·휴지통 여러 줄 되살리기·사이드바 ☆ ·
#                     도중 세션 만료 → 멈춤·남은 목록 유지 → 새 세션으로 「이어서 하기」(QF3A-T7 화면 몫) · 100개 한도 · 폴더 바꾸면 풀림 ·
#                     키보드(Tab·Space·Shift+클릭·Esc) · 360·412(터치)·820·1280 × 라이트·다크 스크린샷 · 가로 넘침 0 · 막대 한 줄 · 콘솔 오류 0
#   REGRESS=1         같은 임시 인스턴스에서 회귀(qf3a.sh 의 회귀 틀 그대로 — REGRESS_LIST 로 바꿀 수 있다)
#
# ★ 운영 DB·운영 포트를 건드리지 않는다 — 임시 DB `tbox_qf3b_<포트>_test`, 임시 인스턴스 `php -S 127.0.0.1:<포트>`(기본 8167) + 동시 넣기용 워커 넷 인스턴스(기본 8169).
#   운영 DB 는 pg_dump 로 **읽어** 임시 DB 에 붓기만 한다(파이프). 검토 계정은 claude_ 다섯(F-71). 비밀은 표준입력으로만(R-62).
#   임시 .env 원본 — 복제본에서는 mktemp 폴더(0700)의 사본을 QF2_ENV_SRC 로 넘기고 끝에 지운다. 스크린샷 폴더는 QF3B_SHOTS(기본 임시).
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
. "$ROOT/tests/lib/review_accounts.sh" || { echo "tests/lib/review_accounts.sh 를 읽지 못했다 — 시험을 시작하지 않는다"; exit 3; }
. "$ROOT/tests/lib/secrets.sh" || { echo "tests/lib/secrets.sh 를 읽지 못했다 — 시험을 시작하지 않는다 (R-62)"; exit 3; }
PORT="${QF3B_PORT:-8167}"
BPORT="${QF3B_BURST_PORT:-8169}"   # L-2 동시 넣기용 워커 넷 인스턴스
for p in "$PORT" "$BPORT"; do case "$p" in 80|8082|8081) echo "운영 포트($p)는 쓰지 않는다"; exit 3 ;; esac; done
[ "$PORT" = "$BPORT" ] && { echo "두 포트가 같다"; exit 3; }
SITE="http://127.0.0.1:$PORT"
BSITE="http://127.0.0.1:$BPORT"
BASE="$SITE/tbox"
CBASE="$SITE/community"
UA="tbox-review/$(date +%F)"
SRC_DB="tbox_re"                  # 운영 — **읽기만** 한다(pg_dump)
DB="tbox_qf3b_${PORT}_test"       # 임시 — 여기에만 쓴다
MIGB="2026-10-05_qf3b_purge_favorites.sql"
JAR_DIR="$(mktemp -d)"
WORK="$(mktemp -d)"
chmod 700 "$JAR_DIR" "$WORK"
SHOTS="${QF3B_SHOTS:-$WORK/shots}"
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
  # 워커 넷(PHP_CLI_SERVER_WORKERS)은 주인의 자식이다 — 주인만 죽이면 워커가 포트를 쥔 채 남는다. 자식 먼저, 그다음 주인, 끝으로 이 포트의 php -S 만
  for pid in "$SERVER_PID" "$BURST_PID"; do
    if [ -n "$pid" ]; then pkill -P "$pid" 2>/dev/null; kill "$pid" 2>/dev/null; wait "$pid" 2>/dev/null; fi
  done
  pkill -f "^php -S 127\.0\.0\.1:$PORT " 2>/dev/null
  pkill -f "^php -S 127\.0\.0\.1:$BPORT " 2>/dev/null
  [ -n "${QF3B_LOGCOPY:-}" ] && [ -n "$INST" ] && cp "$INST/server.log" "$QF3B_LOGCOPY/server.log" 2>/dev/null
  sudo -n -u postgres dropdb --if-exists "$DB" >/dev/null 2>&1
  [ -n "$INST" ] && rm -rf "$INST"
  rm -rf "$JAR_DIR" "$WORK"
}
trap cleanup EXIT

psqlq() { sudo -n -u postgres psql -X -d "$DB" -t -A -c "$1" 2>/dev/null; }
review_password() { sudo -n awk -v u="$1" '$1==u{print $3}' /etc/tbox-v2/seed-accounts.txt 2>/dev/null; }
review_secret()   { sudo -n awk -v u="$1" '$1==u{print $2}' /etc/tbox-v2/qa-totp.txt 2>/dev/null; }
login() {
  local who="$1" jar="$JAR_DIR/${2:-$1}.jar" pw sec raw code left out try body
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
hr "Q:F-3a 라운드 B (커뮤니티 · 화면) — 임시 인스턴스 준비 (운영 DB 에는 쓰지 않는다)"
avail_kb=$(df -Pk / | awk 'NR==2{print $4}')
echo "루트 파티션 여유: $(df -Ph / | awk 'NR==2{print $4}')"
[ "$avail_kb" -ge 1048576 ] || { echo "루트 디스크 여유 1GB 미만 — 시험을 시작하지 않는다 (SECURITY R-80)"; exit 3; }
sudo -n -u postgres psql -X -d "$SRC_DB" -tAc "SELECT 1" >/dev/null 2>&1 \
  || { echo "psql(postgres) 을 쓸 수 없다 — 이 시험은 임시 DB 가 없으면 성립하지 않는다"; exit 3; }
for p in "$PORT" "$BPORT"; do
  if ss -ltn 2>/dev/null | grep -q "127.0.0.1:$p "; then echo "포트 $p 이 이미 쓰이고 있다 — 중단"; exit 3; fi
done

sub "① 운영 DB 를 **읽어** 임시 DB 를 만든다(파이프) · 라운드 A 까지 마이그레이션(2026-10-05 는 D-2 시험에서 붓는다)"
sudo -n -u postgres dropdb --if-exists "$DB" >/dev/null 2>&1
sudo -n -u postgres createdb -O tbox_owner "$DB" >/dev/null 2>&1 || { echo "임시 DB 를 만들 수 없다"; exit 3; }
sudo -n -u postgres pg_dump -d "$SRC_DB" 2>/dev/null \
  | sudo -n -u postgres psql -X -q -d "$DB" -v ON_ERROR_STOP=0 >/dev/null 2>"$WORK/restore.err"
DUMP_PS=("${PIPESTATUS[@]}")
[ "${DUMP_PS[0]}" -eq 0 ] || { echo "운영 DB 를 읽지 못했다 (pg_dump)"; exit 3; }
[ -n "$(psqlq "SELECT count(*) FROM community.pages")" ] || { echo "임시 DB 로 옮기지 못했다 — $(head -2 "$WORK/restore.err")"; exit 3; }
for m in 2026-10-01_qf2_search.sql 2026-10-02_qf2b_files_search.sql 2026-10-03_qf3a_favorites_recent.sql; do
  sudo -n -u postgres psql -X -q -d "$DB" -v ON_ERROR_STOP=1 -f - < "$ROOT/db/migrations/$m" 2>&1 | grep -v 'could not change directory\|already exists, skipping' | sed 's/^/    /'
done
note "임시 DB $DB — 페이지 $(psqlq "SELECT count(*) FROM community.pages") · 파일 $(psqlq "SELECT count(*) FROM files")"

sub "② 저장소를 임시 폴더로 복사하고 인스턴스를 띄운다(자산도 내준다 — 화면 시험 · 한 워커 + 동시 넣기용 워커 넷)"
INST="$(mktemp -d)"
mkdir -p "$INST/app" "$INST/storage" "$INST/log"
tar -C "$ROOT" -cf - --exclude=.git --exclude=jar --exclude=api-test.txt --exclude=./.env --exclude=./.claude . | tar -C "$INST/app" -xf -
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
# L-2 동시 넣기용 **따로** 띄운 워커 넷 인스턴스(같은 사본 · 같은 임시 DB) — 화면 시험은 한 워커 인스턴스로 한다:
# 워커 넷 php -S 에서는 커뮤니티 첫 화면(부품 모듈 수십 개를 한꺼번에 받는다)이 networkidle 에 닿지 않았다(1회차 · 개발 인스턴스로 재현)
( cd "$INST/app" && PHP_CLI_SERVER_WORKERS=4 exec php -S "127.0.0.1:$BPORT" -t public router.php > "$INST/server_burst.log" 2>&1 ) &
BURST_PID=$!
READY=""
for _ in $(seq 1 20); do
  sleep 0.5
  READY=$(curl -s -o /dev/null -w '%{http_code}' -A "$UA" "$CBASE/api/me/favorites")
  [ "$READY" = "401" ] && break
done
expect "임시 인스턴스가 떴다(미로그인 즐겨찾기 창구 401)" "401" "$READY"
[ "$READY" = "401" ] || { echo "인스턴스를 띄우지 못했다 — 중단"; tail -5 "$INST/server.log"; exit 4; }
BREADY=$(curl -s -o /dev/null -w '%{http_code}' -A "$UA" "$BSITE/community/api/me/favorites")
expect "동시 넣기용 워커 넷 인스턴스도 떴다(401)" "401" "$BREADY"

sub "로그인 (claude_ 다섯 + manager01 두 번 더 — 화면의 「이어서 하기」에 쓸 새 세션 둘, 순차, F-71)"
for who in claude_employee01 claude_employee02 claude_manager01 claude_admin01 claude_guest01; do
  login "$who" || { echo "  ✖ $who 로그인 실패 — 중단"; exit 4; }
done
sleep 1.1
login claude_manager01 claude_manager01_new || { echo "  ✖ claude_manager01 두 번째 로그인 실패 — 중단"; exit 4; }
sleep 1.1
login claude_manager01 claude_manager01_new2 || { echo "  ✖ claude_manager01 세 번째 로그인 실패 — 중단"; exit 4; }
# 직원 둘의 둘째 세션 — 화면 시험이 도는 중에 쿠키를 바꿔 「다른 탭에서 같은 계정으로 다시 로그인함」을 만든다(⑱ · ⑳)
sleep 1.1
login claude_employee01 claude_employee01_b || { echo "  ✖ claude_employee01 두 번째 로그인 실패 — 중단"; exit 4; }
sleep 1.1
login claude_employee02 claude_employee02_b || { echo "  ✖ claude_employee02 두 번째 로그인 실패 — 중단"; exit 4; }
S_E1=$(session_of claude_employee01); S_E2=$(session_of claude_employee02); S_M1=$(session_of claude_manager01)
S_A1=$(session_of claude_admin01);   S_G1=$(session_of claude_guest01);   S_M1N=$(session_of claude_manager01_new)
S_M1N2=$(session_of claude_manager01_new2)
S_E1B=$(session_of claude_employee01_b); S_E2B=$(session_of claude_employee02_b)
PW_A1=$(review_password claude_admin01)
note "세션 아홉 받음(값은 출력하지 않는다) — manager01 은 옛 · 새 · 새2(만든 순서) · 직원 둘은 둘째 세션 하나씩 더"
errs_before=$(grep -ciE 'PHP (Fatal|Warning|Notice|Deprecated)|SQLSTATE|Uncaught' "$INST/server.log")

if [ "${REGRESS_ONLY:-0}" != "1" ]; then
cat > "$WORK/drv.py" <<'PY'
import json, subprocess, sys, threading, time, urllib.request, urllib.error, uuid
SITE, UA, DB, ROOT, MIGB, IDS_OUT, BSITE = sys.argv[1:8]
S = {w: sys.stdin.readline().rstrip('\n') for w in ('e1', 'e2', 'm1', 'a1', 'g1', 'm1n')}
PW_A1 = sys.stdin.readline().rstrip('\n')
P = F = 0
def ok(name, cond, detail=''):
    global P, F
    if cond: P += 1; print('  ✔ ' + name)
    else: F += 1; print('  ✖ ' + name + ('  — ' + str(detail)[:300] if detail != '' else ''))
def sql(q):
    r = subprocess.run(['sudo', '-n', '-u', 'postgres', 'psql', '-X', '-d', DB, '-t', '-A', '-c', q], capture_output=True, text=True, cwd='/')
    return r.stdout.strip()
def sql_file(path):
    with open(path, 'rb') as fh:
        r = subprocess.run(['sudo', '-n', '-u', 'postgres', 'psql', '-X', '-q', '-d', DB, '-v', 'ON_ERROR_STOP=1', '-f', '-'], stdin=fh, capture_output=True, cwd='/')
    return r.returncode, r.stderr.decode('utf-8', 'replace')
CSRF = {}
def req(who, m, path, body=None, csrf=True, site=None):
    h = {'User-Agent': UA}
    if who: h['Cookie'] = 'tbox_session=' + S[who]
    if m != 'GET' and csrf and who: h['X-CSRF-Token'] = CSRF.get(who, '')
    data = None
    if body is not None:
        data = json.dumps(body, ensure_ascii=False).encode('utf-8'); h['Content-Type'] = 'application/json'
    r = urllib.request.Request((site or SITE) + path, data=data, headers=h, method=m)
    try:
        with urllib.request.urlopen(r, timeout=90) as x:
            b = x.read(); c = x.status
    except urllib.error.HTTPError as e:
        b = e.read(); c = e.code
    try: d = json.loads(b or b'{}')
    except Exception: d = None
    return c, d, b
for w in S:
    CSRF[w] = (req(w, 'GET', '/tbox/api/me')[1] or {}).get('csrf_token', '')
C = '/community/api'
def new_page(who, title, parent=None):
    body = {'title': title}
    if parent: body['parent_id'] = parent
    c, d, _ = req(who, 'POST', C + '/pages', body)
    return c, ((d or {}).get('page') or {}).get('id', '')
def pid(pub):
    return sql("SELECT id FROM community.pages WHERE public_id = '%s'" % pub)
uid = {n: sql("SELECT id FROM users WHERE username = 'claude_%s'" % n) for n in ('employee01', 'employee02', 'manager01', 'admin01')}
for w in ('e1', 'e2', 'm1', 'a1', 'm1n'):
    req(w, 'GET', C + '/me/space')
tag = uuid.uuid4().hex[:6]

print('\n── D-2 ① 이미 영구 삭제된 페이지의 줄 — 마이그레이션 %s 이 한 번 걷는다' % MIGB)
c, gone = new_page('e2', 'qf3b 옛정리 %s' % tag)
c2, alive = new_page('e2', 'qf3b 살아있음 %s' % tag)
sql("UPDATE community.pages SET deleted_at = now() - interval '40 days', deleted_by = %s, purge_after = now() - interval '10 days', purged_at = now() - interval '9 days' WHERE public_id = '%s'" % (uid['employee02'], gone))
for u in (uid['employee02'], uid['employee01']):
    sql("INSERT INTO community.page_favorites (user_id, page_id, position) VALUES (%s, %s, 99000) ON CONFLICT DO NOTHING" % (u, pid(gone)))
    sql("INSERT INTO community.page_recent (user_id, page_id) VALUES (%s, %s) ON CONFLICT DO NOTHING" % (u, pid(gone)))
    sql("INSERT INTO community.page_favorites (user_id, page_id, position) VALUES (%s, %s, 99001) ON CONFLICT DO NOTHING" % (u, pid(alive)))
    sql("INSERT INTO community.page_recent (user_id, page_id) VALUES (%s, %s) ON CONFLICT DO NOTHING" % (u, pid(alive)))
ok('준비 — 영구 삭제된 페이지 줄 4 · 살아 있는 페이지 줄 4', (sql("SELECT (SELECT count(*) FROM community.page_favorites WHERE page_id = %s) + (SELECT count(*) FROM community.page_recent WHERE page_id = %s)" % (pid(gone), pid(gone))),
    sql("SELECT (SELECT count(*) FROM community.page_favorites WHERE page_id = %s) + (SELECT count(*) FROM community.page_recent WHERE page_id = %s)" % (pid(alive), pid(alive)))) == ('4', '4'))
rc, err = sql_file(ROOT + '/db/migrations/' + MIGB)
ok('마이그레이션 첫 번 — 오류 0', rc == 0, err[-300:])
ok('  영구 삭제된 페이지 줄 0 · 살아 있는 페이지 줄 4 그대로', (sql("SELECT (SELECT count(*) FROM community.page_favorites WHERE page_id = %s) + (SELECT count(*) FROM community.page_recent WHERE page_id = %s)" % (pid(gone), pid(gone))),
    sql("SELECT (SELECT count(*) FROM community.page_favorites WHERE page_id = %s) + (SELECT count(*) FROM community.page_recent WHERE page_id = %s)" % (pid(alive), pid(alive)))) == ('0', '4'))
rc, err = sql_file(ROOT + '/db/migrations/' + MIGB)
ok('마이그레이션 두 번째 — 오류 0(멱등)', rc == 0, err[-300:])

print('\n── D-2 ② 새 정리 함수 — 모양 · 소유자 · 권한')
src = sql("SELECT prosrc FROM pg_proc WHERE oid = 'community.purge_trash(boolean, integer)'::regprocedure")
ok('함수 몸에 즐겨찾기·최근 지우기 두 줄', 'DELETE FROM community.page_favorites fv    WHERE fv.page_id = ANY (ids);' in src and 'DELETE FROM community.page_recent rc       WHERE rc.page_id = ANY (ids);' in src, src[-200:])
old = open(ROOT + '/db/migrations/2026-09-26_community_purge.sql', encoding='utf-8').read()
old_body = old.split('AS $fn$', 1)[1].split('$fn$;', 1)[0]
new_lines = [l for l in src.split('\n') if l not in old_body.split('\n')]
ok('  7-A 몸과 다른 줄은 더한 세 줄(주석 1 + 지우기 2)뿐', len(new_lines) == 3 and all(('page_favorites' in l or 'page_recent' in l or '라운드 B' in l) for l in new_lines), new_lines)
ok('  소유자 tbox_owner', sql("SELECT proowner::regrole FROM pg_proc WHERE oid = 'community.purge_trash(boolean, integer)'::regprocedure") == 'tbox_owner')
ok('  앱 계정(tbox_app)·PUBLIC 은 부를 수 없다', sql("SELECT has_function_privilege('tbox_app', 'community.purge_trash(boolean, integer)', 'EXECUTE')") == 'f')
perm = sql("SELECT string_agg(r || ':' || has_table_privilege(r, t, 'SELECT')::text || has_table_privilege(r, t, 'INSERT')::text || has_table_privilege(r, t, 'DELETE')::text, ',') FROM unnest(ARRAY['tbox_perm','tbox_push']) r, unnest(ARRAY['public.file_favorites','public.folder_favorites','community.page_favorites','community.page_recent']) t WHERE EXISTS (SELECT 1 FROM pg_roles WHERE rolname = r)")
ok('새 표 넷에 tbox_perm · tbox_push 권한 0', perm != '' and 'true' not in perm, perm)

print('\n── D-2 ③ 30일 정리가 즐겨찾기·최근 줄을 함께 지운다')
c, Q = new_page('e2', 'qf3b 정리될 %s' % tag)
c, Q1 = new_page('e2', 'qf3b 정리될 자식 %s' % tag, Q)
c, R = new_page('e2', 'qf3b 기한전 %s' % tag)
for p in (Q, Q1, R):
    req('e2', 'PUT', C + '/pages/%s/favorite' % p)
    req('e2', 'GET', C + '/pages/' + p)
cnt = lambda p: sql("SELECT (SELECT count(*) FROM community.page_favorites WHERE page_id = %s) || '|' || (SELECT count(*) FROM community.page_recent WHERE page_id = %s)" % (pid(p), pid(p)))
ok('준비 — Q · Q1 · R 마다 즐겨찾기 1 · 최근 1', [cnt(p) for p in (Q, Q1, R)] == ['1|1'] * 3, [cnt(p) for p in (Q, Q1, R)])
c1, _, _ = req('e2', 'DELETE', C + '/pages/' + Q, {})
c2, _, _ = req('e2', 'DELETE', C + '/pages/' + R, {})
ok('Q(하위 Q1 함께)·R 휴지통으로 200', (c1, c2) == (200, 200), (c1, c2))
sql("UPDATE community.pages SET purge_after = now() - interval '1 day' WHERE public_id IN ('%s', '%s')" % (Q, Q1))   # 임시 DB — 30일 지난 흉내(R 은 기한 전 그대로)
def purge(dry):
    r = subprocess.run(['sudo', '-n', '-u', 'postgres', 'psql', '-X', '-d', DB, '-t', '-A', '-c',
                        'SET ROLE tbox_owner; SELECT count(*) FROM community.purge_trash(%s, 5000);' % ('true' if dry else 'false')],
                       capture_output=True, text=True, cwd='/')
    return r.returncode, r.stdout.strip(), r.stderr[-200:]
rc, out, err = purge(True)
ok('dry — 줄 그대로(Q · Q1 1|1)', rc == 0 and [cnt(p) for p in (Q, Q1)] == ['1|1', '1|1'], (rc, out, err, [cnt(p) for p in (Q, Q1)]))
rc, out, err = purge(False)
ok('정리 — Q · Q1 영구 삭제(purged_at)', rc == 0 and sql("SELECT count(*) FROM community.pages WHERE public_id IN ('%s', '%s') AND purged_at IS NOT NULL" % (Q, Q1)) == '2', (rc, out, err))
ok('  Q · Q1 의 즐겨찾기·최근 줄 0', [cnt(p) for p in (Q, Q1)] == ['0|0', '0|0'], [cnt(p) for p in (Q, Q1)])
ok('  기한 전 휴지통 R 의 줄은 그대로(1|1)', cnt(R) == '1|1', cnt(R))
ok('  살아 있는 페이지 줄 그대로(4)', sql("SELECT (SELECT count(*) FROM community.page_favorites WHERE page_id = %s) + (SELECT count(*) FROM community.page_recent WHERE page_id = %s)" % (pid(alive), pid(alive))) == '4')

print('\n── 준비 — employee01 사내 페이지 240장(앱 창구로 만들고 임시 DB 에서 사내로 · L-1 · L-2 재료, 약 1분)')
pages = []
for i in range(240):
    c, p = new_page('e1', 'qf3b 사내 %s %03d' % (tag, i))
    if c == 201: pages.append(p)
sql("UPDATE community.pages SET visibility = 'internal' WHERE public_id IN (%s)" % ','.join("'%s'" % p for p in pages))
ok('사내 페이지 240', len(pages) == 240 and sql("SELECT count(*) FROM community.pages WHERE public_id IN (%s) AND visibility = 'internal'" % ','.join("'%s'" % p for p in pages)) == '240', len(pages))

print('\n── L-1 「역할로 보기」 중에는 한도 걷기를 하지 않는다(관리자 자신의 줄을 지우지 않는다)')
c, _, _ = req('a1', 'POST', '/tbox/api/me/reauth', {'password': PW_A1})
ok('admin01 재인증 200', c == 200, c)
c, mine_a = new_page('a1', 'qf3b 관리자 자기 페이지 %s' % tag)
sql("DELETE FROM community.page_favorites WHERE user_id = %s" % uid['admin01'])
sql("INSERT INTO community.page_favorites (user_id, page_id, position) SELECT %s, p.id, row_number() OVER (ORDER BY p.id) FROM community.pages p WHERE p.public_id IN (%s) ORDER BY p.id LIMIT 200" % (uid['admin01'], ','.join("'%s'" % p for p in pages)))
ok('준비 — admin01 즐겨찾기 200(employee01 의 사내 페이지)', sql("SELECT count(*) FROM community.page_favorites WHERE user_id = %s" % uid['admin01']) == '200')
c, d, _ = req('a1', 'POST', '/tbox/api/me/act', {'role': 'Guest'})
ok('admin01 → 「Guest 로 보기」 200', c == 200, (c, d))
c, d, _ = req('a1', 'PUT', C + '/pages/%s/favorite' % mine_a)
ok('Guest 로 보는 중 자기 페이지 넣기 → 409(가득 — 걷지 않는다)', c == 409, (c, d))
ok('  admin01 의 200 줄 그대로', sql("SELECT count(*) FROM community.page_favorites WHERE user_id = %s" % uid['admin01']) == '200')
c, _, _ = req('a1', 'DELETE', '/tbox/api/me/act')
ok('「보기」 끝내기 200', c == 200, c)
victim = sql("SELECT p.public_id FROM community.page_favorites f JOIN community.pages p ON p.id = f.page_id WHERE f.user_id = %s ORDER BY f.position LIMIT 1" % uid['admin01'])
sql("UPDATE community.pages SET deleted_at = now(), deleted_by = %s, purge_after = now() + interval '30 days' WHERE public_id = '%s'" % (uid['employee01'], victim))
c, d, _ = req('a1', 'PUT', C + '/pages/%s/favorite' % mine_a)
ok('원래 역할로 — 하나를 휴지통에 넣으면 넣기 200(그 줄만 걷었다) · 줄 200', c == 200 and sql("SELECT count(*) FROM community.page_favorites WHERE user_id = %s" % uid['admin01']) == '200', (c, d))
ok('  걷힌 것은 휴지통 페이지 줄 하나뿐', sql("SELECT count(*) FROM community.page_favorites f JOIN community.pages p ON p.id = f.page_id WHERE f.user_id = %s AND p.public_id = '%s'" % (uid['admin01'], victim)) == '0'
   and sql("SELECT count(*) FROM community.page_favorites f JOIN community.pages p ON p.id = f.page_id WHERE f.user_id = %s AND p.public_id = '%s'" % (uid['admin01'], mine_a)) == '1')

print('\n── L-2 동시 넣기 — 한도·순서(position)가 어긋나지 않는다(워커 4)')
sql("DELETE FROM community.page_favorites WHERE user_id = %s" % uid['employee02'])
sql("INSERT INTO community.page_favorites (user_id, page_id, position) SELECT %s, p.id, row_number() OVER (ORDER BY p.id) FROM community.pages p WHERE p.public_id IN (%s) AND p.deleted_at IS NULL ORDER BY p.id LIMIT 190" % (uid['employee02'], ','.join("'%s'" % p for p in pages)))
spare = sql("SELECT string_agg(p.public_id::text, ',' ORDER BY p.id) FROM community.pages p WHERE p.public_id IN (%s) AND p.deleted_at IS NULL AND NOT EXISTS (SELECT 1 FROM community.page_favorites x WHERE x.user_id = %s AND x.page_id = p.id)" % (','.join("'%s'" % p for p in pages), uid['employee02'])).split(',')[:15]
ok('준비 — employee02 190 줄 · 새로 넣을 페이지 15', sql("SELECT count(*) FROM community.page_favorites WHERE user_id = %s" % uid['employee02']) == '190' and len(spare) == 15)
out = []
lock = threading.Lock()
gate = threading.Barrier(len(spare))
def one(p):
    gate.wait()
    c, _, _ = req('e2', 'PUT', C + '/pages/%s/favorite' % p, site=BSITE)   # 워커 넷 인스턴스 — 요청이 정말 동시에 처리된다
    with lock: out.append(c)
ts = [threading.Thread(target=one, args=(p,)) for p in spare]
for t in ts: t.start()
for t in ts: t.join()
ok('페이지 15개 동시 PUT → 200 ×10 · 409 ×5', sorted(out) == [200] * 10 + [409] * 5, sorted(out))
ok('  줄 정확히 200', sql("SELECT count(*) FROM community.page_favorites WHERE user_id = %s" % uid['employee02']) == '200')
ok('  순서(position) 겹침 0', sql("SELECT count(*) - count(DISTINCT position) FROM community.page_favorites WHERE user_id = %s" % uid['employee02']) == '0')
sql("DELETE FROM community.page_favorites WHERE user_id = %s" % uid['employee02'])   # 뒤 화면 시험(즐겨찾기 넣기)이 200 한도에 걸리지 않게 비운다

print('\n── L-3 최근 — 5분 안이면 읽기만(행 잠금을 기다리지 않는다) · 5분이 지났으면 쓰기 갈래(잠금을 기다린다)')
X = pages[230]
req('e2', 'DELETE', C + '/me/recent')
c, _, _ = req('e2', 'GET', C + '/pages/' + X)
ok('employee02 가 X 를 연다 200 · 최근 줄 1', c == 200 and sql("SELECT count(*) FROM community.page_recent WHERE user_id = %s AND page_id = %s" % (uid['employee02'], pid(X))) == '1', c)
def hold(sec):
    return subprocess.Popen(['sudo', '-n', '-u', 'postgres', 'psql', '-X', '-q', '-d', DB, '-c',
                             "BEGIN; SELECT 1 FROM community.page_recent WHERE user_id = %s AND page_id = %s FOR UPDATE; SELECT pg_sleep(%s); COMMIT;" % (uid['employee02'], pid(X), sec)],
                            stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, cwd='/')
h = hold(6); time.sleep(1.0)
t0 = time.time(); c, _, _ = req('e2', 'GET', C + '/pages/' + X); dt = time.time() - t0
ok('다른 트랜잭션이 그 줄을 잠근 동안 5분 안에 다시 열기 → 200 · 기다리지 않음(%.2f초 < 2초)' % dt, c == 200 and dt < 2.0, (c, dt))
h.wait()
sql("UPDATE community.page_recent SET last_viewed_at = now() - interval '6 minutes' WHERE user_id = %s AND page_id = %s" % (uid['employee02'], pid(X)))
h = hold(5); time.sleep(1.0)
t0 = time.time(); c, _, _ = req('e2', 'GET', C + '/pages/' + X); dt = time.time() - t0
h.wait()
ok('5분이 지난 줄이면 쓰기 갈래 — 잠금이 풀릴 때까지 기다린다(%.2f초 ≥ 3초) · 200' % dt, c == 200 and dt >= 3.0, (c, dt))
ok('  그리고 시각을 밀었다(6분 전 → 방금)', sql("SELECT last_viewed_at > now() - interval '1 minute' FROM community.page_recent WHERE user_id = %s AND page_id = %s" % (uid['employee02'], pid(X))) == 't')

print('\n── 권고 — 관리자 특권(admin_all)으로 연 페이지는 최근에 적지 않는다(목록과 같은 조건절)')
c, PRIV = new_page('e1', 'qf3b 비공개 %s' % tag)
req('a1', 'DELETE', C + '/me/recent')
c, d, _ = req('a1', 'GET', C + '/pages/' + PRIV)
ok('admin01 이 employee01 비공개 페이지를 연다 200 · granted_by admin_all', c == 200 and (d or {}).get('granted_by') == 'admin_all', (c, (d or {}).get('granted_by')))
ok('  admin01 의 최근 줄 0', sql("SELECT count(*) FROM community.page_recent WHERE user_id = %s AND page_id = %s" % (uid['admin01'], pid(PRIV))) == '0')
c, d, _ = req('a1', 'GET', C + '/pages/' + pages[231])
ok('사내 페이지를 열면 최근 줄 1', c == 200 and sql("SELECT count(*) FROM community.page_recent WHERE user_id = %s AND page_id = %s" % (uid['admin01'], pid(pages[231]))) == '1', c)
c, d, raw = req('a1', 'GET', C + '/me/recent')
ok('  최근 목록 = [사내 페이지] · 비공개 id 0', c == 200 and [p['id'] for p in (d or {}).get('pages', [])] == [pages[231]] and PRIV.encode() not in raw, raw[:200])

print('\n── QF3A-T6 커뮤니티 — 부모+자식 · 깊이 9 가 되는 항목만 거부(화면과 같은 순서로 한 건 창구)')
chain = []
parent = None
for i in range(6):
    c, p = new_page('e2', 'qf3b 깊이%d %s' % (i + 1, tag), parent)
    chain.append(p); parent = p
c, A = new_page('e2', 'qf3b A %s' % tag); c, A1 = new_page('e2', 'qf3b A1 %s' % tag, A)
c, X0 = new_page('e2', 'qf3b X %s' % tag); c, X1 = new_page('e2', 'qf3b X1 %s' % tag, X0); c, X2 = new_page('e2', 'qf3b X2 %s' % tag, X1)
c, B = new_page('e2', 'qf3b B %s' % tag)
ok('준비 — 깊이 6 사슬 · A(+A1) · X(+X1+X2) · B', all(len(x) == 36 for x in chain + [A, A1, X0, X1, X2, B]))
T6 = chain[-1]
a0 = sql("SELECT coalesce(max(id), 0) FROM audit_logs")
rA = req('e2', 'PATCH', C + '/pages/%s/move' % A, {'parent_id': T6})
rX = req('e2', 'PATCH', C + '/pages/%s/move' % X0, {'parent_id': T6})
rB = req('e2', 'PATCH', C + '/pages/%s/move' % B, {'parent_id': T6})
ok('A 200 · X 400(깊이 9) · B 200', (rA[0], rX[0], rB[0]) == (200, 400, 200), (rA[:2], rX[:2], rB[:2]))
rX2 = req('e2', 'PATCH', C + '/pages/%s/move' % X0, {'parent_id': T6})
ok('  X 거부 본문 = 한 건 옮기기 거부 본문(글자까지)', rX[2] == rX2[2] and rX2[0] == 400, (rX[2], rX2[2]))
ok('  A1 은 A 와 함께 옮겨졌다(부모 그대로 A · 깊이 8)', sql("SELECT pp.public_id FROM community.pages c JOIN community.pages pp ON pp.id = c.parent_page_id WHERE c.public_id = '%s'" % A1) == A)
ok('  X 는 제자리(뿌리)', sql("SELECT parent_page_id IS NULL FROM community.pages WHERE public_id = '%s'" % X0) == 't')
rows = sql("SELECT string_agg(target_id || ':' || result, ',' ORDER BY id) FROM audit_logs WHERE id > %s AND action = 'page.move' AND actor_name = 'claude_employee02' AND target_id IN (%s, %s, %s)" % (a0, pid(A), pid(X0), pid(B)))
ok('  감사 page.move 3행(보낸 셋 — A allow · X deny · B allow)', rows.startswith('%s:allow,%s:deny,%s:allow' % (pid(A), pid(X0), pid(B))), rows)

print('\n── 화면 시험 준비 — 두 앱 재료(파일 · 페이지 id 를 넘긴다)')
def upload(who, name, level='internal'):
    b = uuid.uuid4().hex
    parts = [('--%s\r\nContent-Disposition: form-data; name="%s"\r\n\r\n%s\r\n' % (b, k, v)).encode() for k, v in (('access_level', level), ('description', 'qf3b 화면'))]
    parts.append(('--%s\r\nContent-Disposition: form-data; name="file"; filename="%s"\r\nContent-Type: text/plain\r\n\r\n' % (b, name)).encode() + b'qf3b\n\r\n')
    parts.append(('--%s--\r\n' % b).encode())
    h = {'User-Agent': UA, 'Cookie': 'tbox_session=' + S[who], 'X-CSRF-Token': CSRF[who], 'Content-Type': 'multipart/form-data; boundary=' + b}
    try:
        with urllib.request.urlopen(urllib.request.Request(SITE + '/tbox/api/files', data=b''.join(parts), headers=h, method='POST'), timeout=60) as x:
            d = json.loads(x.read())
    except urllib.error.HTTPError:
        d = {}
    return ((d or {}).get('file') or d or {}).get('id', '')
ids = {
    'e1_files': [upload('e1', 'qf3b-화면-내것-%s-%d.txt' % (tag, i)) for i in range(6)],
    'e2_files': [upload('e2', 'qf3b-화면-남의것-%s-%d.txt' % (tag, i)) for i in range(2)],
    'm1_files': [upload('m1', 'qf3b-화면-끊김-%s-%d.txt' % (tag, i)) for i in range(3)],
    'tag': tag,
}
allf = ids['e1_files'] + ids['e2_files'] + ids['m1_files']
sql("UPDATE files SET scan_status = 'clean' WHERE public_id IN (%s)" % ','.join("'%s'" % x for x in allf))
# 100개 한도 재료 — employee01 이 볼 수 있는 사내 파일 행 105개를 한 폴더에(임시 DB 에서만 · 이름 표식)
c, d, _ = req('e1', 'POST', '/tbox/api/folders', {'name': 'qf3b-한도폴더-' + tag, 'access_level': 'internal'})
lim = ((d or {}).get('folder') or {}).get('id')
c, d, _ = req('e1', 'POST', '/tbox/api/folders', {'name': 'qf3b-받는폴더-' + tag, 'access_level': 'internal'})
dest = ((d or {}).get('folder') or {}).get('id')
sql("INSERT INTO files (original_name, stored_name, ext, mime, size_bytes, sha256, description, access_level, uploaded_by, folder_id, scan_status) SELECT 'qf3b-한도-%s-' || lpad(g::text, 3, '0') || '.txt', 'qf3b-lim-%s-' || g || '.txt', 'txt', 'text/plain', 10, repeat('0', 64), '', 'internal', %s, %s, 'clean' FROM generate_series(1, 105) g" % (tag, tag, uid['employee01'], lim))
ids['limit_folder'] = lim
ids['dest_folder'] = dest
ok('화면 재료 — 파일 11 · 한도 폴더(105) · 받는 폴더', all(len(x) == 36 for x in allf) and isinstance(lim, int) and isinstance(dest, int), (allf, lim, dest))
json.dump(ids, open(IDS_OUT, 'w'))

print('PY_PASS=%d PY_FAIL=%d' % (P, F))
PY

hr "D-2 · L-1 · L-2 · L-3 · 권고 · QF3A-T6 (파이썬 몸통)"
py_secret S_E1 S_E2 S_M1 S_A1 S_G1 S_M1N PW_A1 -- python3 "$WORK/drv.py" "$SITE" "$UA" "$DB" "$ROOT" "$MIGB" "$WORK/ids.json" "$BSITE" > "$WORK/drv.out" 2>&1
cat "$WORK/drv.out"
pp=$(sed -n 's/^PY_PASS=\([0-9]*\) PY_FAIL=\([0-9]*\)$/\1/p' "$WORK/drv.out"); pf=$(sed -n 's/^PY_PASS=\([0-9]*\) PY_FAIL=\([0-9]*\)$/\2/p' "$WORK/drv.out")
if [[ "$pp" =~ ^[0-9]+$ ]] && [[ "$pf" =~ ^[0-9]+$ ]]; then PASS=$((PASS + pp)); FAIL=$((FAIL + pf)); else echo "  ✖ 파이썬 몸통이 끝줄을 못 찍었다"; FAIL=$((FAIL+1)); fi
[ "${pp:-0}" -ge 40 ] 2>/dev/null && expect "몸통이 헛돌지 않았다(통과 40 이상)" "yes" "yes" || expect "몸통이 헛돌지 않았다(통과 40 이상)" "yes" "no(${pp:-?})"

hr "QF3A-T9 · T7 화면 몫 — 헤드리스(두 앱) — tests/browser/qf3a_bulk.py"
note "스크린샷 폴더: $SHOTS (사람이 직접 연다)"
py_secret S_E1 S_E2 S_M1 S_M1N S_M1N2 S_E1B S_E2B -- env PATH="$HOME/.local/bin:$PATH" python3 "$ROOT/tests/browser/qf3a_bulk.py" "$SITE" "$UA" "$SHOTS" "$WORK/ids.json" "$DB" > "$WORK/browser.out" 2>&1
rc=$?
cat "$WORK/browser.out"
expect "화면 시험 종료 코드" "0" "$rc"
bf=$(sed -n 's/^SMOKE_FAIL=\([0-9]*\)$/\1/p' "$WORK/browser.out")
expect "화면 시험 실패 수" "0" "${bf:-?}"
bp=$(grep -c '^  ✔' "$WORK/browser.out")
[ "$bp" -ge 60 ] && expect "화면 시험이 헛돌지 않았다(✔ 60 이상)" "yes" "yes" || expect "화면 시험이 헛돌지 않았다(✔ 60 이상)" "yes" "no($bp)"

hr "서버 로그 · 정적"
errs_after=$(grep -ciE 'PHP (Fatal|Warning|Notice|Deprecated)|SQLSTATE|Uncaught' "$INST/server.log")
expect "서버 로그의 새 PHP 오류·SQLSTATE 줄" "0" "$((errs_after - errs_before))"
[ "$((errs_after - errs_before))" = "0" ] || grep -iE 'PHP (Fatal|Warning|Notice|Deprecated)|SQLSTATE|Uncaught' "$INST/server.log" | tail -5 | sed 's/^/    /'
( cd "$ROOT" && php scripts/community_asset_version.php --check > "$WORK/assetv.txt" 2>&1 ); rc=$?
expect "커뮤니티 부품 버전(import ?v=) 이 지금 내용과 맞다" "0" "$rc"
[ "$rc" = "0" ] || tail -5 "$WORK/assetv.txt" | sed 's/^/    /'
fi   # REGRESS_ONLY

# ════════════════════════════════════════════════════════════════
if [ "${REGRESS:-0}" = "1" ] || [ "${REGRESS_ONLY:-0}" = "1" ]; then
  hr "회귀 — 같은 임시 인스턴스에서(qf3a.sh 의 회귀 틀 그대로)"
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
note "임시 DB($DB)·임시 폴더·임시 서버는 이 스크립트가 끝나며 지워진다. 운영에는 아무것도 남지 않는다(스크린샷 폴더는 QF3B_SHOTS 를 주면 남는다)."
[ "$FAIL" -eq 0 ] || exit 1
exit 0
