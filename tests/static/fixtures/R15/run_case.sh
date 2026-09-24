#!/bin/bash
# R15 견본 실행기(v3 묶음 K) — 견본 폴더 안에 outer/ 가 있으면 그것을 바깥 배치로 `--outer` 에 넘긴다(반영 스크립트 입력 ⑤).
# 없으면 selftest.sh 의 기본 부르기와 같다: python3 -B r15_secret_args.py --root <견본>. 파일은 읽기만 한다.
set -u
case_dir=$(cd "$1" && pwd) || exit 2
rule="$(cd "$(dirname "$0")/../.." && pwd)/r15_secret_args.py"
args=(--root "$case_dir")
[ -d "$case_dir/outer" ] && args+=(--outer "$case_dir/outer")
PYTHONDONTWRITEBYTECODE=1 python3 -B "$rule" "${args[@]}"
