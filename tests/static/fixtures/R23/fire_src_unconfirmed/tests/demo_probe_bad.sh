#!/usr/bin/env bash
# 견본(대조 — v4 출처 확인 값): 출처 확인으로 치지 않는 모양은 여전히 울림(4)
#   ① 출처 R2 를 부정 단언(기대 「0」)으로만 봄 — 뽑은 C2(24행)와, 도우미에 그대로 넘긴 R2 자신(23행 · v3 부터)
#   ② 확인된 R0 을 넘기지만 ID 는 **만들기**(curl POST 도우미) 결과 — 뽑기가 아님(30행)
#   ③ 두 단계 흐름(R3 → J3 → C3) — 한 단계까지만 본다(34행)
SITE=https://demo.example.invalid
DBF="$(mktemp)"
trap 'rm -f "$DBF"' EXIT
PASS=0; FAIL=0
expect() {
  if [ "$2" = "$3" ]; then printf '  ok %s\n' "$1"; PASS=$((PASS+1))
  else printf '  FAIL %s (기대=%s 실제=%s)\n' "$1" "$2" "$3"; FAIL=$((FAIL+1)); fi
}
jf() { python3 -c 'import json,sys
try:
    d = json.loads(sys.argv[1]); print("|".join(str(d.get(k)) for k in sys.argv[2:]))
except Exception:
    print("bad-json")' "$@"; }
gone() { sqlite3 "$DBF" "SELECT count(*) FROM conns WHERE id = '$1'"; }
new_item() { curl -s -X POST -d "from=$1" "$SITE/items" | sed -n 's/.*"id":"\([^"]*\)".*/\1/p'; }
in_list() { grep -q "\"id\":\"$1\"" "$DBF.list" && echo yes || echo no; }

R2=$(curl -s "$SITE/probe"); C2=$(jf "$R2" conn)
expect "탐침 오류 0" "0" "$(jf "$R2" errors)"
expect "그 연결($C2)은 닫혔다" "0" "$(gone "$C2")"

R0=$(curl -s "$SITE/probe")
expect "탐침 방식" "on" "$(jf "$R0" mode)"
ID=$(new_item "$R0")
curl -s "$SITE/popular" > "$DBF.list"
expect "새 항목은 인기 목록에 없음" "no" "$(in_list "$ID")"

R3=$(curl -s "$SITE/probe"); J3=$(jf "$R3" inner); C3=$(jf "$J3" conn)
expect "탐침 상태" "ok" "$(jf "$R3" status)"
expect "안쪽 연결($C3)은 닫혔다" "0" "$(gone "$C3")"
