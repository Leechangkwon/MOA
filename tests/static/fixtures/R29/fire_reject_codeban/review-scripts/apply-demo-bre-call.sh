#!/usr/bin/env bash
# 견본(v4 재검토 4): BRE 'rename(' — 글자 괄호 = 함수 호출 모양 → 거부 아님(1)
set -euo pipefail
PATCH="${1:?}"; APP=/srv/demo.example.invalid/app
grep -q 'rename(' "$PATCH" && exit 1
OUT=$(grep -a '^diff --git ' "$PATCH" | awk '{print $4}' | sed 's#^b/##' | grep -v '^docs/' || true)
[ -z "$OUT" ] || exit 1
git -C "$APP" apply "$PATCH"
