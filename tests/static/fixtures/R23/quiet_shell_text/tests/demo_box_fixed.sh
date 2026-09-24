#!/usr/bin/env bash
# 견본: 그 화면에만 있는 표식 · 없어야 하는 글자 · 창구 JSON — 조용
CBASE=http://127.0.0.1:18116/demo
PASS=0; FAIL=0
expect() {
  if [ "$2" = "$3" ]; then printf '  ok %s\n' "$1"; PASS=$((PASS+1))
  else printf '  FAIL %s (기대=%s 실제=%s)\n' "$1" "$2" "$3"; FAIL=$((FAIL+1)); fi
}

R=$(curl -s "$CBASE/box")
expect "제목" "1" "$(printf '%s' "$R" | grep -c '<h1 class="demo-title">내 보관함</h1>')"
expect "관리 열 없음" "0" "$(printf '%s' "$R" | grep -c '관리')"
J=$(curl -s "$CBASE/api/box")
expect "창구 JSON 에 이름" "1" "$(printf '%s' "$J" | grep -c '내 보관함')"
