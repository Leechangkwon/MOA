#!/usr/bin/env bash
# 견본(v4 재검토 중간 1): 허용 목록 파이프 끝의 `grep -q .` 은 거부 줄이 아니다 — 앞 grep 이 `+++ b/` 줄만 남겨 rename 머리가 없다(1)
set -euo pipefail
PATCH="${1:?}"; APP=/srv/demo.example.invalid/app
if grep -E '^\+\+\+ b/' "$PATCH" | grep -vE '^\+\+\+ b/(docs/|tests/demo_[a-z]+\.sh$)' | grep -q .; then
  echo "허용 목록 밖 파일"; exit 1
fi
git -C "$APP" apply "$PATCH"
