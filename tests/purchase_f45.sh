#!/usr/bin/env bash
# tests/purchase_f45.sh — F-45 수락 기준 실행 (PRD 12.2 T-57)
#   입고 화면의 「입력 · 전표 · 현황」 3탭과 그 창구 두 개
#   (GET /api/purchase/gr · GET /api/purchase/gr/{num}) 를 **실제로 불러** 확인한다.
#
# 규칙 (CLAUDE.md 3장)
#   · **검토 계정(qa_ 접두)만** 쓴다. 시드 계정(admin01 …)은 사용자의 자기 공격 전용이다.
#   · 모든 요청에 `-A "tbox-review/<날짜>"` 를 붙인다 — 감사 로그에서 사람의 행동과 갈라 보려는 것.
#   · 비밀번호·TOTP 비밀은 **필요한 필드만 awk 로** 뽑고 값은 변수에만 담는다 (SECURITY R-62).
#
# ★ 시험 안전 (SECURITY R-73 — 2026-09-13 사고 뒤 의무). 19~26단계 스크립트와 **같은 규칙**이다.
#   ① 최근 10분 감사 로그에 비-qa actor 가 하나라도 있으면 **시작하지 않는다**(종료 코드 3).
#   ② 정리는 `created_by` 가 검토 계정인 행만. 표 전체 DELETE·TRUNCATE 는 이 파일에 한 줄도 없다.
#   ③ 시작·끝에 「비-qa 행 수」를 세어 같은지 보고한다.
#   ④ 픽스처 이름은 전부 `S45-` 접두다 — 사용자의 실데이터와 이름이 겹쳐 409 가 나지 않게.
#   ⑤ qa 계정에 잠깐 심는 값(purchase_role·branch_id)은 끝에서 되돌린다.
#
# 순서에 이유가 있다: (f) 의 `purchase_stage21.sh` 는 **자기 정리에서 qa 가 만든 전표를 전부
# 지운다.** 그래서 회귀를 **먼저** 돌리고, 그 뒤에 이 시험의 픽스처를 만든다.
#
# 사용: sudo -v && bash tests/purchase_f45.sh [출력 폴더]
#   출력 폴더 기본값은 ~/.claude/ui-shots/f45 (루트 디스크를 쓰지 않는다).
set -u

BASE="http://127.0.0.1:8082"
UA="tbox-review/$(date +%F)"
DB="tbox_re"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="${1:-$HOME/.claude/ui-shots/f45}"
JAR_DIR="$(mktemp -d)"
PASS=0
FAIL=0
SKIP=0
TODAY="$(date +%Y-%m-%d)"

mkdir -p "$OUT"

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
# ① 사용자 활동 확인 — R-73 ①
# ─────────────────────────────────────────────────────────────
BUSY=$(psqlq "SELECT count(*) FROM audit_logs
               WHERE happened_at > now() - interval '10 minutes'
                 AND actor_name NOT LIKE 'qa\\_%';")
if [ "${BUSY:-1}" != "0" ]; then
  echo "■ 최근 10분 감사 로그에 비-qa actor 가 ${BUSY}건 있습니다 — 사용자가 작업 중입니다."
  echo "■ 데이터를 남기는 시험을 시작하지 않고 끝냅니다 (SECURITY R-73 ①). 종료 코드 3."
  rm -rf "$JAR_DIR"
  exit 3
fi
echo "■ 최근 10분 비-qa 활동 0건 — 시험을 시작합니다."

# ─── 로그인 ──────────────────────────────────────────────────
#
# 검토 계정은 **여러 세션이 함께 쓴다**(2026-09-15 실측: 다른 세션의 `tests/fetch_hosts.sh`
# 와 같은 30초 창을 물었다). 그때 일어나는 일이 이렇다:
#   ① 같은 창의 코드를 두 번 쓰면 R-45 가 `code_reused` 로 막는다 — 이것은 **제대로 된 동작**이다.
#   ② 그 거부도 실패 시도로 세어져 5회면 계정이 10분 잠긴다 (R-05).
# 그래서 시도하기 **전에** 잠금이 풀렸는지 보고, 잠겨 있으면 기다린다 — 잠긴 계정에
# 코드를 더 던져 봐야 잠금만 늘린다.
waitUnlocked() {
  local who="$1" locked waited=0
  while :; do
    # psql 의 `boolean::text` 는 `true`/`false` 다 (`t`/`f` 가 아니다 — 2026-09-15 실측).
    locked=$(psqlq "SELECT (locked_until IS NOT NULL AND locked_until > now())::text
                      FROM users WHERE username = '$who';")
    [ "$locked" = "true" ] || return 0
    if [ "$waited" -ge 900 ]; then
      printf '  ! %s 가 15분 넘게 잠겨 있습니다 — 기다리기를 그만둡니다\n' "$who" >&2
      return 1
    fi
    printf '  · %s 잠금이 풀리기를 기다림 (20초)\n' "$who" >&2
    sleep 20
    waited=$((waited + 20))
  done
}

login() {
  local who="$1" jar="$JAR_DIR/$1.jar" pw sec raw code left out try
  pw=$(sudo -n awk -v u="$who" '$1==u{print $3}' /etc/tbox-v2/seed-accounts.txt)
  sec=$(sudo -n awk -v u="$who" '$1==u{print $2}' /etc/tbox-v2/qa-totp.txt)
  waitUnlocked "$who" || return 1
  for try in 1 2 3 4 5; do
    raw=$(php "$ROOT/scripts/totp_code.php" "$sec")
    code=${raw%%$'\n'*}
    out=$(curl -s -c "$jar" -A "$UA" -H 'Content-Type: application/json' \
          -d "{\"username\":\"$who\",\"password\":\"$pw\",\"otp\":\"$code\"}" "$BASE/api/login")
    case "$out" in
      *'"ok":true'*)
        curl -s -b "$jar" -A "$UA" "$BASE/api/me" \
          | sed -n 's/.*"csrf_token":"\([0-9a-f]*\)".*/\1/p'
        return 0 ;;
    esac
    case "$out" in
      *'시도가 너무 많습니다'*)
        printf '  · %s 잠김 — 풀릴 때까지 기다린 뒤 다시\n' "$who" >&2
        waitUnlocked "$who" || return 1
        continue ;;
    esac
    left=$(printf '%s' "$raw" | sed -n 's/^남은 시간: \([0-9]*\)초.*/\1/p')
    [ -n "$left" ] || left=31
    printf '  · %s 로그인 재시도 — 다음 코드 창까지 %s초 기다림\n' "$who" "$left" >&2
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

# 주석을 걷어 낸 **실제 코드**에서만 낱말을 센다 (tests/purchase_stage25.sh 와 같은 규칙).
count_in_code() {
  local pattern="$1"; shift
  python3 - "$pattern" "$@" <<'PYEOF'
import re, sys
pattern = sys.argv[1]
total = 0
for path in sys.argv[2:]:
    text = open(path, encoding='utf-8').read()
    text = re.sub(r'/\*.*?\*/', '', text, flags=re.S)
    text = re.sub(r'<!--.*?-->', '', text, flags=re.S)
    text = re.sub(r'^\s*//.*$', '', text, flags=re.M)
    total += len(re.findall(pattern, text))
print(total)
PYEOF
}

code_of() { printf '%s' "$1" | tail -1; }
body_of() { printf '%s' "$1" | sed '$d'; }
new_id()  { printf '%s' "$1" | grep -o '"id":[0-9]*' | head -1 | cut -d: -f2; }
field()   { printf '%s' "$2" | sed -n 's/.*"'"$1"'":"\([^"]*\)".*/\1/p' | head -1; }
# 목록 응답에서 전표 번호만 뽑는다 (`"doc_no":"GR-…"` 전부)
docnos()  { printf '%s' "$1" | grep -o '"doc_no":"[^"]*"' | cut -d'"' -f4 | tr '\n' ' '; }
# 목록 응답의 items 길이 — `"id":` 가 아니라 `"doc_no":` 를 센다(줄 id 와 섞이지 않게)
count_docs() { printf '%s' "$1" | grep -o '"doc_no":"' | wc -l; }

# ─────────────────────────────────────────────────────────────
# 인구조사 — 시작·끝에 비-qa 행 수를 찍는다 (R-73 ③)
# ─────────────────────────────────────────────────────────────
QA_IDS=""
CENSUS_BEFORE=""
CENSUS_SUM=0

census() {
  local when="$1" total nonqa sum=0
  printf '  %-18s %8s %10s\n' "표" "전체" "비-qa"
  for t in p_brands p_branches p_locations p_vendors p_categories p_items p_par_levels p_docs; do
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
  # ★ `p_doc_seq` 는 **지우지 않는다** (2026-09-15 사고). 그 표는 qa 의 것이 아니라
  #   그날의 전표 번호 카운터이고, 19~23단계 스크립트가 쓰던
  #   `DELETE FROM p_doc_seq WHERE seq_date = CURRENT_DATE` 는 카운터를 0 으로 되돌린다.
  #   사용자가 **같은 날** 만든 전표(PR-…-7·PO-…-1·GR-…-1·2)가 남아 있으면 다음 채번이
  #   그 번호를 다시 발급하고 `p_docs_doc_no_uidx` 에 부딪혀 409 가 난다. 실패하면
  #   트랜잭션이 되돌아가 카운터도 오르지 않으므로 **그날 내내 전표를 만들 수 없다**.
  #   번호는 하루에 몇 개 더 커질 뿐이니, 카운터는 그대로 두는 편이 맞다.

  psqlc "UPDATE users SET branch_id = NULL, purchase_role = NULL
          WHERE username IN ('qa_employee01','qa_employee02','qa_manager01');" \
    && echo "  qa 계정 branch_id·purchase_role 원복"

  psqlc "DELETE FROM p_stock_moves
          WHERE reverses_move_id IS NOT NULL AND created_by IN ($qa_ids);
         DELETE FROM p_stock_moves WHERE created_by IN ($qa_ids);
         DELETE FROM p_doc_lines WHERE doc_id IN (SELECT id FROM p_docs WHERE created_by IN ($qa_ids));
         DELETE FROM p_docs      WHERE created_by IN ($qa_ids);
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
  printf '\n결과: 통과 %d · 실패 %d · 건너뜀 %d\n' "$PASS" "$FAIL" "$SKIP"
  rm -rf "$JAR_DIR"
  [ "$FAIL" = "0" ] || exit 1
  [ "$SKIP" = "0" ] || exit 3
}

trap cleanup EXIT

hr "0-0. 시작 전 인구조사 (R-73 ③)"
QA_IDS=$(psqlq "SELECT coalesce(string_agg(id::text, ','), '0') FROM users WHERE username LIKE 'qa\\_%';")
echo "  검토 계정 id: $QA_IDS"
census before
CENSUS_BEFORE="$CENSUS_SUM"
echo
echo "  ★ 이 시험의 정리는 위 「비-qa」 행을 **한 줄도 지우지 않습니다**."

# ═════════════════════════════════════════════════════════════
hr "(f-1) 회귀 — tests/purchase_stage21.sh (입고·원장)"
# ═════════════════════════════════════════════════════════════
echo "  ★ 먼저 돌린다: 21단계 스크립트는 정리에서 **qa 가 만든 전표를 전부** 지우므로,"
echo "    이 시험의 픽스처보다 앞에 와야 한다."
# 21단계 스크립트의 로그인 도우미에는 잠금 검사가 없다(그 파일은 이 작업의 수정 범위 밖이다).
# 잠긴 계정에 코드를 던지면 실패만 쌓이므로 **여기서** 먼저 풀리기를 기다린다.
for who in qa_admin01 qa_employee01 qa_employee02 qa_manager01; do
  waitUnlocked "$who" || echo "  ! $who 잠금이 풀리지 않았습니다 — 회귀 결과가 흔들릴 수 있습니다"
done
R21="$OUT/regress-stage21.txt"
bash "$ROOT/tests/purchase_stage21.sh" > "$R21" 2>&1
RC21=$?
LAST21=$(grep -E '결과: 통과 [0-9]+ · 실패 [0-9]+' "$R21" | tail -1)
if [ "$RC21" = "3" ]; then
  echo "  ▲ R-73 관문으로 종료 3 — 21단계 회귀를 돌리지 않았습니다."
  SKIP=$((SKIP+1))
else
  echo "  $LAST21"
  F21=$(printf '%s' "$LAST21" | sed -n 's/.*실패 \([0-9]*\).*/\1/p')
  expect "21단계 회귀 실패 0" 0 "${F21:-없음}"
fi

hr "0. 준비 — 검토 계정 로그인"
TOK_ADMIN=$(login qa_admin01)   || exit 1
TOK_EMP1=$(login qa_employee01) || exit 1
TOK_EMP2=$(login qa_employee02) || exit 1
TOK_MGR=$(login qa_manager01)   || exit 1
echo "  로그인 4계정 완료"
echo "  재인증(기초정보 편집 창 열기): qa_admin01 → $(reauth qa_admin01 "$TOK_ADMIN")"

hr "1. 픽스처 — 브랜드·지점 2곳·장소·거래처 2곳·품목 2개 (전부 S45- 접두)"

R=$(call qa_admin01 POST /api/purchase/brands "$TOK_ADMIN" \
  '{"name":"S45-치과","biz_no":"345-45-67890","ceo_name":"S45 대표","address":"인천 부평구","tel":"032-455-4555"}')
BRAND=$(new_id "$(body_of "$R")"); expect "브랜드 생성" 201 "$(code_of "$R")"

R=$(call qa_admin01 POST /api/purchase/branches "$TOK_ADMIN" "{\"brand_id\":$BRAND,\"name\":\"S45-부평점\"}")
BR_A=$(new_id "$(body_of "$R")"); expect "지점A 생성" 201 "$(code_of "$R")"
R=$(call qa_admin01 POST /api/purchase/branches "$TOK_ADMIN" "{\"brand_id\":$BRAND,\"name\":\"S45-일산점\"}")
BR_B=$(new_id "$(body_of "$R")"); expect "지점B 생성" 201 "$(code_of "$R")"

R=$(call qa_admin01 POST /api/purchase/locations "$TOK_ADMIN" \
  "{\"branch_id\":$BR_A,\"kind\":\"warehouse\",\"name\":\"S45-A 창고\"}")
LOC_A=$(new_id "$(body_of "$R")"); expect "지점A 창고 생성" 201 "$(code_of "$R")"
R=$(call qa_admin01 POST /api/purchase/locations "$TOK_ADMIN" \
  "{\"branch_id\":$BR_B,\"kind\":\"warehouse\",\"name\":\"S45-B 창고\"}")
LOC_B=$(new_id "$(body_of "$R")"); expect "지점B 창고 생성" 201 "$(code_of "$R")"

R=$(call qa_admin01 POST /api/purchase/vendors "$TOK_ADMIN" '{"name":"S45-더존덴탈","code":"S45V1"}')
VEND=$(new_id "$(body_of "$R")"); expect "거래처1 생성" 201 "$(code_of "$R")"
R=$(call qa_admin01 POST /api/purchase/vendors "$TOK_ADMIN" '{"name":"S45-빈거래처","code":"S45V2"}')
VEND2=$(new_id "$(body_of "$R")"); expect "거래처2(전표 없음) 생성" 201 "$(code_of "$R")"

R=$(call qa_admin01 POST /api/purchase/items "$TOK_ADMIN" \
  "{\"vendor_id\":$VEND,\"code\":\"S45-A\",\"name\":\"S45 gauze 2x2\",\"unit\":\"EA\",\"price_in\":\"1000\",\"lead_days\":3}")
ITEM_A=$(new_id "$(body_of "$R")"); expect "품목A 생성" 201 "$(code_of "$R")"
R=$(call qa_admin01 POST /api/purchase/items "$TOK_ADMIN" \
  "{\"vendor_id\":$VEND,\"code\":\"S45-B\",\"name\":\"S45 cotton roll\",\"unit\":\"EA\",\"price_in\":\"2000\",\"lead_days\":3}")
ITEM_B=$(new_id "$(body_of "$R")"); expect "품목B 생성" 201 "$(code_of "$R")"

sub "검토용 모듈 역할·지점 심기 (psql, 끝에서 원복)"
psqlc "UPDATE users SET branch_id = $BR_A, purchase_role = 'stock'     WHERE username = 'qa_employee01';"
psqlc "UPDATE users SET branch_id = $BR_B, purchase_role = 'stock'     WHERE username = 'qa_employee02';"
psqlc "UPDATE users SET branch_id = NULL,  purchase_role = 'buyer'     WHERE username = 'qa_manager01';"
psqlq "SELECT username||' branch='||coalesce(branch_id::text,'-')||' role='||coalesce(purchase_role,'-')
         FROM users WHERE username LIKE 'qa%' ORDER BY id;"

hr "2. 발주요청 → 발주서 → 보냄 → 입고 (지점 A 2건 · 지점 B 1건)"

mk_gr() {   # $1 지점 id  $2 장소 id  $3 수량  $4 로트  → GR id·번호를 전역에 담는다
  local br="$1" loc="$2" q="$3" lot="$4" r pr po prline poline
  r=$(call qa_manager01 POST /api/purchase/pr "$TOK_MGR" \
    "{\"branch_id\":$br,\"to_location_id\":$loc,\"vendor_id\":$VEND,\"lines\":[{\"item_id\":$ITEM_A,\"qty\":\"$q\"},{\"item_id\":$ITEM_B,\"qty\":\"$q\"}]}")
  pr=$(new_id "$(body_of "$r")")
  prline=$(psqlq "SELECT id FROM p_doc_lines WHERE doc_id = $pr AND item_id = $ITEM_A;")
  r=$(call qa_manager01 POST /api/purchase/po "$TOK_MGR" \
    "{\"to_location_id\":$loc,\"lines\":[{\"src_line_id\":$prline,\"qty\":\"$q\"}]}")
  po=$(new_id "$(body_of "$r")")
  PO_NO=$(field doc_no "$(body_of "$r")")
  call qa_manager01 POST "/api/purchase/po/$po/send" "$TOK_MGR" '{}' > /dev/null
  poline=$(psqlq "SELECT id FROM p_doc_lines WHERE doc_id = $po AND item_id = $ITEM_A;")
  PO_LINE="$poline"
  MK_R=$(call "$5" POST /api/purchase/gr "$6" \
    "{\"lines\":[{\"src_line_id\":$poline,\"qty\":\"$q\",\"lot_no\":\"$lot\",\"expiry_date\":\"2028-06-30\",\"remark\":\"S45 비고\"}]}")
  GR_ID=$(new_id "$(body_of "$MK_R")")
  GR_NO=$(field doc_no "$(body_of "$MK_R")")
}

mk_gr "$BR_A" "$LOC_A" 3 "S45-L1" qa_employee01 "$TOK_EMP1"
expect "지점A 입고① 확정" 201 "$(code_of "$MK_R")"
if [ -z "$GR_ID" ]; then
  echo "  ■ 입고 전표가 서지 않았습니다 — 아래 (a)~(d) 는 돌릴 수 없습니다."
  echo "  ■ 전표 번호 충돌(409)이면 그날의 채번 카운터 p_doc_seq 가 지워진 상태입니다:"
  psqlq "SELECT '     p_doc_seq: '||coalesce(string_agg(doc_type||'='||last_no, ' '),'(오늘 행 없음)')
           FROM p_doc_seq WHERE seq_date = CURRENT_DATE;"
  psqlq "SELECT '     오늘 이미 있는 전표 번호: '||string_agg(doc_no, ' ' ORDER BY doc_no)
           FROM p_docs WHERE doc_date = CURRENT_DATE;"
  echo "  ■ 복구 판단은 사용자 몫입니다 (CLAUDE.md 4장) — 시험을 여기서 멈춥니다."
  exit 1
fi
GR_A1="$GR_ID"; GR_A1_NO="$GR_NO"; PO_A1_NO="$PO_NO"; PO_A1_LINE="$PO_LINE"
echo "  입고① $GR_A1_NO (출처 $PO_A1_NO)"

mk_gr "$BR_A" "$LOC_A" 4 "S45-L2" qa_employee01 "$TOK_EMP1"
expect "지점A 입고② 확정" 201 "$(code_of "$MK_R")"
GR_A2="$GR_ID"; GR_A2_NO="$GR_NO"
echo "  입고② $GR_A2_NO"

mk_gr "$BR_B" "$LOC_B" 2 "S45-L3" qa_employee02 "$TOK_EMP2"
expect "지점B 입고③ 확정" 201 "$(code_of "$MK_R")"
GR_B1="$GR_ID"; GR_B1_NO="$GR_NO"
echo "  입고③ $GR_B1_NO (지점B)"

# ═════════════════════════════════════════════════════════════
hr "(a) GET /api/purchase/gr — 목록 · 필드 · 페이지 · 걸개"
# ═════════════════════════════════════════════════════════════
R=$(call qa_employee01 GET /api/purchase/gr "$TOK_EMP1")
B=$(body_of "$R")
echo "  HTTP $(code_of "$R")"
echo "  $(printf '%s' "$B" | head -c 500)"
expect "목록 200" 200 "$(code_of "$R")"
expect "지점A stock 이 보는 입고 전표 2건" 2 "$(count_docs "$B")"
expect "페이지 크기 20" '"per_page":20' "$(printf '%s' "$B" | grep -o '"per_page":20')"
expect "page 1" '"page":1' "$(printf '%s' "$B" | grep -o '"page":1')"

sub "한 건이 갖춰야 할 칸 — 지시서 F-45 의 목록 필드"
for f in id doc_no doc_date vendor_name location_name manager_name line_count qty_total status voided_at; do
  expect "필드 $f" 1 "$(printf '%s' "$B" | grep -c "\"$f\":")"
done

sub "걸개 — from/to · vendor · q"
R=$(call qa_employee01 GET "/api/purchase/gr?from=$TODAY&to=$TODAY" "$TOK_EMP1")
expect "오늘 기간 → 2건" 2 "$(count_docs "$(body_of "$R")")"
R=$(call qa_employee01 GET "/api/purchase/gr?from=2099-01-01" "$TOK_EMP1")
expect "먼 미래 from → 0건" 0 "$(count_docs "$(body_of "$R")")"
R=$(call qa_employee01 GET "/api/purchase/gr?vendor=$VEND" "$TOK_EMP1")
expect "거래처1 → 2건" 2 "$(count_docs "$(body_of "$R")")"
R=$(call qa_employee01 GET "/api/purchase/gr?vendor=$VEND2" "$TOK_EMP1")
expect "전표 없는 거래처2 → 0건" 0 "$(count_docs "$(body_of "$R")")"
R=$(call qa_employee01 GET "/api/purchase/gr?q=$GR_A1_NO" "$TOK_EMP1")
expect "전표 번호 검색 → 1건" 1 "$(count_docs "$(body_of "$R")")"
R=$(call qa_employee01 GET "/api/purchase/gr?q=S45%20gauze" "$TOK_EMP1")
expect "품목명 검색 → 2건" 2 "$(count_docs "$(body_of "$R")")"
R=$(call qa_employee01 GET "/api/purchase/gr?q=%25" "$TOK_EMP1")
expect "와일드카드(%) 는 글자로 다뤄져 0건" 0 "$(count_docs "$(body_of "$R")")"

# ═════════════════════════════════════════════════════════════
hr "(b) GET /api/purchase/gr/{num} — 상세 · 지점 격리 404"
# ═════════════════════════════════════════════════════════════
R=$(call qa_employee01 GET "/api/purchase/gr/$GR_A1" "$TOK_EMP1")
B=$(body_of "$R")
echo "  HTTP $(code_of "$R")"
echo "  $(printf '%s' "$B" | head -c 600)"
expect "상세 200" 200 "$(code_of "$R")"
expect "doc.doc_no 가 입고①" "$GR_A1_NO" "$(field doc_no "$B")"
expect "줄의 로트 S45-L1" 1 "$(printf '%s' "$B" | grep -c '"lot_no":"S45-L1"')"
expect "줄의 유효기간" 1 "$(printf '%s' "$B" | grep -c '"expiry_date":"2028-06-30"')"
expect "줄의 출처 발주서 번호" 1 "$(printf '%s' "$B" | grep -c "\"src_doc_no\":\"$PO_A1_NO\"")"
expect "줄의 출처 발주서 줄 번호(src_line_no)" 1 "$(printf '%s' "$B" | grep -c '"src_line_no":[0-9]')"
expect "totals.qty" 1 "$(printf '%s' "$B" | grep -c '"totals":{"qty":"3.000"}')"
expect "can_void true (stock 이고 취소 전이고 마감 아님)" 1 "$(printf '%s' "$B" | grep -c '"can_void":true')"

sub "남의 지점 전표는 **404** 다 — 403 으로 존재를 알리지 않는다"
R=$(call qa_employee01 GET "/api/purchase/gr/$GR_B1" "$TOK_EMP1")
echo "  지점A stock → 지점B 전표: HTTP $(code_of "$R")  $(body_of "$R")"
expect "지점B 전표 상세 404" 404 "$(code_of "$R")"
R=$(call qa_employee02 GET "/api/purchase/gr/$GR_A1" "$TOK_EMP2")
expect "거꾸로도 404 (지점B → 지점A)" 404 "$(code_of "$R")"
R=$(call qa_employee01 GET "/api/purchase/gr/999999999" "$TOK_EMP1")
expect "없는 id 404" 404 "$(code_of "$R")"

sub "목록도 자기 지점만"
R=$(call qa_employee01 GET /api/purchase/gr "$TOK_EMP1")
expect "지점A 목록에 지점B 전표 0" 0 "$(printf '%s' "$(body_of "$R")" | grep -c "$GR_B1_NO")"
R=$(call qa_employee02 GET /api/purchase/gr "$TOK_EMP2")
B2=$(body_of "$R")
expect "지점B 목록은 1건" 1 "$(count_docs "$B2")"
expect "지점B 목록에 지점A 전표 0" 0 "$(printf '%s' "$B2" | grep -c "$GR_A1_NO")"
# buyer 는 전 지점이라 **사용자(비-qa)가 만든 입고 전표까지** 함께 본다.
# 그래서 「전체 몇 건」 이 아니라 「이 시험이 만든 셋이 다 보이는가」 를 센다.
R=$(call qa_manager01 GET /api/purchase/gr "$TOK_MGR")
B3=$(body_of "$R")
SEEN=0
for n in "$GR_A1_NO" "$GR_A2_NO" "$GR_B1_NO"; do
  SEEN=$((SEEN + $(printf '%s' "$B3" | grep -c "$n")))
done
expect "buyer(전 지점)에게 이 시험의 입고 3건이 모두 보인다" 3 "$SEEN"

sub "?branch= 에 남의 지점을 적어도 조건은 바뀌지 않는다 (PRD S-01)"
R=$(call qa_employee01 GET "/api/purchase/gr?branch=$BR_B" "$TOK_EMP1")
expect "지점A stock 이 branch=B 를 적으면 0건" 0 "$(count_docs "$(body_of "$R")")"

# ═════════════════════════════════════════════════════════════
hr "(c) 취소 — 화면과 같은 호출 · 원장 반대 부호 행"
# ═════════════════════════════════════════════════════════════
MOVE_BEFORE=$(psqlq "SELECT count(*) FROM p_stock_moves m JOIN p_doc_lines l ON l.id = m.doc_line_id
                      WHERE l.doc_id = $GR_A2;")
DELTA_BEFORE=$(psqlq "SELECT coalesce(sum(qty_delta),0)::text FROM p_stock_moves m
                       JOIN p_doc_lines l ON l.id = m.doc_line_id WHERE l.doc_id = $GR_A2;")
echo "  취소 전: 원장 $MOVE_BEFORE 행 · 합계 $DELTA_BEFORE"

R=$(call qa_employee01 POST "/api/purchase/gr/$GR_A2/void" "$TOK_EMP1" '{"reason":"S45 검토 — 화면의 사유 모달과 같은 본문"}')
echo "  HTTP $(code_of "$R")  $(body_of "$R")"
expect "취소 200" 200 "$(code_of "$R")"

R=$(call qa_employee01 GET "/api/purchase/gr?status=void" "$TOK_EMP1")
B=$(body_of "$R")
expect "status=void 목록에 취소한 전표" 1 "$(printf '%s' "$B" | grep -c "$GR_A2_NO")"
expect "그 줄의 status 는 void" 1 "$(printf '%s' "$B" | grep -c '"status":"void"')"
expect "voided_at 이 채워졌다" 0 "$(printf '%s' "$B" | grep -c '"voided_at":null')"

R=$(call qa_employee01 GET "/api/purchase/gr?status=done" "$TOK_EMP1")
expect "status=done 목록에는 없다" 0 "$(printf '%s' "$(body_of "$R")" | grep -c "$GR_A2_NO")"
R=$(call qa_employee01 GET /api/purchase/gr "$TOK_EMP1")
expect "걸개 없는 목록에는 취소도 함께 나온다(2건)" 2 "$(count_docs "$(body_of "$R")")"

R=$(call qa_employee01 GET "/api/purchase/gr/$GR_A2" "$TOK_EMP1")
B=$(body_of "$R")
expect "상세 can_void=false" 1 "$(printf '%s' "$B" | grep -c '"can_void":false')"
expect "상세 doc.status=void" 1 "$(printf '%s' "$B" | grep -c '"status":"void"')"

sub "원장 — 삭제가 아니라 **반대 부호 행**이 늘었다 (21단계 규칙 회귀)"
MOVE_AFTER=$(psqlq "SELECT count(*) FROM p_stock_moves m JOIN p_doc_lines l ON l.id = m.doc_line_id
                     WHERE l.doc_id = $GR_A2;")
DELTA_AFTER=$(psqlq "SELECT coalesce(sum(qty_delta),0)::text FROM p_stock_moves m
                      JOIN p_doc_lines l ON l.id = m.doc_line_id WHERE l.doc_id = $GR_A2;")
REV=$(psqlq "SELECT count(*) FROM p_stock_moves m JOIN p_doc_lines l ON l.id = m.doc_line_id
              WHERE l.doc_id = $GR_A2 AND m.reverses_move_id IS NOT NULL;")
echo "  취소 뒤: 원장 $MOVE_AFTER 행 · 합계 $DELTA_AFTER · 되돌림 행 $REV"
expect "원장 행이 늘었다(지워지지 않았다)" "$((MOVE_BEFORE * 2))" "$MOVE_AFTER"
expect "reverses_move_id 가 있는 행 $MOVE_BEFORE 개" "$MOVE_BEFORE" "$REV"
expect "원장 합계 0 (입고가 되돌아왔다)" "0.000" "$DELTA_AFTER"

sub "두 번 취소는 막힌다"
R=$(call qa_employee01 POST "/api/purchase/gr/$GR_A2/void" "$TOK_EMP1" '{"reason":"S45 두 번째"}')
expect "이미 취소된 전표 재취소 400" 400 "$(code_of "$R")"
sub "남의 지점 전표 취소도 404"
R=$(call qa_employee01 POST "/api/purchase/gr/$GR_B1/void" "$TOK_EMP1" '{"reason":"S45 남의 지점"}')
expect "지점B 전표 취소 404" 404 "$(code_of "$R")"

# ═════════════════════════════════════════════════════════════
hr "(d) 현황 탭 — 미입고 렌더러가 **한 벌**인가"
# ═════════════════════════════════════════════════════════════
sub "정적 — 정의 1 · 호출 2"
expect "renderBackorder 정의 1개 (purchase_common.js)" 1 \
  "$(grep -c '^  function renderBackorder(' "$ROOT/public/assets/purchase_common.js")"
expect "정의가 다른 파일에는 없다" 0 \
  "$(grep -c 'function renderBackorder(' "$ROOT/public/assets/purchase_stock.js" \
       "$ROOT/public/assets/purchase_docs.js" "$ROOT/public/assets/purchase.js" \
     | awk -F: '{s+=$2} END {print s+0}')"
expect "호출 2곳 (재고 화면 · 입고 화면)" 2 \
  "$(grep -c 'UI.renderBackorder(' "$ROOT/public/assets/purchase_stock.js")"
expect "/api/purchase/backorder 를 부르는 자리는 렌더러 한 곳뿐" 1 \
  "$(grep -c "'/api/purchase/backorder'" "$ROOT/public/assets/purchase_common.js")"
expect "번들에 남은 backorder 호출 사본 0" 0 \
  "$(grep -c "/api/purchase/backorder" "$ROOT/public/assets/purchase_stock.js")"
expect "입고 화면에 미입고 표 자리(#gr-backorder)" 1 \
  "$(grep -c 'id="gr-backorder"' "$ROOT/src/views/purchase_receipt.php")"
expect "재고 화면의 미입고 표 자리(#bo-groups)는 그대로" 1 \
  "$(grep -c 'id="bo-groups"' "$ROOT/src/views/purchase_stock.php")"

sub "동작 — 두 화면이 같은 API 를 같은 파라미터로 부른다"
R=$(call qa_employee01 GET "/api/purchase/backorder" "$TOK_EMP1")
B=$(body_of "$R")
echo "  HTTP $(code_of "$R")  $(printf '%s' "$B" | head -c 300)"
expect "미입고 200" 200 "$(code_of "$R")"
expect "응답에 groups" 1 "$(printf '%s' "$B" | grep -c '"groups":')"
R2=$(call qa_employee01 GET "/api/purchase/backorder?vendor=$VEND&overdue=1" "$TOK_EMP1")
expect "걸개 붙여도 200" 200 "$(code_of "$R2")"

# ═════════════════════════════════════════════════════════════
hr "(e) 화면 — 입고 3탭 · 재고 미입고 탭 (1440px, 결함 0)"
# ═════════════════════════════════════════════════════════════
SHOT="$OUT/1440"
mkdir -p "$SHOT"
LOG="$OUT/ui-1440.txt"
if UI_PAGES="/purchase/receipt,/purchase/stock" \
     bash "$ROOT/scripts/ui_snapshot.sh" "$SHOT" 1440 > "$LOG" 2>&1; then
  cat "$LOG"
  TOTAL=$(sed -n 's/^합계 \([0-9]*\)건$/\1/p' "$LOG")
  expect "입고·재고 두 화면 결함 합계 0건" 0 "${TOTAL:-없음}"
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

sub "스크린샷 3장 (입력 · 전표 · 현황)"
ls -1 "$SHOT" | grep '^purchase_receipt' || true
expect "입고 화면 스크린샷 3장" 3 "$(ls -1 "$SHOT" | grep -c '^purchase_receipt')"
expect "재고 화면 스크린샷 존재" 1 \
  "$(ls -1 "$SHOT" | grep -cx 'purchase_stock.png')"

# ═════════════════════════════════════════════════════════════
hr "(f-2) 회귀 — purchase_stage25.sh (a) · help_button.sh · 린트 · php -l"
# ═════════════════════════════════════════════════════════════
sub "purchase_stage25.sh (a) — 구매 11화면 1440px · 1024px 결함 0"
for W in 1440 1024; do
  WLOG="$OUT/stage25a-$W.txt"
  mkdir -p "$OUT/all-$W"
  if bash "$ROOT/scripts/ui_snapshot.sh" "$OUT/all-$W" "$W" > "$WLOG" 2>&1; then
    tail -20 "$WLOG"
    T=$(sed -n 's/^합계 \([0-9]*\)건$/\1/p' "$WLOG")
    expect "${W}px 합계 0건" 0 "${T:-없음}"
  else
    echo "  ■ ui_snapshot.sh(${W}) 실행 실패"; tail -20 "$WLOG"; FAIL=$((FAIL+1))
  fi
done

sub "tests/help_button.sh"
HLOG="$OUT/help_button.txt"
bash "$ROOT/tests/help_button.sh" "$OUT/help-button" > "$HLOG" 2>&1
# help_button.sh 의 결과 줄은 「  통과 N · 실패 N」 꼴이다(앞에 「결과:」 가 없다).
HLAST=$(grep -E '통과 [0-9]+ · 실패 [0-9]+' "$HLOG" | tail -1)
echo "  $HLAST"
HF=$(printf '%s' "$HLAST" | sed -n 's/.*실패 \([0-9]*\).*/\1/p')
expect "help_button 실패 0" 0 "${HF:-없음}"

sub "scripts/security_lint.sh"
LINT="$OUT/security_lint.txt"
bash "$ROOT/scripts/security_lint.sh" > "$LINT" 2>&1
LRC=$?
tail -5 "$LINT"
expect "security_lint 종료 0" 0 "$LRC"

sub "php -l — 이번에 만든·고친 PHP"
N=0
for f in src/handlers/purchase_gr_list.php src/handlers/purchase_gr_get.php \
         src/handlers/purchase_receipt_page.php src/views/purchase_receipt.php \
         src/help/screens.php public/index.php; do
  php -l "$ROOT/$f" > /dev/null 2>&1 || { N=$((N+1)); echo "  ✖ $f"; }
done
expect "문법 오류 0" 0 "$N"

sub "금지 패턴 — 이번 변경분"
expect "구매 JS 에 innerHTML 계열 0 (주석 제외 — 설명 주석의 「innerHTML 을 쓰지 않는다」 는 결함이 아니다)" 0 \
  "$(count_in_code 'innerHTML|outerHTML|insertAdjacentHTML|document\.write' $ROOT/public/assets/purchase*.js)"
expect "입고 화면에 인라인 style= 0 (CSP)" 0 \
  "$(grep -c 'style="' "$ROOT/src/views/purchase_receipt.php")"
expect "화면별 CSS 신설 0 (css 는 셋 그대로)" 3 \
  "$(ls "$ROOT/public/assets/" | grep -c '\.css$')"
expect "새 핸들러 둘 다 purchaseScopeWhere 를 부른다" 2 \
  "$(grep -lc 'Authz::purchaseScopeWhere(' "$ROOT/src/handlers/purchase_gr_list.php" \
       "$ROOT/src/handlers/purchase_gr_get.php" | wc -l)"
expect "입고 취소는 기존 void API 하나뿐 (새 라우트 0)" 1 \
  "$(grep -c "api/purchase/gr/{num}/void" "$ROOT/public/index.php")"

hr "끝 — 정리로 넘어갑니다"
