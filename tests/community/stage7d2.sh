#!/usr/bin/env bash
# tests/community/stage7d2.sh — 7-D2 수락 기준 C:T-200 ~ C:T-208 실행 (관리자 팔레트 — C:F-33 · 결정 L-2)
#
#   C:T-200 권한      창구 넷 × 역할 — 관리자만(재인증) · 매니저·직원·손님 403 같은 문구 + authz.route deny · 목록·CSS 는 넷 다 200 · 미로그인 · CSRF
#   C:T-201 형식      이름(bad_name · reserved) · 값(bad_color · 소문자로 맞춤) · 키 · exists · 뺀 뒤 같은 이름은 새 줄 · 없는/뺀 번호 404
#   C:T-202 한도      12 · 13번째 400 · 하나 빼면 다시 201 · 같은 이름 동시 다섯 → 살아 있는 줄 1 · 12개일 때 동시 다섯 → ≤ 12
#                     (동시 요청은 워커 여섯 인스턴스 8102 로 — PHP_CLI_SERVER_WORKERS=6 · 받은 워커 둘 이상을 확인해 진짜 동시)
#   C:T-203 저장 창구 있는 이름만 새로 · 뺀 이름이 이미 든 글은 통과 · 만들기·게시판 새 글·되돌리기·템플릿 저장 · 템플릿에서 만들기 · 표를 못 읽을 때
#                     (관리 창구 셋은 표를 못 읽을 때·표가 없을 때 503 palette_unavailable · 감사 0 · 되돌린 뒤 201·200·200)
#   C:T-204 검증기    모양만(블록·표 행·칸·마크) · 기본 18값 · 3단계 표본 20 · doc-schema.json 변경 0
#   C:T-205 CSS 파일  모양 · 배경값 공식(파이썬 대조) · 뺀 이름 0 · 버전 = 셸 ?v= = ETag = 목록 version · 빈 목록 · 인라인 style 0 · CSP 그대로
#                     · 앱 규칙 밖 줄(손으로 고친 표)은 건너뛰고 서버 로그는 요청당 한 줄
#   C:T-206 렌더러    DocRender(PHP) 와 doc-render.js 의 텍스트 노드 열·클래스 집합 — PHP 쪽은 여기, JS 쪽은 browser_7d2.py
#   C:T-207 감사      add·update(before)·remove 각 allow 1행 · 임시 DB max(id) 전후 대조 · detail 에 본문·비밀 0
#   C:T-208 브라우저  browser_7d2.py — 관리 창 · 편집기 색 줄 · 읽기 화면 계산 색(라이트·다크) · 대비 경고 · 뺀 뒤 색 없음 · 직원·손님 ·
#                     360·412(터치·마우스)·820·1280 × 라이트·다크 · ui_snapshot 1440·1280 결함 수 = 기준 판(stack/2026-09-27)
#
# ★ 운영 DB 를 건드리지 않는다 — stage7d1.sh 와 같은 틀(임시 DB `tbox_7d2_test`, 포트 8100 · 기준 판 비교 인스턴스 8101 · C:T-202 동시 요청 인스턴스 8102) · 검토 계정 claude_(F-71).
#   운영 tbox_re 는 pg_dump 로 **읽기만** 한다(파이프 — /tmp 덤프 파일 0). 임시 `.env` 세 줄(DB_NAME·STORAGE_DIR·AUDIT_LOG_FILE)이 임시를
#   가리키는지 서버를 띄우기 **전에** 보고, 아니면 exit 3(2026-09-26 사고 셋).
# 쓰는 법: bash tests/community/stage7d2.sh   (REGRESS=1 이면 끝에 회귀 목록을 같은 임시 인스턴스에서 — 8082 → 임시로 sed 치환)
#          환경 SHOTS=<폴더>(기본 ~/.claude/ui-shots/7d2) · BROWSER_LOCK=<잠금 파일>(있으면 flock -x 아래에서 브라우저 단계)
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
. "$ROOT/tests/lib/secrets.sh" || { echo "tests/lib/secrets.sh 를 읽지 못했다 — 시험을 시작하지 않는다 (R-62)"; exit 3; }
PORT=8100
PORT_BASE=8101
PORT_CONC=8102            # C:T-202 동시 요청만 받는 두 번째 인스턴스(같은 앱 사본 · 같은 임시 DB · PHP_CLI_SERVER_WORKERS=6)
SITE="http://127.0.0.1:$PORT"
BASE="$SITE/tbox"
CBASE="$SITE/community"
UA="tbox-review/$(date +%F)"
SRC_DB="tbox_re"          # 운영 — **읽기만** 한다
DB="tbox_7d2_test"         # 임시 — 여기에만 쓴다
BASE_REV="${BASE_REV:-stack/2026-09-27}"   # ui_snapshot 결함 수를 견줄 기준 판(반영 전 판)
PROD_REV="${PROD_REV:-ad5a795}"            # 이 묶음 위에 합친 운영 판 — 허용 목록 밖 검사에서 운영 쪽(발행·정리 묶음)이 가져온 파일을 가른다
SHOTS="${SHOTS:-$HOME/.claude/ui-shots/7d2}"
JAR_DIR="$(mktemp -d)"
WORK="$(mktemp -d)"
INST=""
INST_BASE=""
SERVER_PID=""
SERVER_BASE_PID=""
SERVER_CONC_PID=""
PASS=0
FAIL=0
SKIP=0

hr()  { printf '\n════════════════════════════════════════════════════════════\n%s\n════════════════════════════════════════════════════════════\n' "$1"; }
sub() { printf '\n── %s\n' "$1"; }
expect() {
  if [ "$2" = "$3" ]; then printf '  ✔ %s  (기대=%s 실제=%s)\n' "$1" "$2" "$3"; PASS=$((PASS+1))
  else printf '  ✖ %s  (기대=%s 실제=%s)\n' "$1" "$2" "$3"; FAIL=$((FAIL+1)); fi
}
contains() {
  case "$3" in
    *"$2"*) printf '  ✔ %s  (「%s」 포함)\n' "$1" "$2"; PASS=$((PASS+1)) ;;
    *)      printf '  ✖ %s  (「%s」 없음 — 실제: %s)\n' "$1" "$2" "$(printf '%s' "$3" | head -c 400)"; FAIL=$((FAIL+1)) ;;
  esac
}
lacks() {
  case "$3" in
    *"$2"*) printf '  ✖ %s  (「%s」 가 응답에 있다 — 실제: %s)\n' "$1" "$2" "$(printf '%s' "$3" | head -c 400)"; FAIL=$((FAIL+1)) ;;
    *)      printf '  ✔ %s  (「%s」 없음)\n' "$1" "$2"; PASS=$((PASS+1)) ;;
  esac
}
note() { printf '  · %s\n' "$1"; }
skip() { printf '  ○ SKIP %s  (%s)\n' "$1" "$2"; SKIP=$((SKIP+1)); }
# 공용 헤드리스 브라우저 잠금(BROWSER_LOCK — 한 번에 하나) + 메모리 여유 700MB 이상을 확인한 뒤 브라우저 단계를 연다(최대 15분 기다림)
browser_lock_on() {
  [ -n "${BROWSER_LOCK:-}" ] || return 0
  exec 9>>"$BROWSER_LOCK"; flock -x 9
  local i a
  for i in $(seq 1 15); do
    a=$(free -m | awk '/^Mem:/{print $7}'); [ "${a:-0}" -ge 700 ] && break
    echo "  · 메모리 여유 ${a}MB < 700MB — 1분 기다림($i/15)"; sleep 60
  done
  echo "  · 브라우저 잠금 안 · 메모리 여유 $(free -m | awk '/^Mem:/{print $7}')MB · $(date +%T)"
}
browser_lock_off() { [ -n "${BROWSER_LOCK:-}" ] || return 0; flock -u 9; exec 9>&-; }

# 워커를 둔 php -S 는 주 프로세스만 죽이면 워커가 포트를 쥔 채 남는다 — 워커(자식)부터 내린다
stop_conc() {
  [ -n "$SERVER_CONC_PID" ] || return 0
  pkill -TERM -P "$SERVER_CONC_PID" 2>/dev/null; kill "$SERVER_CONC_PID" 2>/dev/null; wait "$SERVER_CONC_PID" 2>/dev/null
  SERVER_CONC_PID=""
}
cleanup() {
  if [ -n "$SERVER_PID" ]; then kill "$SERVER_PID" 2>/dev/null; wait "$SERVER_PID" 2>/dev/null; fi
  if [ -n "$SERVER_BASE_PID" ]; then kill "$SERVER_BASE_PID" 2>/dev/null; wait "$SERVER_BASE_PID" 2>/dev/null; fi
  stop_conc
  ( cd / && sudo -n -u postgres dropdb --if-exists "$DB" >/dev/null 2>&1 )
  [ -n "$INST" ] && rm -rf "$INST"
  [ -n "$INST_BASE" ] && rm -rf "$INST_BASE"
  rm -rf "$JAR_DIR" "$WORK"
}
trap cleanup EXIT

# ─── 임시 DB 에만 쓰는 psql (운영 DB 이름은 여기에 들어가지 않는다) ───
#   SQL 은 표준입력으로(명령 인자 -c 0 — sudo 명령 줄이 한 모양이라 운영 경보 AL-9 가 한 줄로 묶는다 · 2026-09-26 취합 규칙)
psqlq() { ( cd / && printf '%s\n' "$1" | sudo -n -u postgres psql -X -q -d "$DB" -t -A 2>/dev/null ); }
psqle() { ( cd / && printf '%s\n' "$1" | sudo -n -u postgres psql -X -q -d "$DB" -t -A 2>&1 ); }   # 오류 글자도 받는다(CHECK 확인용)
mark()  { psqlq "SELECT coalesce(max(id),0) FROM audit_logs"; }

jget() {
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

status_of() { sed -n 's#^HTTP/[0-9.]* \([0-9]*\).*#\1#p' "$1" | tail -1 | tr -d '\r'; }
header_of() { tr -d '\r' < "$1" | sed -n "s/^$2: *//Ip" | tail -1; }

review_password() { sudo -n awk -v u="$1" '$1==u{print $3}' /etc/tbox-v2/seed-accounts.txt 2>/dev/null; }
review_secret()   { sudo -n awk -v u="$1" '$1==u{print $2}' /etc/tbox-v2/qa-totp.txt 2>/dev/null; }

login() {
  local who="$1" jar="$JAR_DIR/$1.jar" pw sec raw code left out try body
  pw=$(review_password "$who")
  sec=$(review_secret "$who")
  [ -n "$pw" ] || return 2
  for try in 1 2 3; do
    if [ -n "$sec" ]; then
      raw=$(totp_now sec 2>/dev/null)
      code=${raw%%$'\n'*}
      # 비밀번호가 든 본문은 인자가 아니라 표준입력으로 보낸다(R-62 · tests/lib/secrets.sh)
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

# 커뮤니티 창구 호출 — $1 메서드 $2 경로 $3 계정 $4 본문 파일(없으면 생략). 머리는 $WORK/h · 본문은 $WORK/b
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
# JSON 본문은 **파이썬이** 만든다(셸 따옴표에 한글·특수 글자가 섞이지 않게). $1 = 파이썬 식
jbody() { python3 -c "import json;print(json.dumps($1, ensure_ascii=False))" > "$WORK/body.json"; }
reason_of() { jget "d.get('reason','')" < "$WORK/b"; }
error_of() { jget "d.get('error','')" < "$WORK/b"; }
errs() { jget "' '.join(e['reason'] for e in d.get('errors', []))" < "$WORK/b"; }
pg_page() { psqlq "SELECT $2 FROM community.pages p WHERE p.public_id = '$1'"; }
uid() { psqlq "SELECT id FROM users WHERE username='$1'"; }
audit_n() {     # $1 기준 id $2 action $3 result $4 reason(없으면 '')
  if [ -z "$4" ]; then
    psqlq "SELECT count(*) FROM audit_logs WHERE id > ${1:-0} AND action='$2' AND result='$3'"
  else
    psqlq "SELECT count(*) FROM audit_logs WHERE id > ${1:-0} AND action='$2' AND result='$3' AND reason='$4'"
  fi
}
rows_since() { psqlq "SELECT count(*) FROM audit_logs WHERE id > ${1:-0}"; }

make_page() {   # $1 계정 $2 제목 [$3 부모 id] [$4 공간 번호] → 새 페이지 id
  python3 - "$2" "${3:-}" "${4:-}" > "$WORK/new.json" <<'PY'
import json, sys
b = {"title": sys.argv[1]}
if sys.argv[2]:
    b["parent_id"] = sys.argv[2]
if sys.argv[3]:
    b["space_id"] = int(sys.argv[3])
print(json.dumps(b, ensure_ascii=False))
PY
  capi POST /api/pages "$1" "$WORK/new.json" > /dev/null
  jget "d['page']['id']" < "$WORK/b"
}

# 블록 식을 문서로 — 파이썬 도우미: P(글, color=, m=) · H(글, color=) · C(글, color=) · T([(행 색, [(글, 칸 색, 마크 색), …]), …])
DOCPY='
import json, sys, uuid
def u(): return str(uuid.uuid4())
def seg(t, m=None): return [{"t": t, "m": dict(m or {})}] if t else []
def P(t, color=None, m=None):
    b = {"id": u(), "type": "paragraph", "text": seg(t, m)}
    if color is not None: b["color"] = color
    return b
def H(t, color=None, m=None):
    b = {"id": u(), "type": "heading", "level": 2, "text": seg(t, m)}
    if color is not None: b["color"] = color
    return b
def C(t, color=None, m=None):
    b = {"id": u(), "type": "callout", "icon": "💡", "text": seg(t, m)}
    if color is not None: b["color"] = color
    return b
def T(rows):
    out = []
    for rc, cells in rows:
        r = {"cells": []}
        if rc is not None: r["color"] = rc
        for (t, cc, mc) in cells:
            cell = {"text": seg(t, {"color": mc} if mc else None)}
            if cc is not None: cell["color"] = cc
            r["cells"].append(cell)
        out.append(r)
    return {"id": u(), "type": "table", "header_row": False, "header_col": False, "rows": out}
'
# 문서 저장 — $1 계정 $2 페이지 $3 블록 목록 파이썬 식 → 상태 코드 (판 번호는 지금 값을 psql 로)
put_doc() {
  local v; v=$(pg_page "$2" "p.doc_version")
  python3 -c "$DOCPY
blocks = eval(sys.argv[1])
print(json.dumps({'doc': {'schema': 1, 'blocks': blocks}, 'doc_version': int(sys.argv[2])}, ensure_ascii=False))
" "$3" "${v:-1}" > "$WORK/body.json"
  capi PATCH "/api/pages/$2" "$1" "$WORK/body.json"
}
# 새 페이지(첫 본문) — $1 계정 $2 제목 $3 블록 식 → 상태 코드
create_with_doc() {
  python3 -c "$DOCPY
blocks = eval(sys.argv[2])
print(json.dumps({'title': sys.argv[1], 'doc': {'schema': 1, 'blocks': blocks}}, ensure_ascii=False))
" "$2" "$3" > "$WORK/body.json"
  capi POST /api/pages "$1" "$WORK/body.json"
}
# 5분 합치기(4-C)를 비켜 새 스냅샷이 남게 — 그 페이지의 판 시각을 10분 앞으로(임시 DB 에만)
age_versions() { psqlq "UPDATE community.page_versions v SET edited_at = edited_at - interval '10 minutes' FROM community.pages p WHERE p.id=v.page_id AND p.public_id='$1'" > /dev/null; }

# 팔레트 창구 — $1 계정 $2 이름 $3 라이트 $4 다크 → 상태 코드 · 새 줄 번호는 $WORK/pid
pal_add() {
  jbody "{'name': '''$2''', 'light': '''$3''', 'dark': '''$4'''}"
  local st; st=$(capi POST /api/palettes "$1" "$WORK/body.json")
  jget "d['palette']['id']" < "$WORK/b" > "$WORK/pid"
  printf '%s' "$st"
}
pid_() { cat "$WORK/pid"; }
pal_id() { psqlq "SELECT id FROM community.palettes WHERE name='$1' AND removed_at IS NULL"; }
pal_rm() { capi DELETE "/api/palettes/$(pal_id "$2")" "$1"; }
live_n() { psqlq "SELECT count(*) FROM community.palettes WHERE removed_at IS NULL"; }

reauth_admin() {
  local pw rc
  pw=$(review_password claude_admin01)
  # 비밀번호는 표준입력 첫 줄로 파이썬에(py_secret) — 명령 인자에 싣지 않는다(R-62)
  rc=$(py_secret pw -- python3 -c "import json,sys;print(json.dumps({'password':sys.stdin.readline().rstrip('\n')}))" \
       | curl -s -o /dev/null -w '%{http_code}' -b "$JAR_DIR/claude_admin01.jar" -A "$UA" -H 'Content-Type: application/json' \
        -H "X-CSRF-Token: $CSRF_claude_admin01" --data-binary @- "$BASE/api/me/reauth")
  printf '%s' "$rc"
}
cookie_of() {
  python3 - "$JAR_DIR/$1.jar" <<'PY2'
import sys
for line in open(sys.argv[1], encoding='utf-8', errors='replace'):
    parts = line.rstrip('\n').split('\t')
    if len(parts) == 7 and parts[5] == '__Host-tbox_session':
        print(parts[6]); break
PY2
}
page_html() {   # $1 주소(SITE 기준 경로) $2 계정 → 상태 코드 · 본문 $WORK/html · 머리 $WORK/hh
  curl -s -D "$WORK/hh" -o "$WORK/html" -w '%{http_code}' -A "$UA" -b "$JAR_DIR/$2.jar" "$SITE$1"
}
link_ver() { sed -n 's#.*<link id="palette-css" rel="stylesheet" href="/community/palette.css?v=\([0-9a-f]*\)">.*#\1#p' "$WORK/html" | head -1; }
css_get() {     # $1 계정 → 상태 코드 · 본문 $WORK/css · 머리 $WORK/ch
  curl -s -D "$WORK/ch" -o "$WORK/css" -w '%{http_code}' -A "$UA" -b "$JAR_DIR/$1.jar" "$CBASE/palette.css"
}
# 배경값 공식(P-4) — 파이썬으로 따로 계산한다(서버 코드를 부르지 않는다)
py_tint() { python3 -c "
import sys
c, b, w = sys.argv[1], sys.argv[2], int(sys.argv[3])
print('#' + ''.join('%02x' % ((int(c[i:i+2],16)*w + int(b[i:i+2],16)*(100-w) + 50)//100) for i in (1,3,5)))" "$1" "$2" "$3"; }

# ════════════════════════════════════════════════════════════════
hr "7-D2 — 임시 인스턴스 준비 (운영 DB 에는 쓰지 않는다)"

avail_kb=$(df -Pk / | awk 'NR==2{print $4}')
echo "루트 파티션 여유: $(df -Ph / | awk 'NR==2{print $4}') (${avail_kb}KB)"
[ "$avail_kb" -ge 1048576 ] || { echo "루트 디스크 여유 1GB 미만 — 시험을 시작하지 않는다 (SECURITY R-80)"; exit 3; }
( cd / && printf 'SELECT 1;\n' | sudo -n -u postgres psql -X -q -d "$SRC_DB" -tA >/dev/null 2>&1 ) \
  || { echo "psql(postgres) 을 쓸 수 없다 — 이 시험은 임시 DB 가 없으면 성립하지 않는다"; exit 3; }
if ss -ltn 2>/dev/null | grep -qE "127\.0\.0\.1:($PORT|$PORT_BASE|$PORT_CONC) "; then
  echo "포트 $PORT·$PORT_BASE·$PORT_CONC 가 이미 쓰이고 있다 — 앞선 시험이 남긴 서버를 먼저 내린다"; exit 3
fi

sub "① 운영 DB 를 **읽어** 임시 DB 를 만든다(파이프 — 덤프 파일 0)"
( cd / && sudo -n -u postgres dropdb --if-exists "$DB" >/dev/null 2>&1 )
( cd / && sudo -n -u postgres createdb -O tbox_owner "$DB" >/dev/null 2>&1 ) || { echo "임시 DB 를 만들 수 없다"; exit 3; }
( cd / && sudo -n -u postgres pg_dump -d "$SRC_DB" 2>/dev/null \
  | sudo -n -u postgres psql -q -d "$DB" -v ON_ERROR_STOP=0 >/dev/null 2>"$WORK/restore.err"; exit "${PIPESTATUS[0]}" )
[ "$?" -eq 0 ] || { echo "운영 DB 를 읽지 못했다 (pg_dump)"; exit 3; }
n_users=$(psqlq "SELECT count(*) FROM public.users")
n_pages=$(psqlq "SELECT count(*) FROM community.pages")
[ -n "$n_users" ] && [ -n "$n_pages" ] || { echo "임시 DB 로 옮기지 못했다 — $(head -2 "$WORK/restore.err")"; exit 3; }
echo "  · 임시 DB $DB — 계정 ${n_users}명 · 페이지 ${n_pages}장 · 팔레트 표(옮긴 뒤·마이그레이션 전): $(psqlq "SELECT coalesce(to_regclass('community.palettes')::text, '없음')")"

sub "② 마이그레이션 (임시 DB 에만) — 패스키(기준 판에 쌓인 표) · 7-D2 팔레트 · 두 번(멱등) · grants.sql"
for mig in 2026-10-06_passkeys.sql 2026-10-07_community_palettes.sql 2026-10-07_community_palettes.sql; do
  echo "    ▸ $mig"
  ( cd / && sudo -n -u postgres psql -q -d "$DB" -v ON_ERROR_STOP=1 -f - ) < "$ROOT/db/migrations/$mig" 2>&1 | sed 's/^/      /'
done
PAL_SHAPE_SQL="SELECT md5(concat_ws('|',
  (SELECT string_agg(a.attname || ':' || format_type(a.atttypid, a.atttypmod) || ':' || a.attnotnull::text || ':' || coalesce(pg_get_expr(d.adbin, d.adrelid), ''), ',' ORDER BY a.attnum)
     FROM pg_attribute a LEFT JOIN pg_attrdef d ON d.adrelid = a.attrelid AND d.adnum = a.attnum
    WHERE a.attrelid = 'community.palettes'::regclass AND a.attnum > 0 AND NOT a.attisdropped),
  (SELECT string_agg(pg_get_constraintdef(k.oid), '|' ORDER BY pg_get_constraintdef(k.oid)) FROM pg_constraint k WHERE k.conrelid = 'community.palettes'::regclass),
  (SELECT string_agg(indexdef, '|' ORDER BY indexname) FROM pg_indexes WHERE schemaname = 'community' AND tablename = 'palettes'),
  (SELECT pg_get_userbyid(relowner) FROM pg_class WHERE oid = 'community.palettes'::regclass),
  (SELECT string_agg(r.rolname || '/' || p || '=' || has_table_privilege(r.rolname, 'community.palettes', p)::text, ',' ORDER BY r.rolname, p)
     FROM pg_roles r CROSS JOIN unnest(ARRAY['SELECT','INSERT','UPDATE','DELETE','TRUNCATE','REFERENCES','TRIGGER']) p
    WHERE r.rolname IN ('tbox_app','tbox_backup','tbox_perm','tbox_push','tbox_testread')),
  (SELECT string_agg(r.rolname || '/' || p || '=' || has_sequence_privilege(r.rolname, 'community.palettes_id_seq', p)::text, ',' ORDER BY r.rolname, p)
     FROM pg_roles r CROSS JOIN unnest(ARRAY['USAGE','SELECT','UPDATE']) p
    WHERE r.rolname IN ('tbox_app','tbox_backup','tbox_perm','tbox_push','tbox_testread'))))"
SHAPE_MIG=$(psqlq "$PAL_SHAPE_SQL")
( cd / && sudo -n -u postgres psql -q -d "$DB" -v ON_ERROR_STOP=1 -f - ) < "$ROOT/db/community/grants.sql" 2>&1 | sed 's/^/    /'
SHAPE_GR=$(psqlq "$PAL_SHAPE_SQL")
expect "마이그레이션 뒤 모양·권한 = grants.sql 다시 돌린 뒤(어느 쪽을 다시 돌려도 같은 권한)" "yes" "$([ -n "$SHAPE_MIG" ] && [ "$SHAPE_MIG" = "$SHAPE_GR" ] && echo yes)"
expect "권한 — 앱 S·I·U(DELETE 없음) · 시퀀스 U·S · 백업 S 만 · perm·push 0" "true true true false | true true | true false false false | 0" \
  "$(psqlq "SELECT has_table_privilege('tbox_app','community.palettes','SELECT') || ' ' || has_table_privilege('tbox_app','community.palettes','INSERT') || ' ' || has_table_privilege('tbox_app','community.palettes','UPDATE') || ' ' || has_table_privilege('tbox_app','community.palettes','DELETE') || ' | ' || has_sequence_privilege('tbox_app','community.palettes_id_seq','USAGE') || ' ' || has_sequence_privilege('tbox_app','community.palettes_id_seq','SELECT') || ' | ' || has_table_privilege('tbox_backup','community.palettes','SELECT') || ' ' || has_table_privilege('tbox_backup','community.palettes','INSERT') || ' ' || has_table_privilege('tbox_backup','community.palettes','UPDATE') || ' ' || has_table_privilege('tbox_backup','community.palettes','DELETE') || ' | ' || (SELECT count(*) FROM pg_roles r CROSS JOIN unnest(ARRAY['SELECT','INSERT','UPDATE','DELETE']) p WHERE r.rolname IN ('tbox_perm','tbox_push') AND has_table_privilege(r.rolname,'community.palettes',p))")"
expect "표 소유자 tbox_owner · 행 0" "tbox_owner 0" "$(psqlq "SELECT pg_get_userbyid(relowner) FROM pg_class WHERE oid='community.palettes'::regclass") $(psqlq "SELECT count(*) FROM community.palettes")"

sub "③④ 저장소를 임시 폴더로 복사하고 두 번째 인스턴스를 띄운다"
INST="$(mktemp -d)"
mkdir -p "$INST/app" "$INST/storage" "$INST/log"
tar -C "$ROOT" -cf - --exclude=.git --exclude=jar --exclude=api-test.txt --exclude=./.env . | tar -C "$INST/app" -xf -
# 임시 `.env` — 원본 `.env` 에서 **DB 이름·저장 경로·로그 경로만** 바꾼다. 값은 출력하지 않는다.
#   원본은 이 트리의 .env — 복제본에는 .env 가 없으므로 부르는 쪽이 0600 사본을 트리 뿌리에 두고 부른다(끝나면 지운다).
ENV_SRC="$ROOT/.env"
[ -f "$ENV_SRC" ] || { echo "$ENV_SRC 가 없다 — 임시 .env 를 만들 원본이 없어 시험을 시작하지 않는다"; exit 3; }
# qcheck-allow: R20 — 임시 인스턴스용 사본(0700·0600 · trap 정리) · kill -9 뒤 남는 창은 받아들인 위험(HO-B1-004)
sed -e "s#^DB_NAME=.*#DB_NAME=$DB#" \
    -e "s#^STORAGE_DIR=.*#STORAGE_DIR=$INST/storage#" \
    -e "s#^AUDIT_LOG_FILE=.*#AUDIT_LOG_FILE=$INST/log/audit.log#" \
    "$ENV_SRC" > "$INST/app/.env"
chmod 600 "$INST/app/.env"
# ★ 서버를 띄우기 **전에** 세 줄이 임시를 가리키는지 본다(2026-09-26 사고 셋 — 운영 DB·로그에 쓰는 시험이 되지 않게)
env_db=$(sed -n 's/^DB_NAME=//p' "$INST/app/.env" | tail -1)
env_st=$(sed -n 's/^STORAGE_DIR=//p' "$INST/app/.env" | tail -1)
env_au=$(sed -n 's/^AUDIT_LOG_FILE=//p' "$INST/app/.env" | tail -1)
if [ "$env_db" != "$DB" ] || [ "$env_st" != "$INST/storage" ] || [ "$env_au" != "$INST/log/audit.log" ]; then
  echo "임시 .env 의 DB_NAME·STORAGE_DIR·AUDIT_LOG_FILE 이 임시를 가리키지 않는다 — 중단"; exit 3
fi
[ "$(grep -c '^DB_NAME=' "$INST/app/.env")" = "1" ] || { echo "임시 .env 에 DB_NAME 줄이 하나가 아니다 — 중단"; exit 3; }
echo "  · 임시 .env: DB_NAME=임시($DB) · STORAGE_DIR=임시 · AUDIT_LOG_FILE=임시 — 확인"
PLAN_DIR="${PLAN_OVERRIDE:-$(cd "$ROOT/.." && pwd)/community}"
[ -f "$PLAN_DIR/doc-schema.json" ] || PLAN_DIR=/var/www/qaredi/community
ln -s "$PLAN_DIR" "$INST/community" 2>/dev/null

write_router() {   # $1 앱 폴더
cat > "$1/router.php" <<'PHP'
<?php
$root = __DIR__ . '/public';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (is_string($path) && strpos($path, '/tbox/assets/') === 0) {
    $rel = substr($path, strlen('/tbox/assets/'));
    if (strpos($rel, '..') === false) {
        $file = $root . '/assets/' . $rel;
        if (is_file($file)) {
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            $types = ['js' => 'text/javascript', 'mjs' => 'text/javascript', 'css' => 'text/css',
                      'json' => 'application/json', 'html' => 'text/html', 'svg' => 'image/svg+xml',
                      'png' => 'image/png', 'webmanifest' => 'application/manifest+json',
                      'map' => 'application/json', 'txt' => 'text/plain'];
            header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
            readfile($file);
            return true;
        }
    }
    http_response_code(404);
    return true;
}
require $root . '/index.php';
PHP
}
write_router "$INST/app"
( cd "$INST/app" && exec php -S "127.0.0.1:$PORT" -t public router.php > "$INST/server.log" 2>&1 ) &
SERVER_PID=$!
for _ in 1 2 3 4 5 6 7 8 9 10; do
  sleep 1
  READY=$(curl -s -o /dev/null -w '%{http_code}' -A "$UA" "$CBASE/api/pages")
  [ "$READY" = "401" ] && break
done
expect "임시 인스턴스가 떴다 (미로그인 커뮤니티 창구 401)" "401" "$READY"
[ "$READY" = "401" ] || { echo "인스턴스를 띄우지 못했다 — 중단"; tail -5 "$INST/server.log"; exit 4; }

sub "로그인 (claude_ 검토 계정 다섯 — **순차**, F-71)"
for who in claude_employee01 claude_employee02 claude_manager01 claude_admin01 claude_guest01; do
  tok=$(login "$who")
  if [ -z "$tok" ]; then echo "  ✖ $who 로그인 실패 — 중단"; exit 4; fi
  eval "CSRF_${who}='$tok'"
  echo "  ✔ $who"
done
for who in claude_employee01 claude_employee02 claude_manager01 claude_admin01; do capi GET /api/me/space "$who" > /dev/null; done
B_FREE=$(psqlq "SELECT id FROM community.spaces WHERE kind='board' AND name='자유게시판'")
note "자유게시판 번호: ${B_FREE:-없음}"

# ════════════════════════════════════════════════════════════════
hr "C:T-205(가)  줄이 없을 때 — 머리 주석 한 줄뿐인 200 · 셸 링크 = 빈 목록의 버전"
EMPTY_V=$(python3 -c "import hashlib;print(hashlib.sha256(b'').hexdigest()[:12])")
expect "미로그인 /community/palette.css → 302(로그인으로)" "302" "$(curl -s -o /dev/null -w '%{http_code}' -A "$UA" "$CBASE/palette.css")"
expect "직원 → 200" "200" "$(css_get claude_employee01)"
expect "  Content-Type text/css; charset=utf-8" "text/css; charset=utf-8" "$(header_of "$WORK/ch" Content-Type)"
expect "  Cache-Control private, max-age=604800" "private, max-age=604800" "$(header_of "$WORK/ch" Cache-Control)"
expect "  ETag = 빈 목록의 버전" "\"$EMPTY_V\"" "$(header_of "$WORK/ch" ETag)"
expect "  본문 = 머리 주석 한 줄" "/* qaredi palette v$EMPTY_V */" "$(cat "$WORK/css")"
expect "  줄 수 1" "1" "$(wc -l < "$WORK/css" | tr -d ' ')"
expect "홈 화면 링크 ?v= = 빈 목록의 버전" "200 $EMPTY_V" "$(page_html /community/ claude_employee01) $(link_ver)"
expect "If-None-Match 같은 버전 → 304 · 본문 0바이트" "304 0" \
  "$(rm -f "$WORK/c304"; curl -s -o "$WORK/c304" -w '%{http_code}' -A "$UA" -b "$JAR_DIR/claude_employee01.jar" -H "If-None-Match: \"$EMPTY_V\"" "$CBASE/palette.css") $(cat "$WORK/c304" 2>/dev/null | wc -c | tr -d ' ')"
expect "목록 창구 — 비어 있음 · 한도 12 · 버전 · 직원 can_manage 거짓" "200 0 12 $EMPTY_V False" \
  "$(capi GET /api/palettes claude_employee01) $(jget "len(d['palettes'])" < "$WORK/b") $(jget "d['limit']" < "$WORK/b") $(jget "d['version']" < "$WORK/b") $(jget "d['can_manage']" < "$WORK/b")"

# ════════════════════════════════════════════════════════════════
hr "C:T-200  권한 — 창구 넷 × 역할 · 재인증 · CSRF · 미로그인"
m0=$(mark)
st=$(pal_add claude_admin01 brand-1 '#0055aa' '#6aa7e6')
expect "관리자 — 재인증 전 POST → 403" "403" "$st"
expect "  next=reauth" "reauth" "$(jget "d.get('next','')" < "$WORK/b")"
expect "  재인증 → 200" "200" "$(reauth_admin)"
m0=$(mark)
expect "관리자(재인증 뒤) POST brand-1 → 201" "201" "$(pal_add claude_admin01 brand-1 '#0055aa' '#6aa7e6')"
P_BRAND=$(pid_)
expect "관리자 PATCH → 200" "200" "$(capi PATCH "/api/palettes/$P_BRAND" claude_admin01 <(printf '{"dark":"#6aa7e6"}'))"
expect "관리자 GET 목록 → 200 · can_manage 참 · 관리 칸(id·added_by)" "200 True True" \
  "$(capi GET /api/palettes claude_admin01) $(jget "d['can_manage']" < "$WORK/b") $(jget "all('id' in p and 'added_by' in p and 'added_at' in p for p in d['palettes'])" < "$WORK/b")"
m1=$(mark)
nden=0
for who in claude_manager01 claude_employee01 claude_guest01; do
  expect "$who POST → 403" "403" "$(pal_add "$who" c7d2-x '#111111' '#222222')"
  expect "  같은 문구(권한 없음)" "$(php -r 'require "'"$ROOT"'/src/Http.php"; echo Http::MSG_FORBIDDEN;' 2>/dev/null)" "$(error_of)"
  expect "$who PATCH → 403" "403" "$(capi PATCH "/api/palettes/$P_BRAND" "$who" <(printf '{"light":"#000000"}'))"
  expect "$who DELETE → 403" "403" "$(capi DELETE "/api/palettes/$P_BRAND" "$who")"
  nden=$((nden + 3))
done
expect "  거부 $nden 번 모두 감사 authz.route deny" "$nden" "$(audit_n "$m1" authz.route deny)"
expect "  그 사이 감사 행은 그 거부뿐(max(id) 전후 대조)" "$nden" "$(rows_since "$m1")"
expect "  brand-1 은 그대로(#0055aa · 살아 있음)" "#0055aa 1" "$(psqlq "SELECT light FROM community.palettes WHERE id=$P_BRAND") $(live_n)"
for who in claude_admin01 claude_manager01 claude_employee01 claude_guest01; do
  expect "$who GET /api/palettes · /palette.css → 200 200" "200 200" "$(capi GET /api/palettes "$who") $(css_get "$who")"
done
capi GET /api/palettes claude_guest01 > /dev/null
expect "손님 목록 — 관리 칸 없음(id·added_by·added_at·updated_at)" "False" "$(jget "any(k in p for p in d['palettes'] for k in ('id','added_by','added_at','updated_at'))" < "$WORK/b")"
expect "미로그인 GET /api/palettes → 401 · POST → 401" "401 401" \
  "$(curl -s -o /dev/null -w '%{http_code}' -A "$UA" "$CBASE/api/palettes") $(curl -s -o /dev/null -w '%{http_code}' -A "$UA" -H 'Content-Type: application/json' --data-binary '{"name":"z","light":"#000000","dark":"#000000"}' "$CBASE/api/palettes")"
expect "CSRF 없는 관리자 POST → 403" "403" \
  "$(curl -s -o "$WORK/b" -w '%{http_code}' -A "$UA" -b "$JAR_DIR/claude_admin01.jar" -H 'Content-Type: application/json' --data-binary '{"name":"c7d2-csrf","light":"#000000","dark":"#000000"}' "$CBASE/api/palettes")"
expect "  CSRF 거부 뒤 줄 없음" "0" "$(psqlq "SELECT count(*) FROM community.palettes WHERE name='c7d2-csrf'")"

# ════════════════════════════════════════════════════════════════
hr "C:T-201  형식 — 이름 · 값 · 키 · 겹침 · 뺀 뒤 새 줄 · 404"
for bad in 'Brand' '브랜드' 'a b' 'abcdefghijklmnopqrstu' '' ' brand' 'x-bg' 'red_bg' 'a.b'; do
  expect "이름 「$bad」 → 400 bad_name" "400 bad_name" "$(pal_add claude_admin01 "$bad" '#0055aa' '#6aa7e6') $(reason_of)"
done
for bad in red none gray blue; do
  expect "예약 이름 「$bad」 → 400 reserved" "400 reserved" "$(pal_add claude_admin01 "$bad" '#0055aa' '#6aa7e6') $(reason_of)"
done
expect "  이름 21자는 모양 밖 — 20자는 201" "201" "$(pal_add claude_admin01 abcdefghijklmnopqrst '#101010' '#202020')"
P_LONG=$(pid_)
expect "#0055AA(대문자)·앞뒤 빈칸 → 201 · 응답·표 모두 소문자" "201 #0055aa #0055aa #aabbcc" \
  "$(pal_add claude_admin01 upper-case ' #0055AA ' '#AABBCC') $(jget "d['palette']['light']" < "$WORK/b") $(psqlq "SELECT light FROM community.palettes WHERE name='upper-case' AND removed_at IS NULL") $(psqlq "SELECT dark FROM community.palettes WHERE name='upper-case' AND removed_at IS NULL")"
for bad in '#05a' 'blue' '0055aa' '#0055aag' 'rgb(0,85,170)' '#0055a' ''; do
  expect "값 「$bad」 → 400 bad_color" "400 bad_color" "$(pal_add claude_admin01 c7d2-color "$bad" '#6aa7e6') $(reason_of)"
done
expect "  다크 값만 틀려도 → 400 bad_color" "400 bad_color" "$(pal_add claude_admin01 c7d2-color '#0055aa' 'navy') $(reason_of)"
jbody "{'name': 'c7d2-key', 'light': '#000000', 'dark': '#000000', 'note': 'x'}"
expect "모르는 키 → 400(요청 문구)" "400 " "$(capi POST /api/palettes claude_admin01 "$WORK/body.json") $(reason_of)"
jbody "{'name': 'c7d2-key', 'light': '#000000'}"
expect "빠진 키 → 400" "400" "$(capi POST /api/palettes claude_admin01 "$WORK/body.json")"
jbody "{'name': 7, 'light': '#000000', 'dark': '#000000'}"
expect "글자가 아닌 이름 → 400" "400" "$(capi POST /api/palettes claude_admin01 "$WORK/body.json")"
expect "PATCH 에 name → 400(이름은 고칠 수 없다)" "400" "$(capi PATCH "/api/palettes/$P_BRAND" claude_admin01 <(printf '{"name":"brand-2","light":"#000000"}'))"
expect "PATCH 빈 몸 → 400" "400" "$(capi PATCH "/api/palettes/$P_BRAND" claude_admin01 <(printf '{}'))"
expect "PATCH 값 모양 밖 → 400 bad_color" "400 bad_color" "$(capi PATCH "/api/palettes/$P_BRAND" claude_admin01 <(printf '{"light":"#12345"}')) $(reason_of)"
expect "PATCH 대문자 값 → 200 · 소문자로 저장" "200 #0055bb" "$(capi PATCH "/api/palettes/$P_BRAND" claude_admin01 <(printf '{"light":"#0055BB"}')) $(psqlq "SELECT light FROM community.palettes WHERE id=$P_BRAND")"
capi PATCH "/api/palettes/$P_BRAND" claude_admin01 <(printf '{"light":"#0055aa"}') > /dev/null
expect "겹치는 이름 → 400 exists" "400 exists" "$(pal_add claude_admin01 brand-1 '#111111' '#222222') $(reason_of)"
expect "upper-case 빼기 → 200" "200" "$(pal_rm claude_admin01 upper-case)"
expect "  뺀 뒤 같은 이름 → 201 · 새 줄(옛 줄 removed_at 남음 — 줄 둘)" "201 2 1" \
  "$(pal_add claude_admin01 upper-case '#123456' '#654321') $(psqlq "SELECT count(*) FROM community.palettes WHERE name='upper-case'") $(psqlq "SELECT count(*) FROM community.palettes WHERE name='upper-case' AND removed_at IS NOT NULL")"
OLD_UC=$(psqlq "SELECT id FROM community.palettes WHERE name='upper-case' AND removed_at IS NOT NULL")
expect "없는 번호 PATCH → 404" "404" "$(capi PATCH /api/palettes/999999 claude_admin01 <(printf '{"light":"#000000"}'))"
NF1=$(error_of)
expect "뺀 번호 PATCH → 404 같은 문구" "404 $NF1" "$(capi PATCH "/api/palettes/$OLD_UC" claude_admin01 <(printf '{"light":"#000000"}')) $(error_of)"
expect "없는 번호 DELETE → 404 같은 문구" "404 $NF1" "$(capi DELETE /api/palettes/999999 claude_admin01) $(error_of)"
expect "뺀 번호 DELETE → 404 같은 문구" "404 $NF1" "$(capi DELETE "/api/palettes/$OLD_UC" claude_admin01) $(error_of)"
bad=$(psqle "INSERT INTO community.palettes (name, light, dark, added_by) VALUES ('Bad', '#000000', '#000000', $(uid claude_admin01))")
contains "DB CHECK — 대문자 이름은 들어가지 못한다" "palettes_name_shape" "$bad"
bad=$(psqle "INSERT INTO community.palettes (name, light, dark, added_by) VALUES ('ok-name', '#ABCDEF', '#000000', $(uid claude_admin01))")
contains "DB CHECK — 대문자 값은 들어가지 못한다" "palettes_light_shape" "$bad"
bad=$(psqle "INSERT INTO community.palettes (name, light, dark, added_by) VALUES ('brand-1', '#000000', '#000000', $(uid claude_admin01))")
contains "DB 고유 색인 — 살아 있는 같은 이름 둘은 없다" "palettes_live_name" "$bad"
expect "앱 계정은 DELETE 할 수 없다(빼기는 removed_at)" "f" "$(psqlq "SELECT has_table_privilege('tbox_app','community.palettes','DELETE')")"
pal_rm claude_admin01 abcdefghijklmnopqrst > /dev/null
pal_rm claude_admin01 upper-case > /dev/null

# ════════════════════════════════════════════════════════════════
hr "C:T-202  한도 12 · 겹침 — advisory lock 트랜잭션"
L0=$(live_n)
note "지금 살아 있는 줄: $L0"
ok=0; i=0
while [ "$(live_n)" -lt 12 ]; do
  i=$((i+1)); n=$(printf 'c7d2-f%02d' "$i")
  [ "$(pal_add claude_admin01 "$n" '#101010' '#202020')" = "201" ] && ok=$((ok+1))
  [ "$i" -gt 20 ] && break
done
expect "12개까지 모두 201" "$((12 - L0)) 12" "$ok $(live_n)"
expect "13번째 → 400 too_many_palettes" "400 too_many_palettes" "$(pal_add claude_admin01 c7d2-over '#101010' '#202020') $(reason_of)"
expect "하나 빼면 → 다시 201" "200 201 12" "$(pal_rm claude_admin01 c7d2-f01) $(pal_add claude_admin01 c7d2-over '#101010' '#202020') $(live_n)"
pal_rm claude_admin01 c7d2-over > /dev/null; pal_rm claude_admin01 c7d2-f02 > /dev/null
note "동시 요청 전 살아 있는 줄: $(live_n)"
# 동시 요청은 워커 여섯의 두 번째 인스턴스로(같은 앱 사본 · 같은 임시 DB · 같은 세션 쿠키) — 기본 php -S 는 한 프로세스라
#   다섯이 차례로 처리되어 advisory lock 이 없어도 통과한다. 다른 절은 전처럼 워커 없는 8100 그대로(결과가 바뀌지 않게)
( cd "$INST/app" && PHP_CLI_SERVER_WORKERS=6 exec php -S "127.0.0.1:$PORT_CONC" -t public router.php > "$INST/server_conc.log" 2>&1 ) &
SERVER_CONC_PID=$!
READY_C=""
for _ in 1 2 3 4 5 6 7 8 9 10; do
  sleep 1
  READY_C=$(curl -s -o /dev/null -w '%{http_code}' -A "$UA" "http://127.0.0.1:$PORT_CONC/community/api/pages")
  [ "$READY_C" = "401" ] && break
done
expect "동시 요청용 인스턴스(포트 $PORT_CONC · PHP_CLI_SERVER_WORKERS=6)가 떴다 — 미로그인 401" "401" "$READY_C"
note "워커: $(sed -n 's/^\[\([0-9]*\)\] .* Development Server .* started$/\1/p' "$INST/server_conc.log" | sort -u | wc -l | tr -d ' ')개 프로세스(주 프로세스 포함) — PHP_CLI_SERVER_WORKERS=6"
conc() {        # $1 이름 접두 $2 같은 이름이면 same · 아니면 diff → 결과 코드들 · 이 다섯을 받은(Accepted) 워커 수는 $WORK/cw_$1
  local k t p left pids=()
  for k in 1 2 3 4 5; do
    local nm="$1"; [ "$2" = "diff" ] && nm="$1-$k"
    curl -s -o "$WORK/cc$k" -w '%{http_code} %{local_port}\n' -A "$UA" -b "$JAR_DIR/claude_admin01.jar" -H 'Content-Type: application/json' \
      -H "X-CSRF-Token: $CSRF_claude_admin01" --data-binary "{\"name\":\"$nm\",\"light\":\"#123123\",\"dark\":\"#321321\"}" "http://127.0.0.1:$PORT_CONC/community/api/palettes" > "$WORK/cs$k" &
    pids+=("$!")
  done
  # php -S 워커 모드(PHP 8.1)는 한 워커가 연결을 받아 둔 채 다음 accept() 에서 막힐 수 있다(다른 워커가 그 연결을 먼저 가져감 —
  #   받아 둔 요청은 새 연결이 올 때까지 처리되지 않는다 · 2026-09-27 첫 실행에서 겪음). 다섯이 끝날 때까지 0.3초마다 가벼운 요청
  #   (로그인 화면 GET 셋 · 3초 제한)을 보내 막힌 워커를 깨운다 — 팔레트 표를 읽지도 쓰지도 않아 다섯의 판정에는 닿지 않는다
  for t in $(seq 1 60); do
    left=0; for p in "${pids[@]}"; do kill -0 "$p" 2>/dev/null && left=1; done
    [ "$left" = 0 ] && break
    sleep 0.3
    for k in 1 2 3; do curl -s -o /dev/null -m 3 -A "$UA" "http://127.0.0.1:$PORT_CONC/tbox/login" & done
  done
  wait
  # 다섯을 받은 워커 — 요청마다 클라이언트 포트로 서버 로그의 「Accepted」 줄을 찾아 그 프로세스 번호를 센다(깨우는 요청은 세지 않는다)
  for k in 1 2 3 4 5; do
    p=$(cut -d' ' -f2 "$WORK/cs$k")
    [ -n "$p" ] && sed -n "s/^\[\([0-9]*\)\] .* 127\.0\.0\.1:$p Accepted\$/\1/p" "$INST/server_conc.log" | tail -1
  done | sort -u | grep -c . > "$WORK/cw_$1"
  for k in 1 2 3 4 5; do cut -d' ' -f1 "$WORK/cs$k"; done | sort | tr '\n' ' '
}
workers_of() { local w; w=$(cat "$WORK/cw_$1" 2>/dev/null); printf '%s' "${w:-0}"; }
codes=$(conc c7d2-same same)
expect "같은 이름 다섯 동시 → 201 하나 · 400 넷" "201 400 400 400 400 " "$codes"
expect "  다섯을 받은 워커 $(workers_of c7d2-same)개 — 둘 이상(진짜 동시)" "yes" "$([ "$(workers_of c7d2-same)" -ge 2 ] && echo yes)"
expect "  살아 있는 그 이름 줄 1" "1" "$(psqlq "SELECT count(*) FROM community.palettes WHERE name='c7d2-same' AND removed_at IS NULL")"
note "12 로 채우기 전: $(live_n)"
[ "$(live_n)" -lt 12 ] && pal_add claude_admin01 c7d2-fill '#101010' '#202020' > /dev/null
codes=$(conc c7d2-race diff)
expect "12개 상태에서 다른 이름 다섯 동시 → 모두 400" "400 400 400 400 400 " "$codes"
expect "  다섯을 받은 워커 $(workers_of c7d2-race)개 — 둘 이상(진짜 동시)" "yes" "$([ "$(workers_of c7d2-race)" -ge 2 ] && echo yes)"
expect "  살아 있는 줄 ≤ 12(= 12)" "12" "$(live_n)"
pal_rm claude_admin01 c7d2-fill > /dev/null
codes=$(conc c7d2-race2 diff)
expect "11개 상태에서 다른 이름 다섯 동시 → 201 하나 · 400(too_many) 넷" "201 400 400 400 400 " "$codes"
expect "  다섯을 받은 워커 $(workers_of c7d2-race2)개 — 둘 이상(진짜 동시)" "yes" "$([ "$(workers_of c7d2-race2)" -ge 2 ] && echo yes)"
expect "  살아 있는 줄 = 12" "12" "$(live_n)"
expect "  동시 요청용 인스턴스 로그에 잡히지 않은 예외 0" "0" "$(grep -c 'uncaught\|Fatal error' "$INST/server_conc.log" 2>/dev/null | tr -d ' ')"
stop_conc
expect "  동시 요청용 인스턴스를 내렸다(포트 $PORT_CONC 비움 — 워커까지)" "0" "$(sleep 1; ss -ltn 2>/dev/null | grep -cE "127\.0\.0\.1:$PORT_CONC " | tr -d ' ')"
# 채운 줄을 모두 뺀다(brand-1 만 남긴다)
for n in $(psqlq "SELECT name FROM community.palettes WHERE removed_at IS NULL AND name <> 'brand-1' ORDER BY name"); do pal_rm claude_admin01 "$n" > /dev/null; done
expect "정리 — 살아 있는 줄은 brand-1 하나" "brand-1" "$(psqlq "SELECT string_agg(name, ',') FROM community.palettes WHERE removed_at IS NULL")"

# ════════════════════════════════════════════════════════════════
hr "C:T-203  저장 창구 — 있는 이름만 새로 · 뺀 이름은 옛 글만 통과 · 넷 창구 · 표를 못 읽을 때"
expect "c7d2-old 더하기 → 201" "201" "$(pal_add claude_admin01 c7d2-old '#aa0000' '#ff6666')"
PG=$(make_page claude_employee01 'C7D2-팔레트 페이지')
expect "새 pal:brand-1(글자·배경·블록·표 행·칸·마크) 저장 → 200" "200" \
  "$(put_doc claude_employee01 "$PG" "[P('브랜드 글자', m={'color':'pal:brand-1'}), P('브랜드 배경', color='pal:brand-1_bg'), T([('pal:brand-1_bg', [('칸', 'pal:brand-1', 'pal:brand-1')])])]")"
expect "  저장된 본문에 pal:brand-1 그대로" "yes" "$(pg_page "$PG" "p.doc::text" | grep -q 'pal:brand-1_bg' && echo yes)"
m0=$(mark)
expect "등록 안 된 pal:nope → 400 palette_not_found" "400 palette_not_found" "$(put_doc claude_employee01 "$PG" "[P('x', m={'color':'pal:nope'})]") $(reason_of)"
expect "  감사 page.update deny palette_not_found 1" "1" "$(audit_n "$m0" page.update deny palette_not_found)"
expect "  문구 = 창구 넷의 같은 문구" "$(php -r 'const TBOX_REQUEST_ID="x"; require "'"$ROOT"'/src/community/Doc.php"; require "'"$ROOT"'/src/community/Palette.php"; $s = file_get_contents("'"$ROOT"'/src/community/routes.php"); preg_match("/const COMMUNITY_PALETTE_REFUSED = \x27([^\x27]+)\x27;/", $s, $m); echo $m[1];' 2>/dev/null)" "$(error_of)"
expect "  표 행 색에 pal:nope → 400 palette_not_found" "400 palette_not_found" "$(put_doc claude_employee01 "$PG" "[T([('pal:nope_bg', [('칸', None, None)])])]") $(reason_of)"
expect "  예약 이름 pal:red(모양은 맞다 — 등록될 수 없다) → 400 palette_not_found" "400 palette_not_found" "$(put_doc claude_employee01 "$PG" "[P('x', color='pal:red')]") $(reason_of)"
Q=$(make_page claude_employee01 'C7D2-옛 색이 든 페이지')
expect "c7d2-old 를 쓴 글 저장 → 200" "200" "$(put_doc claude_employee01 "$Q" "[P('옛 색', m={'color':'pal:c7d2-old'}), P('둘째')]")"
expect "c7d2-old 빼기 → 200" "200" "$(pal_rm claude_admin01 c7d2-old)"
expect "뺀 이름이 이미 든 글 — 다른 곳을 고쳐 저장 → 200(옛 이름 통과)" "200" "$(put_doc claude_employee01 "$Q" "[P('옛 색', m={'color':'pal:c7d2-old'}), P('둘째 고침')]")"
expect "  같은 글에 뺀 이름을 한 번 더(블록 색) — 이름 집합이 같아 → 200" "200" "$(put_doc claude_employee01 "$Q" "[P('옛 색', m={'color':'pal:c7d2-old'}), P('셋째', color='pal:c7d2-old_bg')]")"
R2=$(make_page claude_employee01 'C7D2-새로 넣는 페이지')
expect "뺀 이름을 **새로** 넣기 → 400 palette_not_found" "400 palette_not_found" "$(put_doc claude_employee01 "$R2" "[P('새로', m={'color':'pal:c7d2-old'})]") $(reason_of)"
# ── 만들기
expect "만들기 첫 본문 pal:brand-1 → 201" "201" "$(create_with_doc claude_employee01 'C7D2-만들기 긍정' "[P('b', m={'color':'pal:brand-1_bg'})]")"
expect "만들기 첫 본문 뺀 이름 → 400 palette_not_found" "400 palette_not_found" "$(create_with_doc claude_employee01 'C7D2-만들기 부정' "[P('b', m={'color':'pal:c7d2-old'})]") $(reason_of)"
# ── 게시판 새 글 — 본문을 받지 않는다(5-C · 본문 저장은 PATCH 한 창구). 첫 저장이 같은 판정
jbody "{'title': 'C7D2-게시판 글 본문 동봉', 'doc': {'schema': 1, 'blocks': []}}"
expect "게시판 새 글에 doc 키 → 400(받지 않는 키 — 본문은 PATCH 로만)" "400" "$(capi POST "/api/boards/$B_FREE/pages" claude_employee01 "$WORK/body.json")"
jbody "{'title': 'C7D2-게시판 글'}"
expect "게시판 새 글 → 201" "201" "$(capi POST "/api/boards/$B_FREE/pages" claude_employee01 "$WORK/body.json")"
BP=$(jget "d['page']['id']" < "$WORK/b")
expect "  게시판 글 첫 저장 pal:brand-1 → 200" "200" "$(put_doc claude_employee01 "$BP" "[P('게시판 브랜드', m={'color':'pal:brand-1'})]")"
expect "  게시판 글 저장 뺀 이름 → 400 palette_not_found" "400 palette_not_found" "$(put_doc claude_employee01 "$BP" "[P('게시판', m={'color':'pal:c7d2-old'})]") $(reason_of)"
# ── 되돌리기
expect "c7d2-rv 더하기 → 201" "201" "$(pal_add claude_admin01 c7d2-rv '#00aa00' '#66ff66')"
V=$(make_page claude_employee01 'C7D2-되돌리기')
expect "판 1: brand-1 저장 → 200" "200" "$(put_doc claude_employee01 "$V" "[P('판 브랜드', m={'color':'pal:brand-1'})]")"
age_versions "$V"
expect "판 2: c7d2-rv 저장 → 200" "200" "$(put_doc claude_employee01 "$V" "[P('판 rv', m={'color':'pal:c7d2-rv'})]")"
age_versions "$V"
expect "판 3: 색 없음 저장 → 200" "200" "$(put_doc claude_employee01 "$V" "[P('판 색 없음')]")"
age_versions "$V"
expect "판 4: 한 번 더 저장(판 3 을 스냅샷으로) → 200" "200" "$(put_doc claude_employee01 "$V" "[P('판 색 없음 2')]")"
expect "c7d2-rv 빼기 → 200" "200" "$(pal_rm claude_admin01 c7d2-rv)"
VRV=$(psqlq "SELECT v.version FROM community.page_versions v JOIN community.pages p ON p.id=v.page_id WHERE p.public_id='$V' AND v.doc::text LIKE '%pal:c7d2-rv%' ORDER BY v.version LIMIT 1")
VBR=$(psqlq "SELECT v.version FROM community.page_versions v JOIN community.pages p ON p.id=v.page_id WHERE p.public_id='$V' AND v.doc::text LIKE '%pal:brand-1%' AND v.doc::text NOT LIKE '%c7d2-rv%' ORDER BY v.version LIMIT 1")
note "판 번호: 뺀 이름이 든 판 ${VRV:-없음} · brand-1 판 ${VBR:-없음}"
m0=$(mark)
expect "뺀 이름이 든 판으로 되돌리기 → 400 palette_not_found" "400 palette_not_found" "$(capi POST "/api/pages/$V/versions/${VRV:-0}/restore" claude_employee01 <(printf '{}')) $(reason_of)"
expect "  감사 page.restore_version deny palette_not_found 1" "1" "$(audit_n "$m0" page.restore_version deny palette_not_found)"
expect "살아 있는 이름이 든 판으로 되돌리기 → 200" "200" "$(capi POST "/api/pages/$V/versions/${VBR:-0}/restore" claude_employee01 <(printf '{}'))"
# ── 템플릿 저장 · 템플릿에서 만들기
expect "c7d2-t 더하기 → 201" "201" "$(pal_add claude_admin01 c7d2-t '#0000aa' '#6666ff')"
TP=$(make_page claude_employee01 'C7D2-템플릿 원본')
expect "  원본 저장(c7d2-t · brand-1) → 200" "200" "$(put_doc claude_employee01 "$TP" "[P('틀', m={'color':'pal:c7d2-t'}), P('브랜드', color='pal:brand-1_bg')]")"
jbody "{'page_id': '$TP', 'name': 'C7D2-색 템플릿', 'scope': 'personal'}"
expect "살아 있는 이름만 든 페이지를 템플릿으로 저장 → 201" "201" "$(capi POST /api/templates claude_employee01 "$WORK/body.json")"
TPL=$(jget "d['template']['id']" < "$WORK/b")
jbody "{'page_id': '$Q', 'name': 'C7D2-뺀 색 템플릿', 'scope': 'personal'}"
expect "뺀 이름이 든 페이지를 템플릿으로 저장 → 400 palette_not_found" "400 palette_not_found" "$(capi POST /api/templates claude_employee01 "$WORK/body.json") $(reason_of)"
expect "c7d2-t 빼기 → 200" "200" "$(pal_rm claude_admin01 c7d2-t)"
jbody "{'template_id': '$TPL'}"
expect "뺀 이름이 든 템플릿에서 새 페이지 → 201(담을 때 견주었다 — K-7)" "201" "$(capi POST /api/pages claude_employee01 "$WORK/body.json")"
# ── 표를 못 읽을 때(반영 순서가 어긋난 창 — P-17). 임시 DB 에서만 앱 계정의 SELECT 를 잠시 뺀다
LOGN0=$(grep -c 'palettes unreadable' "$INST/server.log" 2>/dev/null | tr -d ' ')
psqlq "REVOKE SELECT ON community.palettes FROM tbox_app" > /dev/null
expect "  (임시) 앱 계정 SELECT 없음" "f" "$(psqlq "SELECT has_table_privilege('tbox_app','community.palettes','SELECT')")"
expect "표를 못 읽을 때 페이지 열기 → 200" "200" "$(page_html "/community/p/$PG" claude_employee01)"
expect "  셸 링크 = 빈 목록의 버전" "$EMPTY_V" "$(link_ver)"
expect "  /palette.css → 200 · 머리 주석만" "200 /* qaredi palette v$EMPTY_V */" "$(css_get claude_employee01) $(cat "$WORK/css")"
expect "  목록 창구 → 200 · 빈 목록(편집기는 기본 9색)" "200 0" "$(capi GET /api/palettes claude_employee01) $(jget "len(d['palettes'])" < "$WORK/b")"
expect "  새 pal:brand-1 저장 → 400 palette_not_found(닫힌 쪽)" "400 palette_not_found" "$(put_doc claude_employee01 "$R2" "[P('새', m={'color':'pal:brand-1'})]") $(reason_of)"
expect "  색 없는 저장은 그대로 → 200" "200" "$(put_doc claude_employee01 "$R2" "[P('색 없음')]")"
# 관리 창구 셋(더하기·고치기·빼기)도 500 이 아니라 503 palette_unavailable · 감사 0 · 세 창구 같은 문구(검토 반영 — 권한 없음 42501)
expect "  재인증(관리 창구 — 15분 창) → 200" "200" "$(reauth_admin)"
UNAV_MSG=$(sed -n "s/^const COMMUNITY_PALETTE_UNAVAILABLE = '\([^']*\)';$/\1/p" "$ROOT/src/community/routes.php")
pal_unav() {    # 세 창구의 「상태 사유」 셋 · 문구가 셋 다 COMMUNITY_PALETTE_UNAVAILABLE 이면 끝에 same
  local a b c ea eb ec
  a="$(pal_add claude_admin01 c7d2-unav '#010101' '#fefefe') $(reason_of)"; ea=$(error_of)
  b="$(capi PATCH "/api/palettes/$P_BRAND" claude_admin01 <(printf '{"light":"#000000"}')) $(reason_of)"; eb=$(error_of)
  c="$(capi DELETE "/api/palettes/$P_BRAND" claude_admin01) $(reason_of)"; ec=$(error_of)
  printf '%s | %s | %s | %s' "$a" "$b" "$c" "$([ -n "$UNAV_MSG" ] && [ "$ea" = "$UNAV_MSG" ] && [ "$eb" = "$UNAV_MSG" ] && [ "$ec" = "$UNAV_MSG" ] && echo same)"
}
pal_state() { psqlq "SELECT (SELECT light || dark || (removed_at IS NULL)::text FROM community.palettes WHERE id=$P_BRAND) || ' ' || (SELECT count(*) FROM community.palettes WHERE name='c7d2-unav') || ' ' || (SELECT count(*) FROM community.palettes WHERE removed_at IS NULL)"; }
UNAV_WANT="503 palette_unavailable | 503 palette_unavailable | 503 palette_unavailable | same"
unav_log() { grep -c "palettes unavailable (SQLSTATE $1)" "$INST/server.log" 2>/dev/null | tr -d ' '; }
PS0=$(pal_state); LOGU0=$(unav_log 42501); m0=$(mark)
expect "관리 창구 셋(POST·PATCH·DELETE) — 표를 못 읽을 때 → 503 palette_unavailable · 같은 문구" "$UNAV_WANT" "$(pal_unav)"
expect "  감사 행 0(쓰기 전에 실패 — max(id) 전후 대조)" "0" "$(rows_since "$m0")"
expect "  표 그대로(brand-1 값·살아 있음 · c7d2-unav 줄 0 · 살아 있는 줄 수)" "$PS0" "$(pal_state)"
expect "  서버 로그 「palettes unavailable (SQLSTATE 42501)」 — 창구마다 한 줄" "3" "$(( $(unav_log 42501) - ${LOGU0:-0} ))"
LOGN1=$(grep -c 'palettes unreadable' "$INST/server.log" 2>/dev/null | tr -d ' ')
expect "  서버 로그에 「palettes unreadable」 줄이 남는다" "yes" "$([ "${LOGN1:-0}" -gt "${LOGN0:-0}" ] && echo yes)"
expect "  서버 로그에 잡히지 않은 예외 0" "0" "$(grep -c 'uncaught\|Fatal error' "$INST/server.log" 2>/dev/null | tr -d ' ')"
psqlq "GRANT SELECT ON community.palettes TO tbox_app" > /dev/null
expect "  (임시) 권한 되돌림 — 마이그레이션 모양과 같다" "yes" "$([ "$(psqlq "$PAL_SHAPE_SQL")" = "$SHAPE_MIG" ] && echo yes)"
# ── 표가 아예 없을 때(임시 DB 에서만 표 이름을 잠시 바꾼다 — 42P01). 관리 창구 셋 503 · 읽기 쪽은 P-17 대로 · 되돌린 뒤 201·200·200 (검토 반영)
psqlq "ALTER TABLE community.palettes RENAME TO palettes_c7d2_away" > /dev/null
expect "  (임시) 표 이름 바꿈 — community.palettes 없음" "없음" "$(psqlq "SELECT coalesce(to_regclass('community.palettes')::text, '없음')")"
LOGU0=$(unav_log 42P01); m0=$(mark)
expect "관리 창구 셋 — 표가 없을 때 → 503 palette_unavailable · 같은 문구" "$UNAV_WANT" "$(pal_unav)"
expect "  감사 행 0" "0" "$(rows_since "$m0")"
expect "  서버 로그 「palettes unavailable (SQLSTATE 42P01)」 — 창구마다 한 줄" "3" "$(( $(unav_log 42P01) - ${LOGU0:-0} ))"
expect "  표가 없을 때도 페이지 열기 200 · CSS 200 머리 주석만 · 목록 200 빈 목록" "200 200 /* qaredi palette v$EMPTY_V */ 200 0" \
  "$(page_html "/community/p/$PG" claude_employee01) $(css_get claude_employee01) $(cat "$WORK/css") $(capi GET /api/palettes claude_employee01) $(jget "len(d['palettes'])" < "$WORK/b")"
psqlq "ALTER TABLE community.palettes_c7d2_away RENAME TO palettes" > /dev/null
expect "  (임시) 표 이름 되돌림 — 마이그레이션 모양과 같다 · 표 그대로" "yes $PS0" "$([ "$(psqlq "$PAL_SHAPE_SQL")" = "$SHAPE_MIG" ] && echo yes) $(pal_state)"
st_a=$(pal_add claude_admin01 c7d2-unav '#010101' '#fefefe'); P_UNAV=$(pid_)
st_u=$(capi PATCH "/api/palettes/${P_UNAV:-0}" claude_admin01 <(printf '{"light":"#020202"}'))
st_r=$(capi DELETE "/api/palettes/${P_UNAV:-0}" claude_admin01)
expect "되돌린 뒤 관리 창구 셋 → POST 201 · PATCH 200 · DELETE 200" "201 200 200" "$st_a $st_u $st_r"
expect "  서버 로그에 잡히지 않은 예외 0(표를 못 읽는 창 · 표가 없는 창 · 되돌린 뒤)" "0" "$(grep -c 'uncaught\|Fatal error' "$INST/server.log" 2>/dev/null | tr -d ' ')"

# ════════════════════════════════════════════════════════════════
hr "C:T-204  검증기 — 모양만 · 넷 자리 같은 결과 · 기본 18 · 3단계 표본 20 · 스키마 변경 0"
STAGE=$(sed -n 's/^const COMMUNITY_DOC_STAGE = \([0-9]*\);.*/\1/p' "$ROOT/src/bootstrap.php")
note "문서 단계(bootstrap 상수): ${STAGE:-?}"
ROOT="$ROOT" STAGE="${STAGE:-7}" php -r '
require getenv("ROOT") . "/src/community/Doc.php";
$st = (int) getenv("STAGE");
$u = function () { return sprintf("%08x-%04x-4%03x-%x%03x-%012x", mt_rand(0, 0xffffffff), mt_rand(0, 0xffff), mt_rand(0, 0xfff), mt_rand(8, 11), mt_rand(0, 0xfff), mt_rand(0, 0xffffffffffff)); };
$docs = [
  "block" => function ($c) use ($u) { return ["schema" => 1, "blocks" => [["id" => $u(), "type" => "paragraph", "color" => $c, "text" => [["t" => "a", "m" => []]]]]]; },
  "row"   => function ($c) use ($u) { return ["schema" => 1, "blocks" => [["id" => $u(), "type" => "table", "header_row" => false, "header_col" => false, "rows" => [["color" => $c, "cells" => [["text" => [["t" => "a", "m" => []]]]]]]]]]; },
  "cell"  => function ($c) use ($u) { return ["schema" => 1, "blocks" => [["id" => $u(), "type" => "table", "header_row" => false, "header_col" => false, "rows" => [["cells" => [["text" => [["t" => "a", "m" => []]], "color" => $c]]]]]]]; },
  "mark"  => function ($c) use ($u) { return ["schema" => 1, "blocks" => [["id" => $u(), "type" => "paragraph", "text" => [["t" => "a", "m" => ["color" => $c]]]]]]; },
];
$good = array_merge(["pal:brand-1", "pal:brand-1_bg", "pal:never-registered", "pal:bg", "pal:bg_bg", "pal:a", "pal:12345678901234567890"], Doc::colorValues());
$bad  = ["pal:Brand", "pal:", "pal:brand_bg_bg", "palette:x", "pal:x-bg", "pal:x_BG", "pal:x-bg_bg", "pal:123456789012345678901", "pal: x", "pal:x\n", "PAL:x", "pal:한글", "red_bg_bg", "nt-red"];
$out = ["good_fail" => [], "bad_pass" => [], "bad_reason" => []];
foreach ($docs as $pos => $mk) {
  foreach ($good as $c) { $v = Doc::validate($mk($c), $st); if (!$v["ok"]) { $out["good_fail"][] = $pos . ":" . $c . ":" . $v["errors"][0]["reason"]; } }
  foreach ($bad as $c)  { $v = Doc::validate($mk($c), $st);
    if ($v["ok"]) { $out["bad_pass"][] = $pos . ":" . $c; }
    elseif ($v["errors"][0]["reason"] !== "not_in_palette") { $out["bad_reason"][] = $pos . ":" . json_encode($c) . ":" . $v["errors"][0]["reason"]; } }
}
$out["n_good"] = count($good); $out["n_bad"] = count($bad);
echo json_encode($out, JSON_UNESCAPED_UNICODE);
' > "$WORK/v204.json" 2>&1
expect "통과해야 할 값(팔레트 모양 7 + 기본 18) × 넷 자리 — 실패 0" "0" "$(jget "len(d['good_fail'])" < "$WORK/v204.json")"
[ "$(jget "len(d['good_fail'])" < "$WORK/v204.json")" = "0" ] || note "$(jget "d['good_fail'][:6]" < "$WORK/v204.json")"
expect "막아야 할 값 14 × 넷 자리 — 통과 0" "0" "$(jget "len(d['bad_pass'])" < "$WORK/v204.json")"
[ "$(jget "len(d['bad_pass'])" < "$WORK/v204.json")" = "0" ] || note "$(jget "d['bad_pass'][:6]" < "$WORK/v204.json")"
expect "  막힌 사유는 모두 not_in_palette" "0" "$(jget "len(d['bad_reason'])" < "$WORK/v204.json")"
[ "$(jget "len(d['bad_reason'])" < "$WORK/v204.json")" = "0" ] || note "$(jget "d['bad_reason'][:6]" < "$WORK/v204.json")"
expect "창구에서도 — pal:x-bg 저장 → 400 not_in_palette" "400 not_in_palette" "$(put_doc claude_employee01 "$R2" "[P('x', m={'color':'pal:x-bg'})]") $(errs)"
expect "창구에서도 — pal:Brand(블록 색) → 400 not_in_palette" "400 not_in_palette" "$(put_doc claude_employee01 "$R2" "[P('x', color='pal:Brand')]") $(errs)"
# 3단계 표본 20 — stage3.sh C:T-19 와 같은 표본(같은 생성 식)을 지금 검증기로
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
json.dump(samples[:20], open(os.path.join(work, "samples3.json"), "w", encoding="utf-8"), ensure_ascii=False)
PY
expect "3단계 표본 20개 — 지금 검증기로 3단계·지금 단계 모두 통과" "20 20" \
  "$(ROOT="$ROOT" WORK="$WORK" STAGE="${STAGE:-7}" php -r 'require getenv("ROOT") . "/src/community/Doc.php"; $s = json_decode(file_get_contents(getenv("WORK") . "/samples3.json"), true); $a = 0; $b = 0; foreach ($s as $d) { $a += Doc::validate($d, 3)["ok"] ? 1 : 0; $b += Doc::validate($d, (int) getenv("STAGE"))["ok"] ? 1 : 0; } echo $a . " " . $b;' 2>&1)"
expect "doc-schema.json 사본 = 원본(기획 폴더) — 두 벌 diff 0" "0" "$(diff "$PLAN_DIR/doc-schema.json" "$ROOT/public/assets/community/doc-schema.json" >/dev/null 2>&1; echo $?)"
if git -C "$ROOT" rev-parse -q --verify "$BASE_REV^{commit}" >/dev/null 2>&1; then
  expect "doc-schema.json 저장소 사본 변경 0(기준 판 $BASE_REV 대비 · 작업 트리 포함)" "0" \
    "$(git -C "$ROOT" diff --stat "$BASE_REV" -- public/assets/community/doc-schema.json | wc -l | tr -d ' ')"
else
  skip "doc-schema.json 변경 0(git)" "기준 판 $BASE_REV 가 이 저장소에 없다"
fi

# ════════════════════════════════════════════════════════════════
hr "C:T-205  CSS 파일 — 모양 · 배경값 공식 · 뺀 이름 0 · 버전 = 셸 ?v= = ETag · 인라인 style 0 · CSP"
expect "c7d2-two 더하기 → 201" "201" "$(pal_add claude_admin01 c7d2-two '#ffff00' '#333333')"
V_ADD=$(jget "d['version']" < "$WORK/b")
check_css() {   # 지금 살아 있는 이름 전부 — 세 블록 · 규칙 · 배경 공식 · 뺀 이름 0 · 모양 밖 값 0
  css_get claude_employee01 > /dev/null
  python3 - "$WORK/css" "$1" "$2" <<'PY'
import re, sys
css = open(sys.argv[1], encoding="utf-8").read()
live = [x.split("|") for x in sys.argv[2].split(",") if x]
gone = [x for x in sys.argv[3].split(",") if x]
def tint(c, b, w): return "#" + "".join("%02x" % ((int(c[i:i+2],16)*w + int(b[i:i+2],16)*(100-w) + 50)//100) for i in (1,3,5))
lines = css.split("\n")
bad = []
head = lines[0]
if not re.fullmatch(r"/\* qaredi palette v[0-9a-f]{12} \*/", head): bad.append("head")
blk = {}
m = re.search(r"^:root\{([^}]*)\}$", css, re.M);                         blk["light"] = m.group(1) if m else None
m = re.search(r'^:root\[data-theme="dark"\]\{([^}]*)\}$', css, re.M);   blk["dark1"] = m.group(1) if m else None
m = re.search(r'^@media \(prefers-color-scheme: dark\)\{:root:not\(\[data-theme="light"\]\)\{([^}]*)\}\}$', css, re.M); blk["dark2"] = m.group(1) if m else None
for k, v in blk.items():
    if v is None: bad.append("block-missing:" + k)
if blk["dark1"] != blk["dark2"]: bad.append("dark-blocks-differ")
for name, light, dark in live:
    want_l = "--pal-%s:%s;--pal-%s-bg:%s" % (name, light, name, tint(light, "#ffffff", 12))
    want_d = "--pal-%s:%s;--pal-%s-bg:%s" % (name, dark, name, tint(dark, "#09090b", 24))
    if want_l not in (blk["light"] or "").split(";") and want_l not in (blk["light"] or ""): bad.append("light:" + name)
    if want_d not in (blk["dark1"] or ""): bad.append("dark:" + name)
    rule = ".pal-%s{color:var(--pal-%s)}.pal-%s-bg{background:var(--pal-%s-bg)}" % (name, name, name, name)
    if rule not in css: bad.append("rule:" + name)
for g in gone:
    if re.search(r"pal-%s[-{;:)]" % re.escape(g), css): bad.append("gone-present:" + g)
vals = re.findall(r":(#[^;}]*)", css)
if any(not re.fullmatch(r"#[0-9a-f]{6}", v) for v in vals): bad.append("value-shape")
if "style=" in css or "<" in css or "@import" in css or "url(" in css: bad.append("forbidden-text")
print("ok" if not bad else " ".join(bad))
PY
}
LIVE=$(psqlq "SELECT string_agg(name || '|' || light || '|' || dark, ',' ORDER BY name) FROM community.palettes WHERE removed_at IS NULL")
GONE=$(psqlq "SELECT string_agg(DISTINCT name, ',') FROM community.palettes p WHERE removed_at IS NOT NULL AND NOT EXISTS (SELECT 1 FROM community.palettes q WHERE q.name=p.name AND q.removed_at IS NULL)")
note "살아 있는: $LIVE · 뺀 이름: $GONE"
expect "CSS 모양 — 세 블록 · 규칙 · 배경값 = 파이썬 공식 · 뺀 이름 0 · 값 모양 · 금지 글자 0" "ok" "$(check_css "$LIVE" "$GONE")"
note "배경값 대조(파이썬): brand-1 라이트 #0055aa → $(py_tint '#0055aa' '#ffffff' 12) · 다크 #6aa7e6 → $(py_tint '#6aa7e6' '#09090b' 24) · c7d2-two 라이트 #ffff00 → $(py_tint '#ffff00' '#ffffff' 12) · 다크 #333333 → $(py_tint '#333333' '#09090b' 24)"
cp "$WORK/css" "$WORK/css-two.txt"
note "GET /community/palette.css 응답 원문(이름 둘 — brand-1 · c7d2-two):"
sed -n '1,/^\r\{0,1\}$/p' "$WORK/ch" | tr -d '\r' | grep -iE '^(HTTP|content-type|cache-control|etag|x-content-type-options):' | sed 's/^/    │ /'
sed 's/^/    │ /' "$WORK/css-two.txt"
ET=$(header_of "$WORK/ch" ETag)
expect "ETag = 목록 창구 version = 더하기 응답 version" "\"$V_ADD\" $V_ADD" "$ET $(capi GET /api/palettes claude_employee01 >/dev/null; jget "d['version']" < "$WORK/b")"
page_html /community/ claude_employee01 > /dev/null; LV_HOME=$(link_ver); cp "$WORK/html" "$WORK/home.html"; cp "$WORK/hh" "$WORK/home.h"
page_html "/community/p/$PG" claude_employee01 > /dev/null; LV_PAGE=$(link_ver); cp "$WORK/html" "$WORK/page.html"; cp "$WORK/hh" "$WORK/page.h"
page_html "/community/b/$B_FREE" claude_employee01 > /dev/null; LV_BOARD=$(link_ver); cp "$WORK/html" "$WORK/board.html"
expect "홈·페이지·게시판의 <link id=palette-css ?v=> = ETag" "$V_ADD $V_ADD $V_ADD" "$LV_HOME $LV_PAGE $LV_BOARD"
expect "t-box 화면에는 팔레트 링크가 없다" "0" "$(page_html /tbox/ claude_employee01 >/dev/null; grep -c 'palette-css' "$WORK/html" | tr -d ' ')"
for f in home page board; do
  expect "  $f 화면 — 인라인 <style> 0 · style= 속성 0" "0 0" "$(grep -ci '<style' "$WORK/$f.html" | tr -d ' ') $(grep -cE '[[:space:]]style=' "$WORK/$f.html" | tr -d ' ')"
done
CSP_PAGE=$(header_of "$WORK/page.h" Content-Security-Policy)
CSP_WANT=$(sed -n "s/^    header(\"Content-Security-Policy: \(.*\)\");$/\1/p" "$ROOT/src/bootstrap.php" | head -1)
contains "페이지 화면 CSP — style-src 'self' 그대로" "style-src 'self';" "$CSP_PAGE"
expect "  페이지 화면 CSP = 부트스트랩 기본값(틀 스위치가 켜져 있으면 frame-src 만 덧붙음)" "yes" "$(case "$CSP_PAGE" in "$CSP_WANT"*) echo yes ;; *) echo no ;; esac)"
expect "CSS 응답에도 보안 머리(nosniff · CSP)" "nosniff yes" "$(header_of "$WORK/ch" X-Content-Type-Options) $([ -n "$(header_of "$WORK/ch" Content-Security-Policy)" ] && echo yes)"
# 버전은 더하기·고치기·빼기마다 바뀐다 — 셸 링크가 따라온다
P_TWO=$(pal_id c7d2-two)
expect "고치기 → 200" "200" "$(capi PATCH "/api/palettes/$P_TWO" claude_admin01 <(printf '{"light":"#eeee00"}'))"
V_UPD=$(jget "d['version']" < "$WORK/b")
expect "  버전이 바뀌었다 · 셸 링크 = 새 버전" "yes $V_UPD" "$([ -n "$V_UPD" ] && [ "$V_UPD" != "$V_ADD" ] && echo yes) $(page_html /community/ claude_employee01 >/dev/null; link_ver)"
expect "  CSS 에 새 값 · 옛 값 0" "1 0" "$(css_get claude_employee01 >/dev/null; grep -c -- '--pal-c7d2-two:#eeee00' "$WORK/css" | tr -d ' ') $(grep -c -- '--pal-c7d2-two:#ffff00' "$WORK/css" | tr -d ' ')"
expect "빼기 → 200" "200" "$(pal_rm claude_admin01 c7d2-two)"
V_RM=$(jget "d['version']" < "$WORK/b")
expect "  버전이 바뀌었다 · 셸 링크 = 새 버전 · ETag" "yes $V_RM \"$V_RM\"" "$([ -n "$V_RM" ] && [ "$V_RM" != "$V_UPD" ] && echo yes) $(page_html "/community/p/$PG" claude_employee01 >/dev/null; link_ver) $(css_get claude_employee01 >/dev/null; header_of "$WORK/ch" ETag)"
expect "  뺀 이름은 CSS 0곳" "0" "$(grep -c 'c7d2-two' "$WORK/css" | tr -d ' ')"
expect "  버전 = 파이썬으로 센 내용 해시(이름순 name|light|dark)" "$V_RM" \
  "$(psqlq "SELECT string_agg(name || '|' || light || '|' || dark, E'\n' ORDER BY name COLLATE \"C\") FROM community.palettes WHERE removed_at IS NULL" | python3 -c 'import sys,hashlib;print(hashlib.sha256(sys.stdin.read().rstrip("\n").encode()).hexdigest()[:12])')"
DARKBG=$(grep -oE -- '--bg: #[0-9a-f]{6}' "$ROOT/public/assets/app.css" | sort -u | tr '\n' ' ')
expect "배경을 섞는 다크 바탕 = app.css 다크 --bg(두 곳) = Palette::DARK_BG = palettes.js DARK_PAGE_BG" "--bg: #09090b --bg: #ffffff  #09090b #09090b" \
  "$DARKBG $(sed -n "s/^    public const DARK_BG  = '\(#[0-9a-f]*\)';/\1/p" "$ROOT/src/community/Palette.php") $(sed -n "s/^const DARK_PAGE_BG = '\(#[0-9a-f]*\)';/\1/p" "$ROOT/public/assets/community/palettes.js")"
expect "  app.css 의 다크 --bg 두 블록이 같은 값" "2" "$(grep -c -- '--bg: #09090b' "$ROOT/public/assets/app.css" | tr -d ' ')"
# 앱 규칙 밖 줄이 표에 있을 때(DB 를 손으로 고친 경우 — DB CHECK 는 통과하지만 예약 이름 · 끝 -bg) — 그릴 때 건너뛰고 서버 로그는 요청당 한 줄(검토 반영)
AD_ID=$(uid claude_admin01)
psqlq "INSERT INTO community.palettes (name, light, dark, added_by) VALUES ('red', '#111111', '#222222', ${AD_ID:-0}), ('zz-bg', '#333333', '#444444', ${AD_ID:-0})" > /dev/null
expect "  (임시) 앱 규칙 밖 줄 둘(red · zz-bg)을 psql 로 넣음" "2" "$(psqlq "SELECT count(*) FROM community.palettes WHERE name IN ('red','zz-bg') AND removed_at IS NULL")"
LOGS0=$(grep -c 'palette rows skipped' "$INST/server.log" 2>/dev/null | tr -d ' ')
r1=$(css_get claude_employee01); cp "$WORK/css" "$WORK/css-skip1"; r2=$(css_get claude_employee01)
expect "  CSS 두 번 → 200 200 · 그 두 이름의 규칙 0 · 버전(ETag) 그대로" "200 200 0 \"$V_RM\"" \
  "$r1 $r2 $(cat "$WORK/css-skip1" "$WORK/css" | grep -cE 'pal-(red|zz-bg)' | tr -d ' ') $(header_of "$WORK/ch" ETag)"
expect "  서버 로그 「palette rows skipped」 = 요청 수 2(줄마다가 아니라 요청당 한 줄)" "2" "$(( $(grep -c 'palette rows skipped' "$INST/server.log" 2>/dev/null | tr -d ' ') - ${LOGS0:-0} ))"
contains "  그 줄에 건너뛴 줄 수 n=2" "n=2 " "$(grep 'palette rows skipped' "$INST/server.log" | tail -1) "
psqlq "DELETE FROM community.palettes WHERE name IN ('red','zz-bg')" > /dev/null
expect "  (임시) 두 줄 지움 — 남은 0" "0" "$(psqlq "SELECT count(*) FROM community.palettes WHERE name IN ('red','zz-bg')")"

# ════════════════════════════════════════════════════════════════
hr "C:T-206  렌더러 동등 — PHP 쪽(DocRender). JS 쪽은 C:T-208 브라우저 안에서 견준다"
python3 - "$WORK" <<'PY'
import json, os, sys, uuid
work = sys.argv[1]
def u(): return str(uuid.uuid4())
def seg(t, m=None): return [{"t": t, "m": m or {}}]
S = []
S.append({"schema":1,"blocks":[{"id":u(),"type":"paragraph","text":[{"t":"브랜드 글자","m":{"color":"pal:brand-1"}},{"t":" 보통","m":{}}]}]})
S.append({"schema":1,"blocks":[{"id":u(),"type":"paragraph","text":[{"t":"브랜드 배경","m":{"color":"pal:brand-1_bg","bold":True}}]}]})
S.append({"schema":1,"blocks":[{"id":u(),"type":"paragraph","color":"pal:brand-1","text":seg("블록 글자색")},{"id":u(),"type":"paragraph","color":"pal:brand-1_bg","text":seg("블록 배경")}]})
S.append({"schema":1,"blocks":[{"id":u(),"type":"table","header_row":True,"header_col":False,"rows":[
    {"color":"pal:brand-1_bg","cells":[{"text":seg("행 색"),"color":"pal:brand-1"},{"text":[{"t":"칸 마크","m":{"color":"pal:brand-1"}}]}]},
    {"cells":[{"text":seg("보통 칸")},{"text":seg("빨강 칸"),"color":"red_bg"}]}]}]})
S.append({"schema":1,"blocks":[{"id":u(),"type":"paragraph","text":[{"t":"뺀 이름","m":{"color":"pal:c7d2-old"}},{"t":"뺀 배경","m":{"color":"pal:c7d2-old_bg"}}]}]})
S.append({"schema":1,"blocks":[{"id":u(),"type":"paragraph","text":[{"t":"모양 밖1","m":{"color":"pal:x-bg"}},{"t":"모양 밖2","m":{"color":"pal:Brand"}},{"t":"모양 밖3","m":{"color":"palette:x"}},{"t":"모양 밖4","m":{"color":"pal:a_bg_bg"}}]}]})
S.append({"schema":1,"blocks":[{"id":u(),"type":"callout","icon":"💡","color":"pal:brand-1_bg","text":seg("강조 상자")},{"id":u(),"type":"heading","level":2,"color":"pal:brand-1","text":seg("제목")}]})
S.append({"schema":1,"blocks":[{"id":u(),"type":"paragraph","color":"pal:x-bg","text":[{"t":"블록 모양 밖","m":{"color":"pal:bg"}},{"t":"이름 bg 배경","m":{"color":"pal:bg_bg"}}]}]})
S.append({"schema":1,"blocks":[{"id":u(),"type":"bulleted","color":"pal:brand-1","text":[{"t":"글머리","m":{"color":"red"}}]},{"id":u(),"type":"to_do","checked":False,"color":"pal:c7d2-old_bg","text":seg("할 일")}]})
S.append({"schema":1,"blocks":[{"id":u(),"type":"paragraph","text":[{"t":"<b>꺾쇠</b>","m":{"color":"pal:brand-1"}}]}]})
# stage3 C:T-19 의 표본 20(C:T-204 가 만든 samples3.json)도 함께 — 팔레트가 들어온 뒤에도 옛 표본의 두 렌더러가 같은지(회귀)
S += json.load(open(os.path.join(work, "samples3.json"), encoding="utf-8"))
json.dump(S, open(os.path.join(work, "samples206.json"), "w", encoding="utf-8"), ensure_ascii=False)
print(len(S), "표본")
PY
ROOT="$ROOT" WORK="$WORK" php -r '
require getenv("ROOT") . "/src/community/Doc.php";
require getenv("ROOT") . "/src/community/DocRender.php";
$samples = json_decode(file_get_contents(getenv("WORK") . "/samples206.json"), true);
$out = [];
foreach ($samples as $doc) {
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
    $out[] = ["texts" => $texts, "classes" => $classes, "html_has_style" => (strpos($html, "style=") !== false)];
}
file_put_contents(getenv("WORK") . "/php206.json", json_encode($out, JSON_UNESCAPED_UNICODE));
echo count($out);
' > "$WORK/php206.txt" 2>&1
expect "PHP 렌더러 — 표본 30개(팔레트 10 + 3단계 C:T-19 표본 20)를 그렸다" "30" "$(cat "$WORK/php206.txt")"
expect "  pal-brand-1 · pal-brand-1-bg · pal-c7d2-old · pal-c7d2-old-bg · pal-bg · pal-bg-bg 클래스가 나온다" "True" \
  "$(python3 -c "import json;a=json.load(open('$WORK/php206.json'));s=set(c for x in a for c in x['classes']);print(all(k in s for k in ['pal-brand-1','pal-brand-1-bg','pal-c7d2-old','pal-c7d2-old-bg','pal-bg','pal-bg-bg']))")"
expect "  모양 밖 값(pal:x-bg · pal:Brand · palette:x · pal:a_bg_bg)은 클래스 없음" "True" \
  "$(python3 -c "import json;a=json.load(open('$WORK/php206.json'));s=set(c for x in a for c in x['classes']);print(not any(k in s for k in ['pal-x-bg','pal-x','pal-Brand','pal-a-bg-bg','pal-a','palette-x']))")"
expect "  인라인 style 0" "False" "$(python3 -c "import json;a=json.load(open('$WORK/php206.json'));print(any(x['html_has_style'] for x in a))")"

# ════════════════════════════════════════════════════════════════
hr "C:T-207  감사 — add·update·remove 각 allow 1행 · 상세 · 그 밖의 행 0"
expect "재인증(15분 창 — 앞 단계가 길었다) → 200" "200" "$(reauth_admin)"
m0=$(mark)
expect "c7d2-audit 더하기 → 201" "201" "$(pal_add claude_admin01 c7d2-audit '#AA5500' '#ffaa66')"
PA=$(pid_)
expect "  감사 행 1 = palette.add allow · target palette/c7d2-audit" "1 1" "$(rows_since "$m0") $(psqlq "SELECT count(*) FROM audit_logs WHERE id > $m0 AND action='palette.add' AND result='allow' AND target_type='palette' AND target_name='c7d2-audit' AND target_id='$PA'")"
expect "  detail name·light(소문자)·dark" "c7d2-audit #aa5500 #ffaa66" "$(psqlq "SELECT (detail->>'name') || ' ' || (detail->>'light') || ' ' || (detail->>'dark') FROM audit_logs WHERE id > $m0 AND action='palette.add'")"
contains "  요약 문장(문장 표)" "c7d2-audit" "$(psqlq "SELECT summary FROM audit_logs WHERE id > $m0 AND action='palette.add'")"
m1=$(mark)
expect "c7d2-audit 고치기 → 200" "200" "$(capi PATCH "/api/palettes/$PA" claude_admin01 <(printf '{"dark":"#FFBB77"}'))"
expect "  감사 행 1 = palette.update allow · before 는 옛 값" "1 c7d2-audit #aa5500 #ffbb77 #aa5500 #ffaa66" \
  "$(rows_since "$m1") $(psqlq "SELECT (detail->>'name') || ' ' || (detail->>'light') || ' ' || (detail->>'dark') || ' ' || (detail->'before'->>'light') || ' ' || (detail->'before'->>'dark') FROM audit_logs WHERE id > $m1 AND action='palette.update' AND result='allow'")"
m2=$(mark)
expect "c7d2-audit 빼기 → 200" "200" "$(pal_rm claude_admin01 c7d2-audit)"
expect "  감사 행 1 = palette.remove allow" "1 1" "$(rows_since "$m2") $(audit_n "$m2" palette.remove allow)"
expect "  세 행 모두 행위자 claude_admin01 · UA 검토 표식" "3" "$(psqlq "SELECT count(*) FROM audit_logs WHERE id > $m0 AND action LIKE 'palette.%' AND actor_name='claude_admin01' AND user_agent LIKE 'tbox-review/%'")"
expect "  세 행 detail 에 본문·비밀 칸 0(비밀·본문 이름의 키 0 · 값에 문서 글자 0)" "0 0" \
  "$(psqlq "SELECT count(*) FROM audit_logs a, jsonb_object_keys(a.detail) k WHERE a.id > $m0 AND a.action LIKE 'palette.%' AND k ~* '(pass|token|cookie|secret|session|hash|csrf|otp|mfa|doc|text|body|plain)'") $(psqlq "SELECT count(*) FROM audit_logs WHERE id > $m0 AND action LIKE 'palette.%' AND detail::text ~ '(색을 입힐|브랜드|schema)'")"
psqlq "SELECT DISTINCT k FROM audit_logs a, jsonb_object_keys(a.detail) k WHERE a.id > $m0 AND a.action LIKE 'palette.%' ORDER BY 1" | tr '\n' ' ' | sed 's/^/  · detail 키: /'; echo
m3=$(mark)
capi GET /api/palettes claude_employee01 > /dev/null; css_get claude_guest01 > /dev/null; page_html /community/ claude_employee01 > /dev/null
expect "목록·CSS·화면 읽기는 감사 행 0" "0" "$(psqlq "SELECT count(*) FROM audit_logs WHERE id > $m3 AND (action LIKE 'palette.%' OR target_type='palette')")"

# ════════════════════════════════════════════════════════════════
hr "C:T-208  브라우저 — browser_7d2.py (관리 창 · 편집기 · 읽기 화면 계산 색 · 직원·손님 · 폭 × 테마) · ui_snapshot 결함 수"
export PATH="$HOME/.local/bin:$PATH"
if ! python3 -c 'import playwright' 2>/dev/null; then
  skip "브라우저" "playwright 가 없다 — **미검증**"
else
  expect "재인증(브라우저 단계 — 15분 창) → 200" "200" "$(reauth_admin)"
  # 편집기 시험용 페이지(직원) · 손님이 읽을 공개 게시판 글
  PE=$(make_page claude_employee01 'C7D2-편집기 색 줄')
  expect "  편집기 페이지 첫 저장 → 200" "200" "$(put_doc claude_employee01 "$PE" "[P('색을 입힐 글자'), P('둘째 줄')]")"
  jbody "{'name': 'C7D2-공개게시판', 'board_kind': 'discussion', 'access_level': 'public'}"
  capi POST /api/boards claude_admin01 "$WORK/body.json" > /dev/null
  B_PUB=$(jget "d['board']['id']" < "$WORK/b")
  jbody "{'title': 'C7D2-공개 글'}"
  capi POST "/api/boards/${B_PUB:-0}/pages" claude_employee01 "$WORK/body.json" > /dev/null
  GP=$(jget "d['page']['id']" < "$WORK/b")
  put_doc claude_employee01 "$GP" "[P('손님이 읽는 글', m={'color':'pal:brand-1'})]" > /dev/null
  note "편집기 페이지 $PE · 공개 게시판 ${B_PUB:-?} 글 ${GP:-?}"
  mkdir -p "$SHOTS"
  A1=$(cookie_of claude_admin01); E1=$(cookie_of claude_employee01); G1=$(cookie_of claude_guest01)
  # 세션 쿠키 셋은 표준입력 세 줄로(명령 인자·환경 변수에 싣지 않는다 — R-62)
  run_browser() {
    py_secret A1 E1 G1 -- python3 "$ROOT/tests/community/browser_7d2.py" "$SITE" "$UA" "$WORK" "$PE" "$GP" "$SHOTS" > "$WORK/smoke.txt" 2>&1
  }
  browser_lock_on
  run_browser
  sed 's/^/    /' "$WORK/smoke.txt"
  expect "브라우저 실패 0" "0" "$(sed -n 's/^SMOKE_FAIL=//p' "$WORK/smoke.txt" | tail -1)"
  expect "C:T-206 두 렌더러의 텍스트 노드·클래스 집합이 같다(JS 쪽)" "DIFF=0" "$(grep '^DIFF=' "$WORK/smoke.txt" | tail -1)"
  cp "$WORK/smoke.txt" "$SHOTS/browser_7d2.txt" 2>/dev/null

  sub "ui_snapshot.sh 1440·1280 — 결함 수 = 기준 판($BASE_REV · 같은 임시 DB · 포트 $PORT_BASE)"
  if git -C "$ROOT" rev-parse -q --verify "$BASE_REV^{commit}" >/dev/null 2>&1; then
    INST_BASE="$(mktemp -d)"; mkdir -p "$INST_BASE/app"
    git -C "$ROOT" archive "$BASE_REV" | tar -C "$INST_BASE/app" -xf -
    cp "$INST/app/.env" "$INST_BASE/app/.env"; chmod 600 "$INST_BASE/app/.env"
    [ "$(sed -n 's/^DB_NAME=//p' "$INST_BASE/app/.env")" = "$DB" ] || { echo "기준 판 .env 가 임시 DB 가 아니다 — 중단"; exit 3; }
    ln -s "$PLAN_DIR" "$INST_BASE/community" 2>/dev/null
    write_router "$INST_BASE/app"
    ( cd "$INST_BASE/app" && exec php -S "127.0.0.1:$PORT_BASE" -t public router.php > "$INST_BASE/server.log" 2>&1 ) &
    SERVER_BASE_PID=$!
    for _ in 1 2 3 4 5 6 7 8 9 10; do sleep 1; [ "$(curl -s -o /dev/null -w '%{http_code}' -A "$UA" "http://127.0.0.1:$PORT_BASE/community/api/pages")" = "401" ] && break; done
    snap() {    # $1 앱 폴더 $2 포트 $3 폭 → 합계
      # 새 TOTP 창까지 기다린다 — 같은 30초 창의 코드를 다시 내면 로그인이 한 번 거부되고, 그 거부 행의 요약 문장(「…2단계 인증…」)이
      # 감사 화면에 떠 결함 수(stage_text)가 **데이터에 따라** 흔들린다(첫 실행 2026-09-27 18:4x 에서 겪음). 창을 비키면 네 번 모두 같은 조건
      sleep $(( 30 - $(date +%s) % 30 + 1 ))
      local s="$WORK/ui_snapshot_$2.sh"
      sed "s#http://127.0.0.1:8082#http://127.0.0.1:$2#g" "$ROOT/scripts/ui_snapshot.sh" > "$s"
      grep -v '^\s*#' "$s" | grep -q '8082' && { echo "운영주소"; return; }
      # 복사본은 원본 자리의 ui_snapshot.py·tests/lib 를 쓴다(HERE·ROOT 계산을 원본 폴더로)
      sed -i "s#^HERE=.*#HERE=\"$1/scripts\"#" "$s"
      bash "$s" "$WORK/snap_$2_$3" "$3" > "$WORK/snap_$2_$3.txt" 2>&1
      sed -n 's/^합계 \([0-9]*\)건$/\1/p' "$WORK/snap_$2_$3.txt" | tail -1
    }
    for w in 1440 1280; do
      nb=$(snap "$INST_BASE/app" "$PORT_BASE" "$w"); nn=$(snap "$INST/app" "$PORT" "$w")
      expect "ui_snapshot $w — 결함 합계 기준 판 = 이번 판" "${nb:-?}" "${nn:-?}"
    done
    kill "$SERVER_BASE_PID" 2>/dev/null; wait "$SERVER_BASE_PID" 2>/dev/null; SERVER_BASE_PID=""
    cp "$WORK"/snap_*.txt "$SHOTS/" 2>/dev/null
  else
    skip "ui_snapshot 기준 판 비교" "기준 판 $BASE_REV 가 이 저장소에 없다"
  fi
  browser_lock_off
fi

# ════════════════════════════════════════════════════════════════
hr "회귀 — 금지 패턴 · 문법 · 부품 버전 · 허용 목록 밖 변경"
out=$(bash "$ROOT/scripts/security_lint.sh" 2>&1 | tail -3)
contains "security_lint 0건" "0건" "$out"
bad=0
for f in $(cd "$ROOT" && git ls-files 'src/**.php' 'public/index.php' 2>/dev/null) src/community/Palette.php src/community/handlers/palettes_list.php src/community/handlers/palettes_add.php src/community/handlers/palettes_update.php src/community/handlers/palettes_remove.php src/community/handlers/palette_css.php; do
  [ -f "$ROOT/$f" ] || continue
  php -l "$ROOT/$f" > /dev/null 2>&1 || { echo "  ✖ php -l 실패: $f"; bad=$((bad+1)); }
done
expect "php -l 전부 통과" "0" "$bad"
expect "커뮤니티 부품 버전 --check" "0" "$(cd "$ROOT" && php scripts/community_asset_version.php --check >/dev/null 2>&1; echo $?)"
expect "JS 구문(esprima) — palettes·doc-render·editor-schema·editor" "ok" \
  "$(python3 -c "
import esprima,sys
for f in ['palettes.js','doc-render.js','editor-schema.js','editor.js']:
    esprima.parseModule(open('$ROOT/public/assets/community/'+f,encoding='utf-8').read())
print('ok')" 2>&1 | tail -1)"
bash -n "$ROOT/tests/community/stage7d2.sh" && expect "stage7d2.sh 문법" "0" "0"
python3 -c "import ast,io;ast.parse(io.open('$ROOT/tests/community/browser_7d2.py',encoding='utf-8').read())" && expect "browser_7d2.py 문법" "0" "0"
expect "커뮤니티 코드에 역할 문자열 비교 0" "0" \
  "$(grep -rnE "'(Admin|Manager|Employee|Guest)'" "$ROOT/src/community" | grep -vE '^[^:]+:[0-9]+:\s*(\*|//|/\*)' | wc -l | tr -d ' ')"
expect "편집기·렌더러에 색값(#rrggbb) 0 — doc-render.js · editor-schema.js · editor.js" "0" \
  "$(grep -cE "#[0-9a-fA-F]{6}\b" "$ROOT/public/assets/community/doc-render.js" "$ROOT/public/assets/community/editor-schema.js" "$ROOT/public/assets/community/editor.js" | awk -F: '{s+=$2} END {print s+0}')"
expect "color-mix() 0 · 팔레트 CSS 를 자산 폴더에 파일로 쓰기 0" "0 0" \
  "$(grep -rc 'color-mix' "$ROOT/public/assets/community" | awk -F: '{s+=$2} END {print s+0}') $(grep -rnE "file_put_contents|fopen\(" "$ROOT/src/community/Palette.php" "$ROOT/src/community/handlers/palette_css.php" "$ROOT/src/community/handlers/palettes_"*.php | wc -l | tr -d ' ')"
expect "렌더러 두 벌은 팔레트 표를 읽지 않는다(목록을 모른다 — P-10)" "0" \
  "$(grep -cE 'palette_rows|palette_names|/api/palettes|community\.palettes' "$ROOT/src/community/DocRender.php" "$ROOT/public/assets/community/doc-render.js" | awk -F: '{s+=$2} END {print s+0}')"
if git -C "$ROOT" rev-parse -q --verify "$BASE_REV^{commit}" >/dev/null 2>&1; then
  ALLOWED='^(db/migrations/2026-10-07_community_palettes\.sql|db/community/schema\.sql|db/community/grants\.sql|deploy/install\.sh|src/community/Doc\.php|src/Audit\.php|src/community/routes\.php|src/community/Palette\.php|src/community/handlers/(palettes_list|palettes_add|palettes_update|palettes_remove|palette_css|pages_update|pages_create|board_pages_create|version_restore|templates_create|home_page|page_view|board_view)\.php|src/community/DocRender\.php|src/views/shell_top\.php|public/assets/community/(doc-render|editor-schema|editor|palettes|templates|database|embeds|popular|drafts)\.js|public/assets/community/community\.css|tests/community/stage7d2\.sh|tests/community/browser_7d2\.py|tests/catalog\.json|SECURITY\.md|DESIGN\.md|STAGES\.md|ARCHITECTURE\.md|API\.md|README\.md|src/help/community\.php|wiki/community/stage-7-d\.json|wiki/community/index\.json|review-scripts/(apply-7d2-db-2026-09-27|apply-7d2-repo-2026-09-27|pins-7d2-repo)\.sh)$'
  OUTSIDE=$( { git -C "$ROOT" diff --name-only "$BASE_REV"; git -C "$ROOT" ls-files --others --exclude-standard; } | sort -u | grep -vE "$ALLOWED" || true)
  # 운영 판(PROD_REV)을 이 묶음 위에 합친 트리면 — 기준 판 대비 바뀐 파일 가운데 운영 판과 **같은** 것은 운영 쪽(발행·정리 묶음)이
  #   가져온 것이다. 두 판 **모두**와 다른 파일만 센다(합치지 않은 복제본 · PROD_REV 가 없는 트리에서는 전과 같다)
  if [ -n "$OUTSIDE" ] && git -C "$ROOT" merge-base --is-ancestor "$PROD_REV" HEAD 2>/dev/null; then
    OUTSIDE=$(LC_ALL=C comm -12 <(printf '%s\n' "$OUTSIDE" | LC_ALL=C sort -u) \
      <( { git -C "$ROOT" diff --name-only "$PROD_REV"; git -C "$ROOT" ls-files --others --exclude-standard; } | LC_ALL=C sort -u) || true)
    note "허용 목록 밖 검사 — 합친 운영 판 $PROD_REV 과도 다른 파일만(운영 쪽이 가져온 파일은 뺌)"
  fi
  expect "허용 목록(지시서 1.6 · 부품 import 버전 다시 찍기 · 반영 스크립트) 밖 변경 0" "" "$OUTSIDE"
fi

# ════════════════════════════════════════════════════════════════
if [ "${REGRESS:-0}" = "1" ]; then
  hr "회귀 — 같은 임시 인스턴스에서(8082 → 임시로 sed 치환 · writes_prod 0)"
  note "각 스크립트를 임시 인스턴스 폴더로 복사해 SITE·DB 만 바꿔 돌린다(운영 8082·tbox_re 에 닿지 않는다)."
  sed -i "s#http://127.0.0.1:8082#$SITE#g" "$INST/app/scripts/ui_snapshot.sh"
  if grep -v '^\s*#' "$INST/app/scripts/ui_snapshot.sh" | grep -q '8082'; then
    echo "  ✖ ui_snapshot.sh 에 운영 주소가 남았다 — 회귀를 돌리지 않는다"; FAIL=$((FAIL+1)); REGRESS_LIST=" "
  fi
  browser_lock_on
  for s in ${REGRESS_LIST:-community/stage3 help_button api_whoami api_read api_folders q_launcher f68}; do
    src="$INST/app/tests/$s.sh"
    dst="$INST/app/tests/$s.temp.sh"
    [ -f "$src" ] || { echo "  ✖ $s — 스크립트가 없다"; FAIL=$((FAIL+1)); continue; }
    sed -e "s#http://127.0.0.1:8082#$SITE#g" -e "s#^DB=\"tbox_re\"#DB=\"$DB\"#" -e "s#-d tbox_re #-d $DB #g" "$src" > "$dst"
    if grep -v '^\s*#' "$dst" | grep -q '8082\|DB="tbox_re"\|-d tbox_re'; then
      echo "  ✖ $s — 운영 주소·DB 가 남아 있어 돌리지 않는다: $(grep -n '8082\|tbox_re' "$dst" | head -2 | tr '\n' ' ')"; FAIL=$((FAIL+1)); continue
    fi
    echo "  ▸ $s (시작 $(date +%T))"
    bash "$dst" > "$WORK/regress_$(basename "$s").txt" 2>&1
    rc=$?
    line=$(grep -E '통과 [0-9]+|PASS|통과:|결과' "$WORK/regress_$(basename "$s").txt" | tail -1)
    echo "    종료 코드 $rc · ${line:-결과 줄 없음}"
    grep -E '^\s*(✖|FAIL)' "$WORK/regress_$(basename "$s").txt" | head -12 | sed 's/^/      /'
    [ -n "${REGRESS_KEEP:-}" ] && cp "$WORK/regress_$(basename "$s").txt" "$REGRESS_KEEP/"
    [ "$rc" = "0" ] && PASS=$((PASS+1)) || FAIL=$((FAIL+1))
  done
  browser_lock_off
fi

# ════════════════════════════════════════════════════════════════
hr "결과"
printf '통과 %d · 실패 %d · 건너뜀 %d\n' "$PASS" "$FAIL" "$SKIP"
note "임시 DB($DB)·임시 폴더·임시 서버는 이 스크립트가 끝나며 지워진다. 운영에는 아무것도 남지 않는다."
[ "$FAIL" -eq 0 ] || exit 1
exit 0
