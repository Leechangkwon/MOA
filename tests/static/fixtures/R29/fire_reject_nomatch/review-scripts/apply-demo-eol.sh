#!/usr/bin/env bash
# 견본(v4 재검토 중간 1): 패턴 '^rename (from|to)$' — 머리 줄엔 경로가 뒤따라 맞지 않음(1)
set -euo pipefail
PATCH="${1:?}"; APP=/srv/demo.example.invalid/app
if grep -qE '^rename (from|to)$' "$PATCH"; then exit 1; fi
OUT=$(grep -a '^diff --git ' "$PATCH" | awk '{print $3; print $4}' | sed 's#^[ab]/##' | sort -u | grep -v '^docs/' || true)
[ -z "$OUT" ] || exit 1
git -C "$APP" apply "$PATCH"
