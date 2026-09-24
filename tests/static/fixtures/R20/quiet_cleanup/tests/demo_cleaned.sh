#!/usr/bin/env bash
# 견본: kill -9 뒤에도 다음 실행이 옛 임시 폴더를 치운다 — 조용
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
TMP_BASE="${TMPDIR:-/tmp}"
while IFS= read -r -d '' old; do
  opid=$(cat "$old/owner.pid" 2>/dev/null || true)
  [ -n "$opid" ] && kill -0 "$opid" 2>/dev/null && continue
  rm -rf -- "$old"
done < <(find "$TMP_BASE" -maxdepth 1 -type d -name 'demo-inst.*' -user "$(id -u)" -print0 2>/dev/null)
WORK="$(mktemp -d "$TMP_BASE/demo-inst.XXXXXXXX")"
echo "$$" > "$WORK/owner.pid"
trap 'rm -rf "$WORK"' EXIT
sed -e 's#^DB_NAME=.*#DB_NAME=demo_test#' "$ROOT/.env" > "$WORK/.env"
