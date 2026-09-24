#!/usr/bin/env bash
# 견본: 머리 확인 요청 뒤 기록을 다시 비운다 — 조용
PORT=18115
SITE="http://127.0.0.1:$PORT"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
PASS=0; FAIL=0
expect() {
  if [ "$2" = "$3" ]; then printf '  ok %s\n' "$1"; PASS=$((PASS+1))
  else printf '  FAIL %s (기대=%s 실제=%s)\n' "$1" "$2" "$3"; FAIL=$((FAIL+1)); fi
}

start_server() {
  : > "$WORK/assets.$1.log"
  ( cd "$WORK/$1" && DEMO_ASSET_LOG="$WORK/assets.$1.log" exec php -S "127.0.0.1:$PORT" router.php ) &
  local cc
  cc=$(curl -s -o /dev/null -D - "$SITE/demo/assets/app.js" | sed -n 's/^Cache-Control: //p')
  expect "캐시 머리" "max-age=60" "$cc"
  : > "$WORK/assets.$1.log"      # 머리 확인 요청은 기록에서 뺀다
}
start_server old
python3 "$WORK/fill_browser.py" "$SITE"
n_old=$(sort -u "$WORK/assets.old.log" | wc -l)
expect "자산을 받았다(1건 이상)" "yes" "$([ "$n_old" -ge 1 ] && echo yes || echo no)"
