#!/usr/bin/env bash
# 견본(v4 재검토 3): -i 와 대문자 '^(RENAME|COPY)( [A-Z]+)+ ' — 글자 판정이 -i 를 반영 → 인정 — 조용(0)
set -euo pipefail
PATCH="${1:?}"; APP=/srv/demo.example.invalid/app
grep -qiE '^(RENAME|COPY)( [A-Z]+)+ ' "$PATCH" && exit 1
OUT=$(grep -a '^diff --git ' "$PATCH" | awk '{print $4}' | sed 's#^b/##' | grep -v '^docs/' || true)
[ -z "$OUT" ] || exit 1
git -C "$APP" apply "$PATCH"
