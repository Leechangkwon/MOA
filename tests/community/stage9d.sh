#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/../.."
python3 tests/community/briefing_serve.py --output "${BRIEFING_TEST_OUTPUT:-$(mktemp -d -t community-stage9d-results-XXXXXX)}"
