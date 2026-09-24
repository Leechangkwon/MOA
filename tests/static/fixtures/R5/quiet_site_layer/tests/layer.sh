#!/bin/bash
# 견본: 사이트 층(옛 주소 전달 · 옛 주소 요청이 기록·DB 에 닿지 않음)을 일부러 보는 시험(가짜 앱 /shop).
# R5 는 조용해야 한다. 요청마다 설명 주석과 빈 줄로 떨어져 있어서 「같은 줄·바로 위 주석」 표시로는
# 빠지지 않는다 — 요청 결과가 가는 곁 줄(①)이나 사이트 뿌리 변수 측정(②)으로만 빠진다(skip 으로 셈).
PORT=9555
SITE="http://127.0.0.1:$PORT"
BASE="$SITE/shop"
WORK="$(mktemp -d)"
php -S "127.0.0.1:$PORT" -t public public/index.php > "$WORK/srv.log" 2>&1 &
code_of() { curl -s -o /dev/null -w '%{http_code}' "$@"; }
hits() { grep -c 'db open' "$WORK/srv.log"; }
curl -s "$BASE/api/ping"

# ① 머리 파일로 결과를 받는 다음 단언이 상태와 새 주소 머리를 기대한다

curl -s -D "$WORK/a.head" -o /dev/null -X POST -d '{}' "$SITE/api/items"
expect "POST 옮김 — 상태 · 새 주소 머리" "308|/shop/api/items" \
  "$(sed -n '1s/^HTTP[^ ]* \([0-9]*\).*/\1/p' "$WORK/a.head")|$(sed -n 's/^Location: *//Ip' "$WORK/a.head" | tr -d '\r')"

# ① 변수로 결과를 받는 곁 줄(사이에 출력 줄이 하나 있다)

got=$(code_of "http://127.0.0.1:$PORT/api/ping")
echo "  · 받은 상태: $got"
expect "GET 옮김" "308" "$got"

# ① 응답을 버리는 측정 — 그 뒤 첫 단언이 무엇을 쟀는지 말한다

h0=$(hits)
code_of "http://127.0.0.1:$PORT/api/items/7" >/dev/null
h1=$(hits)
expect "옛 주소 요청 → DB 열기 증가" "0" "$((h1 - h0))"

# ② 사이트 뿌리 변수로 보내고 응답을 버리는 측정 — 뒤 단언에는 표시가 없다(같은 원점의 BASE 가 따로 있음)

n0=$(hits)
code_of "$SITE/" >/dev/null; code_of "$SITE/api/ping" >/dev/null; code_of "$SITE/nowhere" >/dev/null
n1=$(hits)
expect "DB 없는 판 — 사이트 층 요청 동안 연결 시도" "0" "$((n1 - n0))"
