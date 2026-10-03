#!/usr/bin/env bash
# tests/ux_r1_ui.sh — 사용감 라운드 1 「빠른 승리」 수락 시험 Q:T-11 ~ Q:T-21
#                     (사용자 2026-09-28 10:45 「사용감을 최적화하라」 · 실측 보고서 ux-audit-2026-09-28 · 구현 기록 docs/ux-r1-implementation.md)
#
#   UXR1-S 정적   바뀐 PHP php -l · 보안 린트 0 · 커뮤니티 JS import 버전(--check) · 더한 줄의 인라인 style·script·on* 0 · 문자열 → HTML 자리 0 ·
#                 바뀐 파일은 화면(뷰 셋 · 자산)뿐 — 창구·권한·저장·DB·배포·라우트 차이 0 · 판정·보안 핵심 파일 차이 0 ·
#                 세션 확인 도우미는 한 곳(app.js probeSession) · 일괄 작업은 그 길을 끔(sessionRetry:false 다섯) ·
#                 자동 제출은 로그인 화면 한 곳(requestSubmit 하나) · 기본 제목 표 = 서버 글자 · DESIGN 행
#   UXR1-H 서버   셸·화면 HTML — 윗줄 앱 이름 단추 · 검색 · (커뮤니티만) 알림 종 · 제목 칸 자리표시 · 더보기의 사용 방법·이력(hidden) · 「최근」 판(hidden) ·
#                 서버 기본 제목 그대로(제목 없이 만든 페이지 = 「제목 없는 페이지」)
#   UXR1-B 화면   tests/browser/ux_r1_ui.py — 360 · 412(폰) · 820(터치) · 1280 × 라이트 · 다크 + 흐름 시험(항목은 그 파일 머리글)
#   UXR1-L 서버 로그  임시 인스턴스 로그에 새 PHP 오류 0
#   회귀(선택)     REGRESS=1 이면 같은 임시 인스턴스에서 REGRESS_LIST(기본 help_button q_launcher)를 사본으로(8082 → 이 인스턴스 · tbox_re → 임시 DB)
#
# ★ 운영 DB·운영 포트를 건드리지 않는다 — 임시 DB `tbox_uxr1_<포트>_test`(tbox_testdb 역할로 만들고 지움 · 운영 tbox_re 는 pg_dump 로 **읽어** 붓기만) ·
#   임시 인스턴스 `php -S 127.0.0.1:<포트>`(기본 8393 — 이 라운드는 8391~8395 만 쓴다) · 검토 계정 claude_ 다섯(F-71) ·
#   비밀은 표준입력으로만(R-62 · tests/lib/secrets.sh). 운영 배치 스크립트는 부르지 않는다.
#   임시 .env — UXR1_ENV_SRC(0600 사본)가 없으면 운영 .env 를 mktemp 폴더(0700)에 0600 으로 복사해 쓰고 끝에 지운다.
#   이 인스턴스의 .env 는 DB_NAME·STORAGE_DIR·AUDIT_LOG_FILE 을 임시로, SCAN_ENABLED=0. 띄우기 전에 DB_NAME 이 tbox_re 가 아닌지 본다.
#   운영 감사 max(id)·경보 로그 줄 수·검토 계정 비밀 파일 시각은 시작·끝에 **읽기만** 해 적고, 운영 감사에 이 시험의 UA·로컬 행이 늘지 않았는지 본다.
#   sudo psql 은 SQL 을 **표준입력**으로만(명령 줄이 한 모양 — 운영 경보 AL-9).
# ★ 서버 메모리(취합 규칙): 헤드리스 브라우저는 공용 잠금(QAREDI_BROWSER_LOCK — 있으면 flock) 아래 한 번에 하나, 여유 700MB 이상일 때만.
# 쓰는 법: bash tests/ux_r1_ui.sh   환경 UXR1_PORT(8391~8395) · UXR1_SHOTS · UXR1_BASE_REV(기본 eadabd8) · UXR1_APP_TREE · UXR1_BEFORE=1 · UXR1_COMBOS · REGRESS=1
#   UXR1_TPL_DB=tbox_uxr1_<이름> — 운영 pg_dump 한 번으로 미리 만든 틀 DB(tbox_testdb 소유)를 복제해 쓴다(이 시험 안의 pg_dump·sudo 0 · 여러 번 돌릴 때)
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
. "$ROOT/tests/lib/review_accounts.sh" || { echo "tests/lib/review_accounts.sh 를 읽지 못했다 — 시험을 시작하지 않는다"; exit 3; }
. "$ROOT/tests/lib/secrets.sh" || { echo "tests/lib/secrets.sh 를 읽지 못했다 — 시험을 시작하지 않는다 (R-62)"; exit 3; }
PORT="${UXR1_PORT:-8393}"
case "$PORT" in 839[1-5]) ;; *) echo "이 라운드는 포트 8391~8395 만 쓴다(운영 80·8082·8081 금지) — $PORT"; exit 3 ;; esac
SITE="http://127.0.0.1:$PORT"
BASE="$SITE/tbox"
UA="tbox-review/$(date +%F)"
SRC_DB="tbox_re"                     # 운영 — **읽기만** 한다(pg_dump · max(id))
DB="tbox_uxr1_${PORT}_test"          # 임시 — 여기에만 쓴다
BASE_REV="${UXR1_BASE_REV:-eadabd8}"  # 이 라운드의 기준 판(운영 v2.35.0) — 일부러 고정(지시서: 기준 판 eadabd8 · 운영이 위키 커밋으로 앞서도 옮기기는 메인)
APP_TREE="${UXR1_APP_TREE:-$ROOT}"
BEFORE="${UXR1_BEFORE:-0}"
PGP_TEST="$HOME/.config/qaredi/pgpass-testdb"
JAR_DIR="$(mktemp -d)"
WORK="$(mktemp -d)"
chmod 700 "$JAR_DIR" "$WORK"
SHOTS="${UXR1_SHOTS:-$WORK/shots}"
INST=""
SPID=""
ENV_TMP=""
PASS=0
FAIL=0

hr()  { printf '\n════════════════════════════════════════════════════════════\n%s\n════════════════════════════════════════════════════════════\n' "$1"; }
expect() {
  if [ "$2" = "$3" ]; then printf '  ✔ %s  (기대=%s 실제=%s)\n' "$1" "$2" "$3"; PASS=$((PASS+1))
  else printf '  ✖ %s  (기대=%s 실제=%s)\n' "$1" "$2" "$3"; FAIL=$((FAIL+1)); fi
}
note() { printf '  · %s\n' "$1"; }

tdb() { env -u PGHOST -u PGPORT -u PGUSER -u PGDATABASE -u PGPASSWORD -u PGSERVICE -u PGOPTIONS PGPASSFILE="$PGP_TEST" "$@"; }
psqlq() { printf '%s\n' "$1" | tdb psql -h 127.0.0.1 -p 5432 -U tbox_testdb -w -X -q -At -d "$DB" 2>/dev/null; }
PGP_READ="$HOME/.config/qaredi/pgpass-testread"
# 운영 — max(id) 하나만 읽는다(읽기 전용 역할 tbox_testread · sudo 0 → 운영 경보 AL-9 0). 행 내용은 이 역할로 읽을 수 없다(권한 없음 — 맞는 설계)
prodmax() { printf '%s\n' "SELECT coalesce(max(id),0) FROM audit_logs;" | env -u PGHOST -u PGPORT -u PGUSER -u PGDATABASE -u PGPASSWORD -u PGSERVICE -u PGOPTIONS PGPASSFILE="$PGP_READ" psql -h 127.0.0.1 -U tbox_testread -w -X -q -At -d "$SRC_DB" 2>/dev/null; }

cleanup() {
  [ -n "$SPID" ] && { kill "$SPID" 2>/dev/null; wait "$SPID" 2>/dev/null; }
  tdb dropdb -h 127.0.0.1 -p 5432 -U tbox_testdb -w --if-exists "$DB" >/dev/null 2>&1
  [ -n "$INST" ] && rm -rf "$INST"
  [ -n "$ENV_TMP" ] && rm -rf "$ENV_TMP"
  rm -rf "$JAR_DIR" "$WORK"
}
trap cleanup EXIT

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
    case "$out" in *'"ok":true'*) body=''; pw=''; sec=''; return 0 ;; esac
    left=$(printf '%s' "$raw" | sed -n 's/^남은 시간: \([0-9]*\)초.*/\1/p')
    [ -n "$left" ] || left=31
    printf '  · %s 로그인 재시도 — 다음 코드 창까지 %s초\n' "$who" "$left" >&2
    sleep $((left + 1))
  done
  body=''; pw=''; sec=''
  return 1
}
session_of() { awk '$6=="__Host-tbox_session"{print $7}' "$JAR_DIR/$1.jar" 2>/dev/null | tail -1; }

make_env() {   # $1 원본 $2 대상 $3 저장 폴더 $4 감사 파일. 값은 출력하지 않는다.
  # qcheck-allow: R20 — 임시 인스턴스용 사본(0700 폴더 · 0600 · trap 정리) · kill -9 뒤 남는 창은 받아들인 위험(HO-B1-004)
  sed -e "s#^DB_NAME=.*#DB_NAME=$DB#" \
      -e "s#^STORAGE_DIR=.*#STORAGE_DIR=$3#" \
      -e "s#^AUDIT_LOG_FILE=.*#AUDIT_LOG_FILE=$4#" \
      -e "s#^SCAN_ENABLED=.*#SCAN_ENABLED=0#" \
      "$1" > "$2"
  chmod 600 "$2"
  [ "$(grep -c '^DB_NAME=' "$2")" = "1" ] && grep -q "^DB_NAME=$DB\$" "$2" && ! grep -q '^DB_NAME=tbox_re$' "$2" \
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
hr "사용감 라운드 1 — 임시 인스턴스 준비 (운영 DB 에는 쓰지 않는다)"
if ss -ltn 2>/dev/null | grep -q "127.0.0.1:$PORT "; then echo "포트 $PORT 이 이미 쓰이고 있다 — 중단"; exit 3; fi
[ -r "$PGP_TEST" ] || { echo "임시 DB 역할(tbox_testdb)의 pgpass 가 없다 — $PGP_TEST"; exit 3; }
ENV_SRC="${UXR1_ENV_SRC:-}"
if [ -z "$ENV_SRC" ]; then
  ENV_TMP="$(mktemp -d)"; chmod 700 "$ENV_TMP"
  # qcheck-allow: R20 — 임시 인스턴스용 사본(0700 폴더 · 0600 · trap 정리) · kill -9 뒤 남는 창은 받아들인 위험(HO-B1-004 · dbg_u3_ui.sh make_env 와 같은 판단)
  ( umask 077; cp /var/www/qaredi/tbox-v2/.env "$ENV_TMP/env" ) 2>/dev/null || { echo "운영 .env 를 읽지 못했다 — UXR1_ENV_SRC 로 0600 사본을 넘긴다"; exit 3; }
  chmod 600 "$ENV_TMP/env"; ENV_SRC="$ENV_TMP/env"
fi
[ -f "$ENV_SRC" ] || { echo "임시 .env 원본이 없다"; exit 3; }
[ -d "$APP_TREE/public" ] && [ -d "$APP_TREE/src" ] || { echo "띄울 트리($APP_TREE)에 public·src 가 없다"; exit 3; }
PROD_A0=$(prodmax)
[[ "$PROD_A0" =~ ^[0-9]+$ ]] || { echo "운영 감사 max(id) 를 읽지 못했다 — 중단"; exit 3; }
ALERTS_L0=$(sudo -n wc -l < /var/log/tbox-v2/alerts.log 2>/dev/null || echo '?')
TOTP_M0=$(sudo -n stat -c %y /etc/tbox-v2/qa-totp.txt 2>/dev/null || echo '?')
note "운영 감사 max(id) 시작 = $PROD_A0 · 운영 경보 로그 줄 수 시작 = $ALERTS_L0 · 검토 계정 비밀 파일 시각 = $TOTP_M0 (셋 다 읽기만)"
note "정적 대조 기준 판 = $BASE_REV"
tdb dropdb -h 127.0.0.1 -p 5432 -U tbox_testdb -w --if-exists "$DB" >/dev/null 2>&1
TPL_DB="${UXR1_TPL_DB:-}"
if [ -n "$TPL_DB" ]; then
  # 틀 DB(운영 pg_dump 한 번으로 미리 만든 것 — tbox_testdb 소유)를 복제한다 — 이 시험 안의 pg_dump 0
  [[ "$TPL_DB" =~ ^tbox_uxr1_[a-z0-9_]+$ ]] && [ "$TPL_DB" != "$DB" ] || { echo "UXR1_TPL_DB 는 tbox_uxr1_ 접두의 다른 DB 여야 한다 — $TPL_DB"; exit 3; }
  tdb createdb -h 127.0.0.1 -p 5432 -U tbox_testdb -w -T "$TPL_DB" "$DB" >/dev/null 2>"$WORK/restore.err" || { echo "틀 DB 를 복제할 수 없다 — $(head -2 "$WORK/restore.err")"; exit 3; }
else
  tdb createdb -h 127.0.0.1 -p 5432 -U tbox_testdb -w "$DB" >/dev/null 2>&1 || { echo "임시 DB 를 만들 수 없다(tbox_testdb)"; exit 3; }
  ( cd / && sudo -n -u postgres pg_dump --no-owner -d "$SRC_DB" 2>/dev/null ) \
    | tdb psql -h 127.0.0.1 -p 5432 -U tbox_testdb -w -X -q -d "$DB" -v ON_ERROR_STOP=0 >/dev/null 2>"$WORK/restore.err"
fi
CNT=$(psqlq "SELECT (SELECT count(*) FROM users) || ' ' || (SELECT count(*) FROM community.pages);")
[ -n "$CNT" ] && [ "${CNT%% *}" -gt 0 ] 2>/dev/null || { echo "임시 DB 로 옮기지 못했다 — $(head -2 "$WORK/restore.err")"; exit 3; }
note "임시 DB $DB — 사용자 ${CNT%% *} · 페이지 ${CNT##* }"

# Latest application reads G-0 grants and accepts Q:F-10 combined token scopes.
sed '/^ALTER TABLE public\.access_\(grants\|requests\) OWNER TO tbox_owner;$/d' "$ROOT/db/migrations/2026-10-10_access_grants.sql" > "$WORK/g0.sql"
tdb psql -h 127.0.0.1 -p 5432 -U tbox_testdb -w -X -q -d "$DB" -v ON_ERROR_STOP=1 -f "$WORK/g0.sql" > "$WORK/g0.log" 2>&1 || { echo "G-0 test schema failed"; exit 3; }
tdb psql -h 127.0.0.1 -p 5432 -U tbox_testdb -w -X -q -d "$DB" -v ON_ERROR_STOP=1 -f "$ROOT/db/migrations/2026-10-13_api_tokens_multi_scope.sql" > "$WORK/qf10.log" 2>&1 || { echo "Q:F-10 test schema failed"; exit 3; }

INST="$(mktemp -d)"; chmod 700 "$INST"
mkdir -p "$INST/app" "$INST/storage" "$INST/log"
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

hr "로그인 (claude_ 검토 계정 다섯, 순차, F-71)"
for who in claude_employee01 claude_employee02 claude_manager01 claude_admin01 claude_guest01; do
  login "$who" || { echo "  ✖ $who 로그인 실패 — 중단"; exit 4; }
done
S_E1=$(session_of claude_employee01); S_E2=$(session_of claude_employee02); S_M1=$(session_of claude_manager01); S_A1=$(session_of claude_admin01); S_G1=$(session_of claude_guest01)

hr "UXR1-S 정적 (이 복제본 — 기준 판 $BASE_REV 대비)"
if [ "$BEFORE" = "1" ]; then
  note "UXR1_BEFORE=1 — 고치기 전 판을 띄웠다. 정적 시험은 이 복제본(고친 판)을 보므로 건너뛴다"
else
  PHPBAD=0
  for f in $(git -C "$ROOT" diff --name-only "$BASE_REV" "${UXR1_DIFF_REV:-HEAD}" -- '*.php' 2>/dev/null); do
    [ -f "$ROOT/$f" ] || continue
    php -l "$ROOT/$f" >/dev/null 2>&1 || { PHPBAD=$((PHPBAD+1)); echo "    php -l 실패: $f"; }
  done
  expect "바뀐 PHP php -l 실패 0" "0" "$PHPBAD"
  bash "$ROOT/scripts/security_lint.sh" "$ROOT" > "$WORK/lint.txt" 2>&1
  expect "보안 린트 통과(금지 패턴 0건)" "1" "$(grep -c '결과: 통과' "$WORK/lint.txt")"
  php "$ROOT/scripts/community_asset_version.php" --check > "$WORK/assetver.txt" 2>&1
  expect "커뮤니티 JS import 버전 표시가 지금 내용과 맞다(--check)" "0" "$?"
  git -C "$ROOT" diff "$BASE_REV" "${UXR1_DIFF_REV:-HEAD}" -- src public > "$WORK/diff.txt" 2>/dev/null
  INL=$(grep '^+' "$WORK/diff.txt" | grep -v '^+++' | grep -cE 'style="|<script([[:blank:]]+([^s>[:blank:]][^>[:blank:]]*|s[^r>[:blank:]][^>[:blank:]]*|sr[^c>[:blank:]][^>[:blank:]]*|src[^=>[:blank:]][^>[:blank:]]*|s|sr|src))*[[:blank:]]*>| on[a-z]+="')
  expect "더한 줄에 인라인 style·script·on* 속성 0" "0" "$INL"
  expect "더한 JS 줄에 문자열 → HTML 자리(innerHTML·outerHTML·insertAdjacentHTML·document.write) 0" "0" \
    "$(git -C "$ROOT" diff "$BASE_REV" "${UXR1_DIFF_REV:-HEAD}" -- 'public/assets/*.js' 'public/assets/community/*.js' 2>/dev/null | grep '^+' | grep -v '^+++' | grep -cE 'innerHTML|outerHTML|insertAdjacentHTML|document\.write')"
  # 바뀐 파일은 화면뿐 — 뷰 셋과 자산. 창구(핸들러)·권한·저장·DB·배포·라우트는 0
  ALLOWED='^(public/assets/(app\.css|app\.js|search\.js|community/(community\.css|editor\.js|database\.js|templates\.js|dashboard\.js|suggestions\.js))|src/views/shell_top\.php|src/community/handlers/(page_view|home_page)\.php|src/help/[a-z_]+\.php)$'
  OUTSIDE=$(git -C "$ROOT" diff --name-only "$BASE_REV" "${UXR1_DIFF_REV:-HEAD}" -- src public db deploy config 2>/dev/null | grep -vE "$ALLOWED")
  expect "바뀐 파일은 화면(뷰 셋 · 자산 · 도움말)뿐 — 창구·권한·저장·DB·배포·라우트 차이 0" "" "$OUTSIDE"
  CORE_FILES="src/Authz.php src/community/CommunityAuthz.php src/Auth.php src/Http.php src/Audit.php src/Db.php src/Upload.php src/Storage.php src/Passkey.php public/index.php src/community/routes.php src/community/handlers/pages_create.php src/community/handlers/board_pages_create.php src/community/handlers/pages_update.php src/handlers/login.php src/handlers/login_page.php"
  expect "판정·보안 핵심 · 저장 창구 · 로그인 파일 차이 0" "0" "$(git -C "$ROOT" diff "$BASE_REV" "${UXR1_DIFF_REV:-HEAD}" -- $CORE_FILES 2>/dev/null | wc -l)"
  AJS="$ROOT/public/assets/app.js"; EJS="$ROOT/public/assets/community/editor.js"
  expect "UX-11 세션 확인 도우미는 한 곳(app.js probeSession · window.qarediSessionProbe) · 편집기는 그것을 부른다" "1 1 1" \
    "$(grep -c '^function probeSession(' "$AJS") $(grep -c '^window.qarediSessionProbe = probeSession;$' "$AJS") $(grep -c "typeof window.qarediSessionProbe === 'function' ? window.qarediSessionProbe : null" "$EJS")"
  expect "UX-11 일괄 작업은 그 길을 끈다(sessionRetry:false — 옮기기·즐겨찾기·지우기·되살리기 둘)" "5" "$(grep -c "send: (item) => api(.*{ sessionRetry: false })," "$EJS")"
  expect "UX-11 자동 요청이 부른 세션 확인은 자동 요청(app.js 표식 상수 한 곳 · fetchMe 가 다는 곳 하나 · 편집기가 background 를 넘김)" "1 1 1" \
    "$(grep -c "^var BACKGROUND_HEADER = 'X-Qaredi-Background';$" "$AJS") $(grep -c 'init.headers\[BACKGROUND_HEADER\] = BACKGROUND_VALUE;' "$AJS") $(grep -c "return probe(meUsername, sent.token, { background: !!(opts && opts.background === true) })" "$EJS")"
  expect "UX-11 편집기는 그 요청이 보낸 토큰으로 견준다(apiSend 가 돌려줌 · 지금 토큰과 다르면 확인 없이 한 번 다시)" "1 1" \
    "$(grep -c "readJson(response).then((data) => ({ status: response.status, data: data, token: token }))" "$EJS") $(grep -c "if (sent.token !== csrfToken) { return again(); }" "$EJS")"
  expect "UX-11 파일 화면의 일괄 요청(bulkFetch)은 전과 같은 fetch(「이어서 하기」 흐름 그대로)" "1" "$(grep -A12 '  function bulkFetch(method, url, payload) {' "$AJS" | grep -c '    return fetch(url, options);')"
  expect "UX-9 자동 제출은 로그인 화면 한 곳(form.requestSubmit 하나 · 다른 코드 칸 0)" "1" "$(grep -c 'form\.requestSubmit(' "$AJS")"
  expect "UX-1 기본 제목 표 = 서버 글자(「제목 없는 페이지」 · 「제목 없는 데이터베이스」 · 「제목 없는 글」)" "1 1 1" \
    "$(grep -c "'제목 없는 데이터베이스' : (\$tpl !== null ? (string) \$tpl\['title'\] : '제목 없는 페이지')" "$ROOT/src/community/handlers/pages_create.php") $(grep -c "^\$title = '제목 없는 글';" "$ROOT/src/community/handlers/board_pages_create.php") $(grep -c "^const DEFAULT_TITLES = \['제목 없는 페이지', '제목 없는 데이터베이스', '제목 없는 글'\];$" "$EJS")"
  D="$ROOT/DESIGN.md"
  expect "DESIGN — 좁은 폭 편집 머리 · 폰 윗줄 · 「최근」 판 · 코드 자동 제출 · 블록 창 순서 · 새 페이지 제목 · 방금 올린 줄 · 게시판 글 상태 글자" "8" \
    "$(for k in '좁은 폭 편집 머리 (사용감 라운드 1 UX-2' '폰 윗줄 `header.appbar` (사용감 라운드 1' '「최근」 판 (사용감 라운드 1 UX-4' '2단계 코드 자동 제출 (사용감 라운드 1 UX-9' '「블록 추가」 창 — 순서 · 한 번 누르기' '새 페이지 제목 (사용감 라운드 1 UX-1' '방금 올린 줄 `tr.is-fresh`' '게시판 글이 지금 누구에게 보이나'; do grep -qF "$k" "$D" && echo y; done | grep -c y)"
fi

hr "UXR1-H 서버 — 셸·화면 HTML (고정 글자 · 동적 값 0)"
curl -s -b "$JAR_DIR/claude_employee01.jar" -A "$UA" "$SITE/community/workspace" > "$WORK/home.html"
curl -s -b "$JAR_DIR/claude_employee01.jar" -A "$UA" "$BASE/" > "$WORK/tbox.html"
E1_CSRF=$(curl -s -b "$JAR_DIR/claude_employee01.jar" -A "$UA" "$BASE/api/me" | python3 -c 'import json,sys; print(json.load(sys.stdin).get("csrf_token",""))')
# CSRF 토큰은 명령 인자로 넘기지 않는다(R-62 · R15) — 헤더는 표준입력으로(tests/lib/secrets.sh curl_hdr_secret)
C=$(curl_hdr_secret X-CSRF-Token E1_CSRF -s -b "$JAR_DIR/claude_employee01.jar" -A "$UA" -H 'Content-Type: application/json' --data '{}' "$SITE/community/api/pages")
E1_CSRF=''
PNEW=$(printf '%s' "$C" | python3 -c 'import json,sys; d=json.load(sys.stdin); print((d.get("page") or {}).get("id",""))' 2>/dev/null)
PTITLE=$(printf '%s' "$C" | python3 -c 'import json,sys; d=json.load(sys.stdin); print((d.get("page") or {}).get("title",""))' 2>/dev/null)
expect "제목 없이 만든 페이지의 서버 제목 그대로(저장 창구 바뀌지 않음)" "제목 없는 페이지" "$PTITLE"
curl -s -b "$JAR_DIR/claude_employee01.jar" -A "$UA" "$SITE/community/p/$PNEW" > "$WORK/page.html"
expect "커뮤니티 셸 — 윗줄 앱 이름 단추 · 검색 · 알림 종 · 안 읽은 수 자리(hidden)" "1 1 1 1" \
  "$(grep -c '<button id="appbar-apps" class="appbar__brand" type="button" aria-controls="sidebar" aria-expanded="false"' "$WORK/home.html") $(grep -c '<button id="appbar-search" class="btn btn--icon btn--plain" type="button" aria-haspopup="dialog" aria-controls="search-dialog" aria-label="검색">' "$WORK/home.html") $(grep -c '<button id="appbar-bell" class="btn btn--icon btn--plain" type="button" aria-haspopup="dialog" aria-label="알림">' "$WORK/home.html") $(grep -c '<span id="appbar-bell-count" class="badge badge--internal" hidden></span>' "$WORK/home.html")"
expect "t-box 셸 — 앱 이름 단추 · 검색은 있고 알림 종은 없다" "1 1 0" \
  "$(grep -c 'id="appbar-apps"' "$WORK/tbox.html") $(grep -c 'id="appbar-search"' "$WORK/tbox.html") $(grep -c 'id="appbar-bell"' "$WORK/tbox.html")"
expect "「내 공간」 — 「최근」 판(처음 hidden · 목록 자리)" "1 1" \
  "$(grep -c '<section class="panel panel--tight" id="community-home-recent" aria-labelledby="community-home-recent-title" hidden>' "$WORK/home.html") $(grep -c '<div id="community-home-recent-list"></div>' "$WORK/home.html")"
expect "페이지 — 제목 칸 자리표시 「제목」 · 더보기 맨 위 사용 방법·이력(처음 hidden)" "1 1 1" \
  "$(grep -c 'id="page-title-input" type="text" maxlength="200" autocomplete="off" spellcheck="false" placeholder="제목" enterkeyhint="next">' "$WORK/page.html") $(grep -c '<button id="page-more-help" class="menu__item" type="button" data-help-open aria-haspopup="dialog" hidden>' "$WORK/page.html") $(grep -c '<button id="page-more-history" class="menu__item" type="button" aria-haspopup="dialog" hidden>' "$WORK/page.html")"
expect "화면 HTML 에 계정 이름이 셸 글자 밖으로 새지 않는다(윗줄 단추 aria-label 은 앱 이름뿐)" "1" "$(grep -c 'aria-label="모아 커뮤니티 — 앱 전환"' "$WORK/home.html")"

hr "UXR1-B 화면 — tests/browser/ux_r1_ui.py (360 · 412 · 820 터치 · 1280 × 라이트 · 다크 + 흐름)"
if [ "${UXR1_SKIP_BROWSER:-0}" = "1" ]; then
  note "UXR1_SKIP_BROWSER=1 — 화면 시험을 건너뛴다(통과로 세지 않는다)"
else
  mkdir -p "$SHOTS"
  BARG=""; [ "$BEFORE" = "1" ] && BARG="before"
  PW_E1=$(review_password claude_employee01); SEC_E1=$(review_secret claude_employee01)
  PW_E2=$(review_password claude_employee02); SEC_E2=$(review_secret claude_employee02)
  LOCKCMD=()
  [ -n "${QAREDI_BROWSER_LOCK:-}" ] && LOCKCMD=(flock "$QAREDI_BROWSER_LOCK")
  {
    if mem_ok; then
      py_secret S_E1 S_E2 S_M1 S_A1 S_G1 PW_E1 SEC_E1 PW_E2 SEC_E2 -- "${LOCKCMD[@]}" env PATH="$HOME/.local/bin:$PATH" UXR1_DB="$DB" UXR1_COMBOS="${UXR1_COMBOS:-}" \
        bash -c 'a=$(free -m | awk "/^Mem:/{print \$7}"); echo "  · 브라우저 띄우기 직전 메모리 여유 ${a}MB"; [ "${a:-0}" -ge 700 ] || { echo "메모리 여유 700MB 미만 — 띄우지 않는다"; exit 97; }; exec python3 -u "$@"' _ \
        "$ROOT/tests/browser/ux_r1_ui.py" "$SITE" "$UA" "$SHOTS" $BARG 2>&1
      echo "RC=$?"
    else
      echo "메모리 여유가 15분 동안 700MB 미만 — 화면 시험을 띄우지 않았다"; echo "RC=98"
    fi; } | tee "$WORK/browser.out"
  PW_E1=''; SEC_E1=''; PW_E2=''; SEC_E2=''
  rc=$(sed -n 's/^RC=\([0-9]*\)$/\1/p' "$WORK/browser.out" | tail -1)
  expect "화면 시험 종료 코드" "0" "$rc"
  bf=$(sed -n 's/^SMOKE_FAIL=\([0-9]*\)$/\1/p' "$WORK/browser.out")
  [ "$BEFORE" = "1" ] && note "고치기 전 판의 화면 판정 실패 수 = ${bf:-?} (참고)" || expect "화면 시험 실패 수" "0" "${bf:-?}"
  bp=$(grep -c '^  ✔' "$WORK/browser.out")
  NCOMBO=$(printf '%s' "${UXR1_COMBOS:-360:light,360:dark,412:light,412:dark,820:light,820:dark,1280:light,1280:dark}" | tr ',' '\n' | grep -c .)
  BMIN=$((62 + 15 * NCOMBO))   # 흐름 45 + Q:T-21 모의 경계 경우 17 · 조합마다 15(「최근」 줄 모양 하나 더)
  if [ "$BEFORE" != "1" ]; then
    [ "$bp" -ge "$BMIN" ] && expect "화면 시험이 헛돌지 않았다(✔ $BMIN 이상 — 조합 $NCOMBO)" "yes" "yes" || expect "화면 시험이 헛돌지 않았다(✔ $BMIN 이상 — 조합 $NCOMBO)" "yes" "no($bp)"
  fi
  note "스크린샷: $SHOTS (사람이 직접 연다)"
fi
S_E1=''; S_E2=''; S_M1=''; S_A1=''; S_G1=''

hr "UXR1-L 서버 로그"
expect "임시 인스턴스 로그의 PHP 오류(Fatal·Warning·Notice·Deprecated)" "0" \
  "$(grep -cE 'PHP (Fatal|Warning|Notice|Deprecated|Parse)' "$INST/server.log")"
expect "임시 인스턴스 로그의 잡히지 않은 예외(uncaught) 0" "0" "$(grep -c 'uncaught ' "$INST/server.log")"

if [ "${REGRESS:-0}" = "1" ]; then
  hr "회귀 — 같은 임시 인스턴스에서(dbg_u3_ui.sh 의 회귀 틀 그대로)"
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
PROD_A1=$(prodmax)
ALERTS_L1=$(sudo -n wc -l < /var/log/tbox-v2/alerts.log 2>/dev/null || echo '?')
TOTP_M1=$(sudo -n stat -c %y /etc/tbox-v2/qa-totp.txt 2>/dev/null || echo '?')
note "운영 감사 max(id) 끝 = ${PROD_A1:-?} (시작 ${PROD_A0:-?}) · 경보 로그 줄 수 끝 = $ALERTS_L1 (시작 $ALERTS_L0) · 비밀 파일 시각 끝 = $TOTP_M1"
expect "검토 계정 비밀 파일 시각 그대로(이 시험은 /etc 에 쓰지 않는다)" "$TOTP_M0" "$TOTP_M1"
# 이 시험의 요청은 주소가 127.0.0.1:839x 로 고정이다(셸 case · 파이썬 assert) — 운영 감사가 늘었다면 그 사이 다른 세션·사람의 행이다.
#   같은 UA 를 쓰는 다른 세션의 운영 확인과 행으로 가를 수 없어(읽기 전용 역할은 행 내용을 못 읽는다) 수만 적고 대조는 사람이 한다
note "운영 감사 행 수 변화 $(( ${PROD_A1:-0} - ${PROD_A0:-0} )) — 이 시험 몫 0(요청 주소 839x 고정) · 늘었다면 다른 세션·사람의 행이다(대조는 메인)"
expect "이 시험이 부른 주소는 임시 인스턴스뿐(셸 SITE · 파이썬 SITE 가 127.0.0.1:839x)" "1 1" "$(case "$SITE" in http://127.0.0.1:839[1-5]) echo 1 ;; *) echo 0 ;; esac) $(grep -cF 'assert re.match(r"^http://127\.0\.0\.1:839[1-5]$", SITE)' "$ROOT/tests/browser/ux_r1_ui.py")"

printf '\n통과 %d · 실패 %d\n' "$PASS" "$FAIL"
[ "$BEFORE" = "1" ] && exit 0
[ "$FAIL" -eq 0 ]
