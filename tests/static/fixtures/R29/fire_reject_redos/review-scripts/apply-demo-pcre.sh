#!/usr/bin/env bash
# 견본(v4 재검토 2 중간 — ReDoS): -P 세 갈래 반복 '(\w|\S|.)*Q' — 거르개가 「모름」으로 보내고 글자 판정: rename·copy·similarity 글자 없음 → 거부 아님 → 울림(1) · 1초 안(v4 재검토 3)
set -euo pipefail
PATCH="${1:?}"; APP=/srv/demo.example.invalid/app
grep -qP '(\w|\S|.)*Q' "$PATCH" && exit 1
OUT=$(grep -a '^diff --git ' "$PATCH" | awk '{print $4}' | sed 's#^b/##' | grep -v '^docs/' || true)
[ -z "$OUT" ] || exit 1
git -C "$APP" apply "$PATCH"
