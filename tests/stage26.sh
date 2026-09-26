#!/usr/bin/env bash
# tests/stage26.sh — 26단계 수락 기준 실행 (PRD 12.2 T-52 · T-53 · T-54)
#   T-52 「역할로 보기」(F-39) · T-53 관리 내비·계정 폼(F-40) · T-54 폴더 삭제(F-41)
#
# 규칙 (CLAUDE.md 3장)
#   · **검토 계정(claude_ 접두)만** 쓴다. 시드 계정(admin01 …)은 사용자의 자기 공격 전용이다.
#   · 모든 요청에 `-A "tbox-review/<날짜>"` 를 붙인다 — 감사 로그에서 사람의 행동과 갈라 보려는 것.
#   · 비밀번호·TOTP 비밀은 **필요한 필드만 awk 로** 뽑고 변수에만 담는다 (SECURITY R-62).
#
# ★ 시험 안전 (SECURITY R-73 — 2026-09-13 사고 뒤 의무). 19~24단계 스크립트와 **같은 규칙**이다.
#   ① 최근 10분 감사 로그에 비-qa actor 가 하나라도 있으면 **시작하지 않는다**(종료 코드 3).
#   ② 만드는 것은 전부 `S26-` 접두이고, 정리는 **created_by/uploaded_by 가 qa 계정인 행만** 지운다.
#   ③ 시작·끝에 「비-qa 행 수」를 세어 같은지 보고한다.
#   ④ qa 계정에 잠깐 심는 값(role)은 끝에서 **원래 값으로** 되돌린다.
#
# 준비물: sudo(로 /etc/tbox-v2 읽기 · psql 실행), php(TOTP), curl, python3+playwright(화면 확인)
#
# 사용: sudo -v && bash tests/stage26.sh [출력 폴더]
#   출력 폴더 기본값은 ~/.claude/ui-shots/stage26 (루트 디스크를 쓰지 않는다).
set -u

BASE="http://127.0.0.1:8082/tbox"
UA="tbox-review/$(date +%F)"
DB="tbox_re"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
# 검토 계정 판정 한 벌(qa_·claude_ — F-71 C). 못 읽으면 사전 검사가 빈 값으로 열리므로 시작하지 않는다.
. "$ROOT/tests/lib/review_accounts.sh" || { echo "tests/lib/review_accounts.sh 를 읽지 못했다 — 시험을 시작하지 않는다 (R-73)"; exit 3; }
. "$ROOT/tests/lib/secrets.sh" || { echo "tests/lib/secrets.sh 를 읽지 못했다 — 시험을 시작하지 않는다 (R-62)"; exit 3; }
. "$ROOT/tests/lib/db_read.sh" || { echo "tests/lib/db_read.sh 를 읽지 못했다 — 시험을 시작하지 않는다 (R-73)"; exit 3; }
. "$ROOT/tests/lib/prod_guard.sh" || { echo "tests/lib/prod_guard.sh 를 읽지 못했다 — 시험을 시작하지 않는다(운영 파일 가드)"; exit 3; }
# 정리 때 S26- 파일 바이트를 지우는 저장소 자리 — 운영 대상일 때만 운영 저장소가 기본값(디버깅 묶음 T 9번 · 2026-09-26 사고와 같은 부류).
#   임시 대상(회귀 틀이 BASE·DB 를 바꾼 사본)이면 S26_STORAGE_DIR 을 준 때만 그 자리, 아니면 바이트는 지우지 않는다(임시 저장소는 그 틀이 통째로 지운다).
#   대상을 가를 수 없으면 시작하지 않는다. 지우기 직전에 prod_write_guard 로 다시 본다.
case "$(pg_target_kind)" in
  prod)
    # 운영 대상에 S26_STORAGE_DIR 을 주면 운영 저장소에 S26- 바이트가 고아로 남는다 — 거부. 운영 상태를 바꾸기 전에 쓰기 가드를 한 번 먼저(독립 검토 중간 1)
    [ -z "${S26_STORAGE_DIR:-}" ] || { echo "운영 대상에는 S26_STORAGE_DIR 을 줄 수 없다(운영 저장소에 S26- 바이트가 남는다) — 시험을 시작하지 않는다"; exit 3; }
    S26_STORAGE="$PG_PROD_STORAGE"
    prod_write_guard "$S26_STORAGE" || { echo "운영 파일 쓰기 가드가 막는다 — 운영 상태를 바꾸기 전에 시험을 시작하지 않는다"; exit 3; } ;;
  temp) S26_STORAGE="${S26_STORAGE_DIR:-}" ;;
  *) echo "대상이 운영인지 임시인지 가를 수 없다(BASE=$BASE · DB=$DB) — 시험을 시작하지 않는다(운영 파일 가드)"; exit 3 ;;
esac
OUT="${1:-$HOME/.claude/ui-shots/stage26}"
JAR_DIR="$(mktemp -d)"
WORK="$(mktemp -d)"
PASS=0
FAIL=0
. "$ROOT/tests/lib/common.sh"   # result_line (점검 봇 1-A A-3) — 셸 설정·trap 을 바꾸지 않는다

mkdir -p "$OUT"

# SQL 은 명령 인자가 아니라 **표준입력**으로 넘긴다 — 세션 키(SID_ADMIN = sessions.id)가 sudo 인증 로그(auth.log COMMAND=)·
# 프로세스 목록에 남지 않게(점검 봇 R15 · BF-0013, 2026-09-25). printf 는 bash 내장이라 새 프로세스의 인자에 실리지 않는다.
psqlq() { printf '%s\n' "$1" | sudo -n -u postgres psql -X -d "$DB" -t -A -f -; }
psqlc() { printf '%s\n' "$1" | sudo -n -u postgres psql -X -d "$DB" -v ON_ERROR_STOP=1 -q -f -; }

hr()  { printf '\n════════════════════════════════════════════════════════════\n%s\n════════════════════════════════════════════════════════════\n' "$1"; }
sub() { printf '\n── %s\n' "$1"; }

# $1 설명  $2 기대  $3 실제
expect() {
  if [ "$2" = "$3" ]; then
    printf '  ✔ %s  (기대=%s 실제=%s)\n' "$1" "$2" "$3"; PASS=$((PASS+1))
  else
    printf '  ✖ %s  (기대=%s 실제=%s)\n' "$1" "$2" "$3"; FAIL=$((FAIL+1))
  fi
}

# ── 로그인 ────────────────────────────────────────────────────
# TOTP 코드는 1회성이다(R-45) — 같은 창에서 두 번 쓰면 거부되는 것이 **정상**이라 기다렸다 다시 한다.
login() {
  local who="$1" jar="$JAR_DIR/$1.jar" pw sec raw code left out try body
  pw=$(sudo -n awk -v u="$who" '$1==u{print $3}' /etc/tbox-v2/seed-accounts.txt)
  sec=$(sudo -n awk -v u="$who" '$1==u{print $2}' /etc/tbox-v2/qa-totp.txt)
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
    printf '  · %s 코드 재사용으로 거부됨 — 다음 창까지 %s초 기다림 (R-45 가 의도대로 막은 것)\n' "$who" "$left" >&2
    sleep $((left + 1))
  done
  printf '  ! %s 로그인 실패: %s\n' "$who" "$out" >&2
  return 1
}

reauth() {
  local who="$1" token="$2" pw body
  pw=$(sudo -n awk -v u="$who" '$1==u{print $3}' /etc/tbox-v2/seed-accounts.txt)
  printf -v body '{"password":"%s"}' "$pw"
  curl_json_secret body -s -o /dev/null -w '%{http_code}' -b "$JAR_DIR/$who.jar" -A "$UA" \
    -H 'Content-Type: application/json' -H "X-CSRF-Token: $token" "$BASE/api/me/reauth"
}

# $1 계정 $2 메서드 $3 경로 $4 토큰 $5 본문(선택) → "본문\nHTTP코드"
call() {
  local who="$1" method="$2" path="$3" token="$4" body="${5-}"
  if [ -n "$body" ]; then
    curl -s -w '\n%{http_code}' -X "$method" -b "$JAR_DIR/$who.jar" -A "$UA" \
      -H 'Content-Type: application/json' -H "X-CSRF-Token: $token" \
      -d "$body" "$BASE$path"
  else
    curl -s -w '\n%{http_code}' -X "$method" -b "$JAR_DIR/$who.jar" -A "$UA" \
      -H "X-CSRF-Token: $token" "$BASE$path"
  fi
}

# 파일 올리기 (multipart) — $1 계정 $2 토큰 $3 파일경로 $4 등급 $5 folder_id(선택)
upload() {
  local who="$1" token="$2" file="$3" level="$4" folder="${5-}"
  if [ -n "$folder" ]; then
    curl -s -w '\n%{http_code}' -X POST -b "$JAR_DIR/$who.jar" -A "$UA" \
      -H "X-CSRF-Token: $token" -F "file=@$file" -F "access_level=$level" \
      -F "folder_id=$folder" "$BASE/api/files"
  else
    curl -s -w '\n%{http_code}' -X POST -b "$JAR_DIR/$who.jar" -A "$UA" \
      -H "X-CSRF-Token: $token" -F "file=@$file" -F "access_level=$level" "$BASE/api/files"
  fi
}

code_of() { printf '%s' "$1" | tail -1; }
body_of() { printf '%s' "$1" | sed '$d'; }
new_id()  { printf '%s' "$1" | sed -n 's/.*"id":\([0-9]*\).*/\1/p' | head -1; }
# JSON 에서 "키":"값" 또는 "키":값 하나를 뽑는다 (값에 따옴표가 없는 경우 포함)
field()   { printf '%s' "$2" | sed -n 's/.*"'"$1"'":"\{0,1\}\([^",}]*\)"\{0,1\}.*/\1/p' | head -1; }
# 업로드 응답의 외부 ID — `{"id":"<uuid>","name":…}` (내부 정수 id 는 나가지 않는다)
uuid_of() { printf '%s' "$1" | sed -n 's/.*"id":"\([0-9a-f-]\{36\}\)".*/\1/p' | head -1; }

# ─────────────────────────────────────────────────────────────
# ① 사용자 활동 확인 — SECURITY R-73 ②
# ─────────────────────────────────────────────────────────────
db_read_var BUSY "$DB" "SELECT count(*) FROM audit_logs
               WHERE happened_at > now() - interval '10 minutes'
                 AND $REVIEW_NOT_SQL;" \
  || { echo "운영 DB 를 읽지 못했다 — 시험을 시작하지 않는다 (R-73 · db_read rc 3)"; rm -rf "$JAR_DIR" "$WORK"; exit 3; }
if [ "${BUSY:-1}" != "0" ]; then
  echo "■ 최근 10분 감사 로그에 비-qa actor 가 ${BUSY}건 있습니다 — 사용자가 작업 중입니다."
  echo "■ 데이터를 남기는 시험을 시작하지 않고 끝냅니다 (SECURITY R-73 ②). 종료 코드 3."
  rm -rf "$JAR_DIR" "$WORK"
  exit 3
fi
echo "■ 최근 10분 비-qa 활동 0건 — 시험을 시작합니다. (읽기 경로 ${DB_READ_VIA})"

QA_IDS=$(psqlq "SELECT coalesce(string_agg(id::text, ','), '0') FROM users WHERE $(review_like_sql username);")
CENSUS_BEFORE=""
CENSUS_SUM=0

# 행 수 대조(R-73 ③) — 읽기만 하므로 db_read(읽기 전용 역할, 없으면 sudo). 하나라도 못 읽으면 rc 3 을
# 돌려준다(값은 원래처럼 빈 값). 시작 전(census before)은 그 rc 로 시작을 막고, 끝(cleanup)은 원래대로 비교만 한다.
census() {
  local when="$1" t total nonqa sum=0 rc=0
  printf '  %-18s %8s %10s\n' "표" "전체" "비-qa"
  db_read_var total "$DB" "SELECT count(*) FROM folders;" || rc=3
  db_read_var nonqa "$DB" "SELECT count(*) FROM folders WHERE created_by NOT IN ($QA_IDS);" || rc=3
  printf '  %-18s %8s %10s\n' "folders" "$total" "$nonqa"; sum=$((sum + nonqa))
  db_read_var total "$DB" "SELECT count(*) FROM files;" || rc=3
  db_read_var nonqa "$DB" "SELECT count(*) FROM files WHERE uploaded_by NOT IN ($QA_IDS);" || rc=3
  printf '  %-18s %8s %10s\n' "files" "$total" "$nonqa"; sum=$((sum + nonqa))
  printf '  %-18s %8s %10s   ← 사용자(비-qa)가 만든 행 합계 (%s)\n' "합계" "-" "$sum" "$when"
  CENSUS_SUM="$sum"
  return "$rc"
}

hr "0-0. 시작 전 인구조사 (R-73 ③)"
echo "  검토 계정 id: $QA_IDS"
# 시작 전 대조값 — 못 읽으면 시작하지 않는다. trap 을 걸기 **전**이라(위 ① 관문과 같이) 임시 폴더를 직접 지운다.
# (trap 뒤에서 끝내면 cleanup 의 「정리 뒤 인구조사」가 빈 시작 값과 비교해 「행 수가 달라졌다」 거짓 경보를 낸다)
census before || { echo "운영 DB 를 읽지 못했다 — 시험을 시작하지 않는다 (R-73 · db_read rc 3)"; rm -rf "$JAR_DIR" "$WORK"; exit 3; }
CENSUS_BEFORE="$CENSUS_SUM"

cleanup() {
  local trap_rc=$?   # trap 에 들어온 종료 코드(끝까지 옴 0 · 로그인 실패 exit 1 …) — 맨 끝에서 쓴다
  hr "정리 — **시험이 만든 것만** 되돌린다 (R-73 ②)"
  echo "  검토 계정 id: $QA_IDS — 이 계정이 만든 S26- 행만 지웁니다"

  # ④ qa 계정에 심었던 값 원복 (계정 행 자체는 지우지 않는다)
  psqlc "UPDATE users SET role = 'Admin'    WHERE username = 'claude_admin01' AND role <> 'Admin';" \
    && echo "  claude_admin01 실제 역할 Admin 확인·원복"
  psqlc "UPDATE sessions SET act_role = NULL, act_team_id = NULL,
                             act_started_at = NULL, act_last_seen_at = NULL
          WHERE user_id IN ($QA_IDS);" \
    && echo "  qa 세션의 act_* 해제"

  # 파일 — 이 시험이 올린 S26- 파일만. 바이트도 함께 치운다(휴지통에 남기지 않는다).
  local blobs
  blobs=$(psqlq "SELECT stored_name FROM files
                  WHERE uploaded_by IN ($QA_IDS) AND original_name LIKE 'S26-%';")
  if [ -n "$blobs" ] && [ -z "$S26_STORAGE" ]; then
    echo "  S26- 파일 바이트는 지우지 않음 — 대상이 임시(DB $DB)이고 S26_STORAGE_DIR 이 없다(임시 저장소는 부른 틀이 지운다)"
  elif [ -n "$blobs" ] && ! prod_write_guard "$S26_STORAGE"; then
    echo "  ✖ S26- 파일 바이트 — 운영 파일 쓰기 가드가 막아 지우지 않았다"; FAIL=$((FAIL+1))
  elif [ -n "$blobs" ]; then
    local sn
    for sn in $blobs; do
      if pg_is_prod_path "$S26_STORAGE"; then
        sudo -n rm -f "$S26_STORAGE/${sn:0:2}/${sn:2:2}/$sn" 2>/dev/null
      else
        rm -f "$S26_STORAGE/${sn:0:2}/${sn:2:2}/$sn" 2>/dev/null
      fi
    done
  fi
  psqlc "DELETE FROM files WHERE uploaded_by IN ($QA_IDS) AND original_name LIKE 'S26-%';" \
    && echo "  S26- 파일 행·바이트 삭제"
  psqlc "DELETE FROM folders WHERE created_by IN ($QA_IDS) AND name LIKE 'S26-%';" \
    && echo "  S26- 폴더 삭제"

  sub "정리 뒤 인구조사 — 사용자 자료가 그대로인가"
  census after
  local after="$CENSUS_SUM"
  echo
  if [ "$after" = "$CENSUS_BEFORE" ]; then
    printf '  ✔ 사용자(비-qa) 행 수가 시작 때와 같습니다 (%s → %s)\n' "$CENSUS_BEFORE" "$after"
  else
    printf '  ✖ 사용자(비-qa) 행 수가 달라졌습니다 (%s → %s) — **즉시 보고해야 합니다**\n' "$CENSUS_BEFORE" "$after"
    FAIL=$((FAIL+1))
  fi

  rm -rf "$JAR_DIR" "$WORK"
  hr "합계"
  printf '  통과 %s · 실패 %s\n' "$PASS" "$FAIL"
  result_line "$PASS" "$FAIL" 0
  # 실패가 있으면 rc 1. EXIT trap 안의 exit n 이 최종 코드가 된다. 이미 0 이 아닌 코드
  # (로그인 실패 exit 1 · 신호 등)는 exit 하지 않고 그대로 둔다. R-73 exit 3 은 trap 설치 전이다.
  if [ "$trap_rc" -eq 0 ] && [ "$FAIL" -ne 0 ]; then exit 1; fi
}
trap cleanup EXIT

hr "0. 준비 — 검토 계정 로그인"
TOK_ADMIN=$(login claude_admin01)   || exit 1
TOK_EMP1=$(login claude_employee01) || exit 1
TOK_MGR=$(login claude_manager01)   || exit 1
echo "  로그인 3계정 완료"

SID_ADMIN=$(psqlq "SELECT id FROM sessions WHERE user_id = (SELECT id FROM users WHERE username='claude_admin01')
                    ORDER BY created_at DESC LIMIT 1;")
TEAM_ID=$(psqlq "SELECT id FROM teams WHERE is_active ORDER BY id LIMIT 1;")
TEAM_NAME=$(psqlq "SELECT name FROM teams WHERE id = $TEAM_ID;")
echo "  시험에 쓸 팀: id=$TEAM_ID name=$TEAM_NAME"

# ═════════════════════════════════════════════════════════════
hr "T-52 (b) 재인증 관문 — 재인증 없이 「역할로 보기」를 켤 수 없다"
# ═════════════════════════════════════════════════════════════
R=$(call claude_admin01 POST /api/me/act "$TOK_ADMIN" '{"role":"Employee"}')
echo "  HTTP $(code_of "$R")"; echo "  $(body_of "$R")"
expect "재인증 없이 POST /api/me/act → 403" 403 "$(code_of "$R")"
expect "본문에 next:reauth" 1 "$(body_of "$R" | grep -c '"next":"reauth"')"

echo "  재인증: claude_admin01 → $(reauth claude_admin01 "$TOK_ADMIN")"

# ═════════════════════════════════════════════════════════════
hr "T-52 (a) 비관리자는 켤 수 없다"
# ═════════════════════════════════════════════════════════════
R=$(call claude_employee01 POST /api/me/act "$TOK_EMP1" '{"role":"Guest"}')
echo "  HTTP $(code_of "$R")"; echo "  $(body_of "$R")"
expect "claude_employee01 POST /api/me/act → 403" 403 "$(code_of "$R")"
DENY=$(psqlq "SELECT count(*) FROM audit_logs
               WHERE actor_name='claude_employee01' AND result='deny'
                 AND detail->>'route' = 'POST /api/me/act'
                 AND happened_at > now() - interval '2 minutes';")
expect "감사에 deny 1건 이상" 1 "$([ "${DENY:-0}" -ge 1 ] && echo 1 || echo 0)"

# ═════════════════════════════════════════════════════════════
hr "T-52 (j) 값 검사 — 위로 가는 조합 · 없어진 키 · 없는 팀 · 모르는 키"
# ═════════════════════════════════════════════════════════════
R=$(call claude_admin01 POST /api/me/act "$TOK_ADMIN" '{"role":"Admin"}')
echo "  {role:Admin} → HTTP $(code_of "$R") $(body_of "$R")"
expect "{role:'Admin'} → 400 (위로 갈 수 없다)" 400 "$(code_of "$R")"

R=$(call claude_admin01 POST /api/me/act "$TOK_ADMIN" '{"role":"Employee","team_id":999999999}')
echo "  {team_id:999999999} → HTTP $(code_of "$R")"
expect "없는 팀 → 400" 400 "$(code_of "$R")"

R=$(call claude_admin01 POST /api/me/act "$TOK_ADMIN" '{"role":"Employee","nope":1}')
expect "모르는 키 → 400" 400 "$(code_of "$R")"

# ═════════════════════════════════════════════════════════════
hr "T-52 준비 — admin-only 파일 하나 (보는 중 목록에서 빠지는지 볼 표적)"
# ═════════════════════════════════════════════════════════════
printf 'S26 admin-only fixture\n' > "$WORK/S26-adminonly.txt"
printf 'S26 internal fixture\n'   > "$WORK/S26-internal.txt"
R=$(upload claude_admin01 "$TOK_ADMIN" "$WORK/S26-adminonly.txt" admin-only)
FILE_ADMIN=$(uuid_of "$(body_of "$R")")
expect "admin-only 파일 업로드" 201 "$(code_of "$R")"
# 「남의 파일」 은 **다른 계정**이 올린 것이어야 한다 — 같은 계정이면 보는 중에도 owner 라 지울 수 있다
R=$(upload claude_manager01 "$TOK_MGR" "$WORK/S26-internal.txt" internal)
FILE_INTERNAL=$(uuid_of "$(body_of "$R")")
expect "internal 파일 업로드(claude_manager01 = 남의 파일)" 201 "$(code_of "$R")"
echo "  admin-only public_id=$FILE_ADMIN · internal public_id=$FILE_INTERNAL"

# ═════════════════════════════════════════════════════════════
hr "T-52 (b) 켜기 — {role:Employee, team_id:$TEAM_ID}"
# ═════════════════════════════════════════════════════════════
R=$(call claude_admin01 POST /api/me/act "$TOK_ADMIN" "{\"role\":\"Employee\",\"team_id\":$TEAM_ID}")
echo "  HTTP $(code_of "$R")"; echo "  $(body_of "$R")"
expect "POST /api/me/act → 200" 200 "$(code_of "$R")"

R=$(call claude_admin01 GET /api/me "$TOK_ADMIN")
ME="$(body_of "$R")"
echo "  $ME"
expect "/api/me role=Employee"        "Employee" "$(field role "$ME")"
expect "/api/me acting=true"          1 "$(printf '%s' "$ME" | grep -c '"acting":true')"
expect "/api/me real_role=Admin"      1 "$(printf '%s' "$ME" | grep -c '"real_role":"Admin"')"
expect "/api/me can_admin=false"      1 "$(printf '%s' "$ME" | grep -c '"can_admin":false')"
expect "/api/me can_stop_acting=true" 1 "$(printf '%s' "$ME" | grep -c '"can_stop_acting":true')"
expect "/api/me team_id=$TEAM_ID"     1 "$(printf '%s' "$ME" | grep -c "\"team_id\":$TEAM_ID")"

# ═════════════════════════════════════════════════════════════
hr "T-52 (c) 그 세션의 **서버 판정**이 Employee 다"
# ═════════════════════════════════════════════════════════════
R=$(call claude_admin01 GET "/api/files?q=S26-" "$TOK_ADMIN")
echo "  $(body_of "$R" | head -c 400)"
expect "보는 중 목록에 admin-only 파일 0건" 0 "$(body_of "$R" | grep -c "S26-adminonly")"
expect "보는 중 목록에 internal 파일은 보임" 1 "$(body_of "$R" | grep -c "S26-internal")"

R=$(call claude_admin01 DELETE "/api/files/$FILE_INTERNAL" "$TOK_ADMIN")
echo "  남의 internal 파일 DELETE → HTTP $(code_of "$R") $(body_of "$R")"
expect "남의 파일 삭제 403" 403 "$(code_of "$R")"

R=$(call claude_admin01 GET /users "$TOK_ADMIN")
echo "  GET /users → HTTP $(code_of "$R")"
expect "Admin 화면 막힘(403 또는 302)" 1 \
  "$([ "$(code_of "$R")" = "403" ] || [ "$(code_of "$R")" = "302" ] && echo 1 || echo 0)"

# ═════════════════════════════════════════════════════════════
hr "T-52 (i) 보는 중 한 행동의 감사 — actor_role 은 실제 역할"
# ═════════════════════════════════════════════════════════════
printf 'S26 acting upload\n' > "$WORK/S26-acting.txt"
# 감사는 이번 업로드가 남긴 행만 본다(BF-0009 — T-52(f) STOP_MARK 와 같은 모양): 요청 바로 앞에서 경계를 잰다
ACT_MARK=$(psqlq "SELECT coalesce(max(id),0) FROM audit_logs;")
R=$(upload claude_admin01 "$TOK_ADMIN" "$WORK/S26-acting.txt" internal)
echo "  보는 중 업로드 → HTTP $(code_of "$R")"
expect "보는 중에도 Employee 자격으로 업로드 200/201" 201 "$(code_of "$R")"
ACTROW=$(psqlq "SELECT actor_role||'|'||coalesce(detail->'acting_as'->>'role','-')||'|'||coalesce(detail->>'real_role','-')
                  FROM audit_logs
                 WHERE actor_name='claude_admin01' AND action='file.upload' AND id > ${ACT_MARK:-0}
                 ORDER BY id DESC LIMIT 1;")
echo "  감사 행: actor_role|acting_as.role|real_role = $ACTROW"
expect "actor_role=Admin · acting_as.role=Employee" "Admin|Employee|Admin" "$ACTROW"

# ═════════════════════════════════════════════════════════════
hr "T-52 (e) 보는 중 다시 켜기 금지"
# ═════════════════════════════════════════════════════════════
R=$(call claude_admin01 POST /api/me/act "$TOK_ADMIN" '{"role":"Guest"}')
echo "  HTTP $(code_of "$R") $(body_of "$R")"
expect "보는 중 POST /api/me/act → 403" 403 "$(code_of "$R")"

# ═════════════════════════════════════════════════════════════
hr "T-52 (g) 활동 갱신 — 60초가 지난 뒤의 요청이 act_last_seen_at 을 민다 (Q:F-4 P-3 — 60초에 한 번)"
# ═════════════════════════════════════════════════════════════
# P-3(2026-09-24) 부터 세션 갱신은 마지막 갱신이 60초보다 오래됐을 때만 한다 — 두 열을 61초 전으로 돌려 둔다
psqlc "UPDATE sessions SET last_seen_at = last_seen_at - interval '61 seconds', act_last_seen_at = act_last_seen_at - interval '61 seconds' WHERE id = '$SID_ADMIN';"
T1=$(psqlq "SELECT act_last_seen_at FROM sessions WHERE id = '$SID_ADMIN';")
call claude_admin01 GET /api/me "$TOK_ADMIN" > /dev/null
T2=$(psqlq "SELECT act_last_seen_at FROM sessions WHERE id = '$SID_ADMIN';")
echo "  요청 전: $T1"
echo "  요청 후: $T2"
expect "60초 지난 뒤 요청에 act_last_seen_at 갱신" 1 "$([ "$T1" != "$T2" ] && echo 1 || echo 0)"

sub "29분 전이면 유지된다"
psqlc "UPDATE sessions SET act_last_seen_at = now() - interval '29 minutes' WHERE id = '$SID_ADMIN';"
R=$(call claude_admin01 GET /api/me "$TOK_ADMIN")
expect "29분 전 → 여전히 acting" 1 "$(body_of "$R" | grep -c '"acting":true')"

sub "31분 전이면 자동 해제된다 (idle)"
psqlc "UPDATE sessions SET act_last_seen_at = now() - interval '31 minutes' WHERE id = '$SID_ADMIN';"
IDLE_MARK=$(psqlq "SELECT coalesce(max(id),0) FROM audit_logs;")   # 이번 요청이 남기는 act.expire 만 본다(BF-0009)
R=$(call claude_admin01 GET /api/me "$TOK_ADMIN")
ME="$(body_of "$R")"
expect "31분 전 → role 이 Admin 으로 돌아옴" "Admin" "$(field role "$ME")"
expect "31분 전 → acting false" 1 "$(printf '%s' "$ME" | grep -c '"acting":false')"
EXPIRE=$(psqlq "SELECT result||'|'||coalesce(detail->>'reason','-')||'|'||actor_role
                  FROM audit_logs WHERE action='act.expire' AND actor_name='claude_admin01' AND id > ${IDLE_MARK:-0}
                  ORDER BY id DESC LIMIT 1;")
echo "  감사 act.expire: $EXPIRE"
expect "act.expire allow · reason=idle · actor_role=Admin" "allow|idle|Admin" "$EXPIRE"
ACTNULL=$(psqlq "SELECT count(*) FROM sessions WHERE id='$SID_ADMIN' AND act_role IS NULL;")
expect "세션의 act_* 가 NULL 로 돌아감" 1 "$ACTNULL"

# ═════════════════════════════════════════════════════════════
hr "T-52 (h) 강등되면 그 자리에서 풀린다 (role_lost)"
# ═════════════════════════════════════════════════════════════
R=$(call claude_admin01 POST /api/me/act "$TOK_ADMIN" '{"role":"Employee"}')
expect "다시 켜기 200" 200 "$(code_of "$R")"
psqlc "UPDATE users SET role = 'Manager' WHERE username = 'claude_admin01';"
echo "  psql 로 claude_admin01 을 Manager 로 강등 (시험 뒤 원복)"
LOST_MARK=$(psqlq "SELECT coalesce(max(id),0) FROM audit_logs;")   # 이번 요청이 남기는 act.expire 만 본다(BF-0009)
R=$(call claude_admin01 GET /api/me "$TOK_ADMIN")
ME="$(body_of "$R")"
echo "  $ME" | head -c 200; echo
expect "강등 뒤 acting false" 1 "$(printf '%s' "$ME" | grep -c '"acting":false')"
expect "강등 뒤 role 은 실제 역할 Manager" "Manager" "$(field role "$ME")"
EXPIRE=$(psqlq "SELECT coalesce(detail->>'reason','-')||'|'||actor_role FROM audit_logs
                 WHERE action='act.expire' AND actor_name='claude_admin01' AND id > ${LOST_MARK:-0} ORDER BY id DESC LIMIT 1;")
echo "  감사 act.expire: $EXPIRE"
expect "reason=role_lost · actor_role=Manager(그때의 실제 역할)" "role_lost|Manager" "$EXPIRE"
psqlc "UPDATE users SET role = 'Admin' WHERE username = 'claude_admin01';"
echo "  claude_admin01 실제 역할 Admin 으로 원복"
R=$(call claude_admin01 GET /api/me "$TOK_ADMIN")
expect "원복 뒤 role=Admin" "Admin" "$(field role "$(body_of "$R")")"

# ═════════════════════════════════════════════════════════════
hr "T-52 (f) 끄기 — 원래대로"
# ═════════════════════════════════════════════════════════════
# (h) 의 강등으로 역할 보기는 이미 풀렸다 — 「끄기」를 시험하려면 먼저 다시 켠다. 감사는 이번에 생긴 행만 본다.
# (2026-09-24 취합: 옛 시험은 운영 사본에 남은 예전 act.stop 행(qa_admin01 49건)을 읽어 우연히 통과했다 —
#  1-B-1 에서 claude_admin01 로 바뀌며 드러남)
R=$(call claude_admin01 POST /api/me/act "$TOK_ADMIN" '{"role":"Employee"}')
expect "끄기 전에 다시 켜기 → 200" 200 "$(code_of "$R")"
STOP_MARK=$(psqlq "SELECT coalesce(max(id),0) FROM audit_logs;")
R=$(call claude_admin01 DELETE /api/me/act "$TOK_ADMIN")
ME="$(body_of "$R")"
echo "  HTTP $(code_of "$R")"; echo "  $ME" | head -c 300; echo
expect "DELETE /api/me/act → 200" 200 "$(code_of "$R")"
expect "role 이 Admin 으로 돌아옴" "Admin" "$(field role "$ME")"
expect "acting=false" 1 "$(printf '%s' "$ME" | grep -c '"acting":false')"
STOP=$(psqlq "SELECT result||'|'||actor_role||'|'||coalesce(detail->'acting_as'->>'role','-')
                FROM audit_logs WHERE action='act.stop' AND actor_name='claude_admin01' AND id > ${STOP_MARK:-0}
               ORDER BY id DESC LIMIT 1;")
echo "  감사 act.stop: $STOP"
expect "act.stop allow · actor_role=Admin · acting_as.role=Employee" "allow|Admin|Employee" "$STOP"

R=$(call claude_admin01 GET /api/me "$TOK_ADMIN")
expect "끈 뒤 can_admin=true" 1 "$(body_of "$R" | grep -c '"can_admin":true')"

# ═════════════════════════════════════════════════════════════
hr "T-54 폴더 삭제 — 세 갈래"
# ═════════════════════════════════════════════════════════════
mkfolder() {  # $1 계정 $2 토큰 $3 이름 $4 등급 $5 parent(선택)
  local body="{\"name\":\"$3\",\"access_level\":\"$4\""
  [ -n "${5-}" ] && body="$body,\"parent_id\":$5"
  body="$body}"
  call "$1" POST /api/folders "$2" "$body"
}

sub "(a) 하위 폴더가 있는 폴더"
R=$(mkfolder claude_employee01 "$TOK_EMP1" "S26-parent" internal)
F_PARENT=$(new_id "$(body_of "$R")"); expect "부모 폴더 생성" 201 "$(code_of "$R")"
R=$(mkfolder claude_employee01 "$TOK_EMP1" "S26-child" internal "$F_PARENT")
F_CHILD=$(new_id "$(body_of "$R")"); expect "하위 폴더 생성" 201 "$(code_of "$R")"
R=$(call claude_employee01 DELETE "/api/folders/$F_PARENT" "$TOK_EMP1")
echo "  HTTP $(code_of "$R")"; echo "  $(body_of "$R")"
expect "하위 폴더 있음 → 400" 400 "$(code_of "$R")"
expect "코드 folder_has_children" 1 "$(body_of "$R" | grep -c 'folder_has_children')"
expect "응답에 이름 없음(S26-child 0회)" 0 "$(body_of "$R" | grep -c 'S26-child')"
expect "응답에 개수 없음(trashed 키 0회)" 0 "$(body_of "$R" | grep -c '"trashed"')"

sub "(b) 살아 있는 파일이 있는 폴더"
R=$(mkfolder claude_employee01 "$TOK_EMP1" "S26-alive" internal)
F_ALIVE=$(new_id "$(body_of "$R")"); expect "폴더 생성" 201 "$(code_of "$R")"
printf 'S26 alive\n' > "$WORK/S26-alive.txt"
R=$(upload claude_employee01 "$TOK_EMP1" "$WORK/S26-alive.txt" internal "$F_ALIVE")
expect "살아 있는 파일 업로드" 201 "$(code_of "$R")"
R=$(call claude_employee01 DELETE "/api/folders/$F_ALIVE" "$TOK_EMP1")
echo "  HTTP $(code_of "$R")"; echo "  $(body_of "$R")"
expect "살아 있는 파일 → 400" 400 "$(code_of "$R")"
expect "코드 folder_not_empty" 1 "$(body_of "$R" | grep -c 'folder_not_empty')"
expect "응답에 파일 이름 없음" 0 "$(body_of "$R" | grep -c 'S26-alive.txt')"

sub "(c) 휴지통 파일만 남은 폴더 — 목록을 보여 주고 되묻는다"
R=$(mkfolder claude_employee01 "$TOK_EMP1" "S26-trash" internal)
F_TRASH=$(new_id "$(body_of "$R")"); expect "폴더 생성" 201 "$(code_of "$R")"
printf 'S26 mine\n' > "$WORK/S26-mine.txt"
R=$(upload claude_employee01 "$TOK_EMP1" "$WORK/S26-mine.txt" internal "$F_TRASH")
FILE_MINE=$(uuid_of "$(body_of "$R")"); expect "내 파일 업로드" 201 "$(code_of "$R")"
printf 'S26 hidden\n' > "$WORK/S26-hidden.txt"
R=$(upload claude_admin01 "$TOK_ADMIN" "$WORK/S26-hidden.txt" admin-only "$F_TRASH")
FILE_HIDDEN=$(uuid_of "$(body_of "$R")"); expect "안 보이는(admin-only) 파일 업로드" 201 "$(code_of "$R")"
R=$(call claude_employee01 DELETE "/api/files/$FILE_MINE" "$TOK_EMP1")
expect "내 파일 휴지통으로" 200 "$(code_of "$R")"
R=$(call claude_admin01 DELETE "/api/files/$FILE_HIDDEN" "$TOK_ADMIN")
expect "admin-only 파일 휴지통으로" 200 "$(code_of "$R")"

R=$(call claude_employee01 DELETE "/api/folders/$F_TRASH" "$TOK_EMP1")
BODY_EMP="$(body_of "$R")"
echo "  [claude_employee01] HTTP $(code_of "$R")"; echo "  $BODY_EMP"
expect "휴지통 파일만 남음 → 400" 400 "$(code_of "$R")"
expect "코드 folder_has_trashed" 1 "$(printf '%s' "$BODY_EMP" | grep -c 'folder_has_trashed')"
expect "볼 수 있는 파일 이름은 있다" 1 "$(printf '%s' "$BODY_EMP" | grep -c 'S26-mine.txt')"
expect "볼 수 없는 파일 이름은 없다" 0 "$(printf '%s' "$BODY_EMP" | grep -c 'S26-hidden.txt')"
expect "others:true" 1 "$(printf '%s' "$BODY_EMP" | grep -c '"others":true')"

R=$(call claude_admin01 DELETE "/api/folders/$F_TRASH" "$TOK_ADMIN")
BODY_ADM="$(body_of "$R")"
echo "  [claude_admin01] HTTP $(code_of "$R")"; echo "  $BODY_ADM"
expect "Admin 에게도 400(확인 없이는 안 지운다)" 400 "$(code_of "$R")"
expect "Admin 응답엔 두 이름 모두" 2 \
  "$(( $(printf '%s' "$BODY_ADM" | grep -c 'S26-mine.txt') + $(printf '%s' "$BODY_ADM" | grep -c 'S26-hidden.txt') ))"
expect "Admin 응답 others:false" 1 "$(printf '%s' "$BODY_ADM" | grep -c '"others":false')"

sub "?move_trashed=1 — 확인하면 루트로 옮기고 폴더를 지운다"
MOVE_MARK=$(psqlq "SELECT coalesce(max(id),0) FROM audit_logs;")   # 이번 삭제가 남기는 folder.delete 만 본다(BF-0009)
R=$(call claude_admin01 DELETE "/api/folders/$F_TRASH?move_trashed=1" "$TOK_ADMIN")
echo "  HTTP $(code_of "$R")"; echo "  $(body_of "$R")"
expect "확인 뒤 200" 200 "$(code_of "$R")"
expect "폴더가 사라짐" 0 "$(psqlq "SELECT count(*) FROM folders WHERE id = $F_TRASH;")"
MOVED=$(psqlq "SELECT count(*) FROM files WHERE public_id IN ('$FILE_MINE','$FILE_HIDDEN')
                 AND folder_id IS NULL AND deleted_at IS NOT NULL AND purged_at IS NULL;")
expect "두 파일 모두 folder_id NULL · deleted_at 유지" 2 "$MOVED"
AUD=$(psqlq "SELECT (detail->>'moved_trashed')||'|'||jsonb_array_length(detail->'moved_file_ids')
               FROM audit_logs WHERE action='folder.delete' AND result='allow'
                AND actor_name='claude_admin01' AND id > ${MOVE_MARK:-0} ORDER BY id DESC LIMIT 1;")
echo "  감사 folder.delete detail: moved_trashed|moved_file_ids 개수 = $AUD"
expect "감사 detail moved_trashed=2 · id 2개" "2|2" "$AUD"

R=$(call claude_admin01 GET /api/files/trash "$TOK_ADMIN")
expect "휴지통 목록에 그대로 있다" 1 "$(body_of "$R" | grep -c 'S26-mine.txt')"

sub "(d) 복구 — 루트로 되살아난다"
R=$(call claude_admin01 POST "/api/files/$FILE_MINE/restore" "$TOK_ADMIN")
echo "  HTTP $(code_of "$R")"
expect "복구 200" 200 "$(code_of "$R")"
RESTORED=$(psqlq "SELECT coalesce(folder_id::text,'NULL')||'|'||coalesce(deleted_at::text,'NULL')
                    FROM files WHERE public_id = '$FILE_MINE';")
echo "  복구 뒤 folder_id|deleted_at = $RESTORED"
expect "루트(folder_id NULL)로 복구" "NULL|NULL" "$RESTORED"

# ═════════════════════════════════════════════════════════════
hr "T-53 (c) 계정 화면에 window.prompt 0개 (정적 검사)"
# ═════════════════════════════════════════════════════════════
PROMPTS=$(python3 - "$ROOT/public/assets/app.js" <<'PYEOF'
import re, sys
src = open(sys.argv[1], encoding='utf-8').read()
start = src.index('function initUsers(')
end = src.index('\nfunction ', start + 10)
body = src[start:end]
body = re.sub(r'/\*.*?\*/', '', body, flags=re.S)
body = re.sub(r'^\s*//.*$', '', body, flags=re.M)
print(len(re.findall(r'window\.prompt', body)))
PYEOF
)
expect "initUsers 안의 window.prompt" 0 "$PROMPTS"
expect "users_page.php 안의 window.prompt( 호출" 0 "$(grep -c 'window\.prompt(' "$ROOT/src/handlers/users_page.php")"
expect "계정 변경 모달 마크업 있음" 1 "$(grep -c 'id="user-edit"' "$ROOT/src/handlers/users_page.php")"
expect "사유 모달 마크업 있음"      1 "$(grep -c 'id="reason-dialog"' "$ROOT/src/handlers/users_page.php")"

# ═════════════════════════════════════════════════════════════
hr "T-53 (d) 회귀 T-26 — 화면 분리 뒤에도 권한은 서버가"
# ═════════════════════════════════════════════════════════════
for p in /users /org /audit; do
  R=$(call claude_employee01 GET "$p" "$TOK_EMP1")
  expect "Employee $p → 403" 403 "$(code_of "$R")"
done
# Q:F-4 U-2b(2026-09-24) — /trash 는 이제 누구나 열리고, 비관리자에게는 **자기가 지운 자기 파일만** 보이는 「내 휴지통」이다.
# 범위는 서버가 SQL WHERE 로 정한다(tests/qf4_restore_owner.sh 가 남의 파일 0건·복구 404 를 본다). 여기서는 화면이 관리자용이 아닌지만 본다.
R=$(call claude_employee01 GET /trash "$TOK_EMP1")
expect "Employee /trash → 200 (「내 휴지통」 — U-2b)" 200 "$(code_of "$R")"
# 「내 휴지통」 글자는 모든 화면의 내비에 있으므로(hidden) 글자만으로는 가르지 못한다 — 제목·표 속성·열 구성으로 본다(독립 검토 U-2b Low-1)
expect "Employee /trash 제목이 「내 휴지통」" "1" "$(printf '%s' "$R" | grep -c '<h1 class="page-head__title">내 휴지통</h1>')"
expect "Employee /trash 표가 본인 휴지통(data-trash-own)" "1" "$(printf '%s' "$R" | grep -c 'id="trash-body" data-trash-own')"
expect "Employee /trash 표에 「올린 사람」 칸 없음(관리자 표가 아님)" "0" "$(printf '%s' "$R" | grep -c '<th scope="col">올린 사람</th>')"
R=$(call claude_employee01 GET /settings "$TOK_EMP1")
expect "Employee /settings → 200" 200 "$(code_of "$R")"

# ═════════════════════════════════════════════════════════════
hr "T-52 (k) · T-53 (a)(b)(c) — 화면 확인 (Playwright)"
# ═════════════════════════════════════════════════════════════
jar_cookies() { awk '(!/^#/ || /^#HttpOnly_/) && NF>=7 {printf "%s=%s;", $6, $7}' "$1"; }

# 띠를 찍으려면 보는 중이어야 한다.
R=$(call claude_admin01 POST /api/me/act "$TOK_ADMIN" \
  "{\"role\":\"Employee\",\"team_id\":$TEAM_ID}")
expect "화면 확인용으로 다시 켜기 200" 200 "$(code_of "$R")"

cat > "$WORK/shots.py" <<'PYEOF'
import json, os, sys
from playwright.sync_api import sync_playwright
BASE, UA, OUT = sys.argv[1:4]
# 세션 쿠키 둘은 표준입력에서 차례로 한 줄씩 — 인자는 ps 에 보인다(R-62 · py_secret)
CK_ADMIN = sys.stdin.readline().rstrip('\n')
CK_EMP = sys.stdin.readline().rstrip('\n')
EXE = os.path.expanduser("~/.cache/ms-playwright/chromium-1234/chrome-linux64/chrome")
out = {}

def ctx(browser, cookies):
    c = browser.new_context(user_agent=UA, viewport={"width": 1440, "height": 900})
    c.add_cookies([{"name": k, "value": v, "url": BASE}
                   for k, v in (p.split("=", 1) for p in cookies.split(";") if "=" in p)])
    return c

with sync_playwright() as p:
    b = p.chromium.launch(executable_path=EXE, args=["--no-sandbox"])

    # ── T-52 (k) 보는 중의 띠 · 관리 묶음이 닫힘 ─────────────────
    c = ctx(b, CK_ADMIN); pg = c.new_page()
    errs = []
    pg.on("pageerror", lambda e: errs.append(str(e)))
    pg.goto(BASE + "/activity", wait_until="networkidle")
    pg.wait_for_timeout(800)
    banner = pg.query_selector(".act-banner")
    out["banner_text"] = banner.inner_text().replace("\n", " ") if banner else None
    out["banner_has_button"] = bool(pg.query_selector(".act-banner [data-act-stop]"))
    grp = pg.query_selector("#nav-admin-group")
    out["acting_admin_group_hidden"] = None if grp is None else grp.is_hidden()
    pg.screenshot(path=OUT + "/act-banner.png")
    out["console_errors_acting"] = errs[:]

    # 「원래대로」를 눌러 끈다 → 관리 묶음이 다시 열린다
    if out["banner_has_button"]:
        pg.click(".act-banner [data-act-stop]")
        pg.wait_for_load_state("networkidle")
        pg.wait_for_timeout(900)
    out["after_stop_banner"] = bool(pg.query_selector(".act-banner"))
    grp = pg.query_selector("#nav-admin-group")
    out["after_stop_admin_group_hidden"] = None if grp is None else grp.is_hidden()
    out["after_stop_chip_role"] = (pg.inner_text(".userchip__role") or "").strip()
    pg.screenshot(path=OUT + "/after-stop.png")

    # ── T-53 (c) 계정 변경 모달 — 바뀐 칸만 · 무변경 · 취소 ──────
    pg.goto(BASE + "/users", wait_until="networkidle")
    pg.wait_for_timeout(1200)
    sent = []
    def on_route(route):
        if route.request.method == "PATCH":
            sent.append(route.request.post_data)
            route.abort()           # 실제로 바꾸지 않는다 — 본문만 본다
        else:
            route.continue_()
    pg.route("**/api/users/*", on_route)

    rows = pg.query_selector_all("#users-body tr")
    out["users_rows"] = len(rows)
    target = None
    for r in rows:
        tds = r.query_selector_all("td")
        # 5-A″ — 칸은 「이름 (아이디)」(이름이 없으면 아이디)다. 어느 쪽이든 그 계정의 줄을 고른다.
        cell = tds[1].inner_text().strip() if len(tds) > 1 else ""
        if cell == "claude_employee02" or cell.endswith("(claude_employee02)"):
            target = r
            break
    # 표는 가로 스크롤 상자(.table-wrap) 안이라 좌표 클릭이 막힐 수 있다 → DOM 클릭을 쓴다.
    # 실제 click 이벤트가 그대로 발생하므로 화면 코드의 경로는 같다.
    def dom_click(handle):
        handle.evaluate("el => el.click()")

    if target:
        dom_click(target.query_selector_all("button")[0])
        pg.wait_for_timeout(500)
        out["modal_title"] = (pg.inner_text("#ue-title") or "").strip()
        pg.screenshot(path=OUT + "/user-edit-modal.png")
        # ① 아무것도 바꾸지 않고 저장
        pg.eval_on_selector("#ue-submit", "el => el.click()")
        pg.wait_for_timeout(500)
        out["nochange_requests"] = len(sent)
        out["nochange_msg"] = (pg.inner_text("#msg") or "").strip()
        # ② 역할만 바꾸고 저장
        dom_click(target.query_selector_all("button")[0])
        pg.wait_for_timeout(500)
        cur = pg.eval_on_selector("#ue-role", "el => el.value")
        pg.select_option("#ue-role", "Manager" if cur != "Manager" else "Employee")
        pg.fill("#ue-reason", "S26 검토 — 모달 본문 확인")
        pg.eval_on_selector("#ue-submit", "el => el.click()")
        pg.wait_for_timeout(800)
        out["patch_body"] = sent[-1] if sent else None
        # ③ 취소
        dom_click(target.query_selector_all("button")[0])
        pg.wait_for_timeout(400)
        pg.eval_on_selector("#ue-cancel", "el => el.click()")
        pg.wait_for_timeout(400)
        out["cancel_msg"] = (pg.inner_text("#msg") or "").strip()
    else:
        out["patch_body"] = "claude_employee02 행을 찾지 못함"

    # ── T-53 (b) Employee 에게는 관리 묶음이 닫힌다 ──────────────
    ids = ["nav-admin", "nav-users", "nav-org", "nav-admin-permissions",
           "nav-audit", "nav-trash", "nav-wiki"]
    c2 = ctx(b, CK_EMP); pg2 = c2.new_page()
    pg2.goto(BASE + "/", wait_until="networkidle")
    pg2.wait_for_timeout(800)
    grp = pg2.query_selector("#nav-admin-group")
    out["employee_admin_group_hidden"] = None if grp is None else grp.is_hidden()
    out["employee_admin_links_open"] = sum(
        1 for i in ids if (pg2.query_selector("#" + i) and not pg2.query_selector("#" + i).is_hidden()))

    b.close()
print(json.dumps(out, ensure_ascii=False, indent=2))
PYEOF

export PATH="$HOME/.local/bin:$PATH"
CK_ADMIN=$(jar_cookies "$JAR_DIR/claude_admin01.jar"); CK_EMP=$(jar_cookies "$JAR_DIR/claude_employee01.jar")
if py_secret CK_ADMIN CK_EMP -- python3 "$WORK/shots.py" "$BASE" "$UA" "$OUT" > "$OUT/shots.json" 2>"$OUT/shots.err"; then
  cat "$OUT/shots.json"
  jget() { python3 -c "import json;d=json.load(open('$OUT/shots.json'));v=d.get('$1');print('' if v is None else (json.dumps(v,ensure_ascii=False) if isinstance(v,(dict,list)) else v))"; }
  BANNER="$(jget banner_text)"
  expect "띠에 「으로 보는 중」"          1 "$(printf '%s' "$BANNER" | grep -c '으로 보는 중')"
  expect "띠에 실제 계정 표시"            1 "$(printf '%s' "$BANNER" | grep -c 'claude_admin01(Admin)')"
  expect "띠에 「원래대로」 단추"          "True" "$(jget banner_has_button)"
  expect "보는 중 콘솔 오류 0건"          "[]"   "$(jget console_errors_acting)"
  expect "보는 중 관리 묶음 닫힘"          "True" "$(jget acting_admin_group_hidden)"
  expect "「원래대로」 뒤 띠 사라짐"        "False" "$(jget after_stop_banner)"
  expect "「원래대로」 뒤 관리 묶음 열림"    "False" "$(jget after_stop_admin_group_hidden)"
  expect "「원래대로」 뒤 칩 역할 Admin"    "Admin" "$(jget after_stop_chip_role)"
  expect "Employee 에게 관리 묶음 닫힘"     "True" "$(jget employee_admin_group_hidden)"
  expect "Employee 에게 관리 링크 0개"      0 "$(jget employee_admin_links_open)"
  expect "무변경 저장 → 요청 0건"          0 "$(jget nochange_requests)"
  expect "무변경 문구"                    "바뀐 내용이 없습니다" "$(jget nochange_msg)"
  expect "취소 문구"                      "취소했습니다" "$(jget cancel_msg)"
  PB="$(jget patch_body)"
  echo "  PATCH 본문: $PB"
  expect "PATCH 본문에 role 있음"          1 "$(printf '%s' "$PB" | grep -c '"role"')"
  expect "PATCH 본문에 reason_text 있음"   1 "$(printf '%s' "$PB" | grep -c '"reason_text"')"
  expect "PATCH 본문에 team_id 없음"       0 "$(printf '%s' "$PB" | grep -c '"team_id"')"
  expect "PATCH 본문에 expires_at 없음"    0 "$(printf '%s' "$PB" | grep -c '"expires_at"')"
  # 5-A″ — 이름 칸을 건드리지 않았으면 이름도 보내지 않는다(바뀐 칸만).
  expect "PATCH 본문에 display_name 없음"  0 "$(printf '%s' "$PB" | grep -c '"display_name"')"
else
  echo "  ! Playwright 확인을 실행하지 못했습니다 — 아래 오류를 보고에 적습니다 (미검증)"
  sed -n '1,20p' "$OUT/shots.err"
  FAIL=$((FAIL+1))
fi

# ═════════════════════════════════════════════════════════════
hr "T-53 (a) 재인증이 만료돼도 사이드바가 닫히지 않는다"
# ═════════════════════════════════════════════════════════════
# 재인증 창을 psql 로 20분 전으로 돌린다 (T-36 이 쓰는 것과 **같은 방법** — 검토 계정의 세션 행만)
psqlc "UPDATE sessions SET reauth_at = now() - interval '20 minutes' WHERE id = '$SID_ADMIN';"
echo "  claude_admin01 세션의 reauth_at 을 20분 전으로 (재인증 만료 상태)"
R=$(call claude_admin01 GET /api/users "$TOK_ADMIN")
expect "만료 확인: /api/users → 403 next:reauth" 1 "$(body_of "$R" | grep -c '"next":"reauth"')"

cat > "$WORK/nav.py" <<'PYEOF'
import json, os, sys
from playwright.sync_api import sync_playwright
BASE, UA, OUT = sys.argv[1:4]
CK = sys.stdin.readline().rstrip('\n')   # 세션 쿠키는 표준입력 첫 줄(R-62 · py_secret)
EXE = os.path.expanduser("~/.cache/ms-playwright/chromium-1234/chrome-linux64/chrome")
ids = ["nav-admin", "nav-users", "nav-org", "nav-admin-permissions",
       "nav-audit", "nav-trash", "nav-wiki"]
out = {}
with sync_playwright() as p:
    b = p.chromium.launch(executable_path=EXE, args=["--no-sandbox"])
    c = b.new_context(user_agent=UA, viewport={"width": 1440, "height": 900})
    c.add_cookies([{"name": k, "value": v, "url": BASE}
                   for k, v in (q.split("=", 1) for q in CK.split(";") if "=" in q)])
    pg = c.new_page()
    errs = []
    pg.on("pageerror", lambda e: errs.append(str(e)))
    pg.goto(BASE + "/admin", wait_until="networkidle")
    pg.wait_for_timeout(900)
    out["url"] = pg.url
    out["next_from_me"] = pg.evaluate(
        "() => fetch('/tbox/api/me',{credentials:'same-origin'}).then(r=>r.json()).then(j=>j.next)")
    el = pg.query_selector("#nav-admin")
    out["nav_admin_hidden"] = None if el is None else el.is_hidden()
    grp = pg.query_selector("#nav-admin-group")
    out["admin_group_hidden"] = None if grp is None else grp.is_hidden()
    out["admin_links_open"] = sum(
        1 for i in ids if (pg.query_selector("#" + i) and not pg.query_selector("#" + i).is_hidden()))
    out["console_errors"] = errs[:]
    pg.screenshot(path=OUT + "/admin-nav.png")
    b.close()
print(json.dumps(out, ensure_ascii=False, indent=2))
PYEOF

CK_ADMIN=$(jar_cookies "$JAR_DIR/claude_admin01.jar")
if py_secret CK_ADMIN -- python3 "$WORK/nav.py" "$BASE" "$UA" "$OUT" > "$OUT/nav.json" 2>"$OUT/nav.err"; then
  cat "$OUT/nav.json"
  njget() { python3 -c "import json;d=json.load(open('$OUT/nav.json'));v=d.get('$1');print('' if v is None else (json.dumps(v,ensure_ascii=False) if isinstance(v,(dict,list)) else v))"; }
  expect "재인증 만료 상태에서도 #nav-admin 열림" "False" "$(njget nav_admin_hidden)"
  expect "관리 묶음도 열림"                      "False" "$(njget admin_group_hidden)"
  expect "관리 링크 7개 열림"                    7 "$(njget admin_links_open)"
  expect "콘솔 오류 0건"                         "[]" "$(njget console_errors)"
else
  echo "  ! 관리 내비 확인을 실행하지 못했습니다 (미검증)"
  sed -n '1,20p' "$OUT/nav.err"
  FAIL=$((FAIL+1))
fi

# ═════════════════════════════════════════════════════════════
hr "T-54 (e) 폴더 삭제 경고 모달 — 목록이 보이나 (Playwright)"
# ═════════════════════════════════════════════════════════════
R=$(mkfolder claude_employee01 "$TOK_EMP1" "S26-modal" internal)
F_MODAL=$(new_id "$(body_of "$R")"); expect "모달 확인용 폴더 생성" 201 "$(code_of "$R")"
printf 'S26 modal file\n' > "$WORK/S26-modalfile.txt"
R=$(upload claude_employee01 "$TOK_EMP1" "$WORK/S26-modalfile.txt" internal "$F_MODAL")
FILE_MODAL=$(uuid_of "$(body_of "$R")"); expect "파일 업로드" 201 "$(code_of "$R")"
R=$(call claude_employee01 DELETE "/api/files/$FILE_MODAL" "$TOK_EMP1")
expect "휴지통으로" 200 "$(code_of "$R")"

cat > "$WORK/folder_modal.py" <<'PYEOF'
import json, os, sys
from playwright.sync_api import sync_playwright
BASE, UA, OUT, FOLDER = sys.argv[1:5]   # FOLDER 는 보고용
CK = sys.stdin.readline().rstrip('\n')   # 세션 쿠키는 표준입력 첫 줄(R-62 · py_secret)
EXE = os.path.expanduser("~/.cache/ms-playwright/chromium-1234/chrome-linux64/chrome")
out = {}
with sync_playwright() as p:
    b = p.chromium.launch(executable_path=EXE, args=["--no-sandbox"])
    c = b.new_context(user_agent=UA, viewport={"width": 1440, "height": 900})
    c.add_cookies([{"name": k, "value": v, "url": BASE}
                   for k, v in (q.split("=", 1) for q in CK.split(";") if "=" in q)])
    pg = c.new_page()
    pg.on("dialog", lambda d: d.accept())        # 첫 확인(window.confirm)은 받아 넘긴다
    pg.goto(BASE + "/", wait_until="networkidle")
    pg.wait_for_timeout(1200)
    # 폴더는 URL 이 아니라 트리에서 고른다(화면이 URL 을 읽지 않는다) — 이름으로 누른다.
    opened = False
    for it in pg.query_selector_all("#folder-list button, #folder-list a, #folder-list li"):
        if "S26-modal" in (it.inner_text() or ""):
            it.click()
            opened = True
            break
    out["folder_opened"] = opened
    pg.wait_for_timeout(1000)
    btn = pg.query_selector("#folder-delete")
    out["delete_button_found"] = btn is not None and btn.is_visible()
    if btn and btn.is_visible():
        btn.click()
        pg.wait_for_timeout(600)
        # Q:F-4 U-2a — 첫 확인이 window.confirm 이 아니라 앱의 확인 창(「폴더 지우기」)이 됐다. 그 단추를 눌러야
        # DELETE 가 나가고, 휴지통 파일만 남았으면 서버 답에 따라 두 번째 창(#folder-trashed-dialog)이 뜬다.
        ok = pg.query_selector("dialog[open] button:has-text('폴더 지우기')")
        out["first_confirm_is_app_dialog"] = ok is not None
        if ok:
            ok.click()
        pg.wait_for_timeout(1200)
        dlg = pg.query_selector("#folder-trashed-dialog")
        out["dialog_open"] = bool(dlg and dlg.is_visible())
        out["dialog_text"] = dlg.inner_text().replace("\n", " ") if dlg else None
        pg.screenshot(path=OUT + "/folder-trashed-modal.png")
    b.close()
print(json.dumps(out, ensure_ascii=False, indent=2))
PYEOF

COOKIES_EMP=$(awk '(!/^#/ || /^#HttpOnly_/) && NF>=7 {printf "%s=%s;", $6, $7}' "$JAR_DIR/claude_employee01.jar")
if py_secret COOKIES_EMP -- python3 "$WORK/folder_modal.py" "$BASE" "$UA" "$OUT" "$F_MODAL" > "$OUT/folder_modal.json" 2>"$OUT/folder_modal.err"; then
  cat "$OUT/folder_modal.json"
  DT=$(python3 -c "import json;d=json.load(open('$OUT/folder_modal.json'));print(d.get('dialog_text') or '')")
  expect "경고 모달이 열림" 1 "$(printf '%s' "$DT" | grep -c '휴지통에 있는 파일이 남아 있습니다')"
  expect "모달에 파일 이름이 보임" 1 "$(printf '%s' "$DT" | grep -c 'S26-modalfile.txt')"
  expect "모달에 확인 단추 문구" 1 "$(printf '%s' "$DT" | grep -c '휴지통 파일을 옮기고 폴더 삭제')"
else
  echo "  ! 폴더 모달 확인을 실행하지 못했습니다 (미검증)"
  sed -n '1,20p' "$OUT/folder_modal.err"
  FAIL=$((FAIL+1))
fi

echo
echo "  스크린샷·JSON: $OUT"
