#!/usr/bin/env bash
# 견본(v4 재검토 4): 코드 금지 줄 '^\+…rename\s*\(…\);' — 더한 줄(^\+)·함수 호출 모양 → 머리 줄 거부 아님(1)
set -euo pipefail
PATCH="${1:?}"; APP=/srv/demo.example.invalid/app
grep -qE '^\+[^#]*rename\s*\([^)]*\)\s*;' "$PATCH" && exit 1
OUT=$(grep -a '^diff --git ' "$PATCH" | awk '{print $4}' | sed 's#^b/##' | grep -v '^docs/' || true)
[ -z "$OUT" ] || exit 1
git -C "$APP" apply "$PATCH"
