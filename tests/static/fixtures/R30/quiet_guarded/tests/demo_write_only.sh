#!/usr/bin/env bash
# 견본: 임시 폴더에 쓰기만 하고 읽지 않는다
set -u
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
rm -rf /tmp/demo-drop.*
D=$(mktemp -d /tmp/demo-drop.XXXXXX)
cp "$ROOT/README.md" "$D/README.md"
