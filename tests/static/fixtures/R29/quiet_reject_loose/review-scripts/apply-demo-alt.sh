#!/usr/bin/env bash
# 견본(v4 재검토 중간 1): 앵커 없는 'rename|copy' 갈래 — 조용(0)
set -euo pipefail
PATCH="${1:?}"; APP=/srv/demo.example.invalid/app
if grep -qE 'rename|copy' "$PATCH"; then echo "이름 바꾸기·복사 — 멈춘다"; exit 1; fi
OUT=$(grep -a '^diff --git ' "$PATCH" | awk '{print $3; print $4}' | sed 's#^[ab]/##' | sort -u | grep -v '^docs/' || true)
[ -z "$OUT" ] || exit 1
git -C "$APP" apply "$PATCH"
