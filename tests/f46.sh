#!/usr/bin/env bash
# tests/f46.sh — F-46 수락 기준 실행 (PRD 12.2 T-58)
#   (a) 생성기 이름·매니페스트 링크·홈 화면 안내       (b) 웹 매니페스트 자체
#   (c) 휴대폰(390×844)·데스크톱(1440) 화면 — Playwright
#   (d) CloudLink 단위 (SharePoint 변환 · 기존 12케이스 회귀)
#   (e) Fetch 단위 (쿠키 모으기 · 공급자 내려받기 호스트 · redirectStep)
#   (f) 실링크 1회씩 — SharePoint 공개 링크 · 구글 시트 공개 링크
#   (g) 린트 · php -l
#
# 규칙 (CLAUDE.md 3장)
#   · **검토 계정(qa_ 접두)만** 쓴다. 시드 계정(admin01 …)은 사용자의 자기 공격 전용이다.
#   · 모든 요청에 `-A "tbox-review/<날짜>"` 를 붙인다.
#   · 비밀번호·TOTP 비밀은 **필요한 필드만** awk 로 뽑고 변수에만 담는다 (SECURITY R-62).
#
# ★ 시험 안전 (SECURITY R-73)
#   ① 최근 10분 감사 로그에 비-qa actor 가 하나라도 있으면 **시작하지 않는다**(종료 코드 3).
#   ② (f) 는 **바깥으로 나가는 유일한 부분**이다. 주소는 사용자가 실제로 쓰다 막힌 두 개뿐이고
#      (SharePoint·구글 시트), 각각 **1회씩만** 가져온다. 허용 목록은 이미 관리자가 넣어 두었다.
#   ③ (f) 가 저장한 파일은 qa_manager01 이 **휴지통으로만** 보낸다. psql 로 지우지 않는다
#      (30일 보존 규칙, R-73). 되살릴 수 있는 상태로 남는 것이 정상이다.
#   ④ `fetch_hosts` 표를 한 줄도 바꾸지 않는다.
#
# 준비물: sudo(로 /etc/tbox-v2 읽기 · psql), php, curl, python3+playwright
#
# 사용: sudo -v && bash tests/f46.sh [출력 폴더]
#   출력 폴더 기본값은 ~/.claude/ui-shots/f46 (루트 디스크를 쓰지 않는다).
set -u

BASE="http://127.0.0.1:8082"
UA="tbox-review/$(date +%F)"
DB="tbox_re"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="${1:-$HOME/.claude/ui-shots/f46}"
JAR_DIR="$(mktemp -d)"
PASS=0
FAIL=0

GEN_URL="$BASE/assets/totp-generator.html"
MAN_URL="$BASE/assets/totp-generator.webmanifest"

# (f) 의 실링크 — 사용자가 실제로 쓰다 막힌 주소 두 개 (지시서 0장 · 조정자 추가 지시)
SP_URL='https://planlabs.sharepoint.com/:x:/s/msteams_672b97/IQAy2n1qrkKVRb4jJOQQ_wzwASpmY4gFDOMItKxYxWQ0VWY?e=hdg0gi'
SP_URL_NOE='https://planlabs.sharepoint.com/:x:/s/msteams_672b97/IQAy2n1qrkKVRb4jJOQQ_wzwASpmY4gFDOMItKxYxWQ0VWY'
SP_URL_FAKE='https://planlabs.sharepoint.com/:x:/s/msteams_672b97/IQAyZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZ?e=hdg0gi'
GS_URL='https://docs.google.com/spreadsheets/d/1BWaqMDq088bdTYoXMZA7py7t1qiqCNrVM0cS_-6YhwI/edit?usp=sharing'

mkdir -p "$OUT"

psqlq() { sudo -n -u postgres psql -d "$DB" -t -A -c "$1"; }

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

# ── 로그인 ────────────────────────────────────────────────────
# TOTP 코드는 1회성이다(R-45) — 같은 창에서 두 번 쓰면 거부되는 것이 **정상**이라 기다렸다 다시 한다.
login() {
  local who="$1" jar="$JAR_DIR/$1.jar" pw sec raw code left out try
  pw=$(sudo -n awk -v u="$who" '$1==u{print $3}' /etc/tbox-v2/seed-accounts.txt)
  sec=$(sudo -n awk -v u="$who" '$1==u{print $2}' /etc/tbox-v2/qa-totp.txt)
  for try in 1 2 3; do
    raw=$(php "$ROOT/scripts/totp_code.php" "$sec")
    code=${raw%%$'\n'*}
    out=$(curl -s -c "$jar" -A "$UA" -H 'Content-Type: application/json' \
          -d "{\"username\":\"$who\",\"password\":\"$pw\",\"otp\":\"$code\"}" "$BASE/api/login")
    case "$out" in
      *'"ok":true'*)
        curl -s -b "$jar" -A "$UA" "$BASE/api/me" \
          | sed -n 's/.*"csrf_token":"\([0-9a-f]*\)".*/\1/p'
        return 0 ;;
    esac
    left=$(printf '%s' "$raw" | sed -n 's/^남은 시간: \([0-9]*\)초.*/\1/p')
    [ -n "$left" ] || left=31
    printf '  · %s 코드 재사용으로 거부됨 — 다음 창까지 %s초 기다림 (R-45 가 의도대로 막은 것)\n' "$who" "$left" >&2
    sleep $((left + 1))
  done
  printf '  ! %s 로그인 실패: %s\n' "$who" "$out" >&2
  return 1
}

code_of() { printf '%s' "$1" | tail -1; }
body_of() { printf '%s' "$1" | sed '$d'; }

# ─────────────────────────────────────────────────────────────
hr "0. 시험 안전 점검 (SECURITY R-73)"
# ─────────────────────────────────────────────────────────────
T0=$(psqlq "SELECT now();")
NONQA_BEFORE=$(psqlq "SELECT count(*) FROM audit_logs WHERE happened_at > now() - interval '10 minutes' AND actor_name IS NOT NULL AND actor_name NOT LIKE 'qa\\_%'")
echo "  최근 10분 비-qa 감사 행: $NONQA_BEFORE"
if [ "${NONQA_BEFORE:-1}" != "0" ]; then
  echo "  ✖ 사용자가 지금 서비스를 쓰고 있다 — 시험을 시작하지 않는다 (종료 3)"
  exit 3
fi
echo "  fetch_hosts 활성 행 (이 시험은 한 줄도 바꾸지 않는다):"
psqlq "SELECT host || ' · ' || is_active FROM fetch_hosts ORDER BY host" | sed 's/^/    /'

# ─────────────────────────────────────────────────────────────
hr "(a) 생성기 — 이름 · 매니페스트 링크 · 휴대폰 안내"
# ─────────────────────────────────────────────────────────────
GEN_CODE=$(curl -s -o "$OUT/a-generator.html" -w '%{http_code}' -A "$UA" "$GEN_URL")
expect "생성기 200" 200 "$GEN_CODE"

TITLE=$(sed -n 's:.*<title>\(.*\)</title>.*:\1:p' "$OUT/a-generator.html")
H1=$(sed -n 's:.*<h1>\(.*\)</h1>.*:\1:p' "$OUT/a-generator.html")
echo "    <title> = $TITLE"
echo "    <h1>    = $H1"
expect "title 이 「t-box 2단계 코드 생성기」" "t-box 2단계 코드 생성기" "$TITLE"
expect "h1 이 「t-box 2단계 코드 생성기」"    "t-box 2단계 코드 생성기" "$H1"
expect "title 에 「오프라인」 0회" 0 "$(printf '%s' "$TITLE" | grep -c '오프라인')"
expect "h1 에 「오프라인」 0회"    0 "$(printf '%s' "$H1"    | grep -c '오프라인')"

expect "manifest 링크 1개"  1 "$(grep -c 'rel="manifest" href="totp-generator.webmanifest"' "$OUT/a-generator.html")"
expect "theme-color 1개"    1 "$(grep -c 'name="theme-color"' "$OUT/a-generator.html")"
expect "apple-touch-icon 1개" 1 "$(grep -c 'rel="apple-touch-icon"' "$OUT/a-generator.html")"
expect "「홈 화면에 추가」 안내 1개" 1 "$(grep -c '홈 화면에 추가' "$OUT/a-generator.html")"
expect "@media (max-width: 480px) 있음" 1 "$(grep -c '@media (max-width: 480px)' "$OUT/a-generator.html")"
expect "CSP meta 에 manifest-src 'self'" 1 "$(grep -c "manifest-src 'self'" "$OUT/a-generator.html")"

sub "상태 줄의 「오프라인 · …」(동기화 상태)은 그대로 남아 있어야 한다"
expect "JS 안 '오프라인 · 저장된 보정값 사용' 1" 1 "$(grep -c "오프라인 · 저장된 보정값 사용" "$OUT/a-generator.html")"
expect "JS 안 '오프라인 · PC 시각 사용' 1"       1 "$(grep -c "오프라인 · PC 시각 사용" "$OUT/a-generator.html")"

sub "외부 자원 0 — 아이콘·폰트·스크립트를 밖에서 받아오지 않는다"
expect "http(s):// 로 시작하는 src/href 0건" 0 \
  "$(grep -oE '(src|href)="https?://[^"]*"' "$OUT/a-generator.html" | grep -vc 'www.w3.org/2000/svg')"

sub "「오프라인 코드 생성기」 — 화면 문구·사용자 문서에서 0건"
# 대상은 **지금 보이는 것**이다: 생성기 화면 · 도움말 · 내 계정 화면 · README.
# 과거 기록(STAGES 학습 기록·지난 지시서·wiki 의 지난 단계 글·docs/ui-copy-cleanup.md)과
# 코드 주석(public/index.php · src/handlers/time.php — 이번 지시서의 「수정 파일」 밖)은
# 고치지 않았다. 아래 「남은 자리」에 그대로 센다.
LIVE_HITS=$(grep -c '오프라인 코드 생성기' \
  "$ROOT/public/assets/totp-generator.html" "$ROOT/src/help/screens.php" \
  "$ROOT/src/handlers/settings_page.php" "$ROOT/README.md" 2>/dev/null \
  | awk -F: '{s+=$2} END{print s+0}')
expect "화면 문구·README 에 0건" 0 "$LIVE_HITS"
sub "남은 자리 (기록·주석 — 이번 범위 밖, 고치지 않음)"
grep -rn '오프라인 코드 생성기' "$ROOT" 2>/dev/null | sed 's/:.*//' | sort | uniq -c | sed 's/^/    /'

# ─────────────────────────────────────────────────────────────
hr "(b) 웹 매니페스트"
# ─────────────────────────────────────────────────────────────
MAN_HDR="$OUT/b-manifest-headers.txt"
MAN_CODE=$(curl -s -D "$MAN_HDR" -o "$OUT/b-manifest.json" -w '%{http_code}' -A "$UA" "$MAN_URL")
expect "매니페스트 200" 200 "$MAN_CODE"
MAN_CT=$(sed -n 's/^[Cc]ontent-[Tt]ype: *//p' "$MAN_HDR" | tr -d '\r')
echo "    Content-Type: $MAN_CT"
cat <<NOTE
  · nginx 배포본(/etc/nginx) 적용 **전**에는 Content-Type 이 application/manifest+json 이 아닐 수 있다.
    저장소의 deploy/sites/tbox-v2.conf 에는 전용 location(types { application/manifest+json webmanifest; })
    을 넣어 두었고, /etc/nginx 로 복사·reload 하는 것은 Fable 의 몫이다(지시서).
    Content-Type 이 달라도 크롬은 <link rel="manifest"> 를 읽는다 — 「홈 화면에 추가」 는 동작한다.
NOTE
python3 - "$OUT/b-manifest.json" > "$OUT/b-manifest-check.txt" 2>&1 <<'PY'
import json, sys
d = json.load(open(sys.argv[1], encoding='utf-8'))
src = d['icons'][0]['src']
print('JSON_OK=1')
print('NAME=%s' % d.get('name'))
print('START_URL=%s' % d.get('start_url'))
print('DISPLAY=%s' % d.get('display'))
print('THEME=%s' % d.get('theme_color'))
print('ICON_IS_DATA=%d' % (1 if src.startswith('data:image/svg+xml,') else 0))
print('ICON_HAS_HTTP=%d' % (1 if 'http://' in src.replace('http://www.w3.org/2000/svg','') or 'https://' in src else 0))
print('EXTERNAL_URL=%d' % (1 if 'http' in json.dumps({k: v for k, v in d.items() if k != 'icons'}) else 0))
PY
cat "$OUT/b-manifest-check.txt" | sed 's/^/    /'
expect "JSON 유효"                1 "$(sed -n 's/^JSON_OK=//p' "$OUT/b-manifest-check.txt")"
expect "icons[0].src 가 data:"    1 "$(sed -n 's/^ICON_IS_DATA=//p' "$OUT/b-manifest-check.txt")"
expect "아이콘에 외부 http 주소 0" 0 "$(sed -n 's/^ICON_HAS_HTTP=//p' "$OUT/b-manifest-check.txt")"
expect "start_url 이 생성기 경로" "/assets/totp-generator.html" "$(sed -n 's/^START_URL=//p' "$OUT/b-manifest-check.txt")"
expect "display standalone"       "standalone" "$(sed -n 's/^DISPLAY=//p' "$OUT/b-manifest-check.txt")"

# ─────────────────────────────────────────────────────────────
hr "(c) 화면 — 휴대폰 390×844 · 데스크톱 1440 (Playwright)"
# ─────────────────────────────────────────────────────────────
mkdir -p "$OUT/c-shots"
export PATH="$HOME/.local/bin:$PATH"
python3 - "$GEN_URL" "$UA" "$OUT/c-shots" <<'PY' > "$OUT/c-ui.txt" 2>&1
import json, sys
url, ua, out = sys.argv[1:4]
try:
    from playwright.sync_api import sync_playwright
except Exception as e:
    print('PLAYWRIGHT_MISSING', e); sys.exit(0)

report = {}
with sync_playwright() as p:
    browser = p.chromium.launch(channel='chromium')
    for name, w, h in (('mobile', 390, 844), ('desktop', 1440, 1000)):
        ctx = browser.new_context(viewport={'width': w, 'height': h}, user_agent=ua,
                                  is_mobile=(name == 'mobile'), has_touch=(name == 'mobile'))
        page = ctx.new_page()
        errs = []
        page.on('console', lambda m: errs.append(m.text) if m.type == 'error' else None)
        page.on('pageerror', lambda e: errs.append('pageerror: ' + str(e)))
        page.goto(url, wait_until='networkidle')
        page.wait_for_timeout(600)
        info = page.evaluate("""() => {
          const btns = [...document.querySelectorAll('button')].filter(b => b.offsetParent !== null);
          const cs = getComputedStyle(document.querySelector('.code'));
          return {
            scroll_width: document.documentElement.scrollWidth,
            inner_width: window.innerWidth,
            button_count: btns.length,
            min_button_height: Math.min(...btns.map(b => Math.round(b.getBoundingClientRect().height))),
            code_font_px: parseFloat(cs.fontSize),
            has_manifest_link: !!document.querySelector('link[rel=manifest]'),
            theme_color: (document.querySelector('meta[name=theme-color]') || {}).content || null,
            home_hint: [...document.querySelectorAll('p')].some(p => p.textContent.includes('홈 화면에 추가')),
            status_line: document.getElementById('utc').textContent.trim()
          };
        }""")
        page.screenshot(path=f'{out}/{name}-top.png')
        # 자체 점검 — 9개 확인 (잠금 점검은 60만 회 반복이라 몇 초 걸린다)
        page.click('#selftest')
        page.wait_for_timeout(12000)
        info['selftest'] = page.inner_text('#testout').strip().splitlines()[-1] if page.inner_text('#testout').strip() else ''
        page.screenshot(path=f'{out}/{name}-selftest.png', full_page=True)
        info['console_errors'] = errs
        report[name] = info
        page.close(); ctx.close()
    browser.close()
print(json.dumps(report, ensure_ascii=False, indent=2))
PY
cat "$OUT/c-ui.txt"
if grep -q PLAYWRIGHT_MISSING "$OUT/c-ui.txt"; then
  echo "  ! playwright 가 없어 (c) 를 실행하지 못했다 — 미검증"
else
  q() { python3 -c "
import json,sys
d=json.load(open('$OUT/c-ui.txt'))
v=d['$1']['$2']
print(v if not isinstance(v,bool) else (1 if v else 0))"; }
  MW=$(q mobile scroll_width); MI=$(q mobile inner_width)
  expect "휴대폰 가로 스크롤 없음 (scrollWidth ≤ innerWidth)" 1 "$([ "$MW" -le "$MI" ] && echo 1 || echo 0)"
  expect "휴대폰 버튼 높이 ≥ 44px" 1 "$([ "$(q mobile min_button_height)" -ge 44 ] && echo 1 || echo 0)"
  expect "휴대폰 코드 글자 ≥ 40px" 1 "$(python3 -c "print(1 if $(q mobile code_font_px) >= 40 else 0)")"
  expect "휴대폰 manifest 링크 있음" 1 "$(q mobile has_manifest_link)"
  expect "휴대폰 theme-color"        "#1d5fbf" "$(q mobile theme_color)"
  expect "휴대폰 「홈 화면에 추가」 안내 보임" 1 "$(q mobile home_hint)"
  expect "휴대폰 자체 점검 9/9" 1 "$(python3 -c "
import json;d=json.load(open('$OUT/c-ui.txt'));print(1 if '9/9 통과' in d['mobile']['selftest'] else 0)")"
  DW=$(q desktop scroll_width); DI=$(q desktop inner_width)
  expect "데스크톱 가로 스크롤 없음" 1 "$([ "$DW" -le "$DI" ] && echo 1 || echo 0)"
  expect "데스크톱 자체 점검 9/9" 1 "$(python3 -c "
import json;d=json.load(open('$OUT/c-ui.txt'));print(1 if '9/9 통과' in d['desktop']['selftest'] else 0)")"
  # 매니페스트는 **배포본 CSP 에 manifest-src 가 아직 없어** 거부될 수 있다 — 예상된 것이라 따로 센다.
  expect "예상 밖 콘솔 오류 0건" 0 "$(python3 -c "
import json
d=json.load(open('$OUT/c-ui.txt'))
bad=[e for v in d.values() for e in v['console_errors'] if 'manifest' not in e.lower()]
print(len(bad))
for e in bad: print('     ', e)" | head -20)"
  echo "    (참고) 매니페스트 CSP 거부 메시지는 /etc/nginx 에 manifest-src 를 적용하기 전까지 정상이다:"
  python3 -c "
import json
d=json.load(open('$OUT/c-ui.txt'))
for k,v in d.items():
  for e in v['console_errors']:
    print('      %s: %s' % (k, e[:160]))"
fi

# ─────────────────────────────────────────────────────────────
hr "(d) CloudLink 단위 — SharePoint 변환 · 기존 12케이스 회귀 (네트워크 0)"
# ─────────────────────────────────────────────────────────────
php -r '
require $argv[1];
$id = "1AbC_dEfGhIjKlMnOpQ";
$sp = "https://planlabs.sharepoint.com/:x:/s/msteams_672b97/IQAy2n1q?e=hdg0gi";
$cases = [
  // ── F-46 신규 ──
  [$sp, $sp . "&download=1", "microsoft", false],
  ["https://planlabs.sharepoint.com/:x:/s/a/IQAy", "https://planlabs.sharepoint.com/:x:/s/a/IQAy?download=1", "microsoft", false],
  ["https://contoso-my.sharepoint.com/:x:/g/personal/a/E1", "https://contoso-my.sharepoint.com/:x:/g/personal/a/E1?download=1", "microsoft", false],
  ["https://contoso.sharepoint.com/x?download=1", "https://contoso.sharepoint.com/x?download=1", "microsoft", false],
  ["https://a.b.sharepoint.com.evil.tld/x", "https://a.b.sharepoint.com.evil.tld/x", "", false],
  ["https://sharepoint.com.evil.tld/x", "https://sharepoint.com.evil.tld/x", "", false],
  // ── 기존 12케이스 회귀 (F-43 tests/fetch_hosts.sh (e) 와 같은 표) ──
  ["https://drive.google.com/file/d/$id/view?usp=sharing", "https://drive.google.com/uc?export=download&id=$id", "google", false],
  ["https://drive.google.com/open?id=$id",                 "https://drive.google.com/uc?export=download&id=$id", "google", false],
  ["https://docs.google.com/document/d/$id/edit",          "https://docs.google.com/document/d/$id/export?format=docx", "google", false],
  ["https://docs.google.com/spreadsheets/d/$id/edit",      "https://docs.google.com/spreadsheets/d/$id/export?format=xlsx", "google", false],
  ["https://docs.google.com/presentation/d/$id/edit",      "https://docs.google.com/presentation/d/$id/export?format=pptx", "google", false],
  ["https://1drv.ms/w/s!AbCdEfGh",                          "https://1drv.ms/w/s!AbCdEfGh", "microsoft", false],
  ["https://onedrive.live.com/?resid=ABC&authkey=k",        "https://onedrive.live.com/?resid=ABC&authkey=k&download=1", "microsoft", false],
  ["https://s43-1.example.invalid/a.csv",                   "https://s43-1.example.invalid/a.csv", "", false],
  ["https://drive.google.com/file/d/bad id!/view",          "https://drive.google.com/file/d/bad id!/view", "", false],
  ["https://drive.google.com/file/d/short/view",            "https://drive.google.com/file/d/short/view", "", false],
  ["http://drive.google.com/file/d/$id/view",               "http://drive.google.com/file/d/$id/view", "", false],
  ["https://docs.google.com/document/d/$id/edit#gid=1",     "https://docs.google.com/document/d/$id/export?format=docx", "google", false],
];
$ok = 0; $no = 0;
foreach ($cases as [$in, $wantUrl, $wantProv, $wantLogin]) {
    $r = CloudLink::normalize($in);
    $good = $r["url"] === $wantUrl && (string) $r["provider"] === $wantProv && $r["needs_login"] === $wantLogin;
    printf("  %s %-62s -> %s | %s | %s\n", $good ? "OK  " : "FAIL", substr($in, 0, 62), substr($r["url"], 0, 78), (string) $r["provider"], $r["needs_login"] ? "needs_login" : "-");
    $good ? $ok++ : $no++;
}
echo "CLOUDLINK_OK=$ok\nCLOUDLINK_FAIL=$no\n";
' "$ROOT/src/CloudLink.php" > "$OUT/d-cloudlink.txt" 2>&1
cat "$OUT/d-cloudlink.txt"
expect "CloudLink 단위 실패 0" 0 "$(sed -n 's/^CLOUDLINK_FAIL=//p' "$OUT/d-cloudlink.txt")"
expect "CloudLink 단위 통과 18" 18 "$(sed -n 's/^CLOUDLINK_OK=//p' "$OUT/d-cloudlink.txt")"

# ─────────────────────────────────────────────────────────────
hr "(e) Fetch 단위 — 쿠키 모으기 · 공급자 내려받기 호스트 (네트워크 0)"
# ─────────────────────────────────────────────────────────────
php -r '
require $argv[1];
$ok = 0; $no = 0;
$check = function (string $name, $want, $got) use (&$ok, &$no) {
    $good = $want === $got;
    printf("  %s %-56s 기대=%s 실제=%s\n", $good ? "OK  " : "FAIL", $name,
           is_bool($want) ? var_export($want, true) : "«" . (is_string($want) ? (strlen($want) > 40 ? strlen($want) . "바이트" : $want) : $want) . "»",
           is_bool($got) ? var_export($got, true) : "«" . (is_string($got) ? (strlen($got) > 40 ? strlen($got) . "바이트" : $got) : $got) . "»");
    $good ? $ok++ : $no++;
};

/* ── 쿠키 모으기 ── */
$three = [
  "FedAuth=abc123; Path=/; Secure; HttpOnly; SameSite=None",
  "rtFa=zzz; Domain=.sharepoint.com; Secure; HttpOnly",
  "sess=9; Expires=Wed, 17-Mar-2027 06:03:36 GMT; path=/",
];
$check("Set-Cookie 3개 → 이름=값 3개 (속성 제거)", "FedAuth=abc123; rtFa=zzz; sess=9",
       Fetch::cookieHeader($three, "planlabs.sharepoint.com", "planlabs.sharepoint.com"));
$check("다음 호스트가 다르면 빈 문자열", "",
       Fetch::cookieHeader($three, "planlabs.sharepoint.com", "doc-08.googleusercontent.com"));
$check("직전 호스트가 비면 빈 문자열", "", Fetch::cookieHeader($three, "", ""));
$check("대소문자·끝점 표기는 같은 호스트로 본다", "FedAuth=abc123; rtFa=zzz; sess=9",
       Fetch::cookieHeader($three, "PlanLabs.SharePoint.com.", "planlabs.sharepoint.com"));

$many = [];
for ($i = 1; $i <= 21; $i++) { $many[] = "c$i=v$i; Path=/"; }
$header = Fetch::cookieHeader($many, "h.example.com", "h.example.com");
$check("21개 → 20개까지만", 20, substr_count($header, "=") );
$check("21번째(c21)는 실리지 않는다", false, strpos($header, "c21=") !== false);

$big = [];
for ($i = 1; $i <= 5; $i++) { $big[] = "b$i=" . str_repeat("x", 2000) . "; Path=/"; }
$check("합계 8KB 초과면 자르지 않고 쿠키 없이", "", Fetch::cookieHeader($big, "h.example.com", "h.example.com"));
$check("COOKIE_MAX_PAIRS", 20, Fetch::COOKIE_MAX_PAIRS);
$check("COOKIE_MAX_BYTES", 8192, Fetch::COOKIE_MAX_BYTES);
$check("헤더 주입 시도(값에 개행·세미콜론)는 버린다", "ok=1",
       Fetch::cookieHeader(["bad=a\r\nSet-Cookie: x=y; Path=/", "ok=1; Path=/"], "h.example.com", "h.example.com"));
$check("이름이 없는 줄은 버린다", "", Fetch::cookieHeader(["=novalue; Path=/"], "h.example.com", "h.example.com"));

/* ── 공급자 내려받기 호스트 (회전 도메인) ── */
$check("google + doc-08-9g-sheets.googleusercontent.com", true,
       Fetch::providerHostAllowed("google", "doc-08-9g-sheets.googleusercontent.com"));
$check("google + drive.usercontent.google.com", true,
       Fetch::providerHostAllowed("google", "drive.usercontent.google.com"));
$check("provider 가 null 이면 접미를 무시한다", false,
       Fetch::providerHostAllowed(null, "doc-08-9g-sheets.googleusercontent.com"));
$check("evil.googleusercontent.com.attacker.tld 거부", false,
       Fetch::providerHostAllowed("google", "evil.googleusercontent.com.attacker.tld"));
$check("googleusercontent.com 자체(앞 라벨 없음) 거부", false,
       Fetch::providerHostAllowed("google", "googleusercontent.com"));
$check("microsoft + planlabs.sharepoint.com", true,
       Fetch::providerHostAllowed("microsoft", "planlabs.sharepoint.com"));
$check("microsoft + xyz.files.1drv.com", true,
       Fetch::providerHostAllowed("microsoft", "xyz.files.1drv.com"));
$check("공급자가 어긋나면 거부(google + sharepoint)", false,
       Fetch::providerHostAllowed("google", "planlabs.sharepoint.com"));

/* ── redirectStep 에 공급자를 넘겼을 때 ── */
$allowed = ["docs.google.com", "planlabs.sharepoint.com"];
$r = Fetch::redirectStep("https://doc-08-9g-sheets.googleusercontent.com/export/x?format=xlsx", 1, $allowed, "google");
$check("리다이렉트 1단계: google 회전 도메인 허용", true, $r["ok"]);
$r = Fetch::redirectStep("https://doc-08-9g-sheets.googleusercontent.com/export/x?format=xlsx", 1, $allowed, null);
$check("같은 주소라도 공급자가 없으면 거부", "host_not_allowed", (string) $r["reason"]);
$r = Fetch::redirectStep("https://evil.googleusercontent.com.attacker.tld/x", 1, $allowed, "google");
$check("evil.googleusercontent.com.attacker.tld 거부", "host_not_allowed", (string) $r["reason"]);
$r = Fetch::redirectStep("http://doc-08-9g-sheets.googleusercontent.com/x", 1, $allowed, "google");
$check("회전 도메인이라도 http 면 거부", "scheme", (string) $r["reason"]);
$r = Fetch::redirectStep("https://doc-08-9g-sheets.googleusercontent.com/x", 4, $allowed, "google");
$check("4번째 단계면 거부", "redirect", (string) $r["reason"]);
$r = Fetch::redirectStep("https://planlabs.sharepoint.com/sites/a/b.xlsx?ga=1", 1, $allowed, "microsoft");
$check("SharePoint 같은 호스트 1단계 허용", true, $r["ok"]);

echo "MAX_REDIRECTS=", Fetch::MAX_REDIRECTS, "\nFETCH_OK=$ok\nFETCH_FAIL=$no\n";
' "$ROOT/src/Fetch.php" > "$OUT/e-fetch.txt" 2>&1
cat "$OUT/e-fetch.txt"
expect "Fetch 단위 실패 0" 0 "$(sed -n 's/^FETCH_FAIL=//p' "$OUT/e-fetch.txt")"
expect "최대 3번"          3 "$(sed -n 's/^MAX_REDIRECTS=//p' "$OUT/e-fetch.txt")"

sub "코드 자체 — 통제가 풀리지 않았는가"
expect "CURLOPT_FOLLOWLOCATION => false 한 줄" 1 "$(grep -c 'CURLOPT_FOLLOWLOCATION  => false' "$ROOT/src/Fetch.php")"
expect "FOLLOWLOCATION true 0건"               0 "$(grep -c 'CURLOPT_FOLLOWLOCATION[^,;]*true' "$ROOT/src/Fetch.php")"
expect "리다이렉트 뒤 check() 를 다시 부른다"  1 "$(grep -c 'self::check((string) \$decided\[.url.\], \$provider)' "$ROOT/src/Fetch.php")"
expect "첫 요청의 check() 에는 공급자를 넘기지 않는다" 1 "$(grep -c 'Fetch::check(\$safeInput)' "$ROOT/src/handlers/files_fetch.php")"
expect "쿠키 값이 error_log 로 나가지 않는다"  0 "$(grep -c 'error_log.*cookie' "$ROOT/src/Fetch.php")"
expect "감사 detail 에 cookie_hops 한 줄"      1 "$(grep -c "'cookie_hops' => \\\$cookieHops" "$ROOT/src/handlers/files_fetch.php")"
expect "Audit 예외 목록에 cookie_hops(정수) 등록"  1 "$(grep -c "^        'cookie_hops'," "$ROOT/src/Audit.php")"

# ─────────────────────────────────────────────────────────────
hr "(f) 실링크 — SharePoint · 구글 시트 (qa_manager01, 각 1회)"
# ─────────────────────────────────────────────────────────────
TOK_MGR=$(login qa_manager01) || exit 1
echo "  qa_manager01 로그인 · csrf 토큰 확보"

FETCHED_IDS=""

# $1 이름 $2 URL → 본문+HTTP 코드를 전역 R 에 담는다
do_fetch() {
  curl -s -w '\n%{http_code}' -X POST -b "$JAR_DIR/qa_manager01.jar" -A "$UA" \
    -H 'Content-Type: application/json' -H "X-CSRF-Token: $TOK_MGR" \
    -d "{\"url\":\"$1\",\"access_level\":\"team\",\"team_id\":1,\"description\":\"F-46 시험\"}" \
    "$BASE/api/files/fetch"
}

audit_detail() {
  psqlq "SELECT detail::text FROM audit_logs WHERE action='file.fetch' AND actor_name='qa_manager01' AND happened_at > '$T0'::timestamptz ORDER BY happened_at DESC LIMIT 1"
}

sub "① SharePoint 「링크가 있는 모든 사용자」 링크 (?e= 붙은 원본)"
R=$(do_fetch "$SP_URL")
echo "    HTTP $(code_of "$R") · 본문 $(body_of "$R")"
expect "SharePoint 가져오기 201" 201 "$(code_of "$R")"
SP_ID=$(body_of "$R" | sed -n 's/.*"id":"\([^"]*\)".*/\1/p')
[ -n "$SP_ID" ] && FETCHED_IDS="$FETCHED_IDS $SP_ID"
D=$(audit_detail); echo "    감사 detail: $D"
expect "감사 provider microsoft" 1 "$(printf '%s' "$D" | grep -c '"provider": *"microsoft"')"
expect "감사 cookie_hops 1"      1 "$(printf '%s' "$D" | grep -c '"cookie_hops": *1')"
expect "감사 hops 2개"           2 "$(printf '%s' "$D" | python3 -c "import json,sys;print(len(json.load(sys.stdin).get('hops',[])))")"
expect "감사에 쿠키 값 0"        0 "$(printf '%s' "$D" | grep -ci 'fedauth\|set-cookie\|"cookie"')"
expect "저장된 파일이 xlsx"      1 "$(psqlq "SELECT count(*) FROM files WHERE public_id='$SP_ID' AND ext='xlsx'")"
psqlq "SELECT '    저장: ' || original_name || ' · ' || ext || ' · ' || size_bytes || 'B' FROM files WHERE public_id='$SP_ID'"

sub "② 같은 링크에서 ?e= 를 뗀 것"
sleep 2
R=$(do_fetch "$SP_URL_NOE")
echo "    HTTP $(code_of "$R") · 본문 $(body_of "$R")"
expect "?e= 없는 링크도 201" 201 "$(code_of "$R")"
ID2=$(body_of "$R" | sed -n 's/.*"id":"\([^"]*\)".*/\1/p')
[ -n "$ID2" ] && FETCHED_IDS="$FETCHED_IDS $ID2"

sub "③ 가짜 id 로 바꾼 링크 — needs_login 또는 fetch_failed 여야 한다"
sleep 2
R=$(do_fetch "$SP_URL_FAKE")
echo "    HTTP $(code_of "$R")"
echo "    본문 원문: $(body_of "$R")"
expect "가짜 id → 400" 400 "$(code_of "$R")"
FAKE_REASON=$(psqlq "SELECT reason FROM audit_logs WHERE action='file.fetch' AND result<>'allow' AND actor_name='qa_manager01' AND happened_at > '$T0'::timestamptz ORDER BY happened_at DESC LIMIT 1")
echo "    감사 reason: $FAKE_REASON"
expect "사유가 needs_login 또는 fetch_failed" 1 \
  "$(case "$FAKE_REASON" in needs_login|fetch_failed) echo 1;; *) echo 0;; esac)"

sub "④ 구글 시트 공개 링크 — export 뒤 회전 도메인으로 307 (조정자 추가 지시)"
sleep 2
R=$(do_fetch "$GS_URL")
echo "    HTTP $(code_of "$R") · 본문 $(body_of "$R")"
expect "구글 시트 가져오기 201" 201 "$(code_of "$R")"
GS_ID=$(body_of "$R" | sed -n 's/.*"id":"\([^"]*\)".*/\1/p')
[ -n "$GS_ID" ] && FETCHED_IDS="$FETCHED_IDS $GS_ID"
D=$(audit_detail); echo "    감사 detail: $D"
expect "감사 provider google" 1 "$(printf '%s' "$D" | grep -c '"provider": *"google"')"
expect "감사 hops 2개"        2 "$(printf '%s' "$D" | python3 -c "import json,sys;print(len(json.load(sys.stdin).get('hops',[])))")"
expect "감사 cookie_hops 0 (호스트가 달라 쿠키를 버렸다)" 1 "$(printf '%s' "$D" | grep -c '"cookie_hops": *0')"
expect "저장된 파일이 xlsx"   1 "$(psqlq "SELECT count(*) FROM files WHERE public_id='$GS_ID' AND ext='xlsx'")"
printf '%s' "$D" | python3 -c "import json,sys;print('    hops:', json.load(sys.stdin).get('hops'))"

# ─────────────────────────────────────────────────────────────
hr "정리 — 가져온 파일은 **휴지통으로만** (R-73 · 30일 보존)"
# ─────────────────────────────────────────────────────────────
for fid in $FETCHED_IDS; do
  C=$(curl -s -o /dev/null -w '%{http_code}' -X DELETE -b "$JAR_DIR/qa_manager01.jar" -A "$UA" \
      -H "X-CSRF-Token: $TOK_MGR" "$BASE/api/files/$fid")
  echo "  DELETE /api/files/$fid → $C"
done
expect "가져온 파일이 모두 휴지통에 있다" 0 \
  "$(psqlq "SELECT count(*) FROM files WHERE uploaded_by=(SELECT id FROM users WHERE username='qa_manager01') AND deleted_at IS NULL AND created_at > '$T0'::timestamptz")"
echo "  (psql 로 지우지 않는다 — 휴지통에 남는 것이 정상이다)"

# ─────────────────────────────────────────────────────────────
hr "(g) 정적 검사 — 린트 · php -l"
# ─────────────────────────────────────────────────────────────
if bash "$ROOT/scripts/security_lint.sh" > "$OUT/g-lint.txt" 2>&1; then
  tail -3 "$OUT/g-lint.txt" | sed 's/^/    /'
  expect "린트 0건" 0 0
else
  cat "$OUT/g-lint.txt"; expect "린트 0건" 0 1
fi

CHANGED="src/Fetch.php src/CloudLink.php src/handlers/files_fetch.php
         src/handlers/settings_page.php src/help/screens.php"
LINT_FAIL=0
for f in $CHANGED; do
  php -l "$ROOT/$f" > /dev/null 2>&1 || { php -l "$ROOT/$f"; LINT_FAIL=$((LINT_FAIL+1)); }
done
expect "php -l 실패 0" 0 "$LINT_FAIL"

NONQA_AFTER=$(psqlq "SELECT count(*) FROM audit_logs WHERE happened_at > '$T0'::timestamptz AND actor_name IS NOT NULL AND actor_name NOT LIKE 'qa\\_%'")
echo "  이 실행 중 비-qa 감사 행: $NONQA_AFTER (0 이어야 정상)"

rm -rf "$JAR_DIR"

# ─────────────────────────────────────────────────────────────
hr "결과"
# ─────────────────────────────────────────────────────────────
printf '  통과 %d · 실패 %d\n' "$PASS" "$FAIL"
printf '  스크린샷·출력: %s\n' "$OUT"
printf '  미적용: /etc/nginx 배포본(매니페스트 location · manifest-src) — Fable 이 복사·reload\n'
[ "$FAIL" -eq 0 ] || exit 1
