#!/usr/bin/env bash
# 견본(v4 검토 중간 2): `cd "$ROOT/src"` 뒤 `grep -rc … .` — `.` 은 src 라 tests/static 이 들지 않음 → 조용
set -u
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT/src" || exit 2
n=$(grep -rc 'DEMO-TODO' . | awk -F: '{s+=$2} END{print s+0}')
[ "$n" -eq 3 ] || { echo "표식 수 $n"; exit 1; }
[ "$(grep -rl 'demo_probe' | wc -l)" -eq 4 ] || exit 1
