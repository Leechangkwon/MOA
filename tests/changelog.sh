#!/usr/bin/env bash
# tests/changelog.sh — 버전 관리·변경 이력 창 수락 기준 실행 (PRD 12.2 T-59, F-47, S-03, S-09, ARCH 5.23)
#
#   (a) 자료   — VERSION 형식 · CHANGELOG.json 유효성 · 첫 항목 = VERSION · 등급 · 발행 시각 형식
#                · 버전 내림차순 · 문장에 `<` 0 · CHANGELOG.md 가 생성기 출력과 byte 동일
#   (b) 화면   — 로그인한 셸에 버전 단추 1개(`v<VERSION>`)와 <dialog id="changelog-dialog"> 1개
#                · 첫 항목 제목·등급 표기 ; **로그인 전** /login 과 /api/health · /api/time 에는 0
#   (c) 발행   — --bump 계산(1.2.3 기준) · 더러운 트리 거부 · 태그 중복 거부 · 커밋 제목 형식
#   (d) 브라우저 — 1440px 에서 단추 클릭 → 모달 열림 · 첫 항목 보임 · ESC 로 닫힘 · 스크린샷
#   (e) 린트·문법 — security_lint 0건 · 바뀐 PHP php -l · bash -n · tests/help_button.sh 회귀
#
# 규칙 (CLAUDE.md 3장)
#   · 검토 계정(claude_ 접두)만 쓴다. 시드 계정(admin01 …)은 사용자의 자기 공격 전용이다.
#   · 모든 요청에 `-A "tbox-review/<날짜>"` 를 붙인다.
#   · **데이터를 하나도 만들지 않는다** — 정적 검사와 GET 뿐이라 지울 것도 되돌릴 것도 없다.
#   · 로그인은 이 시험 안에서 **한 번만** 한다 (같은 30초 창의 OTP 재사용은 거부되는 것이 정상 — R-45).
#   · 작업 트리를 건드리지 않는다 — 「더러운 트리 거부」는 임시 클론 안에서만 시험하고 곧 지운다.
#
# 준비물: sudo(로 /etc/tbox-v2 읽기), php, curl, git, python3+playwright
#
# 사용: sudo -v && bash tests/changelog.sh [출력 폴더]
#   출력 폴더 기본값은 ~/.claude/ui-shots/changelog (루트 디스크를 쓰지 않는다).
set -u

BASE="http://127.0.0.1:8082/tbox"
UA="tbox-review/$(date +%F)"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
. "$ROOT/tests/lib/secrets.sh" || { echo "tests/lib/secrets.sh 를 읽지 못했다 — 시험을 시작하지 않는다 (R-62)"; exit 3; }
OUT="${1:-$HOME/.claude/ui-shots/changelog}"
WORK="$(mktemp -d)"
JAR="$WORK/claude_admin01.jar"
PASS=0
FAIL=0

mkdir -p "$OUT"
cleanup() { rm -rf "$WORK"; }
trap cleanup EXIT

hr()  { printf '\n════════════════════════════════════════════════════════════\n%s\n════════════════════════════════════════════════════════════\n' "$1"; }
sub() { printf '\n── %s\n' "$1"; }

# $1 설명  $2 기대  $3 실제
expect() {
  if [ "$2" = "$3" ]; then
    printf '  ✔ %s  (기대=%s 실제=%s)\n' "$1" "$2" "$3"; PASS=$((PASS+1))
  else
    printf '  ✖ %s  (기대=%s 실제=%s)\n' "$1" "$2" "$3"; FAIL=$((FAIL+1))
  fi
}

# ── 로그인 (한 번만) ──────────────────────────────────────────
# TOTP 는 1회성이라(R-45) 같은 창에서 두 번 쓰면 거부되는 것이 정상 — 다음 창까지 기다렸다 다시 한다.
login_once() {
  local who="$1" pw sec raw code left out try body
  pw=$(sudo -n awk -v u="$who" '$1==u{print $3}' /etc/tbox-v2/seed-accounts.txt)
  sec=$(sudo -n awk -v u="$who" '$1==u{print $2}' /etc/tbox-v2/qa-totp.txt)
  for try in 1 2 3; do
    raw=$(totp_now sec)
    code=${raw%%$'\n'*}
    printf -v body '{"username":"%s","password":"%s","otp":"%s"}' "$who" "$pw" "$code"
    out=$(curl_json_secret body -s -c "$JAR" -A "$UA" -H 'Content-Type: application/json' "$BASE/api/login")
    case "$out" in *'"ok":true'*) return 0 ;; esac
    left=$(printf '%s' "$raw" | sed -n 's/^남은 시간: \([0-9]*\)초.*/\1/p')
    [ -n "$left" ] || left=31
    printf '  · %s 코드 재사용으로 거부됨 — 다음 창까지 %s초 기다림 (R-45 가 의도대로 막은 것)\n' "$who" "$left" >&2
    sleep $((left + 1))
  done
  printf '  ! %s 로그인 실패: %s\n' "$who" "$out" >&2
  return 1
}

# ─────────────────────────────────────────────────────────────
hr "(a) 자료 — VERSION · CHANGELOG.json · CHANGELOG.md"
# ─────────────────────────────────────────────────────────────
php -r '
$root = $argv[1];
$ver  = trim((string) @file_get_contents($root . "/VERSION"));
echo "VERSION=", $ver, "\n";
echo "VERSION_OK=", preg_match("/^\d+\.\d+\.\d+$/", $ver) === 1 ? 1 : 0, "\n";

$raw  = @file_get_contents($root . "/CHANGELOG.json");
$list = $raw === false ? null : json_decode($raw, true);
echo "JSON_OK=", (is_array($list) && $list !== []) ? 1 : 0, "\n";
$list = is_array($list) ? $list : [];
echo "COUNT=", count($list), "\n";
echo "FIRST_MATCH=", (isset($list[0]["version"]) && $list[0]["version"] === $ver) ? 1 : 0, "\n";
echo "FIRST_TITLE=", isset($list[0]["title"]) ? (string) $list[0]["title"] : "", "\n";
echo "FIRST_LEVEL=", isset($list[0]["level"]) ? (string) $list[0]["level"] : "", "\n";

$badLevel = 0; $badDate = 0; $lt = 0; $desc = 1; $prev = null;
foreach ($list as $e) {
    if (!in_array($e["level"] ?? "", ["major", "minor", "patch"], true)) { $badLevel++; }
    if (preg_match("/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/", (string) ($e["released_at"] ?? "")) !== 1) { $badDate++; }
    $texts = array_merge([(string) ($e["title"] ?? "")], array_map("strval", (array) ($e["changes"] ?? [])));
    foreach ($texts as $t) { $lt += substr_count($t, "<"); }
    $v = (string) ($e["version"] ?? "");
    if ($prev !== null && version_compare($prev, $v, "<=")) { $desc = 0; }
    $prev = $v;
}
echo "BAD_LEVEL=", $badLevel, "\nBAD_DATE=", $badDate, "\nLT=", $lt, "\nDESC=", $desc, "\n";

require $root . "/src/Changelog.php";
echo "ENTRIES=", count(Changelog::entries()), "\n";
echo "LABEL=", Changelog::levelLabel((string) ($list[0]["level"] ?? "")), "\n";
' "$ROOT" > "$WORK/a.txt" 2>&1
cat "$WORK/a.txt"

VER=$(sed -n 's/^VERSION=//p' "$WORK/a.txt")
FIRST_TITLE=$(sed -n 's/^FIRST_TITLE=//p' "$WORK/a.txt")
LABEL=$(sed -n 's/^LABEL=//p' "$WORK/a.txt")
COUNT=$(sed -n 's/^COUNT=//p' "$WORK/a.txt")

sub "형식"
expect "VERSION 이 X.Y.Z"                1 "$(sed -n 's/^VERSION_OK=//p' "$WORK/a.txt")"
expect "CHANGELOG.json 이 유효한 배열"    1 "$(sed -n 's/^JSON_OK=//p' "$WORK/a.txt")"
expect "첫 항목 version = VERSION"        1 "$(sed -n 's/^FIRST_MATCH=//p' "$WORK/a.txt")"
expect "등급이 major|minor|patch 밖 0건"  0 "$(sed -n 's/^BAD_LEVEL=//p' "$WORK/a.txt")"
expect "released_at 형식 어긋남 0건"      0 "$(sed -n 's/^BAD_DATE=//p' "$WORK/a.txt")"
expect "버전이 최신부터 엄격히 내림차순"  1 "$(sed -n 's/^DESC=//p' "$WORK/a.txt")"
expect "제목·변경 문장에 '<' 0"           0 "$(sed -n 's/^LT=//p' "$WORK/a.txt")"
expect "Changelog::entries() 가 버린 항목 0건" "$COUNT" "$(sed -n 's/^ENTRIES=//p' "$WORK/a.txt")"

sub "CHANGELOG.md 가 생성기 출력과 byte 동일"
if php "$ROOT/scripts/changelog_md.php" 2>"$WORK/a-md.err" | diff -q - "$ROOT/CHANGELOG.md" > "$WORK/a-md.txt" 2>&1; then
  expect "php scripts/changelog_md.php | diff - CHANGELOG.md" 0 0
else
  cat "$WORK/a-md.err" "$WORK/a-md.txt"; expect "php scripts/changelog_md.php | diff - CHANGELOG.md" 0 1
fi
sub "생성기가 결정적인가 (두 번 돌려 같은 바이트)"
php "$ROOT/scripts/changelog_md.php" > "$WORK/md1" 2>/dev/null
php "$ROOT/scripts/changelog_md.php" > "$WORK/md2" 2>/dev/null
expect "두 번 출력이 같음" 0 "$(cmp -s "$WORK/md1" "$WORK/md2" && echo 0 || echo 1)"

# ─────────────────────────────────────────────────────────────
hr "(b) 화면 — 로그인한 셸 · 로그인 전 · health · time"
# ─────────────────────────────────────────────────────────────
sub "claude_admin01 로그인 (이 시험에서 한 번만)"
if login_once claude_admin01; then
  expect "로그인 성공" 0 0
else
  expect "로그인 성공" 0 1
  printf '  (로그인 없이 이어가면 (b)·(d) 는 의미가 없다 — 아래 결과를 그대로 본다)\n'
fi

sub "셸 화면 /"
curl -s -o "$WORK/home.html" -w '%{http_code}' -b "$JAR" -A "$UA" "$BASE/" > "$WORK/home.code"
expect "GET / 200" 200 "$(cat "$WORK/home.code")"
expect "data-changelog-open 단추 1개"     1 "$(grep -o 'data-changelog-open' "$WORK/home.html" | wc -l)"
expect "단추 문구 v$VER"                  1 "$(grep -o ">v$VER</button>" "$WORK/home.html" | wc -l)"
expect "<dialog id=\"changelog-dialog\" 1개" 1 "$(grep -o '<dialog id="changelog-dialog"' "$WORK/home.html" | wc -l)"
expect "첫 항목 제목이 보임"              1 "$(grep -cF -- "$FIRST_TITLE" "$WORK/home.html")"
expect "등급 표기 「$LABEL」"             1 "$(grep -o "<span class=\"badge\">$LABEL</span>" "$WORK/home.html" | wc -l)"
expect "모달 안 innerHTML 계열 0"         0 "$(grep -c 'innerHTML' "$WORK/home.html")"

sub "다른 화면에도 같은 단추 하나 (셸을 쓰는 모든 화면)"
curl -s -o "$WORK/activity.html" -b "$JAR" -A "$UA" "$BASE/activity"
expect "/activity 단추 1개" 1 "$(grep -o 'data-changelog-open' "$WORK/activity.html" | wc -l)"

sub "로그인 전 /login — 쿠키 없이"
curl -s -o "$WORK/login.html" -w '%{http_code}' -A "$UA" "$BASE/login" > "$WORK/login.code"
expect "GET /login 200"        200 "$(cat "$WORK/login.code")"
expect "changelog 문자열 0건"  0   "$(grep -o 'changelog' "$WORK/login.html" | wc -l)"
expect "v$VER 0건"             0   "$(grep -o "v$VER" "$WORK/login.html" | wc -l)"

sub "/api/health · /api/time 본문에 버전 0건"
curl -s -o "$WORK/health.json" -A "$UA" "$BASE/api/health"
curl -s -o "$WORK/time.json"   -A "$UA" "$BASE/api/time"
printf '  health: %s\n  time  : %s\n' "$(head -c 200 "$WORK/health.json")" "$(head -c 200 "$WORK/time.json")"
expect "/api/health 에 $VER 0건" 0 "$(grep -o "$VER" "$WORK/health.json" | wc -l)"
expect "/api/time 에 $VER 0건"   0 "$(grep -o "$VER" "$WORK/time.json" | wc -l)"

sub "새 라우트 없음 (public/index.php 손대지 않음)"
expect "index.php 에 changelog 0건" 0 "$(grep -c 'changelog' "$ROOT/public/index.php")"
expect "login_page.php 에 Changelog 0건" 0 "$(grep -c 'Changelog\|changelog' "$ROOT/src/handlers/login_page.php")"

# ─────────────────────────────────────────────────────────────
hr "(c) 발행 스크립트 — scripts/release.sh"
# ─────────────────────────────────────────────────────────────
sub "번호 계산 (--bump 1.2.3)"
expect "major → 2.0.0" "2.0.0" "$(bash "$ROOT/scripts/release.sh" --bump 1.2.3 major)"
expect "minor → 1.3.0" "1.3.0" "$(bash "$ROOT/scripts/release.sh" --bump 1.2.3 minor)"
expect "patch → 1.2.4" "1.2.4" "$(bash "$ROOT/scripts/release.sh" --bump 1.2.3 patch)"

sub "금지 패턴 · 커밋 제목 형식"
# 「전체 추가」 옵션이 스크립트 안에 문자열로도 없어야 한다 (CLAUDE.md 6장)
expect "스크립트에 전체 추가 옵션 0건" 0 "$(grep -c -- 'git add -A' "$ROOT/scripts/release.sh")"
expect "스테이징이 세 파일 지정" 1 "$(grep -c "git -C \"\$ROOT\" add VERSION CHANGELOG.json CHANGELOG.md" "$ROOT/scripts/release.sh")"
expect "커밋 제목이 vX.Y.Z: 제목" 1 "$(grep -c "printf 'v%s: %s" "$ROOT/scripts/release.sh")"
expect "마지막 줄 문구 「발행 완료」" 1 "$(grep -c "발행 완료: v%s" "$ROOT/scripts/release.sh")"

sub "임시 클론에서 — 더러운 트리 거부 · 태그 중복 거부 (작업 트리는 건드리지 않는다)"
CLONE="$WORK/clone"
if git clone -q "$ROOT" "$CLONE" 2>"$WORK/clone.err"; then
  # 이번 작업의 파일들은 아직 커밋 전이라 클론에 없다 — 복사해 넣는다 (추적되지 않는 파일이므로 트리는 깨끗하다)
  cp "$ROOT/VERSION" "$ROOT/CHANGELOG.json" "$ROOT/CHANGELOG.md" "$CLONE/"
  cp "$ROOT/scripts/release.sh" "$ROOT/scripts/changelog_md.php" "$CLONE/scripts/"
  cp "$ROOT/src/Changelog.php" "$CLONE/src/"

  bash "$CLONE/scripts/release.sh" patch "시험 제목" "시험 문장" --dry-run --no-push > "$WORK/c-clean.txt" 2>&1
  rc_clean=$?
  printf '  ── 깨끗한 트리의 --dry-run 출력 ──\n'; sed 's/^/  /' "$WORK/c-clean.txt"
  expect "깨끗한 트리에서 --dry-run 종료 0" 0 "$rc_clean"
  expect "다음 번호 1.0.1 을 알림" 1 "$(grep -c '다음 버전 : 1.0.1' "$WORK/c-clean.txt")"
  expect "--dry-run 이 VERSION 을 건드리지 않음" "$VER" "$(tr -d '[:space:]' < "$CLONE/VERSION")"
  expect "--dry-run 뒤에도 트리가 깨끗" 0 "$(git -C "$CLONE" status --porcelain | grep -vc '^??')"

  printf '\n' >> "$CLONE/README.md"     # 추적되는 파일을 고쳐 「더러운 트리」를 만든다
  bash "$CLONE/scripts/release.sh" patch "시험 제목" "시험 문장" --dry-run > "$WORK/c-dirty.txt" 2>&1
  rc_dirty=$?
  printf '  ── 더러운 트리 출력 ──\n'; sed 's/^/  /' "$WORK/c-dirty.txt"
  expect "더러운 트리면 종료 코드 ≠0" "yes" "$([ "$rc_dirty" -ne 0 ] && echo yes || echo "no($rc_dirty)")"
  expect "「먼저 커밋하세요」 안내" 1 "$(grep -c '먼저 커밋하세요' "$WORK/c-dirty.txt")"

  git -C "$CLONE" checkout -q -- README.md
  git -C "$CLONE" tag v1.0.1
  bash "$CLONE/scripts/release.sh" patch "시험 제목" "시험 문장" --dry-run > "$WORK/c-tag.txt" 2>&1
  rc_tag=$?
  printf '  ── 태그 중복 출력 ──\n'; sed 's/^/  /' "$WORK/c-tag.txt"
  expect "이미 있는 태그면 종료 코드 ≠0" "yes" "$([ "$rc_tag" -ne 0 ] && echo yes || echo "no($rc_tag)")"
  expect "「태그 v1.0.1 가 이미 있습니다」" 1 "$(grep -c '태그 v1.0.1 가 이미 있습니다' "$WORK/c-tag.txt")"

  sub "인자 검사"
  bash "$CLONE/scripts/release.sh" patch "제목만" --dry-run > "$WORK/c-nochange.txt" 2>&1
  rc_nochange=$?
  sed 's/^/  /' "$WORK/c-nochange.txt"
  expect "변경 문장 0개면 거부" "yes" "$([ "$rc_nochange" -ne 0 ] && echo yes || echo "no($rc_nochange)")"
  bash "$CLONE/scripts/release.sh" patch "제목에 < 가 있다" "문장" --dry-run > "$WORK/c-lt.txt" 2>&1
  rc_lt=$?
  sed 's/^/  /' "$WORK/c-lt.txt"
  expect "제목에 '<' 가 있으면 거부" "yes" "$([ "$rc_lt" -ne 0 ] && echo yes || echo "no($rc_lt)")"

  rm -rf "$CLONE"
  expect "임시 클론 삭제" "no" "$([ -d "$CLONE" ] && echo yes || echo no)"
else
  cat "$WORK/clone.err"
  expect "임시 클론 생성" 0 1
fi

# ─────────────────────────────────────────────────────────────
hr "(d) 브라우저 — 1440px (Playwright, channel=chromium)"
# ─────────────────────────────────────────────────────────────
# 쿠키 jar → name=value 목록 (scripts/ui_snapshot.sh 와 같은 방식. 로그인은 위에서 한 번 했다)
COOKIES=$(awk '(!/^#/ || /^#HttpOnly_/) && NF>=7 {printf "%s=%s;", $6, $7}' "$JAR")
cat > "$WORK/shot.py" <<'PY'
import json, sys
from playwright.sync_api import sync_playwright

BASE, UA, OUT, VER, TITLE = sys.argv[1:6]
COOKIES = sys.stdin.readline().rstrip('\n')   # 세션 쿠키는 표준입력 첫 줄 — 인자는 ps 에 보인다(R-62 · py_secret)
res = {}
with sync_playwright() as p:
    b = p.chromium.launch(channel='chromium')   # headless_shell 대신 정식 빌드(디스크 절약)
    ctx = b.new_context(viewport={"width": 1440, "height": 900}, user_agent=UA, locale="ko-KR")
    ctx.add_cookies([{"name": kv.split("=", 1)[0], "value": kv.split("=", 1)[1], "url": BASE}
                     for kv in COOKIES.split(";") if "=" in kv])
    page = ctx.new_page()
    errors = []
    page.on("console", lambda m: errors.append(m.text[:160]) if m.type == "error" else None)
    page.on("pageerror", lambda e: errors.append("pageerror: " + str(e)[:160]))
    page.goto(BASE + "/", wait_until="networkidle")
    btn = page.locator("[data-changelog-open]")
    res["btn_count"] = btn.count()
    res["btn_text"] = (btn.first.inner_text() if btn.count() else "").strip()
    res["btn_visible"] = btn.first.is_visible() if btn.count() else False
    res["open_before"] = page.locator("#changelog-dialog[open]").count()
    page.screenshot(path=OUT + "/shell-1440.png", full_page=True)

    btn.first.click()
    page.wait_for_timeout(500)
    res["open_after_click"] = page.locator("#changelog-dialog[open]").count()
    entry = page.locator("#changelog-dialog .changelog__entry").first
    res["entry_visible"] = entry.is_visible()
    text = (entry.inner_text() or "").strip()
    res["entry_has_version"] = ("v" + VER) in text
    res["entry_has_title"] = TITLE in text
    res["entry_head"] = text.replace("\n", " ⏎ ")[:140]
    page.screenshot(path=OUT + "/changelog-open-1440.png")

    page.keyboard.press("Escape")
    page.wait_for_timeout(500)
    res["open_after_esc"] = page.locator("#changelog-dialog[open]").count()
    res["console_errors"] = errors

    ctx2 = b.new_context(viewport={"width": 1440, "height": 900}, user_agent=UA, locale="ko-KR")
    p2 = ctx2.new_page()
    p2.goto(BASE + "/login", wait_until="networkidle")
    p2.screenshot(path=OUT + "/login-1440.png", full_page=True)
    html = p2.content()
    res["login_version_hits"] = html.count("v" + VER)
    res["login_changelog_hits"] = html.count("changelog")
    res["login_body_version_hits"] = p2.inner_text("body").count("v" + VER)
    b.close()
print(json.dumps(res, ensure_ascii=False))
PY
export PATH="$HOME/.local/bin:$PATH"
if py_secret COOKIES -- python3 "$WORK/shot.py" "$BASE" "$UA" "$OUT" "$VER" "$FIRST_TITLE" > "$WORK/d.json" 2>"$WORK/d.err"; then
  cat "$WORK/d.json"; echo
  jget() { php -r '$j = json_decode(file_get_contents($argv[1]), true); $v = $j[$argv[2]] ?? null; echo is_bool($v) ? ($v ? "true" : "false") : (is_array($v) ? count($v) : (string) $v);' "$WORK/d.json" "$1"; }
  expect "버전 단추 1개"              1        "$(jget btn_count)"
  expect "단추 문구 v$VER"            "v$VER"  "$(jget btn_text)"
  expect "단추가 보임"                "true"   "$(jget btn_visible)"
  expect "누르기 전에는 닫혀 있음"    0        "$(jget open_before)"
  expect "누르면 모달 open"           1        "$(jget open_after_click)"
  expect "첫 항목이 보임"             "true"   "$(jget entry_visible)"
  expect "첫 항목에 v$VER"            "true"   "$(jget entry_has_version)"
  expect "첫 항목에 제목"             "true"   "$(jget entry_has_title)"
  expect "ESC 로 닫힘"                0        "$(jget open_after_esc)"
  expect "콘솔 오류 0건"              0        "$(jget console_errors)"
  expect "로그인 화면 HTML 에 v$VER 0건"   0   "$(jget login_version_hits)"
  expect "로그인 화면 HTML 에 changelog 0건" 0 "$(jget login_changelog_hits)"
  expect "로그인 화면 본문 글자에 v$VER 0건" 0 "$(jget login_body_version_hits)"
  printf '  첫 항목 앞부분: %s\n' "$(jget entry_head)"
else
  cat "$WORK/d.err"; expect "Playwright 실행" 0 1
fi

# ─────────────────────────────────────────────────────────────
hr "(e) 린트 · 문법 · 회귀"
# ─────────────────────────────────────────────────────────────
CHANGED_PHP="src/Changelog.php src/views/shell_top.php src/views/shell_bottom.php scripts/changelog_md.php"

sub "scripts/security_lint.sh"
if bash "$ROOT/scripts/security_lint.sh" > "$WORK/e-lint.txt" 2>&1; then
  tail -3 "$WORK/e-lint.txt"; expect "금지 패턴 0건" 0 0
else
  cat "$WORK/e-lint.txt"; expect "금지 패턴 0건" 0 1
fi

sub "php -l — 바뀐 PHP 전부"
n_syntax=0
for f in $CHANGED_PHP; do
  php -l "$ROOT/$f" > /dev/null 2>&1 || { php -l "$ROOT/$f"; n_syntax=$((n_syntax+1)); }
done
expect "문법 오류 0건 (4개 파일)" 0 "$n_syntax"

sub "bash -n — scripts/release.sh · tests/changelog.sh"
expect "release.sh 문법" 0 "$(bash -n "$ROOT/scripts/release.sh" 2>&1 >/dev/null && echo 0 || echo 1)"
expect "changelog.sh 문법" 0 "$(bash -n "$ROOT/tests/changelog.sh" 2>&1 >/dev/null && echo 0 || echo 1)"

sub "app.js — innerHTML 계열 0 · 위임 하나"
expect "initChangelogDialog 하나" 1 "$(grep -c '^function initChangelogDialog()' "$ROOT/public/assets/app.js")"
expect "innerHTML 계열 0건" 0 "$(cat "$ROOT/public/assets/app.js" "$ROOT/public/assets/files-page.js" | grep -c 'innerHTML\|outerHTML\|insertAdjacentHTML')"
expect "바뀐 파일에 인라인 style 속성 0" 0 "$(grep -c 'style="' $(for f in $CHANGED_PHP; do echo "$ROOT/$f"; done) | awk -F: '{s+=$2} END {print s+0}')"

sub "회귀 — tests/help_button.sh (모달 뼈대를 함께 쓴다)"
if bash "$ROOT/tests/help_button.sh" > "$WORK/e-help.txt" 2>&1; then
  tail -4 "$WORK/e-help.txt"; expect "help_button.sh 통과" 0 0
else
  tail -40 "$WORK/e-help.txt"; expect "help_button.sh 통과" 0 1
fi

hr "결과"
printf '  통과 %d · 실패 %d\n  출력: %s\n' "$PASS" "$FAIL" "$OUT"
[ "$FAIL" -eq 0 ] || exit 1
