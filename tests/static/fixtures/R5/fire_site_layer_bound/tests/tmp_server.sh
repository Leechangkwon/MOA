#!/bin/bash
# 견본: 임시 서버를 접두 없이 쳐서 전달 응답을 받고 틀어지는 시험(가짜) — 넷이 울려야 한다.
# 파일 안에 308 기대도, 사이트 뿌리 변수도 있지만 결함 줄의 결과를 받는 곁 줄은 503·500·기록 수를 기대한다.
REVIEW="http://127.0.0.1:9090/shop"
PORT=9556
ROOTURL="http://127.0.0.1:$PORT"
WORK="$(mktemp -d)"
php -S "127.0.0.1:$PORT" -t public public/index.php > "$WORK/srv.log" 2>&1 &
code_of() { curl -s -o /dev/null -w '%{http_code}' "$@"; }
hits() { grep -c 'db open' "$WORK/srv.log"; }
curl -s "$REVIEW/api/ping"

C=$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:$PORT/api/ping")
[ "$C" = "503" ] || echo "기동 실패"

curl -s -D "$WORK/o.head" -o /dev/null "http://127.0.0.1:$PORT/api/items/1"
expect "옛 주소 308" "308" "$(head -1 "$WORK/o.head")"
B=$(curl -s -o "$WORK/b.json" -D "$WORK/b.head" -w '%{http_code}' "http://127.0.0.1:$PORT/api/ping")
expect "GET 핑 → 503" "503" "$B"

M=$(code_of "$ROOTURL/api/items/2")
expect "GET 품목 → 500" "500" "$M"

k0=$(hits)
code_of "$ROOTURL/api/ping" >/dev/null
k1=$(hits)
expect "요청 뒤 기록" "1" "$((k1 - k0))"
