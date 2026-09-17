#!/usr/bin/env bash
# tests/purchase_f62.sh — F-62 수락 기준 실행 (PRD 12.2 T-74)
#   발주서 담당자 축 — 나누는 열쇠가 (거래처, 입고 장소, 담당자) 세 축 ·
#   발주서의 담당자를 발주요청 전표에서 물려받음 · 머리 「담당자」 칸과 그 목록 창구
#   (PRD F-62 · F-61 · F-60 · S-01 · ARCH 5.18 「발주서 담당자·3축 분할」
#    · SECURITY R-65 · R-66 · R-70 · R-73 · R-78)
#
# 규칙 (CLAUDE.md 3장)
#   · **검토 계정(qa_ 접두)만** 쓴다. 시드 계정(admin01 …)은 사용자의 자기 공격 전용이다.
#   · 모든 요청에 `-A "tbox-review/<날짜>"` 를 붙인다.
#   · 비밀번호·TOTP 비밀은 **필요한 필드만 awk 로** 뽑고 변수에만 담는다(화면에 찍지 않는다).
#
# ★ 시험 안전 (SECURITY R-73 · R-78) — purchase_f61.sh 와 **같은 방식**이다
#   ① 최근 10분 감사 로그에 비-qa actor 가 있으면 시작하지 않는다(종료 코드 3).
#   ② 정리는 `created_by` 가 검토 계정인 행만. 표 전체 DELETE·TRUNCATE 는 한 줄도 없다.
#   ③ 채번 카운터 `p_doc_seq` 는 **지우지 않는다** — 남은 전표의 최대 번호로 되돌린다(R-78).
#   ④ 시작·끝에 비-qa 행 수를 찍고 달라졌으면 실패로 센다.
#   ⑤ 시험이 만든 요청·발주서는 정리 직전에 **창구(void)로 취소**한 뒤 지운다.
#   ⑥ 감사 단언은 **실행 시작 시각(T0)** 이후 행만 센다.
#
# 사용: sudo -v && bash tests/purchase_f62.sh
set -u

BASE="http://127.0.0.1:8082"
UA="tbox-review/$(date +%F)"
DB="tbox_re"
JAR_DIR="$(mktemp -d)"
# 화면 시험의 부산물(스크린샷·JSON)을 두는 곳. F62_OUT 으로 밖에 남길 수 있다.
OUT="${F62_OUT:-$JAR_DIR/out}"; mkdir -p "$OUT"
PASS=0
FAIL=0

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

# 감사 단언의 기준점 (T0) — 이 시험이 남긴 행만 센다
AUD_T0=$(psqlq "SELECT coalesce(max(id),0) FROM audit_logs;")
echo "■ 감사 기준점 T0 = audit_logs.id > $AUD_T0"

# ─── 로그인 ──────────────────────────────────────────────────
qa_password() { sudo -n awk -v u="$1" '$1==u{print $3}' /etc/tbox-v2/seed-accounts.txt; }
qa_secret()   { sudo -n awk -v u="$1" '$1==u{print $2}' /etc/tbox-v2/qa-totp.txt; }

# TOTP 허용 창이 0(현재 구간만)이라 같은 30초 안 재로그인은 재사용으로 거부된다 —
# purchase_f61.sh 와 같은 재시도 방식이다(다음 창까지 기다린 뒤 다시 보낸다).
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

# 시험이 만든 전표를 **창구로** 취소한다
void_made() {
  local id c t
  [ -n "${TOK_MGR:-}" ] || return 0
  for id in $(psqlq "SELECT id FROM p_docs
                      WHERE created_by IN ($QA_IDS) AND doc_type = 'po' AND status <> 'void'
                      ORDER BY id DESC;"); do
    c=$(code_of "$(call qa_manager01 POST "/api/purchase/po/$id/void" "$TOK_MGR" '{"reason":"F-62 검토 시험 정리"}')")
    printf '  발주서 %s 취소 → HTTP %s\n' "$id" "$c"
  done
  [ -n "${TOK_EMP1:-}" ] || return 0
  for id in $(psqlq "SELECT id FROM p_docs
                      WHERE created_by IN ($QA_IDS) AND doc_type = 'pr' AND status <> 'void'
                      ORDER BY id DESC;"); do
    t=$(psqlq "SELECT created_by FROM p_docs WHERE id = $id;")
    if [ "$t" = "${E2_ID:-}" ] && [ -n "${TOK_EMP2:-}" ]; then
      c=$(code_of "$(call qa_employee02 POST "/api/purchase/pr/$id/void" "$TOK_EMP2" '{"reason":"F-62 검토 시험 정리"}')")
    else
      c=$(code_of "$(call qa_employee01 POST "/api/purchase/pr/$id/void" "$TOK_EMP1" '{"reason":"F-62 검토 시험 정리"}')")
    fi
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

  # (f) 가 잠시 없앤 모듈 역할을 돌려놓은 뒤에 취소 창구를 부른다
  psqlc "UPDATE users SET purchase_role = 'requester'
          WHERE username IN ('qa_employee01','qa_employee02') AND purchase_role IS NULL;" \
    && echo "  (f) 가 없앤 모듈 역할 임시 복구 (아래에서 다시 NULL 로 원복)"

  sub "① 창구로 취소 (void)"
  void_made

  psqlq "SELECT '  사용자(비-qa)가 만든 행: p_docs='||
           (SELECT count(*) FROM p_docs WHERE created_by NOT IN ($qa_ids))||
         ' p_items='||(SELECT count(*) FROM p_items WHERE created_by NOT IN ($qa_ids))||
         ' — 이 행들은 그대로 둡니다';"

  # 검토 지적 ②4 — (i) 가 qa_employee02 를 잠시 중단시킨다. 스크립트가 그 사이에 죽으면
  # 계정이 중단된 채 남아 다음 시험이 조용히 어긋난다. 그래서 정리에서도 되돌린다.
  psqlc "UPDATE users SET branch_id = NULL, purchase_role = NULL, is_active = true
          WHERE username IN ('qa_employee01','qa_employee02','qa_manager01');" \
    && echo "  qa 계정 branch_id·purchase_role·is_active 원복"

  psqlc "DELETE FROM p_doc_lines WHERE doc_id IN (SELECT id FROM p_docs WHERE created_by IN ($qa_ids));
         DELETE FROM p_docs      WHERE created_by IN ($qa_ids);
         -- R-78: 채번 카운터는 qa 의 것이 아니다 — 지우지 않고 「남은 전표의 최대 번호」로 되돌린다
         UPDATE p_doc_seq s SET last_no = COALESCE((SELECT max(split_part(d.doc_no,'-',3)::int) FROM p_docs d
                                                    WHERE d.doc_date = CURRENT_DATE AND d.doc_type = s.doc_type), 0)
          WHERE s.seq_date = CURRENT_DATE;
         DELETE FROM p_item_branches WHERE item_id IN (SELECT id FROM p_items WHERE created_by IN ($qa_ids));
         DELETE FROM p_items         WHERE created_by IN ($qa_ids);
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

E1_ID=$(psqlq "SELECT id FROM users WHERE username='qa_employee01';")
E2_ID=$(psqlq "SELECT id FROM users WHERE username='qa_employee02';")
MGR_ID=$(psqlq "SELECT id FROM users WHERE username='qa_manager01';")
echo "  담당자 후보 id: emp01=$E1_ID emp02=$E2_ID mgr01=$MGR_ID"

hr "1. 기초정보 씨앗 — 브랜드·지점·장소 2·거래처 2·품목"

R=$(call qa_admin01 POST /api/purchase/brands "$TOK_ADMIN" \
  '{"name":"F62 치과","biz_no":"123-45-67892","ceo_name":"검토 대표","address":"인천 부평구","tel":"032-000-0000"}')
BRAND=$(new_id "$(body_of "$R")"); expect "브랜드 생성" 201 "$(code_of "$R")"

R=$(call qa_admin01 POST /api/purchase/branches "$TOK_ADMIN" "{\"brand_id\":$BRAND,\"name\":\"F62 부평점\"}")
BR_A=$(new_id "$(body_of "$R")"); expect "지점A 생성" 201 "$(code_of "$R")"

R=$(call qa_admin01 POST /api/purchase/locations "$TOK_ADMIN" \
  "{\"branch_id\":$BR_A,\"kind\":\"warehouse\",\"name\":\"F62 중앙창고\"}")
LOC_X=$(new_id "$(body_of "$R")"); expect "장소X(중앙창고) 생성" 201 "$(code_of "$R")"
R=$(call qa_admin01 POST /api/purchase/locations "$TOK_ADMIN" \
  "{\"branch_id\":$BR_A,\"kind\":\"or\",\"name\":\"F62 수술방\"}")
LOC_Y=$(new_id "$(body_of "$R")"); expect "장소Y(수술방) 생성" 201 "$(code_of "$R")"

R=$(call qa_admin01 POST /api/purchase/vendors "$TOK_ADMIN" '{"name":"F62 거래처A","code":"F62VA"}')
VEND_A=$(new_id "$(body_of "$R")"); expect "거래처A 생성" 201 "$(code_of "$R")"
R=$(call qa_admin01 POST /api/purchase/vendors "$TOK_ADMIN" '{"name":"F62 거래처B","code":"F62VB"}')
VEND_B=$(new_id "$(body_of "$R")"); expect "거래처B 생성" 201 "$(code_of "$R")"

mkitem() { # $1 vendor  $2 code  $3 name  $4 price
  local r
  r=$(call qa_admin01 POST /api/purchase/items "$TOK_ADMIN" \
    "{\"vendor_id\":$1,\"code\":\"$2\",\"name\":\"$3\",\"unit\":\"EA\",\"price_in\":\"$4\"}")
  printf '  품목 %s → HTTP %s\n' "$2" "$(code_of "$r")" >&2
  new_id "$(body_of "$r")"
}
I_A1=$(mkitem "$VEND_A" F62-A1 "F62 gauze"   1000)
I_A2=$(mkitem "$VEND_A" F62-A2 "F62 cotton"  2000)
I_A3=$(mkitem "$VEND_A" F62-A3 "F62 swab"    1200)
I_A4=$(mkitem "$VEND_A" F62-A4 "F62 mask"    1300)
I_B1=$(mkitem "$VEND_B" F62-B1 "F62 needle"  3000)
I_B2=$(mkitem "$VEND_B" F62-B2 "F62 syringe" 3300)
I_D=$(mkitem  "$VEND_A" F62-D1 "F62 direct"  1500)
expect "품목 7개가 모두 만들어졌다" 7 \
  "$(psqlq "SELECT count(*) FROM p_items WHERE id IN ($I_A1,$I_A2,$I_A3,$I_A4,$I_B1,$I_B2,$I_D);")"

sub "검토용 모듈 역할·지점 심기 (psql, 끝에서 원복)"
psqlc "UPDATE users SET branch_id = $BR_A, purchase_role = 'requester' WHERE username = 'qa_employee01';"
psqlc "UPDATE users SET branch_id = $BR_A, purchase_role = 'requester' WHERE username = 'qa_employee02';"
psqlc "UPDATE users SET branch_id = NULL,  purchase_role = 'buyer'     WHERE username = 'qa_manager01';"
psqlq "SELECT username||' branch='||coalesce(branch_id::text,'-')||' role='||coalesce(purchase_role,'-')
         FROM users WHERE username LIKE 'qa%' ORDER BY id;" | sed 's/^/  /'

# 발주요청 하나 만들고 첫 줄 id 를 돌려준다
mkpr() { # $1 who  $2 token  $3 location  $4 item  $5 qty  $6 memo
  local r id
  r=$(call "$1" POST /api/purchase/pr "$2" \
    "{\"to_location_id\":$3,\"memo\":\"$6\",\"lines\":[{\"item_id\":$4,\"qty\":\"$5\"}]}")
  printf '  요청 %s (%s) → HTTP %s\n' "$6" "$1" "$(code_of "$r")" >&2
  id=$(new_id "$(body_of "$r")")
  psqlq "SELECT id FROM p_doc_lines WHERE doc_id = $id;"
}

# ═════════════════════════════════════════════════════════════
hr "T-74 (a) 같은 거래처·같은 장소 · **담당자가 다른** 요청 2건 → 2장"
# ═════════════════════════════════════════════════════════════

L_A=$(mkpr qa_employee01 "$TOK_EMP1" "$LOC_X" "$I_A1" 5 "F62 a-emp01")
L_B=$(mkpr qa_employee02 "$TOK_EMP2" "$LOC_X" "$I_A2" 3 "F62 a-emp02")
echo "  요청 줄: emp01=$L_A emp02=$L_B (둘 다 장소X · 거래처A)"

AUD_A=$(psqlq "SELECT coalesce(max(id),0) FROM audit_logs;")
R=$(call qa_manager01 POST /api/purchase/po "$TOK_MGR" \
  "{\"split\":true,\"lines\":[{\"src_line_id\":$L_A,\"qty\":\"5\"},{\"src_line_id\":$L_B,\"qty\":\"3\"}]}")
B=$(body_of "$R")
echo "  HTTP $(code_of "$R")"; echo "  $B"; echo
expect "머리 담당자 없이 201" 201 "$(code_of "$R")"
expect "docs 2장" 2 "$(jq_py "$B" "d['count']")"
expect "응답 docs 에 manager_id 칸이 있다" 1 \
  "$(jq_py "$B" "int(all('manager_id' in x for x in d['docs']))")"
expect "두 장의 담당자가 각 요청 전표의 담당자다" \
  "$(python3 -c "print(sorted([$E1_ID, $E2_ID]))")" \
  "$(jq_py "$B" "sorted(x['manager_id'] for x in d['docs'])")"
expect "거래처·장소는 한 가지뿐(담당자만 갈렸다)" 1 \
  "$(jq_py "$B" "len(set((x['vendor_id'], x['to_location_id']) for x in d['docs']))")"
expect "DB 의 manager_id 도 같다 (emp01 장 1개)" 1 \
  "$(psqlq "SELECT count(*) FROM p_docs d JOIN p_doc_lines l ON l.doc_id = d.id
             WHERE d.doc_type='po' AND d.manager_id = $E1_ID AND l.src_line_id = $L_A;")"
expect "DB 의 manager_id 도 같다 (emp02 장 1개)" 1 \
  "$(psqlq "SELECT count(*) FROM p_docs d JOIN p_doc_lines l ON l.doc_id = d.id
             WHERE d.doc_type='po' AND d.manager_id = $E2_ID AND l.src_line_id = $L_B;")"
AUD_A2=$(psqlq "SELECT coalesce(max(id),0) FROM audit_logs;")
expect "만든 사람은 담당자와 **다른 칸**이다 — created_by = qa_manager01" 2 \
  "$(psqlq "SELECT count(*) FROM p_docs WHERE id IN ($(jq_py "$B" "','.join(str(x['id']) for x in d['docs'])")) AND created_by = $MGR_ID;")"

# ═════════════════════════════════════════════════════════════
hr "T-74 (b) 같은 거래처·장소·**같은 담당자** 요청 2건 → 1장 (F-60·F-61 불변)"
# ═════════════════════════════════════════════════════════════

L_C=$(mkpr qa_employee01 "$TOK_EMP1" "$LOC_X" "$I_A3" 2 "F62 b-1")
L_D=$(mkpr qa_employee01 "$TOK_EMP1" "$LOC_X" "$I_A4" 4 "F62 b-2")
R=$(call qa_manager01 POST /api/purchase/po "$TOK_MGR" \
  "{\"split\":true,\"lines\":[{\"src_line_id\":$L_C,\"qty\":\"2\"},{\"src_line_id\":$L_D,\"qty\":\"4\"}]}")
B=$(body_of "$R")
echo "  HTTP $(code_of "$R")"; echo "  $B"; echo
expect "201" 201 "$(code_of "$R")"
expect "1장이다" 1 "$(jq_py "$B" "d['count']")"
expect "그 장의 줄 수 2" 2 "$(jq_py "$B" "d['docs'][0]['line_count']")"
expect "담당자 = emp01" "$E1_ID" "$(jq_py "$B" "d['docs'][0]['manager_id']")"

# ═════════════════════════════════════════════════════════════
hr "T-74 (c) 머리 manager_id 명시 → 담당자가 섞인 줄이어도 1장 · 전 줄 그 담당자"
# ═════════════════════════════════════════════════════════════

L_E=$(mkpr qa_employee01 "$TOK_EMP1" "$LOC_X" "$I_A1" 3 "F62 c-emp01")
L_F=$(mkpr qa_employee02 "$TOK_EMP2" "$LOC_X" "$I_A2" 3 "F62 c-emp02")
R=$(call qa_manager01 POST /api/purchase/po "$TOK_MGR" \
  "{\"split\":true,\"manager_id\":$MGR_ID,\"lines\":[{\"src_line_id\":$L_E,\"qty\":\"3\"},{\"src_line_id\":$L_F,\"qty\":\"3\"}]}")
B=$(body_of "$R")
echo "  HTTP $(code_of "$R")"; echo "  $B"; echo
expect "머리 명시 → 201" 201 "$(code_of "$R")"
expect "명시가 우선 — 1장" 1 "$(jq_py "$B" "d['count']")"
expect "그 장의 담당자 = 머리값" "$MGR_ID" "$(jq_py "$B" "d['docs'][0]['manager_id']")"
PO_C=$(jq_py "$B" "d['docs'][0]['id']")
expect "그 장의 줄 2개가 서로 다른 요청에서 왔다" 2 \
  "$(psqlq "SELECT count(*) FROM p_doc_lines WHERE doc_id = $PO_C AND src_line_id IN ($L_E,$L_F);")"

sub "머리에 **쓸 수 없는 담당자**를 적으면 기존 머리 검사가 그대로 막는다"
GUEST_ID=$(psqlq "SELECT id FROM users WHERE username='qa_guest01';")
R=$(call qa_manager01 POST /api/purchase/po "$TOK_MGR" \
  "{\"to_location_id\":$LOC_X,\"split\":true,\"manager_id\":$GUEST_ID,\"lines\":[{\"item_id\":$I_D,\"qty\":\"1\"}]}")
echo "  HTTP $(code_of "$R")  $(body_of "$R")"
expect "모듈 역할 없는 계정을 머리 담당자로 → 400" 400 "$(code_of "$R")"
expect "감사 사유 bad_reference (기존 코드)" "bad_reference" "$(deny_reason)"

# ═════════════════════════════════════════════════════════════
hr "T-74 (d) 세 축이 전부 갈리는 요청들 → 장 수 = 서로 다른 (거래처,장소,담당자) 조합 수"
# ═════════════════════════════════════════════════════════════

L1=$(mkpr qa_employee01 "$TOK_EMP1" "$LOC_X" "$I_A1" 1 "F62 d1 A-X-e1")
L2=$(mkpr qa_employee02 "$TOK_EMP2" "$LOC_X" "$I_A2" 1 "F62 d2 A-X-e2")
L3=$(mkpr qa_employee01 "$TOK_EMP1" "$LOC_Y" "$I_B1" 1 "F62 d3 B-Y-e1")
L4=$(mkpr qa_employee02 "$TOK_EMP2" "$LOC_Y" "$I_B2" 1 "F62 d4 B-Y-e2")
AUD_D=$(psqlq "SELECT coalesce(max(id),0) FROM audit_logs;")
R=$(call qa_manager01 POST /api/purchase/po "$TOK_MGR" \
  "{\"split\":true,\"lines\":[{\"src_line_id\":$L1,\"qty\":\"1\"},{\"src_line_id\":$L2,\"qty\":\"1\"},{\"src_line_id\":$L3,\"qty\":\"1\"},{\"src_line_id\":$L4,\"qty\":\"1\"}]}")
B=$(body_of "$R")
echo "  HTTP $(code_of "$R")"; echo "  $B"; echo
expect "201" 201 "$(code_of "$R")"
expect "장 수 4 (조합 4가지)" 4 "$(jq_py "$B" "d['count']")"
expect "세 축 조합이 서로 다르다" 4 \
  "$(jq_py "$B" "len(set((x['vendor_id'], x['to_location_id'], x['manager_id']) for x in d['docs']))")"
expect "장마다 줄 1개" 1 \
  "$(jq_py "$B" "int(all(x['line_count']==1 for x in d['docs']))")"
expect "감사 allow 4행 · split_count=4" 4 \
  "$(psqlq "SELECT count(*) FROM audit_logs WHERE id > $AUD_D
             AND action='purchase.order.create' AND result='allow'
             AND detail::jsonb->>'split_count' = '4';")"

# ═════════════════════════════════════════════════════════════
hr "T-74 (e) 직접 줄 — 담당자 = 로그인 사용자 · 요청 줄과 섞이면 2장"
# ═════════════════════════════════════════════════════════════

R=$(call qa_manager01 POST /api/purchase/po "$TOK_MGR" \
  "{\"split\":true,\"lines\":[{\"item_id\":$I_D,\"qty\":\"3\",\"location_id\":$LOC_X}]}")
B=$(body_of "$R")
echo "  HTTP $(code_of "$R")"; echo "  $B"; echo
expect "직접 줄만 → 201" 201 "$(code_of "$R")"
expect "1장" 1 "$(jq_py "$B" "d['count']")"
expect "담당자 = 로그인 사용자(qa_manager01)" "$MGR_ID" "$(jq_py "$B" "d['docs'][0]['manager_id']")"

sub "요청 줄(emp01) + 직접 줄(로그인 사용자) — 같은 거래처·장소인데 담당자가 다르다 → 2장"
L_G=$(mkpr qa_employee01 "$TOK_EMP1" "$LOC_X" "$I_A3" 2 "F62 e-mix")
R=$(call qa_manager01 POST /api/purchase/po "$TOK_MGR" \
  "{\"split\":true,\"lines\":[{\"src_line_id\":$L_G,\"qty\":\"2\"},{\"item_id\":$I_D,\"qty\":\"1\",\"location_id\":$LOC_X}]}")
B=$(body_of "$R")
echo "  HTTP $(code_of "$R")"; echo "  $B"; echo
expect "201" 201 "$(code_of "$R")"
expect "2장 (담당자만 갈렸다)" 2 "$(jq_py "$B" "d['count']")"
expect "거래처·장소는 한 가지" 1 \
  "$(jq_py "$B" "len(set((x['vendor_id'], x['to_location_id']) for x in d['docs']))")"
expect "담당자 둘 = {emp01, mgr01}" \
  "$(python3 -c "print(sorted([$E1_ID, $MGR_ID]))")" \
  "$(jq_py "$B" "sorted(x['manager_id'] for x in d['docs'])")"
expect "머리 담당자를 명시하면 같은 줄이 1장" 1 \
  "$(jq_py "$(body_of "$(call qa_manager01 POST /api/purchase/po "$TOK_MGR" \
      "{\"split\":true,\"manager_id\":$MGR_ID,\"lines\":[{\"item_id\":$I_A4,\"qty\":\"1\",\"location_id\":$LOC_X},{\"item_id\":$I_D,\"qty\":\"1\",\"location_id\":$LOC_X}]}")")" "d['count']")"

# ═════════════════════════════════════════════════════════════
hr "T-74 (f) 담당자 계정의 모듈 역할이 사라진 요청 줄 → 400 bad_reference"
# ═════════════════════════════════════════════════════════════

L_H=$(mkpr qa_employee02 "$TOK_EMP2" "$LOC_X" "$I_A1" 1 "F62 f-stopped")
psqlc "UPDATE users SET purchase_role = NULL WHERE username = 'qa_employee02';" \
  && echo "  qa_employee02 의 모듈 역할을 잠시 없앴다 (Doc::managerOk 가 거부해야 한다)"
R=$(call qa_manager01 POST /api/purchase/po "$TOK_MGR" \
  "{\"split\":true,\"lines\":[{\"src_line_id\":$L_H,\"qty\":\"1\"}]}")
echo "  HTTP $(code_of "$R")  $(body_of "$R")"
expect "물려받은 담당자가 쓸 수 없는 계정 → 400" 400 "$(code_of "$R")"
expect "감사 사유 bad_reference (새 사유 코드 0)" "bad_reference" "$(deny_reason)"
expect "반 장도 남지 않았다 (그 요청 줄로 만든 발주서 0)" 0 \
  "$(psqlq "SELECT count(*) FROM p_doc_lines WHERE src_line_id = $L_H;")"
sub "머리 담당자를 명시하면 같은 줄이 저장된다 (명시 우선)"
R=$(call qa_manager01 POST /api/purchase/po "$TOK_MGR" \
  "{\"split\":true,\"manager_id\":$MGR_ID,\"lines\":[{\"src_line_id\":$L_H,\"qty\":\"1\"}]}")
echo "  HTTP $(code_of "$R")  $(body_of "$R")" | head -c 400; echo
expect "머리 명시로는 201" 201 "$(code_of "$R")"
psqlc "UPDATE users SET purchase_role = 'requester' WHERE username = 'qa_employee02';" \
  && echo "  qa_employee02 의 모듈 역할 복구"

# ═════════════════════════════════════════════════════════════
hr "T-74 (g) split 없는 옛 요청 — 담당자 = 머리 ?? 로그인 사용자 (계약 불변)"
# ═════════════════════════════════════════════════════════════

L_I=$(mkpr qa_employee01 "$TOK_EMP1" "$LOC_X" "$I_A1" 1 "F62 g-old")
R=$(call qa_manager01 POST /api/purchase/po "$TOK_MGR" \
  "{\"to_location_id\":$LOC_X,\"lines\":[{\"src_line_id\":$L_I,\"qty\":\"1\"}]}")
B=$(body_of "$R")
echo "  HTTP $(code_of "$R")"; echo "  $B" | head -c 400; echo
expect "split 없이 201" 201 "$(code_of "$R")"
expect "옛 응답 모양 그대로 (doc_no 있음)" 1 "$(jq_py "$B" "int('doc_no' in d)")"
expect "옛 응답에 docs 가 없다" 0 "$(jq_py "$B" "int('docs' in d)")"
expect "옛 응답에 manager_id 를 새로 넣지 않았다" 0 "$(jq_py "$B" "int('manager_id' in d)")"
PO_OLD=$(jq_py "$B" "d['id']")
expect "담당자 = 로그인 사용자 (요청서의 emp01 이 아니다)" "$MGR_ID" \
  "$(psqlq "SELECT manager_id FROM p_docs WHERE id = $PO_OLD;")"

sub "split 없이 머리 담당자를 적으면 그 담당자 (옛 규칙 그대로)"
L_J=$(mkpr qa_employee01 "$TOK_EMP1" "$LOC_X" "$I_A2" 1 "F62 g-old2")
R=$(call qa_manager01 POST /api/purchase/po "$TOK_MGR" \
  "{\"to_location_id\":$LOC_X,\"manager_id\":$E1_ID,\"lines\":[{\"src_line_id\":$L_J,\"qty\":\"1\"}]}")
B=$(body_of "$R")
expect "201" 201 "$(code_of "$R")"
expect "담당자 = 머리값(emp01)" "$E1_ID" \
  "$(psqlq "SELECT manager_id FROM p_docs WHERE id = $(jq_py "$B" "d['id']");")"

sub "split 없는 요청의 감사 행에는 split_count 가 없다 (불변)"
# 검토 지적 ②6 — 같은 파일의 다른 단언과 같은 규칙으로 좁힌다: 시작 시각(T0) 이후 ·
# 검토 계정이 남긴 행만. 시작 관문이 비-qa 활동 0 을 보장하지만, 규칙은 한 벌이어야 한다.
expect "이 시험이 남긴 마지막 두 행에 split_count 0건" 0 \
  "$(psqlq "SELECT count(*) FROM (SELECT detail FROM audit_logs
             WHERE id > $AUD_T0 AND actor_name LIKE 'qa\\_%'
               AND action='purchase.order.create' AND result='allow'
             ORDER BY id DESC LIMIT 2) x WHERE x.detail::jsonb ? 'split_count';")"

# ═════════════════════════════════════════════════════════════
hr "T-74 (h) 감사 — allow N 행 · split_count = N · **새 키 없음**"
# ═════════════════════════════════════════════════════════════

psqlq "SELECT '  '||target_name||' | '||detail FROM audit_logs
        WHERE id > $AUD_T0 AND action='purchase.order.create' AND result='allow'
        ORDER BY id DESC LIMIT 5;"
expect "만든 발주서마다 allow 행이 **한 줄씩** 있다" \
  "$(psqlq "SELECT count(*) FROM p_docs WHERE created_by IN ($QA_IDS) AND doc_type='po';")" \
  "$(psqlq "SELECT count(*) FROM audit_logs a WHERE a.id > $AUD_T0
             AND a.action='purchase.order.create' AND a.result='allow'
             AND a.target_id IN (SELECT id FROM p_docs
                                  WHERE created_by IN ($QA_IDS) AND doc_type='po');")"
expect "(a) 가 남긴 두 행 모두 split_count=2" 2 \
  "$(psqlq "SELECT count(*) FROM audit_logs WHERE id > $AUD_A AND id <= $AUD_A2
             AND action='purchase.order.create' AND result='allow'
             AND detail::jsonb->>'split_count' = '2';")"
# 실측 정정(2026-09-17) — 허용 목록이 모자랐다. `Audit` 은 **모든 행에** `route`·`login_ref`·
# `actor_team` 을 자동으로 붙인다(`src/Audit.php:1138`·`1169`·`1172`). F-62 **이전** 행
# (오늘 13:39 admin01 의 발주서)의 키 집합도 이미 열 개로 같다 — 그러므로 F-62 가 더한
# detail 키는 **0개**이고, 처음 실패한 것은 이 단언의 기대값이었다.
expect "detail 에 **새 키가 없다** (기존 이름만 — 자동 3키 포함 열 개)" 0 \
  "$(psqlq "SELECT count(*) FROM audit_logs a
             WHERE a.id > $AUD_T0 AND a.action='purchase.order.create' AND a.result='allow'
               AND a.actor_name LIKE 'qa\\_%'
               AND EXISTS (SELECT 1 FROM jsonb_object_keys(a.detail::jsonb) k
                            WHERE k NOT IN ('doc_no','doc_type','branch_id','lines','amount',
                                            'src_doc_no','split_count',
                                            'route','login_ref','actor_team'));")"
expect "감사 문장이 새로 늘지 않았다 (purchase.order.* 은 기존 이름뿐)" 0 \
  "$(psqlq "SELECT count(*) FROM audit_logs WHERE id > $AUD_T0
             AND actor_name LIKE 'qa\\_%'
             AND action LIKE 'purchase.order.%'
             AND action NOT IN ('purchase.order.create','purchase.order.update',
                                'purchase.order.send','purchase.order.void');")"
expect "담당자 목록 조회는 감사를 남기지 않는다" 0 \
  "$(psqlq "SELECT count(*) FROM audit_logs WHERE id > $AUD_T0 AND action LIKE '%manager%';")"

# ═════════════════════════════════════════════════════════════
hr "T-74 (i) GET /api/purchase/managers — buyer 200 · 키 둘 · requester 403"
# ═════════════════════════════════════════════════════════════

R=$(call qa_manager01 GET /api/purchase/managers "$TOK_MGR")
B=$(body_of "$R")
echo "  HTTP $(code_of "$R")"; echo "  $B" | head -c 600; echo
expect "buyer 200" 200 "$(code_of "$R")"
expect "items 배열이 있다" 1 "$(jq_py "$B" "int(isinstance(d.get('items'), list))")"
expect "각 항목의 키가 **정확히 id·name 둘**" 1 \
  "$(jq_py "$B" "int(all(sorted(x.keys())==['id','name'] for x in d['items']))")"
expect "응답에 items 말고 다른 칸이 없다" "['items']" "$(jq_py "$B" "sorted(d.keys())")"
expect "id 는 숫자다" 1 "$(jq_py "$B" "int(all(isinstance(x['id'], int) for x in d['items']))")"
expect "목록이 이름 오름차순이다" 1 \
  "$(jq_py "$B" "int([x['name'] for x in d['items']] == sorted(x['name'] for x in d['items']))")"

sub "목록이 **Doc::managerOk 와 같은 정의**인가 — DB 와 한 사람도 다르지 않다"
expect "목록 수 = (활성 · 모듈 역할 있는) 계정 수" \
  "$(psqlq "SELECT count(*) FROM users WHERE is_active AND purchase_role IS NOT NULL;")" \
  "$(jq_py "$B" "len(d['items'])")"
expect "역할 없는 계정(qa_guest01)이 목록에 없다" 0 \
  "$(jq_py "$B" "sum(1 for x in d['items'] if x['id']==$GUEST_ID)")"
expect "역할 있는 계정(qa_employee01)은 목록에 있다" 1 \
  "$(jq_py "$B" "sum(1 for x in d['items'] if x['id']==$E1_ID)")"
expect "목록의 모든 id 가 DB 조건을 만족한다" 0 \
  "$(psqlq "SELECT count(*) FROM users
             WHERE id IN ($(jq_py "$B" "','.join(str(x['id']) for x in d['items'])"))
               AND NOT (is_active AND purchase_role IS NOT NULL);")"

sub "중단된 계정은 목록에서 사라진다 (qa_employee02 를 잠시 중단)"
psqlc "UPDATE users SET is_active = false WHERE username = 'qa_employee02';"
R2=$(call qa_manager01 GET /api/purchase/managers "$TOK_MGR")
expect "중단된 계정이 목록에 없다" 0 \
  "$(jq_py "$(body_of "$R2")" "sum(1 for x in d['items'] if x['id']==$E2_ID)")"
psqlc "UPDATE users SET is_active = true WHERE username = 'qa_employee02';" \
  && echo "  qa_employee02 복구"

sub "requester 는 이 창구를 볼 수 없다 (purchase.buy.write)"
R=$(call qa_employee01 GET /api/purchase/managers "$TOK_EMP1")
echo "  HTTP $(code_of "$R")  $(body_of "$R")"
expect "requester 403" 403 "$(code_of "$R")"
expect "라우트 거부가 감사에 남는다 (authz.route deny)" 1 \
  "$(psqlq "SELECT CASE WHEN count(*) > 0 THEN 1 ELSE 0 END FROM audit_logs
             WHERE id > $AUD_T0 AND action = 'authz.route' AND result = 'deny'
               AND target_name LIKE '%/api/purchase/managers%';")"

# ═════════════════════════════════════════════════════════════
hr "T-74 (j) 화면 — 머리 담당자 칸 · 미리보기 3축 문구 (Playwright)"
# ═════════════════════════════════════════════════════════════

sub "화면 씨앗 — ① 담당자만 다른 요청 2건(F62UIA) ② 담당자가 같고 거래처·장소가 다른 2건(F62UIC)"
UA1=$(mkitem "$VEND_A" F62UIA-1 "F62UIA swab"    1500)
UA2=$(mkitem "$VEND_A" F62UIA-2 "F62UIA cotton"  1600)
UC1=$(mkitem "$VEND_A" F62UIC-1 "F62UIC gauze"   1700)
UC2=$(mkitem "$VEND_B" F62UIC-2 "F62UIC needle"  1800)
expect "화면용 품목 4개" 4 \
  "$(psqlq "SELECT count(*) FROM p_items WHERE id IN ($UA1,$UA2,$UC1,$UC2);")"
LU1=$(mkpr qa_employee01 "$TOK_EMP1" "$LOC_X" "$UA1" 4 "F62UIA emp01")
LU2=$(mkpr qa_employee02 "$TOK_EMP2" "$LOC_X" "$UA2" 6 "F62UIA emp02")
LU3=$(mkpr qa_employee01 "$TOK_EMP1" "$LOC_X" "$UC1" 2 "F62UIC X")
LU4=$(mkpr qa_employee01 "$TOK_EMP1" "$LOC_Y" "$UC2" 3 "F62UIC Y")
echo "  화면용 요청 줄: $LU1 $LU2 (담당자만 다름) · $LU3 $LU4 (담당자 같음)"

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
base, ua, state, out = sys.argv[1:5]
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
    page.wait_for_timeout(1500)

    # ① 머리에 「담당자」 칸이 있고 첫 값이 「— 줄에서 자동 —」
    report['mgr_exists'] = page.is_visible('#po-manager')
    report['mgr_label'] = page.eval_on_selector(
        '#po-manager', 'e => e.closest("label").querySelector(".field__label").textContent.trim()')
    report['mgr_first'] = page.eval_on_selector('#po-manager', 'e => e.options[0].text')
    report['mgr_value'] = page.eval_on_selector('#po-manager', 'e => e.value')
    report['mgr_options'] = page.eval_on_selector('#po-manager', 'e => e.options.length')
    report['loc_help'] = page.eval_on_selector(
        '#po-location', 'e => e.closest("label").querySelector(".p-sub").textContent.trim()')
    report['line_headers'] = page.eval_on_selector_all(
        '.p-lines thead th', 'els => els.map(e => e.textContent.trim())')

    def pick(q):
        page.click('#po-load')
        page.wait_for_timeout(900)
        # 묶는 기준을 「전표」 하나로 (기본으로 켜진 거래처를 끈다)
        if page.eval_on_selector('#load-groups .chip[data-group="vendor"]',
                                 'e => e.classList.contains("is-on")'):
            page.click('#load-groups .chip[data-group="vendor"]')
        if not page.eval_on_selector('#load-groups .chip[data-group="doc"]',
                                     'e => e.classList.contains("is-on")'):
            page.click('#load-groups .chip[data-group="doc"]')
        page.fill('#load-q', q)
        page.click('#load-run')
        page.wait_for_timeout(1500)
        checks = page.locator('#load-tree .p-tree-check')
        n = checks.count()
        for i in range(n):
            checks.nth(i).check()
            page.wait_for_timeout(300)
        page.click('#load-add')
        page.wait_for_timeout(900)
        return n

    # ② 담당자만 다른 전표 둘 → 「거래처×담당자별 2장」
    report['nodes_a'] = pick('F62UIA')
    report['preview_mgr'] = page.text_content('#po-split-preview')
    report['rows_a'] = page.eval_on_selector_all('#po-line-body tr', 'els => els.length')
    page.screenshot(path='%s/f62-preview-manager.png' % out, full_page=True)

    # ③ 머리 담당자를 고르면 한 장으로 합쳐진다 (명시 우선)
    page.select_option('#po-manager', index=1)
    page.wait_for_timeout(600)
    report['preview_head'] = page.text_content('#po-split-preview')
    page.select_option('#po-manager', index=0)
    page.wait_for_timeout(600)

    # ④ 저장 → 전표 2개
    page.click('#po-save')
    page.wait_for_timeout(3000)
    report['msg'] = page.text_content('#msg')
    page.screenshot(path='%s/f62-saved.png' % out, full_page=True)

    # ⑤ 담당자가 하나뿐일 때 — F-61 문구 그대로 「거래처×입고장소별 2장」
    report['nodes_c'] = pick('F62UIC')
    report['preview_same_mgr'] = page.text_content('#po-split-preview')
    page.screenshot(path='%s/f62-preview-f61.png' % out, full_page=True)

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
  echo "  ! playwright 가 없어 (j) 를 실행하지 못했다 — **SKIP (미검증)**"
elif grep -q 'URL=.*/login' "$OUT/f-login.txt"; then
  echo "  ✖ 브라우저 로그인 실패 — (j) 를 건너뛴다"
  FAIL=$((FAIL+1))
else
  python3 "$OUT/f-screen.py" "$BASE" "$UA" "$OUT/f-state.json" "$OUT/shots" \
    > "$OUT/f-screen.json" 2>&1
  sed 's/^/    /' "$OUT/f-screen.json"
  if grep -q PLAYWRIGHT_MISSING "$OUT/f-screen.json"; then
    echo "  ! playwright 가 없어 (j) 를 실행하지 못했다 — **SKIP (미검증)**"
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
    expect "머리에 담당자 칸이 있다" 1 "$(g mgr_exists)"
    expect "그 칸의 이름이 「담당자」" "담당자" "$(g mgr_label)"
    expect "첫 값이 「— 줄에서 자동 —」" "— 줄에서 자동 —" "$(g mgr_first)"
    expect "기본값이 비어 있다" "" "$(g mgr_value)"
    expect "담당자 목록이 채워졌다 (빈 항목 말고도 있다)" 1 \
      "$([ "$(g mgr_options)" -ge 2 ] && echo 1 || echo 0)"
    expect "입고 장소 도움말에 담당자 축이 적혀 있다" 1 \
      "$(py "int('거래처 × 입고 장소 × 담당자로 나뉩니다' in (d['loc_help'] or ''))")"
    expect "담은 줄 표에 「담당자」 열을 새로 만들지 않았다" 0 \
      "$(py "sum(1 for h in d['line_headers'] if h == '담당자')")"
    expect "불러오기 트리에 전표 노드 2개 (F62UIA)" 2 "$(g nodes_a)"
    expect "담은 줄 2개" 2 "$(g rows_a)"
    expect "미리보기가 「거래처×담당자별 2장」" 1 \
      "$(py "int('거래처×담당자별 2장' in (d['preview_mgr'] or ''))")"
    expect "머리 담당자를 고르면 「· 1장」 (명시 우선)" 1 \
      "$(py "int('1장' in (d['preview_head'] or '') and '별 2장' not in (d['preview_head'] or ''))")"
    expect "저장 알림에 「발주서 2장을 만들었습니다」" 1 \
      "$(py "int('발주서 2장을 만들었습니다' in (d['msg'] or ''))")"
    expect "알림에 전표 번호 2개" 2 \
      "$(py "len(re.findall(r'PO-[0-9]{8}-[0-9]+', d['msg'] or ''))")"
    expect "담당자가 하나뿐이면 F-61 문구 그대로 「거래처×입고장소별 2장」" 1 \
      "$(py "int((d['preview_same_mgr'] or '').startswith('저장하면 거래처×입고장소별 2장: '))")"
    expect "그 문구에 담당자 축이 없다" 0 \
      "$(py "int('담당자' in (d['preview_same_mgr'] or ''))")"
    expect "콘솔 오류 0건" 0 "$(py "len(d['console_errors'])")"
    echo "  스크린샷: $OUT/shots"
  fi
fi
[ "$SCREEN_OK" = "1" ] || echo "  (j) 결과: 실행하지 못함 — 보고서에 SKIP 으로 적을 것"

sub "화면이 저장한 두 장의 담당자가 서로 다르다 (DB 확인)"
expect "F62UIA 두 줄이 서로 다른 담당자의 발주서로 갔다" 2 \
  "$(psqlq "SELECT count(DISTINCT d.manager_id) FROM p_docs d JOIN p_doc_lines l ON l.doc_id = d.id
             WHERE d.doc_type='po' AND l.src_line_id IN ($LU1,$LU2);")"

# ═════════════════════════════════════════════════════════════
hr "T-74 (k) 정적 점검 — php -l · 린트 · 화면 금지 패턴"
# ═════════════════════════════════════════════════════════════

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
for f in "$ROOT/src/handlers/purchase_po_create.php" "$ROOT/src/handlers/purchase_managers_list.php" \
         "$ROOT/src/views/purchase_po.php" "$ROOT/public/index.php"; do
  NAME=$(basename "$f")
  php -l "$f" > /dev/null 2>&1
  RC=$?
  expect "php -l $NAME" 0 "$RC"
done

JSBAD=$(grep -cE 'innerHTML|outerHTML|document\.write\(' "$ROOT/public/assets/purchase_docs.js"); JSBAD=${JSBAD:-0}
expect "purchase_docs.js 에 innerHTML·document.write 0건" 0 "$JSBAD"
expect "담당자 목록 SQL 이 Doc::managerOk 와 같은 조건이다" 1 \
  "$(grep -c 'is_active AND purchase_role IS NOT NULL' "$ROOT/src/handlers/purchase_managers_list.php")"
expect "담당자 목록에 purchase_role **값** 비교가 없다" 0 \
  "$(grep -cE "purchase_role[^A-Za-z_]*(=|==|===)[^=]*'(requester|buyer|stock|viewer)'" \
      "$ROOT/src/handlers/purchase_managers_list.php")"
expect "담당자 목록이 **바인딩 없는 고정 문장**이다 (문자열 결합 0)" 1 \
  "$(grep -cF 'run($sqlManagers, [])' "$ROOT/src/handlers/purchase_managers_list.php")"
expect "담당자 목록이 감사 문장을 부르지 않는다" 0 \
  "$(grep -cE 'Audit::(allow|deny)' "$ROOT/src/handlers/purchase_managers_list.php")"
expect "Doc.php 에 새 함수를 만들지 않았다 (managerOk 하나)" 1 \
  "$(grep -c 'function managerOk' "$ROOT/src/purchase/Doc.php")"
expect "묶음 열쇠가 세 축이다" 1 \
  "$(grep -cF "':' . (string) \$p['manager_id']" "$ROOT/src/handlers/purchase_po_create.php")"

sub "린트"
if bash "$ROOT/scripts/security_lint.sh" > "$OUT/lint.txt" 2>&1; then
  expect "security_lint.sh 0건" 0 0
else
  expect "security_lint.sh 0건" 0 1
fi
tail -6 "$OUT/lint.txt" | sed 's/^/  /'

sub "전표 번호 중복 0건"
expect "중복 전표 번호 0건" 0 \
  "$(psqlq "SELECT count(*) FROM (SELECT doc_no FROM p_docs GROUP BY doc_no HAVING count(*) > 1) x;")"
psqlq "SELECT '  '||doc_type||' '||doc_no||' vendor='||coalesce(vendor_id::text,'-')
             ||' loc='||coalesce(to_location_id::text,'-')||' mgr='||manager_id
         FROM p_docs WHERE created_by IN ($QA_IDS) ORDER BY id;"
