#!/usr/bin/env bash
# tests/purchase_stage19.sh — 19단계 수락 기준 실행 (PRD 12.2 T-42 · T-43 · T-44 + 회귀 T-1 · T-26 · T-36)
#
# 규칙 (CLAUDE.md 3장)
#   · **검토 계정(qa_ 접두)만** 쓴다. 시드 계정(admin01 …)은 사용자의 자기 공격 전용이다.
#   · 모든 요청에 `-A "tbox-review/<날짜>"` 를 붙인다 — 감사 로그에서 사람의 행동과 갈라 보려는 것.
#   · 비밀번호·TOTP 비밀은 **필요한 필드만 awk 로** 뽑는다. 파일을 통째로 출력(cat)하지 않고,
#     값은 변수에만 담아 화면·로그 어디에도 찍지 않는다 (SECURITY R-62).
#
# 준비물: sudo(로 /etc/tbox-v2 읽기 · psql 실행), php(TOTP 계산·xlsx 생성), curl, jq 없이 동작
#
# 시험이 만든 것은 **끝에서 되돌린다**:
#   · qa 계정 두 개의 branch_id·purchase_role → NULL 로 원복
#   · T-43 더미 품목 13,500행 삭제 (디스크 여유가 적다 — 지시서)
#   · T-44 가 만든 기초정보·가져오기 행 삭제
#   되돌리기는 `postgres` 슈퍼유저로 한다 — 앱 계정에는 p_* DELETE 권한이 **없다**(그것이 설계다).
#
# 사용: sudo -v && bash tests/purchase_stage19.sh
set -u

BASE="http://127.0.0.1:8082"
UA="tbox-review/$(date +%F)"
DB="tbox_re"
JAR_DIR="$(mktemp -d)"
PASS=0
FAIL=0

psqlq() { sudo -n -u postgres psql -d "$DB" -t -A -c "$1"; }
psqlc() { sudo -n -u postgres psql -d "$DB" -v ON_ERROR_STOP=1 -q -c "$1"; }

hr()  { printf '\n════════════════════════════════════════════════════════════\n%s\n════════════════════════════════════════════════════════════\n' "$1"; }
sub() { printf '\n── %s\n' "$1"; }

# $1 설명  $2 기대  $3 실제
expect() {
  if [ "$2" = "$3" ]; then
    printf '  ✔ %s  (기대=%s 실제=%s)\n' "$1" "$2" "$3"; PASS=$((PASS+1))
  else
    printf '  ✖ %s  (기대=%s 실제=%s)\n' "$1" "$2" "$3"; FAIL=$((FAIL+1))
  fi
}

# ── 로그인 ────────────────────────────────────────────────────
# $1 계정명 → $JAR_DIR/$1.jar 에 세션 쿠키, 표준출력에 CSRF 토큰
login() {
  local who="$1" jar="$JAR_DIR/$1.jar" pw sec raw code left out try
  pw=$(sudo -n awk -v u="$who" '$1==u{print $3}' /etc/tbox-v2/seed-accounts.txt)
  sec=$(sudo -n awk -v u="$who" '$1==u{print $2}' /etc/tbox-v2/qa-totp.txt)

  # TOTP 코드는 **1회성**이다 (SECURITY R-45 — users.mfa_last_step 이하의 스텝은 거부).
  # 같은 계정으로 30초 안에 두 번 로그인하면 같은 6자리가 나와 거부되는 것이 **정상**이다.
  # 그래서 실패하면 다음 30초 창을 기다렸다가 한 번 더 시도한다(코드가 아니라 시험의 문제다).
  for try in 1 2 3; do
    # `| head -1` 로 자르면 도구 쪽에 SIGPIPE 가 가서 PHP Notice 가 뜬다 → 전부 받고 첫 줄만 쓴다
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
    printf '  · %s 코드 재사용으로 거부됨 — 다음 창까지 %s초 기다림 (R-45 가 의도대로 막은 것)\n' "$who" "$left" >&2
    sleep $((left + 1))
  done
  printf '  ! %s 로그인 실패: %s\n' "$who" "$out" >&2
  return 1
}

# 재인증 (기초정보 편집·가져오기는 15분 창이 필요하다 — F-23)
reauth() {
  local who="$1" token="$2" pw
  pw=$(sudo -n awk -v u="$who" '$1==u{print $3}' /etc/tbox-v2/seed-accounts.txt)
  curl -s -o /dev/null -w '%{http_code}' -b "$JAR_DIR/$who.jar" -A "$UA" \
    -H 'Content-Type: application/json' -H "X-CSRF-Token: $token" \
    -d "{\"password\":\"$pw\"}" "$BASE/api/me/reauth"
}

# $1 계정 $2 메서드 $3 경로 $4 토큰 $5 본문(선택) → "HTTP코드<TAB>본문"
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

# ─────────────────────────────────────────────────────────────
# 표 인구조사 (2026-09-13 사고 뒤 추가, 코디네이터 지시 2)
#
# 시험 **시작 전**과 **끝난 뒤**에 각 표의 전체 행 수와 **qa 가 아닌 created_by 행 수**를 찍는다.
# 끝난 뒤의 「비-qa」 수가 시작 때와 같아야 한다 — 다르면 시험이 남의 자료를 건드린 것이고,
# 그것은 보고에 반드시 드러나야 한다.
# ─────────────────────────────────────────────────────────────
QA_IDS=""
CENSUS_BEFORE=""

qa_ids_of() {
  psqlq "SELECT coalesce(string_agg(id::text, ','), '0') FROM users WHERE username LIKE 'qa\_%';"
}

# $1 = 'before' | 'after'  → 표를 **화면에 찍고**, 비-qa 합계는 전역 CENSUS_SUM 에 담는다.
# (합계를 표준출력으로 돌려주면 표까지 함께 잡혀 비교가 깨진다 — 2026-09-13 실측)
CENSUS_SUM=0
census() {
  local when="$1"
  printf '  %-18s %8s %10s\n' "표" "전체" "비-qa"
  local t total nonqa sum=0
  for t in p_brands p_branches p_locations p_vendors p_categories p_items p_par_levels p_import_mappings; do
    total=$(psqlq "SELECT count(*) FROM $t;")
    nonqa=$(psqlq "SELECT count(*) FROM $t WHERE created_by NOT IN ($QA_IDS);")
    printf '  %-18s %8s %10s\n' "$t" "$total" "$nonqa"
    sum=$((sum + nonqa))
  done
  printf '  %-18s %8s %10s   ← 사용자(비-qa)가 만든 행 합계 (%s)\n' "합계" "-" "$sum" "$when"
  CENSUS_SUM="$sum"
}

cleanup() {
  hr "정리 — **시험이 만든 것만** 되돌린다"

  # ─────────────────────────────────────────────────────────────
  # ★ 2026-09-13 사고 뒤 다시 씀 (반드시 읽을 것)
  #
  # 전에는 `DELETE FROM p_vendors;` 처럼 **표를 통째로** 비웠다. 그때는 p_* 가 시험 전용
  # 빈 표였지만, 사용자가 같은 DB 에서 실제 이카운트 자료를 가져오기 시작하자
  # 이 정리가 **사용자의 거래처 180건·창고·매핑을 지웠다**(08:19~08:22 작업분).
  #
  # 그래서 이제 **`created_by` 가 검토 계정(qa_)인 행만** 지운다. 사용자가 만든 행은
  # 같은 표에 있어도 건드리지 않는다 — 시험은 자기가 만든 것만 치운다
  # (CLAUDE.md 4장 "운영 데이터에 손대지 않는다").
  #
  # p_item_branches 에는 created_by 가 없다 → 품목을 따라간다.
  # ─────────────────────────────────────────────────────────────
  local qa_ids
  qa_ids=$(psqlq "SELECT string_agg(id::text, ',') FROM users WHERE username LIKE 'qa\_%';")
  if [ -z "$qa_ids" ]; then
    echo "  ! 검토 계정을 찾지 못해 정리를 건너뜁니다 (아무것도 지우지 않았습니다)"
    rm -rf "$JAR_DIR"
    return
  fi
  echo "  검토 계정 id: $qa_ids — 이 계정이 만든 행만 지웁니다"

  # 지우기 전에 **남의 행이 몇 개인지** 세어 보고한다(지우지 않는다는 증거).
  psqlq "SELECT '  사용자(비-qa)가 만든 행: p_vendors='||
           (SELECT count(*) FROM p_vendors   WHERE created_by NOT IN ($qa_ids))||
         ' p_locations='||(SELECT count(*) FROM p_locations WHERE created_by NOT IN ($qa_ids))||
         ' p_branches='||(SELECT count(*) FROM p_branches  WHERE created_by NOT IN ($qa_ids))||
         ' p_items='||(SELECT count(*) FROM p_items        WHERE created_by NOT IN ($qa_ids))||
         ' — 이 행들은 그대로 둡니다';"

  # 계정 두 축 원복 (계정 행 자체는 건드리지 않는다)
  psqlc "UPDATE users SET branch_id = NULL, purchase_role = NULL
          WHERE username IN ('qa_employee01','qa_employee02','qa_manager01');" \
    && echo "  qa 계정 branch_id·purchase_role 원복"

  # 참조가 있는 것부터 지운다. 전부 `created_by IN (검토 계정)` 으로 좁혀져 있다.
  # (앱 계정에는 p_* DELETE 권한이 없다 — 그래서 postgres 로 한다. 그 자체가 설계다.)
  psqlc "DELETE FROM p_par_levels    WHERE created_by IN ($qa_ids);
         DELETE FROM p_item_branches WHERE item_id IN (SELECT id FROM p_items WHERE created_by IN ($qa_ids));
         DELETE FROM p_items         WHERE created_by IN ($qa_ids);
         DELETE FROM p_import_mappings WHERE created_by IN ($qa_ids);
         DELETE FROM p_categories    WHERE created_by IN ($qa_ids) AND parent_id IS NOT NULL;
         DELETE FROM p_categories    WHERE created_by IN ($qa_ids);
         DELETE FROM p_vendors       WHERE created_by IN ($qa_ids);
         UPDATE p_branches SET warehouse_location_id = NULL, central_location_id = NULL
          WHERE created_by IN ($qa_ids);
         DELETE FROM p_locations     WHERE created_by IN ($qa_ids);
         DELETE FROM p_branches      WHERE created_by IN ($qa_ids);
         DELETE FROM p_brands        WHERE created_by IN ($qa_ids);" \
    && echo "  검토 계정이 만든 시험 데이터 삭제"

  psqlq "SELECT '  남은 검토 계정 행: p_items='||(SELECT count(*) FROM p_items WHERE created_by IN ($qa_ids))||
         ' p_vendors='||(SELECT count(*) FROM p_vendors WHERE created_by IN ($qa_ids));"

  sub "정리 뒤 인구조사 — 사용자 자료가 그대로인가"
  census after
  local after="$CENSUS_SUM"
  echo
  if [ "$after" = "$CENSUS_BEFORE" ]; then
    printf '  ✔ 사용자(비-qa) 행 수가 시작 때와 같습니다 (%s → %s) — 남의 자료를 건드리지 않았습니다\n' \
      "$CENSUS_BEFORE" "$after"
  else
    printf '  ✖ 사용자(비-qa) 행 수가 달라졌습니다 (%s → %s) — **즉시 보고해야 합니다**\n' \
      "$CENSUS_BEFORE" "$after"
    FAIL=$((FAIL+1))
  fi
  rm -rf "$JAR_DIR"
}

# ─────────────────────────────────────────────────────────────
# ① 사용자 활동 확인 — SECURITY R-73 ② (20단계 스크립트와 **같은 코드**)
#
# 이 시험은 p_* 에 행을 만들고 끝에 지운다. 사용자가 같은 DB 에서 실제 자료를 가져오는
# 중이면 **시작하지 않는다** — 2026-09-13 에 시험 정리가 사용자의 거래처 180행을 지운
# 사고(R-73)가 그래서 났다. 관문이 막으면 아무것도 만들지 않고 종료 코드 3 으로 끝난다.
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

trap cleanup EXIT

hr "0-0. 시작 전 인구조사 — 사용자 자료가 얼마나 있나 (2026-09-13 사고 뒤 필수)"
QA_IDS=$(qa_ids_of)
echo "  검토 계정 id: $QA_IDS"
census before
CENSUS_BEFORE="$CENSUS_SUM"
echo
echo "  ★ 이 시험의 정리는 위 「비-qa」 행을 **한 줄도 지우지 않습니다** (created_by 필터)."

hr "0. 준비 — 검토 계정 로그인"
TOK_ADMIN=$(login qa_admin01)     || exit 1
TOK_EMP1=$(login qa_employee01)   || exit 1
TOK_EMP2=$(login qa_employee02)   || exit 1
TOK_GUEST=$(login qa_guest01)     || exit 1
TOK_MGR=$(login qa_manager01)     || exit 1
echo "  로그인 5계정 완료 (토큰 길이: ${#TOK_ADMIN})"

echo "  재인증(기초정보 편집 창 열기): qa_admin01 → $(reauth qa_admin01 "$TOK_ADMIN")"

hr "1. 기초정보 씨앗 — 브랜드·지점·장소·거래처·분류 (qa_admin01)"

new_id() { printf '%s' "$1" | sed -n 's/.*"id":\([0-9]*\).*/\1/p'; }

R=$(call qa_admin01 POST /api/purchase/brands "$TOK_ADMIN" '{"name":"S19-치과"}')
BRAND=$(new_id "$(body_of "$R")"); expect "브랜드 생성" 201 "$(code_of "$R")"

R=$(call qa_admin01 POST /api/purchase/branches "$TOK_ADMIN" "{\"brand_id\":$BRAND,\"name\":\"S19-부평점\"}")
BR_A=$(new_id "$(body_of "$R")"); expect "지점A(부평점) 생성" 201 "$(code_of "$R")"
R=$(call qa_admin01 POST /api/purchase/branches "$TOK_ADMIN" "{\"brand_id\":$BRAND,\"name\":\"S19-일산점\"}")
BR_B=$(new_id "$(body_of "$R")"); expect "지점B(일산점) 생성" 201 "$(code_of "$R")"

R=$(call qa_admin01 POST /api/purchase/locations "$TOK_ADMIN" "{\"branch_id\":$BR_A,\"kind\":\"warehouse\",\"name\":\"S19-B1F 의약품창고\",\"code\":\"00011\"}")
LOC_A=$(new_id "$(body_of "$R")"); expect "부평점 장소 생성" 201 "$(code_of "$R")"
R=$(call qa_admin01 POST /api/purchase/locations "$TOK_ADMIN" "{\"branch_id\":$BR_B,\"kind\":\"central\",\"name\":\"S19-2F 공급실\",\"code\":\"00038\"}")
LOC_B=$(new_id "$(body_of "$R")"); expect "일산점 장소 생성" 201 "$(code_of "$R")"

R=$(call qa_admin01 POST /api/purchase/vendors "$TOK_ADMIN" '{"name":"S19-더존덴탈","code":"S19-V001"}')
VEND=$(new_id "$(body_of "$R")"); expect "거래처 생성" 201 "$(code_of "$R")"

R=$(call qa_admin01 POST /api/purchase/categories "$TOK_ADMIN" '{"name":"S19-소모품","level":1}')
CAT1=$(new_id "$(body_of "$R")"); expect "분류1 생성" 201 "$(code_of "$R")"
R=$(call qa_admin01 POST /api/purchase/categories "$TOK_ADMIN" "{\"name\":\"S19-거즈\",\"level\":2,\"parent_id\":$CAT1}")
CAT2=$(new_id "$(body_of "$R")"); expect "분류2 생성" 201 "$(code_of "$R")"

# 품목 3건: A지점 전용 · B지점 전용 · 지점 지정 없음(브랜드 전체)
R=$(call qa_admin01 POST /api/purchase/items "$TOK_ADMIN" \
  "{\"vendor_id\":$VEND,\"code\":\"S19-A-0001\",\"name\":\"S19-A gauze 2x2\",\"unit\":\"EA\",\"branches\":[$BR_A],\"cat1_id\":$CAT1,\"cat2_id\":$CAT2}")
ITEM_A=$(new_id "$(body_of "$R")"); expect "품목A(부평점 전용) 생성" 201 "$(code_of "$R")"
R=$(call qa_admin01 POST /api/purchase/items "$TOK_ADMIN" \
  "{\"vendor_id\":$VEND,\"code\":\"S19-B-0001\",\"name\":\"S19-B gauze 3x3\",\"unit\":\"EA\",\"branches\":[$BR_B]}")
ITEM_B=$(new_id "$(body_of "$R")"); expect "품목B(일산점 전용) 생성" 201 "$(code_of "$R")"
R=$(call qa_admin01 POST /api/purchase/items "$TOK_ADMIN" \
  "{\"vendor_id\":$VEND,\"code\":\"S19-ALL-0001\",\"name\":\"S19-ALL cotton\",\"unit\":\"EA\"}")
ITEM_ALL=$(new_id "$(body_of "$R")"); expect "품목ALL(지점 무관) 생성" 201 "$(code_of "$R")"

R=$(call qa_admin01 POST /api/purchase/par-levels "$TOK_ADMIN" "{\"location_id\":$LOC_A,\"item_id\":$ITEM_A,\"qty\":380}")
expect "부평점 인가량 생성" 201 "$(code_of "$R")"
R=$(call qa_admin01 POST /api/purchase/par-levels "$TOK_ADMIN" "{\"location_id\":$LOC_B,\"item_id\":$ITEM_B,\"qty\":190}")
expect "일산점 인가량 생성" 201 "$(code_of "$R")"

sub "검토용 모듈 역할·지점 심기 (psql, 끝에서 원복)"
psqlc "UPDATE users SET branch_id = $BR_A, purchase_role = 'requester' WHERE username = 'qa_employee01';"
psqlc "UPDATE users SET branch_id = $BR_B, purchase_role = 'stock'     WHERE username = 'qa_employee02';"
psqlc "UPDATE users SET branch_id = NULL,  purchase_role = 'buyer'     WHERE username = 'qa_manager01';"
psqlq "SELECT username||' branch='||coalesce(branch_id::text,'-')||' role='||coalesce(purchase_role,'-')
         FROM users WHERE username LIKE 'qa%' ORDER BY id;"

hr "T-42  지점 격리 · 모듈 역할 (PRD 12.2 T-42, F-28, S-01, S-02, SECURITY R-65 · R-70)"

sub "① requester(부평점, qa_employee01) 의 장소 목록 — 다른 지점 0건"
R=$(call qa_employee01 GET /api/purchase/locations "$TOK_EMP1")
echo "  HTTP $(code_of "$R")"; echo "  $(body_of "$R")"
expect "장소 목록 200" 200 "$(code_of "$R")"
expect "다른 지점(일산점) 장소 0건" 0 "$(body_of "$R" | grep -c 'S19-2F 공급실')"
expect "내 지점(부평점) 장소 보임" 1 "$(body_of "$R" | grep -c 'S19-B1F 의약품창고')"
expect "total 은 1" 1 "$(body_of "$R" | sed -n 's/.*"total":\([0-9]*\).*/\1/p')"

sub "② requester 가 ?branch= 로 다른 지점을 요구해도 무시된다"
R=$(call qa_employee01 GET "/api/purchase/locations?branch=$BR_B" "$TOK_EMP1")
echo "  HTTP $(code_of "$R")"; echo "  $(body_of "$R")"
expect "?branch=다른지점 → 여전히 다른 지점 0건" 0 "$(body_of "$R" | grep -c 'S19-2F 공급실')"

sub "③ requester 의 품목 검색 — 다른 지점 전용 품목은 목록에도 total 에도 없다"
R=$(call qa_employee01 GET "/api/purchase/items" "$TOK_EMP1")
echo "  HTTP $(code_of "$R")"; echo "  $(body_of "$R")"
expect "품목 검색 200" 200 "$(code_of "$R")"
expect "일산점 전용 품목 0건" 0 "$(body_of "$R" | grep -c 'S19-B gauze 3x3')"
expect "부평점 품목 보임" 1 "$(body_of "$R" | grep -c 'S19-A gauze 2x2')"
expect "지점 무관 품목 보임" 1 "$(body_of "$R" | grep -c 'S19-ALL cotton')"

sub "④ requester 가 ?branch_used=다른지점 → 0건"
R=$(call qa_employee01 GET "/api/purchase/items?branch_used=$BR_B" "$TOK_EMP1")
echo "  HTTP $(code_of "$R")"; echo "  $(body_of "$R")"
expect "branch_used=다른지점 total 0" 0 "$(body_of "$R" | sed -n 's/.*"total":\([0-9]*\).*/\1/p')"

sub "⑤ requester 가 다른 지점 전용 품목 상세를 직접 요청 → 404"
R=$(call qa_employee01 GET "/api/purchase/items/$ITEM_B" "$TOK_EMP1")
echo "  HTTP $(code_of "$R")"; echo "  $(body_of "$R")"
expect "다른 지점 품목 상세 404" 404 "$(code_of "$R")"
R=$(call qa_employee01 GET "/api/purchase/items/$ITEM_A" "$TOK_EMP1")
expect "내 지점 품목 상세 200" 200 "$(code_of "$R")"
echo "  $(body_of "$R")"

sub "⑥ stock(일산점, qa_employee02) 은 반대로 보인다"
R=$(call qa_employee02 GET /api/purchase/locations "$TOK_EMP2")
echo "  HTTP $(code_of "$R")"; echo "  $(body_of "$R")"
expect "일산점 장소 보임" 1 "$(body_of "$R" | grep -c 'S19-2F 공급실')"
expect "부평점 장소 0건" 0 "$(body_of "$R" | grep -c 'S19-B1F 의약품창고')"

sub "⑦ buyer(qa_manager01, 지점 없음) 는 전 지점"
R=$(call qa_manager01 GET /api/purchase/locations "$TOK_MGR")
echo "  HTTP $(code_of "$R")"; echo "  $(body_of "$R")"
expect "buyer 는 두 지점 모두" 2 "$(body_of "$R" | sed -n 's/.*"total":\([0-9]*\).*/\1/p')"

sub "⑧ 모듈 역할 없는 Employee → 403 (감사 authz.route deny)"
psqlc "UPDATE users SET purchase_role = NULL, branch_id = NULL WHERE username = 'qa_employee01';"
R=$(call qa_employee01 GET /api/purchase/locations "$TOK_EMP1")
echo "  HTTP $(code_of "$R")"; echo "  $(body_of "$R")"
expect "모듈 역할 없음 → 403" 403 "$(code_of "$R")"
R=$(call qa_employee01 GET /purchase/masters "$TOK_EMP1")
expect "화면도 403" 403 "$(code_of "$R")"
psqlc "UPDATE users SET purchase_role = 'requester', branch_id = $BR_A WHERE username = 'qa_employee01';"

sub "⑨ Guest → 403"
R=$(call qa_guest01 GET /api/purchase/locations "$TOK_GUEST")
echo "  HTTP $(code_of "$R")"; echo "  $(body_of "$R")"
expect "Guest → 403" 403 "$(code_of "$R")"

sub "⑩ 기초정보 편집은 재인증 전 403 next:reauth"
TOK_MGR2=$(login qa_manager01)   # 재인증 창이 없는 새 세션
R=$(call qa_manager01 POST /api/purchase/brands "$TOK_MGR2" '{"name":"S19-재인증시험"}')
echo "  HTTP $(code_of "$R")"; echo "  $(body_of "$R")"
expect "재인증 전 기초정보 생성 403" 403 "$(code_of "$R")"
expect "next:reauth 표시" 1 "$(body_of "$R" | grep -c '"next":"reauth"')"
echo "  재인증: $(reauth qa_manager01 "$TOK_MGR2")"
R=$(call qa_manager01 POST /api/purchase/brands "$TOK_MGR2" '{"name":"S19-재인증시험"}')
echo "  재인증 뒤 HTTP $(code_of "$R"): $(body_of "$R")"
expect "재인증 뒤 201" 201 "$(code_of "$R")"

sub "⑪ requester 는 재인증해도 기초정보를 못 고친다 (재인증은 권한을 만들지 않는다)"
echo "  재인증: $(reauth qa_employee01 "$TOK_EMP1")"
R=$(call qa_employee01 POST /api/purchase/brands "$TOK_EMP1" '{"name":"S19-권한없음시험"}')
echo "  HTTP $(code_of "$R")"; echo "  $(body_of "$R")"
expect "requester 기초정보 생성 403" 403 "$(code_of "$R")"
expect "next:reauth 아님(권한 거부)" 0 "$(body_of "$R" | grep -c '"next"')"

sub "⑫ 감사 로그 — authz.route deny 가 남았나"
psqlq "SELECT actor_name||' | '||action||' | '||result||' | '||coalesce(reason,'-')||' | '||coalesce(target_name,'-')
         FROM audit_logs
        WHERE action IN ('authz.route','auth.gate') AND result='deny'
          AND target_name LIKE '%purchase%' AND happened_at > now() - interval '10 minutes'
        ORDER BY id DESC LIMIT 6;"

hr "T-43  품목 찾기 API — 1.35만 건 성능·정렬 허용 목록·SQLi (PRD 12.2 T-43, F-30 R1)"

sub "더미 13,500행 적재 (postgres, 시험 뒤 삭제)"
ADMIN_UID=$(psqlq "SELECT id FROM users WHERE username='qa_admin01';")
psqlc "INSERT INTO p_items (vendor_id, code, name, spec, unit, pack_qty, cat1_id, cat2_id,
                            stock_managed, price_in, barcode, created_by)
       SELECT $VEND, 'S19-D'||lpad(g::text,6,'0'),
              CASE WHEN g % 7 = 0 THEN 'S19-DSO GAUZE '||g||' 2x2inch'
                   ELSE 'S19-ITEM '||g||' cotton roll' END,
              g||'mm', 'EA', 1, $CAT1, $CAT2, true, 1000 + g, '880'||lpad(g::text,10,'0'), $ADMIN_UID
         FROM generate_series(1, 13500) g;"
psqlc "ANALYZE p_items;"
psqlq "SELECT 'p_items 총 '||count(*)||'행' FROM p_items;"

sub "응답 시간 실측 (buyer 세션, 서버 처리 시간 = curl time_total)"
for Q in "gauze" "cotton" "D0135" ""; do
  T=$(curl -s -o /dev/null -w '%{time_total} %{http_code}' -b "$JAR_DIR/qa_manager01.jar" -A "$UA" \
      "$BASE/api/purchase/items?q=$Q&page=2")
  echo "  q='$Q'  time_total=$(printf '%s' "$T" | cut -d' ' -f1)s  HTTP $(printf '%s' "$T" | cut -d' ' -f2)"
done

R=$(call qa_manager01 GET "/api/purchase/items?q=gauze&page=2" "$TOK_MGR")
echo "  본문(앞 400자): $(body_of "$R" | head -c 400)"
expect "q=gauze&page=2 → 200" 200 "$(code_of "$R")"
expect "한 쪽 20건 고정" 20 "$(body_of "$R" | grep -o '"code":' | wc -l)"
expect "page 반영" 2 "$(body_of "$R" | sed -n 's/.*"page":\([0-9]*\).*/\1/p')"
echo "  total=$(body_of "$R" | sed -n 's/.*"total":\([0-9]*\).*/\1/p')"

sub "정렬 — 허용 목록 밖 값은 무시되고 기본값(name)이 된다"
R=$(call qa_manager01 GET "/api/purchase/items?sort=price_in;DROP%20TABLE%20p_items&page=1" "$TOK_MGR")
echo "  HTTP $(code_of "$R")  sort=$(body_of "$R" | sed -n 's/.*"sort":"\([a-z_]*\)".*/\1/p')"
expect "허용 목록 밖 정렬 → 200" 200 "$(code_of "$R")"
expect "허용 목록 밖 정렬 → 기본값 name" name "$(body_of "$R" | sed -n 's/.*"sort":"\([a-z_]*\)".*/\1/p')"
expect "표는 그대로 있다" 13503 "$(psqlq "SELECT count(*) FROM p_items;")"

sub "SQLi 페이로드 — 200 정상 응답, 값은 글자로만 다뤄진다"
for P in "' OR '1'='1" "'; DROP TABLE p_items; --" "%25" "_" "100%25' UNION SELECT NULL--"; do
  R=$(curl -s -w '\n%{http_code}' -b "$JAR_DIR/qa_manager01.jar" -A "$UA" \
      --get --data-urlencode "q=$P" "$BASE/api/purchase/items")
  echo "  q=[$P] → HTTP $(code_of "$R")  total=$(body_of "$R" | sed -n 's/.*"total":\([0-9]*\).*/\1/p')"
  expect "SQLi 페이로드 200" 200 "$(code_of "$R")"
done
expect "SQLi 뒤에도 표 그대로" 13503 "$(psqlq "SELECT count(*) FROM p_items;")"

sub "page 는 1~500 정수로만 (밖의 값은 기본값 1)"
R=$(call qa_manager01 GET "/api/purchase/items?page=99999" "$TOK_MGR")
expect "page=99999 → 1" 1 "$(body_of "$R" | sed -n 's/.*"page":\([0-9]*\).*/\1/p')"
R=$(call qa_manager01 GET "/api/purchase/items?page=abc" "$TOK_MGR")
expect "page=abc → 1" 1 "$(body_of "$R" | sed -n 's/.*"page":\([0-9]*\).*/\1/p')"

hr "T-44  엑셀 가져오기 (PRD 12.2 T-44, F-29, S-06, S-07, SECURITY R-67)"

WORK="$JAR_DIR/xlsx"
mkdir -p "$WORK"
export ECOUNT_XLSX="$WORK/ecount.xlsx"

sub "시험용 xlsx 100행 만들기 (정상 90 · 중복 코드 5 · 거래처 없음 5)"
php -d error_reporting=E_ALL <<PHPGEN
<?php
// 최소 구성의 xlsx 를 ZipArchive 로 만든다 — 시험 자료이며 앱 코드가 아니다.
\$rows = [['품목코드','품목명','구매처명','규격','단위','입고단가']];
for (\$i = 1; \$i <= 90; \$i++) {
    \$rows[] = ['S19-X'.str_pad((string)\$i, 5, '0', STR_PAD_LEFT), 'S19-IMPORT ITEM '.\$i, 'S19-더존덴탈', \$i.'mm', 'EA', (string)(1000+\$i)];
}
// 중복 코드 5 — 앞의 X00001..X00005 와 같은 (거래처, 코드)
for (\$i = 1; \$i <= 5; \$i++) {
    \$rows[] = ['S19-X'.str_pad((string)\$i, 5, '0', STR_PAD_LEFT), 'S19-DUPLICATE '.\$i, 'S19-더존덴탈', '1mm', 'EA', '999'];
}
// 거래처 없음 5
for (\$i = 1; \$i <= 5; \$i++) {
    \$rows[] = ['S19-Z'.str_pad((string)\$i, 5, '0', STR_PAD_LEFT), 'S19-NOVENDOR '.\$i, 'S19-없는거래처', '1mm', 'EA', '999'];
}

\$esc = static fn (string \$v): string => htmlspecialchars(\$v, ENT_QUOTES | ENT_XML1, 'UTF-8');
\$sheet = '<?xml version="1.0" encoding="UTF-8"?>'
       . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
foreach (\$rows as \$r => \$row) {
    \$sheet .= '<row r="'.(\$r+1).'">';
    foreach (\$row as \$c => \$cell) {
        \$col = chr(65 + \$c);
        \$sheet .= '<c r="'.\$col.(\$r+1).'" t="inlineStr"><is><t>'.\$esc((string)\$cell).'</t></is></c>';
    }
    \$sheet .= '</row>';
}
\$sheet .= '</sheetData></worksheet>';

\$ct = '<?xml version="1.0" encoding="UTF-8"?>'
    . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
    . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
    . '<Default Extension="xml" ContentType="application/xml"/>'
    . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
    . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
    . '</Types>';
\$rels = '<?xml version="1.0" encoding="UTF-8"?>'
      . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
      . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
      . '</Relationships>';
\$wb = '<?xml version="1.0" encoding="UTF-8"?>'
    . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
    . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
    . '<sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets></workbook>';
\$wbRels = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '</Relationships>';

\$path = '$WORK/items.xlsx';
@unlink(\$path);
\$zip = new ZipArchive();
\$zip->open(\$path, ZipArchive::CREATE);
\$zip->addFromString('[Content_Types].xml', \$ct);
\$zip->addFromString('_rels/.rels', \$rels);
\$zip->addFromString('xl/workbook.xml', \$wb);
\$zip->addFromString('xl/_rels/workbook.xml.rels', \$wbRels);
\$zip->addFromString('xl/worksheets/sheet1.xml', \$sheet);
\$zip->close();

// 매크로 문서 흉내 — 확장자만 .xlsm 인 같은 zip
copy(\$path, '$WORK/macro.xlsm');
echo '  만든 파일: ', filesize(\$path), " 바이트 (100행 + 머리행)\n";
PHPGEN

sub "① .xlsm 은 업로드 검사에서 거부된다 (확장자 화이트리스트 — 가져오기가 규칙을 완화하지 않는다)"
R=$(curl -s -w '\n%{http_code}' -b "$JAR_DIR/qa_admin01.jar" -A "$UA" \
    -H "X-CSRF-Token: $TOK_ADMIN" -F "file=@$WORK/macro.xlsm" "$BASE/api/purchase/import")
echo "  HTTP $(code_of "$R")  $(body_of "$R")"
expect ".xlsm 업로드 400" 400 "$(code_of "$R")"
psqlq "SELECT '  감사: '||action||' '||result||' '||coalesce(reason,'-')||' '||coalesce(target_name,'-')
         FROM audit_logs WHERE action='purchase.import.upload' AND result='deny'
        ORDER BY id DESC LIMIT 1;"

sub "② xlsx 업로드 → 머리행·표본·매핑 후보"
R=$(curl -s -w '\n%{http_code}' -b "$JAR_DIR/qa_admin01.jar" -A "$UA" \
    -H "X-CSRF-Token: $TOK_ADMIN" -F "file=@$WORK/items.xlsx" "$BASE/api/purchase/import")
echo "  HTTP $(code_of "$R")"
echo "  $(body_of "$R" | head -c 700)"
expect "xlsx 업로드 201" 201 "$(code_of "$R")"
IMPORT_ID=$(body_of "$R" | sed -n 's/.*"import_id":"\([0-9a-f-]*\)".*/\1/p')
echo "  import_id=$IMPORT_ID"
expect "행 수 100" 100 "$(body_of "$R" | sed -n 's/.*"rows":\([0-9]*\).*/\1/p')"

sub "③ 미리보기 — 행별 판정 (등록 90 · 오류 10)"
MAPPING='{"품목코드":"code","품목명":"name","구매처명":"vendor","규격":"spec","단위":"unit","입고단가":"price_in"}'
R=$(call qa_admin01 POST "/api/purchase/import/$IMPORT_ID/preview" "$TOK_ADMIN" \
    "{\"kind\":\"items\",\"mapping\":$MAPPING}")
echo "  HTTP $(code_of "$R")"
echo "  counts: $(body_of "$R" | sed -n 's/.*"counts":{\([^}]*\)}.*/\1/p')"
echo "  본문(앞 700자): $(body_of "$R" | head -c 700)"
expect "미리보기 200" 200 "$(code_of "$R")"
expect "insert 90" 90 "$(body_of "$R" | sed -n 's/.*"insert":\([0-9]*\).*/\1/p')"
expect "error 10 (중복 5 + 거래처 없음 5)" 10 "$(body_of "$R" | sed -n 's/.*"error":\([0-9]*\).*/\1/p')"
expect "미리보기는 아무것도 저장하지 않는다" 13503 "$(psqlq "SELECT count(*) FROM p_items;")"

sub "④ 확정 — 한 트랜잭션, 오류 행은 저장하지 않는다"
# `audit_logs` 는 **추가 전용**이라 지난 실행의 행이 그대로 남는다(그것이 설계다).
# 그래서 "표에 몇 줄인가" 가 아니라 **이번 확정으로 몇 줄이 늘었나** 를 본다.
COMMIT_BEFORE=$(psqlq "SELECT count(*) FROM audit_logs WHERE action='purchase.import.commit' AND result='allow';")
R=$(call qa_admin01 POST "/api/purchase/import/$IMPORT_ID/commit" "$TOK_ADMIN" \
    "{\"kind\":\"items\",\"mapping\":$MAPPING}")
echo "  HTTP $(code_of "$R")"; echo "  $(body_of "$R")"
expect "확정 200" 200 "$(code_of "$R")"
expect "inserted 90" 90 "$(body_of "$R" | sed -n 's/.*"inserted":\([0-9]*\).*/\1/p')"
expect "표에 90행만 늘었다" 13593 "$(psqlq "SELECT count(*) FROM p_items;")"
expect "오류 행(Z 코드) 0건" 0 "$(psqlq "SELECT count(*) FROM p_items WHERE code LIKE 'S19-Z%';")"

sub "⑤ 감사 — 확정 1건에 한 줄 (detail 에 rows·inserted·updated·file_id·sha256)"
psqlq "SELECT summary FROM audit_logs WHERE action='purchase.import.commit' ORDER BY id DESC LIMIT 1;"
psqlq "SELECT detail::text FROM audit_logs WHERE action='purchase.import.commit' ORDER BY id DESC LIMIT 1;"
# `audit_logs` 는 추가 전용이라 지난 실행의 행이 그대로 남는다 — **이번 실행분만** 센다.
# 보는 것은 "확정 1번에 한 줄" 이지 "표에 한 줄" 이 아니다 (지시서 보충 8).
COMMIT_AFTER=$(psqlq "SELECT count(*) FROM audit_logs WHERE action='purchase.import.commit' AND result='allow';")
expect "확정 1번 = 감사 한 줄 (90행을 넣어도 한 줄)" 1 "$((COMMIT_AFTER - COMMIT_BEFORE))"
expect "확정 감사에 행 내용은 없다(요약 수치만)" 0 "$(psqlq "SELECT count(*) FROM audit_logs
        WHERE action='purchase.import.commit' AND detail::text LIKE '%IMPORT ITEM%';")"

sub "⑥ 매핑 저장 → 같은 양식 재업로드 시 저장된 매핑이 내려온다"
R=$(call qa_admin01 POST /api/purchase/import/mappings "$TOK_ADMIN" \
    "{\"kind\":\"items\",\"name\":\"S19-이카운트 품목 v1\",\"mapping\":$MAPPING}")
echo "  HTTP $(code_of "$R")  $(body_of "$R" | head -c 200)"
expect "매핑 저장 201" 201 "$(code_of "$R")"
R=$(curl -s -w '\n%{http_code}' -b "$JAR_DIR/qa_admin01.jar" -A "$UA" \
    -H "X-CSRF-Token: $TOK_ADMIN" -F "file=@$WORK/items.xlsx" "$BASE/api/purchase/import")
expect "재업로드 201" 201 "$(code_of "$R")"
expect "저장된 매핑이 응답에 있다" 1 "$(body_of "$R" | grep -c 'S19-이카운트 품목 v1')"
IMPORT_ID2=$(body_of "$R" | sed -n 's/.*"import_id":"\([0-9a-f-]*\)".*/\1/p')

sub "⑦ 두 번째 확정 — 같은 파일이므로 전부 「갱신 대상이지만 바뀐 것 없음」 = 건너뜀"
R=$(call qa_admin01 POST "/api/purchase/import/$IMPORT_ID2/preview" "$TOK_ADMIN" \
    "{\"kind\":\"items\",\"mapping\":$MAPPING}")
echo "  counts: $(body_of "$R" | sed -n 's/.*"counts":{\([^}]*\)}.*/\1/p')"
R=$(call qa_admin01 POST "/api/purchase/import/$IMPORT_ID2/commit" "$TOK_ADMIN" \
    "{\"kind\":\"items\",\"mapping\":$MAPPING}")
echo "  HTTP $(code_of "$R")  $(body_of "$R")"
expect "두 번째 확정도 행이 늘지 않는다" 13593 "$(psqlq "SELECT count(*) FROM p_items;")"

sub "⑧ 매핑이 부족하면 미리보기 자체가 400"
R=$(call qa_admin01 POST "/api/purchase/import/$IMPORT_ID/preview" "$TOK_ADMIN" \
    '{"kind":"items","mapping":{"품목코드":"code"}}')
echo "  HTTP $(code_of "$R")  $(body_of "$R")"
expect "필수 칸 빠진 매핑 400" 400 "$(code_of "$R")"

sub "⑨ 다른 사람의 admin-only 파일을 import_id 로 넘겨도 404 (보관 폴더 밖은 받지 않는다)"
OTHER=$(psqlq "SELECT public_id FROM files
                WHERE deleted_at IS NULL
                  AND folder_id IS DISTINCT FROM (SELECT id FROM folders WHERE name='가져오기')
                ORDER BY id DESC LIMIT 1;")
if [ -n "$OTHER" ]; then
  R=$(call qa_admin01 POST "/api/purchase/import/$OTHER/preview" "$TOK_ADMIN" \
      "{\"kind\":\"items\",\"mapping\":$MAPPING}")
  echo "  대상 public_id=$OTHER → HTTP $(code_of "$R")  $(body_of "$R")"
  expect "보관 폴더 밖 파일 404" 404 "$(code_of "$R")"
else
  echo "  (비교할 다른 파일이 없어 건너뜀)"
fi

sub "⑪ 이카운트형 파일 — 제목 1행 + 머리행 2행 + 데이터 (1차 보정 2026-09-13)"
# 실제 이카운트 품목 내보내기(ESA009M.xlsx)의 모양을 그대로 흉내낸다:
#   0행 제목 한 칸 · 1행 진짜 머리행 · 2행~ 데이터.
# 보정 전에는 rows[0] 을 머리행으로 못박아 열이 한 칸뿐이었고 preview 가 400 이었다.
php -d error_reporting=E_ALL <<'PHPGEN2'
<?php
$header = ['구매처명','대분류','중분류','소분류','품목코드','품목명','규격','단위',
           '입고단가','당수량(분자)','당수량(분모)','최초작성자'];
$rows = [['회사명 : 주식회사 플란랩스'], $header];
for ($i = 1; $i <= 30; $i++) {
    // 분모 1 = 그대로 등록. 마지막 5행만 분모 2 로 두어 review 로 갈리는지 본다.
    $den = ($i > 25) ? '2' : '1';
    $rows[] = ['S19-더존덴탈', 'S19-소모품', 'S19-거즈', '', 'S19-E'.str_pad((string)$i, 5, '0', STR_PAD_LEFT),
               'S19-ECOUNT ITEM '.$i, $i.'mm', 'EA', (string)(2000+$i), '10', $den, '홍길동'];
}

$esc = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_XML1, 'UTF-8');
$sheet = '<?xml version="1.0" encoding="UTF-8"?>'
       . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
foreach ($rows as $r => $row) {
    $sheet .= '<row r="'.($r+1).'">';
    foreach ($row as $c => $cell) {
        $col = chr(65 + $c);
        $sheet .= '<c r="'.$col.($r+1).'" t="inlineStr"><is><t>'.$esc((string)$cell).'</t></is></c>';
    }
    $sheet .= '</row>';
}
$sheet .= '</sheetData></worksheet>';

$ct = '<?xml version="1.0" encoding="UTF-8"?>'
    . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
    . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
    . '<Default Extension="xml" ContentType="application/xml"/>'
    . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
    . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
    . '</Types>';
$rels = '<?xml version="1.0" encoding="UTF-8"?>'
      . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
      . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
      . '</Relationships>';
$wb = '<?xml version="1.0" encoding="UTF-8"?>'
    . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
    . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
    . '<sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets></workbook>';
$wbRels = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '</Relationships>';

$path = getenv('ECOUNT_XLSX');
@unlink($path);
$zip = new ZipArchive();
$zip->open($path, ZipArchive::CREATE);
$zip->addFromString('[Content_Types].xml', $ct);
$zip->addFromString('_rels/.rels', $rels);
$zip->addFromString('xl/workbook.xml', $wb);
$zip->addFromString('xl/_rels/workbook.xml.rels', $wbRels);
$zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
$zip->close();
echo '  만든 파일: ', filesize($path), " 바이트 (제목 1행 + 머리행 1행 + 데이터 30행)\n";
PHPGEN2

R=$(curl -s -w '\n%{http_code}' -b "$JAR_DIR/qa_admin01.jar" -A "$UA" \
    -H "X-CSRF-Token: $TOK_ADMIN" -F "file=@$WORK/ecount.xlsx" "$BASE/api/purchase/import")
echo "  HTTP $(code_of "$R")"
echo "  $(body_of "$R" | head -c 520)"
expect "이카운트형 업로드 201" 201 "$(code_of "$R")"
ECOUNT_ID=$(body_of "$R" | sed -n 's/.*"import_id":"\([0-9a-f-]*\)".*/\1/p')

sub "⑪-a 머리행 자동 감지 = 1 (0행은 제목 한 칸)"
# 응답에는 `header_row` 가 여러 번 나온다(맨 앞의 감지 결과 + 저장된 매핑마다 하나).
# sed 의 `.*` 는 **가장 뒤**를 잡아 저장된 매핑의 0 을 읽는다 → grep -o 로 **첫 번째**만 본다.
expect "header_row 자동 감지 1" 1 "$(body_of "$R" | grep -o '"header_row":[0-9]*' | head -1 | cut -d: -f2)"
expect "데이터 행 수 30 (제목·머리행 제외)" 30 "$(body_of "$R" | sed -n 's/.*"rows":\([0-9]*\).*/\1/p')"
expect "head_rows 가 내려온다" 1 "$(body_of "$R" | grep -c '"head_rows"')"
expect "매핑 후보에 품목코드→code" 1 "$(body_of "$R" | grep -c '"품목코드":"code"')"
expect "매핑 후보에 품목명→name" 1 "$(body_of "$R" | grep -c '"품목명":"name"')"
expect "매핑 후보에 구매처명→vendor" 1 "$(body_of "$R" | grep -c '"구매처명":"vendor"')"
expect "당수량(분자)→pack_qty" 1 "$(body_of "$R" | grep -c 'pack_qty')"
expect "최초작성자는 건너뜀 기본값" 1 "$(body_of "$R" | grep -c '"최초작성자":"skip"')"

EMAP='{"구매처명":"vendor","대분류":"cat1","중분류":"cat2","품목코드":"code","품목명":"name","규격":"spec","단위":"unit","입고단가":"price_in","당수량(분자)":"pack_qty","당수량(분모)":"pack_den","최초작성자":"skip"}'

sub "⑪-b header_row 를 0 으로 **잘못** 보내면 그 줄엔 열 이름이 없다 → 400"
R=$(call qa_admin01 POST "/api/purchase/import/$ECOUNT_ID/preview" "$TOK_ADMIN" \
    "{\"kind\":\"items\",\"mapping\":$EMAP,\"header_row\":0}")
echo "  HTTP $(code_of "$R")  $(body_of "$R")"
expect "머리행 0 → 400 (열 이름이 없다)" 400 "$(code_of "$R")"

sub "⑪-c header_row=1 로 보내면 정상 판정 (분모 1 은 등록, 분모 2 는 확인 필요)"
R=$(call qa_admin01 POST "/api/purchase/import/$ECOUNT_ID/preview" "$TOK_ADMIN" \
    "{\"kind\":\"items\",\"mapping\":$EMAP,\"header_row\":1}")
echo "  HTTP $(code_of "$R")"
echo "  counts: $(body_of "$R" | sed -n 's/.*"counts":{\([^}]*\)}.*/\1/p')"
echo "  본문(앞 460자): $(body_of "$R" | head -c 460)"
expect "미리보기 200" 200 "$(code_of "$R")"
expect "insert 25 (분모 1)" 25 "$(body_of "$R" | sed -n 's/.*"insert":\([0-9]*\).*/\1/p')"
expect "review 5 (분모 2 — 사람 확인)" 5 "$(body_of "$R" | sed -n 's/.*"review":\([0-9]*\).*/\1/p')"
expect "error 0" 0 "$(body_of "$R" | sed -n 's/.*"error":\([0-9]*\).*/\1/p')"
expect "응답이 머리행을 알려 준다" 1 "$(body_of "$R" | grep -o '"header_row":[0-9]*' | head -1 | cut -d: -f2)"
expect "사유 pack_den_not_one" 1 "$(body_of "$R" | grep -c 'pack_den_not_one')"
expect "행 번호는 3행부터 (제목·머리행 다음)" 1 "$(body_of "$R" | grep -c '"row_no":3')"

sub "⑪-d header_row 를 생략해도 서버가 다시 감지해 같은 결과"
R=$(call qa_admin01 POST "/api/purchase/import/$ECOUNT_ID/preview" "$TOK_ADMIN" \
    "{\"kind\":\"items\",\"mapping\":$EMAP}")
echo "  counts: $(body_of "$R" | sed -n 's/.*"counts":{\([^}]*\)}.*/\1/p')"
expect "생략 시에도 insert 25" 25 "$(body_of "$R" | sed -n 's/.*"insert":\([0-9]*\).*/\1/p')"

sub "⑪-e 범위 밖 header_row 는 400 (0~19 만)"
for BAD in 20 99 -1 abc; do
  R=$(call qa_admin01 POST "/api/purchase/import/$ECOUNT_ID/preview" "$TOK_ADMIN" \
      "{\"kind\":\"items\",\"mapping\":$EMAP,\"header_row\":\"$BAD\"}")
  expect "header_row=$BAD → 400" 400 "$(code_of "$R")"
done

sub "⑪-f 확정 — 분모 1 인 25행만 저장되고 확인 필요 5행은 건너뛴다"
# 화면은 미리보기·확정에 같은 본문 함수를 써서 표시용 키(page·status·q)가 섞여 올 수 있다.
# 2026-09-13 20:05 확정이 이 키 때문에 전부 `bad_request` 400 이 났다 — 확정은 이 셋을 받아서
# 무시해야 한다. 그래서 여기서는 **화면이 실제로 보내던 모양 그대로** 확정을 부른다.
ITEMS_BEFORE=$(psqlq "SELECT count(*) FROM p_items;")
R=$(call qa_admin01 POST "/api/purchase/import/$ECOUNT_ID/commit" "$TOK_ADMIN" \
    "{\"kind\":\"items\",\"mapping\":$EMAP,\"header_row\":1,\"page\":1,\"status\":\"\",\"q\":\"\"}")
echo "  HTTP $(code_of "$R")  $(body_of "$R")"
expect "확정 200" 200 "$(code_of "$R")"
expect "inserted 25" 25 "$(body_of "$R" | sed -n 's/.*"inserted":\([0-9]*\).*/\1/p')"
expect "표에 25행만 늘었다" 25 "$(($(psqlq "SELECT count(*) FROM p_items;") - ITEMS_BEFORE))"
expect "분모 2 행(E00026~)은 저장되지 않았다" 0 "$(psqlq "SELECT count(*) FROM p_items WHERE code IN ('S19-E00026','S19-E00027','S19-E00028','S19-E00029','S19-E00030');")"
expect "분자 10 ÷ 분모 1 = 낱개 10" 25 "$(psqlq "SELECT count(*) FROM p_items WHERE code LIKE 'S19-E%' AND pack_qty = 10;")"

sub "⑪-g 매핑에 머리행까지 저장되고, 다시 올리면 그대로 돌아온다"
R=$(call qa_admin01 POST /api/purchase/import/mappings "$TOK_ADMIN" \
    "{\"kind\":\"items\",\"name\":\"S19-이카운트 ESA009M\",\"mapping\":$EMAP,\"header_row\":1}")
echo "  HTTP $(code_of "$R")  $(body_of "$R" | head -c 200)"
expect "매핑+머리행 저장 201" 201 "$(code_of "$R")"
expect "저장 응답의 header_row=1" 1 "$(body_of "$R" | grep -o '"header_row":[0-9]*' | head -1 | cut -d: -f2)"
R=$(call qa_admin01 GET /api/purchase/import/mappings "$TOK_ADMIN")
expect "목록에 머리행이 함께 있다" 1 "$(body_of "$R" | grep -c 'S19-이카운트 ESA009M')"
R=$(curl -s -w '\n%{http_code}' -b "$JAR_DIR/qa_admin01.jar" -A "$UA" \
    -H "X-CSRF-Token: $TOK_ADMIN" -F "file=@$WORK/ecount.xlsx" "$BASE/api/purchase/import")
expect "재업로드 시 저장된 매핑이 머리행과 함께 내려온다" 1 "$(body_of "$R" | grep -c 'S19-이카운트 ESA009M')"

sub "⑪-h 매핑 저장에도 범위 밖 머리행은 400"
R=$(call qa_admin01 POST /api/purchase/import/mappings "$TOK_ADMIN" \
    "{\"kind\":\"items\",\"name\":\"S19-범위밖\",\"mapping\":$EMAP,\"header_row\":25}")
echo "  HTTP $(code_of "$R")  $(body_of "$R")"
expect "매핑 header_row=25 → 400" 400 "$(code_of "$R")"

sub "⑫ 이카운트형 거래처 파일 — 같은 이름·다른 코드 (2차 보정 2026-09-13)"
# 실제 ESA001M(184행)에서 확정이 23505 → 409 로 통째로 롤백되던 모양을 그대로 줄여 만든다:
#   같은 이름·다른 코드 2쌍 · 이름 빈 행 1 · 사용구분 NO 1.
export VENDOR_XLSX="$WORK/vendors.xlsx"
php -d error_reporting=E_ALL -r '
$header = ["거래처코드","거래처그룹1명","거래처명","대표자명","전화","모바일","검색창내용","사용구분","이체정보"];
$rows = [["회사명 : 주식회사 플란랩스"], $header];
for ($i = 1; $i <= 6; $i++) {
    $rows[] = ["S19-V1".str_pad((string)$i, 4, "0", STR_PAD_LEFT), "그룹", "S19-VEND ".$i, "대표".$i,
               "02-000-000".$i, "010-0000-000".$i, "", "YES", "등록"];
}
// 같은 이름·다른 코드 2쌍 (VEND 1 · VEND 2 를 다시)
$rows[] = ["S19-V29001", "그룹", "S19-VEND 1", "대표A", "02-111-1111", "", "", "YES", "등록"];
$rows[] = ["S19-V29002", "그룹", "S19-VEND 2", "대표B", "02-222-2222", "", "", "YES", "등록"];
// 이름 빈 행 1
$rows[] = ["S19-V29003", "그룹", "", "대표C", "02-333-3333", "", "", "YES", "등록"];
// 사용구분 NO 1
$rows[] = ["S19-V29004", "그룹", "S19-VEND STOPPED", "대표D", "02-444-4444", "", "", "NO", "등록"];

$esc = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_XML1, "UTF-8");
$sheet = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>"
       . "<worksheet xmlns=\"http://schemas.openxmlformats.org/spreadsheetml/2006/main\"><sheetData>";
foreach ($rows as $r => $row) {
    $sheet .= "<row r=\"".($r+1)."\">";
    foreach ($row as $c => $cell) {
        $sheet .= "<c r=\"".chr(65+$c).($r+1)."\" t=\"inlineStr\"><is><t>".$esc((string)$cell)."</t></is></c>";
    }
    $sheet .= "</row>";
}
$sheet .= "</sheetData></worksheet>";
$ct = "<?xml version=\"1.0\" encoding=\"UTF-8\"?><Types xmlns=\"http://schemas.openxmlformats.org/package/2006/content-types\">"
    . "<Default Extension=\"rels\" ContentType=\"application/vnd.openxmlformats-package.relationships+xml\"/>"
    . "<Default Extension=\"xml\" ContentType=\"application/xml\"/>"
    . "<Override PartName=\"/xl/workbook.xml\" ContentType=\"application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml\"/>"
    . "<Override PartName=\"/xl/worksheets/sheet1.xml\" ContentType=\"application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml\"/></Types>";
$rels = "<?xml version=\"1.0\" encoding=\"UTF-8\"?><Relationships xmlns=\"http://schemas.openxmlformats.org/package/2006/relationships\">"
      . "<Relationship Id=\"rId1\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument\" Target=\"xl/workbook.xml\"/></Relationships>";
$wb = "<?xml version=\"1.0\" encoding=\"UTF-8\"?><workbook xmlns=\"http://schemas.openxmlformats.org/spreadsheetml/2006/main\""
    . " xmlns:r=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships\">"
    . "<sheets><sheet name=\"Sheet1\" sheetId=\"1\" r:id=\"rId1\"/></sheets></workbook>";
$wbRels = "<?xml version=\"1.0\" encoding=\"UTF-8\"?><Relationships xmlns=\"http://schemas.openxmlformats.org/package/2006/relationships\">"
        . "<Relationship Id=\"rId1\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet\" Target=\"worksheets/sheet1.xml\"/></Relationships>";
$path = getenv("VENDOR_XLSX");
@unlink($path);
$zip = new ZipArchive(); $zip->open($path, ZipArchive::CREATE);
$zip->addFromString("[Content_Types].xml", $ct);
$zip->addFromString("_rels/.rels", $rels);
$zip->addFromString("xl/workbook.xml", $wb);
$zip->addFromString("xl/_rels/workbook.xml.rels", $wbRels);
$zip->addFromString("xl/worksheets/sheet1.xml", $sheet);
$zip->close();
echo "  만든 파일: ", filesize($path), " 바이트 (거래처 10행: 정상 6 · 같은 이름 2 · 빈 이름 1 · 사용구분 NO 1)\n";
'

R=$(curl -s -w '\n%{http_code}' -b "$JAR_DIR/qa_admin01.jar" -A "$UA" \
    -H "X-CSRF-Token: $TOK_ADMIN" -F "file=@$WORK/vendors.xlsx" "$BASE/api/purchase/import")
expect "거래처 파일 업로드 201" 201 "$(code_of "$R")"
VEND_ID=$(body_of "$R" | sed -n 's/.*"import_id":"\([0-9a-f-]*\)".*/\1/p')
echo "  자동 감지 머리행: $(body_of "$R" | grep -o '"header_row":[0-9]*' | head -1 | cut -d: -f2)"
expect "거래처 머리행 자동 감지 1" 1 "$(body_of "$R" | grep -o '"header_row":[0-9]*' | head -1 | cut -d: -f2)"
expect "대표자명→ceo_name 후보" 1 "$(body_of "$R" | grep -c '"대표자명":"ceo_name"')"
expect "사용구분→active 후보" 1 "$(body_of "$R" | grep -c '"사용구분":"active"')"

VMAP='{"거래처코드":"code","거래처명":"name","대표자명":"ceo_name","전화":"tel","모바일":"tel","사용구분":"active","거래처그룹1명":"skip","검색창내용":"skip","이체정보":"skip"}'
R=$(call qa_admin01 POST "/api/purchase/import/$VEND_ID/preview" "$TOK_ADMIN" \
    "{\"kind\":\"vendors\",\"mapping\":$VMAP,\"header_row\":1}")
echo "  HTTP $(code_of "$R")  counts: $(body_of "$R" | sed -n 's/.*"counts":{\([^}]*\)}.*/\1/p')"
expect "미리보기 200" 200 "$(code_of "$R")"
expect "insert 7 (정상 6 + 사용구분 NO 1)" 7 "$(body_of "$R" | sed -n 's/.*"insert":\([0-9]*\).*/\1/p')"
expect "review 2 (같은 이름·다른 코드)" 2 "$(body_of "$R" | sed -n 's/.*"review":\([0-9]*\).*/\1/p')"
expect "error 1 (이름 빈 행)" 1 "$(body_of "$R" | sed -n 's/.*"error":\([0-9]*\).*/\1/p')"
expect "사유 duplicate_name_in_file" 1 "$(body_of "$R" | grep -c 'duplicate_name_in_file')"

sub "⑫-a 확정 — **409 가 나지 않는다**(겹치는 행은 판정에서 미리 걸렀다)"
VB=$(psqlq "SELECT count(*) FROM p_vendors;")
R=$(call qa_admin01 POST "/api/purchase/import/$VEND_ID/commit" "$TOK_ADMIN" \
    "{\"kind\":\"vendors\",\"mapping\":$VMAP,\"header_row\":1}")
echo "  HTTP $(code_of "$R")  $(body_of "$R")"
expect "확정 200 (409 아님)" 200 "$(code_of "$R")"
expect "inserted 7" 7 "$(body_of "$R" | sed -n 's/.*"inserted":\([0-9]*\).*/\1/p')"
expect "표에 7행만 늘었다" 7 "$(($(psqlq "SELECT count(*) FROM p_vendors;") - VB))"
expect "확인 필요 행(V29001·V29002)은 저장 안 됨" 0 "$(psqlq "SELECT count(*) FROM p_vendors WHERE code IN ('S19-V29001','S19-V29002');")"
expect "빈 이름 행(V29003) 저장 안 됨" 0 "$(psqlq "SELECT count(*) FROM p_vendors WHERE code='S19-V29003';")"
expect "사용구분 NO → is_active=false" 1 "$(psqlq "SELECT count(*) FROM p_vendors WHERE code='S19-V29004' AND NOT is_active;")"
expect "대표자명·전화가 들어갔다" 1 "$(psqlq "SELECT count(*) FROM p_vendors WHERE code='S19-V10001' AND ceo_name='대표1' AND tel='02-000-0001';")"
# ★ 표 전체를 세지 않는다 — 사용자의 실제 거래처는 「전화」 칸에 휴대폰 번호가 들어 있는
#   경우가 흔하다(실측). **이 시험이 만든 거래처(V1xxxx)** 만 본다.
expect "모바일이 아니라 전화가 남았다" 0 "$(psqlq "SELECT count(*) FROM p_vendors WHERE code LIKE 'S19-V1%' AND tel LIKE '010-%';")"
expect "확정 감사에 conflict 없음" 0 "$(psqlq "SELECT count(*) FROM audit_logs WHERE action='purchase.import.commit' AND reason='conflict' AND happened_at > now() - interval '2 minutes';")"

sub "⑫-b 「확인 필요」를 승격하면 **새로 등록**된다 (promote_to = insert)"
R=$(call qa_admin01 POST "/api/purchase/import/$VEND_ID/commit" "$TOK_ADMIN" \
    "{\"kind\":\"vendors\",\"mapping\":$VMAP,\"header_row\":1,\"promote\":[9,10]}")
echo "  HTTP $(code_of "$R")  $(body_of "$R")"
expect "승격 확정 200" 200 "$(code_of "$R")"
expect "같은 이름·다른 코드가 따로 등록됐다" 2 "$(psqlq "SELECT count(*) FROM p_vendors WHERE code IN ('S19-V29001','S19-V29002');")"
expect "같은 이름이 두 건 공존한다" 2 "$(psqlq "SELECT count(*) FROM p_vendors WHERE name='S19-VEND 1';")"

sub "⑬ 창고 파일 — 지점 열이 없다 (2차 보정)"
export WH_XLSX="$WORK/warehouse.xlsx"
php -d error_reporting=E_ALL -r '
$header = ["창고코드","창고명","구분","생산공정명","외주거래처명","사용","추가사업장명"];
$rows = [["회사명 : 주식회사 플란랩스"], $header];
// 창고명에 지점 이름이 든 것 2 · 안 든 것 2 · 사용 No 1
$rows[] = ["00001","S19-플란치과_S19-부평점","창고","","","Yes","주식회사 플란랩스"];
$rows[] = ["00002","S19-플란치과_S19-일산점","창고","","","Yes","주식회사 플란랩스"];
$rows[] = ["00003","S19-라미트치과","창고","","","Yes","주식회사 플란랩스"];
$rows[] = ["00004","S19-중앙창고","기타","","","Yes","주식회사 플란랩스"];
$rows[] = ["00005","S19-폐기창고","창고","","","No","주식회사 플란랩스"];
$esc = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_XML1, "UTF-8");
$sheet = "<?xml version=\"1.0\" encoding=\"UTF-8\"?><worksheet xmlns=\"http://schemas.openxmlformats.org/spreadsheetml/2006/main\"><sheetData>";
foreach ($rows as $r => $row) {
    $sheet .= "<row r=\"".($r+1)."\">";
    foreach ($row as $c => $cell) { $sheet .= "<c r=\"".chr(65+$c).($r+1)."\" t=\"inlineStr\"><is><t>".$esc((string)$cell)."</t></is></c>"; }
    $sheet .= "</row>";
}
$sheet .= "</sheetData></worksheet>";
$ct = "<?xml version=\"1.0\" encoding=\"UTF-8\"?><Types xmlns=\"http://schemas.openxmlformats.org/package/2006/content-types\">"
    . "<Default Extension=\"rels\" ContentType=\"application/vnd.openxmlformats-package.relationships+xml\"/>"
    . "<Default Extension=\"xml\" ContentType=\"application/xml\"/>"
    . "<Override PartName=\"/xl/workbook.xml\" ContentType=\"application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml\"/>"
    . "<Override PartName=\"/xl/worksheets/sheet1.xml\" ContentType=\"application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml\"/></Types>";
$rels = "<?xml version=\"1.0\" encoding=\"UTF-8\"?><Relationships xmlns=\"http://schemas.openxmlformats.org/package/2006/relationships\">"
      . "<Relationship Id=\"rId1\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument\" Target=\"xl/workbook.xml\"/></Relationships>";
$wb = "<?xml version=\"1.0\" encoding=\"UTF-8\"?><workbook xmlns=\"http://schemas.openxmlformats.org/spreadsheetml/2006/main\" xmlns:r=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships\"><sheets><sheet name=\"Sheet1\" sheetId=\"1\" r:id=\"rId1\"/></sheets></workbook>";
$wbRels = "<?xml version=\"1.0\" encoding=\"UTF-8\"?><Relationships xmlns=\"http://schemas.openxmlformats.org/package/2006/relationships\"><Relationship Id=\"rId1\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet\" Target=\"worksheets/sheet1.xml\"/></Relationships>";
$path = getenv("WH_XLSX"); @unlink($path);
$zip = new ZipArchive(); $zip->open($path, ZipArchive::CREATE);
$zip->addFromString("[Content_Types].xml", $ct); $zip->addFromString("_rels/.rels", $rels);
$zip->addFromString("xl/workbook.xml", $wb); $zip->addFromString("xl/_rels/workbook.xml.rels", $wbRels);
$zip->addFromString("xl/worksheets/sheet1.xml", $sheet); $zip->close();
echo "  만든 파일: ", filesize($path), " 바이트 (창고 5행, 지점 열 없음)\n";
'

R=$(curl -s -w '\n%{http_code}' -b "$JAR_DIR/qa_admin01.jar" -A "$UA" \
    -H "X-CSRF-Token: $TOK_ADMIN" -F "file=@$WORK/warehouse.xlsx" "$BASE/api/purchase/import")
expect "창고 파일 업로드 201" 201 "$(code_of "$R")"
WH_ID=$(body_of "$R" | sed -n 's/.*"import_id":"\([0-9a-f-]*\)".*/\1/p')
expect "창고명→name 후보(동의어 보강)" 1 "$(body_of "$R" | grep -c '"창고명":"name"')"
expect "창고코드→code 후보" 1 "$(body_of "$R" | grep -c '"창고코드":"code"')"

WMAP='{"창고코드":"code","창고명":"name","구분":"kind","사용":"active","생산공정명":"skip","외주거래처명":"skip","추가사업장명":"skip"}'

sub "⑬-a 기본 지점을 안 고르면 400 (행마다 오류를 내지 않고 한 번에 말한다)"
R=$(call qa_admin01 POST "/api/purchase/import/$WH_ID/preview" "$TOK_ADMIN" \
    "{\"kind\":\"locations\",\"mapping\":$WMAP,\"header_row\":1}")
echo "  HTTP $(code_of "$R")  $(body_of "$R")"
expect "기본 지점 없음 → 400" 400 "$(code_of "$R")"
psqlq "SELECT '  감사: '||action||' '||result||' '||coalesce(reason,'-') FROM audit_logs
        WHERE action='purchase.import.preview' ORDER BY id DESC LIMIT 1;"

sub "⑬-b 기본 지점을 고르면 판정된다 — 창고명에 지점 이름이 있으면 **그 지점**으로"
R=$(call qa_admin01 POST "/api/purchase/import/$WH_ID/preview" "$TOK_ADMIN" \
    "{\"kind\":\"locations\",\"mapping\":$WMAP,\"header_row\":1,\"default_branch\":$BR_A}")
echo "  HTTP $(code_of "$R")  counts: $(body_of "$R" | sed -n 's/.*"counts":{\([^}]*\)}.*/\1/p')"
echo "  본문(앞 520자): $(body_of "$R" | head -c 520)"
expect "미리보기 200" 200 "$(code_of "$R")"
expect "insert 5" 5 "$(body_of "$R" | sed -n 's/.*"insert":\([0-9]*\).*/\1/p')"
expect "창고명에서 찾은 지점(branch_by=name)이 있다" 1 "$(body_of "$R" | grep -c '"branch_by":"name"')"
expect "기본 지점으로 간 행(branch_by=default)도 있다" 1 "$(body_of "$R" | grep -c '"branch_by":"default"')"

R=$(call qa_admin01 POST "/api/purchase/import/$WH_ID/commit" "$TOK_ADMIN" \
    "{\"kind\":\"locations\",\"mapping\":$WMAP,\"header_row\":1,\"default_branch\":$BR_A}")
echo "  확정 HTTP $(code_of "$R")  $(body_of "$R")"
expect "창고 확정 200" 200 "$(code_of "$R")"
expect "「플란치과_일산점」은 일산점으로 갔다" 1 "$(psqlq "SELECT count(*) FROM p_locations WHERE name='S19-플란치과_S19-일산점' AND branch_id=$BR_B;")"
expect "「플란치과_부평점」은 부평점으로 갔다" 1 "$(psqlq "SELECT count(*) FROM p_locations WHERE name='S19-플란치과_S19-부평점' AND branch_id=$BR_A;")"
expect "지점 이름이 없는 「중앙창고」는 기본 지점으로" 1 "$(psqlq "SELECT count(*) FROM p_locations WHERE name='S19-중앙창고' AND branch_id=$BR_A;")"
expect "구분 「기타」 → other" 1 "$(psqlq "SELECT count(*) FROM p_locations WHERE name='S19-중앙창고' AND kind='other';")"
expect "사용 No → is_active=false" 1 "$(psqlq "SELECT count(*) FROM p_locations WHERE name='S19-폐기창고' AND NOT is_active;")"

sub "⑭ 창고명에서 지점 파생 등록 (kind=branches · derive=location_name)"
BB=$(psqlq "SELECT count(*) FROM p_branches;")
R=$(call qa_admin01 POST "/api/purchase/import/$WH_ID/preview" "$TOK_ADMIN" \
    "{\"kind\":\"branches\",\"mapping\":$WMAP,\"header_row\":1,\"derive\":\"location_name\",\"default_brand\":$BRAND}")
echo "  HTTP $(code_of "$R")  counts: $(body_of "$R" | sed -n 's/.*"counts":{\([^}]*\)}.*/\1/p')"
echo "  본문(앞 400자): $(body_of "$R" | head -c 400)"
expect "파생 미리보기 200" 200 "$(code_of "$R")"
# 창고 5개에서 뽑히는 서로 다른 이름: 부평점·일산점·라미트치과·중앙창고·폐기창고 = 5
expect "중복을 뺀 지점 후보 5" 5 "$(body_of "$R" | sed -n 's/.*"total":\([0-9]*\).*/\1/p')"
expect "이미 있는 지점 2개는 갱신(=건너뜀)" 2 "$(body_of "$R" | sed -n 's/.*"update":\([0-9]*\).*/\1/p')"
expect "새 지점 3개" 3 "$(body_of "$R" | sed -n 's/.*"insert":\([0-9]*\).*/\1/p')"

R=$(call qa_admin01 POST "/api/purchase/import/$WH_ID/commit" "$TOK_ADMIN" \
    "{\"kind\":\"branches\",\"mapping\":$WMAP,\"header_row\":1,\"derive\":\"location_name\",\"default_brand\":$BRAND}")
echo "  확정 HTTP $(code_of "$R")  $(body_of "$R")"
expect "파생 확정 200" 200 "$(code_of "$R")"
expect "지점이 3개 늘었다" 3 "$(($(psqlq "SELECT count(*) FROM p_branches;") - BB))"
expect "「라미트치과」가 지점으로 생겼다" 1 "$(psqlq "SELECT count(*) FROM p_branches WHERE name='S19-라미트치과';")"
expect "이미 있던 부평점은 그대로 1건" 1 "$(psqlq "SELECT count(*) FROM p_branches WHERE name='S19-부평점';")"

sub "⑭-a 브랜드 열도 기본 브랜드도 없으면 400"
R=$(call qa_admin01 POST "/api/purchase/import/$WH_ID/preview" "$TOK_ADMIN" \
    "{\"kind\":\"branches\",\"mapping\":$WMAP,\"header_row\":1,\"derive\":\"location_name\"}")
echo "  HTTP $(code_of "$R")  $(body_of "$R")"
expect "기본 브랜드 없음 → 400" 400 "$(code_of "$R")"

sub "⑮ 수동 거래처 등록 — 같은 이름은 409, 확인하면 통과 (2차 보정)"
R=$(call qa_admin01 POST /api/purchase/vendors "$TOK_ADMIN" '{"name":"S19-VEND 1","code":"S19-V90001"}')
echo "  같은 이름 → HTTP $(code_of "$R")  $(body_of "$R")"
expect "같은 이름 409" 409 "$(code_of "$R")"
R=$(call qa_admin01 POST /api/purchase/vendors "$TOK_ADMIN" '{"name":"S19-VEND 1","code":"S19-V90001","allow_duplicate_name":true}')
echo "  그래도 등록 → HTTP $(code_of "$R")  $(body_of "$R")"
expect "allow_duplicate_name → 201" 201 "$(code_of "$R")"
expect "같은 코드는 여전히 409" 409 "$(code_of "$(call qa_admin01 POST /api/purchase/vendors "$TOK_ADMIN" '{"name":"S19-다른이름","code":"S19-V90001","allow_duplicate_name":true}')")"

sub "⑯ 없는 거래처를 등록하면 **재판정만으로** 오류가 등록으로 바뀐다 (3차 보정)"
# 화면은 이 흐름을 팝업으로 한다: 오류 행 옆 「거래처 등록」 → 201 → 같은 import_id 로 preview 재요청.
# 여기서는 **서버 흐름**만 본다(재판정은 재업로드가 아니라 같은 창구를 다시 부르는 것이다).
export MISS_XLSX="$WORK/missvendor.xlsx"
php -d error_reporting=E_ALL -r '
$header = ["품목코드","품목명","구매처명","단위"];
$rows = [["회사명 : 시험"], $header];
for ($i = 1; $i <= 4; $i++) { $rows[] = ["S19-M".str_pad((string)$i,5,"0",STR_PAD_LEFT), "S19-MISS ITEM ".$i, "S19-새거래처A", "EA"]; }
$rows[] = ["S19-M90001", "S19-MISS ITEM 9", "S19-또다른거래처B", "EA"];
$esc = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_XML1, "UTF-8");
$sheet = "<?xml version=\"1.0\" encoding=\"UTF-8\"?><worksheet xmlns=\"http://schemas.openxmlformats.org/spreadsheetml/2006/main\"><sheetData>";
foreach ($rows as $r => $row) { $sheet .= "<row r=\"".($r+1)."\">";
  foreach ($row as $c => $cell) { $sheet .= "<c r=\"".chr(65+$c).($r+1)."\" t=\"inlineStr\"><is><t>".$esc((string)$cell)."</t></is></c>"; }
  $sheet .= "</row>"; }
$sheet .= "</sheetData></worksheet>";
$ct = "<?xml version=\"1.0\" encoding=\"UTF-8\"?><Types xmlns=\"http://schemas.openxmlformats.org/package/2006/content-types\">"
    . "<Default Extension=\"rels\" ContentType=\"application/vnd.openxmlformats-package.relationships+xml\"/>"
    . "<Default Extension=\"xml\" ContentType=\"application/xml\"/>"
    . "<Override PartName=\"/xl/workbook.xml\" ContentType=\"application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml\"/>"
    . "<Override PartName=\"/xl/worksheets/sheet1.xml\" ContentType=\"application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml\"/></Types>";
$rels = "<?xml version=\"1.0\" encoding=\"UTF-8\"?><Relationships xmlns=\"http://schemas.openxmlformats.org/package/2006/relationships\">"
      . "<Relationship Id=\"rId1\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument\" Target=\"xl/workbook.xml\"/></Relationships>";
$wb = "<?xml version=\"1.0\" encoding=\"UTF-8\"?><workbook xmlns=\"http://schemas.openxmlformats.org/spreadsheetml/2006/main\" xmlns:r=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships\"><sheets><sheet name=\"Sheet1\" sheetId=\"1\" r:id=\"rId1\"/></sheets></workbook>";
$wbRels = "<?xml version=\"1.0\" encoding=\"UTF-8\"?><Relationships xmlns=\"http://schemas.openxmlformats.org/package/2006/relationships\"><Relationship Id=\"rId1\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet\" Target=\"worksheets/sheet1.xml\"/></Relationships>";
$path = getenv("MISS_XLSX"); @unlink($path);
$zip = new ZipArchive(); $zip->open($path, ZipArchive::CREATE);
$zip->addFromString("[Content_Types].xml", $ct); $zip->addFromString("_rels/.rels", $rels);
$zip->addFromString("xl/workbook.xml", $wb); $zip->addFromString("xl/_rels/workbook.xml.rels", $wbRels);
$zip->addFromString("xl/worksheets/sheet1.xml", $sheet); $zip->close();
echo "  만든 파일: ", filesize($path), " 바이트 (없는 거래처 2가지 · 5행)\n";
'

R=$(curl -s -w '\n%{http_code}' -b "$JAR_DIR/qa_admin01.jar" -A "$UA" \
    -H "X-CSRF-Token: $TOK_ADMIN" -F "file=@$WORK/missvendor.xlsx" "$BASE/api/purchase/import")
expect "업로드 201" 201 "$(code_of "$R")"
MISS_ID=$(body_of "$R" | sed -n 's/.*"import_id":"\([0-9a-f-]*\)".*/\1/p')
MMAP='{"품목코드":"code","품목명":"name","구매처명":"vendor","단위":"unit"}'

R=$(call qa_admin01 POST "/api/purchase/import/$MISS_ID/preview" "$TOK_ADMIN" \
    "{\"kind\":\"items\",\"mapping\":$MMAP,\"header_row\":1}")
echo "  1차 판정 counts: $(body_of "$R" | sed -n 's/.*"counts":{\([^}]*\)}.*/\1/p')"
echo "  missing: $(body_of "$R" | sed -n 's/.*"missing":\(\[.*\]\).*/\1/p' | head -c 320)"
expect "1차 미리보기 200" 200 "$(code_of "$R")"
expect "1차 error 5" 5 "$(body_of "$R" | sed -n 's/.*"error":\([0-9]*\).*/\1/p')"
# `"kind":"vendors"` 는 행마다(missing)·묶음마다 나온다 → **묶음에만 있는** first_row 로 센다.
expect "없는 값이 **2가지**로 묶인다" 2 "$(body_of "$R" | grep -o '"first_row":[0-9]*' | wc -l)"
expect "「새거래처A」 4행으로 묶임" 1 "$(body_of "$R" | grep -c '"value":"S19-새거래처A","reason":"vendor_not_found","rows":4')"

sub "⑯-a 팝업이 부르는 것과 **같은 창구**로 거래처를 만든다"
R=$(call qa_admin01 POST /api/purchase/vendors "$TOK_ADMIN" '{"name":"S19-새거래처A"}')
expect "거래처 등록 201" 201 "$(code_of "$R")"

sub "⑯-b 재업로드 없이 같은 import_id 로 다시 판정하면 4행이 풀린다"
R=$(call qa_admin01 POST "/api/purchase/import/$MISS_ID/preview" "$TOK_ADMIN" \
    "{\"kind\":\"items\",\"mapping\":$MMAP,\"header_row\":1}")
echo "  2차 판정 counts: $(body_of "$R" | sed -n 's/.*"counts":{\([^}]*\)}.*/\1/p')"
expect "2차 insert 4" 4 "$(body_of "$R" | sed -n 's/.*"insert":\([0-9]*\).*/\1/p')"
expect "2차 error 1 (남은 거래처 하나)" 1 "$(body_of "$R" | sed -n 's/.*"error":\([0-9]*\).*/\1/p')"
expect "남은 없는 값은 1가지" 1 "$(body_of "$R" | grep -o '"first_row":[0-9]*' | wc -l)"

sub "⑯-c exclude_rows — 체크를 끈 행은 저장되지 않는다"
IB=$(psqlq "SELECT count(*) FROM p_items;")
# `audit_logs` 는 추가 전용이라 **지난 실행의 행이 그대로 남는다** — 시각 창으로 세면
# 조금 전 실행분까지 잡힌다(2026-09-13 실측). 그래서 여기서도 **증가분**을 본다.
EXC_BEFORE=$(psqlq "SELECT count(*) FROM audit_logs
        WHERE action='purchase.import.commit' AND result='allow' AND detail->>'excluded' = '2';")
R=$(call qa_admin01 POST "/api/purchase/import/$MISS_ID/commit" "$TOK_ADMIN" \
    "{\"kind\":\"items\",\"mapping\":$MMAP,\"header_row\":1,\"exclude_rows\":[3,4]}")
echo "  HTTP $(code_of "$R")  $(body_of "$R")"
expect "확정 200" 200 "$(code_of "$R")"
expect "inserted 2 (4행 중 2행을 뺐다)" 2 "$(body_of "$R" | sed -n 's/.*"inserted":\([0-9]*\).*/\1/p')"
expect "excluded 2" 2 "$(body_of "$R" | sed -n 's/.*"excluded":\([0-9]*\).*/\1/p')"
expect "표에 2행만 늘었다" 2 "$(($(psqlq "SELECT count(*) FROM p_items;") - IB))"
expect "뺀 행(M00001·M00002)은 저장 안 됨" 0 "$(psqlq "SELECT count(*) FROM p_items WHERE code IN ('S19-M00001','S19-M00002');")"
psqlq "SELECT '  감사 detail: '||detail::text FROM audit_logs
        WHERE action='purchase.import.commit' AND result='allow' ORDER BY id DESC LIMIT 1;"
EXC_AFTER=$(psqlq "SELECT count(*) FROM audit_logs
        WHERE action='purchase.import.commit' AND result='allow' AND detail->>'excluded' = '2';")
expect "감사에 excluded=2 가 한 줄 늘었다" 1 "$((EXC_AFTER - EXC_BEFORE))"

sub "⑯-d exclude_rows 형식 검사 — 문자열·1000개 초과는 400"
R=$(call qa_admin01 POST "/api/purchase/import/$MISS_ID/commit" "$TOK_ADMIN" \
    "{\"kind\":\"items\",\"mapping\":$MMAP,\"header_row\":1,\"exclude_rows\":[\"abc\"]}")
echo "  문자열 → HTTP $(code_of "$R")  $(body_of "$R")"
expect "문자열 exclude_rows → 400" 400 "$(code_of "$R")"
R=$(call qa_admin01 POST "/api/purchase/import/$MISS_ID/commit" "$TOK_ADMIN" \
    "{\"kind\":\"items\",\"mapping\":$MMAP,\"header_row\":1,\"exclude_rows\":\"3\"}")
expect "배열이 아닌 exclude_rows → 400" 400 "$(code_of "$R")"
BIG=$(python3 -c "print('[' + ','.join(str(i) for i in range(1, 1002)) + ']')")
R=$(call qa_admin01 POST "/api/purchase/import/$MISS_ID/commit" "$TOK_ADMIN" \
    "{\"kind\":\"items\",\"mapping\":$MMAP,\"header_row\":1,\"exclude_rows\":$BIG}")
expect "1000개 초과 → 400" 400 "$(code_of "$R")"

sub "⑰ 같은 이름 거래처가 여럿이면 「확인 필요」 (SECURITY R-72)"
# ⑫-b 에서 「VEND 1」 이 두 건 공존한다 — 그 이름으로 품목을 가져오면 어느 쪽인지 알 수 없다.
# 앞선 시험(⑫-b 승격·⑮ 수동 등록)이 몇 건을 남겼는지에 기대지 않는다 — **지금 세어** 쓴다.
VEND1_N=$(psqlq "SELECT count(*) FROM p_vendors WHERE name='S19-VEND 1';")
echo "  같은 이름 「VEND 1」 거래처: ${VEND1_N}건"
expect "준비: 같은 이름 거래처가 2건 이상" yes "$([ "$VEND1_N" -ge 2 ] && echo yes || echo no)"
export AMB_XLSX="$WORK/ambiguous.xlsx"
php -d error_reporting=E_ALL -r '
$header = ["품목코드","품목명","구매처명","거래처코드","단위"];
$rows = [["회사명 : 시험"], $header];
$rows[] = ["S19-A00001","S19-AMB ITEM 1","S19-VEND 1","","EA"];
$rows[] = ["S19-A00002","S19-AMB ITEM 2","S19-VEND 1","","EA"];
$rows[] = ["S19-A00003","S19-AMB ITEM 3","S19-VEND 1","S19-V29001","EA"];
$esc = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_XML1, "UTF-8");
$sheet = "<?xml version=\"1.0\" encoding=\"UTF-8\"?><worksheet xmlns=\"http://schemas.openxmlformats.org/spreadsheetml/2006/main\"><sheetData>";
foreach ($rows as $r => $row) { $sheet .= "<row r=\"".($r+1)."\">";
  foreach ($row as $c => $cell) { $sheet .= "<c r=\"".chr(65+$c).($r+1)."\" t=\"inlineStr\"><is><t>".$esc((string)$cell)."</t></is></c>"; }
  $sheet .= "</row>"; }
$sheet .= "</sheetData></worksheet>";
$ct = "<?xml version=\"1.0\" encoding=\"UTF-8\"?><Types xmlns=\"http://schemas.openxmlformats.org/package/2006/content-types\">"
    . "<Default Extension=\"rels\" ContentType=\"application/vnd.openxmlformats-package.relationships+xml\"/>"
    . "<Default Extension=\"xml\" ContentType=\"application/xml\"/>"
    . "<Override PartName=\"/xl/workbook.xml\" ContentType=\"application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml\"/>"
    . "<Override PartName=\"/xl/worksheets/sheet1.xml\" ContentType=\"application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml\"/></Types>";
$rels = "<?xml version=\"1.0\" encoding=\"UTF-8\"?><Relationships xmlns=\"http://schemas.openxmlformats.org/package/2006/relationships\">"
      . "<Relationship Id=\"rId1\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument\" Target=\"xl/workbook.xml\"/></Relationships>";
$wb = "<?xml version=\"1.0\" encoding=\"UTF-8\"?><workbook xmlns=\"http://schemas.openxmlformats.org/spreadsheetml/2006/main\" xmlns:r=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships\"><sheets><sheet name=\"Sheet1\" sheetId=\"1\" r:id=\"rId1\"/></sheets></workbook>";
$wbRels = "<?xml version=\"1.0\" encoding=\"UTF-8\"?><Relationships xmlns=\"http://schemas.openxmlformats.org/package/2006/relationships\"><Relationship Id=\"rId1\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet\" Target=\"worksheets/sheet1.xml\"/></Relationships>";
$path = getenv("AMB_XLSX"); @unlink($path);
$zip = new ZipArchive(); $zip->open($path, ZipArchive::CREATE);
$zip->addFromString("[Content_Types].xml", $ct); $zip->addFromString("_rels/.rels", $rels);
$zip->addFromString("xl/workbook.xml", $wb); $zip->addFromString("xl/_rels/workbook.xml.rels", $wbRels);
$zip->addFromString("xl/worksheets/sheet1.xml", $sheet); $zip->close();
echo "  만든 파일: ", filesize($path), " 바이트 (같은 이름 거래처 3행 · 그중 1행은 코드 있음)\n";
'
R=$(curl -s -w '\n%{http_code}' -b "$JAR_DIR/qa_admin01.jar" -A "$UA" \
    -H "X-CSRF-Token: $TOK_ADMIN" -F "file=@$WORK/ambiguous.xlsx" "$BASE/api/purchase/import")
AMB_ID=$(body_of "$R" | sed -n 's/.*"import_id":"\([0-9a-f-]*\)".*/\1/p')
expect "업로드 201" 201 "$(code_of "$R")"

sub "⑰-a 코드 열을 안 매핑하면 3행 모두 「확인 필요」"
AMAP1='{"품목코드":"code","품목명":"name","구매처명":"vendor","단위":"unit","거래처코드":"skip"}'
R=$(call qa_admin01 POST "/api/purchase/import/$AMB_ID/preview" "$TOK_ADMIN" \
    "{\"kind\":\"items\",\"mapping\":$AMAP1,\"header_row\":1}")
echo "  counts: $(body_of "$R" | sed -n 's/.*"counts":{\([^}]*\)}.*/\1/p')"
echo "  본문(앞 360자): $(body_of "$R" | head -c 360)"
expect "review 3" 3 "$(body_of "$R" | sed -n 's/.*"review":\([0-9]*\).*/\1/p')"
expect "사유 vendor_ambiguous" 1 "$(body_of "$R" | grep -c 'vendor_ambiguous')"
expect "같은 이름 개수를 함께 준다" 1 "$(body_of "$R" | grep -c "\"vendor_count\":$VEND1_N")"

sub "⑰-b 거래처 코드 열을 매핑하면 그 행은 정상 등록으로 풀린다"
AMAP2='{"품목코드":"code","품목명":"name","구매처명":"vendor","단위":"unit","거래처코드":"vendor_code"}'
R=$(call qa_admin01 POST "/api/purchase/import/$AMB_ID/preview" "$TOK_ADMIN" \
    "{\"kind\":\"items\",\"mapping\":$AMAP2,\"header_row\":1}")
echo "  counts: $(body_of "$R" | sed -n 's/.*"counts":{\([^}]*\)}.*/\1/p')"
expect "코드가 있는 1행은 insert" 1 "$(body_of "$R" | sed -n 's/.*"insert":\([0-9]*\).*/\1/p')"
expect "코드가 없는 2행은 여전히 확인 필요" 2 "$(body_of "$R" | sed -n 's/.*"review":\([0-9]*\).*/\1/p')"

# ─── JSON 문자열 하나를 안전하게 감싼다 (따옴표·역슬래시만 이스케이프) ───
json_str() { printf '"%s"' "$(printf '%s' "$1" | sed 's/\\/\\\\/g; s/"/\\"/g')"; }

sub "⑱ 미리보기 행 목록의 쪽·필터·찾기 (6차 보정 2026-09-13)"
# ※ 앞의 ⑯ 파일(missvendor.xlsx, 5행)을 그대로 쓴다 — 더미를 새로 만들지 않는다.
if [ -n "${MISS_ID:-}" ]; then
  R=$(call qa_admin01 POST "/api/purchase/import/$MISS_ID/preview" "$TOK_ADMIN" \
      "{\"kind\":\"items\",\"mapping\":$MMAP,\"header_row\":1}")
  echo "  기본 row_page=$(body_of "$R" | grep -o '"row_page":[0-9]*' | head -1 | cut -d: -f2)"
  expect "한 쪽 50행 고정" 50 "$(body_of "$R" | grep -o '"row_per_page":[0-9]*' | head -1 | cut -d: -f2)"
  expect "row_total 이 내려온다" 1 "$(body_of "$R" | grep -c '"row_total":')"
  expect "total 은 여전히 전체 기준" 5 "$(body_of "$R" | sed -n 's/.*"total":\([0-9]*\).*/\1/p')"

  sub "⑱-a page 는 400 이 아니라 **좁힌다** (화면만 깨지고 막아지는 것이 없다 — ARCH 5.1 [2])"
  for BAD in 0 -3 abc 999999999; do
    R=$(call qa_admin01 POST "/api/purchase/import/$MISS_ID/preview" "$TOK_ADMIN" \
        "{\"kind\":\"items\",\"mapping\":$MMAP,\"header_row\":1,\"page\":$(json_str "$BAD")}")
    P=$(body_of "$R" | grep -o '"row_page":[0-9]*' | head -1 | cut -d: -f2)
    printf "  page=%-12s → HTTP %s · row_page=%s\n" "$BAD" "$(code_of "$R")" "$P"
    expect "page=$BAD → 200" 200 "$(code_of "$R")"
    expect "page=$BAD → 쪽 수 안으로" 1 "$P"
  done

  sub "⑱-b 상태 필터 — 거르기는 **서버가** 한다(50행 안에서만 거르면 사람을 속인다)"
  # ★ ⑱ 은 ⑯ **뒤에** 돈다 — 그때는 거래처가 이미 등록돼 판정이 달라져 있다.
  #   그래서 고정 숫자를 기대하지 않고 **같은 응답 안의 counts 와 견준다**(순서에 기대지 않는 단언).
  R=$(call qa_admin01 POST "/api/purchase/import/$MISS_ID/preview" "$TOK_ADMIN" \
      "{\"kind\":\"items\",\"mapping\":$MMAP,\"header_row\":1,\"status\":\"error\"}")
  ERR_COUNT=$(body_of "$R" | sed -n 's/.*"error":\([0-9]*\).*/\1/p')
  ERR_ROWS=$(body_of "$R" | grep -o '"row_total":[0-9]*' | head -1 | cut -d: -f2)
  echo "  status=error → counts.error=$ERR_COUNT · row_total=$ERR_ROWS"
  expect "status=error 200" 200 "$(code_of "$R")"
  expect "거른 행 수 = counts.error (거르기는 서버가 한다)" "$ERR_COUNT" "$ERR_ROWS"
  R=$(call qa_admin01 POST "/api/purchase/import/$MISS_ID/preview" "$TOK_ADMIN" \
      "{\"kind\":\"items\",\"mapping\":$MMAP,\"header_row\":1,\"status\":\"insert\"}")
  INS_COUNT=$(body_of "$R" | sed -n 's/.*"insert":\([0-9]*\).*/\1/p')
  INS_ROWS=$(body_of "$R" | grep -o '"row_total":[0-9]*' | head -1 | cut -d: -f2)
  echo "  status=insert → counts.insert=$INS_COUNT · row_total=$INS_ROWS"
  expect "거른 행 수 = counts.insert" "$INS_COUNT" "$INS_ROWS"
  R=$(call qa_admin01 POST "/api/purchase/import/$MISS_ID/preview" "$TOK_ADMIN" \
      "{\"kind\":\"items\",\"mapping\":$MMAP,\"header_row\":1,\"status\":\"엉뚱한값\"}")
  expect "모르는 status 는 전체로 (400 아님)" 200 "$(code_of "$R")"

  sub "⑱-c 찾기(q) — 판정 결과에서만 찾는다. SQL 에 닿지 않는다"
  R=$(call qa_admin01 POST "/api/purchase/import/$MISS_ID/preview" "$TOK_ADMIN" \
      "{\"kind\":\"items\",\"mapping\":$MMAP,\"header_row\":1,\"q\":\"S19-MISS ITEM 1\"}")
  Q_ROWS=$(body_of "$R" | grep -o '"row_total":[0-9]*' | head -1 | cut -d: -f2)
  ALL_ROWS=$(body_of "$(call qa_admin01 POST "/api/purchase/import/$MISS_ID/preview" "$TOK_ADMIN" \
      "{\"kind\":\"items\",\"mapping\":$MMAP,\"header_row\":1}")" | grep -o '"row_total":[0-9]*' | head -1 | cut -d: -f2)
  echo "  q='S19-MISS ITEM 1' → row_total=$Q_ROWS (거르지 않으면 $ALL_ROWS)"
  expect "찾기 200" 200 "$(code_of "$R")"
  expect "q 가 응답에 되돌아온다" 1 "$(body_of "$R" | grep -c '"row_q":"S19-MISS ITEM 1"')"
  expect "찾기가 실제로 좁힌다" yes "$([ "${Q_ROWS:-0}" -lt "${ALL_ROWS:-0}" ] && echo yes || echo no)"
  expect "찾은 행이 있다" yes "$([ "${Q_ROWS:-0}" -ge 1 ] && echo yes || echo no)"
  ITEMS_NOW=$(psqlq "SELECT count(*) FROM p_items;")
  for BADQ in "' OR '1'='1" "%" "'; DROP TABLE p_items; --" "_" "\\"; do
    R=$(call qa_admin01 POST "/api/purchase/import/$MISS_ID/preview" "$TOK_ADMIN" \
        "{\"kind\":\"items\",\"mapping\":$MMAP,\"header_row\":1,\"q\":$(json_str "$BADQ")}")
    printf "  q=[%s] → HTTP %s\n" "$BADQ" "$(code_of "$R")"
    expect "SQLi 모양의 찾기도 200" 200 "$(code_of "$R")"
  done
  expect "찾기 뒤에도 품목 표 그대로" "$ITEMS_NOW" "$(psqlq "SELECT count(*) FROM p_items;")"
else
  echo "  (⑯ 이 먼저 돌지 않아 건너뜀)"
fi

sub "⑲ 이카운트형 창고명 3조각 — 브랜드_지점_장소상세 (7차 보정 2026-09-13)"
# 실파일 #285 의 조각 패턴을 그대로 줄여 만든다: 98개 중 86개가 **세 조각 이상**이다.
# 5차 판(마지막 조각 = 지점)은 이런 이름을 전부 「판별 불가」로 만들었다.
export WH3_XLSX="$WORK/warehouse3.xlsx"
php -d error_reporting=E_ALL -r '
$header = ["창고코드","창고명","구분","사용"];
$rows = [["회사명 : 주식회사 플란랩스"], $header];
$rows[] = ["10001","S19-플란치과_S19-강남점_15층 공용부","창고","Yes"];
$rows[] = ["10002","S19-플란치과_S19-부평점_B1F_의약품창고","창고","Yes"];
$rows[] = ["10003","S19-플란치과_S19-검단점_6F_공급실","창고","Yes"];
$rows[] = ["10004","S19-플란치과_S19-부산점_13층 중앙공급실(구매팀)","창고","Yes"];
$rows[] = ["10005","S19-플란치과_S19-강남점_3F 수술방","창고","Yes"];
$rows[] = ["10006","S19-플란치과_S19-동탄점","창고","Yes"];
$rows[] = ["10007","S19-중앙창고","창고","Yes"];
$esc = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_XML1, "UTF-8");
$sheet = "<?xml version=\"1.0\" encoding=\"UTF-8\"?><worksheet xmlns=\"http://schemas.openxmlformats.org/spreadsheetml/2006/main\"><sheetData>";
foreach ($rows as $r => $row) { $sheet .= "<row r=\"".($r+1)."\">";
  foreach ($row as $c => $cell) { $sheet .= "<c r=\"".chr(65+$c).($r+1)."\" t=\"inlineStr\"><is><t>".$esc((string)$cell)."</t></is></c>"; }
  $sheet .= "</row>"; }
$sheet .= "</sheetData></worksheet>";
$ct = "<?xml version=\"1.0\" encoding=\"UTF-8\"?><Types xmlns=\"http://schemas.openxmlformats.org/package/2006/content-types\">"
    . "<Default Extension=\"rels\" ContentType=\"application/vnd.openxmlformats-package.relationships+xml\"/>"
    . "<Default Extension=\"xml\" ContentType=\"application/xml\"/>"
    . "<Override PartName=\"/xl/workbook.xml\" ContentType=\"application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml\"/>"
    . "<Override PartName=\"/xl/worksheets/sheet1.xml\" ContentType=\"application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml\"/></Types>";
$rels = "<?xml version=\"1.0\" encoding=\"UTF-8\"?><Relationships xmlns=\"http://schemas.openxmlformats.org/package/2006/relationships\">"
      . "<Relationship Id=\"rId1\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument\" Target=\"xl/workbook.xml\"/></Relationships>";
$wb = "<?xml version=\"1.0\" encoding=\"UTF-8\"?><workbook xmlns=\"http://schemas.openxmlformats.org/spreadsheetml/2006/main\" xmlns:r=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships\"><sheets><sheet name=\"Sheet1\" sheetId=\"1\" r:id=\"rId1\"/></sheets></workbook>";
$wbRels = "<?xml version=\"1.0\" encoding=\"UTF-8\"?><Relationships xmlns=\"http://schemas.openxmlformats.org/package/2006/relationships\"><Relationship Id=\"rId1\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet\" Target=\"worksheets/sheet1.xml\"/></Relationships>";
$path = getenv("WH3_XLSX"); @unlink($path);
$zip = new ZipArchive(); $zip->open($path, ZipArchive::CREATE);
$zip->addFromString("[Content_Types].xml", $ct); $zip->addFromString("_rels/.rels", $rels);
$zip->addFromString("xl/workbook.xml", $wb); $zip->addFromString("xl/_rels/workbook.xml.rels", $wbRels);
$zip->addFromString("xl/worksheets/sheet1.xml", $sheet); $zip->close();
echo "  만든 파일: ", filesize($path), " 바이트 (창고 7행: 3조각 5 · 2조각 1 · 1조각 1)\n";
'

R=$(curl -s -w '\n%{http_code}' -b "$JAR_DIR/qa_admin01.jar" -A "$UA" \
    -H "X-CSRF-Token: $TOK_ADMIN" -F "file=@$WORK/warehouse3.xlsx" "$BASE/api/purchase/import")
expect "3조각 창고 파일 업로드 201" 201 "$(code_of "$R")"
WH3_ID=$(body_of "$R" | sed -n 's/.*"import_id":"\([0-9a-f-]*\)".*/\1/p')
W3MAP='{"창고코드":"code","창고명":"name","구분":"kind","사용":"active"}'

sub "⑲-a 브랜드·지점을 함께 만들면 3조각 이름이 각자의 지점으로 간다"
R=$(call qa_admin01 POST "/api/purchase/import/$WH3_ID/preview" "$TOK_ADMIN" \
    "{\"kind\":\"locations\",\"mapping\":$W3MAP,\"header_row\":1,\"create_brands\":true,\"create_branches\":true}")
echo "  counts: $(body_of "$R" | sed -n 's/.*"counts":{\([^}]*\)}.*/\1/p')"
echo "  본문(앞 560자): $(body_of "$R" | head -c 560)"
expect "미리보기 200" 200 "$(code_of "$R")"
# 3조각 5 + 2조각 1 = 6행이 지점을 얻는다. 1조각(중앙창고) 1행은 판별 불가 → 확인 필요.
expect "insert 6" 6 "$(body_of "$R" | sed -n 's/.*"insert":\([0-9]*\).*/\1/p')"
expect "review 1 (중앙창고 — 판별 불가)" 1 "$(body_of "$R" | sed -n 's/.*"review":\([0-9]*\).*/\1/p')"
expect "사유 branch_unresolved" 1 "$(body_of "$R" | grep -c 'branch_unresolved')"
expect "새로 만들 브랜드 1개 (플란치과)" 1 "$(body_of "$R" | grep -c '"brands":\["S19-플란치과"\]')"
expect "새로 만들 지점에 강남점" 1 "$(body_of "$R" | grep -c '"name":"S19-강남점"')"
expect "새로 만들 지점에 검단점" 1 "$(body_of "$R" | grep -c '"name":"S19-검단점"')"
expect "장소 상세(detail)가 응답에 있다" 1 "$(body_of "$R" | grep -c '"detail":"B1F_의약품창고"')"

sub "⑲-b 자리 이름으로 용도를 짐작한다 (구분 열이 전부 「창고」라도)"
expect "수술방 → or" 1 "$(body_of "$R" | grep -c '3F 수술방')"
R2=$(call qa_admin01 POST "/api/purchase/import/$WH3_ID/commit" "$TOK_ADMIN" \
    "{\"kind\":\"locations\",\"mapping\":$W3MAP,\"header_row\":1,\"create_brands\":true,\"create_branches\":true}")
echo "  확정 HTTP $(code_of "$R2")  $(body_of "$R2")"
expect "확정 200" 200 "$(code_of "$R2")"
expect "브랜드 1개가 함께 생겼다" 1 "$(body_of "$R2" | sed -n 's/.*"brands_created":\([0-9]*\).*/\1/p')"
# S19-부평점은 씨앗(1장)에서 이미 만들어져 있다 → 새로 생기는 지점은 강남·검단·부산·동탄 4개다.
expect "지점 4개가 함께 생겼다 (부평점은 이미 있다)" 4 "$(body_of "$R2" | sed -n 's/.*"branches_created":\([0-9]*\).*/\1/p')"
expect "수술방 자리는 kind=or" 1 "$(psqlq "SELECT count(*) FROM p_locations WHERE name='S19-플란치과_S19-강남점_3F 수술방' AND kind='or';")"
expect "중앙공급실(구매팀)은 kind=central" 1 "$(psqlq "SELECT count(*) FROM p_locations WHERE name LIKE '%중앙공급실(구매팀)' AND kind='central';")"
expect "의약품창고는 kind=warehouse" 1 "$(psqlq "SELECT count(*) FROM p_locations WHERE name='S19-플란치과_S19-부평점_B1F_의약품창고' AND kind='warehouse';")"
expect "창고 이름은 **원문 그대로** 저장된다" 1 "$(psqlq "SELECT count(*) FROM p_locations WHERE name='S19-플란치과_S19-검단점_6F_공급실';")"
expect "legacy_name 에도 같은 원문" 1 "$(psqlq "SELECT count(*) FROM p_locations WHERE name='S19-플란치과_S19-검단점_6F_공급실' AND legacy_name='S19-플란치과_S19-검단점_6F_공급실';")"
expect "강남점 창고 2개가 같은 지점으로" 2 "$(psqlq "SELECT count(*) FROM p_locations l JOIN p_branches b ON b.id=l.branch_id WHERE b.name='S19-강남점';")"

sub "⑩ 업로드 원본이 「구매/가져오기」 폴더(admin-only)에 있나"
psqlq "SELECT '  '||f.original_name||' | '||f.access_level||' | 폴더='||fo.name||' | sha256='||left(f.sha256,16)||'…'
         FROM files f JOIN folders fo ON fo.id = f.folder_id
        WHERE fo.name = '가져오기' ORDER BY f.id DESC LIMIT 3;"

hr "회귀 — T-1(파일 삭제 권한) · T-26(계정 관리) · T-36(재인증은 권한을 만들지 않는다)"

sub "T-1 — Guest 의 파일 삭제는 라우터에서 끝난다 / Admin 은 목록을 본다"
R=$(call qa_guest01 GET "/api/files?page=1" "$TOK_GUEST")
expect "Guest 파일 목록 200" 200 "$(code_of "$R")"
expect "Guest 목록에 admin-only 0건" 0 "$(body_of "$R" | grep -c 'admin-only')"
ANY=$(psqlq "SELECT public_id FROM files WHERE deleted_at IS NULL ORDER BY id DESC LIMIT 1;")
R=$(call qa_guest01 DELETE "/api/files/$ANY" "$TOK_GUEST")
echo "  Guest DELETE → HTTP $(code_of "$R")  $(body_of "$R")"
expect "Guest 파일 삭제 403" 403 "$(code_of "$R")"
R=$(call qa_admin01 GET "/api/files?page=1" "$TOK_ADMIN")
expect "Admin 파일 목록 200" 200 "$(code_of "$R")"

sub "T-26 — 계정 관리: 목록에 지점·모듈 역할이 함께 나오고, 비Admin 은 403"
R=$(call qa_admin01 GET "/api/users" "$TOK_ADMIN")
echo "  HTTP $(code_of "$R")  본문(앞 400자): $(body_of "$R" | head -c 400)"
expect "Admin 계정 목록 200" 200 "$(code_of "$R")"
expect "purchase_role 칸이 있다" 1 "$(body_of "$R" | grep -c '"purchase_role"')"
expect "branch_id 칸이 있다" 1 "$(body_of "$R" | grep -c '"branch_id"')"
R=$(call qa_employee01 GET "/api/users" "$TOK_EMP1")
echo "  Employee → HTTP $(code_of "$R")  $(body_of "$R")"
expect "Employee 계정 목록 403" 403 "$(code_of "$R")"

sub "T-26b — Admin 이 지점·모듈 역할을 바꾸면 before/after 가 감사에 남는다"
UID2=$(psqlq "SELECT id FROM users WHERE username='qa_employee02';")
R=$(call qa_admin01 PATCH "/api/users/$UID2" "$TOK_ADMIN" \
    "{\"branch_id\":$BR_A,\"purchase_role\":\"requester\",\"reason_text\":\"19단계 T-42 검토\"}")
echo "  HTTP $(code_of "$R")  $(body_of "$R")"
expect "지점·모듈 역할 변경 200" 200 "$(code_of "$R")"
psqlq "SELECT detail::text FROM audit_logs WHERE action IN ('user.role_change')
        AND target_name='qa_employee02' ORDER BY id DESC LIMIT 1;"

sub "T-36 — 재인증은 권한을 만들지 않는다 (Employee 는 재인증해도 /api/users 403)"
echo "  재인증: $(reauth qa_employee01 "$TOK_EMP1")"
R=$(call qa_employee01 GET "/api/users" "$TOK_EMP1")
echo "  HTTP $(code_of "$R")  $(body_of "$R")"
expect "재인증 뒤에도 Employee 403" 403 "$(code_of "$R")"
expect "권한 거부라 next 없음" 0 "$(body_of "$R" | grep -c '"next"')"

sub "T-36b — 본인 계정 창구는 재인증 대상이 아니다 (/api/me 200)"
R=$(call qa_employee01 GET "/api/me" "$TOK_EMP1")
expect "/api/me 200" 200 "$(code_of "$R")"
echo "  can_purchase=$(body_of "$R" | sed -n 's/.*"can_purchase":\(true\|false\).*/\1/p')"

hr "결과"
printf '  통과 %d · 실패 %d\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ] || exit 1
