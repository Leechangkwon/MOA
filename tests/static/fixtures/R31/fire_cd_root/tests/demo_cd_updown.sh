#!/usr/bin/env bash
# 견본(v4 검토 중간 2 대조): src 로 갔다가 상대 경로 `cd ..` 로 뿌리에 돌아와 `.` 을 셈(1)
set -u
cd "$(dirname "$0")/../src" || exit 2
cd .. || exit 2
c=$(grep -rc 'demo-flag' . | awk -F: '{s+=$2} END{print s+0}')
[ "$c" = 2 ] || exit 1
