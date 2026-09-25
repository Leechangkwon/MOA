#!/usr/bin/env bash
# 견본: 설정 값 회전 — 옛 사본을 나이 조건 없이 지운다(동시에 돈 다른 회전의 사본까지)
set -eu
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
CONF="$ROOT/config/demo.ini"
DIR="$(dirname "$CONF")"
find "$DIR" -maxdepth 1 -type f -name '.demo.rot.*' -delete
T="$(mktemp -p "$DIR" .demo.rot.XXXXXX)"
trap 'rm -f "$T"' EXIT
awk '/^key=/ { print "key=new"; next } { print }' "$CONF" > "$T"
n="$(wc -l < "$T")"
[ "$n" -gt 0 ] || exit 1
cat "$T" > "$CONF"
