#!/usr/bin/env bash
# t-box 보안 린트 — 금지 패턴을 찾는다. 0건이면 통과(exit 0), 있으면 exit 1.
# 사용: scripts/security_lint.sh [검사 루트, 기본: 저장소 루트]
# 근거: CLAUDE.md 2장 금지 패턴, SECURITY.md 4장 2단계, ARCHITECTURE.md 13장
set -u
ROOT="${1:-$(cd "$(dirname "$0")/.." && pwd)}"
INCLUDE=(--include='*.php' --include='*.js' --include='*.html' --include='*.phtml')
EXCLUDE=(--exclude-dir=.git --exclude-dir=node_modules --exclude-dir=vendor --exclude-dir=scripts)
fail=0

check() { # $1 설명  $2 정규식(-E)  $3 관련 요구사항
  local hits
  hits=$(grep -rnE "${INCLUDE[@]}" "${EXCLUDE[@]}" -- "$2" "$ROOT" 2>/dev/null || true)
  if [ -n "$hits" ]; then
    echo "✖ $1  [$3]"; echo "$hits" | sed 's/^/    /'; fail=1
  else
    echo "✔ $1  [$3]"
  fi
}

echo "== t-box security lint : $ROOT =="
check "innerHTML / outerHTML / document.write 사용"          'innerHTML|outerHTML|document\.write\('                        'S-03'
check "SQL 조각과 변수가 같은 줄에 (SELECT/WHERE/LIKE ... \$var)"  '\b(SELECT|INSERT INTO|UPDATE|DELETE FROM|WHERE|LIKE|ORDER BY|GROUP BY|HAVING)\b[^;]*\$[A-Za-z_]' 'S-04'
check "PDO::query / exec 직접 호출 (Db::run 만 허용)"          '->(query|exec)\('                                             'S-04'
check "원격 URL 열기: file_get_contents('http / fopen('http"   "(file_get_contents|fopen|readfile)\(\s*['\"](https?|ftp|gopher|dict|file):" 'S-05'
check "변수 URL 열기: file_get_contents(\$url) 등"               "(file_get_contents|fopen|readfile|copy)\(\s*\\\$[A-Za-z_]*(url|uri|link|src|href|remote)" 'S-05'
check "소켓 함수 (fsockopen 등)"                                'fsockopen|pfsockopen|stream_socket_client|stream_socket_server'  'S-05'
# cURL 은 src/Fetch.php 한 파일에서만 허용 (PRD S-05 ⑬)
curl_hits=$(grep -rnE "${INCLUDE[@]}" "${EXCLUDE[@]}" -- 'curl_(init|exec|multi_exec|setopt)' "$ROOT" 2>/dev/null | grep -v '/src/Fetch\.php:' || true)
if [ -n "$curl_hits" ]; then
  echo "✖ cURL 사용이 src/Fetch.php 밖에 있음  [S-05 ⑬]"; echo "$curl_hits" | sed 's/^/    /'; fail=1
else
  echo "✔ cURL 사용이 src/Fetch.php 밖에 있음  [S-05 ⑬]"
fi
# 권한 매트릭스 쓰기 연결(tbox_perm)은 핸들러 한 파일에서만 허용 (17-2 — PRD F-25 ⑤, ARCH 5.15,
# SECURITY R-60). cURL 규칙과 같은 방식이다: 이름이 나온 곳을 전부 찾은 뒤 허용 파일만 뺀다.
# 이 규칙이 지키는 것 — 앱 롤 tbox_app 은 permissions 에 SELECT 만이고, 쓰기 자격이 붙는
# 코드 경로는 `PATCH /api/admin/permissions` 하나뿐이라는 성질이다.
perm_hits=$(grep -rnE "${INCLUDE[@]}" "${EXCLUDE[@]}" -- 'Db::perm\(' "$ROOT" 2>/dev/null | grep -v '/src/handlers/admin_permissions_update\.php:' | grep -v '/src/Db\.php:' || true)
if [ -n "$perm_hits" ]; then
  echo "✖ Db::perm() 사용이 admin_permissions_update.php 밖에 있음  [F-25 ⑤ · R-60]"; echo "$perm_hits" | sed 's/^/    /'; fail=1
else
  echo "✔ Db::perm() 사용이 admin_permissions_update.php 밖에 있음  [F-25 ⑤ · R-60]"
fi
check "요청 파라미터의 역할값 사용 (\$_GET/\$_POST['role'])"    "\\\$_(GET|POST|REQUEST|COOKIE)\[['\"](role|is_admin|team_id)['\"]\]" 'S-01'
check "Authz 밖에서 역할 문자열 직접 비교"                      "\\['role'\\]\s*===?\s*'(Admin|Manager|Employee|Guest)'"        'S-01'
check "코드 실행 함수 (eval/exec/system/shell_exec/passthru)"   '\b(eval|exec|system|shell_exec|passthru|popen|proc_open)\('   'S-09'
check "경로에 사용자 입력 결합 (uploads/ . \$var)"              "(storage|uploads)/['\"]?\s*\.\s*\\\$"                        'S-06'
check "display_errors 켬 / var_dump / print_r 노출"             'display_errors[^;]*(1|On)|var_dump\(|print_r\('               'S-09-7'

# ─── 19단계 구매·재고 모듈 (PRD F-28, ARCH 5.18, SECURITY R-65 · R-70) ───────────────
#
# (a) 지점 격리 누락 — 구매 핸들러가 `p_*` 표를 읽으면서 Authz::purchaseScopeWhere 를
#     부르지 않으면 반려한다 (S-01 / R-65). "조건을 빠뜨렸다" 가 코드 리뷰가 아니라
#     린트에서 드러나게 하는 것이 목적이다(라우트 정책 누락을 부팅 실패로 잡는 것과 같은 원칙).
#     예외: 지점이라는 축 자체가 없는 마스터(브랜드·거래처·분류·가져오기 매핑)와, buyer·Admin
#     (언제나 전 지점)만 닿는 쓰기 라우트. 그 자리는 **`// lint:no-branch-scope <이유>`** 를
#     적어 스스로 선언한다 — 표식이 곧 검토 지점이고, 모듈 역할이 넓어지는 날 여기를 지운다.
#
#     ★ 23단계 — 표식의 범위를 **파일 단위 → 조회 단위**로 좁혔다 (SECURITY R-65 잔여).
#       19단계 검토가 남긴 위험이 이것이었다: 표식이 파일 맨 위 주석에 한 번 있으면 그 파일에
#       **나중에 추가된 조회**까지 통째로 예외가 되어, 지점 격리가 빠진 새 SELECT 를 린트가
#       잡지 못했다. 이제 표식은 **바로 다음 줄부터 시작하는 한 덩어리**만 예외로 한다.
#
#       덩어리의 끝 = 빈 줄, 또는 문장을 닫는 줄(`SQL;` · `];` · `);`). 조회 하나 + 그 조회를
#       바로 쓰는 몇 줄이 한 덩어리이며, 거기서 끊긴다. 그래서 같은 파일에 조회를 새로 넣으면
#       **그 조회 위에 표식을 다시 적어야** 하고, 적는 순간 사람이 "정말 예외인가" 를 묻게 된다.
#       주석 줄의 `FROM p_`(설명 문장)는 조회가 아니므로 세지 않는다.
scope_hits=""
for f in "$ROOT"/src/handlers/purchase_*.php; do
  [ -f "$f" ] || continue
  grep -q 'FROM p_' "$f" || continue
  grep -q 'purchaseScopeWhere(' "$f" && continue
  bad=$(awk '
    /lint:no-branch-scope/                            { armed = 1; next }
    /^[[:space:]]*$/                                  { armed = 0; next }
    /^[[:space:]]*(SQL;|\];|\);)[[:space:]]*$/        { armed = 0; next }
    /^[[:space:]]*(\*|\/\/|#)/                        { next }
    /FROM[[:space:]]+p_/ { if (!armed) printf "%d행", NR }
  ' "$f")
  [ -z "$bad" ] && continue
  scope_hits="${scope_hits}${f}: purchaseScopeWhere() 없이 지점 격리 표식 밖에서 FROM p_ 를 읽음 ($bad)"$'\n'
done
if [ -n "$scope_hits" ]; then
  echo "✖ 구매 핸들러의 지점 격리 누락  [S-01 · R-65]"; printf '%s' "$scope_hits" | sed 's/^/    /'; fail=1
else
  echo "✔ 구매 핸들러의 지점 격리 누락  [S-01 · R-65]"
fi

# (c) 20단계 전표 핸들러는 **예외 표식을 쓸 수 없다** (지시서 20단계, SECURITY R-65 잔여).
#     (a) 의 `lint:no-branch-scope` 는 파일 단위라, 예외를 선언한 파일에 나중에 조회가
#     추가되면 잡지 못한다(19단계 검토가 남긴 잔여 위험). 전표는 지점 격리의 핵심이므로
#     전표 핸들러(purchase_pr_* · purchase_po_* · purchase_lists_* · cycle · recommend
#     · 23단계 suggest)는 **표식 없이 반드시** Authz::purchaseScopeWhere 를 부르게 한다.
#     SQL 이 없는 화면 틀(`*_page.php` 중 `FROM p_` 가 없는 것)은 대상이 아니다.
#     23단계 — 발주 제안(`purchase_suggest*.php`)을 이 목록에 넣는다. 제안은 (장소 × 품목)을
#     읽고 그대로 **발주요청 전표**가 되므로, 지점 격리가 빠지면 남의 지점 재고가 새는 것과
#     남의 지점 요청을 만드는 것이 한꺼번에 일어난다.
doc_hits=""
for f in "$ROOT"/src/handlers/purchase_pr_*.php "$ROOT"/src/handlers/purchase_po_*.php \
         "$ROOT"/src/handlers/purchase_lists_*.php "$ROOT"/src/handlers/purchase_cycle.php \
         "$ROOT"/src/handlers/purchase_recommend.php "$ROOT"/src/handlers/purchase_suggest*.php; do
  [ -f "$f" ] || continue
  if grep -q 'lint:no-branch-scope' "$f"; then
    doc_hits="${doc_hits}${f}: 전표 핸들러에는 lint:no-branch-scope 예외를 쓸 수 없음"$'\n'
    continue
  fi
  grep -q 'purchaseScopeWhere(' "$f" && continue
  grep -q 'FROM p_' "$f" || continue        # SQL 이 없는 화면 틀은 대상이 아니다
  doc_hits="${doc_hits}${f}: 전표 핸들러인데 purchaseScopeWhere() 호출이 없음"$'\n'
done
if [ -n "$doc_hits" ]; then
  echo "✖ 구매 전표 핸들러의 지점 격리 (예외 없음)  [S-01 · R-65]"; printf '%s' "$doc_hits" | sed 's/^/    /'; fail=1
else
  echo "✔ 구매 전표 핸들러의 지점 격리 (예외 없음)  [S-01 · R-65]"
fi

# (d) 전표에 **금액·단가를 클라이언트에서 받는** 경로가 생기지 않게 한다 (SECURITY R-66).
#     전표 쓰기 핸들러의 허용 키 목록(TBOX_*_KEYS)에 `unit_price`·`amount` 가 나타나면 반려한다.
#     금액은 언제나 서버가 `p_items.price_in` 에서 계산한다(Doc::lineAmount).
#     찾는 곳은 **허용 키 상수를 정의한 줄**뿐이다(`const TBOX_… = [ … ];`). 응답·감사에
#     금액을 담는 것은 정상이므로 파일 전체를 훑으면 오탐이 된다 — 막으려는 것은
#     "요청 본문에서 그 이름을 **받아들이는** 것" 하나다.
price_hits=$(grep -rn --include='purchase_pr_*.php' --include='purchase_po_*.php' \
  -E "^const TBOX_[A-Z_]*KEYS?[A-Z_]* *=.*'(unit_price|amount)'" "$ROOT/src/handlers" 2>/dev/null || true)
if [ -n "$price_hits" ]; then
  echo "✖ 전표 핸들러가 단가·금액을 요청 본문에서 받음  [S-01 · R-66]"; echo "$price_hits" | sed 's/^/    /'; fail=1
else
  echo "✔ 전표 핸들러가 단가·금액을 요청 본문에서 받음  [S-01 · R-66]"
fi

# (e) 21단계 — **재고 원장은 `src/purchase/Stock.php` 한 곳에서만 INSERT 된다**
#     (PRD F-33, ARCH 5.18 「원장」, SECURITY R-66).
#     입고·이동·사용·실사 핸들러에 `INSERT INTO p_stock_moves` 가 나타나면 반려한다.
#     막으려는 것: 음수 재고 검사(장소 FOR UPDATE → 합 재계산)와 취소 행 짝맞춤을
#     지나지 않는 원장 쓰기 경로가 생기는 것. 앱 계정에는 그 표의 UPDATE·DELETE 권한이
#     아예 없으므로 **잘못 적힌 행은 지울 수도 없다** — 들어가기 전에 막는 것이 유일한 방어다.
#     같은 이유로 `Stock.php` 밖의 어떤 PHP 도 이 문장을 쓰면 안 된다(cURL 규칙과 같은 방식).
ledger_hits=$(grep -rnE "${INCLUDE[@]}" "${EXCLUDE[@]}" -- 'INSERT[[:space:]]+INTO[[:space:]]+p_stock_moves' "$ROOT" 2>/dev/null | grep -v '/src/purchase/Stock\.php:' || true)
if [ -n "$ledger_hits" ]; then
  echo "✖ p_stock_moves INSERT 가 src/purchase/Stock.php 밖에 있음  [F-33 · R-66]"; echo "$ledger_hits" | sed 's/^/    /'; fail=1
else
  echo "✔ p_stock_moves INSERT 가 src/purchase/Stock.php 밖에 있음  [F-33 · R-66]"
fi

# (e-2) 21단계 재고 핸들러도 **예외 표식을 쓸 수 없다** — (c) 와 같은 규칙을 넓힌 것이다.
#     재고는 지점 격리의 핵심이므로 purchase_gr_* · mv_* · is_* · adj_* · stock_* · backorder 는
#     `lint:no-branch-scope` 없이 반드시 `Authz::purchaseScopeWhere` 를 부른다.
#     SQL 이 없는 화면 틀(`*_page.php` 중 `FROM p_` 가 없는 것)은 대상이 아니다.
stock_hits=""
for f in "$ROOT"/src/handlers/purchase_gr_*.php "$ROOT"/src/handlers/purchase_mv_*.php \
         "$ROOT"/src/handlers/purchase_is_*.php "$ROOT"/src/handlers/purchase_adj_*.php \
         "$ROOT"/src/handlers/purchase_stock_*.php "$ROOT"/src/handlers/purchase_backorder.php; do
  [ -f "$f" ] || continue
  if grep -q 'lint:no-branch-scope' "$f"; then
    stock_hits="${stock_hits}${f}: 재고 핸들러에는 lint:no-branch-scope 예외를 쓸 수 없음"$'\n'
    continue
  fi
  grep -q 'purchaseScopeWhere(' "$f" && continue
  grep -q 'FROM p_' "$f" || continue        # SQL 이 없는 화면 틀은 대상이 아니다
  stock_hits="${stock_hits}${f}: 재고 핸들러인데 purchaseScopeWhere() 호출이 없음"$'\n'
done
if [ -n "$stock_hits" ]; then
  echo "✖ 구매 재고 핸들러의 지점 격리 (예외 없음)  [S-01 · R-65]"; printf '%s' "$stock_hits" | sed 's/^/    /'; fail=1
else
  echo "✔ 구매 재고 핸들러의 지점 격리 (예외 없음)  [S-01 · R-65]"
fi

# (e-3) 재고 전표 핸들러도 단가·금액을 **요청 본문에서 받지 않는다** ((d) 를 넓힌 것).
price_hits2=$(grep -rn --include='purchase_gr_*.php' --include='purchase_mv_*.php' \
  --include='purchase_is_*.php' --include='purchase_adj_*.php' \
  -E "^const TBOX_[A-Z_]*KEYS?[A-Z_]* *=.*'(unit_price|amount)'" "$ROOT/src/handlers" 2>/dev/null || true)
if [ -n "$price_hits2" ]; then
  echo "✖ 재고 전표 핸들러가 단가·금액을 요청 본문에서 받음  [S-01 · R-66]"; echo "$price_hits2" | sed 's/^/    /'; fail=1
else
  echo "✔ 재고 전표 핸들러가 단가·금액을 요청 본문에서 받음  [S-01 · R-66]"
fi

# (b) 모듈 역할 판단이 Authz 밖으로 새는 것 — `purchase_role` **문자열 비교**가
#     src/Authz.php 밖에 있으면 반려한다 (S-01 / R-70). 본체의 "역할 문자열 직접 비교" 규칙과
#     같은 방식이다: 컬럼 이름을 적는 것(SQL·허용 키 목록)은 괜찮고, 그 **값을 견주는 것**이
#     Authz 밖에 나타나면 판정이 두 곳으로 갈린다.
prole_hits=$(grep -rnE "${INCLUDE[@]}" "${EXCLUDE[@]}" -- "purchase_role'?\]?\s*===?\s*'|===?\s*'(requester|buyer|stock|viewer)'" "$ROOT" 2>/dev/null | grep -v '/src/Authz\.php:' || true)
if [ -n "$prole_hits" ]; then
  echo "✖ purchase_role 값 비교가 src/Authz.php 밖에 있음  [S-01 · R-70]"; echo "$prole_hits" | sed 's/^/    /'; fail=1
else
  echo "✔ purchase_role 값 비교가 src/Authz.php 밖에 있음  [S-01 · R-70]"
fi

# ─── 26단계 「역할로 보기」 (PRD F-39, ARCH 5.20, SECURITY R-74) ──────────────────────
#
# (f) **보는 역할 판정이 흩어지지 않게 한다.** `act_role`·`real_role` 의 **값을 견주는 코드**가
#     아래 세 곳 밖에 있으면 반려한다:
#       src/Auth.php                 유효 역할을 만드는 유일한 자리 (currentUser)
#       src/Audit.php                actor_role 에 실제 역할을 적는 자리
#       src/handlers/me_act_*.php    켜기·끄기 창구 (시작 자격 확인)
#
#     막으려는 것: 핸들러·화면이 "보는 중이면 이건 빼고" 같은 판단을 따로 하기 시작하는 것.
#     그 순간 유효 역할이 두 벌이 되고, 한쪽만 고쳐지는 날이 곧 우회 경로다 (R-70 과 같은 원칙).
#     컬럼 이름을 적는 것(SQL·마이그레이션·응답 키)은 괜찮다 — 잡는 것은 `=== '값'` 비교뿐이다.
act_hits=$(grep -rnE "${INCLUDE[@]}" "${EXCLUDE[@]}" -- "(act_role|real_role)'?\]?[[:space:]]*===?[[:space:]]*'" "$ROOT" 2>/dev/null \
  | grep -v '/src/Auth\.php:' | grep -v '/src/Audit\.php:' | grep -v '/src/handlers/me_act_' || true)
if [ -n "$act_hits" ]; then
  echo "✖ act_role·real_role 값 비교가 Auth·Audit·me_act_* 밖에 있음  [F-39 · R-74]"; echo "$act_hits" | sed 's/^/    /'; fail=1
else
  echo "✔ act_role·real_role 값 비교가 Auth·Audit·me_act_* 밖에 있음  [F-39 · R-74]"
fi

# ─── F-43 URL 가져오기 통제된 리다이렉트 (PRD F-43, ARCH 5.22, SECURITY R-77) ─────────
#
# (g) **리다이렉트 통제가 풀리지 않게 한다.** 두 가지를 반려한다:
#     ① `CURLOPT_FOLLOWLOCATION` 이 `true` — curl 이 스스로 리다이렉트를 따라가면 우리 코드의
#        단계별 검사(https · 443 · 허용 호스트 · 사설 IP)가 **한 번도** 일어나지 않는다.
#        리다이렉트를 따라가는 것 자체는 F-43 이 허용했지만, 따라가는 주체는 언제나 우리다.
#     ② `curl_exec` 가 있는 파일에 `check(` 호출이 없음 — 검사 없이 밖으로 나가는 코드.
#        cURL 은 src/Fetch.php 한 파일에서만 허용되므로(위 S-05 ⑬ 규칙) 실질적으로
#        "그 파일이 check() 를 잃어버리지 않았는가" 를 지킨다.
follow_hits=$(grep -rnE "${INCLUDE[@]}" "${EXCLUDE[@]}" -- 'CURLOPT_FOLLOWLOCATION[[:space:]]*=>[[:space:]]*true|CURLOPT_FOLLOWLOCATION[^,;]*,[[:space:]]*true' "$ROOT" 2>/dev/null || true)
nocheck_hits=""
for f in $(grep -rlE "${INCLUDE[@]}" "${EXCLUDE[@]}" -- 'curl_exec\(' "$ROOT" 2>/dev/null || true); do
  grep -qE '(self|Fetch)::check\(' "$f" && continue
  nocheck_hits="${nocheck_hits}${f}: curl_exec 이 있는데 check() 호출이 없음"$'\n'
done
if [ -n "$follow_hits" ] || [ -n "$nocheck_hits" ]; then
  echo "✖ 리다이렉트 통제 (FOLLOWLOCATION true · check() 없는 curl_exec)  [F-43 · R-77]"
  [ -n "$follow_hits" ] && echo "$follow_hits" | sed 's/^/    /'
  [ -n "$nocheck_hits" ] && printf '%s' "$nocheck_hits" | sed 's/^/    /'
  fail=1
else
  echo "✔ 리다이렉트 통제 (FOLLOWLOCATION true · check() 없는 curl_exec)  [F-43 · R-77]"
fi

# Authz.php 자체는 역할 문자열 비교가 허용되는 유일한 파일 — 위 검사에서 걸렸다면 그 파일만 예외
if [ "$fail" -eq 1 ] && [ -f "$ROOT/src/Authz.php" ]; then
  echo "(참고) src/Authz.php 의 역할 비교는 허용. 위 목록에서 해당 파일만 나온 항목은 무시해도 됨"
fi

echo
if [ "$fail" -eq 0 ]; then echo "결과: 통과 (금지 패턴 0건)"; else echo "결과: 실패 — 위 항목을 고치기 전엔 커밋 금지"; fi
exit $fail
