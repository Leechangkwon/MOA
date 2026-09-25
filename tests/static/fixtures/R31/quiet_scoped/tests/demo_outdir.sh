#!/usr/bin/env bash
# 견본: 저장소가 아닌 출력 폴더를 센다
set -u
OUT="${1:-$HOME/.cache/demo-out}"
n=$(grep -rc 'ERROR' "$OUT" | awk -F: '{s+=$2} END{print s+0}')
[ "$n" -eq 0 ] || exit 1
