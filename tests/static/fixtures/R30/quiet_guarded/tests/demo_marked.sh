#!/usr/bin/env bash
# 견본: 받아들인 위험 표식(한 사람이 한 번에 하나만 돌리는 손 도구)
set -u
# qcheck-allow: R30 — 손으로 한 번씩만 부른다(동시 실행 없음)
rm -f /tmp/demo-hand.*
T=$(mktemp /tmp/demo-hand.XXXXXX)
printf 'x\n' > "$T"
cat "$T"
