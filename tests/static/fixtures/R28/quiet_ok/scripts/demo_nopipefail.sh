#!/usr/bin/env bash
# 견본: pipefail 없이 파이프 앞자리의 조회(실패가 대입까지 오지 않는다) · 다른 명령
set -e
N=$(id -u demo-x | tr -d ' ')
S=$(sha256sum /srv/demo.example.invalid/f | cut -d' ' -f1)
echo "$N $S"
