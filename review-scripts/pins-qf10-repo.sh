#!/usr/bin/env bash
# review-scripts/pins-qf10-repo.sh — apply-qf10-repo-2026-09-29.sh 의 고정 값 블록(@@HASHES-BEGIN@@ ~ @@HASHES-END@@)과
#   apply-qf10-db-2026-09-29.sh 의 「코드 먼저」 블록(@@CODEPIN-BEGIN@@ ~ @@CODEPIN-END@@)을 찍는다(읽기만)
#   본보기: pins-qf9-repo.sh(Q:F-9). 다른 점 — 이 묶음(Q:F-10 토큰 범위 원자 묶음 · 커뮤니티 검색 창구 · MCP 검색 · 변경 이력 관리자만)은:
#     ① 핵심 = src/Auth.php · src/Authz.php · src/Audit.php 셋(public/index.php 는 **이번엔 고정**) · 창구 = 핸들러 일곱(api_tokens_list ·
#        api_whoami · me · me_session_link · me_tokens_issue · me_tokens_list · settings_page) · 커뮤니티 라우터 = src/community/routes.php 하나 ·
#        자산 = public/assets/app.js 하나 · 변경 이력 = src/Changelog.php · src/views/shell_bottom.php 둘 · DB = db/schema.sql ·
#        db/migrations/2026-10-13_api_tokens_multi_scope.sql 둘 · 배치 = scripts/alerts.php 하나 · MCP = clients/mcp/** ·
#        그 밖의 src·public·db·scripts·deploy·config·clients 0 · 나머지(문서·시험·wiki) — 파일 머리 갈래 수를 찍는다
#     ② 차이 0 이어야 하는 것(FROZEN): src/Http.php · src/Db.php · src/bootstrap.php · public/index.php · db/grants.sql ·
#        src/community/handlers/ · db/community/ · deploy/ · config/
#     ③ t-box 라우트 표·토큰 창구 집합은 **그대로**(index.php 고정 — 줄 수·sha 불변). 커뮤니티 라우트 표 줄도 그대로.
#        커뮤니티 토큰 창구 표(COMMUNITY_API_TOKEN_ROUTES)만 **셋 → 여섯**(GET /api/search · GET /api/pages · GET /api/spaces) — 반영 전·뒤 sha 를 찍는다.
#     ④ 순서가 Q:F-9 와 반대(**코드 → 마이그레이션**) — DB 스크립트는 운영 트리의 코드가 새 판인지 파일 sha 몇 개 + 상수로 본다.
#        그 sha 는 이 스크립트가 CODEPIN 블록으로 함께 찍는다(두 스크립트의 값이 같은 판에서 나온다).
#   사용: bash review-scripts/pins-qf10-repo.sh <기준 판(b2ee066 = 운영 v2.38.0)> <반영할 판(검토 통과판)> [패치 파일]
#   값은 **git 객체**에서 읽는다(작업 트리·checkout 0). 패치를 주면 그 sha256 이 `git diff <기준> <판> -- . ':!review-scripts'` 와 같은지도 본다.
#   검토 반영 커밋이 생기면(Fable): `bash review-scripts/pins-qf10-repo.sh b2ee066 <검토 통과판>` → 출력의 HASHES 블록(표식 줄 포함)으로
#     apply-qf10-repo 의 블록을, CODEPIN 블록으로 apply-qf10-db 의 블록을 통째로 바꾼다. 마이그레이션이 바뀌었으면 apply-qf10-db 의
#     STMTS_WANT(주석·빈 줄 뺀 문장)도 새 파일에서 다시 뽑고, 머리 「연습 결과」 에 「고정 값 갱신 — 연습은 <판> 기준」 한 줄을 남긴다.
#   운영이 나아가면: 복제본에서 운영 HEAD 를 합치고 → 이 스크립트로 새 블록을 찍어 → 두 스크립트의 블록을 바꾼다.
#   위 ①~③ 이 어긋나면 찍지 않는다(그때는 다시 검토한다).
set -euo pipefail
[ "$#" -ge 2 ] && [ "$#" -le 3 ] || { echo "사용: bash $0 <기준 판> <반영할 판> [패치 파일]"; exit 1; }
for a in "$@"; do [ -n "$a" ] || { echo "빈 인자는 받지 않는다"; exit 1; }; done
B="$1"; R="$2"
G="git -C $(cd "$(dirname "$0")/.." && pwd)"
$G rev-parse -q --verify "$B^{commit}" >/dev/null || { echo "기준 판 없음: $B"; exit 1; }
$G rev-parse -q --verify "$R^{commit}" >/dev/null || { echo "반영할 판 없음: $R"; exit 1; }
$G merge-base --is-ancestor "$B" "$R" || { echo "✖ $R 이 $B 위에 있지 않다(운영 판을 먼저 합친다)"; exit 3; }
T=$(mktemp -d); chmod 700 "$T"; trap 'rm -rf "$T"' EXIT
$G diff "$B" "$R" -- . ':!review-scripts' > "$T/patch"
PSHA=$(sha256sum "$T/patch" | cut -d' ' -f1)
if [ "$#" -eq 3 ]; then
  [ "$(sha256sum "$3" | cut -d' ' -f1)" = "$PSHA" ] || { echo "✖ 준 패치가 git diff $B $R 과 다르다"; exit 3; }
fi
SUMM=$($G diff --summary -M -C "$B" "$R" -- . ':!review-scripts')
if grep -qE '^ (rename|copy|delete mode|mode change)|120000' <<< "$SUMM"; then
  echo "✖ 이름 바꾸기·복사·지우기·모드 바꾸기·링크가 있다 — 찍지 않는다"; exit 3
fi
mapfile -t CH < <($G diff --name-only --diff-filter=M "$B" "$R" -- . ':!review-scripts' | LC_ALL=C sort)
mapfile -t NEW < <($G diff --name-only --diff-filter=A "$B" "$R" -- . ':!review-scripts' | LC_ALL=C sort)
bsha() { $G show "$B:$1" | sha256sum | cut -d' ' -f1; }
rsha() { $G show "$R:$1" | sha256sum | cut -d' ' -f1; }
# ② 차이 0 이어야 하는 파일
FROZEN=(src/Http.php src/Db.php src/bootstrap.php public/index.php db/grants.sql src/community/handlers db/community deploy config)
NF=$($G diff "$B" "$R" -- "${FROZEN[@]}" | wc -l)
[ "$NF" = "0" ] || { echo "✖ HTTP·DB 접속·bootstrap·라우터(index.php)·grants·커뮤니티 핸들러·커뮤니티 DB·배포·설정이 바뀌었다($NF 줄) — 찍지 않는다"; exit 3; }
# ① src·public·db·scripts 는 정해진 열여섯만 · clients 는 clients/mcp/ 만
ALLOWED='^(src/(Auth|Authz|Audit)\.php|src/handlers/(api_tokens_list|api_whoami|me|me_session_link|me_tokens_issue|me_tokens_list|settings_page)\.php|src/community/routes\.php|public/assets/app\.js|src/Changelog\.php|src/views/shell_bottom\.php|db/(schema\.sql|migrations/2026-10-13_api_tokens_multi_scope\.sql)|scripts/alerts\.php)$'
OUT=$($G diff --name-only "$B" "$R" -- src public db scripts | grep -vE "$ALLOWED" || true)
[ -z "$OUT" ] || { echo "✖ 정해진 열여섯 밖의 src·public·db·scripts 파일이 바뀌었다 — 찍지 않는다:"; echo "$OUT"; exit 3; }
OUTC=$($G diff --name-only "$B" "$R" -- clients | grep -vE '^clients/mcp/' || true)
[ -z "$OUTC" ] || { echo "✖ clients/mcp/ 밖의 clients 파일이 바뀌었다 — 찍지 않는다:"; echo "$OUTC"; exit 3; }
tok() { sed -n '/^const TBOX_API_TOKEN_\(ROUTES\|PERSONAL_ROUTES\|ONLY_ROUTES\) = \[/,/^\];/p'; }
troutes() { grep -E "^[[:space:]]*\['(GET|POST|PUT|PATCH|DELETE)', *'/" || true; }
croutes() { grep -E "^[[:space:]]*\['(GET|POST|PUT|PATCH|DELETE)'," || true; }
ctok() { sed -n '/^const COMMUNITY_API_TOKEN_ROUTES = \[/,/^\];/p'; }
TKB=$($G show "$B:public/index.php" | tok | sha256sum | cut -d' ' -f1)
TKR=$($G show "$R:public/index.php" | tok | sha256sum | cut -d' ' -f1)
TRB=$($G show "$B:public/index.php" | troutes | sha256sum | cut -d' ' -f1)
TRR=$($G show "$R:public/index.php" | troutes | sha256sum | cut -d' ' -f1)
RNB=$($G show "$B:public/index.php" | troutes | wc -l | tr -d ' ')
RNR=$($G show "$R:public/index.php" | troutes | wc -l | tr -d ' ')
[ "$RNR" = "$RNB" ] && [ "$TRR" = "$TRB" ] && [ "$TKR" = "$TKB" ] || { echo "✖ t-box 라우트 표·토큰 창구 집합이 바뀌었다($RNB → $RNR) — 찍지 않는다"; exit 3; }
CRB=$($G show "$B:src/community/routes.php" | croutes | sha256sum | cut -d' ' -f1)
CRR=$($G show "$R:src/community/routes.php" | croutes | sha256sum | cut -d' ' -f1)
[ "$CRR" = "$CRB" ] || { echo "✖ 커뮤니티 라우트 표 줄이 바뀌었다 — 찍지 않는다"; exit 3; }
CTB=$($G show "$B:src/community/routes.php" | ctok | sha256sum | cut -d' ' -f1)
CTR=$($G show "$R:src/community/routes.php" | ctok | sha256sum | cut -d' ' -f1)
CNB=$($G show "$B:src/community/routes.php" | ctok | grep -cE "^    '(GET|POST) /api/" || true)
CNR=$($G show "$R:src/community/routes.php" | ctok | grep -cE "^    '(GET|POST) /api/" || true)
[ "$CNB" = "3" ] && [ "$CNR" = "6" ] || { echo "✖ 커뮤니티 토큰 창구 표가 셋 → 여섯이 아니다($CNB → $CNR) — 찍지 않는다"; exit 3; }
CADD=$(diff <($G show "$B:src/community/routes.php" | ctok | grep -E "^    '(GET|POST) /api/") <($G show "$R:src/community/routes.php" | ctok | grep -E "^    '(GET|POST) /api/") | sed -n "s/^> *'\([A-Z]* [^']*\)'.*/\1/p" | LC_ALL=C sort | tr '\n' ' ' || true)   # diff 는 다르면 1
[ "$CADD" = "GET /api/pages GET /api/search GET /api/spaces " ] || { echo "✖ 늘어난 커뮤니티 토큰 창구가 읽기 셋(검색·목록·공간)이 아니다: $CADD — 찍지 않는다"; exit 3; }
# 반영 스크립트가 **패치 파일 안에서** 세는 것과 같은 awk — 파일 머리 갈래:
#   핵심 · 창구 · 커뮤니티 라우터 · 자산 · 변경 이력 · DB · 배치 · MCP · 그 밖의 src·public·db·scripts·deploy·config·clients(0 이어야) · 나머지(문서·시험·wiki)
PATCHN=$(awk '
  /^diff --git / { p = substr($3, 3)
    if (p ~ /^src\/(Auth|Authz|Audit)\.php$/) j++
    else if (p ~ /^src\/handlers\/(api_tokens_list|api_whoami|me|me_session_link|me_tokens_issue|me_tokens_list|settings_page)\.php$/) h++
    else if (p ~ /^src\/community\/routes\.php$/) c++
    else if (p ~ /^public\/assets\/app\.js$/) a++
    else if (p ~ /^src\/(Changelog\.php|views\/shell_bottom\.php)$/) v++
    else if (p ~ /^db\/(schema\.sql|migrations\/2026-10-13_api_tokens_multi_scope\.sql)$/) b++
    else if (p ~ /^scripts\/alerts\.php$/) s++
    else if (p ~ /^clients\/mcp\//) m++
    else if (p ~ /^(src|public|db|scripts|deploy|config|clients)\//) x++
    else d++
    next }
  END { printf "%d %d %d %d %d %d %d %d %d %d", j, h, c, a, v, b, s, m, x, d }' "$T/patch")
MIGSHA=$($G show "$R:db/migrations/2026-10-13_api_tokens_multi_scope.sql" | sha256sum | cut -d' ' -f1)
echo "# @@HASHES-BEGIN@@"
echo "# 반영할 패치 = git diff $($G rev-parse --short "$B") $($G rev-parse --short "$R") -- . ':!review-scripts' (검토 통과판) ·"
echo "#   pins-qf10-repo.sh 가 찍음 · 인자로 받은 sha256 과 **둘 다** 같아야 한다"
echo "PATCH_WANT=$PSHA"
echo "# 같은 패치 안의 마이그레이션(apply-qf10-db 의 MIG_WANT 와 같아야 한다)"
echo "MIG_SHA=$MIGSHA"
echo "# 고치는 파일의 반영 전 기준 판($($G rev-parse --short "$B")) — 다르면 시작하지 않고, 되돌린 뒤 이 판으로 돌아왔는지 본다"
printf 'BASE_WANT="'; first=1
for f in "${CH[@]}"; do [ $first -eq 1 ] || printf '\n'; printf '%s=%s' "$f" "$(bsha "$f")"; first=0; done; printf '"\n'
echo "# 반영 뒤 검토 받은 판(바뀐·새 파일 전부 $(( ${#CH[@]} + ${#NEW[@]} )))"
printf 'CODE_WANT="'; first=1
for f in $(printf '%s\n' "${CH[@]}" "${NEW[@]}" | LC_ALL=C sort); do [ $first -eq 1 ] || printf '\n'; printf '%s=%s' "$f" "$(rsha "$f")"; first=0; done; printf '"\n'
echo "FILES_N=$(( ${#CH[@]} + ${#NEW[@]} ))"
echo "# 새로 만드는 파일의 git 요약 줄(모드 포함) — 이것 말고는 요약이 없어야 한다(지우기·이름 바꾸기·복사·모드 바꾸기 0)"
printf 'SUMMARY_WANT="'; $G diff --summary "$B" "$R" -- . ':!review-scripts' | LC_ALL=C sort | sed -e '$!b' -e 's/$/"/'
echo "# 라우트 — t-box 토큰 창구 집합 셋 · t-box 라우트 표 줄·줄 수(그대로 — index.php 고정) · 커뮤니티 라우트 표 줄(그대로) ·"
echo "#   커뮤니티 토큰 창구 표(반영 전 셋 → 뒤 여섯)"
echo "TOKSETS_WANT=$TKB"
echo "TROUTES_WANT=$TRB"
echo "ROUTES_N_WANT=$RNB"
echo "CROUTES_WANT=$CRB"
echo "CTOK_BASE=$CTB"
echo "CTOK_WANT=$CTR"
echo "# 패치 파일 안에서 센 파일 머리 갈래 — 핵심(Auth·Authz·Audit) · 창구 · 커뮤니티 라우터 · 자산 · 변경 이력 · DB · 배치 · MCP ·"
echo "#   그 밖의 src·public·db·scripts·deploy·config·clients(0) · 문서·시험·wiki"
echo "PATCHN_WANT=\"$PATCHN\""
echo "# @@HASHES-END@@"
echo
echo "# ── 아래는 apply-qf10-db-2026-09-29.sh 의 블록 ──"
echo "# @@CODEPIN-BEGIN@@"
echo "# 「코드 먼저」 표지 — 운영 트리의 이 파일들이 검토 통과판($($G rev-parse --short "$R"))이어야 마이그레이션을 보낸다(pins-qf10-repo.sh 가 찍음)"
echo "MIG_WANT=$MIGSHA"
printf 'CODE_PIN="'; first=1
for f in src/Auth.php src/Authz.php src/community/routes.php src/handlers/me_tokens_issue.php db/migrations/2026-10-13_api_tokens_multi_scope.sql; do
  [ $first -eq 1 ] || printf '\n'; printf '%s=%s' "$f" "$(rsha "$f")"; first=0; done; printf '"\n'
echo "# @@CODEPIN-END@@"
