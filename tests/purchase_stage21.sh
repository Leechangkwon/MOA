#!/usr/bin/env bash
# tests/purchase_stage21.sh — 21단계 수락 기준 실행
#   (PRD 12.2 T-46 + F-33 · F-34 · Q-18③ · SECURITY R-65 · R-66 · R-73)
#
# 규칙 (CLAUDE.md 3장)
#   · **검토 계정(qa_ 접두)만** 쓴다. 시드 계정(admin01 …)은 사용자의 자기 공격 전용이다.
#   · 모든 요청에 `-A "tbox-review/<날짜>"` 를 붙인다 — 감사 로그에서 사람의 행동과 갈라 보려는 것.
#   · 비밀번호·TOTP 비밀은 **필요한 필드만 awk 로** 뽑는다. 파일을 통째로 출력(cat)하지 않고,
#     값은 변수에만 담아 화면·로그 어디에도 찍지 않는다.
#
# ★ 시험 안전 (SECURITY R-73 — 2026-09-13 사고 뒤 의무). 20단계 스크립트와 **같은 규칙**이다.
#   ① **사용자가 활동 중이면 시작하지 않는다.** 최근 10분 감사 로그에 비-qa actor 가 하나라도
#      있으면 아무것도 하지 않고 **종료 코드 3** 으로 끝낸다.
#   ② **정리는 qa 계정이 만든 행만.** `created_by` 가 검토 계정인 행만 지운다.
#      표 전체 DELETE·TRUNCATE 는 이 파일에 한 줄도 없다.
#   ③ 시작과 끝에 **비-qa 행 수**를 찍고, 달라졌으면 실패로 센다.
#   ④ 픽스처 이름은 전부 `S21-` 접두다 — 사용자의 실데이터와 이름이 겹쳐 409 가 나지 않게.
#
# ★ 원장(p_stock_moves)의 정리: 앱 계정에는 DELETE 권한이 없지만 **정리는 postgres 로** 한다.
#   그래도 지우는 범위는 「qa 계정이 만든 전표 줄에 달린 행」뿐이다(표 전체 DELETE 없음).
#
# 사용: sudo -v && bash tests/purchase_stage21.sh
set -u

BASE="http://127.0.0.1:8082"
UA="tbox-review/$(date +%F)"
DB="tbox_re"
JAR_DIR="$(mktemp -d)"
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

# 「둘 중 하나면 통과」 — 막는 방식이 400(업무 규칙)일 수도 403(자격)일 수도 있는 자리에 쓴다.
expect_one_of() {
  if [ "$3" = "$1" ] || [ "$3" = "$2" ]; then
    printf '  ✔ %s  (기대=%s 또는 %s 실제=%s)\n' "$4" "$1" "$2" "$3"; PASS=$((PASS+1))
  else
    printf '  ✖ %s  (기대=%s 또는 %s 실제=%s)\n' "$4" "$1" "$2" "$3"; FAIL=$((FAIL+1))
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
login() {
  local who="$1" jar="$JAR_DIR/$1.jar" pw sec raw code left out try
  pw=$(sudo -n awk -v u="$who" '$1==u{print $3}' /etc/tbox-v2/seed-accounts.txt)
  sec=$(sudo -n awk -v u="$who" '$1==u{print $2}' /etc/tbox-v2/qa-totp.txt)
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

reauth() {
  local who="$1" token="$2" pw
  pw=$(sudo -n awk -v u="$who" '$1==u{print $3}' /etc/tbox-v2/seed-accounts.txt)
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
# 응답의 **첫** `"id":` 를 쓴다 (20단계와 같은 이유 — 줄 id 를 전표 id 로 잘못 읽지 않게).
new_id()  { printf '%s' "$1" | grep -o '"id":[0-9]*' | head -1 | cut -d: -f2; }
field()   { printf '%s' "$2" | sed -n 's/.*"'"$1"'":"\([^"]*\)".*/\1/p' | head -1; }

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
  for t in p_brands p_branches p_locations p_vendors p_categories p_items p_par_levels p_docs; do
    total=$(psqlq "SELECT count(*) FROM $t;")
    nonqa=$(psqlq "SELECT count(*) FROM $t WHERE created_by NOT IN ($QA_IDS);")
    printf '  %-18s %8s %10s\n' "$t" "$total" "$nonqa"
    sum=$((sum + nonqa))
  done
  # p_doc_lines 는 created_by 가 없다 → 전표를 따라간다. p_lists 는 owner_id 다.
  total=$(psqlq "SELECT count(*) FROM p_doc_lines;")
  nonqa=$(psqlq "SELECT count(*) FROM p_doc_lines l JOIN p_docs d ON d.id = l.doc_id
                  WHERE d.created_by NOT IN ($QA_IDS);")
  printf '  %-18s %8s %10s\n' "p_doc_lines" "$total" "$nonqa"
  sum=$((sum + nonqa))
  total=$(psqlq "SELECT count(*) FROM p_lists;")
  nonqa=$(psqlq "SELECT count(*) FROM p_lists WHERE owner_id NOT IN ($QA_IDS);")
  printf '  %-18s %8s %10s\n' "p_lists" "$total" "$nonqa"
  sum=$((sum + nonqa))
  # 21단계 — 재고 원장. created_by 가 있으므로 같은 방식이다.
  total=$(psqlq "SELECT count(*) FROM p_stock_moves;")
  nonqa=$(psqlq "SELECT count(*) FROM p_stock_moves WHERE created_by NOT IN ($QA_IDS);")
  printf '  %-18s %8s %10s\n' "p_stock_moves" "$total" "$nonqa"
  sum=$((sum + nonqa))
  printf '  %-18s %8s %10s   ← 사용자(비-qa)가 만든 행 합계 (%s)\n' "합계" "-" "$sum" "$when"
  CENSUS_SUM="$sum"
}

cleanup() {
  hr "정리 — **시험이 만든 것만** 되돌린다 (R-73 ②)"
  local qa_ids
  qa_ids=$(psqlq "SELECT string_agg(id::text, ',') FROM users WHERE username LIKE 'qa\\_%';")
  if [ -z "$qa_ids" ]; then
    echo "  ! 검토 계정을 찾지 못해 정리를 건너뜁니다 (아무것도 지우지 않았습니다)"
    rm -rf "$JAR_DIR"
    return
  fi
  echo "  검토 계정 id: $qa_ids — 이 계정이 만든 행만 지웁니다 (표 전체 DELETE 없음)"

  psqlq "SELECT '  사용자(비-qa)가 만든 행: p_docs='||
           (SELECT count(*) FROM p_docs WHERE created_by NOT IN ($qa_ids))||
         ' p_stock_moves='||(SELECT count(*) FROM p_stock_moves WHERE created_by NOT IN ($qa_ids))||
         ' p_items='||(SELECT count(*) FROM p_items WHERE created_by NOT IN ($qa_ids))||
         ' p_vendors='||(SELECT count(*) FROM p_vendors WHERE created_by NOT IN ($qa_ids))||
         ' — 이 행들은 그대로 둡니다';"

  # 계정 두 축 원복 (계정 행 자체는 건드리지 않는다)
  psqlc "UPDATE users SET branch_id = NULL, purchase_role = NULL
          WHERE username IN ('qa_employee01','qa_employee02','qa_manager01');" \
    && echo "  qa 계정 branch_id·purchase_role 원복"

  # 참조가 있는 것부터. 전부 qa 소유로 좁혀져 있다.
  # ★ 원장은 reverses_move_id 자기 참조가 있으므로 **되돌림 행을 먼저** 지운다.
  psqlc "DELETE FROM p_stock_moves
          WHERE reverses_move_id IS NOT NULL AND created_by IN ($qa_ids);
         DELETE FROM p_stock_moves WHERE created_by IN ($qa_ids);
         DELETE FROM p_doc_lines WHERE doc_id IN (SELECT id FROM p_docs WHERE created_by IN ($qa_ids));
         DELETE FROM p_docs      WHERE created_by IN ($qa_ids);
         -- 2026-09-15 사고 뒤: 채번 카운터는 qa 의 것이 아니다 — 지우지 않고 「남은 전표의 최대 번호」로 되돌린다
         -- (지우면 사용자가 같은 날 만든 PR/PO/GR 과 번호가 겹쳐 그날 내내 409 가 난다)
         UPDATE p_doc_seq s SET last_no = COALESCE((SELECT max(split_part(d.doc_no,'-',3)::int) FROM p_docs d
                                                    WHERE d.doc_date = CURRENT_DATE AND d.doc_type = s.doc_type), 0)
          WHERE s.seq_date = CURRENT_DATE;
         DELETE FROM p_list_items WHERE list_id IN (SELECT id FROM p_lists WHERE owner_id IN ($qa_ids));
         DELETE FROM p_lists     WHERE owner_id IN ($qa_ids);
         DELETE FROM p_par_levels    WHERE created_by IN ($qa_ids);
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
TOK_EMP2=$(login qa_employee02) || exit 1
TOK_MGR=$(login qa_manager01)   || exit 1
echo "  로그인 4계정 완료"
echo "  재인증(기초정보 편집 창 열기): qa_admin01 → $(reauth qa_admin01 "$TOK_ADMIN")"

hr "1. 기초정보 씨앗 — 브랜드·지점 2곳·장소 4곳·거래처·품목 (전부 S21- 접두)"

R=$(call qa_admin01 POST /api/purchase/brands "$TOK_ADMIN" \
  '{"name":"S21-치과","biz_no":"321-45-67890","ceo_name":"S21 대표","address":"인천 부평구","tel":"032-111-1111"}')
BRAND=$(new_id "$(body_of "$R")"); expect "브랜드 생성" 201 "$(code_of "$R")"

R=$(call qa_admin01 POST /api/purchase/branches "$TOK_ADMIN" "{\"brand_id\":$BRAND,\"name\":\"S21-부평점\"}")
BR_A=$(new_id "$(body_of "$R")"); expect "지점A 생성" 201 "$(code_of "$R")"
R=$(call qa_admin01 POST /api/purchase/branches "$TOK_ADMIN" "{\"brand_id\":$BRAND,\"name\":\"S21-일산점\"}")
BR_B=$(new_id "$(body_of "$R")"); expect "지점B 생성" 201 "$(code_of "$R")"

R=$(call qa_admin01 POST /api/purchase/locations "$TOK_ADMIN" \
  "{\"branch_id\":$BR_A,\"kind\":\"warehouse\",\"name\":\"S21-B1F 창고\"}")
LOC_WH=$(new_id "$(body_of "$R")"); expect "지점A 창고 생성" 201 "$(code_of "$R")"
R=$(call qa_admin01 POST /api/purchase/locations "$TOK_ADMIN" \
  "{\"branch_id\":$BR_A,\"kind\":\"central\",\"name\":\"S21-4층 중앙장\"}")
LOC_CT=$(new_id "$(body_of "$R")"); expect "지점A 중앙장 생성" 201 "$(code_of "$R")"
R=$(call qa_admin01 POST /api/purchase/locations "$TOK_ADMIN" \
  "{\"branch_id\":$BR_A,\"kind\":\"or\",\"name\":\"S21-수술방\"}")
LOC_OR=$(new_id "$(body_of "$R")"); expect "지점A 수술방 생성" 201 "$(code_of "$R")"
R=$(call qa_admin01 POST /api/purchase/locations "$TOK_ADMIN" \
  "{\"branch_id\":$BR_B,\"kind\":\"warehouse\",\"name\":\"S21-일산 창고\"}")
LOC_B=$(new_id "$(body_of "$R")"); expect "지점B 창고 생성" 201 "$(code_of "$R")"

R=$(call qa_admin01 POST /api/purchase/vendors "$TOK_ADMIN" '{"name":"S21-더존덴탈","code":"S21V1"}')
VEND=$(new_id "$(body_of "$R")"); expect "거래처 생성" 201 "$(code_of "$R")"

R=$(call qa_admin01 POST /api/purchase/items "$TOK_ADMIN" \
  "{\"vendor_id\":$VEND,\"code\":\"S21-A\",\"name\":\"S21 gauze 2x2\",\"unit\":\"EA\",\"price_in\":\"1000\",\"lead_days\":3}")
ITEM_A=$(new_id "$(body_of "$R")"); expect "품목A(재고관리 O) 생성" 201 "$(code_of "$R")"
R=$(call qa_admin01 POST /api/purchase/items "$TOK_ADMIN" \
  "{\"vendor_id\":$VEND,\"code\":\"S21-B\",\"name\":\"S21 기공료\",\"unit\":\"EA\",\"price_in\":\"5000\",\"stock_managed\":false}")
ITEM_B=$(new_id "$(body_of "$R")"); expect "품목B(재고관리 X) 생성" 201 "$(code_of "$R")"
MANAGED=$(psqlq "SELECT stock_managed::text FROM p_items WHERE id = $ITEM_B;")
expect "품목B 는 stock_managed=false" "false" "$MANAGED"

sub "중앙장 인가량 4 (자동 채우기·과부족 시험용)"
R=$(call qa_admin01 POST /api/purchase/par-levels "$TOK_ADMIN" \
  "{\"location_id\":$LOC_CT,\"item_id\":$ITEM_A,\"qty\":4}")
expect "인가량 생성" 201 "$(code_of "$R")"

sub "검토용 모듈 역할·지점 심기 (psql, 끝에서 원복)"
psqlc "UPDATE users SET branch_id = $BR_A, purchase_role = 'stock'     WHERE username = 'qa_employee01';"
psqlc "UPDATE users SET branch_id = $BR_B, purchase_role = 'requester' WHERE username = 'qa_employee02';"
psqlc "UPDATE users SET branch_id = NULL,  purchase_role = 'buyer'     WHERE username = 'qa_manager01';"
psqlq "SELECT username||' branch='||coalesce(branch_id::text,'-')||' role='||coalesce(purchase_role,'-')
         FROM users WHERE username LIKE 'qa%' ORDER BY id;"

hr "2. 발주요청 → 발주서 5 → 「보냄」 (21단계 입고의 전제, 20단계 경로 재사용)"

R=$(call qa_manager01 POST /api/purchase/pr "$TOK_MGR" \
  "{\"branch_id\":$BR_A,\"to_location_id\":$LOC_WH,\"vendor_id\":$VEND,\"lines\":[{\"item_id\":$ITEM_A,\"qty\":\"5\"},{\"item_id\":$ITEM_B,\"qty\":\"2\"}]}")
PR1=$(new_id "$(body_of "$R")")
echo "  HTTP $(code_of "$R")  $(body_of "$R" | head -c 200)"
expect "발주요청 생성" 201 "$(code_of "$R")"

LINE_A=$(psqlq "SELECT id FROM p_doc_lines WHERE doc_id = $PR1 AND item_id = $ITEM_A;")
LINE_B=$(psqlq "SELECT id FROM p_doc_lines WHERE doc_id = $PR1 AND item_id = $ITEM_B;")

R=$(call qa_manager01 POST /api/purchase/po "$TOK_MGR" \
  "{\"to_location_id\":$LOC_WH,\"lines\":[{\"src_line_id\":$LINE_A,\"qty\":\"5\"},{\"src_line_id\":$LINE_B,\"qty\":\"2\"}]}")
PO1=$(new_id "$(body_of "$R")"); PO1_NO=$(field doc_no "$(body_of "$R")")
echo "  HTTP $(code_of "$R")  발주서 $PO1_NO"
expect "발주서 생성(5 + 2)" 201 "$(code_of "$R")"

PO_LINE_A=$(psqlq "SELECT id FROM p_doc_lines WHERE doc_id = $PO1 AND item_id = $ITEM_A;")
PO_LINE_B=$(psqlq "SELECT id FROM p_doc_lines WHERE doc_id = $PO1 AND item_id = $ITEM_B;")

sub "보내지 않은 발주서는 입고 불러오기에 없다"
R=$(call qa_employee01 GET "/api/purchase/gr/load?po=$PO1" "$TOK_EMP1")
echo "  HTTP $(code_of "$R")  $(body_of "$R" | head -c 200)"
expect "보내기 전 불러오기에 이 발주서 0건" 0 "$(body_of "$R" | grep -c "$PO1_NO")"

R=$(call qa_manager01 POST "/api/purchase/po/$PO1/send" "$TOK_MGR" '{}')
expect "발주서 보냄 200" 200 "$(code_of "$R")"

R=$(call qa_employee01 GET "/api/purchase/gr/load?po=$PO1" "$TOK_EMP1")
expect "보낸 뒤 불러오기에 등장" 1 "$(body_of "$R" | grep -c "$PO1_NO")"

hr "T-46 ① 입고 5 중 3 → 원장 +3 · 현재고 3 · 미입고 2"

R=$(call qa_employee01 POST /api/purchase/gr "$TOK_EMP1" \
  "{\"lines\":[{\"src_line_id\":$PO_LINE_A,\"qty\":\"3\",\"lot_no\":\"S21-L1\",\"expiry_date\":\"2028-06-30\"}]}")
GR1=$(new_id "$(body_of "$R")"); GR1_NO=$(field doc_no "$(body_of "$R")")
echo "  HTTP $(code_of "$R")"; echo "  $(body_of "$R" | head -c 400)"
expect "입고 확정 201" 201 "$(code_of "$R")"
expect "채번 GR-$TODAY-1" "GR-$TODAY-1" "$GR1_NO"

MOVES=$(psqlq "SELECT count(*) FROM p_stock_moves m JOIN p_doc_lines l ON l.id = m.doc_line_id
                WHERE l.doc_id = $GR1;")
expect "원장 행 1개(재고관리 품목)" 1 "$MOVES"
DELTA=$(psqlq "SELECT qty_delta::text FROM p_stock_moves m JOIN p_doc_lines l ON l.id = m.doc_line_id
                WHERE l.doc_id = $GR1;")
expect "qty_delta = +3" "3.000" "$DELTA"
BAL=$(psqlq "SELECT coalesce(qty,0)::text FROM p_stock_balance
              WHERE location_id = $LOC_WH AND item_id = $ITEM_A;")
expect "현재고 뷰 = 3 (SUM)" "3.000" "$BAL"
LOT=$(psqlq "SELECT lot_no FROM p_doc_lines WHERE doc_id = $GR1;")
expect "로트가 줄에 남았다" "S21-L1" "$LOT"
REMAIN=$(psqlq "SELECT remaining_qty::text FROM p_backorder WHERE po_line_id = $PO_LINE_A;")
expect "p_backorder 미입고 2" "2.000" "$REMAIN"
POSTAT=$(psqlq "SELECT status FROM p_docs WHERE id = $PO1;")
expect "발주서 상태 = 일부 입고(partial)" "partial" "$POSTAT"

sub "미입고 현황 API 에 등장한다 (F-34 R6)"
R=$(call qa_employee01 GET /api/purchase/backorder "$TOK_EMP1")
echo "  $(body_of "$R" | head -c 400)"
expect "미입고 현황 200" 200 "$(code_of "$R")"
expect "그 발주서가 미입고 현황에 있다" 1 "$(body_of "$R" | grep -c "$PO1_NO")"

sub "사이클 추적의 입고 칸이 채워진다 (F-34 R4)"
R=$(call qa_manager01 GET "/api/purchase/cycle?pr=$PR1" "$TOK_MGR")
echo "  $(body_of "$R" | head -c 500)"
expect "사이클 200" 200 "$(code_of "$R")"
expect "received_qty 3 (null 이 아니다)" 1 "$(body_of "$R" | grep -c '"received_qty":"3.000"')"
expect "not_received 2" 1 "$(body_of "$R" | grep -c '"not_received":"2.000"')"

sub "잔량 초과 입고는 400 remaining_exceeded"
R=$(call qa_employee01 POST /api/purchase/gr "$TOK_EMP1" \
  "{\"lines\":[{\"src_line_id\":$PO_LINE_A,\"qty\":\"3\"}]}")
echo "  HTTP $(code_of "$R")  $(body_of "$R")"
expect "잔량 초과 → 400" 400 "$(code_of "$R")"
REASON=$(psqlq "SELECT reason FROM audit_logs WHERE action='purchase.receipt.create' AND result='deny'
                 ORDER BY id DESC LIMIT 1;")
expect "감사 사유 remaining_exceeded" "remaining_exceeded" "$REASON"

hr "T-46 ② 재고관리 안 하는 품목 입고 → +·− 두 행, 순변화 0"

R=$(call qa_employee01 POST /api/purchase/gr "$TOK_EMP1" \
  "{\"lines\":[{\"src_line_id\":$PO_LINE_B,\"qty\":\"2\"}]}")
GR2=$(new_id "$(body_of "$R")")
echo "  HTTP $(code_of "$R")  $(body_of "$R" | head -c 300)"
expect "재고관리 X 품목 입고 201" 201 "$(code_of "$R")"
MOVES=$(psqlq "SELECT count(*) FROM p_stock_moves m JOIN p_doc_lines l ON l.id = m.doc_line_id
                WHERE l.doc_id = $GR2;")
expect "원장 행 2개(+, −)" 2 "$MOVES"
NET=$(psqlq "SELECT coalesce(sum(m.qty_delta),0)::text FROM p_stock_moves m
              JOIN p_doc_lines l ON l.id = m.doc_line_id WHERE l.doc_id = $GR2;")
expect "순변화 0" "0.000" "$NET"
BAL=$(psqlq "SELECT coalesce(sum(qty),0)::text FROM p_stock_balance
              WHERE location_id = $LOC_WH AND item_id = $ITEM_B;")
expect "현재고 0 (뷰)" "0.000" "$BAL"
USE4W=$(psqlq "SELECT count(*) FROM p_stock_moves m
                JOIN p_doc_lines l ON l.id = m.doc_line_id
                JOIN p_docs d ON d.id = l.doc_id
               WHERE d.doc_type='gr' AND m.item_id = $ITEM_B AND m.qty_delta > 0;")
expect "사용 통계가 볼 + 행이 남아 있다" 1 "$USE4W"

hr "T-46 ③ 이동 — 재고 3 인데 5 → 400 negative_stock · 2 는 통과"

R=$(call qa_employee01 POST /api/purchase/mv "$TOK_EMP1" \
  "{\"from_location_id\":$LOC_WH,\"to_location_id\":$LOC_CT,\"lines\":[{\"item_id\":$ITEM_A,\"qty\":\"5\"}]}")
echo "  HTTP $(code_of "$R")  $(body_of "$R")"
expect "재고보다 많은 이동 → 400" 400 "$(code_of "$R")"
REASON=$(psqlq "SELECT reason FROM audit_logs WHERE action='purchase.move.create' AND result='deny'
                 ORDER BY id DESC LIMIT 1;")
expect "감사 사유 negative_stock" "negative_stock" "$REASON"
BAL=$(psqlq "SELECT coalesce(qty,0)::text FROM p_stock_balance
              WHERE location_id = $LOC_WH AND item_id = $ITEM_A;")
expect "거부된 뒤에도 현재고 그대로 3" "3.000" "$BAL"

R=$(call qa_employee01 POST /api/purchase/mv "$TOK_EMP1" \
  "{\"from_location_id\":$LOC_WH,\"to_location_id\":$LOC_CT,\"lines\":[{\"item_id\":$ITEM_A,\"qty\":\"2\"}]}")
MV1=$(new_id "$(body_of "$R")"); MV1_NO=$(field doc_no "$(body_of "$R")")
echo "  HTTP $(code_of "$R")  $MV1_NO"
expect "이동 2 → 201" 201 "$(code_of "$R")"
expect "채번 MV-$TODAY-1" "MV-$TODAY-1" "$MV1_NO"
BAL=$(psqlq "SELECT coalesce(qty,0)::text FROM p_stock_balance
              WHERE location_id = $LOC_WH AND item_id = $ITEM_A;")
expect "보내는 곳 3 − 2 = 1" "1.000" "$BAL"
BAL=$(psqlq "SELECT coalesce(qty,0)::text FROM p_stock_balance
              WHERE location_id = $LOC_CT AND item_id = $ITEM_A;")
expect "받는 곳 0 + 2 = 2" "2.000" "$BAL"

sub "재고관리 안 하는 품목은 이동 대상이 아니다"
R=$(call qa_employee01 POST /api/purchase/mv "$TOK_EMP1" \
  "{\"from_location_id\":$LOC_WH,\"to_location_id\":$LOC_CT,\"lines\":[{\"item_id\":$ITEM_B,\"qty\":\"1\"}]}")
echo "  HTTP $(code_of "$R")  $(body_of "$R")"
expect "not_stock_managed → 400" 400 "$(code_of "$R")"
REASON=$(psqlq "SELECT reason FROM audit_logs WHERE action='purchase.move.create' AND result='deny'
                 ORDER BY id DESC LIMIT 1;")
expect "감사 사유 not_stock_managed" "not_stock_managed" "$REASON"

sub "「중앙장 부족분 자동 채우기」 제안 (인가량 4 − 현재고 2 = 2, 창고 재고 1 한도)"
R=$(call qa_employee01 GET "/api/purchase/mv/suggest?to=$LOC_CT&from=$LOC_WH" "$TOK_EMP1")
echo "  HTTP $(code_of "$R")  $(body_of "$R" | head -c 400)"
expect "제안 200" 200 "$(code_of "$R")"
expect "제안 수량은 보내는 곳 재고 한도(1)" 1 "$(body_of "$R" | grep -c '"suggest_qty":"1.000"')"

hr "T-46 ④ 사용 1 → −1"

R=$(call qa_employee01 POST /api/purchase/is "$TOK_EMP1" \
  "{\"from_location_id\":$LOC_CT,\"lines\":[{\"item_id\":$ITEM_A,\"qty\":\"1\"}]}")
IS1=$(new_id "$(body_of "$R")"); IS1_NO=$(field doc_no "$(body_of "$R")")
echo "  HTTP $(code_of "$R")  $IS1_NO"
expect "사용 201" 201 "$(code_of "$R")"
expect "채번 IS-$TODAY-1" "IS-$TODAY-1" "$IS1_NO"
BAL=$(psqlq "SELECT coalesce(qty,0)::text FROM p_stock_balance
              WHERE location_id = $LOC_CT AND item_id = $ITEM_A;")
expect "중앙장 2 − 1 = 1" "1.000" "$BAL"
DELTA=$(psqlq "SELECT qty_delta::text FROM p_stock_moves m JOIN p_doc_lines l ON l.id = m.doc_line_id
                WHERE l.doc_id = $IS1;")
expect "원장에 −1 한 행" "-1.000" "$DELTA"

hr "T-46 ⑤ 실사(daily) — 장부 1 vs 실제 5 → 차이 +4 한 행만"

R=$(call qa_employee01 GET "/api/purchase/adj/start?location=$LOC_CT&cycle=daily" "$TOK_EMP1")
echo "  HTTP $(code_of "$R")  $(body_of "$R" | head -c 300)"
expect "실사 시작 200" 200 "$(code_of "$R")"
expect "장부 스냅샷 1" 1 "$(body_of "$R" | grep -c '"book_qty":"1.000"')"

R=$(call qa_employee01 POST /api/purchase/adj "$TOK_EMP1" \
  "{\"location_id\":$LOC_CT,\"count_cycle\":\"daily\",\"lines\":[{\"item_id\":$ITEM_A,\"book_qty\":\"1\",\"counted_qty\":\"5\"}]}")
ADJ1=$(new_id "$(body_of "$R")"); ADJ1_NO=$(field doc_no "$(body_of "$R")")
echo "  HTTP $(code_of "$R")  $ADJ1_NO"
expect "실사 확정 201" 201 "$(code_of "$R")"
expect "채번 ADJ-$TODAY-1" "ADJ-$TODAY-1" "$ADJ1_NO"
MOVES=$(psqlq "SELECT count(*) FROM p_stock_moves m JOIN p_doc_lines l ON l.id = m.doc_line_id
                WHERE l.doc_id = $ADJ1;")
expect "원장 행은 차이 한 줄뿐" 1 "$MOVES"
DELTA=$(psqlq "SELECT qty_delta::text FROM p_stock_moves m JOIN p_doc_lines l ON l.id = m.doc_line_id
                WHERE l.doc_id = $ADJ1;")
expect "차이 +4" "4.000" "$DELTA"
CYC=$(psqlq "SELECT count_cycle FROM p_docs WHERE id = $ADJ1;")
expect "실사 주기 daily 가 전표에 남았다 (Q-18③)" "daily" "$CYC"
BAL=$(psqlq "SELECT coalesce(qty,0)::text FROM p_stock_balance
              WHERE location_id = $LOC_CT AND item_id = $ITEM_A;")
expect "실사 뒤 현재고 = 센 값 5" "5.000" "$BAL"

sub "묵은 스냅샷은 거부된다 (stale_snapshot)"
R=$(call qa_employee01 POST /api/purchase/adj "$TOK_EMP1" \
  "{\"location_id\":$LOC_CT,\"count_cycle\":\"weekly\",\"lines\":[{\"item_id\":$ITEM_A,\"book_qty\":\"1\",\"counted_qty\":\"9\"}]}")
echo "  HTTP $(code_of "$R")  $(body_of "$R")"
expect "스냅샷이 낡았으면 400" 400 "$(code_of "$R")"
REASON=$(psqlq "SELECT reason FROM audit_logs WHERE action='purchase.count.create' AND result='deny'
                 ORDER BY id DESC LIMIT 1;")
expect "감사 사유 stale_snapshot" "stale_snapshot" "$REASON"

hr "T-46 ⑥ 취소 — 반대 부호 행 + reverses_move_id · 현재고 복구"

BEFORE=$(psqlq "SELECT coalesce(qty,0)::text FROM p_stock_balance
                 WHERE location_id = $LOC_CT AND item_id = $ITEM_A;")
R=$(call qa_employee01 POST "/api/purchase/adj/$ADJ1/void" "$TOK_EMP1" '{"reason":"S21 검토 — 되돌리기 확인"}')
echo "  HTTP $(code_of "$R")  $(body_of "$R")"
expect "실사 취소 200" 200 "$(code_of "$R")"
REV=$(psqlq "SELECT count(*) FROM p_stock_moves WHERE reverses_move_id IS NOT NULL
              AND doc_line_id IN (SELECT id FROM p_doc_lines WHERE doc_id = $ADJ1);")
expect "되돌림 행 1개 (reverses_move_id 있음)" 1 "$REV"
REVDELTA=$(psqlq "SELECT qty_delta::text FROM p_stock_moves WHERE reverses_move_id IS NOT NULL
                   AND doc_line_id IN (SELECT id FROM p_doc_lines WHERE doc_id = $ADJ1);")
expect "되돌림 행은 반대 부호 −4" "-4.000" "$REVDELTA"
AFTER=$(psqlq "SELECT coalesce(qty,0)::text FROM p_stock_balance
                WHERE location_id = $LOC_CT AND item_id = $ITEM_A;")
expect "현재고가 실사 전(1)으로 복구" "1.000" "$AFTER"
VOIDST=$(psqlq "SELECT status FROM p_docs WHERE id = $ADJ1;")
expect "전표는 지워지지 않고 void" "void" "$VOIDST"
KEPT=$(psqlq "SELECT count(*) FROM p_stock_moves m JOIN p_doc_lines l ON l.id = m.doc_line_id
               WHERE l.doc_id = $ADJ1;")
expect "원본 행도 그대로 남는다 (총 2행)" 2 "$KEPT"
echo "  (취소 전 현재고 $BEFORE → 취소 뒤 $AFTER)"

sub "입고 취소는 미입고를 되돌린다"
R=$(call qa_employee01 POST "/api/purchase/gr/$GR2/void" "$TOK_EMP1" '{"reason":"S21 검토"}')
echo "  HTTP $(code_of "$R")  $(body_of "$R")"
expect "입고(재고관리 X) 취소 200" 200 "$(code_of "$R")"
REMAIN=$(psqlq "SELECT remaining_qty::text FROM p_backorder WHERE po_line_id = $PO_LINE_B;")
expect "그 줄의 미입고가 2 로 되돌아왔다" "2.000" "$REMAIN"

hr "T-46 ⑦ 동시 이동 2건 (창고 재고 1, 각 1) → 한쪽만 통과"

# 창고 재고를 3 으로 맞춘 뒤(입고 2 추가) 각 2 씩 동시에 뺀다.
R=$(call qa_employee01 POST /api/purchase/gr "$TOK_EMP1" \
  "{\"lines\":[{\"src_line_id\":$PO_LINE_A,\"qty\":\"2\"}]}")
echo "  준비 입고 2 → HTTP $(code_of "$R")"
BAL=$(psqlq "SELECT coalesce(qty,0)::text FROM p_stock_balance
              WHERE location_id = $LOC_WH AND item_id = $ITEM_A;")
echo "  동시성 시험 전 창고 재고: $BAL  (기대 3.000)"

OUT1="$JAR_DIR/c1.txt"; OUT2="$JAR_DIR/c2.txt"
( call qa_employee01 POST /api/purchase/mv "$TOK_EMP1" \
    "{\"from_location_id\":$LOC_WH,\"to_location_id\":$LOC_OR,\"lines\":[{\"item_id\":$ITEM_A,\"qty\":\"2\"}]}" > "$OUT1" ) &
( call qa_employee01 POST /api/purchase/mv "$TOK_EMP1" \
    "{\"from_location_id\":$LOC_WH,\"to_location_id\":$LOC_OR,\"lines\":[{\"item_id\":$ITEM_A,\"qty\":\"2\"}]}" > "$OUT2" ) &
wait
C1=$(code_of "$(cat "$OUT1")"); C2=$(code_of "$(cat "$OUT2")")
echo "  동시 요청 결과: $C1 / $C2"
echo "  본문1: $(body_of "$(cat "$OUT1")" | head -c 160)"
echo "  본문2: $(body_of "$(cat "$OUT2")" | head -c 160)"
OKN=0
[ "$C1" = "201" ] && OKN=$((OKN+1))
[ "$C2" = "201" ] && OKN=$((OKN+1))
expect "동시 2건 중 성공은 정확히 1건" 1 "$OKN"
BAL=$(psqlq "SELECT coalesce(qty,0)::text FROM p_stock_balance
              WHERE location_id = $LOC_WH AND item_id = $ITEM_A;")
expect "현재고가 음수가 되지 않았다 (1 남음)" "1.000" "$BAL"
NEG=$(psqlq "SELECT count(*) FROM p_stock_balance WHERE qty < 0;")
expect "음수 재고 행 0건 (전체)" 0 "$NEG"

sub "채번 — 동시 생성에도 UNIQUE 충돌 없음"
DUP=$(psqlq "SELECT count(*) FROM (SELECT doc_no FROM p_docs GROUP BY doc_no HAVING count(*) > 1) x;")
expect "중복 전표 번호 0건" 0 "$DUP"

hr "T-46 ⑧ 원장은 앱 계정이 고칠 수 없다 (SECURITY R-66)"

sub "tbox_app 으로 UPDATE p_stock_moves → permission denied"
MID=$(psqlq "SELECT id FROM p_stock_moves ORDER BY id DESC LIMIT 1;")
OUT=$(sudo -n -u postgres psql -d "$DB" -X -q -c \
  "SET ROLE tbox_app; UPDATE p_stock_moves SET qty_delta = 999 WHERE id = $MID;" 2>&1)
printf '%s\n' "$OUT" | sed 's/^/  /'
expect "UPDATE 가 permission denied" 1 "$(printf '%s' "$OUT" | grep -c 'permission denied')"
OUT=$(sudo -n -u postgres psql -d "$DB" -X -q -c \
  "SET ROLE tbox_app; DELETE FROM p_stock_moves WHERE id = $MID;" 2>&1)
printf '%s\n' "$OUT" | sed 's/^/  /'
expect "DELETE 도 permission denied" 1 "$(printf '%s' "$OUT" | grep -c 'permission denied')"
DELPRIV=$(psqlq "SELECT count(*) FROM information_schema.table_privileges
                  WHERE grantee = 'tbox_app' AND table_name = 'p_stock_moves'
                    AND privilege_type IN ('UPDATE','DELETE');")
expect "tbox_app 의 원장 UPDATE·DELETE 권한 0건" 0 "$DELPRIV"

hr "T-46 ⑨ 지점 격리 (SECURITY R-65)"

sub "stock(부평점)은 다른 지점 장소의 재고를 볼 수 없다"
R=$(call qa_employee01 GET "/api/purchase/stock?location=$LOC_B" "$TOK_EMP1")
echo "  HTTP $(code_of "$R")  $(body_of "$R" | head -c 200)"
expect "다른 지점 장소 재고 0건" 1 "$(body_of "$R" | grep -c '"total":0')"

R=$(call qa_employee01 GET "/api/purchase/adj/start?location=$LOC_B" "$TOK_EMP1")
echo "  HTTP $(code_of "$R")  $(body_of "$R")"
expect "다른 지점 장소 실사 시작 404" 404 "$(code_of "$R")"

R=$(call qa_employee01 GET "/api/purchase/ledger?location=$LOC_B" "$TOK_EMP1")
expect "다른 지점 수불부 0건" 1 "$(body_of "$R" | grep -c '"total":0')"

sub "stock 이 다른 지점으로 이동하려 하면 막힌다"
R=$(call qa_employee01 POST /api/purchase/mv "$TOK_EMP1" \
  "{\"from_location_id\":$LOC_WH,\"to_location_id\":$LOC_B,\"lines\":[{\"item_id\":$ITEM_A,\"qty\":\"1\"}]}")
echo "  HTTP $(code_of "$R")  $(body_of "$R")"
expect_one_of 400 403 "$(code_of "$R")" "다른 지점으로 이동 → 400 또는 403"
REASON=$(psqlq "SELECT reason FROM audit_logs WHERE action='purchase.move.create' AND result='deny'
                 ORDER BY id DESC LIMIT 1;")
expect "감사 사유 not_in_scope" "not_in_scope" "$REASON"

sub "requester 는 입고·이동을 할 수 없다 (403)"
R=$(call qa_employee02 POST /api/purchase/gr "$TOK_EMP2" \
  "{\"lines\":[{\"src_line_id\":$PO_LINE_A,\"qty\":\"1\"}]}")
echo "  입고 → HTTP $(code_of "$R")  $(body_of "$R")"
expect "requester 입고 403" 403 "$(code_of "$R")"
R=$(call qa_employee02 POST /api/purchase/mv "$TOK_EMP2" \
  "{\"from_location_id\":$LOC_B,\"to_location_id\":$LOC_B,\"lines\":[{\"item_id\":$ITEM_A,\"qty\":\"1\"}]}")
expect "requester 이동 403" 403 "$(code_of "$R")"
R=$(call qa_employee02 GET "/api/purchase/gr/load" "$TOK_EMP2")
expect "requester 입고 불러오기 403" 403 "$(code_of "$R")"

sub "미입고 현황은 requester 도 볼 수 있지만 **자기 지점만**"
R=$(call qa_employee02 GET /api/purchase/backorder "$TOK_EMP2")
echo "  HTTP $(code_of "$R")  $(body_of "$R" | head -c 260)"
expect "requester 미입고 현황 200" 200 "$(code_of "$R")"
expect "부평점 발주서는 보이지 않는다" 0 "$(body_of "$R" | grep -c "$PO1_NO")"
R=$(call qa_manager01 GET /api/purchase/backorder "$TOK_MGR")
expect "buyer 는 전 지점이 보인다" 1 "$(body_of "$R" | grep -c "$PO1_NO")"

hr "T-46 ⑩ 수불부·재고현황 (F-34)"

R=$(call qa_employee01 GET "/api/purchase/ledger?location=$LOC_WH&item=$ITEM_A" "$TOK_EMP1")
echo "  HTTP $(code_of "$R")"; echo "  $(body_of "$R" | head -c 500)"
expect "수불부 200" 200 "$(code_of "$R")"
expect "전표 번호가 줄에 붙어 있다" 1 "$(body_of "$R" | grep -c "$GR1_NO")"
expect "누적 잔액 칸이 채워진다" 1 "$(body_of "$R" | grep -c '"running":true')"

R=$(call qa_employee01 GET "/api/purchase/stock?location=$LOC_CT" "$TOK_EMP1")
echo "  $(body_of "$R" | head -c 500)"
expect "재고현황 200" 200 "$(code_of "$R")"
expect "발주 제안 칸은 23단계부터 서버 계산 값(숫자 문자열)" 1 "$(body_of "$R" | grep -c '"suggest_qty":"[0-9]')"
expect "인가량 4 가 보인다" 1 "$(body_of "$R" | grep -c '"par_qty":4')"

hr "화면 라우트 점검"

R=$(call qa_employee01 GET /purchase "$TOK_EMP1")
expect "stock 역할의 /purchase → 302" 302 "$(code_of "$R")"
LOC_HDR=$(curl -s -o /dev/null -w '%{redirect_url}' -b "$JAR_DIR/qa_employee01.jar" -A "$UA" "$BASE/purchase")
echo "  보내진 곳: $LOC_HDR"
expect "stock 첫 화면은 /purchase/stock" 1 "$(printf '%s' "$LOC_HDR" | grep -c '/purchase/stock$')"
for p in /purchase/stock /purchase/receipt /purchase/move; do
  R=$(call qa_employee01 GET "$p" "$TOK_EMP1")
  expect "$p 화면 200" 200 "$(code_of "$R")"
done
R=$(call qa_employee02 GET /purchase/stock "$TOK_EMP2")
expect "requester 도 현황 화면은 열린다(내용은 자기 지점) 200" 200 "$(code_of "$R")"

hr "DB 권한 — \\dp p_stock_moves"
sudo -n -u postgres psql -d "$DB" -c "\dp p_stock_moves" -c "\dp p_stock_balance" -c "\dp p_backorder" \
  2>&1 | grep -E 'p_stock|p_backorder|tbox_' | sed 's/^/  /'

hr "경보 AL-15 (미입고 30일) — 규칙이 도는지만 본다"
sudo -n php "$(dirname "$0")/../scripts/alerts.php" --dry-run --rule AL-15 2>&1 | sed 's/^/  /'

hr "린트"
bash "$(dirname "$0")/../scripts/security_lint.sh" | tail -6 | sed 's/^/  /'
