#!/usr/bin/env bash
# 견본: 시험의 dry-run — 막힌 쓰기만
DRY_RUN="${DRY_RUN:-0}"
die() { echo "$*" >&2; exit 1; }
[ -d /srv/demo ] || die "no tree"
save() {
  [ "$DRY_RUN" = 1 ] && return 0
  echo ok > /var/lib/demo-check/ok
}
save
if [ "$DRY_RUN" != 1 ]; then
  sed -i 's/a/b/' /var/lib/demo-check/conf
fi
