#!/usr/bin/env bash
# tests/qf3b_tbox.sh — 개발 대기열 6 · Q:F-3a 라운드 B 수락 시험, t-box 쪽 (지시서 /var/www/qaredi/docs/qf3a-favorites-recent-bulk-directive.md
#                      1.3 일괄 · 3장 QF3A-T6 · T7 · 4장 M-4 · M-7 + 라운드 A 독립 검토 L-1 · L-2)
#
#   QF3A-T6 섞인 선택   claude_employee01 이 내 파일 3 + 남의(employee02) 파일 2 를 **화면과 같은 순서로 한 건 창구에** 차례로 보낸다
#                       (`DELETE /api/files/{id}` × 5) → 200 ×3 · 403 ×2 · 거부 본문이 **한 건 삭제의 거부 본문과 글자까지 같다** ·
#                       감사 file.delete 5행(각 파일 id 로 allow 3 · deny 2). 옮기기(PATCH folder_id)·등급(PATCH access_level)·즐겨찾기(PUT)도
#                       항목마다 한 건과 같은 답 · 되돌리기(POST …/restore — U-2b 본인 복구) 200 ×3
#   QF3A-T7 끊김        claude_manager01 의 **옛 세션**으로 셋 중 첫째를 지운 뒤 그 세션이 무활동 만료(아래 ★) → 둘째 요청 401 · 둘째 파일은
#                       그대로(지워지지 않음) → **새 세션**(같은 계정 · 다시 로그인)으로 둘째·셋째 200 · 새 세션의 /api/me 아이디 = 옛 세션 아이디 ·
#                       서버에 새 일괄 창구 0 — `public/index.php` 기준 판(a0d5503) 대비 diff 0줄 · 커뮤니티 라우트 표에 더한 줄 0
#                       ★ 만료는 SESSION_IDLE_MIN 을 낮추는 대신 **그 세션의 last_seen_at 을 과거로 민다**(임시 DB) — 같은 무활동 만료 갈래다.
#                         이 인스턴스는 다른 계정 세션도 함께 쓰고, P-3(60초에 한 번 갱신)과 1분 문턱이 겹치면 결과가 흔들린다(구현 기록 11장).
#   L-1 역할로 보기     claude_admin01 이 「Guest 로 보기」 중 파일 즐겨찾기 200 줄(사내 파일 — Guest 로는 안 보임)에서 공개 파일을 넣으면 409 이고
#                       **관리자 자신의 200 줄이 그대로**(걷지 않는다) · 폴더 50 줄도 같음 · 보기를 끝내면 전처럼 안 보이는 줄을 걷고 200
#   L-2 동시 넣기       employee02 파일 즐겨찾기 195 줄에서 서로 다른 파일 12개를 **동시에** PUT(따로 띄운 워커 넷 인스턴스) → 성공 5 · 409 7 · 줄 정확히 200 ·
#                       폴더 46 줄에서 8개 동시 → 성공 4 · 줄 50
#   B-T0 정적           바뀐 PHP php -l · 보안 린트 0 · 토큰 창구 목록 불변 · 서버 로그 새 PHP 오류·SQLSTATE 0
#
# ★ 운영 DB·운영 포트를 건드리지 않는다 — 임시 DB `tbox_qf3b_<포트>_test`, 임시 인스턴스 `php -S 127.0.0.1:<포트>`(기본 8166) + 동시 넣기용 워커 넷 인스턴스(기본 8168).
#   운영 DB 는 pg_dump 로 **읽어** 임시 DB 에 붓기만 한다(파이프). 검토 계정은 claude_ 다섯(F-71). 비밀은 표준입력으로만(R-62).
#   임시 .env 원본 — 복제본에서는 mktemp 폴더(0700)의 사본을 QF2_ENV_SRC 로 넘기고 끝에 지운다.
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
. "$ROOT/tests/lib/review_accounts.sh" || { echo "tests/lib/review_accounts.sh 를 읽지 못했다 — 시험을 시작하지 않는다"; exit 3; }
. "$ROOT/tests/lib/secrets.sh" || { echo "tests/lib/secrets.sh 를 읽지 못했다 — 시험을 시작하지 않는다 (R-62)"; exit 3; }
PORT="${QF3B_PORT:-8166}"
BPORT="${QF3B_BURST_PORT:-8168}"   # L-2 동시 넣기용 워커 넷 인스턴스(같은 사본 · 같은 임시 DB)
for p in "$PORT" "$BPORT"; do case "$p" in 80|8082|8081) echo "운영 포트($p)는 쓰지 않는다"; exit 3 ;; esac; done
[ "$PORT" = "$BPORT" ] && { echo "두 포트가 같다"; exit 3; }
SITE="http://127.0.0.1:$PORT"
BSITE="http://127.0.0.1:$BPORT"
BASE="$SITE/tbox"
UA="tbox-review/$(date +%F)"
SRC_DB="tbox_re"                   # 운영 — **읽기만** 한다(pg_dump)
DB="tbox_qf3b_${PORT}_test"        # 임시 — 여기에만 쓴다
BASE_REV="${QF3B_BASE_REV:-a0d5503}"   # 라운드 B 를 시작한 운영 판(새 창구 0 을 견줄 기준)
JAR_DIR="$(mktemp -d)"
WORK="$(mktemp -d)"
chmod 700 "$JAR_DIR" "$WORK"
INST=""
SERVER_PID=""
BURST_PID=""
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
  # 워커 넷(PHP_CLI_SERVER_WORKERS)은 주인의 자식이다 — 주인만 죽이면 워커가 포트를 쥔 채 남는다(1회차에서 겪음). 자식 먼저, 그다음 주인,
  # 끝으로 이 포트의 php -S 만(앞에 고정 — 다른 포트·운영 서버는 맞지 않는다)
  for pid in "$SERVER_PID" "$BURST_PID"; do
    if [ -n "$pid" ]; then pkill -P "$pid" 2>/dev/null; kill "$pid" 2>/dev/null; wait "$pid" 2>/dev/null; fi
  done
  pkill -f "^php -S 127\.0\.0\.1:$PORT " 2>/dev/null
  pkill -f "^php -S 127\.0\.0\.1:$BPORT " 2>/dev/null
  sudo -n -u postgres dropdb --if-exists "$DB" >/dev/null 2>&1
  [ -n "$INST" ] && rm -rf "$INST"
  rm -rf "$JAR_DIR" "$WORK"
}
trap cleanup EXIT

psqlq() { sudo -n -u postgres psql -X -d "$DB" -t -A -c "$1" 2>/dev/null; }
review_password() { sudo -n awk -v u="$1" '$1==u{print $3}' /etc/tbox-v2/seed-accounts.txt 2>/dev/null; }
review_secret()   { sudo -n awk -v u="$1" '$1==u{print $2}' /etc/tbox-v2/qa-totp.txt 2>/dev/null; }
# $1 계정 $2 쿠키 병 이름(같은 계정을 두 번 로그인할 때 다른 병)
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
hr "Q:F-3a 라운드 B (t-box) — 임시 인스턴스 준비 (운영 DB 에는 쓰지 않는다)"
avail_kb=$(df -Pk / | awk 'NR==2{print $4}')
echo "루트 파티션 여유: $(df -Ph / | awk 'NR==2{print $4}')"
[ "$avail_kb" -ge 1048576 ] || { echo "루트 디스크 여유 1GB 미만 — 시험을 시작하지 않는다 (SECURITY R-80)"; exit 3; }
sudo -n -u postgres psql -X -d "$SRC_DB" -tAc "SELECT 1" >/dev/null 2>&1 \
  || { echo "psql(postgres) 을 쓸 수 없다 — 이 시험은 임시 DB 가 없으면 성립하지 않는다"; exit 3; }
for p in "$PORT" "$BPORT"; do
  if ss -ltn 2>/dev/null | grep -q "127.0.0.1:$p "; then echo "포트 $p 이 이미 쓰이고 있다 — 중단"; exit 3; fi
done

sub "① 운영 DB 를 **읽어** 임시 DB 를 만든다(파이프) + 마이그레이션(있으면 그대로)"
sudo -n -u postgres dropdb --if-exists "$DB" >/dev/null 2>&1
sudo -n -u postgres createdb -O tbox_owner "$DB" >/dev/null 2>&1 || { echo "임시 DB 를 만들 수 없다"; exit 3; }
sudo -n -u postgres pg_dump -d "$SRC_DB" 2>/dev/null \
  | sudo -n -u postgres psql -X -q -d "$DB" -v ON_ERROR_STOP=0 >/dev/null 2>"$WORK/restore.err"
DUMP_PS=("${PIPESTATUS[@]}")
[ "${DUMP_PS[0]}" -eq 0 ] || { echo "운영 DB 를 읽지 못했다 (pg_dump)"; exit 3; }
[ -n "$(psqlq "SELECT count(*) FROM files")" ] || { echo "임시 DB 로 옮기지 못했다 — $(head -2 "$WORK/restore.err")"; exit 3; }
for m in 2026-10-01_qf2_search.sql 2026-10-02_qf2b_files_search.sql 2026-10-03_qf3a_favorites_recent.sql 2026-10-05_qf3b_purge_favorites.sql; do
  sudo -n -u postgres psql -X -q -d "$DB" -v ON_ERROR_STOP=1 -f - < "$ROOT/db/migrations/$m" 2>&1 | grep -v 'could not change directory\|already exists, skipping' | sed 's/^/    /'
done
note "임시 DB $DB — 파일 $(psqlq "SELECT count(*) FROM files") · 폴더 $(psqlq "SELECT count(*) FROM folders")"

sub "② 저장소를 임시 폴더로 복사(임시 .env — DB 이름·저장·감사 경로만 바꾼다, 값은 출력하지 않는다) · 워커 4"
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
# L-2 동시 넣기를 재려면 요청이 **동시에** 처리돼야 한다 — php -S 는 기본 한 워커라 **따로** 워커 넷 인스턴스를 띄운다(리눅스 · PHP 7.4+).
# 나머지 시험은 한 워커 인스턴스로 — 워커 넷 php -S 는 커뮤니티 첫 화면에서 networkidle 에 닿지 않는 일이 있었다(tests/community/qf3b.sh 1회차).
( cd "$INST/app" && PHP_CLI_SERVER_WORKERS=4 exec php -S "127.0.0.1:$BPORT" -t public router.php > "$INST/server_burst.log" 2>&1 ) &
BURST_PID=$!
READY=""
for _ in $(seq 1 20); do
  sleep 0.5
  READY=$(curl -s -o /dev/null -w '%{http_code}' -A "$UA" "$BASE/api/files/favorites")
  [ "$READY" = "401" ] && break
done
expect "임시 인스턴스가 떴다(미로그인 즐겨찾기 창구 401)" "401" "$READY"
[ "$READY" = "401" ] || { echo "인스턴스를 띄우지 못했다 — 중단"; tail -5 "$INST/server.log"; exit 4; }
expect "동시 넣기용 워커 넷 인스턴스도 떴다(401)" "401" "$(curl -s -o /dev/null -w '%{http_code}' -A "$UA" "$BSITE/tbox/api/files/favorites")"

sub "로그인 (claude_ 검토 계정 다섯 + manager01 한 번 더 — 새 세션, 순차, F-71)"
for who in claude_employee01 claude_employee02 claude_manager01 claude_admin01 claude_guest01; do
  login "$who" || { echo "  ✖ $who 로그인 실패 — 중단"; exit 4; }
done
sleep 1.1   # 두 세션의 created_at 이 갈리게
login claude_manager01 claude_manager01_new || { echo "  ✖ claude_manager01 두 번째 로그인 실패 — 중단"; exit 4; }
S_E1=$(session_of claude_employee01); S_E2=$(session_of claude_employee02); S_M1=$(session_of claude_manager01)
S_A1=$(session_of claude_admin01);   S_G1=$(session_of claude_guest01);   S_M1N=$(session_of claude_manager01_new)
PW_A1=$(review_password claude_admin01)
note "세션 여섯 받음(값은 출력하지 않는다)"
errs_before=$(grep -ciE 'PHP (Fatal|Warning|Notice|Deprecated)|SQLSTATE|Uncaught' "$INST/server.log")

# ─── 시험 몸통(파이썬) — 세션 여섯 + 관리자 비밀번호(재인증)는 표준입력 한 줄씩(R-62). 임시 DB 는 sudo psql 로만 본다. ─────
cat > "$WORK/drv.py" <<'PY'
import json, subprocess, sys, threading, time, urllib.request, urllib.error, uuid
SITE, UA, DB, BSITE = sys.argv[1], sys.argv[2], sys.argv[3], sys.argv[4]
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
CSRF = {}
def req(who, m, path, body=None, raw=None, ctype=None, csrf=True, site=None):
    h = {'User-Agent': UA}
    if who: h['Cookie'] = 'tbox_session=' + S[who]
    if m != 'GET' and csrf and who: h['X-CSRF-Token'] = CSRF.get(who, '')
    data = raw
    if body is not None:
        data = json.dumps(body, ensure_ascii=False).encode('utf-8'); h['Content-Type'] = 'application/json'
    if ctype: h['Content-Type'] = ctype
    r = urllib.request.Request((site or SITE) + path, data=data, headers=h, method=m)
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
def upload(who, name, level='internal', team=None):
    b = uuid.uuid4().hex; parts = []
    fields = [('access_level', level), ('description', 'qf3b 시험 파일')]
    if team is not None: fields.append(('team_id', str(team)))
    for k, v in fields:
        parts.append(('--%s\r\nContent-Disposition: form-data; name="%s"\r\n\r\n%s\r\n' % (b, k, v)).encode())
    parts.append(('--%s\r\nContent-Disposition: form-data; name="file"; filename="%s"\r\nContent-Type: text/plain\r\n\r\n' % (b, name)).encode() + ('본문 ' + name + '\n').encode() + b'\r\n')
    parts.append(('--%s--\r\n' % b).encode())
    c, d, raw = req(who, 'POST', '/tbox/api/files', raw=b''.join(parts), ctype='multipart/form-data; boundary=' + b)
    return c, ((d or {}).get('file') or d or {}).get('id', '')
def audit_max():
    return sql("SELECT coalesce(max(id), 0) FROM audit_logs")
def fid(pub):
    return sql("SELECT id FROM files WHERE public_id = '%s'" % pub)
uid = {n: sql("SELECT id FROM users WHERE username = 'claude_%s'" % n) for n in ('employee01', 'employee02', 'manager01', 'admin01')}
tag = uuid.uuid4().hex[:6]

print('\n── 준비 — employee01 파일 넷 · employee02 파일 셋(앱 창구 · 이름 표식 %s)' % tag)
mine = [upload('e1', 'qf3b-내것-%s-%d.txt' % (tag, i))[1] for i in range(4)]
theirs = [upload('e2', 'qf3b-남의것-%s-%d.txt' % (tag, i))[1] for i in range(3)]
ok('파일 일곱 올리기', all(len(x) == 36 for x in mine + theirs), mine + theirs)
sql("UPDATE files SET scan_status = 'clean' WHERE public_id IN (%s)" % ','.join("'%s'" % x for x in mine + theirs))

print('\n── QF3A-T6 섞인 선택 — 내 파일 3 + 남의 파일 2 를 화면과 같은 순서로 한 건 창구에(DELETE × 5)')
c_single, _, raw_single = req('e1', 'DELETE', '/tbox/api/files/' + theirs[2])
ok('기준 — 남의 파일 한 건 삭제 403', c_single == 403, (c_single, raw_single[:120]))
sel = mine[:3] + theirs[:2]
a0 = audit_max()
res = [req('e1', 'DELETE', '/tbox/api/files/' + x) for x in sel]
codes = [r[0] for r in res]
ok('상태 = 200 ×3 · 403 ×2 (고른 순서 그대로)', codes == [200, 200, 200, 403, 403], codes)
ok('  거부 본문 둘 다 **한 건 삭제의 거부 본문과 글자까지 같다**', res[3][2] == raw_single and res[4][2] == raw_single, (res[3][2], raw_single))
ok('  거부 본문에 파일 이름 0', all(('qf3b-남의것-%s' % tag).encode() not in r[2] for r in res[3:]))
ids_sql = ','.join(fid(x) for x in sel)
rows = sql("SELECT string_agg(target_id || ':' || result, ',' ORDER BY id) FROM audit_logs WHERE id > %s AND action = 'file.delete' AND actor_name = 'claude_employee01' AND target_id IN (%s)" % (a0, ids_sql))
exp = ','.join('%s:%s' % (fid(x), 'allow' if i < 3 else 'deny') for i, x in enumerate(sel))
ok('  감사 file.delete 5행 — 파일마다 한 줄(allow 3 · deny 2, 보낸 순서)', rows == exp, (rows, exp))
ok('  휴지통 상태 = 내 셋만 지워짐', sql("SELECT count(*) FROM files WHERE public_id IN (%s) AND deleted_at IS NOT NULL" % ','.join("'%s'" % x for x in sel)) == '3')
print('\n── 되돌리기(M-7 — U-2b 본인 복구 창구를 파일마다)')
res = [req('e1', 'POST', '/tbox/api/files/%s/restore' % x, {}) for x in mine[:3]]
ok('되살리기 200 ×3', [r[0] for r in res] == [200, 200, 200], [r[0] for r in res])
ok('  셋 다 살아 있다', sql("SELECT count(*) FROM files WHERE public_id IN (%s) AND deleted_at IS NULL" % ','.join("'%s'" % x for x in mine[:3])) == '3')

print('\n── 옮기기 · 등급 · 즐겨찾기 — 항목마다 한 건과 같은 답')
c, d, _ = req('e1', 'POST', '/tbox/api/folders', {'name': 'qf3b-받는폴더-' + tag, 'access_level': 'internal'})
dest = ((d or {}).get('folder') or {}).get('id')
ok('받는 폴더 만들기 201', c == 201 and isinstance(dest, int), (c, d))
res = [req('e1', 'PATCH', '/tbox/api/files/' + x, {'folder_id': dest}) for x in (mine[0], mine[1], theirs[0])]
ok('폴더로 옮기기 — 내 둘 200 · 남의 하나 403', [r[0] for r in res] == [200, 200, 403], [r[0] for r in res])
c1, _, raw1 = req('e1', 'PATCH', '/tbox/api/files/' + theirs[1], {'folder_id': dest})
ok('  남의 파일 거부 본문 = 한 건 옮기기 거부 본문', res[2][2] == raw1, (res[2][2], raw1))
# 등급은 좁히는 쪽만(사내 → 팀) — 넓히는 변경(→ 공개)은 Admin 만이다(PRD 5.3 · Authz::decideLevelChange). 팀은 employee01 의 팀.
team_e1 = sql("SELECT team_id FROM users WHERE username = 'claude_employee01'")
res = [req('e1', 'PATCH', '/tbox/api/files/' + x, {'access_level': 'team', 'team_id': team_e1}) for x in (mine[0], mine[1])]
ok('등급·팀 바꾸기(사내 → 팀 %s) 200 ×2' % team_e1, [r[0] for r in res] == [200, 200], [r[0] for r in res])
res2 = [req('e1', 'PATCH', '/tbox/api/files/' + x, {'access_level': 'team', 'team_id': team_e1}) for x in (mine[0],)]
ok('  같은 등급을 다시 → 400 「바뀐 내용이 없습니다」(한 건과 같은 문장)', res2[0][0] == 400 and '바뀐 내용이 없습니다' in ((res2[0][1] or {}).get('error') or ''), res2[0][:2])
c_w, d_w, raw_w = req('e1', 'PATCH', '/tbox/api/files/' + mine[2], {'access_level': 'public'})
c_w2, _, raw_w2 = req('e1', 'PATCH', '/tbox/api/files/' + mine[3], {'access_level': 'public'})
ok('  넓히는 변경(→ 공개)은 직원에게 403 — 항목마다 같은 본문', (c_w, c_w2) == (403, 403) and raw_w == raw_w2, (c_w, c_w2, raw_w, raw_w2))
res = [req('e1', 'PUT', '/tbox/api/files/%s/favorite' % x) for x in mine[:3] + theirs[:2]]
ok('즐겨찾기 넣기 200 ×5(볼 수 있는 파일은 남의 것도)', [r[0] for r in res] == [200] * 5, [r[0] for r in res])

print('\n── QF3A-T7 끊김 — 옛 세션 만료 → 401 · 그 파일 그대로 → 새 세션으로 이어서')
m = [upload('m1', 'qf3b-끊김-%s-%d.txt' % (tag, i))[1] for i in range(3)]
ok('manager01 파일 셋 올리기(옛 세션)', all(len(x) == 36 for x in m), m)
c0, _, _ = req('m1', 'DELETE', '/tbox/api/files/' + m[0])
ok('첫째 지우기 200(옛 세션)', c0 == 200, c0)
# 옛 세션만 무활동 만료 — 가장 새 세션(방금 다시 로그인한 것)보다 먼저 만든 manager01 세션의 last_seen_at 을 3시간 전으로(임시 DB)
sql("UPDATE sessions SET last_seen_at = now() - interval '3 hours' WHERE user_id = %s AND created_at < (SELECT max(created_at) FROM sessions WHERE user_id = %s)" % (uid['manager01'], uid['manager01']))
c1, d1, _ = req('m1', 'DELETE', '/tbox/api/files/' + m[1])
ok('둘째 — 옛 세션 401', c1 == 401, (c1, d1))
ok('  둘째 파일은 지워지지 않았다(서버가 핸들러 전에 막았다)', sql("SELECT deleted_at IS NULL FROM files WHERE public_id = '%s'" % m[1]) == 't')
c, me_new, _ = req('m1n', 'GET', '/tbox/api/me')
ok('새 세션 /api/me 200 · 아이디 = claude_manager01(같은 계정 — 화면이 이것으로 이어도 되는지 본다)', c == 200 and (me_new or {}).get('username') == 'claude_manager01', (c, (me_new or {}).get('username')))
ok('  새 세션의 CSRF 토큰은 옛 것과 다르다(화면이 새 토큰으로 바꿔 보낸다)', (me_new or {}).get('csrf_token') not in ('', None, CSRF['m1']))
CSRF['m1n'] = (me_new or {}).get('csrf_token', '')
c2, _, _ = req('m1n', 'DELETE', '/tbox/api/files/' + m[1])
c3, _, _ = req('m1n', 'DELETE', '/tbox/api/files/' + m[2])
ok('새 세션으로 둘째·셋째 200 — 남은 목록을 이어서', (c2, c3) == (200, 200), (c2, c3))
cx, _, _ = req('m1n', 'DELETE', '/tbox/api/files/' + m[0])
ok('  이미 지운 첫째를 다시 보내면 404(두 번 처리되지 않는다)', cx == 404, cx)
c_old_csrf, _, _ = req('m1n', 'PUT', '/tbox/api/files/%s/favorite' % mine[3], csrf=False)
ok('  새 세션에 CSRF 없이 → 403(옛 토큰을 그대로 쓰면 막힌다는 뜻 — 화면은 새 토큰으로 바꾼다)', c_old_csrf == 403, c_old_csrf)

print('\n── L-1 「역할로 보기」 중에는 한도 걷기를 하지 않는다(관리자 자신의 줄을 지우지 않는다)')
c, _, _ = req('a1', 'POST', '/tbox/api/me/reauth', {'password': PW_A1})
ok('admin01 재인증 200', c == 200, c)
sql("DELETE FROM file_favorites WHERE user_id = %s" % uid['admin01'])
sql("DELETE FROM folder_favorites WHERE user_id = %s" % uid['admin01'])
sql("INSERT INTO files (original_name, stored_name, ext, mime, size_bytes, sha256, description, access_level, uploaded_by) SELECT 'qf3b-L1-%s-' || g || '.txt', 'qf3b-l1-%s-' || g || '.txt', 'txt', 'text/plain', 10, repeat('0', 64), '', 'internal', %s FROM generate_series(1, 200) g" % (tag, tag, uid['employee01']))
sql("INSERT INTO file_favorites (user_id, file_id) SELECT %s, f.id FROM files f WHERE f.stored_name LIKE 'qf3b-l1-%s-%%'" % (uid['admin01'], tag))
sql("INSERT INTO folders (name, depth, access_level, created_by) SELECT 'qf3b-L1-%s-' || g, 1, 'internal', %s FROM generate_series(1, 50) g" % (tag, uid['employee01']))
sql("INSERT INTO folder_favorites (user_id, folder_id) SELECT %s, d.id FROM folders d WHERE d.name LIKE 'qf3b-L1-%s-%%'" % (uid['admin01'], tag))
ok('준비 — admin01 파일 즐겨찾기 200(사내 파일) · 폴더 50(사내 폴더)', (sql("SELECT count(*) FROM file_favorites WHERE user_id = %s" % uid['admin01']), sql("SELECT count(*) FROM folder_favorites WHERE user_id = %s" % uid['admin01'])) == ('200', '50'))
c_pub, pub = upload('e1', 'qf3b-공개-%s.txt' % tag, 'public')
sql("UPDATE files SET scan_status = 'clean' WHERE public_id = '%s'" % pub)
c, d, _ = req('e1', 'POST', '/tbox/api/folders', {'name': 'qf3b-공개폴더-' + tag, 'access_level': 'public'})
pubdir = ((d or {}).get('folder') or {}).get('id')
if not isinstance(pubdir, int):   # 직원이 공개 폴더를 못 만드는 판이면 임시 DB 의 공개 폴더 하나(사전 자료)
    pubdir = sql("SELECT id FROM folders WHERE access_level = 'public' ORDER BY id LIMIT 1")
ok('준비 — Guest 도 보는 공개 파일·공개 폴더', c_pub == 201 and str(pubdir) != '', (c_pub, pubdir))
c, d, _ = req('a1', 'POST', '/tbox/api/me/act', {'role': 'Guest'})
ok('admin01 → 「Guest 로 보기」 시작 200', c == 200, (c, d))
c, d, _ = req('a1', 'GET', '/tbox/api/me')
ok('  /api/me acting = true', c == 200 and (d or {}).get('acting') is True, (d or {}).get('acting'))
c, d, _ = req('a1', 'PUT', '/tbox/api/files/%s/favorite' % pub)
ok('Guest 로 보는 중 공개 파일 넣기 → 409(가득 — 걷지 않는다)', c == 409, (c, d))
ok('  admin01 의 200 줄이 그대로(사내 파일 줄 하나도 안 지워짐)', sql("SELECT count(*) FROM file_favorites ff JOIN files f ON f.id = ff.file_id WHERE ff.user_id = %s AND f.stored_name LIKE 'qf3b-l1-%s-%%'" % (uid['admin01'], tag)) == '200')
c, d, _ = req('a1', 'PUT', '/tbox/api/folders/%s/favorite' % pubdir)
ok('Guest 로 보는 중 공개 폴더 넣기 → 409 · 폴더 50 줄 그대로', c == 409 and sql("SELECT count(*) FROM folder_favorites WHERE user_id = %s" % uid['admin01']) == '50', (c, d))
c, _, _ = req('a1', 'DELETE', '/tbox/api/me/act')
ok('「보기」 끝내기 200', c == 200, c)
sql("UPDATE files SET deleted_at = now(), deleted_by = %s WHERE id = (SELECT min(f.id) FROM files f WHERE f.stored_name LIKE 'qf3b-l1-%s-%%')" % (uid['employee01'], tag))
c, d, _ = req('a1', 'PUT', '/tbox/api/files/%s/favorite' % pub)
ok('원래 역할로 — 하나를 휴지통에 넣으면 넣기 200(그 줄만 걷었다) · 줄 200', c == 200 and sql("SELECT count(*) FROM file_favorites WHERE user_id = %s" % uid['admin01']) == '200', (c, d))
ok('  걷힌 것은 휴지통 파일 줄 하나뿐', sql("SELECT count(*) FROM file_favorites ff JOIN files f ON f.id = ff.file_id WHERE ff.user_id = %s AND f.stored_name LIKE 'qf3b-l1-%s-%%'" % (uid['admin01'], tag)) == '199')

print('\n── L-2 동시 넣기 — 한도·줄 수가 어긋나지 않는다(워커 넷 인스턴스)')
sql("DELETE FROM file_favorites WHERE user_id = %s" % uid['employee02'])
sql("INSERT INTO files (original_name, stored_name, ext, mime, size_bytes, sha256, description, access_level, uploaded_by) SELECT 'qf3b-L2-%s-' || g || '.txt', 'qf3b-l2-%s-' || g || '.txt', 'txt', 'text/plain', 10, repeat('0', 64), '', 'internal', %s FROM generate_series(1, 210) g" % (tag, tag, uid['employee01']))
sql("INSERT INTO file_favorites (user_id, file_id) SELECT %s, f.id FROM files f WHERE f.stored_name LIKE 'qf3b-l2-%s-%%' ORDER BY f.id LIMIT 195" % (uid['employee02'], tag))
spare = sql("SELECT string_agg(f.public_id::text, ',' ORDER BY f.id) FROM files f WHERE f.stored_name LIKE 'qf3b-l2-%s-%%' AND NOT EXISTS (SELECT 1 FROM file_favorites x WHERE x.user_id = %s AND x.file_id = f.id)" % (tag, uid['employee02'])).split(',')[:12]
ok('준비 — employee02 195 줄 · 새로 넣을 파일 12', sql("SELECT count(*) FROM file_favorites WHERE user_id = %s" % uid['employee02']) == '195' and len(spare) == 12)
def burst(paths):
    out = []
    lock = threading.Lock()
    gate = threading.Barrier(len(paths))
    def one(p):
        gate.wait()
        c, _, _ = req('e2', 'PUT', p, site=BSITE)   # 워커 넷 인스턴스 — 요청이 정말 동시에 처리된다
        with lock: out.append(c)
    ts = [threading.Thread(target=one, args=(p,)) for p in paths]
    for t in ts: t.start()
    for t in ts: t.join()
    return sorted(out)
got = burst(['/tbox/api/files/%s/favorite' % x for x in spare])
ok('파일 12개 동시 PUT → 200 ×5 · 409 ×7', got == [200] * 5 + [409] * 7, got)
ok('  줄 정확히 200(넘치지 않는다)', sql("SELECT count(*) FROM file_favorites WHERE user_id = %s" % uid['employee02']) == '200')
sql("DELETE FROM folder_favorites WHERE user_id = %s" % uid['employee02'])
sql("INSERT INTO folders (name, depth, access_level, created_by) SELECT 'qf3b-L2-%s-' || g, 1, 'internal', %s FROM generate_series(1, 54) g" % (tag, uid['employee01']))
sql("INSERT INTO folder_favorites (user_id, folder_id) SELECT %s, d.id FROM folders d WHERE d.name LIKE 'qf3b-L2-%s-%%' ORDER BY d.id LIMIT 46" % (uid['employee02'], tag))
spare_d = sql("SELECT string_agg(d.id::text, ',' ORDER BY d.id) FROM folders d WHERE d.name LIKE 'qf3b-L2-%s-%%' AND NOT EXISTS (SELECT 1 FROM folder_favorites x WHERE x.user_id = %s AND x.folder_id = d.id)" % (tag, uid['employee02'])).split(',')[:8]
got = burst(['/tbox/api/folders/%s/favorite' % x for x in spare_d])
ok('폴더 8개 동시 PUT → 200 ×4 · 409 ×4 · 줄 50', got == [200] * 4 + [409] * 4 and sql("SELECT count(*) FROM folder_favorites WHERE user_id = %s" % uid['employee02']) == '50', got)

print('PY_PASS=%d PY_FAIL=%d' % (P, F))
PY

hr "QF3A-T6 · T7 · L-1 · L-2 (파이썬 몸통)"
py_secret S_E1 S_E2 S_M1 S_A1 S_G1 S_M1N PW_A1 -- python3 "$WORK/drv.py" "$SITE" "$UA" "$DB" "$BSITE" > "$WORK/drv.out" 2>&1
cat "$WORK/drv.out"
pp=$(sed -n 's/^PY_PASS=\([0-9]*\) PY_FAIL=\([0-9]*\)$/\1/p' "$WORK/drv.out"); pf=$(sed -n 's/^PY_PASS=\([0-9]*\) PY_FAIL=\([0-9]*\)$/\2/p' "$WORK/drv.out")
if [[ "$pp" =~ ^[0-9]+$ ]] && [[ "$pf" =~ ^[0-9]+$ ]]; then PASS=$((PASS + pp)); FAIL=$((FAIL + pf)); else echo "  ✖ 파이썬 몸통이 끝줄을 못 찍었다"; FAIL=$((FAIL+1)); fi
[ "${pp:-0}" -ge 40 ] 2>/dev/null && expect "몸통이 헛돌지 않았다(통과 40 이상)" "yes" "yes" || expect "몸통이 헛돌지 않았다(통과 40 이상)" "yes" "no(${pp:-?})"

hr "QF3A-T7 — 서버에 새 일괄 창구 0 · B-T0 정적 · 서버 로그"
if git -C "$ROOT" rev-parse -q --verify "$BASE_REV" >/dev/null 2>&1; then
  expect "public/index.php — 기준 판($BASE_REV) 대비 바뀐 줄 0(새 라우트 0)" "0" "$(git -C "$ROOT" diff "$BASE_REV" -- public/index.php | grep -c '^[-+][^-+]')"
  expect "커뮤니티 라우트 표(src/community/routes.php) — 더하거나 뺀 라우트 줄 0" "0" \
    "$(git -C "$ROOT" diff "$BASE_REV" -- src/community/routes.php | grep -cE "^[-+] *\['(GET|POST|PUT|PATCH|DELETE)', *'/")"
  tokconst() { sed -n '/^const TBOX_API_TOKEN_\(ROUTES\|PERSONAL_ROUTES\|ONLY_ROUTES\) = \[/,/^\];/p'; }
  git -C "$ROOT" show "$BASE_REV":public/index.php | tokconst > "$WORK/tok_base.txt"
  tokconst < "$ROOT/public/index.php" > "$WORK/tok_now.txt"
  expect "토큰 창구 목록 셋 = 기준 판 글자 그대로" "same" "$([ -s "$WORK/tok_base.txt" ] && cmp -s "$WORK/tok_base.txt" "$WORK/tok_now.txt" && echo same || echo diff)"
else
  expect "기준 판 $BASE_REV 이 저장소에 있다(새 창구 0 을 견줄 수 있다)" "yes" "no"
fi
errs_after=$(grep -ciE 'PHP (Fatal|Warning|Notice|Deprecated)|SQLSTATE|Uncaught' "$INST/server.log")
expect "서버 로그의 새 PHP 오류·SQLSTATE 줄" "0" "$((errs_after - errs_before))"
[ "$((errs_after - errs_before))" = "0" ] || grep -iE 'PHP (Fatal|Warning|Notice|Deprecated)|SQLSTATE|Uncaught' "$INST/server.log" | tail -5 | sed 's/^/    /'
nbad=0
for f in src/handlers/home.php src/handlers/favorites_files.php src/handlers/favorites_folders.php src/help/screens.php src/help/community.php \
         src/views/shell_top.php src/community/routes.php src/community/handlers/favorites.php src/community/handlers/pages_get.php \
         src/community/handlers/home_page.php src/community/handlers/page_view.php; do
  php -l "$ROOT/$f" >/dev/null 2>&1 || { echo "  ✖ php -l $f"; nbad=$((nbad+1)); }
done
expect "바뀐 PHP php -l 실패" "0" "$nbad"
if [ -f "$ROOT/scripts/security_lint.sh" ]; then
  ( cd "$ROOT" && bash scripts/security_lint.sh > "$WORK/lint.txt" 2>&1 ); rc=$?
  expect "보안 린트 종료 코드" "0" "$rc"
  [ "$rc" -eq 0 ] || tail -8 "$WORK/lint.txt" | sed 's/^/    /'
fi
expect "app.js 절대 경로는 줄마다 APP_BASE 로(Q:T-4) — 새 일괄 코드의 '/api/' 줄" "0" \
  "$(grep -n "'/api/" "$ROOT/public/assets/app.js" | grep -v 'APP_BASE' | grep -ciE 'bulk|restore|favorite')"

hr "결과"
printf '통과 %d · 실패 %d\n' "$PASS" "$FAIL"
note "임시 DB($DB)·임시 폴더·임시 서버는 이 스크립트가 끝나며 지워진다. 운영에는 아무것도 남지 않는다."
[ "$FAIL" -eq 0 ] || exit 1
exit 0
