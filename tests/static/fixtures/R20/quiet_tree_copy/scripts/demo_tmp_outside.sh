#!/usr/bin/env bash
# 견본: 임시 사본은 저장소 밖(TMPDIR) — 옛 사본 청소 있음
set -eu
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
find "${TMPDIR:-/tmp}" -maxdepth 1 -name 'demo-envcopy.*' -mmin +10 -delete
T="$(mktemp -p "${TMPDIR:-/tmp}" demo-envcopy.XXXXXX)"
cp "$ROOT/.env" "$T"
