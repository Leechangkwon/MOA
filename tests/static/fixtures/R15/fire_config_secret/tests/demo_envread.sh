#!/usr/bin/env bash
# 가짜 견본(v3 ④ 대조) — sed 로 뽑는 값이 숫자가 아니라 비밀 글자(session_secret=…)면 여전히 울린다.
ENVF="$ROOT/.env.demo"
pgq() { sudo -n -u postgres psql -X -d postgres -t -A -c "$1"; }
SEC_V=$(sed -n 's/^session_secret=\(.*\)$/\1/p' "$ENVF")
pgq "SELECT set_config('demo.k', '$SEC_V', false)"
