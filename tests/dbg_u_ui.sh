#!/usr/bin/env bash
# tests/dbg_u_ui.sh — 디버깅 묶음 U 수락 시험 (디자인 점검 2026-09-26 12:13 의 높음 다섯 + 중간 일부 · 구현 기록 docs/debug-0926-u-implementation.md)
#
#   U-S 정적       브라우저 기본 창 호출(window.prompt( · window.confirm() 0 · 바뀐 PHP php -l · 보안 린트 0 ·
#                  커뮤니티 JS import 버전 표시(--check) · /api/me 새 값이 서버 판정 함수 그대로(`Authz::canCommunityWrite`) ·
#                  더한 줄에 인라인 style·script·on* 속성 0 (기준 판 대비)
#   U-A 창구       /api/me can_community_write — 직원·관리자 참 · 손님 거짓 · 손님의 쓰기 요청은 서버가 그대로 403(라우트 정책 무변경)
#   U-B 화면       tests/browser/dbg_u_ui.py — 360 · 412(터치) · 820 · 1280 × 라이트 · 다크: 가로 넘침 0 · 주요 동작 화면 안 · 콘솔 0 ·
#                  기본 창 0 · 손님 쓰기 단추 0 · 직원은 그대로 · 새 창 저장·닫기·Esc·초점 복귀 (항목은 그 파일 머리글)
#   U-L 서버 로그  임시 인스턴스 로그에 새 PHP 오류(Fatal·Warning·Notice·Deprecated) 0
#   회귀(선택)     REGRESS=1 이면 같은 임시 인스턴스에서 REGRESS_LIST(기본 stage26 help_button q_launcher f66 f68)를 사본으로
#                  (8082 → 이 인스턴스 · tbox_re → 임시 DB 로 바꿔) 돌린다 — qf3c_zip.sh 의 회귀 틀 그대로
#
# ★ 운영 DB·운영 포트를 건드리지 않는다 — 임시 DB `tbox_d1u_<포트>_test`(운영 pg_dump 를 **읽어** 파이프로 붓기만) ·
#   임시 인스턴스 `php -S 127.0.0.1:<포트>`(기본 8223 — 이 묶음은 8221~8229 만 쓴다) · 검토 계정 claude_ 셋(F-71) ·
#   비밀은 표준입력으로만(R-62 · tests/lib/secrets.sh). 운영 배치 스크립트는 부르지 않는다.
#   임시 .env 원본 — 복제본에는 .env 가 없다: mktemp 폴더(0700)의 0600 사본을 QF2_ENV_SRC 로 넘기고 끝에 지운다.
#   이 인스턴스의 .env 는 DB_NAME·STORAGE_DIR·AUDIT_LOG_FILE 을 임시로, SCAN_ENABLED=0. 띄우기 전에 DB_NAME 이 tbox_re 가 아닌지 본다.
#   운영 감사 max(id)·경보 로그 줄 수는 시작·끝에 **읽기만** 해 적고, 운영 감사에 이 시험의 UA 행이 늘지 않았는지 본다.
# 사용: sudo -v && QF2_ENV_SRC=<0600 사본> bash tests/dbg_u_ui.sh      (DBGU_SHOTS=<폴더> 면 스크린샷을 남긴다 · 기본 임시)
set -u
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
. "$ROOT/tests/lib/review_accounts.sh" || { echo "tests/lib/review_accounts.sh 를 읽지 못했다 — 시험을 시작하지 않는다"; exit 3; }
. "$ROOT/tests/lib/secrets.sh" || { echo "tests/lib/secrets.sh 를 읽지 못했다 — 시험을 시작하지 않는다 (R-62)"; exit 3; }
PORT="${DBGU_PORT:-8223}"
case "$PORT" in 80|8082|8081|"") echo "운영 포트($PORT)·빈 포트는 쓰지 않는다"; exit 3 ;; esac
case "$PORT" in *[!0-9]*) echo "포트는 숫자만"; exit 3 ;; esac
SITE="http://127.0.0.1:$PORT"
BASE="$SITE/tbox"
UA="tbox-review/$(date +%F)"
SRC_DB="tbox_re"                     # 운영 — **읽기만** 한다(pg_dump · max(id))
DB="tbox_d1u_${PORT}_test"           # 임시 — 여기에만 쓴다
# 정적 대조의 기준 판 — 기본은 **운영 HEAD**(디버깅 묶음 U3 · 2026-09-27): 옛 고정값 e88f690(이 묶음을 얹은 v2.29.0 a57cf6b + S · T)은 운영이 앞서가면
#   「라우트 파일 차이 0」 한 줄이 다른 묶음의 라우트 변경을 세어 늘 실패했다. 운영 저장소는 **읽기만**(rev-parse)이고, 그 판이 이 복제본에 없으면
#   (운영이 더 앞섰는데 fetch 전) 고정 판 fd96aca(v2.31.0)로 — 그것도 없으면 git diff 가 실패해 대조 줄이 드러난다. DBGU_BASE_REV 로 바꿀 수 있다.
BASE_REV_DEFAULT=$(git -C "${DBGU_PROD_REPO:-/var/www/qaredi/tbox-v2}" rev-parse --short HEAD 2>/dev/null)
git -C "$ROOT" cat-file -e "${BASE_REV_DEFAULT:-none}^{commit}" 2>/dev/null || BASE_REV_DEFAULT=fd96aca
BASE_REV="${DBGU_BASE_REV:-$BASE_REV_DEFAULT}"
JAR_DIR="$(mktemp -d)"
WORK="$(mktemp -d)"
chmod 700 "$JAR_DIR" "$WORK"
SHOTS="${DBGU_SHOTS:-$WORK/shots}"
INST=""
SPID=""
PASS=0
FAIL=0

hr()  { printf '\n════════════════════════════════════════════════════════════\n%s\n════════════════════════════════════════════════════════════\n' "$1"; }
expect() {
  if [ "$2" = "$3" ]; then printf '  ✔ %s  (기대=%s 실제=%s)\n' "$1" "$2" "$3"; PASS=$((PASS+1))
  else printf '  ✖ %s  (기대=%s 실제=%s)\n' "$1" "$2" "$3"; FAIL=$((FAIL+1)); fi
}
note() { printf '  · %s\n' "$1"; }

cleanup() {
  [ -n "$SPID" ] && { kill "$SPID" 2>/dev/null; wait "$SPID" 2>/dev/null; }
  pkill -f "^php -S 127\.0\.0\.1:$PORT " 2>/dev/null
  sudo -n -u postgres dropdb --if-exists "$DB" >/dev/null 2>&1
  [ -n "$INST" ] && rm -rf "$INST"
  rm -rf "$JAR_DIR" "$WORK"
}
trap cleanup EXIT

psqlq() { sudo -n -u postgres psql -X -d "$DB" -t -A -c "$1" 2>/dev/null; }
prodq() { sudo -n -u postgres psql -X -d "$SRC_DB" -t -A -c "$1" 2>/dev/null; }   # 운영 — SELECT 만
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
csrf_of() { curl -s -b "$JAR_DIR/$1.jar" -A "$UA" "$BASE/api/me" | python3 -c 'import json,sys; print(json.load(sys.stdin).get("csrf_token",""))' 2>/dev/null; }
me_field() { curl -s -b "$JAR_DIR/$1.jar" -A "$UA" "$BASE/api/me" | python3 -c 'import json,sys; v=json.load(sys.stdin).get(sys.argv[1], "(없음)"); print(json.dumps(v))' "$2" 2>/dev/null; }

make_env() {   # $1 원본 $2 대상 $3 저장 폴더 $4 감사 파일. 값은 출력하지 않는다.
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

# ════════════════════════════════════════════════════════════════
hr "디버깅 묶음 U — 임시 인스턴스 준비 (운영 DB 에는 쓰지 않는다)"
sudo -n -u postgres psql -X -d "$SRC_DB" -tAc "SELECT 1" >/dev/null 2>&1 \
  || { echo "psql(postgres) 을 쓸 수 없다 — 이 시험은 임시 DB 가 없으면 성립하지 않는다"; exit 3; }
if ss -ltn 2>/dev/null | grep -q "127.0.0.1:$PORT "; then echo "포트 $PORT 이 이미 쓰이고 있다 — 중단"; exit 3; fi
ENV_SRC="${QF2_ENV_SRC:-$ROOT/.env}"
[ -f "$ENV_SRC" ] || { echo "임시 .env 원본이 없다 — QF2_ENV_SRC 로 0600 사본을 넘긴다"; exit 3; }
PROD_A0=$(prodq "SELECT coalesce(max(id),0) FROM audit_logs")
ALERTS_L0=$(sudo -n wc -l < /var/log/tbox-v2/alerts.log 2>/dev/null || echo '?')
note "운영 감사 max(id) 시작 = ${PROD_A0:-?} · 운영 경보 로그 줄 수 시작 = $ALERTS_L0 (둘 다 읽기만)"

sudo -n -u postgres dropdb --if-exists "$DB" >/dev/null 2>&1
sudo -n -u postgres createdb -O tbox_owner "$DB" >/dev/null 2>&1 || { echo "임시 DB 를 만들 수 없다"; exit 3; }
sudo -n -u postgres pg_dump -d "$SRC_DB" 2>/dev/null \
  | sudo -n -u postgres psql -X -q -d "$DB" -v ON_ERROR_STOP=0 >/dev/null 2>"$WORK/restore.err"
DUMP_PS=("${PIPESTATUS[@]}")
[ "${DUMP_PS[0]}" -eq 0 ] || { echo "운영 DB 를 읽지 못했다 (pg_dump)"; exit 3; }
[ -n "$(psqlq "SELECT count(*) FROM files")" ] || { echo "임시 DB 로 옮기지 못했다 — $(head -2 "$WORK/restore.err")"; exit 3; }
note "임시 DB $DB — 파일 $(psqlq "SELECT count(*) FROM files") · 페이지 $(psqlq "SELECT count(*) FROM community_pages" 2>/dev/null)"
# DBGU_SYNC_TOTP=1 — 검토 계정 비밀 파일(/etc/tbox-v2/qa-totp.txt)과 운영 DB 의 2단계 비밀이 어긋났을 때(2026-09-26 13:47~ 다른 세션이 파일을 바꿈 —
#   구현 기록 8장) **임시 DB 의** 세 계정 비밀만 파일 값에 맞춘다. 운영 DB·파일은 건드리지 않는다. 값은 표준입력(SQL)으로만 — 명령 인자 0(R-62).
if [ "${DBGU_SYNC_TOTP:-0}" = "1" ]; then
  for who in claude_employee01 claude_admin01 claude_guest01; do
    SX=$(review_secret "$who")
    [[ "$SX" =~ ^[A-Z2-7]{16,64}$ ]] || { note "$who — 비밀 모양이 아니어서 맞추지 않음"; continue; }
    printf "UPDATE users SET mfa_secret = '%s', mfa_last_step = NULL WHERE username = '%s' AND mfa_enabled;\n" "$SX" "$who" \
      | sudo -n -u postgres psql -X -q -d "$DB" -f - >/dev/null 2>&1
    SX=''
    note "$who — 임시 DB 의 2단계 비밀을 검토 계정 파일 값에 맞춤(DBGU_SYNC_TOTP)"
  done
fi

INST="$(mktemp -d)"; chmod 700 "$INST"
mkdir -p "$INST/app" "$INST/storage" "$INST/tmp" "$INST/log"
chmod 700 "$INST/tmp"
tar -C "$ROOT" -cf - --exclude=.git --exclude=jar --exclude=api-test.txt --exclude=./.env --exclude=./.claude --exclude=__pycache__ . | tar -C "$INST/app" -xf -
make_env "$ENV_SRC" "$INST/app/.env" "$INST/storage" "$INST/log/audit.log" || { echo "임시 .env 에 임시 값이 들어가지 않았다 — 중단"; exit 4; }
note "임시 .env 확인: $(grep '^DB_NAME=' "$INST/app/.env")"
ln -s /var/www/qaredi/community "$INST/community" 2>/dev/null
make_router "$INST/app"
( cd "$INST/app" && exec php -S "127.0.0.1:$PORT" -t public router.php > "$INST/server.log" 2>&1 ) &
SPID=$!
c=""
for _ in $(seq 1 30); do sleep 0.3; c=$(curl -s -o /dev/null -w '%{http_code}' -A "$UA" "$BASE/login"); [ "$c" = "200" ] && break; done
expect "인스턴스가 떴다(로그인 화면 200)" "200" "$c"
[ "$c" = "200" ] || { tail -5 "$INST/server.log"; exit 4; }

hr "로그인 (claude_ 검토 계정 셋, 순차, F-71) · 관리자 재인증"
for who in claude_employee01 claude_admin01 claude_guest01; do
  login "$who" || { echo "  ✖ $who 로그인 실패 — 중단"; exit 4; }
done
S_E1=$(session_of claude_employee01); S_A1=$(session_of claude_admin01); S_G1=$(session_of claude_guest01)
PW_E1=$(review_password claude_employee01); PW_A1=$(review_password claude_admin01)
SEC_E1=$(review_secret claude_employee01); [ -n "$SEC_E1" ] || SEC_E1='-'
A_CSRF=$(csrf_of claude_admin01)
REAUTH=$(py_secret PW_A1 -- python3 -c 'import json,sys; print(json.dumps({"password": sys.stdin.readline().rstrip("\n")}))')
RC=$(curl_json_secret REAUTH -s -o /dev/null -w '%{http_code}' -X POST -b "$JAR_DIR/claude_admin01.jar" -A "$UA" \
     -H "X-CSRF-Token: $A_CSRF" -H 'Content-Type: application/json' "$BASE/api/me/reauth")
REAUTH=''
expect "관리자 재인증 200" "200" "$RC"

hr "U-S 정적"
CALLS=$(grep -rnE 'window\.(prompt|confirm)\(' "$ROOT/public/assets" --include=*.js | grep -vE '^[^:]+:[0-9]+:\s*(\*|//|/\*)' | wc -l)
expect "브라우저 기본 창 호출 window.prompt( · window.confirm( (주석 제외)" "0" "$CALLS"
PHPBAD=0
for f in $(git -C "$ROOT" diff --name-only "$BASE_REV" -- '*.php' 2>/dev/null); do
  [ -f "$ROOT/$f" ] || continue
  php -l "$ROOT/$f" >/dev/null 2>&1 || { PHPBAD=$((PHPBAD+1)); echo "    php -l 실패: $f"; }
done
expect "바뀐 PHP php -l 실패 0" "0" "$PHPBAD"
bash "$ROOT/scripts/security_lint.sh" "$ROOT" > "$WORK/lint.txt" 2>&1
expect "보안 린트 통과(금지 패턴 0건)" "1" "$(grep -c '결과: 통과' "$WORK/lint.txt")"
php "$ROOT/scripts/community_asset_version.php" --check > "$WORK/assetver.txt" 2>&1
expect "커뮤니티 JS import 버전 표시가 지금 내용과 맞다(--check)" "0" "$?"
expect "/api/me can_community_write = Authz::canCommunityWrite(\$user) 그대로" "1" \
  "$(grep -c "'can_community_write' => Authz::canCommunityWrite(\$user)," "$ROOT/src/handlers/me.php")"
# 인라인만 센다 — `<script` 태그 안에 src= 가 있는 외부 파일(`<script defer src=…>` 등)은 빼고 `<script>`·src 없는 `<script …>`·style="·on…=" (네 시험 같은 식 · 정리 묶음 D2 2026-09-27)
INL=$(git -C "$ROOT" diff "$BASE_REV" -- src public 2>/dev/null | grep '^+' | grep -v '^+++' | grep -cE 'style="|<script([[:blank:]]+([^s>[:blank:]][^>[:blank:]]*|s[^r>[:blank:]][^>[:blank:]]*|sr[^c>[:blank:]][^>[:blank:]]*|src[^=>[:blank:]][^>[:blank:]]*|s|sr|src))*[[:blank:]]*>| on[a-z]+="')
expect "더한 줄에 인라인 style·script·on* 속성 0" "0" "$INL"
expect "security_lint·쓰기 라우트 정책 무변경 — public/index.php · src/community/routes.php 차이 0" "0" \
  "$(git -C "$ROOT" diff "$BASE_REV" -- public/index.php src/community/routes.php src/Authz.php src/community/CommunityAuthz.php 2>/dev/null | wc -l)"

hr "U-A 창구 — /api/me 새 값 · 서버 거부 그대로"
expect "직원 can_community_write" "true" "$(me_field claude_employee01 can_community_write)"
expect "관리자 can_community_write" "true" "$(me_field claude_admin01 can_community_write)"
expect "손님 can_community_write" "false" "$(me_field claude_guest01 can_community_write)"
G_CSRF=$(csrf_of claude_guest01)
GC=$(curl -s -o /dev/null -w '%{http_code}' -X POST -b "$JAR_DIR/claude_guest01.jar" -A "$UA" -H "X-CSRF-Token: $G_CSRF" \
     -H 'Content-Type: application/json' -d '{"title":"DBGU guest"}' "$SITE/community/api/pages")
expect "손님 POST /community/api/pages → 403 (라우트 정책 community.write 그대로)" "403" "$GC"
GT=$(curl -s -o /dev/null -w '%{http_code}' -X POST -b "$JAR_DIR/claude_guest01.jar" -A "$UA" -H "X-CSRF-Token: $G_CSRF" \
     -H 'Content-Type: application/json' -d '{"page_id":"00000000-0000-4000-8000-000000000000","name":"x"}' "$SITE/community/api/templates")
expect "손님 POST /community/api/templates → 403" "403" "$GT"

hr "U-B 화면 — tests/browser/dbg_u_ui.py (360 · 412 · 820 · 1280 × 라이트 · 다크)"
if [ "${DBGU_SKIP_BROWSER:-0}" = "1" ]; then
  note "DBGU_SKIP_BROWSER=1 — 화면 시험을 건너뛴다(같은 판에서 이미 통과한 뒤 회귀만 돌릴 때). 통과로 세지 않는다"
else
  mkdir -p "$SHOTS"
  # 화면 시험은 10분 남짓 — 줄마다 바로 보이게(-u · tee). 종료 코드는 마지막 「RC=」 줄로 받는다(파이프 안이라)
  { py_secret S_E1 S_A1 S_G1 PW_E1 SEC_E1 PW_A1 -- env PATH="$HOME/.local/bin:$PATH" python3 -u "$ROOT/tests/browser/dbg_u_ui.py" "$SITE" "$UA" "$SHOTS" 2>&1
    echo "RC=$?"; } | tee "$WORK/browser.out"
  rc=$(sed -n 's/^RC=\([0-9]*\)$/\1/p' "$WORK/browser.out" | tail -1)
  expect "화면 시험 종료 코드" "0" "$rc"
  bf=$(sed -n 's/^SMOKE_FAIL=\([0-9]*\)$/\1/p' "$WORK/browser.out")
  expect "화면 시험 실패 수" "0" "${bf:-?}"
  bp=$(grep -c '^  ✔' "$WORK/browser.out")
  # 헛돎 기준은 조합 수에 비례(한 조합 ✔ 40 쯤 + 준비 15) — DBGU_COMBOS 로 조합을 줄여 돌려도 맞게(기본 8 조합 → 335)
  NCOMBO=$(printf '%s' "${DBGU_COMBOS:-360:light,360:dark,412:light,412:dark,820:light,820:dark,1280:light,1280:dark}" | tr ',' '\n' | grep -c .)
  BMIN=$((15 + 40 * NCOMBO))
  [ "$bp" -ge "$BMIN" ] && expect "화면 시험이 헛돌지 않았다(✔ $BMIN 이상 — 조합 $NCOMBO)" "yes" "yes" || expect "화면 시험이 헛돌지 않았다(✔ $BMIN 이상 — 조합 $NCOMBO)" "yes" "no($bp)"
  note "스크린샷: $SHOTS (사람이 직접 연다)"
fi

hr "U-L 서버 로그"
expect "임시 인스턴스 로그의 PHP 오류(Fatal·Warning·Notice·Deprecated)" "0" \
  "$(grep -cE 'PHP (Fatal|Warning|Notice|Deprecated|Parse)' "$INST/server.log")"

if [ "${REGRESS:-0}" = "1" ]; then
  hr "회귀 — 같은 임시 인스턴스에서(qf3c_zip.sh 의 회귀 틀 그대로)"
  note "각 스크립트를 임시 인스턴스 폴더로 복사해 SITE·DB 만 바꿔 돈다(운영 8082·tbox_re 에 닿지 않는다)."
  sed -i "s#http://127.0.0.1:8082#$SITE#g" "$INST/app/scripts/ui_snapshot.sh" 2>/dev/null
  for s in ${REGRESS_LIST:-stage26 help_button q_launcher f66 f68}; do
    src="$INST/app/tests/$s.sh"
    dst="$INST/app/tests/$s.temp.sh"
    [ -f "$src" ] || { echo "  ✖ $s — 스크립트가 없다"; FAIL=$((FAIL+1)); continue; }
    sed -e "s#http://127.0.0.1:8082#$SITE#g" -e "s#^DB=\"tbox_re\"#DB=\"$DB\"#" -e "s#-d tbox_re #-d $DB #g" "$src" > "$dst"
    if grep -v '^\s*#' "$dst" | grep -q '8082\|DB="tbox_re"\|-d tbox_re'; then
      echo "  ✖ $s — 운영 주소·DB 가 남아 있어 돌리지 않는다"; FAIL=$((FAIL+1)); continue
    fi
    # 2단계 코드는 한 번만 쓰인다(R-45) — 앞 단계(이 틀의 로그인 · 앞 스크립트)가 쓴 창과 겹치지 않게 다음 코드 창까지 기다린다
    sleep $((31 - $(date +%s) % 30))
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

hr "운영 대조 (읽기만)"
PROD_A1=$(prodq "SELECT coalesce(max(id),0) FROM audit_logs")
ALERTS_L1=$(sudo -n wc -l < /var/log/tbox-v2/alerts.log 2>/dev/null || echo '?')
note "운영 감사 max(id) 끝 = ${PROD_A1:-?} (시작 ${PROD_A0:-?}) · 운영 경보 로그 줄 수 끝 = $ALERTS_L1 (시작 $ALERTS_L0)"
expect "운영 감사에 이 시험의 UA($UA) 행이 늘지 않았다(시작 max 뒤)" "0" \
  "$(prodq "SELECT count(*) FROM audit_logs WHERE id > ${PROD_A0:-0} AND user_agent = '$UA' AND actor_name LIKE 'claude\\_%'")"

printf '\n통과 %d · 실패 %d\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ]
