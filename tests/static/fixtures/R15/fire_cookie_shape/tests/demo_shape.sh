#!/bin/bash
# 가짜 견본(묶음 X ④-b): 이름에 표시가 없어도 쿠키 모양으로 인자에 들어가는 값
BASE="https://demo.example.invalid"
OUT=$(demo-login --print)
V=$(printf '%s\n' "$OUT" | sed -n 's/^V=//p')
curl -s -b "demo_session=$V" "$BASE/api/me"
curl -s -H "Cookie: demo_session=$V" "$BASE/api/me"
curl -s --cookie "a=1; demo_session=$V" "$BASE/api/me"
H="Cookie: demo_session=$V"
wget -q --header "$H" "$BASE/api/me"
CK="demo_session=$V"
curl -s -b "$CK" "$BASE/api/me"
