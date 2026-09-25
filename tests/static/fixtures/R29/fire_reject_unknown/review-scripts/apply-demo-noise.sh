#!/usr/bin/env bash
# 견본(v4 재검토 3): 무의미한 '(a|b)*zz' 한 줄로 규칙을 끄지 못함(1)
set -euo pipefail
PATCH="${1:?}"; APP=/srv/demo.example.invalid/app
grep -qE '(a|b)*zz' "$PATCH" && exit 1
OUT=$(grep -a '^diff --git ' "$PATCH" | awk '{print $4}' | sed 's#^b/##' | grep -v '^docs/' || true)
[ -z "$OUT" ] || exit 1
git -C "$APP" apply "$PATCH"
