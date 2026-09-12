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
#     (언제나 전 지점)만 닿는 쓰기 라우트. 그 파일은 **`// lint:no-branch-scope <이유>`** 를
#     적어 스스로 선언한다 — 표식이 곧 검토 지점이고, 모듈 역할이 넓어지는 날 여기를 지운다.
scope_hits=""
for f in "$ROOT"/src/handlers/purchase_*.php; do
  [ -f "$f" ] || continue
  grep -q 'FROM p_' "$f" || continue
  grep -q 'purchaseScopeWhere(' "$f" && continue
  grep -q 'lint:no-branch-scope' "$f" && continue
  scope_hits="${scope_hits}${f}: FROM p_ 가 있는데 purchaseScopeWhere() 도 lint:no-branch-scope 표식도 없음"$'\n'
done
if [ -n "$scope_hits" ]; then
  echo "✖ 구매 핸들러의 지점 격리 누락  [S-01 · R-65]"; printf '%s' "$scope_hits" | sed 's/^/    /'; fail=1
else
  echo "✔ 구매 핸들러의 지점 격리 누락  [S-01 · R-65]"
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

# Authz.php 자체는 역할 문자열 비교가 허용되는 유일한 파일 — 위 검사에서 걸렸다면 그 파일만 예외
if [ "$fail" -eq 1 ] && [ -f "$ROOT/src/Authz.php" ]; then
  echo "(참고) src/Authz.php 의 역할 비교는 허용. 위 목록에서 해당 파일만 나온 항목은 무시해도 됨"
fi

echo
if [ "$fail" -eq 0 ]; then echo "결과: 통과 (금지 패턴 0건)"; else echo "결과: 실패 — 위 항목을 고치기 전엔 커밋 금지"; fi
exit $fail
