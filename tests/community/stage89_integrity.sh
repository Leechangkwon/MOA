#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/../.."
python3 tests/community/stage89_harness.py --suite root_integrity_cases.py --baseline-schema --output "${INTEGRITY_TEST_OUTPUT:-$(mktemp -d -t community-integrity-XXXXXX)}"
