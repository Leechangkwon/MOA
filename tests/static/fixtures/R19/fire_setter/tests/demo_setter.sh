#!/usr/bin/env bash
. "$(dirname "$0")/lib/demo_common.sh"
curl -s https://demo.example.invalid/api/login >/dev/null
db_read_var DENY demo_db "SELECT count(*) FROM audit_logs WHERE action = 'auth.login' AND result = 'deny'"
expect "로그인 거부 감사 1건" "1" "$DENY"
