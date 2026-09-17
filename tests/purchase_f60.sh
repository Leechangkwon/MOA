#!/usr/bin/env bash
# tests/purchase_f60.sh — F-60 수락 기준 실행 (PRD 12.2 T-72)
#   불러오기 「전표」 묶음 · 저장 시 거래처별 발주서 분할
#   (PRD F-60 · F-32 · S-01 · ARCH 5.18 「분할 저장」 · SECURITY R-65 · R-73 · R-78)
#
# 규칙 (CLAUDE.md 3장)
#   · **검토 계정(qa_ 접두)만** 쓴다. 시드 계정(admin01 …)은 사용자의 자기 공격 전용이다.
#   · 모든 요청에 `-A "tbox-review/<날짜>"` 를 붙인다.
#   · 비밀번호·TOTP 비밀은 **필요한 필드만 awk 로** 뽑고 변수에만 담는다(화면에 찍지 않는다).
#
# ★ 시험 안전 (SECURITY R-73 · R-78)
#   ① 최근 10분 감사 로그에 비-qa actor 가 있으면 시작하지 않는다(종료 코드 3).
#   ② 정리는 `created_by` 가 검토 계정인 행만. 표 전체 DELETE·TRUNCATE 는 한 줄도 없다.
#   ③ 채번 카운터 `p_doc_seq` 는 **지우지 않는다** — 남은 전표의 최대 번호로 되돌린다(R-78).
#   ④ 시작·끝에 비-qa 행 수를 찍고 달라졌으면 실패로 센다.
#   ⑤ 시험이 만든 요청·발주서는 정리 직전에 **창구(void)로 취소**한 뒤 지운다.
#
# 사용: sudo -v && bash tests/purchase_f60.sh
set -u

BASE="http://127.0.0.1:8082"
UA="tbox-review/$(date +%F)"
DB="tbox_re"
JAR_DIR="$(mktemp -d)"
# 화면 시험의 부산물(스크린샷·JSON)을 두는 곳. F60_OUT 으로 밖에 남길 수 있다.
OUT="${F60_OUT:-$JAR_DIR/out}"; mkdir -p "$OUT"
PASS=0
FAIL=0
TODAY="$(date +%Y%m%d)"

psqlq() { sudo -n -u postgres psql -d "$DB" -t -A -c "$1"; }
psqlc() { sudo -n -u postgres psql -d "$DB" -v ON_ERROR_STOP=1 -q -c "$1"; }

hr()  { printf '\n════════════════════════════════════════════════════════════\n%s\n════════════════════════════════════════════════════════════\n' "$1"; }
sub() { printf '\n── %s\n' "$1"; }

expect() {
  if [ "$2" = "$3" ]; then
    printf '  ✔ %s  (기대=%s 실제=%s)\n' "$1" "$2" "$3"; PASS=$((PASS+1))
  else
    printf '  ✖ %s  (기대=%s 실제=%s)\n' "$1" "$2" "$3"; FAIL=$((FAIL+1))
  fi
}

# ─────────────────────────────────────────────────────────────
# ① 사용자 활동 확인 — R-73 ②
# ─────────────────────────────────────────────────────────────
BUSY=$(psqlq "SELECT count(*) FROM audit_logs
               WHERE happened_at > now() - interval '10 minutes'
                 AND actor_name NOT LIKE 'qa\\_%';")
if [ "${BUSY:-1}" != "0" ]; then
  echo "■ 최근 10분 감사 로그에 비-qa actor 가 ${BUSY}건 있습니다 — 사용자가 작업 중입니다."
  echo "■ 데이터를 남기는 시험을 시작하지 않고 끝냅니다 (SECURITY R-73 ②). 종료 코드 3."
  rm -rf "$JAR_DIR"
  exit 3
fi
echo "■ 최근 10분 비-qa 활동 0건 — 시험을 시작합니다."

# ─── 로그인 ──────────────────────────────────────────────────
qa_password() { sudo -n awk -v u="$1" '$1==u{print $3}' /etc/tbox-v2/seed-accounts.txt; }
qa_secret()   { sudo -n awk -v u="$1" '$1==u{print $2}' /etc/tbox-v2/qa-totp.txt; }

login() {
  local who="$1" jar="$JAR_DIR/$1.jar" pw sec raw code left out try
  pw=$(qa_password "$who")
  sec=$(qa_secret "$who")
  for try in 1 2 3; do
    raw=$(php "$(dirname "$0")/../scripts/totp_code.php" "$sec")
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
    printf '  · %s 코드 재사용으로 거부됨 — 다음 창까지 %s초 기다림 (R-45 가 막은 것)\n' "$who" "$left" >&2
    sleep $((left + 1))
  done
  printf '  ! %s 로그인 실패\n' "$who" >&2
  return 1
}

# 브라우저 로그인은 **다음 창의 코드**로 들어간다 — 방금 curl 로 쓴 코드는 1회성이라 거부된다(R-45).
next_code() {
  local raw left
  raw=$(php "$(dirname "$0")/../scripts/totp_code.php" "$1")
  left=$(printf '%s' "$raw" | sed -n 's/^남은 시간: \([0-9]*\)초.*/\1/p')
  [ -n "$left" ] || left=30
  printf '  · 다음 코드 창까지 %s초 기다림 (코드 1회성 R-45)\n' "$left" >&2
  sleep $((left + 1))
  php "$(dirname "$0")/../scripts/totp_code.php" "$1" | head -1
}

reauth() {
  local who="$1" token="$2" pw
  pw=$(qa_password "$who")
  curl -s -o /dev/null -w '%{http_code}' -b "$JAR_DIR/$who.jar" -A "$UA" \
    -H 'Content-Type: application/json' -H "X-CSRF-Token: $token" \
    -d "{\"password\":\"$pw\"}" "$BASE/api/me/reauth"
}

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

code_of() { printf '%s' "$1" | tail -1; }
body_of() { printf '%s' "$1" | sed '$d'; }
new_id()  { printf '%s' "$1" | grep -o '"id":[0-9]*' | head -1 | cut -d: -f2; }
field()   { printf '%s' "$2" | sed -n 's/.*"'"$1"'":"\([^"]*\)".*/\1/p' | head -1; }

# JSON 을 파이썬으로 읽는다 — 배열 안을 봐야 하는 단언이 많다(sed 로는 갈라 읽을 수 없다).
jq_py() { # $1 본문  $2 파이썬 식 (d = 파싱된 값)
  printf '%s' "$1" | python3 -c "
import json, sys
try:
    d = json.load(sys.stdin)
except Exception:
    print('PARSE_ERROR'); sys.exit(0)
print($2)
" 2>/dev/null
}

# ─────────────────────────────────────────────────────────────
# 인구조사 — 시작·끝에 비-qa 행 수를 찍는다 (R-73 ③)
# ─────────────────────────────────────────────────────────────
QA_IDS=""
CENSUS_BEFORE=""
CENSUS_SUM=0

census() {
  local when="$1"
  printf '  %-18s %8s %10s\n' "표" "전체" "비-qa"
  local total nonqa sum=0
  for t in p_brands p_branches p_locations p_vendors p_categories p_items p_docs; do
    total=$(psqlq "SELECT count(*) FROM $t;")
    nonqa=$(psqlq "SELECT count(*) FROM $t WHERE created_by NOT IN ($QA_IDS);")
    printf '  %-18s %8s %10s\n' "$t" "$total" "$nonqa"
    sum=$((sum + nonqa))
  done
  total=$(psqlq "SELECT count(*) FROM p_doc_lines;")
  nonqa=$(psqlq "SELECT count(*) FROM p_doc_lines l JOIN p_docs d ON d.id = l.doc_id
                  WHERE d.created_by NOT IN ($QA_IDS);")
  printf '  %-18s %8s %10s\n' "p_doc_lines" "$total" "$nonqa"
  sum=$((sum + nonqa))
  printf '  %-18s %8s %10s   ← 사용자(비-qa)가 만든 행 합계 (%s)\n' "합계" "-" "$sum" "$when"
  CENSUS_SUM="$sum"
}

# 시험이 만든 전표를 **창구로** 취소한다 (지시서: 만든 요청·발주서는 정리에서 취소)
void_made() {
  local id c
  [ -n "${TOK_MGR:-}" ] || return 0
  for id in $(psqlq "SELECT id FROM p_docs
                      WHERE created_by IN ($QA_IDS) AND doc_type = 'po' AND status <> 'void'
                      ORDER BY id DESC;"); do
    c=$(code_of "$(call qa_manager01 POST "/api/purchase/po/$id/void" "$TOK_MGR" '{"reason":"F-60 검토 시험 정리"}')")
    printf '  발주서 %s 취소 → HTTP %s\n' "$id" "$c"
  done
  [ -n "${TOK_EMP1:-}" ] || return 0
  for id in $(psqlq "SELECT id FROM p_docs
                      WHERE created_by IN ($QA_IDS) AND doc_type = 'pr' AND status <> 'void'
                      ORDER BY id DESC;"); do
    c=$(code_of "$(call qa_employee01 POST "/api/purchase/pr/$id/void" "$TOK_EMP1" '{"reason":"F-60 검토 시험 정리"}')")
    printf '  요청 %s 취소 → HTTP %s\n' "$id" "$c"
  done
}

cleanup() {
  hr "정리 — **시험이 만든 것만** 되돌린다 (R-73 ② · R-78)"
  local qa_ids
  qa_ids=$(psqlq "SELECT string_agg(id::text, ',') FROM users WHERE username LIKE 'qa\\_%';")
  if [ -z "$qa_ids" ]; then
    echo "  ! 검토 계정을 찾지 못해 정리를 건너뜁니다 (아무것도 지우지 않았습니다)"
    rm -rf "$JAR_DIR"
    return
  fi
  echo "  검토 계정 id: $qa_ids — 이 계정이 만든 행만 지웁니다 (표 전체 DELETE 없음)"

  sub "① 창구로 취소 (void)"
  void_made

  psqlq "SELECT '  사용자(비-qa)가 만든 행: p_docs='||
           (SELECT count(*) FROM p_docs WHERE created_by NOT IN ($qa_ids))||
         ' p_items='||(SELECT count(*) FROM p_items WHERE created_by NOT IN ($qa_ids))||
         ' p_vendors='||(SELECT count(*) FROM p_vendors WHERE created_by NOT IN ($qa_ids))||
         ' — 이 행들은 그대로 둡니다';"

  psqlc "UPDATE users SET branch_id = NULL, purchase_role = NULL
          WHERE username IN ('qa_employee01','qa_employee02','qa_manager01');" \
    && echo "  qa 계정 branch_id·purchase_role 원복"

  psqlc "DELETE FROM p_doc_lines WHERE doc_id IN (SELECT id FROM p_docs WHERE created_by IN ($qa_ids));
         DELETE FROM p_docs      WHERE created_by IN ($qa_ids);
         -- R-78: 채번 카운터는 qa 의 것이 아니다 — 지우지 않고 「남은 전표의 최대 번호」로 되돌린다
         UPDATE p_doc_seq s SET last_no = COALESCE((SELECT max(split_part(d.doc_no,'-',3)::int) FROM p_docs d
                                                    WHERE d.doc_date = CURRENT_DATE AND d.doc_type = s.doc_type), 0)
          WHERE s.seq_date = CURRENT_DATE;
         DELETE FROM p_item_branches WHERE item_id IN (SELECT id FROM p_items WHERE created_by IN ($qa_ids));
         DELETE FROM p_items         WHERE created_by IN ($qa_ids);
         DELETE FROM p_categories    WHERE created_by IN ($qa_ids) AND parent_id IS NOT NULL;
         DELETE FROM p_categories    WHERE created_by IN ($qa_ids);
         DELETE FROM p_vendors       WHERE created_by IN ($qa_ids);
         UPDATE p_branches SET warehouse_location_id = NULL, central_location_id = NULL
          WHERE created_by IN ($qa_ids);
         DELETE FROM p_locations WHERE created_by IN ($qa_ids);
         DELETE FROM p_branches  WHERE created_by IN ($qa_ids);
         DELETE FROM p_brands    WHERE created_by IN ($qa_ids);" \
    && echo "  검토 계정이 만든 시험 데이터 삭제"

  sub "정리 뒤 인구조사 — 사용자 자료가 그대로인가"
  census after
  local after="$CENSUS_SUM"
  echo
  if [ "$after" = "$CENSUS_BEFORE" ]; then
    printf '  ✔ 사용자(비-qa) 행 수가 시작 때와 같습니다 (%s → %s)\n' "$CENSUS_BEFORE" "$after"
  else
    printf '  ✖ 사용자(비-qa) 행 수가 달라졌습니다 (%s → %s) — **즉시 보고해야 합니다**\n' \
      "$CENSUS_BEFORE" "$after"
    FAIL=$((FAIL+1))
  fi
  printf '\n결과: 통과 %d · 실패 %d\n' "$PASS" "$FAIL"
  rm -rf "$JAR_DIR"
}

trap cleanup EXIT

hr "0-0. 시작 전 인구조사 (R-73 ③)"
QA_IDS=$(psqlq "SELECT coalesce(string_agg(id::text, ','), '0') FROM users WHERE username LIKE 'qa\\_%';")
echo "  검토 계정 id: $QA_IDS"
census before
CENSUS_BEFORE="$CENSUS_SUM"
echo
echo "  ★ 이 시험의 정리는 위 「비-qa」 행을 **한 줄도 지우지 않습니다**."

hr "0. 준비 — 검토 계정 로그인"
TOK_ADMIN=$(login qa_admin01)   || exit 1
TOK_EMP1=$(login qa_employee01) || exit 1
TOK_MGR=$(login qa_manager01)   || exit 1
echo "  로그인 3계정 완료"
echo "  재인증(기초정보 편집 창 열기): qa_admin01 → $(reauth qa_admin01 "$TOK_ADMIN")"

hr "1. 기초정보 씨앗 — 브랜드·지점·장소·거래처 2곳·품목 3개"

R=$(call qa_admin01 POST /api/purchase/brands "$TOK_ADMIN" \
  '{"name":"F60 치과","biz_no":"123-45-67890","ceo_name":"검토 대표","address":"인천 부평구","tel":"032-000-0000"}')
BRAND=$(new_id "$(body_of "$R")"); expect "브랜드 생성" 201 "$(code_of "$R")"

R=$(call qa_admin01 POST /api/purchase/branches "$TOK_ADMIN" "{\"brand_id\":$BRAND,\"name\":\"F60 부평점\"}")
BR_A=$(new_id "$(body_of "$R")"); expect "지점 생성" 201 "$(code_of "$R")"

R=$(call qa_admin01 POST /api/purchase/locations "$TOK_ADMIN" \
  "{\"branch_id\":$BR_A,\"kind\":\"warehouse\",\"name\":\"F60 B1F 창고\"}")
LOC_A=$(new_id "$(body_of "$R")"); expect "장소 생성" 201 "$(code_of "$R")"

R=$(call qa_admin01 POST /api/purchase/vendors "$TOK_ADMIN" '{"name":"F60 거래처A","code":"F60VA"}')
VEND_A=$(new_id "$(body_of "$R")"); expect "거래처A 생성" 201 "$(code_of "$R")"
R=$(call qa_admin01 POST /api/purchase/vendors "$TOK_ADMIN" '{"name":"F60 거래처B","code":"F60VB"}')
VEND_B=$(new_id "$(body_of "$R")"); expect "거래처B 생성" 201 "$(code_of "$R")"

R=$(call qa_admin01 POST /api/purchase/items "$TOK_ADMIN" \
  "{\"vendor_id\":$VEND_A,\"code\":\"F60-A1\",\"name\":\"F60 gauze\",\"unit\":\"EA\",\"price_in\":\"1000\"}")
ITEM_A1=$(new_id "$(body_of "$R")"); expect "품목A1(거래처A·단가 1000) 생성" 201 "$(code_of "$R")"
R=$(call qa_admin01 POST /api/purchase/items "$TOK_ADMIN" \
  "{\"vendor_id\":$VEND_A,\"code\":\"F60-A2\",\"name\":\"F60 cotton\",\"unit\":\"EA\",\"price_in\":\"2000\"}")
ITEM_A2=$(new_id "$(body_of "$R")"); expect "품목A2(거래처A·단가 2000) 생성" 201 "$(code_of "$R")"
R=$(call qa_admin01 POST /api/purchase/items "$TOK_ADMIN" \
  "{\"vendor_id\":$VEND_B,\"code\":\"F60-B1\",\"name\":\"F60 needle\",\"unit\":\"EA\",\"price_in\":\"3000\"}")
ITEM_B1=$(new_id "$(body_of "$R")"); expect "품목B1(거래처B·단가 3000) 생성" 201 "$(code_of "$R")"

sub "검토용 모듈 역할·지점 심기 (psql, 끝에서 원복)"
psqlc "UPDATE users SET branch_id = $BR_A, purchase_role = 'requester' WHERE username = 'qa_employee01';"
psqlc "UPDATE users SET branch_id = NULL,  purchase_role = 'buyer'     WHERE username = 'qa_manager01';"
psqlq "SELECT username||' branch='||coalesce(branch_id::text,'-')||' role='||coalesce(purchase_role,'-')
         FROM users WHERE username LIKE 'qa%' ORDER BY id;" | sed 's/^/  /'

hr "준비 — 요청 2건 (거래처 A 품목 2줄 / 거래처 B 품목 1줄, **다른 전표**)"

R=$(call qa_employee01 POST /api/purchase/pr "$TOK_EMP1" \
  "{\"to_location_id\":$LOC_A,\"memo\":\"F60 요청1\",\"lines\":[{\"item_id\":$ITEM_A1,\"qty\":\"5\"},{\"item_id\":$ITEM_A2,\"qty\":\"3\"}]}")
PR1=$(new_id "$(body_of "$R")"); PR1_NO=$(field doc_no "$(body_of "$R")")
expect "요청1 생성(거래처 A 품목 2줄)" 201 "$(code_of "$R")"

R=$(call qa_employee01 POST /api/purchase/pr "$TOK_EMP1" \
  "{\"to_location_id\":$LOC_A,\"memo\":\"F60 요청2\",\"lines\":[{\"item_id\":$ITEM_B1,\"qty\":\"2\"}]}")
PR2=$(new_id "$(body_of "$R")"); PR2_NO=$(field doc_no "$(body_of "$R")")
expect "요청2 생성(거래처 B 품목 1줄)" 201 "$(code_of "$R")"
echo "  요청 전표: $PR1_NO(id=$PR1) · $PR2_NO(id=$PR2)"

L_A1=$(psqlq "SELECT id FROM p_doc_lines WHERE doc_id = $PR1 AND item_id = $ITEM_A1;")
L_A2=$(psqlq "SELECT id FROM p_doc_lines WHERE doc_id = $PR1 AND item_id = $ITEM_A2;")
L_B1=$(psqlq "SELECT id FROM p_doc_lines WHERE doc_id = $PR2 AND item_id = $ITEM_B1;")
echo "  요청 줄: A1=$L_A1 A2=$L_A2 B1=$L_B1"

# ═════════════════════════════════════════════════════════════
hr "T-72 (a) 불러오기 — group=doc · 노드 key=doc_id · label 에 PR 번호 · 2층"
# ═════════════════════════════════════════════════════════════

R=$(call qa_manager01 GET "/api/purchase/pr/load?group=doc&q=F60" "$TOK_MGR")
B=$(body_of "$R")
echo "  HTTP $(code_of "$R")"; echo "  $B" | head -c 900; echo
expect "buyer 의 group=doc 불러오기 200" 200 "$(code_of "$R")"
expect "묶는 기준이 doc 그대로" "['doc']" "$(jq_py "$B" "d['group']")"
expect "노드 2개(전표 둘)" 2 "$(jq_py "$B" "len(d['groups'])")"
expect "노드 group 이 doc" "doc" "$(jq_py "$B" "d['groups'][0]['group']")"
expect "노드 key = 요청1 전표 id" "$PR1" "$(jq_py "$B" "[n['key'] for n in d['groups'] if n['label'].startswith('$PR1_NO')][0]")"
expect "노드 label 에 PR 번호가 들어간다" 1 \
  "$(jq_py "$B" "int(any(n['label'].startswith('$PR1_NO ·') for n in d['groups']))")"
expect "요청1 노드 line_count = 2" 2 \
  "$(jq_py "$B" "[n['line_count'] for n in d['groups'] if n['key'] == $PR1][0]")"
expect "요청2 노드 line_count = 1" 1 \
  "$(jq_py "$B" "[n['line_count'] for n in d['groups'] if n['key'] == $PR2][0]")"
expect "요청1 노드 잔량 합계 8.000" "8.000" "$(jq_py "$B" "[n['qty'] for n in d['groups'] if n['key'] == $PR1][0]")"
expect "잎 노드에 lines 가 달린다" 1 \
  "$(jq_py "$B" "int(all('lines' in n for n in d['groups']))")"

sub "group=vendor,doc → 2층 (거래처 아래 전표)"
R=$(call qa_manager01 GET "/api/purchase/pr/load?group=vendor,doc&q=F60" "$TOK_MGR")
B=$(body_of "$R")
echo "  $B" | head -c 700; echo
expect "2층 요청 200" 200 "$(code_of "$R")"
expect "층 순서가 vendor,doc" "['vendor', 'doc']" "$(jq_py "$B" "d['group']")"
expect "1층은 vendor" "doc" "$(jq_py "$B" "d['groups'][0]['children'][0]['group']")"
expect "2층 노드가 children 으로 달린다" 1 \
  "$(jq_py "$B" "int(all('children' in n for n in d['groups']))")"

sub "모르는 group 값은 무시된다 (기존 규칙 불변)"
R=$(call qa_manager01 GET "/api/purchase/pr/load?group=doc,nosuch&q=F60" "$TOK_MGR")
B=$(body_of "$R")
expect "알 수 없는 묶는 기준은 빠진다" "['doc']" "$(jq_py "$B" "d['group']")"

# ═════════════════════════════════════════════════════════════
hr "T-72 (b) split:true · 2거래처 → 201 docs 2장 · 감사 2행 split_count=2"
# ═════════════════════════════════════════════════════════════

AUDIT_BEFORE=$(psqlq "SELECT count(*) FROM audit_logs WHERE action='purchase.order.create' AND result='allow';")

R=$(call qa_manager01 POST /api/purchase/po "$TOK_MGR" \
  "{\"to_location_id\":$LOC_A,\"split\":true,\"lines\":[{\"src_line_id\":$L_A1,\"qty\":\"5\"},{\"src_line_id\":$L_A2,\"qty\":\"3\"},{\"src_line_id\":$L_B1,\"qty\":\"2\"}]}")
B=$(body_of "$R")
echo "  HTTP $(code_of "$R")"; echo "  $B"; echo
expect "분할 저장 201" 201 "$(code_of "$R")"
expect "count = 2" 2 "$(jq_py "$B" "d['count']")"
expect "docs 2장" 2 "$(jq_py "$B" "len(d['docs'])")"
expect "장마다 거래처가 하나씩이고 서로 다르다" 2 \
  "$(jq_py "$B" "len(set(x['vendor_id'] for x in d['docs']))")"
expect "거래처A 장의 줄 수 2" 2 \
  "$(jq_py "$B" "[x['line_count'] for x in d['docs'] if x['vendor_id'] == $VEND_A][0]")"
expect "거래처B 장의 줄 수 1" 1 \
  "$(jq_py "$B" "[x['line_count'] for x in d['docs'] if x['vendor_id'] == $VEND_B][0]")"
expect "거래처 이름이 실려 온다" "F60 거래처A" \
  "$(jq_py "$B" "[x['vendor_name'] for x in d['docs'] if x['vendor_id'] == $VEND_A][0]")"
expect "전표 번호 둘 다 PO- 로 시작" 2 \
  "$(jq_py "$B" "sum(1 for x in d['docs'] if x['doc_no'].startswith('PO-$TODAY-'))")"
expect "전표 번호가 서로 다르다" 2 "$(jq_py "$B" "len(set(x['doc_no'] for x in d['docs']))")"
expect "totals 가 장마다 실려 온다" 1 \
  "$(jq_py "$B" "int(all('supply' in x['totals'] for x in d['docs']))")"
expect "거래처A 장 공급가액 = 5×1000 + 3×2000" "11000.00" \
  "$(jq_py "$B" "[x['totals']['supply'] for x in d['docs'] if x['vendor_id'] == $VEND_A][0]")"

PO_A=$(jq_py "$B" "[x['id'] for x in d['docs'] if x['vendor_id'] == $VEND_A][0]")
PO_B=$(jq_py "$B" "[x['id'] for x in d['docs'] if x['vendor_id'] == $VEND_B][0]")
echo "  만들어진 발주서: A=$PO_A B=$PO_B"

sub "DB — 줄의 src_line_id 가 요청 줄이고, 요청 잔량이 0 이 된다"
expect "거래처A 장의 줄이 2줄" 2 "$(psqlq "SELECT count(*) FROM p_doc_lines WHERE doc_id = $PO_A;")"
expect "거래처A 장의 src_line_id 가 요청 줄 둘" 2 \
  "$(psqlq "SELECT count(*) FROM p_doc_lines WHERE doc_id = $PO_A AND src_line_id IN ($L_A1,$L_A2);")"
expect "거래처B 장의 src_line_id = 요청2 줄" "$L_B1" \
  "$(psqlq "SELECT src_line_id FROM p_doc_lines WHERE doc_id = $PO_B;")"
expect "두 장의 vendor_id 가 서로 다르다" 2 \
  "$(psqlq "SELECT count(DISTINCT vendor_id) FROM p_docs WHERE id IN ($PO_A,$PO_B);")"
expect "두 장의 머리(장소·담당·일자)가 같다" 1 \
  "$(psqlq "SELECT count(*) FROM (SELECT DISTINCT to_location_id, manager_id, doc_date
                                    FROM p_docs WHERE id IN ($PO_A,$PO_B)) x;")"
for L in "$L_A1" "$L_A2" "$L_B1"; do
  REM=$(psqlq "SELECT (l.qty - coalesce((SELECT sum(c.qty) FROM p_doc_lines c JOIN p_docs cd ON cd.id=c.doc_id
                 WHERE c.src_line_id = l.id AND c.status <> 'void' AND cd.status <> 'void'),0))::text
               FROM p_doc_lines l WHERE l.id = $L;")
  expect "요청 줄 $L 잔량 0" "0.000" "$REM"
done
expect "요청1 상태 = done" "done" "$(psqlq "SELECT status FROM p_docs WHERE id = $PR1;")"
expect "요청2 상태 = done" "done" "$(psqlq "SELECT status FROM p_docs WHERE id = $PR2;")"

sub "감사 — 발주서마다 한 행 · detail split_count=2"
AUDIT_AFTER=$(psqlq "SELECT count(*) FROM audit_logs WHERE action='purchase.order.create' AND result='allow';")
expect "purchase.order.create allow 가 2행 늘었다" 2 "$((AUDIT_AFTER - AUDIT_BEFORE))"
psqlq "SELECT '  '||target_name||' | '||detail FROM audit_logs
        WHERE action='purchase.order.create' AND result='allow' ORDER BY id DESC LIMIT 2;"
expect "두 행 모두 split_count=2" 2 \
  "$(psqlq "SELECT count(*) FROM (SELECT detail FROM audit_logs
             WHERE action='purchase.order.create' AND result='allow'
             ORDER BY id DESC LIMIT 2) x WHERE x.detail::jsonb->>'split_count' = '2';")"
expect "split_count 가 [제거됨] 으로 지워지지 않았다" 0 \
  "$(psqlq "SELECT count(*) FROM (SELECT detail FROM audit_logs
             WHERE action='purchase.order.create' AND result='allow'
             ORDER BY id DESC LIMIT 2) x WHERE x.detail::text LIKE '%제거됨%';")"

# ═════════════════════════════════════════════════════════════
hr "T-72 (c) split 없음 → 400 mixed_vendor · split+vendor_id → 400 mixed_vendor"
# ═════════════════════════════════════════════════════════════

R=$(call qa_employee01 POST /api/purchase/pr "$TOK_EMP1" \
  "{\"to_location_id\":$LOC_A,\"memo\":\"F60 요청3\",\"lines\":[{\"item_id\":$ITEM_A1,\"qty\":\"4\"}]}")
PR3=$(new_id "$(body_of "$R")")
R=$(call qa_employee01 POST /api/purchase/pr "$TOK_EMP1" \
  "{\"to_location_id\":$LOC_A,\"memo\":\"F60 요청4\",\"lines\":[{\"item_id\":$ITEM_B1,\"qty\":\"4\"}]}")
PR4=$(new_id "$(body_of "$R")")
M_A=$(psqlq "SELECT id FROM p_doc_lines WHERE doc_id = $PR3;")
M_B=$(psqlq "SELECT id FROM p_doc_lines WHERE doc_id = $PR4;")
echo "  섞기 시험용 줄: A=$M_A B=$M_B"
MIX="{\"src_line_id\":$M_A,\"qty\":\"1\"},{\"src_line_id\":$M_B,\"qty\":\"1\"}"

R=$(call qa_manager01 POST /api/purchase/po "$TOK_MGR" "{\"to_location_id\":$LOC_A,\"lines\":[$MIX]}")
echo "  HTTP $(code_of "$R")  $(body_of "$R")"
expect "split 없이 섞으면 400 (계약 불변)" 400 "$(code_of "$R")"
expect "감사 사유 mixed_vendor" "mixed_vendor" \
  "$(psqlq "SELECT reason FROM audit_logs WHERE action='purchase.order.create' AND result='deny'
             ORDER BY id DESC LIMIT 1;")"

R=$(call qa_manager01 POST /api/purchase/po "$TOK_MGR" \
  "{\"to_location_id\":$LOC_A,\"split\":true,\"vendor_id\":$VEND_A,\"lines\":[$MIX]}")
echo "  HTTP $(code_of "$R")  $(body_of "$R")"
expect "split + vendor_id 머리값 → 400 (명시가 우선)" 400 "$(code_of "$R")"
expect "감사 사유 mixed_vendor" "mixed_vendor" \
  "$(psqlq "SELECT reason FROM audit_logs WHERE action='purchase.order.create' AND result='deny'
             ORDER BY id DESC LIMIT 1;")"

sub "split 값은 참·거짓만 받는다"
R=$(call qa_manager01 POST /api/purchase/po "$TOK_MGR" \
  "{\"to_location_id\":$LOC_A,\"split\":\"true\",\"lines\":[{\"src_line_id\":$M_A,\"qty\":\"1\"}]}")
echo "  HTTP $(code_of "$R")  $(body_of "$R")"
expect "split:\"true\"(문자열) → 400" 400 "$(code_of "$R")"

sub "split 없는 단일 거래처 요청의 **응답은 예전 그대로**"
R=$(call qa_manager01 POST /api/purchase/po "$TOK_MGR" \
  "{\"to_location_id\":$LOC_A,\"lines\":[{\"src_line_id\":$M_A,\"qty\":\"1\"}]}")
B=$(body_of "$R")
echo "  HTTP $(code_of "$R")"; echo "  $B" | head -c 400; echo
expect "단일 거래처·split 없음 → 201" 201 "$(code_of "$R")"
expect "응답에 doc_no 가 있다(옛 모양)" 1 "$(jq_py "$B" "int('doc_no' in d)")"
expect "응답에 docs 가 없다" 0 "$(jq_py "$B" "int('docs' in d)")"
expect "그 감사 행에는 split_count 가 없다" 0 \
  "$(psqlq "SELECT count(*) FROM (SELECT detail FROM audit_logs
             WHERE action='purchase.order.create' AND result='allow'
             ORDER BY id DESC LIMIT 1) x WHERE x.detail::jsonb ? 'split_count';")"

# ═════════════════════════════════════════════════════════════
hr "T-72 (d) 원자성 — 한 줄이 잔량 초과면 400 이고 발주서 0장"
# ═════════════════════════════════════════════════════════════

R=$(call qa_employee01 POST /api/purchase/pr "$TOK_EMP1" \
  "{\"to_location_id\":$LOC_A,\"memo\":\"F60 요청5\",\"lines\":[{\"item_id\":$ITEM_A2,\"qty\":\"2\"}]}")
PR5=$(new_id "$(body_of "$R")")
R=$(call qa_employee01 POST /api/purchase/pr "$TOK_EMP1" \
  "{\"to_location_id\":$LOC_A,\"memo\":\"F60 요청6\",\"lines\":[{\"item_id\":$ITEM_B1,\"qty\":\"2\"}]}")
PR6=$(new_id "$(body_of "$R")")
X_A=$(psqlq "SELECT id FROM p_doc_lines WHERE doc_id = $PR5;")
X_B=$(psqlq "SELECT id FROM p_doc_lines WHERE doc_id = $PR6;")

DOCS_BEFORE=$(psqlq "SELECT count(*) FROM p_docs WHERE doc_type = 'po';")
SEQ_BEFORE=$(psqlq "SELECT coalesce(last_no,0) FROM p_doc_seq WHERE doc_type='po' AND seq_date=CURRENT_DATE;")
echo "  발주서 수 $DOCS_BEFORE · 채번 카운터 ${SEQ_BEFORE:-0}"

# 거래처 A 줄은 잔량 2 인데 3 을 달라고 한다. 거래처 B 줄은 멀쩡하다.
R=$(call qa_manager01 POST /api/purchase/po "$TOK_MGR" \
  "{\"to_location_id\":$LOC_A,\"split\":true,\"lines\":[{\"src_line_id\":$X_A,\"qty\":\"3\"},{\"src_line_id\":$X_B,\"qty\":\"2\"}]}")
echo "  HTTP $(code_of "$R")  $(body_of "$R")"
expect "잔량 초과 → 400" 400 "$(code_of "$R")"
expect "감사 사유 remaining_exceeded" "remaining_exceeded" \
  "$(psqlq "SELECT reason FROM audit_logs WHERE action='purchase.order.create' AND result='deny'
             ORDER BY id DESC LIMIT 1;")"
DOCS_AFTER=$(psqlq "SELECT count(*) FROM p_docs WHERE doc_type = 'po';")
expect "발주서가 **한 장도** 늘지 않았다 (반 장 없음)" "$DOCS_BEFORE" "$DOCS_AFTER"
expect "성한 쪽(거래처B) 줄도 담기지 않았다" 0 \
  "$(psqlq "SELECT count(*) FROM p_doc_lines WHERE src_line_id = $X_B;")"
SEQ_AFTER=$(psqlq "SELECT coalesce(last_no,0) FROM p_doc_seq WHERE doc_type='po' AND seq_date=CURRENT_DATE;")
echo "  채번 카운터: ${SEQ_BEFORE:-0} → ${SEQ_AFTER:-0}  (채번 함수 특성상 소비돼도 무방 — 기록만 한다)"

# ═════════════════════════════════════════════════════════════
hr "T-72 (e) 거래처 하나 split:true → docs 1 · requester 403"
# ═════════════════════════════════════════════════════════════

R=$(call qa_manager01 POST /api/purchase/po "$TOK_MGR" \
  "{\"to_location_id\":$LOC_A,\"split\":true,\"lines\":[{\"src_line_id\":$X_A,\"qty\":\"2\"}]}")
B=$(body_of "$R")
echo "  HTTP $(code_of "$R")"; echo "  $B" | head -c 500; echo
expect "거래처 하나 + split:true → 201" 201 "$(code_of "$R")"
expect "1장이어도 docs 배열이다" 1 "$(jq_py "$B" "int('docs' in d)")"
expect "count = 1" 1 "$(jq_py "$B" "d['count']")"
expect "docs 길이 1" 1 "$(jq_py "$B" "len(d['docs'])")"
expect "그 한 장의 거래처 = A" "$VEND_A" "$(jq_py "$B" "d['docs'][0]['vendor_id']")"
expect "감사 detail split_count=1" "1" \
  "$(psqlq "SELECT detail::jsonb->>'split_count' FROM audit_logs
             WHERE action='purchase.order.create' AND result='allow' ORDER BY id DESC LIMIT 1;")"

R=$(call qa_employee01 POST /api/purchase/po "$TOK_EMP1" \
  "{\"to_location_id\":$LOC_A,\"split\":true,\"lines\":[{\"src_line_id\":$X_B,\"qty\":\"1\"}]}")
echo "  HTTP $(code_of "$R")  $(body_of "$R")"
expect "requester 가 분할 저장을 부르면 403" 403 "$(code_of "$R")"
R=$(call qa_employee01 GET "/api/purchase/pr/load?group=doc" "$TOK_EMP1")
expect "requester 의 불러오기도 403 (기존 그대로)" 403 "$(code_of "$R")"

# ═════════════════════════════════════════════════════════════
hr "T-72 (f) 화면 — 전표 칩 · 노드 체크박스 · 중간 상태 · 미리보기 · 알림 (Playwright)"
# ═════════════════════════════════════════════════════════════

sub "화면 시험용 씨앗 — 검색어 F60UI 로 **두 전표만** 보이게 한다"
sub "(요청 하나에 거래처A 두 줄 → 노드 중간 상태를 만들 수 있다)"
R=$(call qa_admin01 POST /api/purchase/items "$TOK_ADMIN" \
  "{\"vendor_id\":$VEND_A,\"code\":\"F60UI-A1\",\"name\":\"F60UI swab\",\"unit\":\"EA\",\"price_in\":\"1500\"}")
UI_A1=$(new_id "$(body_of "$R")"); expect "화면용 품목A1 생성" 201 "$(code_of "$R")"
R=$(call qa_admin01 POST /api/purchase/items "$TOK_ADMIN" \
  "{\"vendor_id\":$VEND_A,\"code\":\"F60UI-A2\",\"name\":\"F60UI glove\",\"unit\":\"EA\",\"price_in\":\"2500\"}")
UI_A2=$(new_id "$(body_of "$R")"); expect "화면용 품목A2 생성" 201 "$(code_of "$R")"
R=$(call qa_admin01 POST /api/purchase/items "$TOK_ADMIN" \
  "{\"vendor_id\":$VEND_B,\"code\":\"F60UI-B1\",\"name\":\"F60UI syringe\",\"unit\":\"EA\",\"price_in\":\"3500\"}")
UI_B1=$(new_id "$(body_of "$R")"); expect "화면용 품목B1 생성" 201 "$(code_of "$R")"

R=$(call qa_employee01 POST /api/purchase/pr "$TOK_EMP1" \
  "{\"to_location_id\":$LOC_A,\"memo\":\"F60UI 요청1\",\"lines\":[{\"item_id\":$UI_A1,\"qty\":\"4\"},{\"item_id\":$UI_A2,\"qty\":\"6\"}]}")
expect "화면용 요청1(거래처A 두 줄) 생성" 201 "$(code_of "$R")"
R=$(call qa_employee01 POST /api/purchase/pr "$TOK_EMP1" \
  "{\"to_location_id\":$LOC_A,\"memo\":\"F60UI 요청2\",\"lines\":[{\"item_id\":$UI_B1,\"qty\":\"7\"}]}")
expect "화면용 요청2(거래처B 한 줄) 생성" 201 "$(code_of "$R")"

export PATH="$HOME/.local/bin:$PATH"

cat > "$OUT/f-login.py" <<'PY'
import sys
base, ua, who, pw, code, state = sys.argv[1:7]
try:
    from playwright.sync_api import sync_playwright
except Exception as e:
    print('PLAYWRIGHT_MISSING', e); sys.exit(0)
with sync_playwright() as p:
    b = p.chromium.launch(channel='chromium')
    ctx = b.new_context(viewport={'width': 1440, 'height': 900}, user_agent=ua)
    page = ctx.new_page()
    page.goto(base + '/login', wait_until='networkidle')
    page.fill('#username', who)
    page.fill('#password', pw)
    page.click('#login-submit')
    page.wait_for_timeout(1200)
    if page.is_visible('#otp'):
        page.fill('#otp', code)
        page.click('#login-submit')
        page.wait_for_timeout(1500)
    ctx.storage_state(path=state)
    print('URL=%s' % page.url)
    b.close()
PY

cat > "$OUT/f-screen.py" <<'PY'
import json, sys
base, ua, state, out, loc_id = sys.argv[1:6]
try:
    from playwright.sync_api import sync_playwright
except Exception as e:
    print('PLAYWRIGHT_MISSING', e); sys.exit(0)

report = {}
with sync_playwright() as p:
    b = p.chromium.launch(channel='chromium')
    ctx = b.new_context(viewport={'width': 1440, 'height': 900}, user_agent=ua, storage_state=state)
    page = ctx.new_page()
    errs = []
    page.on('console', lambda m: errs.append(m.text) if m.type == 'error' else None)
    page.on('pageerror', lambda e: errs.append('pageerror: ' + str(e)))

    page.goto(base + '/purchase/po', wait_until='networkidle')
    page.wait_for_timeout(900)

    # 불러오기 모달 → 「전표」 칩만 켜고 다시 불러오기
    # (칩 막대는 <dialog> 안에 있어 **모달을 연 뒤에야** 보인다)
    page.click('#po-load')
    page.wait_for_timeout(900)
    report['chip_doc'] = page.is_visible('#load-groups .chip[data-group="doc"]')
    page.click('#load-groups .chip[data-group="vendor"]')   # 기본으로 켜진 거래처를 끈다
    page.click('#load-groups .chip[data-group="doc"]')
    page.fill('#load-q', 'F60UI')
    page.click('#load-run')
    page.wait_for_timeout(1200)

    checks = page.locator('#load-tree .p-tree-check')
    heads  = page.locator('#load-tree .p-tree-head')
    report['node_checks'] = checks.count()

    # ① 첫 묶음 노드를 체크 → 그 아래 줄이 전부 담긴다
    heads.nth(0).click()                                   # 오른쪽 줄 표를 연다
    page.wait_for_timeout(500)
    checks.nth(0).check()
    page.wait_for_timeout(600)
    report['after_node_check'] = page.eval_on_selector_all(
        '#load-lines-body input[type=checkbox]', 'els => els.filter(e => e.checked).length')
    report['line_rows'] = page.eval_on_selector_all('#load-lines-body tr', 'els => els.length')
    report['qty_is_remaining'] = page.evaluate("""() => {
      const rows = Array.from(document.querySelectorAll('#load-lines-body tr'));
      return rows.every(r => {
        const tds = r.querySelectorAll('td');
        const remain = tds[4].textContent.trim();
        const input = r.querySelector('input[type=number]');
        return input && input.value === remain;
      });
    }""")
    report['info'] = page.text_content('#load-info')
    page.screenshot(path='%s/f60-tree.png' % out)

    # ② 잎 줄 하나를 풀면 묶음 노드가 중간 상태
    page.evaluate("""() => {
      const box = document.querySelector('#load-lines-body input[type=checkbox]');
      box.checked = false;
      box.dispatchEvent(new Event('change', {bubbles: true}));
    }""")
    page.wait_for_timeout(400)
    report['indeterminate'] = page.evaluate(
        "() => document.querySelector('#load-tree .p-tree-check').indeterminate")
    report['after_one_off'] = page.eval_on_selector_all(
        '#load-lines-body input[type=checkbox]', 'els => els.filter(e => e.checked).length')

    # ③ 다시 전부 담고, 두 번째 묶음도 담아 거래처 둘을 만든다
    checks.nth(0).check()
    page.wait_for_timeout(400)
    if checks.count() > 1:
        checks.nth(1).check()
        page.wait_for_timeout(400)
    report['picked_info'] = page.text_content('#load-info')

    page.click('#load-add')
    page.wait_for_timeout(700)
    report['preview'] = page.text_content('#po-split-preview')
    report['vendor_disabled'] = page.eval_on_selector('#po-vendor', 'e => e.disabled')
    report['vendor_value'] = page.eval_on_selector('#po-vendor', 'e => e.value')
    report['vendor_help'] = page.evaluate(
        "() => { const h = document.getElementById('po-vendor-help'); return h.hidden ? '' : h.textContent; }")
    report['vendor_cells'] = page.evaluate("""() => {
      return Array.from(document.querySelectorAll('#po-line-body tr'))
                  .map(r => r.querySelectorAll('td')[3].textContent.trim());
    }""")
    page.screenshot(path='%s/f60-picked.png' % out, full_page=True)

    # ④ 저장 → 알림에 전표 번호 N 개
    page.select_option('#po-location', loc_id)
    page.click('#po-save')
    page.wait_for_timeout(2500)
    report['msg'] = page.text_content('#msg')
    page.screenshot(path='%s/f60-saved.png' % out, full_page=True)

    # ⑤ 전표 탭
    page.click('#po-tabs .tab[data-tab="docs"]')
    page.wait_for_timeout(1200)
    report['doc_rows'] = page.eval_on_selector_all('#po-doc-body tr', 'els => els.length')

    report['console_errors'] = errs
    b.close()
print(json.dumps(report, ensure_ascii=False, indent=2))
PY

SCREEN_OK=0
PW_PW=$(qa_password qa_manager01)
PW_SEC=$(qa_secret qa_manager01)
mkdir -p "$OUT/shots"
for TRY in 1 2 3; do
  PW_CODE=$(next_code "$PW_SEC")
  python3 "$OUT/f-login.py" "$BASE" "$UA" qa_manager01 "$PW_PW" "$PW_CODE" "$OUT/f-state.json" \
    > "$OUT/f-login.txt" 2>&1
  sed 's/^/    /' "$OUT/f-login.txt"
  grep -q PLAYWRIGHT_MISSING "$OUT/f-login.txt" && break
  grep -q 'URL=.*/login' "$OUT/f-login.txt" || break
  echo "  · 브라우저가 아직 /login 에 있다 — 다음 코드 창에서 다시 시도 ($TRY/3)"
done
if grep -q PLAYWRIGHT_MISSING "$OUT/f-login.txt"; then
  echo "  ! playwright 가 없어 (f) 를 실행하지 못했다 — **SKIP (미검증)**"
elif grep -q 'URL=.*/login' "$OUT/f-login.txt"; then
  echo "  ✖ 브라우저 로그인 실패 — (f) 를 건너뛴다"
  FAIL=$((FAIL+1))
else
  python3 "$OUT/f-screen.py" "$BASE" "$UA" "$OUT/f-state.json" "$OUT/shots" "$LOC_A" \
    > "$OUT/f-screen.json" 2>&1
  sed 's/^/    /' "$OUT/f-screen.json"
  if grep -q PLAYWRIGHT_MISSING "$OUT/f-screen.json"; then
    echo "  ! playwright 가 없어 (f) 를 실행하지 못했다 — **SKIP (미검증)**"
  else
    SCREEN_OK=1
    g() { python3 -c "
import json
d = json.load(open('$OUT/f-screen.json'))
v = d.get('$1')
print(int(v) if isinstance(v, bool) else v)"; }
    expect "「전표」 칩이 있다" 1 "$(g chip_doc)"
    expect "트리 묶음 노드마다 체크박스가 있다(전표 둘 이상)" 1 \
      "$([ "$(g node_checks)" -ge 2 ] && echo 1 || echo 0)"
    expect "노드 체크 → 아래 줄이 전부 체크됨" "$(g line_rows)" "$(g after_node_check)"
    expect "담기는 수량이 **잔량 그대로**다" 1 "$(g qty_is_remaining)"
    expect "잎 하나를 풀면 노드가 중간 상태" 1 "$(g indeterminate)"
    expect "담은 줄 표에 거래처 이름이 채워진다" 0 \
      "$(python3 -c "
import json
d = json.load(open('$OUT/f-screen.json'))
print(sum(1 for c in d['vendor_cells'] if c in ('', '—')))")"
    expect "미리보기 문구에 「거래처별 2장」" 1 \
      "$(python3 -c "
import json
d = json.load(open('$OUT/f-screen.json'))
print(int('거래처별 2장' in (d['preview'] or '')))")"
    expect "거래처가 섞이면 머리 거래처 칸이 잠긴다" 1 "$(g vendor_disabled)"
    expect "머리 거래처 칸이 비어 있다" "" "$(g vendor_value)"
    expect "도움 문구 「거래처별로 나눠 저장됩니다」" "거래처별로 나눠 저장됩니다" "$(g vendor_help)"
    expect "성공 알림이 「발주서 2장을 만들었습니다」" 1 \
      "$(python3 -c "
import json
d = json.load(open('$OUT/f-screen.json'))
print(int('발주서 2장을 만들었습니다' in (d['msg'] or '')))")"
    expect "알림에 전표 번호가 2개" 2 \
      "$(python3 -c "
import json, re
d = json.load(open('$OUT/f-screen.json'))
print(len(re.findall(r'PO-[0-9]{8}-[0-9]+', d['msg'] or '')))")"
    expect "전표 탭에 행이 보인다" 1 \
      "$([ "$(g doc_rows)" -ge 2 ] && echo 1 || echo 0)"
    expect "콘솔 오류 0건" 0 \
      "$(python3 -c "
import json
d = json.load(open('$OUT/f-screen.json'))
print(len(d['console_errors']))")"
  fi
fi
[ "$SCREEN_OK" = "1" ] || echo "  (f) 결과: 실행하지 못함 — 보고서에 SKIP 으로 적을 것"

# ═════════════════════════════════════════════════════════════
hr "T-72 (g) 정적 점검 — 린트 · php -l · 화면 금지 패턴"
# ═════════════════════════════════════════════════════════════

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
for f in "$ROOT/src/handlers/purchase_po_create.php" "$ROOT/src/handlers/purchase_pr_load.php" \
         "$ROOT/src/Audit.php" "$ROOT/src/purchase/Doc.php" "$ROOT/src/views/purchase_po.php"; do
  NAME=$(basename "$f")
  php -l "$f" > /dev/null 2>&1
  RC=$?
  expect "php -l $NAME" 0 "$RC"
done

# 린트(scripts/security_lint.sh)와 **같은 그물**을 쓴다 — 주석의 설명 문장은 호출이 아니다.
JSBAD=$(grep -cE 'innerHTML|outerHTML|document\.write\(' "$ROOT/public/assets/purchase_docs.js"); JSBAD=${JSBAD:-0}
expect "purchase_docs.js 에 innerHTML·document.write 0건" 0 "$JSBAD"
expect "발주서 만들기 핸들러가 purchaseScopeWhere 를 부른다" 1 \
  "$([ "$(grep -c 'purchaseScopeWhere(' "$ROOT/src/handlers/purchase_po_create.php")" -ge 1 ] && echo 1 || echo 0)"
expect "불러오기 핸들러가 purchaseScopeWhere 를 부른다" 1 \
  "$([ "$(grep -c 'purchaseScopeWhere(' "$ROOT/src/handlers/purchase_pr_load.php")" -ge 1 ] && echo 1 || echo 0)"

sub "린트"
if bash "$ROOT/scripts/security_lint.sh" > "$OUT/lint.txt" 2>&1; then
  expect "security_lint.sh 0건" 0 0
else
  expect "security_lint.sh 0건" 0 1
fi
tail -5 "$OUT/lint.txt" | sed 's/^/  /'

sub "전표 번호 중복 0건"
expect "중복 전표 번호 0건" 0 \
  "$(psqlq "SELECT count(*) FROM (SELECT doc_no FROM p_docs GROUP BY doc_no HAVING count(*) > 1) x;")"
psqlq "SELECT '  '||doc_type||' '||doc_no||' vendor='||coalesce(vendor_id::text,'-')
         FROM p_docs WHERE created_by IN ($QA_IDS) ORDER BY id;"
