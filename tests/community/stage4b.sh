#!/usr/bin/env bash
# tests/community/stage4b.sh — 커뮤니티 4-B 수락 기준 C:T-30 ~ C:T-37 실행
#                              (../../../community/stage4b-visibility-directive.md 「완료 기준」)
#
#   C:T-30 팀 범위       qa_employee01 이 페이지를 `team` 으로 → 200 ·
#                        같은 팀 qa_manager01 200 읽기(감사 `page.read allow same_team`) ·
#                        다른 팀 qa_employee02 403 · qa_guest01 403
#   C:T-31 사내 범위     `internal` 로 → qa_employee02 200 · qa_guest01 403(Guest 제외) · Admin 200
#   C:T-32 남이 고치기   `team`·`internal` 이어도 소유자 아닌 사람의 PATCH 는 403
#                        (본문 PATCH · 공개 범위 PATCH 둘 다)
#   C:T-33 넓히기        부모 `private`, 자식을 `internal` 로 → 400 `wider_than_parent` · 감사 · DB 불변
#   C:T-34 좁히기        부모 `internal`→`private`, 하위에 `internal` 존재 →
#                        cascade 없이 400 `children_wider` / `cascade:true` 면 200 + 하위 전부 `private`
#                        + **항목마다 감사**
#   C:T-35 목록 격리     qa_employee02 의 `GET /api/pages?space=<employee01 개인공간>` → 403 ·
#                        employee02 의 개인 공간 목록 원문에 employee01 의 제목 0건 ·
#                        `internal` 페이지는 직접 읽기로는 제목이 보인다
#   C:T-36 팀 공간       `GET /api/spaces` 가 내 팀 공간을 만들어 돌려준다(두 번째는 만들지 않는다 —
#                        행 수 불변) · 팀원은 그 공간에 페이지를 만들고 고칠 수 있다 · 다른 팀은 403
#   C:T-37 판정 위치     목록은 SQL `WHERE` 로 걸러진다 — psql 로 센 「볼 수 있는 행 수」와
#                        API 가 준 수가 같다 · 응답 원문에 볼 수 없는 제목 0건
#   회귀                 security_lint 0 · php -l · bash -n · doc-schema 사본 diff 0
#
# 규칙 (CLAUDE.md 3장) — stage3.sh·stage4a.sh 와 같다
#   · 검토 계정 `qa_` 다섯뿐. 시드 계정은 사용자 몫.
#   · 모든 요청에 `-A "tbox-review/<날짜>"`.
#   · 운영 DB 에 DDL·DML 을 실행하지 않는다 (psql 은 **읽기**만).
#   · 만든 페이지는 **앱 창구로만** 되돌린다(논리 삭제). 만들어진 팀 공간은 남는다 —
#     지우는 창구가 없고, 지우는 일은 DML 이라 이 시험이 할 일이 아니다.
#
# ★ 이 라운드에는 마이그레이션이 없다 — `spaces.kind`·`team_id`·`pages.visibility` 는
#   3단계 스키마에 이미 있다.
#
# 준비물: curl · php · python3 · sudo -n(seed-accounts.txt · qa-totp.txt · psql)
# 사용: sudo -v && bash tests/community/stage4b.sh
set -u

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
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
lacks() {
  case "$3" in
    *"$2"*) printf '  ✖ %s  (「%s」 가 응답에 있다 — 실제: %s)\n' "$1" "$2" "$3"; FAIL=$((FAIL+1)) ;;
    *)      printf '  ✔ %s  (「%s」 없음)\n' "$1" "$2"; PASS=$((PASS+1)) ;;
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
  local who="$1" jar="$JAR_DIR/$1.jar" pw sec raw code left out try
  pw=$(qa_password "$who")
  sec=$(qa_secret "$who")
  [ -n "$pw" ] || return 2
  for try in 1 2 3; do
    if [ -n "$sec" ]; then
      raw=$(php "$ROOT/scripts/totp_code.php" "$sec" 2>/dev/null)
      code=${raw%%$'\n'*}
      out=$(curl -s -c "$jar" -A "$UA" -H 'Content-Type: application/json' \
            -d "{\"username\":\"$who\",\"password\":\"$pw\",\"otp\":\"$code\"}" "$BASE/api/login")
    else
      out=$(curl -s -c "$jar" -A "$UA" -H 'Content-Type: application/json' \
            -d "{\"username\":\"$who\",\"password\":\"$pw\"}" "$BASE/api/login")
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

# 페이지 하나 만들기 — $1 계정 $2 제목 $3 부모(없으면 뿌리) $4 공간(없으면 내 개인 공간)
make_page() {
  local who="$1" title="$2" parent="${3:-}" space="${4:-}"
  python3 - "$title" "$parent" "$space" > "$WORK/new.json" <<'PY'
import json, sys
body = {"title": sys.argv[1]}
if sys.argv[2]:
    body["parent_id"] = sys.argv[2]
if sys.argv[3]:
    body["space_id"] = int(sys.argv[3])
print(json.dumps(body, ensure_ascii=False))
PY
  local code
  code=$(capi POST /api/pages "$who" "$WORK/new.json")
  printf '%s|%s' "$code" "$(jget "d['page']['id']" < "$WORK/b")"
}

# 공개 범위 바꾸기 — $1 계정 $2 페이지 $3 값 $4 cascade(true/false, 없으면 생략)
set_vis() {
  local who="$1" page="$2" vis="$3" casc="${4:-}"
  if [ -z "$casc" ]; then
    printf '{"visibility":"%s"}' "$vis" > "$WORK/vis.json"
  else
    printf '{"visibility":"%s","cascade":%s}' "$vis" "$casc" > "$WORK/vis.json"
  fi
  capi PATCH "/api/pages/$page/visibility" "$who" "$WORK/vis.json"
}

vis_of() { psqlq "SELECT visibility FROM community.pages WHERE public_id='$1'"; }

# ════════════════════════════════════════════════════════════════
hr "커뮤니티 4-B — 시험 시작 전 확인"

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

if [ "$HAVE_PSQL" = "1" ]; then
  echo "· qa 계정의 팀 구성 (판정의 전제 — 이 표 위에서 시험을 짠다)"
  psqlq "SELECT u.username || ' / ' || u.role || ' / ' || coalesce(t.name,'(팀 없음)')
           FROM users u LEFT JOIN teams t ON t.id = u.team_id
          WHERE u.username IN ('qa_admin01','qa_manager01','qa_employee01','qa_employee02','qa_guest01')
          ORDER BY u.id" | sed 's/^/    /'
else
  echo "psql 을 쓸 수 없다 — DB·감사 확인 항목은 SKIP 된다"
fi

AUDIT_START=$(mark); AUDIT_START=${AUDIT_START:-0}

sub "로그인 (qa 계정 다섯)"
for who in qa_admin01 qa_manager01 qa_employee01 qa_employee02 qa_guest01; do
  tok=$(login "$who")
  if [ -z "$tok" ]; then echo "  ✖ $who 로그인 실패 — 중단"; exit 4; fi
  eval "CSRF_${who}='$tok'"
  echo "  ✔ $who"
done

# ════════════════════════════════════════════════════════════════
hr "C:T-36  팀 공간 — 없으면 만든다 · 두 번째는 만들지 않는다 · 다른 팀은 403"

SPACES_BEFORE=$(psqlq "SELECT count(*) FROM community.spaces WHERE kind='team'")

CODE=$(capi GET /api/spaces qa_employee01)
expect "GET /api/spaces (qa_employee01)" "200" "$CODE"
SP_RAW1=$(cat "$WORK/b")
note "응답: $SP_RAW1"
TEAM_SPACE=$(jget "[s['id'] for s in d['spaces'] if s['kind']=='team'][0]" < "$WORK/b")
MY_SPACE=$(jget "[s['id'] for s in d['spaces'] if s['kind']=='personal'][0]" < "$WORK/b")
contains "내 팀 공간(개발팀)이 목록에 있다" '"kind":"team"' "$SP_RAW1"
note "팀 공간 번호=$TEAM_SPACE · 내 개인 공간 번호=$MY_SPACE"

CODE=$(capi GET /api/spaces qa_employee01)
SP_RAW2=$(cat "$WORK/b")
expect "두 번째 호출도 200" "200" "$CODE"
contains "두 번째 호출은 만들지 않았다 (created=false)" '"created":false' "$SP_RAW2"

if [ "$HAVE_PSQL" = "1" ]; then
  # 같은 팀의 다른 사람이 불러도 행이 늘지 않는다.
  capi GET /api/spaces qa_manager01 >/dev/null
  SPACES_AFTER=$(psqlq "SELECT count(*) FROM community.spaces WHERE kind='team' AND team_id=1")
  expect "개발팀의 팀 공간은 정확히 1행 (UNIQUE(team_id) WHERE kind='team')" "1" "$SPACES_AFTER"
  note "시험 전 팀 공간 수=$SPACES_BEFORE · 지금(개발팀)=$SPACES_AFTER"
  note "감사: $(psqlq "SELECT summary FROM audit_logs WHERE id > ${AUDIT_START} AND action='space.create' ORDER BY id DESC LIMIT 1")"
else
  skip "팀 공간 행 수 불변" "psql 없음 — **미검증**"
fi

sub "팀원은 그 공간에 페이지를 만들고 고칠 수 있다"
RES=$(make_page qa_employee01 "팀공간-4B-원본" "" "$TEAM_SPACE")
TEAM_PAGE=${RES#*|}
expect "qa_employee01 이 팀 공간에 페이지 만들기" "201" "${RES%%|*}"
MADE_PAGES="$MADE_PAGES $TEAM_PAGE"

CODE=$(capi GET "/api/pages/$TEAM_PAGE" qa_manager01)
expect "같은 팀 qa_manager01 이 그 페이지를 읽는다" "200" "$CODE"
contains "granted_by=team_member" '"granted_by":"team_member"' "$(cat "$WORK/b")"
contains "can_edit=true (팀원은 함께 고친다)" '"can_edit":true' "$(cat "$WORK/b")"

printf '{"doc_version":1,"title":"팀공간-4B-원본(매니저가 고침)"}' > "$WORK/p.json"
CODE=$(capi PATCH "/api/pages/$TEAM_PAGE" qa_manager01 "$WORK/p.json")
expect "같은 팀 qa_manager01 이 그 페이지를 고친다" "200" "$CODE"

CODE=$(capi GET "/api/pages/$TEAM_PAGE" qa_employee02)
expect "다른 팀 qa_employee02 는 그 페이지를 못 읽는다" "403" "$CODE"
CODE=$(capi GET "/api/pages?space=$TEAM_SPACE" qa_employee02)
expect "다른 팀 qa_employee02 의 팀 공간 목록 요청" "403" "$CODE"
RES=$(make_page qa_employee02 "남의-팀공간에-만들기" "" "$TEAM_SPACE")
expect "다른 팀 qa_employee02 가 그 공간에 만들기" "403" "${RES%%|*}"

CODE=$(capi GET "/api/pages?space=$TEAM_SPACE" qa_manager01)
expect "같은 팀 qa_manager01 의 팀 공간 목록 요청" "200" "$CODE"
contains "팀 공간 요약이 응답에 있다" '"kind":"team"' "$(cat "$WORK/b")"

# ════════════════════════════════════════════════════════════════
hr "C:T-30  「team」 범위 — 같은 팀만 본다"

RES=$(make_page qa_employee01 "4B-범위시험-부모")
ROOT_PAGE=${RES#*|}
expect "개인 공간에 페이지 만들기" "201" "${RES%%|*}"
MADE_PAGES="$MADE_PAGES $ROOT_PAGE"
contains "새 페이지는 비공개로 시작한다" '"visibility":"private"' "$(cat "$WORK/b")"

T30_MARK=$(mark)
CODE=$(set_vis qa_employee01 "$ROOT_PAGE" team)
expect "소유자가 team 으로 바꾼다" "200" "$CODE"
note "응답: $(cat "$WORK/b")"
[ "$HAVE_PSQL" = "1" ] && expect "DB 의 visibility" "team" "$(vis_of "$ROOT_PAGE")"

CODE=$(capi GET "/api/pages/$ROOT_PAGE" qa_manager01)
expect "같은 팀 qa_manager01 읽기" "200" "$CODE"
contains "granted_by=same_team" '"granted_by":"same_team"' "$(cat "$WORK/b")"
contains "can_edit=false (보는 것과 고치는 것은 다르다)" '"can_edit":false' "$(cat "$WORK/b")"

CODE=$(capi GET "/api/pages/$ROOT_PAGE" qa_employee02)
expect "다른 팀 qa_employee02 읽기" "403" "$CODE"
CODE=$(capi GET "/api/pages/$ROOT_PAGE" qa_guest01)
expect "qa_guest01 읽기" "403" "$CODE"

if [ "$HAVE_PSQL" = "1" ]; then
  expect "감사 page.read allow same_team 1건" "1" \
    "$(psqlq "SELECT count(*) FROM audit_logs WHERE id > ${T30_MARK} AND action='page.read' AND result='allow' AND reason='same_team'")"
  note "문장: $(psqlq "SELECT summary FROM audit_logs WHERE id > ${T30_MARK} AND action='page.read' AND reason='same_team' ORDER BY id DESC LIMIT 1")"
  note "감사 page.visibility allow: $(psqlq "SELECT summary || ' | ' || detail::text FROM audit_logs WHERE id > ${T30_MARK} AND action='page.visibility' AND result='allow' ORDER BY id LIMIT 1")"
else
  skip "감사 same_team" "psql 없음 — **미검증**"
fi

# ════════════════════════════════════════════════════════════════
hr "C:T-31  「internal」 범위 — 로그인 전원, Guest 제외"

CODE=$(set_vis qa_employee01 "$ROOT_PAGE" internal)
expect "소유자가 internal 로 바꾼다" "200" "$CODE"
[ "$HAVE_PSQL" = "1" ] && expect "DB 의 visibility" "internal" "$(vis_of "$ROOT_PAGE")"

CODE=$(capi GET "/api/pages/$ROOT_PAGE" qa_employee02)
expect "다른 팀 qa_employee02 읽기" "200" "$CODE"
INTERNAL_BODY=$(cat "$WORK/b")
contains "granted_by=matrix" '"granted_by":"matrix"' "$INTERNAL_BODY"
contains "제목이 응답 원문에 보인다" '4B-범위시험-부모' "$INTERNAL_BODY"

CODE=$(capi GET "/api/pages/$ROOT_PAGE" qa_guest01)
expect "qa_guest01 읽기 (Guest 제외)" "403" "$CODE"
CODE=$(capi GET "/api/pages/$ROOT_PAGE" qa_admin01)
expect "qa_admin01 읽기" "200" "$CODE"

CODE=$(set_vis qa_employee01 "$ROOT_PAGE" published)
expect "published 는 화면·API 가 받지 않는다 (5단계)" "400" "$CODE"

# ════════════════════════════════════════════════════════════════
hr "C:T-32  남이 고치기 — 보이는 것과 고치는 것은 다르다"

printf '{"doc_version":1,"title":"남이 고친 제목"}' > "$WORK/p.json"
CODE=$(capi PATCH "/api/pages/$ROOT_PAGE" qa_employee02 "$WORK/p.json")
expect "internal 페이지를 남(qa_employee02)이 본문 PATCH" "403" "$CODE"
CODE=$(capi PATCH "/api/pages/$ROOT_PAGE" qa_manager01 "$WORK/p.json")
expect "internal 페이지를 같은 팀 qa_manager01 이 본문 PATCH" "403" "$CODE"
CODE=$(capi PATCH "/api/pages/$ROOT_PAGE" qa_admin01 "$WORK/p.json")
expect "internal 페이지를 Admin 이 본문 PATCH" "403" "$CODE"

T32_MARK=$(mark)
CODE=$(set_vis qa_employee02 "$ROOT_PAGE" private)
expect "남(qa_employee02)이 공개 범위 PATCH" "403" "$CODE"
CODE=$(set_vis qa_manager01 "$ROOT_PAGE" private)
expect "같은 팀 qa_manager01 이 공개 범위 PATCH" "403" "$CODE"
CODE=$(set_vis qa_admin01 "$ROOT_PAGE" private)
expect "Admin 이 공개 범위 PATCH" "403" "$CODE"
[ "$HAVE_PSQL" = "1" ] && expect "DB 는 그대로 internal" "internal" "$(vis_of "$ROOT_PAGE")"
if [ "$HAVE_PSQL" = "1" ]; then
  expect "감사 page.visibility deny not_owner 3건" "3" \
    "$(psqlq "SELECT count(*) FROM audit_logs WHERE id > ${T32_MARK} AND action='page.visibility' AND result='deny' AND reason='not_owner'")"
  note "문장: $(psqlq "SELECT summary FROM audit_logs WHERE id > ${T32_MARK} AND action='page.visibility' AND reason='not_owner' ORDER BY id DESC LIMIT 1")"
fi

# ════════════════════════════════════════════════════════════════
hr "C:T-33  넓히기 — 자식은 부모보다 넓을 수 없다"

# 부모를 private 로 되돌리고(하위가 없으므로 cascade 불필요) 자식을 만든다.
CODE=$(set_vis qa_employee01 "$ROOT_PAGE" private)
expect "부모를 private 로 되돌린다" "200" "$CODE"

RES=$(make_page qa_employee01 "4B-범위시험-자식" "$ROOT_PAGE")
CHILD=${RES#*|}
expect "자식 만들기" "201" "${RES%%|*}"
contains "새 자식은 **부모와 같은 범위**로 시작한다" '"visibility":"private"' "$(cat "$WORK/b")"

T33_MARK=$(mark)
CODE=$(set_vis qa_employee01 "$CHILD" internal)
expect "부모 private 아래 자식을 internal 로" "400" "$CODE"
T33_BODY=$(cat "$WORK/b")
contains "사유 wider_than_parent" '"reason":"wider_than_parent"' "$T33_BODY"
note "응답: $T33_BODY"
if [ "$HAVE_PSQL" = "1" ]; then
  expect "DB 불변 — 자식은 그대로 private" "private" "$(vis_of "$CHILD")"
  expect "감사 page.visibility deny wider_than_parent 1건" "1" \
    "$(psqlq "SELECT count(*) FROM audit_logs WHERE id > ${T33_MARK} AND action='page.visibility' AND result='deny' AND reason='wider_than_parent'")"
  note "문장: $(psqlq "SELECT summary || ' | ' || detail::text FROM audit_logs WHERE id > ${T33_MARK} AND action='page.visibility' AND reason='wider_than_parent' ORDER BY id DESC LIMIT 1")"
else
  skip "DB 불변·감사" "psql 없음 — **미검증**"
fi

# ════════════════════════════════════════════════════════════════
hr "C:T-34  좁히기 — 하위가 더 넓으면 cascade 없이는 막는다"

CODE=$(set_vis qa_employee01 "$ROOT_PAGE" internal)
expect "부모를 internal 로" "200" "$CODE"
CODE=$(set_vis qa_employee01 "$CHILD" internal)
expect "자식도 internal 로 (이제 부모가 넓다)" "200" "$CODE"
RES=$(make_page qa_employee01 "4B-범위시험-손자" "$CHILD")
GRAND=${RES#*|}
expect "손자 만들기 (부모와 같은 internal 로 시작)" "201" "${RES%%|*}"
contains "손자도 internal" '"visibility":"internal"' "$(cat "$WORK/b")"

T34_MARK=$(mark)
CODE=$(set_vis qa_employee01 "$ROOT_PAGE" private)
expect "cascade 없이 부모를 private 로" "400" "$CODE"
T34_BODY=$(cat "$WORK/b")
contains "사유 children_wider" '"reason":"children_wider"' "$T34_BODY"
note "응답: $T34_BODY"
if [ "$HAVE_PSQL" = "1" ]; then
  expect "DB 불변 — 부모는 그대로 internal" "internal" "$(vis_of "$ROOT_PAGE")"
  expect "감사 page.visibility deny children_wider 1건" "1" \
    "$(psqlq "SELECT count(*) FROM audit_logs WHERE id > ${T34_MARK} AND action='page.visibility' AND result='deny' AND reason='children_wider'")"
  note "문장: $(psqlq "SELECT summary FROM audit_logs WHERE id > ${T34_MARK} AND action='page.visibility' AND reason='children_wider' ORDER BY id DESC LIMIT 1")"
fi

T34B_MARK=$(mark)
CODE=$(set_vis qa_employee01 "$ROOT_PAGE" private true)
expect "cascade:true 로 부모를 private 로" "200" "$CODE"
note "응답: $(cat "$WORK/b")"
contains "함께 바뀐 하위 수가 2" '"cascaded":2' "$(cat "$WORK/b")"
if [ "$HAVE_PSQL" = "1" ]; then
  expect "부모 private"  "private" "$(vis_of "$ROOT_PAGE")"
  expect "자식 private"  "private" "$(vis_of "$CHILD")"
  expect "손자 private"  "private" "$(vis_of "$GRAND")"
  expect "감사 page.visibility allow 3건 (**항목마다**)" "3" \
    "$(psqlq "SELECT count(*) FROM audit_logs WHERE id > ${T34B_MARK} AND action='page.visibility' AND result='allow'")"
  echo "    — 항목마다 남은 행:"
  psqlq "SELECT '      ' || target_name || ' : ' || (detail->>'visibility_from') || ' → ' || (detail->>'visibility_to') || ' (cascaded=' || (detail->>'cascaded') || ')'
           FROM audit_logs WHERE id > ${T34B_MARK} AND action='page.visibility' AND result='allow' ORDER BY id"
fi

# ════════════════════════════════════════════════════════════════
hr "C:T-35  목록 격리 — 남의 개인 공간은 통째로 닫혀 있다"

# 다시 internal 로 열어 둔다(격리 시험의 전제: 「볼 수 있는 페이지가 남의 공간에 있다」).
set_vis qa_employee01 "$ROOT_PAGE" internal >/dev/null

CODE=$(capi GET "/api/pages?space=$MY_SPACE" qa_employee02)
expect "qa_employee02 의 GET /api/pages?space=<employee01 개인공간>" "403" "$CODE"
note "응답 원문: $(cat "$WORK/b")"

CODE=$(capi GET "/api/pages" qa_employee02)
expect "qa_employee02 의 자기 개인 공간 목록" "200" "$CODE"
E02_LIST=$(cat "$WORK/b")
lacks "employee01 의 internal 페이지 제목이 그 목록에 없다 (공간이 다르다)" '4B-범위시험-부모' "$E02_LIST"
note "응답 원문: $E02_LIST"

CODE=$(capi GET "/api/pages/$ROOT_PAGE" qa_employee02)
expect "그러나 직접 읽기는 200 — 제목이 보인다" "200" "$CODE"
contains "제목이 응답 원문에 있다" '4B-범위시험-부모' "$(cat "$WORK/b")"

CODE=$(capi GET "/api/pages?space=$MY_SPACE" qa_admin01)
expect "Admin 도 남의 개인 공간 목록은 403 (페이지 한 건은 admin_all 로 읽는다)" "403" "$CODE"

# ════════════════════════════════════════════════════════════════
hr "C:T-37  판정 위치 — 목록은 SQL WHERE 로 걸러진다"

if [ "$HAVE_PSQL" = "1" ]; then
  # 핸들러가 쓰는 조건절을 **그대로** 옮긴 질의다 (src/community/routes.php
  # community_visible_pages_where + handlers/pages_list.php 의 WHERE).
  count_visible() {   # $1 = 보는 사람 username, $2 = 공간 번호
    psqlq "
      SELECT count(*) FROM community.pages p
        JOIN community.spaces s ON s.id = p.space_id
        LEFT JOIN public.users owner ON owner.id = s.owner_user_id
        CROSS JOIN (SELECT id, role, team_id FROM users WHERE username = '$1') me
       WHERE p.space_id = $2
         AND p.deleted_at IS NULL
         AND (
               (s.kind = 'personal' AND s.owner_user_id = me.id)
            OR (s.kind = 'personal' AND p.visibility = 'internal' AND me.role <> 'Guest')
            OR (s.kind = 'personal' AND p.visibility = 'team'
                AND owner.team_id IS NOT NULL AND owner.team_id = me.team_id)
            OR (s.kind = 'personal' AND p.visibility IN ('private','team','internal') AND me.role = 'Admin')
            OR (s.kind = 'team' AND s.team_id IS NOT NULL AND s.team_id = me.team_id)
            OR (s.kind = 'team' AND me.role = 'Admin')
         )"
  }

  for pair in "qa_employee01:$MY_SPACE" "qa_employee01:$TEAM_SPACE" "qa_manager01:$TEAM_SPACE"; do
    who=${pair%%:*}; sp=${pair##*:}
    CODE=$(capi GET "/api/pages?space=$sp&tree=1" "$who")
    api_n=$(jget "d['total']" < "$WORK/b")
    sql_n=$(count_visible "$who" "$sp")
    expect "$who / 공간 $sp — psql 로 센 수와 API 가 준 수" "$sql_n" "$api_n"
  done

  echo "  — 쓰인 조건절 (핸들러와 같은 문장):"
  sed -n '/^($/,/^)$/p' "$ROOT/src/community/routes.php" | sed 's/^/      /'

  # 응답 원문에 볼 수 없는 제목이 0건인가 — employee02 의 모든 목록을 훑는다.
  capi GET "/api/pages?tree=1" qa_employee02 >/dev/null
  lacks "employee02 의 트리 원문에 employee01 의 제목 0건" '4B-범위시험' "$(cat "$WORK/b")"
else
  skip "psql 대조" "psql 없음 — **미검증**"
fi

# ════════════════════════════════════════════════════════════════
hr "옮기기도 트리 불변 규칙을 지킨다 (4-B, pages_move)"

RES=$(make_page qa_employee01 "4B-이동-좁은부모")
NARROW=${RES#*|}
MADE_PAGES="$MADE_PAGES $NARROW"
expect "private 뿌리 하나 더 만들기" "201" "${RES%%|*}"

MOVE_MARK=$(mark)
printf '{"parent_id":"%s"}' "$NARROW" > "$WORK/mv.json"
CODE=$(capi PATCH "/api/pages/$ROOT_PAGE/move" qa_employee01 "$WORK/mv.json")
expect "internal 가지를 private 부모 아래로 옮기기" "400" "$CODE"
contains "사유 wider_than_parent" '"reason":"wider_than_parent"' "$(cat "$WORK/b")"
if [ "$HAVE_PSQL" = "1" ]; then
  expect "감사 page.move deny wider_than_parent 1건" "1" \
    "$(psqlq "SELECT count(*) FROM audit_logs WHERE id > ${MOVE_MARK} AND action='page.move' AND result='deny' AND reason='wider_than_parent'")"
fi

# ════════════════════════════════════════════════════════════════
hr "브라우저 — 4-B 항목 (진짜 브라우저로 눌러 본다)"

SMOKE_OUT="$WORK/smoke.txt"
SESSION_VALUE=$(awk '$6=="tbox_session"{print $7}' "$JAR_DIR/qa_employee01.jar" 2>/dev/null)

if ! python3 -c "import playwright" >/dev/null 2>&1; then
  skip "브라우저 스모크" "Playwright 가 없다 — **미검증**"
elif [ -z "$SESSION_VALUE" ]; then
  skip "브라우저 스모크" "qa_employee01 세션 쿠키를 얻지 못했다 — **미검증**"
else
  # browser_smoke.py 는 3-B(1~19)·4-A(21~26) 에 이어 4-B 항목(27~29)을 같은 창에서 돈다.
  # 창을 두 번 띄우지 않으려고 나누지 않았다 — 아래 출력은 4-B 구간부터 보인다.
  PATH="$HOME/.local/bin:$PATH" \
    python3 "$ROOT/tests/community/browser_smoke.py" "$SITE" "$UA" "$SESSION_VALUE" \
    > "$SMOKE_OUT" 2>&1
  if grep -q '^SMOKE_FAIL=' "$SMOKE_OUT"; then
    sed -n '/^── 27\./,$p' "$SMOKE_OUT" | head -80
    expect "브라우저에서 pageerror 0건" "PAGEERROR=0" "$(grep '^PAGEERROR=' "$SMOKE_OUT")"
    expect "브라우저에서 콘솔 오류 0건" "CONSOLEERR=0" "$(grep '^CONSOLEERR=' "$SMOKE_OUT")"
    expect "브라우저에서 실패한 요청 0건" "REQFAIL=0" "$(grep '^REQFAIL=' "$SMOKE_OUT")"
    expect "브라우저에서 HTTP 400 이상 0건" "HTTP4XX=0" "$(grep '^HTTP4XX=' "$SMOKE_OUT")"
    expect "스모크 항목 실패 0건" "SMOKE_FAIL=0" "$(grep '^SMOKE_FAIL=' "$SMOKE_OUT")"
    note "브라우저 내부 CSP 인라인 style 차단: $(grep '^CSPSTYLE=' "$SMOKE_OUT")"
    note "일부러 거부당한 요청(28번 children_wider): $(grep '^EXPECTEDBAD=' "$SMOKE_OUT")"
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
for f in $(find "$ROOT/src/community" -name '*.php') "$ROOT/src/Audit.php" "$ROOT/src/views/shell_top.php"; do
  php -l "$f" >/dev/null 2>&1 || { n_bad=$((n_bad+1)); echo "  php -l 실패: $f"; }
done
expect "바뀐 PHP 문법 오류 0건" "0" "$n_bad"
bash -n "$0" && expect "이 시험 파일 bash -n" "0" "0" || expect "이 시험 파일 bash -n" "0" "1"
python3 -c 'import sys; compile(open(sys.argv[1], encoding="utf-8").read(), sys.argv[1], "exec")' \
  "$ROOT/tests/community/browser_smoke.py" 2>/dev/null \
  && expect "browser_smoke.py 구문" "0" "0" || expect "browser_smoke.py 구문" "0" "1"
if command -v esprima >/dev/null 2>&1 || python3 -c "import esprima" >/dev/null 2>&1; then
  PATH="$HOME/.local/bin:$PATH" python3 -c '
import esprima, sys
esprima.parseModule(open(sys.argv[1], encoding="utf-8").read())
' "$ROOT/public/assets/community/editor.js" 2>/dev/null \
    && expect "editor.js 구문 (esprima, 모듈)" "0" "0" || expect "editor.js 구문 (esprima, 모듈)" "0" "1"
else
  skip "editor.js 구문" "esprima 가 없다 — **미검증**"
fi

# ════════════════════════════════════════════════════════════════
hr "정리 — 만든 페이지를 앱 창구로 되돌린다 (논리 삭제)"

for pid in $MADE_PAGES; do
  [ -n "$pid" ] || continue
  owner=qa_employee01
  CODE=$(capi DELETE "/api/pages/$pid" "$owner")
  printf '  · %s → %s\n' "$pid" "$CODE"
done

if [ "$HAVE_PSQL" = "1" ]; then
  note "시험이 만든 감사 행(전체): $(psqlq "SELECT count(*) FROM audit_logs WHERE id > ${AUDIT_START}")건"
  note "남은 팀 공간: $(psqlq "SELECT count(*) FROM community.spaces WHERE kind='team'")개 (창구가 만든 것 — 지우는 창구가 없다)"
fi

hr "결과"
printf '통과 %d · 실패 %d · 건너뜀 %d\n' "$PASS" "$FAIL" "$SKIP"
[ "$FAIL" -eq 0 ] || exit 1
