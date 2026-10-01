#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/../.."
python3 tests/community/automation_ui_serve.py --output "${AUTOMATION_UI_OUTPUT:-$(mktemp -d -t community-automation-ui-XXXXXX)}"
