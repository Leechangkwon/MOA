#!/usr/bin/env bash
# 견본: rename 거부 줄이 반영 뒤에 있다(이미 늦다)
set -eu
P="${1:?}"; APP=/srv/demo.example.invalid/app
X=$(grep -a '^diff --git ' "$P" | awk '{print $4}' | sed 's#^b/##' | grep -v '^docs/' || true)
[ -z "$X" ] || exit 1
git -C "$APP" apply "$P"
if grep -aqE '^rename (from|to) ' "$P"; then echo "이름 바꾸기가 있었다 — 되돌린다"; git -C "$APP" apply -R "$P"; exit 1; fi
