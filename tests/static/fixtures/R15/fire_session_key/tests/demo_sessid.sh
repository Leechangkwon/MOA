#!/usr/bin/env bash
# 가짜 견본(v3 ③) — session_id 칸은 식별자 예외가 아니다(v2 는 *_id 로 보고 뺐다). 둘 다 울려야 한다.
BASE="https://example.invalid/demo"
SID=$(curl -s -X POST "$BASE/api/login" --data-binary @"$WORK/login.json" | jq -r .session_id)
python3 "$ROOT/tests/browser/demo_page.py" "$SID"
SESSION=$(curl -s "$BASE/api/login" | python3 -c 'import json,sys; print(json.load(sys.stdin).get("session_id"))')
python3 "$ROOT/tests/browser/demo_other.py" "$SESSION"
# 대조: 사용자 id 칸은 식별자 — 조용
UID_V=$(curl -s "$BASE/api/me" | jq -r .user_id)
python3 "$ROOT/tests/browser/demo_me.py" "$UID_V"
