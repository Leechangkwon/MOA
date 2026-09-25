#!/usr/bin/env bash
# 견본(v4 재검토 3): 앵커 없는 'a.*'×8 'Q' — 혼자 약 0.7초라 예전(타이머에 기댐)엔 부하에 따라 뒤집힘 · 이제 거르개가 바로 글자 판정 → 울림(1)
set -euo pipefail
PATCH="${1:?}"; APP=/srv/demo.example.invalid/app
grep -qE 'a.*.*.*.*.*.*.*.*Q' "$PATCH" && exit 1
OUT=$(grep -a '^diff --git ' "$PATCH" | awk '{print $4}' | sed 's#^b/##' | grep -v '^docs/' || true)
[ -z "$OUT" ] || exit 1
git -C "$APP" apply "$PATCH"
