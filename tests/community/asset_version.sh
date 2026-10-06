#!/usr/bin/env bash
# tests/community/asset_version.sh — 커뮤니티 JS 부품 import 의 버전 표시 (2026-09-24 핫픽스 · 저장소 파일만)
#
# 왜: `/tbox/assets/` 는 7일 캐시다. 진입 파일 editor.js 만 버전을 달던 때, 7-C 이전 doc-render.js 를
#     캐시한 브라우저는 새 editor.js 의 `import { embedHost }` 에서 모듈 연결이 실패해 커뮤니티 단추가
#     전부 먹통이었다. 이제 import 문마다 `?v=<버전>` 이 찍힌다 — scripts/community_asset_version.php.
#     브라우저로 「옛 부품 캐시」를 흉내 내는 시험은 tests/community/stale_modules.sh.
#
#   ① 저장소 그대로 --check 통과 · 상대 import 전부 버전 있음 · 한 파일은 어디서 부르든 같은 주소(모듈 두 벌 0)
#   ② 임시 사본에서 우리 부품 한 줄을 바꾸면 --check 가 실패하고, 다시 찍으면 통과 · 부품 버전만 바뀌고
#      ProseMirror 버전·vendor 파일은 그대로
#   ③ vendor 한 줄을 바꾸면 두 버전이 모두 바뀐다(우리 부품도 따라 새 주소)
#   ④ 줄 머리가 아닌 import · 없는 파일을 가리키는 import 는 문제로 잡는다
#   ⑤ release.sh 는 버전 표시가 낡은 커밋을 발행하지 않는다(임시 클론 · --dry-run)
#   ⑥ 셸이 진입 파일을 module 로 부른다 · 문법
#
# 운영에 닿지 않는다: 저장소 파일을 읽고, 쓰기는 mktemp 폴더에만 한다. 나가는 값 0 통과 · 1 실패
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
SCRIPT="$ROOT/scripts/community_asset_version.php"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
PASS=0
FAIL=0

hr()  { printf '\n── %s\n' "$1"; }
expect() {
  if [ "$2" = "$3" ]; then printf '  ✔ %s  (기대=%s 실제=%s)\n' "$1" "$2" "$3"; PASS=$((PASS+1))
  else printf '  ✖ %s  (기대=%s 실제=%s)\n' "$1" "$2" "$3"; FAIL=$((FAIL+1)); fi
}
contains() {
  case "$3" in
    *"$2"*) printf '  ✔ %s  (「%s」 포함)\n' "$1" "$2"; PASS=$((PASS+1)) ;;
    *)      printf '  ✖ %s  (「%s」 없음 — 실제: %s)\n' "$1" "$2" "$(printf '%s' "$3" | head -c 300)"; FAIL=$((FAIL+1)) ;;
  esac
}
ver() { php "$SCRIPT" --print --root "$1" | sed -n "s/^$2=//p"; }

# 임시 사본 — 커뮤니티 부품 폴더 + 폴더 밖 진입 파일 messenger.js(스크립트는 --root 로 이 사본을 본다 ·
# 스크립트의 OUTSIDE_ENTRIES 가 이 파일을 반드시 찾는다 — Q:F-11 보정 D-3)
fresh_copy() {
  rm -rf "$TMP/app"
  mkdir -p "$TMP/app/public/assets"
  cp -a "$ROOT/public/assets/community" "$TMP/app/public/assets/"
  cp -a "$ROOT/public/assets/messenger.js" "$TMP/app/public/assets/"
}

hr "① 저장소 그대로"
out=$(php "$SCRIPT" --check 2>&1); rc=$?
expect "--check 통과" "0" "$rc"
printf '    %s\n' "$(printf '%s' "$out" | head -1)"

# 상대 import 를 전부 모아 (부르는 파일, 가리키는 파일, 붙은 버전) 으로 본다
python3 - "$ROOT/public/assets/community" > "$TMP/imports.txt" <<'PY'
import os, re, sys
base = sys.argv[1]
rx = re.compile(r"^(?:import\s*|(?:import|export)\b[^;'\"]*?\bfrom\s*)(['\"])(\.\.?/[^'\"?]+\.js)(\?v=[0-9a-f]*)?\1", re.M)
for dp, _, fs in os.walk(base):
    for f in sorted(fs):
        if not f.endswith('.js'):
            continue
        p = os.path.join(dp, f)
        src = open(p, encoding='utf-8').read()
        for m in rx.finditer(src):
            target = os.path.normpath(os.path.join(os.path.dirname(p), m.group(2)))
            print("%s\t%s\t%s" % (os.path.relpath(p, base), os.path.relpath(target, base), m.group(3) or ''))
PY
n_all=$(wc -l < "$TMP/imports.txt" | tr -d ' ')
n_nov=$(awk -F'\t' '$3==""' "$TMP/imports.txt" | wc -l | tr -d ' ')
expect "상대 import 가 있다(40개 넘게 — editor.js 14 · vendor/pm 끼리 20여)" "yes" "$([ "$n_all" -gt 40 ] && echo yes || echo "no($n_all)")"
expect "버전 없는 상대 import 0" "0" "$n_nov"
# 한 파일을 부르는 주소는 하나 — 다르면 브라우저가 모듈을 두 벌 돌린다(ProseMirror instanceof 가 깨진다)
n_split=$(awk -F'\t' '{print $2"\t"$3}' "$TMP/imports.txt" | sort -u | awk -F'\t' '{c[$1]++} END {n=0; for (k in c) if (c[k]>1) n++; print n}')
expect "같은 파일을 서로 다른 버전으로 부르는 곳 0" "0" "$n_split"
APP=$(ver "$ROOT" app); PM=$(ver "$ROOT" pm)
n_pm_bad=$(awk -F'\t' -v pm="?v=$PM" '$2 ~ /^vendor\// && $3 != pm' "$TMP/imports.txt" | wc -l | tr -d ' ')
n_app_bad=$(awk -F'\t' -v app="?v=$APP" '$2 !~ /^vendor\// && $3 != app' "$TMP/imports.txt" | wc -l | tr -d ' ')
expect "vendor/pm 을 부르는 import 는 전부 ProseMirror 버전($PM)" "0" "$n_pm_bad"
expect "우리 부품을 부르는 import 는 전부 부품 버전($APP)" "0" "$n_app_bad"
expect "vendor 가 우리 부품을 부르지 않는다" "0" "$(awk -F'\t' '$1 ~ /^vendor\// && $2 !~ /^vendor\//' "$TMP/imports.txt" | wc -l | tr -d ' ')"
expect "editor.js 가 doc-render.js 를 버전과 함께 부른다" "1" \
  "$(grep -cF "from './doc-render.js?v=$APP'" "$ROOT/public/assets/community/editor.js")"

hr "② 우리 부품 한 줄을 바꾸면 (임시 사본)"
fresh_copy
cp "$TMP/app/public/assets/community/vendor/pm/prosemirror-view.js" "$TMP/view.before"
printf '\n// 시험용 한 줄\n' >> "$TMP/app/public/assets/community/doc-render.js"
out=$(php "$SCRIPT" --check --root "$TMP/app" 2>&1); rc=$?
expect "--check 실패(낡은 표시)" "1" "$rc"
contains "doc-render.js 를 부르는 editor.js 를 짚는다" "editor.js" "$out"
contains "templates.js 도 짚는다(doc-render 를 부른다)" "templates.js" "$out"
php "$SCRIPT" --root "$TMP/app" > /dev/null 2>&1
expect "다시 찍으면 --check 통과" "0" "$(php "$SCRIPT" --check --root "$TMP/app" > /dev/null 2>&1; echo $?)"
APP2=$(ver "$TMP/app" app); PM2=$(ver "$TMP/app" pm)
expect "부품 버전이 바뀌었다" "yes" "$([ -n "$APP2" ] && [ "$APP2" != "$APP" ] && echo yes || echo "no($APP→$APP2)")"
expect "ProseMirror 버전은 그대로" "$PM" "$PM2"
expect "vendor 파일은 한 바이트도 안 바뀌었다" "0" "$(cmp -s "$TMP/view.before" "$TMP/app/public/assets/community/vendor/pm/prosemirror-view.js"; echo $?)"
expect "database.js 도 새 부품 버전으로 templates.js 를 부른다" "1" \
  "$(grep -cF "from './templates.js?v=$APP2'" "$TMP/app/public/assets/community/database.js")"
expect "두 번 찍어도 바뀌는 것 없음" "  (바뀐 파일 없음)" "$(php "$SCRIPT" --root "$TMP/app" 2>&1 | tail -1)"

hr "③ vendor 한 줄을 바꾸면 (임시 사본)"
fresh_copy
printf '\n// 시험용 한 줄\n' >> "$TMP/app/public/assets/community/vendor/pm/orderedmap.js"
php "$SCRIPT" --root "$TMP/app" > /dev/null 2>&1
APP3=$(ver "$TMP/app" app); PM3=$(ver "$TMP/app" pm)
expect "ProseMirror 버전이 바뀌었다" "yes" "$([ "$PM3" != "$PM" ] && echo yes || echo no)"
expect "부품 버전도 따라 바뀌었다(우리 부품이 옛 vendor 주소를 품은 채 캐시에 남지 않게)" "yes" "$([ "$APP3" != "$APP" ] && echo yes || echo no)"
expect "prosemirror-model.js 가 새 버전으로 orderedmap.js 를 부른다" "1" \
  "$(grep -cF "from './orderedmap.js?v=$PM3'" "$TMP/app/public/assets/community/vendor/pm/prosemirror-model.js")"

hr "④ 잡아야 하는 모양"
fresh_copy
printf '\nif (window.x) {\n  import("./doc-render.js");\n}\n' >> "$TMP/app/public/assets/community/popular.js"
out=$(php "$SCRIPT" --check --root "$TMP/app" 2>&1); rc=$?
expect "줄 머리가 아닌 import(동적 import) → 실패" "1" "$rc"
contains "무엇이 문제인지 적는다" "import 문으로 보이지 않는 상대 경로" "$out"
fresh_copy
sed -i "1i import { nope } from './nope.js';" "$TMP/app/public/assets/community/popular.js"
php "$SCRIPT" --root "$TMP/app" > "$TMP/o.txt" 2>&1; rc=$?
expect "없는 파일을 가리키는 import → 찍기도 실패" "1" "$rc"
contains "파일 이름을 적는다" "가리키는 파일이 없다 — ./nope.js" "$(cat "$TMP/o.txt")"

fresh_copy
printf "\nimport { renderDoc as r2 } from './doc-render.js?v=DEADBEEF';\n" >> "$TMP/app/public/assets/community/popular.js"
expect "손으로 쓴 이상한 버전(?v=DEADBEEF) → --check 실패(검토 L-1)" "1" "$(php "$SCRIPT" --check --root "$TMP/app" > /dev/null 2>&1; echo $?)"
php "$SCRIPT" --root "$TMP/app" > /dev/null 2>&1
expect "찍으면 (새) 부품 버전으로 바뀐다" "1" "$(grep -cF "from './doc-render.js?v=$(ver "$TMP/app" app)'" "$TMP/app/public/assets/community/popular.js")"
fresh_copy
printf "\nimport { renderDoc as r3 } from './doc-render.js?v=%s&x=1';\n" "$APP" >> "$TMP/app/public/assets/community/popular.js"
expect "버전 뒤에 다른 질의(&x=1) → --check 실패" "1" "$(php "$SCRIPT" --check --root "$TMP/app" > /dev/null 2>&1; echo $?)"
fresh_copy
printf "\nimport s from './x.json' with { type: 'json' };\n" >> "$TMP/app/public/assets/community/popular.js"
out=$(php "$SCRIPT" --check --root "$TMP/app" 2>&1); rc=$?
expect "JSON 모듈 import → 실패(버전을 못 찍는 모양 — 검토 L-4)" "1" "$rc"
contains "무엇이 문제인지 적는다(JSON)" "./x.json" "$out"

hr "⑤ release.sh — 낡은 표시로는 발행하지 않는다 (임시 클론 · --dry-run --no-push)"
CLONE="$TMP/clone"
if git clone -q "$ROOT" "$CLONE" 2>/dev/null && [ -f "$CLONE/scripts/community_asset_version.php" ]; then
  gitc() { git -C "$CLONE" -c user.name=asset-test -c user.email=asset-test@localhost "$@"; }
  printf '\n// 시험용 한 줄\n' >> "$CLONE/public/assets/community/doc-render.js"
  gitc commit -q -am "시험: 부품만 바꾸고 표시는 그대로"
  out=$(bash "$CLONE/scripts/release.sh" patch "시험 제목" "시험 문장" --dry-run --no-push 2>&1); rc=$?
  expect "낡은 표시 → release.sh 거부" "1" "$rc"
  contains "거부 이유를 적는다" "import 버전 표시가 지금 내용과 다릅니다" "$out"
  php "$CLONE/scripts/community_asset_version.php" > /dev/null 2>&1
  gitc commit -q -am "시험: 다시 찍음"
  out=$(bash "$CLONE/scripts/release.sh" patch "시험 제목" "시험 문장" --dry-run --no-push 2>&1); rc=$?
  expect "다시 찍고 커밋하면 release.sh(--dry-run) 통과" "0" "$rc"
else
  printf '  ✖ 임시 클론에 스크립트가 없다 — 커밋한 뒤에 돌린다\n'; FAIL=$((FAIL+1))
fi

hr "⑥ 셸 · 문법"
expect "셸이 editor.js 를 type=module 로 부른다" "1" "$(grep -c 'type="module" src="<?= Http::url(.\/assets\/community\/editor.js.) ?>?v=' "$ROOT/src/views/shell_top.php")"
expect "php -l scripts/community_asset_version.php" "0" "$(php -l "$SCRIPT" > /dev/null 2>&1; echo $?)"
expect "bash -n asset_version.sh" "0" "$(bash -n "$0"; echo $?)"
expect "bash -n release.sh · vendor_prosemirror.sh" "0" "$(bash -n "$ROOT/scripts/release.sh" && bash -n "$ROOT/scripts/vendor_prosemirror.sh"; echo $?)"
expect "웹으로는 돌지 않는다(SAPI 확인이 있다)" "1" "$(grep -c "PHP_SAPI !== 'cli'" "$SCRIPT")"

printf '\n통과 %d · 실패 %d\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ] || exit 1
exit 0
