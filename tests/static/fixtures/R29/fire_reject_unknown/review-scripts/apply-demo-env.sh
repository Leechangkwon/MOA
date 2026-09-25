#!/usr/bin/env bash
# 견본(v4 재검토 3): .env 거부(겹친 반복 · rename 글자 없음) — rename 거부가 아니다(1)
set -euo pipefail
PATCH="${1:?}"; APP=/srv/demo.example.invalid/app
grep -qE '^\+\+\+ b/([a-z_]+/)*\.env' "$PATCH" && exit 1
OUT=$(grep -a '^diff --git ' "$PATCH" | awk '{print $4}' | sed 's#^b/##' | grep -v '^docs/' || true)
[ -z "$OUT" ] || exit 1
git -C "$APP" apply "$PATCH"
