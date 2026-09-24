#!/usr/bin/env bash
# 가짜 견본(HO-B1-007 부류) — 전후 비교 시험의 「전」 기본값이 범위 시작 판보다 앞선 판(C1) — 까닭이 적힌 고정이라 조용
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BEFORE_REF="${BEFORE_REF:-@C1@}"   # 일부러 고정 — 사용자가 겪은 옛 판을 재현
W=$(mktemp -d)
git -C "$ROOT" archive "$BEFORE_REF" | tar -C "$W" -xf -
diff -r "$W/src" "$ROOT/src" >/dev/null || echo "전후 다름"
