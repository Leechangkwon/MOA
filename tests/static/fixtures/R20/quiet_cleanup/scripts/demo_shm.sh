#!/usr/bin/env bash
# 견본: tmpfs(0700) 위 — 재부팅이 지운다는 받아들인 위험을 표식으로
WORK="$(mktemp -d -p /dev/shm demo.XXXXXX)"   # 메모리 · 0700 — 받아들인 위험 qcheck-allow: R20
trap 'rm -rf "$WORK"' EXIT
cp /etc/tbox-v2/demo-totp.txt "$WORK/t"
