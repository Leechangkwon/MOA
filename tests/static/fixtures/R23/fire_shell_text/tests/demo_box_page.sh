#!/usr/bin/env bash
# 견본: 화면 응답에서 제목 글자만 본다
BASE=http://127.0.0.1:18112/demo
PASS=0; FAIL=0
expect() {
  if [ "$2" = "$3" ]; then printf '  ok %s\n' "$1"; PASS=$((PASS+1))
  else printf '  FAIL %s (기대=%s 실제=%s)\n' "$1" "$2" "$3"; FAIL=$((FAIL+1)); fi
}

R=$(curl -s -A demo "$BASE/box")
expect "직원 /box 는 「내 보관함」 화면" "1" "$(printf '%s' "$R" | grep -c '내 보관함')"
ADM=$(curl -s -A demo "$BASE/admin/box")
expect "관리자 /admin/box 는 전체 보관함" "yes" "$(grep -q '전체 보관함' <<< "$ADM" && echo yes || echo no)"
