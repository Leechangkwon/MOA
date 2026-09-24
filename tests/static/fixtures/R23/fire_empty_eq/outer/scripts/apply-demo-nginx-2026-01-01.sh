#!/usr/bin/env bash
# 견본: 바깥 반영 스크립트 — 반영 전·후 보안 머리가 같은지(가짜)
set -uo pipefail
L=http://127.0.0.1:18114
ok=1
say() { if [ "$2" = "$3" ]; then echo "  ok $1"; else echo "  FAIL $1"; ok=0; fi; }
sec() { curl -s -D - -o /dev/null "$1" | tr -d '\r' | grep -iE '^(content-security-policy|x-frame-options):' | sort; }
BEFORE=$(sec "$L/demo/login" | sha256sum | cut -c1-16)
echo "반영…"
say "보안 머리 그대로" "$BEFORE" "$(sec "$L/demo/login" | sha256sum | cut -c1-16)"
