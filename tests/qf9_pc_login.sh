#!/usr/bin/env bash
# tests/qf9_pc_login.sh — Q:F-9 조각 S 수락 시험 T-1 ~ T-7 (등록 PC 에서 토큰으로 바로 로그인 — 「이 PC 로그인 전용」 토큰 ·
#                         30초·1회용 링크 · 토큰 출신 세션의 재인증·유휴 10분)
#                         (지시서 docs/qf9-pc-login-directive.md 「조각 S」 · ARCHITECTURE 4.9 · API.md 20장 · SECURITY R-113)
#
#   T-7 정적   라우트 둘 · 토큰 허용 표 셋에 링크 창구 · IP 는 Http::clientIp() 로만 · Q:F-9 커밋이 Http·Db·community 를 고치지 않음 · deploy 는 nginx session/open 덩이 하나 ·
#              security_lint 0 · 바뀐 PHP php -l · app.js node --check · tokenScopeAllows 표(범위 다섯 × 정책) ·
#              토큰 세션 재인증 라우트 표의 줄이 전부 실제 라우트 · 마이그레이션 UPDATE·DELETE·TRUNCATE 0
#   준비       임시 DB `tbox_qf9_<포트>_test`(tbox_testdb · 운영 사본 tbox_re 는 pg_dump 로 **읽기만**) · 마이그레이션 2026-10-12 **두 번**
#              → 같은 모양 · session_links 에 code 열 0 · 임시 인스턴스 둘(새 코드 <포트> · 기준 판 <포트+1>)
#   T-1 발급   claude_employee01 token_scopes 끝에 session.open · 발급 201 · bound_ip=127.0.0.1 · 두 번째 발급 → revoked_previous=1 ·
#              옛 토큰으로 링크 401 · Guest 400 · 2단계 없는 계정(있으면) 400
#   T-2 링크   POST /api/me/session-link 201 · url 모양 · DB 에 해시만 · 헤더 없음(세션 쿠키) 401 · 6번째 429 · 다른 범위(read·community.write)
#              403 token_scope · 공용(.env) 토큰 403 · 이 토큰으로 파일·커뮤니티 창구 403 · whoami 200
#   T-3 교환   GET url → 302 /tbox/ + 쿠키 → GET /api/me 200(같은 사람) · 세션 행 origin=token · 같은 url 다시 → 302 login ·
#              31초 뒤 → 302 login · 모양 틀린·없는 코드 → 302 login(감사 0) · to= 허용 목록만 · 동시 두 번 중 하나만 · 감사 auth=token_session
#   T-4 IP     bound_ip 를 임시 DB 에서 바꾼 뒤 링크 → 403 ip_mismatch ×3 + 감사 deny → alerts.php --dry-run 에 AL-24 한 줄 ·
#              링크를 받은 IP 를 바꾼 뒤 교환 → 302 login + 감사 ip_mismatch · 묶인 IP 가 바뀌면 이미 연 토큰 세션도 401
#   T-5 제한   토큰 세션: GET /api/files 200 · DELETE 파일 403 next:reauth(같은 임시 DB 의 일반 세션은 200) · 토큰 목록·발급·패스키 ·
#              관리 창구(관리자 토큰 세션) · 커뮤니티 지우기·옮기기 → reauth · /api/me/reauth 뒤 200 · 유휴 11분 → 401(일반 세션은 200) ·
#              토큰을 회수하면 그 토큰 세션도 401
#   T-6 불변   일반 로그인(비밀번호+OTP) 흐름·세션·재인증을 기준 판 인스턴스와 대조(대표 요청 — 상태 코드·본문 모양) · origin 기본값 'login'
#   T-7 끝     코드·토큰 원문이 DB(감사·세션·링크)·감사 파일·앱 로그에 0
#   서버 로그  임시 인스턴스 로그에 PHP 오류 0
#
# ★ 운영 DB·운영 포트를 건드리지 않는다 — 임시 DB 만 쓴다(만들고 지움) · 포트 8421~8425(QF9_PORT) · 검토 계정 claude_ 다섯(F-71) ·
#   UA `tbox-review/<날짜>` · 비밀(비밀번호·TOTP·토큰·CSRF·링크 주소)은 표준입력으로만(R-62 · tests/lib/secrets.sh).
#   임시 .env 는 운영 .env 를 mktemp 폴더(0700)에 0600 으로 복사해 DB_NAME·STORAGE_DIR·AUDIT_LOG_FILE 을 임시로, 공용 토큰을
#   이 시험이 만든 난수(claude_employee01 귀속)로 바꾼다. 끝에 지운다. 시험용 토큰 둘(read · community.write)과 관리자의 로그인 전용
#   토큰 하나는 **임시 DB 에 직접** 넣는다(TOTP 창 기다림을 줄인다 — 발급 창구 자체는 T-1 이 두 번 부른다).
# 쓰는 법: bash tests/qf9_pc_login.sh     환경 QF9_PORT(8421~8424 · 기본 8421 — 기준 판은 +1 · 쓰는 포트 전부 8421~8425 안) · QF9_BASE_REV(기본 b7f6da4 — v2.36.0)
#          REGRESS=1 이면 같은 임시 인스턴스에서 REGRESS_LIST(기본 api_whoami f66)를 사본으로(8082 → 이 인스턴스 · tbox_re → 임시 DB) ·
#          REGRESS_KEEP=<폴더> 면 결과 사본
set -u

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
. "$ROOT/tests/lib/review_accounts.sh" || { echo "tests/lib/review_accounts.sh 를 읽지 못했다 — 시험을 시작하지 않는다"; exit 3; }
. "$ROOT/tests/lib/secrets.sh" || { echo "tests/lib/secrets.sh 를 읽지 못했다 — 시험을 시작하지 않는다 (R-62)"; exit 3; }

PORT="${QF9_PORT:-8421}"
case "$PORT" in 8421|8422|8423|8424) ;; *) echo "이 조각은 포트 8421~8425 만 쓴다(새 코드 8421~8424 · 기준 판 +1) — $PORT"; exit 3 ;; esac
BPORT=$((PORT + 1))
SITE="http://127.0.0.1:$PORT"
BSITE="http://127.0.0.1:$BPORT"
BASE="$SITE/tbox"
CBASE="$SITE/community"
UA="tbox-review/$(date +%F)"
SRC_DB="tbox_re"                      # 운영 사본 — pg_dump 로 **읽기만**
DB="tbox_qf9_${PORT}_test"            # 임시 — 여기에만 쓴다
BASE_REV="${QF9_BASE_REV:-b7f6da4}"   # 기준 판(v2.36.0 — 이 조각의 출발점)
MIG="$ROOT/db/migrations/2026-10-12_session_open.sql"
PGP_TEST="$HOME/.config/qaredi/pgpass-testdb"
JAR_DIR="$(mktemp -d)"
WORK="$(mktemp -d)"
chmod 700 "$JAR_DIR" "$WORK"
INST=""; BINST=""; SPID=""; BPID=""; ENV_TMP=""
PASS=0
FAIL=0
SEEN_CODES="$WORK/.codes"             # 이 시험이 받은 링크 코드(끝의 「원문 0」 대조용 — 0600 · 끝에 지움)
SEEN_TOKS="$WORK/.toks"               # 이 시험이 받은·만든 토큰 원문(같은 대조용)
( umask 077; : > "$SEEN_CODES"; : > "$SEEN_TOKS" )

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

login() {   # $1 계정 · $2 사이트(기본 새 코드) · $3 잼 이름(기본 계정) — 쿠키는 $JAR_DIR/<잼>.jar
  local who="$1" site="${2:-$SITE}" jar="$JAR_DIR/${3:-$1}.jar" pw sec raw code left out try body
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
# CSRF — $1 잼 이름 · $2 사이트(기본 새 코드)
csrf_of() { curl -s -b "$JAR_DIR/$1.jar" -A "$UA" "${2:-$SITE}/tbox/api/me" | python3 -c 'import json,sys; print(json.load(sys.stdin).get("csrf_token",""))' 2>/dev/null; }

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

# 토큰 발급(세션 · 비밀번호+코드) — 결과는 전역: ISSUED_CODE · ISSUED_ID · ISSUED_SCOPE · ISSUED_BIP · ISSUED_REVPREV · 원문은 $2 이름의 변수에
ISSUED_CODE=''; ISSUED_ID=''; ISSUED_SCOPE=''; ISSUED_BIP=''; ISSUED_REVPREV=''
issue_as() {   # $1 계정 · $2 받을 변수 이름 · $3 범위 · $4 코드("next" 면 다음 창 코드, 그 밖은 그 글자 그대로)
  local who="$1" var="$2" scope="$3" otp="$4" pw body csrf tok hf
  ISSUED_CODE=''; ISSUED_ID=''; ISSUED_SCOPE=''; ISSUED_BIP=''; ISSUED_REVPREV=''
  printf -v "$var" '%s' ''
  if [ "$otp" = "next" ]; then otp_next "$who" || { ISSUED_CODE='코드없음'; return 0; }; otp="$NEXT_OTP"; fi
  pw=$(review_password "$who"); [ -n "$pw" ] || { ISSUED_CODE='비밀번호없음'; return 0; }
  body=$(py_secret pw -- python3 -c 'import json,sys; pw=sys.stdin.readline().rstrip("\n"); print(json.dumps({"name":sys.argv[1],"purpose":sys.argv[2],"scope":sys.argv[3],"password":pw,"otp":sys.argv[4]}))' \
         "QF9-$scope" "Q:F-9 수락 시험(tests/qf9_pc_login.sh)" "$scope" "$otp")
  pw=''
  csrf="X-CSRF-Token: $(csrf_of "$who")"
  hf=$(secret_file csrf "$WORK") || { ISSUED_CODE='머리파일없음'; return 0; }
  ISSUED_CODE=$(curl_json_secret body -s -o "$WORK/issue.json" -w '%{http_code}' -X POST \
         -b "$JAR_DIR/$who.jar" -A "$UA" -H @"$hf" -H 'Content-Type: application/json' "$BASE/api/me/api-tokens")
  rm -f "$hf"; body=''; csrf=''
  if [ "$ISSUED_CODE" = "201" ]; then
    tok=$(jf "$WORK/issue.json" "d.get('token','')")
    printf -v "$var" '%s' "$tok"; printf '%s\n' "$tok" >> "$SEEN_TOKS"; tok=''
    ISSUED_ID=$(jf "$WORK/issue.json" "d.get('id','')")
    ISSUED_SCOPE=$(jf "$WORK/issue.json" "d.get('scope','')")
    ISSUED_BIP=$(jf "$WORK/issue.json" "d.get('bound_ip','')")
    ISSUED_REVPREV=$(jf "$WORK/issue.json" "d.get('revoked_previous','')")
  fi
  : > "$WORK/issue.json"      # 토큰이 든 응답 파일은 곧바로 비운다
}

# 임시 DB 에 토큰 한 줄을 직접 — $1 받을 변수 · $2 계정 · $3 범위 · $4 묶인 IP(없으면 빈 글자). 행 번호는 전역 MADE_ID
MADE_ID=''
make_token() {
  local var="$1" who="$2" scope="$3" bip="$4" plain hash
  plain=$(head -c 64 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 48)
  hash=$(printf '%s' "$plain" | sha256sum | cut -d' ' -f1)
  MADE_ID=$(psqlq "INSERT INTO api_tokens (user_id, name, token_hash, hint, created_by, reason_text, scope, expires_at, bound_ip)
                   SELECT id, 'QF9-db-$scope', '$hash', '${plain:0:6}', id, 'Q:F-9 수락 시험(임시 DB 직접)', '$scope', now() + interval '1 day',
                          $( [ -n "$bip" ] && printf "CAST('%s' AS inet)" "$bip" || printf 'NULL' )
                     FROM users WHERE username = '$who' RETURNING id")
  printf -v "$var" '%s' "$plain"; printf '%s\n' "$plain" >> "$SEEN_TOKS"; plain=''
}

# 토큰 요청 — $1 토큰 변수 · $2 메서드 · $3 주소 · $4 본문 파일(선택) · $5 세션 잼(선택 — 헤더와 함께 쿠키도 싣는다). 본문은 $WORK/r.json
treq() {
  local v="$1" m="$2" u="$3" f="${4:-}" jar="${5:-}"
  local copts=(-s -o "$WORK/r.json" -w '%{http_code}' -A "$UA" -X "$m" -H 'Expect:')
  [ -n "$f" ] && copts+=(-H 'Content-Type: application/json' --data-binary @"$f")
  [ -n "$jar" ] && copts+=(-b "$JAR_DIR/$jar.jar")
  curl_hdr_secret X-API-Token "$v" "${copts[@]}" "$u"
}
# 세션 요청 — $1 잼 · $2 메서드 · $3 주소 · $4 본문 파일(선택) · $5 csrf 1/0(기본 1) · $6 사이트(csrf 를 받을 곳, 기본 새 코드)
sreq() {
  local jar="$1" m="$2" u="$3" f="${4:-}" withc="${5:-1}" site="${6:-$SITE}" csrf=''
  local copts=(-s -o "$WORK/r.json" -w '%{http_code}' -b "$JAR_DIR/$jar.jar" -A "$UA" -X "$m" -H 'Expect:')
  [ -n "$f" ] && copts+=(-H 'Content-Type: application/json' --data-binary @"$f")
  if [ "$withc" = "1" ]; then
    csrf=$(csrf_of "$jar" "$site")
    printf 'X-CSRF-Token: %s\n' "$csrf" | curl -H @- "${copts[@]}" "$u"
  else
    curl "${copts[@]}" "$u"
  fi
}
err_of()    { jf "$WORK/r.json" "d.get('error','')"; }
next_of()   { jf "$WORK/r.json" "d.get('next','')"; }
code_of()   { jf "$WORK/r.json" "d.get('code','')"; }
maxaudit()  { psqlq "SELECT coalesce(max(id),0) FROM audit_logs"; }

# 링크 받기 — $1 토큰 변수. 전역 LINK_STATUS(상태 코드) · 성공이면 LINK_URL(서버가 준 주소) · LINK_LOCAL(같은 경로를 이 임시
#   인스턴스로) · LINK_CODE. ★ 전역에 쓰므로 $( ) 안에서 부르지 않는다(하위 셸이면 값이 사라진다).
LINK_URL=''; LINK_LOCAL=''; LINK_CODE=''; LINK_STATUS=''
get_link() {
  LINK_URL=''; LINK_LOCAL=''; LINK_CODE=''
  LINK_STATUS=$(treq "$1" POST "$BASE/api/me/session-link")
  if [ "$LINK_STATUS" = "201" ]; then
    LINK_URL=$(jf "$WORK/r.json" "d.get('url','')")
    LINK_CODE=${LINK_URL##*code=}
    LINK_LOCAL="$SITE/tbox/session/open?code=$LINK_CODE"
    printf '%s\n' "$LINK_CODE" >> "$SEEN_CODES"
    : > "$WORK/r.json"
  fi
}
# 링크 열기 — $1 주소 변수 · $2 잼 이름(쿠키를 받는다). 「상태 경로」 를 찍는다(주소의 호스트·포트는 뗀다)
open_link() {
  local out
  out=$(curl_url_secret "$1" -s -o /dev/null -c "$JAR_DIR/$2.jar" -A "$UA" -w '%{http_code} %{redirect_url}')
  printf '%s' "${out/$SITE/}"
}
code_hash() { printf '%s' "$1" | sha256sum | cut -d' ' -f1; }

make_env() {   # $1 원본 $2 대상 $3 저장 폴더 $4 감사 파일 $5 공용 토큰 파일. 값은 출력하지 않는다.
  # qcheck-allow: R20 — 임시 인스턴스용 사본(0700 폴더 · 0600 · trap 정리) · kill -9 뒤 남는 창은 받아들인 위험(HO-B1-004 · qf8_token_write.sh make_env 와 같은 판단)
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
  # 워커 넷 — 1회용 링크의 동시 교환을 진짜 동시로 본다(검토 RV-QF9-7 · php -S 단일 워커면 직렬이 된다)
  ( cd "$1/app" && PHP_CLI_SERVER_WORKERS=4 exec php -S "127.0.0.1:$2" -t public router.php > "$1/server.log" 2>&1 ) &
  LAST_PID=$!
  local c="" i
  for i in $(seq 1 30); do sleep 0.3; c=$(curl -s -o /dev/null -w '%{http_code}' -A "$UA" "http://127.0.0.1:$2/tbox/login"); [ "$c" = "200" ] && break; done
  [ "$c" = "200" ]
}

# ════════════════════════════════════════════════════════════════
hr "T-7 정적 (저장소 — 기준 판 $BASE_REV 대비)"
IX="$ROOT/public/index.php"
expect "라우트 둘 — POST /api/me/session-link(session.open) · GET /session/open(public)" "1 1" \
  "$(grep -cE "^\s*\['POST',\s*'/api/me/session-link',\s*'me_session_link.php',\s*'session.open'\]," "$IX") $(grep -cE "^\s*\['GET',\s*'/session/open',\s*'session_open_page.php',\s*Authz::POLICY_PUBLIC\]," "$IX")"
tset() { sed -n "/^const $1 = \[\$/,/^\];\$/p" "$IX" | grep -c "^    'POST /api/me/session-link',\$"; }
expect "토큰 허용 표 셋(ROUTES · ONLY · PERSONAL)에 링크 창구 한 줄씩" "1 1 1" \
  "$(tset TBOX_API_TOKEN_ROUTES) $(tset TBOX_API_TOKEN_ONLY_ROUTES) $(tset TBOX_API_TOKEN_PERSONAL_ROUTES)"
expect "재인증 관문이 세션 출신·라우트를 넘긴다(Authz::requiresReauth 3인자 한 곳)" "1" \
  "$(grep -c "Authz::requiresReauth(\$policy, (string) (\$user\['session_origin'\] ?? 'login'), TBOX_ROUTE)" "$IX")"
NEWF="src/handlers/me_session_link.php src/handlers/session_open_page.php src/handlers/me_tokens_issue.php src/Auth.php"
expect "IP 를 요청 값에서 읽는 곳 0(REMOTE_ADDR·X-Forwarded·HTTP_X_REAL 직접 — 바뀐 파일)" "0" \
  "$(cd "$ROOT" && cat $NEWF | grep -v '^\s*\*\|^\s*//' | grep -cE "REMOTE_ADDR|HTTP_X_FORWARDED|X-Forwarded-For|HTTP_X_REAL_IP|HTTP_CLIENT_IP")"
codeln() { grep -v '^\s*\*\|^\s*//\|^\s*/\*' "$1" | grep -c "$2"; }
# Q:F-10(2026-09-29) — 링크 창구의 IP 비교는 토큰 조회 한 곳(Auth::requireApiToken 의 SQL_FIND_API_TOKEN)으로 옮겼다(링크 창구 2→1 · Auth 5→6)
expect "IP 는 Http::clientIp() 로만 — 코드 줄 수(링크 창구 1(링크 INSERT) · 발급 1 · Auth: 세션 조회·링크 조회 둘(교환·이미 로그인)·세션 두 INSERT·토큰 조회 여섯)" "1 1 6" \
  "$(codeln "$ROOT/src/handlers/me_session_link.php" 'Http::clientIp()') $(codeln "$ROOT/src/handlers/me_tokens_issue.php" 'Http::clientIp()') $(codeln "$ROOT/src/Auth.php" 'Http::clientIp()')"
# 이 조각(Q:F-9 커밋 1223715)이 고치지 않은 파일 — 커밋 그 자체로 본다(뒤 조각 Q:F-10 이 src/community 를 고쳤다 · 지금 트리로 보면 헛실패).
#   deploy 는 Q:F-9 커밋이 nginx `location = /tbox/session/open` 한 덩이(12줄)를 함께 넣었다(메인 결정 — 커밋 제목 「nginx session/open 창구」) — 그 덩이만 허용.
expect "Q:F-9 커밋이 고치지 않은 파일 차이 0 (Http · Db · src/community)" "0" \
  "$(git -C "$ROOT" diff "$BASE_REV" 1223715 -- src/Http.php src/Db.php src/community 2>/dev/null | wc -l)"
expect "Q:F-9 커밋의 deploy 차이 = nginx session/open 덩이 하나(빈 줄 뺀 더한 줄 11 · 지운 줄 0 · location 한 줄)" "11 0 1" \
  "$(git -C "$ROOT" diff "$BASE_REV" 1223715 -- deploy 2>/dev/null | grep -c '^+[^+]') $(git -C "$ROOT" diff "$BASE_REV" 1223715 -- deploy 2>/dev/null | grep -c '^-[^-]') $(git -C "$ROOT" diff "$BASE_REV" 1223715 -- deploy 2>/dev/null | grep -c '^+    location = /tbox/session/open {$')"
expect "지금 트리 — Http · Db 차이 0 · deploy 는 Q:F-9 커밋 그대로" "0 0" \
  "$(git -C "$ROOT" diff "$BASE_REV" -- src/Http.php src/Db.php 2>/dev/null | wc -l) $(git -C "$ROOT" diff 1223715 -- deploy ':(exclude)deploy/DEPENDENCIES.md' 2>/dev/null | wc -l)"
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
AZ=$(php -r '
require $argv[1];
$scopes = ["read", "read+upload", "read+upload+delete", "community.write", "session.open"];   // 옛 다섯 값 — Q:F-10 원자 모델에서도 같은 답이어야 한다
$pols = ["authenticated", "file.read", "file.upload", "file.update", "file.delete", "community.read", "community.write", "session.open", "role:Admin", "file.fetch"];
foreach ($pols as $p) { $row = []; foreach ($scopes as $s) { $row[] = Authz::tokenScopeAllows($s, $p) ? "1" : "0"; } echo $p, "=", implode("", $row), " "; }
echo "ATOMS=", implode(",", Authz::TOKEN_SCOPE_ATOMS), " KNOWN=", in_array("session.open", Authz::KNOWN_POLICIES, true) ? 1 : 0;
' "$ROOT/src/Authz.php" 2>&1)
# Q:F-10 — 옛 상수 TOKEN_SCOPES 는 지웠다(원자 표 TOKEN_SCOPE_ATOMS 하나). 옛 다섯 값의 판정 표는 그대로다.
expect "tokenScopeAllows 표(옛 다섯 값 read · read+upload · read+upload+delete · community.write · session.open)" \
  "authenticated=11111 file.read=11100 file.upload=01100 file.update=01100 file.delete=00100 community.read=00010 community.write=00010 session.open=00001 role:Admin=00000 file.fetch=00000 ATOMS=read,upload,delete,community.read,community.write,session.open KNOWN=1" "$AZ"
# 토큰 세션 재인증 라우트 표 — 줄마다 실제 라우트인가(t-box 표 · 커뮤니티 표). 오타는 조용히 열린 창구가 된다
python3 - "$ROOT" > "$WORK/reauth_routes.txt" <<'PY'
import re, sys
root = sys.argv[1]
az = open(root + "/src/Authz.php", encoding="utf-8").read()
blk = az[az.index("public const TOKEN_SESSION_REAUTH_ROUTES = ["):]
blk = blk[:blk.index("];")]
wanted = re.findall(r"'((?:GET|POST|PUT|PATCH|DELETE) [^']+)'", blk)
ix = open(root + "/public/index.php", encoding="utf-8").read()
ix = ix[ix.index("const TBOX_ROUTES = ["):]; ix = ix[:ix.index("\n];")]
cr = open(root + "/src/community/routes.php", encoding="utf-8").read()
cr = cr[cr.index("const COMMUNITY_ROUTES = ["):]; cr = cr[:cr.index("\n];")]
have = set(m + " " + p for m, p in re.findall(r"\['(GET|POST|PUT|PATCH|DELETE)',\s*'([^']+)'", ix))
have |= set(m + " /community" + p for m, p in re.findall(r"\['(GET|POST|PUT|PATCH|DELETE)',\s*'([^']+)'", cr))
bad = [w for w in wanted if w not in have]
print(len(wanted), len(bad), ";".join(bad))
PY
# 24줄 — 메인 판단(2026-09-29): 토큰 목록·패스키 목록 조회와 PATCH /api/me/settings 는 「변경」이 아니라 집합에서 뺐다(집합 정의 변경 — 시험 약화 아님)
expect "TOKEN_SESSION_REAUTH_ROUTES 줄 수 · 실제 라우트가 아닌 줄 0" "24 0 " "$(cat "$WORK/reauth_routes.txt")"
expect "재인증 창구·로그아웃은 토큰 세션 재인증 표 밖(묻는 창구가 막히면 들어갈 길이 없다)" "0" \
  "$(sed -n '/public const TOKEN_SESSION_REAUTH_ROUTES = \[/,/\];/p' "$ROOT/src/Authz.php" | grep -cE "'(POST /api/me/reauth|GET /reauth|POST /api/me/reauth/passkey|POST /api/me/reauth/passkey/options|POST /api/logout)'")"
expect "마이그레이션 — UPDATE·DELETE·TRUNCATE 0 · 되돌리기 절 · 트랜잭션 하나" "0 1 1 1" \
  "$(grep -v '^--' "$MIG" | grep -ciE '^\s*(UPDATE|DELETE|TRUNCATE)\b') $(grep -c '^-- 되돌리기' "$MIG") $(grep -c '^BEGIN;$' "$MIG") $(grep -c '^COMMIT;$' "$MIG")"
expect "db/schema.sql — session_links 에 code_hash · origin·token_id · bound_ip · 제약 이름 넷" "1 1 1 1 4" \
  "$(grep -c '^CREATE TABLE session_links' "$ROOT/db/schema.sql") $(grep -c "^    origin            TEXT        NOT NULL DEFAULT 'login'," "$ROOT/db/schema.sql") $(grep -c '^    bound_ip     INET,' "$ROOT/db/schema.sql") $(grep -c '^    code_hash   CHAR(64)    NOT NULL UNIQUE,' "$ROOT/db/schema.sql") $(grep -cE 'CONSTRAINT (api_tokens_scope_check|api_tokens_bound_ip_check|sessions_origin_check|sessions_token_id_fkey)$' "$ROOT/db/schema.sql")"

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
SHARED_TOK=$(cat "$ENV_TMP/shared"); printf '%s\n' "$SHARED_TOK" >> "$SEEN_TOKS"

tdb dropdb -h 127.0.0.1 -p 5432 -U tbox_testdb -w --if-exists "$DB" >/dev/null 2>&1
tdb createdb -h 127.0.0.1 -p 5432 -U tbox_testdb -w "$DB" >/dev/null 2>&1 || { echo "임시 DB 를 만들 수 없다(tbox_testdb)"; exit 3; }
( cd / && sudo -n -u postgres pg_dump --no-owner -d "$SRC_DB" 2>/dev/null ) \
  | tdb psql -h 127.0.0.1 -p 5432 -U tbox_testdb -w -X -q -d "$DB" -v ON_ERROR_STOP=0 >/dev/null 2>"$WORK/restore.err"
CNT=$(psqlq "SELECT (SELECT count(*) FROM users) || ' ' || (SELECT count(*) FROM sessions);")
[ -n "$CNT" ] && [ "${CNT%% *}" -gt 0 ] 2>/dev/null || { echo "임시 DB 로 옮기지 못했다 — $(head -2 "$WORK/restore.err")"; exit 3; }
note "임시 DB $DB — 사용자 ${CNT%% *} · 세션 ${CNT##* }"
SHAPE="SELECT string_agg(conname || '=' || pg_get_constraintdef(oid), ' | ' ORDER BY conname) FROM pg_constraint WHERE conname IN ('api_tokens_scope_check','api_tokens_bound_ip_check','sessions_origin_check','sessions_token_id_fkey','session_links_expiry')
       UNION ALL SELECT string_agg(table_name || '.' || column_name || ':' || data_type || ':' || is_nullable || ':' || coalesce(column_default,''), ' | ' ORDER BY table_name, column_name) FROM information_schema.columns WHERE table_schema = 'public' AND (table_name = 'session_links' OR (table_name = 'sessions' AND column_name IN ('origin','token_id')) OR (table_name = 'api_tokens' AND column_name = 'bound_ip'))
       UNION ALL SELECT string_agg(privilege_type, ',' ORDER BY privilege_type) FROM information_schema.role_table_grants WHERE table_name = 'session_links' AND grantee = 'tbox_app';"
M1=$(tdb psql -h 127.0.0.1 -p 5432 -U tbox_testdb -w -X -q -d "$DB" -v ON_ERROR_STOP=1 -f "$MIG" 2>&1 >/dev/null; echo "rc=$?")
S1=$(psqlq "$SHAPE")
M2=$(tdb psql -h 127.0.0.1 -p 5432 -U tbox_testdb -w -X -q -d "$DB" -v ON_ERROR_STOP=1 -f "$MIG" 2>&1 >/dev/null; echo "rc=$?")
S2=$(psqlq "$SHAPE")
expect "마이그레이션 첫 적용 rc" "rc=0" "$(printf '%s' "$M1" | tail -1)"
expect "마이그레이션 둘째 적용 rc(멱등)" "rc=0" "$(printf '%s' "$M2" | tail -1)"
expect "첫 적용과 둘째 적용의 결과(제약·열·권한)가 같다" "$S1" "$S2"
note "적용 뒤 모양: $(printf '%s' "$S2" | head -c 900)"
expect "session_links 의 열 — code 원문 칸 없음(code_hash 하나)" "code_hash,created_at,expires_at,id,ip,token_id,used_at,user_id" \
  "$(psqlq "SELECT string_agg(column_name, ',' ORDER BY column_name) FROM information_schema.columns WHERE table_name = 'session_links'")"
expect "tbox_app 의 session_links 권한(SELECT·INSERT·UPDATE·DELETE)" "DELETE,INSERT,SELECT,UPDATE" \
  "$(psqlq "SELECT string_agg(privilege_type, ',' ORDER BY privilege_type) FROM information_schema.role_table_grants WHERE table_name = 'session_links' AND grantee = 'tbox_app'")"
expect "기존 세션 행 전부 origin=login · token_id NULL(기본값) · 기존 토큰 bound_ip NULL" "0 0" \
  "$(psqlq "SELECT (SELECT count(*) FROM sessions WHERE origin <> 'login' OR token_id IS NOT NULL) || ' ' || (SELECT count(*) FROM api_tokens WHERE bound_ip IS NOT NULL)")"

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
note "임시 .env 확인: $(grep '^DB_NAME=' "$INST/app/.env") · APP_URL 모양 $(grep -c '^APP_URL=https\?://' "$INST/app/.env")줄"
start_inst "$INST" "$PORT"; OK1=$?; SPID=$LAST_PID
start_inst "$BINST" "$BPORT"; OK2=$?; BPID=$LAST_PID
expect "새 코드 인스턴스가 떴다($PORT)" "0" "$OK1"
expect "기준 판 인스턴스가 떴다($BPORT)" "0" "$OK2"
[ "$OK1" = "0" ] && [ "$OK2" = "0" ] || { tail -5 "$INST/server.log" "$BINST/server.log"; exit 4; }

hr "로그인 (claude_ 검토 계정, 순차, F-71)"
for who in claude_employee01 claude_employee02 claude_guest01; do
  login "$who" || { echo "  ✖ $who 로그인 실패 — 중단"; exit 4; }
done
note "세션 둘(claude_employee01 · claude_guest01)"

# ════════════════════════════════════════════════════════════════
hr "T-1 발급 — 범위 session.open (이 PC 로그인 전용)"
curl -s -b "$JAR_DIR/claude_employee01.jar" -A "$UA" "$BASE/api/me" > "$WORK/me.json"
expect "claude_employee01 token_scopes 끝에 session.open(2단계 켜짐 · 쓰기 자격)" "session.open True" \
  "$(jf "$WORK/me.json" "d.get('token_scopes',[])[-1]") $(jf "$WORK/me.json" "d.get('mfa_enabled')")"
curl -s -b "$JAR_DIR/claude_guest01.jar" -A "$UA" "$BASE/api/me" > "$WORK/gme.json"
expect "claude_guest01 token_scopes 에 session.open 없음" "0" "$(jf "$WORK/gme.json" "d.get('token_scopes',[]).count('session.open')")"
issue_as claude_guest01 TOK_G session.open 000000
expect "Guest 가 session.open 발급 → 400(허용 목록 밖 · 비밀번호 확인 전)" "400" "$ISSUED_CODE"
NOMFA=$(psqlq "SELECT u.username FROM users u WHERE u.username LIKE 'claude\\_%' AND u.is_active AND NOT u.mfa_enabled AND NOT u.must_change_password AND NOT u.mfa_setup_required AND u.role <> 'Guest' ORDER BY u.username LIMIT 1")
if [ -n "$NOMFA" ] && login "$NOMFA"; then
  issue_as "$NOMFA" TOK_N session.open 000000
  expect "2단계가 꺼진 계정($NOMFA)이 session.open 발급 → 400" "400" "$ISSUED_CODE"
else
  note "2단계가 꺼진 비Guest 검토 계정이 임시 DB 에 없다 — 이 항목은 정적 판정(Authz::canOpenSessionByToken = mfa_enabled && canUploadAtAll)으로만 본다"
  expect "canOpenSessionByToken — 2단계 꺼짐이면 거짓(함수 직접 · 매트릭스를 읽기 전에 끝난다)" "false" \
    "$(php -r 'require $argv[1]; echo var_export(Authz::canOpenSessionByToken(["mfa_enabled" => false, "role" => "Employee"]), true);' "$ROOT/src/Authz.php" 2>&1)"
fi
expect "위 거부는 실패 집계 0(claude_employee01 failed_count)" "0" "$(psqlq "SELECT failed_count FROM users WHERE username='claude_employee01'")"
A0=$(maxaudit)
issue_as claude_employee01 TOK_S1 session.open next
expect "claude_employee01 session.open 발급 → 201 · bound_ip 127.0.0.1 · 앞 토큰 회수 0" "201 session.open 127.0.0.1 0" "$ISSUED_CODE $ISSUED_SCOPE $ISSUED_BIP $ISSUED_REVPREV"
S1_ID="$ISSUED_ID"
expect "DB 행 — 범위 · bound_ip · 만료 180일" "session.open 127.0.0.1 180" \
  "$(psqlq "SELECT scope || ' ' || host(bound_ip) || ' ' || round(extract(epoch FROM expires_at - created_at) / 86400) FROM api_tokens WHERE id = ${S1_ID:-0}")"
expect "감사 api_token.issue allow — token_scope · bound_ip · 토큰 원문 없음" "1" \
  "$(psqlq "SELECT count(*) FROM audit_logs WHERE id > $A0 AND action = 'api_token.issue' AND result = 'allow' AND detail->>'token_scope' = 'session.open' AND detail->>'bound_ip' = '127.0.0.1' AND detail->>'token_id' = '${S1_ID:-0}'")"
get_link TOK_S1; C=$LINK_STATUS; expect "첫 토큰으로 링크 → 201(살아 있을 때)" "201" "$C"
A1=$(maxaudit)
issue_as claude_employee01 TOK_S session.open next
expect "두 번째 session.open 발급 → 201 · revoked_previous=1(1대 규칙)" "201 1" "$ISSUED_CODE $ISSUED_REVPREV"
S_ID="$ISSUED_ID"
expect "앞 토큰은 회수됨 · 살아 있는 session.open 한 개" "true 1" \
  "$(psqlq "SELECT (revoked_at IS NOT NULL)::text FROM api_tokens WHERE id = ${S1_ID:-0}") $(psqlq "SELECT count(*) FROM api_tokens t JOIN users u ON u.id = t.user_id WHERE u.username = 'claude_employee01' AND t.scope = 'session.open' AND t.revoked_at IS NULL")"
expect "감사 api_token.revoke allow replaced 한 행(앞 토큰 번호)" "1" \
  "$(psqlq "SELECT count(*) FROM audit_logs WHERE id > $A1 AND action = 'api_token.revoke' AND result = 'allow' AND reason = 'replaced' AND detail->>'token_id' = '${S1_ID:-0}'")"
C=$(treq TOK_S1 POST "$BASE/api/me/session-link")
expect "회수된 앞 토큰으로 링크 → 401(틀린 토큰과 같은 문구)" "401 API 토큰이 올바르지 않습니다" "$C $(err_of)"
[ -n "$TOK_S" ] || { echo "  ✖ 로그인 전용 토큰이 없어 뒤 항목을 돌릴 수 없다 — 중단"; exit 4; }

# ════════════════════════════════════════════════════════════════
hr "T-2 링크 — POST /api/me/session-link"
make_token TOK_R claude_employee01 read '';               R_ID="$MADE_ID"
make_token TOK_CW claude_employee01 community.write '';   CW_ID="$MADE_ID"
note "임시 DB 에 직접 넣은 시험 토큰: read #$R_ID · community.write #$CW_ID"
A2=$(maxaudit)
get_link TOK_S; C=$LINK_STATUS
expect "로그인 전용 토큰 → 201" "201" "$C"
expect "url 모양(APP_URL + /tbox/session/open?code=<43자>) · expires_in 30" "1 30" \
  "$(printf '%s' "$LINK_URL" | grep -cE '^https?://[A-Za-z0-9.-]+(:[0-9]+)?/tbox/session/open\?code=[A-Za-z0-9_-]{43}$') $(treq TOK_S POST "$BASE/api/me/session-link" >/dev/null; jf "$WORK/r.json" "d.get('expires_in','')")"
printf '%s\n' "$(jf "$WORK/r.json" "d.get('url','').split('code=')[-1]")" >> "$SEEN_CODES"; : > "$WORK/r.json"
L1_URL="$LINK_LOCAL"; L1_CODE="$LINK_CODE"
expect "DB 에는 코드의 sha256 만 · 받은 IP · 30초 · 미사용" "1 127.0.0.1 30 true" \
  "$(psqlq "SELECT count(*) FROM session_links WHERE code_hash = '$(code_hash "$L1_CODE")'") $(psqlq "SELECT host(ip) || ' ' || round(extract(epoch FROM expires_at - created_at)) || ' ' || (used_at IS NULL)::text FROM session_links WHERE code_hash = '$(code_hash "$L1_CODE")'")"
expect "감사 session.link allow 두 행 — auth=api_token · token_id · 행위자 claude_employee01" "2" \
  "$(psqlq "SELECT count(*) FROM audit_logs WHERE id > $A2 AND action = 'session.link' AND result = 'allow' AND detail->>'auth' = 'api_token' AND detail->>'token_id' = '${S_ID:-0}' AND actor_name = 'claude_employee01' AND user_agent = '$UA'")"
C=$(sreq claude_employee01 POST "$BASE/api/me/session-link" "" 1)
expect "헤더 없이 세션 쿠키+CSRF 로 → 401(토큰 전용 창구)" "401 X-API-Token 헤더가 없습니다" "$C $(err_of)"
C=$(treq TOK_R POST "$BASE/api/me/session-link")
expect "read 범위 토큰 → 403 token_scope" "403 이 토큰의 범위에 없는 창구입니다 — 범위가 더 넓은 토큰을 발급하세요" "$C $(err_of)"
C=$(treq TOK_CW POST "$BASE/api/me/session-link")
expect "community.write 범위 토큰 → 403 token_scope" "403" "$C"
C=$(treq SHARED_TOK POST "$BASE/api/me/session-link")
expect "공용(.env) 토큰 → 403 개인 토큰만" "403 이 창구는 개인 토큰으로만 쓸 수 있습니다" "$C $(err_of)"
BAD=0
for r in "GET $BASE/api/files" "GET $BASE/api/folders" "GET $BASE/api/files/00000000-0000-4000-8000-000000000000/download" "DELETE $BASE/api/files/00000000-0000-4000-8000-000000000000" "GET $CBASE/api/me/space" "GET $CBASE/api/pages/00000000-0000-4000-8000-000000000000"; do
  C=$(treq TOK_S ${r%% *} "${r#* }")
  [ "$C $(err_of)" = "403 이 토큰의 범위에 없는 창구입니다 — 범위가 더 넓은 토큰을 발급하세요" ] || { BAD=$((BAD+1)); echo "    ✖ $r → $C $(err_of)"; }
done
printf 'qf9\n' > "$WORK/up.txt"
C=$(curl_hdr_secret X-API-Token TOK_S -s -o "$WORK/r.json" -w '%{http_code}' -A "$UA" -F "file=@$WORK/up.txt" -F "level=internal" "$BASE/api/upload")
[ "$C" = "403" ] || { BAD=$((BAD+1)); echo "    ✖ POST /api/upload → $C"; }
printf '{"title":"qf9"}' > "$WORK/cp.json"
C=$(treq TOK_S POST "$CBASE/api/pages" "$WORK/cp.json")
[ "$C" = "403" ] || { BAD=$((BAD+1)); echo "    ✖ POST /community/api/pages → $C"; }
expect "로그인 전용 토큰으로 파일·커뮤니티 창구 여덟 → 전부 403 token_scope" "0" "$BAD"
C=$(treq TOK_S GET "$BASE/api/whoami")
expect "로그인 전용 토큰 GET /api/whoami → 200 claude_employee01(비서의 토큰 확인)" "200 claude_employee01" "$C $(jf "$WORK/r.json" "d.get('username','')")"

# ════════════════════════════════════════════════════════════════
hr "T-3 교환 — GET /tbox/session/open?code=…"
A3=$(maxaudit)
C=$(open_link L1_URL ts)
expect "링크 열기 → 302 /tbox/ + 세션 쿠키" "302 /tbox/ 1" "$C $(grep -c '__Host-tbox_session' "$JAR_DIR/ts.jar" 2>/dev/null)"
C=$(sreq ts GET "$BASE/api/me" "" 0)
expect "그 쿠키로 GET /api/me → 200 · 같은 사람" "200 claude_employee01" "$C $(jf "$WORK/r.json" "d.get('username','')")"
expect "세션 행 — origin=token · token_id · 재인증 창 없음 · IP" "token ${S_ID:-0} true 127.0.0.1" \
  "$(psqlq "SELECT origin || ' ' || token_id || ' ' || (reauth_at IS NULL)::text || ' ' || host(ip) FROM sessions WHERE token_id IS NOT NULL ORDER BY created_at DESC LIMIT 1")"
expect "링크 행은 소비됨(used_at)" "true" "$(psqlq "SELECT (used_at IS NOT NULL)::text FROM session_links WHERE code_hash = '$(code_hash "$L1_CODE")'")"
expect "감사 session.open allow — 행위자 = 토큰 주인 · auth=token_session · token_id · UA" "1" \
  "$(psqlq "SELECT count(*) FROM audit_logs WHERE id > $A3 AND action = 'session.open' AND result = 'allow' AND actor_name = 'claude_employee01' AND target_name = 'claude_employee01' AND detail->>'auth' = 'token_session' AND detail->>'token_id' = '${S_ID:-0}' AND detail->>'login_ref' IS NOT NULL AND user_agent = '$UA'")"
C=$(open_link L1_URL ts_again)
expect "같은 링크 다시 → 302 /tbox/login(1회용) · 쿠키 없음" "302 /tbox/login 0" "$C $(cat "$JAR_DIR/ts_again.jar" 2>/dev/null | grep -c '__Host-tbox_session')"
expect "감사 session.open deny link_used" "1" "$(psqlq "SELECT count(*) FROM audit_logs WHERE id > $A3 AND action = 'session.open' AND result = 'deny' AND reason = 'link_used' AND target_name = 'claude_employee01'")"
A3b=$(maxaudit)
BADF="$SITE/tbox/session/open?code=short"; UNK="$SITE/tbox/session/open?code=$(head -c 32 /dev/urandom | base64 | tr '+/' '-_' | tr -d '=')"
expect "모양 틀린 코드 · 없는 코드 · 코드 없음 → 302 /tbox/login · 감사 0" "302 /tbox/login|302 /tbox/login|302 /tbox/login|0" \
  "$(open_link BADF x1)|$(open_link UNK x2)|$(curl -s -o /dev/null -A "$UA" -w '%{http_code} %{redirect_url}' "$SITE/tbox/session/open" | sed "s#$SITE##")|$(psqlq "SELECT count(*) FROM audit_logs WHERE id > $A3b")"
# to= — 허용 목록(Http::returnPath)만
get_link TOK_S; L2="${LINK_LOCAL}&to=%2Ftbox%2Fsettings"
C=$(open_link L2 ts_to); expect "to=/tbox/settings(허용 목록) → 302 /tbox/settings" "302 /tbox/settings" "$C"
get_link TOK_S; L3="${LINK_LOCAL}&to=https%3A%2F%2Fevil.example%2F"
C=$(open_link L3 ts_to2); expect "to=https://evil.example/ → 302 /tbox/(허용 목록 밖은 첫 화면)" "302 /tbox/" "$C"
# 동시 넷 — 하나만(인스턴스 워커 넷 · 진짜 동시)
get_link TOK_S; L4="$LINK_LOCAL"
PIDS=""
for n in 1 2 3 4; do ( open_link L4 "par$n" > "$WORK/par$n.txt" ) & PIDS="$PIDS $!"; done
wait $PIDS
expect "같은 링크를 동시에 네 번(워커 넷) → /tbox/ 하나 · /tbox/login 셋" "1 3" \
  "$(cat "$WORK"/par[1-4].txt | grep -o '302 /tbox/[a-z]*' | grep -c '302 /tbox/$') $(cat "$WORK"/par[1-4].txt | grep -o '302 /tbox/[a-z]*' | grep -c '302 /tbox/login')"
# 링크 수 1분 5회 — 이 분에 이미 받은 것을 세고 6번째까지 채운다
N_NOW=$(psqlq "SELECT count(*) FROM session_links WHERE token_id = ${S_ID:-0} AND created_at > now() - interval '60 seconds'")
note "지난 60초 이 토큰의 링크 $N_NOW 개"
C=""; K=$N_NOW
while [ "$K" -lt 5 ]; do get_link TOK_S; C=$LINK_STATUS; K=$((K+1)); done
A3c=$(maxaudit)
C=$(treq TOK_S POST "$BASE/api/me/session-link")
expect "같은 토큰 1분 안 6번째 → 429 rate_limited" "429 rate_limited" "$C $(code_of)"
expect "감사 session.link deny rate_limited" "1" "$(psqlq "SELECT count(*) FROM audit_logs WHERE id > $A3c AND action = 'session.link' AND result = 'deny' AND reason = 'rate_limited'")"
# 속도 창을 비운다(임시 DB — 이 토큰의 링크 시각을 2분 앞으로. 30초 CHECK 는 두 칸을 함께 옮겨 지킨다)
psqlq "UPDATE session_links SET created_at = created_at - interval '2 minutes', expires_at = expires_at - interval '2 minutes' WHERE token_id = ${S_ID:-0}" >/dev/null
# 검토 RV-QF9-1 — 이미 로그인된 브라우저(claude_employee02)가 employee01 의 링크를 열면 갈아타지 않는다 · 링크는 그대로
get_link TOK_S; LX="$LINK_LOCAL"; LX_CODE="$LINK_CODE"
A3x=$(maxaudit)
E2_N0=$(psqlq "SELECT count(*) FROM sessions s JOIN users u ON u.id = s.user_id WHERE u.username = 'claude_employee02'")
C=$(curl_url_secret LX -s -o /dev/null -b "$JAR_DIR/claude_employee02.jar" -c "$JAR_DIR/claude_employee02.jar" -A "$UA" -w '%{http_code} %{redirect_url}')
expect "로그인된 브라우저가 남의 링크를 열면 → 302 /tbox/(지금 세션 그대로)" "302 /tbox/" "${C/$SITE/}"
C=$(sreq claude_employee02 GET "$BASE/api/me" "" 0)
expect "  그 브라우저는 여전히 claude_employee02 · 세션 행 수 그대로" "200 claude_employee02 $E2_N0" \
  "$C $(jf "$WORK/r.json" "d.get('username','')") $(psqlq "SELECT count(*) FROM sessions s JOIN users u ON u.id = s.user_id WHERE u.username = 'claude_employee02'")"
expect "  링크는 소비되지 않음(used_at NULL) · 감사 session.open deny already_logged_in(행위자 employee02 · 대상 employee01)" "true 1" \
  "$(psqlq "SELECT (used_at IS NULL)::text FROM session_links WHERE code_hash = '$(code_hash "$LX_CODE")'") $(psqlq "SELECT count(*) FROM audit_logs WHERE id > $A3x AND action = 'session.open' AND result = 'deny' AND reason = 'already_logged_in' AND actor_name = 'claude_employee02' AND target_name = 'claude_employee01'")"
C=$(open_link LX ts_lx); expect "  같은 링크를 로그인 안 된 브라우저로 → 302 /tbox/(아직 쓸 수 있다)" "302 /tbox/" "$C"
get_link TOK_S; L5="$LINK_LOCAL"
note "31초 기다린다(링크 만료)"
sleep 31
A3d=$(maxaudit)
C=$(open_link L5 ts_exp); expect "31초 지난 링크 → 302 /tbox/login(만료)" "302 /tbox/login" "$C"
expect "감사 session.open deny link_expired" "1" "$(psqlq "SELECT count(*) FROM audit_logs WHERE id > $A3d AND action = 'session.open' AND result = 'deny' AND reason = 'link_expired'")"

# ════════════════════════════════════════════════════════════════
hr "T-5 토큰 세션의 제한 — 일반 읽기는 되고 민감한 일은 재인증"
C=$(sreq ts GET "$BASE/api/files" "" 0); expect "토큰 세션 GET /api/files → 200" "200" "$C"
C=$(sreq ts GET "$CBASE/api/me/space" "" 0); expect "토큰 세션 GET /community/api/me/space → 200" "200" "$C"
# 파일 둘 — 일반 세션으로 올린다(하나는 토큰 세션이, 하나는 일반 세션이 지운다)
upl() {   # $1 잼 · $2 파일 이름 → id
  local csrf; csrf=$(csrf_of "$1"); printf 'qf9 %s\n' "$2" > "$WORK/$2"
  printf 'X-CSRF-Token: %s\n' "$csrf" | curl -H @- -s -o "$WORK/up.json" -b "$JAR_DIR/$1.jar" -A "$UA" \
    -F "file=@$WORK/$2" -F 'access_level=internal' "$BASE/api/files"
  jf "$WORK/up.json" "d.get('id','')"
}
F1=$(upl claude_employee01 "QF9-a-$$.txt"); F2=$(upl claude_employee01 "QF9-b-$$.txt")
expect "일반 세션으로 파일 둘 올림" "36 36" "${#F1} ${#F2}"
A5=$(maxaudit)
C=$(sreq ts DELETE "$BASE/api/files/$F1" "" 1)
expect "토큰 세션 DELETE /api/files/{id} → 403 next:reauth" "403 reauth" "$C $(next_of)"
C=$(sreq claude_employee01 DELETE "$BASE/api/files/$F2" "" 1)
expect "(대조) 같은 임시 DB 의 일반 세션 DELETE → 200(재인증 없음 — 일반 세션 불변)" "200" "$C"
expect "감사 auth.gate deny reauth_required — 토큰 세션 행에 auth=token_session · token_id" "1" \
  "$(psqlq "SELECT count(*) FROM audit_logs WHERE id > $A5 AND action = 'auth.gate' AND reason = 'reauth_required' AND detail->>'auth' = 'token_session' AND detail->>'token_id' = '${S_ID:-0}' AND detail->>'route' = 'DELETE /api/files/{id}'")"
expect "일반 세션의 삭제 행에는 auth 키 없음(행 불변)" "1 0" \
  "$(psqlq "SELECT count(*) FROM audit_logs WHERE id > $A5 AND action = 'file.delete' AND result = 'allow' AND actor_name = 'claude_employee01'") $(psqlq "SELECT count(*) FROM audit_logs WHERE id > $A5 AND action = 'file.delete' AND result = 'allow' AND detail ? 'auth'" 2>/dev/null || echo x)"
BAD=0
for r in "POST $BASE/api/me/api-tokens" "DELETE $BASE/api/me/api-tokens/${R_ID:-0}" "POST $BASE/api/me/passkeys/options" "POST $BASE/api/me/password" "POST $BASE/api/me/mfa/disable" "DELETE $BASE/api/folders/999999999" "POST $BASE/api/files/$F1/share" "DELETE $CBASE/api/pages/00000000-0000-4000-8000-000000000000" "PATCH $CBASE/api/pages/00000000-0000-4000-8000-000000000000/move" "PUT $CBASE/api/pages/00000000-0000-4000-8000-000000000000/shares/claude_employee02" "POST $CBASE/api/pages/00000000-0000-4000-8000-000000000000/handover"; do
  m=${r%% *}; u=${r#* }
  case "$m" in GET|DELETE) C=$(sreq ts "$m" "$u" "" 1) ;; *) printf '{}' > "$WORK/e.json"; C=$(sreq ts "$m" "$u" "$WORK/e.json" 1) ;; esac
  [ "$C $(next_of)" = "403 reauth" ] || { BAD=$((BAD+1)); echo "    ✖ $r → $C $(next_of)"; }
done
expect "토큰 세션 — 토큰 발급·회수 · 패스키 등록 · 비밀번호 · 2단계 끄기 · 폴더 삭제 · 공유 링크 · 커뮤니티 지우기·옮기기·공유·인계 → 전부 403 next:reauth" "0" "$BAD"
# 메인 판단(2026-09-29): 조회 셋은 토큰 세션에서도 재인증 없이 — 민감한 「변경」이 아니다(집합에서 뺐다)
C=$(sreq ts GET "$BASE/api/me/api-tokens" "" 0); expect "토큰 세션 GET /api/me/api-tokens(조회) → 200(재인증 집합 밖)" "200" "$C"
C=$(sreq ts GET "$BASE/api/me/passkeys" "" 0); expect "토큰 세션 GET /api/me/passkeys(조회) → 200(재인증 집합 밖)" "200" "$C"
printf '{"high_contrast":false}' > "$WORK/hc.json"
C=$(sreq ts PATCH "$BASE/api/me/settings" "$WORK/hc.json" 1); expect "토큰 세션 PATCH /api/me/settings(고대비) → 200(재인증 집합 밖)" "200" "$C"
C=$(sreq claude_employee01 GET "$BASE/api/me/api-tokens" "" 0); expect "(대조) 일반 세션 GET /api/me/api-tokens → 200" "200" "$C"
expect "  그 목록의 로그인 전용 토큰에 bound_ip 127.0.0.1 · 다른 범위는 null" "127.0.0.1 None" \
  "$(jf "$WORK/r.json" "[t['bound_ip'] for t in d['tokens'] if t['id']==${S_ID:-0}][0]") $(jf "$WORK/r.json" "str([t['bound_ip'] for t in d['tokens'] if t['id']==${R_ID:-0}][0])")"
C=$(sreq claude_employee01 GET "$BASE/api/me/passkeys" "" 0); expect "(대조) 일반 세션 GET /api/me/passkeys → 200" "200" "$C"
C=$(sreq claude_employee01 DELETE "$CBASE/api/pages/00000000-0000-4000-8000-000000000000" "" 1); expect "(대조) 일반 세션 커뮤니티 없는 페이지 지우기 → 404(재인증 아님)" "404" "$C"
C=$(sreq ts GET "$BASE/api/users" "" 0); expect "Employee 토큰 세션 GET /api/users → 403 권한(재인증은 권한을 만들지 않는다 — 인가가 먼저)" "403 " "$C $(next_of)"
# 관리 창구 — 관리자의 로그인 전용 토큰(임시 DB 에 직접)으로 연 세션
make_token TOK_ADM claude_admin01 session.open 127.0.0.1; ADM_ID="$MADE_ID"
get_link TOK_ADM; LA="$LINK_LOCAL"
C=$(open_link LA ts_adm); expect "관리자 로그인 전용 링크 → 302 /tbox/" "302 /tbox/" "$C"
C=$(sreq ts_adm GET "$BASE/api/users" "" 0); expect "관리자 토큰 세션 GET /api/users → 403 next:reauth" "403 reauth" "$C $(next_of)"
C=$(curl -s -o /dev/null -b "$JAR_DIR/ts_adm.jar" -A "$UA" -w '%{http_code} %{redirect_url}' "$BASE/admin"); expect "관리자 토큰 세션 화면 /tbox/admin → 302 재인증 화면" "302 /tbox/reauth?to=admin" "${C/$SITE/}"
# 재인증 — 비밀번호(기존 창구 그대로) 뒤 200
reauth_as() {   # $1 잼 · $2 계정
  local pw body csrf hf c
  pw=$(review_password "$2"); [ -n "$pw" ] || { printf '비밀번호없음'; return 0; }
  body=$(py_secret pw -- python3 -c 'import json,sys; print(json.dumps({"password":sys.stdin.readline().rstrip("\n")}))'); pw=''
  csrf="X-CSRF-Token: $(csrf_of "$1")"; hf=$(secret_file csrf "$WORK") || { printf '머리파일없음'; return 0; }
  c=$(curl_json_secret body -s -o "$WORK/r.json" -w '%{http_code}' -X POST -b "$JAR_DIR/$1.jar" -A "$UA" -H @"$hf" -H 'Content-Type: application/json' "$BASE/api/me/reauth")
  rm -f "$hf"; body=''; csrf=''
  printf '%s' "$c"
}
C=$(reauth_as ts_adm claude_admin01); expect "관리자 토큰 세션 POST /api/me/reauth(비밀번호) → 200" "200" "$C"
C=$(sreq ts_adm GET "$BASE/api/users" "" 0); expect "재인증 뒤 GET /api/users → 200" "200" "$C"
C=$(reauth_as ts claude_employee01); expect "토큰 세션 POST /api/me/reauth → 200" "200" "$C"
C=$(sreq ts DELETE "$BASE/api/files/$F1" "" 1); expect "재인증 뒤 토큰 세션 DELETE 파일 → 200" "200" "$C"
C=$(sreq ts POST "$BASE/api/me/passkeys/options" "$WORK/e.json" 1); expect "재인증 뒤 토큰 세션 POST /api/me/passkeys/options → 재인증 요구 아님" "0" "$([ "$(next_of)" = "reauth" ] && echo 1 || echo 0)"
expect "감사 file.delete allow(토큰 세션) — auth=token_session · token_id" "1" \
  "$(psqlq "SELECT count(*) FROM audit_logs WHERE id > $A5 AND action = 'file.delete' AND result = 'allow' AND detail->>'auth' = 'token_session' AND detail->>'token_id' = '${S_ID:-0}'")"
# 유휴 — 토큰 세션 10분 · 일반 세션 30분
TS_SID=$(psqlq "SELECT id FROM sessions WHERE token_id = ${S_ID:-0} ORDER BY created_at LIMIT 1")
E1_SID=$(psqlq "SELECT s.id FROM sessions s JOIN users u ON u.id = s.user_id WHERE u.username = 'claude_employee01' AND s.origin = 'login' ORDER BY s.created_at DESC LIMIT 1")
psqlq "UPDATE sessions SET last_seen_at = now() - interval '11 minutes' WHERE id IN ('$TS_SID', '$E1_SID')" >/dev/null
C=$(sreq ts GET "$BASE/api/me" "" 0); expect "토큰 세션 유휴 11분 → 401" "401" "$C"
C=$(sreq claude_employee01 GET "$BASE/api/me" "" 0); expect "일반 세션 유휴 11분 → 200(30분 그대로)" "200" "$C"
psqlq "UPDATE sessions SET last_seen_at = now() - interval '9 minutes' WHERE id = '$TS_SID'" >/dev/null
C=$(sreq ts GET "$BASE/api/me" "" 0); expect "(경계) 토큰 세션 유휴 9분 → 200" "200" "$C"
# 검토 RV-QF9-3 — 2단계를 끄면 열린 토큰 세션도 끝난다(임시 DB 에서 끄고 곧바로 되돌린다)
get_link TOK_S; LM="$LINK_LOCAL"
C=$(open_link LM ts_mfa); expect "새 토큰 세션(2단계 확인용) → 302 /tbox/" "302 /tbox/" "$C"
psqlq "UPDATE users SET mfa_enabled = false WHERE username = 'claude_employee01'" >/dev/null
C=$(sreq ts_mfa GET "$BASE/api/me" "" 0); C2=$(sreq claude_employee01 GET "$BASE/api/me" "" 0)
psqlq "UPDATE users SET mfa_enabled = true WHERE username = 'claude_employee01'" >/dev/null
expect "2단계를 끈 뒤 토큰 세션 → 401 · 일반 세션은 200" "401 200" "$C $C2"
expect "  (되돌림) claude_employee01 mfa_enabled" "t" "$(psqlq "SELECT mfa_enabled FROM users WHERE username = 'claude_employee01'")"
# 검토 RV-QF9-2 — 한 사람의 살아 있는 로그인 전용 토큰은 DB 가 하나로 지킨다(부분 유일 인덱스)
IDXERR=$(printf '%s\n' "INSERT INTO api_tokens (user_id, name, token_hash, hint, created_by, reason_text, scope, expires_at, bound_ip) SELECT id, 'QF9-db-dup', repeat('d', 64), 'dddddd', id, 'Q:F-9 유일 인덱스 시험', 'session.open', now() + interval '1 day', CAST('127.0.0.1' AS inet) FROM users WHERE username = 'claude_employee01';" \
  | tdb psql -h 127.0.0.1 -p 5432 -U tbox_testdb -w -X -q -At -d "$DB" 2>&1 >/dev/null)
expect "둘째 살아 있는 session.open 행 INSERT → 유일 위반(api_tokens_one_session_open)" "1" "$(printf '%s' "$IDXERR" | grep -c 'api_tokens_one_session_open')"
# 토큰을 회수하면 그 토큰으로 연 세션도 끝난다(관리자 토큰으로 — 뒤 T-4 는 직원 토큰을 쓴다)
C=$(sreq ts_adm GET "$BASE/api/me" "" 0); expect "관리자 토큰 세션 살아 있음" "200" "$C"
C=$(sreq ts_adm DELETE "$BASE/api/me/api-tokens/${ADM_ID:-0}" "" 1); expect "(재인증 창 안) 관리자가 자기 로그인 전용 토큰 회수 → 200" "200" "$C"
C=$(sreq ts_adm GET "$BASE/api/me" "" 0); expect "회수한 토큰으로 연 세션 → 401" "401" "$C"

# ════════════════════════════════════════════════════════════════
hr "T-4 IP 불일치"
# 속도 창을 다시 비운다(앞 절의 링크가 60초 안에 남아 있을 수 있다 — 임시 DB)
psqlq "UPDATE session_links SET created_at = created_at - interval '2 minutes', expires_at = expires_at - interval '2 minutes' WHERE token_id = ${S_ID:-0}" >/dev/null
A4=$(maxaudit)
psqlq "UPDATE api_tokens SET bound_ip = '10.20.30.40' WHERE id = ${S_ID:-0}" >/dev/null
C=$(sreq ts GET "$BASE/api/me" "" 0); expect "묶인 IP 가 바뀌면 이미 연 토큰 세션도 401(세션 조회가 묶인 IP 를 본다)" "401" "$C"
BAD=0
for i in 1 2 3; do
  C=$(treq TOK_S POST "$BASE/api/me/session-link")
  [ "$C $(code_of)" = "403 ip_mismatch" ] || { BAD=$((BAD+1)); echo "    ✖ $i 번째 → $C $(code_of) $(err_of)"; }
done
expect "bound_ip 가 다른 토큰으로 링크 세 번 → 전부 403 ip_mismatch" "0" "$BAD"
note "403 본문: $(head -c 300 "$WORK/r.json")"
expect "감사 session.link deny ip_mismatch 세 행 — token_id · 요청 IP 칸 127.0.0.1" "3" \
  "$(psqlq "SELECT count(*) FROM audit_logs WHERE id > $A4 AND action = 'session.link' AND result = 'deny' AND reason = 'ip_mismatch' AND detail->>'token_id' = '${S_ID:-0}' AND host(ip) = '127.0.0.1'")"
touch "$WORK/empty-auth.log"
( cd / && php "$INST/app/scripts/alerts.php" --rule AL-24 --dry-run --state "$WORK/al-state.json" --auth-log "$WORK/empty-auth.log" ) > "$WORK/al24.out" 2>&1
tail -3 "$WORK/al24.out" | sed 's/^/    /'
expect "alerts.php --rule AL-24 --dry-run → AL-24 한 줄(claude_employee01 · token_id)" "1 1" \
  "$(grep -c "\[dry-run\] AL-24 claude_employee01 .*\"token_id\":\"${S_ID:-0}\"" "$WORK/al24.out") $(grep -c 'AL-24 대상 1건' "$WORK/al24.out")"
if [ -s "$SEEN_TOKS" ]; then
  expect "경보 출력에 토큰·코드 원문 0" "0" "$(cat "$SEEN_TOKS" "$SEEN_CODES" | grep -v '^$' | grep -c -F -f - "$WORK/al24.out")"
fi
psqlq "UPDATE api_tokens SET bound_ip = '127.0.0.1' WHERE id = ${S_ID:-0}" >/dev/null
get_link TOK_S; C=$LINK_STATUS; expect "묶인 IP 를 되돌리면 링크 → 201" "201" "$C"; L6="$LINK_LOCAL"
psqlq "UPDATE api_tokens SET bound_ip = '10.20.30.41' WHERE id = ${S_ID:-0}; UPDATE session_links SET ip = '10.20.30.41' WHERE code_hash = '$(code_hash "$LINK_CODE")'" >/dev/null
A4b=$(maxaudit)
C=$(open_link L6 ts_ip); expect "링크를 받은 IP(= 묶인 IP)가 지금 IP 와 다르면 교환 → 302 /tbox/login" "302 /tbox/login" "$C"
expect "감사 session.open deny ip_mismatch(대상 claude_employee01 · token_id)" "1" \
  "$(psqlq "SELECT count(*) FROM audit_logs WHERE id > $A4b AND action = 'session.open' AND result = 'deny' AND reason = 'ip_mismatch' AND detail->>'token_id' = '${S_ID:-0}'")"
psqlq "UPDATE api_tokens SET bound_ip = '127.0.0.1' WHERE id = ${S_ID:-0}" >/dev/null
get_link TOK_S; L7="$LINK_LOCAL"
psqlq "UPDATE session_links SET ip = '10.20.30.42' WHERE code_hash = '$(code_hash "$LINK_CODE")'" >/dev/null
C=$(open_link L7 ts_ip2); expect "링크 행의 IP 만 바뀐 경우 → 302 /tbox/login(token_invalid)" "302 /tbox/login" "$C"

# 검토 RV-QF9-4 — IP 불일치 거부도 분당 5회에 든다(다른 토큰 — employee02 · 틀린 IP 에 묶어 임시 DB 에 직접)
make_token TOK_E2 claude_employee02 session.open 10.9.9.9; E2T_ID="$MADE_ID"
R=""
for i in 1 2 3 4 5 6; do C=$(treq TOK_E2 POST "$BASE/api/me/session-link"); R="$R $C/$(code_of)"; done
expect "틀린 IP 에서 여섯 번 → 403 ip_mismatch 다섯 뒤 429 rate_limited" " 403/ip_mismatch 403/ip_mismatch 403/ip_mismatch 403/ip_mismatch 403/ip_mismatch 429/rate_limited" "$R"
psqlq "UPDATE api_tokens SET bound_ip = '127.0.0.1' WHERE id = ${E2T_ID:-0}" >/dev/null
C=$(treq TOK_E2 POST "$BASE/api/me/session-link")
expect "  IP 를 맞춰도 1분 안이면 429(거부 다섯이 셈에 든다)" "429 rate_limited" "$C $(code_of)"
expect "  그 사이 이 토큰의 링크 행 0(틀린 IP 시도는 링크를 만들지 않는다)" "0" "$(psqlq "SELECT count(*) FROM session_links WHERE token_id = ${E2T_ID:-0}")"

# ════════════════════════════════════════════════════════════════
hr "T-6 일반 로그인 불변 — 기준 판($BASE_REV · $BSITE)과 대조"
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
T6_T0=$(psqlq "SELECT now()")
login claude_employee01 "$BSITE" b_e1 || { echo "  ✖ 기준 판 로그인 실패"; exit 4; }
login claude_employee01 "$SITE" n_e1 || { echo "  ✖ 새 코드 로그인 실패"; exit 4; }
login claude_admin01 "$BSITE" b_adm || { echo "  ✖ 기준 판 관리자 로그인 실패"; exit 4; }
login claude_admin01 "$SITE" n_adm || { echo "  ✖ 새 코드 관리자 로그인 실패"; exit 4; }
expect "두 판 모두 일반 로그인(비밀번호+OTP) 성공 · 그 네 세션 행 origin=login(기본값 — 기준 판은 열을 모른다) · token_id NULL" "4 0" \
  "$(psqlq "SELECT count(*) FILTER (WHERE s.origin = 'login' AND s.token_id IS NULL) || ' ' || count(*) FILTER (WHERE s.origin <> 'login' OR s.token_id IS NOT NULL) FROM sessions s JOIN users u ON u.id = s.user_id WHERE u.username IN ('claude_employee01','claude_admin01') AND s.created_at >= CAST('$T6_T0' AS timestamptz)")"
cmp_pair() {   # $1 설명 · $2 메서드 · $3 모듈 경로 · $4 새 잼 · $5 기준 잼 · $6 본문 파일 · $7 csrf 1/0
  local nc bc ns bs
  nc=$(sreq "$4" "$2" "$SITE$3" "${6:-}" "${7:-0}" "$SITE"); cp "$WORK/r.json" "$WORK/n.json"
  bc=$(sreq "$5" "$2" "$BSITE$3" "${6:-}" "${7:-0}" "$BSITE"); cp "$WORK/r.json" "$WORK/b.json"
  ns=$(shape "$WORK/n.json"); bs=$(shape "$WORK/b.json")
  [ -n "$bc" ] && [ -n "$bs" ] || { bc='기준 판 응답 없음'; bs='기준 판 응답 없음'; }
  expect "$1 — 상태 코드 새=기준" "$bc" "$nc"
  expect "$1 — 본문 모양 새=기준" "$bs" "$ns"
}
cmp_pair "GET /api/me(일반 세션)"                        GET  "/tbox/api/me"        n_e1 b_e1
# Q:F-10 — token_scopes 는 원자 목록이 됐다(기준 판은 옛 사다리 값). 끝이 session.open 인 것은 그대로
expect "  (값) token_scopes — 새 판은 원자 여섯(Q:F-10) · 기준 판은 옛 사다리 넷" "read,upload,delete,community.read,community.write,session.open | read,read+upload,read+upload+delete,community.write" "$(jf "$WORK/n.json" "','.join(d['token_scopes'])") | $(jf "$WORK/b.json" "','.join(d['token_scopes'])")"
cmp_pair "GET /api/files(일반 세션)"                     GET  "/tbox/api/files"     n_e1 b_e1
cmp_pair "GET /api/users(Employee — 인가 403)"           GET  "/tbox/api/users"     n_e1 b_e1
cmp_pair "GET /api/users(관리자 · 재인증 전 — 403 reauth)" GET  "/tbox/api/users"     n_adm b_adm
expect "  그 답은 403 next:reauth" "reauth" "$(jf "$WORK/n.json" "d.get('next','')")"
printf '{"password":"qf9-wrong-password-0000"}' > "$WORK/wrongpw.json"
cmp_pair "POST /api/me/reauth(틀린 비밀번호 — 400 통일 문구)" POST "/tbox/api/me/reauth" n_adm b_adm "$WORK/wrongpw.json" 1
C=$(reauth_as n_adm claude_admin01); B=$(csrf=''; pw=$(review_password claude_admin01); body=$(py_secret pw -- python3 -c 'import json,sys; print(json.dumps({"password":sys.stdin.readline().rstrip("\n")}))'); pw=''
  csrf="X-CSRF-Token: $(csrf_of b_adm "$BSITE")"; hf=$(secret_file csrf "$WORK"); curl_json_secret body -s -o "$WORK/b.json" -w '%{http_code}' -X POST -b "$JAR_DIR/b_adm.jar" -A "$UA" -H @"$hf" -H 'Content-Type: application/json' "$BSITE/tbox/api/me/reauth"; rm -f "$hf")
expect "POST /api/me/reauth(맞는 비밀번호) — 새=기준" "$B" "$C"
expect "  본문 모양 새=기준" "$(shape "$WORK/b.json")" "$(shape "$WORK/r.json")"
cmp_pair "GET /api/users(관리자 · 재인증 뒤 — 200)"       GET  "/tbox/api/users"     n_adm b_adm
cmp_pair "POST /api/logout(일반 세션)"                    POST "/tbox/api/logout"    n_e1 b_e1 "" 1
cmp_pair "로그아웃 뒤 GET /api/me(401)"                   GET  "/tbox/api/me"        n_e1 b_e1
C=$(curl -s -o "$WORK/n.json" -w '%{http_code}' -A "$UA" -H 'Content-Type: application/json' --data-binary '{"username":"claude_employee01","password":"qf9-wrong-password-0000"}' "$SITE/tbox/api/login")
B=$(curl -s -o "$WORK/b.json" -w '%{http_code}' -A "$UA" -H 'Content-Type: application/json' --data-binary '{"username":"claude_employee01","password":"qf9-wrong-password-0000"}' "$BSITE/tbox/api/login")
expect "POST /api/login 틀린 비밀번호 — 새=기준(401 · 같은 본문)" "$B $(cat "$WORK/b.json")" "$C $(cat "$WORK/n.json")"
C=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -A "$UA" "$SITE/tbox/settings"); B=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -A "$UA" "$BSITE/tbox/settings")
expect "쿠키 없음 화면 /tbox/settings — 새=기준(302 로그인 · 보던 곳 꼬리표)" "${B//$BPORT/PORT}" "${C//$PORT/PORT}"
psqlq "UPDATE users SET failed_count = 0, locked_until = NULL WHERE username IN ('claude_employee01','claude_admin01')" >/dev/null   # 위 틀린 비밀번호 두 번의 집계를 되돌린다(임시 DB)
expect "일반 로그인 세션 행은 전부 origin=login · token_id NULL" "0" \
  "$(psqlq "SELECT count(*) FROM sessions WHERE (origin = 'login') <> (token_id IS NULL)")"

# ════════════════════════════════════════════════════════════════
hr "T-7 원문 0 — 링크 코드·토큰 원문이 DB·감사 파일·앱 로그 어디에도 없다"
expect "이 시험이 받은 링크 코드 수(0 이면 대조가 헛돈다)" "yes" "$([ "$(grep -c . "$SEEN_CODES")" -ge 8 ] && echo yes || echo "no($(grep -c . "$SEEN_CODES"))")"
tdb pg_dump -h 127.0.0.1 -p 5432 -U tbox_testdb -w --data-only -t audit_logs -t sessions -t session_links -t api_tokens "$DB" > "$WORK/dump.sql" 2>/dev/null
expect "임시 DB 덤프(감사·세션·링크·토큰 표)에 코드·토큰 원문 0" "0" "$(grep -v '^$' "$SEEN_CODES" "$SEEN_TOKS" -h | grep -c -F -f - "$WORK/dump.sql")"
expect "  (대조) 덤프에 코드의 sha256 은 있다(해시만 저장)" "1" "$(grep -c "$(code_hash "$L1_CODE")" "$WORK/dump.sql")"
expect "감사 파일(audit.log)에 코드·토큰 원문 0" "0" "$(grep -v '^$' "$SEEN_CODES" "$SEEN_TOKS" -h | grep -c -F -f - "$INST/log/audit.log")"
note "앱 로그(error_log)는 php -S 의 표준 오류(server.log)로 간다 — server.log 의 요청 줄에는 주소(쿼리 포함)가 찍힌다(접근 로그 — 앱 밖, SECURITY 6장 · R-113)"
expect "server.log 에서 요청 줄(Accepted/Closing/[200]…)을 뺀 나머지에 코드·토큰 원문 0" "0" \
  "$(grep -vE '^\[.*\] 127\.0\.0\.1:[0-9]+ (Accepted|Closing|\[[0-9]{3}\]: )' "$INST/server.log" | grep -c -F -f <(grep -hv '^$' "$SEEN_CODES" "$SEEN_TOKS"))"

if [ "${REGRESS:-0}" = "1" ]; then
  hr "회귀 — 같은 임시 인스턴스·임시 DB 에서(qf8_token_write.sh 의 회귀 틀 · 8082 → $SITE · tbox_re → $DB)"
  # 이 시험이 만든 토큰(QF9-…)을 임시 DB 에서 회수해 둔다 — 살아 있는 토큰은 계정당 상한 5 에 들어 f66 의 발급을 막는다
  note "이 시험의 토큰 회수(임시 DB): $(psqlq "WITH r AS (UPDATE api_tokens SET revoked_at = now() WHERE revoked_at IS NULL AND name LIKE 'QF9-%' RETURNING 1) SELECT count(*) FROM r")개"
  for s in ${REGRESS_LIST:-api_whoami f66}; do
    src="$INST/app/tests/$s.sh"; dst="$INST/app/tests/$s.temp.sh"; rlog="$WORK/regress_$(basename "$s").txt"
    [ -f "$src" ] || { echo "  ✖ $s — 스크립트가 없다"; FAIL=$((FAIL+1)); continue; }
    sed -e "s#http://127.0.0.1:8082#$SITE#g" -e "s#^DB=\"tbox_re\"#DB=\"$DB\"#" -e "s#-d tbox_re #-d $DB #g" "$src" > "$dst"
    if grep -qE '^(SITE|BASE|CBASE|DB|PORT)=.*(8082|"tbox_re")' "$dst" || grep -v '^\s*#' "$dst" | grep -qE -- '-d tbox_re '; then
      echo "  ✖ $s — 운영 주소·DB 가 남아 있어 돌리지 않는다"; FAIL=$((FAIL+1)); continue
    fi
    sleep $((31 - $(date +%s) % 30))
    echo "  ▸ $s (시작 $(date +%T))"
    REGRESS=0 SHOTS="$WORK/shots" bash "$dst" > "$rlog" 2>&1
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
expect "이 시험이 부른 주소는 임시 인스턴스뿐(127.0.0.1:8421~8425)" "1 1" \
  "$(case "$SITE" in http://127.0.0.1:842[1-5]) echo 1 ;; *) echo 0 ;; esac) $(case "$BSITE" in http://127.0.0.1:842[1-5]) echo 1 ;; *) echo 0 ;; esac)"
TOK_E2=''; LX=''; LM=''; LX_CODE=''; TOK_S=''; TOK_S1=''; TOK_R=''; TOK_CW=''; TOK_ADM=''; SHARED_TOK=''; TOK_G=''; TOK_N=''
L1_URL=''; L2=''; L3=''; L4=''; L5=''; L6=''; L7=''; LA=''; LINK_URL=''; LINK_LOCAL=''; LINK_CODE=''; L1_CODE=''

printf '\n통과 %d · 실패 %d\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ]
