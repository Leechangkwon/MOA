#!/usr/bin/env bash
# 가짜 견본(v3 ① ②) — 세션 해시 키(sha256(토큰) 을 쓴 .sid 파일을 읽는 sid_of · 세션 표의 id)를 sudo psql 인자로.
#   dbq 싱크: 출처 둘(:13 · :15) 모두 해시 키 → FINDING 한 줄, 한 단계 낮음(높음 → 중간)
#   dbw 싱크: 해시 키 + 진짜 토큰이 섞임 → 낮추지 않음(높음)
W=$(mktemp -d)
dbq() { sudo -n -u postgres psql -d demo_db -t -A -c "$1"; }
dbw() { sudo -n -u postgres psql -d demo_db -t -A -c "$1"; }
login() {
  python3 "$ROOT/tests/demo_login.py" < "$W/cred" | sha256sum | cut -c1-64 > "$W/$1.sid"
}
sid_of() { cat "$W/$1.sid"; }
login a
dbq "UPDATE sessions SET last_seen_at = now() WHERE id = '$(sid_of a)'"
SID_ADM=$(dbq "SELECT id FROM sessions WHERE user_id = 7 ORDER BY created_at DESC LIMIT 1")
dbq "UPDATE sessions SET reauth_at = now() WHERE id = '$SID_ADM'"
API_TOKEN=$(cat "$HOME/.config/demo/api.token")
dbw "UPDATE sessions SET note = '$API_TOKEN' WHERE id = '$SID_ADM'"
