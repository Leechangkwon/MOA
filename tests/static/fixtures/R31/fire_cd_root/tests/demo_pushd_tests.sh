#!/usr/bin/env bash
# 견본(v4 검토 중간 2 대조): pushd 로 tests 에 들어가 경로 없는 grep -r 로 세어 견줌(1)
set -u
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
pushd "$ROOT/tests" >/dev/null || exit 2
[ "$(grep -rl 'demo_probe' | wc -l)" -eq 4 ] || exit 1
popd >/dev/null
