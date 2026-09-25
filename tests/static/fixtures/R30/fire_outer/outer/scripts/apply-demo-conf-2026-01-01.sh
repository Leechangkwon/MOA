#!/usr/bin/env bash
# 견본: 바깥 반영 스크립트 — 설정 폴더의 옛 임시 파일을 xargs rm 으로 치우고 새 임시 파일을 원본으로 옮긴다
set -euo pipefail
CONF=/srv/demo.example.invalid/conf
find "$CONF" -maxdepth 1 -name '.demo-*' -print0 | xargs -0 -r rm -f
T=$(mktemp "$CONF/.demo-XXXXXX")
sed 's/^limit=.*/limit=10/' "$CONF/app.conf" > "$T"
mv "$T" "$CONF/app.conf"
