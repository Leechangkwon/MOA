#!/usr/bin/env bash
# 견본: 시험 자료 심기 — 시험도 dry-run 을 갖는다
set -u
DRY=${DRY:-0}
[ "${1:-}" = "--dry-run" ] && DRY=1
MARK_FILE=/var/lib/demo-seed/seeded

run() {
  if [ "$DRY" -eq 1 ]; then
    echo "+ $*"
    return 0
  fi
  "$@"
}

remember() {
  printf '%s\n' "$1" >> "$MARK_FILE"
}

run mkdir -p /var/lib/demo-seed
run cp /usr/share/demo/seed.sql /var/lib/demo-seed/
remember "seed-1"
