#!/usr/bin/env bash
# tests/api_upload.sh — F-49 「프로그램용 업로드 API」 수락 기준 실행 (PRD 12.2 T-61)
#
#   (a) 헤더 없이 POST /api/upload → 401 {"error":"X-API-Token 헤더가 없습니다"} + 감사
#       `auth.token deny missing_header`.
#       ★ .env 에 API_TOKEN 이 아직 없으면 **503 not_configured 가 정답**이다(닫힌 실패,
#         PRD F-49 ①). 이 스크립트는 둘 중 하나를 통과로 보되 **어느 쪽이었는지 반드시 출력**한다.
#   (b) 틀린 토큰 → 401 「API 토큰이 올바르지 않습니다」 + `auth.token deny bad_token`
#       (미설정이면 위와 같은 이유로 503 not_configured).
#   (c) 다른 라우트는 토큰을 **무시한다** — 틀린 토큰 헤더로 GET /api/users ·
#       DELETE /api/files/{uuid} → 401 「API 토큰이 올바르지 않습니다」(F-59 뒤 토큰 창구) · `auth.token` 행 1.
#   (d) 웹 업로드 회귀 — qa_employee01 세션으로 POST /api/files 정상 txt 201 {id,name}(키 정확히 둘) ·
#       x.exe → 400 `ext_not_allowed` 문장(F-68 · ARCH 5.30, 허용 목록은 Upload::ALLOWED_EXT) ·
#       감사 사유 코드 전과 같음 ·
#       올린 파일은 같은 세션으로 DELETE 해 정리한다.
#   (e) 102MB 를 /api/upload 에(헤더 없이) → 413 + JSON {"error":…} · Content-Type: application/json.
#       ★ nginx reload 전이면 기본 HTML 413 이 나온다 — 그 경우 「reload 전 실측」으로 적고
#         실패로 세지 않되 **미검증**으로 표시한다.
#       ★ 이 시험은 검토 포트 8082(`deploy/sites/tbox-v2-local.conf`)를 때린다. JSON 413 은
#         **공개용 `tbox-v2.conf` 에만** 넣었으므로(지시서 수정 파일 목록), 8082 에서는 reload
#         뒤에도 HTML 413 일 수 있다. 어느 쪽이든 받은 그대로 적는다.
#   (f) 회귀 — scripts/security_lint.sh 0 · 바뀐 PHP php -l · bash -n 두 스크립트 · nginx -t(가능하면)
#
#   (g) 긍정 경로(진짜 토큰으로 201)는 **여기서 하지 않는다**. 진짜 토큰은 사용자 계정
#       (API_TOKEN_USER)으로 동작하고 그 값을 읽으면 검토 기록에 비밀이 남는다(SECURITY R-62).
#       → 관문 ③에서 사용자가 실행하고 결과를 STAGES.md 에 적는다.
#
# 규칙 (CLAUDE.md 3장)
#   · **검토 계정(qa_ 접두)만** 쓴다. 시드 계정(admin01 …)은 사용자의 자기 공격 전용이다.
#   · 모든 요청에 `-A "tbox-review/<날짜>"` 를 붙인다 — 감사 로그에서 사람의 행동과 갈라 보려는 것.
#   · 비밀번호·TOTP 비밀은 필요한 필드만 awk 로 뽑아 변수에만 담는다 (SECURITY R-62).
#   · **운영 DB·.env·nginx·php-fpm 에 손대지 않는다.** 실제 .env 는 읽지도 복사하지도 않는다
#     (키가 있는지 필요하면 `grep -c '^API_TOKEN=' .env` 처럼 **개수만** 센다).
#
# ★ 시험 안전 (SECURITY R-73 · R-80) — 19~26단계 스크립트와 같은 규칙
#   ① 최근 10분 감사 로그에 비-qa actor 가 있으면 시작하지 않는다(종료 코드 3)
#   ② 만드는 파일은 전부 `F49-` 접두이고, 올린 것은 같은 세션으로 되돌린다
#   ③ 루트 파티션 여유가 1GB 미만이면 시작하지 않는다
#
# 준비물: curl · php(TOTP) · sudo -n(seed-accounts.txt 읽기 · psql). 없으면 해당 항목 SKIP.
# 사용: sudo -v && bash tests/api_upload.sh
set -u

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BASE="http://127.0.0.1:8082/tbox"
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
sub() { printf '\n── %s\n' "$1"; }
expect() {
  if [ "$2" = "$3" ]; then printf '  ✔ %s  (기대=%s 실제=%s)\n' "$1" "$2" "$3"; PASS=$((PASS+1))
  else printf '  ✖ %s  (기대=%s 실제=%s)\n' "$1" "$2" "$3"; FAIL=$((FAIL+1)); fi
}
# 둘 중 하나면 통과 — (a)(b) 의 「미설정이면 503」 처럼 서버 상태에 따라 정답이 갈릴 때만 쓴다.
expect2() {
  if [ "$3" = "$2" ] || [ "$3" = "$4" ]; then printf '  ✔ %s  (기대=%s 또는 %s · 실제=%s)\n' "$1" "$2" "$4" "$3"; PASS=$((PASS+1))
  else printf '  ✖ %s  (기대=%s 또는 %s · 실제=%s)\n' "$1" "$2" "$4" "$3"; FAIL=$((FAIL+1)); fi
}
note() { printf '  · %s\n' "$1"; }
skip() { printf '  ○ SKIP %s  (%s)\n' "$1" "$2"; SKIP=$((SKIP+1)); }

psqlq() { sudo -n -u postgres psql -d "$DB" -t -A -c "$1" 2>/dev/null; }

# 감사 로그의 **물길 표시**. 시각(초 단위)으로 자르면 같은 초에 들어온 앞 항목의 행이
# 다음 항목의 창에 섞여 든다(2026-09-15 실측: (b) 의 auth.token 행이 (c) 의 「0건」을 깨뜨렸다).
# 그래서 id 를 쓴다 — 행 번호는 겹치지 않는다. psql 이 없으면 0 이고, 그 경우 확인 항목은 건너뛴다.
mark() { psqlq "SELECT coalesce(max(id),0) FROM audit_logs" 2>/dev/null; }

code_of() { printf '%s' "$1" | tail -1; }
body_of() { printf '%s' "$1" | sed '$d'; }
uuid_of() { printf '%s' "$1" | sed -n 's/.*"id":"\([0-9a-f-]\{36\}\)".*/\1/p' | head -1; }

# 틀린 토큰. 진짜 토큰은 **읽지도 쓰지도 않는다** — 이 값은 어떤 서버에서도 맞을 수 없는 고정 문자열이다.
WRONG_TOKEN='wrong-token-0000000000000000000000000000000000'

# ─── 시작 전 관문 ────────────────────────────────────────────────
hr "F-49 프로그램용 업로드 API — 시험 시작 전 확인"

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

# .env 에 토큰 키가 있는지 **개수만** 센다. 값은 읽지 않는다 (지시서 「하지 말 것」).
# grep -c 는 0건일 때 `0` 을 찍고 종료코드 1 을 낸다 — `|| echo 0` 을 붙이면 0 이 두 줄이 된다.
TOKEN_SET=$(grep -c '^API_TOKEN=..*$' "$ROOT/.env" 2>/dev/null); TOKEN_SET=${TOKEN_SET:-0}
USER_SET=$(grep -c '^API_TOKEN_USER=..*$' "$ROOT/.env" 2>/dev/null); USER_SET=${USER_SET:-0}
echo ".env 의 API_TOKEN 값 있음: $TOKEN_SET · API_TOKEN_USER 값 있음: $USER_SET  (값은 읽지 않는다)"
if [ "$TOKEN_SET" -eq 0 ]; then
  echo "→ 토큰 **미설정** 상태다. (a)(b) 의 정답은 503 not_configured 다 (닫힌 실패, PRD F-49 ①)."
else
  echo "→ 토큰 설정됨. (a)(b) 의 정답은 401 이다."
fi

printf 'F-49 시험용 텍스트 파일입니다.\n' > "$WORK/F49-ok.txt"
printf 'MZ fake executable\n'             > "$WORK/F49-bad.exe"

# ─── (a) 헤더 없이 ───────────────────────────────────────────────
hr "(a) 헤더 없이 POST /api/upload"
T0=$(mark)
OUT=$(curl -s -w '\n%{http_code}' -X POST -A "$UA" -F "file=@$WORK/F49-ok.txt" "$BASE/api/upload")
CODE=$(code_of "$OUT"); BODY=$(body_of "$OUT")
echo "  응답: $BODY"
expect2 "헤더 없음 → 401(설정됨) 또는 503(미설정)" "401" "$CODE" "503"
if [ "$CODE" = "401" ]; then
  expect "문구" '{"error":"X-API-Token 헤더가 없습니다"}' "$BODY"
  WANT_A="missing_header"
else
  expect "문구" '{"error":"API 토큰이 서버에 설정되지 않았습니다"}' "$BODY"
  WANT_A="not_configured"
  note "받은 것은 **503 not_configured** — .env 에 API_TOKEN 이 없어서다(정답). 401 missing_header 는 이 실행에서 미검증."
fi
if [ "$HAVE_PSQL" -eq 1 ]; then
  GOT=$(psqlq "SELECT reason FROM audit_logs WHERE action='auth.token' AND id > ${T0:-0} ORDER BY id DESC LIMIT 1")
  expect "감사 auth.token deny 사유" "$WANT_A" "${GOT:-없음}"
  TGT=$(psqlq "SELECT target_type||' '||coalesce(target_name,'') FROM audit_logs WHERE action='auth.token' AND id > ${T0:-0} ORDER BY id DESC LIMIT 1")
  expect "감사 대상(계정 정보 없음)" "system POST /api/upload" "${TGT:-없음}"
fi

# ─── (b) 틀린 토큰 ───────────────────────────────────────────────
hr "(b) 틀린 토큰으로 POST /api/upload"
T0=$(mark)
OUT=$(curl -s -w '\n%{http_code}' -X POST -A "$UA" -H "X-API-Token: $WRONG_TOKEN" \
      -F "file=@$WORK/F49-ok.txt" "$BASE/api/upload")
CODE=$(code_of "$OUT"); BODY=$(body_of "$OUT")
echo "  응답: $BODY"
expect2 "틀린 토큰 → 401(설정됨) 또는 503(미설정)" "401" "$CODE" "503"
if [ "$CODE" = "401" ]; then
  expect "문구" '{"error":"API 토큰이 올바르지 않습니다"}' "$BODY"
  WANT_B="bad_token"
else
  WANT_B="not_configured"
  note "받은 것은 **503 not_configured** — 토큰 미설정이면 헤더를 보든 말든 닫는다(정답). 401 bad_token 은 이 실행에서 미검증."
fi
if [ "$HAVE_PSQL" -eq 1 ]; then
  GOT=$(psqlq "SELECT reason FROM audit_logs WHERE action='auth.token' AND id > ${T0:-0} ORDER BY id DESC LIMIT 1")
  expect "감사 auth.token deny 사유" "$WANT_B" "${GOT:-없음}"
fi
# 토큰 값이 감사 로그 어디에도 없어야 한다 (PRD S-09-11)
if [ "$HAVE_PSQL" -eq 1 ]; then
  LEAK=$(psqlq "SELECT count(*) FROM audit_logs WHERE id > ${T0:-0} AND (coalesce(detail::text,'')||coalesce(summary,'')||coalesce(target_name,'')) LIKE '%${WRONG_TOKEN}%'")
  expect "감사 로그에 토큰 문자열 0건" "0" "${LEAK:-?}"
fi

# ─── (c) 다른 라우트는 토큰을 무시한다 ──────────────────────────
hr "(c) 다른 라우트는 X-API-Token 을 무시한다"
T0=$(mark)
OUT=$(curl -s -w '\n%{http_code}' -A "$UA" -H "X-API-Token: $WRONG_TOKEN" "$BASE/api/users")
echo "  GET /api/users → $(body_of "$OUT")"
expect "GET /api/users 상태" "401" "$(code_of "$OUT")"
expect "GET /api/users 문구(세션 경로)" '{"error":"로그인이 필요합니다"}' "$(body_of "$OUT")"

OUT=$(curl -s -w '\n%{http_code}' -X DELETE -A "$UA" -H "X-API-Token: $WRONG_TOKEN" \
      "$BASE/api/files/00000000-0000-4000-8000-000000000000")
echo "  DELETE /api/files/{uuid} → $(body_of "$OUT")"
expect "DELETE /api/files/{id} 상태" "401" "$(code_of "$OUT")"
# F-59(2026-09-16) 뒤 DELETE /api/files/{id} 는 **토큰 창구**다(TBOX_API_TOKEN_ROUTES) — 틀린 토큰은 토큰 문구 401 + auth.token 행 1.
# (F-65 검토 2026-09-21 에 드리프트 발견 — 그때까지 기대가 F-59 이전 「세션 경로」로 남아 있었다.)
expect "DELETE /api/files/{id} 문구(토큰 경로, F-59)" '{"error":"API 토큰이 올바르지 않습니다"}' "$(body_of "$OUT")"

if [ "$HAVE_PSQL" -eq 1 ]; then
  N=$(psqlq "SELECT count(*) FROM audit_logs WHERE action='auth.token' AND id > ${T0:-0}")
  expect "그 사이 auth.token 감사 행 (DELETE 는 토큰 창구라 1)" "1" "${N:-?}"
  N=$(psqlq "SELECT count(*) FROM audit_logs WHERE action='auth.required' AND id > ${T0:-0}")
  note "같은 구간의 auth.required 행: ${N:-?} (세션 경로로 갔다는 뜻)"
fi

# ─── (d) 웹 업로드 회귀 ─────────────────────────────────────────
hr "(d) 웹 업로드 회귀 — POST /api/files 동작 불변"

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
if [ -z "${CSRF:-}" ]; then
  skip "(d) 웹 업로드 회귀" "qa_employee01 로그인 실패 또는 sudo 로 계정 파일을 읽을 수 없음"
else
  T0=$(mark)
  OUT=$(curl -s -w '\n%{http_code}' -X POST -b "$JAR_DIR/qa_employee01.jar" -A "$UA" \
        -H "X-CSRF-Token: $CSRF" -F "file=@$WORK/F49-ok.txt" -F 'access_level=internal' \
        "$BASE/api/files")
  CODE=$(code_of "$OUT"); BODY=$(body_of "$OUT")
  echo "  정상 txt → $BODY"
  expect "정상 업로드 상태" "201" "$CODE"
  KEYS=$(printf '%s' "$BODY" | tr ',' '\n' | sed -n 's/.*"\([a-z_]*\)":.*/\1/p' | sort | tr '\n' ' ')
  expect "응답 키가 정확히 둘 (등급은 담지 않는다)" "id name " "$KEYS"

  NEWID=$(uuid_of "$BODY")
  if [ -n "$NEWID" ]; then
    DEL=$(curl -s -o /dev/null -w '%{http_code}' -X DELETE -b "$JAR_DIR/qa_employee01.jar" -A "$UA" \
          -H "X-CSRF-Token: $CSRF" "$BASE/api/files/$NEWID")
    expect "올린 시험 파일 정리(휴지통)" "200" "$DEL"
  fi

  OUT=$(curl -s -w '\n%{http_code}' -X POST -b "$JAR_DIR/qa_employee01.jar" -A "$UA" \
        -H "X-CSRF-Token: $CSRF" -F "file=@$WORK/F49-bad.exe" -F 'access_level=internal' \
        "$BASE/api/files")
  echo "  x.exe → $(body_of "$OUT")"
  expect ".exe 상태" "400" "$(code_of "$OUT")"
  # F-68 — 웹도 프로그램 입구와 **같은 문장**을 쓴다. 허용 목록은 손으로 베끼지 않고
  # `Upload::ALLOWED_EXT` 에서 만든다 (ARCH 5.30 문장 규칙 ③).
  EXT_MSG=$(php -r 'require $argv[1]."/src/Http.php"; require $argv[1]."/src/Upload.php"; echo Http::reasonMessage("ext_not_allowed");' "$ROOT")
  expect ".exe 문구(F-68 ext_not_allowed 문장)" "{\"error\":\"$EXT_MSG\"}" "$(body_of "$OUT")"

  # 등급 필드가 비면 지금까지처럼 bad_access_level 400 이다 (기본값 public 은 API 입구에만 있다)
  OUT=$(curl -s -w '\n%{http_code}' -X POST -b "$JAR_DIR/qa_employee01.jar" -A "$UA" \
        -H "X-CSRF-Token: $CSRF" -F "file=@$WORK/F49-ok.txt" "$BASE/api/files")
  echo "  access_level 없음 → $(body_of "$OUT")"
  expect "등급 없는 웹 업로드 상태(기본값이 생기지 않았나)" "400" "$(code_of "$OUT")"

  if [ "$HAVE_PSQL" -eq 1 ]; then
    R=$(psqlq "SELECT string_agg(reason,',' ORDER BY id) FROM audit_logs WHERE action='file.upload' AND result='deny' AND id > ${T0:-0}")
    expect "감사 file.upload deny 사유 코드(전과 같음)" "ext_not_allowed,bad_access_level" "${R:-없음}"
    A=$(psqlq "SELECT count(*) FROM audit_logs WHERE id > ${T0:-0} AND detail ? 'auth'")
    expect "세션 업로드 행에는 detail.auth 가 없다" "0" "${A:-?}"
  fi
fi

# ─── (e) 102MB → 413 ────────────────────────────────────────────
hr "(e) 102MB 를 /api/upload 로 (nginx 크기 제한)"
truncate -s 102M "$WORK/F49-big.bin" 2>/dev/null
if [ ! -f "$WORK/F49-big.bin" ]; then
  skip "(e) 413" "102MB 희소 파일을 만들 수 없음"
else
  HDR="$WORK/big.h"
  CODE=$(curl -s -o "$WORK/big.body" -D "$HDR" -w '%{http_code}' -X POST -A "$UA" \
         -F "file=@$WORK/F49-big.bin" "$BASE/api/upload")
  CT=$(sed -n 's/^[Cc]ontent-[Tt]ype:[[:space:]]*\([^;[:space:]]*\).*/\1/p' "$HDR" | head -1)
  echo "  상태=$CODE · Content-Type=${CT:-없음}"
  echo "  본문 앞 200자: $(head -c 200 "$WORK/big.body" | tr '\n' ' ')"
  expect "413" "413" "$CODE"
  if [ "$CT" = "application/json" ]; then
    expect "413 본문이 JSON" '{"error":"파일이 100MB 를 넘습니다"}' "$(cat "$WORK/big.body")"
  else
    note "JSON 이 아니라 **$CT** 를 받았다 → 「nginx reload 전(또는 검토 포트 8082 의 tbox-v2-local.conf 에는 이 블록이 없음)」 실측. **미검증**으로 적는다."
    SKIP=$((SKIP+1))
  fi
fi

# ─── (f) 회귀 ───────────────────────────────────────────────────
hr "(f) 회귀 — 린트 · php -l · bash -n · nginx -t"
bash "$ROOT/scripts/security_lint.sh" >"$WORK/lint.out" 2>&1
expect "scripts/security_lint.sh" "0" "$?"
tail -1 "$WORK/lint.out" | sed 's/^/  /'

PHPBAD=0
for f in public/index.php src/Auth.php src/Http.php src/Audit.php \
         src/handlers/_upload_common.php src/handlers/files_upload.php src/handlers/api_upload.php; do
  php -l "$ROOT/$f" >/dev/null 2>&1 || { echo "  ✖ php -l 실패: $f"; PHPBAD=1; }
done
expect "바뀐 PHP 7개 php -l" "0" "$PHPBAD"

SHBAD=0
bash -n "$ROOT/tests/api_upload.sh" 2>/dev/null       || SHBAD=1
bash -n "$ROOT/scripts/api_token_rotate.sh" 2>/dev/null || SHBAD=1
expect "bash -n (시험·회전 스크립트)" "0" "$SHBAD"

# ★ nginx -t 는 **설치된** 설정(/etc/nginx/…)을 본다. 저장소의 deploy/sites/tbox-v2.conf 는
#   switch-site.sh 가 복사해야 설치된다 — 그래서 아래 두 검사는 뜻이 다르다.
if sudo -n nginx -t >"$WORK/ngx.out" 2>&1; then
  expect "sudo nginx -t (지금 **설치된** 설정)" "0" "0"
else
  skip "sudo nginx -t" "권한 없음 또는 실패 — $(tail -1 "$WORK/ngx.out" 2>/dev/null)"
fi

if sudo -n diff -q /etc/nginx/sites-available/tbox-v2 "$ROOT/deploy/sites/tbox-v2.conf" >/dev/null 2>&1; then
  note "저장소 conf == 설치된 conf → JSON 413 이 이미 배포돼 있다"
else
  note "저장소 conf != 설치된 conf → **JSON 413 은 아직 배포 전**이다(위 (e) 가 HTML 이었다면 이 때문). 배포는 Fable 이 한다 — 이 시험은 reload 하지 않는다."
fi

# ─── 마무리 ─────────────────────────────────────────────────────
hr "결과"
printf '통과 %d · 실패 %d · 건너뜀/미검증 %d\n' "$PASS" "$FAIL" "$SKIP"
echo "(g) 긍정 경로(진짜 토큰 → 201)는 관문 ③에서 사용자가 실행한다 — 여기서는 하지 않았다."
[ "$FAIL" -eq 0 ] || exit 1
exit 0
