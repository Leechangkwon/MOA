#!/usr/bin/env bash
# 견본(대조 — v4 모양 확인): 확인으로 치지 않는 모양은 여전히 울림(3)
#   ① 빈 글자에도 맞는 정규식(^[0-9]*$) ② 걸러도 갈래를 끝내지 않음(&& 뒤 알림만) ③ case 의 빈 값 갈래가 끝내지 않음
SITE=https://demo.example.invalid
PASS=0; FAIL=0
expect() {
  if [ "$2" = "$3" ]; then printf '  ok %s\n' "$1"; PASS=$((PASS+1))
  else printf '  FAIL %s (기대=%s 실제=%s)\n' "$1" "$2" "$3"; FAIL=$((FAIL+1)); fi
}
probe() { curl -s "$SITE/probe" | python3 -c 'import json,sys; print(json.load(sys.stdin)["prepared"])'; }

A=$(probe); B=$(probe)
[[ "$A" =~ ^[0-9]*$ ]] && [[ "$B" =~ ^[0-9]*$ ]] || { echo "숫자가 아니다"; exit 1; }
expect "prepare 수가 늘지 않는다" "$A" "$B"

C=$(probe); D=$(probe)
[[ "$C" =~ ^[0-9]+$ ]] && echo "숫자다"
expect "두 번째도 늘지 않는다" "$C" "$D"

E=$(probe); G=$(probe)
case "$E" in
  ''|*[!0-9]*) echo "이상한 값" ;;
esac
expect "세 번째도 늘지 않는다" "$E" "$G"
