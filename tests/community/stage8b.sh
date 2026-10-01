#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"
# Python dependencies: requests, PyYAML, markdown-it-py==3.0.0, playwright + Chromium.
# The caller may set PYTHONPATH to isolated test dependencies; never install at runtime.
OUT="${EXPORT_TEST_OUTPUT:-$(mktemp -d -t community-8b-results-XXXXXX)}"
python3 tests/community/export_converters.py
python3 tests/community/export_serve.py --output "$OUT"
printf '8-B evidence: %s\n' "$OUT"
