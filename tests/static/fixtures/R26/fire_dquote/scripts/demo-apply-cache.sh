#!/usr/bin/env bash
# 견본: 반영 스크립트가 새 설정 파일에 map 줄이 들어갔는지 확인한다(가짜 경로)
set -uo pipefail
CONF=/srv/demo.example.invalid/conf.d/demo-common.conf
# ✖ 큰따옴표 — 셸이 $arg_ver · $demo_expires 를 먼저 펼친다
if grep -q "map $arg_ver $demo_expires" "$CONF"; then
  echo "새 판"
fi
