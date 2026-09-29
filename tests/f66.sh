#!/usr/bin/env bash
# tests/f66.sh — F-66 「프로그램 토큰 본인 발급 · 범위 · 만료 180일」 수락 기준 실행
#                (PRD 12.2 T-78, F-66, ARCH 4.7 · 4.6 · 6.2-5 · 7.4 · 7.5,
#                 SECURITY R-97 · R-91 · R-48 · R-45, docs/f66-self-tokens-directive.md)
#
#   (a) 발급   claude_employee01 `POST /api/me/api-tokens {name,purpose,scope:"read+upload",password,otp}`
#       → 201 · `token` 48자 `[A-Za-z0-9]` · `hint` = 앞 6자 · `scope` 그대로 ·
#       `expires_at` 이 발급 시각 + 180일(±1분) · 목록에 그 행(`token` 키 없음 ·
#       `expires_soon:false`) · 감사 `api_token.issue allow` actor = target = claude_employee01,
#       detail `token_scope`·`otp_verified`·`reason_text` · **비밀번호·코드 문자열 0**
#   (b) 거부   틀린 비밀번호 400 `bad_credentials` + `failed_count` 증가 · 맞는 비밀번호 +
#       틀린 코드 400 `bad_code` · 같은 코드 재사용 `code_reused` · 역할 밖/모르는 범위
#       400 `bad_request` · 모르는 키 400 · 6번째 400 `limit_exceeded`(문장 대조) ·
#       2단계 꺼진 계정(있으면) 403 `mfa_not_enabled` 이고 `failed_count` 불변 ·
#       각 실패 뒤 재로그인(잠금 방지)
#   (c) 범위 가름  세 범위 토큰 × 토큰 창구 열 = 30 요청. 범위 밖은 **403**
#       「이 토큰의 범위에 없는 창구입니다 …」 + `auth.token deny scope_not_permitted`
#       (detail `token_id`·`token_scope`) · 범위 안이라도 `Authz` 판정은 그대로
#       (남의 파일 DELETE → 403 「권한이 없습니다」 `not_owner`)
#   (d) 만료   시험이 만든 F66- 토큰 **한 개**의 `expires_at` 만 psql 로 과거로 옮겨
#       401 「만료된 API 토큰입니다 …」 를 실측(틀린 토큰·회수된 토큰의 401 문구와 갈린다) ·
#       다른 한 개를 `now()+7 days` 로 두고 목록의 `expires_soon:true` · 끝나면 둘 다 회수
#   (e) 회수   본인 `DELETE /api/me/api-tokens/{tid}` 200 · 그 토큰 401 「올바르지 않습니다」 ·
#       다시 회수 400 · 남의 tid 404 · 관리자 `DELETE /api/users/{uid}/api-tokens/{tid}` 200
#   (f) 닫힘   개인 토큰으로 `POST /api/me/api-tokens` → 401(토큰 집합 밖) ·
#       `POST /api/users/{uid}/api-tokens` → 404(라우트 제거) · 관리자 목록에 `scope`·`expires_at` ·
#       공용 토큰(.env, 귀속이 검토 계정(qa_·claude_)일 때만)은 `read+upload` 로 동작
#   (g) 화면   Playwright(claude_employee01): 「프로그램 토큰」 절 · 범위 선택지 == `token_scopes` ·
#       발급 → 토큰 한 번 표시 · **본문 텍스트에 토큰 0회** · 표에 범위·만료 · 회수 단추 ·
#       콘솔 오류 0
#   (h) 회귀   `security_lint.sh` 0 · 바뀐 PHP `php -l` · 시험 스크립트 `bash -n` ·
#       `alerts.php --rule AL-20 --dry-run` · **마이그레이션은 임시 DB 로만**(운영은 사용자 몫)
#
# ★ 마이그레이션 감지 (지시서 0장 · f65.sh (g) 방식)
#   운영 DB 에 `api_tokens.scope` 열이 없으면 (a)~(g) 를 **전부 SKIP** 하고 (h) 만 돈다 —
#   코드는 그 열을 읽으므로 열이 없는 서버에서 토큰 경로는 500 이고, 그것은 시험의 실패가
#   아니라 「마이그레이션을 아직 안 돌렸다」는 사실이다.
#
# ★ 토큰 값 취급 (PRD S-09-11 · 7.7, SECURITY R-91, 지시서 금지 패턴)
#   토큰·비밀번호·2단계 코드는 **변수와 0700 임시 디렉터리 안에서만** 다루고 화면·로그
#   어디에도 출력하지 않는다. 201 응답 본문은 echo 하지 않고 파일로 받아 파싱한 뒤 비운다.
#   psql·grep·curl·php·python 에 토큰·비밀번호·TOTP 비밀을 **인자로 넘기지 않는다**(`ps` 로 보인다) —
#   패턴 파일·파이프(tests/lib/secrets.sh — 표준입력·0600 임시 파일)로만 준다(R-62 · 점검 봇 R15).
#
# 규칙 (CLAUDE.md 3장) — tests/api_tokens.sh · f65.sh 와 같다
#   · 검토 계정(claude_ 접두)만. 시드 계정(admin01 …)은 사용자의 자기 공격 전용이다.
#   · 모든 요청에 `-A "tbox-review/<날짜>"`.
#   · 운영 DB 에 DDL 을 걸지 않는다. 유일한 예외는 (d) 가 **시험이 방금 만든 F66- 토큰**의
#     `expires_at` 을 UPDATE 하는 것이고, `AND name LIKE 'F66-%'` 로 좁히고 rowCount 를 본다.
#
# ★ 시험 안전 (SECURITY R-73 · R-80)
#   ① 최근 10분 감사 로그에 비-qa actor 가 있으면 시작하지 않는다(종료 코드 3)
#   ② 만드는 토큰·파일·폴더는 전부 `F66-` 접두 + 날짜·PID 이고 **trap 에서도** 스스로 치운다
#   ③ 루트 파티션 여유가 1GB 미만이면 시작하지 않는다
#
# ★ 느린 것이 정상이다: 2단계 코드는 1회성(R-45)이라 발급 한 번에 30초 창 하나를 쓴다.
#   (a)(b)(c)(d) 를 합쳐 10분 넘게 걸릴 수 있다.
#
# 준비물: curl · php(TOTP) · python3(JSON · 선택적으로 playwright) · sudo -n(seed-accounts.txt · psql)
# 사용: sudo -v && bash tests/f66.sh
set -u

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
# 검토 계정 판정 한 벌(qa_·claude_ — F-71 C). 못 읽으면 사전 검사가 빈 값으로 열리므로 시작하지 않는다.
. "$ROOT/tests/lib/review_accounts.sh" || { echo "tests/lib/review_accounts.sh 를 읽지 못했다 — 시험을 시작하지 않는다 (R-73)"; exit 3; }
. "$ROOT/tests/lib/secrets.sh" || { echo "tests/lib/secrets.sh 를 읽지 못했다 — 시험을 시작하지 않는다 (R-62)"; exit 3; }
. "$ROOT/tests/lib/db_read.sh" || { echo "tests/lib/db_read.sh 를 읽지 못했다 — 시험을 시작하지 않는다 (R-73)"; exit 3; }
BASE="http://127.0.0.1:8082/tbox"
UA="tbox-review/$(date +%F)"
DB="tbox_re"
TMPDB="tbox_f66_fresh"
JAR_DIR="$(mktemp -d)"
WORK="$(mktemp -d)"
chmod 700 "$JAR_DIR" "$WORK"
TAG="$(date +%m%d)-$$"
PASS=0
FAIL=0
SKIP=0

MADE_TOKENS=''     # "uid:tid" 목록 — 전부 회수한다
MADE_FILES=''
MADE_FOLDERS=''
CLEANED=0

hr()  { printf '\n════════════════════════════════════════════════════════════\n%s\n════════════════════════════════════════════════════════════\n' "$1"; }
sub() { printf '\n── %s\n' "$1"; }
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

# 표준입력의 JSON 을 `d` 로 놓고 파이썬 식을 평가한다 (tests/api_tokens.sh 와 같다)
jq_py() { python3 -c 'import sys,json
d=json.load(sys.stdin)
print(eval(sys.argv[1]))' "$1" 2>/dev/null; }

qa_password() { sudo -n awk -v u="$1" '$1==u{print $3}' /etc/tbox-v2/seed-accounts.txt 2>/dev/null; }
qa_secret()   { sudo -n awk -v u="$1" '$1==u{print $2}' /etc/tbox-v2/qa-totp.txt 2>/dev/null; }

# ARCH 5.30 표의 문장 — **베끼지 않고 부른다** (f68.sh 와 같은 방식)
reason_msg() {
  php -r 'require $argv[1]."/src/Http.php"; require $argv[1]."/src/Upload.php"; echo Http::reasonMessage($argv[2]);' "$ROOT" "$1"
}

# ─── 정리 ────────────────────────────────────────────────────────
del_file() {
  local id="$1" c who csrf
  [ -n "$id" ] || return 0
  for who in claude_employee01 claude_admin01; do
    if [ "$who" = "claude_admin01" ]; then csrf="${ADM_CSRF:-}"; else csrf="${EMP_CSRF:-}"; fi
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
  local num="$1" c who csrf
  [ -n "$num" ] || return 0
  for who in claude_employee01 claude_admin01; do
    if [ "$who" = "claude_admin01" ]; then csrf="${ADM_CSRF:-}"; else csrf="${EMP_CSRF:-}"; fi
    [ -n "$csrf" ] || continue
    c=$(curl -s -o /dev/null -w '%{http_code}' -X DELETE -b "$JAR_DIR/$who.jar" -A "$UA" \
        -H "X-CSRF-Token: $csrf" "$BASE/api/folders/$num")
    if [ "$c" != "200" ] && [ "$c" != "404" ]; then
      c=$(curl -s -o /dev/null -w '%{http_code}' -X DELETE -b "$JAR_DIR/$who.jar" -A "$UA" \
          -H "X-CSRF-Token: $csrf" "$BASE/api/folders/$num?move_trashed=1")
    fi
    if [ "$c" = "200" ] || [ "$c" = "404" ]; then
      printf '  · 폴더 %s 삭제 [%s] (%s)\n' "$num" "$c" "$who"; return 0
    fi
  done
  printf '  ! 폴더 %s 를 치우지 못했다 — 손으로 확인이 필요하다\n' "$num"
}
# 회수는 **본인 창구**로 한다(비밀번호·코드를 묻지 않는다 — ARCH 4.7).
revoke_mine() {
  local tid="$1" c
  [ -n "$tid" ] || return 0
  [ -n "${EMP_CSRF:-}" ] || return 0
  c=$(curl -s -o /dev/null -w '%{http_code}' -X DELETE -b "$JAR_DIR/claude_employee01.jar" -A "$UA" \
      -H "X-CSRF-Token: $EMP_CSRF" "$BASE/api/me/api-tokens/$tid")
  printf '  · 토큰 #%s 회수 [%s]\n' "$tid" "$c"
}
# 목록에만 기대지 않는다 — 「만든 것은 전부 치운다」 를 목록이 아니라 **상태**로 지킨다
# (api_tokens.sh sweep_tokens 와 같은 방식).
sweep_tokens() {
  local ids='' id
  [ -n "${EMP_CSRF:-}" ] || return 0
  curl -s -b "$JAR_DIR/claude_employee01.jar" -A "$UA" "$BASE/api/me/api-tokens" > "$WORK/sweep.json" 2>/dev/null
  ids=$(jq_py "' '.join(str(t['id']) for t in d.get('tokens',[]) if not t.get('revoked_at') and str(t.get('name','')).startswith('F66-'))" < "$WORK/sweep.json")
  for id in $ids; do revoke_mine "$id"; done
}
cleanup_artifacts() {
  [ "$CLEANED" -eq 0 ] || return 0
  CLEANED=1
  [ -n "$MADE_TOKENS$MADE_FILES$MADE_FOLDERS" ] || { sweep_tokens; return 0; }
  hr "정리 — 시험이 만든 것 되돌리기 (토큰 회수 · 파일 휴지통 · 폴더 삭제)"
  local t
  for t in $MADE_TOKENS; do revoke_mine "${t##*:}"; done
  sweep_tokens
  for t in $MADE_FILES; do del_file "$t"; done
  for t in $(printf '%s\n' $MADE_FOLDERS | tac); do del_folder "$t"; done
}
cleanup() { cleanup_artifacts; rm -rf "$JAR_DIR" "$WORK"; }
trap cleanup EXIT

# ─── 시작 전 관문 ────────────────────────────────────────────────
hr "F-66 프로그램 토큰 본인 발급 — 시험 시작 전 확인"

avail_kb=$(df -Pk / | awk 'NR==2{print $4}')
echo "루트 파티션 여유: $(df -Ph / | awk 'NR==2{print $4}') (${avail_kb}KB)"
[ "$avail_kb" -ge 1048576 ] || { echo "루트 디스크 여유 1GB 미만 — 시험을 시작하지 않는다 (SECURITY R-80)"; exit 3; }

HAVE_PSQL=0
if psqlq "SELECT 1" >/dev/null 2>&1; then HAVE_PSQL=1; fi
if [ "$HAVE_PSQL" -eq 1 ]; then
  db_read_var BUSY "$DB" "SELECT count(*) FROM audit_logs WHERE happened_at > now() - interval '10 minutes' AND actor_name IS NOT NULL AND $REVIEW_NOT_SQL" \
    || { echo "운영 DB 를 읽지 못했다 — 시험을 시작하지 않는다 (R-73 · db_read rc 3)"; exit 3; }
  echo "최근 10분 비-qa 감사 행: ${BUSY:-?} (읽기 경로 ${DB_READ_VIA})"
  if [ "${BUSY:-0}" -gt 0 ] 2>/dev/null; then
    echo "사용자가 지금 서비스를 쓰고 있다 — 시험을 시작하지 않는다 (SECURITY R-73 ①)"; exit 3
  fi
else
  echo "psql 을 쓸 수 없다 — 감사 확인 항목과 (d) 가 SKIP 된다"
fi

# ─── 마이그레이션 감지 ───────────────────────────────────────────
MIG=0
if [ "$HAVE_PSQL" -eq 1 ]; then
  HAS_SCOPE=$(psqlq "SELECT count(*) FROM information_schema.columns WHERE table_name='api_tokens' AND column_name='scope'")
  HAS_EXP=$(psqlq "SELECT count(*) FROM information_schema.columns WHERE table_name='api_tokens' AND column_name='expires_at'")
  echo "운영 DB api_tokens.scope=${HAS_SCOPE:-?} · expires_at=${HAS_EXP:-?}"
  if [ "${HAS_SCOPE:-0}" = "1" ] && [ "${HAS_EXP:-0}" = "1" ]; then MIG=1; fi
fi
if [ "$MIG" -eq 0 ]; then
  echo "→ 마이그레이션이 아직 적용되지 않았다(또는 psql 없음). (a)~(g) 는 전부 SKIP 하고 (h) 만 돈다."
  echo "  운영 적용(사용자): sudo -u postgres psql -d $DB -v ON_ERROR_STOP=1 -f db/migrations/2026-09-22_api_tokens_scope_expiry.sql"
else
  echo "→ 마이그레이션 적용됨. (a)~(h) 전부 실행한다."
fi

# ─── 공용 토큰 귀속 — 계정 **이름만** 읽는다 ─────────────────────
SHARED_USER=$(grep -oE '^API_TOKEN_USER=.*' "$ROOT/.env" 2>/dev/null | cut -d= -f2- | tr -d '"'"'"' \r')
SHARED_SET=$(grep -c '^API_TOKEN=..*$' "$ROOT/.env" 2>/dev/null); SHARED_SET=${SHARED_SET:-0}
USE_SHARED=0
case "${SHARED_USER:-}" in
  qa_*|claude_*) [ "$SHARED_SET" -eq 1 ] && USE_SHARED=1 ;;
esac
if [ "$USE_SHARED" -eq 1 ]; then
  SHARED_TOKEN=$(grep -oE '^API_TOKEN=.*' "$ROOT/.env" | cut -d= -f2- | tr -d '"'"'"' \r')
  echo "공용 토큰 귀속이 검토 계정이다 — (f) 의 공용 토큰 항목을 실행한다 (값은 출력하지 않는다)"
else
  SHARED_TOKEN=''
  echo "공용 토큰 귀속이 검토 계정(qa_·claude_)이 아니다 — (f) 의 공용 토큰 항목은 SKIP"
fi
WRONG_TOKEN='wrong-token-0000000000000000000000000000000000'

# ─── 로그인 ──────────────────────────────────────────────────────
login() {   # $1 계정 → 성공하면 표준출력에 csrf 토큰
  local who="$1" jar="$JAR_DIR/$1.jar" pw sec raw code left out try body
  pw=$(qa_password "$who"); sec=$(qa_secret "$who")
  [ -n "$pw" ] && [ -n "$sec" ] || return 2
  for try in 1 2 3; do
    raw=$(totp_now sec)
    code=${raw%%$'\n'*}
    # 비밀번호가 든 본문은 표준입력으로(-d 인자 아님 — R-62). 보내는 바이트는 전과 같다(printf '%s' · 끝 줄바꿈 없음)
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
    printf '  · %s 코드 재사용으로 거부됨 — 다음 창까지 %s초 (R-45 가 의도대로 막은 것)\n' "$who" "$left" >&2
    sleep $((left + 1))
  done
  return 1
}
reauth() {
  local who="$1" token="$2" pw body
  pw=$(qa_password "$who")
  printf -v body '{"password":"%s"}' "$pw"
  curl_json_secret body -s -o /dev/null -w '%{http_code}' -b "$JAR_DIR/$who.jar" -A "$UA" \
    -H 'Content-Type: application/json' -H "X-CSRF-Token: $token" \
    "$BASE/api/me/reauth"
}

# 악성코드 검사(F-54, ARCH 5.28)가 끝나기를 기다린다 — `tests/f65.sh` wait_clean 과 같다.
#   `SCAN_ENABLED` 서버에서 방금 올린 파일은 `scan_status='pending'` 이고, 받기 관문이
#   **범위 판정보다 뒤**가 아니라 핸들러 안에서 423 「검사 중입니다」로 먼저 끊는다.
#   1분 cron 을 기다리지 않고 배치를 직접 돌린다 — 시험이 운에 기대면 안 된다
#   (f65.sh 3회차 실측: 이 기다림이 없어 200 항목들이 423 으로 어긋났다).
# @return 0 = clean · 1 = 그 밖(격리·시간 초과·psql 없음)
wait_clean() {   # $1 public_id
  local id="$1" i st
  [ -n "$id" ] || return 1
  if [ "$HAVE_PSQL" -eq 0 ]; then
    note "psql 이 없어 검사 상태를 볼 수 없다 — 이 파일의 받기 항목은 미검증으로 남긴다"
    return 1
  fi
  for i in 1 2 3 4 5 6 7 8 9 10; do
    st=$(psqlq "SELECT scan_status FROM files WHERE public_id='$id'")
    case "${st:-}" in
      clean)    [ "$i" -gt 1 ] && note "검사 완료(clean) — ${i}회째"; return 0 ;;
      infected) note "격리됨(infected) — 시험 파일이 격리됐다"; return 1 ;;
    esac
    sudo -n -u tbox-php php "$ROOT/scripts/scan_pending.php" --force-enabled >/dev/null 2>&1 || true
    sleep 5
  done
  note "검사가 10회 안에 끝나지 않았다 (마지막 상태: ${st:-?})"
  return 1
}

# **다음 창**의 2단계 코드 — 방금 쓴 코드는 다시 못 쓴다 (R-45, RFC 6238 §5.2)
totp_next() {   # $1 계정 → 코드만
  local who="$1" sec raw left
  sec=$(qa_secret "$who")
  [ -n "$sec" ] || return 1
  raw=$(totp_now sec 2>/dev/null) || return 1
  left=$(printf '%s' "$raw" | sed -n 's/^남은 시간: \([0-9]*\)초.*/\1/p')
  [ -n "$left" ] || left=31
  printf '  · %s 다음 2단계 코드 창까지 %s초 기다린다 (R-45 1회성)\n' "$who" "$left" >&2
  sleep $((left + 1))
  raw=$(totp_now sec 2>/dev/null) || return 1
  printf '%s\n' "${raw%%$'\n'*}"
}

# 발급 한 번 — 결과는 **전역 변수**로만 (명령 치환으로 부르면 서브셸에 갇힌다)
ISSUED_TOKEN=''; ISSUED_ID=''; ISSUED_HINT=''; ISSUED_CODE=''
ISSUED_SCOPE=''; ISSUED_EXPIRES=''; ISSUED_OTP=''; ISSUED_AT=''
issue() {   # $1 이름 · $2 용도 · $3 범위
  local name="$1" purpose="$2" scope="$3" pw body
  ISSUED_TOKEN=''; ISSUED_ID=''; ISSUED_HINT=''; ISSUED_CODE=''
  ISSUED_SCOPE=''; ISSUED_EXPIRES=''; ISSUED_AT=''
  pw=$(qa_password claude_employee01)
  ISSUED_OTP=$(totp_next claude_employee01) || { ISSUED_CODE='코드없음'; return 0; }
  [ -n "$pw" ] || { ISSUED_CODE='비밀번호없음'; return 0; }
  # 비밀번호는 표준입력 첫 줄로만 파이썬에 준다(argv 아님 — R-62). 본문 JSON(키 순서·이스케이프)은 전과 같다
  body=$(py_secret pw -- python3 -c 'import json,sys; pw=sys.stdin.readline().rstrip("\n"); print(json.dumps({"name":sys.argv[1],"purpose":sys.argv[2],"scope":sys.argv[3],"password":pw,"otp":sys.argv[4]}))' \
         "$name" "$purpose" "$scope" "$ISSUED_OTP")
  ISSUED_AT=$(psqlq "SELECT now()")
  ISSUED_CODE=$(curl_json_secret body -s -o "$WORK/issue.json" -w '%{http_code}' -X POST \
         -b "$JAR_DIR/claude_employee01.jar" -A "$UA" -H "X-CSRF-Token: $EMP_CSRF" \
         -H 'Content-Type: application/json' "$BASE/api/me/api-tokens")
  if [ "$ISSUED_CODE" = "201" ]; then
    ISSUED_TOKEN=$(jq_py "d.get('token','')" < "$WORK/issue.json")
    ISSUED_ID=$(jq_py "str(d.get('id',''))" < "$WORK/issue.json")
    ISSUED_HINT=$(jq_py "d.get('hint','')" < "$WORK/issue.json")
    ISSUED_SCOPE=$(jq_py "d.get('scope','')" < "$WORK/issue.json")
    ISSUED_EXPIRES=$(jq_py "d.get('expires_at','')" < "$WORK/issue.json")
    [ -n "$ISSUED_ID" ] && MADE_TOKENS="$MADE_TOKENS ${EMP_UID:-0}:$ISSUED_ID"
  fi
  : > "$WORK/issue.json"      # 토큰이 든 응답 파일은 곧바로 비운다
}

# 실패한 뒤에는 **곧바로 재로그인**한다 — 실패 5회면 계정이 잠긴다 (R-48).
# 로그인 성공이 failed_count 를 0 으로 돌린다.
relogin_emp() {
  EMP_CSRF=$(login claude_employee01) || { echo "  ! claude_employee01 재로그인 실패"; return 1; }
  note "claude_employee01 재로그인 (실패 집계 0으로)"
}

failed_count() { psqlq "SELECT failed_count FROM users WHERE username='claude_employee01'"; }

# ─── 준비: 세션 ──────────────────────────────────────────────────
hr "준비 — 검토 계정 세션"
ADM_CSRF=$(login claude_admin01)    || { echo "claude_admin01 로그인 실패 — 시험을 시작할 수 없다"; exit 1; }
EMP_CSRF=$(login claude_employee01) || { echo "claude_employee01 로그인 실패 — 시험을 시작할 수 없다"; exit 1; }
GST_CSRF=$(login claude_guest01)    || { GST_CSRF=''; note "claude_guest01 이 없다(또는 로그인 실패) — Guest 범위 항목은 SKIP"; }

EMP_UID=''
ADM_UID=''
if [ "$HAVE_PSQL" -eq 1 ]; then
  EMP_UID=$(psqlq "SELECT id FROM users WHERE username='claude_employee01'")
  ADM_UID=$(psqlq "SELECT id FROM users WHERE username='claude_admin01'")
fi
note "claude_employee01 uid=${EMP_UID:-?} · claude_admin01 uid=${ADM_UID:-?}"

curl -s -b "$JAR_DIR/claude_employee01.jar" -A "$UA" "$BASE/api/me" > "$WORK/me.json"
ME_SCOPES=$(jq_py "','.join(d.get('token_scopes',[]))" < "$WORK/me.json")
ME_CAN=$(jq_py "str(d.get('can_issue_tokens','없음'))" < "$WORK/me.json")
note "claude_employee01 token_scopes=[${ME_SCOPES:-?}] · can_issue_tokens=${ME_CAN:-?}"
# Q:F-8(2026-09-28) — 쓰기 자격 있는 역할의 선택지 끝에 독립 값 community.write 가 붙는다(ARCH 4.8 · 이 조각이 의도한 값)
# Q:F-10(2026-09-29) — token_scopes 는 고를 수 있는 **원자**(2단계가 켜진 Employee 라 session.open 까지 — ARCH 4.10)
expect "준비 /api/me token_scopes (Employee — 원자 여섯)" "read,upload,delete,community.read,community.write,session.open" "${ME_SCOPES:-없음}"
expect "준비 /api/me can_issue_tokens (2단계 켜짐)" "True" "${ME_CAN:-없음}"

# ─── (a) 발급 ────────────────────────────────────────────────────
hr "(a) POST /api/me/api-tokens — 본인 발급"
A_TOK=''; A_TID=''; A_OTP=''
if [ "$MIG" -eq 0 ]; then
  skip "(a) 전체" "운영 DB 에 api_tokens.scope 열이 없다 — 마이그레이션 먼저"
else
  T0=$(psqlq "SELECT now()")
  issue "F66-a-$TAG" "T-78 (a) 시험 — 본인 발급" "read+upload"
  expect "발급 상태" "201" "$ISSUED_CODE"
  A_TOK="$ISSUED_TOKEN"; A_TID="$ISSUED_ID"; A_OTP="$ISSUED_OTP"
  expect "토큰 길이 48" "48" "${#A_TOK}"
  expect "토큰 글자 구성 [A-Za-z0-9]" "1" "$(printf '%s' "$A_TOK" | grep -cE '^[A-Za-z0-9]{48}$')"
  if [ -n "$A_TOK" ] && [ "$ISSUED_HINT" = "${A_TOK:0:6}" ]; then
    expect "hint == 토큰 앞 6자 (값은 출력하지 않는다)" "같음" "같음"
  else
    expect "hint == 토큰 앞 6자 (값은 출력하지 않는다)" "같음" "다름"
  fi
  expect "응답 scope 그대로" "read+upload" "$ISSUED_SCOPE"

  if [ "$HAVE_PSQL" -eq 0 ] || [ -z "$A_TID" ]; then
    skip "(a) expires_at = 발급 + 180일" "psql 또는 토큰 없음"
  else
    expect "expires_at == created_at + 180일 (±1분)" "t" \
      "$(psqlq "SELECT abs(extract(epoch from (expires_at - (created_at + interval '180 days')))) < 60 FROM api_tokens WHERE id = $A_TID")"
  fi

  R=$(curl -s -w '\n%{http_code}' -b "$JAR_DIR/claude_employee01.jar" -A "$UA" "$BASE/api/me/api-tokens")
  expect "내 목록 상태" "200" "$(code_of "$R")"
  printf '%s' "$(body_of "$R")" > "$WORK/list.json"
  expect "내 목록에 그 행" "1" "$(jq_py "sum(1 for t in d['tokens'] if str(t['id'])=='$A_TID')" < "$WORK/list.json")"
  # Q:F-9 가 bound_ip, Q:F-10 이 scopes(원자 배열)를 더했다(API.md 13장 · 21장)
  expect "내 목록 행의 키" \
    "bound_ip,created_at,expires_at,expires_soon,hint,id,last_used_at,name,purpose,revoked_at,scope,scopes" \
    "$(jq_py "','.join(sorted(d['tokens'][0].keys())) if d['tokens'] else '(빈 목록)'" < "$WORK/list.json")"
  expect "내 목록에 token 키 없음" "0" "$(grep -c '"token"' "$WORK/list.json")"
  expect "방금 발급한 토큰 expires_soon:false" "False" \
    "$(jq_py "str(next((t['expires_soon'] for t in d['tokens'] if str(t['id'])=='$A_TID'), '없음'))" < "$WORK/list.json")"

  if [ "$HAVE_PSQL" -eq 0 ]; then
    skip "(a) 감사" "psql 을 쓸 수 없다"
  else
    expect "감사 api_token.issue allow (발급자 = 대상 = 본인)" "t" \
      "$(psqlq "SELECT count(*) > 0 FROM audit_logs WHERE action='api_token.issue' AND result='allow' AND actor_name='claude_employee01' AND target_name='claude_employee01' AND happened_at > '$T0'::timestamptz")"
    expect "감사 detail 에 token_scope·otp_verified·reason_text" "t" \
      "$(psqlq "SELECT count(*) > 0 FROM audit_logs WHERE action='api_token.issue' AND result='allow' AND happened_at > '$T0'::timestamptz AND detail->>'token_scope'='read+upload' AND detail->>'otp_verified'='true' AND detail ? 'reason_text'")"
    expect "감사 detail 에 비밀 키 0 (password·otp·hint·token)" "t" \
      "$(psqlq "SELECT count(*) = 0 FROM audit_logs WHERE action='api_token.issue' AND happened_at > '$T0'::timestamptz AND (detail ? 'password' OR detail ? 'otp' OR detail ? 'hint' OR detail ? 'token' OR detail ? 'token_hash')")"

    # ★ DB 어디에도 토큰 원문·비밀번호·코드가 없다 — 패턴 파일로만 넘긴다
    : > "$WORK/pat.txt"
    [ -n "$A_TOK" ] && printf '%s\n' "$A_TOK" >> "$WORK/pat.txt"
    [ -n "$A_OTP" ] && printf '%s\n' "$A_OTP" >> "$WORK/pat.txt"
    printf '%s\n' "$(qa_password claude_employee01)" >> "$WORK/pat.txt"
    psqlq "SELECT coalesce(summary,'') || ' ' || coalesce(detail::text,'') FROM audit_logs WHERE happened_at > '$T0'::timestamptz" > "$WORK/dump.txt"
    psqlq "SELECT id || ' ' || name || ' ' || token_hash || ' ' || hint || ' ' || coalesce(reason_text,'') || ' ' || scope FROM api_tokens" >> "$WORK/dump.txt"
    expect "audit_logs·api_tokens 에 토큰·비밀번호·코드 원문 0건" "0" \
      "$(grep -c -F -f "$WORK/pat.txt" "$WORK/dump.txt")"
  fi
fi

# ─── (b) 거부 ────────────────────────────────────────────────────
hr "(b) 거부 — 비밀번호·코드·범위·키·상한·2단계"
if [ "$MIG" -eq 0 ]; then
  skip "(b) 전체" "운영 DB 에 api_tokens.scope 열이 없다"
else
  PW=$(qa_password claude_employee01)
  post_issue() {   # $1 = JSON 본문 → "본문\n상태" (본문에 비밀번호가 들 수 있어 표준입력으로 보낸다 — R-62)
    local pbody="$1"
    curl_json_secret pbody -s -w '\n%{http_code}' -X POST -b "$JAR_DIR/claude_employee01.jar" -A "$UA" \
      -H "X-CSRF-Token: $EMP_CSRF" -H 'Content-Type: application/json' "$BASE/api/me/api-tokens"
  }
  UNIFORM=$(reason_msg bad_request)
  note "통일 문구(ARCH 5.30 예외 목록): 「$UNIFORM」"

  # 1. 틀린 비밀번호 → 400 bad_credentials + failed_count 증가
  sub "1. 틀린 비밀번호"
  FC0=$(failed_count)
  M=$(psqlq "SELECT now()")
  R=$(post_issue "$(python3 -c 'import json; print(json.dumps({"name":"F66-b1","purpose":"틀린 비밀번호","scope":"read","password":"이-비밀번호는-틀렸다","otp":"000000"}))')")
  expect "(b)1 틀린 비밀번호 → 400" "400" "$(code_of "$R")"
  expect "(b)1 문구는 통일 문구" "{\"error\":\"$UNIFORM\"}" "$(body_of "$R")"
  if [ "$HAVE_PSQL" -eq 1 ]; then
    expect "(b)1 감사 reason=bad_credentials" "t" \
      "$(psqlq "SELECT count(*) > 0 FROM audit_logs WHERE action='api_token.issue' AND result='deny' AND reason='bad_credentials' AND happened_at > '$M'::timestamptz")"
    FC1=$(failed_count)
    note "failed_count ${FC0:-?} → ${FC1:-?}"
    expect "(b)1 failed_count 증가 (R-48)" "t" "$(psqlq "SELECT ${FC1:-0} > ${FC0:-0}")"
  else
    skip "(b)1 감사·failed_count" "psql 없음"
  fi
  relogin_emp

  # 2. 맞는 비밀번호 + 틀린 코드 → 400 bad_code
  sub "2. 맞는 비밀번호 + 틀린 코드"
  M=$(psqlq "SELECT now()")
  # 「틀린 코드」는 지금 창의 코드에서 한 자리를 바꿔 만든다(우연히 맞을 일이 없게).
  B2_SEC=$(qa_secret claude_employee01)
  NOWCODE=$(totp_now B2_SEC 2>/dev/null | head -1)
  B2_SEC=''
  BADCODE=$(python3 -c 'import sys; c=sys.argv[1] or "000000"; print(("1" if c[0]!="1" else "2") + c[1:])' "$NOWCODE")
  R=$(post_issue "$(py_secret PW -- python3 -c 'import json,sys; pw=sys.stdin.readline().rstrip("\n"); print(json.dumps({"name":"F66-b2","purpose":"틀린 코드","scope":"read","password":pw,"otp":sys.argv[1]}))' "$BADCODE")")
  expect "(b)2 틀린 코드 → 400" "400" "$(code_of "$R")"
  expect "(b)2 문구는 (b)1 과 같다 (오라클 차단)" "{\"error\":\"$UNIFORM\"}" "$(body_of "$R")"
  if [ "$HAVE_PSQL" -eq 1 ]; then
    expect "(b)2 감사 reason=bad_code" "t" \
      "$(psqlq "SELECT count(*) > 0 FROM audit_logs WHERE action='api_token.issue' AND result='deny' AND reason='bad_code' AND happened_at > '$M'::timestamptz")"
  else skip "(b)2 감사" "psql 없음"; fi
  relogin_emp

  # 3. 같은 코드 재사용 → code_reused ((a) 에서 쓴 코드를 그대로 다시 쓴다)
  sub "3. 이미 쓴 코드 재사용 (R-45)"
  if [ -z "$A_OTP" ]; then
    skip "(b)3 code_reused" "(a) 에서 쓴 코드가 없다"
  else
    M=$(psqlq "SELECT now()")
    R=$(post_issue "$(py_secret PW -- python3 -c 'import json,sys; pw=sys.stdin.readline().rstrip("\n"); print(json.dumps({"name":"F66-b3","purpose":"코드 재사용","scope":"read","password":pw,"otp":sys.argv[1]}))' "$A_OTP")")
    expect "(b)3 재사용 → 400" "400" "$(code_of "$R")"
    expect "(b)3 문구는 같다" "{\"error\":\"$UNIFORM\"}" "$(body_of "$R")"
    if [ "$HAVE_PSQL" -eq 1 ]; then
      expect "(b)3 감사 reason 이 bad_code 또는 code_reused" "t" \
        "$(psqlq "SELECT count(*) > 0 FROM audit_logs WHERE action='api_token.issue' AND result='deny' AND reason IN ('code_reused','bad_code') AND happened_at > '$M'::timestamptz")"
    else skip "(b)3 감사" "psql 없음"; fi
    relogin_emp
  fi

  # 4. 역할 밖 · 모르는 범위 → 400 bad_request (역할 힌트를 주지 않는다)
  sub "4. 역할 밖 / 모르는 범위"
  R=$(post_issue '{"name":"F66-b4","purpose":"모르는 범위","scope":"admin","password":"x","otp":"000000"}')
  expect "(b)4 모르는 범위 → 400" "400" "$(code_of "$R")"
  expect "(b)4 문구" "{\"error\":\"$UNIFORM\"}" "$(body_of "$R")"
  if [ -z "$GST_CSRF" ]; then
    skip "(b)4 Guest 가 read+upload 를 고르면 400" "claude_guest01 이 없다"
  else
    curl -s -b "$JAR_DIR/claude_guest01.jar" -A "$UA" "$BASE/api/me" > "$WORK/gme.json"
    expect "(b)4 Guest token_scopes 는 read 하나" "read" \
      "$(jq_py "','.join(d.get('token_scopes',[]))" < "$WORK/gme.json")"
    GC=$(curl -s -o /dev/null -w '%{http_code}' -X POST -b "$JAR_DIR/claude_guest01.jar" -A "$UA" \
         -H "X-CSRF-Token: $GST_CSRF" -H 'Content-Type: application/json' \
         -d '{"name":"F66-b4g","purpose":"Guest 범위 시험","scope":"read+upload","password":"x","otp":"000000"}' \
         "$BASE/api/me/api-tokens")
    expect "(b)4 Guest 가 read+upload → 400" "400" "$GC"
  fi

  # 5. 모르는 키 → 400 (R-07)
  sub "5. 모르는 키 (R-07)"
  R=$(post_issue '{"name":"F66-b5","purpose":"모르는 키","scope":"read","password":"x","otp":"000000","user_id":1}')
  expect "(b)5 모르는 키 → 400" "400" "$(code_of "$R")"

  # 6. 살아 있는 5개 뒤 6번째 → 400 limit_exceeded (f68.sh (a)13 에서 옮겨 왔다)
  sub "6. 상한 — 살아 있는 5개 뒤 6번째 (f68.sh (a)13 이 여기로 옮겨졌다)"
  LIVE=$(curl -s -b "$JAR_DIR/claude_employee01.jar" -A "$UA" "$BASE/api/me/api-tokens" \
         | jq_py "str(len([t for t in d.get('tokens',[]) if not t.get('revoked_at')]))")
  note "claude_employee01 의 살아 있는 토큰: ${LIVE:-?}개 (상한 5)"
  LIM_OK=1
  if [ "${LIVE:-0}" -gt 5 ] 2>/dev/null; then
    skip "(b)6 limit_exceeded" "살아 있는 토큰이 이미 5개를 넘는다 (${LIVE}) — 운영 데이터 이상, 손대지 않는다"
    LIM_OK=0
  fi
  FILLED=''
  if [ "$LIM_OK" -eq 1 ]; then
    i=${LIVE:-0}
    while [ "$i" -lt 5 ]; do
      i=$((i+1))
      issue "F66-lim$i-$TAG" "T-78 (b)6 상한 채우기" "read"
      note "상한 채우기 $i 번째 [$ISSUED_CODE]"
      if [ "$ISSUED_CODE" != "201" ]; then
        expect "(b)6 상한 채우기 발급" "201" "$ISSUED_CODE"; LIM_OK=0; break
      fi
      FILLED="$FILLED $ISSUED_ID"
    done
  fi
  if [ "$LIM_OK" -eq 1 ]; then
    M=$(psqlq "SELECT now()")
    issue "F66-lim6-$TAG" "T-78 (b)6 — 6번째는 거부되어야 한다" "read"
    expect "(b)6 6번째 → 400" "400" "$ISSUED_CODE"
    R=$(curl -s -w '\n%{http_code}' -b "$JAR_DIR/claude_employee01.jar" -A "$UA" "$BASE/api/me/api-tokens")
    # 문장은 ARCH 5.30 표를 **부른다**(베끼지 않는다 — T-81 (a)15 와 같은 규칙)
    LIMSG=$(reason_msg limit_exceeded)
    RL=$(post_issue "$(py_secret PW -- python3 -c 'import json,sys; pw=sys.stdin.readline().rstrip("\n"); print(json.dumps({"name":"F66-lim7","purpose":"문장 대조","scope":"read","password":pw,"otp":"000000"}))')")
    note "(참고) 코드가 틀린 7번째는 인증에서 먼저 끝난다 [$(code_of "$RL")]"
    if [ "$HAVE_PSQL" -eq 1 ]; then
      expect "(b)6 감사 reason=limit_exceeded" "t" \
        "$(psqlq "SELECT count(*) > 0 FROM audit_logs WHERE action='api_token.issue' AND result='deny' AND reason='limit_exceeded' AND happened_at > '$M'::timestamptz")"
      expect "(b)6 limit_exceeded 문장이 ARCH 5.30 표와 같다" "1" \
        "$(printf '%s' "$LIMSG" | grep -c '살아 있는 토큰이 상한에 이르렀습니다')"
    else skip "(b)6 감사" "psql 없음"; fi
    relogin_emp
    # 채운 것들을 되돌린다 — (c) 가 세 토큰을 더 발급해야 한다
    for t in $FILLED; do revoke_mine "$t"; done
  fi

  # 7. 2단계가 꺼진 계정 → 403 mfa_not_enabled, failed_count 불변
  sub "7. 2단계 인증이 꺼진 계정"
  MFA_OFF=''
  for who in claude_guest01 claude_manager01 claude_viewer01; do
    [ -f "$JAR_DIR/$who.jar" ] || continue
    if [ "$(curl -s -b "$JAR_DIR/$who.jar" -A "$UA" "$BASE/api/me" | jq_py "str(d.get('mfa_enabled'))")" = "False" ]; then
      MFA_OFF="$who"; break
    fi
  done
  if [ -z "$MFA_OFF" ]; then
    skip "(b)7 mfa_not_enabled 실측" "2단계가 꺼진 검토 계정이 없다 — me_tokens_issue.php [6] 이 \$user['mfa_enabled'] 를 보고 403 MSG_FORBIDDEN 을 내는 **코드 읽기**로 대체하며 미검증으로 남긴다"
  else
    note "2단계 꺼진 계정: $MFA_OFF"
    FC0=$(psqlq "SELECT failed_count FROM users WHERE username='$MFA_OFF'")
    CS=$(curl -s -b "$JAR_DIR/$MFA_OFF.jar" -A "$UA" "$BASE/api/me" | sed -n 's/.*"csrf_token":"\([0-9a-f]*\)".*/\1/p')
    B7_PW=$(qa_password "$MFA_OFF")
    printf -v B7_BODY '{"name":"F66-b7","purpose":"2단계 꺼짐 시험","scope":"read","password":"%s","otp":"000000"}' "$B7_PW"
    R=$(curl_json_secret B7_BODY -s -w '\n%{http_code}' -X POST -b "$JAR_DIR/$MFA_OFF.jar" -A "$UA" \
        -H "X-CSRF-Token: $CS" -H 'Content-Type: application/json' \
        "$BASE/api/me/api-tokens")
    B7_PW=''; B7_BODY=''
    expect "(b)7 2단계 꺼짐 → 403" "403" "$(code_of "$R")"
    expect "(b)7 문구는 통일 403" '{"error":"권한이 없습니다"}' "$(body_of "$R")"
    if [ "$HAVE_PSQL" -eq 1 ]; then
      FC1=$(psqlq "SELECT failed_count FROM users WHERE username='$MFA_OFF'")
      expect "(b)7 failed_count 불변 (코드 추측이 아니다)" "${FC0:-?}" "${FC1:-?}"
    else skip "(b)7 failed_count" "psql 없음"; fi
  fi
fi

# ─── (c) 범위 가름 ───────────────────────────────────────────────
hr "(c) 범위 가름 — 세 범위 × 토큰 창구 열"
if [ "$MIG" -eq 0 ]; then
  skip "(c) 전체" "운영 DB 에 api_tokens.scope 열이 없다"
else
  T2=$(psqlq "SELECT now()")
  SCOPE_MSG='이 토큰의 범위에 없는 창구입니다 — 범위가 더 넓은 토큰을 발급하세요'

  # 시험용 파일·폴더를 **세션으로** 먼저 만든다 (토큰이 아니라 사람이 만든 것)
  printf 'F-66 범위 시험 파일입니다. %s\n' "$TAG" > "$WORK/F66-read-$TAG.txt"
  R=$(curl -s -w '\n%{http_code}' -X POST -b "$JAR_DIR/claude_employee01.jar" -A "$UA" \
      -H "X-CSRF-Token: $EMP_CSRF" -F "file=@$WORK/F66-read-$TAG.txt" -F 'access_level=internal' \
      "$BASE/api/files")
  C_FILE=$(uuid_of "$(body_of "$R")")
  [ -n "$C_FILE" ] && MADE_FILES="$MADE_FILES $C_FILE"
  note "시험 파일 ${C_FILE:-(없음)} [$(code_of "$R")]"

  # 받기 항목은 **clean 이 된 뒤에만** 범위 판정에 닿는다 (F-54 관문이 앞이다).
  C_CLEAN=0
  if [ -n "$C_FILE" ] && wait_clean "$C_FILE"; then C_CLEAN=1; fi
  note "시험 파일 검사 상태: $( [ "$C_CLEAN" -eq 1 ] && echo clean || echo '미완 — 받기 항목은 미검증' )"

  # 받기(다운로드) 한 항목 — clean 이면 200 을 본다. 아니면 423 이 정상이고
  # **범위 판정에는 닿지 못했다**고 적는다(SKIP 이 아니라 미검증으로 남긴다).
  expect_download() {   # $1 토큰 · $2 이름
    local got; got=$(tk "$1" download)
    if [ "$C_CLEAN" -eq 1 ]; then
      expect "$2" "200" "$got"
    elif [ "$got" = "423" ]; then
      note "$2 — 423 「검사 중입니다」(F-54 관문이 범위 판정보다 앞) · **미검증**"
      SKIP=$((SKIP+1))
    else
      expect "$2 (검사 미완 — 423 이거나 200 이어야 한다)" "423" "$got"
    fi
  }

  R=$(curl -s -w '\n%{http_code}' -X POST -b "$JAR_DIR/claude_employee01.jar" -A "$UA" \
      -H "X-CSRF-Token: $EMP_CSRF" -H 'Content-Type: application/json' \
      -d "{\"name\":\"F66-scope-$TAG\",\"access_level\":\"internal\"}" "$BASE/api/folders")
  C_FOLDER=$(fid_of "$(body_of "$R")")
  [ -n "$C_FOLDER" ] && MADE_FOLDERS="$MADE_FOLDERS $C_FOLDER"
  note "시험 폴더 ${C_FOLDER:-(없음)} [$(code_of "$R")]"

  # 창구 열 — 토큰으로 부르고 상태 코드만 본다
  tk() {   # $1 토큰 · $2 창구이름 → 상태 코드 (표준출력)
    # 토큰은 「X-API-Token: 값」 한 줄로 표준입력에(-H @- · R-62) — 지역 변수 t 의 **이름**만 넘긴다
    local t="$1" what="$2"
    case "$what" in
      whoami)   curl_hdr_secret X-API-Token t -s -o /dev/null -w '%{http_code}' -A "$UA" "$BASE/api/whoami" ;;
      files)    curl_hdr_secret X-API-Token t -s -o /dev/null -w '%{http_code}' -A "$UA" "$BASE/api/files?limit=1" ;;
      download) curl_hdr_secret X-API-Token t -s -o /dev/null -w '%{http_code}' -A "$UA" "$BASE/api/files/$C_FILE/download" ;;
      folders)  curl_hdr_secret X-API-Token t -s -o /dev/null -w '%{http_code}' -A "$UA" "$BASE/api/folders" ;;
      upload)   curl_hdr_secret X-API-Token t -s -o /dev/null -w '%{http_code}' -X POST -A "$UA" \
                  -F "file=@$WORK/F66-read-$TAG.txt" -F 'level=internal' "$BASE/api/upload" ;;
      mkfolder) curl_hdr_secret X-API-Token t -s -o /dev/null -w '%{http_code}' -X POST -A "$UA" \
                  -H 'Content-Type: application/json' \
                  -d "{\"name\":\"F66-tk-$TAG-$RANDOM\",\"access_level\":\"internal\"}" "$BASE/api/folders" ;;
      pfolder)  curl_hdr_secret X-API-Token t -s -o /dev/null -w '%{http_code}' -X PATCH -A "$UA" \
                  -H 'Content-Type: application/json' -d '{"name":"F66-renamed"}' "$BASE/api/folders/$C_FOLDER" ;;
      # ★ 토큰 경로의 `PATCH /api/files/{id}` 는 허용 키가 **`folder_id` 하나**다
      #   (files_update.php [2], F-58 · R-90 — 설명·등급·팀은 프로그램 창구에 열지 않는다).
      #   `{"description":…}` 을 보내면 범위와 무관하게 400 이라 범위 판정을 볼 수 없다.
      pfile)    curl_hdr_secret X-API-Token t -s -o /dev/null -w '%{http_code}' -X PATCH -A "$UA" \
                  -H 'Content-Type: application/json' -d "{\"folder_id\":$C_FOLDER}" "$BASE/api/files/$C_FILE" ;;
      dfile)    curl_hdr_secret X-API-Token t -s -o /dev/null -w '%{http_code}' -X DELETE -A "$UA" "$BASE/api/files/$C_FILE" ;;
      dfolder)  curl_hdr_secret X-API-Token t -s -o /dev/null -w '%{http_code}' -X DELETE -A "$UA" "$BASE/api/folders/$C_FOLDER" ;;
    esac
  }

  # ── read 토큰 ────────────────────────────────────────────────
  sub "read 토큰 — 읽기 넷 200 · 쓰기·지우기 여섯 403"
  issue "F66-c-read-$TAG" "T-78 (c) read" "read"
  R_TOK="$ISSUED_TOKEN"; R_TID="$ISSUED_ID"
  if [ -z "$R_TOK" ]; then
    skip "(c) read 토큰" "발급 실패 [$ISSUED_CODE]"
  else
    expect "read: GET /api/whoami" "200" "$(tk "$R_TOK" whoami)"
    expect "read: GET /api/files" "200" "$(tk "$R_TOK" files)"
    expect "read: GET /api/folders" "200" "$(tk "$R_TOK" folders)"
    if [ -n "$C_FILE" ]; then
      expect_download "$R_TOK" "read: GET /api/files/{id}/download"
    else skip "(c) read 다운로드" "시험 파일이 없다"; fi
    expect "read: POST /api/upload → 403" "403" "$(tk "$R_TOK" upload)"
    expect "read: POST /api/folders → 403" "403" "$(tk "$R_TOK" mkfolder)"
    if [ -n "$C_FOLDER" ]; then
      expect "read: PATCH /api/folders/{num} → 403" "403" "$(tk "$R_TOK" pfolder)"
      expect "read: DELETE /api/folders/{num} → 403" "403" "$(tk "$R_TOK" dfolder)"
    else skip "(c) read 폴더 둘" "시험 폴더가 없다"; fi
    if [ -n "$C_FILE" ] && [ -n "$C_FOLDER" ]; then
      # 범위 관문은 **인가·핸들러보다 앞**이라 본문이 무엇이든 403 이다
      expect "read: PATCH /api/files/{id} → 403" "403" "$(tk "$R_TOK" pfile)"
      expect "read: DELETE /api/files/{id} → 403" "403" "$(tk "$R_TOK" dfile)"
      # 문구 한 번만 견준다 (같은 관문에서 나오는 같은 문장이다)
      OUT=$(curl_hdr_secret X-API-Token R_TOK -s -X POST -A "$UA" \
            -F "file=@$WORK/F66-read-$TAG.txt" -F 'level=internal' "$BASE/api/upload")
      expect "read: 범위 밖 문구" "{\"error\":\"$SCOPE_MSG\"}" "$OUT"
    fi
  fi

  # ── read+upload 토큰 ─────────────────────────────────────────
  sub "read+upload 토큰 — 읽기·쓰기 여덟 통과 · 지우기 둘 403"
  issue "F66-c-rw-$TAG" "T-78 (c) read+upload" "read+upload"
  W_TOK="$ISSUED_TOKEN"; W_TID="$ISSUED_ID"
  if [ -z "$W_TOK" ]; then
    skip "(c) read+upload 토큰" "발급 실패 [$ISSUED_CODE]"
  else
    expect "read+upload: GET /api/whoami" "200" "$(tk "$W_TOK" whoami)"
    expect "read+upload: GET /api/files" "200" "$(tk "$W_TOK" files)"
    expect "read+upload: GET /api/folders" "200" "$(tk "$W_TOK" folders)"
    [ -n "$C_FILE" ] && expect_download "$W_TOK" "read+upload: 다운로드"
    OUT=$(curl_hdr_secret X-API-Token W_TOK -s -X POST -A "$UA" \
          -F "file=@$WORK/F66-read-$TAG.txt" -F 'level=internal' "$BASE/api/upload")
    W_FILE=$(uuid_of "$OUT")
    [ -n "$W_FILE" ] && MADE_FILES="$MADE_FILES $W_FILE"
    expect "read+upload: POST /api/upload 가 파일을 만들었다" "1" "$( [ -n "$W_FILE" ] && echo 1 || echo 0 )"
    OUT=$(curl_hdr_secret X-API-Token W_TOK -s -X POST -A "$UA" -H 'Content-Type: application/json' \
          -d "{\"name\":\"F66-tkw-$TAG\",\"access_level\":\"internal\"}" "$BASE/api/folders")
    W_FOLDER=$(fid_of "$OUT")
    [ -n "$W_FOLDER" ] && MADE_FOLDERS="$MADE_FOLDERS $W_FOLDER"
    expect "read+upload: POST /api/folders 가 폴더를 만들었다" "1" "$( [ -n "$W_FOLDER" ] && echo 1 || echo 0 )"
    [ -n "$C_FOLDER" ] && expect "read+upload: PATCH /api/folders/{num}" "200" "$(tk "$W_TOK" pfolder)"
    # 토큰 경로의 PATCH 는 폴더 이동 하나다 — 시험 폴더(같은 등급)로 옮긴다
    if [ -n "$C_FILE" ] && [ -n "$C_FOLDER" ]; then
      expect "read+upload: PATCH /api/files/{id} (folder_id — 토큰 경로 허용 키)" "200" "$(tk "$W_TOK" pfile)"
    else skip "(c) read+upload PATCH 파일" "시험 파일 또는 폴더가 없다"; fi
    [ -n "$C_FILE" ]   && expect "read+upload: DELETE /api/files/{id} → 403" "403" "$(tk "$W_TOK" dfile)"
    [ -n "$C_FOLDER" ] && expect "read+upload: DELETE /api/folders/{num} → 403" "403" "$(tk "$W_TOK" dfolder)"
  fi

  # ── read+upload+delete 토큰 ──────────────────────────────────
  sub "read+upload+delete 토큰 — 열 전부 통과 · 그래도 Authz 판정은 그대로"
  issue "F66-c-rud-$TAG" "T-78 (c) read+upload+delete" "read+upload+delete"
  D_TOK="$ISSUED_TOKEN"; D_TID="$ISSUED_ID"
  if [ -z "$D_TOK" ]; then
    skip "(c) read+upload+delete 토큰" "발급 실패 [$ISSUED_CODE]"
  else
    expect "rud: GET /api/whoami" "200" "$(tk "$D_TOK" whoami)"
    OUT=$(curl_hdr_secret X-API-Token D_TOK -s -X POST -A "$UA" \
          -F "file=@$WORK/F66-read-$TAG.txt" -F 'level=internal' "$BASE/api/upload")
    D_FILE=$(uuid_of "$OUT")
    if [ -z "$D_FILE" ]; then
      skip "(c) rud 자기 파일 DELETE" "업로드가 id 를 주지 않았다"
    else
      C=$(curl_hdr_secret X-API-Token D_TOK -s -o /dev/null -w '%{http_code}' -X DELETE -A "$UA" "$BASE/api/files/$D_FILE")
      expect "rud: 자기 파일 DELETE → 200" "200" "$C"
      [ "$C" = "200" ] || MADE_FILES="$MADE_FILES $D_FILE"
    fi
    OUT=$(curl_hdr_secret X-API-Token D_TOK -s -X POST -A "$UA" -H 'Content-Type: application/json' \
          -d "{\"name\":\"F66-tkd-$TAG\",\"access_level\":\"internal\"}" "$BASE/api/folders")
    D_FOLDER=$(fid_of "$OUT")
    if [ -z "$D_FOLDER" ]; then
      skip "(c) rud 빈 폴더 DELETE" "폴더를 만들지 못했다"
    else
      C=$(curl_hdr_secret X-API-Token D_TOK -s -o /dev/null -w '%{http_code}' -X DELETE -A "$UA" "$BASE/api/folders/$D_FOLDER")
      expect "rud: 자기 빈 폴더 DELETE → 200" "200" "$C"
      [ "$C" = "200" ] || MADE_FOLDERS="$MADE_FOLDERS $D_FOLDER"
    fi

    # ★ 범위 안이라도 파일별 판정은 그대로다 — 남의 파일은 403 「권한이 없습니다」
    printf 'F-66 관리자 파일입니다. %s\n' "$TAG" > "$WORK/F66-adm-$TAG.txt"
    OUT=$(curl -s -X POST -b "$JAR_DIR/claude_admin01.jar" -A "$UA" -H "X-CSRF-Token: $ADM_CSRF" \
          -F "file=@$WORK/F66-adm-$TAG.txt" -F 'access_level=internal' "$BASE/api/files")
    A_FILE=$(uuid_of "$OUT")
    if [ -z "$A_FILE" ]; then
      skip "(c) 남의 파일 DELETE 403" "관리자 업로드가 id 를 주지 않았다"
    else
      MADE_FILES="$MADE_FILES $A_FILE"
      R=$(curl_hdr_secret X-API-Token D_TOK -s -w '\n%{http_code}' -X DELETE -A "$UA" "$BASE/api/files/$A_FILE")
      expect "rud: 남의 파일 DELETE → 403 (범위가 아니라 Authz)" "403" "$(code_of "$R")"
      expect "rud: 그 문구는 「권한이 없습니다」" '{"error":"권한이 없습니다"}' "$(body_of "$R")"
      if [ "$HAVE_PSQL" -eq 1 ]; then
        expect "rud: 감사 reason=not_owner (범위 거부가 아니다)" "t" \
          "$(psqlq "SELECT count(*) > 0 FROM audit_logs WHERE action='file.delete' AND result='deny' AND reason='not_owner' AND happened_at > '$T2'::timestamptz")"
      fi
    fi
  fi

  # ── 감사 — 범위 거부 행 ──────────────────────────────────────
  if [ "$HAVE_PSQL" -eq 0 ]; then
    skip "(c) 감사 scope_not_permitted" "psql 없음"
  else
    expect "(c) 감사 auth.token deny scope_not_permitted" "t" \
      "$(psqlq "SELECT count(*) > 0 FROM audit_logs WHERE action='auth.token' AND result='deny' AND reason='scope_not_permitted' AND happened_at > '$T2'::timestamptz")"
    expect "(c) 그 행의 detail 에 token_scope·token_id" "t" \
      "$(psqlq "SELECT count(*) > 0 FROM audit_logs WHERE action='auth.token' AND reason='scope_not_permitted' AND happened_at > '$T2'::timestamptz AND detail ? 'token_scope' AND detail ? 'token_id'")"
    expect "(c) 그 행의 target_name 은 라우트 패턴" "t" \
      "$(psqlq "SELECT count(*) > 0 FROM audit_logs WHERE action='auth.token' AND reason='scope_not_permitted' AND happened_at > '$T2'::timestamptz AND target_name LIKE '% /api/%{%' OR (action='auth.token' AND reason='scope_not_permitted' AND happened_at > '$T2'::timestamptz AND target_name IN ('POST /api/upload','POST /api/folders'))")"
    expect "(c) 통과한 행에 token_scope 가 붙는다 (autoDetail)" "t" \
      "$(psqlq "SELECT count(*) > 0 FROM audit_logs WHERE happened_at > '$T2'::timestamptz AND detail->>'token_kind'='personal' AND detail ? 'token_scope'")"
  fi
fi

# ─── (d) 만료 ────────────────────────────────────────────────────
hr "(d) 만료 — 401 문구가 회수·틀린 토큰과 갈린다"
if [ "$MIG" -eq 0 ]; then
  skip "(d) 전체" "운영 DB 에 api_tokens.scope 열이 없다"
elif [ "$HAVE_PSQL" -eq 0 ]; then
  skip "(d) 전체" "psql 을 쓸 수 없다 — 시계를 돌릴 방법이 없다"
else
  T3=$(psqlq "SELECT now()")
  # 단위 확인 — SQL 조건 `expires_at <= now()` 에 어제를 넣으면 참이다
  expect "(d) SQL 조건 단위 확인 (어제 <= now())" "t" \
    "$(psqlq "SELECT ((now() - interval '1 day') <= now())")"

  sub "만료된 토큰"
  issue "F66-d-exp-$TAG" "T-78 (d) 만료 시험" "read"
  E_TOK="$ISSUED_TOKEN"; E_TID="$ISSUED_ID"
  if [ -z "$E_TOK" ] || [ -z "$E_TID" ]; then
    skip "(d) 만료" "토큰 발급 실패 [$ISSUED_CODE]"
  else
    # ★ 시험이 방금 만든 F66- 토큰 **한 개**만 — 이름 조건과 rowCount 로 좁힌다
    RC=$(sudo -n -u postgres psql -d "$DB" -t -A -c \
         "UPDATE api_tokens SET expires_at = now() - interval '1 minute' WHERE id = $E_TID AND name LIKE 'F66-%'" 2>/dev/null)
    note "UPDATE 결과: ${RC:-?}"
    expect "(d) UPDATE 가 한 행만 고쳤다" "UPDATE 1" "${RC:-?}"

    R=$(curl_hdr_secret X-API-Token E_TOK -s -w '\n%{http_code}' -A "$UA" "$BASE/api/whoami")
    expect "(d) 만료된 토큰 → 401" "401" "$(code_of "$R")"
    expect "(d) 만료 문구" '{"error":"만료된 API 토큰입니다 — 내 계정에서 새로 발급하세요"}' "$(body_of "$R")"
    expect "(d) 감사 auth.token deny token_expired" "t" \
      "$(psqlq "SELECT count(*) > 0 FROM audit_logs WHERE action='auth.token' AND result='deny' AND reason='token_expired' AND happened_at > '$T3'::timestamptz")"

    R=$(curl -s -w '\n%{http_code}' -A "$UA" -H "X-API-Token: $WRONG_TOKEN" "$BASE/api/whoami")
    expect "(d) 틀린 토큰 → 401" "401" "$(code_of "$R")"
    expect "(d) 틀린 토큰 문구 (만료와 다르다)" '{"error":"API 토큰이 올바르지 않습니다"}' "$(body_of "$R")"

    revoke_mine "$E_TID"
    R=$(curl_hdr_secret X-API-Token E_TOK -s -w '\n%{http_code}' -A "$UA" "$BASE/api/whoami")
    expect "(d) 회수된(그리고 만료된) 토큰 → 401" "401" "$(code_of "$R")"
    expect "(d) 회수된 토큰 문구 == 틀린 토큰 문구" '{"error":"API 토큰이 올바르지 않습니다"}' "$(body_of "$R")"
  fi

  sub "expires_soon — 만료 14일 안"
  issue "F66-d-soon-$TAG" "T-78 (d) expires_soon 시험" "read"
  S_TOK="$ISSUED_TOKEN"; S_TID="$ISSUED_ID"
  if [ -z "$S_TID" ]; then
    skip "(d) expires_soon" "토큰 발급 실패 [$ISSUED_CODE]"
  else
    RC=$(sudo -n -u postgres psql -d "$DB" -t -A -c \
         "UPDATE api_tokens SET expires_at = now() + interval '7 days' WHERE id = $S_TID AND name LIKE 'F66-%'" 2>/dev/null)
    expect "(d) expires_soon UPDATE 가 한 행만" "UPDATE 1" "${RC:-?}"
    curl -s -b "$JAR_DIR/claude_employee01.jar" -A "$UA" "$BASE/api/me/api-tokens" > "$WORK/soon.json"
    expect "(d) 목록의 그 행 expires_soon:true" "True" \
      "$(jq_py "str(next((t['expires_soon'] for t in d['tokens'] if str(t['id'])=='$S_TID'), '없음'))" < "$WORK/soon.json")"
    revoke_mine "$S_TID"
  fi
fi

# ─── (e) 회수 ────────────────────────────────────────────────────
hr "(e) 회수 — 본인 · 남의 tid · 관리자"
if [ "$MIG" -eq 0 ]; then
  skip "(e) 전체" "운영 DB 에 api_tokens.scope 열이 없다"
else
  T4=$(psqlq "SELECT now()")
  if [ -z "${R_TID:-}" ] || [ -z "${R_TOK:-}" ]; then
    skip "(e) 본인 회수" "(c) 의 read 토큰이 없다"
  else
    R=$(curl -s -w '\n%{http_code}' -X DELETE -b "$JAR_DIR/claude_employee01.jar" -A "$UA" \
        -H "X-CSRF-Token: $EMP_CSRF" "$BASE/api/me/api-tokens/$R_TID")
    expect "(e) 본인 회수 → 200" "200" "$(code_of "$R")"
    expect "(e) 본인 회수 본문" '{"ok":true}' "$(body_of "$R")"

    R=$(curl_hdr_secret X-API-Token R_TOK -s -w '\n%{http_code}' -A "$UA" "$BASE/api/whoami")
    expect "(e) 회수된 토큰 → 401" "401" "$(code_of "$R")"
    expect "(e) 회수 문구 (만료와 다르다)" '{"error":"API 토큰이 올바르지 않습니다"}' "$(body_of "$R")"

    R=$(curl -s -w '\n%{http_code}' -X DELETE -b "$JAR_DIR/claude_employee01.jar" -A "$UA" \
        -H "X-CSRF-Token: $EMP_CSRF" "$BASE/api/me/api-tokens/$R_TID")
    expect "(e) 다시 회수 → 400" "400" "$(code_of "$R")"

    if [ "$HAVE_PSQL" -eq 1 ]; then
      expect "(e) revoked_by == 본인" "t" \
        "$(psqlq "SELECT revoked_by = $EMP_UID FROM api_tokens WHERE id = $R_TID")"
      expect "(e) 감사 api_token.revoke allow" "t" \
        "$(psqlq "SELECT count(*) > 0 FROM audit_logs WHERE action='api_token.revoke' AND result='allow' AND actor_name='claude_employee01' AND happened_at > '$T4'::timestamptz")"
    fi
  fi

  # 남의 tid → 404 (그 번호가 존재하는지도 새지 않는다)
  OTHER_TID=''
  [ "$HAVE_PSQL" -eq 1 ] && OTHER_TID=$(psqlq "SELECT id FROM api_tokens WHERE user_id <> ${EMP_UID:-0} ORDER BY id DESC LIMIT 1")
  [ -n "$OTHER_TID" ] || OTHER_TID=999999999
  R=$(curl -s -w '\n%{http_code}' -X DELETE -b "$JAR_DIR/claude_employee01.jar" -A "$UA" \
      -H "X-CSRF-Token: $EMP_CSRF" "$BASE/api/me/api-tokens/$OTHER_TID")
  expect "(e) 남의(또는 없는) tid → 404" "404" "$(code_of "$R")"
  expect "(e) 그 문구" '{"error":"찾을 수 없습니다"}' "$(body_of "$R")"

  # 관리자 회수는 그대로 200
  if [ -z "${W_TID:-}" ] || [ -z "${EMP_UID:-}" ]; then
    skip "(e) 관리자 회수" "(c) 의 read+upload 토큰 또는 uid 가 없다"
  else
    note "재인증: claude_admin01 → $(reauth claude_admin01 "$ADM_CSRF")"
    R=$(curl -s -w '\n%{http_code}' -X DELETE -b "$JAR_DIR/claude_admin01.jar" -A "$UA" \
        -H "X-CSRF-Token: $ADM_CSRF" "$BASE/api/users/$EMP_UID/api-tokens/$W_TID")
    expect "(e) 관리자 회수 → 200 (그대로)" "200" "$(code_of "$R")"
  fi
fi

# ─── (f) 닫힘 ────────────────────────────────────────────────────
hr "(f) 닫힘 — 토큰이 토큰을 낳지 않는다 · 관리자 창구 · 공용 토큰"
if [ "$MIG" -eq 0 ]; then
  skip "(f) 전체" "운영 DB 에 api_tokens.scope 열이 없다"
else
  if [ -z "${D_TOK:-}" ]; then
    skip "(f) 개인 토큰으로 본인 발급 창구" "rud 토큰이 없다"
  else
    R=$(curl_hdr_secret X-API-Token D_TOK -s -w '\n%{http_code}' -X POST -A "$UA" \
        -H 'Content-Type: application/json' \
        -d '{"name":"F66-f","purpose":"닫힘 시험","scope":"read","password":"x","otp":"000000"}' \
        "$BASE/api/me/api-tokens")
    expect "(f) 개인 토큰으로 POST /api/me/api-tokens → 401 (토큰 집합 밖)" "401" "$(code_of "$R")"
    expect "(f) 그 문구는 세션 경로의 것" '{"error":"로그인이 필요합니다"}' "$(body_of "$R")"
  fi

  R=$(curl -s -w '\n%{http_code}' -X POST -b "$JAR_DIR/claude_admin01.jar" -A "$UA" \
      -H "X-CSRF-Token: $ADM_CSRF" -H 'Content-Type: application/json' \
      -d '{"name":"F66-f2","reason_text":"관리자 발급 창구"}' "$BASE/api/users/${EMP_UID:-1}/api-tokens")
  # 같은 경로의 목록(GET)이 남아 있으므로 라우터 [1] 의 「메서드만 다르다」 갈래가 먼저 잡는다
  expect "(f) POST /api/users/{uid}/api-tokens → 405 (POST 라우트 제거 · GET 은 남음)" "405" "$(code_of "$R")"
  expect "(f) 그 문구" '{"error":"허용되지 않는 메서드입니다"}' "$(body_of "$R")"

  if [ -z "${EMP_UID:-}" ]; then
    skip "(f) 관리자 목록에 scope·expires_at" "uid 를 찾지 못했다"
  else
    note "재인증: claude_admin01 → $(reauth claude_admin01 "$ADM_CSRF")"
    curl -s -b "$JAR_DIR/claude_admin01.jar" -A "$UA" "$BASE/api/users/$EMP_UID/api-tokens" > "$WORK/alist.json"
    expect "(f) 관리자 목록 행의 키 (scope·expires_at · Q:F-9 bound_ip · Q:F-10 scopes 추가)" \
      "bound_ip,created_at,expires_at,hint,id,last_used_at,name,revoked_at,scope,scopes" \
      "$(jq_py "','.join(sorted(d['tokens'][0].keys())) if d.get('tokens') else '(빈 목록)'" < "$WORK/alist.json")"
    expect "(f) 관리자 목록에 token 키 없음" "0" "$(grep -c '"token"' "$WORK/alist.json")"
  fi

  if [ "$USE_SHARED" -eq 0 ]; then
    skip "(f) 공용 토큰은 read+upload" "공용 토큰 귀속이 검토 계정(qa_·claude_)이 아니다 — 값은 읽지 않았다"
  else
    expect "(f) 공용 토큰 GET /api/files → 200" "200" \
      "$(curl_hdr_secret X-API-Token SHARED_TOKEN -s -o /dev/null -w '%{http_code}' -A "$UA" "$BASE/api/files?limit=1")"
    OUT=$(curl_hdr_secret X-API-Token SHARED_TOKEN -s -X POST -A "$UA" \
          -F "file=@$WORK/F66-read-$TAG.txt" -F 'level=internal' "$BASE/api/upload")
    F_FILE=$(uuid_of "$OUT")
    [ -n "$F_FILE" ] && MADE_FILES="$MADE_FILES $F_FILE"
    expect "(f) 공용 토큰 POST /api/upload 가 파일을 만들었다 (read+upload)" "1" \
      "$( [ -n "$F_FILE" ] && echo 1 || echo 0 )"
    # 삭제는 범위보다 **앞**의 관문(개인 전용 창구)이 먼저 막는다 — F-59 그대로다
    R=$(curl_hdr_secret X-API-Token SHARED_TOKEN -s -w '\n%{http_code}' -X DELETE -A "$UA" \
        "$BASE/api/files/${F_FILE:-00000000-0000-4000-8000-000000000000}")
    expect "(f) 공용 토큰 DELETE → 403" "403" "$(code_of "$R")"
    expect "(f) 그 문구는 개인 전용 창구 문구 (범위보다 앞의 관문)" \
      '{"error":"이 창구는 개인 토큰으로만 쓸 수 있습니다"}' "$(body_of "$R")"
  fi
fi

# ─── (g) 화면 (Playwright) ───────────────────────────────────────
hr "(g) 화면 — 「프로그램 토큰」 절 · 범위 선택지 · 한 번 표시 · 콘솔 오류 0"
if [ "$MIG" -eq 0 ]; then
  skip "(g) 전체" "운영 DB 에 api_tokens.scope 열이 없다"
else
  cat > "$WORK/otp.sh" <<OTPSH
#!/bin/sh
sec=\$(sudo -n awk -v u="\$1" '\$1==u{print \$2}' /etc/tbox-v2/qa-totp.txt 2>/dev/null)
[ -n "\$sec" ] || exit 1
# 비밀은 표준입력으로(인자는 ps 로 보인다 — R-62). printf 는 /bin/sh(dash) 내장이다
printf '%s\n' "\$sec" | php "$ROOT/scripts/totp_code.php" -
OTPSH
  chmod +x "$WORK/otp.sh"

  cat > "$WORK/g-screen.py" <<'PY'
import json, re, subprocess, sys, time

base, ua, who, otpsh, chrome, tag = sys.argv[1:7]
# 비밀번호는 표준입력 첫 줄로만 받는다(argv 는 ps 로 보인다 — R-62). 부르는 쪽은 py_secret
pw = sys.stdin.readline().rstrip('\n')

try:
    from playwright.sync_api import sync_playwright
except Exception as e:
    print(json.dumps({'PLAYWRIGHT_MISSING': str(e)})); sys.exit(0)


def fresh_code():
    """**다음 30초 창**의 코드 (R-45 1회성 — 방금 쓴 코드는 다시 못 쓴다)."""
    out = subprocess.run([otpsh, who], capture_output=True, text=True).stdout
    left = re.search(r'남은 시간: (\d+)', out)
    time.sleep(int(left.group(1)) + 1 if left else 31)
    out = subprocess.run([otpsh, who], capture_output=True, text=True).stdout
    return out.splitlines()[0].strip()


js_errs = []
bad_reqs = []
out = {}


def on_console(m):
    if m.type != 'error':
        return
    if m.text.startswith('Failed to load resource'):
        return
    js_errs.append(m.text)

launch = {'args': ['--no-sandbox']}
if chrome:
    launch['executable_path'] = chrome

with sync_playwright() as p:
    b = p.chromium.launch(**launch)
    ctx = b.new_context(user_agent=ua)
    page = ctx.new_page()
    page.on('console', on_console)
    page.on('pageerror', lambda e: js_errs.append('pageerror: ' + str(e)))
    page.on('response', lambda r: bad_reqs.append('%s %s' % (r.status, r.url)) if r.status >= 400 else None)
    page.on('dialog', lambda d: d.accept())

    page.goto(base + '/login', wait_until='networkidle')
    page.fill('#username', who)
    page.fill('#password', pw)
    page.click('#login-submit')
    page.wait_for_timeout(1200)
    if page.is_visible('#otp'):
        page.fill('#otp', fresh_code())
        page.click('#login-submit')
        page.wait_for_timeout(2000)

    page.goto(base + '/settings', wait_until='networkidle')
    page.wait_for_timeout(1200)

    me = page.evaluate("""async () => {
        const r = await fetch('/tbox/api/me', {credentials: 'same-origin', cache: 'no-store'});
        return r.json();
    }""")
    out['me_scopes'] = me.get('token_scopes', [])
    out['me_can_issue'] = me.get('can_issue_tokens')

    out['section'] = page.locator('#token-issue-form').count()
    out['table'] = page.locator('#my-tokens-body').count()
    out['scope_options'] = page.locator('#token-scope option').all_text_contents()
    out['scope_values'] = page.eval_on_selector_all(
        '#token-scope option', 'els => els.map(e => e.value)')
    out['form_visible'] = page.locator('#token-issue-form').is_visible()
    out['blocked_visible'] = page.locator('#token-issue-blocked').is_visible()
    out['head_title'] = page.locator('.panel__title').all_text_contents()
    out['thead'] = page.eval_on_selector_all(
        '#my-tokens-body', 'els => els.length ? Array.from(els[0].closest("table").querySelectorAll("thead th")).map(t => t.textContent.trim()) : []')
    out['inline_style'] = page.eval_on_selector_all('[style]', 'els => els.length')

    # ── 발급 — 값은 한 번만 보이고 **본문 텍스트에는 없어야 한다** ──
    page.fill('#token-name', 'F66-ui-' + tag)
    page.fill('#token-purpose', 'T-78 (g) 화면 시험')
    page.select_option('#token-scope', 'read')
    page.fill('#token-password', pw)
    page.fill('#token-otp', fresh_code())
    page.click('#token-issue')
    page.wait_for_timeout(3000)

    out['once_box'] = page.locator('#api-token-once').count()
    val = page.eval_on_selector('#api-token-once input', 'e => e.value') if out['once_box'] else ''
    out['token_len'] = len(val or '')
    body_text = page.inner_text('body')
    out['token_in_body_text'] = body_text.count(val) if val else -1
    out['msg'] = page.locator('#token-issue-msg').text_content()
    out['password_cleared'] = page.eval_on_selector('#token-password', 'e => e.value') == ''
    out['otp_cleared'] = page.eval_on_selector('#token-otp', 'e => e.value') == ''
    out['rows'] = page.locator('#my-tokens-body tr').count()
    out['row_text'] = page.locator('#my-tokens-body tr').last.inner_text()
    out['revoke_buttons'] = page.locator('#my-tokens-body td.actions button').count()

    out['errors'] = js_errs
    out['bad_requests'] = bad_reqs
    b.close()

print(json.dumps(out, ensure_ascii=False))
PY

  PW_CHROME=""
  for cand in "$HOME"/.cache/ms-playwright/chromium-*/chrome-linux64/chrome \
              "$HOME"/.cache/ms-playwright/chromium-*/chrome-linux/chrome; do
    [ -x "$cand" ] && { PW_CHROME="$cand"; break; }
  done
  note "크로미움: ${PW_CHROME:-(playwright 기본값)}"

  # ★ stderr 는 **따로** 받는다 — 표준출력에 섞으면 JSON 이 깨진다 (f65.sh 실측)
  G_PW=$(qa_password claude_employee01)
  py_secret G_PW -- python3 "$WORK/g-screen.py" "$BASE" "$UA" "claude_employee01" \
    "$WORK/otp.sh" "$PW_CHROME" "$TAG" > "$WORK/g.json" 2> "$WORK/g.err"
  G_RC=$?
  G_PW=''
  if [ "$G_RC" -ne 0 ] || [ ! -s "$WORK/g.json" ]; then
    printf '  ! (g) 파이썬이 실패했다 (rc=%s) — stderr:\n' "$G_RC"
    tail -20 "$WORK/g.err" | sed 's/^/      /'
  fi
  if grep -q 'PLAYWRIGHT_MISSING' "$WORK/g.json" 2>/dev/null; then
    skip "(g) 전체" "playwright 가 없다 — 미검증 (브라우저 있는 환경에서 다시 실행)"
  elif [ ! -s "$WORK/g.json" ]; then
    skip "(g) 전체" "브라우저 시험이 결과를 내지 못했다 — 위 stderr 참고"
  else
    gq() { jq_py "$1" < "$WORK/g.json"; }
    expect "(g) 「프로그램 토큰」 절이 있다" "1" "$(gq "d.get('section',0)")"
    expect "(g) 내 토큰 표가 있다" "1" "$(gq "d.get('table',0)")"
    expect "(g) 표 머리 여덟 칸" "이름,용도,앞 6자,범위,만료,마지막 사용,상태,작업" \
      "$(gq "','.join(d.get('thead',[]))")"
    expect "(g) 범위 선택지 == /api/me 의 token_scopes" "t" \
      "$(gq "'t' if d.get('scope_values') == d.get('me_scopes') else 'f (%s != %s)' % (d.get('scope_values'), d.get('me_scopes'))")"
    expect "(g) 범위 라벨 한글" "읽기만,읽기 + 올리기,읽기 + 올리기 + 지우기" \
      "$(gq "','.join(d.get('scope_options',[]))")"
    expect "(g) 2단계가 켜진 계정에는 폼이 보인다" "True" "$(gq "str(d.get('form_visible'))")"
    expect "(g) 그때 안내 줄은 숨는다" "False" "$(gq "str(d.get('blocked_visible'))")"
    expect "(g) 인라인 style 0" "0" "$(gq "d.get('inline_style',-1)")"
    expect "(g) 발급 뒤 한 번 표시 상자" "1" "$(gq "d.get('once_box',0)")"
    expect "(g) 그 칸의 값이 48자" "48" "$(gq "d.get('token_len',0)")"
    expect "(g) **본문 텍스트에 토큰 0회**" "0" "$(gq "d.get('token_in_body_text',-1)")"
    expect "(g) 비밀번호 칸 즉시 비움" "True" "$(gq "str(d.get('password_cleared'))")"
    expect "(g) 코드 칸 즉시 비움" "True" "$(gq "str(d.get('otp_cleared'))")"
    expect "(g) 표에 범위 라벨이 보인다" "1" "$(gq "1 if '읽기만' in d.get('row_text','') else 0")"
    expect "(g) 회수 단추가 있다" "t" "$(gq "'t' if d.get('revoke_buttons',0) >= 1 else 'f'")"
    expect "(g) 콘솔 자바스크립트 오류 0" "0" "$(gq "len(d.get('errors',[]))")"
    [ "$(gq "len(d.get('errors',[]))")" = "0" ] || gq "d.get('errors')" | sed 's/^/      /'
    note "400 이상 응답(일부는 의도한 거부): $(gq "len(d.get('bad_requests',[]))")건"
  fi
fi

# ─── 정리 (트랩보다 먼저 — 결과를 눈으로 보려고) ────────────────
cleanup_artifacts

# ─── (h) 회귀 ────────────────────────────────────────────────────
hr "(h) 회귀 — 임시 DB 마이그레이션 · 린트 · php -l · bash -n · AL-20"

sub "임시 DB 마이그레이션 (운영 DB 실행은 사용자 몫)"
if sudo -n -u postgres psql -l >/dev/null 2>&1; then
  sudo -n -u postgres dropdb --if-exists "$TMPDB" >/dev/null 2>&1
  if sudo -n -u postgres createdb "$TMPDB" >/dev/null 2>&1; then
    # postgres 가 홈을 못 읽는 환경이 있어 -f 대신 표준입력으로 넣는다
    sudo -n -u postgres psql -d "$TMPDB" -v ON_ERROR_STOP=1 -q < "$ROOT/db/schema.sql" \
      > "$WORK/schema.out" 2>&1
    expect "(h) schema.sql 적용 rc" "0" "$?"
    expect "(h) 새 DB api_tokens 에 scope·expires_at" "2" \
      "$(sudo -n -u postgres psql -d "$TMPDB" -t -A -c "SELECT count(*) FROM information_schema.columns WHERE table_name='api_tokens' AND column_name IN ('scope','expires_at')" 2>/dev/null)"
    expect "(h) 두 열 NOT NULL" "0" \
      "$(sudo -n -u postgres psql -d "$TMPDB" -t -A -c "SELECT count(*) FROM information_schema.columns WHERE table_name='api_tokens' AND column_name IN ('scope','expires_at') AND is_nullable='YES'" 2>/dev/null)"
    expect "(h) scope CHECK 제약" "1" \
      "$(sudo -n -u postgres psql -d "$TMPDB" -t -A -c "SELECT count(*) FROM pg_constraint WHERE conname='api_tokens_scope_check'" 2>/dev/null)"

    # ── 옛 모양으로 되돌린 뒤 마이그레이션을 적용한다 (기존 행이 채워지는지) ──
    sudo -n -u postgres psql -d "$TMPDB" -v ON_ERROR_STOP=1 -q -c "
      ALTER TABLE api_tokens DROP CONSTRAINT IF EXISTS api_tokens_scope_check;
      ALTER TABLE api_tokens DROP COLUMN scope;
      ALTER TABLE api_tokens DROP COLUMN expires_at;
      INSERT INTO users (username, password_hash, role, is_active) VALUES ('f66_tmp_owner','x','Admin',true);
      INSERT INTO api_tokens (user_id, name, token_hash, hint, created_by, created_at, reason_text)
        SELECT id, 'F66-old', repeat('a',64), 'aaaaaa', id, now() - interval '200 days', '옛 토큰'
          FROM users WHERE username='f66_tmp_owner';
    " > "$WORK/old.out" 2>&1
    expect "(h) 옛 모양으로 되돌리기 rc" "0" "$?"
    expect "(h) 되돌린 뒤 두 열 없음" "0" \
      "$(sudo -n -u postgres psql -d "$TMPDB" -t -A -c "SELECT count(*) FROM information_schema.columns WHERE table_name='api_tokens' AND column_name IN ('scope','expires_at')" 2>/dev/null)"

    sudo -n -u postgres psql -d "$TMPDB" -v ON_ERROR_STOP=1 -q \
      < "$ROOT/db/migrations/2026-09-22_api_tokens_scope_expiry.sql" > "$WORK/mig.out" 2>&1
    expect "(h) 마이그레이션 적용 rc" "0" "$?"
    expect "(h) 마이그레이션 뒤 두 열" "2" \
      "$(sudo -n -u postgres psql -d "$TMPDB" -t -A -c "SELECT count(*) FROM information_schema.columns WHERE table_name='api_tokens' AND column_name IN ('scope','expires_at')" 2>/dev/null)"
    expect "(h) 기존 행 scope = read+upload+delete" "read+upload+delete" \
      "$(sudo -n -u postgres psql -d "$TMPDB" -t -A -c "SELECT scope FROM api_tokens WHERE name='F66-old'" 2>/dev/null)"
    expect "(h) 기존 행 expires_at = created_at + 180일" "t" \
      "$(sudo -n -u postgres psql -d "$TMPDB" -t -A -c "SELECT expires_at = created_at + interval '180 days' FROM api_tokens WHERE name='F66-old'" 2>/dev/null)"
    expect "(h) CHECK 가 세 값만 받는다" "1" \
      "$(sudo -n -u postgres psql -d "$TMPDB" -t -A -c "UPDATE api_tokens SET scope='admin' WHERE name='F66-old'" 2>&1 >/dev/null | grep -c 'api_tokens_scope_check')"
    expect "(h) 두 열 NOT NULL (마이그레이션 뒤)" "0" \
      "$(sudo -n -u postgres psql -d "$TMPDB" -t -A -c "SELECT count(*) FROM information_schema.columns WHERE table_name='api_tokens' AND column_name IN ('scope','expires_at') AND is_nullable='YES'" 2>/dev/null)"

    sudo -n -u postgres psql -d "$TMPDB" -v ON_ERROR_STOP=1 -q \
      < "$ROOT/db/migrations/2026-09-22_api_tokens_scope_expiry.sql" > "$WORK/mig2.out" 2>&1
    expect "(h) 마이그레이션 재적용 rc (멱등)" "0" "$?"

    sudo -n -u postgres dropdb "$TMPDB" >/dev/null 2>&1
    note "임시 DB $TMPDB 를 지웠다"
  else
    skip "(h) 임시 DB" "createdb 실패"
  fi
else
  skip "(h) 임시 DB" "sudo -n -u postgres 를 쓸 수 없다"
fi

sub "린트 · php -l · bash -n"
bash "$ROOT/scripts/security_lint.sh" > "$WORK/lint.out" 2>&1
expect "scripts/security_lint.sh (종료 코드)" "0" "$?"
tail -1 "$WORK/lint.out" | sed 's/^/  /'

PHPBAD=0
for f in public/index.php src/Auth.php src/Authz.php src/Http.php src/Audit.php \
         src/handlers/me.php src/handlers/me_tokens_list.php src/handlers/me_tokens_issue.php \
         src/handlers/me_tokens_revoke.php src/handlers/api_tokens_list.php \
         src/handlers/api_tokens_revoke.php src/handlers/settings_page.php \
         src/help/screens.php scripts/alerts.php; do
  php -l "$ROOT/$f" >/dev/null 2>&1 || { echo "  ✖ php -l 실패: $f"; PHPBAD=1; }
done
expect "바뀐 PHP php -l" "0" "$PHPBAD"

expect "지운 핸들러가 없다 (api_tokens_issue.php)" "0" \
  "$( [ -e "$ROOT/src/handlers/api_tokens_issue.php" ] && echo 1 || echo 0 )"

SHBAD=0
for t in f66.sh api_tokens.sh f68.sh api_whoami.sh api_read.sh api_folders.sh stage26.sh help_button.sh; do
  bash -n "$ROOT/tests/$t" 2>/dev/null || { echo "  ✖ bash -n 실패: tests/$t"; SHBAD=1; }
done
expect "bash -n (회귀 시험 여덟)" "0" "$SHBAD"

sub "AL-20 — 개인 토큰 발급 경보 (evidence 에 token_scope)"
if [ "$HAVE_PSQL" -eq 0 ]; then
  skip "(h) AL-20" "psql 을 쓸 수 없다"
elif [ "$MIG" -eq 0 ]; then
  skip "(h) AL-20 1건" "마이그레이션 전이라 이 시험이 발급한 행이 없다 — 규칙 자체는 아래 실행으로 확인한다"
  sudo -n php "$ROOT/scripts/alerts.php" --rule AL-20 --dry-run --state "$WORK/alerts-state.json" \
    > "$WORK/al20.out" 2>&1
  tail -3 "$WORK/al20.out" | sed 's/^/  /'
  expect "(h) AL-20 이 오류 없이 돈다" "1" "$(grep -cE 'AL-20 대상 [0-9]+명' "$WORK/al20.out")"
else
  sudo -n php "$ROOT/scripts/alerts.php" --rule AL-20 --dry-run --state "$WORK/alerts-state.json" \
    > "$WORK/al20.out" 2>&1
  tail -5 "$WORK/al20.out" | sed 's/^/  /'
  expect "(h) AL-20 대상 1명 이상" "1" "$(grep -cE 'AL-20 대상 [1-9][0-9]*명' "$WORK/al20.out")"
  expect "(h) AL-20 근거에 token_scope" "1" "$(grep -c 'token_scope' "$WORK/al20.out")"
  if [ -s "$WORK/pat.txt" ]; then
    expect "(h) AL-20 근거에 토큰·비밀 원문 0건" "0" "$(grep -c -F -f "$WORK/pat.txt" "$WORK/al20.out" 2>/dev/null)"
  fi
fi

# ─── 마무리 ─────────────────────────────────────────────────────
hr "결과"
printf '통과 %d · 실패 %d · 건너뜀/미검증 %d\n' "$PASS" "$FAIL" "$SKIP"
if [ "$MIG" -eq 0 ]; then
  echo "★ (a)~(g) 는 **미검증**이다 — 운영 DB 에 api_tokens.scope 열이 없다."
  echo "  운영 적용(사용자): sudo -u postgres psql -d $DB -v ON_ERROR_STOP=1 -f db/migrations/2026-09-22_api_tokens_scope_expiry.sql"
  echo "  적용 뒤 다시: sudo -v && bash tests/f66.sh"
fi
echo "회귀 다섯(api_tokens.sh · f68.sh · api_whoami.sh · api_read.sh · api_folders.sh)과"
echo "stage26.sh · help_button.sh 는 이 스크립트가 돌리지 않는다 — 따로 실행해 결과를 보고에 적는다."
[ "$FAIL" -eq 0 ] || exit 1
exit 0
