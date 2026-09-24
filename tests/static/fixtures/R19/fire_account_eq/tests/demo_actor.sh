#!/usr/bin/env bash
# 계정 id 는 실행마다 같다 — 경계가 아니다
. "$(dirname "$0")/lib/demo_common.sh"
UID_A=$(psqlq "SELECT id FROM demo_users WHERE username = 'demo_admin01'")
curl -s https://demo.example.invalid/api/share >/dev/null
expect "공유 감사 1건" "1" "$(psqlq "SELECT count(*) FROM audit_logs WHERE actor_id = $UID_A AND action = 'page.share'")"
