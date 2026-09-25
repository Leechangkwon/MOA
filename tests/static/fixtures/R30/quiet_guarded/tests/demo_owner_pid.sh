#!/usr/bin/env bash
# 견본: 주인(owner.pid)의 프로세스가 죽은 옛 폴더만 지운다
set -u
B="${TMPDIR:-/tmp}"
while IFS= read -r -d '' old; do
  pid=$(head -c 16 "$old/owner.pid" 2>/dev/null | tr -cd '0-9')
  [ -n "$pid" ] || continue
  kill -0 "$pid" 2>/dev/null && continue
  rm -rf -- "$old"
done < <(find "$B" -maxdepth 1 -type d -name 'demo-own.*' -print0)
W=$(mktemp -d "$B/demo-own.XXXXXX")
echo "$$" > "$W/owner.pid"
printf 'echo ok\n' > "$W/x.sh"
bash "$W/x.sh"
