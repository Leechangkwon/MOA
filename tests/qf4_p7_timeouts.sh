#!/usr/bin/env bash
# tests/qf4_p7_timeouts.sh — Q:F-4 P-7: FPM 워커·요청 시간 제한 · DB 문장 시간 제한 (지시서 ../docs/qf4-quick-fixes-directive.md 「P-7」,
#                             ARCH 9.1 · 10장 · 5.31, SECURITY R-103 · R-101)
#
#   ① 마이그레이션의 두 값(15s · 60s)을 글자 그대로 읽어 임시 DB 에만 `ALTER ROLE tbox_app IN DATABASE <임시> SET …` 로 건다
#      (마이그레이션 자체는 클러스터 전체에 걸리는 `ALTER ROLE … SET` 이라 시험에서 그대로 돌릴 수 없다)
#   ② tbox_app 으로 `SELECT pg_sleep(20)` → 약 15초에 취소(57014)
#   ③ SHOW — tbox_app 15s · 1min, 다른 역할(tbox_perm 실제 연결 · tbox_owner·tbox_backup·tbox_testread 는 카탈로그)은 그대로
#   ④ B1 지속 연결과의 상호작용 — 임시 php-fpm(워커 1개 고정)에서 idle_in_transaction 을 3초로 줄여, 트랜잭션을 연 채 쉬다
#      서버에 끊긴 연결을 다음 요청이 넘겨받아도 200 인가(Db::adopt) · 끊긴 요청 자신은 500 · 문장 취소 뒤 같은 연결이 멀쩡한가
#   ⑤ php-fpm8.1 -t -y (새 풀 설정 사본) · 안전망 줄(open_basedir·disable_functions·allow_url_fopen)이 운영 /etc 파일과 같다
#   ⑥ request_terminate_timeout 이 실제로 걸린다 — 앱이 아닌 작은 sleep PHP 로(값은 시험에서만 2초)
#   ⑦ 반영 스크립트 둘 — bash -n · 해시 고정값이 이 트리 파일과 같은가 · 경로만 바꾼 사본으로 dry-run·실패 경로·되돌림(qct/trial.sh 방식)
#
# ★ 운영에 쓰지 않는다: /etc 는 읽기만(설정 파일을 사본으로 떠서 경로만 바꾼다) · 운영 DB 는 pg_dump 로 **읽어** 임시 DB 로 파이프 ·
#   역할 설정은 `IN DATABASE <임시>` 로만(임시 DB 를 지우면 같이 사라진다) · 전역 `ALTER ROLE tbox_app SET` 0 ·
#   php-fpm/nginx 는 비root 임시 인스턴스(높은 포트)만 띄우고 끈다. 운영 서비스 reload·restart 0.
# ★ 비밀은 명령 인자에 싣지 않는다 — 로그인은 표준입력(tests/lib/secrets.sh py_secret), 쿠키·CSRF 는 0600 헤더 파일(-H @파일).
# ★ 검토 계정 claude_employee01 만 쓴다(tests/lib/review_accounts.sh). UA tbox-review/<날짜>.
# 준비물: sudo -n(postgres psql·pg_dump·createdb·dropdb · /etc/tbox-v2 비밀 읽기) · php-fpm8.1 · nginx · php · curl · python3
# 걸리는 시간: 약 2~3분(② 15초 · ④ 15초+ · ⑦ 여러 번 reload). 임시 파일은 $TMPDIR(없으면 /tmp) 아래 mktemp 폴더 하나.
# 손잡이(나란히 도는 복제본 · 복제본에서 돌리기 — 디버깅 묶음 T): 임시 DB 이름 앞 `tbox_` = QTEST_DB_PREFIX(예 tbox_d1t_) ·
#   임시 nginx 포트 P7_P80·P7_P82·P7_P84 · 임시 .env 원본 QF2_ENV_SRC(복제본에는 .env 가 없다 — mktemp 폴더 0600 사본) ·
#   ⑦ 반영 스크립트 자리 P7_SCRIPTS_DIR(없으면 review-scripts/ → 트리 형제 ../scripts/). 그 자리에 스크립트 둘이 없으면
#   ⑦ 만 「환경 없음 — 건너뜀(실패 아님)」이고 ①~⑥ 은 그대로 돈다.
# 나가는 값: 0 통과 · 1 실패 · 3 준비물 없음 · 4 인스턴스 실패
set -u
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
. "$ROOT/tests/lib/review_accounts.sh" || { echo "tests/lib/review_accounts.sh 를 읽지 못했다 — 시작하지 않는다"; exit 3; }
. "$ROOT/tests/lib/secrets.sh" || { echo "tests/lib/secrets.sh 를 읽지 못했다 — 시작하지 않는다"; exit 3; }
UA="tbox-review/$(date +%F)"
SRC_DB="tbox_re"            # 운영 — **읽기만** (pg_dump)
# 임시 DB 접두 — 나란히 도는 복제본끼리 이름을 가른다(예 QTEST_DB_PREFIX=tbox_d1t_). tbox_ 로 시작 · 소문자·숫자·밑줄 · _ 로 끝
DBP="${QTEST_DB_PREFIX:-tbox_}"
[[ "$DBP" =~ ^tbox_([a-z0-9]+_)*$ ]] || { echo "QTEST_DB_PREFIX 모양이 아니다(tbox_…_) — 시작하지 않는다"; exit 3; }
DB="${DBP}qf4p7_test"        # 임시 — 앱 인스턴스·②③④
DBH="${DBP}qf4p7_dbh"        # 임시(빈 DB) — ⑦ DB 반영 스크립트 사본이 역할 설정을 거는 곳
P80="${P7_P80:-18580}"; P82="${P7_P82:-18582}"; P84="${P7_P84:-18584}"     # 임시 nginx: 운영 사이트 사본 둘(⑦) · 시험 전용(④⑥)
for p in "$P80" "$P82" "$P84"; do case "$p" in 80|443|8082|8081|"") echo "운영 포트($p)·빈 포트는 쓰지 않는다"; exit 3 ;; esac; done
[ "$(printf '%s\n' "$P80" "$P82" "$P84" | sort -u | wc -l | tr -d ' ')" = 3 ] || { echo "포트 셋이 서로 달라야 한다"; exit 3; }
ENV_SRC="${QF2_ENV_SRC:-$ROOT/.env}"   # 임시 .env 를 만들 원본(복제본은 mktemp 사본)
WHO=claude_employee01
MIG="$ROOT/db/migrations/2026-09-29_app_role_timeouts.sql"
POOL_SRC="$ROOT/deploy/php-fpm-pool.conf"
# 반영 스크립트 자리 — 복제본(검토 중)은 review-scripts/, 운영은 Qaredi 저장소 scripts/(트리 형제). 앞에 있는 것을 쓴다
#   폴더가 있는지가 아니라 **P-7 스크립트가 있는 자리**를 고른다 — 다른 항목의 복제본에도 review-scripts/ 가 있을 수 있다(대기열 11 에서 발견)
#   P7_SCRIPTS_DIR 를 주면 그 자리가 먼저다(복제본에서 운영 사이트의 scripts/ 를 **읽어** 사본으로만 돌릴 때 — 디버깅 묶음 T)
SH_DIR="$(dirname "$ROOT")/scripts"
[ -f "$ROOT/review-scripts/apply-fpm-p7-2026-09-24.sh" ] && SH_DIR="$ROOT/review-scripts"
[ -n "${P7_SCRIPTS_DIR:-}" ] && SH_DIR="$P7_SCRIPTS_DIR"
FPM_SH="$SH_DIR/apply-fpm-p7-2026-09-24.sh"
DB_SH="$SH_DIR/apply-db-p7-2026-09-24.sh"
FPM_BIN=/usr/sbin/php-fpm8.1
ETC_POOL=/etc/php/8.1/fpm/pool.d/tbox.conf   # 운영 풀 파일 — **읽기만**(사본을 떠서 경로만 바꾼다)
WORK="$(mktemp -d "${TMPDIR:-/tmp}/p7t.XXXX")"; chmod 700 "$WORK"
PASS=0; FAIL=0; SKIP=0

hr()  { printf '\n════════════════════════════════════════════════════════════\n%s\n════════════════════════════════════════════════════════════\n' "$1"; }
sub() { printf '\n── %s\n' "$1"; }
expect() {
  if [ "$2" = "$3" ]; then printf '  ✔ %s  (기대=%s 실제=%s)\n' "$1" "$2" "$3"; PASS=$((PASS+1))
  else printf '  ✖ %s  (기대=%s 실제=%s)\n' "$1" "$2" "$3"; FAIL=$((FAIL+1)); fi
}
note() { printf '  · %s\n' "$1"; }
skip() { printf '  ○ SKIP %s  (%s)\n' "$1" "$2"; SKIP=$((SKIP+1)); }

stop_all() {
  local f
  for f in "$WORK"/run/*.pid; do [ -f "$f" ] && kill "$(cat "$f")" 2>/dev/null; done
  sleep 0.5
}
cleanup() {
  stop_all
  sudo -n -u postgres dropdb --if-exists "$DB" >/dev/null 2>&1
  sudo -n -u postgres dropdb --if-exists "$DBH" >/dev/null 2>&1
  rm -rf "$WORK"
}
trap cleanup EXIT

# SQL 은 표준입력으로 — sudo 로 부른 명령의 인자는 auth.log COMMAND= 에 남는다(R15). 값은 시험이 정한 고정 문자열뿐이다
psqlq()  { printf '%s\n' "$1" | sudo -n -u postgres psql -X -d "$DB" -t -A -f - 2>/dev/null; }
psqlh()  { printf '%s\n' "$1" | sudo -n -u postgres psql -X -d "$DBH" -t -A -f - 2>/dev/null; }
psqlpg() { printf '%s\n' "$1" | sudo -n -u postgres psql -X -d postgres -t -A -v ON_ERROR_STOP=1 -f - 2>/dev/null; }
code_of() { curl -s -o /dev/null -w '%{http_code}' -A "$UA" "$@"; }
# (임시 DB 이름, 역할) 의 IN DATABASE 설정 — 원소 정렬, 없으면 (none)
dbrole_conf() { psqlpg "SELECT coalesce((SELECT string_agg(x, ',' ORDER BY x) FROM pg_db_role_setting s JOIN pg_roles r ON r.oid = s.setrole JOIN pg_database d ON d.oid = s.setdatabase, unnest(s.setconfig) x WHERE r.rolname = '$2' AND d.datname = '$1'), '(none)')"; }
global_conf() { psqlpg "SELECT coalesce(array_to_string(rolconfig, ','), '(none)') FROM pg_roles WHERE rolname = '$1'"; }
sha() { sha256sum "$1" | cut -d' ' -f1; }
# 반영 스크립트(사본) 한 번 — 종료 코드만 돌려주고 출력은 runf.txt 로. ⑦ 의 FPM 절과 DB 절이 **함께** 쓴다 — FPM 절 안에서만 정의하면
#   FPM 사본 시험을 건너뛸 때(ETC_WANT 판을 못 찾음) DB 절이 「runf: command not found」로 9건 틀렸다(디버깅 묶음 T, 2026-09-26 기능 점검)
runf() { local rc=0; bash "$@" > "$WORK/log/runf.txt" 2>&1 || rc=$?; echo "$rc"; }

# ════════════════════════════════════════════════════════════════
hr "P-7 — 준비 (운영에는 쓰지 않는다)"
avail_kb=$(df -Pk / | awk 'NR==2{print $4}')
[ "$avail_kb" -ge 1048576 ] || { echo "루트 디스크 여유 1GB 미만 — 시작하지 않는다 (SECURITY R-80)"; exit 3; }
sudo -n -u postgres psql -X -d "$SRC_DB" -tAc "SELECT 1" >/dev/null 2>&1 || { echo "psql(postgres) 을 쓸 수 없다"; exit 3; }
for b in "$FPM_BIN" nginx php curl python3; do command -v "$b" >/dev/null 2>&1 || { echo "$b 가 없다"; exit 3; }; done
[ -f "$MIG" ] && [ -f "$POOL_SRC" ] || { echo "P-7 파일이 없다(마이그레이션 · 풀 설정)"; exit 3; }
# 반영 스크립트 둘은 저장소 밖(트리 형제 scripts/ 또는 검토 복제본 review-scripts/)에 있다 — 없으면 ⑦ 만 건너뛴다(환경 없음 · 실패 아님)
HAVE_SH=0; [ -f "$FPM_SH" ] && [ -f "$DB_SH" ] && HAVE_SH=1
[ -f "$ENV_SRC" ] || { echo "$ENV_SRC 가 없다 — 임시 .env 를 만들 원본이 없다(복제본은 QF2_ENV_SRC)"; exit 3; }
[ -r "$ETC_POOL" ] || { echo "$ETC_POOL 을 읽을 수 없다 — 운영 흉내 풀을 만들 수 없다"; exit 3; }
mkdir -p "$WORK/run" "$WORK/log" "$WORK/hdr" "$WORK/tmp"/{cb,px,fc,uw,sc}
chmod 700 "$WORK/hdr"
SOCK_A="$WORK/a.sk"; SOCK_B="$WORK/b.sk"; SOCK_C="$WORK/c.sk"   # 유닉스 소켓 경로는 108바이트 안 — 짧게
[ "$(printf '%s' "$SOCK_A" | wc -c)" -lt 107 ] || { echo "소켓 경로가 너무 길다 — TMPDIR 을 짧게"; exit 3; }
GLOBAL_BEFORE=$(global_conf tbox_app)
ETC_BEFORE=$(sha "$ETC_POOL")          # 운영 /etc 풀 파일 — 반영 전(ETC_WANT)이든 반영 뒤(WANT)든 시험이 끝날 때 같아야 한다
ETC_WANT_H=""; WANT_H=""
[ "$HAVE_SH" = 1 ] && { ETC_WANT_H=$(sed -n 's/^ETC_WANT=//p' "$FPM_SH"); WANT_H=$(sed -n 's/^WANT=//p' "$FPM_SH"); }
note "⑦ 반영 스크립트 자리: $SH_DIR — $([ "$HAVE_SH" = 1 ] && echo '둘 다 있음' || echo '없음 → ⑦ 은 「환경 없음 — 건너뜀(실패 아님)」')"
TESTREAD_BEFORE=$(global_conf tbox_testread)
note "시작할 때 전역 tbox_app 역할 설정: $GLOBAL_BEFORE (시험이 끝날 때 같아야 한다)"

sub "운영 DB 를 **읽어** 임시 DB 를 만든다 (파이프 — 덤프 파일 0) · 빈 임시 DB 하나 더(⑦)"
sudo -n -u postgres dropdb --if-exists "$DB" >/dev/null 2>&1
sudo -n -u postgres dropdb --if-exists "$DBH" >/dev/null 2>&1
sudo -n -u postgres createdb -O tbox_owner "$DB" >/dev/null 2>&1 || { echo "임시 DB 를 만들 수 없다"; exit 3; }
sudo -n -u postgres createdb -O tbox_owner "$DBH" >/dev/null 2>&1 || { echo "임시 DB(⑦)를 만들 수 없다"; exit 3; }
set -o pipefail
sudo -n -u postgres pg_dump -d "$SRC_DB" 2>/dev/null | sudo -n -u postgres psql -X -q -d "$DB" -v ON_ERROR_STOP=0 >/dev/null 2>"$WORK/restore.err" \
  || { set +o pipefail; echo "운영 DB 를 읽어 임시 DB 로 옮기지 못했다"; exit 3; }
set +o pipefail
sudo -n -u postgres psql -X -q -d "$DB" -v ON_ERROR_STOP=1 -f - < "$ROOT/db/community/grants.sql" >/dev/null 2>&1
n_users=$(psqlq "SELECT count(*) FROM public.users")
[ -n "$n_users" ] && [ "$n_users" -gt 0 ] || { echo "임시 DB 로 옮기지 못했다"; exit 3; }
note "임시 DB $DB — 사용자 ${n_users}명 · audit_logs $(psqlq "SELECT count(*) FROM audit_logs")행"

# 앱 사본 — 인스턴스마다 한 벌(A: ⑦ 운영 흉내 · B: ②③④ · H: ⑦ DB 스크립트). .env 는 DB 이름·저장소·감사 로그만 바꾼다
make_app() {  # $1 폴더 $2 DB 이름
  mkdir -p "$1/app" "$1/data/storage" "$1/data/quarantine" "$1/data/tmp" "$1/log" "$1/sess"
  tar -C "$ROOT" -cf - --exclude=.git --exclude=jar --exclude=api-test.txt --exclude=./.env . | tar -C "$1/app" -xf -
  # qcheck-allow: R20 — 임시 인스턴스용 사본(0700·0600 · trap 정리) · kill -9 뒤 남는 창은 받아들인 위험(HO-B1-004)
  sed -e "s#^DB_NAME=.*#DB_NAME=$2#" -e "s#^STORAGE_DIR=.*#STORAGE_DIR=$1/data/storage#" \
      -e "s#^AUDIT_LOG_FILE=.*#AUDIT_LOG_FILE=$1/log/audit.log#" "$ENV_SRC" > "$1/app/.env"
  chmod 600 "$1/app/.env"
  grep -q "^DB_NAME=$2\$" "$1/app/.env"
}
make_app "$WORK/A" "$DB" && make_app "$WORK/B" "$DB" && make_app "$WORK/H" "$DBH" || { echo "앱 사본을 만들지 못했다"; exit 4; }
# 풀 설정 사본 — 계정·소켓 소유자 줄을 빼고 경로·소켓만 바꾼다(값은 그대로). $1 원본 $2 앱 폴더 $3 소켓 $4 풀 이름
conv_pool() {
  sed -e '/^user /d' -e '/^group /d' -e '/^listen.owner/d' -e '/^listen.group/d' \
      -e "s#^\[tbox\]#[$4]#" -e "s#^listen       = .*#listen       = $3#" \
      -e "s#/var/log/tbox-v2/app.log#$2/log/app.log#" -e "s#/var/lib/tbox-v2/sessions#$2/sess#" \
      -e "s#= /var/www/qaredi/tbox-v2:/var/lib/tbox-v2:/var/log/tbox-v2:#= $2/app:$2/data:$2/log:#" "$1"
}
# nginx 사이트 사본 — $1 원본 $2 포트 $3 앱 폴더 $4 소켓
conv_site() {
  sed -e "s#/var/www/qaredi/tbox-v2#$3/app#g" -e "s#unix:/run/php/php8.1-fpm-tbox.sock#unix:$4#g" \
      -e "s#/var/log/nginx/#$WORK/log/#g" -e "s#listen 127.0.0.1:8082;#listen 127.0.0.1:$2;#" \
      -e "s#listen              80 default_server;#listen 127.0.0.1:$2;#" -e "/listen              \[::\]:80 default_server;/d" "$1"
}

# ════════════════════════════════════════════════════════════════
hr "① 마이그레이션의 값을 글자 그대로 — 임시 DB 에만 IN DATABASE 로"
STMTS=$(grep -vE '^[[:space:]]*(--|$)' "$MIG")
expect "마이그레이션 문장 = \\set · BEGIN · ALTER ROLE 둘 · COMMIT (주석 밖 5줄)" "5" "$(printf '%s\n' "$STMTS" | wc -l | tr -d ' ')"
ST_VAL=$(sed -n "s/^ALTER ROLE tbox_app SET statement_timeout = '\([0-9]*s\)';\$/\1/p" "$MIG")
IT_VAL=$(sed -n "s/^ALTER ROLE tbox_app SET idle_in_transaction_session_timeout = '\([0-9]*s\)';\$/\1/p" "$MIG")
expect "마이그레이션의 두 값" "15s|60s" "$ST_VAL|$IT_VAL"
expect "ALTER ROLE 은 tbox_app 에만(다른 역할·IN DATABASE 0)" "2|0" \
  "$(grep -c '^ALTER ROLE tbox_app SET ' "$MIG")|$(grep -ciE '^ALTER ROLE ([a-z_]+ IN DATABASE|tbox_(owner|perm|backup|testread))' "$MIG")"
expect "되돌리기 주석(RESET 둘)이 머리에 있다" "2" "$(grep -cE '^--   ALTER ROLE tbox_app RESET (statement_timeout|idle_in_transaction_session_timeout);' "$MIG")"
expect "마이그레이션 순서가 2026-09-28_community_embeds.sql 보다 뒤(파일 이름 정렬)" "yes" \
  "$([ -f "$ROOT/db/migrations/2026-09-28_community_embeds.sql" ] && [[ "$(basename "$MIG")" > "2026-09-28_community_embeds.sql" ]] && echo yes || echo no)"
psqlpg "ALTER ROLE tbox_app IN DATABASE $DB SET statement_timeout = '$ST_VAL'" >/dev/null
psqlpg "ALTER ROLE tbox_app IN DATABASE $DB SET idle_in_transaction_session_timeout = '$IT_VAL'" >/dev/null
expect "임시 DB 의 tbox_app 설정 = 마이그레이션 값" "idle_in_transaction_session_timeout=$IT_VAL,statement_timeout=$ST_VAL" "$(dbrole_conf "$DB" tbox_app)"
expect "전역 tbox_app 역할 설정은 그대로" "$GLOBAL_BEFORE" "$(global_conf tbox_app)"
grep -q "tbox_qf4p7\|IN DATABASE" "$ROOT/db/grants.sql" && expect "db/grants.sql 에 시험 흔적 0" "0" "1" || true
expect "db/grants.sql 머리글이 이 마이그레이션을 가리킨다" "1" "$(grep -c '2026-09-29_app_role_timeouts.sql' "$ROOT/db/grants.sql")"

# 사본 B 의 cli_bootstrap 으로 tbox_app 연결(앱과 같은 길) — PHP 한 조각을 파일로 두고 부른다
cat > "$WORK/cli_q.php" <<'PHP'
<?php
// 인자: 앱 폴더, 모드. 결과는 한 줄. 비밀은 .env 에서 앱 코드가 읽는다(출력 0)
declare(strict_types=1);
require $argv[1] . '/scripts/lib/cli_bootstrap.php';
$mode = $argv[2] ?? 'show';
$db = Db::instance();
if ($mode === 'show') {
    $r = $db->run("SELECT current_user AS u, current_setting('statement_timeout') AS st, current_setting('idle_in_transaction_session_timeout') AS it")->fetch();
    echo $r['u'], ' ', $r['st'], ' ', $r['it'], "\n";
} elseif ($mode === 'sleep20' || $mode === 'sleep1') {
    $t = hrtime(true);
    try {
        $db->run($mode === 'sleep20' ? 'SELECT pg_sleep(20)' : 'SELECT pg_sleep(1)');
        printf("ok %.2f\n", (hrtime(true) - $t) / 1e9);
    } catch (PDOException $e) {
        printf("err %s %.2f %s\n", (string) $e->getCode(), (hrtime(true) - $t) / 1e9, strpos($e->getMessage(), 'canceling statement due to statement timeout') !== false ? 'timeout_msg' : 'other_msg');
    }
} elseif ($mode === 'perm') {
    // 권한 매트릭스 쓰기 연결(tbox_perm) — 역할 설정이 없어야 한다
    $perm = Db::perm();
    $r = $perm->run("SELECT current_user AS u, current_setting('statement_timeout') AS st, current_setting('idle_in_transaction_session_timeout') AS it")->fetch();
    echo $r['u'], ' ', $r['st'], ' ', $r['it'], "\n";
}
PHP
cli() { ( cd "$WORK/B/app" && php "$WORK/cli_q.php" "$WORK/B/app" "$1" 2>"$WORK/log/cli.err" | tail -1 ); }

# ════════════════════════════════════════════════════════════════
hr "② tbox_app 으로 SELECT pg_sleep(20) → 약 15초에 취소"
R=$(cli sleep20)
note "결과: $R"
set -- $R
expect "SQLSTATE 57014 (query_canceled) · 문구 statement timeout" "err|57014|timeout_msg" "${1:-}|${2:-}|${4:-}"
expect "걸린 시간 14.5~17초" "yes" "$(awk -v t="${3:-0}" 'BEGIN{print (t >= 14.5 && t <= 17) ? "yes" : "no(" t ")"}')"
R=$(cli sleep1); set -- $R
expect "대조군: pg_sleep(1) 은 끝난다" "ok" "${1:-}"

# ════════════════════════════════════════════════════════════════
hr "③ SHOW — tbox_app 15s · 1min, 다른 역할은 그대로"
expect "tbox_app 새 연결 (역할 statement_timeout idle_in_transaction)" "tbox_app 15s 1min" "$(cli show)"
PERM_USER=$(sed -n 's/^DB_PERM_USER=//p' "$WORK/B/app/.env")
expect "tbox_perm 새 연결 — 제한 없음 (실제 연결)" "$PERM_USER 0 0" "$(cli perm)"
for r in tbox_owner tbox_backup tbox_perm; do
  expect "$r — 임시 DB 에 건 설정 없음 · 전역 설정 없음(카탈로그)" "(none)|(none)" "$(dbrole_conf "$DB" "$r")|$(global_conf "$r")"
done
expect "tbox_testread 전역 설정은 시작할 때 그대로(시험이 건드리지 않음)" "$TESTREAD_BEFORE" "$(global_conf tbox_testread)"
expect "postgres(슈퍼유저) 세션 — 제한 없음" "0|0" \
  "$(psqlq "SHOW statement_timeout")|$(psqlq "SHOW idle_in_transaction_session_timeout")"

# ════════════════════════════════════════════════════════════════
hr "⑤ 새 풀 설정 — php-fpm8.1 -t -y · 안전망 줄이 운영 /etc 파일과 같다"
printf '[global]\nerror_log = %s\npid = %s\ninclude = %s\n' "$WORK/log/fpm-t.log" "$WORK/run/t.pidx" "$POOL_SRC" > "$WORK/fpm-t.conf"
OUT=$("$FPM_BIN" -t -y "$WORK/fpm-t.conf" 2>&1); RC=$?
note "$(printf '%s\n' "$OUT" | grep -v '^$' | tail -1)"
expect "php-fpm8.1 -t (새 풀 설정 그대로 — 비root 라 [global] 만 임시)" "0" "$RC"
TT=$("$FPM_BIN" -tt -y "$WORK/fpm-t.conf" 2>&1 | sed 's/^\[[^]]*\] NOTICE: //' | awk '$0=="[tbox]"{on=1;next} /^\[/{on=0} on{sub(/^[ \t]+/,""); print}')
ttv() { printf '%s\n' "$TT" | sed -n "s/^$1 = //p" | head -1; }
expect "읽힌 값 pm · max_children · start · min_spare · max_spare · max_requests · request_terminate_timeout" \
  "dynamic|8|2|2|4|500|90s" "$(ttv pm)|$(ttv pm.max_children)|$(ttv pm.start_servers)|$(ttv pm.min_spare_servers)|$(ttv pm.max_spare_servers)|$(ttv pm.max_requests)|$(ttv request_terminate_timeout)"
if [ -r "$ETC_POOL" ]; then
  for k in open_basedir disable_functions allow_url_fopen upload_max_filesize post_max_size max_file_uploads expose_php; do
    expect "  $k 줄 = 운영 /etc 파일" "$(grep -E "^php_admin_(value|flag)\[$k\]" "$ETC_POOL" | tr -s ' ')" "$(grep -E "^php_admin_(value|flag)\[$k\]" "$POOL_SRC" | tr -s ' ')"
  done
  # 운영 파일과 다른 줄은 P-7 덩이(워커 수·시간 제한과 그 주석)뿐
  OTHER=$(diff "$ETC_POOL" "$POOL_SRC" | grep -E '^[<>]' | grep -vE '^[<>] *(;|$)' | grep -vE '^[<>] (pm( |\.)|request_terminate_timeout)' || true)
  expect "운영 파일과 다른 설정 줄은 pm·request_terminate_timeout 뿐" "" "$OTHER"
  expect "옛 ondemand 전용 줄(process_idle_timeout)은 없다" "0" "$(grep -c '^pm.process_idle_timeout' "$POOL_SRC")"
else
  skip "운영 /etc 풀 파일과 대조" "읽을 수 없다"
fi

# ════════════════════════════════════════════════════════════════
hr "임시 php-fpm 둘 + nginx 하나 (비root · 높은 포트)"
# 마스터 A — 운영 흉내(⑦): 지금 운영 /etc 풀 파일의 사본으로 시작한다. 반영 스크립트 사본이 이 파일을 바꾸고 USR2 로 reload 한다.
#   운영처럼(systemd `--nodaemonize`) **앞에서** 띄운다 — 스스로 데몬이 되면 USR2 로 다시 실행될 때 또 fork 해 pid 가 바뀐다.
mkdir -p "$WORK/fpmA/pool.d"
printf '[global]\npid = %s\nerror_log = %s\ninclude=%s\n' "$WORK/run/fpmA.pid" "$WORK/log/fpmA.err" "$WORK/fpmA/pool.d/*.conf" > "$WORK/fpmA/php-fpm.conf"
# 「반영 전 판」 = 반영 스크립트의 ETC_WANT 에 맞는 파일. 지금 /etc 가 그것이면 /etc 를, 이미 새 판(반영 뒤)이면 저장소 이력에서
#   같은 해시의 deploy/php-fpm-pool.conf 를 찾는다. 못 찾으면 ⑦ FPM 사본 시험만 건너뛴다(시험대는 새 판으로 띄운다).
OLD_POOL="$WORK/old-etc-pool.conf"; : > "$OLD_POOL"
if [ "$ETC_BEFORE" = "$ETC_WANT_H" ]; then
  cp "$ETC_POOL" "$OLD_POOL"
else
  for c in $(git -C "$ROOT" log --format=%H -- deploy/php-fpm-pool.conf 2>/dev/null); do
    git -C "$ROOT" show "$c:deploy/php-fpm-pool.conf" > "$OLD_POOL" 2>/dev/null || true
    [ "$(sha "$OLD_POOL")" = "$ETC_WANT_H" ] && break
    : > "$OLD_POOL"
  done
fi
FPM_HARNESS=1
if [ ! -s "$OLD_POOL" ]; then FPM_HARNESS=0; cp "$POOL_SRC" "$OLD_POOL"; fi
note "반영 전 판(ETC_WANT) 출처: $([ "$ETC_BEFORE" = "$ETC_WANT_H" ] && echo '지금 /etc' || { [ "$FPM_HARNESS" = 1 ] && echo '저장소 이력' || echo '없음 — ⑦ FPM 사본 시험 건너뜀'; })"
conv_pool "$OLD_POOL" "$WORK/A" "$SOCK_A" tbox > "$WORK/fpmA/pool.d/tbox.conf"
conv_pool "$POOL_SRC" "$WORK/A" "$SOCK_A" tbox > "$WORK/A/app/deploy/php-fpm-pool.conf"
OLD_A=$(sha "$WORK/fpmA/pool.d/tbox.conf"); NEW_A=$(sha "$WORK/A/app/deploy/php-fpm-pool.conf")
# 마스터 B — ④ 워커 1개 고정(p7b, 새 설정 사본에서 pm 줄만 static 1) · ⑥ sleep 풀(p7sleep, 요청 시간 제한 2초)
mkdir -p "$WORK/S/app" "$WORK/S/log" "$WORK/S/data" "$WORK/S/sess"
cat > "$WORK/S/app/sleep.php" <<'PHP'
<?php
$s = (int) ($_GET['s'] ?? 5);
sleep(max(0, min($s, 30)));
echo 'slept ', $s, "\n";
PHP
{
  printf '[global]\npid = %s\nerror_log = %s\ndaemonize = yes\n\n' "$WORK/run/fpmB.pid" "$WORK/log/fpmB.err"
  conv_pool "$POOL_SRC" "$WORK/B" "$SOCK_B" p7b | sed -e 's/^pm                   = dynamic/pm = static/' -e 's/^pm.max_children      = 8/pm.max_children = 1/' \
      -e '/^pm.start_servers/d' -e '/^pm.min_spare_servers/d' -e '/^pm.max_spare_servers/d'
  printf '\naccess.log = %s\naccess.format = "%%{%%H:%%M:%%S}t pid=%%p %%m %%r%%Q%%q %%s %%{mili}d ms"\n\n' "$WORK/log/fpmB-access.log"
  conv_pool "$POOL_SRC" "$WORK/S" "$SOCK_C" p7sleep | sed -e 's/^pm                   = dynamic/pm = static/' -e 's/^pm.max_children      = 8/pm.max_children = 2/' \
      -e '/^pm.start_servers/d' -e '/^pm.min_spare_servers/d' -e '/^pm.max_spare_servers/d' -e 's/^request_terminate_timeout = .*/request_terminate_timeout = 2s/'
} > "$WORK/fpmB.conf"
expect "사본 B 풀 — p7b static 1 · p7sleep 요청 시간 제한 2s" "1|2s" \
  "$(awk '/^\[p7b\]/{on=1} /^\[p7sleep\]/{on=0} on && /^pm.max_children/{print $3}' "$WORK/fpmB.conf")|$(awk '/^\[p7sleep\]/{on=1} on && /^request_terminate_timeout/{print $3}' "$WORK/fpmB.conf")"
grep -hvE '^[[:space:]]*;' "$WORK/fpmB.conf" "$WORK/fpmA/pool.d/tbox.conf" | grep -qE "/var/www/qaredi|/var/lib/tbox-v2|/var/log/tbox-v2|/run/php" \
  && { echo "사본 풀에 운영 경로가 남았다 — 중단"; grep -nE "/var/www/qaredi|/var/lib/tbox-v2|/var/log/tbox-v2|/run/php" "$WORK/fpmB.conf" "$WORK/fpmA/pool.d/tbox.conf" | grep -vE ':[[:space:]]*;' | head; exit 4; }
# ④ 는 트랜잭션 안 유휴를 3초로 줄인 새 연결이 필요하다 — 마스터 B 를 띄우기 **전에** 건다(시험 전용, 임시 DB 한정)
psqlpg "ALTER ROLE tbox_app IN DATABASE $DB SET idle_in_transaction_session_timeout = '3s'" >/dev/null
"$FPM_BIN" -F -y "$WORK/fpmA/php-fpm.conf" >"$WORK/log/fpmA.start" 2>&1 &
sleep 1
kill -0 "$!" 2>/dev/null || { echo "마스터 A 가 뜨지 않았다"; cat "$WORK/log/fpmA.start"; exit 4; }
"$FPM_BIN" -y "$WORK/fpmB.conf" 2>"$WORK/log/fpmB.start" || { echo "마스터 B 가 뜨지 않았다"; cat "$WORK/log/fpmB.start"; exit 4; }
# 시험 전용 탐침 — 사본 B 의 앱 폴더에만(제품 트리·라우트 표 밖). 트랜잭션을 연 채 쉬거나, 긴 문장을 보낸다
cat > "$WORK/B/app/p7_probe.php" <<'PHP'
<?php
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';
$mode = (string) ($_GET['m'] ?? 'probe');
$db   = Db::instance();
header('Content-Type: application/json');
$probe = static function () use ($db): array {
    $r = $db->run("SELECT pg_backend_pid() AS pid, current_setting('statement_timeout') AS st, current_setting('idle_in_transaction_session_timeout') AS it")->fetch();
    return ['pid' => (int) $r['pid'], 'st' => (string) $r['st'], 'it' => (string) $r['it'], 'in_tx' => $db->inTransaction(), 'worker' => getmypid()];
};
if ($mode === 'probe') {
    echo json_encode($probe());
    return;
}
if ($mode === 'idle-tx' || $mode === 'idle-tx-query') {
    // 트랜잭션을 열고 한 문장 뒤 5초 쉰다 — 서버는 3초에 이 연결을 끊는다(시험 전용 설정)
    $db->begin();
    $p = $probe();
    sleep(5);
    if ($mode === 'idle-tx-query') {
        $db->run('SELECT 1');      // 끊긴 연결 — 예외 → bootstrap 예외 처리기 → 500
    }
    echo json_encode($p);          // idle-tx: 문장을 더 보내지 않고 트랜잭션이 열린 채 끝난다(요청 끝 정리가 끊긴 연결을 만난다)
    return;
}
if ($mode === 'stmt-timeout') {
    $db->begin();
    $db->run('SELECT pg_sleep(20)');   // 15초에 취소 → 예외 → 500
    echo '{}';
    return;
}
http_response_code(404);
echo '{}';
PHP
# nginx — 운영 사이트 사본 둘(→ 마스터 A) + 시험 전용 서버(검토 포트 사본에 탐침·sleep 자리만 더함, → 마스터 B)
mkdir -p "$WORK/ngx/sites"
cp /etc/nginx/fastcgi_params "$WORK/ngx/fastcgi_params"
sed -e "s#/var/log/nginx/#$WORK/log/#g" /etc/nginx/conf.d/tbox-common.conf > "$WORK/ngx/tbox-common.conf"
conv_site /etc/nginx/sites-available/tbox-v2 "$P80" "$WORK/A" "$SOCK_A" > "$WORK/ngx/sites/tbox-v2"
conv_site /etc/nginx/sites-available/tbox-v2-local "$P82" "$WORK/A" "$SOCK_A" > "$WORK/ngx/sites/tbox-v2-local"
conv_site /etc/nginx/sites-available/tbox-v2-local "$P84" "$WORK/B" "$SOCK_B" \
  | sed "/^    server_name _;/a\\
    location ^~ /__p7/ { include fastcgi_params; fastcgi_param SCRIPT_FILENAME $WORK/B/app/p7_probe.php; fastcgi_param HTTPS on; fastcgi_pass unix:$SOCK_B; fastcgi_read_timeout 60s; }\\
    location ^~ /__p7sleep/ { include fastcgi_params; fastcgi_param SCRIPT_FILENAME $WORK/S/app/sleep.php; fastcgi_pass unix:$SOCK_C; fastcgi_read_timeout 60s; }" \
  | sed "s#tbox-v2-local.access.log#p7-test.access.log#; s#tbox-v2-local.error.log#p7-test.error.log#" > "$WORK/ngx/sites/p7-test"
cat > "$WORK/ngx/nginx.conf" <<NG
pid $WORK/run/nginx.pid;
error_log $WORK/log/nginx-main.err;
worker_processes 1;
events { worker_connections 128; }
http {
    include /etc/nginx/mime.types;
    default_type application/octet-stream;
    client_body_temp_path $WORK/tmp/cb; proxy_temp_path $WORK/tmp/px; fastcgi_temp_path $WORK/tmp/fc; uwsgi_temp_path $WORK/tmp/uw; scgi_temp_path $WORK/tmp/sc;
    access_log $WORK/log/nginx-access.log;
    include $WORK/ngx/tbox-common.conf;
    include $WORK/ngx/sites/*;
}
NG
grep -hvE '^[[:space:]]*#' "$WORK"/ngx/sites/* | grep -qE "/var/www/qaredi|php8.1-fpm-tbox.sock|listen +80 |listen +\[::\]|:8082;" && { echo "사본 사이트에 운영 경로·포트가 남았다 — 중단"; exit 4; }
nginx -c "$WORK/ngx/nginx.conf" -p "$WORK/ngx/" 2>"$WORK/log/ngx.start" || { echo "임시 nginx 가 뜨지 않았다"; cat "$WORK/log/ngx.start"; exit 4; }
sleep 1
expect "임시 인스턴스 health (A 80 사본 · A 8082 사본 · B 시험 서버)" "200|200|200" \
  "$(code_of "http://127.0.0.1:$P80/tbox/api/health")|$(code_of "http://127.0.0.1:$P82/tbox/api/health")|$(code_of "http://127.0.0.1:$P84/tbox/api/health")"

# ════════════════════════════════════════════════════════════════
hr "④ B1 지속 연결 × idle_in_transaction (임시 DB 한정 3초) — php-fpm 워커 1개"
SITEB="http://127.0.0.1:$P84"
cat > "$WORK/login.py" <<'PY'
# 로그인 — 비밀번호·TOTP 비밀은 표준입력 두 줄(인자·환경 변수 0). 쿠키·CSRF 는 0600 헤더 파일로만
import base64, hashlib, hmac, json, os, re, struct, sys, time, urllib.request
base, ua, who, out = sys.argv[1:5]
pw = sys.stdin.readline().rstrip("\n"); sec = sys.stdin.readline().rstrip("\n")
def totp(secret, t):
    key = base64.b32decode(secret.upper() + "=" * (-len(secret) % 8))
    mac = hmac.new(key, struct.pack(">Q", int(t) // 30), hashlib.sha1).digest()
    o = mac[-1] & 15
    return "%06d" % ((struct.unpack(">I", mac[o:o + 4])[0] & 0x7FFFFFFF) % 1000000)
for attempt in range(3):
    body = {"username": who, "password": pw, "otp": totp(sec, time.time())}
    req = urllib.request.Request(base + "/api/login", data=json.dumps(body).encode(), method="POST", headers={"Content-Type": "application/json", "User-Agent": ua})
    cookie, data = "", {}
    try:
        resp = urllib.request.urlopen(req, timeout=20)
        data = json.loads(resp.read().decode())
        for h in resp.headers.get_all("Set-Cookie") or []:
            m = re.match(r"__Host-tbox_session=([^;]+)", h)
            if m: cookie = m.group(1)
    except Exception:
        pass
    if data.get("ok") is True and cookie:
        old = os.umask(0o077)
        with open(os.path.join(out, who + ".cookie"), "w") as f: f.write("Cookie: __Host-tbox_session=%s\n" % cookie)
        os.umask(old)
        print("LOGIN_OK"); sys.exit(0)
    time.sleep(31 - int(time.time()) % 30)
print("LOGIN_FAIL"); sys.exit(1)
PY
PW=$(sudo -n awk -v u="$WHO" '$1==u{print $3}' /etc/tbox-v2/seed-accounts.txt 2>/dev/null)
SEC=$(sudo -n awk -v u="$WHO" '$1==u{print $2}' /etc/tbox-v2/qa-totp.txt 2>/dev/null)
L=$(py_secret PW SEC -- python3 "$WORK/login.py" "$SITEB/tbox" "$UA" "$WHO" "$WORK/hdr")
unset PW SEC
expect "$WHO 로그인 (시험 서버)" "LOGIN_OK" "$L"
HC="$WORK/hdr/$WHO.cookie"
pj() { python3 -c 'import json,sys
try:
    d = json.loads(sys.argv[1]); print("|".join(str(d.get(k)) for k in sys.argv[2:]))
except Exception:
    print("bad-json")' "$@"; }
idle_tx() { psqlq "SELECT count(*) FROM pg_stat_activity WHERE datname = '$DB' AND usename = 'tbox_app' AND state LIKE 'idle in transaction%'"; }
alive() { psqlq "SELECT count(*) FROM pg_stat_activity WHERE pid = $1"; }
LOGB="$WORK/B/log/app.log"; : > "$LOGB" 2>/dev/null || true

sub "④-1 기준 — 워커 1개가 영속 연결 하나를 다시 쓴다"
P0J=$(curl -s -A "$UA" "$SITEB/__p7/?m=probe"); P0=$(pj "$P0J" pid); W0=$(pj "$P0J" worker)
expect "탐침 — 이 연결의 설정 (statement_timeout|idle_in_transaction)" "15s|3s" "$(pj "$P0J" st it)"
P0bJ=$(curl -s -A "$UA" "$SITEB/__p7/?m=probe")
expect "두 번째 요청도 같은 워커 · 같은 백엔드(영속 연결)" "$W0|$P0" "$(pj "$P0bJ" worker pid)"
expect "로그인 창구 GET /tbox/api/me 200" "200" "$(code_of -H @"$HC" "$SITEB/tbox/api/me")"

sub "④-2 트랜잭션을 연 채 5초 쉰다 → 서버가 3초에 끊는다 → 요청은 문장을 더 보내지 않고 끝난다"
C=$(code_of "$SITEB/__p7/?m=idle-tx")
expect "그 요청 자신은 200 (끊긴 뒤 문장을 보내지 않았다)" "200" "$C"
sleep 0.5
expect "그 백엔드($P0)는 서버가 끊었다 (pg_stat_activity 에 없음)" "0" "$(alive "$P0")"
expect "idle in transaction 0" "0" "$(idle_tx)"
expect "다음 요청 — GET /tbox/api/me 가 죽은 영속 연결을 넘겨받아도 200" "200" "$(code_of -H @"$HC" "$SITEB/tbox/api/me")"
P1J=$(curl -s -A "$UA" "$SITEB/__p7/?m=probe"); P1=$(pj "$P1J" pid)
expect "탐침 — 같은 워커 · 새 백엔드 · 트랜잭션 아님" "$W0|yes|False" "$(pj "$P1J" worker)|$([ -n "$P1" ] && [ "$P1" != "$P0" ] && echo yes || echo no)|$(pj "$P1J" in_tx)"
P1bJ=$(curl -s -A "$UA" "$SITEB/__p7/?m=probe")
expect "그다음 요청은 새 백엔드를 다시 쓴다(영속으로 돌아옴)" "$P1" "$(pj "$P1bJ" pid)"

sub "④-3 트랜잭션을 연 채 5초 쉰 뒤 문장을 보낸다 → 그 요청은 500 · 다음 요청은 200"
C=$(code_of "$SITEB/__p7/?m=idle-tx-query")
expect "끊긴 연결에 문장을 보낸 요청 → 500 (기대한 실패)" "500" "$C"
sleep 0.5
expect "그 백엔드($P1)는 서버가 끊었다" "0" "$(alive "$P1")"
expect "다음 요청 — GET /tbox/api/me 200" "200" "$(code_of -H @"$HC" "$SITEB/tbox/api/me")"
expect "다음 요청 — GET /tbox/api/health 200" "200" "$(code_of "$SITEB/tbox/api/health")"
P2J=$(curl -s -A "$UA" "$SITEB/__p7/?m=probe"); P2=$(pj "$P2J" pid)
expect "탐침 — 새 백엔드 · 트랜잭션 아님" "yes|False" "$([ -n "$P2" ] && [ "$P2" != "$P1" ] && echo yes || echo no)|$(pj "$P2J" in_tx)"

sub "④-4 트랜잭션 안에서 문장이 15초에 취소된다 → 500 · 같은 연결이 그대로 살아 다음 요청 200"
T0=$(date +%s.%N)
C=$(code_of "$SITEB/__p7/?m=stmt-timeout")
T1=$(date +%s.%N)
expect "pg_sleep(20) 요청 → 500 · 14.5~17초" "500|yes" "$C|$(awk -v a="$T0" -v b="$T1" 'BEGIN{t=b-a; print (t >= 14.5 && t <= 17) ? "yes" : "no(" t ")"}')"
P3J=$(curl -s -A "$UA" "$SITEB/__p7/?m=probe")
expect "다음 탐침 — 같은 백엔드(문장 취소는 연결을 끊지 않는다) · 트랜잭션 아님" "$P2|False" "$(pj "$P3J" pid in_tx)"
expect "idle in transaction 0" "0" "$(idle_tx)"
expect "GET /tbox/api/me 200" "200" "$(code_of -H @"$HC" "$SITEB/tbox/api/me")"

sub "④ 서버 로그 — 무엇을 거쳤나"
L_END=$(grep -c 'request-end rollback failed' "$LOGB" 2>/dev/null || true)
L_RE=$(grep -c 'reused connection is not usable, reconnecting once' "$LOGB" 2>/dev/null || true)
L_RB=$(grep -c 'open transaction rolled back at request end' "$LOGB" 2>/dev/null || true)
L_UN=$(grep -c 'uncaught' "$LOGB" 2>/dev/null || true)
note "요청 끝 롤백 실패 ${L_END:-0} · 넘겨받을 때 다시 연결 ${L_RE:-0} · 요청 끝 롤백 ${L_RB:-0} · uncaught ${L_UN:-0}"
grep -E 'request-end|reused connection|rolled back|uncaught' "$LOGB" 2>/dev/null | sed -E 's/(request_id=)[0-9a-f-]+/\1…/' | cut -c1-200 | sed 's/^/    /' | head -8
expect "끊긴 연결을 만난 흔적(요청 끝 롤백 실패 또는 넘겨받을 때 다시 연결) ≥ 1 — 어느 길이든 다음 요청은 200 이어야 한다(위)" "yes" "$([ $(( ${L_END:-0} + ${L_RE:-0} )) -ge 1 ] && echo yes || echo no)"
expect "uncaught 는 일부러 낸 두 실패(④-3 · ④-4)뿐" "2" "${L_UN:-0}"
expect "워커 1개가 모든 요청을 처리(영속 연결 시험이 성립)" "1" "$(grep -oE 'pid=[0-9]+' "$WORK/log/fpmB-access.log" | sort -u | wc -l | tr -d ' ')"
# 시험 전용 3초를 원래 값으로 — ⑦ 와 섞이지 않게
psqlpg "ALTER ROLE tbox_app IN DATABASE $DB SET idle_in_transaction_session_timeout = '$IT_VAL'" >/dev/null

# ════════════════════════════════════════════════════════════════
hr "⑥ request_terminate_timeout 이 걸린다 — 작은 sleep PHP · 시험 풀만 2초"
L0=$(wc -l < "$WORK/log/fpmB.err")
R=$(curl -s -o /dev/null -w '%{http_code} %{time_total}' -A "$UA" "$SITEB/__p7sleep/?s=1"); set -- $R
expect "sleep 1초 — 제한 안 → 200" "200" "${1:-}"
R=$(curl -s -o /dev/null -w '%{http_code} %{time_total}' -A "$UA" "$SITEB/__p7sleep/?s=6"); set -- $R
expect "sleep 6초 — 2초에 워커가 죽는다 → 502 · 2~4.5초" "502|yes" "${1:-}|$(awk -v t="${2:-0}" 'BEGIN{print (t >= 2 && t <= 4.5) ? "yes" : "no(" t ")"}')"
sleep 1
tail -n +"$((L0 + 1))" "$WORK/log/fpmB.err" > "$WORK/log/rtt.txt"
sed 's/^/    /' "$WORK/log/rtt.txt" | head -4
expect "FPM 로그 — execution timed out · terminating" "yes|yes" \
  "$(grep -q 'execution timed out' "$WORK/log/rtt.txt" && echo yes || echo no)|$(grep -q 'terminating' "$WORK/log/rtt.txt" && echo yes || echo no)"
R=$(curl -s -o /dev/null -w '%{http_code}' -A "$UA" "$SITEB/__p7sleep/?s=0")
expect "그 뒤 풀은 새 워커로 계속 받는다 → 200" "200" "$R"

# ════════════════════════════════════════════════════════════════
hr "⑦ 반영 스크립트 둘"
if [ "$HAVE_SH" != 1 ]; then
  skip "⑦ 반영 스크립트 둘(정적 · FPM 사본 · DB 사본)" "환경 없음 — $SH_DIR 에 apply-fpm-p7·apply-db-p7 이 없다(P7_SCRIPTS_DIR 로 줄 수 있다) · 실패 아님"
else
sub "정적 — bash -n · 해시 고정값이 이 트리 파일과 같다 · 헤더 확인 함수 모양"
expect "bash -n apply-fpm" "0" "$(bash -n "$FPM_SH" >/dev/null 2>&1; echo $?)"
expect "bash -n apply-db" "0" "$(bash -n "$DB_SH" >/dev/null 2>&1; echo $?)"
expect "지금 운영 /etc 풀 파일 = apply-fpm 의 ETC_WANT(반영 전) 또는 WANT(반영 뒤)" "yes" \
  "$([ "$ETC_BEFORE" = "$ETC_WANT_H" ] || [ "$ETC_BEFORE" = "$WANT_H" ] && echo yes || echo "no(${ETC_BEFORE:0:16})")"
expect "apply-fpm 의 WANT = 이 트리 deploy/php-fpm-pool.conf" "$(sha "$POOL_SRC")" "$(sed -n 's/^WANT=//p' "$FPM_SH")"
expect "apply-db 의 MIG_WANT = 이 트리 마이그레이션" "$(sha "$MIG")" "$(sed -n 's/^MIG_WANT=//p' "$DB_SH")"
for f in "$FPM_SH" "$DB_SH"; do
  expect "$(basename "$f") — hdr 는 이름을 마지막 인자로 · 없으면 (none) · set -euo pipefail" "yes|yes|yes" \
    "$(grep -q 'name=\${!#}' "$f" && echo yes || echo no)|$(grep -q 'printf .%s. "\${v:-(none)}"' "$f" && echo yes || echo no)|$(grep -q '^set -euo pipefail' "$f" && echo yes || echo no)"
done
# hdr 함수만 떼어 실제로 불러 본다 — 헤더가 없을 때 (none), 있을 때 값, 추가 curl 인자
eval "$(sed -n '/^hdr() {/,/^}/p' "$FPM_SH")"
expect "hdr — 있는 헤더 · 없는 헤더 · curl 인자를 사이에 끼워도" "application/json|(none)|application/json" \
  "$(hdr "http://127.0.0.1:$P82/tbox/api/health" Content-Type | cut -d';' -f1)|$(hdr "http://127.0.0.1:$P82/tbox/api/health" X-P7-Nope)|$(hdr "http://127.0.0.1:$P82/tbox/api/health" -H 'X-Test: 1' Content-Type | cut -d';' -f1)"

if [ "$FPM_HARNESS" = 1 ]; then
  sub "FPM 스크립트 사본 — 운영 경로·root 명령만 시험대 것으로 (검사 논리는 그대로)"
  PIDA=$(cat "$WORK/run/fpmA.pid")
  mk_fpm_copy() {  # $1 출력 · $2 ETC_WANT · $3 WANT
    sed -e '/^\[ "\$(id -u)" -eq 0 \]/d' \
        -e "s#^APP=.*#APP=$WORK/A/app#" -e "s#^FPM_DIR=.*#FPM_DIR=$WORK/fpmA#" \
        -e "s#^FPM_TEST=.*#FPM_TEST=(\"\$FPM_BIN\" -t -y $WORK/fpmA/php-fpm.conf)#" \
        -e "s#^FPM_TT=.*#FPM_TT=(\"\$FPM_BIN\" -tt -y $WORK/fpmA/php-fpm.conf)#" \
        -e "s#^RELOAD=.*#RELOAD=(kill -USR2 $PIDA)#" -e "s#^ACTIVE=.*#ACTIVE=(kill -0 $PIDA)#" \
        -e "s#^INSTALL=.*#INSTALL=(install -m 644)#" -e "s#^FPM_USER=.*#FPM_USER=$(id -un)#" \
        -e "s#^L8082=.*#L8082=http://127.0.0.1:$P82#" -e "s#^L80=.*#L80=http://127.0.0.1:$P80#" \
        -e "s#^BK_ROOT=.*#BK_ROOT=$WORK/backups#" -e "s#^ETC_WANT=.*#ETC_WANT=$2#" -e "s#^WANT=.*#WANT=$3#" "$FPM_SH" > "$1"
    ! grep -nE '(/etc/php|/var/www/qaredi|/var/backups|systemctl|-o root|id -u\))' "$1" | grep -vE '^[0-9]+:[[:space:]]*(#|echo )'
  }
  mkdir -p "$WORK/backups"
  DEST_A="$WORK/fpmA/pool.d/tbox.conf"; SRC_A="$WORK/A/app/deploy/php-fpm-pool.conf"
  cp -p "$DEST_A" "$WORK/old-pool.conf"; cp -p "$SRC_A" "$WORK/new-pool.conf"
  reset_a() { cp -p "$WORK/old-pool.conf" "$DEST_A"; cp -p "$WORK/new-pool.conf" "$SRC_A"; kill -USR2 "$PIDA"; sleep 2; }
  # 사본 정리 검사가 「left」면 이 절의 사본을 **돌리지 않는다**(독립 검토 L6). 뒤의 bad·down 사본은 같은 원본·같은 sed 라 이 검사가 대표한다
  if mk_fpm_copy "$WORK/fpm-apply.sh" "$OLD_A" "$NEW_A"; then FPM_COPY_OK=1; expect "사본에 운영 경로·root 명령이 남지 않았다" "ok" "ok"; else FPM_COPY_OK=0; expect "사본에 운영 경로·root 명령이 남지 않았다" "ok" "left"; fi
  if [ "$FPM_COPY_OK" != 1 ]; then
    skip "⑦ FPM 사본 dry-run·본 실행" "사본에 운영 경로·root 명령이 남았다 — 사본을 돌리지 않는다"
  else

  RC=$(TMPDIR="$WORK/tmp" runf "$WORK/fpm-apply.sh" --dry-run)
  expect "dry-run 통과 → 0 · /etc 사본 그대로" "0|$OLD_A" "$RC|$(sha "$DEST_A")"
  grep -q '\[dry\] php-fpm -t 통과' "$WORK/log/runf.txt" || sed 's/^/    | /' "$WORK/log/runf.txt" | tail -6

  printf '\npm.max_children = abc\n' >> "$SRC_A"; mk_fpm_copy "$WORK/fpm-bad.sh" "$OLD_A" "$(sha "$SRC_A")" >/dev/null
  RC=$(TMPDIR="$WORK/tmp" runf "$WORK/fpm-bad.sh" --dry-run)
  expect "dry-run — 새 판이 php-fpm -t 에 실패 → non-zero(3) · /etc 사본 그대로" "3|$OLD_A" "$RC|$(sha "$DEST_A")"
  cp -p "$WORK/new-pool.conf" "$SRC_A"

  printf '; 누가 고침\n' >> "$DEST_A"
  RC=$(runf "$WORK/fpm-apply.sh")
  expect "본 실행 — /etc 가 알려진 판이 아님 → 1 · 덮어쓰지 않음" "1|yes" "$RC|$(grep -q '누가 고침' "$DEST_A" && echo yes || echo no)"
  RC=$(runf "$WORK/fpm-apply.sh" --dry-run)
  expect "dry-run 도 같은 사전 확인에서 멈춘다 → 1" "1" "$RC"
  reset_a

  printf '; 검토 뒤 누가 고침\n' >> "$SRC_A"
  RC=$(runf "$WORK/fpm-apply.sh")
  expect "본 실행 — 원본이 검토 통과 판이 아님 → 1 · /etc 사본 그대로" "1|$OLD_A" "$RC|$(sha "$DEST_A")"
  reset_a

  sed -i "s#^listen       = .*#listen       = $WORK/x.sk#" "$SRC_A"; mk_fpm_copy "$WORK/fpm-down.sh" "$OLD_A" "$(sha "$SRC_A")" >/dev/null
  RC=$(runf "$WORK/fpm-down.sh")
  expect "본 실행 — reload 뒤 확인 실패(소켓이 바뀌어 health 502) → 되돌림 · 4" "4" "$RC"
  grep -E '✖|되돌림' "$WORK/log/runf.txt" | head -4 | sed 's/^/    | /'
  sleep 1
  expect "  되돌린 뒤 /etc 사본 = 반영 전 판 · health 200 (두 포트)" "$OLD_A|200|200" \
    "$(sha "$DEST_A")|$(code_of "http://127.0.0.1:$P82/tbox/api/health")|$(code_of "http://127.0.0.1:$P80/tbox/api/health")"
  expect "  백업 폴더에 반영 전 판" "$OLD_A" "$(sha "$(ls -d "$WORK"/backups/php-fpm-p7-* | tail -1)/tbox.conf")"
  reset_a

  W_BEFORE=$(ps -o pid= --ppid "$PIDA" | sort -n | tr '\n' ' ')
  RC=$(runf "$WORK/fpm-apply.sh")
  expect "본 실행 — 통과 → 0" "0" "$RC"
  [ "$RC" = 0 ] || sed 's/^/    | /' "$WORK/log/runf.txt" | grep -E '✖' | head -8
  grep -E '✔' "$WORK/log/runf.txt" | sed 's/^/    | /' | head -24
  expect "  /etc 사본 = 새 판 · 마스터 살아 있음" "$NEW_A|yes" "$(sha "$DEST_A")|$(kill -0 "$PIDA" 2>/dev/null && echo yes || echo no)"
  W_AFTER=$(ps -o pid= --ppid "$PIDA" | sort -n | tr '\n' ' ')
  expect "  워커가 새로 떴다(옛 pid 0개 남음) · dynamic 쉬는 워커 2 이상" "0|yes" \
    "$(n=0; for p in $W_BEFORE; do case " $W_AFTER " in *" $p "*) n=$((n+1));; esac; done; echo $n)|$([ "$(printf '%s\n' $W_AFTER | wc -l)" -ge 2 ] && echo yes || echo no)"
  W_BEFORE=$(ps -o pid= --ppid "$PIDA" | sort -n | tr '\n' ' '); BK_N=$(ls -d "$WORK"/backups/php-fpm-p7-* 2>/dev/null | wc -l)
  reloads() { grep -c 'Reloading in progress' "$WORK/log/fpmA.err" 2>/dev/null || true; }
  RL_N=$(reloads)
  expect "  (자기 확인) 앞 갈래들의 reload 가 FPM 로그에 남아 있다 — 아래 「reload 없음」 비교가 헛돌지 않는다" "yes" "$([ "${RL_N:-0}" -ge 1 ] && echo yes || echo "no(${RL_N:-0})")"
  sleep 1
  RC=$(runf "$WORK/fpm-apply.sh")
  expect "  한 번 더 — 이미 새 판 → 확인만 하고 0" "0|yes" "$RC|$(grep -q '이미 새 판' "$WORK/log/runf.txt" && echo yes || echo no)"
  # reload 가 없었다 = 옛 워커가 모두 살아 있다(dynamic 이라 확인 요청 때문에 워커가 **더** 뜰 수는 있다)
  gone() { local n=0 p; for p in $1; do kill -0 "$p" 2>/dev/null || n=$((n + 1)); done; echo "$n"; }
  expect "  이미 새 판 — reload 없음(FPM 로그의 reload 줄 수 그대로 · 옛 워커 사라진 수 0) · 백업 새로 없음 · install 없음" "$RL_N|0|$BK_N|yes" \
    "$(reloads)|$(gone "$W_BEFORE")|$(ls -d "$WORK"/backups/php-fpm-p7-* 2>/dev/null | wc -l)|$(grep -q '== 백업:' "$WORK/log/runf.txt" && echo no || echo yes)"
  # 이미 새 판인데 확인이 틀리면 — 되돌리지 않고 5 (80 사본 주소를 닫힌 포트로 바꾼 사본)
  sed "s#^L80=.*#L80=http://127.0.0.1:9#" "$WORK/fpm-apply.sh" > "$WORK/fpm-already-bad.sh"
  RC=$(runf "$WORK/fpm-already-bad.sh")
  expect "  이미 새 판 + 확인 실패 → 5 · /etc 사본 그대로(새 판) · reload 없음(reload 줄 수 · 옛 워커 사라진 수 0)" "5|$NEW_A|$RL_N|0" \
    "$RC|$(sha "$DEST_A")|$(reloads)|$(gone "$W_BEFORE")"
  # 풀 계정이 없으면 — 안내 문구와 1 (조용히 죽지 않는다)
  sed "s#^FPM_USER=.*#FPM_USER=p7-no-such-user#" "$WORK/fpm-apply.sh" > "$WORK/fpm-nouser.sh"
  RC=$(runf "$WORK/fpm-nouser.sh")
  expect "  풀 계정이 없음 → 1 · 안내 문구" "1|yes" "$RC|$(grep -q '계정 p7-no-such-user 이 없다' "$WORK/log/runf.txt" && echo yes || echo no)"
  RC=$(runf "$WORK/fpm-nouser.sh" --dry-run)
  expect "  풀 계정이 없음 — dry-run 도 1" "1" "$RC"

  fi   # FPM_COPY_OK
else
  skip "⑦ FPM 반영 스크립트 사본 시험" "ETC_WANT 판을 /etc·저장소 이력에서 찾지 못함"
fi

sub "DB 스크립트 사본 — 전역 ALTER ROLE 대신 IN DATABASE $DBH (빈 임시 DB) · psql 은 sudo -n"
mkdir -p "$WORK/H/app/db/migrations"
mk_mig() {  # $1 statement_timeout 값 — 임시 DB 한정 판을 사본 앱에 쓴다
  sed -e "s/^ALTER ROLE tbox_app SET /ALTER ROLE tbox_app IN DATABASE $DBH SET /" -e "s/statement_timeout = '15s'/statement_timeout = '$1'/" "$MIG" \
    > "$WORK/H/app/db/migrations/$(basename "$MIG")"
}
mk_db_copy() {  # $1 출력 · $2 MIG_WANT
  sed -e '/^\[ "\$(id -u)" -eq 0 \]/d' -e "s#^APP=.*#APP=$WORK/H/app#" -e "s#^MIG_WANT=.*#MIG_WANT=$2#" \
      -e 's#^PSQL=(sudo -u postgres #PSQL=(sudo -n -u postgres #' \
      -e "s#s.setdatabase = 0#s.setdatabase = (SELECT oid FROM pg_database WHERE datname = '$DBH')#" \
      -e "s#ALTER ROLE \$ROLE RESET#ALTER ROLE \$ROLE IN DATABASE $DBH RESET#g" \
      -e "s#^OVERRIDE_DB=.*#OVERRIDE_DB=$SRC_DB#" \
      -e "s#ALTER ROLE '\"\$ROLE\"' SET (statement_timeout#ALTER ROLE '\"\$ROLE\"' IN DATABASE [a-z0-9_]+ SET (statement_timeout#" \
      -e "s#^APP_USER=.*#APP_USER=$(id -un)#" -e "s#^AS_APP=.*#AS_APP=(env)#" \
      -e "s#^L8082=.*#L8082=http://127.0.0.1:$P82#" -e "s#^L80=.*#L80=http://127.0.0.1:$P80#" "$DB_SH" > "$1"
  grep -q "IN DATABASE $DBH RESET" "$1" && grep -q "datname = '$DBH')" "$1" && grep -q '^AS_APP=(env)$' "$1" && ! grep -nE '(sudo -u postgres|/var/www/qaredi|ALTER ROLE \$ROLE RESET|runuser)' "$1" | grep -vE '^[0-9]+:[[:space:]]*(#|echo )'
}
mk_mig 15s; MIGH="$WORK/H/app/db/migrations/$(basename "$MIG")"
# 사본 정리 검사(운영 경로·전역 ALTER 가 남지 않았나)가 「left」면 이 절의 사본을 **돌리지 않는다**(독립 검토 L6 — 전에는 적기만 하고 계속 돌렸다)
if mk_db_copy "$WORK/db-apply.sh" "$(sha "$MIGH")"; then DB_COPY_OK=1; expect "사본에 전역 ALTER·운영 경로가 남지 않았다" "ok" "ok"; else DB_COPY_OK=0; expect "사본에 전역 ALTER·운영 경로가 남지 않았다" "ok" "left"; fi
if [ "$DB_COPY_OK" != 1 ]; then
  skip "⑦ DB 사본 dry-run·본 실행" "사본에 운영 경로·전역 ALTER 가 남았다 — 사본을 돌리지 않는다"
else
expect "사본 마이그레이션은 IN DATABASE $DBH 두 줄" "2" "$(grep -c "^ALTER ROLE tbox_app IN DATABASE $DBH SET" "$MIGH")"
TARGET="idle_in_transaction_session_timeout=$IT_VAL,statement_timeout=$ST_VAL"
OTHERS0=$(psqlpg "SELECT string_agg(rolname || '=' || coalesce(array_to_string(rolconfig, ','), '(none)'), ' ' ORDER BY rolname) FROM pg_roles WHERE rolname LIKE 'tbox\_%'")

RC=$(runf "$WORK/db-apply.sh" --dry-run)
expect "dry-run 통과 → 0 · 쓰지 않음" "0|(none)" "$RC|$(dbrole_conf "$DBH" tbox_app)"
grep -E '보낼 문장|ALTER ROLE|\[dry\]' "$WORK/log/runf.txt" | head -4 | sed 's/^/    | /'

printf -- '-- 누가 고침\n' >> "$MIGH"
RC=$(runf "$WORK/db-apply.sh" --dry-run)
expect "dry-run — 마이그레이션이 검토 통과 판이 아님 → 1" "1" "$RC"
RC=$(runf "$WORK/db-apply.sh")
expect "본 실행 — 같은 이유 → 1 · 쓰지 않음" "1|(none)" "$RC|$(dbrole_conf "$DBH" tbox_app)"
mk_mig 15s

psqlpg "ALTER ROLE tbox_app IN DATABASE $DBH SET statement_timeout = '5s'" >/dev/null
RC=$(runf "$WORK/db-apply.sh" --dry-run)
expect "dry-run — tbox_app 설정이 알려진 상태가 아님 → 1" "1" "$RC"
RC=$(runf "$WORK/db-apply.sh")
expect "본 실행 — 같은 이유 → 1 · 덮어쓰지 않음" "1|statement_timeout=5s" "$RC|$(dbrole_conf "$DBH" tbox_app)"
psqlpg "ALTER ROLE tbox_app IN DATABASE $DBH RESET statement_timeout" >/dev/null

RC=$(runf "$WORK/db-apply.sh")
expect "본 실행 — 통과 → 0" "0" "$RC"
[ "$RC" = 0 ] || grep -E '✖' "$WORK/log/runf.txt" | head -6 | sed 's/^/    | /'
grep -E '✔' "$WORK/log/runf.txt" | head -12 | sed 's/^/    | /'
expect "  임시 DB 의 tbox_app 설정 = 목표 · 전역 tbox_app 그대로" "$TARGET|$GLOBAL_BEFORE" "$(dbrole_conf "$DBH" tbox_app)|$(global_conf tbox_app)"
expect "  다른 tbox_ 역할 설정 그대로(카탈로그)" "$OTHERS0" \
  "$(psqlpg "SELECT string_agg(rolname || '=' || coalesce(array_to_string(rolconfig, ','), '(none)'), ' ' ORDER BY rolname) FROM pg_roles WHERE rolname LIKE 'tbox\_%'")"
RC=$(runf "$WORK/db-apply.sh")
expect "  한 번 더 — 이미 적용 → 확인만 하고 0" "0|yes" "$RC|$(grep -q '이미 목표값' "$WORK/log/runf.txt" && echo yes || echo no)"
psqlpg "ALTER ROLE tbox_app IN DATABASE $DBH RESET ALL" >/dev/null

mk_mig 14s; mk_db_copy "$WORK/db-bad.sh" "$(sha "$MIGH")" >/dev/null
RC=$(runf "$WORK/db-bad.sh")
expect "본 실행 — 적용 뒤 확인 실패(14s 판) → RESET 으로 되돌림 · 4" "4|(none)" "$RC|$(dbrole_conf "$DBH" tbox_app)"
grep -E '✖|되돌림' "$WORK/log/runf.txt" | head -4 | sed 's/^/    | /'
fi   # DB_COPY_OK
fi   # HAVE_SH — ⑦

# ════════════════════════════════════════════════════════════════
hr "끝 — 시험이 운영에 남긴 것 0"
expect "전역 tbox_app 역할 설정 — 시작할 때와 같다" "$GLOBAL_BEFORE" "$(global_conf tbox_app)"
expect "tbox_re 에 건 tbox_app 설정(IN DATABASE) 0" "(none)" "$(dbrole_conf "$SRC_DB" tbox_app)"
expect "운영 /etc 풀 파일 해시 — 시작할 때와 같다(읽기만)" "$ETC_BEFORE" "$(sha "$ETC_POOL")"
note "uncaught·fatal 로그(시험 인스턴스 A): $( [ -f "$WORK/A/log/app.log" ] && grep -c 'uncaught\|fatal' "$WORK/A/log/app.log" || echo 0)"

hr "결과"
printf '통과 %d · 실패 %d · 건너뜀 %d\n' "$PASS" "$FAIL" "$SKIP"
note "임시 DB($DB · $DBH)·임시 폴더·임시 php-fpm·nginx 는 이 스크립트가 끝나며 지워진다."
[ "$FAIL" -eq 0 ] || exit 1
exit 0
