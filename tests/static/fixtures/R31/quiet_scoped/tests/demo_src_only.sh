#!/usr/bin/env bash
# 견본: 앱 코드(src)만 센다
set -u
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
expect() { [ "$2" = "$3" ] || echo "틀림: $1"; }
expect "도움말 13곳" 13 "$(grep -rl '^\$pageHelp' "$ROOT/src" | wc -l)"
