#!/usr/bin/env bash
# tests/q_launcher.sh — Qaredi C 수락 기준 실행 (사이트 PRD.md 9장 Q:T-1 · 3 · 4 · 5 · 6 · 7 · 8 · 9,
#                        설계 ../docs/launcher/ARCHITECTURE.md, 지시서 ../docs/launcher/c-launcher-basepath-directive.md)
#
#   Q:T-1 런처 `GET /`         200 HTML · 타일 t-box·커뮤니티(「준비 중」) · `<script` 0 · `Set-Cookie` 0 ·
#                              미로그인이면 사용자 칩 없음 · 로그인(qa)이면 표시 이름 · `POST /` 405
#   Q:T-3 옛 주소 308          `/api/health` `/login` `POST /api/login` `/s/{43자}` `/settings` `/assets/app.css`
#                              → 308 + `Location: /tbox<경로+쿼리 원문>` · POST 는 처리 0(감사 행 0 · Set-Cookie 0)
#                              · `/foo` `/api/nope` `/tbox/nope` `/community/` → 404(리다이렉트 아님)
#   Q:T-4 링크 접두            렌더된 HTML 의 `href|src|action="/…"` 가 전부 `/tbox/` 로 시작
#                              (예외: 「Qaredi 홈」·커뮤니티 `/community/`) · `app.js` 절대 경로가 전부 `APP_BASE +` 뒤
#   Q:T-5 리다이렉트 경로      미로그인 `/tbox/settings` → 302 `/tbox/login` · 재인증 전 `/tbox/admin` → 302 `/tbox/reauth?to=admin`
#   Q:T-6 공유 링크            `POST …/share` 의 `url` 이 `APP_URL/tbox/s/` 로 시작하고 그 주소로 받아진다
#   Q:T-7 쿠키·헤더 불변       `Set-Cookie` 속성(`Path=/; Secure; HttpOnly; SameSite=Lax`) · 전역 CSP 문자열
#   Q:T-8 그리드 단추          셸 화면 전부에 `<details class="appbar__apps">` · 항목 셋 · `.btn--icon[title]` 0
#   Q:T-9 정적 자산            `/tbox/assets/app.css` 200 `text/css` · 생성기 200 + 자체 CSP(`unsafe-inline`) ·
#                              매니페스트 `start_url` `/tbox/…` · 옛 `/assets/app.css` → 308
#
# 규칙 (CLAUDE.md 3장)
#   · 검토 계정은 `qa_employee01`·`qa_admin01` 뿐이다. 시드 계정(admin01 …)은 사용자 몫이다.
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

login() {   # $1 계정 → 성공하면 표준출력에 csrf 토큰
  local who="$1" jar="$JAR_DIR/$1.jar" pw sec raw code left out try
  pw=$(qa_password "$who")
  sec=$(qa_secret "$who")
  [ -n "$pw" ] && [ -n "$sec" ] || return 2
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
if psqlq "SELECT 1" >/dev/null 2>&1; then HAVE_PSQL=1; fi

if [ "$HAVE_PSQL" -eq 1 ]; then
  TRY=1
  while :; do
    BUSY=$(psqlq "SELECT count(*) FROM audit_logs WHERE happened_at > now() - interval '10 minutes' AND actor_name IS NOT NULL AND actor_name NOT LIKE 'qa\_%'")
    echo "최근 10분 비-qa 감사 행: ${BUSY:-?} (시도 ${TRY}/10)"
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
[ "$HAVE_PSQL" -eq 1 ] && AUDIT_START=$(mark)

# ════════════════════════════════════════════════════════════════
hr "Q:T-1 — 런처 GET /"

curl -s -D "$WORK/root.head" -o "$WORK/root.html" -A "$UA" "$SITE/"
expect "GET / 상태" "200" "$(status_of "$WORK/root.head")"
contains "Content-Type" "text/html" "$(hdr Content-Type "$WORK/root.head")"
contains "Cache-Control no-store" "no-store" "$(hdr Cache-Control "$WORK/root.head")"
expect "Set-Cookie 0건 (쿠키 발행 0)" "0" "$(grep -ci '^Set-Cookie:' "$WORK/root.head" | tr -d ' ')"
expect "<script 0건 (스크립트 0)" "0" "$(grep -c '<script' "$WORK/root.html" | tr -d ' ')"
expect "인라인 style= 0건 (CSP)" "0" "$(grep -cE '<[^>]+ style="' "$WORK/root.html" | tr -d ' ')"
expect "인라인 on…= 0건 (CSP)" "0" "$(grep -cE '<[^>]+ on[a-z]+=' "$WORK/root.html" | tr -d ' ')"
contains "타일 t-box" "t-box" "$(cat "$WORK/root.html")"
contains "타일 커뮤니티" "커뮤니티" "$(cat "$WORK/root.html")"
contains "배지 준비 중" "준비 중" "$(cat "$WORK/root.html")"
contains "타일 클래스 card--link" "card card--link" "$(cat "$WORK/root.html")"
contains "격자 card-grid" "card-grid" "$(cat "$WORK/root.html")"
expect "화면 전용 클래스 launcher- 0건 (DESIGN 원칙 6)" "0" "$(grep -c 'launcher-' "$WORK/root.html" | tr -d ' ')"
expect "미로그인 사용자 표시 없음 (page-head__actions 0)" "0" "$(grep -c 'page-head__actions' "$WORK/root.html" | tr -d ' ')"
expect "런처에 userchip 0건 (DESIGN 2.9 — 배지 하나만)" "0" "$(grep -c 'userchip' "$WORK/root.html" | tr -d ' ')"
expect "t-box 타일이 /tbox/ 로 간다" "1" "$(grep -c 'href="/tbox/"' "$WORK/root.html" | tr -d ' ')"
expect "커뮤니티 타일에 링크 없음" "0" "$(grep -c 'href="/community/"' "$WORK/root.html" | tr -d ' ')"

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
for p in /foo /api/nope /tbox/nope /community/; do
  curl -s -D "$WORK/r.head" -o /dev/null -A "$UA" "$SITE$p"
  expect "GET $p 상태" "404" "$(status_of "$WORK/r.head")"
  expect "GET $p Location 없음" "" "$(hdr Location "$WORK/r.head")"
done

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
expect "서버 화면의 절대 경로 링크 중 Http::url 밖 (Qaredi 홈 둘 제외)" "2" \
  "$(grep -rhE 'href=\"/|action=\"/|src=\"/' "$ROOT/src" --include=*.php | grep -vc 'Http::url' | tr -d ' ')"

# ════════════════════════════════════════════════════════════════
hr "Q:T-8 — 셸 그리드 단추 · Q:T-5 리다이렉트 · Q:T-1 로그인 뒤 런처 · Q:T-6 공유 링크 · Q:T-7 쿠키·헤더"
echo "※ 여기부터는 **로그인**이 필요하다. PHP 내장 서버에서는 세션이 서지 않으므로 nginx 적용 뒤에 돌린다."

sub "Q:T-5 — 미로그인 화면 요청은 /tbox/login 으로 302"
curl -s -D "$WORK/set.head" -o /dev/null -A "$UA" "$BASE/settings"
expect "GET /tbox/settings (미로그인) 상태" "302" "$(status_of "$WORK/set.head")"
expect "GET /tbox/settings Location" "/tbox/login" "$(hdr Location "$WORK/set.head")"

sub "로그인 화면"
curl -s -D "$WORK/login.head" -o "$WORK/login.html" -A "$UA" "$BASE/login"
expect "GET /tbox/login 상태" "200" "$(status_of "$WORK/login.head")"
expect "로그인 화면의 /tbox 밖 절대 경로 (「Qaredi 홈」 제외) 0건" "0" "$(bad_links "$WORK/login.html")"
contains "로그인 화면에 Qaredi 홈 링크" 'href="/"' "$(cat "$WORK/login.html")"
contains "로그인 폼 action 접두" 'action="/tbox/api/login"' "$(cat "$WORK/login.html")"

EMP_CSRF=""; ADM_CSRF=""
EMP_CSRF=$(login qa_employee01) || EMP_CSRF=""
ADM_CSRF=$(login qa_admin01)    || ADM_CSRF=""
[ -n "$EMP_CSRF" ] && note "qa_employee01 로그인 OK" || note "qa_employee01 로그인 실패 — 관련 항목 SKIP"
[ -n "$ADM_CSRF" ] && note "qa_admin01 로그인 OK"    || note "qa_admin01 로그인 실패 — 관련 항목 SKIP"
EMP_JAR="$JAR_DIR/qa_employee01.jar"
ADM_JAR="$JAR_DIR/qa_admin01.jar"

if [ -n "$EMP_CSRF" ]; then
  sub "Q:T-7 — 쿠키 속성·전역 CSP 불변"
  curl -s -D "$WORK/cookie.head" -o /dev/null -A "$UA" -c "$WORK/throwaway.jar" \
    -H 'Content-Type: application/json' \
    -d "{\"username\":\"qa_employee01\",\"password\":\"$(qa_password qa_employee01)\"}" "$BASE/api/login" || true
  SC=$(hdr Set-Cookie "$WORK/cookie.head")
  if [ -n "$SC" ]; then
    contains "쿠키 Path=/"      "Path=/"        "$SC"
    contains "쿠키 HttpOnly"    "HttpOnly"      "$SC"
    contains "쿠키 Secure"      "Secure"        "$SC"
    contains "쿠키 SameSite=Lax" "SameSite=Lax" "$SC"
  else
    note "이 응답에는 Set-Cookie 가 없다(2단계 코드 단계) — 로그인 응답의 쿠키는 위 login() 이 이미 받았다"
    skip "쿠키 속성" "이 요청에 Set-Cookie 없음"
  fi
  contains "전역 CSP 문자열" "default-src 'self'; script-src 'self'" "$(hdr Content-Security-Policy "$WORK/login.head")"

  sub "Q:T-8 — 셸 화면의 앱 그리드 단추"
  for pg in "" /settings /activity; do
    curl -s -b "$EMP_JAR" -A "$UA" "$BASE/${pg#/}" -o "$WORK/shell.html"
    expect "GET /tbox/${pg#/} 에 appbar__apps 1개 (사이드바 brand 줄)" "1" "$(grep -c 'details class="appbar__apps"' "$WORK/shell.html" | tr -d ' ')"
    expect "GET /tbox/${pg#/} 항목 Qaredi 홈" "1" "$(grep -c '>Qaredi 홈<' "$WORK/shell.html" | tr -d ' ')"
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
      SCODE=$(curl -s -o /dev/null -w '%{http_code}' -A "$UA" "$BASE/s/$TOKEN")
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
  expect "로그인 상태 런처에 사용자 배지 (page-head__actions 1)" "1" "$(grep -c 'page-head__actions' "$WORK/root2.html" | tr -d ' ')"
  expect "로그인 상태 런처에도 userchip 0건" "0" "$(grep -c 'userchip' "$WORK/root2.html" | tr -d ' ')"
  contains "칩에 표시 이름" "qa_admin01" "$(cat "$WORK/root2.html")"
  expect "로그인 상태 런처에도 <script 0건" "0" "$(grep -c '<script' "$WORK/root2.html" | tr -d ' ')"
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
    "$(psqlq "SELECT count(*) FROM audit_logs
                WHERE id > ${AUDIT_START:-0}
                  AND actor_name IS NOT NULL AND actor_name NOT LIKE 'qa\_%'
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
