#!/usr/bin/env bash
# tests/static/selftest.sh — 정적 규칙 자체 시험 (점검 봇 1-F 완료 기준 F1, docs/checkbot/stage1-directive.md)
#
# 규칙마다 tests/static/fixtures/<규칙>/expect.txt 의 「<견본> rc=<n> findings=<n>」 줄대로 규칙을 돌려
#   ① 종료 코드 ② FINDING 줄 수 ③ 출력 규약(마지막 RESULT 줄이 있고 fail= 이 FINDING 줄 수와 같음)을 확인한다.
# 규칙 파일마다 py_compile 도 한 항목으로 센다(바이트코드는 임시 폴더에만 쓴다).
# 끝에 합계 한 줄과 `RESULT pass= fail= skip=`. 하나라도 어긋나면 rc 1, 준비를 못 하면 rc 2.
#
#   bash tests/static/selftest.sh            견본 폴더가 있는 규칙 전부
#   bash tests/static/selftest.sh R1 R6      고른 규칙만
#
# · 규칙 파일은 tests/static/<규칙 id 소문자>_*.py 하나(예: R15 → r15_secret_args.py).
# · 견본은 임시 폴더(${TMPDIR:-/tmp} 아래, 끝나면 지움)에 규칙 폴더째 복사해서 돌린다. 이때 파일 이름 끝의
#   `.in` 을 뗀다 — 일부러 금지 패턴을 담은 견본 PHP(R15·R16)가 저장소 안에서 scripts/security_lint.sh 에
#   걸리지 않게 하려는 것이다. 규칙 폴더째 복사하므로 <root>/.. 를 바깥 배치로 읽는 견본(R5·R11)도 그대로다.
# · 견본 폴더에 run_case.sh 가 있으면(R6 — 커밋 범위가 입력) `bash <원래 run_case.sh> <복사한 견본>` 으로 돌린다.
# · 저장소에는 아무것도 쓰지 않는다(PYTHONDONTWRITEBYTECODE=1 — .gitignore 에 __pycache__ 가 없다).
set -u
export PYTHONDONTWRITEBYTECODE=1

HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P) || exit 2
FIX="$HERE/fixtures"
[ -d "$FIX" ] || { echo "selftest: 견본 폴더가 없다: $FIX" >&2; echo "RESULT pass=0 fail=0 skip=1"; exit 2; }
command -v python3 >/dev/null 2>&1 || { echo "selftest: python3 가 없다" >&2; echo "RESULT pass=0 fail=0 skip=1"; exit 2; }

tmp=$(mktemp -d "${TMPDIR:-/tmp}/qstatic-selftest.XXXXXX") || { echo "RESULT pass=0 fail=0 skip=1"; exit 2; }
trap 'rm -rf "$tmp"' EXIT

if [ "$#" -gt 0 ]; then
  rules=("$@")
else
  rules=()
  for d in "$FIX"/*/; do
    [ -f "$d/expect.txt" ] && rules+=("$(basename "$d")")
  done
  # R1 R2 … R16 순서(숫자 순)
  mapfile -t rules < <(printf '%s\n' "${rules[@]}" | sort -t R -k 2 -n)
fi
[ "${#rules[@]}" -gt 0 ] || { echo "selftest: expect.txt 가 있는 견본 폴더가 없다" >&2; echo "RESULT pass=0 fail=0 skip=1"; exit 2; }

pass=0; fail=0; skip=0; nrules=0; ncases=0

bad() {  # $1 항목  $2 설명  $3 출력(있으면 끝 12줄을 들여 붙인다)
  echo "FAIL $1 — $2"
  [ -n "${3:-}" ] && printf '%s\n' "$3" | tail -n 12 | sed 's/^/      | /'
  fail=$((fail + 1))
}

for R in "${rules[@]}"; do
  dir="$FIX/$R"
  if [ ! -f "$dir/expect.txt" ]; then
    bad "$R" "견본 폴더나 expect.txt 가 없다: $dir"
    continue
  fi
  r=$(printf '%s' "$R" | tr 'A-Z' 'a-z')
  mapfile -t cand < <(ls -1 "$HERE/${r}"_*.py 2>/dev/null)
  if [ "${#cand[@]}" -ne 1 ]; then
    bad "$R" "규칙 파일 tests/static/${r}_*.py 가 하나가 아니다(${#cand[@]}개)"
    continue
  fi
  rule="${cand[0]}"
  nrules=$((nrules + 1))

  # 문법(바이트코드는 임시 폴더로)
  if out=$(python3 -c 'import py_compile,sys; py_compile.compile(sys.argv[1], cfile=sys.argv[2], doraise=True)' \
           "$rule" "$tmp/$r.pyc" 2>&1); then
    echo "PASS $R py_compile $(basename "$rule")"
    pass=$((pass + 1))
  else
    bad "$R py_compile" "$(basename "$rule") 문법 오류" "$out"
  fi

  # 견본을 규칙 폴더째 임시 폴더에 풀기(.in 떼기)
  work="$tmp/fx/$R"
  mkdir -p "$tmp/fx" && cp -a "$dir" "$work" || { bad "$R" "견본을 임시 폴더에 복사하지 못함"; continue; }
  while IFS= read -r -d '' f; do
    mv -n -- "$f" "${f%.in}" || bad "$R" "견본 이름 바꾸기 실패: ${f#$work/}"
  done < <(find "$work" -type f -name '*.in' -print0)

  while IFS= read -r line || [ -n "$line" ]; do
    case "$line" in ''|'#'*) continue ;; esac
    read -r cname rcx fx extra <<<"$line"
    erc=${rcx#rc=}; ef=${fx#findings=}
    if [ "$rcx" = "$erc" ] || [ "$fx" = "$ef" ] || [ -n "${extra:-}" ] || \
       ! [[ "$erc" =~ ^[0-9]+$ && "$ef" =~ ^[0-9]+$ ]]; then
      bad "$R" "expect.txt 줄 모양이 아니다: $line"
      continue
    fi
    ncases=$((ncases + 1))
    cdir="$work/$cname"
    if [ ! -d "$cdir" ]; then
      bad "$R $cname" "견본 폴더가 없다"
      continue
    fi
    if [ -f "$dir/run_case.sh" ]; then
      out=$(bash "$dir/run_case.sh" "$cdir" 2>&1); rc=$?
    else
      out=$(python3 -B "$rule" --root "$cdir" 2>&1); rc=$?
    fi
    nf=$(printf '%s\n' "$out" | grep -c '^FINDING ')
    resl=$(printf '%s\n' "$out" | grep -E '^RESULT pass=[0-9]+ fail=[0-9]+ skip=[0-9]+[[:space:]]*$' | tail -n 1)
    why=""
    [ "$rc" = "$erc" ] || why="${why}rc 기대 $erc · 실제 $rc; "
    [ "$nf" = "$ef" ] || why="${why}FINDING 기대 $ef · 실제 $nf; "
    if [ -z "$resl" ]; then
      why="${why}RESULT 줄 없음; "
    else
      rf=$(printf '%s' "$resl" | sed -E 's/.*fail=([0-9]+).*/\1/')
      [ "$rf" = "$nf" ] || why="${why}RESULT fail=$rf 가 FINDING 줄 수 $nf 와 다름; "
    fi
    if [ -z "$why" ]; then
      echo "PASS $R $cname rc=$rc findings=$nf"
      pass=$((pass + 1))
    else
      bad "$R $cname" "${why%; }" "$out"
    fi
  done < "$dir/expect.txt"
done

echo "합계: 규칙 $nrules · 견본 $ncases · 항목 $((pass + fail + skip)) (py_compile $nrules 포함) · 맞음 $pass · 틀림 $fail"
echo "RESULT pass=$pass fail=$fail skip=$skip"
[ "$fail" -eq 0 ] || exit 1
exit 0
