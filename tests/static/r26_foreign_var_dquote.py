#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
R26 남의 변수 모양이 셸 큰따옴표 안 (nginx·PHP variable in shell double quotes) — 정적 규칙, python3 stdlib 만,
파일은 읽기만.

answers: HO-C-006(라운드 C 반영 스크립트 grep 패턴의 $arg_v — 결함 판은 git 밖이라 없음 → 견본 · 고친 판 qaredi
         0dd2240 scripts/apply-nginx-qf4c-2026-09-24.sh 는 작은따옴표 → 조용해야)

무엇을 부류로 잡나:
  셸은 큰따옴표 안·따옴표 없는 인자·풀리는 heredoc(<<EOF) 본문의 `$이름` 을 **셸 변수로** 먼저 펼친다. 반영
  스크립트·시험이 nginx 설정이나 PHP 코드를 grep·sed·heredoc 으로 다룰 때 `"map $arg_v …"` 처럼 nginx·PHP 의
  변수 모양을 큰따옴표로 적으면, 셸 변수 arg_v 가 없어 빈 글자가 되고(패턴이 우연히 맞거나 틀림) set -u 면 멈춘다.
  이 규칙은 그런 이름이 셸이 펼치는 자리에 있는 것을 잡는다.
    남의 변수 이름 — ① nginx 내장 변수(코드에 고정한 목록: $uri·$request_uri·$remote_addr·$host·$args… 와
      접두 $arg_·$http_·$sent_http_·$cookie_·$upstream_·$ssl_·$request_… ) ② 뿌리의 deploy/sites/*.conf 가
      map·set·geo·split_clients 로 정의한 변수(예: map 이 만든 $tbox_…) ③ PHP 전역($_SERVER·$_GET·$_POST·
      $_COOKIE·$_REQUEST·$_FILES·$_ENV·$_SESSION·$GLOBALS·$this).
    같은 파일(과 그 파일이 . · source 로 읽는 파일)이 그 이름을 셸 변수로 대입·선언하면(uri=…·local host·
      read status·for uri in …) 셸 변수로 보고 뺀다.
    조용: 작은따옴표('…')·$'…'·역슬래시(\$)·따옴표 친 heredoc(<<'EOF')·산술 $(( )) 안.
  심각도: 낮음(대개 「우연히 동작」 — 뜻이 셸 쪽으로 새는 것).

대상: --root 아래 --dirs(기본 tests·scripts·review-scripts)의 셸(*.sh·*.bash·셸 셔뱅) + --outer 를 주면
      <outer>/scripts/*.sh(반영 스크립트 — 출력 경로는 `../scripts/<이름>`, r18 과 같은 표기). --root 가 git 작업
      트리의 맨 위이면 `git ls-files --cached --others --exclude-standard`, 아니면 디렉터리 전체.
      기본으로 tests/static/fixtures/* 는 뺀다(--exclude 로 바꿈). 줄(또는 바로 윗줄)에 `qcheck-allow: R26` 이면 뺀다.
출력: FINDING R26 낮음 <파일:줄> <설명> · 끝 줄 RESULT pass=<대상 - 발견 파일> fail=<발견 수> skip=<못 읽음·구문 오류·표식>
rc:   0 발견 없음 · 1 발견 있음 · 2 실행 오류(뿌리·바깥 배치 없음 · git 목록 실패 = 검사 못 함)
한계: 셸 풀이는 bash 의 부분 집합(아래 공통 사본 머리 참고). 환경에서 받는 소문자 변수(부르는 쪽이 export)는
      모른다 — 그런 뜻이면 대입을 적거나 표식을 단다. PHP 변수는 전역 이름만 본다(일반 $x 는 셸 변수와 가를 수 없음).
"""
import argparse
import fnmatch
import os
import re
import subprocess
import sys

RULE = 'R26'


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

# ─── R26 ─────────────────────────────────────────────────────────────────────────────────────
# nginx 내장 변수(ngx_http_core_module 과 흔한 모듈의 「Embedded Variables」 — 고정 목록)
NGINX_EXACT = {
    'ancient_browser', 'args', 'binary_remote_addr', 'body_bytes_sent', 'bytes_sent', 'connection',
    'connection_requests', 'connection_time', 'connections_active', 'connections_reading', 'connections_waiting',
    'connections_writing', 'content_length', 'content_type', 'date_gmt', 'date_local', 'document_root',
    'document_uri', 'fastcgi_path_info', 'fastcgi_script_name', 'gzip_ratio', 'host', 'hostname', 'http2', 'http3',
    'https', 'invalid_referer', 'is_args', 'limit_conn_status', 'limit_rate', 'limit_req_status', 'memcached_key',
    'modern_browser', 'msec', 'msie', 'nginx_version', 'pid', 'pipe', 'proxy_add_x_forwarded_for', 'proxy_host',
    'proxy_port', 'query_string', 'realpath_root', 'remote_addr', 'remote_port', 'remote_user', 'request',
    'request_body', 'request_body_file', 'request_completion', 'request_filename', 'request_id', 'request_length',
    'request_method', 'request_time', 'request_uri', 'scheme', 'secure_link', 'secure_link_expires',
    'server_addr', 'server_name', 'server_port', 'server_protocol', 'slice_range', 'status', 'time_iso8601',
    'time_local', 'uid_got', 'uid_reset', 'uid_set', 'uri', 'realip_remote_addr', 'realip_remote_port',
}
NGINX_PREFIX = ('arg_', 'http_', 'sent_http_', 'sent_trailer_', 'cookie_', 'upstream_', 'ssl_', 'request_',
                'proxy_protocol_', 'tcpinfo_', 'geoip_', 'geoip2_', 'jwt_', 'realip_', 'limit_req_', 'limit_conn_')
PHP_GLOBALS = {'_SERVER', '_GET', '_POST', '_COOKIE', '_REQUEST', '_FILES', '_ENV', '_SESSION', 'GLOBALS', 'this'}
_CONF_DEF_RES = (
    re.compile(r'(?:^|[;{}\s])map\s+\S+\s+\$([A-Za-z_][A-Za-z0-9_]*)'),
    re.compile(r'(?:^|[;{}\s])set\s+\$([A-Za-z_][A-Za-z0-9_]*)'),
    re.compile(r'(?:^|[;{}\s])geo\s+(?:\$\S+\s+)?\$([A-Za-z_][A-Za-z0-9_]*)'),
    re.compile(r'(?:^|[;{}\s])split_clients\s+\S+\s+\$([A-Za-z_][A-Za-z0-9_]*)'),
    re.compile(r'(?:^|[;{}\s])(?:perl_set|js_set)\s+\$([A-Za-z_][A-Za-z0-9_]*)'),
)


# ─── 안전한 읽기(독립 검토 낮음 4) — 심볼릭 링크 · 뿌리 밖 · 너무 큰 파일은 읽지 않는다 ──────────────
READ_LIMIT = 4 * 1024 * 1024


def safe_file(path, root):
    """링크가 아니고, 실제 경로가 뿌리 안이며, 4MB 이하인 보통 파일만 참"""
    try:
        if os.path.islink(path) or not os.path.isfile(path):
            return False
        rp, rr = os.path.realpath(path), os.path.realpath(root)
        if rp != rr and not rp.startswith(rr + os.sep):
            return False
        return os.path.getsize(path) <= READ_LIMIT
    except OSError:
        return False


def conf_defined_vars(root):
    """deploy/sites/*.conf 가 map·set·geo·split_clients 로 만드는 변수 이름 — {이름: 파일}."""
    out = {}
    d = os.path.join(root, 'deploy', 'sites')
    if not os.path.isdir(d):
        return out
    for f in sorted(os.listdir(d)):
        full = os.path.join(d, f)
        if not f.endswith('.conf') or not safe_file(full, root):
            continue
        try:
            with open(full, 'rb') as fh:
                text = fh.read().decode('utf-8', 'replace')
        except OSError:
            continue
        # 주석(# 부터 줄 끝 — 따옴표 밖) 떼기
        clean = []
        for ln in text.split('\n'):
            q = None
            cut = len(ln)
            for k, ch in enumerate(ln):
                if q:
                    if ch == q:
                        q = None
                elif ch in '"\'':
                    q = ch
                elif ch == '#':
                    cut = k
                    break
            clean.append(ln[:cut])
        body = '\n'.join(clean)
        for rx in _CONF_DEF_RES:
            for m in rx.finditer(body):
                out.setdefault(m.group(1), 'deploy/sites/' + f)
    return out


def foreign_kind(name, confvars):
    if name in confvars:
        return 'nginx(%s 가 정의)' % confvars[name]
    if name in PHP_GLOBALS:
        return 'PHP'
    if name in NGINX_EXACT or any(name.startswith(p) and len(name) > len(p) for p in NGINX_PREFIX):
        return 'nginx 내장'
    return None


def cmd_parts(c):
    """명령의 단어 조각들(대입 값 · 인자 · 리다이렉트 대상 · <<< · 풀리는 heredoc 본문)."""
    for w in c.assigns + c.words + c.herestr:
        for p in w.parts:
            yield p
    for _op, w in c.redirs:
        for p in w.parts:
            yield p
    for hd in c.heredocs:
        for p in hd.parts:
            yield p


QDESC = {'d': '셸 큰따옴표 안', 'u': '따옴표 없는 인자', 'h': '풀리는 heredoc(<<EOF) 본문'}


def check_file(sf, defined, confvars):
    out, allowed = [], 0
    seen = set()
    for c in sf.cmds:
        for p in cmd_parts(c):
            if p.k != 'var' or p.q not in QDESC:
                continue
            kind = foreign_kind(p.v, confvars)
            if not kind or p.v in defined:
                continue
            key = (p.line, p.v)
            if key in seen:
                continue
            seen.add(key)
            if sf.allowed(RULE, p.line):
                allowed += 1
                continue
            out.append(('낮음', sf.rel, p.line,
                        '%s 변수 모양 $%s 가 %s — 셸이 먼저 펼친다(이 파일에 셸 변수 %s 대입 없음 → 빈 글자 · set -u 면 멈춤). '
                        '작은따옴표나 \\$ 로' % (kind, p.v, QDESC[p.q], p.v)))
    return out, allowed


def main(argv=None):
    here = os.path.dirname(os.path.abspath(__file__))
    ap = argparse.ArgumentParser(description='R26 nginx·PHP 변수 모양이 셸이 펼치는 자리(큰따옴표 등)에 있음 — 읽기만')
    sh_common_args(ap, here)
    args = ap.parse_args(argv)
    st = sh_setup(RULE, args)
    if st is None:
        print('RESULT pass=0 fail=0 skip=1')
        return 2
    root, outer, files, skipped = st
    sfs, bad = sh_parse_all(RULE, files)
    skipped += bad
    srcs = sh_resolve_sources(sfs)
    confvars = conf_defined_vars(root)
    findings = []
    for rel, sf in sfs.items():
        defined = set(sf.defined_names())
        for r in srcs.get(rel, []):
            defined |= sfs[r].defined_names()
        got, allowed = check_file(sf, defined, confvars)
        findings.extend(got)
        skipped += allowed
    return sh_emit(RULE, findings, len(sfs), skipped)


if __name__ == '__main__':
    # 경로에 UTF-8 이 아닌 바이트가 있어도 찍다가 죽지 않게(독립 검토 낮음 5) · 규칙 자체 오류는 「검사 못 함」(rc 2)
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
