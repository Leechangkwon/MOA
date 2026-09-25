#!/usr/bin/env bash
# 견본: 머리 줄을 세고, 바뀐 셸 목록을 bash -n 으로 돌기만 한다(허용 목록 아님)
set -eu
P="${1:?}"; APP=/srv/demo.example.invalid/app
echo "파일 $(grep -ac '^diff --git ' "$P")개"
git -C "$APP" apply "$P"
for f in $(grep -a '^diff --git ' "$P" | awk '{print $4}' | sed 's#^b/##' | grep '\.sh$'); do
  bash -n "$APP/$f" || echo "문법: $f"
done
