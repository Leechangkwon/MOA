#!/usr/bin/env bash
# 가짜 견본 — 사이트 뿌리 변수로 보낸 **쓰기** 요청(접두 빠짐)은 옛 주소 측정이 아니다: 울려야 한다
PORT=18990
ROOTURL="http://127.0.0.1:$PORT"
APPURL="$ROOTURL/shop"
WORK="$(mktemp -d)"
php -S "127.0.0.1:$PORT" -t public public/index.php > "$WORK/srv.log" 2>&1 &
curl -s -D "$WORK/h" -o /dev/null "$ROOTURL/login"
expect "GET /login — 308" "308" "$(sed -n '1p' "$WORK/h")"
curl -s -o /dev/null -X POST -H 'Content-Type: application/json' "$ROOTURL/api/items"
expect "만든 항목 수" "1" "$(count_rows items)"
