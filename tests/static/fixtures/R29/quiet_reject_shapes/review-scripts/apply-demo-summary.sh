#!/usr/bin/env bash
# 견본(v4 검토 중간 3 대조): 같은 패치의 git apply --summary 출력에서 rename·copy 를 찾아 멈춘다 — 조용(0)
set -euo pipefail
PATCH="${1:?}"; APP=/srv/demo.example.invalid/app
if git -C "$APP" apply --summary "$PATCH" | grep -qE '^ (rename|copy) '; then echo "이름 바꾸기 — 거부"; exit 1; fi
OUT=$(grep -a '^diff --git ' "$PATCH" | awk '{print $4}' | sed 's#^b/##' | grep -v '^docs/' || true)
[ -z "$OUT" ] || exit 1
git -C "$APP" apply "$PATCH"
