#!/usr/bin/env bash
# 가짜 견본(FP-0005 모양) — dry-run 참 갈래가 같은 갈래의 mktemp 사본을 만들고 그 아래만 고친 뒤(링크 돌리기 포함)
# 사본으로 설정 검사를 돌리고 끝난다. /etc 에는 쓰지 않는다 → 조용해야 한다.
set -eu
DRY=0
[ "${1:-}" = "--dry-run" ] && DRY=1
SRC="$(cd "$(dirname "$0")/.." && pwd)/deploy"
if [ "$DRY" -eq 1 ]; then
  T=$(mktemp -d); trap 'rm -rf "$T"' EXIT
  cp -a /etc/demo "$T/demo"
  cp "$SRC/site.conf" "$T/demo/sites-available/site"
  sed -i -e "s#/etc/demo/#$T/demo/#g" "$T/demo/demo.conf"
  for l in "$T"/demo/sites-enabled/*; do
    [ -L "$l" ] || continue
    ln -sfn "$T/demo/sites-available/site" "$l"
  done
  demo-check -c "$T/demo/demo.conf"
  exit 0
fi
cp "$SRC/site.conf" /etc/demo/sites-available/site
