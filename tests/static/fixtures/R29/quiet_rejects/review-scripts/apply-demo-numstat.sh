#!/usr/bin/env bash
# 견본: 허용 목록을 git apply --numstat 로 뽑는다(이름 바꾸기 대상도 보인다 — 이 규칙의 부류 아님)
set -eu
P="${1:?}"; APP=/srv/demo.example.invalid/app
OUT=$(git -C "$APP" apply --numstat "$P" | awk '{print $3}' | grep -v '^docs/' || true)
[ -z "$OUT" ] || exit 1
git -C "$APP" apply "$P"
