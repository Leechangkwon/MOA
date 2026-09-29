#!/usr/bin/env bash
# clients/mcp/tests/validate_with_php.sh — 변환기 결과를 저장소의 서버 검증기(src/community/Doc.php)로 확인한다.
#
# Q:F-8 조각 C 완료 기준: 「validate_with_php.sh 통과(표본 10 이상 · 2000자 넘는 문단 쪼개기 · 3000 블록 상한)」.
#
# 길: 부트스트랩·DB 에 닿지 않는다. Doc.php 는 스스로 서 있는 클래스라(require 없음 · DB 없음 · 허용 목록은
#     `__DIR__/../../public/assets/community/doc-schema.json` 을 스스로 읽는다) 그 파일 하나만 require 한다.
#     흉내내는 것은 하나 — 문서 단계 `COMMUNITY_DOC_STAGE` 를 src/bootstrap.php 에서 **글자로 읽어** 넘긴다
#     (bootstrap.php 를 실행하지 않는다 · 값을 여기 적지 않는다).
#
# 쓰는 것: python3(표준 라이브러리) · php(CLI · json · iconv). 네트워크·DB 0. 임시 폴더는 끝나면 지운다.
set -u

HERE="$(cd "$(dirname "$0")" && pwd)"
PKG="$(cd "$HERE/.." && pwd)"
REPO="$(cd "$PKG/../.." && pwd)"
DOC_PHP="$REPO/src/community/Doc.php"
BOOT="$REPO/src/bootstrap.php"

[ -f "$DOC_PHP" ] || { echo "Doc.php 를 찾지 못했습니다: $DOC_PHP"; exit 2; }
STAGE="$(sed -n 's/^const COMMUNITY_DOC_STAGE = \([0-9][0-9]*\);.*/\1/p' "$BOOT" | head -1)"
[ -n "$STAGE" ] || { echo "COMMUNITY_DOC_STAGE 를 bootstrap.php 에서 읽지 못했습니다"; exit 2; }

WORK="$(mktemp -d "${TMPDIR:-/tmp}/qaredi-mcp-validate.XXXXXX")"
trap 'rm -rf "$WORK"' EXIT

echo "== 문서 단계 COMMUNITY_DOC_STAGE=$STAGE (bootstrap.php 에서 읽음) · 검증기 $DOC_PHP"

# ── 1) 변환: 표본 → JSON (기대: pass / fail:<까닭>) ──────────────────────────
PYTHONPATH="$PKG" python3 - "$HERE/samples" "$WORK" <<'PY' || { echo "변환 단계 실패"; exit 1; }
import json, sys, pathlib
from qaredi_mcp.markdown_doc import markdown_to_doc, doc_to_markdown, DocLimitError, new_id, LIMITS

samples, work = pathlib.Path(sys.argv[1]), pathlib.Path(sys.argv[2])
cases = []   # (이름, 문서, 기대)
bad = 0

def add(name, doc, expect="pass"):
    cases.append((name, doc, expect))

def expect_limit(name, md, limit):
    global bad
    try:
        markdown_to_doc(md)
    except DocLimitError as e:
        ok = e.limit == limit
        print(("OK  " if ok else "BAD ") + f"{name}: 변환기 오류 limit={e.limit} — {e}")
        bad += 0 if ok else 1
        return
    print(f"BAD {name}: 변환기가 {limit} 상한 오류를 내지 않았다")
    bad += 1

# 표본 파일 전부 + 되돌리기(블록 → Markdown → 블록)
for p in sorted(samples.glob("*.md")):
    doc = markdown_to_doc(p.read_text(encoding="utf-8"))
    add(p.stem, doc)
    add(p.stem + "~왕복", markdown_to_doc(doc_to_markdown(doc)))

# 2000자 넘는 문단 → 조각이 나뉜다(4500자 → 3조각)
long = "가" * 4500
doc = markdown_to_doc(long)
segs = doc["blocks"][0]["text"]
print(f"INFO long_paragraph: 4500자 문단 → 조각 {len(segs)}개 · 길이 {[len(s['t']) for s in segs]}")
add("long_paragraph_4500", doc)
# 꾸밈이 섞인 긴 문단(굵게 3000자) → 굵게 조각도 나뉜다
add("long_bold_3000", markdown_to_doc("앞 **" + "나" * 3000 + "** 뒤"))
# 제어문자·탭 → 정리돼 통과
add("control_chars", markdown_to_doc("탭\t과 \x01제어\x7f문자\x85 · 줄\n바꿈"))

# 블록 상한: 정확히 3000 은 통과 · 3001 은 변환기가 blocks 오류
add("blocks_3000", markdown_to_doc("\n\n".join(f"문단 {i}" for i in range(LIMITS["blocks"]))))
expect_limit("blocks_3001", "\n\n".join(f"문단 {i}" for i in range(LIMITS["blocks"] + 1)), "blocks")
# 같은 3001 을 변환기 검사 없이 만들어 서버도 막는지 본다(두 상한이 같은 값인지 — 기대 fail:too_many_blocks)
add("blocks_3001_forced", {"schema": 1, "blocks": [
    {"id": new_id(), "type": "paragraph", "text": [{"t": str(i), "m": {}}]} for i in range(LIMITS["blocks"] + 1)
]}, "fail:too_many_blocks")

# 깊이: 10 단계 통과 · 11 단계 변환기 depth 오류
nest = lambda k: "\n".join("  " * d + "- 깊이 " + str(d + 1) for d in range(k))
add("depth_10", markdown_to_doc(nest(10)))
expect_limit("depth_11", nest(11), "depth")

# 표: 20열·200행 통과 · 21열 / 201행 변환기 오류
row = lambda k: "| " + " | ".join("c%d" % j for j in range(k)) + " |"
delim = lambda k: "|" + "|".join("---" for _ in range(k)) + "|"
add("table_20x200", markdown_to_doc("\n".join([row(20), delim(20)] + [row(20)] * 199)))
expect_limit("table_21cols", "\n".join([row(21), delim(21), row(21)]), "table_cols")
expect_limit("table_201rows", "\n".join([row(2), delim(2)] + [row(2)] * 200), "table_rows")

# 조각 100개 상한 — 꾸밈 101번 번갈아 → 변환기 오류
expect_limit("segments_101", " ".join("**굵%d** 보통" % i for i in range(60)), "segments_per_text")

# 문서 1MB 상한 — 큰 코드 블록 여럿
big = "\n\n".join("```\n" + ("x" * 49000) + "\n```" for _ in range(22))
expect_limit("doc_bytes_over", big, "doc_bytes")
expect_limit("code_chars_over", "```\n" + "y" * 50001 + "\n```", "code_chars")

# 음성 대조 — 검증기가 정말 도는지: 탭이 든 조각은 서버가 control_char 로 막아야 한다
add("negative_control_tab", {"schema": 1, "blocks": [
    {"id": new_id(), "type": "paragraph", "text": [{"t": "a\tb", "m": {}}]}]}, "fail:control_char")

for k, (name, doc, expect) in enumerate(cases):
    (work / f"{k:03d}.json").write_text(json.dumps({"name": name, "expect": expect, "doc": doc}, ensure_ascii=False), encoding="utf-8")
print(f"INFO 변환 {len(cases)}건 · 변환기 상한 확인 실패 {bad}건")
sys.exit(1 if bad else 0)
PY

# ── 2) 검증: 저장소의 Doc::validate ─────────────────────────────────────────
php -d display_errors=stderr -r '
require $argv[1];
$stage = (int) $argv[2];
$pass = 0; $fail = 0;
foreach (glob($argv[3] . "/*.json") as $f) {
    $c = json_decode(file_get_contents($f), true, 512);
    $r = Doc::validate($c["doc"], $stage);
    $reasons = array_values(array_unique(array_map(fn($e) => $e["reason"], $r["errors"])));
    if ($c["expect"] === "pass") {
        $ok = $r["ok"];
    } else {
        $ok = !$r["ok"] && in_array(substr($c["expect"], 5), $reasons, true);
    }
    $n = count($c["doc"]["blocks"]);
    printf("%s %-24s 기대=%-22s 결과=%s%s (최상위 블록 %d)\n", $ok ? "OK  " : "BAD ", $c["name"], $c["expect"],
        $r["ok"] ? "ok" : "fail", $r["ok"] ? "" : ":" . implode(",", $reasons), $n);
    if (!$ok && $c["expect"] === "pass") {
        foreach (array_slice($r["errors"], 0, 5) as $e) { echo "      ", $e["path"], " ", $e["reason"], "\n"; }
    }
    $ok ? $pass++ : $fail++;
}
echo "== Doc::validate 결과: 통과 $pass · 실패 $fail\n";
exit($fail === 0 ? 0 : 1);
' "$DOC_PHP" "$STAGE" "$WORK"
