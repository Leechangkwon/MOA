#!/usr/bin/env bash
# 가짜 견본(v4 복합 조건 가드, 셸) — `if [ A ] && [ "$DRY" -eq 1 ]; then …; continue; fi` 뒤의 쓰기가 같은 [ A ] 아래 — 조용(0)
set -u
DRY=0; [ "${1:-}" = "--dry-run" ] && DRY=1
SRC=/var/lib/demo-hold/in; HOLD=/var/lib/demo-hold/held; DONE=/var/lib/demo-hold/done
for f in "$SRC"/*; do
  q="$HOLD/$(basename "$f")"
  if [ -e "$q" ] && [ "$DRY" -eq 1 ]; then
    echo "[dry] 이미 보관됨: $q"
    continue
  fi
  if [ -e "$q" ]; then
    mv "$f" "$DONE/"
  fi
  [ -s "$f" ] && [ "$DRY" = 1 ] && continue
  [ -s "$f" ] && rm -f "$f"
done
