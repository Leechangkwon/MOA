#!/usr/bin/env bash
# tests/f54.sh — F-54 「악성코드 검사 파이프라인」 수락 기준 실행 (PRD 12.2 T-66, F-54, S-06, R-02, R-86)
#
#   (a) 정상 txt 업로드 → 201 · DB `pending` · 즉시 다운로드 **423 + Retry-After: 30**
#       「검사 중」·본문 `retry_after` 30 (F-57, T-69 (a)) + 감사
#       `file.download deny scan_pending` · 목록 `scan_status:"pending"` → 배치 1회 →
#       DB `clean` · 감사 `file.scan allow` · 다운로드 200
#   (b) EICAR 시험 문자열 → 201 · `pending` → 배치 → `infected` ·
#       바이트가 storage 에서 사라지고 quarantine/<stored_name> 에 0640 으로 있음 ·
#       감사 `file.scan deny infected`(detail.signature 에 Eicar) · 다운로드·미리보기 403 「격리」 ·
#       목록 `infected` · `alerts.php --rule AL-19 --dry-run --state <임시>` 가 발동
#   (c) 대기 0건 → 배치가 clamscan 을 **띄우지 않고** 2초 안에 「검사 대기 없음」 ·
#       잠금 파일을 남이 쥐고 있으면 두 번째 실행은 「이미 실행 중」으로 즉시 종료
#   (d) `Upload::initialScanStatus()` — SCAN_ENABLED 없음/0/1 → clean/clean/pending (단위)
#   (e) 서버 층 — clamav·clamav-freshclam 설치 · clamav-daemon **미설치** · freshclam active ·
#       시그니처 24시간 이내 · 격리 0750 tbox-php · cron 행 · clam 리스너 없음(T-18) ·
#       check_baseline.sh 의 새 절
#   (f) 자원 — 배치 1회(대기 1건)의 최대 RSS·소요 시간 (기대 < 1.5GB · < 60초)
#   (g) 린트 0건 · `php -l` · `bash -n` · 시험 파일은 휴지통으로(**격리 파일은 그대로 둔다 — 증거**)
#
# ★ (a)(b)(f) 는 `.env` 의 `SCAN_ENABLED=1` 이 **실제로 켜져 있을 때만** 뜻이 있다.
#   이 스크립트는 `.env` 를 **고치지 않고**, `psql` 로 `scan_status` 를 바꾸지도 않는다
#   (운영 데이터 불변 — CLAUDE.md 4장). 꺼져 있으면 그 항목을 SKIP 하고 나머지를 돌린다.
#   Fable 이 `SCAN_ENABLED=1` 로 켠 뒤 이 스크립트를 **다시 돌린다**.
#
# 규칙 (CLAUDE.md 3장)
#   · 검토 계정은 `qa_employee01`·`qa_admin01` 뿐이다. 시드 계정(admin01 …)은 사용자 몫이다.
#   · 모든 요청에 `-A "tbox-review/<날짜>"` — 감사 로그에서 사람의 행동과 갈라 보려는 것.
#   · 운영 데이터에 손대지 않는다. 올린 파일은 앱 창구(`DELETE /api/files/{id}`)로만 되돌린다.
#   · `.env` 는 **읽지 않는다** — `grep -c` 로 켜짐 여부(0/1)만 센다. 값은 출력하지 않는다.
#
# ★ 시험 안전 (SECURITY R-73 · R-80)
#   ① 최근 10분 감사 로그에 비-qa actor 가 있으면 시작하지 않는다 — 2분 간격으로 최대 10회 기다린다
#   ② 만드는 파일은 전부 `F54-` 접두
#   ③ 루트 파티션 여유가 1GB 미만이면 시작하지 않는다
#
# 준비물: curl · php(TOTP) · sudo -n(seed-accounts.txt · psql · tbox-php · ls) · /usr/bin/time
# 사용: sudo -v && bash tests/f54.sh
set -u

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
# 검토 계정 판정 한 벌(qa_·claude_ — F-71 C). 못 읽으면 사전 검사가 빈 값으로 열리므로 시작하지 않는다.
. "$ROOT/tests/lib/review_accounts.sh" || { echo "tests/lib/review_accounts.sh 를 읽지 못했다 — 시험을 시작하지 않는다 (R-73)"; exit 3; }
BASE="http://127.0.0.1:8082/tbox"
UA="tbox-review/$(date +%F)"
DB="tbox_re"
JAR_DIR="$(mktemp -d)"
WORK="$(mktemp -d)"
PASS=0
FAIL=0
SKIP=0
UPLOADED=""          # 휴지통으로 되돌릴 public_id 들 (격리된 것은 넣지 않는다)

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

# ─── 시작 전 관문 ────────────────────────────────────────────────
hr "F-54 악성코드 검사 파이프라인 — 시험 시작 전 확인"

avail_kb=$(df -Pk / | awk 'NR==2{print $4}')
echo "루트 파티션 여유: $(df -Ph / | awk 'NR==2{print $4}') (${avail_kb}KB)"
[ "$avail_kb" -ge 1048576 ] || { echo "루트 디스크 여유 1GB 미만 — 시험을 시작하지 않는다 (SECURITY R-80)"; exit 3; }

HAVE_PSQL=0
if psqlq "SELECT 1" >/dev/null 2>&1; then HAVE_PSQL=1; fi

# R-73 ① — 사용자가 지금 서비스를 쓰고 있으면 기다린다 (2분 간격, 최대 10회).
if [ "$HAVE_PSQL" -eq 1 ]; then
  TRY=1
  while :; do
    BUSY=$(psqlq "SELECT count(*) FROM audit_logs WHERE happened_at > now() - interval '10 minutes' AND actor_name IS NOT NULL AND $REVIEW_NOT_SQL")
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

# ─── SCAN_ENABLED 가 실제로 켜져 있나 (값은 읽지 않고 **개수만** 센다) ───
# 실제 설정 파일의 자리는 저장소 루트 `.env` 다(.env.example 머리말). 운영 전환 뒤의 자리
# (/etc/tbox-v2/.env)도 함께 본다 — 어느 쪽이든 `^SCAN_ENABLED=1` 이 한 줄이면 켜진 것이다.
SCAN_ON=0
for envfile in "$ROOT/.env" /etc/tbox-v2/.env; do
  n=$(sudo -n grep -c '^SCAN_ENABLED=1' "$envfile" 2>/dev/null || true)
  case "${n:-0}" in
    ''|*[!0-9]*) n=0 ;;
  esac
  printf '  %s : ^SCAN_ENABLED=1 인 줄 %s개\n' "$envfile" "$n"
  [ "$n" -ge 1 ] 2>/dev/null && SCAN_ON=1
done
if [ "$SCAN_ON" -eq 1 ]; then
  echo "  → 검사가 켜져 있다. (a)(b)(f) 를 전부 돈다."
else
  echo "  → 검사가 꺼져 있다. (a)(b)(f) 는 SKIP 하고 (c)(d)(e)(g) 만 돈다 — Fable 이 켠 뒤 재실행."
fi

# ─── 로그인 (qa_employee01) ─────────────────────────────────────
hr "로그인 — qa_employee01"

# TOTP 코드는 1회성이다(R-45) — 같은 창에서 두 번 쓰면 거부되는 것이 정상이라 기다렸다 다시 한다.
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

CSRF=$(login qa_employee01)
JAR="$JAR_DIR/qa_employee01.jar"
HAVE_SESSION=0
if [ -n "${CSRF:-}" ]; then HAVE_SESSION=1; echo "  qa_employee01 로그인 OK (CSRF 토큰 확보)"
else echo "  qa_employee01 로그인 실패 — HTTP 항목은 전부 SKIP 되고 단위·서버 항목만 돈다"; fi

CODE=""; BODY=""; NEWID=""
up() {   # $1 파일경로 → CODE·BODY·NEWID
  local f="$1" out
  out=$(curl -s -w '\n%{http_code}' -X POST -b "$JAR" -A "$UA" -H "X-CSRF-Token: $CSRF" \
        -F "file=@$f" -F 'access_level=internal' "$BASE/api/files")
  CODE=$(code_of "$out"); BODY=$(body_of "$out")
  NEWID=$(uuid_of "$BODY")
}

get_code() {   # $1 경로 → HTTP 코드만
  curl -s -o /dev/null -w '%{http_code}' -b "$JAR" -A "$UA" "$BASE$1"
}
get_body() {   # $1 경로 → 본문만
  curl -s -b "$JAR" -A "$UA" "$BASE$1"
}

# 목록 응답에서 그 파일의 scan_status 를 뽑는다 (웹 응답 투영 확인용)
list_scan_of() {   # $1 public_id
  get_body "/api/files?page=1" \
    | tr '{' '\n' | grep -F "\"id\":\"$1\"" | sed -n 's/.*"scan_status":"\([a-z]*\)".*/\1/p' | head -1
}

# 배치 한 번. `--force-enabled` 는 시험용 인자다 (.env 를 고치지 않고 돌리기 위한 것).
run_batch() {   # 표준출력+표준오류를 그대로 돌려준다
  sudo -n -u tbox-php php "$ROOT/scripts/scan_pending.php" --force-enabled 2>&1
}

# ─── (a) 정상 파일 — pending → 423 → 배치 → clean → 200 ─────────
hr "(a) 정상 txt — 저장은 「검사 중」, 검사 뒤에 열린다"

A_ID=""
if [ "$SCAN_ON" -eq 0 ]; then
  skip "(a) 전체" "SCAN_ENABLED 가 켜져 있지 않다 — Fable 이 켠 뒤 재실행"
elif [ "$HAVE_SESSION" -eq 0 ]; then
  skip "(a) 전체" "세션 없음"
else
  printf 'F-54 정상 파일 시험입니다.\n' > "$WORK/F54-ok.txt"
  M=$(mark)
  up "$WORK/F54-ok.txt"
  expect "업로드 상태" "201" "$CODE"
  A_ID="$NEWID"
  if [ -n "$A_ID" ]; then
    UPLOADED="$UPLOADED $A_ID"
    if [ "$HAVE_PSQL" -eq 1 ]; then
      expect "DB scan_status" "pending" "$(psqlq "SELECT scan_status FROM files WHERE public_id='$A_ID'")"
    else skip "DB scan_status" "psql 없음"; fi

    sub "검사 전 다운로드 — 423 Locked + Retry-After (F-57, T-69 (a))"
    M=$(mark)
    # 한 번의 요청에서 상태·헤더·본문을 같이 받는다 — 검사 창(배치 매분)이 짧아
    # 같은 요청을 여러 번 보내면 그 사이에 clean 으로 바뀔 수 있다.
    DL_HDR="$WORK/F54-a-download.hdr"
    DL_BODY=$(curl -s -D "$DL_HDR" -b "$JAR" -A "$UA" "$BASE/api/files/$A_ID/download")
    expect "다운로드 상태" "423" \
      "$(sed -n 's#^HTTP/[0-9.]* \([0-9][0-9][0-9]\).*#\1#p' "$DL_HDR" | tail -1)"
    expect "Retry-After 헤더" "30" \
      "$(grep -i '^Retry-After:' "$DL_HDR" | tail -1 | tr -d '\r' | sed 's/^[^:]*: *//')"
    contains "응답 문구" "검사 중" "$DL_BODY"
    expect "본문 retry_after" "30" \
      "$(printf '%s' "$DL_BODY" | sed -n 's/.*"retry_after":\([0-9]*\).*/\1/p')"
    if [ "$HAVE_PSQL" -eq 1 ]; then
      expect "감사 action|result|reason" "file.download|deny|scan_pending" \
        "$(psqlq "SELECT action||'|'||result||'|'||coalesce(reason,'') FROM audit_logs WHERE id > ${M:-0} AND action='file.download' ORDER BY id DESC LIMIT 1")"
      note "감사 문장: $(psqlq "SELECT summary FROM audit_logs WHERE id > ${M:-0} AND action='file.download' ORDER BY id DESC LIMIT 1")"
    else skip "감사 확인" "psql 없음"; fi
    expect "목록 응답의 scan_status" "pending" "$(list_scan_of "$A_ID")"

    sub "배치 1회"
    M=$(mark)
    OUT=$(run_batch)
    printf '%s\n' "$OUT" | sed 's/^/    | /'
    contains "배치 출력 요약" "완료:" "$OUT"
    if [ "$HAVE_PSQL" -eq 1 ]; then
      expect "DB scan_status (검사 뒤)" "clean" "$(psqlq "SELECT scan_status FROM files WHERE public_id='$A_ID'")"
      expect "감사 file.scan" "file.scan|allow" \
        "$(psqlq "SELECT action||'|'||result FROM audit_logs WHERE id > ${M:-0} AND action='file.scan' AND target_id=(SELECT id FROM files WHERE public_id='$A_ID') ORDER BY id DESC LIMIT 1")"
      note "감사 문장: $(psqlq "SELECT summary FROM audit_logs WHERE id > ${M:-0} AND action='file.scan' AND target_id=(SELECT id FROM files WHERE public_id='$A_ID') ORDER BY id DESC LIMIT 1")"
    else skip "DB·감사 확인" "psql 없음"; fi
    expect "검사 뒤 다운로드" "200" "$(get_code "/api/files/$A_ID/download")"
    expect "목록 응답의 scan_status (검사 뒤)" "clean" "$(list_scan_of "$A_ID")"
  else
    skip "(a) 나머지" "업로드 응답에서 id 를 읽지 못했다"
  fi
fi

# ─── (b) EICAR — infected → 격리 → 403 → AL-19 ──────────────────
hr "(b) EICAR 시험 파일 — 격리 · 403 · 경보 AL-19"

B_ID=""
B_STORED=""
if [ "$SCAN_ON" -eq 0 ]; then
  skip "(b) 전체" "SCAN_ENABLED 가 켜져 있지 않다 — Fable 이 켠 뒤 재실행"
elif [ "$HAVE_SESSION" -eq 0 ]; then
  skip "(b) 전체" "세션 없음"
else
  # EICAR 표준 시험 문자열 — 진짜 악성코드가 아니라 "백신이 반응하는지" 를 보는 규약 문자열이다.
  printf 'X5O!P%%@AP[4\\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*' > "$WORK/eicar-test.txt"
  note "eicar-test.txt 크기: $(wc -c < "$WORK/eicar-test.txt") 바이트 · mime: $(file -b --mime-type "$WORK/eicar-test.txt")"
  up "$WORK/eicar-test.txt"
  expect "업로드 상태" "201" "$CODE"
  B_ID="$NEWID"
  if [ -n "$B_ID" ] && [ "$HAVE_PSQL" -eq 1 ]; then
    expect "DB scan_status" "pending" "$(psqlq "SELECT scan_status FROM files WHERE public_id='$B_ID'")"
    B_STORED=$(psqlq "SELECT stored_name FROM files WHERE public_id='$B_ID'")
    note "stored_name: $B_STORED"

    sub "배치 1회"
    M=$(mark)
    OUT=$(run_batch)
    printf '%s\n' "$OUT" | sed 's/^/    | /'
    expect "DB scan_status (검사 뒤)" "infected" "$(psqlq "SELECT scan_status FROM files WHERE public_id='$B_ID'")"
    expect "deleted_at 은 건드리지 않는다" "t" "$(psqlq "SELECT deleted_at IS NULL FROM files WHERE public_id='$B_ID'")"
    expect "감사 file.scan" "file.scan|deny|infected" \
      "$(psqlq "SELECT action||'|'||result||'|'||coalesce(reason,'') FROM audit_logs WHERE id > ${M:-0} AND action='file.scan' AND target_id=(SELECT id FROM files WHERE public_id='$B_ID') ORDER BY id DESC LIMIT 1")"
    SIG=$(psqlq "SELECT detail->>'signature' FROM audit_logs WHERE id > ${M:-0} AND action='file.scan' AND result='deny' AND target_id=(SELECT id FROM files WHERE public_id='$B_ID') ORDER BY id DESC LIMIT 1")
    note "detail.signature: ${SIG:-없음}"
    case "$SIG" in
      *Eicar*|*EICAR*|*eicar*) printf '  ✔ 시그니처에 Eicar 포함\n'; PASS=$((PASS+1)) ;;
      *)                       printf '  ✖ 시그니처에 Eicar 없음 (실제: %s)\n' "${SIG:-없음}"; FAIL=$((FAIL+1)) ;;
    esac
    note "감사 문장: $(psqlq "SELECT summary FROM audit_logs WHERE id > ${M:-0} AND action='file.scan' AND result='deny' AND target_id=(SELECT id FROM files WHERE public_id='$B_ID') ORDER BY id DESC LIMIT 1")"

    sub "바이트는 어디에 있나 (삭제가 아니라 이동)"
    if [ -n "$B_STORED" ]; then
      QPATH="/var/lib/tbox-v2/quarantine/$B_STORED"
      SPATH="/var/lib/tbox-v2/storage/$(printf '%s' "$B_STORED" | cut -c1-2)/$(printf '%s' "$B_STORED" | cut -c3-4)/$B_STORED"
      QLS=$(sudo -n ls -l "$QPATH" 2>&1)
      note "격리: $QLS"
      expect "격리 폴더에 있다" "0" "$(sudo -n test -f "$QPATH" && echo 0 || echo 1)"
      expect "격리 파일 권한" "640" "$(sudo -n stat -c %a "$QPATH" 2>/dev/null)"
      expect "저장소에는 없다" "1" "$(sudo -n test -e "$SPATH" && echo 0 || echo 1)"
    else
      skip "격리 위치 확인" "stored_name 을 읽지 못했다"
    fi

    sub "격리 파일은 아무도 못 받는다"
    expect "다운로드 상태" "403" "$(get_code "/api/files/$B_ID/download")"
    contains "다운로드 응답 문구" "격리" "$(get_body "/api/files/$B_ID/download")"
    expect "미리보기 상태" "403" "$(get_code "/api/files/$B_ID/preview")"
    contains "미리보기 응답 문구" "격리" "$(get_body "/api/files/$B_ID/preview")"
    expect "목록 응답의 scan_status" "infected" "$(list_scan_of "$B_ID")"

    sub "경보 AL-19 (--dry-run · 시험용 상태 파일)"
    ASTATE="$WORK/alerts-state.json"
    printf '{}\n' > "$ASTATE"
    AOUT=$(sudo -n php "$ROOT/scripts/alerts.php" --rule AL-19 --dry-run --state "$ASTATE" 2>&1)
    printf '%s\n' "$AOUT" | sed 's/^/    | /'
    contains "AL-19 발동" "AL-19" "$AOUT"
    contains "AL-19 근거에 시그니처" "signature" "$AOUT"
  else
    skip "(b) 나머지" "업로드 id 또는 psql 없음"
  fi
fi

# ─── (c) 대기 0건 · 잠금 ────────────────────────────────────────
hr "(c) 대기 0건이면 스캐너를 띄우지 않는다 · 잠금이 있으면 즉시 끝난다"

sub "대기 0건"
T0=$(date +%s.%N)
OUT=$(run_batch)
T1=$(date +%s.%N)
printf '%s\n' "$OUT" | sed 's/^/    | /'
ELAPSED=$(awk -v a="$T0" -v b="$T1" 'BEGIN{printf "%.2f", b-a}')
note "소요: ${ELAPSED}초"
contains "출력에 「검사 대기 없음」" "검사 대기 없음" "$OUT"
expect "2초 미만" "예" "$(awk -v s="$ELAPSED" 'BEGIN{print (s < 2) ? "예" : "아니오"}')"

sub "잠금 — 남이 쥐고 있으면 「이미 실행 중」"
if command -v flock >/dev/null 2>&1; then
  # tbox-php 는 nologin 이라 `flock -c '<셸 명령>'` 을 쓸 수 없다 — 명령을 직접 준다.
  sudo -n -u tbox-php flock -x /var/lib/tbox-v2/scan.lock sleep 6 &
  HOLDER=$!
  sleep 1
  T0=$(date +%s.%N)
  OUT=$(run_batch)
  T1=$(date +%s.%N)
  printf '%s\n' "$OUT" | sed 's/^/    | /'
  contains "출력에 「이미 실행 중」" "이미 실행 중" "$OUT"
  expect "즉시 종료(2초 미만)" "예" \
    "$(awk -v a="$T0" -v b="$T1" 'BEGIN{print ((b-a) < 2) ? "예" : "아니오"}')"
  wait "$HOLDER" 2>/dev/null
else
  skip "잠금 시험" "flock 명령이 없다"
fi

sub "격리는 됐는데 기록을 못 남긴 경우의 복구 (코드 읽기 항목)"
# 재현하려면 「rename 성공 → COMMIT 직전에 프로세스 죽이기」가 필요하고, 그것을 운영 DB 에서
# 만들 수 없다(pending 행을 손으로 만드는 것은 금지 — 운영 데이터 불변). 그래서 HTTP/DB 재현
# 대신 **그 갈래가 코드에 있는지**만 본다. 실제 동작은 (b) 가 켜진 뒤에도 자동으로는 확인되지
# 않으므로 이 항목은 언제나 「코드 읽기」다.
# 주석의 설명이 아니라 **코드의 대입**만 센다.
REC=$(grep -c "=> 'quarantined_earlier'," "$ROOT/scripts/scan_pending.php")
expect "복구 갈래(signature: quarantined_earlier) 가 있다" "1" "$REC"
grep -n 'QUARANTINE_DIR . ./. . basename($path)' "$ROOT/scripts/scan_pending.php" | sed 's/^/    | /'
note "저장소에 바이트가 없을 때 격리 폴더를 먼저 보고, 있으면 옮기지 않고 UPDATE+감사만 마무리한다"
note "그 밖(사람이 지웠다·저장소 사고)은 지금처럼 file_missing 으로 남기고 상태는 pending 그대로"
RECFAIL=$(grep -c "scan_fail(\$row, 'file_missing')" "$ROOT/scripts/scan_pending.php")
expect "file_missing 갈래도 그대로 있다" "1" "$RECFAIL"
note "실제 재현(rename 뒤 COMMIT 전 강제 종료)은 **미검증** — 운영 DB 에 pending 행을 손으로 만들지 않는다"
SKIP=$((SKIP+1))

# ─── (d) 초기 상태 — 단위 시험 ──────────────────────────────────
hr "(d) Upload::initialScanStatus() — SCAN_ENABLED 없음 / 0 / 1"

# `.env` 를 고치지 않는다. Env::load 에 **임시 파일 경로**를 주어 세 경우를 재현한다
# (Env 는 정적 캐시라 php 프로세스를 세 번 따로 띄운다).
d_case() {   # $1 설명  $2 임시 .env 내용  $3 기대값
  local f="$WORK/env-$$.tmp" got
  printf '%s' "$2" > "$f"
  got=$(php -r '
    require "'"$ROOT"'/src/Env.php";
    require "'"$ROOT"'/src/Upload.php";
    Env::load($argv[1]);
    echo Upload::initialScanStatus();
  ' "$f" 2>&1)
  rm -f "$f"
  expect "$1" "$3" "$got"
}
d_case "SCAN_ENABLED 키 없음"  "$(printf 'DB_HOST=127.0.0.1\n')"     "clean"
d_case "SCAN_ENABLED=0"        "$(printf 'SCAN_ENABLED=0\n')"        "clean"
d_case "SCAN_ENABLED=1"        "$(printf 'SCAN_ENABLED=1\n')"        "pending"
d_case "SCAN_ENABLED=true (1 이 아니면 전부 clean)" "$(printf 'SCAN_ENABLED=true\n')" "clean"

# ─── (e) 서버 층 ────────────────────────────────────────────────
hr "(e) 서버 층 — 패키지 · 데몬 없음 · 시그니처 · 격리 · cron · 리스너"

DPKG=$(dpkg -l clamav clamav-freshclam 2>/dev/null | awk '/^ii/{printf "%s ", $2}')
note "dpkg -l: ${DPKG:-없음}"
case "$DPKG" in *clamav\ *) printf '  ✔ clamav 설치됨\n'; PASS=$((PASS+1)) ;; *) printf '  ✖ clamav 미설치\n'; FAIL=$((FAIL+1)) ;; esac
case "$DPKG" in *clamav-freshclam*) printf '  ✔ clamav-freshclam 설치됨\n'; PASS=$((PASS+1)) ;; *) printf '  ✖ clamav-freshclam 미설치\n'; FAIL=$((FAIL+1)) ;; esac
expect "clamav-daemon 은 **미설치**가 정상 (R-86)" "1" "$(dpkg -s clamav-daemon >/dev/null 2>&1 && echo 0 || echo 1)"
expect "clamav-freshclam active" "active" "$(systemctl is-active clamav-freshclam 2>/dev/null)"
note "clamscan: $(clamscan --version 2>/dev/null | head -1)"

SIG_FILE=$(ls -t /var/lib/clamav/daily.c*d 2>/dev/null | head -1)
if [ -n "${SIG_FILE:-}" ]; then
  SIG_AGE=$(( ( $(date +%s) - $(stat -c %Y "$SIG_FILE") ) / 3600 ))
  note "시그니처 $SIG_FILE — $(stat -c %y "$SIG_FILE" | cut -d. -f1) (${SIG_AGE}시간 전)"
  expect "시그니처 24시간 이내" "예" "$([ "$SIG_AGE" -le 24 ] && echo 예 || echo 아니오)"
else
  skip "시그니처 나이" "/var/lib/clamav/daily.c*d 를 읽지 못했다"
fi

expect "격리 디렉터리 0750 tbox-php" "750 tbox-php:tbox-php" \
  "$(sudo -n stat -c '%a %U:%G' /var/lib/tbox-v2/quarantine 2>/dev/null)"

expect "deploy/cron.d/tbox-v2 에 scan_pending 행 (주석 제외)" "1" \
  "$(grep -c '^[^#].*scan_pending\.php' "$ROOT/deploy/cron.d/tbox-v2")"
note "cron 행: $(grep 'scan_pending.php' "$ROOT/deploy/cron.d/tbox-v2" | grep -v '^#')"
if [ -r /etc/cron.d/tbox-v2 ]; then
  if grep -q 'scan_pending.php' /etc/cron.d/tbox-v2; then
    note "실제 /etc/cron.d/tbox-v2 에도 행이 있다"
  else
    note "실제 /etc/cron.d/tbox-v2 에는 아직 없다 — Fable 이 검토 뒤 복사한다(시험은 배치를 직접 부른다)"
  fi
else
  note "실제 /etc/cron.d/tbox-v2 를 읽지 못했다 (root 권한 필요)"
fi

# T-18 — clamd 를 쓰지 않으므로 리스닝 포트가 늘어나면 안 된다.
CLAMLISTEN=$(ss -tlnp 2>/dev/null | grep -ci 'clam' || true)
expect "리스닝 포트에 clam 없음 (T-18)" "0" "${CLAMLISTEN:-0}"

sub "check_baseline.sh 의 새 절"
# hdr() 이 ANSI 색을 붙이므로 먼저 벗긴 뒤 절만 잘라 낸다.
BOUT=$(sudo -n bash "$ROOT/scripts/check_baseline.sh" 2>/dev/null \
       | sed 's/\x1b\[[0-9;]*m//g' | sed -n '/악성코드 검사/,/^── 계정/p')
printf '%s\n' "$BOUT" | sed 's/^/    | /'
contains "절이 있다" "악성코드 검사" "$BOUT"
contains "clamav-daemon 미설치를 OK 로 본다" "clamav-daemon 미설치" "$BOUT"

# ─── (f) 자원 ───────────────────────────────────────────────────
hr "(f) 자원 — 배치 1회(대기 1건)의 최대 RSS·시간"

if [ "$SCAN_ON" -eq 0 ]; then
  skip "(f) 자원 측정" "SCAN_ENABLED 가 꺼져 있어 대기 행을 만들 수 없다 — Fable 이 켠 뒤 재실행"
elif [ "$HAVE_SESSION" -eq 0 ]; then
  skip "(f) 자원 측정" "세션 없음"
elif [ ! -x /usr/bin/time ]; then
  skip "(f) 자원 측정" "/usr/bin/time 이 없다"
else
  printf 'F-54 자원 측정용 파일입니다.\n' > "$WORK/F54-perf.txt"
  up "$WORK/F54-perf.txt"
  expect "측정용 업로드" "201" "$CODE"
  [ -n "$NEWID" ] && UPLOADED="$UPLOADED $NEWID"
  sudo -n -u tbox-php /usr/bin/time -v php "$ROOT/scripts/scan_pending.php" --force-enabled \
    > "$WORK/perf.out" 2> "$WORK/perf.err"
  sed 's/^/    | /' "$WORK/perf.out"
  RSS_KB=$(sed -n 's/.*Maximum resident set size (kbytes): *\([0-9]*\).*/\1/p' "$WORK/perf.err")
  WALL=$(sed -n 's/.*Elapsed (wall clock) time.*: *\(.*\)$/\1/p' "$WORK/perf.err")
  note "최대 RSS: ${RSS_KB:-?} KB · 소요(wall): ${WALL:-?}"
  if [ -n "${RSS_KB:-}" ]; then
    expect "최대 RSS < 1.5GB" "예" "$([ "$RSS_KB" -lt 1572864 ] && echo 예 || echo 아니오)"
  else
    skip "최대 RSS" "/usr/bin/time -v 출력을 읽지 못했다"
  fi
fi

# ─── (g) 정리 · 린트 ────────────────────────────────────────────
hr "(g) 정리 — 시험 파일은 휴지통으로(격리 파일은 그대로 둔다) · 린트 · php -l · bash -n"

N_UP=0; N_DEL=0
for id in $UPLOADED; do
  N_UP=$((N_UP+1))
  D=$(curl -s -o /dev/null -w '%{http_code}' -X DELETE -b "$JAR" -A "$UA" \
      -H "X-CSRF-Token: $CSRF" "$BASE/api/files/$id")
  [ "$D" = "200" ] && N_DEL=$((N_DEL+1)) || printf '  ✖ 정리 실패 %s → %s\n' "$id" "$D"
done
expect "올린 파일 휴지통으로 (psql 삭제 없음)" "$N_UP" "$N_DEL"
if [ -n "$B_ID" ]; then
  note "격리된 EICAR 파일($B_ID)은 **그대로 둔다** — PRD T-66 (g) 증거. 바이트는 quarantine/ 에 남는다"
  SKIP=$((SKIP+1))
fi

sub "보안 린트"
bash "$ROOT/scripts/security_lint.sh" >"$WORK/lint.out" 2>&1
LINT=$?
expect "scripts/security_lint.sh 종료 코드" "0" "$LINT"
expect "✖ 표시 줄 수" "0" "$(grep -c '^✖' "$WORK/lint.out")"
tail -1 "$WORK/lint.out" | sed 's/^/    /'

sub "앱 코드(src/·public/)에 exec·proc_open·소켓이 0인가"
APPHITS=$(grep -rnE 'proc_open|shell_exec|passthru|popen\(|\bexec\(|fsockopen|stream_socket_' "$ROOT/src" "$ROOT/public" 2>/dev/null | wc -l)
expect "0건" "0" "$APPHITS"
# 스캐너를 **실행**하는 곳은 배치 하나뿐이어야 한다 (ARCH 5.28). 주석의 언급이 아니라
# `proc_open(` 호출이 있는 파일만 센다.
expect "proc_open( 이 있는 배치" "scan_pending.php" \
  "$(cd "$ROOT/scripts" && grep -l 'proc_open(' ./*.php 2>/dev/null | sed 's#^\./##' | tr '\n' ' ' | sed 's/ $//')"

sub "검사 문구 상수는 Http.php 한 곳에만 (include 되는 파일의 최상위 const 중복 금지)"
expect "Http.php 에 두 상수" "2" \
  "$(grep -c '^    public const MSG_SCAN_' "$ROOT/src/Http.php")"
expect "핸들러의 파일 최상위 const MSG_SCAN_* 0건" "0" \
  "$(grep -rc '^const MSG_SCAN_' "$ROOT/src/handlers/" 2>/dev/null | awk -F: '{n+=$2} END{print n+0}')"
expect "다섯 창구가 Http::MSG_SCAN_* 를 쓴다 (F-65 exec-download 포함)" "5" \
  "$(grep -rl 'Http::MSG_SCAN_' "$ROOT/src/handlers/" | wc -l)"

sub "php -l · bash -n"
PHPBAD=0
for f in src/Upload.php src/Audit.php src/Http.php src/handlers/_upload_common.php src/handlers/files_fetch.php \
         src/handlers/files_download.php src/handlers/files_preview.php \
         src/handlers/files_preview_raw.php src/handlers/share_download.php \
         src/handlers/files_list.php scripts/scan_pending.php scripts/alerts.php; do
  php -l "$ROOT/$f" >/dev/null 2>&1 || { echo "  ✖ php -l 실패: $f"; PHPBAD=1; }
done
expect "php -l (수정한 12개 PHP)" "0" "$PHPBAD"

BASHBAD=0
for f in scripts/check_baseline.sh deploy/install.sh tests/f54.sh; do
  bash -n "$ROOT/$f" 2>/dev/null || { echo "  ✖ bash -n 실패: $f"; BASHBAD=1; }
done
expect "bash -n (3개 셸)" "0" "$BASHBAD"

# ─── 마무리 ─────────────────────────────────────────────────────
hr "결과"
printf '통과 %d · 실패 %d · 건너뜀/미검증 %d\n' "$PASS" "$FAIL" "$SKIP"
if [ "$SCAN_ON" -eq 0 ]; then
  echo "미검증: (a) 정상 파일 pending→clean · (b) EICAR 격리·AL-19 · (f) 자원 —"
  echo "        .env 의 SCAN_ENABLED 가 켜져 있지 않다. Fable 이 켠 뒤 이 스크립트를 다시 돌린다."
fi
echo "작업 폴더 $WORK 는 종료와 함께 지워진다."
[ "$FAIL" -eq 0 ] || exit 1
exit 0
