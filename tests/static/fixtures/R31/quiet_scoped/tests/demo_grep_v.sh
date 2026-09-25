#!/usr/bin/env bash
# 견본: 센 줄에서 tests/static 을 거른다
set -u
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
n=$(grep -rn 'demo_probe' "$ROOT/tests" | grep -v '/tests/static/' | wc -l)
[ "$n" -eq 4 ] || exit 1
