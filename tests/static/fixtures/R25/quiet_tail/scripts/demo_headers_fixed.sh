#!/usr/bin/env bash
# 견본: 고친 hdr — 머리 이름은 마지막 인자, 꼬리는 길이를 준다(조용)
set -euo pipefail
B=http://127.0.0.1:18092
hdr() {
  local n=$# url=$1 name=${!#} v
  v=$(curl -s -D - -o /dev/null "${@:2:$((n-2))}" "$url" | tr -d '\r' | sed -n "s/^${name}: //Ip" | head -1 || true)
  printf '%s' "${v:-(none)}"
}
hdr "$B/demo/app.js" -H 'Accept-Encoding: gzip' Content-Encoding
hdr "$B/demo/app.js" Cache-Control
wrap() { echo "+ $1"; "$@"; }
wrap true
