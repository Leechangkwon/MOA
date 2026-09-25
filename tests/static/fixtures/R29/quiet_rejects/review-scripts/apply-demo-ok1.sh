#!/usr/bin/env bash
# 견본: rename/copy 머리와 링크를 반영 앞에서 거부한다
set -euo pipefail
PATCH="${1:?}"; APP=/srv/demo.example.invalid/app
if grep -aqE '^(rename|copy) (from|to) |^similarity index ' "$PATCH"; then echo "이름 바꾸기·복사 — 거부"; exit 1; fi
if grep -aqE '^(new file mode|new mode|old mode) 120000' "$PATCH"; then echo "링크 — 거부"; exit 1; fi
OUT=$(grep -a '^diff --git ' "$PATCH" | awk '{print $3; print $4}' | sed 's#^[ab]/##' | sort -u | grep -v '^docs/' || true)
[ -z "$OUT" ] || exit 1
git -C "$APP" apply "$PATCH"
