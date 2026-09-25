#!/usr/bin/env bash
# 견본(v4 재검토 중간 1): --summary 출력을 변수에 받아 echo 로 grep — 입력을 모름 → 인정 · --summary 모양에 맞음 — 조용(0)
set -euo pipefail
PATCH="${1:?}"; APP=/srv/demo.example.invalid/app
SUM=$(git -C "$APP" apply --summary "$PATCH"); if echo "$SUM" | grep -qE '^ (rename|copy) '; then exit 1; fi
OUT=$(grep -a '^diff --git ' "$PATCH" | awk '{print $3; print $4}' | sed 's#^[ab]/##' | sort -u | grep -v '^docs/' || true)
[ -z "$OUT" ] || exit 1
git -C "$APP" apply "$PATCH"
