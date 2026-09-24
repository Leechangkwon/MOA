#!/usr/bin/env bash
# 견본: 준비(만들기) 성공을 확인하지 않는다(가짜 창구)
CBASE=http://127.0.0.1:18113/demo
PASS=0; FAIL=0
expect() {
  if [ "$2" = "$3" ]; then printf '  ok %s\n' "$1"; PASS=$((PASS+1))
  else printf '  FAIL %s (기대=%s 실제=%s)\n' "$1" "$2" "$3"; FAIL=$((FAIL+1)); fi
}

make_page() { curl -s -X POST -d "title=$1" "$CBASE/api/pages" | sed -n 's/.*"id":"\([^"]*\)".*/\1/p'; }
in_list() { grep -q "\"id\":\"$1\"" "$WORK/list.json" && echo yes || echo no; }
TEAM=$(curl -s "$CBASE/api/spaces?kind=team" | sed -n 's/.*"id":\([0-9]*\).*/\1/p')
P_PUB=$(make_page 'demo-공개')
P_PRIV=$(make_page 'demo-비공개')
[ -n "$TEAM" ] && P_TEAM=$(make_page 'demo-팀')
curl -s "$CBASE/api/popular" > "$WORK/list.json"
expect "공개 페이지 있음" "yes" "$(in_list "$P_PUB")"
expect "비공개 페이지 없음" "no" "$(in_list "$P_PRIV")"
[ -n "${P_TEAM:-}" ] && { expect "팀 페이지 없음" "no" "$(in_list "$P_TEAM")"; }
