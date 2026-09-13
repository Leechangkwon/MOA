#!/usr/bin/env bash
# tests/purchase_stage23.sh — 23단계 수락 기준 실행
#   (PRD 12.2 T-48 + F-36 발주 제안·부족 알림 · 경보 AL-14~17
#    · SECURITY R-65 · R-66 · R-73)
#
# 규칙 (CLAUDE.md 3장)
#   · **검토 계정(qa_ 접두)만** 쓴다. 시드 계정(admin01 …)은 사용자의 자기 공격 전용이다.
#   · 모든 요청에 `-A "tbox-review/<날짜>"` 를 붙인다 — 감사 로그에서 사람의 행동과 갈라 보려는 것.
#   · 비밀번호·TOTP 비밀은 **필요한 필드만 awk 로** 뽑는다. 파일을 통째로 출력(cat)하지 않고,
#     값은 변수에만 담아 화면·로그 어디에도 찍지 않는다.
#
# ★ 시험 안전 (SECURITY R-73). 20·21·22단계 스크립트와 **같은 규칙**이다.
#   ① **사용자가 활동 중이면 시작하지 않는다.** 최근 10분 감사 로그에 비-qa actor 가 하나라도
#      있으면 아무것도 하지 않고 **종료 코드 3** 으로 끝낸다.
#   ② **정리는 qa 계정이 만든 행만.** `created_by` 가 검토 계정인 행만 지운다.
#      표 전체 DELETE·TRUNCATE 는 이 파일에 한 줄도 없다.
#   ③ 시작과 끝에 **비-qa 행 수**를 찍고, 달라졌으면 실패로 센다.
#   ④ 픽스처 이름은 전부 `S23-` 접두다 — 사용자의 실데이터와 이름이 겹쳐 409 가 나지 않게.
#
# ★ 원장(p_stock_moves)·전표 행의 정리는 **postgres 로** 한다(앱 계정에는 DELETE 권한이 없다).
#   그래도 지우는 범위는 「qa 계정이 만든 것」뿐이다.
#
# ★ 경보(AL-14~17)는 기본이 `--dry-run` 이다. 중복 억제만 **실제 발동**으로 확인하며, 그때도
#   규칙 하나(AL-17)만 돌린다 — 그 규칙의 대상은 이 시험이 만든 qa 계정뿐이기 때문이다.
#
# 사용: bash tests/purchase_stage23.sh
set -u

BASE="http://127.0.0.1:8082"
UA="tbox-review/$(date +%F)"
DB="tbox_re"
JAR_DIR="$(mktemp -d)"
TMP_DIR="$(mktemp -d)"
PASS=0
FAIL=0
TODAY="$(date +%Y-%m-%d)"

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

# 「적어도 이만큼」 — 경보처럼 다른 qa 시험이 남긴 대상이 함께 걸릴 수 있는 검사에 쓴다.
expect_min() {
  if [ "${3:-0}" -ge "$2" ] 2>/dev/null; then
    printf '  ✔ %s  (최소=%s 실제=%s)\n' "$1" "$2" "$3"; PASS=$((PASS+1))
  else
    printf '  ✖ %s  (최소=%s 실제=%s)\n' "$1" "$2" "$3"; FAIL=$((FAIL+1))
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
  rm -rf "$JAR_DIR" "$TMP_DIR"
  exit 3
fi
echo "■ 최근 10분 비-qa 활동 0건 — 시험을 시작합니다."

# ─── 로그인 (22단계와 같은 함수) ─────────────────────────────
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

alerts() { sudo -n php "$(dirname "$0")/../scripts/alerts.php" "$@" 2>&1; }
# 탐지 확인은 **빈 상태**로 한다 — 5분마다 도는 cron 이 이미 억제해 둔 대상까지 보려는 것.
alerts_fresh() { rm -f "$TMP_DIR/fresh-state.json"; alerts --state "$TMP_DIR/fresh-state.json" "$@"; }

code_of() { printf '%s' "$1" | tail -1; }
body_of() { printf '%s' "$1" | sed '$d'; }
new_id()  { printf '%s' "$1" | grep -o '"id":[0-9]*' | head -1 | cut -d: -f2; }
jnum()    { printf '%s' "$2" | grep -o "\"$1\":-\?[0-9.]*" | head -1 | cut -d: -f2; }
jstr()    { printf '%s' "$2" | sed -n 's/.*"'"$1"'":"\([^"]*\)".*/\1/p' | head -1; }

# 한 품목의 칸 하나 — 응답에서 그 품목이 있는 조각만 잘라 낸 뒤 키를 읽는다.
field_of() { # $1 item_id  $2 key  $3 body
  printf '%s' "$3" | tr '{' '\n' \
    | grep "\"item_id\":$1," \
    | sed -n 's/.*"'"$2"'":"\{0,1\}\([^,"}]*\)"\{0,1\}.*/\1/p' | head -1
}

# ─────────────────────────────────────────────────────────────
# 인구조사 — 시작·끝에 비-qa 행 수를 찍는다 (R-73 ③)
# ─────────────────────────────────────────────────────────────
QA_IDS=""
CENSUS_BEFORE=""
CENSUS_SUM=0

census() {
  local when="$1"
  printf '  %-20s %8s %10s\n' "표" "전체" "비-qa"
  local total nonqa sum=0
  for t in p_brands p_branches p_locations p_vendors p_categories p_items p_par_levels p_docs; do
    total=$(psqlq "SELECT count(*) FROM $t;")
    nonqa=$(psqlq "SELECT count(*) FROM $t WHERE created_by NOT IN ($QA_IDS);")
    printf '  %-20s %8s %10s\n' "$t" "$total" "$nonqa"
    sum=$((sum + nonqa))
  done
  total=$(psqlq "SELECT count(*) FROM p_doc_lines;")
  nonqa=$(psqlq "SELECT count(*) FROM p_doc_lines l JOIN p_docs d ON d.id = l.doc_id
                  WHERE d.created_by NOT IN ($QA_IDS);")
  printf '  %-20s %8s %10s\n' "p_doc_lines" "$total" "$nonqa"
  sum=$((sum + nonqa))
  total=$(psqlq "SELECT count(*) FROM p_stock_moves;")
  nonqa=$(psqlq "SELECT count(*) FROM p_stock_moves WHERE created_by NOT IN ($QA_IDS);")
  printf '  %-20s %8s %10s\n' "p_stock_moves" "$total" "$nonqa"
  sum=$((sum + nonqa))
  total=$(psqlq "SELECT count(*) FROM p_item_branches;")
  nonqa=$(psqlq "SELECT count(*) FROM p_item_branches ib
                  JOIN p_items i ON i.id = ib.item_id
                 WHERE i.created_by NOT IN ($QA_IDS);")
  printf '  %-20s %8s %10s\n' "p_item_branches" "$total" "$nonqa"
  sum=$((sum + nonqa))
  printf '  %-20s %8s %10s   ← 사용자(비-qa)가 만든 행 합계 (%s)\n' "합계" "-" "$sum" "$when"
  CENSUS_SUM="$sum"
}

cleanup() {
  hr "정리 — **시험이 만든 것만** 되돌린다 (R-73 ②)"
  local qa_ids
  qa_ids=$(psqlq "SELECT string_agg(id::text, ',') FROM users WHERE username LIKE 'qa\\_%';")
  if [ -z "$qa_ids" ]; then
    echo "  ! 검토 계정을 찾지 못해 정리를 건너뜁니다 (아무것도 지우지 않았습니다)"
    rm -rf "$JAR_DIR" "$TMP_DIR"
    return
  fi
  echo "  검토 계정 id: $qa_ids — 이 계정이 만든 행만 지웁니다 (표 전체 DELETE 없음)"

  psqlq "SELECT '  사용자(비-qa)가 만든 행: p_docs='||
           (SELECT count(*) FROM p_docs WHERE created_by NOT IN ($qa_ids))||
         ' p_stock_moves='||(SELECT count(*) FROM p_stock_moves WHERE created_by NOT IN ($qa_ids))||
         ' p_items='||(SELECT count(*) FROM p_items WHERE created_by NOT IN ($qa_ids))||
         ' — 이 행들은 그대로 둡니다';"

  psqlc "UPDATE users SET branch_id = NULL, purchase_role = NULL
          WHERE username IN ('qa_employee01','qa_employee02','qa_manager01');" \
    && echo "  qa 계정 branch_id·purchase_role 원복"

  # 21·20·19단계 정리 (22단계 스크립트와 같은 순서·같은 범위)
  psqlc "DELETE FROM p_stock_moves
          WHERE reverses_move_id IS NOT NULL AND created_by IN ($qa_ids);
         DELETE FROM p_stock_moves WHERE created_by IN ($qa_ids);
         DELETE FROM p_doc_lines WHERE doc_id IN (SELECT id FROM p_docs WHERE created_by IN ($qa_ids));
         DELETE FROM p_docs      WHERE created_by IN ($qa_ids);
         DELETE FROM p_doc_seq   WHERE seq_date = CURRENT_DATE;
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
  rm -rf "$JAR_DIR" "$TMP_DIR"
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

hr "1. 기초정보 씨앗 (전부 S23- 접두)"

R=$(call qa_admin01 POST /api/purchase/brands "$TOK_ADMIN" \
  '{"name":"S23-치과","biz_no":"323-45-67890","ceo_name":"S23 대표","address":"인천 부평구","tel":"032-233-3333"}')
BRAND=$(new_id "$(body_of "$R")"); expect "브랜드 생성" 201 "$(code_of "$R")"

R=$(call qa_admin01 POST /api/purchase/branches "$TOK_ADMIN" "{\"brand_id\":$BRAND,\"name\":\"S23-부평점\"}")
BR_A=$(new_id "$(body_of "$R")"); expect "지점A 생성" 201 "$(code_of "$R")"
R=$(call qa_admin01 POST /api/purchase/branches "$TOK_ADMIN" "{\"brand_id\":$BRAND,\"name\":\"S23-일산점\"}")
BR_B=$(new_id "$(body_of "$R")"); expect "지점B 생성" 201 "$(code_of "$R")"

R=$(call qa_admin01 POST /api/purchase/locations "$TOK_ADMIN" \
  "{\"branch_id\":$BR_A,\"kind\":\"warehouse\",\"name\":\"S23-B1F 창고\"}")
LOC_A=$(new_id "$(body_of "$R")"); expect "지점A 창고 생성" 201 "$(code_of "$R")"
R=$(call qa_admin01 POST /api/purchase/locations "$TOK_ADMIN" \
  "{\"branch_id\":$BR_B,\"kind\":\"warehouse\",\"name\":\"S23-일산 창고\"}")
LOC_B=$(new_id "$(body_of "$R")"); expect "지점B 창고 생성" 201 "$(code_of "$R")"

R=$(call qa_admin01 POST /api/purchase/vendors "$TOK_ADMIN" '{"name":"S23-더존덴탈","code":"S23V1"}')
VENDOR=$(new_id "$(body_of "$R")"); expect "거래처 생성" 201 "$(code_of "$R")"

R=$(call qa_admin01 POST /api/purchase/categories "$TOK_ADMIN" '{"name":"S23-소모품","level":1}')
CAT1=$(new_id "$(body_of "$R")"); expect "대분류 생성" 201 "$(code_of "$R")"

# 품목A — T-48 (a) 의 주인공. 안전 2 · 조달 7일 · 최소단위 5
R=$(call qa_admin01 POST /api/purchase/items "$TOK_ADMIN" \
  "{\"vendor_id\":$VENDOR,\"code\":\"S23-A\",\"name\":\"S23 gauze 2x2\",\"unit\":\"EA\",\"price_in\":\"1000\",\"stock_managed\":true,\"cat1_id\":$CAT1,\"safety_qty\":2,\"lead_days\":7,\"min_order_qty\":5,\"branches\":[$BR_A,$BR_B]}")
ITEM_A=$(new_id "$(body_of "$R")"); expect "품목A 생성 (안전2·조달7·최소5)" 201 "$(code_of "$R")"

# 품목C — 재고가 넉넉해 제안이 0 이어야 하는 줄
R=$(call qa_admin01 POST /api/purchase/items "$TOK_ADMIN" \
  "{\"vendor_id\":$VENDOR,\"code\":\"S23-C\",\"name\":\"S23 cotton roll\",\"unit\":\"EA\",\"price_in\":\"2000\",\"stock_managed\":true,\"cat1_id\":$CAT1,\"safety_qty\":0,\"lead_days\":0,\"min_order_qty\":1}")
ITEM_C=$(new_id "$(body_of "$R")"); expect "품목C 생성 (재고 충분해질 품목)" 201 "$(code_of "$R")"

# 품목D·E — apply 로 함께 담을 줄 (E 는 최소단위 3 → not_multiple 시험에 쓴다)
R=$(call qa_admin01 POST /api/purchase/items "$TOK_ADMIN" \
  "{\"vendor_id\":$VENDOR,\"code\":\"S23-D\",\"name\":\"S23 알콜솜\",\"unit\":\"EA\",\"price_in\":\"4000\",\"stock_managed\":true,\"cat1_id\":$CAT1,\"safety_qty\":0,\"lead_days\":0,\"min_order_qty\":1}")
ITEM_D=$(new_id "$(body_of "$R")"); expect "품목D 생성 (최소단위 1)" 201 "$(code_of "$R")"
R=$(call qa_admin01 POST /api/purchase/items "$TOK_ADMIN" \
  "{\"vendor_id\":$VENDOR,\"code\":\"S23-E\",\"name\":\"S23 bur FG\",\"unit\":\"EA\",\"price_in\":\"3000\",\"stock_managed\":true,\"cat1_id\":$CAT1,\"safety_qty\":0,\"lead_days\":0,\"min_order_qty\":3}")
ITEM_E=$(new_id "$(body_of "$R")"); expect "품목E 생성 (최소단위 3)" 201 "$(code_of "$R")"

# 품목F — AL-16 (입고 단가 +30%) 픽스처. 지금은 기준 단가 1300 으로 만들고 입고 뒤 1000 으로 내린다.
R=$(call qa_admin01 POST /api/purchase/items "$TOK_ADMIN" \
  "{\"vendor_id\":$VENDOR,\"code\":\"S23-F\",\"name\":\"S23 임플란트 픽스처\",\"unit\":\"EA\",\"price_in\":\"1300\",\"stock_managed\":true,\"cat1_id\":$CAT1,\"safety_qty\":0,\"lead_days\":0,\"min_order_qty\":1}")
ITEM_F=$(new_id "$(body_of "$R")"); expect "품목F 생성 (단가 급변 픽스처)" 201 "$(code_of "$R")"

sub "계정 두 축 (qa_employee01 = 지점A stock · qa_employee02 = 지점B requester · qa_manager01 = buyer)"
UID1=$(psqlq "SELECT id FROM users WHERE username = 'qa_employee01';")
UID2=$(psqlq "SELECT id FROM users WHERE username = 'qa_employee02';")
UID3=$(psqlq "SELECT id FROM users WHERE username = 'qa_manager01';")
psqlc "UPDATE users SET branch_id = $BR_A, purchase_role = 'stock'     WHERE id = $UID1;
       UPDATE users SET branch_id = $BR_B, purchase_role = 'requester' WHERE id = $UID2;
       UPDATE users SET branch_id = NULL,  purchase_role = 'buyer'     WHERE id = $UID3;"
echo "  qa_employee01 branch=$BR_A role=stock / qa_employee02 branch=$BR_B role=requester / qa_manager01 buyer"

sub "인가량 — A 10 · C 2 · D 4 · E 5 (전부 지점A 창고)"
for pair in "$ITEM_A:10" "$ITEM_C:2" "$ITEM_D:4" "$ITEM_E:5"; do
  IT=${pair%%:*}; QTY=${pair##*:}
  R=$(call qa_admin01 POST /api/purchase/par-levels "$TOK_ADMIN" \
    "{\"location_id\":$LOC_A,\"item_id\":$IT,\"qty\":$QTY}")
  expect "인가량 등록 (품목 $IT = $QTY)" 201 "$(code_of "$R")"
done

hr "2. T-48 (a) 의 재료 — 현재고 3 · 미입고 2 · 주평균 7"

sub "발주요청 → 발주서 → 보냄 → 입고(3/5) : 품목A 현재고 3 · 미입고 2"
R=$(call qa_manager01 POST /api/purchase/pr "$TOK_MGR" \
  "{\"branch_id\":$BR_A,\"to_location_id\":$LOC_A,\"vendor_id\":$VENDOR,\"lines\":[{\"item_id\":$ITEM_A,\"qty\":\"5\"},{\"item_id\":$ITEM_C,\"qty\":\"10\"},{\"item_id\":$ITEM_F,\"qty\":\"2\"}]}")
PR_BODY=$(body_of "$R"); expect "발주요청 생성" 201 "$(code_of "$R")"
PRL_A=$(printf '%s' "$PR_BODY" | grep -o '"id":[0-9]*' | sed -n '2p' | cut -d: -f2)
PRL_C=$(printf '%s' "$PR_BODY" | grep -o '"id":[0-9]*' | sed -n '3p' | cut -d: -f2)
PRL_F=$(printf '%s' "$PR_BODY" | grep -o '"id":[0-9]*' | sed -n '4p' | cut -d: -f2)

R=$(call qa_manager01 POST /api/purchase/po "$TOK_MGR" \
  "{\"vendor_id\":$VENDOR,\"to_location_id\":$LOC_A,\"lines\":[{\"src_line_id\":$PRL_A,\"qty\":\"5\"},{\"src_line_id\":$PRL_C,\"qty\":\"10\"},{\"src_line_id\":$PRL_F,\"qty\":\"2\"}]}")
PO_BODY=$(body_of "$R"); PO_ID=$(new_id "$PO_BODY"); expect "발주서 생성" 201 "$(code_of "$R")"
POL_A=$(printf '%s' "$PO_BODY" | grep -o '"id":[0-9]*' | sed -n '2p' | cut -d: -f2)
POL_C=$(printf '%s' "$PO_BODY" | grep -o '"id":[0-9]*' | sed -n '3p' | cut -d: -f2)
POL_F=$(printf '%s' "$PO_BODY" | grep -o '"id":[0-9]*' | sed -n '4p' | cut -d: -f2)

R=$(call qa_manager01 POST "/api/purchase/po/$PO_ID/send" "$TOK_MGR" '{}')
expect "발주서 보냄" 200 "$(code_of "$R")"

R=$(call qa_employee01 POST /api/purchase/gr "$TOK_EMP1" \
  "{\"lines\":[{\"src_line_id\":$POL_A,\"qty\":\"3\"},{\"src_line_id\":$POL_C,\"qty\":\"10\"},{\"src_line_id\":$POL_F,\"qty\":\"2\"}]}")
GR_ID=$(new_id "$(body_of "$R")"); expect "입고 (A 3/5 · C 10/10 · F 2/2)" 201 "$(code_of "$R")"

sub "주평균 7 만들기 — 실사로 28 올린 뒤 사용 28 (원장 순증 0, 사용량만 28)"
R=$(call qa_employee01 GET "/api/purchase/adj/start?location=$LOC_A" "$TOK_EMP1")
expect "실사 시작 200" 200 "$(code_of "$R")"
R=$(call qa_employee01 POST /api/purchase/adj "$TOK_EMP1" \
  "{\"location_id\":$LOC_A,\"count_cycle\":\"daily\",\"lines\":[{\"item_id\":$ITEM_A,\"book_qty\":\"3\",\"counted_qty\":\"31\"}]}")
expect "실사 확정 (3 → 31)" 201 "$(code_of "$R")"
R=$(call qa_employee01 POST /api/purchase/is "$TOK_EMP1" \
  "{\"from_location_id\":$LOC_A,\"lines\":[{\"item_id\":$ITEM_A,\"qty\":\"28\"}]}")
expect "사용 28 (28일 사용량 28 → 주평균 7)" 201 "$(code_of "$R")"

ONHAND=$(psqlq "SELECT to_char(coalesce(sum(qty_delta),0),'FM9999999990.000') FROM p_stock_moves
                 WHERE location_id = $LOC_A AND item_id = $ITEM_A;")
expect "품목A 현재고 3" "3.000" "$ONHAND"

hr "3. T-48 (a) — 제안 = ⌈(10+2−3−2+7)÷5⌉×5 = 15"

R=$(call qa_employee01 GET "/api/purchase/suggest?location=$LOC_A&only_short=1" "$TOK_EMP1")
SG_BODY=$(body_of "$R")
expect "제안 목록 200" 200 "$(code_of "$R")"
echo "  $SG_BODY" | head -c 700; echo

expect "품목A 제안 15"        "15.000" "$(field_of "$ITEM_A" suggest_qty "$SG_BODY")"
expect "근거 인가량 10"       "10"     "$(field_of "$ITEM_A" par_qty "$SG_BODY")"
expect "근거 안전재고 2"      "2"      "$(field_of "$ITEM_A" safety_qty "$SG_BODY")"
expect "근거 현재고 3"        "3.000"  "$(field_of "$ITEM_A" on_hand "$SG_BODY")"
expect "근거 미입고 2"        "2.000"  "$(field_of "$ITEM_A" incoming "$SG_BODY")"
expect "근거 주평균 7"        "7.00"   "$(field_of "$ITEM_A" weekly_use "$SG_BODY")"
expect "근거 조달기간 7일"    "7"      "$(field_of "$ITEM_A" lead_days "$SG_BODY")"
expect "근거 조달 예상 사용 7" "7.000" "$(field_of "$ITEM_A" lead_use "$SG_BODY")"
expect "근거 소요량 14"       "14.000" "$(field_of "$ITEM_A" need_qty "$SG_BODY")"
expect "근거 최소단위 5"      "5"      "$(field_of "$ITEM_A" min_order_qty "$SG_BODY")"
expect "주평균 근거 표시"     "weekly_avg_4w" "$(field_of "$ITEM_A" usage_basis "$SG_BODY")"

sub "현재고가 충분하면 제안 0 · only_short 목록에서 제외 (품목C: 인가 2 · 현재고 10)"
expect "품목C 는 제안 목록에 없다" 0 "$(printf '%s' "$SG_BODY" | grep -c "\"item_id\":$ITEM_C,")"
R=$(call qa_employee01 GET "/api/purchase/stock?location=$LOC_A" "$TOK_EMP1")
ST_BODY=$(body_of "$R")
expect "재고현황 200" 200 "$(code_of "$R")"
expect "재고현황에는 품목C 가 제안 0 으로 보인다" "0.000" "$(field_of "$ITEM_C" suggest_qty "$ST_BODY")"
expect "재고현황의 품목A 제안도 15 (같은 계산 한 곳)" "15.000" "$(field_of "$ITEM_A" suggest_qty "$ST_BODY")"

sub "requester(지점B)는 자기 지점 장소만 — 지점A 제안은 0건 (R-65 · T-42)"
R=$(call qa_employee02 GET "/api/purchase/suggest?location=$LOC_A&only_short=1" "$TOK_EMP2")
expect "다른 지점 장소 제안 0건" 1 "$(body_of "$R" | grep -c '"total":0')"
R=$(call qa_employee02 GET "/api/purchase/suggest?only_short=1" "$TOK_EMP2")
expect "requester 응답에 지점A 행 없음" 0 "$(body_of "$R" | grep -c "\"branch_id\":$BR_A,")"

hr "4. T-48 (b) — 제안 3건 → 발주요청 전표 1건 · 줄 3"

R=$(call qa_employee01 GET "/api/purchase/suggest?location=$LOC_A&only_short=1" "$TOK_EMP1")
SG_BODY=$(body_of "$R")
expect "품목D 제안 4 (인가 4 · 재고 0 · 최소 1)" "4.000" "$(field_of "$ITEM_D" suggest_qty "$SG_BODY")"
expect "품목E 제안 6 (인가 5 · 최소 3 → 올림)" "6.000" "$(field_of "$ITEM_E" suggest_qty "$SG_BODY")"

R=$(call qa_manager01 POST /api/purchase/suggest/apply "$TOK_MGR" \
  "{\"location_id\":$LOC_A,\"lines\":[{\"item_id\":$ITEM_A},{\"item_id\":$ITEM_D},{\"item_id\":$ITEM_E}]}")
AP_BODY=$(body_of "$R")
AP_ID=$(new_id "$AP_BODY")
expect "제안 담기 201" 201 "$(code_of "$R")"
echo "  $AP_BODY" | head -c 400; echo
expect "전표 종류 pr" "pr" "$(psqlq "SELECT doc_type FROM p_docs WHERE id = $AP_ID;")"
expect "줄 3개" 3 "$(psqlq "SELECT count(*) FROM p_doc_lines WHERE doc_id = $AP_ID;")"
expect "전표 1건" 1 "$(psqlq "SELECT count(*) FROM p_docs WHERE id = $AP_ID AND doc_type='pr';")"
expect "메모가 「발주 제안 …」" "발주 제안 $TODAY" "$(psqlq "SELECT memo FROM p_docs WHERE id = $AP_ID;")"
expect "제안 합계 25 (15+4+6)" "25.000" "$(jstr suggested_qty "$AP_BODY")"
expect "담은 합계도 25 (고치지 않았다)" "25.000" "$(jstr applied_qty "$AP_BODY")"
AUD=$(psqlq "SELECT detail->>'suggested_qty'||'/'||(detail->>'applied_qty')||'/'||(detail->>'lines')
               FROM audit_logs WHERE action='purchase.suggest.apply' AND result='allow'
              ORDER BY id DESC LIMIT 1;")
expect "감사 detail suggested/applied/lines" "25.000/25.000/3" "$AUD"

sub "수량을 고쳐 담기 — 최소단위의 배수면 통과"
R=$(call qa_manager01 POST /api/purchase/suggest/apply "$TOK_MGR" \
  "{\"location_id\":$LOC_A,\"lines\":[{\"item_id\":$ITEM_E,\"qty\":\"9\"}]}")
AP2=$(body_of "$R")
expect "고친 수량 9 (최소 3의 배수) → 201" 201 "$(code_of "$R")"
expect "제안 6 · 담은 9 가 둘 다 남는다" "6.000/9.000" \
  "$(jstr suggested_qty "$AP2")/$(jstr applied_qty "$AP2")"

sub "최소단위의 배수가 아니면 400 not_multiple"
R=$(call qa_manager01 POST /api/purchase/suggest/apply "$TOK_MGR" \
  "{\"location_id\":$LOC_A,\"lines\":[{\"item_id\":$ITEM_E,\"qty\":\"7\"}]}")
expect "7 (최소 3의 배수 아님) → 400" 400 "$(code_of "$R")"
REASON=$(psqlq "SELECT reason FROM audit_logs WHERE action='purchase.suggest.apply' AND result='deny'
                 ORDER BY id DESC LIMIT 1;")
expect "감사 사유 not_multiple" "not_multiple" "$REASON"

sub "제안이 없는 품목은 담을 수 없다 (품목C: 재고 충분)"
R=$(call qa_manager01 POST /api/purchase/suggest/apply "$TOK_MGR" \
  "{\"location_id\":$LOC_A,\"lines\":[{\"item_id\":$ITEM_C}]}")
expect "제안 없는 품목 → 400" 400 "$(code_of "$R")"
REASON=$(psqlq "SELECT reason FROM audit_logs WHERE action='purchase.suggest.apply' AND result='deny'
                 ORDER BY id DESC LIMIT 1;")
expect "감사 사유 no_suggestion" "no_suggestion" "$REASON"

sub "남의 지점 장소에는 담을 수 없다 (R-65)"
R=$(call qa_employee02 POST /api/purchase/suggest/apply "$TOK_EMP2" \
  "{\"location_id\":$LOC_A,\"lines\":[{\"item_id\":$ITEM_A}]}")
expect "다른 지점 장소 → 400" 400 "$(code_of "$R")"
REASON=$(psqlq "SELECT reason FROM audit_logs WHERE action='purchase.suggest.apply' AND result='deny'
                 ORDER BY id DESC LIMIT 1;")
expect "감사 사유 not_in_scope" "not_in_scope" "$REASON"

sub "단가·금액은 본문으로 받지 않는다 (SECURITY R-66)"
R=$(call qa_manager01 POST /api/purchase/suggest/apply "$TOK_MGR" \
  "{\"location_id\":$LOC_A,\"lines\":[{\"item_id\":$ITEM_A,\"unit_price\":\"1\"}]}")
expect "허용 키 밖(unit_price) → 400" 400 "$(code_of "$R")"

hr "5. T-48 (f) — 품목의 사용 지점 「빼기」"

expect "빼기 전: 품목A 사용 지점 2개" 2 \
  "$(psqlq "SELECT count(*) FROM p_item_branches WHERE item_id = $ITEM_A AND is_active;")"
R=$(call qa_employee02 GET "/api/purchase/items?branch_used=$BR_B" "$TOK_EMP2")
expect "빼기 전: 지점B 품목 검색에 품목A 가 있다" 1 \
  "$(body_of "$R" | grep -c "\"id\":$ITEM_A,")"

R=$(call qa_admin01 PATCH "/api/purchase/items/$ITEM_A" "$TOK_ADMIN" "{\"branches_off\":[$BR_B]}")
expect "사용 지점 빼기 200" 200 "$(code_of "$R")"
expect "p_item_branches.is_active = false" "f" \
  "$(psqlq "SELECT is_active FROM p_item_branches WHERE item_id = $ITEM_A AND branch_id = $BR_B;")"
expect "행은 남아 있다 (지운 것이 아니다)" 1 \
  "$(psqlq "SELECT count(*) FROM p_item_branches WHERE item_id = $ITEM_A AND branch_id = $BR_B;")"
ACT=$(psqlq "SELECT action FROM audit_logs WHERE target_id = $ITEM_A AND result='allow'
              ORDER BY id DESC LIMIT 1;")
expect "감사 action purchase.master.branch_off" "purchase.master.branch_off" "$ACT"

R=$(call qa_employee02 GET "/api/purchase/items?branch_used=$BR_B" "$TOK_EMP2")
expect "빼기 뒤: 지점B 품목 검색에서 빠진다" 0 \
  "$(body_of "$R" | grep -c "\"id\":$ITEM_A,")"

hr "6. T-48 (c) — 경보 AL-14 · AL-15 · AL-16 · AL-17"

sub "AL-14 — 보낸 뒤 발주서 수정 (사유 필수)"
R=$(call qa_manager01 PATCH "/api/purchase/po/$PO_ID" "$TOK_MGR" \
  '{"header":{"cc":"S23 구매팀"},"reason":"거래처 요청으로 수신 참조 보정"}')
expect "보낸 뒤 수정 200" 200 "$(code_of "$R")"
AFTERSENT=$(psqlq "SELECT detail->>'after_sent' FROM audit_logs
                    WHERE action='purchase.order.update' AND result='allow'
                    ORDER BY id DESC LIMIT 1;")
expect "감사 detail.after_sent = true (AL-14 근거)" "true" "$AFTERSENT"
OUT=$(alerts_fresh --dry-run --rule AL-14)
echo "$OUT" | tail -4 | sed 's/^/  /'
expect_min "AL-14 dry-run 1건 이상" 1 "$(printf '%s' "$OUT" | grep -c '\[dry-run\] AL-14')"

sub "AL-15 — 미입고 30일 (보낸 시각을 31일 전으로 옮긴 픽스처)"
psqlc "UPDATE p_docs SET sent_at = now() - interval '31 days' WHERE id = $PO_ID;"
OUT=$(alerts_fresh --dry-run --rule AL-15)
echo "$OUT" | tail -4 | sed 's/^/  /'
expect "AL-15 가 거래처 하나로 묶어 올린다" 1 \
  "$(printf '%s' "$OUT" | grep -c "\[dry-run\] AL-15 purchase_vendor#$VENDOR")"
expect "문장이 「거래처 … 발주서 N건 미입고 30일 초과」" 1 \
  "$(printf '%s' "$OUT" | grep -c '발주서 1건 미입고 30일 초과')"
expect "문장에 전표 번호가 없다" 0 "$(printf '%s' "$OUT" | grep -c 'PO-')"

sub "AL-16 — 입고 단가가 품목 기준 단가보다 30% 높다"
R=$(call qa_admin01 PATCH "/api/purchase/items/$ITEM_F" "$TOK_ADMIN" '{"price_in":"1000"}')
expect "품목F 기준 단가 1300 → 1000 (입고 단가는 1300 그대로)" 200 "$(code_of "$R")"
GRL_F=$(psqlq "SELECT l.id FROM p_doc_lines l WHERE l.doc_id = $GR_ID AND l.item_id = $ITEM_F;")
OUT=$(alerts_fresh --dry-run --rule AL-16)
echo "$OUT" | tail -4 | sed 's/^/  /'
expect "AL-16 이 그 입고 줄을 올린다" 1 \
  "$(printf '%s' "$OUT" | grep -c "\[dry-run\] AL-16 purchase_gr_line#$GRL_F")"
expect_min "AL-16 입고 출처 1건 이상" 1 "$(printf '%s' "$OUT" | grep -c '"source":"receipt"')"
expect "문장에 품목 코드가 없다" 0 "$(printf '%s' "$OUT" | grep -c 'S23-F')"

sub "AL-17 — 한 사람이 1시간에 5건 이상 취소"
# 되돌리는 **순서가 있다** — 원장이 음수가 되면 서버가 막기 때문이다(그것이 옳다).
#   사용 취소(+28) → 실사 취소(−28) → 입고 취소(−3) → 제안으로 만든 요청 둘
VOIDED=0
IS_ID=$(psqlq "SELECT id FROM p_docs WHERE doc_type='is' AND created_by = $UID1 ORDER BY id DESC LIMIT 1;")
R=$(call qa_manager01 POST "/api/purchase/is/$IS_ID/void" "$TOK_MGR" '{"reason":"S23 시험 취소 1"}')
[ "$(code_of "$R")" = "200" ] && VOIDED=$((VOIDED+1))
ADJ_ID=$(psqlq "SELECT id FROM p_docs WHERE doc_type='adj' AND created_by = $UID1 ORDER BY id DESC LIMIT 1;")
R=$(call qa_manager01 POST "/api/purchase/adj/$ADJ_ID/void" "$TOK_MGR" '{"reason":"S23 시험 취소 2"}')
[ "$(code_of "$R")" = "200" ] && VOIDED=$((VOIDED+1))
R=$(call qa_manager01 POST "/api/purchase/gr/$GR_ID/void" "$TOK_MGR" '{"reason":"S23 시험 취소 3"}')
[ "$(code_of "$R")" = "200" ] && VOIDED=$((VOIDED+1))
for pid in $(psqlq "SELECT id FROM p_docs WHERE doc_type='pr' AND created_by = $UID3 AND status <> 'void' ORDER BY id DESC LIMIT 2;"); do
  R=$(call qa_manager01 POST "/api/purchase/pr/$pid/void" "$TOK_MGR" '{"reason":"S23 시험 취소"}')
  [ "$(code_of "$R")" = "200" ] && VOIDED=$((VOIDED+1))
done
expect_min "취소 5건 이상 성공" 5 "$VOIDED"

OUT=$(alerts_fresh --dry-run --rule AL-17)
echo "$OUT" | tail -4 | sed 's/^/  /'
expect "AL-17 이 그 사람을 올린다" 1 "$(printf '%s' "$OUT" | grep -c "\[dry-run\] AL-17 user#$UID3")"
expect "근거에 계정명이 없다" 0 "$(printf '%s' "$OUT" | grep -c 'qa_manager01')"

sub "중복 억제 — 같은 대상은 24시간 안에 다시 발동하지 않는다"
# 운영 상태 파일은 5분마다 도는 cron 이 이미 써 놓았을 수 있다. 「처음 발동 → 다시 억제」를
# 보려면 **빈 상태**가 필요하므로 시험용 상태 파일(--state)을 쓴다 — 운영 상태는 건드리지 않는다.
ST_FILE="$TMP_DIR/alerts-state.json"
OUT=$(alerts --rule AL-17 --state "$ST_FILE")
echo "$OUT" | tail -4 | sed 's/^/  /'
expect_min "첫 실행에서 실제 발동" 1 "$(printf '%s' "$OUT" | grep -c "★ AL-17 user#$UID3 발동")"
OUT=$(alerts --rule AL-17 --state "$ST_FILE")
echo "$OUT" | tail -4 | sed 's/^/  /'
expect "재실행 시 같은 대상 중복 0" 1 \
  "$(printf '%s' "$OUT" | grep -c "AL-17 user#$UID3 — 1440분 안에 이미 발동해 건너뛴다")"
expect "재실행에서 새로 발동한 것 0건" 0 "$(printf '%s' "$OUT" | grep -c "★ AL-17 user#$UID3 발동")"
expect "운영 상태 파일은 건드리지 않았다" 0 \
  "$(sudo -n grep -c \"AL-17|user#$UID3\" /var/lib/tbox-v2/alerts-state.json 2>/dev/null || echo 0)"

hr "7. T-48 (d) — 보안 린트 0건"
LINT=$(bash "$(dirname "$0")/../scripts/security_lint.sh" 2>&1)
echo "$LINT" | tail -3 | sed 's/^/  /'
expect "린트 통과 (금지 패턴 0건)" 1 "$(printf '%s' "$LINT" | grep -c '결과: 통과')"
expect "린트 실패 항목 0" 0 "$(printf '%s' "$LINT" | grep -c '^✖')"

hr "8. 화면 라우트 점검"
for p in /purchase/stock /purchase/pr; do
  CODE=$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR_DIR/qa_employee01.jar" -A "$UA" "$BASE$p")
  expect "화면 $p" 200 "$CODE"
done
CODE=$(curl -s -o /dev/null -w '%{http_code}' -A "$UA" "$BASE/api/purchase/suggest")
expect "미로그인 /api/purchase/suggest 401" 401 "$CODE"
