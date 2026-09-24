#!/usr/bin/env bash
# 견본: 고정 경로 — 복사 전에 비우므로 다음 실행이 같은 자리를 지운다
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
T=/tmp/demo-fixed-work
rm -rf "$T"
mkdir -p "$T"
cp "$ROOT/.env" "$T/.env"
