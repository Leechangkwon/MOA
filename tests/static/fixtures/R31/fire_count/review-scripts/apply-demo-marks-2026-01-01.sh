#!/usr/bin/env bash
# 견본: 반영 뒤 표식 수를 tests/ 전체에서 센다 — 봇 규칙·견본(tests/static)의 같은 글자도 함께 센다
set -euo pipefail
PATCH="${1:?}"; APP=/srv/demo.example.invalid/app
ok=1
chk() { if [ "$2" = "$3" ]; then echo "ok $1"; else echo "틀림 $1 (기대 $2 · 실제 $3)"; ok=0; fi; }
git -C "$APP" apply "$PATCH"
n=$(grep -r 'demo-mark: X1' "$APP/tests" | wc -l)
chk "표식 수" "5" "$n"
[ "$ok" -eq 1 ] || { git -C "$APP" apply -R "$PATCH"; exit 4; }
