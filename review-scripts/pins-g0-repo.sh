#!/usr/bin/env bash
# review-scripts/pins-g0-repo.sh — apply-g0-repo-2026-09-28.sh 의 고정 값 블록(@@HASHES-BEGIN@@ ~ @@HASHES-END@@)을 찍는다(읽기만)
#   본보기: scripts/pins-7d3-repo.sh(라우트 줄을 더하고 보안 핵심에 손대는 판의 통과 틀) · scripts/pins-7d4-repo.sh. 다른 점 — 이 묶음(G-0 개인 등급)은
#     ① 라우터 public/index.php 에 **라우트 열두 줄**(권한 창구 여섯 · 사람 후보 · 공유받음 · 요청 넷)을 더하고 지운 줄은 0 —
#        토큰 창구 집합 셋 · 강제 절차 허용 목록 · 커뮤니티 라우트 표 줄은 한 글자도 같다(새 창구는 세션 전용)
#     ② 판정·보안 핵심 Authz · Audit · Http · CommunityAuthz · db/** 를 **바꾼다**(지시서 범위 — 판정 함수·목록 SQL·폴더 불변) — 그래서 그 파일들의
#        지운 줄·더한 줄 수를 패치 안에서 세어 고정한다(CORE_WANT). 「고치지 않는 것」 Auth · Db · Passkey · Storage · Upload · bootstrap · deploy/** ·
#        login_page · 커뮤니티 search · comments_create 는 차이 0 이어야 한다
#     ③ 커뮤니티 JS 를 바꾼다(editor · database · templates) — 부품 버전(import ?v=)을 찍은 판이어야 한다(`community_asset_version.php --check` 0)
#   사용: bash review-scripts/pins-g0-repo.sh <기준 판(운영 HEAD — c2b4d76)> <반영할 판(review-base/g0)> [패치 파일]
#   값은 **git 객체**에서 읽는다(작업 트리·checkout 0). 패치를 주면 그 sha256 이 `git diff <기준> <판> -- . ':!review-scripts'` 와 같은지도 본다.
#   운영이 나아가면(사용감 라운드 1 등이 먼저 들어가면): 복제본에서 운영 HEAD 를 합치고(커뮤니티 JS 는 import 버전을 다시 찍는다) → 이 스크립트로 새 블록을 찍어 →
#   반영 스크립트의 블록(표식 줄 포함)을 통째로 바꾼다. 위 ①~③ 이 하나라도 어긋나면 찍지 않는다 — 그때는 다시 검토한다.
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
# 이름 바꾸기·복사·지우기·모드 바꾸기·심볼릭 링크가 있으면 찍지 않는다
# pipefail 아래 `… | grep -q` 는 쓰지 않는다 — 값으로 받고 본다
SUMM=$($G diff --summary -M -C "$B" "$R" -- . ':!review-scripts')
if grep -qE '^ (rename|copy|delete mode|mode change)|120000' <<< "$SUMM"; then
  echo "✖ 이름 바꾸기·복사·지우기·모드 바꾸기·링크가 있다 — 찍지 않는다"; exit 3
fi
mapfile -t CH < <($G diff --name-only --diff-filter=M "$B" "$R" -- . ':!review-scripts' | LC_ALL=C sort)
mapfile -t NEW < <($G diff --name-only --diff-filter=A "$B" "$R" -- . ':!review-scripts' | LC_ALL=C sort)
bsha() { $G show "$B:$1" | sha256sum | cut -d' ' -f1; }
rsha() { $G show "$R:$1" | sha256sum | cut -d' ' -f1; }
# ② 「고치지 않는 것」 — 차이 0
FROZEN=(src/Auth.php src/Db.php src/Passkey.php src/Storage.php src/Upload.php src/bootstrap.php deploy src/handlers/login_page.php
        src/community/handlers/search.php src/community/handlers/comments_create.php)
NF=$($G diff "$B" "$R" -- "${FROZEN[@]}" | wc -l)
[ "$NF" = "0" ] || { echo "✖ 「고치지 않는 것」이 바뀌었다($NF 줄) — 찍지 않는다"; exit 3; }
tok() { sed -n '/^const TBOX_API_TOKEN_\(ROUTES\|PERSONAL_ROUTES\|ONLY_ROUTES\) = \[/,/^\];/p'; }
gate() { sed -n '/^const TBOX_GATE_ALLOW_\(PASSWORD_CHANGE\|MFA_SETUP\) = \[/,/^\];/p'; }
troutes() { grep -E "^[[:space:]]*\['(GET|POST|PUT|PATCH|DELETE)', *'/" || true; }
croutes() { grep -E "^[[:space:]]*\['(GET|POST|PUT|PATCH|DELETE)'," || true; }
TB=$($G show "$B:public/index.php" | tok | sha256sum | cut -d' ' -f1); TR=$($G show "$R:public/index.php" | tok | sha256sum | cut -d' ' -f1)
GB=$($G show "$B:public/index.php" | gate | sha256sum | cut -d' ' -f1); GR=$($G show "$R:public/index.php" | gate | sha256sum | cut -d' ' -f1)
[ -n "$($G show "$B:public/index.php" | tok)" ] && [ "$TB" = "$TR" ] || { echo "✖ 토큰 창구 집합이 바뀌었다(새 창구는 세션 전용이어야) — 찍지 않는다"; exit 3; }
[ -n "$($G show "$B:public/index.php" | gate)" ] && [ "$GB" = "$GR" ] || { echo "✖ 강제 절차 허용 목록이 바뀌었다 — 찍지 않는다"; exit 3; }
TRB=$($G show "$B:public/index.php" | troutes | sha256sum | cut -d' ' -f1)
TRR=$($G show "$R:public/index.php" | troutes | sha256sum | cut -d' ' -f1)
CRB=$($G show "$B:src/community/routes.php" | croutes | sha256sum | cut -d' ' -f1)
CRR=$($G show "$R:src/community/routes.php" | croutes | sha256sum | cut -d' ' -f1)
[ "$CRB" = "$CRR" ] || { echo "✖ 커뮤니티 라우트 표가 바뀌었다(이 판은 0) — 찍지 않는다"; exit 3; }
# ① public/index.php — 지운 줄 0 · 더한 라우트 줄 = 열둘(글자 그대로 고정)
IDX=$($G diff -U0 "$B" "$R" -- public/index.php | grep -E '^[-+]' | grep -vE '^(\+\+\+|---) ' || true)
IDX_RM=$(grep -c '^-' <<< "$IDX" || true)
[ "$IDX_RM" = "0" ] || { echo "✖ public/index.php 에 지운 줄이 있다($IDX_RM) — 더하기만 허락 — 찍지 않는다"; exit 3; }
ADDED=$(grep -E "^\+[[:space:]]*\['(GET|POST|PUT|PATCH|DELETE)', *'/" <<< "$IDX" | sed 's/^+//' || true)
ADD_N=$(printf '%s\n' "$ADDED" | grep -c . || true)
[ "$ADD_N" = "12" ] || { echo "✖ 더한 라우트 줄이 열둘이 아니다($ADD_N) — 찍지 않는다"; exit 3; }
ADD_SHA=$(printf '%s\n' "$ADDED" | sha256sum | cut -d' ' -f1)
# ③ 커뮤니티 부품 버전 — 반영할 판의 트리에서 --check(작업 트리가 아니라 git 객체를 풀어서)
mkdir -p "$T/tree"; $G archive "$R" scripts public/assets src/community | tar -C "$T/tree" -xf -
( cd "$T/tree" && php scripts/community_asset_version.php --check > "$T/asset.txt" 2>&1 ) || { echo "✖ 커뮤니티 부품 버전이 찍힌 판이 아니다 — 찍지 않는다"; cat "$T/asset.txt"; exit 3; }
# 보안 핵심·라우터·커뮤니티 판정 파일 — 반영 스크립트가 **패치 파일 안에서** 세는 것과 같은 awk 로 기대값을 찍는다(지운/더한 줄)
CORE=$(awk '
  /^diff --git / { f = ""; if ($3 == "a/public/index.php") f = "idx"; else if ($3 == "a/src/Authz.php") f = "authz"; else if ($3 == "a/src/Audit.php") f = "aud";
                   else if ($3 == "a/src/Http.php") f = "http"; else if ($3 == "a/src/community/CommunityAuthz.php") f = "cauthz"; else if ($3 == "a/src/community/routes.php") f = "croutes"; next }
  f == "" { next }
  /^(\+\+\+|---) / { next }
  /^-/ { rm[f]++; next }
  /^\+/ { ad[f]++; next }
  END { printf "%d %d %d %d %d %d %d %d %d %d %d %d", rm["idx"], ad["idx"], rm["authz"], ad["authz"], rm["aud"], ad["aud"], rm["http"], ad["http"], rm["cauthz"], ad["cauthz"], rm["croutes"], ad["croutes"] }' "$T/patch")
echo "# @@HASHES-BEGIN@@"
echo "# 반영할 패치 = git diff $($G rev-parse --short "$B") <검토 태그> -- . ':!review-scripts' — 찍은 판 $($G rev-parse --short "$R") 과 내용이 같다(그 뒤 커밋은 review-scripts/ 만 바꾼다) ·"
echo "#   pins-g0-repo.sh 가 찍음 · 인자로 받은 sha256 과 **둘 다** 같아야 한다"
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
echo "# 라우트 — 토큰 창구 집합 셋 · 강제 절차 허용 목록 · 커뮤니티 라우트 표 줄(셋 다 반영 전후 같아야) · t-box 라우트 표 줄(반영 전 · 뒤)"
echo "TOKSETS_WANT=$TB"
echo "GATE_WANT=$GB"
echo "CROUTES_WANT=$CRB"
echo "TROUTES_BASE_WANT=$TRB"
echo "TROUTES_NEW_WANT=$TRR"
echo "# 더하는 라우트 열두 줄(글자 그대로 · 차례대로)의 sha256 · 줄 수"
echo "ROUTES_ADD_WANT=$ADD_SHA"
echo "ROUTES_ADD_N=$ADD_N"
echo "# 패치 파일 안에서 센 지운/더한 줄 — index · Authz · Audit · Http · CommunityAuthz · 커뮤니티 routes"
echo "CORE_WANT=\"$CORE\""
echo "# @@HASHES-END@@"
