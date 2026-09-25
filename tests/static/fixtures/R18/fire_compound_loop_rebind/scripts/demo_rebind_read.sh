#!/usr/bin/env bash
# 가짜 견본(v4 검토 중간 1, 셸 while read) — 가드 뒤 `while read -r q` 가 조각 변수 q 를 다시 묶음 → 울림(1)
set -u
DRY=0; [ "${1:-}" = "--dry-run" ] && DRY=1
for f in /var/lib/demo-hold/in/*; do
  q="/var/lib/demo-hold/held/$(basename "$f")"
  if [ -e "$q" ] && [ "$DRY" -eq 1 ]; then
    continue
  fi
  while read -r q; do
    if [ -e "$q" ]; then
      rm -f "$q"
    fi
  done < /var/lib/demo-hold/list.txt
done
