#!/usr/bin/env bash
# Q:F-17. Rebuild only from the exact lockfile; no install lifecycle scripts.
set -euo pipefail
task_root="$(cd "$(dirname "$0")/.." && pwd)"
cd "$task_root/tools/whiteboard"
if [[ "${1:-}" != "" && "${1:-}" != "--verify" ]]; then
  echo 'Usage: vendor_excalidraw.sh [--verify]' >&2; exit 2
fi
npm ci --ignore-scripts --no-fund --no-audit >/dev/null
node build.mjs "${1:-}"
