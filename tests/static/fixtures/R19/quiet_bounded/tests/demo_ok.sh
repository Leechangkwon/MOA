#!/usr/bin/env bash
# 견본: 이번 실행으로 좁힌 모양 모음 — 조용해야 한다
. "$(dirname "$0")/lib/demo_common.sh"
M=$(mark)
T0=$(psqlq "SELECT now()")
TAG="demo-$(date +%s)"
make_page() { curl -s -X POST "https://demo.example.invalid/api/pages?t=$1" | cut -c1-36; }
snap_count() { psqlq "SELECT count(*) FROM demo_snapshots s JOIN demo_pages p ON p.id = s.page_id WHERE p.public_id = '$1'"; }
P=$(make_page home)
expect "stop 감사(mark 뒤)" "allow" "$(psqlq "SELECT result FROM audit_logs WHERE id > ${M:-0} AND action='act.stop' ORDER BY id DESC LIMIT 1")"
expect "시각 경계" "1" "$(psqlq "SELECT count(*) FROM audit_logs WHERE happened_at > '$T0'::timestamptz AND action='x'")"
expect "최근 10분" "yes" "$([ "$(psqlq "SELECT count(*) FROM demo_events WHERE at > now() - interval '10 minutes'")" -ge 1 ] && echo yes)"
expect "이번에 만든 페이지의 스냅샷" "1" "$(snap_count "$P")"
expect "이번에 만든 개체 id" "allow" "$(psqlq "SELECT result FROM audit_logs WHERE detail->>'page_id' = '$P' ORDER BY id DESC LIMIT 1")"
expect "실행마다 새 이름" "1" "$(psqlq "SELECT count(*) FROM audit_logs WHERE target_name = '$TAG-x'")"
n0=$(psqlq "SELECT count(*) FROM demo_events")
curl -s https://demo.example.invalid/api/ping >/dev/null
expect "이벤트 줄 수가 줄지 않았다" "yes" "$([ "$(psqlq "SELECT count(*) FROM demo_events")" -ge "$n0" ] && echo yes)"
BEFORE=$(psqlq "SELECT count(*) FROM audit_logs WHERE actor_name IS NULL")
AFTER=$(psqlq "SELECT count(*) FROM audit_logs WHERE actor_name IS NULL")
expect "시스템 행 그대로" "$BEFORE" "$AFTER"
CNT=$(psqlq "SELECT count(*) FROM audit_logs")
[ -z "$CNT" ] && note "psql 없음"
expect "감사 detail 에 비밀 키 0 (전역 불변식)" "0" "$(psqlq "SELECT count(*) FROM audit_logs WHERE detail ? 'password'")"
STOP_MARK=$(psqlq "SELECT coalesce(max(id),0) FROM audit_logs;")
expect "stop 감사(표시 뒤)" "allow" "$(psqlq "SELECT result FROM audit_logs WHERE action='act.stop' AND id > ${STOP_MARK:-0} ORDER BY id DESC LIMIT 1")"
