#!/usr/bin/env bash
# tests/community/stage3.sh — 커뮤니티 3단계 수락 기준 C:T-14 ~ C:T-20 실행
#                             (../../../community/ARCHITECTURE.md 21.4, stage3-directive.md)
#
#   C:T-14 스키마 퍼징     모르는 키·모르는 타입·4단계 블록(table)·색 팔레트 밖·링크 스킴
#                          `javascript:`·깊이 11·블록 3,001·세그먼트 2,001자·문서 1MB+·id 중복·
#                          `m` 누락·제목 단계 4 → 전부 400 + `errors[].path` · 감사
#                          `page.update deny invalid_doc` · DB 불변(판 번호가 오르지 않는다)
#   C:T-15 렌더 XSS        세그먼트 `<img src=x onerror=…>` · 링크 `"><script>` · 코드 `</script>`
#                          → 창구 JSON 은 **원문 그대로**, `DocRender.php` 출력에 `<img`·`<script` 0
#   C:T-16 낙관적 잠금     같은 `doc_version` 으로 두 번 PATCH → 둘째 409 `{error,current_version,doc}`
#                          + 감사 `version_conflict` · 첫째 저장 유지
#   C:T-17 개인 공간 격리  claude_employee02 가 employee01 페이지 GET·PATCH·DELETE → 403 `not_owner` ·
#                          claude_manager01(같은 팀) GET → 403 · claude_admin01 GET → 200 + 감사 `admin_all`
#   C:T-18 쓰기 자격·CSRF  claude_guest01 `POST /api/pages` → 403 `authz.route deny` ·
#                          쿠키만 보내고 `X-CSRF-Token` 없이 PATCH → 403 `csrf`
#   C:T-19 렌더러 동등성   표본 문서 24개(3단계 20 + 7-D4 글 안 멘션 4)를 `DocRender.php` 와 `doc-render.js` 로 그려
#                          **텍스트 노드 열·클래스 집합**을 견준다. PHP 쪽은 여기서, JS 쪽은
#                          아래 C:T-21 의 브라우저 안에서 잰다(Playwright 없으면 JS 쪽 미검증)
#   C:T-21 브라우저 스모크 **진짜 브라우저로** 편집기를 눌러 보는 20항목(2026-09-23 전면 재작성,
#                          `../../../community/stage3b-editor-rework-directive.md` 「완료 기준」).
#                          목록·번호·할 일의 Enter · 빈 항목 Enter · 제목 뒤 Enter · 맨 앞 Backspace ·
#                          「블록 추가」 창(10종 이상, 하나씩 전부 넣기) · 블록 손잡이로 바꾸기 ·
#                          글자색·배경색·링크(입력칸 Enter, `javascript:` 거절) · `/` 는 글자 ·
#                          툴바 한 줄·단추 셋·폭 · **빠른 타자(delay=0) 200자 유실 0** ·
#                          **저장 중 이어친 20자 유실 0** · 삭제까지. 단언은 전부 「행동 → 결과」이고
#                          결과는 DOM 또는 **저장 JSON** 으로 본다.
#                          (관문 ③ 2026-09-22: JS 가 첫 줄에서 죽어 화면 전체가 멈춰 있었는데
#                           curl 시험은 전부 통과였다 — 그 구멍을 메우는 자리다.
#                           2026-09-23: 「요소가 보인다」만 보던 단언으로는 목록 Enter·링크 Enter가
#                           안 되는 것을 잡지 못했다 — 그래서 전부 결과로 바꿨다)
#   C:T-20 vendoring 무결성 `scripts/vendor_prosemirror.sh --verify` · bare import 0 ·
#                          `editor.js` 에 절대 경로 리터럴 0
#   회귀                   `scripts/security_lint.sh` 0건 · 바뀐 PHP `php -l` · 이 파일 `bash -n`
#
# 규칙 (CLAUDE.md 3장)
#   · 검토 계정은 `claude_employee01`·`claude_employee02`·`claude_manager01`·`claude_admin01`·`claude_guest01` 뿐이다.
#     시드 계정(admin01 …)은 사용자 몫이다.
#   · 모든 요청에 `-A "tbox-review/<날짜>"` — 감사 로그에서 사람의 행동과 갈라 보려는 것.
#   · 운영 데이터에 손대지 않는다. 만든 페이지는 **앱 창구로만** 되돌린다(논리 삭제).
#   · 운영 DB 에 DDL·DML 을 실행하지 않는다 (psql 은 **읽기**만).
#
# ★ 시험 안전 (SECURITY R-73 · R-80): 최근 10분에 비-qa 감사 행이 있으면 기다리고,
#   루트 파티션 여유가 1GB 미만이면 시작하지 않는다 (f68.sh · q_launcher.sh 와 같은 관문).
#
# 준비물: curl · php · python3 · sudo -n(seed-accounts.txt · qa-totp.txt · psql)
# 사용: sudo -v && bash tests/community/stage3.sh
#   ※ **먼저 마이그레이션이 적용돼 있어야 한다** (db/migrations/2026-09-22_community_stage3.sql).
#     적용 전에는 모든 창구가 503 「커뮤니티는 아직 준비 중입니다」 이고 이 시험은 시작하지 않는다.
set -u

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
# 검토 계정 판정 한 벌(qa_·claude_ — F-71 C). 못 읽으면 사전 검사가 빈 값으로 열리므로 시작하지 않는다.
. "$ROOT/tests/lib/review_accounts.sh" || { echo "tests/lib/review_accounts.sh 를 읽지 못했다 — 시험을 시작하지 않는다 (R-73)"; exit 3; }
. "$ROOT/tests/lib/secrets.sh" || { echo "tests/lib/secrets.sh 를 읽지 못했다 — 시험을 시작하지 않는다 (R-62)"; exit 3; }
. "$ROOT/tests/lib/db_read.sh" || { echo "tests/lib/db_read.sh 를 읽지 못했다 — 시험을 시작하지 않는다 (R-73)"; exit 3; }
SITE="http://127.0.0.1:8082"
BASE="$SITE/tbox"                 # t-box 모듈 (로그인·CSRF 토큰을 받는 자리)
CBASE="$SITE/community"           # 커뮤니티 모듈
UA="tbox-review/$(date +%F)"
DB="tbox_re"
JAR_DIR="$(mktemp -d)"
WORK="$(mktemp -d)"
PASS=0
FAIL=0
SKIP=0

MADE_PAGES=""     # 정리할 페이지 public_id (만든 순서)
SKIP_REST=0       # 1 이면 판 번호를 잃은 것 — 뒤 항목을 건너뛴다(연쇄 오탐 방지)

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

# 응답 머리의 **마지막** 상태 줄을 읽는다. curl 이 큰 본문에 `Expect: 100-continue` 를 보내면
# 머리가 `HTTP/1.1 100 Continue` 로 시작해 첫 줄을 읽던 예전 방식이 `100` 을 돌려줬다
# (2026-09-22 관문 ② 실측 — fuzz_09 가 400 대신 100 으로 보였다). capi() 는 그 헤더 자체를
# 끄고(`-H 'Expect:'`), 여기서는 그래도 마지막 줄을 읽어 두 겹으로 막는다.
status_of() { sed -n 's#^HTTP/[0-9.]* \([0-9]*\).*#\1#p' "$1" | tail -1 | tr -d '\r'; }

qa_password() { sudo -n awk -v u="$1" '$1==u{print $3}' /etc/tbox-v2/seed-accounts.txt 2>/dev/null; }
qa_secret()   { sudo -n awk -v u="$1" '$1==u{print $2}' /etc/tbox-v2/qa-totp.txt 2>/dev/null; }

login() {   # $1 계정 → 성공하면 표준출력에 csrf 토큰
  local who="$1" jar="$JAR_DIR/$1.jar" pw sec raw code left out try body
  pw=$(qa_password "$who")
  sec=$(qa_secret "$who")
  [ -n "$pw" ] || return 2
  for try in 1 2 3; do
    if [ -n "$sec" ]; then
      raw=$(totp_now sec 2>/dev/null)
      code=${raw%%$'\n'*}
      printf -v body '{"username":"%s","password":"%s","otp":"%s"}' "$who" "$pw" "$code"
      out=$(curl_json_secret body -s -c "$jar" -A "$UA" -H 'Content-Type: application/json' "$BASE/api/login")
    else
      printf -v body '{"username":"%s","password":"%s"}' "$who" "$pw"
      out=$(curl_json_secret body -s -c "$jar" -A "$UA" -H 'Content-Type: application/json' "$BASE/api/login")
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

# 커뮤니티 창구 호출 — $1 메서드 $2 경로 $3 계정 $4 본문(없으면 생략)
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

# ════════════════════════════════════════════════════════════════
hr "커뮤니티 3단계 — 시험 시작 전 확인"

avail_kb=$(df -Pk / | awk 'NR==2{print $4}')
echo "루트 파티션 여유: $(df -Ph / | awk 'NR==2{print $4}') (${avail_kb}KB)"
[ "$avail_kb" -ge 1048576 ] || { echo "루트 디스크 여유 1GB 미만 — 시험을 시작하지 않는다 (SECURITY R-80)"; exit 3; }

HAVE_PSQL=0
if psqlq "SELECT 1" >/dev/null 2>&1; then HAVE_PSQL=1; fi

# 스키마가 적용됐나 — 적용 전이면 모든 창구가 503 이므로 여기서 멈춘다.
READY=$(curl -s -o /dev/null -w '%{http_code}' -A "$UA" "$CBASE/api/pages")
if [ "$READY" = "503" ]; then
  echo "커뮤니티 스키마가 아직 적용되지 않았다 (창구가 503) — 마이그레이션을 먼저 돌린다:"
  echo "  PGPASSFILE=/etc/tbox-v2/owner.pgpass psql -h 127.0.0.1 -U tbox_owner -d tbox_re \\"
  echo "    -v ON_ERROR_STOP=1 -f db/migrations/2026-09-22_community_stage3.sql"
  echo "  sudo -u postgres psql -d tbox_re -v ON_ERROR_STOP=1 -f db/community/grants.sql"
  exit 3
fi

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

AUDIT_START=0
# 끝의 「시험이 만든 비-qa 행 0건」 대조의 시작 값 — 읽지 못하면 시작하지 않는다(R-73 · db_read)
if [ "$HAVE_PSQL" -eq 1 ]; then
  db_read_var AUDIT_START "$DB" "SELECT coalesce(max(id),0) FROM audit_logs" \
    || { echo "운영 DB 를 읽지 못했다 — 시험을 시작하지 않는다 (R-73 · db_read rc 3)"; exit 3; }
fi

sub "검토 계정 로그인"
for who in claude_employee01 claude_employee02 claude_manager01 claude_admin01 claude_guest01; do
  token=$(login "$who") || token=""
  eval "CSRF_${who}=\"\$token\""
  if [ -n "$token" ]; then note "$who 로그인 OK"; else note "$who 로그인 실패 — 관련 항목 SKIP"; fi
done

# 개인 공간을 연다(첫 방문이면 서버가 「시작하기」 페이지와 함께 만든다)
sub "개인 공간 열기 — GET /community/api/me/space"
CODE=$(capi GET /api/me/space claude_employee01)
expect "claude_employee01 개인 공간" "200" "$CODE"
note "created=$(jget "d['created']" < "$WORK/b") page_count=$(jget "d['space']['page_count']" < "$WORK/b")"
CODE=$(capi GET /api/me/space claude_employee02)
expect "claude_employee02 개인 공간" "200" "$CODE"

# 시험용 페이지 하나 (employee01 소유)
printf '{"title":"C3-시험 페이지"}' > "$WORK/new.json"
CODE=$(capi POST /api/pages claude_employee01 "$WORK/new.json")
expect "페이지 만들기 201" "201" "$CODE"
PAGE=$(jget "d['page']['id']" < "$WORK/b")
VER=$(jget "d['page']['doc_version']" < "$WORK/b")
[ -n "$PAGE" ] && MADE_PAGES="$MADE_PAGES $PAGE"
note "페이지 id=$PAGE doc_version=$VER"

# ════════════════════════════════════════════════════════════════
hr "C:T-14 — 스키마 퍼징 (모르는 것은 400)"

# 12가지 악성·어긋난 본문을 python 이 만든다(큰 문서는 셸로 만들 수 없다).
python3 - "$WORK" "$VER" <<'PY'
import json, os, sys, uuid
work, ver = sys.argv[1], int(sys.argv[2])
def u(): return str(uuid.uuid4())
def seg(t, m=None): return [{"t": t, "m": m or {}}]
def good(): return {"schema": 1, "blocks": [{"id": u(), "type": "paragraph", "text": seg("정상")}]}

cases = {}
c = good(); c["blocks"][0]["evil"] = "x";                      cases["01_unknown_key"] = c
c = good(); c["blocks"][0] = {"id": u(), "type": "script", "text": seg("x")}; cases["02_unknown_type"] = c
c = good(); c["blocks"][0] = {"id": u(), "type": "table", "header_row": True, "header_col": False, "rows": []}; cases["03_stage4_table"] = c
c = good(); c["blocks"][0]["text"] = [{"t": "x", "m": {"color": "neon"}}]; cases["04_color_outside"] = c
c = good(); c["blocks"][0]["text"] = [{"t": "x", "m": {"link": {"href": "javascript:alert(1)"}}}]; cases["05_scheme_js"] = c
# 「깊이 11」 = 블록이 **열한 겹**이다(스키마 상한 10 보다 한 겹 깊다).
# 감싸는 것 10 + 맨 안쪽 1 = 11 — 전에 11번 감싸 열두 겹을 만들었더니 입구의 깊이 상한에
# 먼저 걸려 검증기의 `too_deep` 을 보지 못했다(2026-09-22 실측).
deep = {"id": u(), "type": "paragraph", "text": seg("바닥")}
for _ in range(10): deep = {"id": u(), "type": "paragraph", "text": seg("층"), "children": [deep]}
cases["06_depth_11"] = {"schema": 1, "blocks": [deep]}
cases["07_blocks_3001"] = {"schema": 1, "blocks": [{"id": u(), "type": "paragraph", "text": []} for _ in range(3001)]}
c = good(); c["blocks"][0]["text"] = [{"t": "가" * 2001, "m": {}}]; cases["08_segment_2001"] = c
# 09 — **문서 스키마의 상한**(doc_bytes 1MB)을 넘기되 **입구 상한**(Http::jsonBody 의
# doc_bytes+64KB)은 넘지 않게 만든다. 그래야 검증기까지 닿아 `too_large` 로 거부되고
# 감사 `invalid_doc` 이 남는다. 더 키우면 입구에서 잘려(400, 감사 없음) 다른 것을 재게 된다.
big = {"schema": 1, "blocks": []}
def _size(d): return len(json.dumps(d, ensure_ascii=False, separators=(",", ":")).encode("utf-8"))
while _size(big) <= 1048576:
    big["blocks"].append({"id": u(), "type": "paragraph", "text": [{"t": "a" * 1999, "m": {}}]})
for _ in range(3):
    big["blocks"].append({"id": u(), "type": "paragraph", "text": [{"t": "a" * 1999, "m": {}}]})
assert 1048576 < _size(big) < 1110000, _size(big)
cases["09_doc_over_1mb"] = big
same = u()
cases["10_dup_id"] = {"schema": 1, "blocks": [
    {"id": same, "type": "paragraph", "text": seg("하나")},
    {"id": same, "type": "paragraph", "text": seg("둘")}]}
cases["11_missing_m"] = {"schema": 1, "blocks": [{"id": u(), "type": "paragraph", "text": [{"t": "마크 없음"}]}]}
cases["12_heading_level_4"] = {"schema": 1, "blocks": [{"id": u(), "type": "heading", "level": 4, "text": seg("큰 제목")}]}

for name, doc in cases.items():
    with open(os.path.join(work, "fuzz_" + name + ".json"), "w", encoding="utf-8") as f:
        json.dump({"doc_version": ver, "doc": doc}, f, ensure_ascii=False, separators=(",", ":"))

# ─ 함께 만드는 「정상 200KB 문서」 — 스키마 한도 안이고 옛 입구 상한(64KB)은 넘는 크기.
#   관문 ② 에서 드러난 「스키마는 받아 주는데 입구가 막는」 모순이 없어졌는지 보는 자리다.
ok200 = {"schema": 1, "blocks": []}
while len(json.dumps(ok200, ensure_ascii=False, separators=(",", ":")).encode("utf-8")) < 200000:
    ok200["blocks"].append({"id": u(), "type": "paragraph", "text": [{"t": "긴 노트 " * 120, "m": {}}]})
with open(os.path.join(work, "ok_200kb.json"), "w", encoding="utf-8") as f:
    json.dump({"doc_version": ver, "doc": ok200}, f, ensure_ascii=False, separators=(",", ":"))
print("만든 악성 입력:", ", ".join(sorted(cases)))
print("정상 200KB 문서 블록 수:", len(ok200["blocks"]),
      "· 바이트:", len(json.dumps(ok200, ensure_ascii=False, separators=(",", ":")).encode("utf-8")))
PY

# 400 에는 두 갈래가 있다 — 둘 다 「거부」이고 둘 다 맞다.
#   ① **검증기에 닿은 것**   응답에 `errors[]`(자리·사유)가 있고 감사 `page.update deny invalid_doc` 이 남는다
#   ② **입구에서 잘린 것**   `Http::jsonBody()` 의 바이트·깊이 상한에 먼저 걸린다. 응답은 통일 문구
#                            하나뿐이고 **감사가 없다** — 형식 오류를 감사에 남기지 않는 t-box 규칙 그대로다.
# 그래서 감사 건수의 기대값은 12 가 아니라 **①의 개수**이고, 그 수를 여기서 센다.
VALIDATED=0
EARLY=""
for f in "$WORK"/fuzz_*.json; do
  name=$(basename "$f" .json)
  CODE=$(capi PATCH "/api/pages/$PAGE" claude_employee01 "$f")
  path=$(jget "d['errors'][0]['path']" < "$WORK/b")
  reason=$(jget "d['errors'][0]['reason']" < "$WORK/b")
  expect "$name → 400" "400" "$CODE"
  if [ -n "$reason" ]; then
    VALIDATED=$((VALIDATED+1))
    printf '  ✔ %s 검증기까지 닿음 — errors[0] = %s : %s\n' "$name" "${path:-(문서 전체)}" "$reason"; PASS=$((PASS+1))
  else
    EARLY="$EARLY $name"
    printf '  ✔ %s 입구(Http::jsonBody 의 바이트·깊이 상한)에서 잘림 — 본문: %s\n' \
      "$name" "$(head -c 60 "$WORK/b")"; PASS=$((PASS+1))
  fi
done
note "검증기까지 닿은 케이스: ${VALIDATED}개"
note "입구에서 잘린 케이스:${EARLY:- (없음)}"

sub "200KB 정상 문서 저장 — 스키마 한도 안이면 들어간다 (관문 ② 보정 2026-09-22)"
# 전에는 `Http::jsonBody()` 의 64KB 상한이 먼저 막아, 스키마가 허용하는 크기의 문서도 저장이
# 되지 않았다. 지금은 본문 저장 창구 둘만 「스키마 상한 + 껍데기 여유」를 쓴다.
CODE=$(capi PATCH "/api/pages/$PAGE" claude_employee01 "$WORK/ok_200kb.json")
expect "200KB 문서 PATCH 200" "200" "$CODE"
BIGVER=$(jget "d['doc_version']" < "$WORK/b")
if [ -n "$BIGVER" ]; then
  expect "판 번호가 하나 올랐다" "$((VER + 1))" "$BIGVER"
  VER="$BIGVER"
  note "블록 수: $(jget "d['block_count']" < "$WORK/b")"
else
  printf '  ✖ 200KB 저장 응답에 doc_version 이 없다 — 본문: %s\n' "$(head -c 200 "$WORK/b")"; FAIL=$((FAIL+1))
fi

sub "DB 불변 — 퍼징이 판 번호를 올리지 않았다"
CODE=$(capi GET "/api/pages/$PAGE" claude_employee01)
NOWVER=$(jget "d['page']['doc_version']" < "$WORK/b")
expect "doc_version 이 저장 한 번(200KB)만큼만 올랐다" "$VER" "$NOWVER"

if [ "$HAVE_PSQL" -eq 1 ]; then
  N=$(psqlq "SELECT count(*) FROM audit_logs WHERE id > ${AUDIT_START:-0} AND action='page.update' AND result='deny' AND reason='invalid_doc'")
  expect "감사 page.update deny invalid_doc = 검증기까지 닿은 수" "$VALIDATED" "${N:-0}"
  note "문장 예: $(psqlq "SELECT summary FROM audit_logs WHERE action='page.update' AND reason='invalid_doc' ORDER BY id DESC LIMIT 1")"
  note "detail 예: $(psqlq "SELECT detail->>'error_path' FROM audit_logs WHERE action='page.update' AND reason='invalid_doc' ORDER BY id DESC LIMIT 1")"
else
  skip "감사 invalid_doc" "psql 없음"
fi

# ════════════════════════════════════════════════════════════════
hr "C:T-15 — 렌더 XSS"

# 시험 문서는 **스키마상 유효**해야 한다. 저장이 되어야 「창구가 글자를 그대로 돌려주는가」를
# 볼 수 있기 때문이다 (2026-09-22 관문 ②: 못 쓰는 링크 하나 때문에 저장이 400 이 되고,
# 그 뒤 판 번호를 잃어 C:T-16·C:T-17 이 통째로 무너졌다).
#   · 페이로드는 **세그먼트 텍스트 · 제목 텍스트 · 코드 블록 본문**에만 넣는다.
#   · 링크는 정상 주소(https://example.com/)를 쓴다.
#   · 허용 목록 밖 href 는 아래 「400 + bad_scheme」 항목에서 따로 본다 — 그것은 **검증기**의
#     일이고, 이 항목이 보는 것은 **렌더러**다.
python3 - "$WORK" "$VER" <<'XSSPY'
import json, os, sys, uuid
work, ver = sys.argv[1], int(sys.argv[2])
def u(): return str(uuid.uuid4())
doc = {"schema": 1, "blocks": [
    {"id": u(), "type": "paragraph",
     "text": [{"t": "<img src=x onerror=alert(1)>", "m": {}}]},
    {"id": u(), "type": "heading", "level": 2,
     "text": [{"t": "</h2><script>alert(2)</script>", "m": {"bold": True}}]},
    {"id": u(), "type": "paragraph",
     "text": [{"t": chr(34) + "><script>alert(3)</script>",
               "m": {"link": {"href": "https://example.com/"}}}]},
    {"id": u(), "type": "code",
     "code": "</script><script>alert(4)</script>", "language": "html"},
    {"id": u(), "type": "callout", "icon": "\u26a0",
     "text": [{"t": "<svg onload=alert(5)>", "m": {"color": "red"}}]},
]}
with open(os.path.join(work, "xss.json"), "w", encoding="utf-8") as f:
    json.dump({"doc_version": ver, "doc": doc}, f, ensure_ascii=False)
with open(os.path.join(work, "xss_doc.json"), "w", encoding="utf-8") as f:
    json.dump(doc, f, ensure_ascii=False)
XSSPY

CODE=$(capi PATCH "/api/pages/$PAGE" claude_employee01 "$WORK/xss.json")
expect "XSS 페이로드 저장 200 (형식은 맞다 — 글자일 뿐이다)" "200" "$CODE"
NEWVER=$(jget "d['doc_version']" < "$WORK/b")
if [ -n "$NEWVER" ]; then
  VER="$NEWVER"
  note "판 번호 $VER"
else
  # 판 번호를 잃으면 뒤 항목의 PATCH 가 전부 400 이 되어 **시험이 앱을 잘못 고발한다**.
  # 여기서 바로 실패로 찍고 뒤를 건너뛴다.
  printf '  ✖ 저장 응답에 doc_version 이 없다 — 뒤 항목을 건너뛴다 (본문: %s)\n' "$(head -c 200 "$WORK/b")"
  FAIL=$((FAIL+1)); SKIP_REST=1
fi

CODE=$(capi GET "/api/pages/$PAGE" claude_employee01)
expect "GET 200" "200" "$CODE"
# 창구는 **글자를 그대로** 돌려준다(HTML 로 바꾸지 않는다). 그리는 일은 렌더러의 몫이고,
# 렌더러가 안전한지는 바로 아래에서 본다.
expect "응답이 JSON 이다" "application/json; charset=utf-8" \
  "$(sed -n 's/^[Cc]ontent-[Tt]ype: //p' "$WORK/h" | tr -d '\r' | head -1)"
expect "본문에 페이로드 글자가 그대로 있다" "yes" \
  "$(python3 -c "
import json,sys
try:
    d=json.load(open(sys.argv[1],encoding='utf-8'))
    t=d['page']['doc']['blocks'][0]['text'][0]['t']
except Exception as e:
    print('no:'+str(e)); raise SystemExit
print('yes' if t=='<img src=x onerror=alert(1)>' else 'no:'+t)" "$WORK/b")"
note "본문 앞부분: $(head -c 160 "$WORK/b")"

sub "DocRender.php 출력 — 태그가 하나도 살아남지 않는다"
ROOT="$ROOT" WORK="$WORK" php -r '
require getenv("ROOT") . "/src/community/Doc.php";
require getenv("ROOT") . "/src/community/DocRender.php";
$doc = json_decode(file_get_contents(getenv("WORK") . "/xss_doc.json"), true);
$v = Doc::validate($doc, 3);
$html = DocRender::html($doc);
file_put_contents(getenv("WORK") . "/render.html", $html);
printf("VALID=%s\nIMG=%d\nSCRIPT=%d\nSVG=%d\nA=%d\n",
    $v["ok"] ? "yes" : "no", substr_count($html, "<img"), substr_count($html, "<script"),
    substr_count($html, "<svg"), preg_match_all("/<a class=/", $html));
' > "$WORK/render.txt" 2>&1
cat "$WORK/render.txt"
expect "시험 문서가 스키마를 지난다" "VALID=yes" "$(grep '^VALID=' "$WORK/render.txt")"
expect "출력에 '<img' 0건" "IMG=0" "$(grep '^IMG=' "$WORK/render.txt")"
expect "출력에 '<script' 0건" "SCRIPT=0" "$(grep '^SCRIPT=' "$WORK/render.txt")"
expect "출력에 '<svg' 0건" "SVG=0" "$(grep '^SVG=' "$WORK/render.txt")"
expect "정상 링크 하나만 <a> 가 된다" "A=1" "$(grep '^A=' "$WORK/render.txt")"
note "출력 원문: $(cat "$WORK/render.html")"

sub "허용 목록 밖 링크는 **저장되지 않는다** (400 + bad_scheme)"
python3 - "$WORK" "$VER" <<'BADPY'
import json, os, sys, uuid
work, ver = sys.argv[1], int(sys.argv[2])
def u(): return str(uuid.uuid4())
hrefs = {
    "badlink_quote": chr(34) + "><script>alert(1)</script>",
    "badlink_js": "javascript:alert(2)",
    "badlink_data": "data:text/html;base64,PHNjcmlwdD4=",
}
for name, href in hrefs.items():
    doc = {"schema": 1, "blocks": [{"id": u(), "type": "paragraph",
           "text": [{"t": "링크", "m": {"link": {"href": href}}}]}]}
    with open(os.path.join(work, name + ".json"), "w", encoding="utf-8") as f:
        json.dump({"doc_version": ver, "doc": doc}, f, ensure_ascii=False)
BADPY
for name in badlink_quote badlink_js badlink_data; do
  CODE=$(capi PATCH "/api/pages/$PAGE" claude_employee01 "$WORK/$name.json")
  expect "$name → 400" "400" "$CODE"
  expect "$name → bad_scheme" "bad_scheme" "$(jget "d['errors'][0]['reason']" < "$WORK/b")"
done

hr "C:T-16 — 낙관적 잠금 (같은 판 번호로 두 번)"
if [ "$SKIP_REST" = "1" ]; then
  skip "C:T-16 전체" "앞 단계에서 판 번호를 잃었다 — 뒤 항목의 PATCH 가 전부 400 이 되어 시험이 앱을 잘못 고발한다"
else


python3 - "$WORK" "$VER" <<'PY'
import json, os, sys, uuid
work, ver = sys.argv[1], int(sys.argv[2])
for n in ("a", "b"):
    doc = {"schema": 1, "blocks": [{"id": str(uuid.uuid4()), "type": "paragraph",
                                    "text": [{"t": "저장 " + n, "m": {}}]}]}
    with open(os.path.join(work, "lock_" + n + ".json"), "w", encoding="utf-8") as f:
        json.dump({"doc_version": ver, "doc": doc}, f, ensure_ascii=False)
PY

CODE=$(capi PATCH "/api/pages/$PAGE" claude_employee01 "$WORK/lock_a.json")
expect "첫 번째 저장 200" "200" "$CODE"
VER2=$(jget "d['doc_version']" < "$WORK/b")

CODE=$(capi PATCH "/api/pages/$PAGE" claude_employee01 "$WORK/lock_b.json")
expect "두 번째(옛 판 번호) 409" "409" "$CODE"
expect "409 본문에 current_version" "$VER2" "$(jget "d['current_version']" < "$WORK/b")"
expect "409 본문에 doc (지금 서버의 것)" "저장 a" "$(jget "d['doc']['blocks'][0]['text'][0]['t']" < "$WORK/b")"

CODE=$(capi GET "/api/pages/$PAGE" claude_employee01)
expect "첫 번째 저장이 유지된다" "저장 a" "$(jget "d['page']['doc']['blocks'][0]['text'][0]['t']" < "$WORK/b")"
VER=$VER2

if [ "$HAVE_PSQL" -eq 1 ]; then
  N=$(psqlq "SELECT count(*) FROM audit_logs WHERE id > ${AUDIT_START:-0} AND action='page.update' AND reason='version_conflict'")
  expect "감사 version_conflict 1건" "1" "${N:-0}"
else
  skip "감사 version_conflict" "psql 없음"
fi

fi

# ════════════════════════════════════════════════════════════════
hr "C:T-17 — 개인 공간 격리"
if [ "$SKIP_REST" = "1" ]; then
  skip "C:T-17 전체" "앞 단계에서 판 번호를 잃었다 — 뒤 항목의 PATCH 가 전부 400 이 되어 시험이 앱을 잘못 고발한다"
else


printf '{"doc_version":%s,"title":"남의 페이지를 고침"}' "$VER" > "$WORK/steal.json"

sub "claude_employee02 (다른 사람)"
CODE=$(capi GET "/api/pages/$PAGE" claude_employee02)
expect "GET 403 (404 가 아니다)" "403" "$CODE"
expect "403 동일 문구" "권한이 없습니다" "$(jget "d['error']" < "$WORK/b")"
CODE=$(capi PATCH "/api/pages/$PAGE" claude_employee02 "$WORK/steal.json")
expect "PATCH 403" "403" "$CODE"
CODE=$(capi DELETE "/api/pages/$PAGE" claude_employee02)
expect "DELETE 403" "403" "$CODE"

sub "claude_manager01 (같은 팀 Manager — 특권 없음)"
CODE=$(capi GET "/api/pages/$PAGE" claude_manager01)
expect "GET 403" "403" "$CODE"

sub "claude_admin01 (Admin — 볼 수는 있다)"
CODE=$(capi GET "/api/pages/$PAGE" claude_admin01)
expect "GET 200" "200" "$CODE"
expect "granted_by = admin_all" "admin_all" "$(jget "d['granted_by']" < "$WORK/b")"
expect "can_edit = False (관리자도 고치지 못한다)" "False" "$(jget "d['can_edit']" < "$WORK/b")"
CODE=$(capi PATCH "/api/pages/$PAGE" claude_admin01 "$WORK/steal.json")
expect "Admin PATCH 403 (보기는 되고 고치기는 안 된다)" "403" "$CODE"

sub "목록은 남의 공간을 보여 주지 않는다 (C:S-02)"
CODE=$(capi GET "/api/pages" claude_employee02)
expect "claude_employee02 목록 200" "200" "$CODE"
expect "그 목록에 employee01 의 페이지가 없다" "0" \
  "$(grep -c "$PAGE" "$WORK/b" | tr -d ' ')"

if [ "$HAVE_PSQL" -eq 1 ]; then
  N=$(psqlq "SELECT count(*) FROM audit_logs WHERE id > ${AUDIT_START:-0} AND action='page.read' AND result='deny' AND reason='not_owner'")
  expect "감사 page.read deny not_owner 4건 이상" "yes" "$([ "${N:-0}" -ge 4 ] && echo yes || echo "no(${N:-0})")"
  N=$(psqlq "SELECT count(*) FROM audit_logs WHERE id > ${AUDIT_START:-0} AND action='page.read' AND result='allow' AND reason='admin_all'")
  expect "감사 page.read allow admin_all 1건" "1" "${N:-0}"
  note "문장: $(psqlq "SELECT summary FROM audit_logs WHERE action='page.read' AND reason='admin_all' ORDER BY id DESC LIMIT 1")"
else
  skip "감사 not_owner·admin_all" "psql 없음"
fi

fi

# ════════════════════════════════════════════════════════════════
hr "C:T-18 — 쓰기 자격 · CSRF"
if [ "$SKIP_REST" = "1" ]; then
  skip "C:T-18 전체" "앞 단계에서 판 번호를 잃었다 — 뒤 항목의 PATCH 가 전부 400 이 되어 시험이 앱을 잘못 고발한다"
else


sub "claude_guest01 — 쓰기 자격 없음 (라우트 정책 community.write)"
printf '{"title":"게스트가 만든 페이지"}' > "$WORK/guest.json"
CODE=$(capi POST /api/pages claude_guest01 "$WORK/guest.json")
expect "POST 403" "403" "$CODE"
expect "403 동일 문구" "권한이 없습니다" "$(jget "d['error']" < "$WORK/b")"
if [ "$HAVE_PSQL" -eq 1 ]; then
  N=$(psqlq "SELECT count(*) FROM audit_logs WHERE id > ${AUDIT_START:-0} AND action='authz.route' AND result='deny' AND target_name = 'POST /community/api/pages'")
  expect "감사 authz.route deny (route 패턴에 /community 접두)" "1" "${N:-0}"
else
  skip "감사 authz.route deny" "psql 없음"
fi

sub "쿠키만 보내고 X-CSRF-Token 없이 PATCH"
curl -s -D "$WORK/h" -o "$WORK/b" -A "$UA" -b "$JAR_DIR/claude_employee01.jar" -X PATCH \
  -H 'Content-Type: application/json' --data-binary "@$WORK/steal.json" "$CBASE/api/pages/$PAGE"
expect "CSRF 없는 PATCH 403" "403" "$(status_of "$WORK/h")"
if [ "$HAVE_PSQL" -eq 1 ]; then
  N=$(psqlq "SELECT count(*) FROM audit_logs WHERE id > ${AUDIT_START:-0} AND action='csrf' AND reason='token_mismatch'")
  expect "감사 csrf deny token_mismatch 1건" "1" "${N:-0}"
else
  skip "감사 csrf" "psql 없음"
fi

sub "게스트의 화면 요청도 막힌다"
curl -s -D "$WORK/h" -o /dev/null -A "$UA" -b "$JAR_DIR/claude_guest01.jar" "$CBASE/"
expect "GET /community/ (Guest) 200 — 화면은 열리고 쓰기만 막힌다" "200" "$(status_of "$WORK/h")"

fi

# ════════════════════════════════════════════════════════════════
hr "C:T-19 — 렌더러 동등성 (표본 24개 — 3단계 20 + 7-D4 멘션 4)"

python3 - "$WORK" <<'PY'
import json, os, sys, uuid
work = sys.argv[1]
def u(): return str(uuid.uuid4())
def seg(t, m=None): return [{"t": t, "m": m or {}}]
samples = []
samples.append({"schema":1,"blocks":[{"id":u(),"type":"paragraph","text":seg("보통 글")}]})
for lv in (1,2,3):
    samples.append({"schema":1,"blocks":[{"id":u(),"type":"heading","level":lv,"text":seg("제목 %d" % lv)}]})
samples.append({"schema":1,"blocks":[{"id":u(),"type":"bulleted","text":seg("하나")},{"id":u(),"type":"bulleted","text":seg("둘")}]})
samples.append({"schema":1,"blocks":[{"id":u(),"type":"numbered","text":seg("첫째")},{"id":u(),"type":"numbered","text":seg("둘째")}]})
samples.append({"schema":1,"blocks":[{"id":u(),"type":"to_do","checked":True,"text":seg("한 일")},{"id":u(),"type":"to_do","checked":False,"text":seg("할 일")}]})
samples.append({"schema":1,"blocks":[{"id":u(),"type":"quote","text":seg("인용")}]})
samples.append({"schema":1,"blocks":[{"id":u(),"type":"callout","icon":"💡","text":seg("강조")}]})
samples.append({"schema":1,"blocks":[{"id":u(),"type":"divider"}]})
samples.append({"schema":1,"blocks":[{"id":u(),"type":"code","code":"echo 1;","language":"php"}]})
samples.append({"schema":1,"blocks":[{"id":u(),"type":"heading","level":1,"text":seg("차례용")},{"id":u(),"type":"toc"}]})
samples.append({"schema":1,"blocks":[{"id":u(),"type":"paragraph","text":[{"t":"굵게","m":{"bold":True}},{"t":"기울임","m":{"italic":True}}]}]})
samples.append({"schema":1,"blocks":[{"id":u(),"type":"paragraph","text":[{"t":"밑줄","m":{"underline":True}},{"t":"취소","m":{"strike":True}}]}]})
samples.append({"schema":1,"blocks":[{"id":u(),"type":"paragraph","text":[{"t":"코드","m":{"code":True}}]}]})
samples.append({"schema":1,"blocks":[{"id":u(),"type":"paragraph","text":[{"t":"빨강","m":{"color":"red"}},{"t":"노랑바탕","m":{"color":"yellow_bg"}}]}]})
samples.append({"schema":1,"blocks":[{"id":u(),"type":"paragraph","text":[{"t":"바깥","m":{"link":{"href":"https://example.com/"}}}]}]})
samples.append({"schema":1,"blocks":[{"id":u(),"type":"paragraph","text":[{"t":"메일","m":{"link":{"href":"mailto:a@b.co"}}}]}]})
samples.append({"schema":1,"blocks":[{"id":u(),"type":"paragraph","color":"blue_bg","align":"center","size":"large","text":seg("꾸민 문단")}]})
samples.append({"schema":1,"blocks":[{"id":u(),"type":"paragraph","text":seg("<img src=x onerror=alert(1)>")}]})
samples.append({"schema":1,"blocks":[{"id":u(),"type":"paragraph","text":seg("여러 줄\n둘째 줄")}]})
while len(samples) < 20:
    samples.append({"schema":1,"blocks":[{"id":u(),"type":"paragraph","text":seg("채움 %d" % len(samples))}]})
samples = samples[:20]
# 7-D4 글 안 멘션 표본 4 — 곁가지(이름 지도) 없이 그린다: 사람·페이지는 두 렌더러 모두 「@볼 수 없음」, 날짜는 id 그대로(지시서 M-6)
samples.append({"schema":1,"blocks":[{"id":u(),"type":"paragraph","text":[{"t":"안녕 ","m":{}},{"t":"@","m":{"mention":{"kind":"user","id":12}}},{"t":" 님","m":{}}]}]})
samples.append({"schema":1,"blocks":[{"id":u(),"type":"paragraph","text":[{"t":"@","m":{"mention":{"kind":"page","id":"0a1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d"}}},{"t":" 참고","m":{}}]}]})
samples.append({"schema":1,"blocks":[{"id":u(),"type":"bulleted","text":[{"t":"마감 ","m":{}},{"t":"@","m":{"mention":{"kind":"date","id":"2026-09-27"},"bold":True}}]}]})
samples.append({"schema":1,"blocks":[{"id":u(),"type":"heading","level":2,"text":[{"t":"@","m":{"mention":{"kind":"user","id":7},"color":"red"}},{"t":"@","m":{"mention":{"kind":"date","id":"2028-02-29"}}}]}]})
with open(os.path.join(work, "samples.json"), "w", encoding="utf-8") as f:
    json.dump(samples, f, ensure_ascii=False)
print(len(samples), "표본")
PY

# 이 서버의 PHP CLI 에는 **dom 확장이 없다**(`php -m`: iconv·json·libxml …). 그래서
# `DOMDocument` 를 쓰지 않고 정규식으로 두 가지만 뽑는다 — 견주는 것이 그 둘뿐이기 때문이다:
#   · 텍스트 노드 열 : 태그로 자른 뒤 남는 조각을 `html_entity_decode` 로 되돌린다
#                      (렌더러의 출력에는 `>` 가 전부 `&gt;` 로 나가므로 태그 자르기가 안전하다)
#   · 클래스 집합   : `class="…"` 를 모아 낱말로 쪼갠다
# 앞 20 은 3단계로, 뒤 4(7-D4 멘션)는 지금 문서 단계(bootstrap 상수 — 멘션은 7단계부터)로 검사한다
DOC_STAGE=$(sed -n 's/^const COMMUNITY_DOC_STAGE = \([0-9]*\);.*/\1/p' "$ROOT/src/bootstrap.php")
ROOT="$ROOT" WORK="$WORK" STAGE="${DOC_STAGE:-7}" php -r '
require getenv("ROOT") . "/src/community/Doc.php";
require getenv("ROOT") . "/src/community/DocRender.php";
$samples = json_decode(file_get_contents(getenv("WORK") . "/samples.json"), true);
$out = [];
$bad = 0;
foreach ($samples as $i => $doc) {
    $v = Doc::validate($doc, $i < 20 ? 3 : (int) getenv("STAGE"));
    if (!$v["ok"]) { $bad++; }
    $html = DocRender::html($doc);

    $texts = [];
    foreach (preg_split("/<[^>]*>/", $html) as $chunk) {
        $t = trim(html_entity_decode($chunk, ENT_QUOTES, "UTF-8"));
        if ($t !== "") { $texts[] = $t; }
    }

    $classes = [];
    preg_match_all("/class=\"([^\"]*)\"/", $html, $m);
    foreach ($m[1] as $cl) {
        foreach (preg_split("/\s+/", html_entity_decode($cl, ENT_QUOTES, "UTF-8")) as $k) {
            if ($k !== "") { $classes[] = $k; }
        }
    }
    $classes = array_values(array_unique($classes));
    sort($classes);

    $out[] = ["texts" => $texts, "classes" => $classes];
}
file_put_contents(getenv("WORK") . "/php-render.json", json_encode($out, JSON_UNESCAPED_UNICODE));
printf("표본 %d개 · 스키마를 통과하지 못한 것 %d개\n", count($samples), $bad);
' > "$WORK/parity-php.txt" 2>&1
cat "$WORK/parity-php.txt"
expect "표본 24개가 모두 스키마를 지난다(앞 20 은 3단계 · 뒤 4 는 지금 단계)" "표본 24개 · 스키마를 통과하지 못한 것 0개" "$(cat "$WORK/parity-php.txt")"

note "JS 쪽(doc-render.js)은 아래 C:T-21 의 브라우저 안에서 잰다 — 창을 두 번 띄우지 않는다"

# ════════════════════════════════════════════════════════════════
hr "C:T-20 — vendoring 무결성"

bash "$ROOT/scripts/vendor_prosemirror.sh" --verify > "$WORK/vendor.txt" 2>&1
VEXIT=$?
tail -6 "$WORK/vendor.txt"
expect "vendor_prosemirror.sh --verify 통과" "0" "$VEXIT"
expect "bare import 0건" "0" \
  "$(grep -rE 'from "[a-z@]' "$ROOT/public/assets/community/vendor/pm" 2>/dev/null | wc -l | tr -d ' ')"
expect "따옴표 종류를 가리지 않고 상대 경로가 아닌 import 0건" "0" \
  "$(grep -hoE "(from|import)[[:space:]]*['\"][^'\"]+['\"]" "$ROOT"/public/assets/community/vendor/pm/*.js \
     | sed -E "s/.*['\"]([^'\"]+)['\"].*/\1/" | grep -vc '^\./' | tr -d ' ')"
expect "vendor/pm 파일 수 14" "14" "$(find "$ROOT/public/assets/community/vendor/pm" -maxdepth 1 -name '*.js' | wc -l | tr -d ' ')"
expect "편집기 부품 전체에 /tbox·/community 절대 경로 리터럴 0건" "0" \
  "$(cat "$ROOT/public/assets/community/editor.js" "$ROOT/public/assets/community/page.js" "$ROOT/public/assets/community/editor-runtime.js" "$ROOT/public/assets/community/editor-loader.js" | grep -cE "['\"]/(tbox|community)/" | tr -d ' ')"
expect "doc-schema.json 사본이 원본과 같다" "0" \
  "$(diff "$ROOT/../community/doc-schema.json" "$ROOT/public/assets/community/doc-schema.json" >/dev/null 2>&1; echo $?)"

# ════════════════════════════════════════════════════════════════
hr "C:T-21 — 브라우저 스모크 (진짜 브라우저로 눌러 본다)"

# 왜 이 항목이 있나 (관문 ③ 2026-09-22)
#   커뮤니티 화면의 동작은 전부 브라우저 안에서 일어난다. `curl` 은 **서버에 요청이 왔을 때**
#   무엇이 오는지를 볼 뿐이라, JS 가 첫 줄에서 죽어 요청이 아예 오지 않는 상태를 통과시킨다.
#   실제로 ProseMirror 스키마가 예외를 던져 화면이 통째로 멈춰 있었는데 이 시험은 82 통과였다.
#   그래서 「콘솔에 오류 0 · 단추가 실제로 눌린다」를 여기서 본다.
#   C:T-19 의 JS 쪽 동등성도 같은 브라우저에서 함께 잰다(창을 두 번 띄우지 않는다).

SMOKE_OUT="$WORK/smoke.txt"
SESSION_VALUE=$(awk '$6=="__Host-tbox_session"{print $7}' "$JAR_DIR/claude_employee01.jar" 2>/dev/null)

if ! python3 -c "import playwright" >/dev/null 2>&1; then
  skip "브라우저 스모크" "Playwright 가 없다 — **미검증**"
  skip "doc-render.js 쪽 동등성 (C:T-19)" "같은 이유 — **미검증**"
elif [ -z "$SESSION_VALUE" ]; then
  skip "브라우저 스모크" "claude_employee01 세션 쿠키를 얻지 못했다 — **미검증**"
  skip "doc-render.js 쪽 동등성 (C:T-19)" "같은 이유 — **미검증**"
else
  # 세션 값은 표준입력 첫 줄로 넘긴다(명령 인자에 싣지 않는다 — tests/lib/secrets.sh py_secret · R-62)
  PATH="$HOME/.local/bin:$PATH" py_secret SESSION_VALUE -- python3 "$ROOT/tests/community/browser_smoke.py" \
    "$SITE" "$UA" "$WORK/samples.json" "$WORK/php-render.json" \
    > "$SMOKE_OUT" 2>&1
  if grep -q '^SMOKE_FAIL=' "$SMOKE_OUT"; then
    sed -n '1,/^── 브라우저가 본 오류/p' "$SMOKE_OUT"
    grep -E '^   (pageerror|console error|requestfailed|HTTP>=400)' "$SMOKE_OUT"
    expect "브라우저에서 pageerror 0건" "PAGEERROR=0" "$(grep '^PAGEERROR=' "$SMOKE_OUT")"
    expect "브라우저에서 콘솔 오류 0건" "CONSOLEERR=0" "$(grep '^CONSOLEERR=' "$SMOKE_OUT")"
    expect "브라우저에서 실패한 요청 0건" "REQFAIL=0" "$(grep '^REQFAIL=' "$SMOKE_OUT")"
    expect "브라우저에서 HTTP 400 이상 0건" "HTTP4XX=0" "$(grep '^HTTP4XX=' "$SMOKE_OUT")"
    expect "스모크 항목 실패 0건" "SMOKE_FAIL=0" "$(grep '^SMOKE_FAIL=' "$SMOKE_OUT")"
    # 브라우저가 스스로 막은 인라인 style(우리 코드가 아니다 — browser_smoke.py 머리글 ★).
    # 실패로 세지 않고 줄 수만 알린다. 숨기지 않는다.
    note "브라우저 내부 CSP 인라인 style 차단: $(grep '^CSPSTYLE=' "$SMOKE_OUT")"
    if grep -q '^DIFF=' "$SMOKE_OUT"; then
      expect "C:T-19 두 렌더러의 텍스트 노드·클래스 집합이 같다" "DIFF=0" "$(grep '^DIFF=' "$SMOKE_OUT")"
    else
      skip "doc-render.js 쪽 동등성 (C:T-19)" "스모크가 그 단계까지 가지 못했다 — **미검증**"
    fi
  else
    skip "브라우저 스모크" "브라우저를 열지 못했다 — **미검증**"
    skip "doc-render.js 쪽 동등성 (C:T-19)" "같은 이유 — **미검증**"
    note "$(tail -4 "$SMOKE_OUT" | tr '\n' ' ')"
    note "받으려면: PATH=\"\$HOME/.local/bin:\$PATH\" playwright install chromium"
  fi
fi

# ════════════════════════════════════════════════════════════════
hr "회귀 — 린트 · 문법"

bash "$ROOT/scripts/security_lint.sh" > "$WORK/lint.txt" 2>&1
expect "금지 패턴 0건" "0" "$?"
tail -2 "$WORK/lint.txt"

n_bad=0
for f in $(find "$ROOT/src/community" "$ROOT/src/help/community.php" -name '*.php'); do
  php -l "$f" >/dev/null 2>&1 || n_bad=$((n_bad+1))
done
expect "커뮤니티 PHP 문법 오류 0건" "0" "$n_bad"
bash -n "$0" && expect "이 시험 파일 bash -n" "0" "0" || expect "이 시험 파일 bash -n" "0" "1"
# 구문만 본다 — `py_compile` 은 저장소에 `__pycache__` 를 남기므로 쓰지 않는다.
python3 -c 'import sys; compile(open(sys.argv[1], encoding="utf-8").read(), sys.argv[1], "exec")' \
  "$ROOT/tests/community/browser_smoke.py" 2>/dev/null \
  && expect "browser_smoke.py 구문" "0" "0" || expect "browser_smoke.py 구문" "0" "1"

# ─── 정리 ───────────────────────────────────────────────────────
hr "정리 — 만든 페이지는 앱 창구로 되돌린다"
for pid in $MADE_PAGES; do
  CODE=$(capi DELETE "/api/pages/$pid" claude_employee01)
  printf '  · 페이지 %s 논리 삭제 [%s]\n' "$pid" "$CODE"
done

if [ "$HAVE_PSQL" -eq 1 ]; then
  expect "시험이 만든 비-qa 행 0건 (검토 UA 기준 — 사용자 데이터 불변)" "0" \
    "$(db_read "$DB" "SELECT count(*) FROM audit_logs
                WHERE id > ${AUDIT_START:-0}
                  AND actor_name IS NOT NULL AND $REVIEW_NOT_SQL
                  AND COALESCE(user_agent, '') LIKE 'tbox-review/%'")"
  note "시험이 만든 감사 행(전체): $(psqlq "SELECT count(*) FROM audit_logs WHERE id > ${AUDIT_START:-0}")건"
  note "남은 qa 페이지: $(psqlq "SELECT count(*) FROM community.pages p JOIN community.spaces s ON s.id=p.space_id JOIN users u ON u.id=s.owner_user_id WHERE $(review_like_sql u.username) AND p.deleted_at IS NULL")건 (「시작하기」 페이지는 남는다)"
else
  skip "비-qa 행 0건" "psql 없음"
fi

# ─── 마무리 ─────────────────────────────────────────────────────
hr "결과"
printf '통과 %d · 실패 %d · 건너뜀/미검증 %d\n' "$PASS" "$FAIL" "$SKIP"
echo "작업 폴더 $WORK 는 종료와 함께 지워진다."
[ "$FAIL" -eq 0 ] || exit 1
exit 0
