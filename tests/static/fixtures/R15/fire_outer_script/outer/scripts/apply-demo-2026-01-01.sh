#!/usr/bin/env bash
# 가짜 견본(v3 ⑤) — 바깥 저장소 반영 스크립트. 비밀을 명령 인자로(울림 1) · 비밀 파일의 **자리**는 조용.
set -eu
CONF=/etc/demo-app
TOTP="$CONF/demo-totp.txt"
stat -c '%U:%G %a' "$TOTP"
install -m 0600 "$TOTP" "$TOTP.bak"
DB_PASSWORD=$(cat "$CONF/db.pass")
mysql -u demo -p"$DB_PASSWORD" -e 'SELECT 1'
