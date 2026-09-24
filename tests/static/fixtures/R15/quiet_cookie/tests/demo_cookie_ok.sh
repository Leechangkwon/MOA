#!/bin/bash
# 가짜 견본(묶음 X): 쿠키를 파일·표준입력으로 넘기는 모양, 세션처럼 보이지만 값이 아닌 것들
BASE="https://demo.example.invalid"
JAR=$(mktemp); COOKIE_JAR=$(mktemp); JAR_DIR=$(mktemp -d); WORK=$(mktemp -d)
py_secret() {
  local names=() v
  while [ "$#" -gt 0 ] && [ "$1" != "--" ]; do names+=("$1"); shift; done
  shift
  for v in "${names[@]}"; do printf '%s\n' "${!v}"; done | "$@"
}
curl -s -c "$JAR" -b "$JAR" "$BASE/api/login"
curl -s -c "$COOKIE_JAR" -b "$COOKIE_JAR" "$BASE/api/me"
curl -s -b "$JAR_DIR/demo_user.jar" "$BASE/api/me"
SESSION=$(demo-login --session)
( umask 077; printf 'Cookie: demo_session=%s\n' "$SESSION" > "$WORK/cookie.hdr" )
curl -s -H @"$WORK/cookie.hdr" "$BASE/api/me"
COOKIES=$(awk '!/^#/ && NF>=7 {printf "%s=%s;", $6, $7}' "$JAR")
py_secret COOKIES -- python3 tools/demo_shot.py "$BASE"
HAVE_SESSION=0
[ -n "$SESSION" ] && HAVE_SESSION=1
demo-tool --have "$HAVE_SESSION"
SESSION_TTL=$(demo-config get ttl)
demo-tool --ttl "$SESSION_TTL"
R=$(curl -s -b "$JAR" "$BASE/api/shares")
SID=$(printf '%s' "$R" | python3 -c 'import json,sys; print(json.load(sys.stdin).get("id",""))')
IDS=''
IDS="$IDS $SID"
curl -s -b "$JAR" -X DELETE "$BASE/api/shares/$SID"
for sid in $IDS; do curl -s -b "$JAR" -X DELETE "$BASE/api/shares/$sid"; done
CSRF=$(curl -s -b "$JAR" "$BASE/api/me" | sed -n 's/.*"csrf_token":"\([0-9a-f]*\)".*/\1/p')
curl -s -b "$JAR" -H "X-CSRF-Token: $CSRF" -X POST "$BASE/api/ping"
curl -s -b "lang=ko" "$BASE/"
N=$(grep -c demo_session "$JAR")
demo-tool --count "$N"
NAME=demo_session
grep -i "Set-Cookie: $NAME=" "$WORK/headers.txt"
