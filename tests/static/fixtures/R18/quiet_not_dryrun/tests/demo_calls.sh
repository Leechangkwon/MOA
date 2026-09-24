#!/usr/bin/env bash
# 견본: 다른 스크립트에 --dry-run 을 넘기는 시험 — 스스로는 dry-run 을 받지 않는다
OUT=/var/lib/demo-calls
DRY=$(php scripts/demo.php --dry-run 2>&1)
echo "$DRY" > "$OUT/dry.txt"
DRY_RUN=1 bash scripts/other.sh > "$OUT/other.txt"
mkdir -p "$OUT/more"
