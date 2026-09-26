#!/usr/bin/env bash
# tests/wiki_check.sh — scripts/wiki_check.php 를 어느 자리에서든 돌리는 시험 틀 (카탈로그 core.wiki_check · 디버깅 묶음 T, 2026-09-26)
#
# wiki_check.php 의 ⑧(STAGES 0장 ✅ × 위키 단계 글)은 저장소 **바깥**의 사이트 문서 두 개를 읽는다 —
#   <사이트 루트>/STAGES.md · <사이트 루트>/community/STAGES.md (경로는 스크립트 자리 기준 `../../` 고정 — 운영 스크립트라 고치지 않는다).
# 운영 트리(/var/www/qaredi/tbox-v2)는 부모 폴더가 사이트 루트라 그대로 되지만, 복제본에는 부모 폴더에 그 두 파일이 없어
# 「파일이 없다」 문제 2건으로 실패했다(2026-09-26 기능 점검 — 환경 요인). 이 틀은 사이트 루트를 이렇게 정한다.
#
#   bash tests/wiki_check.sh [사이트 루트]
#   사이트 루트 = 인자 → 환경 변수 QAREDI_SITE_ROOT → 저장소 부모 폴더(두 파일이 있을 때만)
#   · 저장소 부모 폴더가 사이트 루트면 → 그대로 `php scripts/wiki_check.php`
#   · 다른 사이트 루트면 → 임시 폴더(0700)에 저장소 사본(app/)을 두고 그 형제 자리에 사이트 문서 두 개를 **읽기 링크**로 놓아 사본에서 돈다
#     (tests/f69.sh · tests/sec4.sh · tests/community/qf2_search.sh 와 같은 방식 — 사이트 문서는 읽기만)
#   · 사이트 루트를 못 찾으면 → 저장소에서 그대로 돌리되 **⑧ 의 사이트·커뮤니티 두 책만** 「환경 없음 — 건너뜀(실패 아님)」.
#     ①~⑦(구조 검사)과 t-box 책의 ⑧(저장소 안 STAGES.md)은 그대로 본다(독립 검토 L5). 문제가 그 두 줄 「(STAGES) — 파일이 없다」뿐이면
#     통과 1 · 건너뜀 1(RESULT pass=1 skip=1 · 종료 0 — 요약기는 ③ skipped_by_test), 다른 문제가 하나라도 있으면 실패.
#     운영 사이트 폴더로 저절로 넘어가지 않는다 — 복제본의 위키를 운영 STAGES 와 견주려면 QAREDI_SITE_ROOT=/var/www/qaredi 로 분명히 준다.
#
# 나가는 값: 0 통과·건너뜀 · 1 wiki_check 문제 · 2 사용법(없는 사이트 루트를 분명히 줌) · 끝 줄 `RESULT pass= fail= skip=`
set -u
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PARENT="$(cd "$ROOT/.." && pwd)"
has_site_docs() { [ -f "$1/STAGES.md" ] && [ -f "$1/community/STAGES.md" ]; }

SITE="${1:-${QAREDI_SITE_ROOT:-}}"
if [ -n "$SITE" ]; then
  has_site_docs "$SITE" || { echo "사이트 루트 $SITE 에 STAGES.md · community/STAGES.md 가 없다 — 시작하지 않는다"; echo "RESULT pass=0 fail=0 skip=1"; exit 2; }
  SITE="$(cd "$SITE" && pwd)"
elif has_site_docs "$PARENT"; then
  SITE="$PARENT"
else
  echo "환경 없음 — 사이트 문서(<사이트 루트>/STAGES.md · community/STAGES.md)를 찾지 못했다: 저장소 부모 $PARENT 에 없고 인자·QAREDI_SITE_ROOT 도 없다"
  echo "①~⑦ 과 t-box 책 ⑧ 은 돌리고, 사이트·커뮤니티 책 ⑧ 만 건너뛴다(실패 아님) — 모두 보려면: QAREDI_SITE_ROOT=<사이트 루트> bash tests/wiki_check.sh"
  out=$(php "$ROOT/scripts/wiki_check.php" 2>&1); rc=$?
  printf '%s\n' "$out"
  probs=$(printf '%s\n' "$out" | grep -E '^  x ' || true)
  n_all=$(printf '%s\n' "$probs" | grep -c . || true)
  n_env=$(printf '%s\n' "$probs" | grep -cE '^  x (community|qaredi) ← .*/STAGES\.md : \(STAGES\) — 파일이 없다' || true)
  if [ "$rc" = 0 ]; then
    echo "RESULT pass=1 fail=0 skip=0"; exit 0
  elif [ "$rc" = 1 ] && [ "$n_all" -gt 0 ] && [ "$n_all" = "$n_env" ]; then
    echo "①~⑦ · t-box 책 ⑧ 통과 — 문제 ${n_all}건은 모두 사이트·커뮤니티 STAGES 「파일이 없다」(환경 없음 — 건너뜀)"
    echo "RESULT pass=1 fail=0 skip=1"; exit 0
  fi
  echo "문제가 있다 — 환경 몫 ${n_env}건 밖 $((n_all - n_env))건(또는 실행 오류 rc $rc)"
  echo "RESULT pass=0 fail=1 skip=0"
  [ "$rc" = 0 ] && exit 1
  exit "$rc"
fi

if [ "$SITE" = "$PARENT" ]; then
  echo "사이트 루트: $SITE (저장소 부모 — 그대로)"
  php "$ROOT/scripts/wiki_check.php"; rc=$?
else
  WORK="$(mktemp -d)"; chmod 700 "$WORK"
  trap 'rm -rf "$WORK"' EXIT
  mkdir -p "$WORK/app"
  tar -C "$ROOT" -cf - --exclude=.git --exclude=jar --exclude=api-test.txt --exclude=./.env . | tar -C "$WORK/app" -xf - \
    || { echo "저장소 사본을 만들지 못했다"; echo "RESULT pass=0 fail=0 skip=1"; exit 2; }
  ln -s "$SITE/STAGES.md" "$WORK/STAGES.md"
  ln -s "$SITE/community" "$WORK/community"
  echo "사이트 루트: $SITE (저장소 사본 $WORK/app 의 형제 자리에 읽기 링크 둘)"
  php "$WORK/app/scripts/wiki_check.php"; rc=$?
fi
if [ "$rc" = 0 ]; then echo "RESULT pass=1 fail=0 skip=0"; else echo "RESULT pass=0 fail=1 skip=0"; fi
exit "$rc"
