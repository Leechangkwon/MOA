#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/../.."
python3 tests/community/stage89_harness.py --suite automation_backend_cases.py --output "${BACKEND_TEST_OUTPUT:-$(mktemp -d -t community-backend-XXXXXX)}"
