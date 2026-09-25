#!/usr/bin/env bash
# 견본(v4 재검토 4): '(copyright|license)+ ' — 겹친 반복 → 글자 판정 · copyright 는 copy 가 아님(단어 경계)(1)
set -euo pipefail
PATCH="${1:?}"; APP=/srv/demo.example.invalid/app
grep -qE '(copyright|license)+ ' "$PATCH" && exit 1
OUT=$(grep -a '^diff --git ' "$PATCH" | awk '{print $4}' | sed 's#^b/##' | grep -v '^docs/' || true)
[ -z "$OUT" ] || exit 1
git -C "$APP" apply "$PATCH"
