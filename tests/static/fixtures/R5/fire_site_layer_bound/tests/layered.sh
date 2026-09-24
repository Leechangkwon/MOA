#!/bin/bash
# 견본: 층을 나눠 쓰고(SITE · BASE) 사이트 층도 보는 파일 — 결과를 받는 요청은 여전히 울려야 한다(하나),
# 응답을 버리는 측정 요청은 조용하다.
PORT=9558
SITE="http://127.0.0.1:$PORT"
BASE="$SITE/shop"
WORK="$(mktemp -d)"
php -S "127.0.0.1:$PORT" -t public public/index.php > "$WORK/srv.log" 2>&1 &
code_of() { curl -s -o /dev/null -w '%{http_code}' "$@"; }
expect "옛 주소 308" "308" "$(code_of "$SITE/api/ping")"
r=$(code_of "$SITE/api/items/3")
expect "GET 품목 → 200" "200" "$r"
code_of "$SITE/api/ping" >/dev/null
expect "그 뒤 로그 줄 수" "0" "$(grep -c 'db open' "$WORK/srv.log")"
