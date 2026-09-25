#!/usr/bin/env bash
# 견본(v4 재검토 중간 1): 대소문자 무시 similarity — `similarity index` 머리에 맞음 — 조용(0)
set -euo pipefail
PATCH="${1:?}"; APP=/srv/demo.example.invalid/app
grep -qi similarity "$PATCH" && { echo "이름 바꾸기 — 멈춘다"; exit 1; }
OUT=$(grep -a '^diff --git ' "$PATCH" | awk '{print $3; print $4}' | sed 's#^[ab]/##' | sort -u | grep -v '^docs/' || true)
[ -z "$OUT" ] || exit 1
git -C "$APP" apply "$PATCH"
