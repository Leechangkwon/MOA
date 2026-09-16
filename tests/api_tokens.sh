#!/usr/bin/env bash
# tests/api_tokens.sh — F-59 「사용자별 프로그램 토큰 · 개인 토큰으로 삭제」 수락 기준 실행
#                       (PRD 12.2 T-71, F-59, ARCH 4.6 · 6.2-5, SECURITY R-82 · R-90 · R-91)
#
#   (a) 발급  재인증 없는 Admin → 403 `next:reauth` · 재인증 뒤 POST → 201(토큰 48자
#       `[A-Za-z0-9]` · hint == 토큰 앞 6자) · 목록에 그 행(`hint` 있음 · `token` 키 **없음**) ·
#       사유 없음 400 · 이름 51자 400 · 모르는 키 400 · qa_manager01 세션 403 ·
#       살아 있는 5개 뒤 6번째 400(limit_exceeded) · 감사 `api_token.issue allow` 의 detail
#       (`token_id`·`token_name`·`reason_text`) · **DB 어디에도 토큰 원문 없음** ·
#       `api_tokens.token_hash` == 토큰의 sha256.
#   (b) 개인 토큰 사용  그 토큰으로 GET /api/whoami(`username`) · GET /api/files 200 ·
#       POST /api/upload 201 · GET /api/folders 200 · 감사 행에 `auth=api_token` ·
#       `token_kind=personal` · `token_id=<id>` · `api_tokens.last_used_at` 갱신.
#   (c) 개인 토큰으로 삭제  자기 파일 DELETE /api/files/{id} → 200 `{ok:true}`(휴지통,
#       `deleted_at` 설정) · 자기 빈 폴더 DELETE /api/folders/{num} → 200 · 파일이 든 폴더 → 400
#       「파일이 있는 폴더는 지울 수 없습니다」 · 남의(qa_admin01) 파일 → 403 · 없는 파일 id → 404 · 없는 폴더 번호 → 403(동일 문구) ·
#       감사 `file.delete allow`·`folder.delete allow` 에 `auth=api_token`·`token_id`.
#   (d) 공용 토큰 게이트  공용 토큰으로 DELETE /api/files/{id} → **403** 「이 창구는 개인
#       토큰으로만 쓸 수 있습니다」 + `auth.token deny shared_token_not_permitted` ·
#       공용 토큰으로 GET /api/files 는 여전히 200(`token_kind=shared` · `token_id` 없음).
#       ★ `.env` 의 귀속(API_TOKEN_USER)이 **검토 계정(qa_)일 때만** 돌린다. 아니면 SKIP.
#   (e) 회수  DELETE …/api-tokens/{tid} → 200 · 그 토큰으로 GET /api/whoami → **401**
#       「API 토큰이 올바르지 않습니다」 · 다시 회수 → 400 · 다른 계정의 tid → 404 ·
#       목록에 `revoked_at` 채워짐 · 감사 `api_token.revoke allow`.
#   (f) 계정 비활성  시험이 계정을 만들지 않으므로 **미검증**으로 적는다(코드 읽기로 대체:
#       `requireApiToken` 이 매 요청 4조건을 본다). 대신 회수된 토큰·틀린 토큰·헤더 없음
#       401 셋을 실측한다.
#   (g) 닫힘·회귀  개인 토큰으로 POST /api/users/{uid}/api-tokens → 401(라우트 집합 밖) ·
#       개인 토큰으로 PATCH /api/files/{id} {access_level} → 400 불변 · 세션 DELETE
#       /api/files/{id} CSRF 없이 → 403(웹 불변) · GET /api/whoami 키 넷 불변 ·
#       `\dp api_tokens` = `tbox_app=arw`(DELETE 없음) · alerts.php --rule AL-20 1건 ·
#       scripts/security_lint.sh 0 · php -l · bash -n.
#
# ★ 토큰 값 취급 (PRD S-09-11, SECURITY R-91, 지시서 금지 패턴)
#   발급받은 개인 토큰·hint·해시는 **변수와 임시 파일(0700 디렉터리) 안에서만** 다루고
#   화면·로그 어디에도 출력하지 않는다. 201 응답 본문은 echo 하지 않고 파일로 받아 파싱한다.
#   psql·grep 에 토큰을 **인자로 넘기지 않는다**(`ps` 로 보인다) — 패턴 파일·파이프로만 준다.
#
# 규칙 (CLAUDE.md 3장) — tests/api_folders.sh · tests/fetch_hosts.sh 와 같다
#   · 검토 계정(qa_ 접두)만. 시드 계정(admin01 …)은 사용자의 자기 공격 전용이다.
#   · 모든 요청에 `-A "tbox-review/<날짜>"`.
#   · 운영 DB·.env·nginx·php-fpm 에 손대지 않는다. `.env` 에서 읽는 것은 API_TOKEN_USER
#     **계정 이름**과 API_TOKEN **값(변수로만)** 뿐이고, 고치지 않는다.
#
# ★ 시험 안전 (SECURITY R-73 · R-80)
#   ① 최근 10분 감사 로그에 비-qa actor 가 있으면 시작하지 않는다(종료 코드 3)
#   ② 만드는 토큰·파일·폴더는 전부 `F59-` 접두 + 날짜·PID 이고, **trap 에서도** 스스로 치운다
#      (토큰은 회수, 파일은 휴지통, 폴더는 세션 삭제)
#   ③ 루트 파티션 여유가 1GB 미만이면 시작하지 않는다
#
# ★ 이 스크립트는 `db/migrations/2026-09-16_api_tokens.sql` 이 **적용된 뒤에야** 돈다
#   (표가 없으면 발급·조회가 전부 500 이다).
#
# 준비물: curl · php(TOTP) · python3(JSON) · sudo -n(seed-accounts.txt · psql). 없으면 해당 항목 SKIP.
# 사용: sudo -v && bash tests/api_tokens.sh
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

# 치워야 할 것들
MADE_TOKENS=''     # "uid:tid" 목록 — 전부 회수한다
MADE_FILES=''
MADE_FOLDERS=''
CLEANED=0

hr()  { printf '\n════════════════════════════════════════════════════════════\n%s\n════════════════════════════════════════════════════════════\n' "$1"; }
expect() {
  if [ "$2" = "$3" ]; then printf '  ✔ %s  (기대=%s 실제=%s)\n' "$1" "$2" "$3"; PASS=$((PASS+1))
  else printf '  ✖ %s  (기대=%s 실제=%s)\n' "$1" "$2" "$3"; FAIL=$((FAIL+1)); fi
}
note() { printf '  · %s\n' "$1"; }
skip() { printf '  ○ SKIP %s  (%s)\n' "$1" "$2"; SKIP=$((SKIP+1)); }

psqlq() { sudo -n -u postgres psql -d "$DB" -t -A -c "$1" 2>/dev/null; }

code_of() { printf '%s' "$1" | tail -1; }
body_of() { printf '%s' "$1" | sed '$d'; }
uuid_of() { printf '%s' "$1" | sed -n 's/.*"id":"\([0-9a-f-]\{36\}\)".*/\1/p' | head -1; }
fid_of()  { printf '%s' "$1" | sed -n 's/.*"folder":{"id":\([0-9]\{1,9\}\).*/\1/p' | head -1; }

# JSON 판독기 (tests/api_folders.sh 와 같다). 표준입력의 JSON 을 `d` 로 놓고 파이썬 식을 평가한다.
jq_py() { python3 -c 'import sys,json
d=json.load(sys.stdin)
print(eval(sys.argv[1]))' "$1" 2>/dev/null; }

# ─── 정리 — 토큰 회수 · 파일 휴지통 · 폴더 삭제 (trap 에서도 돈다) ──
del_file() {
  local id="$1" c
  [ -n "$id" ] || return 0
  for who in qa_employee01 qa_admin01; do
    local csrf=''
    [ "$who" = "qa_admin01" ] && csrf="${ADM_CSRF:-}" || csrf="${EMP_CSRF:-}"
    [ -n "$csrf" ] || continue
    c=$(curl -s -o /dev/null -w '%{http_code}' -X DELETE -b "$JAR_DIR/$who.jar" -A "$UA" \
        -H "X-CSRF-Token: $csrf" "$BASE/api/files/$id")
    if [ "$c" = "200" ] || [ "$c" = "404" ]; then
      printf '  · 파일 %s 휴지통 [%s] (%s)\n' "$id" "$c" "$who"; return 0
    fi
  done
  printf '  ! 파일 %s 를 치우지 못했다 — 손으로 확인이 필요하다\n' "$id"
}
del_folder() {
  local num="$1" c
  [ -n "$num" ] || return 0
  for who in qa_employee01 qa_admin01; do
    local csrf=''
    [ "$who" = "qa_admin01" ] && csrf="${ADM_CSRF:-}" || csrf="${EMP_CSRF:-}"
    [ -n "$csrf" ] || continue
    c=$(curl -s -o /dev/null -w '%{http_code}' -X DELETE -b "$JAR_DIR/$who.jar" -A "$UA" \
        -H "X-CSRF-Token: $csrf" "$BASE/api/folders/$num")
    if [ "$c" != "200" ] && [ "$c" != "404" ]; then
      # 휴지통 파일만 남은 갈래(400 folder_has_trashed) — 확인 꼬리표로 한 번 더 (ARCH 5.16)
      c=$(curl -s -o /dev/null -w '%{http_code}' -X DELETE -b "$JAR_DIR/$who.jar" -A "$UA" \
          -H "X-CSRF-Token: $csrf" "$BASE/api/folders/$num?move_trashed=1")
    fi
    if [ "$c" = "200" ] || [ "$c" = "404" ]; then
      printf '  · 폴더 %s 삭제 [%s] (%s)\n' "$num" "$c" "$who"; return 0
    fi
  done
  printf '  ! 폴더 %s 를 치우지 못했다 — 손으로 확인이 필요하다\n' "$num"
}
revoke_token() {
  local pair="$1" uid tid c
  uid=${pair%%:*}; tid=${pair##*:}
  [ -n "$uid" ] && [ -n "$tid" ] || return 0
  [ -n "${ADM_CSRF:-}" ] || { printf '  ! 토큰 %s 를 회수하지 못했다 — 관리자 세션 없음\n' "$tid"; return 0; }
  c=$(curl -s -o /dev/null -w '%{http_code}' -X DELETE -b "$JAR_DIR/qa_admin01.jar" -A "$UA" \
      -H "X-CSRF-Token: $ADM_CSRF" "$BASE/api/users/$uid/api-tokens/$tid")
  printf '  · 토큰 #%s 회수 [%s]\n' "$tid" "$c"
}
# 이름이 `F59-` 로 시작하는 **살아 있는** 토큰을 그 계정에서 마저 찾아 회수한다.
# MADE_TOKENS 목록에만 기대지 않는 이유: 목록을 세우는 자리가 어긋나면(서브셸 사고처럼)
# 시험이 만든 자격이 서버에 남는다 — 「만든 것은 전부 치운다」 는 목록이 아니라 **상태**로 지킨다.
sweep_tokens() {
  local uid="$1" ids='' id
  [ -n "$uid" ] || return 0
  printf '%s' "$uid" | grep -qE '^[0-9]{1,9}$' || return 0
  if [ "$HAVE_PSQL" -eq 1 ]; then
    ids=$(psqlq "SELECT id FROM api_tokens WHERE user_id = $uid AND name LIKE 'F59-%' AND revoked_at IS NULL")
  fi
  if [ -z "$ids" ] && [ -n "${ADM_CSRF:-}" ]; then
    curl -s -b "$JAR_DIR/qa_admin01.jar" -A "$UA" "$BASE/api/users/$uid/api-tokens" > "$WORK/sweep.json"
    ids=$(jq_py "' '.join(str(t['id']) for t in d.get('tokens',[]) if not t.get('revoked_at') and str(t.get('name','')).startswith('F59-'))" < "$WORK/sweep.json")
  fi
  for id in $ids; do revoke_token "$uid:$id"; done
}
cleanup_artifacts() {
  [ "$CLEANED" -eq 0 ] || return 0
  CLEANED=1
  [ -n "$MADE_TOKENS$MADE_FILES$MADE_FOLDERS${EMP_UID:-}${ADM_UID:-}" ] || return 0
  hr "정리 — 시험이 만든 것 되돌리기 (토큰 회수 · 파일 휴지통 · 폴더 삭제)"
  # 회수는 재인증 창이 필요하다 — 창이 닫혔을 수 있으므로 한 번 더 연다.
  [ -n "${ADM_CSRF:-}" ] && reauth qa_admin01 "$ADM_CSRF" >/dev/null 2>&1
  for t in $MADE_TOKENS; do revoke_token "$t"; done
  # 목록에 없던 것까지 이름으로 훑는다 (두 계정 다 — 자기 자신 대상 발급도 있을 수 있다)
  sweep_tokens "${EMP_UID:-}"
  sweep_tokens "${ADM_UID:-}"
  for f in $MADE_FILES; do del_file "$f"; done
  for n in $(printf '%s\n' $MADE_FOLDERS | tac); do del_folder "$n"; done
}
cleanup() { cleanup_artifacts; rm -rf "$JAR_DIR" "$WORK"; }
trap cleanup EXIT

# ─── 시작 전 관문 ────────────────────────────────────────────────
hr "F-59 개인 프로그램 토큰 — 시험 시작 전 확인"

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
  HAS_TABLE=$(psqlq "SELECT count(*) FROM information_schema.tables WHERE table_name='api_tokens'")
  echo "api_tokens 표 있음: ${HAS_TABLE:-?}"
  if [ "${HAS_TABLE:-0}" != "1" ]; then
    echo "마이그레이션(db/migrations/2026-09-16_api_tokens.sql)이 아직 적용되지 않았다 — 시험을 시작하지 않는다"; exit 3
  fi
else
  echo "psql 을 쓸 수 없다 — 감사 로그·표 확인 항목은 SKIP 된다"
fi

# ─── 공용 토큰 귀속 확인 — 계정 **이름만** 읽는다 ────────────────
SHARED_USER=$(grep -oE '^API_TOKEN_USER=.*' "$ROOT/.env" 2>/dev/null | cut -d= -f2- | tr -d '"'"'"' \r')
SHARED_SET=$(grep -c '^API_TOKEN=..*$' "$ROOT/.env" 2>/dev/null); SHARED_SET=${SHARED_SET:-0}
echo ".env 의 API_TOKEN 값 있음: $SHARED_SET · 귀속 계정: ${SHARED_USER:-없음}"

USE_SHARED=0
case "${SHARED_USER:-}" in
  qa_*) if [ "$SHARED_SET" -eq 1 ]; then USE_SHARED=1; fi ;;
esac
if [ "$USE_SHARED" -eq 1 ]; then
  SHARED_TOKEN=$(grep -oE '^API_TOKEN=.*' "$ROOT/.env" | cut -d= -f2- | tr -d '"'"'"' \r')
  echo "→ 공용 토큰 귀속이 검토 계정이다. (d) 를 실행한다. (값은 출력하지 않는다)"
else
  SHARED_TOKEN=''
  echo "→ 공용 토큰 귀속이 검토 계정(qa_)이 아니다 — (d) 는 **SKIP**."
fi
# 어떤 서버에서도 맞을 수 없는 고정 문자열 — (f) 「틀린 토큰」에 쓴다.
WRONG_TOKEN='wrong-token-0000000000000000000000000000000000'

# ─── 로그인·재인증 헬퍼 (tests/fetch_hosts.sh 와 같다) ───────────
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

reauth() {
  local who="$1" token="$2" pw
  pw=$(sudo -n awk -v u="$who" '$1==u{print $3}' /etc/tbox-v2/seed-accounts.txt 2>/dev/null)
  curl -s -o /dev/null -w '%{http_code}' -b "$JAR_DIR/$who.jar" -A "$UA" \
    -H 'Content-Type: application/json' -H "X-CSRF-Token: $token" \
    -d "{\"password\":\"$pw\"}" "$BASE/api/me/reauth"
}

# 아이디 → users.id.
#   ① psql 을 쓸 수 있으면 **DB 를 직접** 본다. `GET /api/users` 는 정책이 `user.manage` 라
#      재인증 창이 열려 있어야 하는데, (a) 의 첫 항목이 「재인증 **없는** Admin → 403」 이라
#      준비 단계에서 먼저 재인증할 수 없기 때문이다 (2026-09-16 Fable 실측: 준비에서 403 → exit 1).
#      `who` 는 스크립트 안의 고정 문자열이고, 그래도 형태를 한 번 검사한다.
#   ② psql 이 없으면 `GET /api/users` 순회로 폴백한다 — 그 폴백은 재인증 뒤에만 뜻이 있어서
#      **부르는 쪽이 먼저 재인증**하고, 그때는 (a) 의 「재인증 없는 403」 항목을 SKIP 한다.
uid_of() {
  local who="$1" page=1 found=''
  printf '%s' "$who" | grep -qE '^[a-z0-9_]+$' || return 1

  if [ "$HAVE_PSQL" -eq 1 ]; then
    found=$(psqlq "SELECT id FROM users WHERE username = '$who'")
    if [ -n "$found" ]; then printf '%s' "$found"; return 0; fi
    return 1
  fi

  while [ "$page" -le 10 ]; do
    curl -s -b "$JAR_DIR/qa_admin01.jar" -A "$UA" "$BASE/api/users?page=$page" > "$WORK/users.json" || break
    found=$(jq_py "next((str(u['id']) for u in d.get('users',[]) if u['username']=='$who'), '')" < "$WORK/users.json")
    [ -n "$found" ] && { printf '%s' "$found"; return 0; }
    local total per
    total=$(jq_py "d.get('total',0)" < "$WORK/users.json")
    per=$(jq_py "d.get('per_page',50)" < "$WORK/users.json")
    [ -n "$total" ] && [ -n "$per" ] || break
    [ $((page * per)) -ge "$total" ] && break
    page=$((page + 1))
  done
  return 1
}

# 발급 한 번. 결과는 **전역 변수 넷**으로만 돌려준다 — 표준출력으로 돌려주면 부르는 쪽이
# `$(issue_token …)` 로 감싸게 되고, 그러면 이 함수가 **서브셸**에서 돌아 ISSUED_* 도
# MADE_TOKENS 도 부모로 올라오지 않는다(2026-09-16 Fable 실측: 발급은 201 이었는데 시험은
# 「토큰을 발급받지 못했다」 였고, 정리도 되지 않아 살아 있는 토큰 5개가 남았다).
# 그래서 이 함수는 **명령 치환 없이** 부르고, 상태 코드는 ISSUED_CODE 로 읽는다.
ISSUED_TOKEN=''
ISSUED_ID=''
ISSUED_HINT=''
ISSUED_CODE=''
issue_token() {
  local uid="$1" name="$2" reason="$3" body
  ISSUED_TOKEN=''; ISSUED_ID=''; ISSUED_HINT=''; ISSUED_CODE=''
  body=$(python3 -c 'import json,sys; print(json.dumps({"name":sys.argv[1],"reason_text":sys.argv[2]}))' "$name" "$reason")
  ISSUED_CODE=$(curl -s -o "$WORK/issue.json" -w '%{http_code}' -X POST -b "$JAR_DIR/qa_admin01.jar" -A "$UA" \
         -H "X-CSRF-Token: $ADM_CSRF" -H 'Content-Type: application/json' \
         -d "$body" "$BASE/api/users/$uid/api-tokens")
  if [ "$ISSUED_CODE" = "201" ]; then
    ISSUED_TOKEN=$(jq_py "d.get('token','')" < "$WORK/issue.json")
    ISSUED_ID=$(jq_py "str(d.get('id',''))" < "$WORK/issue.json")
    ISSUED_HINT=$(jq_py "d.get('hint','')" < "$WORK/issue.json")
    [ -n "$ISSUED_ID" ] && MADE_TOKENS="$MADE_TOKENS $uid:$ISSUED_ID"
  fi
  : > "$WORK/issue.json"      # 토큰이 든 응답 파일은 곧바로 비운다
}

# ─── 준비: 세션 셋 ───────────────────────────────────────────────
hr "준비 — 검토 계정 세션 (qa_admin01 · qa_employee01 · qa_manager01)"
ADM_CSRF=$(login qa_admin01)     || { echo "qa_admin01 로그인 실패 — 시험을 시작할 수 없다"; exit 1; }
EMP_CSRF=$(login qa_employee01)  || { echo "qa_employee01 로그인 실패 — 시험을 시작할 수 없다"; exit 1; }
MGR_CSRF=$(login qa_manager01)   || { echo "qa_manager01 로그인 실패 — (a) 권한 항목은 SKIP 된다"; MGR_CSRF=''; }
note "csrf 토큰 확보"

# 폴백(psql 없음)은 `GET /api/users` 를 쓰므로 재인증 창이 먼저 열려 있어야 한다.
# 그 창이 열리면 (a) 의 「재인증 없는 Admin → 403」 은 더 이상 확인할 수 없어 SKIP 한다.
UID_VIA_API=0
if [ "$HAVE_PSQL" -eq 0 ]; then
  UID_VIA_API=1
  note "psql 을 쓸 수 없다 — 계정 id 를 GET /api/users 로 찾는다 (정책 user.manage → 재인증 필요)"
  note "준비 재인증: qa_admin01 → $(reauth qa_admin01 "$ADM_CSRF")"
  note "→ 재인증 창이 열렸으므로 (a) 「재인증 없는 Admin → 403」 두 항목은 SKIP 한다"
fi

EMP_UID=$(uid_of qa_employee01) || EMP_UID=''
ADM_UID=$(uid_of qa_admin01)    || ADM_UID=''
note "qa_employee01 uid=${EMP_UID:-?} · qa_admin01 uid=${ADM_UID:-?}"
[ -n "$EMP_UID" ] || { echo "대상 계정 id 를 찾지 못했다 — 시험을 시작할 수 없다"; exit 1; }

T0=$(psqlq "SELECT now();")

# ─── (a) 발급 ────────────────────────────────────────────────────
hr "(a) POST /api/users/{uid}/api-tokens — 발급"

if [ "$UID_VIA_API" -eq 1 ]; then
  skip "(a) 재인증 없는 Admin → 403" "psql 이 없어 계정 id 를 GET /api/users 로 찾느라 준비 단계에서 이미 재인증했다"
else
  R=$(curl -s -w '\n%{http_code}' -X POST -b "$JAR_DIR/qa_admin01.jar" -A "$UA" \
      -H "X-CSRF-Token: $ADM_CSRF" -H 'Content-Type: application/json' \
      -d '{"name":"F59-nopreauth","reason_text":"재인증 관문 확인"}' "$BASE/api/users/$EMP_UID/api-tokens")
  expect "재인증 없는 Admin → 403" "403" "$(code_of "$R")"
  expect "본문에 next:reauth" "1" "$(body_of "$R" | grep -c '"next":"reauth"')"
fi

note "재인증: qa_admin01 → $(reauth qa_admin01 "$ADM_CSRF")"

issue_token "$EMP_UID" "F59-pc-$TAG" "T-71 시험 — 개인 토큰 발급"
CODE="$ISSUED_CODE"
expect "발급 상태" "201" "$CODE"
TOK="$ISSUED_TOKEN"; TID="$ISSUED_ID"; THINT="$ISSUED_HINT"
if [ -z "$TOK" ] || [ -z "$TID" ]; then
  echo "  ! 토큰을 발급받지 못했다 — 이후 항목 대부분이 SKIP 된다"
fi
expect "토큰 길이 48" "48" "${#TOK}"
expect "토큰 글자 구성 [A-Za-z0-9]" "1" "$(printf '%s' "$TOK" | grep -cE '^[A-Za-z0-9]{48}$')"
if [ -n "$TOK" ] && [ "$THINT" = "${TOK:0:6}" ]; then
  expect "hint == 토큰 앞 6자 (값은 출력하지 않는다)" "같음" "같음"
else
  expect "hint == 토큰 앞 6자 (값은 출력하지 않는다)" "같음" "다름"
fi

R=$(curl -s -w '\n%{http_code}' -b "$JAR_DIR/qa_admin01.jar" -A "$UA" "$BASE/api/users/$EMP_UID/api-tokens")
CODE=$(code_of "$R"); printf '%s' "$(body_of "$R")" > "$WORK/list.json"
expect "목록 상태" "200" "$CODE"
expect "목록에 방금 발급한 행" "1" "$(jq_py "sum(1 for t in d['tokens'] if str(t['id'])=='$TID')" < "$WORK/list.json")"
expect "목록 행의 키" "created_at,hint,id,last_used_at,name,revoked_at" \
  "$(jq_py "','.join(sorted(d['tokens'][0].keys())) if d['tokens'] else '(빈 목록)'" < "$WORK/list.json")"
expect "목록에 token 키 없음" "0" "$(grep -c '"token"' "$WORK/list.json")"

R=$(curl -s -w '\n%{http_code}' -X POST -b "$JAR_DIR/qa_admin01.jar" -A "$UA" \
    -H "X-CSRF-Token: $ADM_CSRF" -H 'Content-Type: application/json' \
    -d '{"name":"F59-noreason"}' "$BASE/api/users/$EMP_UID/api-tokens")
expect "사유 없음 → 400" "400" "$(code_of "$R")"
expect "사유 없음 문구" '{"error":"잘못된 요청입니다"}' "$(body_of "$R")"

LONG=$(python3 -c 'print("가"*51)')
R=$(curl -s -w '\n%{http_code}' -X POST -b "$JAR_DIR/qa_admin01.jar" -A "$UA" \
    -H "X-CSRF-Token: $ADM_CSRF" -H 'Content-Type: application/json' \
    -d "$(python3 -c 'import json,sys; print(json.dumps({"name":sys.argv[1],"reason_text":"길이 시험"}))' "$LONG")" \
    "$BASE/api/users/$EMP_UID/api-tokens")
expect "이름 51자 → 400" "400" "$(code_of "$R")"

R=$(curl -s -w '\n%{http_code}' -X POST -b "$JAR_DIR/qa_admin01.jar" -A "$UA" \
    -H "X-CSRF-Token: $ADM_CSRF" -H 'Content-Type: application/json' \
    -d '{"name":"F59-x","reason_text":"모르는 키","user_id":1}' "$BASE/api/users/$EMP_UID/api-tokens")
expect "모르는 키 → 400 (R-07)" "400" "$(code_of "$R")"

if [ -z "$MGR_CSRF" ]; then
  skip "(a) Manager 403" "qa_manager01 세션 없음"
else
  R=$(curl -s -w '\n%{http_code}' -X POST -b "$JAR_DIR/qa_manager01.jar" -A "$UA" \
      -H "X-CSRF-Token: $MGR_CSRF" -H 'Content-Type: application/json' \
      -d '{"name":"F59-mgr","reason_text":"권한 시험"}' "$BASE/api/users/$EMP_UID/api-tokens")
  expect "Manager 세션 → 403" "403" "$(code_of "$R")"
  R=$(curl -s -w '\n%{http_code}' -b "$JAR_DIR/qa_manager01.jar" -A "$UA" "$BASE/api/users/$EMP_UID/api-tokens")
  expect "Manager 목록 → 403" "403" "$(code_of "$R")"
fi

# 살아 있는 토큰 5개를 채우고 6번째를 본다 (하나는 위에서 이미 발급했다)
LIMIT_CODE=''
for i in 2 3 4 5 6; do
  issue_token "$EMP_UID" "F59-lim$i-$TAG" "T-71 상한 시험"
  LIMIT_CODE="$ISSUED_CODE"
  [ "$i" -lt 6 ] && note "상한 채우기 $i 번째 [$LIMIT_CODE]"
done
expect "살아 있는 5개 뒤 6번째 → 400 (limit_exceeded)" "400" "$LIMIT_CODE"

if [ "$HAVE_PSQL" -eq 0 ]; then
  skip "(a) 감사·해시·원문 확인" "psql 을 쓸 수 없다"
else
  expect "감사 api_token.issue allow" "t" \
    "$(psqlq "SELECT count(*) > 0 FROM audit_logs WHERE action='api_token.issue' AND result='allow' AND actor_name='qa_admin01' AND happened_at > '$T0'::timestamptz")"
  expect "감사 detail 에 token_id·token_name·reason_text" "t" \
    "$(psqlq "SELECT count(*) > 0 FROM audit_logs WHERE action='api_token.issue' AND result='allow' AND happened_at > '$T0'::timestamptz AND detail ? 'token_id' AND detail ? 'token_name' AND detail ? 'reason_text'")"
  expect "감사 detail 에 hint·token 원문 키 없음" "t" \
    "$(psqlq "SELECT count(*) = 0 FROM audit_logs WHERE action='api_token.issue' AND happened_at > '$T0'::timestamptz AND (detail ? 'hint' OR detail ? 'token' OR detail ? 'token_hash')")"
  expect "감사 api_token.issue deny limit_exceeded" "t" \
    "$(psqlq "SELECT count(*) > 0 FROM audit_logs WHERE action='api_token.issue' AND result='deny' AND reason='limit_exceeded' AND happened_at > '$T0'::timestamptz")"

  # ★ DB 어디에도 토큰 원문이 없다 — 토큰은 **패턴 파일**로만 넘긴다(명령줄 노출 금지)
  printf '%s' "$TOK" > "$WORK/pat.txt"
  psqlq "SELECT coalesce(summary,'') || ' ' || coalesce(detail::text,'') FROM audit_logs WHERE happened_at > '$T0'::timestamptz" > "$WORK/dump.txt"
  psqlq "SELECT id || ' ' || name || ' ' || token_hash || ' ' || hint || ' ' || coalesce(reason_text,'') FROM api_tokens" >> "$WORK/dump.txt"
  expect "audit_logs·api_tokens 에 토큰 원문 0건" "0" "$(grep -c -F -f "$WORK/pat.txt" "$WORK/dump.txt")"

  CALC=$(printf '%s' "$TOK" | sha256sum | cut -d' ' -f1)
  DBHASH=$(psqlq "SELECT token_hash FROM api_tokens WHERE id = $TID")
  if [ -n "$CALC" ] && [ "$CALC" = "$DBHASH" ]; then
    expect "api_tokens.token_hash == 토큰의 sha256 (값은 출력하지 않는다)" "같음" "같음"
  else
    expect "api_tokens.token_hash == 토큰의 sha256 (값은 출력하지 않는다)" "같음" "다름"
  fi
fi

# ─── (b) 개인 토큰 사용 ─────────────────────────────────────────
hr "(b) 개인 토큰으로 whoami · 검색 · 업로드 · 폴더"
T1=$(psqlq "SELECT now();")
if [ -z "$TOK" ]; then
  skip "(b)" "토큰이 없다"
else
  R=$(curl -s -w '\n%{http_code}' -A "$UA" -H "X-API-Token: $TOK" "$BASE/api/whoami")
  echo "  whoami → [$(code_of "$R")] $(body_of "$R")"
  expect "whoami 상태" "200" "$(code_of "$R")"
  expect "whoami username" "qa_employee01" \
    "$(printf '%s' "$(body_of "$R")" | sed -n 's/.*"username":"\([^"]*\)".*/\1/p')"
  expect "whoami 키 넷 (T-68 불변)" "role,team_id,upload_levels,username" \
    "$(printf '%s' "$(body_of "$R")" | jq_py "','.join(sorted(d.keys()))")"

  R=$(curl -s -w '\n%{http_code}' -A "$UA" -H "X-API-Token: $TOK" "$BASE/api/files?limit=5")
  expect "GET /api/files 상태" "200" "$(code_of "$R")"
  expect "GET /api/files 배열" "1" "$(printf '%s' "$(body_of "$R")" | grep -c '^\[')"

  printf 'F-59 시험용 파일입니다. %s\n' "$TAG" > "$WORK/F59-b-$TAG.txt"
  R=$(curl -s -w '\n%{http_code}' -X POST -A "$UA" -H "X-API-Token: $TOK" \
      -F "file=@$WORK/F59-b-$TAG.txt" -F 'level=internal' "$BASE/api/upload")
  echo "  업로드 → [$(code_of "$R")] $(body_of "$R")"
  expect "POST /api/upload 상태" "201" "$(code_of "$R")"
  B_FILE=$(uuid_of "$(body_of "$R")")
  [ -n "$B_FILE" ] && MADE_FILES="$MADE_FILES $B_FILE"

  R=$(curl -s -w '\n%{http_code}' -A "$UA" -H "X-API-Token: $TOK" "$BASE/api/folders")
  expect "GET /api/folders 상태" "200" "$(code_of "$R")"

  if [ "$HAVE_PSQL" -eq 0 ]; then
    skip "(b) 감사·last_used_at" "psql 을 쓸 수 없다"
  else
    expect "감사 file.upload allow 에 auth=api_token" "t" \
      "$(psqlq "SELECT count(*) > 0 FROM audit_logs WHERE action='file.upload' AND result='allow' AND actor_name='qa_employee01' AND happened_at > '$T1'::timestamptz AND detail->>'auth' = 'api_token'")"
    expect "감사 token_kind=personal" "t" \
      "$(psqlq "SELECT count(*) > 0 FROM audit_logs WHERE happened_at > '$T1'::timestamptz AND detail->>'token_kind' = 'personal'")"
    expect "감사 token_id = 발급한 토큰 번호" "t" \
      "$(psqlq "SELECT count(*) > 0 FROM audit_logs WHERE happened_at > '$T1'::timestamptz AND detail->>'token_id' = '$TID'")"
    expect "api_tokens.last_used_at 갱신됨" "t" \
      "$(psqlq "SELECT last_used_at IS NOT NULL FROM api_tokens WHERE id = $TID")"
  fi
fi

# ─── (c) 개인 토큰으로 삭제 ─────────────────────────────────────
hr "(c) 개인 토큰으로 DELETE /api/files/{id} · DELETE /api/folders/{num}"
T2=$(psqlq "SELECT now();")
if [ -z "$TOK" ]; then
  skip "(c)" "토큰이 없다"
else
  # 지울 파일 하나 (토큰으로 올린 것 = 자기 파일)
  printf 'F-59 삭제 시험 파일입니다. %s\n' "$TAG" > "$WORK/F59-c-$TAG.txt"
  R=$(curl -s -w '\n%{http_code}' -X POST -A "$UA" -H "X-API-Token: $TOK" \
      -F "file=@$WORK/F59-c-$TAG.txt" -F 'level=internal' "$BASE/api/upload")
  C_FILE=$(uuid_of "$(body_of "$R")")
  if [ -z "$C_FILE" ]; then
    skip "(c) 파일 삭제" "업로드가 id 를 주지 않았다"
  else
    R=$(curl -s -w '\n%{http_code}' -X DELETE -A "$UA" -H "X-API-Token: $TOK" "$BASE/api/files/$C_FILE")
    echo "  파일 삭제 → [$(code_of "$R")] $(body_of "$R")"
    expect "파일 삭제 상태" "200" "$(code_of "$R")"
    expect "파일 삭제 본문" '{"ok":true}' "$(body_of "$R")"
    if [ "$HAVE_PSQL" -eq 1 ]; then
      # API 가 주는 36자 UUID 는 `files.public_id` 다 — `files.id` 는 bigint 라
      # 그 열로 견주면 질의가 오류로 끝나 빈 값이 돌아온다(2026-09-16 실측).
      expect "deleted_at 설정 (30일 휴지통)" "t" \
        "$(psqlq "SELECT deleted_at IS NOT NULL FROM files WHERE public_id = '$C_FILE'")"
    fi
  fi

  # 빈 폴더 하나 → 삭제
  R=$(curl -s -w '\n%{http_code}' -X POST -A "$UA" -H "X-API-Token: $TOK" \
      -H 'Content-Type: application/json' \
      -d "{\"name\":\"F59-empty-$TAG\",\"access_level\":\"internal\"}" "$BASE/api/folders")
  C_EMPTY=$(fid_of "$(body_of "$R")")
  if [ -z "$C_EMPTY" ]; then
    skip "(c) 빈 폴더 삭제" "폴더를 만들지 못했다"
  else
    MADE_FOLDERS="$MADE_FOLDERS $C_EMPTY"
    R=$(curl -s -w '\n%{http_code}' -X DELETE -A "$UA" -H "X-API-Token: $TOK" "$BASE/api/folders/$C_EMPTY")
    echo "  빈 폴더 삭제 → [$(code_of "$R")] $(body_of "$R")"
    expect "빈 폴더 삭제 상태" "200" "$(code_of "$R")"
  fi

  # 파일이 든 폴더 → 400
  R=$(curl -s -w '\n%{http_code}' -X POST -A "$UA" -H "X-API-Token: $TOK" \
      -H 'Content-Type: application/json' \
      -d "{\"name\":\"F59-full-$TAG\",\"access_level\":\"internal\"}" "$BASE/api/folders")
  C_FULL=$(fid_of "$(body_of "$R")")
  if [ -z "$C_FULL" ] || [ -z "${B_FILE:-}" ]; then
    skip "(c) 파일 든 폴더 400" "폴더 또는 파일이 없다"
  else
    MADE_FOLDERS="$MADE_FOLDERS $C_FULL"
    curl -s -o /dev/null -X PATCH -A "$UA" -H "X-API-Token: $TOK" -H 'Content-Type: application/json' \
      -d "{\"folder_id\":$C_FULL}" "$BASE/api/files/$B_FILE"
    R=$(curl -s -w '\n%{http_code}' -X DELETE -A "$UA" -H "X-API-Token: $TOK" "$BASE/api/folders/$C_FULL")
    echo "  파일 든 폴더 삭제 → [$(code_of "$R")] $(body_of "$R")"
    expect "파일 든 폴더 → 400" "400" "$(code_of "$R")"
    # 폴더 삭제 400 은 F-41 이후 `code` 키를 함께 준다(기존 동작) — 본문 전체가 아니라
    # 사람이 보는 문구(`error`)만 견준다.
    expect "파일 든 폴더 문구" '파일이 있는 폴더는 지울 수 없습니다' \
      "$(printf '%s' "$(body_of "$R")" | jq_py "d['error']")"
  fi

  # 남의 파일(qa_admin01 이 올린 것) → 403
  printf 'F-59 관리자 파일입니다. %s\n' "$TAG" > "$WORK/F59-adm-$TAG.txt"
  R=$(curl -s -w '\n%{http_code}' -X POST -b "$JAR_DIR/qa_admin01.jar" -A "$UA" \
      -H "X-CSRF-Token: $ADM_CSRF" -F "file=@$WORK/F59-adm-$TAG.txt" -F 'access_level=internal' \
      "$BASE/api/files")
  A_FILE=$(uuid_of "$(body_of "$R")")
  if [ -z "$A_FILE" ]; then
    skip "(c) 남의 파일 403" "관리자 업로드가 id 를 주지 않았다 [$(code_of "$R")]"
  else
    MADE_FILES="$MADE_FILES $A_FILE"
    R=$(curl -s -w '\n%{http_code}' -X DELETE -A "$UA" -H "X-API-Token: $TOK" "$BASE/api/files/$A_FILE")
    echo "  남의 파일 삭제 → [$(code_of "$R")] $(body_of "$R")"
    expect "남의 파일 → 403" "403" "$(code_of "$R")"
    expect "남의 파일 문구" '{"error":"권한이 없습니다"}' "$(body_of "$R")"
  fi

  R=$(curl -s -w '\n%{http_code}' -X DELETE -A "$UA" -H "X-API-Token: $TOK" \
      "$BASE/api/files/00000000-0000-4000-8000-000000000000")
  expect "없는 파일 id → 404" "404" "$(code_of "$R")"
  # 폴더 번호는 **순차 정수**라 「없음(404)」과 「권한 없음(403)」이 갈리면 그 차이가 곧
  # 개수 열거가 된다 → folders_delete.php 는 둘 다 403 동일 문구다(핸들러 [1], 파일 UUID 와 다름).
  R=$(curl -s -w '\n%{http_code}' -X DELETE -A "$UA" -H "X-API-Token: $TOK" "$BASE/api/folders/999999")
  expect "없는 폴더 번호 → 403 (동일 문구)" "403" "$(code_of "$R")"

  if [ "$HAVE_PSQL" -eq 0 ]; then
    skip "(c) 감사" "psql 을 쓸 수 없다"
  else
    expect "감사 file.delete allow 에 auth=api_token·token_id" "t" \
      "$(psqlq "SELECT count(*) > 0 FROM audit_logs WHERE action='file.delete' AND result='allow' AND happened_at > '$T2'::timestamptz AND detail->>'auth'='api_token' AND detail->>'token_id' = '$TID'")"
    expect "감사 folder.delete allow 에 auth=api_token·token_id" "t" \
      "$(psqlq "SELECT count(*) > 0 FROM audit_logs WHERE action='folder.delete' AND result='allow' AND happened_at > '$T2'::timestamptz AND detail->>'auth'='api_token' AND detail->>'token_id' = '$TID'")"
  fi
fi

# ─── (d) 공용 토큰 게이트 ───────────────────────────────────────
hr "(d) 공용 토큰은 삭제 창구를 쓸 수 없다"
T3=$(psqlq "SELECT now();")
if [ "$USE_SHARED" -eq 0 ]; then
  skip "(d)" "공용 토큰 귀속이 검토 계정(qa_)이 아님 — 값은 읽지 않았다"
else
  printf 'F-59 공용 토큰 시험 파일입니다. %s\n' "$TAG" > "$WORK/F59-d-$TAG.txt"
  R=$(curl -s -w '\n%{http_code}' -X POST -A "$UA" -H "X-API-Token: $SHARED_TOKEN" \
      -F "file=@$WORK/F59-d-$TAG.txt" -F 'level=internal' "$BASE/api/upload")
  D_FILE=$(uuid_of "$(body_of "$R")")
  [ -n "$D_FILE" ] && MADE_FILES="$MADE_FILES $D_FILE"

  if [ -z "$D_FILE" ]; then
    skip "(d) 삭제 403" "공용 토큰 업로드가 id 를 주지 않았다 [$(code_of "$R")]"
  else
    R=$(curl -s -w '\n%{http_code}' -X DELETE -A "$UA" -H "X-API-Token: $SHARED_TOKEN" "$BASE/api/files/$D_FILE")
    echo "  공용 토큰 파일 삭제 → [$(code_of "$R")] $(body_of "$R")"
    expect "공용 토큰 DELETE /api/files → 403" "403" "$(code_of "$R")"
    expect "공용 토큰 DELETE 문구" '{"error":"이 창구는 개인 토큰으로만 쓸 수 있습니다"}' "$(body_of "$R")"
  fi

  R=$(curl -s -w '\n%{http_code}' -X DELETE -A "$UA" -H "X-API-Token: $SHARED_TOKEN" "$BASE/api/folders/999999")
  expect "공용 토큰 DELETE /api/folders → 403" "403" "$(code_of "$R")"

  R=$(curl -s -w '\n%{http_code}' -A "$UA" -H "X-API-Token: $SHARED_TOKEN" "$BASE/api/files?limit=5")
  expect "공용 토큰 GET /api/files → 200 (불변)" "200" "$(code_of "$R")"

  if [ "$HAVE_PSQL" -eq 0 ]; then
    skip "(d) 감사" "psql 을 쓸 수 없다"
  else
    expect "감사 auth.token deny shared_token_not_permitted" "t" \
      "$(psqlq "SELECT count(*) > 0 FROM audit_logs WHERE action='auth.token' AND result='deny' AND reason='shared_token_not_permitted' AND happened_at > '$T3'::timestamptz")"
    expect "공용 토큰 행에 token_kind=shared" "t" \
      "$(psqlq "SELECT count(*) > 0 FROM audit_logs WHERE happened_at > '$T3'::timestamptz AND detail->>'token_kind' = 'shared'")"
    expect "공용 토큰 행에 token_id 없음" "t" \
      "$(psqlq "SELECT count(*) = 0 FROM audit_logs WHERE happened_at > '$T3'::timestamptz AND detail->>'token_kind' = 'shared' AND detail ? 'token_id'")"
  fi
fi

# ─── (e) 회수 ───────────────────────────────────────────────────
hr "(e) DELETE /api/users/{uid}/api-tokens/{tid} — 회수"
T4=$(psqlq "SELECT now();")
if [ -z "$TID" ]; then
  skip "(e)" "회수할 토큰이 없다"
else
  note "재인증: qa_admin01 → $(reauth qa_admin01 "$ADM_CSRF")"
  R=$(curl -s -w '\n%{http_code}' -X DELETE -b "$JAR_DIR/qa_admin01.jar" -A "$UA" \
      -H "X-CSRF-Token: $ADM_CSRF" "$BASE/api/users/$EMP_UID/api-tokens/$TID")
  echo "  회수 → [$(code_of "$R")] $(body_of "$R")"
  expect "회수 상태" "200" "$(code_of "$R")"
  expect "회수 본문" '{"ok":true}' "$(body_of "$R")"

  R=$(curl -s -w '\n%{http_code}' -A "$UA" -H "X-API-Token: $TOK" "$BASE/api/whoami")
  echo "  회수된 토큰으로 whoami → [$(code_of "$R")] $(body_of "$R")"
  expect "회수된 토큰 → 401" "401" "$(code_of "$R")"
  expect "회수된 토큰 문구 (회수 사실을 알리지 않는다)" '{"error":"API 토큰이 올바르지 않습니다"}' "$(body_of "$R")"

  R=$(curl -s -w '\n%{http_code}' -X DELETE -b "$JAR_DIR/qa_admin01.jar" -A "$UA" \
      -H "X-CSRF-Token: $ADM_CSRF" "$BASE/api/users/$EMP_UID/api-tokens/$TID")
  expect "다시 회수 → 400" "400" "$(code_of "$R")"

  if [ -z "$ADM_UID" ]; then
    skip "(e) 다른 계정의 tid 404" "qa_admin01 uid 를 찾지 못했다"
  else
    R=$(curl -s -w '\n%{http_code}' -X DELETE -b "$JAR_DIR/qa_admin01.jar" -A "$UA" \
        -H "X-CSRF-Token: $ADM_CSRF" "$BASE/api/users/$ADM_UID/api-tokens/$TID")
    echo "  다른 계정의 tid → [$(code_of "$R")] $(body_of "$R")"
    expect "다른 계정의 tid → 404" "404" "$(code_of "$R")"
  fi

  R=$(curl -s -w '\n%{http_code}' -b "$JAR_DIR/qa_admin01.jar" -A "$UA" "$BASE/api/users/$EMP_UID/api-tokens")
  printf '%s' "$(body_of "$R")" > "$WORK/list2.json"
  expect "목록의 그 행에 revoked_at 채워짐" "1" \
    "$(jq_py "sum(1 for t in d['tokens'] if str(t['id'])=='$TID' and t['revoked_at'])" < "$WORK/list2.json")"

  if [ "$HAVE_PSQL" -eq 0 ]; then
    skip "(e) 감사" "psql 을 쓸 수 없다"
  else
    expect "감사 api_token.revoke allow" "t" \
      "$(psqlq "SELECT count(*) > 0 FROM audit_logs WHERE action='api_token.revoke' AND result='allow' AND happened_at > '$T4'::timestamptz")"
    expect "감사 api_token.revoke deny not_found" "t" \
      "$(psqlq "SELECT count(*) > 0 FROM audit_logs WHERE action='api_token.revoke' AND result='deny' AND reason='not_found' AND happened_at > '$T4'::timestamptz")"
    expect "회수 행에 revoked_by 채워짐" "t" \
      "$(psqlq "SELECT revoked_by IS NOT NULL FROM api_tokens WHERE id = $TID")"
  fi
fi

# ─── (f) 계정 비활성 (미검증) + 401 세 갈래 ─────────────────────
hr "(f) 계정 비활성은 미검증 · 401 세 갈래 실측"
skip "(f) 계정 비활성으로 토큰이 막히는지" "시험이 계정을 만들거나 비활성화하지 않는다(운영 데이터 불가침) — requireApiToken 이 매 요청 4조건을 본다는 **코드 읽기**로 대체하며 미검증으로 남긴다"

R=$(curl -s -w '\n%{http_code}' -A "$UA" "$BASE/api/whoami")
expect "헤더 없음 → 401" "401" "$(code_of "$R")"
expect "헤더 없음 문구" '{"error":"X-API-Token 헤더가 없습니다"}' "$(body_of "$R")"
R=$(curl -s -w '\n%{http_code}' -A "$UA" -H "X-API-Token: $WRONG_TOKEN" "$BASE/api/whoami")
expect "틀린 토큰 → 401" "401" "$(code_of "$R")"
expect "틀린 토큰 문구" '{"error":"API 토큰이 올바르지 않습니다"}' "$(body_of "$R")"
if [ -n "$TOK" ] && [ -n "$TID" ]; then
  R=$(curl -s -w '\n%{http_code}' -A "$UA" -H "X-API-Token: $TOK" "$BASE/api/files?limit=1")
  expect "회수된 토큰으로 검색 → 401" "401" "$(code_of "$R")"
else
  skip "(f) 회수된 토큰 401" "토큰이 없다"
fi

# ─── (g) 닫힘 · 회귀 ────────────────────────────────────────────
hr "(g) 닫힘 — 토큰은 그 열 밖에서 아무것도 열지 못한다"
if [ -z "$TOK" ]; then
  skip "(g) 토큰 닫힘" "토큰이 없다"
else
  # 회수됐어도 **라우트 집합 밖**이라는 사실은 그대로 확인된다(세션 경로 401).
  R=$(curl -s -w '\n%{http_code}' -X POST -A "$UA" -H "X-API-Token: $TOK" \
      -H 'Content-Type: application/json' -d '{"name":"F59-x","reason_text":"닫힘 시험"}' \
      "$BASE/api/users/$EMP_UID/api-tokens")
  echo "  토큰으로 발급 창구 → [$(code_of "$R")] $(body_of "$R")"
  expect "개인 토큰으로 POST …/api-tokens → 401 (라우트 집합 밖)" "401" "$(code_of "$R")"
  expect "그 문구는 세션 경로의 것" '{"error":"로그인이 필요합니다"}' "$(body_of "$R")"
fi

if [ -z "${B_FILE:-}" ]; then
  skip "(g) PATCH 허용 키·세션 CSRF" "시험 파일이 없다"
else
  if [ "$USE_SHARED" -eq 1 ]; then
    R=$(curl -s -w '\n%{http_code}' -X PATCH -A "$UA" -H "X-API-Token: $SHARED_TOKEN" \
        -H 'Content-Type: application/json' -d '{"access_level":"public"}' "$BASE/api/files/$B_FILE")
    echo "  토큰 PATCH {access_level} → [$(code_of "$R")] $(body_of "$R")"
    expect "토큰 PATCH {access_level} → 400 (불변)" "400" "$(code_of "$R")"
  else
    skip "(g) 토큰 PATCH {access_level}" "쓸 수 있는 토큰이 없다(개인 토큰은 회수됨·공용은 검토 계정 아님)"
  fi

  R=$(curl -s -w '\n%{http_code}' -X DELETE -b "$JAR_DIR/qa_employee01.jar" -A "$UA" "$BASE/api/files/$B_FILE")
  echo "  세션 + CSRF 없음 → [$(code_of "$R")] $(body_of "$R")"
  expect "세션 DELETE, CSRF 없음 → 403 (웹 불변)" "403" "$(code_of "$R")"
fi

if [ "$HAVE_PSQL" -eq 0 ]; then
  skip "(g) 표 권한·AL-20" "psql 을 쓸 수 없다"
else
  GRANTS=$(psqlq "SELECT array_to_string(relacl, ' ') FROM pg_class WHERE relname = 'api_tokens'")
  echo "  api_tokens 권한: ${GRANTS:-(없음)}"
  expect "tbox_app 에 arw (DELETE 없음)" "1" "$(printf '%s' "$GRANTS" | grep -c 'tbox_app=arw/')"

  # 운영 상태 파일을 건드리지 않도록 --state 를 따로 주고 --dry-run 으로 센다.
  sudo -n php "$ROOT/scripts/alerts.php" --rule AL-20 --dry-run --state "$WORK/alerts-state.json" \
    > "$WORK/al20.out" 2>&1
  tail -3 "$WORK/al20.out" | sed 's/^/  /'
  expect "AL-20 대상 1명 이상" "1" \
    "$(grep -cE 'AL-20 대상 [1-9][0-9]*명' "$WORK/al20.out")"
  # `grep -c` 는 0건이면 종료 코드가 1 이다 — `|| echo 0` 을 붙이면 「0」이 두 번 찍혀
  # 실제값이 `0\n0` 이 된다(2026-09-16 실측). 숫자는 grep 이 이미 내주므로 그대로 쓴다.
  expect "AL-20 근거에 토큰 원문 0건" "0" "$(grep -c -F -f "$WORK/pat.txt" "$WORK/al20.out" 2>/dev/null)"
fi

# ─── 정리 (트랩보다 먼저 — 결과를 눈으로 보려고) ────────────────
cleanup_artifacts

# ─── (g) 이어서 — 린트 · php -l · bash -n ───────────────────────
hr "(g) 회귀 — 린트 · php -l · bash -n"
bash "$ROOT/scripts/security_lint.sh" >"$WORK/lint.out" 2>&1
expect "scripts/security_lint.sh" "0" "$?"
tail -1 "$WORK/lint.out" | sed 's/^/  /'

PHPBAD=0
for f in public/index.php src/Auth.php src/Http.php src/Audit.php \
         src/handlers/api_tokens_list.php src/handlers/api_tokens_issue.php \
         src/handlers/api_tokens_revoke.php scripts/alerts.php; do
  php -l "$ROOT/$f" >/dev/null 2>&1 || { echo "  ✖ php -l 실패: $f"; PHPBAD=1; }
done
expect "바뀐 PHP php -l" "0" "$PHPBAD"

SHBAD=0
bash -n "$ROOT/tests/api_tokens.sh"  2>/dev/null || SHBAD=1
bash -n "$ROOT/tests/api_folders.sh" 2>/dev/null || SHBAD=1
bash -n "$ROOT/tests/api_whoami.sh"  2>/dev/null || SHBAD=1
expect "bash -n (시험 스크립트 셋)" "0" "$SHBAD"

# ─── 마무리 ─────────────────────────────────────────────────────
hr "결과"
printf '통과 %d · 실패 %d · 건너뜀/미검증 %d\n' "$PASS" "$FAIL" "$SKIP"
if [ "$USE_SHARED" -eq 0 ]; then
  echo "(d) 는 공용 토큰 귀속이 검토 계정(qa_)이 아니라 실행하지 않았다 — **미검증**이다."
fi
echo "(f) 계정 비활성 갈래는 코드 읽기로 대체했다 — **미검증**이다."
[ "$FAIL" -eq 0 ] || exit 1
exit 0
