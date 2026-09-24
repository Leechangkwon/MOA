#!/usr/bin/env bash
# 견본: 시작할 때 옛 폴더를 글롭으로 지운다
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
rm -rf "${TMPDIR:-/tmp}"/demo-glob.*
W=$(mktemp -d -t demo-glob.XXXXXX)
cp "$ROOT/.env" "$W/.env"
