#!/usr/bin/env bash
# 가짜 시험 — 인사말 확인
grep -q "안녕하세요" "$(dirname "$0")/../src/app.php"
