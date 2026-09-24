#!/usr/bin/env bash
# 견본: set -e 없는 시험 — 빈 값으로 이어 간다(다른 문제)
U_ID=$(id -u demo-tester)
[ -n "$U_ID" ] || echo "계정 없음"
