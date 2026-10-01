#!/usr/bin/env bash
set -euo pipefail
cross_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
cross_output="${STAGE89_CROSS_OUTPUT:-${HOME}/.cache/qaredi-stage89-cross/$(date +%Y%m%d-%H%M%S)-$$}"
exec python3 "$cross_root/tests/stage89_cross_regression/run.py" --output "$cross_output"
