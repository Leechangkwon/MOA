#!/usr/bin/env bash
# tests/api_folders.sh — F-58 「프로그램용 폴더 관리」 수락 기준 실행 (PRD 12.2 T-70)
#
#   (a) 목록  토큰 GET /api/folders → 200 · 최상위 키 folder,folders · 같은 계정 **세션**
#       응답과 JSON 문자열 동일(투영 없음) · qa_admin01 이 만든 admin-only 폴더 S 는
#       Employee 귀속 토큰 트리에 **없음** · ?parent=S → 403 「권한이 없습니다」.
#   (b) 생성  토큰 POST /api/folders {name,access_level:"internal"} → 201 folder.id ·
#       같은 이름 다시 → 409 · name 없음 → 400 · access_level:"admin-only" → 403.
#   (c) 폴더 이름·이동  PATCH {name} → 200 · 하위로 옮김 → 200 depth 2 ·
#       부모를 자식 아래로 → 400(cycle) · {access_level} → **400**(토큰 허용 키 밖) ·
#       {name,cascade:false} → 400 · S 의 이름 변경 → 403.
#   (d) 파일 이동  토큰 업로드(POST /api/upload) 한 파일을 PATCH /api/files/{id}
#       {folder_id} 로 옮김 → 200 · 토큰 GET /api/files?folder=<id> 배열에 그 id 있음 ·
#       {folder_id:null} 루트 복귀 → 200 · {description} → **400** · {folder_id:S} → 400.
#   (e) 닫힘  **틀린 토큰**으로 DELETE /api/folders/{num}·DELETE /api/files/{id} → 401
#       「API 토큰이 올바르지 않습니다」 — F-59 로 삭제 둘이 토큰 라우트가 됐다(옛 기대였던
#       「헤더 무시」가 아니다) · 헤더 없이 GET /api/folders → 401 「로그인이 필요합니다」
#       (세션 경로) · 틀린 토큰 GET /api/folders → 401 「API 토큰이 올바르지 않습니다」 ·
#       **헤더 무시**는 여전히 닫힌 라우트로 확인한다: 틀린 토큰으로 GET /api/users ·
#       POST /api/me/api-tokens → 401 「로그인이 필요합니다」(세션 경로).
#       F-66(2026-09-22): 옛 관리자 발급 창구 POST /api/users/{num}/api-tokens 는 **404**
#       (라우트 제거 — 라우터가 라우트를 못 찾으면 인증·인가에 닿지도 않는다).
#   (f) 감사  (b)(c)(d) 의 folder.create·folder.update·file.update 행이 전부
#       detail.auth=api_token · 나온 (action·result·reason) 조합이 전부 src/Audit.php 의
#       SUMMARY_TEMPLATES 에 있다(0 건이 「없는 조합」). 「새 조합」의 기준은 이 DB 의 과거
#       이력이 아니라 **문장표**다 — 표에 없는 조합은 RuntimeException → 500 이라 기록 자체가
#       남지 않는다(PRD 7.7 「요약 문장 필수」, ARCH 7.4).
#   (g) 회귀  세션 PATCH /api/folders/{num} {access_level,team_id} 200(허용 키 여섯 불변) ·
#       세션 PATCH /api/files/{id} {description} 200 · 세션 GET /api/folders 모양 불변 ·
#       scripts/security_lint.sh 0 · php -l(세 파일) · bash -n.
#
# ★ 토큰 항목의 조건 (a)(b)(c)(d)(f) 와 (e) 의 「틀린 토큰」 뒤 감사
#   진짜 토큰은 `.env` 의 `API_TOKEN_USER` 계정으로 동작한다. 그 귀속이 **검토 계정(qa_)이
#   아니면** 이 항목들을 돌리지 않는다(SKIP) — 시드·운영 계정으로 요청을 보내면 감사 로그에서
#   사용자의 자기 공격과 검토 요청이 갈라지지 않는다(CLAUDE.md 3장).
#   토큰 값은 변수로만 다루고 **어디에도 출력하지 않는다**(SECURITY R-62 · PRD S-09-11).
#
# 규칙 (CLAUDE.md 3장) — tests/api_read.sh · tests/api_whoami.sh 와 같다
#   · 검토 계정(qa_ 접두)만. 시드 계정(admin01 …)은 사용자의 자기 공격 전용이다.
#   · 모든 요청에 `-A "tbox-review/<날짜>"`.
#   · 운영 DB·.env·nginx·php-fpm 에 손대지 않는다. .env 에서 읽는 것은 API_TOKEN_USER **계정 이름**과
#     API_TOKEN **값(변수로만)** 뿐이고, 고치지 않는다.
#
# ★ 시험 안전 (SECURITY R-73 · R-80)
#   ① 최근 10분 감사 로그에 비-qa actor 가 있으면 시작하지 않는다(종료 코드 3)
#   ② 만드는 폴더·파일은 전부 `F58-` 접두 + 날짜·PID 이고, **trap 에서도** 스스로 치운다 —
#      파일은 세션 DELETE(휴지통), 폴더는 세션 DELETE 를 하위부터, 휴지통 파일이 남으면
#      `?move_trashed=1` 로 확인한다(ARCH 5.16 「삭제」 세 갈래)
#   ③ 루트 파티션 여유가 1GB 미만이면 시작하지 않는다
#
# 준비물: curl · php(TOTP) · python3(JSON) · sudo -n(seed-accounts.txt · psql). 없으면 해당 항목 SKIP.
# 사용: sudo -v && bash tests/api_folders.sh
set -u

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BASE="http://127.0.0.1:8082"
UA="tbox-review/$(date +%F)"
DB="tbox_re"
JAR_DIR="$(mktemp -d)"
WORK="$(mktemp -d)"
TAG="$(date +%m%d)-$$"
PASS=0
FAIL=0
SKIP=0

# 치워야 할 것들 — 만든 **순서대로** 쌓고, 정리는 역순(하위 폴더부터)이다.
MADE_FOLDERS=''
MADE_FILES=''
CLEANED=0

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
fid_of()  { printf '%s' "$1" | sed -n 's/.*"folder":{"id":\([0-9]\{1,9\}\).*/\1/p' | head -1; }

# JSON 판독기 (tests/api_read.sh 와 같다). 표준입력의 JSON 을 `d` 로 놓고 파이썬 식을 평가한다.
# 토큰 값은 이 경로에 오지 않는다(응답 본문만 넣는다).
jq_py() { python3 -c 'import sys,json
d=json.load(sys.stdin)
print(eval(sys.argv[1]))' "$1" 2>/dev/null; }

# ─── 정리 — 만든 파일·폴더를 세션으로 되돌린다 (trap 에서도 돈다) ──
# 삭제는 **토큰으로 열려 있지 않다**(F-58 ⑤). 그래서 정리는 언제나 세션 경로다.
# 지우는 자격: Admin 은 남의 폴더·파일도 지울 수 있으므로 qa_admin01 을 먼저 쓰고,
# 그 세션이 없으면 만든 계정(귀속 계정) 세션으로 되돌린다.
del_file() {
  local id="$1" c
  [ -n "$id" ] || return 0
  if [ -n "${ADM_CSRF:-}" ]; then
    c=$(curl -s -o /dev/null -w '%{http_code}' -X DELETE -b "$JAR_DIR/qa_admin01.jar" -A "$UA" \
        -H "X-CSRF-Token: $ADM_CSRF" "$BASE/api/files/$id")
    [ "$c" = "200" ] && { printf '  · 파일 %s 휴지통 [%s]\n' "$id" "$c"; return 0; }
  fi
  if [ -n "${OWN_CSRF:-}" ] && [ -n "${TOKEN_USER:-}" ]; then
    c=$(curl -s -o /dev/null -w '%{http_code}' -X DELETE -b "$JAR_DIR/$TOKEN_USER.jar" -A "$UA" \
        -H "X-CSRF-Token: $OWN_CSRF" "$BASE/api/files/$id")
    printf '  · 파일 %s 휴지통 [%s]\n' "$id" "$c"; return 0
  fi
  printf '  ! 파일 %s 를 치우지 못했다 — 세션 없음\n' "$id"
}
del_folder() {
  local num="$1" c
  [ -n "$num" ] || return 0
  for who in qa_admin01 "${TOKEN_USER:-}"; do
    [ -n "$who" ] || continue
    local csrf=''
    [ "$who" = "qa_admin01" ] && csrf="${ADM_CSRF:-}" || csrf="${OWN_CSRF:-}"
    [ -n "$csrf" ] || continue
    c=$(curl -s -o /dev/null -w '%{http_code}' -X DELETE -b "$JAR_DIR/$who.jar" -A "$UA" \
        -H "X-CSRF-Token: $csrf" "$BASE/api/folders/$num")
    if [ "$c" != "200" ]; then
      # 휴지통 파일만 남은 갈래(400 folder_has_trashed) — 확인 꼬리표로 한 번 더 (ARCH 5.16)
      c=$(curl -s -o /dev/null -w '%{http_code}' -X DELETE -b "$JAR_DIR/$who.jar" -A "$UA" \
          -H "X-CSRF-Token: $csrf" "$BASE/api/folders/$num?move_trashed=1")
    fi
    printf '  · 폴더 %s 삭제 [%s] (%s)\n' "$num" "$c" "$who"
    [ "$c" = "200" ] && return 0
  done
  printf '  ! 폴더 %s 를 치우지 못했다 — 손으로 확인이 필요하다\n' "$num"
}
cleanup_artifacts() {
  [ "$CLEANED" -eq 0 ] || return 0
  CLEANED=1
  [ -n "$MADE_FILES$MADE_FOLDERS" ] || return 0
  hr "정리 — 시험이 만든 것 되돌리기 (파일은 휴지통 · 폴더는 하위부터)"
  for f in $MADE_FILES; do del_file "$f"; done
  # 만든 역순 = 하위 폴더부터
  for n in $(printf '%s\n' $MADE_FOLDERS | tac); do del_folder "$n"; done
}
cleanup() { cleanup_artifacts; rm -rf "$JAR_DIR" "$WORK"; }
trap cleanup EXIT

# ─── 시작 전 관문 ────────────────────────────────────────────────
hr "F-58 프로그램용 폴더 관리 — 시험 시작 전 확인"

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
  echo "→ 귀속이 검토 계정이다. (a)(b)(c)(d)(f) 를 실행한다. (토큰 값은 출력하지 않는다)"
else
  API_TOKEN=''
  echo "→ 귀속이 검토 계정(qa_)이 아니다 — (a)(b)(c)(d)(f) 는 **SKIP**. Fable 이 검토에서 귀속을 바꿔 돌린다."
fi
# 어떤 서버에서도 맞을 수 없는 고정 문자열. (e) 의 「틀린 토큰」과 「헤더 무시」에 쓴다.
WRONG_TOKEN='wrong-token-0000000000000000000000000000000000'

# ─── 로그인 헬퍼 (tests/api_read.sh 와 같다) ─────────────────────
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

# ─── 준비: 세션 둘, 그리고 admin-only 폴더 S ─────────────────────
hr "준비 — 검토 계정 세션 · admin-only 폴더 S"

EMP_CSRF=$(login qa_employee01)
ADM_CSRF=$(login qa_admin01)
[ -n "${EMP_CSRF:-}" ] && note "qa_employee01 세션 확보" || note "qa_employee01 로그인 실패 — (g) 회귀는 SKIP 된다"
[ -n "${ADM_CSRF:-}" ] && note "qa_admin01 세션 확보"    || note "qa_admin01 로그인 실패 — 폴더 S 를 만들지 못한다"

# (b)(c) 는 **토큰 귀속 계정 그 자신**의 세션도 쓴다((a) 의 세션 대조·정리).
OWN_CSRF=''
if [ "$USE_TOKEN" -eq 1 ]; then
  case "$TOKEN_USER" in
    qa_employee01) OWN_CSRF="${EMP_CSRF:-}" ;;
    qa_admin01)    OWN_CSRF="${ADM_CSRF:-}" ;;
    *)             OWN_CSRF=$(login "$TOKEN_USER") ;;
  esac
  [ -n "${OWN_CSRF:-}" ] && note "귀속 계정($TOKEN_USER) 세션 확보" || note "귀속 계정($TOKEN_USER) 세션 없음 — (a) 세션 대조는 SKIP"
fi

S_NAME="F58-S-$TAG"
S_ID=''
if [ -n "${ADM_CSRF:-}" ]; then
  OUT=$(curl -s -w '\n%{http_code}' -X POST -b "$JAR_DIR/qa_admin01.jar" -A "$UA" \
        -H "X-CSRF-Token: $ADM_CSRF" -H 'Content-Type: application/json' \
        -d "{\"name\":\"$S_NAME\",\"access_level\":\"admin-only\"}" "$BASE/api/folders")
  echo "  $S_NAME 생성 [$(code_of "$OUT")] $(body_of "$OUT")"
  S_ID=$(fid_of "$(body_of "$OUT")")
  [ -n "$S_ID" ] && MADE_FOLDERS="$MADE_FOLDERS $S_ID"
fi

# 귀속 계정이 admin-only 를 읽을 수 있으면 (a) 의 「S 가 보이지 않는다」는 뜻이 없다.
TOKEN_ROLE=''
if [ "$USE_TOKEN" -eq 1 ]; then
  TOKEN_ROLE=$(curl -s -A "$UA" -H "X-API-Token: $API_TOKEN" "$BASE/api/whoami" \
               | sed -n 's/.*"role":"\([A-Za-z]*\)".*/\1/p')
  note "귀속 계정 역할: ${TOKEN_ROLE:-알 수 없음}"
fi

# ─── (a) 목록 — 세션과 같은 JSON · 못 읽는 폴더는 없다 ───────────
hr "(a) 토큰으로 GET /api/folders"
if [ "$USE_TOKEN" -eq 0 ]; then
  skip "(a)" "토큰 귀속이 검토 계정(qa_)이 아님 — 값은 읽지 않았다"
else
  OUT=$(curl -s -w '\n%{http_code}' -A "$UA" -H "X-API-Token: $API_TOKEN" "$BASE/api/folders")
  CODE=$(code_of "$OUT"); printf '%s' "$(body_of "$OUT")" > "$WORK/a_token.json"
  echo "  응답: $(body_of "$OUT")"
  expect "상태" "200" "$CODE"
  expect "최상위 키" "folder,folders" "$(jq_py '",".join(sorted(d.keys()))' < "$WORK/a_token.json")"
  expect "folder 키" "access_level,breadcrumb,id,name" "$(jq_py '",".join(sorted(d["folder"].keys()))' < "$WORK/a_token.json")"

  if [ -z "${OWN_CSRF:-}" ]; then
    skip "(a) 세션 대조" "귀속 계정($TOKEN_USER) 세션 없음"
  else
    curl -s -b "$JAR_DIR/$TOKEN_USER.jar" -A "$UA" "$BASE/api/folders" > "$WORK/a_sess.json"
    if cmp -s "$WORK/a_token.json" "$WORK/a_sess.json"; then
      expect "토큰 응답 == 같은 계정 세션 응답 (JSON 문자열 동일 — 투영 없음)" "같음" "같음"
    else
      expect "토큰 응답 == 같은 계정 세션 응답 (JSON 문자열 동일 — 투영 없음)" "같음" "다름"
      note "세션: $(cat "$WORK/a_sess.json")"
    fi
  fi

  if [ -z "$S_ID" ]; then
    skip "(a) S 숨김·403" "폴더 S 를 만들지 못했다"
  elif [ "$TOKEN_ROLE" = "Admin" ]; then
    skip "(a) S 숨김·403" "귀속 계정이 Admin 이라 admin-only 를 읽을 수 있다 — 이 항목은 Employee 귀속에서만 뜻이 있다"
  else
    expect "트리에 S 없음 (S-02 — 숨김이 아니라 응답에 없음)" "0" \
      "$(jq_py "sum(1 for f in d['folders'] if f['name']=='$S_NAME')" < "$WORK/a_token.json")"
    OUT=$(curl -s -w '\n%{http_code}' -A "$UA" -H "X-API-Token: $API_TOKEN" "$BASE/api/folders?parent=$S_ID")
    echo "  ?parent=S → [$(code_of "$OUT")] $(body_of "$OUT")"
    expect "?parent=S 상태" "403" "$(code_of "$OUT")"
    expect "?parent=S 문구" '{"error":"권한이 없습니다"}' "$(body_of "$OUT")"
  fi
fi

# ─── (b) 생성 ───────────────────────────────────────────────────
hr "(b) 토큰으로 POST /api/folders"
A_ID=''
B_ID=''
A_NAME="F58-a-$TAG"
B_NAME="F58-b-$TAG"
T_AUDIT=$(mark)
if [ "$USE_TOKEN" -eq 0 ]; then
  skip "(b)" "토큰 귀속이 검토 계정(qa_)이 아님"
else
  OUT=$(curl -s -w '\n%{http_code}' -X POST -A "$UA" -H "X-API-Token: $API_TOKEN" \
        -H 'Content-Type: application/json' \
        -d "{\"name\":\"$A_NAME\",\"access_level\":\"internal\"}" "$BASE/api/folders")
  echo "  $A_NAME → [$(code_of "$OUT")] $(body_of "$OUT")"
  expect "생성 상태" "201" "$(code_of "$OUT")"
  A_ID=$(fid_of "$(body_of "$OUT")")
  [ -n "$A_ID" ] && MADE_FOLDERS="$MADE_FOLDERS $A_ID"
  expect "folder.id 가 왔다" "yes" "$([ -n "$A_ID" ] && echo yes || echo no)"

  OUT=$(curl -s -w '\n%{http_code}' -X POST -A "$UA" -H "X-API-Token: $API_TOKEN" \
        -H 'Content-Type: application/json' \
        -d "{\"name\":\"$A_NAME\",\"access_level\":\"internal\"}" "$BASE/api/folders")
  echo "  같은 이름 다시 → [$(code_of "$OUT")] $(body_of "$OUT")"
  expect "중복 이름 상태" "409" "$(code_of "$OUT")"
  expect "중복 이름 문구" '{"error":"이미 있는 이름입니다"}' "$(body_of "$OUT")"

  OUT=$(curl -s -w '\n%{http_code}' -X POST -A "$UA" -H "X-API-Token: $API_TOKEN" \
        -H 'Content-Type: application/json' -d '{"access_level":"internal"}' "$BASE/api/folders")
  echo "  name 없음 → [$(code_of "$OUT")] $(body_of "$OUT")"
  expect "name 없음 상태" "400" "$(code_of "$OUT")"
  expect "name 없음 문구" '{"error":"잘못된 요청입니다"}' "$(body_of "$OUT")"

  OUT=$(curl -s -w '\n%{http_code}' -X POST -A "$UA" -H "X-API-Token: $API_TOKEN" \
        -H 'Content-Type: application/json' \
        -d "{\"name\":\"F58-x-$TAG\",\"access_level\":\"admin-only\"}" "$BASE/api/folders")
  echo "  access_level=admin-only → [$(code_of "$OUT")] $(body_of "$OUT")"
  if [ "$TOKEN_ROLE" = "Admin" ]; then
    XID=$(fid_of "$(body_of "$OUT")"); [ -n "$XID" ] && MADE_FOLDERS="$MADE_FOLDERS $XID"
    skip "(b) admin-only 403" "귀속 계정이 Admin 이라 만들 수 있다 — Employee 귀속에서만 뜻이 있다"
  else
    expect "admin-only 상태(그 등급으로 만들 자격 없음)" "403" "$(code_of "$OUT")"
    expect "admin-only 문구" '{"error":"권한이 없습니다"}' "$(body_of "$OUT")"
  fi
fi

# ─── (c) 폴더 이름 변경·이동 · 허용 키 밖은 400 ──────────────────
hr "(c) 토큰으로 PATCH /api/folders/{num}"
if [ "$USE_TOKEN" -eq 0 ]; then
  skip "(c)" "토큰 귀속이 검토 계정(qa_)이 아님"
elif [ -z "$A_ID" ]; then
  skip "(c)" "(b) 에서 폴더 A 를 만들지 못했다"
else
  A_NAME2="F58-a2-$TAG"
  OUT=$(curl -s -w '\n%{http_code}' -X PATCH -A "$UA" -H "X-API-Token: $API_TOKEN" \
        -H 'Content-Type: application/json' -d "{\"name\":\"$A_NAME2\"}" "$BASE/api/folders/$A_ID")
  echo "  이름 변경 → [$(code_of "$OUT")] $(body_of "$OUT")"
  expect "이름 변경 상태" "200" "$(code_of "$OUT")"

  OUT=$(curl -s -w '\n%{http_code}' -X POST -A "$UA" -H "X-API-Token: $API_TOKEN" \
        -H 'Content-Type: application/json' \
        -d "{\"name\":\"$B_NAME\",\"access_level\":\"internal\"}" "$BASE/api/folders")
  B_ID=$(fid_of "$(body_of "$OUT")")
  [ -n "$B_ID" ] && MADE_FOLDERS="$MADE_FOLDERS $B_ID"
  echo "  $B_NAME 생성 → [$(code_of "$OUT")] $(body_of "$OUT")"

  if [ -z "$B_ID" ]; then
    skip "(c) 이동·cycle" "폴더 B 를 만들지 못했다"
  else
    OUT=$(curl -s -w '\n%{http_code}' -X PATCH -A "$UA" -H "X-API-Token: $API_TOKEN" \
          -H 'Content-Type: application/json' -d "{\"parent_id\":$A_ID}" "$BASE/api/folders/$B_ID")
    CODE=$(code_of "$OUT"); printf '%s' "$(body_of "$OUT")" > "$WORK/c_move.json"
    echo "  B 를 A 아래로 → [$CODE] $(body_of "$OUT")"
    expect "이동 상태" "200" "$CODE"
    expect "옮긴 뒤 depth" "2" "$(jq_py 'd["folder"]["depth"]' < "$WORK/c_move.json")"

    OUT=$(curl -s -w '\n%{http_code}' -X PATCH -A "$UA" -H "X-API-Token: $API_TOKEN" \
          -H 'Content-Type: application/json' -d "{\"parent_id\":$B_ID}" "$BASE/api/folders/$A_ID")
    echo "  A 를 자식 B 아래로(cycle) → [$(code_of "$OUT")] $(body_of "$OUT")"
    expect "cycle 상태" "400" "$(code_of "$OUT")"
    expect "cycle 문구(F-68 사유 문장 — ARCH 5.30)" '{"error":"폴더를 자기 자신이나 그 하위 폴더 안으로 옮길 수 없습니다"}' "$(body_of "$OUT")"
  fi

  OUT=$(curl -s -w '\n%{http_code}' -X PATCH -A "$UA" -H "X-API-Token: $API_TOKEN" \
        -H 'Content-Type: application/json' -d '{"access_level":"public"}' "$BASE/api/folders/$A_ID")
  echo "  access_level (토큰 허용 키 밖) → [$(code_of "$OUT")] $(body_of "$OUT")"
  expect "access_level 상태(토큰 허용 키 밖 — 등급 변경은 닫혀 있다)" "400" "$(code_of "$OUT")"
  expect "access_level 문구" '{"error":"잘못된 요청입니다"}' "$(body_of "$OUT")"

  OUT=$(curl -s -w '\n%{http_code}' -X PATCH -A "$UA" -H "X-API-Token: $API_TOKEN" \
        -H 'Content-Type: application/json' -d "{\"name\":\"$A_NAME2\",\"cascade\":false}" "$BASE/api/folders/$A_ID")
  echo "  cascade (토큰 허용 키 밖) → [$(code_of "$OUT")] $(body_of "$OUT")"
  expect "cascade 상태" "400" "$(code_of "$OUT")"
  expect "cascade 문구" '{"error":"잘못된 요청입니다"}' "$(body_of "$OUT")"

  if [ -z "$S_ID" ]; then
    skip "(c) S 이름 변경 403" "폴더 S 를 만들지 못했다"
  elif [ "$TOKEN_ROLE" = "Admin" ]; then
    skip "(c) S 이름 변경 403" "귀속 계정이 Admin 이라 남의 폴더도 고칠 수 있다"
  else
    OUT=$(curl -s -w '\n%{http_code}' -X PATCH -A "$UA" -H "X-API-Token: $API_TOKEN" \
          -H 'Content-Type: application/json' -d '{"name":"F58-stolen"}' "$BASE/api/folders/$S_ID")
    echo "  S 이름 변경 → [$(code_of "$OUT")] $(body_of "$OUT")"
    expect "남의(못 읽는) 폴더 상태" "403" "$(code_of "$OUT")"
    expect "남의(못 읽는) 폴더 문구" '{"error":"권한이 없습니다"}' "$(body_of "$OUT")"
  fi
fi

# ─── (d) 파일 이동 ──────────────────────────────────────────────
hr "(d) 토큰으로 PATCH /api/files/{id} — folder_id 만"
D_FILE=''
if [ "$USE_TOKEN" -eq 0 ]; then
  skip "(d)" "토큰 귀속이 검토 계정(qa_)이 아님"
elif [ -z "$A_ID" ]; then
  skip "(d)" "옮겨 넣을 폴더 A 가 없다"
else
  printf 'F-58 시험용 파일입니다. %s\n' "$TAG" > "$WORK/F58-move-$TAG.txt"
  OUT=$(curl -s -w '\n%{http_code}' -X POST -A "$UA" -H "X-API-Token: $API_TOKEN" \
        -F "file=@$WORK/F58-move-$TAG.txt" -F 'level=internal' "$BASE/api/upload")
  echo "  업로드 → [$(code_of "$OUT")] $(body_of "$OUT")"
  D_FILE=$(uuid_of "$(body_of "$OUT")")
  [ -n "$D_FILE" ] && MADE_FILES="$MADE_FILES $D_FILE"

  if [ -z "$D_FILE" ]; then
    skip "(d)" "토큰 업로드가 id 를 주지 않았다"
  else
    OUT=$(curl -s -w '\n%{http_code}' -X PATCH -A "$UA" -H "X-API-Token: $API_TOKEN" \
          -H 'Content-Type: application/json' -d "{\"folder_id\":$A_ID}" "$BASE/api/files/$D_FILE")
    echo "  A 로 이동 → [$(code_of "$OUT")] $(body_of "$OUT")"
    expect "파일 이동 상태" "200" "$(code_of "$OUT")"

    curl -s -A "$UA" -H "X-API-Token: $API_TOKEN" "$BASE/api/files?folder=$A_ID&limit=50" > "$WORK/d_list.json"
    expect "?folder=A 배열에 그 id 가 있다" "1" \
      "$(jq_py "sum(1 for f in d if f['id']=='$D_FILE')" < "$WORK/d_list.json")"

    OUT=$(curl -s -w '\n%{http_code}' -X PATCH -A "$UA" -H "X-API-Token: $API_TOKEN" \
          -H 'Content-Type: application/json' -d '{"folder_id":null}' "$BASE/api/files/$D_FILE")
    echo "  루트 복귀 → [$(code_of "$OUT")] $(body_of "$OUT")"
    expect "루트 복귀 상태" "200" "$(code_of "$OUT")"

    OUT=$(curl -s -w '\n%{http_code}' -X PATCH -A "$UA" -H "X-API-Token: $API_TOKEN" \
          -H 'Content-Type: application/json' -d '{"description":"x"}' "$BASE/api/files/$D_FILE")
    echo "  description (토큰 허용 키 밖) → [$(code_of "$OUT")] $(body_of "$OUT")"
    expect "description 상태(토큰 허용 키 밖)" "400" "$(code_of "$OUT")"
    expect "description 문구" '{"error":"잘못된 요청입니다"}' "$(body_of "$OUT")"

    if [ -z "$S_ID" ]; then
      skip "(d) 못 읽는 폴더 400" "폴더 S 를 만들지 못했다"
    elif [ "$TOKEN_ROLE" = "Admin" ]; then
      skip "(d) 못 읽는 폴더 400" "귀속 계정이 Admin 이라 S 를 읽을 수 있다"
    else
      OUT=$(curl -s -w '\n%{http_code}' -X PATCH -A "$UA" -H "X-API-Token: $API_TOKEN" \
            -H 'Content-Type: application/json' -d "{\"folder_id\":$S_ID}" "$BASE/api/files/$D_FILE")
      echo "  못 읽는 폴더로 이동 → [$(code_of "$OUT")] $(body_of "$OUT")"
      expect "못 읽는 폴더 상태(없는 폴더와 같은 bad_folder)" "400" "$(code_of "$OUT")"
      expect "못 읽는 폴더 문구(F-68 bad_folder 문장 — 없는 폴더와 같다)" '{"error":"folder_id 가 없거나 접근할 수 없는 폴더입니다"}' "$(body_of "$OUT")"
    fi
  fi
fi

# ─── (e) 닫힘 — 삭제는 토큰 라우트다(틀린 값이면 401) · 집합 밖은 헤더를 읽지 않는다 ──
hr "(e) 닫힘 — 삭제는 토큰 라우트(틀린 토큰 401) · 헤더 없음 · 헤더 무시"
# 이 항목은 **토큰 귀속과 무관하게** 돈다. 보는 것이 「틀린 값이면 어떤 답인가」이기 때문이다.
# ★ 2026-09-16 (F-59) — 옛 기대는 「삭제 둘은 헤더를 읽지도 않는다(세션 경로 401)」였다.
#   이제 DELETE 둘은 **토큰 라우트**(TBOX_API_TOKEN_ROUTES, 그중 개인 토큰 전용)라
#   틀린 토큰이면 401 「API 토큰이 올바르지 않습니다」 + `auth.token deny bad_token` 이다.
#   설계가 바뀐 것이므로 시험의 기대를 바꾼다. 「헤더 무시」는 아래 **닫힌 라우트**로 확인한다.
# ★ 삭제에는 **언제나 틀린 토큰**을 쓴다 — 진짜 토큰으로 부르면 실제로 지워질 수 있다.
note "삭제 둘은 틀린 토큰으로 확인한다(진짜 토큰은 쓰지 않는다 — 지워질 수 있다)"

E_NUM="${A_ID:-999999999}"
E_UUID='00000000-0000-4000-8000-000000000000'
T_E=$(mark)

OUT=$(curl -s -w '\n%{http_code}' -X DELETE -A "$UA" -H "X-API-Token: $WRONG_TOKEN" "$BASE/api/folders/$E_NUM")
echo "  틀린 토큰 DELETE /api/folders/$E_NUM → [$(code_of "$OUT")] $(body_of "$OUT")"
expect "틀린 토큰 폴더 삭제 상태(F-59 — 토큰 라우트)" "401" "$(code_of "$OUT")"
expect "틀린 토큰 폴더 삭제 문구" '{"error":"API 토큰이 올바르지 않습니다"}' "$(body_of "$OUT")"

OUT=$(curl -s -w '\n%{http_code}' -X DELETE -A "$UA" -H "X-API-Token: $WRONG_TOKEN" "$BASE/api/files/$E_UUID")
echo "  틀린 토큰 DELETE /api/files/$E_UUID → [$(code_of "$OUT")] $(body_of "$OUT")"
expect "틀린 토큰 파일 삭제 상태(F-59 — 토큰 라우트)" "401" "$(code_of "$OUT")"
expect "틀린 토큰 파일 삭제 문구" '{"error":"API 토큰이 올바르지 않습니다"}' "$(body_of "$OUT")"

OUT=$(curl -s -w '\n%{http_code}' -A "$UA" "$BASE/api/folders")
echo "  헤더 없음 GET /api/folders → [$(code_of "$OUT")] $(body_of "$OUT")"
expect "헤더 없음 상태(세션 경로 그대로)" "401" "$(code_of "$OUT")"
expect "헤더 없음 문구" '{"error":"로그인이 필요합니다"}' "$(body_of "$OUT")"

OUT=$(curl -s -w '\n%{http_code}' -A "$UA" -H "X-API-Token: $WRONG_TOKEN" "$BASE/api/folders")
echo "  틀린 토큰 GET /api/folders → [$(code_of "$OUT")] $(body_of "$OUT")"
expect "틀린 토큰 상태(세션으로 되돌아가지 않는다)" "401" "$(code_of "$OUT")"
expect "틀린 토큰 문구" '{"error":"API 토큰이 올바르지 않습니다"}' "$(body_of "$OUT")"

# 「헤더 무시」 — 토큰 집합 **밖**의 라우트는 헤더를 읽는 코드에 닿지도 않는다.
#   계정 창구(user.manage)와 개인 토큰 발급 창구가 그것이다(F-59: 토큰이 토큰을 만들 수 없다).
OUT=$(curl -s -w '\n%{http_code}' -A "$UA" -H "X-API-Token: $WRONG_TOKEN" "$BASE/api/users")
echo "  틀린 토큰 GET /api/users → [$(code_of "$OUT")] $(body_of "$OUT")"
expect "닫힌 라우트 상태(헤더 무시 — 세션 경로)" "401" "$(code_of "$OUT")"
expect "닫힌 라우트 문구" '{"error":"로그인이 필요합니다"}' "$(body_of "$OUT")"

# F-66 (2026-09-22) — 관리자 발급 창구(POST)는 **라우트에서 사라졌다**. 같은 경로의 목록
#   라우트(GET)는 남아 있으므로 라우터 [1] 의 「경로는 맞고 메서드만 다르다」 갈래가 먼저
#   잡아 **405** 다(404 가 아니다 — 인증·인가에는 닿지 않는다. 헤더도 읽히지 않는다).
#   본인 발급 창구(`POST /api/me/api-tokens`)도 토큰 집합 밖이라 세션 경로 401 이다.
OUT=$(curl -s -w '\n%{http_code}' -X POST -A "$UA" -H "X-API-Token: $WRONG_TOKEN" \
      -H 'Content-Type: application/json' -d '{"name":"x","reason_text":"x"}' \
      "$BASE/api/users/999999999/api-tokens")
echo "  틀린 토큰 POST /api/users/{num}/api-tokens → [$(code_of "$OUT")] $(body_of "$OUT")"
expect "옛 관리자 발급 창구 → 405 (F-66 으로 POST 만 제거 · GET 은 남음)" "405" "$(code_of "$OUT")"
expect "옛 관리자 발급 창구 문구" '{"error":"허용되지 않는 메서드입니다"}' "$(body_of "$OUT")"

OUT=$(curl -s -w '\n%{http_code}' -X POST -A "$UA" -H "X-API-Token: $WRONG_TOKEN" \
      -H 'Content-Type: application/json' \
      -d '{"name":"x","purpose":"x","scope":"read","password":"x","otp":"000000"}' \
      "$BASE/api/me/api-tokens")
echo "  틀린 토큰 POST /api/me/api-tokens → [$(code_of "$OUT")] $(body_of "$OUT")"
expect "본인 발급 창구도 헤더 무시(세션 경로)" "401" "$(code_of "$OUT")"
expect "본인 발급 창구 문구" '{"error":"로그인이 필요합니다"}' "$(body_of "$OUT")"

if [ "$HAVE_PSQL" -eq 1 ]; then
  # 토큰 집합 **안**의 라우트로 보낸 틀린 토큰 셋만 auth.token 에 남는다
  # (DELETE 둘 + GET /api/folders). 닫힌 라우트 둘은 헤더를 읽지도 않아 여기 없다.
  N=$(psqlq "SELECT count(*) FROM audit_logs WHERE action='auth.token' AND id > ${T_E:-0}")
  expect "auth.token 감사 행(틀린 토큰 3건)" "3" "${N:-?}"
  GOT=$(psqlq "SELECT string_agg(result||' '||coalesce(reason,'없음')||' @'||coalesce(target_name,'?'), ' | ' ORDER BY id) FROM audit_logs WHERE action='auth.token' AND id > ${T_E:-0}")
  expect "그 세 건" "deny bad_token @DELETE /api/folders/{num} | deny bad_token @DELETE /api/files/{id} | deny bad_token @GET /api/folders" "${GOT:-없음}"
fi

# ─── (f) 감사 — 전부 detail.auth=api_token · 문장표에 없는 조합 0 ─────────
hr "(f) 감사 — (b)(c)(d) 의 행"
if [ "$USE_TOKEN" -eq 0 ]; then
  skip "(f)" "토큰 귀속이 검토 계정(qa_)이 아님 — 토큰 요청을 보내지 않았다"
elif [ "$HAVE_PSQL" -eq 0 ]; then
  skip "(f)" "psql 을 쓸 수 없음"
else
  N=$(psqlq "SELECT count(*) FROM audit_logs WHERE id > ${T_AUDIT:-0} AND id <= ${T_E:-0} AND action IN ('folder.create','folder.update','file.update') AND actor_name = '$TOKEN_USER' AND detail->>'auth' IS DISTINCT FROM 'api_token'")
  expect "detail.auth 가 api_token 이 아닌 행" "0" "${N:-?}"
  ROWS=$(psqlq "SELECT string_agg(action||' '||result||' '||coalesce(reason,'-'), ' | ' ORDER BY id) FROM audit_logs WHERE id > ${T_AUDIT:-0} AND id <= ${T_E:-0} AND action IN ('folder.create','folder.update','file.update')")
  note "행: ${ROWS:-없음}"
  # 조합의 기준은 **문장표**다. Audit::summary 는 `action|result[|reason]` 키를
  # SUMMARY_TEMPLATES 에서 찾지 못하면 예외를 던지고 그 요청은 500 으로 끝난다 —
  # 즉 「표에 없는 조합」은 감사 행으로 남을 수 없다. 이 DB 에 그 조합의 과거 행이
  # 있었는지는 F-58 과 무관하다(처음 만들어 본 거부 갈래면 이력이 없는 것이 정상이다).
  COMBOS=$(psqlq "SELECT DISTINCT action||'|'||result||coalesce('|'||reason,'') FROM audit_logs WHERE id > ${T_AUDIT:-0} AND id <= ${T_E:-0} AND action IN ('folder.create','folder.update','file.update') ORDER BY 1")
  NEW=0
  for k in $COMBOS; do
    if [ "$(grep -cF "'$k'" "$ROOT/src/Audit.php")" -eq 0 ]; then
      note "문장표에 없는 조합: $k"
      NEW=$((NEW+1))
    fi
  done
  expect "문장표에 없는 (action·result·reason) 조합 (Audit.php SUMMARY_TEMPLATES 기준)" "0" "$NEW"
fi

# ─── (g) 회귀 — 세션 경로는 한 글자도 바뀌지 않았다 ─────────────
hr "(g) 회귀 — 세션 허용 키 여섯·넷 불변 · 트리 모양 불변"
G_FOLDER=''
G_FILE=''
if [ -z "${EMP_CSRF:-}" ]; then
  skip "(g) 세션 회귀" "qa_employee01 세션 없음"
else
  MY_TEAM=$(curl -s -b "$JAR_DIR/qa_employee01.jar" -A "$UA" "$BASE/api/me" \
            | sed -n 's/.*"team_id":\([0-9]*\).*/\1/p' | head -1)
  note "qa_employee01 team_id=${MY_TEAM:-없음}"

  OUT=$(curl -s -w '\n%{http_code}' -X POST -b "$JAR_DIR/qa_employee01.jar" -A "$UA" \
        -H "X-CSRF-Token: $EMP_CSRF" -H 'Content-Type: application/json' \
        -d "{\"name\":\"F58-g-$TAG\",\"access_level\":\"internal\"}" "$BASE/api/folders")
  echo "  세션 폴더 생성 → [$(code_of "$OUT")] $(body_of "$OUT")"
  G_FOLDER=$(fid_of "$(body_of "$OUT")")
  [ -n "$G_FOLDER" ] && MADE_FOLDERS="$MADE_FOLDERS $G_FOLDER"

  if [ -z "$G_FOLDER" ] || [ -z "${MY_TEAM:-}" ]; then
    skip "(g) 세션 등급 변경" "폴더 또는 team_id 가 없다"
  else
    OUT=$(curl -s -w '\n%{http_code}' -X PATCH -b "$JAR_DIR/qa_employee01.jar" -A "$UA" \
          -H "X-CSRF-Token: $EMP_CSRF" -H 'Content-Type: application/json' \
          -d "{\"access_level\":\"team\",\"team_id\":$MY_TEAM}" "$BASE/api/folders/$G_FOLDER")
    echo "  세션 등급 변경 internal→team → [$(code_of "$OUT")] $(body_of "$OUT")"
    expect "세션 PATCH /api/folders 등급 변경(허용 키 여섯 불변)" "200" "$(code_of "$OUT")"
  fi

  printf 'F-58 세션 회귀용 파일입니다. %s\n' "$TAG" > "$WORK/F58-g-$TAG.txt"
  OUT=$(curl -s -w '\n%{http_code}' -X POST -b "$JAR_DIR/qa_employee01.jar" -A "$UA" \
        -H "X-CSRF-Token: $EMP_CSRF" -F "file=@$WORK/F58-g-$TAG.txt" -F 'access_level=internal' \
        "$BASE/api/files")
  echo "  세션 업로드 → [$(code_of "$OUT")] $(body_of "$OUT")"
  G_FILE=$(uuid_of "$(body_of "$OUT")")
  [ -n "$G_FILE" ] && MADE_FILES="$MADE_FILES $G_FILE"

  if [ -z "$G_FILE" ]; then
    skip "(g) 세션 설명 변경" "세션 업로드가 id 를 주지 않았다"
  else
    OUT=$(curl -s -w '\n%{http_code}' -X PATCH -b "$JAR_DIR/qa_employee01.jar" -A "$UA" \
          -H "X-CSRF-Token: $EMP_CSRF" -H 'Content-Type: application/json' \
          -d '{"description":"F-58 회귀"}' "$BASE/api/files/$G_FILE")
    echo "  세션 설명 변경 → [$(code_of "$OUT")] $(body_of "$OUT")"
    expect "세션 PATCH /api/files 설명 변경(허용 키 넷 불변)" "200" "$(code_of "$OUT")"
  fi

  OUT=$(curl -s -w '\n%{http_code}' -b "$JAR_DIR/qa_employee01.jar" -A "$UA" "$BASE/api/folders")
  CODE=$(code_of "$OUT"); printf '%s' "$(body_of "$OUT")" > "$WORK/g_tree.json"
  expect "세션 GET /api/folders 상태" "200" "$CODE"
  expect "세션 트리 최상위 키" "folder,folders" "$(jq_py '",".join(sorted(d.keys()))' < "$WORK/g_tree.json")"
  expect "세션 트리 folder 키" "access_level,breadcrumb,id,name" \
    "$(jq_py '",".join(sorted(d["folder"].keys()))' < "$WORK/g_tree.json")"
  expect "세션 트리 자식 키" "access_level,child_count,depth,file_count,id,name,team_id,team_name" \
    "$(jq_py '",".join(sorted(d["folders"][0].keys())) if d["folders"] else "(빈 트리)"' < "$WORK/g_tree.json")"
fi

# ─── 정리 (트랩보다 먼저 — 결과를 눈으로 보려고) ────────────────
cleanup_artifacts

# ─── (g) 이어서 — 린트 · php -l · bash -n ───────────────────────
hr "(g) 회귀 — 린트 · php -l · bash -n"
bash "$ROOT/scripts/security_lint.sh" >"$WORK/lint.out" 2>&1
expect "scripts/security_lint.sh" "0" "$?"
tail -1 "$WORK/lint.out" | sed 's/^/  /'

PHPBAD=0
for f in public/index.php src/handlers/folders_update.php src/handlers/files_update.php; do
  php -l "$ROOT/$f" >/dev/null 2>&1 || { echo "  ✖ php -l 실패: $f"; PHPBAD=1; }
done
expect "바뀐 PHP 3개 php -l" "0" "$PHPBAD"

SHBAD=0
bash -n "$ROOT/tests/api_folders.sh" 2>/dev/null || SHBAD=1
bash -n "$ROOT/tests/api_read.sh"    2>/dev/null || SHBAD=1
bash -n "$ROOT/tests/api_whoami.sh"  2>/dev/null || SHBAD=1
expect "bash -n (시험 스크립트 셋)" "0" "$SHBAD"

# ─── 마무리 ─────────────────────────────────────────────────────
hr "결과"
printf '통과 %d · 실패 %d · 건너뜀/미검증 %d\n' "$PASS" "$FAIL" "$SKIP"
if [ "$USE_TOKEN" -eq 0 ]; then
  echo "(a)(b)(c)(d)(f) 는 토큰 귀속이 검토 계정(qa_)이 아니라 실행하지 않았다 — **미검증**이다."
fi
[ "$FAIL" -eq 0 ] || exit 1
exit 0
