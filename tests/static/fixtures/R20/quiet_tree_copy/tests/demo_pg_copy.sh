#!/usr/bin/env bash
# 견본: 시험 폴더 안(BASH_SOURCE 기준)에 작업 폴더를 만들어 pgpass 사본을 둔다 — 뿌리 .gitignore 의 폴더 무늬에 걸린다
set -u
HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)
rm -rf "$HERE"/.work.*
W=$(mktemp -d "$HERE/.work.XXXXXX")
cp "$HOME/.pgpass" "$W/pgpass"
PGPASSFILE="$W/pgpass" psql -X -d demo_test -c 'SELECT 1' >/dev/null
