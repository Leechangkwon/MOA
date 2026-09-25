#!/usr/bin/env bash
# 견본: 머리 줄 목록을 변수에 받아 뒤에서 거른다
set -eu
P="${1:?}"
FILES=$(grep -a '^diff --git ' "$P" | awk '{print $4}' | sed 's#^b/##' | sort -u)
if printf '%s\n' "$FILES" | grep -qvE '^docs/'; then
  echo "docs 밖 파일이 있다"; exit 1
fi
git apply --index "$P"
