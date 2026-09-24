#!/usr/bin/env bash
# 가짜 견본 — 이미 있던 시험(범위 밖에서 들어옴)의 기본값. 이번 범위는 다른 줄만 고친다 — 조용
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BEFORE_REF="${BEFORE_REF:-@C1@}"
git -C "$ROOT" archive "$BEFORE_REF" > /dev/null
echo "첫 판"
