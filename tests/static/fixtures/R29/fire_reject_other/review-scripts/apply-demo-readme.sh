#!/usr/bin/env bash
# 견본(v4 검토 중간 3): 「rename」 글자가 든 grep 이 반영 앞 조건에 있지만 패치가 아닌 README.md 를 본다 — 거부 줄이 아니다(1)
set -euo pipefail
PATCH="${1:?}"; APP=/srv/demo.example.invalid/app
if grep -q 'rename' README.md; then echo "README 에 이름 바꾸기 안내가 있다"; fi
OUT=$(grep -a '^diff --git ' "$PATCH" | awk '{print $3; print $4}' | sed 's#^[ab]/##' | sort -u | grep -v '^docs/' || true)
[ -z "$OUT" ] || exit 1
git -C "$APP" apply "$PATCH"
