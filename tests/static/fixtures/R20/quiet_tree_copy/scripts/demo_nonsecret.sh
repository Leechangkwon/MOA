#!/usr/bin/env bash
# 견본: 트리 안 임시 파일이지만 비밀이 아닌 예시 설정을 옮긴다
set -eu
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
T="$(mktemp -p "$ROOT" .cfg.XXXXXX)"
cp "$ROOT/config/demo.example.ini" "$T"
