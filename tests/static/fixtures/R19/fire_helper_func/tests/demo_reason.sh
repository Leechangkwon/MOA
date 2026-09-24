#!/usr/bin/env bash
. "$(dirname "$0")/lib/demo_common.sh"
last_reason() {
  psqlq "SELECT reason FROM audit_logs WHERE action = '$1' AND result = 'deny' ORDER BY id DESC LIMIT 1"
}
curl -s https://demo.example.invalid/api/fetch >/dev/null
expect "거부 사유" "host_not_allowed" "$(last_reason file.fetch)"
