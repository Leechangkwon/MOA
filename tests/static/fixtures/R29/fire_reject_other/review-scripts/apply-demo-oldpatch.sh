#!/usr/bin/env bash
# 견본(v4 검토 중간 3): 머리 모양 거부 grep 이 반영할 패치가 아닌 지난 패치($OLD)를 본다(1)
set -euo pipefail
P="${1:?}"; OLD="${2:?}"; APP=/srv/demo.example.invalid/app
if grep -aqE '^(rename|copy) (from|to) |^similarity index ' "$OLD"; then echo "지난 패치에 이름 바꾸기"; exit 1; fi
BAD=$(grep -a '^diff --git ' "$P" | awk '{print $3; print $4}' | sed 's#^[ab]/##' | grep -vE '^tests/' || true)
[ -z "$BAD" ] || exit 1
git -C "$APP" apply "$P"
