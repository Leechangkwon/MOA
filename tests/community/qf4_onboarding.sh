#!/usr/bin/env bash
# tests/community/qf4_onboarding.sh — Q:F-4 U-5 「새로 만들어지는 『시작하기』 페이지 문구」 수락 시험
#
#   ① 개인 공간이 없는 검토 계정으로 첫 진입 → 본문에 「/ 를」「굵게」「기울임」「다음에 열립」 0건,
#      「블록 추가」·「Ctrl+K」·알림·「t-box」 첨부 안내 있음
#   ② 본문이 `Doc::validate` 통과(지금 스키마 단계 — COMMUNITY_DOC_STAGE) — 만든 창구가 이미 검사하고,
#      여기서는 코드의 welcomeDoc() 과 **DB 에 저장된 본문**을 CLI 로 다시 검사한다
#   ③ 이미 있던 「시작하기」 페이지의 doc_version·updated_at 불변(이 시험 전후 대조 — qf4a.sh 는 맨 처음·맨 끝으로도 대조)
#   + 새 「시작하기」 페이지 라이트·다크·390px 스크린샷
#
# 부르는 쪽: tests/qf4a.sh (임시 인스턴스 · 임시 DB). 그쪽이 로그인해 600 헤더 파일과 환경 변수를 넘긴다.
#   QF4_DB · QF4_CBASE · QF4_SITE · QF4_UA · QF4_ROOT · QF4_SHOTS · QF4_NEW_WHO · QF4_NEW_HDR · QF4_NEW_SESSION · QF4_OLD_HDR
# 혼자 돌리면(환경 변수가 없으면) 틀인 tests/qf4a.sh 를 onboarding 모드로 대신 부른다 — 운영 DB·8082 에는 닿지 않는다.
# 나가는 값: 0 통과 · 1 실패 (마지막 줄 `ONB_FAIL=<개수>`)
if [ -z "${QF4_DB:-}" ]; then
  exec bash "$(cd "$(dirname "$0")/../.." && pwd)/tests/qf4a.sh" onboarding "${1:-}"
fi
case "$QF4_DB" in tbox_re|"") echo "임시 DB 이름이 아니다 — 중단"; exit 3 ;; esac
ROOT="$QF4_ROOT"
UA="$QF4_UA"
CBASE="$QF4_CBASE"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
PASS=0
FAIL=0

expect() {
  if [ "$2" = "$3" ]; then printf '  ✔ %s  (기대=%s 실제=%s)\n' "$1" "$2" "$3"; PASS=$((PASS+1))
  else printf '  ✖ %s  (기대=%s 실제=%s)\n' "$1" "$2" "$3"; FAIL=$((FAIL+1)); fi
}
note() { printf '  · %s\n' "$1"; }
psqlq() { sudo -n -u postgres psql -d "$QF4_DB" -t -A -c "$1" 2>/dev/null; }
jget() {
  python3 -c '
import json,sys
try:
    d = json.load(sys.stdin)
except Exception:
    print(""); sys.exit(0)
try:
    v = eval(sys.argv[1])
except Exception:
    v = ""
print("" if v is None else v)
' "$1" 2>/dev/null
}

snap() { psqlq "SELECT id || '|' || doc_version || '|' || updated_at FROM community.pages WHERE title = '시작하기' ORDER BY id"; }

echo "── ③ 먼저 — 이미 있는 「시작하기」 페이지를 찍어 둔다"
snap > "$WORK/before.txt"
note "이미 있는 「시작하기」 $(wc -l < "$WORK/before.txt" | tr -d ' ')장"

echo "── ① $QF4_NEW_WHO(개인 공간 없음) 첫 진입 — GET /community/api/me/space"
expect "첫 진입 전 $QF4_NEW_WHO 의 개인 공간 0개" "0" \
  "$(psqlq "SELECT count(*) FROM community.spaces s JOIN users u ON u.id = s.owner_user_id WHERE s.kind = 'personal' AND u.username = '$QF4_NEW_WHO'")"
code=$(curl -s -o "$WORK/space.json" -w '%{http_code}' -A "$UA" -H @"$QF4_NEW_HDR" "$CBASE/api/me/space")
expect "GET /community/api/me/space → 200" "200" "$code"
expect "처음 만들어졌다(created=true)" "True" "$(jget "d['created']" < "$WORK/space.json")"
START=$(jget "d['start_page']['id']" < "$WORK/space.json")
expect "「시작하기」 페이지가 함께 만들어졌다" "yes" "$([ -n "$START" ] && echo yes || echo no)"
code=$(curl -s -o "$WORK/page.json" -w '%{http_code}' -A "$UA" -H @"$QF4_NEW_HDR" "$CBASE/api/pages/$START")
expect "GET /community/api/pages/{시작하기} → 200" "200" "$code"
# 본문의 글자만 뽑는다(블록 JSON 의 t 값들) — 화면이 보이는 글자 그대로
python3 - "$WORK/page.json" > "$WORK/text.txt" <<'PY'
import json, sys
d = json.load(open(sys.argv[1], encoding="utf-8"))
doc = (d.get("page") or d).get("doc") or {}
out = []
def walk(blocks):
    for b in blocks or []:
        for key in ("text", "caption"):
            for seg in b.get(key) or []:
                if isinstance(seg, dict) and isinstance(seg.get("t"), str):
                    out.append(seg["t"])
        walk(b.get("children"))
walk(doc.get("blocks"))
print("\n".join(out))
PY
note "본문 $(wc -l < "$WORK/text.txt" | tr -d ' ')줄"
for bad in "/ 를" "굵게" "기울임" "다음에 열립"; do
  expect "본문에 「$bad」 0건" "0" "$(grep -c -F -- "$bad" "$WORK/text.txt")"
done
for good in "블록 추가" "Ctrl+K" "알림" "자료실"; do
  expect "본문에 「$good」 있음" "yes" "$(grep -q -F -- "$good" "$WORK/text.txt" && echo yes || echo no)"
done
expect "자료실 첨부 안내(「파일」·「그림」으로 자료실 파일을 붙인다)" "yes" \
  "$(grep -F -- '자료실' "$WORK/text.txt" | grep -q -F '붙' && echo yes || echo no)"

echo "── ② 본문이 Doc::validate 를 지난다 (지금 스키마 단계)"
STAGE=$(sed -n "s/^const COMMUNITY_DOC_STAGE = \([0-9]*\);.*/\1/p" "$ROOT/src/bootstrap.php")
note "COMMUNITY_DOC_STAGE=$STAGE"
psqlq "SELECT doc::text FROM community.pages WHERE public_id = '$START'" > "$WORK/stored.json"
res=$(php -r '
require $argv[1] . "/src/community/Doc.php";
$stage = (int) $argv[2];
$code = Doc::validate(Doc::welcomeDoc(), $stage);
$stored = json_decode((string) file_get_contents($argv[3]), true);
$db = is_array($stored) ? Doc::validate($stored, $stage) : ["ok" => false];
echo ($code["ok"] ? "ok" : "bad") . "|" . ($db["ok"] ? "ok" : "bad");
' "$ROOT" "$STAGE" "$WORK/stored.json" 2>&1)
expect "코드의 welcomeDoc() · DB 에 저장된 본문 모두 통과" "ok|ok" "$res"
expect "저장된 판 번호는 1(새로 만든 것)" "1" "$(psqlq "SELECT doc_version FROM community.pages WHERE public_id = '$START'")"

echo "── ②-손님 읽기만 하는 계정의 「시작하기」(대기열 10번 A.5, 2026-09-24)"
res=$(php -r '
require $argv[1] . "/src/community/Doc.php";
$d = Doc::welcomeDocReadOnly();
$ok = Doc::validate($d, (int) $argv[2])["ok"] ? "ok" : "bad";
$text = Doc::extract($d)["plain_text"] ?? "";
if ($text === "") { foreach ($d["blocks"] as $b) { foreach (($b["text"] ?? []) as $r) { $text .= ($r["t"] ?? "") . "\n"; } } }
file_put_contents($argv[3], $text);
echo $ok;
' "$ROOT" "$STAGE" "$WORK/guest.txt" 2>&1)
expect "welcomeDocReadOnly() 가 Doc::validate 통과" "ok" "$res"
for bad in "「편집」" "「게시」" "새 페이지를" "공개 범위" "블록 추가"; do
  expect "  손님 판에 「$bad」 0건" "0" "$(grep -c -F -- "$bad" "$WORK/guest.txt")"
done
expect "  손님 판에 「읽기만」 안내 있음" "yes" "$(grep -q -F -- "읽기만" "$WORK/guest.txt" && echo yes || echo no)"
expect "  space_me.php 가 쓸 수 없는 계정에 손님 판을 고른다" "1" \
  "$(grep -c 'Authz::canCommunityWrite($user) ? Doc::welcomeDoc() : Doc::welcomeDocReadOnly()' "$ROOT/src/community/handlers/space_me.php")"

echo "── ③ 이미 공간이 있는 계정(claude_employee01)이 다시 들어와도 새로 만들지 않는다"
code=$(curl -s -o "$WORK/old.json" -w '%{http_code}' -A "$UA" -H @"$QF4_OLD_HDR" "$CBASE/api/me/space")
expect "GET /community/api/me/space → 200 · created=false" "200|False" "$code|$(jget "d['created']" < "$WORK/old.json")"
code=$(curl -s -o /dev/null -w '%{http_code}' -A "$UA" -H @"$QF4_NEW_HDR" "$CBASE/api/me/space")
expect "새 계정이 두 번째로 들어와도 200 · 공간 1개 그대로" "200|1" \
  "$code|$(psqlq "SELECT count(*) FROM community.spaces s JOIN users u ON u.id = s.owner_user_id WHERE s.kind = 'personal' AND u.username = '$QF4_NEW_WHO'")"
snap | grep -v -F -- "$(psqlq "SELECT id FROM community.pages WHERE public_id = '$START'")|" > "$WORK/after.txt"
expect "이미 있던 「시작하기」 doc_version·updated_at 불변($(wc -l < "$WORK/before.txt" | tr -d ' ')장)" \
  "$(md5sum < "$WORK/before.txt" | cut -c1-12)" "$(md5sum < "$WORK/after.txt" | cut -c1-12)"

echo "── 새 「시작하기」 페이지 — 라이트·다크·390px 스크린샷 (직접 본다)"
if [ -n "${QF4_SHOTS:-}" ] && python3 -c 'import playwright' 2>/dev/null; then
  SHOT_SESSION="$QF4_NEW_SESSION" python3 - "$QF4_SITE" "$UA" "$START" "$QF4_SHOTS" > "$WORK/shots.txt" 2>&1 <<'PY'
import os, re, sys
from playwright.sync_api import sync_playwright
site, ua, page_id, shots = sys.argv[1].rstrip("/"), sys.argv[2], sys.argv[3], sys.argv[4]
host = re.match(r"https?://([^/:]+)", site).group(1)
bad = []
with sync_playwright() as p:
    b = p.chromium.launch()
    for theme, w, h in (("light", 1440, 900), ("dark", 1440, 900), ("light", 390, 844), ("dark", 390, 844)):
        ctx = b.new_context(user_agent=ua, viewport={"width": w, "height": h})
        ctx.add_cookies([{"name": "__Host-tbox_session", "value": os.environ["SHOT_SESSION"], "domain": host, "path": "/", "httpOnly": True, "secure": True},
                         {"name": "tbox_theme", "value": theme, "domain": host, "path": "/"}])
        pg = ctx.new_page()
        pg.on("pageerror", lambda e: bad.append("pageerror " + str(e)[:120]))
        pg.on("console", lambda m: bad.append("console " + m.text[:120]) if m.type == "error" and "Failed to load resource" not in m.text else None)
        pg.on("response", lambda r: bad.append("%d %s" % (r.status, r.url)) if r.status >= 400 and host in r.url else None)
        pg.goto(site + "/community/p/" + page_id, wait_until="networkidle")
        pg.wait_for_timeout(500)
        over = pg.evaluate("() => document.documentElement.scrollWidth - innerWidth")
        if over > 1:
            bad.append("%s %d 가로 넘침 %dpx" % (theme, w, over))
        pg.screenshot(path=os.path.join(shots, "qf4a-u5-start-%s-%d.png" % (theme, w)), full_page=True)
        ctx.close()
    b.close()
print("SHOT_BAD=%d" % len(bad))
for x in bad[:5]:
    print("  " + x)
PY
  sed 's/^/    /' "$WORK/shots.txt"
  expect "스크린샷 넷 — pageerror·console.error·HTTP≥400·가로 넘침 0" "0" "$(sed -n 's/^SHOT_BAD=//p' "$WORK/shots.txt")"
else
  note "스크린샷 폴더 또는 playwright 가 없어 찍지 않았다"
fi

printf '통과 %d · 실패 %d\n' "$PASS" "$FAIL"
echo "ONB_FAIL=$FAIL"
[ "$FAIL" -eq 0 ] || exit 1
exit 0
