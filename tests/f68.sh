#!/usr/bin/env bash
# tests/f68.sh — F-68 「400 거부의 이유를 화면에 — 사유 코드마다 사람 문장」
#                수락 기준 실행 (PRD 12.2 T-81, F-68, S-09-12, S-09-5, R-48, R-45, R-96,
#                ARCH 5.30 · 5.27 · 5.29)
#
#   (a) 문장이 나오는 갈래 — 15 항목. 각 항목마다 네 가지를 함께 본다:
#         · 상태 400
#         · 본문 키가 `error` **하나**뿐 (`code`·`reason` 같은 키가 새로 붙지 않았나)
#         · `error` 가 **ARCH 5.30 표의 문장과 정확히 같음** — 문장을 여기 베끼지 않고
#           `Http::reasonMessage()` 를 부른다(표가 원본이고 PHP 가 옮긴 것이라, 시험까지
#           베끼면 세 벌이 되고 한 벌만 갱신되는 자리가 생긴다)
#         · 직전 감사 행의 `reason` 이 **옛 사유 코드와 같음**(F-68 은 문장만 바꾼다)
#         · 문장에 시험이 만든 이름(`F68-…`)·다른 계정 이름이 0건
#       1 team_has_members · 2 depth_exceeded · 3 cycle · 4 parent_narrower ·
#       5 children_exceed · 6 description_too_long · 7 no_change · 8 already_exists ·
#       9 self_change · 10 ext_not_allowed(문장에 Upload::ALLOWED_EXT 원소) ·
#       11 zip_blocked_entry(**`next` 키 0**) · 12 macro_found · 13 limit_exceeded ·
#       14 already_enabled · 15 `Http::reasonMessage()` ↔ ARCH 5.30 표 글자 대조(불일치 0)
#
#   (b) 통일 문구가 지켜지는 갈래 — 옛 상태·옛 문구가 한 글자도 바뀌지 않았나 (R-48·R-45·S-09-5)
#       1 허용 키 밖 PATCH → 400 「잘못된 요청입니다」
#       2 `/api/me/password` 틀린 현재 비밀번호 → 400 같은 문구 (**1회만**, 직후 재로그인)
#       3 `/api/me/mfa/disable` 맞는 비밀번호 + 틀린 코드 → 2 와 **같은 문구**
#         (「비밀번호는 맞았나」가 갈라지지 않는다 — 1회만, 직후 재로그인)
#       4 직원이 admin-only 파일 다운로드 → 403 「권한이 없습니다」
#       5 없는 공유 토큰 → 404 「찾을 수 없습니다」
#       6 `POST /api/files/fetch` → 400 「가져올 수 없습니다」 (S-05 ⑩)
#       7 F-65 확인 업로드 틀린 비밀번호 → 400 「잘못된 요청입니다」 · `next` 0 (1회만, 직후 재로그인)
#
#   (c) 프로그램 입구 회귀(`tests/api_upload.sh`)는 **이 스크립트가 돌리지 않는다** — 보고에서 따로 돈다.
#   (d) 회귀 — `scripts/security_lint.sh` 0건 · 바뀐 PHP `php -l` · 이 파일 `bash -n`
#   (e) 정리 — 올린 파일은 앱 창구로 휴지통 · 만든 폴더 역순 삭제 · 만든 토큰 회수 ·
#       마지막에 「검토 UA 로 들어온 비-qa actor 행 0건」
#
# 규칙 (CLAUDE.md 3장)
#   · 검토 계정은 `qa_admin01`·`qa_employee01` 뿐이다. 시드 계정(admin01 …)은 사용자 몫이다.
#   · 모든 요청에 `-A "tbox-review/<날짜>"` — 감사 로그에서 사람의 행동과 갈라 보려는 것.
#   · 운영 데이터에 손대지 않는다. 만든 것은 앱 창구로만 되돌린다.
#   · 운영 DB 에 DDL·DML 을 실행하지 않는다 (psql 은 **읽기**만).
#   · 비밀번호·2단계 코드는 화면에 찍지 않는다.
#
# ★ 시험 안전 (SECURITY R-73 · R-80)
#   ① 최근 10분 감사 로그에 비-qa actor 가 있으면 시작하지 않는다 — 2분 간격으로 최대 10회 기다린다
#   ② 만드는 파일·폴더·토큰은 전부 `F68-` 접두
#   ③ 루트 파티션 여유가 1GB 미만이면 시작하지 않는다
#
# ★ 실패 카운터 (SECURITY R-48) — (b) 2·3·7 은 **일부러** 비밀번호·코드를 틀린다. 5회면 계정이
#   잠기므로 각 항목은 **1회만** 틀리고 직후 다시 로그인해 카운터를 0 으로 되돌린다.
#
# 준비물: curl · php(TOTP) · python3 · sudo -n(seed-accounts.txt · qa-totp.txt · psql)
# 사용: sudo -v && bash tests/f68.sh
set -u

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BASE="http://127.0.0.1:8082/tbox"
UA="tbox-review/$(date +%F)"
DB="tbox_re"
JAR_DIR="$(mktemp -d)"
WORK="$(mktemp -d)"
TAG="$(date +%H%M%S)"
PASS=0
FAIL=0
SKIP=0

UPLOADED=""        # 휴지통으로 되돌릴 public_id
MADE_FOLDERS=""    # folders.id (만든 순서 — 정리는 역순)
MADE_TOKENS=""     # "uid:tokenid"

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
mark()  { psqlq "SELECT coalesce(max(id),0) FROM audit_logs" 2>/dev/null; }

code_of() { printf '%s' "$1" | tail -1; }
body_of() { printf '%s' "$1" | sed '$d'; }
uuid_of() { printf '%s' "$1" | sed -n 's/.*"id":"\([0-9a-f-]\{36\}\)".*/\1/p' | head -1; }

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

# 본문의 키가 `error` 하나뿐인가 (응답 모양 불변 — `code`·`reason` 키를 더하지 않았다)
onekey() {
  printf '%s' "$1" | python3 -c '
import json,sys
try:
    d = json.load(sys.stdin)
except Exception:
    print("0"); sys.exit(0)
print("1" if isinstance(d, dict) and list(d.keys()) == ["error"] else "0")' 2>/dev/null
}

# ARCH 5.30 표의 문장 — **베끼지 않고 부른다** (Http.php 가 그 표를 옮긴 것이다).
reason_msg() {   # $1 사유 코드 → 문장
  php -r 'require $argv[1]."/src/Http.php"; require $argv[1]."/src/Upload.php"; echo Http::reasonMessage($argv[2]);' "$ROOT" "$1"
}

qa_password() { sudo -n awk -v u="$1" '$1==u{print $3}' /etc/tbox-v2/seed-accounts.txt 2>/dev/null; }
qa_secret()   { sudo -n awk -v u="$1" '$1==u{print $2}' /etc/tbox-v2/qa-totp.txt 2>/dev/null; }

totp_now() {   # $1 계정 → "코드 남은초"
  local sec raw
  sec=$(qa_secret "$1")
  [ -n "$sec" ] || return 1
  raw=$(php "$ROOT/scripts/totp_code.php" "$sec" 2>/dev/null) || return 1
  printf '%s %s\n' \
    "$(printf '%s' "$raw" | head -1)" \
    "$(printf '%s' "$raw" | sed -n 's/^남은 시간: \([0-9]*\)초.*/\1/p')"
}

# **다음 창**의 코드 — 방금 쓴 코드를 다시 쓰면 `code_reused` 가 정상이다 (R-45).
totp_fresh() {   # $1 계정 → 코드만
  local pair left
  pair=$(totp_now "$1") || return 1
  left=${pair##* }
  [ -n "$left" ] || left=31
  printf '  · %s 다음 2단계 코드 창까지 %s초 기다린다 (R-45 1회성)\n' "$1" "$left" >&2
  sleep $((left + 1))
  pair=$(totp_now "$1") || return 1
  printf '%s\n' "${pair%% *}"
}

login() {   # $1 계정 → 성공하면 표준출력에 csrf 토큰
  local who="$1" jar="$JAR_DIR/$1.jar" pw sec raw code left out try
  pw=$(qa_password "$who")
  sec=$(qa_secret "$who")
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
    printf '  · %s 로그인 재시도 — 다음 코드 창까지 %s초 기다림\n' "$who" "$left" >&2
    sleep $((left + 1))
  done
  return 1
}

reauth() {   # $1 계정  $2 csrf → HTTP 코드
  curl -s -o /dev/null -w '%{http_code}' -b "$JAR_DIR/$1.jar" -A "$UA" \
    -H 'Content-Type: application/json' -H "X-CSRF-Token: $2" \
    -d "{\"password\":\"$(qa_password "$1")\"}" "$BASE/api/me/reauth"
}

# ─── 한 항목을 네 가지로 판정한다 ────────────────────────────────
#   $1 설명  $2 사유 코드  $3 상태  $4 본문  $5 감사 action(빈 값이면 감사 확인 생략)  $6 mark
reason_case() {
  local desc="$1" code="$2" st="$3" body="$4" action="$5" m="${6:-0}" got want
  expect "$desc — 상태 400" "400" "$st"
  expect "$desc — 본문 키가 error 하나" "1" "$(onekey "$body")"
  want=$(reason_msg "$code")
  got=$(printf '%s' "$body" | jget "d.get('error','')")
  expect "$desc — 문장(ARCH 5.30 표 = Http::reasonMessage)" "$want" "$got"
  expect "$desc — 문장에 시험이 만든 이름·계정 이름 0" "0" \
    "$(printf '%s\n' "$got" | grep -c -e 'F68-' -e 'qa_admin01' -e 'qa_employee01' || true)"
  if [ -n "$action" ]; then
    if [ "$HAVE_PSQL" -eq 1 ]; then
      expect "$desc — 감사 사유 코드 불변 ($action deny)" "$code" \
        "$(psqlq "SELECT reason FROM audit_logs WHERE id > ${m:-0} AND action='$action' AND result='deny' ORDER BY id DESC LIMIT 1")"
    else skip "$desc 감사 사유" "psql 없음"; fi
  fi
}

# ─── 시작 전 관문 ────────────────────────────────────────────────
hr "F-68 400 거부의 이유 문장 — 시험 시작 전 확인"

avail_kb=$(df -Pk / | awk 'NR==2{print $4}')
echo "루트 파티션 여유: $(df -Ph / | awk 'NR==2{print $4}') (${avail_kb}KB)"
[ "$avail_kb" -ge 1048576 ] || { echo "루트 디스크 여유 1GB 미만 — 시험을 시작하지 않는다 (SECURITY R-80)"; exit 3; }

HAVE_PSQL=0
if psqlq "SELECT 1" >/dev/null 2>&1; then HAVE_PSQL=1; fi

if [ "$HAVE_PSQL" -eq 1 ]; then
  TRY=1
  while :; do
    BUSY=$(psqlq "SELECT count(*) FROM audit_logs WHERE happened_at > now() - interval '10 minutes' AND actor_name IS NOT NULL AND actor_name NOT LIKE 'qa\_%'")
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

AUDIT_START=0
[ "$HAVE_PSQL" -eq 1 ] && AUDIT_START=$(mark)

# ─── 세션 ────────────────────────────────────────────────────────
hr "준비 — 검토 계정 세션 (qa_admin01 · qa_employee01)"
ADM_JAR="$JAR_DIR/qa_admin01.jar"
EMP_JAR="$JAR_DIR/qa_employee01.jar"
ADM_CSRF=""; EMP_CSRF=""
ADM_CSRF=$(login qa_admin01)    || ADM_CSRF=""
EMP_CSRF=$(login qa_employee01) || EMP_CSRF=""
[ -n "$ADM_CSRF" ] && note "qa_admin01 로그인 OK"    || note "qa_admin01 로그인 실패 — 관련 항목 SKIP"
[ -n "$EMP_CSRF" ] && note "qa_employee01 로그인 OK" || note "qa_employee01 로그인 실패 — 관련 항목 SKIP"

# 계정 번호·팀 — 관리 창구(`GET /api/users`)로 읽는다. 운영 DB 에 쓰지 않는다.
ADM_UID=""; EMP_UID=""; ADM_TEAM=""; EMP_TEAM=""; ADM_ROLE=""
if [ -n "$ADM_CSRF" ]; then
  RC=$(reauth qa_admin01 "$ADM_CSRF"); note "qa_admin01 재인증 [$RC]"
  curl -s -b "$ADM_JAR" -A "$UA" "$BASE/api/users?per_page=100" > "$WORK/users.json"
  ADM_UID=$(jget "[u['id'] for u in d['users'] if u['username']=='qa_admin01'][0]" < "$WORK/users.json")
  EMP_UID=$(jget "[u['id'] for u in d['users'] if u['username']=='qa_employee01'][0]" < "$WORK/users.json")
  ADM_TEAM=$(jget "[u['team_id'] for u in d['users'] if u['username']=='qa_admin01'][0]" < "$WORK/users.json")
  EMP_TEAM=$(jget "[u['team_id'] for u in d['users'] if u['username']=='qa_employee01'][0]" < "$WORK/users.json")
  ADM_ROLE=$(jget "[u['role'] for u in d['users'] if u['username']=='qa_admin01'][0]" < "$WORK/users.json")
  note "qa_admin01 id=${ADM_UID:-?} role=${ADM_ROLE:-?} team=${ADM_TEAM:-없음} · qa_employee01 id=${EMP_UID:-?} team=${EMP_TEAM:-없음}"
fi

# ─── 시험 파일 ───────────────────────────────────────────────────
hr "시험용 파일 만들기 (표준 라이브러리만 쓴다)"
python3 - "$WORK" <<'PY'
import os, sys, zipfile
d = sys.argv[1]
p = lambda n: os.path.join(d, n)

open(p('F68-desc.txt'), 'w', encoding='utf-8').write('F-68 설명 시험용 파일입니다.\n')
open(p('F68-adm.txt'),  'w', encoding='utf-8').write('F-68 admin-only 시험용 파일입니다.\n')
open(p('F68-x.exe'),    'w', encoding='utf-8').write('MZ fake executable\n')
open(p('F68-ok.txt'),   'w', encoding='utf-8').write('F-68 정상 텍스트입니다.\n')

# zip 안에 x.sh — F-53 `zip_blocked_entry` 갈래 하나만 타게 한다(경로·중첩·폭탄에는 걸리지 않는다)
with zipfile.ZipFile(p('F68-sh.zip'), 'w', zipfile.ZIP_DEFLATED) as z:
    z.writestr('a.txt', 'hello f68')
    z.writestr('x.sh', '#!/bin/sh\necho 1\n')
# F-65 확인 업로드(admin-only)용 두 번째 사본
with zipfile.ZipFile(p('F68-sh2.zip'), 'w', zipfile.ZIP_DEFLATED) as z:
    z.writestr('a.txt', 'hello f68')
    z.writestr('x.sh', '#!/bin/sh\necho 1\n')

# 매크로 docx — f53.sh 의 픽스처 생성 방식 그대로
CT_DOCX = ('<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
    '<Default Extension="xml" ContentType="application/xml"/>'
    '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-'
    'officedocument.wordprocessingml.document.main+xml"/></Types>')
DOCUMENT = ('<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
    '<w:body><w:p><w:r><w:t>F-68</w:t></w:r></w:p></w:body></w:document>')
with zipfile.ZipFile(p('F68-macro.docx'), 'w', zipfile.ZIP_DEFLATED) as z:
    z.writestr('[Content_Types].xml', CT_DOCX)
    z.writestr('word/document.xml', DOCUMENT)
    z.writestr('word/vbaProject.bin', b'\xd0\xcf\x11\xe0' + b'\0' * 60)
print('made', ', '.join(sorted(os.listdir(d))))
PY

# 업로드 한 건 (웹 세션) → CODE·BODY·NEWID
CODE=""; BODY=""; NEWID=""
web_upload() {   # $1 jar  $2 csrf  $3 파일  $4 등급  [$5 폴더 id]
  local out extra=""
  [ -n "${5:-}" ] && extra="-F folder_id=$5"
  out=$(curl -s -w '\n%{http_code}' -X POST -b "$1" -A "$UA" -H "X-CSRF-Token: $2" \
        -F "file=@$3" -F "access_level=$4" $extra "$BASE/api/files")
  CODE=$(code_of "$out"); BODY=$(body_of "$out"); NEWID=$(uuid_of "$BODY")
  [ -n "$NEWID" ] && UPLOADED="$UPLOADED $NEWID"
}

mkfolder() {   # $1 jar  $2 csrf  $3 이름  $4 등급  [$5 부모 id] → OUT·FCODE·FID
  local payload
  if [ -n "${5:-}" ]; then
    payload="{\"name\":\"$3\",\"access_level\":\"$4\",\"parent_id\":$5}"
  else
    payload="{\"name\":\"$3\",\"access_level\":\"$4\"}"
  fi
  OUT=$(curl -s -w '\n%{http_code}' -X POST -b "$1" -A "$UA" \
        -H 'Content-Type: application/json' -H "X-CSRF-Token: $2" -d "$payload" "$BASE/api/folders")
  FCODE=$(code_of "$OUT")
  FID=$(body_of "$OUT" | jget "str(d['folder']['id'])")
  [ -n "$FID" ] && MADE_FOLDERS="$MADE_FOLDERS $FID"
}

# ════════════════════════════════════════════════════════════════
hr "(a) 문장이 나오는 갈래 (T-81 (a))"

# ── 1. team_has_members ─────────────────────────────────────────
sub "1. team_has_members — 활성 구성원이 있는 팀 비활성화 (PATCH /api/org/teams/{id})"
if [ -z "$ADM_CSRF" ]; then
  skip "(a)1 team_has_members" "qa_admin01 세션 없음"
elif [ -z "${EMP_TEAM:-}" ]; then
  skip "(a)1 team_has_members" "qa_employee01 에 팀이 없다 — 팀을 실제로 내릴 위험을 만들지 않는다"
else
  reauth qa_admin01 "$ADM_CSRF" >/dev/null
  curl -s -b "$ADM_JAR" -A "$UA" "$BASE/api/org/teams" > "$WORK/teams.json"
  MC=$(jget "str([t['member_count'] for t in d['teams'] if t['id']==$EMP_TEAM][0])" < "$WORK/teams.json")
  TNAME_ACTIVE=$(jget "str([t['is_active'] for t in d['teams'] if t['id']==$EMP_TEAM][0])" < "$WORK/teams.json")
  note "팀 #$EMP_TEAM member_count=${MC:-?} is_active=${TNAME_ACTIVE:-?}"
  if [ "${MC:-0}" -lt 1 ] 2>/dev/null; then
    skip "(a)1 team_has_members" "member_count 가 0 이다 — 팀을 실제로 내릴 위험을 만들지 않는다"
  elif [ "${TNAME_ACTIVE:-False}" != "True" ]; then
    skip "(a)1 team_has_members" "그 팀이 이미 비활성이다 (활성 갈래를 재현할 수 없다)"
  else
    M=$(mark)
    OUT=$(curl -s -w '\n%{http_code}' -X PATCH -b "$ADM_JAR" -A "$UA" \
          -H 'Content-Type: application/json' -H "X-CSRF-Token: $ADM_CSRF" \
          -d '{"is_active":false,"reason_text":"F68 시험 — 구성원이 있는 팀은 내려가지 않아야 한다"}' \
          "$BASE/api/org/teams/$EMP_TEAM")
    if [ "$(code_of "$OUT")" = "200" ]; then
      RB=$(curl -s -o /dev/null -w '%{http_code}' -X PATCH -b "$ADM_JAR" -A "$UA" \
           -H 'Content-Type: application/json' -H "X-CSRF-Token: $ADM_CSRF" \
           -d '{"is_active":true,"reason_text":"F68 시험 — 즉시 되돌림"}' "$BASE/api/org/teams/$EMP_TEAM")
      printf '  ✖ (a)1 팀이 실제로 내려갔다 — 즉시 되돌렸다 [%s]\n' "$RB"; FAIL=$((FAIL+1))
    else
      reason_case "(a)1 team_has_members" "team_has_members" "$(code_of "$OUT")" "$(body_of "$OUT")" "team.update" "$M"
    fi
  fi
fi

# ── 2. depth_exceeded ───────────────────────────────────────────
sub "2. depth_exceeded — F68-d1 … F68-d6 (6번째가 400)"
if [ -z "$ADM_CSRF" ]; then
  skip "(a)2 depth_exceeded" "qa_admin01 세션 없음"
else
  PARENT=""
  DEPTH_OK=1
  for i in 1 2 3 4 5; do
    mkfolder "$ADM_JAR" "$ADM_CSRF" "F68-d$i-$TAG" "internal" "$PARENT"
    if [ "$FCODE" != "201" ]; then
      note "F68-d$i 생성 실패 [$FCODE] $(body_of "$OUT")"; DEPTH_OK=0; break
    fi
    PARENT="$FID"
  done
  if [ "$DEPTH_OK" -eq 1 ]; then
    M=$(mark)
    mkfolder "$ADM_JAR" "$ADM_CSRF" "F68-d6-$TAG" "internal" "$PARENT"
    reason_case "(a)2 depth_exceeded" "depth_exceeded" "$FCODE" "$(body_of "$OUT")" "folder.create" "$M"
  else
    skip "(a)2 depth_exceeded" "1~5단계 폴더를 만들지 못했다"
  fi
fi

# ── 3. cycle ────────────────────────────────────────────────────
sub "3. cycle — F68-a 아래 F68-b 를 두고 F68-a 를 b 아래로"
A_FID=""; B_FID=""
if [ -z "$ADM_CSRF" ]; then
  skip "(a)3 cycle" "qa_admin01 세션 없음"
else
  mkfolder "$ADM_JAR" "$ADM_CSRF" "F68-a-$TAG" "internal"; A_FID="$FID"
  if [ "$FCODE" = "201" ]; then
    mkfolder "$ADM_JAR" "$ADM_CSRF" "F68-b-$TAG" "internal" "$A_FID"; B_FID="$FID"
  fi
  if [ -n "$A_FID" ] && [ -n "$B_FID" ]; then
    M=$(mark)
    OUT=$(curl -s -w '\n%{http_code}' -X PATCH -b "$ADM_JAR" -A "$UA" \
          -H 'Content-Type: application/json' -H "X-CSRF-Token: $ADM_CSRF" \
          -d "{\"parent_id\":$B_FID}" "$BASE/api/folders/$A_FID")
    reason_case "(a)3 cycle" "cycle" "$(code_of "$OUT")" "$(body_of "$OUT")" "folder.update" "$M"
  else
    skip "(a)3 cycle" "F68-a/F68-b 를 만들지 못했다"
  fi
fi

# ── 4. parent_narrower ──────────────────────────────────────────
sub "4. parent_narrower — internal 폴더 F68-p 아래 public 폴더"
P_FID=""; Q_FID=""
if [ -z "$ADM_CSRF" ]; then
  skip "(a)4 parent_narrower" "qa_admin01 세션 없음"
else
  mkfolder "$ADM_JAR" "$ADM_CSRF" "F68-p-$TAG" "internal"; P_FID="$FID"
  if [ -n "$P_FID" ]; then
    M=$(mark)
    mkfolder "$ADM_JAR" "$ADM_CSRF" "F68-c-$TAG" "public" "$P_FID"
    reason_case "(a)4 parent_narrower" "parent_narrower" "$FCODE" "$(body_of "$OUT")" "folder.create" "$M"
  else
    skip "(a)4 parent_narrower" "F68-p 를 만들지 못했다 ($FCODE)"
  fi
fi

# ── 5. children_exceed ──────────────────────────────────────────
sub "5. children_exceed — F68-p(internal) 아래 F68-q(internal) 를 두고 cascade 없이 team 으로 좁히기"
NARROW_TEAM="${ADM_TEAM:-}"
if [ -z "$NARROW_TEAM" ] && [ -f "$WORK/teams.json" ]; then
  NARROW_TEAM=$(jget "str([t['id'] for t in d['teams'] if t['is_active']][0])" < "$WORK/teams.json")
fi
if [ -z "$ADM_CSRF" ] || [ -z "$P_FID" ]; then
  skip "(a)5 children_exceed" "qa_admin01 세션 또는 F68-p 가 없다"
elif [ -z "$NARROW_TEAM" ]; then
  skip "(a)5 children_exceed" "좁힐 대상 활성 팀을 찾지 못했다"
else
  mkfolder "$ADM_JAR" "$ADM_CSRF" "F68-q-$TAG" "internal" "$P_FID"; Q_FID="$FID"
  if [ -n "$Q_FID" ]; then
    M=$(mark)
    OUT=$(curl -s -w '\n%{http_code}' -X PATCH -b "$ADM_JAR" -A "$UA" \
          -H 'Content-Type: application/json' -H "X-CSRF-Token: $ADM_CSRF" \
          -d "{\"access_level\":\"team\",\"team_id\":$NARROW_TEAM,\"cascade\":false,\"reason_text\":\"F68 시험 — cascade 없이 좁히기\"}" \
          "$BASE/api/folders/$P_FID")
    reason_case "(a)5 children_exceed" "children_exceed" "$(code_of "$OUT")" "$(body_of "$OUT")" "folder.level_change" "$M"
  else
    skip "(a)5 children_exceed" "F68-q 를 만들지 못했다 ($FCODE)"
  fi
fi

# ── 6·7. description_too_long · no_change ──────────────────────
sub "6. description_too_long — 501자 · 7. no_change — 같은 값 다시"
DESC_ID=""
if [ -z "$EMP_CSRF" ]; then
  skip "(a)6·7" "qa_employee01 세션 없음"
else
  web_upload "$EMP_JAR" "$EMP_CSRF" "$WORK/F68-desc.txt" "internal"
  if [ "$CODE" != "201" ]; then
    skip "(a)6·7" "F68-desc.txt 업로드 실패 [$CODE] $BODY"
  else
    DESC_ID="$NEWID"
    LONG=$(python3 -c "print('a'*501)")
    M=$(mark)
    OUT=$(curl -s -w '\n%{http_code}' -X PATCH -b "$EMP_JAR" -A "$UA" \
          -H 'Content-Type: application/json' -H "X-CSRF-Token: $EMP_CSRF" \
          -d "{\"description\":\"$LONG\"}" "$BASE/api/files/$DESC_ID")
    reason_case "(a)6 description_too_long" "description_too_long" "$(code_of "$OUT")" "$(body_of "$OUT")" "file.update" "$M"

    RC=$(curl -s -o /dev/null -w '%{http_code}' -X PATCH -b "$EMP_JAR" -A "$UA" \
         -H 'Content-Type: application/json' -H "X-CSRF-Token: $EMP_CSRF" \
         -d '{"description":"F68 설명"}' "$BASE/api/files/$DESC_ID")
    expect "(a)7 준비 — 설명 바꾸기 200" "200" "$RC"
    M=$(mark)
    OUT=$(curl -s -w '\n%{http_code}' -X PATCH -b "$EMP_JAR" -A "$UA" \
          -H 'Content-Type: application/json' -H "X-CSRF-Token: $EMP_CSRF" \
          -d '{"description":"F68 설명"}' "$BASE/api/files/$DESC_ID")
    reason_case "(a)7 no_change" "no_change" "$(code_of "$OUT")" "$(body_of "$OUT")" "file.update" "$M"
  fi
fi

# ── 8. already_exists ───────────────────────────────────────────
sub "8. already_exists — 있는 아이디로 계정 생성 (아무것도 만들어지지 않아야 한다)"
if [ -z "$ADM_CSRF" ]; then
  skip "(a)8 already_exists" "qa_admin01 세션 없음"
else
  reauth qa_admin01 "$ADM_CSRF" >/dev/null
  TOTAL_BEFORE=$(curl -s -b "$ADM_JAR" -A "$UA" "$BASE/api/users?per_page=1" | jget "str(d['total'])")
  M=$(mark)
  OUT=$(curl -s -w '\n%{http_code}' -X POST -b "$ADM_JAR" -A "$UA" \
        -H 'Content-Type: application/json' -H "X-CSRF-Token: $ADM_CSRF" \
        -d '{"username":"qa_employee01","role":"Employee","reason_text":"F68 시험 — 있는 아이디는 만들어지지 않아야 한다"}' \
        "$BASE/api/users")
  reason_case "(a)8 already_exists" "already_exists" "$(code_of "$OUT")" "$(body_of "$OUT")" "user.create" "$M"
  TOTAL_AFTER=$(curl -s -b "$ADM_JAR" -A "$UA" "$BASE/api/users?per_page=1" | jget "str(d['total'])")
  expect "(a)8 계정 수 불변" "${TOTAL_BEFORE:-?}" "${TOTAL_AFTER:-?}"
fi

# ── 9. self_change ──────────────────────────────────────────────
sub "9. self_change — qa_admin01 이 자기 역할 변경 (역할 불변이어야 한다)"
if [ -z "$ADM_CSRF" ] || [ -z "${ADM_UID:-}" ]; then
  skip "(a)9 self_change" "qa_admin01 세션 또는 계정 번호 없음"
else
  reauth qa_admin01 "$ADM_CSRF" >/dev/null
  M=$(mark)
  OUT=$(curl -s -w '\n%{http_code}' -X PATCH -b "$ADM_JAR" -A "$UA" \
        -H 'Content-Type: application/json' -H "X-CSRF-Token: $ADM_CSRF" \
        -d '{"role":"Manager","reason_text":"F68 시험 — 자기 자신은 바꿀 수 없어야 한다"}' \
        "$BASE/api/users/$ADM_UID")
  reason_case "(a)9 self_change" "self_change" "$(code_of "$OUT")" "$(body_of "$OUT")" "user.role_change" "$M"
  ROLE_AFTER=$(curl -s -b "$ADM_JAR" -A "$UA" "$BASE/api/users?per_page=100" \
               | jget "[u['role'] for u in d['users'] if u['username']=='qa_admin01'][0]")
  expect "(a)9 역할 불변" "${ADM_ROLE:-Admin}" "${ROLE_AFTER:-?}"
  note "last_admin 은 운영 DB 에서 재현할 수 없다 — 코드 읽기로만 확인한다 (T-81 (a) 단서)"
  skip "(a)9b last_admin" "운영 DB 에서 재현 불가 — Http::reasonMessage('last_admin') 문장은 (a)15 표 대조가 확인한다"
fi

# ── 10. ext_not_allowed ─────────────────────────────────────────
sub "10. ext_not_allowed — 웹 업로드 F68-x.exe (문장의 허용 목록은 Upload::ALLOWED_EXT 에서 만들어진다)"
if [ -z "$EMP_CSRF" ]; then
  skip "(a)10 ext_not_allowed" "qa_employee01 세션 없음"
else
  M=$(mark)
  web_upload "$EMP_JAR" "$EMP_CSRF" "$WORK/F68-x.exe" "internal"
  reason_case "(a)10 ext_not_allowed" "ext_not_allowed" "$CODE" "$BODY" "file.upload" "$M"
  EXT_FIRST=$(php -r 'require $argv[1]."/src/Upload.php"; echo Upload::ALLOWED_EXT[0];' "$ROOT")
  EXT_TXT=$(php -r 'require $argv[1]."/src/Upload.php"; echo in_array("txt", Upload::ALLOWED_EXT, true) ? "txt" : Upload::ALLOWED_EXT[0];' "$ROOT")
  contains "(a)10 문장에 ALLOWED_EXT 원소 ($EXT_FIRST)" "$EXT_FIRST" "$(printf '%s' "$BODY" | jget "d.get('error','')")"
  contains "(a)10 문장에 ALLOWED_EXT 원소 ($EXT_TXT)"   "$EXT_TXT"   "$(printf '%s' "$BODY" | jget "d.get('error','')")"
fi

# ── 11. zip_blocked_entry ───────────────────────────────────────
sub "11. zip_blocked_entry — 직원이 x.sh 든 zip(internal) · 응답에 next 키 0 (ARCH 5.29 ②)"
if [ -z "$EMP_CSRF" ]; then
  skip "(a)11 zip_blocked_entry" "qa_employee01 세션 없음"
else
  M=$(mark)
  web_upload "$EMP_JAR" "$EMP_CSRF" "$WORK/F68-sh.zip" "internal"
  reason_case "(a)11 zip_blocked_entry" "zip_blocked_entry" "$CODE" "$BODY" "file.upload" "$M"
  expect "(a)11 응답에 next 키 0 (확인 창은 열 수 있는 사람에게만)" "0" \
    "$(printf '%s' "$BODY" | grep -c 'next' || true)"
  if [ "$HAVE_PSQL" -eq 1 ]; then
    expect "(a)11 file.upload_exec 행 0건" "0" \
      "$(psqlq "SELECT count(*) FROM audit_logs WHERE id > ${M:-0} AND action='file.upload_exec'")"
  else skip "(a)11 file.upload_exec 0건" "psql 없음"; fi
fi

# ── 12. macro_found ─────────────────────────────────────────────
sub "12. macro_found — word/vbaProject.bin 이 든 docx (f53.sh 픽스처 방식)"
if [ -z "$EMP_CSRF" ]; then
  skip "(a)12 macro_found" "qa_employee01 세션 없음"
else
  M=$(mark)
  web_upload "$EMP_JAR" "$EMP_CSRF" "$WORK/F68-macro.docx" "internal"
  reason_case "(a)12 macro_found" "macro_found" "$CODE" "$BODY" "file.upload" "$M"
fi

# ── 13. limit_exceeded ──────────────────────────────────────────
sub "13. limit_exceeded — 살아 있는 개인 토큰 5개 뒤 6번째 발급"
# F-66 (2026-09-22) — 발급 창구가 **본인 창구**로 옮겨졌다(`POST /api/me/api-tokens`,
# 요청마다 비밀번호 + 2단계 코드). 코드는 1회성(R-45)이라 상한까지 채우려면 30초 창을
# 다섯 번 기다려야 하고, 그 몫은 `tests/f66.sh` (b) 가 맡는다(T-78 (b)).
# 문장 대조(`Http::reasonMessage('limit_exceeded')` ↔ 응답 `error`)도 거기서 한다.
skip "(a)13 limit_exceeded" "F-66 으로 발급이 본인 창구가 되어 tests/f66.sh (b) 로 옮겼다 — 2단계 코드 1회성 때문에 30초 창 다섯 번이 필요하다"

# ── 14. already_enabled ─────────────────────────────────────────
sub "14. already_enabled — 2단계가 켜진 qa_admin01 의 enroll (mfa_secret 불변)"
if [ -z "$ADM_CSRF" ]; then
  skip "(a)14 already_enabled" "qa_admin01 세션 없음"
else
  SEC_BEFORE=""
  [ "$HAVE_PSQL" -eq 1 ] && SEC_BEFORE=$(psqlq "SELECT md5(coalesce(mfa_secret,'')) FROM users WHERE username='qa_admin01'")
  M=$(mark)
  OUT=$(curl -s -w '\n%{http_code}' -X POST -b "$ADM_JAR" -A "$UA" \
        -H 'Content-Type: application/json' -H "X-CSRF-Token: $ADM_CSRF" -d '{}' "$BASE/api/me/mfa/enroll")
  reason_case "(a)14 already_enabled" "already_enabled" "$(code_of "$OUT")" "$(body_of "$OUT")" "auth.mfa_enroll" "$M"
  if [ "$HAVE_PSQL" -eq 1 ]; then
    SEC_AFTER=$(psqlq "SELECT md5(coalesce(mfa_secret,'')) FROM users WHERE username='qa_admin01'")
    expect "(a)14 mfa_secret 불변 (해시 비교 — 값은 찍지 않는다)" "${SEC_BEFORE:-?}" "${SEC_AFTER:-?}"
  else skip "(a)14 mfa_secret 불변" "psql 없음"; fi
fi

# ── 15. 표 대조 ─────────────────────────────────────────────────
sub "15. Http::reasonMessage() ↔ ARCHITECTURE.md 5.30 표 — 글자 대조 (불일치 0)"
CMP=$(php -r '
require $argv[1] . "/src/Http.php";
require $argv[1] . "/src/Upload.php";
$md = file($argv[1] . "/docs/tbox/ARCHITECTURE.md");
$in = false; $rows = 0; $bad = 0; $out = [];
foreach ($md as $line) {
    if (strpos($line, "### 5.30 ") === 0) { $in = true; continue; }
    if ($in && strpos($line, "### 5.31 ") === 0) { break; }
    if ($in && strpos($line, "## 6.") === 0) { break; }
    if (!$in || strpos($line, "| `") !== 0) { continue; }
    $cells = explode(" | ", rtrim($line, "\n"));
    if (count($cells) < 3) { continue; }
    $code = trim(trim(trim($cells[0]), "| "), "`");
    if (preg_match("/^([a-z_]+)/", $code, $m) !== 1) { continue; }
    $code = $m[1];
    // 예외 목록 행(`bad_request`·`bad_credentials` …)과 「기존 상수」 묶음 행은 문장 표가 아니다
    if (in_array($code, Http::REASON_UNIFORM, true)) { continue; }
    if (strpos($cells[0], "·") !== false) { continue; }
    $want = rtrim(trim($cells[2]), " |");
    $got  = Http::reasonMessage($code);
    $rows++;
    if ($code === "ext_not_allowed") {
        $pre = "허용되지 않는 확장자입니다 (허용: ";
        if (strpos($got, $pre) !== 0) { $bad++; $out[] = "PREFIX $code"; }
        continue;
    }
    if ($got !== $want) { $bad++; $out[] = "MISMATCH $code | ARCH=[$want] | PHP=[$got]"; }
}
echo $rows . " " . $bad;
foreach ($out as $o) { echo "\n" . $o; }
' "$ROOT")
CMP_ROWS=$(printf '%s' "$CMP" | head -1 | awk '{print $1}')
CMP_BAD=$(printf '%s' "$CMP" | head -1 | awk '{print $2}')
note "ARCH 5.30 문장 행 ${CMP_ROWS:-?}개 대조"
printf '%s' "$CMP" | tail -n +2 | sed 's/^/      /'
expect "(a)15 표 대조 불일치 0" "0" "${CMP_BAD:-?}"
[ "${CMP_ROWS:-0}" -ge 30 ] 2>/dev/null \
  && expect "(a)15 대조한 행이 30 이상인가 (표를 못 읽은 것이 아니다)" "yes" "yes" \
  || expect "(a)15 대조한 행이 30 이상인가 (표를 못 읽은 것이 아니다)" "yes" "no(${CMP_ROWS:-?})"

# 예외 목록 자체가 코드에 그대로 있나 (오라클 차단이 실수로 풀리지 않았나)
UNIFORM=$(php -r 'require $argv[1]."/src/Http.php"; echo implode(" ", Http::REASON_UNIFORM);' "$ROOT")
expect "(a)15 REASON_UNIFORM 목록 (ARCH 5.30 예외 목록)" \
  "bad_request bad_credentials bad_code code_reused same_password weak_password" "$UNIFORM"
for c in bad_request bad_credentials bad_code code_reused same_password weak_password; do
  expect "(a)15 $c 는 통일 문구로 떨어진다" "잘못된 요청입니다" "$(reason_msg "$c")"
done
expect "(a)15 표에 없는 코드는 통일 문구" "잘못된 요청입니다" "$(reason_msg "f68_no_such_reason")"

# ════════════════════════════════════════════════════════════════
hr "(b) 통일 문구가 지켜지는 갈래 (T-81 (b) · R-48 · R-45 · S-09-5)"

# ── 1. 허용 키 밖 ───────────────────────────────────────────────
sub "1. 허용 키 밖 PATCH → 400 「잘못된 요청입니다」"
if [ -z "$EMP_CSRF" ] || [ -z "$DESC_ID" ]; then
  skip "(b)1 허용 키 밖" "세션 또는 시험 파일 없음"
else
  OUT=$(curl -s -w '\n%{http_code}' -X PATCH -b "$EMP_JAR" -A "$UA" \
        -H 'Content-Type: application/json' -H "X-CSRF-Token: $EMP_CSRF" \
        -d '{"foo":1}' "$BASE/api/files/$DESC_ID")
  expect "(b)1 상태" "400" "$(code_of "$OUT")"
  expect "(b)1 문구(통일 — bad_request)" '{"error":"잘못된 요청입니다"}' "$(body_of "$OUT")"
fi

# ── 2. /api/me/password 틀린 현재 비밀번호 (1회만) ──────────────
sub "2. POST /api/me/password 틀린 현재 비밀번호 → 400 통일 문구 (1회만, 직후 재로그인)"
PW_MSG=""
if [ -z "$EMP_CSRF" ]; then
  skip "(b)2 비밀번호 변경 실패 문구" "qa_employee01 세션 없음"
else
  OUT=$(curl -s -w '\n%{http_code}' -X POST -b "$EMP_JAR" -A "$UA" \
        -H 'Content-Type: application/json' -H "X-CSRF-Token: $EMP_CSRF" \
        -d "{\"current\":\"wrong-current-$TAG\",\"new\":\"F68-new-password-1\"}" "$BASE/api/me/password")
  expect "(b)2 상태" "400" "$(code_of "$OUT")"
  PW_MSG=$(body_of "$OUT" | jget "d.get('error','')")
  expect "(b)2 문구(통일)" "잘못된 요청입니다" "$PW_MSG"
  note "실패 카운터를 되돌리기 위해 qa_employee01 을 다시 로그인한다 (R-48)"
  NEW=$(login qa_employee01) && EMP_CSRF="$NEW"
  if [ "$HAVE_PSQL" -eq 1 ]; then
    expect "(b)2 재로그인 뒤 failed_count 0" "0" \
      "$(psqlq "SELECT failed_count FROM users WHERE username='qa_employee01'")"
  else skip "(b)2 failed_count 0" "psql 없음"; fi
fi

# ── 3. /api/me/mfa/disable 맞는 비밀번호 + 틀린 코드 (1회만) ────
sub "3. POST /api/me/mfa/disable 맞는 비밀번호 + 틀린 코드 → 2 와 **같은 문구** (R-48 오라클 차단)"
if [ -z "$ADM_CSRF" ]; then
  skip "(b)3 mfa/disable 문구" "qa_admin01 세션 없음"
else
  OUT=$(curl -s -w '\n%{http_code}' -X POST -b "$ADM_JAR" -A "$UA" \
        -H 'Content-Type: application/json' -H "X-CSRF-Token: $ADM_CSRF" \
        -d "{\"current_password\":\"$(qa_password qa_admin01)\",\"otp\":\"000000\"}" "$BASE/api/me/mfa/disable")
  expect "(b)3 상태" "400" "$(code_of "$OUT")"
  MFA_MSG=$(body_of "$OUT" | jget "d.get('error','')")
  expect "(b)3 문구(통일)" "잘못된 요청입니다" "$MFA_MSG"
  if [ -n "$PW_MSG" ]; then
    expect "(b)3 「비밀번호가 맞았나」가 갈라지지 않는다 ((b)2 와 같은 문구)" "$PW_MSG" "$MFA_MSG"
  else skip "(b)3 (b)2 와 문구 비교" "(b)2 를 돌리지 못했다"; fi
  if [ "$HAVE_PSQL" -eq 1 ]; then
    expect "(b)3 2단계 인증이 꺼지지 않았다" "t" \
      "$(psqlq "SELECT mfa_enabled FROM users WHERE username='qa_admin01'")"
  else skip "(b)3 mfa_enabled 불변" "psql 없음"; fi
  note "실패 카운터를 되돌리기 위해 qa_admin01 을 다시 로그인한다 (R-48)"
  NEW=$(login qa_admin01) && ADM_CSRF="$NEW"
  if [ "$HAVE_PSQL" -eq 1 ]; then
    expect "(b)3 재로그인 뒤 failed_count 0" "0" \
      "$(psqlq "SELECT failed_count FROM users WHERE username='qa_admin01'")"
  else skip "(b)3 failed_count 0" "psql 없음"; fi
fi

# ── 4. 403 ─────────────────────────────────────────────────────
sub "4. 직원이 admin-only 파일 다운로드 → 403 「권한이 없습니다」"
ADM_FILE=""
if [ -z "$ADM_CSRF" ] || [ -z "$EMP_CSRF" ]; then
  skip "(b)4 403 통일 문구" "두 세션이 모두 필요하다"
else
  web_upload "$ADM_JAR" "$ADM_CSRF" "$WORK/F68-adm.txt" "admin-only"
  if [ "$CODE" != "201" ]; then
    skip "(b)4 403 통일 문구" "F68-adm.txt 업로드 실패 [$CODE] $BODY"
  else
    ADM_FILE="$NEWID"
    OUT=$(curl -s -w '\n%{http_code}' -b "$EMP_JAR" -A "$UA" "$BASE/api/files/$ADM_FILE/download")
    expect "(b)4 상태" "403" "$(code_of "$OUT")"
    expect "(b)4 문구(403 통일 — 대상 정보 0)" '{"error":"권한이 없습니다"}' "$(body_of "$OUT")"
  fi
fi

# ── 5. 404 ─────────────────────────────────────────────────────
sub "5. 없는 공유 토큰 GET /s/{43자} → 404 「찾을 수 없습니다」"
RAND=$(python3 -c "import secrets,string;print(''.join(secrets.choice(string.ascii_letters+string.digits+'-_') for _ in range(43)))")
OUT=$(curl -s -w '\n%{http_code}' -A "$UA" "$BASE/s/$RAND")
expect "(b)5 상태" "404" "$(code_of "$OUT")"
expect "(b)5 문구(404 통일 — 존재 여부 오라클 차단)" '{"error":"찾을 수 없습니다"}' "$(body_of "$OUT")"

# ── 6. URL 가져오기 ────────────────────────────────────────────
sub "6. POST /api/files/fetch → 400 「가져올 수 없습니다」 (S-05 ⑩ — 내부망 정찰 재료를 주지 않는다)"
if [ -z "$ADM_CSRF" ]; then
  skip "(b)6 fetch 통일 문구" "qa_admin01 세션 없음"
else
  OUT=$(curl -s -w '\n%{http_code}' -X POST -b "$ADM_JAR" -A "$UA" \
        -H 'Content-Type: application/json' -H "X-CSRF-Token: $ADM_CSRF" \
        -d '{"url":"http://127.0.0.1:1/x","access_level":"internal"}' "$BASE/api/files/fetch")
  expect "(b)6 상태" "400" "$(code_of "$OUT")"
  expect "(b)6 문구(통일 — 사유는 감사에만)" '{"error":"가져올 수 없습니다"}' "$(body_of "$OUT")"
fi

# ── 7. F-65 확인 업로드 틀린 비밀번호 (1회만) ──────────────────
sub "7. F-65 확인 업로드 틀린 비밀번호 → 400 「잘못된 요청입니다」 · next 0 (1회만, 직후 재로그인)"
if [ -z "$ADM_CSRF" ]; then
  skip "(b)7 F-65 확인 업로드" "qa_admin01 세션 없음"
else
  OUT=$(curl -s -w '\n%{http_code}' -X POST -b "$ADM_JAR" -A "$UA" -H "X-CSRF-Token: $ADM_CSRF" \
        -F "file=@$WORK/F68-sh2.zip" -F 'access_level=admin-only' -F 'exec_ack=1' \
        -F "password=wrong-password-$TAG" -F 'otp=000000' "$BASE/api/files")
  expect "(b)7 상태" "400" "$(code_of "$OUT")"
  expect "(b)7 문구(통일 — bad_credentials, R-48 오라클 차단)" "잘못된 요청입니다" \
    "$(body_of "$OUT" | jget "d.get('error','')")"
  expect "(b)7 응답에 next 키 0 (T-77 (b) 그대로)" "0" "$(body_of "$OUT" | grep -c 'next' || true)"
  if [ "$HAVE_PSQL" -eq 1 ]; then
    expect "(b)7 감사 file.upload_exec deny bad_credentials 1건" "1" \
      "$(psqlq "SELECT count(*) FROM audit_logs WHERE id > ${AUDIT_START:-0} AND action='file.upload_exec' AND result='deny' AND reason='bad_credentials'")"
  else skip "(b)7 감사 확인" "psql 없음"; fi
  note "실패 카운터를 되돌리기 위해 qa_admin01 을 다시 로그인한다 (R-48)"
  NEW=$(login qa_admin01) && ADM_CSRF="$NEW"
  if [ "$HAVE_PSQL" -eq 1 ]; then
    expect "(b)7 재로그인 뒤 failed_count 0" "0" \
      "$(psqlq "SELECT failed_count FROM users WHERE username='qa_admin01'")"
  else skip "(b)7 failed_count 0" "psql 없음"; fi
fi

# ════════════════════════════════════════════════════════════════
hr "(d) 회귀 — 린트 · php -l · bash -n"

LINT_OUT="$WORK/lint.txt"
bash "$ROOT/scripts/security_lint.sh" > "$LINT_OUT" 2>&1
LINT_RC=$?
tail -3 "$LINT_OUT" | sed 's/^/    /'
expect "security_lint.sh 0건 (종료 코드)" "0" "$LINT_RC"

LERR=0
for f in "$ROOT/src/Http.php" \
         "$ROOT/src/handlers/org_team_update.php" "$ROOT/src/handlers/folders_create.php" \
         "$ROOT/src/handlers/folders_update.php"  "$ROOT/src/handlers/files_update.php" \
         "$ROOT/src/handlers/users_update.php"    "$ROOT/src/handlers/users_create.php" \
         "$ROOT/src/handlers/users_mfa_reset.php" "$ROOT/src/handlers/users_password_reset.php" \
         "$ROOT/src/handlers/me_tokens_list.php"  "$ROOT/src/handlers/me_tokens_issue.php" \
         "$ROOT/src/handlers/me_tokens_revoke.php" "$ROOT/src/handlers/admin_permissions_update.php" \
         "$ROOT/src/handlers/fetch_hosts_update.php" "$ROOT/src/handlers/mfa_enroll.php" \
         "$ROOT/src/handlers/share_create.php"    "$ROOT/src/handlers/files_upload.php" \
         "$ROOT/src/handlers/api_upload.php"; do
  if php -l "$f" >/dev/null 2>&1; then printf '    ✔ %s\n' "$f"; else printf '    ✖ %s\n' "$f"; LERR=$((LERR+1)); fi
done
expect "바뀐 PHP 문법 오류 0" "0" "$LERR"

bash -n "$0" && expect "이 시험 파일 bash -n" "0" "0" || expect "이 시험 파일 bash -n" "0" "1"

expect "라이브가 여전히 정상인가 — GET /api/health" "200" \
  "$(curl -s -o /dev/null -w '%{http_code}' -A "$UA" "$BASE/api/health")"

# ════════════════════════════════════════════════════════════════
hr "(e) 정리 — 만든 것만 되돌린다"

for id in $UPLOADED; do
  RC=$(curl -s -o /dev/null -w '%{http_code}' -X DELETE -b "$EMP_JAR" -A "$UA" \
       -H "X-CSRF-Token: $EMP_CSRF" "$BASE/api/files/$id")
  if [ "$RC" != "200" ] && [ -n "$ADM_CSRF" ]; then
    RC=$(curl -s -o /dev/null -w '%{http_code}' -X DELETE -b "$ADM_JAR" -A "$UA" \
         -H "X-CSRF-Token: $ADM_CSRF" "$BASE/api/files/$id")
  fi
  printf '  · 파일 %s 휴지통 [%s]\n' "$id" "$RC"
done

if [ -n "$MADE_TOKENS" ] && [ -n "$ADM_CSRF" ]; then
  reauth qa_admin01 "$ADM_CSRF" >/dev/null 2>&1
  for t in $MADE_TOKENS; do
    uid=${t%%:*}; tid=${t##*:}
    RC=$(curl -s -o /dev/null -w '%{http_code}' -X DELETE -b "$ADM_JAR" -A "$UA" \
         -H "X-CSRF-Token: $ADM_CSRF" "$BASE/api/users/$uid/api-tokens/$tid")
    printf '  · 토큰 #%s 회수 [%s]\n' "$tid" "$RC"
  done
fi

# 폴더는 **역순**으로 지운다 (자식이 먼저 사라져야 부모가 지워진다)
REV=""
for n in $MADE_FOLDERS; do REV="$n $REV"; done
for n in $REV; do
  RC=$(curl -s -o /dev/null -w '%{http_code}' -X DELETE -b "$ADM_JAR" -A "$UA" \
       -H "X-CSRF-Token: $ADM_CSRF" "$BASE/api/folders/$n?move_trashed=1")
  printf '  · 폴더 %s 삭제 [%s]\n' "$n" "$RC"
done

if [ "$HAVE_PSQL" -eq 1 ]; then
  # 「사용자 데이터 불변」은 개수가 아니라 **검토 UA 기준**으로 본다 (f65.sh (h) 방식).
  # 사용자가 같은 시간에 t-box 를 쓰면 비-qa 행은 늘어난다 — 그것은 시험의 탓이 아니다.
  # 물어야 할 것은 「이 시험의 요청(검토 UA)이 비-qa 계정으로 무엇을 했나」이고 그 답은 0 이어야 한다.
  expect "시험 요청이 만든 비-qa 행 0건 (검토 UA 기준 — 사용자 데이터 불변)" "0" \
    "$(psqlq "SELECT count(*) FROM audit_logs
                WHERE id > ${AUDIT_START:-0}
                  AND actor_name IS NOT NULL AND actor_name NOT LIKE 'qa\_%'
                  AND COALESCE(user_agent, '') LIKE 'tbox-review/%'")"
  note "시험이 만든 감사 행(전체): $(psqlq "SELECT count(*) FROM audit_logs WHERE id > ${AUDIT_START:-0}")건"
else
  skip "비-qa 행 0건" "psql 없음"
fi

# ─── 마무리 ─────────────────────────────────────────────────────
hr "결과"
printf '통과 %d · 실패 %d · 건너뜀/미검증 %d\n' "$PASS" "$FAIL" "$SKIP"
echo "작업 폴더 $WORK 는 종료와 함께 지워진다."
[ "$FAIL" -eq 0 ] || exit 1
exit 0
