#!/usr/bin/env bash
# 견본(v4 재검토 중간 1): here-string 으로 받은 패치 본문을 ^rename 으로 — 입력을 모름 → 인정 — 조용(0)
set -euo pipefail
PATCH="${1:?}"; APP=/srv/demo.example.invalid/app
if grep -q '^rename' <<< "$(cat "$PATCH")"; then exit 1; fi
OUT=$(grep -a '^diff --git ' "$PATCH" | awk '{print $3; print $4}' | sed 's#^[ab]/##' | sort -u | grep -v '^docs/' || true)
[ -z "$OUT" ] || exit 1
git -C "$APP" apply "$PATCH"
