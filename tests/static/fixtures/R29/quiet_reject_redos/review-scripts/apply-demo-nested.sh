#!/usr/bin/env bash
# 견본(v4 재검토 3): 겹친 반복이지만 rename·copy 글자가 든 '^(rename|copy)( [a-z/._]+)+$' — 거르개 → 글자 판정 → 인정 — 조용(0)
set -euo pipefail
PATCH="${1:?}"; APP=/srv/demo.example.invalid/app
grep -qE '^(rename|copy)( [a-z/._]+)+$' "$PATCH" && exit 1
OUT=$(grep -a '^diff --git ' "$PATCH" | awk '{print $4}' | sed 's#^b/##' | grep -v '^docs/' || true)
[ -z "$OUT" ] || exit 1
git -C "$APP" apply "$PATCH"
