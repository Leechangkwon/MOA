#!/usr/bin/env bash
# tests/dbg_s_security.sh — 디버깅 묶음 S 수락 시험 (2026-09-26 11:52 보안 점검 중간·낮음 고치기 — 구현 기록 docs/debug-0926-s-implementation.md)
#
#   S-1 이름 새기     남의 공유 링크 회수 = 없는 링크 404(상태·머리·본문 바이트 같음) · 거부 감사에 파일 이름·등급 0(링크 번호만) ·
#                    「내 활동」 거부 행(회수·내려받기·미리보기·수정·삭제)에 대상 이름 0 · 등급 가림 · 감사 원본은 그대로 · 허용 행은 그대로
#   S-2 링크 재판정   파일 등급 올림 → 404(없는 링크와 같은 본문) + share.download deny role_not_permitted · 되돌리면 200 ·
#                    만든 사람 비활성 → issuer_inactive · 계정 만료 → issuer_inactive · 팀 이동 → team_mismatch · 되돌리면 200 · 막힌 요청은 횟수 소비 0
#   S-3 AL-9 억제     합성 auth.log 사본 + 임시 상태·임시 경보 로그 — 서로 다른 sudo 명령 셋 → 각각 한 줄 · 같은 명령 되풀이는 억제 ·
#                    두 번째 실행 0줄 · 새 명령 하나 → 한 줄 · 줄마다 command_key · 감사 alert.raised 도 같은 수
#   S-4 AL-7 주말     alerts_lib 판정 함수를 php -r 로(토·일·평일 경계) · 오늘 요일에 맞는 건너뜀/판정 줄(dry-run · 임시 설정)
#   S-5 토큰 회수     비밀번호 변경(본인) · 임시 비밀번호 재발급 · 2단계 인증 초기화 → 그 계정 토큰 401 · 감사 api_token.revoke allow <사유> ·
#                    응답 tokens_revoked · 다른 계정 토큰은 그대로 200
#   S-6 URL 비밀      user:pass@ 가 든 URL 가져오기 셋(스킴 있음·없음·쿼리 속 주소) → 감사(DB·파일 사본)에 비밀번호 글자 0 · `***@` 로 남음
#   S-7 child_count   못 보는 admin-only 하위 폴더는 세지 않는다 · 펼친 개수와 같다 · 관리자는 모두
#   S-8 재인증        재인증 안 한 Admin — 등급 내리기 403 next:reauth(+auth.gate 행 · 등급 그대로) · 공유 만들기 403 next:reauth(링크 행 0) ·
#                    올리기·설명만은 묻지 않음 · Manager 의 내리기는 재인증이 아니라 403 · 재인증 뒤 둘 다 됨
#   S-9 root 쓰기     cli_write_private — 대상이 링크면 거부 · 옛 고정 임시 이름에 심은 링크를 따라가지 않음 · 결과 0600 ·
#                    cli_append_line — 링크면 거부 · 새 파일 0644 · 상태 파일의 미래 시각은 무시하고 경고 한 줄(그 경보가 울린다) ·
#                    상태 파일이 링크면 읽지·쓰지 않음
#   S-10 업로드       zip 항목 `x.exe.`·`x.EXE `·`x.lnk`·`x.hta`·`x.iso`·`x.exe::$DATA` → 400 zip_blocked_entry · 정상 zip 201 ·
#                    겉 이름 `ISO.9001.txt` 는 막지 않음(흔한 낱말 형식은 zip 항목에서만) · docm — 겉은 화이트리스트 400, zip 속은 통과(보고만)
#   화면(선택)       DBGS_BROWSER=1 이면 tests/browser/dbg_s_screens.py(내 활동 가림 · 도움말 · 내 계정 비밀번호 칸 — 360·1280 × 라이트·다크)
#   회귀(선택)       REGRESS=1 이면 같은 임시 인스턴스에서 REGRESS_LIST(기본 help_button api_whoami api_read api_folders f68 q_launcher —
#                    카탈로그 writes_prod·manual_only(stage26·f66·f65·fetch_hosts·f51·f52·f54·f46)는 기본에서 빠지고 이름을 줘도 거부)
#                    — S-5 가 임시 DB 의 계정 셋을 바꾸므로 회귀는 `DBGS_SKIP_BODY=1 REGRESS=1` 로 **새 임시 DB** 에서 따로 돌린다
#
# ★ 운영 DB·운영 포트를 건드리지 않는다 — 임시 DB `tbox_d1s_<포트>_test`(운영 pg_dump 를 **읽어** 파이프로 붓기만) · 임시 인스턴스
#   `php -S 127.0.0.1:<포트>`(8211~8219 만 — 기본 8211). 검토 계정 claude_ 다섯(F-71). 비밀은 표준입력·0600 임시 파일로만(R-62).
#   임시 .env 원본 — 복제본에는 .env 가 없다: mktemp 폴더(0700)의 0600 사본을 DBGS_ENV_SRC(또는 QF2_ENV_SRC)로 넘기고 끝에 지운다.
#   이 인스턴스의 .env 는 DB_NAME·STORAGE_DIR·AUDIT_LOG_FILE 을 임시로, SCAN_ENABLED=0. 띄우기 전에 DB_NAME 이 tbox_re 가 아닌지 본다.
#   경보 배치(alerts.php)는 **임시 사본**을 임시 .env 로만 돌린다 — `--auth-log <합성 사본>` · `--state <임시>` · 경보 로그는 임시 폴더(먼저 확인).
#   운영 감사 max(id)·운영 alerts.log 줄 수를 시작·끝에 적는다(이 시험의 sudo psql 은 운영 cron 의 AL-9 에 한 줄 걸릴 수 있다 — 결과 줄에 적는다).
set -u
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
. "$ROOT/tests/lib/review_accounts.sh" || { echo "tests/lib/review_accounts.sh 를 읽지 못했다 — 시험을 시작하지 않는다"; exit 3; }
. "$ROOT/tests/lib/secrets.sh" || { echo "tests/lib/secrets.sh 를 읽지 못했다 — 시험을 시작하지 않는다 (R-62)"; exit 3; }
PORT="${DBGS_PORT:-8211}"
case "$PORT" in 821[1-9]) ;; *) echo "포트는 8211~8219 만 쓴다(받은 값: $PORT)"; exit 3 ;; esac
SITE="http://127.0.0.1:$PORT"
BASE="$SITE/tbox"
UA="tbox-review/$(date +%F)"
SRC_DB="tbox_re"                     # 운영 — **읽기만** 한다(pg_dump)
DB="tbox_d1s_${PORT}_test"           # 임시 — 여기에만 쓴다(접두 tbox_d1s_)
case "$DB" in tbox_d1s_*_test) ;; *) echo "임시 DB 이름 규칙 밖 — 중단"; exit 3 ;; esac
PROD_ALERTS="/var/log/tbox-v2/alerts.log"
JAR_DIR="$(mktemp -d)"
WORK="$(mktemp -d)"
chmod 700 "$JAR_DIR" "$WORK"
SHOTS="${DBGS_SHOTS:-$WORK/shots}"
INST=""
PIDS=()
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
  local pid
  for pid in "${PIDS[@]}"; do
    [ -n "$pid" ] && { pkill -P "$pid" 2>/dev/null; kill "$pid" 2>/dev/null; wait "$pid" 2>/dev/null; }
  done
  pkill -f "^php -S 127\.0\.0\.1:$PORT " 2>/dev/null
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

# 임시 .env — DB 이름·저장·감사 경로를 임시로(+ 검사 끔). $1 원본 $2 대상 $3 저장 폴더 $4 감사 파일. 값은 출력하지 않는다.
make_env() {
  # qcheck-allow: R20 — 임시 인스턴스용 사본(0700 폴더 · 0600 · trap 정리) · kill -9 뒤 남는 창은 받아들인 위험(HO-B1-004)
  sed -e "s#^DB_NAME=.*#DB_NAME=$DB#" \
      -e "s#^STORAGE_DIR=.*#STORAGE_DIR=$3#" \
      -e "s#^AUDIT_LOG_FILE=.*#AUDIT_LOG_FILE=$4#" \
      -e "s#^SCAN_ENABLED=.*#SCAN_ENABLED=0#" \
      "$1" > "$2"
  chmod 600 "$2"
  grep -q "^DB_NAME=$DB\$" "$2" && ! grep -q '^DB_NAME=tbox_re$' "$2" \
    && grep -q "^STORAGE_DIR=$3\$" "$2" && grep -q "^AUDIT_LOG_FILE=$4\$" "$2"
}
make_router() {
  cat > "$1/router.php" <<'PHP'
<?php
$root = __DIR__ . '/public';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (is_string($path) && strpos($path, '/tbox/assets/') === 0) {
    $rel = substr($path, strlen('/tbox/assets/'));
    $file = realpath($root . '/assets/' . $rel);
    if ($file !== false && strpos($file, realpath($root . '/assets') . '/') === 0 && is_file($file)) {
        $types = ['css' => 'text/css', 'js' => 'text/javascript', 'svg' => 'image/svg+xml', 'png' => 'image/png', 'webmanifest' => 'application/manifest+json', 'json' => 'application/json', 'woff2' => 'font/woff2'];
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
        readfile($file);
        return true;
    }
    http_response_code(404);
    return true;
}
require $root . '/index.php';
PHP
}
wait_up() {   # $1 사이트 — 미로그인 GET /tbox/login 200(감사 행을 만들지 않는 화면)
  local i c=""
  for i in $(seq 1 30); do
    sleep 0.3
    c=$(curl -s -o /dev/null -w '%{http_code}' -A "$UA" "$1/tbox/login")
    [ "$c" = "200" ] && break
  done
  printf '%s' "$c"
}
prod_alerts_lines() { sudo -n wc -l < "$PROD_ALERTS" 2>/dev/null | tr -d ' '; }

# ════════════════════════════════════════════════════════════════
hr "디버깅 묶음 S — 임시 인스턴스 준비 (운영 DB 에는 쓰지 않는다)"
sudo -n -u postgres psql -X -d "$SRC_DB" -tAc "SELECT 1" >/dev/null 2>&1 \
  || { echo "psql(postgres) 을 쓸 수 없다 — 이 시험은 임시 DB 가 없으면 성립하지 않는다"; exit 3; }
if ss -ltn 2>/dev/null | grep -q "127.0.0.1:$PORT "; then echo "포트 $PORT 이 이미 쓰이고 있다 — 중단"; exit 3; fi
ENV_SRC="${DBGS_ENV_SRC:-${QF2_ENV_SRC:-$ROOT/.env}}"
[ -f "$ENV_SRC" ] || { echo "임시 .env 원본이 없다 — DBGS_ENV_SRC(또는 QF2_ENV_SRC)로 0600 사본을 넘긴다"; exit 3; }
PROD_A0=$(sudo -n -u postgres psql -X -d "$SRC_DB" -tAc "SELECT coalesce(max(id),0) FROM audit_logs" 2>/dev/null)
PROD_L0=$(prod_alerts_lines)
note "운영 감사 max(id) 시작 = ${PROD_A0:-?} · 운영 alerts.log 줄 수 시작 = ${PROD_L0:-?} (읽기만)"

sub "① 운영 DB 를 **읽어** 임시 DB 를 만든다(파이프) + 마이그레이션(있으면 그대로)"
sudo -n -u postgres dropdb --if-exists "$DB" >/dev/null 2>&1
sudo -n -u postgres createdb -O tbox_owner "$DB" >/dev/null 2>&1 || { echo "임시 DB 를 만들 수 없다"; exit 3; }
sudo -n -u postgres pg_dump -d "$SRC_DB" 2>/dev/null \
  | sudo -n -u postgres psql -X -q -d "$DB" -v ON_ERROR_STOP=0 >/dev/null 2>"$WORK/restore.err"
DUMP_PS=("${PIPESTATUS[@]}")
[ "${DUMP_PS[0]}" -eq 0 ] || { echo "운영 DB 를 읽지 못했다 (pg_dump)"; exit 3; }
[ -n "$(psqlq "SELECT count(*) FROM files")" ] || { echo "임시 DB 로 옮기지 못했다 — $(head -2 "$WORK/restore.err")"; exit 3; }
for m in 2026-10-01_qf2_search.sql 2026-10-02_qf2b_files_search.sql 2026-10-03_qf3a_favorites_recent.sql 2026-10-04_users_temp_password_issued_at.sql 2026-10-05_qf3b_purge_favorites.sql; do
  [ -f "$ROOT/db/migrations/$m" ] || continue
  sudo -n -u postgres psql -X -q -d "$DB" -v ON_ERROR_STOP=1 -f - < "$ROOT/db/migrations/$m" 2>&1 | grep -v 'could not change directory\|already exists, skipping' | sed 's/^/    /'
done
note "임시 DB $DB — 파일 $(psqlq "SELECT count(*) FROM files") · 감사 $(psqlq "SELECT count(*) FROM audit_logs")"
# 선택: 임시 DB 의 claude_ 2단계 비밀을 /etc/tbox-v2/qa-totp.txt 값에 맞춘다(**임시 DB 만** · 파일은 읽기만).
#   2026-09-26 사고(f51·f52 가 운영 파일을 임시 DB 값으로 덮어씀) 뒤 운영 파일이 복구되기 전까지 로그인하려고 둔다.
#   비밀은 awk 가 SQL 을 만들어 psql 의 **표준입력**으로만 흐른다(명령 인자·출력에 0 — R-62). 오류 출력도 버린다(문장에 값이 있다).
if [ "${DBGS_SYNC_TOTP:-0}" = "1" ]; then
  sudo -n awk '$1 ~ /^claude_[a-z0-9]+$/ && $2 ~ /^[A-Z2-7]+$/ { printf "UPDATE users SET mfa_secret = %c%s%c, mfa_last_step = NULL WHERE username = %c%s%c;\n", 39, $2, 39, 39, $1, 39 }' /etc/tbox-v2/qa-totp.txt \
    | sudo -n -u postgres psql -X -q -d "$DB" -v ON_ERROR_STOP=1 >/dev/null 2>&1
  note "DBGS_SYNC_TOTP=1 — 임시 DB 의 claude_ 2단계 비밀을 파일 값에 맞췄다(종료 ${PIPESTATUS[1]:-?} · 값은 찍지 않는다)"
fi

sub "② 인스턴스 하나(포트 $PORT) — .env 는 사본(값은 출력하지 않는다)"
INST="$(mktemp -d)"; chmod 700 "$INST"
mkdir -p "$INST/app" "$INST/storage" "$INST/tmp" "$INST/log"
chmod 700 "$INST/tmp"
tar -C "$ROOT" -cf - --exclude=.git --exclude=jar --exclude=api-test.txt --exclude=./.env --exclude=./.claude . | tar -C "$INST/app" -xf -
make_env "$ENV_SRC" "$INST/app/.env" "$INST/storage" "$INST/log/audit.log" || { echo "임시 .env 에 임시 값이 들어가지 않았다 — 중단"; exit 4; }
expect "임시 .env — DB_NAME 이 tbox_re 가 아니다(grep '^DB_NAME=')" "DB_NAME=$DB" "$(grep '^DB_NAME=' "$INST/app/.env")"
expect "임시 .env — 감사 파일(=경보 로그 폴더)이 임시 폴더 안" "AUDIT_LOG_FILE=$INST/log/audit.log" "$(grep '^AUDIT_LOG_FILE=' "$INST/app/.env")"
ln -s /var/www/qaredi/community "$INST/community" 2>/dev/null
make_router "$INST/app"
( cd "$INST/app" && exec php -S "127.0.0.1:$PORT" -t public router.php > "$INST/server.log" 2>&1 ) &
PIDS+=("$!")
c=$(wait_up "$SITE")
expect "인스턴스가 떴다(로그인 화면 200)" "200" "$c"
[ "$c" = "200" ] || { echo "인스턴스를 띄우지 못했다 — 중단"; tail -5 "$INST/server.log"; exit 4; }

sub "로그인 (claude_ 검토 계정 다섯, 순차, F-71)"
for who in claude_employee01 claude_employee02 claude_manager01 claude_admin01 claude_guest01; do
  login "$who" || { echo "  ✖ $who 로그인 실패 — 중단"; exit 4; }
done
S_E1=$(session_of claude_employee01); S_E2=$(session_of claude_employee02); S_M1=$(session_of claude_manager01)
S_A1=$(session_of claude_admin01);   S_G1=$(session_of claude_guest01)
PW_A1=$(review_password claude_admin01)
PW_M1=$(review_password claude_manager01)
server_errs() { grep -iE 'PHP (Fatal|Warning|Notice|Deprecated)|SQLSTATE|Uncaught' "$INST/server.log" 2>/dev/null | wc -l; }
errs_before=$(server_errs)

# ─── 시험 몸통(파이썬) — 세션 다섯 + 관리자·매니저 비밀번호는 표준입력 한 줄씩(R-62). 임시 DB 는 sudo psql 로만 본다. ─────
cat > "$WORK/drv.py" <<'PY'
import hashlib, io, json, os, re, secrets, stat, string, subprocess, sys, time, urllib.error, urllib.request, uuid, zipfile
SITE, UA, DB, INST, WORK, SHOTS = sys.argv[1:7]
S = {w: sys.stdin.readline().rstrip('\n') for w in ('e1', 'e2', 'm1', 'a1', 'g1')}
PW_A1 = sys.stdin.readline().rstrip('\n')
PW_M1 = sys.stdin.readline().rstrip('\n')
P = F = 0
def ok(name, cond, detail=''):
    global P, F
    if cond: P += 1; print('  ✔ ' + name)
    else: F += 1; print('  ✖ ' + name + ('  — ' + str(detail)[:500] if detail != '' else ''))
def sql(q):
    r = subprocess.run(['sudo', '-n', '-u', 'postgres', 'psql', '-X', '-d', DB, '-t', '-A', '-c', q], capture_output=True, text=True, cwd='/')
    return r.stdout.strip()
CSRF = {}
def req(who, m, path, body=None, raw=None, ctype=None, csrf=True, extra=None):
    h = {'User-Agent': UA}
    if who: h['Cookie'] = 'tbox_session=' + S[who]
    if m != 'GET' and csrf and who: h['X-CSRF-Token'] = CSRF.get(who, '')
    data = raw
    if body is not None:
        data = json.dumps(body, ensure_ascii=False).encode('utf-8'); h['Content-Type'] = 'application/json'
    if ctype: h['Content-Type'] = ctype
    if extra: h.update(extra)
    r = urllib.request.Request(SITE + path, data=data, headers=h, method=m)
    try:
        with urllib.request.urlopen(r, timeout=60) as x:
            b = x.read(); c = x.status; hd = list(x.headers.items())
    except urllib.error.HTTPError as e:
        b = e.read(); c = e.code; hd = list(e.headers.items())
    try: d = json.loads(b or b'{}')
    except Exception: d = None
    return c, d, b, hd
def hdrs(hd):  # 매번 바뀌는 머리(Date)만 빼고 이름·값을 그대로
    return sorted((k.lower(), v) for k, v in hd if k.lower() not in ('date',))
for w in S:
    CSRF[w] = (req(w, 'GET', '/tbox/api/me')[1] or {}).get('csrf_token', '')
ok('세션 다섯의 CSRF 토큰', all(len(CSRF[w]) > 10 for w in S))
def upload(who, name, level='internal', team=None, content=None, ctype='text/plain'):
    b = uuid.uuid4().hex; parts = []
    fields = [('access_level', level), ('description', 'dbg-S 시험 파일')]
    if team is not None: fields.append(('team_id', str(team)))
    for k, v in fields:
        parts.append(('--%s\r\nContent-Disposition: form-data; name="%s"\r\n\r\n%s\r\n' % (b, k, v)).encode())
    body = content if content is not None else ('본문 ' + name + ' ' + uuid.uuid4().hex + '\n').encode()
    parts.append(('--%s\r\nContent-Disposition: form-data; name="file"; filename="%s"\r\nContent-Type: %s\r\n\r\n' % (b, name, ctype)).encode() + body + b'\r\n')
    parts.append(('--%s--\r\n' % b).encode())
    c, d, raw, _ = req(who, 'POST', '/tbox/api/files', raw=b''.join(parts), ctype='multipart/form-data; boundary=' + b)
    pid = (d or {}).get('id', '') if c == 201 else ''
    return c, d, pid, body
def fid(pub): return sql("SELECT id FROM files WHERE public_id = '%s'" % pub)
def audit_max(): return int(sql("SELECT coalesce(max(id), 0) FROM audit_logs") or 0)
def arows(a, b, where='TRUE'):
    q = ("SELECT coalesce(json_agg(json_build_array(action, result, coalesce(reason,''), coalesce(target_type,''), coalesce(target_id::text,''), "
         "coalesce(target_name,''), coalesce(access_level,''), summary, detail, coalesce(actor_name,'')) ORDER BY id), '[]') "
         "FROM audit_logs WHERE id > %d AND id <= %d AND %s" % (a, b, where))
    return json.loads(sql(q) or '[]')
uid = {n: sql("SELECT id FROM users WHERE username = 'claude_%s'" % n) for n in ('employee01', 'employee02', 'manager01', 'admin01', 'guest01')}
tag = uuid.uuid4().hex[:6]
ADMIN_NAME = 'dbgS-비밀-%s.txt' % tag      # 관리자만 보는 파일 — 이 이름이 새는지가 S-1 의 기준
NF_TOKEN = ''.join(secrets.choice(string.ascii_letters + string.digits) for _ in range(43))

print('\n── 준비 — 파일(앱 창구로 올림 · 이름 표식 %s)' % tag)
c, _, ADM, _ = upload('a1', ADMIN_NAME, 'admin-only')
ok('관리자 admin-only 파일 올림 201', c == 201 and len(ADM) == 36, c)
sql("UPDATE files SET scan_status = 'clean' WHERE public_id = '%s'" % ADM)

# ═══ S-1 ═══════════════════════════════════════════════════════
print('\n── S-1 남의 공유 링크 = 없는 링크 · 내 활동 거부 행 가림')
L1 = sql("INSERT INTO share_links (token_hash, file_id, created_by, expires_at, max_downloads) VALUES ('%s', %s, %s, now() + interval '1 day', 1) RETURNING id"
         % (hashlib.sha256(uuid.uuid4().bytes).hexdigest(), fid(ADM), uid['admin01'])).split('\n')[0]
NOPE = str(int(sql("SELECT coalesce(max(id),0) FROM share_links") or 0) + 100000)
ok('관리자의 admin-only 파일 링크 한 줄(임시 DB)', L1.isdigit(), L1)
a0 = audit_max()
cg, _, bg, hg = req('g1', 'DELETE', '/tbox/api/shares/' + L1)
cn, _, bn, hn = req('g1', 'DELETE', '/tbox/api/shares/' + NOPE)
ce, _, be, he = req('e1', 'DELETE', '/tbox/api/shares/' + L1)
ok('Guest — 남의 링크 404 · 없는 링크 404', cg == 404 and cn == 404, (cg, cn))
ok('  본문 바이트가 같다(남의 링크 = 없는 링크)', bg == bn and bg == be, (bg, bn, be))
ok('  머리(Date 뺀 이름·값 전부)가 같다', hdrs(hg) == hdrs(hn) == hdrs(he), (hdrs(hg), hdrs(hn)))
ok('Employee 도 404(같은 본문)', ce == 404, ce)
a1 = audit_max()
rv = [r for r in arows(a0, a1) if r[0] == 'share.revoke']
ok('감사 share.revoke deny not_owner 세 행 — 없는 링크도 같은 모양(검토 L-1)', [(r[1], r[2]) for r in rv] == [('deny', 'not_owner')] * 3, [(r[1], r[2]) for r in rv])
ok('  대상 = 요청한 링크 번호만(target_type share · target_id · 이름·등급 없음 · detail share_id)',
   [(r[3], r[4], r[5], r[6], (r[8] or {}).get('share_id')) for r in rv] == [('share', L1, '', '', int(L1)), ('share', NOPE, '', '', int(NOPE)), ('share', L1, '', '', int(L1))], rv)
t0 = (req('g1', 'GET', '/tbox/api/me/activity')[1] or {}).get('total')
req('g1', 'DELETE', '/tbox/api/shares/' + L1)
t1 = (req('g1', 'GET', '/tbox/api/me/activity')[1] or {}).get('total')
req('g1', 'DELETE', '/tbox/api/shares/' + str(int(NOPE) + 1))
t2 = (req('g1', 'GET', '/tbox/api/me/activity')[1] or {}).get('total')
ok('  내 활동 전체 건수 — 남의 링크·없는 링크 모두 +1(번호 존재가 total 로 새지 않는다)', isinstance(t0, int) and (t1 - t0, t2 - t1) == (1, 1), (t0, t1, t2))
ok('  행 전체(문장·detail 포함)에 파일 이름·admin-only 0', all(ADMIN_NAME not in json.dumps(r, ensure_ascii=False) and 'admin-only' not in json.dumps(r) for r in rv), rv)
# UUID 창구 거부 — Guest: 내려받기·미리보기 / Employee: 수정·삭제·내려받기
a0 = audit_max()
cd1 = req('g1', 'GET', '/tbox/api/files/%s/download' % ADM)[0]
cp1 = req('g1', 'GET', '/tbox/api/files/%s/preview' % ADM)[0]
cu1 = req('e1', 'PATCH', '/tbox/api/files/%s' % ADM, {'description': 'x'})[0]
cx1 = req('e1', 'DELETE', '/tbox/api/files/%s' % ADM)[0]
cd2 = req('e1', 'GET', '/tbox/api/files/%s/download' % ADM)[0]
a1 = audit_max()
ok('UUID 창구 거부 다섯(403)', (cd1, cp1, cu1, cx1, cd2) == (403, 403, 403, 403, 403), (cd1, cp1, cu1, cx1, cd2))
orig = arows(a0, a1, "result = 'deny' AND action LIKE 'file.%'")
ok('감사 원본에는 이름이 그대로(관리자 감사 화면 몫 — 가리지 않는다)', len(orig) >= 5 and all(r[5] == ADMIN_NAME for r in orig), [(r[0], r[5]) for r in orig])
for who in ('g1', 'e1'):
    c, d, raw, _ = req(who, 'GET', '/tbox/api/me/activity')
    rows = (d or {}).get('rows', [])
    deny = [r for r in rows if r.get('result') == 'deny']
    ok('%s 내 활동 200 · 거부 행 %d개 이상' % (who, 4 if who == 'e1' else 3), c == 200 and len(deny) >= (4 if who == 'e1' else 3), (c, len(deny)))
    ok('  응답 원문(JSON 바이트)에 admin-only 파일 이름 0', ADMIN_NAME.encode() not in raw and ADMIN_NAME.encode('unicode_escape') not in raw, raw[:300])
    perm = [r for r in deny if r.get('reason') in ('role_not_permitted', 'team_mismatch', 'not_owner', 'not_found', 'not_found_in_trash') and r.get('action') not in ('file.upload', 'file.fetch')]
    ok('  권한 계열 거부 행 target_name = 「(볼 수 없는 대상)」(값이 있던 행 — 그 밖의 사유는 가리지 않는다)', len(perm) >= 3 and all(r.get('target_name') in (None, '(볼 수 없는 대상)') for r in perm) and any(r.get('target_name') == '(볼 수 없는 대상)' for r in perm), [(r.get('reason'), r.get('target_name')) for r in deny][:8])
    ok('  거부 행 문장에 「(볼 수 없는 대상)」 · 등급 글자 admin-only 0', all('admin-only' not in r.get('summary', '') for r in deny) and any('(볼 수 없는 대상)' in r.get('summary', '') for r in deny), [r.get('summary') for r in deny][:3])
    ok('  칸은 여섯 그대로(actor_name 을 새로 내보내지 않는다)', all(sorted(r.keys()) == ['action', 'happened_at', 'reason', 'result', 'summary', 'target_name'] for r in rows), rows[:1])
c, _, OWN, _ = upload('e1', 'dbgS-내파일-%s.txt' % tag, 'internal')
sql("UPDATE files SET scan_status = 'clean' WHERE public_id = '%s'" % OWN)
req('e1', 'GET', '/tbox/api/files/%s/download' % OWN)
d = req('e1', 'GET', '/tbox/api/me/activity')[1] or {}
allow = [r for r in d.get('rows', []) if r.get('result') == 'allow' and r.get('action') == 'file.download']
ok('허용 행은 가리지 않는다(내가 받은 내 파일 이름 그대로)', any(r.get('target_name') == 'dbgS-내파일-%s.txt' % tag and ('dbgS-내파일-%s.txt' % tag) in r.get('summary', '') for r in allow), allow[:1])
sql("UPDATE files SET scan_status = 'pending' WHERE public_id = '%s'" % OWN)
cpend = req('e1', 'GET', '/tbox/api/files/%s/download' % OWN)[0]
d = req('e1', 'GET', '/tbox/api/me/activity')[1] or {}
pend = [r for r in d.get('rows', []) if r.get('result') == 'deny' and r.get('reason') == 'scan_pending']
ok('권한 계열이 아닌 거부(내 파일 검사 중 423)는 가리지 않는다 — 이름 그대로(검토 권고)', cpend == 423 and any(r.get('target_name') == 'dbgS-내파일-%s.txt' % tag for r in pend), (cpend, pend[:1]))
sql("UPDATE files SET scan_status = 'clean' WHERE public_id = '%s'" % OWN)
c1 = req('a1', 'DELETE', '/tbox/api/shares/' + L1)[0]
c2 = req('a1', 'DELETE', '/tbox/api/shares/' + L1)[0]
ok('관리자 갈래는 그대로 — 회수 200 · 다시 회수 400', (c1, c2) == (200, 400), (c1, c2))

# ═══ S-2 ═══════════════════════════════════════════════════════
print('\n── S-2 공유 링크 — 받을 때마다 만든 사람의 권한을 다시 본다')
c, _, SH, SHbody = upload('e1', 'dbgS-링크-%s.txt' % tag, 'internal')
sql("UPDATE files SET scan_status = 'clean' WHERE public_id = '%s'" % SH)
c, d, _, _ = req('e1', 'POST', '/tbox/api/files/%s/share' % SH, {'expires_hours': 24, 'max_downloads': 10})
url = (d or {}).get('url', '')
TOK = url.rsplit('/s/', 1)[-1] if '/s/' in url else ''
ok('직원이 자기 파일 링크 만들기 201(재인증 묻지 않음 — S-8 범위 밖)', c == 201 and len(TOK) == 43, (c, d and {k: v for k, v in d.items() if k != 'url'}))
SID = str((d or {}).get('id', ''))
def sget(tok):
    r = urllib.request.Request(SITE + '/tbox/s/' + tok, headers={'User-Agent': UA})
    try:
        with urllib.request.urlopen(r, timeout=30) as x: return x.status, x.read()
    except urllib.error.HTTPError as e: return e.code, e.read()
def used(): return sql("SELECT download_count FROM share_links WHERE id = %s" % SID)
cnf, bnf = sget(NF_TOKEN)
ok('없는 링크 404(비교 기준)', cnf == 404, cnf)
c, b = sget(TOK)
ok('처음 — 200 · 올린 바이트 그대로', c == 200 and b == SHbody, c)
e1team = sql("SELECT coalesce(team_id::text,'') FROM users WHERE id = %s" % uid['employee01'])
exp0 = sql("SELECT coalesce(expires_at::text,'') FROM users WHERE id = %s" % uid['employee01'])
def denied(label, reason):
    a0 = audit_max(); n0 = used()
    c, b = sget(TOK)
    a1 = audit_max()
    rows = arows(a0, a1, "action = 'share.download'")
    ok('%s → 404 · 없는 링크와 같은 본문' % label, c == 404 and b == bnf, (c, b[:80]))
    ok('  감사 share.download deny %s 한 행(링크 번호·발급자는 detail)' % reason,
       [(r[1], r[2]) for r in rows] == [('deny', reason)] and (rows[0][8] or {}).get('share_id') == int(SID) and (rows[0][8] or {}).get('issued_by') == 'claude_employee01', rows)
    ok('  막힌 요청은 횟수를 쓰지 않는다', used() == n0, (n0, used()))
def allowed(label):
    c, b = sget(TOK)
    ok('%s → 200' % label, c == 200 and b == SHbody, c)
sql("UPDATE files SET access_level = 'admin-only' WHERE public_id = '%s'" % SH)
denied('파일 등급을 admin-only 로 올림', 'role_not_permitted')
sql("UPDATE files SET access_level = 'internal' WHERE public_id = '%s'" % SH)
allowed('등급을 되돌림')
sql("UPDATE users SET is_active = false WHERE id = %s" % uid['employee01'])
denied('만든 사람 비활성화', 'issuer_inactive')
sql("UPDATE users SET is_active = true WHERE id = %s" % uid['employee01'])
allowed('다시 활성화')
sql("UPDATE users SET expires_at = now() - interval '1 minute' WHERE id = %s" % uid['employee01'])
denied('만든 사람 계정 만료', 'issuer_inactive')
sql("UPDATE users SET expires_at = %s WHERE id = %s" % ("NULL" if exp0 == '' else "'%s'" % exp0, uid['employee01']))
allowed('만료를 되돌림')
if e1team:
    other = sql("SELECT id FROM teams WHERE id <> %s AND is_active ORDER BY id LIMIT 1" % e1team)
    sql("UPDATE files SET access_level = 'team', team_id = %s WHERE public_id = '%s'" % (e1team, SH))
    allowed('team 등급(만든 사람의 팀)')
    if other:
        sql("UPDATE users SET team_id = %s WHERE id = %s" % (other, uid['employee01']))
        denied('만든 사람이 다른 팀으로 옮김', 'team_mismatch')
        sql("UPDATE users SET team_id = %s WHERE id = %s" % (e1team, uid['employee01']))
        allowed('팀을 되돌림')
    sql("UPDATE files SET access_level = 'internal', team_id = NULL WHERE public_id = '%s'" % SH)
else:
    print('  · claude_employee01 에 팀이 없다 — team_mismatch 갈래는 건너뜀')
own0 = sql("SELECT uploaded_by FROM files WHERE public_id = '%s'" % SH)
sql("UPDATE files SET uploaded_by = %s WHERE public_id = '%s'" % (uid['employee02'], SH))
denied('파일의 올린 사람이 바뀌어 만든 사람이 더는 고칠 수 없음(decideEdit — 검토 L-2)', 'not_owner')
sql("UPDATE files SET uploaded_by = %s WHERE public_id = '%s'" % (own0, SH))
allowed('올린 사람을 되돌림')

# ═══ S-8 (재인증 전 반) ════════════════════════════════════════
print('\n── S-8 Admin 재인증 — 재인증하지 않은 관리자 세션(로그인만)')
c, _, A8, _ = upload('a1', 'dbgS-등급-%s.txt' % tag, 'admin-only')
c2, _, A8b, _ = upload('a1', 'dbgS-올림-%s.txt' % tag, 'public')
sql("UPDATE files SET scan_status = 'clean' WHERE public_id IN ('%s','%s')" % (A8, A8b))
ok('관리자 파일 둘 올림(업로드는 재인증을 묻지 않는다)', c == 201 and c2 == 201, (c, c2))
a0 = audit_max()
c, d, _, _ = req('a1', 'PATCH', '/tbox/api/files/' + A8, {'access_level': 'public'})
a1 = audit_max()
ok('등급 내리기(admin-only → public) → 403 next:"reauth"', c == 403 and (d or {}).get('next') == 'reauth', (c, d))
ok('  등급은 그대로 admin-only', sql("SELECT access_level FROM files WHERE public_id = '%s'" % A8) == 'admin-only')
g = arows(a0, a1)
ok('  감사 auth.gate deny reauth_required 한 행(policy file.update) · file.level_change allow 0',
   [(r[0], r[1], r[2]) for r in g] == [('auth.gate', 'deny', 'reauth_required')] and (g[0][8] or {}).get('policy') == 'file.update', g)
c = req('a1', 'PATCH', '/tbox/api/files/' + A8b, {'access_level': 'admin-only'})[0]
ok('등급 올리기(public → admin-only)는 묻지 않는다 200', c == 200, c)
c = req('a1', 'PATCH', '/tbox/api/files/' + A8, {'description': 'dbg-S 설명만'})[0]
ok('설명만 바꾸기는 묻지 않는다 200', c == 200, c)
n0 = sql("SELECT count(*) FROM share_links WHERE file_id = %s" % fid(A8))
c, d, _, _ = req('a1', 'POST', '/tbox/api/files/%s/share' % A8, {'expires_hours': 1, 'max_downloads': 1})
ok('관리자 공유 링크 만들기 → 403 next:"reauth" · 링크 행 0', c == 403 and (d or {}).get('next') == 'reauth' and sql("SELECT count(*) FROM share_links WHERE file_id = %s" % fid(A8)) == n0, (c, d))
m1team = sql("SELECT coalesce(team_id::text,'') FROM users WHERE id = %s" % uid['manager01'])
c, _, MF, _ = upload('m1', 'dbgS-매니저-%s.txt' % tag, 'admin-only' if False else 'team', m1team or None) if m1team else (0, None, '', b'')
if MF:
    c, d, _, _ = req('m1', 'PATCH', '/tbox/api/files/' + MF, {'access_level': 'internal'})
    ok('Manager 의 등급 내리기는 재인증이 아니라 권한 403(next 없음)', c == 403 and 'next' not in (d or {}), (c, d))
c, d, _, _ = req('a1', 'POST', '/tbox/api/me/reauth', {'password': PW_A1})
ok('관리자 재인증 200', c == 200, (c, d))
c = req('a1', 'PATCH', '/tbox/api/files/' + A8, {'access_level': 'public'})[0]
ok('재인증 뒤 등급 내리기 200', c == 200 and sql("SELECT access_level FROM files WHERE public_id = '%s'" % A8) == 'public', c)
c, d, _, _ = req('a1', 'POST', '/tbox/api/files/%s/share' % A8, {'expires_hours': 1, 'max_downloads': 1})
ok('재인증 뒤 공유 링크 만들기 201', c == 201, (c, d and d.get('id')))

# ═══ S-6 ═══════════════════════════════════════════════════════
print('\n── S-6 URL 가져오기 — 감사에 user:pass@ 비밀번호 글자 0')
# 임시 DB 의 이 계정 가져오기 기록을 한 시간 창 밖으로 민다(1시간 10회 제한 — 운영 사본에 오늘 기록이 있으면 429 가 되어 이 시험이 헛돈다)
sql("UPDATE audit_logs SET happened_at = happened_at - interval '2 hours' WHERE actor_id = %s AND action = 'file.fetch' AND happened_at > now() - interval '1 hour'" % uid['manager01'])
pws = ['S3cretPW%s%d' % (tag, i) for i in range(3)]
pws += ['S3cret/PW?%s#3' % tag, 'S3cret/PW%s4' % tag]
urls = ['https://dbgsuser:%s@example.com/a.pdf' % pws[0],
        'dbgsuser:%s@example.com/b.pdf' % pws[1],
        'https://example.com/p?next=https://u:%s@evil.example/' % pws[2],
        'https://u:%s@x@example.com/c.pdf' % pws[3],
        'dbgsuser:%s@example.com/d.pdf' % pws[4]]
a0 = audit_max()
codes = [req('m1', 'POST', '/tbox/api/files/fetch', {'url': u, 'access_level': 'internal'})[0] for u in urls]
a1 = audit_max()
ok('가져오기 다섯 모두 거부(4xx)', all(400 <= x < 500 for x in codes), codes)
fr = arows(a0, a1, "action = 'file.fetch'")
ok('감사 file.fetch deny 다섯 행', len(fr) == 5 and all(r[1] == 'deny' for r in fr), [(r[1], r[2]) for r in fr])
blob = json.dumps(fr, ensure_ascii=False)
ok('  DB 행(문장·detail 전부)에 비밀번호 글자 0', all(p not in blob for p in pws), blob[:300])
ok('  source_url 은 `***@` 로 남는다(다섯 모두 — 비밀번호에 / ? # 가 섞여도, 검토 L-3)', all('***@' in str((r[8] or {}).get('source_url', '')) for r in fr), [(r[8] or {}).get('source_url') for r in fr])
try:
    with open(INST + '/log/audit.log', encoding='utf-8', errors='replace') as fh: filelog = fh.read()
except OSError:
    filelog = ''
ok('  감사 파일 사본(임시 audit.log)에도 비밀번호 글자 0', filelog != '' and all(p not in filelog for p in pws), len(filelog))

# ═══ S-7 ═══════════════════════════════════════════════════════
print('\n── S-7 폴더 child_count — 보이는 하위만')
PF = sql("INSERT INTO folders (parent_id, name, depth, access_level, created_by) VALUES (NULL, 'dbgS-부모-%s', 1, 'public', %s) RETURNING id" % (tag, uid['admin01'])).split('\n')[0]
sql("INSERT INTO folders (parent_id, name, depth, access_level, created_by) VALUES (%s, 'dbgS-숨은-%s', 2, 'admin-only', %s)" % (PF, tag, uid['admin01']))
sql("INSERT INTO folders (parent_id, name, depth, access_level, created_by) VALUES (%s, 'dbgS-보임-%s', 2, 'public', %s)" % (PF, tag, uid['admin01']))
def folder_row(who):
    d = req(who, 'GET', '/tbox/api/folders?parent=0')[1] or {}
    return next((f for f in d.get('folders', []) if str(f.get('id')) == PF), None)
fe, fa = folder_row('e1'), folder_row('a1')
ok('직원 — child_count 1(admin-only 하위는 세지 않음)', fe is not None and fe.get('child_count') == 1, fe)
kids = (req('e1', 'GET', '/tbox/api/folders?parent=' + PF)[1] or {}).get('folders', [])
ok('  펼친 개수와 같다(1) · 숨은 폴더 이름 0', len(kids) == 1 and all('숨은' not in k.get('name', '') for k in kids), kids)
ok('관리자 — child_count 2', fa is not None and fa.get('child_count') == 2, fa)
fg = folder_row('g1')
ok('Guest — child_count 1', fg is not None and fg.get('child_count') == 1, fg)

# ═══ S-10 ══════════════════════════════════════════════════════
print('\n── S-10 업로드 — zip 항목 확장자 정규화 · 차단 형식 보강')
def mkzip(names):
    bio = io.BytesIO()
    with zipfile.ZipFile(bio, 'w', zipfile.ZIP_DEFLATED) as z:
        for n in names:
            z.writestr(n, b'MZ dbg-S ' + n.encode('utf-8', 'surrogateescape'))
    return bio.getvalue()
bad = ['x.exe.', 'x.EXE ', 'x.lnk', 'dir/x.hta', 'x.iso', 'x.exe::$DATA', 'x.exe .', 'x.jar', 'x.sct', 'x.SHS', 'x.ps1xml.']
for n in bad:
    a0 = audit_max()
    c, d, pid, _ = upload('e1', 'dbgS-zip-%s.zip' % tag, 'internal', content=mkzip(['readme.txt', n]), ctype='application/zip')
    a1 = audit_max()
    rs = [(r[1], r[2]) for r in arows(a0, a1, "action = 'file.upload'")]
    ok('zip 항목 %r → 400 · 감사 file.upload deny zip_blocked_entry' % n, c == 400 and rs == [('deny', 'zip_blocked_entry')], (c, d, rs))
c, d, pid, _ = upload('e1', 'dbgS-zip-ok-%s.zip' % tag, 'internal', content=mkzip(['readme.txt', 'ISO.9001.인증.pdf.txt', 'a.b.c.txt', 'meeting 10:30.txt']), ctype='application/zip')
ok('정상 zip(흔한 낱말·콜론 든 이름) 201', c == 201, (c, d))
c, d, pid, _ = upload('e1', 'ISO.9001.dbgS-%s.txt' % tag, 'internal')
ok('겉 이름 ISO.9001.….txt 는 막지 않는다 201(흔한 낱말 형식은 zip 항목에서만)', c == 201, (c, d))
# 검토 M-1 — 새 윈도 형식은 zip 항목에서만. 겉 이름의 앞·가운데 조각에 그 낱말이 있어도 기준 판처럼 통과해야 한다
def png1():
    import struct, zlib
    def ch(t, b): return struct.pack('>I', len(b)) + t + b + struct.pack('>I', zlib.crc32(t + b) & 0xffffffff)
    return b'\x89PNG\r\n\x1a\n' + ch(b'IHDR', struct.pack('>IIBBBBB', 1, 1, 8, 2, 0, 0, 0)) + ch(b'IDAT', zlib.compress(b'\x00\xff\x00\x00')) + ch(b'IEND', b'')
pdf1 = b'%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[]/Count 0>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n'
for n, body, ct in [('Gadget-%s.png' % tag, png1(), 'image/png'), ('김철수.MSc.논문-%s.pdf' % tag, pdf1, 'application/pdf'), ('HTA.보고서-%s.pdf' % tag, pdf1, 'application/pdf'),
                    ('jar-%s.txt' % tag, None, 'text/plain'), ('plan.MST.%s.txt' % tag, None, 'text/plain'), ('MSP.계약.%s.txt' % tag, None, 'text/plain'), ('evil.hta.dbgS-%s.txt' % tag, None, 'text/plain')]:
    c, d, pid, _ = upload('e1', n, 'internal', content=body, ctype=ct)
    ok('겉 이름 %s → 201(기준 판과 같음 — 검토 M-1)' % n, c == 201, (c, d))
d = req('e1', 'GET', '/tbox/api/me/activity')[1] or {}
c, d2, pid, _ = upload('e1', 'bad.php.dbgS-%s.txt' % tag, 'internal')
ok('겉 이름 가운데 php → 400 double_extension(기준 판 규칙 그대로)', c == 400, (c, d2))
d = req('e1', 'GET', '/tbox/api/me/activity')[1] or {}
up = [r for r in d.get('rows', []) if r.get('action') == 'file.upload' and r.get('result') == 'deny']
ok('S-1 예외 — 내가 올리다 막힌 행은 내가 보낸 이름 그대로(가리지 않음)', any(r.get('target_name') == 'bad.php.dbgS-%s.txt' % tag and ('bad.php.dbgS-%s.txt' % tag) in r.get('summary', '') for r in up), up[:1])
c, d, pid, _ = upload('e1', 'dbgS-%s.docm' % tag, 'internal', content=b'PK\x03\x04', ctype='application/octet-stream')
ok('docm 겉 파일은 화이트리스트가 막는다 400(F-53 전 단계)', c == 400, (c, d))
c, d, pid, _ = upload('e1', 'dbgS-docm-in-zip-%s.zip' % tag, 'internal', content=mkzip(['report.docm', 'sheet.xlsm']), ctype='application/zip')
print('  · [보고만] zip 속 docm·xlsm 항목 — 상태 %s (F-53 매크로 검사는 겉 OOXML 만 본다 — 이번 묶음에서 바꾸지 않는다)' % c)

# ═══ S-5 (마지막 — 계정 상태를 바꾼다) ═════════════════════════
print('\n── S-5 비밀번호 변경·재발급·2단계 초기화 → 그 계정의 개인 프로그램 토큰 전부 회수')
def mktoken(who):
    t = ''.join(secrets.choice(string.ascii_letters + string.digits) for _ in range(48))
    tid = sql("INSERT INTO api_tokens (user_id, name, token_hash, hint, created_by, scope, expires_at) VALUES (%s, 'dbgS-%s', '%s', '%s', %s, 'read', now() + interval '1 day') RETURNING id"
              % (uid[who], tag, hashlib.sha256(t.encode()).hexdigest(), t[:6], uid[who])).split('\n')[0]
    return t, tid
def whoami(t):
    r = urllib.request.Request(SITE + '/tbox/api/whoami', headers={'User-Agent': UA, 'X-API-Token': t})
    try:
        with urllib.request.urlopen(r, timeout=30) as x: return x.status
    except urllib.error.HTTPError as e: return e.code
T = {w: mktoken(w) for w in ('manager01', 'employee02', 'guest01', 'employee01')}
ok('토큰 넷 — 처음에는 모두 200', all(whoami(T[w][0]) == 200 for w in T), {w: whoami(T[w][0]) for w in T})
a0 = audit_max()
newpw = 'dbgS-' + secrets.token_urlsafe(12)
c, d, _, _ = req('m1', 'POST', '/tbox/api/me/password', {'current': PW_M1, 'new': newpw})
a1 = audit_max()
ok('(a) 본인 비밀번호 변경 200 · tokens_revoked ≥ 1', c == 200 and isinstance((d or {}).get('tokens_revoked'), int) and d['tokens_revoked'] >= 1, (c, d))
ok('  그 토큰 401', whoami(T['manager01'][0]) == 401)
rr = [r for r in arows(a0, a1, "action = 'api_token.revoke'")]
ok('  감사 api_token.revoke allow password_change — 넣은 토큰 번호 · 회수한 사람 = 본인',
   any(r[1] == 'allow' and r[2] == 'password_change' and (r[8] or {}).get('token_id') == int(T['manager01'][1]) for r in rr)
   and sql("SELECT revoked_by FROM api_tokens WHERE id = %s" % T['manager01'][1]) == uid['manager01'], rr)
ok('  감사 행에 토큰 값·해시 0', all(T['manager01'][0] not in json.dumps(r) and hashlib.sha256(T['manager01'][0].encode()).hexdigest() not in json.dumps(r) for r in rr))
ok('  회수 수 = 응답 tokens_revoked = 감사 행 수', len(rr) == (d or {}).get('tokens_revoked'), (len(rr), d))
a0 = audit_max()
c, d, _, _ = req('a1', 'POST', '/tbox/api/users/%s/password/reset' % uid['employee02'], {'reason_text': 'dbg-S 시험'})
a1 = audit_max()
ok('(b) 관리자 임시 비밀번호 재발급 200', c == 200, c)
ok('  그 토큰 401(강제 절차의 403 이 아니라 회수된 토큰)', whoami(T['employee02'][0]) == 401)
rr = arows(a0, a1, "action = 'api_token.revoke'")
ok('  감사 api_token.revoke allow password_reset · 회수한 사람 = 관리자',
   any(r[2] == 'password_reset' and (r[8] or {}).get('token_id') == int(T['employee02'][1]) and r[9] == 'claude_admin01' for r in rr)
   and sql("SELECT revoked_by FROM api_tokens WHERE id = %s" % T['employee02'][1]) == uid['admin01'], rr)
a0 = audit_max()
c, d, _, _ = req('a1', 'POST', '/tbox/api/users/%s/mfa/reset' % uid['guest01'], {'reason_text': 'dbg-S 시험'})
a1 = audit_max()
ok('(c) 관리자 2단계 인증 초기화 200', c == 200, (c, d))
ok('  그 토큰 401', whoami(T['guest01'][0]) == 401)
rr = arows(a0, a1, "action = 'api_token.revoke'")
ok('  감사 api_token.revoke allow mfa_reset', any(r[2] == 'mfa_reset' and (r[8] or {}).get('token_id') == int(T['guest01'][1]) for r in rr), rr)
ok('다른 계정(claude_employee01) 토큰은 그대로 200', whoami(T['employee01'][0]) == 200)
ok('관리자 재발급·초기화 응답·감사에 토큰 원문 0', all(T[w][0] not in sql("SELECT coalesce(string_agg(detail::text || summary, ''), '') FROM audit_logs WHERE id > %d" % (a0 - 50)) for w in T))

print('PY_PASS=%d PY_FAIL=%d' % (P, F))
PY

if [ "${DBGS_SKIP_BODY:-0}" != "1" ]; then
hr "S-1 · S-2 · S-5 · S-6 · S-7 · S-8 · S-10 (파이썬 몸통 — 임시 인스턴스 HTTP)"
py_secret S_E1 S_E2 S_M1 S_A1 S_G1 PW_A1 PW_M1 -- python3 "$WORK/drv.py" "$SITE" "$UA" "$DB" "$INST" "$WORK" "$SHOTS" > "$WORK/drv.out" 2>&1
cat "$WORK/drv.out"
pp=$(sed -n 's/^PY_PASS=\([0-9]*\) PY_FAIL=\([0-9]*\)$/\1/p' "$WORK/drv.out"); pf=$(sed -n 's/^PY_PASS=\([0-9]*\) PY_FAIL=\([0-9]*\)$/\2/p' "$WORK/drv.out")
if [[ "$pp" =~ ^[0-9]+$ ]] && [[ "$pf" =~ ^[0-9]+$ ]]; then PASS=$((PASS + pp)); FAIL=$((FAIL + pf)); else echo "  ✖ 파이썬 몸통이 끝줄을 못 찍었다"; FAIL=$((FAIL+1)); fi
[ "${pp:-0}" -ge 70 ] 2>/dev/null && expect "몸통이 헛돌지 않았다(통과 70 이상)" "yes" "yes" || expect "몸통이 헛돌지 않았다(통과 70 이상)" "yes" "no(${pp:-?})"

if [ "${DBGS_BROWSER:-0}" = "1" ]; then
  hr "화면 — tests/browser/dbg_s_screens.py (몸통 뒤 — S-5 가 바꾸지 않는 claude_employee01 세션 · 몸통이 만든 거부 행이 보인다)"
  mkdir -p "$SHOTS"
  # 화면 첫 쪽에 가린 거부 행이 보이게 — 몸통이 올린 관리자 파일을 한 번 더 찔러 본다(쿠키는 파일에서 — 인자에 세션 없음)
  ADMF=$(psqlq "SELECT public_id FROM files WHERE original_name LIKE 'dbgS-비밀-%' ORDER BY id DESC LIMIT 1")
  expect "화면 준비 — 관리자 파일 내려받기 거부 403" "403" "$(curl -s -o /dev/null -w '%{http_code}' -A "$UA" -b "$JAR_DIR/claude_employee01.jar" "$BASE/api/files/$ADMF/download")"
  py_secret S_E1 -- env PATH="$HOME/.local/bin:$PATH" python3 "$ROOT/tests/browser/dbg_s_screens.py" "$SITE" "$UA" "$SHOTS" > "$WORK/browser.out" 2>&1
  rc=$?
  cat "$WORK/browser.out"
  expect "화면 시험 종료 코드" "0" "$rc"
  bf=$(sed -n 's/^SMOKE_FAIL=\([0-9]*\)$/\1/p' "$WORK/browser.out")
  expect "화면 시험 실패 수" "0" "${bf:-?}"
  note "스크린샷: $SHOTS (사람이 직접 연다)"
fi

# ════════════════════════════════════════════════════════════════
hr "S-3 · S-9 경보 배치 — 임시 사본(임시 .env · 합성 auth.log · 임시 상태 · 임시 경보 로그)"
TA="$INST/log/alerts.log"
expect "경보 로그 경로 = 임시 감사 폴더/alerts.log(운영 아님)" "$INST/log/alerts.log" "$(dirname "$(sed -n 's/^AUDIT_LOG_FILE=//p' "$INST/app/.env")")/alerts.log"
AL="$WORK/auth.log"; ST="$WORK/al-state.json"
umask 077
{
  printf 'Sep 26 13:00:01 box sudo: dbgsuser : TTY=pts/0 ; PWD=/home/dbgsuser ; USER=postgres ; COMMAND=/usr/bin/psql -X -d tbox_re -c select 1\n'
  printf 'Sep 26 13:00:02 box sudo: dbgsuser : TTY=pts/0 ; PWD=/home/dbgsuser ; USER=postgres ; COMMAND=/usr/bin/pg_dump -d tbox_re\n'
  printf 'Sep 26 13:00:03 box sudo: dbgsuser : TTY=pts/0 ; PWD=/home/dbgsuser ; USER=root ; COMMAND=/usr/bin/rm -rf /var/lib/tbox-v2/storage\n'
  printf 'Sep 26 13:00:04 box sudo: dbgsuser : TTY=pts/0 ; PWD=/home/dbgsuser ; USER=postgres ; COMMAND=/usr/bin/psql -X -d tbox_re -c select 1\n'
  printf 'Sep 26 13:00:05 box sudo: dbgsuser : TTY=pts/0 ; PWD=/home/dbgsuser ; USER=root ; COMMAND=/usr/bin/systemctl status nginx\n'
} > "$AL"
umask 022
run_alerts() { ( cd / && php "$INST/app/scripts/alerts.php" "$@" ) ; }
l0=$(cat "$TA" 2>/dev/null | wc -l)
AMAX0=$(psqlq "SELECT coalesce(max(id),0) FROM audit_logs")
run_alerts --rule AL-9 --auth-log "$AL" --state "$ST" > "$WORK/al1.out" 2>&1; rc=$?
sed 's/^/    | /' "$WORK/al1.out" | tail -8
expect "S-3 첫 실행 종료 코드" "0" "$rc"
l1=$(cat "$TA" 2>/dev/null | wc -l)
expect "S-3 서로 다른 sudo 명령 셋(psql·pg_dump·rm) → 경보 줄 셋(같은 psql 되풀이·systemctl 은 0)" "3" "$((l1 - l0))"
expect "  줄마다 AL-9 · subject = 계정 · command_key 있음" "3" "$(tail -n 3 "$TA" | jq -r 'select(.rule=="AL-9" and .evidence.subject=="dbgsuser" and (.evidence.command_key|type)=="string") | .rule' 2>/dev/null | wc -l)"
expect "  command_key 셋이 서로 다르다(psql# · pg_dump# · rm#)" "pg_dump psql rm" "$(tail -n 3 "$TA" | jq -r '.evidence.command_key' 2>/dev/null | sed 's/#.*//' | sort | tr '\n' ' ' | sed 's/ $//')"
expect "  감사 alert.raised AL-9 도 셋(임시 DB · 이번 실행 뒤 행만)" "3" "$(psqlq "SELECT count(*) FROM audit_logs WHERE id > ${AMAX0:-0} AND action = 'alert.raised' AND target_name = 'AL-9' AND detail->'evidence'->>'user' = 'dbgsuser'")"
run_alerts --rule AL-9 --auth-log "$AL" --state "$ST" > "$WORK/al2.out" 2>&1; rc=$?
l2=$(cat "$TA" | wc -l)
expect "S-3 두 번째 실행(30분 안) — 새 줄 0 · 「이미 발동해 건너뛴다」 셋 이상" "0 yes" "$((l2 - l1)) $([ "$(grep -c '이미 발동해 건너뛴다' "$WORK/al2.out")" -ge 3 ] && echo yes || echo no)"
printf 'Sep 26 13:10:00 box sudo: dbgsuser : TTY=pts/0 ; PWD=/home/dbgsuser ; USER=postgres ; COMMAND=/usr/bin/dropdb scratch_x\n' >> "$AL"
run_alerts --rule AL-9 --auth-log "$AL" --state "$ST" > "$WORK/al3.out" 2>&1
l3=$(cat "$TA" | wc -l)
expect "S-3 새 명령(dropdb) 하나 더 → 한 줄(옛 키 AL-9|계정 이면 0 이었다)" "1" "$((l3 - l2))"
expect "  상태 파일 억제 키 = AL-9|dbgsuser|<요약> 넷" "4" "$(jq -r 'keys[] | select(startswith("AL-9|dbgsuser|"))' "$ST" 2>/dev/null | wc -l)"
expect "  상태 파일 0600(임시)" "600" "$(stat -c '%a' "$ST")"

sub "S-9 상태 파일의 미래 시각 — 버리고 경고 한 줄 · 그 경보가 울린다"
KEY=$(php -r 'require $argv[1]; echo al_sudo_command_key($argv[2]);' "$INST/app/scripts/lib/alerts_lib.php" "/usr/bin/pg_dump -d tbox_re")
ST2="$WORK/al-state-future.json"
printf '{"AL-9|dbgsuser|%s": "2099-01-01T00:00:00+09:00", "ssh_seen": {"x@1.2.3.4": "2099-01-01T00:00:00+09:00"}}\n' "$KEY" > "$ST2"
AL2="$WORK/auth2.log"
printf 'Sep 26 13:20:00 box sudo: dbgsuser : TTY=pts/0 ; PWD=/home/dbgsuser ; USER=postgres ; COMMAND=/usr/bin/pg_dump -d tbox_re\n' > "$AL2"
l4=$(cat "$TA" | wc -l)
run_alerts --rule AL-9 --auth-log "$AL2" --state "$ST2" > "$WORK/al4.out" 2>&1; rc=$?
l5=$(cat "$TA" | wc -l)
expect "미래 시각(2099) 억제 칸 — 무시하고 발동 1줄 · 종료 0" "1 0" "$((l5 - l4)) $rc"
expect "  경고 한 줄(미래 시각 2개 버림)" "1" "$(grep -c '앞선 시각 2개' "$WORK/al4.out")"
NOW5=$(date -d '-5 minutes' -Iseconds)
printf '{"AL-9|dbgsuser|%s": "%s"}\n' "$KEY" "$NOW5" > "$ST2"
run_alerts --rule AL-9 --auth-log "$AL2" --state "$ST2" > "$WORK/al5.out" 2>&1
l6=$(cat "$TA" | wc -l)
expect "대조 — 5분 전(과거) 억제 칸은 그대로 억제(0줄) · 경고 0" "0 0" "$((l6 - l5)) $(grep -c '앞선 시각' "$WORK/al5.out")"
printf '{}\n' > "$WORK/al-target.json"; T0=$(sha256sum "$WORK/al-target.json" | cut -d' ' -f1)
ln -s "$WORK/al-target.json" "$WORK/al-link.json"
run_alerts --rule AL-9 --auth-log "$AL2" --state "$WORK/al-link.json" > "$WORK/al6.out" 2>&1; rc=$?
expect "상태 파일이 링크 — 읽지 않는다는 경고 · 저장 거부로 종료 1 · 링크 끝 파일 그대로" "1 1 $T0" \
  "$(grep -c '보통 파일이 아니다(심볼릭 링크 등) — 읽지 않고' "$WORK/al6.out") $rc $(sha256sum "$WORK/al-target.json" | cut -d' ' -f1)"

sub "S-9 cli_write_private · cli_append_line — 링크를 따라가지 않는다 (임시 폴더 · 이 계정 권한)"
W9="$WORK/s9"; mkdir -p "$W9"; chmod 700 "$W9"
printf 'ORIGINAL\n' > "$W9/target1"; printf 'ORIGINAL\n' > "$W9/target2"; printf 'ORIGINAL\n' > "$W9/target3"
ln -s "$W9/target1" "$W9/state1.json"
ln -s "$W9/target2" "$W9/state2.json.tmp"
ln -s "$W9/target3" "$W9/alerts-link.log"
cat > "$WORK/s9.php" <<'PHP'
<?php
require $argv[1] . '/scripts/lib/cli_bootstrap.php';
$w = $argv[2];
$r = [];
try { cli_write_private($w . '/state1.json', "NEW\n"); $r[] = 'write-link:written'; } catch (RuntimeException $e) { $r[] = 'write-link:refused'; }
try { cli_write_private($w . '/state2.json', "NEW2\n"); $r[] = 'write-oldtmp:written'; } catch (RuntimeException $e) { $r[] = 'write-oldtmp:refused'; }
try { cli_append_line($w . '/alerts-link.log', "L\n"); $r[] = 'append-link:written'; } catch (RuntimeException $e) { $r[] = 'append-link:refused'; }
try { cli_append_line($w . '/alerts-new.log', "L1\n"); cli_append_line($w . '/alerts-new.log', "L2\n"); $r[] = 'append-new:written'; } catch (RuntimeException $e) { $r[] = 'append-new:refused'; }
echo implode(' ', $r), "\n";
PHP
out=$( cd / && php "$WORK/s9.php" "$INST/app" "$W9" 2>&1 | tail -1 )
expect "결과 넷(링크 대상 거부 · 옛 고정 임시 이름의 링크는 무시하고 씀 · 덧붙이기 링크 거부 · 새 파일 덧붙이기)" \
  "write-link:refused write-oldtmp:written append-link:refused append-new:written" "$out"
expect "  링크 끝 파일 셋은 그대로 ORIGINAL" "ORIGINAL ORIGINAL ORIGINAL" "$(cat "$W9/target1") $(cat "$W9/target2") $(cat "$W9/target3")"
expect "  state2.json = 보통 파일 · 0600 · 새 내용" "regular 600 NEW2" "$([ -f "$W9/state2.json" ] && [ ! -L "$W9/state2.json" ] && echo regular) $(stat -c '%a' "$W9/state2.json") $(cat "$W9/state2.json")"
expect "  남은 임시 파일 0(.state2.json.*.tmp)" "0" "$(find "$W9" -name '.*.tmp' | wc -l)"
expect "  새 경보 로그 = 0644 · 두 줄" "644 2" "$(stat -c '%a' "$W9/alerts-new.log") $(wc -l < "$W9/alerts-new.log")"

sub "검토 M-2·L-6 — 한 벌 Audit::appendLineNoFollow(감사 파일 사본 · 경보 로그)"
printf 'A\n' > "$W9/aud.log"; ln "$W9/aud.log" "$W9/aud-hard.log"; printf 'B\n' > "$W9/aud2.log"; chmod 640 "$W9/aud2.log"
ln -s "$W9/aud2.log" "$W9/aud-link.log"
sudo -n install -o root -g root -m 644 /dev/null "$W9/root-owned.log" 2>/dev/null
cat > "$WORK/m2.php" <<'PHP'
<?php
require $argv[1] . '/scripts/lib/cli_bootstrap.php';
$w = $argv[2]; $r = [];
$t = static function (string $k, callable $f) use (&$r): void { try { $f(); $r[] = $k . ':ok'; } catch (RuntimeException $e) { $r[] = $k . ':refused'; } };
$t('append-a', fn() => Audit::appendLineNoFollow($w . '/aud2.log', "C\n", true, false, null));
$t('missing-nocreate', fn() => Audit::appendLineNoFollow($w . '/nope.log', "C\n", true, false, null));
$t('link', fn() => Audit::appendLineNoFollow($w . '/aud-link.log', "C\n", true, false, null));
$t('hardlink', fn() => Audit::appendLineNoFollow($w . '/aud.log', "C\n", true, false, null));
$t('cli-root-owned', fn() => cli_append_line($w . '/root-owned.log', "C\n"));
echo implode(' ', $r), "\n";
PHP
out=$( cd / && php "$WORK/m2.php" "$INST/app" "$W9" 2>&1 | tail -1 )
expect "추가 모드 쓰기 · 없는 파일은 만들지 않음 · 링크 거부 · 하드 링크 둘 거부 · 경보 로그 주인이 배치 계정이 아니면 거부(L-6)" \
  "append-a:ok missing-nocreate:refused link:refused hardlink:refused cli-root-owned:refused" "$out"
expect "  aud2.log 두 줄 · 없는 파일 안 생김 · 하드 링크 파일 그대로 A" "2 no A" "$(wc -l < "$W9/aud2.log") $([ -e "$W9/nope.log" ] && echo yes || echo no) $(cat "$W9/aud.log")"
expect "Audit::toFile — root CLI(euid 0)에서만 appendLineNoFollow 로(코드 한 줄 · 웹은 그대로)" "1" "$(grep -c "posix_geteuid() === 0" "$INST/app/src/Audit.php")"

sub "검토 M-3 — 경보 로그 쓰기 실패에도 감사 행은 남고 회차는 계속 · 주인·권한이 맞지 않는 상태 파일은 믿지 않는다"
TA2="$INST/log/alerts.log"; mv "$TA2" "$TA2.keep"; printf 'X\n' > "$W9/elsewhere.log"; ln -s "$W9/elsewhere.log" "$TA2"
A0=$(psqlq "SELECT coalesce(max(id),0) FROM audit_logs")
AL3="$WORK/auth3.log"
printf 'Sep 26 17:00:01 box sudo: dbgsuser : TTY=pts/0 ; PWD=/x ; USER=root ; COMMAND=/usr/bin/rm -rf /srv/m3a\n' > "$AL3"
printf 'Sep 26 17:00:02 box sudo: dbgsuser : TTY=pts/0 ; PWD=/x ; USER=root ; COMMAND=/usr/bin/rm -rf /srv/m3b\n' >> "$AL3"
run_alerts --rule AL-9 --auth-log "$AL3" --state "$WORK/m3a-state.json" > "$WORK/m3a.out" 2>&1; rc=$?
expect "경보 로그가 링크 — 종료 0 · 경고 두 줄 · 두 경보 모두 감사 행(alerts_log_failed) · 링크 끝 그대로" "0 2 2 X" \
  "$rc $(grep -c '경보 로그에 쓰지 못했다' "$WORK/m3a.out") $(psqlq "SELECT count(*) FROM audit_logs WHERE id > $A0 AND action = 'alert.raised' AND (detail->>'alerts_log_failed')::boolean") $(cat "$W9/elsewhere.log")"
rm -f "$TA2"; mv "$TA2.keep" "$TA2"
KEYB=$(php -r 'require $argv[1]; echo al_sudo_command_key(al_scrub_secrets($argv[2]));' "$INST/app/scripts/lib/alerts_lib.php" "/usr/bin/pg_dump -d tbox_re")
printf '{"AL-9|dbgsuser|%s": "%s"}\n' "$KEYB" "$(date -Iseconds)" > "$WORK/m3b-state.json"; chmod 620 "$WORK/m3b-state.json"
l7=$(cat "$TA" | wc -l)
run_alerts --rule AL-9 --auth-log "$AL2" --state "$WORK/m3b-state.json" > "$WORK/m3b.out" 2>&1; rc=$?
l8=$(cat "$TA" | wc -l)
expect "그룹 쓰기 권한이 있는 상태 파일 — 믿지 않고(경고) 억제 없이 발동 1줄 · 종료 0" "1 1 0" "$(grep -c '주인·권한이 맞지 않다' "$WORK/m3b.out") $((l8 - l7)) $rc"
chmod 600 "$WORK/m3b-state.json"; printf '{"AL-9|dbgsuser|%s": "%s"}\n' "$KEYB" "$(date -Iseconds)" > "$WORK/m3b-state.json"
run_alerts --rule AL-9 --auth-log "$AL2" --state "$WORK/m3b-state.json" > "$WORK/m3c.out" 2>&1
expect "대조 — 0600 · 주인 맞음 → 억제(0줄) · 경고 0" "0 0" "$(( $(cat "$TA" | wc -l) - l8 )) $(grep -c '주인·권한' "$WORK/m3c.out")"

sub "검토 L-4 — 앞 200자가 같은 서로 다른 긴 명령은 다른 요약(각각 한 줄)"
LONGP=$(printf 'x%.0s' $(seq 1 230))
printf 'Sep 26 17:10:01 box sudo: dbgsuser : TTY=pts/0 ; PWD=/x ; USER=postgres ; COMMAND=/usr/bin/psql -c %sA\n' "$LONGP" > "$WORK/auth4.log"
printf 'Sep 26 17:10:02 box sudo: dbgsuser : TTY=pts/0 ; PWD=/x ; USER=postgres ; COMMAND=/usr/bin/psql -c %sB\n' "$LONGP" >> "$WORK/auth4.log"
l9=$(cat "$TA" | wc -l)
run_alerts --rule AL-9 --auth-log "$WORK/auth4.log" --state "$WORK/l4-state.json" > "$WORK/l4.out" 2>&1
expect "긴 명령 둘 → 두 줄(자르기 전 전체를 해시)" "2" "$(( $(cat "$TA" | wc -l) - l9 ))"

# ════════════════════════════════════════════════════════════════
hr "S-4 AL-7 업무시간 = 평일 09~18시 (판정 함수 php -r · 운영 동작 불변)"
bh() { TZ=Asia/Seoul php -d date.timezone=Asia/Seoul -r 'require $argv[1]; $t = strtotime($argv[2]); echo al_is_business_hours($t) ? "1" : "0", " ", al_off_hours_reason($t);' "$INST/app/scripts/lib/alerts_lib.php" "$1"; }
expect "토 2026-09-26 13:00 → 업무시간 아님(주말)"     "0 주말" "$(bh '2026-09-26 13:00:00 Asia/Seoul')"
expect "일 2026-09-27 10:00 → 업무시간 아님(주말)"     "0 주말" "$(bh '2026-09-27 10:00:00 Asia/Seoul')"
expect "월 2026-09-28 08:59 → 아님(평일)"             "0 업무시간 아님" "$(bh '2026-09-28 08:59:00 Asia/Seoul')"
expect "월 2026-09-28 09:00 → 업무시간"               "1 업무시간 아님" "$(bh '2026-09-28 09:00:00 Asia/Seoul')"
expect "월 2026-09-28 17:59 → 업무시간"               "1 업무시간 아님" "$(bh '2026-09-28 17:59:00 Asia/Seoul')"
expect "월 2026-09-28 18:00 → 아님"                   "0 업무시간 아님" "$(bh '2026-09-28 18:00:00 Asia/Seoul')"
expect "금 2026-10-02 12:00 → 업무시간"               "1 업무시간 아님" "$(bh '2026-10-02 12:00:00 Asia/Seoul')"
expect "토 2026-10-03 09:30 → 아님(주말 — 공휴일은 모른다)" "0 주말" "$(bh '2026-10-03 09:30:00 Asia/Seoul')"
run_alerts --rule AL-7 --dry-run --state "$WORK/al7-state.json" > "$WORK/al7.out" 2>&1; rc=$?
DOW=$(date +%u); HR=$(date +%-H)
if [ "$DOW" -ge 6 ]; then want='AL-7 건너뜀 (주말'; elif [ "$HR" -ge 9 ] && [ "$HR" -lt 18 ]; then want='(업무시간)'; else want='AL-7 건너뜀 (업무시간 아님'; fi
expect "오늘($(date +%a) ${HR}시) dry-run 판정 줄 — 「$want」 · 종료 0 · 상태 파일 없음(dry-run)" "1 0 no" \
  "$(grep -cF "$want" "$WORK/al7.out") $rc $([ -e "$WORK/al7-state.json" ] && echo yes || echo no)"
sed 's/^/    | /' "$WORK/al7.out" | grep 'AL-7'
else
  note "DBGS_SKIP_BODY=1 — S-* 몸통을 건너뛴다(회귀 전용 실행: 새 임시 DB · 계정 상태를 바꾸지 않는다)"
fi

# ════════════════════════════════════════════════════════════════
hr "정적 — php -l · 보안 린트 · bash -n · JS 구문 · 서버 로그"
nbad=0
for f in src/Audit.php src/Auth.php src/Authz.php src/Upload.php src/handlers/activity_list.php src/handlers/share_revoke.php \
         src/handlers/share_download.php src/handlers/share_create.php src/handlers/files_update.php src/handlers/files_fetch.php \
         src/handlers/folders_list.php src/handlers/password_change.php src/handlers/users_password_reset.php src/handlers/users_mfa_reset.php \
         src/handlers/settings_page.php src/help/screens.php scripts/alerts.php scripts/lib/alerts_lib.php scripts/lib/cli_bootstrap.php; do
  php -l "$ROOT/$f" >/dev/null 2>&1 || { echo "  ✖ php -l $f"; nbad=$((nbad+1)); }
done
expect "바뀐 PHP php -l 실패" "0" "$nbad"
( cd "$ROOT" && bash scripts/security_lint.sh > "$WORK/lint.txt" 2>&1 ); rc=$?
expect "보안 린트 종료 코드" "0" "$rc"
[ "$rc" -eq 0 ] || tail -8 "$WORK/lint.txt" | sed 's/^/    /'
expect "bash -n 이 시험" "0" "$(bash -n "$0"; echo $?)"
expect "검토 M-1 — 겉 이름 여섯은 이중 확장자가 아니다(Upload::checkExtension · 기준 판과 같음)" "png pdf pdf jpg xlsx docx" \
  "$(php -r 'require $argv[1]; $o = []; foreach (array_slice($argv, 2) as $n) { try { $o[] = Upload::checkExtension($n); } catch (RuntimeException $e) { $o[] = $e->getMessage(); } } echo implode(" ", $o);' "$ROOT/src/Upload.php" Gadget.png 김철수.MSc.논문.pdf HTA.보고서.pdf jar.jpg plan.MST.xlsx MSP.계약.docx)"
if python3 -c 'import esprima' 2>/dev/null; then
  expect "esprima 구문 — app.js" "ok" "$(python3 -c 'import esprima,sys; esprima.parseScript(open(sys.argv[1],encoding="utf-8").read()); print("ok")' "$ROOT/public/assets/app.js" 2>&1 | tail -1)"
fi
errs_after=$(server_errs)
expect "서버 로그의 새 PHP 오류·SQLSTATE 줄" "0" "$((errs_after - errs_before))"
[ "$((errs_after - errs_before))" = "0" ] || grep -iE 'PHP (Fatal|Warning|Notice|Deprecated)|SQLSTATE|Uncaught' "$INST/server.log" | tail -5 | sed 's/^/    /'

# ════════════════════════════════════════════════════════════════
if [ "${REGRESS:-0}" = "1" ]; then
  hr "회귀 — 같은 임시 인스턴스에서(qf3c_zip.sh 의 회귀 틀 그대로)"
  note "각 스크립트를 임시 인스턴스 폴더로 복사해 SITE·DB 만 바꿔 돈다(운영 8082·tbox_re 에 닿지 않는다). S-5 가 바꾼 계정(claude_manager01·employee02·guest01)은 임시 DB 를 새로 붓지 않으면 로그인이 달라진다 — 회귀는 따로 부른 새 인스턴스에서 돌리는 것을 권한다."
  sed -i "s#http://127.0.0.1:8082#$SITE#g" "$INST/app/scripts/ui_snapshot.sh" 2>/dev/null
  grep -q "http://127.0.0.1:8082" "$INST/app/scripts/ui_snapshot.sh" && { echo "  ✖ ui_snapshot.sh 에 운영 주소가 남았다 — 회귀를 돌리지 않는다"; FAIL=$((FAIL+1)); REGRESS_LIST=" "; }
  # 검토 M-4 — 운영에 쓰는 시험(카탈로그 writes_prod)·사람이 도는 시험(manual_only)은 **기본 목록에서 빼고, 이름을 줘도 거부**한다.
  #   이 틀은 주소·DB 만 바꾸므로 시험 안의 /etc·운영 저장소 쓰기는 그대로 운영에 닿는다(2026-09-26 f51·f52 사고).
  #   stage26·f66·fetch_hosts·f65 도 카탈로그상 writes_prod 라 빠진다 — 그 시험들은 자기 임시 틀이 생길 때까지 따로 돈다.
  CAT_BLOCK="$WORK/catalog_block.txt"
  python3 - "$INST/app/tests/catalog.json" > "$CAT_BLOCK" <<'PYCAT'
import json, sys
d = json.load(open(sys.argv[1], encoding='utf-8'))
for t in d.get('tests', []):
    if t.get('writes_prod') is True or t.get('status') == 'manual_only':
        c = t.get('cmd', '')
        if c.startswith('bash tests/') and c.endswith('.sh'):
            print(c[len('bash tests/'):-3])
PYCAT
  [ -s "$CAT_BLOCK" ] || { echo "  ✖ 카탈로그에서 거부 목록을 못 읽었다 — 회귀를 돌리지 않는다"; FAIL=$((FAIL+1)); REGRESS_LIST=" "; }
  note "카탈로그 거부 목록(writes_prod·manual_only): $(tr '\n' ' ' < "$CAT_BLOCK")"
  for s in ${REGRESS_LIST:-help_button api_whoami api_read api_folders f68 q_launcher}; do
    src="$INST/app/tests/$s.sh"
    dst="$INST/app/tests/$s.temp.sh"
    [ -f "$src" ] || { echo "  ✖ $s — 스크립트가 없다"; FAIL=$((FAIL+1)); continue; }
    if grep -qxF "$s" "$CAT_BLOCK"; then
      echo "  ✖ $s — 카탈로그상 운영에 쓰거나(writes_prod) 사람이 도는(manual_only) 시험이라 이 틀로 돌리지 않는다"; FAIL=$((FAIL+1)); continue
    fi
    sed -e "s#http://127.0.0.1:8082#$SITE#g" -e "s#^DB=\"tbox_re\"#DB=\"$DB\"#" -e "s#-d tbox_re #-d $DB #g" \
        -e "s#^TMPDB=\"tbox_\([a-z0-9_]*\)\"#TMPDB=\"tbox_d1s_\1\"#" "$src" > "$dst"
    # 주석을 뺀 줄을 임시 파일로 — `grep | grep -q` 파이프는 앞쪽이 SIGPIPE 로 끝날 수 있다(pipefail·검토 M-4)
    grep -v '^\s*#' "$dst" > "$WORK/nc_$s.txt" || true
    if grep -q '127\.0\.0\.1:8082\|localhost:8082\|DB="tbox_re"\|-d tbox_re' "$WORK/nc_$s.txt"; then
      echo "  ✖ $s — 운영 주소·DB 가 남아 있어 돌리지 않는다"; FAIL=$((FAIL+1)); continue
    fi
    # 운영 경로에 쓰는 줄 — sudo + 쓰기 동작 + 운영 경로, 또는 그 경로로의 넘겨쓰기(> · >> · tee)
    if grep -qE 'sudo[^|;&]*[[:space:]](install|cp|mv|tee|rm|ln|chmod|chown|chattr|truncate|dd|touch|mkdir|rmdir|sed[[:space:]]+-i)[[:space:]][^|;&]*(/etc/|/var/lib/tbox-v2|/var/log/tbox-v2|/var/backups)|>>?[[:space:]]*"?(/etc/|/var/lib/tbox-v2|/var/log/tbox-v2|/var/backups)|tee[^|;&]*(/etc/|/var/lib/tbox-v2|/var/log/tbox-v2|/var/backups)' "$WORK/nc_$s.txt"; then
      echo "  ✖ $s — 운영 /etc·/var/lib/tbox-v2·/var/log/tbox-v2·/var/backups 에 쓰는 줄이 있어 이 틀로 돌리지 않는다"; FAIL=$((FAIL+1)); continue
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
PROD_A1=$(sudo -n -u postgres psql -X -d "$SRC_DB" -tAc "SELECT coalesce(max(id),0) FROM audit_logs" 2>/dev/null)
PROD_L1=$(prod_alerts_lines)
PROD_MINE=$(sudo -n -u postgres psql -X -d "$SRC_DB" -tAc "SELECT count(*) FROM audit_logs WHERE id > ${PROD_A0:-0} AND user_agent = '$UA' AND (actor_name LIKE 'claude\\_%' OR action LIKE 'share.%' OR action LIKE 'api_token.%')" 2>/dev/null)
note "운영 감사 max(id) 끝 = ${PROD_A1:-?} (시작 ${PROD_A0:-?}) · 운영 alerts.log 끝 = ${PROD_L1:-?}줄 (시작 ${PROD_L0:-?}) — 늘었다면 운영 cron 이 이 시험의 sudo psql(임시 DB)을 AL-9 로 적은 것일 수 있다(읽기만)"
expect "운영 감사에 이 시험의 표식(UA) 행 0 — 운영에는 요청하지 않는다" "0" "${PROD_MINE:-?}"
printf '통과 %d · 실패 %d\n' "$PASS" "$FAIL"
note "임시 DB($DB)·임시 폴더·임시 서버는 이 스크립트가 끝나며 지워진다. 운영에는 아무것도 남지 않는다."
[ "$FAIL" -eq 0 ] || exit 1
exit 0
