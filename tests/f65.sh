#!/usr/bin/env bash
# tests/f65.sh — F-65 「실행 파일이 든 압축본의 관리자 확인 업로드·확인 다운로드」
#                수락 기준 실행 (PRD 12.2 T-77, F-65, S-06, S-01, S-07, R-45, R-94, ARCH 5.29)
#
#   (a) 올리기  — claude_admin01 · admin-only · 확인 없이 → 400 `{next:"exec_ack",entries:[…]}` +
#                 감사 `file.upload_exec deny exec_ack_required`(detail.exec_entry_count=2) ;
#                 `exec_ack=1` + 틀린 비밀번호 → 400 통일 문구 + `bad_credentials` +
#                 `users.failed_count` 증가 ; 맞는 비밀번호 + 틀린 코드 → 400 `bad_code` ;
#                 맞는 코드 → 201 · 목록 `exec_content:true` · DB `exec_content=true` ·
#                 감사 `file.upload_exec allow`(`exec_ack`·`otp_verified`·`exec_entry_count`·
#                 `exec_entries`) · **비밀번호·코드 문자열이 감사 detail·app.log 에 0건** ;
#                 같은 코드 재사용 → 400 `code_reused`
#   (b) 예외가 아닌 갈래 — claude_employee01(internal) · claude_admin01(internal) ·
#                 claude_admin01 「역할로 보기」(Employee) 중 admin-only · 개인 토큰 `POST /api/upload`
#                 → 전부 400 `zip_blocked_entry` 문장(F-68 · ARCH 5.30)이고 **응답에 `next` 가
#                 없다**(확인 창은 열 수 있는 사람에게만) ; 정상 zip 은 확인 없이 201
#   (c) 받기    — `GET …/download` 403 `next:"exec_ack"` · `…/preview` 403 ·
#                 개인 토큰 GET download 403 `exec_content_token` · `POST …/share` 400 ·
#                 `PATCH {access_level}` 400 · `PATCH {folder_id}` 200 ·
#                 `POST …/exec-download` 틀린 비밀번호 400 · 맞으면 200 + attachment +
#                 본문 sha256 일치 + 감사 `file.download_exec allow otp_verified` ·
#                 `exec_content=false` 파일에 exec-download → 400 `bad_request`
#   (d) AL-21   — `alerts.php --rule AL-21 --dry-run --state <임시>` 1건, evidence 에
#                 파일명·계정·항목 수, 비밀번호 0건
#   (e) 화면    — Playwright(claude_admin01): 확인 블록(항목 이름 2개·체크·비밀번호·코드) ·
#                 체크 없이 누르면 요청 0건 · 채우면 201 · 배지 · 확인 다운로드 창 ·
#                 공유 단추 없음 · 콘솔 오류 0
#   (f) 회귀    — security_lint 0건 · 바뀐 PHP `php -l` · `bash -n`
#   (g) 마이그레이션 — **임시 DB 로만** 검증한다(T-76 (f) 방식). 운영 DB 실행은 **사용자 몫**이다.
#   (h) 정리    — 시험 파일은 휴지통으로 · 만든 토큰 회수 · 만든 폴더 삭제 · 비-qa 행 수 불변
#
# ★ 배포 순서 (중요)
#   운영 DB 에 `files.exec_content` 열이 **없으면** 새 코드의 SELECT 가 실패한다.
#   그러므로 순서는 「마이그레이션 → 코드」이고, 이 스크립트는 열이 없으면 (a)~(e) 를 전부
#   SKIP 하고 (f)(g) 만 돈다. 실행 명령은 아래 (g) 절이 찍어 준다.
#
# 규칙 (CLAUDE.md 3장)
#   · 검토 계정은 `claude_admin01`·`claude_employee01` 뿐이다. 시드 계정(admin01 …)은 사용자 몫이다.
#   · 모든 요청에 `-A "tbox-review/<날짜>"` — 감사 로그에서 사람의 행동과 갈라 보려는 것.
#   · 운영 데이터에 손대지 않는다. 올린 파일은 앱 창구(`DELETE /api/files/{id}`)로만 되돌린다.
#   · 운영 DB 에 DDL·DML 을 실행하지 않는다. 마이그레이션은 **임시 DB** 에만 적용한다.
#   · 비밀번호·2단계 코드는 **화면에 찍지 않는다** — 확인은 「감사·로그에 없다」 쪽으로만 한다.
#
# ★ 시험 안전 (SECURITY R-73 · R-80)
#   ① 최근 10분 감사 로그에 비-qa actor 가 있으면 시작하지 않는다 — 2분 간격으로 최대 10회 기다린다
#   ② 만드는 파일·폴더·토큰은 전부 `F65-` 접두
#   ③ 루트 파티션 여유가 1GB 미만이면 시작하지 않는다
#
# ★ 실패 카운터 (SECURITY R-48) — 이 시험은 **일부러 비밀번호·코드를 틀린다**. 5회면 계정이
#   잠기므로, 갈래 사이에 다시 로그인해 카운터를 0 으로 되돌린다(로그인 성공이 SQL_CLEAR_FAILED).
#   그래도 한 갈래 안에서 4회를 넘기지 않는다.
#
# 준비물: curl · php(TOTP) · python3(zip 생성 · JSON · 선택적으로 playwright) ·
#         sudo -n(seed-accounts.txt · qa-totp.txt · psql · createdb/dropdb)
# 사용: sudo -v && bash tests/f65.sh
set -u

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
# 검토 계정 판정 한 벌(qa_·claude_ — F-71 C). 못 읽으면 사전 검사가 빈 값으로 열리므로 시작하지 않는다.
. "$ROOT/tests/lib/review_accounts.sh" || { echo "tests/lib/review_accounts.sh 를 읽지 못했다 — 시험을 시작하지 않는다 (R-73)"; exit 3; }
. "$ROOT/tests/lib/secrets.sh" || { echo "tests/lib/secrets.sh 를 읽지 못했다 — 시험을 시작하지 않는다 (R-62)"; exit 3; }
. "$ROOT/tests/lib/db_read.sh" || { echo "tests/lib/db_read.sh 를 읽지 못했다 — 시험을 시작하지 않는다 (R-73)"; exit 3; }
BASE="http://127.0.0.1:8082/tbox"
UA="tbox-review/$(date +%F)"
DB="tbox_re"
TMPDB="tbox_f65_fresh"
JAR_DIR="$(mktemp -d)"
WORK="$(mktemp -d)"
TAG="$(date +%H%M%S)"
PASS=0
FAIL=0
SKIP=0

UPLOADED=""          # 휴지통으로 되돌릴 public_id 들
MADE_TOKENS=""       # "uid:tokenid" 목록
MADE_FOLDERS=""      # folders.id 목록

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
    *)      printf '  ✖ %s  (「%s」 없음 — 실제: %s)\n' "$1" "$2" "$3"; FAIL=$((FAIL+1)) ;;
  esac
}
note() { printf '  · %s\n' "$1"; }
skip() { printf '  ○ SKIP %s  (%s)\n' "$1" "$2"; SKIP=$((SKIP+1)); }

psqlq() { sudo -n -u postgres psql -d "$DB" -t -A -c "$1" 2>/dev/null; }

# 감사 로그의 물길 표시. 시각이 아니라 id 로 자른다(같은 초의 앞 항목이 섞이지 않게).
mark() { psqlq "SELECT coalesce(max(id),0) FROM audit_logs" 2>/dev/null; }

code_of() { printf '%s' "$1" | tail -1; }
body_of() { printf '%s' "$1" | sed '$d'; }
uuid_of() { printf '%s' "$1" | sed -n 's/.*"id":"\([0-9a-f-]\{36\}\)".*/\1/p' | head -1; }

# JSON 한 조각 — python3 로만 읽는다(따옴표 안의 한글·특수문자에 sed 가 흔들리지 않게).
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

qa_password() { sudo -n awk -v u="$1" '$1==u{print $3}' /etc/tbox-v2/seed-accounts.txt 2>/dev/null; }
qa_secret()   { sudo -n awk -v u="$1" '$1==u{print $2}' /etc/tbox-v2/qa-totp.txt 2>/dev/null; }

# 지금 창의 코드와 남은 초 — "코드 남은초" 한 줄
# (이름 totp_pair — tests/lib/secrets.sh 의 totp_now <변수 이름> 과 겹치지 않게. 비밀은 표준입력으로 php 에)
totp_pair() {   # $1 = 계정
  local sec raw
  sec=$(qa_secret "$1")
  [ -n "$sec" ] || return 1
  raw=$(totp_now sec 2>/dev/null) || return 1
  printf '%s %s\n' \
    "$(printf '%s' "$raw" | head -1)" \
    "$(printf '%s' "$raw" | sed -n 's/^남은 시간: \([0-9]*\)초.*/\1/p')"
}

# **다음 창**의 코드를 받는다 — 방금 쓴 코드를 다시 쓰면 `code_reused` 가 정상이므로
# (R-45), 새 코드가 필요한 자리에서는 창이 바뀔 때까지 기다린다 (stage26.sh login 과 같은 방식).
totp_fresh() {   # $1 = 계정  → 코드만
  local pair left
  pair=$(totp_pair "$1") || return 1
  left=${pair##* }
  [ -n "$left" ] || left=31
  printf '  · %s 다음 2단계 코드 창까지 %s초 기다린다 (R-45 1회성)\n' "$1" "$left" >&2
  sleep $((left + 1))
  pair=$(totp_pair "$1") || return 1
  printf '%s\n' "${pair%% *}"
}

login() {   # $1 = 계정 → 성공하면 표준출력에 csrf 토큰
  local who="$1" jar="$JAR_DIR/$1.jar" pw sec raw code left out try body
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
    printf '  · %s 코드 재사용으로 거부됨 — 다음 창까지 %s초 기다림 (R-45 가 의도대로 막은 것)\n' "$who" "$left" >&2
    sleep $((left + 1))
    try=$try
  done
  return 1
}

reauth() {   # $1 계정  $2 csrf → HTTP 코드
  local pw body
  pw=$(qa_password "$1")
  printf -v body '{"password":"%s"}' "$pw"
  curl_json_secret body -s -o /dev/null -w '%{http_code}' -b "$JAR_DIR/$1.jar" -A "$UA" \
    -H 'Content-Type: application/json' -H "X-CSRF-Token: $2" "$BASE/api/me/reauth"
}

# 악성코드 검사가 끝나기를 기다린다 — `.env` 의 SCAN_ENABLED=1 이면 올린 직후는 `pending` 이고,
# **모든 받기 창구**가 423 「검사 중」을 준다 (ARCH 5.28). exec-download 도 예외가 아니다:
# 순서가 「비밀번호 → 코드 → 검사 관문 → 전송」이라(ARCH 5.29 받기 표) 검사가 안 끝났으면
# 코드를 맞혀도 423 이다.
#
# ★ 3회차 실측(2026-09-21): 이 기다림이 없어 (c) 의 200 항목 다섯이 423 으로 어긋났다.
#   2회차에 우연히 통과한 것은 1분 cron 이 그 사이에 돈 덕이었다 — 시험이 운에 기대면 안 된다.
#   그래서 cron 을 기다리지 않고 **배치를 직접** 한 번씩 돌린다 (f54.sh run_batch 와 같은 방식).
wait_clean() {   # $1 public_id → 0 이면 clean
  local id="$1" i st
  [ -n "$id" ] || return 1
  if [ "$HAVE_PSQL" -eq 0 ]; then
    note "psql 이 없어 검사 상태를 볼 수 없다 — 70초 기다린다(1분 cron 기준)"
    sleep 70
    return 0
  fi
  for i in 1 2 3 4 5 6 7 8 9 10; do
    st=$(psqlq "SELECT scan_status FROM files WHERE public_id='$id'")
    case "${st:-}" in
      clean)    [ "$i" -gt 1 ] && note "검사 완료(clean) — ${i}회째"; return 0 ;;
      infected) note "격리됨(infected) — 시험 파일이 격리됐다. 이후 받기 항목은 뜻이 달라진다"; return 1 ;;
    esac
    sudo -n -u tbox-php php "$ROOT/scripts/scan_pending.php" --force-enabled >/dev/null 2>&1 || true
    sleep 5
  done
  note "검사가 10회 안에 끝나지 않았다 (마지막 상태: ${st:-?})"
  return 1
}

# ─── 시작 전 관문 ────────────────────────────────────────────────
hr "F-65 실행 파일이 든 압축본 — 시험 시작 전 확인"

avail_kb=$(df -Pk / | awk 'NR==2{print $4}')
echo "루트 파티션 여유: $(df -Ph / | awk 'NR==2{print $4}') (${avail_kb}KB)"
[ "$avail_kb" -ge 1048576 ] || { echo "루트 디스크 여유 1GB 미만 — 시험을 시작하지 않는다 (SECURITY R-80)"; exit 3; }

HAVE_PSQL=0
if psqlq "SELECT 1" >/dev/null 2>&1; then HAVE_PSQL=1; fi

# R-73 ① — 사용자가 지금 서비스를 쓰고 있으면 기다린다 (2분 간격, 최대 10회).
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

# 운영 DB 에 열이 있나 — 없으면 HTTP 항목을 돌리지 않는다 (배포 순서: 마이그레이션 → 코드).
HAVE_COLUMN=0
if [ "$HAVE_PSQL" -eq 1 ]; then
  HAVE_COLUMN=$(psqlq "SELECT count(*) FROM information_schema.columns WHERE table_name='files' AND column_name='exec_content'")
  HAVE_COLUMN=${HAVE_COLUMN:-0}
fi
echo "운영 DB files.exec_content 열: ${HAVE_COLUMN}개"
if [ "$HAVE_COLUMN" != "1" ]; then
  echo "  ! 열이 없다 — (a)~(e) 는 전부 SKIP 한다. 사용자에게 아래를 실행하도록 청한 뒤 다시 돌린다:"
  echo "      sudo -u postgres psql -d $DB -v ON_ERROR_STOP=1 -f db/migrations/2026-09-21_files_exec_content.sql"
fi

# 비-qa **사람** 감사 행 수 — 끝에서 같은 값인지 본다 (stage26 방식, 사용자 데이터 불변 증거).
#
# ★ 조건은 위 R-73 관문(188행)과 **같아야** 한다: `actor_name IS NOT NULL AND $REVIEW_NOT_SQL`(검토 계정 qa_·claude_).
#   `COALESCE(actor_name, target_name)` 로 쓰면 **시스템 행**(actor 가 없는 배치 기록)이
#   「사용자 행」으로 잡힌다 — 2026-09-21 실측에서 시험이 만든 `file.scan allow F65-exec.zip`
#   과 `alert.raised AL-21` 둘이 그렇게 잡혀 2418→2420 으로 보였다. 둘 다 이 시험이 **의도해서**
#   만든 배치 기록이지 사용자의 행동이 아니다. 그래서 사람 행은 이 조건으로 세고,
#   시스템 행은 아래 (h) 에서 「시험이 만든 N건」으로 따로 세어 보고만 한다.
NONQA_BEFORE=""
SYS_BEFORE=""
AUDIT_START=0
if [ "$HAVE_PSQL" -eq 1 ]; then
  db_read_var NONQA_BEFORE "$DB" "SELECT count(*) FROM audit_logs WHERE actor_name IS NOT NULL AND $REVIEW_NOT_SQL" \
    || { echo "운영 DB 를 읽지 못했다 — 시험을 시작하지 않는다 (R-73 · db_read rc 3)"; exit 3; }
  db_read_var SYS_BEFORE "$DB" "SELECT count(*) FROM audit_logs WHERE actor_name IS NULL" \
    || { echo "운영 DB 를 읽지 못했다 — 시험을 시작하지 않는다 (R-73 · db_read rc 3)"; exit 3; }
  db_read_var AUDIT_START "$DB" "SELECT coalesce(max(id),0) FROM audit_logs" \
    || { echo "운영 DB 를 읽지 못했다 — 시험을 시작하지 않는다 (R-73 · db_read rc 3)"; exit 3; }
fi

# ─── 시험 파일 ───────────────────────────────────────────────────
hr "시험용 zip 만들기 (표준 라이브러리 zipfile 만 쓴다)"

python3 - "$WORK" <<'PY'
import os, sys, zipfile
d = sys.argv[1]
p = lambda n: os.path.join(d, n)

# 차단 항목 2개(x.php · run.sh)와 정상 1개(a.txt). F-53 이름 검사에 걸리고,
# 경로 탈출·중첩·폭탄·매크로에는 걸리지 않는다 — F-65 갈래 하나만 타게 하려는 것이다.
with zipfile.ZipFile(p('F65-exec.zip'), 'w', zipfile.ZIP_DEFLATED) as z:
    z.writestr('x.php', '<?php echo 1;')
    z.writestr('run.sh', '#!/bin/sh\necho 1\n')
    z.writestr('a.txt', 'hello f65')

# 같은 내용이지만 (b) 갈래에서 쓰는 두 번째 사본 (이름만 다르다)
with zipfile.ZipFile(p('F65-exec2.zip'), 'w', zipfile.ZIP_DEFLATED) as z:
    z.writestr('x.php', '<?php echo 1;')
    z.writestr('run.sh', '#!/bin/sh\necho 1\n')
    z.writestr('a.txt', 'hello f65')

# 차단 항목이 없는 정상 zip — 확인 없이 201 이어야 한다 (회귀)
with zipfile.ZipFile(p('F65-ok.zip'), 'w', zipfile.ZIP_DEFLATED) as z:
    z.writestr('a.txt', 'plain one')
    z.writestr('b.txt', 'plain two')
print('made', ', '.join(sorted(os.listdir(d))))
PY

EXEC_ZIP="$WORK/F65-exec.zip"
EXEC_ZIP2="$WORK/F65-exec2.zip"
OK_ZIP="$WORK/F65-ok.zip"
EXEC_SHA=$(sha256sum "$EXEC_ZIP" | awk '{print $1}')
note "F65-exec.zip sha256 = $EXEC_SHA"

# ─── 세션 ────────────────────────────────────────────────────────
hr "준비 — 검토 계정 세션 (claude_admin01 · claude_employee01)"
ADM_CSRF=""; EMP_CSRF=""
ADM_CSRF=$(login claude_admin01)    || ADM_CSRF=""
EMP_CSRF=$(login claude_employee01) || EMP_CSRF=""
[ -n "$ADM_CSRF" ] && note "claude_admin01 로그인 OK" || note "claude_admin01 로그인 실패 — 관련 항목 SKIP"
[ -n "$EMP_CSRF" ] && note "claude_employee01 로그인 OK" || note "claude_employee01 로그인 실패 — 관련 항목 SKIP"

ADM_JAR="$JAR_DIR/claude_admin01.jar"
EMP_JAR="$JAR_DIR/claude_employee01.jar"

RUN_HTTP=0
if [ "$HAVE_COLUMN" = "1" ] && [ -n "$ADM_CSRF" ]; then RUN_HTTP=1; fi

# 업로드 한 번 — CODE·BODY·NEWID 를 채운다.
CODE=""; BODY=""; NEWID=""
up_exec() {   # $1 jar  $2 csrf  $3 파일  $4 등급  $5 exec_ack("" 또는 1)  $6 비밀번호  $7 코드
  local out args up_pw
  set -- "$1" "$2" "$3" "$4" "${5:-}" "${6:-}" "${7:-}"
  if [ -n "$5" ]; then
    # 비밀번호 필드는 인자가 아니라 표준입력(-F 'password=<-')으로 — curl_form_secret (R-62)
    up_pw="$6"
    out=$(curl_form_secret password up_pw -s -w '\n%{http_code}' -X POST -b "$1" -A "$UA" -H "X-CSRF-Token: $2" \
          -F "file=@$3" -F "access_level=$4" \
          -F "exec_ack=$5" -F "otp=$7" "$BASE/api/files")
  else
    out=$(curl -s -w '\n%{http_code}' -X POST -b "$1" -A "$UA" -H "X-CSRF-Token: $2" \
          -F "file=@$3" -F "access_level=$4" "$BASE/api/files")
  fi
  CODE=$(code_of "$out"); BODY=$(body_of "$out"); NEWID=$(uuid_of "$BODY")
}

# ─── (a) 올리기 ──────────────────────────────────────────────────
hr "(a) 올리기 — 확인 없이 → 400 next · 틀린 비밀번호 · 틀린 코드 · 맞으면 201 · 코드 재사용"

A_ID=""
if [ "$RUN_HTTP" -eq 0 ]; then
  skip "(a) 전체" "운영 DB 열 없음 또는 claude_admin01 세션 없음"
else
  M=$(mark)
  sub "① 확인 없이 (admin-only)"
  up_exec "$ADM_JAR" "$ADM_CSRF" "$EXEC_ZIP" "admin-only"
  expect "(a) 확인 없이 → 상태" "400" "$CODE"
  expect "(a) 확인 없이 → next" "exec_ack" "$(printf '%s' "$BODY" | jget "d.get('next','')")"
  expect "(a) 확인 없이 → entries" "run.sh,x.php" \
    "$(printf '%s' "$BODY" | jget "','.join(sorted(d.get('entries',[])))")"
  contains "(a) 확인 없이 → 문구" "실행 파일이 든 압축본입니다" "$BODY"
  if [ "$HAVE_PSQL" -eq 1 ]; then
    expect "(a) 감사 file.upload_exec deny exec_ack_required" "1" \
      "$(psqlq "SELECT count(*) FROM audit_logs WHERE id > ${M:-0} AND action='file.upload_exec' AND result='deny' AND reason='exec_ack_required'")"
    expect "(a) detail.exec_entry_count" "2" \
      "$(psqlq "SELECT detail->>'exec_entry_count' FROM audit_logs WHERE id > ${M:-0} AND action='file.upload_exec' AND reason='exec_ack_required' ORDER BY id DESC LIMIT 1")"
  else skip "(a) 감사 확인" "psql 없음"; fi

  sub "② exec_ack=1 + 틀린 비밀번호 → 400 통일 문구 + bad_credentials + failed_count 증가"
  FC_BEFORE=0
  [ "$HAVE_PSQL" -eq 1 ] && FC_BEFORE=$(psqlq "SELECT failed_count FROM users WHERE username='claude_admin01'")
  M=$(mark)
  up_exec "$ADM_JAR" "$ADM_CSRF" "$EXEC_ZIP" "admin-only" "1" "wrong-password-$TAG" "000000"
  expect "(a) 틀린 비밀번호 → 상태" "400" "$CODE"
  expect "(a) 틀린 비밀번호 → 통일 문구" "잘못된 요청입니다" "$(printf '%s' "$BODY" | jget "d.get('error','')")"
  expect "(a) 틀린 비밀번호 → 응답에 next 없음" "0" "$(printf '%s' "$BODY" | grep -c 'next' || true)"
  if [ "$HAVE_PSQL" -eq 1 ]; then
    expect "(a) 감사 file.upload_exec deny bad_credentials" "1" \
      "$(psqlq "SELECT count(*) FROM audit_logs WHERE id > ${M:-0} AND action='file.upload_exec' AND result='deny' AND reason='bad_credentials'")"
    FC_AFTER=$(psqlq "SELECT failed_count FROM users WHERE username='claude_admin01'")
    expect "(a) users.failed_count 증가" "$((${FC_BEFORE:-0} + 1))" "${FC_AFTER:-0}"
  else skip "(a) 실패 집계 확인" "psql 없음"; fi

  sub "③ 맞는 비밀번호 + 틀린 코드 → 400 bad_code"
  M=$(mark)
  up_exec "$ADM_JAR" "$ADM_CSRF" "$EXEC_ZIP" "admin-only" "1" "$(qa_password claude_admin01)" "000000"
  expect "(a) 틀린 코드 → 상태" "400" "$CODE"
  expect "(a) 틀린 코드 → 통일 문구" "잘못된 요청입니다" "$(printf '%s' "$BODY" | jget "d.get('error','')")"
  if [ "$HAVE_PSQL" -eq 1 ]; then
    expect "(a) 감사 file.upload_exec deny bad_code" "1" \
      "$(psqlq "SELECT count(*) FROM audit_logs WHERE id > ${M:-0} AND action='file.upload_exec' AND result='deny' AND reason='bad_code'")"
  else skip "(a) bad_code 감사" "psql 없음"; fi

  sub "④ 맞는 비밀번호 + 맞는 코드 → 201"
  OTP=$(totp_fresh claude_admin01)
  M=$(mark)
  up_exec "$ADM_JAR" "$ADM_CSRF" "$EXEC_ZIP" "admin-only" "1" "$(qa_password claude_admin01)" "$OTP"
  expect "(a) 확인하고 올리기 → 상태" "201" "$CODE"
  A_ID="$NEWID"
  if [ -n "$A_ID" ]; then
    UPLOADED="$UPLOADED $A_ID"
    if [ "$HAVE_PSQL" -eq 1 ]; then
      expect "(a) DB exec_content" "t" "$(psqlq "SELECT exec_content FROM files WHERE public_id='$A_ID'")"
      expect "(a) 감사 file.upload_exec allow" "1" \
        "$(psqlq "SELECT count(*) FROM audit_logs WHERE id > ${M:-0} AND action='file.upload_exec' AND result='allow'")"
      expect "(a) 감사 file.upload allow 는 남지 않는다" "0" \
        "$(psqlq "SELECT count(*) FROM audit_logs WHERE id > ${M:-0} AND action='file.upload' AND result='allow'")"
      expect "(a) detail 키 넷" "t" \
        "$(psqlq "SELECT (detail ? 'exec_ack') AND (detail ? 'otp_verified') AND (detail ? 'exec_entry_count') AND (detail ? 'exec_entries') FROM audit_logs WHERE id > ${M:-0} AND action='file.upload_exec' AND result='allow' ORDER BY id DESC LIMIT 1")"
      expect "(a) detail.exec_entries 개수" "2" \
        "$(psqlq "SELECT jsonb_array_length(detail->'exec_entries') FROM audit_logs WHERE id > ${M:-0} AND action='file.upload_exec' AND result='allow' ORDER BY id DESC LIMIT 1")"
      expect "(a) 목록 exec_content" "True" \
        "$(curl -s -b "$ADM_JAR" -A "$UA" "$BASE/api/files?page=1" | jget "[f['exec_content'] for f in d['files'] if f['id']=='$A_ID'][0]")"

      sub "⑤ 비밀번호·코드 문자열이 감사 detail 에 0건 (PRD 7.7)"
      expect "(a) detail 에 비밀번호 문자열" "0" \
        "$(psqlq "SELECT count(*) FROM audit_logs WHERE id > ${M:-0} AND detail::text LIKE '%' || (SELECT 'claude_admin01') || '-pw%'")"
      expect "(a) detail 에 방금 쓴 코드 문자열" "0" \
        "$(psqlq "SELECT count(*) FROM audit_logs WHERE happened_at > now() - interval '30 minutes' AND detail::text LIKE '%${OTP}%'")"
      expect "(a) detail 에 password·otp 키" "0" \
        "$(psqlq "SELECT count(*) FROM audit_logs WHERE happened_at > now() - interval '30 minutes' AND (detail ? 'password' OR detail ? 'otp')")"
    else skip "(a) DB·감사 확인" "psql 없음"; fi
  else
    skip "(a) 이후 항목" "업로드 id 를 받지 못했다"
  fi

  sub "⑥ 같은 코드 재사용 → 400 code_reused (R-45)"
  M=$(mark)
  up_exec "$ADM_JAR" "$ADM_CSRF" "$EXEC_ZIP2" "admin-only" "1" "$(qa_password claude_admin01)" "$OTP"
  expect "(a) 코드 재사용 → 상태" "400" "$CODE"
  if [ "$HAVE_PSQL" -eq 1 ]; then
    expect "(a) 감사 file.upload_exec deny code_reused" "1" \
      "$(psqlq "SELECT count(*) FROM audit_logs WHERE id > ${M:-0} AND action='file.upload_exec' AND result='deny' AND reason='code_reused'")"
  else skip "(a) code_reused 감사" "psql 없음"; fi

  # 실패 3회가 쌓였다 — 다시 로그인해 카운터를 0 으로 되돌린다 (R-48 잠금 방지).
  note "실패 카운터를 되돌리기 위해 claude_admin01 을 다시 로그인한다"
  NEW_CSRF=$(login claude_admin01) && ADM_CSRF="$NEW_CSRF"
fi

# ─── (b) 예외가 아닌 갈래 ────────────────────────────────────────
hr "(b) 예외가 아닌 갈래 — 힌트 없이 400 (ARCH 5.29 ①②)"

if [ "$HAVE_COLUMN" != "1" ]; then
  skip "(b) 전체" "운영 DB 열 없음"
else
  if [ -n "$EMP_CSRF" ]; then
    M=$(mark)
    up_exec "$EMP_JAR" "$EMP_CSRF" "$EXEC_ZIP2" "internal"
    expect "(b) 직원 internal → 상태" "400" "$CODE"
    expect "(b) 직원 internal → zip_blocked_entry 문장 (F-68 · ARCH 5.30)" "압축본 안에 실행 파일이나 스크립트가 있어 올릴 수 없습니다" "$(printf '%s' "$BODY" | jget "d.get('error','')")"
    expect "(b) 직원 internal → next 없음" "0" "$(printf '%s' "$BODY" | grep -c 'next' || true)"
    if [ "$HAVE_PSQL" -eq 1 ]; then
      expect "(b) 감사 file.upload deny zip_blocked_entry" "1" \
        "$(psqlq "SELECT count(*) FROM audit_logs WHERE id > ${M:-0} AND action='file.upload' AND result='deny' AND reason='zip_blocked_entry'")"
      expect "(b) file.upload_exec 행 0건" "0" \
        "$(psqlq "SELECT count(*) FROM audit_logs WHERE id > ${M:-0} AND action='file.upload_exec'")"
    else skip "(b) 직원 감사" "psql 없음"; fi
  else skip "(b) 직원 갈래" "claude_employee01 세션 없음"; fi

  if [ -n "$ADM_CSRF" ]; then
    up_exec "$ADM_JAR" "$ADM_CSRF" "$EXEC_ZIP2" "internal"
    expect "(b) 관리자 internal → 상태" "400" "$CODE"
    expect "(b) 관리자 internal → next 없음" "0" "$(printf '%s' "$BODY" | grep -c 'next' || true)"

    sub "「역할로 보기」(Employee) 중에는 admin-only 라도 예외가 열리지 않는다 (F-39)"
    RC=$(reauth claude_admin01 "$ADM_CSRF")
    if [ "$RC" = "200" ]; then
      AC=$(curl -s -o /dev/null -w '%{http_code}' -X POST -b "$ADM_JAR" -A "$UA" \
           -H 'Content-Type: application/json' -H "X-CSRF-Token: $ADM_CSRF" \
           -d '{"role":"Employee"}' "$BASE/api/me/act")
      if [ "$AC" = "200" ]; then
        up_exec "$ADM_JAR" "$ADM_CSRF" "$EXEC_ZIP2" "admin-only"
        # ★ 400 이 아니라 **403** 이 맞다 (2026-09-21 Fable 실측, 감사 15:55:03).
        #   「역할로 보기」로 Employee 가 된 세션은 F-65 갈래에 닿기 **전**에
        #   [3-2] `Authz::decideUploadAs` 가 admin-only 를 `role_not_permitted` 403 으로 막는다
        #   (ARCH 5.2 「권한 ✔ A」 — 등급 판정이 파일 검증보다 앞이다).
        #   즉 이 갈래는 F-65 이전과 **한 글자도 같고**, 확인 창이 열릴 자리 자체가 없다.
        #   막는 층이 둘(등급 권한 → F-65 자격)이라는 뜻이라 오히려 더 좁다.
        expect "(b) 역할로 보기 중 admin-only → 상태 (등급 권한이 먼저 막는다)" "403" "$CODE"
        expect "(b) 역할로 보기 중 → next 없음" "0" "$(printf '%s' "$BODY" | grep -c 'next' || true)"
        curl -s -o /dev/null -X DELETE -b "$ADM_JAR" -A "$UA" -H "X-CSRF-Token: $ADM_CSRF" "$BASE/api/me/act"
        note "「역할로 보기」를 껐다"
      else skip "(b) 역할로 보기" "POST /api/me/act → $AC"; fi
    else skip "(b) 역할로 보기" "재인증 실패 → $RC"; fi
  else skip "(b) 관리자 갈래" "claude_admin01 세션 없음"; fi

  sub "개인 토큰으로 프로그램 입구 POST /api/upload → 힌트 없는 400"
  TOK=""
  # ★ 토큰의 귀속 계정은 **claude_admin01** 이다 (2026-09-21 보정).
  #   claude_employee01 에 발급하면 (c) 의 「토큰으로 exec 파일 받기」가 `decideRead` 에서
  #   `role_not_permitted` 로 먼저 끝나 **F-65 관문(`exec_content_token`)에 닿지 못한다**
  #   (첫 실행에서 실측: 403 은 났지만 사유가 달랐다). API.md 9-1 이 약속하는 것은
  #   「읽을 수 있는 계정의 토큰이어도 못 받는다」 이므로, 읽을 수 있는 계정으로 발급해
  #   그 갈래를 실제로 지나게 한다.
  if [ -n "$ADM_CSRF" ] && [ "$HAVE_PSQL" -eq 1 ]; then
    ADM_UID=$(psqlq "SELECT id FROM users WHERE username='claude_admin01'")
    if [ -n "$ADM_UID" ]; then
      reauth claude_admin01 "$ADM_CSRF" >/dev/null
      curl -s -o "$WORK/issue.json" -w '%{http_code}' -X POST -b "$ADM_JAR" -A "$UA" \
        -H "X-CSRF-Token: $ADM_CSRF" -H 'Content-Type: application/json' \
        -d "{\"name\":\"F65-tok-$TAG\",\"reason_text\":\"T-77 (b)(c) 시험 — 토큰 입구·토큰 받기에 예외가 없는지\"}" \
        "$BASE/api/users/$ADM_UID/api-tokens" > "$WORK/issue.code"
      TOK=$(jget "d.get('token','')" < "$WORK/issue.json")
      TOKID=$(jget "str(d.get('id',''))" < "$WORK/issue.json")
      : > "$WORK/issue.json"
      [ -n "${TOKID:-}" ] && MADE_TOKENS="$MADE_TOKENS $ADM_UID:$TOKID"
    fi
  fi
  if [ -n "$TOK" ]; then
    OUT=$(curl_hdr_secret X-API-Token TOK -s -w '\n%{http_code}' -X POST -A "$UA" \
          -F "file=@$EXEC_ZIP2" -F 'level=public' "$BASE/api/upload")
    expect "(b) 토큰 입구 → 상태" "400" "$(code_of "$OUT")"
    expect "(b) 토큰 입구 → next 없음" "0" "$(body_of "$OUT" | grep -c 'next' || true)"
  else skip "(b) 토큰 입구" "개인 토큰을 발급하지 못했다"; fi

  sub "정상 zip 은 확인 없이 201 (회귀)"
  if [ -n "$ADM_CSRF" ]; then
    up_exec "$ADM_JAR" "$ADM_CSRF" "$OK_ZIP" "admin-only"
    expect "(b) 정상 zip → 상태" "201" "$CODE"
    OK_ID="$NEWID"
    [ -n "$OK_ID" ] && UPLOADED="$UPLOADED $OK_ID"
  else skip "(b) 정상 zip" "세션 없음"; OK_ID=""; fi
fi
: "${OK_ID:=}"
: "${TOK:=}"

# ─── (c) 받기 ────────────────────────────────────────────────────
hr "(c) 받기 — 네 창구는 막히고 exec-download 하나만 열린다"

if [ "$RUN_HTTP" -eq 0 ] || [ -z "$A_ID" ]; then
  skip "(c) 전체" "확인 업로드한 파일이 없다"
else
  M=$(mark)
  OUT=$(curl -s -w '\n%{http_code}' -b "$ADM_JAR" -A "$UA" "$BASE/api/files/$A_ID/download")
  expect "(c) GET download → 상태" "403" "$(code_of "$OUT")"
  expect "(c) GET download → next" "exec_ack" "$(body_of "$OUT" | jget "d.get('next','')")"
  if [ "$HAVE_PSQL" -eq 1 ]; then
    expect "(c) 감사 file.download deny exec_ack_required" "1" \
      "$(psqlq "SELECT count(*) FROM audit_logs WHERE id > ${M:-0} AND action='file.download' AND result='deny' AND reason='exec_ack_required'")"
  else skip "(c) download 감사" "psql 없음"; fi

  OUT=$(curl -s -w '\n%{http_code}' -b "$ADM_JAR" -A "$UA" "$BASE/api/files/$A_ID/preview")
  expect "(c) GET preview → 상태" "403" "$(code_of "$OUT")"
  expect "(c) GET preview → next" "exec_ack" "$(body_of "$OUT" | jget "d.get('next','')")"

  OUT=$(curl -s -w '\n%{http_code}' -b "$ADM_JAR" -A "$UA" "$BASE/api/files/$A_ID/preview/raw")
  expect "(c) GET preview/raw → 상태" "403" "$(code_of "$OUT")"

  if [ -n "$TOK" ]; then
    M=$(mark)
    OUT=$(curl_hdr_secret X-API-Token TOK -s -w '\n%{http_code}' -A "$UA" "$BASE/api/files/$A_ID/download")
    # 귀속 계정이 claude_admin01 이라 `decideRead` 는 **통과**한다 — 그래서 여기서 막는 것은
    # 권한이 아니라 F-65 관문이고, 사유가 `exec_content_token` 이어야 한다 (API.md 9-1).
    # 문구는 통일 문구다: 프로그램은 2단계 코드를 칠 수 없어 알려 줄 다음 단계가 없다.
    expect "(c) 토큰 GET download → 상태" "403" "$(code_of "$OUT")"
    expect "(c) 토큰 GET download → 통일 문구" "권한이 없습니다" "$(body_of "$OUT" | jget "d.get('error','')")"
    expect "(c) 토큰 GET download → next 없음" "0" "$(body_of "$OUT" | grep -c 'next' || true)"
    if [ "$HAVE_PSQL" -eq 1 ]; then
      expect "(c) 감사 file.download deny exec_content_token" "1" \
        "$(psqlq "SELECT count(*) FROM audit_logs WHERE id > ${M:-0} AND action='file.download' AND result='deny' AND reason='exec_content_token'")"
    else skip "(c) exec_content_token 감사" "psql 없음"; fi
  else skip "(c) 토큰 download" "토큰 없음"; fi

  M=$(mark)
  OUT=$(curl -s -w '\n%{http_code}' -X POST -b "$ADM_JAR" -A "$UA" \
        -H 'Content-Type: application/json' -H "X-CSRF-Token: $ADM_CSRF" \
        -d '{"expires_hours":1,"max_downloads":1}' "$BASE/api/files/$A_ID/share")
  expect "(c) POST share → 상태" "400" "$(code_of "$OUT")"
  if [ "$HAVE_PSQL" -eq 1 ]; then
    expect "(c) 감사 share.create deny exec_content" "1" \
      "$(psqlq "SELECT count(*) FROM audit_logs WHERE id > ${M:-0} AND action='share.create' AND result='deny' AND reason='exec_content'")"
  else skip "(c) share 감사" "psql 없음"; fi

  M=$(mark)
  OUT=$(curl -s -w '\n%{http_code}' -X PATCH -b "$ADM_JAR" -A "$UA" \
        -H 'Content-Type: application/json' -H "X-CSRF-Token: $ADM_CSRF" \
        -d '{"access_level":"internal"}' "$BASE/api/files/$A_ID")
  expect "(c) PATCH access_level → 상태" "400" "$(code_of "$OUT")"
  if [ "$HAVE_PSQL" -eq 1 ]; then
    expect "(c) 감사 file.update deny exec_content" "1" \
      "$(psqlq "SELECT count(*) FROM audit_logs WHERE id > ${M:-0} AND action='file.update' AND result='deny' AND reason='exec_content'")"
    expect "(c) 등급은 그대로 admin-only" "admin-only" \
      "$(psqlq "SELECT access_level FROM files WHERE public_id='$A_ID'")"
  else skip "(c) update 감사" "psql 없음"; fi

  sub "PATCH {folder_id} 는 200 — 막는 것은 등급뿐이다"
  FOUT=$(curl -s -w '\n%{http_code}' -X POST -b "$ADM_JAR" -A "$UA" \
         -H 'Content-Type: application/json' -H "X-CSRF-Token: $ADM_CSRF" \
         -d "{\"name\":\"F65-$TAG\",\"access_level\":\"admin-only\"}" "$BASE/api/folders")
  # 응답 모양은 `{"folder":{"id":…}}` 다 (folders_create.php 238행) — 최상위 `id` 가 아니다.
  FID=$(body_of "$FOUT" | jget "str(d.get('folder',{}).get('id',''))")
  if [ "$(code_of "$FOUT")" = "201" ] && [ -n "$FID" ]; then
    MADE_FOLDERS="$MADE_FOLDERS $FID"
    OUT=$(curl -s -w '\n%{http_code}' -X PATCH -b "$ADM_JAR" -A "$UA" \
          -H 'Content-Type: application/json' -H "X-CSRF-Token: $ADM_CSRF" \
          -d "{\"folder_id\":$FID}" "$BASE/api/files/$A_ID")
    expect "(c) PATCH folder_id → 상태" "200" "$(code_of "$OUT")"
  else skip "(c) PATCH folder_id" "시험용 폴더를 만들지 못했다 ($(code_of "$FOUT"))"; fi

  sub "POST …/exec-download — 틀린 비밀번호 → 400"
  OUT=$(curl -s -w '\n%{http_code}' -X POST -b "$ADM_JAR" -A "$UA" \
        -H 'Content-Type: application/json' -H "X-CSRF-Token: $ADM_CSRF" \
        -d "{\"password\":\"wrong-$TAG\",\"otp\":\"000000\"}" "$BASE/api/files/$A_ID/exec-download")
  expect "(c) exec-download 틀린 비밀번호 → 상태" "400" "$(code_of "$OUT")"
  expect "(c) exec-download 틀린 비밀번호 → 통일 문구" "잘못된 요청입니다" \
    "$(body_of "$OUT" | jget "d.get('error','')")"

  sub "모르는 본문 키 → 400 bad_request"
  OUT=$(curl -s -w '\n%{http_code}' -X POST -b "$ADM_JAR" -A "$UA" \
        -H 'Content-Type: application/json' -H "X-CSRF-Token: $ADM_CSRF" \
        -d '{"password":"x","otp":"000000","admin":true}' "$BASE/api/files/$A_ID/exec-download")
  expect "(c) exec-download 모르는 키 → 상태" "400" "$(code_of "$OUT")"

  sub "맞는 비밀번호 + 맞는 코드 → 200 · attachment · sha256 일치"
  # 검사(F-54)가 끝난 뒤에 물어야 한다 — 안 끝났으면 코드를 맞혀도 423 이 정답이다.
  # 코드가 **먼저** 쓰이고 그 뒤에 423 이 나면 그 코드만 버려지므로(1회성), 순서가 중요하다.
  wait_clean "$A_ID" || note "검사가 끝나지 않은 채로 아래를 시도한다 — 423 이면 그것이 정상이다"
  # 실패 2회가 쌓였으니 다시 로그인해 카운터를 되돌린다 (R-48).
  NEW_CSRF=$(login claude_admin01) && ADM_CSRF="$NEW_CSRF"
  OTP2=$(totp_fresh claude_admin01)
  M=$(mark)
  DL_HDR="$WORK/F65-exec-dl.hdr"
  DL_PW=$(qa_password claude_admin01)
  printf -v body '{"password":"%s","otp":"%s"}' "$DL_PW" "$OTP2"
  curl_json_secret body -s -D "$DL_HDR" -o "$WORK/F65-got.zip" -X POST -b "$ADM_JAR" -A "$UA" \
    -H 'Content-Type: application/json' -H "X-CSRF-Token: $ADM_CSRF" \
    "$BASE/api/files/$A_ID/exec-download"
  DL_PW=""; body=""
  expect "(c) exec-download → 상태" "200" \
    "$(sed -n 's#^HTTP/[0-9.]* \([0-9][0-9][0-9]\).*#\1#p' "$DL_HDR" | tail -1)"
  expect "(c) Content-Disposition attachment" "1" \
    "$(grep -ic '^content-disposition: *attachment' "$DL_HDR" || true)"
  expect "(c) 받은 본문 sha256 = 올린 zip" "$EXEC_SHA" "$(sha256sum "$WORK/F65-got.zip" | awk '{print $1}')"
  if [ "$HAVE_PSQL" -eq 1 ]; then
    expect "(c) 감사 file.download_exec allow" "1" \
      "$(psqlq "SELECT count(*) FROM audit_logs WHERE id > ${M:-0} AND action='file.download_exec' AND result='allow'")"
    expect "(c) detail.otp_verified" "true" \
      "$(psqlq "SELECT detail->>'otp_verified' FROM audit_logs WHERE id > ${M:-0} AND action='file.download_exec' AND result='allow' ORDER BY id DESC LIMIT 1")"
    expect "(c) 받은 요청의 코드 문자열이 감사에 0건" "0" \
      "$(psqlq "SELECT count(*) FROM audit_logs WHERE id > ${M:-0} AND detail::text LIKE '%${OTP2}%'")"
  else skip "(c) download_exec 감사" "psql 없음"; fi

  sub "exec_content=false 인 파일에 exec-download → 400 bad_request"
  if [ -n "$OK_ID" ]; then
    M=$(mark)
    DL_PW=$(qa_password claude_admin01)
    printf -v body '{"password":"%s","otp":"000000"}' "$DL_PW"
    OUT=$(curl_json_secret body -s -w '\n%{http_code}' -X POST -b "$ADM_JAR" -A "$UA" \
          -H 'Content-Type: application/json' -H "X-CSRF-Token: $ADM_CSRF" \
          "$BASE/api/files/$OK_ID/exec-download")
    DL_PW=""; body=""
    expect "(c) 보통 파일 exec-download → 상태" "400" "$(code_of "$OUT")"
    if [ "$HAVE_PSQL" -eq 1 ]; then
      expect "(c) 감사 file.download_exec deny bad_request" "1" \
        "$(psqlq "SELECT count(*) FROM audit_logs WHERE id > ${M:-0} AND action='file.download_exec' AND result='deny' AND reason='bad_request'")"
    else skip "(c) bad_request 감사" "psql 없음"; fi
  else skip "(c) 보통 파일 exec-download" "정상 zip 업로드가 없었다"; fi
fi

# ─── (d) AL-21 ───────────────────────────────────────────────────
hr "(d) AL-21 — file.upload_exec allow 1건이라도 즉시"

if [ "$RUN_HTTP" -eq 0 ] || [ -z "$A_ID" ]; then
  skip "(d) 전체" "확인 업로드가 없었다"
else
  # 운영 상태 파일을 건드리지 않게 **빈 임시 상태**로 돌린다 (23단계 방식).
  printf '{}' > "$WORK/alerts-state.json"
  php "$ROOT/scripts/alerts.php" --rule AL-21 --dry-run --state "$WORK/alerts-state.json" \
    > "$WORK/al21.out" 2>&1
  expect "(d) alerts.php 종료 코드" "0" "$?"
  sed 's/^/    /' "$WORK/al21.out"
  expect "(d) AL-21 dry-run 1건" "1" "$(grep -c '\[dry-run\] AL-21' "$WORK/al21.out" || true)"
  contains "(d) evidence 에 파일명" "F65-exec.zip" "$(cat "$WORK/al21.out")"
  contains "(d) evidence 에 올린 사람" "claude_admin01" "$(cat "$WORK/al21.out")"
  contains "(d) evidence 에 항목 수" "exec_entry_count" "$(cat "$WORK/al21.out")"
  expect "(d) 출력에 비밀번호 문자열 0건" "0" \
    "$(grep -c -i 'password' "$WORK/al21.out" || true)"
fi

# ─── (e) 화면 (Playwright) ───────────────────────────────────────
hr "(e) 화면 — 확인 블록 · 배지 · 확인 다운로드 창 · 공유 단추 없음 · 콘솔 오류 0"

if [ "$RUN_HTTP" -eq 0 ]; then
  skip "(e) 전체" "운영 DB 열 없음 또는 세션 없음"
else
  # 2단계 코드를 **파이썬 안에서** 새 창마다 받기 위한 작은 wrapper.
  # 비밀을 argv 로 넘기지 않는다(`ps` 에 뜬다) — 부를 때마다 여기서 읽어 php 에 **표준입력**으로 준다
  # (`totp_code.php -` · /bin/sh(dash)의 printf 도 내장이라 새 프로세스의 인자에 비밀이 없다 — R-62).
  cat > "$WORK/otp.sh" <<OTPSH
#!/bin/sh
sec=\$(sudo -n awk -v u="\$1" '\$1==u{print \$2}' /etc/tbox-v2/qa-totp.txt 2>/dev/null)
[ -n "\$sec" ] || exit 1
printf '%s\n' "\$sec" | php "$ROOT/scripts/totp_code.php" -
OTPSH
  chmod +x "$WORK/otp.sh"

  cat > "$WORK/e-screen.py" <<'PY'
import json, re, subprocess, sys, time

base, ua, who, zippath, otpsh, chrome = sys.argv[1:7]
pw = sys.stdin.readline().rstrip('\n')   # 비밀번호는 인자가 아니라 표준입력 첫 줄(R-62 · py_secret)

try:
    from playwright.sync_api import sync_playwright
except Exception as e:
    print(json.dumps({'PLAYWRIGHT_MISSING': str(e)})); sys.exit(0)


def fresh_code():
    """**다음 30초 창**의 코드를 돌려준다 (R-45 1회성 — 방금 쓴 코드는 다시 못 쓴다)."""
    for _ in range(2):
        out = subprocess.run([otpsh, who], capture_output=True, text=True).stdout
        line = out.splitlines()
        if not line:
            raise RuntimeError('totp_code.php 가 값을 주지 않았다')
        left = re.search(r'남은 시간: (\d+)', out)
        time.sleep(int(left.group(1)) + 1 if left else 31)
    out = subprocess.run([otpsh, who], capture_output=True, text=True).stdout
    return out.splitlines()[0].strip()


# 콘솔 오류를 두 갈래로 나눈다 (f52.sh 와 같은 방식).
#   js_errs  — 진짜 자바스크립트 오류(pageerror + 자원 적재가 아닌 console.error). **0 이어야 한다.**
#   bad_reqs — 400 이상으로 끝난 요청. 이 시험은 **일부러** 400 을 만들므로(확인 없이 올리기)
#              개수를 세어 보고만 하고 판정하지 않는다. 브라우저는 그런 응답마다
#              `Failed to load resource:` 를 console.error 로 찍는데, 그것을 자바스크립트
#              오류로 세면 「의도한 거부」가 실패로 둔갑한다(첫 실행에서 실측).
js_errs = []
bad_reqs = []
posts = []
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
    ctx = b.new_context(user_agent=ua, accept_downloads=True)
    page = ctx.new_page()
    page.on('console', on_console)
    page.on('pageerror', lambda e: js_errs.append('pageerror: ' + str(e)))
    page.on('request', lambda r: posts.append(r.url) if r.method == 'POST' else None)
    page.on('response', lambda r: bad_reqs.append('%s %s' % (r.status, r.url)) if r.status >= 400 else None)

    page.goto(base + '/login', wait_until='networkidle')
    page.fill('#username', who)
    page.fill('#password', pw)
    page.click('#login-submit')
    page.wait_for_timeout(1200)
    if page.is_visible('#otp'):
        page.fill('#otp', fresh_code())
        page.click('#login-submit')
        page.wait_for_timeout(2000)
    out['logged_in'] = page.locator('#upload-toggle').count()

    # ── 올리기 창 → 실행 파일이 든 zip → 확인 블록이 펴지는가 ──────────────
    page.click('#upload-toggle')
    page.wait_for_timeout(400)
    page.set_input_files('#upload-file', zippath)
    page.select_option('#upload-level', 'admin-only')
    page.click('#upload-submit')
    page.wait_for_timeout(3000)

    out['ack_block'] = page.locator('.upload-exec').count()
    out['ack_entries'] = page.locator('.upload-exec__list li').all_text_contents()
    out['ack_has_checkbox'] = page.locator('.upload-exec__ack input[type=checkbox]').count()
    out['ack_has_password'] = page.locator('.upload-exec__field input[type=password]').count()

    # ── 체크 없이 「확인하고 올리기」 → 요청이 나가지 않아야 한다 ──────────
    before = len(posts)
    if out['ack_block'] > 0:
        page.locator('.upload-exec button').first.click()
        page.wait_for_timeout(1000)
    out['posts_without_ack'] = len(posts) - before
    out['ack_note'] = (page.locator('.upload-exec__msg').first.text_content()
                       if out['ack_block'] else '')

    # ── 체크 + 비밀번호 + **새 창의 코드** → 201 ──────────────────────────
    # 로그인 코드와 같은 창을 쓰면 서버가 code_reused 로 막는 것이 정상이라(R-45),
    # 여기서 다음 창까지 기다린다. 그래서 이 갈래만 최대 1분쯤 걸린다.
    out['uploaded_state'] = ''
    if out['ack_block'] > 0:
        page.locator('.upload-exec__ack input[type=checkbox]').first.check()
        page.locator('.upload-exec__field input[type=password]').first.fill(pw)
        page.locator('.upload-exec__field input[inputmode=numeric]').first.fill(fresh_code())
        page.locator('.upload-exec button').first.click()
        page.wait_for_timeout(5000)
        cells = page.locator('.upload-status__state').all_text_contents()
        out['uploaded_state'] = cells[0] if cells else ''
        out['ack_block_after'] = page.locator('.upload-exec').count()

    # 올리기 창을 닫고 목록을 본다
    page.click('#upload-dialog-close')
    page.wait_for_timeout(2000)

    # ── F-54 검사가 끝나기를 기다린다 (SCAN_ENABLED=1 이면 올라온 직후는 `pending`) ──
    #
    # ★ 첫 실행에서 (e) 다섯 항목이 여기서 어긋났다(2026-09-21 실측). `addActions` 는
    #   **검사 관문을 먼저** 본다: `pending` 이면 「확인하고 받기」 단추 대신 **비활성
    #   다운로드 단추**를 만든다(ARCH 5.28 이 F-65 보다 앞선다 — 검사 안 끝난 파일은
    #   확인을 통과해도 나가면 안 된다). 즉 앱이 맞고 시험이 성급했다.
    #   1분 cron `scan_pending.php` 가 `clean` 으로 바꿀 때까지 새로 고치며 기다린다.
    out['scan_badge_first'] = page.locator('#files-body tr').filter(
        has_text='F65-exec.zip').first.locator('.badge--scan-pending').count()
    out['scan_wait_s'] = 0
    started = time.time()
    while time.time() - started < 180:
        cnt = page.locator('#files-body tr').filter(
            has_text='F65-exec.zip').first.locator('button[title*="확인하고 받기"]').count()
        if cnt > 0:
            break
        page.wait_for_timeout(6000)
        page.reload(wait_until='networkidle')
        page.wait_for_timeout(1500)
    out['scan_wait_s'] = int(time.time() - started)

    # ── 목록: 배지 · 공유 단추 없음 · 확인 다운로드 단추 ──────────────────
    row = page.locator('#files-body tr').filter(has_text='F65-exec.zip').first
    out['row_found'] = page.locator('#files-body tr').filter(has_text='F65-exec.zip').count()
    if out['row_found'] > 0:
        out['badge_exec'] = row.locator('.badge--exec').count()
        out['badge_text'] = (row.locator('.badge--exec').first.text_content()
                             if out['badge_exec'] else '')
        out['share_button'] = row.locator('button[title*="공유"]').count()
        out['exec_dl_button'] = row.locator('button[title*="확인하고 받기"]').count()
        out['plain_dl_link'] = row.locator('a[href*="/download"]').count()

        # ── 확인 다운로드 창이 열리고 올린 사람·날짜가 채워지는가 ──────────
        if out['exec_dl_button'] > 0:
            row.locator('button[title*="확인하고 받기"]').first.click()
            page.wait_for_timeout(800)
            out['dialog_open'] = page.locator('#exec-download-dialog[open]').count()
            out['dialog_file'] = page.locator('#exec-dl-file').text_content()
            out['dialog_uploader'] = page.locator('#exec-dl-uploader').text_content()
            out['dialog_created'] = page.locator('#exec-dl-created').text_content()
            page.click('#exec-dl-close')
            page.wait_for_timeout(300)

    out['errors'] = js_errs
    out['bad_requests'] = bad_reqs
    b.close()

print(json.dumps(out, ensure_ascii=False))
PY

  # 크로미움 실행 파일 — 기본 `chromium_headless_shell` 이 없는 환경이 있어 직접 찾아 넘긴다
  # (2026-09-21 실측: p.chromium.launch() 가 "Executable doesn't exist" 로 죽었고,
  #  stderr 를 JSON 파일에 함께 흘려 (e) 8항목이 전부 빈 값이었다).
  PW_CHROME=""
  for cand in "$HOME"/.cache/ms-playwright/chromium-*/chrome-linux64/chrome \
              "$HOME"/.cache/ms-playwright/chromium-*/chrome-linux/chrome; do
    [ -x "$cand" ] && { PW_CHROME="$cand"; break; }
  done
  note "크로미움: ${PW_CHROME:-(playwright 기본값)}"

  # ★ stderr 는 **따로** 받는다 — 표준출력에 섞으면 JSON 이 깨져 모든 항목이 빈 값이 된다.
  E_PW=$(qa_password claude_admin01)
  py_secret E_PW -- python3 "$WORK/e-screen.py" "$BASE" "$UA" "claude_admin01" \
    "$EXEC_ZIP" "$WORK/otp.sh" "$PW_CHROME" > "$WORK/e.json" 2> "$WORK/e.err"
  E_RC=$?
  E_PW=""
  if [ "$E_RC" -ne 0 ] || [ ! -s "$WORK/e.json" ]; then
    printf '  ! (e) 파이썬이 실패했다 (rc=%s) — stderr:\n' "$E_RC"
    tail -20 "$WORK/e.err" | sed 's/^/      /'
  fi
  if grep -q 'PLAYWRIGHT_MISSING' "$WORK/e.json" 2>/dev/null; then
    skip "(e) 전체" "playwright 가 없다 — 미검증 (브라우저 있는 환경에서 다시 실행)"
  elif [ ! -s "$WORK/e.json" ]; then
    skip "(e) 전체" "브라우저 시험이 결과를 내지 못했다 — 위 stderr 참고"
  else
    eq() { jget "$1" < "$WORK/e.json"; }
    expect "(e) 로그인 뒤 올리기 단추 보임" "1" "$(eq "d.get('logged_in',0)")"
    expect "(e) 확인 블록이 펴진다" "1" "$(eq "d.get('ack_block',0)")"
    expect "(e) 항목 이름 2개" "2" "$(eq "len(d.get('ack_entries',[]))")"
    contains "(e) 항목 이름에 x.php" "x.php" "$(eq "','.join(d.get('ack_entries',[]))")"
    contains "(e) 항목 이름에 run.sh" "run.sh" "$(eq "','.join(d.get('ack_entries',[]))")"
    expect "(e) 체크박스 1개" "1" "$(eq "d.get('ack_has_checkbox',0)")"
    expect "(e) 비밀번호 칸 1개" "1" "$(eq "d.get('ack_has_password',0)")"
    expect "(e) 체크 없이 누르면 요청 0건" "0" "$(eq "d.get('posts_without_ack',-1)")"
    note "체크 없이 눌렀을 때의 안내: $(eq "d.get('ack_note','')")"

    expect "(e) 확인하고 올리기 → 상태 줄 「완료」" "완료" "$(eq "d.get('uploaded_state','')")"
    expect "(e) 성공 뒤 확인 블록이 사라진다" "0" "$(eq "d.get('ack_block_after',-1)")"
    note "올린 직후 「검사 중」 배지: $(eq "d.get('scan_badge_first','?')")개 · 검사가 끝나기까지 $(eq "d.get('scan_wait_s','?')")초 기다렸다 (F-54 관문이 F-65 보다 앞이다)"
    expect "(e) 목록에 그 파일 행이 있다" "1" "$(eq "min(d.get('row_found',0),1)")"
    expect "(e) 「실행 파일 포함」 배지" "1" "$(eq "min(d.get('badge_exec',0),1)")"
    contains "(e) 배지 문구" "실행 파일 포함" "$(eq "d.get('badge_text','')")"
    expect "(e) 공유 단추 없음" "0" "$(eq "d.get('share_button',-1)")"
    expect "(e) 보통 다운로드 링크 없음" "0" "$(eq "d.get('plain_dl_link',-1)")"
    expect "(e) 확인하고 받기 단추" "1" "$(eq "d.get('exec_dl_button',0)")"
    expect "(e) 확인 다운로드 창이 열린다" "1" "$(eq "d.get('dialog_open',0)")"
    contains "(e) 창에 파일명" "F65-exec.zip" "$(eq "d.get('dialog_file','')")"
    contains "(e) 창에 올린 사람" "claude_admin01" "$(eq "d.get('dialog_uploader','')")"
    note "창의 올린 날짜: $(eq "d.get('dialog_created','')")"
    # 「자원 적재 밖」 자바스크립트 오류만 판정한다 — 이 시험은 **일부러** 400 을 만든다
    # (확인 없이 올리기). 그 400 을 브라우저가 console.error 로 찍는 것까지 세면
    # 의도한 거부가 실패로 둔갑한다 (f52.sh 와 같은 가름).
    expect "(e) 자원 적재 밖 콘솔·자바스크립트 오류 0건" "0" "$(eq "len(d.get('errors',[]))")"
    [ "$(eq "len(d.get('errors',[]))")" = "0" ] || eq "' | '.join(d.get('errors',[]))" | sed 's/^/      /'
    note "400 이상으로 끝난 요청 $(eq "len(d.get('bad_requests',[]))")건 (의도한 거부 포함) — 목록:"
    eq "' | '.join(d.get('bad_requests',[]))" | sed 's/^/      /'
  fi
fi

# ─── (f) 회귀 — 린트 · php -l · bash -n ─────────────────────────
hr "(f) 회귀 — security_lint · php -l · bash -n"

bash "$ROOT/scripts/security_lint.sh" > "$WORK/lint.out" 2>&1
LINT=$?
expect "scripts/security_lint.sh 종료 코드" "0" "$LINT"
expect "✖ 표시 줄 수" "0" "$(grep -c '^✖' "$WORK/lint.out" || true)"
tail -1 "$WORK/lint.out" | sed 's/^/    /'

PHPBAD=0
for f in src/Auth.php src/Authz.php src/Audit.php src/Http.php src/Upload.php \
         src/handlers/_upload_common.php src/handlers/files_upload.php \
         src/handlers/files_download.php src/handlers/files_preview.php \
         src/handlers/files_preview_raw.php src/handlers/files_exec_download.php \
         src/handlers/share_create.php src/handlers/share_download.php \
         src/handlers/files_update.php src/handlers/files_list.php \
         src/handlers/home.php public/index.php scripts/alerts.php; do
  php -l "$ROOT/$f" >/dev/null 2>&1 || { echo "  ✖ php -l 실패: $f"; PHPBAD=1; }
done
expect "php -l (바뀐 18개 PHP)" "0" "$PHPBAD"

expect "bash -n tests/f65.sh" "0" "$(bash -n "$ROOT/tests/f65.sh" 2>/dev/null; echo $?)"

sub "문구 상수는 Http.php 한 곳에만"
expect "Http.php 에 MSG_EXEC_ACK_REQUIRED" "1" \
  "$(grep -c '^    public const MSG_EXEC_ACK_REQUIRED' "$ROOT/src/Http.php")"
expect "핸들러의 파일 최상위 const MSG_EXEC_* 0건" "0" \
  "$(grep -rc '^const MSG_EXEC_' "$ROOT/src/handlers/" 2>/dev/null | awk -F: '{n+=$2} END{print n+0}')"

sub "exec-download 라우트가 토큰 목록 밖인가 (API.md 9-1)"
expect "TBOX_API_TOKEN_ROUTES 에 exec-download 없음" "0" \
  "$(sed -n '/^const TBOX_API_TOKEN_ROUTES/,/^];/p' "$ROOT/public/index.php" | grep -c 'exec-download' || true)"
# 패턴에 작은따옴표를 넣으면 셸 인용이 꼬인다(2026-09-21 실측: 이스케이프한 큰따옴표가
# 패턴 글자가 되어 언제나 0 이었다). 경로 조각만으로 세고, 라우트 행인지는 `['POST',` 로 가린다.
expect "라우트 표에 exec-download 1행" "1" \
  "$(grep -c "/api/files/{id}/exec-download'," "$ROOT/public/index.php" || true)"

sub "비밀번호·코드가 감사·로그·응답 인자에 없는가 (지시서 3장)"
grep -n 'password\|otp' "$ROOT/src/handlers/_upload_common.php" "$ROOT/src/handlers/files_exec_download.php" \
  | sed 's/^/    /'
expect "두 파일에 error_log(…password 0건" "0" \
  "$(grep -c 'error_log(.*\(password\|otp\)' "$ROOT/src/handlers/_upload_common.php" "$ROOT/src/handlers/files_exec_download.php" | awk -F: '{n+=$2} END{print n+0}')"

# ─── (g) 마이그레이션 — 임시 DB 로만 ────────────────────────────
hr "(g) 마이그레이션 — 임시 DB 로 검증 (운영 DB 실행은 사용자 몫)"

if sudo -n -u postgres psql -l >/dev/null 2>&1; then
  sudo -n -u postgres dropdb --if-exists "$TMPDB" >/dev/null 2>&1
  if sudo -n -u postgres createdb "$TMPDB" >/dev/null 2>&1; then
    # postgres 가 홈을 못 읽는 환경이 있어 -f 대신 표준입력으로 넣는다.
    sudo -n -u postgres psql -d "$TMPDB" -v ON_ERROR_STOP=1 -q < "$ROOT/db/schema.sql" \
      > "$WORK/schema.out" 2>&1
    expect "(g) schema.sql 적용 rc" "0" "$?"
    expect "(g) 새 DB files 에 exec_content" "1" \
      "$(sudo -n -u postgres psql -d "$TMPDB" -t -A -c "SELECT count(*) FROM information_schema.columns WHERE table_name='files' AND column_name='exec_content'" 2>/dev/null)"
    expect "(g) 기본값 false" "false" \
      "$(sudo -n -u postgres psql -d "$TMPDB" -t -A -c "SELECT column_default FROM information_schema.columns WHERE table_name='files' AND column_name='exec_content'" 2>/dev/null)"
    # 멱등: 마이그레이션을 그 위에 한 번 더 적용해도 같은 결과여야 한다.
    sudo -n -u postgres psql -d "$TMPDB" -v ON_ERROR_STOP=1 -q \
      < "$ROOT/db/migrations/2026-09-21_files_exec_content.sql" > "$WORK/mig.out" 2>&1
    expect "(g) 마이그레이션 재적용 rc (멱등)" "0" "$?"
    sudo -n -u postgres dropdb "$TMPDB" >/dev/null 2>&1
    note "임시 DB $TMPDB 를 지웠다"
  else
    skip "(g) 임시 DB" "createdb 실패"
  fi
else
  skip "(g) 임시 DB" "sudo -n -u postgres 를 쓸 수 없다"
fi
note "운영 DB 실행(사용자): sudo -u postgres psql -d $DB -v ON_ERROR_STOP=1 -f db/migrations/2026-09-21_files_exec_content.sql"

# ─── (h) 정리 ────────────────────────────────────────────────────
hr "(h) 정리 — 시험 파일은 휴지통으로 · 토큰 회수 · 폴더 삭제 · 비-qa 행 수 불변"

# 이름으로 한 번 더 훑는다 — 「만든 것은 전부 치운다」 를 목록이 아니라 **상태**로 지킨다
# (api_tokens.sh sweep_tokens 와 같은 방식). (e) 화면 시험이 브라우저로 올린 사본처럼
# $UPLOADED 에 없는 것도 여기서 잡힌다.
if [ "$HAVE_PSQL" -eq 1 ]; then
  SWEEP=$(psqlq "SELECT f.public_id FROM files f JOIN users u ON u.id = f.uploaded_by
                  WHERE f.original_name LIKE 'F65-%' AND f.deleted_at IS NULL
                    AND $(review_like_sql u.username)")
  for s in $SWEEP; do
    case " $UPLOADED " in *" $s "*) ;; *) UPLOADED="$UPLOADED $s" ;; esac
  done
fi

# 2026-09-21 Fable 이 운영 트리 합친 뒤 스모크로 올린 파일 둘도 함께 치운다
# (옛 검토 관리자 계정(qa_ 접두)이 올린 것 — 시험이 만든 것과 같은 성격이라 남겨 둘 이유가 없다).
# 이미 휴지통에 있으면 404 가 나고, 그것도 「치워져 있다」 는 뜻이라 실패로 세지 않는다.
for smoke in 4e5a5c67-fcdf-48d7-9d0e-8a2fce39f714 63c980b8-0cba-4515-a9b0-605566d34fb4; do
  SD=$(curl -s -o /dev/null -w '%{http_code}' -X DELETE -b "$ADM_JAR" -A "$UA" \
       -H "X-CSRF-Token: $ADM_CSRF" "$BASE/api/files/$smoke")
  printf '  · 스모크 파일 %s 휴지통 [%s]\n' "$smoke" "$SD"
done

N_UP=0; N_DEL=0
for id in $UPLOADED; do
  N_UP=$((N_UP+1))
  D=$(curl -s -o /dev/null -w '%{http_code}' -X DELETE -b "$ADM_JAR" -A "$UA" \
      -H "X-CSRF-Token: $ADM_CSRF" "$BASE/api/files/$id")
  [ "$D" = "200" ] && N_DEL=$((N_DEL+1)) || printf '  ✖ 정리 실패 %s → %s\n' "$id" "$D"
done
expect "올린 파일 휴지통으로 (psql 삭제 없음)" "$N_UP" "$N_DEL"

if [ -n "$MADE_TOKENS" ] && [ -n "$ADM_CSRF" ]; then
  reauth claude_admin01 "$ADM_CSRF" >/dev/null 2>&1
  for t in $MADE_TOKENS; do
    uid=${t%%:*}; tid=${t##*:}
    RC=$(curl -s -o /dev/null -w '%{http_code}' -X DELETE -b "$ADM_JAR" -A "$UA" \
         -H "X-CSRF-Token: $ADM_CSRF" "$BASE/api/users/$uid/api-tokens/$tid")
    printf '  · 토큰 #%s 회수 [%s]\n' "$tid" "$RC"
  done
fi

for n in $MADE_FOLDERS; do
  RC=$(curl -s -o /dev/null -w '%{http_code}' -X DELETE -b "$ADM_JAR" -A "$UA" \
       -H "X-CSRF-Token: $ADM_CSRF" "$BASE/api/folders/$n?move_trashed=1")
  printf '  · 폴더 %s 삭제 [%s]\n' "$n" "$RC"
done

if [ "$HAVE_PSQL" -eq 1 ] && [ -n "$NONQA_BEFORE" ]; then
  # ★ 「사용자 데이터 불변」을 **개수**로 재지 않는다 (2026-09-21 실측으로 고침).
  #
  #   시험이 도는 10분 사이에 사용자가 t-box 를 쓰면 비-qa 행이 늘어난다 — 2회차에서
  #   864→877 로 13건이 늘었고, 전부 admin01 본인의 작업이었다(재인증·「역할로 보기」
  #   켜고 끄기·감사 조회, 16:29~16:30). 시작 전 R-73 관문은 **시작 시점**만 보므로
  #   도중 활동은 막을 수 없고, 개수 비교는 그것을 「시험이 사용자 데이터를 건드렸다」로
  #   잘못 읽는다.
  #
  #   진짜 물어야 할 것은 「**이 시험의 요청이** 비-qa 계정으로, 또는 비-qa 계정에 대해
  #   무엇을 했나」다. 그 가름은 CLAUDE.md 3장·ARCH 7.5 가 정해 두었다 — 검토 요청은
  #   전부 `-A "tbox-review/<날짜>"` 를 달고 간다. 그래서 「검토 UA 이면서 actor 가
  #   비-qa 인 행」이 0 인지를 본다. 사용자가 같은 시간에 무엇을 하든 이 값은 흔들리지 않는다.
  expect "시험 요청이 만든 비-qa 행 0건 (검토 UA 기준 — 사용자 데이터 불변)" "0" \
    "$(db_read "$DB" "SELECT count(*) FROM audit_logs
                WHERE id > ${AUDIT_START:-0}
                  AND actor_name IS NOT NULL AND $REVIEW_NOT_SQL
                  AND COALESCE(user_agent, '') LIKE 'tbox-review/%'")"
  # 사용자가 같은 시간에 만든 행은 **판정하지 않고** 숫자로만 남긴다 (증거용).
  NONQA_AFTER=$(db_read "$DB" "SELECT count(*) FROM audit_logs WHERE actor_name IS NOT NULL AND $REVIEW_NOT_SQL")
  note "시험이 도는 동안 사용자가 만든 비-qa 행: $((${NONQA_AFTER:-0} - ${NONQA_BEFORE:-0}))건 (판정 대상 아님)"
  # 시스템 행(actor 없음)은 **늘어나는 것이 정상**이다 — 이 시험이 ClamAV 배치와 AL-21 을
  # 일부러 돌리기 때문이다. 몇 건이 왜 늘었는지를 숫자와 목록으로 남긴다(판정하지 않는다).
  SYS_AFTER=$(db_read "$DB" "SELECT count(*) FROM audit_logs WHERE actor_name IS NULL")
  note "시험이 만든 시스템 행(actor 없음): $((${SYS_AFTER:-0} - ${SYS_BEFORE:-0}))건 — 내역:"
  psqlq "SELECT action || ' ' || result || coalesce(' ' || reason, '') || '  ' || coalesce(target_name, '(대상 없음)')
           FROM audit_logs
          WHERE actor_name IS NULL AND id > ${AUDIT_START:-0}
          ORDER BY id" | sed 's/^/      /'
else
  skip "비-qa 행 수 불변" "psql 없음"
fi

# ─── 마무리 ─────────────────────────────────────────────────────
hr "결과"
printf '통과 %d · 실패 %d · 건너뜀/미검증 %d\n' "$PASS" "$FAIL" "$SKIP"
if [ "$HAVE_COLUMN" != "1" ]; then
  echo "미검증: (a)~(e) — 운영 DB 에 files.exec_content 가 없다."
  echo "        사용자가 마이그레이션을 실행한 뒤 이 스크립트를 다시 돌린다 (배포 순서: 마이그레이션 → 코드)."
fi
echo "작업 폴더 $WORK 는 종료와 함께 지워진다."
[ "$FAIL" -eq 0 ] || exit 1
exit 0
