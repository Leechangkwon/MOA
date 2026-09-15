#!/usr/bin/env bash
# tests/api_read.sh — F-50 「프로그램용 검색·다운로드」 수락 기준 실행 (PRD 12.2 T-62)
#
#   (a) 토큰으로 GET /api/files?q=F50&limit=8 → 200 · 최상위가 **배열** ·
#       원소 키가 정확히 {id,name,level,size,uploaded_at} · 올린 F50-emp.txt 가 보인다.
#   (b) 그 응답에 level=admin-only 0건 · F50-adm.txt 없음. 같은 검색을 qa_admin01 **세션**으로
#       하면 있다(대조) — 즉 "안 보이는 것" 은 파일이 없어서가 아니라 권한 때문이다.
#   (c) 검색어 주입 시도 — `%' OR 1=1 --` · `_` · `#` 는 **글자 그대로** 찾는 값이다(와일드카드 아님).
#       limit=abc → 기본 20 · limit=9999 → 범위 밖이라 기본 20 (둘 다 ≤100).
#   (d) 토큰으로 F50-emp.txt 다운로드 → 200 · Content-Disposition 에 원래 이름 · 본문 sha256 일치.
#   (e) 토큰으로 F50-adm.txt → **403** {"error":"권한이 없습니다"} + 감사 file.download deny 의
#       detail.auth=api_token · 없는 UUID → 404 · UUID 형태가 아니면 → 404(라우트 불일치).
#   (f) 토큰은 **읽기 두 창구 밖에서 아무것도 열지 못한다** — 같은 헤더로
#       DELETE /api/files/{id} · PATCH /api/files/{id} · GET /api/users → 401 「로그인이 필요합니다」,
#       그 사이 auth.token 감사 행 0.
#   (g) 웹(세션) 회귀 — qa_employee01 세션 GET /api/files?q=F50 은 여전히 **객체**이고
#       키가 정확히 files,total,page,per_page,scope,folder.
#   (h) 회귀 — scripts/security_lint.sh 0 · 바뀐 PHP php -l · bash -n.
#
# ★ 토큰 항목의 조건 (a)(b)(d)(e)
#   진짜 토큰은 `.env` 의 `API_TOKEN_USER` 계정으로 동작한다. 그 귀속이 **검토 계정(qa_)이
#   아니면** 이 항목들을 돌리지 않는다(SKIP) — 시드·운영 계정으로 요청을 보내면 감사 로그에서
#   사용자의 자기 공격과 검토 요청이 갈라지지 않는다(CLAUDE.md 3장).
#   토큰 값은 변수로만 다루고 **어디에도 출력하지 않는다**(SECURITY R-62 · PRD S-09-11).
#
# 규칙 (CLAUDE.md 3장) — tests/api_upload.sh 와 같다
#   · 검토 계정(qa_ 접두)만. 시드 계정(admin01 …)은 사용자의 자기 공격 전용이다.
#   · 모든 요청에 `-A "tbox-review/<날짜>"`.
#   · 운영 DB·.env·nginx·php-fpm 에 손대지 않는다. .env 에서 읽는 것은 API_TOKEN_USER **계정 이름**과
#     API_TOKEN **값(변수로만)** 뿐이고, 고치지 않는다.
#
# ★ 시험 안전 (SECURITY R-73 · R-80)
#   ① 최근 10분 감사 로그에 비-qa actor 가 있으면 시작하지 않는다(종료 코드 3)
#   ② 만드는 파일은 전부 `F50-` 접두이고, 올린 것은 같은 세션으로 되돌린다(휴지통)
#   ③ 루트 파티션 여유가 1GB 미만이면 시작하지 않는다
#
# 준비물: curl · php(TOTP) · python3(JSON) · sudo -n(seed-accounts.txt · psql). 없으면 해당 항목 SKIP.
# 사용: sudo -v && bash tests/api_read.sh
set -u

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BASE="http://127.0.0.1:8082"
UA="tbox-review/$(date +%F)"
DB="tbox_re"
JAR_DIR="$(mktemp -d)"
WORK="$(mktemp -d)"
PASS=0
FAIL=0
SKIP=0

cleanup() { rm -rf "$JAR_DIR" "$WORK"; }
trap cleanup EXIT

hr()  { printf '\n════════════════════════════════════════════════════════════\n%s\n════════════════════════════════════════════════════════════\n' "$1"; }
expect() {
  if [ "$2" = "$3" ]; then printf '  ✔ %s  (기대=%s 실제=%s)\n' "$1" "$2" "$3"; PASS=$((PASS+1))
  else printf '  ✖ %s  (기대=%s 실제=%s)\n' "$1" "$2" "$3"; FAIL=$((FAIL+1)); fi
}
note() { printf '  · %s\n' "$1"; }
skip() { printf '  ○ SKIP %s  (%s)\n' "$1" "$2"; SKIP=$((SKIP+1)); }

psqlq() { sudo -n -u postgres psql -d "$DB" -t -A -c "$1" 2>/dev/null; }
mark()  { psqlq "SELECT coalesce(max(id),0) FROM audit_logs" 2>/dev/null; }

code_of() { printf '%s' "$1" | tail -1; }
body_of() { printf '%s' "$1" | sed '$d'; }
uuid_of() { printf '%s' "$1" | sed -n 's/.*"id":"\([0-9a-f-]\{36\}\)".*/\1/p' | head -1; }

# JSON 판독기. 인자로 준 파이썬 식을 표준입력의 JSON(`d`)에 대해 평가해 한 줄로 찍는다.
# 토큰 값은 이 경로에 오지 않는다(응답 본문만 넣는다).
jq_py() { python3 -c 'import sys,json
d=json.load(sys.stdin)
print(eval(sys.argv[1]))' "$1" 2>/dev/null; }

# ─── 시작 전 관문 ────────────────────────────────────────────────
hr "F-50 프로그램용 검색·다운로드 — 시험 시작 전 확인"

avail_kb=$(df -Pk / | awk 'NR==2{print $4}')
echo "루트 파티션 여유: $(df -Ph / | awk 'NR==2{print $4}') (${avail_kb}KB)"
[ "$avail_kb" -ge 1048576 ] || { echo "루트 디스크 여유 1GB 미만 — 시험을 시작하지 않는다 (SECURITY R-80)"; exit 3; }

HAVE_PSQL=0
if psqlq "SELECT 1" >/dev/null 2>&1; then HAVE_PSQL=1; fi
if [ "$HAVE_PSQL" -eq 1 ]; then
  BUSY=$(psqlq "SELECT count(*) FROM audit_logs WHERE happened_at > now() - interval '10 minutes' AND actor_name IS NOT NULL AND actor_name NOT LIKE 'qa\_%'")
  echo "최근 10분 비-qa 감사 행: ${BUSY:-?}"
  if [ "${BUSY:-0}" -gt 0 ] 2>/dev/null; then
    echo "사용자가 지금 서비스를 쓰고 있다 — 시험을 시작하지 않는다 (SECURITY R-73 ①)"; exit 3
  fi
else
  echo "psql 을 쓸 수 없다 — 감사 로그 확인 항목은 SKIP 된다"
fi

# ─── 토큰 귀속 확인 — 계정 **이름만** 읽는다 ─────────────────────
TOKEN_USER=$(grep -oE '^API_TOKEN_USER=.*' "$ROOT/.env" 2>/dev/null | cut -d= -f2- | tr -d '"'"'"' \r')
TOKEN_SET=$(grep -c '^API_TOKEN=..*$' "$ROOT/.env" 2>/dev/null); TOKEN_SET=${TOKEN_SET:-0}
echo ".env 의 API_TOKEN 값 있음: $TOKEN_SET · 귀속 계정: ${TOKEN_USER:-없음}"

USE_TOKEN=0
case "${TOKEN_USER:-}" in
  qa_*) if [ "$TOKEN_SET" -eq 1 ]; then USE_TOKEN=1; fi ;;
esac
if [ "$USE_TOKEN" -eq 1 ]; then
  # 값은 변수에만 담고 절대 출력하지 않는다.
  API_TOKEN=$(grep -oE '^API_TOKEN=.*' "$ROOT/.env" | cut -d= -f2- | tr -d '"'"'"' \r')
  echo "→ 귀속이 검토 계정이다. (a)(b)(d)(e) 를 실행한다. (토큰 값은 출력하지 않는다)"
else
  API_TOKEN=''
  echo "→ 귀속이 검토 계정(qa_)이 아니다 — (a)(b)(d)(e) 는 **SKIP**. Fable 이 검토에서 귀속을 바꿔 돌린다."
fi
# 어떤 서버에서도 맞을 수 없는 고정 문자열. 진짜 토큰이 없을 때 (f) 에서 쓴다.
WRONG_TOKEN='wrong-token-0000000000000000000000000000000000'

# ─── 로그인 헬퍼 (tests/api_upload.sh 와 같다) ───────────────────
login() {
  local who="$1" jar="$JAR_DIR/$1.jar" pw sec raw code left out try
  pw=$(sudo -n awk -v u="$who" '$1==u{print $3}' /etc/tbox-v2/seed-accounts.txt 2>/dev/null)
  sec=$(sudo -n awk -v u="$who" '$1==u{print $2}' /etc/tbox-v2/qa-totp.txt 2>/dev/null)
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
    printf '  · %s 코드 재사용으로 거부됨 — 다음 창까지 %s초 기다림 (R-45 가 의도대로 막은 것)\n' "$who" "$left" >&2
    sleep $((left + 1))
  done
  return 1
}

# ─── 준비: 시험용 파일 둘 (끝에서 휴지통으로) ────────────────────
hr "준비 — F50-emp.txt(internal, qa_employee01) · F50-adm.txt(admin-only, qa_admin01)"

printf 'F-50 시험용 파일입니다. employee.\n' > "$WORK/F50-emp.txt"
printf 'F-50 시험용 파일입니다. admin only.\n' > "$WORK/F50-adm.txt"
EMP_SHA=$(sha256sum "$WORK/F50-emp.txt" | awk '{print $1}')

EMP_CSRF=$(login qa_employee01)
ADM_CSRF=$(login qa_admin01)
EMP_ID=''
ADM_ID=''

if [ -n "${EMP_CSRF:-}" ]; then
  OUT=$(curl -s -w '\n%{http_code}' -X POST -b "$JAR_DIR/qa_employee01.jar" -A "$UA" \
        -H "X-CSRF-Token: $EMP_CSRF" -F "file=@$WORK/F50-emp.txt" -F 'access_level=internal' \
        "$BASE/api/files")
  echo "  F50-emp.txt 업로드 [$(code_of "$OUT")] $(body_of "$OUT")"
  EMP_ID=$(uuid_of "$(body_of "$OUT")")
else
  note "qa_employee01 로그인 실패 — 준비 업로드를 하지 못했다"
fi

if [ -n "${ADM_CSRF:-}" ]; then
  OUT=$(curl -s -w '\n%{http_code}' -X POST -b "$JAR_DIR/qa_admin01.jar" -A "$UA" \
        -H "X-CSRF-Token: $ADM_CSRF" -F "file=@$WORK/F50-adm.txt" -F 'access_level=admin-only' \
        "$BASE/api/files")
  echo "  F50-adm.txt 업로드 [$(code_of "$OUT")] $(body_of "$OUT")"
  ADM_ID=$(uuid_of "$(body_of "$OUT")")
else
  note "qa_admin01 로그인 실패 — 준비 업로드를 하지 못했다"
fi

# ─── (a) 토큰 검색 — 최상위 배열 · 키 다섯 ───────────────────────
hr "(a) 토큰으로 GET /api/files?q=F50&limit=8"
if [ "$USE_TOKEN" -eq 0 ]; then
  skip "(a)" "토큰 귀속이 검토 계정(qa_)이 아님 — 값은 읽지 않았다"
else
  OUT=$(curl -s -w '\n%{http_code}' -A "$UA" -H "X-API-Token: $API_TOKEN" \
        "$BASE/api/files?q=F50&limit=8")
  CODE=$(code_of "$OUT"); BODY=$(body_of "$OUT")
  printf '%s' "$BODY" > "$WORK/a.json"
  echo "  응답: $BODY"
  expect "상태" "200" "$CODE"
  expect "최상위가 배열" "list" "$(jq_py 'type(d).__name__' < "$WORK/a.json")"
  expect "원소 키" "id,level,name,size,uploaded_at" \
    "$(jq_py '",".join(sorted(d[0].keys())) if d else "(빈 배열)"' < "$WORK/a.json")"
  expect "F50-emp.txt 가 보인다" "1" \
    "$(jq_py 'sum(1 for f in d if f["name"]=="F50-emp.txt")' < "$WORK/a.json")"
  note "원소 수: $(jq_py 'len(d)' < "$WORK/a.json")"
fi

# ─── (b) 권한 — admin-only 는 애초에 오지 않는다 ─────────────────
hr "(b) 토큰(귀속 계정 역할)이 못 보는 파일은 응답에 없다"
if [ "$USE_TOKEN" -eq 0 ]; then
  skip "(b) 토큰 쪽" "토큰 귀속이 검토 계정(qa_)이 아님"
else
  expect "응답에 level=admin-only 0건" "0" \
    "$(jq_py 'sum(1 for f in d if f["level"]=="admin-only")' < "$WORK/a.json")"
  expect "F50-adm.txt 없음" "0" \
    "$(jq_py 'sum(1 for f in d if f["name"]=="F50-adm.txt")' < "$WORK/a.json")"
fi
if [ -n "${ADM_CSRF:-}" ]; then
  OUT=$(curl -s -b "$JAR_DIR/qa_admin01.jar" -A "$UA" "$BASE/api/files?q=F50")
  printf '%s' "$OUT" > "$WORK/b_adm.json"
  expect "대조 — qa_admin01 세션에는 F50-adm.txt 가 있다" "1" \
    "$(jq_py 'sum(1 for f in d["files"] if f["name"]=="F50-adm.txt")' < "$WORK/b_adm.json")"
else
  skip "(b) 대조" "qa_admin01 세션 없음"
fi

# ─── (c) 검색어 주입 · limit 좁히기 ──────────────────────────────
hr "(c) 검색어는 값이다 — 와일드카드도 SQL 도 아니다"
# 토큰이 있으면 토큰으로, 없으면 **같은 SQL 을 쓰는 세션 경로**로 확인한다(조건절·바인딩은 하나다).
if [ "$USE_TOKEN" -eq 1 ]; then
  CPATH="토큰"
  cget() { curl -s -w '\n%{http_code}' -A "$UA" -H "X-API-Token: $API_TOKEN" "$BASE/api/files?$1"; }
  clen() { jq_py 'len(d)'; }
  cper() { jq_py 'len(d)'; }
elif [ -n "${EMP_CSRF:-}" ]; then
  CPATH="qa_employee01 세션"
  cget() { curl -s -w '\n%{http_code}' -b "$JAR_DIR/qa_employee01.jar" -A "$UA" "$BASE/api/files?$1"; }
  clen() { jq_py 'len(d["files"])'; }
  cper() { jq_py 'd["per_page"]'; }
else
  CPATH=""
fi

if [ -z "$CPATH" ]; then
  skip "(c)" "토큰도 세션도 없음"
else
  note "확인 경로: $CPATH (권한 조건·검색 SQL·바인딩은 두 경로가 **같은 코드**다)"

  OUT=$(cget "q=%25%27%20OR%201%3D1%20--"); CODE=$(code_of "$OUT"); BODY=$(body_of "$OUT")
  printf '%s' "$BODY" > "$WORK/c1.json"
  echo "  q=%' OR 1=1 --  → [$CODE] ${BODY:0:120}"
  expect "q=%' OR 1=1 -- 상태" "200" "$CODE"
  expect "그 문자열을 이름·설명에 가진 파일만 (0건)" "0" "$(clen < "$WORK/c1.json")"

  OUT=$(cget "q=_"); CODE=$(code_of "$OUT"); printf '%s' "$(body_of "$OUT")" > "$WORK/c2.json"
  expect "q=_ 상태" "200" "$CODE"
  note "q=_ 결과 수: $(clen < "$WORK/c2.json")  (와일드카드라면 전체 건수가 나왔을 자리다)"

  OUT=$(cget "q=%23"); CODE=$(code_of "$OUT"); printf '%s' "$(body_of "$OUT")" > "$WORK/c3.json"
  expect "q=# 상태 (ESCAPE 문자 자신)" "200" "$CODE"
  note "q=# 결과 수: $(clen < "$WORK/c3.json")"

  OUT=$(cget "q=F50&limit=abc"); printf '%s' "$(body_of "$OUT")" > "$WORK/c4.json"
  N=$(clen < "$WORK/c4.json")
  if [ "${N:-999}" -le 20 ] 2>/dev/null; then expect "limit=abc → 기본 20 이하" "ok" "ok"
  else expect "limit=abc → 기본 20 이하" "ok" "$N 건"; fi
  note "limit=abc 결과 수: $N"

  OUT=$(cget "q=F50&limit=9999"); printf '%s' "$(body_of "$OUT")" > "$WORK/c5.json"
  N=$(clen < "$WORK/c5.json")
  if [ "${N:-999}" -le 100 ] 2>/dev/null; then expect "limit=9999 → 100 이하" "ok" "ok"
  else expect "limit=9999 → 100 이하" "ok" "$N 건"; fi
  note "limit=9999 결과 수: $N (범위 밖 값은 queryInt 가 기본 20 으로 좁힌다)"
fi

# ─── (d) 토큰 다운로드 — 원래 이름 · 내용 일치 ───────────────────
hr "(d) 토큰으로 내려받기 — F50-emp.txt"
if [ "$USE_TOKEN" -eq 0 ]; then
  skip "(d)" "토큰 귀속이 검토 계정(qa_)이 아님"
elif [ -z "$EMP_ID" ]; then
  skip "(d)" "준비 업로드(F50-emp.txt)가 없어 id 를 모른다"
else
  CODE=$(curl -s -A "$UA" -H "X-API-Token: $API_TOKEN" -D "$WORK/d.h" -o "$WORK/d.bin" \
         -w '%{http_code}' "$BASE/api/files/$EMP_ID/download")
  expect "상태" "200" "$CODE"
  CD=$(sed -n 's/^[Cc]ontent-[Dd]isposition:[[:space:]]*//p' "$WORK/d.h" | tr -d '\r' | head -1)
  echo "  Content-Disposition: $CD"
  case "$CD" in *F50-emp.txt*) expect "원래 파일 이름이 담겼다" "yes" "yes" ;;
                *)              expect "원래 파일 이름이 담겼다" "yes" "no" ;; esac
  expect "본문 sha256 == 올린 파일" "$EMP_SHA" "$(sha256sum "$WORK/d.bin" | awk '{print $1}')"
fi

# ─── (e) 토큰이라도 권한 없는 파일은 403, 없는 파일은 404 ────────
hr "(e) IDOR — 남의 등급(admin-only) 파일 · 없는 id"
if [ "$USE_TOKEN" -eq 0 ]; then
  skip "(e)" "토큰 귀속이 검토 계정(qa_)이 아님"
else
  if [ -z "$ADM_ID" ]; then
    skip "(e) 403" "준비 업로드(F50-adm.txt)가 없어 id 를 모른다"
  else
    T0=$(mark)
    OUT=$(curl -s -w '\n%{http_code}' -A "$UA" -H "X-API-Token: $API_TOKEN" \
          "$BASE/api/files/$ADM_ID/download")
    echo "  admin-only 파일 → [$(code_of "$OUT")] $(body_of "$OUT")"
    expect "권한 없는 파일 상태" "403" "$(code_of "$OUT")"
    expect "문구(대상 정보 없음)" '{"error":"권한이 없습니다"}' "$(body_of "$OUT")"
    if [ "$HAVE_PSQL" -eq 1 ]; then
      GOT=$(psqlq "SELECT result||' '||coalesce(detail->>'auth','없음') FROM audit_logs WHERE action='file.download' AND id > ${T0:-0} ORDER BY id DESC LIMIT 1")
      expect "감사 file.download 행" "deny api_token" "${GOT:-없음}"
    fi
  fi

  OUT=$(curl -s -w '\n%{http_code}' -A "$UA" -H "X-API-Token: $API_TOKEN" \
        "$BASE/api/files/00000000-0000-4000-8000-000000000000/download")
  echo "  없는 UUID → [$(code_of "$OUT")] $(body_of "$OUT")"
  expect "없는 id 상태" "404" "$(code_of "$OUT")"

  OUT=$(curl -s -w '\n%{http_code}' -A "$UA" -H "X-API-Token: $API_TOKEN" \
        "$BASE/api/files/not-a-uuid/download")
  echo "  UUID 아님 → [$(code_of "$OUT")] $(body_of "$OUT")"
  expect "형태가 아닌 id 상태" "404" "$(code_of "$OUT")"
fi

# ─── (f) 토큰은 읽기 두 창구 밖에서 아무것도 열지 못한다 ─────────
hr "(f) 토큰이 통하지 않는 라우트 — 삭제 · 수정 · 계정"
if [ "$USE_TOKEN" -eq 1 ]; then TOK="$API_TOKEN"; note "진짜 토큰으로 확인한다(값은 출력하지 않는다)"
else TOK="$WRONG_TOKEN"; note "귀속이 검토 계정이 아니라 **틀린 토큰**으로 확인한다 — 이 항목이 보는 것은 「헤더를 아예 읽지 않는가」다"; fi

T0=$(mark)
FAKE_ID='00000000-0000-4000-8000-000000000000'

OUT=$(curl -s -w '\n%{http_code}' -X DELETE -A "$UA" -H "X-API-Token: $TOK" "$BASE/api/files/$FAKE_ID")
echo "  DELETE /api/files/{id} → [$(code_of "$OUT")] $(body_of "$OUT")"
expect "DELETE 상태" "401" "$(code_of "$OUT")"
expect "DELETE 문구(세션 경로)" '{"error":"로그인이 필요합니다"}' "$(body_of "$OUT")"

OUT=$(curl -s -w '\n%{http_code}' -X PATCH -A "$UA" -H "X-API-Token: $TOK" \
      -H 'Content-Type: application/json' -d '{"description":"x"}' "$BASE/api/files/$FAKE_ID")
echo "  PATCH /api/files/{id} → [$(code_of "$OUT")] $(body_of "$OUT")"
expect "PATCH 상태" "401" "$(code_of "$OUT")"
expect "PATCH 문구(세션 경로)" '{"error":"로그인이 필요합니다"}' "$(body_of "$OUT")"

OUT=$(curl -s -w '\n%{http_code}' -A "$UA" -H "X-API-Token: $TOK" "$BASE/api/users")
echo "  GET /api/users → [$(code_of "$OUT")] $(body_of "$OUT")"
expect "GET /api/users 상태" "401" "$(code_of "$OUT")"
expect "GET /api/users 문구(세션 경로)" '{"error":"로그인이 필요합니다"}' "$(body_of "$OUT")"

if [ "$HAVE_PSQL" -eq 1 ]; then
  N=$(psqlq "SELECT count(*) FROM audit_logs WHERE action='auth.token' AND id > ${T0:-0}")
  expect "그 사이 auth.token 감사 행" "0" "${N:-?}"
fi

# ─── (g) 웹(세션) 응답 모양 불변 ─────────────────────────────────
hr "(g) 회귀 — 세션 GET /api/files 는 여전히 객체다"
if [ -z "${EMP_CSRF:-}" ]; then
  skip "(g)" "qa_employee01 세션 없음"
else
  OUT=$(curl -s -w '\n%{http_code}' -b "$JAR_DIR/qa_employee01.jar" -A "$UA" "$BASE/api/files?q=F50")
  CODE=$(code_of "$OUT"); printf '%s' "$(body_of "$OUT")" > "$WORK/g.json"
  expect "상태" "200" "$CODE"
  expect "최상위가 객체" "dict" "$(jq_py 'type(d).__name__' < "$WORK/g.json")"
  expect "최상위 키" "files,folder,page,per_page,scope,total" \
    "$(jq_py '",".join(sorted(d.keys()))' < "$WORK/g.json")"
  expect "per_page 는 여전히 20" "20" "$(jq_py 'd["per_page"]' < "$WORK/g.json")"
  expect "행에 화면 편의 값이 그대로 있다(can_delete)" "True" \
    "$(jq_py '"can_delete" in d["files"][0] if d["files"] else "(빈 목록)"' < "$WORK/g.json")"
fi

# ─── 정리 — 올린 시험 파일을 휴지통으로 ──────────────────────────
hr "정리 — 시험 파일 되돌리기"
if [ -n "$EMP_ID" ] && [ -n "${EMP_CSRF:-}" ]; then
  C=$(curl -s -o /dev/null -w '%{http_code}' -X DELETE -b "$JAR_DIR/qa_employee01.jar" -A "$UA" \
      -H "X-CSRF-Token: $EMP_CSRF" "$BASE/api/files/$EMP_ID")
  expect "F50-emp.txt 휴지통" "200" "$C"
fi
if [ -n "$ADM_ID" ] && [ -n "${ADM_CSRF:-}" ]; then
  C=$(curl -s -o /dev/null -w '%{http_code}' -X DELETE -b "$JAR_DIR/qa_admin01.jar" -A "$UA" \
      -H "X-CSRF-Token: $ADM_CSRF" "$BASE/api/files/$ADM_ID")
  expect "F50-adm.txt 휴지통" "200" "$C"
fi

# ─── (h) 회귀 — 린트 · php -l · bash -n ──────────────────────────
hr "(h) 회귀 — 린트 · php -l · bash -n"
bash "$ROOT/scripts/security_lint.sh" >"$WORK/lint.out" 2>&1
expect "scripts/security_lint.sh" "0" "$?"
tail -1 "$WORK/lint.out" | sed 's/^/  /'

PHPBAD=0
for f in public/index.php src/handlers/files_list.php; do
  php -l "$ROOT/$f" >/dev/null 2>&1 || { echo "  ✖ php -l 실패: $f"; PHPBAD=1; }
done
expect "바뀐 PHP 2개 php -l" "0" "$PHPBAD"

SHBAD=0
bash -n "$ROOT/tests/api_read.sh"   2>/dev/null || SHBAD=1
bash -n "$ROOT/tests/api_upload.sh" 2>/dev/null || SHBAD=1
expect "bash -n (시험 스크립트 둘)" "0" "$SHBAD"

# ─── 마무리 ─────────────────────────────────────────────────────
hr "결과"
printf '통과 %d · 실패 %d · 건너뜀/미검증 %d\n' "$PASS" "$FAIL" "$SKIP"
if [ "$USE_TOKEN" -eq 0 ]; then
  echo "(a)(b)(d)(e) 는 토큰 귀속이 검토 계정(qa_)이 아니라 실행하지 않았다 — **미검증**이다."
fi
[ "$FAIL" -eq 0 ] || exit 1
exit 0
