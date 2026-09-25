#!/usr/bin/env bash
# 견본(v4 검토 중간 3): 패치를 보지만 패턴이 머리 모양이 아님('renamed' — `rename from`·`rename to` 줄에 맞지 않음)(1)
set -euo pipefail
PATCH="${1:?}"; APP=/srv/demo.example.invalid/app
grep -q 'renamed' "$PATCH" && { echo "이름 바꾼 파일 — 멈춘다"; exit 1; }
OUT=$(grep -a '^diff --git ' "$PATCH" | awk '{print $4}' | sed 's#^b/##' | grep -v '^docs/' || true)
[ -z "$OUT" ] || exit 1
git -C "$APP" apply "$PATCH"
