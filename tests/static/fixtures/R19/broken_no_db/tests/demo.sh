#!/usr/bin/env bash
# 견본: db/ 가 없는 트리 — 기록 표 사전을 만들 수 없어 검사 못 함(rc 2)
expect "x" "1" "$(psqlq "SELECT count(*) FROM audit_logs")"
