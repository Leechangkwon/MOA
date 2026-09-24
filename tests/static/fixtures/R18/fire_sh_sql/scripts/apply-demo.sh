#!/usr/bin/env bash
# 견본: 반영 스크립트 모양 — 사전 확인 뒤 dry-run 이면 끝
set -euo pipefail
SRC="${1:?트리}"; DRY=0; [ "${2:-}" = "--dry-run" ] && DRY=1
q() { psql -X -At -d demo_db -c "$1"; }
echo "rows: $(q "SELECT count(*) FROM demo_items")"
q "UPDATE demo_items SET checked_at = now() WHERE id = 1"
if [ "$DRY" -eq 1 ]; then echo "== [dry] 여기까지"; exit 0; fi
q "DELETE FROM demo_items WHERE stale"
install -m 0644 "$SRC/demo.conf" /etc/demo/demo.conf
