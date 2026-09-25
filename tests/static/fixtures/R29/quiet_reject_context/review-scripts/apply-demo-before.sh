#!/usr/bin/env bash
# 견본(v4 재검토 2 낮음): --before-context=2 · -a 와 붙은 -aB2 — 앞 grep 에 문맥 선택지가 있어 뒤 줄(rename 머리)까지 넘긴다 → 거부 줄 — 조용(0)
set -euo pipefail
PATCH="${1:?}"; APP=/srv/demo.example.invalid/app
grep -aB2 '^index ' "$PATCH" | grep -q '^rename from ' && exit 1
grep --before-context=2 '^index ' "$PATCH" | grep -q '^copy from ' && exit 1
OUT=$(grep -a '^diff --git ' "$PATCH" | awk '{print $4}' | sed 's#^b/##' | grep -v '^docs/' || true)
[ -z "$OUT" ] || exit 1
git -C "$APP" apply "$PATCH"
