#!/usr/bin/env bash
# 견본(v4 재검토 3): '.*' 열 개 이은 패턴(rename 글자 없음) — 거르개가 바로 글자 판정 → 부하와 무관하게 울림(1)
set -euo pipefail
PATCH="${1:?}"; APP=/srv/demo.example.invalid/app
grep -qE '^diff.*.*.*.*.*.*.*.*.*.*Q' "$PATCH" && exit 1
OUT=$(grep -a '^diff --git ' "$PATCH" | awk '{print $4}' | sed 's#^b/##' | grep -v '^docs/' || true)
[ -z "$OUT" ] || exit 1
git -C "$APP" apply "$PATCH"
