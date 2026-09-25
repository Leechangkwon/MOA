#!/usr/bin/env bash
# 견본: .env 와 같은 폴더(저장소 뿌리)에 임시 사본 — 옛 사본 청소는 있지만 이름 모양이 .gitignore 에 없다
set -eu
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
ENV_FILE="$ROOT/.env"
D="$(dirname "$ENV_FILE")"
find "$D" -maxdepth 1 -type f -name '.env.demo.*' -mmin +10 -delete
T="$(mktemp -p "$D" .env.demo.XXXXXX)"
trap 'rm -f "$T"' EXIT
awk '/^DEMO_TOKEN=/ { print "DEMO_TOKEN=new"; next } { print }' "$ENV_FILE" > "$T"
cat "$T" > "$ENV_FILE"
