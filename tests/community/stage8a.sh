#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
python3 "$ROOT/tests/community/stage89_harness.py" --output "${OUTPUT:-$(mktemp -d -t community-stage89-http-XXXXXX)}"
