#!/usr/bin/env bash
# 견본: git grep 에서 tests/static 을 뺀다
set -u
ROOT="$(git rev-parse --show-toplevel)"
c=$(git -C "$ROOT" grep -c 'demo-flag' -- . ':!tests/static' | awk -F: '{s+=$2} END{print s+0}')
[ "$c" = 2 ] || exit 1
