#!/usr/bin/env bash
# tests/dbg_u3_ui.sh — 디버깅 묶음 U3 수락 시험 「커뮤니티를 쓰기 쉽게」 (사용자 2026-09-26 22:5x · 에이전트 시연 · 구현 기록 docs/debug-0927-u3-implementation.md)
#
#   U3-S 정적      바뀐 PHP php -l · 보안 린트 0 · 커뮤니티 JS import 버전(--check) · 더한 줄의 인라인 style·script·on* 0 ·
#                  라우터(public/index.php)·커뮤니티 라우터(routes.php) 차이는 **404 갈래 한 줄 → 없는 주소 함수 부르기**뿐 ·
#                  판정·보안 핵심(Authz · CommunityAuthz · Auth · Http · Audit) 차이 0 · 댓글 본문을 문자열로 넣는 줄 0 ·
#                  옛 시험 dbg_u_ui.sh 기준 판 기본값이 운영 HEAD 를 따라감 · 도움말 문구 ↔ 하위 페이지·데이터베이스 단추 글자(좁은·넓은 폭)
#   U3-H 서버      없는 주소 — 브라우저 화면 요청(Accept text/html · GET/HEAD · /api/ 아님)은 404 HTML 「찾을 수 없는 페이지」, 그 밖은 전과 같은 404 JSON ·
#                  로그인 전·후 같은 바이트 · 새 쿠키 0 · 감사 행 0 · 있는데 권한 없는 주소(403)·로그인 전(302)은 전과 같음 ·
#                  공개 게시·범위 넓히기 거부(file_narrower)의 새 문장(상태 코드·사유 코드·감사는 그대로)
#   U3-B 화면      tests/browser/dbg_u3_ui.py — 360 · 412(폰) · 820(터치) · 1280 × 라이트 · 다크 (항목은 그 파일 머리글)
#   U3-L 서버 로그 임시 인스턴스 로그에 새 PHP 오류 0
#   회귀(선택)     REGRESS=1 이면 같은 임시 인스턴스에서 REGRESS_LIST 를 사본으로(8082 → 이 인스턴스 · tbox_re → 임시 DB) 돌린다(dbg_u2_ui.sh 틀 그대로)
#
# ★ 운영 DB·운영 포트를 건드리지 않는다 — 임시 DB `tbox_d3u_<포트>_test`(운영 pg_dump 를 **읽어** 파이프로 붓기만) ·
#   임시 인스턴스 `php -S 127.0.0.1:<포트>`(기본 8323 — 이 묶음은 8321~8329 만 쓴다) · 검토 계정 claude_ 넷(F-71) ·
#   비밀은 표준입력으로만(R-62 · tests/lib/secrets.sh). 운영 배치 스크립트는 부르지 않는다.
#   임시 .env 원본 — 복제본에는 .env 가 없다: mktemp 폴더(0700)의 0600 사본을 QF2_ENV_SRC 로 넘기고 끝에 지운다.
#   이 인스턴스의 .env 는 DB_NAME·STORAGE_DIR·AUDIT_LOG_FILE 을 임시로, SCAN_ENABLED=0. 띄우기 전에 DB_NAME 이 tbox_re 가 아닌지 본다.
#   운영 감사 max(id)·경보 로그 줄 수·검토 계정 비밀 파일 시각은 시작·끝에 **읽기만** 해 적고, 운영 감사에 이 시험의 UA 행이 늘지 않았는지 본다.
#   psql 은 SQL 을 **표준입력**으로만(명령 줄이 DB 마다 한 모양 — 운영 경보 AL-9).
# ★ 서버 메모리(취합 규칙 2026-09-27 02:1x): 헤드리스 브라우저는 공용 잠금(QAREDI_BROWSER_LOCK — 있으면 flock) 아래에서 한 번에 하나,
#   띄우기 직전 `free -m` 의 available 이 700MB 미만이면 1분씩 기다린다(최대 15분 — 그래도 모자라면 화면 시험을 실패로 두고 끝낸다).
# 손잡이: DBGU3_PORT · DBGU3_COMBOS(조합 줄이기) · DBGU3_SHOTS(스크린샷 폴더) · DBGU3_SKIP_BROWSER=1 · DBGU3_BASE_REV ·
#         DBGU3_APP_TREE=<폴더>(그 트리를 띄운다 — 고치기 전 판 스크린샷용) + DBGU3_BEFORE=1(정적·판정은 참고, 종료 코드 0) ·
#         DBGU3_SYNC_TOTP=1(검토 계정 비밀 파일과 운영 DB 가 어긋날 때 임시 DB 만 맞춤) · QAREDI_BROWSER_LOCK · REGRESS=1 · REGRESS_LIST · REGRESS_KEEP
# 사용: sudo -v && QF2_ENV_SRC=<0600 사본> bash tests/dbg_u3_ui.sh
set -u
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
. "$ROOT/tests/lib/review_accounts.sh" || { echo "tests/lib/review_accounts.sh 를 읽지 못했다 — 시험을 시작하지 않는다"; exit 3; }
. "$ROOT/tests/lib/secrets.sh" || { echo "tests/lib/secrets.sh 를 읽지 못했다 — 시험을 시작하지 않는다 (R-62)"; exit 3; }
PORT="${DBGU3_PORT:-8323}"
case "$PORT" in 832[1-9]) ;; *) echo "이 묶음은 포트 8321~8329 만 쓴다(운영 80·8082·8081 금지) — $PORT"; exit 3 ;; esac
SITE="http://127.0.0.1:$PORT"
BASE="$SITE/tbox"
UA="tbox-review/$(date +%F)"
SRC_DB="tbox_re"                     # 운영 — **읽기만** 한다(pg_dump · max(id))
DB="tbox_d3u_${PORT}_test"           # 임시 — 여기에만 쓴다
# 정적 대조의 기준 판 — 기본은 **운영 HEAD**(읽기만 — rev-parse). 이 복제본에 그 판이 없으면 이 묶음을 얹은 판 fd96aca(v2.31.0).
BASE_REV_DEFAULT=$(git -C "${DBGU3_PROD_REPO:-/var/www/qaredi/tbox-v2}" rev-parse --short HEAD 2>/dev/null)
git -C "$ROOT" cat-file -e "${BASE_REV_DEFAULT:-none}^{commit}" 2>/dev/null || BASE_REV_DEFAULT=fd96aca
BASE_REV="${DBGU3_BASE_REV:-$BASE_REV_DEFAULT}"
APP_TREE="${DBGU3_APP_TREE:-$ROOT}"  # 띄울 트리 — 기본은 이 복제본. 고치기 전 판을 찍을 때만 다른 폴더
BEFORE="${DBGU3_BEFORE:-0}"
JAR_DIR="$(mktemp -d)"
WORK="$(mktemp -d)"
chmod 700 "$JAR_DIR" "$WORK"
SHOTS="${DBGU3_SHOTS:-$WORK/shots}"
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

psqlq() { sudo -n -u postgres psql -X -q -At -d "$DB" 2>/dev/null <<< "$1"; }
prodq() { sudo -n -u postgres psql -X -q -At -d "$SRC_DB" 2>/dev/null <<< "$1"; }   # 운영 — SELECT 만 · 시작·끝 한 번씩
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
# 헤드리스 브라우저 하나를 띄우기 전 — 메모리 여유(available ≥ 700MB)를 1분씩 최대 15분 기다린다(취합 규칙 2026-09-27)
mem_ok() {
  local a i
  for i in $(seq 1 15); do
    a=$(free -m | awk '/^Mem:/{print $7}')
    [ "${a:-0}" -ge 700 ] && return 0
    printf '  · 메모리 여유 %sMB (700 미만) — 1분 기다림 %d/15\n' "${a:-?}" "$i" >&2
    sleep 60
  done
  return 1
}

# ════════════════════════════════════════════════════════════════
hr "디버깅 묶음 U3 — 임시 인스턴스 준비 (운영 DB 에는 쓰지 않는다)"
if ss -ltn 2>/dev/null | grep -q "127.0.0.1:$PORT "; then echo "포트 $PORT 이 이미 쓰이고 있다 — 중단"; exit 3; fi
ENV_SRC="${QF2_ENV_SRC:-$ROOT/.env}"
[ -f "$ENV_SRC" ] || { echo "임시 .env 원본이 없다 — QF2_ENV_SRC 로 0600 사본을 넘긴다"; exit 3; }
[ -d "$APP_TREE/public" ] && [ -d "$APP_TREE/src" ] || { echo "띄울 트리($APP_TREE)에 public·src 가 없다"; exit 3; }
PROD_A0=$(prodq "SELECT coalesce(max(id),0) FROM audit_logs;")   # 운영 읽기 — 시작 한 번
[[ "$PROD_A0" =~ ^[0-9]+$ ]] || { echo "psql(postgres) 로 운영 감사 max(id) 를 읽지 못했다 — 이 시험은 임시 DB 가 없으면 성립하지 않는다"; exit 3; }
ALERTS_L0=$(sudo -n wc -l < /var/log/tbox-v2/alerts.log 2>/dev/null || echo '?')
TOTP_M0=$(sudo -n stat -c %y /etc/tbox-v2/qa-totp.txt 2>/dev/null || echo '?')
note "운영 감사 max(id) 시작 = ${PROD_A0:-?} · 운영 경보 로그 줄 수 시작 = $ALERTS_L0 · 검토 계정 비밀 파일 시각 = $TOTP_M0 (셋 다 읽기만)"
note "정적 대조 기준 판 = $BASE_REV"
[ "$APP_TREE" = "$ROOT" ] || note "띄우는 트리: $APP_TREE (DBGU3_APP_TREE)"
sudo -n -u postgres dropdb --if-exists "$DB" >/dev/null 2>&1
sudo -n -u postgres createdb -O tbox_owner "$DB" >/dev/null 2>&1 || { echo "임시 DB 를 만들 수 없다"; exit 3; }
sudo -n -u postgres pg_dump -d "$SRC_DB" 2>/dev/null \
  | sudo -n -u postgres psql -X -q -d "$DB" -v ON_ERROR_STOP=0 >/dev/null 2>"$WORK/restore.err"
DUMP_PS=("${PIPESTATUS[@]}")
[ "${DUMP_PS[0]}" -eq 0 ] || { echo "운영 DB 를 읽지 못했다 (pg_dump)"; exit 3; }
CNT=$(psqlq "SELECT (SELECT count(*) FROM files) || ' ' || (SELECT count(*) FROM community.pages);")
[ -n "$CNT" ] || { echo "임시 DB 로 옮기지 못했다 — $(head -2 "$WORK/restore.err")"; exit 3; }
note "임시 DB $DB — 파일 ${CNT%% *} · 페이지 ${CNT##* }"
if [ "${DBGU3_SYNC_TOTP:-0}" = "1" ]; then
  for who in claude_employee01 claude_employee02 claude_admin01 claude_guest01; do
    SX=$(review_secret "$who")
    [[ "$SX" =~ ^[A-Z2-7]{16,64}$ ]] || { note "$who — 비밀 모양이 아니어서 맞추지 않음"; continue; }
    printf "UPDATE users SET mfa_secret = '%s', mfa_last_step = NULL WHERE username = '%s' AND mfa_enabled;\n" "$SX" "$who" \
      | sudo -n -u postgres psql -X -q -d "$DB" -f - >/dev/null 2>&1
    SX=''
    note "$who — 임시 DB 의 2단계 비밀을 검토 계정 파일 값에 맞춤(DBGU3_SYNC_TOTP)"
  done
fi

INST="$(mktemp -d)"; chmod 700 "$INST"
mkdir -p "$INST/app" "$INST/storage" "$INST/tmp" "$INST/log"
chmod 700 "$INST/tmp"
tar -C "$APP_TREE" -cf - --exclude=.git --exclude=jar --exclude=api-test.txt --exclude=./.env --exclude=./.claude --exclude=__pycache__ . | tar -C "$INST/app" -xf -
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

hr "로그인 (claude_ 검토 계정 넷, 순차, F-71) · 관리자 재인증"
for who in claude_employee01 claude_employee02 claude_admin01 claude_guest01; do
  login "$who" || { echo "  ✖ $who 로그인 실패 — 중단"; exit 4; }
done
S_E1=$(session_of claude_employee01); S_E2=$(session_of claude_employee02); S_A1=$(session_of claude_admin01); S_G1=$(session_of claude_guest01)
PW_A1=$(review_password claude_admin01)
A_CSRF=$(csrf_of claude_admin01)
REAUTH=$(py_secret PW_A1 -- python3 -c 'import json,sys; print(json.dumps({"password": sys.stdin.readline().rstrip("\n")}))')
RC=$(curl_json_secret REAUTH -s -o /dev/null -w '%{http_code}' -X POST -b "$JAR_DIR/claude_admin01.jar" -A "$UA" \
     -H "X-CSRF-Token: $A_CSRF" -H 'Content-Type: application/json' "$BASE/api/me/reauth")
REAUTH=''; PW_A1=''
expect "관리자 재인증 200" "200" "$RC"

hr "U3-S 정적 (이 복제본 — 기준 판 $BASE_REV 대비)"
if [ "$BEFORE" = "1" ]; then
  note "DBGU3_BEFORE=1 — 고치기 전 판을 띄웠다. 정적 시험은 이 복제본(고친 판)을 보므로 건너뛴다"
else
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
  git -C "$ROOT" diff "$BASE_REV" -- src public > "$WORK/diff.txt" 2>/dev/null
  INL=$(grep '^+' "$WORK/diff.txt" | grep -v '^+++' | grep -cE 'style="|<script>|<script [^s]| on[a-z]+="')
  expect "더한 줄에 인라인 style·script·on* 속성 0" "0" "$INL"
  expect "더한 JS 줄에 문자열 → HTML 자리(innerHTML·outerHTML·insertAdjacentHTML·document.write) 0" "0" \
    "$(git -C "$ROOT" diff "$BASE_REV" -- 'public/assets/*.js' 'public/assets/community/*.js' 2>/dev/null | grep '^+' | grep -v '^+++' | grep -cE 'innerHTML|outerHTML|insertAdjacentHTML|document\.write')"
  expect "판정·보안 핵심 파일 차이 0 — Authz · CommunityAuthz · Auth · Http · Audit · Db" "0" \
    "$(git -C "$ROOT" diff "$BASE_REV" -- src/Authz.php src/community/CommunityAuthz.php src/Auth.php src/Http.php src/Audit.php src/Db.php 2>/dev/null | wc -l)"
  # 라우터 — 404 갈래만: 지운 줄은 `Http::error(404, Http::MSG_NOT_FOUND);` 뿐, 더한 줄은 주석 · require_once · qaredi_not_found(…) 뿐
  router_ok() {   # $1 파일  $2 지운 줄 수
    local d rm_all rm_ok add_all add_ok
    d=$(git -C "$ROOT" diff -U0 "$BASE_REV" -- "$1" 2>/dev/null | grep -E '^[-+]' | grep -vE '^(\+\+\+|---) ')
    rm_all=$(printf '%s\n' "$d" | grep -c '^-')
    rm_ok=$(printf '%s\n' "$d" | grep -cE '^-\s*Http::error\(404, Http::MSG_NOT_FOUND\);$')
    add_all=$(printf '%s\n' "$d" | grep -c '^+')
    # 주석 줄은 앞머리(`//`)만 본다 — GNU grep 3.7 은 UTF-8 에서 `//.*$` 가 긴 한글 주석 줄을 놓쳤다(2026-09-27 첫 회차 전 발견). 세 모양은 서로 겹치지 않는다
    add_ok=$(( $(printf '%s\n' "$d" | grep -cE '^\+\s*//') \
             + $(printf '%s\n' "$d" | grep -cE "^\+\s*require_once __DIR__ \. '/\.\./(src/)?site/not_found_page\.php';$") \
             + $(printf '%s\n' "$d" | grep -cE "^\+\s*qaredi_not_found\(strpos\(\\\$(reqPath|modulePath), '/api/'\) === 0\);$") ))
    printf '%s %s %s %s' "$rm_all" "$rm_ok" "$add_all" "$add_ok"
  }
  R=$(router_ok public/index.php); set -- $R
  expect "public/index.php 차이 = 404 갈래 두 곳만(지운 줄 둘 모두 404 · 더한 줄 모두 주석·require·함수 부르기)" "2 2 yes" "$1 $2 $([ "$3" = "$4" ] && [ "$3" -gt 0 ] && echo yes || echo "no($3/$4)")"
  R=$(router_ok src/community/routes.php); set -- $R
  expect "src/community/routes.php 차이 = 404 갈래 한 곳만" "1 1 yes" "$1 $2 $([ "$3" = "$4" ] && [ "$3" -gt 0 ] && echo yes || echo "no($3/$4)")"
  expect "댓글 본문을 한 덩어리 글자로 넣던 줄 0 · 글자 노드와 링크를 만드는 함수로" "0 1" \
    "$(grep -c 'else { body.textContent = c.body; }' "$ROOT/public/assets/community/editor.js") $(grep -c 'else { linkifyInto(body, c.body); }' "$ROOT/public/assets/community/editor.js")"
  expect "댓글 링크 — http·https 스킴 확인 · rel noopener noreferrer (editor.js)" "1 1" \
    "$(grep -c "url.protocol !== 'https:' && url.protocol !== 'http:'" "$ROOT/public/assets/community/editor.js") $(grep -c "a.setAttribute('rel', 'noopener noreferrer');" "$ROOT/public/assets/community/editor.js")"
  expect "옛 시험 dbg_u_ui.sh — 기준 판 기본값이 운영 HEAD 를 따라간다(고정 e88f690 0)" "1 0" \
    "$(grep -c 'rev-parse --short HEAD' "$ROOT/tests/dbg_u_ui.sh") $(grep -c 'DBGU_BASE_REV:-e88f690' "$ROOT/tests/dbg_u_ui.sh")"
  # 11 — 도움말 ↔ 단추 글자(좁은 폭은 narrow-hide 가 가려 「＋ 페이지」 · 넓은 폭은 「하위 페이지 만들기」)
  expect "단추 마크업 — 하위 페이지·데이터베이스(좁은 폭 가림 조각 그대로)" "1 1" \
    "$(grep -c '<span class="narrow-hide">하위 </span>페이지<span class="narrow-hide"> 만들기</span>' "$ROOT/src/community/handlers/page_view.php") $(grep -c '<span class="narrow-hide">하위 </span>데이터베이스<span class="narrow-hide"> 만들기</span>' "$ROOT/src/community/handlers/page_view.php")"
  HC="$ROOT/src/help/community.php"
  expect "도움말이 두 폭의 단추 글자를 다 말한다(「＋ 페이지」·「하위 페이지 만들기」·「＋ 데이터베이스」·「하위 데이터베이스 만들기」)" "yes" \
    "$(grep -q '「＋ 페이지」' "$HC" && grep -q '「하위 페이지 만들기」' "$HC" && grep -q '「＋ 데이터베이스」' "$HC" && grep -q '「하위 데이터베이스 만들기」' "$HC" && echo yes || echo no)"
  expect "도움말에 옛 문장(휴대폰에서 「하위 페이지 만들기」를 누름 · 「더보기」 → 「휴지통」) 0" "0" \
    "$(grep -cE '휴대폰에서는 페이지를 열어 아래쪽 「하위 페이지 만들기」|「더보기」 → 「휴지통」에' "$HC")"
  expect "터치 — 표 행 동작 단추 위아래 여백 6px(app.css 터치 규칙 안)" "1" \
    "$(awk '/^@media \(max-width: 719\.98px\), \(pointer: coarse\) \{/{f=1} f&&/table\.files td\.actions \.btn \{ margin-top: 6px; margin-bottom: 6px; \}/{n++} f&&/^\}/{f=0} END{print n+0}' "$ROOT/public/assets/app.css")"
fi

hr "U3-H 서버 — 없는 주소 (브라우저 화면 요청 = HTML · 그 밖 = 전과 같은 JSON)"
nf() {   # $1 잼(빈 글자면 로그인 전) $2 Accept $3 경로 $4 메서드 — 「코드 형식 쿠키수」 한 줄, 본문은 $WORK/nf.body
  local args=(-s -D "$WORK/nf.head" -A "$UA" -w '%{http_code}')
  [ -n "$1" ] && args+=(-b "$JAR_DIR/$1.jar")
  : > "$WORK/nf.body"
  # HEAD 는 머리만 받는다(curl -I — 본문을 읽지 않는다) · 그 밖은 본문을 파일로
  if [ "${4:-GET}" = "HEAD" ]; then args+=(-I -o /dev/null); else args+=(-o "$WORK/nf.body" -X "${4:-GET}"); fi
  local code; code=$(curl "${args[@]}" -H "Accept: $2" "$SITE$3")
  local ct; ct=$(grep -i '^content-type:' "$WORK/nf.head" | tail -1 | tr -d '\r' | sed 's/^[^:]*: *//; s/;.*//')
  printf '%s %s %s' "$code" "${ct:-none}" "$(grep -ci '^set-cookie:' "$WORK/nf.head")"
}
HTML_ACC='text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'
AUD0=$(psqlq "SELECT coalesce(max(id),0) FROM audit_logs;")
for path in /tbox/my-trash /tbox/nope/deeper /nope-u3 /community/nope-u3; do
  R=$(nf "" "$HTML_ACC" "$path"); cp "$WORK/nf.body" "$WORK/nf.anon"
  expect "$path 로그인 전 · 브라우저 → 404 HTML · 새 쿠키 0" "404 text/html 0" "$R"
  expect "$path   「찾을 수 없는 페이지」 · 첫 화면 링크 · JSON 글자 0" "1 1 0" \
    "$(grep -c '<h1 class="page-head__title">찾을 수 없는 페이지</h1>' "$WORK/nf.anon") $(grep -c '<a class="btn" href="/">첫 화면으로</a>' "$WORK/nf.anon") $(grep -c '"error"' "$WORK/nf.anon")"
  R=$(nf claude_employee01 "$HTML_ACC" "$path")
  expect "$path 로그인 뒤 · 브라우저 → 404 HTML · 새 쿠키 0" "404 text/html 0" "$R"
  expect "$path   로그인 전·후 본문이 바이트까지 같다(세션을 읽지 않는다)" "$(sha256sum < "$WORK/nf.anon" | cut -c1-16)" "$(sha256sum < "$WORK/nf.body" | cut -c1-16)"
  expect "$path   본문에 계정 이름·요청 주소 0" "0 0" "$(grep -c 'claude_' "$WORK/nf.body") $(grep -cF -- "${path#/}" "$WORK/nf.body")"
  R=$(nf claude_employee01 "application/json" "$path")
  expect "$path Accept application/json → 전과 같은 404 JSON" "404 application/json {\"error\":\"찾을 수 없습니다\"}" "${R% *} $(cat "$WORK/nf.body")"
  R=$(nf "" "*/*" "$path")
  expect "$path Accept */*(fetch 기본값) → 404 JSON" "404 application/json" "${R% *}"
done
R=$(nf "" "$HTML_ACC" /tbox/api/nope-u3);        expect "/tbox/api/… 는 브라우저 Accept 여도 404 JSON" "404 application/json" "${R% *}"
R=$(nf "" "$HTML_ACC" /community/api/nope-u3);   expect "/community/api/… 는 브라우저 Accept 여도 404 JSON" "404 application/json" "${R% *}"
R=$(nf claude_employee01 "$HTML_ACC" /tbox/my-trash POST); expect "POST 없는 주소는 브라우저 Accept 여도 404 JSON" "404 application/json" "${R% *}"
R=$(nf "" "$HTML_ACC" /tbox/my-trash HEAD);      expect "HEAD 없는 주소 → 404 HTML 머리" "404 text/html" "${R% *}"
R=$(nf claude_employee01 "$HTML_ACC" /tbox/users); note "있는데 권한 없는 주소(직원 /tbox/users) = $R — 라우터 [4] 인가의 403 JSON 그대로(이 묶음이 바꾸지 않은 갈래)"
expect "있는데 권한 없는 주소는 전과 같은 403" "403" "${R%% *}"
R=$(nf "" "$HTML_ACC" /tbox/users);                expect "로그인 전 있는 화면 주소는 전과 같은 302(로그인으로)" "302" "${R%% *}"
AUD1=$(psqlq "SELECT coalesce(max(id),0) FROM audit_logs;")
expect "없는 주소 요청은 감사 행을 남기지 않는다(그 사이 행은 권한 없는 주소의 authz.route deny 한 건뿐)" "0 1" \
  "$(psqlq "SELECT (SELECT count(*) FROM audit_logs WHERE id > ${AUD0:-0} AND action <> 'authz.route') || ' ' || (SELECT count(*) FROM audit_logs WHERE id > ${AUD0:-0} AND action = 'authz.route');")"
note "임시 DB 감사 max(id) $AUD0 → $AUD1"

hr "U3-B 화면 — tests/browser/dbg_u3_ui.py (360 · 412 · 820 터치 · 1280 × 라이트 · 다크)"
if [ "${DBGU3_SKIP_BROWSER:-0}" = "1" ]; then
  note "DBGU3_SKIP_BROWSER=1 — 화면 시험을 건너뛴다(통과로 세지 않는다)"
else
  mkdir -p "$SHOTS"
  BARG=""; [ "$BEFORE" = "1" ] && BARG="before"
  LOCKCMD=()
  [ -n "${QAREDI_BROWSER_LOCK:-}" ] && LOCKCMD=(flock "$QAREDI_BROWSER_LOCK")
  {
    if mem_ok; then
      # 잠금 안에서 한 번 더 메모리를 보고 띄운다(기다리는 동안 다른 작업자가 띄웠을 수 있다)
      py_secret S_E1 S_E2 S_A1 S_G1 -- "${LOCKCMD[@]}" env PATH="$HOME/.local/bin:$PATH" DBGU3_COMBOS="${DBGU3_COMBOS:-}" \
        bash -c 'a=$(free -m | awk "/^Mem:/{print \$7}"); echo "  · 브라우저 띄우기 직전 메모리 여유 ${a}MB"; [ "${a:-0}" -ge 700 ] || { echo "메모리 여유 700MB 미만 — 띄우지 않는다"; exit 97; }; exec python3 -u "$@"' _ \
        "$ROOT/tests/browser/dbg_u3_ui.py" "$SITE" "$UA" "$SHOTS" $BARG 2>&1
      echo "RC=$?"
    else
      echo "메모리 여유가 15분 동안 700MB 미만 — 화면 시험을 띄우지 않았다"; echo "RC=98"
    fi; } | tee "$WORK/browser.out"
  rc=$(sed -n 's/^RC=\([0-9]*\)$/\1/p' "$WORK/browser.out" | tail -1)
  expect "화면 시험 종료 코드" "0" "$rc"
  bf=$(sed -n 's/^SMOKE_FAIL=\([0-9]*\)$/\1/p' "$WORK/browser.out")
  [ "$BEFORE" = "1" ] && note "고치기 전 판의 화면 판정 실패 수 = ${bf:-?} (참고 — 시험이 결함을 잡는지)" || expect "화면 시험 실패 수" "0" "${bf:-?}"
  bp=$(grep -c '^  ✔' "$WORK/browser.out")
  NCOMBO=$(printf '%s' "${DBGU3_COMBOS:-360:light,360:dark,412:light,412:dark,820:light,820:dark,1280:light,1280:dark}" | tr ',' '\n' | grep -c .)
  BMIN=$((20 + 25 * NCOMBO))
  if [ "$BEFORE" != "1" ]; then
    [ "$bp" -ge "$BMIN" ] && expect "화면 시험이 헛돌지 않았다(✔ $BMIN 이상 — 조합 $NCOMBO)" "yes" "yes" || expect "화면 시험이 헛돌지 않았다(✔ $BMIN 이상 — 조합 $NCOMBO)" "yes" "no($bp)"
  fi
  S_E1=''; S_E2=''; S_A1=''; S_G1=''
  note "스크린샷: $SHOTS (사람이 직접 연다)"
fi

hr "U3-L 서버 로그"
expect "임시 인스턴스 로그의 PHP 오류(Fatal·Warning·Notice·Deprecated)" "0" \
  "$(grep -cE 'PHP (Fatal|Warning|Notice|Deprecated|Parse)' "$INST/server.log")"
expect "임시 인스턴스 로그의 잡히지 않은 예외(uncaught) 0" "0" "$(grep -c 'uncaught ' "$INST/server.log")"

if [ "${REGRESS:-0}" = "1" ]; then
  hr "회귀 — 같은 임시 인스턴스에서(dbg_u2_ui.sh 의 회귀 틀 그대로)"
  note "각 스크립트를 임시 인스턴스 폴더로 복사해 SITE·DB 만 바꿔 돈다(운영 8082·tbox_re 에 닿지 않는다)."
  # help_button.sh 가 부르는 scripts/ui_snapshot.sh 도 주소를 품고 있다 — **임시 복사본만** 고치고, 남았으면 회귀를 돌리지 않는다
  sed -i "s#http://127.0.0.1:8082#$SITE#g" "$INST/app/scripts/ui_snapshot.sh" 2>/dev/null
  if grep -v '^\s*#' "$INST/app/scripts/ui_snapshot.sh" | grep -q '8082'; then
    echo "  ✖ ui_snapshot.sh 에 운영 주소가 남았다 — 회귀를 돌리지 않는다"; FAIL=$((FAIL+1)); REGRESS_LIST=" "
  fi
  for s in ${REGRESS_LIST:-help_button q_launcher}; do
    src="$INST/app/tests/$s.sh"
    dst="$INST/app/tests/$s.temp.sh"
    [ -f "$src" ] || { echo "  ✖ $s — 스크립트가 없다"; FAIL=$((FAIL+1)); continue; }
    sed -e "s#http://127.0.0.1:8082#$SITE#g" -e "s#^DB=\"tbox_re\"#DB=\"$DB\"#" -e "s#-d tbox_re #-d $DB #g" "$src" > "$dst"
    if grep -v '^\s*#' "$dst" | grep -q '8082\|DB="tbox_re"\|-d tbox_re'; then
      echo "  ✖ $s — 운영 주소·DB 가 남아 있어 돌리지 않는다"; FAIL=$((FAIL+1)); continue
    fi
    sleep $((31 - $(date +%s) % 30))
    echo "  ▸ $s (시작 $(date +%T))"
    if [ -n "${QAREDI_BROWSER_LOCK:-}" ]; then flock "$QAREDI_BROWSER_LOCK" bash "$dst" > "$WORK/regress_$(basename "$s").txt" 2>&1
    else bash "$dst" > "$WORK/regress_$(basename "$s").txt" 2>&1; fi
    rc=$?
    line=$(grep -E '통과 [0-9]+|PASS|통과:|결과' "$WORK/regress_$(basename "$s").txt" | tail -1)
    echo "    종료 코드 $rc · ${line:-결과 줄 없음}"
    grep -E '^\s*(✖|FAIL)' "$WORK/regress_$(basename "$s").txt" | head -12 | sed 's/^/      /'
    [ -n "${REGRESS_KEEP:-}" ] && cp "$WORK/regress_$(basename "$s").txt" "$REGRESS_KEEP/"
    [ "$rc" = "0" ] && PASS=$((PASS+1)) || FAIL=$((FAIL+1))
  done
fi

hr "운영 대조 (읽기만)"
# 이 시험이 운영에 닿았다면 그 요청은 127.0.0.1(로컬 8082)에서 온다 — 같은 UA 를 쓰는 다른 세션의 운영 확인(도메인 경유 · 바깥 IP)은 세지 않고 줄만 보인다
END=$(prodq "SELECT coalesce(max(id),0) || '|' || (SELECT count(*) FROM audit_logs WHERE id > ${PROD_A0:-0} AND user_agent = '$UA' AND actor_name LIKE 'claude\\_%' AND host(ip) = '127.0.0.1') FROM audit_logs;")
OTHER=$(prodq "SELECT string_agg(id || ' ' || coalesce(actor_name,'-') || ' ' || action || ' ' || coalesce(host(ip),'-'), ' ; ') FROM audit_logs WHERE id > ${PROD_A0:-0} AND user_agent = '$UA';")
[ -n "$OTHER" ] && note "같은 UA 의 운영 감사 행(참고 — IP 로 가름): $OTHER"
PROD_A1=${END%%|*}; UA_ROWS=${END##*|}
ALERTS_L1=$(sudo -n wc -l < /var/log/tbox-v2/alerts.log 2>/dev/null || echo '?')
TOTP_M1=$(sudo -n stat -c %y /etc/tbox-v2/qa-totp.txt 2>/dev/null || echo '?')
note "운영 감사 max(id) 끝 = ${PROD_A1:-?} (시작 ${PROD_A0:-?}) · 경보 로그 줄 수 끝 = $ALERTS_L1 (시작 $ALERTS_L0) · 비밀 파일 시각 끝 = $TOTP_M1"
expect "검토 계정 비밀 파일 시각 그대로(이 시험은 /etc 에 쓰지 않는다)" "$TOTP_M0" "$TOTP_M1"
expect "운영 감사에 이 시험의 UA($UA) · 로컬(127.0.0.1) 행이 늘지 않았다(시작 max 뒤)" "0" "${UA_ROWS:-?}"

printf '\n통과 %d · 실패 %d\n' "$PASS" "$FAIL"
[ "$BEFORE" = "1" ] && exit 0
[ "$FAIL" -eq 0 ]
