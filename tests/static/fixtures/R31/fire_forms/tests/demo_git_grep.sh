#!/usr/bin/env bash
# 견본: git grep(경로 없음 = 트리 전체)으로 센 수를 더해 견준다
set -u
ROOT="$(git rev-parse --show-toplevel)"
c=$(git -C "$ROOT" grep -c 'demo-flag' | awk -F: '{s+=$2} END{print s+0}')
total=$((0 + c))
[ "$total" = 2 ] || { echo "플래그 수 $total"; exit 1; }
