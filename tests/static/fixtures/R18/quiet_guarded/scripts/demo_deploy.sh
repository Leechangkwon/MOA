#!/usr/bin/env bash
# 견본: 배포 — 앞선 dry 종료 뒤에만 쓴다
set -eu
NOOP=false
while [ $# -gt 0 ]; do
  case "$1" in
    -n|--noop) NOOP=true ;;
  esac
  shift
done
exec 9>/run/demo-deploy.lock
flock -n 9 || exit 1
T=$(mktemp)
trap 'rm -f "$T"' EXIT
git -C /srv/demo status --short > "$T"
if $NOOP; then
  echo "would deploy"; exit 0
fi
cp "$T" /var/lib/demo-deploy/last-status
git -C /srv/demo tag "deploy-$(date +%s)"
