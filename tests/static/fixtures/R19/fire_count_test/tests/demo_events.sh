#!/usr/bin/env bash
. "$(dirname "$0")/lib/demo_common.sh"
curl -s https://demo.example.invalid/api/ping >/dev/null
expect "ping 이벤트가 있다" "yes" \
  "$([ "$(psqlq "SELECT count(*) FROM demo_events WHERE kind = 'ping'")" -ge 1 ] && echo yes || echo no)"
