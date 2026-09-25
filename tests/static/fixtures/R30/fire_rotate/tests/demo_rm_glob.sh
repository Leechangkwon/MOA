#!/usr/bin/env bash
# 견본: 시작할 때 옛 캐시를 글롭으로 지우고 같은 접두 임시 파일을 뒤에서 읽는다
set -u
rm -f "${TMPDIR:-/tmp}"/demo-cache.*
C=$(mktemp -t demo-cache.XXXXXX)
printf 'b\na\n' > "$C"
sort -u "$C" | head -n 1
