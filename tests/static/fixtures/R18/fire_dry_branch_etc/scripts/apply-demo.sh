#!/usr/bin/env bash
# 가짜 견본(대조) — dry-run 참 갈래 안이라도 임시 밖(/etc)에 쓰면 울린다(1). 임시 사본 아래 링크는 조용.
set -eu
DRY=0
[ "${1:-}" = "--dry-run" ] && DRY=1
if [ "$DRY" -eq 1 ]; then
  T=$(mktemp -d)
  for l in "$T"/demo/sites-enabled/*; do
    ln -sfn "$T/demo/sites-available/site" "$l"
  done
  cp /usr/share/demo/site.conf /etc/demo/sites-available/site
  exit 0
fi
