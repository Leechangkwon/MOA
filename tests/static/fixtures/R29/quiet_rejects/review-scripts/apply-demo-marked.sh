#!/usr/bin/env bash
# 견본: 받아들인 위험 표식(이름 바꾸기를 만들지 않는 생성기의 패치만 받는다)
set -eu
P="${1:?}"; APP=/srv/demo.example.invalid/app
# qcheck-allow: R29 — 패치는 git diff --no-renames 로만 만든다(생성기 쪽에서 막음)
OUT=$(grep -a '^diff --git ' "$P" | awk '{print $4}' | sed 's#^b/##' | grep -v '^docs/' || true)
[ -z "$OUT" ] || exit 1
git -C "$APP" apply "$P"
