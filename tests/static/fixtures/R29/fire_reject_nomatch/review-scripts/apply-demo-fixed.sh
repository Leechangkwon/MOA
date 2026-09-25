#!/usr/bin/env bash
# 견본(v4 재검토 중간 1): grep -F '^rename from' — 고정 글자라 ^ 가 글자 그대로 → 맞지 않음(1)
set -euo pipefail
PATCH="${1:?}"; APP=/srv/demo.example.invalid/app
grep -qF '^rename from' "$PATCH" && exit 1
OUT=$(grep -a '^diff --git ' "$PATCH" | awk '{print $3; print $4}' | sed 's#^[ab]/##' | sort -u | grep -v '^docs/' || true)
[ -z "$OUT" ] || exit 1
git -C "$APP" apply "$PATCH"
