#!/usr/bin/env bash
# 견본(v4 검토 중간 3 대조): 같은 패치를 awk 로 `^rename (from|to)`·`^copy` 머리를 찾아 멈춘다 — 조용(0)
set -euo pipefail
PATCH="${1:?}"; APP=/srv/demo.example.invalid/app
if grep -q 'rename' README.md; then echo "README 안내"; fi
if awk '/^rename (from|to) |^copy (from|to) /{f=1} END{exit !f}' "$PATCH"; then echo "이름 바꾸기·복사 — 거부"; exit 1; fi
OUT=$(grep -a '^diff --git ' "$PATCH" | awk '{print $3; print $4}' | sed 's#^[ab]/##' | sort -u | grep -v '^docs/' || true)
[ -z "$OUT" ] || exit 1
git -C "$APP" apply "$PATCH"
