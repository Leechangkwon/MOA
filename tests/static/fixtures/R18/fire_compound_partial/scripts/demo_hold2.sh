#!/usr/bin/env bash
# 가짜 견본(대조 — v4 복합 조건 가드, 셸) — 앞 조각([ -e ])과 뒤 조각([ -s ])이 달라 가드가 아니다 → 울림(1)
set -u
DRY=0; [ "${1:-}" = "--dry-run" ] && DRY=1
for f in /var/lib/demo-hold/in/*; do
  [ -e "$f.lock" ] && [ "$DRY" -eq 1 ] && continue
  [ -s "$f" ] && rm -f "$f"
done
