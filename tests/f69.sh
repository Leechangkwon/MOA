#!/usr/bin/env bash
# tests/f69.sh — F-69 「경보에 누가 일으켰나(origin) 표기 + AL-6 격리 파일 오탐 수정」 수락 기준 실행
#                (지시서 docs/f69-alert-origin-directive.md D 표 F69-T1 ~ F69-T10 · PRD F-69 · F-54 · F-71 ③④)
#
#   T1  Origin 단위 시험 — 지시서 A-1 표의 칸마다 한 줄 (PHP CLI, 순수 함수)
#   T2  임시 DB 에 규칙별 가짜 감사 행(검토 계정·사람 계정 두 벌 + AL-1 은 「한쪽만」 한 벌) →
#       임시 인스턴스의 alerts.php(비 dry-run, --state 임시) → 임시 alerts.log 의 줄마다 origin·title
#   T3  사본 auth.log(--auth-log) — SSH 1줄 · dropdb 시험 DB · psql 운영 DB · psql qcheck_ · rm (+ 비밀값 두 줄)
#   T4  감사 alert.raised detail.origin 이 줄과 같다 · 비밀값 0 (al_scrub_command 그대로)
#   T5  verify_backup.sh 실패 경로 — --backup-root 빈 임시 폴더로 두 번 → AL-8 줄 origin:"system" ·
#       PHP 쪽과 같은 키 · 같은 파서(GET /api/admin/alerts)로 읽힘 · 운영 실패 횟수 파일 불변
#   T6  옛 줄 불변 — 운영 alerts.log 첫 889줄 sha256 시작 = 끝 · 이 시험의 표식이 운영 로그에 0줄
#   T7  AL-6 — 임시 저장소·임시 격리 폴더로 지시서 C-3 의 경우들(+ 격리 중 변조 한 경우)을 만든 뒤 consistency_check
#   T8  AL-6 운영 확인(쓰기 없음) — 운영 DB 를 **읽어** 살아 있는 infected 행이 전부 격리 폴더에 있나(SQL + ls)
#   T9  화면 — 헤드리스로 관리자 경보 목록(재인증 포함) · 배지 「Claude」·「시스템」·「확인 필요」·「출처 표기 전」 ·
#       사람 줄엔 배지 없음 · 글자는 textContent(주입 문자열이 요소가 되지 않음) · 라이트·다크·390px 스크린샷
#   2b  (대기열) Admin 계정 만들기 창구 — claude_·qa_ 새 이름 400 reserved_prefix · 있는 이름은 already_exists · 사람 이름 201
#   T10 회귀·정적 — 운영 alerts.php --dry-run(읽기만 — 줄마다 origin=) · 옛 코드(F69_BASE)와 발동 목록 대조 ·
#       security_lint 0 · php -l · bash -n · wiki_check 0.  회귀 시험(f54·stage26·help_button·f68)은 **따로** 돈다
#
# ★ 운영에 쓰지 않는다 — tests/community/stage7c.sh 와 같은 틀.
#   · 임시 DB `tbox_f69_test`(운영을 pg_dump 로 **읽어** 만든다) · 임시 인스턴스 포트 8099 · 임시 저장소·격리·로그 폴더
#   · alerts.php·consistency_check.php 는 임시 인스턴스의 사본을 **일반 사용자**로 돌린다(--auth-log 사본 ·
#     --state 임시) — 운영 상태 파일·운영 alerts.log 에 닿지 않는다. verify_backup.sh 만 root 가 필요해 sudo 로
#     돌리고 --backup-root 임시 폴더를 준다(운영 백업 폴더·실패 횟수 불변 — T5 가 대조한다).
#   · 운영에 닿는 것은 **읽기** 넷뿐이다: pg_dump(임시 DB 만들기) · T6 alerts.log 앞 889줄 해시 · T8 SQL+ls ·
#     T10 alerts.php --dry-run(--state 임시 · --auth-log 빈 파일).
#   · 이 시험이 치는 sudo 명령은 운영 alerts.php 의 AL-9 에 걸린다. 임시 DB(`tbox_f69_test`)를 겨눈 것은
#     `claude-session`, 운영 DB 를 읽는 pg_dump·T8 은 `human` 이다(지시서 A-2 · K-3 — 판정을 넓히지 않는다).
#
# 규칙 (CLAUDE.md 3장 · F-71)
#   · 로그인은 `claude_admin01` 한 번뿐(T9 브라우저, 임시 인스턴스). 모든 요청에 `User-Agent: tbox-review/<날짜>`.
#   · 가짜 감사 행의 행위자 이름은 이 실행의 표식이 붙은 `claude_f69r<시각>`(검토) · `f69h<시각>`(사람) ·
#     `claude_f69p<시각>`(검토 계정이지만 표식 없음) — 계정 행은 만들지 않는다(감사 행만, 임시 DB).
#   · 비밀번호·2단계 코드는 화면에 찍지 않는다.
#
# 준비물: php · python3(+playwright — 없으면 T9 SKIP) · sudo -n(postgres psql·pg_dump·createdb·dropdb ·
#         verify_backup · seed-accounts.txt · qa-totp.txt · 운영 alerts.log 읽기) · git(T10 옛 코드 대조)
# 사용: sudo -v && bash tests/f69.sh [스크린샷 폴더]      (F69_BASE=v2.19.0 — 대조할 옛 코드)
#       복제본(운영 .env 가 없는 트리): F69_PORT=819x F69_NO_ROOT=1 QF2_ENV_SRC=<0700 폴더의 .env 사본> bash tests/f69.sh
#       — 포트·root 갈래 건너뛰기·임시 .env 원본만 바꾼다(보안 넷 2026-09-26). 기본값은 전과 같다.
set -u

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
# 검토 계정 판정 한 벌(qa_·claude_ — F-71 C). 못 읽으면 시작하지 않는다.
. "$ROOT/tests/lib/review_accounts.sh" || { echo "tests/lib/review_accounts.sh 를 읽지 못했다 — 시험을 시작하지 않는다 (R-73)"; exit 3; }
. "$ROOT/tests/lib/secrets.sh" || { echo "tests/lib/secrets.sh 를 읽지 못했다 — 시험을 시작하지 않는다 (R-62)"; exit 3; }

PORT="${F69_PORT:-8099}"
case "$PORT" in 80|8082|8081) echo "운영 포트($PORT)는 쓰지 않는다"; exit 3 ;; esac
# F69_NO_ROOT=1 — postgres(psql·pg_dump·createdb·dropdb)·비밀 awk 말고는 sudo 를 쓰지 않는 판(보안 넷 2026-09-26, 복제본 자체 시험용).
#   root 가 필요한 갈래(T5 verify_backup · T6 운영 alerts.log 읽기 · T8 운영 저장소 ls · T10 운영 dry-run)는 건너뜀으로 센다.
#   기본값(비움)은 전과 같다.
NO_ROOT="${F69_NO_ROOT:-}"
SITE="http://127.0.0.1:$PORT"
BASE="$SITE/tbox"
UA="tbox-review/$(date +%F)"
UA_HUMAN="Mozilla/5.0 (X11; Linux x86_64) F69-human"
SRC_DB="tbox_re"          # 운영 — **읽기만** 한다
DB="tbox_f69_test"        # 임시 — 여기에만 쓴다
ADMIN="claude_admin01"
F69_BASE="${F69_BASE:-v2.19.0}"
OUT="${1:-$HOME/.claude/ui-shots/f69}"
PROD_ALERTS="/var/log/tbox-v2/alerts.log"
PROD_OLD_LINES=889        # 지시서 머리 실측(2026-09-24) — 이 줄들은 origin 이 없는 옛 줄이다
TAG="$(date +%H%M%S)"
R="claude_f69r${TAG}"     # 검토 계정 + 표식 → claude-review
H="f69h${TAG}"            # 사람 계정 → human
P="claude_f69p${TAG}"     # 검토 계정인데 표식 없음 → human + partial
X="f69x${TAG}<img src=x onerror=1>"   # 화면 주입 시험용 행위자 이름(AL-18) — 요소가 되면 안 된다
JAR_DIR="$(mktemp -d)"
WORK="$(mktemp -d)"
RWORK="$(mktemp -d)"      # root 가 쓰는 파일(verify_backup 실패 횟수 등) — 정리는 sudo 로
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
note() { printf '  · %s\n' "$1"; }
skip() { printf '  ○ SKIP %s  (%s)\n' "$1" "$2"; SKIP=$((SKIP+1)); }

cleanup() {
  if [ -n "$SERVER_PID" ]; then kill "$SERVER_PID" 2>/dev/null; wait "$SERVER_PID" 2>/dev/null; fi
  sudo -n -u postgres dropdb --if-exists "$DB" >/dev/null 2>&1
  [ -n "$INST" ] && rm -rf "$INST"
  if [ -n "$NO_ROOT" ]; then rm -rf "$RWORK"; else sudo -n rm -rf "$RWORK" 2>/dev/null; fi
  rm -rf "$JAR_DIR" "$WORK"
}
trap cleanup EXIT

# ─── 임시 DB 에만 쓰는 psql (운영 DB 이름은 여기에 들어가지 않는다) ───
psqlt() { sudo -n -u postgres psql -d "$DB" -t -A -c "$1" 2>/dev/null; }
# 운영 DB **읽기** 전용 — T8 한 곳에서만 쓴다
psqlp() { sudo -n -u postgres psql -d "$SRC_DB" -t -A -c "$1" 2>/dev/null; }
mark()  { psqlt "SELECT coalesce(max(id),0) FROM audit_logs"; }

review_password() { sudo -n awk -v u="$1" '$1==u{print $3}' /etc/tbox-v2/seed-accounts.txt 2>/dev/null; }

jq_py() {   # 표준입력 = JSON, $1 = python 식 (d 가 파싱 결과)
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

# 경보 줄 하나 찾기 — $1 alerts.log  $2 규칙  $3 evidence 키  $4 값 → "origin|hint|제목모양|키"
#   제목모양: claude = `[Claude] ` + rule_text · plain = rule_text 그대로 · other = 그 밖
#   키: keys = origin·title 둘 다 있음 · nokeys = 없음(옛 모양)
cat > "$WORK/line_of.py" <<'PY'
import json, sys
path, rule, field, value = sys.argv[1:5]
hit = None
try:
    fh = open(path, encoding='utf-8')
except Exception:
    print('NOFILE'); sys.exit(0)
for raw in fh:
    raw = raw.strip()
    if not raw:
        continue
    try:
        d = json.loads(raw)
    except Exception:
        continue
    if d.get('rule') != rule:
        continue
    ev = d.get('evidence') or {}
    if str(ev.get(field, '')) == value:
        hit = d
if hit is None:
    print('MISSING'); sys.exit(0)
ev = hit.get('evidence') or {}
rt = ev.get('rule_text') or hit.get('rule')
t = hit.get('title')
tk = 'claude' if t == '[Claude] ' + str(rt) else ('plain' if t == rt else 'other')
print('%s|%s|%s|%s' % (hit.get('origin'), ev.get('origin_hint') or '-', tk,
                       'keys' if ('origin' in hit and 'title' in hit) else 'nokeys'))
PY
line_of() { python3 "$WORK/line_of.py" "$INST/log/alerts.log" "$1" "$2" "$3"; }

# ════════════════════════════════════════════════════════════════
hr "F-69 — 시작 전 확인 · 임시 인스턴스 준비 (운영 DB 에는 쓰지 않는다)"

avail_kb=$(df -Pk / | awk 'NR==2{print $4}')
echo "루트 파티션 여유: $(df -Ph / | awk 'NR==2{print $4}') (${avail_kb}KB)"
[ "$avail_kb" -ge 1048576 ] || { echo "루트 디스크 여유 1GB 미만 — 시험을 시작하지 않는다 (SECURITY R-80)"; exit 3; }
review_account "$ADMIN" || { echo "$ADMIN 이 검토 계정 판정에 맞지 않는다 — 중단"; exit 3; }
review_account "$R" && review_account "$P" && ! review_account "$H" \
  || { echo "가짜 행위자 이름이 검토 판정과 어긋난다 — 중단"; exit 3; }

sudo -n -u postgres psql -d "$SRC_DB" -tAc "SELECT 1" >/dev/null 2>&1 \
  || { echo "psql(postgres) 을 쓸 수 없다 — 이 시험은 임시 DB 가 없으면 성립하지 않는다"; exit 3; }
note "운영 최근 10분 비-검토 actor 행: $(psqlp "SELECT count(*) FROM audit_logs WHERE happened_at > now() - interval '10 minutes' AND actor_name IS NOT NULL AND $REVIEW_NOT_SQL") (참고 — 이 시험은 운영 DB 에 쓰지 않는다)"

sub "T6 준비 — 운영 alerts.log 앞 ${PROD_OLD_LINES}줄의 sha256 (읽기만)"
if [ -z "$NO_ROOT" ]; then
T6_N0=$(sudo -n wc -l < "$PROD_ALERTS" 2>/dev/null)
T6_H0=$(sudo -n head -n "$PROD_OLD_LINES" "$PROD_ALERTS" 2>/dev/null | sha256sum | cut -c1-64)
fi
note "운영 alerts.log 줄 수 ${T6_N0:-?} · 앞 ${PROD_OLD_LINES}줄 sha256 ${T6_H0:-?}"

sub "① 운영 DB 를 **읽어** 임시 DB 를 만든다"
# 덤프를 파일로 두지 않는다 — 운영 전체 평문 덤프(비밀번호 해시·TOTP 비밀 포함)가 /tmp 에 postgres umask(세계 읽기)로
# 잠시라도 남는 길을 없앤다(개발 대기열 2d · 점검 봇 1-B-2). pg_dump 의 표준출력을 그대로 psql 의 표준입력으로.
sudo -n -u postgres dropdb --if-exists "$DB" >/dev/null 2>&1
sudo -n -u postgres createdb -O tbox_owner "$DB" >/dev/null 2>&1 || { echo "임시 DB 를 만들 수 없다"; exit 3; }
sudo -n -u postgres pg_dump -d "$SRC_DB" 2>/dev/null \
  | sudo -n -u postgres psql -q -d "$DB" -v ON_ERROR_STOP=0 >/dev/null 2>"$WORK/restore.err"
DUMP_RC=${PIPESTATUS[0]}
# pg_dump 가 실패하면 예전처럼 rc 3(임시 DB 는 반쯤 찼을 수 있다 — cleanup trap 이 dropdb 로 지운다)
[ "$DUMP_RC" -eq 0 ] || { echo "운영 DB 를 읽지 못했다 (pg_dump rc $DUMP_RC — 141 이면 psql 쪽이 먼저 끝났다)"; head -2 "$WORK/restore.err" 2>/dev/null | sed 's/^/   /'; exit 3; }
n_users=$(psqlt "SELECT count(*) FROM public.users")
[ -n "$n_users" ] && [ "$n_users" -gt 0 ] 2>/dev/null || { echo "임시 DB 로 옮기지 못했다 — $(head -2 "$WORK/restore.err")"; exit 3; }
echo "  · 임시 DB $DB — 계정 ${n_users}명"
# 반영 순서가 DB → 코드인 마이그레이션은 임시 DB 에도 먼저 넣는다(이미 있으면 아무 일도 없다 — IF NOT EXISTS).
#   보안 넷 ④(2026-09-26): 새 코드의 계정 만들기(2b 201 갈래)가 users.temp_password_issued_at 에 쓴다.
for m in 2026-10-04_users_temp_password_issued_at.sql; do
  [ -f "$ROOT/db/migrations/$m" ] || continue
  sudo -n -u postgres psql -X -q -d "$DB" -v ON_ERROR_STOP=1 -f - < "$ROOT/db/migrations/$m" 2>&1 | grep -v 'could not change directory' | sed 's/^/    /'
done

sub "② 저장소를 임시 폴더로 복사하고 두 번째 인스턴스를 띄운다"
INST="$(mktemp -d)"
mkdir -p "$INST/app" "$INST/storage" "$INST/quarantine" "$INST/tmp" "$INST/log"
tar -C "$ROOT" -cf - --exclude=.git --exclude=jar --exclude=api-test.txt --exclude=./.env . | tar -C "$INST/app" -xf -
# 임시 `.env` — 원본에서 **DB 이름·저장 경로·로그 경로만** 바꾼다. 값은 출력하지 않는다.
# qcheck-allow: R20 — 임시 인스턴스용 사본(0700·0600 · trap 정리) · kill -9 뒤 남는 창은 받아들인 위험(HO-B1-004)
sed -e "s#^DB_NAME=.*#DB_NAME=$DB#" \
    -e "s#^STORAGE_DIR=.*#STORAGE_DIR=$INST/storage#" \
    -e "s#^AUDIT_LOG_FILE=.*#AUDIT_LOG_FILE=$INST/log/audit.log#" \
    "${QF2_ENV_SRC:-$ROOT/.env}" > "$INST/app/.env"
chmod 600 "$INST/app/.env"
grep -q "^DB_NAME=$DB\$" "$INST/app/.env" || { echo "임시 .env 에 임시 DB 이름이 들어가지 않았다 — 중단"; exit 4; }
grep -q "^AUDIT_LOG_FILE=$INST/log/audit.log\$" "$INST/app/.env" || { echo "임시 .env 의 로그 경로가 임시가 아니다 — 중단"; exit 4; }
# 로그 두 파일을 **이 사용자**가 먼저 만든다 — 뒤에 root(verify_backup)가 덧붙여도 주인이 바뀌지 않게.
touch "$INST/log/audit.log" "$INST/log/alerts.log"

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
READY=""
for _ in 1 2 3 4 5 6 7 8 9 10; do
  sleep 1
  READY=$(curl -s -o /dev/null -w '%{http_code}' -A "$UA" "$BASE/api/me")
  [ "$READY" = "401" ] && break
done
expect "임시 인스턴스가 떴다 (미로그인 /api/me 401)" "401" "$READY"
[ "$READY" = "401" ] || { echo "인스턴스를 띄우지 못했다 — 중단"; tail -5 "$INST/server.log"; exit 4; }
expect "임시 인스턴스의 격리 폴더 = 저장소의 형제 (cli_quarantine_dir)" "$(realpath "$INST")/quarantine" \
  "$(cd "$INST/app" && php -r 'require "scripts/lib/cli_bootstrap.php"; echo cli_quarantine_dir();' 2>/dev/null)"

# ════════════════════════════════════════════════════════════════
hr "T1 — Origin 단위 시험 (지시서 A-1 표의 칸마다 한 줄)"

T1_OUT=$(php -r '
require $argv[1] . "/src/Origin.php";
$bad = 0; $n = 0;
$eq = function (string $what, $want, $got) use (&$bad, &$n) {
    $n++;
    $ok = $want === $got;
    if (!$ok) { $bad++; }
    printf("%s %s  기대=%s 실제=%s\n", $ok ? "OK " : "BAD", $what, json_encode($want, JSON_UNESCAPED_UNICODE), json_encode($got, JSON_UNESCAPED_UNICODE));
};
$o = fn(array $r) => $r["origin"] . "|" . ($r["origin_hint"] ?? "-");
$M = "tbox-review/2026-09-24";
// ── 앱 행동 한 건 (AL-5) — 계정 그리고 표식
$eq("AL-5 claude_ + 표식",            "claude-review|-",   $o(Origin::ofAppAction("claude_admin01", $M)));
$eq("AL-5 qa_ + 표식",                "claude-review|-",   $o(Origin::ofAppAction("qa_admin01", $M)));
$eq("AL-5 사람 계정 + 표식 → partial", "human|partial",     $o(Origin::ofAppAction("admin01", $M)));
$eq("AL-5 qa_ + 표식 없음 → partial",  "human|partial",     $o(Origin::ofAppAction("qa_employee01", "Mozilla/5.0")));
$eq("AL-5 사람 + 표식 없음",           "human|-",           $o(Origin::ofAppAction("admin01", "Mozilla/5.0")));
$eq("AL-5 계정 없음 + 표식 없음",       "human|-",           $o(Origin::ofAppAction(null, null)));
$eq("표식 대소문자(TBOX-REVIEW/) 는 표식 아님", "human|partial", $o(Origin::ofAppAction("claude_x", "TBOX-REVIEW/1")));
$eq("접두만 닮은 이름(qaemployee) 은 검토 아님", "human|partial", $o(Origin::ofAppAction("qaemployee", $M)));
// ── 묶음 규칙 (AL-1·10·12·13·18·20 = 행위자 하나 · AL-2 = 겨눈 계정들 · AL-19·21 = 올린 사람들)
foreach (["AL-1", "AL-10", "AL-12", "AL-13", "AL-18", "AL-20"] as $rule) {
    $eq("$rule 검토 계정 + 모든 행 표식",   "claude-review|-", $o(Origin::ofGroup(["claude_employee01"], true)));
    $eq("$rule 검토 계정 + 표식 없는 행 섞임", "human|partial", $o(Origin::ofGroup(["claude_employee01"], false)));
    $eq("$rule 사람 계정 + 모든 행 표식",   "human|partial",   $o(Origin::ofGroup(["employee01"], true)));
    $eq("$rule 사람 계정 + 표식 없음",      "human|-",         $o(Origin::ofGroup(["employee01"], false)));
}
$eq("AL-13·18·20 행위자 없음(null) + 표식 없음", "human|-", $o(Origin::ofGroup([null], false)));
$eq("AL-2 겨눈 계정 전부 검토 + 전부 표식",  "claude-review|-", $o(Origin::ofGroup(["qa_admin01", "claude_admin01"], true)));
$eq("AL-2 사람 계정이 하나라도 섞임",        "human|partial",   $o(Origin::ofGroup(["claude_admin01", "admin01"], true)));
$eq("AL-2 대상 이름 없음(빈 글자)",          "human|partial",   $o(Origin::ofGroup([""], true)));
$eq("AL-2 줄바꿈 섞인 이름 조각(evil / qa_x)", "human|partial", $o(Origin::ofGroup(["evil", "qa_x"], true)));
$eq("AL-19 올린 사람 검토 + 표식",           "claude-review|-", $o(Origin::ofGroup(["claude_employee01"], true)));
$eq("AL-19 올린 행 못 찾음(빈 이름·표식 없음)", "human|-",       $o(Origin::ofGroup([""], false)));
$eq("AL-21 올린 사람 사람 + 표식 없음",       "human|-",         $o(Origin::ofGroup(["admin01"], false)));
$eq("묶음 계정 목록이 비면 검토 아님",        "human|partial",   $o(Origin::ofGroup([], true)));
// ── 고정 값
$eq("AL-6 → system",  "system|-", $o(Origin::ofFixedRule("AL-6")));
$eq("AL-7 → system",  "system|-", $o(Origin::ofFixedRule("AL-7")));
$eq("AL-8 → system",  "system|-", $o(Origin::ofFixedRule("AL-8")));
$eq("AL-3 → 늘 human", "human|-", $o(Origin::ofFixedRule("AL-3")));
$eq("AL-1 은 고정 값이 아니다", null, Origin::ofFixedRule("AL-1"));
$eq("옛 규칙 AL-14 는 판정하지 않는다", null, Origin::ofFixedRule("AL-14"));
// ── AL-9 sudo 명령 (지시서 T3 예 + 모호하면 human)
$s = fn(string $c, bool $complete = true) => Origin::ofSudoCommand($c, $complete)["origin"];
$eq("AL-9 dropdb --if-exists tbox_4d_test",       "claude-session", $s("/usr/bin/dropdb --if-exists tbox_4d_test"));
$eq("AL-9 psql -d tbox_re -c SELECT 1 (운영 읽기)", "human",          $s("/usr/bin/psql -d tbox_re -c SELECT 1"));
$eq("AL-9 psql -d qcheck_17 …",                    "claude-session", $s("/usr/bin/psql -d qcheck_17 -t -A -c SELECT count(*) FROM audit_logs"));
$eq("AL-9 rm -rf /var/lib/x (DB 없음)",            "human",          $s("/usr/bin/rm -rf /var/lib/x"));
$eq("AL-9 psql -d comm_check",                     "claude-session", $s("/usr/bin/psql -d comm_check -c SELECT 1"));
$eq("AL-9 psql -d x_scratch · x_test2",            "claude-session", $s("/usr/bin/psql -d a_scratch -d b_test2 -c SELECT 1"));
$eq("AL-9 시험 DB 와 운영 DB 를 함께 겨눔",         "human",          $s("/usr/bin/psql -d qcheck_1 -d tbox_re"));
$eq("AL-9 시험 DB 에 붙어 DROP DATABASE 운영",      "human",          $s("/usr/bin/psql -d qcheck_1 -c DROP DATABASE tbox_re"));
$eq("AL-9 메타 명령(\\c 다른 DB)",                  "human",          $s("/usr/bin/psql -d qcheck_1 -c \\c tbox_re"));
$eq("AL-9 pg_dump 운영 읽기",                       "human",          $s("/usr/bin/pg_dump -d tbox_re -f /tmp/x.sql"));
$eq("AL-9 pg_dump 위치 인자가 -d 를 덮음",          "human",          $s("/usr/bin/pg_dump -d x_test tbox_re"));
$eq("AL-9 createdb -T 운영 복사",                   "human",          $s("/usr/bin/createdb -T tbox_re x_test"));
$eq("AL-9 pg_restore -C (아카이브 안 이름)",         "human",          $s("/usr/bin/pg_restore -C -d postgres /tmp/a.dump"));
// 독립 검토 F-69 M-1 · L-1 · L-2 — 스크립트·출력 파일·표준입력·가짜 경로는 겨눈 DB 를 모른다 → human
$eq("AL-9 psql -f 스크립트(내용 안 보임 · M-1)",     "human",          $s("/usr/bin/psql -d qcheck_1 -f /tmp/evil.sql"));
$eq("AL-9 psql --file= 스크립트(M-1)",               "human",          $s("/usr/bin/psql -d qcheck_1 --file=/tmp/evil.sql"));
$eq("AL-9 psql 묶음 -qf(M-1)",                       "human",          $s("/usr/bin/psql -d qcheck_1 -qf /tmp/evil.sql"));
$eq("AL-9 psql -c 없음 = 표준입력 스크립트(M-1)",     "human",          $s("/usr/bin/psql -d qcheck_1"));
$eq("AL-9 설치 자리 밖 /tmp/psql(L-1)",              "human",          $s("/tmp/psql -d qcheck_1 -c SELECT 1"));
$eq("AL-9 psql -o 서버 파일 쓰기(L-2)",              "human",          $s("/usr/bin/psql -d qcheck_1 -c SELECT 1 -o /etc/cron.d/x"));
$eq("AL-9 psql -L 로그 파일(L-2)",                   "human",          $s("/usr/bin/psql -d qcheck_1 -c SELECT 1 -L /tmp/l"));
$eq("AL-9 psql --command= 시험 DB",                  "claude-session", $s("/usr/bin/psql -d qcheck_1 --command=SELECT"));
$eq("AL-9 /usr/lib/postgresql/14/bin/psql 시험 DB",  "claude-session", $s("/usr/lib/postgresql/14/bin/psql -d qcheck_1 -c SELECT 1"));
$eq("AL-9 psql -d 없음(기본 DB)",                   "human",          $s("/usr/bin/psql -c SELECT 1"));
$eq("AL-9 sh -c 감싸개",                            "human",          $s("/bin/sh -c dropdb x_test"));
$eq("AL-9 접속 문자열(이름 모양 아님)",             "human",          $s("/usr/bin/psql -d postgresql://h/x_test"));
$eq("AL-9 200자에서 잘린 명령",                     "human",          $s("/usr/bin/dropdb x_test", false));
$eq("isScratchDatabase(tbox_re)",   false, Origin::isScratchDatabase("tbox_re"));
$eq("isScratchDatabase(tbox_f69_test)", true, Origin::isScratchDatabase("tbox_f69_test"));
// ── 제목
$eq("title claude-review",  "[Claude] 규칙", Origin::title("claude-review", "규칙"));
$eq("title claude-session", "[Claude] 규칙", Origin::title("claude-session", "규칙"));
$eq("title system",         "규칙",          Origin::title("system", "규칙"));
$eq("title human",          "규칙",          Origin::title("human", "규칙"));
$eq("값 넷", ["human", "system", "claude-review", "claude-session"], Origin::VALUES);
$eq("isValue(robot) 아님", false, Origin::isValue("robot"));
// ── F-71 감사 화면 판정은 뜻이 그대로 (ofAuditRow)
$eq("ofAuditRow 로그인 성공은 대상 계정", "claude-review|-", $o(Origin::ofAuditRow(null, $M, "auth.login", "allow", "claude_admin01")));
$eq("ofAuditRow 로그인 실패는 행위자만",  "human|partial",   $o(Origin::ofAuditRow(null, $M, "auth.login", "deny", "claude_admin01")));
printf("합계 %d 칸 · 어긋남 %d\n", $n, $bad);
' "$ROOT" 2>&1)
printf '%s\n' "$T1_OUT" | sed 's/^/    | /'
expect "T1 어긋난 칸 0" "0" "$(printf '%s\n' "$T1_OUT" | sed -n 's/^합계 [0-9]* 칸 · 어긋남 \([0-9]*\)$/\1/p')"
T1_N=$(printf '%s\n' "$T1_OUT" | sed -n 's/^합계 \([0-9]*\) 칸.*/\1/p')
expect "T1 칸 수가 60 이상 (표를 다 돌았다)" "yes" "$([ "${T1_N:-0}" -ge 60 ] 2>/dev/null && echo yes || echo "no(${T1_N:-?})")"

# ════════════════════════════════════════════════════════════════
hr "T2 — 규칙별 가짜 감사 행 두 벌(검토 계정·사람 계정) → alerts.php → 줄마다 origin·title"

cat > "$WORK/t2.sql" <<SQL
\\set ON_ERROR_STOP 1
-- AL-1: admin-only 접근 거부 5회 × (검토 · 사람 · 한쪽만)
INSERT INTO audit_logs (request_id, actor_name, actor_role, action, target_type, target_name, access_level, result, reason, ip, user_agent, summary)
SELECT gen_random_uuid(), a.name, 'Employee', 'file.download', 'file', 'F69-al1.txt', 'admin-only', 'deny', 'role_not_permitted', '10.69.1.1', a.ua, 'F69 시험 행'
  FROM (VALUES ('$R', '$UA'), ('$H', '$UA_HUMAN'), ('$P', '$UA_HUMAN')) AS a(name, ua), generate_series(1, 5);
-- AL-2: 출발 IP 하나에서 로그인 실패 20회 (대상 계정 = target_name)
INSERT INTO audit_logs (request_id, action, target_type, target_name, result, reason, ip, user_agent, summary)
SELECT gen_random_uuid(), 'auth.login', 'user', a.acct, 'deny', 'bad_credentials', a.ip::inet, a.ua, 'F69 시험 행'
  FROM (VALUES ('$R', '127.0.0.1', '$UA'), ('$R', '10.69.2.1', '$UA'), ('$H', '10.69.2.2', '$UA_HUMAN')) AS a(acct, ip, ua), generate_series(1, 20);
-- AL-5: admin-only → public 하향 한 건씩
INSERT INTO audit_logs (request_id, actor_name, action, target_type, target_id, target_name, access_level, result, ip, user_agent, detail, summary)
SELECT gen_random_uuid(), a.name, 'file.level_change', 'file', a.fid, a.fname, 'public', 'allow', '10.69.5.1', a.ua,
       '{"before":{"access_level":"admin-only"},"after":{"access_level":"public"}}'::jsonb, 'F69 시험 행'
  FROM (VALUES ('$R', 9690051, 'F69-al5-r.txt', '$UA'), ('$H', 9690052, 'F69-al5-h.txt', '$UA_HUMAN')) AS a(name, fid, fname, ua);
-- AL-10: login_ref 셋
INSERT INTO audit_logs (request_id, actor_name, action, result, ip, user_agent, detail, summary)
SELECT gen_random_uuid(), a.name, 'file.list', 'allow', '10.69.10.1', a.ua, jsonb_build_object('login_ref', 'f69ref' || g || a.k), 'F69 시험 행'
  FROM (VALUES ('$R', '$UA', 'r'), ('$H', '$UA_HUMAN', 'h')) AS a(name, ua, k), generate_series(1, 3) AS g;
-- AL-12: file.fetch 거부 private_ip 5회
INSERT INTO audit_logs (request_id, actor_name, action, result, reason, ip, user_agent, summary)
SELECT gen_random_uuid(), a.name, 'file.fetch', 'deny', 'private_ip', '10.69.12.1', a.ua, 'F69 시험 행'
  FROM (VALUES ('$R', '$UA'), ('$H', '$UA_HUMAN')) AS a(name, ua), generate_series(1, 5);
-- AL-13: 권한 매트릭스 변경 한 건
INSERT INTO audit_logs (request_id, actor_name, action, target_type, target_name, result, ip, user_agent, detail, summary)
SELECT gen_random_uuid(), a.name, 'permission.change', 'system', 'Employee|team', 'allow', '10.69.13.1', a.ua, '{"reason_text":"F69"}'::jsonb, 'F69 시험 행'
  FROM (VALUES ('$R', '$UA'), ('$H', '$UA_HUMAN')) AS a(name, ua);
-- AL-18: 허용 사이트 변경 한 건 (+ 화면 주입 시험용 행위자 이름)
INSERT INTO audit_logs (request_id, actor_name, action, target_type, target_name, result, ip, user_agent, detail, summary)
SELECT gen_random_uuid(), a.name, 'fetch_host.change', 'system', 'f69.example.invalid', 'allow', '10.69.18.1', a.ua, '{"reason_text":"F69"}'::jsonb, 'F69 시험 행'
  FROM (VALUES ('$R', '$UA'), ('$H', '$UA_HUMAN'), ('$X', '$UA_HUMAN')) AS a(name, ua);
-- AL-19: 올린 행(file.upload allow) 먼저, 그 다음 검사 행(file.scan deny infected, 행위자 없음)
INSERT INTO audit_logs (request_id, actor_name, action, target_type, target_id, target_name, access_level, result, ip, user_agent, summary)
SELECT gen_random_uuid(), a.name, 'file.upload', 'file', a.fid, a.fname, 'internal', 'allow', '10.69.19.1', a.ua, 'F69 시험 행'
  FROM (VALUES ('$R', 9690191, 'F69-al19-r-$TAG.txt', '$UA'), ('$H', 9690192, 'F69-al19-h-$TAG.txt', '$UA_HUMAN')) AS a(name, fid, fname, ua);
INSERT INTO audit_logs (request_id, action, target_type, target_id, target_name, access_level, result, reason, detail, summary)
SELECT gen_random_uuid(), 'file.scan', 'file', a.fid, a.fname, 'internal', 'deny', 'infected',
       jsonb_build_object('signature', 'Eicar-Signature', 'uploader_id', 1), 'F69 시험 행'
  FROM (VALUES (9690191, 'F69-al19-r-$TAG.txt'), (9690192, 'F69-al19-h-$TAG.txt')) AS a(fid, fname);
-- AL-20: 개인 토큰 발급 한 건
INSERT INTO audit_logs (request_id, actor_name, action, target_type, target_name, result, ip, user_agent, detail, summary)
SELECT gen_random_uuid(), a.name, 'api_token.issue', 'user', a.name, 'allow', '10.69.20.1', a.ua,
       '{"token_name":"F69","token_scope":"read","reason_text":"F69"}'::jsonb, 'F69 시험 행'
  FROM (VALUES ('$R', '$UA'), ('$H', '$UA_HUMAN')) AS a(name, ua);
-- AL-21: 실행 파일이 든 압축본 확인 업로드 한 건
INSERT INTO audit_logs (request_id, actor_name, action, target_type, target_id, target_name, access_level, result, ip, user_agent, detail, summary)
SELECT gen_random_uuid(), a.name, 'file.upload_exec', 'file', a.fid, a.fname, 'admin-only', 'allow', '10.69.21.1', a.ua,
       '{"exec_entry_count":1}'::jsonb, 'F69 시험 행'
  FROM (VALUES ('$R', 9690211, 'F69-al21-r-$TAG.zip', '$UA'), ('$H', 9690212, 'F69-al21-h-$TAG.zip', '$UA_HUMAN')) AS a(name, fid, fname, ua);
SQL
# 임시 DB 의 **최근 2시간** 감사 행을 지운다(임시 DB 만 — 운영에서 옮겨 온 진짜 행이 규칙에 걸려 줄이 섞이지 않게).
# 그러면 이 실행의 줄만 남아 T4·T9 의 「마지막 50줄」 안에 전부 들어온다.
psqlt "DELETE FROM audit_logs WHERE happened_at > now() - interval '2 hours'" >/dev/null
M_ALL=$(mark)
M_T2="$M_ALL"
sudo -n -u postgres psql -q -d "$DB" -f - < "$WORK/t2.sql" > "$WORK/t2.sqlout" 2>&1
expect "T2 가짜 감사 행 넣기 rc" "0" "$?"
sed 's/^/    | /' "$WORK/t2.sqlout" | head -5
: > "$WORK/empty-auth.log"
php "$INST/app/scripts/alerts.php" --state "$WORK/state-t2.json" --auth-log "$WORK/empty-auth.log" > "$WORK/t2.out" 2>&1
expect "T2 alerts.php 종료 코드" "0" "$?"
grep -E "★|대상" "$WORK/t2.out" | sed 's/^/    | /'

for rule in AL-1 AL-10 AL-12 AL-13 AL-18 AL-20; do
  expect "T2 $rule 검토 계정 + 표식 → claude-review · [Claude] 제목" "claude-review|-|claude|keys" "$(line_of "$rule" subject "$R")"
  expect "T2 $rule 사람 계정 → human · 제목 그대로"                 "human|-|plain|keys"          "$(line_of "$rule" subject "$H")"
done
expect "T2 AL-1 검토 계정인데 표식 없음 → human + partial" "human|partial|plain|keys" "$(line_of AL-1 subject "$P")"
expect "T2 AL-2 루프백 IP(검토 계정만 겨눔 + 표식)" "claude-review|-|claude|keys" "$(line_of AL-2 subject 127.0.0.1)"
expect "T2 AL-2 바깥 IP(검토 계정 이름 + 표식 UA 흉내) → human + partial(대상 이름·UA 는 공격자가 정한다)" "human|partial|plain|keys" "$(line_of AL-2 subject 10.69.2.1)"
expect "T2 AL-2 IP(사람 계정)"                 "human|-|plain|keys"          "$(line_of AL-2 subject 10.69.2.2)"
expect "T2 AL-5 검토 계정 한 건"               "claude-review|-|claude|keys" "$(line_of AL-5 actor "$R")"
expect "T2 AL-5 사람 계정 한 건"               "human|-|plain|keys"          "$(line_of AL-5 actor "$H")"
expect "T2 AL-19 검토 계정이 올린 감염 파일"   "claude-review|-|claude|keys" "$(line_of AL-19 subject "F69-al19-r-$TAG.txt")"
expect "T2 AL-19 사람이 올린 감염 파일"        "human|-|plain|keys"          "$(line_of AL-19 subject "F69-al19-h-$TAG.txt")"
expect "T2 AL-21 검토 계정"                    "claude-review|-|claude|keys" "$(line_of AL-21 subject "F69-al21-r-$TAG.zip")"
expect "T2 AL-21 사람 계정"                    "human|-|plain|keys"          "$(line_of AL-21 subject "F69-al21-h-$TAG.zip")"

# ════════════════════════════════════════════════════════════════
hr "T3 — 사본 auth.log (--auth-log) · 서버 층 AL-3 · AL-9"

AUTHLOG="$WORK/auth.log.f69"
# 계정을 줄마다 다르게 둔다 — AL-9 의 중복 억제 키가 `AL-9|계정` 이라 같은 계정이면 한 실행에 한 줄만 발동한다.
cat > "$AUTHLOG" <<LOG
Sep 24 10:00:01 f69host sshd[1111]: Accepted publickey for f69ssh${TAG} from 10.69.9.9 port 50000 ssh2: ED25519 SHA256:f69
Sep 24 10:00:02 f69host sudo:  f69s1${TAG} : TTY=pts/0 ; PWD=/tmp ; USER=postgres ; COMMAND=/usr/bin/dropdb --if-exists tbox_4d_test
Sep 24 10:00:03 f69host sudo:  f69s2${TAG} : TTY=pts/0 ; PWD=/tmp ; USER=postgres ; COMMAND=/usr/bin/psql -d tbox_re -c SELECT 1
Sep 24 10:00:04 f69host sudo:  f69s3${TAG} : TTY=pts/0 ; PWD=/tmp ; USER=postgres ; COMMAND=/usr/bin/psql -d qcheck_17 -c SELECT 1
Sep 24 10:00:05 f69host sudo:  f69s4${TAG} : TTY=pts/0 ; PWD=/tmp ; USER=root ; COMMAND=/usr/bin/find /var/lib/x -delete
Sep 24 10:00:08 f69host sudo:  f69s7${TAG} : TTY=pts/0 ; PWD=/tmp ; USER=root ; COMMAND=/usr/bin/rm -rf /var/lib/x
Sep 24 10:00:09 f69host sudo:  f69s0${TAG} : TTY=pts/0 ; PWD=/tmp ; USER=root ; COMMAND=/bin/rm -f /var/log/tbox-v2/audit.log
Sep 24 10:00:10 f69host sudo:  f69s8${TAG} : TTY=pts/0 ; PWD=/tmp ; USER=root ; COMMAND=/usr/bin/rmdir /var/lib/x
Sep 24 10:00:11 f69host sudo:  f69s9${TAG} : TTY=pts/0 ; PWD=/tmp ; USER=root ; COMMAND=/usr/bin/firmware --list
Sep 24 10:00:06 f69host sudo:  f69s5${TAG} : TTY=pts/0 ; PWD=/tmp ; USER=postgres ; COMMAND=/usr/bin/psql -d qcheck_18 -c SELECT 1 PGPASSWORD=f69secretXYZ
Sep 24 10:00:07 f69host sudo:  f69s6${TAG} : TTY=pts/0 ; PWD=/tmp ; USER=postgres ; COMMAND=/usr/bin/psql -d tbox_re -c CREATE USER f69u WITH PASSWORD 'f69secretABC'
LOG
M_T3=$(mark)
for rule in AL-3 AL-9; do
  php "$INST/app/scripts/alerts.php" --rule "$rule" --auth-log "$AUTHLOG" --state "$WORK/state-$rule.json" \
    > "$WORK/t3-$rule.out" 2>&1
  expect "T3 alerts.php --rule $rule 종료 코드" "0" "$?"
  sed 's/^/    | /' "$WORK/t3-$rule.out"
done
expect "T3 AL-3 SSH 새 출처 → human(늘)"            "human|-|plain|keys"          "$(line_of AL-3 user "f69ssh${TAG}")"
expect "T3 AL-9 dropdb --if-exists tbox_4d_test"      "claude-session|-|claude|keys" "$(line_of AL-9 user "f69s1${TAG}")"
expect "T3 AL-9 psql -d tbox_re (운영 읽기) → human"  "human|-|plain|keys"          "$(line_of AL-9 user "f69s2${TAG}")"
expect "T3 AL-9 psql -d qcheck_17"                    "claude-session|-|claude|keys" "$(line_of AL-9 user "f69s3${TAG}")"
expect "T3 AL-9 DB 이름 없는 명령(find -delete) → human" "human|-|plain|keys"        "$(line_of AL-9 user "f69s4${TAG}")"
# ★ auth.log 의 COMMAND 는 늘 절대 경로(/usr/bin/rm)다. 옛 발동 정규식 `(^|\s)rm\s` 는 앞이 `/` 인 rm 을 못 잡았다
#   (R-100 남은 틈). 보안 넷(2026-09-26)이 `(^|\/|\s)rm\s` 로 고쳤다 — 절대 경로 rm 은 울리고, rm 이 이름 속에 든 명령
#   (rmdir·firmware)은 뒤의 공백 조건 때문에 그대로 조용하다.
expect "T3 AL-9 /usr/bin/rm -rf → 발동 · human(DB 없음)"  "human|-|plain|keys"          "$(line_of AL-9 user "f69s7${TAG}")"
expect "T3 AL-9 /bin/rm -f (감사 로그) → 발동 · human"     "human|-|plain|keys"          "$(line_of AL-9 user "f69s0${TAG}")"
expect "T3 AL-9 /usr/bin/rmdir → 울리지 않음(rm 뒤 공백 아님)" "MISSING"                  "$(line_of AL-9 user "f69s8${TAG}")"
expect "T3 AL-9 /usr/bin/firmware → 울리지 않음(이름 속 rm)"   "MISSING"                  "$(line_of AL-9 user "f69s9${TAG}")"
sub "T3 dry-run — 같은 사본 auth.log · 임시 상태 파일(새로) · --dry-run: 발동 목록만 보고 쓰지 않는다"
php "$INST/app/scripts/alerts.php" --rule AL-9 --dry-run --auth-log "$AUTHLOG" --state "$WORK/state-AL-9-dry.json" \
  > "$WORK/t3-dry.out" 2>&1
expect "T3 dry-run 종료 코드" "0" "$?"
T3D=$(grep -oE "\[dry-run\] AL-9 f69s[0-9]${TAG} " "$WORK/t3-dry.out" | sed -E "s/.*AL-9 f69(s[0-9])${TAG} /\1/" | sort | tr '\n' ' ')
expect "T3 dry-run AL-9 발동 계정(s0·s1~s7 · s8 rmdir·s9 firmware 없음)" "s0 s1 s2 s3 s4 s5 s6 s7 " "$T3D"
expect "T3 dry-run 이 임시 상태 파일을 만들지 않음" "no" "$([ -e "$WORK/state-AL-9-dry.json" ] && echo yes || echo no)"
expect "T3 AL-9 CREATE USER on tbox_re → human"       "human|-|plain|keys"          "$(line_of AL-9 user "f69s6${TAG}")"

# ════════════════════════════════════════════════════════════════
hr "T5 — verify_backup.sh 실패 경로 (--backup-root 빈 임시 폴더) · AL-8 origin system"

BK="$RWORK/backup"
if [ -n "$NO_ROOT" ]; then
  skip "T5 전체" "F69_NO_ROOT — verify_backup 은 root 로만 돈다"
else
PROD_FAIL_BEFORE=$(sudo -n cat /var/backups/tbox-v2/.fail_count 2>/dev/null || echo none)
if sudo -n test -r /etc/tbox-v2/backup.key; then
  sudo -n mkdir -p "$BK/db" "$BK/files"
  M_T5=$(mark)
  sudo -n bash "$INST/app/scripts/verify_backup.sh" --backup-root "$BK" > "$WORK/vb1.out" 2>&1; VB1=$?
  sudo -n bash "$INST/app/scripts/verify_backup.sh" --backup-root "$BK" > "$WORK/vb2.out" 2>&1; VB2=$?
  sed 's/^/    | /' "$WORK/vb2.out"
  expect "T5 두 번 모두 실패 종료(1)" "1|1" "$VB1|$VB2"
  expect "T5 연속 실패 횟수(임시 폴더)" "2" "$(sudo -n cat "$BK/.fail_count" 2>/dev/null)"
  expect "T5 AL-8 줄 — origin system · 제목 = rule_text · origin·title 키" "system|-|plain|keys" \
    "$(line_of AL-8 source verify_backup)"
  expect "T5 AL-8 줄의 키가 PHP 줄의 키(request_id 제외)를 다 가진다" "yes" "$(grep '"rule":"AL-8"' "$INST/log/alerts.log" | tail -1 | python3 -c '
import json,sys
d = json.loads(sys.stdin.read() or "{}")
need = {"raised_at","rule","evidence","dispatch","origin","title"}
print("yes" if need <= set(d) else "no " + ",".join(sorted(need - set(d))))' 2>/dev/null)"
  expect "T5 감사 alert.raised AL-8 detail.origin" "system" \
    "$(psqlt "SELECT detail->>'origin' FROM audit_logs WHERE id > ${M_T5:-0} AND action='alert.raised' AND target_name='AL-8' ORDER BY id DESC LIMIT 1")"
  expect "T5 운영 실패 횟수 파일 불변" "$PROD_FAIL_BEFORE" "$(sudo -n cat /var/backups/tbox-v2/.fail_count 2>/dev/null || echo none)"
  expect "T5 사용법 — 모르는 인자는 3" "3" "$(sudo -n bash "$INST/app/scripts/verify_backup.sh" --nope >/dev/null 2>&1; echo $?)"
  note "같은 파서(GET /api/admin/alerts) 로 읽히는지는 T9 브라우저 단계에서 본다"
else
  skip "T5 전체" "/etc/tbox-v2/backup.key 를 root 로 읽을 수 없다"
fi
fi   # NO_ROOT

# ════════════════════════════════════════════════════════════════
hr "T7 — AL-6 격리 파일 (임시 저장소 · 임시 격리 폴더 · 임시 DB 의 files 만)"

# 임시 DB 의 files 를 비운다(운영 행의 바이트는 임시 저장소에 없으므로 그대로 두면 전부 missing 이 된다).
psqlt "TRUNCATE files CASCADE" >/dev/null
expect "T7 임시 DB files 비움" "0" "$(psqlt "SELECT count(*) FROM files")"
UPL=$(psqlt "SELECT id FROM users WHERE username='claude_employee01'")
[ -n "$UPL" ] || UPL=$(psqlt "SELECT min(id) FROM users")

mkfile() {   # $1 이름표 $2 scan_status $3 살아있음(1/0) $4 저장소에 둔다(1/0) $5 격리에 둔다(1/0) $6 sha 를 틀리게(1/0)
  local label="$1" st="$2" alive="$3" in_s="$4" in_q="$5" badsha="${6:-0}" uuid stored sha del
  uuid=$(cat /proc/sys/kernel/random/uuid)
  stored="$uuid.txt"
  printf 'F-69 %s %s\n' "$label" "$TAG" > "$WORK/$label.bin"
  sha=$(sha256sum "$WORK/$label.bin" | cut -c1-64)
  [ "$badsha" = "1" ] && sha=$(printf 'x%s' "$sha" | sha256sum | cut -c1-64)
  del="NULL"; [ "$alive" = "0" ] && del="now()"
  psqlt "INSERT INTO files (original_name, stored_name, ext, mime, size_bytes, sha256, access_level, uploaded_by, scan_status, deleted_at)
         VALUES ('F69-$label.txt', '$stored', 'txt', 'text/plain', $(wc -c < "$WORK/$label.bin"), '$sha', 'internal', $UPL, '$st', $del)" >/dev/null
  if [ "$in_s" = "1" ]; then
    mkdir -p "$INST/storage/${uuid:0:2}/${uuid:2:2}"
    cp "$WORK/$label.bin" "$INST/storage/${uuid:0:2}/${uuid:2:2}/$stored"
  fi
  [ "$in_q" = "1" ] && cp "$WORK/$label.bin" "$INST/quarantine/$stored"
  printf '%s' "$stored"
}
S_OK=$(mkfile quarantined   infected 1 0 1)      # 살아 있음 + infected · 격리에 있음 · 저장소 없음 → 정상
S_INS=$(mkfile in_storage   infected 1 1 0)      # 격리 없음 · 저장소에 있음 → infected_in_storage
S_MIS=$(mkfile both_missing infected 1 0 0)      # 둘 다 없음 → quarantine_missing
S_TR=$(mkfile trash         infected 0 0 0)      # 휴지통 + infected · 격리 없음 → 보고만 trash_quarantine_missing
S_BAD=$(mkfile tampered     infected 1 0 1 1)    # 격리에 있는데 sha 가 다름 → quarantine_hash_mismatch
S_CLN=$(mkfile clean        clean    1 1 0)      # 지금 규칙 그대로(정상)
cp "$WORK/clean.bin" "$INST/quarantine/$(cat /proc/sys/kernel/random/uuid).txt"   # 행 없는 격리 파일 → 보고만 quarantine_orphan
note "만든 행: 정상격리 $S_OK · 저장소남음 $S_INS · 둘다없음 $S_MIS · 휴지통 $S_TR · 변조 $S_BAD · clean $S_CLN"

sub "첫 실행 — 불일치 셋(infected_in_storage · quarantine_missing · quarantine_hash_mismatch) + AL-6(system)"
M_T7=$(mark)
C1=$(cd "$INST/app" && php scripts/consistency_check.php --strict 2>&1); C1RC=$?
printf '%s\n' "$C1" | sed 's/^/    | /'
expect "T7-1 --strict 종료 코드(불일치)" "2" "$C1RC"
expect "T7-1 불일치 3건" "3" "$(psqlt "SELECT detail->>'mismatch_count' FROM audit_logs WHERE id > ${M_T7:-0} AND action='audit.integrity' ORDER BY id DESC LIMIT 1")"
contains "T7-1 infected_in_storage"     "[infected_in_storage] $S_INS"     "$C1"
contains "T7-1 quarantine_missing"      "[quarantine_missing] $S_MIS"      "$C1"
contains "T7-1 quarantine_hash_mismatch" "[quarantine_hash_mismatch] $S_BAD" "$C1"
expect "T7-1 정상 격리 파일은 불일치 목록에 없다" "0" "$(printf '%s\n' "$C1" | grep -cF "$S_OK")"
expect "T7-1 휴지통 감염 파일은 불일치가 아니다(보고만)" "0" "$(printf '%s\n' "$C1" | grep -F "$S_TR" | grep -c '^\s*\[')"
expect "T7-1 detail quarantined · quarantine_orphan · trash_quarantine_missing" "1|1|1" \
  "$(psqlt "SELECT concat_ws('|', detail->>'quarantined', detail->>'quarantine_orphan', detail->>'trash_quarantine_missing') FROM audit_logs WHERE id > ${M_T7:-0} AND action='audit.integrity' ORDER BY id DESC LIMIT 1")"
expect "T7-1 AL-6 줄 — origin system" "system|-|plain|keys" "$(line_of AL-6 source consistency_check)"
expect "T7-1 감사 alert.raised AL-6 detail.origin" "system" \
  "$(psqlt "SELECT detail->>'origin' FROM audit_logs WHERE id > ${M_T7:-0} AND action='alert.raised' AND target_name='AL-6' ORDER BY id DESC LIMIT 1")"

sub "두 번째 실행 — 불일치 셋을 치운 뒤(행·바이트 삭제, 임시만): 격리된 살아 있는 감염 파일만 남으면 불일치 0"
psqlt "DELETE FROM files WHERE stored_name IN ('$S_INS', '$S_MIS', '$S_BAD')" >/dev/null
rm -f "$INST/storage/${S_INS:0:2}/${S_INS:2:2}/$S_INS" "$INST/quarantine/$S_BAD"
M_T7B=$(mark)
C2=$(cd "$INST/app" && php scripts/consistency_check.php --strict 2>&1); C2RC=$?
printf '%s\n' "$C2" | sed 's/^/    | /'
expect "T7-2 --strict 종료 코드(통과)" "0" "$C2RC"
expect "T7-2 audit.integrity allow · 불일치 0 · 격리 1 · 고아 1(보고만) · 휴지통 1(보고만)" "allow|0|1|1|1" \
  "$(psqlt "SELECT concat_ws('|', result, detail->>'mismatches', detail->>'quarantined', detail->>'quarantine_orphan', detail->>'trash_quarantine_missing') FROM audit_logs WHERE id > ${M_T7B:-0} AND action='audit.integrity' ORDER BY id DESC LIMIT 1")"
expect "T7-2 AL-6 새로 안 남음" "0" \
  "$(psqlt "SELECT count(*) FROM audit_logs WHERE id > ${M_T7B:-0} AND action='alert.raised' AND target_name='AL-6'")"
expect "T7-2 격리 파일은 그대로(읽기만 — 옮기거나 지우지 않는다)" "yes" "$([ -f "$INST/quarantine/$S_OK" ] && echo yes || echo no)"

# ════════════════════════════════════════════════════════════════
hr "T4 — 감사 detail · 비밀값 (T2·T3·T5·T7 이 남긴 줄 전체)"

sub "T4 — 비밀값 0 (al_scrub_command 그대로)"
expect "T4 임시 alerts.log 에 비밀값 원문 0줄" "0" "$(grep -c 'f69secret' "$INST/log/alerts.log")"
expect "T4 임시 감사 alert.raised detail 에 비밀값 0행" "0" \
  "$(psqlt "SELECT count(*) FROM audit_logs WHERE id > ${M_T3:-0} AND action='alert.raised' AND detail::text LIKE '%f69secret%'")"
contains "T4 가려진 표시가 남았다" "[제거됨]" "$(grep "f69s5${TAG}" "$INST/log/alerts.log" | tail -1)"


sub "T4 — 감사 alert.raised detail.origin 이 줄의 origin 과 같다 (T2·T3·T5·T7 전체)"
psqlt "SELECT json_build_object('rule', detail->>'rule', 'subject', coalesce(detail->'evidence'->>'subject',''), 'source', coalesce(detail->'evidence'->>'source',''), 'origin', detail->>'origin') FROM audit_logs WHERE id > ${M_ALL:-0} AND action='alert.raised' ORDER BY id" > "$WORK/t4.audit"
T4=$(python3 - "$INST/log/alerts.log" "$WORK/t4.audit" <<'PY'
import json, sys
lines = []
for raw in open(sys.argv[1], encoding='utf-8'):
    try:
        d = json.loads(raw)
    except Exception:
        continue
    if 'origin' not in d:
        continue
    ev = d.get('evidence') or {}
    lines.append((d.get('rule'), str(ev.get('subject', '')), str(ev.get('source', '')), d.get('origin')))
audit = []
for raw in open(sys.argv[2], encoding='utf-8'):
    raw = raw.strip()
    if not raw:
        continue
    a = json.loads(raw)
    audit.append((a['rule'], a['subject'], a['source'], a['origin']))
missing = [l for l in lines if l not in audit]
print('%d %d %d' % (len(lines), len(audit), len(missing)))
for m in missing[:5]:
    print('MISSING', m)
PY
)
printf '%s\n' "$T4" | sed 's/^/    | /'
expect "T4 줄마다 같은 origin 의 감사 행이 있다(어긋남 0)" "0" "$(printf '%s' "$T4" | head -1 | awk '{print $3}')"

sub "옛 줄 하나를 **임시** 로그에 덧붙인다 — 화면의 「출처 표기 전」 확인용 (운영 로그는 건드리지 않는다)"
printf '{"raised_at":"2026-09-17T02:00:05+09:00","rule":"AL-6","evidence":{"source":"consistency_check","mismatch_count":3,"kinds":{"missing":3},"sample":["f69-old-%s"],"f69_old":"%s"},"dispatch":"log_only","request_id":"00000000-0000-4000-8000-000000000069"}\n' "$TAG" "$TAG" >> "$INST/log/alerts.log"
expect "옛 줄 모양(origin·title 없음)" "None|-|other|nokeys" "$(line_of AL-6 f69_old "$TAG")"

# ════════════════════════════════════════════════════════════════
hr "T8 — AL-6 운영 확인 (쓰기 없음 — 운영 DB 읽기 + ls)"

PROD_INF=$(psqlp "SELECT stored_name FROM files WHERE deleted_at IS NULL AND purged_at IS NULL AND scan_status='infected' ORDER BY id")
T8_N=0; T8_Q=0; T8_S=0; T8_BAD=0
if [ -n "$NO_ROOT" ]; then
  skip "T8 격리 폴더·저장소 확인" "F69_NO_ROOT — 운영 저장소는 root 만 읽는다"
  PROD_INF=""
fi
for n in $PROD_INF; do
  # DB 값이지만 경로에 넣기 전에 모양을 본다(UUID.확장자만)
  if ! [[ "$n" =~ ^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.[a-z0-9]{1,10}$ ]]; then
    T8_BAD=$((T8_BAD+1)); continue
  fi
  T8_N=$((T8_N+1))
  sudo -n test -f "/var/lib/tbox-v2/quarantine/$n" && T8_Q=$((T8_Q+1))
  sudo -n test -e "/var/lib/tbox-v2/storage/${n:0:2}/${n:2:2}/$n" && T8_S=$((T8_S+1))
done
note "운영 살아 있는 infected 행 ${T8_N}개 · 격리 폴더에 있음 ${T8_Q} · 저장소에 남음 ${T8_S} · 이름 모양 이상 ${T8_BAD}"
if [ -z "$NO_ROOT" ]; then
expect "T8 살아 있는 infected 행이 전부 격리 폴더에 있다" "$T8_N" "$T8_Q"
expect "T8 저장소에 남은 감염 바이트 0" "0" "$T8_S"
fi
note "→ 이 갈래는 고친 코드로 불일치 0 이 될 것이다(옛 코드는 ${T8_N}건을 missing 으로 셌다 — 지시서 머리 실측 3건)"
note "실제 확인은 적용 **다음 날 02:00** 배치의 행(읽기): SELECT happened_at, result, detail->>'mismatches', detail->>'quarantined' FROM audit_logs WHERE action='audit.integrity' AND target_name='consistency' ORDER BY id DESC LIMIT 1"
SKIP=$((SKIP+1))

# ════════════════════════════════════════════════════════════════
hr "T9 — 화면: 관리자 경보 목록 (재인증 포함) · 배지 · textContent · 라이트·다크·390px"

mkdir -p "$OUT"
cat > "$WORK/otp.sh" <<OTPSH
#!/bin/sh
sec=\$(sudo -n awk -v u="\$1" '\$1==u{print \$2}' /etc/tbox-v2/qa-totp.txt 2>/dev/null)
[ -n "\$sec" ] || exit 1
# 비밀은 php 의 인자가 아니라 표준입력으로(ps·/proc 에 보이지 않게 — R-62 · 봇 BF-0002). dash 의 printf 도 내장이다.
printf '%s\n' "\$sec" | php "$ROOT/scripts/totp_code.php" -
OTPSH
chmod +x "$WORK/otp.sh"

cat > "$WORK/t9.py" <<'PY'
import json, re, subprocess, sys, time

base, ua, who, otpsh, chrome, shots, tag = sys.argv[1:8]
pw = sys.stdin.readline().rstrip('\n')   # 비밀번호는 인자가 아니라 표준입력 첫 줄로(R-62 · 봇 BF-0003 — py_secret)

try:
    from playwright.sync_api import sync_playwright
except Exception as e:
    print(json.dumps({'PLAYWRIGHT_MISSING': str(e)})); sys.exit(0)


def fresh_code():
    """**다음 30초 창**의 코드 (R-45 1회성)."""
    out = subprocess.run([otpsh, who], capture_output=True, text=True).stdout
    left = re.search(r'남은 시간: (\d+)', out)
    time.sleep(int(left.group(1)) + 1 if left else 31)
    out = subprocess.run([otpsh, who], capture_output=True, text=True).stdout
    return out.splitlines()[0].strip()


js_errs = []
out = {'reauth_via': []}


def on_console(m):
    if m.type == 'error' and not m.text.startswith('Failed to load resource'):
        js_errs.append(m.text)


def pass_reauth(page):
    """관리 화면 재인증 — 화면(/reauth) 또는 모달(dialog) 어느 쪽이 나와도 비밀번호만 넣는다."""
    for _ in range(3):
        if '/reauth' in page.url and page.locator('#reauth-password').count():
            page.fill('#reauth-password', pw)
            page.click('#reauth-submit')
            page.wait_for_timeout(1500)
            out['reauth_via'].append('page')
            continue
        dlg = page.locator('#reauth-dialog-form input[type=password]')
        if dlg.count() and dlg.first.is_visible():
            dlg.first.fill(pw)
            page.click('button[form=reauth-dialog-form]')
            page.wait_for_timeout(1500)
            out['reauth_via'].append('modal')
            continue
        break


def read_list(page):
    return page.evaluate("""() => Array.from(document.querySelectorAll('#admin-alerts > li')).map(li => ({
        rule: (li.querySelector('.list__head strong') || {}).textContent || '',
        badges: Array.from(li.querySelectorAll('.list__head .badge')).map(b => b.textContent),
        badge_cls: Array.from(li.querySelectorAll('.list__head .badge')).map(b => b.className),
        head: (li.querySelector('.list__head') || {}).textContent || '',
        body: (li.querySelector('.list__body') || {}).textContent || ''
    }))""")


launch = {'args': ['--no-sandbox']}
if chrome:
    launch['executable_path'] = chrome

with sync_playwright() as p:
    b = p.chromium.launch(**launch)
    ctx = b.new_context(user_agent=ua, viewport={'width': 1440, 'height': 1000}, color_scheme='light')
    page = ctx.new_page()
    page.on('console', on_console)
    page.on('pageerror', lambda e: js_errs.append('pageerror: ' + str(e)))
    page.on('dialog', lambda d: d.accept())

    page.goto(base + '/login', wait_until='networkidle')
    page.fill('#username', who)
    page.fill('#password', pw)
    page.click('#login-submit')
    page.wait_for_timeout(1200)
    if page.is_visible('#otp'):
        page.fill('#otp', fresh_code())
        page.wait_for_timeout(1500)   # 사용감 라운드 1 UX-9 — 여섯 자리에서 로그인 화면이 스스로 제출한다(옛 판은 아래 단추)
        if page.is_visible('#otp') and page.input_value('#otp') != '':
            page.click('#login-submit')
        page.wait_for_timeout(2000)

    page.goto(base + '/admin', wait_until='networkidle')
    pass_reauth(page)
    if '/admin' not in page.url:
        page.goto(base + '/admin', wait_until='networkidle')
        pass_reauth(page)
    page.wait_for_selector('#admin-alerts > li', timeout=10000)
    page.wait_for_timeout(800)
    pass_reauth(page)   # 목록 창구가 403 next:reauth 로 모달을 띄운 경우
    page.wait_for_timeout(500)

    out['url'] = page.url
    out['items'] = read_list(page)
    out['injected_img'] = page.locator('#admin-alerts img').count()
    out['inline_style'] = page.eval_on_selector_all('#admin-alerts [style]', 'els => els.length')
    api = page.evaluate("""async () => {
        const r = await fetch('/tbox/api/admin/alerts', {credentials: 'same-origin', cache: 'no-store'});
        let d = null; try { d = await r.json(); } catch (e) {}
        return {status: r.status, data: d};
    }""")
    out['api_status'] = api.get('status')
    alerts = ((api.get('data') or {}).get('alerts') or [])
    out['api_count'] = len(alerts)
    out['api_al8'] = [[a.get('origin'), a.get('title')] for a in alerts if a.get('rule') == 'AL-8'][:1]
    out['api_old'] = [a.get('origin') for a in alerts
                      if tag in json.dumps(a.get('evidence') or {}, ensure_ascii=False) and a.get('rule') == 'AL-6'
                      and 'f69-old' in json.dumps(a.get('evidence') or {})][:1]
    out['api_keys_ok'] = all(('origin' in a and 'title' in a) for a in alerts)

    # 대기열 2b — Admin 계정 만들기 창구가 검토 접두(claude_·qa_)를 400 으로 막는다(임시 인스턴스·임시 DB 에만 쓴다).
    #   있는 아이디는 전처럼 already_exists 가 먼저다(tests/f68.sh (a)8 과 같은 갈래). 사람 이름은 201.
    out['b2'] = page.evaluate("""async (tag) => {
        const me = await (await fetch('/tbox/api/me', {credentials: 'same-origin', cache: 'no-store'})).json();
        const csrf = (me && me.csrf_token) || '';
        async function mk(name) {
            const r = await fetch('/tbox/api/users', {method: 'POST', credentials: 'same-origin',
                headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrf},
                body: JSON.stringify({username: name, role: 'Employee', reason_text: 'F69 2b 시험'})});
            let d = null; try { d = await r.json(); } catch (e) {}
            return [r.status, d ? (d.error || '') : ''];
        }
        return {claude: await mk('claude_f69new' + tag), qa: await mk('qa_f69new' + tag),
                exists: await mk('claude_admin01'), human: await mk('f69new' + tag)};
    }""", tag)
    page.screenshot(path=shots + '/t9-light.png', full_page=True)

    page.emulate_media(color_scheme='dark')
    page.reload(wait_until='networkidle')
    pass_reauth(page)
    page.wait_for_selector('#admin-alerts > li', timeout=10000)
    page.wait_for_timeout(600)
    page.screenshot(path=shots + '/t9-dark.png', full_page=True)

    cookies = ctx.cookies()
    m = b.new_context(user_agent=ua, viewport={'width': 390, 'height': 844}, color_scheme='light')
    m.add_cookies(cookies)
    mp = m.new_page()
    mp.on('console', on_console)
    mp.on('pageerror', lambda e: js_errs.append('pageerror(390): ' + str(e)))
    mp.goto(base + '/admin', wait_until='networkidle')
    pass_reauth(mp)
    mp.wait_for_selector('#admin-alerts > li', timeout=10000)
    mp.wait_for_timeout(600)
    out['mobile_overflow'] = mp.evaluate('() => document.documentElement.scrollWidth > document.documentElement.clientWidth')
    mp.screenshot(path=shots + '/t9-390.png', full_page=True)

    out['errors'] = js_errs
    b.close()

print(json.dumps(out, ensure_ascii=False))
PY

PW_CHROME=""
for cand in "$HOME"/.cache/ms-playwright/chromium-*/chrome-linux64/chrome \
            "$HOME"/.cache/ms-playwright/chromium-*/chrome-linux/chrome; do
  [ -x "$cand" ] && { PW_CHROME="$cand"; break; }
done
note "크로미움: ${PW_CHROME:-(playwright 기본값)} · 스크린샷 폴더: $OUT"
ADMIN_PW=$(review_password "$ADMIN")
if [ -z "$ADMIN_PW" ]; then
  skip "T9 전체" "$ADMIN 비밀번호를 읽지 못했다(seed-accounts.txt)"
else
  # ★ stderr 는 따로 받는다 — 표준출력에 섞으면 JSON 이 깨진다 (f65.sh 실측)
  py_secret ADMIN_PW -- python3 "$WORK/t9.py" "$BASE" "$UA" "$ADMIN" "$WORK/otp.sh" "$PW_CHROME" "$OUT" "$TAG" \
    > "$WORK/t9.json" 2> "$WORK/t9.err"
  T9_RC=$?
  unset ADMIN_PW
  if grep -q 'PLAYWRIGHT_MISSING' "$WORK/t9.json" 2>/dev/null; then
    skip "T9 전체" "playwright 가 없다 — 미검증 (브라우저 있는 환경에서 다시 실행)"
  elif [ "$T9_RC" -ne 0 ] || [ ! -s "$WORK/t9.json" ]; then
    printf '  ! T9 파이썬이 실패했다 (rc=%s) — stderr:\n' "$T9_RC"; tail -20 "$WORK/t9.err" | sed 's/^/      /'
    FAIL=$((FAIL+1))
  else
    tq() { jq_py "$1" < "$WORK/t9.json"; }
    note "재인증 경로: $(tq "','.join(d.get('reauth_via', [])) or '없음'") · 목록 $(tq "len(d.get('items', []))")줄"
    # 줄 고르기 — 본문(근거)에 그 줄만의 표식이 있다
    pick() { tq "next((','.join(i['badges']) or '없음') for i in d.get('items', []) if '$1' in i['body'] and i['rule'] == '$2')"; }
    expect "T9 검토 계정 줄(AL-1) — 배지 「Claude」"          "Claude"       "$(pick "subject: $R" AL-1)"
    expect "T9 사람 계정 줄(AL-1) — 배지 없음"                "없음"         "$(pick "subject: $H" AL-1)"
    expect "T9 한쪽만 맞은 줄(AL-1) — 「확인 필요」"           "확인 필요"    "$(pick "subject: $P" AL-1)"
    expect "T9 AL-9 claude-session 줄 — 「Claude」"             "Claude"       "$(pick "user: f69s1${TAG}" AL-9)"
    if [ -n "$NO_ROOT" ]; then skip "T9 AL-8 백업 실패 줄 — 「시스템」" "F69_NO_ROOT — AL-8 줄은 T5(verify_backup, root)가 만든다"
    else
    expect "T9 AL-8 백업 실패 줄 — 「시스템」"                  "시스템"       "$(tq "next((','.join(i['badges']) or '없음') for i in d.get('items', []) if i['rule'] == 'AL-8')")"
    fi
    expect "T9 AL-6 정합성 줄(T7 첫 실행) — 「시스템」"         "시스템"       "$(pick "infected_in_storage" AL-6)"
    expect "T9 옛 줄 — 「출처 표기 전」"                        "출처 표기 전" "$(pick "f69-old-$TAG" AL-6)"
    expect "T9 「Claude」 줄의 제목이 [Claude] 로 시작"         "yes" \
      "$(tq "'yes' if any(('[Claude] ' in i['head']) for i in d.get('items', []) if 'subject: $R' in i['body']) else 'no'")"
    expect "T9 배지 클래스는 기존 변형만(badge--internal·team·off·scan-pending)" "yes" \
      "$(tq "'yes' if all(c in ('badge badge--internal','badge badge--team','badge badge--off','badge badge--scan-pending') for i in d.get('items', []) for c in i['badge_cls']) else 'no'")"
    expect "T9 주입 문자열이 요소가 되지 않았다(<img> 0)" "0" "$(tq "d.get('injected_img', -1)")"
    expect "T9 주입 문자열은 글자로 보인다" "yes" \
      "$(tq "'yes' if any('<img src=x' in i['body'] for i in d.get('items', [])) else 'no'")"
    expect "T9 경보 목록 안 인라인 style 0" "0" "$(tq "d.get('inline_style', -1)")"
    if [ -n "$NO_ROOT" ]; then skip "T9 T5 — 같은 파서가 AL-8 을 읽음" "F69_NO_ROOT — T5 를 건너뛰었다"
    else
    expect "T9 T5 — 같은 파서(GET /api/admin/alerts)가 AL-8 을 origin system 으로 읽음" "system" "$(tq "(d.get('api_al8') or [['?']])[0][0]")"
    fi
    expect "T9 API — 옛 줄 origin 은 null" "None" "$(tq "str((d.get('api_old') or ['?'])[0])")"
    expect "T9 API — 줄마다 origin·title 키가 있다(옛 줄은 null)" "True" "$(tq "str(d.get('api_keys_ok'))")"
    expect "T9 390px 가로 넘침 없음" "False" "$(tq "str(d.get('mobile_overflow'))")"
    sub "2b — 사람 계정 이름에 검토 접두 금지 (Admin 창구 · 임시 인스턴스)"
    expect "2b claude_ 새 이름 → 400" "400" "$(tq "d['b2']['claude'][0]")"
    contains "2b claude_ 문구" "검토·시험 계정 전용 접두" "$(tq "d['b2']['claude'][1]")"
    expect "2b qa_ 새 이름 → 400" "400" "$(tq "d['b2']['qa'][0]")"
    expect "2b 있는 검토 계정 이름 → 400 already_exists 문구(전과 같음)" "400|이미 있는 아이디입니다" \
      "$(tq "'%s|%s' % (d['b2']['exists'][0], d['b2']['exists'][1])")"
    expect "2b 사람 이름 → 201" "201" "$(tq "d['b2']['human'][0]")"
    expect "2b 검토 접두 계정이 만들어지지 않았다(임시 DB)" "0" \
      "$(psqlt "SELECT count(*) FROM users WHERE username IN ('claude_f69new$TAG', 'qa_f69new$TAG')")"
    expect "2b 감사 user.create deny reserved_prefix 2행" "2" \
      "$(psqlt "SELECT count(*) FROM audit_logs WHERE action='user.create' AND result='deny' AND reason='reserved_prefix' AND target_name IN ('claude_f69new$TAG', 'qa_f69new$TAG')")"
    expect "T9 콘솔 오류 0" "0" "$(tq "len(d.get('errors', []))")"
    note "스크린샷(직접 본다): $OUT/t9-light.png · $OUT/t9-dark.png · $OUT/t9-390.png"
  fi
fi

# ════════════════════════════════════════════════════════════════
hr "T10 — 회귀·정적"

sub "운영 alerts.php --dry-run (읽기만 — --state 임시 · --auth-log 빈 파일): 줄마다 origin= 이 붙는다"
if [ -n "$NO_ROOT" ]; then
  skip "T10 운영 dry-run" "F69_NO_ROOT — 운영 .env 를 root 로 읽어야 한다(임시 인스턴스 dry-run 은 아래 옛 코드 대조가 본다)"
else
: > "$RWORK/empty-auth.log" 2>/dev/null || sudo -n touch "$RWORK/empty-auth.log"
sudo -n php "$ROOT/scripts/alerts.php" --dry-run --state "$RWORK/prod-dry-state.json" --auth-log "$RWORK/empty-auth.log" \
  > "$WORK/prod-dry.out" 2>&1
expect "T10 운영 dry-run 종료 코드" "0" "$?"
sed 's/^/    | /' "$WORK/prod-dry.out" | head -40
PD_N=$(grep -c '\[dry-run\]' "$WORK/prod-dry.out")
PD_O=$(grep '\[dry-run\]' "$WORK/prod-dry.out" | grep -cE ' origin=(human|system|claude-review|claude-session)( hint=partial)?$')
expect "T10 운영 dry-run 줄마다 origin= (넷 중 하나)" "$PD_N" "$PD_O"
fi

sub "옛 코드($F69_BASE)와 발동 목록 대조 — 같은 임시 DB · 같은 순간 · origin 꼬리만 떼고 같아야 한다"
if git -C "$ROOT" rev-parse --verify -q "$F69_BASE^{commit}" >/dev/null 2>&1; then
  OLD="$INST/old"
  mkdir -p "$OLD"
  tar -C "$INST/app" -cf - . | tar -C "$OLD" -xf -
  for f in scripts/alerts.php scripts/lib/cli_bootstrap.php; do
    git -C "$ROOT" show "$F69_BASE:$f" > "$OLD/$f" 2>/dev/null || { echo "  ✖ $F69_BASE:$f 를 꺼내지 못했다"; FAIL=$((FAIL+1)); }
  done
  php "$OLD/scripts/alerts.php" --dry-run --state "$WORK/cmp-old.json" --auth-log "$WORK/empty-auth.log" 2>&1 \
    | grep '\[dry-run\]' | sort > "$WORK/cmp-old.txt"
  php "$INST/app/scripts/alerts.php" --dry-run --state "$WORK/cmp-new.json" --auth-log "$WORK/empty-auth.log" 2>&1 \
    | grep '\[dry-run\]' | sed -E 's/ origin=[a-z-]+( hint=partial)?$//' | sort > "$WORK/cmp-new.txt"
  note "옛 $(wc -l < "$WORK/cmp-old.txt")줄 · 새 $(wc -l < "$WORK/cmp-new.txt")줄"
  diff "$WORK/cmp-old.txt" "$WORK/cmp-new.txt" | head -10 | sed 's/^/    | /'
  expect "T10 발동 목록이 옛 코드와 같다(origin 만 붙음)" "0" "$(diff "$WORK/cmp-old.txt" "$WORK/cmp-new.txt" | grep -c '^[<>]')"
else
  skip "T10 옛 코드 대조" "git 에 $F69_BASE 가 없다"
fi

sub "보안 린트 · php -l · bash -n · 위키 점검"
bash "$ROOT/scripts/security_lint.sh" > "$WORK/lint.out" 2>&1
expect "T10 security_lint.sh 종료 코드" "0" "$?"
expect "T10 ✖ 줄 수" "0" "$(grep -c '^✖' "$WORK/lint.out")"
tail -1 "$WORK/lint.out" | sed 's/^/    /'
PHPBAD=0
for f in src/Origin.php src/Audit.php src/handlers/admin_alerts.php src/handlers/users_create.php \
         scripts/lib/cli_bootstrap.php scripts/alerts.php scripts/consistency_check.php \
         scripts/audit_chain.php scripts/scan_pending.php; do
  php -l "$ROOT/$f" >/dev/null 2>&1 || { echo "  ✖ php -l 실패: $f"; PHPBAD=1; }
done
expect "T10 php -l (F-69·2b 가 고친 9개)" "0" "$PHPBAD"
BASHBAD=0
for f in scripts/verify_backup.sh tests/f69.sh tests/f54.sh; do
  bash -n "$ROOT/$f" 2>/dev/null || { echo "  ✖ bash -n 실패: $f"; BASHBAD=1; }
done
expect "T10 bash -n (3개)" "0" "$BASHBAD"
# 위키 검사 ⑧ 은 저장소 부모 폴더의 사이트·커뮤니티 STAGES 를 읽는다. 복제본(부모 폴더 없음)이면 임시 사본에 운영 쪽 두 파일을
# 읽기 링크로 두고 그 사본에서 돈다(보안 넷 2026-09-26 — 읽기만)
WIKI_ROOT="$ROOT"
if [ ! -f "$ROOT/../STAGES.md" ] || [ ! -f "$ROOT/../community/STAGES.md" ]; then
  [ -e "$INST/STAGES.md" ] || ln -s /var/www/qaredi/STAGES.md "$INST/STAGES.md"
  [ -e "$INST/community" ] || ln -s /var/www/qaredi/community "$INST/community"
  WIKI_ROOT="$INST/app"
fi
php "$WIKI_ROOT/scripts/wiki_check.php" > "$WORK/wiki.out" 2>&1
expect "T10 wiki_check.php 종료 코드" "0" "$?"
tail -1 "$WORK/wiki.out" | sed 's/^/    /'
note "회귀 시험은 이 스크립트가 돌리지 않는다 — 따로: tests/f54.sh(격리 흐름 + 새 한 단계) · tests/stage26.sh · tests/help_button.sh · tests/f68.sh"

# ════════════════════════════════════════════════════════════════
hr "T6 — 옛 줄 불변 (운영 alerts.log 앞 ${PROD_OLD_LINES}줄) · 이 시험이 운영 로그에 남긴 줄 0"
if [ -n "$NO_ROOT" ]; then
  skip "T6" "F69_NO_ROOT — 운영 alerts.log 는 root 만 읽는다(이 판은 운영 로그에 쓰는 갈래도 없다: alerts.php 는 임시 인스턴스 사본만 돈다)"
else
T6_H1=$(sudo -n head -n "$PROD_OLD_LINES" "$PROD_ALERTS" 2>/dev/null | sha256sum | cut -c1-64)
if [ -n "${T6_N0:-}" ] && [ "${T6_N0:-0}" -ge "$PROD_OLD_LINES" ] 2>/dev/null; then
  expect "T6 앞 ${PROD_OLD_LINES}줄 sha256 시작 = 끝" "$T6_H0" "$T6_H1"
  expect "T6 운영 alerts.log 에 이 실행의 가짜 행위자·계정 0줄" "0" \
    "$(sudo -n grep -cE "f69[rhpxs][0-9]?${TAG}|f69ssh${TAG}|F69-al[0-9]+-[rh]-${TAG}" "$PROD_ALERTS" 2>/dev/null)"
else
  skip "T6" "운영 alerts.log 를 읽지 못했거나 ${PROD_OLD_LINES}줄보다 짧다(${T6_N0:-?}줄)"
fi
fi   # NO_ROOT

# ════════════════════════════════════════════════════════════════
hr "결과"
printf '통과 %d · 실패 %d · 건너뜀/미검증 %d\n' "$PASS" "$FAIL" "$SKIP"
note "임시 DB($DB)·임시 폴더·임시 서버는 이 스크립트가 끝나며 지워진다. 스크린샷만 $OUT 에 남는다."
[ "$FAIL" -eq 0 ] || exit 1
exit 0
