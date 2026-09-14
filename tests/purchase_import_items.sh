#!/usr/bin/env bash
# tests/purchase_import_items.sh — 19단계 9차 보정 수락 기준 (i)~(vii)
#   이카운트 품목 파일(ESA009M 15열) 자동 가져오기 — 분류·거래처 함께 등록 · 빈 단위 허용 ·
#   매핑 + 옵션 기억 · 자동 적용 (PRD F-29 · 12.2 T-44 보충, ARCH 5.18, SECURITY R-67 · R-72 · R-73)
#
# 규칙 (CLAUDE.md 3장 · SECURITY R-73)
#   · **검토 계정(qa_ 접두)만** 쓴다. 시드 계정(admin01 …)은 사용자의 자기 공격 전용이다.
#   · 모든 요청에 `-A "tbox-review/<날짜>"` 를 붙인다.
#   · 시작 전 **최근 10분 비-qa 감사 활동**이 있으면 아무것도 만들지 않고 **종료 코드 3**.
#   · 픽스처는 전부 접두 `S19I-` 이고, 정리는 **created_by 가 qa 계정이면서 이름·코드가
#     `S19I-` 로 시작하는 행**만 지운다. 표 전체 DELETE·TRUNCATE 는 없다.
#   · 사용자의 실자료(files #609 등)는 **올리지도 확정하지도 않는다** — 같은 열 구조의 픽스처를 쓴다.
#
# 준비물: sudo(로 /etc/tbox-v2 읽기 · psql 실행), php(TOTP 계산·xlsx 생성), curl
# 사용: sudo -v && bash tests/purchase_import_items.sh
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

expect() {
  if [ "$2" = "$3" ]; then
    printf '  ✔ %s  (기대=%s 실제=%s)\n' "$1" "$2" "$3"; PASS=$((PASS+1))
  else
    printf '  ✖ %s  (기대=%s 실제=%s)\n' "$1" "$2" "$3"; FAIL=$((FAIL+1))
  fi
}

# ── 로그인 (stage19 과 같은 코드 — TOTP 는 1회성이라 실패하면 다음 창을 기다린다) ──
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
    printf '  · %s 코드 재사용으로 거부됨 — 다음 창까지 %s초 기다림 (R-45 가 의도대로 막은 것)\n' "$who" "$left" >&2
    sleep $((left + 1))
  done
  printf '  ! %s 로그인 실패: %s\n' "$who" "$out" >&2
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

upload() {   # $1 파일 경로 → "HTTP코드<TAB>본문"
  curl -s -w '\n%{http_code}' -b "$JAR_DIR/qa_admin01.jar" -A "$UA" \
    -H "X-CSRF-Token: $TOK" -F "file=@$1" "$BASE/api/purchase/import"
}

code_of() { printf '%s' "$1" | tail -1; }
body_of() { printf '%s' "$1" | sed '$d'; }
new_id()  { printf '%s' "$1" | sed -n 's/.*"id":\([0-9]*\).*/\1/p'; }
num_of()  { printf '%s' "$1" | sed -n "s/.*\"$2\":\([0-9]*\).*/\1/p"; }

# ─────────────────────────────────────────────────────────────
# 표 인구조사 — 시험 전후로 **비-qa 행 수**가 같아야 한다 (2026-09-13 사고 뒤 필수)
# ─────────────────────────────────────────────────────────────
QA_IDS=""
CENSUS_BEFORE=""
CENSUS_SUM=0
census() {
  local when="$1"
  printf '  %-18s %8s %10s\n' "표" "전체" "비-qa"
  local t total nonqa sum=0
  for t in p_vendors p_categories p_items p_import_mappings; do
    total=$(psqlq "SELECT count(*) FROM $t;")
    nonqa=$(psqlq "SELECT count(*) FROM $t WHERE created_by NOT IN ($QA_IDS);")
    printf '  %-18s %8s %10s\n' "$t" "$total" "$nonqa"
    sum=$((sum + nonqa))
  done
  printf '  %-18s %8s %10s   ← 사용자(비-qa)가 만든 행 합계 (%s)\n' "합계" "-" "$sum" "$when"
  CENSUS_SUM="$sum"
}

cleanup() {
  hr "정리 — **시험이 만든 것만** 되돌린다 (qa 가 만들었고 이름·코드가 S19I- 인 행)"
  local qa_ids
  qa_ids=$(psqlq "SELECT string_agg(id::text, ',') FROM users WHERE username LIKE 'qa\_%';")
  if [ -z "$qa_ids" ]; then
    echo "  ! 검토 계정을 찾지 못해 정리를 건너뜁니다 (아무것도 지우지 않았습니다)"
    rm -rf "$JAR_DIR"
    return
  fi
  echo "  검토 계정 id: $qa_ids — 이 계정이 만든 S19I- 행만 지웁니다"

  psqlq "SELECT '  사용자(비-qa)가 만든 행: p_vendors='||
           (SELECT count(*) FROM p_vendors    WHERE created_by NOT IN ($qa_ids))||
         ' p_categories='||(SELECT count(*) FROM p_categories WHERE created_by NOT IN ($qa_ids))||
         ' p_items='||(SELECT count(*) FROM p_items           WHERE created_by NOT IN ($qa_ids))||
         ' — 이 행들은 그대로 둡니다';"

  # 참조가 있는 것부터. 분류는 3단 → 2단 → 1단 순서여야 부모 참조가 남지 않는다.
  psqlc "DELETE FROM p_item_branches WHERE item_id IN
             (SELECT id FROM p_items WHERE created_by IN ($qa_ids) AND code LIKE 'S19I-%');
         DELETE FROM p_items          WHERE created_by IN ($qa_ids) AND code LIKE 'S19I-%';
         DELETE FROM p_import_mappings WHERE created_by IN ($qa_ids) AND name LIKE 'S19I-%';
         DELETE FROM p_categories     WHERE created_by IN ($qa_ids) AND name LIKE 'S19I-%' AND level = 3;
         DELETE FROM p_categories     WHERE created_by IN ($qa_ids) AND name LIKE 'S19I-%' AND level = 2;
         DELETE FROM p_categories     WHERE created_by IN ($qa_ids) AND name LIKE 'S19I-%' AND level = 1;
         DELETE FROM p_vendors        WHERE created_by IN ($qa_ids) AND name LIKE 'S19I-%';" \
    && echo "  검토 계정이 만든 S19I- 시험 데이터 삭제"

  psqlq "SELECT '  남은 S19I- 행: p_items='||(SELECT count(*) FROM p_items WHERE code LIKE 'S19I-%')||
         ' p_vendors='||(SELECT count(*) FROM p_vendors WHERE name LIKE 'S19I-%')||
         ' p_categories='||(SELECT count(*) FROM p_categories WHERE name LIKE 'S19I-%');"

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
# 관문 — SECURITY R-73 ② (19~24단계 스크립트와 같은 코드)
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

hr "0. 시작 전 인구조사"
QA_IDS=$(psqlq "SELECT coalesce(string_agg(id::text, ','), '0') FROM users WHERE username LIKE 'qa\_%';")
echo "  검토 계정 id: $QA_IDS"
census before
CENSUS_BEFORE="$CENSUS_SUM"
echo "  ★ 이 시험의 정리는 위 「비-qa」 행을 **한 줄도 지우지 않습니다** (created_by + S19I- 필터)."

hr "0-1. 로그인·재인증 (qa_admin01)"
TOK=$(login qa_admin01) || exit 1
echo "  토큰 길이 ${#TOK} · 재인증 $(reauth qa_admin01 "$TOK")"

hr "0-2. 씨앗 거래처 — 정상 1 · 동명 2 (R-72 확인용)"
R=$(call qa_admin01 POST /api/purchase/vendors "$TOK" '{"name":"S19I-더존덴탈","code":"S19I-V001"}')
VEND_MAIN=$(new_id "$(body_of "$R")"); expect "거래처 생성" 201 "$(code_of "$R")"
R=$(call qa_admin01 POST /api/purchase/vendors "$TOK" '{"name":"S19I-동명거래처","code":"S19I-V002"}')
expect "동명 거래처 ① 생성" 201 "$(code_of "$R")"
R=$(call qa_admin01 POST /api/purchase/vendors "$TOK" \
    '{"name":"S19I-동명거래처","code":"S19I-V003","allow_duplicate_name":true}')
expect "동명 거래처 ② 생성 (이름 중복 허용)" 201 "$(code_of "$R")"
psqlq "SELECT '  같은 이름의 거래처 수: '||count(*) FROM p_vendors WHERE name = 'S19I-동명거래처';"

# ─────────────────────────────────────────────────────────────
hr "0-3. 픽스처 xlsx 만들기 — 이카운트 ESA009M 의 **15열 그대로** (제목행 0 · 머리행 1)"
# ─────────────────────────────────────────────────────────────
WORK="$JAR_DIR/xlsx"
mkdir -p "$WORK"

# 행 배열(JSON)을 받아 최소 구성의 xlsx 를 만드는 도구. 시험 자료이며 앱 코드가 아니다.
cat > "$WORK/mkxlsx.php" <<'PHPTOOL'
<?php
// argv[1] = 행 배열 JSON 경로, argv[2] = 만들 xlsx 경로
$rows = json_decode((string) file_get_contents($argv[1]), true);
$esc  = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_XML1, 'UTF-8');
$sheet = '<?xml version="1.0" encoding="UTF-8"?>'
       . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
foreach ($rows as $r => $row) {
    $sheet .= '<row r="' . ($r + 1) . '">';
    foreach ($row as $c => $cell) {
        // 열 이름 A..Z, AA.. (15열이라 A..O 로 충분하지만 일반화해 둔다)
        $name = '';
        for ($n = $c; ; $n = intdiv($n, 26) - 1) { $name = chr(65 + $n % 26) . $name; if ($n < 26) break; }
        $sheet .= '<c r="' . $name . ($r + 1) . '" t="inlineStr"><is><t>' . $esc((string) $cell) . '</t></is></c>';
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
@unlink($argv[2]);
$zip = new ZipArchive();
$zip->open($argv[2], ZipArchive::CREATE);
$zip->addFromString('[Content_Types].xml', $ct);
$zip->addFromString('_rels/.rels', $rels);
$zip->addFromString('xl/workbook.xml', $wb);
$zip->addFromString('xl/_rels/workbook.xml.rels', $wbRels);
$zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
$zip->close();
echo '  만든 파일: ', basename($argv[2]), ' ', filesize($argv[2]), " 바이트 (", count($rows), "행)\n";
PHPTOOL

# 0행 제목 · 1행 머리행(15열) · 2행~ 데이터 12행 (엑셀에서 사람이 보는 줄 번호는 3~14)
cat > "$WORK/items.json" <<'JSONEOF'
[
 ["회사명 : 주식회사 플란랩스"],
 ["구매처명","대분류","중분류","소분류","품목코드","품목명","규격","단위","입고단가",
  "당수량(분자)","당수량(분모)","최초작성자","최종수정자","문자형추가항목2","최종수정일자"],

 ["S19I-더존덴탈","","","","S19I-N0001","S19I-NORMAL 1","10mm","EA","1001","1","1","홍길동","홍길동","","2026-09-14"],
 ["S19I-더존덴탈","","","","S19I-N0002","S19I-NORMAL 2","20mm","EA","1002","1","1","홍길동","홍길동","","2026-09-14"],
 ["S19I-더존덴탈","","","","S19I-N0003","S19I-NORMAL 3","30mm","BOX","1003","1","1","홍길동","홍길동","","2026-09-14"],

 ["S19I-더존덴탈","","","","S19I-U0001","S19I-NOUNIT 1","5mm","","2001","1","1","홍길동","홍길동","","2026-09-14"],
 ["S19I-더존덴탈","","","","S19I-U0002","S19I-NOUNIT 2","6mm","","2002","1","1","홍길동","홍길동","","2026-09-14"],

 ["S19I-더존덴탈","S19I-소모품","S19I-거즈","S19I-2x2","S19I-C0001","S19I-CAT 1","2x2","EA","3001","1","1","홍길동","홍길동","","2026-09-14"],
 ["S19I-더존덴탈","S19I-소모품","S19I-거즈","S19I-3x3","S19I-C0002","S19I-CAT 2","3x3","EA","3002","1","1","홍길동","홍길동","","2026-09-14"],

 ["S19I-새거래처","","","","S19I-V0001","S19I-NEWVENDOR 1","1mm","EA","4001","1","1","홍길동","홍길동","","2026-09-14"],

 ["","","","","S19I-B0001","S19I-NOVENDOR 1","1mm","EA","5001","1","1","홍길동","홍길동","","2026-09-14"],

 ["S19I-동명거래처","","","","S19I-A0001","S19I-AMBIGUOUS 1","1mm","EA","6001","1","1","홍길동","홍길동","","2026-09-14"],

 ["S19I-더존덴탈","","","","S19I-N0001","S19I-DUPLICATE 1","10mm","EA","1001","1","1","홍길동","홍길동","","2026-09-14"],

 ["S19I-더존덴탈","","","","S19I-P0001","S19I-PACKDEN 1","1mm","EA","7001","10","2","홍길동","홍길동","","2026-09-14"]
]
JSONEOF
php "$WORK/mkxlsx.php" "$WORK/items.json" "$WORK/items.xlsx"
echo "  구성: 정상 3 · 단위 빈칸 2 · 새 분류 3단 2(같은 대분류 공유) · 새 거래처 1 ·"
echo "        거래처 빈칸 1 · 동명 거래처 1 · 파일 내 중복 1 · 판정용 review 1 = 12행"

MAPPING='{"구매처명":"vendor","대분류":"cat1","중분류":"cat2","소분류":"cat3","품목코드":"code","품목명":"name","규격":"spec","단위":"unit","입고단가":"price_in","당수량(분자)":"pack_qty","당수량(분모)":"pack_den","최초작성자":"skip","최종수정자":"skip","문자형추가항목2":"skip","최종수정일자":"skip"}'

hr "업로드 — 15열 픽스처"
R=$(upload "$WORK/items.xlsx")
echo "  HTTP $(code_of "$R")"
echo "  $(body_of "$R" | head -c 600)"
expect "업로드 201" 201 "$(code_of "$R")"
IMPORT_ID=$(body_of "$R" | sed -n 's/.*"import_id":"\([0-9a-f-]*\)".*/\1/p')
expect "머리행 자동 감지 1" 1 "$(body_of "$R" | grep -o '"header_row":[0-9]*' | head -1 | cut -d: -f2)"
expect "데이터 행 12" 12 "$(body_of "$R" | sed -n 's/.*"rows":\([0-9]*\).*/\1/p')"
expect "저장된 매핑이 없으니 match 없음" null "$(body_of "$R" | sed -n 's/.*"match":\([a-z0-9]*\).*/\1/p')"

# ─────────────────────────────────────────────────────────────
hr "(i) 옵션 **없이** 미리보기 — 분류·거래처는 오류, 단위 빈칸은 오류가 아니다"
# ─────────────────────────────────────────────────────────────
R=$(call qa_admin01 POST "/api/purchase/import/$IMPORT_ID/preview" "$TOK" \
    "{\"kind\":\"items\",\"mapping\":$MAPPING,\"header_row\":1}")
BODY_I=$(body_of "$R")
echo "  HTTP $(code_of "$R")"
echo "  counts: $(printf '%s' "$BODY_I" | sed -n 's/.*"counts":{\([^}]*\)}.*/\1/p')"
echo "  본문(앞 900자): $(printf '%s' "$BODY_I" | head -c 900)"
expect "미리보기 200" 200 "$(code_of "$R")"
expect "insert 5 (정상 3 + 단위 빈칸 2)" 5 "$(num_of "$BODY_I" insert)"
expect "review 2 (동명 거래처 · 분모 2)"  2 "$(num_of "$BODY_I" review)"
expect "error 5 (분류 2 · 거래처 미등록 1 · 거래처 빈칸 1 · 중복 1)" 5 "$(num_of "$BODY_I" error)"
expect "사유 category_not_found 가 있다" 1 "$(printf '%s' "$BODY_I" | grep -c 'category_not_found')"
expect "사유 vendor_not_found 가 있다"   1 "$(printf '%s' "$BODY_I" | grep -c '"vendor_not_found"')"
expect "사유 vendor_missing 이 있다"     1 "$(printf '%s' "$BODY_I" | grep -c 'vendor_missing')"
expect "사유 vendor_ambiguous 가 있다"   1 "$(printf '%s' "$BODY_I" | grep -c 'vendor_ambiguous')"
expect "사유 duplicate_in_file 이 있다"  1 "$(printf '%s' "$BODY_I" | grep -c 'duplicate_in_file')"
expect "**unit_missing 은 어디에도 없다**" 0 "$(printf '%s' "$BODY_I" | grep -c 'unit_missing')"
sub "단위 빈칸 두 행(6·7행)의 판정 — 오류가 아니라 등록이다"
printf '%s' "$BODY_I" | tr ',' '\n' | grep -n 'NOUNIT' | head -4
expect "옵션 없으면 새로 만들 분류 0" 0 "$(num_of "$BODY_I" new_categories)"
expect "옵션 없으면 새로 만들 거래처 0" 0 "$(num_of "$BODY_I" new_vendors)"

# ─────────────────────────────────────────────────────────────
hr "(ii) 옵션 **켜고** 미리보기 — 오류는 거래처 빈칸 1 · 파일 내 중복 1 뿐"
# ─────────────────────────────────────────────────────────────
OPTS='"create_categories":true,"create_vendors":true'
R=$(call qa_admin01 POST "/api/purchase/import/$IMPORT_ID/preview" "$TOK" \
    "{\"kind\":\"items\",\"mapping\":$MAPPING,\"header_row\":1,$OPTS}")
BODY_II=$(body_of "$R")
echo "  HTTP $(code_of "$R")"
echo "  counts: $(printf '%s' "$BODY_II" | sed -n 's/.*"counts":{\([^}]*\)}.*/\1/p')"
echo "  to_create: $(printf '%s' "$BODY_II" | sed -n 's/.*\("to_create":.*\)/\1/p' | head -c 700)"
expect "미리보기 200" 200 "$(code_of "$R")"
expect "insert 8 (정상 3 · 빈 단위 2 · 새 분류 2 · 새 거래처 1)" 8 "$(num_of "$BODY_II" insert)"
expect "review 2 (동명 거래처 · 분모 2 — 그대로)" 2 "$(num_of "$BODY_II" review)"
expect "error 2 (거래처 빈칸 · 파일 내 중복)"     2 "$(num_of "$BODY_II" error)"
expect "new_categories 4 (대1 · 중1 · 소2)" 4 "$(num_of "$BODY_II" new_categories)"
expect "new_vendors 1"                      1 "$(num_of "$BODY_II" new_vendors)"
expect "동명 거래처 행은 여전히 vendor_ambiguous" 1 "$(printf '%s' "$BODY_II" | grep -c 'vendor_ambiguous')"
expect "category_not_found 는 사라졌다" 0 "$(printf '%s' "$BODY_II" | grep -c 'category_not_found')"
expect "vendor_not_found 도 사라졌다"   0 "$(printf '%s' "$BODY_II" | grep -c '"vendor_not_found"')"
expect "거래처 빈칸은 여전히 vendor_missing" 1 "$(printf '%s' "$BODY_II" | grep -c 'vendor_missing')"
expect "행 표시 flags.category_new 가 있다" 1 "$(printf '%s' "$BODY_II" | grep -c 'category_new')"
expect "행 표시 flags.vendor_new 가 있다"   1 "$(printf '%s' "$BODY_II" | grep -c 'vendor_new')"

# ─────────────────────────────────────────────────────────────
hr "(iii) 같은 옵션으로 **확정** — 분류·거래처가 같은 트랜잭션에서 먼저 생긴다"
# ─────────────────────────────────────────────────────────────
CAT_BEFORE=$(psqlq "SELECT count(*) FROM p_categories;")
VEN_BEFORE=$(psqlq "SELECT count(*) FROM p_vendors;")
ITEM_BEFORE=$(psqlq "SELECT count(*) FROM p_items;")
R=$(call qa_admin01 POST "/api/purchase/import/$IMPORT_ID/commit" "$TOK" \
    "{\"kind\":\"items\",\"mapping\":$MAPPING,\"header_row\":1,$OPTS}")
BODY_III=$(body_of "$R")
echo "  HTTP $(code_of "$R")  $BODY_III"
expect "확정 200" 200 "$(code_of "$R")"
expect "inserted 8" 8 "$(num_of "$BODY_III" inserted)"
expect "categories_created 4" 4 "$(num_of "$BODY_III" categories_created)"
expect "vendors_created 1"    1 "$(num_of "$BODY_III" vendors_created)"
expect "p_categories 가 4 늘었다" 4 "$(($(psqlq "SELECT count(*) FROM p_categories;") - CAT_BEFORE))"
expect "p_vendors 가 1 늘었다"    1 "$(($(psqlq "SELECT count(*) FROM p_vendors;")    - VEN_BEFORE))"
expect "p_items 가 8 늘었다"      8 "$(($(psqlq "SELECT count(*) FROM p_items;")      - ITEM_BEFORE))"

sub "분류 3단의 부모 연결 (level · parent)"
psqlq "SELECT '  '||c.level||'단 '||c.name||' ← 상위 '||coalesce(p.name,'(없음)')
         FROM p_categories c LEFT JOIN p_categories p ON p.id = c.parent_id
        WHERE c.name LIKE 'S19I-%' ORDER BY c.level, c.name;"
expect "1단 S19I-소모품 은 부모가 없다" 1 "$(psqlq "SELECT count(*) FROM p_categories WHERE name='S19I-소모품' AND level=1 AND parent_id IS NULL;")"
expect "2단 S19I-거즈 의 부모는 S19I-소모품" 1 "$(psqlq "SELECT count(*) FROM p_categories c JOIN p_categories p ON p.id=c.parent_id WHERE c.name='S19I-거즈' AND c.level=2 AND p.name='S19I-소모품';")"
expect "3단 두 개의 부모는 S19I-거즈" 2 "$(psqlq "SELECT count(*) FROM p_categories c JOIN p_categories p ON p.id=c.parent_id WHERE c.level=3 AND p.name='S19I-거즈' AND c.name IN ('S19I-2x2','S19I-3x3');")"

sub "새 거래처는 **이름만**(코드 NULL)"
psqlq "SELECT '  '||name||' code='||coalesce(code,'(NULL)') FROM p_vendors WHERE name='S19I-새거래처';"
expect "S19I-새거래처 의 code 가 NULL" 1 "$(psqlq "SELECT count(*) FROM p_vendors WHERE name='S19I-새거래처' AND code IS NULL;")"

sub "빈 단위 행은 unit IS NULL 로 저장됐다 (원본과 같게)"
psqlq "SELECT '  '||code||' unit='||coalesce(unit,'(NULL)') FROM p_items WHERE code LIKE 'S19I-U%' ORDER BY code;"
expect "S19I-U0001·U0002 의 unit 이 NULL" 2 "$(psqlq "SELECT count(*) FROM p_items WHERE code IN ('S19I-U0001','S19I-U0002') AND unit IS NULL;")"
expect "단위가 있는 행은 그대로 들어갔다" 1 "$(psqlq "SELECT count(*) FROM p_items WHERE code='S19I-N0003' AND unit='BOX';")"
expect "분류 행이 3단까지 이어졌다" 1 "$(psqlq "SELECT count(*) FROM p_items i JOIN p_categories c ON c.id=i.cat3_id WHERE i.code='S19I-C0001' AND c.name='S19I-2x2';")"
expect "새 거래처의 품목이 그 거래처에 붙었다" 1 "$(psqlq "SELECT count(*) FROM p_items i JOIN p_vendors v ON v.id=i.vendor_id WHERE i.code='S19I-V0001' AND v.name='S19I-새거래처';")"
expect "오류·확인 행은 저장되지 않았다" 0 "$(psqlq "SELECT count(*) FROM p_items WHERE code IN ('S19I-B0001','S19I-A0001','S19I-P0001');")"

sub "감사 — purchase.import.commit allow 의 detail"
psqlq "SELECT '  '||action||' '||result||' '||detail::text
         FROM audit_logs WHERE action='purchase.import.commit' AND result='allow'
        ORDER BY id DESC LIMIT 1;"
expect "detail 에 categories_created 가 있다" 1 "$(psqlq "SELECT count(*) FROM audit_logs WHERE action='purchase.import.commit' AND result='allow' AND detail ? 'categories_created' AND id=(SELECT max(id) FROM audit_logs WHERE action='purchase.import.commit' AND result='allow');")"
expect "detail 에 vendors_created 가 있다"    1 "$(psqlq "SELECT count(*) FROM audit_logs WHERE action='purchase.import.commit' AND result='allow' AND detail ? 'vendors_created' AND id=(SELECT max(id) FROM audit_logs WHERE action='purchase.import.commit' AND result='allow');")"
expect "detail 의 categories_created 값이 4"  4 "$(psqlq "SELECT (detail->>'categories_created')::int FROM audit_logs WHERE action='purchase.import.commit' AND result='allow' ORDER BY id DESC LIMIT 1;")"

# ─────────────────────────────────────────────────────────────
hr "(iv) 같은 파일 **재확정** — 멱등 (아무것도 늘지 않는다)"
# ─────────────────────────────────────────────────────────────
CAT_BEFORE=$(psqlq "SELECT count(*) FROM p_categories;")
VEN_BEFORE=$(psqlq "SELECT count(*) FROM p_vendors;")
ITEM_BEFORE=$(psqlq "SELECT count(*) FROM p_items;")
R=$(call qa_admin01 POST "/api/purchase/import/$IMPORT_ID/commit" "$TOK" \
    "{\"kind\":\"items\",\"mapping\":$MAPPING,\"header_row\":1,$OPTS}")
BODY_IV=$(body_of "$R")
echo "  HTTP $(code_of "$R")  $BODY_IV"
expect "재확정 200" 200 "$(code_of "$R")"
expect "inserted 0"           0 "$(num_of "$BODY_IV" inserted)"
expect "updated 0"            0 "$(num_of "$BODY_IV" updated)"
expect "categories_created 0" 0 "$(num_of "$BODY_IV" categories_created)"
expect "vendors_created 0"    0 "$(num_of "$BODY_IV" vendors_created)"
expect "p_categories 변동 0" 0 "$(($(psqlq "SELECT count(*) FROM p_categories;") - CAT_BEFORE))"
expect "p_vendors 변동 0"    0 "$(($(psqlq "SELECT count(*) FROM p_vendors;")    - VEN_BEFORE))"
expect "p_items 변동 0"      0 "$(($(psqlq "SELECT count(*) FROM p_items;")      - ITEM_BEFORE))"

# ─────────────────────────────────────────────────────────────
hr "(v) 확정 중 실패 — 분류·거래처도 **0건** (부분 저장 없음)"
# ─────────────────────────────────────────────────────────────
# 실패를 확실히 일으키는 자리: **중단된(is_active=false) 1단 분류와 같은 이름**.
# 판정 lookup 은 활성 분류만 읽으므로 「없다」고 보고 새로 만들려 하지만,
# p_categories_root_name_uidx 는 활성 여부를 보지 않아 INSERT 가 23505 로 터진다.
# 그때 **그 앞에서 만든 새 거래처까지** 되돌아가야 한다 — 그것이 이 시험의 핵심이다.
R=$(call qa_admin01 POST /api/purchase/categories "$TOK" '{"name":"S19I-충돌분류","level":1}')
CONFLICT_CAT=$(new_id "$(body_of "$R")"); expect "충돌용 1단 분류 생성" 201 "$(code_of "$R")"
R=$(call qa_admin01 PATCH "/api/purchase/categories/$CONFLICT_CAT" "$TOK" '{"is_active":false}')
expect "그 분류를 중단(is_active=false)" 200 "$(code_of "$R")"

cat > "$WORK/conflict.json" <<'JSONEOF'
[
 ["회사명 : 주식회사 플란랩스"],
 ["구매처명","대분류","중분류","소분류","품목코드","품목명","규격","단위","입고단가",
  "당수량(분자)","당수량(분모)","최초작성자","최종수정자","문자형추가항목2","최종수정일자"],
 ["S19I-충돌거래처","S19I-충돌분류","","","S19I-X0001","S19I-CONFLICT 1","1mm","EA","8001","1","1","홍","홍","","2026-09-14"],
 ["S19I-더존덴탈","","","","S19I-X0002","S19I-CONFLICT 2","1mm","EA","8002","1","1","홍","홍","","2026-09-14"]
]
JSONEOF
php "$WORK/mkxlsx.php" "$WORK/conflict.json" "$WORK/conflict.xlsx"
R=$(upload "$WORK/conflict.xlsx")
expect "충돌 픽스처 업로드 201" 201 "$(code_of "$R")"
CONFLICT_ID=$(body_of "$R" | sed -n 's/.*"import_id":"\([0-9a-f-]*\)".*/\1/p')

R=$(call qa_admin01 POST "/api/purchase/import/$CONFLICT_ID/preview" "$TOK" \
    "{\"kind\":\"items\",\"mapping\":$MAPPING,\"header_row\":1,$OPTS}")
echo "  미리보기 counts: $(body_of "$R" | sed -n 's/.*"counts":{\([^}]*\)}.*/\1/p')"
expect "미리보기에서는 둘 다 등록으로 보인다" 2 "$(num_of "$(body_of "$R")" insert)"

CAT_BEFORE=$(psqlq "SELECT count(*) FROM p_categories;")
VEN_BEFORE=$(psqlq "SELECT count(*) FROM p_vendors;")
ITEM_BEFORE=$(psqlq "SELECT count(*) FROM p_items;")
R=$(call qa_admin01 POST "/api/purchase/import/$CONFLICT_ID/commit" "$TOK" \
    "{\"kind\":\"items\",\"mapping\":$MAPPING,\"header_row\":1,$OPTS}")
echo "  확정 HTTP $(code_of "$R")  $(body_of "$R")"
expect "확정이 거부됐다 (409 conflict)" 409 "$(code_of "$R")"
expect "p_categories 변동 0 (부분 저장 없음)" 0 "$(($(psqlq "SELECT count(*) FROM p_categories;") - CAT_BEFORE))"
expect "p_vendors 변동 0 — **앞서 만든 새 거래처까지 되돌아갔다**" 0 "$(($(psqlq "SELECT count(*) FROM p_vendors;") - VEN_BEFORE))"
expect "p_items 변동 0" 0 "$(($(psqlq "SELECT count(*) FROM p_items;") - ITEM_BEFORE))"
expect "S19I-충돌거래처 는 만들어지지 않았다" 0 "$(psqlq "SELECT count(*) FROM p_vendors WHERE name='S19I-충돌거래처';")"
expect "S19I-X 품목도 없다" 0 "$(psqlq "SELECT count(*) FROM p_items WHERE code LIKE 'S19I-X%';")"
psqlq "SELECT '  감사: '||action||' '||result||' '||coalesce(reason,'-')||' '||detail::text
         FROM audit_logs WHERE action='purchase.import.commit' AND result='deny'
        ORDER BY id DESC LIMIT 1;"

# ─────────────────────────────────────────────────────────────
hr "(vi) 매핑 + 옵션 저장 → 다시 올리면 자동 적용 후보(match)로 돌아온다"
# ─────────────────────────────────────────────────────────────
R=$(call qa_admin01 POST /api/purchase/import/mappings "$TOK" \
    "{\"kind\":\"items\",\"name\":\"S19I-이카운트 ESA009M\",\"mapping\":$MAPPING,\"header_row\":1,\"options\":{$OPTS}}")
BODY_VI=$(body_of "$R")
echo "  HTTP $(code_of "$R")  $BODY_VI"
expect "매핑+옵션 저장 201" 201 "$(code_of "$R")"
MAP_ID=$(printf '%s' "$BODY_VI" | sed -n 's/.*"id":\([0-9]*\).*/\1/p')
expect "응답에 options 가 있다" 1 "$(printf '%s' "$BODY_VI" | grep -c '"options"')"
expect "options 에 create_categories:true" 1 "$(printf '%s' "$BODY_VI" | grep -c '"create_categories":true')"
expect "options 에 create_vendors:true"    1 "$(printf '%s' "$BODY_VI" | grep -c '"create_vendors":true')"
psqlq "SELECT '  저장된 행: '||name||' header_row='||header_row||' options='||options_json::text
         FROM p_import_mappings WHERE name='S19I-이카운트 ESA009M';"

sub "같은 파일을 다시 올리면 match = 방금 저장한 매핑"
R=$(upload "$WORK/items.xlsx")
BODY_VI2=$(body_of "$R")
echo "  HTTP $(code_of "$R")"
echo "  $(printf '%s' "$BODY_VI2" | sed -n 's/.*\("saved":.*\)/\1/p' | head -c 600)"
expect "재업로드 201" 201 "$(code_of "$R")"
expect "match = 저장한 매핑 id" "$MAP_ID" "$(printf '%s' "$BODY_VI2" | sed -n 's/.*"match":\([0-9a-z]*\).*/\1/p')"
expect "saved 목록에 options 가 실린다" 1 "$(printf '%s' "$BODY_VI2" | grep -c '"create_categories":true')"

sub "열 하나(단위)가 빠진 파일 → match 없음"
cat > "$WORK/short.json" <<'JSONEOF'
[
 ["회사명 : 주식회사 플란랩스"],
 ["구매처명","대분류","중분류","소분류","품목코드","품목명","규격","입고단가",
  "당수량(분자)","당수량(분모)","최초작성자","최종수정자","문자형추가항목2","최종수정일자"],
 ["S19I-더존덴탈","","","","S19I-S0001","S19I-SHORT 1","1mm","9001","1","1","홍","홍","","2026-09-14"]
]
JSONEOF
php "$WORK/mkxlsx.php" "$WORK/short.json" "$WORK/short.xlsx"
R=$(upload "$WORK/short.xlsx")
echo "  HTTP $(code_of "$R")  match=$(body_of "$R" | sed -n 's/.*"match":\([0-9a-z]*\).*/\1/p')"
expect "열이 빠지면 match 없음" null "$(body_of "$R" | sed -n 's/.*"match":\([0-9a-z]*\).*/\1/p')"

# ─────────────────────────────────────────────────────────────
hr "(vii) 옵션 타입 검사 · 종류가 다르면 무시"
# ─────────────────────────────────────────────────────────────
R=$(call qa_admin01 POST "/api/purchase/import/$IMPORT_ID/preview" "$TOK" \
    "{\"kind\":\"items\",\"mapping\":$MAPPING,\"header_row\":1,\"create_categories\":\"yes\"}")
echo "  미리보기 create_categories=\"yes\" → HTTP $(code_of "$R")  $(body_of "$R")"
expect "문자열 옵션은 400" 400 "$(code_of "$R")"
R=$(call qa_admin01 POST "/api/purchase/import/$IMPORT_ID/commit" "$TOK" \
    "{\"kind\":\"items\",\"mapping\":$MAPPING,\"header_row\":1,\"create_vendors\":1}")
echo "  확정 create_vendors=1 → HTTP $(code_of "$R")  $(body_of "$R")"
expect "숫자 옵션도 400 (확정도 같은 규칙)" 400 "$(code_of "$R")"
R=$(call qa_admin01 POST /api/purchase/import/mappings "$TOK" \
    "{\"kind\":\"items\",\"name\":\"S19I-나쁜옵션\",\"mapping\":$MAPPING,\"header_row\":1,\"options\":{\"create_vendors\":\"yes\"}}")
echo "  매핑 저장 options.create_vendors=\"yes\" → HTTP $(code_of "$R")"
expect "매핑 저장의 옵션 타입 오류도 400" 400 "$(code_of "$R")"
R=$(call qa_admin01 POST /api/purchase/import/mappings "$TOK" \
    "{\"kind\":\"items\",\"name\":\"S19I-모르는옵션\",\"mapping\":$MAPPING,\"header_row\":1,\"options\":{\"drop_table\":true}}")
expect "허용 목록 밖 옵션 키도 400" 400 "$(code_of "$R")"

sub "종류가 vendors 면 create_categories 는 **무시**되고 정상 판정"
VMAP='{"구매처명":"name"}'
R=$(call qa_admin01 POST "/api/purchase/import/$IMPORT_ID/preview" "$TOK" \
    "{\"kind\":\"vendors\",\"mapping\":$VMAP,\"header_row\":1,\"create_categories\":true,\"create_vendors\":true}")
BODY_VII=$(body_of "$R")
echo "  HTTP $(code_of "$R")"
echo "  counts: $(printf '%s' "$BODY_VII" | sed -n 's/.*"counts":{\([^}]*\)}.*/\1/p')"
expect "거래처 종류 미리보기 200" 200 "$(code_of "$R")"
expect "옵션이 무시돼 새 분류 0" 0 "$(num_of "$BODY_VII" new_categories)"
expect "옵션이 무시돼 새 거래처 0" 0 "$(num_of "$BODY_VII" new_vendors)"

# (viii) 실자료(files #609) 미리보기는 **검토자(Fable)의 몫**이다 — 이 스크립트는 하지 않는다.
sub "(viii) 실자료 미리보기는 이 스크립트가 하지 않는다 (지시서 — 검토자 몫)"
echo "  qa 계정으로 사용자 실자료를 올리거나 확정하지 않았습니다."

hr "결과"
printf '  통과 %d · 실패 %d\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ] || exit 1
