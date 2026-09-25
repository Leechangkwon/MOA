#!/usr/bin/env bash
# 견본: 목록 검사 뒤에 rename/copy 거부(링크 거부는 없음 — 경로는 목록 안이라 이 규칙은 조용)
set -eu
P="${1:?}"; APP=/srv/demo.example.invalid/app
BAD=$(grep -a '^diff --git ' "$P" | awk '{print $3; print $4}' | sed 's#^[ab]/##' | grep -vE '^tests/' || true)
[ -z "$BAD" ] || exit 1
grep -aqE '^(rename|copy) (from|to) |^similarity index ' "$P" && { echo "이름 바꾸기 — 멈춘다"; exit 1; }
git -C "$APP" apply "$P"
