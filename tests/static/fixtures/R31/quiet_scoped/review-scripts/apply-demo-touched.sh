#!/usr/bin/env bash
# 견본: 표식은 패치가 건드린 파일 안에서만 센다
set -euo pipefail
PATCH="${1:?}"; APP=/srv/demo.example.invalid/app
chk() { [ "$2" = "$3" ] && echo "ok $1" || echo "틀림 $1"; }
nmark=0
for f in $(grep -a '^diff --git ' "$PATCH" | awk '{print $4}' | sed 's#^b/##' | sort -u); do
  c=$(grep -c 'demo-mark: X1' "$APP/$f" || true); nmark=$((nmark + ${c:-0}))
done
chk "표식 수" "5" "$nmark"
