#!/bin/bash
# 견본: 조용해야 하는 모양들(가짜)
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SITE="http://127.0.0.1:9090"
BASE="$SITE/shop"
curl -s "$SITE/"
curl -s "$BASE/api/ping"
curl -s -X POST "$BASE/api/items"
curl -s "$BASE/api/items/$ID"
curl -s "$BASE/robots.txt"
curl -s -o /dev/null -w '%{http_code}' "$BASE/api/nothing"   # 404 기대
# 옛 주소는 308 로 새 주소를 알려 준다
curl -s -o /dev/null "$SITE/api/ping"
curl -sL "$SITE/api/ping"
echo "옮김: $SITE/api/ping → $SITE/shop/api/ping"
curl -s "http://127.0.0.1:9091/api/ping"
curl -s "https://example.com/api/ping"
curl -s "$TARGET_URL/api/ping"
curl -s "$BASE/api/pong"   # qcheck-allow: R5 (일부러 없는 경로를 친다)
grep -c 단계 "$ROOT/docs/GUIDE.md"
mkdir -p "$ROOT/out/logs"
echo 결과 > "$ROOT/out/log.txt"
[ -f "$ROOT/docs/NOPE.md" ] && cat "$ROOT/docs/NOPE.md"
. "$ROOT/.env"
