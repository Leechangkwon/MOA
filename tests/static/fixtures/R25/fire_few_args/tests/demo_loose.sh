#!/usr/bin/env bash
# 견본: set -u 없이 모자라게 부름 — 빈 값으로 돈다
label() { printf '%s — %s\n' "$1" "$2"; }
label "하나만"
