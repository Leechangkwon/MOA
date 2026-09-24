#!/usr/bin/env bash
# 견본: 인스턴스 폴더를 함수에 넘겨 .env 를 복사(정리 trap 도 없다)
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
make_inst() {
  local dir="$1"
  mkdir -p "$dir/app"
  cp "$ROOT/.env" "$dir/app/.env"
}
INST_A="$(mktemp -d)"; INST_B="$(mktemp -d)"
make_inst "$INST_A"
make_inst "$INST_B"
