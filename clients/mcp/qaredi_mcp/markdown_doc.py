"""Markdown ↔ Qaredi 커뮤니티 블록 문서 변환기 (Q:F-8 조각 C).

표준 라이브러리만 쓴다 — `mcp` 패키지 없이도 단위 시험이 돌게(지시서 · 조각 C).

모양의 진실은 `doc-schema.json` 한 벌이다. 이 패키지 안의 `doc-schema.json` 은 저장소
`public/assets/community/doc-schema.json` 의 **사본**이고(PC 에 설치되면 저장소가 없다),
단위 시험이 두 파일이 같은지 본다. 검증은 서버(`src/community/Doc.php`) 한 곳이 한다 —
이 변환기는 그 검증을 **통과하는 모양**을 만들 뿐이고, 상한을 넘으면 어느 상한인지 말하는
오류(`DocLimitError`)를 낸다.

리치 텍스트 조각은 Doc.php `richtext()` 그대로 `{"t": 글자, "m": {마크}}` 이다(`m` 은 비어도 있다).
마크: bold·italic·strike·code 는 `true`, 링크는 `{"href": 주소}`. color·align·size 는 넣지 않는다.

HTML 은 만들지 않는다(블록 JSON 만). 모르는 문법(HTML 태그·각주·이미지)은 본문 글자로 남긴다.
"""

from __future__ import annotations

import json
import re
import uuid
from importlib import resources
from typing import Any

# ─── 허용 목록(사본) ─────────────────────────────────────────────────────────


def _load_schema() -> dict[str, Any]:
    raw = resources.files(__package__).joinpath("doc-schema.json").read_text(encoding="utf-8")
    data = json.loads(raw)
    for key in ("schema", "limits", "languages", "link_schemes", "blocks"):
        if key not in data:
            raise RuntimeError("doc-schema.json 에 키가 없습니다 — " + key)
    return data


SCHEMA: dict[str, Any] = _load_schema()
LIMITS: dict[str, int] = {k: int(v) for k, v in SCHEMA["limits"].items()}
LANGUAGES: frozenset[str] = frozenset(SCHEMA["languages"])
LINK_SCHEMES: frozenset[str] = frozenset(SCHEMA["link_schemes"])
DEFAULT_LANGUAGE = "plain" if "plain" in LANGUAGES else SCHEMA["languages"][0]

# 흔한 줄임 이름 → 스키마의 이름. 결과가 스키마 목록에 없으면 기본값으로 간다.
_LANG_ALIASES = {
    "js": "javascript", "jsx": "javascript", "mjs": "javascript", "node": "javascript",
    "ts": "typescript", "tsx": "typescript",
    "py": "python", "python3": "python",
    "sh": "bash", "zsh": "bash", "console": "shell", "shellscript": "shell",
    "yml": "yaml", "md": "markdown", "c++": "cpp", "cc": "cpp", "hpp": "cpp", "h": "c",
    "c#": "csharp", "cs": "csharp", "kt": "kotlin", "rb": "ruby", "rs": "rust",
    "golang": "go", "ps1": "powershell", "pwsh": "powershell", "objc": "objectivec",
    "docker": "dockerfile", "make": "makefile", "tex": "latex", "text": "plain", "txt": "plain",
    "plaintext": "plain", "vb": "vbnet", "patch": "diff", "psql": "sql", "postgresql": "sql",
}


class DocLimitError(ValueError):
    """문서가 스키마 상한을 넘었다. `limit` 은 doc-schema.json `limits` 의 이름이다."""

    def __init__(self, limit: str, message: str) -> None:
        super().__init__(message)
        self.limit = limit


def new_id() -> str:
    """블록 id — UUIDv4 소문자(Doc.php 정규식 `…-4…-[89ab]…`)."""
    return str(uuid.uuid4())


# ─── 글자 정리 ───────────────────────────────────────────────────────────────

# Doc.php: 조각 글자는 \n 밖의 제어문자(Cc) 금지 · 코드는 \n·\t 밖의 Cc 금지.
_CC_TEXT = re.compile(r"[\x00-\x09\x0b-\x1f\x7f-\x9f]")
_CC_CODE = re.compile(r"[\x00-\x08\x0b-\x1f\x7f-\x9f]")


def _clean_text(s: str) -> str:
    s = s.replace("\t", " ")
    return _CC_TEXT.sub("", s)


def _clean_code(s: str) -> str:
    return _CC_CODE.sub("", s)


# ─── 링크 주소 — Doc::href · Doc::schemeOk 와 같은 규칙(바이트 단위로 본다: PHP 정규식이 /u 없이 돈다) ───

_HREF_RULES = {
    "https": re.compile(rb"https://[^ \t\n\x0b\x0c\r/?#]+[^ \t\n\x0b\x0c\r]*"),
    "http": re.compile(rb"http://[^ \t\n\x0b\x0c\r/?#]+[^ \t\n\x0b\x0c\r]*"),
    "mailto": re.compile(rb"mailto:[^ \t\n\x0b\x0c\r@]{1,128}@[^ \t\n\x0b\x0c\r@]{1,128}"),
    "page": re.compile(rb"page:[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}"),
}


def href_ok(href: str) -> bool:
    """링크 마크로 넣어도 서버 검증을 지나는 주소인가."""
    b = href.encode("utf-8", "surrogatepass")
    if len(b) > LIMITS["href_chars"]:
        return False
    if re.search(rb"[\x00-\x1f\x7f]", b):
        return False
    for scheme, rule in _HREF_RULES.items():
        if scheme in LINK_SCHEMES and rule.fullmatch(b):
            return True
    return False


# ─── 인라인 ─────────────────────────────────────────────────────────────────

_ASCII_PUNCT = frozenset("!\"#$%&'()*+,-./:;<=>?@[\\]^_`{|}~")
_EMPH = (
    ("***", {"bold": True, "italic": True}),
    ("___", {"bold": True, "italic": True}),
    ("**", {"bold": True}),
    ("__", {"bold": True}),
    ("~~", {"strike": True}),
    ("*", {"italic": True}),
    ("_", {"italic": True}),
)
_AUTOLINK = re.compile(r"<((?:https?://|mailto:)[^\s<>]+)>")
_AUTOMAIL = re.compile(r"<([^\s<>@]+@[^\s<>@]+\.[^\s<>@]+)>")
_BARE_URL = re.compile(r"https?://[^\s<>]+")

Seg = tuple[str, dict[str, Any]]


def _run_len(s: str, i: int, ch: str) -> int:
    j = i
    while j < len(s) and s[j] == ch:
        j += 1
    return j - i


def _code_span_end(s: str, i: int) -> tuple[int, int]:
    """s[i] 가 백틱이면 (닫는 백틱 위치, 백틱 수). 닫힘이 없으면 (-1, 수)."""
    run = _run_len(s, i, "`")
    j = i + run
    while True:
        k = s.find("`" * run, j)
        if k < 0:
            return -1, run
        r2 = _run_len(s, k, "`")
        if r2 == run:
            return k, run
        j = k + r2


def _find_closer(s: str, start: int, delim: str) -> int:
    ch = delim[0]
    i = start
    n = len(s)
    while i < n:
        c = s[i]
        if c == "\\":
            i += 2
            continue
        if c == "`":
            end, run = _code_span_end(s, i)
            i = (end + run) if end >= 0 else (i + run)
            continue
        if c == ch:
            run = _run_len(s, i, ch)
            ok_len = (run >= len(delim)) and not (len(delim) == 1 and run == 2) and not (len(delim) == 2 and run == 1)
            if ok_len and i > start and not s[i - 1].isspace():
                after = i + run
                if ch != "_" or after >= n or not s[after].isalnum():
                    return i + run - len(delim)
            i += run
            continue
        i += 1
    return -1


def _bracket_end(s: str, i: int) -> int:
    """s[i]=='[' 의 짝 `]` 위치(중첩·이스케이프·코드 고려). 없으면 -1."""
    depth = 0
    j = i
    while j < len(s):
        c = s[j]
        if c == "\\":
            j += 2
            continue
        if c == "`":
            end, run = _code_span_end(s, j)
            j = (end + run) if end >= 0 else (j + run)
            continue
        if c == "[":
            depth += 1
        elif c == "]":
            depth -= 1
            if depth == 0:
                return j
        j += 1
    return -1


def _link_dest(s: str, i: int) -> tuple[str, int] | None:
    """s[i]=='(' 에서 `(주소 "제목")` 을 읽는다 → (주소, 닫는 괄호 다음 위치)."""
    if i >= len(s) or s[i] != "(":
        return None
    j = i + 1
    while j < len(s) and s[j] == " ":
        j += 1
    if j < len(s) and s[j] == "<":
        k = s.find(">", j + 1)
        if k < 0:
            return None
        url = s[j + 1:k]
        j = k + 1
    else:
        depth = 0
        k = j
        while k < len(s):
            c = s[k]
            if c == "\\" and k + 1 < len(s):
                k += 2
                continue
            if c == "(":
                depth += 1
            elif c == ")":
                if depth == 0:
                    break
                depth -= 1
            elif c == " ":
                break
            k += 1
        url = s[j:k]
        j = k
    # 제목("…")은 버린다 — 블록 문서에 자리가 없다
    m = re.match(r'\s*(?:"[^"]*"|\'[^\']*\')?\s*\)', s[j:])
    if not m:
        return None
    url = re.sub(r"\\([!-/:-@\[-`{-~])", r"\1", url)
    return url, j + m.end()


def _with(marks: dict[str, Any], extra: dict[str, Any]) -> dict[str, Any]:
    out = dict(marks)
    out.update(extra)
    return out


def _inline(s: str, marks: dict[str, Any], out: list[Seg]) -> None:
    buf: list[str] = []

    def flush() -> None:
        if buf:
            out.append(("".join(buf), marks))
            buf.clear()

    def link_seg(text: str, href: str) -> None:
        flush()
        if href_ok(href) and "link" not in marks:
            _inline(text, _with(marks, {"link": {"href": href}}), out)
        else:
            # 받을 수 없는 주소(상대 주소·javascript: …)는 링크로 만들지 않고 글자로 남긴다
            _inline(text, marks, out)
            if href and href != text:
                out.append((" (" + href + ")", marks))

    n = len(s)
    i = 0
    while i < n:
        c = s[i]
        if c == "\\" and i + 1 < n and s[i + 1] in _ASCII_PUNCT:
            buf.append(s[i + 1])
            i += 2
            continue
        if c == "`":
            end, run = _code_span_end(s, i)
            if end < 0:
                buf.append("`" * run)
                i += run
                continue
            code = s[i + run:end]
            if len(code) >= 2 and code[0] == " " and code[-1] == " " and code.strip(" "):
                code = code[1:-1]
            flush()
            if code:
                out.append((code, _with(marks, {"code": True})))
            i = end + run
            continue
        if c == "!" and i + 1 < n and s[i + 1] == "[":
            close = _bracket_end(s, i + 1)
            dest = _link_dest(s, close + 1) if close > 0 else None
            if dest is not None:
                alt = s[i + 2:close]
                link_seg(alt if alt.strip() else dest[0], dest[0])
                i = dest[1]
                continue
        if c == "[":
            close = _bracket_end(s, i)
            dest = _link_dest(s, close + 1) if close > 0 else None
            if dest is not None:
                link_seg(s[i + 1:close], dest[0])
                i = dest[1]
                continue
        if c == "<":
            m = _AUTOLINK.match(s, i)
            if m:
                link_seg(m.group(1), m.group(1))
                i = m.end()
                continue
            m = _AUTOMAIL.match(s, i)
            if m:
                flush()
                href = "mailto:" + m.group(1)
                if href_ok(href) and "link" not in marks:
                    out.append((m.group(1), _with(marks, {"link": {"href": href}})))
                else:
                    out.append((m.group(0), marks))
                i = m.end()
                continue
        if c == "h" and (i == 0 or not s[i - 1].isalnum()) and "link" not in marks:
            m = _BARE_URL.match(s, i)
            if m:
                url = m.group(0)
                # 끝의 문장 부호·짝 없는 닫는 괄호는 주소가 아니다(GFM 자동 링크 규칙)
                while url and (url[-1] in ".,;:!?'\"*_~" or (url[-1] == ")" and url.count(")") > url.count("("))):
                    url = url[:-1]
                if len(url) > len("https://") and href_ok(url):
                    flush()
                    out.append((url, _with(marks, {"link": {"href": url}})))
                    i += len(url)
                    continue
        matched = False
        for delim, extra in _EMPH:
            if not s.startswith(delim, i):
                continue
            after = i + len(delim)
            if after >= n or s[after].isspace():
                continue
            if delim[0] == "_" and i > 0 and s[i - 1].isalnum():
                continue
            j = _find_closer(s, after, delim)
            if j < 0:
                continue
            flush()
            _inline(s[after:j], _with(marks, extra), out)
            i = j + len(delim)
            matched = True
            break
        if matched:
            continue
        if c in "*_~":
            run = _run_len(s, i, c)
            buf.append(c * run)
            i += run
            continue
        buf.append(c)
        i += 1
    flush()


def inline_to_richtext(s: str) -> list[dict[str, Any]]:
    """한 덩이 Markdown 글자 → 리치 텍스트 조각 목록(합치고 · 2000자로 나누고 · 100개 상한 확인)."""
    s = _clean_text(s)
    raw: list[Seg] = []
    _inline(s, {}, raw)
    merged: list[Seg] = []
    for text, marks in raw:
        text = _clean_text(text)
        if not text:
            continue
        if merged and merged[-1][1] == marks:
            merged[-1] = (merged[-1][0] + text, marks)
        else:
            merged.append((text, marks))
    size = LIMITS["segment_chars"]
    segs: list[dict[str, Any]] = []
    for text, marks in merged:
        for k in range(0, len(text), size):
            segs.append({"t": text[k:k + size], "m": dict(marks)})
    if len(segs) > LIMITS["segments_per_text"]:
        raise DocLimitError(
            "segments_per_text",
            f"한 블록의 글 조각이 {LIMITS['segments_per_text']}개를 넘습니다(지금 {len(segs)}개) — "
            "아주 긴 문단이거나 꾸밈이 너무 잦습니다. 문단을 나누세요",
        )
    return segs


# ─── 블록 ───────────────────────────────────────────────────────────────────

_FENCE = re.compile(r"^(`{3,}|~{3,})[ \t]*([^`]*?)[ \t]*$")
_HEADING = re.compile(r"^(#{1,6})(?:[ \t]+(.*?))?[ \t]*$")
_HR = re.compile(r"^(?:(?:-[ \t]*){3,}|(?:\*[ \t]*){3,}|(?:_[ \t]*){3,})$")
_QUOTE = re.compile(r"^>[ ]?(.*)$")
_BULLET = re.compile(r"^([-*+])([ \t]+|$)(.*)$")
_ORDERED = re.compile(r"^(\d{1,9})([.)])([ \t]+|$)(.*)$")
_TASK = re.compile(r"^\[([ xX])\](?:[ \t]+|$)(.*)$", re.S)
_TABLE_DELIM = re.compile(r"^\|?[ \t]*:?-+:?[ \t]*(?:\|[ \t]*:?-+:?[ \t]*)*\|?[ \t]*$")


def _indent(line: str) -> int:
    col = 0
    for ch in line:
        if ch == " ":
            col += 1
        elif ch == "\t":
            col += 4 - (col % 4)
        else:
            break
    return col


def _dedent(line: str, n: int) -> str:
    col = 0
    i = 0
    while i < len(line) and col < n and line[i] in " \t":
        col += 1 if line[i] == " " else 4 - (col % 4)
        i += 1
    rest = line[i:]
    if col > n:
        rest = " " * (col - n) + rest
    return rest


def _blank(line: str) -> bool:
    return line.strip() == ""


def _block_start(line: str) -> bool:
    """이 줄이 문단을 끊고 새 블록을 여는가(들여쓰기 3칸 이하에서)."""
    if _blank(line) or _indent(line) > 3:
        return False
    t = line.lstrip(" \t")
    return bool(
        _FENCE.match(t) or _HEADING.match(t) or _HR.match(t) or _QUOTE.match(t)
        or _BULLET.match(t) or _ORDERED.match(t)
    )


def _split_cells(line: str) -> list[str]:
    t = line.strip()
    if t.startswith("|"):
        t = t[1:]
    if t.endswith("|") and not t.endswith("\\|"):
        t = t[:-1]
    cells: list[str] = []
    cur: list[str] = []
    i = 0
    in_code = 0
    while i < len(t):
        c = t[i]
        if c == "\\" and i + 1 < len(t) and t[i + 1] == "|":
            cur.append("|")
            i += 2
            continue
        if c == "`":
            run = _run_len(t, i, "`")
            if in_code == 0:
                in_code = run
            elif in_code == run:
                in_code = 0
            cur.append("`" * run)
            i += run
            continue
        if c == "|" and in_code == 0:
            cells.append("".join(cur).strip())
            cur = []
            i += 1
            continue
        cur.append(c)
        i += 1
    cells.append("".join(cur).strip())
    return cells


def _table_start(lines: list[str], i: int) -> bool:
    if i + 1 >= len(lines) or "|" not in lines[i] or _indent(lines[i]) > 3:
        return False
    if not _TABLE_DELIM.match(lines[i + 1].strip()) or "-" not in lines[i + 1]:
        return False
    if "|" not in lines[i + 1] and len(_split_cells(lines[i])) != 1:
        return False
    return len(_split_cells(lines[i])) == len(_split_cells(lines[i + 1]))


def _language(info: str) -> str:
    word = info.split()[0].lower() if info.split() else ""
    word = word.lstrip(".").strip("{}")
    word = _LANG_ALIASES.get(word, word)
    return word if word in LANGUAGES else DEFAULT_LANGUAGE


def _para(text: str) -> dict[str, Any]:
    return {"id": new_id(), "type": "paragraph", "text": inline_to_richtext(text)}


def _container(kind: str, content: list[str], extra: dict[str, Any]) -> dict[str, Any]:
    """목록 항목·인용 — 안쪽 줄을 다시 블록으로 읽어 첫 문단은 글자, 나머지는 children."""
    inner = parse_blocks(content)
    block: dict[str, Any] = {"id": new_id(), "type": kind}
    block.update(extra)
    if inner and inner[0]["type"] == "paragraph":
        block["text"] = inner[0]["text"]
        inner = inner[1:]
    else:
        block["text"] = []
    if inner:
        block["children"] = inner
    return block


def parse_blocks(lines: list[str]) -> list[dict[str, Any]]:
    blocks: list[dict[str, Any]] = []
    i = 0
    n = len(lines)
    while i < n:
        line = lines[i]
        if _blank(line):
            i += 1
            continue
        ind = _indent(line)
        t = line.lstrip(" \t") if ind <= 3 else line

        # 코드 펜스
        m = _FENCE.match(t) if ind <= 3 else None
        if m and not (m.group(1)[0] == "`" and "`" in m.group(2)):
            fence = m.group(1)
            body: list[str] = []
            i += 1
            while i < n:
                cl = lines[i]
                ct = cl.lstrip(" ")
                if _indent(cl) <= 3 and ct.startswith(fence[0] * len(fence)) and ct.rstrip().strip(fence[0]) == "":
                    i += 1
                    break
                body.append(_dedent(cl, ind))
                i += 1
            code = _clean_code("\n".join(body))
            if len(code) > LIMITS["code_chars"]:
                raise DocLimitError(
                    "code_chars",
                    f"코드 블록 하나가 {LIMITS['code_chars']}자를 넘습니다(지금 {len(code)}자) — 코드를 나누세요",
                )
            blocks.append({"id": new_id(), "type": "code", "code": code, "language": _language(m.group(2))})
            continue

        # 제목
        m = _HEADING.match(t) if ind <= 3 else None
        if m:
            text = m.group(2) or ""
            text = re.sub(r"(?:^|[ \t]+)#+[ \t]*$", "", text)
            level = min(len(m.group(1)), 3)
            blocks.append({"id": new_id(), "type": "heading", "level": level, "text": inline_to_richtext(text)})
            i += 1
            continue

        # 구분선 (목록 표시 `* * *` 보다 먼저)
        if ind <= 3 and _HR.match(t):
            blocks.append({"id": new_id(), "type": "divider"})
            i += 1
            continue

        # 인용
        if ind <= 3 and _QUOTE.match(t):
            content: list[str] = []
            while i < n and _indent(lines[i]) <= 3:
                qm = _QUOTE.match(lines[i].lstrip(" \t"))
                if not qm:
                    break
                content.append(qm.group(1))
                i += 1
            blocks.append(_container("quote", content, {}))
            continue

        # 목록 항목
        bm = _BULLET.match(t) if ind <= 3 else None
        om = _ORDERED.match(t) if ind <= 3 and not bm else None
        if bm or om:
            if bm:
                marker_len, gap, first = 1, bm.group(2), bm.group(3)
            else:
                marker_len, gap, first = len(om.group(1)) + 1, om.group(3), om.group(4)
            gap_cols = _indent(gap) if gap else 0
            if gap_cols == 0 or gap_cols > 4:
                content_indent = ind + marker_len + 1
                first = (gap[1:] if gap_cols > 4 else "") + first
            else:
                content_indent = ind + marker_len + gap_cols
            content = [first]
            i += 1
            prev_blank = False
            while i < n:
                cl = lines[i]
                if _blank(cl):
                    # 빈 줄 뒤에 이 항목 안쪽 줄이 이어지는지 본다
                    k = i
                    while k < n and _blank(lines[k]):
                        k += 1
                    if k < n and _indent(lines[k]) >= content_indent:
                        content.extend("" for _ in range(k - i))
                        i = k
                        prev_blank = True
                        continue
                    break
                if _indent(cl) >= content_indent:
                    content.append(_dedent(cl, content_indent))
                    i += 1
                    prev_blank = False
                    continue
                if not prev_blank and not _block_start(cl) and not _table_start(lines, i) and content and not _blank(content[-1]):
                    content.append(cl.lstrip(" \t"))  # 게으른 이어짐 줄
                    i += 1
                    continue
                break
            kind = "bulleted" if bm else "numbered"
            extra: dict[str, Any] = {}
            if bm:
                tm = _TASK.match(content[0])
                if tm:
                    kind = "to_do"
                    extra["checked"] = tm.group(1) in "xX"
                    content[0] = tm.group(2)
            blocks.append(_container(kind, content, extra))
            continue

        # 표
        if _table_start(lines, i):
            header = _split_cells(lines[i])
            width = len(header)
            rows = [header]
            i += 2
            while i < n and not _blank(lines[i]) and "|" in lines[i] and not (
                _block_start(lines[i]) and not lines[i].lstrip().startswith("|")
            ):
                cells = _split_cells(lines[i])
                if len(cells) < width:
                    cells += [""] * (width - len(cells))
                elif len(cells) > width:
                    # 넘치는 칸은 버리지 않고 마지막 칸에 이어 붙인다(모든 행의 칸 수가 같아야 한다)
                    cells = cells[:width - 1] + [" | ".join(cells[width - 1:])]
                rows.append(cells)
                i += 1
            if width > LIMITS["table_cols"]:
                raise DocLimitError("table_cols", f"표의 열이 {LIMITS['table_cols']}개를 넘습니다(지금 {width}개)")
            if len(rows) > LIMITS["table_rows"]:
                raise DocLimitError(
                    "table_rows", f"표의 행이 {LIMITS['table_rows']}개를 넘습니다(머리 행 포함 지금 {len(rows)}개) — 표를 나누세요"
                )
            blocks.append({
                "id": new_id(),
                "type": "table",
                "header_row": True,
                "header_col": False,
                "rows": [{"cells": [{"text": inline_to_richtext(c)} for c in r]} for r in rows],
            })
            continue

        # 문단 — 다음 빈 줄·블록 시작까지
        para = [line.strip(" \t")]
        i += 1
        while i < n and not _blank(lines[i]) and not _block_start(lines[i]) and not _table_start(lines, i):
            para.append(lines[i].strip(" \t"))
            i += 1
        # 줄 끝 강제 줄바꿈 표시(`\`)는 줄바꿈 그 자체로 둔다
        para = [p[:-1] if p.endswith("\\") and not p.endswith("\\\\") else p for p in para]
        blocks.append(_para("\n".join(para)))
    return blocks


def _measure(blocks: list[dict[str, Any]], depth: int, acc: dict[str, int]) -> None:
    acc["depth"] = max(acc["depth"], depth)
    for b in blocks:
        acc["count"] += 1
        if "children" in b:
            _measure(b["children"], depth + 1, acc)


def markdown_to_doc(markdown: str) -> dict[str, Any]:
    """Markdown 글 → `{"schema": 1, "blocks": [...]}`. 상한을 넘으면 `DocLimitError`."""
    if not isinstance(markdown, str):
        raise TypeError("markdown 은 문자열이어야 합니다")
    text = markdown.replace("\r\n", "\n").replace("\r", "\n").lstrip("\ufeff")
    blocks = parse_blocks(text.split("\n"))
    if not blocks:
        blocks = [{"id": new_id(), "type": "paragraph", "text": []}]
    acc = {"count": 0, "depth": 0}
    _measure(blocks, 1, acc)
    if acc["depth"] > LIMITS["depth"]:
        raise DocLimitError(
            "depth", f"목록·인용이 {LIMITS['depth']}단계보다 깊게 겹쳤습니다(지금 {acc['depth']}단계) — 들여쓰기를 줄이세요"
        )
    if acc["count"] > LIMITS["blocks"]:
        raise DocLimitError(
            "blocks",
            f"블록이 {LIMITS['blocks']}개를 넘습니다(지금 {acc['count']}개) — 글을 나눠 여러 페이지로 올리세요",
        )
    doc = {"schema": int(SCHEMA["schema"]), "blocks": blocks}
    size = len(doc_json(doc).encode("utf-8"))
    if size > LIMITS["doc_bytes"]:
        raise DocLimitError(
            "doc_bytes", f"문서가 {LIMITS['doc_bytes']}바이트(1MB)를 넘습니다(지금 {size}바이트) — 글을 나누세요"
        )
    return doc


def doc_json(doc: dict[str, Any]) -> str:
    """서버가 크기를 재는 방식(JSON_UNESCAPED_UNICODE|SLASHES)에 가까운 직렬화."""
    return json.dumps(doc, ensure_ascii=False, separators=(",", ":"))


# ─── 블록 → Markdown (읽기 도구가 쓴다) ─────────────────────────────────────

_MD_ESCAPE = re.compile(r"([\\`*_~\[\]<])")


def _esc(text: str) -> str:
    return _MD_ESCAPE.sub(r"\\\1", text)


def richtext_to_markdown(text: Any) -> str:
    if not isinstance(text, list):
        return ""
    out: list[str] = []
    for seg in text:
        if not isinstance(seg, dict):
            continue
        t = seg.get("t")
        m = seg.get("m") if isinstance(seg.get("m"), dict) else {}
        if not isinstance(t, str) or t == "":
            continue
        if isinstance(m.get("mention"), dict):
            men = m["mention"]
            out.append(f"@[{men.get('kind')}:{men.get('id')}]")
            continue
        if m.get("code"):
            run = 1
            while "`" * run in t:
                run += 1
            pad = " " if t.startswith("`") or t.endswith("`") else ""
            s = "`" * run + pad + t + pad + "`" * run
        else:
            s = _esc(t)
        # 앞뒤 빈칸은 꾸밈 밖으로 뺀다(`** 굵게**` 는 Markdown 이 꾸밈으로 읽지 않는다)
        core = s.strip(" \n")
        if core and core != s:
            lead = s[: len(s) - len(s.lstrip(" \n"))]
            trail = s[len(s.rstrip(" \n")):]
        else:
            lead = trail = ""
            core = s
        if core:
            if m.get("strike"):
                core = "~~" + core + "~~"
            if m.get("italic"):
                core = "*" + core + "*"
            if m.get("bold"):
                core = "**" + core + "**"
            link = m.get("link")
            if isinstance(link, dict) and isinstance(link.get("href"), str):
                href = link["href"].replace(")", "%29").replace(" ", "%20")
                core = "[" + core + "](" + href + ")"
        out.append(lead + core + trail)
    return "".join(out)


def _line_start_escape(s: str) -> str:
    """문단 글자가 줄 머리에서 블록 문법(# · > · - · 1.)으로 읽히지 않게."""
    lines = s.split("\n")
    fixed = []
    for ln in lines:
        if re.match(r"^(#{1,6}([ \t]|$)|>|[-+]([ \t]|$)|\d{1,9}[.)]([ \t]|$)|(?:-[ \t]*){3,}$|\|)", ln):
            m = re.match(r"^(\d{1,9})([.)])", ln)
            ln = (m.group(1) + "\\" + ln[len(m.group(1)):]) if m else "\\" + ln
        fixed.append(ln)
    return "\n".join(fixed)


def _indent_lines(s: str, pad: str) -> str:
    return "\n".join((pad + ln) if ln else ln for ln in s.split("\n"))


def blocks_to_markdown(blocks: Any) -> str:
    if not isinstance(blocks, list):
        return ""
    parts: list[str] = []
    num = 0
    for b in blocks:
        if not isinstance(b, dict):
            continue
        typ = b.get("type")
        num = num + 1 if typ == "numbered" else 0
        text = _line_start_escape(richtext_to_markdown(b.get("text")))
        kids = blocks_to_markdown(b.get("children")) if b.get("children") else ""
        if typ == "paragraph":
            body = text
            if kids:
                body += "\n\n" + _indent_lines(kids, "    ")
        elif typ == "heading":
            lvl = b.get("level") if b.get("level") in (1, 2, 3) else 3
            body = "#" * lvl + " " + text.replace("\n", " ")
            if kids:
                body += "\n\n" + kids
        elif typ in ("bulleted", "numbered", "to_do"):
            if typ == "bulleted":
                marker = "- "
            elif typ == "numbered":
                marker = f"{num}. "
            else:
                marker = "- [x] " if b.get("checked") else "- [ ] "
            pad = " " * len(marker) if typ != "to_do" else "  "
            body = marker + _indent_lines(text, pad).lstrip(" ")
            if kids:
                body += "\n\n" + _indent_lines(kids, pad)
        elif typ in ("quote", "callout"):
            inner = text
            if typ == "callout" and isinstance(b.get("icon"), str):
                inner = b["icon"] + " " + inner
            if kids:
                inner += "\n\n" + kids
            body = "\n".join(("> " + ln) if ln else ">" for ln in inner.split("\n"))
        elif typ == "toggle":
            body = "▸ " + text
            if kids:
                body += "\n\n" + _indent_lines(kids, "    ")
        elif typ == "divider":
            body = "---"
        elif typ == "code":
            code = b.get("code") if isinstance(b.get("code"), str) else ""
            fence = "```"
            while fence in code:
                fence += "`"
            lang = b.get("language") if b.get("language") != "plain" else ""
            body = fence + (lang or "") + "\n" + code + "\n" + fence
        elif typ == "table":
            rows = b.get("rows") if isinstance(b.get("rows"), list) else []
            lines_ = []
            for r_i, row in enumerate(rows):
                cells = row.get("cells", []) if isinstance(row, dict) else []
                vals = [
                    richtext_to_markdown(c.get("text")).replace("|", "\\|").replace("\n", " ")
                    if isinstance(c, dict) else ""
                    for c in cells
                ]
                lines_.append("| " + " | ".join(vals) + " |")
                if r_i == 0:
                    lines_.append("|" + "|".join(" --- " for _ in vals) + "|")
            body = "\n".join(lines_)
        elif typ == "toc":
            body = "[목차]"
        elif typ in ("child_page", "link_to_page"):
            pid = b.get("page_id", "")
            body = f"[하위 페이지](page:{pid})" if typ == "child_page" else f"[페이지 링크](page:{pid})"
        elif typ in ("image", "file"):
            cap = richtext_to_markdown(b.get("caption"))
            label = "그림" if typ == "image" else "파일"
            body = f"[{label}: {b.get('file_ref', '')}]" + (f" {cap}" if cap else "")
        elif typ == "embed":
            url = b.get("url", "")
            body = f"[임베드]({url})"
        elif typ in ("column_list", "column"):
            body = kids
        else:
            body = f"[알 수 없는 블록: {typ}]"
        parts.append(body)
    # 이어진 목록 항목은 빈 줄 없이, 나머지는 빈 줄 하나로 잇는다
    out = ""
    prev = None
    for b, body in zip([x for x in blocks if isinstance(x, dict)], parts):
        typ = b.get("type")
        if prev is None:
            out = body
        elif typ == prev and typ in ("bulleted", "numbered", "to_do") and not b.get("children"):
            out += "\n" + body
        else:
            out += "\n\n" + body
        prev = typ
    return out


def doc_to_markdown(doc: Any) -> str:
    if not isinstance(doc, dict):
        return ""
    return blocks_to_markdown(doc.get("blocks"))
