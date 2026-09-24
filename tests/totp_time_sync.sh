#!/usr/bin/env bash
# tests/totp_time_sync.sh — F-42 「생성기 시각 동기화 · 잠금 문구 12자」 수락 기준 실행 (PRD 12.2 T-55)
#
#   (a) GET /api/time — 200 · 본문 키 {now, period} 뿐 · now 가 서버 시각 ±2초 ·
#       `Cache-Control: no-store` · `Access-Control-Allow-Origin: *` ·
#       /api/health 에는 CORS 헤더 없음 · 로그인 없이 됨 · 감사 로그 0행
#   (b) 생성기 소스 정적 검사 — Date.now() 직접 사용은 serverNow()·syncTime() 안에만 ·
#       MIN_PASS = 12 · 화면 문구에 구조 설명 키워드 0
#   (c) Playwright(headless) — 서버에서 연 생성기의 상태 줄·남은 초·자체 점검·콘솔
#       ① 지금 배포된 CSP 그대로 → 「서버와 동기화됨」 (F-42 의 nginx `connect-src 'self'` 가
#          2026-09-15 적용됨 — 그 전에는 fetch 가 막혀 「오프라인」 이 정상이었다)
#       ② CSP 응답 헤더를 벗긴 상태 → 역시 「서버와 동기화됨」 (헤더가 없어도 생성기는 같은 서버만 부른다)
#   (d) Playwright(file://) — 저장된 보정값이 없으면 「오프라인 · PC 시각 사용」 + 주소 입력칸,
#       보정값을 넣어 두고 열면 「저장된 보정값 사용」
#   (e) 시각 일치 실증 — 시험용 임의 비밀 키로 서버 Totp::code 와 생성기 화면의 6자리를
#       같은 스텝에서 3회(스텝 경계 직후·중간·직전) 비교
#   회귀 : scripts/security_lint.sh · php -l · tests/help_button.sh(셸 확인) · 로그인 화면 200
#
# 규칙 (CLAUDE.md 3장)
#   · 로그인하지 않는다. 데이터를 만들지 않는다 — 공개 창구 GET 과 정적 검사, 그리고 브라우저뿐이다.
#   · 실계정의 비밀(users.mfa_secret)은 읽지 않는다. (e)는 이 시험이 그 자리에서 만든 무작위 키를 쓴다.
#     그 키도 비밀 모양이라 명령 인자에 싣지 않는다 — 파이썬·php 에 표준입력으로 준다(tests/lib/secrets.sh · 점검 봇 R15).
#   · 모든 요청에 `-A "tbox-review/<날짜>"` 를 붙인다.
#
# 준비물: curl · php · python3 + playwright(사용자 로컬 설치) · sudo -u postgres psql(감사 로그 확인)
# 사용: bash tests/totp_time_sync.sh [출력 폴더]
set -u

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
. "$ROOT/tests/lib/secrets.sh" || { echo "tests/lib/secrets.sh 를 읽지 못했다 — 시험을 시작하지 않는다 (R-62)"; exit 3; }
OUT="${1:-$HOME/.claude/ui-shots/f42}"
BASE="http://127.0.0.1:8082/tbox"
UA="tbox-review/$(date +%F)"
DB="tbox_re"
GEN="$ROOT/public/assets/totp-generator.html"
WORK="$(mktemp -d)"
PASS=0
FAIL=0

mkdir -p "$OUT"
export PATH="$HOME/.local/bin:$PATH"

psqlq() { sudo -n -u postgres psql -d "$DB" -t -A -c "$1"; }

hr()  { printf '\n════════════════════════════════════════════════════════════\n%s\n════════════════════════════════════════════════════════════\n' "$1"; }
sub() { printf '\n── %s\n' "$1"; }
expect() {
  if [ "$2" = "$3" ]; then printf '  ✔ %s  (기대=%s 실제=%s)\n' "$1" "$2" "$3"; PASS=$((PASS+1))
  else printf '  ✖ %s  (기대=%s 실제=%s)\n' "$1" "$2" "$3"; FAIL=$((FAIL+1)); fi
}

# ════════════════════════════════════════════════════════════
hr "(a) GET /api/time — 공개 창구 하나, 시각과 주기만"

AUDIT_BEFORE=$(psqlq "SELECT count(*) FROM audit_logs;")
CODE=$(curl -s -o "$WORK/time.json" -D "$WORK/time.head" -w '%{http_code}' -A "$UA" "$BASE/api/time")
SRV_NOW=$(date +%s)
echo "  · 응답 본문: $(cat "$WORK/time.json")"
expect "HTTP 상태" "200" "$CODE"

KEYS=$(python3 -c "import json;print(','.join(sorted(json.load(open('$WORK/time.json')))))" 2>/dev/null || echo "파싱실패")
expect "본문 키가 정확히 {now, period}" "now,period" "$KEYS"

NOW=$(python3 -c "import json;d=json.load(open('$WORK/time.json'));print(d.get('now'))" 2>/dev/null || echo 0)
PERIOD=$(python3 -c "import json;d=json.load(open('$WORK/time.json'));print(d.get('period'))" 2>/dev/null || echo 0)
expect "period = 30" "30" "$PERIOD"
expect "now 가 정수" "True" "$(python3 -c "import json;print(isinstance(json.load(open('$WORK/time.json')).get('now'), int))")"
GAP=$(( NOW > SRV_NOW ? NOW - SRV_NOW : SRV_NOW - NOW ))
echo "  · 서버 date +%s = $SRV_NOW · 응답 now = $NOW · 차이 ${GAP}초"
expect "now 가 서버 시각 ±2초" "yes" "$([ "$GAP" -le 2 ] && echo yes || echo no)"

expect "Cache-Control: no-store" "1" "$(grep -ci '^Cache-Control: no-store' "$WORK/time.head" | head -1)"
expect "Access-Control-Allow-Origin: *" "1" "$(grep -ci '^Access-Control-Allow-Origin: \*' "$WORK/time.head")"
expect "Access-Control-Allow-Methods: GET" "1" "$(grep -ci '^Access-Control-Allow-Methods: GET' "$WORK/time.head")"

curl -s -o /dev/null -D "$WORK/health.head" -A "$UA" "$BASE/api/health"
expect "/api/health 에는 CORS 헤더 없음" "0" "$(grep -ci '^Access-Control-' "$WORK/health.head")"
curl -s -o /dev/null -D "$WORK/login.head" -A "$UA" "$BASE/login"
expect "/login 에도 CORS 헤더 없음" "0" "$(grep -ci '^Access-Control-' "$WORK/login.head")"

# 쿠키를 하나도 보내지 않은 요청이 위에서 200 이었다 = 로그인 불필요
expect "로그인 없이 200" "200" "$CODE"
# 다른 메서드는 라우트 표대로 405
expect "POST /api/time → 405" "405" "$(curl -s -o /dev/null -w '%{http_code}' -X POST -A "$UA" "$BASE/api/time")"

AUDIT_AFTER=$(psqlq "SELECT count(*) FROM audit_logs;")
TIME_ROWS=$(psqlq "SELECT count(*) FROM audit_logs WHERE detail::text LIKE '%/api/time%';")
echo "  · audit_logs 총 행수 $AUDIT_BEFORE → $AUDIT_AFTER (이 서버는 사용 중이라 다른 행이 늘 수 있다)"
expect "감사 로그에 /api/time 행 0" "0" "$TIME_ROWS"

# ════════════════════════════════════════════════════════════
hr "(b) 생성기 소스 정적 검사"

python3 - "$GEN" > "$WORK/static.txt" <<'PYEOF'
import json, re, sys

src = open(sys.argv[1], encoding='utf-8').read()

# 1) Date.now() 가 나오는 함수 이름 — serverNow()·syncTime() 밖이면 실패
lines = src.split('\n')
owner, bad = None, []
for i, line in enumerate(lines):
    m = re.search(r'function\s+([A-Za-z0-9_]+)\s*\(', line)
    if m:
        owner = m.group(1)
    if 'Date.now()' in line:
        bad.append('%d행(%s)' % (i + 1, owner)) if owner not in ('serverNow', 'syncTime') else None
print('date_now_outside=' + (','.join(bad) if bad else '0'))
print('date_now_total=%d' % src.count('Date.now()'))

# 2) MIN_PASS
m = re.search(r'var\s+MIN_PASS\s*=\s*(\d+)', src)
print('min_pass=' + (m.group(1) if m else '없음'))

# 3) 화면 문구에 구조 설명 키워드가 있나
#    화면 문구 = ① <script> 밖 HTML 텍스트(주석·태그 제외) ② 스크립트 안에서 한글이 들어간 문자열 리터럴
KEY = re.compile(r'RFC|HMAC|PBKDF2|PRD|R-\d|F-1\d')
HANGUL = re.compile(r'[가-힣]')
head, _, rest = src.partition('<script>')
body, _, tail = rest.rpartition('</script>')
html = re.sub(r'<!--.*?-->', ' ', head + tail, flags=re.S)
html = re.sub(r'<[^>]+>', ' ', html)
hits = [t.strip() for t in html.split('\n') if KEY.search(t)]
for lit in re.findall(r"'((?:[^'\\]|\\.)*)'", body):
    if HANGUL.search(lit) and KEY.search(lit):
        hits.append(lit)
print('screen_keyword_hits=%d' % len(hits))
for h in hits:
    print('   · ' + h[:120])
PYEOF
cat "$WORK/static.txt"
expect "Date.now() 직접 사용이 serverNow()·syncTime() 밖" "0" "$(sed -n 's/^date_now_outside=//p' "$WORK/static.txt")"
expect "MIN_PASS" "12" "$(sed -n 's/^min_pass=//p' "$WORK/static.txt")"
expect "화면 문구의 구조 설명 키워드" "0" "$(sed -n 's/^screen_keyword_hits=//p' "$WORK/static.txt")"
expect "잠금 문구 안내 문구" "1" "$(grep -c "자 이상이어야 합니다. 단어 2~3개를 권합니다." "$GEN")"
expect "인라인 이벤트 속성(on…=) 0" "0" "$(grep -cE '<[^>]+ on[a-z]+=' "$GEN")"
expect "생성기가 부르는 주소는 api/time 하나" "1" "$(grep -c "fetch(openedAsFile() ? base + '/api/time' : '../api/time'" "$GEN")"
expect "fetch 호출은 1곳뿐" "1" "$(grep -c 'fetch(' "$GEN")"
expect "저장 키 tbox-totp-offset" "1" "$(grep -c "OFFSET_KEY = 'tbox-totp-offset'" "$GEN")"

# ════════════════════════════════════════════════════════════
hr "(c) Playwright — 서버에서 연 생성기 (CSP 그대로 / CSP 벗긴 상태)"

cat > "$WORK/server_page.py" <<'PYEOF'
import json, re, sys, time
from playwright.sync_api import sync_playwright

BASE, OUT, MODE = sys.argv[1], sys.argv[2], sys.argv[3]   # MODE: 'csp' | 'nocsp'
URL = BASE + '/assets/totp-generator.html'
SECRET = sys.stdin.readline().rstrip('\n')   # 시험용 비밀 키는 표준입력 첫 줄(py_secret) — 명령 인자에 싣지 않는다
out = {'mode': MODE, 'console': [], 'pageerror': []}

with sync_playwright() as p:
    b = p.chromium.launch(channel='chromium', args=['--no-sandbox'])
    page = b.new_page()
    page.on('console', lambda m: out['console'].append(m.type + ': ' + m.text) if m.type == 'error' else None)
    page.on('pageerror', lambda e: out['pageerror'].append(str(e)))

    if MODE == 'nocsp':
        # nginx 에 connect-src 'self' 를 적용한 뒤와 같은 상태를 흉내 낸다 — 응답의 CSP 헤더만 벗긴다.
        def strip(route):
            r = route.fetch()
            h = {k: v for k, v in r.headers.items() if k.lower() != 'content-security-policy'}
            route.fulfill(response=r, headers=h)
        page.route(URL, strip)

    page.goto(URL)
    page.wait_for_timeout(2500)
    out['status'] = page.locator('#utc').inner_text()
    out['base_row_visible'] = page.locator('#base-row').is_visible()

    # 남은 초 — 비밀 키를 넣고 코드 계산을 시작한 뒤 화면 값과 서버 시계를 나란히 읽는다
    page.fill('#secret', SECRET)
    page.click('#apply')
    page.wait_for_timeout(1200)
    left_text = page.locator('#left').inner_text()
    now = time.time()
    out['left_text'] = left_text
    m = re.search(r'(\d+)초', left_text)
    out['left'] = int(m.group(1)) if m else -1
    out['left_expected'] = 30 - int(now) % 30
    out['code'] = page.locator('#code').inner_text()
    out['status_after'] = page.locator('#utc').inner_text()
    page.screenshot(path=OUT + '/c-' + MODE + '-status.png', full_page=True)

    # 자체 점검
    page.click('#selftest')
    page.wait_for_timeout(9000)
    out['selftest'] = page.locator('#testout').inner_text()
    page.screenshot(path=OUT + '/c-' + MODE + '-selftest.png', full_page=True)
    b.close()

print(json.dumps(out, ensure_ascii=False))
PYEOF

# 시험용 임의 비밀 키 — 이 자리에서 만든다(실계정의 mfa_secret 은 읽지 않는다)
TEST_SECRET=$(php -r '
require "'"$ROOT"'/src/Totp.php";
echo Totp::generateSecret(), PHP_EOL;')
echo "  · 시험용 임의 비밀 키를 만들었다 (${#TEST_SECRET}자 · 이번 실행에서만 쓰고 버린다 · 값은 찍지 않는다)"

for MODE in csp nocsp; do
  if [ "$MODE" = csp ]; then sub "① 지금 배포된 CSP 그대로 — connect-src 'self' 적용 뒤(F-42, 2026-09-15)라 「서버와 동기화됨」 이 정상"
  else sub "② CSP 응답 헤더를 벗긴 상태 — nginx 에 connect-src 'self' 를 적용한 뒤와 같다"; fi
  if py_secret TEST_SECRET -- python3 "$WORK/server_page.py" "$BASE" "$OUT" "$MODE" > "$WORK/c-$MODE.json" 2>"$WORK/c-$MODE.err"; then
    STATUS=$(python3 -c "import json;print(json.load(open('$WORK/c-$MODE.json'))['status'])")
    STATUS2=$(python3 -c "import json;print(json.load(open('$WORK/c-$MODE.json'))['status_after'])")
    LEFT=$(python3 -c "import json;print(json.load(open('$WORK/c-$MODE.json'))['left'])")
    LEFT_E=$(python3 -c "import json;print(json.load(open('$WORK/c-$MODE.json'))['left_expected'])")
    SELF=$(python3 -c "import json;print(json.load(open('$WORK/c-$MODE.json'))['selftest'].replace(chr(10),' | '))")
    ERRC=$(python3 -c "import json;d=json.load(open('$WORK/c-$MODE.json'));print(len(d['console']))")
    ERRP=$(python3 -c "import json;d=json.load(open('$WORK/c-$MODE.json'));print(len(d['pageerror']))")
    ERRT=$(python3 -c "import json;d=json.load(open('$WORK/c-$MODE.json'));print(' / '.join(d['console']+d['pageerror'])[:300])")
    ROW=$(python3 -c "import json;print(json.load(open('$WORK/c-$MODE.json'))['base_row_visible'])")
    echo "  · 상태 줄      : $STATUS"
    echo "  · 코드 계산 뒤 : $STATUS2"
    echo "  · 남은 초      : 화면 $LEFT · 서버 기준 $LEFT_E"
    echo "  · 자체 점검    : $SELF"
    echo "  · 콘솔 오류 $ERRC · 페이지 오류 $ERRP  $ERRT"
    expect "서버에서 열면 주소 입력칸 없음" "False" "$ROW"
    DIFF=$(( LEFT > LEFT_E ? LEFT - LEFT_E : LEFT_E - LEFT ))
    [ "$DIFF" -gt 15 ] && DIFF=$(( 30 - DIFF ))   # 30↔0 경계를 사이에 둔 경우
    if [ "$MODE" = csp ]; then
      expect "① 상태 줄이 「서버와 동기화됨」 (nginx CSP 에 connect-src 'self' 적용됨, F-42)" "yes" "$(case "$STATUS" in 서버와\ 동기화됨*) echo yes;; *) echo no;; esac)"
      expect "① 페이지 오류 0" "0" "$ERRP"
    else
      expect "② 상태 줄이 「서버와 동기화됨」" "yes" "$(case "$STATUS" in 서버와\ 동기화됨*) echo yes;; *) echo no;; esac)"
      expect "② 남은 초가 30-(서버 now%30) ±1" "yes" "$([ "$DIFF" -le 1 ] && echo yes || echo no)"
      expect "② 자체 점검 9/9 통과" "yes" "$(case "$SELF" in *"9/9 통과"*) echo yes;; *) echo no;; esac)"
      expect "② 콘솔 오류 0" "0" "$ERRC"
      expect "② 페이지 오류 0" "0" "$ERRP"
    fi
  else
    echo "  ✖ Playwright 실행 실패"; sed 's/^/    /' "$WORK/c-$MODE.err"; FAIL=$((FAIL+1))
  fi
done

# ════════════════════════════════════════════════════════════
hr "(d) Playwright — 파일(file://)로 연 생성기"

cat > "$WORK/file_page.py" <<'PYEOF'
import json, sys
from playwright.sync_api import sync_playwright

PATH, OUT, SEED = sys.argv[1], sys.argv[2], sys.argv[3]   # SEED: 'none' | 'offset'
out = {'seed': SEED, 'console': [], 'pageerror': []}

with sync_playwright() as p:
    b = p.chromium.launch(channel='chromium', args=['--no-sandbox'])
    page = b.new_page()
    page.on('console', lambda m: out['console'].append(m.type + ': ' + m.text) if m.type == 'error' else None)
    page.on('pageerror', lambda e: out['pageerror'].append(str(e)))
    if SEED == 'offset':
        # 이전에 동기화해 둔 것처럼 보정값을 미리 넣어 둔다 (12분 전 · +7초)
        page.add_init_script("""
          try { localStorage.setItem('tbox-totp-offset', JSON.stringify(
            { offset: 7000, at: Date.now() - 12 * 60 * 1000, base: '' })); } catch (e) {}
        """)
    page.goto('file://' + PATH)
    page.wait_for_timeout(2000)
    out['status'] = page.locator('#utc').inner_text()
    out['base_row_visible'] = page.locator('#base-row').is_visible()
    out['base_label'] = page.locator('#base-row label').inner_text()
    page.screenshot(path=OUT + '/d-file-' + SEED + '.png', full_page=True)
    b.close()

print(json.dumps(out, ensure_ascii=False))
PYEOF

for SEED in none offset; do
  sub "file:// · 저장된 보정값 $([ "$SEED" = none ] && echo 없음 || echo 있음)"
  if python3 "$WORK/file_page.py" "$GEN" "$OUT" "$SEED" > "$WORK/d-$SEED.json" 2>"$WORK/d-$SEED.err"; then
    STATUS=$(python3 -c "import json;print(json.load(open('$WORK/d-$SEED.json'))['status'])")
    ROW=$(python3 -c "import json;print(json.load(open('$WORK/d-$SEED.json'))['base_row_visible'])")
    LABEL=$(python3 -c "import json;print(json.load(open('$WORK/d-$SEED.json'))['base_label'])")
    ERRP=$(python3 -c "import json;print(len(json.load(open('$WORK/d-$SEED.json'))['pageerror']))")
    echo "  · 상태 줄   : $STATUS"
    echo "  · 주소 입력칸: $ROW ($LABEL)"
    if [ "$SEED" = none ]; then
      expect "상태 「오프라인 · PC 시각 사용」" "yes" "$(case "$STATUS" in *"PC 시각 사용"*) echo yes;; *) echo no;; esac)"
      expect "주소 입력칸 보임" "True" "$ROW"
      expect "입력칸 안내 문구" "yes" "$(case "$LABEL" in *"t-box 주소"*) echo yes;; *) echo no;; esac)"
    else
      expect "상태 「저장된 보정값 사용」" "yes" "$(case "$STATUS" in *"저장된 보정값 사용"*) echo yes;; *) echo no;; esac)"
    fi
    expect "페이지 오류 0" "0" "$ERRP"
  else
    echo "  ✖ Playwright 실행 실패"; sed 's/^/    /' "$WORK/d-$SEED.err"; FAIL=$((FAIL+1))
  fi
done

# ════════════════════════════════════════════════════════════
hr "(e) 시각 일치 실증 — 서버 Totp 와 생성기 화면의 6자리"

cat > "$WORK/match.py" <<'PYEOF'
import json, sys, time
from playwright.sync_api import sync_playwright

BASE, OUT = sys.argv[1], sys.argv[2]
SECRET = sys.stdin.readline().rstrip('\n')   # 시험용 비밀 키는 표준입력 첫 줄(py_secret) — 명령 인자에 싣지 않는다
URL = BASE + '/assets/totp-generator.html'
out = {'rows': [], 'pageerror': []}

def strip(route):
    r = route.fetch()
    h = {k: v for k, v in r.headers.items() if k.lower() != 'content-security-policy'}
    route.fulfill(response=r, headers=h)

with sync_playwright() as p:
    b = p.chromium.launch(channel='chromium', args=['--no-sandbox'])
    page = b.new_page()
    page.on('pageerror', lambda e: out['pageerror'].append(str(e)))
    page.route(URL, strip)               # 동기화된 상태에서 본다
    page.goto(URL)
    page.wait_for_timeout(2500)
    out['status'] = page.locator('#utc').inner_text()
    page.fill('#secret', SECRET)
    page.click('#apply')
    page.wait_for_timeout(1500)

    # 스텝(30초) 안의 자리별로 세 번 읽는다: 경계 직후 · 중간 · 직전
    for want in (1, 15, 28):
        while int(time.time()) % 30 != want:
            time.sleep(0.2)
        time.sleep(0.6)                  # 화면이 1초마다 갱신되므로 조금 기다린다
        code = page.locator('#code').inner_text()
        now = time.time()
        out['rows'].append({'at': int(now), 'pos': int(now) % 30, 'step': int(now) // 30, 'code': code,
                            'left': page.locator('#left').inner_text()})
        if want == 15:
            page.screenshot(path=OUT + '/e-match.png', full_page=True)
    b.close()

print(json.dumps(out, ensure_ascii=False))
PYEOF

if py_secret TEST_SECRET -- python3 "$WORK/match.py" "$BASE" "$OUT" > "$WORK/e.json" 2>"$WORK/e.err"; then
  echo "  · 상태 줄: $(python3 -c "import json;print(json.load(open('$WORK/e.json'))['status'])")"
  N=$(python3 -c "import json;print(len(json.load(open('$WORK/e.json'))['rows']))")
  i=0
  while [ "$i" -lt "$N" ]; do
    ROW=$(python3 -c "
import json
r = json.load(open('$WORK/e.json'))['rows'][$i]
print(r['at'], r['pos'], r['step'], r['code'], r['left'])")
    set -- $ROW
    AT=$1; POS=$2; STEP=$3; SHOWN=$4
    # 시험용 비밀 키는 표준입력 첫 줄로 준다(py_secret) — php 인자는 코드·시각만
    SRVCODE=$(py_secret TEST_SECRET -- php -r '
require "'"$ROOT"'/src/Totp.php";
$sec = rtrim((string) fgets(STDIN), "\r\n");
echo Totp::code($sec, (int) $argv[1]), PHP_EOL;' "$AT")
    OKSTEP=$(py_secret TEST_SECRET -- php -r '
require "'"$ROOT"'/src/Totp.php";
$sec = rtrim((string) fgets(STDIN), "\r\n");
$s = Totp::verifyStep($sec, $argv[1], 0, (int) $argv[2]);
echo $s === null ? "null" : $s, PHP_EOL;' "$SHOWN" "$AT")
    echo "  · 스텝 안 ${POS}초 지점(step $STEP) — 화면 $SHOWN · 서버 $SRVCODE"
    expect "스텝 $STEP 에서 화면 = 서버 코드" "$SRVCODE" "$SHOWN"
    expect "서버 verifyStep(창 0) 이 같은 스텝으로 인정" "$STEP" "$OKSTEP"
    i=$((i+1))
  done
  expect "페이지 오류 0" "0" "$(python3 -c "import json;print(len(json.load(open('$WORK/e.json'))['pageerror']))")"
else
  echo "  ✖ Playwright 실행 실패"; sed 's/^/    /' "$WORK/e.err"; FAIL=$((FAIL+1))
fi

# ════════════════════════════════════════════════════════════
hr "회귀"

sub "scripts/security_lint.sh"
if bash "$ROOT/scripts/security_lint.sh" > "$WORK/lint.txt" 2>&1; then
  expect "금지 패턴 0건" "0" "0"
else
  grep '✖' "$WORK/lint.txt" | sed 's/^/    /'; expect "금지 패턴 0건" "0" "1"
fi

sub "php -l (바뀐 PHP)"
LINT_FAIL=0
for f in "$ROOT/src/handlers/time.php" "$ROOT/public/index.php"; do
  php -l "$f" > /dev/null 2>&1 || { echo "    ✖ $f"; LINT_FAIL=1; }
done
expect "문법 오류 0" "0" "$LINT_FAIL"

sub "셸 문법 확인"
bash -n "$ROOT/tests/help_button.sh" && expect "tests/help_button.sh -n" "0" "$?"
bash -n "$0" && expect "이 시험 파일 -n" "0" "$?"

sub "로그인 화면"
expect "GET /login 200" "200" "$(curl -s -o /dev/null -w '%{http_code}' -A "$UA" "$BASE/login")"
expect "GET /assets/totp-generator.html 200" "200" "$(curl -s -o /dev/null -w '%{http_code}' -A "$UA" "$BASE/assets/totp-generator.html")"

# ════════════════════════════════════════════════════════════
hr "결과"
echo "  통과 $PASS · 실패 $FAIL"
echo "  스크린샷: $OUT"
rm -rf "$WORK"
[ "$FAIL" -eq 0 ] || exit 1
