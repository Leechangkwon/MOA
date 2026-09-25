#!/usr/bin/env bash
# 견본: flock 으로 동시 실행을 막은 뒤에 청소한다
set -eu
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
CONF="$ROOT/config/demo.ini"; D="$(dirname "$CONF")"
exec 9>"$D/.demo.lock"
flock -n 9 || { echo "다른 실행이 돈다"; exit 1; }
find "$D" -maxdepth 1 -name '.demo.fl.*' -delete
T="$(mktemp -p "$D" .demo.fl.XXXXXX)"
awk '{ print }' "$CONF" > "$T"
cat "$T" > "$CONF"
