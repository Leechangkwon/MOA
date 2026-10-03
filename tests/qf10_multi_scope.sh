#!/usr/bin/env bash
# tests/qf10_multi_scope.sh — Q:F-10 조각 S 수락 시험 T-1 ~ T-8 (토큰 하나에 범위 여럿 · 커뮤니티 검색·읽기를 토큰에)
#                              (지시서 docs/qf10-multi-scope-directive.md 「조각 S」 S-6 · ARCHITECTURE 4.10 · API.md 21장 ·
#                               t-box PRD F-76 · 사이트 PRD Q:F-10 · SECURITY R-114)
#
#   정적     Authz 원자 표·정규화·판정 표(옛 다섯 값 + 묶음) · 옛 상수 둘(FILE_TOKEN_SCOPES·TOKEN_SCOPES) 0 · 토큰 창구 표 여섯 ·
#            public/index.php·Http·Db·grants 차이 0(기준 b2ee066) · security_lint 0 · 바뀐 PHP php -l · app.js node --check
#   T-5 옛 값  임시 DB 에 마이그레이션 2026-10-12(Q:F-9 모양) → 옛 다섯 값 행 직접 INSERT → 2026-10-13 **두 번**(멱등) →
#            community.write 행만 바뀜 · 모르는 값이 있으면 멈춤(바뀐 것 없음) · db/schema.sql 새 설치 모양 = 마이그레이션 모양 ·
#            CHECK 가 모르는 원자·빈 글자·IP 짝 어긋남을 막음 · 의존이 깨진 행(DB 직접 `upload`)은 가진 원자만큼만 연다
#   T-1 정규화 발급 ["upload"]·["delete","read"]·["x"]·[]·[1]·{..} → 400 · ["upload","read"] → 201 read+upload ·
#            ["community.write"] → 201 community.read+community.write · 옛 문자열 "read+upload+delete" → 201 · Guest 커뮤니티 400
#   T-2 묶음 ["read","upload","community.read","session.open"] → 201 · bound_ip · 파일 200 · 올리기 201 · 커뮤니티 쓰기 403 ·
#            검색 200 · 링크 201 · 지우기 403 · 링크 to=/community/p/<id> → 그 페이지로
#   T-3 IP   묶음 토큰의 bound_ip 를 10.9.9.9 로(임시 DB) → 파일·검색·whoami 401 token_ip_mismatch · 감사 auth.token deny ip_mismatch ·
#            링크 창구는 403 ip_mismatch(Q:F-9 답 그대로) · 묶이지 않은 토큰 영향 0 · AL-24 dry-run 한 줄
#   T-4 하나 session.open 이 든 토큰이 살아 있는데 또 하나(다른 원자 섞어) → 201 revoked_previous=1(지금 규칙 — 자동 회수) ·
#            앞 토큰 401 · DB 직접 둘째 → 유일 위반 · 회수 뒤 발급 → 201 revoked_previous=0
#   T-6 읽기 ["community.read"](claude_employee02) → 검색·목록·공간·내 페이지 200 · 남의 비공개 = 세션과 같은 답 · 쓰기·파일·링크 403
#   T-7 whoami 토큰: token_scope · token_scopes_held(원자) · 키 여섯 · 공용 토큰 read+upload · 세션 /api/me: 두 칸 없음 · token_scopes 원자
#   T-8 회귀 REGRESS=1 이면 같은 임시 인스턴스에서 REGRESS_LIST(기본 api_tokens api_whoami) 사본 — qf8·qf9 는 각자 스크립트로 따로
#   서버 로그 PHP 오류 0
#
# ★ 운영 DB·운영 포트를 건드리지 않는다 — 임시 DB 만 쓴다(만들고 지움) · 포트 8451~8455(QF10_PORT) · 검토 계정 claude_ 다섯(F-71) ·
#   UA `tbox-review/<날짜>` · 비밀(비밀번호·TOTP·토큰·CSRF·링크 주소)은 표준입력으로만(R-62 · tests/lib/secrets.sh).
#   임시 .env 는 운영 .env 를 mktemp 폴더(0700)에 0600 으로 복사해 DB_NAME·STORAGE_DIR·AUDIT_LOG_FILE 을 임시로, 공용 토큰을
#   이 시험이 만든 난수(claude_employee01 귀속)로 바꾼다. 끝에 지운다. 틀은 tests/qf9_pc_login.sh 와 같다.
# 쓰는 법: bash tests/qf10_multi_scope.sh     환경 QF10_PORT(8451~8455 · 기본 8451) · QF10_BASE_REV(기본 b2ee066 — v2.38.0) ·
#          REGRESS=1 이면 REGRESS_LIST(기본 api_tokens api_whoami)를 사본으로(8082 → 이 인스턴스 · tbox_re → 임시 DB) · REGRESS_KEEP=<폴더>
set -u

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
. "$ROOT/tests/lib/review_accounts.sh" || { echo "tests/lib/review_accounts.sh 를 읽지 못했다 — 시험을 시작하지 않는다"; exit 3; }
. "$ROOT/tests/lib/secrets.sh" || { echo "tests/lib/secrets.sh 를 읽지 못했다 — 시험을 시작하지 않는다 (R-62)"; exit 3; }

PORT="${QF10_PORT:-8451}"
case "$PORT" in 8451|8452|8453|8454|8455) ;; *) echo "이 조각은 포트 8451~8455 만 쓴다 — $PORT"; exit 3 ;; esac
SITE="http://127.0.0.1:$PORT"
BASE="$SITE/tbox"
CBASE="$SITE/community"
UA="tbox-review/$(date +%F)"
SRC_DB="tbox_re"                      # 운영 사본 — pg_dump 로 **읽기만**
DB="tbox_qf10_${PORT}_test"           # 임시 — 여기에만 쓴다
SDB="tbox_qf10_${PORT}_schema"        # 임시 — db/schema.sql 새 설치 모양 대조용
BASE_REV="${QF10_BASE_REV:-b2ee066}"  # 기준 판(v2.38.0 — 이 조각의 출발점)
MIG9="$ROOT/db/migrations/2026-10-12_session_open.sql"
MIG="$ROOT/db/migrations/2026-10-13_api_tokens_multi_scope.sql"
PGP_TEST="$HOME/.config/qaredi/pgpass-testdb"
JAR_DIR="$(mktemp -d)"
WORK="$(mktemp -d)"
chmod 700 "$JAR_DIR" "$WORK"
INST=""; SPID=""; ENV_TMP=""
PASS=0
FAIL=0
SEEN_TOKS="$WORK/.toks"               # 이 시험이 받은·만든 토큰 원문(끝의 「원문 0」 대조용 — 0600 · 끝에 지움)
( umask 077; : > "$SEEN_TOKS" )

hr()  { printf '\n════════════════════════════════════════════════════════════\n%s\n════════════════════════════════════════════════════════════\n' "$1"; }
expect() {
  if [ "$2" = "$3" ]; then printf '  ✔ %s  (기대=%s 실제=%s)\n' "$1" "$2" "$3"; PASS=$((PASS+1))
  else printf '  ✖ %s  (기대=%s 실제=%s)\n' "$1" "$2" "$3"; FAIL=$((FAIL+1)); fi
}
note() { printf '  · %s\n' "$1"; }

tdb() { env -u PGHOST -u PGPORT -u PGUSER -u PGDATABASE -u PGPASSWORD -u PGSERVICE -u PGOPTIONS PGPASSFILE="$PGP_TEST" "$@"; }
psqlq() { printf '%s\n' "$1" | tdb psql -h 127.0.0.1 -p 5432 -U tbox_testdb -w -X -q -At -d "$DB" 2>/dev/null; }
psqle() { printf '%s\n' "$1" | tdb psql -h 127.0.0.1 -p 5432 -U tbox_testdb -w -X -q -At -d "$DB" 2>&1 >/dev/null; }   # 오류 글자만

port_busy() { (exec 3<>"/dev/tcp/127.0.0.1/$1") 2>/dev/null; }

cleanup() {
  [ -n "$SPID" ] && { kill "$SPID" 2>/dev/null; wait "$SPID" 2>/dev/null; }
  tdb dropdb -h 127.0.0.1 -p 5432 -U tbox_testdb -w --if-exists "$DB" >/dev/null 2>&1
  tdb dropdb -h 127.0.0.1 -p 5432 -U tbox_testdb -w --if-exists "$SDB" >/dev/null 2>&1
  [ -n "$INST" ] && rm -rf "$INST"
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

login() {   # $1 계정 · $2 잼 이름(기본 계정) — 쿠키는 $JAR_DIR/<잼>.jar
  local who="$1" jar="$JAR_DIR/${2:-$1}.jar" pw sec raw code left out try body
  pw=$(review_password "$who"); sec=$(review_secret "$who")
  [ -n "$pw" ] || return 2
  for try in 1 2 3; do
    if [ -n "$sec" ]; then
      raw=$(totp_now sec 2>/dev/null); code=${raw%%$'\n'*}
      printf -v body '{"username":"%s","password":"%s","otp":"%s"}' "$who" "$pw" "$code"
    else
      printf -v body '{"username":"%s","password":"%s"}' "$who" "$pw"
    fi
    out=$(curl_json_secret body -s -c "$jar" -A "$UA" -H 'Content-Type: application/json' "$SITE/tbox/api/login")
    case "$out" in *'"ok":true'*) body=''; pw=''; sec=''; return 0 ;; esac
    left=$(printf '%s' "$raw" | sed -n 's/^남은 시간: \([0-9]*\)초.*/\1/p'); [ -n "$left" ] || left=31
    printf '  · %s 로그인 재시도 — 다음 코드 창까지 %s초\n' "$who" "$left" >&2
    sleep $((left + 1))
  done
  body=''; pw=''; sec=''
  return 1
}
csrf_of() { curl -s -b "$JAR_DIR/$1.jar" -A "$UA" "$SITE/tbox/api/me" | python3 -c 'import json,sys; print(json.load(sys.stdin).get("csrf_token",""))' 2>/dev/null; }

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

# 토큰 발급(세션 · 비밀번호+코드) — $1 계정 · $2 받을 변수 이름 · $3 범위(**JSON 값 그대로** — '["read","upload"]' · '"read+upload"') ·
#   $4 코드("next" 면 다음 창 코드). 결과는 전역: ISSUED_CODE · ISSUED_ID · ISSUED_SCOPE · ISSUED_SCOPES(원자를 , 로) · ISSUED_BIP · ISSUED_REVPREV
ISSUED_CODE=''; ISSUED_ID=''; ISSUED_SCOPE=''; ISSUED_SCOPES=''; ISSUED_BIP=''; ISSUED_REVPREV=''
issue_as() {
  local who="$1" var="$2" scope="$3" otp="$4" pw body csrf tok hf
  ISSUED_CODE=''; ISSUED_ID=''; ISSUED_SCOPE=''; ISSUED_SCOPES=''; ISSUED_BIP=''; ISSUED_REVPREV=''
  printf -v "$var" '%s' ''
  if [ "$otp" = "next" ]; then otp_next "$who" || { ISSUED_CODE='코드없음'; return 0; }; otp="$NEXT_OTP"; fi
  pw=$(review_password "$who"); [ -n "$pw" ] || { ISSUED_CODE='비밀번호없음'; return 0; }
  body=$(py_secret pw -- python3 -c 'import json,sys; pw=sys.stdin.readline().rstrip("\n"); print(json.dumps({"name":sys.argv[1],"purpose":sys.argv[2],"scope":json.loads(sys.argv[3]),"password":pw,"otp":sys.argv[4]}))' \
         "QF10-$(printf '%s' "$scope" | tr -dc 'a-z.+,' | head -c 40)" "Q:F-10 수락 시험(tests/qf10_multi_scope.sh)" "$scope" "$otp")
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
    ISSUED_SCOPES=$(jf "$WORK/issue.json" "','.join(d.get('scopes',[]))")
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
                   SELECT id, 'QF10-db', '$hash', '${plain:0:6}', id, 'Q:F-10 수락 시험(임시 DB 직접)', '$scope', now() + interval '1 day',
                          $( [ -n "$bip" ] && printf "CAST('%s' AS inet)" "$bip" || printf 'NULL' )
                     FROM users WHERE username = '$who' RETURNING id")
  printf -v "$var" '%s' "$plain"; printf '%s\n' "$plain" >> "$SEEN_TOKS"; plain=''
}

# 토큰 요청 — $1 토큰 변수 · $2 메서드 · $3 주소 · $4 본문 파일(선택). 본문은 $WORK/r.json
treq() {
  local v="$1" m="$2" u="$3" f="${4:-}"
  local copts=(-s -o "$WORK/r.json" -w '%{http_code}' -A "$UA" -X "$m" -H 'Expect:')
  [ -n "$f" ] && copts+=(-H 'Content-Type: application/json' --data-binary @"$f")
  curl_hdr_secret X-API-Token "$v" "${copts[@]}" "$u"
}
# 세션 요청 — $1 잼 · $2 메서드 · $3 주소 · $4 본문 파일(선택) · $5 csrf 1/0(기본 1)
sreq() {
  local jar="$1" m="$2" u="$3" f="${4:-}" withc="${5:-1}" csrf=''
  local copts=(-s -o "$WORK/r.json" -w '%{http_code}' -b "$JAR_DIR/$jar.jar" -A "$UA" -X "$m" -H 'Expect:')
  [ -n "$f" ] && copts+=(-H 'Content-Type: application/json' --data-binary @"$f")
  if [ "$withc" = "1" ]; then
    csrf=$(csrf_of "$jar")
    printf 'X-CSRF-Token: %s\n' "$csrf" | curl -H @- "${copts[@]}" "$u"
  else
    curl "${copts[@]}" "$u"
  fi
}
err_of()    { jf "$WORK/r.json" "d.get('error','')"; }
code_of()   { jf "$WORK/r.json" "d.get('code','')"; }
maxaudit()  { psqlq "SELECT coalesce(max(id),0) FROM audit_logs"; }
MSG_SCOPE='이 토큰의 범위에 없는 창구입니다 — 범위가 더 넓은 토큰을 발급하세요'

make_env() {   # $1 원본 $2 대상 $3 저장 폴더 $4 감사 파일 $5 공용 토큰 파일. 값은 출력하지 않는다.
  # qcheck-allow: R20 — 임시 인스턴스용 사본(0700 폴더 · 0600 · trap 정리) · kill -9 뒤 남는 창은 받아들인 위험(HO-B1-004 · qf9_pc_login.sh make_env 와 같은 판단)
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
  ( cd "$1/app" && PHP_CLI_SERVER_WORKERS=4 exec php -S "127.0.0.1:$2" -t public router.php > "$1/server.log" 2>&1 ) &
  LAST_PID=$!
  local c="" i
  for i in $(seq 1 30); do sleep 0.3; c=$(curl -s -o /dev/null -w '%{http_code}' -A "$UA" "http://127.0.0.1:$2/tbox/login"); [ "$c" = "200" ] && break; done
  [ "$c" = "200" ]
}

# ════════════════════════════════════════════════════════════════
hr "정적 (저장소 — 기준 판 $BASE_REV 대비)"
AZ=$(php -r '
require $argv[1];
$scopes = ["read", "read+upload", "read+upload+delete", "community.write", "session.open",
           "community.read", "community.read+community.write", "read+upload+community.read+session.open", "upload", "read+admin", ""];
$pols = ["authenticated", "file.read", "file.upload", "file.update", "file.delete", "community.read", "community.write", "session.open", "role:Admin", "file.fetch"];
foreach ($pols as $p) { $row = []; foreach ($scopes as $s) { $row[] = Authz::tokenScopeAllows($s, $p) ? "1" : "0"; } echo $p, "=", implode("", $row), " "; }
echo "ATOMS=", implode(",", Authz::TOKEN_SCOPE_ATOMS);
' "$ROOT/src/Authz.php" 2>&1)
#            열: read · r+u · r+u+d · c.write(옛) · s.open · c.read · c.read+c.write · 묶음 · upload(DB 직접) · read+admin · 빈 글자
expect "tokenScopeAllows 표(옛 다섯 + 묶음 + 의존이 깨진 행 + 모르는 원자 + 빈 글자)" \
  "authenticated=11111111100 file.read=11100001000 file.upload=01100001100 file.update=01100001100 file.delete=00100000000 community.read=00010111000 community.write=00010010000 session.open=00001001000 role:Admin=00000000000 file.fetch=00000000000 ATOMS=read,upload,delete,community.read,community.write,session.open" "$AZ"
NZ=$(php -r '
require $argv[1];
$cases = [["upload"], ["delete","read"], ["upload","read"], ["community.write"], ["x"], [], "read+upload+delete", "community.write",
          ["read","upload","community.read","session.open"], ["session.open","read","read"], "", [1], ["a" => "read"], ["READ"], "read+", ["delete","upload","read"]];
foreach ($cases as $c) { try { echo Authz::normalizeScope($c); } catch (InvalidArgumentException $e) { echo "X"; } echo "|"; }
' "$ROOT/src/Authz.php" 2>&1)
expect "normalizeScope 벡터(X = 거부)" \
  "X|X|read+upload|community.read+community.write|X|X|read+upload+delete|community.read+community.write|read+upload+community.read+session.open|read+session.open|X|X|X|X|X|read+upload+delete|" "$NZ"
expect "옛 상수 둘(FILE_TOKEN_SCOPES · TOKEN_SCOPES)을 쓰는 코드 0(src · public · scripts)" "0" \
  "$(grep -rnE 'FILE_TOKEN_SCOPES|[^_]TOKEN_SCOPES\b' "$ROOT/src" "$ROOT/public" "$ROOT/scripts" 2>/dev/null | grep -c .)"
CR="$ROOT/src/community/routes.php"
expect "커뮤니티 토큰 창구 표 여섯(Q:F-8 셋 + Q:F-10 읽기 셋)" "6 1 1 1" \
  "$(sed -n '/^const COMMUNITY_API_TOKEN_ROUTES = \[$/,/^\];$/p' "$CR" | grep -cE "^    '(GET|POST) /api/") $(sed -n '/^const COMMUNITY_API_TOKEN_ROUTES = \[$/,/^\];$/p' "$CR" | grep -c "^    'GET /api/search',") $(sed -n '/^const COMMUNITY_API_TOKEN_ROUTES = \[$/,/^\];$/p' "$CR" | grep -c "^    'GET /api/pages',") $(sed -n '/^const COMMUNITY_API_TOKEN_ROUTES = \[$/,/^\];$/p' "$CR" | grep -c "^    'GET /api/spaces',")"
expect "새 읽기 셋의 라우트 정책 = community.read(라우트 표 값 그대로)" "3" \
  "$(grep -cE "^\s*\['GET',\s*'/api/(search|pages|spaces)',\s*'(search|pages_list|spaces_list)\.php',\s*'community\.read'\]," "$CR")"
expect "고치지 않는 파일 차이 0 (public/index.php · Http · Db · db/grants.sql · 커뮤니티 핸들러 셋)" "0" \
  "$(git -C "$ROOT" diff "$BASE_REV" -- public/index.php src/Http.php src/Db.php db/grants.sql src/community/handlers/search.php src/community/handlers/pages_list.php src/community/handlers/spaces_list.php 2>/dev/null | wc -l)"
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
expect "SQL 에 옛 한 값 판정(scope = 'session.open') 0 — 원자 집합 판정만(src · scripts)" "0" \
  "$(grep -rnE "scope\s*(=|<>)\s*'session\.open'" "$ROOT/src" "$ROOT/scripts" 2>/dev/null | grep -c .)"

# ════════════════════════════════════════════════════════════════
hr "준비 — 임시 DB · 임시 인스턴스 (운영 사본에는 쓰지 않는다)"
port_busy "$PORT" && { echo "포트 $PORT 이 이미 쓰이고 있다 — 중단"; exit 3; }
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
CNT=$(psqlq "SELECT (SELECT count(*) FROM users) || ' ' || (SELECT count(*) FROM api_tokens);")
[ -n "$CNT" ] && [ "${CNT%% *}" -gt 0 ] 2>/dev/null || { echo "임시 DB 로 옮기지 못했다 — $(head -2 "$WORK/restore.err")"; exit 3; }
note "임시 DB $DB — 사용자 ${CNT%% *} · 토큰 ${CNT##* }"
M0=$(tdb psql -h 127.0.0.1 -p 5432 -U tbox_testdb -w -X -q -d "$DB" -v ON_ERROR_STOP=1 -f "$MIG9" 2>&1 >/dev/null; echo "rc=$?")
expect "Q:F-9 마이그레이션(2026-10-12 — 이 조각 앞 모양) 적용 rc" "rc=0" "$(printf '%s' "$M0" | tail -1)"

# ════════════════════════════════════════════════════════════════
hr "T-5 옛 값 — 마이그레이션 2026-10-13 (두 번 · 멱등 · 모르는 값이면 멈춤)"
make_token OT1 claude_employee01 'read' '';                 OT1_ID="$MADE_ID"
make_token OT2 claude_employee01 'read+upload' '';          OT2_ID="$MADE_ID"
make_token OT3 claude_employee01 'read+upload+delete' '';   OT3_ID="$MADE_ID"
make_token OT4 claude_employee01 'community.write' '';      OT4_ID="$MADE_ID"
make_token OT5 claude_employee01 'session.open' '10.1.2.3'; OT5_ID="$MADE_ID"
OLDIDS="${OT1_ID:-0},${OT2_ID:-0},${OT3_ID:-0},${OT4_ID:-0},${OT5_ID:-0}"
expect "옛 모양(Q:F-9) 표에 옛 다섯 값 행이 들어간다" "5" "$(psqlq "SELECT count(*) FROM api_tokens WHERE id IN ($OLDIDS)")"
ROWS_Q="SELECT string_agg(id || ':' || scope || ':' || coalesce(host(bound_ip),'-'), ' ' ORDER BY id) FROM api_tokens WHERE id IN ($OLDIDS)"
BEFORE=$(psqlq "$ROWS_Q")
SHAPE="SELECT string_agg(conname || '=' || pg_get_constraintdef(oid), ' | ' ORDER BY conname) FROM pg_constraint WHERE conname IN ('api_tokens_scope_check','api_tokens_bound_ip_check')
       UNION ALL SELECT indexdef FROM pg_indexes WHERE indexname = 'api_tokens_one_session_open'
       UNION ALL SELECT string_agg(privilege_type, ',' ORDER BY privilege_type) FROM information_schema.role_table_grants WHERE table_name = 'api_tokens' AND grantee = 'tbox_app'
       UNION ALL SELECT string_agg(column_name || ':' || data_type, ',' ORDER BY column_name) FROM information_schema.columns WHERE table_name = 'api_tokens';"
G0=$(psqlq "$SHAPE" | sed -n 3,4p)
M1=$(tdb psql -h 127.0.0.1 -p 5432 -U tbox_testdb -w -X -q -d "$DB" -v ON_ERROR_STOP=1 -f "$MIG" 2>&1 >/dev/null; echo "rc=$?")
S1=$(psqlq "$SHAPE"); R1=$(psqlq "$ROWS_Q")
M2=$(tdb psql -h 127.0.0.1 -p 5432 -U tbox_testdb -w -X -q -d "$DB" -v ON_ERROR_STOP=1 -f "$MIG" 2>&1 >/dev/null; echo "rc=$?")
S2=$(psqlq "$SHAPE"); R2=$(psqlq "$ROWS_Q")
expect "마이그레이션 첫 적용 rc" "rc=0" "$(printf '%s' "$M1" | tail -1)"
expect "마이그레이션 둘째 적용 rc(멱등)" "rc=0" "$(printf '%s' "$M2" | tail -1)"
expect "첫 적용과 둘째 적용의 모양(제약·인덱스·권한·열)이 같다" "$S1" "$S2"
expect "첫 적용과 둘째 적용의 행이 같다" "$R1" "$R2"
expect "권한·열 변화 0(마이그레이션 앞과 같다)" "$G0" "$(printf '%s\n' "$S2" | sed -n 3,4p)"
note "적용 뒤 제약: $(printf '%s' "$S2" | head -1 | head -c 600)"
expect "옛 다섯 값 → community.write 행만 community.read+community.write · 나머지 넷 그대로" \
  "${OT1_ID}:read:- ${OT2_ID}:read+upload:- ${OT3_ID}:read+upload+delete:- ${OT4_ID}:community.read+community.write:- ${OT5_ID}:session.open:10.1.2.3" "$R2"
expect "  (앞) 행 모양" "${OT1_ID}:read:- ${OT2_ID}:read+upload:- ${OT3_ID}:read+upload+delete:- ${OT4_ID}:community.write:- ${OT5_ID}:session.open:10.1.2.3" "$BEFORE"
expect "마이그레이션 — 트랜잭션 하나 · 앞 검사 RAISE · 되돌리기 절 · UPDATE 는 community.write 한 줄뿐 · DELETE·TRUNCATE 0" "1 1 1 1 1 0" \
  "$(grep -c '^BEGIN;$' "$MIG") $(grep -c '^COMMIT;$' "$MIG") $(grep -v '^--' "$MIG" | grep -c 'RAISE EXCEPTION') $(grep -c '^-- 되돌리기' "$MIG") $(grep -v '^--' "$MIG" | grep -ciE '^\s*UPDATE\b.*WHERE scope = .community\.write.;$') $(grep -v '^--' "$MIG" | grep -ciE '^\s*(DELETE|TRUNCATE)\b')"
# 새 설치(db/schema.sql)의 모양 = 마이그레이션 뒤 모양
tdb dropdb -h 127.0.0.1 -p 5432 -U tbox_testdb -w --if-exists "$SDB" >/dev/null 2>&1
tdb createdb -h 127.0.0.1 -p 5432 -U tbox_testdb -w "$SDB" >/dev/null 2>&1
SL=$(tdb psql -h 127.0.0.1 -p 5432 -U tbox_testdb -w -X -q -d "$SDB" -v ON_ERROR_STOP=1 -f "$ROOT/db/schema.sql" 2>&1 >/dev/null; echo "rc=$?")
expect "db/schema.sql 새 설치 rc" "rc=0" "$(printf '%s' "$SL" | tail -1)"
expect "db/schema.sql 의 제약·인덱스 = 마이그레이션 뒤 모양" "$(printf '%s\n' "$S2" | sed -n 1,2p)" \
  "$(printf '%s\n' "SELECT string_agg(conname || '=' || pg_get_constraintdef(oid), ' | ' ORDER BY conname) FROM pg_constraint WHERE conname IN ('api_tokens_scope_check','api_tokens_bound_ip_check') UNION ALL SELECT indexdef FROM pg_indexes WHERE indexname = 'api_tokens_one_session_open';" | tdb psql -h 127.0.0.1 -p 5432 -U tbox_testdb -w -X -q -At -d "$SDB" 2>/dev/null)"
# CHECK — 마지막 그물(포함 여부 · IP 짝)
UH=$(printf 'e%.0s' $(seq 1 64))
ins_err() {   # $1 범위 · $2 IP(없으면 빈 글자) · $3 해시 앞 글자 → 오류 글자(성공이면 빈 글자)
  psqle "INSERT INTO api_tokens (user_id, name, token_hash, hint, created_by, reason_text, scope, expires_at, bound_ip, revoked_at)
         SELECT id, 'QF10-chk', repeat('$3', 64), 'cccccc', id, 'CHECK 시험', '$1', now() + interval '1 day',
                $( [ -n "$2" ] && printf "CAST('%s' AS inet)" "$2" || printf 'NULL' ), now() FROM users WHERE username = 'claude_employee01'"
}
expect "CHECK — 모르는 원자(read+admin) 거부" "1" "$(ins_err 'read+admin' '' 'a' | grep -c 'api_tokens_scope_check')"
expect "CHECK — 빈 글자 거부" "1" "$(ins_err '' '' 'b' | grep -c 'api_tokens_scope_check')"
expect "CHECK — session.open 원자인데 IP 없음 거부" "1" "$(ins_err 'read+session.open' '' 'c' | grep -c 'api_tokens_bound_ip_check')"
expect "CHECK — IP 가 있는데 session.open 원자 없음 거부" "1" "$(ins_err 'read+upload' '10.0.0.1' 'd' | grep -c 'api_tokens_bound_ip_check')"
expect "CHECK — 순서·의존은 앱 몫(DB 는 upload 한 원자를 받는다 — 판정은 가진 원자만큼)" "" "$(ins_err 'upload' '' 'f')"
# 모르는 값이 있으면 멈춤 — 임시 DB 에서만 CHECK 를 잠깐 떼고 모르는 값 한 행을 넣는다
psqlq "ALTER TABLE api_tokens DROP CONSTRAINT api_tokens_scope_check" >/dev/null
make_token BADT claude_employee02 'admin' ''; BAD_ID="$MADE_ID"; BADT=''
psqlq "UPDATE api_tokens SET scope = 'community.write' WHERE id = ${OT4_ID:-0}" >/dev/null   # 되돌림 확인용 — 옛 값 하나를 다시 둔다
M3=$(tdb psql -h 127.0.0.1 -p 5432 -U tbox_testdb -w -X -q -d "$DB" -v ON_ERROR_STOP=1 -f "$MIG" 2>&1 >/dev/null; echo "rc=$?")
expect "모르는 값(admin) 한 행 → 마이그레이션 실패(rc≠0) · 문구 「모르는 값」" "1 1" \
  "$([ "$(printf '%s' "$M3" | tail -1)" != "rc=0" ] && echo 1 || echo 0) $(printf '%s' "$M3" | grep -c '모르는 값이 1 행')"
expect "  바뀐 것 없음 — 옛 community.write 행 그대로 · scope CHECK 없는 채로(ROLLBACK)" "community.write 0" \
  "$(psqlq "SELECT scope FROM api_tokens WHERE id = ${OT4_ID:-0}") $(psqlq "SELECT count(*) FROM pg_constraint WHERE conname = 'api_tokens_scope_check'")"
psqlq "DELETE FROM api_tokens WHERE id = ${BAD_ID:-0}" >/dev/null
M4=$(tdb psql -h 127.0.0.1 -p 5432 -U tbox_testdb -w -X -q -d "$DB" -v ON_ERROR_STOP=1 -f "$MIG" 2>&1 >/dev/null; echo "rc=$?")
expect "그 행을 지운 뒤 다시 → rc=0 · 모양이 첫 적용과 같다 · community.write 행 정규화" "rc=0 1 community.read+community.write" \
  "$(printf '%s' "$M4" | tail -1) $([ "$(psqlq "$SHAPE")" = "$S1" ] && echo 1 || echo 0) $(psqlq "SELECT scope FROM api_tokens WHERE id = ${OT4_ID:-0}")"
# 옛 다섯 행은 회수해 둔다(살아 있는 토큰 상한 5 · session.open 하나 규칙이 뒤 발급을 막지 않게 — 임시 DB)
psqlq "UPDATE api_tokens SET revoked_at = now() WHERE id IN ($OLDIDS) AND revoked_at IS NULL" >/dev/null

# 임시 인스턴스
INST="$(mktemp -d)"; chmod 700 "$INST"; mkdir -p "$INST/app" "$INST/storage" "$INST/log"
tar -C "$ROOT" -cf - --exclude=.git --exclude=jar --exclude=api-test.txt --exclude=./.env --exclude=./.claude --exclude=__pycache__ --exclude=./clients . | tar -C "$INST/app" -xf -
make_env "$ENV_TMP/env" "$INST/app/.env" "$INST/storage" "$INST/log/audit.log" "$ENV_TMP/shared" || { echo "임시 .env 에 임시 값이 들어가지 않았다 — 중단"; exit 4; }
ln -s /var/www/qaredi/community "$INST/community" 2>/dev/null
make_router "$INST/app"
note "임시 .env 확인: $(grep '^DB_NAME=' "$INST/app/.env")"
start_inst "$INST" "$PORT"; OK1=$?; SPID=$LAST_PID
expect "새 코드 인스턴스가 떴다($PORT)" "0" "$OK1"
[ "$OK1" = "0" ] || { tail -5 "$INST/server.log"; exit 4; }

hr "로그인 (claude_ 검토 계정, 순차, F-71)"
for who in claude_employee01 claude_employee02 claude_guest01; do
  login "$who" || { echo "  ✖ $who 로그인 실패 — 중단"; exit 4; }
done
note "세션 셋(claude_employee01 · claude_employee02 · claude_guest01)"

# ════════════════════════════════════════════════════════════════
hr "T-7(세션 갈래) — GET /api/me 의 token_scopes 는 원자 · 쥔 범위 두 칸은 없다"
curl -s -b "$JAR_DIR/claude_employee01.jar" -A "$UA" "$BASE/api/me" > "$WORK/me.json"
expect "claude_employee01 token_scopes = 원자 여섯(2단계 켜짐 · 쓰기 자격)" "read,upload,delete,community.read,community.write,session.open" \
  "$(jf "$WORK/me.json" "','.join(d.get('token_scopes',[]))")"
expect "세션 /api/me 에 token_scope · token_scopes_held 없음" "0 0" \
  "$(jf "$WORK/me.json" "int('token_scope' in d)") $(jf "$WORK/me.json" "int('token_scopes_held' in d)")"
curl -s -b "$JAR_DIR/claude_guest01.jar" -A "$UA" "$BASE/api/me" > "$WORK/gme.json"
expect "claude_guest01 token_scopes = read 하나(Guest — 커뮤니티·로그인 전용 0)" "read" "$(jf "$WORK/gme.json" "','.join(d.get('token_scopes',[]))")"

# ════════════════════════════════════════════════════════════════
hr "T-1 정규화 — 발급 창구"
A1=$(maxaudit)
FC0=$(psqlq "SELECT failed_count FROM users WHERE username = 'claude_employee01'")
BAD=0; R=""
for s in '["upload"]' '["delete","read"]' '["x"]' '[]' '[1]' '{"a":"read"}' '["READ"]' '"read+"' '""' '"read+admin"' 'null' '7'; do
  issue_as claude_employee01 TOK_X "$s" 000000
  R="$R $ISSUED_CODE"
  [ "$ISSUED_CODE" = "400" ] || { BAD=$((BAD+1)); echo "    ✖ $s → $ISSUED_CODE"; }
done
expect "틀린 범위 열둘(올리기만 · 읽기 없이 지우기 · 모르는 원자 · 빈 배열 · 숫자 · 객체 · 대문자 · 빈 조각 · 빈 글자 · 모르는 원자 문자열 · null · 숫자) → 전부 400" "0" "$BAD"
expect "  400 본문은 통일 문구(힌트 없음)" "잘못된 요청입니다" "$(printf '{"x":1}' > "$WORK/x.json"; sreq claude_employee01 POST "$BASE/api/me/api-tokens" "$WORK/x.json" 1 >/dev/null; err_of)"
expect "  비밀번호·코드 확인 전에 끝남 — 실패 집계 그대로 · 감사 api_token.issue deny bad_request 열셋" "$FC0 13" \
  "$(psqlq "SELECT failed_count FROM users WHERE username = 'claude_employee01'") $(psqlq "SELECT count(*) FROM audit_logs WHERE id > $A1 AND action = 'api_token.issue' AND result = 'deny' AND reason = 'bad_request'")"
issue_as claude_guest01 TOK_X '["community.read"]' 000000
expect "Guest 가 [community.read] → 400(고를 수 있는 원자 밖)" "400" "$ISSUED_CODE"
issue_as claude_guest01 TOK_X '["read","session.open"]' 000000
expect "Guest 가 [read, session.open] → 400" "400" "$ISSUED_CODE"
A1b=$(maxaudit)
issue_as claude_employee01 TOK_RU '["upload","read"]' next;  RU_ID="$ISSUED_ID"
expect "[upload, read] → 201 · scope=read+upload · scopes · bound_ip 없음" "201 read+upload read,upload " "$ISSUED_CODE $ISSUED_SCOPE $ISSUED_SCOPES $ISSUED_BIP"
expect "  DB 행 범위 = 정규형 · bound_ip NULL · 감사 token_scope 정규형" "read+upload t 1" \
  "$(psqlq "SELECT scope || ' ' || (bound_ip IS NULL)::text FROM api_tokens WHERE id = ${RU_ID:-0}" | sed 's/true/t/') $(psqlq "SELECT count(*) FROM audit_logs WHERE id > $A1b AND action = 'api_token.issue' AND result = 'allow' AND detail->>'token_scope' = 'read+upload' AND detail->>'token_id' = '${RU_ID:-0}'")"
issue_as claude_employee01 TOK_CW '["community.write"]' next; CW_ID="$ISSUED_ID"
expect "[community.write] → 201 · scope=community.read+community.write(읽기를 덧붙임)" "201 community.read+community.write community.read,community.write" "$ISSUED_CODE $ISSUED_SCOPE $ISSUED_SCOPES"
issue_as claude_employee01 TOK_RUD '"read+upload+delete"' next; RUD_ID="$ISSUED_ID"
expect "옛 문자열 \"read+upload+delete\" → 201 · 같은 글자" "201 read+upload+delete read,upload,delete" "$ISSUED_CODE $ISSUED_SCOPE $ISSUED_SCOPES"
curl -s -b "$JAR_DIR/claude_employee01.jar" -A "$UA" "$BASE/api/me/api-tokens" > "$WORK/list.json"
expect "내 토큰 목록 — scope 문자열 + scopes 배열(위 셋)" "read+upload=read,upload community.read+community.write=community.read,community.write read+upload+delete=read,upload,delete" \
  "$(jf "$WORK/list.json" "' '.join(t['scope'] + '=' + ','.join(t.get('scopes', ['?'])) for t in sorted(d['tokens'], key=lambda t: t['id']) if t['id'] in (${RU_ID:-0}, ${CW_ID:-0}, ${RUD_ID:-0}))")"

# ════════════════════════════════════════════════════════════════
hr "T-2 묶음 토큰 — [read, upload, community.read, session.open]"
# 커뮤니티 페이지 — claude_employee01(묶음 토큰 주인)의 비공개 한 장 · claude_employee02 의 비공개 한 장(세션으로 만든다)
TAGW="qf10w$$$(date +%s)"
mkpage() {   # $1 잼 · $2 제목 → id
  printf '{"title":"%s"}' "$2" > "$WORK/pg.json"
  sreq "$1" POST "$CBASE/api/pages" "$WORK/pg.json" 1 >/dev/null
  jf "$WORK/r.json" "d['page']['id']"
}
P1=$(mkpage claude_employee01 "${TAGW}a 일번 비공개")
P2=$(mkpage claude_employee02 "${TAGW}b 이번 비공개")
expect "세션으로 비공개 페이지 둘(employee01 · employee02)" "36 36" "${#P1} ${#P2}"
A2=$(maxaudit)
issue_as claude_employee01 TOK_B '["read","upload","community.read","session.open"]' next; B_ID="$ISSUED_ID"
expect "묶음 발급 → 201 · 정규형 · bound_ip 127.0.0.1 · 앞 로그인 전용 회수 0" "201 read+upload+community.read+session.open read,upload,community.read,session.open 127.0.0.1 0" \
  "$ISSUED_CODE $ISSUED_SCOPE $ISSUED_SCOPES $ISSUED_BIP $ISSUED_REVPREV"
expect "  DB 행 bound_ip · 감사 detail bound_ip" "127.0.0.1 1" \
  "$(psqlq "SELECT host(bound_ip) FROM api_tokens WHERE id = ${B_ID:-0}") $(psqlq "SELECT count(*) FROM audit_logs WHERE id > $A2 AND action = 'api_token.issue' AND result = 'allow' AND detail->>'bound_ip' = '127.0.0.1' AND detail->>'token_id' = '${B_ID:-0}'")"
[ -n "$TOK_B" ] || { echo "  ✖ 묶음 토큰이 없어 뒤 항목을 돌릴 수 없다 — 중단"; exit 4; }
C=$(treq TOK_B GET "$BASE/api/files");  expect "묶음 토큰 GET /api/files → 200" "200" "$C"
printf 'qf10 %s\n' "$TAGW" > "$WORK/up.txt"
C=$(curl_hdr_secret X-API-Token TOK_B -s -o "$WORK/r.json" -w '%{http_code}' -A "$UA" -F "file=@$WORK/up.txt" -F "level=internal" "$BASE/api/upload")
UPF=$(jf "$WORK/r.json" "d.get('id','')")
expect "묶음 토큰 POST /api/upload → 201" "201" "$C"
C=$(treq TOK_B DELETE "$BASE/api/files/${UPF:-00000000-0000-4000-8000-000000000000}")
expect "묶음 토큰 DELETE /api/files/{id}(delete 원자 없음) → 403 token_scope" "403 $MSG_SCOPE" "$C $(err_of)"
printf '{"title":"qf10 토큰"}' > "$WORK/cp.json"
C=$(treq TOK_B POST "$CBASE/api/pages" "$WORK/cp.json")
expect "묶음 토큰 POST /community/api/pages(community.write 없음) → 403 token_scope" "403 $MSG_SCOPE" "$C $(err_of)"
C=$(treq TOK_B GET "$CBASE/api/search?q=${TAGW}a")
expect "묶음 토큰 GET /community/api/search → 200 · 내 비공개 페이지가 결과에" "200 1" "$C $(jf "$WORK/r.json" "sum(1 for r in d['results'] if r['id'] == '$P1')")"
C=$(treq TOK_B POST "$BASE/api/me/session-link")
LINK_URL=$(jf "$WORK/r.json" "d.get('url','')"); LINK_CODE=${LINK_URL##*code=}; : > "$WORK/r.json"
expect "묶음 토큰 POST /api/me/session-link → 201" "201" "$C"
LTO="$SITE/tbox/session/open?code=$LINK_CODE&to=%2Fcommunity%2Fp%2F$P1"
OUT=$(curl_url_secret LTO -s -o /dev/null -c "$JAR_DIR/lk.jar" -A "$UA" -w '%{http_code} %{redirect_url}')
expect "링크 + to=/community/p/<id> → 302 그 페이지(Http::returnPath 허용 목록 — 비서 「검색 결과 열기」 경로)" "302 /community/p/$P1" "${OUT/$SITE/}"
LTO=''; LINK_URL=''; LINK_CODE=''
C=$(treq TOK_B GET "$BASE/api/whoami")
expect "묶음 토큰 whoami → 200 · token_scope · token_scopes_held" "200 read+upload+community.read+session.open read,upload,community.read,session.open" \
  "$C $(jf "$WORK/r.json" "d.get('token_scope','')") $(jf "$WORK/r.json" "','.join(d.get('token_scopes_held',[]))")"
expect "  감사 — 묶음 토큰 요청 행에 auth=api_token · token_scope 정규형" "1" \
  "$(psqlq "SELECT (count(*) > 0)::int FROM audit_logs WHERE id > $A2 AND detail->>'token_id' = '${B_ID:-0}' AND detail->>'auth' = 'api_token' AND detail->>'token_scope' = 'read+upload+community.read+session.open'")"

# ════════════════════════════════════════════════════════════════
hr "T-3 IP 묶음 확장 — 묶인 IP 밖에서는 그 토큰의 모든 창구가 401"
A3=$(maxaudit)
psqlq "UPDATE api_tokens SET bound_ip = '10.9.9.9' WHERE id = ${B_ID:-0}" >/dev/null
C=$(treq TOK_B GET "$BASE/api/files")
expect "GET /api/files → 401 token_ip_mismatch" "401 token_ip_mismatch" "$C $(code_of)"
note "401 본문: $(head -c 300 "$WORK/r.json")"
C=$(treq TOK_B GET "$CBASE/api/search?q=${TAGW}a"); expect "GET /community/api/search → 401 token_ip_mismatch" "401 token_ip_mismatch" "$C $(code_of)"
C=$(treq TOK_B GET "$BASE/api/whoami");             expect "GET /api/whoami → 401 token_ip_mismatch" "401 token_ip_mismatch" "$C $(code_of)"
N3A=$(psqlq "SELECT count(*) FROM audit_logs WHERE id > $A3 AND action = 'auth.token' AND result = 'deny' AND reason = 'ip_mismatch' AND detail->>'token_id' = '${B_ID:-0}' AND host(ip) = '127.0.0.1'")
N3B=$(psqlq "SELECT count(*) FROM audit_logs WHERE id > $A3 AND action = 'auth.token' AND reason = 'ip_mismatch' AND target_name = 'GET /api/files'")
expect "감사 auth.token deny ip_mismatch 세 행 — token_id · 요청 IP 칸 127.0.0.1 · 대상 라우트" "3 1" "$N3A $N3B"
expect "  그 사이 계정 활동 기록 0(틀린 곳의 요청으로는 계정을 읽지 않는다 — 거부 행 말고 다른 행 0)" "0" \
  "$(psqlq "SELECT count(*) FROM audit_logs WHERE id > $A3 AND NOT (action = 'auth.token' AND reason = 'ip_mismatch')")"
C=$(treq TOK_B POST "$BASE/api/me/session-link")
expect "링크 창구는 Q:F-9 답 그대로 — 403 ip_mismatch(분당 셈 뒤 · session.link deny)" "403 ip_mismatch 1" \
  "$C $(code_of) $(psqlq "SELECT count(*) FROM audit_logs WHERE id > $A3 AND action = 'session.link' AND result = 'deny' AND reason = 'ip_mismatch' AND detail->>'token_id' = '${B_ID:-0}'")"
C=$(treq TOK_RU GET "$BASE/api/files"); expect "묶이지 않은 토큰(read+upload)은 영향 0 → 200" "200" "$C"
C=$(treq SHARED_TOK GET "$BASE/api/files"); expect "공용(.env) 토큰도 영향 0 → 200" "200" "$C"
touch "$WORK/empty-auth.log"
( cd / && php "$INST/app/scripts/alerts.php" --rule AL-24 --dry-run --state "$WORK/al-state.json" --auth-log "$WORK/empty-auth.log" ) > "$WORK/al24.out" 2>&1
tail -2 "$WORK/al24.out" | sed 's/^/    /'
expect "alerts.php --rule AL-24 --dry-run → 한 줄(auth.token 셋 + session.link 하나 = 네 번 · 이 토큰)" "1 1" \
  "$(grep -c "\[dry-run\] AL-24 claude_employee01 .*\"token_id\":\"${B_ID:-0}\".*\"count\":4" "$WORK/al24.out") $(grep -c 'AL-24 대상 1건' "$WORK/al24.out")"
expect "  경보 출력에 토큰 원문 0" "0" "$(grep -v '^$' "$SEEN_TOKS" | grep -c -F -f - "$WORK/al24.out")"
psqlq "UPDATE api_tokens SET bound_ip = '127.0.0.1' WHERE id = ${B_ID:-0}" >/dev/null
C=$(treq TOK_B GET "$BASE/api/files"); expect "IP 를 되돌리면 → 200" "200" "$C"
# 검토 RV-QF10-1 — 링크 창구를 한 번도 안 부르고 파일 창구만 다른 IP 로 두드린 토큰(auth.token 행만 — actor_name NULL)도
#   경보에 **토큰 주인 이름**이 나온다(alerts.php 가 token_id 로 api_tokens → users 를 잇는다). 토큰은 임시 DB 에 직접(10.8.8.8 에 묶음).
make_token TOK_E2IP claude_employee02 'read+session.open' '10.8.8.8'; E2IP_ID="$MADE_ID"
for i in 1 2 3; do treq TOK_E2IP GET "$BASE/api/files" >/dev/null; done
expect "  파일 창구만 세 번 → auth.token deny ip_mismatch 세 행(actor_name NULL) · session.link 행 0" "3 3 0" \
  "$(psqlq "SELECT count(*) FROM audit_logs WHERE action = 'auth.token' AND reason = 'ip_mismatch' AND detail->>'token_id' = '${E2IP_ID:-0}'") $(psqlq "SELECT count(*) FROM audit_logs WHERE action = 'auth.token' AND reason = 'ip_mismatch' AND detail->>'token_id' = '${E2IP_ID:-0}' AND actor_name IS NULL") $(psqlq "SELECT count(*) FROM audit_logs WHERE action = 'session.link' AND detail->>'token_id' = '${E2IP_ID:-0}'")"
( cd / && php "$INST/app/scripts/alerts.php" --rule AL-24 --dry-run --state "$WORK/al-state2.json" --auth-log "$WORK/empty-auth.log" ) > "$WORK/al24b.out" 2>&1
grep "token_id\":\"${E2IP_ID:-0}\"" "$WORK/al24b.out" | head -1 | cut -c1-200 | sed 's/^/    /'
expect "  alerts.php --rule AL-24 --dry-run → 그 토큰 줄에 주인 이름 claude_employee02(? 아님)" "1 0" \
  "$(grep -c "\[dry-run\] AL-24 claude_employee02 .*\"token_id\":\"${E2IP_ID:-0}\"" "$WORK/al24b.out") $(grep -c "\[dry-run\] AL-24 ? .*\"token_id\":\"${E2IP_ID:-0}\"" "$WORK/al24b.out")"
psqlq "UPDATE api_tokens SET revoked_at = now() WHERE id = ${E2IP_ID:-0}" >/dev/null
TOK_E2IP=''

# ════════════════════════════════════════════════════════════════
hr "T-4 하나 규칙 — session.open 이 든 토큰은 사람당 살아 있는 것 하나"
A4=$(maxaudit)
issue_as claude_employee01 TOK_B2 '["community.read","session.open"]' next; B2_ID="$ISSUED_ID"
expect "살아 있는 묶음(session.open 포함)이 있는데 또 하나(다른 원자 섞어) → 201 · revoked_previous=1(지금 규칙 — 앞 것 자동 회수)" \
  "201 community.read+session.open 1" "$ISSUED_CODE $ISSUED_SCOPE $ISSUED_REVPREV"
expect "  앞 묶음 토큰은 회수됨 · 살아 있는 session.open 원자 토큰 하나 · 감사 api_token.revoke replaced" "true 1 1" \
  "$(psqlq "SELECT (revoked_at IS NOT NULL)::text FROM api_tokens WHERE id = ${B_ID:-0}") $(psqlq "SELECT count(*) FROM api_tokens t JOIN users u ON u.id = t.user_id WHERE u.username = 'claude_employee01' AND 'session.open' = ANY (string_to_array(t.scope, '+')) AND t.revoked_at IS NULL") $(psqlq "SELECT count(*) FROM audit_logs WHERE id > $A4 AND action = 'api_token.revoke' AND reason = 'replaced' AND detail->>'token_id' = '${B_ID:-0}'")"
C=$(treq TOK_B GET "$BASE/api/files"); expect "  회수된 앞 묶음 토큰으로 파일 → 401(틀린 토큰과 같은 문구)" "401 API 토큰이 올바르지 않습니다" "$C $(err_of)"
C=$(treq TOK_RU GET "$BASE/api/files"); expect "  session.open 없는 토큰은 회수되지 않는다(read+upload 200)" "200" "$C"
IDXERR=$(psqle "INSERT INTO api_tokens (user_id, name, token_hash, hint, created_by, reason_text, scope, expires_at, bound_ip) SELECT id, 'QF10-db-dup', repeat('9', 64), '999999', id, 'Q:F-10 유일 인덱스 시험', 'read+session.open', now() + interval '1 day', CAST('127.0.0.1' AS inet) FROM users WHERE username = 'claude_employee01'")
expect "DB 직접 — 둘째 살아 있는 read+session.open 행 → 유일 위반(api_tokens_one_session_open)" "1" "$(printf '%s' "$IDXERR" | grep -c 'api_tokens_one_session_open')"
C=$(sreq claude_employee01 DELETE "$BASE/api/me/api-tokens/${B2_ID:-0}" "" 1); expect "세션으로 그 토큰 회수 → 200" "200" "$C"
issue_as claude_employee01 TOK_B3 '"session.open+read"' next; B3_ID="$ISSUED_ID"
expect "회수 뒤 발급(옛 문자열 모양 · 순서 뒤집음) → 201 · 정규형 read+session.open · revoked_previous=0" "201 read+session.open 0 127.0.0.1" \
  "$ISSUED_CODE $ISSUED_SCOPE $ISSUED_REVPREV $ISSUED_BIP"
expect "  살아 있는 토큰 셈 — session.open 원자 토큰은 상한 셈에서 빠진다(n_other 기준) · 지금 employee01 의 살아 있는 토큰" "4" \
  "$(psqlq "SELECT count(*) FROM api_tokens t JOIN users u ON u.id = t.user_id WHERE u.username = 'claude_employee01' AND t.revoked_at IS NULL")"

# ════════════════════════════════════════════════════════════════
hr "T-6 커뮤니티 읽기 토큰 — [community.read] (claude_employee02)"
issue_as claude_employee02 TOK_CR '["community.read"]' next; CR_ID="$ISSUED_ID"
expect "claude_employee02 [community.read] → 201" "201 community.read community.read" "$ISSUED_CODE $ISSUED_SCOPE $ISSUED_SCOPES"
[ -n "$TOK_CR" ] || { echo "  ✖ 읽기 토큰이 없어 뒤 항목을 돌릴 수 없다 — 중단"; exit 4; }
A6=$(maxaudit)
# 공간 번호 — employee02 의 개인 공간(세션) · employee01 의 개인 공간(세션)
sreq claude_employee02 GET "$CBASE/api/me/space" "" 0 >/dev/null; SP2=$(jf "$WORK/r.json" "d['space']['id']")
sreq claude_employee01 GET "$CBASE/api/me/space" "" 0 >/dev/null; SP1=$(jf "$WORK/r.json" "d['space']['id']")
note "개인 공간 employee02=$SP2 · employee01=$SP1"
# 토큰 vs 세션 — 같은 창구·같은 사람의 답(상태 코드 + 본문)을 나란히
pair() {   # $1 설명 · $2 경로(커뮤니티 모듈 경로) · $3 기대 상태
  local tc sc tb sb
  tc=$(treq TOK_CR GET "$CBASE$2"); cp "$WORK/r.json" "$WORK/t.json"
  sc=$(sreq claude_employee02 GET "$CBASE$2" "" 0); cp "$WORK/r.json" "$WORK/s.json"
  # `GET /api/spaces` 의 `created` 는 「이 요청이 팀 공간을 처음 만들었나」(spaces_list.php 의 게으른 만들기 — 먼저 부른 쪽만 참)라 뺀다.
  tb=$(python3 -c 'import json,sys; d=json.load(open(sys.argv[1])); (d.pop("created", None) if isinstance(d, dict) else None); print(json.dumps(d, sort_keys=True))' "$WORK/t.json" 2>/dev/null)
  sb=$(python3 -c 'import json,sys; d=json.load(open(sys.argv[1])); (d.pop("created", None) if isinstance(d, dict) else None); print(json.dumps(d, sort_keys=True))' "$WORK/s.json" 2>/dev/null)
  expect "$1 — 토큰 상태 $3 · 세션과 같은 상태" "$3 $3" "$tc $sc"
  expect "  본문이 세션과 같다" "$sb" "$tb"
}
pair "검색 q=<내 비공개 제목>"              "/api/search?q=${TAGW}b" 200
expect "  결과에 내 비공개 페이지" "1" "$(jf "$WORK/t.json" "sum(1 for r in d['results'] if r['id'] == '$P2')")"
pair "검색 q=<남(employee01)의 비공개 제목>" "/api/search?q=${TAGW}a" 200
expect "  남의 비공개 페이지는 결과에 없다" "0" "$(jf "$WORK/t.json" "sum(1 for r in d['results'] if r['id'] == '$P1')")"
pair "공간 목록 GET /api/spaces"           "/api/spaces" 200
expect "  (기록) 읽기 토큰의 첫 공간 목록이 팀 공간을 게으르게 만들면 그 행에 auth=api_token(세션과 같은 부작용 — 핸들러 무변경)" "0" \
  "$(psqlq "SELECT count(*) FROM audit_logs WHERE id > $A6 AND action = 'space.create' AND NOT (detail ? 'auth')")"
pair "내 공간의 목록 GET /api/pages?space=" "/api/pages?space=${SP2}" 200
expect "  목록에 내 비공개 페이지" "1" "$(jf "$WORK/t.json" "sum(1 for p in d.get('pages', []) if p.get('id') == '$P2')")"
SC1=$(sreq claude_employee02 GET "$CBASE/api/pages?space=${SP1}" "" 0)
pair "남의 개인 공간 목록 GET /api/pages?space=<employee01>" "/api/pages?space=${SP1}" "$SC1"
pair "내 페이지 GET /api/pages/{id}"        "/api/pages/$P2" 200
SC2=$(sreq claude_employee02 GET "$CBASE/api/pages/$P1" "" 0)
pair "남의 비공개 페이지 GET /api/pages/{id}" "/api/pages/$P1" "$SC2"
expect "  그 답은 거부(403/404)" "1" "$(case "$SC2" in 403|404) echo 1 ;; *) echo 0 ;; esac)"
printf '{"title":"qf10 읽기 토큰"}' > "$WORK/cp.json"
C=$(treq TOK_CR POST "$CBASE/api/pages" "$WORK/cp.json"); expect "읽기 토큰 POST /community/api/pages → 403 token_scope" "403 $MSG_SCOPE" "$C $(err_of)"
C=$(treq TOK_CR GET "$BASE/api/files");                  expect "읽기 토큰 GET /api/files → 403 token_scope" "403 $MSG_SCOPE" "$C $(err_of)"
C=$(treq TOK_CR POST "$BASE/api/me/session-link");       expect "읽기 토큰 POST /api/me/session-link → 403 token_scope" "403" "$C"
BAD=0
for r in "PATCH $CBASE/api/pages/$P2" "DELETE $CBASE/api/pages/$P2" "GET $CBASE/api/me/favorites" "GET $CBASE/api/me/recent" "POST $CBASE/api/pages/$P2/comments" "GET $CBASE/api/boards/1/pages"; do
  printf '{}' > "$WORK/e.json"
  C=$(treq TOK_CR ${r%% *} "${r#* }" "$WORK/e.json")
  [ "$C" = "401" ] || { BAD=$((BAD+1)); echo "    ✖ $r → $C"; }
done
expect "토큰 창구 표 밖 커뮤니티 창구(고치기·지우기·즐겨찾기·최근·댓글·게시판) → 401(세션 경로 — 토큰을 읽지 않는다)" "0" "$BAD"
expect "감사 — 읽기 토큰 요청 행에 auth=api_token · token_id(검색·페이지 읽기)" "1" \
  "$(psqlq "SELECT (count(*) > 0)::int FROM audit_logs WHERE id > $A6 AND detail->>'token_id' = '${CR_ID:-0}' AND detail->>'auth' = 'api_token'")"
C=$(treq TOK_CW GET "$CBASE/api/search?q=${TAGW}a")
expect "커뮤니티 글쓰기 토큰(community.read+community.write)도 검색 200 · 내 페이지" "200 1" "$C $(jf "$WORK/r.json" "sum(1 for r in d['results'] if r['id'] == '$P1')")"
C=$(treq TOK_RUD GET "$CBASE/api/search?q=${TAGW}a")
expect "파일 토큰(read+upload+delete)은 검색 403 token_scope" "403" "$C"
# 옛 행(community.write 한 원자 — 마이그레이션 전 모양)도 읽기로 통한다(이중 안전 — DB 에 직접)
make_token TOK_OLDCW claude_employee01 'community.write' ''; OLDCW_ID="$MADE_ID"
C=$(treq TOK_OLDCW GET "$CBASE/api/search?q=${TAGW}a"); expect "옛 문자열 community.write 한 원자 토큰도 검색 200(판정이 쓰기를 읽기로 인정)" "200" "$C"
psqlq "UPDATE api_tokens SET revoked_at = now() WHERE id = ${OLDCW_ID:-0}" >/dev/null
# 의존이 깨진 행(DB 직접 upload 한 원자) — 가진 원자만큼만(읽기 창구는 닫힘)
make_token TOK_UP claude_employee02 'upload' ''; UP_ID="$MADE_ID"
C=$(treq TOK_UP GET "$BASE/api/files"); expect "DB 직접 upload 한 원자 토큰 GET /api/files → 403(read 원자가 없다 — 넓어지지 않는다)" "403" "$C"
psqlq "UPDATE api_tokens SET revoked_at = now() WHERE id = ${UP_ID:-0}" >/dev/null

# ════════════════════════════════════════════════════════════════
hr "T-7(토큰 갈래) — GET /api/whoami 의 쥔 범위"
C=$(treq TOK_CR GET "$BASE/api/whoami")
expect "읽기 토큰 whoami → 200 · 키 여섯(순서) · token_scope · token_scopes_held" "200 username,role,team_id,upload_levels,token_scope,token_scopes_held community.read community.read" \
  "$C $(jf "$WORK/r.json" "','.join(d.keys())") $(jf "$WORK/r.json" "d.get('token_scope','')") $(jf "$WORK/r.json" "','.join(d.get('token_scopes_held',[]))")"
C=$(treq TOK_CW GET "$BASE/api/whoami")
expect "커뮤니티 글쓰기 토큰 whoami → token_scopes_held 둘" "200 community.read,community.write" "$C $(jf "$WORK/r.json" "','.join(d.get('token_scopes_held',[]))")"
C=$(treq SHARED_TOK GET "$BASE/api/whoami")
expect "공용(.env) 토큰 whoami → read+upload(이행 규칙 그대로)" "200 read+upload read,upload" "$C $(jf "$WORK/r.json" "d.get('token_scope','')") $(jf "$WORK/r.json" "','.join(d.get('token_scopes_held',[]))")"

# ════════════════════════════════════════════════════════════════
hr "끝 — 토큰 원문 0"
tdb pg_dump -h 127.0.0.1 -p 5432 -U tbox_testdb -w --data-only -t audit_logs -t api_tokens "$DB" > "$WORK/dump.sql" 2>/dev/null
expect "이 시험이 받은·만든 토큰 수(0 이면 대조가 헛돈다)" "yes" "$([ "$(grep -c . "$SEEN_TOKS")" -ge 10 ] && echo yes || echo "no($(grep -c . "$SEEN_TOKS"))")"
expect "임시 DB 덤프(감사·토큰 표)에 토큰 원문 0" "0" "$(grep -v '^$' "$SEEN_TOKS" | grep -c -F -f - "$WORK/dump.sql")"
expect "감사 파일(audit.log)에 토큰 원문 0" "0" "$(grep -v '^$' "$SEEN_TOKS" | grep -c -F -f - "$INST/log/audit.log")"

if [ "${REGRESS:-0}" = "1" ]; then
  hr "T-8 회귀 — 같은 임시 인스턴스·임시 DB 에서(qf9_pc_login.sh 의 회귀 틀 · 8082 → $SITE · tbox_re → $DB)"
  note "이 시험의 토큰 회수(임시 DB): $(psqlq "WITH r AS (UPDATE api_tokens SET revoked_at = now() WHERE revoked_at IS NULL AND name LIKE 'QF10-%' RETURNING 1) SELECT count(*) FROM r")개"
  for s in ${REGRESS_LIST:-api_tokens api_whoami}; do
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
expect "이 시험이 부른 주소는 임시 인스턴스뿐(127.0.0.1:8451~8455)" "1" "$(case "$SITE" in http://127.0.0.1:845[1-5]) echo 1 ;; *) echo 0 ;; esac)"
TOK_X=''; TOK_RU=''; TOK_CW=''; TOK_RUD=''; TOK_B=''; TOK_B2=''; TOK_B3=''; TOK_CR=''; TOK_OLDCW=''; TOK_UP=''; SHARED_TOK=''
OT1=''; OT2=''; OT3=''; OT4=''; OT5=''

printf '\n통과 %d · 실패 %d\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ]
