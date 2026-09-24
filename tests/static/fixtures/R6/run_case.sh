#!/bin/bash
# R6 견본 실행기 — 견본 폴더의 base/ 를 첫 커밋으로, tip/ 을 덮고 delete.txt 의 파일을 지운 상태를 둘째 커밋(또는 작업 트리)으로
# 만든 **임시 저장소**(TMPDIR 아래, 끝나면 지움)에서 r6_commit_scope.py 를 돌린다. 프로젝트 저장소에는 아무것도 쓰지 않는다.
# 쓰는 법: bash tests/static/fixtures/R6/run_case.sh tests/static/fixtures/R6/<견본 폴더>
#   mode 파일(없으면 commit): commit = 둘째 커밋 하나를 범위로(C^..C) · worktree = 커밋하지 않고 HEAD.. · badrange = 없는 범위
#   directive.md 가 있으면 --directive 로 넘긴다.  rc·출력은 규칙 그대로.
set -u
case_dir=$(cd "$1" && pwd) || exit 2
rule="$(cd "$(dirname "$0")/../.." && pwd)/r6_commit_scope.py"
tmp=$(mktemp -d "${TMPDIR:-/tmp}/r6case.XXXXXX") || exit 2
trap 'rm -rf "$tmp"' EXIT
repo="$tmp/repo"; mkdir -p "$repo"
g() { git -C "$repo" -c user.name=fixture -c user.email=fixture@example.invalid -c commit.gpgsign=false "$@"; }
g init -q
cp -a "$case_dir/base/." "$repo/"
g add -A >/dev/null && g commit -qm base || exit 2
# tip 은 시각을 보존하지 않고 복사한다(통합 단계 보정). cp -a 로 시각을 보존하면, base 와 크기가 같고
# 같은 초에 만든 tip 파일을 git 이 stat 만 보고 「안 바뀜」으로 여겨 둘째 커밋에서 빠진다
# (broken_directive 는 「nothing to commit」으로 rc 2 가 났고, fire_unfinished 는 4개 중 3개만 바뀌었다).
[ -d "$case_dir/tip" ] && cp -R --no-preserve=timestamps "$case_dir/tip/." "$repo/"
if [ -f "$case_dir/delete.txt" ]; then
  while IFS= read -r p; do [ -n "$p" ] && rm -f "$repo/$p"; done < "$case_dir/delete.txt"
fi
mode=commit; [ -f "$case_dir/mode" ] && mode=$(tr -d ' \n' < "$case_dir/mode")
case "$mode" in
  commit)   g add -A >/dev/null && g commit -qm tip || exit 2; range=HEAD ;;
  worktree) range=HEAD.. ;;
  badrange) range=no-such-ref..HEAD ;;
  *) echo "모르는 mode: $mode" >&2; exit 2 ;;
esac
# (v3 묶음 K) 바깥 저장소 견본: outer_base/ 를 뿌리 범위 시작 커밋보다 앞선 시각에, outer_tip/ 을 뒤 시각에 커밋한
# 임시 바깥 저장소를 만들어 --outer 로 넘긴다(K4 바깥 판정). 없으면 전처럼 --outer none. frag 파일이 있으면 지시서#절.
outer=none
if [ -d "$case_dir/outer_base" ]; then
  outer="$tmp/outer"; mkdir -p "$outer"
  og() { git -C "$outer" -c user.name=fixture -c user.email=fixture@example.invalid -c commit.gpgsign=false "$@"; }
  bt=$(g log -1 --format=%ct "$(g rev-list --max-parents=0 HEAD | tail -1)")
  og init -q
  cp -a "$case_dir/outer_base/." "$outer/"
  og add -A >/dev/null && GIT_AUTHOR_DATE="@$((bt - 1000)) +0900" GIT_COMMITTER_DATE="@$((bt - 1000)) +0900" og commit -qm base || exit 2
  if [ -d "$case_dir/outer_tip" ]; then
    cp -R --no-preserve=timestamps "$case_dir/outer_tip/." "$outer/"
    og add -A >/dev/null && GIT_AUTHOR_DATE="@$((bt + 1000)) +0900" GIT_COMMITTER_DATE="@$((bt + 1000)) +0900" og commit -qm tip || exit 2
  fi
fi
args=(--root "$repo" --range "$range" --outer "$outer")
dfrag=""; [ -f "$case_dir/frag" ] && dfrag="#$(tr -d '\n' < "$case_dir/frag")"
[ -f "$case_dir/directive.md" ] && args+=(--directive "$case_dir/directive.md$dfrag")
PYTHONDONTWRITEBYTECODE=1 python3 "$rule" "${args[@]}"
