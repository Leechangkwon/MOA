#!/usr/bin/env bash
# 견본: 비밀 파일을 걸러 임시 파일에 쓰고 제자리로 옮긴다(가짜)
set -euo pipefail
TMP="$(mktemp)"
trap 'rm -f "$TMP"' EXIT
awk '$1 != "demo_old" {print}' /etc/tbox-v2/demo-accounts.txt > "$TMP"
install -m 600 "$TMP" /etc/tbox-v2/demo-accounts.txt
