#!/usr/bin/env bash
# 견본(v4 재검토 3): 겹친 반복 '(a+)+Q'(rename 글자 없음) — 거르개 → 글자 판정 → 거부 아님 → 울림(1) · 1초 안
set -euo pipefail
PATCH="${1:?}"; APP=/srv/demo.example.invalid/app
grep -qE '(a+)+Q' "$PATCH" && exit 1
OUT=$(grep -a '^diff --git ' "$PATCH" | awk '{print $4}' | sed 's#^b/##' | grep -v '^docs/' || true)
[ -z "$OUT" ] || exit 1
git -C "$APP" apply "$PATCH"
