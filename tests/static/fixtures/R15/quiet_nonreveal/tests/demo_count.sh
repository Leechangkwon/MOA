#!/usr/bin/env bash
# 가짜 견본(v3 ④) — 비밀 칸의 **개수**(len(…) · jq length)는 비밀이 아니다. 조용해야 한다.
BASE="https://example.invalid/demo"
LIVE=$(curl -s -b "$JAR_DIR/demo.jar" "$BASE/api/me/tokens" \
       | python3 -c "import json,sys; d=json.load(sys.stdin); print(len([t for t in d.get('tokens', []) if not t.get('revoked')]))")
i=${LIVE:-0}
while [ "$i" -lt 5 ]; do
  i=$((i + 1))
  python3 "$ROOT/tests/demo_issue.py" "demo-$i"
done
N=$(curl -s "$BASE/api/me/tokens" | jq '.tokens | length')
seq 1 "$N"
