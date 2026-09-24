#!/usr/bin/env bash
# 가짜 견본 — 단언 문구의 「legacy」 낱말만으로 접두 빠짐을 빼지 않는다: 울려야 한다
PORT=18991
ORIGIN="http://127.0.0.1:$PORT"
WORK="$(mktemp -d)"
php -S "127.0.0.1:$PORT" -t public public/index.php > "$WORK/srv.log" 2>&1 &
curl -s -o /dev/null "$ORIGIN/api/ping"
expect "legacy 필드 없음" "0" "$(count_rows items)"
