#!/usr/bin/env bash
# 견본(v4 검토 중간 2): 직전 cd 를 풀 수 없음(인자로 받은 폴더 · 갈래 안 cd · popd) → 어디를 세는지 모름 → 조용
set -u
WORK="${1:?작업 폴더}"
cd "$WORK" || exit 2
n=$(grep -rc 'ERROR' . | awk -F: '{s+=$2} END{print s+0}')
[ "$n" -eq 0 ] || exit 1
cd "$(dirname "$0")/.." || exit 2
if [ -d build ]; then
  cd build
fi
[ "$(grep -rc 'DEMO-TODO' . | awk -F: '{s+=$2} END{print s+0}')" -eq 3 ] || exit 1
pushd /var/tmp >/dev/null
popd >/dev/null
[ "$(grep -rl 'demo_probe' | wc -l)" -eq 4 ] || exit 1
