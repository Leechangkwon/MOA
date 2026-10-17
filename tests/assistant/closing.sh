#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
RESULT="${ASSISTANT_TEST_OUTPUT:-$(mktemp -d /tmp/qaredi-closing-results-XXXXXX)}"
mkdir -p "$RESULT"
python3 "$ROOT/tests/assistant/serve.py" --suite closing --output "$RESULT"
printf 'CLOSING_TEST_REPORT=%s\n' "$RESULT"
