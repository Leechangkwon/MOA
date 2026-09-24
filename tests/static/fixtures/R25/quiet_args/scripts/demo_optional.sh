#!/usr/bin/env bash
# 견본: 고르는 인자 · 인자 수를 스스로 다루는 함수(조용)
set -euo pipefail
mk() { local body="{\"name\":\"$1\""; [ -n "${2:-}" ] && body="$body,\"parent\":$2"; printf '%s}' "$body"; }
mk demo
mk demo 7
opt() { printf '%s %s\n' "$1" "${2:-기본}"; }
opt 하나
plus() { echo "$1${2:+ (부모 $2)}"; }
plus 하나
count_args() { [ $# -ge 2 ] || { echo "인자 둘"; return 1; }; echo "$1 $2"; }
count_args 하나 || true
eat() { local first=$1; shift; echo "$first $1"; }
eat a b
relay() { need3 "$@"; }
need3() { echo "$1 $2 $3"; }
relay a b c
need3 x y   # 일부러 짧게 — qcheck-allow: R25
