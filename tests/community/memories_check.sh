#!/usr/bin/env bash
# Local-only source/UI verification. Does not contact production or change a database.
set -euo pipefail
cd "$(dirname "$0")/../.."
node --check public/assets/community/memories.js
node --check public/assets/community/editor.js
node --check public/assets/messenger.js
node --check public/assets/app.js
bash scripts/security_lint.sh
if ! command -v php >/dev/null 2>&1; then
  printf 'UNVERIFIED: PHP is not installed. PHP syntax, backend unit, render, asset-version and wiki checks cannot run.\n' >&2
  exit 2
fi
for path in src/community/Memories.php src/community/MemoryAi.php src/community/MemoryStory.php src/MemberAi.php src/community/Messenger.php src/community/handlers/messenger_api.php src/community/handlers/memories_*.php src/community/routes.php src/Http.php src/Audit.php src/Fetch.php src/views/shell_top.php src/views/shell_bottom.php src/views/messenger_window.php scripts/memories_cleanup.php scripts/consistency_check.php tests/community/memories_unit.php tests/community/memory_ai_unit.php tests/community/memories_render.php; do
  php -l "$path"
done
php tests/community/memories_unit.php
php tests/community/memory_ai_unit.php
php tests/community/memory_story_unit.php
php tests/community/memories_render.php >/dev/null
php tests/community/memories_render.php shared >/dev/null
php scripts/community_asset_version.php --check
php scripts/wiki_check.php
python3 tests/community/memories_browser.py
python3 tests/community/memories_social_browser.py
python3 tests/community/memories_attachments_browser.py
python3 tests/community/memory_ai_browser.py
# 떠 있는 메신저 창(사이트 Q:F-11 — 옛 커뮤니티 대화 창 messenger_browser.py 를 대체)은 실제 PHP·PostgreSQL 이 필요해 여기서 돌지 않는다.
# 격리 틀로 따로: python3 tests/community/memories_harness.py --suite messenger_window_browser.py --output <결과 폴더>
