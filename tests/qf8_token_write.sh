#!/usr/bin/env bash
# tests/qf8_token_write.sh — Q:F-8 조각 S 수락 시험 T-1 ~ T-7 (AI 글쓰기 — 커뮤니티 창구 셋에 개인 토큰)
#                            (지시서 docs/qf8-ai-writer-directive.md 「조각 S」 · ARCHITECTURE 4.8 · API.md 19장 · SECURITY R-112)
#
#   T-7 정적   허용 표는 상수 하나 · `X-API-Token` 을 읽는 곳이 routes.php 에 한 곳 · index.php·Auth·Http·Db·CommunityAuthz·grants·deploy
#              차이 0 · security_lint 0 · 바뀐 PHP php -l · app.js node --check · Authz::tokenScopeAllows 표(범위 넷 × 정책)
#   준비       임시 DB `tbox_qf8_<포트>_test`(tbox_testdb 역할 · 운영 사본 tbox_re 는 pg_dump 로 **읽어** 붓기만) ·
#              마이그레이션 2026-10-11 을 **두 번** 적용 → 같은 CHECK 하나 · 임시 인스턴스 둘(새 코드 <포트> · 기준 판 <포트+1>)
#   T-1 발급   claude_employee01 token_scopes 에 community.write · 발급 201 · Guest 400 · 모르는 값 400
#   T-2 쓰기   토큰 POST /community/api/pages {title, doc} → 201 · personal · private · parent null · DB 행 · 감사 auth=api_token
#   T-3 읽기   토큰 GET 페이지 200 · 남의 비공개 글 = 세션과 같은 답 · GET /api/me/space 토큰 = 세션 번호
#   T-4 거부   다섯 키 각각 400 token_scope_fields · read 토큰 403 token_scope · 공용(.env) 토큰 403 · 만료 401 · 회수 401 · 틀린 값 401(세션으로 안 돌아감)
#   T-5 닫힘   토큰으로 목록·검색·고치기·댓글·지우기 → 401(헤더를 읽지 않는다) · t-box 업로드·파일 목록 403 token_scope · whoami 200
#   T-6 불변   헤더 없는 세션 요청을 기준 판 인스턴스와 견줌(상태 코드·본문 모양) · CSRF 없는 POST 403
#   서버 로그  임시 인스턴스 로그에 PHP 오류 0
#
# ★ 운영 DB·운영 포트를 건드리지 않는다 — 임시 DB 만 쓴다(만들고 지움) · 포트 8401~8405 · 검토 계정 claude_ 다섯(F-71) ·
#   UA `tbox-review/<날짜>` · 비밀(비밀번호·TOTP·토큰·CSRF)은 표준입력으로만(R-62 · tests/lib/secrets.sh).
#   임시 .env 는 운영 .env 를 mktemp 폴더(0700)에 0600 으로 복사해 DB_NAME·STORAGE_DIR·AUDIT_LOG_FILE 을 임시로 바꾸고,
#   공용 토큰(API_TOKEN·API_TOKEN_USER)은 이 시험이 만든 난수 · claude_employee01 로 바꾼다(T-4 공용 토큰 항목). 끝에 지운다.
# 쓰는 법: bash tests/qf8_token_write.sh     환경 QF8_PORT(8401~8404 · 기본 8401 — 기준 판은 +1 · 쓰는 포트 전부 8401~8405 안) · QF8_BASE_REV(기본 c2b4d76 — 운영 HEAD)
#          REGRESS=1 이면 같은 임시 인스턴스에서 REGRESS_LIST(기본 api_whoami community/stage3 community/stage5a2 f66)를 사본으로
#          (8082 → 이 인스턴스 · tbox_re → 임시 DB · stage5a2 는 8085 → 8401~8405 중 빈 자리 · 자기 임시 DB tbox_qf8_5a2_test) · REGRESS_KEEP=<폴더> 면 결과 사본
set -u

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
. "$ROOT/tests/lib/review_accounts.sh" || { echo "tests/lib/review_accounts.sh 를 읽지 못했다 — 시험을 시작하지 않는다"; exit 3; }
. "$ROOT/tests/lib/secrets.sh" || { echo "tests/lib/secrets.sh 를 읽지 못했다 — 시험을 시작하지 않는다 (R-62)"; exit 3; }

PORT="${QF8_PORT:-8401}"
case "$PORT" in 8401|8402|8403|8404) ;; *) echo "이 조각은 포트 8401~8405 만 쓴다(새 코드 8401~8404 · 기준 판 +1) — $PORT"; exit 3 ;; esac
BPORT=$((PORT + 1))
R5A2=$((PORT + 2)); [ "$R5A2" -gt 8405 ] && R5A2=$((PORT - 1))   # REGRESS 의 stage5a2 자기 인스턴스 자리(8401~8405 안)
SITE="http://127.0.0.1:$PORT"
BSITE="http://127.0.0.1:$BPORT"
BASE="$SITE/tbox"
CBASE="$SITE/community"
UA="tbox-review/$(date +%F)"
SRC_DB="tbox_re"                      # 운영 사본 — pg_dump 로 **읽기만**
DB="tbox_qf8_${PORT}_test"            # 임시 — 여기에만 쓴다
BASE_REV="${QF8_BASE_REV:-c2b4d76}"   # 기준 판(운영 HEAD — 독립 검토 RV-QF8-6)
MIG="$ROOT/db/migrations/2026-10-11_api_tokens_scope_community.sql"
PGP_TEST="$HOME/.config/qaredi/pgpass-testdb"
JAR_DIR="$(mktemp -d)"
WORK="$(mktemp -d)"
chmod 700 "$JAR_DIR" "$WORK"
INST=""; BINST=""; SPID=""; BPID=""; ENV_TMP=""
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

port_busy() { (exec 3<>"/dev/tcp/127.0.0.1/$1") 2>/dev/null; }

cleanup() {
  [ -n "$SPID" ] && { kill "$SPID" 2>/dev/null; wait "$SPID" 2>/dev/null; }
  [ -n "$BPID" ] && { kill "$BPID" 2>/dev/null; wait "$BPID" 2>/dev/null; }
  tdb dropdb -h 127.0.0.1 -p 5432 -U tbox_testdb -w --if-exists "$DB" >/dev/null 2>&1
  [ -n "$INST" ] && rm -rf "$INST"
  [ -n "$BINST" ] && rm -rf "$BINST"
  [ -n "$ENV_TMP" ] && rm -rf "$ENV_TMP"
  rm -rf "$JAR_DIR" "$WORK"
}
trap cleanup EXIT

# JSON 한 값 — $1 파일 · $2 파이썬 식(d = 파싱 결과). 읽지 못하면 빈 글자
jf() {
  python3 -c '
import json,sys
try:
    d = json.load(open(sys.argv[1], encoding="utf-8"))
except Exception:
    print(""); sys.exit(0)
try:
    v = eval(sys.argv[2])
except Exception:
    v = ""
print("" if v is None else v)
' "$1" "$2" 2>/dev/null
}

review_password() { sudo -n awk -v u="$1" '$1==u{print $3}' /etc/tbox-v2/seed-accounts.txt 2>/dev/null; }
review_secret()   { sudo -n awk -v u="$1" '$1==u{print $2}' /etc/tbox-v2/qa-totp.txt 2>/dev/null; }

login() {   # $1 계정 · $2 사이트(기본 새 코드) — 쿠키는 $JAR_DIR/<계정>.jar
  local who="$1" site="${2:-$SITE}" jar="$JAR_DIR/$1.jar" pw sec raw code left out try body
  pw=$(review_password "$who"); sec=$(review_secret "$who")
  [ -n "$pw" ] || return 2
  for try in 1 2 3; do
    if [ -n "$sec" ]; then
      raw=$(totp_now sec 2>/dev/null); code=${raw%%$'\n'*}
      printf -v body '{"username":"%s","password":"%s","otp":"%s"}' "$who" "$pw" "$code"
    else
      printf -v body '{"username":"%s","password":"%s"}' "$who" "$pw"
    fi
    out=$(curl_json_secret body -s -c "$jar" -A "$UA" -H 'Content-Type: application/json' "$site/tbox/api/login")
    case "$out" in *'"ok":true'*) body=''; pw=''; sec=''; return 0 ;; esac
    left=$(printf '%s' "$raw" | sed -n 's/^남은 시간: \([0-9]*\)초.*/\1/p'); [ -n "$left" ] || left=31
    printf '  · %s 로그인 재시도 — 다음 코드 창까지 %s초\n' "$who" "$left" >&2
    sleep $((left + 1))
  done
  body=''; pw=''; sec=''
  return 1
}
csrf_of() { curl -s -b "$JAR_DIR/$1.jar" -A "$UA" "$SITE/tbox/api/me" | python3 -c 'import json,sys; print(json.load(sys.stdin).get("csrf_token",""))' 2>/dev/null; }

# 다음 30초 창의 2단계 코드 — 방금 쓴 코드는 다시 못 쓴다(R-45). 결과는 전역 NEXT_OTP
NEXT_OTP=''
otp_next() {
  local sec raw left
  NEXT_OTP=''
  sec=$(review_secret "$1"); [ -n "$sec" ] || return 1
  raw=$(totp_now sec 2>/dev/null) || return 1
  left=$(printf '%s' "$raw" | sed -n 's/^남은 시간: \([0-9]*\)초.*/\1/p'); [ -n "$left" ] || left=31
  printf '  · %s 다음 2단계 코드 창까지 %s초 기다린다 (R-45 1회성)\n' "$1" "$left" >&2
  sleep $((left + 1))
  raw=$(totp_now sec 2>/dev/null) || return 1
  NEXT_OTP=${raw%%$'\n'*}
  sec=''
}

# 토큰 발급(세션 · 비밀번호+코드) — 결과는 전역: ISSUED_CODE · ISSUED_ID · ISSUED_SCOPE · 토큰 원문은 $2 이름의 변수에
ISSUED_CODE=''; ISSUED_ID=''; ISSUED_SCOPE=''
issue_as() {   # $1 계정 · $2 받을 변수 이름 · $3 범위 · $4 코드("next" 면 다음 창 코드, 그 밖은 그 글자 그대로)
  local who="$1" var="$2" scope="$3" otp="$4" pw body csrf tok hf
  ISSUED_CODE=''; ISSUED_ID=''; ISSUED_SCOPE=''
  printf -v "$var" '%s' ''
  if [ "$otp" = "next" ]; then otp_next "$who" || { ISSUED_CODE='코드없음'; return 0; }; otp="$NEXT_OTP"; fi
  pw=$(review_password "$who"); [ -n "$pw" ] || { ISSUED_CODE='비밀번호없음'; return 0; }
  body=$(py_secret pw -- python3 -c 'import json,sys; pw=sys.stdin.readline().rstrip("\n"); print(json.dumps({"name":sys.argv[1],"purpose":sys.argv[2],"scope":sys.argv[3],"password":pw,"otp":sys.argv[4]}))' \
         "QF8-$scope" "Q:F-8 수락 시험(tests/qf8_token_write.sh)" "$scope" "$otp")
  pw=''
  # 표준입력은 본문(비밀번호)이 쓴다 — CSRF 머리는 0600 임시 파일로(secret_file · 한 명령은 표준입력 하나)
  csrf="X-CSRF-Token: $(csrf_of "$who")"
  hf=$(secret_file csrf "$WORK") || { ISSUED_CODE='머리파일없음'; return 0; }
  ISSUED_CODE=$(curl_json_secret body -s -o "$WORK/issue.json" -w '%{http_code}' -X POST \
         -b "$JAR_DIR/$who.jar" -A "$UA" -H @"$hf" -H 'Content-Type: application/json' "$BASE/api/me/api-tokens")
  rm -f "$hf"; body=''; csrf=''
  if [ "$ISSUED_CODE" = "201" ]; then
    tok=$(jf "$WORK/issue.json" "d.get('token','')")
    printf -v "$var" '%s' "$tok"; tok=''
    ISSUED_ID=$(jf "$WORK/issue.json" "d.get('id','')")
    ISSUED_SCOPE=$(jf "$WORK/issue.json" "d.get('scope','')")
  fi
  : > "$WORK/issue.json"      # 토큰이 든 응답 파일은 곧바로 비운다
}

# 토큰 요청 — $1 토큰 변수 이름 · $2 메서드 · $3 주소 · $4 본문 파일(선택). 상태 코드를 찍고 본문은 $WORK/r.json
treq() {
  local v="$1" m="$2" u="$3" f="${4:-}"
  if [ -n "$f" ]; then
    curl_hdr_secret X-API-Token "$v" -s -o "$WORK/r.json" -w '%{http_code}' -A "$UA" -X "$m" -H 'Expect:' \
      -H 'Content-Type: application/json' --data-binary @"$f" "$u"
  else
    curl_hdr_secret X-API-Token "$v" -s -o "$WORK/r.json" -w '%{http_code}' -A "$UA" -X "$m" "$u"
  fi
}
# 세션 요청 — $1 계정 · $2 메서드 · $3 주소 · $4 본문 파일(선택) · $5 csrf 를 싣나(1/0, 기본 1) · $6 본문 저장 파일(기본 r.json)
sreq() {
  local who="$1" m="$2" u="$3" f="${4:-}" withc="${5:-1}" saveto="${6:-$WORK/r.json}"
  local csrf=''
  local copts=(-s -o "$saveto" -w '%{http_code}' -b "$JAR_DIR/$who.jar" -A "$UA" -X "$m" -H 'Expect:')
  [ -n "$f" ] && copts+=(-H 'Content-Type: application/json' --data-binary @"$f")
  if [ "$withc" = "1" ]; then
    csrf=$(csrf_of "$who")
    printf 'X-CSRF-Token: %s\n' "$csrf" | curl -H @- "${copts[@]}" "$u"
  else
    curl "${copts[@]}" "$u"
  fi
}
err_of()    { jf "$WORK/r.json" "d.get('error','')"; }
reason_of() { jf "$WORK/r.json" "d.get('reason','')"; }
maxaudit()  { psqlq "SELECT coalesce(max(id),0) FROM audit_logs"; }

make_env() {   # $1 원본 $2 대상 $3 저장 폴더 $4 감사 파일 $5 공용 토큰 파일. 값은 출력하지 않는다.
  # qcheck-allow: R20 — 임시 인스턴스용 사본(0700 폴더 · 0600 · trap 정리) · kill -9 뒤 남는 창은 받아들인 위험(HO-B1-004 · ux_r1_ui.sh make_env 와 같은 판단)
  { sed -e "s#^DB_NAME=.*#DB_NAME=$DB#" \
        -e "s#^STORAGE_DIR=.*#STORAGE_DIR=$3#" \
        -e "s#^AUDIT_LOG_FILE=.*#AUDIT_LOG_FILE=$4#" \
        -e "s#^SCAN_ENABLED=.*#SCAN_ENABLED=0#" \
        -e '/^API_TOKEN=/d' -e '/^API_TOKEN_USER=/d' "$1"
    printf 'API_TOKEN=%s\nAPI_TOKEN_USER=claude_employee01\n' "$(cat "$5")"; } > "$2"
  chmod 600 "$2"
  [ "$(grep -c '^DB_NAME=' "$2")" = "1" ] && grep -q "^DB_NAME=$DB\$" "$2" && ! grep -q '^DB_NAME=tbox_re$' "$2" \
    && grep -q "^STORAGE_DIR=$3\$" "$2" && grep -q "^AUDIT_LOG_FILE=$4\$" "$2" && [ "$(grep -c '^API_TOKEN=' "$2")" = "1" ]
}
make_router() {
  cat > "$1/router.php" <<'PHP'
<?php
$root = __DIR__ . '/public';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (is_string($path) && strpos($path, '/tbox/assets/') === 0) {
    http_response_code(404);
    return true;
}
require $root . '/index.php';
PHP
}
start_inst() {   # $1 인스턴스 폴더 · $2 포트 → 전역 LAST_PID
  ( cd "$1/app" && exec php -S "127.0.0.1:$2" -t public router.php > "$1/server.log" 2>&1 ) &
  LAST_PID=$!
  local c="" i
  for i in $(seq 1 30); do sleep 0.3; c=$(curl -s -o /dev/null -w '%{http_code}' -A "$UA" "http://127.0.0.1:$2/tbox/login"); [ "$c" = "200" ] && break; done
  [ "$c" = "200" ]
}

# ════════════════════════════════════════════════════════════════
hr "T-7 정적 (저장소 — 기준 판 $BASE_REV 대비)"
RT="$ROOT/src/community/routes.php"
expect "허용 표는 상수 하나(COMMUNITY_API_TOKEN_ROUTES) · 항목 셋" "1 3" \
  "$(grep -c '^const COMMUNITY_API_TOKEN_ROUTES = \[$' "$RT") $(sed -n '/^const COMMUNITY_API_TOKEN_ROUTES = \[$/,/^\];$/p' "$RT" | grep -cE "^    '(GET|POST) /api/")"
expect "표의 셋 = GET /api/me/space · POST /api/pages · GET /api/pages/{id}" "GET /api/me/space|POST /api/pages|GET /api/pages/{id}" \
  "$(sed -n '/^const COMMUNITY_API_TOKEN_ROUTES = \[$/,/^\];$/p' "$RT" | sed -n "s/^    '\([^']*\)',$/\1/p" | paste -sd'|')"
expect "X-API-Token 을 읽는 곳 — routes.php 에 한 곳(Http::apiTokenHeader) · 커뮤니티 폴더의 다른 파일 0 · \$_SERVER 직접 0" "1 0 0" \
  "$(grep -c 'Http::apiTokenHeader()' "$RT") $(grep -rl 'apiTokenHeader\|HTTP_X_API_TOKEN' "$ROOT/src/community" | grep -vc '/routes.php$') $(grep -rc 'HTTP_X_API_TOKEN' "$ROOT/src/community" | awk -F: '{s+=$2} END{print s+0}')"
expect "토큰 갈래의 판정 함수는 기존 셋 그대로(requireApiToken(true) · tokenScopeAllows · MSG_TOKEN_SCOPE)" "1 1 1" \
  "$(grep -c 'Auth::requireApiToken(true)' "$RT") $(grep -c 'Authz::tokenScopeAllows(TBOX_API_TOKEN_SCOPE, \$policy)' "$RT") $(grep -c 'Http::error(403, Http::MSG_TOKEN_SCOPE);' "$RT")"
# deploy/DEPENDENCIES.md 는 같은 묶음의 조각 C 몫(파이썬 패키지 절)이라 뺀다 — 조각 S 는 deploy/** 를 고치지 않는다
expect "고치지 않는 파일 차이 0 (index.php · Auth · Http · Db · CommunityAuthz · grants · deploy — 조각 C 의 DEPENDENCIES.md 제외)" "0" \
  "$(git -C "$ROOT" diff "$BASE_REV" -- public/index.php src/Auth.php src/Http.php src/Db.php src/community/CommunityAuthz.php db/grants.sql deploy ':(exclude)deploy/DEPENDENCIES.md' 2>/dev/null | wc -l)"
PHPBAD=0
for f in $(git -C "$ROOT" diff --name-only "$BASE_REV" -- '*.php' 2>/dev/null) $(git -C "$ROOT" ls-files --others --exclude-standard -- '*.php' 2>/dev/null); do
  [ -f "$ROOT/$f" ] || continue
  php -l "$ROOT/$f" >/dev/null 2>&1 || { PHPBAD=$((PHPBAD+1)); echo "    php -l 실패: $f"; }
done
expect "바뀐 PHP php -l 실패 0" "0" "$PHPBAD"
node --check "$ROOT/public/assets/app.js" >/dev/null 2>&1; expect "app.js node --check" "0" "$?"
bash "$ROOT/scripts/security_lint.sh" "$ROOT" > "$WORK/lint.txt" 2>&1
expect "보안 린트 통과(금지 패턴 0건)" "1" "$(grep -c '결과: 통과' "$WORK/lint.txt")"
expect "더한 JS 줄에 문자열 → HTML 자리 0" "0" \
  "$(git -C "$ROOT" diff "$BASE_REV" -- 'public/assets/*.js' 2>/dev/null | grep '^+' | grep -v '^+++' | grep -cE 'innerHTML|outerHTML|insertAdjacentHTML|document\.write')"
# Authz::tokenScopeAllows 표 — 범위 넷 × 정책. 사다리에 끼지 않는다(독립 값)
AZ=$(php -r '
require $argv[1];
$scopes = ["read", "read+upload", "read+upload+delete", "community.write"];
$pols = ["authenticated", "file.read", "file.upload", "file.update", "file.delete", "community.read", "community.write", "role:Admin", "file.fetch"];
foreach ($pols as $p) { $row = []; foreach ($scopes as $s) { $row[] = Authz::tokenScopeAllows($s, $p) ? "1" : "0"; } echo $p, "=", implode("", $row), " "; }
echo "SCOPES=", implode(",", Authz::TOKEN_SCOPES);
' "$ROOT/src/Authz.php" 2>&1)
expect "tokenScopeAllows 표(read · read+upload · read+upload+delete · community.write)" \
  "authenticated=1111 file.read=1110 file.upload=0110 file.update=0110 file.delete=0010 community.read=0001 community.write=0001 role:Admin=0000 file.fetch=0000 SCOPES=read,read+upload,read+upload+delete,community.write" "$AZ"
expect "마이그레이션 — 제약 하나를 지우고 다시(네 값) · UPDATE·DELETE·TRUNCATE 0 · 되돌리기 절" "1 1 0 1" \
  "$(grep -c '^ALTER TABLE api_tokens DROP CONSTRAINT IF EXISTS api_tokens_scope_check;$' "$MIG") $(grep -c "CHECK (scope IN ('read', 'read+upload', 'read+upload+delete', 'community.write'))" "$MIG") $(grep -v '^--' "$MIG" | grep -ciE '^\s*(UPDATE|DELETE|TRUNCATE)\b') $(grep -c '^-- 되돌리기' "$MIG")"

# ════════════════════════════════════════════════════════════════
hr "준비 — 임시 DB · 마이그레이션 두 번 · 임시 인스턴스 둘 (운영 사본에는 쓰지 않는다)"
for p in "$PORT" "$BPORT"; do port_busy "$p" && { echo "포트 $p 이 이미 쓰이고 있다 — 중단"; exit 3; }; done
[ -r "$PGP_TEST" ] || { echo "임시 DB 역할(tbox_testdb)의 pgpass 가 없다 — $PGP_TEST"; exit 3; }
ENV_TMP="$(mktemp -d)"; chmod 700 "$ENV_TMP"
# qcheck-allow: R20 — 임시 인스턴스용 사본(0700 폴더 · 0600 · trap 정리) · kill -9 뒤 남는 창은 받아들인 위험(HO-B1-004)
( umask 077; cp /var/www/qaredi/tbox-v2/.env "$ENV_TMP/env" ) 2>/dev/null || { echo "운영 .env 를 읽지 못했다"; exit 3; }
chmod 600 "$ENV_TMP/env"
( umask 077; head -c 64 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 48 > "$ENV_TMP/shared" )
[ "$(wc -c < "$ENV_TMP/shared")" = "48" ] || { echo "공용 토큰 난수를 만들지 못했다"; exit 3; }
SHARED_TOK=$(cat "$ENV_TMP/shared")

tdb dropdb -h 127.0.0.1 -p 5432 -U tbox_testdb -w --if-exists "$DB" >/dev/null 2>&1
tdb createdb -h 127.0.0.1 -p 5432 -U tbox_testdb -w "$DB" >/dev/null 2>&1 || { echo "임시 DB 를 만들 수 없다(tbox_testdb)"; exit 3; }
( cd / && sudo -n -u postgres pg_dump --no-owner -d "$SRC_DB" 2>/dev/null ) \
  | tdb psql -h 127.0.0.1 -p 5432 -U tbox_testdb -w -X -q -d "$DB" -v ON_ERROR_STOP=0 >/dev/null 2>"$WORK/restore.err"
CNT=$(psqlq "SELECT (SELECT count(*) FROM users) || ' ' || (SELECT count(*) FROM community.pages);")
[ -n "$CNT" ] && [ "${CNT%% *}" -gt 0 ] 2>/dev/null || { echo "임시 DB 로 옮기지 못했다 — $(head -2 "$WORK/restore.err")"; exit 3; }
note "임시 DB $DB — 사용자 ${CNT%% *} · 페이지 ${CNT##* }"
CHK="SELECT count(*) || ' ' || string_agg(pg_get_constraintdef(oid), ' ') FROM pg_constraint WHERE conrelid = 'api_tokens'::regclass AND contype = 'c' AND pg_get_constraintdef(oid) LIKE '%scope%';"
note "적용 전 CHECK: $(psqlq "$CHK")"
M1=$(tdb psql -h 127.0.0.1 -p 5432 -U tbox_testdb -w -X -q -d "$DB" -v ON_ERROR_STOP=1 -f "$MIG" 2>&1 >/dev/null; echo "rc=$?")
C1=$(psqlq "$CHK")
M2=$(tdb psql -h 127.0.0.1 -p 5432 -U tbox_testdb -w -X -q -d "$DB" -v ON_ERROR_STOP=1 -f "$MIG" 2>&1 >/dev/null; echo "rc=$?")
C2=$(psqlq "$CHK")
expect "마이그레이션 첫 적용 rc" "rc=0" "$M1"
expect "마이그레이션 둘째 적용 rc(멱등)" "rc=0" "$M2"
expect "두 번 적용 뒤 scope CHECK 하나 · 네 값" "1 CHECK ((scope = ANY (ARRAY['read'::text, 'read+upload'::text, 'read+upload+delete'::text, 'community.write'::text])))" "$C2"
expect "첫 적용과 둘째 적용의 결과가 같다" "$C1" "$C2"
expect "기존 토큰 행의 범위는 그대로(네 값 밖 0)" "0" "$(psqlq "SELECT count(*) FROM api_tokens WHERE scope NOT IN ('read','read+upload','read+upload+delete','community.write')")"

INST="$(mktemp -d)"; chmod 700 "$INST"; mkdir -p "$INST/app" "$INST/storage" "$INST/log"
tar -C "$ROOT" -cf - --exclude=.git --exclude=jar --exclude=api-test.txt --exclude=./.env --exclude=./.claude --exclude=__pycache__ --exclude=./clients . | tar -C "$INST/app" -xf -
make_env "$ENV_TMP/env" "$INST/app/.env" "$INST/storage" "$INST/log/audit.log" "$ENV_TMP/shared" || { echo "임시 .env 에 임시 값이 들어가지 않았다 — 중단"; exit 4; }
ln -s /var/www/qaredi/community "$INST/community" 2>/dev/null
make_router "$INST/app"
BINST="$(mktemp -d)"; chmod 700 "$BINST"; mkdir -p "$BINST/app" "$BINST/storage" "$BINST/log"
git -C "$ROOT" archive "$BASE_REV" | tar -C "$BINST/app" -xf - || { echo "기준 판 $BASE_REV 을 풀지 못했다"; exit 4; }
make_env "$ENV_TMP/env" "$BINST/app/.env" "$BINST/storage" "$BINST/log/audit.log" "$ENV_TMP/shared" || { echo "기준 판 .env 준비 실패"; exit 4; }
ln -s /var/www/qaredi/community "$BINST/community" 2>/dev/null
make_router "$BINST/app"
note "임시 .env 확인: $(grep '^DB_NAME=' "$INST/app/.env") · 공용 토큰 귀속 $(grep '^API_TOKEN_USER=' "$INST/app/.env")"
start_inst "$INST" "$PORT"; OK1=$?; SPID=$LAST_PID
start_inst "$BINST" "$BPORT"; OK2=$?; BPID=$LAST_PID
expect "새 코드 인스턴스가 떴다($PORT)" "0" "$OK1"
expect "기준 판 인스턴스가 떴다($BPORT)" "0" "$OK2"
[ "$OK1" = "0" ] && [ "$OK2" = "0" ] || { tail -5 "$INST/server.log" "$BINST/server.log"; exit 4; }

hr "로그인 (claude_ 검토 계정, 순차, F-71)"
for who in claude_employee01 claude_employee02 claude_guest01; do
  login "$who" || { echo "  ✖ $who 로그인 실패 — 중단"; exit 4; }
done
note "세션 셋(claude_employee01 · claude_employee02 · claude_guest01)"

# ════════════════════════════════════════════════════════════════
hr "T-1 발급 — 범위 community.write"
curl -s -b "$JAR_DIR/claude_employee01.jar" -A "$UA" "$BASE/api/me" > "$WORK/me.json"
expect "claude_employee01 token_scopes(파일 셋 + community.write)" "read,read+upload,read+upload+delete,community.write" "$(jf "$WORK/me.json" "','.join(d.get('token_scopes',[]))")"
curl -s -b "$JAR_DIR/claude_guest01.jar" -A "$UA" "$BASE/api/me" > "$WORK/gme.json"
expect "claude_guest01 token_scopes 는 read 하나(커뮤니티 쓰기 자격 없음)" "read" "$(jf "$WORK/gme.json" "','.join(d.get('token_scopes',[]))")"
A0=$(maxaudit)
issue_as claude_guest01 TOK_G community.write 000000
expect "Guest 가 community.write 발급 → 400(허용 목록 밖 · 비밀번호 확인 전에 끝남)" "400" "$ISSUED_CODE"
issue_as claude_employee01 TOK_X community.admin 000000
expect "모르는 범위 값 → 400" "400" "$ISSUED_CODE"
issue_as claude_employee01 TOK_X 'read+community.write' 000000
expect "사다리에 끼운 값(read+community.write) → 400" "400" "$ISSUED_CODE"
expect "위 세 거부는 실패 집계 0(claude_employee01 failed_count)" "0" "$(psqlq "SELECT failed_count FROM users WHERE username='claude_employee01'")"
issue_as claude_employee01 TOK_CW community.write next
expect "claude_employee01 community.write 발급 → 201" "201 community.write" "$ISSUED_CODE $ISSUED_SCOPE"
CW_ID="$ISSUED_ID"
expect "DB 행 범위 · 만료 180일" "community.write 180" "$(psqlq "SELECT scope || ' ' || round(extract(epoch FROM expires_at - created_at) / 86400) FROM api_tokens WHERE id = ${CW_ID:-0}")"
issue_as claude_employee01 TOK_R read next
expect "claude_employee01 read 발급 → 201(T-4 범위 거부용)" "201 read" "$ISSUED_CODE $ISSUED_SCOPE"
R_ID="$ISSUED_ID"
[ -n "$TOK_CW" ] || { echo "  ✖ community.write 토큰이 없어 뒤 항목을 돌릴 수 없다 — 중단"; exit 4; }

# ════════════════════════════════════════════════════════════════
hr "T-3(앞) · T-2 쓰기 — 토큰으로 내 개인 공간에 비공개 페이지"
C=$(treq TOK_CW GET "$CBASE/api/me/space")
TSPACE=$(jf "$WORK/r.json" "d['space']['id']"); TKIND=$(jf "$WORK/r.json" "d['space']['kind']")
expect "토큰 GET /community/api/me/space → 200 · personal" "200 personal" "$C $TKIND"
note "실제 200 본문(API.md 19장): $(head -c 400 "$WORK/r.json")"
C=$(sreq claude_employee01 GET "$CBASE/api/me/space" "" 0)
SSPACE=$(jf "$WORK/r.json" "d['space']['id']")
expect "세션 GET /community/api/me/space 와 같은 번호" "$SSPACE" "$TSPACE"
UID1=$(psqlq "SELECT id FROM users WHERE username='claude_employee01'")
python3 - "$WORK/doc.json" <<'PY'
import json, sys, uuid
u = lambda: str(uuid.uuid4())
doc = {"schema": 1, "blocks": [
    {"id": u(), "type": "heading", "level": 2, "text": [{"t": "AI 가 쓴 글", "m": {}}]},
    {"id": u(), "type": "paragraph", "text": [{"t": "첫 문단 — ", "m": {}}, {"t": "굵게", "m": {"bold": True}}]},
    {"id": u(), "type": "paragraph", "text": [{"t": "둘째 문단", "m": {}}]},
    {"id": u(), "type": "bulleted", "text": [{"t": "목록 하나", "m": {}}]},
    {"id": u(), "type": "bulleted", "text": [{"t": "목록 둘", "m": {}}]},
]}
json.dump({"title": "QF8 토큰 글", "doc": doc}, open(sys.argv[1], "w", encoding="utf-8"), ensure_ascii=False)
PY
A1=$(maxaudit)
C=$(treq TOK_CW POST "$CBASE/api/pages" "$WORK/doc.json")
cp "$WORK/r.json" "$WORK/created.json"
PID_T=$(jf "$WORK/created.json" "d['page']['id']")
expect "토큰 POST /community/api/pages {title, doc} → 201" "201" "$C"
note "실제 201 본문(비밀 없음 — API.md 19장): $(head -c 700 "$WORK/created.json")"
[ "$C" = "201" ] && [ -n "$PID_T" ] || { note "응답: $(head -c 300 "$WORK/created.json")"; echo "  ✖ 토큰으로 만든 페이지가 없어 뒤 항목이 연쇄로 어긋난다 — 중단"; exit 4; }
expect "응답 space.kind · visibility · parent_id · 내 공간 번호" "personal private None $TSPACE" \
  "$(jf "$WORK/created.json" "d['page']['space']['kind'] + ' ' + d['page']['visibility'] + ' ' + str(d['page']['parent_id']) + ' ' + str(d['page']['space']['id'])")"
expect "임시 DB 행 — 개인 공간 · 비공개 · 부모 없음 · 작성자 claude_employee01 · 블록 5" "personal private true $UID1 5" \
  "$(psqlq "SELECT s.kind || ' ' || p.visibility || ' ' || (p.parent_page_id IS NULL) || ' ' || p.author_id || ' ' || p.block_count FROM community.pages p JOIN community.spaces s ON s.id = p.space_id WHERE p.public_id = CAST('${PID_T:-00000000-0000-0000-0000-000000000000}' AS uuid)")"
expect "감사 page.create allow 한 행 — auth=api_token · token_kind=personal · token_id · token_scope · 행위자 claude_employee01 · UA" "1" \
  "$(psqlq "SELECT count(*) FROM audit_logs WHERE id > $A1 AND action = 'page.create' AND result = 'allow' AND detail->>'auth' = 'api_token' AND detail->>'token_kind' = 'personal' AND detail->>'token_id' = '${CW_ID:-0}' AND detail->>'token_scope' = 'community.write' AND actor_name = 'claude_employee01' AND user_agent = '$UA'")"
expect "감사 route = POST /community/api/pages" "POST /community/api/pages" \
  "$(psqlq "SELECT detail->>'route' FROM audit_logs WHERE id > $A1 AND action = 'page.create' ORDER BY id DESC LIMIT 1")"

# ════════════════════════════════════════════════════════════════
hr "T-3 읽기"
C=$(treq TOK_CW GET "$CBASE/api/pages/$PID_T")
expect "토큰 GET /community/api/pages/{내 글} → 200 · 같은 id · 블록 5" "200 $PID_T 5" "$C $(jf "$WORK/r.json" "d['page']['id']") $(jf "$WORK/r.json" "len(d['page']['doc']['blocks'])")"
C=$(sreq claude_employee02 GET "$CBASE/api/me/space" "" 0)
E2_PAGE=$(jf "$WORK/r.json" "(d.get('start_page') or {}).get('id','')")
[ -n "$E2_PAGE" ] || E2_PAGE=$(psqlq "SELECT p.public_id FROM community.pages p JOIN community.spaces s ON s.id = p.space_id JOIN users u ON u.id = s.owner_user_id WHERE u.username = 'claude_employee02' AND s.kind = 'personal' AND p.deleted_at IS NULL AND p.visibility = 'private' ORDER BY p.id LIMIT 1")
note "claude_employee02 의 비공개 글 id = ${E2_PAGE:-없음}"
expect "claude_employee02 글은 비공개" "private" "$(psqlq "SELECT visibility FROM community.pages WHERE public_id = CAST('${E2_PAGE:-00000000-0000-0000-0000-000000000000}' AS uuid)")"
SC=$(sreq claude_employee01 GET "$CBASE/api/pages/$E2_PAGE" "" 0); SE=$(err_of)
TC=$(treq TOK_CW GET "$CBASE/api/pages/$E2_PAGE"); TE=$(err_of)
expect "남의 비공개 글 — 토큰 답 = 세션 답(403/404)" "$SC $SE" "$TC $TE"
case "$TC" in 403|404) expect "그 답은 403 또는 404" "yes" "yes" ;; *) expect "그 답은 403 또는 404" "yes" "no($TC)" ;; esac
C=$(treq TOK_CW GET "$CBASE/api/pages/00000000-0000-4000-8000-000000000000")
expect "없는 페이지 id → 404(세션과 같은 갈래)" "404" "$C"

# ════════════════════════════════════════════════════════════════
hr "T-4 거부"
for k in space_id parent_id template_id database properties; do
  python3 -c 'import json,sys; json.dump({"title":"QF8 거부","doc":{"schema":1,"blocks":[]},sys.argv[1]:None}, open(sys.argv[2],"w"))' "$k" "$WORK/bad.json"
  C=$(treq TOK_CW POST "$CBASE/api/pages" "$WORK/bad.json")
  expect "토큰 POST 에 $k(null) → 400 token_scope_fields" "400 token_scope_fields" "$C $(reason_of)"
done
python3 -c 'import json,sys; json.dump({"title":"QF8 거부","space_id":int(sys.argv[1])}, open(sys.argv[2],"w"))' "${TSPACE:-1}" "$WORK/bad.json"
C=$(treq TOK_CW POST "$CBASE/api/pages" "$WORK/bad.json")
expect "토큰 POST 에 space_id(내 공간 번호 — 값이 맞아도) → 400 token_scope_fields" "400 token_scope_fields" "$C $(reason_of)"
python3 -c 'import json,sys; json.dump({"title":"QF8 거부","visibility":"team"}, open(sys.argv[1],"w"))' "$WORK/bad.json"
C=$(treq TOK_CW POST "$CBASE/api/pages" "$WORK/bad.json")
expect "토큰 POST 에 visibility(원래 허용 키 밖) → 400 잘못된 요청(세션과 같은 갈래)" "400 잘못된 요청입니다" "$C $(err_of)"
expect "거부 뒤 claude_employee01 의 페이지 수 늘지 않음(토큰으로 만든 것 1)" "1" \
  "$(psqlq "SELECT count(*) FROM community.pages WHERE author_id = ${UID1:-0} AND title IN ('QF8 토큰 글','QF8 거부')")"
A2=$(maxaudit)
C=$(treq TOK_R POST "$CBASE/api/pages" "$WORK/doc.json")
expect "read 범위 토큰 POST /api/pages → 403 token_scope 문구" "403 이 토큰의 범위에 없는 창구입니다 — 범위가 더 넓은 토큰을 발급하세요" "$C $(err_of)"
C=$(treq TOK_R GET "$CBASE/api/me/space")
expect "read 범위 토큰 GET /api/me/space → 403(범위 독립 — 읽기 사다리가 커뮤니티를 열지 않는다)" "403" "$C"
expect "감사 auth.token deny scope_not_permitted 두 행(token_scope=read · auth=api_token · route 패턴)" "2" \
  "$(psqlq "SELECT count(*) FROM audit_logs WHERE id > $A2 AND action = 'auth.token' AND result = 'deny' AND reason = 'scope_not_permitted' AND detail->>'token_scope' = 'read' AND detail->>'auth' = 'api_token' AND target_name IN ('POST /community/api/pages','GET /community/api/me/space')")"
C=$(treq SHARED_TOK POST "$CBASE/api/pages" "$WORK/doc.json")
expect "공용(.env) 토큰 → 403 개인 토큰만(F-59)" "403 이 창구는 개인 토큰으로만 쓸 수 있습니다" "$C $(err_of)"
C=$(treq SHARED_TOK GET "$BASE/api/whoami")
expect "(대조) 같은 공용 토큰 GET /tbox/api/whoami → 200(값은 맞다)" "200" "$C"
WRONG_TOK='qf8-wrong-token-000000000000000000000000000000000'
C=$(treq WRONG_TOK GET "$CBASE/api/me/space")
expect "틀린 값 → 401" "401 API 토큰이 올바르지 않습니다" "$C $(err_of)"
C=$(curl_hdr_secret X-API-Token WRONG_TOK -s -o "$WORK/r.json" -w '%{http_code}' -b "$JAR_DIR/claude_employee01.jar" -A "$UA" "$CBASE/api/me/space")
expect "틀린 값 + 살아 있는 세션 쿠키 → 401(세션으로 되돌아가지 않는다)" "401" "$C"
psqlq "UPDATE api_tokens SET expires_at = now() - interval '1 minute' WHERE id = ${R_ID:-0}" >/dev/null
C=$(treq TOK_R GET "$CBASE/api/pages/$PID_T")
expect "만료 토큰 → 401 token_expired 문구" "401 만료된 API 토큰입니다 — 내 계정에서 새로 발급하세요" "$C $(err_of)"

# ════════════════════════════════════════════════════════════════
hr "T-5 닫힘 — 표 밖 창구는 헤더를 읽지 않는다 (COMMUNITY_ROUTES 전수 · 독립 검토 RV-QF8-6)"
# 라우트 표를 **저장소 파일에서** 읽는다 — 창구가 늘어도 이 시험이 따라간다. 허용 셋(COMMUNITY_API_TOKEN_ROUTES)만 뺀다.
# 자리표시자는 없는 값으로(없는 uuid · 없는 번호 · 없는 계정) — 헤더를 읽지 않으면 인증 단계에서 끝나 대상에 닿지 않는다.
python3 - "$RT" "$WORK/closed.tsv" <<'PY2'
import re, sys
src = open(sys.argv[1], encoding="utf-8").read()
body = src[src.index("const COMMUNITY_ROUTES = ["):]
body = body[:body.index("\n];")]
allow = re.search(r"const COMMUNITY_API_TOKEN_ROUTES = \[(.*?)\n\];", src, re.S).group(1)
allowed = set(re.findall(r"'((?:GET|POST|PATCH|PUT|DELETE) [^']+)'", allow))
fill = {"{id}": "00000000-0000-4000-8000-00000000f5f5", "{num}": "999999999", "{username}": "claude_nobody_qf8"}
seen = set()
with open(sys.argv[2], "w", encoding="utf-8") as out:
    for m, p in re.findall(r"^\s*\['(GET|POST|PATCH|PUT|DELETE)',\s*'([^']+)'", body, re.M):
        key = m + " " + p
        if key in allowed or key in seen:
            continue
        seen.add(key)
        path = p
        for k, v in fill.items():
            path = path.replace(k, v)
        out.write("%s\t%s\t%s\n" % (m, p, path))
PY2
printf '{}' > "$WORK/empty.json"
NROUTES=$(grep -c "^\s*\['\(GET\|POST\|PATCH\|PUT\|DELETE\)'," "$RT")
NCLOSED=$(grep -c . "$WORK/closed.tsv")
expect "전수 대상 = 라우트 표 줄 수 − 허용 셋(중복 없음)" "$((NROUTES - 3))" "$NCLOSED"
A3=$(maxaudit)
BAD=0; NAPI=0; NSCR=0
while IFS=$'\t' read -r m pat path; do
  case "$m" in GET|DELETE) C=$(treq TOK_CW "$m" "$CBASE$path") ;; *) C=$(treq TOK_CW "$m" "$CBASE$path" "$WORK/empty.json") ;; esac
  case "$pat" in
    /api/*) NAPI=$((NAPI+1)); [ "$C $(err_of)" = "401 로그인이 필요합니다" ] || { BAD=$((BAD+1)); echo "    ✖ $m $pat → $C $(err_of)"; } ;;
    *)      NSCR=$((NSCR+1)); [ "$C" = "302" ] || { BAD=$((BAD+1)); echo "    ✖ $m $pat → $C"; } ;;
  esac
done < "$WORK/closed.tsv"
note "전수: API $NAPI 개 · 화면 $NSCR 개 (허용 셋 3 제외 · 라우트 표 $NROUTES 줄)"
expect "허용 셋 밖 커뮤니티 창구 전부 — community.write 토큰만으로 API 401 「로그인이 필요합니다」 · 화면 302(로그인)" "0" "$BAD"
[ "$NCLOSED" -gt 50 ] && expect "전수 목록이 헛돌지 않았다(50 개 넘게)" "yes" "yes" || expect "전수 목록이 헛돌지 않았다(50 개 넘게)" "yes" "no($NCLOSED)"
C=$(treq TOK_CW GET "$CBASE/api/search?q=QF8");            expect "토큰 GET /community/api/search?q=… → 401(쿼리 붙은 모양도)" "401 로그인이 필요합니다" "$C $(err_of)"
expect "닫힌 창구의 토큰 사용 기록 0(auth=api_token 행 0 — 헤더를 읽지 않았다)" "0" \
  "$(psqlq "SELECT count(*) FROM audit_logs WHERE id > $A3 AND detail->>'auth' = 'api_token'")"
expect "그 페이지는 그대로(지워지지 않고 제목 그대로)" "QF8 토큰 글 true" "$(psqlq "SELECT title || ' ' || (deleted_at IS NULL) FROM community.pages WHERE public_id = CAST('${PID_T:-00000000-0000-0000-0000-000000000000}' AS uuid)")"
printf 'qf8\n' > "$WORK/up.txt"
C=$(curl_hdr_secret X-API-Token TOK_CW -s -o "$WORK/r.json" -w '%{http_code}' -A "$UA" -F "file=@$WORK/up.txt" -F "level=internal" "$BASE/api/upload")
expect "community.write 토큰으로 t-box POST /tbox/api/upload → 403 token_scope" "403 이 토큰의 범위에 없는 창구입니다 — 범위가 더 넓은 토큰을 발급하세요" "$C $(err_of)"
C=$(treq TOK_CW GET "$BASE/api/files");                    expect "community.write 토큰으로 t-box GET /tbox/api/files → 403 token_scope" "403" "$C"
C=$(treq TOK_CW GET "$BASE/api/whoami")
expect "community.write 토큰으로 GET /tbox/api/whoami → 200 claude_employee01(조각 C 의 토큰 확인)" "200 claude_employee01" "$C $(jf "$WORK/r.json" "d.get('username','')")"

# ════════════════════════════════════════════════════════════════
hr "T-6 세션 불변 — 헤더 없는 요청을 기준 판($BASE_REV · $BSITE)과 견준다"
login claude_employee01 "$BSITE" || { echo "  ✖ 기준 판 로그인 실패"; exit 4; }
# 같은 쿠키 이름(__Host-)이라 잼 파일을 인스턴스마다 따로 둔다 — 기준 판 로그인은 방금 새 잼에 덮어썼으므로 새 코드 쪽은 다시 로그인
mv "$JAR_DIR/claude_employee01.jar" "$JAR_DIR/base_e1.jar"
login claude_employee01 || { echo "  ✖ 재로그인 실패"; exit 4; }
shape() {   # $1 파일 → 정렬한 키 모양(값이 바뀌는 시각·수는 빼고 모양만)
  python3 -c '
import json,sys
def sh(v):
    if isinstance(v, dict): return {k: sh(x) for k, x in sorted(v.items())}
    if isinstance(v, list): return [sh(v[0])] if v else []
    return type(v).__name__
try: print(json.dumps(sh(json.load(open(sys.argv[1], encoding="utf-8"))), sort_keys=True))
except Exception: print(open(sys.argv[1], encoding="utf-8", errors="replace").read()[:200])
' "$1"
}
bcurl() {   # 기준 판 — $1 메서드 · $2 모듈 경로 · $3 본문 파일 · $4 csrf 1/0
  local csrf=''
  local copts=(-s -o "$WORK/b.json" -w '%{http_code}' -b "$JAR_DIR/base_e1.jar" -A "$UA" -X "$1" -H 'Expect:')
  [ -n "${3:-}" ] && copts+=(-H 'Content-Type: application/json' --data-binary @"$3")
  if [ "${4:-0}" = "1" ]; then
    csrf=$(curl -s -b "$JAR_DIR/base_e1.jar" -A "$UA" "$BSITE/tbox/api/me" | python3 -c 'import json,sys; print(json.load(sys.stdin).get("csrf_token",""))' 2>/dev/null)
    printf 'X-CSRF-Token: %s\n' "$csrf" | curl -H @- "${copts[@]}" "$BSITE$2"
  else curl "${copts[@]}" "$BSITE$2"; fi
}
cmp_one() {   # $1 설명 · $2 메서드 · $3 경로 · $4 본문 · $5 csrf
  local nc bc bs ns
  nc=$(sreq claude_employee01 "$2" "$SITE$3" "${4:-}" "${5:-0}")
  bc=$(bcurl "$2" "$3" "${4:-}" "${5:-0}")
  bs=$(shape "$WORK/b.json"); ns=$(shape "$WORK/r.json")
  # 둘 다 빈 값이면(서버가 답하지 않음) 같다고 통과시키지 않는다(R23)
  [ -n "$bc" ] && [ -n "$bs" ] || { bc='기준 판 응답 없음'; bs='기준 판 응답 없음'; }
  expect "$1 — 상태 코드 새=기준" "$bc" "$nc"
  expect "$1 — 본문 모양 새=기준" "$bs" "$ns"
}
cmp_one "세션 GET /community/api/me/space"            GET  "/community/api/me/space"
expect "  그 번호도 같다" "$(jf "$WORK/b.json" "d['space']['id']")" "$(jf "$WORK/r.json" "d['space']['id']")"
cmp_one "세션 GET /community/api/pages/{토큰으로 만든 글}" GET  "/community/api/pages/$PID_T"
cmp_one "세션 GET /community/api/pages/{남의 비공개 글}"   GET  "/community/api/pages/$E2_PAGE"
cmp_one "세션 POST /community/api/pages CSRF 없음"       POST "/community/api/pages" "$WORK/doc.json" 0
expect "  그 답은 403" "403" "$(sreq claude_employee01 POST "$CBASE/api/pages" "$WORK/doc.json" 0)"
python3 -c 'import json,sys; json.dump({"title":"QF8 세션 글","space_id":int(sys.argv[1])}, open(sys.argv[2],"w"))' "${TSPACE:-1}" "$WORK/sess.json"
cmp_one "세션 POST /community/api/pages {title, space_id} CSRF 있음(세션은 space_id 를 받는다)" POST "/community/api/pages" "$WORK/sess.json" 1
expect "  새 코드 쪽 201 · personal · private" "201 personal private" "$(sreq claude_employee01 POST "$CBASE/api/pages" "$WORK/sess.json" 1) $(jf "$WORK/r.json" "d['page']['space']['kind'] + ' ' + d['page']['visibility']")"
cmp_one "세션 GET /community/api/pages(목록)"          GET  "/community/api/pages"
C=$(curl -s -o "$WORK/r.json" -w '%{http_code}' -A "$UA" "$CBASE/api/me/space"); B=$(curl -s -o "$WORK/b.json" -w '%{http_code}' -A "$UA" "$BSITE/community/api/me/space")
expect "쿠키·헤더 없음 GET /community/api/me/space — 새=기준(401)" "$B $(jf "$WORK/b.json" "d.get('error','')")" "$C $(err_of)"
C=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -A "$UA" "$CBASE/"); B=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -A "$UA" "$BSITE/community/")
expect "쿠키 없음 화면 /community/ — 새=기준(302 로그인)" "${B//$BPORT/PORT}" "${C//$PORT/PORT}"

# ── 회수 (T-4 마지막) — 세션으로 회수한 뒤 그 토큰은 401 ──
C=$(sreq claude_employee01 DELETE "$BASE/api/me/api-tokens/${CW_ID:-0}" "" 1)
expect "세션으로 community.write 토큰 회수 → 200" "200" "$C"
C=$(treq TOK_CW GET "$CBASE/api/pages/$PID_T")
expect "회수된 토큰 → 401(틀린 토큰과 같은 문구)" "401 API 토큰이 올바르지 않습니다" "$C $(err_of)"
# 만료시킨 read 토큰도 회수한다 — 살아 있는(회수 안 된) 토큰 수는 계정당 상한 5 에 든다(회귀 f66 의 발급이 막히지 않게)
C=$(sreq claude_employee01 DELETE "$BASE/api/me/api-tokens/${R_ID:-0}" "" 1)
expect "시험이 만든 read 토큰 회수 → 200 · 이 시험이 남긴 살아 있는 토큰 0" "200 0" \
  "$C $(psqlq "SELECT count(*) FROM api_tokens WHERE revoked_at IS NULL AND name LIKE 'QF8-%'")"

if [ "${REGRESS:-0}" = "1" ]; then
  hr "회귀 — 같은 임시 인스턴스·임시 DB 에서(ux_r1_ui.sh 의 회귀 틀 · 8082 → $SITE · tbox_re → $DB)"
  # stage5a2 는 스스로 임시 인스턴스를 띄운다 — 포트 8085 → $R5A2 · 임시 DB 이름 → tbox_qf8_5a2_test(이 조각의 접두)
  for s in ${REGRESS_LIST:-api_whoami community/stage3 community/stage5a2 f66}; do
    src="$INST/app/tests/$s.sh"; dst="$INST/app/tests/$s.temp.sh"; rlog="$WORK/regress_$(basename "$s").txt"
    [ -f "$src" ] || { echo "  ✖ $s — 스크립트가 없다"; FAIL=$((FAIL+1)); continue; }
    sed -e "s#http://127.0.0.1:8082#$SITE#g" -e "s#^DB=\"tbox_re\"#DB=\"$DB\"#" -e "s#-d tbox_re #-d $DB #g" \
        -e "s#^PORT=8085\$#PORT=$R5A2#" -e 's#^DB="tbox_5a2_test" #DB="tbox_qf8_5a2_test" #' "$src" > "$dst"
    # 대상을 정하는 줄(SITE·BASE·CBASE·DB·PORT 대입)과 `-d tbox_re ` 만 본다 — SRC_DB="tbox_re"(stage5a2 가 pg_dump 로
    # **읽기만** 하는 원본)와 그 시험 안 회귀 절의 안내 글자(8082 를 가리키는 문장)는 대상이 아니다
    if grep -qE '^(SITE|BASE|CBASE|DB|PORT)=.*(8082|"tbox_re"|8085)' "$dst" || grep -v '^\s*#' "$dst" | grep -qE -- '-d tbox_re '; then
      echo "  ✖ $s — 운영 주소·DB 가 남아 있어 돌리지 않는다"; FAIL=$((FAIL+1)); continue
    fi
    sleep $((31 - $(date +%s) % 30))
    echo "  ▸ $s (시작 $(date +%T))"
    REGRESS=0 SHOTS="$WORK/shots" bash "$dst" > "$rlog" 2>&1   # 사본 안의 회귀는 켜지 않는다(stage5a2 는 REGRESS=1 이면 스스로 회귀를 돈다)
    rc=$?
    echo "    종료 코드 $rc · $(grep -E '통과 [0-9]+|PASS|통과:|결과|합계' "$rlog" | tail -1)"
    grep -E '^\s*(✖|FAIL)' "$rlog" | head -20 | sed 's/^/      /'
    [ -n "${REGRESS_KEEP:-}" ] && cp "$rlog" "$REGRESS_KEEP/"
    [ "$rc" = "0" ] && PASS=$((PASS+1)) || FAIL=$((FAIL+1))
  done
fi

hr "서버 로그"
expect "새 코드 인스턴스 로그의 PHP 오류(Fatal·Warning·Notice·Deprecated) 0" "0" "$(grep -cE 'PHP (Fatal|Warning|Notice|Deprecated|Parse)' "$INST/server.log")"
expect "새 코드 인스턴스 로그의 잡히지 않은 예외 0" "0" "$(grep -c 'uncaught ' "$INST/server.log")"
expect "이 시험이 부른 주소는 임시 인스턴스뿐(127.0.0.1:8401~8405)" "1 1" \
  "$(case "$SITE" in http://127.0.0.1:840[1-5]) echo 1 ;; *) echo 0 ;; esac) $(case "$BSITE" in http://127.0.0.1:840[1-5]) echo 1 ;; *) echo 0 ;; esac)"
TOK_CW=''; TOK_R=''; SHARED_TOK=''; TOK_G=''; TOK_X=''

printf '\n통과 %d · 실패 %d\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ]
