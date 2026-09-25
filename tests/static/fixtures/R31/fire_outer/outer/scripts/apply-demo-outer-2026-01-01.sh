#!/usr/bin/env bash
# 견본: 바깥 반영 스크립트 — tests/ 전체에서 도우미를 부르는 파일 수를 센다
set -euo pipefail
APP=/srv/demo.example.invalid/app
got=$(grep -rl 'demo_marker(' "$APP/tests" | wc -l)
[ "$got" -eq 7 ] || { echo "도우미 파일 수 $got"; exit 1; }
