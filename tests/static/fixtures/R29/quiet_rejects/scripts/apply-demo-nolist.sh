#!/usr/bin/env bash
# 견본: 허용 목록 없이 반영만(다른 부류 — 이 규칙은 보지 않는다)
set -eu
P="${1:?}"; APP=/srv/demo.example.invalid/app
git -C "$APP" apply --check "$P" && echo "check ok"
git -C "$APP" apply "$P"
