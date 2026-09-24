#!/usr/bin/env bash
# 견본: 실패 처리가 있는 조회 — 모두 조용
set -euo pipefail
U=demo-php
A_UID=$(id -u "$U") || { echo "계정 $U 이 없다 — 먼저 만든다"; exit 1; }
if ! B_GID=$(getent group demo-www | cut -d: -f3); then echo "그룹 없음"; exit 1; fi
id demo-cron >/dev/null 2>&1 || { echo "계정 demo-cron 이 없다"; exit 1; }
C_UID=$(id -u demo-cron)
getent passwd demo-mail >/dev/null || { echo "계정 demo-mail 없음"; exit 1; }
D_UID=$(id -u demo-mail)
look() { local x=$(id -u "$1"); echo "$x"; }
ME=$(id -u)
ME_NAME=$(id -un)
set +e
E_UID=$(id -u demo-maybe)
set -e
REAL=$(readlink -f /srv/demo.example.invalid/current)
F_UID=$(id -u demo-opt 2>/dev/null || true)
G_PID=$(pgrep -f demo-worker || echo none)
PHP=$(command -v php 2>/dev/null) || PHP=/usr/bin/php
H_UID=$(id -u demo-legacy)   # 없으면 멈추는 것이 맞다(설치 전제) — qcheck-allow: R28
echo "$A_UID $B_GID $C_UID $D_UID $ME $ME_NAME $E_UID $REAL $F_UID $G_PID $PHP $H_UID"
