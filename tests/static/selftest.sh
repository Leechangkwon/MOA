#!/usr/bin/env bash
# tests/static/selftest.sh — 정적 규칙 자체 시험 (점검 봇 1-F 완료 기준 F1, docs/checkbot/stage1-directive.md)
#
# 규칙마다 tests/static/fixtures/<규칙>/expect.txt 의 「<견본> rc=<n> findings=<n> [max_s=<n>] [par=<n>]」 줄대로 규칙을 돌려
#   ① 종료 코드 ② FINDING 줄 수 ③ 출력 규약(마지막 RESULT 줄이 있고 fail= 이 FINDING 줄 수와 같음)을 확인한다.
#   max_s= 가 있으면 ④ 그 견본 한 번 도는 시간이 그 초 이하인지도 본다(v4 재검토 2 — R29 ReDoS 시간 단언).
#   par= 가 있으면 ⑤ 같은 견본을 그 수만큼 한꺼번에(병렬로) 더 돌려 rc·FINDING 수가 모두 기대값과 같은지 본다
#   (부하에 따라 결과가 뒤집히지 않는지 — v4 재검토 3).
# 규칙 파일마다 py_compile 도 한 항목으로 센다(바이트코드는 임시 폴더에만 쓴다).
# 셸 규칙 여덟(r20·r23·r25·r26·r28·r29·r30·r31)의 풀이기 구간(class ShPart … sh_emit)이 바이트 단위로 같은지도 한 항목으로 센다(v4).
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
    read -r cname rcx fx rest <<<"$line"
    erc=${rcx#rc=}; ef=${fx#findings=}
    maxs=""; par=""; extra=""
    for opt in ${rest:-}; do
      if [[ "$opt" =~ ^max_s=([0-9]+)$ ]] && [ -z "$maxs" ]; then
        maxs=${BASH_REMATCH[1]}
      elif [[ "$opt" =~ ^par=([1-9][0-9]?)$ ]] && [ -z "$par" ]; then
        par=${BASH_REMATCH[1]}
      else
        extra="$extra $opt"
      fi
    done
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
    # max_s 가 있으면 그 두 배 + 2초에서 끊는다(멈춘 규칙이 selftest 를 멈추지 않고 틀림으로 나오게)
    lim=()
    [ -n "$maxs" ] && command -v timeout >/dev/null 2>&1 && lim=(timeout -k 2 $((maxs * 2 + 2)))
    t0=$(date +%s%N)
    if [ -f "$dir/run_case.sh" ]; then
      out=$("${lim[@]}" bash "$dir/run_case.sh" "$cdir" 2>&1); rc=$?
    else
      out=$("${lim[@]}" python3 -B "$rule" --root "$cdir" 2>&1); rc=$?
    fi
    ms=$(( ($(date +%s%N) - t0) / 1000000 ))
    nf=$(printf '%s\n' "$out" | grep -c '^FINDING ')
    resl=$(printf '%s\n' "$out" | grep -E '^RESULT pass=[0-9]+ fail=[0-9]+ skip=[0-9]+[[:space:]]*$' | tail -n 1)
    why=""
    [ "$rc" = "$erc" ] || why="${why}rc 기대 $erc · 실제 $rc; "
    [ "$nf" = "$ef" ] || why="${why}FINDING 기대 $ef · 실제 $nf; "
    if [ -n "$maxs" ] && [ "$ms" -gt $((maxs * 1000)) ]; then
      why="${why}시간 ${ms}ms > ${maxs}s; "
    fi
    if [ -n "$par" ]; then
      pdir="$tmp/par/$R.$cname"; mkdir -p "$pdir"
      for k in $(seq 1 "$par"); do
        if [ -f "$dir/run_case.sh" ]; then
          ( "${lim[@]}" bash "$dir/run_case.sh" "$cdir" >"$pdir/$k.out" 2>&1; echo $? >"$pdir/$k.rc" ) &
        else
          ( "${lim[@]}" python3 -B "$rule" --root "$cdir" >"$pdir/$k.out" 2>&1; echo $? >"$pdir/$k.rc" ) &
        fi
      done
      wait
      for k in $(seq 1 "$par"); do
        prc=$(cat "$pdir/$k.rc" 2>/dev/null); pnf=$(grep -c '^FINDING ' "$pdir/$k.out" 2>/dev/null)
        if [ "$prc" != "$erc" ] || [ "$pnf" != "$ef" ]; then
          why="${why}병렬 $k/$par: rc $prc · FINDING $pnf; "
        fi
      done
    fi
    if [ -z "$resl" ]; then
      why="${why}RESULT 줄 없음; "
    else
      rf=$(printf '%s' "$resl" | sed -E 's/.*fail=([0-9]+).*/\1/')
      [ "$rf" = "$nf" ] || why="${why}RESULT fail=$rf 가 FINDING 줄 수 $nf 와 다름; "
    fi
    if [ -z "$why" ]; then
      echo "PASS $R $cname rc=$rc findings=$nf${maxs:+ ${ms}ms≤${maxs}s}${par:+ 병렬 ${par}번 같음}"
      pass=$((pass + 1))
    else
      bad "$R $cname" "${why%; }" "$out"
    fi
  done < "$dir/expect.txt"
done

# 셸 풀이기 사본(v4) — 셸 규칙(r20·r23·r25·r26·r28 · v4 묶음 C 의 r29·r30·r31)은 풀이기를 import 없이 한 벌씩 갖는다. 그 구간
# (`class ShPart` 줄부터 `sh_emit` 몸 끝 줄까지)이 바이트 단위로 같아야 한다 — 한 벌만 고치면 틀림 1. 전 규칙이거나 그 가운데 하나를 고른 때만 본다.
SH_RULES="R20 R23 R25 R26 R28 R29 R30 R31"
want_sh=0
if [ "$#" -eq 0 ]; then
  want_sh=1
else
  for R in "${rules[@]}"; do
    case " $SH_RULES " in *" $R "*) want_sh=1 ;; esac
  done
fi
if [ "$want_sh" -eq 1 ]; then
  sh_why=""; sh_first=""; sh_list=""; sh_n=0
  for R in $SH_RULES; do
    r=$(printf '%s' "$R" | tr 'A-Z' 'a-z')
    mapfile -t cand < <(ls -1 "$HERE/${r}"_*.py 2>/dev/null)
    if [ "${#cand[@]}" -ne 1 ]; then
      sh_why="${sh_why}${r}_*.py 가 하나가 아님(${#cand[@]}개); "
      continue
    fi
    h=$(python3 -c '
import hashlib, re, sys
b = open(sys.argv[1], "rb").read()
m = re.search(rb"^class ShPart\b", b, re.M)
e = re.search(rb"^def sh_emit\(", b, re.M)
if not m or not e or e.start() < m.start():
    sys.exit(1)
nl = b.find(b"\n", e.end())
nl = len(b) if nl < 0 else nl + 1
rest = re.search(rb"^(?=\S)", b[nl:], re.M)
end = nl + rest.start() if rest else len(b)
print(hashlib.sha256(b[m.start():end].rstrip(b"\n")).hexdigest())
' "${cand[0]}" 2>/dev/null) || { sh_why="${sh_why}$(basename "${cand[0]}") 에서 풀이기 구간을 찾지 못함; "; continue; }
    sh_list="${sh_list} ${r}=${h:0:12}"; sh_n=$((sh_n + 1))
    if [ -z "$sh_first" ]; then
      sh_first=$h
    elif [ "$h" != "$sh_first" ]; then
      sh_why="${sh_why}${r} 의 풀이기가 다름; "
    fi
  done
  if [ -z "$sh_why" ]; then
    echo "PASS 셸 풀이기 ${sh_n} 벌 바이트 같음 (class ShPart … sh_emit · sha256 ${sh_first:0:12})"
    pass=$((pass + 1))
  else
    bad "셸 풀이기 사본" "${sh_why%; }" "$(printf '%s\n' $sh_list)"
  fi
fi

echo "합계: 규칙 $nrules · 견본 $ncases · 항목 $((pass + fail + skip)) (py_compile $nrules 포함) · 맞음 $pass · 틀림 $fail"
echo "RESULT pass=$pass fail=$fail skip=$skip"
[ "$fail" -eq 0 ] || exit 1
exit 0
