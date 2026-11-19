#!/usr/bin/env bash
# tests/community/stage7d4.sh — 7-D4 수락 기준 C:T-220 ~ C:T-227 실행 (글 안 멘션 — C:F-15 마크 `mention` · 결정 J-7 · L-4)
#
#   C:T-220 스키마·검증기  사본 = 원본(원본에 넣을 한 줄 diff 를 적용한 사본과도) · 단계 7 문서에 user·page·date 200 · Doc::validate(…, 6) 단계 밖 ·
#                          kind·id 모양 · 키 수 · 글자가 「@」 가 아님 · 링크와 함께 400 · bold·color 와 함께 200 · 오류 경로 blocks[i].text[j].m.mention
#   C:T-221 사람 이름      직원이 보는 활성 직원 「이름 (아이디)」 · 이름 없는 계정 「아이디」 · 비활성 키 없음 · 손님은 사람 키 0(users 조회 0 — PDO 계측) ·
#                          자기 자신도 같은 규칙 · 라벨에 「@」 없음 · 대상이 손님 계정 → 키 없음(검토 반영 — 대상도 쓰기 자격 · 댓글 @ 후보와 같은 범위)
#   C:T-222 페이지 전수    페이지 아홉(비공개·팀·사내·게시·숨김·보기 공유·팀 공간·지운 것·없는 UUID) × 계정 5 = 45 사례 —
#                          mention_labels == community_link_titles == 목록 조건절(community_visible_pages_where) · 검색은 관리자 아닌 넷은 목록 조건절과 같고
#                          관리자는 특권·게시판 관리자 갈래만 빠진 집합(메인 결정 2026-09-27) · 표 원문을 찍는다
#   C:T-223 음성           없는 것 · 못 보는 것 · 손님 계정 대상 — 200 · 지도에 키 없음 · 본문 어디에도 그 제목·이름 0 · 응답이 id 자리만 빼고 글자 그대로 같고 크기도 같다 ·
#                          저장 때 존재 검사 없음 · 감사에 대상 id·제목 0
#   C:T-224 저장·파생      저장된 doc 의 멘션 글자 = 「@」 · plain_text·search_tsv(검색)·page_versions·page_blocks.excerpt 에 이름·제목 0 ·
#                          notifications·page_links 전후 같음 · 되돌리기 뒤 마크 그대로
#   C:T-225 렌더러 동등    DocRender(PHP) 와 doc-render.js 의 텍스트 노드 열·클래스 집합·href·target — PHP 쪽은 여기, JS 쪽은 browser_7d4.py ·
#                          「볼 수 없음」 두 벌 같은 상수 · stage3 C:T-19 표본 24 도 함께
#   C:T-226 템플릿         멘션 든 글 → 템플릿 본문에 mention 0 · 「@」 남음 · 템플릿에서 만든 페이지도 0 · 템플릿 응답에 mention_labels 없음
#   C:T-227 브라우저       browser_7d4.py — 편집기 「멘션」(사람·페이지·날짜) · 저장 · 읽기 화면 · 링크 이동 · 뒤에 친 글자 · Backspace · HTML 붙여넣기 ·
#                          알림 0 · 못 보는 사람 · 손님 · 툴바 한 줄 320 이하 · 360·412(터치·마우스)·820·1280 × 라이트·다크 + 고대비 한 조합
#
# ★ 운영 DB 를 건드리지 않는다 — stage7d2.sh 와 같은 틀(임시 DB `tbox_7d4_test`, 포트 8109) · 검토 계정 claude_(F-71).
#   운영 tbox_re 는 pg_dump 로 **읽기만** 한다(파이프 — 덤프 파일 0). 임시 `.env` 세 줄(DB_NAME·STORAGE_DIR·AUDIT_LOG_FILE)이 임시를
#   가리키는지 서버를 띄우기 **전에** 보고, 아니면 exit 3(2026-09-26 사고 셋). 임시 DB 에는 7-D2 마이그레이션(팔레트)을 붓는다(운영 DB 에는 아직 없다).
# 쓰는 법: bash tests/community/stage7d4.sh   (REGRESS=1 이면 끝에 회귀 목록을 같은 임시 인스턴스에서 — 8082 → 임시로 sed 치환)
#          환경 SHOTS=<폴더>(기본 ~/.claude/ui-shots/7d4) · BROWSER_LOCK=<잠금 파일>(있으면 flock -x 아래에서 브라우저 단계) ·
#          ORIGIN_DIFF=<원본 doc-schema.json 에 넣을 한 줄 diff>(기본 ~/.local/share/qaredi-dev/7d4-doc-schema-origin.diff — 원본이 아직 옛 줄일 때 대조용)
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
. "$ROOT/tests/lib/secrets.sh" || { echo "tests/lib/secrets.sh 를 읽지 못했다 — 시험을 시작하지 않는다 (R-62)"; exit 3; }
PORT=8109
SITE="http://127.0.0.1:$PORT"
BASE="$SITE/tbox"
CBASE="$SITE/community"
UA="tbox-review/$(date +%F)"
SRC_DB="tbox_re"          # 운영 — **읽기만** 한다
DB="tbox_7d4_test"        # 임시 — 여기에만 쓴다
BASE_REV="${BASE_REV:-620e0dc}"            # 이 라운드의 기준 판(7-D2 검토 통과판 review-fix/7-D2) — 허용 목록 밖 검사
SHOTS="${SHOTS:-$HOME/.claude/ui-shots/7d4}"
ORIGIN_DIFF="${ORIGIN_DIFF:-$HOME/.local/share/qaredi-dev/7d4-doc-schema-origin.diff}"
JAR_DIR="$(mktemp -d)"
WORK="$(mktemp -d)"
INST=""
SERVER_PID=""
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

cleanup() {
  if [ -n "$SERVER_PID" ]; then kill "$SERVER_PID" 2>/dev/null; wait "$SERVER_PID" 2>/dev/null; fi
  ( cd / && sudo -n -u postgres dropdb --if-exists "$DB" >/dev/null 2>&1 )
  [ -n "$INST" ] && rm -rf "$INST"
  rm -rf "$JAR_DIR" "$WORK"
}
trap cleanup EXIT

# ─── 임시 DB 에만 쓰는 psql (운영 DB 이름은 여기에 들어가지 않는다) ───
#   SQL 은 표준입력으로(명령 인자 -c 0 — sudo 명령 줄이 한 모양이라 운영 경보 AL-9 가 한 줄로 묶는다 · 2026-09-26 취합 규칙)
psqlq() { ( cd / && printf '%s\n' "$1" | sudo -n -u postgres psql -X -q -d "$DB" -t -A 2>/dev/null ); }
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
errs() { jget "' '.join(e['reason'] for e in d.get('errors', []))" < "$WORK/b"; }
errpaths() { jget "' '.join(e['path'] for e in d.get('errors', []))" < "$WORK/b"; }
pg_page() { psqlq "SELECT $2 FROM community.pages p WHERE p.public_id = '$1'"; }
uid() { psqlq "SELECT id FROM users WHERE username='$1'"; }
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

# 블록 식을 문서로 — 파이썬 도우미: P(세그먼트 목록) · S(글, m=) · MU(사용자 id, m=) · MP(페이지 id) · MD(날짜) · H(세그먼트 목록)
DOCPY='
import json, sys, uuid
def u(): return str(uuid.uuid4())
def S(t, m=None): return {"t": t, "m": dict(m or {})}
def M(kind, id, extra=None, t="@"):
    m = {"mention": {"kind": kind, "id": id}}
    m.update(extra or {})
    return {"t": t, "m": m}
def MU(i, extra=None): return M("user", i, extra)
def MP(i, extra=None): return M("page", i, extra)
def MD(i, extra=None): return M("date", i, extra)
def P(segs, bid=None): return {"id": bid or u(), "type": "paragraph", "text": list(segs)}
def H(segs): return {"id": u(), "type": "heading", "level": 2, "text": list(segs)}
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
# 5분 합치기(4-C)를 비켜 새 스냅샷이 남게 — 그 페이지의 판 시각을 10분 앞으로(임시 DB 에만)
age_versions() { psqlq "UPDATE community.page_versions v SET edited_at = edited_at - interval '10 minutes' FROM community.pages p WHERE p.id=v.page_id AND p.public_id='$1'" > /dev/null; }
cookie_of() {
  python3 - "$JAR_DIR/$1.jar" <<'PY2'
import sys
for line in open(sys.argv[1], encoding='utf-8', errors='replace'):
    parts = line.rstrip('\n').split('\t')
    if len(parts) == 7 and parts[5] == '__Host-tbox_session':
        print(parts[6]); break
PY2
}
labels_of() {   # $1 계정 $2 페이지 → mention_labels 를 정렬한 JSON 한 줄(없으면 빈 글자)
  capi GET "/api/pages/$2" "$1" > "$WORK/st"
  jget "json.dumps(d.get('mention_labels'), ensure_ascii=False, sort_keys=True)" < "$WORK/b"
}

# ════════════════════════════════════════════════════════════════
hr "7-D4 — 임시 인스턴스 준비 (운영 DB 에는 쓰지 않는다)"

avail_kb=$(df -Pk / | awk 'NR==2{print $4}')
echo "루트 파티션 여유: $(df -Ph / | awk 'NR==2{print $4}') (${avail_kb}KB)"
[ "$avail_kb" -ge 1048576 ] || { echo "루트 디스크 여유 1GB 미만 — 시험을 시작하지 않는다 (SECURITY R-80)"; exit 3; }
( cd / && printf 'SELECT 1;\n' | sudo -n -u postgres psql -X -q -d "$SRC_DB" -tA >/dev/null 2>&1 ) \
  || { echo "psql(postgres) 을 쓸 수 없다 — 이 시험은 임시 DB 가 없으면 성립하지 않는다"; exit 3; }
if ss -ltn 2>/dev/null | grep -qE "127\.0\.0\.1:$PORT "; then
  echo "포트 $PORT 이 이미 쓰이고 있다 — 앞선 시험이 남긴 서버를 먼저 내린다"; exit 3
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
echo "  · 임시 DB $DB — 계정 ${n_users}명 · 페이지 ${n_pages}장"

sub "② 마이그레이션 (임시 DB 에만) — 7-D2 팔레트(이 판에 쌓인 표 · 운영 DB 에는 아직 없다) · grants.sql · 7-D4 는 DB 변경 0"
( cd / && sudo -n -u postgres psql -q -d "$DB" -v ON_ERROR_STOP=1 -f - ) < "$ROOT/db/migrations/2026-10-07_community_palettes.sql" 2>&1 | sed 's/^/    /'
( cd / && sudo -n -u postgres psql -q -d "$DB" -v ON_ERROR_STOP=1 -f - ) < "$ROOT/db/community/grants.sql" 2>&1 | sed 's/^/    /'
expect "팔레트 표가 임시 DB 에 있다(7-D2 판 위)" "community.palettes" "$(psqlq "SELECT coalesce(to_regclass('community.palettes')::text, '없음')")"
expect "7-D4 는 마이그레이션 0 — db/ 변경 0(기준 판 $BASE_REV 대비)" "0" "$(git -C "$ROOT" diff --name-only "$BASE_REV" -- db | wc -l | tr -d ' ')"

sub "③ 저장소를 임시 폴더로 복사하고 인스턴스를 띄운다"
INST="$(mktemp -d)"
mkdir -p "$INST/app" "$INST/storage" "$INST/log"
tar -C "$ROOT" -cf - --exclude=.git --exclude=jar --exclude=api-test.txt --exclude=./.env . | tar -C "$INST/app" -xf -
# 임시 `.env` — 원본 `.env` 에서 **DB 이름·저장 경로·로그 경로만** 바꾼다. 값은 출력하지 않는다.
#   원본은 이 트리의 .env — 복제본에는 .env 가 없으므로 부르는 쪽이 0600 사본을 트리 뿌리에 두고 부른다(끝나면 지운다).
ENV_SRC="${QCHECK_ENV_SRC:-$ROOT/.env}"
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
# 기획 폴더(doc-schema.json 원본) — 부르는 쪽이 원본에 넣을 한 줄을 적용한 사본 폴더를 `$ROOT/../community` 에 둘 수 있다(원본은 사이트 저장소라 이 라운드가 쓰지 않는다)
PLAN_DIR="${PLAN_OVERRIDE:-$(cd "$ROOT/.." && pwd)/community}"
[ -f "$PLAN_DIR/doc-schema.json" ] || PLAN_DIR=/var/www/qaredi/community
ln -s "$PLAN_DIR" "$INST/community" 2>/dev/null
note "기획 폴더(원본 비교 자리): $PLAN_DIR"

cat > "$INST/app/router.php" <<'PHP'
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
U_E1=$(uid claude_employee01); U_E2=$(uid claude_employee02); U_M1=$(uid claude_manager01); U_A1=$(uid claude_admin01); U_G1=$(uid claude_guest01)
note "계정 번호: employee01=$U_E1 employee02=$U_E2 manager01=$U_M1 admin01=$U_A1 guest01=$U_G1"
# 이름(표시 이름) — 임시 DB 에서만: employee02 에게 이름을 주고 나머지는 없음(「이름 (아이디)」 · 「아이디」 둘 다 보려고)
psqlq "UPDATE users SET display_name = NULL WHERE username IN ('claude_employee01','claude_manager01','claude_admin01','claude_guest01')" > /dev/null
psqlq "UPDATE users SET display_name = '검토이칠디사' WHERE username = 'claude_employee02'" > /dev/null
NAME_E2='검토이칠디사'
T_TEAM1=$(psqlq "SELECT team_id FROM users WHERE username='claude_employee01'")
S_TEAM2=$(psqlq "SELECT s.id FROM community.spaces s JOIN users u ON u.team_id = s.team_id WHERE s.kind='team' AND u.username='claude_employee02' LIMIT 1")
note "employee01 팀 ${T_TEAM1:-없음} · employee02 팀 공간 ${S_TEAM2:-없음}"

# ════════════════════════════════════════════════════════════════
hr "C:T-220  스키마·검증기 — 사본 = 원본 · 단계 7 · 모양 · 글자 「@」 · 링크와 함께 · 오류 경로"
STAGE=$(sed -n 's/^const COMMUNITY_DOC_STAGE = \([0-9]*\);.*/\1/p' "$ROOT/src/bootstrap.php")
expect "문서 단계(bootstrap 상수) 7 그대로 · bootstrap.php 변경 0" "7 0" "$STAGE $(git -C "$ROOT" diff --name-only "$BASE_REV" -- src/bootstrap.php | wc -l | tr -d ' ')"
expect "doc-schema.json 사본 stage_marks — mention 이 7 · 8 칸 없음 · marks.mention 그대로" "['mention'] None {'kind': ['user', 'page', 'date'], 'id': 'uuid|int|date'}" \
  "$(python3 -c "import json;d=json.load(open('$ROOT/public/assets/community/doc-schema.json'));print(d['stage_marks'].get('7'), d['stage_marks'].get('8'), d['marks']['mention'])")"
expect "doc-schema.json 사본의 변경은 그 한 줄뿐(기준 판 대비 +1 −1)" "1 1" \
  "$(git -C "$ROOT" diff --numstat "$BASE_REV" -- public/assets/community/doc-schema.json | awk '{print $1, $2}')"
expect "사본 = 기획 폴더 원본($PLAN_DIR) — diff 0" "0" "$(diff "$PLAN_DIR/doc-schema.json" "$ROOT/public/assets/community/doc-schema.json" >/dev/null 2>&1; echo $?)"
# 원본(사이트 저장소)이 아직 옛 줄이면 — 원본에 넣을 한 줄 diff 를 **적용한 사본**과 대조한다(원본 대신 · 지시문 3)
if diff /var/www/qaredi/community/doc-schema.json "$ROOT/public/assets/community/doc-schema.json" >/dev/null 2>&1; then
  expect "사이트 원본(/var/www/qaredi/community)이 이미 새 줄 — 사본과 diff 0" "0" "0"
elif [ -f "$ORIGIN_DIFF" ]; then
  cp /var/www/qaredi/community/doc-schema.json "$WORK/origin-schema.json"
  ( cd "$WORK" && patch -s --no-backup-if-mismatch origin-schema.json < "$ORIGIN_DIFF" ) > "$WORK/patch.out" 2>&1
  expect "사이트 원본 + 원본용 한 줄 diff($ORIGIN_DIFF) = 사본 — diff 0" "0 0" \
    "$([ -s "$WORK/patch.out" ] && echo 1 || echo 0) $(diff "$WORK/origin-schema.json" "$ROOT/public/assets/community/doc-schema.json" >/dev/null 2>&1; echo $?)"
  expect "  그 diff 는 한 줄 바꿈뿐(- \"8\": [\"mention\"] / + \"7\": [\"mention\"])" "1 1" \
    "$(grep -c '^-    "8": \["mention"\]$' "$ORIGIN_DIFF") $(grep -c '^+    "7": \["mention"\]$' "$ORIGIN_DIFF")"
else
  skip "사이트 원본 + 원본용 diff = 사본" "diff 파일 $ORIGIN_DIFF 가 없다"
fi

R0=$(make_page claude_employee01 'C7D4-검증기 시험')
note "검증기 시험 페이지 $R0"
expect "단계 7 문서에 멘션 셋(사람·페이지·날짜) → 200" "200" \
  "$(put_doc claude_employee01 "$R0" "[P([S('가 '), MU($U_E2), S(' '), MP('$R0'), S(' '), MD('2026-09-27')])]")"
ROOT="$ROOT" php -r '
require getenv("ROOT") . "/src/community/Doc.php";
$d = ["schema" => 1, "blocks" => [["id" => "7f0c1d2e-3a4b-4c5d-8e6f-0a1b2c3d4e5f", "type" => "paragraph", "text" => [["t" => "@", "m" => ["mention" => ["kind" => "user", "id" => 12]]]]]]];
$a = Doc::validate($d, 6); $b = Doc::validate($d, 7);
echo ($a["errors"][0]["reason"] ?? "ok") . " " . ($a["errors"][0]["path"] ?? "") . " " . ($b["ok"] ? "ok" : "bad");
' > "$WORK/v6.txt" 2>&1
expect "Doc::validate(문서, 6) → mark_not_in_stage(경로 …m.mention) · 7 → 통과(php -r)" "mark_not_in_stage blocks[0].text[0].m.mention ok" "$(cat "$WORK/v6.txt")"

sub "모양 밖 — 전부 400 (사유 · 경로)"
bad400() {   # $1 설명 $2 블록 식 $3 기대 사유
  local st; st=$(put_doc claude_employee01 "$R0" "$2")
  expect "$1 → 400 $3 · 경로 blocks[0].text[1].m.mention" "400 $3 blocks[0].text[1].m.mention" "$st $(errs) $(errpaths)"
}
bad400 'kind "team"'                 "[P([S('x'), M('team', 1)])]"                        bad_value
bad400 'user id "12"(문자열)'          "[P([S('x'), MU('12')])]"                            bad_type
bad400 'user id 0'                    "[P([S('x'), MU(0)])]"                               bad_value
bad400 'user id -1'                   "[P([S('x'), MU(-1)])]"                              bad_value
bad400 'user id 1.5'                  "[P([S('x'), MU(1.5)])]"                             bad_type
bad400 'user id 2⁵³+1'                "[P([S('x'), MU(9007199254740993)])]"               bad_value
expect "  (경계) user id 2⁵³ 는 통과 → 200" "200" "$(put_doc claude_employee01 "$R0" "[P([S('x'), MU(9007199254740992)])]")"
bad400 'page id 대문자'               "[P([S('x'), MP('$R0'.upper())])]"                    bad_value
bad400 'page id 35자'                 "[P([S('x'), MP('$R0'[:35])])]"                       bad_value
bad400 'page id UUID 모양 아님(36자 · 붙임표 자리 틀림)' "[P([S('x'), MP('0123456-789ab-cdef-0123-456789abcdef0')])]" bad_value
bad400 'page id 숫자'                 "[P([S('x'), MP(12)])]"                              bad_type
bad400 'date 2026-13-01'              "[P([S('x'), MD('2026-13-01')])]"                     bad_value
bad400 'date 2026-9-1'                "[P([S('x'), MD('2026-9-1')])]"                       bad_value
bad400 'date 2026-02-30'              "[P([S('x'), MD('2026-02-30')])]"                     bad_value
bad400 'date 1899-12-31(범위 밖)'     "[P([S('x'), MD('1899-12-31')])]"                     bad_value
bad400 '키 셋(label 더함 — 이름 저장 시도)' "[P([S('x'), {'t': '@', 'm': {'mention': {'kind': 'user', 'id': $U_E2, 'label': '$NAME_E2'}}}])]" unknown_key
bad400 '키 하나(kind 만)'             "[P([S('x'), {'t': '@', 'm': {'mention': {'kind': 'user'}}}])]" missing_key
bad400 '값이 객체가 아님("user:12")'  "[P([S('x'), {'t': '@', 'm': {'mention': 'user:12'}}])]" not_object
bad400 't "@홍길동"(이름 섞기)'       "[P([S('x'), M('user', $U_E2, t='@홍길동')])]"         bad_value
bad400 't "@@"'                       "[P([S('x'), M('user', $U_E2, t='@@')])]"              bad_value
bad400 't ""'                         "[P([S('x'), M('user', $U_E2, t='')])]"                bad_value
bad400 'link 마크와 함께'             "[P([S('x'), MU($U_E2, {'link': {'href': 'https://example.com/'}})])]" bad_value
expect "bold·color 와 함께 → 200" "200" "$(put_doc claude_employee01 "$R0" "[P([S('x'), MU($U_E2, {'bold': True, 'color': 'red'}), MD('2026-09-27', {'color': 'blue_bg'})])]")"
expect "표 칸·설명 안의 멘션도 모양 검사(표 칸 t='@이름' → 400 bad_value)" "400 bad_value" \
  "$(put_doc claude_employee01 "$R0" "[{'id': u(), 'type': 'table', 'header_row': False, 'header_col': False, 'rows': [{'cells': [{'text': [M('user', $U_E2, t='@이름')]}]}]}]") $(errs)"
expect "저장 창구가 남긴 감사는 page.update deny invalid_doc(새 감사 키 0 — 문장 행 추가 0)" "0" \
  "$(git -C "$ROOT" diff "$BASE_REV" -- src/Audit.php | wc -l | tr -d ' ')"

reauth_admin() {
  local pw rc
  pw=$(review_password claude_admin01)
  # 비밀번호는 표준입력 첫 줄로 파이썬에(py_secret) — 명령 인자에 싣지 않는다(R-62)
  rc=$(py_secret pw -- python3 -c "import json,sys;print(json.dumps({'password':sys.stdin.readline().rstrip('\n')}))" \
       | curl -s -o /dev/null -w '%{http_code}' -b "$JAR_DIR/claude_admin01.jar" -A "$UA" -H 'Content-Type: application/json' \
        -H "X-CSRF-Token: $CSRF_claude_admin01" --data-binary @- "$BASE/api/me/reauth")
  printf '%s' "$rc"
}

# 계측 — 앱 연결의 PDO 를 **SQL 을 적어 두는 PDO** 로 바꿔 끼운 뒤 함수를 부른다(임시 인스턴스 사본 · 자기 .env = 임시 DB · 읽기만).
#   인자: <앱 폴더> <계정> <멘션 JSON> → {"users_sql": users 표를 부른 문장 수, "all_sql": 전체 문장 수, "keys": [지도 키]}
cat > "$WORK/instrument.php" <<'PHP'
<?php
declare(strict_types=1);
$app = $argv[1];
require $app . '/scripts/lib/cli_bootstrap.php';
require_once $app . '/src/community/routes.php';
final class LogPDO extends PDO
{
    /** @var list<string> */
    public static array $sqls = [];
    #[\ReturnTypeWillChange]
    public function prepare(string $query, array $options = [])
    {
        self::$sqls[] = $query;
        return parent::prepare($query, $options);
    }
}
$db = Db::instance();
$rc = new ReflectionClass($db);
$val = static function (string $n) use ($rc, $db) { $p = $rc->getProperty($n); $p->setAccessible(true); return $p->getValue($db); };
$pdo = new LogPDO($val('dsn'), $val('user'), $val('pass'), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_STRINGIFY_FETCHES => false,
]);
$pp = $rc->getProperty('pdo'); $pp->setAccessible(true); $pp->setValue($db, $pdo);
$r = $db->run('SELECT id, username, role, team_id FROM public.users WHERE username = :u', [':u' => $argv[2]])->fetch();
$u = ['id' => (int) $r['id'], 'username' => (string) $r['username'], 'role' => (string) $r['role'], 'team_id' => $r['team_id'] === null ? null : (int) $r['team_id']];
LogPDO::$sqls = [];
$labels = community_mention_labels(json_decode($argv[3], true), $u);
$n = 0;
foreach (LogPDO::$sqls as $q) { if (preg_match('/\busers\b/', $q) === 1 && preg_match('/owner\.id = s\.owner_user_id/', $q) !== 1) { $n++; } }
echo json_encode(['users_sql' => $n, 'all_sql' => count(LogPDO::$sqls), 'keys' => array_keys($labels)], JSON_UNESCAPED_UNICODE);
PHP

# ════════════════════════════════════════════════════════════════
hr "픽스처 — 멘션을 모은 글(H) · 대상 페이지 아홉(C:T-222) · 공개 게시판"
N0=$(psqlq "SELECT count(*) FROM community.notifications")
L0=$(psqlq "SELECT count(*) FROM community.page_links")
note "시작 — 알림 ${N0}행 · page_links ${L0}행"
expect "관리자 재인증 → 200" "200" "$(reauth_admin)"
jbody "{'name': 'C7D4-공개게시판', 'board_kind': 'discussion', 'access_level': 'public'}"
capi POST /api/boards claude_admin01 "$WORK/body.json" > /dev/null
B_PUB=$(jget "d['board']['id']" < "$WORK/b")
note "공개 게시판 ${B_PUB:-?}"
vis() { jbody "{'visibility': '$3'}"; capi PATCH "/api/pages/$2/visibility" "$1" "$WORK/body.json"; }
T1=$(make_page claude_employee01 'C7D4짝-비공개')
T2=$(make_page claude_employee01 'C7D4짝-팀');      expect "  팀 범위 → 200" "200" "$(vis claude_employee01 "$T2" team)"
T3=$(make_page claude_employee01 'C7D4짝-사내');    expect "  사내 범위 → 200" "200" "$(vis claude_employee01 "$T3" internal)"
T4=$(make_page claude_employee01 'C7D4짝-게시');    jbody "{'board_id': int('${B_PUB:-0}')}"; expect "  공개 게시판에 게시 → 200" "200" "$(capi POST "/api/pages/$T4/publish" claude_employee01 "$WORK/body.json")"
T5=$(make_page claude_employee01 'C7D4짝-숨김');    expect "  게시 → 200" "200" "$(capi POST "/api/pages/$T5/publish" claude_employee01 "$WORK/body.json")"
expect "  관리자가 숨김 → 200" "200" "$(capi POST "/api/pages/$T5/hide" claude_admin01 <(printf '{}'))"
T6=$(make_page claude_employee01 'C7D4짝-공유');    jbody "{'level': 'view'}"; expect "  손님에게 보기 공유 → 200" "200" "$(capi PUT "/api/pages/$T6/shares/claude_guest01" claude_employee01 "$WORK/body.json")"
T7=$(make_page claude_employee02 'C7D4짝-팀공간' '' "${S_TEAM2:-0}")
T8=$(make_page claude_employee01 'C7D4짝-지움');    expect "  지움 → 200" "200" "$(capi DELETE "/api/pages/$T8" claude_employee01)"
T9=$(python3 -c "import uuid;print(uuid.uuid4())")
expect "  대상 아홉의 id 모양(없는 UUID 는 DB 에 없음)" "9 0" \
  "$(for t in "$T1" "$T2" "$T3" "$T4" "$T5" "$T6" "$T7" "$T8" "$T9"; do printf '%s\n' "$t"; done | grep -cE '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$') $(psqlq "SELECT count(*) FROM community.pages WHERE public_id='$T9'")"
NONEX_UID=$(psqlq "SELECT min(g) FROM generate_series(1000000001, 1000000100) g WHERE g NOT IN (SELECT id FROM users)")
H=$(make_page claude_employee01 'C7D4-멘션 모음')
expect "  모음 글 — 사내 범위 · 손님에게 보기 공유" "200 200" "$(vis claude_employee01 "$H" internal) $(jbody "{'level': 'view'}"; capi PUT "/api/pages/$H/shares/claude_guest01" claude_employee01 "$WORK/body.json")"
H_BLOCKS="[P([S('사람 '), MU($U_E2), S(' '), MU($U_M1), S(' '), MU($U_E1), S(' '), MU($NONEX_UID), S(' 날짜 '), MD('2026-09-27')]), P([S('페이지 '), MP('$T1'), MP('$T2'), MP('$T3'), MP('$T4'), MP('$T5'), MP('$T6'), MP('$T7'), MP('$T8'), MP('$T9')])]"
expect "모음 글 저장(사람 넷 · 날짜 · 페이지 아홉 — 없는 id 도 저장된다 M-5) → 200" "200" "$(put_doc claude_employee01 "$H" "$H_BLOCKS")"
note "H=$H · T1..T9 = $T1 $T2 $T3 $T4 $T5 $T6 $T7 $T8 $T9 · 없는 사용자 번호 $NONEX_UID"

# ════════════════════════════════════════════════════════════════
hr "C:T-221  사람 이름 — 보는 사람 쓰기 자격 + 대상 활성·쓰기 자격 · 「이름 (아이디)」 · 손님 0(users 조회 0) · 손님 대상 키 없음"
LAB=$(labels_of claude_employee01 "$H")
expect "직원(employee01)이 본 employee02 → 「이름 (아이디)」" "$NAME_E2 (claude_employee02)" "$(printf '%s' "$LAB" | jget "d.get('user:$U_E2', {}).get('label')")"
expect "  이름 없는 계정(manager01) → 「아이디」" "claude_manager01" "$(printf '%s' "$LAB" | jget "d.get('user:$U_M1', {}).get('label')")"
expect "  자기 자신(employee01)도 같은 규칙 → 「아이디」" "claude_employee01" "$(printf '%s' "$LAB" | jget "d.get('user:$U_E1', {}).get('label')")"
expect "  없는 사용자 번호 → 키 없음" "False" "$(printf '%s' "$LAB" | jget "'user:$NONEX_UID' in d")"
expect "  라벨 값에 「@」 없음 · 사람 라벨 칸은 label 하나" "0 True" \
  "$(printf '%s' "$LAB" | jget "sum(1 for v in d.values() if '@' in v.get('label', ''))") $(printf '%s' "$LAB" | jget "all(list(v.keys()) == ['label'] for k, v in d.items() if k.startswith('user:'))")"
psqlq "UPDATE users SET is_active = false WHERE id = $U_M1" > /dev/null
LAB_I=$(labels_of claude_employee01 "$H")
expect "비활성 계정(임시 DB 에서 manager01 is_active=false) → 키 없음 · 다른 사람 키는 그대로" "False True" \
  "$(printf '%s' "$LAB_I" | jget "'user:$U_M1' in d") $(printf '%s' "$LAB_I" | jget "'user:$U_E2' in d")"
psqlq "UPDATE users SET is_active = true WHERE id = $U_M1" > /dev/null
expect "  되살리면 다시 보인다" "True" "$(labels_of claude_employee01 "$H" | jget "'user:$U_M1' in d")"
for w in claude_employee02 claude_manager01 claude_admin01; do
  expect "  $w(쓰기 자격)도 같은 사람 라벨 셋" "3 $NAME_E2 (claude_employee02)" \
    "$(labels_of "$w" "$H" | jget "str(sum(1 for k in d if k.startswith('user:'))) + ' ' + d.get('user:$U_E2', {}).get('label', '')")"
done
LAB_G=$(labels_of claude_guest01 "$H")
expect "손님(guest01 — 공유로 보는 글) → 200 · 사람 키 0" "200 0" "$(cat "$WORK/st") $(printf '%s' "$LAB_G" | jget "sum(1 for k in d if k.startswith('user:'))")"
lacks "  손님이 받은 응답 본문에 employee02 의 이름·아이디 글자 0" "$NAME_E2" "$(cat "$WORK/b")"
lacks "  (아이디)" "claude_employee02" "$(cat "$WORK/b")"
MJ="[{\"kind\":\"user\",\"id\":$U_E2},{\"kind\":\"user\",\"id\":$U_M1},{\"kind\":\"date\",\"id\":\"2026-09-27\"}]"
IG=$(cd "$INST/app" && php "$WORK/instrument.php" "$INST/app" claude_guest01 "$MJ" 2>&1)
IE=$(cd "$INST/app" && php "$WORK/instrument.php" "$INST/app" claude_employee01 "$MJ" 2>&1)
note "계측(손님): $IG"
note "계측(직원): $IE"
expect "계측 — 손님: users 표를 부른 문장 0 · 지도 키 0" "0 0" "$(printf '%s' "$IG" | jget "str(d['users_sql']) + ' ' + str(len(d['keys']))")"
expect "계측 — 직원: users 표 문장 1(한 문장 · id = ANY) · 사람 키 둘" "1 2" "$(printf '%s' "$IE" | jget "str(d['users_sql']) + ' ' + str(len(d['keys']))")"
# 대상이 손님 계정 → 키 없음(검토 반영 — 대상도 `Authz::canCommunityWrite` · 댓글 @ 후보 decideMentionTarget·편집기 후보와 같은 범위)
GU=$(make_page claude_employee01 'C7D4-손님 대상')
vis claude_employee01 "$GU" internal > /dev/null
expect "손님 대상 글 저장(사내 · 사람 둘 — 손님 guest01 · 직원 employee02) → 200" "200" "$(put_doc claude_employee01 "$GU" "[P([S('손님 '), MU($U_G1), S(' 직원 '), MU($U_E2)])]")"
for w in claude_employee01 claude_employee02 claude_manager01 claude_admin01; do
  expect "  $w(쓰기 자격)가 본 손님 대상 → 키 없음 · 직원 대상은 그대로" "False True" \
    "$(labels_of "$w" "$GU" | jget "str('user:$U_G1' in d) + ' ' + str('user:$U_E2' in d)")"
done
labels_of claude_employee02 "$GU" > /dev/null
lacks "  그 응답 본문에 손님 아이디 글자 0" "claude_guest01" "$(cat "$WORK/b")"
MJG="[{\"kind\":\"user\",\"id\":$U_G1},{\"kind\":\"user\",\"id\":$U_E2}]"
IEG=$(cd "$INST/app" && php "$WORK/instrument.php" "$INST/app" claude_employee01 "$MJG" 2>&1)
note "계측(직원 · 손님 대상 포함): $IEG"
expect "계측 — 직원이 본 손님·직원 대상: users 표 문장 1(같은 한 문장 — 대상 자격은 PHP) · 키는 직원 하나" "1 ['user:$U_E2']" \
  "$(printf '%s' "$IEG" | jget "str(d['users_sql']) + ' ' + str(d['keys'])")"

# ════════════════════════════════════════════════════════════════
hr "C:T-222  페이지 전수 대조 — 9 × 5 = 45 사례 · mention_labels == community_link_titles == 목록 조건절 · 검색"
printf '%s\n' "$T1 비공개" "$T2 팀" "$T3 사내" "$T4 게시" "$T5 숨김" "$T6 보기공유" "$T7 팀공간" "$T8 지운것" "$T9 없는UUID" > "$WORK/targets.txt"
for w in claude_employee01 claude_employee02 claude_manager01 claude_admin01 claude_guest01; do
  labels_of "$w" "$H" > "$WORK/lab-$w.json"
  capi GET "/api/search?q=$(python3 -c "import urllib.parse;print(urllib.parse.quote('C7D4짝'))")" "$w" > "$WORK/sst-$w"
  cp "$WORK/b" "$WORK/search-$w.json"
done
cat > "$WORK/sets.php" <<'PHP'
<?php
declare(strict_types=1);
// 임시 인스턴스 사본에서 부른다(자기 .env = 임시 DB · 읽기만). 계정마다: link_titles 키 · 목록 조건절 · 검색 조건절 · 행 판정 코드
$app = $argv[1];
require $app . '/scripts/lib/cli_bootstrap.php';
require_once $app . '/src/community/routes.php';
$ids = array_map(static fn ($l) => explode(' ', $l)[0], array_filter(explode("\n", (string) file_get_contents($argv[2]))));
$db = Db::instance();
$out = [];
foreach (['claude_employee01', 'claude_employee02', 'claude_manager01', 'claude_admin01', 'claude_guest01'] as $name) {
    $r = $db->run('SELECT id, username, role, team_id FROM public.users WHERE username = :u', [':u' => $name])->fetch();
    $u = ['id' => (int) $r['id'], 'username' => (string) $r['username'], 'role' => (string) $r['role'], 'team_id' => $r['team_id'] === null ? null : (int) $r['team_id']];
    $one = ['link_titles' => array_keys(community_link_titles($ids, $u))];
    foreach (['list' => false, 'search' => true] as $mode => $isSearch) {
        [$where, $bind] = $isSearch ? community_search_pages_where($u) : community_visible_pages_where($u);
        $names = [];
        foreach ($ids as $i => $pid) { $names[] = 'CAST(:x' . $i . ' AS uuid)'; $bind[':x' . $i] = $pid; }
        $sql = 'SELECT p.public_id FROM community.pages p JOIN community.spaces s ON s.id = p.space_id'
             . ' LEFT JOIN public.users owner ON owner.id = s.owner_user_id'
             . ' WHERE p.deleted_at IS NULL AND ' . $where . ' AND p.public_id IN (' . implode(', ', $names) . ')';
        $one[$mode] = array_map(static fn ($x) => (string) $x['public_id'], $db->run($sql, $bind)->fetchAll());
    }
    $codes = [];
    foreach ($ids as $pid) {
        $row = community_page_row($pid, $u['id']);
        if ($row === null) { $codes[$pid] = 'no_row'; continue; }
        [$ok, $code] = CommunityAuthz::decidePageRead($u, $row);
        $codes[$pid] = ($ok ? '' : 'X:') . $code;
    }
    $one['codes'] = $codes;
    $out[$name] = $one;
}
echo json_encode($out, JSON_UNESCAPED_UNICODE);
PHP
(cd "$INST/app" && php "$WORK/sets.php" "$INST/app" "$WORK/targets.txt") > "$WORK/sets.json" 2>"$WORK/sets.err"
[ -s "$WORK/sets.err" ] && note "sets.php 오류: $(head -3 "$WORK/sets.err")"
python3 - "$WORK" "$SHOTS" <<'PY' > "$WORK/c222.txt"
import json, os, sys
work, shots = sys.argv[1], sys.argv[2]
targets = [l.split() for l in open(os.path.join(work, 'targets.txt'), encoding='utf-8') if l.strip()]
sets = json.load(open(os.path.join(work, 'sets.json'), encoding='utf-8'))
accts = ['claude_employee01', 'claude_employee02', 'claude_manager01', 'claude_admin01', 'claude_guest01']
PRIV = ('admin_all', 'board_moderator')
bad = 0
rows = []
print('| 대상 | 계정 | mention_labels | link_titles | 목록 조건절 | 검색 | 판정 코드 | 맞음 |')
print('|---|---|---|---|---|---|---|---|')
for a in accts:
    lab = json.loads(open(os.path.join(work, 'lab-%s.json' % a), encoding='utf-8').read() or '{}') or {}
    srch = json.load(open(os.path.join(work, 'search-%s.json' % a), encoding='utf-8'))
    sids = set(r['id'] for r in srch.get('results', []))
    s = sets[a]
    for pid, label in targets:
        A = ('page:' + pid) in lab
        B = pid in s['link_titles']
        C = pid in s['list']
        S = pid in s['search']
        D = pid in sids
        code = s['codes'].get(pid, '')
        ok = (A == B == C) and (D == S)
        if a != 'claude_admin01':
            ok = ok and (S == C)
        else:
            ok = ok and ((C and not S) == (code in PRIV))
        # 판정 ↔ 조건절 짝: 판정이 참이면(모든 갈래) 목록에 있다
        ok = ok and (C == (not code.startswith('X:') and code != 'no_row'))
        bad += 0 if ok else 1
        yn = lambda v: 'O' if v else '·'
        print('| %s | %s | %s | %s | %s | %s | %s | %s |' % (label, a.replace('claude_', ''), yn(A), yn(B), yn(C), yn(D), code, '✔' if ok else '✖'))
print('CASES=%d' % (len(targets) * len(accts)))
print('MISMATCH=%d' % bad)
PY
cat "$WORK/c222.txt"
mkdir -p "$SHOTS" && cp "$WORK/c222.txt" "$SHOTS/c222-table.txt" 2>/dev/null
expect "45 사례 — 네 집합(mention_labels · link_titles · 목록 조건절 · 행 판정) 어긋남 0 · 검색 = 목록 조건절(관리자는 특권 갈래만 빠짐)" "CASES=45 MISMATCH=0" \
  "$(grep -E '^CASES=' "$WORK/c222.txt") $(grep -E '^MISMATCH=' "$WORK/c222.txt")"
expect "  지운 것·없는 UUID 는 다섯 계정 모두 지도에 없다" "0" \
  "$(for w in claude_employee01 claude_employee02 claude_manager01 claude_admin01 claude_guest01; do jget "int('page:$T8' in d) + int('page:$T9' in d)" < "$WORK/lab-$w.json"; done | awk '{s+=$1} END {print s+0}')"
expect "  관리자 지도 = 특권 갈래 포함(비공개·팀·숨김·보기 공유·팀 공간까지 7) · 손님 = 게시·보기 공유 2" "7 2" \
  "$(jget "sum(1 for k in d if k.startswith('page:'))" < "$WORK/lab-claude_admin01.json") $(jget "sum(1 for k in d if k.startswith('page:'))" < "$WORK/lab-claude_guest01.json")"

# ════════════════════════════════════════════════════════════════
hr "C:T-223  음성 — 없는 것 · 못 보는 것 · 손님 대상: 200 · 키 없음 · 글자 0 · 응답이 id 자리만 빼고 같다 · 저장 때 존재 검사 없음 · 감사 0"
# 보는 사람 employee02 · 글 NEG(employee01 · 사내 — employee02 가 읽는다) · 판 둘: ⓐ 없는 페이지 UUID + 없는 사용자 번호 ⓑ 못 보는 비공개 페이지(T1) + 비활성 계정
NEG=$(make_page claude_employee01 'C7D4-음성')
vis claude_employee01 "$NEG" internal > /dev/null
GHOST_UUID=$(python3 -c "import uuid;print(uuid.uuid4())")
INACT_ID="$U_M1"
GHOST_UID=$(psqlq "SELECT min(g) FROM generate_series(power(10, length('$INACT_ID') - 1)::int, (power(10, length('$INACT_ID')) - 1)::int) g WHERE g NOT IN (SELECT id FROM users)")
note "없는 페이지 $GHOST_UUID · 없는 사용자 번호 $GHOST_UID(비활성으로 쓸 manager01=$INACT_ID 와 자릿수 같음)"
m0=$(mark)
capi GET "/api/pages/$NEG" claude_employee02 > /dev/null   # 조회수·최근 기록을 먼저 채운다(두 응답이 그 칸에서 갈리지 않게)
BID=$(python3 -c "import uuid;print(uuid.uuid4())")
expect "ⓐ 없는 id 둘을 저장 → 200(존재를 묻지 않는다 — M-5)" "200" "$(put_doc claude_employee01 "$NEG" "[P([S('가 '), MP('$GHOST_UUID'), S(' 나 '), MU($GHOST_UID)], bid='$BID')]")"
capi GET "/api/pages/$NEG" claude_employee02 > "$WORK/st"; cp "$WORK/b" "$WORK/neg-none.json"; ST_A=$(cat "$WORK/st")
psqlq "UPDATE users SET is_active = false WHERE id = $INACT_ID" > /dev/null
expect "ⓑ 못 보는 페이지(T1)·비활성 계정을 저장 → 200" "200" "$(put_doc claude_employee01 "$NEG" "[P([S('가 '), MP('$T1'), S(' 나 '), MU($INACT_ID)], bid='$BID')]")"
capi GET "/api/pages/$NEG" claude_employee02 > "$WORK/st"; cp "$WORK/b" "$WORK/neg-hidden.json"; ST_B=$(cat "$WORK/st")
psqlq "UPDATE users SET is_active = true WHERE id = $INACT_ID" > /dev/null
expect "ⓒ 못 보는 페이지(T1)·손님 계정(guest01 — 활성 · 쓰기 자격 없음)을 저장 → 200" "200" "$(put_doc claude_employee01 "$NEG" "[P([S('가 '), MP('$T1'), S(' 나 '), MU($U_G1)], bid='$BID')]")"
capi GET "/api/pages/$NEG" claude_employee02 > "$WORK/st"; cp "$WORK/b" "$WORK/neg-guest.json"; ST_C=$(cat "$WORK/st")
expect "두 응답 모두 200" "200 200" "$ST_A $ST_B"
expect "ⓒ 손님 대상 응답 200 · 지도 비어 있음({}) · error 키 없음" "200 {} False" \
  "$ST_C $(jget "json.dumps(d.get('mention_labels'))" < "$WORK/neg-guest.json") $(jget "'error' in d" < "$WORK/neg-guest.json")"
lacks "ⓒ 응답 본문 — 손님 아이디 글자 0" "claude_guest01" "$(cat "$WORK/neg-guest.json")"
lacks "ⓒ 응답 본문 — T1 제목 글자 0" "C7D4짝-비공개" "$(cat "$WORK/neg-guest.json")"
expect "두 응답 모두 지도 비어 있음({}) · error 키 없음" "{} {} False False" \
  "$(jget "json.dumps(d.get('mention_labels'))" < "$WORK/neg-none.json") $(jget "json.dumps(d.get('mention_labels'))" < "$WORK/neg-hidden.json") $(jget "'error' in d" < "$WORK/neg-none.json") $(jget "'error' in d" < "$WORK/neg-hidden.json")"
lacks "못 보는 응답 본문 — T1 제목 글자 0" "C7D4짝-비공개" "$(cat "$WORK/neg-hidden.json")"
lacks "  비활성 계정 아이디 글자 0" "claude_manager01" "$(cat "$WORK/neg-hidden.json")"
lacks "  「볼 수 없음」 같은 문구도 응답에 없다(그리는 것은 렌더러)" "볼 수 없음" "$(cat "$WORK/neg-hidden.json")$(cat "$WORK/neg-none.json")"
python3 - "$WORK" "$GHOST_UUID" "$T1" "$GHOST_UID" "$INACT_ID" "$U_G1" <<'PY' > "$WORK/neg-cmp.txt"
import json, sys, os
work, gu, t1, gid, iid, guid = sys.argv[1:7]
a_raw = open(os.path.join(work, 'neg-none.json'), encoding='utf-8').read()
b_raw = open(os.path.join(work, 'neg-hidden.json'), encoding='utf-8').read()
def norm(raw, pid, uid):
    d = json.loads(raw)
    for k in ('doc_version', 'updated_at'):
        d['page'][k] = '§'
    s = json.dumps(d, ensure_ascii=False, sort_keys=True)
    return s.replace(pid, '«PAGE»').replace('"id": %s, "kind": "user"' % uid, '"id": «USER», "kind": "user"')
na, nb = norm(a_raw, gu, gid), norm(b_raw, t1, iid)
print('SAME=%d' % (na == nb))
print('LEN_NORM=%d %d' % (len(na), len(nb)))
print('LEN_RAW=%d %d' % (len(a_raw.encode()), len(b_raw.encode())))
if na != nb:
    for i, (x, y) in enumerate(zip(na, nb)):
        if x != y:
            print('FIRST_DIFF_AT=%d …%s… / …%s…' % (i, na[max(0, i - 60):i + 60], nb[max(0, i - 60):i + 60])); break
# ⓒ 손님 계정 대상(검토 반영) — ⓐ·ⓑ 와 같은 정규화
c_raw = open(os.path.join(work, 'neg-guest.json'), encoding='utf-8').read()
nc = norm(c_raw, t1, guid)
print('SAME_AC=%d SAME_BC=%d' % (na == nc, nb == nc))
print('LEN_NORM3=%d %d %d' % (len(na), len(nb), len(nc)))
if na != nc:
    for i, (x, y) in enumerate(zip(na, nc)):
        if x != y:
            print('FIRST_DIFF_AC_AT=%d …%s… / …%s…' % (i, na[max(0, i - 60):i + 60], nc[max(0, i - 60):i + 60])); break
PY
cat "$WORK/neg-cmp.txt" | sed 's/^/  · /'
expect "두 응답이 id 자리(와 판 번호·고친 시각)만 빼고 글자 그대로 같다" "SAME=1" "$(grep '^SAME=' "$WORK/neg-cmp.txt")"
expect "  크기 — 판 번호·시각을 뺀 모양이 같은 길이" "same" "$(awk -F'[= ]' '/^LEN_NORM=/{print ($2==$3)?"same":"diff"}' "$WORK/neg-cmp.txt")"
expect "ⓒ 손님 id 응답도 ⓐ 없는 것·ⓑ 못 보는 것과 id 자리(와 판 번호·고친 시각)만 빼고 글자 그대로 같다" "SAME_AC=1 SAME_BC=1" "$(grep '^SAME_AC=' "$WORK/neg-cmp.txt")"
expect "  크기 — 세 벌의 정규화 모양이 같은 길이" "same" "$(awk -F'[= ]' '/^LEN_NORM3=/{print ($2==$3 && $3==$4)?"same":"diff"}' "$WORK/neg-cmp.txt")"
note "응답 원문 두 벌: $SHOTS/c223-none.json · $SHOTS/c223-hidden.json (크기 $(sed -n 's/^LEN_RAW=//p' "$WORK/neg-cmp.txt") 바이트 — 고친 시각 마이크로초 자릿수만큼 흔들릴 수 있다)"
cp "$WORK/neg-none.json" "$SHOTS/c223-none.json"; cp "$WORK/neg-hidden.json" "$SHOTS/c223-hidden.json"; cp "$WORK/neg-guest.json" "$SHOTS/c223-guest.json"
note "응답 원문 셋째(손님 계정 대상): $SHOTS/c223-guest.json"
expect "감사 — 이 사이 행에 대상 id·제목 0(T1 제목 · T1 id · 없는 UUID)" "0" \
  "$(psqlq "SELECT count(*) FROM audit_logs WHERE id > $m0 AND (coalesce(detail::text,'') || coalesce(target_name,'') || coalesce(summary,'')) ~ ('C7D4짝-비공개|$T1|$GHOST_UUID')")"
expect "  감사 — 이 사이 행에 손님 아이디 글자 0" "0" \
  "$(psqlq "SELECT count(*) FROM audit_logs WHERE id > $m0 AND (coalesce(detail::text,'') || coalesce(target_name,'') || coalesce(summary,'')) LIKE '%claude_guest01%'")"
note "  이 사이 감사 행: $(psqlq "SELECT string_agg(action || ' ' || result, ', ' ORDER BY id) FROM audit_logs WHERE id > $m0")"
note "  응답 시간으로는 가르지 않는다 — 단언은 모양만(지시서 C:T-223)"

# ════════════════════════════════════════════════════════════════
hr "C:T-224  저장·파생 — 이름·제목 0 · 알림 0 · page_links 0 · 되돌리기 뒤 마크 그대로"
HPID=$(pg_page "$H" "p.id")
python3 - "$(pg_page "$H" "p.doc")" <<'PY' > "$WORK/h-doc.txt"
import json, sys
d = json.loads(sys.argv[1])
segs = [s for b in d['blocks'] for s in b.get('text', []) if 'mention' in (s.get('m') or {})]
print('N=%d' % len(segs))
print('T=%s' % ''.join(sorted(set(s['t'] for s in segs))))
print('KEYS=%s' % sorted(set(tuple(sorted(s['m']['mention'].keys())) for s in segs)))
PY
expect "저장된 doc 의 멘션 세그먼트 14개(사람 넷 · 날짜 · 페이지 아홉) · 글자는 모두 「@」 · 마크 칸은 kind·id 뿐" "N=14 T=@ KEYS=[('id', 'kind')]" "$(tr '\n' ' ' < "$WORK/h-doc.txt" | sed 's/ $//')"
DOCTXT=$(pg_page "$H" "p.doc::text")
mkdir -p "$SHOTS" && printf '%s\n' "$DOCTXT" > "$SHOTS/c224-saved-doc.json" && note "저장된 문서 JSON 한 벌(DB pages.doc — 모음 글): $SHOTS/c224-saved-doc.json"
PLAIN=$(pg_page "$H" "p.plain_text")
EXC=$(psqlq "SELECT string_agg(excerpt, '|' ORDER BY ord) FROM community.page_blocks WHERE page_id = $HPID")
for needle in "$NAME_E2" "claude_employee02" "claude_manager01" "C7D4짝"; do
  lacks "  doc(DB)에 「$needle」 0" "$needle" "$DOCTXT"
  lacks "  plain_text 에 「$needle」 0" "$needle" "$PLAIN"
  lacks "  page_blocks.excerpt 에 「$needle」 0" "$needle" "$EXC"
done
contains "  plain_text 에는 「@」 만(사람 · 날짜 자리)" "사람 @ @ @ @ 날짜 @" "$PLAIN"
for q in "$NAME_E2" "claude_employee02" "C7D4짝-사내"; do
  capi GET "/api/search?q=$(python3 -c "import urllib.parse,sys;print(urllib.parse.quote(sys.argv[1]))" "$q")" claude_employee01 > /dev/null
  expect "  검색 q=「$q」 결과에 모음 글 0(search_tsv 에 이름·제목 없음)" "False" "$(jget "any(r['id'] == '$H' for r in d.get('results', []))" < "$WORK/b")"
done
# 판 이력 — 5분 합치기를 비켜 스냅샷을 남긴다
age_versions "$H"
put_doc claude_employee01 "$H" "[P([S('비운 판')])]" > /dev/null
age_versions "$H"
VER_TXT=$(psqlq "SELECT string_agg(doc::text, ' ') FROM community.page_versions WHERE page_id = $HPID")
expect "  page_versions 에 모음 글의 판이 남았다(1 이상)" "yes" "$([ "$(psqlq "SELECT count(*) FROM community.page_versions WHERE page_id = $HPID")" -ge 1 ] && echo yes)"
for needle in "$NAME_E2" "claude_employee02" "C7D4짝"; do lacks "  page_versions 본문에 「$needle」 0" "$needle" "$VER_TXT"; done
VMEN=$(psqlq "SELECT max(version) FROM community.page_versions WHERE page_id = $HPID AND doc::text LIKE '%\"mention\"%'")
expect "되돌리기 — 멘션 든 판(${VMEN:-?})으로 → 200" "200" "$(capi POST "/api/pages/$H/versions/${VMEN:-0}/restore" claude_employee01 <(printf '{}'))"
python3 - "$(pg_page "$H" "p.doc")" "$(psqlq "SELECT doc FROM community.page_versions WHERE page_id = $HPID AND version = ${VMEN:-0}")" <<'PY' > "$WORK/restore-cmp.txt"
import json, sys
a, b = json.loads(sys.argv[1]), json.loads(sys.argv[2])
f = lambda d: [s['m'].get('mention') for bl in d['blocks'] for s in bl.get('text', []) if isinstance(s.get('m'), dict) and 'mention' in s['m']]
print('SAME=%d N=%d' % (f(a) == f(b), len(f(a))))
PY
expect "  되돌린 뒤 멘션 마크 그대로(판의 것과 같은 14)" "SAME=1 N=14" "$(cat "$WORK/restore-cmp.txt")"
N1=$(psqlq "SELECT count(*) FROM community.notifications")
L1=$(psqlq "SELECT count(*) FROM community.page_links")
expect "notifications 행 수 전후 같음(멘션은 알림 0 — J-7)" "$N0" "$N1"
expect "page_links 행 수 전후 같음 · 모음 글에서 나가는 줄 0(역참조에 넣지 않는다 — E-7)" "$L0 0" "$L1 $(psqlq "SELECT count(*) FROM community.page_links WHERE from_page_id = $HPID")"
expect "새 코드에 community_notify 부르는 줄 0(기준 판 대비 더한 줄)" "0" \
  "$(git -C "$ROOT" diff "$BASE_REV" -- src | grep -c '^+.*community_notify(' | tr -d ' ')"

# ════════════════════════════════════════════════════════════════
hr "C:T-225  렌더러 동등 — PHP 쪽(DocRender). JS 쪽은 C:T-227 브라우저 안에서 견준다 · 문구 상수 두 벌"
C_PHP=$(sed -n "s/^    private const MENTION_UNREADABLE = '\(.*\)';$/\1/p" "$ROOT/src/community/DocRender.php")
C_JS=$(sed -n "s/^const MENTION_UNREADABLE = '\(.*\)';$/\1/p" "$ROOT/public/assets/community/doc-render.js")
expect "「볼 수 없음」 상수 두 벌이 한 글자도 다르지 않다(DocRender::MENTION_UNREADABLE = doc-render.js)" "볼 수 없음|볼 수 없음" "$C_PHP|$C_JS"
expect "MARK_ORDER 두 벌 — 끝에 mention(가장 바깥)" "['code', 'color', 'strike', 'underline', 'italic', 'bold', 'link', 'mention']|['code', 'color', 'strike', 'underline', 'italic', 'bold', 'link', 'mention']" \
  "$(sed -n "s/^    private const MARK_ORDER = \(.*\);$/\1/p" "$ROOT/src/community/DocRender.php")|$(sed -n "s/^const MARK_ORDER = \(.*\);$/\1/p" "$ROOT/public/assets/community/doc-render.js")"
python3 - "$WORK" <<'PY'
import json, os, sys, uuid
work = sys.argv[1]
def u(): return str(uuid.uuid4())
def S(t, m=None): return {"t": t, "m": dict(m or {})}
def M(kind, id, extra=None, t="@"):
    m = {"mention": {"kind": kind, "id": id}}; m.update(extra or {}); return {"t": t, "m": m}
def P(segs): return {"id": u(), "type": "paragraph", "text": segs}
PA, PB = "0a1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d", "1b2c3d4e-5f60-4b7c-9d8e-0f1a2b3c4d5e"
CTX = {"base": "/community", "mention_labels": {"user:12": {"label": "홍길순 (hong)"}, "page:" + PA: {"label": "문서 에이", "icon": "📄"},
       "user:14": {"label": "<b>꺾쇠</b> (x&y)"}}}
S_ = []
S_.append([P([S("안녕 "), M("user", 12), S(" 님")])])                                    # 사람 라벨 있음
S_.append([P([M("user", 13)])])                                                          # 사람 라벨 없음
S_.append([P([S("참고 "), M("page", PA)])])                                              # 페이지 있음(링크)
S_.append([P([M("page", PB), S(" 없음")])])                                              # 페이지 없음
S_.append([P([S("마감 "), M("date", "2026-09-27")])])                                    # 날짜
S_.append([P([M("user", 12, {"bold": True}), M("page", PA, {"bold": True, "color": "red"}), M("date", "2026-02-28", {"italic": True})])])   # 겹침
S_.append([P([M("user", "12"), M("page", PA.upper()), M("date", "2026-02-30"), {"t": "@", "m": {"mention": "user:12"}}, M("team", 1), {"t": "@", "m": {"mention": {"kind": "user", "id": 12, "x": 1}}}])])  # 모양 밖
S_.append([P([M("page", PA, {"link": {"href": "https://example.com/"}})])])            # 링크와 함께(검증기 밖) — 링크는 그리지 않는다
S_.append([{"id": u(), "type": "heading", "level": 2, "text": [M("date", "2026-01-01")]},
           {"id": u(), "type": "bulleted", "text": [M("user", 12)]},
           {"id": u(), "type": "table", "header_row": False, "header_col": False, "rows": [{"cells": [{"text": [M("page", PA)]}, {"text": [S("칸")]}]}]}])   # 제목·목록·표 칸
S_.append([P([M("user", 14), M("user", 12, t="@홍")])])                                  # 라벨 이스케이프 · 글자가 「@」 아님(검증기 밖) — 라벨로 바뀐다
samples = [{"doc": {"schema": 1, "blocks": b}, "ctx": CTX} for b in S_]
# stage3 C:T-19 표본 24(stage3.sh 와 같은 생성 식) — 곁가지 없이
def seg(t, m=None): return [{"t": t, "m": m or {}}]
s3 = []
s3.append({"schema":1,"blocks":[{"id":u(),"type":"paragraph","text":seg("보통 글")}]})
for lv in (1,2,3):
    s3.append({"schema":1,"blocks":[{"id":u(),"type":"heading","level":lv,"text":seg("제목 %d" % lv)}]})
s3.append({"schema":1,"blocks":[{"id":u(),"type":"bulleted","text":seg("하나")},{"id":u(),"type":"bulleted","text":seg("둘")}]})
s3.append({"schema":1,"blocks":[{"id":u(),"type":"numbered","text":seg("첫째")},{"id":u(),"type":"numbered","text":seg("둘째")}]})
s3.append({"schema":1,"blocks":[{"id":u(),"type":"to_do","checked":True,"text":seg("한 일")},{"id":u(),"type":"to_do","checked":False,"text":seg("할 일")}]})
s3.append({"schema":1,"blocks":[{"id":u(),"type":"quote","text":seg("인용")}]})
s3.append({"schema":1,"blocks":[{"id":u(),"type":"callout","icon":"💡","text":seg("강조")}]})
s3.append({"schema":1,"blocks":[{"id":u(),"type":"divider"}]})
s3.append({"schema":1,"blocks":[{"id":u(),"type":"code","code":"echo 1;","language":"php"}]})
s3.append({"schema":1,"blocks":[{"id":u(),"type":"heading","level":1,"text":seg("차례용")},{"id":u(),"type":"toc"}]})
s3.append({"schema":1,"blocks":[{"id":u(),"type":"paragraph","text":[{"t":"굵게","m":{"bold":True}},{"t":"기울임","m":{"italic":True}}]}]})
s3.append({"schema":1,"blocks":[{"id":u(),"type":"paragraph","text":[{"t":"밑줄","m":{"underline":True}},{"t":"취소","m":{"strike":True}}]}]})
s3.append({"schema":1,"blocks":[{"id":u(),"type":"paragraph","text":[{"t":"코드","m":{"code":True}}]}]})
s3.append({"schema":1,"blocks":[{"id":u(),"type":"paragraph","text":[{"t":"빨강","m":{"color":"red"}},{"t":"노랑바탕","m":{"color":"yellow_bg"}}]}]})
s3.append({"schema":1,"blocks":[{"id":u(),"type":"paragraph","text":[{"t":"바깥","m":{"link":{"href":"https://example.com/"}}}]}]})
s3.append({"schema":1,"blocks":[{"id":u(),"type":"paragraph","text":[{"t":"메일","m":{"link":{"href":"mailto:a@b.co"}}}]}]})
s3.append({"schema":1,"blocks":[{"id":u(),"type":"paragraph","color":"blue_bg","align":"center","size":"large","text":seg("꾸민 문단")}]})
s3.append({"schema":1,"blocks":[{"id":u(),"type":"paragraph","text":seg("<img src=x onerror=alert(1)>")}]})
s3.append({"schema":1,"blocks":[{"id":u(),"type":"paragraph","text":seg("여러 줄\n둘째 줄")}]})
while len(s3) < 20:
    s3.append({"schema":1,"blocks":[{"id":u(),"type":"paragraph","text":seg("채움 %d" % len(s3))}]})
s3 = s3[:20]
s3.append({"schema":1,"blocks":[{"id":u(),"type":"paragraph","text":[{"t":"안녕 ","m":{}},{"t":"@","m":{"mention":{"kind":"user","id":12}}},{"t":" 님","m":{}}]}]})
s3.append({"schema":1,"blocks":[{"id":u(),"type":"paragraph","text":[{"t":"@","m":{"mention":{"kind":"page","id":"0a1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d"}}},{"t":" 참고","m":{}}]}]})
s3.append({"schema":1,"blocks":[{"id":u(),"type":"bulleted","text":[{"t":"마감 ","m":{}},{"t":"@","m":{"mention":{"kind":"date","id":"2026-09-27"},"bold":True}}]}]})
s3.append({"schema":1,"blocks":[{"id":u(),"type":"heading","level":2,"text":[{"t":"@","m":{"mention":{"kind":"user","id":7},"color":"red"}},{"t":"@","m":{"mention":{"kind":"date","id":"2028-02-29"}}}]}]})
samples += [{"doc": d, "ctx": {}} for d in s3]
json.dump(samples, open(os.path.join(work, "samples225.json"), "w", encoding="utf-8"), ensure_ascii=False)
print(len(samples), "표본 (멘션 %d + stage3 C:T-19 %d)" % (len(S_), len(s3)))
PY
cat > "$WORK/render225.php" <<'PHP'
<?php
declare(strict_types=1);
// 임시 인스턴스 사본에서 부른다 — DocRender::html(문서, 곁가지)의 텍스트 노드 열 · 클래스 집합 · 링크(<a>)의 class·href·target
$app = $argv[1];
require $app . '/scripts/lib/cli_bootstrap.php';
require_once $app . '/src/community/routes.php';
$samples = json_decode((string) file_get_contents($argv[2]), true);
$out = [];
foreach ($samples as $s) {
    $html = DocRender::html($s['doc'], $s['ctx']);
    $texts = [];
    foreach (preg_split('/<[^>]*>/', $html) as $chunk) {
        $t = trim(html_entity_decode($chunk, ENT_QUOTES, 'UTF-8'));
        if ($t !== '') { $texts[] = $t; }
    }
    $classes = [];
    preg_match_all('/class="([^"]*)"/', $html, $m);
    foreach ($m[1] as $cl) { foreach (preg_split('/\s+/', html_entity_decode($cl, ENT_QUOTES, 'UTF-8')) as $k) { if ($k !== '') { $classes[] = $k; } } }
    $classes = array_values(array_unique($classes)); sort($classes);
    $links = [];
    preg_match_all('/<a\b([^>]*)>/', $html, $am);
    foreach ($am[1] as $attrs) {
        $get = static function (string $n) use ($attrs): ?string { return preg_match('/\b' . $n . '="([^"]*)"/', $attrs, $x) === 1 ? html_entity_decode($x[1], ENT_QUOTES, 'UTF-8') : null; };
        $links[] = [$get('class'), $get('href'), $get('target')];
    }
    $out[] = ['texts' => $texts, 'classes' => $classes, 'links' => $links, 'style' => strpos($html, 'style=') !== false];
}
echo json_encode($out, JSON_UNESCAPED_UNICODE);
PHP
(cd "$INST/app" && php "$WORK/render225.php" "$INST/app" "$WORK/samples225.json") > "$WORK/php225.json" 2>"$WORK/php225.err"
[ -s "$WORK/php225.err" ] && note "render225.php 오류: $(head -3 "$WORK/php225.err")"
expect "PHP 렌더러 — 표본 34개(멘션 10 + stage3 C:T-19 24)를 그렸다" "34" "$(jget "len(d)" < "$WORK/php225.json")"
python3 - "$WORK" <<'PY' > "$WORK/php225-check.txt"
import json, os, sys
a = json.load(open(os.path.join(sys.argv[1], 'php225.json'), encoding='utf-8'))
print('T0=%s' % a[0]['texts'])
print('T1=%s' % a[1]['texts'])
print('L2=%s' % a[2]['links'])
print('T6=%s' % a[6]['texts'])
print('L7=%s' % a[7]['links'])
print('T9=%s' % a[9]['texts'])
print('C=%s' % sorted(set(c for x in a[:10] for c in x['classes'] if c.startswith('doc-mention'))))
print('STYLE=%s' % any(x['style'] for x in a))
print('MLINKS=%s' % sorted(set(tuple(l) for x in a for l in x['links'] if l[0] and 'doc-mention' in l[0])))
PY
sed 's/^/  · /' "$WORK/php225-check.txt"
expect "  사람 라벨 있음 → 「@홍길순 (hong)」 · 원문 「@」 는 라벨로 바뀜" "T0=['안녕', '@홍길순 (hong)', '님']" "$(grep '^T0=' "$WORK/php225-check.txt")"
expect "  사람 라벨 없음 → 「@볼 수 없음」" "T1=['@볼 수 없음']" "$(grep '^T1=' "$WORK/php225-check.txt")"
expect "  페이지 있음 → a.doc-mention href=/community/p/<uuid> · target 없음" "L2=[['doc-mention', '/community/p/0a1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d', None]]" "$(grep '^L2=' "$WORK/php225-check.txt")"
expect "  모양 밖 id 여섯 → 모두 「@볼 수 없음」" "T6=['@볼 수 없음', '@볼 수 없음', '@볼 수 없음', '@볼 수 없음', '@볼 수 없음', '@볼 수 없음']" "$(grep '^T6=' "$WORK/php225-check.txt")"
expect "  멘션 + 링크(검증기 밖) → 링크는 그리지 않는다(멘션 링크 하나뿐)" "L7=[['doc-mention', '/community/p/0a1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d', None]]" "$(grep '^L7=' "$WORK/php225-check.txt")"
expect "  라벨의 꺾쇠는 글자 그대로(이스케이프) · 「@」 가 아닌 원문도 라벨로" "T9=['@<b>꺾쇠</b> (x&y)', '@홍길순 (hong)']" "$(grep '^T9=' "$WORK/php225-check.txt")"
expect "  멘션 클래스 둘뿐(doc-mention · doc-mention--unknown) · 인라인 style 0" "C=['doc-mention', 'doc-mention--unknown'] STYLE=False" "$(grep '^C=' "$WORK/php225-check.txt") $(grep '^STYLE=' "$WORK/php225-check.txt")"
expect "  멘션 링크는 모두 사이트 안 /community/p/<uuid> · target 0" "yes" \
  "$(python3 -c "import json,re;a=json.load(open('$WORK/php225.json'));L=[l for x in a for l in x['links'] if l[0] and 'doc-mention' in l[0]];print('yes' if L and all(re.fullmatch(r'/community/p/[0-9a-f-]{36}', l[1] or '') and l[2] is None for l in L) else 'no')")"

# ════════════════════════════════════════════════════════════════
hr "C:T-226  템플릿 — 담을 때 멘션 마크를 뗀다 · 「@」 는 남는다 · 템플릿에서 만든 페이지도 0 · 템플릿 응답에 지도 없음"
TSRC=$(make_page claude_employee01 'C7D4-템플릿 원본')
expect "원본에 멘션(문단 · 표 칸) 저장 → 200" "200" \
  "$(put_doc claude_employee01 "$TSRC" "[P([S('담당 '), MU($U_E2), S(' 마감 '), MD('2026-09-27', {'bold': True})]), {'id': u(), 'type': 'table', 'header_row': False, 'header_col': False, 'rows': [{'cells': [{'text': [MP('$T3')]}, {'text': [S('칸')]}]}]}]")"
jbody "{'page_id': '$TSRC', 'name': 'C7D4 멘션 템플릿', 'scope': 'personal'}"
expect "템플릿으로 저장 → 201" "201" "$(capi POST /api/templates claude_employee01 "$WORK/body.json")"
TPL=$(jget "d['template']['id']" < "$WORK/b")
TPL_DOC=$(psqlq "SELECT doc::text FROM community.templates WHERE public_id = '$TPL'")
expect "  템플릿 본문(DB)에 mention 0 · 「@」 글자는 셋 남음" "0 3" "$(printf '%s' "$TPL_DOC" | grep -o '"mention"' | wc -l | tr -d ' ') $(python3 -c "import json,sys;d=json.loads(sys.argv[1]);print(sum(1 for b in d['blocks'] for s in (b.get('text') or []) if s['t']=='@') + sum(1 for b in d['blocks'] if b['type']=='table' for r in b['rows'] for c in r['cells'] for s in c['text'] if s['t']=='@'))" "$TPL_DOC")"
expect "  굵게 같은 다른 마크는 남는다" "yes" "$(printf '%s' "$TPL_DOC" | grep -q '"bold": *true' && echo yes)"
capi GET "/api/templates/$TPL" claude_employee01 > /dev/null
expect "템플릿 보기 응답 200 · mention_labels 키 없음 · 본문에 mention 0" "200 0 0" \
  "$(status_of "$WORK/h") $(grep -o 'mention_labels' "$WORK/b" | wc -l | tr -d ' ') $(grep -o '"mention"' "$WORK/b" | wc -l | tr -d ' ')"
jbody "{'title': 'C7D4-템플릿에서', 'template_id': '$TPL'}"
expect "템플릿에서 새 페이지 → 201" "201" "$(capi POST /api/pages claude_employee01 "$WORK/body.json")"
TNEW=$(jget "d['page']['id']" < "$WORK/b")
expect "  새 페이지 본문에 mention 0 · 「@」 남음" "0 yes" \
  "$(pg_page "$TNEW" "p.doc::text" | grep -o '"mention"' | wc -l | tr -d ' ') $(pg_page "$TNEW" "p.doc::text" | grep -q '"t": *"@"' && echo yes)"

# ════════════════════════════════════════════════════════════════
hr "C:T-227  브라우저 — browser_7d4.py (편집기 「멘션」 · 읽기 화면 · 못 보는 사람 · 손님 · 폭 × 테마 + 고대비 한 조합) · C:T-225 JS 쪽"
export PATH="$HOME/.local/bin:$PATH"
if ! python3 -c 'import playwright' 2>/dev/null; then
  skip "브라우저" "playwright 가 없다 — **미검증**"
else
  PE=$(make_page claude_employee01 'C7D4-편집기 멘션')
  vis claude_employee01 "$PE" internal > /dev/null
  expect "  편집기 페이지 첫 저장(사내 — employee02 가 후보가 되게) → 200" "200" "$(put_doc claude_employee01 "$PE" "[P([S('멘션 시험 ')]), P([S('둘째 줄')])]")"
  PS=$(make_page claude_employee01 'C7D4-공유받은 글')
  jbody "{'level': 'view'}"; capi PUT "/api/pages/$PS/shares/claude_employee02" claude_employee01 "$WORK/body.json" > /dev/null
  expect "  공유받은 글(비공개 · employee02 보기 공유) 저장 — 못 보는 페이지 T1 · 사람 employee02 → 200" "200" \
    "$(put_doc claude_employee01 "$PS" "[P([S('비공개 '), MP('$T1'), S(' 사람 '), MU($U_E2)])]")"
  NB0=$(psqlq "SELECT count(*) FROM community.notifications")
  mkdir -p "$SHOTS"
  E1=$(cookie_of claude_employee01); E2=$(cookie_of claude_employee02); G1=$(cookie_of claude_guest01)
  browser_lock_on
  # 세션 쿠키 셋은 표준입력 세 줄로(명령 인자·환경 변수에 싣지 않는다 — R-62)
  py_secret E1 E2 G1 -- python3 "$ROOT/tests/community/browser_7d4.py" "$SITE" "$UA" "$WORK" "$PE" "$PS" "$H" "$T3" "$T6" "$SHOTS" > "$WORK/smoke.txt" 2>&1
  browser_lock_off
  sed 's/^/    /' "$WORK/smoke.txt"
  cp "$WORK/smoke.txt" "$SHOTS/browser_7d4.txt" 2>/dev/null
  expect "브라우저 실패 0" "0" "$(sed -n 's/^SMOKE_FAIL=//p' "$WORK/smoke.txt" | tail -1)"
  expect "C:T-225 두 렌더러의 텍스트 노드·클래스 집합·링크(href·target)가 같다(JS 쪽 · 표본 34)" "DIFF=0" "$(grep '^DIFF=' "$WORK/smoke.txt" | tail -1)"
  expect "브라우저 단계 뒤에도 알림 행 수 그대로(멘션은 알림 0)" "$NB0 $N0" "$(psqlq "SELECT count(*) FROM community.notifications") $NB0"
  python3 - "$(pg_page "$PE" "p.doc")" "$NAME_E2" <<'PY' > "$WORK/pe-doc.txt"
import json, sys
d = json.loads(sys.argv[1]); raw = sys.argv[1]
m = [s['m']['mention'] for b in d['blocks'] for s in b.get('text', []) if isinstance(s.get('m'), dict) and 'mention' in s['m']]
t = set(s['t'] for b in d['blocks'] for s in b.get('text', []) if isinstance(s.get('m'), dict) and 'mention' in s['m'])
print('KINDS=%s T=%s NAME=%d TITLE=%d' % (sorted(x['kind'] for x in m), ''.join(sorted(t)), int(sys.argv[2] in raw), int('C7D4짝-사내' in raw)))
PY
  expect "편집기로 저장한 본문(DB) — 멘션 셋(사람·페이지·날짜) · 글자 「@」 · 이름·제목 글자 0" "KINDS=['date', 'page', 'user'] T=@ NAME=0 TITLE=0" "$(cat "$WORK/pe-doc.txt")"
fi

# ════════════════════════════════════════════════════════════════
hr "회귀 — 금지 패턴 · 문법 · 부품 버전 · 허용 목록 밖 변경 · 고치지 않는 것"
out=$(bash "$ROOT/scripts/security_lint.sh" 2>&1 | tail -3)
contains "security_lint 0건" "0건" "$out"
bad=0
for f in $(cd "$ROOT" && git ls-files 'src/**.php' 'public/index.php' 2>/dev/null); do
  [ -f "$ROOT/$f" ] || continue
  php -l "$ROOT/$f" > /dev/null 2>&1 || { echo "  ✖ php -l 실패: $f"; bad=$((bad+1)); }
done
expect "php -l 전부 통과" "0" "$bad"
expect "커뮤니티 부품 버전 --check" "0" "$(cd "$ROOT" && php scripts/community_asset_version.php --check >/dev/null 2>&1; echo $?)"
expect "JS 구문(esprima) — doc-render·editor-schema·editor" "ok" \
  "$(python3 -c "
import esprima,sys
for f in ['doc-render.js','editor-schema.js','editor.js']:
    esprima.parseModule(open('$ROOT/public/assets/community/'+f,encoding='utf-8').read())
print('ok')" 2>&1 | tail -1)"
bash -n "$ROOT/tests/community/stage7d4.sh" && expect "stage7d4.sh 문법" "0" "0"
python3 -c "import ast,io;ast.parse(io.open('$ROOT/tests/community/browser_7d4.py',encoding='utf-8').read())" && expect "browser_7d4.py 문법" "0" "0"
expect "커뮤니티 코드에 역할 문자열 비교 0" "0" \
  "$(grep -rnE "'(Admin|Manager|Employee|Guest)'" "$ROOT/src/community" | grep -vE '^[^:]+:[0-9]+:\s*(\*|//|/\*)' | wc -l | tr -d ' ')"
expect "편집기·렌더러에 색값(#rrggbb) 0 · 7-D4 CSS 줄에 색값 0" "0 0" \
  "$(grep -cE "#[0-9a-fA-F]{6}\b" "$ROOT/public/assets/community/doc-render.js" "$ROOT/public/assets/community/editor-schema.js" "$ROOT/public/assets/community/editor.js" "$ROOT/public/assets/community/page.js" "$ROOT/public/assets/community/editor-runtime.js" "$ROOT/public/assets/community/editor-loader.js" | awk -F: '{s+=$2} END {print s+0}') $(git -C "$ROOT" diff "$BASE_REV" -- public/assets/community/community.css | grep '^+' | grep -cE '#[0-9a-fA-F]{3,8}\b|rgb\(' | tr -d ' ')"
expect "렌더러 두 벌은 표를 읽지 않는다(이름 풀이는 창구가 — 지도만 본다)" "0" \
  "$(grep -cE 'Db::|public\.users|/api/mention-candidates|/api/search' "$ROOT/src/community/DocRender.php" "$ROOT/public/assets/community/doc-render.js" | awk -F: '{s+=$2} END {print s+0}')"
expect "편집기 — mention 마크에 parseDOM 0 · 「@」 키로 여는 길 0(keydown '@' · keymap '@' 0)" "0 0" \
  "$(awk '/^    mention: \{/,/^    \}/' "$ROOT/public/assets/community/editor-schema.js" | grep -c parseDOM | tr -d ' ') $(cat "$ROOT/public/assets/community/editor.js" "$ROOT/public/assets/community/page.js" "$ROOT/public/assets/community/editor-runtime.js" "$ROOT/public/assets/community/editor-loader.js" | grep -cE "key === '@'|'@': |\"@\": " | tr -d ' ')"
expect "멘션 후보 목록은 줄 안(.list.list--scroll) — 떠 있는 요소(popover·position) 0" "1 0" \
  "$(grep -c "mentionList.className = 'list list--scroll';" "$ROOT/public/assets/community/editor-runtime.js" | tr -d ' ') $(git -C "$ROOT" diff "$BASE_REV" -- public/assets/community/editor-runtime.js | grep '^+' | grep -cE 'popover|\.style\.|position' | tr -d ' ')"
expect "고치지 않는 것(지시서 3.5) 변경 0 — Authz·Auth·Audit·Http·Db·CommunityAuthz·index·db·deploy·bootstrap·comments_create·search" "0" \
  "$(git -C "$ROOT" diff --name-only "$BASE_REV" -- src/Authz.php src/Auth.php src/Audit.php src/Http.php src/Db.php src/community/CommunityAuthz.php public/index.php db deploy src/bootstrap.php src/community/handlers/comments_create.php src/community/handlers/search.php | wc -l | tr -d ' ')"
expect "지시서 예외 mention_candidates.php — 바뀐 줄은 id 칸 하나 + 머리글 주석뿐(판정·SQL·정렬·개수 그대로)" "1" \
  "$(git -C "$ROOT" diff -U0 "$BASE_REV" -- src/community/handlers/mention_candidates.php | grep -E '^[+-]' | grep -vE '^(\+\+\+|---)' | grep -vE '^\+ \*' | grep -c . | tr -d ' ')"
PL_TESTS="stage5a2 stage5c stage5d stage5e stage6a stage6b stage6c stage7a stage7b stage7c stage7d1 qf2_search"
expect "지시서 예외 stage7c.sh — 바뀐 줄은 :935 한 항목 + 「이름 (아이디)」 잇는 자리 한 항목(+3 −3)" "3 3" \
  "$(git -C "$ROOT" diff --numstat "$BASE_REV" -- tests/community/stage7c.sh | awk '{print $1, $2}')"
expect "지시서 예외(5-A″ 불변식) 시험 12 — 「이름 (아이디)」 잇는 자리 한 항목씩만 파일 이름 목록 대조로(+2 −2 · stage7c 는 위)" "11 12" \
  "$(n=0; for t in $PL_TESTS; do [ "$t" = stage7c ] && continue; [ "$(git -C "$ROOT" diff --numstat "$BASE_REV" -- "tests/community/$t.sh" | awk '{print $1, $2}')" = "2 2" ] && n=$((n+1)); done; printf '%s ' "$n"; m=0; for t in $PL_TESTS; do [ "$(git -C "$ROOT" diff -U0 "$BASE_REV" -- "tests/community/$t.sh" | grep -cF "grep -rln 'tbox_person_label(' src | grep -vx 'src/bootstrap.php' | LC_ALL=C sort")" = "1" ] && m=$((m+1)); done; printf '%s' "$m")"
expect "지시서 예외 browser_stale_modules.py — 대조군 B 의 빠진 export 단언 한 줄만(+3 −2 — 이름 집합으로)" "3 2" \
  "$(git -C "$ROOT" diff --numstat "$BASE_REV" -- tests/community/browser_stale_modules.py | awk '{print $1, $2}')"
expect "불변식 예외 하나 — routes.php 에서 tbox_person_label( 은 community_mention_labels 안 한 번뿐" "1 community_mention_labels" \
  "$(grep -c 'tbox_person_label(' "$ROOT/src/community/routes.php" | tr -d ' ') $(awk '/^function /{fn=$2; sub(/\(.*/, "", fn)} /tbox_person_label\(/{print fn}' "$ROOT/src/community/routes.php")"
# 부품 버전 다시 찍기로 바뀐 파일은 import 줄만
RESTAMP_BAD=$(for f in $(git -C "$ROOT" diff --name-only "$BASE_REV" -- public/assets/community | grep -E '\.js$' | grep -vE '/(doc-render|editor-schema|editor|page|editor-runtime|editor-loader)\.js$'); do
  git -C "$ROOT" diff -U0 "$BASE_REV" -- "$f" | grep -E '^[+-]' | grep -vE '^(\+\+\+|---)' | grep -vE "^[+-]import .* from '\./[a-z/-]+\.js\?v=[0-9a-f]{12}';$" | sed "s#^#$f: #"
done)
expect "부품 버전 다시 찍기만 한 파일은 import 줄만 바뀌었다" "" "$RESTAMP_BAD"
if git -C "$ROOT" rev-parse -q --verify "$BASE_REV^{commit}" >/dev/null 2>&1; then
  ALLOWED='^(public/assets/community/doc-schema\.json|src/community/Doc\.php|src/community/routes\.php|src/community/DocRender\.php|src/community/handlers/(pages_get|version_get|mention_candidates)\.php|public/assets/community/(doc-render|editor-schema|editor|page|editor-runtime|editor-loader|templates|database|embeds|popular|palettes|drafts)\.js|public/assets/community/community\.css|tests/community/(stage7d4\.sh|browser_7d4\.py|stage3\.sh|stage7c\.sh|stage5a2\.sh|stage5c\.sh|stage5d\.sh|stage5e\.sh|stage6a\.sh|stage6b\.sh|stage6c\.sh|stage7a\.sh|stage7b\.sh|stage7d1\.sh|qf2_search\.sh|browser_stale_modules\.py)|tests/catalog\.json|SECURITY\.md|DESIGN\.md|src/help/community\.php|wiki/community/(stage-7-d|guide-blocks|index)\.json|review-scripts/(apply-7d4-repo-2026-09-27|pins-7d4-repo)\.sh)$'
  OUTSIDE=$( { git -C "$ROOT" diff --name-only "$BASE_REV"; git -C "$ROOT" ls-files --others --exclude-standard; } | sort -u | grep -vE "$ALLOWED" || true)
  expect "허용 목록(지시서 3.5 · 메인 결정 예외 다섯 · 부품 import 버전 · 반영 스크립트) 밖 변경 0" "" "$OUTSIDE"
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
