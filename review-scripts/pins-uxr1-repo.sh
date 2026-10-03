#!/usr/bin/env bash
# review-scripts/pins-uxr1-repo.sh — apply-uxr1-repo-2026-09-28.sh 의 고정 값 블록(@@HASHES-BEGIN@@ ~ @@HASHES-END@@)을 찍는다(읽기만)
#   본보기: pins-7d4-repo.sh(2026-09-28 운영 반영 통과판 — 7d4s). 다른 점 — 이 묶음(사용감 라운드 1 · 화면·문구·순서만)은
#     ① 라우트 표(t-box · 커뮤니티) · 토큰 창구 집합 변경 0 — 반영 전후 표 줄 sha 같음
#     ② src/ 에서 바뀌는 파일은 **뷰 셋과 도움말 둘뿐**(src/views/shell_top.php · src/community/handlers/page_view.php · home_page.php ·
#        src/help/community.php · screens.php) — 창구(핸들러)·판정·저장·로그인은 0 · public/ 은 public/assets/ 만 · db/ · deploy/ · config/ 0
#     ③ 판정·보안 핵심 Authz · CommunityAuthz · Auth · Http · Db · Passkey · Audit · Upload · Storage · 라우터 public/index.php · src/community/routes.php ·
#        bootstrap 은 차이 0
#   사용: bash review-scripts/pins-uxr1-repo.sh <기준 판(eadabd8 = 운영 v2.35.0)> <반영할 판(review-base/ux-r1)> [패치 파일]
#   값은 **git 객체**에서 읽는다(작업 트리·checkout 0). 패치를 주면 그 sha256 이 `git diff <기준> <판> -- . ':!review-scripts'` 와 같은지도 본다.
#   운영이 나아가면(위키 커밋 등): 복제본에서 운영 HEAD 를 합치고(커뮤니티 JS 는 import 버전을 다시 찍는다) → 이 스크립트로 새 블록을 찍어 →
#   반영 스크립트의 블록(표식 줄 포함)을 통째로 바꾼다. 위 ①~③ 이 어긋나면 찍지 않는다(그때는 다시 검토한다).
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
# 이름 바꾸기·복사·지우기·모드 바꾸기·심볼릭 링크가 있으면 찍지 않는다 — pipefail 아래 `… | grep -q` 는 쓰지 않는다(값으로 받고 본다)
SUMM=$($G diff --summary -M -C "$B" "$R" -- . ':!review-scripts')
if grep -qE '^ (rename|copy|delete mode|mode change)|120000' <<< "$SUMM"; then
  echo "✖ 이름 바꾸기·복사·지우기·모드 바꾸기·링크가 있다 — 찍지 않는다"; exit 3
fi
mapfile -t CH < <($G diff --name-only --diff-filter=M "$B" "$R" -- . ':!review-scripts' | LC_ALL=C sort)
mapfile -t NEW < <($G diff --name-only --diff-filter=A "$B" "$R" -- . ':!review-scripts' | LC_ALL=C sort)
bsha() { $G show "$B:$1" | sha256sum | cut -d' ' -f1; }
rsha() { $G show "$R:$1" | sha256sum | cut -d' ' -f1; }
# ③ 차이 0 이어야 하는 파일
FROZEN=(src/Authz.php src/community/CommunityAuthz.php src/Auth.php src/Http.php src/Db.php src/Passkey.php src/Audit.php src/Upload.php src/Storage.php
        public/index.php src/community/routes.php src/bootstrap.php db deploy config)
NF=$($G diff "$B" "$R" -- "${FROZEN[@]}" | wc -l)
[ "$NF" = "0" ] || { echo "✖ 판정·보안 핵심·라우터·DB·배포·설정이 바뀌었다($NF 줄) — 찍지 않는다"; exit 3; }
# ② src/ 는 뷰 셋 · 도움말 둘만 · public/ 은 public/assets/ 만
OUT=$($G diff --name-only "$B" "$R" -- src public | grep -vE '^(src/views/shell_top\.php|src/community/handlers/(page_view|home_page)\.php|src/help/(community|screens)\.php|public/assets/.*)$' || true)
[ -z "$OUT" ] || { echo "✖ 뷰·도움말·자산 밖의 src·public 파일이 바뀌었다 — 찍지 않는다:"; echo "$OUT"; exit 3; }
tok() { sed -n '/^const TBOX_API_TOKEN_\(ROUTES\|PERSONAL_ROUTES\|ONLY_ROUTES\) = \[/,/^\];/p'; }
TB=$($G show "$B:public/index.php" | tok | sha256sum | cut -d' ' -f1)
troutes() { grep -E "^[[:space:]]*\['(GET|POST|PUT|PATCH|DELETE)', *'/" || true; }
TRB=$($G show "$B:public/index.php" | troutes | sha256sum | cut -d' ' -f1)
croutes() { grep -E "^[[:space:]]*\['(GET|POST|PUT|PATCH|DELETE)'," || true; }
CRB=$($G show "$B:src/community/routes.php" | croutes | sha256sum | cut -d' ' -f1)
CRR=$($G show "$R:src/community/routes.php" | croutes | sha256sum | cut -d' ' -f1)
[ "$CRB" = "$CRR" ] || { echo "✖ 커뮤니티 라우트 표가 바뀌었다(이번 범위는 새 창구 0) — 찍지 않는다"; exit 3; }
# 반영 스크립트가 **패치 파일 안에서** 세는 것과 같은 awk 로 기대값을 찍는다 — 파일 머리(diff --git)의 갈래별 수:
#   뷰·도움말(src) · 자산(public/assets) · 그 밖의 src·public(0 이어야) · 문서·시험·위키(나머지)
PATCHN=$(awk '
  /^diff --git / { p = substr($3, 3)
    if (p ~ /^src\/(views\/shell_top\.php|community\/handlers\/(page_view|home_page)\.php|help\/(community|screens)\.php)$/) v++
    else if (p ~ /^public\/assets\//) a++
    else if (p ~ /^(src|public|db|deploy|config)\//) x++
    else d++
    next }
  END { printf "%d %d %d %d", v, a, x, d }' "$T/patch")
echo "# @@HASHES-BEGIN@@"
echo "# 반영할 패치 = git diff $($G rev-parse --short "$B") <검토 태그> -- . ':!review-scripts' — 찍은 판 $($G rev-parse --short "$R") 과 내용이 같다(그 뒤 커밋은 review-scripts/ 만 바꾼다) ·"
echo "#   pins-uxr1-repo.sh 가 찍음 · 인자로 받은 sha256 과 **둘 다** 같아야 한다"
echo "PATCH_WANT=$PSHA"
echo "# 고치는 파일의 반영 전 기준 판($($G rev-parse --short "$B")) — 다르면 시작하지 않고, 되돌린 뒤 이 판으로 돌아왔는지 본다"
printf 'BASE_WANT="'; first=1
for f in "${CH[@]}"; do [ $first -eq 1 ] || printf '\n'; printf '%s=%s' "$f" "$(bsha "$f")"; first=0; done; printf '"\n'
echo "# 반영 뒤 검토 받은 판(바뀐·새 파일 전부 $(( ${#CH[@]} + ${#NEW[@]} )))"
printf 'CODE_WANT="'; first=1
for f in $(printf '%s\n' "${CH[@]}" "${NEW[@]}" | LC_ALL=C sort); do [ $first -eq 1 ] || printf '\n'; printf '%s=%s' "$f" "$(rsha "$f")"; first=0; done; printf '"\n'
echo "FILES_N=$(( ${#CH[@]} + ${#NEW[@]} ))"
echo "# 새로 만드는 파일의 git 요약 줄(모드 포함) — 이것 말고는 요약이 없어야 한다(지우기·이름 바꾸기·복사·모드 바꾸기 0)"
printf 'SUMMARY_WANT="'; $G diff --summary "$B" "$R" -- . ':!review-scripts' | LC_ALL=C sort | sed -e '$!b' -e 's/$/"/'
echo "# 라우트 — public/index.php 의 토큰 창구 집합 셋 · t-box 라우트 표 줄 · 커뮤니티 라우트 표 줄(셋 다 반영 전후 같아야 — 새 창구 0)"
echo "TOKSETS_WANT=$TB"
echo "TROUTES_WANT=$TRB"
echo "CROUTES_WANT=$CRB"
echo "# 패치 파일 안에서 센 파일 머리 갈래 — 뷰·도움말 · 자산 · 그 밖의 src·public·db·deploy·config(0) · 문서·시험·위키"
echo "PATCHN_WANT=\"$PATCHN\""
echo "# @@HASHES-END@@"
