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
