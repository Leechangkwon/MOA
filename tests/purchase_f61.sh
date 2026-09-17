#!/usr/bin/env bash
# tests/purchase_f61.sh — F-61 수락 기준 실행 (PRD 12.2 T-73)
#   발주서 화면 완성 — 「현황」 탭 · 입고 장소 자동(거래처×장소 분할) · 세 갈래 입력
#   (PRD F-61 · F-60 · F-36 · F-34 · S-01 · ARCH 5.18 「발주서 세 갈래·장소 규칙」
#    · SECURITY R-65 · R-66 · R-73 · R-78)
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
#   ⑥ (d) 가 만든 재고는 원장이 append-only 라 지울 수 없다 — **반대 전표(실사 0)** 로
#      0 으로 되돌린 뒤, 시험이 만든 원장 행(qa 소유)만 정리에서 지운다.
#
# 사용: sudo -v && bash tests/purchase_f61.sh
set -u

BASE="http://127.0.0.1:8082"
UA="tbox-review/$(date +%F)"
DB="tbox_re"
JAR_DIR="$(mktemp -d)"
# 화면 시험의 부산물(스크린샷·JSON)을 두는 곳. F61_OUT 으로 밖에 남길 수 있다.
OUT="${F61_OUT:-$JAR_DIR/out}"; mkdir -p "$OUT"
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

# 마지막 거부 사유 (감사 로그)
deny_reason() {
  psqlq "SELECT reason FROM audit_logs WHERE action='purchase.order.create' AND result='deny'
          ORDER BY id DESC LIMIT 1;"
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
  for t in p_brands p_branches p_locations p_vendors p_categories p_items p_docs p_par_levels p_stock_moves; do
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

# (d) 가 만든 재고를 **반대 전표(실사 0)** 로 0 으로 되돌린다.
# 원장은 append-only 라 「지우는」 길이 앱에 없다 — 창구로 되돌리는 것이 유일한 정상 경로다.
unstock() {
  [ -n "${TOK_MGR:-}" ] || return 0
  [ -n "${LOC_X:-}" ] || return 0
  [ -n "${ITEM_S:-}" ] || return 0
  local bal c
  bal=$(psqlq "SELECT coalesce(sum(qty_delta),0)::text FROM p_stock_moves
                WHERE location_id = $LOC_X AND item_id = $ITEM_S;")
  [ "${bal:-0}" = "0.000" ] && return 0
  c=$(code_of "$(call qa_manager01 POST /api/purchase/adj "$TOK_MGR" \
      "{\"location_id\":$LOC_X,\"count_cycle\":\"daily\",\"memo\":\"F-61 검토 시험 되돌리기\",\"lines\":[{\"item_id\":$ITEM_S,\"book_qty\":\"$bal\",\"counted_qty\":\"0\"}]}")")
  printf '  재고 되돌리기(실사 %s → 0) → HTTP %s  ※ 원장은 append-only 라 반대 부호 행이 남는다\n' "$bal" "$c"
}

# 시험이 만든 전표를 **창구로** 취소한다
void_made() {
  local id c
  [ -n "${TOK_MGR:-}" ] || return 0
  for id in $(psqlq "SELECT id FROM p_docs
                      WHERE created_by IN ($QA_IDS) AND doc_type IN ('po','adj') AND status <> 'void'
                      ORDER BY id DESC;"); do
    local t
    t=$(psqlq "SELECT doc_type FROM p_docs WHERE id = $id;")
    if [ "$t" = "po" ]; then
      c=$(code_of "$(call qa_manager01 POST "/api/purchase/po/$id/void" "$TOK_MGR" '{"reason":"F-61 검토 시험 정리"}')")
    else
      c=$(code_of "$(call qa_manager01 POST "/api/purchase/adj/$id/void" "$TOK_MGR" '{"reason":"F-61 검토 시험 정리"}')")
    fi
    printf '  %s %s 취소 → HTTP %s\n' "$t" "$id" "$c"
  done
  [ -n "${TOK_EMP1:-}" ] || return 0
  for id in $(psqlq "SELECT id FROM p_docs
                      WHERE created_by IN ($QA_IDS) AND doc_type = 'pr' AND status <> 'void'
                      ORDER BY id DESC;"); do
    c=$(code_of "$(call qa_employee01 POST "/api/purchase/pr/$id/void" "$TOK_EMP1" '{"reason":"F-61 검토 시험 정리"}')")
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

  sub "① 재고 되돌리기(반대 전표) · 창구로 취소 (void)"
  unstock
  void_made

  psqlq "SELECT '  사용자(비-qa)가 만든 행: p_docs='||
           (SELECT count(*) FROM p_docs WHERE created_by NOT IN ($qa_ids))||
         ' p_items='||(SELECT count(*) FROM p_items WHERE created_by NOT IN ($qa_ids))||
         ' p_stock_moves='||(SELECT count(*) FROM p_stock_moves WHERE created_by NOT IN ($qa_ids))||
         ' — 이 행들은 그대로 둡니다';"

  psqlc "UPDATE users SET branch_id = NULL, purchase_role = NULL
          WHERE username IN ('qa_employee01','qa_employee02','qa_manager01');" \
    && echo "  qa 계정 branch_id·purchase_role 원복"

  # ★ 원장은 reverses_move_id 자기 참조가 있으므로 **되돌림 행을 먼저** 지운다 (21단계와 같은 순서).
  psqlc "DELETE FROM p_stock_moves
          WHERE reverses_move_id IS NOT NULL AND created_by IN ($qa_ids);
         DELETE FROM p_stock_moves WHERE created_by IN ($qa_ids);
         DELETE FROM p_doc_lines WHERE doc_id IN (SELECT id FROM p_docs WHERE created_by IN ($qa_ids));
         DELETE FROM p_docs      WHERE created_by IN ($qa_ids);
         -- R-78: 채번 카운터는 qa 의 것이 아니다 — 지우지 않고 「남은 전표의 최대 번호」로 되돌린다
         UPDATE p_doc_seq s SET last_no = COALESCE((SELECT max(split_part(d.doc_no,'-',3)::int) FROM p_docs d
                                                    WHERE d.doc_date = CURRENT_DATE AND d.doc_type = s.doc_type), 0)
          WHERE s.seq_date = CURRENT_DATE;
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
TOK_MGR=$(login qa_manager01)   || exit 1
echo "  로그인 3계정 완료"
echo "  재인증(기초정보 편집 창 열기): qa_admin01 → $(reauth qa_admin01 "$TOK_ADMIN")"

hr "1. 기초정보 씨앗 — 브랜드·지점 2·장소 3·거래처 2·품목 6"

R=$(call qa_admin01 POST /api/purchase/brands "$TOK_ADMIN" \
  '{"name":"F61 치과","biz_no":"123-45-67891","ceo_name":"검토 대표","address":"인천 부평구","tel":"032-000-0000"}')
BRAND=$(new_id "$(body_of "$R")"); expect "브랜드 생성" 201 "$(code_of "$R")"

R=$(call qa_admin01 POST /api/purchase/branches "$TOK_ADMIN" "{\"brand_id\":$BRAND,\"name\":\"F61 부평점\"}")
BR_A=$(new_id "$(body_of "$R")"); expect "지점A 생성" 201 "$(code_of "$R")"
R=$(call qa_admin01 POST /api/purchase/branches "$TOK_ADMIN" "{\"brand_id\":$BRAND,\"name\":\"F61 일산점\"}")
BR_B=$(new_id "$(body_of "$R")"); expect "지점B 생성" 201 "$(code_of "$R")"

R=$(call qa_admin01 POST /api/purchase/locations "$TOK_ADMIN" \
  "{\"branch_id\":$BR_A,\"kind\":\"warehouse\",\"name\":\"F61 중앙창고\"}")
LOC_X=$(new_id "$(body_of "$R")"); expect "장소X(중앙창고) 생성" 201 "$(code_of "$R")"
R=$(call qa_admin01 POST /api/purchase/locations "$TOK_ADMIN" \
  "{\"branch_id\":$BR_A,\"kind\":\"or\",\"name\":\"F61 수술방\"}")
LOC_Y=$(new_id "$(body_of "$R")"); expect "장소Y(수술방) 생성" 201 "$(code_of "$R")"
R=$(call qa_admin01 POST /api/purchase/locations "$TOK_ADMIN" \
  "{\"branch_id\":$BR_B,\"kind\":\"warehouse\",\"name\":\"F61 일산창고\"}")
LOC_Z=$(new_id "$(body_of "$R")"); expect "장소Z(다른 지점) 생성" 201 "$(code_of "$R")"

R=$(call qa_admin01 POST /api/purchase/vendors "$TOK_ADMIN" '{"name":"F61 거래처A","code":"F61VA"}')
VEND_A=$(new_id "$(body_of "$R")"); expect "거래처A 생성" 201 "$(code_of "$R")"
R=$(call qa_admin01 POST /api/purchase/vendors "$TOK_ADMIN" '{"name":"F61 거래처B","code":"F61VB"}')
VEND_B=$(new_id "$(body_of "$R")"); expect "거래처B 생성" 201 "$(code_of "$R")"

# 품목 하나. 명령 치환 안에서 돌므로 여기서 세지 않는다(하위 셸이라 카운터가 돌아오지 않는다)
# — 만들어진 id 만 돌려주고, 결과는 바로 아래에서 expect 로 한 번에 센다.
mkitem() { # $1 vendor  $2 code  $3 name  $4 price
  local r
  r=$(call qa_admin01 POST /api/purchase/items "$TOK_ADMIN" \
    "{\"vendor_id\":$1,\"code\":\"$2\",\"name\":\"$3\",\"unit\":\"EA\",\"price_in\":\"$4\"}")
  printf '  품목 %s → HTTP %s\n' "$2" "$(code_of "$r")" >&2
  new_id "$(body_of "$r")"
}
ITEM_A1=$(mkitem "$VEND_A" F61-A1 "F61 gauze"   1000)
ITEM_A2=$(mkitem "$VEND_A" F61-A2 "F61 cotton"  2000)
ITEM_B1=$(mkitem "$VEND_B" F61-B1 "F61 needle"  3000)
ITEM_D=$(mkitem  "$VEND_A" F61-D1 "F61 direct"  1500)
ITEM_OFF=$(mkitem "$VEND_A" F61-OFF "F61 stopped" 900)
ITEM_S=$(mkitem  "$VEND_A" F61S-1 "F61S suggest" 500)
echo "  품목: A1=$ITEM_A1 A2=$ITEM_A2 B1=$ITEM_B1 D=$ITEM_D OFF=$ITEM_OFF S=$ITEM_S"
expect "품목 6개가 모두 만들어졌다" 6 \
  "$(psqlq "SELECT count(*) FROM p_items WHERE id IN ($ITEM_A1,$ITEM_A2,$ITEM_B1,$ITEM_D,$ITEM_OFF,$ITEM_S);")"

sub "검토용 모듈 역할·지점 심기 (psql, 끝에서 원복)"
psqlc "UPDATE users SET branch_id = $BR_A, purchase_role = 'requester' WHERE username = 'qa_employee01';"
psqlc "UPDATE users SET branch_id = NULL,  purchase_role = 'buyer'     WHERE username = 'qa_manager01';"
psqlq "SELECT username||' branch='||coalesce(branch_id::text,'-')||' role='||coalesce(purchase_role,'-')
         FROM users WHERE username LIKE 'qa%' ORDER BY id;" | sed 's/^/  /'

# ═════════════════════════════════════════════════════════════
hr "T-73 (a) 현황 — 입고 화면과 **같은 창구** GET /api/purchase/backorder"
# ═════════════════════════════════════════════════════════════

R=$(call qa_manager01 GET /api/purchase/backorder "$TOK_MGR")
B=$(body_of "$R")
echo "  HTTP $(code_of "$R")"; echo "  $B" | head -c 400; echo
expect "buyer 의 미입고 현황 200" 200 "$(code_of "$R")"
expect "groups 배열이 있다" 1 "$(jq_py "$B" "int(isinstance(d.get('groups'), list))")"
for k in doc_count line_count overdue amount; do
  expect "응답에 $k 가 있다" 1 "$(jq_py "$B" "int('$k' in d)")"
done
expect "발주서 화면이 새 창구를 부르지 않는다(백오더 API 하나)" 1 \
  "$(grep -c "api/purchase/backorder" "$(dirname "$0")/../public/assets/purchase_common.js")"

# ═════════════════════════════════════════════════════════════
hr "T-73 (b) 장소 자동 · (거래처 × 입고 장소) 분할"
# ═════════════════════════════════════════════════════════════

sub "요청 3건 — 장소X(거래처A 2줄) · 장소Y(거래처A 1줄 + 거래처B 1줄)"
R=$(call qa_employee01 POST /api/purchase/pr "$TOK_EMP1" \
  "{\"to_location_id\":$LOC_X,\"memo\":\"F61 요청X\",\"lines\":[{\"item_id\":$ITEM_A1,\"qty\":\"5\"},{\"item_id\":$ITEM_A2,\"qty\":\"3\"}]}")
PRX=$(new_id "$(body_of "$R")"); expect "요청X(장소X·거래처A 2줄) 생성" 201 "$(code_of "$R")"
R=$(call qa_employee01 POST /api/purchase/pr "$TOK_EMP1" \
  "{\"to_location_id\":$LOC_Y,\"memo\":\"F61 요청Y\",\"lines\":[{\"item_id\":$ITEM_A1,\"qty\":\"2\"},{\"item_id\":$ITEM_B1,\"qty\":\"4\"}]}")
PRY=$(new_id "$(body_of "$R")"); expect "요청Y(장소Y·거래처A+B) 생성" 201 "$(code_of "$R")"

LX1=$(psqlq "SELECT id FROM p_doc_lines WHERE doc_id = $PRX AND item_id = $ITEM_A1;")
LX2=$(psqlq "SELECT id FROM p_doc_lines WHERE doc_id = $PRX AND item_id = $ITEM_A2;")
LY1=$(psqlq "SELECT id FROM p_doc_lines WHERE doc_id = $PRY AND item_id = $ITEM_A1;")
LY2=$(psqlq "SELECT id FROM p_doc_lines WHERE doc_id = $PRY AND item_id = $ITEM_B1;")
echo "  요청 줄: X1=$LX1 X2=$LX2 (장소X) · Y1=$LY1 Y2=$LY2 (장소Y)"

sub "머리 장소 **없이** split:true → 3장 (A·X, A·Y, B·Y)"
AUD0=$(psqlq "SELECT count(*) FROM audit_logs WHERE action='purchase.order.create' AND result='allow';")
R=$(call qa_manager01 POST /api/purchase/po "$TOK_MGR" \
  "{\"split\":true,\"lines\":[{\"src_line_id\":$LX1,\"qty\":\"5\"},{\"src_line_id\":$LX2,\"qty\":\"3\"},{\"src_line_id\":$LY1,\"qty\":\"2\"},{\"src_line_id\":$LY2,\"qty\":\"4\"}]}")
B=$(body_of "$R")
echo "  HTTP $(code_of "$R")"; echo "  $B"; echo
expect "머리 장소 없이 201" 201 "$(code_of "$R")"
expect "count = 3" 3 "$(jq_py "$B" "d['count']")"
expect "묶음이 (거래처,장소) 3가지" 3 \
  "$(jq_py "$B" "len(set((x['vendor_id'], x['to_location_id']) for x in d['docs']))")"
expect "거래처A·장소X 장의 줄 수 2" 2 \
  "$(jq_py "$B" "[x['line_count'] for x in d['docs'] if x['vendor_id']==$VEND_A and x['to_location_id']==$LOC_X][0]")"
expect "거래처A·장소Y 장의 줄 수 1" 1 \
  "$(jq_py "$B" "[x['line_count'] for x in d['docs'] if x['vendor_id']==$VEND_A and x['to_location_id']==$LOC_Y][0]")"
expect "거래처B·장소Y 장의 줄 수 1" 1 \
  "$(jq_py "$B" "[x['line_count'] for x in d['docs'] if x['vendor_id']==$VEND_B and x['to_location_id']==$LOC_Y][0]")"
expect "전표 번호 셋이 서로 다르다" 3 "$(jq_py "$B" "len(set(x['doc_no'] for x in d['docs']))")"

sub "DB — 각 장의 to_location_id 가 **요청서의 창고**와 같다"
for D in $(jq_py "$B" "' '.join(str(x['id']) for x in d['docs'])"); do
  psqlq "SELECT '  발주서 '||doc_no||' → 장소 '||to_location_id||' 지점 '||branch_id FROM p_docs WHERE id = $D;"
done
expect "장소X 발주서의 줄이 전부 요청X 에서 왔다" 2 \
  "$(psqlq "SELECT count(*) FROM p_doc_lines c JOIN p_docs d ON d.id=c.doc_id
             WHERE d.doc_type='po' AND d.to_location_id=$LOC_X AND c.src_line_id IN ($LX1,$LX2);")"
expect "장소Y 발주서의 줄이 전부 요청Y 에서 왔다" 2 \
  "$(psqlq "SELECT count(*) FROM p_doc_lines c JOIN p_docs d ON d.id=c.doc_id
             WHERE d.doc_type='po' AND d.to_location_id=$LOC_Y AND c.src_line_id IN ($LY1,$LY2);")"
expect "감사 allow 가 3행 늘었다" 3 \
  "$(( $(psqlq "SELECT count(*) FROM audit_logs WHERE action='purchase.order.create' AND result='allow';") - AUD0 ))"
expect "세 행 모두 split_count=3" 3 \
  "$(psqlq "SELECT count(*) FROM (SELECT detail FROM audit_logs
             WHERE action='purchase.order.create' AND result='allow'
             ORDER BY id DESC LIMIT 3) x WHERE x.detail::jsonb->>'split_count' = '3';")"

sub "같은 모양을 머리 장소 X 로 **명시**하면 → 2장 (A·X, B·X)"
R=$(call qa_employee01 POST /api/purchase/pr "$TOK_EMP1" \
  "{\"to_location_id\":$LOC_X,\"memo\":\"F61 요청X2\",\"lines\":[{\"item_id\":$ITEM_A1,\"qty\":\"5\"}]}")
PRX2=$(new_id "$(body_of "$R")")
R=$(call qa_employee01 POST /api/purchase/pr "$TOK_EMP1" \
  "{\"to_location_id\":$LOC_Y,\"memo\":\"F61 요청Y2\",\"lines\":[{\"item_id\":$ITEM_A2,\"qty\":\"2\"},{\"item_id\":$ITEM_B1,\"qty\":\"2\"}]}")
PRY2=$(new_id "$(body_of "$R")")
M1=$(psqlq "SELECT id FROM p_doc_lines WHERE doc_id = $PRX2;")
M2=$(psqlq "SELECT id FROM p_doc_lines WHERE doc_id = $PRY2 AND item_id = $ITEM_A2;")
M3=$(psqlq "SELECT id FROM p_doc_lines WHERE doc_id = $PRY2 AND item_id = $ITEM_B1;")
HEADMIX="{\"src_line_id\":$M1,\"qty\":\"1\"},{\"src_line_id\":$M2,\"qty\":\"1\"},{\"src_line_id\":$M3,\"qty\":\"1\"}"

R=$(call qa_manager01 POST /api/purchase/po "$TOK_MGR" \
  "{\"to_location_id\":$LOC_X,\"split\":true,\"lines\":[$HEADMIX]}")
B=$(body_of "$R")
echo "  HTTP $(code_of "$R")"; echo "  $B" | head -c 600; echo
expect "머리 장소 명시 → 201" 201 "$(code_of "$R")"
expect "머리 명시가 우선 — 2장(거래처별)" 2 "$(jq_py "$B" "d['count']")"
expect "두 장 모두 장소 X" 1 \
  "$(jq_py "$B" "int(all(x['to_location_id']==$LOC_X for x in d['docs']))")"

sub "장소를 정할 수 없는 줄 — 요청에 장소가 없고 머리도 없음 → 400"
R=$(call qa_employee01 POST /api/purchase/pr "$TOK_EMP1" \
  "{\"memo\":\"F61 장소없는 요청\",\"lines\":[{\"item_id\":$ITEM_A1,\"qty\":\"2\"}]}")
PRN=$(new_id "$(body_of "$R")"); expect "장소 없는 요청 생성 201" 201 "$(code_of "$R")"
expect "그 요청의 to_location_id 가 NULL" "" "$(psqlq "SELECT coalesce(to_location_id::text,'') FROM p_docs WHERE id=$PRN;")"
LN=$(psqlq "SELECT id FROM p_doc_lines WHERE doc_id = $PRN;")
R=$(call qa_manager01 POST /api/purchase/po "$TOK_MGR" \
  "{\"split\":true,\"lines\":[{\"src_line_id\":$LN,\"qty\":\"1\"}]}")
echo "  HTTP $(code_of "$R")  $(body_of "$R")"
expect "장소를 정할 수 없으면 400" 400 "$(code_of "$R")"
expect "감사 사유 bad_request" "bad_request" "$(deny_reason)"
R=$(call qa_manager01 POST /api/purchase/po "$TOK_MGR" \
  "{\"to_location_id\":$LOC_X,\"split\":true,\"lines\":[{\"src_line_id\":$LN,\"qty\":\"1\"}]}")
expect "머리 장소를 주면 같은 줄이 201" 201 "$(code_of "$R")"

sub "split 없이 머리 장소 없음 → 400 (옛 계약 불변)"
R=$(call qa_employee01 POST /api/purchase/pr "$TOK_EMP1" \
  "{\"to_location_id\":$LOC_X,\"memo\":\"F61 옛계약\",\"lines\":[{\"item_id\":$ITEM_A1,\"qty\":\"2\"}]}")
PRO=$(new_id "$(body_of "$R")")
LO=$(psqlq "SELECT id FROM p_doc_lines WHERE doc_id = $PRO;")
R=$(call qa_manager01 POST /api/purchase/po "$TOK_MGR" "{\"lines\":[{\"src_line_id\":$LO,\"qty\":\"1\"}]}")
echo "  HTTP $(code_of "$R")  $(body_of "$R")"
expect "split 없이 머리 장소를 빼면 400" 400 "$(code_of "$R")"
expect "감사 사유 bad_request" "bad_request" "$(deny_reason)"
R=$(call qa_manager01 POST /api/purchase/po "$TOK_MGR" \
  "{\"to_location_id\":$LOC_X,\"lines\":[{\"src_line_id\":$LO,\"qty\":\"1\"}]}")
B=$(body_of "$R")
expect "split 없이 머리 장소를 주면 201 (옛 응답 모양)" 201 "$(code_of "$R")"
expect "옛 응답에 doc_no 가 있다" 1 "$(jq_py "$B" "int('doc_no' in d)")"
expect "옛 응답에 docs 가 없다" 0 "$(jq_py "$B" "int('docs' in d)")"

sub "범위 밖 장소 → 400 not_in_scope (중단된 장소로 확인)"
sub "  ※ buyer 는 Authz 상 **전 지점**(purchaseScopeWhere = 1=1)이고 POST /api/purchase/po 는"
sub "    buyer 전용이라, 「다른 지점 장소」 로는 범위 밖을 만들 수 없다 — 같은 조건(locationBranch"
sub "    가 빈손)을 만드는 **중단된 장소**로 그 갈래를 확인한다."
R=$(call qa_admin01 PATCH "/api/purchase/locations/$LOC_Z" "$TOK_ADMIN" '{"is_active":false}')
expect "장소Z 중단 200" 200 "$(code_of "$R")"
R=$(call qa_employee01 POST /api/purchase/pr "$TOK_EMP1" \
  "{\"to_location_id\":$LOC_X,\"memo\":\"F61 범위밖\",\"lines\":[{\"item_id\":$ITEM_A1,\"qty\":\"2\"}]}")
PRS=$(new_id "$(body_of "$R")")
LS=$(psqlq "SELECT id FROM p_doc_lines WHERE doc_id = $PRS;")
R=$(call qa_manager01 POST /api/purchase/po "$TOK_MGR" \
  "{\"to_location_id\":$LOC_Z,\"split\":true,\"lines\":[{\"src_line_id\":$LS,\"qty\":\"1\"}]}")
echo "  HTTP $(code_of "$R")  $(body_of "$R")"
expect "split + 볼 수 없는 장소 → 400" 400 "$(code_of "$R")"
expect "감사 사유 not_in_scope" "not_in_scope" "$(deny_reason)"
R=$(call qa_manager01 POST /api/purchase/po "$TOK_MGR" \
  "{\"to_location_id\":$LOC_Z,\"lines\":[{\"src_line_id\":$LS,\"qty\":\"1\"}]}")
expect "split 없는 같은 요청은 옛 사유 그대로(400)" 400 "$(code_of "$R")"
expect "옛 경로의 감사 사유는 bad_reference (불변)" "bad_reference" "$(deny_reason)"

# ═════════════════════════════════════════════════════════════
hr "T-73 (c) 직접 입력 — item_id · src_line_id 와 정확히 하나"
# ═════════════════════════════════════════════════════════════

R=$(call qa_manager01 POST /api/purchase/po "$TOK_MGR" \
  "{\"split\":true,\"lines\":[{\"item_id\":$ITEM_D,\"qty\":\"3\",\"location_id\":$LOC_X}]}")
B=$(body_of "$R")
echo "  HTTP $(code_of "$R")"; echo "  $B" | head -c 600; echo
expect "직접 줄 저장 201" 201 "$(code_of "$R")"
expect "docs 1장" 1 "$(jq_py "$B" "d['count']")"
expect "그 장의 거래처 = **품목의** 거래처 A" "$VEND_A" "$(jq_py "$B" "d['docs'][0]['vendor_id']")"
expect "그 장의 장소 = 줄의 location_id" "$LOC_X" "$(jq_py "$B" "d['docs'][0]['to_location_id']")"
PO_D=$(jq_py "$B" "d['docs'][0]['id']")
expect "줄의 src_line_id 가 NULL" "" \
  "$(psqlq "SELECT coalesce(src_line_id::text,'') FROM p_doc_lines WHERE doc_id = $PO_D;")"
expect "단가 = 품목 price_in 1500" "1500.00" \
  "$(psqlq "SELECT unit_price::text FROM p_doc_lines WHERE doc_id = $PO_D;")"
expect "금액 = 3 × 1500" "4500.00" \
  "$(psqlq "SELECT amount::text FROM p_doc_lines WHERE doc_id = $PO_D;")"
expect "그 감사 행에 src_doc_no 가 **없다**" 0 \
  "$(psqlq "SELECT count(*) FROM (SELECT detail FROM audit_logs
             WHERE action='purchase.order.create' AND result='allow'
             ORDER BY id DESC LIMIT 1) x WHERE x.detail::jsonb ? 'src_doc_no';")"

sub "줄에 vendor_id 를 적으면 그 거래처"
R=$(call qa_manager01 POST /api/purchase/po "$TOK_MGR" \
  "{\"split\":true,\"lines\":[{\"item_id\":$ITEM_D,\"qty\":\"1\",\"location_id\":$LOC_X,\"vendor_id\":$VEND_B}]}")
B=$(body_of "$R")
expect "줄 vendor_id 명시 201" 201 "$(code_of "$R")"
expect "거래처 = 적어 준 B" "$VEND_B" "$(jq_py "$B" "d['docs'][0]['vendor_id']")"

sub "단가·금액은 요청 본문에서 받지 않는다 (R-66)"
R=$(call qa_manager01 POST /api/purchase/po "$TOK_MGR" \
  "{\"split\":true,\"lines\":[{\"item_id\":$ITEM_D,\"qty\":\"1\",\"location_id\":$LOC_X,\"unit_price\":\"1\"}]}")
echo "  HTTP $(code_of "$R")  $(body_of "$R")"
expect "본문에 unit_price 를 적으면 400" 400 "$(code_of "$R")"

sub "둘 다 · 둘 다 없음 · 없는 품목 · 비활성 품목 · 중복"
R=$(call qa_manager01 POST /api/purchase/po "$TOK_MGR" \
  "{\"to_location_id\":$LOC_X,\"split\":true,\"lines\":[{\"src_line_id\":$LS,\"item_id\":$ITEM_D,\"qty\":\"1\"}]}")
expect "src_line_id + item_id 둘 다 → 400" 400 "$(code_of "$R")"
expect "감사 사유 bad_request" "bad_request" "$(deny_reason)"
R=$(call qa_manager01 POST /api/purchase/po "$TOK_MGR" \
  "{\"to_location_id\":$LOC_X,\"split\":true,\"lines\":[{\"qty\":\"1\"}]}")
expect "둘 다 없음 → 400" 400 "$(code_of "$R")"
R=$(call qa_manager01 POST /api/purchase/po "$TOK_MGR" \
  "{\"to_location_id\":$LOC_X,\"split\":true,\"lines\":[{\"item_id\":999999999,\"qty\":\"1\"}]}")
expect "없는 품목 → 400" 400 "$(code_of "$R")"
expect "감사 사유 bad_request" "bad_request" "$(deny_reason)"
R=$(call qa_admin01 PATCH "/api/purchase/items/$ITEM_OFF" "$TOK_ADMIN" '{"is_active":false}')
expect "품목 중단 200" 200 "$(code_of "$R")"
R=$(call qa_manager01 POST /api/purchase/po "$TOK_MGR" \
  "{\"to_location_id\":$LOC_X,\"split\":true,\"lines\":[{\"item_id\":$ITEM_OFF,\"qty\":\"1\"}]}")
echo "  HTTP $(code_of "$R")  $(body_of "$R")"
expect "비활성 품목 → 400" 400 "$(code_of "$R")"
expect "감사 사유 bad_request" "bad_request" "$(deny_reason)"
R=$(call qa_manager01 POST /api/purchase/po "$TOK_MGR" \
  "{\"to_location_id\":$LOC_X,\"split\":true,\"lines\":[{\"item_id\":$ITEM_D,\"qty\":\"1\"},{\"item_id\":$ITEM_D,\"qty\":\"2\"}]}")
expect "같은 품목을 직접 줄로 두 번 → 400" 400 "$(code_of "$R")"
R=$(call qa_manager01 POST /api/purchase/po "$TOK_MGR" \
  "{\"to_location_id\":$LOC_X,\"split\":true,\"lines\":[{\"src_line_id\":$LS,\"qty\":\"1\",\"location_id\":$LOC_Y}]}")
expect "요청 줄에 location_id 를 적으면 400 (장소는 그 요청이 정한다)" 400 "$(code_of "$R")"
R=$(call qa_manager01 POST /api/purchase/po "$TOK_MGR" \
  "{\"split\":true,\"lines\":[{\"item_id\":$ITEM_D,\"qty\":\"1\"}]}")
expect "직접 줄에 장소가 없고 머리도 없으면 400" 400 "$(code_of "$R")"
expect "감사 사유 bad_request" "bad_request" "$(deny_reason)"

sub "요청 줄 + 직접 줄 섞기 — 같은 (거래처, 장소) 면 **한 장**"
R=$(call qa_employee01 POST /api/purchase/pr "$TOK_EMP1" \
  "{\"to_location_id\":$LOC_X,\"memo\":\"F61 섞기\",\"lines\":[{\"item_id\":$ITEM_A1,\"qty\":\"2\"}]}")
PRM=$(new_id "$(body_of "$R")")
LM=$(psqlq "SELECT id FROM p_doc_lines WHERE doc_id = $PRM;")
R=$(call qa_manager01 POST /api/purchase/po "$TOK_MGR" \
  "{\"split\":true,\"lines\":[{\"src_line_id\":$LM,\"qty\":\"2\"},{\"item_id\":$ITEM_D,\"qty\":\"4\",\"location_id\":$LOC_X}]}")
B=$(body_of "$R")
echo "  HTTP $(code_of "$R")"; echo "  $B" | head -c 600; echo
expect "섞어 저장 201" 201 "$(code_of "$R")"
expect "한 장이다 (거래처A·장소X)" 1 "$(jq_py "$B" "d['count']")"
expect "그 장의 줄 수 2" 2 "$(jq_py "$B" "d['docs'][0]['line_count']")"
PO_MIX=$(jq_py "$B" "d['docs'][0]['id']")
expect "요청 줄 1 · 직접 줄 1" "1 1" \
  "$(psqlq "SELECT count(*) FILTER (WHERE src_line_id IS NOT NULL)||' '||
                   count(*) FILTER (WHERE src_line_id IS NULL)
              FROM p_doc_lines WHERE doc_id = $PO_MIX;")"
expect "요청 줄의 잔량이 0 이 됐다" "0.000" \
  "$(psqlq "SELECT (l.qty - coalesce((SELECT sum(c.qty) FROM p_doc_lines c JOIN p_docs cd ON cd.id=c.doc_id
               WHERE c.src_line_id = l.id AND c.status <> 'void' AND cd.status <> 'void'),0))::text
             FROM p_doc_lines l WHERE l.id = $LM;")"

sub "그 발주서를 입고 불러오기로 보면 **직접 줄도 보인다**"
R=$(call qa_manager01 POST "/api/purchase/po/$PO_MIX/send" "$TOK_MGR" '{}')
expect "발주서 보냄 200" 200 "$(code_of "$R")"
R=$(call qa_manager01 GET "/api/purchase/gr/load?po=$PO_MIX" "$TOK_MGR")
B=$(body_of "$R")
echo "  HTTP $(code_of "$R")"; echo "  $B" | head -c 500; echo
expect "입고 불러오기 200" 200 "$(code_of "$R")"
expect "그 발주서의 줄 2개가 보인다(직접 줄 포함)" 2 \
  "$(jq_py "$B" "sum(1 for g in d['orders'] for l in g['lines'])")"
expect "직접 담은 품목이 그 안에 있다" 1 \
  "$(jq_py "$B" "int(any(l['item_id']==$ITEM_D for g in d['orders'] for l in g['lines']))")"

# ═════════════════════════════════════════════════════════════
hr "T-73 (d) 부족 산출 — 인가 10 · 재고 5 · 미입고 3 → 제안 2"
# ═════════════════════════════════════════════════════════════

R=$(call qa_admin01 POST /api/purchase/par-levels "$TOK_ADMIN" \
  "{\"location_id\":$LOC_X,\"item_id\":$ITEM_S,\"qty\":10}")
echo "  인가량 10 → HTTP $(code_of "$R")  $(body_of "$R")" | head -c 300; echo
expect "인가량(p_par_levels) 10 생성" 201 "$(code_of "$R")"

sub "재고 5 — 실사(adj)로 만든다 (원장은 append-only, 정리에서 반대 전표로 0 으로 되돌린다)"
R=$(call qa_manager01 POST /api/purchase/adj "$TOK_MGR" \
  "{\"location_id\":$LOC_X,\"count_cycle\":\"daily\",\"memo\":\"F61 재고 씨앗\",\"lines\":[{\"item_id\":$ITEM_S,\"book_qty\":\"0\",\"counted_qty\":\"5\"}]}")
echo "  HTTP $(code_of "$R")  $(body_of "$R")" | head -c 300; echo
expect "실사 확정 201" 201 "$(code_of "$R")"
expect "현재고 = 5" "5.000" \
  "$(psqlq "SELECT coalesce(sum(qty_delta),0)::text FROM p_stock_moves
             WHERE location_id = $LOC_X AND item_id = $ITEM_S;")"

sub "미입고 3 — 직접 줄 발주서를 만들고 **보낸다**(미입고는 보낸 것만 센다)"
R=$(call qa_manager01 POST /api/purchase/po "$TOK_MGR" \
  "{\"split\":true,\"lines\":[{\"item_id\":$ITEM_S,\"qty\":\"3\",\"location_id\":$LOC_X}]}")
B=$(body_of "$R"); PO_S1=$(jq_py "$B" "d['docs'][0]['id']")
expect "미입고용 발주서 201" 201 "$(code_of "$R")"
R=$(call qa_manager01 POST "/api/purchase/po/$PO_S1/send" "$TOK_MGR" '{}')
expect "보냄 200" 200 "$(code_of "$R")"
expect "미입고(뷰) = 3" "3.000" \
  "$(psqlq "SELECT coalesce(sum(remaining_qty),0)::text FROM p_backorder
             WHERE to_location_id = $LOC_X AND item_id = $ITEM_S;")"

R=$(call qa_manager01 GET "/api/purchase/suggest?location=$LOC_X&only_short=1&q=F61S" "$TOK_MGR")
B=$(body_of "$R")
echo "  HTTP $(code_of "$R")"; echo "  $B" | head -c 700; echo
expect "제안 조회 200" 200 "$(code_of "$R")"
expect "그 품목이 목록에 있다" 1 \
  "$(jq_py "$B" "sum(1 for x in d['items'] if x['item_id']==$ITEM_S)")"
expect "인가 10" 10 "$(jq_py "$B" "[x['par_qty'] for x in d['items'] if x['item_id']==$ITEM_S][0]")"
expect "현재고 5" "5.000" "$(jq_py "$B" "[x['on_hand'] for x in d['items'] if x['item_id']==$ITEM_S][0]")"
expect "미입고 3" "3.000" "$(jq_py "$B" "[x['incoming'] for x in d['items'] if x['item_id']==$ITEM_S][0]")"
expect "제안 = 10 − 5 − 3 = 2 (안전·조달 0)" "2.000" \
  "$(jq_py "$B" "[x['suggest_qty'] for x in d['items'] if x['item_id']==$ITEM_S][0]")"
expect "응답에 식(formula) 문장이 실린다" 1 "$(jq_py "$B" "int(len(d.get('formula','')) > 10)")"

sub "그 제안값으로 직접 줄 발주 → 201 · 다시 조회하면 미입고 5 · 제안 0"
R=$(call qa_manager01 POST /api/purchase/po "$TOK_MGR" \
  "{\"split\":true,\"lines\":[{\"item_id\":$ITEM_S,\"qty\":\"2\",\"location_id\":$LOC_X}]}")
B=$(body_of "$R"); PO_S2=$(jq_py "$B" "d['docs'][0]['id']")
expect "제안값으로 발주 201" 201 "$(code_of "$R")"
R=$(call qa_manager01 POST "/api/purchase/po/$PO_S2/send" "$TOK_MGR" '{}')
expect "보냄 200" 200 "$(code_of "$R")"
R=$(call qa_manager01 GET "/api/purchase/suggest?location=$LOC_X&only_short=1&q=F61S" "$TOK_MGR")
B=$(body_of "$R")
echo "  $B" | head -c 400; echo
expect "제안 목록(부족만)에서 빠진다" 0 \
  "$(jq_py "$B" "sum(1 for x in d['items'] if x['item_id']==$ITEM_S)")"
R=$(call qa_manager01 GET "/api/purchase/suggest?location=$LOC_X&only_short=0&q=F61S" "$TOK_MGR")
B=$(body_of "$R")
expect "미입고 5" "5.000" "$(jq_py "$B" "[x['incoming'] for x in d['items'] if x['item_id']==$ITEM_S][0]")"
expect "제안 0" "0.000" "$(jq_py "$B" "[x['suggest_qty'] for x in d['items'] if x['item_id']==$ITEM_S][0]")"

# ═════════════════════════════════════════════════════════════
hr "T-73 (e) 감사 — 발주서마다 기존 문장 · split_count · 직접 줄은 src_doc_no 없음"
# ═════════════════════════════════════════════════════════════

psqlq "SELECT '  '||target_name||' | '||detail FROM audit_logs
        WHERE action='purchase.order.create' AND result='allow' ORDER BY id DESC LIMIT 4;"
expect "감사 행의 문장이 기존 그대로(purchase.order.create)" 1 \
  "$(psqlq "SELECT count(*) FROM (SELECT action FROM audit_logs
             WHERE action='purchase.order.create' AND result='allow' ORDER BY id DESC LIMIT 1) x;")"
expect "직접 줄만인 발주서 감사에 src_doc_no 0건" 0 \
  "$(psqlq "SELECT count(*) FROM audit_logs a
             WHERE a.action='purchase.order.create' AND a.result='allow'
               AND a.target_id IN (SELECT d.id FROM p_docs d
                                    WHERE d.created_by IN ($QA_IDS) AND d.doc_type='po'
                                      AND NOT EXISTS (SELECT 1 FROM p_doc_lines l
                                                       WHERE l.doc_id = d.id AND l.src_line_id IS NOT NULL))
               AND a.detail::jsonb ? 'src_doc_no';")"
expect "요청 줄이 있는 발주서 감사에는 src_doc_no 가 있다" 1 \
  "$(psqlq "SELECT CASE WHEN count(*) > 0 THEN 1 ELSE 0 END FROM audit_logs a
             WHERE a.action='purchase.order.create' AND a.result='allow'
               AND a.target_id = $PO_MIX AND a.detail::jsonb ? 'src_doc_no';")"
expect "감사 detail 이 [제거됨] 으로 지워지지 않았다" 0 \
  "$(psqlq "SELECT count(*) FROM (SELECT detail FROM audit_logs
             WHERE action='purchase.order.create' AND result='allow'
             ORDER BY id DESC LIMIT 6) x WHERE x.detail::text LIKE '%제거됨%';")"

R=$(call qa_employee01 POST /api/purchase/po "$TOK_EMP1" \
  "{\"split\":true,\"lines\":[{\"item_id\":$ITEM_D,\"qty\":\"1\",\"location_id\":$LOC_X}]}")
expect "requester 는 직접 줄 저장도 403" 403 "$(code_of "$R")"

# ═════════════════════════════════════════════════════════════
hr "T-73 (f) 화면 — 3탭 · 장소 자동 · 세 갈래 입력 · 미리보기 (Playwright)"
# ═════════════════════════════════════════════════════════════

sub "화면 시험용 씨앗 — 검색어 F61UI 로 **두 전표(장소 X·Y)** 만 보이게 한다"
UI_A1=$(mkitem "$VEND_A" F61UI-A1 "F61UI swab"    1500)
UI_B1=$(mkitem "$VEND_B" F61UI-B1 "F61UI syringe" 3500)
UI_D1=$(mkitem "$VEND_A" F61UI-D1 "F61UI direct"  2500)
expect "화면용 품목 3개가 모두 만들어졌다" 3 \
  "$(psqlq "SELECT count(*) FROM p_items WHERE id IN ($UI_A1,$UI_B1,$UI_D1);")"
R=$(call qa_employee01 POST /api/purchase/pr "$TOK_EMP1" \
  "{\"to_location_id\":$LOC_X,\"memo\":\"F61UI 요청X\",\"lines\":[{\"item_id\":$UI_A1,\"qty\":\"4\"}]}")
expect "화면용 요청X(장소X·거래처A) 생성" 201 "$(code_of "$R")"
R=$(call qa_employee01 POST /api/purchase/pr "$TOK_EMP1" \
  "{\"to_location_id\":$LOC_Y,\"memo\":\"F61UI 요청Y\",\"lines\":[{\"item_id\":$UI_B1,\"qty\":\"6\"}]}")
expect "화면용 요청Y(장소Y·거래처B) 생성" 201 "$(code_of "$R")"

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
base, ua, state, out, loc_x, short_q, pick_q = sys.argv[1:8]
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
    page.wait_for_timeout(1200)

    # ① 탭 셋 (입력·전표·현황)
    report['tabs'] = page.eval_on_selector_all(
        '#po-tabs .tab', 'els => els.map(e => e.textContent.trim())')

    # ② 현황 탭 — 미입고 표 (입고 화면과 같은 렌더러)
    page.click('#po-tabs .tab[data-tab="status"]')
    page.wait_for_timeout(1500)
    report['status_visible'] = page.is_visible('#po-status')
    report['bo_kpis'] = page.eval_on_selector_all('#po-bo-kpis .kpi', 'els => els.length')
    report['bo_groups'] = page.eval_on_selector_all('#po-backorder .p-group', 'els => els.length')
    page.screenshot(path='%s/f61-status.png' % out, full_page=True)

    # ③ 입력 탭 — 머리 장소 기본값이 「— 줄에서 자동 —」
    page.click('#po-tabs .tab[data-tab="input"]')
    page.wait_for_timeout(600)
    report['head_loc_first'] = page.eval_on_selector('#po-location', 'e => e.options[0].text')
    report['head_loc_value'] = page.eval_on_selector('#po-location', 'e => e.value')

    # ④ 불러오기 — 머리 장소를 그대로 두고 두 전표를 담는다
    page.click('#po-load')
    page.wait_for_timeout(900)
    page.click('#load-groups .chip[data-group="vendor"]')   # 기본으로 켜진 거래처를 끈다
    page.click('#load-groups .chip[data-group="doc"]')
    page.fill('#load-q', 'F61UI')
    page.click('#load-run')
    page.wait_for_timeout(1400)
    checks = page.locator('#load-tree .p-tree-check')
    report['load_nodes'] = checks.count()
    for i in range(checks.count()):
        checks.nth(i).check()
        page.wait_for_timeout(300)
    page.click('#load-add')
    page.wait_for_timeout(800)
    report['line_locations'] = page.evaluate("""() => {
      return Array.from(document.querySelectorAll('#po-line-body tr'))
                  .map(r => r.querySelectorAll('td')[4].textContent.trim());
    }""")
    report['line_sources'] = page.evaluate("""() => {
      return Array.from(document.querySelectorAll('#po-line-body tr'))
                  .map(r => r.querySelectorAll('td')[5].textContent.trim());
    }""")
    report['preview'] = page.text_content('#po-split-preview')
    page.screenshot(path='%s/f61-loaded.png' % out, full_page=True)

    # ⑤ 품목 찾기 → 장소 고르고 담기 → 출처 「직접」
    page.click('#po-pick')
    page.wait_for_timeout(900)
    report['pick_loc_visible'] = page.is_visible('#pick-location-field')
    page.fill('#pick-q', pick_q)
    page.click('#pick-search')
    page.wait_for_timeout(1200)
    page.select_option('#pick-location', loc_x)
    page.check('#pick-body tr:first-child input[type=checkbox]')
    page.wait_for_timeout(300)
    page.click('#pick-add')
    page.wait_for_timeout(800)
    report['after_pick_sources'] = page.evaluate("""() => {
      return Array.from(document.querySelectorAll('#po-line-body tr'))
                  .map(r => r.querySelectorAll('td')[5].textContent.trim());
    }""")
    page.screenshot(path='%s/f61-direct.png' % out, full_page=True)

    # ⑥ 부족 산출 → 장소 고르고 담기 → 출처 「부족 산출」
    page.click('#po-short')
    page.wait_for_timeout(700)
    page.select_option('#short-location', loc_x)
    page.fill('#short-q', short_q)
    page.uncheck('#short-only')      # 이 시점엔 제안 0 이므로 「부족만」 을 끄고 본다
    page.wait_for_timeout(1400)
    report['short_rows'] = page.eval_on_selector_all('#short-body tr', 'els => els.length')
    report['short_formula'] = page.text_content('#short-formula')
    if report['short_rows'] > 0:
        page.check('#short-body tr:first-child input[type=checkbox]')
        page.wait_for_timeout(300)
        page.fill('#short-body tr:first-child input[type=number]', '2')
        page.wait_for_timeout(200)
        page.click('#short-add')
        page.wait_for_timeout(800)
    report['after_short_sources'] = page.evaluate("""() => {
      return Array.from(document.querySelectorAll('#po-line-body tr'))
                  .map(r => r.querySelectorAll('td')[5].textContent.trim());
    }""")
    report['preview2'] = page.text_content('#po-split-preview')
    page.screenshot(path='%s/f61-short.png' % out, full_page=True)

    # ⑦ 저장 → 알림에 전표 번호 N 개 (머리 장소는 그대로 「— 줄에서 자동 —」)
    page.click('#po-save')
    page.wait_for_timeout(2800)
    report['msg'] = page.text_content('#msg')
    page.screenshot(path='%s/f61-saved.png' % out, full_page=True)

    page.click('#po-tabs .tab[data-tab="docs"]')
    page.wait_for_timeout(1400)
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
  python3 "$OUT/f-screen.py" "$BASE" "$UA" "$OUT/f-state.json" "$OUT/shots" \
    "$LOC_X" "F61S" "F61UI-D1" > "$OUT/f-screen.json" 2>&1
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
    py() { python3 -c "
import json, re
d = json.load(open('$OUT/f-screen.json'))
print($1)"; }
    expect "탭이 셋(입력·전표·현황)" "['입력', '전표', '현황']" "$(g tabs)"
    expect "현황 패널이 보인다" 1 "$(g status_visible)"
    expect "현황 탭에 KPI 카드 4장" 4 "$(g bo_kpis)"
    expect "머리 장소 첫 항목이 「— 줄에서 자동 —」" "— 줄에서 자동 —" "$(g head_loc_first)"
    expect "머리 장소 기본값이 비어 있다" "" "$(g head_loc_value)"
    expect "불러오기 트리에 전표 노드 2개" 2 "$(g load_nodes)"
    expect "담은 줄의 입고 장소 칸이 비지 않는다" 0 \
      "$(py "sum(1 for c in d['line_locations'] if c in ('', '—'))")"
    expect "담은 줄의 입고 장소가 두 가지(중앙창고·수술방)" 2 \
      "$(py "len(set(d['line_locations']))")"
    expect "출처 칸이 PR 번호다" 1 \
      "$(py "int(all(c.startswith('PR-') for c in d['line_sources']))")"
    expect "미리보기가 「거래처×입고장소별 2장」" 1 \
      "$(py "int('거래처×입고장소별 2장' in (d['preview'] or ''))")"
    expect "머리 장소가 비어 있어 찾기 창에 장소 칸이 보인다" 1 "$(g pick_loc_visible)"
    expect "품목 찾기로 담은 줄의 출처가 「직접」" 1 \
      "$(py "int(any(c.startswith('직접') for c in d['after_pick_sources']))")"
    expect "부족 산출 창에 줄이 나온다" 1 \
      "$([ "$(g short_rows)" -ge 1 ] && echo 1 || echo 0)"
    expect "부족 산출 창 아래에 식 문장이 있다" 1 \
      "$(py "int('최소구매단위' in (d['short_formula'] or ''))")"
    expect "부족 산출로 담은 줄의 출처가 「부족 산출」" 1 \
      "$(py "int(any(c.startswith('부족 산출') for c in d['after_short_sources']))")"
    expect "미리보기가 세 갈래를 합쳐 「거래처×입고장소별 N장」" 1 \
      "$(py "int('거래처×입고장소별' in (d['preview2'] or ''))")"
    expect "성공 알림에 「발주서 N장을 만들었습니다」" 1 \
      "$(py "int('장을 만들었습니다' in (d['msg'] or ''))")"
    expect "알림에 전표 번호가 2개 이상" 1 \
      "$(py "int(len(re.findall(r'PO-[0-9]{8}-[0-9]+', d['msg'] or '')) >= 2)")"
    expect "전표 탭에 행이 보인다" 1 \
      "$([ "$(g doc_rows)" -ge 2 ] && echo 1 || echo 0)"
    expect "콘솔 오류 0건" 0 "$(py "len(d['console_errors'])")"
  fi
fi
[ "$SCREEN_OK" = "1" ] || echo "  (f) 결과: 실행하지 못함 — 보고서에 SKIP 으로 적을 것"

# ═════════════════════════════════════════════════════════════
hr "T-73 (g) 정적 점검 — 린트 · php -l · 화면 금지 패턴"
# ═════════════════════════════════════════════════════════════

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
for f in "$ROOT/src/handlers/purchase_po_create.php" "$ROOT/src/handlers/purchase_pr_load.php" \
         "$ROOT/src/purchase/Doc.php" "$ROOT/src/views/purchase_po.php"; do
  NAME=$(basename "$f")
  php -l "$f" > /dev/null 2>&1
  RC=$?
  expect "php -l $NAME" 0 "$RC"
done

# 린트(scripts/security_lint.sh)와 **같은 그물**을 쓴다 — 주석의 설명 문장은 호출이 아니다.
for f in purchase_docs.js purchase_common.js; do
  JSBAD=$(grep -cE 'innerHTML|outerHTML|document\.write\(' "$ROOT/public/assets/$f"); JSBAD=${JSBAD:-0}
  expect "$f 에 innerHTML·document.write 0건" 0 "$JSBAD"
done
expect "발주서 만들기 핸들러가 purchaseScopeWhere 를 부른다" 1 \
  "$([ "$(grep -c 'purchaseScopeWhere(' "$ROOT/src/handlers/purchase_po_create.php")" -ge 1 ] && echo 1 || echo 0)"
expect "ItemPicker 가 **한 벌**이다 (공통 파일에만 있다)" 1 \
  "$(grep -c 'function itemPicker' "$ROOT/public/assets/purchase_common.js")"
expect "발주요청·발주서 화면은 그것을 **부르기만** 한다" 2 \
  "$(grep -c 'UI.itemPicker(' "$ROOT/public/assets/purchase_docs.js")"
expect "미입고 렌더러도 한 벌 그대로다" 1 \
  "$(grep -c 'function renderBackorder' "$ROOT/public/assets/purchase_common.js")"
expect "발주서 화면이 부르는 미입고 창구는 공통 함수뿐이다" 0 \
  "$(grep -c "api/purchase/backorder" "$ROOT/public/assets/purchase_docs.js")"

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
             ||' loc='||coalesce(to_location_id::text,'-')
         FROM p_docs WHERE created_by IN ($QA_IDS) ORDER BY id;"
