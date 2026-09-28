#!/usr/bin/env bash
# tests/community/stage7d3.sh — 커뮤니티 7-D3 수락 기준 C:T-210 ~ C:T-214 (고대비 화면 — 결정 J-5 · L-3 · DESIGN.md 2.16)
#
#   정적      바뀐 PHP php -l · 보안 린트 0 · 커뮤니티 JS 버전(--check) · 위키 검사 0 · 허용 목록 밖 파일 0 · 「고치지 않는 것」 차이 0 ·
#             라우트 표 = 한 줄 더함(PATCH /api/me/settings · authenticated) · 토큰 창구 집합·커뮤니티 라우트 그대로 · 더한 줄의 인라인
#             style·script·on* · innerHTML 류 · 쿠키·localStorage · filter: contrast 0 · 뷰 두 파일은 <html> 한 줄씩(새 함수 0) · 첫 커밋 = DESIGN
#   DB        마이그레이션(임시 DB) — 코드보다 먼저: 칸이 없으면 새 코드의 로그인한 요청 500 · 두 번 돌려도 같다 · `\d users` 칸 · 모든 행 false ·
#             `\dp users` 권한 전과 같음 · 되돌리기 줄(DROP COLUMN) 뒤 다시 적용 · 옛 코드(기준 판)는 칸이 있어도 200(코드만 되돌려도 무해)
#   C:T-210 창구   미로그인 401 · CSRF 없음 403 · true 200 → /api/me true · 형식 400(문자열 "true" · 다른 키 · 빈 본문 · 둘 이상 키 · 1 · null ·
#                  배열 · 대상 계정 칸 · JSON 아님) · 400 은 감사 0 · me.settings allow 1행(detail high_contrast) · 다른 계정 값 무변화(표 직접) ·
#                  false 로 되돌리기 200 · 개인 프로그램 토큰 → 401(토큰 집합 밖 — 같은 토큰으로 whoami 는 200) · 「역할로 보기」 중에도 본인 칸(H-8)
#   C:T-211 대비   tests/lib/contrast.py(WCAG 2.1 · 외부 라이브러리 0) — 실패 0 · 틀린 값·빠진 이름·화면 CSS 갈래를 넣은 사본은 실패(헛돌지 않음)
#   C:T-212 속성   끔: 런처 · /tbox/ · /community/ · /tbox/reauth 에 data-contrast 0 · 켬: 넷 모두 정확히 한 번 · 로그인 화면 0 · 미로그인 런처 0 ·
#                  tbox_theme=dark 와 둘 다 · 다른 계정 0 — `<html>` 줄 원문을 켬·끔 모두 적는다
#   C:T-213 브라우저 tests/browser/hc_screens.py(내 계정 체크 → 요청 하나 → 곧바로 · 화면 일곱 × 360·412(터치·마우스)·820·1280 × 라이트·다크 ·
#                  끄기 · 서버 실패 흉내)
#   C:T-214 변화 0 끈 상태 토큰 계산값 = 기준 판(ad5a795 사본 인스턴스) · ui_snapshot 1440·1280 결함 수 = 기준 판(켬에서도 같은 수) ·
#                  tests/browser/dbg_s_screens.py SMOKE_FAIL 0(필요한 거부 행을 이 시험이 만든다)
#   회귀(선택)     REGRESS=1 이면 같은 임시 인스턴스에서 REGRESS_LIST(기본 community/stage3 help_button api_whoami api_read api_folders q_launcher f68)를
#                  사본으로(8082 → 이 인스턴스 · tbox_re → 임시 DB) 돌린다. writes_prod·manual_only 는 넣지 않는다.
#
# ★ 운영 DB·운영 포트를 건드리지 않는다 — 임시 DB `tbox_7d3_<포트>_test`(운영 pg_dump 를 **읽어** 파이프로 붓기만) · 임시 인스턴스 둘
#   `php -S 127.0.0.1:<포트>`(이 라운드는 8106~8108 만 — 새 판 기본 8106 · 기준 판 기본 8107) · 검토 계정 claude_ 다섯(F-71) ·
#   비밀은 표준입력으로만(R-62 · tests/lib/secrets.sh). psql 은 SQL 을 **표준입력**으로만(명령 줄이 DB 마다 한 모양 — 운영 경보 AL-9).
#   임시 .env 원본 — 복제본에는 .env 가 없다: STAGE7D3_ENV_SRC 로 넘긴다(읽기만). 두 인스턴스의 .env 는 DB_NAME·STORAGE_DIR·AUDIT_LOG_FILE 을
#   임시로, SCAN_ENABLED=0 — 띄우기 전에 DB_NAME 이 임시 이름이고 tbox_re 가 아닌지 본다(아니면 exit 3/4 · 2026-09-26 사고 셋).
#   운영 감사 max(id)·경보 로그 줄 수·검토 계정 비밀 파일 시각은 시작·끝에 **읽기만** 해 적고, 운영 감사에 이 시험의 UA 행이 늘지 않았는지 본다.
# ★ 서버 메모리(취합 규칙 2026-09-27): 헤드리스 브라우저는 공용 잠금(QAREDI_BROWSER_LOCK — 있으면 flock -x) 아래에서 한 번에 하나,
#   띄우기 직전 `free -m` 의 available 이 700MB 미만이면 1분씩 기다린다(최대 15분 — 그래도 모자라면 그 화면 시험을 실패로 두고 계속).
# 손잡이: STAGE7D3_PORT · STAGE7D3_BASE_PORT · STAGE7D3_BASE_REV(기본 ad5a795 — 운영 HEAD · 7-D3 앞) · STAGE7D3_ENV_SRC · STAGE7D3_SHOTS ·
#         STAGE7D3_SKIP_BROWSER=1 · HC_COMBOS · QAREDI_BROWSER_LOCK · REGRESS=1 · REGRESS_LIST · REGRESS_KEEP
# 사용: sudo -v && STAGE7D3_ENV_SRC=<.env 경로> QAREDI_BROWSER_LOCK=<잠금 파일> bash tests/community/stage7d3.sh
set -u
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
. "$ROOT/tests/lib/review_accounts.sh" || { echo "tests/lib/review_accounts.sh 를 읽지 못했다 — 시험을 시작하지 않는다"; exit 3; }
. "$ROOT/tests/lib/secrets.sh" || { echo "tests/lib/secrets.sh 를 읽지 못했다 — 시험을 시작하지 않는다 (R-62)"; exit 3; }
PORT="${STAGE7D3_PORT:-8106}"
BPORT="${STAGE7D3_BASE_PORT:-8107}"
for p in "$PORT" "$BPORT"; do
  case "$p" in 8106|8107|8108) ;; *) echo "이 라운드는 포트 8106~8108 만 쓴다(운영 80·8081·8082 금지) — $p"; exit 3 ;; esac
done
[ "$PORT" != "$BPORT" ] || { echo "새 판·기준 판 포트가 같다 — 중단"; exit 3; }
SITE="http://127.0.0.1:$PORT"
BASE="$SITE/tbox"
CBASE="$SITE/community"
BSITE="http://127.0.0.1:$BPORT"
UA="tbox-review/$(date +%F)"
SRC_DB="tbox_re"                     # 운영 — **읽기만** 한다(pg_dump · max(id))
DB="tbox_7d3_${PORT}_test"           # 임시 — 여기에만 쓴다(접두 tbox_7d3_)
case "$DB" in tbox_7d3_*_test) ;; *) echo "임시 DB 이름 규칙 밖 — 중단"; exit 3 ;; esac
BASE_REV="${STAGE7D3_BASE_REV:-ad5a795}"
git -C "$ROOT" cat-file -e "${BASE_REV}^{commit}" 2>/dev/null || { echo "기준 판 $BASE_REV 가 이 저장소에 없다 — 중단"; exit 3; }
ENV_SRC="${STAGE7D3_ENV_SRC:-$ROOT/.env}"
JAR_DIR="$(mktemp -d)"
WORK="$(mktemp -d)"
chmod 700 "$JAR_DIR" "$WORK"
SHOTS="${STAGE7D3_SHOTS:-$WORK/shots}"
INST=""
SPID=""
BPID=""
PASS=0
FAIL=0
SKIP=0

hr()  { printf '\n════════════════════════════════════════════════════════════\n%s\n════════════════════════════════════════════════════════════\n' "$1"; }
sub() { printf '\n── %s\n' "$1"; }
expect() {
  if [ "$2" = "$3" ]; then printf '  ✔ %s  (기대=%s 실제=%s)\n' "$1" "$2" "$3"; PASS=$((PASS+1))
  else printf '  ✖ %s  (기대=%s 실제=%s)\n' "$1" "$2" "$3"; FAIL=$((FAIL+1)); fi
}
note() { printf '  · %s\n' "$1"; }
skip() { printf '  ○ 건너뜀 — %s  (%s)\n' "$1" "$2"; SKIP=$((SKIP+1)); }

cleanup() {
  [ -n "$SPID" ] && { kill "$SPID" 2>/dev/null; wait "$SPID" 2>/dev/null; }
  [ -n "$BPID" ] && { kill "$BPID" 2>/dev/null; wait "$BPID" 2>/dev/null; }
  pkill -f "^php -S 127\.0\.0\.1:$PORT " 2>/dev/null
  pkill -f "^php -S 127\.0\.0\.1:$BPORT " 2>/dev/null
  sudo -n -u postgres dropdb --if-exists "$DB" >/dev/null 2>&1
  [ -n "$INST" ] && rm -rf "$INST"
  rm -rf "$JAR_DIR" "$WORK"
}
trap cleanup EXIT

# SQL 은 표준입력으로만 — 임시 DB(psqlq)·운영 읽기(prodq, 시작·끝 한 번씩 SELECT 만)
psqlq() { ( cd / && sudo -n -u postgres psql -X -q -At -d "$DB" ) 2>/dev/null <<< "$1"; }
psqlf() { ( cd / && sudo -n -u postgres psql -X -q -v ON_ERROR_STOP=1 -d "$DB" -f - ) < "$1" 2>&1; }
prodq() { ( cd / && sudo -n -u postgres psql -X -q -At -d "$SRC_DB" ) 2>/dev/null <<< "$1"; }
review_password() { sudo -n awk -v u="$1" '$1==u{print $3}' /etc/tbox-v2/seed-accounts.txt 2>/dev/null; }
review_secret()   { sudo -n awk -v u="$1" '$1==u{print $2}' /etc/tbox-v2/qa-totp.txt 2>/dev/null; }
login() {   # $1 계정 [$2 t-box 주소(기본 새 판)] — 잼은 $JAR_DIR/<계정>.jar
  local who="$1" at="${2:-$BASE}" jar="$JAR_DIR/$1.jar" pw sec raw code left out try body
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
    out=$(curl_json_secret body -s -c "$jar" -A "$UA" -H 'Content-Type: application/json' "$at/api/login")
    case "$out" in *'"ok":true'*) return 0 ;; esac
    left=$(printf '%s' "$raw" | sed -n 's/^남은 시간: \([0-9]*\)초.*/\1/p')
    [ -n "$left" ] || left=31
    printf '  · %s 로그인 재시도 — 다음 코드 창까지 %s초\n' "$who" "$left" >&2
    sleep $((left + 1))
  done
  return 1
}
session_of() { awk '$6=="__Host-tbox_session"{print $7}' "$JAR_DIR/$1.jar" 2>/dev/null | tail -1; }
csrf_of() { curl -s -b "$JAR_DIR/$1.jar" -A "$UA" "${2:-$BASE}/api/me" | python3 -c 'import json,sys; print(json.load(sys.stdin).get("csrf_token",""))' 2>/dev/null; }
status_of() { sed -n 's#^HTTP/[0-9.]* \([0-9]*\).*#\1#p' "$1" | tail -1 | tr -d '\r'; }
jget() {
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
# t-box 창구 — $1 메서드 $2 경로 $3 계정 [$4 본문 파일] [$5 내용 형식] → 상태 코드 · 본문 $WORK/b
tapi() {
  local method="$1" path="$2" who="$3" bodyfile="${4:-}" ctype="${5:-application/json}" csrf
  eval "csrf=\${CSRF_${who}:-}"
  if [ -z "$bodyfile" ]; then
    curl -s -D "$WORK/h" -o "$WORK/b" -A "$UA" -b "$JAR_DIR/$who.jar" -X "$method" -H 'Expect:' -H "X-CSRF-Token: $csrf" "$BASE$path"
  else
    curl -s -D "$WORK/h" -o "$WORK/b" -A "$UA" -b "$JAR_DIR/$who.jar" -X "$method" -H 'Expect:' -H "Content-Type: $ctype" \
      -H "X-CSRF-Token: $csrf" --data-binary "@$bodyfile" "$BASE$path"
  fi
  status_of "$WORK/h"
}
capi() {
  local method="$1" path="$2" who="$3" bodyfile="${4:-}" csrf
  eval "csrf=\${CSRF_${who}:-}"
  if [ -z "$bodyfile" ]; then
    curl -s -D "$WORK/h" -o "$WORK/b" -A "$UA" -b "$JAR_DIR/$who.jar" -X "$method" -H 'Expect:' -H "X-CSRF-Token: $csrf" "$CBASE$path"
  else
    curl -s -D "$WORK/h" -o "$WORK/b" -A "$UA" -b "$JAR_DIR/$who.jar" -X "$method" -H 'Expect:' -H 'Content-Type: application/json' \
      -H "X-CSRF-Token: $csrf" --data-binary "@$bodyfile" "$CBASE$path"
  fi
  status_of "$WORK/h"
}
body() { printf '%s' "$1" > "$WORK/body.json"; }
set_hc() { body "{\"high_contrast\":$2}"; tapi PATCH /api/me/settings "$1" "$WORK/body.json"; }   # $1 계정 $2 true|false
hc_db() { psqlq "SELECT high_contrast::text FROM users WHERE username = '$1';"; }
mark() { psqlq "SELECT coalesce(max(id),0) FROM audit_logs;"; }
audit_n() { psqlq "SELECT count(*) FROM audit_logs WHERE id > ${1:-0} AND action = '$2' AND result = '$3';"; }
html_of() {   # $1 사이트+경로 [$2 계정(없으면 로그인 전)] [$3 더 실을 쿠키 「이름=값」] → 상태 코드 · 본문 $WORK/html
  local args=(-s -o "$WORK/html" -w '%{http_code}' -A "$UA") ck=""
  if [ -n "${3:-}" ]; then
    # 쿠키 머리를 한 줄로 직접 만든다(잼과 섞어 머리가 둘이 되지 않게). 세션 값은 머리 줄로만 — 비밀은 명령 인자에 싣지 않는다(R-62)
    ck="$3"; [ -n "${2:-}" ] && ck="$ck; __Host-tbox_session=$(session_of "$2")"
    curl_hdr_secret Cookie ck "${args[@]}" "$1"
    ck=''
    return
  fi
  [ -n "${2:-}" ] && args+=(-b "$JAR_DIR/$2.jar")
  curl "${args[@]}" "$1"
}
html_line() { grep -m1 -o '<html[^>]*>' "$WORK/html" 2>/dev/null; }
attr_n() { grep -o 'data-contrast="high"' "$WORK/html" 2>/dev/null | wc -l | tr -d ' '; }
anyattr_n() { grep -o 'data-contrast' "$WORK/html" 2>/dev/null | wc -l | tr -d ' '; }
reauth_admin() {
  local pw rc
  pw=$(review_password claude_admin01)
  rc=$(py_secret pw -- python3 -c "import json,sys;print(json.dumps({'password':sys.stdin.readline().rstrip('\n')}))" \
       | curl -s -o /dev/null -w '%{http_code}' -b "$JAR_DIR/claude_admin01.jar" -A "$UA" -H 'Content-Type: application/json' \
        -H "X-CSRF-Token: $CSRF_claude_admin01" --data-binary @- "$BASE/api/me/reauth")
  pw=''
  printf '%s' "$rc"
}
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
mem_ok() {   # 헤드리스 브라우저 하나를 띄우기 전 — available ≥ 700MB 를 1분씩 최대 15분 기다린다(취합 규칙 2026-09-27)
  local a i
  for i in $(seq 1 15); do
    a=$(free -m | awk '/^Mem:/{print $7}')
    [ "${a:-0}" -ge 700 ] && return 0
    printf '  · 메모리 여유 %sMB (700 미만) — 1분 기다림 %d/15\n' "${a:-?}" "$i" >&2
    sleep 60
  done
  return 1
}
LOCKCMD=()
[ -n "${QAREDI_BROWSER_LOCK:-}" ] && LOCKCMD=(flock -x "$QAREDI_BROWSER_LOCK")
# 잠금 안에서 한 번 더 메모리를 보고 띄운다(기다리는 동안 다른 작업자가 띄웠을 수 있다) — $@ = 부를 명령
browser_run() {
  mem_ok || { echo "메모리 여유가 15분 동안 700MB 미만 — 띄우지 않았다"; return 98; }
  "${LOCKCMD[@]}" bash -c 'a=$(free -m | awk "/^Mem:/{print \$7}"); echo "  · 브라우저 띄우기 직전 메모리 여유 ${a}MB"; [ "${a:-0}" -ge 700 ] || { echo "메모리 여유 700MB 미만 — 띄우지 않는다"; exit 97; }; exec "$@"' _ "$@"
}
server_errs() { grep -cE 'PHP (Fatal|Warning|Notice|Deprecated|Parse)|Uncaught ' "$1" 2>/dev/null; }

# ════════════════════════════════════════════════════════════════
hr "7-D3 정적 (이 복제본 — 기준 판 $BASE_REV 대비)"
PHPBAD=0; NPHP=0
for f in $(git -C "$ROOT" diff --name-only "$BASE_REV" -- '*.php' 2>/dev/null) $(git -C "$ROOT" ls-files --others --exclude-standard -- '*.php'); do
  [ -f "$ROOT/$f" ] || continue
  NPHP=$((NPHP+1))
  php -l "$ROOT/$f" >/dev/null 2>&1 || { PHPBAD=$((PHPBAD+1)); echo "    php -l 실패: $f"; }
done
expect "바뀐·새 PHP ${NPHP}개 php -l 실패 0" "0" "$PHPBAD"
bash "$ROOT/scripts/security_lint.sh" > "$WORK/lint.txt" 2>&1
expect "보안 린트 통과(금지 패턴 0건)" "1" "$(grep -c '결과: 통과 (금지 패턴 0건)' "$WORK/lint.txt")"
php "$ROOT/scripts/community_asset_version.php" --check > "$WORK/assetver.txt" 2>&1
expect "커뮤니티 JS import 버전 표시가 지금 내용과 맞다(--check)" "0" "$?"
expect "  커뮤니티 JS 는 바뀌지 않았다(이번엔 community.css 만 — 버전 다시 찍기 0)" "0" \
  "$(git -C "$ROOT" diff --name-only "$BASE_REV" -- 'public/assets/community/*.js' | wc -l | tr -d ' ')"
bash -n "$ROOT/tests/community/stage7d3.sh"; expect "stage7d3.sh 문법" "0" "$?"
PYC="$WORK/pyc"; mkdir -p "$PYC"
PYTHONPYCACHEPREFIX="$PYC" python3 -m py_compile "$ROOT/tests/lib/contrast.py" "$ROOT/tests/browser/hc_screens.py"; expect "contrast.py · hc_screens.py 문법" "0" "$?"
# 허용 목록(지시서 2.5 + 메인 결정 2026-09-27 22:1x — 반영 스크립트는 복제본 review-scripts/) 밖의 파일 0
ALLOW="DESIGN.md db/migrations/2026-10-08_users_high_contrast.sql db/schema.sql public/index.php src/Auth.php src/Audit.php
src/handlers/me_settings.php src/handlers/me.php src/handlers/settings_page.php src/help/screens.php src/views/shell_top.php src/views/launcher.php
public/assets/app.css public/assets/community/community.css public/assets/app.js docs/design-guide.html
tests/lib/contrast.py tests/browser/hc_screens.py tests/community/stage7d3.sh tests/catalog.json
SECURITY.md STAGES.md README.md wiki/community/stage-7-d.json
review-scripts/apply-7d3-db-2026-09-27.sh review-scripts/apply-7d3-repo-2026-09-27.sh review-scripts/pins-7d3-repo.sh
tests/api_whoami.sh ARCHITECTURE.md API.md"   # 끝 줄 셋 = 검토 반영(review-fix/7-D3 · 메인 지시) 허용 목록 예외 — /api/me 키 목록 한 낱말 · t-box 문서만
OUTSIDE=""
for f in $( (git -C "$ROOT" diff --name-only "$BASE_REV"; git -C "$ROOT" ls-files --others --exclude-standard) | LC_ALL=C sort -u); do
  case " $(printf '%s' "$ALLOW" | tr '\n' ' ') " in *" $f "*) ;; *) OUTSIDE="$OUTSIDE $f" ;; esac
done
expect "허용 목록 밖에서 바뀐·새 파일 0" "" "$OUTSIDE"
# 「고치지 않는 것」(지시서 2.5) — 차이 0
KEEP="src/Authz.php src/Http.php src/Db.php src/community/CommunityAuthz.php src/community/routes.php src/handlers/login_page.php deploy"
expect "「고치지 않는 것」 차이 0 — Authz · Http(themeAttr 옆 새 함수 0) · Db · CommunityAuthz · routes.php · login_page.php · deploy/**" "0" \
  "$(git -C "$ROOT" diff "$BASE_REV" -- $KEEP | wc -l | tr -d ' ')"
# 라우트 표 — 한 줄 더함, 지운 줄 0 · 토큰 창구 집합·강제 절차 허용 목록·커뮤니티 라우트 그대로
troutes() { grep -E "^[[:space:]]*\['(GET|POST|PUT|PATCH|DELETE)', *'/" || true; }
tok() { sed -n '/^const TBOX_API_TOKEN_\(ROUTES\|PERSONAL_ROUTES\|ONLY_ROUTES\) = \[/,/^\];/p'; }
gate() { sed -n '/^const TBOX_GATE_ALLOW_\(PASSWORD_CHANGE\|MFA_SETUP\) = \[/,/^\];/p'; }
git -C "$ROOT" show "$BASE_REV:public/index.php" > "$WORK/index.base"
RDIFF=$(diff <(troutes < "$WORK/index.base") <(troutes < "$ROOT/public/index.php") | grep -E '^[<>]' || true)
expect "t-box 라우트 표 차이 = 더한 줄 하나(PATCH /api/me/settings · me_settings.php · authenticated) · 지운 줄 0" \
  "> ['PATCH',  '/api/me/settings',         'me_settings.php',     Authz::POLICY_AUTHENTICATED]," "$(printf '%s' "$RDIFF" | sed 's/^> *\[/> [/')"
expect "토큰 창구 집합 셋 그대로(토큰으로는 못 부른다)" "$(tok < "$WORK/index.base" | sha256sum | cut -c1-16)" "$(tok < "$ROOT/public/index.php" | sha256sum | cut -c1-16)"
expect "강제 절차 허용 목록 그대로" "$(gate < "$WORK/index.base" | sha256sum | cut -c1-16)" "$(gate < "$ROOT/public/index.php" | sha256sum | cut -c1-16)"
# 인라인 — `<script` 태그 안에 src= 가 있는 외부 파일은 빼고 `<script>`·src 없는 `<script …>`·style="·on…=" (네 시험 같은 식)
#   docs/design-guide.html 은 저장소 안 견본(서버가 내보내지 않는다 — 원래 인라인 style·script 가 있는 문서)이라 뺀다
INL=$(git -C "$ROOT" diff "$BASE_REV" -- src public | grep '^+' | grep -v '^+++' | grep -cE 'style="|<script([[:blank:]]+([^s>[:blank:]][^>[:blank:]]*|s[^r>[:blank:]][^>[:blank:]]*|sr[^c>[:blank:]][^>[:blank:]]*|src[^=>[:blank:]][^>[:blank:]]*|s|sr|src))*[[:blank:]]*>| on[a-z]+="')
expect "더한 줄(src · public)에 인라인 style·script·on* 속성 0" "0" "$INL"
expect "더한 JS 줄에 innerHTML·outerHTML·insertAdjacentHTML·document.write 0" "0" \
  "$(git -C "$ROOT" diff "$BASE_REV" -- 'public/assets/*.js' | grep '^+' | grep -v '^+++' | grep -cE 'innerHTML|outerHTML|insertAdjacentHTML|document\.write')"
expect "더한 JS 줄에 쿠키·localStorage·sessionStorage 0(고대비는 계정 칸으로만 — H-1 · 2.6)" "0" \
  "$(git -C "$ROOT" diff "$BASE_REV" -- 'public/assets/*.js' | grep '^+' | grep -v '^+++' | grep -cE 'document\.cookie|localStorage|sessionStorage')"
expect "더한 JS 줄에 브라우저 기본 창(alert·confirm·prompt) 0" "0" \
  "$(git -C "$ROOT" diff "$BASE_REV" -- 'public/assets/*.js' | grep '^+' | grep -v '^+++' | grep -cE 'window\.(alert|confirm|prompt)\(')"
expect "CSS 에 filter: contrast()·invert() 통째 뒤집기 0" "0" \
  "$(git -C "$ROOT" diff "$BASE_REV" -- 'public/assets/*.css' 'public/assets/community/*.css' | grep '^+' | grep -v '^+++' | grep -cE 'filter:[^;]*(contrast|invert)\(')"
# 뷰 두 파일 — <html> 한 줄씩만 바뀌고 새 함수 0(부르는 것은 원래 있던 Http::themeAttr()·is_array 뿐)
vline() { git -C "$ROOT" diff -U0 "$BASE_REV" -- "$1" | grep -E '^[-+]' | grep -vE '^(\+\+\+|---) '; }
for v in src/views/shell_top.php src/views/launcher.php; do
  D=$(vline "$v")
  CALLS=$(printf '%s\n' "$D" | grep '^+' | grep -oE '[A-Za-z_][A-Za-z0-9_:]*\(' | LC_ALL=C sort -u | tr '\n' ' ')
  expect "$v — 지운 줄 하나·더한 줄 하나 모두 <html> 줄" "1 1 1 1" \
    "$(printf '%s\n' "$D" | grep -c '^-') $(printf '%s\n' "$D" | grep -c '^+') $(printf '%s\n' "$D" | grep -c '^-<html lang="ko"') $(printf '%s\n' "$D" | grep -c '^+<html lang="ko"')"
  case "$v" in
    *launcher.php) expect "  더한 줄이 부르는 것 — Http::themeAttr · is_array 뿐(새 PHP 함수 0)" "Http::themeAttr( is_array( " "$CALLS" ;;
    *) expect "  더한 줄이 부르는 것 없음(새 PHP 함수 0 — \$themeAttr 는 원래 변수)" "" "$CALLS" ;;
  esac
done
expect "로그인 화면(login_page.php)에 data-contrast 0" "0" "$(grep -c 'data-contrast' "$ROOT/src/handlers/login_page.php")"
expect "src 에서 data-contrast 를 그리는 자리 = 셸·런처 두 줄" "2" "$(grep -rn 'data-contrast="high"' "$ROOT/src" | wc -l | tr -d ' ')"
FIRST=$(git -C "$ROOT" rev-list --reverse "$BASE_REV..HEAD" | head -1)
expect "이 라운드 첫 커밋 = DESIGN.md 하나(H-10 문서 먼저)" "DESIGN.md" "$( [ -n "$FIRST" ] && git -C "$ROOT" show --name-only --format= "$FIRST" | tr '\n' ' ' | sed 's/ $//')"
expect "DESIGN 2.16 절 · 1.1 머리 한 줄 · 6장 한 줄" "1 1 1" \
  "$(grep -c '^### 2.16 고대비 토큰 한 벌' "$ROOT/DESIGN.md") $(grep -c '^고대비 화면(2.16)은 이 표와 2.14 의 \*\*이름을 그대로\*\*' "$ROOT/DESIGN.md") $(grep -c '^- \[ \] 고대비 켬(`<html data-contrast="high">`, 2.16)' "$ROOT/DESIGN.md")"
MIG="$ROOT/db/migrations/2026-10-08_users_high_contrast.sql"
expect "마이그레이션 — 칸 하나(ADD COLUMN IF NOT EXISTS … BOOLEAN NOT NULL DEFAULT false) · 주석 밖 GRANT·REVOKE·DROP·UPDATE 0" "1 0" \
  "$(grep -c '^ALTER TABLE users ADD COLUMN IF NOT EXISTS high_contrast BOOLEAN NOT NULL DEFAULT false;$' "$MIG") $(grep -vE '^[[:space:]]*--' "$MIG" | grep -ciE 'GRANT|REVOKE|DROP|UPDATE|DELETE')"
expect "db/schema.sql users 에 같은 칸" "1" "$(grep -cE '^    high_contrast +BOOLEAN +NOT NULL DEFAULT false,$' "$ROOT/db/schema.sql")"
expect "Auth::SQL_LOOKUP 한 열 · 사용자 배열 한 칸 · 감사 문장 행 하나 · /api/me 한 값" "1 1 1 1" \
  "$(grep -c '^               u.high_contrast AS high_contrast,$' "$ROOT/src/Auth.php") $(grep -c "^            'high_contrast' => \$row\['high_contrast'\] === true,$" "$ROOT/src/Auth.php") $(grep -c "^        'me.settings|allow' " "$ROOT/src/Audit.php") $(grep -c "^    'high_contrast'    => (\$user\['high_contrast'\] ?? false) === true,$" "$ROOT/src/handlers/me.php")"

# ════════════════════════════════════════════════════════════════
hr "7-D3 — 임시 인스턴스 둘 준비 (운영 DB 에는 쓰지 않는다)"
for p in "$PORT" "$BPORT"; do
  if ss -ltn 2>/dev/null | grep -q "127.0.0.1:$p "; then echo "포트 $p 이 이미 쓰이고 있다 — 중단"; exit 3; fi
done
[ -f "$ENV_SRC" ] || { echo "임시 .env 원본이 없다 — STAGE7D3_ENV_SRC 로 넘긴다"; exit 3; }
avail_kb=$(df -Pk / | awk 'NR==2{print $4}')
[ "$avail_kb" -ge 1048576 ] || { echo "루트 디스크 여유 1GB 미만 — 시험을 시작하지 않는다 (SECURITY R-80)"; exit 3; }
PROD_A0=$(prodq "SELECT coalesce(max(id),0) FROM audit_logs;")   # 운영 읽기 — 시작 한 번
[[ "$PROD_A0" =~ ^[0-9]+$ ]] || { echo "psql(postgres) 로 운영 감사 max(id) 를 읽지 못했다 — 이 시험은 임시 DB 가 없으면 성립하지 않는다"; exit 3; }
ALERTS_L0=$(sudo -n wc -l < /var/log/tbox-v2/alerts.log 2>/dev/null || echo '?')
TOTP_M0=$(sudo -n stat -c %y /etc/tbox-v2/qa-totp.txt 2>/dev/null || echo '?')
note "운영 감사 max(id) 시작 = $PROD_A0 · 운영 경보 로그 줄 수 시작 = $ALERTS_L0 · 검토 계정 비밀 파일 시각 = $TOTP_M0 (셋 다 읽기만)"
sudo -n -u postgres dropdb --if-exists "$DB" >/dev/null 2>&1
sudo -n -u postgres createdb -O tbox_owner "$DB" >/dev/null 2>&1 || { echo "임시 DB 를 만들 수 없다"; exit 3; }
sudo -n -u postgres pg_dump -d "$SRC_DB" 2>/dev/null \
  | sudo -n -u postgres psql -X -q -d "$DB" -v ON_ERROR_STOP=0 >/dev/null 2>"$WORK/restore.err"
DUMP_PS=("${PIPESTATUS[@]}")
[ "${DUMP_PS[0]}" -eq 0 ] || { echo "운영 DB 를 읽지 못했다 (pg_dump)"; exit 3; }
CNT=$(psqlq "SELECT (SELECT count(*) FROM users) || ' ' || (SELECT count(*) FROM community.pages);")
[ -n "$CNT" ] || { echo "임시 DB 로 옮기지 못했다 — $(head -2 "$WORK/restore.err")"; exit 3; }
note "임시 DB $DB — 계정 ${CNT%% *} · 페이지 ${CNT##* }"
expect "임시 DB 에 아직 칸이 없다(운영 = 반영 전)" "0" "$(psqlq "SELECT count(*) FROM information_schema.columns WHERE table_schema='public' AND table_name='users' AND column_name='high_contrast';")"

INST="$(mktemp -d)"; chmod 700 "$INST"
mkdir -p "$INST/app" "$INST/base" "$INST/storage" "$INST/bstorage" "$INST/log" "$INST/blog"
tar -C "$ROOT" -cf - --exclude=.git --exclude=jar --exclude=api-test.txt --exclude=./.env --exclude=./.claude --exclude=__pycache__ . | tar -C "$INST/app" -xf -
git -C "$ROOT" archive "$BASE_REV" | tar -C "$INST/base" -xf -
make_env "$ENV_SRC" "$INST/app/.env" "$INST/storage" "$INST/log/audit.log" || { echo "임시 .env(새 판)에 임시 값이 들어가지 않았다 — 중단"; exit 4; }
make_env "$ENV_SRC" "$INST/base/.env" "$INST/bstorage" "$INST/blog/audit.log" || { echo "임시 .env(기준 판)에 임시 값이 들어가지 않았다 — 중단"; exit 4; }
note "임시 .env 확인: 새 판 $(grep '^DB_NAME=' "$INST/app/.env") · 기준 판 $(grep '^DB_NAME=' "$INST/base/.env") (둘 다 같은 임시 DB)"
ln -s /var/www/qaredi/community "$INST/community" 2>/dev/null
# 위키 검사는 저장소 바깥의 기획 폴더 둘(../community/STAGES.md · ../STAGES.md)을 읽는다 — 읽기 전용 링크로 두고 사본에서 돌린다
ln -s /var/www/qaredi/STAGES.md "$INST/STAGES.md" 2>/dev/null
php "$INST/app/scripts/wiki_check.php" > "$WORK/wiki.txt" 2>&1
expect "위키 검사 0건(wiki_check.php — 이 복제본 사본 · 기획 폴더는 운영 것을 읽기만)" "0 1" "$? $(grep -c '^결과: 통과' "$WORK/wiki.txt")"
make_router "$INST/app"; make_router "$INST/base"
( cd "$INST/app" && exec php -S "127.0.0.1:$PORT" -t public router.php > "$INST/server.log" 2>&1 ) &
SPID=$!
( cd "$INST/base" && exec php -S "127.0.0.1:$BPORT" -t public router.php > "$INST/bserver.log" 2>&1 ) &
BPID=$!
c=""; bc=""
for _ in $(seq 1 30); do sleep 0.3; c=$(curl -s -o /dev/null -w '%{http_code}' -A "$UA" "$BASE/login"); bc=$(curl -s -o /dev/null -w '%{http_code}' -A "$UA" "$BSITE/tbox/login"); [ "$c$bc" = "200200" ] && break; done
expect "두 인스턴스가 떴다(로그인 화면 200 · 새 판 $PORT · 기준 판 $BPORT = $BASE_REV)" "200 200" "$c $bc"
[ "$c$bc" = "200200" ] || { tail -5 "$INST/server.log" "$INST/bserver.log"; exit 4; }

# ════════════════════════════════════════════════════════════════
hr "DB — 마이그레이션은 코드보다 먼저 (임시 DB 에만)"
login claude_employee01 "$BSITE/tbox" || { echo "  ✖ claude_employee01 로그인(기준 판) 실패 — 중단"; exit 4; }
expect "칸이 없는 DB · 기준 판(옛 코드) 로그인한 /api/me → 200" "200" "$(curl -s -o /dev/null -w '%{http_code}' -A "$UA" -b "$JAR_DIR/claude_employee01.jar" "$BSITE/tbox/api/me")"
expect "칸이 없는 DB · 새 코드 로그인한 /api/me → 500(SQL_LOOKUP 이 없는 칸을 읽는다 — 그래서 DB 먼저)" "500" "$(curl -s -o /dev/null -w '%{http_code}' -A "$UA" -b "$JAR_DIR/claude_employee01.jar" "$BASE/api/me")"
expect "칸이 없는 DB · 새 코드 미로그인 로그인 화면 → 200(세션 조회 없음)" "200" "$(curl -s -o /dev/null -w '%{http_code}' -A "$UA" "$BASE/login")"
LOG_OFF=$(wc -l < "$INST/server.log")   # 여기까지의 서버 오류는 위 「순서 어긋남」을 일부러 만든 것 — 끝의 서버 로그 확인은 이 뒤만 센다
note "순서 어긋남을 일부러 만든 서버 로그 줄 $LOG_OFF 줄(PHP 오류 $(server_errs "$INST/server.log")줄 — 아래 로그 확인에서 뺀다)"
DP0=$(psqlq '\dp public.users')
COLS0=$(psqlq "SELECT string_agg(column_name, ',' ORDER BY ordinal_position) FROM information_schema.columns WHERE table_schema='public' AND table_name='users';")
sub "마이그레이션 두 번(여러 번 돌려도 같다)"
psqlf "$MIG" > "$WORK/mig1.txt"; R1=$?
psqlf "$MIG" > "$WORK/mig2.txt"; R2=$?
expect "마이그레이션 1회 · 2회 종료 코드" "0 0" "$R1 $R2"
expect "  칸 모양 — boolean · NOT NULL · 기본 false" "boolean|NO|false" \
  "$(psqlq "SELECT data_type || '|' || is_nullable || '|' || column_default FROM information_schema.columns WHERE table_schema='public' AND table_name='users' AND column_name='high_contrast';")"
expect "  칸은 끝에 하나만 더해졌다(다른 칸 순서 그대로)" "$COLS0,high_contrast" \
  "$(psqlq "SELECT string_agg(column_name, ',' ORDER BY ordinal_position) FROM information_schema.columns WHERE table_schema='public' AND table_name='users';")"
expect "  기존 행 전부 false" "0" "$(psqlq "SELECT count(*) FROM users WHERE high_contrast IS DISTINCT FROM false;")"
DP1=$(psqlq '\dp public.users')
expect "  \\dp users — 권한 전과 같음(표 단위 · tbox_push 열 단위 SELECT 셋 그대로)" "$(printf '%s' "$DP0" | sha256sum | cut -c1-16)" "$(printf '%s' "$DP1" | sha256sum | cut -c1-16)"
expect "  tbox_push 는 이 칸을 못 읽는다(열 단위 셋 밖)" "f" \
  "$(psqlq "SELECT CASE WHEN EXISTS (SELECT 1 FROM pg_roles WHERE rolname='tbox_push') THEN has_column_privilege('tbox_push', 'public.users', 'high_contrast', 'SELECT')::text::char ELSE 'f' END;")"
expect "  앱 계정 tbox_app 은 읽고 쓴다(표 단위 권한에 포함)" "t t" \
  "$(psqlq "SELECT has_column_privilege('tbox_app', 'public.users', 'high_contrast', 'SELECT')::text::char || ' ' || has_column_privilege('tbox_app', 'public.users', 'high_contrast', 'UPDATE')::text::char;")"
sub "되돌리기 줄(ALTER TABLE users DROP COLUMN high_contrast) 뒤 다시 적용"
printf '%s\n' '\set ON_ERROR_STOP on' 'ALTER TABLE users DROP COLUMN high_contrast;' > "$WORK/rollback.sql"
psqlf "$WORK/rollback.sql" > /dev/null; RB=$?
DP2=$(psqlq '\dp public.users')
expect "  되돌리기 → 칸 없음 · \\dp 전과 같음" "0 0 $(printf '%s' "$DP0" | sha256sum | cut -c1-16)" \
  "$RB $(psqlq "SELECT count(*) FROM information_schema.columns WHERE table_schema='public' AND table_name='users' AND column_name='high_contrast';") $(printf '%s' "$DP2" | sha256sum | cut -c1-16)"
psqlf "$MIG" > /dev/null; expect "  다시 적용 종료 코드 · 칸 있음" "0 1" "$? $(psqlq "SELECT count(*) FROM information_schema.columns WHERE table_schema='public' AND table_name='users' AND column_name='high_contrast';")"
expect "칸을 넣은 뒤 · 새 코드 로그인한 /api/me → 200 · high_contrast false" "200 False" \
  "$(curl -s -o "$WORK/me0.json" -w '%{http_code}' -A "$UA" -b "$JAR_DIR/claude_employee01.jar" "$BASE/api/me") $(jget "d.get('high_contrast')" < "$WORK/me0.json")"
expect "칸을 넣은 뒤 · 기준 판(옛 코드)도 200(코드만 되돌려도 무해)" "200" "$(curl -s -o /dev/null -w '%{http_code}' -A "$UA" -b "$JAR_DIR/claude_employee01.jar" "$BSITE/tbox/api/me")"

sub "로그인 (claude_ 검토 계정 — 순차, F-71 · claude_employee01 은 위 세션을 그대로 쓴다)"
for who in claude_employee02 claude_manager01 claude_admin01 claude_guest01; do
  login "$who" || { echo "  ✖ $who 로그인 실패 — 중단"; exit 4; }
  echo "  ✔ $who"
done
for who in claude_employee01 claude_employee02 claude_manager01 claude_admin01 claude_guest01; do
  eval "CSRF_${who}='$(csrf_of "$who")'"
done
UID_E1=$(psqlq "SELECT id FROM users WHERE username='claude_employee01';")
UID_E2=$(psqlq "SELECT id FROM users WHERE username='claude_employee02';")

# ════════════════════════════════════════════════════════════════
hr "C:T-210  창구 — PATCH /tbox/api/me/settings (권한 · 형식 · 감사)"
m0=$(mark)
body '{"high_contrast":true}'
expect "미로그인 → 401" "401" "$(curl -s -o /dev/null -w '%{http_code}' -A "$UA" -X PATCH -H 'Content-Type: application/json' --data-binary @"$WORK/body.json" "$BASE/api/me/settings")"
expect "CSRF 머리 없음 → 403" "403" "$(curl -s -o /dev/null -w '%{http_code}' -A "$UA" -b "$JAR_DIR/claude_employee01.jar" -X PATCH -H 'Content-Type: application/json' --data-binary @"$WORK/body.json" "$BASE/api/me/settings")"
expect "틀린 CSRF → 403" "403" "$(curl -s -o /dev/null -w '%{http_code}' -A "$UA" -b "$JAR_DIR/claude_employee01.jar" -X PATCH -H 'X-CSRF-Token: 00' -H 'Content-Type: application/json' --data-binary @"$WORK/body.json" "$BASE/api/me/settings")"
expect "  세 거부 모두 값 그대로(false)" "false" "$(hc_db claude_employee01)"
expect "  감사 csrf deny 2 · me.settings 0" "2 0" "$(audit_n "$m0" csrf deny) $(psqlq "SELECT count(*) FROM audit_logs WHERE id > $m0 AND action = 'me.settings';")"
E2_0=$(hc_db claude_employee02)
m1=$(mark)
expect "{high_contrast:true} → 200" "200" "$(set_hc claude_employee01 true)"
expect "  응답 = {\"high_contrast\":true}" '{"high_contrast":true}' "$(cat "$WORK/b")"
expect "  곧바로 /api/me.high_contrast === true" "True" "$(curl -s -A "$UA" -b "$JAR_DIR/claude_employee01.jar" "$BASE/api/me" | jget "d.get('high_contrast')")"
expect "  표 값 true" "true" "$(hc_db claude_employee01)"
expect "  감사 me.settings allow 1행" "1" "$(audit_n "$m1" me.settings allow)"
expect "  그 행 — 행위자·대상 = 본인 · detail {high_contrast: true} · 문장" "claude_employee01|user|$UID_E1|claude_employee01|true|claude_employee01 이(가) 자기 계정의 화면 설정(고대비 화면)을 바꿨습니다 (켬·끔은 상세 기록)" \
  "$(psqlq "SELECT actor_name || '|' || target_type || '|' || target_id || '|' || target_name || '|' || (detail->>'high_contrast') || '|' || summary FROM audit_logs WHERE id > $m1 AND action = 'me.settings' ORDER BY id DESC LIMIT 1;")"
expect "  다른 계정(claude_employee02) 값 그대로(표 직접 대조)" "$E2_0" "$(hc_db claude_employee02)"
sub "형식 — 400 · 값 그대로 · 감사 0"
m2=$(mark)
bad=0
while IFS='|' read -r label payload ctype; do
  [ -n "$label" ] || continue
  printf '%s' "$payload" > "$WORK/bad.json"
  st=$(tapi PATCH /api/me/settings claude_employee01 "$WORK/bad.json" "${ctype:-application/json}")
  if [ "$st" = "400" ]; then printf '  ✔ %s → 400\n' "$label"; PASS=$((PASS+1)); else printf '  ✖ %s → %s (기대 400)\n' "$label" "$st"; FAIL=$((FAIL+1)); bad=$((bad+1)); fi
done <<'CASES'
문자열 "true"|{"high_contrast":"true"}|
숫자 1|{"high_contrast":1}|
null|{"high_contrast":null}|
다른 키 하나|{"contrast":true}|
빈 본문 {}|{}|
빈 글자|""|
본문 0바이트||
둘 이상 키(모르는 키 곁들임)|{"high_contrast":true,"theme":"dark"}|
대상 계정 칸을 곁들임|{"high_contrast":false,"username":"claude_employee02"}|
대상 id 칸을 곁들임|{"high_contrast":false,"user_id":2}|
배열 [true]|[true]|
JSON 이 아닌 본문|high_contrast=true|
JSON 머리가 아님(form)|high_contrast=true|application/x-www-form-urlencoded
CASES
expect "  400 뒤에도 값 그대로(true) · 다른 계정 그대로 · 감사 me.settings 0" "true $E2_0 0" \
  "$(hc_db claude_employee01) $(hc_db claude_employee02) $(psqlq "SELECT count(*) FROM audit_logs WHERE id > $m2 AND action = 'me.settings';")"
m3=$(mark)
expect "false 로 되돌리기 → 200 · 응답 false · 표 false" "200 {\"high_contrast\":false} false" "$(set_hc claude_employee01 false) $(cat "$WORK/b") $(hc_db claude_employee01)"
expect "  감사 me.settings allow 1 · detail false" "1 false" "$(audit_n "$m3" me.settings allow) $(psqlq "SELECT detail->>'high_contrast' FROM audit_logs WHERE id > $m3 AND action = 'me.settings' ORDER BY id DESC LIMIT 1;")"
expect "GET 은 없다(메서드만 다름 → 405)" "405" "$(tapi GET /api/me/settings claude_employee01)"
sub "개인 프로그램 토큰 — 토큰 창구 집합 밖(세션으로만)"
TOK=$(python3 -c 'import secrets; print(secrets.token_urlsafe(36))')
TOKSQL=$(printf '%s' "$TOK" | python3 -c "import hashlib,sys; t=sys.stdin.read(); print(\"INSERT INTO api_tokens (user_id, name, token_hash, hint, created_by, scope, expires_at) VALUES ($UID_E1, 'C7D3 시험 토큰', '%s', '%s', $UID_E1, 'read', now() + interval '1 day') RETURNING id;\" % (hashlib.sha256(t.encode()).hexdigest(), t[:6]))")
TOK_ID=$(psqlq "$TOKSQL"); TOKSQL=''
note "임시 DB 에만 claude_employee01 의 개인 토큰 한 줄(id ${TOK_ID:-?} · 범위 read · 값은 출력하지 않는다)"
expect "같은 토큰으로 GET /tbox/api/whoami → 200(토큰은 살아 있다)" "200" "$(curl_hdr_secret X-API-Token TOK -s -o /dev/null -w '%{http_code}' -A "$UA" "$BASE/api/whoami")"
body '{"high_contrast":true}'
expect "토큰으로 PATCH /tbox/api/me/settings → 401(세션이 없다 — 토큰 헤더를 읽지도 않는다)" "401 {\"error\":\"로그인이 필요합니다\"}" \
  "$(curl_hdr_secret X-API-Token TOK -s -o "$WORK/tb" -w '%{http_code}' -A "$UA" -X PATCH -H 'Content-Type: application/json' --data-binary @"$WORK/body.json" "$BASE/api/me/settings") $(cat "$WORK/tb")"
expect "  값 그대로(false)" "false" "$(hc_db claude_employee01)"
TOK=''
sub "「역할로 보기」 중에도 실제 사람의 칸(H-8 — 따로 코드 0)"
expect "관리자 재인증 200" "200" "$(reauth_admin)"
expect "관리자 본인 켬 200" "200" "$(set_hc claude_admin01 true)"
body '{"role":"Employee"}'
expect "관리자 역할로 보기(직원) 시작 200" "200" "$(tapi POST /api/me/act claude_admin01 "$WORK/body.json")"
html_of "$BASE/" claude_admin01 > /dev/null
expect "  보는 중 /tbox/ — 띠 있음 · data-contrast 한 번(실제 사람의 칸)" "yes 1" "$(grep -q '역할로 보기' "$WORK/html" && echo yes || echo no) $(attr_n)"
expect "  보는 중 /api/me.high_contrast === true · 역할 Employee" "True Employee" "$(curl -s -A "$UA" -b "$JAR_DIR/claude_admin01.jar" "$BASE/api/me" | jget "str(d.get('high_contrast')) + ' ' + str(d.get('role'))")"
expect "역할로 보기 끝 200" "200" "$(tapi DELETE /api/me/act claude_admin01)"
expect "관리자 본인 끔 200 · 표 false" "200 false" "$(set_hc claude_admin01 false) $(hc_db claude_admin01)"

# ════════════════════════════════════════════════════════════════
hr "C:T-211  대비 — tests/lib/contrast.py (WCAG 2.1 상대 휘도 · 밝게·어둡게)"
python3 "$ROOT/tests/lib/contrast.py" "$ROOT/public/assets/app.css" "$ROOT/public/assets/community/community.css" > "$WORK/contrast.txt" 2>&1
CRC=$?
sed 's/^/    /' "$WORK/contrast.txt"
expect "contrast.py 종료 코드 0 · CONTRAST_FAIL=0" "0 0" "$CRC $(sed -n 's/^CONTRAST_FAIL=//p' "$WORK/contrast.txt")"
NPAIR=$(grep -cE ':1  \(≥' "$WORK/contrast.txt")
expect "  쌍을 빠짐없이 쟀다(방식 둘 × (글자 16 + 의미색 12 + 주요 1 + 문서 27 + 선·고리 2) = 116)" "116" "$NPAIR"
sub "헛돌지 않는지 — 틀린 사본은 실패해야 한다"
mut() {   # $1 이름 $2 파이썬 치환 식(app.css 글자 s 를 바꿔 돌려준다) $3 대상 파일(app|com)
  mkdir -p "$WORK/mut"
  cp "$ROOT/public/assets/app.css" "$WORK/mut/app.css"; cp "$ROOT/public/assets/community/community.css" "$WORK/mut/com.css"
  python3 - "$WORK/mut/$3.css" "$2" <<'PY'
import sys
p, expr = sys.argv[1], sys.argv[2]
s = open(p, encoding="utf-8").read()
t = eval(expr)
if t == s:
    sys.exit("치환이 아무것도 바꾸지 않았다")
open(p, "w", encoding="utf-8").write(t)
PY
  python3 "$ROOT/tests/lib/contrast.py" "$WORK/mut/app.css" "$WORK/mut/com.css" > "$WORK/mut/out.txt" 2>&1
  local n; n=$(sed -n 's/^CONTRAST_FAIL=//p' "$WORK/mut/out.txt")
  if [ "${n:-0}" -gt 0 ] 2>/dev/null; then printf '  ✔ %s → 실패 %s\n' "$1" "$n"; PASS=$((PASS+1)); else printf '  ✖ %s → 실패로 잡지 못함(%s)\n' "$1" "${n:-?}"; FAIL=$((FAIL+1)); fi
}
mut "밝은 고대비 --muted 를 옅게(#a1a1aa)" "s.replace(':root[data-contrast=\"high\"] {\n  --fg: #000000;\n  --fg-2: #18181b;\n  --muted: #3f3f46;', ':root[data-contrast=\"high\"] {\n  --fg: #000000;\n  --fg-2: #18181b;\n  --muted: #a1a1aa;', 1)" app
mut "어두운 고대비(명시) 블록에서 --accent-dark 빼기(밝은 값이 샌다)" "s.replace('  --accent: #93c5fd;\n  --accent-dark: #bfdbfe;\n  --danger: #fca5a5;\n  --warn-fg: #fcd34d;\n  --ok-fg: #86efac;\n  --violet-fg: #c4b5fd;\n}', '  --accent: #93c5fd;\n  --danger: #fca5a5;\n  --warn-fg: #fcd34d;\n  --ok-fg: #86efac;\n  --violet-fg: #c4b5fd;\n}', 1)" app
mut "어두운 두 블록 값이 다름(OS 쪽 --line 만 바꿈)" "s.replace('    --line: #a1a1aa;', '    --line: #a1a1ab;', 1)" app
mut "화면 CSS 에 [data-contrast] 갈래 한 줄" "s + '\n:root[data-contrast=\"high\"] .btn { border-width: 2px; }\n'" app
mut "고대비 블록에 새 토큰 이름" "s.replace(':root[data-contrast=\"high\"] {\n  --fg: #000000;', ':root[data-contrast=\"high\"] {\n  --hc-extra: #000000;\n  --fg: #000000;', 1)" app
mut "문서 빨강을 흐리게(밝게 #e07a74)" "s.replace(':root[data-contrast=\"high\"] {\n  --nt-gray:   #4a4a47;\n  --nt-brown:  #6b4633;\n  --nt-orange: #9a4a0a;\n  --nt-yellow: #7a5a12;\n  --nt-green:  #2f5a3f;\n  --nt-blue:   #1f4f7a;\n  --nt-purple: #5b3d85;\n  --nt-pink:   #8a2e5e;\n  --nt-red:    #9a2f2a;', ':root[data-contrast=\"high\"] {\n  --nt-gray:   #4a4a47;\n  --nt-brown:  #6b4633;\n  --nt-orange: #9a4a0a;\n  --nt-yellow: #7a5a12;\n  --nt-green:  #2f5a3f;\n  --nt-blue:   #1f4f7a;\n  --nt-purple: #5b3d85;\n  --nt-pink:   #8a2e5e;\n  --nt-red:    #e07a74;', 1)" com

# ════════════════════════════════════════════════════════════════
hr "C:T-212  속성 — 세 곳(t-box · 런처 · 커뮤니티) + 재인증 화면 · 로그인 화면은 없음"
SCREENS="/ /tbox/ /community/ /tbox/reauth"
: > "$WORK/html_lines.txt"
sub "끔(claude_employee01 — 표 값 $(hc_db claude_employee01))"
for p in $SCREENS; do
  st=$(html_of "$SITE$p" claude_employee01)
  expect "끔 $p → 200 · data-contrast 0" "200 0" "$st $(anyattr_n)"
  printf '끔  %-14s %s\n' "$p" "$(html_line)" >> "$WORK/html_lines.txt"
done
sub "켬"
expect "claude_employee01 켬 200" "200" "$(set_hc claude_employee01 true)"
for p in $SCREENS; do
  st=$(html_of "$SITE$p" claude_employee01)
  expect "켬 $p → 200 · data-contrast=\"high\" 정확히 한 번 · <html> 줄에" "200 1 1" "$st $(attr_n) $(html_line | grep -c 'data-contrast="high"')"
  printf '켬  %-14s %s\n' "$p" "$(html_line)" >> "$WORK/html_lines.txt"
done
for p in $SCREENS; do
  st=$(html_of "$SITE$p" claude_employee01 "tbox_theme=dark")
  expect "켬 + tbox_theme=dark $p → 둘 다(data-theme=\"dark\" · data-contrast=\"high\")" "200 1 1" "$st $(html_line | grep -c 'data-theme="dark"') $(attr_n)"
  printf '켬+다크 %-10s %s\n' "$p" "$(html_line)" >> "$WORK/html_lines.txt"
done
st=$(html_of "$SITE/tbox/login"); expect "로그인 화면(로그인 전) → 200 · data-contrast 0" "200 0" "$st $(anyattr_n)"
printf '로그인 전 %-10s %s\n' "/tbox/login" "$(html_line)" >> "$WORK/html_lines.txt"
st=$(html_of "$SITE/tbox/login?to=community"); expect "로그인 화면(커뮤니티로) → 200 · data-contrast 0" "200 0" "$st $(anyattr_n)"
st=$(html_of "$SITE/tbox/login" claude_employee01); expect "켠 사람이 로그인 화면 → 로그인 화면을 그리지 않는다(302) · 본문 data-contrast 0" "302 0" "$st $(anyattr_n)"
st=$(html_of "$SITE/"); expect "미로그인 런처 → 200 · data-contrast 0" "200 0" "$st $(anyattr_n)"
printf '로그인 전 %-10s %s\n' "/" "$(html_line)" >> "$WORK/html_lines.txt"
for p in $SCREENS; do
  st=$(html_of "$SITE$p" claude_employee02)
  expect "다른 계정(claude_employee02 · 끔) $p → data-contrast 0" "200 0" "$st $(anyattr_n)"
done
st=$(html_of "$SITE/tbox/" claude_employee01 'tbox_theme="><script>x</script>')
expect "켬 + 허용 목록 밖 테마 쿠키 → data-theme 없음 · data-contrast 그대로 한 번 · 스크립트 글자 0" "0 1 0" \
  "$(html_line | grep -c 'data-theme') $(attr_n) $(grep -c '<script>x' "$WORK/html")"
sed 's/^/    /' "$WORK/html_lines.txt"
expect "끄기 200" "200" "$(set_hc claude_employee01 false)"

# ════════════════════════════════════════════════════════════════
hr "C:T-213 · C:T-214 준비 — 문서(nt-red) 페이지 · 게시판 · 내 활동 거부 행(dbg_s_screens) · 모두 임시 DB"
body '{"title":"C7D3-고대비 문서"}'
capi POST /api/pages claude_employee01 "$WORK/body.json" > /dev/null
PG=$(jget "d['page']['id']" < "$WORK/b")
V=$(psqlq "SELECT doc_version FROM community.pages WHERE public_id = '${PG:-00000000-0000-0000-0000-000000000000}';")
python3 - "${V:-1}" > "$WORK/doc.json" <<'PY'
import json, sys, uuid
def u(): return str(uuid.uuid4())
blocks = [
    {"id": u(), "type": "paragraph", "text": [{"t": "고대비 빨강 글자", "m": {"color": "red"}}, {"t": " · 보통 글자 · ", "m": {}}, {"t": "노랑 배경", "m": {"color": "yellow_bg"}}]},
    {"id": u(), "type": "paragraph", "color": "blue_bg", "text": [{"t": "파랑 배경 문단 — 글자와 배경의 차이", "m": {}}]},
    {"id": u(), "type": "paragraph", "text": [{"t": "회색", "m": {"color": "gray"}}, {"t": " · ", "m": {}}, {"t": "초록", "m": {"color": "green"}}, {"t": " · ", "m": {}}, {"t": "보라", "m": {"color": "purple"}}]},
]
print(json.dumps({"doc": {"schema": 1, "blocks": blocks}, "doc_version": int(sys.argv[1])}, ensure_ascii=False))
PY
expect "문서 페이지 만들고 본문 저장(빨강 · 노랑 배경 · 파랑 배경 문단)" "200" "$(capi PATCH "/api/pages/$PG" claude_employee01 "$WORK/doc.json")"
BOARD=$(psqlq "SELECT id FROM community.spaces WHERE kind='board' AND name='자유게시판' AND is_active ORDER BY id LIMIT 1;")
note "페이지 $PG · 게시판(자유게시판) ${BOARD:-없음}"
# dbg_s_screens.py 가 보는 「내 활동」 거부 행 둘 — 관리자 파일 받기 거부(가림) · 내가 올리다 막힌 행(가림 없음)
upload() {   # $1 계정 $2 파일 이름 $3 등급 → 새 파일 id(201 이 아니면 빈 글자) · 상태 코드는 $WORK/up.code
  local csrf; eval "csrf=\${CSRF_$1:-}"
  printf 'C7D3 %s\n' "$2" > "$WORK/up.txt"
  curl -s -o "$WORK/up.json" -w '%{http_code}' -A "$UA" -b "$JAR_DIR/$1.jar" -H "X-CSRF-Token: $csrf" -H 'Expect:' \
    -F "access_level=$3" -F "description=7-D3 시험" -F "file=@$WORK/up.txt;filename=$2;type=text/plain" "$BASE/api/files" > "$WORK/up.code"
  [ "$(cat "$WORK/up.code")" = "201" ] && jget "d.get('id','')" < "$WORK/up.json"
}
ADMF=$(upload claude_admin01 "C7D3-관리자파일.txt" admin-only)
expect "관리자 admin-only 파일 올림 201" "201" "$(cat "$WORK/up.code")"
psqlq "UPDATE files SET scan_status = 'clean' WHERE public_id = '${ADMF:-none}';" > /dev/null
expect "직원이 관리자 파일 받기 → 403(내 활동 거부 행 — 가림)" "403" "$(curl -s -o /dev/null -w '%{http_code}' -A "$UA" -b "$JAR_DIR/claude_employee01.jar" "$BASE/api/files/$ADMF/download")"
upload claude_employee01 "bad.php.C7D3.txt" internal > /dev/null
expect "직원이 막힌 이름 올리기 → 400(내 활동 거부 행 — 이름 그대로)" "400" "$(cat "$WORK/up.code")"

# ════════════════════════════════════════════════════════════════
hr "C:T-213  브라우저 — tests/browser/hc_screens.py (+ C:T-214 토큰 계산값 = 기준 판)"
S_E1=$(session_of claude_employee01)
if [ "${STAGE7D3_SKIP_BROWSER:-0}" = "1" ]; then
  skip "화면 시험" "STAGE7D3_SKIP_BROWSER=1 — 통과로 세지 않는다"
elif ! python3 -c 'import playwright' 2>/dev/null; then
  skip "화면 시험" "playwright 가 없다"
else
  mkdir -p "$SHOTS"
  { py_secret S_E1 -- browser_run env PATH="$HOME/.local/bin:$PATH" HC_PAGE="$PG" HC_BOARD="${BOARD:-}" HC_BASE_SITE="$BSITE" HC_COMBOS="${HC_COMBOS:-}" \
      python3 -u "$ROOT/tests/browser/hc_screens.py" "$SITE" "$UA" "$SHOTS" "$ROOT/public/assets/app.css" "$ROOT/public/assets/community/community.css" 2>&1
    echo "RC=$?"; } > "$WORK/hc.out"
  sed 's/^/    /' "$WORK/hc.out"
  expect "hc_screens.py 종료 코드 · SMOKE_FAIL" "0 0" "$(sed -n 's/^RC=\([0-9]*\)$/\1/p' "$WORK/hc.out" | tail -1) $(sed -n 's/^SMOKE_FAIL=\([0-9]*\)$/\1/p' "$WORK/hc.out" | tail -1)"
  HP=$(grep -c '^  ✔' "$WORK/hc.out")
  [ "$HP" -ge 40 ] && expect "화면 시험이 헛돌지 않았다(✔ 40 이상)" "yes" "yes" || expect "화면 시험이 헛돌지 않았다(✔ 40 이상)" "yes" "no($HP)"
  note "스크린샷: $SHOTS"
fi

# ════════════════════════════════════════════════════════════════
hr "C:T-214  끈 상태 — 변화 0 (ui_snapshot 결함 수 = 기준 판 · dbg_s_screens.py)"
expect "끈 상태에서 시작(claude_admin01 · claude_employee01 표 값)" "false false" "$(hc_db claude_admin01) $(hc_db claude_employee01)"
if [ "${STAGE7D3_SKIP_BROWSER:-0}" = "1" ] || ! python3 -c 'import playwright' 2>/dev/null; then
  skip "ui_snapshot · dbg_s_screens" "브라우저 없음 또는 STAGE7D3_SKIP_BROWSER=1"
else
  expect "관리자 재인증 200(관리 화면을 찍으려고 — 한 세션을 두 인스턴스가 같은 DB 로 본다)" "200" "$(reauth_admin)"
  COOK="__Host-tbox_session=$(session_of claude_admin01);"
  snap() {   # $1 이름 $2 t-box 주소 $3 폭 → 합계 건수
    mkdir -p "$SHOTS/snap-$1-$3"
    { py_secret COOK -- browser_run env PATH="$HOME/.local/bin:$PATH" python3 "$INST/app/scripts/ui_snapshot.py" "$2" "$UA" "$SHOTS/snap-$1-$3" "$3" 2>&1; echo "RC=$?"; } > "$WORK/snap-$1-$3.txt"
    sed -n 's/^합계 \([0-9]*\)건$/\1/p' "$WORK/snap-$1-$3.txt" | tail -1
  }
  for w in 1440 1280; do
    NB=$(snap base "$BSITE/tbox" "$w"); NN=$(snap new "$BASE" "$w")
    sed 's/^/    [기준 판] /' "$WORK/snap-base-$w.txt" | grep -v '^\s*\[기준 판\] RC='
    sed 's/^/    [새 판]  /' "$WORK/snap-new-$w.txt" | grep -v '^\s*\[새 판\]  RC='
    expect "ui_snapshot $w — 결함 수 기준 판 = 새 판(끔)" "${NB:-?}" "${NN:-?}"
  done
  expect "관리자 켬 200(켬에서도 같은 수인지 — 1280)" "200" "$(set_hc claude_admin01 true)"
  NH=$(snap new-hc "$BASE" 1280)
  sed 's/^/    [새 판·켬] /' "$WORK/snap-new-hc-1280.txt" | grep -v 'RC='
  expect "ui_snapshot 1280 — 켬에서도 결함 수 같음(색만 바뀐다)" "${NN:-?}" "${NH:-?}"
  expect "관리자 끔 200" "200" "$(set_hc claude_admin01 false)"
  COOK=''
  { py_secret S_E1 -- browser_run env PATH="$HOME/.local/bin:$PATH" python3 -u "$ROOT/tests/browser/dbg_s_screens.py" "$SITE" "$UA" "$SHOTS/dbg-s" 2>&1; echo "RC=$?"; } > "$WORK/dbgs.out"
  sed 's/^/    /' "$WORK/dbgs.out"
  expect "dbg_s_screens.py(끈 상태) 종료 코드 · SMOKE_FAIL" "0 0" "$(sed -n 's/^RC=\([0-9]*\)$/\1/p' "$WORK/dbgs.out" | tail -1) $(sed -n 's/^SMOKE_FAIL=\([0-9]*\)$/\1/p' "$WORK/dbgs.out" | tail -1)"
fi
S_E1=''

# ════════════════════════════════════════════════════════════════
hr "서버 로그 — 새 판(순서 어긋남을 일부러 만든 앞부분 뒤) · 기준 판"
expect "새 판 서버 로그의 PHP 오류·잡히지 않은 예외(앞 $LOG_OFF 줄 뒤)" "0" "$(tail -n +"$((LOG_OFF + 1))" "$INST/server.log" | grep -cE 'PHP (Fatal|Warning|Notice|Deprecated|Parse)|Uncaught ')"
expect "기준 판 서버 로그의 PHP 오류" "0" "$(server_errs "$INST/bserver.log")"

# ════════════════════════════════════════════════════════════════
if [ "${REGRESS:-0}" = "1" ]; then
  hr "회귀 — 같은 임시 인스턴스(새 판 $PORT · $DB)에서 사본으로"
  note "각 스크립트를 임시 인스턴스 폴더로 복사해 SITE·DB 만 바꿔 돈다(운영 8082·tbox_re 에 닿지 않는다). writes_prod·manual_only 는 넣지 않는다."
  sed -i "s#http://127.0.0.1:8082#$SITE#g" "$INST/app/scripts/ui_snapshot.sh" 2>/dev/null
  if grep -v '^\s*#' "$INST/app/scripts/ui_snapshot.sh" | grep -q '8082'; then
    echo "  ✖ ui_snapshot.sh 에 운영 주소가 남았다 — 회귀를 돌리지 않는다"; FAIL=$((FAIL+1)); REGRESS_LIST=" "
  fi
  for s in ${REGRESS_LIST:-community/stage3 help_button api_whoami api_read api_folders q_launcher f68}; do
    case "$s" in stage26|f66|f65|fetch_hosts|f51|f52|f54|f46) echo "  ✖ $s — 운영에 쓰는·사람만 도는 시험은 넣지 않는다"; FAIL=$((FAIL+1)); continue ;; esac
    src="$INST/app/tests/$s.sh"
    dst="$INST/app/tests/$s.temp.sh"
    [ -f "$src" ] || { echo "  ✖ $s — 스크립트가 없다"; FAIL=$((FAIL+1)); continue; }
    sed -e "s#http://127.0.0.1:8082#$SITE#g" -e "s#^DB=\"tbox_re\"#DB=\"$DB\"#" -e "s#-d tbox_re #-d $DB #g" "$src" > "$dst"
    if grep -v '^\s*#' "$dst" | grep -q '8082\|DB="tbox_re"\|-d tbox_re'; then
      echo "  ✖ $s — 운영 주소·DB 가 남아 있어 돌리지 않는다: $(grep -n '8082\|tbox_re' "$dst" | grep -v '^\s*[0-9]*:\s*#' | head -2 | tr '\n' ' ')"; FAIL=$((FAIL+1)); continue
    fi
    sleep $((31 - $(date +%s) % 30))
    echo "  ▸ $s (시작 $(date +%T))"
    mem_ok || note "메모리 여유 부족 — 그래도 잠금 아래에서 돈다"
    "${LOCKCMD[@]}" bash "$dst" > "$WORK/regress_$(basename "$s").txt" 2>&1
    rc=$?
    line=$(grep -E '통과 [0-9]+|PASS|통과:|결과' "$WORK/regress_$(basename "$s").txt" | tail -1)
    echo "    종료 코드 $rc · ${line:-결과 줄 없음} (끝 $(date +%T))"
    grep -E '^\s*(✖|FAIL)' "$WORK/regress_$(basename "$s").txt" | head -12 | sed 's/^/      /'
    [ -n "${REGRESS_KEEP:-}" ] && cp "$WORK/regress_$(basename "$s").txt" "$REGRESS_KEEP/"
    [ "$rc" = "0" ] && PASS=$((PASS+1)) || FAIL=$((FAIL+1))
  done
fi

# ════════════════════════════════════════════════════════════════
hr "운영 대조 (읽기만)"
END=$(prodq "SELECT coalesce(max(id),0) || '|' || (SELECT count(*) FROM audit_logs WHERE id > ${PROD_A0:-0} AND user_agent = '$UA' AND actor_name LIKE 'claude\\_%' AND host(ip) = '127.0.0.1') FROM audit_logs;")
OTHER=$(prodq "SELECT string_agg(id || ' ' || coalesce(actor_name,'-') || ' ' || action || ' ' || coalesce(host(ip),'-'), ' ; ') FROM audit_logs WHERE id > ${PROD_A0:-0} AND user_agent = '$UA';")
[ -n "$OTHER" ] && note "같은 UA 의 운영 감사 행(참고 — IP 로 가름): $OTHER"
PROD_A1=${END%%|*}; UA_ROWS=${END##*|}
ALERTS_L1=$(sudo -n wc -l < /var/log/tbox-v2/alerts.log 2>/dev/null || echo '?')
TOTP_M1=$(sudo -n stat -c %y /etc/tbox-v2/qa-totp.txt 2>/dev/null || echo '?')
note "운영 감사 max(id) 끝 = ${PROD_A1:-?} (시작 ${PROD_A0:-?}) · 경보 로그 줄 수 끝 = $ALERTS_L1 (시작 $ALERTS_L0 — 이 시험의 sudo psql·pg_dump 가 운영 cron 의 AL-9 에 걸릴 수 있다) · 비밀 파일 시각 끝 = $TOTP_M1"
expect "검토 계정 비밀 파일 시각 그대로(이 시험은 /etc 에 쓰지 않는다)" "$TOTP_M0" "$TOTP_M1"
expect "운영 감사에 이 시험의 UA($UA) · 로컬(127.0.0.1) 행이 늘지 않았다(시작 max 뒤)" "0" "${UA_ROWS:-?}"

[ "$SKIP" -gt 0 ] && printf '  · 건너뜀 %d (위 「○ 건너뜀」 줄 — 통과로 세지 않음)\n' "$SKIP"
note "임시 DB($DB)·임시 폴더·임시 서버 둘은 이 스크립트가 끝나며 지워진다. 운영에는 아무것도 남지 않는다."
printf '\n통과 %d · 실패 %d\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ]
