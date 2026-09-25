#!/usr/bin/env bash
# 견본: 지우는 글롭과 mktemp 접두가 다르다
set -u
rm -f /tmp/demo-a.*
T=$(mktemp /tmp/demo-b.XXXXXX)
printf 'x\n' > "$T"
cat "$T"
