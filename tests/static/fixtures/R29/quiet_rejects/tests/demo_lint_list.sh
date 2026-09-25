#!/usr/bin/env bash
# 견본: 반영 뒤 바뀐 PHP 를 php -l 로 돈다 — grep -v 로 거른 목록이지만 멈추는 허용 목록이 아니다
set -eu
P="${1:?}"; APP=/srv/demo.example.invalid/app
git -C "$APP" apply "$P"
for f in $(grep '^+++ b/' "$P" | sed 's#^+++ b/##' | grep -v '^docs/' | grep '\.php$'); do
  php -l "$APP/$f" >/dev/null || echo "문법: $f"
done
