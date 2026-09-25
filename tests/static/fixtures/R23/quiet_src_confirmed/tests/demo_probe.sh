#!/usr/bin/env bash
# 견본(v4 출처 확인 값): 준비 값 C 는 탐침 응답 R 에서 **뽑은** 값이고, R(또는 같은 R 에서 뽑은 다른 값)을 긍정 단언으로
# 확인했다 — R 이 비거나 엉뚱하면 그 긍정 단언이 먼저 실패하므로 「없음」 단언이 공짜로 통과하지 않는다 — 조용(0)
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
pick() { printf '%s' "$1" | sed -n "s/.*\"$2\":\"\([^\"]*\)\".*/\1/p"; }
gone() { sqlite3 "$DBF" "SELECT count(*) FROM conns WHERE id = '$1'"; }
known_worker() { grep -qx "$1" "$DBF.workers" && echo yes || echo no; }

# ① 뽑은 출처(R0)를 긍정 단언으로 확인
R0=$(curl -s "$SITE/probe"); C0=$(jf "$R0" conn)
expect "탐침 설정 (방식|제한)" "on|5s" "$(jf "$R0" mode limit)"
curl -s -o /dev/null "$SITE/close"
expect "그 연결($C0)은 닫혔다" "0" "$(gone "$C0")"

# ② 같은 출처(R1)에서 뽑은 다른 값(W1)을 긍정 단언으로 확인 · 뽑기는 파이프(printf | sed)라도 같다
R1=$(curl -s "$SITE/probe"); C1=$(pick "$R1" conn); W1=$(pick "$R1" worker)
expect "알려진 워커" "yes" "$(known_worker "$W1")"
curl -s -o /dev/null "$SITE/close"
expect "두 번째 연결($C1)도 닫혔다" "0" "$(gone "$C1")"
