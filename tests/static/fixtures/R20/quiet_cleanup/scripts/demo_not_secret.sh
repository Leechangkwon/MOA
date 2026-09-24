#!/usr/bin/env bash
# 견본: 비밀이 아닌 파일 · 임시 밖으로
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
W=$(mktemp -d); trap 'rm -rf "$W"' EXIT
cp "$ROOT/.env.example" "$W/"
cp "$ROOT/config/demo.json" "$W/demo.json"
cp "$ROOT/.env" /var/backups/demo/env.copy
