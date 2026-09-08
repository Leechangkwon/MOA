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
check "cURL / 소켓 함수"                                       'curl_(init|exec|multi_exec)|fsockopen|pfsockopen|stream_socket_client' 'S-05'
check "요청 파라미터의 역할값 사용 (\$_GET/\$_POST['role'])"    "\\\$_(GET|POST|REQUEST|COOKIE)\[['\"](role|is_admin|team_id)['\"]\]" 'S-01'
check "Authz 밖에서 역할 문자열 직접 비교"                      "\\['role'\\]\s*===?\s*'(Admin|Manager|Employee|Guest)'"        'S-01'
check "코드 실행 함수 (eval/exec/system/shell_exec/passthru)"   '\b(eval|exec|system|shell_exec|passthru|popen|proc_open)\('   'S-09'
check "경로에 사용자 입력 결합 (uploads/ . \$var)"              "(storage|uploads)/['\"]?\s*\.\s*\\\$"                        'S-06'
check "display_errors 켬 / var_dump / print_r 노출"             'display_errors[^;]*(1|On)|var_dump\(|print_r\('               'S-09-7'

# Authz.php 자체는 역할 문자열 비교가 허용되는 유일한 파일 — 위 검사에서 걸렸다면 그 파일만 예외
if [ "$fail" -eq 1 ] && [ -f "$ROOT/src/Authz.php" ]; then
  echo "(참고) src/Authz.php 의 역할 비교는 허용. 위 목록에서 해당 파일만 나온 항목은 무시해도 됨"
fi

echo
if [ "$fail" -eq 0 ]; then echo "결과: 통과 (금지 패턴 0건)"; else echo "결과: 실패 — 위 항목을 고치기 전엔 커밋 금지"; fi
exit $fail
