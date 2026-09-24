#!/usr/bin/env bash
# 가짜 견본(v3 ①) — 한 도우미(싱크)에 세 자리가 비밀을 넘긴다. FINDING 은 싱크 한 줄, 설명에 출처 셋이 다 있어야 한다.
BASE="https://example.invalid/demo"
dbq() { sudo -n -u postgres psql -d demo_db -t -A -c "$1"; }
API_TOKEN=$(cat "$HOME/.config/demo/api.token")
REFRESH_TOKEN="$(curl -s "$BASE/api/refresh" | jq -r .refresh_token)"
dbq "UPDATE demo_keys SET v = '$API_TOKEN' WHERE k = 1"
dbq "UPDATE demo_keys SET v = '$API_TOKEN' WHERE k = 2"
dbq "UPDATE demo_keys SET v = '$REFRESH_TOKEN' WHERE k = 3"
