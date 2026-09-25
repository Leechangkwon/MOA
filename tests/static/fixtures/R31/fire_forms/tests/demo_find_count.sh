#!/usr/bin/env bash
# 견본: find 로 모은 tests/ 아래 셸 전부에서 탐침 이름을 센다
set -u
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
if [ "$(grep -c 'demo_probe' $(find "$ROOT/tests" -name '*.sh') | awk -F: '{s+=$2} END{print s+0}')" -eq 4 ]; then
  echo "탐침 4곳"
else
  echo "탐침 수가 다르다"; exit 1
fi
