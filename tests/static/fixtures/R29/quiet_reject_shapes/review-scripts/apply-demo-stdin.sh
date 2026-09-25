#!/usr/bin/env bash
# 견본(v4 검토 중간 3 대조): 거부 grep 이 같은 패치를 < 로 받음 · cat 파이프로 받음(from/to 모양) — 조용(0)
set -euo pipefail
P="${1:?}"; APP=/srv/demo.example.invalid/app
grep -aqE '^(copy|rename) ' < "$P" && { echo "이름 바꾸기·복사 — 멈춘다"; exit 1; }
if cat "$P" | grep -qE 'rename (from|to)|copy (from|to)'; then exit 1; fi
BAD=$(grep -a '^diff --git ' "$P" | awk '{print $3; print $4}' | sed 's#^[ab]/##' | grep -vE '^tests/' || true)
[ -z "$BAD" ] || exit 1
git -C "$APP" apply "$P"
