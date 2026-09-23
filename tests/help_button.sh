#!/usr/bin/env bash
# tests/help_button.sh — 「화면 문구 정리 2단계」 수락 기준 실행 (PRD 12.2 T-51, F-38, ARCH 5.19)
#
#   (a) 도움말 내용 파일 — 문법 · 키 10개 · HTML 0 · 구조 설명 문구 0
#   (b) 대상 핸들러 9개가 `$pageHelp` 를 넘기고 그 키가 배열에 있음 / 대상 아닌 화면은 안 넘김
#   (c) 대상 화면 9개에 `data-help-open` 단추 1개 · 아이콘 `data-icon="help"`
#   (d) scripts/ui_snapshot.sh 로 9화면(UI_PAGES) 1440px 시각 점검
#   (e) scripts/security_lint.sh 0건 · 바뀐 파일 php -l
#
# 규칙 (CLAUDE.md 3장)
#   · 검토 계정(qa_ 접두)만 쓴다 — (d)·(e) 의 scripts/ui_snapshot.sh 가 qa_admin01 로 들어가고
#     모든 요청에 `-A "tbox-review/<날짜>"` 를 붙인다. 이 시험은 **데이터를 하나도 만들지 않는다**
#     (정적 검사와 GET 뿐이라 지울 것도 되돌릴 것도 없다).
#
# 사용: bash tests/help_button.sh [출력 폴더]
#   출력 폴더 기본값은 ~/.claude/ui-shots/help-button (루트 디스크를 쓰지 않는다).
set -u

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="${1:-$HOME/.claude/ui-shots/help-button}"
PASS=0
FAIL=0

hr()  { printf '\n════════════════════════════════════════════════════════════\n%s\n════════════════════════════════════════════════════════════\n' "$1"; }
sub() { printf '\n── %s\n' "$1"; }
expect() {
  if [ "$2" = "$3" ]; then printf '  ✔ %s  (기대=%s 실제=%s)\n' "$1" "$2" "$3"; PASS=$((PASS+1))
  else printf '  ✖ %s  (기대=%s 실제=%s)\n' "$1" "$2" "$3"; FAIL=$((FAIL+1)); fi
}

# 대상 화면 9개 — 키 : 핸들러 : 단추가 있는 파일(핸들러 자신이거나 그 화면 view)
TARGETS="
files:home.php:src/handlers/home.php
settings:settings_page.php:src/handlers/settings_page.php
activity:activity_page.php:src/handlers/activity_page.php
admin:admin_page.php:src/handlers/admin_page.php
users:users_page.php:src/handlers/users_page.php
org:org_page.php:src/handlers/org_page.php
permissions:admin_permissions_page.php:src/handlers/admin_permissions_page.php
audit:audit_page.php:src/handlers/audit_page.php
trash:trash_page.php:src/handlers/trash_page.php
"

# 대상이 **아닌** 화면 — 로그인·재인증·2단계 등록·위키
NOT_TARGETS="src/handlers/login_page.php src/handlers/reauth_page.php src/views/mfa_enroll.php
             src/handlers/wiki_index.php src/handlers/wiki_page.php
             src/handlers/wiki_books.php src/handlers/wiki_legacy.php"

# 이번 작업이 바꾼 파일 (php -l · 정적 검사 대상)
CHANGED_PHP="src/help/screens.php src/views/shell_top.php src/views/shell_bottom.php"
for t in $TARGETS; do
  CHANGED_PHP="$CHANGED_PHP src/handlers/$(echo "$t" | cut -d: -f2) $(echo "$t" | cut -d: -f3)"
done
CHANGED_PHP=$(printf '%s\n' $CHANGED_PHP | sort -u)

mkdir -p "$OUT"

# ─────────────────────────────────────────────────────────────
hr "(a) 도움말 내용 파일 — src/help/screens.php"
# ─────────────────────────────────────────────────────────────
sub "문법"
if php -l "$ROOT/src/help/screens.php" > /dev/null 2>&1; then
  expect "php -l 통과" 0 0
else
  php -l "$ROOT/src/help/screens.php"; expect "php -l 통과" 0 1
fi

sub "키 10개 · 순수 문자열 · 구조 설명 문구"
# 배열 **값만** 본다 — 파일 맨 위 설명 주석은 개발자용이라 검사 대상이 아니다.
php -r '
$a = require $argv[1];
$keys = array_keys($a);
$texts = [];
foreach ($a as $k => $v) {
    $texts[] = $v["title"] ?? "";
    foreach ($v["sections"] ?? [] as $s) {
        $texts[] = $s["h"] ?? "";
        foreach ($s["items"] ?? [] as $i) { $texts[] = $i; }
    }
}
$all = implode("\n", $texts);
$lt = 0;
foreach ($texts as $t) { $lt += substr_count($t, "<"); }
/* 금지: 개발 구조 설명 (지시서 금지 패턴).
   「감사 로그」는 화면 이름이라 그대로 두고, 막는 것은 「감사 로그에 남습니다」 쪽이다. */
$banned = ["N단계" => "/[0-9]{1,2}단계(?! 인증)/u", "S-0x" => "/S-0[0-9]/u", "R-xx" => "/R-[0-9]/u",
           "T-xx" => "/T-[0-9]/u", "PRD" => "/PRD/u", "ARCH" => "/ARCH/u",
           "서버가" => "/서버가/u", "감사 로그에 남" => "/감사 로그에 남/u"];
$hits = 0;
foreach ($banned as $name => $re) {
    if (preg_match_all($re, $all, $m)) { $hits += count($m[0]); echo "    금지 문구 [$name] ", implode(",", $m[0]), "\n"; }
}
echo "KEYS=", count($keys), "\nLT=", $lt, "\nBANNED=", $hits, "\nTEXTS=", count($texts), "\n";
echo "KEYLIST=", implode(",", $keys), "\n";
/* 참고 — 화면 이름으로 쓰인 「감사 로그」 는 금지가 아니다(지시서 1장 원문 그대로). */
echo "NOTE_감사로그=", preg_match_all("/감사 로그/u", $all), "\n";
' "$ROOT/src/help/screens.php" > "$OUT/a-screens.txt" 2>&1
cat "$OUT/a-screens.txt"
expect "키 13개(F-43 fetch-hosts·커뮤니티 셋 포함 — 5-C 게시판)" 13 "$(sed -n 's/^KEYS=//p' "$OUT/a-screens.txt")"
expect "모든 문자열에 '<' 없음" 0 "$(sed -n 's/^LT=//p' "$OUT/a-screens.txt")"
expect "구조 설명 문구 0건" 0 "$(sed -n 's/^BANNED=//p' "$OUT/a-screens.txt")"

# ─────────────────────────────────────────────────────────────
hr "(b) 핸들러가 넘기는 도움말 키"
# ─────────────────────────────────────────────────────────────
sub "대상 9개 — \$pageHelp 한 줄 · 키가 배열에 있음"
n_ok=0
for t in $TARGETS; do
  key=$(echo "$t" | cut -d: -f1); h="src/handlers/$(echo "$t" | cut -d: -f2)"
  got=$(grep -c "^\$pageHelp  *= '$key';" "$ROOT/$h")
  in_array=$(php -r '$a = require $argv[1]; exit(isset($a[$argv[2]]) ? 0 : 1);' "$ROOT/src/help/screens.php" "$key" && echo 1 || echo 0)
  if [ "$got" = "1" ] && [ "$in_array" = "1" ]; then n_ok=$((n_ok+1)); else printf '    ✖ %s (키=%s 선언=%s 배열=%s)\n' "$h" "$key" "$got" "$in_array"; fi
done
expect "9개 핸들러가 배열에 있는 키를 넘김" 9 "$n_ok"

sub "대상이 아닌 화면 — \$pageHelp 도 단추도 없음"
n_bad=0
for f in $NOT_TARGETS; do
  [ -f "$ROOT/$f" ] || continue
  if grep -q 'pageHelp\|data-help-open' "$ROOT/$f"; then printf '    ✖ %s\n' "$f"; n_bad=$((n_bad+1)); fi
done
expect "로그인·재인증·2단계 등록·위키 0건" 0 "$n_bad"

sub "저장소 전체에서 \$pageHelp 를 넘기는 파일 수"
expect "정확히 13개(F-43 fetch-hosts·커뮤니티 셋 포함 — 5-C 게시판)" 13 "$(grep -rl '^\$pageHelp' "$ROOT/src" | wc -l)"

# ─────────────────────────────────────────────────────────────
hr "(c) 화면의 「사용 방법」 단추"
# ─────────────────────────────────────────────────────────────
n_btn=0
for t in $TARGETS; do
  v="$(echo "$t" | cut -d: -f3)"
  c_open=$(grep -c 'data-help-open' "$ROOT/$v")
  c_icon=$(grep -c 'data-icon="help"' "$ROOT/$v")
  c_pos=$(grep -A1 '<div class="page-head__actions">' "$ROOT/$v" | grep -c 'data-help-open')
  if [ "$c_open" = "1" ] && [ "$c_icon" = "1" ] && [ "$c_pos" = "1" ]; then n_btn=$((n_btn+1));
  else printf '    ✖ %s (data-help-open=%s icon=%s 첫자리=%s)\n' "$v" "$c_open" "$c_icon" "$c_pos"; fi
done
expect "9개 화면에 단추 1개씩(.page-head__actions 첫 자리)" 9 "$n_btn"

sub "셸 · 스크립트 쪽"
# 설명 주석에도 같은 이름이 나오므로 **줄 첫머리의 <dialog** 만 센다.
expect "shell_bottom 에 help-dialog 1개" 1 "$(grep -c '^<dialog id="help-dialog"' "$ROOT/src/views/shell_bottom.php")"
expect "아이콘 템플릿 tpl-icon-help" 1 "$(grep -c 'id="tpl-icon-help"' "$ROOT/src/views/shell_bottom.php")"
expect "app.js 위임 하나 (initHelpDialog)" 1 "$(grep -c '^function initHelpDialog()' "$ROOT/public/assets/app.js")"
expect "app.js 에 innerHTML 계열 0" 0 "$(grep -c 'innerHTML\|outerHTML\|insertAdjacentHTML' "$ROOT/public/assets/app.js")"
expect "바뀐 파일에 인라인 style 속성 0" 0 "$(grep -c 'style="' $(for f in $CHANGED_PHP; do echo "$ROOT/$f"; done) | awk -F: '{s+=$2} END {print s+0}')"

# ─────────────────────────────────────────────────────────────
hr "(d) 9화면 시각 점검 — 1440px (scripts/ui_snapshot.sh)"
# ─────────────────────────────────────────────────────────────
D_PAGES="/,/settings,/activity,/admin,/users,/org,/admin/permissions,/audit,/trash"
mkdir -p "$OUT/d-1440"
if UI_PAGES="$D_PAGES" bash "$ROOT/scripts/ui_snapshot.sh" "$OUT/d-1440" 1440 > "$OUT/d-1440.txt" 2>&1; then
  cat "$OUT/d-1440.txt"
  TOTAL=$(sed -n 's/^합계 \([0-9]*\)건$/\1/p' "$OUT/d-1440.txt")
  # 이 작업 **전** 같은 화면들의 합계는 7건이었다 (전부 이번 변경과 무관한 기존 항목:
  #   「2단계 인증」이라는 기능 이름 6건 + /org 의 규칙 없는 class `org-teams` 1건).
  # 그래서 여기서 보는 것은 "0건" 이 아니라 **늘지 않았는가** 이다.
  expect "1440px 합계가 기존값(7건) 이하" "yes" "$([ "${TOTAL:-99}" -le 7 ] && echo yes || echo "no(${TOTAL:-없음})")"
  echo "  ── 결함 원문 ──"
  python3 - "$OUT/d-1440/report.json" <<'PY'
import json, sys
r = json.load(open(sys.argv[1]))
for path, shots in r.items():
    for name, a in shots.items():
        if 'error' in a:
            print(f"  {path} [{name}] ERROR {a['error']}"); continue
        for k in ('clipped','overlap','row_height','offscreen','undefined_class','dup_id','stage_text','console_errors'):
            for v in a.get(k, []):
                print(f"  {path} [{name}] {k} :: {v}")
        if a.get('overflow_x'):
            print(f"  {path} [{name}] overflow_x")
PY
else
  cat "$OUT/d-1440.txt"; expect "1440px 합계가 기존값(7건) 이하" "yes" "실행 실패"
fi

# ─────────────────────────────────────────────────────────────
hr "(e) 보안 린트 · php -l"
# ─────────────────────────────────────────────────────────────
sub "scripts/security_lint.sh"
if bash "$ROOT/scripts/security_lint.sh" > "$OUT/e-lint.txt" 2>&1; then
  tail -3 "$OUT/e-lint.txt"; expect "금지 패턴 0건" 0 0
else
  cat "$OUT/e-lint.txt"; expect "금지 패턴 0건" 0 1
fi

sub "php -l — 바뀐 파일 전부"
n_syntax=0
for f in $CHANGED_PHP; do
  php -l "$ROOT/$f" > /dev/null 2>&1 || { php -l "$ROOT/$f"; n_syntax=$((n_syntax+1)); }
done
expect "문법 오류 0건 ($(printf '%s\n' $CHANGED_PHP | wc -l)개 파일)" 0 "$n_syntax"

hr "결과"
printf '  통과 %d · 실패 %d\n  출력: %s\n' "$PASS" "$FAIL" "$OUT"
[ "$FAIL" -eq 0 ] || exit 1
