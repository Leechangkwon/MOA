#!/usr/bin/env bash
# 견본(v4 재검토 4): '.*.*.*.*renamed' — 반복 넷은 표본에 돌려 봄 → renamed 는 머리 줄에 맞지 않음(1)
set -euo pipefail
PATCH="${1:?}"; APP=/srv/demo.example.invalid/app
grep -qE '.*.*.*.*renamed' "$PATCH" && exit 1
OUT=$(grep -a '^diff --git ' "$PATCH" | awk '{print $4}' | sed 's#^b/##' | grep -v '^docs/' || true)
[ -z "$OUT" ] || exit 1
git -C "$APP" apply "$PATCH"
