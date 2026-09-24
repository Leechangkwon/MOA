#!/bin/bash
# 가짜 견본: 이름으로 비밀인 변수를 명령 인자로 넘긴다
DEMO_PASSWORD=$(cat "$HOME/.demo-login")
curl -s -u "demo:$DEMO_PASSWORD" https://example.invalid/api/ping
