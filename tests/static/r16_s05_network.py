#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
R16 S-05 넓히기 (network I/O outside Fetch) — 1-F 정적 규칙, python3 stdlib 만, 파일은 읽기만.

answers: F-71 채점 뒤 train 번호(예정) — 1-F 지시서 「추가(R15·R16)」의 부류 정의로 만든다(보류 집합은 열지 않았다)

무엇을 부류로 잡나:
  S-05 의 뜻은 「서버가 밖으로 여는 모든 네트워크 입출력은 src/Fetch.php 한 곳」이다. 그러니 주소가
  상수든 루프백이든 모양과 상관없이, 그 밖의 앱 코드(src/·public/ 의 PHP)가 PHP 의 스트림·소켓 함수로
  네트워크를 열면 경고한다. 지금 security_lint S-05 는 몇몇 함수에 **줄 안의** http·ftp 문자열이 오거나
  이름이 url 같은 변수만 본다. 이 규칙은 넓혀서
    · 스트림 함수(fopen · file_get_contents · file · readfile · copy · get_headers · get_meta_tags ·
      `new SplFileObject` · include/require)의 인자 가운데 하나라도 정적으로 모은 값이
      URL 스킴(http · https · ftp · ftps · tcp · ssl · tls · udp) 또는 `IP:포트`·`[IPv6]:포트`·`localhost:포트`
      모양으로 **시작하면** 잡는다. 값은 문자열 · 문자열 잇기(`.`, 앞부분만 알아도 된다) · 보간 문자열의 앞부분 ·
      클래스 상수(`self::X`·`Cls::X`) · 전역 상수 · 같은 함수 안에서 그 변수에 앞서 넣은 값에서 모은다.
    · 소켓 전용 함수(fsockopen · pfsockopen · stream_socket_client · stream_socket_server · socket_connect ·
      socket_sendto)는 네트워크 말고 쓸 곳이 없으므로 인자와 상관없이 잡는다(`unix://`·`udg://` 로 시작하는
      로컬 소켓 값만 뺀다).
  대상은 src/·public/ 만(시험·배치 스크립트의 루프백 주소는 정상), src/Fetch.php 는 S-05 가 정한 자리라 뺀다.
  주석 · 문자열 안의 글자는 호출이 아니므로 보지 않는다(PHP 토막 나누기로 가른다). 주소 인자를 정적으로
  모를 때(변수·함수 결과)는 검사하지 않고 skip 으로 센다.

입력: --root <트리>(기본: 이 파일 기준 저장소 뿌리)
출력: FINDING R16 <심각도> <파일:줄> <설명> · 끝 줄 RESULT pass= fail= skip=
      설명의 주소는 스킴·호스트까지만 적는다. 경로·질의는 버리고 포트는 `‹포트›`, 사용자 정보는 `‹가림›` 으로 가린다.
rc:   0 발견 없음 · 1 발견 있음 · 2 실행 오류(= 검사 못 함)
"""
import argparse
import os
import re
import sys

RULE = 'R16'
SCAN_DIRS = ('src', 'public')
EXCLUDE_REL = {os.path.join('src', 'Fetch.php')}
SKIP_DIRS = {'.git', 'vendor', 'node_modules'}
STREAM_FUNCS = {'fopen', 'file_get_contents', 'file', 'readfile', 'copy', 'get_headers', 'get_meta_tags'}
SOCKET_FUNCS = {'fsockopen', 'pfsockopen', 'stream_socket_client', 'stream_socket_server',
                'socket_connect', 'socket_sendto'}
INCLUDES = {'include', 'include_once', 'require', 'require_once'}
# 주소가 오는 인자 자리(0부터). 적지 않은 함수는 첫 인자.
ADDR_ARGS = {'copy': (0, 1), 'socket_connect': (1,), 'socket_sendto': (4,)}
URL_RE = re.compile(r'^\s*(https?|ftps?|tcp|ssl|tls|udp)://', re.I)
HOSTPORT_RE = re.compile(r'^\s*(?:\d{1,3}(?:\.\d{1,3}){3}|\[[0-9A-Fa-f:.]+\]|localhost):\d{1,5}(?:$|[/?#\s])', re.I)
LOCAL_SOCK_RE = re.compile(r'^\s*(unix|udg)://', re.I)

# ─────────────────────────── PHP 토막 나누기 ───────────────────────────
MULTI_OPS = ['<<=', '>>=', '**=', '...', '===', '!==', '<=>', '??=', '?->',
             '::', '->', '=>', '==', '!=', '<>', '<=', '>=', '&&', '||', '??', '++', '--',
             '+=', '-=', '*=', '/=', '.=', '%=', '&=', '|=', '^=', '<<', '>>', '**']
DQ_ESC = {'n': '\n', 't': '\t', 'r': '\r', 'v': '\v', 'f': '\f', '0': '\0', '"': '"', '\\': '\\', '$': '$', 'e': '\x1b'}


class Tok:
    __slots__ = ('kind', 'val', 'line')

    def __init__(self, kind, val, line):
        self.kind, self.val, self.line = kind, val, line

    def __repr__(self):
        return '%s:%r@%d' % (self.kind, self.val, self.line)


def lex_php(src):
    """PHP 원문 → 토막 목록. kind: str(보간 없는 문자열) · istr(보간 있는 문자열) · var · ident · num · op.
    주석과 PHP 밖 HTML 은 버린다(주석 안의 호출·주소는 코드가 아니다)."""
    toks = []
    i, n, line = 0, len(src), 1
    in_php = False
    while i < n:
        if not in_php:
            j = src.find('<?', i)
            if j < 0:
                break
            line += src.count('\n', i, j)
            if src.startswith('<?php', j):
                i = j + 5
            elif src.startswith('<?=', j):
                i = j + 3
            else:
                i = j + 2
            in_php = True
            continue
        c = src[i]
        if c == '\n':
            line += 1
            i += 1
            continue
        if c in ' \t\r\f\v':
            i += 1
            continue
        if src.startswith('?>', i):
            in_php = False
            toks.append(Tok('op', ';', line))
            i += 2
            continue
        if src.startswith('//', i) or (c == '#' and not src.startswith('#[', i)):
            j = i
            while j < n and src[j] != '\n' and not src.startswith('?>', j):
                j += 1
            i = j
            continue
        if src.startswith('/*', i):
            j = src.find('*/', i + 2)
            j = n if j < 0 else j + 2
            line += src.count('\n', i, j)
            i = j
            continue
        if src.startswith('<<<', i):
            m = re.match(r'<<<[ \t]*(["\']?)([A-Za-z_][A-Za-z0-9_]*)\1[ \t]*\r?\n', src[i:])
            if m:
                ident = m.group(2)
                body_start = i + m.end()
                close = re.compile(r'^[ \t]*' + re.escape(ident) + r'\b', re.M)
                cm = close.search(src, body_start)
                if cm is None:
                    body_end = n
                    after = n
                else:
                    body_end = cm.start()
                    after = cm.end()
                body = src[body_start:body_end]
                nowdoc = m.group(1) == "'"
                interp = (not nowdoc) and re.search(r'\$[A-Za-z_{]|\{\$', body) is not None
                toks.append(Tok('istr' if interp else 'str', body, line))
                line += src.count('\n', i, after)
                i = after
                continue
        if c == "'":
            j = i + 1
            buf = []
            while j < n:
                ch = src[j]
                if ch == '\\' and j + 1 < n and src[j + 1] in "\\'":
                    buf.append(src[j + 1])
                    j += 2
                    continue
                if ch == "'":
                    break
                buf.append(ch)
                j += 1
            toks.append(Tok('str', ''.join(buf), line))
            line += src.count('\n', i, j + 1)
            i = j + 1
            continue
        if c == '"' or c == '`':
            q = c
            j = i + 1
            buf = []
            interp = False
            while j < n:
                ch = src[j]
                if ch == '\\' and j + 1 < n:
                    nx = src[j + 1]
                    buf.append(DQ_ESC.get(nx, '\\' + nx))
                    j += 2
                    continue
                if ch == q:
                    break
                if ch == '$' and j + 1 < n and (src[j + 1].isalpha() or src[j + 1] in '_{'):
                    interp = True
                if ch == '{' and j + 1 < n and src[j + 1] == '$':
                    interp = True
                buf.append(ch)
                j += 1
            toks.append(Tok('istr' if (interp or q == '`') else 'str', ''.join(buf), line))
            line += src.count('\n', i, j + 1)
            i = j + 1
            continue
        if c == '$' and i + 1 < n and (src[i + 1].isalpha() or src[i + 1] == '_' or ord(src[i + 1]) > 127):
            j = i + 1
            while j < n and (src[j].isalnum() or src[j] == '_' or ord(src[j]) > 127):
                j += 1
            toks.append(Tok('var', src[i + 1:j], line))
            i = j
            continue
        if c.isalpha() or c == '_' or c == '\\' or ord(c) > 127:
            j = i
            while j < n and (src[j].isalnum() or src[j] in '_\\' or ord(src[j]) > 127):
                j += 1
            toks.append(Tok('ident', src[i:j], line))
            i = j
            continue
        if c.isdigit():
            m = re.match(r'0[xX][0-9a-fA-F_]+|0[bB][01_]+|\d[\d_]*(?:\.\d+)?(?:[eE][+-]?\d+)?', src[i:])
            toks.append(Tok('num', m.group(0), line))
            i += m.end()
            continue
        for op in MULTI_OPS:
            if src.startswith(op, i):
                toks.append(Tok('op', op, line))
                i += len(op)
                break
        else:
            toks.append(Tok('op', c, line))
            i += 1
    return toks


def match_brackets(toks):
    """여는 괄호 위치 → 닫는 괄호 위치 (), [], {} 모두."""
    pairs = {}
    stack = []
    opener = {'(': ')', '[': ']', '{': '}'}
    closer = {')', ']', '}'}
    for k, t in enumerate(toks):
        if t.kind != 'op':
            continue
        if t.val in opener:
            stack.append(k)
        elif t.val in closer:
            # 짝이 맞는 가장 가까운 여는 괄호까지 푼다(깨진 파일에도 견딘다)
            while stack:
                o = stack.pop()
                if opener[toks[o].val] == t.val:
                    pairs[o] = k
                    break
    return pairs


def split_args(toks, lo, hi):
    """toks[lo:hi] (괄호 안쪽) 를 최상위 쉼표로 나눈 [(a,b), …] 범위."""
    out = []
    depth = 0
    start = lo
    for k in range(lo, hi):
        t = toks[k]
        if t.kind == 'op':
            if t.val in '([{':
                depth += 1
            elif t.val in ')]}':
                depth -= 1
            elif t.val == ',' and depth == 0:
                out.append((start, k))
                start = k + 1
    if start < hi:
        out.append((start, hi))
    return out


# ─────────────────────────── 값 모으기 ───────────────────────────
def short_name(name):
    return name.rsplit('\\', 1)[-1]


def class_ranges(toks, pairs):
    out = []
    for k in range(len(toks) - 1):
        t = toks[k]
        if t.kind == 'ident' and t.val.lower() in ('class', 'trait', 'interface', 'enum') \
                and toks[k + 1].kind == 'ident' and not (k > 0 and toks[k - 1].val in ('::', '->', 'new')):
            j = k + 2
            while j < len(toks) and not (toks[j].kind == 'op' and toks[j].val in ('{', ';')):
                j += 1
            if j < len(toks) and toks[j].val == '{' and j in pairs:
                out.append((j, pairs[j], toks[k + 1].val))
    return out


def class_at(ranges, pos):
    best, bo = '', -1
    for o, c, name in ranges:
        if o < pos < c and o > bo:
            best, bo = name, o
    return best


class Consts:
    def __init__(self):
        self.cls = {}   # (클래스, 이름) → (토막 목록, lo, hi)
        self.glob = {}  # 이름 → (토막 목록, lo, hi)


def collect_consts(cs, toks, pairs, ranges):
    n = len(toks)
    for k in range(n - 3):
        t = toks[k]
        if t.kind == 'ident' and t.val.lower() == 'const':
            j = k + 1
            if toks[j].kind == 'ident' and j + 1 < n and toks[j + 1].kind == 'ident':
                j += 1   # typed const
            if toks[j].kind != 'ident' or j + 1 >= n or toks[j + 1].val != '=':
                continue
            a = j + 2
            b = a
            depth = 0
            while b < n:
                v = toks[b].val if toks[b].kind == 'op' else None
                if v in ('(', '[', '{'):
                    depth += 1
                elif v in (')', ']', '}'):
                    if depth == 0:
                        break
                    depth -= 1
                elif v in (';', ',') and depth == 0:
                    break
                b += 1
            owner = class_at(ranges, k)
            if owner:
                cs.cls[(owner, toks[j].val)] = (toks, a, b)
            else:
                cs.glob[toks[j].val] = (toks, a, b)
        if t.kind == 'ident' and t.val.lower() == 'define' and toks[k + 1].val == '(' and toks[k + 2].kind == 'str' \
                and toks[k + 3].val == ',' and (k + 1) in pairs:
            cs.glob[toks[k + 2].val] = (toks, k + 4, pairs[k + 1])


LOCAL_PATH_FUNCS = {'dirname', 'realpath', 'sys_get_temp_dir', 'tempnam', 'basename'}


def prefixes(env, toks, lo, hi, depth=0):
    """toks[lo:hi] 가 가질 수 있는 문자열의 (앞부분, 온전함) 목록. 전혀 모르면 None."""
    if depth > 8 or hi <= lo:
        return None
    # 바깥 괄호
    while hi - lo >= 2 and toks[lo].val == '(' and env['pairs'].get(lo) == hi - 1:
        lo, hi = lo + 1, hi - 1
    # 형 변환 (string)
    if hi - lo >= 4 and toks[lo].val == '(' and toks[lo + 1].kind == 'ident' and toks[lo + 1].val.lower() == 'string' \
            and toks[lo + 2].val == ')':
        lo += 3
    # @ 억제
    while lo < hi and toks[lo].kind == 'op' and toks[lo].val == '@':
        lo += 1
    # 최상위 삼항·?? — 각 갈래의 값을 모두 모은다
    d = 0
    for k in range(lo, hi):
        t = toks[k]
        if t.kind != 'op':
            continue
        if t.val in '([{':
            d += 1
        elif t.val in ')]}':
            d -= 1
        elif d == 0 and t.val in ('?', '??'):
            out = []
            if t.val == '??':
                parts = [(lo, k), (k + 1, hi)]
            else:
                c = None
                dd = 0
                for m in range(k + 1, hi):
                    v = toks[m].val if toks[m].kind == 'op' else None
                    if v in ('(', '[', '{'):
                        dd += 1
                    elif v in (')', ']', '}'):
                        dd -= 1
                    elif v == ':' and dd == 0:
                        c = m
                        break
                if c is None:
                    return None
                parts = [(k + 1 if c > k + 1 else lo, c), (c + 1, hi)]
            for a, b in parts:
                p = prefixes(env, toks, a, b, depth + 1)
                if p:
                    out.extend(p)
            return out or None
    # 최상위 잇기  a . b . c
    pieces = []
    d = 0
    start = lo
    for k in range(lo, hi):
        t = toks[k]
        if t.kind == 'op':
            if t.val in '([{':
                d += 1
            elif t.val in ')]}':
                d -= 1
            elif d == 0 and t.val == '.':
                pieces.append((start, k))
                start = k + 1
    pieces.append((start, hi))
    if len(pieces) > 1:
        acc = [('', True)]
        for a, b in pieces:
            p = prefixes(env, toks, a, b, depth + 1)
            nxt = []
            for s, exact in acc:
                if not exact:
                    nxt.append((s, False))
                    continue
                if p is None:
                    nxt.append((s, False))
                else:
                    for s2, e2 in p:
                        nxt.append((s + s2, e2))
            acc = nxt
        acc = [x for x in acc if x[0] != '' or x[1]]
        return acc or None
    # 원자
    t = toks[lo]
    if hi - lo == 1:
        if t.kind == 'str':
            return [(t.val, True)]
        if t.kind == 'istr':
            m = re.search(r'\$|\{\$', t.val)
            return [(t.val[:m.start()] if m else t.val, m is None)]
        if t.kind == 'ident':
            if t.val in ('__DIR__', '__FILE__'):
                return [('/', False)]
            g = env['consts'].glob.get(short_name(t.val))
            if g is not None:
                return prefixes(dict(env, pairs=env['pairs_of'](g[0])), g[0], g[1], g[2], depth + 1)
            return None
        if t.kind == 'var':
            return var_prefixes(env, toks, lo, t.val, depth)
        return None
    if hi - lo == 3 and t.kind == 'ident' and toks[lo + 1].val == '::' and toks[lo + 2].kind == 'ident':
        cls = short_name(t.val)
        if cls.lower() in ('self', 'static'):
            cls = class_at(env['ranges'], lo)
        g = env['consts'].cls.get((cls, toks[lo + 2].val))
        if g is not None:
            return prefixes(dict(env, pairs=env['pairs_of'](g[0])), g[0], g[1], g[2], depth + 1)
        return None
    # 함수 호출: sprintf 류의 형식 앞부분 · 로컬 경로 함수
    if t.kind == 'ident' and toks[lo + 1].val == '(' and env['pairs'].get(lo + 1) == hi - 1:
        fn = short_name(t.val).lower()
        if fn in ('sprintf', 'vsprintf'):
            args = split_args(toks, lo + 2, hi - 1)
            if args:
                p = prefixes(env, toks, args[0][0], args[0][1], depth + 1)
                if p:
                    return [(s.split('%', 1)[0], ('%' not in s) and e) for s, e in p]
            return None
        if fn in LOCAL_PATH_FUNCS:
            return [('/', False)]
    return None


def var_prefixes(env, toks, pos, name, depth):
    """같은 함수(또는 파일 최상위)에서 pos 앞의 가장 가까운 `$name = …;` 의 값."""
    lo = env['scope_lo'](pos)
    j = pos - 1
    while j >= lo:
        t = toks[j]
        if t.kind == 'var' and t.val == name and j + 1 < len(toks) and toks[j + 1].kind == 'op' \
                and toks[j + 1].val == '=' and (j == 0 or toks[j - 1].val in (';', '{', '}')):
            a = j + 2
            b = a
            d = 0
            while b < len(toks):
                v = toks[b].val if toks[b].kind == 'op' else None
                if v in ('(', '[', '{'):
                    d += 1
                elif v in (')', ']', '}'):
                    if d == 0:
                        break
                    d -= 1
                elif v == ';' and d == 0:
                    break
                b += 1
            return prefixes(env, toks, a, b, depth + 1)
        j -= 1
    return None


def looks_network(p):
    for s, exact in p:
        if URL_RE.match(s):
            return True
        if HOSTPORT_RE.match(s):
            return True
        if not exact and re.match(r'^\s*(?:\d{1,3}(?:\.\d{1,3}){3}|\[[0-9A-Fa-f:.]+\]|localhost):\d{0,5}$', s, re.I):
            return True
    return False


# ─────────────────────────── 트리 읽기 ───────────────────────────
def php_files(root):
    for top in SCAN_DIRS:
        base = os.path.join(root, top)
        if not os.path.isdir(base):
            continue
        for dp, dns, fns in os.walk(base):
            dns[:] = sorted(d for d in dns if d not in SKIP_DIRS)
            for fn in sorted(fns):
                if fn.endswith(('.php', '.phtml', '.inc')):
                    p = os.path.join(dp, fn)
                    if os.path.relpath(p, root) in EXCLUDE_REL:
                        continue
                    yield p


def read_text(path):
    with open(path, 'rb') as f:
        return f.read().decode('utf-8', errors='replace')


def main():
    ap = argparse.ArgumentParser(description='R16 S-05 넓히기 — Fetch.php 밖의 네트워크 입출력')
    ap.add_argument('--root', default=os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..')))
    ap.add_argument('--verbose', action='store_true')
    args = ap.parse_args()
    root = os.path.abspath(args.root)
    if not os.path.isdir(root) or not any(os.path.isdir(os.path.join(root, d)) for d in SCAN_DIRS):
        print('ERROR R16 대상 트리(src/·public/)가 없음: %s' % root)
        print('RESULT pass=0 fail=0 skip=1')
        return 2

    files = []
    skipped = 0
    notes = []
    for path in php_files(root):
        try:
            toks = lex_php(read_text(path))
        except Exception as e:
            skipped += 1
            notes.append('%s: 읽기 실패 %s' % (rel(root, path), e))
            continue
        pairs = match_brackets(toks)
        files.append((path, toks, pairs, class_ranges(toks, pairs)))

    cs = Consts()
    pairs_by_id = {}
    for path, toks, pairs, ranges in files:
        collect_consts(cs, toks, pairs, ranges)
        pairs_by_id[id(toks)] = pairs

    findings = []
    checked = 0
    for path, toks, pairs, ranges in files:
        funcs = []
        for k in range(len(toks) - 2):
            if toks[k].kind == 'ident' and toks[k].val.lower() in ('function', 'fn'):
                j = k + 1
                while j < len(toks) and not (toks[j].kind == 'op' and toks[j].val in ('{', ';')):
                    j += 1
                if j < len(toks) and toks[j].val == '{' and j in pairs:
                    funcs.append((j, pairs[j]))

        def scope_lo(pos, funcs=funcs):
            best = 0
            for o, c in funcs:
                if o < pos < c and o + 1 > best:
                    best = o + 1
            return best

        env = {'pairs': pairs, 'consts': cs, 'ranges': ranges, 'scope_lo': scope_lo,
               'pairs_of': lambda tk: pairs_by_id.get(id(tk), {})}
        for k, t in enumerate(toks):
            if t.kind != 'ident':
                continue
            name = short_name(t.val).lower()
            prev = toks[k - 1].val if k > 0 else ''
            kind = None
            if name in INCLUDES and prev not in ('->', '::', '?->'):
                kind = 'include'
            elif k + 1 < len(toks) and toks[k + 1].val == '(':
                if prev in ('->', '::', '?->', 'function'):
                    continue
                if prev == 'new':
                    if name == 'splfileobject':
                        kind = 'stream'
                elif name in STREAM_FUNCS:
                    kind = 'stream'
                elif name in SOCKET_FUNCS:
                    kind = 'socket'
            if kind is None:
                continue
            # 인자 범위
            if kind == 'include':
                a = k + 1
                b = a
                d = 0
                while b < len(toks):
                    v = toks[b].val if toks[b].kind == 'op' else None
                    if v in ('(', '[', '{'):
                        d += 1
                    elif v in (')', ']', '}'):
                        if d == 0:
                            break
                        d -= 1
                    elif v == ';' and d == 0:
                        break
                    b += 1
                argv = [(a, b)]
            else:
                pc = pairs.get(k + 1)
                if pc is None:
                    continue
                argv = split_args(toks, k + 2, pc)
            fname = short_name(t.val) + ('' if kind == 'include' else '()')
            if prev == 'new':
                fname = 'new ' + fname
            known_any = False
            hit = None
            local_sock = False
            want = ADDR_ARGS.get(name, (0,))
            for idx, (a, b) in enumerate(argv):
                if idx not in want:
                    continue
                p = prefixes(env, toks, a, b)
                if p is None:
                    continue
                known_any = True
                if any(LOCAL_SOCK_RE.match(s) for s, _ in p):
                    local_sock = True
                if looks_network(p):
                    hit = sorted({s for s, _ in p if URL_RE.match(s) or HOSTPORT_RE.match(s)
                                  or re.match(r'^\s*[\d.\[\]a-fA-F:]+:\d*$', s)} or {p[0][0]})[0]
                    break
            if kind == 'socket':
                if local_sock and hit is None:
                    checked += 1
                    continue
                checked += 1
                why = ('주소 %s' % shorten(hit)) if hit else '소켓 전용 함수'
                findings.append((path, t.line, '%s 로 네트워크를 연다(%s) — 외부·내부 요청은 src/Fetch.php 한 곳(S-05)'
                                 % (fname, why)))
                continue
            if hit is not None:
                checked += 1
                findings.append((path, t.line, '%s 에 네트워크 주소 %s — 외부·내부 요청은 src/Fetch.php 한 곳(S-05)'
                                 % (fname, shorten(hit))))
            elif known_any:
                checked += 1
            else:
                skipped += 1
                notes.append('%s:%d %s 주소 인자를 정적으로 모름' % (rel(root, path), t.line, fname))

    for path, line, msg in findings:
        print('FINDING %s 중간 %s:%d %s' % (RULE, rel(root, path), line, msg))
    if args.verbose:
        for s in notes:
            print('SKIP R16 ' + s)
    print('RESULT pass=%d fail=%d skip=%d' % (max(checked - len({(p, l) for p, l, _ in findings}), 0),
                                             len(findings), skipped))
    return 1 if findings else 0


ADDR_SHOW_RE = re.compile(r'^(?:(?P<scheme>[a-z][a-z0-9+.\-]*)://)?(?:(?P<userinfo>[^@/\s?#]*)@)?'
                          r'(?P<host>\[[^\]\s/]*\]|[^:/\s?#\[\]@]*)(?::(?P<port>\d*))?', re.I)


def shorten(s):
    s = s.strip()
    # 주소는 스킴·호스트까지만 보인다. 경로·질의는 버리고(값이 섞일 수 있다), 사용자 정보(user:pass@)와
    # 포트는 가린다 — 내부 주소의 열린 포트가 요약·원문 로그에 남지 않게. 예: tcp://10.0.0.5:6379 → tcp://10.0.0.5:‹포트›
    m = ADDR_SHOW_RE.match(s)
    if m and (m.group('scheme') or m.group('port') is not None):
        out = (m.group('scheme') + '://') if m.group('scheme') else ''
        if m.group('userinfo') is not None:
            out += '‹가림›@'
        out += m.group('host')
        if m.group('port') is not None:
            out += ':‹포트›'
        s = out
    else:
        # 주소 모양이 아닌 값은 앞 60자만 보이되, 그 안의 「:숫자」 는 포트로 보고 가린다
        s = re.sub(r':\d{1,5}\b', ':‹포트›', s)
    return '「%s」' % (s[:60] + ('…' if len(s) > 60 else ''))


def rel(root, path):
    r = os.path.relpath(path, root)
    return path if r.startswith('..') else r


if __name__ == '__main__':
    try:
        sys.exit(main())
    except SystemExit:
        raise
    except Exception as e:
        print('ERROR R16 실행 오류: %s: %s' % (type(e).__name__, e))
        print('RESULT pass=0 fail=0 skip=1')
        sys.exit(2)
