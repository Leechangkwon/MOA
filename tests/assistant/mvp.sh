#!/usr/bin/env bash
# Independent synthetic DB/HTTP only; no production records/tokens/services.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
RESULT="${ASSISTANT_TEST_OUTPUT:-$(mktemp -d /tmp/qaredi-assistant-results-XXXXXX)}"
mkdir -p "$RESULT"
php "$ROOT/tests/assistant/unit.php" | tee "$RESULT/unit.log"
python3 "$ROOT/tests/assistant/serve.py" --output "$RESULT"
printf 'ASSISTANT_TEST_REPORT=%s\n' "$RESULT"
