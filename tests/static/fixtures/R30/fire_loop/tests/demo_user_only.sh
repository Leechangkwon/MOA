#!/usr/bin/env bash
# 견본: -user 로 내 것만 — 같은 사람이 두 번 돌리면 서로 지운다
set -u
BASE="${TMPDIR:-/tmp}"
find "$BASE" -maxdepth 1 -type d -name 'demo-inst.*' -user "$(id -u)" -exec rm -rf {} +
I=$(mktemp -d "$BASE/demo-inst.XXXXXX")
printf '<?php echo 1;\n' > "$I/index.php"
php -l "$I/index.php"
