#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
R31 기대값과 견주는 글자 세기 범위에 tests/static 이 들어감 (count scope includes bot rules and fixtures) —
정적 규칙, python3 stdlib 만, 파일은 읽기만.

answers: 대기열 11 검토 [높음](review-scripts/apply-q11-botfix:33 — 결함 판은 저장소 밖 → 견본 · 고친 판 train 사본
         q11-apply-q11-botfix-fixed.sh 는 패치가 건드린 파일 안에서만 세므로 조용해야)

무엇을 부류로 잡나:
  검사·반영 스크립트가 저장소 폴더 전체(뿌리 · tests/)의 글자 수를 세어(`grep -r`·`grep -rc`·`grep -rl … | wc -l`·
  `git grep -c`·`grep -c … $(find …)`·`find … -exec grep -c`·`find … | xargs grep -c`) **그 수를 기대값과 견주면**,
  그 범위에 점검 봇 규칙·견본(tests/static/**)이 들어가 같은 글자(표식·패턴 이름)를 함께 센다 — 규칙판이 바뀔
  때마다 수가 달라져 반영 확인이 거짓으로 실패하거나(되돌림) 모자란 것을 가린다.
    센다로 보는 것 — grep 의 -c/--count 이거나 파이프 뒤에 wc·awk·`grep -c` 가 있음.
    견준다로 보는 것 — 그 치환이 `[ … -eq N ]`·`[ "$(…)" = N ]`·`[[ … ]]`·test 의 한쪽이거나, 숫자 글자 인자와 함께
      함수(expect·chk 류)에 넘겨지거나, 그 수를 받은 변수(· 그 변수를 더해 가는 `n=$((n + c))` 한 단계)가 뒤에서
      그렇게 쓰임.
    범위가 tests/static 을 품는다 — 대상 경로가 저장소 뿌리(`$(cd "$(dirname "$0")/.." && pwd)` 처럼 스크립트 자리에
      묶였거나 `git -C "$V"`·"$V/tests/…" 로 쓰인 변수 · `.` · 경로 없는 grep -r · 경로 없는 git grep) 이거나
      `…/tests`·`tests`. tests/static 자체나 그 아래를 세는 것(의도)·src 같은 다른 폴더는 뺀다.
    조용 — `--exclude-dir=static`(·tests) · git grep 의 `:!tests/static` · find 의 `-not -path '*/static/*'`·`-prune` ·
      파이프 뒤 `grep -v …static…` · 패치가 건드린 파일 목록 안에서만 세기(재귀가 아님).
  표식: 세는 줄·견주는 줄(또는 바로 윗줄)의 `qcheck-allow: R31`.
  심각도: 중간(확인이 거짓으로 실패하거나 거짓으로 통과).

대상: --root 아래 --dirs(기본 tests·scripts·review-scripts)의 셸 + --outer 를 주면 <outer>/scripts/*.sh
      (출력 경로 `../scripts/<이름>`). 기본으로 tests/static/fixtures/* 는 뺀다. 셸 풀이기는 r20·r23·r25·r26·r28 과
      같은 사본(class ShPart … sh_emit — selftest 가 바이트 같음을 본다).
출력: FINDING R31 중간 <파일:줄> <설명> · 끝 줄 RESULT pass= fail= skip=
rc:   0 발견 없음 · 1 발견 있음 · 2 실행 오류
한계: 세는 글자가 정말 tests/static 에 있는지는 보지 않는다(부류로 잡는다). 배열로 넘긴 grep 선택지(`"${OPTS[@]}"`)는
      풀지 않아 건너뛴다. 기대값이 계산된 값이면(숫자 글자가 아니면) 견줌으로 보지 않는다.
"""
import argparse
import fnmatch
import os
import re
import subprocess
import sys

RULE = 'R31'


# ═══ 셸 풀이(공통 사본 — r20·r23·r25·r26·r28 이 같은 글을 갖는다. 규칙은 홀로 돈다 · import 없음) ══════════════
# r15 의 풀이기(따옴표·heredoc·줄 이어짐·명령 치환)를 바탕으로, 조각마다 **따옴표 문맥**(u 없음 · s 작은 · d 큰 ·
# h 풀리는 heredoc · e 역슬래시)과 줄 번호를 붙이고, 명령마다 앞뒤 연산자(&& · || · | · ;)·조건 자리·감싸는 블록을 적는다.
# bash 문법의 부분 집합이다 — eval·동적 명령 이름·case 무늬 안의 치환·배열 원소별 흐름은 보지 않는다.
SH_ASSIGN_RE = re.compile(r'^([A-Za-z_][A-Za-z0-9_]*)(\[[^\]]*\])?\+?=')
SH_REDIR_RE = re.compile(r'(\d+|\{[A-Za-z_][A-Za-z0-9_]*\})?(<<<|<<-|<<|<>|<&|<|>>|>&|>\||>)')


class ShPart(object):
    """단어 조각. k: lit · var · pos · special · indirect · len · sub · arith
    v: 글자(lit·arith) · 이름(var·len·indirect) · 번호 int 또는 '@'·'*'(pos) · 글자(special)
    q: u 따옴표 없음 · s 작은따옴표($'…' 포함) · d 큰따옴표 · h 풀리는 heredoc 본문 · e 역슬래시로 막은 글자
    op: ${…} 안 이름 뒤의 나머지(':-x' · ':2' · '[i]' …) · sub: ShSub"""
    __slots__ = ('k', 'v', 'q', 'op', 'line', 'sub', 'nest')

    def __init__(self, k, v, q, line, op='', sub=None):
        self.k, self.v, self.q, self.line, self.op, self.sub = k, v, q, line, op, sub
        self.nest = None     # ${X:-…} 의 나머지 안에서 나온 조각이면 바깥 조각

    def __repr__(self):
        return 'P(%s,%r,%s,%r)' % (self.k, self.v, self.q, self.op)


class ShWord(object):
    __slots__ = ('parts', 'line', 'raw')

    def __init__(self, parts, line, raw):
        self.parts, self.line, self.raw = parts, line, raw

    def lit(self):
        """확장이 없으면 글자, 있으면 None."""
        if all(p.k == 'lit' for p in self.parts):
            return ''.join(p.v for p in self.parts)
        return None

    def lit_text(self):
        """확장 조각은 빼고 글자만 이은 것."""
        return ''.join(p.v for p in self.parts if p.k == 'lit')

    def is_single(self):
        """펼치면 꼭 인자 하나가 되나(따옴표 없는 확장·글롭·"$@"·배열 전체가 없음)."""
        for p in self.parts:
            if p.k == 'lit':
                if p.q == 'u' and re.search(r'[*?\[]|\{[^}]*,[^}]*\}', p.v):
                    return False
                continue
            if p.q == 'u' and p.k in ('var', 'pos', 'special', 'indirect', 'sub', 'len', 'arith'):
                if p.k in ('special', 'len', 'arith'):
                    continue
                return False
            if p.k == 'pos' and p.v in ('@', '*'):
                return False
            if p.k == 'var' and re.match(r'\[[@*]\]', p.op or ''):
                return False
        return True


class ShSub(object):
    __slots__ = ('cmds', 'text', 'line')

    def __init__(self, cmds, text, line):
        self.cmds, self.text, self.line = cmds, text, line


class ShCmd(object):
    __slots__ = ('words', 'assigns', 'redirs', 'herestr', 'heredocs', 'line', 'func', 'kind', 'seq', 'sep',
                 'prev', 'neg', 'cond', 'blocks', 'insub', 'text', 'end_line', 'parent')

    def __init__(self, line, func, seq):
        self.words, self.assigns, self.redirs, self.herestr, self.heredocs = [], [], [], [], []
        self.line, self.func, self.kind, self.seq = line, func, 'simple', seq
        self.sep = None      # 이 명령을 끝낸 연산자: '\n' ';' '&&' '||' '|' '&' ';;' ')' 'EOF'
        self.prev = None     # (앞 명령, 그 연산자) — && · || · | 로 이어졌을 때만
        self.neg = False     # 앞에 ! 가 있음
        self.cond = None     # if · while · until 의 조건 자리면 그 블록
        self.blocks = ()     # 감싸는 블록들 (블록 dict, 그때의 갈래)
        self.insub = 0       # 명령 치환 깊이
        self.text = ''       # arith 명령의 글
        self.end_line = line
        self.parent = None   # 명령 치환 안이면 그 치환을 가진 명령

    def name(self):
        return self.words[0].lit() if self.words else None


def sh_adopt(cmd, parts):
    for p in parts:
        if p.k == 'sub' and p.sub is not None:
            for c in p.sub.cmds:
                if c.parent is None:
                    c.parent = cmd


class ShHeredoc(object):
    __slots__ = ('body', 'line', 'quoted', 'parts')

    def __init__(self, body, line, quoted, parts):
        self.body, self.line, self.quoted, self.parts = body, line, quoted, parts


class ShellParser(object):
    def __init__(self, text, line0=1, shared=None):
        self.t = text
        self.n = len(text)
        self.i = 0
        self.line = line0
        if shared is None:
            shared = {'cmds': [], 'funcs': {}, 'fstack': [], 'bstack': [], 'pending_func': [None], 'seq': [0],
                      'insub': [0]}
        self.sh = shared
        self.pending_heredocs = []

    def cur_func(self):
        fs = self.sh['fstack']
        return fs[-1] if fs else None

    def new_cmd(self):
        self.sh['seq'][0] += 1
        c = ShCmd(self.line, self.cur_func(), self.sh['seq'][0])
        c.blocks = tuple((b, b.get('part')) for b in self.sh['bstack'])
        c.insub = self.sh['insub'][0]
        return c

    def _skip_blank(self):
        while self.i < self.n:
            c = self.t[self.i]
            if c in ' \t\r':
                self.i += 1
            elif c == '\\' and self.t.startswith('\\\n', self.i):
                self.i += 2
                self.line += 1
            else:
                break

    def _match_close(self, i, op, cl):
        depth = 0
        j = i
        while j < self.n:
            c = self.t[j]
            if c == '\\':
                j += 2
                continue
            if c == "'" and op != '{':
                k = self.t.find("'", j + 1)
                j = self.n if k < 0 else k + 1
                continue
            if c == op:
                depth += 1
            elif c == cl:
                depth -= 1
                if depth == 0:
                    return j
            j += 1
        return self.n - 1

    def _read_heredoc_bodies(self):
        for (delim, strip, quoted, cmd) in self.pending_heredocs:
            start_line = self.line
            lines = []
            while self.i < self.n:
                k = self.t.find('\n', self.i)
                if k < 0:
                    k = self.n
                ln = self.t[self.i:k]
                self.i = min(k + 1, self.n)
                self.line += 1
                cmp_ln = ln.lstrip('\t') if strip else ln
                if cmp_ln.rstrip('\r') == delim:
                    break
                lines.append(ln)
            body = '\n'.join(lines)
            parts = []
            if not quoted and ('$' in body or '`' in body):
                sp = ShellParser(body, start_line, self.sh)
                self.sh['insub'][0] += 1
                try:
                    parts = sp.read_hd_body()
                finally:
                    self.sh['insub'][0] -= 1
            sh_adopt(cmd, parts)
            cmd.heredocs.append(ShHeredoc(body, start_line, quoted, parts))
        self.pending_heredocs = []

    # ── 단어 ──
    def read_word(self):
        parts, buf = [], []
        start, start_line = self.i, self.line
        bq = ['u', self.line]

        def flush():
            if buf:
                parts.append(ShPart('lit', ''.join(buf), bq[0], bq[1]))
                del buf[:]

        def put(ch, q):
            if buf and bq[0] != q:
                flush()
            if not buf:
                bq[0], bq[1] = q, self.line
            buf.append(ch)

        while self.i < self.n:
            c = self.t[self.i]
            if c in ' \t\n;&|()<>\r':
                if c == '(' and not parts and re.match(r'^[A-Za-z_][A-Za-z0-9_]*(\[[^\]]*\])?\+?=$', ''.join(buf)):
                    j = self._match_close(self.i, '(', ')')
                    inner = self.t[self.i + 1:j]
                    flush()
                    sub = ShellParser(inner, self.line, self.sh)
                    while sub.i < sub.n:
                        sub._skip_blank()
                        if sub.i < sub.n and sub.t[sub.i] == '\n':
                            sub.i += 1
                            sub.line += 1
                            continue
                        if sub.i < sub.n and sub.t[sub.i] == '#':
                            k = sub.t.find('\n', sub.i)
                            sub.i = sub.n if k < 0 else k
                            continue
                        if sub.i >= sub.n:
                            break
                        before = sub.i
                        w = sub.read_word()
                        parts.append(ShPart('lit', ' ', 'u', w.line))
                        parts.extend(w.parts)
                        if sub.i == before:
                            sub.i += 1
                    self.line += inner.count('\n')
                    self.i = j + 1
                    continue
                if c == '(' and buf and not parts and ''.join(buf)[-1:] in '@?*+!' and \
                        re.match(r'[@?*+!]$', ''.join(buf)[-1:]):
                    # extglob @( … ) — 글자로
                    j = self._match_close(self.i, '(', ')')
                    for ch in self.t[self.i:j + 1]:
                        put(ch, 'u')
                    self.line += self.t[self.i:j + 1].count('\n')
                    self.i = j + 1
                    continue
                break
            if c == '\\':
                if self.t.startswith('\\\n', self.i):
                    self.i += 2
                    self.line += 1
                    continue
                put(self.t[self.i + 1:self.i + 2], 'e')
                self.i += 2
                continue
            if c == "'":
                k = self.t.find("'", self.i + 1)
                if k < 0:
                    k = self.n
                s = self.t[self.i + 1:k]
                flush()
                parts.append(ShPart('lit', s, 's', self.line))
                self.line += s.count('\n')
                self.i = k + 1
                continue
            if c == '"':
                self.i += 1
                flush()
                parts.extend(self.read_dquote())
                continue
            if c == '$':
                flush()
                parts.extend(self.read_dollar('u'))
                continue
            if c == '`':
                flush()
                parts.append(self.read_backtick('u'))
                continue
            put(c, 'u')
            self.i += 1
        flush()
        return ShWord(parts, start_line, self.t[start:self.i])

    def read_dquote(self, q='d', stop='"'):
        parts, buf = [], []
        bl = [self.line]

        def flush():
            if buf:
                parts.append(ShPart('lit', ''.join(buf), q, bl[0]))
                del buf[:]

        while self.i < self.n:
            c = self.t[self.i]
            if stop and c == stop:
                self.i += 1
                break
            if c == '\\' and self.i + 1 < self.n and self.t[self.i + 1] in '$`"\\\n':
                if self.t[self.i + 1] == '\n':
                    self.line += 1
                elif self.t[self.i + 1] == '"' and not stop:
                    if not buf:
                        bl[0] = self.line
                    buf.append('\\"')
                else:
                    flush()
                    parts.append(ShPart('lit', self.t[self.i + 1], 'e', self.line))
                self.i += 2
                continue
            if c == '$':
                flush()
                parts.extend(self.read_dollar(q))
                continue
            if c == '`':
                flush()
                parts.append(self.read_backtick(q))
                continue
            if not buf:
                bl[0] = self.line
            if c == '\n':
                self.line += 1
            buf.append(c)
            self.i += 1
        flush()
        return parts

    def read_hd_body(self):
        return self.read_dquote(q='h', stop=None)

    def read_dollar(self, q):
        t, i = self.t, self.i
        ln = self.line
        nx = t[i + 1] if i + 1 < self.n else ''
        if nx == '(':
            if t.startswith('((', i + 1):
                j = self._match_close(i + 1, '(', ')')
                txt = t[i + 3:j - 1]
                self.line += t[i:j].count('\n')
                self.i = j + 1
                return [ShPart('arith', txt, q, ln)]
            self.i = i + 2
            start = self.i
            self.sh['insub'][0] += 1
            try:
                cmds = self.parse_list(term=')')
            finally:
                self.sh['insub'][0] -= 1
            return [ShPart('sub', t[start:self.i - 1], q, ln, sub=ShSub(cmds, t[start:self.i - 1], ln))]
        if nx == '[':
            j = t.find(']', i)
            self.i = self.n if j < 0 else j + 1
            return [ShPart('arith', t[i + 2:max(i + 2, self.i - 1)], q, ln)]
        if nx == '{':
            j = self._match_close(i + 1, '{', '}')
            inner = t[i + 2:j]
            self.line += inner.count('\n')
            self.i = j + 1
            m = re.match(r'(!|#)?([A-Za-z_][A-Za-z0-9_]*|[0-9]+|[@*#?$!-])', inner)
            if not m:
                return [ShPart('lit', '', q, ln)]
            flag, name = m.group(1), m.group(2)
            rest = inner[m.end():]
            out = []
            if flag == '#' and (not rest or rest.startswith('[')):
                out.append(ShPart('len', name, q, ln, op=rest))
            elif flag == '!':
                out.append(ShPart('indirect', name, q, ln, op=rest))
            elif name.isdigit():
                out.append(ShPart('pos', int(name), q, ln, op=rest))
            elif name in ('@', '*'):
                out.append(ShPart('pos', name, q, ln, op=rest))
            elif re.match(r'[A-Za-z_]', name):
                out.append(ShPart('var', name, q, ln, op=rest))
            else:
                out.append(ShPart('special', name, q, ln, op=rest))
            if '$' in rest or '`' in rest:
                sp = ShellParser(rest, ln, self.sh)
                inner_parts = sp.read_dquote(q=q, stop=None)
                for ip in inner_parts:
                    if ip.nest is None:
                        ip.nest = out[0]
                out.extend(inner_parts)
            return out
        if nx == "'":
            j = i + 2
            while j < self.n and t[j] != "'":
                j += 2 if t[j] == '\\' else 1
            self.i = j + 1
            s = t[i + 2:j]
            self.line += s.count('\n')
            return [ShPart('lit', s, 's', ln)]
        if nx == '"':
            self.i = i + 2
            return self.read_dquote()
        m = re.match(r'[A-Za-z_][A-Za-z0-9_]*', t[i + 1:i + 200])
        if m:
            self.i = i + 1 + m.end()
            return [ShPart('var', m.group(0), q, ln)]
        if nx.isdigit():
            self.i = i + 2
            return [ShPart('pos', int(nx), q, ln)]
        if nx in ('@', '*'):
            self.i = i + 2
            return [ShPart('pos', nx, q, ln)]
        if nx and nx in '#?$!-':
            self.i = i + 2
            return [ShPart('special', nx, q, ln)]
        self.i = i + 1
        return [ShPart('lit', '$', q, ln)]

    def read_backtick(self, q):
        j = self.i + 1
        while j < self.n and self.t[j] != '`':
            j += 2 if self.t[j] == '\\' else 1
        raw = self.t[self.i + 1:j]
        inner = re.sub(r'\\([`$\\])', r'\1', raw)
        ln = self.line
        sp = ShellParser(inner, self.line, self.sh)
        self.sh['insub'][0] += 1
        try:
            cmds = sp.parse_list()
        finally:
            self.sh['insub'][0] -= 1
        self.line += raw.count('\n')
        self.i = j + 1
        return ShPart('sub', inner, q, ln, sub=ShSub(cmds, inner, ln))

    # ── 명령 목록 ──
    def parse_list(self, term=None):
        out = []
        sh = self.sh
        cmd = self.new_cmd()
        paren = 0
        dbr = False
        expect_fname = False
        last = [None, None]      # 마지막으로 끝난 명령, 그 연산자
        neg_next = [False]
        closed = [None]          # 방금 닫힌 ( … ) 블록 — 뒤 연산자를 적는다

        def finish(sep):
            nonlocal cmd
            if cmd.words or cmd.assigns or cmd.redirs or cmd.herestr or cmd.heredocs or cmd.kind == 'arith':
                if last[0] is not None and last[1] in ('&&', '||', '|', '|&'):
                    cmd.prev = (last[0], last[1])
                lns = [w.line for w in cmd.assigns + cmd.words + cmd.herestr] + [rw[1].line for rw in cmd.redirs]
                if lns:
                    cmd.line = min(lns)
                for w in cmd.assigns + cmd.words + cmd.herestr + [rw[1] for rw in cmd.redirs]:
                    sh_adopt(cmd, w.parts)
                cmd.sep = sep
                cmd.end_line = self.line
                if neg_next[0]:
                    cmd.neg = True
                    neg_next[0] = False
                top = sh['bstack'][-1] if sh['bstack'] else None
                if top is not None and top.get('part') == 'cond':
                    cmd.cond = top
                    top.setdefault('conds', []).append(cmd)
                sh['cmds'].append(cmd)
                out.append(cmd)
                last[0], last[1] = cmd, sep
            elif sep in ('&&', '||', '|'):
                pass
            else:
                last[0], last[1] = None, sep
            cmd = self.new_cmd()

        def push_block(kind, **kw):
            b = {'kind': kind, 'part': kw.pop('part', None), 'line': self.line}
            if last[0] is not None and last[1] in ('&&', '||'):
                b['guard'] = (last[0], last[1])
            b.update(kw)
            sh['bstack'].append(b)
            return b

        def pop_block(kinds):
            st = sh['bstack']
            for k in range(len(st) - 1, -1, -1):
                if st[k]['kind'] in kinds:
                    b = st[k]
                    del st[k:]
                    return b
            return None

        while True:
            self._skip_blank()
            if self.i >= self.n:
                finish('EOF')
                break
            c = self.t[self.i]
            if c == '#' and (self.i == 0 or self.t[self.i - 1] in ' \t\n;&|()'):
                k = self.t.find('\n', self.i)
                self.i = self.n if k < 0 else k
                continue
            topb = sh['bstack'][-1] if sh['bstack'] else None
            if topb is not None and topb['kind'] == 'case' and topb.get('pat') and c != '\n' and \
                    not cmd.words and not cmd.assigns:
                if re.match(r'esac(?![A-Za-z0-9_])', self.t[self.i:self.i + 5]):
                    self.i += 4
                    pop_block(('case',))
                    cmd = self.new_cmd()
                    last[0], last[1] = None, None
                    continue
                # 무늬 — 짝이 맞는 ) 까지(따옴표 안의 ) 는 건너뜀)
                j = self.i
                depth = 0
                while j < self.n:
                    ch = self.t[j]
                    if ch == '\\':
                        j += 2
                        continue
                    if ch in '\'"':
                        k = self.t.find(ch, j + 1)
                        j = self.n if k < 0 else k + 1
                        continue
                    if ch == '(':
                        depth += 1
                    elif ch == ')':
                        if depth == 0:
                            break
                        depth -= 1
                    elif ch == '\n':
                        self.line += 1
                    j += 1
                self.i = min(j + 1, self.n)
                topb['pat'] = False
                cmd = self.new_cmd()
                continue
            if c == '\n':
                finish('\n')
                self.i += 1
                self.line += 1
                if self.pending_heredocs:
                    self._read_heredoc_bodies()
                continue
            if dbr and c in '&|<>()!':
                j = self.i
                while j < self.n and self.t[j] in '&|<>()!=':
                    j += 1
                cmd.words.append(ShWord([ShPart('lit', self.t[self.i:j], 'u', self.line)], self.line,
                                        self.t[self.i:j]))
                self.i = j
                continue
            if c == '&' and self.t.startswith('&>', self.i):
                op = '&>>' if self.t.startswith('&>>', self.i) else '&>'
                self.i += len(op)
                self._skip_blank()
                cmd.redirs.append((op, self.read_word()))
                continue
            if c in ';&|':
                j = self.i
                while j < self.n and self.t[j] in ';&|' and j - self.i < 2:
                    j += 1
                sep = self.t[self.i:j]
                if sep not in ('&&', '||', ';;', '|&', ';&'):
                    sep = sep[0]
                    j = self.i + 1
                self.i = j
                if closed[0] is not None and not (cmd.words or cmd.assigns or cmd.redirs):
                    closed[0]['sep'] = sep     # ( … ) & · ( … ) || …
                closed[0] = None
                finish(sep)
                if sep in (';;', ';&') and sh['bstack'] and sh['bstack'][-1]['kind'] == 'case':
                    sh['bstack'][-1]['pat'] = True
                continue
            if c == '(':
                if re.match(r'\(\s*\)', self.t[self.i:self.i + 20]) and not cmd.assigns and (
                        len(cmd.words) == 1 or (not cmd.words and sh['pending_func'][0])):
                    if cmd.words:
                        sh['pending_func'][0] = (cmd.words[0].raw, self.line)
                    cmd = self.new_cmd()
                    self.i = self.t.index(')', self.i) + 1
                    continue
                if not cmd.words and not cmd.assigns and self.t.startswith('((', self.i):
                    j = self._match_close(self.i, '(', ')')
                    cmd.kind = 'arith'
                    cmd.line = self.line
                    cmd.text = self.t[self.i + 2:j - 1]
                    self.line += self.t[self.i:j].count('\n')
                    self.i = j + 1
                    continue
                if cmd.words and cmd.kind == 'header':
                    j = self._match_close(self.i, '(', ')')
                    self.line += self.t[self.i:j].count('\n')
                    self.i = j + 1
                    continue
                finish(';')
                paren += 1
                pf = sh['pending_func'][0]
                if pf:
                    sh['pending_func'][0] = None
                    sh['fstack'].append(pf[0])
                    sh['funcs'].setdefault(pf[0], []).append({'line': pf[1], 'depth': len(sh['bstack'])})
                    push_block('func', name=pf[0], paren=True)
                else:
                    push_block('subshell')
                self.i += 1
                cmd = self.new_cmd()
                continue
            if c == ')':
                self.i += 1
                if paren > 0:
                    paren -= 1
                    finish(')')
                    b = pop_block(('subshell', 'func'))
                    if b is not None and b['kind'] == 'func':
                        if sh['fstack']:
                            sh['fstack'].pop()
                    closed[0] = b
                    cmd = self.new_cmd()
                    continue
                if term == ')':
                    finish(')')
                    return out
                cmd = self.new_cmd()     # case 갈래의 무늬 — 명령이 아니다
                continue
            if c in '<>' or (c.isdigit() or c == '{') and SH_REDIR_RE.match(self.t, self.i) and \
                    SH_REDIR_RE.match(self.t, self.i).group(1):
                if c in '<>' and self.t.startswith('(', self.i + 1):
                    self.i += 2
                    ln = self.line
                    start = self.i
                    sh['insub'][0] += 1
                    try:
                        cmds = self.parse_list(term=')')
                    finally:
                        sh['insub'][0] -= 1
                    txt = self.t[start:self.i - 1]
                    cmd.words.append(ShWord([ShPart('sub', txt, 'u', ln, sub=ShSub(cmds, txt, ln))], ln, '<(…)'))
                    continue
                m = SH_REDIR_RE.match(self.t, self.i)
                if m and (c in '<>' or m.group(1)):
                    op = m.group(2)
                    self.i = m.end()
                    self._skip_blank()
                    if op in ('<<', '<<-'):
                        w = self.read_word()
                        delim = w.lit() if w.lit() is not None else re.sub(r'["\'\\]', '', w.raw)
                        quoted = any(qc in w.raw for qc in ('"', "'", '\\'))
                        self.pending_heredocs.append((delim, op == '<<-', quoted, cmd))
                    elif op == '<<<':
                        cmd.herestr.append(self.read_word())
                    elif op in ('>&', '<&') and re.match(r'[0-9-]', self.t[self.i:self.i + 1]):
                        self.read_word()
                    else:
                        cmd.redirs.append((op, self.read_word()))
                    continue
            before = self.i
            w = self.read_word()
            if self.i == before:
                self.i += 1
                continue
            raw = w.raw
            if expect_fname:
                sh['pending_func'][0] = (re.sub(r'\(\)$', '', raw), w.line)
                expect_fname = False
                continue
            if not cmd.words and not cmd.assigns and cmd.kind != 'arith':
                if raw == '{':
                    pf = sh['pending_func'][0]
                    if pf:
                        sh['pending_func'][0] = None
                        sh['fstack'].append(pf[0])
                        sh['funcs'].setdefault(pf[0], []).append({'line': pf[1], 'depth': len(sh['bstack'])})
                        push_block('func', name=pf[0])
                    else:
                        push_block('brace')
                    cmd = self.new_cmd()
                    continue
                if raw == '}':
                    b = pop_block(('brace', 'func'))
                    if b is not None and b['kind'] == 'func' and sh['fstack']:
                        sh['fstack'].pop()
                    cmd = self.new_cmd()
                    last[0], last[1] = None, None
                    continue
                if raw == '!':
                    neg_next[0] = True
                    continue
                if raw in ('if', 'while', 'until'):
                    push_block('if' if raw == 'if' else 'loop', part='cond', word=raw)
                    cmd = self.new_cmd()
                    continue
                if raw in ('then', 'do', 'elif', 'else'):
                    st = sh['bstack']
                    if st:
                        if raw == 'then':
                            st[-1]['part'] = 'then'
                        elif raw == 'do':
                            st[-1]['part'] = 'body'
                        elif raw == 'elif':
                            st[-1]['part'] = 'cond'
                            st[-1]['elif'] = True
                        else:
                            st[-1]['part'] = 'else'
                            st[-1]['has_else'] = True
                    cmd = self.new_cmd()
                    continue
                if raw in ('fi', 'done', 'esac'):
                    pop_block(('if',) if raw == 'fi' else ('loop',) if raw == 'done' else ('case',))
                    cmd = self.new_cmd()
                    last[0], last[1] = None, None
                    continue
                if raw == 'function':
                    expect_fname = True
                    continue
                if raw in ('for', 'select'):
                    cmd.kind = 'header'
                    push_block('loop', part='header', word=raw)
                    cmd.blocks = cmd.blocks + ((sh['bstack'][-1], 'header'),)
                if raw == 'case':
                    cmd.kind = 'header'
                    push_block('case', part='body')
                if raw == '[[':
                    dbr = True
            elif dbr and raw == ']]':
                dbr = False
            if not cmd.words and cmd.kind == 'simple' and SH_ASSIGN_RE.match(raw):
                cmd.assigns.append(w)
                continue
            if cmd.kind == 'header' and raw == 'in' and cmd.words and cmd.words[0].lit() == 'case':
                cmd.words.append(w)
                finish(';')
                if sh['bstack'] and sh['bstack'][-1]['kind'] == 'case':
                    sh['bstack'][-1]['pat'] = True
                continue
            cmd.words.append(w)
        return out


class ShFile(object):
    """한 셸 파일(또는 heredoc 으로 만든 셸 조각)의 명령·함수."""

    def __init__(self, rel, text, line0=1):
        self.rel = rel
        self.text = text
        self.lines = text.split('\n')
        p = ShellParser(text, line0)
        p.parse_list()
        self.cmds = p.sh['cmds']
        self.cmds.sort(key=lambda c: c.seq)
        self.funcs = {}          # 이름 → [명령들]
        self.func_line = {}
        for name, defs in p.sh['funcs'].items():
            self.func_line[name] = defs[0]['line']
            self.funcs[name] = []
        for c in self.cmds:
            if c.func in self.funcs:
                self.funcs[c.func].append(c)

    def line_text(self, ln):
        if 1 <= ln <= len(self.lines):
            return self.lines[ln - 1]
        return ''

    def allowed(self, rule, line0, line1=None):
        """줄(또는 줄 범위)이나 그 바로 윗줄에 `qcheck-allow: <규칙>` 표식이 있나."""
        rx = re.compile(SH_FILE_ALLOW_TMPL % re.escape(rule))
        line1 = line0 if line1 is None else line1
        for ln in range(max(1, line0 - 1), line1 + 1):
            if rx.search(self.line_text(ln)):
                return True
        return False

    def defined_names(self):
        """이 파일에서 셸 변수로 값을 받는 이름(대입 · local/declare/export · read · for · mapfile · printf -v · getopts)."""
        if getattr(self, '_defined', None) is not None:
            return self._defined
        out = set()
        for c in self.cmds:
            for w in c.assigns:
                m = SH_ASSIGN_RE.match(w.raw)
                if m:
                    out.add(m.group(1))
            n0 = c.name()
            if n0 is None:
                continue
            rest = c.words[1:]
            if n0 in ('local', 'declare', 'typeset', 'export', 'readonly', 'unset'):
                for w in rest:
                    m = re.match(r'([A-Za-z_][A-Za-z0-9_]*)', w.raw)
                    if m and not w.raw.startswith('-'):
                        out.add(m.group(1))
            elif n0 == 'read':
                skip = False
                for w in rest:
                    lt = w.lit()
                    if skip:
                        skip = False
                        continue
                    if lt is None:
                        continue
                    if lt.startswith('-'):
                        if lt[-1:] in ('a', 'd', 'p', 'n', 'N', 't', 'u', 'i') and lt not in ('-r', '-s', '-e'):
                            skip = lt[-1:] not in ('r',)
                            if lt[-1:] == 'a' and len(rest) > 0:
                                skip = False
                        continue
                    if re.fullmatch(r'[A-Za-z_][A-Za-z0-9_]*', lt):
                        out.add(lt)
            elif n0 in ('for', 'select') and len(c.words) >= 2:
                lt = c.words[1].lit()
                if lt and re.fullmatch(r'[A-Za-z_][A-Za-z0-9_]*', lt):
                    out.add(lt)
            elif n0 in ('mapfile', 'readarray') and rest:
                lt = rest[-1].lit()
                if lt and re.fullmatch(r'[A-Za-z_][A-Za-z0-9_]*', lt):
                    out.add(lt)
            elif n0 == 'printf':
                for k, w in enumerate(rest[:-1]):
                    if w.lit() == '-v':
                        lt = rest[k + 1].lit()
                        if lt:
                            out.add(re.sub(r'\[.*$', '', lt))
            elif n0 == 'getopts' and len(rest) >= 2:
                lt = rest[1].lit()
                if lt:
                    out.add(lt)
            elif n0 == 'let':
                for w in rest:
                    m = re.match(r'([A-Za-z_][A-Za-z0-9_]*)', w.lit() or '')
                    if m:
                        out.add(m.group(1))
        for c in self.cmds:
            if c.kind == 'arith':
                for m in re.finditer(r'([A-Za-z_][A-Za-z0-9_]*)\s*(?:\+\+|--|[-+*/%]?=(?!=))', c.text):
                    out.add(m.group(1))
        self._defined = out
        return out

    def opts_at(self, cmd):
        """그 명령 자리의 (errexit, nounset, pipefail) — 글 차례로 본 set 명령들(함수 안 set 도 그 자리부터)."""
        if getattr(self, '_opts', None) is None:
            first = self.lines[0] if self.lines else ''
            e = u = pf = False
            m = re.match(r'#!\S+(?:\s+\S*sh)?((?:\s+-[A-Za-z]+)+)', first)
            if m:
                fl = m.group(1)
                e = 'e' in fl
                u = 'u' in fl
            marks = [(0, (e, u, pf))]
            for c in self.cmds:
                if c.name() != 'set' or c.insub:
                    continue
                ws = [w.lit() or '' for w in c.words[1:]]
                k = 0
                while k < len(ws):
                    a = ws[k]
                    if a in ('-o', '+o') and k + 1 < len(ws):
                        on = a == '-o'
                        nm = ws[k + 1]
                        if nm == 'errexit':
                            e = on
                        elif nm == 'nounset':
                            u = on
                        elif nm == 'pipefail':
                            pf = on
                        k += 2
                        continue
                    if re.fullmatch(r'[-+][A-Za-z]+', a):
                        on = a[0] == '-'
                        if 'e' in a:
                            e = on
                        if 'u' in a:
                            u = on
                        if a.endswith('o') and k + 1 < len(ws) and ws[k + 1] == 'pipefail':
                            pf = on
                            k += 2
                            continue
                    elif a == '--':
                        break
                    k += 1
                marks.append((c.seq, (e, u, pf)))
            self._opts = marks
        st = self._opts[0][1]
        for sq, v in self._opts:
            if sq < cmd.seq:
                st = v
            else:
                break
        return st


def sh_resolve_sources(sfs):
    """{경로: [그 파일이 . · source 로 읽는 파일 경로들(모인 대상 안에서 찾은 것, 이어진 것까지)]}."""
    rels = list(sfs)
    direct = {}
    for rel, sf in sfs.items():
        got = []
        for c in sf.cmds:
            if c.name() not in ('.', 'source') or len(c.words) < 2:
                continue
            w = c.words[1]
            tail = ''
            for p in w.parts:
                if p.k == 'lit':
                    tail += p.v
                else:
                    tail = ''
            tail = tail.strip()
            if not tail or '/' not in tail and not tail.endswith('.sh'):
                continue
            tail = '/' + tail.lstrip('./') if not tail.startswith('/') else tail
            cand = [r for r in rels if ('/' + r).endswith(tail) and r != rel]
            if not cand:
                continue
            base = os.path.dirname(rel)
            cand.sort(key=lambda r: -len(os.path.commonprefix([base, os.path.dirname(r)])))
            got.append(cand[0])
        direct[rel] = got
    out = {}
    for rel in rels:
        seen, stack = [], list(direct.get(rel, []))
        while stack:
            r = stack.pop(0)
            if r in seen or r == rel:
                continue
            seen.append(r)
            stack.extend(direct.get(r, []))
        out[rel] = seen
    return out


def sh_assign_parts(w):
    """대입 단어 NAME=값 에서 (이름, 값 조각들)."""
    m = SH_ASSIGN_RE.match(w.raw)
    if not m:
        return None, []
    name = m.group(1)
    parts = list(w.parts)
    out = []
    cut = False
    for p in parts:
        if not cut and p.k == 'lit':
            k = p.v.find('=')
            if k >= 0:
                cut = True
                if p.v[k + 1:]:
                    out.append(ShPart('lit', p.v[k + 1:], p.q, p.line))
                continue
            continue
        out.append(p)
    return name, out


def sh_effective(words):
    """sudo·env·timeout 같은 감싸는 명령을 벗긴 실제 명령 단어들."""
    wrap = {'sudo', 'doas', 'env', 'nohup', 'nice', 'ionice', 'timeout', 'stdbuf', 'setsid', 'exec', 'command',
            'time', 'flock', 'runuser', 'builtin'}
    k = 0
    while k < len(words):
        lit = words[k].lit()
        if lit is None or os.path.basename(lit) not in wrap:
            break
        base = os.path.basename(lit)
        if base == 'command' and k + 1 < len(words) and words[k + 1].lit() in ('-v', '-V'):
            break
        k += 1
        while k < len(words):
            l2 = words[k].lit()
            if l2 is not None and (l2.startswith('-') or SH_ASSIGN_RE.match(words[k].raw) or
                                   re.fullmatch(r'[0-9.]+[smhd]?', l2)):
                if l2 in ('-u', '-g', '-C', '-p', '-h', '-U', '-D', '-R', '-T', '-E', '-n') and \
                        base in ('sudo', 'doas', 'runuser') and l2 not in ('-E', '-n'):
                    k += 1
                k += 1
                continue
            break
    return words[k:]


SH_FILE_ALLOW_TMPL = r'qcheck-allow\s*[:=]?\s*(?:[A-Za-z0-9_-]+\s*,\s*)*%s\b'


def sh_file_lang(rel, head):
    ext = os.path.splitext(rel)[1].lower()
    if ext in ('.sh', '.bash'):
        return True
    if ext == '' and head.startswith('#!'):
        first = head.split('\n', 1)[0]
        if re.search(r'\b(ba|da|k)?sh\b', first):
            return True
    return False


def sh_list_files(root, use_git):
    if use_git:
        r = subprocess.run(['git', '--no-optional-locks', '-C', root, '-c', 'core.quotepath=off', 'ls-files', '-z', '--cached', '--others',
                            '--exclude-standard'], stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=300)
        if r.returncode != 0:
            raise RuntimeError('git ls-files 실패: ' + r.stderr.decode('utf-8', 'replace').strip())
        return sorted(set(os.fsdecode(p) for p in r.stdout.split(b'\0') if p))
    out = []
    for d, dirs, files in os.walk(root):
        dirs[:] = sorted(x for x in dirs if x != '.git')
        for f in sorted(files):
            out.append(os.path.relpath(os.path.join(d, f), root).replace(os.sep, '/'))
    return out


def sh_repo_top(root):
    try:
        r = subprocess.run(['git', '--no-optional-locks', '-C', root, 'rev-parse', '--show-toplevel'], stdout=subprocess.PIPE,
                           stderr=subprocess.DEVNULL, timeout=60)
    except (OSError, subprocess.SubprocessError):
        return None
    if r.returncode != 0:
        return None
    return os.path.realpath(r.stdout.decode('utf-8', 'replace').strip())


def sh_collect(rule, root, outer, dirs, excludes, walk=False):
    """대상 셸 파일 목록 — [(표시 경로, 절대 경로, 바깥 여부)], 못 읽은 수. 오류면 RuntimeError."""
    use_git = (not walk) and sh_repo_top(root) == root
    rels = sh_list_files(root, use_git)
    entries = []
    for rel in rels:
        if not any(rel == d or rel.startswith(d + '/') for d in dirs):
            continue
        if any(fnmatch.fnmatch(rel, g) for g in excludes):
            continue
        entries.append((rel, os.path.join(root, rel), False))
    if outer is not None:
        od = os.path.join(outer, 'scripts')
        if os.path.isdir(od):
            for f in sorted(os.listdir(od)):
                full = os.path.join(od, f)
                if f.endswith('.sh') and os.path.isfile(full) and not os.path.islink(full):
                    entries.append(('../scripts/' + f, full, True))
    files, skipped = [], 0
    for rel, full, is_outer in entries:
        if os.path.islink(full) or not os.path.isfile(full):
            continue
        try:
            with open(full, 'rb') as fh:
                data = fh.read()
        except OSError as e:
            sys.stderr.write('%s: 읽지 못함: %s (%s)\n' % (rule, rel, e.strerror))
            skipped += 1
            continue
        text = data.decode('utf-8', 'replace').lstrip(chr(0xFEFF))
        if not sh_file_lang(rel, text[:200]):
            continue
        files.append((rel, text, is_outer))
    return files, skipped


def sh_parse_all(rule, files):
    """[(표시 경로, 글, 바깥)] → ({경로: ShFile}, 구문 실패 수)."""
    out, bad = {}, 0
    for rel, text, is_outer in files:
        try:
            out[rel] = ShFile(rel, text)
        except (ValueError, RecursionError, IndexError) as e:
            sys.stderr.write('%s: 구문 분석 실패로 건너뜀: %s (%s)\n' % (rule, rel, e.__class__.__name__))
            bad += 1
    return out, bad


def sh_common_args(ap, here):
    ap.add_argument('--root', default=os.path.normpath(os.path.join(here, '..', '..')),
                    help='검사할 트리(기본: 이 파일 기준 저장소 뿌리)')
    ap.add_argument('--outer', default=None,
                    help='바깥 저장소(qaredi) 배치 — 그 아래 scripts/*.sh(반영 스크립트)도 본다. 없으면 바깥은 보지 않는다')
    ap.add_argument('--dirs', default='tests,scripts,review-scripts',
                    help='뿌리 아래 볼 디렉터리(쉼표, 기본 tests,scripts,review-scripts)')
    ap.add_argument('--exclude', action='append', default=None,
                    help='뺄 경로 glob(여러 번). 주면 기본값(tests/static/fixtures/*)을 대신한다')
    ap.add_argument('--walk', action='store_true', help='git 목록 대신 디렉터리 전체를 본다')
    ap.add_argument('--range', default=None, help='(받기만 한다 — 이 규칙은 트리 전체를 본다)')
    ap.add_argument('--directive', default=None, help='(받기만 한다)')


def sh_setup(rule, args):
    """(뿌리, 바깥, 파일 목록, 못 읽은 수) 또는 rc 2 를 뜻하는 None."""
    root = os.path.realpath(args.root)
    if not os.path.isdir(root):
        sys.stderr.write('%s: 뿌리가 디렉터리가 아님: %s\n' % (rule, args.root))
        return None
    outer = None
    if args.outer and args.outer.lower() != 'none':
        outer = os.path.realpath(args.outer)
        if not os.path.isdir(outer):
            sys.stderr.write('%s: 바깥 배치가 디렉터리가 아님: %s\n' % (rule, args.outer))
            return None
    excludes = ['tests/static/fixtures/*'] if args.exclude is None else args.exclude
    dirs = [d.strip('/ ') for d in args.dirs.split(',') if d.strip('/ ')]
    try:
        files, skipped = sh_collect(rule, root, outer, dirs, excludes, args.walk)
    except (RuntimeError, OSError, subprocess.SubprocessError) as e:
        sys.stderr.write('%s: 대상 목록을 만들지 못함: %s\n' % (rule, e))
        return None
    return root, outer, files, skipped


def sh_emit(rule, findings, nfiles, skipped):
    uniq = {}
    for f in findings:
        k = (f[1], f[2], f[3])
        if k not in uniq:
            uniq[k] = f
    fs = sorted(uniq.values(), key=lambda f: (f[1], f[2], f[3]))
    for sev, path, line, msg in fs:
        print('FINDING %s %s %s:%d %s' % (rule, sev, path, line, msg))
    bad = len(set(f[1] for f in fs))
    print('RESULT pass=%d fail=%d skip=%d' % (max(0, nfiles - bad), len(fs), skipped))
    return 1 if fs else 0
# ═══ 셸 풀이 끝 ═══════════════════════════════════════════════════════════════════════════════

# ─── R31 ─────────────────────────────────────────────────────────────────────────────────────
CMP_OPS = {'-eq', '-ne', '-lt', '-le', '-gt', '-ge', '=', '==', '!='}
NONCMP = {'echo', 'printf', 'local', 'export', 'declare', 'readonly', 'typeset', 'cat', 'tee', 'grep', 'egrep', 'sed',
          'awk', 'tr', 'wc', 'sort', 'uniq', 'head', 'tail', 'cut', 'paste', 'xargs', 'eval', 'true', ':'}
GREP_SHORT_ARG = set('efmABCdD')
GREP_LONG_ARG = {'--regexp', '--file', '--max-count', '--context', '--after-context', '--before-context',
                 '--devices', '--directories', '--label', '--color', '--colour', '--binary-files'}


def cname(c):
    ws = sh_effective(c.words)
    return os.path.basename(ws[0].lit() or '') if ws else ''


def word_text(w):
    return re.sub(r'["\']', '', w.raw)


def succ_map(sf):
    out = {}
    for c in sf.cmds:
        if c.prev is not None and c.prev[1] in ('|', '|&'):
            out[id(c.prev[0])] = c
    return out


def pipe_after(c, succ):
    out = []
    k = succ.get(id(c))
    while k is not None and len(out) < 50:
        out.append(k)
        k = succ.get(id(k))
    return out


def assign_words(c):
    if not c.words:
        return list(c.assigns)
    if c.words[0].lit() in ('local', 'declare', 'typeset', 'export', 'readonly'):
        return [w for w in c.words[1:] if SH_ASSIGN_RE.match(w.raw)]
    return []


def _norm_rel(path):
    """'a/b/../c' → 'a/c' · 뿌리 위로 나가면 None · '' 은 뿌리."""
    out = []
    for comp in path.split('/'):
        if comp in ('', '.'):
            continue
        if comp == '..':
            if not out:
                return None
            out.pop()
            continue
        out.append(comp)
    return '/'.join(out)


class PathEval(object):
    """셸 경로 글을 저장소 뿌리 기준 상대 경로로 푼다 — 스크립트 자리($0 · BASH_SOURCE)나
    `git rev-parse --show-toplevel` 에 묶인 것만(대입 · dirname · cd … && pwd · realpath/readlink -f 를 따라감).
    못 풀면 None. '' 은 뿌리."""

    def __init__(self, sf, script_rel):
        self.script = script_rel
        self.assign = {}
        self.memo = {}
        for c in sf.cmds:
            for w in assign_words(c):
                name, parts = sh_assign_parts(w)
                if name:
                    self.assign.setdefault(name, []).append(parts)

    def parts(self, parts, depth=0):
        if depth > 8:
            return None
        ps = [p for p in parts if not (p.k == 'lit' and p.v == '')]
        if not ps:
            return None
        p0, rest = ps[0], ps[1:]
        if any(p.k != 'lit' for p in rest):
            return None
        tail = ''.join(p.v for p in rest)
        if p0.k == 'pos' and p0.v == 0 and not p0.op:
            base = self.script
        elif p0.k == 'var' and p0.v == 'BASH_SOURCE' and (p0.op or '') in ('', '[0]'):
            base = self.script
        elif p0.k == 'var' and not p0.op:
            if p0.v in self.memo:
                base = self.memo[p0.v]
            else:
                self.memo[p0.v] = None          # 제 자신을 부르는 대입(X="$X/…")은 못 푼 것으로
                got = set(self.parts(v, depth + 1) for v in (self.assign.get(p0.v) or []))
                base = got.pop() if len(got) == 1 else None
                self.memo[p0.v] = base
        elif p0.k == 'sub' and p0.sub is not None:
            base = self.sub(p0.sub, depth + 1)
        else:
            return None
        if base is None:
            return None
        return _norm_rel(base + '/' + tail) if tail else base

    def sub(self, sub, depth):
        if re.search(r'rev-parse\b.*--show-toplevel', sub.text or ''):
            return ''
        cmds = sub.cmds
        if not cmds:
            return None
        c0 = cmds[0]
        n0 = os.path.basename(c0.words[0].lit() or '') if c0.words else ''
        args = [w for w in c0.words[1:] if not (w.lit() or '').startswith('-')]
        if n0 == 'cd' and args and any(c.name() == 'pwd' for c in cmds[1:]):
            return self.parts(args[0].parts, depth)
        if n0 == 'dirname' and args:
            x = self.parts(args[-1].parts, depth)
            if x is None or x == '':
                return None
            return x.rsplit('/', 1)[0] if '/' in x else ''
        if n0 in ('realpath', 'readlink') and args and (n0 == 'realpath' or any(
                re.fullmatch(r'-[a-zA-Z]*[fem][a-zA-Z]*', w.lit() or '') for w in c0.words[1:])):
            return self.parts(args[-1].parts, depth)
        return None


def root_vars(sf, pe):
    """저장소 뿌리를 뜻하는 변수 — 스크립트 자리에 묶여 뿌리로 풀리거나, `git -C "$V"` · "$V/tests/…"·"$V/src/…"·
    "$V/.git" 로 쓰인 것."""
    out = set()
    for name, vals in pe.assign.items():
        got = set(pe.parts(v) for v in vals)
        if got == {''}:
            out.add(name)
    for c in sf.cmds:
        ws = c.words
        for k, w in enumerate(ws):
            t = re.sub(r'\$\{([A-Za-z_][A-Za-z0-9_]*)\}', r'$\1', word_text(w))
            m = re.match(r'\$([A-Za-z_][A-Za-z0-9_]*)/(?:tests|src|\.git)(?:/|$)', t)
            if m:
                out.add(m.group(1))
            if w.lit() == '-C' and k > 0 and os.path.basename(ws[0].lit() or '') == 'git' and k + 1 < len(ws):
                m = re.fullmatch(r'\$([A-Za-z_][A-Za-z0-9_]*)', re.sub(r'\$\{([A-Za-z_][A-Za-z0-9_]*)\}', r'$\1',
                                                                     word_text(ws[k + 1])))
                if m:
                    out.add(m.group(1))
    return out


CD_NAMES = ('cd', 'pushd', 'popd')


def resolve_dir(w, pe, roots, cwd):
    """cd·pushd·git -C 인자 → 저장소 뿌리 기준 상대 경로('' 은 뿌리) · 못 풀면 None. 상대 글자는 cwd 에 잇는다."""
    rel = pe.parts(w.parts)
    if rel is not None:
        return rel
    tt = re.sub(r'\$\{([A-Za-z_][A-Za-z0-9_]*)\}', r'$\1', word_text(w))
    m = re.fullmatch(r'\$([A-Za-z_][A-Za-z0-9_]*)(/.*)?', tt)
    if m:
        return _norm_rel(m.group(2) or '') if m.group(1) in roots else None
    lt = w.lit()
    if lt is None or lt == '' or lt.startswith(('/', '~', '-', '+')) or cwd is None:
        return None
    return _norm_rel(cwd + '/' + lt)


def cd_target(sf, d, pe, roots, cds, depth):
    """cd·pushd 명령 d 뒤의 작업 폴더 · popd·인자 없음·`cd -`·못 푼 인자는 None."""
    ws = sh_effective(d.words)
    if not ws or cname(d) == 'popd':
        return None
    args = []
    for w in ws[1:]:
        lt = w.lit()
        if not args and lt is not None and (lt == '--' or re.fullmatch(r'-[LPen@]+', lt)):
            continue
        args.append(w)
    if not args:
        return None
    base = cwd_at(sf, d, pe, roots, cds, depth + 1) if args[0].lit() is not None else ''
    return resolve_dir(args[0], pe, roots, base)


def cwd_at(sf, c, pe, roots, cds, depth=0):
    """명령 c 가 도는 작업 폴더(저장소 뿌리 기준 상대 · '' 은 뿌리) — 직전 cd·pushd 를 PathEval 로 푼다(v4 검토 중간 2).
    cd 가 없으면 뿌리로 본다(스크립트를 뿌리에서 부른다는 기존 가정). 못 풀면 None — 갈래·반복 안 cd · && ‖ 로 걸린 cd ·
    popd · 다른 함수 안 cd · 인자 폴더. 서브셸 ( … ) 안 cd 는 그 서브셸 밖에 닿지 않는다."""
    if depth > 8:
        return None
    x = c
    while True:
        xb = set((id(b), part) for b, part in x.blocks)
        xids = set(id(b) for b, _p in x.blocks)
        same = []
        for d in cds:
            if d.seq >= x.seq or d.insub != x.insub or d.parent is not x.parent:
                continue
            if any(b['kind'] == 'subshell' and id(b) not in xids for b, _p in d.blocks):
                continue            # 닫힌 서브셸 안 cd — 밖에 닿지 않음
            same.append(d)
        mine = [d for d in same if d.func == x.func]
        if mine:
            d = mine[-1]
            if d.prev is not None and d.prev[1] in ('&&', '||'):
                return None
            if any(b['kind'] != 'func' and (id(b), part) not in xb for b, part in d.blocks):
                return None
            return cd_target(sf, d, pe, roots, cds, depth)
        if x.insub and x.parent is not None:
            x = x.parent
            continue
        if same:
            return None             # 다른 함수(또는 함수 밖) 의 cd 가 먼저 돌았을 수 있음
        if x.func is not None and any(d.insub == 0 and d.func != x.func for d in cds):
            return None
        return ''


def scope_hit(w, pe, roots, cwd=''):
    """단어가 가리키는 범위가 tests/static 을 품으면 보고용 글, 아니면 None. cwd: 작업 폴더(None 이면 상대 경로는 모름)."""
    t = word_text(w)
    rel = pe.parts(w.parts)
    if rel is not None:
        return t if rel in ('', 'tests', 'tests/*', '*') else None
    tt = re.sub(r'\$\{([A-Za-z_][A-Za-z0-9_]*)\}', r'$\1', t)
    if len(tt) > 1:
        tt = tt.rstrip('/')
    m = re.fullmatch(r'\$([A-Za-z_][A-Za-z0-9_]*)(/.*)?', tt)
    if m:
        tail = (m.group(2) or '').strip('/')
        tail = tail[2:] if tail.startswith('./') else tail
        if tail in ('tests', 'tests/*'):
            return t
        if tail in ('', '*') and m.group(1) in roots:
            return t
        return None
    if '$' in tt or '`' in tt:
        return None
    n = tt[2:] if tt.startswith('./') else tt
    if not n.startswith('/') and cwd != '':
        if cwd is None:
            return None
        b = '' if n in ('*', '.') else (n[:-2] if n.endswith('/*') else n)
        rel = _norm_rel(cwd + '/' + b) if b else cwd
        return (t or '.') if rel in ('', 'tests') else None
    if n in ('', '.', 'tests', 'tests/*', '*'):
        return t or '.'
    if n.endswith('/tests'):
        return t
    return None


def parse_grep(ws, k0=1):
    """grep 단어 → dict(rec, count, pattern, paths, excl, unknown)."""
    short, longs, paths = set(), [], []
    pat_given, pat, dd, unknown = False, None, False, False
    k = k0
    while k < len(ws):
        w = ws[k]
        t = word_text(w)
        if not dd and t == '--':
            dd = True
            k += 1
            continue
        if not dd and re.search(r'\[[@*]\]', w.raw):
            unknown = True
            k += 1
            continue
        if not dd and t.startswith('--'):
            longs.append(t)
            if t in GREP_LONG_ARG:
                if t in ('--regexp', '--file'):
                    pat_given = True
                k += 2
                continue
            if t.startswith('--regexp=') or t.startswith('--file='):
                pat_given = True
            k += 1
            continue
        if not dd and t.startswith('-') and len(t) > 1:
            fl = t[1:]
            j, skip = 0, 0
            while j < len(fl):
                ch = fl[j]
                short.add(ch)
                if ch in GREP_SHORT_ARG:
                    if ch in 'ef':
                        pat_given = True
                    if j == len(fl) - 1:
                        skip = 1
                    break
                j += 1
            k += 1 + skip
            continue
        if not pat_given and pat is None:
            pat = w
            k += 1
            continue
        paths.append(w)
        k += 1
    return {'rec': bool(short & set('rR')) or any(x in ('--recursive', '--dereference-recursive') for x in longs),
            'count': 'c' in short or '--count' in longs,
            'excl': [x.split('=', 1)[1] for x in longs if x.startswith('--exclude-dir=')],
            'paths': paths, 'pat': pat, 'unknown': unknown}


def excl_static(vals):
    return any('static' in v or v.strip('/{}') == 'tests' for v in vals)


def later_counts(after):
    for k in after:
        n = cname(k)
        if n in ('wc', 'awk', 'gawk', 'mawk'):
            return True
        if n in ('grep', 'egrep') and parse_grep(sh_effective(k.words))['count']:
            return True
    return False


def later_excludes(after):
    for k in after:
        if cname(k) in ('grep', 'egrep', 'fgrep'):
            ws = sh_effective(k.words)
            inv = any(w.lit() == '--invert-match' or re.fullmatch(r'-[A-Za-z]*v[A-Za-z]*', w.lit() or '')
                      for w in ws[1:])
            if inv and any('static' in word_text(w) for w in ws[1:]):
                return True
    return False


def find_scope(ws):
    """find 단어 → (폴더 단어들, static 을 빼나)."""
    k = 1
    while k < len(ws) and (ws[k].lit() or '') in ('-L', '-H', '-P'):
        k += 1
    dirs = []
    while k < len(ws) and not (word_text(ws[k]).startswith('-') or word_text(ws[k]) in ('(', '!', '\\(')):
        dirs.append(ws[k])
        k += 1
    ex = any('static' in word_text(w) for w in ws[k:])
    return dirs, ex


def count_sources(sf, succ, pe, roots):
    """[(세는 명령, 범위 글)] — tests/static 을 품는 재귀 세기만."""
    out = []
    cds = [d for d in sf.cmds if cname(d) in CD_NAMES]
    for c in sf.cmds:
        ws = sh_effective(c.words)
        if not ws:
            continue
        n = os.path.basename(ws[0].lit() or '')
        if n not in ('grep', 'egrep', 'fgrep', 'rg', 'git', 'find'):
            continue
        cwd = cwd_at(sf, c, pe, roots, cds) if cds else ''
        after = pipe_after(c, succ)
        hits = []
        if n in ('grep', 'egrep', 'fgrep', 'rg'):
            g = parse_grep(ws)
            if g['unknown']:
                continue
            if not (g['count'] or later_counts(after)):
                continue
            if excl_static(g['excl']) or later_excludes(after):
                continue
            if g['rec'] or n == 'rg':
                tg = g['paths'] or [None]
                for w in tg:
                    if w is None:
                        h = '.' if cwd in ('', 'tests') else None
                    else:
                        h = scope_hit(w, pe, roots, cwd)
                    if h:
                        hits.append(h)
            else:
                for w in g['paths']:
                    for p in w.parts:
                        if p.k != 'sub' or p.sub is None:
                            continue
                        for fc in p.sub.cmds:
                            fws = sh_effective(fc.words)
                            if fws and os.path.basename(fws[0].lit() or '') == 'find':
                                dirs, ex = find_scope(fws)
                                if ex:
                                    continue
                                for d in dirs or []:
                                    h = scope_hit(d, pe, roots, cwd)
                                    if h:
                                        hits.append(h)
        elif n == 'git':
            j = 1
            gcwd = cwd
            while j < len(ws):
                lt = ws[j].lit()
                if lt in ('-C', '-c', '--git-dir', '--work-tree'):
                    if lt == '-C' and j + 1 < len(ws):
                        gcwd = resolve_dir(ws[j + 1], pe, roots, gcwd)
                    elif lt != '-c':
                        gcwd = None
                    j += 2
                    continue
                if lt is not None and lt.startswith('-'):
                    j += 1
                    continue
                break
            if j >= len(ws) or ws[j].lit() != 'grep':
                continue
            g = parse_grep(ws, j + 1)
            if g['unknown'] or not (g['count'] or later_counts(after)) or later_excludes(after):
                continue
            specs = [word_text(w) for w in g['paths']]
            if any(s.startswith((':!', ':^', ':(exclude)')) and ('static' in s or s.rstrip('/').endswith('tests'))
                   for s in specs):
                continue
            pos = [w for w, s in zip(g['paths'], specs) if not s.startswith(':')]
            if not pos and gcwd in ('', 'tests'):
                hits.append('git grep(트리 전체)')      # 경로 없는 git grep 은 작업 폴더 아래만 — 뿌리·tests 일 때
            for w in pos:
                h = scope_hit(w, pe, roots, gcwd)
                if h:
                    hits.append(h)
        elif n == 'find':
            dirs, ex = find_scope(ws)
            if ex:
                continue
            texts = [word_text(w) for w in ws]
            gi = None
            for j, t in enumerate(texts):
                if t in ('-exec', '-execdir') and j + 1 < len(texts) and os.path.basename(texts[j + 1]) in \
                        ('grep', 'egrep'):
                    gi = j + 1
                    break
            gcount = False
            if gi is not None:
                gcount = parse_grep(ws, gi + 1)['count']
            else:
                for k in after:
                    kws = sh_effective(k.words)
                    if kws and os.path.basename(kws[0].lit() or '') == 'xargs':
                        for j, w in enumerate(kws[1:], 1):
                            if os.path.basename(w.lit() or '') in ('grep', 'egrep'):
                                gi = j
                                gcount = parse_grep(kws, j + 1)['count']
                                break
                        break
            if gi is None:
                continue
            if not (gcount or later_counts(after)) or later_excludes(after):
                continue
            for d in dirs or []:
                h = scope_hit(d, pe, roots, cwd)
                if h:
                    hits.append(h)
        if hits:
            out.append((c, hits[0]))
    return out


def assigned_var(owner):
    if owner is None:
        return None
    for w in assign_words(owner):
        m = SH_ASSIGN_RE.match(w.raw)
        if m:
            return m.group(1)
    return None


def int_lit(w):
    lt = w.lit()
    return lt if lt is not None and re.fullmatch(r'-?\d+', lt.strip()) else None


def compare_of(c, uses):
    """명령 c 가 uses(단어 → 쓰는가) 인 값을 기대값과 견주면 기대값 글('?' = 모름), 아니면 None."""
    n = c.name()
    if n in ('[', '[[', 'test'):
        ts = [w.lit() for w in c.words]
        for j, t in enumerate(ts):
            if t in CMP_OPS and 0 < j < len(c.words) - 1:
                a, b = c.words[j - 1], c.words[j + 1]
                if uses(a) or uses(b):
                    other = b if uses(a) else a
                    return int_lit(other) or '?'
        return None
    if not c.words or n is None or os.path.basename(n) in NONCMP:
        return None
    if not any(uses(w) for w in c.words[1:]):
        return None
    for w in c.words[1:]:
        if not uses(w) and int_lit(w) is not None:
            return int_lit(w)
    return None


def var_compared(sf, name, after_seq):
    names = {name}
    for c in sf.cmds:
        if c.seq <= after_seq:
            continue
        for w in assign_words(c):
            nm, parts = sh_assign_parts(w)
            if not nm or nm in names:
                continue
            for p in parts:
                if (p.k == 'var' and p.v in names) or (p.k == 'arith' and any(
                        re.search(r'(?<![A-Za-z0-9_])\$?\{?%s\b' % re.escape(x), p.v) for x in names)):
                    names.add(nm)
                    break

    def uses(w):
        return any(p.k == 'var' and p.v in names for p in w.parts)

    for c in sf.cmds:
        if c.seq <= after_seq:
            continue
        if c.kind == 'arith':
            if any(re.search(r'(?<![A-Za-z0-9_])\$?%s\b' % re.escape(x), c.text) for x in names) and \
                    re.search(r'==|!=|<=|>=|<|>', c.text):
                m = re.search(r'(?:==|!=|<=|>=|<|>)\s*(\d+)', c.text)
                return c, (m.group(1) if m else '?')
            continue
        e = compare_of(c, uses)
        if e is not None:
            return c, e
    return None


def compared(sf, c, succ):
    """세는 명령 c 의 수가 기대값과 견주어지면 (견주는 명령, 기대값 글)."""
    after = pipe_after(c, succ)
    last = after[-1] if after else c
    owner = last.parent if last.insub else None
    k, prev = owner, last
    while k is not None:
        v = assigned_var(k)
        if v:
            return var_compared(sf, v, k.seq)
        target = prev

        def uses(w, _t=target):
            return _word_has_cmd(w, _t)

        e = compare_of(k, uses)
        if e is not None:
            return k, e
        if k.insub and k.parent is not None:
            prev, k = k, k.parent
            continue
        return None
    return None


def _word_has_cmd(w, t):
    """단어 w 의 명령 치환(겹친 것 포함) 안에 명령 t 가 있나."""
    stack = [p for p in w.parts if p.k == 'sub' and p.sub is not None]
    seen = 0
    while stack and seen < 200:
        p = stack.pop()
        seen += 1
        for x in p.sub.cmds:
            if x is t:
                return True
            for ww in x.words + x.assigns + x.herestr + [rw[1] for rw in x.redirs]:
                stack.extend(q for q in ww.parts if q.k == 'sub' and q.sub is not None)
    return False


def check_file(sf, script_rel):
    succ = succ_map(sf)
    pe = PathEval(sf, script_rel)
    roots = root_vars(sf, pe)
    out, allowed = [], 0
    for c, scope in count_sources(sf, succ, pe, roots):
        got = compared(sf, c, succ)
        if not got:
            continue
        cc, exp = got
        if sf.allowed(RULE, c.line) or sf.allowed(RULE, cc.line):
            allowed += 1
            continue
        out.append(('중간', sf.rel, c.line,
                    '글자 수를 세는 범위(%s)에 tests/static(점검 봇 규칙·견본)이 들어가 같은 글자를 함께 센다 — 그 수를 '
                    '기대값%s에 견준다(%d행) · `--exclude-dir=static` 이나 패치가 건드린 파일 목록 안에서만 세기'
                    % (scope, ('(%s)' % exp) if exp != '?' else '', cc.line)))
    return out, allowed


def main(argv=None):
    here = os.path.dirname(os.path.abspath(__file__))
    ap = argparse.ArgumentParser(description='R31 기대값과 견주는 글자 세기 범위에 tests/static 이 들어감 — 읽기만')
    sh_common_args(ap, here)
    args = ap.parse_args(argv)
    st = sh_setup(RULE, args)
    if st is None:
        print('RESULT pass=0 fail=0 skip=1')
        return 2
    root, outer, files, skipped = st
    sfs, bad = sh_parse_all(RULE, files)
    skipped += bad
    findings = []
    for rel, sf in sfs.items():
        script_rel = rel[len('../'):] if rel.startswith('../') else rel
        got, allowed = check_file(sf, script_rel)
        findings.extend(got)
        skipped += allowed
    return sh_emit(RULE, findings, len(sfs), skipped)


if __name__ == '__main__':
    # 경로에 UTF-8 이 아닌 바이트가 있어도 찍다가 죽지 않게 · 규칙 자체 오류는 「검사 못 함」(rc 2)
    for _s in (sys.stdout, sys.stderr):
        try:
            _s.reconfigure(errors='backslashreplace')
        except Exception:
            pass
    try:
        sys.exit(main())
    except KeyboardInterrupt:
        raise
    except SystemExit:
        raise
    except Exception as _e:
        sys.stderr.write('실행 오류: %s: %s\n' % (type(_e).__name__, _e))
        print('RESULT pass=0 fail=0 skip=1')
        sys.exit(2)
