#!/usr/bin/env bash
# 견본: 바깥 반영 스크립트 — 고친 모양(조용)
set -uo pipefail
L=http://127.0.0.1:18118
ok=1
say() { if [ "$2" = "$3" ]; then echo "  ok $1"; else echo "  FAIL $1"; ok=0; fi; }
sec() { curl -s -D - -o /dev/null "$1" | tr -d '\r' | grep -iE '^(content-security-policy|x-frame-options):' | sort || true; }
h=$(sec "$L/demo/login")
printf '%s\n' "$h" | grep -qi '^content-security-policy:' || { echo "반영 전 CSP 가 없다 — 시작하지 않는다"; exit 1; }
BEFORE=$(printf '%s\n' "$h" | sha256sum | cut -c1-16)
say "보안 머리 그대로" "$BEFORE" "$(sec "$L/demo/login" | sha256sum | cut -c1-16)"
hdr() { local v; v=$(curl -s -D - -o /dev/null "$1" | sed -n "s/^$2: //Ip" | head -1 || true); printf '%s' "${v:-(none)}"; }
say "두 주소 같은 Vary" "$(hdr "$L/a" Vary)" "$(hdr "$L/b" Vary)"
say "gzip" "gzip" "$(hdr "$L/demo/app.js" Content-Encoding)"
X1=$(curl -s "$L/x"); X2=$(curl -s "$L/x")
say "두 번 같은 응답" "$X1" "$X2"   # 빈 응답도 같다는 뜻 — qcheck-allow: R23
