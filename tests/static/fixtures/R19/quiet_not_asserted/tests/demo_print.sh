#!/usr/bin/env bash
# 견본: 찍기만 하거나 기록 표가 아니거나 속 스크립트 글자인 것 — 대상 밖
. "$(dirname "$0")/lib/demo_common.sh"
note "최근 감사: $(psqlq "SELECT action FROM audit_logs ORDER BY id DESC LIMIT 1")"
LAST=$(psqlq "SELECT count(*) FROM audit_logs WHERE action = 'x'")
echo "  감사 x: $LAST"
expect "최근 사용자" "demo_admin01" "$(psqlq "SELECT username FROM demo_users ORDER BY id DESC LIMIT 1")"
OUT=$(php -r '$n = $db->query("SELECT count(*) FROM audit_logs")->fetchColumn(); echo $n > 0 ? "ok" : "no";')
expect "속 스크립트 결과" "ok" "$OUT"
