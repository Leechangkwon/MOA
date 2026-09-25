#!/usr/bin/env bash
# 견본(v4 재검토 중간 1): 거부 grep 은 "$1", 허용 목록은 "$PATCH"(PATCH="$1" 별칭) — 조용(0)
set -euo pipefail
PATCH="$1"; APP=/srv/demo.example.invalid/app
if grep -qE '^(rename|copy) ' "$1"; then exit 1; fi
OUT=$(grep -a '^diff --git ' "$PATCH" | awk '{print $3; print $4}' | sed 's#^[ab]/##' | sort -u | grep -v '^docs/' || true)
[ -z "$OUT" ] || exit 1
git -C "$APP" apply "$PATCH"
