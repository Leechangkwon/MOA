#!/bin/bash
# R22 견본 실행기 — 견본 폴더의 base/ 를 첫 커밋, tip/ 을 덮고 delete.txt 의 파일을 지운 상태를 둘째 커밋으로 만든 **임시
# 저장소**(TMPDIR 아래, 끝나면 지움)에서 r22_stale_comment.py 를 `--range HEAD^..HEAD` 로 돌린다. 프로젝트 저장소에는
# 아무것도 쓰지 않는다. 쓰는 법: bash run_case.sh <견본 폴더>
set -u
case_dir=$(cd "$1" && pwd) || exit 2
rule="$(cd "$(dirname "$0")/../.." && pwd)/r22_stale_comment.py"
tmp=$(mktemp -d "${TMPDIR:-/tmp}/r22case.XXXXXX") || exit 2
trap 'rm -rf "$tmp"' EXIT
repo="$tmp/repo"; mkdir -p "$repo"
g() { git -C "$repo" -c user.name=fixture -c user.email=fixture@example.invalid -c commit.gpgsign=false "$@"; }
g init -q
cp -a "$case_dir/base/." "$repo/"
g add -A >/dev/null && g commit -qm base || exit 2
[ -d "$case_dir/tip" ] && cp -R --no-preserve=timestamps "$case_dir/tip/." "$repo/"
if [ -f "$case_dir/delete.txt" ]; then
  while IFS= read -r p; do
    case "$p" in ''|/*|*..*) continue ;; esac
    rm -f "$repo/$p"
  done < "$case_dir/delete.txt"
fi
g add -A >/dev/null && g commit -qm tip || exit 2
PYTHONDONTWRITEBYTECODE=1 python3 "$rule" --root "$repo" --range HEAD^..HEAD
