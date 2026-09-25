#!/usr/bin/env bash
# 견본(v4 재검토 3): 모드 바꾸기 거부(반복 · rename 글자 없음)(1)
set -euo pipefail
PATCH="${1:?}"; APP=/srv/demo.example.invalid/app
grep -qE '^(old|new) mode (100755|100644)+$' "$PATCH" && exit 1
OUT=$(grep -a '^diff --git ' "$PATCH" | awk '{print $4}' | sed 's#^b/##' | grep -v '^docs/' || true)
[ -z "$OUT" ] || exit 1
git -C "$APP" apply "$PATCH"
