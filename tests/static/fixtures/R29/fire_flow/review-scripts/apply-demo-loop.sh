#!/usr/bin/env bash
# 견본: 머리 줄 목록을 돌며 case 로 판정한다
set -eu
P="${1:?}"; APP=/srv/demo.example.invalid/app
for f in $(grep '^diff --git' "$P" | awk '{print $4}' | sed 's#^b/##'); do
  case "$f" in
    docs/*|tests/demo_*) ;;
    *) echo "목록 밖: $f"; exit 1 ;;
  esac
done
git -C "$APP" apply "$P"
