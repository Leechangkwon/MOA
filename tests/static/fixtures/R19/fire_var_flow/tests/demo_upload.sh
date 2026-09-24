#!/usr/bin/env bash
. "$(dirname "$0")/lib/demo_common.sh"
curl -s -F f=@/dev/null https://demo.example.invalid/api/files >/dev/null
ROW=$(psqlq "SELECT actor_name || '|' || result
               FROM audit_logs
              WHERE action = 'file.upload'
              ORDER BY id DESC LIMIT 1;")
echo "  감사 행: $ROW"
expect "업로드 감사 allow" "demo_admin01|allow" "$ROW"
