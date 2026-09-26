#!/usr/bin/env bash
# tests/community/stage4a.sh — 커뮤니티 4-A 수락 기준 C:T-22 ~ C:T-29 실행
#                              (../../../community/stage4a-tree-directive.md 「완료 기준」)
#
#   C:T-22 하위 페이지 3단   각 응답 201 · `GET` 의 breadcrumb 길이 1·2·3 · children 수가 맞다
#   C:T-23 깊이 9 시도       400 `depth_exceeded` · DB 불변(페이지 수가 늘지 않는다)
#   C:T-24 순환              자기 자신·자기 후손을 부모로 → 둘 다 400 `cycle` ·
#                            감사 `page.move deny cycle` 2건 · 트리 불변
#   C:T-25 남의 페이지       claude_employee02 의 페이지를 부모로 → 403 + 감사 `page.read deny not_owner` ·
#                            없는 UUID → 404
#   C:T-26 옮기기 + 순서     position 이 형제 안에서 0..n-1 로 다시 매겨진다 · 순서가 기대대로
#   C:T-27 부모 삭제         하위 전부 deleted_at · 목록에서 사라짐 · 감사 `cascaded` 건수 일치
#   C:T-28 child_page 저장   저장 JSON 에 child_page · GET 의 child_titles 에 제목 ·
#                            남의 페이지 id 는 **400 이 아니고** child_titles 에 없다(화면은 「볼 수 없는 페이지」)
#   C:T-29 단계 밖           7-C 에서 블록은 모두 열렸다 — 허용 목록 밖 embed → 400 `embed_not_allowed` · 단계 밖 마크(mention, 8단계) → 400 `mark_not_in_stage`
#   브라우저                 browser_smoke.py 의 4-A 항목(하위 페이지 만들기 → 카드 클릭 → 이동 →
#                            경로 → 뒤로 · 트리 접기·펼치기 · 옮기기 창)
#   회귀                     doc-schema 사본 diff 0 · security_lint 0 · php -l · bash -n
#
# 규칙 (CLAUDE.md 3장) — stage3.sh 와 같다
#   · 검토 계정 `claude_` 다섯뿐. 시드 계정은 사용자 몫.
#   · 모든 요청에 `-A "tbox-review/<날짜>"`.
#   · 운영 DB 에 DDL·DML 을 실행하지 않는다 (psql 은 **읽기**만).
#   · 만든 페이지는 **앱 창구로만** 되돌린다(논리 삭제).
#
# ★ 이 라운드에는 마이그레이션이 없다 — `pages.parent_page_id`·`position` 은 3단계 스키마에 이미 있다.
#
# 준비물: curl · php · python3 · sudo -n(seed-accounts.txt · qa-totp.txt · psql)
# 사용: sudo -v && bash tests/community/stage4a.sh
set -u

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
# 검토 계정 판정 한 벌(qa_·claude_ — F-71 C). 못 읽으면 사전 검사가 빈 값으로 열리므로 시작하지 않는다.
. "$ROOT/tests/lib/review_accounts.sh" || { echo "tests/lib/review_accounts.sh 를 읽지 못했다 — 시험을 시작하지 않는다 (R-73)"; exit 3; }
. "$ROOT/tests/lib/secrets.sh" || { echo "tests/lib/secrets.sh 를 읽지 못했다 — 시험을 시작하지 않는다 (R-62)"; exit 3; }
. "$ROOT/tests/lib/db_read.sh" || { echo "tests/lib/db_read.sh 를 읽지 못했다 — 시험을 시작하지 않는다 (R-73)"; exit 3; }
SITE="http://127.0.0.1:8082"
BASE="$SITE/tbox"
CBASE="$SITE/community"
UA="tbox-review/$(date +%F)"
DB="tbox_re"
JAR_DIR="$(mktemp -d)"
WORK="$(mktemp -d)"
PASS=0
FAIL=0
SKIP=0

MADE_PAGES=""     # 정리할 페이지 public_id (뿌리만 적는다 — 하위는 함께 내려간다)

cleanup() { rm -rf "$JAR_DIR" "$WORK"; }
trap cleanup EXIT

hr()  { printf '\n════════════════════════════════════════════════════════════\n%s\n════════════════════════════════════════════════════════════\n' "$1"; }
sub() { printf '\n── %s\n' "$1"; }
expect() {
  if [ "$2" = "$3" ]; then printf '  ✔ %s  (기대=%s 실제=%s)\n' "$1" "$2" "$3"; PASS=$((PASS+1))
  else printf '  ✖ %s  (기대=%s 실제=%s)\n' "$1" "$2" "$3"; FAIL=$((FAIL+1)); fi
}
contains() {
  case "$3" in
    *"$2"*) printf '  ✔ %s  (「%s」 포함)\n' "$1" "$2"; PASS=$((PASS+1)) ;;
    *)      printf '  ✖ %s  (「%s」 없음 — 실제: %s)\n' "$1" "$2" "$3"; FAIL=$((FAIL+1)) ;;
  esac
}
note() { printf '  · %s\n' "$1"; }
skip() { printf '  ○ SKIP %s  (%s)\n' "$1" "$2"; SKIP=$((SKIP+1)); }

psqlq() { sudo -n -u postgres psql -d "$DB" -t -A -c "$1" 2>/dev/null; }
mark()  { psqlq "SELECT coalesce(max(id),0) FROM audit_logs" 2>/dev/null; }

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

status_of() { sed -n 's#^HTTP/[0-9.]* \([0-9]*\).*#\1#p' "$1" | tail -1 | tr -d '\r'; }

qa_password() { sudo -n awk -v u="$1" '$1==u{print $3}' /etc/tbox-v2/seed-accounts.txt 2>/dev/null; }
qa_secret()   { sudo -n awk -v u="$1" '$1==u{print $2}' /etc/tbox-v2/qa-totp.txt 2>/dev/null; }

login() {
  local who="$1" jar="$JAR_DIR/$1.jar" pw sec raw code left out try body
  pw=$(qa_password "$who")
  sec=$(qa_secret "$who")
  [ -n "$pw" ] || return 2
  for try in 1 2 3; do
    if [ -n "$sec" ]; then
      raw=$(totp_now sec 2>/dev/null)
      code=${raw%%$'\n'*}
      printf -v body '{"username":"%s","password":"%s","otp":"%s"}' "$who" "$pw" "$code"
      out=$(curl_json_secret body -s -c "$jar" -A "$UA" -H 'Content-Type: application/json' "$BASE/api/login")
    else
      printf -v body '{"username":"%s","password":"%s"}' "$who" "$pw"
      out=$(curl_json_secret body -s -c "$jar" -A "$UA" -H 'Content-Type: application/json' "$BASE/api/login")
    fi
    case "$out" in
      *'"ok":true'*)
        curl -s -b "$jar" -A "$UA" "$BASE/api/me" \
          | sed -n 's/.*"csrf_token":"\([0-9a-f]*\)".*/\1/p'
        return 0 ;;
    esac
    left=$(printf '%s' "$raw" | sed -n 's/^남은 시간: \([0-9]*\)초.*/\1/p')
    [ -n "$left" ] || left=31
    printf '  · %s 로그인 재시도 — 다음 코드 창까지 %s초\n' "$who" "$left" >&2
    sleep $((left + 1))
  done
  return 1
}

# 커뮤니티 창구 호출 — $1 메서드 $2 경로 $3 계정 $4 본문 파일(없으면 생략)
capi() {
  local method="$1" path="$2" who="$3" bodyfile="${4:-}"
  local jar="$JAR_DIR/$who.jar" csrf
  eval "csrf=\${CSRF_${who}:-}"
  if [ -z "$bodyfile" ]; then
    curl -s -D "$WORK/h" -o "$WORK/b" -A "$UA" -b "$jar" -X "$method" \
      -H 'Expect:' -H "X-CSRF-Token: $csrf" "$CBASE$path"
  else
    curl -s -D "$WORK/h" -o "$WORK/b" -A "$UA" -b "$jar" -X "$method" \
      -H 'Expect:' -H 'Content-Type: application/json' -H "X-CSRF-Token: $csrf" \
      --data-binary "@$bodyfile" "$CBASE$path"
  fi
  status_of "$WORK/h"
}

# 페이지 하나 만들기 — $1 계정 $2 제목 $3 부모(없으면 뿌리). 표준출력에 "상태|id"
make_page() {
  local who="$1" title="$2" parent="${3:-}"
  if [ -z "$parent" ]; then
    printf '{"title":"%s"}' "$title" > "$WORK/new.json"
  else
    printf '{"title":"%s","parent_id":"%s"}' "$title" "$parent" > "$WORK/new.json"
  fi
  local code
  code=$(capi POST /api/pages "$who" "$WORK/new.json")
  printf '%s|%s' "$code" "$(jget "d['page']['id']" < "$WORK/b")"
}

# ════════════════════════════════════════════════════════════════
hr "커뮤니티 4-A — 시험 시작 전 확인"

avail_kb=$(df -Pk / | awk 'NR==2{print $4}')
echo "루트 파티션 여유: $(df -Ph / | awk 'NR==2{print $4}') (${avail_kb}KB)"
[ "$avail_kb" -ge 1048576 ] || { echo "루트 디스크 여유 1GB 미만 — 시험을 시작하지 않는다 (SECURITY R-80)"; exit 3; }

HAVE_PSQL=0
if psqlq "SELECT 1" >/dev/null 2>&1; then HAVE_PSQL=1; fi

READY=$(curl -s -o /dev/null -w '%{http_code}' -A "$UA" "$CBASE/api/pages")
if [ "$READY" = "503" ]; then
  echo "커뮤니티 스키마가 아직 적용되지 않았다 (창구가 503) — 3단계 마이그레이션을 먼저 돌린다."
  exit 3
fi

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
# 끝의 「시험이 만든 비-qa 행 0건」 대조의 시작 값 — 읽지 못하면 시작하지 않는다(R-73 · db_read)
if [ "$HAVE_PSQL" -eq 1 ]; then
  db_read_var AUDIT_START "$DB" "SELECT coalesce(max(id),0) FROM audit_logs" \
    || { echo "운영 DB 를 읽지 못했다 — 시험을 시작하지 않는다 (R-73 · db_read rc 3)"; exit 3; }
fi

sub "검토 계정 로그인"
for who in claude_employee01 claude_employee02; do
  token=$(login "$who") || token=""
  eval "CSRF_${who}=\"\$token\""
  if [ -n "$token" ]; then note "$who 로그인 OK"; else note "$who 로그인 실패 — 관련 항목 SKIP"; fi
done

CODE=$(capi GET /api/me/space claude_employee01)
expect "claude_employee01 개인 공간" "200" "$CODE"
CODE=$(capi GET /api/me/space claude_employee02)
expect "claude_employee02 개인 공간" "200" "$CODE"

# ════════════════════════════════════════════════════════════════
hr "C:T-22 — 하위 페이지 3단 (breadcrumb 1·2·3)"

R=$(make_page claude_employee01 "T22-1층");  expect "1층 만들기 201" "201" "${R%%|*}"; P1="${R##*|}"
[ -n "$P1" ] && MADE_PAGES="$MADE_PAGES $P1"
R=$(make_page claude_employee01 "T22-2층" "$P1"); expect "2층 만들기 201" "201" "${R%%|*}"; P2="${R##*|}"
R=$(make_page claude_employee01 "T22-3층" "$P2"); expect "3층 만들기 201" "201" "${R%%|*}"; P3="${R##*|}"
note "P1=$P1 P2=$P2 P3=$P3"

for pair in "1:$P1" "2:$P2" "3:$P3"; do
  want="${pair%%:*}"; pid="${pair##*:}"
  CODE=$(capi GET "/api/pages/$pid" claude_employee01)
  expect "$want층 GET 200" "200" "$CODE"
  expect "$want층 breadcrumb 길이" "$want" "$(jget "len(d['breadcrumb'])" < "$WORK/b")"
done

CODE=$(capi GET "/api/pages/$P1" claude_employee01)
expect "1층의 children 수" "1" "$(jget "len(d['children'])" < "$WORK/b")"
expect "1층 children[0] 의 하위 수(손자 1)" "1" "$(jget "d['children'][0]['child_count']" < "$WORK/b")"
CODE=$(capi GET "/api/pages/$P3" claude_employee01)
expect "3층의 children 수" "0" "$(jget "len(d['children'])" < "$WORK/b")"
expect "3층 breadcrumb 의 맨 앞은 1층" "T22-1층" "$(jget "d['breadcrumb'][0]['title']" < "$WORK/b")"
expect "3층 breadcrumb 의 맨 뒤는 자기 자신" "T22-3층" "$(jget "d['breadcrumb'][-1]['title']" < "$WORK/b")"

# ════════════════════════════════════════════════════════════════
hr "C:T-23 — 깊이 9 시도 (400 depth_exceeded · DB 불변)"

if [ "$HAVE_PSQL" -eq 1 ]; then
  N_BEFORE=$(psqlq "SELECT count(*) FROM community.pages WHERE deleted_at IS NULL")
else
  N_BEFORE=""
fi

DEEP="$P3"
OK_DEEP=1
for lvl in 4 5 6 7 8; do
  R=$(make_page claude_employee01 "T23-${lvl}층" "$DEEP")
  [ "${R%%|*}" = "201" ] || { expect "${lvl}층 만들기 201" "201" "${R%%|*}"; OK_DEEP=0; break; }
  DEEP="${R##*|}"
done
expect "8층까지 이어 만들기" "1" "$OK_DEEP"

R=$(make_page claude_employee01 "T23-9층" "$DEEP")
expect "9층 만들기 → 400" "400" "${R%%|*}"
expect "사유가 depth_exceeded" "depth_exceeded" "$(jget "d['reason']" < "$WORK/b")"

if [ "$HAVE_PSQL" -eq 1 ]; then
  N_AFTER=$(psqlq "SELECT count(*) FROM community.pages WHERE deleted_at IS NULL")
  expect "9층 거부 뒤 살아 있는 페이지 수가 8층까지의 5개만 늘었다 (9층은 저장되지 않았다)" \
    "$((N_BEFORE + 5))" "$N_AFTER"
else
  skip "깊이 9 거부 뒤 DB 불변" "psql 없음 — **미검증**"
fi

# 깊이 8짜리 가지(P1…)를 다른 가지 아래로 옮기면 9가 된다 → 같은 거부
R=$(make_page claude_employee01 "T23-옆가지"); expect "옆가지 만들기 201" "201" "${R%%|*}"; SIDE="${R##*|}"
[ -n "$SIDE" ] && MADE_PAGES="$MADE_PAGES $SIDE"
printf '{"parent_id":"%s"}' "$SIDE" > "$WORK/mv.json"
CODE=$(capi PATCH "/api/pages/$P1/move" claude_employee01 "$WORK/mv.json")
expect "깊이 8 가지를 한 겹 더 깊이 옮기기 → 400" "400" "$CODE"
expect "사유가 depth_exceeded" "depth_exceeded" "$(jget "d['reason']" < "$WORK/b")"
CODE=$(capi GET "/api/pages/$P1" claude_employee01)
expect "거부 뒤 1층은 그대로 뿌리다 (breadcrumb 길이 1)" "1" "$(jget "len(d['breadcrumb'])" < "$WORK/b")"

# ════════════════════════════════════════════════════════════════
hr "C:T-24 — 순환 (자기 자신 · 자기 후손)"

[ "$HAVE_PSQL" -eq 1 ] && CYCLE_MARK=$(mark) || CYCLE_MARK=0

printf '{"parent_id":"%s"}' "$P1" > "$WORK/mv.json"
CODE=$(capi PATCH "/api/pages/$P1/move" claude_employee01 "$WORK/mv.json")
expect "자기 자신을 부모로 → 400" "400" "$CODE"
expect "사유가 cycle" "cycle" "$(jget "d['reason']" < "$WORK/b")"

printf '{"parent_id":"%s"}' "$P3" > "$WORK/mv.json"
CODE=$(capi PATCH "/api/pages/$P1/move" claude_employee01 "$WORK/mv.json")
expect "자기 후손(3층)을 부모로 → 400" "400" "$CODE"
expect "사유가 cycle" "cycle" "$(jget "d['reason']" < "$WORK/b")"

CODE=$(capi GET "/api/pages/$P3" claude_employee01)
expect "트리 불변 — 3층 breadcrumb 길이 그대로" "3" "$(jget "len(d['breadcrumb'])" < "$WORK/b")"

if [ "$HAVE_PSQL" -eq 1 ]; then
  expect "감사 page.move deny cycle 2건" "2" \
    "$(psqlq "SELECT count(*) FROM audit_logs WHERE id > ${CYCLE_MARK} AND action='page.move' AND result='deny' AND reason='cycle'")"
  note "문장: $(psqlq "SELECT summary FROM audit_logs WHERE id > ${CYCLE_MARK} AND action='page.move' AND reason='cycle' ORDER BY id DESC LIMIT 1")"
else
  skip "감사 page.move deny cycle" "psql 없음 — **미검증**"
fi

# ════════════════════════════════════════════════════════════════
hr "C:T-25 — 남의 페이지를 부모로 · 없는 UUID"

R=$(make_page claude_employee02 "T25-남의 페이지")
expect "claude_employee02 페이지 만들기 201" "201" "${R%%|*}"
OTHER="${R##*|}"
note "남의 페이지 id=$OTHER"

[ "$HAVE_PSQL" -eq 1 ] && OTHER_MARK=$(mark) || OTHER_MARK=0

printf '{"parent_id":"%s"}' "$OTHER" > "$WORK/mv.json"
CODE=$(capi PATCH "/api/pages/$P1/move" claude_employee01 "$WORK/mv.json")
expect "남의 페이지를 부모로(옮기기) → 403" "403" "$CODE"

printf '{"title":"T25-남의 것 아래","parent_id":"%s"}' "$OTHER" > "$WORK/new2.json"
CODE=$(capi POST /api/pages claude_employee01 "$WORK/new2.json")
expect "남의 페이지를 부모로(만들기) → 403" "403" "$CODE"

printf '{"parent_id":"00000000-0000-4000-8000-00000000dead"}' > "$WORK/mv.json"
CODE=$(capi PATCH "/api/pages/$P1/move" claude_employee01 "$WORK/mv.json")
expect "없는 UUID 를 부모로(옮기기) → 404" "404" "$CODE"

printf '{"title":"T25-없는 부모","parent_id":"00000000-0000-4000-8000-00000000dead"}' > "$WORK/new2.json"
CODE=$(capi POST /api/pages claude_employee01 "$WORK/new2.json")
expect "없는 UUID 를 부모로(만들기) → 404" "404" "$CODE"

if [ "$HAVE_PSQL" -eq 1 ]; then
  expect "감사 page.read deny not_owner 2건 (옮기기·만들기)" "2" \
    "$(psqlq "SELECT count(*) FROM audit_logs WHERE id > ${OTHER_MARK} AND action='page.read' AND result='deny' AND reason='not_owner'")"
  expect "없는 UUID 는 감사를 남기지 않는다 (닿은 행이 없다)" "2" \
    "$(psqlq "SELECT count(*) FROM audit_logs WHERE id > ${OTHER_MARK} AND action='page.read' AND result='deny'")"
else
  skip "남의 페이지 감사" "psql 없음 — **미검증**"
fi

# ════════════════════════════════════════════════════════════════
hr "C:T-26 — 옮기기 + 순서 (position 0..n-1)"

R=$(make_page claude_employee01 "T26-상자"); expect "상자 만들기 201" "201" "${R%%|*}"; BOX="${R##*|}"
[ -n "$BOX" ] && MADE_PAGES="$MADE_PAGES $BOX"
R=$(make_page claude_employee01 "T26-가" "$BOX"); C1="${R##*|}"; expect "가 201" "201" "${R%%|*}"
R=$(make_page claude_employee01 "T26-나" "$BOX"); C2="${R##*|}"; expect "나 201" "201" "${R%%|*}"
R=$(make_page claude_employee01 "T26-다" "$BOX"); C3="${R##*|}"; expect "다 201" "201" "${R%%|*}"

order_of() {   # 상자의 하위 제목을 position 순으로 한 줄에
  capi GET "/api/pages/$BOX" claude_employee01 >/dev/null
  jget "','.join(c['title'] for c in d['children'])" < "$WORK/b"
}
pos_of() {
  capi GET "/api/pages/$BOX" claude_employee01 >/dev/null
  jget "','.join(str(c['position']) for c in d['children'])" < "$WORK/b"
}

expect "만든 차례대로 가·나·다" "T26-가,T26-나,T26-다" "$(order_of)"
expect "position 이 0,1,2" "0,1,2" "$(pos_of)"

printf '{"parent_id":"%s","position":0}' "$BOX" > "$WORK/mv.json"
CODE=$(capi PATCH "/api/pages/$C3/move" claude_employee01 "$WORK/mv.json")
expect "「다」를 맨 앞(0)으로 → 200" "200" "$CODE"
expect "차례가 다·가·나" "T26-다,T26-가,T26-나" "$(order_of)"
expect "position 이 0,1,2 로 다시 매겨졌다" "0,1,2" "$(pos_of)"

printf '{"parent_id":"%s","position":99}' "$BOX" > "$WORK/mv.json"
CODE=$(capi PATCH "/api/pages/$C3/move" claude_employee01 "$WORK/mv.json")
expect "범위 밖 position 99 → 거부하지 않고 맨 뒤" "200" "$CODE"
expect "차례가 가·나·다" "T26-가,T26-나,T26-다" "$(order_of)"
expect "position 이 여전히 0,1,2" "0,1,2" "$(pos_of)"

printf '{"parent_id":null}' > "$WORK/mv.json"
CODE=$(capi PATCH "/api/pages/$C2/move" claude_employee01 "$WORK/mv.json")
expect "「나」를 뿌리로 → 200" "200" "$CODE"
[ -n "$C2" ] && MADE_PAGES="$MADE_PAGES $C2"
expect "떠난 자리의 형제가 가·다 로 다시 매겨졌다" "T26-가,T26-다" "$(order_of)"
expect "그 자리 position 이 0,1" "0,1" "$(pos_of)"
CODE=$(capi GET "/api/pages/$C2" claude_employee01)
expect "「나」의 breadcrumb 길이 1 (뿌리다)" "1" "$(jget "len(d['breadcrumb'])" < "$WORK/b")"

# ════════════════════════════════════════════════════════════════
hr "C:T-27 — 부모 삭제 (하위까지 함께)"

R=$(make_page claude_employee01 "T27-뿌리"); DEL="${R##*|}"; expect "뿌리 만들기 201" "201" "${R%%|*}"
R=$(make_page claude_employee01 "T27-아들" "$DEL"); DSON="${R##*|}"; expect "아들 201" "201" "${R%%|*}"
R=$(make_page claude_employee01 "T27-손자" "$DSON"); DGSON="${R##*|}"; expect "손자 201" "201" "${R%%|*}"

[ "$HAVE_PSQL" -eq 1 ] && DEL_MARK=$(mark) || DEL_MARK=0

CODE=$(capi DELETE "/api/pages/$DEL" claude_employee01)
expect "부모 삭제 200" "200" "$CODE"
expect "지운 건수 3" "3" "$(jget "d['deleted']" < "$WORK/b")"

for pid in "$DEL" "$DSON" "$DGSON"; do
  CODE=$(capi GET "/api/pages/$pid" claude_employee01)
  expect "지운 뒤 GET → 404 ($pid)" "404" "$CODE"
done

CODE=$(capi GET "/api/pages?tree=1" claude_employee01)
expect "트리 목록 200" "200" "$CODE"
expect "트리에 T27- 이 하나도 없다" "0" \
  "$(jget "sum(1 for p in d['pages'] if p['title'].startswith('T27-'))" < "$WORK/b")"

if [ "$HAVE_PSQL" -eq 1 ]; then
  expect "감사 detail.cascaded 가 3" "3" \
    "$(psqlq "SELECT detail->>'cascaded' FROM audit_logs WHERE id > ${DEL_MARK} AND action='page.delete' AND result='allow' ORDER BY id DESC LIMIT 1")"
  expect "세 행 모두 deleted_at 이 찍혔다" "3" \
    "$(psqlq "SELECT count(*) FROM community.pages WHERE public_id IN ('$DEL','$DSON','$DGSON') AND deleted_at IS NOT NULL")"
else
  skip "삭제 감사·deleted_at" "psql 없음 — **미검증**"
fi

# ════════════════════════════════════════════════════════════════
hr "C:T-28 — child_page 블록 저장 · child_titles"

R=$(make_page claude_employee01 "T28-부모"); T28P="${R##*|}"; expect "부모 만들기 201" "201" "${R%%|*}"
[ -n "$T28P" ] && MADE_PAGES="$MADE_PAGES $T28P"
R=$(make_page claude_employee01 "T28-자식" "$T28P"); T28C="${R##*|}"; expect "자식 만들기 201" "201" "${R%%|*}"

CODE=$(capi GET "/api/pages/$T28P" claude_employee01)
VER=$(jget "d['page']['doc_version']" < "$WORK/b")

python3 - "$WORK" "$VER" "$T28C" "$OTHER" <<'PY'
import json, sys, uuid
work, ver, child, other = sys.argv[1], int(sys.argv[2]), sys.argv[3], sys.argv[4]
def u(): return str(uuid.uuid4())

# ① 내 하위 페이지를 가리키는 child_page
doc = {"schema": 1, "blocks": [
    {"id": u(), "type": "paragraph", "text": [{"t": "아래는 하위 페이지", "m": {}}]},
    {"id": u(), "type": "child_page", "page_id": child},
]}
json.dump({"doc_version": ver, "doc": doc}, open(work + "/t28_mine.json", "w"), ensure_ascii=False)

# ② 남의 페이지를 가리키는 child_page — **400 이 아니다**(읽기 판정은 렌더 시점)
doc2 = {"schema": 1, "blocks": [
    {"id": u(), "type": "child_page", "page_id": other},
]}
json.dump({"doc_version": ver + 1, "doc": doc2}, open(work + "/t28_other.json", "w"), ensure_ascii=False)

# ③ UUID 가 아닌 page_id — 형태 검사에는 걸린다
doc3 = {"schema": 1, "blocks": [
    {"id": u(), "type": "child_page", "page_id": "not-a-uuid"},
]}
json.dump({"doc_version": ver + 2, "doc": doc3}, open(work + "/t28_bad.json", "w"), ensure_ascii=False)
PY

CODE=$(capi PATCH "/api/pages/$T28P" claude_employee01 "$WORK/t28_mine.json")
expect "child_page 저장 200" "200" "$CODE"
CODE=$(capi GET "/api/pages/$T28P" claude_employee01)
expect "저장 JSON 의 둘째 블록이 child_page" "child_page" "$(jget "d['page']['doc']['blocks'][1]['type']" < "$WORK/b")"
expect "그 블록의 page_id 가 자식 id" "$T28C" "$(jget "d['page']['doc']['blocks'][1]['page_id']" < "$WORK/b")"
expect "child_titles 에 자식 제목이 있다" "T28-자식" "$(jget "d['child_titles']['$T28C']['title']" < "$WORK/b")"

CODE=$(capi PATCH "/api/pages/$T28P" claude_employee01 "$WORK/t28_other.json")
expect "남의 페이지 id 를 넣은 저장 → 400 이 아니다" "200" "$CODE"
CODE=$(capi GET "/api/pages/$T28P" claude_employee01)
expect "그래도 child_titles 는 비어 있다 (화면은 「볼 수 없는 페이지」)" "0" \
  "$(jget "len(d['child_titles'])" < "$WORK/b")"
note "렌더러 두 벌의 문구 확인:"
expect "DocRender.php 가 그 자리에 「볼 수 없는 페이지」" "1" \
  "$(php -r '
    require "'"$ROOT"'/src/bootstrap.php";
    require "'"$ROOT"'/src/community/Doc.php";
    require "'"$ROOT"'/src/community/DocRender.php";
    $doc = ["schema"=>1,"blocks"=>[["id"=>"11111111-1111-4111-8111-111111111111","type"=>"child_page","page_id"=>"22222222-2222-4222-8222-222222222222"]]];
    $html = DocRender::html($doc);
    echo (strpos($html, "볼 수 없는 페이지") !== false && strpos($html, "card--soon") !== false) ? "1" : "0";
  ' 2>/dev/null)"
expect "제목 지도를 주면 카드가 링크가 된다" "1" \
  "$(php -r '
    require "'"$ROOT"'/src/bootstrap.php";
    require "'"$ROOT"'/src/community/Doc.php";
    require "'"$ROOT"'/src/community/DocRender.php";
    $doc = ["schema"=>1,"blocks"=>[["id"=>"11111111-1111-4111-8111-111111111111","type"=>"child_page","page_id"=>"22222222-2222-4222-8222-222222222222"]]];
    $ctx = ["child_titles"=>["22222222-2222-4222-8222-222222222222"=>["title"=>"보이는 제목","icon"=>null]]];
    $html = DocRender::html($doc, $ctx);
    echo (strpos($html, "보이는 제목") !== false && strpos($html, "card--link") !== false) ? "1" : "0";
  ' 2>/dev/null)"

CODE=$(capi PATCH "/api/pages/$T28P" claude_employee01 "$WORK/t28_bad.json")
expect "UUID 형태가 아닌 page_id → 400" "400" "$CODE"
expect "어긋난 자리를 알려 준다" "blocks[0].page_id" "$(jget "d['errors'][0]['path']" < "$WORK/b")"

# ════════════════════════════════════════════════════════════════
hr "C:T-29 — 단계 밖 (7-C: 블록은 모두 열림 — 허용 목록 밖 embed · 8단계 mention 마크)"

CODE=$(capi GET "/api/pages/$T28P" claude_employee01)
VER=$(jget "d['page']['doc_version']" < "$WORK/b")

python3 - "$WORK" "$VER" <<'PY'
import json, sys, uuid
work, ver = sys.argv[1], int(sys.argv[2])
def u(): return str(uuid.uuid4())
json.dump({"doc_version": ver, "doc": {"schema": 1, "blocks": [
    {"id": u(), "type": "embed", "url": "https://example.com/"}]}},
    open(work + "/t29_toggle.json", "w"), ensure_ascii=False)
json.dump({"doc_version": ver, "doc": {"schema": 1, "blocks": [
    {"id": u(), "type": "paragraph", "text": [{"t": "x", "m": {"mention": {"kind": "user", "id": 1}}}]}]}},
    open(work + "/t29_table.json", "w"), ensure_ascii=False)
PY

CODE=$(capi PATCH "/api/pages/$T28P" claude_employee01 "$WORK/t29_toggle.json")
expect "허용 목록 밖 embed 저장 → 400" "400" "$CODE"
expect "사유가 embed_not_allowed" "embed_not_allowed" "$(jget "d['reason']" < "$WORK/b")"

CODE=$(capi PATCH "/api/pages/$T28P" claude_employee01 "$WORK/t29_table.json")
expect "단계 밖 마크(mention — 8단계) 저장 → 400" "400" "$CODE"
expect "사유가 mark_not_in_stage" "mark_not_in_stage" "$(jget "d['errors'][0]['reason']" < "$WORK/b")"

note "단계 표: $(python3 -c "import json;print(json.load(open('$ROOT/public/assets/community/doc-schema.json'))['stage'])")"
expect "COMMUNITY_DOC_STAGE 가 7 (7-C)" "7" \
  "$(php -r 'require "'"$ROOT"'/src/bootstrap.php"; echo COMMUNITY_DOC_STAGE;' 2>/dev/null)"

# ════════════════════════════════════════════════════════════════
hr "브라우저 — 4-A 항목 (진짜 브라우저로 눌러 본다)"

SMOKE_OUT="$WORK/smoke.txt"
SESSION_VALUE=$(awk '$6=="__Host-tbox_session"{print $7}' "$JAR_DIR/claude_employee01.jar" 2>/dev/null)

if ! python3 -c "import playwright" >/dev/null 2>&1; then
  skip "브라우저 스모크" "Playwright 가 없다 — **미검증**"
elif [ -z "$SESSION_VALUE" ]; then
  skip "브라우저 스모크" "claude_employee01 세션 쿠키를 얻지 못했다 — **미검증**"
else
  # browser_smoke.py 는 3-B 항목(1~19)에 이어 4-A 항목(21~26)을 같은 창에서 돈다.
  # 창을 두 번 띄우지 않으려고 나누지 않았다 — 아래 출력은 4-A 구간부터 보인다.
  # 세션 값은 표준입력 첫 줄로 넘긴다(명령 인자에 싣지 않는다 — tests/lib/secrets.sh py_secret · R-62)
  PATH="$HOME/.local/bin:$PATH" \
    py_secret SESSION_VALUE -- python3 "$ROOT/tests/community/browser_smoke.py" "$SITE" "$UA" \
    > "$SMOKE_OUT" 2>&1
  if grep -q '^SMOKE_FAIL=' "$SMOKE_OUT"; then
    sed -n '/^── 21\./,$p' "$SMOKE_OUT" | head -80
    expect "브라우저에서 pageerror 0건" "PAGEERROR=0" "$(grep '^PAGEERROR=' "$SMOKE_OUT")"
    expect "브라우저에서 콘솔 오류 0건" "CONSOLEERR=0" "$(grep '^CONSOLEERR=' "$SMOKE_OUT")"
    expect "브라우저에서 실패한 요청 0건" "REQFAIL=0" "$(grep '^REQFAIL=' "$SMOKE_OUT")"
    expect "브라우저에서 HTTP 400 이상 0건" "HTTP4XX=0" "$(grep '^HTTP4XX=' "$SMOKE_OUT")"
    expect "스모크 항목 실패 0건" "SMOKE_FAIL=0" "$(grep '^SMOKE_FAIL=' "$SMOKE_OUT")"
    note "브라우저 내부 CSP 인라인 style 차단: $(grep '^CSPSTYLE=' "$SMOKE_OUT")"
  else
    skip "브라우저 스모크" "브라우저를 열지 못했다 — **미검증**"
    note "$(tail -4 "$SMOKE_OUT" | tr '\n' ' ')"
  fi
fi

# ════════════════════════════════════════════════════════════════
hr "회귀 — 스키마 사본 · 린트 · 문법"

expect "doc-schema.json 사본이 원본과 같다" "0" \
  "$(diff "$ROOT/../community/doc-schema.json" "$ROOT/public/assets/community/doc-schema.json" >/dev/null 2>&1; echo $?)"

bash "$ROOT/scripts/security_lint.sh" > "$WORK/lint.txt" 2>&1
expect "금지 패턴 0건" "0" "$?"
tail -2 "$WORK/lint.txt"

n_bad=0
for f in $(find "$ROOT/src/community" -name '*.php') "$ROOT/src/Audit.php" "$ROOT/src/bootstrap.php"; do
  php -l "$f" >/dev/null 2>&1 || { n_bad=$((n_bad+1)); echo "  php -l 실패: $f"; }
done
expect "바뀐 PHP 문법 오류 0건" "0" "$n_bad"
bash -n "$0" && expect "이 시험 파일 bash -n" "0" "0" || expect "이 시험 파일 bash -n" "0" "1"
python3 -c 'import sys; compile(open(sys.argv[1], encoding="utf-8").read(), sys.argv[1], "exec")' \
  "$ROOT/tests/community/browser_smoke.py" 2>/dev/null \
  && expect "browser_smoke.py 구문" "0" "0" || expect "browser_smoke.py 구문" "0" "1"

# ─── 정리 ───────────────────────────────────────────────────────
hr "정리 — 만든 페이지는 앱 창구로 되돌린다 (하위는 함께 내려간다)"
for pid in $MADE_PAGES; do
  CODE=$(capi DELETE "/api/pages/$pid" claude_employee01)
  printf '  · 페이지 %s 논리 삭제 [%s]\n' "$pid" "$CODE"
done
if [ -n "${OTHER:-}" ]; then
  CODE=$(capi DELETE "/api/pages/$OTHER" claude_employee02)
  printf '  · claude_employee02 페이지 %s 논리 삭제 [%s]\n' "$OTHER" "$CODE"
fi

if [ "$HAVE_PSQL" -eq 1 ]; then
  expect "시험이 만든 비-qa 행 0건 (검토 UA 기준 — 사용자 데이터 불변)" "0" \
    "$(db_read "$DB" "SELECT count(*) FROM audit_logs
                WHERE id > ${AUDIT_START:-0}
                  AND actor_name IS NOT NULL AND $REVIEW_NOT_SQL
                  AND COALESCE(user_agent, '') LIKE 'tbox-review/%'")"
  note "시험이 만든 감사 행(전체): $(psqlq "SELECT count(*) FROM audit_logs WHERE id > ${AUDIT_START:-0}")건"
  note "남은 qa 페이지: $(psqlq "SELECT count(*) FROM community.pages p JOIN community.spaces s ON s.id=p.space_id JOIN users u ON u.id=s.owner_user_id WHERE $(review_like_sql u.username) AND p.deleted_at IS NULL")건 (「시작하기」 페이지는 남는다)"
fi

# ─── 마무리 ─────────────────────────────────────────────────────
hr "결과"
printf '통과 %d · 실패 %d · 건너뜀/미검증 %d\n' "$PASS" "$FAIL" "$SKIP"
echo "작업 폴더 $WORK 는 종료와 함께 지워진다."
[ "$FAIL" -eq 0 ] || exit 1
exit 0
