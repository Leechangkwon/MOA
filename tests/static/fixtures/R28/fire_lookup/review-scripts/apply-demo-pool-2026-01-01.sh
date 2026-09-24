#!/usr/bin/env bash
# 견본: 풀 설정 반영 스크립트(가짜) — 계정·그룹·링크를 찾아 대입한다
set -euo pipefail
POOL_USER=demo-php
POOL_GROUP=demo-www
LINK=/srv/demo.example.invalid/current
POOL_UID=$(id -u "$POOL_USER")
POOL_GID=$(getent group "$POOL_GROUP" | cut -d: -f3)
REAL=$(readlink -e "$LINK")
echo "$POOL_UID $POOL_GID $REAL"
