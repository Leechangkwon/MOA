#!/usr/bin/env bash
# 견본: 저장소 뿌리 전체의 할 일 표식 수를 센다
set -u
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PASS=0; FAIL=0
expect() { if [ "$2" = "$3" ]; then PASS=$((PASS+1)); else FAIL=$((FAIL+1)); echo "틀림: $1"; fi; }
expect "할 일 표식 3개" 3 "$(grep -rc 'DEMO-TODO' "$ROOT" | awk -F: '{s+=$2} END{print s+0}')"
