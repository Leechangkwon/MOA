#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/../.."
python3 tests/community/stage89_harness.py --suite legacy_compat_cases.py --output "${LEGACY_TEST_OUTPUT:-$(mktemp -d -t community-legacy-XXXXXX)}"
