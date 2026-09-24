#!/bin/bash
# 견본: 층을 나눠 쓰는(SITE · BASE) 파일이지만 사이트 층을 본다는 표시가 파일 어디에도 없다 —
# 접두 없는 측정 요청은 울려야 한다(하나).
PORT=9557
SITE="http://127.0.0.1:$PORT"
BASE="$SITE/shop"
WORK="$(mktemp -d)"
php -S "127.0.0.1:$PORT" -t public public/index.php > "$WORK/srv.log" 2>&1 &
curl -s "$BASE/api/ping"

s0=$(grep -c 'item saved' "$WORK/srv.log")
curl -s -o /dev/null -X POST -d '{}' "$SITE/api/items"
s1=$(grep -c 'item saved' "$WORK/srv.log")
expect "품목 만들기 뒤 기록 +1" "1" "$((s1 - s0))"
