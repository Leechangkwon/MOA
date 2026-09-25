#!/usr/bin/env bash
# 견본(v4 검토 중간 2): 함수 안에서 src 로 cd 한 뒤 센다 · 서브셸 ( cd src && … ) 안에서 센다 → 조용
set -u
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
count_src() {
  cd "$ROOT/src" || return 2
  [ "$(grep -rc 'DEMO-TODO' . | awk -F: '{s+=$2} END{print s+0}')" -eq 3 ]
}
count_src || exit 1
( cd "$ROOT/src" && [ "$(grep -rl 'demo_probe' . | wc -l)" -eq 4 ] ) || exit 1
