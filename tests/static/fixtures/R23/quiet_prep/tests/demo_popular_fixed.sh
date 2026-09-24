#!/usr/bin/env bash
# 견본: 준비 값을 확인한다 — 조용
CBASE=http://127.0.0.1:18117/demo
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
PASS=0; FAIL=0
expect() {
  if [ "$2" = "$3" ]; then printf '  ok %s\n' "$1"; PASS=$((PASS+1))
  else printf '  FAIL %s (기대=%s 실제=%s)\n' "$1" "$2" "$3"; FAIL=$((FAIL+1)); fi
}

skip() { printf '  skip %s\n' "$1"; }
make_page() { curl -s -X POST -d "title=$1" "$CBASE/api/pages" | sed -n 's/.*"id":"\([^"]*\)".*/\1/p'; }
in_list() { grep -q "\"id\":\"$1\"" "$WORK/list.json" && echo yes || echo no; }
P_A=$(make_page a); P_B=$(make_page b); P_C=$(make_page c)
NAMES="P_A P_B"
n_empty=0; for v in $NAMES; do [ -z "${!v}" ] && n_empty=$((n_empty+1)); done
expect "준비 — 빈 id 0" "0" "$n_empty"
curl -s "$CBASE/api/popular" > "$WORK/list.json"
expect "a 없음" "no" "$(in_list "$P_A")"
expect "b 없음" "no" "$(in_list "$P_B")"
expect "c 있음" "yes" "$(in_list "$P_C")"
expect "c 는 숨김 목록에 없음" "no" "$(in_list "$P_C")"
P_D=$(make_page d)
[ -n "$P_D" ] || { echo "준비 실패"; exit 3; }
expect "d 없음" "no" "$(in_list "$P_D")"
TEAM=$(curl -s "$CBASE/api/spaces")
if [ -n "$TEAM" ]; then
  expect "팀 공간은 인기 목록에 없음" "no" "$(in_list "$TEAM")"
else
  skip "팀 공간 없음"
fi
P_E=$(make_page e)
[ -n "$P_E" ] && expect "e 지우기 200" "200" "$(curl -s -o /dev/null -w '%{http_code}' -X DELETE "$CBASE/api/pages/$P_E")"
