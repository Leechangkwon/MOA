#!/usr/bin/env bash
# tests/community/stage4c.sh — 커뮤니티 4-C 수락 기준 C:T-38 ~ C:T-45 실행
#                              (../../../community/stage4c-history-directive.md 「완료 기준」)
#
#   C:T-38 5분 합치기   판정 함수를 **단위로** 확인(8가지) + 연속 저장 3회 →
#                       스냅샷은 1개만 늘고 판 번호는 3 오른다
#   C:T-39 되돌리기     과거 판 내용이 현재가 되고 `doc_version` 이 오른다 ·
#                       과거 스냅샷은 개수·내용 불변 · 감사 `page.restore_version allow` 1건
#   C:T-40 기록 불변    **앱 계정(tbox_app)으로** `UPDATE`·`DELETE` ×4 → 전부 42501
#   C:T-41 체크 이력    켜고 끄고 다시 켜기 → `checklist_events` 3행 · 「처리 이력」 3줄 ·
#                       누가·언제가 맞다
#   C:T-42 남의 이력    claude_employee02 의 `versions`·`history`·`versions/{n}`·`restore` → 403 ·
#                       없는 판 번호 404 · 볼 수는 있으나 고칠 수 없는 사람(Admin)의 되돌리기 403
#   C:T-43 휴지통       하위까지 삭제 → 휴지통에 보임 → 되살리면 하위까지 함께 ·
#                       부모가 아직 지워져 있으면 뿌리로 올라오고 응답에 `moved_to_root: true`
#   C:T-44 본문 안 샘   `versions`·`history` 응답 원문에 본문 글자 0건 · `versions/{n}` 에만 있다
#   C:T-45 파생 표      되돌린 뒤 `page_blocks`·`page_links` 가 되돌린 내용과 맞다
#   브라우저            browser_smoke.py 30~31 (이력 창 탭 둘·보기·되돌리기 · 휴지통 되살리기)
#   회귀                security_lint 0 · php -l · bash -n · doc-schema 사본 diff 0
#
# 규칙 (CLAUDE.md 3장) — stage3.sh·stage4a.sh·stage4b.sh 와 같다
#   · 검토 계정 `claude_` 다섯뿐. 시드 계정은 사용자 몫.
#   · 모든 요청에 `-A "tbox-review/<날짜>"`.
#   · 운영 DB 에 **DDL 을 실행하지 않는다.** psql 은 읽기 확인과 C:T-40(권한이 막는 것을
#     확인하는 실패하는 문장, `WHERE false`)에만 쓴다.
#   · 만든 페이지는 **앱 창구로만** 되돌린다(논리 삭제).
#
# ★ 마이그레이션 `db/migrations/2026-09-23_community_version_squash.sql` 은 **이 시험의
#   전제가 아니다.** 4-C 코드는 그 칸(`page_versions.created_at`)을 읽지도 쓰지도 않으므로
#   적용 전에도 뒤에도 같은 결과가 나온다(마이그레이션 머리글 ★). 적용 여부는 아래
#   「전제 확인」에서 눈으로만 알린다.
#
# 준비물: curl · php · python3 · sudo -n(seed-accounts.txt · qa-totp.txt · psql) · playwright
# 사용: sudo -v && bash tests/community/stage4c.sh
set -u

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
. "$ROOT/tests/lib/secrets.sh" || { echo "tests/lib/secrets.sh 를 읽지 못했다 — 시험을 시작하지 않는다 (R-62)"; exit 3; }
SITE="http://127.0.0.1:8082"
BASE="$SITE/tbox"
CBASE="$SITE/community"
UA="tbox-review/$(date +%F)"
DB="tbox_re"
JAR_DIR="$(mktemp -d)"
WORK="$(mktemp -d)"
PASS=0
FAIL=0
SKIP=0

MADE_PAGES=""     # 정리할 페이지 public_id (뿌리만 적는다 — 하위는 함께 내려간다)

cleanup() { rm -rf "$JAR_DIR" "$WORK"; }
trap cleanup EXIT

hr()  { printf '\n════════════════════════════════════════════════════════════\n%s\n════════════════════════════════════════════════════════════\n' "$1"; }
sub() { printf '\n── %s\n' "$1"; }
expect() {
  if [ "$2" = "$3" ]; then printf '  ✔ %s  (기대=%s 실제=%s)\n' "$1" "$2" "$3"; PASS=$((PASS+1))
  else printf '  ✖ %s  (기대=%s 실제=%s)\n' "$1" "$2" "$3"; FAIL=$((FAIL+1)); fi
}
contains() {
  case "$3" in
    *"$2"*) printf '  ✔ %s  (「%s」 포함)\n' "$1" "$2"; PASS=$((PASS+1)) ;;
    *)      printf '  ✖ %s  (「%s」 없음 — 실제: %s)\n' "$1" "$2" "$3"; FAIL=$((FAIL+1)) ;;
  esac
}
lacks() {
  case "$3" in
    *"$2"*) printf '  ✖ %s  (「%s」 가 응답에 있다 — 실제: %s)\n' "$1" "$2" "$3"; FAIL=$((FAIL+1)) ;;
    *)      printf '  ✔ %s  (「%s」 없음)\n' "$1" "$2"; PASS=$((PASS+1)) ;;
  esac
}
note() { printf '  · %s\n' "$1"; }
skip() { printf '  ○ SKIP %s  (%s)\n' "$1" "$2"; SKIP=$((SKIP+1)); }

psqlq() { sudo -n -u postgres psql -d "$DB" -t -A -c "$1" 2>/dev/null; }
mark()  { psqlq "SELECT coalesce(max(id),0) FROM audit_logs" 2>/dev/null; }

# 앱 계정(tbox_app)으로 한 문장 — C:T-40 전용. 값은 저장소의 .env 에서 읽고 출력하지 않는다.
appq() {
  local u p n h
  u=$(sed -n 's/^DB_USER=//p' "$ROOT/.env"); p=$(sed -n 's/^DB_PASS=//p' "$ROOT/.env")
  n=$(sed -n 's/^DB_NAME=//p' "$ROOT/.env"); h=$(sed -n 's/^DB_HOST=//p' "$ROOT/.env")
  [ -n "$u" ] || return 2
  PGPASSWORD="$p" psql -h "$h" -U "$u" -d "$n" -v VERBOSITY=verbose -t -A -c "$1" 2>&1 | head -1
}

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

qa_password() { sudo -n awk -v u="$1" '$1==u{print $3}' /etc/tbox-v2/seed-accounts.txt 2>/dev/null; }
qa_secret()   { sudo -n awk -v u="$1" '$1==u{print $2}' /etc/tbox-v2/qa-totp.txt 2>/dev/null; }

login() {
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

# 커뮤니티 창구 호출 — $1 메서드 $2 경로 $3 계정 $4 본문 파일(없으면 생략)
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

# 페이지 하나 만들기 — $1 계정 $2 제목 $3 부모(없으면 뿌리)
make_page() {
  local who="$1" title="$2" parent="${3:-}"
  python3 - "$title" "$parent" > "$WORK/new.json" <<'PY'
import json, sys
body = {"title": sys.argv[1]}
if sys.argv[2]:
    body["parent_id"] = sys.argv[2]
print(json.dumps(body, ensure_ascii=False))
PY
  local code
  code=$(capi POST /api/pages "$who" "$WORK/new.json")
  printf '%s|%s' "$code" "$(jget "d['page']['id']" < "$WORK/b")"
}

# 본문 저장 — $1 계정 $2 페이지 $3 판 번호 $4 문단 글자
save_text() {
  python3 - "$3" "$4" > "$WORK/save.json" <<'PY'
import json, sys, uuid
doc = {"schema": 1, "blocks": [{"id": str(uuid.uuid4()), "type": "paragraph",
                                "text": [{"t": sys.argv[2], "m": {}}]}]}
print(json.dumps({"doc_version": int(sys.argv[1]), "doc": doc}, ensure_ascii=False))
PY
  capi PATCH "/api/pages/$2" "$1" "$WORK/save.json"
}

# 할 일 하나 저장 — $1 계정 $2 페이지 $3 판 번호 $4 true/false $5 블록 id
save_todo() {
  python3 - "$3" "$4" "$5" > "$WORK/save.json" <<'PY'
import json, sys
doc = {"schema": 1, "blocks": [{"id": sys.argv[3], "type": "to_do",
                                "checked": sys.argv[2] == "true",
                                "text": [{"t": "보고서 마무리", "m": {}}]}]}
print(json.dumps({"doc_version": int(sys.argv[1]), "doc": doc}, ensure_ascii=False))
PY
  capi PATCH "/api/pages/$2" "$1" "$WORK/save.json"
}

snap_count() { psqlq "SELECT count(*) FROM community.page_versions v JOIN community.pages p ON p.id = v.page_id WHERE p.public_id = '$1'"; }
docver()     { psqlq "SELECT doc_version FROM community.pages WHERE public_id = '$1'"; }

# 빈 본문 — 되돌리기·되살리기 두 창구가 쓴다(받는 값이 없다).
printf '{}' > "$WORK/empty.json"

# ════════════════════════════════════════════════════════════════
hr "커뮤니티 4-C — 시험 시작 전 확인"

avail_kb=$(df -Pk / | awk 'NR==2{print $4}')
echo "루트 파티션 여유: $(df -Ph / | awk 'NR==2{print $4}') (${avail_kb}KB)"
[ "$avail_kb" -ge 1048576 ] || { echo "루트 디스크 여유 1GB 미만 — 시험을 시작하지 않는다 (SECURITY R-80)"; exit 3; }

HAVE_PSQL=0
if psqlq "SELECT 1" >/dev/null 2>&1; then HAVE_PSQL=1; fi

READY=$(curl -s -o /dev/null -w '%{http_code}' -A "$UA" "$CBASE/api/pages")
if [ "$READY" = "503" ]; then
  echo "커뮤니티 스키마가 아직 적용되지 않았다 (창구가 503) — 3단계 마이그레이션을 먼저 돌린다."
  exit 3
fi

if [ "$HAVE_PSQL" = "1" ]; then
  echo "· 4-C 마이그레이션(created_at) 적용 여부: $(psqlq "SELECT CASE WHEN count(*) > 0 THEN '적용됨' ELSE '아직(코드는 이 칸을 쓰지 않는다 — 결과 같음)' END FROM information_schema.columns WHERE table_schema='community' AND table_name='page_versions' AND column_name='created_at'")"
  echo "· 기록 표의 앱 계정 권한 (C:S-14 — r=SELECT · a=INSERT 만이어야 한다)"
  psqlq "SELECT c.relname || ' : ' || coalesce(array_to_string(c.relacl, ' '), '(없음)')
           FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
          WHERE n.nspname='community' AND c.relname IN ('page_versions','checklist_events')
          ORDER BY c.relname" | sed 's/^/    /'
else
  echo "psql 을 쓸 수 없다 — DB·감사 확인 항목은 SKIP 된다"
fi

AUDIT_START=$(mark); AUDIT_START=${AUDIT_START:-0}

sub "로그인 (qa 계정 셋 — 주인 · 남 · 관리자)"
for who in claude_employee01 claude_employee02 claude_admin01; do
  tok=$(login "$who")
  if [ -z "$tok" ]; then echo "  ✖ $who 로그인 실패 — 중단"; exit 4; fi
  eval "CSRF_${who}='$tok'"
  echo "  ✔ $who"
done

# ════════════════════════════════════════════════════════════════
hr "C:T-38  5분 합치기 — 판정 함수(단위) · 연속 저장 3회"

sub '판정 함수 community_squash_snapshot() 를 단위로 (DB 를 읽지 않는 함수다)'
php -r '
require "'"$ROOT"'/src/community/routes.php";
$now = "2026-09-23 10:00:00+09";
$cases = [
  ["직전 스냅샷이 없다(첫 저장)",  null,                                                                        7, false],
  ["같은 사람 · 1분 전",           ["edited_by"=>7,"snapshot_at"=>"2026-09-23 09:59:00+09","now_at"=>$now],       7, true],
  ["같은 사람 · 4분 59초 전",      ["edited_by"=>7,"snapshot_at"=>"2026-09-23 09:55:01+09","now_at"=>$now],       7, true],
  ["같은 사람 · 5분 정각(창 밖)",  ["edited_by"=>7,"snapshot_at"=>"2026-09-23 09:55:00+09","now_at"=>$now],       7, false],
  ["같은 사람 · 6분 전",           ["edited_by"=>7,"snapshot_at"=>"2026-09-23 09:54:00+09","now_at"=>$now],       7, false],
  ["다른 사람 · 1분 전",           ["edited_by"=>8,"snapshot_at"=>"2026-09-23 09:59:00+09","now_at"=>$now],       7, false],
  ["시각이 미래(모르는 상태)",     ["edited_by"=>7,"snapshot_at"=>"2026-09-23 10:01:00+09","now_at"=>$now],       7, false],
  ["시각을 읽을 수 없다",          ["edited_by"=>7,"snapshot_at"=>"???","now_at"=>$now],                          7, false],
];
$bad = 0;
foreach ($cases as [$name, $last, $me, $want]) {
  $got = community_squash_snapshot($last, $me);
  if ($got !== $want) { $bad++; }
  printf("    %s %-28s 기대=%-5s 실제=%s\n", $got === $want ? "✔" : "✖", $name,
         var_export($want, true), var_export($got, true));
}
echo "SQUASH_BAD=$bad\n";
' > "$WORK/squash.txt" 2>&1
sed -n '/^    /p' "$WORK/squash.txt"
expect "5분 합치기 판정 8가지 전부 기대대로" "SQUASH_BAD=0" "$(grep '^SQUASH_BAD=' "$WORK/squash.txt")"

sub "연속 저장 3회 — 스냅샷은 1개만 늘고 판 번호는 3 오른다"
RES=$(make_page claude_employee01 "4C-합치기")
SQ=${RES#*|}
expect "페이지 만들기" "201" "${RES%%|*}"
MADE_PAGES="$MADE_PAGES $SQ"

if [ "$HAVE_PSQL" = "1" ]; then
  SNAP0=$(snap_count "$SQ"); VER0=$(docver "$SQ")
  note "저장 전 — 스냅샷 ${SNAP0}개 · 판 ${VER0}"
  V=$VER0
  for n in 1 2 3; do
    CODE=$(save_text claude_employee01 "$SQ" "$V" "연속 저장 $n 회차")
    expect "저장 $n 회차" "200" "$CODE"
    V=$((V+1))
  done
  SNAP1=$(snap_count "$SQ"); VER1=$(docver "$SQ")
  expect "스냅샷은 1개만 늘었다" "$((SNAP0+1))" "$SNAP1"
  expect "판 번호는 3 올랐다" "$((VER0+3))" "$VER1"
  note "저장 뒤 — 스냅샷 ${SNAP1}개 · 판 ${VER1}"
  note "남은 스냅샷: $(psqlq "SELECT string_agg('v'||v.version, ' ' ORDER BY v.version) FROM community.page_versions v JOIN community.pages p ON p.id=v.page_id WHERE p.public_id='$SQ'")"
else
  skip "연속 저장 3회" "psql 없음 — **미검증**"
fi

# ════════════════════════════════════════════════════════════════
hr "C:T-39 · C:T-45  되돌리기 — 새 판으로 복사 · 과거는 그대로 · 파생 표"

RES=$(make_page claude_employee01 "4C-되돌리기")
RP=${RES#*|}
expect "페이지 만들기" "201" "${RES%%|*}"
MADE_PAGES="$MADE_PAGES $RP"

V=$(jget "d['page']['doc_version']" < "$WORK/b")
CODE=$(save_text claude_employee01 "$RP" "$V" "첫 번째 내용")
expect "첫 저장" "200" "$CODE"
V=$(jget "d['doc_version']" < "$WORK/b")

# 다른 사람이 끼어들지 않으면 두 번째 저장은 합쳐진다 — 되돌릴 판을 만들려면 그 사이에
# **스냅샷이 남는 저장**이 있어야 한다. 그래서 되돌릴 대상은 「첫 저장이 남긴 판」이다.
sub "판 목록"
CODE=$(capi GET "/api/pages/$RP/versions" claude_employee01)
expect "GET …/versions" "200" "$CODE"
VLIST=$(cat "$WORK/b")
note "응답: $VLIST"
TARGET=$(jget "[v['version'] for v in d['versions'] if not v['is_current']][0]" < "$WORK/b")
note "되돌릴 판 = $TARGET"

CODE=$(capi GET "/api/pages/$RP/versions/$TARGET" claude_employee01)
expect "GET …/versions/$TARGET (그 판의 본문)" "200" "$CODE"
OLDDOC=$(jget "d['version']['doc']" < "$WORK/b")
note "그 판의 doc: $OLDDOC"

if [ "$HAVE_PSQL" = "1" ]; then
  SNAPB=$(snap_count "$RP")
  SNAPHASH=$(psqlq "SELECT md5(string_agg(v.version || ':' || v.doc::text, '|' ORDER BY v.version)) FROM community.page_versions v JOIN community.pages p ON p.id=v.page_id WHERE p.public_id='$RP'")
fi
VERB=$(jget "d['page']['doc_version']" < /dev/null; echo "")
VERB=$(docver "$RP")

CODE=$(capi POST "/api/pages/$RP/versions/$TARGET/restore" claude_employee01 "$WORK/empty.json")
expect "POST …/versions/$TARGET/restore" "200" "$CODE"
note "응답: $(cat "$WORK/b")"
NEWV=$(jget "d['page']['doc_version']" < "$WORK/b")

CODE=$(capi GET "/api/pages/$RP" claude_employee01)
NOWDOC=$(jget "d['page']['doc']" < "$WORK/b")
expect "되돌린 내용이 지금 내용이 됐다" "$OLDDOC" "$NOWDOC"

if [ "$HAVE_PSQL" = "1" ]; then
  expect "판 번호가 올랐다" "$((VERB+1))" "$(docver "$RP")"
  SNAPA=$(snap_count "$RP")
  expect "과거 스냅샷은 줄지 않았다 (하나 늘어난다 — 지금 판이 남았다)" "$((SNAPB+1))" "$SNAPA"
  SNAPHASH2=$(psqlq "SELECT md5(string_agg(v.version || ':' || v.doc::text, '|' ORDER BY v.version)) FROM community.page_versions v JOIN community.pages p ON p.id=v.page_id WHERE p.public_id='$RP' AND v.version <= $VERB - 1")
  # 되돌리기 전에 있던 판들의 내용이 한 글자도 바뀌지 않았다.
  expect "되돌리기 전 판들의 내용 불변 (md5)" "$SNAPHASH" "$(psqlq "SELECT md5(string_agg(v.version || ':' || v.doc::text, '|' ORDER BY v.version)) FROM community.page_versions v JOIN community.pages p ON p.id=v.page_id WHERE p.public_id='$RP' AND v.version < $VERB")"
  expect "감사 page.restore_version allow 1건" "1" \
    "$(psqlq "SELECT count(*) FROM audit_logs WHERE id > ${AUDIT_START} AND action='page.restore_version' AND result='allow'")"
  note "감사: $(psqlq "SELECT summary FROM audit_logs WHERE id > ${AUDIT_START} AND action='page.restore_version' ORDER BY id DESC LIMIT 1")"
  note "상세: $(psqlq "SELECT detail FROM audit_logs WHERE id > ${AUDIT_START} AND action='page.restore_version' ORDER BY id DESC LIMIT 1")"

  sub "C:T-45  파생 표가 되돌린 내용과 맞다"
  DB_BLOCKS=$(psqlq "SELECT string_agg(b.block_id::text || ':' || b.block_type, ' ' ORDER BY b.ord) FROM community.page_blocks b JOIN community.pages p ON p.id=b.page_id WHERE p.public_id='$RP'")
  DOC_BLOCKS=$(capi GET "/api/pages/$RP" claude_employee01 >/dev/null; jget "' '.join(b['id']+':'+b['type'] for b in d['page']['doc']['blocks'])" < "$WORK/b")
  expect "page_blocks 가 되돌린 doc 과 같다" "$DOC_BLOCKS" "$DB_BLOCKS"
  expect "page_links 는 되돌린 내용대로 0건" "0" \
    "$(psqlq "SELECT count(*) FROM community.page_links l JOIN community.pages p ON p.id=l.from_page_id WHERE p.public_id='$RP'")"
  expect "block_count 가 doc 과 같다" "$(jget "len(d['page']['doc']['blocks'])" < "$WORK/b")" \
    "$(psqlq "SELECT block_count FROM community.pages WHERE public_id='$RP'")"
else
  skip "되돌리기 DB 확인" "psql 없음 — **미검증**"
fi

sub "되돌리기 표시가 **다음 판**에 붙는다"
CODE=$(capi GET "/api/pages/$RP/versions" claude_employee01)
contains "새 판에 restored_from=$TARGET" "\"restored_from\":$TARGET" "$(cat "$WORK/b")"

sub "같은 판을 다시 되돌리기(지금 판) · 없는 판"
CODE=$(capi POST "/api/pages/$RP/versions/$NEWV/restore" claude_employee01 "$WORK/empty.json")
expect "지금 판으로 되돌리기 → 400 already_current" "400" "$CODE"
contains "사유 already_current" '"reason":"already_current"' "$(cat "$WORK/b")"
CODE=$(capi POST "/api/pages/$RP/versions/999999/restore" claude_employee01 "$WORK/empty.json")
expect "없는 판으로 되돌리기 → 404" "404" "$CODE"

# ════════════════════════════════════════════════════════════════
hr "C:T-40  기록 불변 (S-14) — 앱 계정으로 UPDATE·DELETE 는 전부 42501"

if [ -r "$ROOT/.env" ]; then
  for q in "UPDATE community.page_versions SET title = 'x' WHERE false" \
           "DELETE FROM community.page_versions WHERE false" \
           "UPDATE community.checklist_events SET checked = false WHERE false" \
           "DELETE FROM community.checklist_events WHERE false"; do
    OUT=$(appq "$q")
    printf '  · %s\n' "$q"
    case "$OUT" in
      *42501*) printf '  ✔ 42501 permission denied  (%s)\n' "$OUT"; PASS=$((PASS+1)) ;;
      *)       printf '  ✖ 42501 이 아니다  (%s)\n' "$OUT"; FAIL=$((FAIL+1)) ;;
    esac
  done
  OUT=$(appq "SELECT count(*) FROM community.page_versions")
  case "$OUT" in
    ''|*ERROR*) printf '  ✖ 앱 계정이 SELECT 도 못 한다 (%s)\n' "$OUT"; FAIL=$((FAIL+1)) ;;
    *)          printf '  ✔ 앱 계정은 SELECT 는 된다 (행 %s개)\n' "$OUT"; PASS=$((PASS+1)) ;;
  esac
else
  skip "기록 불변" ".env 를 읽을 수 없다 — **미검증**"
fi

# ════════════════════════════════════════════════════════════════
hr "C:T-41  체크 이력 — 켜고 끄고 다시 켜기"

RES=$(make_page claude_employee01 "4C-체크")
CP=${RES#*|}
expect "페이지 만들기" "201" "${RES%%|*}"
MADE_PAGES="$MADE_PAGES $CP"
BID="22222222-2222-4222-8222-222222222222"

V=$(jget "d['page']['doc_version']" < "$WORK/b")
for st in false true false true; do
  CODE=$(save_todo claude_employee01 "$CP" "$V" "$st" "$BID")
  expect "checked=$st 로 저장" "200" "$CODE"
  V=$(jget "d['doc_version']" < "$WORK/b")
done

if [ "$HAVE_PSQL" = "1" ]; then
  expect "checklist_events 3행 (켜짐·꺼짐·켜짐)" "3" \
    "$(psqlq "SELECT count(*) FROM community.checklist_events e JOIN community.pages p ON p.id=e.page_id WHERE p.public_id='$CP'")"
  expect "순서가 true,false,true" "t,f,t" \
    "$(psqlq "SELECT string_agg(CASE WHEN e.checked THEN 't' ELSE 'f' END, ',' ORDER BY e.at) FROM community.checklist_events e JOIN community.pages p ON p.id=e.page_id WHERE p.public_id='$CP'")"
  expect "누른 사람이 모두 claude_employee01" "claude_employee01" \
    "$(psqlq "SELECT DISTINCT u.username FROM community.checklist_events e JOIN community.pages p ON p.id=e.page_id JOIN users u ON u.id=e.by_user_id WHERE p.public_id='$CP'")"
  note "기록: $(psqlq "SELECT string_agg(e.checked::text || '@' || to_char(e.at,'HH24:MI:SS'), ' → ' ORDER BY e.at) FROM community.checklist_events e JOIN community.pages p ON p.id=e.page_id WHERE p.public_id='$CP'")"
else
  skip "checklist_events 행 수" "psql 없음 — **미검증**"
fi

CODE=$(capi GET "/api/pages/$CP/history" claude_employee01)
expect "GET …/history" "200" "$CODE"
HIST=$(cat "$WORK/b")
expect "「처리 이력」에 체크 줄 3개" "3" "$(jget "len([e for e in d['events'] if e['kind']=='check'])" < "$WORK/b")"
expect "시간순(내림차순)으로 온다" "True" \
  "$(jget "[e['at'] for e in d['events']] == sorted([e['at'] for e in d['events']], reverse=True)" < "$WORK/b")"
contains "누가 눌렀나가 줄마다 있다" '"by":"claude_employee01"' "$HIST"

# ════════════════════════════════════════════════════════════════
hr "C:T-44  이력 응답에 본문 글자가 새지 않는가"

# 본문에만 있는 글자다 — 제목에도, 블록 id 에도 없다.
lacks "versions 응답에 본문 글자 없음" "보고서 마무리" "$(capi GET "/api/pages/$CP/versions" claude_employee01 >/dev/null; cat "$WORK/b")"
lacks "history 응답에 본문 글자 없음" "보고서 마무리" "$HIST"
lacks "versions 응답에 doc 통째가 없음" '"doc"' "$(capi GET "/api/pages/$CP/versions" claude_employee01 >/dev/null; cat "$WORK/b")"
lacks "history 응답에 doc 통째가 없음" '"doc"' "$HIST"
# 할 일이 든 판은 **지금 판**이다(판 1 은 페이지를 만들 때의 빈 문단이다).
NOWV=$(jget "d['page']['doc_version']" < "$WORK/b")
CODE=$(capi GET "/api/pages/$CP/versions/$NOWV" claude_employee01)
expect "본문은 versions/{num} 에서만 (200)" "200" "$CODE"
contains "그 창구에는 본문이 있다" "보고서 마무리" "$(cat "$WORK/b")"
note "versions 응답 원문: $(capi GET "/api/pages/$CP/versions" claude_employee01 >/dev/null; cat "$WORK/b")"

# ════════════════════════════════════════════════════════════════
hr "C:T-42  남의 이력 · 없는 판 번호 · 볼 수는 있으나 고칠 수 없는 사람"

sub "다른 팀 사람(claude_employee02) — 비공개 페이지의 이력"
for path in "/api/pages/$CP/versions" "/api/pages/$CP/history" "/api/pages/$CP/versions/1"; do
  expect "GET $path → 403" "403" "$(capi GET "$path" claude_employee02)"
done
expect "POST …/versions/1/restore → 403" "403" \
  "$(capi POST "/api/pages/$CP/versions/1/restore" claude_employee02 "$WORK/empty.json")"
lacks "거부 응답에 제목이 새지 않는다" "4C-체크" "$(cat "$WORK/b")"

sub "없는 판 번호"
expect "GET …/versions/999999 → 404" "404" "$(capi GET "/api/pages/$CP/versions/999999" claude_employee01)"
expect "라우트에 맞지 않는 판 번호(글자) → 404" "404" "$(capi GET "/api/pages/$CP/versions/abc" claude_employee01)"

sub "관리자 — 볼 수는 있으나(admin_all) 되돌리지는 못한다"
CODE=$(capi GET "/api/pages/$CP/versions" claude_admin01)
expect "관리자는 이력을 본다 (200)" "200" "$CODE"
contains "되돌리기 단추를 열지 않는다 (can_edit=false)" '"can_edit":false' "$(cat "$WORK/b")"
CODE=$(capi POST "/api/pages/$CP/versions/1/restore" claude_admin01 "$WORK/empty.json")
expect "관리자의 되돌리기 → 403" "403" "$CODE"
if [ "$HAVE_PSQL" = "1" ]; then
  expect "감사 page.restore_version deny not_owner 1건" "1" \
    "$(psqlq "SELECT count(*) FROM audit_logs WHERE id > ${AUDIT_START} AND action='page.restore_version' AND result='deny' AND reason='not_owner'")"
  note "감사: $(psqlq "SELECT summary FROM audit_logs WHERE id > ${AUDIT_START} AND action='page.restore_version' AND result='deny' ORDER BY id DESC LIMIT 1")"
  note "관리자 열람 기록: $(psqlq "SELECT count(*) FROM audit_logs WHERE id > ${AUDIT_START} AND action='page.read' AND result='allow' AND reason='admin_all'")건"
fi

sub "볼 수는 있으나 고칠 수 없는 사람의 삭제 — 3단계에 빠졌던 감사를 4-C 에서 보완했다"
# claude_employee02 는 이 페이지를 **보지도 못하므로** 읽기 관문에서 끝난다(`page.read deny not_owner`).
# 「삭제 거부」 줄에 닿는 사람은 볼 수는 있으나 고칠 수 없는 사람 — 여기서는 관리자다.
expect "claude_employee02 의 DELETE → 403 (읽기에서 막힌다)" "403" "$(capi DELETE "/api/pages/$CP" claude_employee02)"
CODE=$(capi DELETE "/api/pages/$CP" claude_admin01)
expect "claude_admin01 의 DELETE → 403" "403" "$CODE"
if [ "$HAVE_PSQL" = "1" ]; then
  expect "감사 page.delete deny not_owner 1건" "1" \
    "$(psqlq "SELECT count(*) FROM audit_logs WHERE id > ${AUDIT_START} AND action='page.delete' AND result='deny' AND reason='not_owner'")"
  note "감사: $(psqlq "SELECT summary FROM audit_logs WHERE id > ${AUDIT_START} AND action='page.delete' AND result='deny' ORDER BY id DESC LIMIT 1")"
fi

# ════════════════════════════════════════════════════════════════
hr "C:T-43  휴지통 — 하위까지 함께 · 부모가 지워져 있으면 뿌리로"

RES=$(make_page claude_employee01 "4C-부모"); TA=${RES#*|}
expect "부모 만들기" "201" "${RES%%|*}"
RES=$(make_page claude_employee01 "4C-자식" "$TA"); TB=${RES#*|}
expect "자식 만들기" "201" "${RES%%|*}"
RES=$(make_page claude_employee01 "4C-손자" "$TB"); TC=${RES#*|}
expect "손자 만들기" "201" "${RES%%|*}"
MADE_PAGES="$MADE_PAGES $TA"

CODE=$(capi DELETE "/api/pages/$TA" claude_employee01)
expect "부모를 지우면 하위까지 3건" "200" "$CODE"
contains "응답에 deleted=3" '"deleted":3' "$(cat "$WORK/b")"

sub "휴지통에 보인다"
CODE=$(capi GET /api/me/trash claude_employee01)
expect "GET /api/me/trash" "200" "$CODE"
expect "지운 뿌리가 목록에 있다" "1" \
  "$(jget "len([p for p in d['pages'] if p['id']=='$TA'])" < "$WORK/b")"
expect "함께 내려간 하위를 2개로 센다" "2" \
  "$(jget "[p['descendants'] for p in d['pages'] if p['id']=='$TA'][0]" < "$WORK/b")"
expect "함께 내려간 하위는 따로 줄로 서지 않는다" "0" \
  "$(jget "len([p for p in d['pages'] if p['id'] in ('$TB','$TC')])" < "$WORK/b")"
lacks "휴지통 응답에 본문이 없다" '"doc"' "$(cat "$WORK/b")"
note "그 줄: $(jget "[p for p in d['pages'] if p['id']=='$TA'][0]" < "$WORK/b")"

sub "부모가 아직 지워져 있는데 자식만 되살리면 — 뿌리로 올라온다"
CODE=$(capi POST "/api/pages/$TB/restore" claude_employee01 "$WORK/empty.json")
expect "POST …/restore (자식)" "200" "$CODE"
contains "응답에 moved_to_root: true" '"moved_to_root":true' "$(cat "$WORK/b")"
contains "손자까지 함께 돌아왔다 (restored=2)" '"restored":2' "$(cat "$WORK/b")"
if [ "$HAVE_PSQL" = "1" ]; then
  expect "자식은 살아 있고 뿌리다" "살아있음|뿌리" \
    "$(psqlq "SELECT CASE WHEN deleted_at IS NULL THEN '살아있음' ELSE '지워짐' END || '|' || CASE WHEN parent_page_id IS NULL THEN '뿌리' ELSE '아래' END FROM community.pages WHERE public_id='$TB'")"
  expect "손자도 살아 있고 자식 아래 그대로다" "살아있음|아래" \
    "$(psqlq "SELECT CASE WHEN deleted_at IS NULL THEN '살아있음' ELSE '지워짐' END || '|' || CASE WHEN parent_page_id IS NULL THEN '뿌리' ELSE '아래' END FROM community.pages WHERE public_id='$TC'")"
  expect "부모는 아직 휴지통에 있다" "지워짐" \
    "$(psqlq "SELECT CASE WHEN deleted_at IS NULL THEN '살아있음' ELSE '지워짐' END FROM community.pages WHERE public_id='$TA'")"
  note "감사: $(psqlq "SELECT summary || ' :: ' || detail FROM audit_logs WHERE id > ${AUDIT_START} AND action='page.restore' ORDER BY id DESC LIMIT 1")"
fi

sub "부모도 되살린다"
CODE=$(capi POST "/api/pages/$TA/restore" claude_employee01 "$WORK/empty.json")
expect "POST …/restore (부모)" "200" "$CODE"
contains "혼자 돌아온다 (restored=1 — 하위는 이미 살아 있다)" '"restored":1' "$(cat "$WORK/b")"
contains "뿌리로 옮기지 않았다" '"moved_to_root":false' "$(cat "$WORK/b")"
expect "되살린 페이지를 다시 읽을 수 있다" "200" "$(capi GET "/api/pages/$TA" claude_employee01)"

sub "남이 지운 것은 내 휴지통에 없고, 되살리지도 못한다"
CODE=$(capi DELETE "/api/pages/$TA" claude_employee01)
expect "다시 지우기" "200" "$CODE"
CODE=$(capi GET /api/me/trash claude_employee02)
expect "claude_employee02 의 휴지통은 200" "200" "$CODE"
expect "그 안에 남의 페이지가 없다" "0" "$(jget "len([p for p in d['pages'] if p['id']=='$TA'])" < "$WORK/b")"
expect "남의 페이지 되살리기 → 403" "403" \
  "$(capi POST "/api/pages/$TA/restore" claude_employee02 "$WORK/empty.json")"
CODE=$(capi POST "/api/pages/$TA/restore" claude_employee01 "$WORK/empty.json")
expect "주인이 다시 되살린다" "200" "$CODE"

sub "상위가 그 사이 **좁아졌으면** 뿌리로 올린다 (4-B 트리 불변 규칙을 되살리기가 깨지 않는다)"
# 부모를 사내로 열고 자식도 사내로 연 뒤, 자식을 지우고 부모를 비공개로 좁힌다.
# 좁히기 검사는 **살아 있는 하위만** 보므로 지워진 자식은 `internal` 을 들고 휴지통에 남는다.
RES=$(make_page claude_employee01 "4C-좁아진부모"); NA=${RES#*|}
RES=$(make_page claude_employee01 "4C-넓은자식" "$NA"); NB=${RES#*|}
MADE_PAGES="$MADE_PAGES $NA"
expect "부모를 사내로" "200" "$(printf '{"visibility":"internal"}' > "$WORK/vis.json"; capi PATCH "/api/pages/$NA/visibility" claude_employee01 "$WORK/vis.json")"
expect "자식을 사내로" "200" "$(printf '{"visibility":"internal"}' > "$WORK/vis.json"; capi PATCH "/api/pages/$NB/visibility" claude_employee01 "$WORK/vis.json")"
expect "자식을 지운다" "200" "$(capi DELETE "/api/pages/$NB" claude_employee01)"
expect "부모를 비공개로 좁힌다 (지워진 자식은 검사에 걸리지 않는다)" "200" \
  "$(printf '{"visibility":"private"}' > "$WORK/vis.json"; capi PATCH "/api/pages/$NA/visibility" claude_employee01 "$WORK/vis.json")"
CODE=$(capi POST "/api/pages/$NB/restore" claude_employee01 "$WORK/empty.json")
expect "자식을 되살린다" "200" "$CODE"
contains "뿌리로 올렸다" '"moved_to_root":true' "$(cat "$WORK/b")"
contains "까닭이 parent_narrower" '"moved_reason":"parent_narrower"' "$(cat "$WORK/b")"
if [ "$HAVE_PSQL" = "1" ]; then
  expect "자식은 뿌리이고 범위는 그대로 사내" "뿌리|internal" \
    "$(psqlq "SELECT CASE WHEN parent_page_id IS NULL THEN '뿌리' ELSE '아래' END || '|' || visibility FROM community.pages WHERE public_id='$NB'")"
  expect "자식보다 좁은 부모 아래에 붙지 않았다 (트리 불변 규칙 유지)" "0" \
    "$(psqlq "SELECT count(*) FROM community.pages c JOIN community.pages par ON par.id = c.parent_page_id WHERE c.public_id='$NB' AND (CASE c.visibility WHEN 'private' THEN 0 WHEN 'team' THEN 1 WHEN 'internal' THEN 2 ELSE 3 END) > (CASE par.visibility WHEN 'private' THEN 0 WHEN 'team' THEN 1 WHEN 'internal' THEN 2 ELSE 3 END)")"
fi
MADE_PAGES="$MADE_PAGES $NB"

sub "지워지지 않은 페이지에 되살리기 → 404"
expect "POST …/restore (살아 있는 페이지)" "404" \
  "$(capi POST "/api/pages/$TA/restore" claude_employee01 "$WORK/empty.json")"

# ════════════════════════════════════════════════════════════════
hr "브라우저 — 이력 창(탭 둘 · 보기 · 되돌리기) · 휴지통 되살리기"

SMOKE_OUT="$WORK/smoke.txt"
SESSION=$(awk '$6=="__Host-tbox_session"{print $7}' "$JAR_DIR/claude_employee01.jar" 2>/dev/null)
if [ -z "$SESSION" ]; then
  skip "브라우저 스모크" "세션 쿠키를 읽지 못했다 — **미검증**"
else
  # 세션 값은 표준입력 첫 줄로 넘긴다(명령 인자에 싣지 않는다 — tests/lib/secrets.sh py_secret · R-62)
  PATH="$HOME/.local/bin:$PATH" py_secret SESSION -- python3 "$ROOT/tests/community/browser_smoke.py" \
    "$SITE" "$UA" > "$SMOKE_OUT" 2>&1
  SMOKE_RC=$?
  sed -n '/── 30\./,/── 19\./p' "$SMOKE_OUT" | sed 's/^/  /'
  if grep -q '^SMOKE_FAIL=' "$SMOKE_OUT"; then
    expect "브라우저에서 pageerror 0건" "PAGEERROR=0" "$(grep '^PAGEERROR=' "$SMOKE_OUT")"
    expect "브라우저에서 콘솔 오류 0건" "CONSOLEERR=0" "$(grep '^CONSOLEERR=' "$SMOKE_OUT")"
    expect "브라우저에서 실패한 요청 0건" "REQFAIL=0" "$(grep '^REQFAIL=' "$SMOKE_OUT")"
    expect "브라우저에서 HTTP 400 이상 0건" "HTTP4XX=0" "$(grep '^HTTP4XX=' "$SMOKE_OUT")"
    expect "스모크 항목 실패 0건" "SMOKE_FAIL=0" "$(grep '^SMOKE_FAIL=' "$SMOKE_OUT")"
    note "일부러 거부당한 요청(4-B 28번): $(grep '^EXPECTEDBAD=' "$SMOKE_OUT")"
  else
    skip "브라우저 스모크" "브라우저를 열지 못했다 (rc=$SMOKE_RC) — **미검증**"
    note "$(tail -4 "$SMOKE_OUT" | tr '\n' ' ')"
  fi
fi

# ════════════════════════════════════════════════════════════════
hr "회귀 — 스키마 사본 · 린트 · 문법"

expect "doc-schema.json 사본이 원본과 같다" "0" \
  "$(diff "$ROOT/../community/doc-schema.json" "$ROOT/public/assets/community/doc-schema.json" >/dev/null 2>&1; echo $?)"

bash "$ROOT/scripts/security_lint.sh" > "$WORK/lint.txt" 2>&1
expect "금지 패턴 0건" "0" "$?"
tail -2 "$WORK/lint.txt"

n_bad=0
for f in $(find "$ROOT/src/community" -name '*.php') "$ROOT/src/Audit.php"; do
  php -l "$f" >/dev/null 2>&1 || { n_bad=$((n_bad+1)); echo "  php -l 실패: $f"; }
done
expect "바뀐 PHP 문법 오류 0건" "0" "$n_bad"
bash -n "$0" && expect "이 시험 파일 bash -n" "0" "0" || expect "이 시험 파일 bash -n" "0" "1"
python3 -c 'import sys; compile(open(sys.argv[1], encoding="utf-8").read(), sys.argv[1], "exec")' \
  "$ROOT/tests/community/browser_smoke.py" 2>/dev/null \
  && expect "browser_smoke.py 구문" "0" "0" || expect "browser_smoke.py 구문" "0" "1"
if python3 -c "import esprima" >/dev/null 2>&1; then
  PATH="$HOME/.local/bin:$PATH" python3 -c '
import esprima, sys
esprima.parseModule(open(sys.argv[1], encoding="utf-8").read())
' "$ROOT/public/assets/community/editor.js" 2>/dev/null \
    && expect "editor.js 구문 (esprima, 모듈)" "0" "0" || expect "editor.js 구문 (esprima, 모듈)" "0" "1"
else
  skip "editor.js 구문" "esprima 가 없다 — **미검증**"
fi

# ════════════════════════════════════════════════════════════════
hr "정리 — 만든 페이지를 앱 창구로 되돌린다 (논리 삭제)"

for pid in $MADE_PAGES; do
  [ -n "$pid" ] || continue
  CODE=$(capi DELETE "/api/pages/$pid" claude_employee01)
  printf '  · %s → %s\n' "$pid" "$CODE"
done

if [ "$HAVE_PSQL" = "1" ]; then
  note "시험이 만든 감사 행(전체): $(psqlq "SELECT count(*) FROM audit_logs WHERE id > ${AUDIT_START}")건"
  note "기록 표는 지우지 않는다 — 스냅샷 $(psqlq "SELECT count(*) FROM community.page_versions")행 · 체크 $(psqlq "SELECT count(*) FROM community.checklist_events")행 (S-14)"
fi

hr "결과"
printf '통과 %d · 실패 %d · 건너뜀 %d\n' "$PASS" "$FAIL" "$SKIP"
[ "$FAIL" -eq 0 ] || exit 1
