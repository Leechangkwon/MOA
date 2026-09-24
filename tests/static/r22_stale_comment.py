#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
R22 낡았을 수 있는 주석 (stale comment — 의심 올리기) — 점검 봇 v3 정적 규칙, python3 stdlib 만, 파일은 읽기만.

answers: HO-P3-002(SQL_TOUCH 몸을 바꾼 범위 · 같은 파일의 바뀌지 않은 설명 주석) · HO-B1-005(Db::boot 몸을 바꾼 범위 ·
         다른 파일 health.php 의 주석) · HO-7D1-003(결함 bebe652 · 고친 d9678d3) · HO-C-007(참고 — nginx 설정 주석, 입력 밖)
         이 규칙은 **판단 라벨**의 보조다 — 재현율에 넣지 않고 의심 수로만 잰다(주석이 틀렸는지는 사람이 뜻을 읽어야 안다).

무엇을 부류로 잡나:
  범위(--range)가 몸을 바꾼 정의(함수·메서드·상수 — PHP `const SQL_TOUCH = <<<'SQL' …` 같은 SQL 상수 포함)의 이름이
  같은 파일·다른 파일의 **범위가 건드리지 않은 주석**에 나오면 「설명이 낡았을 수 있다」고 올린다. 코드의 뜻이 바뀌었는데
  그 뜻을 설명하던 주석은 그대로인 모양이다.
    · 몸이 바뀜: 범위 시작 판에도 있던 정의이고, 그 정의의 줄 범위(여는 줄 ~ 닫는 괄호·문장 끝·들여쓰기 끝) 안에서 주석·빈 줄이
      아닌 줄이 더해지거나 지워졌다. 범위 끝 판에서 없어진 정의(지운 기호)도 넣는다. 새로 생긴 정의는 넣지 않는다
      (그 이름을 먼저 설명한 옛 주석이 있을 수 없다).
    · 이름 모양: `Db::boot` · `Db->boot` · `boot()`(호출 모양) · 뚜렷한 이름은 맨 이름도(`SQL_TOUCH` 같은 대문자 상수 ·
      `_` 나 낙타 등이 든 6자 이상 이름). 흔한 짧은 이름(`run`·`get`·`boot`)은 맨 이름으로는 보지 않는다.
    · 주석: PHP·JS·CSS 의 `//`·`#`(PHP)·`/* … */`·`*` 줄, 셸·파이썬·설정의 `#`, SQL 의 `--`. 이력을 말하는 줄
      (날짜로 시작하는 표 행 · 옛/이전/→/바뀌기 전 …)은 뺀다(지금 동작의 설명이 아니다).
    · 그 정의 자신의 몸 안 주석은 뺀다(바꾼 사람이 같이 읽은 자리). 정의 바로 위 설명 주석은 넣는다.
    · 동작 설명이 아닌 줄은 뺀다: 형식 태그(@param·@var·@return …) 줄 · 이름 나열(`a·b·c` 처럼 셋 이상 늘어놓은 것).
    · 범위를 좁힌다: 시험(tests/)에서 정의한 기호는 tests/ 의 주석에서만 · 상수는 같은 파일에서 맨 이름으로, 다른
      파일에서는 클래스 붙은 모양(`Auth::SQL_TOUCH`)만 · 몸이 200줄 넘는 정의(화면 전체 init 류)는 보지 않는다.
  심각도: 참고(의심). 권고: 고치지 말고 지시서·검토에 「이 주석을 다시 읽을 것」을 더한다.
  `qcheck-allow: R22` 줄은 뺀다. 기본 제외 tests/static/fixtures/*.

입력: --root <저장소>(기본: 이 파일 기준 저장소 뿌리) · --range A..B | A..(작업 트리) | C(=C^..C), 기본 HEAD..
출력: FINDING R22 참고 <파일:줄> <설명> · 끝 줄 RESULT pass=<바뀐 정의 - 의심을 낳은 정의> fail=<의심 수> skip=<못 읽은 파일>
      의심 하나 = 한 파일에서 한 기호를 가리키는 바뀌지 않은 주석 줄들(첫 줄에 적고 나머지 줄 번호는 설명에).
      날짜가 박힌 파일(마이그레이션·반영 스크립트 — 그때의 기록)의 주석은 보지 않는다.
rc:   0 의심 없음 · 1 의심 있음 · 2 실행 오류(git 저장소 아님·범위 풀기 실패 = 검사 못 함)
한계: 정의 찾기는 줄 단위 근사다(PHP·JS 는 중괄호 짝, 파이썬은 들여쓰기, 셸은 `name() {` ~ `}`). 문자열 안의 괄호가
      짝을 흐리면 몸 범위가 넓어질 수 있다. 주석 뜻이 코드와 맞는지는 보지 않는다(의심만).
"""
import argparse
import fnmatch
import os
import posixpath
import re
import subprocess
import sys

RULE = 'R22'
# 날짜가 박힌 파일(마이그레이션 · 반영 스크립트)은 그때의 기록이다 — 지금 동작의 설명이 아니라서 보지 않는다
RECORD_FILE_RE = re.compile(r'\d{4}-\d{2}-\d{2}')
DEFAULT_EXCLUDES = ['tests/static/fixtures/*']
ALLOW_RE = re.compile(r'qcheck-allow\s*[:=]?\s*(?:[A-Za-z0-9_-]+\s*,\s*)*R22\b')
DEF_EXT = ('.php', '.phtml', '.js', '.mjs', '.py', '.sh', '.bash')
COMMENT_EXT = DEF_EXT + ('.css', '.sql', '.conf', '.service', '.timer', '.ini', '.yml', '.yaml', '.toml')
HIST_RE = re.compile(r'^\s*(?:[|*#/-]\s*)*\|?\s*\d{4}-\d{2}-\d{2}|→|->|=>|옛\s|옛 |이전\b|예전|바뀌기\s*전|바꾸기\s*전|전에는|'
                     r'고치기\s*전|없앴|지웠|폐기|\bformerly\b|\bwas\b|\bold\b|\bprevious', re.I)
GENERIC = {'run', 'get', 'set', 'boot', 'init', 'main', 'open', 'close', 'read', 'write', 'load', 'save', 'call', 'send',
           'test', 'check', 'start', 'stop', 'make', 'build', 'add', 'del', 'put', 'post', 'list', 'find', 'next', 'prev',
           'draw', 'render', 'handle', 'update', 'create', 'delete', 'remove', 'query', 'fetch', 'parse', 'format',
           'value', 'values', 'items', 'data', 'json', 'text', 'html', 'url', 'path', 'name', 'id', 'key', 'log', 'error',
           'note', 'skip', 'expect', 'hr', 'sub', 'cleanup', 'usage', 'help', 'show', 'hide', 'toggle', 'reset',
           'login', 'logout', 'signin', 'session', 'auth', 'request', 'response', 'setup', 'teardown'}
TAG_RE = re.compile(r'@(?:param|var|return|returns|see|type|property|throws|typedef|callback)\b')
LIST_RE = re.compile(r'[\w$:>-]+(?:\(\))?(?:\s*[·,/、]\s*[\w$:>-]+(?:\(\))?){2,}')
BIG_BODY = 200       # 몸이 이보다 긴 정의(화면 전체 init 류)는 바뀐 곳이 다른 주석의 설명과 무관할 때가 많아 보지 않는다
KEYWORDS = {'if', 'for', 'while', 'switch', 'catch', 'function', 'return', 'foreach', 'elseif', 'else', 'do', 'try',
            'with', 'match', 'new', 'typeof', 'await', 'async', 'class', 'constructor', 'super'}


def note(msg):
    sys.stderr.write('R22: %s\n' % msg)


class GitError(RuntimeError):
    pass


def git(root, *args, check=True):
    try:
        p = subprocess.run(['git', '--no-optional-locks', '-C', root] + list(args), stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                           timeout=300)
    except (OSError, subprocess.SubprocessError) as e:
        raise GitError(str(e))
    if check and p.returncode != 0:
        raise GitError('git %s: %s' % (' '.join(args[:3]), p.stderr.decode(errors='replace').strip()))
    return p


def resolve_range(root, spec):
    spec = spec or 'HEAD..'
    if '..' in spec:
        a, b = spec.split('..', 1)
        if b.startswith('.'):
            raise GitError('세 점 범위(A...B)는 받지 않는다')
        base = git(root, 'rev-parse', '--verify', '-q', '--end-of-options', (a or 'HEAD') + '^{commit}').stdout.decode().strip()
        tip = None if b == '' else git(root, 'rev-parse', '--verify', '-q', '--end-of-options',
                                       b + '^{commit}').stdout.decode().strip()
        return base, tip
    tip = git(root, 'rev-parse', '--verify', '-q', '--end-of-options', spec + '^{commit}').stdout.decode().strip()
    base = git(root, 'rev-parse', '--verify', '-q', tip + '^').stdout.decode().strip()
    return base, tip


def show(root, rev, rel):
    if rev is None:
        try:
            with open(os.path.join(root, rel), 'rb') as fh:
                data = fh.read()
        except OSError:
            return None
    else:
        p = git(root, 'show', '%s:%s' % (rev, rel), check=False)
        if p.returncode != 0:
            return None
        data = p.stdout
    if b'\0' in data[:8000] or len(data) > 2 * 1024 * 1024:
        return None
    return data.decode('utf-8', 'replace')


def diff_lines(root, base, tip, rel):
    """(옛 판에서 지운 줄 번호들, 새 판에서 더한 줄 번호들)."""
    args = ['diff', '-U0', '--no-renames', '--no-color', '--no-ext-diff', base] + ([tip] if tip else []) + ['--', rel]
    raw = git(root, *args).stdout.decode('utf-8', 'replace')
    old, new = set(), set()
    for l in raw.split('\n'):
        m = re.match(r'@@ -(\d+)(?:,(\d+))? \+(\d+)(?:,(\d+))? @@', l)
        if m:
            os_, oc = int(m.group(1)), int(m.group(2)) if m.group(2) is not None else 1
            ns, nc = int(m.group(3)), int(m.group(4)) if m.group(4) is not None else 1
            old.update(range(os_, os_ + oc))
            new.update(range(ns, ns + nc))
    return old, new


# ─── 주석 ────────────────────────────────────────────────────────────────────
def lang_of(rel):
    ext = os.path.splitext(rel)[1].lower()
    if ext in ('.php', '.phtml'):
        return 'php'
    if ext in ('.js', '.mjs', '.css'):
        return 'c'
    if ext == '.sql':
        return 'sql'
    if ext in ('.py', '.sh', '.bash', '.conf', '.service', '.timer', '.ini', '.yml', '.yaml', '.toml'):
        return 'hash'
    return None


def comment_map(text, lang):
    """줄 번호 → 그 줄의 주석 글(없으면 빠짐). 줄 단위 근사 — 따옴표 안의 주석 기호는 건너뛴다."""
    out = {}
    in_block = False
    for ln, line in enumerate(text.split('\n'), 1):
        if lang in ('php', 'c'):
            if in_block:
                end = line.find('*/')
                out[ln] = line if end < 0 else line[:end]
                if end >= 0:
                    in_block = False
                continue
            q, i = None, 0
            while i < len(line):
                c = line[i]
                if q:
                    if c == '\\':
                        i += 2
                        continue
                    if c == q:
                        q = None
                elif c in '\'"`':
                    q = c
                elif line.startswith('//', i) or (lang == 'php' and c == '#' and not line.startswith('#[', i)):
                    out[ln] = line[i:]
                    break
                elif line.startswith('/*', i):
                    end = line.find('*/', i + 2)
                    if end < 0:
                        out[ln] = line[i:]
                        in_block = True
                        break
                    out[ln] = out.get(ln, '') + line[i:end + 2]
                    i = end + 2
                    continue
                i += 1
        elif lang == 'hash':
            if ln == 1 and line.startswith('#!'):
                continue
            q = None
            for i, c in enumerate(line):
                if q:
                    if c == q:
                        q = None
                    continue
                if c in '\'"':
                    q = c
                elif c == '#' and (i == 0 or line[i - 1] in ' \t;('):
                    out[ln] = line[i:]
                    break
        elif lang == 'sql':
            k = line.find('--')
            if k >= 0:
                out[ln] = line[k:]
    return out


# ─── 정의 ────────────────────────────────────────────────────────────────────
PHP_CLASS_RE = re.compile(r'^\s*(?:(?:abstract|final|readonly)\s+)*(?:class|trait|interface|enum)\s+([A-Za-z_]\w*)')
PHP_FUNC_RE = re.compile(r'^\s*(?:(?:abstract|final|public|private|protected|static)\s+)*function\s+&?([A-Za-z_]\w*)\s*\(')
PHP_CONST_RE = re.compile(r'^\s*(?:(?:final|public|private|protected)\s+)*const\s+([A-Z_][A-Z0-9_]*)\s*=')
JS_FUNC_RE = re.compile(r'^\s*(?:export\s+)?(?:default\s+)?(?:async\s+)?function\s*\*?\s*([A-Za-z_$][\w$]*)\s*\(')
JS_VARFN_RE = re.compile(r'^\s*(?:export\s+)?(?:const|let|var)\s+([A-Za-z_$][\w$]*)\s*=\s*(?:async\s+)?'
                         r'(?:function\b|\([^)]*\)\s*=>|[A-Za-z_$][\w$]*\s*=>)')
JS_CONST_RE = re.compile(r'^\s*(?:export\s+)?const\s+([A-Z][A-Z0-9_]{2,})\s*=')
JS_METHOD_RE = re.compile(r'^\s+(?:static\s+)?(?:async\s+)?([A-Za-z_$][\w$]*)\s*\([^)]*\)\s*\{\s*$')
PY_DEF_RE = re.compile(r'^(\s*)(?:async\s+)?def\s+([A-Za-z_]\w*)\s*\(|^(\s*)class\s+([A-Za-z_]\w*)')
PY_CONST_RE = re.compile(r'^([A-Z][A-Z0-9_]{2,})\s*(?::[^=]+)?=')
SH_FUNC_RE = re.compile(r'^\s*(?:function\s+)?([A-Za-z_][\w-]*)\s*\(\)\s*(?:\{|\(|$)|^\s*function\s+([A-Za-z_][\w-]*)\s*\{')


def brace_end(lines, i, limit=4000):
    """lines[i] 부터 중괄호 짝이 닫히는 줄(0-기준). 문자열·주석 안 괄호는 대충 건너뛴다."""
    depth, opened = 0, False
    for j in range(i, min(len(lines), i + limit)):
        l = re.sub(r'//.*$|/\*.*?\*/|\'(?:\\.|[^\'\\])*\'|"(?:\\.|[^"\\])*"|`(?:\\.|[^`\\])*`', '', lines[j])
        for c in l:
            if c == '{':
                depth += 1
                opened = True
            elif c == '}':
                depth -= 1
                if opened and depth <= 0:
                    return j
        if not opened and j > i and l.strip().endswith(';'):
            return j            # 몸 없는 선언(abstract · interface)
    return i


def stmt_end(lines, i):
    """const X = …; 의 끝 줄(heredoc/nowdoc 이면 닫는 표식 줄 뒤의 ; 까지)."""
    m = re.search(r"<<<\s*['\"]?([A-Za-z_]\w*)['\"]?\s*$", lines[i])
    if m:
        tag = m.group(1)
        for j in range(i + 1, len(lines)):
            if re.match(r'^\s*' + re.escape(tag) + r'\b', lines[j]):
                return j
        return i
    depth = 0
    for j in range(i, len(lines)):
        l = re.sub(r'\'(?:\\.|[^\'\\])*\'|"(?:\\.|[^"\\])*"', '', lines[j])
        depth += l.count('(') + l.count('[') - l.count(')') - l.count(']')
        if depth <= 0 and l.rstrip().endswith((';', ',')):
            return j
    return i


def indent_end(lines, i, ind):
    j = i + 1
    last = i
    while j < len(lines):
        l = lines[j]
        if l.strip():
            if len(l) - len(l.lstrip()) <= ind and not l.lstrip().startswith((')', ']', '}')):
                break
            last = j
        j += 1
    return last


def definitions(text, lang):
    """[(이름 목록(보고용 첫째), 시작 줄, 끝 줄, 종류)] — 줄은 1-기준."""
    lines = text.split('\n')
    out = []
    ext = lang
    if ext == 'php':
        cls, cls_end = None, -1
        for i, l in enumerate(lines):
            if i > cls_end:
                cls = None
            m = PHP_CLASS_RE.match(l)
            if m:
                cls, cls_end = m.group(1), brace_end(lines, i)
                continue
            m = PHP_FUNC_RE.match(l)
            if m:
                nm = m.group(1)
                if nm.startswith('__'):
                    continue
                e = brace_end(lines, i)
                out.append((forms(nm, cls, 'func'), i + 1, e + 1, 'func'))
                continue
            m = PHP_CONST_RE.match(l)
            if m:
                e = stmt_end(lines, i)
                out.append((forms(m.group(1), cls, 'const'), i + 1, e + 1, 'const'))
    elif ext == 'js':
        for i, l in enumerate(lines):
            m = JS_FUNC_RE.match(l) or JS_VARFN_RE.match(l)
            if m:
                e = brace_end(lines, i) if '{' in ''.join(lines[i:i + 3]) else stmt_end(lines, i)
                out.append((forms(m.group(1), None, 'func'), i + 1, e + 1, 'func'))
                continue
            m = JS_CONST_RE.match(l)
            if m:
                e = stmt_end(lines, i) if '{' not in l and '[' not in l else brace_end(lines, i)
                out.append((forms(m.group(1), None, 'const'), i + 1, e + 1, 'const'))
                continue
            m = JS_METHOD_RE.match(l)
            if m and m.group(1) not in KEYWORDS:
                out.append((forms(m.group(1), None, 'func'), i + 1, brace_end(lines, i) + 1, 'func'))
    elif ext == 'py':
        stack = []
        for i, l in enumerate(lines):
            m = PY_DEF_RE.match(l)
            if m:
                ind = len(m.group(1) if m.group(1) is not None else m.group(3))
                nm = m.group(2) or m.group(4)
                while stack and stack[-1][1] >= ind:
                    stack.pop()
                cls = stack[-1][0] if stack and stack[-1][2] == 'class' else None
                kind = 'func' if m.group(2) else 'class'
                if kind == 'func' and not nm.startswith('__'):
                    out.append((forms(nm, cls, 'func'), i + 1, indent_end(lines, i, ind) + 1, 'func'))
                stack.append((nm, ind, kind))
                continue
            m = PY_CONST_RE.match(l)
            if m:
                out.append((forms(m.group(1), None, 'const'), i + 1, indent_end(lines, i, 0) + 1, 'const'))
    elif ext == 'sh':
        for i, l in enumerate(lines):
            m = SH_FUNC_RE.match(l)
            if m:
                nm = m.group(1) or m.group(2)
                if nm in KEYWORDS:
                    continue
                e = i
                if '{' in l and l.rstrip().endswith('}') and l.count('{') == l.count('}'):
                    e = i
                else:
                    for j in range(i + 1, len(lines)):
                        if re.match(r'^\s*\}\s*(?:[;#].*)?$', lines[j]) and \
                                len(lines[j]) - len(lines[j].lstrip()) <= len(l) - len(l.lstrip()):
                            e = j
                            break
                out.append((forms(nm, None, 'shfunc'), i + 1, e + 1, 'func'))
    return out


def forms(nm, cls, kind):
    """(보고 이름, [찾을 정규식 조각], [다른 파일에서 찾을 조각]) — 주석에서 이 기호를 가리키는 모양들.
    상수는 같은 파일에서만 맨 이름으로 찾고, 다른 파일에서는 클래스 붙은 모양(`Auth::SQL_TOUCH`)만 본다."""
    pats = []
    shown = ('%s::%s' % (cls, nm)) if cls else nm
    qual = []
    if cls:
        qual.append(re.escape(cls) + r'\s*(?:::|->|\.)\s*' + re.escape(nm) + r'\b')
        pats.extend(qual)
    distinct = (kind == 'const' and (len(nm) >= 5 or '_' in nm)) or \
        (nm.lower() not in GENERIC and len(nm) >= 6 and ('_' in nm.strip('_') or re.search(r'[a-z][A-Z]', nm)))
    if distinct:
        pats.append(r'(?<![\w$>:.-])' + re.escape(nm) + r'(?![\w-])')
    elif nm.lower() not in GENERIC and len(nm) >= 4:
        pats.append(r'(?<![\w$>:.-])' + re.escape(nm) + r'\(\)')
    other = qual if kind == 'const' else pats
    return shown, pats, other


def def_lang(rel):
    ext = os.path.splitext(rel)[1].lower()
    return {'.php': 'php', '.phtml': 'php', '.js': 'js', '.mjs': 'js', '.py': 'py', '.sh': 'sh', '.bash': 'sh'}.get(ext)


def body_changed(start, end, touched, lines):
    """정의 줄 범위 안에서 주석·빈 줄이 아닌 줄이 바뀌었나."""
    for k in touched:
        if start <= k <= end and 0 < k <= len(lines):
            l = lines[k - 1].strip()
            if l and not re.match(r'^(?://|#(?!\[)|\*|/\*|--|\*/)', l):
                return True
    return False


def list_files(root, tip):
    if tip is None:
        r = git(root, '-c', 'core.quotepath=off', 'ls-files', '-z', '--cached', '--others', '--exclude-standard')
    else:
        r = git(root, '-c', 'core.quotepath=off', 'ls-tree', '-r', '--name-only', '-z', tip)
    return sorted(set(x for x in r.stdout.decode('utf-8', 'surrogateescape').split('\0') if x))


def main(argv=None):
    here = os.path.dirname(os.path.abspath(__file__))
    ap = argparse.ArgumentParser(description='R22 낡았을 수 있는 주석 — 범위가 몸을 바꾼 기호를 가리키는 바뀌지 않은 주석(의심)')
    ap.add_argument('--root', default=os.path.normpath(os.path.join(here, '..', '..')))
    ap.add_argument('--range', dest='range_', default=None)
    ap.add_argument('--exclude', action='append', default=None)
    a = ap.parse_args(argv)
    root = os.path.abspath(a.root)
    excludes = DEFAULT_EXCLUDES if a.exclude is None else a.exclude

    def broken(msg):
        print('RESULT pass=0 fail=0 skip=1')
        note(msg)
        return 2

    if not os.path.isdir(root):
        return broken('뿌리가 없음: %s' % root)
    try:
        top = git(root, 'rev-parse', '--show-toplevel').stdout.decode().strip()
        if os.path.realpath(top) != os.path.realpath(root):
            return broken('--root 는 저장소 맨 위여야 한다(%s)' % top)
        base, tip = resolve_range(root, a.range_)
        toks = [x for x in git(root, 'diff', '--name-status', '-z', '--no-renames', base, *([tip] if tip else [])
                               ).stdout.decode('utf-8', 'surrogateescape').split('\0') if x]
        changes = [(toks[i][0], toks[i + 1]) for i in range(0, len(toks) - 1, 2)]
        files = list_files(root, tip)
    except GitError as e:
        return broken('범위를 풀지 못함: %s' % e)

    skip = 0
    changed_defs = []        # (보고 이름, 정규식들, 정의 파일, 정의 줄, 몸 범위(파일, 시작, 끝) | None, 지움?)
    added_by_file = {}
    for st, rel in changes:
        if any(fnmatch.fnmatch(rel, g) for g in excludes):
            continue
        lang = def_lang(rel)
        if st not in ('M', 'D'):
            continue        # 새 파일의 정의는 새 이름이다(옛 설명이 있을 수 없다)
        old_text = show(root, base, rel)
        new_text = show(root, tip, rel) if st == 'M' else ''
        if old_text is None or new_text is None:
            skip += 1
            continue
        try:
            rem, add = diff_lines(root, base, tip, rel)
        except GitError:
            skip += 1
            continue
        added_by_file[rel] = add
        if lang is None:
            continue
        old_defs = definitions(old_text, lang)
        new_defs = definitions(new_text, lang)
        new_by = {}
        for d in new_defs:
            new_by.setdefault(d[0][0], []).append(d)
        old_lines, new_lines = old_text.split('\n'), new_text.split('\n')
        seen = set()
        for (shown, pats, other), s0, e0, kind in old_defs:
            if shown in seen or not pats:
                continue
            if e0 - s0 > BIG_BODY:
                continue
            # 시험(tests/)에서 정의한 기호(도우미 함수 · 상수)는 시험 주석에서만 찾는다 — 제품 코드의 같은 이름과 섞이지 않게
            scope = 'tests/' if rel.startswith('tests/') else ''
            nd = new_by.get(shown)
            if not nd:
                seen.add(shown)
                changed_defs.append((shown, pats, rel, s0, None, True, other, scope))
                continue
            _, s1, e1, _ = nd[0]
            if e1 - s1 > BIG_BODY:
                continue
            if body_changed(s0, e0, rem, old_lines) or body_changed(s1, e1, add, new_lines):
                seen.add(shown)
                changed_defs.append((shown, pats, rel, s1, (rel, s1, e1), False, other, scope))

    suspects = {}
    hit_defs = set()
    if changed_defs:
        rx = [(d, re.compile('|'.join('(?:%s)' % p for p in d[1])),
               re.compile('|'.join('(?:%s)' % p for p in d[6])) if d[6] else None) for d in changed_defs]
        for rel in files:
            if any(fnmatch.fnmatch(rel, g) for g in excludes) or not rel.lower().endswith(COMMENT_EXT):
                continue
            if '/vendor/' in '/' + rel or '/node_modules/' in '/' + rel or RECORD_FILE_RE.search(rel):
                continue
            text = show(root, tip, rel)
            if text is None:
                continue
            lang = lang_of(rel)
            if lang is None:
                continue
            cm = comment_map(text, lang)
            if not cm:
                continue
            touched = added_by_file.get(rel, set())
            lines = text.split('\n')
            for ln, ctext in cm.items():
                if ln in touched or ALLOW_RE.search(lines[ln - 1]) or HIST_RE.search(ctext):
                    continue
                for d, r_same, r_other in rx:
                    if d[7] and not rel.startswith(d[7]):
                        continue
                    r = r_same if rel == d[2] else r_other
                    m = r.search(ctext) if r is not None else None
                    if not m:
                        continue
                    # 동작 설명이 아닌 줄 — 형식 태그(@param …)와 이름 나열(`initFiles·initTrash·initAccount`)은 뺀다
                    if TAG_RE.search(ctext) or any(lm.start() <= m.start() < lm.end() and
                                                   len(re.findall(r'[·,/、]', lm.group(0))) >= 2
                                                   for lm in LIST_RE.finditer(ctext)):
                        continue
                    span = d[4]
                    if span and span[0] == rel and span[1] < ln <= span[2]:
                        continue       # 그 정의 자신의 몸 안 주석
                    key = (rel, ln)
                    if key not in suspects:
                        suspects[key] = []
                    if d[0] not in suspects[key]:
                        suspects[key].append(d)
                    hit_defs.add(d[0])
    # 같은 파일에서 같은 기호를 가리키는 주석 줄은 한 의심으로 묶는다(첫 줄에 적고 나머지 줄 번호는 설명에)
    grouped = {}
    for (rel, ln), ds in sorted(suspects.items()):
        for d in ds:
            grouped.setdefault((rel, d[0]), {'d': d, 'lines': []})['lines'].append(ln)
    out = []
    for (rel, _), g in sorted(grouped.items(), key=lambda x: (x[0][0], x[1]['lines'][0])):
        d, ls = g['d'], g['lines']
        more = '' if len(ls) == 1 else ' · 같은 파일 %d줄 더(%s%s)' % (
            len(ls) - 1, '·'.join(str(x) for x in ls[1:7]), ' …' if len(ls) > 7 else '')
        out.append('FINDING %s 참고 %s:%d 범위가 몸을 바꾼 %s(%s%s:%d) 을(를) 가리키는 주석이 바뀌지 않았다%s — 설명이 낡았을 수 '
                   '있다(의심 · 사람이 뜻을 대조 · 고치지 말고 검토·지시서에 「이 주석 다시 읽기」를 더할 것)'
                   % (RULE, rel, ls[0], d[0], '지움 ' if d[5] else '', d[2], d[3], more))
    for l in out:
        print(l)
    ndefs = len({d[0] for d in changed_defs})
    print('RESULT pass=%d fail=%d skip=%d' % (max(0, ndefs - len(hit_defs)), len(out), skip))
    note('범위 %s..%s · 몸이 바뀐 정의 %d · 의심 %d' % (base[:7], tip[:7] if tip else 'WORKTREE', ndefs, len(out)))
    return 1 if out else 0


if __name__ == '__main__':
    try:
        sys.exit(main())
    except KeyboardInterrupt:
        sys.exit(2)
    except Exception as ex:     # 실행 오류 = 검사 못 함(H-1)
        sys.stderr.write('R22: 실행 오류: %s: %s\n' % (ex.__class__.__name__, ex))
        print('RESULT pass=0 fail=0 skip=1')
        sys.exit(2)
