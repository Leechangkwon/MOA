#!/usr/bin/env bash
# 견본: 받아들인 표식(tests/static 에 그 글자가 없음을 따로 확인함)
set -u
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
# qcheck-allow: R31 — 세는 글자는 봇 규칙·견본에 없다(tests/static 을 따로 grep 해 0 확인)
[ "$(grep -rc 'demo-unique' "$ROOT/tests" | awk -F: '{s+=$2} END{print s+0}')" -eq 1 ] || exit 1
