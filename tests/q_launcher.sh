#!/usr/bin/env bash
# tests/q_launcher.sh — Qaredi C 수락 기준 실행 (사이트 PRD.md 9장 Q:T-1 · 3 · 4 · 5 · 6 · 7 · 8 · 9,
#                        설계 ../docs/launcher/ARCHITECTURE.md, 지시서 ../docs/launcher/c-launcher-basepath-directive.md)
#
#   Q:T-1 런처 `GET /`         200 HTML · 자체 테마 `<script` 1 · `Set-Cookie` 0 · `POST /` 405 ·
#                              미로그인이면 **사이트 이름 + 로그인 단추만**(앱 이름·설명·경로·타일 0 — 디버깅 묶음 S2 · R-98) ·
#                              로그인하면 타일 t-box·커뮤니티(둘 다 링크) + 사용자 배지(표시 이름) · 옛 이름 쿠키면 만료 머리 하나
#   Q:T-3 옛 주소 308          `/api/health` `/login` `POST /api/login` `/s/{43자}` `/settings` `/assets/app.css`
#                              → 308 + `Location: /tbox<경로+쿼리 원문>` · POST 는 처리 0(감사 행 0 · Set-Cookie 0)
#                              · `/foo` `/api/nope` `/tbox/nope` `/community/` → 404(리다이렉트 아님)
#   Q:T-4 링크 접두            렌더된 HTML 의 `href|src|action="/…"` 가 전부 `/tbox/` 로 시작
#                              (예외: 「Qaredi 홈」·커뮤니티 `/community/`) · `app.js` 절대 경로가 전부 `APP_BASE +` 뒤
#   Q:T-5 리다이렉트 경로      미로그인 `/tbox/settings` → 302 `/tbox/login?to=%2Ftbox%2Fsettings`(B1-1) · 재인증 전 `/tbox/admin` → 302 `/tbox/reauth?to=admin`
#   Q:T-6 공유 링크            `POST …/share` 의 `url` 이 `APP_URL/tbox/s/` 로 시작하고 그 주소로 받아진다
#   Q:T-7 쿠키·헤더 불변       `Set-Cookie` 이름 `__Host-tbox_session`(S2) · 속성(`Path=/; Secure; HttpOnly; SameSite=Lax` · Domain 없음) · 전역 CSP 문자열
#   Q:T-8 그리드 단추          셸 화면 전부에 `<details class="appbar__apps">` · 항목 셋 · `.btn--icon[title]` 0
#   Q:T-9 정적 자산            `/tbox/assets/app.css` 200 `text/css` · 생성기 200 + 자체 CSP(`unsafe-inline`) ·
#                              매니페스트 `start_url` `/tbox/…` · 옛 `/assets/app.css` → 308
#
# 규칙 (CLAUDE.md 3장)
#   · 검토 계정은 `claude_employee01`·`claude_admin01` 뿐이다. 시드 계정(admin01 …)은 사용자 몫이다.
#   · 모든 요청에 `-A "tbox-review/<날짜>"` — 감사 로그에서 사람의 행동과 갈라 보려는 것.
#   · 운영 데이터에 손대지 않는다. 만든 것(공유 링크)은 앱 창구로만 되돌린다.
#   · 운영 DB 에 DDL·DML 을 실행하지 않는다 (psql 은 **읽기**만).
#
# ★ 시험 안전 (SECURITY R-73 · R-80): 최근 10분에 비-qa 감사 행이 있으면 기다리고,
#   루트 파티션 여유가 1GB 미만이면 시작하지 않는다 (f68.sh 와 같은 관문).
#
# 준비물: curl · php(TOTP) · python3 · sudo -n(seed-accounts.txt · qa-totp.txt · psql)
# 사용: sudo -v && bash tests/q_launcher.sh
#   ※ 로그인이 필요한 항목은 nginx 설정을 적용한 뒤에 돌린다 — PHP 내장 서버에서는 세션이 서지 않는다.
set -u

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
# 검토 계정 판정 한 벌(qa_·claude_ — F-71 C). 못 읽으면 사전 검사가 빈 값으로 열리므로 시작하지 않는다.
. "$ROOT/tests/lib/review_accounts.sh" || { echo "tests/lib/review_accounts.sh 를 읽지 못했다 — 시험을 시작하지 않는다 (R-73)"; exit 3; }
. "$ROOT/tests/lib/secrets.sh" || { echo "tests/lib/secrets.sh 를 읽지 못했다 — 시험을 시작하지 않는다 (R-62)"; exit 3; }
. "$ROOT/tests/lib/db_read.sh" || { echo "tests/lib/db_read.sh 를 읽지 못했다 — 시험을 시작하지 않는다 (R-73)"; exit 3; }
SITE="http://127.0.0.1:8082"          # 사이트 루트 — 런처와 옛 주소 308 을 보는 자리
BASE="$SITE/tbox"                     # t-box 모듈 (다른 시험의 BASE 와 같은 값)
UA="tbox-review/$(date +%F)"
DB="tbox_re"
JAR_DIR="$(mktemp -d)"
WORK="$(mktemp -d)"
PASS=0
FAIL=0
SKIP=0

MADE_SHARES=""    # 회수할 공유 링크 id

cleanup() { rm -rf "$JAR_DIR" "$WORK"; }
trap cleanup EXIT

hr()  { printf '\n════════════════════════════════════════════════════════════\n%s\n════════════════════════════════════════════════════════════\n' "$1"; }
sub() { printf '\n── %s\n' "$1"; }
expect() {
  if [ "$2" = "$3" ]; then printf '  ✔ %s  (기대=%s 실제=%s)\n' "$1" "$2" "$3"; PASS=$((PASS+1))
  else printf '  ✖ %s  (기대=%s 실제=%s)\n' "$1" "$2" "$3"; FAIL=$((FAIL+1)); fi
}
contains() {   # $1 설명  $2 기대 조각  $3 실제 문자열
  case "$3" in
    *"$2"*) printf '  ✔ %s  (「%s」 포함)\n' "$1" "$2"; PASS=$((PASS+1)) ;;
    *)      printf '  ✖ %s  (「%s」 없음)\n' "$1" "$2"; FAIL=$((FAIL+1)) ;;
  esac
}
note() { printf '  · %s\n' "$1"; }
skip() { printf '  ○ SKIP %s  (%s)\n' "$1" "$2"; SKIP=$((SKIP+1)); }

psqlq() { sudo -n -u postgres psql -d "$DB" -t -A -c "$1" 2>/dev/null; }
mark()  { psqlq "SELECT coalesce(max(id),0) FROM audit_logs" 2>/dev/null; }

code_of() { printf '%s' "$1" | tail -1; }
body_of() { printf '%s' "$1" | sed '$d'; }

jget() {   # 표준입력 = JSON, $1 = python 식 (d 가 파싱 결과)
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

# 응답 머리에서 한 헤더의 값을 꺼낸다 (여러 줄이면 첫 줄)
hdr() {   # $1 헤더 이름  $2 머리 파일
  sed -n "s/^$1: \(.*\)\r*$/\1/Ip" "$2" | head -1 | tr -d '\r'
}
status_of() { sed -n 's#^HTTP/[0-9.]* \([0-9]*\).*#\1#p' "$1" | head -1 | tr -d '\r'; }

qa_password() { sudo -n awk -v u="$1" '$1==u{print $3}' /etc/tbox-v2/seed-accounts.txt 2>/dev/null; }
qa_secret()   { sudo -n awk -v u="$1" '$1==u{print $2}' /etc/tbox-v2/qa-totp.txt 2>/dev/null; }

login() {   # $1 계정 [$2 쿠키 병 이름 — 같은 계정의 두 번째 세션] → 성공하면 표준출력에 csrf 토큰
  local who="$1" jar="$JAR_DIR/${2:-$1}.jar" pw sec raw code left out try body
  pw=$(qa_password "$who")
  sec=$(qa_secret "$who")
  [ -n "$pw" ] && [ -n "$sec" ] || return 2
  for try in 1 2 3; do
    raw=$(totp_now sec)
    code=${raw%%$'\n'*}
    printf -v body '{"username":"%s","password":"%s","otp":"%s"}' "$who" "$pw" "$code"
    out=$(curl_json_secret body -s -c "$jar" -A "$UA" -H 'Content-Type: application/json' "$BASE/api/login")
    case "$out" in
      *'"ok":true'*)
        curl -s -b "$jar" -A "$UA" "$BASE/api/me" \
          | sed -n 's/.*"csrf_token":"\([0-9a-f]*\)".*/\1/p'
        return 0 ;;
    esac
    left=$(printf '%s' "$raw" | sed -n 's/^남은 시간: \([0-9]*\)초.*/\1/p')
    [ -n "$left" ] || left=31
    printf '  · %s 로그인 재시도 — 다음 코드 창까지 %s초 기다림\n' "$who" "$left" >&2
    sleep $((left + 1))
  done
  return 1
}

# 렌더된 HTML 안의 절대 경로 링크 가운데 `/tbox/` 로 시작하지 않는 것을 센다.
#   허용 예외는 「Qaredi 홈」(`/`)과 커뮤니티(`/community/`) 둘뿐이다 (Q:T-4).
bad_links() {   # $1 HTML 파일 → 개수
  grep -oE '(href|src|action)="/[^"]*"' "$1" \
    | sed -E 's/^(href|src|action)="//; s/"$//' \
    | grep -vE '^/tbox/' \
    | grep -vE '^/$' \
    | grep -vE '^/community/' \
    | wc -l | tr -d ' '
}

# ─── 시작 전 관문 ────────────────────────────────────────────────
hr "Qaredi C — 런처 · /tbox base-path · 옛 주소 308 — 시험 시작 전 확인"

avail_kb=$(df -Pk / | awk 'NR==2{print $4}')
echo "루트 파티션 여유: $(df -Ph / | awk 'NR==2{print $4}') (${avail_kb}KB)"
[ "$avail_kb" -ge 1048576 ] || { echo "루트 디스크 여유 1GB 미만 — 시험을 시작하지 않는다 (SECURITY R-80)"; exit 3; }

HAVE_PSQL=0
# Read-only public checks need no operational DB, auth or share creation. Full mode keeps its activity guard.
if [ "${QLAUNCHER_PUBLIC_ONLY:-0}" != "1" ]; then
if psqlq "SELECT 1" >/dev/null 2>&1; then HAVE_PSQL=1; fi

if [ "$HAVE_PSQL" -eq 1 ]; then
  TRY=1
  while :; do
    db_read_var BUSY "$DB" "SELECT count(*) FROM audit_logs WHERE happened_at > now() - interval '10 minutes' AND actor_name IS NOT NULL AND $REVIEW_NOT_SQL" \
      || { echo "운영 DB 를 읽지 못했다 — 시험을 시작하지 않는다 (R-73 · db_read rc 3)"; exit 3; }
    echo "최근 10분 비-qa 감사 행: ${BUSY:-?} (시도 ${TRY}/10) (읽기 경로 ${DB_READ_VIA})"
    [ "${BUSY:-0}" -gt 0 ] 2>/dev/null || break
    if [ "$TRY" -ge 10 ]; then
      echo "10회(약 20분) 기다렸지만 사용자의 활동이 이어진다 — 시험을 시작하지 않는다 (SECURITY R-73 ①)"
      exit 3
    fi
    echo "  … 사용자가 쓰는 중이다. 2분 뒤 다시 본다."
    sleep 120
    TRY=$((TRY+1))
  done
else
  echo "psql 을 쓸 수 없다 — DB·감사 확인 항목은 SKIP 된다"
fi

AUDIT_START=0
# 끝의 「시험 요청이 만든 비-qa 행 0건」 대조의 시작 값 — 읽지 못하면 시작하지 않는다(R-73 · db_read)
if [ "$HAVE_PSQL" -eq 1 ]; then
  db_read_var AUDIT_START "$DB" "SELECT coalesce(max(id),0) FROM audit_logs" \
    || { echo "운영 DB 를 읽지 못했다 — 시험을 시작하지 않는다 (R-73 · db_read rc 3)"; exit 3; }
fi

# ════════════════════════════════════════════════════════════════
fi

hr "Q:T-1 — 런처 GET /"

curl -s -D "$WORK/root.head" -o "$WORK/root.html" -A "$UA" "$SITE/"
expect "GET / 상태" "200" "$(status_of "$WORK/root.head")"
contains "Content-Type" "text/html" "$(hdr Content-Type "$WORK/root.head")"
contains "Cache-Control no-store" "no-store" "$(hdr Cache-Control "$WORK/root.head")"
expect "Set-Cookie 0건 (쿠키 발행 0)" "0" "$(grep -ci '^Set-Cookie:' "$WORK/root.head" | tr -d ' ')"
expect "테마 설정용 외부 script 1건" "1" "$(grep -c '<script' "$WORK/root.html" | tr -d ' ')"
expect "인라인 style= 0건 (CSP)" "0" "$(grep -cE '<[^>]+ style="' "$WORK/root.html" | tr -d ' ')"
expect "인라인 on…= 0건 (CSP)" "0" "$(grep -cE '<[^>]+ on[a-z]+=' "$WORK/root.html" | tr -d ' ')"
# 디버깅 묶음 S2 (사용자 결정 d72 ① · R-98) — 로그인 전에는 **사이트 이름 + 로그인 단추만**. 앱 이름·설명·경로·타일 0.
contains "사이트 이름 Qaredi" ">Qaredi</h1>" "$(cat "$WORK/root.html")"
expect "미로그인 — 앱 이름 t-box 0 · 커뮤니티 0" "0 0" "$(grep -c 't-box' "$WORK/root.html" | tr -d ' ') $(grep -c '커뮤니티' "$WORK/root.html" | tr -d ' ')"
expect "미로그인 — 앱 설명(사내 파일 공유 · 개인 업무 공간) 0" "0 0" "$(grep -c '사내 파일 공유' "$WORK/root.html" | tr -d ' ') $(grep -c '개인 업무 공간' "$WORK/root.html" | tr -d ' ')"
expect "미로그인 — 타일·격자 0(card-grid · card--link)" "0 0" "$(grep -c 'card-grid' "$WORK/root.html" | tr -d ' ') $(grep -c 'card--link' "$WORK/root.html" | tr -d ' ')"
expect "미로그인 — 앱 경로 링크 0(href=\"/tbox/\" · href=\"/community/\")" "0 0" "$(grep -c 'href="/tbox/"' "$WORK/root.html" | tr -d ' ') $(grep -c 'href="/community/"' "$WORK/root.html" | tr -d ' ')"
expect "미로그인 — 로그인 단추 하나(href=\"/tbox/login\" · .btn)" "1" "$(grep -c '<a class="btn" href="/tbox/login">로그인</a>' "$WORK/root.html" | tr -d ' ')"
expect "공통 브랜드 hero 하나 (사용자 테마 결정 2026-10-01)" "1" "$(grep -c '<section class="q-hero"' "$WORK/root.html" | tr -d ' ')"
expect "미로그인 — 사용자 배지 없음(badge 0) · 단추 자리는 page-head__actions 하나" "0 1" "$(grep -c 'class="badge"' "$WORK/root.html" | tr -d ' ') $(grep -c 'page-head__actions' "$WORK/root.html" | tr -d ' ')"
expect "런처에 userchip 0건 (DESIGN 2.9 — 배지 하나만)" "0" "$(grep -c 'userchip' "$WORK/root.html" | tr -d ' ')"

sub "Q:T-1 — 옛 이름 쿠키(tbox_session)만 들고 오면: 로그인 전 화면 + 옛 쿠키 만료 머리 하나(S2)"
curl -s -D "$WORK/rootold.head" -o "$WORK/rootold.html" -A "$UA" -H 'Cookie: tbox_session=0000000000000000000000000000000000000000000000000000000000000000' "$SITE/"
expect "옛 쿠키 — 상태 200 · 로그인 단추(로그인 전 화면)" "200 1" "$(status_of "$WORK/rootold.head") $(grep -c 'href="/tbox/login">로그인<' "$WORK/rootold.html" | tr -d ' ')"
expect "옛 쿠키 — Set-Cookie 한 줄 = 옛 이름 만료(Max-Age=0)" "1 1" "$(grep -ci '^Set-Cookie:' "$WORK/rootold.head" | tr -d ' ') $(grep -ciE '^Set-Cookie: tbox_session=(deleted)?;.*Max-Age=0' "$WORK/rootold.head" | tr -d ' ')"

curl -s -D "$WORK/rootpost.head" -o /dev/null -A "$UA" -X POST "$SITE/"
expect "POST / 상태 405" "405" "$(status_of "$WORK/rootpost.head")"
contains "405 의 Allow 헤더" "GET" "$(hdr Allow "$WORK/rootpost.head")"

# ════════════════════════════════════════════════════════════════
hr "Q:T-3 — 옛 주소 308"

old308() {   # $1 설명  $2 메서드  $3 옛 경로  $4 기대 Location
  local m="$2" p="$3" want="$4"
  if [ "$m" = "POST" ]; then
    curl -s -D "$WORK/r.head" -o /dev/null -A "$UA" -X POST \
      -H 'Content-Type: application/json' -d '{}' "$SITE$p"
  else
    curl -s -D "$WORK/r.head" -o /dev/null -A "$UA" "$SITE$p"
  fi
  expect "$1 — 308" "308" "$(status_of "$WORK/r.head")"
  expect "$1 — Location" "$want" "$(hdr Location "$WORK/r.head")"
  expect "$1 — Set-Cookie 0" "0" "$(grep -ci '^Set-Cookie:' "$WORK/r.head" | tr -d ' ')"
}

M0=0
[ "$HAVE_PSQL" -eq 1 ] && M0=$(mark)

old308 "GET /api/health"  GET  "/api/health"  "/tbox/api/health"
old308 "GET /login"       GET  "/login"       "/tbox/login"
old308 "POST /api/login"  POST "/api/login"   "/tbox/api/login"
old308 "GET /settings"    GET  "/settings"    "/tbox/settings"
old308 "GET /s/{43자}"    GET  "/s/0123456789012345678901234567890123456789012" \
                               "/tbox/s/0123456789012345678901234567890123456789012"
old308 "쿼리 원문 보존"   GET  "/api/health?x=1" "/tbox/api/health?x=1"

if [ "$HAVE_PSQL" -eq 1 ]; then
  expect "POST 308 은 처리 0 — 이 사이 감사 행 0건" "0" "$(psqlq "SELECT count(*) FROM audit_logs WHERE id > ${M0:-0}")"
else
  skip "POST 308 감사 행 0건" "psql 없음"
fi

sub "라우트가 아닌 경로는 404 (리다이렉트가 아니다)"
for p in /foo /api/nope /tbox/nope /community/nope; do
  curl -s -D "$WORK/r.head" -o /dev/null -A "$UA" "$SITE$p"
  expect "GET $p 상태" "404" "$(status_of "$WORK/r.head")"
  expect "GET $p Location 없음" "" "$(hdr Location "$WORK/r.head")"
done

sub '커뮤니티 갈래 (3단계, 2026-09-22) — 전에는 `/community/` 가 404 였다'
curl -s -D "$WORK/r.head" -o /dev/null -A "$UA" "$SITE/community/"
expect "GET /community/ (미로그인) 상태" "302" "$(status_of "$WORK/r.head")"
expect "GET /community/ Location" "/tbox/login?to=community" "$(hdr Location "$WORK/r.head")"
curl -s -D "$WORK/r.head" -o /dev/null -A "$UA" "$SITE/community/api/pages"
expect "GET /community/api/pages (미로그인) 상태" "401" "$(status_of "$WORK/r.head")"

# ════════════════════════════════════════════════════════════════
hr "Q:T-9 — 정적 자산 (nginx 적용 뒤에만 통과한다)"

curl -s -D "$WORK/css.head" -o "$WORK/app.css" -A "$UA" "$BASE/assets/app.css"
expect "GET /tbox/assets/app.css 상태" "200" "$(status_of "$WORK/css.head")"
contains "Content-Type text/css" "text/css" "$(hdr Content-Type "$WORK/css.head")"

curl -s -D "$WORK/gen.head" -o "$WORK/gen.html" -A "$UA" "$BASE/assets/totp-generator.html"
expect "생성기 상태" "200" "$(status_of "$WORK/gen.head")"
contains "생성기 자체 CSP(unsafe-inline)" "unsafe-inline" "$(hdr Content-Security-Policy "$WORK/gen.head")"

curl -s -D "$WORK/man.head" -o "$WORK/app.webmanifest" -A "$UA" "$BASE/assets/totp-generator.webmanifest"
expect "매니페스트 상태" "200" "$(status_of "$WORK/man.head")"
expect "매니페스트 start_url" "/tbox/assets/totp-generator.html" "$(jget "d['start_url']" < "$WORK/app.webmanifest")"
expect "매니페스트 scope" "/tbox/assets/" "$(jget "d['scope']" < "$WORK/app.webmanifest")"

curl -s -D "$WORK/oldcss.head" -o /dev/null -A "$UA" "$SITE/assets/app.css"
expect "옛 /assets/app.css → 308" "308" "$(status_of "$WORK/oldcss.head")"
expect "옛 /assets/app.css Location" "/tbox/assets/app.css" "$(hdr Location "$WORK/oldcss.head")"

# ════════════════════════════════════════════════════════════════
hr "Q:T-4 — 링크 접두 (정적 검사: app.js · 서버 코드)"

expect "app.js 절대 경로 중 APP_BASE 로 감싸이지 않은 것 0건" "0" \
  "$(grep -nE "['\"]/(api|login|reauth|wiki|admin|settings|files|s)(/|['\"])" "$ROOT/public/assets/app.js" \
     | grep -vc 'APP_BASE' | tr -d ' ')"
expect "app.js 에 APP_BASE 정의 1곳" "1" "$(grep -c '^var APP_BASE =' "$ROOT/public/assets/app.js" | tr -d ' ')"
# 커뮤니티 3단계 — 「커뮤니티 준비 중」 안내(src/community/routes.php)에도 「Qaredi 홈」 링크가
# 하나 있어 셋이 됐다. 전부 사이트 루트(`/`)이고 `/tbox` 접두를 타지 않는 것이 맞다.
# 디버깅 묶음 U3 — 없는 주소 화면(src/site/not_found_page.php)의 「첫 화면으로」(`/` — 사이트 루트)로 넷.
expect "서버 화면의 절대 경로 링크 중 Http::url 밖 (Qaredi 홈 셋 · 없는 주소 화면 「첫 화면으로」 하나 제외)" "4" \
  "$(grep -rhE 'href=\"/|action=\"/|src=\"/' "$ROOT/src" --include=*.php | grep -vc 'Http::url' | tr -d ' ')"

# ════════════════════════════════════════════════════════════════
if [ "${QLAUNCHER_PUBLIC_ONLY:-0}" = "1" ]; then
  printf '\n공개 런처 검사: 통과 %s · 실패 %s · 건너뜀 %s\n' "$PASS" "$FAIL" "$SKIP"
  [ "$FAIL" -eq 0 ]; exit $?
fi

hr "Q:T-8 — 셸 그리드 단추 · Q:T-5 리다이렉트 · Q:T-1 로그인 뒤 런처 · Q:T-6 공유 링크 · Q:T-7 쿠키·헤더"
echo "※ 여기부터는 **로그인**이 필요하다. PHP 내장 서버에서는 세션이 서지 않으므로 nginx 적용 뒤에 돌린다."

sub "Q:T-5 — 미로그인 화면 요청은 /tbox/login 으로 302"
curl -s -D "$WORK/set.head" -o /dev/null -A "$UA" "$BASE/settings"
expect "GET /tbox/settings (미로그인) 상태" "302" "$(status_of "$WORK/set.head")"
# Q:F-7 B1-1(2026-09-27 T:F-72 라운드 A) — 보던 화면이 허용 목록 안이면 꼬리표 ?to=<정규형 rawurlencode> 를 싣는다(ARCH 5.35 · Http::returnPath)
expect "GET /tbox/settings Location" "/tbox/login?to=%2Ftbox%2Fsettings" "$(hdr Location "$WORK/set.head")"

sub "로그인 화면"
curl -s -D "$WORK/login.head" -o "$WORK/login.html" -A "$UA" "$BASE/login"
expect "GET /tbox/login 상태" "200" "$(status_of "$WORK/login.head")"
expect "로그인 화면의 /tbox 밖 절대 경로 (「Qaredi 홈」 제외) 0건" "0" "$(bad_links "$WORK/login.html")"
contains "로그인 화면에 Qaredi 홈 링크" 'href="/"' "$(cat "$WORK/login.html")"
contains "로그인 폼 action 접두" 'action="/tbox/api/login"' "$(cat "$WORK/login.html")"

EMP_CSRF=""; ADM_CSRF=""
EMP_CSRF=$(login claude_employee01) || EMP_CSRF=""
ADM_CSRF=$(login claude_admin01)    || ADM_CSRF=""
[ -n "$EMP_CSRF" ] && note "claude_employee01 로그인 OK" || note "claude_employee01 로그인 실패 — 관련 항목 SKIP"
[ -n "$ADM_CSRF" ] && note "claude_admin01 로그인 OK"    || note "claude_admin01 로그인 실패 — 관련 항목 SKIP"
EMP_JAR="$JAR_DIR/claude_employee01.jar"
ADM_JAR="$JAR_DIR/claude_admin01.jar"

if [ -n "$EMP_CSRF" ]; then
  sub "Q:T-7 — 쿠키 속성·전역 CSP 불변"
  T7_PW=$(qa_password claude_employee01)
  printf -v body '{"username":"claude_employee01","password":"%s"}' "$T7_PW"
  curl_json_secret body -s -D "$WORK/cookie.head" -o /dev/null -A "$UA" -c "$WORK/throwaway.jar" \
    -H 'Content-Type: application/json' "$BASE/api/login" || true
  T7_PW=""; body=""
  SC=$(hdr Set-Cookie "$WORK/cookie.head")
  if [ -n "$SC" ]; then
    contains "쿠키 이름 __Host-tbox_session(S2)" "__Host-tbox_session=" "$SC"
    contains "쿠키 Path=/"      "Path=/"        "$SC"
    contains "쿠키 HttpOnly"    "HttpOnly"      "$SC"
    contains "쿠키 Secure"      "Secure"        "$SC"
    contains "쿠키 SameSite=Lax" "SameSite=Lax" "$SC"
    expect "쿠키 Domain 없음(__Host- 조건)" "0" "$(printf '%s' "$SC" | grep -ci 'domain=' | tr -d ' ')"
  else
    note "이 응답에는 Set-Cookie 가 없다(2단계 코드 단계) — 로그인 응답의 쿠키는 위 login() 이 이미 받았다"
    skip "쿠키 속성" "이 요청에 Set-Cookie 없음"
  fi
  contains "전역 CSP 문자열" "default-src 'self'; script-src 'self'" "$(hdr Content-Security-Policy "$WORK/login.head")"

  sub "Q:T-8 — 셸 화면의 앱 그리드 단추"
  for pg in "" /settings /activity; do
    curl -s -b "$EMP_JAR" -A "$UA" "$BASE/${pg#/}" -o "$WORK/shell.html"
    expect "GET /tbox/${pg#/} 에 appbar__apps 1개 (사이드바 brand 줄)" "1" "$(grep -c 'details class="appbar__apps"' "$WORK/shell.html" | tr -d ' ')"
    # 원래 어긋남(Q:F-12 C 에서 함께 고침) — 앱 메뉴의 첫 줄 이름은 「모아 홈」(모아 디자인 2026-10-02)
    expect "GET /tbox/${pg#/} 항목 모아 홈" "1" "$(grep -c '>모아 홈<' "$WORK/shell.html" | tr -d ' ')"
    # Q:F-12 C (DESIGN 2.18) — 앱 아이콘 줄 하나 · 자료실 · 업무 · 이야기 순 · 자료실 켜짐
    expect "GET /tbox/${pg#/} 앱 아이콘 줄 1개" "1" "$(grep -c '<nav class="app-rail" aria-label="앱 바꾸기">' "$WORK/shell.html" | tr -d ' ')"
    expect "GET /tbox/${pg#/} 아이콘 줄 지금 앱 = 자료실" "1" "$(grep -c '<a class="app-rail__item" href="/tbox/" aria-current="page">' "$WORK/shell.html" | tr -d ' ')"
    expect "GET /tbox/${pg#/} 아이콘 단추의 title 0건 (D-3)" "0" \
      "$(grep -cE 'btn--icon[^>]*title=' "$WORK/shell.html" | tr -d ' ')"
    expect "GET /tbox/${pg#/} 의 /tbox 밖 절대 경로 (Qaredi 홈 제외) 0건" "0" "$(bad_links "$WORK/shell.html")"
  done

  sub "Q:T-6 — 공유 링크 주소"
  # 검사가 끝난(clean) 파일만 고른다 — pending·infected 는 공유 링크로 나가지 않는다(F-57).
  FID=$(curl -s -b "$EMP_JAR" -A "$UA" "$BASE/api/files?page=1" | jget "next((f['id'] for f in d.get('files',[]) if f.get('scan_status','clean')=='clean'), '')")
  SHARE_JAR="$EMP_JAR"; SHARE_CSRF="$EMP_CSRF"
  if [ -z "$FID" ] && [ -n "$ADM_CSRF" ]; then   # 직원이 볼 수 있는 clean 파일이 없으면 관리자 목록으로
    FID=$(curl -s -b "$ADM_JAR" -A "$UA" "$BASE/api/files?page=1" | jget "next((f['id'] for f in d.get('files',[]) if f.get('scan_status','clean')=='clean'), '')")
    SHARE_JAR="$ADM_JAR"; SHARE_CSRF="$ADM_CSRF"
    # 디버깅 묶음 S-8 — 관리자 권한(admin_all)으로 만드는 링크는 15분 재인증 창 안에서만 된다(403 next:"reauth").
    # 뒤의 Q:T-5 가 첫 관리자 세션의 「재인증 전 302」를 보므로, 재인증은 같은 계정의 **두 번째 세션**에서 한다.
    ADM2_CSRF=$(login claude_admin01 claude_admin01-share) || ADM2_CSRF=""
    if [ -n "$ADM2_CSRF" ]; then
      ADM_PW=$(qa_password claude_admin01)
      printf -v RBODY '{"password":"%s"}' "$ADM_PW"
      RC=$(curl_json_secret RBODY -s -o /dev/null -w '%{http_code}' -b "$JAR_DIR/claude_admin01-share.jar" -A "$UA" \
           -H 'Content-Type: application/json' -H "X-CSRF-Token: $ADM2_CSRF" "$BASE/api/me/reauth")
      unset ADM_PW RBODY
      expect "공유 링크를 만들 관리자 두 번째 세션 재인증(S-8)" "200" "$RC"
      SHARE_JAR="$JAR_DIR/claude_admin01-share.jar"; SHARE_CSRF="$ADM2_CSRF"
    fi
  fi
  if [ -n "$FID" ]; then
    R=$(curl -s -b "$SHARE_JAR" -A "$UA" -H 'Content-Type: application/json' -H "X-CSRF-Token: $SHARE_CSRF" \
        -d '{"expires_hours":1}' "$BASE/api/files/$FID/share")   # 허용 키는 expires_hours (share_create.php R-07)
    URL=$(printf '%s' "$R" | jget "d.get('url','')")
    SID=$(printf '%s' "$R" | jget "d.get('id','')")
    [ -n "$SID" ] && MADE_SHARES="$MADE_SHARES $SID"
    case "$URL" in
      */tbox/s/*) expect "공유 링크 url 이 /tbox/s/ 를 지난다" "1" "1" ;;
      *)          expect "공유 링크 url 이 /tbox/s/ 를 지난다" "1" "0 (실제: $URL)" ;;
    esac
    TOKEN="${URL##*/}"
    if [ -n "$TOKEN" ]; then
      SHARE_URL_S="$BASE/s/$TOKEN"   # 공유 링크 토큰이 든 주소 — 인자가 아니라 표준입력(--config -)으로 (R-62)
      SCODE=$(curl_url_secret SHARE_URL_S -s -o /dev/null -w '%{http_code}' -A "$UA")
      expect "새 주소로 공유 파일 받기" "200" "$SCODE"
    fi
  else
    skip "Q:T-6 공유 링크" "받을 수 있는 파일이 없다"
  fi
fi

if [ -n "$ADM_CSRF" ]; then
  sub "Q:T-5 — 재인증 관문의 302 경로"
  curl -s -D "$WORK/admin.head" -o /dev/null -b "$ADM_JAR" -A "$UA" "$BASE/admin"
  expect "GET /tbox/admin (재인증 전) 상태" "302" "$(status_of "$WORK/admin.head")"
  expect "GET /tbox/admin Location" "/tbox/reauth?to=admin" "$(hdr Location "$WORK/admin.head")"

  sub "Q:T-1 — 로그인 상태의 런처 (사용자 칩)"
  curl -s -b "$ADM_JAR" -A "$UA" -o "$WORK/root2.html" -D "$WORK/root2.head" "$SITE/"
  expect "로그인 상태 런처 상태" "200" "$(status_of "$WORK/root2.head")"
  # 원래 어긋남(Q:F-12 C 에서 함께 고침) — 모아 런처(2026-10-02)의 사용자 표시는 머리 줄 `a.moa-account`(「이름 님」 · 내 계정)
  expect "로그인 상태 런처에 사용자 표시 (moa-account 1)" "1" "$(grep -c 'class="moa-account"' "$WORK/root2.html" | tr -d ' ')"
  expect "로그인 상태 런처에도 userchip 0건" "0" "$(grep -c 'userchip' "$WORK/root2.html" | tr -d ' ')"
  contains "칩에 표시 이름" "claude_admin01" "$(cat "$WORK/root2.html")"
  expect "로그인 상태 런처 테마·내 작업·메신저 창 script 5건(Q:F-11 B — 창 스크립트 셋 +3)" "5" "$(grep -c '<script' "$WORK/root2.html" | tr -d ' ')"
  # 로그인 뒤 — 바로가기 타일(Q:F-12 C-1 · Q:T-38: 자료실 · 업무 · 이야기 → 메신저 · PC 비서). 모아 런처의 타일 격자는 `nav.moa-shortcuts`
  # (원래 어긋남: card--link · card-grid · 「t-box」 · 「커뮤니티」 타일은 모아 디자인 2026-10-02 에서 없어졌다 — Q:F-12 C 에서 함께 고침)
  contains "로그인 뒤 바로가기 격자 moa-shortcuts" 'class="moa-shortcuts"' "$(cat "$WORK/root2.html")"
  expect "로그인 뒤 타일 순서 자료실 · 업무 · 이야기" "자료실 업무 이야기" "$(tr -d '\n' < "$WORK/root2.html" | sed -n 's/.*<nav class="moa-shortcuts"//p' | grep -oE '<strong>[^<]+' | head -3 | sed 's/<strong>//' | paste -sd' ')"
  expect "로그인 뒤 배지 준비 중 0건" "0" "$(grep -c '준비 중' "$WORK/root2.html" | tr -d ' ')"
  expect "로그인 뒤 자료실 타일이 /tbox/ 로 간다" "1" "$(grep -c 'href="/tbox/"' "$WORK/root2.html" | tr -d ' ')"
  expect "로그인 뒤 이야기(머리 링크 · 타일)가 /community/memories 로 간다" "2" "$(grep -o 'href="/community/memories"' "$WORK/root2.html" | wc -l | tr -d ' ')"
  expect "로그인 뒤 로그인 단추 0" "0" "$(grep -c 'href="/tbox/login">로그인<' "$WORK/root2.html" | tr -d ' ')"
  expect "로그인 뒤 런처 Set-Cookie 0(세션은 읽기만)" "0" "$(grep -ci '^Set-Cookie:' "$WORK/root2.head" | tr -d ' ')"
fi

# ─── 정리 ───────────────────────────────────────────────────────
hr "정리"
for sid in $MADE_SHARES; do
  RC=$(curl -s -o /dev/null -w '%{http_code}' -X DELETE -b "${SHARE_JAR:-$EMP_JAR}" -A "$UA" \
       -H "X-CSRF-Token: ${SHARE_CSRF:-$EMP_CSRF}" "$BASE/api/shares/$sid")
  printf '  · 공유 링크 %s 회수 [%s]\n' "$sid" "$RC"
done

if [ "$HAVE_PSQL" -eq 1 ]; then
  expect "시험 요청이 만든 비-qa 행 0건 (검토 UA 기준 — 사용자 데이터 불변)" "0" \
    "$(db_read "$DB" "SELECT count(*) FROM audit_logs
                WHERE id > ${AUDIT_START:-0}
                  AND actor_name IS NOT NULL AND $REVIEW_NOT_SQL
                  AND COALESCE(user_agent, '') LIKE 'tbox-review/%'")"
  note "시험이 만든 감사 행(전체): $(psqlq "SELECT count(*) FROM audit_logs WHERE id > ${AUDIT_START:-0}")건"
else
  skip "비-qa 행 0건" "psql 없음"
fi

# ─── 마무리 ─────────────────────────────────────────────────────
hr "결과"
printf '통과 %d · 실패 %d · 건너뜀/미검증 %d\n' "$PASS" "$FAIL" "$SKIP"
echo "작업 폴더 $WORK 는 종료와 함께 지워진다."
[ "$FAIL" -eq 0 ] || exit 1
exit 0
