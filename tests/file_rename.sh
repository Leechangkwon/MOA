#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="${FILE_RENAME_OUTPUT:-$(mktemp -d /tmp/qaredi-rename-results-XXXXXX)}"
python3 "$ROOT/tests/file_rename/serve.py" --output "$OUT"
printf 'SANITIZED_RESULTS=%s\n' "$OUT"
