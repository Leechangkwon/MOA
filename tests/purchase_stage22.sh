#!/usr/bin/env bash
# tests/purchase_stage22.sh — 22단계 수락 기준 실행
#   (PRD 12.2 T-47 + F-34 주간 사용량 · F-35 월 대사·월 마감·기공 명세서
#    · SECURITY R-65 · R-66 · R-69 · R-73)
#
# 규칙 (CLAUDE.md 3장)
#   · **검토 계정(qa_ 접두)만** 쓴다. 시드 계정(admin01 …)은 사용자의 자기 공격 전용이다.
#   · 모든 요청에 `-A "tbox-review/<날짜>"` 를 붙인다 — 감사 로그에서 사람의 행동과 갈라 보려는 것.
#   · 비밀번호·TOTP 비밀은 **필요한 필드만 awk 로** 뽑는다. 파일을 통째로 출력(cat)하지 않고,
#     값은 변수에만 담아 화면·로그 어디에도 찍지 않는다.
#
# ★ 시험 안전 (SECURITY R-73). 20·21단계 스크립트와 **같은 규칙**이다.
#   ① **사용자가 활동 중이면 시작하지 않는다.** 최근 10분 감사 로그에 비-qa actor 가 하나라도
#      있으면 아무것도 하지 않고 **종료 코드 3** 으로 끝낸다.
#   ② **정리는 qa 계정이 만든 행만.** `created_by` 가 검토 계정인 행만 지운다.
#      표 전체 DELETE·TRUNCATE 는 이 파일에 한 줄도 없다.
#   ③ 시작과 끝에 **비-qa 행 수**를 찍고, 달라졌으면 실패로 센다.
#   ④ 픽스처 이름은 전부 `S22-` 접두다 — 사용자의 실데이터와 이름이 겹쳐 409 가 나지 않게.
#
# ★ 원장(p_stock_moves)·대사표·마감 행의 정리는 **postgres 로** 한다(앱 계정에는 DELETE 권한이
#   없다). 그래도 지우는 범위는 「qa 계정이 만든 것」뿐이다. **월 마감 행은 qa 계정이 만든
#   것만** 지운다 — 사용자가 마감한 달을 되살리는 일이 없어야 한다.
#
# 사용: bash tests/purchase_stage22.sh
set -u

BASE="http://127.0.0.1:8082"
UA="tbox-review/$(date +%F)"
DB="tbox_re"
JAR_DIR="$(mktemp -d)"
TMP_DIR="$(mktemp -d)"
PASS=0
FAIL=0
TODAY="$(date +%Y-%m-%d)"
MONTH="$(date +%Y-%m)"

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
  rm -rf "$JAR_DIR" "$TMP_DIR"
  exit 3
fi
echo "■ 최근 10분 비-qa 활동 0건 — 시험을 시작합니다."

# ─── 로그인 (21단계와 같은 함수) ─────────────────────────────
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

upload_file() {
  local who="$1" path="$2" token="$3" file="$4" vendor="$5" month="$6"
  curl -s -w '\n%{http_code}' -X POST -b "$JAR_DIR/$who.jar" -A "$UA" \
    -H "X-CSRF-Token: $token" \
    -F "vendor_id=$vendor" -F "month=$month" -F "file=@$file" "$BASE$path"
}

code_of() { printf '%s' "$1" | tail -1; }
body_of() { printf '%s' "$1" | sed '$d'; }
new_id()  { printf '%s' "$1" | grep -o '"id":[0-9]*' | head -1 | cut -d: -f2; }
jnum()    { printf '%s' "$2" | grep -o "\"$1\":-\?[0-9.]*" | head -1 | cut -d: -f2; }
jstr()    { printf '%s' "$2" | sed -n 's/.*"'"$1"'":"\([^"]*\)".*/\1/p' | head -1; }

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
  for t in p_brands p_branches p_locations p_vendors p_categories p_items p_par_levels p_docs \
           p_recon_statements; do
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
  total=$(psqlq "SELECT count(*) FROM p_recon_lines;")
  nonqa=$(psqlq "SELECT count(*) FROM p_recon_lines r JOIN p_recon_statements s ON s.id = r.statement_id
                  WHERE s.created_by NOT IN ($QA_IDS);")
  printf '  %-20s %8s %10s\n' "p_recon_lines" "$total" "$nonqa"
  sum=$((sum + nonqa))
  total=$(psqlq "SELECT count(*) FROM p_month_close;")
  nonqa=$(psqlq "SELECT count(*) FROM p_month_close WHERE closed_by NOT IN ($QA_IDS);")
  printf '  %-20s %8s %10s\n' "p_month_close" "$total" "$nonqa"
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
         ' p_month_close='||(SELECT count(*) FROM p_month_close WHERE closed_by NOT IN ($qa_ids))||
         ' p_recon_statements='||(SELECT count(*) FROM p_recon_statements WHERE created_by NOT IN ($qa_ids))||
         ' — 이 행들은 그대로 둡니다';"

  psqlc "UPDATE users SET branch_id = NULL, purchase_role = NULL
          WHERE username IN ('qa_employee01','qa_employee02','qa_manager01');" \
    && echo "  qa 계정 branch_id·purchase_role 원복"

  # 22단계 표 먼저 — 대사 줄이 전표 줄을 참조한다.
  psqlc "DELETE FROM p_recon_lines
          WHERE statement_id IN (SELECT id FROM p_recon_statements WHERE created_by IN ($qa_ids));
         DELETE FROM p_recon_statements WHERE created_by IN ($qa_ids);
         DELETE FROM p_month_close      WHERE closed_by  IN ($qa_ids);" \
    && echo "  검토 계정이 만든 대사·마감 행 삭제"

  # 21·20·19단계 정리 (21단계 스크립트와 같은 순서·같은 범위)
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

  # 명세서 파일 행 — 이 시험이 올린 것만 (설명 문자열 + 올린 사람으로 좁힌다).
  # 실제 바이트는 저장소에 남는다(앱에도 지우는 길이 없다 — 파일 삭제는 논리 삭제다).
  psqlc "UPDATE files SET deleted_at = now()
          WHERE deleted_at IS NULL
            AND description = '거래처 명세서 원본'
            AND uploaded_by IN ($qa_ids)
            AND original_name LIKE 'S22-%';" \
    && echo "  시험이 올린 명세서 파일을 휴지통으로 (행은 남는다)"

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

hr "1. 기초정보 씨앗 (전부 S22- 접두)"

R=$(call qa_admin01 POST /api/purchase/brands "$TOK_ADMIN" \
  '{"name":"S22-치과","biz_no":"322-45-67890","ceo_name":"S22 대표","address":"인천 부평구","tel":"032-222-2222"}')
BRAND=$(new_id "$(body_of "$R")"); expect "브랜드 생성" 201 "$(code_of "$R")"

R=$(call qa_admin01 POST /api/purchase/branches "$TOK_ADMIN" "{\"brand_id\":$BRAND,\"name\":\"S22-부평점\"}")
BR_A=$(new_id "$(body_of "$R")"); expect "지점A 생성" 201 "$(code_of "$R")"
R=$(call qa_admin01 POST /api/purchase/branches "$TOK_ADMIN" "{\"brand_id\":$BRAND,\"name\":\"S22-일산점\"}")
BR_B=$(new_id "$(body_of "$R")"); expect "지점B 생성" 201 "$(code_of "$R")"

R=$(call qa_admin01 POST /api/purchase/locations "$TOK_ADMIN" \
  "{\"branch_id\":$BR_A,\"kind\":\"warehouse\",\"name\":\"S22-B1F 창고\"}")
LOC_WH=$(new_id "$(body_of "$R")"); expect "지점A 창고 생성" 201 "$(code_of "$R")"
R=$(call qa_admin01 POST /api/purchase/locations "$TOK_ADMIN" \
  "{\"branch_id\":$BR_A,\"kind\":\"or\",\"name\":\"S22-수술방\"}")
LOC_OR=$(new_id "$(body_of "$R")"); expect "지점A 수술방 생성" 201 "$(code_of "$R")"
R=$(call qa_admin01 POST /api/purchase/locations "$TOK_ADMIN" \
  "{\"branch_id\":$BR_B,\"kind\":\"warehouse\",\"name\":\"S22-일산 창고\"}")
LOC_B=$(new_id "$(body_of "$R")"); expect "지점B 창고 생성" 201 "$(code_of "$R")"

R=$(call qa_admin01 POST /api/purchase/vendors "$TOK_ADMIN" '{"name":"S22-더존덴탈","code":"S22V1"}')
VENDOR=$(new_id "$(body_of "$R")"); expect "거래처 생성" 201 "$(code_of "$R")"

R=$(call qa_admin01 POST /api/purchase/categories "$TOK_ADMIN" '{"name":"S22-소모품","level":1}')
CAT1=$(new_id "$(body_of "$R")"); expect "대분류 생성" 201 "$(code_of "$R")"
R=$(call qa_admin01 POST /api/purchase/categories "$TOK_ADMIN" '{"name":"S22-기공","level":1}')
CAT1B=$(new_id "$(body_of "$R")"); expect "대분류2 생성" 201 "$(code_of "$R")"

R=$(call qa_admin01 POST /api/purchase/items "$TOK_ADMIN" \
  "{\"vendor_id\":$VENDOR,\"code\":\"S22-A\",\"name\":\"S22 gauze 2x2\",\"unit\":\"EA\",\"price_in\":\"1000\",\"stock_managed\":true,\"cat1_id\":$CAT1}")
ITEM_A=$(new_id "$(body_of "$R")"); expect "품목A(재고관리 O) 생성" 201 "$(code_of "$R")"
R=$(call qa_admin01 POST /api/purchase/items "$TOK_ADMIN" \
  "{\"vendor_id\":$VENDOR,\"code\":\"S22-B\",\"name\":\"S22 기공료\",\"unit\":\"EA\",\"price_in\":\"5000\",\"stock_managed\":false,\"cat1_id\":$CAT1B}")
ITEM_B=$(new_id "$(body_of "$R")"); expect "품목B(재고관리 X) 생성" 201 "$(code_of "$R")"
R=$(call qa_admin01 POST /api/purchase/items "$TOK_ADMIN" \
  "{\"vendor_id\":$VENDOR,\"code\":\"S22-C\",\"name\":\"S22 cotton roll\",\"unit\":\"EA\",\"price_in\":\"2000\",\"stock_managed\":true,\"cat1_id\":$CAT1}")
ITEM_C=$(new_id "$(body_of "$R")"); expect "품목C 생성" 201 "$(code_of "$R")"
R=$(call qa_admin01 POST /api/purchase/items "$TOK_ADMIN" \
  "{\"vendor_id\":$VENDOR,\"code\":\"S22-D\",\"name\":\"S22 알콜솜\",\"unit\":\"EA\",\"price_in\":\"4000\",\"stock_managed\":true,\"cat1_id\":$CAT1}")
ITEM_D=$(new_id "$(body_of "$R")"); expect "품목D 생성 (명세서에는 없을 품목)" 201 "$(code_of "$R")"
R=$(call qa_admin01 POST /api/purchase/items "$TOK_ADMIN" \
  "{\"vendor_id\":$VENDOR,\"code\":\"S22-F\",\"name\":\"S22 bur FG\",\"unit\":\"EA\",\"price_in\":\"7000\",\"stock_managed\":true,\"cat1_id\":$CAT1}")
ITEM_F=$(new_id "$(body_of "$R")"); expect "품목F 생성 (단가 차이가 날 품목)" 201 "$(code_of "$R")"

sub "계정 두 축 (qa_employee01 = 지점A stock · qa_employee02 = 지점B requester · qa_manager01 = buyer)"
UID1=$(psqlq "SELECT id FROM users WHERE username = 'qa_employee01';")
UID2=$(psqlq "SELECT id FROM users WHERE username = 'qa_employee02';")
UID3=$(psqlq "SELECT id FROM users WHERE username = 'qa_manager01';")
psqlc "UPDATE users SET branch_id = $BR_A, purchase_role = 'stock'     WHERE id = $UID1;
       UPDATE users SET branch_id = $BR_B, purchase_role = 'requester' WHERE id = $UID2;
       UPDATE users SET branch_id = NULL,  purchase_role = 'buyer'     WHERE id = $UID3;"
echo "  qa_employee01 branch=$BR_A role=stock / qa_employee02 branch=$BR_B role=requester / qa_manager01 buyer"

hr "2. 입고 — 주간 사용량·대사의 재료 (발주요청 → 발주서 → 보냄 → 입고)"

R=$(call qa_manager01 POST /api/purchase/pr "$TOK_MGR" \
  "{\"branch_id\":$BR_A,\"to_location_id\":$LOC_WH,\"vendor_id\":$VENDOR,\"lines\":[{\"item_id\":$ITEM_A,\"qty\":\"50\"},{\"item_id\":$ITEM_C,\"qty\":\"30\"},{\"item_id\":$ITEM_D,\"qty\":\"20\"},{\"item_id\":$ITEM_F,\"qty\":\"10\"}]}")
PR_ID=$(new_id "$(body_of "$R")"); expect "발주요청 생성" 201 "$(code_of "$R")"
PRL_A=$(printf '%s' "$(body_of "$R")" | grep -o '"id":[0-9]*' | sed -n '2p' | cut -d: -f2)
PRL_C=$(printf '%s' "$(body_of "$R")" | grep -o '"id":[0-9]*' | sed -n '3p' | cut -d: -f2)
PRL_D=$(printf '%s' "$(body_of "$R")" | grep -o '"id":[0-9]*' | sed -n '4p' | cut -d: -f2)
PRL_F=$(printf '%s' "$(body_of "$R")" | grep -o '"id":[0-9]*' | sed -n '5p' | cut -d: -f2)

R=$(call qa_manager01 POST /api/purchase/po "$TOK_MGR" \
  "{\"vendor_id\":$VENDOR,\"to_location_id\":$LOC_WH,\"lines\":[{\"src_line_id\":$PRL_A,\"qty\":\"50\"},{\"src_line_id\":$PRL_C,\"qty\":\"30\"},{\"src_line_id\":$PRL_D,\"qty\":\"20\"},{\"src_line_id\":$PRL_F,\"qty\":\"10\"}]}")
PO_ID=$(new_id "$(body_of "$R")"); expect "발주서 생성" 201 "$(code_of "$R")"
POL_A=$(printf '%s' "$(body_of "$R")" | grep -o '"id":[0-9]*' | sed -n '2p' | cut -d: -f2)
POL_C=$(printf '%s' "$(body_of "$R")" | grep -o '"id":[0-9]*' | sed -n '3p' | cut -d: -f2)
POL_D=$(printf '%s' "$(body_of "$R")" | grep -o '"id":[0-9]*' | sed -n '4p' | cut -d: -f2)
POL_F=$(printf '%s' "$(body_of "$R")" | grep -o '"id":[0-9]*' | sed -n '5p' | cut -d: -f2)

R=$(call qa_manager01 POST "/api/purchase/po/$PO_ID/send" "$TOK_MGR" '{}')
expect "발주서 보냄" 200 "$(code_of "$R")"

R=$(call qa_employee01 POST /api/purchase/gr "$TOK_EMP1" \
  "{\"lines\":[{\"src_line_id\":$POL_A,\"qty\":\"50\"},{\"src_line_id\":$POL_C,\"qty\":\"30\"},{\"src_line_id\":$POL_D,\"qty\":\"20\"},{\"src_line_id\":$POL_F,\"qty\":\"10\"}]}")
GR_ID=$(new_id "$(body_of "$R")"); expect "입고 확정 (A 50 · C 30 · D 20 · F 10)" 201 "$(code_of "$R")"

sub "사용(IS) 3건 — 창고 → 수술방으로 옮긴 뒤 쓴다"
R=$(call qa_employee01 POST /api/purchase/mv "$TOK_EMP1" \
  "{\"from_location_id\":$LOC_WH,\"to_location_id\":$LOC_OR,\"lines\":[{\"item_id\":$ITEM_A,\"qty\":\"20\"},{\"item_id\":$ITEM_C,\"qty\":\"10\"}]}")
expect "이동 201" 201 "$(code_of "$R")"

R=$(call qa_employee01 POST /api/purchase/is "$TOK_EMP1" \
  "{\"from_location_id\":$LOC_OR,\"lines\":[{\"item_id\":$ITEM_A,\"qty\":\"5\"}]}")
expect "사용① 201" 201 "$(code_of "$R")"
R=$(call qa_employee01 POST /api/purchase/is "$TOK_EMP1" \
  "{\"from_location_id\":$LOC_OR,\"lines\":[{\"item_id\":$ITEM_A,\"qty\":\"3\"}]}")
expect "사용② 201" 201 "$(code_of "$R")"
R=$(call qa_employee01 POST /api/purchase/is "$TOK_EMP1" \
  "{\"from_location_id\":$LOC_OR,\"lines\":[{\"item_id\":$ITEM_C,\"qty\":\"4\"}]}")
expect "사용③ 201" 201 "$(code_of "$R")"

hr "3. T-47 (d) — 기공 명세서 → 입고 전표 (재고관리 안 하는 품목)"

R=$(call qa_manager01 POST /api/purchase/lab "$TOK_MGR" \
  "{\"vendor_id\":$VENDOR,\"to_location_id\":$LOC_WH,\"doc_date\":\"$TODAY\",\"lines\":[{\"item_id\":$ITEM_B,\"qty\":\"2\"}]}")
LAB_BODY=$(body_of "$R")
LAB_ID=$(new_id "$LAB_BODY")
expect "기공 명세서 전표 201" 201 "$(code_of "$R")"
echo "  $LAB_BODY" | head -c 300; echo
expect "원장 행 2개 (+ 와 −)" 2 "$(jnum moves "$LAB_BODY")"
MOVE_SUM=$(psqlq "SELECT coalesce(sum(m.qty_delta),0)::text FROM p_stock_moves m
                   JOIN p_doc_lines l ON l.id = m.doc_line_id WHERE l.doc_id = $LAB_ID;")
expect "순변화 0 (현재고는 늘지 않는다)" "0.000" "$MOVE_SUM"

sub "재고관리 품목을 섞으면 400 stock_managed_item"
R=$(call qa_manager01 POST /api/purchase/lab "$TOK_MGR" \
  "{\"vendor_id\":$VENDOR,\"to_location_id\":$LOC_WH,\"lines\":[{\"item_id\":$ITEM_A,\"qty\":\"1\"}]}")
expect "재고관리 품목 섞임 → 400" 400 "$(code_of "$R")"
REASON=$(psqlq "SELECT reason FROM audit_logs WHERE action='purchase.lab.create' AND result='deny'
                 ORDER BY id DESC LIMIT 1;")
expect "감사 사유 stock_managed_item" "stock_managed_item" "$REASON"

hr "4. T-47 (a) — 주간 사용량"

R=$(call qa_employee01 GET "/api/purchase/usage/weekly?group=cat1" "$TOK_EMP1")
WK_BODY=$(body_of "$R")
expect "주간 사용량 200" 200 "$(code_of "$R")"
echo "  $WK_BODY" | head -c 400; echo

# 표의 합 = 원장이 세는 사용량(IS 음수 행 + 비재고 GR 양수 행)과 같아야 한다
TABLE_SUM=$(printf '%s' "$WK_BODY" | grep -o '"qty":"[0-9.]*"' | cut -d'"' -f4 \
            | awk '{s+=$1} END {printf "%.3f", s+0}')
LEDGER_SUM=$(psqlq "SELECT to_char(coalesce(sum(CASE
                        WHEN d.doc_type='is' AND m.qty_delta < 0 THEN -m.qty_delta
                        WHEN d.doc_type='gr' AND NOT i.stock_managed AND m.qty_delta > 0 THEN m.qty_delta
                        ELSE 0 END),0), 'FM9999999990.000')
                     FROM p_stock_moves m
                     JOIN p_doc_lines l ON l.id = m.doc_line_id
                     JOIN p_docs      d ON d.id = l.doc_id
                     JOIN p_items     i ON i.id = m.item_id
                     JOIN p_locations lo ON lo.id = m.location_id
                    WHERE lo.branch_id IN ($BR_A, $BR_B);")
expect "표의 합 = 원장의 사용량" "$LEDGER_SUM" "$TABLE_SUM"

sub "requester(지점B)는 자기 지점 열만 — 지점A 사용량은 보이지 않는다"
R=$(call qa_employee02 GET "/api/purchase/usage/weekly?group=cat1" "$TOK_EMP2")
WK2=$(body_of "$R")
expect "requester 주간 사용량 200" 200 "$(code_of "$R")"
COUNT_A=$(printf '%s' "$WK2" | grep -c "\"branch_id\":$BR_A")
expect "지점A 칸 0건 (다른 지점)" 0 "$COUNT_A"

hr "5. T-47 (b) — 거래처 월 대사"

sub "명세서 xlsx 만들기 (5행: 일치 2 · 수량 차이 1 · 단가 차이 1 · 우리에게 없음 1)"
python3 - "$TMP_DIR/S22-statement.xlsx" <<'PY'
import sys, zipfile
rows = [
    ["품목코드", "품목명", "수량", "단가", "금액"],
    ["S22-A", "S22 gauze 2x2",  "50", "1000", "50000"],   # 일치
    ["S22-B", "S22 기공료",      "2", "5000", "10000"],   # 일치 (기공 명세서 전표와)
    ["S22-C", "S22 cotton roll", "28", "2000", "56000"],  # 수량 차이 (우리는 30)
    ["S22-F", "S22 bur FG",      "10", "9000", "90000"],  # 단가 차이 (우리는 7000 · +28%)
    ["S22-E", "S22 없는품목",     "10", "3000", "30000"],  # 우리에게 없음 (missing_ours)
]
# 우리 입고에만 있고 명세서에 없는 것: S22-D 20개 → missing_theirs
def esc(v):
    return v.replace("&","&amp;").replace("<","&lt;").replace(">","&gt;")
def col(i):
    return chr(ord('A') + i)
xml = ['<?xml version="1.0" encoding="UTF-8"?>',
       '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>']
for r, row in enumerate(rows, start=1):
    xml.append('<row r="%d">' % r)
    for c, val in enumerate(row):
        xml.append('<c r="%s%d" t="inlineStr"><is><t>%s</t></is></c>' % (col(c), r, esc(val)))
    xml.append('</row>')
xml.append('</sheetData></worksheet>')

ct = ('<?xml version="1.0" encoding="UTF-8"?>'
      '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
      '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
      '<Default Extension="xml" ContentType="application/xml"/>'
      '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
      '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
      '</Types>')
rels = ('<?xml version="1.0" encoding="UTF-8"?>'
        '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        '</Relationships>')
wb = ('<?xml version="1.0" encoding="UTF-8"?>'
      '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
      'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
      '<sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets></workbook>')
wbrels = ('<?xml version="1.0" encoding="UTF-8"?>'
          '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
          '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
          '</Relationships>')

with zipfile.ZipFile(sys.argv[1], "w", zipfile.ZIP_DEFLATED) as z:
    z.writestr("[Content_Types].xml", ct)
    z.writestr("_rels/.rels", rels)
    z.writestr("xl/workbook.xml", wb)
    z.writestr("xl/_rels/workbook.xml.rels", wbrels)
    z.writestr("xl/worksheets/sheet1.xml", "".join(xml))
print("made", sys.argv[1])
PY

R=$(upload_file qa_manager01 /api/purchase/recon "$TOK_MGR" "$TMP_DIR/S22-statement.xlsx" "$VENDOR" "$MONTH")
UP_BODY=$(body_of "$R")
expect "명세서 업로드 201" 201 "$(code_of "$R")"
STMT=$(jnum statement_id "$UP_BODY")
echo "  statement_id=$STMT  $(printf '%s' "$UP_BODY" | head -c 260)"

sub "보관 폴더는 admin-only 인가 (R-69)"
FLEVEL=$(psqlq "SELECT f.access_level FROM files f
                 JOIN p_recon_statements s ON s.file_id = f.id WHERE s.id = $STMT;")
expect "명세서 파일 등급 admin-only" "admin-only" "$FLEVEL"
FOLDER=$(psqlq "SELECT string_agg(name, '/' ORDER BY depth) FROM folders
                 WHERE id IN (SELECT folder_id FROM files f
                               JOIN p_recon_statements s ON s.file_id = f.id WHERE s.id = $STMT)
                    OR id IN (SELECT parent_id FROM folders WHERE id IN
                               (SELECT folder_id FROM files f
                                 JOIN p_recon_statements s ON s.file_id = f.id WHERE s.id = $STMT));")
echo "  보관 폴더(끝 두 단계): $FOLDER"

sub "허용되지 않은 열(연락처)을 매핑하면 400 — R-69"
R=$(call qa_manager01 POST "/api/purchase/recon/$STMT/preview" "$TOK_MGR" \
  '{"mapping":{"item_name":1,"qty":2,"contact":4},"header_row":0}')
expect "연락처 열 매핑 → 400" 400 "$(code_of "$R")"
REASON=$(psqlq "SELECT reason FROM audit_logs WHERE action='purchase.recon.preview' AND result='deny'
                 ORDER BY id DESC LIMIT 1;")
expect "감사 사유 bad_mapping" "bad_mapping" "$REASON"

sub "열을 맞춰 대조"
MAPPING='{"item_code":0,"item_name":1,"qty":2,"unit_price":3,"amount":4}'
R=$(call qa_manager01 POST "/api/purchase/recon/$STMT/preview" "$TOK_MGR" \
  "{\"mapping\":$MAPPING,\"header_row\":0}")
PV=$(body_of "$R")
expect "대조 미리보기 200" 200 "$(code_of "$R")"
echo "  $(printf '%s' "$PV" | grep -o '"diff_counts":{[^}]*}')"
echo "  $(printf '%s' "$PV" | grep -o '"totals":{[^}]*}')"

DC=$(printf '%s' "$PV" | grep -o '"diff_counts":{[^}]*}')
expect "일치 2건"        2 "$(jnum none "$DC")"
expect "수량 차이 1건"    1 "$(jnum qty "$DC")"
expect "단가 차이 1건"     1 "$(jnum price "$DC")"
expect "명세서만 있음 1건" 1 "$(jnum missing_ours "$DC")"
expect "우리만 있음 1건"   1 "$(jnum missing_theirs "$DC")"

sub "확정 — 한 트랜잭션"
R=$(call qa_manager01 POST "/api/purchase/recon/$STMT/commit" "$TOK_MGR" \
  "{\"mapping\":$MAPPING,\"header_row\":0}")
CM=$(body_of "$R")
expect "대사 확정 201" 201 "$(code_of "$R")"
LINES_DB=$(psqlq "SELECT count(*) FROM p_recon_lines WHERE statement_id = $STMT;")
expect "저장된 줄 수 = 응답 줄 수" "$(jnum lines "$CM")" "$LINES_DB"
DIFFS=$(psqlq "SELECT string_agg(diff_type || ':' || count::text, ' ' ORDER BY diff_type) FROM
               (SELECT diff_type, count(*) AS count FROM p_recon_lines
                 WHERE statement_id = $STMT GROUP BY diff_type) t;")
echo "  DB 판정 분포: $DIFFS"

sub "두 번째 확정은 막힌다 (되돌릴 권한이 없으므로)"
R=$(call qa_manager01 POST "/api/purchase/recon/$STMT/commit" "$TOK_MGR" \
  "{\"mapping\":$MAPPING,\"header_row\":0}")
expect "재확정 → 400" 400 "$(code_of "$R")"

sub "연락처·계좌 열은 저장될 자리가 없다 (p_recon_lines 컬럼)"
COLS=$(psqlq "SELECT count(*) FROM information_schema.columns
               WHERE table_name = 'p_recon_lines'
                 AND column_name IN ('contact','phone','tel','account','account_no','bank');")
expect "연락처·계좌 컬럼 0개" 0 "$COLS"

sub "확정된 대사표 다시 읽기"
R=$(call qa_manager01 GET "/api/purchase/recon/$STMT" "$TOK_MGR")
expect "대사표 조회 200" 200 "$(code_of "$R")"

hr "6. T-47 (c) — 월 마감"

sub "재인증 없이 마감 → 403 next:reauth"
NEW_TOK=$(login qa_manager01)   # 새 세션 = 재인증 창이 닫힌 상태
R=$(call qa_manager01 POST /api/purchase/close "$NEW_TOK" "{\"month\":\"$MONTH\",\"branch_id\":$BR_A}")
expect "재인증 전 마감 403" 403 "$(code_of "$R")"
printf '%s' "$(body_of "$R")" | grep -q '"next":"reauth"' \
  && { echo '  ✔ 응답에 next:reauth'; PASS=$((PASS+1)); } \
  || { echo '  ✖ 응답에 next:reauth 없음'; FAIL=$((FAIL+1)); }
TOK_MGR="$NEW_TOK"
echo "  재인증: qa_manager01 → $(reauth qa_manager01 "$TOK_MGR")"

sub "미확정 전표가 있으면 400 open_docs_exist"
R=$(call qa_manager01 POST /api/purchase/pr "$TOK_MGR" \
  "{\"branch_id\":$BR_A,\"to_location_id\":$LOC_WH,\"vendor_id\":$VENDOR,\"lines\":[{\"item_id\":$ITEM_A,\"qty\":\"1\"}]}")
OPEN_PR=$(new_id "$(body_of "$R")"); expect "미확정 발주요청 생성" 201 "$(code_of "$R")"
R=$(call qa_manager01 POST /api/purchase/close "$TOK_MGR" "{\"month\":\"$MONTH\",\"branch_id\":$BR_A}")
expect "미확정 전표 있음 → 400" 400 "$(code_of "$R")"
REASON=$(psqlq "SELECT reason FROM audit_logs WHERE action='purchase.close.create' AND result='deny'
                 ORDER BY id DESC LIMIT 1;")
expect "감사 사유 open_docs_exist" "open_docs_exist" "$REASON"

sub "미확정 전표를 치우고 마감 → 200"
R=$(call qa_manager01 POST "/api/purchase/pr/$OPEN_PR/void" "$TOK_MGR" '{"reason":"S22 시험 정리"}')
expect "미확정 요청 취소 200" 200 "$(code_of "$R")"
R=$(call qa_manager01 POST /api/purchase/close "$TOK_MGR" \
  "{\"month\":\"$MONTH\",\"branch_id\":$BR_A,\"reason_text\":\"S22 시험 마감\"}")
CL=$(body_of "$R")
expect "마감 201" 201 "$(code_of "$R")"
echo "  $CL"
CLOSED_DOCS=$(psqlq "SELECT count(*) FROM p_docs WHERE branch_id = $BR_A AND closed_month = '$MONTH';")
echo "  closed_month 가 찍힌 전표: $CLOSED_DOCS"

sub "같은 달 두 번 마감 → 409"
R=$(call qa_manager01 POST /api/purchase/close "$TOK_MGR" "{\"month\":\"$MONTH\",\"branch_id\":$BR_A}")
expect "재마감 409" 409 "$(code_of "$R")"

sub "마감된 달의 전표 취소 — 사유 없으면 400 month_closed"
R=$(call qa_employee01 POST "/api/purchase/gr/$GR_ID/void" "$TOK_EMP1" '{}')
expect "stock 이 사유 없이 입고 취소 → 400" 400 "$(code_of "$R")"
REASON=$(psqlq "SELECT reason FROM audit_logs WHERE action='purchase.receipt.void' AND result='deny'
                 ORDER BY id DESC LIMIT 1;")
expect "감사 사유 month_closed" "month_closed" "$REASON"

sub "사유를 적어도 purchase.close 가 없으면 여전히 400"
R=$(call qa_employee01 POST "/api/purchase/gr/$GR_ID/void" "$TOK_EMP1" '{"reason":"S22 사유는 있으나 자격 없음"}')
expect "stock 이 사유를 적어도 → 400" 400 "$(code_of "$R")"

sub "buyer(purchase.close 보유)가 사유를 적으면 200 + 감사 after_close"
R=$(call qa_manager01 POST "/api/purchase/lab" "$TOK_MGR" \
  "{\"vendor_id\":$VENDOR,\"to_location_id\":$LOC_WH,\"doc_date\":\"$TODAY\",\"lines\":[{\"item_id\":$ITEM_B,\"qty\":\"1\"}]}")
expect "마감된 달에 새 전표 → 400 month_closed" 400 "$(code_of "$R")"

R=$(call qa_manager01 POST "/api/purchase/gr/$LAB_ID/void" "$TOK_MGR" '{"reason":"S22 마감 뒤 정정"}')
expect "buyer 가 사유로 취소 → 200" 200 "$(code_of "$R")"
AFTER=$(psqlq "SELECT detail->>'after_close' FROM audit_logs
                WHERE action='purchase.receipt.void' AND result='allow' ORDER BY id DESC LIMIT 1;")
expect "감사 detail.after_close = true" "true" "$AFTER"

sub "경보 AL-14 · AL-16 dry-run"
sudo -n php "$(dirname "$0")/../scripts/alerts.php" --dry-run --rule AL-14 2>&1 | sed 's/^/  /' | tail -5
sudo -n php "$(dirname "$0")/../scripts/alerts.php" --dry-run --rule AL-16 2>&1 | sed 's/^/  /' | tail -4

hr "7. 지점 격리 · 자격 (SECURITY R-65 · R-70)"

R=$(call qa_employee02 POST /api/purchase/close "$TOK_EMP2" "{\"month\":\"$MONTH\"}")
expect "requester 마감 403" 403 "$(code_of "$R")"
R=$(call qa_employee01 POST /api/purchase/close "$TOK_EMP1" "{\"month\":\"$MONTH\"}")
expect "stock 마감 403" 403 "$(code_of "$R")"
R=$(call qa_employee02 POST /api/purchase/lab "$TOK_EMP2" \
  "{\"vendor_id\":$VENDOR,\"to_location_id\":$LOC_B,\"lines\":[{\"item_id\":$ITEM_B,\"qty\":\"1\"}]}")
expect "requester 기공 명세서 403" 403 "$(code_of "$R")"
R=$(upload_file qa_employee02 /api/purchase/recon "$TOK_EMP2" "$TMP_DIR/S22-statement.xlsx" "$VENDOR" "$MONTH")
expect "requester 명세서 업로드 403" 403 "$(code_of "$R")"

sub "requester 는 대사 목록은 보되, 우리 입고 줄은 자기 지점 것만"
R=$(call qa_employee02 GET "/api/purchase/recon/$STMT" "$TOK_EMP2")
RG=$(body_of "$R")
expect "requester 대사표 조회 200" 200 "$(code_of "$R")"
CNT=$(printf '%s' "$RG" | grep -c "\"branch_name\":\"S22-부평점\"")
expect "다른 지점 전표 정보 0건" 0 "$CNT"

hr "8. 화면 (틀만 — 값은 JS 가 채운다)"
for p in /purchase/usage /purchase/recon; do
  CODE=$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR_DIR/qa_manager01.jar" -A "$UA" "$BASE$p")
  expect "$p 화면 200" 200 "$CODE"
done
CODE=$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR_DIR/qa_employee02.jar" -A "$UA" "$BASE/purchase/usage")
expect "requester 도 주간 사용량 화면은 열린다" 200 "$CODE"

hr "9. 권한 · 린트"
sub "\\dp — 새 표 셋은 arw (DELETE 없음), 원장은 ar"
for t in p_recon_statements p_recon_lines p_month_close p_stock_moves; do
  PRIV=$(psqlq "SELECT coalesce((SELECT substring(a, position('=' in a) + 1, position('/' in a) - position('=' in a) - 1)
                  FROM unnest(relacl::text[]) a WHERE a LIKE 'tbox_app=%'), '(없음)')
                 FROM pg_class WHERE relname = '$t';")
  printf '  %-20s tbox_app=%s\n' "$t" "$PRIV"
done
DEL=$(psqlq "SELECT count(*) FROM pg_class c, unnest(c.relacl::text[]) a
              WHERE c.relname IN ('p_recon_statements','p_recon_lines','p_month_close')
                AND a LIKE 'tbox_app=%d%';")
expect "새 표에 앱 DELETE 권한 0건" 0 "$DEL"

sub "security_lint.sh"
bash "$(dirname "$0")/../scripts/security_lint.sh" >/dev/null 2>&1 \
  && { echo '  ✔ 린트 통과 (금지 패턴 0건)'; PASS=$((PASS+1)); } \
  || { echo '  ✖ 린트 실패'; FAIL=$((FAIL+1)); }
