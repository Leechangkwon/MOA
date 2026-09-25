#!/bin/bash
# R30 견본 실행기 — 견본 폴더를 --root 로 넘기고, 그 안에 outer/ 가 있으면 --outer 로도 넘긴다
# (바깥 저장소 scripts/*.sh = 반영 스크립트 견본). 프로젝트 저장소에는 아무것도 쓰지 않는다.
# 쓰는 법: bash tests/static/fixtures/R30/run_case.sh <견본 폴더>   (selftest.sh 가 견본마다 부른다)
set -u
case_dir=$(cd "$1" && pwd) || exit 2
rule="$(cd "$(dirname "$0")/../.." && pwd)/r30_cleanup_race.py"
args=(--root "$case_dir")
[ -d "$case_dir/outer" ] && args+=(--outer "$case_dir/outer")
PYTHONDONTWRITEBYTECODE=1 exec python3 -B "$rule" "${args[@]}"
