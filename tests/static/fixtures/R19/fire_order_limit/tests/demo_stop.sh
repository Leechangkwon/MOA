#!/usr/bin/env bash
# 견본: 끄기 단언이 가장 최근 감사 행을 읽는다 — 이번 실행 경계 없음
. "$(dirname "$0")/lib/demo_common.sh"
curl -s -X DELETE https://demo.example.invalid/api/me/act >/dev/null
expect "act.stop allow" "allow" \
  "$(psqlq "SELECT result FROM audit_logs WHERE action='act.stop' AND actor_name='demo_admin01' ORDER BY id DESC LIMIT 1")"
