#!/usr/bin/env bash
# 견본: 봇 규칙·견본 폴더를 빼고 센다
set -u
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
[ "$(grep -rc --exclude-dir=static 'DEMO-TODO' "$ROOT/tests" | awk -F: '{s+=$2} END{print s+0}')" -eq 3 ] || exit 1
