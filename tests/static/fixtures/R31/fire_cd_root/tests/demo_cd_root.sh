#!/usr/bin/env bash
# 견본(v4 검토 중간 2 대조): 직전 cd 가 저장소 뿌리로 풀리면 `.` 은 뿌리 — 뿌리 전체를 세어 견줌(1)
set -u
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT" || exit 2
n=$(grep -rc 'DEMO-TODO' . | awk -F: '{s+=$2} END{print s+0}')
[ "$n" -eq 3 ] || { echo "표식 수 $n"; exit 1; }
