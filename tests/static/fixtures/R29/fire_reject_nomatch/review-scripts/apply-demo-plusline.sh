#!/usr/bin/env bash
# 견본(v4 재검토 중간 1): 패턴 '^+.*rename' — 더한 줄(본문)에만 맞고 rename 머리에는 맞지 않음(1)
set -euo pipefail
PATCH="${1:?}"; APP=/srv/demo.example.invalid/app
grep -q '^+.*rename' "$PATCH" && exit 1
OUT=$(grep -a '^diff --git ' "$PATCH" | awk '{print $3; print $4}' | sed 's#^[ab]/##' | sort -u | grep -v '^docs/' || true)
[ -z "$OUT" ] || exit 1
git -C "$APP" apply "$PATCH"
