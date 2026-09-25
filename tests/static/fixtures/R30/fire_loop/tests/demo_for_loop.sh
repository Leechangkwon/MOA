#!/usr/bin/env bash
# 견본: 옛 작업 폴더를 for 글롭으로 지운다(주인 확인 없음) · 새 작업 폴더 안 스크립트를 돌린다
set -u
for old in /tmp/demo-work.*; do
  rm -rf -- "$old"
done
W=$(mktemp -d /tmp/demo-work.XXXXXX)
printf 'echo hi\n' > "$W/run.sh"
bash "$W/run.sh"
