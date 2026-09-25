#!/usr/bin/env bash
# 견본: 이번 실행만의 mktemp -d 안에서 글롭 청소(남과 나누지 않는 폴더)
set -u
T=$(mktemp -d); trap 'rm -rf "$T"' EXIT
rm -f "$T"/part.*
P=$(mktemp "$T/part.XXXX")
printf 'x\n' > "$P"
cat "$P"
