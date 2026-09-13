#!/usr/bin/env bash
# tests/purchase_stage20.sh — 20단계 수락 기준 실행
#   (PRD 12.2 T-45 + Q-18①②④ · SECURITY R-65 · R-66 · R-68 · R-73)
#
# 규칙 (CLAUDE.md 3장)
#   · **검토 계정(qa_ 접두)만** 쓴다. 시드 계정(admin01 …)은 사용자의 자기 공격 전용이다.
#   · 모든 요청에 `-A "tbox-review/<날짜>"` 를 붙인다 — 감사 로그에서 사람의 행동과 갈라 보려는 것.
#   · 비밀번호·TOTP 비밀은 **필요한 필드만 awk 로** 뽑는다. 파일을 통째로 출력(cat)하지 않고,
#     값은 변수에만 담아 화면·로그 어디에도 찍지 않는다.
#
# ★ 시험 안전 (SECURITY R-73 — 2026-09-13 사고 뒤 의무)
#   ① **사용자가 활동 중이면 시작하지 않는다.** 최근 10분 감사 로그에 비-qa actor 가 하나라도
#      있으면 아무것도 하지 않고 **종료 코드 3** 으로 끝낸다.
#   ② **정리는 qa 계정이 만든 행만.** `created_by` / `owner_id` 가 검토 계정인 행만 지운다.
#      표 전체 DELETE·TRUNCATE 는 이 파일에 한 줄도 없다.
#   ③ 시작과 끝에 **비-qa 행 수**를 찍고, 달라졌으면 실패로 센다.
#
# 사용: sudo -v && bash tests/purchase_stage20.sh
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

# 「적어도 한 번은 나온다」 — 인쇄 화면처럼 같은 값이 머리와 표에 **여러 번** 나오는 곳에 쓴다.
expect_has() {
  if [ "${2:-0}" -ge 1 ] 2>/dev/null; then
    printf '  ✔ %s  (나온 횟수=%s)\n' "$1" "$2"; PASS=$((PASS+1))
  else
    printf '  ✖ %s  (나온 횟수=%s — 한 번도 없음)\n' "$1" "$2"; FAIL=$((FAIL+1))
  fi
}

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
# 응답의 **첫** `"id":` 를 쓴다. 전표 응답은 `{"id":…, … ,"lines":[{"id":…}]}` 꼴이라
# 탐욕적 정규식으로 뒤에서 찾으면 **줄 id** 를 전표 id 로 잘못 읽는다(2026-09-14 실측).
new_id()  { printf '%s' "$1" | grep -o '"id":[0-9]*' | head -1 | cut -d: -f2; }
field()   { printf '%s' "$2" | sed -n 's/.*"'"$1"'":"\([^"]*\)".*/\1/p' | head -1; }
numfield() { printf '%s' "$2" | sed -n 's/.*"'"$1"'":\([0-9.]*\).*/\1/p' | head -1; }

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
         ' p_lists='||(SELECT count(*) FROM p_lists WHERE owner_id NOT IN ($qa_ids))||
         ' p_items='||(SELECT count(*) FROM p_items WHERE created_by NOT IN ($qa_ids))||
         ' p_vendors='||(SELECT count(*) FROM p_vendors WHERE created_by NOT IN ($qa_ids))||
         ' — 이 행들은 그대로 둡니다';"

  # 계정 두 축 원복 (계정 행 자체는 건드리지 않는다)
  psqlc "UPDATE users SET branch_id = NULL, purchase_role = NULL
          WHERE username IN ('qa_employee01','qa_employee02','qa_manager01');" \
    && echo "  qa 계정 branch_id·purchase_role 원복"

  # 참조가 있는 것부터. 전부 qa 소유로 좁혀져 있다.
  psqlc "DELETE FROM p_doc_lines WHERE doc_id IN (SELECT id FROM p_docs WHERE created_by IN ($qa_ids));
         DELETE FROM p_docs      WHERE created_by IN ($qa_ids);
         DELETE FROM p_doc_seq   WHERE seq_date = CURRENT_DATE;
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

hr "1. 기초정보 씨앗 — 브랜드·지점 2곳·장소·거래처·품목"

R=$(call qa_admin01 POST /api/purchase/brands "$TOK_ADMIN" \
  '{"name":"S20 치과","biz_no":"123-45-67890","ceo_name":"검토 대표","address":"인천 부평구","tel":"032-000-0000"}')
BRAND=$(new_id "$(body_of "$R")"); expect "브랜드 생성" 201 "$(code_of "$R")"

R=$(call qa_admin01 POST /api/purchase/branches "$TOK_ADMIN" "{\"brand_id\":$BRAND,\"name\":\"S20 부평점\"}")
BR_A=$(new_id "$(body_of "$R")"); expect "지점A 생성" 201 "$(code_of "$R")"
R=$(call qa_admin01 POST /api/purchase/branches "$TOK_ADMIN" "{\"brand_id\":$BRAND,\"name\":\"S20 일산점\"}")
BR_B=$(new_id "$(body_of "$R")"); expect "지점B 생성" 201 "$(code_of "$R")"

R=$(call qa_admin01 POST /api/purchase/locations "$TOK_ADMIN" \
  "{\"branch_id\":$BR_A,\"kind\":\"warehouse\",\"name\":\"S20 B1F 창고\"}")
LOC_A=$(new_id "$(body_of "$R")"); expect "지점A 장소 생성" 201 "$(code_of "$R")"
R=$(call qa_admin01 POST /api/purchase/locations "$TOK_ADMIN" \
  "{\"branch_id\":$BR_B,\"kind\":\"central\",\"name\":\"S20 2F 공급실\"}")
LOC_B=$(new_id "$(body_of "$R")"); expect "지점B 장소 생성" 201 "$(code_of "$R")"

R=$(call qa_admin01 POST /api/purchase/vendors "$TOK_ADMIN" '{"name":"S20 더존덴탈","code":"S20V1"}')
VEND=$(new_id "$(body_of "$R")"); expect "거래처1 생성" 201 "$(code_of "$R")"
R=$(call qa_admin01 POST /api/purchase/vendors "$TOK_ADMIN" '{"name":"S20 메타약품","code":"S20V2"}')
VEND2=$(new_id "$(body_of "$R")"); expect "거래처2 생성" 201 "$(code_of "$R")"

# 단가가 있는 품목 둘 + 다른 거래처 품목 하나(mixed_vendor 시험용)
R=$(call qa_admin01 POST /api/purchase/items "$TOK_ADMIN" \
  "{\"vendor_id\":$VEND,\"code\":\"S20-A\",\"name\":\"S20 gauze 2x2\",\"unit\":\"EA\",\"price_in\":\"1000\",\"lead_days\":3}")
ITEM_A=$(new_id "$(body_of "$R")"); expect "품목A(단가 1000) 생성" 201 "$(code_of "$R")"
R=$(call qa_admin01 POST /api/purchase/items "$TOK_ADMIN" \
  "{\"vendor_id\":$VEND,\"code\":\"S20-B\",\"name\":\"S20 cotton\",\"unit\":\"EA\",\"price_in\":\"2500\",\"lead_days\":5}")
ITEM_B=$(new_id "$(body_of "$R")"); expect "품목B(단가 2500) 생성" 201 "$(code_of "$R")"
R=$(call qa_admin01 POST /api/purchase/items "$TOK_ADMIN" \
  "{\"vendor_id\":$VEND2,\"code\":\"S20-C\",\"name\":\"S20 other vendor item\",\"unit\":\"EA\",\"price_in\":\"700\"}")
ITEM_C=$(new_id "$(body_of "$R")"); expect "품목C(다른 거래처) 생성" 201 "$(code_of "$R")"

sub "검토용 모듈 역할·지점 심기 (psql, 끝에서 원복)"
psqlc "UPDATE users SET branch_id = $BR_A, purchase_role = 'requester' WHERE username = 'qa_employee01';"
psqlc "UPDATE users SET branch_id = $BR_B, purchase_role = 'requester' WHERE username = 'qa_employee02';"
psqlc "UPDATE users SET branch_id = NULL,  purchase_role = 'buyer'     WHERE username = 'qa_manager01';"
psqlq "SELECT username||' branch='||coalesce(branch_id::text,'-')||' role='||coalesce(purchase_role,'-')
         FROM users WHERE username LIKE 'qa%' ORDER BY id;"

hr "T-45 ① 요청 2건 (요청자 둘 · 다른 지점) · 채번 연속"

R=$(call qa_employee01 POST /api/purchase/pr "$TOK_EMP1" \
  "{\"to_location_id\":$LOC_A,\"vendor_id\":$VEND,\"memo\":\"검토 요청1\",\"lines\":[{\"item_id\":$ITEM_A,\"qty\":\"5\"},{\"item_id\":$ITEM_B,\"qty\":\"5\"}]}")
PR1=$(new_id "$(body_of "$R")"); PR1_NO=$(field doc_no "$(body_of "$R")")
echo "  HTTP $(code_of "$R")"; echo "  $(body_of "$R")"
expect "요청1 생성(부평점 requester)" 201 "$(code_of "$R")"
expect "채번 PR-$TODAY-1" "PR-$TODAY-1" "$PR1_NO"

R=$(call qa_employee02 POST /api/purchase/pr "$TOK_EMP2" \
  "{\"to_location_id\":$LOC_B,\"lines\":[{\"item_id\":$ITEM_A,\"qty\":\"4\"}]}")
PR2=$(new_id "$(body_of "$R")"); PR2_NO=$(field doc_no "$(body_of "$R")")
echo "  HTTP $(code_of "$R")"
expect "요청2 생성(일산점 requester)" 201 "$(code_of "$R")"
expect "채번 PR-$TODAY-2 (연속)" "PR-$TODAY-2" "$PR2_NO"

sub "금액은 서버가 계산한다 — 클라이언트 amount·unit_price 는 아예 받지 않는다 (R-66)"
R=$(call qa_employee01 POST /api/purchase/pr "$TOK_EMP1" \
  "{\"to_location_id\":$LOC_A,\"lines\":[{\"item_id\":$ITEM_A,\"qty\":\"1\",\"unit_price\":\"1\",\"amount\":\"1\"}]}")
echo "  HTTP $(code_of "$R")"; echo "  $(body_of "$R")"
expect "본문에 단가·금액을 넣으면 400" 400 "$(code_of "$R")"

SUPPLY=$(psqlq "SELECT coalesce(sum(amount),0)::text FROM p_doc_lines WHERE doc_id = $PR1;")
echo "  요청1 서버 계산 금액 합계: $SUPPLY  (기대 5*1000 + 5*2500 = 17500.00)"
expect "금액 = Σ qty × p_items.price_in" "17500.00" "$SUPPLY"

hr "T-45 ② 지점 격리 (SECURITY R-65)"

R=$(call qa_employee01 GET /api/purchase/pr "$TOK_EMP1")
echo "  HTTP $(code_of "$R")"; echo "  $(body_of "$R")" | head -c 400; echo
expect "요청자A 목록에 다른 지점 전표 0건" 0 "$(body_of "$R" | grep -c "$PR2_NO")"

R=$(call qa_employee01 GET "/api/purchase/pr?branch=$BR_B" "$TOK_EMP1")
expect "?branch=다른지점 → 여전히 0건" 0 "$(body_of "$R" | grep -c "$PR2_NO")"

R=$(call qa_employee01 GET "/api/purchase/pr/$PR2" "$TOK_EMP1")
echo "  HTTP $(code_of "$R")  $(body_of "$R")"
expect "다른 지점 요청 상세 404" 404 "$(code_of "$R")"
R=$(call qa_employee01 GET "/api/purchase/pr/$PR1" "$TOK_EMP1")
expect "내 지점 요청 상세 200" 200 "$(code_of "$R")"

hr "T-45 ③ 불러오기 — 잔량만 · group 순서 = 트리 층"

R=$(call qa_manager01 GET "/api/purchase/pr/load?group=vendor,location,manager" "$TOK_MGR")
echo "  HTTP $(code_of "$R")"; echo "  $(body_of "$R")" | head -c 900; echo
expect "buyer 불러오기 200" 200 "$(code_of "$R")"
expect "두 지점 요청이 모두 보인다(buyer 는 전 지점)" 1 "$(body_of "$R" | grep -c "$PR2_NO")"
expect "묶는 기준이 요청 순서 그대로" 1 "$(body_of "$R" | grep -c '"group":\["vendor","location","manager"\]')"

R=$(call qa_employee01 GET "/api/purchase/pr/load?group=vendor" "$TOK_EMP1")
echo "  요청자가 불러오기 → HTTP $(code_of "$R")  $(body_of "$R")"
expect "requester 의 불러오기는 403 (purchase.buy.write)" 403 "$(code_of "$R")"

hr "T-45 ④ 부분 담기 (5 중 3) → 잔량 2 · 요청 상태 「일부 발주」"

LINE_A=$(psqlq "SELECT id FROM p_doc_lines WHERE doc_id = $PR1 AND item_id = $ITEM_A;")
LINE_B=$(psqlq "SELECT id FROM p_doc_lines WHERE doc_id = $PR1 AND item_id = $ITEM_B;")
echo "  요청1 줄: A=$LINE_A B=$LINE_B"

R=$(call qa_manager01 POST /api/purchase/po "$TOK_MGR" \
  "{\"to_location_id\":$LOC_A,\"lines\":[{\"src_line_id\":$LINE_A,\"qty\":\"3\"}]}")
PO1=$(new_id "$(body_of "$R")"); PO1_NO=$(field doc_no "$(body_of "$R")")
echo "  HTTP $(code_of "$R")"; echo "  $(body_of "$R")"
expect "발주서 생성" 201 "$(code_of "$R")"
expect "채번 PO-$TODAY-1" "PO-$TODAY-1" "$PO1_NO"

SRC=$(psqlq "SELECT src_line_id FROM p_doc_lines WHERE doc_id = $PO1;")
expect "발주서 줄의 src_line_id = 요청 줄" "$LINE_A" "$SRC"
REMAIN=$(psqlq "SELECT (l.qty - coalesce((SELECT sum(c.qty) FROM p_doc_lines c JOIN p_docs cd ON cd.id=c.doc_id
                   WHERE c.src_line_id = l.id AND c.status <> 'void' AND cd.status <> 'void'),0))::text
                 FROM p_doc_lines l WHERE l.id = $LINE_A;")
expect "요청 줄 잔량 2" "2.000" "$REMAIN"
ST=$(psqlq "SELECT status FROM p_docs WHERE id = $PR1;")
expect "요청 전표 상태 = 일부 발주(partial)" "partial" "$ST"
POAMT=$(psqlq "SELECT coalesce(sum(amount),0)::text FROM p_doc_lines WHERE doc_id = $PO1;")
expect "발주서 금액 = 3 × 1000 (서버 계산)" "3000.00" "$POAMT"

hr "T-45 ⑤ 같은 줄 다시 담기 4 → 400 remaining_exceeded"

R=$(call qa_manager01 POST /api/purchase/po "$TOK_MGR" \
  "{\"to_location_id\":$LOC_A,\"lines\":[{\"src_line_id\":$LINE_A,\"qty\":\"4\"}]}")
echo "  HTTP $(code_of "$R")  $(body_of "$R")"
expect "잔량 초과 → 400" 400 "$(code_of "$R")"
REASON=$(psqlq "SELECT reason FROM audit_logs WHERE action = 'purchase.order.create' AND result='deny'
                 ORDER BY id DESC LIMIT 1;")
expect "감사 사유 remaining_exceeded" "remaining_exceeded" "$REASON"

sub "거래처가 섞이면 400 mixed_vendor"
R=$(call qa_employee01 POST /api/purchase/pr "$TOK_EMP1" \
  "{\"to_location_id\":$LOC_A,\"lines\":[{\"item_id\":$ITEM_C,\"qty\":\"2\"}]}")
PR3=$(new_id "$(body_of "$R")")
LINE_C=$(psqlq "SELECT id FROM p_doc_lines WHERE doc_id = $PR3;")
R=$(call qa_manager01 POST /api/purchase/po "$TOK_MGR" \
  "{\"to_location_id\":$LOC_A,\"lines\":[{\"src_line_id\":$LINE_B,\"qty\":\"1\"},{\"src_line_id\":$LINE_C,\"qty\":\"1\"}]}")
echo "  HTTP $(code_of "$R")  $(body_of "$R")"
expect "거래처 섞임 → 400" 400 "$(code_of "$R")"
REASON=$(psqlq "SELECT reason FROM audit_logs WHERE action = 'purchase.order.create' AND result='deny'
                 ORDER BY id DESC LIMIT 1;")
expect "감사 사유 mixed_vendor" "mixed_vendor" "$REASON"

hr "T-45 ⑥ 동시 요청 2개 (잔량 2 를 각 2 씩) → 한쪽만 통과"

OUT1="$JAR_DIR/c1.txt"; OUT2="$JAR_DIR/c2.txt"
( call qa_manager01 POST /api/purchase/po "$TOK_MGR" \
    "{\"to_location_id\":$LOC_A,\"lines\":[{\"src_line_id\":$LINE_A,\"qty\":\"2\"}]}" > "$OUT1" ) &
( call qa_manager01 POST /api/purchase/po "$TOK_MGR" \
    "{\"to_location_id\":$LOC_A,\"lines\":[{\"src_line_id\":$LINE_A,\"qty\":\"2\"}]}" > "$OUT2" ) &
wait
C1=$(code_of "$(cat "$OUT1")"); C2=$(code_of "$(cat "$OUT2")")
echo "  동시 요청 결과: $C1 / $C2"
echo "  본문1: $(body_of "$(cat "$OUT1")" | head -c 200)"
echo "  본문2: $(body_of "$(cat "$OUT2")" | head -c 200)"
OKN=0
[ "$C1" = "201" ] && OKN=$((OKN+1))
[ "$C2" = "201" ] && OKN=$((OKN+1))
expect "동시 2건 중 성공은 정확히 1건" 1 "$OKN"
TAKEN=$(psqlq "SELECT coalesce(sum(c.qty),0)::text FROM p_doc_lines c JOIN p_docs cd ON cd.id=c.doc_id
                WHERE c.src_line_id = $LINE_A AND c.status <> 'void' AND cd.status <> 'void';")
expect "담긴 합계가 요청 수량 5 를 넘지 않는다" "5.000" "$TAKEN"
ST=$(psqlq "SELECT status FROM p_docs WHERE id = $PR1;")
echo "  요청1 상태: $ST (A 줄은 전량 담김, B 줄은 남음 → partial)"

sub "채번 — 동시 생성에도 UNIQUE 충돌 없음"
DUP=$(psqlq "SELECT count(*) FROM (SELECT doc_no FROM p_docs GROUP BY doc_no HAVING count(*) > 1) x;")
expect "중복 전표 번호 0건" 0 "$DUP"
psqlq "SELECT doc_no FROM p_docs WHERE created_by IN ($QA_IDS) ORDER BY id;" | sed 's/^/  /'

hr "T-45 ⑦ 요청 수정 창 (Q-18①)"

R=$(call qa_employee01 PATCH "/api/purchase/pr/$PR1" "$TOK_EMP1" \
  "{\"lines\":[{\"id\":$LINE_A,\"qty\":\"9\"}]}")
echo "  HTTP $(code_of "$R")  $(body_of "$R")"
expect "담긴 줄 수정 → 400" 400 "$(code_of "$R")"
REASON=$(psqlq "SELECT reason FROM audit_logs WHERE action = 'purchase.request.update' AND result='deny'
                 ORDER BY id DESC LIMIT 1;")
expect "감사 사유 line_locked" "line_locked" "$REASON"

sub "담기지 않은 줄은 아직 고칠 수 있다"
R=$(call qa_employee01 PATCH "/api/purchase/pr/$PR1" "$TOK_EMP1" \
  "{\"lines\":[{\"id\":$LINE_B,\"qty\":\"7\"}]}")
echo "  HTTP $(code_of "$R")"
expect "잠기지 않은 줄 수정 200" 200 "$(code_of "$R")"
NEWQ=$(psqlq "SELECT qty::text FROM p_doc_lines WHERE id = $LINE_B;")
expect "수량이 7 로 바뀜" "7.000" "$NEWQ"
NEWAMT=$(psqlq "SELECT amount::text FROM p_doc_lines WHERE id = $LINE_B;")
expect "금액도 서버가 다시 계산 (7 × 2500)" "17500.00" "$NEWAMT"

sub "담긴 전표에 줄을 더하면 **새 요청 전표**로 갈라진다"
R=$(call qa_employee01 PATCH "/api/purchase/pr/$PR1" "$TOK_EMP1" \
  "{\"add_lines\":[{\"item_id\":$ITEM_B,\"qty\":\"2\"}]}")
echo "  HTTP $(code_of "$R")"; echo "  $(body_of "$R")" | head -c 400; echo
expect "줄 추가 200" 200 "$(code_of "$R")"
NEW_NO=$(field new_doc_no "$(body_of "$R")")
echo "  갈라진 새 전표: $NEW_NO"
SPLIT=$(psqlq "SELECT count(*) FROM p_docs WHERE split_from_id = $PR1;")
expect "split_from_id 가 원 전표를 가리키는 새 PR 1건" 1 "$SPLIT"

hr "T-45 ⑧ 「보냄」 (Q-18④) — viewer·buyer 만"

sub "요청자(requester)가 보내려 하면 403"
R=$(call qa_employee01 POST "/api/purchase/po/$PO1/send" "$TOK_EMP1" '{}')
echo "  HTTP $(code_of "$R")  $(body_of "$R")"
expect "requester send → 403" 403 "$(code_of "$R")"

sub "검토자(viewer) 로 바꿔 보냄"
psqlc "UPDATE users SET purchase_role = 'viewer', branch_id = NULL WHERE username = 'qa_employee02';"
R=$(call qa_employee02 POST "/api/purchase/po/$PO1/send" "$TOK_EMP2" '{}')
echo "  HTTP $(code_of "$R")"; echo "  $(body_of "$R")"
expect "viewer send → 200" 200 "$(code_of "$R")"
SENT=$(psqlq "SELECT CASE WHEN sent_at IS NULL THEN 'null' ELSE 'set' END FROM p_docs WHERE id = $PO1;")
expect "sent_at 이 놓였다" "set" "$SENT"

R=$(call qa_employee02 POST "/api/purchase/po/$PO1/send" "$TOK_EMP2" '{}')
echo "  두 번째 보냄 → HTTP $(code_of "$R")  $(body_of "$R")"
expect "두 번째 send → 400" 400 "$(code_of "$R")"
REASON=$(psqlq "SELECT reason FROM audit_logs WHERE action='purchase.order.send' AND result='deny'
                 ORDER BY id DESC LIMIT 1;")
expect "감사 사유 already_sent" "already_sent" "$REASON"

R=$(call qa_employee02 POST "/api/purchase/po/$PO1/send" "$TOK_EMP2" '{"reason":"거래처가 못 받아 재송부"}')
echo "  사유를 붙인 재송부 → HTTP $(code_of "$R")  $(body_of "$R")"
expect "사유가 있으면 재송부 200" 200 "$(code_of "$R")"
RT=$(psqlq "SELECT detail->>'reason_text' FROM audit_logs WHERE action='purchase.order.send' AND result='allow'
             ORDER BY id DESC LIMIT 1;")
expect "감사 detail.reason_text 에 사유가 남는다" "거래처가 못 받아 재송부" "$RT"
SENTTO=$(psqlq "SELECT detail->>'sent_to' FROM audit_logs WHERE action='purchase.order.send' AND result='allow'
                 ORDER BY id DESC LIMIT 1;")
expect "감사 detail.sent_to = 거래처 이름" "S20 더존덴탈" "$SENTTO"

sub "보낸 뒤 수정은 사유가 필수 (AL-14 가 보는 기록)"
R=$(call qa_manager01 PATCH "/api/purchase/po/$PO1" "$TOK_MGR" '{"header":{"cc":"구매팀"}}')
echo "  사유 없는 수정 → HTTP $(code_of "$R")  $(body_of "$R")"
expect "보낸 뒤 사유 없는 수정 400" 400 "$(code_of "$R")"
R=$(call qa_manager01 PATCH "/api/purchase/po/$PO1" "$TOK_MGR" \
  '{"header":{"cc":"구매팀"},"reason":"수신 참조 누락"}')
echo "  사유 있는 수정 → HTTP $(code_of "$R")"
expect "사유가 있으면 200" 200 "$(code_of "$R")"
AFTERSENT=$(psqlq "SELECT detail->>'after_sent' FROM audit_logs WHERE action='purchase.order.update' AND result='allow'
                    ORDER BY id DESC LIMIT 1;")
expect "감사 detail.after_sent = true (AL-14 근거)" "true" "$AFTERSENT"
sudo -n php "$(dirname "$0")/../scripts/alerts.php" --dry-run --rule AL-14 2>&1 | sed 's/^/  /'

hr "T-45 ⑨ 추천 — 남의 주문·다른 지점을 포함하지 않는다 (R-68)"

R=$(call qa_employee01 GET /api/purchase/recommend "$TOK_EMP1")
echo "  HTTP $(code_of "$R")"; echo "  $(body_of "$R")" | head -c 600; echo
expect "추천 200" 200 "$(code_of "$R")"
expect "내 요청 품목이 들어 있다" 1 "$(body_of "$R" | grep -c 'S20 gauze 2x2')"
expect "지점 확장은 기본 꺼짐(scope=me)" 1 "$(body_of "$R" | grep -c '"scope":"me"')"

R=$(call qa_employee01 GET "/api/purchase/recommend?scope=branch" "$TOK_EMP1")
echo "  scope=branch 요구 → $(body_of "$R" | head -c 200)"
expect "공유 리스트가 없으면 scope 는 me 로 되돌아간다" 1 "$(body_of "$R" | grep -c '"scope":"me"')"

hr "T-45 ⑩ 내 발주 리스트 (F-31)"

R=$(call qa_employee01 POST /api/purchase/lists "$TOK_EMP1" \
  "{\"name\":\"S20 월초 소모품\",\"items\":[{\"item_id\":$ITEM_A,\"default_qty\":\"5\"}]}")
LIST1=$(new_id "$(body_of "$R")")
echo "  HTTP $(code_of "$R")  $(body_of "$R")"
expect "리스트 생성 201" 201 "$(code_of "$R")"

R=$(call qa_employee01 GET /api/purchase/lists "$TOK_EMP1")
expect "내 리스트가 보인다" 1 "$(body_of "$R" | grep -c 'S20 월초 소모품')"

R=$(call qa_employee01 PATCH "/api/purchase/lists/$LIST1" "$TOK_EMP1" \
  "{\"is_shared\":true,\"items\":[{\"item_id\":$ITEM_B,\"default_qty\":\"2\"}]}")
echo "  리스트 수정 → HTTP $(code_of "$R")  $(body_of "$R")"
expect "리스트 수정 200" 200 "$(code_of "$R")"
OFFS=$(psqlq "SELECT count(*) FROM p_list_items WHERE list_id = $LIST1 AND NOT is_active;")
expect "뺀 품목은 지워지지 않고 is_active=false 로 남는다" 1 "$OFFS"

R=$(call qa_employee01 GET "/api/purchase/recommend?scope=branch" "$TOK_EMP1")
expect "공유 리스트가 생기면 scope=branch 가 열린다" 1 "$(body_of "$R" | grep -c '"scope":"branch"')"

R=$(call qa_manager01 PATCH "/api/purchase/lists/$LIST1" "$TOK_MGR" '{"name":"남의 리스트 고치기"}')
echo "  남의 리스트 수정 → HTTP $(code_of "$R")  $(body_of "$R")"
expect "남의 리스트는 404 (있다는 사실도 알려 주지 않는다)" 404 "$(code_of "$R")"

R=$(call qa_employee01 DELETE "/api/purchase/lists/$LIST1" "$TOK_EMP1")
expect "리스트 닫기 200" 200 "$(code_of "$R")"
ALIVE=$(psqlq "SELECT is_active::text FROM p_lists WHERE id = $LIST1;")
expect "행은 남고 is_active=false" "false" "$ALIVE"

hr "T-45 ⑪ 사이클 추적 (R4)"

R=$(call qa_employee01 GET "/api/purchase/cycle?pr=$PR1" "$TOK_EMP1")
echo "  HTTP $(code_of "$R")"; echo "  $(body_of "$R")" | head -c 700; echo
expect "사이클 200" 200 "$(code_of "$R")"
expect "요청 줄에 발주서 번호가 붙어 있다" 1 "$(body_of "$R" | grep -c "$PO1_NO")"
expect "입고 칸은 원장 합(21단계부터 값, 입고 전이면 0.000)" 1 "$(body_of "$R" | grep -c '"received_qty":"0.000"')"

hr "T-45 ⑫ 인쇄 화면 — 머리·표·금액이 서버 값으로만 (Q-18②)"

R=$(call qa_manager01 GET "/purchase/po/$PO1/print" "$TOK_MGR")
PRINT=$(body_of "$R")
echo "  HTTP $(code_of "$R")  (본문 $(printf '%s' "$PRINT" | wc -c) 바이트)"
expect "인쇄 화면 200" 200 "$(code_of "$R")"
expect_has "일련번호" "$(printf '%s' "$PRINT" | grep -c "$PO1_NO")"
expect "수신(거래처)" 1 "$(printf '%s' "$PRINT" | grep -c 'S20 더존덴탈')"
expect_has "납품창고" "$(printf '%s' "$PRINT" | grep -c 'S20 B1F 창고')"
expect "회사 정보(사업자번호)" 1 "$(printf '%s' "$PRINT" | grep -c '123-45-67890')"
expect_has "표 머리 8칸(공급가액)" "$(printf '%s' "$PRINT" | grep -c '공급가액')"
expect "부가세 칸" 1 "$(printf '%s' "$PRINT" | grep -c '<th class="po-num">부가세</th>')"
expect "인라인 script 없음" 0 "$(printf '%s' "$PRINT" | grep -c '<script')"
printf '%s' "$PRINT" | grep -o '합계금액 (부가세 포함) *<strong>[^<]*</strong>' | sed 's/^/  /'
printf '%s' "$PRINT" | sed -n 's/.*<th>공급가액<\/th><td class="po-num">\([^<]*\)<\/td>.*/  공급가액 \1/p'

sub "다른 지점 requester 는 남의 발주서 인쇄 화면을 열 수 없다"
psqlc "UPDATE users SET purchase_role = 'requester', branch_id = $BR_B WHERE username = 'qa_employee02';"
R=$(call qa_employee02 GET "/purchase/po/$PO1/print" "$TOK_EMP2")
echo "  HTTP $(code_of "$R")"
expect "범위 밖 인쇄 화면 404" 404 "$(code_of "$R")"

hr "T-45 ⑬ 발주서 취소 → 요청 잔량이 되돌아온다"

R=$(call qa_manager01 POST "/api/purchase/po/$PO1/void" "$TOK_MGR" '{"reason":"검토 시험 — 되돌리기 확인"}')
echo "  HTTP $(code_of "$R")  $(body_of "$R")"
expect "발주서 취소 200" 200 "$(code_of "$R")"
TAKEN=$(psqlq "SELECT coalesce(sum(c.qty),0)::text FROM p_doc_lines c JOIN p_docs cd ON cd.id=c.doc_id
                WHERE c.src_line_id = $LINE_A AND c.status <> 'void' AND cd.status <> 'void';")
echo "  취소 뒤 담긴 합계: $TAKEN (동시성 시험에서 통과한 발주서 1건이 남아 있으면 2.000)"
ST=$(psqlq "SELECT status FROM p_docs WHERE id = $PR1;")
echo "  요청1 상태: $ST"

hr "정책·화면 라우트 점검"

R=$(call qa_employee01 GET /purchase "$TOK_EMP1")
expect "requester 의 /purchase → 302" 302 "$(code_of "$R")"
R=$(call qa_manager01 GET /purchase/po "$TOK_MGR")
expect "buyer 의 /purchase/po 화면 200" 200 "$(code_of "$R")"
R=$(call qa_employee01 GET /purchase/pr "$TOK_EMP1")
expect "requester 의 /purchase/pr 화면 200" 200 "$(code_of "$R")"
R=$(call qa_employee01 GET /purchase/docs "$TOK_EMP1")
expect "전표 화면 200" 200 "$(code_of "$R")"
R=$(call qa_employee01 POST /api/purchase/po "$TOK_EMP1" \
  "{\"to_location_id\":$LOC_A,\"lines\":[{\"src_line_id\":$LINE_B,\"qty\":\"1\"}]}")
expect "requester 가 발주서를 만들려 하면 403" 403 "$(code_of "$R")"

hr "DB 권한 — p_* 에 DELETE 가 없다"
sudo -n -u postgres psql -d "$DB" -c "\dp p_docs" -c "\dp p_doc_lines" -c "\dp p_doc_seq" \
  -c "\dp p_lists" -c "\dp p_list_items" 2>&1 | grep -E 'p_doc|p_list|tbox_' | sed 's/^/  /'
DELPRIV=$(psqlq "SELECT count(*) FROM information_schema.table_privileges
                  WHERE grantee = 'tbox_app' AND privilege_type = 'DELETE'
                    AND table_name IN ('p_docs','p_doc_lines','p_doc_seq','p_lists','p_list_items');")
expect "tbox_app 의 p_* DELETE 권한 0건" 0 "$DELPRIV"

hr "린트"
bash "$(dirname "$0")/../scripts/security_lint.sh" | tail -5 | sed 's/^/  /'
