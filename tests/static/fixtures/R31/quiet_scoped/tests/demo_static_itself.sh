#!/usr/bin/env bash
# 견본: 규칙 폴더 자체를 센다(의도)
set -u
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
[ "$(grep -rl '^RULE = ' "$ROOT/tests/static" | wc -l)" -eq 3 ] || exit 1
