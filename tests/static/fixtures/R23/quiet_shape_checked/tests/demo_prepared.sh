#!/usr/bin/env bash
# 견본(v4 모양 확인 — FP-0008): 두 명령 치환 값(탐침 응답)을 같음 비교하기 전에 한쪽을 빈 글자에 맞지 않는 정규식·case 로
# 걸러 갈래를 끝낸다(exit · 실패 단언) — 빈 값끼리 같다고 통과하지 않는다 — 조용(0). 비교마다 확인 방식 하나씩.
SITE=https://demo.example.invalid
PASS=0; FAIL=0
expect() {
  if [ "$2" = "$3" ]; then printf '  ok %s\n' "$1"; PASS=$((PASS+1))
  else printf '  FAIL %s (기대=%s 실제=%s)\n' "$1" "$2" "$3"; FAIL=$((FAIL+1)); fi
}
probe() { curl -s "$SITE/probe" | python3 -c 'import json,sys; print(json.load(sys.stdin)["prepared"])'; }

# ① [[ =~ ]] && [[ =~ ]] || 끝냄
A=$(probe); B=$(probe)
[[ "$A" =~ ^[0-9]+$ ]] && [[ "$B" =~ ^[0-9]+$ ]] || { echo "탐침 실패 — 숫자가 아니다"; exit 1; }
expect "prepare 수가 늘지 않는다" "$A" "$B"

# ② 숫자인지를 단언으로 확인(POSIX 문자 부류)
C=$(probe); D=$(probe)
expect "탐침 값이 숫자" "yes" "$([[ "$C" =~ ^[[:digit:]]+$ ]] && echo yes || echo no)"
expect "두 번째도 늘지 않는다" "$C" "$D"

# ③ case 로 거름 — 빈 값이 걸리는 갈래가 끝낸다
E=$(probe); G=$(probe)
case "$E" in
  ''|*[!0-9]*) echo "탐침 실패 — 숫자가 아니다"; exit 1 ;;
  *) : ;;
esac
expect "세 번째도 늘지 않는다" "$E" "$G"

# ④ 정규식을 변수에 담아 뒤집어 씀(if ! [[ … ]]; then exit)
num_re='^[0-9]+$'
H=$(probe); J=$(probe)
if ! [[ $H =~ $num_re ]]; then echo "탐침 실패"; exit 1; fi
expect "네 번째도 늘지 않는다" "$H" "$J"
