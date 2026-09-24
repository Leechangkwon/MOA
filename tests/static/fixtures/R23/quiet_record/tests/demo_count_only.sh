#!/usr/bin/env bash
# 견본: 기록기는 있지만 이 셸이 요청을 보내지 않는다
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
: > "$WORK/hits.log"
DEMO_LOG="$WORK/hits.log" python3 "$WORK/server.py" &
python3 "$WORK/client.py"
n=$(wc -l < "$WORK/hits.log")
[ "$n" -ge 1 ] && echo "받음" || echo "못 받음"
