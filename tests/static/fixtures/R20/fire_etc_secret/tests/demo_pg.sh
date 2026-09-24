#!/usr/bin/env bash
# 견본: pgpass 를 /tmp 고정 경로로
mkdir -p /tmp/demo-pg-check
install -m 600 "$HOME/.pgpass" /tmp/demo-pg-check/pgpass
