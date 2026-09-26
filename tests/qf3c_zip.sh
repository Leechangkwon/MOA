#!/usr/bin/env bash
# tests/qf3c_zip.sh — 개발 대기열 6 · Q:F-3a 라운드 C 수락 시험 — 여러 파일을 zip 으로 받기(`POST /api/files/zip`)
#                     (지시서 /var/www/qaredi/docs/qf3a-favorites-recent-bulk-directive.md 1.4 · 3장 QF3A-T8 · 결정 M-5)
#
#   C-T1 권한        남의 팀(team_mismatch) · admin-only(role_not_permitted) · Guest · 「Guest 로 보기」 중 관리자 · 없는 id · 지운 파일 →
#                    zip 에 안 들어가고 응답은 `X-Zip-Skipped` 개수만 · 응답 전체(머리+본문)에 뺀 파일 이름 0 · 모두 못 받으면 403 `skipped` ·
#                    감사 `file.download deny <사유>` detail via=zip(없는 id·지운 파일은 한 건 받기처럼 행 없음)
#   C-T2 추가 거부   검사 중(scan_pending) · 격리(scan_infected) · 실행 파일 압축본(관리자 — exec_ack_required) → 빠짐 · 감사 행
#   C-T3 한도        101개 400(이유 문장) · 100개 받음 · 합계 500MB 넘으면 400(이유 문장 · `file.zip deny too_large`) · 딱 500MB 는 400 아님
#   C-T4 이름        `../`·절대 경로·`C:\`·제어 문자·`..`·`:`·긴 이름·같은 이름(대소문자) → 파일 이름만 · `이름 (2).확장자` · `/`·`\`·`..`·제어 문자 0 ·
#                    한글 이름은 UTF-8 표시(0x800) · 압축 없이 담기(STORED)
#   C-T5 바이트      zip 안 바이트 sha256 = DB sha256 = 올린 바이트 · 무결성 실패(바이트 바꿈·파일 없음)는 그 파일만 빠지고 `file.download error` via=zip
#   C-T6 감사        파일마다 `file.download allow`(granted_by · via=zip) + 묶음 `file.zip allow`(requested·added·skipped·size_bytes) 한 줄 ·
#                    zip 으로 받은 파일이 「최근」에 나온다
#   C-T7 관문        CSRF 없음 403(csrf 행) · 미로그인 401 · `X-API-Token` 만(세션 없음) 401 — 토큰 창구 아님 · GET 405 · 본문 형식 오류 400 ×6
#   C-T8 임시 파일    응답 뒤 `zip-*` 0 · 손님이 도중에 끊어도 0 · 만드는 동안의 임시 파일 0600 · 공간 모자람(작은 tmpfs 인스턴스) 503 + `file.zip error no_space` 뒤 0
#   C-T9 동시        워커 넷 인스턴스 — 같은 사람 둘째 zip 429(kind=user · Retry-After) · 사이트 슬롯 둘이 차면 셋째 사람 429(kind=site) · 풀리면 200 ·
#                    (검토 L-C1) 다른 슬롯이 쥐고 적어 둔 몫을 여유에서 뺀다 — 쥔 슬롯의 큰 몫 → 503 no_space(reserved_bytes) · 안 쥔 슬롯의 옛 값은 무시 ·
#                    내 슬롯 파일에 2 × 합계를 적는다
#   C-T12 시간 한도   (검토 L-C2) TBOX_ZIP_TIME_BUDGET 을 0 으로 낮춘 **사본 인스턴스**(기본 8180) — 503 · file.zip error zip_failed(why=time) · allow 행 0 · zip-* 0
#   C-T10 한 건 받기  `_download_common.php` 로 옮긴 뒤에도 **옛 판(기준 판)과 같다** — 같은 임시 DB 에 옛 판 인스턴스를 하나 더 띄우고 열두 갈래
#                    (허용 · 역할 · 팀 · Guest · 실행 압축본 · 검사 중 · 격리 · 없는 id · 형태 틀림 · 지운 파일 · 해시 다름 · 바이트 없음)를
#                    같은 세션으로 두 판에 보내 상태 · 본문 · 머리 · 감사 행(action·result·reason·대상·문장·detail)이 글자까지 같은지
#   C-T11 정적       새 라우트 정확히 한 줄(`public/index.php` 기준 판 대비 — 더한 라우트 1 · 뺀 줄 0 · 주석 아닌 더한 줄 1) · 토큰 창구 목록 불변 ·
#                    바뀐 PHP php -l · 보안 린트 0 · 서버 로그 새 PHP 오류·SQLSTATE 0
#   500MB(선택)      QF3C_BIG=1 이면 100MB × 5 실제 바이트로 zip — 머리까지·끝까지 시간 · 본 인스턴스 최대 RSS(시간 한도 대조용 · 디스크 1.5GB 더 씀)
#   화면(선택)       QF3C_BROWSER=1 이면 tests/browser/qf3c_zip.py(일괄 막대 → zip 받기 → 내려받은 파일 · 360·412·1280 × 라이트·다크)
#   회귀(선택)       REGRESS=1 이면 같은 임시 인스턴스에서 REGRESS_LIST(기본 stage26 help_button f68 api_read api_whoami api_folders f65 —
#                    f65 는 (d) 경보 배치 사본만 SKIP. f54 는 운영 검사 잠금·격리 폴더에 닿아 이 틀로 돌리지 않는다)
#
# ★ 운영 DB·운영 포트를 건드리지 않는다 — 임시 DB `tbox_qf3c_<포트>_test`(운영 pg_dump 를 **읽어** 파이프로 붓기만) · 임시 인스턴스
#   `php -S 127.0.0.1:<포트>` 넷(기본 8170 본 · 8172 워커 넷 · 8174 작은 공간 · 8176 기준 판). 검토 계정 claude_ 다섯(F-71). 비밀은 표준입력으로만(R-62).
#   임시 .env 원본 — 복제본에는 .env 가 없다: mktemp 폴더(0700)의 0600 사본을 QF2_ENV_SRC 로 넘기고 끝에 지운다.
#   이 인스턴스의 .env 는 DB_NAME·STORAGE_DIR·AUDIT_LOG_FILE 을 임시로, SCAN_ENABLED=0(올린 파일의 검사 상태는 시험이 SQL 로 정한다).
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
. "$ROOT/tests/lib/review_accounts.sh" || { echo "tests/lib/review_accounts.sh 를 읽지 못했다 — 시험을 시작하지 않는다"; exit 3; }
. "$ROOT/tests/lib/secrets.sh" || { echo "tests/lib/secrets.sh 를 읽지 못했다 — 시험을 시작하지 않는다 (R-62)"; exit 3; }
PORT="${QF3C_PORT:-8170}"
BPORT="${QF3C_BURST_PORT:-8172}"   # 워커 넷 — 동시 zip · 손님 끊김
SPORT="${QF3C_SMALL_PORT:-8174}"   # 앱 임시 폴더가 작은 tmpfs 인 인스턴스 — 공간 모자람
OPORT="${QF3C_OLD_PORT:-8176}"     # 기준 판 코드 — 한 건 받기 옛 판·새 판 대조
TPORT="${QF3C_TIME_PORT:-8180}"    # 시간 한도를 0 으로 낮춘 사본 — L-C2
MPORT="${QF3C_SHM_PORT:-8182}"     # 앱 임시 폴더가 /dev/shm(약 2GB tmpfs) 인 인스턴스 — L-C1 다른 슬롯 몫(결과가 디스크 여유에 흔들리지 않게)
for p in "$PORT" "$BPORT" "$SPORT" "$OPORT" "$TPORT" "$MPORT"; do case "$p" in 80|8082|8081|"") echo "운영 포트($p)·빈 포트는 쓰지 않는다"; exit 3 ;; esac; done
[ "$(printf '%s\n' "$PORT" "$BPORT" "$SPORT" "$OPORT" "$TPORT" "$MPORT" | sort -u | wc -l)" = "6" ] || { echo "포트 여섯이 서로 달라야 한다"; exit 3; }
SITE="http://127.0.0.1:$PORT"
BASE="$SITE/tbox"
UA="tbox-review/$(date +%F)"
SRC_DB="tbox_re"                     # 운영 — **읽기만** 한다(pg_dump)
DB="tbox_qf3c_${PORT}_test"          # 임시 — 여기에만 쓴다
BASE_REV="${QF3C_BASE_REV:-5de7467}" # 라운드 C 를 시작한 운영 판(새 라우트 한 줄 · 한 건 받기 옛 판)
JAR_DIR="$(mktemp -d)"
WORK="$(mktemp -d)"
chmod 700 "$JAR_DIR" "$WORK"
SHOTS="${QF3C_SHOTS:-$WORK/shots}"
INST=""; INST_S=""; INST_O=""; INST_T=""; INST_M=""; SMALL=""; SHM=""
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
  # 워커 넷(PHP_CLI_SERVER_WORKERS)은 주인의 자식이다 — 자식 먼저, 그다음 주인, 끝으로 이 포트들의 php -S 만(앞에 고정)
  local pid p
  for pid in "${PIDS[@]}"; do
    [ -n "$pid" ] && { pkill -P "$pid" 2>/dev/null; kill "$pid" 2>/dev/null; wait "$pid" 2>/dev/null; }
  done
  for p in "$PORT" "$BPORT" "$SPORT" "$OPORT" "$TPORT" "$MPORT"; do pkill -f "^php -S 127\.0\.0\.1:$p " 2>/dev/null; done
  sudo -n -u postgres dropdb --if-exists "$DB" >/dev/null 2>&1
  [ -n "$INST" ] && rm -rf "$INST"
  [ -n "$INST_S" ] && rm -rf "$INST_S"
  [ -n "$INST_O" ] && rm -rf "$INST_O"
  [ -n "$INST_T" ] && rm -rf "$INST_T"
  [ -n "$INST_M" ] && rm -rf "$INST_M"
  [ -n "$SHM" ] && rm -rf "$SHM"
  [ -n "$SMALL" ] && rm -rf "$SMALL"
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
wait_up() {   # $1 사이트 — 미로그인 GET /tbox/login 200(감사 행 0 — 임시 DB 라도 행을 만들지 않는 화면으로)
  local i c=""
  for i in $(seq 1 30); do
    sleep 0.3
    c=$(curl -s -o /dev/null -w '%{http_code}' -A "$UA" "$1/tbox/login")
    [ "$c" = "200" ] && break
  done
  printf '%s' "$c"
}

# ════════════════════════════════════════════════════════════════
hr "Q:F-3a 라운드 C (zip 으로 받기) — 임시 인스턴스 준비 (운영 DB 에는 쓰지 않는다)"
avail_kb=$(df -Pk / | awk 'NR==2{print $4}')
echo "루트 파티션 여유: $(df -Ph / | awk 'NR==2{print $4}')"
[ "$avail_kb" -ge 1572864 ] || { echo "루트 디스크 여유 1.5GB 미만 — 시험을 시작하지 않는다 (SECURITY R-80 · 이 시험은 수백 MB 를 잠시 쓴다)"; exit 3; }
sudo -n -u postgres psql -X -d "$SRC_DB" -tAc "SELECT 1" >/dev/null 2>&1 \
  || { echo "psql(postgres) 을 쓸 수 없다 — 이 시험은 임시 DB 가 없으면 성립하지 않는다"; exit 3; }
for p in "$PORT" "$BPORT" "$SPORT" "$OPORT" "$TPORT" "$MPORT"; do
  if ss -ltn 2>/dev/null | grep -q "127.0.0.1:$p "; then echo "포트 $p 이 이미 쓰이고 있다 — 중단"; exit 3; fi
done
ENV_SRC="${QF2_ENV_SRC:-$ROOT/.env}"
[ -f "$ENV_SRC" ] || { echo "임시 .env 원본이 없다 — QF2_ENV_SRC 로 0600 사본을 넘긴다"; exit 3; }
git -C "$ROOT" rev-parse -q --verify "$BASE_REV^{commit}" >/dev/null 2>&1 || { echo "기준 판 $BASE_REV 이 저장소에 없다 — 옛 판 대조·라우트 한 줄을 볼 수 없다"; exit 3; }
PHP_ZIP=$(php -r 'echo class_exists("ZipArchive") ? "yes" : "no";')
expect "php 의 zip 확장(ZipArchive)" "yes" "$PHP_ZIP"
expect "FPM 에도 zip 확장이 켜져 있다(/etc/php/8.1/fpm/conf.d/20-zip.ini — 읽기만)" "yes" "$([ -e /etc/php/8.1/fpm/conf.d/20-zip.ini ] && echo yes || echo no)"
PROD_A0=$(sudo -n -u postgres psql -X -d "$SRC_DB" -tAc "SELECT coalesce(max(id),0) FROM audit_logs" 2>/dev/null)
note "운영 감사 max(id) 시작 = ${PROD_A0:-?} (읽기만 — 끝에서 이 시험의 UA 행이 늘지 않았는지 적는다)"

sub "① 운영 DB 를 **읽어** 임시 DB 를 만든다(파이프) + 마이그레이션(있으면 그대로)"
sudo -n -u postgres dropdb --if-exists "$DB" >/dev/null 2>&1
sudo -n -u postgres createdb -O tbox_owner "$DB" >/dev/null 2>&1 || { echo "임시 DB 를 만들 수 없다"; exit 3; }
sudo -n -u postgres pg_dump -d "$SRC_DB" 2>/dev/null \
  | sudo -n -u postgres psql -X -q -d "$DB" -v ON_ERROR_STOP=0 >/dev/null 2>"$WORK/restore.err"
DUMP_PS=("${PIPESTATUS[@]}")
[ "${DUMP_PS[0]}" -eq 0 ] || { echo "운영 DB 를 읽지 못했다 (pg_dump)"; exit 3; }
[ -n "$(psqlq "SELECT count(*) FROM files")" ] || { echo "임시 DB 로 옮기지 못했다 — $(head -2 "$WORK/restore.err")"; exit 3; }
for m in 2026-10-01_qf2_search.sql 2026-10-02_qf2b_files_search.sql 2026-10-03_qf3a_favorites_recent.sql 2026-10-05_qf3b_purge_favorites.sql; do
  [ -f "$ROOT/db/migrations/$m" ] || continue
  sudo -n -u postgres psql -X -q -d "$DB" -v ON_ERROR_STOP=1 -f - < "$ROOT/db/migrations/$m" 2>&1 | grep -v 'could not change directory\|already exists, skipping' | sed 's/^/    /'
done
note "임시 DB $DB — 파일 $(psqlq "SELECT count(*) FROM files") · 감사 $(psqlq "SELECT count(*) FROM audit_logs")"

sub "② 인스턴스 넷 — 본(한 워커) · 워커 넷 · 작은 공간 · 기준 판($BASE_REV). .env 는 사본(값은 출력하지 않는다)"
INST="$(mktemp -d)"; chmod 700 "$INST"
mkdir -p "$INST/app" "$INST/storage" "$INST/tmp" "$INST/log"
chmod 700 "$INST/tmp"                       # 운영 /var/lib/tbox-v2/tmp 와 같은 0700 (deploy/install.sh)
tar -C "$ROOT" -cf - --exclude=.git --exclude=jar --exclude=api-test.txt --exclude=./.env --exclude=./.claude . | tar -C "$INST/app" -xf -
make_env "$ENV_SRC" "$INST/app/.env" "$INST/storage" "$INST/log/audit.log" || { echo "임시 .env 에 임시 값이 들어가지 않았다 — 중단"; exit 4; }
ln -s /var/www/qaredi/community "$INST/community" 2>/dev/null
make_router "$INST/app"
( cd "$INST/app" && exec php -S "127.0.0.1:$PORT" -t public router.php > "$INST/server.log" 2>&1 ) &
PIDS+=("$!")
( cd "$INST/app" && PHP_CLI_SERVER_WORKERS=4 exec php -S "127.0.0.1:$BPORT" -t public router.php > "$INST/server_burst.log" 2>&1 ) &
PIDS+=("$!")
# 작은 공간 — 앱 임시 폴더(STORAGE_DIR 의 형제 tmp)를 /run/user/<나> tmpfs(수백 MB) 안 폴더로 잇는다. 코드에 시험용 갈래를 두지 않는다.
INST_S="$(mktemp -d)"; chmod 700 "$INST_S"
mkdir -p "$INST_S/app" "$INST_S/storage" "$INST_S/log"
RUNDIR="/run/user/$(id -u)"
if [ -d "$RUNDIR" ] && [ -w "$RUNDIR" ]; then
  SMALL="$(mktemp -d "$RUNDIR/qf3c-small.XXXXXX")"; chmod 700 "$SMALL"
  ln -s "$SMALL" "$INST_S/tmp"
fi
tar -C "$INST/app" -cf - --exclude=./.env . | tar -C "$INST_S/app" -xf -
make_env "$ENV_SRC" "$INST_S/app/.env" "$INST_S/storage" "$INST_S/log/audit.log" || { echo "작은 공간 .env 실패 — 중단"; exit 4; }
ln -s /var/www/qaredi/community "$INST_S/community" 2>/dev/null
( cd "$INST_S/app" && exec php -S "127.0.0.1:$SPORT" -t public router.php > "$INST_S/server.log" 2>&1 ) &
PIDS+=("$!")
# 기준 판 — git archive(작업 트리가 아니라 그 판의 파일). 저장소는 본 인스턴스와 **같은 폴더**(같은 파일을 두 판이 내준다)
INST_O="$(mktemp -d)"; chmod 700 "$INST_O"
mkdir -p "$INST_O/app" "$INST_O/log"
git -C "$ROOT" archive "$BASE_REV" | tar -C "$INST_O/app" -xf -
make_env "$ENV_SRC" "$INST_O/app/.env" "$INST/storage" "$INST_O/log/audit.log" || { echo "기준 판 .env 실패 — 중단"; exit 4; }
ln -s /var/www/qaredi/community "$INST_O/community" 2>/dev/null
make_router "$INST_O/app"
( cd "$INST_O/app" && exec php -S "127.0.0.1:$OPORT" -t public router.php > "$INST_O/server.log" 2>&1 ) &
PIDS+=("$!")
# 시간 한도 사본 — 본 사본에서 `const TBOX_ZIP_TIME_BUDGET = 50;` 한 줄만 0 으로(그 줄이 정확히 하나 바뀌었는지 본다). 저장소·임시 폴더는 본 인스턴스와 같다
INST_T="$(mktemp -d)"; chmod 700 "$INST_T"
mkdir -p "$INST_T/app" "$INST_T/log"
tar -C "$INST/app" -cf - --exclude=./.env . | tar -C "$INST_T/app" -xf -
sed -i 's#^const TBOX_ZIP_TIME_BUDGET = 50;$#const TBOX_ZIP_TIME_BUDGET = 0;#' "$INST_T/app/src/handlers/files_zip.php"
expect "시간 한도 사본 — files_zip.php 에서 바뀐 줄 = 그 상수 한 줄" "1" "$(diff "$INST/app/src/handlers/files_zip.php" "$INST_T/app/src/handlers/files_zip.php" | grep -c '^>')"
make_env "$ENV_SRC" "$INST_T/app/.env" "$INST/storage" "$INST_T/log/audit.log" || { echo "시간 한도 사본 .env 실패 — 중단"; exit 4; }
ln -s /var/www/qaredi/community "$INST_T/community" 2>/dev/null
( cd "$INST_T/app" && exec php -S "127.0.0.1:$TPORT" -t public router.php > "$INST_T/server.log" 2>&1 ) &
PIDS+=("$!")
# /dev/shm 인스턴스 — 저장소는 빈 폴더(이 인스턴스의 zip 은 공간 확인까지만 본다 — 통과하면 바이트가 없어 403), 임시 폴더는 /dev/shm 안
INST_M="$(mktemp -d)"; chmod 700 "$INST_M"
mkdir -p "$INST_M/app" "$INST_M/storage" "$INST_M/log"
if [ -d /dev/shm ] && [ -w /dev/shm ]; then
  SHM="$(mktemp -d /dev/shm/qf3c-shm.XXXXXX)"; chmod 700 "$SHM"
  ln -s "$SHM" "$INST_M/tmp"
fi
tar -C "$INST/app" -cf - --exclude=./.env . | tar -C "$INST_M/app" -xf -
make_env "$ENV_SRC" "$INST_M/app/.env" "$INST_M/storage" "$INST_M/log/audit.log" || { echo "shm 인스턴스 .env 실패 — 중단"; exit 4; }
ln -s /var/www/qaredi/community "$INST_M/community" 2>/dev/null
( cd "$INST_M/app" && exec php -S "127.0.0.1:$MPORT" -t public router.php > "$INST_M/server.log" 2>&1 ) &
PIDS+=("$!")
for s in "$SITE" "http://127.0.0.1:$BPORT" "http://127.0.0.1:$SPORT" "http://127.0.0.1:$OPORT" "http://127.0.0.1:$TPORT" "http://127.0.0.1:$MPORT"; do
  c=$(wait_up "$s")
  expect "인스턴스가 떴다($s — 로그인 화면 200)" "200" "$c"
  [ "$c" = "200" ] || { echo "인스턴스를 띄우지 못했다 — 중단"; tail -5 "$INST/server.log"; exit 4; }
done
expect "기준 판 인스턴스에는 zip 창구가 없다(파일 없음)" "no" "$([ -e "$INST_O/app/src/handlers/files_zip.php" ] && echo yes || echo no)"

sub "로그인 (claude_ 검토 계정 다섯, 순차, F-71)"
for who in claude_employee01 claude_employee02 claude_manager01 claude_admin01 claude_guest01; do
  login "$who" || { echo "  ✖ $who 로그인 실패 — 중단"; exit 4; }
done
S_E1=$(session_of claude_employee01); S_E2=$(session_of claude_employee02); S_M1=$(session_of claude_manager01)
S_A1=$(session_of claude_admin01);   S_G1=$(session_of claude_guest01)
PW_A1=$(review_password claude_admin01)
note "세션 다섯 받음(값은 출력하지 않는다)"
# 서버 로그의 PHP 오류 줄 — 한 건 받기 「바이트 없음」 갈래가 **일부러** 내는 예외(옛 판·새 판 모두 bootstrap 이 `uncaught … 저장된 파일을 찾을 수 없습니다`)는 뺀다
server_errs() { cat "$INST"/server*.log "$INST_S/server.log" "$INST_O/server.log" "$INST_T/server.log" "$INST_M/server.log" 2>/dev/null | grep -iE 'PHP (Fatal|Warning|Notice|Deprecated)|SQLSTATE|Uncaught' | grep -vc '저장된 파일을 찾을 수 없습니다'; }
errs_before=$(server_errs)

# ─── 시험 몸통(파이썬) — 세션 다섯 + 관리자 비밀번호(재인증)는 표준입력 한 줄씩(R-62). 임시 DB 는 sudo psql 로만 본다. ─────
cat > "$WORK/drv.py" <<'PY'
import fcntl, hashlib, http.client, io, json, os, re, shutil, subprocess, sys, time, urllib.error, urllib.request, uuid, zipfile
SITE, UA, DB, BSITE, SSITE, OSITE, INST, SMALL, WORK, TSITE, MSITE, SHM = sys.argv[1:13]
S = {w: sys.stdin.readline().rstrip('\n') for w in ('e1', 'e2', 'm1', 'a1', 'g1')}
PW_A1 = sys.stdin.readline().rstrip('\n')
P = F = 0
def ok(name, cond, detail=''):
    global P, F
    if cond: P += 1; print('  ✔ ' + name)
    else: F += 1; print('  ✖ ' + name + ('  — ' + str(detail)[:400] if detail != '' else ''))
def sql(q):
    r = subprocess.run(['sudo', '-n', '-u', 'postgres', 'psql', '-X', '-d', DB, '-t', '-A', '-c', q], capture_output=True, text=True, cwd='/')
    return r.stdout.strip()
CSRF = {}
def req(who, m, path, body=None, raw=None, ctype=None, csrf=True, site=None, extra=None):
    h = {'User-Agent': UA}
    if who: h['Cookie'] = 'tbox_session=' + S[who]
    if m != 'GET' and csrf and who: h['X-CSRF-Token'] = CSRF.get(who, '')
    data = raw
    if body is not None:
        data = json.dumps(body, ensure_ascii=False).encode('utf-8'); h['Content-Type'] = 'application/json'
    if ctype: h['Content-Type'] = ctype
    if extra: h.update(extra)
    r = urllib.request.Request((site or SITE) + path, data=data, headers=h, method=m)
    try:
        with urllib.request.urlopen(r, timeout=120) as x:
            b = x.read(); c = x.status; hd = dict(x.headers.items())
    except urllib.error.HTTPError as e:
        b = e.read(); c = e.code; hd = dict(e.headers.items())
    try: d = json.loads(b or b'{}')
    except Exception: d = None
    return c, d, b, hd
def zipreq(who, ids, site=None, **kw):
    return req(who, 'POST', '/tbox/api/files/zip', {'ids': ids}, site=site, **kw)
for w in S:
    CSRF[w] = (req(w, 'GET', '/tbox/api/me')[1] or {}).get('csrf_token', '')
ok('세션 다섯의 CSRF 토큰', all(len(CSRF[w]) > 10 for w in S))
def upload(who, name, level='internal', team=None, content=None):
    b = uuid.uuid4().hex; parts = []
    fields = [('access_level', level), ('description', 'qf3c 시험 파일')]
    if team is not None: fields.append(('team_id', str(team)))
    for k, v in fields:
        parts.append(('--%s\r\nContent-Disposition: form-data; name="%s"\r\n\r\n%s\r\n' % (b, k, v)).encode())
    body = content if content is not None else ('본문 ' + name + ' ' + uuid.uuid4().hex + '\n').encode()
    parts.append(('--%s\r\nContent-Disposition: form-data; name="file"; filename="%s"\r\nContent-Type: text/plain\r\n\r\n' % (b, name)).encode() + body + b'\r\n')
    parts.append(('--%s--\r\n' % b).encode())
    c, d, raw, _ = req(who, 'POST', '/tbox/api/files', raw=b''.join(parts), ctype='multipart/form-data; boundary=' + b)
    pid = ((d or {}).get('file') or d or {}).get('id', '')
    return pid, hashlib.sha256(body).hexdigest(), body
def fid(pub): return sql("SELECT id FROM files WHERE public_id = '%s'" % pub)
def stored(pub): return sql("SELECT stored_name FROM files WHERE public_id = '%s'" % pub)
def spath(pub, base=None):
    s = stored(pub); return os.path.join(base or (INST + '/storage'), s[0:2], s[2:4], s)
def audit_max(): return int(sql("SELECT coalesce(max(id), 0) FROM audit_logs") or 0)
def arows(a, b, where='TRUE'):
    # JSON 한 덩이로 받는다 — 대상 이름(파일 이름)에 제어 문자가 들어 있어도(이 시험이 일부러 넣는다) 칸이 어긋나지 않게
    q = ("SELECT coalesce(json_agg(json_build_array(action, result, coalesce(reason,''), coalesce(target_type,''), coalesce(target_id::text,''), "
         "coalesce(target_name,''), coalesce(access_level,''), summary, detail) ORDER BY id), '[]') "
         "FROM audit_logs WHERE id > %d AND id <= %d AND %s" % (a, b, where))
    return json.loads(sql(q) or '[]')
def zlist(raw):
    z = zipfile.ZipFile(io.BytesIO(raw))
    return [(i.filename, i.compress_type, i.flag_bits, hashlib.sha256(z.read(i)).hexdigest()) for i in z.infolist()]
def leftovers(d):
    try: return sorted(n for n in os.listdir(d) if n.startswith('zip-'))
    except FileNotFoundError: return ['(폴더 없음)']
def no_left(d, wait=3.0):
    # 손님이 Content-Length 만큼 다 받은 뒤 서버의 readfile → unlink 까지 틈이 있다 — 그만큼만 기다린다(지우지 않는 결함은 3초 뒤에도 남는다)
    end = time.time() + wait
    while True:
        if leftovers(d) == []: return True
        if time.time() > end: return False
        time.sleep(0.1)
TMPD = INST + '/tmp'
uid = {n: sql("SELECT id FROM users WHERE username = 'claude_%s'" % n) for n in ('employee01', 'employee02', 'manager01', 'admin01', 'guest01')}
tag = uuid.uuid4().hex[:6]
CD_RE = re.compile(r'^attachment; filename="(t-box-\d{8}-\d{6}\.zip)"; filename\*=UTF-8\'\'(t-box-\d{8}-\d{6}\.zip)$')

print('\n── 준비 — 파일(앱 창구로 올림 · 검사 상태·이름은 임시 DB 에서 정함 · 이름 표식 %s)' % tag)
F1, F1h, _ = upload('e1', 'qf3c-a-%s.txt' % tag)
F2, F2h, _ = upload('e1', 'qf3c-b-%s.txt' % tag)
TEAM1, _, _ = upload('e1', 'qf3c-secret-team-%s.txt' % tag, 'team', uid and sql("SELECT team_id FROM users WHERE id = %s" % uid['employee01']))
ADM, ADMh, _ = upload('a1', 'qf3c-secret-admin-%s.txt' % tag, 'admin-only')
PUB, PUBh, _ = upload('a1', 'qf3c-pub-%s.txt' % tag, 'public')
PEND, _, _ = upload('e1', 'qf3c-secret-pending-%s.txt' % tag)
INF, _, _ = upload('e1', 'qf3c-secret-infected-%s.txt' % tag)
EXE, _, _ = upload('a1', 'qf3c-secret-exec-%s.txt' % tag, 'admin-only')
DEL, _, _ = upload('e1', 'qf3c-secret-deleted-%s.txt' % tag)
HM, _, _ = upload('e1', 'qf3c-hash-%s.txt' % tag)
MS, _, _ = upload('e1', 'qf3c-missing-%s.txt' % tag)
allp = [F1, F2, TEAM1, ADM, PUB, PEND, INF, EXE, DEL, HM, MS]
ok('파일 열하나 올리기(201)', all(len(x) == 36 for x in allp), allp)
sql("UPDATE files SET scan_status = 'clean' WHERE public_id IN (%s)" % ','.join("'%s'" % x for x in allp))
sql("UPDATE files SET scan_status = 'pending' WHERE public_id = '%s'" % PEND)
sql("UPDATE files SET scan_status = 'infected' WHERE public_id = '%s'" % INF)
sql("UPDATE files SET exec_content = true WHERE public_id = '%s'" % EXE)
c, _, _, _ = req('e1', 'DELETE', '/tbox/api/files/' + DEL)
ok('지운 파일 하나(휴지통) 200', c == 200, c)
# 이름 — DB 의 original_name 을 바꾼다(업로드 창구는 이런 이름을 받지 않는다 — 가져오기·옛 자료·DB 직접 수정을 흉내)
weird = [
    ('../../etc/passwd.txt', 'passwd.txt'), ('/abs/root.txt', 'root.txt'), ('C:\\win\\evil.txt', 'evil.txt'),
    ('ctl\x01\x1f\x7fname.txt', 'ctlname.txt'), ('..', 'file'), ('dup.txt', 'dup.txt'), ('dup.txt', 'dup (2).txt'),
    ('DUP.txt', 'DUP (3).txt'), ('a:b.txt', 'a_b.txt'), ('한글 보고서.txt', '한글 보고서.txt'), ('dir/sub/', 'sub'),
    ('.hidden', '.hidden'), ('.hidden', '.hidden (2)'), ('x' * 300 + '.txt', ('x' * 300 + '.txt')[:255]),
]
W = []
for i, (orig, want) in enumerate(weird):
    pid, h, body = upload('e1', 'qf3c-w%02d-%s.txt' % (i, tag))
    W.append((pid, h, orig, want))
sql("UPDATE files SET scan_status = 'clean' WHERE public_id IN (%s)" % ','.join("'%s'" % x[0] for x in W))
for pid, h, orig, want in W:
    lit = orig.replace("'", "''")
    sql("UPDATE files SET original_name = E'%s' WHERE public_id = '%s'" % (lit.replace('\\', '\\\\').replace('\x01', '\\x01').replace('\x1f', '\\x1f').replace('\x7f', '\\x7f'), pid))
ok('이상한 이름 %d개를 DB 에 넣음' % len(W), all(sql("SELECT original_name FROM files WHERE public_id = '%s'" % pid) == orig for pid, _, orig, _ in W),
   [(sql("SELECT original_name FROM files WHERE public_id = '%s'" % pid), orig) for pid, _, orig, _ in W][:3])

print('\n── C-T4 · C-T5 · C-T6 — claude_employee01 · 보통 파일 둘 + 이상한 이름 %d개' % len(W))
a0 = audit_max()
ids = [F1, F2] + [x[0] for x in W]
c, d, raw, hd = zipreq('e1', ids)
a1 = audit_max()
ok('200 · application/zip', c == 200 and hd.get('Content-Type', '').startswith('application/zip'), (c, hd.get('Content-Type'), raw[:200]))
m = CD_RE.match(hd.get('Content-Disposition', ''))
ok('Content-Disposition — attachment · ASCII filename + RFC 5987 filename*(한 건 받기와 같은 모양) · 서버가 만든 이름', m is not None and m.group(1) == m.group(2), hd.get('Content-Disposition'))
ok('X-Zip-Count = %d · X-Zip-Skipped = 0 · Cache-Control no-store · Content-Length = 본문' % len(ids),
   hd.get('X-Zip-Count') == str(len(ids)) and hd.get('X-Zip-Skipped') == '0' and hd.get('Cache-Control') == 'no-store' and hd.get('Content-Length') == str(len(raw)), hd)
try:
    ents = zlist(raw)
except Exception as e:
    ents = []; ok('zip 을 열 수 있다', False, e)
names = [e[0] for e in ents]
want_names = ['qf3c-a-%s.txt' % tag, 'qf3c-b-%s.txt' % tag] + [x[3] for x in W]
ok('안 이름 = 파일 이름만 · 정리 규칙 · 같은 이름은 「이름 (n)」(대소문자 무시)', names == want_names, list(zip(names, want_names)))
ok('안 이름에 / · \\ · .. 조각 · 제어 문자 · : · 빈 이름 0',
   all('/' not in n and '\\' not in n and n not in ('', '.', '..') and ':' not in n and not re.search(r'[\x00-\x1f\x7f]', n) for n in names), names)
ok('긴 이름은 255자', any(len(n) == 255 for n in names) and max(len(n) for n in names) <= 255, [len(n) for n in names])
ok('모든 항목이 압축 없이(STORED)', all(e[1] == zipfile.ZIP_STORED for e in ents), [e[1] for e in ents])
ok('한글 이름 항목에 UTF-8 표시(0x800)', any(e[0] == '한글 보고서.txt' and e[2] & 0x800 for e in ents))
want_sha = [F1h, F2h] + [x[1] for x in W]
db_sha = [sql("SELECT sha256 FROM files WHERE public_id = '%s'" % x) for x in ids]
ok('zip 안 바이트 sha256 = 올린 바이트 = DB sha256(항목마다)', [e[3] for e in ents] == want_sha == db_sha)
ok('응답 뒤 앱 임시 폴더에 zip-* 0', no_left(TMPD), leftovers(TMPD))
rows = arows(a0, a1, "actor_name = 'claude_employee01'")
dl = [r for r in rows if r[0] == 'file.download']
ok('감사 file.download allow %d행 — 파일마다 한 줄(보낸 순서 · 대상 id)' % len(ids),
   [(r[1], r[4]) for r in dl] == [('allow', fid(x)) for x in ids], [(r[1], r[4]) for r in dl][:4])
dj = [(r[8] or {}) for r in dl]
ok('  detail — via=zip · granted_by · sha256 · size_bytes · owner_id · route=POST /api/files/zip',
   all(x.get('via') == 'zip' and x.get('granted_by') and len(x.get('sha256', '')) == 64 and x.get('route') == 'POST /api/files/zip' and 'owner_id' in x for x in dj), dj[:1])
zr = [r for r in rows if r[0] == 'file.zip']
zd = (zr[0][8] or {}) if len(zr) == 1 else {}
ok('묶음 한 줄 file.zip allow — requested=%d · added=%d · skipped=0 · size_bytes · target_name = zip 이름' % (len(ids), len(ids)),
   len(zr) == 1 and zr[0][1] == 'allow' and zd.get('requested') == len(ids) and zd.get('added') == len(ids) and zd.get('skipped') == 0
   and zd.get('size_bytes', 0) > 0 and zr[0][5] == (m.group(1) if m else '?') and 'zip' in zr[0][7], zr)
ok('  감사 행은 파일 %d + 묶음 1 뿐(그 밖 0)' % len(ids), len(rows) == len(ids) + 1, [r[0] for r in rows])
c, d, _, _ = req('e1', 'GET', '/tbox/api/files/recent')
rec = [x.get('id') for x in (d or {}).get('files', [])]
ok('zip 으로 받은 파일이 「최근」에 나온다(감사 file.download allow 를 읽는 창구)', F1 in rec and F2 in rec, rec[:5])

print('\n── C-T1 · C-T2 — claude_employee02 · 섞인 선택(사내 1 · 남의 팀 · admin-only · 검사 중 · 격리 · 지운 것 · 없는 id)')
ghost = str(uuid.uuid4())
mix = [F1, TEAM1, ADM, PEND, INF, DEL, ghost]
a0 = audit_max()
c, d, raw, hd = zipreq('e2', mix)
a1 = audit_max()
ok('200 · X-Zip-Count 1 · X-Zip-Skipped 6', c == 200 and hd.get('X-Zip-Count') == '1' and hd.get('X-Zip-Skipped') == '6', (c, hd.get('X-Zip-Count'), hd.get('X-Zip-Skipped'), raw[:200]))
ents = zlist(raw) if c == 200 else []
ok('zip 에는 볼 수 있는 clean 파일 하나만', [e[0] for e in ents] == ['qf3c-a-%s.txt' % tag], [e[0] for e in ents])
whole = (json.dumps(hd, ensure_ascii=False) + '\n').encode() + raw
ok('응답 전체(머리+본문)에 뺀 파일 이름·id 0', all(x.encode() not in whole for x in ('secret', TEAM1, ADM, PEND, INF, DEL, ghost)))
rows = arows(a0, a1, "actor_name = 'claude_employee02'")
dd = [(r[0], r[1], r[2], r[4], (r[8] or {}).get('via')) for r in rows if r[0] == 'file.download']
ok('감사 — 남의 팀 team_mismatch · admin-only role_not_permitted · 검사 중 scan_pending · 격리 scan_infected (via=zip) + 사내 1 allow · 지운 것·없는 id 행 0',
   sorted(dd) == sorted([
       ('file.download', 'allow', '', fid(F1), 'zip'), ('file.download', 'deny', 'team_mismatch', fid(TEAM1), 'zip'),
       ('file.download', 'deny', 'role_not_permitted', fid(ADM), 'zip'), ('file.download', 'deny', 'scan_pending', fid(PEND), 'zip'),
       ('file.download', 'deny', 'scan_infected', fid(INF), 'zip')]), dd)
zr = [(r[8] or {}) for r in rows if r[0] == 'file.zip']
ok('묶음 file.zip allow — requested 7 · added 1 · skipped 6', len(zr) == 1 and (zr[0].get('requested'), zr[0].get('added'), zr[0].get('skipped')) == (7, 1, 6), zr)
ok('응답 뒤 zip-* 0', no_left(TMPD), leftovers(TMPD))

print('\n── 모두 못 받으면 403 — 뺀 개수만(이름 0) · file.zip deny nothing_allowed')
a0 = audit_max()
c, d, raw, hd = zipreq('e2', [TEAM1, ADM, ghost])
a1 = audit_max()
ok('403 · skipped 3 · 이유 문장', c == 403 and (d or {}).get('skipped') == 3 and 'zip 에 넣을 수 있는 파일이 없습니다' in (d or {}).get('error', ''), (c, d))
ok('  본문에 이름·id 0 · 키는 error·skipped 둘뿐', b'secret' not in raw and TEAM1.encode() not in raw and ADM.encode() not in raw and sorted((d or {}).keys()) == ['error', 'skipped'], raw)
rows = arows(a0, a1, "actor_name = 'claude_employee02'")
ok('  감사 — 거부 둘(via=zip) + file.zip deny nothing_allowed(requested 3 · skipped 3)',
   sorted((r[0], r[1], r[2]) for r in rows) == sorted([('file.download', 'deny', 'team_mismatch'), ('file.download', 'deny', 'role_not_permitted'), ('file.zip', 'deny', 'nothing_allowed')])
   and [(r[8] or {}).get('skipped') for r in rows if r[0] == 'file.zip'] == [3], [(r[0], r[1], r[2]) for r in rows])

print('\n── Guest — 공개만 · 사내는 빠짐')
a0 = audit_max()
c, d, raw, hd = zipreq('g1', [PUB, F1])
a1 = audit_max()
ents = zlist(raw) if c == 200 else []
ok('200 · 공개 파일 하나 · skipped 1', c == 200 and [e[0] for e in ents] == ['qf3c-pub-%s.txt' % tag] and hd.get('X-Zip-Skipped') == '1' and ents[0][3] == PUBh, (c, [e[0] for e in ents], hd.get('X-Zip-Skipped')))
rows = arows(a0, a1, "actor_name = 'claude_guest01'")
ok('  감사 — 사내 파일 role_not_permitted(via=zip)', any(r[0] == 'file.download' and r[2] == 'role_not_permitted' and r[4] == fid(F1) for r in rows), [(r[0], r[1], r[2]) for r in rows])

print('\n── 실행 파일이 든 압축본(F-65) — 관리자도 zip 에서 빠짐(요청마다 2단계 코드)')
a0 = audit_max()
c, d, raw, hd = zipreq('a1', [EXE, ADM])
a1 = audit_max()
ents = zlist(raw) if c == 200 else []
ok('200 · admin-only 보통 파일만 · skipped 1', c == 200 and [e[0] for e in ents] == ['qf3c-secret-admin-%s.txt' % tag] and hd.get('X-Zip-Skipped') == '1' and ents[0][3] == ADMh,
   (c, [e[0] for e in ents], hd.get('X-Zip-Skipped')))
rows = arows(a0, a1, "actor_name = 'claude_admin01'")
ok('  감사 — file.download deny exec_ack_required(via=zip)', any(r[0] == 'file.download' and r[2] == 'exec_ack_required' and r[4] == fid(EXE) and (r[8] or {}).get('via') == 'zip' for r in rows),
   [(r[0], r[1], r[2]) for r in rows])

print('\n── 「Guest 로 보기」 중 관리자 — 판정은 보이는 역할(decideRead 가 그대로)')
c, _, _, _ = req('a1', 'POST', '/tbox/api/me/reauth', {'password': PW_A1})
ok('admin01 재인증 200', c == 200, c)
c, d, _, _ = req('a1', 'POST', '/tbox/api/me/act', {'role': 'Guest'})
ok('「Guest 로 보기」 시작 200', c == 200, (c, d))
a0 = audit_max()
c, d, raw, hd = zipreq('a1', [PUB, F1, ADM])
a1 = audit_max()
ents = zlist(raw) if c == 200 else []
ok('200 · 공개 하나만 · skipped 2', c == 200 and [e[0] for e in ents] == ['qf3c-pub-%s.txt' % tag] and hd.get('X-Zip-Skipped') == '2', (c, [e[0] for e in ents], hd.get('X-Zip-Skipped')))
rows = arows(a0, a1, "actor_name = 'claude_admin01' AND action = 'file.download'")
ok('  감사 actor_name = 실제 관리자 · 거부 둘 role_not_permitted', sorted(r[2] for r in rows if r[1] == 'deny') == ['role_not_permitted', 'role_not_permitted'], [(r[1], r[2]) for r in rows])
c, _, _, _ = req('a1', 'DELETE', '/tbox/api/me/act')
ok('「보기」 끝내기 200', c == 200, c)

print('\n── C-T5 무결성 — 바이트를 바꾼 파일 · 바이트가 없는 파일은 그 파일만 빠짐(file.download error · via=zip)')
p_hm = spath(HM); p_ms = spath(MS)
orig_hm = open(p_hm, 'rb').read()
open(p_hm, 'wb').write(orig_hm + b'tampered')
os.rename(p_ms, p_ms + '.away')
a0 = audit_max()
c, d, raw, hd = zipreq('e1', [HM, F1, MS])
a1 = audit_max()
ents = zlist(raw) if c == 200 else []
ok('200 · 멀쩡한 하나만 · skipped 2', c == 200 and [e[0] for e in ents] == ['qf3c-a-%s.txt' % tag] and hd.get('X-Zip-Skipped') == '2', (c, [e[0] for e in ents], hd.get('X-Zip-Skipped')))
rows = arows(a0, a1, "actor_name = 'claude_employee01' AND action = 'file.download'")
ok('  감사 — hash_mismatch · file_missing (error · via=zip) + allow 1 · 바꾼 파일에 allow 0',
   sorted((r[1], r[2], r[4]) for r in rows) == sorted([('error', 'hash_mismatch', fid(HM)), ('error', 'file_missing', fid(MS)), ('allow', '', fid(F1))])
   and all((r[8] or {}).get('via') == 'zip' for r in rows), [(r[1], r[2], r[4]) for r in rows])
open(p_hm, 'wb').write(orig_hm)
os.rename(p_ms + '.away', p_ms)

print('\n── C-T3 한도 — 101개 400 · 100개 받음 · 합계 500MB')
c, d, raw, _ = zipreq('e1', [str(uuid.uuid4()) for _ in range(101)])
ok('101개 → 400 · 이유 문장(100개까지)', c == 400 and '100개까지' in (d or {}).get('error', ''), (c, d))
c, d, raw, _ = zipreq('e1', [str(uuid.uuid4()) for _ in range(100)])
ok('100개(없는 id) → 한도는 통과 · 403 skipped 100', c == 403 and (d or {}).get('skipped') == 100, (c, d))
# 크기 — DB 행만(바이트 없음 — 합계 검사가 저장소보다 먼저라 닿지 않는다). 한 행 상한 100MB(files_size_bytes_check)
def fake(n, size):
    out = []
    for i in range(n):
        sn = str(uuid.uuid4()) + '.txt'
        out.append(sql("INSERT INTO files (original_name, stored_name, ext, mime, size_bytes, sha256, description, access_level, uploaded_by, scan_status) "
                       "VALUES ('qf3c-big-%s-%d-%d.txt', '%s', 'txt', 'text/plain', %d, repeat('0', 64), '', 'internal', %s, 'clean') RETURNING public_id" % (tag, n, i, sn, size, uid['employee01'])).split('\n')[0])
    return out
big5 = fake(5, 104857600)
one = fake(1, 1)
a0 = audit_max()
c, d, raw, _ = zipreq('e1', big5 + one)
a1 = audit_max()
ok('합계 524,288,001 B(500MB + 1) → 400 · 이유 문장(500MB)', c == 400 and '500MB' in (d or {}).get('error', ''), (c, d))
rows = arows(a0, a1, "actor_name = 'claude_employee01'")
zr = [(r[8] or {}) for r in rows if r[0] == 'file.zip' and r[2] == 'too_large']
ok('  file.zip deny too_large(size_bytes 524288001) · 파일마다 allow 0', len(zr) == 1 and zr[0].get('size_bytes') == 524288001 and not any(r[0] == 'file.download' and r[1] == 'allow' for r in rows), [(r[0], r[1], r[2]) for r in rows])
a0 = audit_max()
c, d, raw, _ = zipreq('e1', big5)
a1 = audit_max()
rows = arows(a0, a1, "actor_name = 'claude_employee01'")
ok('딱 500MB(524,288,000 B) → 400 아님(한도 안 — 바이트 없는 가짜 행이라 무결성에서 빠져 403)', c == 403 and (d or {}).get('skipped') == 5, (c, d))
ok('  file_missing error 5 (via=zip)', sum(1 for r in rows if r[0] == 'file.download' and r[2] == 'file_missing') == 5, [(r[0], r[1], r[2]) for r in rows])
ok('  응답 뒤 zip-* 0', no_left(TMPD), leftovers(TMPD))

print('\n── C-T7 관문 — CSRF · 미로그인 · 토큰 머리 · 메서드 · 본문 형식')
a0 = audit_max()
c, d, _, _ = zipreq('e1', [F1], csrf=False)
a1 = audit_max()
ok('CSRF 없음 → 403 · csrf deny 한 행 · 파일 행 0', c == 403 and [(r[0], r[1]) for r in arows(a0, a1)] == [('csrf', 'deny')], (c, [(r[0], r[1]) for r in arows(a0, a1)]))
c, d, _, _ = zipreq(None, [F1])
ok('미로그인 → 401', c == 401, (c, d))
c, d, _, _ = zipreq(None, [F1], extra={'X-API-Token': 'qf3c-not-a-real-token-' + tag})
ok('X-API-Token 만(세션 없음) → 401 — 토큰 창구가 아니다(헤더를 읽지 않고 세션 경로)', c == 401, (c, d))
c, _, _, hd = req('e1', 'GET', '/tbox/api/files/zip')
ok('GET → 405', c == 405, c)
bad = [({}, None), ({'ids': 'x'}, None), ({'ids': []}, None), ({'ids': ['not-a-uuid']}, None), ({'ids': [F1], 'x': 1}, None), ({'ids': {'a': F1}}, None)]
codes = [req('e1', 'POST', '/tbox/api/files/zip', b)[0] for b, _ in bad]
ok('본문 형식 오류 여섯(ids 없음 · 문자열 · 빈 목록 · UUID 아님 · 모르는 키 · 목록 아님) → 400', codes == [400] * 6, codes)
c = req('e1', 'POST', '/tbox/api/files/zip', raw=json.dumps({'ids': [F1]}).encode(), ctype='text/plain')[0]
ok('JSON 이 아닌 Content-Type → 400', c == 400, c)
ok('관문에서 끝난 요청 뒤 zip-* 0', no_left(TMPD), leftovers(TMPD))

print('\n── C-T8 · C-T9 — 큰 파일 둘(40MB ×2, 임시 저장소에 직접 · DB 행) · 워커 넷 인스턴스')
def make_big(owner, name, size):
    sn = str(uuid.uuid4()) + '.txt'
    d = os.path.join(INST, 'storage', sn[0:2], sn[2:4]); os.makedirs(d, exist_ok=True)
    blk = hashlib.sha256(name.encode()).digest() * 32768     # 1MB 반복 무늬(파일마다 다름)
    h = hashlib.sha256()
    with open(os.path.join(d, sn), 'wb') as f:
        for _ in range(size // len(blk)):
            f.write(blk); h.update(blk)
    return sql("INSERT INTO files (original_name, stored_name, ext, mime, size_bytes, sha256, description, access_level, uploaded_by, scan_status) "
               "VALUES ('%s', '%s', 'txt', 'text/plain', %d, '%s', '', 'internal', %s, 'clean') RETURNING public_id" % (name, sn, size, h.hexdigest(), owner)).split('\n')[0], h.hexdigest()
B1, B1h = make_big(uid['employee01'], 'qf3c-big1-%s.txt' % tag, 40 * 1048576)
B2, B2h = make_big(uid['employee01'], 'qf3c-big2-%s.txt' % tag, 40 * 1048576)
ok('큰 파일 둘 준비', len(B1) == 36 and len(B2) == 36, (B1, B2))
BH = BSITE.replace('http://', '').split(':')
def hold(who, ids):
    conn = http.client.HTTPConnection(BH[0], int(BH[1]), timeout=120)
    conn.request('POST', '/tbox/api/files/zip', body=json.dumps({'ids': ids}), headers={
        'User-Agent': UA, 'Cookie': 'tbox_session=' + S[who], 'X-CSRF-Token': CSRF[who], 'Content-Type': 'application/json'})
    t0 = time.time()
    r = conn.getresponse()   # 머리까지만 — 본문은 읽지 않는다(서버는 소켓이 차서 readfile 에서 멈추고 잠금을 쥔 채)
    return conn, r, time.time() - t0
conn1, r1, t_build = hold('e1', [B1, B2])
ok('e1 큰 zip 머리 200(본문은 아직 안 읽음 — 잠금을 쥔 채)', r1.status == 200, r1.status)
note_t = 'zip 만들기(80MB · 무결성 sha256 두 번 + 담기) 머리까지 %.2f초' % t_build
print('  · ' + note_t)
lo = leftovers(TMPD)
modes = [oct(os.stat(os.path.join(TMPD, n)).st_mode & 0o777) for n in lo]
ok('만드는 동안 임시 파일 하나 · 0600 · 폴더 0700', len(lo) == 1 and modes == ['0o600'] and oct(os.stat(TMPD).st_mode & 0o777) == '0o700', (lo, modes, oct(os.stat(TMPD).st_mode & 0o777)))
a0 = audit_max()
c, d, _, hd = zipreq('e1', [F1], site=BSITE)
a1 = audit_max()
ok('같은 사람 둘째 zip → 429 · Retry-After 10 · 이유 문장', c == 429 and hd.get('Retry-After') == '10' and '만드는 중' in (d or {}).get('error', ''), (c, d, hd.get('Retry-After')))
zr = [(r[8] or {}) for r in arows(a0, a1) if r[0] == 'file.zip']
ok('  file.zip deny busy kind=user · 파일 행 0', [(z.get('kind')) for z in zr] == ['user'] and not any(r[0] == 'file.download' for r in arows(a0, a1)), arows(a0, a1))
conn2, r2, _ = hold('e2', [B1, B2])
ok('e2 큰 zip 머리 200(둘째 슬롯)', r2.status == 200, r2.status)
a0 = audit_max()
c, d, _, hd = zipreq('m1', [F1], site=BSITE)
a1 = audit_max()
zr = [(r[8] or {}) for r in arows(a0, a1) if r[0] == 'file.zip']
ok('슬롯 둘이 차면 셋째 사람 → 429 kind=site', c == 429 and [z.get('kind') for z in zr] == ['site'], (c, d, zr))
ok('  이 순간 임시 파일 둘(zip 둘)', len(leftovers(TMPD)) == 2, leftovers(TMPD))
b1 = r1.read(); conn1.close()
ents = zlist(b1)
ok('e1 zip 을 다 받음 — 두 항목 sha256 = 원본', [(e[0], e[3]) for e in ents] == [('qf3c-big1-%s.txt' % tag, B1h), ('qf3c-big2-%s.txt' % tag, B2h)], [e[0] for e in ents])
time.sleep(0.5)
c, d, raw, hd = zipreq('m1', [F1], site=BSITE)
ok('슬롯이 풀리면 셋째 사람 200', c == 200 and hd.get('X-Zip-Count') == '1', (c, d))
b2 = r2.read(); conn2.close()
ok('e2 zip 도 다 받음', len(zlist(b2)) == 2)
time.sleep(0.5)
ok('모두 끝난 뒤 zip-* 0 · 잠금 파일만(점 파일)', no_left(TMPD) and all(n.startswith('.zip-') for n in os.listdir(TMPD) if not n.startswith('fetch-')), os.listdir(TMPD))
print('── 손님이 도중에 끊음')
conn3 = http.client.HTTPConnection(BH[0], int(BH[1]), timeout=120)
conn3.request('POST', '/tbox/api/files/zip', body=json.dumps({'ids': [B1, B2]}), headers={
    'User-Agent': UA, 'Cookie': 'tbox_session=' + S['e1'], 'X-CSRF-Token': CSRF['e1'], 'Content-Type': 'application/json'})
r3 = conn3.getresponse()
first = r3.read(1048576)
# 끊기 — getresponse 뒤에는 소켓이 응답 쪽(r3.fp)으로 넘어가 conn3.sock 은 None 이다(1·3회차 시험 결함: conn3.close() 만으로는 안 끊겼다).
# 그 소켓을 직접 닫아(RST 에 가깝게) 서버의 다음 쓰기가 실패하게 한다.
raw_sock = getattr(getattr(r3.fp, 'raw', None), '_sock', None)
if raw_sock is not None:
    try: raw_sock.shutdown(2)
    except OSError: pass
r3.close()
conn3.close()
gone = False
for _ in range(40):
    time.sleep(0.25)
    if no_left(TMPD):
        gone = True; break
ok('1MB 받고 끊음 → 임시 파일이 지워진다(shutdown 함수)', r3.status == 200 and len(first) == 1048576 and gone, (r3.status, len(first), leftovers(TMPD)))
c, d, raw, hd = zipreq('e1', [F1], site=BSITE)
ok('끊긴 뒤 같은 사람 다음 zip 200(잠금이 풀렸다)', c == 200, (c, d))

print('\n── C-T8 공간 모자람 — 앱 임시 폴더가 작은 tmpfs 인 인스턴스(%s)' % SSITE)
if SMALL == '-':
    ok('작은 tmpfs(/run/user/<나>) 를 쓸 수 있다', False, '없음')
else:
    a0 = audit_max()
    c, d, raw, hd = zipreq('e1', [F1], site=SSITE)
    a1 = audit_max()
    ok('503 · 이유 문장(임시 공간)', c == 503 and '임시 공간' in (d or {}).get('error', ''), (c, d))
    rows = arows(a0, a1, "actor_name = 'claude_employee01'")
    zr = [(r[1], r[2], (r[8] or {})) for r in rows if r[0] == 'file.zip']
    ok('  file.zip error no_space(size_bytes · free_bytes) · 파일마다 allow 0', len(zr) == 1 and zr[0][:2] == ('error', 'no_space') and 'free_bytes' in zr[0][2]
       and not any(r[0] == 'file.download' and r[1] == 'allow' for r in rows), rows)
    ok('  작은 폴더에 zip-* 0', no_left(SMALL), leftovers(SMALL))

print('\n── 검토 L-C1 — 다른 슬롯이 쥐고 적어 둔 몫을 여유에서 뺀다')
c, d, raw, hd = zipreq('e1', [F1])
f1size = int(sql("SELECT size_bytes FROM files WHERE public_id = '%s'" % F1))
slot0 = os.path.join(TMPD, '.zip-slot-0.lock')
ok('혼자 zip 200 · 내 슬롯(0) 파일에 2 × 합계(%d)를 적었다' % (2 * f1size), c == 200 and open(slot0).read().strip() == str(2 * f1size), (c, open(slot0).read()[:40]))
if SHM == '-':
    ok('/dev/shm 인스턴스를 쓸 수 있다', False, '없음')
else:
    st = os.statvfs(SHM); shm_free = st.f_bavail * st.f_frsize
    WORST = 2 * 524288000
    print('  · /dev/shm 여유 %d B — 필요 = 2 × %d + 다른 슬롯 몫 + 1GB. 몫 0 이면 넉넉, 최악(%d) 이면 모자람' % (shm_free, f1size, WORST))
    ok('  전제 — 몫 0 이면 통과 · 최악 몫이면 모자람(이 폴더에서 결과가 갈린다)', 2 * f1size + 1073741824 <= shm_free < 2 * f1size + WORST + 1073741824, shm_free)
    mslot0 = os.path.join(SHM, '.zip-slot-0.lock')
    open(mslot0, 'w').close()
    def mzip():
        a0 = audit_max(); c, d, _, _ = zipreq('e1', [F1], site=MSITE); a1 = audit_max()
        return c, d, [(r[1], r[2], (r[8] or {})) for r in arows(a0, a1) if r[0] == 'file.zip']
    c, d, zr = mzip()
    ok('슬롯 몫 없음 → 공간 확인 통과(이 인스턴스는 저장소가 비어 무결성에서 빠져 403 — no_space 아님)', c == 403 and [z[:2] for z in zr] == [('deny', 'nothing_allowed')], (c, d, zr))
    hold0 = open(mslot0, 'r+')
    fcntl.flock(hold0.fileno(), fcntl.LOCK_EX)          # 다른 zip 이 슬롯 0 을 쥐고 최악 몫을 적어 둔 것처럼
    hold0.seek(0); hold0.truncate(); hold0.write(str(WORST)); hold0.flush()
    c, d, zr = mzip()
    ok('쥔 슬롯 0 의 몫 %d → 둘째 슬롯을 잡고도 503 no_space · detail reserved_bytes = 그 몫' % WORST,
       c == 503 and len(zr) == 1 and zr[0][:2] == ('error', 'no_space') and zr[0][2].get('reserved_bytes') == WORST, (c, d, zr))
    ok('  내 슬롯(1) 파일에는 2 × 합계를 먼저 적었다', open(os.path.join(SHM, '.zip-slot-1.lock')).read().strip() == str(2 * f1size))
    hold0.seek(0); hold0.truncate(); hold0.write('망가진 값'); hold0.flush()
    c, d, zr = mzip()
    ok('쥔 슬롯의 값이 숫자가 아니면 최악으로 센다 → 503 · reserved_bytes = 최악', c == 503 and len(zr) == 1 and zr[0][2].get('reserved_bytes') == WORST, (c, zr))
    hold0.seek(0); hold0.truncate(); hold0.write(str(WORST)); hold0.flush()
    fcntl.flock(hold0.fileno(), fcntl.LOCK_UN); hold0.close()
    c, d, zr = mzip()
    ok('같은 최악 값이 적혀 있어도 아무도 쥐지 않은 슬롯이면 무시 → no_space 아님(403)', c == 403 and [z[:2] for z in zr] == [('deny', 'nothing_allowed')], (c, d, zr))
    ok('  /dev/shm 폴더에 zip-* 0', no_left(SHM), leftovers(SHM))

print('\n── 검토 L-C2 — [8] 감사 직전 시간 한도(TBOX_ZIP_TIME_BUDGET 을 0 으로 낮춘 사본 인스턴스 %s)' % TSITE)
a0 = audit_max()
c, d, raw, hd = zipreq('e1', [F1, F2], site=TSITE)
a1 = audit_max()
rows = arows(a0, a1, "actor_name = 'claude_employee01'")
zr = [(r[1], r[2], (r[8] or {})) for r in rows if r[0] == 'file.zip']
ok('503 · 이유 문장(시간)', c == 503 and '시간이 너무 걸려' in (d or {}).get('error', ''), (c, d))
ok('  file.zip error zip_failed(why=time · elapsed_ms) · file.download allow 0(받지 못했는데 「받음」 없음)',
   len(zr) == 1 and zr[0][:2] == ('error', 'zip_failed') and zr[0][2].get('why') == 'time' and isinstance(zr[0][2].get('elapsed_ms'), int)
   and not any(r[0] == 'file.download' and r[1] == 'allow' for r in rows), [(r[0], r[1], r[2]) for r in rows])
ok('  zip-* 0(임시 파일 지움)', no_left(TMPD), leftovers(TMPD))

print('\n── 오래된 임시 파일 치우기(워커가 죽어 남은 것 흉내 — 11분 전 zip-* · 방금 zip-* · 다른 이름)')
old = os.path.join(TMPD, 'zip-oldone'); new = os.path.join(TMPD, 'zip-newone'); other = os.path.join(TMPD, 'fetch-keep')
for pth in (old, new, other): open(pth, 'wb').write(b'x')
os.utime(old, (time.time() - 660, time.time() - 660)); os.utime(other, (time.time() - 660, time.time() - 660))
c, d, raw, hd = zipreq('e1', [F1])
ok('다음 zip 200 · 11분 전 zip-* 는 지워짐 · 방금 zip-* 와 다른 이름(fetch-*)은 그대로', c == 200 and not os.path.exists(old) and os.path.exists(new) and os.path.exists(other), (c, os.listdir(TMPD)))
for pth in (new, other):
    try: os.remove(pth)
    except FileNotFoundError: pass

print('\n── C-T10 한 건 받기 — 기준 판(옛 코드) · 이 판을 같은 세션·같은 DB·같은 저장소로 · 열두 갈래')
def ab(label, who, pub, want):
    path = '/tbox/api/files/%s/download' % pub
    m0 = audit_max(); co, _, ro, ho = req(who, 'GET', path, site=OSITE)
    m1 = audit_max(); cn, _, rn, hn = req(who, 'GET', path)
    m2 = audit_max()
    ao = [r for r in arows(m0, m1)]; an = [r for r in arows(m1, m2)]
    hk = ('Content-Type', 'Content-Disposition', 'Content-Length', 'Retry-After', 'Cache-Control')
    same_h = [k for k in hk if ho.get(k) != hn.get(k)]
    ok('%s — %s · 옛 판 = 이 판(상태 · 본문 %dB · 머리 · 감사 %d행 글자까지)' % (label, want, len(rn), len(an)),
       co == cn == want and ro == rn and same_h == [] and ao == an and (want == 404 or len(an) >= 1), (co, cn, same_h, ao, an, ro[:120], rn[:120]))
ab('허용(내 사내 파일)', 'e1', F1, 200)
ab('역할 — 직원 → admin-only', 'e2', ADM, 403)
ab('팀 — 다른 팀 파일', 'e2', TEAM1, 403)
ab('Guest → 사내', 'g1', F1, 403)
ab('실행 파일 압축본(관리자 세션 — exec_ack)', 'a1', EXE, 403)
ab('검사 중', 'e1', PEND, 423)
ab('격리', 'e1', INF, 403)
ab('없는 id', 'e1', str(uuid.uuid4()), 404)
ab('UUID 형태 아님(v1 모양)', 'e1', '11111111-1111-1111-1111-111111111111', 404)
ab('지운 파일', 'e1', DEL, 404)
open(p_hm, 'wb').write(orig_hm + b'tampered')
ab('해시 다름(R-13 · AL-6)', 'e1', HM, 500)
open(p_hm, 'wb').write(orig_hm)
os.rename(p_ms, p_ms + '.away')
ab('바이트 없음(file_missing)', 'e1', MS, 500)
os.rename(p_ms + '.away', p_ms)

if os.environ.get('QF3C_BIG') == '1':
    print('\n── (선택 QF3C_BIG=1) 500MB 실제 바이트 — 100MB × 5 · 시간(머리까지 · 끝까지) · 본 인스턴스 최대 RSS · 뒤 zip-* 0')
    pid = os.environ.get('QF3C_MAIN_PID', '')
    def hwm():
        try:
            for line in open('/proc/%s/status' % pid):
                if line.startswith('VmHWM:'): return int(line.split()[1])
        except Exception: return -1
        return -1
    big = [make_big(uid['employee01'], 'qf3c-500-%s-%d.txt' % (tag, i), 100 * 1048576) for i in range(5)]
    ok('100MB × 5 준비(합계 524,288,000 B = 한도)', all(len(x[0]) == 36 for x in big))
    h0 = hwm()
    MH = SITE.replace('http://', '').split(':')
    conn = http.client.HTTPConnection(MH[0], int(MH[1]), timeout=300)
    t0 = time.time()
    conn.request('POST', '/tbox/api/files/zip', body=json.dumps({'ids': [x[0] for x in big]}), headers={
        'User-Agent': UA, 'Cookie': 'tbox_session=' + S['e1'], 'X-CSRF-Token': CSRF['e1'], 'Content-Type': 'application/json'})
    r = conn.getresponse(); t_head = time.time() - t0
    # 받은 500MB 는 루트 디스크가 아니라 /dev/shm(메모리 tmpfs)에 — 루트 여유를 1GB 위로 지킨다(R-80). 끝나면 지운다
    zdir = '/dev/shm' if os.path.isdir('/dev/shm') and os.access('/dev/shm', os.W_OK) else WORK
    zpath = os.path.join(zdir, 'qf3c-big-%s.zip' % tag); n = 0
    with open(zpath, 'wb') as out:
        while True:
            chunk = r.read(4 * 1048576)
            if not chunk: break
            out.write(chunk); n += len(chunk)
    t_all = time.time() - t0
    conn.close()
    h1 = hwm()
    z = zipfile.ZipFile(zpath)
    shas = []
    for i in z.infolist():
        hh = hashlib.sha256()
        with z.open(i) as f:
            while True:
                b = f.read(4 * 1048576)
                if not b: break
                hh.update(b)
        shas.append(hh.hexdigest())
    print('  · 500MB zip — 머리까지 %.2f초(무결성 sha256 500MB + 담기) · 끝까지 %.2f초 · zip %d B · 본 인스턴스 최대 RSS %d kB → %d kB' % (t_head, t_all, n, h0, h1))
    ok('500MB 200 · 다섯 항목 sha256 = 원본 · 뒤 zip-* 0', r.status == 200 and shas == [x[1] for x in big] and no_left(TMPD), (r.status, leftovers(TMPD)))
    ok('머리까지 60초 안(nginx fastcgi_read_timeout 60s) · 끝까지 90초 안(FPM request_terminate_timeout — php -S 는 nginx 가 받아 두는 몫이 없어 끝까지가 곧 PHP 시간)', t_head < 60 and t_all < 90, (t_head, t_all))
    os.remove(zpath)
    for x in big:
        try: os.remove(spath(x[0]))
        except FileNotFoundError: pass

print('PY_PASS=%d PY_FAIL=%d' % (P, F))
PY

hr "C-T1 ~ C-T10 (파이썬 몸통)"
py_secret S_E1 S_E2 S_M1 S_A1 S_G1 PW_A1 -- env QF3C_MAIN_PID="${PIDS[0]}" python3 "$WORK/drv.py" "$SITE" "$UA" "$DB" "http://127.0.0.1:$BPORT" "http://127.0.0.1:$SPORT" "http://127.0.0.1:$OPORT" "$INST" "${SMALL:--}" "$WORK" "http://127.0.0.1:$TPORT" "http://127.0.0.1:$MPORT" "${SHM:--}" > "$WORK/drv.out" 2>&1
cat "$WORK/drv.out"
pp=$(sed -n 's/^PY_PASS=\([0-9]*\) PY_FAIL=\([0-9]*\)$/\1/p' "$WORK/drv.out"); pf=$(sed -n 's/^PY_PASS=\([0-9]*\) PY_FAIL=\([0-9]*\)$/\2/p' "$WORK/drv.out")
if [[ "$pp" =~ ^[0-9]+$ ]] && [[ "$pf" =~ ^[0-9]+$ ]]; then PASS=$((PASS + pp)); FAIL=$((FAIL + pf)); else echo "  ✖ 파이썬 몸통이 끝줄을 못 찍었다"; FAIL=$((FAIL+1)); fi
[ "${pp:-0}" -ge 60 ] 2>/dev/null && expect "몸통이 헛돌지 않았다(통과 60 이상)" "yes" "yes" || expect "몸통이 헛돌지 않았다(통과 60 이상)" "yes" "no(${pp:-?})"

if [ "${QF3C_BROWSER:-0}" = "1" ]; then
  hr "화면 — tests/browser/qf3c_zip.py (일괄 막대 → zip 받기 · 360·412·1280 × 라이트·다크)"
  mkdir -p "$SHOTS"
  py_secret S_E1 -- env PATH="$HOME/.local/bin:$PATH" python3 "$ROOT/tests/browser/qf3c_zip.py" "$SITE" "$UA" "$SHOTS" "$DB" "$INST" > "$WORK/browser.out" 2>&1
  rc=$?
  cat "$WORK/browser.out"
  expect "화면 시험 종료 코드" "0" "$rc"
  bf=$(sed -n 's/^SMOKE_FAIL=\([0-9]*\)$/\1/p' "$WORK/browser.out")
  expect "화면 시험 실패 수" "0" "${bf:-?}"
  bp=$(grep -c '^  ✔' "$WORK/browser.out")
  [ "$bp" -ge 20 ] && expect "화면 시험이 헛돌지 않았다(✔ 20 이상)" "yes" "yes" || expect "화면 시험이 헛돌지 않았다(✔ 20 이상)" "yes" "no($bp)"
  note "스크린샷: $SHOTS (사람이 직접 연다)"
fi

hr "C-T11 정적 — 새 라우트 정확히 한 줄 · 토큰 창구 불변 · php -l · 린트 · 서버 로그"
D=$(git -C "$ROOT" diff "$BASE_REV" -- public/index.php)
expect "public/index.php — 더한 라우트 줄(\`['메서드', '/…'\` 모양)" "1" "$(printf '%s\n' "$D" | grep -cE "^\+ *\['(GET|POST|PUT|PATCH|DELETE)', *'/")"
expect "  그 한 줄 = POST /api/files/zip → files_zip.php · file.read" "1" "$(printf '%s\n' "$D" | grep -cE "^\+ *\['POST', *'/api/files/zip', *'files_zip\.php', *'file\.read'\],$")"
expect "  뺀 줄 0" "0" "$(printf '%s\n' "$D" | grep -cE '^-[^-]')"
expect "  주석이 아닌 더한 줄 = 1(라우트 한 줄뿐)" "1" "$(printf '%s\n' "$D" | grep -E '^\+[^+]' | grep -vcE '^\+ *//')"
tokconst() { sed -n '/^const TBOX_API_TOKEN_\(ROUTES\|PERSONAL_ROUTES\|ONLY_ROUTES\) = \[/,/^\];/p'; }
git -C "$ROOT" show "$BASE_REV":public/index.php | tokconst > "$WORK/tok_base.txt"
tokconst < "$ROOT/public/index.php" > "$WORK/tok_now.txt"
expect "토큰 창구 목록 셋 = 기준 판 글자 그대로(zip 은 토큰 창구가 아니다)" "same" "$([ -s "$WORK/tok_base.txt" ] && cmp -s "$WORK/tok_base.txt" "$WORK/tok_now.txt" && echo same || echo diff)"
expect "토큰 창구 목록에 zip 이 없다" "0" "$(grep -c 'files/zip' "$WORK/tok_now.txt")"
errs_after=$(server_errs)
expect "서버 로그(인스턴스 넷)의 새 PHP 오류·SQLSTATE 줄" "0" "$((errs_after - errs_before))"
[ "$((errs_after - errs_before))" = "0" ] || cat "$INST"/server*.log "$INST_S/server.log" "$INST_O/server.log" | grep -iE 'PHP (Fatal|Warning|Notice|Deprecated)|SQLSTATE|Uncaught' | grep -v '저장된 파일을 찾을 수 없습니다' | tail -5 | sed 's/^/    /'
expect "  「바이트 없음」 갈래의 예외 줄 — 옛 판 1 · 이 판 1(같은 갈래 · 같은 문구)" "1 1" \
  "$(grep -c '저장된 파일을 찾을 수 없습니다' "$INST_O/server.log") $(cat "$INST"/server*.log | grep -c '저장된 파일을 찾을 수 없습니다')"
nbad=0
for f in public/index.php src/Audit.php src/handlers/_download_common.php src/handlers/files_download.php src/handlers/files_zip.php src/handlers/home.php src/help/screens.php; do
  php -l "$ROOT/$f" >/dev/null 2>&1 || { echo "  ✖ php -l $f"; nbad=$((nbad+1)); }
done
expect "바뀐 PHP php -l 실패" "0" "$nbad"
( cd "$ROOT" && bash scripts/security_lint.sh > "$WORK/lint.txt" 2>&1 ); rc=$?
expect "보안 린트 종료 코드" "0" "$rc"
[ "$rc" -eq 0 ] || tail -8 "$WORK/lint.txt" | sed 's/^/    /'
expect "app.js — zip 창구 주소는 APP_BASE 로(Q:T-4)" "0" "$(grep -n "'/api/files/zip'" "$ROOT/public/assets/app.js" | grep -vc 'APP_BASE')"

# ════════════════════════════════════════════════════════════════
if [ "${REGRESS:-0}" = "1" ]; then
  hr "회귀 — 같은 임시 인스턴스에서(qf3a.sh · qf2_search.sh 의 회귀 틀 그대로)"
  note "각 스크립트를 임시 인스턴스 폴더로 복사해 SITE·DB 만 바꿔 돈다(운영 8082·tbox_re 에 닿지 않는다)."
  sed -i "s#http://127.0.0.1:8082#$SITE#g" "$INST/app/scripts/ui_snapshot.sh" 2>/dev/null
  for s in ${REGRESS_LIST:-stage26 help_button f68 api_read api_whoami api_folders f65}; do
    src="$INST/app/tests/$s.sh"
    dst="$INST/app/tests/$s.temp.sh"
    [ -f "$src" ] || { echo "  ✖ $s — 스크립트가 없다"; FAIL=$((FAIL+1)); continue; }
    sed -e "s#http://127.0.0.1:8082#$SITE#g" -e "s#^DB=\"tbox_re\"#DB=\"$DB\"#" -e "s#-d tbox_re #-d $DB #g" "$src" > "$dst"
    if [ "$s" = "f65" ]; then
      # (d) 는 경보 배치 alerts.php(사본 · 임시 DB · dry-run)를 부른다 — 지시(운영 배치 스크립트는 돌리지 않는다)에 따라 그 절만 SKIP 으로 돌린다
      sed -i -e '/^hr "(d) AL-21/,/^# ─── (e)/ s/^if \[ "\$RUN_HTTP" -eq 0 \] || \[ -z "\$A_ID" \]; then$/if true; then/' \
             -e '/^hr "(d) AL-21/,/^# ─── (e)/ s/skip "(d) 전체" "확인 업로드가 없었다"/skip "(d) 전체" "회귀 틀 — 경보 배치 사본도 돌리지 않는다(qf3c 지시)"/' "$dst"
      grep -q 'skip "(d) 전체" "회귀 틀' "$dst" || { echo "  ✖ f65 — (d) 를 SKIP 으로 돌리지 못했다(돌리지 않는다)"; FAIL=$((FAIL+1)); continue; }
    fi
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
PROD_A1=$(sudo -n -u postgres psql -X -d "$SRC_DB" -tAc "SELECT coalesce(max(id),0) FROM audit_logs" 2>/dev/null)
PROD_ZIP=$(sudo -n -u postgres psql -X -d "$SRC_DB" -tAc "SELECT count(*) FROM audit_logs WHERE id > ${PROD_A0:-0} AND (action = 'file.zip' OR detail->>'via' = 'zip' OR detail->>'route' = 'POST /api/files/zip')" 2>/dev/null)
note "운영 감사 max(id) 끝 = ${PROD_A1:-?} (시작 ${PROD_A0:-?}) — 그사이 운영에 zip 창구 행 ${PROD_ZIP:-?}(이 시험은 운영에 보내지 않는다)"
expect "운영 감사에 zip 창구 행 0(읽기만)" "0" "${PROD_ZIP:-?}"
printf '통과 %d · 실패 %d\n' "$PASS" "$FAIL"
note "임시 DB($DB)·임시 폴더·임시 서버는 이 스크립트가 끝나며 지워진다. 운영에는 아무것도 남지 않는다."
[ "$FAIL" -eq 0 ] || exit 1
exit 0
