#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/../.."
output="${STAGE10_OUTPUT:-$(mktemp -d -t community-stage10-results-XXXXXX)}"
for suite in stage10_http.py stage10_compat.py browser_10.py; do
    python3 tests/community/stage10_harness.py --suite "$suite" --output "$output/${suite%.py}"
done
