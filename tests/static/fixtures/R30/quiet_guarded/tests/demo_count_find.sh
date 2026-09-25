#!/usr/bin/env bash
# 견본: 옛 사본을 세기만 한다(지우지 않음)
set -u
D="${TMPDIR:-/tmp}"
n=$(find "$D" -maxdepth 1 -name 'demo-cnt.*' | wc -l)
echo "옛 사본 $n개"
T=$(mktemp "$D/demo-cnt.XXXXXX")
printf 'x\n' > "$T"
cat "$T"
