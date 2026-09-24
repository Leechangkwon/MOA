#!/usr/bin/env bash
# 견본: 임시 서버의 자산 기록으로 「브라우저가 자산을 받았다」를 센다(가짜)
PORT=18111
SITE="http://127.0.0.1:$PORT"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
PASS=0; FAIL=0
expect() {
  if [ "$2" = "$3" ]; then printf '  ok %s\n' "$1"; PASS=$((PASS+1))
  else printf '  FAIL %s (기대=%s 실제=%s)\n' "$1" "$2" "$3"; FAIL=$((FAIL+1)); fi
}

start_server() {   # $1 = old|new
  : > "$WORK/assets.$1.log"
  ( cd "$WORK/$1" && DEMO_ASSET_LOG="$WORK/assets.$1.log" exec php -S "127.0.0.1:$PORT" router.php > "$WORK/server.$1.log" 2>&1 ) &
  SERVER_PID=$!
  local cc
  cc=$(curl -s -o /dev/null -D - "$SITE/demo/assets/app.js" | tr -d '\r' | sed -n 's/^Cache-Control: //p')
  expect "캐시 머리" "max-age=60" "$cc"
}
start_server old
python3 "$WORK/fill_browser.py" "$SITE" "$WORK/assets.old.log"
n_old=$(sort -u "$WORK/assets.old.log" | wc -l)
expect "N-1 자산을 받았다(1건 이상)" "yes" "$([ "$n_old" -ge 1 ] && echo yes || echo no)"
kill "$SERVER_PID"
