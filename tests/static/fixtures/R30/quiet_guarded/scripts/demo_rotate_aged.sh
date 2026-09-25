#!/usr/bin/env bash
# 견본: 10분 넘은 옛 사본만 지운다(동시 실행의 사본은 남긴다)
set -eu
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
CONF="$ROOT/config/demo.ini"
DIR="$(dirname "$CONF")"
find "$DIR" -maxdepth 1 -type f -name '.demo.rot.*' -mmin +10 -delete
T="$(mktemp -p "$DIR" .demo.rot.XXXXXX)"
trap 'rm -f "$T"' EXIT
awk '{ print }' "$CONF" > "$T"
cat "$T" > "$CONF"
