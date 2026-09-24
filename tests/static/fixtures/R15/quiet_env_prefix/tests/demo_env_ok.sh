#!/bin/bash
# 가짜 견본(FP-0001): 줄 이어짐으로 나뉜 명령 앞 환경 대입 여러 개 — 비밀은 환경으로만 간다
WORK=$(mktemp -d)
SITE="https://demo.example.invalid"
DEMO_PW=$(demo-vault get demo)
SESSION=$(demo-login --session)
SMOKE_E1="$(cat "$WORK/demo_employee.session")" SMOKE_A1="$(cat "$WORK/demo_admin.session")" \
DEMO_PW="$DEMO_PW" PAGE="$WORK/page" LABEL="데모 게시판 $$" \
  python3 tools/demo_round.py "$SITE" check > "$WORK/round.txt" 2>&1
SMOKE_E1="$SESSION" OLD_DIR="$WORK/old" \
  python3 tools/demo_stale.py "$SITE" > "$WORK/stale.txt" 2>&1
SA="$SESSION" SB="$DEMO_PW" demo-tool --check
