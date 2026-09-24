#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
R9 기능 목록 대조 (feature list cross-check) — 1-F 정적 규칙, python3 stdlib 만, 파일은 읽기만.

answers: AK-0090 · AK-0179 · AK-0036

무엇을 부류로 잡나:
  기능을 없애거나 바꾼 뒤, 그 기능을 「말로」 가리키는 곳(도움말·스키마 단계 표·제거 지시서)이 코드와 어긋난 채
  남는 결함이다. 문법 검사·단위 시험은 통과하고, 사람이 도움말을 따라 해 보거나 단계를 올린 뒤에야 드러난다.
  기능 이름 사전은 손으로 적지 않고 **대상 트리의 코드에서 뽑는다**(아래 「사전」). 여섯 갈래를 본다.
    H1 입력 규칙   도움말이 「X 를 치면」「X 뒤에 빈칸」처럼 글자를 쳐서 여는 기능을 안내하는데, 화면 코드(JS)의    낮음
                   입력 규칙(앞·뒤가 고정된 정규식)과 키·글자 비교 어디에도 X 를 받는 것이 없다.
    H2 단축키      도움말의 `Ctrl+…`·`⌘…` 조합이 코드의 키 등록(`Mod-z` 류 문자열, ctrlKey/metaKey 와 함께 보는      낮음
                   키 비교)에 없다. 브라우저가 스스로 하는 조합(복사·붙여넣기·찾기 등)은 보지 않는다.
    H3 UI 이름     도움말이 「누르라」「고르라」고 안내하는 「이름」이 화면 코드의 짧은 글자(단추·메뉴·창 이름)에 없다.   낮음
    H4 나열        「… 이/가 뜹니다(나옵니다·열립니다·보입니다·있습니다) — a, b, c」 처럼 화면에 뜨는 목록을 늘어놓은   낮음
                   문장에서, 절반 이상은 화면 코드의 짧은 글자로 있는데 나머지가 없다(= 없앤 항목이 목록에 남음).
    S1 스키마 단계 JSON 스키마의 단계 표(`"<숫자>": [이름…]`)와 지금 단계 상수(이름에 STAGE, 스키마 파일 이름과 낱말     중간
                   하나를 나눔)로 「열린」 이름을 구하고, 가장 낮은 단계 이름을 전부 다루는 처리기(렌더러·에디터 스키마
                   = src·public 의 코드 파일)마다 열린 이름을 모두 다루는지 본다. 단계 표의 이름이 정의 표에 없어도 낮음.
    S2 제거 지시서 제목이 제거·삭제를 말하는 지시서의 「삭제(git rm)」 절 경로·글롭과 「이동」 표의 옛 경로가 아직       낮음
                   추적(또는 트리에 존재)된다. 같은 지시서의 「새로 만드는 파일」 절에 있는 것은 뺀다.
  사전: 화면 코드 = src/·public/ 아래(또는 트리 안 어디든) .js·.mjs·.php·.phtml·.html 가운데 vendor·node_modules·
        tests·scripts·docs·wiki·db·deploy·fixtures·help 폴더 밖 파일. 주석(//·#·/* */·<!-- -->)은 버리고 문자열
        상수·HTML 글자·속성 값만 쓴다. 「짧은 글자」는 30자 이하이고 문장 끝(…다.)이 아닌 것 — 안내 문장 속 낱말은
        이름으로 치지 않는다. 입력 규칙은 JS 의 정규식 상수와 `new RegExp('…')`(이어 붙인 조각이면 조각만).
  도움말 = 경로에 `help` 폴더가 있는 .php·.json·.md·.html·.txt 파일의 글(PHP 는 문자열 상수, 주석 제외).
  부정문(없습니다·않습니다·지웠습니다·없앴습니다 …)은 기능이 없다는 안내라서 보지 않는다.
  같은 줄에 `qcheck-allow: R9` 가 있으면 뺀다.

입력: --root <트리>(기본: 이 파일 기준 저장소 뿌리) · --outer <바깥 배치>(기본: 뿌리가 git 저장소 맨 위면 <root>/.., 아니면 없음;
      none 이면 바깥 지시서 안 봄)
      --exclude GLOB(여러 번, 기본 tests/static/fixtures/*)
출력: FINDING R9 <심각도> <파일:줄> <설명> · 끝 줄 RESULT pass=<판정한 대상 - 발견 대상> fail=<발견 수> skip=<판정 못 한 대상>
rc:   0 발견 없음 · 1 발견 있음 · 2 실행 오류(뿌리 없음·목록 실패, 또는 도움말·스키마·제거 지시서가 하나도 없어 검사 못 함)
"""
import argparse
import fnmatch
import json
import os
import re
import subprocess
import sys

RULE = 'R9'
DEFAULT_EXCLUDES = ['tests/static/fixtures/*']
MAX_BYTES = 4 * 1024 * 1024
ALLOW_RE = re.compile(r'qcheck-allow\s*[:=]?\s*(?:[A-Za-z0-9_-]+\s*,\s*)*R9\b')
UI_EXT = ('.js', '.mjs', '.php', '.phtml', '.html', '.htm')
NON_UI_DIRS = {'vendor', 'node_modules', 'tests', 'test', 'scripts', 'docs', 'wiki', 'db', 'deploy',
               'fixtures', 'help', '.git'}
HELP_EXT = ('.php', '.json', '.md', '.html', '.htm', '.txt')
SHORT_MAX = 30
NEG_RE = re.compile(r'없습니다|없어요|없다\b|않습니다|않는다|않아요|못합니다|지웠|없앴|사라졌|빠졌|제거|더는|이제는')
SENT_END_RE = re.compile(r'(다|요|니다)[.!?]?\s*$')


def note(msg):
    sys.stderr.write('R9: %s\n' % msg)


# ─── 파일 목록 ────────────────────────────────────────────────────────────────
def git(root, *args):
    return subprocess.run(['git', '--no-optional-locks', '-C', root] + list(args), stdout=subprocess.PIPE,
                          stderr=subprocess.PIPE, timeout=120)


def list_files(root, excludes):
    top = None
    try:
        p = git(root, 'rev-parse', '--show-toplevel')
        if p.returncode == 0:
            top = os.path.realpath(p.stdout.decode().strip())
    except (OSError, subprocess.SubprocessError):
        top = None
    files = []
    if top and top == os.path.realpath(root):
        p = git(root, 'ls-files', '-z', '--cached', '--others', '--exclude-standard')
        if p.returncode != 0:
            raise RuntimeError('git ls-files 실패: ' + p.stderr.decode(errors='replace').strip())
        seen = set()
        for rel in p.stdout.decode('utf-8', errors='surrogateescape').split('\0'):
            if rel and rel not in seen and os.path.isfile(os.path.join(root, rel)):
                seen.add(rel)
                files.append(rel)
    else:
        for dp, dns, fns in os.walk(root):
            dns[:] = [d for d in dns if d != '.git']
            for fn in fns:
                full = os.path.join(dp, fn)
                if os.path.isfile(full) and not os.path.islink(full):
                    files.append(os.path.relpath(full, root).replace(os.sep, '/'))
    out = []
    for rel in sorted(files):
        if any(fnmatch.fnmatch(rel, g) for g in excludes):
            continue
        out.append(rel)
    return out


def read_text(path):
    try:
        if os.path.getsize(path) > MAX_BYTES:
            return None
        with open(path, 'rb') as fh:
            data = fh.read()
    except OSError:
        return None
    if b'\0' in data[:8000]:
        return None
    return data.decode('utf-8', errors='replace')


# ─── 코드 훑기: 문자열·정규식·HTML 글자만 남기고 주석은 버린다 ─────────────────
REGEX_PREV = set('(,=:[!&|?{};+-*%<>~^')


def scan_code(text, lang):
    """lang: 'js' | 'php' | 'html'. (kind, value, line) 를 낸다. kind: str|regex|html|code."""
    out = []
    i, n, line = 0, len(text), 1
    mode = 'html' if lang in ('php', 'html') else 'code'
    code_buf = []
    last_sig = ''

    def flush_code():
        if code_buf:
            out.append(('code', ''.join(code_buf), line))
            code_buf.clear()

    html_start, html_line = 0, 1
    while i < n:
        c = text[i]
        if mode == 'html':
            if text.startswith('<!--', i):
                j = text.find('-->', i + 4)
                j = n if j < 0 else j + 3
                seg = text[html_start:i]
                if seg.strip():
                    out.append(('html', seg, html_line))
                line += text.count('\n', i, j)
                i = j
                html_start, html_line = i, line
                continue
            if lang == 'php' and text.startswith('<?', i):
                seg = text[html_start:i]
                if seg.strip():
                    out.append(('html', seg, html_line))
                j = i + 2
                if text.startswith('php', j):
                    j += 3
                elif text.startswith('=', j):
                    j += 1
                i = j
                mode = 'code'
                last_sig = '('
                continue
            if lang == 'html' and text[i:i + 7].lower() == '<script':
                j = text.find('>', i)
                if j < 0:
                    break
                seg = text[html_start:j + 1]
                if seg.strip():
                    out.append(('html', seg, html_line))
                line += text.count('\n', i, j + 1)
                i = j + 1
                mode = 'code'
                last_sig = '('
                continue
            if c == '\n':
                line += 1
            i += 1
            continue
        # code mode (JS 또는 PHP)
        if lang == 'php' and text.startswith('?>', i):
            flush_code()
            i += 2
            mode = 'html'
            html_start, html_line = i, line
            continue
        if lang == 'html' and text[i:i + 9].lower() == '</script>':
            flush_code()
            i += 9
            mode = 'html'
            html_start, html_line = i, line
            continue
        if text.startswith('//', i) or (lang == 'php' and c == '#' and not text.startswith('#[', i)):
            j = text.find('\n', i)
            if lang == 'php':
                k = text.find('?>', i)
                if 0 <= k < (j if j >= 0 else n):
                    j = k
            i = n if j < 0 else j
            continue
        if text.startswith('/*', i):
            j = text.find('*/', i + 2)
            j = n if j < 0 else j + 2
            line += text.count('\n', i, j)
            i = j
            continue
        if lang == 'php' and text.startswith('<<<', i):
            m = re.match(r"<<<[ \t]*(['\"]?)([A-Za-z_][A-Za-z0-9_]*)\1\r?\n", text[i:])
            if m:
                label = m.group(2)
                body_start = i + m.end()
                em = re.compile(r'^[ \t]*' + re.escape(label) + r'\b', re.M).search(text, body_start)
                j = em.start() if em else n
                flush_code()
                out.append(('str', text[body_start:j], line + 1))
                line += text.count('\n', i, j)
                i = em.end() if em else n
                last_sig = 'a'
                continue
        if c in ('"', "'", '`'):
            j = i + 1
            buf = []
            start_line = line
            while j < n:
                d = text[j]
                if d == '\\' and j + 1 < n:
                    buf.append(text[j:j + 2])
                    if text[j + 1] == '\n':
                        line += 1
                    j += 2
                    continue
                if d == c:
                    break
                if d == '\n':
                    line += 1
                    if c != '`' and lang == 'js':
                        break
                buf.append(d)
                j += 1
            flush_code()
            out.append(('str', unescape(''.join(buf)), start_line))
            i = j + 1
            last_sig = 'a'
            continue
        if c == '/' and lang in ('js', 'html') and (last_sig in REGEX_PREV or last_sig == '' or last_sig == 'kw'):
            j = i + 1
            in_cls = False
            ok = False
            while j < n:
                d = text[j]
                if d == '\n':
                    break
                if d == '\\':
                    j += 2
                    continue
                if d == '[':
                    in_cls = True
                elif d == ']':
                    in_cls = False
                elif d == '/' and not in_cls:
                    ok = True
                    break
                j += 1
            if ok and j > i + 1:
                k = j + 1
                while k < n and text[k].isalpha():
                    k += 1
                flush_code()
                out.append(('regex', text[i + 1:j], line))
                i = k
                last_sig = 'a'
                continue
        if c == '\n':
            line += 1
        if not c.isspace():
            if c.isalnum() or c in '_$':
                m = re.match(r'[A-Za-z0-9_$]+', text[i:])
                word = m.group(0)
                code_buf.append(word)
                last_sig = 'kw' if word in ('return', 'typeof', 'case', 'in', 'of', 'yield', 'void') else 'a'
                i += len(word)
                continue
            last_sig = c if c not in ')]' else 'a'
        code_buf.append(c)
        i += 1
    if mode == 'html':
        seg = text[html_start:]
        if seg.strip():
            out.append(('html', seg, html_line))
    flush_code()
    return out


def unescape(s):
    return re.sub(r'\\(.)', lambda m: {'n': '\n', 't': '\t'}.get(m.group(1), m.group(1)), s)


def lang_of(rel):
    ext = os.path.splitext(rel)[1].lower()
    if ext in ('.js', '.mjs'):
        return 'js'
    if ext in ('.php', '.phtml'):
        return 'php'
    if ext in ('.html', '.htm'):
        return 'html'
    return None


# ─── 사전: 화면 코드의 짧은 글자 · 입력 규칙 · 키 등록 ─────────────────────────
TAG_RE = re.compile(r'<[^>]*>')
ATTR_RE = re.compile(r'\b(?:aria-label|title|placeholder|value|alt|label|data-label)\s*=\s*"([^"]*)"')


def is_ui_file(rel):
    parts = rel.split('/')
    if any(p in NON_UI_DIRS for p in parts[:-1]):
        return False
    return rel.lower().endswith(UI_EXT)


class Dict:
    def __init__(self):
        self.short = set()          # 짧은 글자(정규화)
        self.rules = []             # (compiled regex, file, line)
        self.frags = []             # 이어 붙인 RegExp 의 글자 조각
        self.keylits = set()        # 키·글자 비교에 쓰인 글자
        self.combos = set()         # (frozenset(mods), key)
        self.files = 0
        self.short_nospace = None

    def add_short(self, s):
        for piece in re.split(r'[\n\r\t]+', s):
            # 「이름 (설명)」 모양의 글자는 괄호 앞도 이름으로 본다
            for cand in (piece, re.split(r'\s*[(（]', piece)[0]):
                p = norm_label(cand)
                if 0 < len(p) <= SHORT_MAX and not SENT_END_RE.search(p) and re.search(r'[가-힣A-Za-z]', p):
                    self.short.add(p)


def norm_label(s):
    s = s.replace('\u00a0', ' ')
    s = re.sub(r'\s+', ' ', s).strip()
    s = s.strip(' :·…,.!?"\'「」『』()[]+-*=→›>')
    return s


KEYMAP_STR_RE = re.compile(r'^((?:(?:Mod|Ctrl|Control|Cmd|Meta|Alt|Option|Shift)-)+)(.{1,12})$')
KEYCMP_RE = re.compile(r"""(?:\.(?:key|code|data|char|which|keyCode)\b[^=!;]{0,20}?[!=]==?\s*)(['"])(.{1,12}?)\1"""
                       r"""|(['"])(.{1,12}?)\3\s*[!=]==?\s*[\w.$]*\.(?:key|code|data|char)\b""")
TEXTCMP_RE = re.compile(r"""\b(\w*(?:key|Key|text|Text|char|Char|ch|typed|input|Input)\w*)\s*[!=]==?\s*(['"])(.{1,4}?)\2""")
CASE_RE = re.compile(r"""\bcase\s+(['"])(.{1,12}?)\1\s*:""")
MODS = {'mod': 'ctrl', 'ctrl': 'ctrl', 'control': 'ctrl', 'cmd': 'ctrl', 'meta': 'ctrl', '⌘': 'ctrl',
        'alt': 'alt', 'option': 'alt', '⌥': 'alt', 'shift': 'shift', '⇧': 'shift'}


def build_dict(root, files):
    d = Dict()
    for rel in files:
        lang = lang_of(rel)
        if not lang:
            continue
        ui = is_ui_file(rel)
        vendor = '/vendor/' in ('/' + rel) or '/node_modules/' in ('/' + rel)
        if not ui and not vendor:
            continue
        text = read_text(os.path.join(root, rel))
        if text is None:
            continue
        toks = scan_code(text, lang)
        if vendor:
            # 외부 라이브러리는 키 등록(기본 키맵)만 사전에 넣는다
            for kind, val, _ in toks:
                if kind == 'str':
                    add_combo_str(d, val)
            continue
        d.files += 1
        code_text = []
        for idx, (kind, val, ln) in enumerate(toks):
            if kind == 'str':
                d.add_short(val)
                add_combo_str(d, val)
                if '<' in val and '>' in val:
                    for a in ATTR_RE.findall(val):
                        d.add_short(a)
                    d.add_short(TAG_RE.sub('\n', val))
            elif kind == 'html':
                for a in ATTR_RE.findall(val):
                    d.add_short(a)
                d.add_short(TAG_RE.sub('\n', val))
            elif kind == 'regex' and lang in ('js', 'html'):
                # 바꾸기·나누기에 쓰는 정규식(.replace(/…/)·.split(/…/))은 입력을 알아보는 규칙이 아니다
                prev = toks[idx - 1][1] if idx > 0 and toks[idx - 1][0] == 'code' else ''
                if not re.search(r'\.(?:replace|replaceAll|split|trim\w*)\s*\($', prev):
                    add_rule(d, val, rel, ln)
            if kind == 'code':
                code_text.append(val)
            else:
                code_text.append(' ' + (repr(val) if kind != 'code' else val) + ' ')
            # new RegExp('…' + x + '…')
            if kind == 'code' and re.search(r'\bRegExp\s*\($', val):
                frags = []
                k = idx + 1
                depth = 1
                while k < len(toks) and k < idx + 12:
                    kk, vv, _ = toks[k]
                    if kk == 'str':
                        frags.append(vv)
                    elif kk == 'code':
                        depth += vv.count('(') - vv.count(')')
                        if depth <= 0:
                            break
                    k += 1
                if len(frags) == 1 and k < len(toks) and toks[k][0] == 'code' and toks[k][1].lstrip().startswith(')'):
                    add_rule(d, frags[0], rel, ln)
                elif frags:
                    d.frags.append(''.join(frags))
        joined = ''.join(code_text)
        if lang in ('js', 'html'):
            for m in KEYCMP_RE.finditer(joined):
                d.keylits.add(m.group(2) if m.group(2) is not None else m.group(4))
            for m in TEXTCMP_RE.finditer(joined):
                d.keylits.add(m.group(3))
            for m in CASE_RE.finditer(joined):
                d.keylits.add(m.group(2))
        # ctrlKey/metaKey 와 함께 보는 키 비교 = 단축키 등록
        for m in re.finditer(r'(ctrlKey|metaKey)', joined):
            win = joined[max(0, m.start() - 300): m.end() + 300]
            for km in re.finditer(r"""\.(?:key|code)\b[^=!;]{0,20}?===?\s*(['"])(.{1,8}?)\1|(['"])(.{1,8}?)\3\s*===?\s*[\w.$]*\.(?:key|code)\b""", win):
                k = km.group(2) if km.group(2) is not None else km.group(4)
                k = re.sub(r'^(Key|Digit|Numpad)', '', k)
                mods = {'ctrl'}
                if re.search(r'(?<!!)\b\w*\.altKey\b(?!\s*===?\s*false)', win):
                    mods.add('alt')
                if re.search(r'(?<!!)\b\w*\.shiftKey\b(?!\s*===?\s*false)', win):
                    mods.add('shift')
                d.combos.add((frozenset(mods), k.lower()))
                d.combos.add((frozenset({'ctrl'}), k.lower()))
    return d


def add_combo_str(d, s):
    m = KEYMAP_STR_RE.match(s.strip())
    if not m:
        return
    mods = set()
    for p in m.group(1).strip('-').split('-'):
        if p.lower() in MODS:
            mods.add(MODS[p.lower()])
    key = m.group(2).lower()
    d.combos.add((frozenset(mods), key))


def js_to_py_regex(src):
    s = src.replace('(?<', '(?P<') if '(?<' in src and '(?<=' not in src and '(?<!' not in src else src
    s = re.sub(r'\\u\{([0-9a-fA-F]+)\}', lambda m: '\\U%08x' % int(m.group(1), 16), s)
    s = s.replace('\\p{L}', 'a-zA-Z\u00c0-\uffff') if '\\p{' in s else s
    return s


def add_rule(d, src, rel, ln):
    if not (src.startswith('^') or src.endswith('$')):
        return
    try:
        rx = re.compile(js_to_py_regex(src))
    except (re.error, OverflowError):
        d.frags.append(src)
        return
    d.rules.append((rx, rel, ln))


def trigger_known(d, tok, placeholder=False):
    cands = [tok, tok + ' ', ' ' + tok, ' ' + tok + ' ']
    if placeholder:
        # 「@아이디」·「#태그」 처럼 뒤에 자리표시 낱말이 붙은 모양 — 그 자리에 흔한 값을 넣어 본다
        cands += [tok + x for x in ('a', 'abc', 'user1', 'x_1', '가', '가나')]
    for rx, _, _ in d.rules:
        for c in cands:
            if rx.fullmatch(c):
                return True
    if tok in d.keylits:
        return True
    # 같은 글자를 되풀이한 토큰(##·---)은 그 글자가 규칙 조각에 있으면 안다고 본다(느슨하게)
    core = tok.strip()
    if len(set(core)) == 1:
        ch = core[0]
        if ch in d.keylits:
            return True
        for rx, _, _ in d.rules:
            if ch in rx.pattern:
                for c in (ch, ch + ' '):
                    if rx.fullmatch(c):
                        return True
        for f in d.frags:
            if ch in f.replace('\\' + ch, ch):
                return True
    for f in d.frags:
        if core and core in f:
            return True
    return False


def label_known(d, label):
    """이름은 화면 코드의 짧은 글자와 **통째로** 같아야 한다(띄어쓰기 차이만 봐 준다).
    다른 단추 이름 속 낱말(「취소」 ↔ '도움됐어요 취소')은 같은 이름으로 치지 않는다."""
    lab = norm_label(label)
    if not lab:
        return True
    if lab in d.short:
        return True
    if d.short_nospace is None:
        d.short_nospace = {re.sub(r'\s+', '', x) for x in d.short}
    return re.sub(r'\s+', '', lab) in d.short_nospace


# ─── 도움말 글 모으기 ─────────────────────────────────────────────────────────
def help_files(files):
    out = []
    for rel in files:
        parts = rel.split('/')
        if 'help' in parts[:-1] and rel.lower().endswith(HELP_EXT):
            out.append(rel)
    return out


def help_sentences(root, rel):
    text = read_text(os.path.join(root, rel))
    if text is None:
        return None
    lines = text.split('\n')
    out = []
    ext = os.path.splitext(rel)[1].lower()
    if ext == '.php':
        for kind, val, ln in scan_code(text, 'php'):
            if kind == 'str' and re.search(r'[가-힣]', val):
                src_line = lines[ln - 1] if 0 < ln <= len(lines) else ''
                if ALLOW_RE.search(src_line):
                    continue
                out.append((ln, val))
    elif ext == '.json':
        for i, l in enumerate(lines, 1):
            for m in re.finditer(r'"((?:\\.|[^"\\])*)"', l):
                v = m.group(1)
                if re.search(r'[가-힣]', v) and not ALLOW_RE.search(l):
                    out.append((i, unescape(v)))
    else:
        for i, l in enumerate(lines, 1):
            if re.search(r'[가-힣]', l) and not ALLOW_RE.search(l):
                out.append((i, TAG_RE.sub(' ', l)))
    return out


def split_sentences(s):
    parts = re.split(r'(?<![0-9])\.\s+|(?<=[다요])\.$', s)
    return [p for p in parts if p.strip()]


ACTION_RE = re.compile(r'^\s*(?:을|를|은|는|이|가|에서|으로|로|과|와|나|이나|도|만|의)?\s*(?:한\s*번\s*더\s*|다시\s*|먼저\s*|꼭\s*)?'
                       r'(누르|눌러|눌렀|누릅|고르|골라|고릅|선택|클릭|켜|켭|끄|끕|열|탭|치면|쳐)')
LABEL_RE = re.compile(r'「([^」]{1,40})」')
TRIG_VERB_RE = re.compile(r'치면|치고|쳐서|쳐도|칩니다|입력하면|입력하고|뒤에\s*빈칸|를\s*쓰면|을\s*쓰면')
TRIG_TOK_RE = re.compile(r'(?:(?<=^)|(?<=[\s(「]))((?:[#\-*>/\[\]`~=_|!@^+:;]{1,4})|(?:\d+\.))(?=[\s)」]|$|[가-힣])')
COMBO_RE = re.compile(r'((?:(?:Ctrl|Control|Cmd|Command|Alt|Option|Shift)\s*\+\s*)+)([A-Za-z0-9]|Enter|Tab|Space|Backspace|Delete|Esc|Escape|Home|End|[\[\]/.,;\'`-])(?![A-Za-z0-9])'
                      r'|((?:[⌘⌥⇧])+)\s*([A-Za-z0-9])(?![A-Za-z0-9])')
NATIVE_CTRL = set('cvxafprwtnlhj')
ENUM_RE = re.compile(r'(뜹니다|나옵니다|열립니다|보입니다|있습니다|나타납니다)\s*[—–]\s*([^.。]+)')
ENUM_TAIL_RE = re.compile(r'\s*(?:(?:셋|넷|둘|다섯|여섯|일곱|여덟|아홉|열|두\s*가지|세\s*가지|네\s*가지|등)\s*)?'
                          r'(?:입니다|이에요|예요|이다|가\s*있습니다|이\s*있습니다)?\s*$')


def claims_in(sent):
    """(kind, payload) — kind: label | trigger | combo | enum"""
    out = []
    neg = NEG_RE.search(sent) is not None
    for m in LABEL_RE.finditer(sent):
        lab = m.group(1)
        if ACTION_RE.match(sent[m.end():m.end() + 16]):
            out.append(('label', lab, neg))
    if TRIG_VERB_RE.search(sent):
        body = COMBO_RE.sub(' ', sent)
        for m in TRIG_TOK_RE.finditer(body):
            tok = m.group(1)
            if re.fullmatch(r'[.\-]+', tok) and len(tok) < 3 and tok != '-':
                continue
            ph = m.end() < len(body) and re.match(r'[가-힣]', body[m.end()]) is not None
            out.append(('trigger', (tok, ph), neg))
    for m in COMBO_RE.finditer(sent):
        if m.group(1):
            mods = {MODS.get(p.strip().lower().replace('command', 'cmd'), 'ctrl')
                    for p in m.group(1).split('+') if p.strip()}
            key = m.group(2).lower()
        else:
            mods = {MODS[c] for c in m.group(3)}
            key = m.group(4).lower()
        key = {'escape': 'esc', 'space': ' '}.get(key, key)
        out.append(('combo', (frozenset(mods), key, m.group(0)), neg))
    em = ENUM_RE.search(sent)
    if em:
        items = [x for x in re.split(r'\s*[,·、]\s*', em.group(2)) if x.strip()]
        clean = []
        for it in items:
            it = re.sub(r'\([^)]*\)', '', it)
            it = ENUM_TAIL_RE.sub('', it).strip()
            if it:
                clean.append(it)
        if len(clean) >= 3 and all(len(x) <= 14 for x in clean):
            out.append(('enum', clean, neg))
    return out


def combo_known(d, mods, key):
    if mods == frozenset({'ctrl'}) and key in NATIVE_CTRL:
        return True
    if (mods, key) in d.combos:
        return True
    # 느슨하게: 같은 키를 Ctrl 과 함께 등록했으면 Alt/Shift 차이는 따지지 않는다
    for m2, k2 in d.combos:
        if k2 == key and 'ctrl' in m2 and 'ctrl' in mods and (mods - {'shift'}) <= (m2 | {'alt'}) \
                and ('alt' in mods) == ('alt' in m2):
            return True
    return False


# ─── S1 스키마 단계 표 ────────────────────────────────────────────────────────
def find_stage_tables(obj):
    """{키: {int: [이름]}} 모양과 정의 표({이름: …})를 짝짓는다."""
    tables = {}
    defs = {}
    if not isinstance(obj, dict):
        return []
    for k, v in obj.items():
        if isinstance(v, dict) and v and all(re.fullmatch(r'\d+', str(kk)) for kk in v) \
                and all(isinstance(vv, list) and all(isinstance(x, str) for x in vv) for vv in v.values()):
            tables[k] = {int(kk): vv for kk, vv in v.items()}
        elif isinstance(v, dict) and v and all(isinstance(kk, str) for kk in v):
            defs[k] = set(v.keys())
    pairs = []
    for tk, tv in tables.items():
        names = [x for l in tv.values() for x in l]
        best, score = None, 0.0
        for dk, dv in defs.items():
            s = sum(1 for x in names if x in dv) / max(1, len(names))
            if s > score:
                best, score = dk, s
        if best and score >= 0.8:
            pairs.append((tk, tv, best, defs[best]))
    return pairs


STAGE_CONST_RE = re.compile(r"""(?:\bconst\s+|define\(\s*['"])([A-Z][A-Z0-9_]*STAGE[A-Z0-9_]*)['"]?\s*[,=]\s*(\d+)""")


def active_stage(root, files, schema_rel):
    stem_words = {w for w in re.split(r'[^a-z0-9]+', os.path.splitext(os.path.basename(schema_rel))[0].lower())
                  if w and w not in ('schema', 'json')}
    found = {}
    for rel in files:
        if not rel.lower().endswith(('.php', '.js', '.mjs')) or not is_ui_file(rel) and not rel.startswith('src/'):
            continue
        text = read_text(os.path.join(root, rel))
        if text is None or 'STAGE' not in text:
            continue
        for kind, val, ln in scan_code(text, lang_of(rel)):
            if kind != 'code':
                continue
            for m in STAGE_CONST_RE.finditer(val):
                words = set(m.group(1).lower().split('_'))
                if words & stem_words:
                    found.setdefault(m.group(1), set()).add(int(m.group(2)))
        for m in re.finditer(r"""define\(\s*['"]([A-Z][A-Z0-9_]*STAGE[A-Z0-9_]*)['"]\s*,\s*(\d+)""", text):
            if set(m.group(1).lower().split('_')) & stem_words:
                found.setdefault(m.group(1), set()).add(int(m.group(2)))
    vals = set()
    for v in found.values():
        vals |= v
    if len(vals) == 1:
        return vals.pop(), sorted(found)
    return None, sorted(found)


def json_line_of(text, name):
    m = re.search(r'"' + re.escape(name) + r'"', text)
    return text.count('\n', 0, m.start()) + 1 if m else 1


def check_schema(root, files, findings, stats):
    handlers_cache = {}
    for rel in files:
        if not rel.lower().endswith('.json') or '/fixtures/' in '/' + rel:
            continue
        text = read_text(os.path.join(root, rel))
        if text is None or '"' not in text:
            continue
        try:
            obj = json.loads(text)
        except ValueError:
            continue
        pairs = find_stage_tables(obj)
        if not pairs:
            continue
        stage, consts = active_stage(root, files, rel)
        for tk, tv, dk, dv in pairs:
            names_all = [x for l in tv.values() for x in l]
            for x in names_all:
                stats['targets'] += 1
                if x not in dv:
                    findings.append(('낮음', rel, json_line_of(text, x),
                                     '스키마 단계 표 "%s" 의 이름 %s 가 정의 표 "%s" 에 없음' % (tk, x, dk)))
            if stage is None:
                note('%s: 단계 표 %s — 지금 단계 상수를 하나로 정하지 못함(후보 %s) → skip' % (rel, tk, consts or '없음'))
                stats['skip'] += 1
                continue
            base = min(tv)
            base_names = tv[base]
            active = [x for s, l in sorted(tv.items()) if s <= stage for x in l]
            for h in files:
                if not is_ui_file(h) or not h.lower().endswith(('.php', '.js', '.mjs')):
                    continue
                if h not in handlers_cache:
                    t = read_text(os.path.join(root, h))
                    lits = set()
                    if t is not None:
                        for kind, val, _ in scan_code(t, lang_of(h)):
                            if kind == 'str':
                                lits.add(val)
                            elif kind == 'code':
                                lits.update(re.findall(r'\b[a-z_][a-z0-9_]*\b', val))
                    handlers_cache[h] = lits
                lits = handlers_cache[h]
                if not all(x in lits for x in base_names):
                    continue
                for x in active:
                    if x in base_names:
                        continue
                    stats['targets'] += 1
                    if x not in lits:
                        st = [s for s, l in tv.items() if x in l][0]
                        findings.append(('중간', h, 1,
                                         '스키마 %s 의 "%s" 단계 %d 이름 %s 가 열려 있는데(지금 단계 %d) 이 처리기가 다루지 않음 '
                                         '(가장 낮은 단계 이름 %d개는 모두 다룸)' % (rel, tk, st, x, stage, len(base_names))))


# ─── S2 제거 지시서 ───────────────────────────────────────────────────────────
REMOVAL_TITLE_RE = re.compile(r'제거|삭제|떼어|지운다|지우기|없앤다|removal|remove', re.I)
DEL_HEAD_RE = re.compile(r'삭제|git\s+rm|제거\s*대상|지우는\s*것|지울\s*것', re.I)
MOVE_HEAD_RE = re.compile(r'이동|git\s+mv|옮기|옮길', re.I)
NEW_HEAD_RE = re.compile(r'새로\s*만드|새\s*파일|추가\s*파일|new\s+files?', re.I)
HEAD_RE = re.compile(r'^(#{1,6})\s+(.*)$')
TICK_RE = re.compile(r'`([^`\n]+)`')


def expand_braces(p):
    m = re.search(r'\{([^{}]*)\}', p)
    if not m:
        return [p]
    out = []
    for alt in m.group(1).split(','):
        out.extend(expand_braces(p[:m.start()] + alt + p[m.end():]))
    return out


def path_like(tok):
    tok = tok.strip()
    if ' ' in tok or '://' in tok or tok.startswith(('-', '$', '|')):
        return False
    return ('/' in tok or re.search(r'\.[A-Za-z0-9]{1,6}$', tok) or '*' in tok) and re.match(r'^[\w./*{},\-~<>]+$', tok)


def md_sections(lines):
    """(level, title, start, end) 목록"""
    heads = []
    in_code = False
    for i, l in enumerate(lines):
        if l.lstrip().startswith('```'):
            in_code = not in_code
            continue
        if in_code:
            continue
        m = HEAD_RE.match(l)
        if m:
            heads.append((len(m.group(1)), m.group(2), i))
    out = []
    for idx, (lv, title, st) in enumerate(heads):
        end = len(lines)
        for lv2, _, st2 in heads[idx + 1:]:
            if lv2 <= lv:
                end = st2
                break
        out.append((lv, title, st, end))
    return out


def removal_directives(root, files, outer):
    docs = []
    for rel in files:
        if rel.lower().endswith('.md'):
            docs.append((rel, os.path.join(root, rel)))
    if outer and os.path.isdir(outer):
        for dp, dns, fns in os.walk(outer):
            dns[:] = [d for d in dns if d not in ('.git', 'node_modules', 'vendor')
                      and os.path.realpath(os.path.join(dp, d)) != os.path.realpath(root)]
            for fn in fns:
                if fn.lower().endswith('.md'):
                    full = os.path.join(dp, fn)
                    docs.append(('../' + os.path.relpath(full, outer).replace(os.sep, '/'), full))
            if dp.count(os.sep) - outer.count(os.sep) >= 2:
                dns[:] = []
    out = []
    for rel, full in docs:
        text = read_text(full)
        if text is None:
            continue
        lines = text.split('\n')
        first = next((l for l in lines if l.startswith('# ')), '')
        if not first or not REMOVAL_TITLE_RE.search(first) or not re.search(r'지시서|directive', first + rel, re.I):
            continue
        secs = md_sections(lines)
        dels, moves, news = [], [], set()
        for lv, title, st, end in secs:
            if lv < 2:
                continue
            body = lines[st + 1:end]
            if NEW_HEAD_RE.search(title):
                for l in body:
                    for t in TICK_RE.findall(l):
                        if path_like(t):
                            news.update(expand_braces(t))
            elif DEL_HEAD_RE.search(title) and not MOVE_HEAD_RE.search(title):
                for k, l in enumerate(body):
                    if l.lstrip().startswith('#'):
                        break
                    for t in TICK_RE.findall(l):
                        if path_like(t) and not t.startswith('git '):
                            for e in expand_braces(t):
                                dels.append((e, st + 2 + k))
                    # 「wiki/a-19.json ~ wiki/a-25.json」 범위
                    for a, b in re.findall(r'`([^`]*\d+[^`]*)`\s*~\s*`([^`]*\d+[^`]*)`', l):
                        dels.extend((x, st + 2 + k) for x in expand_range(a, b))
            elif MOVE_HEAD_RE.search(title):
                for k, l in enumerate(body):
                    cells = [c.strip() for c in l.strip().strip('|').split('|')] if l.strip().startswith('|') else []
                    if len(cells) >= 2:
                        a = TICK_RE.findall(cells[0])
                        b = TICK_RE.findall(cells[1])
                        if a and b and path_like(a[0]) and path_like(b[0]):
                            moves.append((a[0], b[0], st + 2 + k))
        if dels or moves:
            out.append((rel, dels, moves, news))
    return out


def expand_range(a, b):
    ma = re.match(r'^(.*?)(\d+)(\D*)$', a)
    mb = re.match(r'^(.*?)(\d+)(\D*)$', b)
    if not ma or not mb or ma.group(1) != mb.group(1) or ma.group(3) != mb.group(3):
        return [a, b]
    lo, hi = int(ma.group(2)), int(mb.group(2))
    if hi < lo or hi - lo > 200:
        return [a, b]
    return ['%s%d%s' % (ma.group(1), x, ma.group(3)) for x in range(lo, hi + 1)]


def glob_match(pat, rel):
    pat = re.sub(r'<[^>]*>', '*', pat)
    if pat.endswith('/'):
        return rel.startswith(pat)
    if '*' in pat or '?' in pat:
        rx = '^' + re.escape(pat).replace(r'\*\*/', '(?:.*/)?').replace(r'\*\*', '.*').replace(r'\*', '[^/]*') + '$'
        return re.match(rx, rel) is not None
    return rel == pat


def check_removal(root, files, outer, findings, stats):
    fileset = set(files)
    for drel, dels, moves, news in removal_directives(root, files, outer):
        newpats = [n for n in news]
        for pat, ln in dels:
            if pat.startswith('../') or pat.startswith('/'):
                stats['skip'] += 1
                continue
            stats['targets'] += 1
            hits = [f for f in files if glob_match(pat, f) and not any(glob_match(n, f) for n in newpats)]
            if hits:
                shown = ', '.join(hits[:5]) + (' 외 %d' % (len(hits) - 5) if len(hits) > 5 else '')
                findings.append(('낮음', drel, ln, '제거 지시서의 삭제 대상 `%s` 에 걸리는 파일이 아직 트리에 있음: %s' % (pat, shown)))
        for old, new, ln in moves:
            if old.startswith(('../', '/')):
                stats['skip'] += 1
                continue
            stats['targets'] += 1
            if old in fileset or (old.endswith('/') and any(f.startswith(old) for f in files)):
                findings.append(('낮음', drel, ln, '제거 지시서의 이동 표 옛 경로 `%s` 가 아직 있음(옮길 곳 `%s`)' % (old, new)))


# ─── 본체 ────────────────────────────────────────────────────────────────────
def main(argv=None):
    here = os.path.dirname(os.path.abspath(__file__))
    ap = argparse.ArgumentParser(description='R9 기능 목록 대조')
    ap.add_argument('--root', default=os.path.normpath(os.path.join(here, '..', '..')))
    ap.add_argument('--outer', default=None)
    ap.add_argument('--exclude', action='append', default=None)
    a = ap.parse_args(argv)
    root = os.path.abspath(a.root)
    if not os.path.isdir(root):
        print('RESULT pass=0 fail=0 skip=0')
        note('뿌리가 없음: %s' % root)
        return 2
    if a.outer == 'none':
        outer = None
    elif a.outer:
        outer = os.path.abspath(a.outer)
    else:
        # 기본 바깥 배치는 뿌리가 git 저장소 맨 위일 때만 <root>/.. — 견본 폴더에서는 옆 견본을 읽지 않는다
        try:
            p = git(root, 'rev-parse', '--show-toplevel')
            is_top = p.returncode == 0 and os.path.realpath(p.stdout.decode().strip()) == os.path.realpath(root)
        except (OSError, subprocess.SubprocessError):
            is_top = False
        outer = os.path.abspath(os.path.join(root, '..')) if is_top else None
    excludes = DEFAULT_EXCLUDES if a.exclude is None else a.exclude
    try:
        files = list_files(root, excludes)
    except (RuntimeError, OSError, subprocess.SubprocessError) as e:
        print('RESULT pass=0 fail=0 skip=0')
        note(str(e))
        return 2

    findings = []
    stats = {'targets': 0, 'skip': 0}
    d = build_dict(root, files)
    hfiles = help_files(files)
    for rel in hfiles:
        sents = help_sentences(root, rel)
        if sents is None:
            stats['skip'] += 1
            continue
        for ln, val in sents:
            for sent in split_sentences(val):
                for kind, payload, neg in claims_in(sent):
                    if neg:
                        continue
                    if d.files == 0:
                        stats['skip'] += 1
                        continue
                    if kind == 'label':
                        parts = [p for p in re.split(r'\s*(?:→|›|>)\s*', payload) if p.strip()]
                        if any(re.search(r'[@…]|\b[a-zA-Z]\b|\d', p) for p in parts):
                            stats['skip'] += 1
                            continue
                        stats['targets'] += 1
                        miss = [p for p in parts if not label_known(d, p)]
                        if miss:
                            findings.append(('낮음', rel, ln, '도움말이 누르거나 고르라고 안내하는 「%s」 가 화면 코드의 이름(단추·메뉴·창 글자)에 없음'
                                             % '」·「'.join(miss)))
                    elif kind == 'trigger':
                        if not d.rules and not d.keylits:
                            stats['skip'] += 1
                            continue
                        stats['targets'] += 1
                        tok, ph = payload
                        if not trigger_known(d, tok, ph):
                            findings.append(('낮음', rel, ln, '도움말이 「%s」 를 쳐서 쓰는 기능을 안내하는데 화면 코드의 입력 규칙·키 비교에 그 글자를 받는 것이 없음'
                                             % tok))
                    elif kind == 'combo':
                        mods, key, shown = payload
                        stats['targets'] += 1
                        if not combo_known(d, mods, key):
                            findings.append(('낮음', rel, ln, '도움말의 단축키 %s 가 코드의 키 등록에 없음' % shown))
                    elif kind == 'enum':
                        found = [x for x in payload if label_known(d, x)]
                        if len(found) * 2 < len(payload):
                            stats['skip'] += 1
                            continue
                        stats['targets'] += 1
                        miss = [x for x in payload if x not in found]
                        if miss:
                            findings.append(('낮음', rel, ln, '도움말이 화면에 뜬다고 늘어놓은 %d개 가운데 %s 가 화면 코드의 이름에 없음(나머지 %d개는 있음)'
                                             % (len(payload), '·'.join(miss), len(found))))
    check_schema(root, files, findings, stats)
    check_removal(root, files, outer, findings, stats)

    if not hfiles and stats['targets'] == 0:
        print('RESULT pass=0 fail=0 skip=%d' % stats['skip'])
        note('도움말·스키마 단계 표·제거 지시서가 하나도 없음 — 검사 못 함')
        return 2
    seen = set()
    uniq = []
    for f in findings:
        k = (f[1], f[2], f[3])
        if k not in seen:
            seen.add(k)
            uniq.append(f)
    for sev, rel, ln, msg in uniq:
        print('FINDING %s %s %s:%d %s' % (RULE, sev, rel, ln, msg))
    bad_targets = len({(f[1], f[2], f[3]) for f in uniq})
    print('RESULT pass=%d fail=%d skip=%d' % (max(0, stats['targets'] - bad_targets), len(uniq), stats['skip']))
    return 1 if uniq else 0


if __name__ == '__main__':
    sys.exit(main())
