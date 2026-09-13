#!/usr/bin/env bash
# tests/purchase_stage24.sh — 24단계 수락 기준 실행
#   (PRD 12.2 T-49 + F-37 「브랜드·지점·창고」 한 페이지 · 창고 대기표 · 연결/제외
#    · ARCH 5.18 · SECURITY R-65 · R-73)
#
# 규칙 (CLAUDE.md 3장)
#   · **검토 계정(qa_ 접두)만** 쓴다. 시드 계정(admin01 …)은 사용자의 자기 공격 전용이다.
#   · 모든 요청에 `-A "tbox-review/<날짜>"` 를 붙인다 — 감사 로그에서 사람의 행동과 갈라 보려는 것.
#   · 비밀번호·TOTP 비밀은 **필요한 필드만 awk 로** 뽑는다. 파일을 통째로 출력(cat)하지 않고,
#     값은 변수에만 담아 화면·로그 어디에도 찍지 않는다.
#
# ★ 시험 안전 (SECURITY R-73). 19~23단계 스크립트와 **같은 규칙**이다.
#   ① **사용자가 활동 중이면 시작하지 않는다.** 최근 10분 감사 로그에 비-qa actor 가 하나라도
#      있으면 아무것도 하지 않고 **종료 코드 3** 으로 끝낸다.
#   ② **정리는 qa 계정이 만든 행만.** `created_by` 가 검토 계정인 행만 지운다.
#      표 전체 DELETE·TRUNCATE 는 이 파일에 한 줄도 없다.
#   ③ 시작과 끝에 **비-qa 행 수**를 찍고, 달라졌으면 실패로 센다(p_location_inbox 포함).
#   ④ 픽스처 이름은 전부 `S24-` 접두다 — 사용자의 실데이터와 이름이 겹쳐 409 가 나지 않게.
#
# ★ 실자료(사용자가 올린 ESA005M)는 **읽기만** 한다 — 복사해서 qa 로 올리지 않는다.
#   대신 같은 열 구조(창고코드·창고명·구분·생산공정명·외주거래처명·사용·추가사업장명)의
#   S24- 픽스처를 PHP ZipArchive 로 만들어 쓴다 (19단계 시험과 같은 방식).
#
# ★ 대기표·장소 행의 정리는 **postgres 로** 한다(앱 계정에는 DELETE 권한이 없다 — 그것이 설계다).
#   그래도 지우는 범위는 「qa 계정이 만든 것」뿐이다.
#
# 사용: bash tests/purchase_stage24.sh
set -u

BASE="http://127.0.0.1:8082"
UA="tbox-review/$(date +%F)"
DB="tbox_re"
JAR_DIR="$(mktemp -d)"
WORK="$(mktemp -d)"
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
  rm -rf "$JAR_DIR" "$WORK"
  exit 3
fi
echo "■ 최근 10분 비-qa 활동 0건 — 시험을 시작합니다."

# ─── 로그인·호출 (23단계와 같은 함수) ────────────────────────
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

upload() { # $1 who  $2 token  $3 파일
  curl -s -w '\n%{http_code}' -b "$JAR_DIR/$1.jar" -A "$UA" \
    -H "X-CSRF-Token: $2" -F "file=@$3" "$BASE/api/purchase/structure/upload"
}

code_of() { printf '%s' "$1" | tail -1; }
body_of() { printf '%s' "$1" | sed '$d'; }
new_id()  { printf '%s' "$1" | grep -o '"id":[0-9]*' | head -1 | cut -d: -f2; }

# JSON 을 **PHP 로 읽는다** — 대기표 응답은 `suggest` 가 중첩 객체라 sed 로는 읽을 수 없다.
# (새 도구를 들이지 않는다: php 는 이 저장소가 이미 쓰는 실행기다.)
jget() { # $1 본문  $2 점 경로 (없으면 "null", 배열이면 길이)
  printf '%s' "$1" | php -r '
    $d = json_decode(stream_get_contents(STDIN), true);
    $v = $d;
    foreach (explode(".", $argv[1]) as $k) {
      $v = is_array($v) && array_key_exists($k, $v) ? $v[$k] : null;
    }
    if ($v === true) { echo "true"; }
    elseif ($v === false) { echo "false"; }
    elseif ($v === null) { echo "null"; }
    elseif (is_array($v)) { echo count($v); }
    else { echo $v; }
  ' "$2"
}

inbox_field() { # $1 본문  $2 창고코드  $3 점 경로 (suggest.branch_id 처럼 중첩 가능)
  printf '%s' "$1" | php -r '
    $d = json_decode(stream_get_contents(STDIN), true);
    $rows = array_merge($d["unlinked"] ?? [], $d["excluded"] ?? []);
    foreach ($rows as $r) {
      if (($r["ecount_code"] ?? "") !== $argv[1]) { continue; }
      $v = $r;
      foreach (explode(".", $argv[2]) as $k) {
        $v = is_array($v) && array_key_exists($k, $v) ? $v[$k] : null;
      }
      if ($v === true) { echo "true"; }
      elseif ($v === false) { echo "false"; }
      elseif ($v === null) { echo "null"; }
      elseif (is_array($v)) { echo count($v); }
      else { echo $v; }
      exit;
    }
    echo "행없음";
  ' "$2" "$3"
}

inbox_id_of() { inbox_field "$1" "$2" "id"; }

# 응답 목록에서 **S24- 픽스처 행만** 센다.
# `counts.*` 는 표 전체(사용자가 올린 실자료 창고가 이미 들어 있을 수 있다)라 이 시험의
# 기대값으로 쓸 수 없다 — 시험은 자기가 만든 것만 센다 (R-73 ②와 같은 원칙).
jcount() { # $1 본문  $2 unlinked|excluded
  printf '%s' "$1" | php -r '
    $d = json_decode(stream_get_contents(STDIN), true);
    $n = 0;
    foreach ($d[$argv[1]] ?? [] as $r) {
      if (strpos((string) ($r["ecount_code"] ?? ""), "S24W") === 0) { $n++; }
    }
    echo $n;
  ' "$2"
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
  for t in p_brands p_branches p_locations p_location_inbox p_vendors p_items p_docs; do
    total=$(psqlq "SELECT count(*) FROM $t;")
    nonqa=$(psqlq "SELECT count(*) FROM $t WHERE created_by NOT IN ($QA_IDS);")
    printf '  %-20s %8s %10s\n' "$t" "$total" "$nonqa"
    sum=$((sum + nonqa))
  done
  printf '  %-20s %8s %10s   ← 사용자(비-qa)가 만든 행 합계 (%s)\n' "합계" "-" "$sum" "$when"
  CENSUS_SUM="$sum"
}

cleanup() {
  hr "정리 — **시험이 만든 것만** 되돌린다 (R-73 ②)"
  local qa_ids
  qa_ids=$(psqlq "SELECT string_agg(id::text, ',') FROM users WHERE username LIKE 'qa\\_%';")
  if [ -z "$qa_ids" ]; then
    echo "  ! 검토 계정을 찾지 못해 정리를 건너뜁니다 (아무것도 지우지 않았습니다)"
    rm -rf "$JAR_DIR" "$WORK"
    return
  fi
  echo "  검토 계정 id: $qa_ids — 이 계정이 만든 행만 지웁니다 (표 전체 DELETE 없음)"

  psqlq "SELECT '  사용자(비-qa)가 만든 행: p_location_inbox='||
           (SELECT count(*) FROM p_location_inbox WHERE created_by NOT IN ($qa_ids))||
         ' p_locations='||(SELECT count(*) FROM p_locations WHERE created_by NOT IN ($qa_ids))||
         ' p_branches='||(SELECT count(*) FROM p_branches  WHERE created_by NOT IN ($qa_ids))||
         ' — 이 행들은 그대로 둡니다';"

  psqlc "UPDATE users SET branch_id = NULL, purchase_role = NULL
          WHERE username IN ('qa_employee01','qa_manager01');" \
    && echo "  qa 계정 branch_id·purchase_role 원복"

  # 참조가 있는 것부터. 대기표가 p_locations 를 가리키므로 **대기표를 먼저** 지운다.
  # (올린 원본 파일 행은 19단계 정리와 같게 그대로 둔다 — 「구매/가져오기」 폴더에 남는다.)
  psqlc "DELETE FROM p_location_inbox WHERE created_by IN ($qa_ids);
         UPDATE p_branches SET warehouse_location_id = NULL, central_location_id = NULL
          WHERE created_by IN ($qa_ids) AND name LIKE 'S24-%';
         DELETE FROM p_locations WHERE created_by IN ($qa_ids) AND name LIKE 'S24-%';
         DELETE FROM p_branches  WHERE created_by IN ($qa_ids) AND name LIKE 'S24-%';
         DELETE FROM p_brands    WHERE created_by IN ($qa_ids) AND name LIKE 'S24-%';" \
    && echo "  검토 계정이 만든 S24- 시험 데이터 삭제"

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
  rm -rf "$JAR_DIR" "$WORK"
}

trap cleanup EXIT

hr "0-0. 시작 전 인구조사 (R-73 ③)"
QA_IDS=$(psqlq "SELECT coalesce(string_agg(id::text, ','), '0') FROM users WHERE username LIKE 'qa\\_%';")
echo "  검토 계정 id: $QA_IDS"
census before
CENSUS_BEFORE="$CENSUS_SUM"
INBOX_NONQA_BEFORE=$(psqlq "SELECT count(*) FROM p_location_inbox WHERE created_by NOT IN ($QA_IDS);")
echo
echo "  ★ 이 시험의 정리는 위 「비-qa」 행을 **한 줄도 지우지 않습니다**."

hr "0. 준비 — 검토 계정 로그인"
TOK_ADMIN=$(login qa_admin01)   || exit 1
TOK_EMP1=$(login qa_employee01) || exit 1
TOK_GUEST=$(login qa_guest01)   || exit 1
TOK_MGR=$(login qa_manager01)   || exit 1
echo "  로그인 4계정 완료"

sub "(f-1) 재인증 **전**의 buyer 세션 — 관문 응답이어야 한다"
psqlc "UPDATE users SET purchase_role = 'buyer' WHERE username = 'qa_manager01';"
R=$(call qa_manager01 GET /api/purchase/structure "$TOK_MGR")
echo "  HTTP $(code_of "$R")  $(body_of "$R" | head -c 160)"
expect "재인증 없는 buyer → 403" 403 "$(code_of "$R")"
expect "next=reauth 로 다음 걸음을 알려 준다" "reauth" "$(jget "$(body_of "$R")" next)"
R=$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR_DIR/qa_manager01.jar" -A "$UA" "$BASE/purchase/structure")
expect "화면 요청은 302 (재인증 화면으로)" 302 "$R"

echo "  재인증(편집 창 열기): qa_admin01 → $(reauth qa_admin01 "$TOK_ADMIN") · qa_manager01 → $(reauth qa_manager01 "$TOK_MGR")"

R=$(call qa_manager01 GET /api/purchase/structure "$TOK_MGR")
expect "재인증 뒤 buyer → 200" 200 "$(code_of "$R")"

hr "1. 씨앗 — 브랜드 S24-플란 · 지점 S24-강남·S24-일산·S24-라미트 (전부 S24- 접두)"
R=$(call qa_admin01 POST /api/purchase/brands "$TOK_ADMIN" '{"name":"S24-플란"}')
BRAND=$(new_id "$(body_of "$R")"); expect "브랜드 생성" 201 "$(code_of "$R")"
R=$(call qa_admin01 POST /api/purchase/branches "$TOK_ADMIN" "{\"brand_id\":$BRAND,\"name\":\"S24-강남\"}")
BR_GN=$(new_id "$(body_of "$R")"); expect "지점 S24-강남 생성" 201 "$(code_of "$R")"
R=$(call qa_admin01 POST /api/purchase/branches "$TOK_ADMIN" "{\"brand_id\":$BRAND,\"name\":\"S24-일산\"}")
BR_IS=$(new_id "$(body_of "$R")"); expect "지점 S24-일산 생성" 201 "$(code_of "$R")"
R=$(call qa_admin01 POST /api/purchase/branches "$TOK_ADMIN" "{\"brand_id\":$BRAND,\"name\":\"S24-라미트\"}")
BR_LM=$(new_id "$(body_of "$R")"); expect "지점 S24-라미트 생성" 201 "$(code_of "$R")"
echo "  brand=$BRAND 강남=$BR_GN 일산=$BR_IS 라미트=$BR_LM"

hr "2. 픽스처 — 이카운트 창고 내보내기(ESA005M) 모양의 xlsx (ZipArchive)"
export S24_DIR="$WORK"
php -d error_reporting=E_ALL -r '
$dir = getenv("S24_DIR");

$make = static function (string $path, array $rows): void {
    $esc = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_XML1, "UTF-8");
    $sheet = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>"
           . "<worksheet xmlns=\"http://schemas.openxmlformats.org/spreadsheetml/2006/main\"><sheetData>";
    foreach ($rows as $r => $row) {
        $sheet .= "<row r=\"" . ($r + 1) . "\">";
        foreach ($row as $c => $cell) {
            $col = chr(65 + $c);
            $sheet .= "<c r=\"" . $col . ($r + 1) . "\" t=\"inlineStr\"><is><t>" . $esc((string) $cell) . "</t></is></c>";
        }
        $sheet .= "</row>";
    }
    $sheet .= "</sheetData></worksheet>";

    $ct = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>"
        . "<Types xmlns=\"http://schemas.openxmlformats.org/package/2006/content-types\">"
        . "<Default Extension=\"rels\" ContentType=\"application/vnd.openxmlformats-package.relationships+xml\"/>"
        . "<Default Extension=\"xml\" ContentType=\"application/xml\"/>"
        . "<Override PartName=\"/xl/workbook.xml\" ContentType=\"application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml\"/>"
        . "<Override PartName=\"/xl/worksheets/sheet1.xml\" ContentType=\"application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml\"/>"
        . "</Types>";
    $rels = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>"
          . "<Relationships xmlns=\"http://schemas.openxmlformats.org/package/2006/relationships\">"
          . "<Relationship Id=\"rId1\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument\" Target=\"xl/workbook.xml\"/>"
          . "</Relationships>";
    $wb = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>"
        . "<workbook xmlns=\"http://schemas.openxmlformats.org/spreadsheetml/2006/main\""
        . " xmlns:r=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships\">"
        . "<sheets><sheet name=\"Sheet1\" sheetId=\"1\" r:id=\"rId1\"/></sheets></workbook>";
    $wbRels = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>"
            . "<Relationships xmlns=\"http://schemas.openxmlformats.org/package/2006/relationships\">"
            . "<Relationship Id=\"rId1\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet\" Target=\"worksheets/sheet1.xml\"/>"
            . "</Relationships>";

    @unlink($path);
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE);
    $zip->addFromString("[Content_Types].xml", $ct);
    $zip->addFromString("_rels/.rels", $rels);
    $zip->addFromString("xl/workbook.xml", $wb);
    $zip->addFromString("xl/_rels/workbook.xml.rels", $wbRels);
    $zip->addFromString("xl/worksheets/sheet1.xml", $sheet);
    $zip->close();
    echo "  만든 파일: ", basename($path), " ", filesize($path), " 바이트\n";
};

$head = ["창고코드","창고명","구분","생산공정명","외주거래처명","사용","추가사업장명"];
$tail = ["2026/09/13  오전 4:56:43"];
$base = [
    ["S24W001","S24-플란_S24-강남","창고","","","Yes","S24 사업장"],
    ["S24W002","S24-플란_S24-일산","창고","","","Yes","S24 사업장"],
    ["S24W003","S24-라미트","창고","","","Yes",""],
    ["S24W004","S24-중앙창고","창고","","","Yes",""],
    ["S24W005","S24-폐기창고","창고","","","No",""],
    ["S24W006","S24-기타창고","기타","","","Yes",""],
];
$make($dir . "/ecount-locations.xlsx", array_merge([["회사명 : S24"], $head], $base, [$tail]));

// 구분·사용이 바뀐 파일 — S24W004 한 행만 다르다 (updated 1 이어야 한다)
$changed = $base;
$changed[3] = ["S24W004","S24-중앙창고","중앙","","","No",""];
$make($dir . "/ecount-changed.xlsx", array_merge([["회사명 : S24"], $head], $changed, [$tail]));

// 같은 이름·다른 코드 한 행 — 연결 묶음의 409 를 만든다
$make($dir . "/ecount-dup.xlsx", [["회사명 : S24"], $head, ["S24W007","S24-기타창고","기타","","","Yes",""]]);

// 머리행이 없는 파일 — bad_header 400
$make($dir . "/no-header.xlsx", [["회사명 : S24"], ["가","나","다"], ["1","2","3"]]);
'

hr "3. (a) 업로드 → 대기표"
R=$(upload qa_admin01 "$TOK_ADMIN" "$WORK/ecount-locations.xlsx")
B=$(body_of "$R")
echo "  HTTP $(code_of "$R")  $B"
expect "업로드 201" 201 "$(code_of "$R")"
expect "등록 6" 6 "$(jget "$B" inserted)"
expect "건너뜀 1 (꼬리행)" 1 "$(jget "$B" skipped)"
expect "갱신 0" 0 "$(jget "$B" updated)"
expect "대기표에 S24 행 6" 6 "$(psqlq "SELECT count(*) FROM p_location_inbox WHERE ecount_code LIKE 'S24W%';")"
expect "전부 unlinked" 6 "$(psqlq "SELECT count(*) FROM p_location_inbox WHERE ecount_code LIKE 'S24W%' AND status = 'unlinked';")"
expect "이름은 원문 그대로 (S24-플란_S24-강남)" 1 "$(psqlq "SELECT count(*) FROM p_location_inbox WHERE ecount_code = 'S24W001' AND name = 'S24-플란_S24-강남';")"
expect "「사용 No」 행은 ecount_active=false" "f" "$(psqlq "SELECT ecount_active FROM p_location_inbox WHERE ecount_code = 'S24W005';")"
expect "「추가사업장명」이 표시용으로 담긴다" "S24 사업장" "$(psqlq "SELECT site_name FROM p_location_inbox WHERE ecount_code = 'S24W001';")"
expect "감사 한 줄 (purchase.structure.upload allow)" 1 \
  "$(psqlq "SELECT CASE WHEN count(*) > 0 THEN 1 ELSE 0 END FROM audit_logs
             WHERE action = 'purchase.structure.upload' AND result = 'allow' AND actor_name = 'qa_admin01'
               AND happened_at > now() - interval '5 minutes';")"
expect "상세 기록에 건수가 있다 (inserted=6)" 1 \
  "$(psqlq "SELECT count(*) FROM audit_logs WHERE action = 'purchase.structure.upload' AND result = 'allow'
              AND detail->>'inserted' = '6' AND happened_at > now() - interval '5 minutes';")"

sub "(a-2) 같은 파일 재업로드 → 코드 기준 멱등"
R=$(upload qa_admin01 "$TOK_ADMIN" "$WORK/ecount-locations.xlsx")
B=$(body_of "$R")
echo "  $B"
expect "재업로드 201" 201 "$(code_of "$R")"
expect "등록 0" 0 "$(jget "$B" inserted)"
expect "갱신 0" 0 "$(jget "$B" updated)"
expect "변동 없음 6" 6 "$(jget "$B" unchanged)"
expect "행 수는 그대로 6" 6 "$(psqlq "SELECT count(*) FROM p_location_inbox WHERE ecount_code LIKE 'S24W%';")"

sub "(a-3) 구분·사용을 바꾼 파일 → updated 1"
R=$(upload qa_admin01 "$TOK_ADMIN" "$WORK/ecount-changed.xlsx")
B=$(body_of "$R")
echo "  $B"
expect "갱신 1" 1 "$(jget "$B" updated)"
expect "변동 없음 5" 5 "$(jget "$B" unchanged)"
expect "구분이 바뀌었다" "중앙" "$(psqlq "SELECT kind_text FROM p_location_inbox WHERE ecount_code = 'S24W004';")"

sub "(a-4) 머리행이 없는 파일 → 400 bad_header (사유를 알려 준다)"
R=$(upload qa_admin01 "$TOK_ADMIN" "$WORK/no-header.xlsx")
echo "  HTTP $(code_of "$R")  $(body_of "$R")"
expect "bad_header 400" 400 "$(code_of "$R")"
expect "감사 deny bad_header" 1 \
  "$(psqlq "SELECT CASE WHEN count(*) > 0 THEN 1 ELSE 0 END FROM audit_logs
             WHERE action = 'purchase.structure.upload' AND reason = 'bad_header'
               AND happened_at > now() - interval '5 minutes';")"
expect "실패한 업로드는 대기표를 늘리지 않았다" 6 "$(psqlq "SELECT count(*) FROM p_location_inbox WHERE ecount_code LIKE 'S24W%';")"

hr "4. (b) GET /api/purchase/structure — 나무·미연결·추천"
R=$(call qa_admin01 GET /api/purchase/structure "$TOK_ADMIN")
B=$(body_of "$R")
expect "조회 200" 200 "$(code_of "$R")"
expect "미연결 6 (S24- 픽스처만)" 6 "$(jcount "$B" unlinked)"
expect "추천: S24-플란_S24-강남 → 지점 S24-강남" "$BR_GN" "$(inbox_field "$B" S24W001 suggest.branch_id)"
expect "추천 근거는 이름 해석" "name" "$(inbox_field "$B" S24W001 suggest.by)"
expect "추천: S24-플란_S24-일산 → 지점 S24-일산" "$BR_IS" "$(inbox_field "$B" S24W002 suggest.branch_id)"
expect "추천: 창고명 전체가 지점 이름(S24-라미트)" "$BR_LM" "$(inbox_field "$B" S24W003 suggest.branch_id)"
expect "추천 없음: S24-중앙창고" "null" "$(inbox_field "$B" S24W004 suggest)"
expect "브랜드가 나무에 있다" 1 "$(printf '%s' "$B" | grep -c 'S24-플란')"

ID1=$(inbox_id_of "$B" S24W001); ID2=$(inbox_id_of "$B" S24W002); ID3=$(inbox_id_of "$B" S24W003)
ID4=$(inbox_id_of "$B" S24W004); ID5=$(inbox_id_of "$B" S24W005); ID6=$(inbox_id_of "$B" S24W006)
echo "  대기표 id: $ID1 $ID2 $ID3 $ID4 $ID5 $ID6"

hr "5. (c) 연결 — p_locations 행은 **이때** 생긴다"
LOC_BEFORE=$(psqlq "SELECT count(*) FROM p_locations;")
R=$(call qa_admin01 POST /api/purchase/structure/link "$TOK_ADMIN" \
  "{\"links\":[{\"inbox_id\":$ID1,\"branch_id\":$BR_GN},{\"inbox_id\":$ID5,\"branch_id\":$BR_GN},{\"inbox_id\":$ID6,\"branch_id\":$BR_GN}]}")
echo "  HTTP $(code_of "$R")  $(body_of "$R")"
expect "연결 201" 201 "$(code_of "$R")"
expect "장소 3행이 늘었다" 3 "$(($(psqlq "SELECT count(*) FROM p_locations;") - LOC_BEFORE))"
expect "이름 원문 그대로" 1 "$(psqlq "SELECT count(*) FROM p_locations WHERE name = 'S24-플란_S24-강남' AND branch_id = $BR_GN;")"
expect "code = 창고코드" "S24W001" "$(psqlq "SELECT code FROM p_locations WHERE name = 'S24-플란_S24-강남' AND branch_id = $BR_GN;")"
expect "legacy_name = 원문" "S24-플란_S24-강남" "$(psqlq "SELECT legacy_name FROM p_locations WHERE code = 'S24W001';")"
expect "구분 「창고」 → warehouse" "warehouse" "$(psqlq "SELECT kind FROM p_locations WHERE code = 'S24W001';")"
expect "구분 「기타」 → other" "other" "$(psqlq "SELECT kind FROM p_locations WHERE code = 'S24W006';")"
expect "사용 No → is_active=false" "f" "$(psqlq "SELECT is_active FROM p_locations WHERE code = 'S24W005';")"
expect "대기표 status=linked 3행" 3 "$(psqlq "SELECT count(*) FROM p_location_inbox WHERE ecount_code LIKE 'S24W%' AND status = 'linked' AND location_id IS NOT NULL;")"
expect "감사 한 줄 (links=3)" 1 \
  "$(psqlq "SELECT count(*) FROM audit_logs WHERE action = 'purchase.structure.link' AND result = 'allow'
              AND detail->>'links' = '3' AND happened_at > now() - interval '5 minutes';")"

sub "(c-2) 같은 지점에 같은 이름 → 묶음 전체 409 · 0행 (트랜잭션)"
R=$(upload qa_admin01 "$TOK_ADMIN" "$WORK/ecount-dup.xlsx")
echo "  같은 이름·다른 코드 한 행 추가: $(body_of "$R")"
R=$(call qa_admin01 GET /api/purchase/structure "$TOK_ADMIN"); B=$(body_of "$R")
ID7=$(inbox_id_of "$B" S24W007)
LOC_BEFORE=$(psqlq "SELECT count(*) FROM p_locations;")
R=$(call qa_admin01 POST /api/purchase/structure/link "$TOK_ADMIN" \
  "{\"links\":[{\"inbox_id\":$ID4,\"branch_id\":$BR_GN},{\"inbox_id\":$ID7,\"branch_id\":$BR_GN}]}")
echo "  HTTP $(code_of "$R")  $(body_of "$R")"
expect "conflict 409" 409 "$(code_of "$R")"
expect "장소가 한 행도 늘지 않았다 (앞 건까지 롤백)" 0 "$(($(psqlq "SELECT count(*) FROM p_locations;") - LOC_BEFORE))"
expect "앞 건도 unlinked 그대로" "unlinked" "$(psqlq "SELECT status FROM p_location_inbox WHERE ecount_code = 'S24W004';")"
expect "감사 deny conflict" 1 \
  "$(psqlq "SELECT CASE WHEN count(*) > 0 THEN 1 ELSE 0 END FROM audit_logs
             WHERE action = 'purchase.structure.link' AND reason = 'conflict'
               AND happened_at > now() - interval '5 minutes';")"

sub "(c-3) 중단된 지점 → 400 bad_reference"
R=$(call qa_admin01 PATCH "/api/purchase/branches/$BR_IS" "$TOK_ADMIN" '{"is_active":false}')
expect "지점 S24-일산 중단 200" 200 "$(code_of "$R")"
R=$(call qa_admin01 POST /api/purchase/structure/link "$TOK_ADMIN" \
  "{\"links\":[{\"inbox_id\":$ID4,\"branch_id\":$BR_IS}]}")
echo "  HTTP $(code_of "$R")  $(body_of "$R")"
expect "bad_reference 400" 400 "$(code_of "$R")"
R=$(call qa_admin01 PATCH "/api/purchase/branches/$BR_IS" "$TOK_ADMIN" '{"is_active":true}')
expect "지점 되살리기 200" 200 "$(code_of "$R")"

sub "(c-4) 이미 연결된 행 → 400 not_unlinked"
R=$(call qa_admin01 POST /api/purchase/structure/link "$TOK_ADMIN" \
  "{\"links\":[{\"inbox_id\":$ID1,\"branch_id\":$BR_GN}]}")
echo "  HTTP $(code_of "$R")  $(body_of "$R")"
expect "not_unlinked 400" 400 "$(code_of "$R")"

sub "(c-5) 허용 키 밖·중복 inbox_id → 400 bad_request"
R=$(call qa_admin01 POST /api/purchase/structure/link "$TOK_ADMIN" \
  "{\"links\":[{\"inbox_id\":$ID4,\"branch_id\":$BR_GN}],\"branch_id\":9}")
expect "허용 키 밖 400" 400 "$(code_of "$R")"
R=$(call qa_admin01 POST /api/purchase/structure/link "$TOK_ADMIN" \
  "{\"links\":[{\"inbox_id\":$ID4,\"branch_id\":$BR_GN},{\"inbox_id\":$ID4,\"branch_id\":$BR_LM}]}")
expect "같은 창고 두 번 400" 400 "$(code_of "$R")"

hr "6. (d) 제외 · 되살리기"
R=$(call qa_admin01 POST /api/purchase/structure/exclude "$TOK_ADMIN" \
  "{\"inbox_ids\":[$ID4,$ID7],\"excluded\":true}")
echo "  HTTP $(code_of "$R")  $(body_of "$R")"
expect "제외 200" 200 "$(code_of "$R")"
expect "status=excluded 2행" 2 "$(psqlq "SELECT count(*) FROM p_location_inbox WHERE ecount_code IN ('S24W004','S24W007') AND status = 'excluded';")"
R=$(call qa_admin01 GET /api/purchase/structure "$TOK_ADMIN"); B=$(body_of "$R")
expect "미연결 수가 2로 줄었다 (S24W002·S24W003)" 2 "$(jcount "$B" unlinked)"
expect "제외 목록 2" 2 "$(jcount "$B" excluded)"
expect "제외 행에는 추천이 없다" "null" "$(inbox_field "$B" S24W004 suggest)"

sub "(d-2) 제외 행에 link → 400 not_unlinked"
R=$(call qa_admin01 POST /api/purchase/structure/link "$TOK_ADMIN" \
  "{\"links\":[{\"inbox_id\":$ID4,\"branch_id\":$BR_GN}]}")
expect "not_unlinked 400" 400 "$(code_of "$R")"

sub "(d-3) 되살리기 → unlinked"
R=$(call qa_admin01 POST /api/purchase/structure/exclude "$TOK_ADMIN" \
  "{\"inbox_ids\":[$ID7],\"excluded\":false}")
echo "  HTTP $(code_of "$R")  $(body_of "$R")"
expect "되살리기 200" 200 "$(code_of "$R")"
expect "다시 unlinked" "unlinked" "$(psqlq "SELECT status FROM p_location_inbox WHERE ecount_code = 'S24W007';")"
R=$(call qa_admin01 POST /api/purchase/structure/exclude "$TOK_ADMIN" \
  "{\"inbox_ids\":[$ID7],\"excluded\":false}")
expect "이미 unlinked 인데 되살리기 → 400 not_excluded" 400 "$(code_of "$R")"

hr "7. (e) 제외된 창고가 있어도 나머지 진행이 막히지 않는다"
R=$(call qa_admin01 POST /api/purchase/structure/link "$TOK_ADMIN" \
  "{\"links\":[{\"inbox_id\":$ID2,\"branch_id\":$BR_IS},{\"inbox_id\":$ID3,\"branch_id\":$BR_LM}]}")
echo "  HTTP $(code_of "$R")  $(body_of "$R")"
expect "제외 행이 있어도 연결 201" 201 "$(code_of "$R")"
R=$(call qa_admin01 POST /api/purchase/structure/exclude "$TOK_ADMIN" \
  "{\"inbox_ids\":[$ID7],\"excluded\":true}")
expect "마지막 남은 창고 제외 200" 200 "$(code_of "$R")"
R=$(call qa_admin01 GET /api/purchase/structure "$TOK_ADMIN"); B=$(body_of "$R")
expect "미연결 0 → 화면 「모두 정리됨」 조건" 0 "$(jcount "$B" unlinked)"
expect "연결 5" 5 "$(psqlq "SELECT count(*) FROM p_location_inbox WHERE ecount_code LIKE 'S24W%' AND status = 'linked';")"
expect "제외 2" 2 "$(jcount "$B" excluded)"
expect "지점 S24-라미트 아래 창고 1" 1 "$(psqlq "SELECT count(*) FROM p_locations WHERE branch_id = $BR_LM;")"

hr "8. (f) 권한 — qa_employee01(requester)·qa_guest01 은 다섯 라우트 전부 403"
psqlc "UPDATE users SET branch_id = $BR_GN, purchase_role = 'requester' WHERE username = 'qa_employee01';"
for WHO in qa_employee01 qa_guest01; do
  case "$WHO" in
    qa_employee01) T="$TOK_EMP1" ;;
    *)             T="$TOK_GUEST" ;;
  esac
  R=$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR_DIR/$WHO.jar" -A "$UA" "$BASE/purchase/structure")
  expect "$WHO 화면 403" 403 "$R"
  R=$(call "$WHO" GET /api/purchase/structure "$T")
  expect "$WHO GET 403" 403 "$(code_of "$R")"
  R=$(call "$WHO" POST /api/purchase/structure/link "$T" "{\"links\":[{\"inbox_id\":$ID4,\"branch_id\":$BR_GN}]}")
  expect "$WHO link 403" 403 "$(code_of "$R")"
  R=$(call "$WHO" POST /api/purchase/structure/exclude "$T" "{\"inbox_ids\":[$ID4],\"excluded\":true}")
  expect "$WHO exclude 403" 403 "$(code_of "$R")"
  R=$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR_DIR/$WHO.jar" -A "$UA" \
      -H "X-CSRF-Token: $T" -F "file=@$WORK/ecount-locations.xlsx" "$BASE/api/purchase/structure/upload")
  expect "$WHO upload 403" 403 "$R"
done
expect "거부가 감사에 남는다 (authz.route deny)" 1 \
  "$(psqlq "SELECT CASE WHEN count(*) > 0 THEN 1 ELSE 0 END FROM audit_logs
             WHERE action = 'authz.route' AND result = 'deny'
               AND detail::text LIKE '%/api/purchase/structure%'
               AND happened_at > now() - interval '10 minutes';")"

hr "9. (g) 실자료는 읽기만 — 사용자의 대기표·장소 행을 건드리지 않았다"
expect "비-qa 대기표 행 수 그대로" "$INBOX_NONQA_BEFORE" \
  "$(psqlq "SELECT count(*) FROM p_location_inbox WHERE created_by NOT IN ($QA_IDS);")"
echo "  (사용자가 올린 ESA005M 파일은 열지도 복사하지도 않았습니다 — 같은 열 구조의 S24- 픽스처만 썼습니다)"

hr "10. (h) 앱 계정에는 대기표 DELETE 권한이 없다"
expect "tbox_app 권한은 arw (DELETE 없음)" 1 \
  "$(psqlq "SELECT CASE WHEN has_table_privilege('tbox_app','p_location_inbox','DELETE') THEN 0 ELSE 1 END;")"
expect "tbox_app 은 SELECT·INSERT·UPDATE 를 갖는다" 1 \
  "$(psqlq "SELECT CASE WHEN has_table_privilege('tbox_app','p_location_inbox','SELECT')
                    AND has_table_privilege('tbox_app','p_location_inbox','INSERT')
                    AND has_table_privilege('tbox_app','p_location_inbox','UPDATE') THEN 1 ELSE 0 END;")"
