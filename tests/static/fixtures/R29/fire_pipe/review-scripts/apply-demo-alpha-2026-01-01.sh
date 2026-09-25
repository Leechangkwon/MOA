#!/usr/bin/env bash
# 견본: 허용 목록을 diff --git 줄에서만 뽑는다 — 링크는 거부하지만 rename/copy 머리는 보지 않는다
set -euo pipefail
PATCH="${1:?패치}"; APP=/srv/demo.example.invalid/app
ALLOW='^(docs/[a-z0-9_-]+\.md|tests/demo_[a-z0-9_]+\.sh)$'
if grep -aqE '^(new file mode|new mode) 120000' "$PATCH"; then echo "링크 패치 — 멈춘다"; exit 1; fi
OUT=$(grep -a '^diff --git ' "$PATCH" | awk '{print $3; print $4}' | sed 's#^[ab]/##' | sort -u | grep -vE "$ALLOW" || true)
[ -z "$OUT" ] || { echo "목록 밖: $OUT"; exit 1; }
git -C "$APP" apply --check "$PATCH"
git -C "$APP" apply "$PATCH"
