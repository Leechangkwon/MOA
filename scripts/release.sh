#!/usr/bin/env bash
# scripts/release.sh — 발행 한 번 = 커밋 하나 + 태그 하나 (PRD F-47, ARCHITECTURE 5.23)
#
# 사용: scripts/release.sh <major|minor|patch> "<제목>" "<변경 1>" ["<변경 2>" …] [--no-push] [--dry-run]
#       scripts/release.sh --bump <X.Y.Z> <major|minor|patch>     # 번호 계산만 출력(시험용)
#
# 하는 일 (ARCH 5.23 차례 그대로)
#   ⓪ 작업 트리가 지저분하면(커밋하지 않은 수정·스테이징) 거부한다 — 기능 커밋을 먼저 하고 발행한다.
#      추적되지 않는 파일(??)은 허용한다.
#   ① VERSION 을 읽어 다음 번호를 계산하고, 같은 이름의 태그가 이미 있으면 거부한다.
#   ② CHANGELOG.json 맨 앞에 항목을 넣는다.
#   ③ VERSION 을 갱신하고 CHANGELOG.md 를 다시 만든다.
#   ④ 세 파일만 지정해 스테이징하고 커밋·태그를 만든다.
#   ⑤ origin 에 main 과 태그를 올린다 (--no-push 면 건너뛴다).
#
# 규칙
#   · 스테이징은 **파일을 지정해서만** 한다. 전체 추가 옵션은 쓰지 않는다 (CLAUDE.md 6장 —
#     비밀 정보 실수 커밋 방지).
#   · 제목·변경 문장으로 JSON 을 **셸 문자열 결합으로 만들지 않는다.** php 에 인자로 넘기고
#     json_encode 가 만든다 — 따옴표·역슬래시·개행이 섞여도 깨지지 않는다.
#   · 문장에 `<` 는 넣지 않는다. 화면에는 글자로 나오지만(모든 값이 htmlspecialchars 를 거친다)
#     이력 문장은 사람 말로만 적는 것이 규칙이다 (ARCH 5.19 와 같은 규칙).
#
# 종료 코드: 0 정상 · 1 거부(더러운 트리 · 태그 중복 · 인자 오류 · 실행 실패)
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"

usage() {
  cat >&2 <<'EOF'
사용: scripts/release.sh <major|minor|patch> "<제목>" "<변경 1>" ["<변경 2>" …] [--no-push] [--dry-run]
      scripts/release.sh --bump <X.Y.Z> <major|minor|patch>     # 번호 계산만 출력(시험용)
EOF
}

# 다음 번호 하나를 표준출력에 찍는다. $1 지금 번호  $2 등급
#   major → X+1.0.0 · minor → X.Y+1.0 · patch → X.Y.Z+1   (위 자리가 오르면 아래는 0)
bump_version() {
  local cur="$1" level="$2" rest x y z
  if ! printf '%s' "$cur" | grep -qE '^[0-9]+\.[0-9]+\.[0-9]+$'; then
    printf '버전 형식이 X.Y.Z 가 아닙니다: %s\n' "$cur" >&2
    return 1
  fi
  x="${cur%%.*}"; rest="${cur#*.}"; y="${rest%%.*}"; z="${rest#*.}"
  case "$level" in
    major) printf '%s.0.0\n'    "$((10#$x + 1))" ;;
    minor) printf '%s.%s.0\n'   "$((10#$x))" "$((10#$y + 1))" ;;
    patch) printf '%s.%s.%s\n'  "$((10#$x))" "$((10#$y))" "$((10#$z + 1))" ;;
    *) printf '등급은 major·minor·patch 중 하나입니다: %s\n' "$level" >&2; return 1 ;;
  esac
}

# ── 시험용 갈래 — 번호 계산만 한다. 파일도 git 도 건드리지 않는다 ──
if [ "${1-}" = "--bump" ]; then
  if [ "$#" -ne 3 ]; then usage; exit 1; fi
  bump_version "$2" "$3"
  exit 0
fi

# ── 인자 ─────────────────────────────────────────────────────
NO_PUSH=0
DRY_RUN=0
POS=()
for arg in "$@"; do
  case "$arg" in
    --no-push) NO_PUSH=1 ;;
    --dry-run) DRY_RUN=1 ;;
    --bump)    printf -- '--bump 은 첫 인자로만 씁니다.\n' >&2; usage; exit 1 ;;
    --*)       printf '모르는 옵션입니다: %s\n' "$arg" >&2; usage; exit 1 ;;
    *)         POS+=("$arg") ;;
  esac
done

if [ "${#POS[@]}" -lt 3 ]; then
  printf '등급·제목·변경 문장 하나는 반드시 있어야 합니다.\n' >&2
  usage
  exit 1
fi

LEVEL="${POS[0]}"
TITLE="${POS[1]}"
CHANGES=("${POS[@]:2}")

case "$LEVEL" in
  major|minor|patch) ;;
  *) printf '등급은 major·minor·patch 중 하나입니다: %s\n' "$LEVEL" >&2; exit 1 ;;
esac

for line in "$TITLE" "${CHANGES[@]}"; do
  if [ -z "$line" ]; then
    printf '빈 제목·빈 문장은 넣을 수 없습니다.\n' >&2
    exit 1
  fi
  case "$line" in
    *'<'*) printf '제목·변경 문장에 < 를 넣지 않습니다: %s\n' "$line" >&2; exit 1 ;;
  esac
done

# ── ⓪ 작업 트리 확인 ─────────────────────────────────────────
DIRTY="$(git -C "$ROOT" status --porcelain | grep -v '^??' || true)"
if [ -n "$DIRTY" ]; then
  printf '커밋하지 않은 변경이 있습니다 — 먼저 커밋하세요:\n' >&2
  printf '%s\n' "$DIRTY" >&2
  exit 1
fi

# ── ① 다음 번호 · 태그 중복 ──────────────────────────────────
CURRENT="$(tr -d '[:space:]' < "$ROOT/VERSION")"
NEXT="$(bump_version "$CURRENT" "$LEVEL")"

if git -C "$ROOT" rev-parse -q --verify "refs/tags/v$NEXT" >/dev/null 2>&1; then
  printf '태그 v%s 가 이미 있습니다 — 이미 발행된 번호입니다.\n' "$NEXT" >&2
  exit 1
fi

WHEN="$(date '+%Y-%m-%d %H:%M')"

# 새 항목을 만든다. $1 이 print 면 찍기만 하고, write 면 CHANGELOG.json 맨 앞에 넣는다.
# 제목·변경 문장은 **인자로만** 넘어간다 — 셸이 JSON 글자를 만들지 않는다.
changelog_entry() {
  php -r '
    $mode    = $argv[1];
    $root    = $argv[2];
    $entry   = [
        "version"     => $argv[3],
        "level"       => $argv[4],
        "released_at" => $argv[5],
        "title"       => $argv[6],
        "changes"     => array_values(array_slice($argv, 7)),
    ];
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT;
    if ($mode === "print") {
        echo json_encode($entry, $flags), "\n";
        exit(0);
    }
    $file = $root . "/CHANGELOG.json";
    $raw  = @file_get_contents($file);
    if ($raw === false) { fwrite(STDERR, "CHANGELOG.json 을 읽지 못했습니다\n"); exit(1); }
    $list = json_decode($raw, true);
    if (!is_array($list)) { fwrite(STDERR, "CHANGELOG.json 이 배열이 아닙니다\n"); exit(1); }
    array_unshift($list, $entry);
    $json = json_encode($list, $flags);
    if ($json === false) { fwrite(STDERR, "JSON 으로 바꾸지 못했습니다\n"); exit(1); }
    if (file_put_contents($file, $json . "\n") === false) {
        fwrite(STDERR, "CHANGELOG.json 을 쓰지 못했습니다\n"); exit(1);
    }
  ' "$1" "$ROOT" "$NEXT" "$LEVEL" "$WHEN" "$TITLE" "${CHANGES[@]}"
}

# 커밋 메시지 — 첫 줄은 언제나 `vX.Y.Z: 제목`
commit_message() {
  printf 'v%s: %s\n\n' "$NEXT" "$TITLE"
  local line
  for line in "${CHANGES[@]}"; do
    printf -- '- %s\n' "$line"
  done
  printf '\nCo-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>\n'
}

# ── --dry-run — 파일도 git 도 하나도 건드리지 않는다 ─────────
if [ "$DRY_RUN" -eq 1 ]; then
  printf '[미리보기] 파일과 git 을 하나도 건드리지 않습니다.\n\n'
  printf '  지금 버전 : %s\n' "$CURRENT"
  printf '  다음 버전 : %s  (등급 %s)\n' "$NEXT" "$LEVEL"
  printf '  발행 시각 : %s\n\n' "$WHEN"
  printf '  CHANGELOG.json 맨 앞에 넣을 항목\n'
  changelog_entry print | sed 's/^/    /'
  printf '\n  실행할 git 명령\n'
  printf '    git add VERSION CHANGELOG.json CHANGELOG.md\n'
  printf '    git commit -m "v%s: %s" (본문에 변경 %s줄)\n' "$NEXT" "$TITLE" "${#CHANGES[@]}"
  printf '    git tag -a v%s -m "v%s — %s"\n' "$NEXT" "$NEXT" "$TITLE"
  if [ "$NO_PUSH" -eq 1 ]; then
    printf '    (push 생략 — --no-push)\n'
  else
    printf '    git push origin main\n'
    printf '    git push origin v%s\n' "$NEXT"
  fi
  exit 0
fi

# ── ② CHANGELOG.json ─────────────────────────────────────────
changelog_entry write

# ── ③ VERSION · CHANGELOG.md ─────────────────────────────────
printf '%s\n' "$NEXT" > "$ROOT/VERSION"
php "$ROOT/scripts/changelog_md.php" > "$ROOT/CHANGELOG.md"

# ── ④ 커밋 · 태그 (파일을 지정해서만 스테이징한다) ───────────
git -C "$ROOT" add VERSION CHANGELOG.json CHANGELOG.md
git -C "$ROOT" commit -q -F - <<EOF
$(commit_message)
EOF
git -C "$ROOT" tag -a "v$NEXT" -m "v$NEXT — $TITLE"

# ── ⑤ push ───────────────────────────────────────────────────
if [ "$NO_PUSH" -eq 1 ]; then
  PUSHED='생략'
  printf '원격에 올리지 않았습니다 — 나중에: git push origin main && git push origin v%s\n' "$NEXT"
else
  git -C "$ROOT" push origin main
  git -C "$ROOT" push origin "v$NEXT"
  PUSHED='완료'
fi

HASH="$(git -C "$ROOT" rev-parse --short HEAD)"
printf '발행 완료: v%s (커밋 %s, 태그 push %s)\n' "$NEXT" "$HASH" "$PUSHED"
