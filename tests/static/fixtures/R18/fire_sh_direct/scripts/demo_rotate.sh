#!/usr/bin/env bash
# 견본: 로그 돌리기 — --dry-run 이면 계획만
set -u
DRY_RUN=0
for a in "$@"; do
  case "$a" in
    --dry-run) DRY_RUN=1 ;;
  esac
done
STATE=/var/lib/demo-rotate/last
OLD=/var/lib/demo-rotate/old.log
W=$(mktemp -d)
echo "plan" > "$W/plan.txt"
echo "note" >&2
[ "$DRY_RUN" -eq 1 ] || rm -f "$OLD"
if [ "$DRY_RUN" = 0 ]; then
  cp /var/lib/demo-rotate/cur.log "$OLD"
else
  echo "would copy"
fi
if [ "$DRY_RUN" -eq 1 ]; then printf 'dry\n'; else printf 'x\n' | tee -a /var/lib/demo-rotate/audit.txt >/dev/null; fi
date +%s > "$STATE"
rm -rf "$W"
