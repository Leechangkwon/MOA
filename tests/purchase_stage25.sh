#!/usr/bin/env bash
# tests/purchase_stage25.sh — 25단계 수락 기준 실행 (PRD 12.2 T-50)
#   구매·재고 13개 화면의 **보이는 것**만 본다: 공통 CSS 한 벌 · 시안 충실도 · 정보구조.
#
# 규칙 (CLAUDE.md 3장)
#   · **검토 계정(qa_ 접두)만** 쓴다 — 여기서는 scripts/ui_snapshot.sh 가 qa_admin01 로 들어간다.
#   · 모든 요청에 `-A "tbox-review/<날짜>"` 가 붙는다 (같은 스크립트가 붙인다).
#
# ★ 이 시험은 **데이터를 하나도 만들지 않는다** — 읽기(GET)와 정적 검사뿐이다.
#   그래서 19~24단계 스크립트의 「최근 10분 비-qa 활동」 관문(R-73 ②)이 필요 없다.
#   지우는 것도, 되돌릴 것도 없다.
#
# 사용: bash tests/purchase_stage25.sh [출력 폴더]
#   출력 폴더 기본값은 ~/.claude/ui-shots/stage25-test (루트 디스크를 쓰지 않는다).
set -u

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="${1:-$HOME/.claude/ui-shots/stage25-test}"
PASS=0
FAIL=0
SKIP=0

hr()  { printf '\n════════════════════════════════════════════════════════════\n%s\n════════════════════════════════════════════════════════════\n' "$1"; }
sub() { printf '\n── %s\n' "$1"; }

expect() {
  if [ "$2" = "$3" ]; then
    printf '  ✔ %s  (기대=%s 실제=%s)\n' "$1" "$2" "$3"; PASS=$((PASS+1))
  else
    printf '  ✖ %s  (기대=%s 실제=%s)\n' "$1" "$2" "$3"; FAIL=$((FAIL+1))
  fi
}

# 주석을 걷어 낸 **실제 코드·화면 문구**에서만 낱말을 센다.
# (PHP 의 `/* … */`·`//`, JS 의 같은 두 가지. 설명 주석에 적힌 「innerHTML 을 쓰지 않는다」
#  같은 문장이 결함으로 잡히면 시험이 사람을 속인다.)
count_in_code() {
  local pattern="$1"; shift
  python3 - "$pattern" "$@" <<'PYEOF'
import re, sys
pattern = sys.argv[1]
total = 0
for path in sys.argv[2:]:
    text = open(path, encoding='utf-8').read()
    text = re.sub(r'/\*.*?\*/', '', text, flags=re.S)      # PHP·JS 블록 주석
    text = re.sub(r'<!--.*?-->', '', text, flags=re.S)      # HTML 주석
    text = re.sub(r'^\s*//.*$', '', text, flags=re.M)       # 한 줄 주석
    total += len(re.findall(pattern, text))
print(total)
PYEOF
}

# 구매 화면 13개 (인쇄 화면은 공통 셸을 쓰지 않아 따로 본다)
SHELL_VIEWS="purchase_pr purchase_po purchase_receipt purchase_move purchase_stock
             purchase_weekly purchase_recon purchase_docs purchase_masters purchase_item
             purchase_import purchase_structure"
SHELL_HANDLERS="purchase_pr_page purchase_po_page purchase_receipt_page purchase_move_page
                purchase_stock_page purchase_weekly_page purchase_recon_page purchase_docs_page
                purchase_masters_page purchase_item_page purchase_import_page purchase_structure_page"

# ─────────────────────────────────────────────────────────────
hr "(a) 화면 스크린샷 · DOM 결함 — 1440px 과 1024px 두 폭"
# ─────────────────────────────────────────────────────────────
# 개선 전 기준: 1440px 46건(지시서 0장) → 25단계 측정 시점 65건. 목표는 두 폭 모두 0건.
for W in 1440 1024; do
  sub "폭 ${W}px"
  LOG="$OUT/summary-$W.txt"
  mkdir -p "$OUT/$W"
  if bash "$ROOT/scripts/ui_snapshot.sh" "$OUT/$W" "$W" > "$LOG" 2>&1; then
    cat "$LOG"
    TOTAL=$(sed -n 's/^합계 \([0-9]*\)건$/\1/p' "$LOG")
    expect "${W}px 합계 0건" 0 "${TOTAL:-없음}"
    if [ "${TOTAL:-1}" != "0" ]; then
      echo "  ── report.json 의 결함 항목 원문 ──"
      python3 - "$OUT/$W/report.json" <<'PY'
import json, sys
report = json.load(open(sys.argv[1]))
for path, shots in report.items():
    for name, audit in shots.items():
        for key in ('clipped', 'overlap', 'row_height', 'offscreen',
                    'undefined_class', 'dup_id', 'stage_text', 'console_errors'):
            found = audit.get(key) or []
            if found:
                print('   ', path, '[' + name + ']', key, '=', found)
        if audit.get('overflow_x'):
            print('   ', path, '[' + name + ']', 'overflow_x = True')
PY
    fi
  else
    echo "  ■ ui_snapshot.sh 실행 실패 — 아래 원문"
    cat "$LOG"
    FAIL=$((FAIL+1))
  fi
done

# ─────────────────────────────────────────────────────────────
hr "(b) 화면이 싣는 CSS = app.css + purchase.css (인쇄만 purchase_print.css)"
# ─────────────────────────────────────────────────────────────
N=0
for v in $SHELL_VIEWS; do
  C=$(grep -c '<link rel="stylesheet"' "$ROOT/src/views/$v.php")
  N=$((N + C))
done
expect "구매 view 12개의 본문 안 <link> 0개 (셸이 head 에서 싣는다)" 0 "$N"

N=0
for h in $SHELL_HANDLERS; do
  grep -q "\$pageCss" "$ROOT/src/handlers/$h.php" || N=$((N + 1))
done
expect "핸들러 12개가 \$pageCss 를 넘긴다 (안 넘기는 것 0)" 0 "$N"

N=0
for h in $SHELL_HANDLERS; do
  grep -q "\$pageMsgTop" "$ROOT/src/handlers/$h.php" || N=$((N + 1))
done
expect "핸들러 12개가 \$pageMsgTop 을 넘긴다 (안 넘기는 것 0)" 0 "$N"

expect "셸이 \$pageCss 를 head 에서 화이트리스트로 내보낸다" 1 \
  "$(grep -c 'shellCssAllowed' "$ROOT/src/views/shell_top.php" | head -1 | awk '{print ($1>0)?1:0}')"
expect "인쇄 화면은 자기 head 에 purchase_print.css 한 벌" 1 \
  "$(grep -c '<link rel="stylesheet" href="/assets/purchase_print.css' "$ROOT/src/views/purchase_po_print.php")"

# ─────────────────────────────────────────────────────────────
hr "(c) 지운 화면별 CSS 참조 0 · 같은 클래스 두 정의 0"
# ─────────────────────────────────────────────────────────────
expect "purchase_(docs|stock|recon|structure).css 참조 0" 0 \
  "$(grep -rn 'purchase_\(docs\|stock\|recon\|structure\)\.css' "$ROOT/src" "$ROOT/public" | wc -l)"
expect "그 네 파일이 저장소에 없다" 0 \
  "$(ls "$ROOT/public/assets/" | grep -c '^purchase_\(docs\|stock\|recon\|structure\)\.css$')"
expect "남은 CSS 는 app.css · purchase.css · purchase_print.css 셋" 3 \
  "$(ls "$ROOT/public/assets/" | grep -c '\.css$')"

# 「같은 클래스 두 정의」 — 클래스 하나만으로 여는 규칙 블록(`^.foo {`)이 두 번 나오는지 본다.
# 지시서의 원문 grep(`^\.[a-z0-9_-]+`)은 `.btn:hover`·`.btn .icon` 같은 **정상 선택자**도
# 같은 낱말로 세어 app.css 의 기존 규칙에서 늘 걸린다(아래 「참고」에 원문 그대로 찍는다).
sub "정의가 두 번인 클래스 (단일 클래스 규칙 블록 기준)"
DUPES=$(grep -ohE '^\.[a-z0-9_-]+ *\{' "$ROOT/public/assets/app.css" "$ROOT/public/assets/purchase.css" \
        | tr -d ' {' | sort | uniq -d | grep -v '^\.wiki-check__row$')
echo "${DUPES:-(없음)}"
expect "구매·공통 조각에서 두 번 정의된 클래스 0" 0 "$(printf '%s' "$DUPES" | grep -c . )"
echo "  (제외: .wiki-check__row — 14단계 위키 규칙이 「묶음 선택자 + 개별 선택자」로 쓰는 자리다."
echo "   25단계는 app.css 의 기존 규칙을 고치지 않는다.)"

sub "참고 — 지시서 원문 grep 의 출력 (낱말 기준이라 정상 선택자도 걸린다)"
grep -ohE '^\.[a-z0-9_-]+' "$ROOT/public/assets/app.css" "$ROOT/public/assets/purchase.css" \
  | sort | uniq -d | tr '\n' ' '
echo

expect "purchase.css 에 !important 0 (주석 제외)" 0 \
  "$(count_in_code '!important' "$ROOT/public/assets/purchase.css")"
expect "구매 화면·JS 에 인라인 style= 0 (CSP)" 0 \
  "$(grep -c 'style="' $ROOT/src/views/purchase_*.php | awk -F: '{s+=$2} END {print s+0}')"

# ─────────────────────────────────────────────────────────────
hr "(d) 상태 어휘 = README 고정 어휘 · 배지 색 = 의미"
# ─────────────────────────────────────────────────────────────
expect "JS 에 축약 '일부' 단독 문자열 0" 0 \
  "$(grep -c "'일부'" $ROOT/public/assets/purchase*.js | awk -F: '{s+=$2} END {print s+0}')"
expect "'일부 발주' 를 쓰는 JS 파일 2개 (docs·stock)" 2 \
  "$(grep -l "'일부 발주'" $ROOT/public/assets/purchase*.js | wc -l)"
expect "발주요청 전표 탭 상태 셀렉트에 in_progress 있음" 1 \
  "$(grep -c 'value="in_progress"' "$ROOT/src/views/purchase_pr.php")"
expect "진행중 = badge--warn (두 번들 모두)" 2 \
  "$(grep -c "in_progress: 'badge--warn'" $ROOT/public/assets/purchase*.js | awk -F: '{s+=$2} END {print s+0}')"
expect "일부 발주 = badge--accent (두 번들 모두)" 2 \
  "$(grep -c "partial: 'badge--accent'" $ROOT/public/assets/purchase*.js | awk -F: '{s+=$2} END {print s+0}')"
expect "완료 = badge--ok (두 번들 모두)" 2 \
  "$(grep -c "done: 'badge--ok'" $ROOT/public/assets/purchase*.js | awk -F: '{s+=$2} END {print s+0}')"
expect "취소 = badge--off (두 번들 모두)" 2 \
  "$(grep -c "void: 'badge--off'" $ROOT/public/assets/purchase*.js | awk -F: '{s+=$2} END {print s+0}')"

# ─────────────────────────────────────────────────────────────
hr "(e) 헤더의 다른 화면 링크 0 · 개발 잔재 0"
# ─────────────────────────────────────────────────────────────
# .page-head__actions 안에서 /purchase/ 로 가는 <a> — 인쇄(#po-print)는 예외다.
CROSS=$(python3 - "$ROOT" <<'PY'
import re, sys, pathlib
root = pathlib.Path(sys.argv[1])
bad = []
for view in sorted((root / 'src/views').glob('purchase_*.php')):
    text = view.read_text()
    for block in re.findall(r'<div class="page-head__actions">(.*?)</div>\s*</header>', text, re.S):
        for href in re.findall(r'<a[^>]*href="(/purchase/[^"]*)"', block):
            bad.append(view.name + ' → ' + href)
print(len(bad))
for line in bad:
    print('   ', line)
PY
)
echo "$CROSS" | tail -n +2
expect "머리말의 다른 구매 화면 링크 0" 0 "$(echo "$CROSS" | head -1)"

expect "화면 문구에 「N단계」 0 (주석 제외 — 주석은 개발 이력이라 남긴다)" 0 \
  "$(count_in_code '[0-9]단계' $ROOT/src/views/purchase_*.php)"
expect "수불부의 「품목 id」 숫자 입력 0 (주석 제외)" 0 \
  "$(count_in_code '품목 id' "$ROOT/src/views/purchase_stock.php")"
expect "자기 자신으로 가는 「인쇄·PDF」 더미 링크 0" 0 \
  "$(grep -c 'id="po-print".*href="/purchase/po"' "$ROOT/src/views/purchase_po.php")"
expect "라벨 없는 <th> 0 (빈 머리는 sr-only)" 0 \
  "$(grep -o '<th[^>]*></th>' $ROOT/src/views/purchase_*.php | wc -l)"
expect "도달 불가 품목 등록 폼 정의 0" 0 \
  "$(count_in_code "'items': \\[" "$ROOT/public/assets/purchase.js")"
expect "호박색 안내 상자(.notice) 0" 0 \
  "$(grep -c 'class="notice"' $ROOT/src/views/purchase_*.php | awk -F: '{s+=$2} END {print s+0}')"

sub "공통 도우미 한 벌 — 다섯 번들이 purchase_common.js 를 쓴다"
expect "window.PurchaseUI 를 노출하는 파일 1개" 1 \
  "$(grep -l 'window.PurchaseUI =' $ROOT/public/assets/*.js | wc -l)"
expect "다섯 번들이 PurchaseUI 를 읽는다" 5 \
  "$(grep -l 'window.PurchaseUI' $ROOT/public/assets/purchase.js $ROOT/public/assets/purchase_docs.js \
       $ROOT/public/assets/purchase_stock.js $ROOT/public/assets/purchase_recon.js \
       $ROOT/public/assets/purchase_structure.js | wc -l)"
expect "번들에 남은 handleGate 사본 0" 0 \
  "$(grep -c 'function handleGate' $ROOT/public/assets/purchase.js $ROOT/public/assets/purchase_docs.js \
       $ROOT/public/assets/purchase_stock.js $ROOT/public/assets/purchase_recon.js \
       $ROOT/public/assets/purchase_structure.js | awk -F: '{s+=$2} END {print s+0}')"
expect "사이클 표를 그리는 함수 1벌" 1 \
  "$(grep -c 'function renderCycleRows' "$ROOT/public/assets/purchase_docs.js")"
expect "구매 JS 에 innerHTML 계열 0 (주석 제외 — scripts/security_lint.sh 와 같은 규칙)" 0 \
  "$(count_in_code 'innerHTML|outerHTML|insertAdjacentHTML|document\.write' $ROOT/public/assets/purchase*.js)"

# ─────────────────────────────────────────────────────────────
hr "(f) 회귀 — 19~24단계 시험 전부"
# ─────────────────────────────────────────────────────────────
for n in 19 20 21 22 23 24; do
  sub "tests/purchase_stage$n.sh"
  RLOG="$OUT/regress-$n.txt"
  bash "$ROOT/tests/purchase_stage$n.sh" > "$RLOG" 2>&1
  RC=$?
  # 19단계 스크립트만 「  통과 N · 실패 N」(앞에 「결과:」 가 없다) 꼴이다.
  LAST=$(grep -E '통과 [0-9]+ · 실패 [0-9]+' "$RLOG" | tail -1)
  if [ "$RC" = "3" ]; then
    echo "  ▲ R-73 사용자 활동 관문으로 종료 3 — 시험을 돌리지 않았습니다 (10분 뒤 다시 도세요)."
    SKIP=$((SKIP+1))
  elif [ -n "$LAST" ]; then
    echo "  $LAST"
    RFAIL=$(printf '%s' "$LAST" | sed -n 's/.*실패 \([0-9]*\).*/\1/p')
    expect "$n단계 회귀 실패 0" 0 "${RFAIL:-없음}"
  else
    echo "  ■ 결과 줄을 찾지 못했습니다 (종료 코드 $RC) — 원문 끝 20줄:"
    tail -20 "$RLOG"
    FAIL=$((FAIL+1))
  fi
done

# ─────────────────────────────────────────────────────────────
hr "(g) 보안 린트 · php -l"
# ─────────────────────────────────────────────────────────────
sub "scripts/security_lint.sh"
LINT="$OUT/security_lint.txt"
bash "$ROOT/scripts/security_lint.sh" > "$LINT" 2>&1
LRC=$?
tail -20 "$LINT"
expect "security_lint 종료 0" 0 "$LRC"

sub "php -l — 구매 view 13 · handler 13"
N=0
for v in $SHELL_VIEWS purchase_po_print; do
  php -l "$ROOT/src/views/$v.php" > /dev/null 2>&1 || { N=$((N+1)); echo "  ✖ $v.php"; }
done
for h in $SHELL_HANDLERS purchase_po_print_page; do
  php -l "$ROOT/src/handlers/$h.php" > /dev/null 2>&1 || { N=$((N+1)); echo "  ✖ $h.php"; }
done
php -l "$ROOT/src/views/shell_top.php" > /dev/null 2>&1 || { N=$((N+1)); echo "  ✖ shell_top.php"; }
php -l "$ROOT/src/views/shell_bottom.php" > /dev/null 2>&1 || { N=$((N+1)); echo "  ✖ shell_bottom.php"; }
expect "문법 오류 0" 0 "$N"

echo "  (JS 는 이 서버에 node 가 없어 문법을 따로 돌리지 못한다 —"
echo "   대신 (a) 의 ui_snapshot 이 화면을 실제로 열어 브라우저 콘솔 오류를 센다: console=0.)"

hr "결과"
printf '결과: 통과 %d · 실패 %d · 건너뜀 %d\n' "$PASS" "$FAIL" "$SKIP"
[ "$FAIL" = "0" ] || exit 1
[ "$SKIP" = "0" ] || exit 3
exit 0
