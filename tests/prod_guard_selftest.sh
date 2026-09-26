#!/usr/bin/env bash
# tests/prod_guard_selftest.sh — 운영 파일 쓰기 가드 단위 확인 (디버깅 묶음 T 9번 · 2026-09-26 사고)
#
# 무엇을 보나 — **가짜 경로만** 쓴다. 운영 /etc/tbox-v2 · /var/lib/tbox-v2 · /var/log/tbox-v2 에는 읽기도 쓰기도 하지 않는다.
#   G1 tests/lib/prod_guard.sh — pg_target_kind(prod · temp · unknown) · pg_is_prod_path(링크를 푼 자리 포함) · prod_write_guard
#      (운영 자리 × 대상 · 운영 앱 .env 의 DB_NAME 대조). 운영 자리 무늬(PG_PROD_PATH_RE)는 가짜 뿌리 아래로 바꿔 돈다.
#   G2 tests/f51.sh · tests/f52.sh — 비밀 파일 자리 결정 절(PROD_TOTP_FILE … esac)과 save_secret · qa_secret 을 **떼어**(sed)
#      운영 경로 글자를 가짜 뿌리로 바꾼 뒤 돌린다. sudo 는 셸 함수로 바꿔 끼운다(-o root 를 떼고 가짜 파일에만 install · 부른 기록).
#      임시 대상 → 메모리(파일 쓰기 0) · 임시 대상 + 운영 자리를 준 QA_TOTP_FILE → 가드가 멈춤(rc 3 · 쓰기 0) · 임시 대상 + 임시 파일 →
#      그 파일만 · 운영 대상 → 가짜 운영 파일에 한 계정 줄만 바뀜 · 운영 대상 + .env DB_NAME 어긋남 → 멈춤 · 섞인 대상 → 시작 거부
#   G3 tests/stage26.sh — 저장소 자리 결정 절(case pg_target_kind … esac)을 떼어: 운영 → 운영 저장소 · 임시 → 비움(지우지 않음) · 섞임 → 거부
#   (독립 검토 반영 — review-fix2) 운영 대상 + QA_TOTP_FILE·S26_STORAGE_DIR → 거부 · 운영 대상이면 **운영 상태를 바꾸기 전**(결정 절)에
#      가드가 먼저 멈춘다(떼어 낸 조각 뒤의 「상태 바꿈」 표식이 0) · 운영 트리가 아닌 곳에서 부르면 거부 · BASE 별칭 정규화 · listen … default_server
#   G4 떼어 낸 코드에 운영 경로 글자가 남지 않았다(자기 확인 — 이 시험이 운영 파일을 건드릴 길이 없다)
# 두 시험(f51·f52·stage26) 자체는 돌리지 않는다 — 운영 계정 2단계를 바꾸는 시험이다.
# 나가는 값: 0 통과 · 1 실패 · 끝 줄 RESULT pass= fail= skip=
set -u
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PASS=0; FAIL=0
expect() {
  if [ "$2" = "$3" ]; then printf '  ✔ %s  (기대=%s 실제=%s)\n' "$1" "$2" "$3"; PASS=$((PASS+1))
  else printf '  ✖ %s  (기대=%s 실제=%s)\n' "$1" "$2" "$3"; FAIL=$((FAIL+1)); fi
}
F="$(mktemp -d)"; chmod 700 "$F"
trap 'rm -rf "$F"' EXIT
mkdir -p "$F/etc/tbox-v2" "$F/var/lib/tbox-v2/storage" "$F/app" "$F/tmp"
FAKE_PORT=18082
printf 'server {\n    listen 127.0.0.1:%s;\n}\n' "$FAKE_PORT" > "$F/local.conf"
printf 'DB_HOST=127.0.0.1\nDB_NAME=tbox_re\n' > "$F/app/.env"
# 가짜 비밀 파일(모양만 — base32 무작위 · 운영 값 아님)
fake_secret() { python3 -c 'import base64,os; print(base64.b32encode(os.urandom(20)).decode().rstrip("="))'; }
S_E1=$(fake_secret); S_A1=$(fake_secret); S_M1=$(fake_secret)
printf 'claude_admin01 %s\nclaude_manager01 %s\nclaude_employee01 %s\n' "$S_A1" "$S_M1" "$S_E1" > "$F/etc/tbox-v2/qa-totp.txt"
chmod 600 "$F/etc/tbox-v2/qa-totp.txt"
FAKE_TOTP="$F/etc/tbox-v2/qa-totp.txt"
sha() { sha256sum "$1" | cut -c1-16; }
H0=$(sha "$FAKE_TOTP")
PROD_BASE="http://127.0.0.1:$FAKE_PORT/tbox"
TEMP_BASE="http://127.0.0.1:8231/tbox"
TEMP_DB="tbox_d1t_guard_test"

# 가드 라이브러리 — 가짜 설정·가짜 운영 자리로
export PG_PROD_APP_ENV="$F/app/.env" PG_PROD_LOCAL_CONF="$F/local.conf"
export PG_GUARD_SELFTEST=1    # 가드가 PG_PROD_TREE 덮어쓰기를 받는 것은 이 표식이 있을 때만(재확인 낮음)
export PG_PROD_TREE="$ROOT"   # 가짜 운영 트리 = 이 저장소(ROOT 가 운영 트리인가 확인 — 독립 검토 L3)
STATE="$F/state.txt"          # 떼어 낸 결정 절을 지나 「운영 상태를 바꾸는」 자리에 닿으면 한 글자씩(중간 1 — 먼저 멈추면 0)
. "$ROOT/tests/lib/prod_guard.sh" || { echo "prod_guard.sh 를 읽지 못했다"; exit 2; }
. "$ROOT/tests/lib/secrets.sh" || { echo "secrets.sh 를 읽지 못했다"; exit 2; }
REAL_PATH_RE="$PG_PROD_PATH_RE"
PG_PROD_PATH_RE="^${F}/(etc|var/lib|var/log)/tbox-v2(/|\$)"
PG_PROD_STORAGE="$F/var/lib/tbox-v2/storage"

# sudo 바꿔 끼우기 — 부른 기록만 남기고 옵션을 떼어 이 사용자로 돈다(install 의 -o/-g root 도 뗌). 가짜 파일에만 닿는다.
CALLS="$F/calls.txt"; : > "$CALLS"
sudo() {
  while [ "$#" -gt 0 ]; do case "$1" in -n) shift ;; -u) shift 2 ;; *) break ;; esac; done
  if [ "${1:-}" = install ]; then
    shift; local a=()
    while [ "$#" -gt 0 ]; do case "$1" in -o|-g) shift 2 ;; *) a+=("$1"); shift ;; esac; done
    printf 'sudo-install %s\n' "${a[-1]}" >> "$CALLS"
    command install "${a[@]}"
  else
    "$@"
  fi
}
install() { printf 'install %s\n' "${*: -1}" >> "$CALLS"; command install "$@"; }

printf '\n── G1 tests/lib/prod_guard.sh\n'
kind() { BASE=$1 DB=$2 pg_target_kind; }
expect "pg_target_kind — 운영(BASE·DB 둘 다)" "prod" "$(kind "$PROD_BASE" tbox_re)"
expect "pg_target_kind — 임시(둘 다 아님)" "temp" "$(kind "$TEMP_BASE" "$TEMP_DB")"
expect "pg_target_kind — BASE 만 운영(회귀 틀이 DB 만 바꿈) → unknown" "unknown" "$(kind "$PROD_BASE" "$TEMP_DB")"
expect "pg_target_kind — DB 만 운영(BASE 만 바꿈) → unknown" "unknown" "$(kind "$TEMP_BASE" tbox_re)"
expect "pg_target_kind — 운영 검토 포트를 못 읽음 → unknown" "unknown" "$(PG_PROD_LOCAL_CONF="$F/nope.conf" BASE="$PROD_BASE" DB=tbox_re pg_target_kind)"
expect "pg_norm_base — localhost · 끝 / · 대문자 · [::1] 별칭이 운영 주소 하나로" "yes|yes|yes|yes" \
  "$(for b in "http://localhost:$FAKE_PORT/tbox" "http://127.0.0.1:$FAKE_PORT/tbox/" "HTTP://LocalHost:$FAKE_PORT/tbox//" "http://[::1]:$FAKE_PORT/tbox"; do [ "$(pg_norm_base "$b")" = "$PROD_BASE" ] && printf yes || printf no; printf '|'; done | sed 's/|$//')"
expect "pg_target_kind — 운영 별칭(localhost:포트/) + tbox_re → prod · 운영 별칭 + 임시 DB → unknown(옛 판은 temp)" "prod|unknown" \
  "$(kind "http://localhost:$FAKE_PORT/tbox/" tbox_re)|$(kind "http://localhost:$FAKE_PORT/tbox/" "$TEMP_DB")"
printf 'server {\n    listen 127.0.0.1:%s default_server;\n}\n' "$FAKE_PORT" > "$F/local-ds.conf"
expect "pg_prod_port — listen 127.0.0.1:<포트> default_server; 도 읽는다" "$FAKE_PORT" "$(PG_PROD_LOCAL_CONF="$F/local-ds.conf" pg_prod_port)"
ln -s "$F/etc/tbox-v2" "$F/tmp/link"
expect "pg_is_prod_path — 운영 자리 · 링크로 가리킨 운영 자리 · 임시 자리" "yes|yes|no" \
  "$(pg_is_prod_path "$FAKE_TOTP" && echo yes || echo no)|$(pg_is_prod_path "$F/tmp/link/qa-totp.txt" && echo yes || echo no)|$(pg_is_prod_path "$F/tmp/x.txt" && echo yes || echo no)"
guard() { BASE=$1 DB=$2 prod_write_guard "$3" 2>/dev/null && echo pass || echo stop; }
expect "prod_write_guard — 임시 자리는 대상과 상관없이 통과" "pass" "$(guard "$TEMP_BASE" "$TEMP_DB" "$F/tmp/x.txt")"
expect "prod_write_guard — 운영 자리 + 임시 대상 → 멈춤" "stop" "$(guard "$TEMP_BASE" "$TEMP_DB" "$FAKE_TOTP")"
expect "prod_write_guard — 운영 자리(링크) + 임시 대상 → 멈춤" "stop" "$(guard "$TEMP_BASE" "$TEMP_DB" "$F/tmp/link/qa-totp.txt")"
expect "prod_write_guard — 운영 자리 + 섞인 대상 → 멈춤" "stop" "$(guard "$PROD_BASE" "$TEMP_DB" "$FAKE_TOTP")"
expect "prod_write_guard — 운영 자리 + 운영 대상 + .env DB_NAME=tbox_re → 통과" "pass" "$(guard "$PROD_BASE" tbox_re "$FAKE_TOTP")"
expect "prod_write_guard — 운영 대상이어도 .env DB_NAME 이 다르면 멈춤" "stop" \
  "$(printf 'DB_NAME=tbox_other\n' > "$F/app/.env2"; PG_PROD_APP_ENV="$F/app/.env2" BASE="$PROD_BASE" DB=tbox_re prod_write_guard "$FAKE_TOTP" 2>/dev/null && echo pass || echo stop)"
expect "prod_write_guard — 운영 대상이어도 시험을 부른 트리가 운영 트리가 아니면 멈춤" "stop" \
  "$(PG_PROD_TREE="$F/not-prod-tree" BASE="$PROD_BASE" DB=tbox_re prod_write_guard "$FAKE_TOTP" 2>/dev/null && echo pass || echo stop)"
expect "PG_PROD_TREE 덮어쓰기 — 표식(PG_GUARD_SELFTEST=1) 없으면 무시(운영 트리 고정) · 있으면 받음" "/var/www/qaredi/tbox-v2|$F/x" \
  "$(env -u PG_GUARD_SELFTEST PG_PROD_TREE="$F/x" ROOT="$ROOT" bash -c '. "$ROOT/tests/lib/prod_guard.sh"; printf %s "$PG_PROD_TREE"')|$(PG_GUARD_SELFTEST=1 PG_PROD_TREE="$F/x" ROOT="$ROOT" bash -c '. "$ROOT/tests/lib/prod_guard.sh"; printf %s "$PG_PROD_TREE"')"
expect "prod_write_guard — .env 를 못 읽으면 멈춤" "stop" \
  "$(PG_PROD_APP_ENV="$F/app/none" BASE="$PROD_BASE" DB=tbox_re prod_write_guard "$FAKE_TOTP" 2>/dev/null && echo pass || echo stop)"
expect "진짜 운영 자리 무늬(바꾸기 전)는 /etc/tbox-v2 · /var/lib/tbox-v2 · /var/log/tbox-v2 셋" "yes|yes|yes|no" \
  "$([[ /etc/tbox-v2/qa-totp.txt =~ $REAL_PATH_RE ]] && echo yes || echo no)|$([[ /var/lib/tbox-v2/storage/ab =~ $REAL_PATH_RE ]] && echo yes || echo no)|$([[ /var/log/tbox-v2/alerts.log =~ $REAL_PATH_RE ]] && echo yes || echo no)|$([[ /etc/tbox-v2x/a =~ $REAL_PATH_RE ]] && echo yes || echo no)"

# f51·f52 에서 결정 절과 save_secret·qa_secret 을 떼어 운영 경로 글자를 가짜 뿌리로
extract() {  # $1 시험 파일 → 떼어 낸 조각(가짜 뿌리로 바꿈)
  { sed -n -E '/^PROD_TOTP_FILE=/,/^(MEM_SECRET=|declare -A MEM_SECRET=)/p' "$1"; sed -n '/^qa_secret() /p' "$1"; sed -n '/^save_secret() {/,/^}$/p' "$1"; } \
    | sed -e "s#/etc/tbox-v2/#$F/etc/tbox-v2/#g"
}
for t in f51 f52; do
  extract "$ROOT/tests/$t.sh" > "$F/$t.part.sh"
  expect "G4 $t 조각 — 결정 절(+ MEM_SECRET)·qa_secret·save_secret 을 떼었다 · 결정 절의 가드 1 · 쓰기 직전 가드 1" "yes|1|1" \
    "$(grep -q '^TARGET_KIND=' "$F/$t.part.sh" && grep -qE '^(MEM_SECRET=|declare -A MEM_SECRET=)' "$F/$t.part.sh" && grep -q '^qa_secret()' "$F/$t.part.sh" && grep -q '^save_secret() {' "$F/$t.part.sh" && echo yes || echo no)|$(grep -c 'prod_write_guard "\$PROD_TOTP_FILE"' "$F/$t.part.sh")|$(grep -c 'prod_write_guard "\$TOTP_FILE"' "$F/$t.part.sh")"
done
for t in f51 f52; do
  expect "G4 $t 조각 — 가짜 뿌리 밖 운영 경로 글자 0" "0" "$(sed -E "s#$F/(etc|var/lib|var/log)/tbox-v2#FAKE#g" "$F/$t.part.sh" | grep -c '/etc/tbox-v2\|/var/lib/tbox-v2\|/var/log/tbox-v2')"
done

# 조각 하나를 새 셸에서 — $1 시험 · $2 BASE · $3 DB · $4 QA_TOTP_FILE(빈 값이면 안 줌) · $5 계정 · 결과 "rc|저장 방식|qa_secret 이 새 값인가"
run_part() {
  local t=$1 base=$2 db=$3 qf=$4 who=$5
  NEWV=$(fake_secret) BASE="$base" DB="$db" QA_TOTP_FILE="$qf" WHO="$who" T="$t" F="$F" CALLS="$CALLS" \
    PG_PROD_APP_ENV="$PG_PROD_APP_ENV" PG_PROD_LOCAL_CONF="$PG_PROD_LOCAL_CONF" ROOT="$ROOT" bash -c '
    set -u
    . "$ROOT/tests/lib/prod_guard.sh"; . "$ROOT/tests/lib/secrets.sh"
    PG_PROD_PATH_RE="^${F}/(etc|var/lib|var/log)/tbox-v2(/|\$)"
    sudo() {
      while [ "$#" -gt 0 ]; do case "$1" in -n) shift ;; -u) shift 2 ;; *) break ;; esac; done
      if [ "${1:-}" = install ]; then shift; local a=(); while [ "$#" -gt 0 ]; do case "$1" in -o|-g) shift 2 ;; *) a+=("$1"); shift ;; esac; done
        printf "sudo-install %s\n" "${a[-1]}" >> "$CALLS"; command install "${a[@]}"; else "$@"; fi; }
    install() { printf "install %s\n" "${*: -1}" >> "$CALLS"; command install "$@"; }
    [ -n "$QA_TOTP_FILE" ] || unset QA_TOTP_FILE
    . "$F/$T.part.sh" || exit $?
    printf x >> "$F/state.txt"   # 여기부터가 시험의 「운영 상태를 바꾸는」 자리(2단계 끄기·다시 켜기)
    new="$NEWV"
    if [ "$T" = f51 ]; then save_secret new >/dev/null 2>&1; rc=$?; got=$(qa_secret)
    else save_secret "$WHO" new >/dev/null 2>&1; rc=$?; got=$(qa_secret "$WHO"); fi
    printf "%s|%s|%s\n" "$rc" "$TOTP_SAVE" "$([ "$got" = "$NEWV" ] && echo new || echo old)"
  ' 2>/dev/null
  echo "rc=$?"
}
line_of() { awk -v u="$2" '$1==u{print $2}' "$1" | sha256sum | cut -c1-12; }

printf '\n── G2 f51·f52 — 비밀 파일 자리 결정 · save_secret (가짜 경로 · sudo 바꿔 끼움)\n'
for t in f51 f52; do
  who=claude_employee01; [ "$t" = f52 ] && who=claude_admin01
  : > "$CALLS"; H=$(sha "$FAKE_TOTP")
  R=$(run_part "$t" "$TEMP_BASE" "$TEMP_DB" "" "$who")
  expect "$t 임시 대상(QA_TOTP_FILE 없음) → 메모리에 두고 qa_secret 은 새 값 · 운영(가짜) 파일 그대로 · install 0" "0|memory|new|rc=0|$H|0" \
    "$(printf '%s\n' "$R" | tr '\n' '|')$(sha "$FAKE_TOTP")|$(wc -l < "$CALLS" | tr -d ' ')"
  : > "$CALLS"
  R=$(run_part "$t" "$TEMP_BASE" "$TEMP_DB" "$FAKE_TOTP" "$who")
  expect "$t 임시 대상 + QA_TOTP_FILE=운영 자리 → 쓰기 직전 가드가 멈춤(rc 3) · 파일 그대로 · install 0" "rc=3|$H|0" \
    "$(printf '%s' "$R" | tail -1)|$(sha "$FAKE_TOTP")|$(wc -l < "$CALLS" | tr -d ' ')"
  : > "$CALLS"; cp "$FAKE_TOTP" "$F/tmp/copy-$t.txt"
  R=$(run_part "$t" "$TEMP_BASE" "$TEMP_DB" "$F/tmp/copy-$t.txt" "$who")
  expect "$t 임시 대상 + QA_TOTP_FILE=임시 파일 → 그 파일에만(sudo 없는 install 1) · 운영(가짜) 파일 그대로" "0|file|new|rc=0|install $F/tmp/copy-$t.txt|$H" \
    "$(printf '%s\n' "$R" | tr '\n' '|')$(cat "$CALLS")|$(sha "$FAKE_TOTP")"
  : > "$CALLS"; BEFORE_OTHER=$(line_of "$FAKE_TOTP" claude_manager01); BEFORE_WHO=$(line_of "$FAKE_TOTP" "$who")
  R=$(run_part "$t" "$PROD_BASE" tbox_re "" "$who")
  expect "$t 운영 대상(가짜 운영 · .env DB_NAME=tbox_re) → 운영(가짜) 파일에 씀 · sudo install 1 · 그 계정 줄만 바뀜" "0|file|new|rc=0|sudo-install $FAKE_TOTP|changed|same" \
    "$(printf '%s\n' "$R" | tr '\n' '|')$(cat "$CALLS")|$([ "$(line_of "$FAKE_TOTP" "$who")" != "$BEFORE_WHO" ] && echo changed || echo same)|$([ "$(line_of "$FAKE_TOTP" claude_manager01)" = "$BEFORE_OTHER" ] && echo same || echo changed)"
  : > "$CALLS"; : > "$STATE"; H=$(sha "$FAKE_TOTP"); cp "$F/app/.env" "$F/app/.env.keep"; printf 'DB_NAME=tbox_other\n' > "$F/app/.env"
  R=$(run_part "$t" "$PROD_BASE" tbox_re "" "$who")
  expect "$t 운영 대상인데 운영 .env 의 DB_NAME 이 다름 → 운영 상태를 바꾸기 **전에** 멈춤(rc 3 · 상태 바꿈 0) · 파일 그대로 · install 0" "rc=3|0|$H|0" \
    "$(printf '%s' "$R" | tail -1)|$(wc -c < "$STATE" | tr -d ' ')|$(sha "$FAKE_TOTP")|$(wc -l < "$CALLS" | tr -d ' ')"
  mv "$F/app/.env.keep" "$F/app/.env"
  : > "$CALLS"; : > "$STATE"; cp "$FAKE_TOTP" "$F/tmp/copy2-$t.txt"
  R=$(run_part "$t" "$PROD_BASE" tbox_re "$F/tmp/copy2-$t.txt" "$who")
  expect "$t 운영 대상 + QA_TOTP_FILE(임시 파일) → 거부(rc 3 · 상태 바꿈 0) · 운영(가짜)·임시 파일 그대로 · install 0" "rc=3|0|$H|$H|0" \
    "$(printf '%s' "$R" | tail -1)|$(wc -c < "$STATE" | tr -d ' ')|$(sha "$FAKE_TOTP")|$(sha "$F/tmp/copy2-$t.txt")|$(wc -l < "$CALLS" | tr -d ' ')"
  : > "$CALLS"; : > "$STATE"
  R=$(PG_PROD_TREE="$F/not-prod-tree" run_part "$t" "$PROD_BASE" tbox_re "" "$who")
  expect "$t 운영 대상인데 운영 트리가 아닌 곳에서 부름 → 거부(rc 3 · 상태 바꿈 0) · install 0" "rc=3|0|$H|0" \
    "$(printf '%s' "$R" | tail -1)|$(wc -c < "$STATE" | tr -d ' ')|$(sha "$FAKE_TOTP")|$(wc -l < "$CALLS" | tr -d ' ')"
  : > "$CALLS"; : > "$STATE"
  R=$(run_part "$t" "http://localhost:$FAKE_PORT/tbox/" "$TEMP_DB" "" "$who")
  expect "$t 운영 별칭(localhost:포트/) + 임시 DB → 결정 절에서 거부(rc 3 · 상태 바꿈 0 — 옛 판은 temp 로 메모리 저장)" "rc=3|0|$H|0" \
    "$(printf '%s' "$R" | tail -1)|$(wc -c < "$STATE" | tr -d ' ')|$(sha "$FAKE_TOTP")|$(wc -l < "$CALLS" | tr -d ' ')"
  : > "$CALLS"
  R=$(run_part "$t" "$PROD_BASE" "$TEMP_DB" "" "$who")
  expect "$t 섞인 대상(BASE 운영 · DB 임시) → 결정 절에서 시작 거부(rc 3) · install 0" "rc=3|$H|0" \
    "$(printf '%s' "$R" | tail -1)|$(sha "$FAKE_TOTP")|$(wc -l < "$CALLS" | tr -d ' ')"
done

printf '\n── G3 stage26 — 정리 때 지울 저장소 자리\n'
sed -n '/^case "\$(pg_target_kind)" in$/,/^esac$/p' "$ROOT/tests/stage26.sh" > "$F/s26.part.sh"
expect "G4 stage26 조각 — 결정 절을 떼었다 · 운영 저장 경로 글자 0(라이브러리 변수만)" "yes|0" \
  "$(grep -q 'S26_STORAGE=' "$F/s26.part.sh" && echo yes || echo no)|$(grep -c '/var/lib/tbox-v2' "$F/s26.part.sh")"
s26() { BASE=$1 DB=$2 S26_STORAGE_DIR=$3 PG_PROD_STORAGE="$PG_PROD_STORAGE" F="$F" ROOT="$ROOT" PG_PROD_APP_ENV="$PG_PROD_APP_ENV" PG_PROD_LOCAL_CONF="$PG_PROD_LOCAL_CONF" bash -c '
  . "$ROOT/tests/lib/prod_guard.sh"; PG_PROD_STORAGE="'"$PG_PROD_STORAGE"'"
  PG_PROD_PATH_RE="^${F}/(etc|var/lib|var/log)/tbox-v2(/|\$)"
  [ -n "$S26_STORAGE_DIR" ] || unset S26_STORAGE_DIR
  . "$F/s26.part.sh"; printf "%s\n" "${S26_STORAGE:-(비움)}"' 2>/dev/null; echo "rc=$?"; }
expect "stage26 운영 대상 → 운영 저장소(가짜)" "$PG_PROD_STORAGE|rc=0" "$(s26 "$PROD_BASE" tbox_re "" | tr '\n' '|' | sed 's/|$//')"
expect "stage26 임시 대상 → 비움(바이트를 지우지 않는다)" "(비움)|rc=0" "$(s26 "$TEMP_BASE" "$TEMP_DB" "" | tr '\n' '|' | sed 's/|$//')"
expect "stage26 임시 대상 + S26_STORAGE_DIR → 그 자리" "$F/tmp|rc=0" "$(s26 "$TEMP_BASE" "$TEMP_DB" "$F/tmp" | tr '\n' '|' | sed 's/|$//')"
expect "stage26 섞인 대상 → 시작 거부(rc 3)" "rc=3" "$(s26 "$PROD_BASE" "$TEMP_DB" "" | tail -1)"
expect "stage26 운영 대상 + S26_STORAGE_DIR → 거부(rc 3 — 운영 저장소에 S26- 바이트가 고아로 남는 길)" "rc=3" "$(s26 "$PROD_BASE" tbox_re "$F/tmp" | tail -1)"
expect "stage26 운영 대상인데 운영 트리가 아닌 곳에서 부름 → 결정 절에서 거부(rc 3)" "rc=3" "$(PG_PROD_TREE="$F/not-prod-tree" s26 "$PROD_BASE" tbox_re "" | tail -1)"
expect "stage26 운영 대상인데 운영 .env DB_NAME 다름 → 결정 절에서 거부(rc 3)" "rc=3" \
  "$(cp "$F/app/.env" "$F/app/.env.keep"; printf 'DB_NAME=tbox_other\n' > "$F/app/.env"; s26 "$PROD_BASE" tbox_re "" | tail -1; mv "$F/app/.env.keep" "$F/app/.env")"
expect "stage26 정리 — 임시 저장소 자리는 가드 통과 · 운영(가짜) 저장소 + 임시 대상은 멈춤" "pass|stop" \
  "$(guard "$TEMP_BASE" "$TEMP_DB" "$F/tmp")|$(guard "$TEMP_BASE" "$TEMP_DB" "$PG_PROD_STORAGE")"

printf '\n── 끝\n'
expect "가짜 운영 파일은 운영 대상 두 번(f51·f52)만 바뀌었다 — 처음과 다름" "changed" "$([ "$(sha "$FAKE_TOTP")" != "$H0" ] && echo changed || echo same)"
printf '통과 %d · 실패 %d\n' "$PASS" "$FAIL"
printf 'RESULT pass=%d fail=%d skip=0\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ] || exit 1
exit 0
