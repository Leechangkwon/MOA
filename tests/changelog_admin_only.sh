#!/usr/bin/env bash
# tests/changelog_admin_only.sh — 「변경 이력」 창의 변경 내용 목록은 관리자에게만 (T:F-47 · PRD S-01 · S-03)
#
#   사용자 결정 2026-09-29 — 「버전 기록의 상세 패치 내용은 관리자만, 일반 사용자는 텍스트(제목)만.
#   로그인 구조 같은 민감한 내용이 다 보인다」.
#
#   (a) 정적   shell_bottom.php 에 역할 문자열 비교 0 · 판정은 Authz::canAdmin 한 줄(/api/me can_admin 과 같은 함수) ·
#              Changelog::headlines() 가 changes 칸을 내보내지 않음 · php -l · app.js node --check · security_lint 0
#   (b) 로그인 전  /tbox/login 에 changelog-dialog·changelog__list 0 · 로그인 없는 / 는 셸을 그리지 않는다(changelog__list 0)
#   (c) 관리자    claude_admin01 — /tbox/ · /community/ 셸에 changelog__list 있음 · 첫 항목 제목·버전·첫 변경 문장 있음
#   (d) 일반      claude_employee01 — 같은 두 셸에 changelog__list 0 · 첫 변경 문장 0 · 제목·버전·등급 표기는 있음
#   (e) 보는 중   claude_admin01 이 Employee 로 보기(POST /api/me/act) → 목록 0 · 되돌리기(DELETE) → 목록 다시 있음
#                 (재인증 관문 등으로 보기를 못 열면 「미검증」으로 적고 실패로 세지 않는다)
#   서버 로그     임시 인스턴스 로그에 PHP 오류 0
#
# ★ 운영 DB·운영 포트를 건드리지 않는다 — 임시 DB `tbox_cladm_<포트>_test`(tbox_testdb · 운영 사본 tbox_re 는 pg_dump 로 **읽기만**) ·
#   임시 인스턴스 하나(포트 8431~8433, CLADM_PORT) · 검토 계정 claude_ 둘(F-71) · UA `tbox-review/<날짜>` ·
#   비밀(비밀번호·TOTP·CSRF)은 표준입력으로만(R-62 · tests/lib/secrets.sh). 임시 .env 는 운영 .env 를 mktemp 폴더(0700)에 0600 으로
#   복사해 DB_NAME·STORAGE_DIR·AUDIT_LOG_FILE 을 임시로 바꾼 것 · 끝에 지운다. 데이터를 만드는 쓰기는 「보는 중」 시작·끝(임시 DB 세션 행)뿐.
# 쓰는 법: bash tests/changelog_admin_only.sh      환경 CLADM_PORT(8431~8433, 기본 8431)
set -u

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
. "$ROOT/tests/lib/review_accounts.sh" || { echo "tests/lib/review_accounts.sh 를 읽지 못했다 — 시험을 시작하지 않는다"; exit 3; }
. "$ROOT/tests/lib/secrets.sh" || { echo "tests/lib/secrets.sh 를 읽지 못했다 — 시험을 시작하지 않는다 (R-62)"; exit 3; }

PORT="${CLADM_PORT:-8431}"
case "$PORT" in 8431|8432|8433) ;; *) echo "이 시험은 포트 8431~8433 만 쓴다 — $PORT"; exit 3 ;; esac
SITE="http://127.0.0.1:$PORT"
UA="tbox-review/$(date +%F)"
SRC_DB="tbox_re"                      # 운영 사본 — pg_dump 로 **읽기만**
DB="tbox_cladm_${PORT}_test"          # 임시 — 여기에만 쓴다
PGP_TEST="$HOME/.config/qaredi/pgpass-testdb"
ADMIN="claude_admin01"
EMP="claude_employee01"
JAR_DIR="$(mktemp -d)"
WORK="$(mktemp -d)"
chmod 700 "$JAR_DIR" "$WORK"
INST=""; SPID=""; ENV_TMP=""
PASS=0
FAIL=0
UNVERIFIED=0

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
  tdb dropdb -h 127.0.0.1 -p 5432 -U tbox_testdb -w --if-exists "$DB" >/dev/null 2>&1
  [ -n "$INST" ] && rm -rf "$INST"
  [ -n "$ENV_TMP" ] && rm -rf "$ENV_TMP"
  rm -rf "$JAR_DIR" "$WORK"
}
trap cleanup EXIT

review_password() { sudo -n awk -v u="$1" '$1==u{print $3}' /etc/tbox-v2/seed-accounts.txt 2>/dev/null; }
review_secret()   { sudo -n awk -v u="$1" '$1==u{print $2}' /etc/tbox-v2/qa-totp.txt 2>/dev/null; }

login() {   # $1 계정 — 쿠키는 $JAR_DIR/<계정>.jar
  local who="$1" jar="$JAR_DIR/$1.jar" pw sec raw code left out try body
  case "$who" in claude_*) ;; *) echo "검토 계정(claude_)만 쓴다 — $who"; return 2 ;; esac
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

# 셸 HTML 받기 — $1 잼 이름(빈 글자면 쿠키 없이) · $2 경로 · $3 저장 파일. 출력은 HTTP 상태
page() {
  local copts=(-s -o "$3" -w '%{http_code}' -A "$UA")
  [ -n "$1" ] && copts+=(-b "$JAR_DIR/$1.jar")
  curl "${copts[@]}" "$SITE$2"
}
cnt() { grep -o -- "$1" "$2" 2>/dev/null | wc -l | tr -d ' '; }
cntF() { grep -oF -- "$1" "$2" 2>/dev/null | wc -l | tr -d ' '; }

make_env() {   # $1 원본 $2 대상 $3 저장 폴더 $4 감사 파일. 값은 출력하지 않는다.
  # qcheck-allow: R20 — 임시 인스턴스용 사본(0700 폴더 · 0600 · trap 정리) · kill -9 뒤 남는 창은 받아들인 위험(HO-B1-004 · qf9_pc_login.sh make_env 와 같은 판단)
  sed -e "s#^DB_NAME=.*#DB_NAME=$DB#" \
      -e "s#^STORAGE_DIR=.*#STORAGE_DIR=$3#" \
      -e "s#^AUDIT_LOG_FILE=.*#AUDIT_LOG_FILE=$4#" \
      -e "s#^SCAN_ENABLED=.*#SCAN_ENABLED=0#" "$1" > "$2"
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
    http_response_code(404);
    return true;
}
require $root . '/index.php';
PHP
}

# ════════════════════════════════════════════════════════════════
hr "(a) 정적 — 판정 한 곳 · 역할 문자열 0 · 문법 · 린트"
SB="$ROOT/src/views/shell_bottom.php"
code_only() { grep -v '^\s*\*\|^\s*//\|^\s*/\*' "$1"; }
expect "shell_bottom.php 코드 줄에 역할 문자열('Admin'·'Manager'·'Employee'·'Guest') 0" "0" \
  "$(code_only "$SB" | grep -cE "'(Admin|Manager|Employee|Guest)'|\"(Admin|Manager|Employee|Guest)\"")"
expect "shell_bottom.php 코드 줄에 role 칸 직접 읽기 0" "0" "$(code_only "$SB" | grep -cE "\['(real_)?role'\]")"
expect "목록 판정은 Authz::canAdmin 한 줄(/api/me can_admin 과 같은 함수)" "1 1" \
  "$(code_only "$SB" | grep -c 'Authz::canAdmin($shellUser)') $(grep -c "'can_admin'        => Authz::canAdmin(\$user)," "$ROOT/src/handlers/me.php")"
HL=$(php -r 'require $argv[1]; $h = Changelog::headlines(); $e = Changelog::entries();
  $keys = []; foreach ($h as $x) { $keys[implode(",", array_keys($x))] = 1; }
  echo count($h) === count($e) ? "same" : "diff", " ", implode("|", array_keys($keys));' "$ROOT/src/Changelog.php" 2>&1)
expect "Changelog::headlines() — 항목 수 같음 · 칸은 네 개(changes 없음)" "same version,level,released_at,title" "$HL"
PHPBAD=0
for f in src/views/shell_bottom.php src/Changelog.php; do php -l "$ROOT/$f" >/dev/null 2>&1 || { PHPBAD=$((PHPBAD+1)); echo "    php -l 실패: $f"; }; done
expect "바뀐 PHP php -l 실패 0" "0" "$PHPBAD"
node --check "$ROOT/public/assets/app.js" >/dev/null 2>&1; expect "app.js node --check" "0" "$?"
bash "$ROOT/scripts/security_lint.sh" "$ROOT" > "$WORK/lint.txt" 2>&1
expect "보안 린트 통과(금지 패턴 0건)" "1" "$(grep -c '결과: 통과' "$WORK/lint.txt")"

# 기대값 — 파일의 첫 항목(최신)에서
php -r 'require $argv[1]; $e = Changelog::entries(); $f = $e[0] ?? null;
  echo $f === null ? "" : $f["version"], "\n", $f === null ? "" : htmlspecialchars($f["title"], ENT_QUOTES, "UTF-8"), "\n",
       $f === null ? "" : htmlspecialchars($f["changes"][0], ENT_QUOTES, "UTF-8"), "\n", $f === null ? "" : Changelog::levelLabel($f["level"]), "\n", count($e), "\n";' \
  "$ROOT/src/Changelog.php" > "$WORK/first.txt" 2>&1
VER=$(sed -n 1p "$WORK/first.txt"); TITLE=$(sed -n 2p "$WORK/first.txt"); CHANGE=$(sed -n 3p "$WORK/first.txt")
LABEL=$(sed -n 4p "$WORK/first.txt"); NENT=$(sed -n 5p "$WORK/first.txt")
[ -n "$VER" ] && [ -n "$TITLE" ] && [ -n "$CHANGE" ] || { echo "CHANGELOG.json 첫 항목을 읽지 못했다 — $(head -3 "$WORK/first.txt")"; exit 3; }
note "첫 항목 v$VER · 등급 $LABEL · 항목 $NENT개"

# ════════════════════════════════════════════════════════════════
hr "준비 — 임시 DB · 임시 인스턴스 (운영 사본에는 쓰지 않는다)"
port_busy "$PORT" && { echo "포트 $PORT 이 이미 쓰이고 있다 — 중단"; exit 3; }
[ -r "$PGP_TEST" ] || { echo "임시 DB 역할(tbox_testdb)의 pgpass 가 없다 — $PGP_TEST"; exit 3; }
ENV_TMP="$(mktemp -d)"; chmod 700 "$ENV_TMP"
# qcheck-allow: R20 — 임시 인스턴스용 사본(0700 폴더 · 0600 · trap 정리)
( umask 077; cp /var/www/qaredi/tbox-v2/.env "$ENV_TMP/env" ) 2>/dev/null || { echo "운영 .env 를 읽지 못했다"; exit 3; }
chmod 600 "$ENV_TMP/env"
tdb dropdb -h 127.0.0.1 -p 5432 -U tbox_testdb -w --if-exists "$DB" >/dev/null 2>&1
tdb createdb -h 127.0.0.1 -p 5432 -U tbox_testdb -w "$DB" >/dev/null 2>&1 || { echo "임시 DB 를 만들 수 없다(tbox_testdb)"; exit 3; }
( cd / && sudo -n -u postgres pg_dump --no-owner -d "$SRC_DB" 2>/dev/null ) \
  | tdb psql -h 127.0.0.1 -p 5432 -U tbox_testdb -w -X -q -d "$DB" -v ON_ERROR_STOP=0 >/dev/null 2>"$WORK/restore.err"
CNT=$(psqlq "SELECT count(*) FROM users;")
[ -n "$CNT" ] && [ "$CNT" -gt 0 ] 2>/dev/null || { echo "임시 DB 로 옮기지 못했다 — $(head -2 "$WORK/restore.err")"; exit 3; }
note "임시 DB $DB — 사용자 $CNT"
# 운영에 아직 적용되지 않은 마이그레이션 — 작업 트리의 코드가 기대하는 모양을 임시 DB 에만 맞춘다(운영 사본은 읽기만).
# 기준 이름(CLADM_MIG_FROM, 기본 2026-10-12 — 운영 적용 전인 Q:F-9 부터)과 같거나 뒤인 파일을 이름 차례로 한 번씩.
MIG_FROM="${CLADM_MIG_FROM:-2026-10-12}"
for m in $(ls "$ROOT/db/migrations" | sort); do
  [ "$(printf '%s\n%s\n' "$MIG_FROM" "$m" | sort | head -1)" = "$MIG_FROM" ] || continue
  R=$(tdb psql -h 127.0.0.1 -p 5432 -U tbox_testdb -w -X -q -d "$DB" -v ON_ERROR_STOP=1 -f "$ROOT/db/migrations/$m" 2>&1 >/dev/null; echo "rc=$?")
  expect "임시 DB 에 마이그레이션 $m" "rc=0" "$(printf '%s' "$R" | tail -1)"
done
expect "검토 계정 역할(임시 DB) — $ADMIN=Admin · $EMP=Employee" "Admin Employee" \
  "$(psqlq "SELECT (SELECT role FROM users WHERE username = '$ADMIN') || ' ' || (SELECT role FROM users WHERE username = '$EMP')")"

INST="$(mktemp -d)"; chmod 700 "$INST"; mkdir -p "$INST/app" "$INST/storage" "$INST/log"
tar -C "$ROOT" -cf - --exclude=.git --exclude=jar --exclude=api-test.txt --exclude=./.env --exclude=./.claude --exclude=__pycache__ --exclude=./clients . | tar -C "$INST/app" -xf -
make_env "$ENV_TMP/env" "$INST/app/.env" "$INST/storage" "$INST/log/audit.log" || { echo "임시 .env 에 임시 값이 들어가지 않았다 — 중단"; exit 4; }
ln -s /var/www/qaredi/community "$INST/community" 2>/dev/null
make_router "$INST/app"
note "임시 .env 확인: $(grep '^DB_NAME=' "$INST/app/.env")"
( cd "$INST/app" && exec php -S "127.0.0.1:$PORT" -t public router.php > "$INST/server.log" 2>&1 ) &
SPID=$!
C=""; for i in $(seq 1 30); do sleep 0.3; C=$(curl -s -o /dev/null -w '%{http_code}' -A "$UA" "$SITE/tbox/login"); [ "$C" = "200" ] && break; done
expect "임시 인스턴스가 떴다($PORT)" "200" "$C"
[ "$C" = "200" ] || { tail -5 "$INST/server.log"; exit 4; }

# ════════════════════════════════════════════════════════════════
hr "(b) 로그인 전"
C=$(page "" /tbox/login "$WORK/login.html")
expect "GET /tbox/login 200" "200" "$C"
expect "로그인 화면에 changelog-dialog · changelog__list 0" "0 0" "$(cnt 'changelog-dialog' "$WORK/login.html") $(cnt 'changelog__list' "$WORK/login.html")"
expect "로그인 화면에 첫 변경 문장 0" "0" "$(cntF "$CHANGE" "$WORK/login.html")"
C=$(page "" /tbox/ "$WORK/anon.html")
note "쿠키 없이 GET /tbox/ → $C"
expect "쿠키 없는 /tbox/ 응답에 changelog__list · 첫 변경 문장 0" "0 0" "$(cnt 'changelog__list' "$WORK/anon.html") $(cntF "$CHANGE" "$WORK/anon.html")"

# ════════════════════════════════════════════════════════════════
hr "로그인 (claude_ 검토 계정, 순차, F-71)"
login "$ADMIN"; expect "$ADMIN 로그인" "0" "$?"
login "$EMP";   expect "$EMP 로그인" "0" "$?"
expect "/api/me can_admin — $ADMIN true · $EMP false" "True False" \
  "$(curl -s -b "$JAR_DIR/$ADMIN.jar" -A "$UA" "$SITE/tbox/api/me" | python3 -c 'import json,sys; print(json.load(sys.stdin).get("can_admin"))' 2>/dev/null) $(curl -s -b "$JAR_DIR/$EMP.jar" -A "$UA" "$SITE/tbox/api/me" | python3 -c 'import json,sys; print(json.load(sys.stdin).get("can_admin"))' 2>/dev/null)"

# 셸 둘(t-box 첫 화면 · 커뮤니티 홈) × 계정 둘
check_shell() {   # $1 잼 · $2 경로 · $3 목록 기대(있음=1 / 없음=0) · $4 설명
  local f="$WORK/$1-$(printf '%s' "$2" | tr -c 'a-z0-9' '_').html" c full
  c=$(page "$1" "$2" "$f")
  expect "$4 GET $2 200" "200" "$c"
  expect "$4 $2 — 변경 이력 창 1개 · 버전 v$VER · 제목 · 등급 표기" "1 1 1 1" \
    "$(cnt '<dialog id="changelog-dialog"' "$f") $(cntF ">v$VER</span>" "$f" | awk '{print ($1>0)?1:0}') $(cntF "$TITLE" "$f" | awk '{print ($1>0)?1:0}') $(cntF ">$LABEL</span>" "$f" | awk '{print ($1>0)?1:0}')"
  expect "$4 $2 — 제목 줄 수 = 항목 수" "$NENT" "$(cnt 'class="changelog__title"' "$f")"
  if [ "$3" = "1" ]; then
    expect "$4 $2 — changelog__list 있음(항목 수만큼) · 첫 변경 문장 있음" "$NENT 1" \
      "$(cnt 'class="changelog__list"' "$f") $(cntF "$CHANGE" "$f" | awk '{print ($1>0)?1:0}')"
  else
    expect "$4 $2 — changelog__list 0 · 첫 변경 문장 0" "0 0" "$(cnt 'changelog__list' "$f") $(cntF "$CHANGE" "$f")"
  fi
}

hr "(c) 관리자 — 목록 있음"
check_shell "$ADMIN" /tbox/ 1 "관리자"
check_shell "$ADMIN" /community/ 1 "관리자"

hr "(d) 일반(Employee) — 제목만"
check_shell "$EMP" /tbox/ 0 "일반"
check_shell "$EMP" /community/ 0 "일반"

# ════════════════════════════════════════════════════════════════
hr "(e) 보는 중 — 관리자가 Employee 로 보면 목록이 숨는다"
# 「보기」는 role:Admin 정책이라 재인증 관문이 붙는다 — 비밀번호 재확인(기존 창구 그대로 · 비밀은 표준입력으로만) 뒤에 연다
reauth_as() {   # $1 계정 — 출력은 HTTP 상태
  local pw body csrf hf c
  pw=$(review_password "$1"); [ -n "$pw" ] || { printf '비밀번호없음'; return 0; }
  body=$(py_secret pw -- python3 -c 'import json,sys; print(json.dumps({"password":sys.stdin.readline().rstrip("\n")}))'); pw=''
  csrf="X-CSRF-Token: $(csrf_of "$1")"; hf=$(secret_file csrf "$WORK") || { printf '머리파일없음'; return 0; }
  c=$(curl_json_secret body -s -o "$WORK/reauth.json" -w '%{http_code}' -X POST -b "$JAR_DIR/$1.jar" -A "$UA" -H @"$hf" -H 'Content-Type: application/json' "$SITE/tbox/api/me/reauth")
  rm -f "$hf"; body=''; csrf=''
  printf '%s' "$c"
}
expect "$ADMIN POST /api/me/reauth(비밀번호) → 200" "200" "$(reauth_as "$ADMIN")"
CSRF=$(csrf_of "$ADMIN")
C=$(printf 'X-CSRF-Token: %s\n' "$CSRF" | curl -s -o "$WORK/act.json" -w '%{http_code}' -H @- -b "$JAR_DIR/$ADMIN.jar" -A "$UA" \
      -X POST -H 'Content-Type: application/json' --data-binary '{"role":"Employee"}' "$SITE/tbox/api/me/act")
CSRF=''
# 응답 본문에는 csrf_token 이 들어 있다 — 원문을 찍지 않고 역할·acting 두 칸만 (R-62)
note "POST /api/me/act {role:Employee} → $C role=$(python3 -c 'import json,sys; d=json.load(open(sys.argv[1])); print(d.get("role",""), "acting=%s" % d.get("acting",""), d.get("error",""))' "$WORK/act.json" 2>/dev/null)"
: > "$WORK/act.json"
if [ "$C" = "200" ] || [ "$C" = "201" ]; then
  check_shell "$ADMIN" /tbox/ 0 "보는 중"
  CSRF=$(csrf_of "$ADMIN")
  C=$(printf 'X-CSRF-Token: %s\n' "$CSRF" | curl -s -o "$WORK/unact.json" -w '%{http_code}' -H @- -b "$JAR_DIR/$ADMIN.jar" -A "$UA" \
        -X DELETE "$SITE/tbox/api/me/act")
  CSRF=''
  expect "DELETE /api/me/act (되돌리기)" "200" "$C"
  check_shell "$ADMIN" /tbox/ 1 "되돌린 뒤"
else
  UNVERIFIED=$((UNVERIFIED+1))
  note "미검증 — 보기를 열지 못했다(상태 $C). 실패로 세지 않는다"
fi

# ════════════════════════════════════════════════════════════════
hr "서버 로그"
expect "임시 인스턴스 로그에 PHP 오류(Fatal·Warning·Parse·Deprecated) 0" "0" \
  "$(grep -cE 'PHP (Fatal|Warning|Parse|Deprecated)|Uncaught' "$INST/server.log")"

[ "$FAIL" = "0" ] || { note "실패가 있어 임시 인스턴스 로그 끝 20줄을 붙인다(요청 주소 줄 제외)"; grep -v ' \[[0-9]\{3\}\]: ' "$INST/server.log" | tail -20 | sed 's/^/    /'; }

hr "결과"
printf '  통과 %d · 실패 %d · 미검증 %d\n' "$PASS" "$FAIL" "$UNVERIFIED"
[ "$FAIL" = "0" ]
