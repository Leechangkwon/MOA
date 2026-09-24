#!/bin/bash
# 가짜 견본(묶음 X ④-a): 이름이 세션·쿠키를 가리키는 값을 명령 인자로 넘긴다
BASE="https://demo.example.invalid"
JAR_DIR=$(mktemp -d)
cookie_of() { awk -v n=demo_session '$6==n{print $7}' "$JAR_DIR/$1.jar"; }
SESSION_VALUE=$(sed -n 's/^SESSION=//p' "$JAR_DIR/login.out")
python3 tools/demo_browser.py "$BASE" "$SESSION_VALUE"
S1=$(cookie_of demo_user)
node tools/demo_smoke.js "$BASE" "$S1"
