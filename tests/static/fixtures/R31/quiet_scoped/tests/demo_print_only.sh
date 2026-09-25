#!/usr/bin/env bash
# 견본: 세어 찍기만 한다(견주지 않음)
set -u
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
echo "표식 $(grep -rc 'DEMO-TODO' "$ROOT/tests" | awk -F: '{s+=$2} END{print s+0}')개"
