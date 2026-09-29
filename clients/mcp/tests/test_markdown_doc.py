"""변환기 단위 시험 — 표준 라이브러리 unittest(pytest 로도 돈다). `mcp` 를 import 하지 않는다.

    cd clients/mcp && python3 -m unittest discover -s tests -v
"""

from __future__ import annotations

import json
import pathlib
import re
import sys
import unittest

PKG = pathlib.Path(__file__).resolve().parents[1]
sys.path.insert(0, str(PKG))

from qaredi_mcp import markdown_doc as md  # noqa: E402
from qaredi_mcp.markdown_doc import DocLimitError, doc_to_markdown, markdown_to_doc  # noqa: E402

UUID4 = re.compile(r"^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$")


def blocks(src: str) -> list[dict]:
    return markdown_to_doc(src)["blocks"]


def plain(block: dict) -> str:
    return "".join(s["t"] for s in block.get("text", []))


def strip_ids(x):
    if isinstance(x, dict):
        return {k: strip_ids(v) for k, v in x.items() if k != "id"}
    if isinstance(x, list):
        return [strip_ids(v) for v in x]
    return x


class SchemaCopy(unittest.TestCase):
    def test_schema_copy_matches_repo(self):
        repo = PKG.parents[1] / "public" / "assets" / "community" / "doc-schema.json"
        if not repo.exists():
            self.skipTest("저장소 밖에서 도는 중 — 사본 대조 생략")
        mine = PKG / "qaredi_mcp" / "doc-schema.json"
        self.assertEqual(json.loads(mine.read_text("utf-8")), json.loads(repo.read_text("utf-8")),
                         "qaredi_mcp/doc-schema.json 이 저장소 원본과 다르다 — 복사해 맞춘다")

    def test_limits_read_from_schema(self):
        self.assertEqual(md.LIMITS["blocks"], 3000)
        self.assertEqual(md.LIMITS["segment_chars"], 2000)
        self.assertIn("python", md.LANGUAGES)
        self.assertEqual(md.DEFAULT_LANGUAGE, "plain")


class Blocks(unittest.TestCase):
    def test_doc_shape(self):
        d = markdown_to_doc("안녕")
        self.assertEqual(set(d), {"schema", "blocks"})
        self.assertEqual(d["schema"], 1)
        b = d["blocks"][0]
        self.assertEqual(b["type"], "paragraph")
        self.assertRegex(b["id"], UUID4)
        self.assertEqual(b["text"], [{"t": "안녕", "m": {}}])
        for k in ("color", "align", "size"):
            self.assertNotIn(k, b)

    def test_empty_markdown_is_one_empty_paragraph(self):
        self.assertEqual(strip_ids(blocks("")), [{"type": "paragraph", "text": []}])

    def test_headings(self):
        bs = blocks("# 하나\n## 둘\n### 셋\n#### 넷 ####\n####### 일곱")
        self.assertEqual([(b["type"], b.get("level"), plain(b)) for b in bs], [
            ("heading", 1, "하나"), ("heading", 2, "둘"), ("heading", 3, "셋"), ("heading", 3, "넷"),
            ("paragraph", None, "####### 일곱"),
        ])

    def test_lists_and_nesting(self):
        bs = blocks("- a\n  - b\n    1. c\n* d\n1. e\n2) f")
        self.assertEqual([b["type"] for b in bs], ["bulleted", "bulleted", "numbered", "numbered"])
        self.assertEqual(plain(bs[0]["children"][0]), "b")
        self.assertEqual(bs[0]["children"][0]["children"][0]["type"], "numbered")
        self.assertNotIn("start", bs[2])

    def test_todo(self):
        bs = blocks("- [ ] 할 일\n- [x] 끝\n- [X] 대문자")
        self.assertEqual([(b["type"], b["checked"], plain(b)) for b in bs],
                         [("to_do", False, "할 일"), ("to_do", True, "끝"), ("to_do", True, "대문자")])

    def test_quote_with_children(self):
        bs = blocks("> 첫\n> 둘\n>\n> - 목록")
        self.assertEqual(bs[0]["type"], "quote")
        self.assertEqual(plain(bs[0]), "첫\n둘")
        self.assertEqual(bs[0]["children"][0]["type"], "bulleted")

    def test_code_language(self):
        bs = blocks("```py\nx = 1\n\tindented\n```\n```brainfuck\n+\n```\n```\nnone\n```")
        self.assertEqual([(b["type"], b["language"]) for b in bs],
                         [("code", "python"), ("code", "plain"), ("code", "plain")])
        self.assertEqual(bs[0]["code"], "x = 1\n\tindented")

    def test_unclosed_fence_runs_to_end(self):
        bs = blocks("```sql\nSELECT 1;\n\n# 제목 아님")
        self.assertEqual(len(bs), 1)
        self.assertEqual(bs[0]["code"], "SELECT 1;\n\n# 제목 아님")

    def test_divider(self):
        self.assertEqual([b["type"] for b in blocks("a\n\n---\n\n***\n\n___\n\nb")],
                         ["paragraph", "divider", "divider", "divider", "paragraph"])

    def test_table(self):
        bs = blocks("| h1 | h2 |\n|---|:-:|\n| a | `x\\|y` |\n| only |\n| 1 | 2 | 3 |")
        t = bs[0]
        self.assertEqual(t["type"], "table")
        self.assertTrue(t["header_row"])
        self.assertFalse(t["header_col"])
        widths = {len(r["cells"]) for r in t["rows"]}
        self.assertEqual(widths, {2})
        self.assertEqual(set(t["rows"][0]), {"cells"})
        self.assertEqual(t["rows"][1]["cells"][1]["text"], [{"t": "x|y", "m": {"code": True}}])
        self.assertEqual(plain(t["rows"][3]["cells"][1]), "2 | 3")   # 넘치는 칸은 잃지 않는다

    def test_unknown_syntax_kept_as_text(self):
        b = blocks("<div>html</div> 각주[^1] ![그림](https://i.example.com/a.png)")[0]
        self.assertEqual(b["type"], "paragraph")
        self.assertIn("<div>html</div>", plain(b))
        self.assertIn("[^1]", plain(b))
        img = [s for s in b["text"] if s["t"] == "그림"][0]
        self.assertEqual(img["m"], {"link": {"href": "https://i.example.com/a.png"}})

    def test_ids_unique_uuid4(self):
        d = markdown_to_doc("\n\n".join(["- a\n  - b", "> q", "```\nc\n```", "| a |\n|---|\n| b |"] * 5))
        ids = []

        def walk(bs):
            for b in bs:
                ids.append(b["id"])
                walk(b.get("children", []))
        walk(d["blocks"])
        self.assertEqual(len(ids), len(set(ids)))
        self.assertTrue(all(UUID4.match(i) for i in ids))


class Inline(unittest.TestCase):
    def segs(self, s):
        return [(x["t"], x["m"]) for x in md.inline_to_richtext(s)]

    def test_marks(self):
        self.assertEqual(self.segs("**b** *i* _j_ ~~s~~ `c`"), [
            ("b", {"bold": True}), (" ", {}), ("i", {"italic": True}), (" ", {}), ("j", {"italic": True}),
            (" ", {}), ("s", {"strike": True}), (" ", {}), ("c", {"code": True}),
        ])

    def test_bold_italic_combo_and_nesting(self):
        self.assertEqual(self.segs("***x***"), [("x", {"bold": True, "italic": True})])
        self.assertEqual(self.segs("**a *b* c**"),
                         [("a ", {"bold": True}), ("b", {"bold": True, "italic": True}), (" c", {"bold": True})])

    def test_snake_case_not_italic(self):
        self.assertEqual(self.segs("snake_case_name"), [("snake_case_name", {})])

    def test_unmatched_and_escaped(self):
        self.assertEqual(self.segs("a ** b \\*c\\*"), [("a ** b *c*", {})])

    def test_links(self):
        self.assertEqual(self.segs("[t](https://e.com/x?a=1)"), [("t", {"link": {"href": "https://e.com/x?a=1"}})])
        self.assertEqual(self.segs("[**b**](http://e.org)"), [("b", {"bold": True, "link": {"href": "http://e.org"}})])
        self.assertEqual(self.segs("<me@e.com>"), [("me@e.com", {"link": {"href": "mailto:me@e.com"}})])
        self.assertEqual(self.segs("see https://e.com/a)."),
                         [("see ", {}), ("https://e.com/a", {"link": {"href": "https://e.com/a"}}), (").", {})])

    def test_bad_scheme_not_linked(self):
        self.assertEqual(self.segs("[x](javascript:alert(1))"), [("x (javascript:alert(1))", {})])
        self.assertEqual(self.segs("[x](/relative)"), [("x (/relative)", {})])
        long_href = "https://e.com/" + "a" * 2100
        self.assertEqual(self.segs(f"[x]({long_href})")[0][1], {})

    def test_href_rules_match_server(self):
        ok = ["https://e.com", "http://e.com/a b".replace(" ", "%20"), "mailto:a@b",
              "page:1b4e28ba-2fa1-41d2-883f-0016d3cca427"]
        bad = ["https://", "ftp://e.com", "javascript:x", "https://e.com/a b", "mailto:ab", "data:x",
               "page:1B4E28BA-2fa1-41d2-883f-0016d3cca427"]
        for h in ok:
            self.assertTrue(md.href_ok(h), h)
        for h in bad:
            self.assertFalse(md.href_ok(h), h)

    def test_control_chars_and_tabs(self):
        self.assertEqual(self.segs("a\tb\x01c\x7fd\x85e"), [("a bcde", {})])

    def test_segment_split_2000(self):
        segs = md.inline_to_richtext("가" * 4500)
        self.assertEqual([len(s["t"]) for s in segs], [2000, 2000, 500])
        segs = md.inline_to_richtext("**" + "나" * 2500 + "**")
        self.assertEqual([(len(s["t"]), s["m"]) for s in segs], [(2000, {"bold": True}), (500, {"bold": True})])

    def test_astral_chars_counted_as_code_points(self):
        segs = md.inline_to_richtext("😀" * 2001)
        self.assertEqual([len(s["t"]) for s in segs], [2000, 1])


class Limits(unittest.TestCase):
    def assertLimit(self, src, limit):
        with self.assertRaises(DocLimitError) as cm:
            markdown_to_doc(src)
        self.assertEqual(cm.exception.limit, limit)
        self.assertTrue(str(cm.exception))

    def test_blocks(self):
        self.assertEqual(len(blocks("\n\n".join(["p"] * 3000))), 3000)
        self.assertLimit("\n\n".join(["p"] * 3001), "blocks")

    def test_nested_blocks_count(self):
        # 목록 1500 개 × 자식 하나 = 3000 블록(통과) · 하나 더 → 초과
        src = "\n".join(["- a\n  - b"] * 1500)
        self.assertEqual(len(blocks(src)), 1500)
        self.assertLimit(src + "\n- c", "blocks")

    def test_depth(self):
        nest = lambda k: "\n".join("  " * d + "- x" for d in range(k))  # noqa: E731
        markdown_to_doc(nest(10))
        self.assertLimit(nest(11), "depth")

    def test_segments(self):
        self.assertLimit(" ".join("**b** p" for _ in range(60)), "segments_per_text")
        with self.assertRaises(DocLimitError) as cm:
            markdown_to_doc(" ".join("**비밀본문** p" for _ in range(60)))
        self.assertNotIn("비밀본문", str(cm.exception))   # 오류 문장에 본문을 넣지 않는다

    def test_table(self):
        row = lambda k: "|" + "|".join("c" for _ in range(k)) + "|"  # noqa: E731
        dl = lambda k: "|" + "|".join("-" for _ in range(k)) + "|"  # noqa: E731
        markdown_to_doc("\n".join([row(20), dl(20)] + [row(20)] * 199))
        self.assertLimit("\n".join([row(21), dl(21)]), "table_cols")
        self.assertLimit("\n".join([row(2), dl(2)] + [row(2)] * 200), "table_rows")

    def test_code_and_bytes(self):
        self.assertLimit("```\n" + "x" * 50001 + "\n```", "code_chars")
        self.assertLimit("\n\n".join("```\n" + "x" * 49000 + "\n```" for _ in range(22)), "doc_bytes")


class RoundTrip(unittest.TestCase):
    SAMPLES = sorted((PKG / "tests" / "samples").glob("*.md"))

    def test_samples_exist(self):
        self.assertGreaterEqual(len(self.SAMPLES), 10)

    def test_markdown_roundtrip_is_stable(self):
        for p in self.SAMPLES:
            with self.subTest(sample=p.name):
                d1 = markdown_to_doc(p.read_text("utf-8"))
                m1 = doc_to_markdown(d1)
                d2 = markdown_to_doc(m1)
                m2 = doc_to_markdown(d2)
                self.assertEqual(m1, m2)
                self.assertEqual(strip_ids(d2), strip_ids(markdown_to_doc(m2)))

    def test_text_survives_roundtrip(self):
        src = "# 제목\n\n**굵게** *기울임* ~~취소~~ `a*b` [링크](https://e.com) \\*별\\* 1. 아님\n\n- [ ] 일\n\n| a | b |\n|---|---|\n| 1 | 2 |"
        d1 = markdown_to_doc(src)
        d2 = markdown_to_doc(doc_to_markdown(d1))
        self.assertEqual(strip_ids(d1), strip_ids(d2))

    def test_line_start_escape(self):
        d1 = markdown_to_doc("\\# 제목 아님\n\\- 목록 아님\n1\\. 번호 아님\n\\> 인용 아님")
        self.assertEqual([b["type"] for b in d1["blocks"]], ["paragraph"])
        d2 = markdown_to_doc(doc_to_markdown(d1))
        self.assertEqual(strip_ids(d1), strip_ids(d2))

    def test_read_unknown_blocks_does_not_crash(self):
        doc = {"schema": 1, "blocks": [
            {"id": "x", "type": "callout", "icon": "💡", "text": [{"t": "알림", "m": {}}]},
            {"id": "y", "type": "toc"}, {"id": "z", "type": "embed", "url": "https://e.com"},
            {"id": "w", "type": "image", "file_ref": "tbox:1b4e28ba-2fa1-41d2-883f-0016d3cca427"},
            {"id": "v", "type": "mystery"}, "not-a-block",
            {"id": "u", "type": "paragraph", "text": [{"t": "@", "m": {"mention": {"kind": "user", "id": 3}}}]},
        ]}
        out = doc_to_markdown(doc)
        self.assertIn("> 💡 알림", out)
        self.assertIn("[알 수 없는 블록: mystery]", out)
        self.assertIn("@[user:3]", out)


if __name__ == "__main__":
    unittest.main()
