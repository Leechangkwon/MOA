#!/usr/bin/env bash
# 가짜 견본(대조 — v4 검토 중간 1, 셸) — 안쪽 for·while read 가 다른 변수(x·line)만 묶음 → 조용(0)
set -u
DRY=0; [ "${1:-}" = "--dry-run" ] && DRY=1
for f in /var/lib/demo-hold/in/*; do
  q="/var/lib/demo-hold/held/$(basename "$f")"
  [ -e "$q" ] && [ "$DRY" -eq 1 ] && continue
  for x in a b; do
    [ -e "$q" ] && rm -f "$q.$x"
  done
  while read -r line; do
    [ -e "$q" ] && rm -f "$q.$line"
  done < /var/lib/demo-hold/list.txt
done
