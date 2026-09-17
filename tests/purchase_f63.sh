#!/usr/bin/env bash
# tests/purchase_f63.sh — F-63 수락 기준 실행 (PRD 12.2 T-75)
#   입고 불러오기 — 단추 뒤 창 · 묶는 기준 여섯 · 묶기 코드 한 벌
#   (PRD F-63 · F-33 · F-45 · S-01 · ARCH 5.18 「입고 불러오기 — 단추 뒤 창·묶는 기준」
#    · 25단계 「JS 헬퍼 한 벌」 / SECURITY R-65 · R-66 · R-73 · R-78)
#
# 규칙 (CLAUDE.md 3장)
#   · **검토 계정(qa_ 접두)만** 쓴다. 시드 계정(admin01 …)은 사용자의 자기 공격 전용이다.
#   · 모든 요청에 `-A "tbox-review/<날짜>"` 를 붙인다.
#   · 비밀번호·TOTP 비밀은 **필요한 필드만 awk 로** 뽑고 변수에만 담는다(화면에 찍지 않는다).
#
# ★ 시험 안전 (SECURITY R-73 · R-78) — tests/purchase_f61.sh 와 같은 방식이다
#   ① 최근 10분 감사 로그에 비-qa actor 가 있으면 시작하지 않는다(종료 코드 3).
#   ② 정리는 `created_by` 가 검토 계정인 행만. 표 전체 DELETE·TRUNCATE 는 **한 줄도 없다**.
#   ③ 채번 카운터 `p_doc_seq` 는 **지우지 않는다** — 남은 전표의 최대 번호로 되돌린다(R-78).
#   ④ 시작·끝에 비-qa 행 수를 찍고 달라졌으면 실패로 센다.
#   ⑤ 시험이 만든 입고·발주서·요청은 정리 직전에 **창구(void)로 취소**한 뒤 지운다.
#      입고를 먼저 취소하면 원장에 반대 부호 행이 들어가 재고가 0 으로 돌아온다 —
#      원장은 append-only 라 그것이 유일한 정상 경로다(21단계·F-61 과 같다).
#
# 사용: sudo -v && bash tests/purchase_f63.sh
set -u

BASE="http://127.0.0.1:8082"
UA="tbox-review/$(date +%F)"
DB="tbox_re"
JAR_DIR="$(mktemp -d)"
# 화면 시험의 부산물(스크린샷·JSON)을 두는 곳. F63_OUT 으로 밖에 남길 수 있다.
OUT="${F63_OUT:-$JAR_DIR/out}"; mkdir -p "$OUT"
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

# JSON 을 파이썬으로 읽는다 — 트리 안을 봐야 하는 단언이 많다(sed 로는 갈라 읽을 수 없다).
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
  for t in p_brands p_branches p_locations p_vendors p_categories p_items p_docs p_stock_moves; do
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

# 시험이 만든 전표를 **창구로** 취소한다. 순서가 중요하다:
#   입고(gr) → 발주서(po) → 요청(pr). 입고를 먼저 취소해야 발주서 취소가 막히지 않고,
#   원장은 반대 부호 행으로 0 으로 돌아온다(지우는 길이 앱에 없다).
void_made() {
  local id c
  [ -n "${TOK_EMP1:-}" ] || return 0
  for id in $(psqlq "SELECT id FROM p_docs
                      WHERE created_by IN ($QA_IDS) AND doc_type = 'gr' AND status <> 'void'
                      ORDER BY id DESC;"); do
    c=$(code_of "$(call qa_employee01 POST "/api/purchase/gr/$id/void" "$TOK_EMP1" '{"reason":"F-63 검토 시험 정리"}')")
    printf '  입고 %s 취소 → HTTP %s  ※ 원장은 append-only 라 반대 부호 행이 남는다\n' "$id" "$c"
  done
  [ -n "${TOK_MGR:-}" ] || return 0
  for id in $(psqlq "SELECT id FROM p_docs
                      WHERE created_by IN ($QA_IDS) AND doc_type = 'po' AND status <> 'void'
                      ORDER BY id DESC;"); do
    c=$(code_of "$(call qa_manager01 POST "/api/purchase/po/$id/void" "$TOK_MGR" '{"reason":"F-63 검토 시험 정리"}')")
    printf '  발주서 %s 취소 → HTTP %s\n' "$id" "$c"
  done
  for id in $(psqlq "SELECT id FROM p_docs
                      WHERE created_by IN ($QA_IDS) AND doc_type = 'pr' AND status <> 'void'
                      ORDER BY id DESC;"); do
    c=$(code_of "$(call qa_manager01 POST "/api/purchase/pr/$id/void" "$TOK_MGR" '{"reason":"F-63 검토 시험 정리"}')")
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

  sub "① 창구로 취소 (void) — 입고 → 발주서 → 요청"
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
TOK_ADMIN=$(login qa_admin01)    || exit 1
TOK_EMP1=$(login qa_employee01)  || exit 1
TOK_EMP2=$(login qa_employee02)  || exit 1
TOK_MGR=$(login qa_manager01)    || exit 1
echo "  로그인 4계정 완료"
echo "  재인증(기초정보 편집 창 열기): qa_admin01 → $(reauth qa_admin01 "$TOK_ADMIN")"

hr "1. 기초정보 씨앗 — 브랜드·지점 2·장소 3·거래처 2·분류 1·품목 4 (전부 F63 접두)"

R=$(call qa_admin01 POST /api/purchase/brands "$TOK_ADMIN" \
  '{"name":"F63 치과","biz_no":"163-45-67891","ceo_name":"검토 대표","address":"인천 부평구","tel":"032-000-0063"}')
BRAND=$(new_id "$(body_of "$R")"); expect "브랜드 생성" 201 "$(code_of "$R")"

R=$(call qa_admin01 POST /api/purchase/branches "$TOK_ADMIN" "{\"brand_id\":$BRAND,\"name\":\"F63 부평점\"}")
BR_A=$(new_id "$(body_of "$R")"); expect "지점A 생성" 201 "$(code_of "$R")"
R=$(call qa_admin01 POST /api/purchase/branches "$TOK_ADMIN" "{\"brand_id\":$BRAND,\"name\":\"F63 일산점\"}")
BR_B=$(new_id "$(body_of "$R")"); expect "지점B 생성" 201 "$(code_of "$R")"

R=$(call qa_admin01 POST /api/purchase/locations "$TOK_ADMIN" \
  "{\"branch_id\":$BR_A,\"kind\":\"warehouse\",\"name\":\"F63 중앙창고\"}")
LOC_X=$(new_id "$(body_of "$R")"); expect "장소X(지점A 창고) 생성" 201 "$(code_of "$R")"
R=$(call qa_admin01 POST /api/purchase/locations "$TOK_ADMIN" \
  "{\"branch_id\":$BR_A,\"kind\":\"or\",\"name\":\"F63 수술방\"}")
LOC_Y=$(new_id "$(body_of "$R")"); expect "장소Y(지점A 수술방) 생성" 201 "$(code_of "$R")"
R=$(call qa_admin01 POST /api/purchase/locations "$TOK_ADMIN" \
  "{\"branch_id\":$BR_B,\"kind\":\"warehouse\",\"name\":\"F63 일산창고\"}")
LOC_Z=$(new_id "$(body_of "$R")"); expect "장소Z(지점B 창고) 생성" 201 "$(code_of "$R")"

R=$(call qa_admin01 POST /api/purchase/vendors "$TOK_ADMIN" '{"name":"F63 거래처A","code":"F63VA"}')
VEND_A=$(new_id "$(body_of "$R")"); expect "거래처A 생성" 201 "$(code_of "$R")"
R=$(call qa_admin01 POST /api/purchase/vendors "$TOK_ADMIN" '{"name":"F63 거래처B","code":"F63VB"}')
VEND_B=$(new_id "$(body_of "$R")"); expect "거래처B 생성" 201 "$(code_of "$R")"

R=$(call qa_admin01 POST /api/purchase/categories "$TOK_ADMIN" '{"name":"F63 소모품","level":1}')
CAT1=$(new_id "$(body_of "$R")"); expect "분류(1단) 생성" 201 "$(code_of "$R")"

# 품목 하나. 명령 치환 안에서 돌므로 여기서 세지 않는다(하위 셸이라 카운터가 돌아오지 않는다).
mkitem() { # $1 vendor  $2 code  $3 name  $4 price  $5 추가 JSON 조각(앞에 콤마 포함, 없으면 빈 문자열)
  local r
  r=$(call qa_admin01 POST /api/purchase/items "$TOK_ADMIN" \
    "{\"vendor_id\":$1,\"code\":\"$2\",\"name\":\"$3\",\"unit\":\"EA\",\"price_in\":\"$4\"$5}")
  printf '  품목 %s → HTTP %s\n' "$2" "$(code_of "$r")" >&2
  new_id "$(body_of "$r")"
}
# A1: 분류 있음 · 조달기간 3일 / A2: **분류 없음**(미분류 열쇠 0 시험) / B1: 거래처B · 분류 있음
ITEM_A1=$(mkitem "$VEND_A" F63-A1 "F63 gauze"  1000 ",\"cat1_id\":$CAT1,\"lead_days\":3")
ITEM_A2=$(mkitem "$VEND_A" F63-A2 "F63 cotton" 2000 "")
ITEM_B1=$(mkitem "$VEND_B" F63-B1 "F63 needle" 3000 ",\"cat1_id\":$CAT1")
ITEM_Z1=$(mkitem "$VEND_A" F63-Z1 "F63 other-branch" 1500 ",\"cat1_id\":$CAT1")
echo "  품목: A1=$ITEM_A1 A2=$ITEM_A2 B1=$ITEM_B1 Z1=$ITEM_Z1"
expect "품목 4개가 모두 만들어졌다" 4 \
  "$(psqlq "SELECT count(*) FROM p_items WHERE id IN ($ITEM_A1,$ITEM_A2,$ITEM_B1,$ITEM_Z1);")"
expect "품목 A2 는 분류가 없다 (미분류)" "" \
  "$(psqlq "SELECT coalesce(cat1_id::text,'') FROM p_items WHERE id = $ITEM_A2;")"

sub "검토용 모듈 역할·지점 심기 (psql, 끝에서 원복)"
psqlc "UPDATE users SET branch_id = $BR_A, purchase_role = 'stock' WHERE username = 'qa_employee01';"
psqlc "UPDATE users SET branch_id = $BR_B, purchase_role = 'stock' WHERE username = 'qa_employee02';"
psqlc "UPDATE users SET branch_id = NULL,  purchase_role = 'buyer' WHERE username = 'qa_manager01';"
psqlq "SELECT username||' branch='||coalesce(branch_id::text,'-')||' role='||coalesce(purchase_role,'-')
         FROM users WHERE username LIKE 'qa%' ORDER BY id;" | sed 's/^/  /'

hr "2. 발주요청 → 발주서 4장 (보냄 3 · 안 보냄 1) — 입고 불러오기의 전제"

# ── PO1: 지점A · 장소X · 거래처A · 두 줄(A1 분류 있음 · A2 미분류)
R=$(call qa_manager01 POST /api/purchase/pr "$TOK_MGR" \
  "{\"branch_id\":$BR_A,\"to_location_id\":$LOC_X,\"vendor_id\":$VEND_A,\"lines\":[{\"item_id\":$ITEM_A1,\"qty\":\"5\"},{\"item_id\":$ITEM_A2,\"qty\":\"2\"}]}")
PR1=$(new_id "$(body_of "$R")"); expect "요청1 생성" 201 "$(code_of "$R")"
PR1_A1=$(psqlq "SELECT id FROM p_doc_lines WHERE doc_id = $PR1 AND item_id = $ITEM_A1;")
PR1_A2=$(psqlq "SELECT id FROM p_doc_lines WHERE doc_id = $PR1 AND item_id = $ITEM_A2;")
R=$(call qa_manager01 POST /api/purchase/po "$TOK_MGR" \
  "{\"to_location_id\":$LOC_X,\"lines\":[{\"src_line_id\":$PR1_A1,\"qty\":\"5\"},{\"src_line_id\":$PR1_A2,\"qty\":\"2\"}]}")
PO1=$(new_id "$(body_of "$R")"); PO1_NO=$(field doc_no "$(body_of "$R")")
expect "발주서1 생성(장소X·거래처A·2줄)" 201 "$(code_of "$R")"
PO1_A1=$(psqlq "SELECT id FROM p_doc_lines WHERE doc_id = $PO1 AND item_id = $ITEM_A1;")
PO1_A2=$(psqlq "SELECT id FROM p_doc_lines WHERE doc_id = $PO1 AND item_id = $ITEM_A2;")

# ── PO2: 지점A · 장소Y · 거래처B · 한 줄
R=$(call qa_manager01 POST /api/purchase/pr "$TOK_MGR" \
  "{\"branch_id\":$BR_A,\"to_location_id\":$LOC_Y,\"vendor_id\":$VEND_B,\"lines\":[{\"item_id\":$ITEM_B1,\"qty\":\"6\"}]}")
PR2=$(new_id "$(body_of "$R")"); expect "요청2 생성" 201 "$(code_of "$R")"
PR2_B1=$(psqlq "SELECT id FROM p_doc_lines WHERE doc_id = $PR2 AND item_id = $ITEM_B1;")
R=$(call qa_manager01 POST /api/purchase/po "$TOK_MGR" \
  "{\"to_location_id\":$LOC_Y,\"lines\":[{\"src_line_id\":$PR2_B1,\"qty\":\"6\"}]}")
PO2=$(new_id "$(body_of "$R")"); PO2_NO=$(field doc_no "$(body_of "$R")")
expect "발주서2 생성(장소Y·거래처B·1줄)" 201 "$(code_of "$R")"
PO2_B1=$(psqlq "SELECT id FROM p_doc_lines WHERE doc_id = $PO2 AND item_id = $ITEM_B1;")

# ── PO3: **보내지 않는다** (T-75 (e))
R=$(call qa_manager01 POST /api/purchase/pr "$TOK_MGR" \
  "{\"branch_id\":$BR_A,\"to_location_id\":$LOC_X,\"vendor_id\":$VEND_A,\"lines\":[{\"item_id\":$ITEM_A1,\"qty\":\"9\"}]}")
PR3=$(new_id "$(body_of "$R")"); expect "요청3 생성" 201 "$(code_of "$R")"
PR3_A1=$(psqlq "SELECT id FROM p_doc_lines WHERE doc_id = $PR3 AND item_id = $ITEM_A1;")
R=$(call qa_manager01 POST /api/purchase/po "$TOK_MGR" \
  "{\"to_location_id\":$LOC_X,\"lines\":[{\"src_line_id\":$PR3_A1,\"qty\":\"9\"}]}")
PO3=$(new_id "$(body_of "$R")"); PO3_NO=$(field doc_no "$(body_of "$R")")
expect "발주서3 생성(**보내지 않는다**)" 201 "$(code_of "$R")"

# ── PO4: 지점B (T-75 (d) 지점 격리)
R=$(call qa_manager01 POST /api/purchase/pr "$TOK_MGR" \
  "{\"branch_id\":$BR_B,\"to_location_id\":$LOC_Z,\"vendor_id\":$VEND_A,\"lines\":[{\"item_id\":$ITEM_Z1,\"qty\":\"4\"}]}")
PR4=$(new_id "$(body_of "$R")"); expect "요청4(지점B) 생성" 201 "$(code_of "$R")"
PR4_Z1=$(psqlq "SELECT id FROM p_doc_lines WHERE doc_id = $PR4 AND item_id = $ITEM_Z1;")
R=$(call qa_manager01 POST /api/purchase/po "$TOK_MGR" \
  "{\"to_location_id\":$LOC_Z,\"lines\":[{\"src_line_id\":$PR4_Z1,\"qty\":\"4\"}]}")
PO4=$(new_id "$(body_of "$R")"); PO4_NO=$(field doc_no "$(body_of "$R")")
expect "발주서4(지점B) 생성" 201 "$(code_of "$R")"
PO4_Z1=$(psqlq "SELECT id FROM p_doc_lines WHERE doc_id = $PO4 AND item_id = $ITEM_Z1;")

sub "보냄 — PO1·PO2·PO4 만 (PO3 는 그대로 둔다)"
for P in "$PO1" "$PO2" "$PO4"; do
  expect "발주서 $P 보냄 200" 200 "$(code_of "$(call qa_manager01 POST "/api/purchase/po/$P/send" "$TOK_MGR" '{}')")"
done
expect "PO3 는 sent_at 이 비어 있다" "" "$(psqlq "SELECT coalesce(sent_at::text,'') FROM p_docs WHERE id = $PO3;")"

# ═════════════════════════════════════════════════════════════
hr "T-75 (a) ?group=doc → nodes · key = 발주서 id · label 「PO 번호 · 일자」 · 소계"
# ═════════════════════════════════════════════════════════════

R=$(call qa_employee01 GET "/api/purchase/gr/load?group=doc&q=F63" "$TOK_EMP1")
B=$(body_of "$R")
echo "  HTTP $(code_of "$R")"; printf '  %s\n' "$(printf '%s' "$B" | head -c 500)"
expect "stock 계정 200" 200 "$(code_of "$R")"
expect "응답에 nodes 가 있다" 1 "$(jq_py "$B" "int(isinstance(d.get('nodes'), list))")"
expect "응답에 orders 는 **없다**(두 모양을 섞지 않는다)" 0 "$(jq_py "$B" "int('orders' in d)")"
expect "group 이 ['doc'] 로 되돌아온다" "['doc']" "$(jq_py "$B" "d.get('group')")"
expect "노드가 2개(PO1·PO2 — 지점A 의 보낸 발주서)" 2 "$(jq_py "$B" "len(d['nodes'])")"
expect "모든 노드의 group 이 'doc'" 1 "$(jq_py "$B" "int(all(n['group'] == 'doc' for n in d['nodes']))")"
expect "노드 열쇠가 발주서 id 다 (PO1 이 있다)" 1 "$(jq_py "$B" "int($PO1 in [n['key'] for n in d['nodes']])")"
expect "노드 라벨이 「PO 번호 · 일자」" 1 \
  "$(jq_py "$B" "int(all(n['label'].startswith('PO-') and ' · ' in n['label'] for n in d['nodes']))")"
expect "노드마다 line_count 가 있다" 1 \
  "$(jq_py "$B" "int(all(isinstance(n.get('line_count'), int) for n in d['nodes']))")"
expect "PO1 노드의 줄 수 2" 2 \
  "$(jq_py "$B" "[n['line_count'] for n in d['nodes'] if n['key'] == $PO1][0]")"
expect "PO1 노드의 잔량 소계 7.000 (5 + 2)" "7.000" \
  "$(jq_py "$B" "[n['qty'] for n in d['nodes'] if n['key'] == $PO1][0]")"
expect "PO2 노드의 잔량 소계 6.000" "6.000" \
  "$(jq_py "$B" "[n['qty'] for n in d['nodes'] if n['key'] == $PO2][0]")"
expect "마지막 층에 lines 가 달려 있다" 1 \
  "$(jq_py "$B" "int(all('lines' in n and 'children' not in n for n in d['nodes']))")"
expect "줄에 담기용 칸이 그대로 있다 (po_line_id·remaining)" 1 \
  "$(jq_py "$B" "int(all('po_line_id' in l and 'remaining' in l and 'po_doc_no' in l
                          for n in d['nodes'] for l in n['lines']))")"
expect "전체 잔량 13.000" "13.000" "$(jq_py "$B" "d.get('qty')")"
expect "line_count 합계 3" 3 "$(jq_py "$B" "d.get('line_count')")"

# ═════════════════════════════════════════════════════════════
hr "T-75 (b) 2층 · group 없으면 orders 그대로(계약 불변) · 모르는 값 무시"
# ═════════════════════════════════════════════════════════════

sub "?group=vendor,doc — 적은 순서가 층이다"
R=$(call qa_employee01 GET "/api/purchase/gr/load?group=vendor,doc&q=F63" "$TOK_EMP1")
B=$(body_of "$R")
expect "200" 200 "$(code_of "$R")"
expect "1층이 거래처" 1 "$(jq_py "$B" "int(all(n['group'] == 'vendor' for n in d['nodes']))")"
expect "2층이 전표" 1 \
  "$(jq_py "$B" "int(all(k['group'] == 'doc' for n in d['nodes'] for k in n['children']))")"
expect "중간 층에는 lines 가 없다" 1 \
  "$(jq_py "$B" "int(all('lines' not in n for n in d['nodes']))")"
expect "거래처 두 묶음(A·B)" 2 "$(jq_py "$B" "len(d['nodes'])")"
expect "거래처A 묶음 소계 7.000" "7.000" \
  "$(jq_py "$B" "[n['qty'] for n in d['nodes'] if n['key'] == $VEND_A][0]")"

sub "★ group 이 없으면 **21단계의 orders 그대로** — 계약 불변 (실측)"
R=$(call qa_employee01 GET "/api/purchase/gr/load?q=F63" "$TOK_EMP1")
B_NOGROUP=$(body_of "$R")
printf '  %s\n' "$(printf '%s' "$B_NOGROUP" | head -c 400)"
expect "200" 200 "$(code_of "$R")"
expect "orders 가 있다" 1 "$(jq_py "$B_NOGROUP" "int(isinstance(d.get('orders'), list))")"
expect "nodes 는 **없다**" 0 "$(jq_py "$B_NOGROUP" "int('nodes' in d)")"
expect "group 키도 없다 (21단계 응답에 없던 키)" 0 "$(jq_py "$B_NOGROUP" "int('group' in d)")"
expect "응답 키가 21단계와 똑같이 셋(orders·line_count·truncated)" \
  "['orders', 'line_count', 'truncated']" "$(jq_py "$B_NOGROUP" "list(d.keys())")"
expect "발주서별 묶음 2장" 2 "$(jq_py "$B_NOGROUP" "len(d['orders'])")"
expect "묶음 머리 칸이 21단계와 같다" 1 \
  "$(jq_py "$B_NOGROUP" "int(all(set(['po_doc_id','po_doc_no','po_date','sent_at','days_elapsed',
       'branch_id','branch_name','location_id','location_name','vendor_id','vendor_name',
       'manager_name','lines']) == set(o.keys()) for o in d['orders']))")"
expect "묶음 줄 칸이 21단계와 같다" 1 \
  "$(jq_py "$B_NOGROUP" "int(all(set(['po_line_id','item_id','code','name','spec','unit',
       'stock_managed','lead_days','ordered_qty','received_qty','remaining','unit_price'])
       == set(l.keys()) for o in d['orders'] for l in o['lines']))")"
expect "?po= 걸개도 그대로 돈다" 1 \
  "$(jq_py "$(body_of "$(call qa_employee01 GET "/api/purchase/gr/load?po=$PO1" "$TOK_EMP1")")" \
      "int(len(d['orders']) == 1 and d['orders'][0]['po_doc_id'] == $PO1)")"

sub "모르는 group 값은 무시한다"
R=$(call qa_employee01 GET "/api/purchase/gr/load?group=bogus&q=F63" "$TOK_EMP1")
B=$(body_of "$R")
expect "아는 값이 하나도 없으면 orders (트리 아님)" 1 \
  "$(jq_py "$B" "int('orders' in d and 'nodes' not in d)")"
R=$(call qa_employee01 GET "/api/purchase/gr/load?group=bogus,doc,%20&q=F63" "$TOK_EMP1")
B=$(body_of "$R")
expect "모르는 값을 버리고 doc 만 남는다" "['doc']" "$(jq_py "$B" "d.get('group')")"
R=$(call qa_employee01 GET "/api/purchase/gr/load?group=doc,doc,vendor&q=F63" "$TOK_EMP1")
expect "같은 값이 두 번이면 한 번만 센다" "['doc', 'vendor']" \
  "$(jq_py "$(body_of "$R")" "d.get('group')")"
R=$(call qa_employee01 GET "/api/purchase/gr/load?group=vendor,location,manager,branch&q=F63" "$TOK_EMP1")
expect "네 개를 적어도 **세 층까지**만" 3 "$(jq_py "$(body_of "$R")" "len(d['group'])")"
R=$(call qa_employee01 GET "/api/purchase/gr/load?group=vendor%27%3B--&q=F63" "$TOK_EMP1")
expect "따옴표를 넣어도 200 이고 트리가 아니다 (SQL 에 닿지 않는다)" 1 \
  "$(jq_py "$(body_of "$R")" "int('orders' in d)")"
expect "그 요청 뒤에도 발주서 표가 그대로다" 4 \
  "$(psqlq "SELECT count(*) FROM p_docs WHERE id IN ($PO1,$PO2,$PO3,$PO4);")"

# ═════════════════════════════════════════════════════════════
hr "T-75 (c) ?group=category → 1단 분류 · 미분류는 열쇠 0"
# ═════════════════════════════════════════════════════════════

R=$(call qa_employee01 GET "/api/purchase/gr/load?group=category&q=F63" "$TOK_EMP1")
B=$(body_of "$R")
printf '  %s\n' "$(printf '%s' "$B" | head -c 400)"
expect "200" 200 "$(code_of "$R")"
expect "1단이다 (children 없음)" 1 \
  "$(jq_py "$B" "int(all('children' not in n and 'lines' in n for n in d['nodes']))")"
expect "묶음 둘 — 분류 하나 + 미분류" 2 "$(jq_py "$B" "len(d['nodes'])")"
expect "분류 묶음의 열쇠가 분류 id" 1 "$(jq_py "$B" "int($CAT1 in [n['key'] for n in d['nodes']])")"
expect "미분류 묶음의 열쇠가 0" 1 "$(jq_py "$B" "int(0 in [n['key'] for n in d['nodes']])")"
expect "미분류 묶음의 이름이 「미분류」" "미분류" \
  "$(jq_py "$B" "[n['label'] for n in d['nodes'] if n['key'] == 0][0]")"
expect "미분류 묶음에 A2 한 줄(잔량 2.000)" "2.000" \
  "$(jq_py "$B" "[n['qty'] for n in d['nodes'] if n['key'] == 0][0]")"

sub "나머지 세 기준도 트리를 낸다 (location·manager·branch)"
for G in location manager branch; do
  RB=$(body_of "$(call qa_employee01 GET "/api/purchase/gr/load?group=$G&q=F63" "$TOK_EMP1")")
  expect "?group=$G → nodes 이고 group 이 그 값" 1 \
    "$(jq_py "$RB" "int(isinstance(d.get('nodes'), list) and d.get('group') == ['$G'])")"
  expect "?group=$G 노드의 열쇠가 정수" 1 \
    "$(jq_py "$RB" "int(all(isinstance(n['key'], int) for n in d['nodes']))")"
done
RB=$(body_of "$(call qa_employee01 GET "/api/purchase/gr/load?group=location&q=F63" "$TOK_EMP1")")
expect "입고 장소 묶음 둘(장소X·장소Y)" 2 "$(jq_py "$RB" "len(d['nodes'])")"
expect "장소X 묶음이 있다" 1 "$(jq_py "$RB" "int($LOC_X in [n['key'] for n in d['nodes']])")"

# ═════════════════════════════════════════════════════════════
hr "T-75 (d) 지점 격리 — 남의 지점은 트리에도 없다 (R-65)"
# ═════════════════════════════════════════════════════════════

R=$(call qa_employee02 GET "/api/purchase/gr/load?group=doc&q=F63" "$TOK_EMP2")
B_B=$(body_of "$R")
echo "  지점B stock 계정: HTTP $(code_of "$R")"
printf '  %s\n' "$(printf '%s' "$B_B" | head -c 400)"
expect "200" 200 "$(code_of "$R")"
expect "지점B 계정에게는 노드 1개(PO4)뿐" 1 "$(jq_py "$B_B" "len(d['nodes'])")"
expect "그 노드가 PO4 다" 1 "$(jq_py "$B_B" "int(d['nodes'][0]['key'] == $PO4)")"
expect "지점A 발주서 번호가 본문에 0건" 0 "$(printf '%s' "$B_B" | grep -c "$PO1_NO")"
expect "지점A 발주서 번호(PO2) 도 0건" 0 "$(printf '%s' "$B_B" | grep -c "$PO2_NO")"

sub "?branch= 에 남의 지점을 적어도 결과가 바뀌지 않는다"
R2=$(call qa_employee02 GET "/api/purchase/gr/load?group=doc&q=F63&branch=$BR_A" "$TOK_EMP2")
expect "200" 200 "$(code_of "$R2")"
expect "「남의 지점만」 을 적으면 0건이 되고 남의 자료는 새지 않는다" 0 \
  "$(jq_py "$(body_of "$R2")" "len(d['nodes'])")"
expect "본문에 지점A 발주서 번호 0건" 0 "$(printf '%s' "$(body_of "$R2")" | grep -c "$PO1_NO")"
R3=$(call qa_employee02 GET "/api/purchase/gr/load?group=doc&q=F63&branch=$BR_B" "$TOK_EMP2")
expect "자기 지점을 적으면 결과가 같다" "$(jq_py "$B_B" "len(d['nodes'])")" \
  "$(jq_py "$(body_of "$R3")" "len(d['nodes'])")"

sub "?po= 로 남의 지점 발주서를 집어도 0건"
expect "지점B 계정이 PO1 을 집으면 노드 0" 0 \
  "$(jq_py "$(body_of "$(call qa_employee02 GET "/api/purchase/gr/load?group=doc&po=$PO1" "$TOK_EMP2")")" "len(d['nodes'])")"

sub "buyer(전 지점)는 둘 다 본다 — 조건이 역할에서 나온다는 증거"
expect "buyer 에게는 세 발주서가 다 보인다" 3 \
  "$(jq_py "$(body_of "$(call qa_manager01 GET "/api/purchase/gr/load?group=doc&q=F63" "$TOK_MGR")")" "len(d['nodes'])")"

# ═════════════════════════════════════════════════════════════
hr "T-75 (e) 안 보낸 발주서 · 잔량 0 줄은 트리에도 없다"
# ═════════════════════════════════════════════════════════════

R=$(call qa_employee01 GET "/api/purchase/gr/load?group=doc&q=F63" "$TOK_EMP1")
B=$(body_of "$R")
expect "보내지 않은 PO3 는 트리에 0건" 0 "$(printf '%s' "$B" | grep -c "$PO3_NO")"
expect "PO3 를 ?po= 로 집어도 노드 0" 0 \
  "$(jq_py "$(body_of "$(call qa_employee01 GET "/api/purchase/gr/load?group=doc&po=$PO3" "$TOK_EMP1")")" "len(d['nodes'])")"

sub "A2 줄(잔량 2)을 **전량 입고** → 그 줄이 트리에서 사라진다"
R=$(call qa_employee01 POST /api/purchase/gr "$TOK_EMP1" \
  "{\"lines\":[{\"src_line_id\":$PO1_A2,\"qty\":\"2\"}]}")
GR0=$(new_id "$(body_of "$R")")
expect "입고 확정 201" 201 "$(code_of "$R")"
B=$(body_of "$(call qa_employee01 GET "/api/purchase/gr/load?group=doc&q=F63" "$TOK_EMP1")")
expect "PO1 노드의 줄이 1개로 줄었다" 1 \
  "$(jq_py "$B" "[n['line_count'] for n in d['nodes'] if n['key'] == $PO1][0]")"
expect "PO1 노드 잔량 소계가 5.000" "5.000" \
  "$(jq_py "$B" "[n['qty'] for n in d['nodes'] if n['key'] == $PO1][0]")"
expect "전량 입고된 줄의 po_line_id 가 트리에 0건" 0 \
  "$(jq_py "$B" "sum(1 for n in d['nodes'] for l in n['lines'] if l['po_line_id'] == $PO1_A2)")"
expect "?group=category 에서도 미분류 묶음이 사라졌다" 0 \
  "$(jq_py "$(body_of "$(call qa_employee01 GET "/api/purchase/gr/load?group=category&q=F63" "$TOK_EMP1")")" \
      "sum(1 for n in d['nodes'] if n['key'] == 0)")"

sub "부분 입고 줄은 **남은 잔량만** 트리에 있다 (A1: 5 중 2 입고 → 3)"
R=$(call qa_employee01 POST /api/purchase/gr "$TOK_EMP1" \
  "{\"lines\":[{\"src_line_id\":$PO1_A1,\"qty\":\"2\"}]}")
expect "입고 확정 201" 201 "$(code_of "$R")"
B=$(body_of "$(call qa_employee01 GET "/api/purchase/gr/load?group=doc&q=F63" "$TOK_EMP1")")
expect "PO1 노드 잔량 소계가 3.000" "3.000" \
  "$(jq_py "$B" "[n['qty'] for n in d['nodes'] if n['key'] == $PO1][0]")"
expect "그 줄의 remaining 이 3.000" "3.000" \
  "$(jq_py "$B" "[l['remaining'] for n in d['nodes'] for l in n['lines'] if l['po_line_id'] == $PO1_A1][0]")"
expect "received_qty 가 2.000 으로 보인다" "2.000" \
  "$(jq_py "$B" "[l['received_qty'] for n in d['nodes'] for l in n['lines'] if l['po_line_id'] == $PO1_A1][0]")"

sub "취소된 발주서도 트리에 없다"
R=$(call qa_manager01 POST "/api/purchase/po/$PO2/void" "$TOK_MGR" '{"reason":"F-63 (e) 시험"}')
expect "PO2 취소 200" 200 "$(code_of "$R")"
B=$(body_of "$(call qa_employee01 GET "/api/purchase/gr/load?group=doc&q=F63" "$TOK_EMP1")")
expect "취소한 PO2 가 트리에 0건" 0 "$(printf '%s' "$B" | grep -c "$PO2_NO")"

sub "정책 — 읽기 전용(viewer) 은 이 창구에 닿지 못한다 (403)"
psqlc "UPDATE users SET purchase_role = 'viewer' WHERE username = 'qa_employee02';"
expect "viewer 는 403" 403 \
  "$(code_of "$(call qa_employee02 GET "/api/purchase/gr/load?group=doc" "$TOK_EMP2")")"
psqlc "UPDATE users SET purchase_role = 'stock' WHERE username = 'qa_employee02';"

# ═════════════════════════════════════════════════════════════
hr "T-75 (f)(g)(h) 화면 — 단추 뒤 창 · 조회 횟수 · 묶음 체크 · 여러 전표 담기 (Playwright)"
# ═════════════════════════════════════════════════════════════

sub "화면 시험용 씨앗 — 보낸 발주서 두 장을 더 만든다(검색어 F63UI)"
UI_1=$(mkitem "$VEND_A" F63UI-1 "F63UI swab"    1500 ",\"cat1_id\":$CAT1")
UI_2=$(mkitem "$VEND_B" F63UI-2 "F63UI syringe" 3500 ",\"cat1_id\":$CAT1")
expect "화면용 품목 2개가 모두 만들어졌다" 2 \
  "$(psqlq "SELECT count(*) FROM p_items WHERE id IN ($UI_1,$UI_2);")"

# $5·$6 이 있으면 **줄 두 개**인 발주서를 만든다 — 노드 아래 줄이 둘이어야
# 「한 줄만 해제 → 노드가 중간 상태」를 볼 수 있다(실측 정정 2026-09-17).
mkpo() { # $1 장소  $2 거래처  $3 품목  $4 수량  [$5 품목2  $6 수량2] → 보낸 발주서 id
  local r pr po prlines polines
  prlines="{\"item_id\":$3,\"qty\":\"$4\"}"
  [ -n "${5:-}" ] && prlines="$prlines,{\"item_id\":$5,\"qty\":\"$6\"}"
  r=$(call qa_manager01 POST /api/purchase/pr "$TOK_MGR" \
    "{\"branch_id\":$BR_A,\"to_location_id\":$1,\"vendor_id\":$2,\"lines\":[$prlines]}")
  pr=$(new_id "$(body_of "$r")")
  polines=""
  local lid
  for it in $3 ${5:-}; do
    lid=$(psqlq "SELECT id FROM p_doc_lines WHERE doc_id = $pr AND item_id = $it;")
    [ -n "$polines" ] && polines="$polines,"
    polines="$polines{\"src_line_id\":$lid,\"qty\":\"$(psqlq "SELECT qty::int FROM p_doc_lines WHERE id = $lid;")\"}"
  done
  r=$(call qa_manager01 POST /api/purchase/po "$TOK_MGR" \
    "{\"to_location_id\":$1,\"lines\":[$polines]}")
  po=$(new_id "$(body_of "$r")")
  call qa_manager01 POST "/api/purchase/po/$po/send" "$TOK_MGR" '{}' > /dev/null
  printf '%s' "$po"
}
# ★ 두 발주서를 **같은 장소**(LOC_X)로 만든다. 입고 전표는 장소가 하나여야 하고
#   (`purchase_gr_create.php:283` — 「입고 장소는 한 전표에 하나」, 21단계 규칙),
#   이 장면이 보려는 것은 「여러 전표를 한 입고 전표에 담는다」이다. 장소가 갈리면
#   서버가 옳게 400 `bad_request` 로 거부하고, 그 갈래는 아래 (h-2) 가 따로 시험한다.
#   PO1 은 줄 두 개(중간 상태용), PO2 는 한 개.
UI_PO1=$(mkpo "$LOC_X" "$VEND_A" "$UI_1" 4 "$UI_2" 5)
UI_PO2=$(mkpo "$LOC_X" "$VEND_B" "$UI_2" 6)
echo "  화면용 발주서: $UI_PO1 · $UI_PO2"
expect "화면용 발주서 두 장이 보낸 상태다" 2 \
  "$(psqlq "SELECT count(*) FROM p_docs WHERE id IN ($UI_PO1,$UI_PO2) AND sent_at IS NOT NULL;")"
expect "검색어 F63UI 로 트리에 전표 노드 2개" 2 \
  "$(jq_py "$(body_of "$(call qa_employee01 GET "/api/purchase/gr/load?group=doc&q=F63UI" "$TOK_EMP1")")" "len(d['nodes'])")"

# ─────────────────────────────────────────────────────────────
# (h-2) 장소가 갈리는 두 발주서를 한 입고 전표에 담으면 거부된다
#   규칙은 21단계부터 있다 — `purchase_gr_create.php:283` 「입고 장소는 한 전표에
#   하나 — 여러 곳에 동시에 들어오는 입고는 전표를 나눈다」. 어디에서도 시험하지
#   않고 있었는데, F-63 이 **여러 전표를 한 번에 담기** 쉽게 만들었으므로 여기서
#   못 박는다(2026-09-17 실측으로 드러난 자리 — 처음엔 화면 결함으로 보였다).
# ─────────────────────────────────────────────────────────────
sub "(h-2) 입고 전표는 장소가 하나 — 장소가 갈리면 400 bad_request"
MIX_ITEM=$(mkitem "$VEND_A" F63MIX-1 "F63MIX pad" 700 ",\"cat1_id\":$CAT1")
MIX_PO=$(mkpo "$LOC_Y" "$VEND_A" "$MIX_ITEM" 3)
L_X=$(psqlq "SELECT id FROM p_doc_lines WHERE doc_id = $UI_PO1 ORDER BY id LIMIT 1;")
L_Y=$(psqlq "SELECT id FROM p_doc_lines WHERE doc_id = $MIX_PO ORDER BY id LIMIT 1;")
GR_BEFORE_MIX=$(psqlq "SELECT count(*) FROM p_docs WHERE doc_type='gr' AND created_by IN ($QA_IDS);")
expect "두 발주서의 장소가 실제로 다르다" 2 \
  "$(psqlq "SELECT count(DISTINCT to_location_id) FROM p_docs WHERE id IN ($UI_PO1,$MIX_PO);")"
R=$(call qa_employee01 POST /api/purchase/gr "$TOK_EMP1" \
  "{\"doc_date\":\"$(date +%F)\",\"lines\":[{\"src_line_id\":$L_X,\"qty\":\"1\"},{\"src_line_id\":$L_Y,\"qty\":\"1\"}]}")
expect "장소가 갈린 입고 400" 400 "$(code_of "$R")"
expect "감사 사유 bad_request" "bad_request" \
  "$(psqlq "SELECT reason FROM audit_logs WHERE action='purchase.receipt.create' AND result='deny'
              AND actor_name LIKE 'qa\\_%' ORDER BY id DESC LIMIT 1;")"
expect "그 시도로 입고 전표가 생기지 않았다 (시도 전후가 같다)" "$GR_BEFORE_MIX" \
  "$(psqlq "SELECT count(*) FROM p_docs WHERE doc_type='gr' AND created_by IN ($QA_IDS);")"
# 한 장소만 담으면 통과한다 — 거부가 **장소 때문**이라는 증거다.
# 출처는 MIX_PO 쪽 줄로 쓴다(UI_PO1 을 쓰면 아래 「화면이 만든 입고 전표」 셈에 섞인다).
R=$(call qa_employee01 POST /api/purchase/gr "$TOK_EMP1" \
  "{\"doc_date\":\"$(date +%F)\",\"lines\":[{\"src_line_id\":$L_Y,\"qty\":\"1\"}]}")
expect "한 장소만이면 201 (거부가 장소 때문임을 못 박는다)" 201 "$(code_of "$R")"

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
base, ua, state, out, search = sys.argv[1:6]
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

    # ★ (f) 의 핵심 — `gr/load` 요청을 **센다**
    calls = []
    page.on('request', lambda r: calls.append(r.url) if '/api/purchase/gr/load' in r.url else None)

    # ① 진입 — 불러오기 패널이 없고 조회도 없다
    page.goto(base + '/purchase/receipt', wait_until='networkidle')
    page.wait_for_timeout(2500)
    report['panel_gone'] = page.eval_on_selector_all('#gr-load', 'els => els.length')
    report['orders_host_gone'] = page.eval_on_selector_all('#gr-orders', 'els => els.length')
    report['open_btn'] = page.is_visible('#gr-load-open')
    report['open_btn_text'] = page.text_content('#gr-load-open').strip()
    report['line_empty'] = (page.text_content('#gr-line-empty') or '').strip()
    report['modal_closed_at_first'] = not page.evaluate("document.getElementById('gr-load-modal').open")
    report['calls_on_enter'] = len(calls)
    page.screenshot(path='%s/f63-enter.png' % out, full_page=True)

    # ② 단추 → 창 열림. 조회는 아직 0회
    page.click('#gr-load-open')
    page.wait_for_timeout(1500)
    report['modal_open'] = page.evaluate("document.getElementById('gr-load-modal').open")
    report['calls_on_open'] = len(calls)
    report['chips'] = page.eval_on_selector_all(
        '#grload-groups .chip', 'els => els.map(e => e.textContent.replace(/^[①②③]/, "").trim())')
    report['filters_in_modal'] = page.evaluate("""() => {
      var m = document.getElementById('gr-load-modal');
      return ['gr-vendor', 'gr-q', 'gr-overdue'].map(function (id) {
        var n = document.getElementById(id);
        return n ? m.contains(n) : false;
      });
    }""")
    page.screenshot(path='%s/f63-modal-empty.png' % out, full_page=True)

    # ③ 기본이 이미 「전표」 다 — 칩을 누르지 않고 그대로 「불러오기」 → 1회 조회
    #    (사용자 요청이 「발주요청 불러오듯이 **전표** 불러오도록」 이었다, PRD F-63)
    report['chips_on_default'] = page.eval_on_selector_all(
        '#grload-groups .chip.is-on', 'els => els.map(e => e.getAttribute("data-group"))')
    report['tree_panel_title'] = page.text_content(
        '#gr-load-modal .split section:first-child .panel__title')
    report['pick_text'] = (page.text_content('#grload-lines-empty') or '').strip()
    page.fill('#gr-q', search)
    page.click('#grload-run')
    page.wait_for_timeout(2000)
    report['calls_after_run'] = len(calls)
    report['call_urls'] = calls[:]
    checks = page.locator('#grload-tree .p-tree-check')
    report['nodes'] = checks.count()
    report['node_labels'] = page.eval_on_selector_all('#grload-tree .p-tree-name', 'e => e.map(x => x.textContent)')
    report['node_sums'] = page.eval_on_selector_all('#grload-tree .p-tree-sum', 'e => e.map(x => x.textContent)')
    page.screenshot(path='%s/f63-tree.png' % out, full_page=True)

    # ④ 노드 체크 → 아래 줄 전부 담김(잔량) · 일부 해제 → 중간 상태
    checks.nth(0).check()
    page.wait_for_timeout(400)
    report['info_node0'] = page.text_content('#grload-info')
    page.click('#grload-tree .p-tree-head >> nth=0')          # 줄 표를 펼친다
    page.wait_for_timeout(500)
    report['lines_title'] = page.text_content('#grload-lines-title')
    report['row_checked'] = page.eval_on_selector_all(
        '#grload-lines-body tr input[type=checkbox]', 'e => e.map(x => x.checked)')
    report['row_qtys'] = page.eval_on_selector_all(
        '#grload-lines-body tr input[type=number]', 'e => e.map(x => x.value)')
    page.uncheck('#grload-lines-body tr:first-child input[type=checkbox]')
    page.wait_for_timeout(400)
    report['node0_indeterminate'] = page.evaluate(
        "document.querySelectorAll('#grload-tree .p-tree-check')[0].indeterminate")
    report['node0_checked_partial'] = page.evaluate(
        "document.querySelectorAll('#grload-tree .p-tree-check')[0].checked")
    page.check('#grload-lines-body tr:first-child input[type=checkbox]')
    page.wait_for_timeout(300)
    report['node0_checked_full'] = page.evaluate(
        "document.querySelectorAll('#grload-tree .p-tree-check')[0].checked")

    # ⑤ **여러 전표를 한 번에** — 둘째 노드도 체크하고 담는다
    checks.nth(1).check()
    page.wait_for_timeout(400)
    report['info_both'] = page.text_content('#grload-info')
    page.click('#grload-add')
    page.wait_for_timeout(1200)
    report['modal_closed_after_add'] = not page.evaluate("document.getElementById('gr-load-modal').open")
    report['line_rows'] = page.eval_on_selector_all('#gr-line-body tr', 'e => e.length')
    report['line_docs'] = page.evaluate("""() => {
      return Array.from(document.querySelectorAll('#gr-line-body tr'))
                  .map(r => r.querySelectorAll('td')[2].textContent.trim());
    }""")
    report['calls_after_add'] = len(calls)
    page.screenshot(path='%s/f63-loaded.png' % out, full_page=True)

    # ⑥ (h) 같은 줄을 두 번 담아도 한 줄
    page.click('#gr-load-open')
    page.wait_for_timeout(1200)
    report['calls_on_reopen'] = len(calls)
    page.click('#grload-run')
    page.wait_for_timeout(2000)
    page.locator('#grload-tree .p-tree-check').nth(0).check()
    page.wait_for_timeout(400)
    page.click('#grload-add')
    page.wait_for_timeout(1200)
    report['line_rows_after_twice'] = page.eval_on_selector_all('#gr-line-body tr', 'e => e.length')

    # ⑦ (h) 입고 확정 — 21단계와 같은 저장 경로
    page.click('#gr-save')
    page.wait_for_timeout(3000)
    report['msg'] = page.text_content('#msg')
    report['result_visible'] = page.is_visible('#gr-result')
    report['result_head'] = page.text_content('#gr-result-head') if page.is_visible('#gr-result') else ''
    page.screenshot(path='%s/f63-saved.png' % out, full_page=True)

    report['console_errors'] = errs
    b.close()
print(json.dumps(report, ensure_ascii=False, indent=2))
PY

SCREEN_OK=0
PW_PW=$(qa_password qa_employee01)
PW_SEC=$(qa_secret qa_employee01)
mkdir -p "$OUT/shots"
for TRY in 1 2 3; do
  PW_CODE=$(next_code "$PW_SEC")
  python3 "$OUT/f-login.py" "$BASE" "$UA" qa_employee01 "$PW_PW" "$PW_CODE" "$OUT/f-state.json" \
    > "$OUT/f-login.txt" 2>&1
  sed 's/^/    /' "$OUT/f-login.txt"
  grep -q PLAYWRIGHT_MISSING "$OUT/f-login.txt" && break
  grep -q 'URL=.*/login' "$OUT/f-login.txt" || break
  echo "  · 브라우저가 아직 /login 에 있다 — 다음 코드 창에서 다시 시도 ($TRY/3)"
done
if grep -q PLAYWRIGHT_MISSING "$OUT/f-login.txt"; then
  echo "  ! playwright 가 없어 (f)(g)(h) 를 실행하지 못했다 — **SKIP (미검증)**"
elif grep -q 'URL=.*/login' "$OUT/f-login.txt"; then
  echo "  ✖ 브라우저 로그인 실패 — (f)(g)(h) 를 건너뛴다"
  FAIL=$((FAIL+1))
else
  python3 "$OUT/f-screen.py" "$BASE" "$UA" "$OUT/f-state.json" "$OUT/shots" "F63UI" \
    > "$OUT/f-screen.json" 2>&1
  sed 's/^/    /' "$OUT/f-screen.json"
  if grep -q PLAYWRIGHT_MISSING "$OUT/f-screen.json"; then
    echo "  ! playwright 가 없어 (f)(g)(h) 를 실행하지 못했다 — **SKIP (미검증)**"
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

    sub "(f) 첫 그림에 불러오기 패널이 없고 진입 조회 0회"
    expect "패널 #gr-load 가 화면에 없다" 0 "$(g panel_gone)"
    expect "옛 목록 자리 #gr-orders 도 없다" 0 "$(g orders_host_gone)"
    expect "「발주서 불러오기」 단추가 보인다" 1 "$(g open_btn)"
    expect "단추 이름이 「발주서 불러오기」" "발주서 불러오기" "$(g open_btn_text)"
    expect "입고 줄 빈 문구가 단추를 가리킨다" "「발주서 불러오기」로 입고할 줄을 고르세요." "$(g line_empty)"
    expect "처음엔 창이 닫혀 있다" 1 "$(g modal_closed_at_first)"
    expect "★ 진입 시 gr/load 요청 **0회**" 0 "$(g calls_on_enter)"

    sub "(f) 단추 → 창 열림. 조회는 아직 0회"
    expect "창이 열렸다" 1 "$(g modal_open)"
    expect "★ 창을 열 때도 gr/load 요청 **0회**" 0 "$(g calls_on_open)"
    expect "묶는 기준 칩이 여섯" 6 "$(py "len(d['chips'])")"
    expect "칩 이름이 여섯 기준이다" \
      "['거래처', '입고 장소', '담당자', '지점', '분류', '전표']" "$(g chips)"
    expect "거래처·검색·납기지남 걸개가 **창 안**으로 들어갔다" "[True, True, True]" "$(g filters_in_modal)"

    sub "(f) 기본이 「전표」 · 창 안 「불러오기」 → 1회 조회"
    expect "★ 기본 묶는 기준이 **전표**다 (발주요청 불러오듯 전표로 뜬다)" "['doc']" \
      "$(g chips_on_default)"
    expect "왼쪽 패널 제목이 「발주서」" "발주서" "$(g tree_panel_title)"
    expect "빈 문구가 「왼쪽에서 발주서를 고르세요.」" "왼쪽에서 발주서를 고르세요." "$(g pick_text)"
    expect "★ 불러오기 뒤 gr/load 요청 **1회**" 1 "$(g calls_after_run)"
    expect "그 요청에 ?group=doc 가 실렸다" 1 \
      "$(py "int(all('group=doc' in u for u in d['call_urls']))")"
    expect "그 요청에 검색어가 실렸다" 1 \
      "$(py "int(all('q=F63UI' in u for u in d['call_urls']))")"

    sub "(g) 전표 노드 · 묶음 체크 · 중간 상태 · 여러 전표"
    expect "트리에 전표 노드 2개" 2 "$(g nodes)"
    expect "노드 이름이 「PO 번호 · 일자」" 1 \
      "$(py "int(all(x.startswith('PO-') and ' · ' in x for x in d['node_labels']))")"
    expect "노드마다 잔량 소계가 보인다" 1 \
      "$(py "int(all(x.startswith('잔량 ') for x in d['node_sums']))")"
    expect "노드를 체크하니 고른 줄이 생겼다" 1 \
      "$(py "int('고른 줄 0개' != (d['info_node0'] or ''))")"
    expect "그 노드의 줄이 전부 체크되었다" 1 \
      "$(py "int(len(d['row_checked']) > 0 and all(d['row_checked']))")"
    expect "담을 수량이 잔량으로 채워졌다 (빈 칸 0)" 0 \
      "$(py "sum(1 for v in d['row_qtys'] if v in ('', '0'))")"
    expect "한 줄을 해제하니 노드가 **중간 상태**" 1 "$(g node0_indeterminate)"
    expect "그때 노드 체크는 꺼져 있다" 0 "$(g node0_checked_partial)"
    expect "다시 체크하니 노드가 켜졌다" 1 "$(g node0_checked_full)"
    expect "두 노드를 체크하니 고른 줄이 2개 이상" 1 \
      "$(py "int(int(re.sub(r'[^0-9]', '', d['info_both'] or '0') or 0) >= 2)")"
    expect "담은 뒤 창이 닫힌다" 1 "$(g modal_closed_after_add)"
    expect "입고 줄 표에 3줄이 들어왔다 (PO1 두 줄 + PO2 한 줄)" 3 "$(g line_rows)"
    expect "★ **여러 전표**가 한 번에 담겼다 (출처 발주서 두 가지)" 2 \
      "$(py "len(set(d['line_docs']))")"
    expect "담기는 조회를 더 하지 않는다 (여전히 1회)" 1 "$(g calls_after_add)"

    sub "(h) 같은 줄 두 번 담아도 한 줄 · 입고 확정은 기존 경로"
    expect "창을 다시 열 때도 조회 0회 (여전히 1회 누계)" 1 "$(g calls_on_reopen)"
    expect "같은 줄을 두 번 담아도 입고 줄은 3줄 그대로" 3 "$(g line_rows_after_twice)"
    expect "입고 확정 알림에 「입고를 확정했습니다」" 1 \
      "$(py "int('입고를 확정했습니다' in (d['msg'] or ''))")"
    expect "알림에 입고 전표 번호(GR-…)가 있다" 1 \
      "$(py "int(bool(re.search(r'GR-[0-9]{8}-[0-9]+', d['msg'] or '')))")"
    expect "저장 결과 카드가 보인다" 1 "$(g result_visible)"
    expect "콘솔 오류 0건" 0 "$(py "len(d['console_errors'])")"
    echo "  스크린샷: $OUT/shots"
  fi
fi
[ "$SCREEN_OK" = "1" ] || echo "  (f)(g)(h) 결과: 실행하지 못함 — 보고서에 SKIP 으로 적을 것"

sub "(h) DB 확인 — 화면이 만든 입고 전표가 두 발주서를 출처로 갖는다"
expect "화면이 만든 입고 전표 1장" 1 \
  "$(psqlq "SELECT count(*) FROM p_docs d WHERE d.doc_type='gr' AND d.created_by IN ($QA_IDS)
             AND EXISTS (SELECT 1 FROM p_doc_lines l JOIN p_doc_lines s ON s.id = l.src_line_id
                         WHERE l.doc_id = d.id AND s.doc_id IN ($UI_PO1,$UI_PO2));")"
expect "그 전표의 줄이 **두 발주서**에서 왔다" 2 \
  "$(psqlq "SELECT count(DISTINCT s.doc_id) FROM p_doc_lines l JOIN p_doc_lines s ON s.id = l.src_line_id
             JOIN p_docs d ON d.id = l.doc_id
             WHERE d.doc_type='gr' AND s.doc_id IN ($UI_PO1,$UI_PO2);")"
expect "입고 확정 뒤 두 발주서의 미입고가 0" 0 \
  "$(psqlq "SELECT count(*) FROM p_backorder WHERE po_doc_id IN ($UI_PO1,$UI_PO2);")"

# ═════════════════════════════════════════════════════════════
hr "T-75 (i) 회귀의 뼈대 — 발주요청 불러오기 응답이 그대로인가 (묶기 한 벌 도입 뒤)"
# ═════════════════════════════════════════════════════════════
#
# 회귀 스크립트 자체(purchase_stage21.sh · purchase_f45.sh · purchase_f60.sh ·
# purchase_f61.sh · purchase_f62.sh)는 **따로 돌린다**. 여기서는 F-63 이 건드린
# 창구 하나(`pr/load`)의 응답 모양이 20단계와 같은지를 직접 본다.

R=$(call qa_manager01 GET "/api/purchase/pr/load?group=vendor,doc&q=F63" "$TOK_MGR")
B=$(body_of "$R")
echo "  HTTP $(code_of "$R")"; printf '  %s\n' "$(printf '%s' "$B" | head -c 400)"
expect "발주요청 불러오기 200" 200 "$(code_of "$R")"
expect "응답 키가 20단계와 똑같이 다섯" \
  "['group', 'groups', 'line_count', 'qty', 'truncated']" "$(jq_py "$B" "list(d.keys())")"
expect "트리 키는 여전히 `groups` 다 (`nodes` 가 아니다)" 0 "$(jq_py "$B" "int('nodes' in d)")"
expect "노드 키 순서가 group·key·label·line_count·qty·children" \
  "['group', 'key', 'label', 'line_count', 'qty', 'children']" \
  "$(jq_py "$B" "list(d['groups'][0].keys()) if d['groups'] else '빈 트리'")"
expect "마지막 층의 노드 키 순서는 …·lines" \
  "['group', 'key', 'label', 'line_count', 'qty', 'lines']" \
  "$(jq_py "$B" "list(d['groups'][0]['children'][0].keys()) if d['groups'] else '빈 트리'")"
expect "수량 소계가 소수 셋째 자리 문자열" 1 \
  "$(jq_py "$B" "int(all(isinstance(n['qty'], str) and n['qty'].count('.') == 1
                          and len(n['qty'].split('.')[1]) == 3 for n in d['groups']))")"
expect "group 없이도 기본이 거래처 한 층이다 (20단계 그대로)" "['vendor']" \
  "$(jq_py "$(body_of "$(call qa_manager01 GET "/api/purchase/pr/load?q=F63" "$TOK_MGR")")" "d.get('group')")"
expect "모르는 값만 주면 기본 거래처로 돌아간다" "['vendor']" \
  "$(jq_py "$(body_of "$(call qa_manager01 GET "/api/purchase/pr/load?group=bogus&q=F63" "$TOK_MGR")")" "d.get('group')")"

# ═════════════════════════════════════════════════════════════
hr "T-75 (j) 정적 점검 — 묶기·트리 코드가 **한 벌**인가 · 린트 · php -l"
# ═════════════════════════════════════════════════════════════

ROOT="$(cd "$(dirname "$0")/.." && pwd)"

sub "php -l"
for f in "$ROOT/src/purchase/Grouping.php" "$ROOT/src/handlers/purchase_gr_load.php" \
         "$ROOT/src/handlers/purchase_pr_load.php" "$ROOT/src/views/purchase_receipt.php"; do
  NAME=$(basename "$f")
  php -l "$f" > /dev/null 2>&1
  RC=$?
  expect "php -l $NAME" 0 "$RC"
done

sub "묶기는 서버에 한 벌 (Grouping.php)"
expect "Grouping::tree 가 한 곳에만 있다" 1 \
  "$(grep -cF 'function tree' "$ROOT/src/purchase/Grouping.php")"
expect "두 창구가 그것을 **부르기만** 한다" 2 \
  "$(grep -cF 'Grouping::tree(' "$ROOT/src/handlers/purchase_pr_load.php" "$ROOT/src/handlers/purchase_gr_load.php" \
      | awk -F: '{s+=$2} END {print s+0}')"
expect "핸들러에 재귀 묶기 클로저(\$build)가 남아 있지 않다" 0 \
  "$(grep -cF '$build' "$ROOT/src/handlers/purchase_pr_load.php" "$ROOT/src/handlers/purchase_gr_load.php" \
      | awk -F: '{s+=$2} END {print s+0}')"
expect "Grouping 에 DB 접근이 없다" 0 \
  "$(grep -cE 'Db::|->run\(|FROM p_' "$ROOT/src/purchase/Grouping.php")"
expect "Grouping 에 SQL 문장이 없다" 0 \
  "$(grep -cE '\b(SELECT|INSERT|UPDATE|DELETE)\b' "$ROOT/src/purchase/Grouping.php")"
expect "Grouping 에 전역·정적 상태가 없다" 0 \
  "$(grep -cE '^\s*(static|global) \$|\bstatic \$[a-z]' "$ROOT/src/purchase/Grouping.php")"

sub "묶는 기준은 **코드에 적힌 여섯**이고 SQL 에 닿지 않는다"
expect "gr_load 의 화이트리스트가 여섯" 1 \
  "$(grep -cF "const TBOX_GR_LOAD_GROUPS = ['vendor', 'location', 'manager', 'branch', 'category', 'doc'];" \
      "$ROOT/src/handlers/purchase_gr_load.php")"
expect "그 값을 in_array 로만 고른다" 1 \
  "$(grep -cF 'in_array($g, TBOX_GR_LOAD_GROUPS, true)' "$ROOT/src/handlers/purchase_gr_load.php")"
expect "group 변수가 SQL 조각과 같은 줄에 나타나지 않는다" 0 \
  "$(grep -cE "(WHERE|ORDER BY|GROUP BY|SELECT).*\\\$group" "$ROOT/src/handlers/purchase_gr_load.php")"
expect "gr_load 가 지점 격리를 부른다 (R-65)" 1 \
  "$(grep -cF "\$scope = Authz::purchaseScopeWhere(\$user, 'd');" "$ROOT/src/handlers/purchase_gr_load.php")"
expect "「보낸 발주서만」 정의는 뷰 p_backorder 그대로다" 1 \
  "$(grep -cF 'sent_at IS NOT NULL' "$ROOT/db/schema.sql")"
expect "gr_load 에 감사 문장이 없다 (조회는 감사하지 않는다)" 0 \
  "$(grep -cE 'Audit::(allow|deny)' "$ROOT/src/handlers/purchase_gr_load.php")"
expect "상한 300 이 그대로다" 1 \
  "$(grep -cF 'const TBOX_GR_LOAD_MAX = 300;' "$ROOT/src/handlers/purchase_gr_load.php")"
expect "입고 확정 핸들러는 손대지 않았다 (단가·금액 수신 0)" 0 \
  "$(grep -cE "^const TBOX_[A-Z_]*KEYS?[A-Z_]* *=.*'(unit_price|amount)'" \
      "$ROOT/src/handlers/purchase_gr_create.php")"

sub "화면도 한 벌 (UI.loadPicker)"
expect "loadPicker 가 공통 파일에만 있다" 1 \
  "$(grep -cF 'function loadPicker' "$ROOT/public/assets/purchase_common.js")"
expect "두 화면은 그것을 **부르기만** 한다" 2 \
  "$(grep -cF 'UI.loadPicker(' "$ROOT/public/assets/purchase_docs.js" "$ROOT/public/assets/purchase_stock.js" \
      | awk -F: '{s+=$2} END {print s+0}')"
expect "트리 그리기가 화면 번들에 남아 있지 않다" 0 \
  "$(grep -cF 'function drawTree' "$ROOT/public/assets/purchase_docs.js" "$ROOT/public/assets/purchase_stock.js" \
      | awk -F: '{s+=$2} END {print s+0}')"
expect "묶음 체크 목록(nodeChecks)도 공통 파일에만 있다" 0 \
  "$(grep -cF 'nodeChecks' "$ROOT/public/assets/purchase_docs.js" "$ROOT/public/assets/purchase_stock.js" \
      | awk -F: '{s+=$2} END {print s+0}')"
expect "중간 상태(indeterminate)를 공통 함수가 들고 있다" 1 \
  "$(grep -cF 'indeterminate' "$ROOT/public/assets/purchase_common.js")"
expect "ItemPicker 도 여전히 한 벌이다 (F-61 회귀)" 1 \
  "$(grep -cF 'function itemPicker' "$ROOT/public/assets/purchase_common.js")"
expect "입고 화면에 진입 자동 조회가 없다 (loadOrders 호출 0)" 0 \
  "$(grep -cE '^\s*loadOrders\(\);' "$ROOT/public/assets/purchase_stock.js")"
expect "「발주서 불러오기」 단추는 창만 연다" 1 \
  "$(grep -cF "\$('gr-load-open').addEventListener" "$ROOT/public/assets/purchase_stock.js")"
for f in purchase_common.js purchase_docs.js purchase_stock.js; do
  JSBAD=$(grep -cE 'innerHTML|outerHTML|document\.write\(' "$ROOT/public/assets/$f"); JSBAD=${JSBAD:-0}
  expect "$f 에 innerHTML·document.write 0건" 0 "$JSBAD"
done

sub "화면 틀 — 패널은 없고 창이 있다 · 새 CSS 클래스 0"
expect "purchase_receipt.php 에 #gr-load 패널이 없다" 0 \
  "$(grep -cF 'id="gr-load"' "$ROOT/src/views/purchase_receipt.php")"
expect "「발주서 불러오기」 단추가 있다" 1 \
  "$(grep -cF 'id="gr-load-open"' "$ROOT/src/views/purchase_receipt.php")"
expect "불러오기 창(dialog)이 있다" 1 \
  "$(grep -cF 'id="gr-load-modal"' "$ROOT/src/views/purchase_receipt.php")"
expect "칩바에 data-group 여섯" 6 \
  "$(sed -n '/id="grload-groups"/,/<\/span>/p' "$ROOT/src/views/purchase_receipt.php" | grep -cF 'data-group=')"
expect "purchase.css 는 무수정이다 (F-63 흔적 0)" 0 \
  "$(grep -cE 'F-63|grload' "$ROOT/public/assets/purchase.css")"
expect "창에 쓰는 클래스가 전부 기존 것이다" 1 \
  "$(python3 - "$ROOT" <<'PY'
import re, sys
root = sys.argv[1]
view = open(root + '/src/views/purchase_receipt.php', encoding='utf-8').read()
s = view.index('id="gr-load-modal"')
e = view.index('</dialog>', s)
used = set()
for m in re.finditer(r'class="([^"]+)"', view[s:e]):
    for c in m.group(1).split():
        used.add(c)
css = open(root + '/public/assets/purchase.css', encoding='utf-8').read() \
    + open(root + '/public/assets/app.css', encoding='utf-8').read()
missing = sorted(c for c in used if ('.' + c) not in css)
print(0 if missing else 1, end='')
if missing:
    print('  없는 클래스: ' + ', '.join(missing), file=sys.stderr)
PY
)"

sub "린트"
if bash "$ROOT/scripts/security_lint.sh" > "$OUT/lint.txt" 2>&1; then
  expect "security_lint.sh 0건" 0 0
else
  expect "security_lint.sh 0건" 0 1
fi
tail -6 "$OUT/lint.txt" | sed 's/^/  /'

sub "화면 시각 점검 — 입고 화면 1440px (결함 0)"
SHOT="$OUT/1440"; mkdir -p "$SHOT"
LOG="$OUT/ui-1440.txt"
if UI_PAGES="/purchase/receipt,/purchase/po" \
     bash "$ROOT/scripts/ui_snapshot.sh" "$SHOT" 1440 > "$LOG" 2>&1; then
  cat "$LOG"
  TOTAL=$(sed -n 's/^합계 \([0-9]*\)건$/\1/p' "$LOG")
  expect "입고·발주서 두 화면 결함 합계 0건" 0 "${TOTAL:-없음}"
  if [ "${TOTAL:-1}" != "0" ]; then
    python3 - "$SHOT/report.json" <<'PY'
import json, sys
report = json.load(open(sys.argv[1]))
for path, shots in report.items():
    for name, audit in shots.items():
        for key in ('clipped', 'overlap', 'row_height', 'offscreen',
                    'undefined_class', 'dup_id', 'stage_text', 'console_errors'):
            found = audit.get(key) or []
            if found:
                print('   ', path, '[' + name + ']', key, '=', found)
        if audit.get('overflow_x'):
            print('   ', path, '[' + name + ']', 'overflow_x = True')
PY
  fi
else
  echo "  ■ ui_snapshot.sh 실행 실패 — 아래 원문"; cat "$LOG"; FAIL=$((FAIL+1))
fi

sub "전표 번호 중복 0건"
expect "중복 전표 번호 0건" 0 \
  "$(psqlq "SELECT count(*) FROM (SELECT doc_no FROM p_docs GROUP BY doc_no HAVING count(*) > 1) x;")"
psqlq "SELECT '  '||doc_type||' '||doc_no||' vendor='||coalesce(vendor_id::text,'-')
             ||' loc='||coalesce(to_location_id::text,'-')||' sent='||coalesce(sent_at::text,'-')
         FROM p_docs WHERE created_by IN ($QA_IDS) ORDER BY id;"
