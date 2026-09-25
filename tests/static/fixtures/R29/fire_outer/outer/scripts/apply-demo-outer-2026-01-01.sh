#!/usr/bin/env bash
# 견본: 바깥 반영 스크립트 — 목록을 while 루프로 판정(프로세스 치환)
set -euo pipefail
PATCH="${1:?}"; APP=/srv/demo.example.invalid/app
ALLOW='^(src/demo/[a-z_]+\.php|tests/demo_[a-z_]+\.sh)$'
while IFS= read -r f; do
  [[ "$f" =~ $ALLOW ]] || { echo "목록 밖: $f"; exit 1; }
done < <(grep -a '^diff --git ' "$PATCH" | awk '{print $4}' | sed 's#^b/##')
git -C "$APP" apply "$PATCH"
