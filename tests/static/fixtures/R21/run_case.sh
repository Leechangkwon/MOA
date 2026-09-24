#!/bin/bash
# R21 견본 실행기 — 견본 폴더의 c1/ 을 첫 커밋 C1 로, c2/ 를 덮어 둘째 커밋 C2(= 범위 시작 판)로, tip/ 을 덮어 셋째 커밋
# (= 후보)으로 만든 **임시 저장소**(TMPDIR 아래, 끝나면 지움)에서 r21_stale_default_ref.py 를 `--range C2..후보` 로 돌린다.
# c2/·tip/ 파일 안의 `@C1@`·`@C2@` 는 그 커밋의 짧은 해시로 바꾼다. tag_c1 파일이 있으면 C1 에 그 이름의 태그를 단다.
# 커밋 시각은 고정한다. 프로젝트 저장소에는 아무것도 쓰지 않는다. 쓰는 법: bash run_case.sh <견본 폴더>
set -u
case_dir=$(cd "$1" && pwd) || exit 2
rule="$(cd "$(dirname "$0")/../.." && pwd)/r21_stale_default_ref.py"
tmp=$(mktemp -d "${TMPDIR:-/tmp}/r21case.XXXXXX") || exit 2
trap 'rm -rf "$tmp"' EXIT
repo="$tmp/repo"; mkdir -p "$repo"
g() { git -C "$repo" -c user.name=fixture -c user.email=fixture@example.invalid -c commit.gpgsign=false -c tag.gpgsign=false "$@"; }
at() { GIT_AUTHOR_DATE="@$1 +0900" GIT_COMMITTER_DATE="@$1 +0900" g "${@:2}"; }
fill() {   # $1 덮을 폴더 — 자리표시를 해시로
  [ -d "$1" ] || return 0
  cp -R --no-preserve=timestamps "$1/." "$repo/"
  while IFS= read -r -d '' f; do
    sed -i -e "s/@C1@/${H1:-@C1@}/g" -e "s/@C2@/${H2:-@C2@}/g" "$f"
  done < <(find "$1" -type f -printf '%P\0' | sed -z "s#^#$repo/#")
}
g init -q
cp -a "$case_dir/c1/." "$repo/"
g add -A >/dev/null && at 1700000000 commit -qm c1 || exit 2
H1=$(g rev-parse --short=7 HEAD)
[ -f "$case_dir/tag_c1" ] && g tag "$(tr -d '\n' < "$case_dir/tag_c1")" HEAD
H2=""
fill "$case_dir/c2"
g add -A >/dev/null && at 1700000100 commit -qm c2 || exit 2
H2=$(g rev-parse --short=7 HEAD)
fill "$case_dir/tip"
g add -A >/dev/null && at 1700000200 commit -qm tip || exit 2
PYTHONDONTWRITEBYTECODE=1 python3 "$rule" --root "$repo" --range "$H2..HEAD"
