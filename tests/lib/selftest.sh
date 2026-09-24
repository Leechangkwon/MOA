#!/usr/bin/env bash
# tests/lib/selftest.sh — lib 자체 시험 (점검 봇 1-A, docs/checkbot/stage1-directive.md T2)
#
# 고정 입력(tests/lib/fixtures/*.txt) → 기대 출력. 네트워크·DB·sudo·운영 8082 를 쓰지 않는다.
#   ① 한국어 합계 다섯 변형   ② 파이썬 네 형식   ③ 정적 「결과:」·RESULT 줄
#   ④ rc 와 합계가 어긋나면 더 나쁜 쪽(실패) + 형식 위반   ⑤ ③ 미검증(필수 SKIP·rc 3·4·known_fail)
#   ⑥ 가림·잡음 견본   ⑦ common.sh 함수   ⑧ run.sh 흉내 저장소(임시 폴더, 흉내 git — 실제 커밋 0)
#   ⑨ secrets.sh — 가짜 비밀이 명령 인자·/proc/*/cmdline 에 0, 표준입력으로만 감(가짜 curl·php·python3 심)
#   ⑩ db_read.sh — ro → sudo 폴백 → rc 3, 실패를 0 으로 읽지 않음, 두 경로 모두 READ ONLY 세션 먼저, 한 문장만(가짜 psql·sudo 심)
#   ⑨·⑩ 은 PATH 앞에 둔 가짜 명령만 부른다 — 진짜 curl·psql·sudo 는 한 번도 불리지 않는다(네트워크·DB 0).
# 임시 파일은 ${TMPDIR:-/tmp} 아래 한 폴더에만 만들고 끝나면 지운다. 저장소 안에는 쓰지 않는다.
# 나가는 값: 0 전부 통과 · 1 실패 있음. 끝에 한국어 합계 줄과 RESULT 줄.
set -u
umask 077

HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)
FX="$HERE/fixtures"
PY="$HERE/results.py"
RUN="$HERE/../run.sh"
# shellcheck source=common.sh
. "$HERE/common.sh"

PASS=0 FAIL=0
hr()  { printf '\n════════════════════════════════════════════════════════════\n%s\n════════════════════════════════════════════════════════════\n' "$1"; }
sub() { printf '\n── %s\n' "$1"; }
ok()  { printf '  ✔ %s\n' "$1"; PASS=$((PASS+1)); }
bad() { printf '  ✖ %s\n' "$1"; FAIL=$((FAIL+1)); }
_short() { # 여러 줄 값은 「n줄·sha256 앞 7자」로 줄여 보인다
  case "$1" in
    *$'\n'*) printf '%s줄·%s' "$(printf '%s\n' "$1" | wc -l | tr -d ' ')" "$(printf '%s' "$1" | sha256sum | cut -c1-7)" ;;
    *) printf '%s' "$1" ;;
  esac
}
expect() {
  if [ "$2" = "$3" ]; then printf '  ✔ %s  (기대=%s 실제=%s)\n' "$1" "$(_short "$2")" "$(_short "$3")"; PASS=$((PASS+1))
  else printf '  ✖ %s  (기대=%s 실제=%s)\n' "$1" "$(_short "$2")" "$(_short "$3")"; FAIL=$((FAIL+1)); fi
}
contains() { # 이름 · 찾을 글자(고정 문자열) · 본문
  if printf '%s\n' "$3" | grep -qF -- "$2"; then ok "$1"; else bad "$1  (없음: $2)"; fi
}
lacks() {
  if printf '%s\n' "$3" | grep -qF -- "$2"; then bad "$1  (있으면 안 됨: $2)"; else ok "$1"; fi
}
J() { local f="$1" rc="$2"; shift 2; python3 "$PY" judge "$FX/$f" --rc "$rc" "$@" 2>&1; }
V() { printf 'verdict=%s code=%s pass=%s fail=%s skip=%s format=%s violation=%s' "$@"; }

command -v python3 >/dev/null 2>&1 || { echo "python3 없음 — 자체 시험을 할 수 없다"; exit 2; }

# ════════════════════════════════════════════════════════════════
hr "① 한국어 합계 다섯 변형 (원문 printf/echo 형식)"
expect "「통과 n · 실패 m · 건너뜀/미검증 k」 (api_*·f6x·stage3·4a)" \
  "$(V fail - 41 2 3 ko_skip_unverified 0)" "$(J ko-skip-unverified.txt 1)"
expect "「통과 n · 실패 m · 건너뜀 k」 (community stage4b~7a)" \
  "$(V pass - 57 0 4 ko_skip 0)" "$(J ko-skip.txt 0 --status advisory)"
expect "「  통과 n · 실패 m」 (stage26·f46·f51·f52·changelog·help_button)" \
  "$(V pass - 18 0 0 ko_indent_pf 0)" "$(J ko-indent-pf.txt 0)"
expect "health echo 줄 「  통과 n · 실패 m · 건너뜀 k」" \
  "$(V fail - 12 1 2 ko_health 0)" "$(J ko-health.txt 1)"
expect "wiki_books 「결과: 통과 n · 실패 m · 건너뜀 k」" \
  "$(V pass - 33 0 0 ko_wiki_books 0)" "$(J ko-wiki-books.txt 0)"
expect "중첩 시험의 「종료 코드 0 · 통과 9 …」 줄에 속지 않음(바깥 합계)" \
  "57" "$(J ko-skip.txt 0 --status advisory | sed -n 's/.* pass=\([0-9]*\) .*/\1/p')"

hr "② 파이썬 네 형식"
expect "SMOKE_FAIL= (browser_*.py) — 통과 수는 ✔ 줄" "$(V fail - 2 1 0 py_smoke_fail 0)" "$(J py-smoke-fail.txt 1)"
expect "BROWSER_FAIL= (wiki_books_browser.py) — 통과 수는 PASS 줄" "$(V pass - 3 0 0 py_browser_fail 0)" "$(J py-browser-fail.txt 0)"
expect "FAIL= (stage5a_handover_self.py)" "$(V fail - 1 1 0 py_fail 0)" "$(J py-fail.txt 1)"
expect "SEARCH_FAIL= (stage5b_search.py)" "$(V pass - 3 0 0 py_search_fail 0)" "$(J py-search-fail.txt 0)"

hr "③ 정적 「결과: 통과/실패」 · RESULT 줄"
expect "security_lint 「결과: 통과 (금지 패턴 0건)」" "$(V pass - 1 0 0 static 0)" "$(J static-lint-pass.txt 0)"
expect "wiki_check 「결과: 실패 — …」" "$(V fail - 0 1 0 static 0)" "$(J static-wiki-fail.txt 1)"
expect "RESULT 줄이 있으면 그것을 쓴다" "$(V pass - 25 0 0 result 0)" "$(J result-line.txt 0)"
expect "안쪽 시험의 RESULT 뒤 바깥 한국어 합계 → 바깥 것" "$(V fail - 70 1 0 ko_skip 0)" "$(J result-nested.txt 1)"
expect "RESULT 와 한국어 줄이 다르면 형식 위반·실패는 큰 쪽" "$(V fail - 22 2 0 result 1)" "$(J result-mismatch.txt 0)"

hr "④ rc 와 합계가 어긋나면 더 나쁜 쪽(실패) + 형식 위반"
expect "stage26 형: 합계 실패 2 인데 rc 0 → 실패" "$(V fail - 48 2 0 ko_indent_pf 1)" "$(J rc-mismatch-stage26.txt 0)"
expect "합계 실패 0 인데 rc 1 → 실패" "$(V fail - 18 0 0 ko_indent_pf 1)" "$(J ko-indent-pf.txt 1)"
expect "파이썬: X_FAIL=0 인데 rc 1 → 실패" "$(V fail - 3 0 0 py_browser_fail 1)" "$(J py-browser-fail.txt 1)"
expect "합계 줄 없음 + rc 0 → 실패(형식 위반)" "$(V fail - 0 0 0 - 1)" "$(J no-summary.txt 0)"
expect "합계 줄 없음 + rc 1 → 실패" "$(V fail - 0 0 0 - 0)" "$(J no-summary.txt 1)"

hr "⑤ ③ 미검증 — 필수 SKIP · rc 3·4 · known_fail"
expect "required + SKIP 5 → ③ skipped_by_test (통과 아님)" \
  "$(V unverified skipped_by_test 20 0 5 ko_skip_unverified 0)" "$(J required-skip.txt 0 --status required)"
expect "advisory + SKIP 5 → 통과" "$(V pass - 20 0 5 ko_skip_unverified 0)" "$(J required-skip.txt 0 --status advisory)"
expect "status 가 없으면 required 로 → ③ skipped_by_test" \
  "$(V unverified skipped_by_test 57 0 4 ko_skip 0)" "$(J ko-skip.txt 0)"
expect "rc 3 → ③ refused(시작 거부)" "$(V unverified refused 0 0 0 - 0)" "$(J refused-r73.txt 3)"
expect "rc 4 → ③ prep_failed(준비 실패)" \
  "$(V unverified prep_failed 41 2 3 ko_skip_unverified 0)" "$(J ko-skip-unverified.txt 4)"
expect "known_fail(만료 전) 실패 → ③ known_fail" "$(V unverified known_fail 12 1 2 ko_health 0)" \
  "$(J ko-health.txt 1 --status known_fail --expires 2026-10-24 --today 2026-09-24)"
expect "known_fail 만료 지남 → required 로 실패" "$(V fail - 12 1 2 ko_health 0)" \
  "$(J ko-health.txt 1 --status known_fail --expires 2026-10-24 --today 2026-10-25)"
expect "known_fail 만료일 없음 → required 로 실패" "$(V fail - 12 1 2 ko_health 0)" \
  "$(J ko-health.txt 1 --status known_fail --today 2026-09-24)"
expect "known_fail 인데 통과 → ③ known_fail(해소 후보)" "$(V unverified known_fail 18 0 0 ko_indent_pf 0)" \
  "$(J ko-indent-pf.txt 0 --status known_fail --expires 2026-10-24 --today 2026-09-24)"

hr "⑥ 가림 · 잡음 견본"
R=$(python3 "$PY" redact "$FX/redact-sample.txt")
expect "가림 뒤 지어낸 비밀 문자열(fake) 0" "0" "$(printf '%s\n' "$R" | grep -ci 'fake')"
# 견본 16줄부터: X-API-Token 헤더(curl -v · set -x 소문자) · 토큰 발급 응답 JSON "token"(expect 실패 줄 · 공백 꼴) · meta csrf-token
expect "가림 뒤 fake 0 — X-API-Token·\"token\"·meta 견본(16줄~)" "0" "$(printf '%s\n' "$R" | sed -n '16,$p' | grep -ci 'fake')"
# 견본 21줄부터(1-A 검토 2회차): JSON "password"·"current_password"·"new_password"·"secret" · KEY = 값(빈칸) · URL 쿼리 token=·secret=
expect "가림 뒤 fake 0 — JSON 비밀 칸·KEY = 값·쿼리 견본(21줄~)" "0" "$(printf '%s\n' "$R" | sed -n '21,$p' | grep -ci 'fake')"
expect "‹가림› 24곳" "24" "$(printf '%s\n' "$R" | grep -o '‹가림›' | wc -l | tr -d ' ')"
for k in 'tbox_session=‹가림›' 'X-CSRF-Token: ‹가림›' 'Authorization: ‹가림›' 'PGPASSWORD=‹가림›' \
         '해시 견본: ‹가림›' 'QR 원문: ‹가림›' 'DB_PASS=‹가림›' 'API_TOKEN=‹가림›' '"csrf_token":"‹가림›"' \
         'X-API-Token: ‹가림›' "x-api-token: ‹가림›' http://127.0.0.1/example" '"token":"‹가림›","expires_at":null' \
         '{"token": "‹가림›"}' '<meta name="csrf-token" content="‹가림›">' \
         '"password":"‹가림›"}' '{"current_password":"‹가림›","new_password":"‹가림›"}' '"secret": "‹가림›"}' \
         'DB_PASS = ‹가림›' 'APP_SECRET =  ‹가림›' '?token=‹가림›&page=2 HTTP/1.1' '&secret=‹가림›'; do
  contains "가림: $k" "$k" "$R"
done
contains "가림: 쿠키 병(jar) 줄의 tbox_session 값" "$(printf 'tbox_session\t‹가림›')" "$R"
contains "비밀이 아닌 줄은 그대로(qa_admin01 로그인 200)" "✔ 견본 항목 (qa_admin01 로그인 200)" "$R"
expect "가림은 두 번 해도 같다" "$R" "$(printf '%s' "$R" | python3 "$PY" redact -)"
D=$(python3 "$PY" denoise "$FX/noise-sample.txt")
expect "잡음 제거 뒤 남는 줄 4" "4" "$(printf '%s\n' "$D" | grep -c .)"
lacks "psql NOTICE 줄 빠짐" "NOTICE" "$D"
lacks "BEGIN·COMMIT·GRANT 줄 빠짐" "GRANT" "$D"
lacks "Playwright 재시도 줄 빠짐" "retrying" "$D"
contains "✖ 실패 줄은 남음" "✖ 견본 실패 줄은 남는다" "$D"
contains "CREATE 낱말이 든 설명 줄은 남음" "CREATE 라는 낱말" "$D"
expect "잡음은 원문 파일에서 지우지 않는다(견본 그대로)" "1" "$(grep -c '^BEGIN$' "$FX/noise-sample.txt")"

hr "⑦ common.sh"
expect "종료 코드 상수 RC_OK·FAIL·USAGE·REFUSE·PREP" "0 1 2 3 4" "$RC_OK $RC_FAIL $RC_USAGE $RC_REFUSE $RC_PREP"
# 검토 계정 판정은 tests/lib/review_accounts.sh(F-71) 한 곳 — common.sh 에 판정이 다시 생기지 않았는지만 본다
expect "common.sh 에 검토 계정 판정 정의 0(판정은 review_accounts.sh)" "0" \
  "$(grep -cE '^[[:space:]]*(REVIEW_[A-Z_]*=|is_review_account[[:space:]]*\(|review_[a-z_]*sql[[:space:]]*\()' "$HERE/common.sh")"
expect "result_line 3 1 2" "RESULT pass=3 fail=1 skip=2" "$(result_line 3 1 2)"
expect "result_line 08 0 0 (10진수)" "RESULT pass=8 fail=0 skip=0" "$(result_line 08 0 0)"
out=$(result_line 1 x 0 2>/dev/null); rc=$?
expect "result_line 숫자 아님 → 출력 0 · rc 0(시험 rc 를 바꾸지 않음)" "0:" "$rc:$out"
out=$(result_line 1 2 2>/dev/null); rc=$?
expect "result_line 인자 2개 → 출력 0 · rc 0" "0:" "$rc:$out"
expect "한국어 줄 + result_line → 요약기가 RESULT 로 셈" "$(V pass - 5 0 0 result 0)" \
  "$({ printf '  통과 %d · 실패 %d\n' 5 0; result_line 5 0 0; } | python3 "$PY" judge - --rc 0)"
expect "common.sh 를 source 해도 부르는 쪽 set -e/-u 를 바꾸지 않음" "hB" \
  "$(bash -c 'set +e +u; . "$1"; echo "$-" | tr -cd hBeu' _ "$HERE/common.sh")"

# ════════════════════════════════════════════════════════════════
hr "⑧ run.sh 흉내 저장소 (임시 폴더 · 흉내 git · 흉내 시험)"
T=$(mktemp -d "${TMPDIR:-/tmp}/tbox-selftest.XXXXXX") || { bad "임시 폴더를 만들지 못함"; T=""; }
if [ -n "$T" ]; then
  trap 'rm -rf -- "$T"' EXIT
  R0="$T/repo"; RUNS="$T/runs"; CAT="$T/catalog.json"; MARK="$T/mark"
  mkdir -p "$R0/tests/lib" "$T/bin" "$MARK"
  cp "$RUN" "$R0/tests/run.sh"; cp "$PY" "$HERE/common.sh" "$R0/tests/lib/"
  # 흉내 git — rev-parse·status·ls-files·diff 만 답한다(실제 저장소·커밋 없음)
  cat > "$T/bin/git" <<'EOF'
#!/usr/bin/env bash
while [ $# -gt 0 ]; do case "$1" in --no-optional-locks) shift ;; -C) shift 2 ;; *) break ;; esac; done
case "${1:-}" in
  rev-parse) case "${2:-}" in 'HEAD^{tree}') echo 1111111aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa ;;
                              HEAD) echo 2222222bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb ;; *) exit 128 ;; esac ;;
  status) [ -n "${FAKE_GIT_DIRTY:-}" ] && echo ' M tests/t_pass.sh'; exit 0 ;;
  ls-files) if [ "${2:-}" = --others ]; then for f in ${FAKE_GIT_OTHERS:-}; do echo "$f"; done; exit 0; fi
            printf '%s\n' tests/run.sh tests/lib/results.py tests/lib/common.sh tests/t_pass.sh tests/t_fail.sh \
              tests/t_skip.sh tests/t_refuse.sh tests/t_secret.sh tests/t_kf.sh tests/t_manual.sh \
              src/a.php src/b1.php src/c.php docs/readme.md
            for f in ${FAKE_GIT_EXTRA:-}; do echo "$f"; done ;;
  diff) for f in ${FAKE_GIT_DIFF:-}; do echo "$f"; done ;;
  *) exit 1 ;;
esac
EOF
  chmod 700 "$T/bin/git"
  cat > "$R0/tests/t_pass.sh" <<'EOF'
. "$(dirname "$0")/lib/common.sh"
printf '  ✔ 흉내 통과 하나\n  ✔ 흉내 통과 둘\n  ✔ 흉내 통과 셋\n'
printf '  통과 %d · 실패 %d\n' 3 0
result_line 3 0 0
EOF
  cat > "$R0/tests/t_fail.sh" <<'EOF'
printf 'NOTICE:  흉내 잡음\nBEGIN\n'
printf '  ✖ 흉내 실패 항목  (기대=403 실제=200)\n'
printf '통과 %d · 실패 %d · 건너뜀/미검증 %d\n' 1 1 0
exit 1
EOF
  cat > "$R0/tests/t_skip.sh" <<'EOF'
printf '  ✔ 흉내 하나\n  ○ SKIP 흉내 건너뜀  (검토 계정 아님)\n'
printf '통과 %d · 실패 %d · 건너뜀 %d\n' 2 0 1
EOF
  printf 'printf "  ✖ 흉내 R-73 가드 — 시작하지 않습니다\\n"\nexit 3\n' > "$R0/tests/t_refuse.sh"
  cat > "$R0/tests/t_secret.sh" <<'EOF'
printf '> Cookie: tbox_session=fakeE2Esession000\n'
printf '  ✖ 흉내 비밀이 든 실패 줄 tbox_session=fakeE2Esession000\n'
printf '  통과 %d · 실패 %d\n' 0 1
exit 1
EOF
  printf 'printf "  ✖ 흉내 알려진 실패\\n  통과 4 · 실패 1\\n"\nexit 1\n' > "$R0/tests/t_kf.sh"
  printf 'touch "$E2E_MARK/manual-ran"\nprintf "  통과 1 · 실패 0\\n"\n' > "$R0/tests/t_manual.sh"
  cat > "$CAT" <<'EOF'
{"version": 1, "tests": [
 {"id": "e2e.pass", "cmd": "bash tests/t_pass.sh", "module": "core", "env": "static", "status": "required", "needs": [], "covers": ["src/a.php"], "est_sec": 1, "writes_prod": false},
 {"id": "e2e.fail", "cmd": "bash tests/t_fail.sh", "module": "tbox", "env": "prod", "status": "required", "needs": [], "covers": ["src/b*.php"], "est_sec": 1, "writes_prod": false},
 {"id": "e2e.skip", "cmd": "bash tests/t_skip.sh", "module": "tbox", "env": "temp-instance", "status": "required", "covers": []},
 {"id": "e2e.refuse", "cmd": "bash tests/t_refuse.sh", "module": "community", "env": "prod", "status": "required"},
 {"id": "e2e.secret", "cmd": "bash tests/t_secret.sh", "module": "community", "env": "static", "status": "required"},
 {"id": "e2e.kf", "cmd": "bash tests/t_kf.sh", "module": "tbox", "env": "static", "status": "known_fail", "known_fail": {"reason": "흉내 사유", "since": "2026-09-24", "expires": "2099-01-01"}},
 {"id": "e2e.manual", "cmd": "bash tests/t_manual.sh", "module": "tbox", "env": "manual", "status": "manual_only", "notes": "흉내 수동"},
 {"id": "e2e.helper", "cmd": "python3 tests/t_helper.py", "module": "community", "env": "static", "status": "helper"},
 {"id": "e2e.orphan", "cmd": "python3 tests/t_orphan.py", "module": "community", "env": "static", "status": "orphan"},
 {"id": "e2e.prodpass", "cmd": "bash tests/t_pass.sh", "module": "tbox", "env": "prod", "status": "required"}
]}
EOF
  XDG="$T/xdg"; mkdir -p "$XDG"; LK="$XDG/qaredi-check/run.lock"
  E() { env PATH="$T/bin:$PATH" XDG_RUNTIME_DIR="$XDG" QCHECK_RUNS="$RUNS" E2E_MARK="$MARK" bash "$R0/tests/run.sh" "$@"; }

  sub "list — 고르기(실행 0)"
  o=$(E list --catalog "$CAT" 2>&1); rc=$?
  expect "list rc" "0" "$rc"
  contains "list 전체: 기본 제외 3(manual_only·helper·orphan)" "전체 10 · 고름 10 · 돌릴 것 7 · 기본 제외 3" "$o"
  contains "list 에 [운영] 표시" "[운영]" "$o"
  o=$(E list --catalog "$CAT" --select module:community 2>&1)
  contains "module:community → 4 고름 · 2 돌림" "고름 4 · 돌릴 것 2 · 기본 제외 2" "$o"
  o=$(E list --catalog "$CAT" --select status:manual_only 2>&1)
  contains "status:manual_only → 고르되 기본 제외" "고름 1 · 돌릴 것 0 · 기본 제외 1" "$o"
  o=$(E list --catalog "$CAT" --select status:manual_only --include-manual 2>&1)
  contains "--include-manual 이면 돌릴 것에 든다" "고름 1 · 돌릴 것 1 · 기본 제외 0" "$o"
  o=$(FAKE_GIT_DIFF="src/a.php src/b1.php src/c.php docs/readme.md tests/t_skip.sh" E list --catalog "$CAT" --select diff:HEAD~1..HEAD 2>&1)
  contains "diff: covers 겹침 2 + 바뀐 시험 자신 1" "고름 3 · 돌릴 것 3" "$o"
  contains "diff: 시험 없는 코드 파일 → ③ no_test (문서 제외)" "③ no_test 1 (바뀐 코드 파일 중 어느 covers 에도 없음): src/c.php" "$o"
  o=$(E list --catalog "$CAT" --select path:src/b1.php 2>&1)
  contains "path:src/b1.php → covers src/b*.php 인 e2e.fail" "고름 1" "$o"
  contains "path: 고른 것이 e2e.fail" "e2e.fail" "$o"
  o=$(E list --catalog "$CAT" --select 'id:e2e.p*' 2>&1)
  contains "id: glob" "고름 2" "$o"
  lacks "시험 파일이 모두 실렸으면 「카탈로그 밖」 경고 없음" "카탈로그 밖" "$(E list --catalog "$CAT" 2>&1)"
  o=$(FAKE_GIT_EXTRA="tests/t_new.sh tests/community/t_new2.py tests/lib/t_lib.sh tests/lib/fixtures/x.py" \
      FAKE_GIT_OTHERS="tests/t_untracked.sh" E list --catalog "$CAT" --select id:e2e.pass 2>&1)
  contains "카탈로그 밖 시험 3개(추적 2 + 추적 안 된 새 파일 1, 선택과 무관)" "⚠ 카탈로그 밖 시험 3개" "$o"
  contains "카탈로그 밖 목록" "tests/community/t_new2.py · tests/t_new.sh · tests/t_untracked.sh" "$o"
  lacks "tests/lib·fixtures 는 시험 파일로 세지 않음" "t_lib.sh" "$o"

  sub "run — 전체(기본 제외 적용)"
  o=$(E run --catalog "$CAT" 2>&1); rc=$?
  expect "실패가 있으면 rc 1" "1" "$rc"
  head1=$(printf '%s\n' "$o" | head -1)
  if printf '%s\n' "$head1" | grep -qE '^점검\(로컬\) #1 · 1111111 · [0-9]+초 · 10/10$'; then ok "머리 줄 형식: $head1"; else bad "머리 줄 형식: $head1"; fi
  contains "운영 표시(막지 않음)" "⚠ 운영 인스턴스를 침(qa 계정·R-73 가드는 시험 안): 3개" "$o"
  contains "운영 표시 줄: id 는 실패·③ 인 것만" "): 3개 — 실패·③ e2e.fail · e2e.refuse" "$o"
  lacks "운영 표시 줄에 통과한 시험 id 없음(e2e.prodpass)" "e2e.prodpass" "$(printf '%s\n' "$o" | grep '^⚠')"
  contains "합계 줄" "시험 통과 2 · 실패 2 · ③ 5 │ 항목 통과 13 · 실패 3 · SKIP 1" "$o"
  contains "실패 줄 + show 안내" "✖ e2e.fail 실패 1 (rc 1) — ✖ 흉내 실패 항목  (기대=403 실제=200)   » tests/run.sh show 1 e2e.fail" "$o"
  contains "③ known_fail(사유·만료일)" "③ known_fail e2e.kf 「흉내 사유」 만료 2099-01-01" "$o"
  contains "③ skipped_by_test(→ ai)" "③ skipped_by_test e2e.skip SKIP 1" "$o"
  contains "③ refused — 시작 거부(rc 3)" "③ refused e2e.refuse 시작 거부(rc 3)" "$o"
  contains "③ manual_only(기본 제외 → user)" "③ manual_only 1 — e2e.manual (기본 제외 · --include-manual · → user)" "$o"
  contains "③ orphan(기본 제외)" "③ orphan 1 — e2e.orphan (기본 제외 · 부르는 곳 없음)" "$o"
  contains "helper 는 정보 줄" "· helper 1 는 부르는 시험 안에서 돎" "$o"
  lacks "통과한 시험은 나열하지 않음" "e2e.pass" "$o"
  lacks "요약에 비밀 원문 없음" "fakeE2Esession" "$o"
  lacks "요약 실패 줄에 잡음(NOTICE) 없음" "NOTICE" "$o"
  expect "끝 줄 고정 문구" "로컬 요약 — 독립 검토(②)·관문 ③ 을 대신하지 않음" "$(printf '%s\n' "$o" | tail -1)"
  n=$(printf '%s\n' "$o" | wc -l | tr -d ' ')
  if [ "$n" -le 40 ]; then ok "요약 ≤40줄 ($n)"; else bad "요약 ≤40줄 ($n)"; fi
  if [ -e "$MARK/manual-ran" ]; then bad "manual_only 가 기본으로 돌았다"; else ok "manual_only 는 기본으로 돌지 않음"; fi

  sub "실행 폴더 권한 · 원문"
  expect "runs 0700" "700" "$(stat -c %a "$RUNS")"
  expect "runs/1 0700" "700" "$(stat -c %a "$RUNS/1")"
  expect "runs/1/raw 0700" "700" "$(stat -c %a "$RUNS/1/raw")"
  expect "raw/e2e.fail.log 0600" "600" "$(stat -c %a "$RUNS/1/raw/e2e.fail.log")"
  expect "summary.json 0600" "600" "$(stat -c %a "$RUNS/1/summary.json")"
  expect "summary.txt 0600" "600" "$(stat -c %a "$RUNS/1/summary.txt")"
  expect "원문은 가리지 않고 남김(0600)" "2" "$(grep -c fakeE2Esession "$RUNS/1/raw/e2e.secret.log")"
  expect "summary.json 에도 비밀 원문 없음" "0" "$(grep -c fakeE2Esession "$RUNS/1/summary.json")"
  expect "저장소 안에 쓴 파일 0 (repo 파일 수 그대로)" "10" "$(find "$R0" -type f | wc -l | tr -d ' ')"

  sub "show · summary · report"
  o=$(E show 1 e2e.secret 2>&1); rc=$?
  expect "show rc" "0" "$rc"
  contains "show 는 가림 적용" "tbox_session=‹가림›" "$o"
  lacks "show 에 비밀 원문 없음" "fakeE2Esession" "$o"
  contains "show 에 원문 줄 번호" "     2│   ✖ 흉내 비밀이 든 실패 줄" "$o"
  o=$(E show 1 e2e.fail --grep '흉내' 2>&1)
  contains "show --grep" "흉내 실패 항목" "$o"
  # 가린 값 한 글자씩 알아내기 차단: 원문에 맞추면 「tbox_session=f」 가 2줄로 답한다
  for pat in 'tbox_session=f' 'tbox_session=fakeE2E' 'fakeE2Esession000' 'session=[a-z]'; do
    o=$(E show 1 e2e.secret --grep "$pat" 2>&1)
    expect "show --grep '$pat' → 가린 뒤 줄에 맞춰 0줄" "0" "$(printf '%s\n' "$o" | grep -c '│')"
  done
  contains "  머리 줄에 「0줄(가린 뒤)」" "0줄(가린 뒤)" "$o"
  o=$(E show 1 e2e.secret --grep 'tbox_session=‹가림›' 2>&1)
  expect "show --grep 'tbox_session=‹가림›' → 가린 줄 2줄에 맞음" "2" "$(printf '%s\n' "$o" | grep -c '│')"
  lacks "  맞은 줄에도 비밀 원문 없음" "fakeE2Esession" "$o"
  expect "show --lines 1-2 → 머리 1 + 2줄" "3" "$(E show 1 e2e.fail --lines 1-2 | wc -l | tr -d ' ')"
  E show 1 no.such >/dev/null 2>&1; expect "show 없는 id → rc 2" "2" "$?"
  expect "summary 1 = summary.txt" "$(cat "$RUNS/1/summary.txt")" "$(E summary 1)"
  expect "summary last = summary 1" "$(E summary 1)" "$(E summary last)"
  o=$(E report 1 --md 2>&1)
  for h in '**① 안전한 이유(증거)**' '**② 불안전한 이유**' '**③ 미검증**' '**④ 다음 조치**' '✖ `e2e.fail`' 'known_fail `e2e.kf`'; do
    contains "report --md: $h" "$h" "$o"
  done
  lacks "report 에 비밀 원문 없음" "fakeE2Esession" "$o"

  sub "캐시 — 깨끗한 트리 · 24시간 · --fresh · dirty · env=prod"
  o=$(E run --catalog "$CAT" --select id:e2e.pass 2>&1); rc=$?
  expect "캐시 적중 rc 0" "0" "$rc"
  contains "캐시로 건너뜀" "시험 통과 1 (캐시 1)" "$o"
  o=$(E run --catalog "$CAT" --select id:e2e.pass --fresh 2>&1)
  lacks "--fresh 는 캐시 무시" "(캐시" "$o"
  o=$(FAKE_GIT_DIRTY=1 E run --catalog "$CAT" --select id:e2e.pass 2>&1)
  contains "dirty 표시" "1111111+dirty" "$o"
  lacks "dirty 면 캐시 안 씀" "(캐시" "$o"
  o=$(E run --catalog "$CAT" --select id:e2e.prodpass 2>&1)
  lacks "env=prod 는 캐시 안 함(#1 에서 통과했어도)" "(캐시" "$o"
  o=$(E show 2 e2e.pass 2>&1)
  contains "캐시로 센 시험의 show 는 원래 실행의 원문" "캐시 #1 의 원문" "$o"
  contains "캐시 원문 내용" "흉내 통과 하나" "$o"

  sub "--fail-fast · 40줄 상한"
  # 순서: static(e2e.pass) → API(e2e.fail, 실패) → 여기서 멈춤 → e2e.skip 은 돌리지 않음
  o=$(E run --catalog "$CAT" --select id:e2e.fail --select id:e2e.skip --select id:e2e.pass --fail-fast --fresh 2>&1); rc=$?
  expect "--fail-fast rc 1" "1" "$rc"
  contains "앞 실패 뒤는 ③ not_run" "③ not_run 1 — e2e.skip (--fail-fast — 앞 실패로 멈춤)" "$o"
  python3 - "$CAT" "$T/cat45.json" <<'PY'
import json, sys
c = json.load(open(sys.argv[1]))
c["tests"] = [{"id": "e2e.f%02d" % i, "cmd": "bash tests/t_fail.sh", "module": "tbox", "env": "static", "status": "required"} for i in range(45)]
json.dump(c, open(sys.argv[2], "w"))
PY
  o=$(E run --catalog "$T/cat45.json" 2>&1); rc=$?
  n=$(printf '%s\n' "$o" | wc -l | tr -d ' ')
  if [ "$rc" = 1 ] && [ "$n" -le 40 ]; then ok "실패 45개여도 요약 ≤40줄 ($n줄, rc $rc)"; else bad "실패 45개 요약 ($n줄, rc $rc)"; fi
  contains "넘친 줄은 --all 안내" "» tests/run.sh summary" "$o"
  last=$(ls "$RUNS" | grep -E '^[0-9]+$' | sort -n | tail -1)
  expect "summary --all 은 실패 45줄 전부" "45" "$(E summary "$last" --all | grep -c '^✖ ')"
  printf 'for i in $(seq 1 300); do printf "  ✖ 흉내 실패 %%d\\n" "$i"; done\nprintf "통과 0 · 실패 300 · 건너뜀 0\\n"\nexit 1\n' > "$T/t_big.sh"
  printf '[{"id": "e2e.big", "cmd": "bash %s", "module": "tbox", "env": "static", "status": "required"}]\n' "$T/t_big.sh" > "$T/cat-big.json"
  E run --catalog "$T/cat-big.json" >/dev/null 2>&1
  last=$(ls "$RUNS" | grep -E '^[0-9]+$' | sort -n | tail -1)
  n=$(E show "$last" e2e.big | wc -l | tr -d ' ')
  if [ "$n" -le 200 ]; then ok "show 는 머리 줄 포함 200줄 이하 ($n)"; else bad "show 200줄 초과 ($n)"; fi
  contains "show 가 잘렸으면 「더 있음」 안내" "줄 더 있음(--lines 로)" "$(E show "$last" e2e.big | head -1)"
  n=$(E show "$last" e2e.big --lines 1-500 | wc -l | tr -d ' ')
  if [ "$n" -le 200 ]; then ok "show --lines 1-500 도 200줄 이하 ($n)"; else bad "show --lines 200줄 초과 ($n)"; fi

  sub "flock — 사용자 단위 한 곳 \${XDG_RUNTIME_DIR:-\$HOME/.cache}/qaredi-check/run.lock · 저장소 안 실행 폴더 · 사용법"
  expect "잠금 폴더 0700" "700" "$(stat -c %a "$XDG/qaredi-check" 2>/dev/null)"
  expect "잠금 파일 0600" "600" "$(stat -c %a "$LK" 2>/dev/null)"
  if [ -e "$RUNS/.lock" ]; then bad "실행 폴더에 잠금 파일이 생겼다(옛 자리)"; else ok "실행 폴더에는 잠금 파일을 두지 않음"; fi
  o=$(env PATH="$T/bin:$PATH" flock -x "$LK" env XDG_RUNTIME_DIR="$XDG" QCHECK_RUNS="$RUNS" bash "$R0/tests/run.sh" run --catalog "$CAT" --select id:e2e.pass 2>&1); rc=$?
  expect "다른 실행이 잠금을 쥐면 rc 3" "3" "$rc"
  contains "거부 사유(잠금 자리)" "다른 실행이 진행 중이다(flock $LK)" "$o"
  if flock -n "$LK" true; then ok "실행이 끝나면 잠금이 풀린다"; else bad "실행 뒤 잠금이 남음"; fi
  # QCHECK_RUNS 를 달리 준 두 실행 — 첫 실행(느린 흉내 시험)이 도는 동안 둘째 실행은 rc 3
  printf 'touch "$E2E_MARK/slow-started"\ni=0\nwhile [ ! -e "$E2E_MARK/slow-go" ] && [ "$i" -lt 300 ]; do sleep 0.1; i=$((i+1)); done\nprintf "  ✔ 흉내 느린 시험\\n  통과 1 · 실패 0\\n"\n' > "$T/t_slow.sh"
  printf '[{"id": "e2e.slow", "cmd": "bash %s", "module": "core", "env": "static", "status": "required"}]\n' "$T/t_slow.sh" > "$T/cat-slow.json"
  ( env PATH="$T/bin:$PATH" XDG_RUNTIME_DIR="$XDG" QCHECK_RUNS="$T/runsA" E2E_MARK="$MARK" \
      bash "$R0/tests/run.sh" run --catalog "$T/cat-slow.json" --fresh > "$T/slowA.out" 2>&1; echo "$?" > "$T/slowA.rc" ) &
  pidA=$!
  i=0; while [ ! -e "$MARK/slow-started" ] && [ "$i" -lt 150 ]; do sleep 0.1; i=$((i+1)); done
  if [ -e "$MARK/slow-started" ]; then ok "첫 실행(QCHECK_RUNS=runsA)이 시험을 돌리는 중"; else bad "첫 실행이 시작되지 않음"; fi
  o=$(env PATH="$T/bin:$PATH" XDG_RUNTIME_DIR="$XDG" QCHECK_RUNS="$T/runsB" bash "$R0/tests/run.sh" run --catalog "$CAT" --select id:e2e.pass --fresh 2>&1); rc=$?
  expect "실행 폴더가 다른 둘째 실행(QCHECK_RUNS=runsB)도 rc 3" "3" "$rc"
  contains "  거부 사유" "다른 실행이 진행 중이다(flock $LK)" "$o"
  expect "  거부된 실행은 실행 기록(번호 폴더)을 만들지 않음" "0" "$(ls "$T/runsB" 2>/dev/null | grep -cE '^[0-9]+$')"
  touch "$MARK/slow-go"; wait "$pidA"
  expect "첫 실행은 끝까지 돌아 rc 0" "0" "$(cat "$T/slowA.rc" 2>/dev/null)"
  o=$(env PATH="$T/bin:$PATH" XDG_RUNTIME_DIR="$XDG" QCHECK_RUNS="$T/runsB" bash "$R0/tests/run.sh" run --catalog "$CAT" --select id:e2e.pass --fresh 2>&1); rc=$?
  expect "첫 실행이 끝난 뒤 둘째 실행은 돈다 rc 0" "0" "$rc"
  # XDG_RUNTIME_DIR 이 없으면 $HOME/.cache/qaredi-check/run.lock
  mkdir -p "$T/home"
  o=$(env -u XDG_RUNTIME_DIR PATH="$T/bin:$PATH" HOME="$T/home" QCHECK_RUNS="$RUNS" bash "$R0/tests/run.sh" run --catalog "$CAT" --select id:e2e.pass --fresh 2>&1); rc=$?
  expect "XDG_RUNTIME_DIR 없음 → rc 0" "0" "$rc"
  expect "  잠금은 \$HOME/.cache/qaredi-check/run.lock (폴더 0700)" "700 600" \
    "$(stat -c %a "$T/home/.cache/qaredi-check" 2>/dev/null) $(stat -c %a "$T/home/.cache/qaredi-check/run.lock" 2>/dev/null)"
  o=$(env -u XDG_RUNTIME_DIR PATH="$T/bin:$PATH" HOME="$T/home" flock -x "$T/home/.cache/qaredi-check/run.lock" \
      env -u XDG_RUNTIME_DIR HOME="$T/home" QCHECK_RUNS="$T/runsC" bash "$R0/tests/run.sh" run --catalog "$CAT" --select id:e2e.pass 2>&1); rc=$?
  expect "  그 자리를 쥐고 있으면 rc 3" "3" "$rc"
  mkdir -p "$T/xdg-link" "$T/elsewhere"; ln -s "$T/elsewhere" "$T/xdg-link/qaredi-check"
  o=$(env PATH="$T/bin:$PATH" XDG_RUNTIME_DIR="$T/xdg-link" QCHECK_RUNS="$RUNS" bash "$R0/tests/run.sh" run --catalog "$CAT" --select id:e2e.pass 2>&1); rc=$?
  expect "잠금 폴더가 링크면 rc 3" "3" "$rc"
  contains "  거부 사유" "잠금 폴더가 폴더가 아니다" "$o"
  if [ -e "$T/elsewhere/run.lock" ]; then bad "  링크 너머에 잠금 파일을 만들었다"; else ok "  링크 너머에 잠금 파일을 만들지 않음"; fi
  o=$(env PATH="$T/bin:$PATH" XDG_RUNTIME_DIR="rel/xdg" QCHECK_RUNS="$RUNS" bash "$R0/tests/run.sh" run --catalog "$CAT" --select id:e2e.pass 2>&1); rc=$?
  expect "XDG_RUNTIME_DIR 가 상대 경로면 rc 3" "3" "$rc"
  o=$(env PATH="$T/bin:$PATH" XDG_RUNTIME_DIR="$XDG" QCHECK_RUNS="$R0/.runs" bash "$R0/tests/run.sh" run --catalog "$CAT" --select id:e2e.pass 2>&1); rc=$?
  expect "실행 폴더가 저장소 안이면 rc 3" "3" "$rc"
  if [ -e "$R0/.runs" ]; then bad "거부했는데 저장소 안에 폴더가 생겼다"; else ok "거부할 때 저장소 안에 폴더를 만들지 않음"; fi
  E bogus >/dev/null 2>&1; expect "모르는 명령 → rc 2" "2" "$?"
  E run --catalog "$CAT" --select bogus:x >/dev/null 2>&1; expect "모르는 선택자 → rc 2" "2" "$?"
  E list --catalog "$T/none.json" >/dev/null 2>&1; expect "카탈로그 없음 → rc 2" "2" "$?"
  E run --catalog "$CAT" --select 'diff:--output=x' >/dev/null 2>&1; expect "diff 선택자에 옵션 주입 → rc 2" "2" "$?"
  E summary 999 >/dev/null 2>&1; expect "없는 실행 번호 → rc 2" "2" "$?"

  sub "선택자가 0개를 고르면 rc 2 (fail-closed)"
  nb=$(ls "$RUNS" | grep -cE '^[0-9]+$')
  o=$(E run --catalog "$CAT" --select id:e2e.nosuch 2>&1); rc=$?
  expect "id 오타로 0개 → rc 2(「전부 통과」 rc 0 아님)" "2" "$rc"
  contains "0개 사유 문구" "선택자가 아무것도 고르지 않음: id:e2e.nosuch" "$o"
  lacks "0개면 요약(합계 줄)을 찍지 않음" "시험 통과 0" "$o"
  o=$(FAKE_GIT_DIFF="docs/readme.md" E run --catalog "$CAT" --select diff:HEAD~1..HEAD 2>&1); rc=$?
  expect "diff 가 시험을 하나도 못 고르면 rc 2" "2" "$rc"
  contains "diff 0개 사유에 바뀐 파일 수" "선택자가 아무것도 고르지 않음: diff:HEAD~1..HEAD · diff HEAD~1..HEAD 바뀐 파일 1" "$o"
  expect "0개 선택이면 실행 기록(번호 폴더)을 만들지 않음" "$nb" "$(ls "$RUNS" | grep -cE '^[0-9]+$')"

  sub "시험 자식 umask = 부른 쪽 umask (요약기 자신은 077)"
  printf 'printf "child-umask=%%s\\n" "$(umask)"\nprintf "child-var=%%s\\n" "${QCHECK_CHILD_UMASK-none}"\nprintf "  ✔ 흉내 umask 보고\\n  통과 1 · 실패 0\\n"\n' > "$T/t_umask.sh"
  printf '[{"id": "e2e.umask", "cmd": "bash %s", "module": "core", "env": "static", "status": "required"}]\n' "$T/t_umask.sh" > "$T/cat-umask.json"
  for um in 0002 0022 0077; do
    o=$( (umask "$um"; E run --catalog "$T/cat-umask.json" --fresh) 2>&1); rc=$?
    last=$(ls "$RUNS" | grep -E '^[0-9]+$' | sort -n | tail -1)
    expect "부른 쪽 umask $um → 시험 자식 umask $um (rc $rc)" "child-umask=$um" \
      "$(grep '^child-umask=' "$RUNS/$last/raw/e2e.umask.log" 2>/dev/null)"
    if [ "$um" = 0002 ]; then
      expect "umask 0002 로 불러도 runs/$last 0700" "700" "$(stat -c %a "$RUNS/$last")"
      expect "umask 0002 로 불러도 raw/ 0700" "700" "$(stat -c %a "$RUNS/$last/raw")"
      expect "umask 0002 로 불러도 원문 raw/e2e.umask.log 0600" "600" "$(stat -c %a "$RUNS/$last/raw/e2e.umask.log")"
      expect "umask 0002 로 불러도 summary.json 0600" "600" "$(stat -c %a "$RUNS/$last/summary.json")"
      expect "umask 0002 로 불러도 summary.txt 0600" "600" "$(stat -c %a "$RUNS/$last/summary.txt")"
    fi
  done
  expect "QCHECK_CHILD_UMASK 는 시험 자식 환경에 넘기지 않음" "child-var=none" \
    "$(grep '^child-var=' "$RUNS/$last/raw/e2e.umask.log" 2>/dev/null)"
fi

# ════════════════════════════════════════════════════════════════
hr "⑨ secrets.sh — 비밀은 표준입력으로만 (1-B-1 B1-2 · R15)"
# ════════════════════════════════════════════════════════════════
if [ -z "$T" ]; then
  bad "임시 폴더가 없어 ⑨ 를 못 함"
elif ! . "$HERE/secrets.sh"; then
  bad "secrets.sh 를 source 하지 못함"
else
  SX="$T/secrets"; mkdir -p "$SX/bin"
  # 가짜 명령: 인자(한 줄에 하나)·표준입력을 기록하고, 0.4초 살아 있어 /proc 훑기가 볼 수 있게 한다
  for c in curl php python3; do
    cat > "$SX/bin/$c" <<SHIM
#!/bin/sh
for a in "\$@"; do printf '%s\n' "\$a"; done > "$SX/argv.$c"
cat > "$SX/stdin.$c"
sleep 0.4
exit 0
SHIM
    chmod 700 "$SX/bin/$c"
  done
  FAKE_SECRET="qchk-FAKE-$$-ZZ7Q"          # 가짜 비밀 — 진짜 계정 값이 아니다
  PW_FAKE="$FAKE_SECRET"
  # /proc 훑기 — 파이썬으로 1.2초 동안 쉬지 않고 모든 프로세스 인자를 본다(셸 루프는 한 바퀴에 3초가 넘어
  # 0.4초 사는 가짜 명령을 못 본다 — 1-B-1 독립 검토 ②-4). 아래 「양성 대조」가 이 훑기의 눈을 확인한다.
  proc_scan() { # → 가짜 비밀이 인자에 보인 (pid, 인자) 쌍 수
    python3 - "$FAKE_SECRET" <<'PY'
import os, sys, time
needle = sys.argv[1].encode(); me = str(os.getpid()); seen = set(); end = time.time() + 1.2
while time.time() < end:
    for pid in os.listdir('/proc'):
        if not pid.isdigit() or pid == me:
            continue
        try:
            raw = open('/proc/%s/cmdline' % pid, 'rb').read()
        except OSError:
            continue
        if needle in raw and not raw.startswith(b'python3\0-\0'):
            seen.add((pid, raw))
print(len(seen), end='')
PY
  }
  sx_run() { # $1 가짜 명령 이름  나머지 = 부를 도우미와 인자. 결과: "인자에 비밀 n · 표준입력에 비밀 n · /proc n"
    local c="$1" hits; shift
    rm -f "$SX/argv.$c" "$SX/stdin.$c"
    proc_scan > "$SX/scan" & local sp=$!
    sleep 0.2                                    # 훑기가 먼저 돌기 시작하게
    PATH="$SX/bin:$PATH" "$@" >/dev/null 2>&1
    wait "$sp"; hits=$(cat "$SX/scan")
    local na=x ns=x
    [ -f "$SX/argv.$c" ] && na=$(grep -cF -- "$FAKE_SECRET" "$SX/argv.$c")
    [ -f "$SX/stdin.$c" ] && ns=$(grep -cF -- "$FAKE_SECRET" "$SX/stdin.$c")
    printf '인자 %s · 표준입력 %s · proc %s' "$na" "$ns" "$hits"
  }
  # 양성 대조 — 가짜 비밀을 **인자로** 받은 가짜 명령을 훑기가 본다(보지 못하면 아래 「proc 0」은 증거가 아니다)
  expect "양성 대조: 비밀을 인자로 받은 가짜 curl 을 /proc 훑기가 본다" "인자 1 · 표준입력 0 · proc 1" \
    "$(sx_run curl curl -s "$FAKE_SECRET" </dev/null)"
  printf -v BODY_FAKE '{"username":"claude_x","password":"%s","otp":"000000"}' "$FAKE_SECRET"
  URL_FAKE="http://127.0.0.1:9/s/$FAKE_SECRET"
  expect "totp_now — php 인자에 비밀 0 · 표준입력 1" "인자 0 · 표준입력 1 · proc 0" "$(sx_run php totp_now PW_FAKE)"
  expect "totp_now — php 인자는 [totp_code.php 경로, -]" "$QSX_ROOT/scripts/totp_code.php|-" "$(paste -sd'|' "$SX/argv.php")"
  expect "curl_json_secret — 본문은 표준입력" "인자 0 · 표준입력 1 · proc 0" "$(sx_run curl curl_json_secret BODY_FAKE -s -H 'Content-Type: application/json' http://127.0.0.1:9/api/login)"
  contains "curl_json_secret — --data-binary @- 로 부름" "@-" "$(cat "$SX/argv.curl")"
  expect "curl_json_secret — 표준입력 = 본문 바이트 그대로(끝 줄바꿈 없음)" "${BODY_FAKE}x" "$(cat "$SX/stdin.curl"; printf x)"
  expect "curl_hdr_secret — 헤더는 표준입력" "인자 0 · 표준입력 1 · proc 0" "$(sx_run curl curl_hdr_secret X-API-Token PW_FAKE -s http://127.0.0.1:9/api/whoami)"
  expect "curl_hdr_secret — 표준입력 한 줄 = 「X-API-Token: 값」" "X-API-Token: $FAKE_SECRET" "$(cat "$SX/stdin.curl")"
  expect "curl_url_secret — 주소는 --config - 로" "인자 0 · 표준입력 1 · proc 0" "$(sx_run curl curl_url_secret URL_FAKE -s -o /dev/null)"
  expect "curl_url_secret — 표준입력 = url = \"…\"" "url = \"$URL_FAKE\"" "$(cat "$SX/stdin.curl")"
  expect "curl_form_secret — 필드 값은 표준입력(-F 이름=<-)" "인자 0 · 표준입력 1 · proc 0" "$(sx_run curl curl_form_secret password PW_FAKE -s -F otp=1 http://127.0.0.1:9/api/files)"
  contains "curl_form_secret — -F password=<- 로 부름" "password=<-" "$(cat "$SX/argv.curl")"
  A2_FAKE="second-$FAKE_SECRET"
  expect "py_secret — 두 값을 한 줄씩 표준입력으로" "인자 0 · 표준입력 2 · proc 0" "$(sx_run python3 py_secret PW_FAKE A2_FAKE -- python3 -c pass x)"
  expect "py_secret — 표준입력 순서 그대로" "$FAKE_SECRET|second-$FAKE_SECRET" "$(paste -sd'|' "$SX/stdin.python3")"
  sub "거부 — 보내지 않고 rc 90 (가짜 명령이 불리지 않음)"
  EMPTY_FAKE=''; NL_FAKE=$'a\nb'
  for spec in "빈 값|curl_hdr_secret X-API-Token EMPTY_FAKE -s x" "줄바꿈 섞임|curl_json_secret NL_FAKE -s x" \
              "변수 이름 모양 아님|curl_json_secret 'BAD NAME' -s x" "헤더 이름에 콜론|curl_hdr_secret X:Y PW_FAKE -s x" \
              "없는 변수|totp_now NO_SUCH_VAR_1B1" "py_secret -- 없음|py_secret PW_FAKE python3 -c pass"; do
    what=${spec%%|*}; cmd=${spec#*|}; rm -f "$SX/argv.curl" "$SX/argv.php" "$SX/argv.python3"
    (PATH="$SX/bin:$PATH" eval "$cmd") >/dev/null 2>&1; rc=$?
    called=0; for c in curl php python3; do [ -f "$SX/argv.$c" ] && called=1; done
    expect "거부: $what → rc 90 · 부름 0" "90:0" "$rc:$called"
  done
  sub "secret_file — 0600 · 0700 폴더만"
  SD=$(mktemp -d "$T/sd.XXXXXX"); sf=$(secret_file PW_FAKE "$SD"); rc=$?
  expect "secret_file rc 0 · 파일 0600" "0:600" "$rc:$(stat -c %a "$sf" 2>/dev/null)"
  expect "secret_file 내용 = 값 한 줄" "$FAKE_SECRET" "$(cat "$sf" 2>/dev/null)"
  expect "secret_file 로 grep -F -f 대조" "1" "$(printf 'x %s y\n' "$FAKE_SECRET" | grep -cF -f "$sf")"
  mkdir -p "$T/open755" && chmod 755 "$T/open755"
  secret_file PW_FAKE "$T/open755" >/dev/null 2>&1; rc=$?
  expect "secret_file — 0700 이 아닌 폴더 거부 rc 90 · 파일 0" "90:0" "$rc:$(ls -A "$T/open755" | wc -l | tr -d ' ')"
  sub "동적 범위 — 받을 변수 이름이 도우미 지역 이름과 같아도 값이 간다(__v 사고 재발 방지)"
  # 옛 지역 이름(__v·__s·__b·__h)을 비밀 변수 이름으로 넘겨도 값이 간다 · 도우미 몫 접두(_sx_·_sg_)는 거부
  __v="$FAKE_SECRET"; __s="$FAKE_SECRET"; __b="$FAKE_SECRET"; __h="$FAKE_SECRET"
  expect "curl_hdr_secret 에 __v 를 넘겨도 값이 감" "X-API-Token: $FAKE_SECRET" "$(sx_run curl curl_hdr_secret X-API-Token __v -s x >/dev/null; cat "$SX/stdin.curl" 2>/dev/null)"
  expect "totp_now 에 __s 를 넘겨도 값이 감" "$FAKE_SECRET" "$(sx_run php totp_now __s >/dev/null; cat "$SX/stdin.php" 2>/dev/null)"
  expect "curl_json_secret 에 __b 를 넘겨도 값이 감" "$FAKE_SECRET" "$(sx_run curl curl_json_secret __b -s x >/dev/null; cat "$SX/stdin.curl" 2>/dev/null)"
  _sx_v="$FAKE_SECRET"; _sg_v="$FAKE_SECRET"
  (PATH="$SX/bin:$PATH"; rm -f "$SX/argv.curl"; curl_hdr_secret X-API-Token _sx_v -s x) >/dev/null 2>&1; rc=$?
  expect "비밀 변수 이름 _sx_… 거부 rc 90 · 부름 0" "90:0" "$rc:$([ -f "$SX/argv.curl" ] && echo 1 || echo 0)"
  (PATH="$SX/bin:$PATH"; rm -f "$SX/argv.curl"; curl_json_secret _sg_v -s x) >/dev/null 2>&1; rc=$?
  expect "비밀 변수 이름 _sg_… 거부 rc 90 · 부름 0" "90:0" "$rc:$([ -f "$SX/argv.curl" ] && echo 1 || echo 0)"
  unset __v __s __b __h _sx_v _sg_v
  sub "진짜 php — totp_now = totp_code.php <인자> (공개 견본 비밀, 네트워크·DB 0)"
  RFC_FAKE=JBSWY3DPEHPK3PXP
  if command -v php >/dev/null 2>&1; then
    a=$(php "$QSX_ROOT/scripts/totp_code.php" "$RFC_FAKE" 2>/dev/null | head -1)
    b=$(totp_now RFC_FAKE 2>/dev/null | head -1)
    expect "같은 창의 코드가 같다(6자리)" "ok" "$([ -n "$a" ] && [ "$a" = "$b" ] && [ "${#a}" -eq 6 ] && echo ok || echo "a=$a b=$b")"
  else
    bad "php 가 없다 — totp_now 진짜 대조 못 함"
  fi
fi

# ════════════════════════════════════════════════════════════════
hr "⑩ db_read.sh — ro → sudo 폴백 → rc 3 (1-E E1·E3·E5 흉내)"
# ════════════════════════════════════════════════════════════════
if [ -z "$T" ]; then
  bad "임시 폴더가 없어 ⑩ 을 못 함"
elif ! . "$HERE/db_read.sh"; then
  bad "db_read.sh 를 source 하지 못함"
else
  DX="$T/dbread"; mkdir -p "$DX/bin"
  # 가짜 psql: -U tbox_testread 면 FAKE_RO, 아니면 FAKE_SUDO_PSQL 대로. 인자·표준입력·PG* 환경을 기록한다
  cat > "$DX/bin/psql" <<SHIM
#!/bin/sh
for a in "\$@"; do printf '%s\n' "\$a"; done > "$DX/argv.psql.\$\$"
printf 'PGOPTIONS=%s\nPGAPPNAME=%s\nPGPASSFILE=%s\nPGPORT=%s\nPGHOST=%s\n' "\$PGOPTIONS" "\$PGAPPNAME" "\$PGPASSFILE" "\$PGPORT" "\$PGHOST" > "$DX/env.psql.\$\$"
case " \$* " in *" tbox_testread "*) mode="\${FAKE_RO:-ok}" ;; *) mode="\${FAKE_SUDO_PSQL:-ok}" ;; esac
[ "\$mode" = ok ] || { echo "ERROR: permission denied (가짜)" >&2; exit 1; }
echo 7
SHIM
  cat > "$DX/bin/sudo" <<SHIM
#!/bin/sh
echo called >> "$DX/sudo.calls"
[ "\${FAKE_SUDO:-ok}" = ok ] || exit 1
while [ "\$#" -gt 0 ]; do case "\$1" in -n) shift ;; -u) shift 2 ;; *) break ;; esac; done
exec "\$@"
SHIM
  chmod 700 "$DX/bin/psql" "$DX/bin/sudo"
  PF="$DX/pgpass"; printf '127.0.0.1:5432:tbox_re:tbox_testread:qchk-FAKEPW\n' > "$PF"; chmod 600 "$PF"
  dr() { # 환경 조합으로 db_read_var 한 번 → "rc|값|경로|sudo 부름 수"
    rm -f "$DX"/argv.psql.* "$DX"/env.psql.* "$DX/sudo.calls"
    local rc; ( PATH="$DX/bin:$PATH"; export FAKE_RO="$1" FAKE_SUDO="$2" FAKE_SUDO_PSQL="${3:-ok}" PGPASSFILE="${4:-$PF}"
      db_read_var DRV tbox_re "SELECT count(*) FROM audit_logs WHERE actor_name = 'qchk-sql-marker'" 2>"$DX/err"; rc=$?
      printf '%s|%s|%s|%s' "$rc" "$DRV" "$DB_READ_VIA" "$( { [ -f "$DX/sudo.calls" ] && wc -l < "$DX/sudo.calls"; } || echo 0)" )
  }
  expect "E2 흉내: ro 성공 → 값 · via=ro · sudo 0" "0|7|ro|0" "$(dr ok ok)"
  expect "ro 경로 PGOPTIONS 에 default_transaction_read_only=on" "PGOPTIONS=-c default_transaction_read_only=on" "$(grep -h '^PGOPTIONS=' "$DX"/env.psql.* 2>/dev/null)"
  expect "ro 경로 application_name=tbox-testread" "PGAPPNAME=tbox-testread" "$(grep -h '^PGAPPNAME=' "$DX"/env.psql.* 2>/dev/null)"
  expect "SQL 은 -c 인자 하나로(표준입력 아님 — 메타 명령이 섞이지 않는다)" "1" "$(cat "$DX"/argv.psql.* | grep -cF qchk-sql-marker)"
  expect "ro 경로: -c 순서 = standard_conforming_strings → READ ONLY → SQL" "SET standard_conforming_strings = on|SET SESSION CHARACTERISTICS AS TRANSACTION READ ONLY|SELECT count(*) FROM audit_logs WHERE actor_name = 'qchk-sql-marker'" \
    "$(cat "$DX"/argv.psql.* | awk 'p{print; p=0} /^-c$/{p=1}' | paste -sd'|')"
  expect "ro 경로: 주소를 127.0.0.1:5432 로 못 박음" "-h 127.0.0.1|-p 5432" "$(cat "$DX"/argv.psql.* | awk '/^-[hp]$/{o=$0; getline; print o " " $0}' | paste -sd'|')"
  o=$( (export PGPORT=55432 PGHOST=/nonexist; dr ok ok >/dev/null; cat "$DX"/env.psql.* | grep -E '^PG(PORT|HOST)=' | paste -sd'|') )
  expect "ro 경로: 부른 쪽 PGPORT·PGHOST 를 따르지 않음(독립 검토 ②-3)" "PGPORT=|PGHOST=" "$o"
  expect "비밀번호는 인자·환경에 없음(.pgpass 경로만)" "0" "$(cat "$DX"/argv.psql.* "$DX"/env.psql.* | grep -cF qchk-FAKEPW)"
  expect "E1: ro 실패 → sudo 폴백 · 값 · via=sudo · sudo 1" "0|7|sudo|1" "$(dr fail ok)"
  expect "sudo 경로도 READ ONLY 세션 -c 가 먼저(ro 거부 → 슈퍼유저 쓰기 길 막음 · 독립 검토 ②-2)" "2" \
    "$(cat "$DX"/argv.psql.* | grep -cxF 'SET SESSION CHARACTERISTICS AS TRANSACTION READ ONLY')"
  expect "sudo 경로: -c 순서 = SET ROLE pg_read_all_data → standard_conforming_strings → READ ONLY → SQL(재검토 ②)" \
    "SET ROLE pg_read_all_data|SET standard_conforming_strings = on|SET SESSION CHARACTERISTICS AS TRANSACTION READ ONLY|SELECT count(*) FROM audit_logs WHERE actor_name = 'qchk-sql-marker'" \
    "$(grep -lx 'SET ROLE pg_read_all_data' "$DX"/argv.psql.* 2>/dev/null | head -1 | xargs -r cat | awk 'p{print; p=0} /^-c$/{p=1}' | paste -sd'|')"
  contains "E1: 폴백 경고 한 줄" "읽기 전용 경로 실패" "$(cat "$DX/err")"
  expect "E1: .pgpass 없음 → 조용히 sudo" "0|7|sudo|1" "$(dr ok ok ok "$DX/none")"
  chmod 644 "$PF"
  expect ".pgpass 0644 → 쓰지 않고 sudo" "0|7|sudo|1" "$(dr ok ok)"
  contains ".pgpass 0644 경고" "0600 일반 파일이 아니다" "$(cat "$DX/err")"
  chmod 600 "$PF"
  expect "E3: ro 실패 + sudo 실패 → rc 3 · 값 빈칸 · via=none" "3||none|1" "$(dr fail fail)"
  expect "E3: ro 실패 + sudo 는 되나 psql 실패 → rc 3" "3||none|1" "$(dr fail ok fail)"
  contains "E3: 0 으로 읽지 않는다는 문구" "0 으로 읽지 않는다" "$(cat "$DX/err")"
  o=$( (PATH="$DX/bin:$PATH"; FAKE_RO=fail FAKE_SUDO=fail PGPASSFILE="$PF"; export FAKE_RO FAKE_SUDO PGPASSFILE; db_read tbox_re "SELECT 1") 2>/dev/null); rc=$?
  expect "db_read(서브셸 형태) 둘 다 실패 → rc 3 · 출력 0" "3:" "$rc:$o"
  o=$( (PATH="$DX/bin:$PATH"; rm -f "$DX/sudo.calls"; PGPASSFILE="$PF"; export PGPASSFILE
        db_read_var X tbox_re "DELETE FROM audit_logs"; echo "rc=$?"; db_read_var X tbox_re "update users set role='x'"; echo "rc=$?"
        db_read_var X tbox_re "  ( select 1 )"; echo "rc=$? X=$X"; [ -f "$DX/sudo.calls" ] && echo sudo-called || echo sudo-none) 2>/dev/null)
  expect "쓰기 문장 거부 rc 2 ×2 · 괄호 SELECT 는 보냄 · sudo 0" "rc=2|rc=2|rc=0 X=7|sudo-none" "$(printf '%s' "$o" | paste -sd'|')"
  o=$( (PATH="$DX/bin:$PATH"; rm -f "$DX/sudo.calls"; PGPASSFILE="$PF"; export PGPASSFILE
        for q in "SELECT 1; DELETE FROM audit_logs" "SELECT 1;;" 'SELECT $$x$$' "SELECT 'open" "SELECT 1 -- ; x" \
                 "SELECT 1 -- '"$'\n'"; BEGIN READ WRITE; DELETE FROM t" "SELECT 1 /* ' */ ; DELETE FROM t" "SELECT E'\\''; DELETE FROM t; SELECT ''" \
                 'SELECT $a$x$a$' "SELECT pg_create_physical_replication_slot('x')" "SELECT set_config('default_transaction_read_only','off',false)" \
                 "WITH d AS (DELETE FROM t) SELECT 1" "SELECT 1 INTO t2" "SELECT U&'x'" 'SELECT "a" FROM t' \
                 "SELECT public.count(*) FROM t" "SELECT x . count (1)" $'\u00a0SELECT 1' $'SELECT cou\u00f1t(1)' $'SELECT 1\x01'; do db_read_var X tbox_re "$q"; printf 'rc=%s ' "$?"; done
        for q in "SELECT 1;" "SELECT 'a;b'" "SELECT 'it''s;'" "SELECT count(*) FROM t WHERE actor_name NOT LIKE 'qa\\_%' AND actor_name NOT LIKE 'claude\\_%';"; do db_read_var X tbox_re "$q"; printf 'rc=%s ' "$?"; done
        [ -f "$DX/sudo.calls" ] && echo sudo-called || echo sudo-none) 2>/dev/null)
  expect "허용 목록: 다중 문장·;;·달러 따옴표·안 닫힌 따옴표·주석·주석 속 따옴표·E'…'·\$tag\$·부수효과 함수·set_config·CTE DML·INTO·U&'·큰따옴표·스키마 한정 함수·NBSP·비-ASCII·제어 문자 → rc 2 · 끝 ;·따옴표 속 ;·판정 조각은 보냄" \
    "rc=2 rc=2 rc=2 rc=2 rc=2 rc=2 rc=2 rc=2 rc=2 rc=2 rc=2 rc=2 rc=2 rc=2 rc=2 rc=2 rc=2 rc=2 rc=2 rc=2 rc=0 rc=0 rc=0 rc=0 sudo-none" "$o"
  o=$( (db_read_var 'BAD NAME' tbox_re "SELECT 1"; echo "rc=$?"; db_read_var OK 'tbox;re' "SELECT 1"; echo "rc=$?") 2>/dev/null | paste -sd'|')
  expect "변수·DB 이름 모양 아니면 rc 2" "rc=2|rc=2" "$o"
fi

# ════════════════════════════════════════════════════════════════
hr "결과"
printf '통과 %d · 실패 %d\n' "$PASS" "$FAIL"
result_line "$PASS" "$FAIL" 0
[ "$FAIL" -eq 0 ] || exit 1
exit 0
