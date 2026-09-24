#!/usr/bin/env bash
# 견본: 반영 뒤 응답 머리 확인(가짜 주소) — hdr <주소> [curl 인자…] <머리 이름> 로 부르려던 도우미
set -euo pipefail
B=http://127.0.0.1:18090
hdr() { curl -s -D - -o /dev/null "${@:2}" "$1" | tr -d '\r' | sed -n "s/^$3: //Ip" | head -1; }
check() { if [ "$2" = "$3" ]; then echo "ok $1"; else echo "FAIL $1"; fi; }
check "gzip" "gzip" "$(hdr "$B/demo/app.js" -H 'Accept-Encoding: gzip' Content-Encoding)"
check "1년" "max-age=31536000" "$(hdr "$B/demo/app.js?v=abc123" Cache-Control)"
check "Location" "/demo/health" "$(hdr "$B/health" Location)"
