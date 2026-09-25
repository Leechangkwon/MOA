#!/usr/bin/env bash
# 가짜 견본(v4 검토 중간 1, 셸 for) — 가드 뒤 `for q in …` 가 조각 변수 q 를 다시 묶음 → 울림(1)
set -u
DRY=0; [ "${1:-}" = "--dry-run" ] && DRY=1
for f in /var/lib/demo-hold/in/*; do
  q="/var/lib/demo-hold/held/$(basename "$f")"
  [ -e "$q" ] && [ "$DRY" -eq 1 ] && continue
  for q in "$q".*; do
    [ -e "$q" ] && rm -f "$q"
  done
done
