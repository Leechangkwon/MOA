#!/usr/bin/env bash
# scripts/vendor_prosemirror.sh — ProseMirror 를 **node 없이** 저장소 안으로 들인다
#
# 요구사항: 커뮤니티 `C:S-12`(공급망), `../../community/ARCHITECTURE.md` 21.3,
#           사이트 `CLAUDE.md` 4장 「외부 코드는 deploy/DEPENDENCIES.md 에 버전·라이선스·sha256」
#
# 무엇을 하나
#   ① registry 에서 **고정 버전** tarball 을 받는다 (https://registry.npmjs.org)
#   ② 받은 tarball 의 sha256 을 계산해 `deploy/DEPENDENCIES.md` 의 값과 **대조**한다
#   ③ 패키지의 ESM 진입 파일(대개 `dist/index.js`) 하나만 꺼낸다
#   ④ 그 안의 **bare import** 를 상대 경로로 바꾼다 — `from "prosemirror-model"`
#      → `from "./prosemirror-model.js"`
#   ⑤ `public/assets/community/vendor/pm/<패키지>.js` 로 둔다
#
# 왜 이렇게 하나
#   · 이 VM 에 **node 를 설치하지 않는다**(공급망 표면·운영 부담). 그래서 번들러를 쓰지 않고
#     각 패키지의 ESM 파일을 그대로 두고 브라우저가 서로를 불러 쓰게 한다.
#   · **import map 을 쓰지 않는다.** import map 은 인라인 `<script type="importmap">` 이 필요한데
#     CSP 가 `script-src 'self'` 다. bare import 를 미리 상대 경로로 바꿔 두면 아무 장치 없이
#     브라우저가 그대로 읽는다.
#   · **CDN 을 쓰지 않는다.** 파일은 저장소 안에 고정되고, 바뀌면 sha256 대조가 깨진다.
#
# 쓰는 법
#   bash scripts/vendor_prosemirror.sh            # 받아서 vendor/pm 에 둔다 (DEPENDENCIES.md 표도 갱신)
#   bash scripts/vendor_prosemirror.sh --verify   # 받지만 **쓰지 않는다** — 해시·산출물 일치만 본다
#
# 나가는 값: 0 통과 · 1 불일치 · 2 준비물 없음
set -u

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT_DIR="$ROOT/public/assets/community/vendor/pm"
DEPS_FILE="$ROOT/deploy/DEPENDENCIES.md"
REGISTRY="https://registry.npmjs.org"
BEGIN_MARK="<!-- PM-VENDOR-BEGIN -->"
END_MARK="<!-- PM-VENDOR-END -->"

MODE="write"
if [ "${1:-}" = "--verify" ]; then
  MODE="verify"
elif [ -n "${1:-}" ]; then
  echo "쓰는 법: $0 [--verify]" >&2
  exit 2
fi

for tool in curl tar sha256sum sed awk; do
  command -v "$tool" >/dev/null 2>&1 || { echo "필요한 도구가 없다: $tool" >&2; exit 2; }
done

# ─── 고정 버전 (커뮤니티 ARCHITECTURE.md 21.3 — 2026-09-22 registry 최신, 전부 MIT) ───
#     형식: 패키지:버전:tarball 안의 ESM 진입 경로
PKGS="
prosemirror-model:1.25.12:package/dist/index.js
prosemirror-state:1.4.4:package/dist/index.js
prosemirror-view:1.42.5:package/dist/index.js
prosemirror-transform:1.12.1:package/dist/index.js
prosemirror-commands:1.7.2:package/dist/index.js
prosemirror-keymap:1.2.3:package/dist/index.js
prosemirror-history:1.5.0:package/dist/index.js
prosemirror-inputrules:1.5.1:package/dist/index.js
prosemirror-dropcursor:1.8.3:package/dist/index.js
prosemirror-gapcursor:1.4.1:package/dist/index.js
prosemirror-schema-list:1.5.1:package/dist/index.js
orderedmap:2.1.1:package/dist/index.js
w3c-keyname:2.2.8:package/index.js
rope-sequence:1.3.4:package/dist/index.js
"

# 위 목록의 패키지 이름만 (bare import 치환 대상)
NAMES=$(printf '%s\n' "$PKGS" | sed '/^$/d' | cut -d: -f1)

TMP="$(mktemp -d)"
cleanup() { rm -rf "$TMP"; }
trap cleanup EXIT

fail=0
rows=""
n=0

# DEPENDENCIES.md 에 적힌 sha256 을 꺼낸다 (없으면 빈 문자열)
recorded_sha() {
  awk -v pkg="$1" -F'|' '
    $0 ~ /^\| `/ {
      name = $2; gsub(/[ `]/, "", name);
      if (name == pkg) { sha = $5; gsub(/[ `]/, "", sha); print sha; exit }
    }' "$DEPS_FILE" 2>/dev/null
}

echo "== ProseMirror vendoring ($MODE) =="
echo "   받는 곳: $REGISTRY"
echo "   두는 곳: $OUT_DIR"
echo

for spec in $PKGS; do
  pkg="${spec%%:*}"
  rest="${spec#*:}"
  ver="${rest%%:*}"
  entry="${rest#*:}"
  n=$((n + 1))

  url="$REGISTRY/$pkg/-/$pkg-$ver.tgz"
  tgz="$TMP/$pkg-$ver.tgz"

  printf '── %-26s %-10s\n' "$pkg" "$ver"
  printf '   내려받기: %s\n' "$url"
  if ! curl -fsSL --max-time 60 -o "$tgz" "$url"; then
    echo "   ✖ 내려받기 실패"
    fail=1
    continue
  fi

  sha="$(sha256sum "$tgz" | awk '{print $1}')"
  printf '   sha256   : %s\n' "$sha"

  old="$(recorded_sha "$pkg")"
  if [ -n "$old" ]; then
    if [ "$old" = "$sha" ]; then
      printf '   대조     : ✔ DEPENDENCIES.md 의 값과 같다\n'
    else
      printf '   대조     : ✖ DEPENDENCIES.md 의 값과 다르다 (기록=%s)\n' "$old"
      fail=1
    fi
  else
    printf '   대조     : (기록 없음 — 이번에 적는다)\n'
    if [ "$MODE" = "verify" ]; then
      fail=1
    fi
  fi

  # ③ 진입 파일 하나만 꺼낸다
  if ! tar xzf "$tgz" -C "$TMP" "$entry" 2>/dev/null; then
    echo "   ✖ tarball 안에 $entry 가 없다"
    fail=1
    continue
  fi
  src="$TMP/$entry"

  # ④ bare import → 상대 경로. `from "x"` · `from 'x'` · `import "x"` 세 모양을 본다.
  work="$TMP/$pkg.js"
  cp "$src" "$work"
  for name in $NAMES; do
    sed -i \
      -e "s|from \"$name\"|from \"./$name.js\"|g" \
      -e "s|from '$name'|from './$name.js'|g" \
      -e "s|import \"$name\"|import \"./$name.js\"|g" \
      -e "s|import '$name'|import './$name.js'|g" \
      "$work"
  done
  rm -rf "$TMP/package"

  # 남은 bare import 가 있으면 목록에 없는 의존이라는 뜻이다 — 조용히 넘어가지 않는다.
  if grep -nE "(from|import)[[:space:]]*[\"'][a-zA-Z@][^\"']*[\"']" "$work" \
     | grep -vE "[\"']\./" >/dev/null 2>&1; then
    echo "   ✖ 상대 경로로 바뀌지 않은 import 가 남았다:"
    grep -nE "(from|import)[[:space:]]*[\"'][a-zA-Z@][^\"']*[\"']" "$work" | grep -vE "[\"']\./" | sed 's/^/      /'
    fail=1
  fi

  dest="$OUT_DIR/$pkg.js"
  if [ "$MODE" = "write" ]; then
    mkdir -p "$OUT_DIR"
    mv "$work" "$dest"
    printf '   산출물   : %s (%s 바이트)\n' "${dest#"$ROOT/"}" "$(wc -c < "$dest" | tr -d ' ')"
  else
    if [ ! -f "$dest" ]; then
      echo "   ✖ 산출물이 없다: ${dest#"$ROOT/"}"
      fail=1
    elif cmp -s "$work" "$dest"; then
      printf '   산출물   : ✔ 지금 만든 것과 저장소의 파일이 **바이트까지 같다**\n'
    else
      echo "   ✖ 산출물이 다르다 — 저장소의 파일이 손으로 고쳐졌거나 절차가 바뀌었다"
      fail=1
    fi
  fi

  rows="$rows| \`$pkg\` | $ver | MIT | \`$sha\` |
"
  echo
done

# ─── bare import 0건 (산출물 전체) ──────────────────────────────
echo "── 산출물 점검"
if [ -d "$OUT_DIR" ]; then
  left=$(grep -rE 'from "[a-z@]' "$OUT_DIR" 2>/dev/null | wc -l | tr -d ' ')
  printf '   bare import (from "…") : %s 건 (0 이어야 한다)\n' "$left"
  [ "$left" = "0" ] || fail=1
  count=$(find "$OUT_DIR" -maxdepth 1 -name '*.js' | wc -l | tr -d ' ')
  printf '   파일 수                : %s (기대 %s)\n' "$count" "$n"
  [ "$count" = "$n" ] || fail=1
else
  echo "   ✖ $OUT_DIR 가 없다"
  fail=1
fi

# ─── DEPENDENCIES.md 표 갱신 (write 모드에서만) ─────────────────
if [ "$MODE" = "write" ]; then
  if grep -q "$BEGIN_MARK" "$DEPS_FILE" && grep -q "$END_MARK" "$DEPS_FILE"; then
    awk -v begin="$BEGIN_MARK" -v end="$END_MARK" -v rows="$rows" '
      $0 == begin { print; print "| 패키지 | 버전 | 라이선스 | tarball sha256 |"; print "|---|---|---|---|"; printf "%s", rows; skip = 1; next }
      $0 == end   { skip = 0 }
      !skip { print }
    ' "$DEPS_FILE" > "$TMP/deps.md" && mv "$TMP/deps.md" "$DEPS_FILE"
    echo "   DEPENDENCIES.md 표을 갱신했다 ($BEGIN_MARK ~ $END_MARK 사이)"
  else
    echo "   ✖ DEPENDENCIES.md 에 $BEGIN_MARK / $END_MARK 표시가 없다 — 표를 갱신하지 못했다"
    fail=1
  fi
fi

echo
if [ "$fail" -eq 0 ]; then
  echo "결과: 통과"
else
  echo "결과: 실패 — 위 ✖ 를 확인한다"
fi
exit "$fail"
