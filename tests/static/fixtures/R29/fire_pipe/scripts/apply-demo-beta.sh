#!/usr/bin/env bash
# 견본: +++ 줄로 허용 목록 — 100% 같은 이름 바꾸기에는 +++ 줄이 없다 · 감싸는 함수로 반영
set -eu
P="$1"; APP=/srv/demo.example.invalid/app
asowner() { sudo -n -u demo-owner "$@"; }
BAD=$(grep '^+++ b/' "$P" | sed 's#^+++ b/##' | grep -v '^tests/' || true)
[ -z "$BAD" ] || { echo "시험 밖 파일"; exit 1; }
asowner git -C "$APP" apply "$P"
