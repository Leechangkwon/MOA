#!/usr/bin/env bash
. "$(dirname "$0")/lib/demo_common.sh"
curl -s -X POST https://demo.example.invalid/api/pages/home/snapshot >/dev/null
expect "스냅샷 판 3" "3" "$(psqlq "SELECT max(version) FROM demo_snapshots WHERE version > 0")"
