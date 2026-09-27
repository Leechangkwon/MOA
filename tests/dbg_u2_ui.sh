#!/usr/bin/env bash
# tests/dbg_u2_ui.sh — 디버깅 묶음 U2 수락 시험 (디자인 점검 2026-09-26 12:13 의 남은 중간 + 그날 검토·운영 캡처 · 구현 기록 docs/debug-0926-u2-implementation.md)
#
#   U2-S 정적      화면 파일의 옛 낱말 문자열(「접근 레벨」·「내 계정 설정」·aria-label 「다운로드」「삭제」「복구」 …) 0 · 등급 고르개 글자에 영어 값 0 ·
#                  창 바닥 「그만두기」 0 · 브라우저 기본 창 호출 0 · 바뀐 PHP php -l · 보안 린트 0 · 커뮤니티 JS import 버전(--check) ·
#                  더한 줄의 인라인 style·script·on* 0 · 라우트·판정 파일 diff 0 (기준 판 대비)
#   U2-H 서버 HTML 로그인한 세 계정으로 받은 화면 HTML(주석·태그를 걷은 글자)에 옛 낱말 0 — 서버가 그리는 문장
#   U2-B 화면      tests/browser/dbg_u2_ui.py — 360 · 412(터치) · 820 · 1280 × 라이트 · 다크: 가로 넘침 0 · 주요 단추 화면 안 · 콘솔 0 · 기본 창 0 ·
#                  창 단추 규칙(모든 창의 DOM 순서·주요 클래스) · 낱말 표(화면 글자에 옛 낱말 0) · 터치 대상 44px(폰 폭) ·
#                  재인증 창 Esc 뒤 다시 뜸 · 계정 「관리」 하나 + 상세 창 · 데이터베이스 쓰기 단추 손님 숨김 (항목은 그 파일 머리글)
#   U2-L 서버 로그 임시 인스턴스 로그에 새 PHP 오류 0
#   회귀(선택)     REGRESS=1 이면 같은 임시 인스턴스에서 REGRESS_LIST 를 사본으로(8082 → 이 인스턴스 · tbox_re → 임시 DB) 돌린다(dbg_u_ui.sh 틀 그대로)
#
# ★ 운영 DB·운영 포트를 건드리지 않는다 — 임시 DB `tbox_d2u_<포트>_test`(운영 pg_dump 를 **읽어** 파이프로 붓기만) ·
#   임시 인스턴스 `php -S 127.0.0.1:<포트>`(기본 8273 — 이 묶음은 8271~8279 만 쓴다) · 검토 계정 claude_ 셋(F-71) ·
#   비밀은 표준입력으로만(R-62 · tests/lib/secrets.sh). 운영 배치 스크립트는 부르지 않는다.
#   임시 .env 원본 — 복제본에는 .env 가 없다: mktemp 폴더(0700)의 0600 사본을 QF2_ENV_SRC 로 넘기고 끝에 지운다.
#   이 인스턴스의 .env 는 DB_NAME·STORAGE_DIR·AUDIT_LOG_FILE 을 임시로, SCAN_ENABLED=0. 띄우기 전에 DB_NAME 이 tbox_re 가 아닌지 본다.
#   운영 감사 max(id)·경보 로그 줄 수·검토 계정 비밀 파일 시각은 시작·끝에 **읽기만** 해 적고, 운영 감사에 이 시험의 UA 행이 늘지 않았는지 본다.
# 손잡이: DBGU2_PORT · DBGU2_COMBOS(조합 줄이기) · DBGU2_SHOTS(스크린샷 폴더) · DBGU2_SKIP_BROWSER=1 ·
#         DBGU2_APP_TREE=<폴더>(그 트리를 띄운다 — 고치기 전 판 스크린샷용) + DBGU2_BEFORE=1(정적·판정은 참고, 종료 코드 0) ·
#         DBGU2_SYNC_TOTP=1(검토 계정 비밀 파일과 운영 DB 가 어긋날 때 임시 DB 만 맞춤) · REGRESS=1 · REGRESS_LIST · REGRESS_KEEP
# 사용: sudo -v && QF2_ENV_SRC=<0600 사본> bash tests/dbg_u2_ui.sh
set -u
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
. "$ROOT/tests/lib/review_accounts.sh" || { echo "tests/lib/review_accounts.sh 를 읽지 못했다 — 시험을 시작하지 않는다"; exit 3; }
. "$ROOT/tests/lib/secrets.sh" || { echo "tests/lib/secrets.sh 를 읽지 못했다 — 시험을 시작하지 않는다 (R-62)"; exit 3; }
PORT="${DBGU2_PORT:-8273}"
case "$PORT" in 80|8082|8081|"") echo "운영 포트($PORT)·빈 포트는 쓰지 않는다"; exit 3 ;; esac
case "$PORT" in *[!0-9]*) echo "포트는 숫자만"; exit 3 ;; esac
SITE="http://127.0.0.1:$PORT"
BASE="$SITE/tbox"
UA="tbox-review/$(date +%F)"
SRC_DB="tbox_re"                     # 운영 — **읽기만** 한다(pg_dump · max(id))
DB="tbox_d2u_${PORT}_test"           # 임시 — 여기에만 쓴다
# 기준 판 기본값은 운영 저장소의 HEAD(읽기만 — rev-parse). 그 판이 이 복제본에 없으면 고정 판 081af21(이 묶음을 얹은 운영 판 — v2.30.0 위 S2).
# 고정값이면 다음 묶음(U3 …)이 운영에 들어간 뒤로 「기준 판 대조」 한 줄이 늘 실패한다(dbg_u_ui 와 같은 방식 — U3 검토 뒤 고침 2026-09-27).
BASE_REV_DEFAULT=$(git -C "${DBGU2_PROD_REPO:-/var/www/qaredi/tbox-v2}" rev-parse --short HEAD 2>/dev/null)
git -C "$ROOT" cat-file -e "${BASE_REV_DEFAULT:-none}^{commit}" 2>/dev/null || BASE_REV_DEFAULT=081af21
BASE_REV="${DBGU2_BASE_REV:-$BASE_REV_DEFAULT}"
APP_TREE="${DBGU2_APP_TREE:-$ROOT}"  # 띄울 트리 — 기본은 이 복제본. 고치기 전 판을 찍을 때만 다른 폴더
BEFORE="${DBGU2_BEFORE:-0}"
JAR_DIR="$(mktemp -d)"
WORK="$(mktemp -d)"
chmod 700 "$JAR_DIR" "$WORK"
SHOTS="${DBGU2_SHOTS:-$WORK/shots}"
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

# ★ SQL 은 **표준입력**으로만 보낸다(명령 인자 -c 0). 명령 줄이 DB 마다 늘 같은 한 모양이라 운영 경보 AL-9(sudo 명령마다 한 줄 —
#   디버깅 묶음 S 부터)가 30분에 한 줄로 묶인다(취합 규칙 2026-09-26 21:4x — 18:30~19:40 두 묶음 시험이 AL-9 417줄). 조회는 한 번에 모은다.
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

# ════════════════════════════════════════════════════════════════
hr "디버깅 묶음 U2 — 임시 인스턴스 준비 (운영 DB 에는 쓰지 않는다)"
if ss -ltn 2>/dev/null | grep -q "127.0.0.1:$PORT "; then echo "포트 $PORT 이 이미 쓰이고 있다 — 중단"; exit 3; fi
ENV_SRC="${QF2_ENV_SRC:-$ROOT/.env}"
[ -f "$ENV_SRC" ] || { echo "임시 .env 원본이 없다 — QF2_ENV_SRC 로 0600 사본을 넘긴다"; exit 3; }
[ -d "$APP_TREE/public" ] && [ -d "$APP_TREE/src" ] || { echo "띄울 트리($APP_TREE)에 public·src 가 없다"; exit 3; }
PROD_A0=$(prodq "SELECT coalesce(max(id),0) FROM audit_logs;")   # 운영 읽기 — 시작 한 번(psql 을 쓸 수 있는지도 이것으로 본다)
[[ "$PROD_A0" =~ ^[0-9]+$ ]] || { echo "psql(postgres) 로 운영 감사 max(id) 를 읽지 못했다 — 이 시험은 임시 DB 가 없으면 성립하지 않는다"; exit 3; }
ALERTS_L0=$(sudo -n wc -l < /var/log/tbox-v2/alerts.log 2>/dev/null || echo '?')
TOTP_M0=$(sudo -n stat -c %y /etc/tbox-v2/qa-totp.txt 2>/dev/null || echo '?')
note "운영 감사 max(id) 시작 = ${PROD_A0:-?} · 운영 경보 로그 줄 수 시작 = $ALERTS_L0 · 검토 계정 비밀 파일 시각 = $TOTP_M0 (셋 다 읽기만)"
[ "$APP_TREE" = "$ROOT" ] || note "띄우는 트리: $APP_TREE (DBGU2_APP_TREE)"

sudo -n -u postgres dropdb --if-exists "$DB" >/dev/null 2>&1
sudo -n -u postgres createdb -O tbox_owner "$DB" >/dev/null 2>&1 || { echo "임시 DB 를 만들 수 없다"; exit 3; }
sudo -n -u postgres pg_dump -d "$SRC_DB" 2>/dev/null \
  | sudo -n -u postgres psql -X -q -d "$DB" -v ON_ERROR_STOP=0 >/dev/null 2>"$WORK/restore.err"
DUMP_PS=("${PIPESTATUS[@]}")
[ "${DUMP_PS[0]}" -eq 0 ] || { echo "운영 DB 를 읽지 못했다 (pg_dump)"; exit 3; }
CNT=$(psqlq "SELECT (SELECT count(*) FROM files) || ' ' || (SELECT count(*) FROM community.pages);")   # 임시 DB 조회 한 번
[ -n "$CNT" ] || { echo "임시 DB 로 옮기지 못했다 — $(head -2 "$WORK/restore.err")"; exit 3; }
note "임시 DB $DB — 파일 ${CNT%% *} · 페이지 ${CNT##* }"
if [ "${DBGU2_SYNC_TOTP:-0}" = "1" ]; then
  for who in claude_employee01 claude_admin01 claude_guest01; do
    SX=$(review_secret "$who")
    [[ "$SX" =~ ^[A-Z2-7]{16,64}$ ]] || { note "$who — 비밀 모양이 아니어서 맞추지 않음"; continue; }
    printf "UPDATE users SET mfa_secret = '%s', mfa_last_step = NULL WHERE username = '%s' AND mfa_enabled;\n" "$SX" "$who" \
      | sudo -n -u postgres psql -X -q -d "$DB" -f - >/dev/null 2>&1
    SX=''
    note "$who — 임시 DB 의 2단계 비밀을 검토 계정 파일 값에 맞춤(DBGU2_SYNC_TOTP)"
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

hr "로그인 (claude_ 검토 계정 셋, 순차, F-71) · 관리자 재인증"
for who in claude_employee01 claude_admin01 claude_guest01; do
  login "$who" || { echo "  ✖ $who 로그인 실패 — 중단"; exit 4; }
done
S_E1=$(session_of claude_employee01); S_A1=$(session_of claude_admin01); S_G1=$(session_of claude_guest01)
PW_A1=$(review_password claude_admin01)
A_CSRF=$(csrf_of claude_admin01)
REAUTH=$(py_secret PW_A1 -- python3 -c 'import json,sys; print(json.dumps({"password": sys.stdin.readline().rstrip("\n")}))')
RC=$(curl_json_secret REAUTH -s -o /dev/null -w '%{http_code}' -X POST -b "$JAR_DIR/claude_admin01.jar" -A "$UA" \
     -H "X-CSRF-Token: $A_CSRF" -H 'Content-Type: application/json' "$BASE/api/me/reauth")
REAUTH=''
expect "관리자 재인증 200" "200" "$RC"

hr "U2-S 정적 (이 복제본 — 기준 판 $BASE_REV 대비)"
if [ "$BEFORE" = "1" ]; then
  note "DBGU2_BEFORE=1 — 고치기 전 판을 띄웠다. 정적 시험은 이 복제본(고친 판)을 보므로 건너뛴다"
else
  SCR="$ROOT/public/assets $ROOT/src/handlers $ROOT/src/community/handlers $ROOT/src/views $ROOT/src/help $ROOT/src/site"
  # 화면 글자로 나가는 옛 낱말 — 주석 줄(//, *, #, <!--)은 빼고 센다. 서버 감사 문장(src/Audit.php)·API 오류 문장은 이 묶음 밖(구현 기록 1장)
  old_words() {
    grep -rnE "$1" $SCR --include=*.js --include=*.php 2>/dev/null \
      | grep -vE '^[^:]+:[0-9]+:\s*(//|\*|/\*|#|<!--)' \
      | grep -vE "${2:-^$}" | wc -l
  }
  expect "「접근 레벨」·「접근 등급」·「레벨」 화면 문자열 0 (등급 하나로)" "0" \
    "$(old_words "접근 레벨|접근 등급|>레벨<|'레벨'|\"레벨\"|레벨 \\(" "src/handlers/org_permissions.php:.*Authz|Authz::")"
  expect "「내 계정 설정」 0 (「내 계정」 하나로)" "0" "$(old_words '내 계정 설정')"
  expect "aria-label·단추 글자 「다운로드」「삭제」「복구」 0 (받기·지우기·되살리기)" "0" \
    "$(old_words "aria-label=\"(다운로드|삭제|복구)\"|'(다운로드|삭제|복구)'\)|>(삭제|복구|다운로드)<|data-icon=\"(download|trash|restore)\"></span>(다운로드|삭제|복구)")"
  # files_fetch.php 의 거부 문장(「직접 내려받아 …」)은 다른 묶음(보안)이 같은 시각 고치는 창구 파일이라 이 묶음 밖 — 구현 기록 1장 「보고만」
  expect "「다운로드로 확인」「내려받」 화면 문장 0 (files_fetch.php 거부 문장 제외 — 보고만)" "0" "$(old_words "다운로드로 확인|내려받|내려 받" "src/handlers/files_fetch.php:")"
  expect "등급 고르개 글자에 영어 값 0 (「internal — 사내 전체」 꼴)" "0" \
    "$(grep -cE "'(public|internal|team|admin-only) — " "$ROOT/public/assets/app.js")"
  expect "역할 고르개 글자에 영어 병기 0 (「직원 (Employee)」 꼴)" "0" \
    "$(grep -cE "ROLE_LABELS\[v\] \+ ' \(' \+ v" "$ROOT/public/assets/app.js")"
  expect "창 바닥 「그만두기」 0 (창을 닫는 단추는 「닫기」)" "0" \
    "$(grep -rnE 'data-[a-z-]*-close>그만두기<' "$ROOT/src/community/handlers" "$ROOT/src/handlers" | wc -l)"
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
  # 인라인만 센다 — `<script` 태그 안에 src= 가 있는 외부 파일(`<script defer src=…>` 등)은 빼고 `<script>`·src 없는 `<script …>`·style="·on…=" (네 시험 같은 식 · 정리 묶음 D2 2026-09-27)
  INL=$(git -C "$ROOT" diff "$BASE_REV" -- src public 2>/dev/null | grep '^+' | grep -v '^+++' | grep -cE 'style="|<script([[:blank:]]+([^s>[:blank:]][^>[:blank:]]*|s[^r>[:blank:]][^>[:blank:]]*|sr[^c>[:blank:]][^>[:blank:]]*|src[^=>[:blank:]][^>[:blank:]]*|s|sr|src))*[[:blank:]]*>| on[a-z]+="')
  expect "더한 줄에 인라인 style·script·on* 속성 0" "0" "$INL"
  expect "라우트·판정·보안 핵심 파일 diff 0 — public/index.php · routes · Authz · CommunityAuthz · Auth · Http" "0" \
    "$(git -C "$ROOT" diff "$BASE_REV" -- public/index.php src/community/routes.php src/Authz.php src/community/CommunityAuthz.php src/Auth.php src/Http.php 2>/dev/null | wc -l)"
fi

hr "U2-H 서버가 그린 화면 글자 — 옛 낱말 0 (주석·태그·스크립트를 걷은 글자)"
page_text() {   # $1 계정 $2 경로 — 화면 HTML 의 보이는 글자(대략)만
  curl -s -b "$JAR_DIR/$1.jar" -A "$UA" "$SITE$2" | python3 -c '
import re, sys, html
s = sys.stdin.read()
s = re.sub(r"<!--.*?-->", " ", s, flags=re.S)
s = re.sub(r"<(script|style|template)\b.*?</\1>", " ", s, flags=re.S)
s = re.sub(r"<dialog id=\"changelog-dialog\".*?</dialog>", " ", s, flags=re.S)   # 지난 발행 기록(당시 말 그대로) — 낱말 표 밖
s = re.sub(r"<[^>]+>", " ", s)
print(re.sub(r"\s+", " ", html.unescape(s)))'
}
OLD_RE='내 계정 설정|접근 레벨|접근 등급|레벨|다운로드|내려받|복구|삭제|그만두기|루트|업로드'
for pair in "claude_employee01 /tbox/" "claude_employee01 /tbox/trash" "claude_employee01 /tbox/settings" "claude_employee01 /tbox/activity" \
            "claude_admin01 /tbox/users" "claude_admin01 /tbox/org" "claude_admin01 /tbox/admin" "claude_admin01 /tbox/audit" \
            "claude_admin01 /tbox/admin/permissions" "claude_employee01 /community/" "claude_guest01 /community/"; do
  set -- $pair
  txt=$(page_text "$1" "$2")
  hits=$(printf '%s' "$txt" | grep -oE "$OLD_RE" | sort | uniq -c | tr -s ' ' | tr '\n' ',' )
  n=$(printf '%s' "$txt" | grep -oE "$OLD_RE" | wc -l)
  [ "$BEFORE" = "1" ] && { note "$2 ($1) 옛 낱말 ${n}건 ${hits}"; continue; }
  [ -n "$txt" ] || { expect "$2 ($1) 화면 글자를 받았다" "yes" "no"; continue; }
  expect "$2 ($1) 화면 글자의 옛 낱말(${OLD_RE//|/·}) 0 ${hits:+— $hits}" "0" "$n"
done

hr "U2-B 화면 — tests/browser/dbg_u2_ui.py (360 · 412 · 820 · 1280 × 라이트 · 다크)"
if [ "${DBGU2_SKIP_BROWSER:-0}" = "1" ]; then
  note "DBGU2_SKIP_BROWSER=1 — 화면 시험을 건너뛴다(통과로 세지 않는다)"
else
  mkdir -p "$SHOTS"
  BARG=""; [ "$BEFORE" = "1" ] && BARG="before"
  { py_secret S_E1 S_A1 S_G1 PW_A1 -- env PATH="$HOME/.local/bin:$PATH" DBGU2_COMBOS="${DBGU2_COMBOS:-}" \
      python3 -u "$ROOT/tests/browser/dbg_u2_ui.py" "$SITE" "$UA" "$SHOTS" $BARG 2>&1
    echo "RC=$?"; } | tee "$WORK/browser.out"
  rc=$(sed -n 's/^RC=\([0-9]*\)$/\1/p' "$WORK/browser.out" | tail -1)
  expect "화면 시험 종료 코드" "0" "$rc"
  bf=$(sed -n 's/^SMOKE_FAIL=\([0-9]*\)$/\1/p' "$WORK/browser.out")
  [ "$BEFORE" = "1" ] && note "고치기 전 판의 화면 판정 실패 수 = ${bf:-?} (참고 — 시험이 결함을 잡는지)" || expect "화면 시험 실패 수" "0" "${bf:-?}"
  bp=$(grep -c '^  ✔' "$WORK/browser.out")
  NCOMBO=$(printf '%s' "${DBGU2_COMBOS:-360:light,360:dark,412:light,412:dark,820:light,820:dark,1280:light,1280:dark}" | tr ',' '\n' | grep -c .)
  BMIN=$((10 + 30 * NCOMBO))
  if [ "$BEFORE" != "1" ]; then
    [ "$bp" -ge "$BMIN" ] && expect "화면 시험이 헛돌지 않았다(✔ $BMIN 이상 — 조합 $NCOMBO)" "yes" "yes" || expect "화면 시험이 헛돌지 않았다(✔ $BMIN 이상 — 조합 $NCOMBO)" "yes" "no($bp)"
  fi
  note "스크린샷: $SHOTS (사람이 직접 연다)"
fi

hr "U2-L 서버 로그"
expect "임시 인스턴스 로그의 PHP 오류(Fatal·Warning·Notice·Deprecated)" "0" \
  "$(grep -cE 'PHP (Fatal|Warning|Notice|Deprecated|Parse)' "$INST/server.log")"

if [ "${REGRESS:-0}" = "1" ]; then
  hr "회귀 — 같은 임시 인스턴스에서(dbg_u_ui.sh 의 회귀 틀 그대로)"
  note "각 스크립트를 임시 인스턴스 폴더로 복사해 SITE·DB 만 바꿔 돈다(운영 8082·tbox_re 에 닿지 않는다)."
  sed -i "s#http://127.0.0.1:8082#$SITE#g" "$INST/app/scripts/ui_snapshot.sh" 2>/dev/null
  for s in ${REGRESS_LIST:-help_button q_launcher f68}; do
    src="$INST/app/tests/$s.sh"
    dst="$INST/app/tests/$s.temp.sh"
    [ -f "$src" ] || { echo "  ✖ $s — 스크립트가 없다"; FAIL=$((FAIL+1)); continue; }
    sed -e "s#http://127.0.0.1:8082#$SITE#g" -e "s#^DB=\"tbox_re\"#DB=\"$DB\"#" -e "s#-d tbox_re #-d $DB #g" "$src" > "$dst"
    if grep -v '^\s*#' "$dst" | grep -q '8082\|DB="tbox_re"\|-d tbox_re'; then
      echo "  ✖ $s — 운영 주소·DB 가 남아 있어 돌리지 않는다"; FAIL=$((FAIL+1)); continue
    fi
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
# 운영 읽기 — 끝 한 번(max(id) 와 이 시험 UA 의 행 수를 한 조회로)
END=$(prodq "SELECT coalesce(max(id),0) || '|' || (SELECT count(*) FROM audit_logs WHERE id > ${PROD_A0:-0} AND user_agent = '$UA' AND actor_name LIKE 'claude\\_%') FROM audit_logs;")
PROD_A1=${END%%|*}; UA_ROWS=${END##*|}
ALERTS_L1=$(sudo -n wc -l < /var/log/tbox-v2/alerts.log 2>/dev/null || echo '?')
TOTP_M1=$(sudo -n stat -c %y /etc/tbox-v2/qa-totp.txt 2>/dev/null || echo '?')
note "운영 감사 max(id) 끝 = ${PROD_A1:-?} (시작 ${PROD_A0:-?}) · 경보 로그 줄 수 끝 = $ALERTS_L1 (시작 $ALERTS_L0) · 비밀 파일 시각 끝 = $TOTP_M1"
expect "검토 계정 비밀 파일 시각 그대로(이 시험은 /etc 에 쓰지 않는다)" "$TOTP_M0" "$TOTP_M1"
expect "운영 감사에 이 시험의 UA($UA) 행이 늘지 않았다(시작 max 뒤)" "0" "${UA_ROWS:-?}"

printf '\n통과 %d · 실패 %d\n' "$PASS" "$FAIL"
[ "$BEFORE" = "1" ] && exit 0
[ "$FAIL" -eq 0 ]
