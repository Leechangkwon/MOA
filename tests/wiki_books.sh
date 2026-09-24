#!/usr/bin/env bash
# tests/wiki_books.sh — 위키 책 셋(Qaredi · t-box · 커뮤니티) 수락 시험 (2026-09-23, PRD F-21 · T-32 · SECURITY R-56)
#
#   사용자 지시: 「위키 접속 시 Qaredi · T-box · Community 3개를 보여 주고, 클릭하여 접속하면 해당하는
#   상세 위키를 볼 수 있도록」. 주소는 `/wiki`(책 고르기) · `/wiki/{book}`(차례) · `/wiki/{book}/{slug}`(글),
#   옛 `/wiki/{slug}` 는 t-box 책에 그 글이 있으면 308, 없으면 404.
#
#   (a) 미로그인        네 주소 모두 302 /tbox/login
#   (b) 재인증 전 Admin  네 주소 모두 302 /tbox/reauth?to=wiki (재인증 반환 키가 새 경로에도 걸렸나)
#   (c) Admin           /wiki 200 · 타일 셋(순서·주소·글 수) · 세 책 차례 200 · 차례의 모든 글 200 ·
#                       인라인 <script>·style=·on…= 0
#   (d) 주소 경계        옛 주소 308 → 새 주소 · 모르는 책·대문자·`.json`·`../`·`%2f`·다른 책의 slug 404
#   (e) 역할            Manager·Employee·Guest 로 네 주소 403 + 감사 `authz.route deny`
#   (f) 출력 안전        `<script>` 가 든 글(메모리 안에서만 만든 것)이 글자로 그려짐 · 다른 책 링크 · 이웃 글 순서
#   (g) 브라우저         tests/wiki_books_browser.py — 눌러서 이동 · 라이트/다크/390px 스크린샷 · 오류 0 · JS 끔
#   (h) 정적            wiki_check 0 · security_lint 0 · php -l
#
# 규칙 (CLAUDE.md 3장)
#   · 검토 계정(claude_ 접두)만 쓴다. 모든 요청에 `-A "tbox-review/<날짜>"`.
#   · 이 시험은 **데이터를 만들지 않는다** — GET 과 재인증뿐이다. (f) 의 XSS 글은 파일로 쓰지 않고
#     `php` 메모리 안에서만 만들어 렌더러에 넘긴다(작업 트리·운영 화면에 아무것도 놓이지 않는다).
#   · 운영 DB 는 **읽기**(psql SELECT)만.
#
# ★ 시험 안전 (SECURITY R-73 · R-80): 최근 10분에 비-qa 감사 행이 있으면 기다리고,
#   루트 파티션 여유가 1GB 미만이면 시작하지 않는다 (q_launcher.sh 와 같은 관문).
# ★ qa 로그인은 2단계 코드 30초 창 때문에 **차례로** 한다(코드 재사용은 다음 창까지 기다린다).
#
# 사용: sudo -v && bash tests/wiki_books.sh [스크린샷 폴더]   (기본 ~/.claude/ui-shots/wiki-books)
set -u

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
# 검토 계정 판정 한 벌(qa_·claude_ — F-71 C). 못 읽으면 사전 검사가 빈 값으로 열리므로 시작하지 않는다.
. "$ROOT/tests/lib/review_accounts.sh" || { echo "tests/lib/review_accounts.sh 를 읽지 못했다 — 시험을 시작하지 않는다 (R-73)"; exit 3; }
. "$ROOT/tests/lib/secrets.sh" || { echo "tests/lib/secrets.sh 를 읽지 못했다 — 시험을 시작하지 않는다 (R-62)"; exit 3; }
. "$ROOT/tests/lib/db_read.sh" || { echo "tests/lib/db_read.sh 를 읽지 못했다 — 시험을 시작하지 않는다 (R-73)"; exit 3; }
SITE="http://127.0.0.1:8082"
BASE="$SITE/tbox"
UA="tbox-review/$(date +%F)"
DB="tbox_re"
SHOTS="${1:-$HOME/.claude/ui-shots/wiki-books}"
JAR_DIR="$(mktemp -d)"
WORK="$(mktemp -d)"
PASS=0
FAIL=0
SKIP=0

cleanup() { rm -rf "$JAR_DIR" "$WORK"; }
trap cleanup EXIT

hr()  { printf '\n════════════════════════════════════════════════════════════\n%s\n════════════════════════════════════════════════════════════\n' "$1"; }
sub() { printf '\n── %s\n' "$1"; }
expect() {
  if [ "$2" = "$3" ]; then printf '  ✔ %s  (기대=%s 실제=%s)\n' "$1" "$2" "$3"; PASS=$((PASS+1))
  else printf '  ✖ %s  (기대=%s 실제=%s)\n' "$1" "$2" "$3"; FAIL=$((FAIL+1)); fi
}
contains() {   # $1 설명  $2 기대 조각  $3 파일
  if grep -qF -- "$2" "$3"; then printf '  ✔ %s  (「%s」 포함)\n' "$1" "$2"; PASS=$((PASS+1))
  else printf '  ✖ %s  (「%s」 없음)\n' "$1" "$2"; FAIL=$((FAIL+1)); fi
}
skip() { printf '  ○ SKIP %s  (%s)\n' "$1" "$2"; SKIP=$((SKIP+1)); }

psqlq() { sudo -n -u postgres psql -d "$DB" -t -A -c "$1" 2>/dev/null; }

hdr()       { sed -n "s/^$1: \(.*\)\r*$/\1/Ip" "$2" | head -1 | tr -d '\r'; }
status_of() { sed -n 's#^HTTP/[0-9.]* \([0-9]*\).*#\1#p' "$1" | head -1 | tr -d '\r'; }

qa_password() { sudo -n awk -v u="$1" '$1==u{print $3}' /etc/tbox-v2/seed-accounts.txt 2>/dev/null; }
qa_secret()   { sudo -n awk -v u="$1" '$1==u{print $2}' /etc/tbox-v2/qa-totp.txt 2>/dev/null; }

login() {   # $1 계정 → 성공하면 표준출력에 csrf 토큰
  local who="$1" jar="$JAR_DIR/$1.jar" pw sec raw code left out try body
  pw=$(qa_password "$who"); sec=$(qa_secret "$who")
  [ -n "$pw" ] && [ -n "$sec" ] || return 2
  for try in 1 2 3; do
    raw=$(totp_now sec); code=${raw%%$'\n'*}
    printf -v body '{"username":"%s","password":"%s","otp":"%s"}' "$who" "$pw" "$code"
    out=$(curl_json_secret body -s -c "$jar" -A "$UA" -H 'Content-Type: application/json' "$BASE/api/login")
    case "$out" in
      *'"ok":true'*)
        curl -s -b "$jar" -A "$UA" "$BASE/api/me" | sed -n 's/.*"csrf_token":"\([0-9a-f]*\)".*/\1/p'
        return 0 ;;
    esac
    left=$(printf '%s' "$raw" | sed -n 's/^남은 시간: \([0-9]*\)초.*/\1/p'); [ -n "$left" ] || left=31
    printf '  · %s 로그인 재시도 — 다음 코드 창까지 %s초 기다림\n' "$who" "$left" >&2
    sleep $((left + 1))
  done
  return 1
}

reauth() {   # $1 계정 $2 토큰 → HTTP 코드
  local pw body
  pw=$(qa_password "$1")
  printf -v body '{"password":"%s"}' "$pw"
  curl_json_secret body -s -o /dev/null -w '%{http_code}' -b "$JAR_DIR/$1.jar" -c "$JAR_DIR/$1.jar" -A "$UA" \
    -H 'Content-Type: application/json' -H "X-CSRF-Token: $2" "$BASE/api/me/reauth"
}

get() {   # $1 jar 이름(빈 문자열 = 쿠키 없음)  $2 경로(모듈 기준)  $3 저장 이름 → 머리·본문 파일
  local jar=()
  [ -n "$1" ] && jar=(-b "$JAR_DIR/$1.jar")
  curl -s --path-as-is "${jar[@]}" -A "$UA" -D "$WORK/$3.head" -o "$WORK/$3.body" "$BASE$2"
}

# 네 가지 모양의 주소 — 책 고르기 · 차례 · 글 · 옛 주소
PATHS4="/wiki /wiki/tbox /wiki/tbox/stage-01 /wiki/stage-01"

# ─── 시작 전 관문 ────────────────────────────────────────────────
hr "위키 책 셋 — 시험 시작 전 확인"

avail_kb=$(df -Pk / | awk 'NR==2{print $4}')
echo "루트 파티션 여유: $(df -Ph / | awk 'NR==2{print $4}')"
[ "$avail_kb" -ge 1048576 ] || { echo "루트 디스크 여유 1GB 미만 — 시작하지 않는다 (SECURITY R-80)"; exit 3; }

HAVE_PSQL=0
psqlq "SELECT 1" >/dev/null 2>&1 && HAVE_PSQL=1
if [ "$HAVE_PSQL" -eq 1 ]; then
  TRY=1
  while :; do
    db_read_var BUSY "$DB" "SELECT count(*) FROM audit_logs WHERE happened_at > now() - interval '10 minutes' AND actor_name IS NOT NULL AND $REVIEW_NOT_SQL" \
      || { echo "운영 DB 를 읽지 못했다 — 시험을 시작하지 않는다 (R-73 · db_read rc 3)"; exit 3; }
    echo "최근 10분 비-qa 감사 행: ${BUSY:-?} (시도 ${TRY}/10) (읽기 경로 ${DB_READ_VIA})"
    [ "${BUSY:-0}" -gt 0 ] 2>/dev/null || break
    if [ "$TRY" -ge 10 ]; then
      echo "10회(약 20분) 기다렸지만 사용자의 활동이 이어진다 — 시작하지 않는다 (SECURITY R-73 ①)"; exit 3
    fi
    echo "  … 사용자가 쓰는 중이다. 2분 뒤 다시 본다."; sleep 120; TRY=$((TRY+1))
  done
else
  echo "psql 을 쓸 수 없다 — 감사 확인 항목은 SKIP 된다"
fi

# 차례에서 기대값을 뽑는다 — 책마다 「있는 글」 목록 (CLI 는 Wiki.php 한 파일만 읽는다)
php -r '
require $argv[1] . "/src/Wiki.php";
foreach (array_keys(Wiki::BOOKS) as $b) {
    $i = Wiki::loadIndex($b) ?? [];
    foreach (Wiki::entries($i, $b) as $e) { if ($e["exists"]) { echo $b, " ", $e["slug"], " ", $e["group"], "\n"; } }
}' "$ROOT" > "$WORK/expected.txt"
echo "차례에 있는 글: $(wc -l < "$WORK/expected.txt")편 (qaredi $(grep -c '^qaredi ' "$WORK/expected.txt") · tbox $(grep -c '^tbox ' "$WORK/expected.txt") · community $(grep -c '^community ' "$WORK/expected.txt"))"

# ════════════════════════════════════════════════════════════════
hr "(a) 미로그인 — 302 /tbox/login"
for p in $PATHS4; do
  get "" "$p" anon
  expect "GET $p 상태" "302" "$(status_of "$WORK/anon.head")"
  expect "GET $p Location" "$BASE/login" "$(hdr Location "$WORK/anon.head" | sed "s#^/tbox#$BASE#")"
done

# ════════════════════════════════════════════════════════════════
hr "(b) Admin 재인증 전 — 302 /tbox/reauth?to=wiki"
TOK_ADMIN=$(login claude_admin01) || { echo "claude_admin01 로그인 실패 — 중단"; exit 2; }
echo "  claude_admin01 로그인 ok"
for p in $PATHS4; do
  get claude_admin01 "$p" pre
  expect "GET $p 상태" "302" "$(status_of "$WORK/pre.head")"
  expect "GET $p → 재인증(꼬리표 wiki)" "/tbox/reauth?to=wiki" "$(hdr Location "$WORK/pre.head" | sed "s#^$SITE##")"
done
expect "재인증 POST /api/me/reauth" "200" "$(reauth claude_admin01 "$TOK_ADMIN")"

# ════════════════════════════════════════════════════════════════
hr "(c) Admin — 책 고르기 · 차례 · 글"
sub "GET /wiki — 타일 셋"
get claude_admin01 /wiki books
expect "상태" "200" "$(status_of "$WORK/books.head")"
contains "Content-Type HTML" "text/html" "$WORK/books.head"
expect "타일(a.card.card--link) 3개" "3" "$(grep -o '<a class="card card--link"' "$WORK/books.body" | wc -l | tr -d ' ')"
expect "타일 주소 순서 qaredi → tbox → community" "/tbox/wiki/qaredi /tbox/wiki/tbox /tbox/wiki/community" \
  "$(grep -o '<a class="card card--link" href="[^"]*"' "$WORK/books.body" | sed 's/.*href="\([^"]*\)"/\1/' | tr '\n' ' ' | sed 's/ $//')"
for name in Qaredi t-box 커뮤니티; do
  contains "타일 이름 $name" "<strong class=\"card__title\">$name</strong>" "$WORK/books.body"
done
for b in qaredi tbox community; do
  n=$(grep -c "^$b " "$WORK/expected.txt")
  # 그 타일의 href 부터 문서 끝까지 잘라 첫 「글 N편」 — 타일마다 메타가 href 바로 뒤에 온다
  got=$(tr '\n' ' ' < "$WORK/books.body" | grep -o "href=\"/tbox/wiki/$b\">.*" | grep -o '글 [0-9]*편' | head -1)
  expect "$b 타일의 글 수 = 차례의 있는 글 수" "글 ${n}편" "$got"
done
expect "인라인 <script>(src 없는) 0" "0" "$(grep -cE '<script(>| [^s])' "$WORK/books.body" | tr -d ' ')"
expect "style= 속성 0" "0" "$(grep -cE '<[^>]+ style="' "$WORK/books.body" | tr -d ' ')"
expect "on…= 속성 0" "0" "$(grep -cE '<[^>]+ on[a-z]+="' "$WORK/books.body" | tr -d ' ')"
expect "화면 전용 새 클래스 wiki-book 0" "0" "$(grep -c 'wiki-book' "$WORK/books.body" | tr -d ' ')"

sub "세 책 차례"
for b in qaredi tbox community; do
  get claude_admin01 "/wiki/$b" "idx-$b"
  expect "GET /wiki/$b 상태" "200" "$(status_of "$WORK/idx-$b.head")"
  contains "/wiki/$b 경로 줄 ← 위키 책 고르기" 'href="/tbox/wiki">← 위키 책 고르기' "$WORK/idx-$b.body"
  st=$(grep -c "^$b .* stage$" "$WORK/expected.txt")
  expect "/wiki/$b 단계 카드 수 = 차례의 단계 글 수" "$st" \
    "$(grep -o "<a class=\"card card--link\" href=\"/tbox/wiki/$b/[^\"]*\"" "$WORK/idx-$b.body" | wc -l | tr -d ' ')"
done
get claude_admin01 /wiki/tbox/ idx-slash
expect "끝 슬래시 /wiki/tbox/ 도 200" "200" "$(status_of "$WORK/idx-slash.head")"

sub "차례에 있는 모든 글 200"
bad=0; n=0
while read -r b s g; do
  n=$((n+1))
  get claude_admin01 "/wiki/$b/$s" one
  c=$(status_of "$WORK/one.head")
  if [ "$c" != "200" ]; then echo "    ✖ /wiki/$b/$s → $c"; bad=$((bad+1)); continue; fi
  # 경로 줄 「위키 › <책> 차례」 가 그 책을 가리키는가
  grep -qF "href=\"/tbox/wiki/$b\">" "$WORK/one.body" || { echo "    ✖ /wiki/$b/$s 경로 줄에 책 링크 없음"; bad=$((bad+1)); }
  # 글에서 온 문자열에 태그가 섞이지 않았나 — 본문(article) 안에 <script 0
  sed -n '/<article class="wiki-article">/,/<\/article>/p' "$WORK/one.body" | grep -q '<script' \
    && { echo "    ✖ /wiki/$b/$s 본문에 <script"; bad=$((bad+1)); }
done < "$WORK/expected.txt"
expect "글 ${n}편 전부 200 · 경로 줄 · 본문 <script 0" "0" "$bad"

# ════════════════════════════════════════════════════════════════
hr "(d) 주소 경계 — 옛 주소 308 · 나머지 404"
for s in stage-14 guide-what stage-28; do
  get claude_admin01 "/wiki/$s" old
  expect "옛 /wiki/$s 상태" "308" "$(status_of "$WORK/old.head")"
  expect "옛 /wiki/$s Location" "/tbox/wiki/tbox/$s" "$(hdr Location "$WORK/old.head")"
done
get claude_admin01 "/wiki/stage-14?x=1" oldq
expect "옛 주소의 쿼리는 버린다 (Location 은 코드가 만든 것뿐)" "/tbox/wiki/tbox/stage-14" "$(hdr Location "$WORK/oldq.head")"
# 모르는 책 `/wiki/zzz` 는 옛 주소 줄에 잡히지만 t-box 에 그 글이 없으므로 **바로 404** (308 뒤 404 가 아니다)
for p in /wiki/zzz /wiki/zzz/stage-01 /wiki/TBOX /wiki/Tbox/stage-01 /wiki/tbox/STAGE-01 \
         /wiki/tbox/stage-01.json /wiki/tbox/nope /wiki/community/stage-01 /wiki/qaredi/stage-26 \
         /wiki/tbox/stage-01/x /wiki/../../etc/passwd /wiki/tbox/../../etc/passwd \
         /wiki/tbox/..%2f..%2fetc%2fpasswd /wiki/%2e%2e/tbox /wiki/tbox%2fstage-01 /wiki/index /wiki/tbox/index; do
  get claude_admin01 "$p" edge
  c=$(status_of "$WORK/edge.head")
  case "$c" in
    404|400) expect "GET $p → 404/400 (리다이렉트·200 아님)" "ok" "ok" ;;
    *)       expect "GET $p → 404/400 (리다이렉트·200 아님)" "404|400" "$c $(hdr Location "$WORK/edge.head")" ;;
  esac
done

# ════════════════════════════════════════════════════════════════
hr "(e) 역할 — Manager · Employee · Guest 는 403"
MARK=0
[ "$HAVE_PSQL" -eq 1 ] && MARK=$(psqlq "SELECT coalesce(max(id),0) FROM audit_logs")
for who in claude_manager01 claude_employee01 claude_guest01; do
  if ! login "$who" > /dev/null; then skip "$who 네 주소 403" "로그인 실패"; continue; fi
  for p in $PATHS4 /wiki/community /wiki/qaredi/guide-site; do
    get "$who" "$p" role
    expect "$who GET $p" "403" "$(status_of "$WORK/role.head")"
  done
done
if [ "$HAVE_PSQL" -eq 1 ]; then
  DENY=$(psqlq "SELECT count(*) FROM audit_logs WHERE id > $MARK AND action='authz.route' AND result='deny'
                  AND actor_name IN ('claude_manager01','claude_employee01','claude_guest01')
                  AND detail->>'route' IN ('GET /wiki','GET /wiki/{book}','GET /wiki/{book}/{slug}','GET /wiki/{slug}')")
  expect "감사 authz.route deny — 3계정 × 6요청 = 18" "18" "${DENY:-?}"
  ROUTES=$(psqlq "SELECT string_agg(DISTINCT detail->>'route', ', ' ORDER BY detail->>'route') FROM audit_logs WHERE id > $MARK AND action='authz.route' AND $(review_like_sql actor_name)")
  echo "  · 감사에 남은 라우트 패턴: $ROUTES"
else
  skip "감사 authz.route deny" "psql 없음"
fi

# ════════════════════════════════════════════════════════════════
hr "(f) 출력 안전 · 다른 책 링크 · 이웃 글 순서"
sub "<script> 가 든 글 — 파일로 쓰지 않고 메모리에서 렌더러에 넘긴다"
php -r '
define("TBOX_BASE", "/tbox");
final class Http { public static function url(string $p): string { return TBOX_BASE . $p; } }
require $argv[1] . "/src/Wiki.php";
$x = "<script>alert(1)</script>\"><img src=x onerror=alert(2)>";
$page = ["slug" => "zz-xss", "title" => "제목 " . $x, "summary" => "요약 `" . $x . "`", "group" => "stage", "stage" => "4-A",
  "sections" => [["id" => "a", "heading" => "절 " . $x, "blocks" => [
    ["type" => "p", "text" => "문단 " . $x],
    ["type" => "table", "header" => ["머리 " . $x], "rows" => [["칸 " . $x]]],
    ["type" => "callout", "kind" => "risk", "title" => $x, "text" => $x],
    ["type" => "code", "text" => "</pre>" . $x],
    ["type" => "terms", "items" => [["term" => $x, "easy" => $x]]],
    ["type" => "refs", "items" => [["doc" => "DIRECTIVE", "where" => $x, "what" => $x]]],
    ["type" => "link", "slug" => "guide-what", "book" => "tbox", "label" => $x],
  ]]]];
$problems = Wiki::validate($page, "community");
echo "VALID=", $problems === [] ? "yes" : implode(" | ", $problems), "\n";
$html = Wiki::renderPage("community", $page, []);
echo "RAW_SCRIPT=", substr_count($html, "<script"), "\n";
echo "RAW_IMG=", substr_count($html, "<img"), "\n";
echo "ON_ATTR=", preg_match_all("/<[^>]+ onerror=/", $html), "\n";
echo "ESCAPED=", substr_count($html, "&lt;script&gt;"), "\n";
echo "CROSS_LINK=", substr_count($html, "href=\"/tbox/wiki/tbox/guide-what\""), "\n";
echo "BADGE=", substr_count($html, ">4-A단계<"), "\n";
' "$ROOT" > "$WORK/xss.txt" 2>&1
cat "$WORK/xss.txt" | sed 's/^/  · /'
expect "XSS 글이 구조 검사를 통과(글자는 무엇이든 받는다)" "yes" "$(sed -n 's/^VALID=//p' "$WORK/xss.txt")"
expect "렌더 결과에 <script 0" "0" "$(sed -n 's/^RAW_SCRIPT=//p' "$WORK/xss.txt")"
expect "렌더 결과에 <img 0" "0" "$(sed -n 's/^RAW_IMG=//p' "$WORK/xss.txt")"
expect "렌더 결과에 onerror= 속성 0" "0" "$(sed -n 's/^ON_ATTR=//p' "$WORK/xss.txt")"
ESC=$(sed -n 's/^ESCAPED=//p' "$WORK/xss.txt")
expect "&lt;script&gt; 로 글자가 된 곳 ≥ 10" "yes" "$([ "${ESC:-0}" -ge 10 ] && echo yes || echo "no($ESC)")"
expect "다른 책 링크(book=tbox)가 /tbox/wiki/tbox/… 로" "1" "$(sed -n 's/^CROSS_LINK=//p' "$WORK/xss.txt")"
expect "커뮤니티 단계 배지 「4-A단계」" "1" "$(sed -n 's/^BADGE=//p' "$WORK/xss.txt")"

sub "잘못된 값은 구조 오류로 (조용히 넘기지 않는다)"
php -r '
require $argv[1] . "/src/Wiki.php";
$base = ["slug" => "zz", "title" => "t", "group" => "stage", "sections" => [["id" => "a", "heading" => "h", "blocks" => [["type" => "p", "text" => "x"]]]]];
$cases = ["tbox 29" => ["tbox", 29], "tbox \"27\"" => ["tbox", "27"], "community 3(정수)" => ["community", 3],
          "community 4-a" => ["community", "4-a"], "community post-4" => ["community", "post-4"], "qaredi Q0" => ["qaredi", "Q0"]];
foreach ($cases as $name => [$b, $s]) { $p = $base; $p["stage"] = $s; echo $name, "=", Wiki::validate($p, $b) === [] ? "통과" : "거부", "\n"; }
$p = $base; $p["stage"] = 28; echo "tbox 28=", Wiki::validate($p, "tbox") === [] ? "통과" : "거부", "\n";
$p = $base; $p["stage"] = "4+"; echo "community 4+=", Wiki::validate($p, "community") === [] ? "통과" : "거부", "\n";
$p = $base; $p["slug"] = "tbox"; $p["stage"] = 1; echo "slug=책이름=", Wiki::validate($p, "tbox") === [] ? "통과" : "거부", "\n";
' "$ROOT" > "$WORK/stage.txt" 2>&1
cat "$WORK/stage.txt" | sed 's/^/  · /'
expect "틀린 단계 번호 6종 전부 거부" "6" "$(grep -c '=거부$' "$WORK/stage.txt" | awk '{print $1-1}')"
expect "tbox 28 · community 4+ 통과" "2" "$(grep -cE '^(tbox 28|community 4\+)=통과$' "$WORK/stage.txt")"
expect "책 이름과 같은 slug 거부" "1" "$(grep -c '^slug=책이름=거부$' "$WORK/stage.txt")"

sub "HTTP — 이웃 글과 다른 책 링크"
get claude_admin01 /wiki/community/stage-4-d n4d
contains "4-D 「이전」 = 4-C" 'href="/tbox/wiki/community/stage-4-c"><span class="wiki-nav__dir">← 이전' "$WORK/n4d.body"
contains "4-D 「다음」 = 4단계 뒤(4+)" 'href="/tbox/wiki/community/stage-4-after"><span class="wiki-nav__dir">다음 →' "$WORK/n4d.body"
get claude_admin01 /wiki/tbox/stage-27 n27
contains "t-box 27 「다음」 = 28" 'href="/tbox/wiki/tbox/stage-28"><span class="wiki-nav__dir">다음 →' "$WORK/n27.body"
get claude_admin01 /wiki/qaredi/guide-site gsite
contains "Qaredi 안내 → t-box 책 링크" 'href="/tbox/wiki/tbox/guide-what"' "$WORK/gsite.body"
contains "Qaredi 안내 → 커뮤니티 책 링크" 'href="/tbox/wiki/community/guide-what"' "$WORK/gsite.body"

# ════════════════════════════════════════════════════════════════
hr "(g) 브라우저 — 눌러 보기 · 스크린샷(라이트 · 다크 · 390px)"
mkdir -p "$SHOTS"
COOKIES=$(awk '(!/^#/ || /^#HttpOnly_/) && NF>=7 {printf "%s=%s;", $6, $7}' "$JAR_DIR/claude_admin01.jar")
export PATH="$HOME/.local/bin:$PATH"
if python3 -c 'import playwright' 2>/dev/null; then
  py_secret COOKIES -- python3 "$ROOT/tests/wiki_books_browser.py" "$BASE" "$UA" "$SHOTS" > "$WORK/browser.txt" 2>&1
  sed 's/^/  · /' "$WORK/browser.txt"
  expect "브라우저 항목 실패 0" "0" "$(sed -n 's/^BROWSER_FAIL=//p' "$WORK/browser.txt")"
  echo "  스크린샷: $SHOTS ($(ls "$SHOTS"/*.png 2>/dev/null | wc -l)장)"
else
  skip "브라우저 항목" "playwright 없음"
fi

# ════════════════════════════════════════════════════════════════
hr "(h) 정적 — wiki_check · security_lint · php -l"
php "$ROOT/scripts/wiki_check.php" > "$WORK/wc.txt" 2>&1; rc=$?
tail -3 "$WORK/wc.txt" | sed 's/^/  · /'
expect "php scripts/wiki_check.php 종료 코드" "0" "$rc"
bash "$ROOT/scripts/security_lint.sh" > "$WORK/lint.txt" 2>&1; rc=$?
tail -2 "$WORK/lint.txt" | sed 's/^/  · /'
expect "scripts/security_lint.sh 종료 코드" "0" "$rc"
LINT_FAIL=0
for f in src/Wiki.php src/handlers/wiki_books.php src/handlers/wiki_index.php src/handlers/wiki_page.php \
         src/handlers/wiki_legacy.php scripts/wiki_check.php public/index.php; do
  php -l "$ROOT/$f" > /dev/null 2>&1 || { php -l "$ROOT/$f"; LINT_FAIL=$((LINT_FAIL+1)); }
done
expect "php -l 실패 0 (7파일)" "0" "$LINT_FAIL"

hr "결과: 통과 $PASS · 실패 $FAIL · 건너뜀 $SKIP"
[ "$FAIL" -eq 0 ]
