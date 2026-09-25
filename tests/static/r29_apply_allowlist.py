#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
R29 패치 반영 스크립트의 허용 목록이 rename/copy 머리를 보지 않음 (patch allowlist bypass by rename/copy
headers) — 정적 규칙, python3 stdlib 만, 파일은 읽기만. 반영 스크립트를 돌리지 않는다.

answers: 대기열 11 독립 검토 [중간](rename 패치로 허용 목록 밖 파일 생성 재현 · Qaredi scripts 의 apply-*.sh 가
         같은 틀) · 고친 판: train 사본 q11-apply-q11-botfix-fixed.sh(rename/copy 거부 줄 — 조용해야) · 바깥 76cd677
         의 scripts/apply-1l-…·apply-checkbot-rules-v3-…(rename/copy·링크 거부 줄 — 조용해야) · 바깥 fa889e8 의 다른
         apply-*.sh(거부 줄 없음 — 울려야)

무엇을 부류로 잡나:
  `git apply` 로 패치를 반영하는 스크립트가 「허용 목록 밖 파일이 있으면 멈춘다」를 패치 **머리 줄**
  (`grep … '^diff --git '` · `grep '^+++ b/'` · `^--- a/`)에서 뽑은 경로로만 판정하면, 이름 바꾸기·복사 패치는
  `diff --git a/<허용> b/<허용>` 처럼 보이게 쓰고 `rename to <목록 밖>`·`copy to <목록 밖>` 로 목록 밖 파일을
  만든다(100% 같음이면 `+++` 줄도 없다). 이 규칙은 그런 허용 목록 검사가 있는데 rename/copy 머리
  (`rename from/to`·`copy from/to`·`similarity index`)를 **거부하는 줄**이 없는 스크립트를 잡는다.
    허용 목록 검사로 보는 것 — 머리 줄을 뽑는 grep·awk·sed 가 ① 같은 파이프 뒤에서 `grep -v…`(목록 밖만 남김)·
      awk `!…` 로 걸러지고, 그 파이프가 조건 자리(if · && · || · !)에 있거나 그 결과를 받은 변수를 뒤에서 `[ ]`·
      `[[ ]]`·test 로 볼 때 ② 그 결과를 받은 변수(한 단계 · mapfile 포함)가 뒤에서 `grep -v…` 로 걸러질 때
      ③ 그 결과를 도는 for·while 몸에 `case`·`[[`·`[`·grep 과 `exit`·`return`(또는 exit 하는 함수)이 있을 때.
      (`grep -c '^diff --git'` 처럼 세기만 하거나 `for f in $(grep '^diff --git' … | grep -v …); do php -l …` 처럼
      목록을 도는 것만으로는 허용 목록이 아니다.)
    거부 줄로 보는 것(조용) — grep·awk·sed·perl 의 패턴을 파이썬 re 로 옮겨(eval 없음 · -E/-F/-G/-P/-i/-w/-x 반영 ·
      확장이 든 패턴은 아님 · 돌리지 않는 「모름」 — 겹친 반복 · 끝없는 반복 넷 이상 · 300자 넘음 · 1초 넘음 · 못 옮김 — 은
      패턴 글자에 rename·copy·similarity 가 있을 때만 인정하는 글자 판정으로, 부하와 무관하게 결정적) 표본 머리 줄(`rename from/to …`·`copy from/to …`·
      `similarity index …` · --summary 출력 ` rename a => b (90%)`)에 하나라도 맞고 — git 의 rename/copy 머리엔 늘 이 줄들이
      있어 앵커 없는 `rename` 도 막는다 —, 입력이 허용 목록을 뽑은·반영하는 패치와 **확실히 다른 파일**(글자 경로 ·
      다른 자리 인자로 풀리는 변수 — P="$1" 같은 단순 별칭은 푼다)이 아니며(입력을 모르면 인정), -v 로 뒤집지 않았고,
      파이프 앞 grep 도 표본에 맞으며, 허용 목록 검사 파이프 자체가 아닌 것(v4 검토 중간 3 · 재검토 중간 1)이 조건
      (if·while · && · || · !) 자리에 있고, 첫 반영(`git apply`(--check·--stat·--numstat·--summary 빼고)·`git am`·
      `patch`)보다 앞에 있음. 링크 만들기(mode 120000) 거부가 없으면 설명에 함께 적는다 — 링크 거부만 없는 것은
      조용하다(경로는 허용 목록 안 · 대기열 11 고친 판 기준).
  표식: 허용 목록 줄·반영 줄(또는 바로 윗줄)의 `qcheck-allow: R29`.
  심각도: 중간(목록 밖 파일 생성 — 이미 반영이 끝난 일회용 스크립트면 분류표에서 낮춘다).

대상: --root 아래 --dirs(기본 tests·scripts·review-scripts)의 셸 + --outer 를 주면 <outer>/scripts/*.sh
      (출력 경로 `../scripts/<이름>`). 기본으로 tests/static/fixtures/* 는 뺀다. 셸 풀이기는 r20·r23·r25·r26·r28 과
      같은 사본(class ShPart … sh_emit — selftest 가 바이트 같음을 본다).
출력: FINDING R29 중간 <파일:줄> <설명> · 끝 줄 RESULT pass= fail= skip=
rc:   0 발견 없음 · 1 발견 있음 · 2 실행 오류
한계: 허용 목록을 `git apply --numstat`·`--summary` 로 뽑는 스크립트(rename 대상이 보인다)는 대상이 아니다.
      허용 목록 검사를 다른 파일의 함수로 하는 것 · eval 로 만든 명령은 보지 않는다. 거부 줄이 정말 멈추는지
      (then 갈래에 exit 이 있는지)는 보지 않는다.
"""
import argparse
import fnmatch
import os
import re
import signal
import subprocess
import sys
import threading

RULE = 'R29'


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

# ─── R29 ─────────────────────────────────────────────────────────────────────────────────────
HDR_RE = re.compile(r'diff --git|\^(?:\\?\+){3}|\^---(?:\s|$)')
REJ_RE = re.compile(r'rename|similarity', re.I)
# 거부 줄의 패턴이 rename/copy 머리에 맞나(v4 검토 중간 3 · 재검토 중간 1) — 패턴을 파이썬 re 로 옮겨(eval 없음) 표본 머리
# 줄에 맞춰 본다. git 의 rename/copy 머리에는 늘 `rename from`·`similarity index` 가 따르므로, 표본 하나에라도 맞으면 막는다.
REJ_HDR_SAMPLES = ('rename from a/x.php', 'rename to b/y.php', 'copy from a/x.php', 'copy to b/y.php',
                   'similarity index 90%', 'dissimilarity index 10%')
REJ_SUM_SAMPLES = (' rename a/x.php => b/y.php (90%)', ' rename a/{x.php => y.php} (90%)',
                   ' copy a/x.php => b/y.php (90%)')
POSIX_CLASS = {'space': r'\s', 'blank': r' \t', 'digit': '0-9', 'alpha': 'A-Za-z', 'alnum': 'A-Za-z0-9',
               'upper': 'A-Z', 'lower': 'a-z', 'punct': re.escape('!"#$%&\'()*+,-./:;<=>?@[\\]^_`{|}~'),
               'xdigit': '0-9A-Fa-f', 'print': r'\x20-\x7e', 'graph': r'\x21-\x7e', 'cntrl': r'\x00-\x1f'}
LINK_RE = re.compile(r'120000|symlink|symbolic', re.I)
TEXT_CMDS = {'grep', 'egrep', 'fgrep', 'rg', 'awk', 'gawk', 'mawk', 'sed', 'perl'}
APPLY_DRY = {'--check', '--stat', '--numstat', '--summary'}


def cname(c):
    ws = sh_effective(c.words)
    return os.path.basename(ws[0].lit() or '') if ws else ''


def arg_text(c):
    """명령 이름을 뺀 단어들의 글자(확장 조각은 뺌)."""
    ws = sh_effective(c.words)
    return ' '.join(w.lit_text() for w in ws[1:])


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


def is_inverse_filter(c):
    n = cname(c)
    ws = sh_effective(c.words)
    if n in ('grep', 'egrep', 'fgrep'):
        for w in ws[1:]:
            lt = w.lit()
            if lt is None:
                continue
            if lt == '--':
                break
            if lt == '--invert-match' or re.fullmatch(r'-[A-Za-z]*v[A-Za-z]*', lt):
                return True
        return False
    if n in ('awk', 'gawk', 'mawk'):
        for w in ws[1:]:
            lt = w.lit()
            if lt and not lt.startswith('-') and (lt.lstrip().startswith('!') or '!~' in lt):
                return True
        return False
    if n == 'comm':
        return any(re.fullmatch(r'-[123]+', w.lit() or '') for w in ws[1:])
    return False


def block_cmds(sf, b):
    return [c for c in sf.cmds if any(bb is b for bb, _p in c.blocks)]


def exiting_funcs(sf):
    out = set()
    for name, cmds in sf.funcs.items():
        if any(c.name() == 'exit' for c in cmds):
            out.add(name)
    return out


def loop_checks_list(sf, b, efuncs):
    """루프 몸이 목록을 판정하고(case·[[·[·test·grep) 멈추나(exit·return·exit 하는 함수)."""
    body = block_cmds(sf, b)
    judge = any(c.name() in ('case', '[[', '[', 'test') or cname(c) in ('grep', 'egrep') for c in body)
    stop = any(c.name() in ('exit', 'return') or c.name() in efuncs for c in body)
    return judge and stop


def loop_closed_before(sf, p):
    """`done < <(…)` · `done <<< …` 처럼 루프를 닫은 바로 뒤 명령 p 가 받는 루프 블록."""
    prev = None
    for c in sf.cmds:
        if c.seq >= p.seq:
            break
        if c.insub == p.insub:
            prev = c
    if prev is None:
        return None
    pset = set(id(bb) for bb, _x in p.blocks)
    for bb, _x in prev.blocks:
        if id(bb) not in pset and bb.get('kind') == 'loop':
            return bb
    return None


def top_owner(c):
    """치환 안 명령이면 그 치환을 가진 명령(한 단계)."""
    return c.parent


def assigned_var(owner):
    """owner 가 V=$(…) · local V=$(…) · mapfile … V 이면 V."""
    if owner is None:
        return None
    if not owner.words and len(owner.assigns) == 1:
        m = SH_ASSIGN_RE.match(owner.assigns[0].raw)
        return m.group(1) if m else None
    n0 = owner.name()
    if n0 in ('local', 'declare', 'typeset', 'readonly', 'export'):
        for w in owner.words[1:]:
            m = SH_ASSIGN_RE.match(w.raw)
            if m:
                return m.group(1)
    if n0 in ('mapfile', 'readarray') and len(owner.words) >= 2:
        lt = owner.words[-1].lit()
        if lt and re.fullmatch(r'[A-Za-z_][A-Za-z0-9_]*', lt):
            return lt
    return None


def word_uses_var(w, name):
    return any(p.k == 'var' and p.v == name for p in w.parts)


def cmd_uses_var(c, name):
    for w in c.words + c.herestr + [rw[1] for rw in c.redirs]:
        if word_uses_var(w, name):
            return True
    return False


def var_filtered_later(sf, name, after_seq, succ, efuncs):
    """변수 name 이 뒤에서 허용 목록으로 걸러지나."""
    for c in sf.cmds:
        if c.seq <= after_seq:
            continue
        if not cmd_uses_var(c, name):
            continue
        if c.herestr and any(word_uses_var(w, name) for w in c.herestr):
            if is_inverse_filter(c):
                return True
            if not c.words:
                b = loop_closed_before(sf, c)
                if b is not None and loop_checks_list(sf, b, efuncs):
                    return True
        if cname(c) in ('echo', 'printf', 'cat') and any(is_inverse_filter(k) for k in pipe_after(c, succ)):
            return True
        if c.kind == 'header' and c.words and c.words[0].lit() == 'for':
            b = c.blocks[-1][0] if c.blocks else None
            if b is not None and loop_checks_list(sf, b, efuncs):
                return True
    return False


def var_tested_later(sf, name, after_seq):
    for c in sf.cmds:
        if c.seq > after_seq and c.name() in ('[', '[[', 'test') and cmd_uses_var(c, name):
            return True
    return False


def allowlist_kind(sf, c, succ, efuncs):
    """머리 줄을 뽑는 명령 c 가 허용 목록 검사에 쓰이면 무엇으로 쓰이는지(글), 아니면 None."""
    after = pipe_after(c, succ)
    last = after[-1] if after else c
    if any(is_inverse_filter(k) for k in after):
        # 걸러 낸 목록이 멈추는 데 쓰일 때만 — 조건 자리(if · && · || · !) 이거나, 받은 변수를 뒤에서 [ ] 로 보거나
        if in_guard(last):
            return 'pipe'
        own = top_owner(last) if last.insub else None
        v = assigned_var(own)
        if v and var_tested_later(sf, v, own.seq):
            return 'pipe'
    # 파이프가 while 루프로 들어감
    for k in after:
        if k.cond is not None and k.cond.get('kind') == 'loop' and loop_checks_list(sf, k.cond, efuncs):
            return 'loop'
    owner = top_owner(last) if last.insub else None
    if owner is None:
        return None
    if owner.kind == 'header' and owner.words and owner.words[0].lit() == 'for':
        b = owner.blocks[-1][0] if owner.blocks else None
        if b is not None and loop_checks_list(sf, b, efuncs):
            return 'loop'
        return None
    if owner.words and owner.words[0].raw == '<(…)':
        b = loop_closed_before(sf, owner)
        if b is not None and loop_checks_list(sf, b, efuncs):
            return 'loop'
    v = assigned_var(owner)
    if v and var_filtered_later(sf, v, owner.seq, succ, efuncs):
        return 'var'
    return None


def apply_kind(c):
    """반영 명령이면 'apply' · 'check'(git apply --check 류) · None."""
    ws = c.words
    lits = [w.lit() for w in ws]
    for k in range(min(len(ws), 4)):
        if os.path.basename(lits[k] or '') != 'git':
            continue
        j = k + 1
        while j < len(ws):
            lt = lits[j]
            if lt in ('-C', '-c', '--git-dir', '--work-tree', '--namespace'):
                j += 2
                continue
            if lt is not None and lt.startswith('-'):
                j += 1
                continue
            break
        if j < len(ws) and lits[j] == 'apply':
            opts = set(x for x in lits[j + 1:] if x and x.startswith('--'))
            return 'check' if opts & APPLY_DRY else 'apply'
        if j < len(ws) and lits[j] == 'am':
            return 'apply'
        return None
    n = cname(c)
    if n == 'patch' and any(re.fullmatch(r'-p\d*', lt or '') or (lt or '').startswith('--strip') for lt in lits[1:]):
        return 'check' if any(lt in ('--dry-run', '-C') for lt in lits[1:]) else 'apply'
    return None


GREP_OPT_ARG = set('efmABCd') | {'--regexp', '--file', '--max-count', '--after-context', '--before-context',
                                 '--context', '--directories', '--devices', '--label', '--include', '--exclude'}


def _bracket(pat, i):
    """POSIX 괄호 식 pat[i] == '[' → (파이썬 글, 다음 자리). 못 옮기면 ValueError."""
    j = i + 1
    out = '['
    if j < len(pat) and pat[j] == '^':
        out += '^'
        j += 1
    first = True
    while j < len(pat):
        ch = pat[j]
        if ch == ']' and not first:
            return out + ']', j + 1
        first = False
        if ch == '[' and pat.startswith('[:', j):
            e = pat.find(':]', j + 2)
            if e < 0 or pat[j + 2:e] not in POSIX_CLASS:
                raise ValueError('class')
            out += POSIX_CLASS[pat[j + 2:e]]
            j = e + 2
            continue
        if ch == '[' and pat.startswith(('[.', '[='), j):
            raise ValueError('collating')
        out += '\\' + ch if ch in '\\[]^' else ch
        j += 1
    raise ValueError('unclosed')


def posix_to_py(pat, ere):
    """grep BRE·ERE → 파이썬 re 글. 못 옮기면 ValueError."""
    out = []
    i = 0
    atom_start = True           # 앞에 반복할 것이 없음(처음 · ^ · ( · | 뒤) — * + ? { 는 글자 그대로
    while i < len(pat):
        ch = pat[i]
        if ch == '\\' and i + 1 < len(pat):
            nx = pat[i + 1]
            i += 2
            if nx in '<>':
                out.append(r'\b')
                continue
            if nx in 'wWsSbB':
                out.append('\\' + nx)
                atom_start = nx in 'bB'
                continue
            if not ere and nx in '(|)':
                out.append(nx)
                atom_start = nx != ')'
                continue
            if not ere and nx in '+?{}':
                out.append(re.escape(nx) if atom_start else nx)
                continue
            if nx.isdigit():
                raise ValueError('backref')
            out.append(re.escape(nx))
            atom_start = False
            continue
        if ch == '[':
            b, i = _bracket(pat, i)
            out.append(b)
            atom_start = False
            continue
        if ch in '()|':
            if ere:
                out.append(ch)
                atom_start = ch != ')'
            else:
                out.append(re.escape(ch))
                atom_start = False
            i += 1
            continue
        if ch in '*+?{':
            special = ch == '*' or ere
            if ch == '{' and not re.match(r'\{\d*(,\d*)?\}', pat[i:]):
                special = False
            if special and not atom_start:
                if ch == '{':
                    e = pat.index('}', i)
                    out.append(pat[i:e + 1])
                    i = e + 1
                else:
                    out.append(ch)
                    i += 1
                continue
            out.append(re.escape(ch))
            atom_start = False
            i += 1
            continue
        if ch == '^':
            out.append(ch)
            atom_start = True
            i += 1
            continue
        out.append(ch if ch in '.$' else re.escape(ch))
        atom_start = False
        i += 1
    return ''.join(out)


def grep_mode(c):
    """grep 류 선택지 → (방식 'G'·'E'·'F'·'P', 대소문자 무시, -w, -x)."""
    n = cname(c)
    mode = {'egrep': 'E', 'fgrep': 'F', 'rg': 'P'}.get(n, 'G')
    icase = word = line = False
    longs = {'--extended-regexp': 'E', '--fixed-strings': 'F', '--basic-regexp': 'G', '--perl-regexp': 'P'}
    ws = sh_effective(c.words)[1:]
    k = 0
    while k < len(ws):
        lt = ws[k].lit()
        if lt is None or lt == '--' or not lt.startswith('-') or lt == '-':
            k += 1
            continue
        if lt.startswith('--'):
            nm = lt.split('=', 1)[0]
            mode = longs.get(nm, mode)
            icase = icase or nm == '--ignore-case'
            word = word or nm == '--word-regexp'
            line = line or nm == '--line-regexp'
            k += 2 if (nm in GREP_OPT_ARG and '=' not in lt) else 1
            continue
        for ch in lt[1:]:
            if ch in 'EFGP':
                mode = ch
            elif ch in 'iy':
                icase = True
            elif ch == 'w':
                word = True
            elif ch == 'x':
                line = True
            elif ch in 'efmABCd':
                break
        k += 1
    return mode, icase, word, line


def _prog_regexes(prog, sed):
    """awk·sed·perl 프로그램 글 안의 /정규식/ (sed 는 s<구분>정규식<구분> 도)."""
    out = [m.group(1) for m in re.finditer(r'(?:^|[\s;{(!~,&|])/((?:\\.|\[[^\]]*\]|[^/\\\n])+)/', prog)]
    if sed:
        out += [m.group(2) for m in re.finditer(r'(?:^|[\s;{}])s([^\w\s\\])((?:\\.|(?!\1).)+)\1', prog)]
    return out


REJ_PAT_MAX = 300          # 이보다 긴 패턴은 옮기지 않는다(「모름」 → 글자 판정)
REJ_QUANT_MAX = 4          # 끝없는 반복(* · + · {n,})이 이보다 많으면 돌리지 않는다(「모름」 → 글자 판정) — 표본(가장 긴 32자)
                           # 실측 최악(앵커 없는 `[^Q]*`×k Q): 4개 0.05초 · 5개 0.24초 · 6개 1.1초 · 8개 26초 → 1초와 먼 4
REJ_SEARCH_SECS = 1.0      # 표본 맞추기 한 번의 시간 상한(SIGALRM — 메인 스레드에서만 · 마지막 겹)
REJ_WORD_RE = re.compile(r'(?:rename|copy|similarity)(?![A-Za-z])')     # renamed·copyright 는 아님(뒤 단어 경계)
# 머리 줄 거부가 아님으로 확정하는 패턴(v4 재검토 4) — diff 본문 줄(더한 줄 ^+ · 뺀 줄 ^- · 문맥 줄 ^␠)로 시작하거나
# rename(·copy( 함수 호출 모양(ERE·-P 는 \( · BRE·-F 는 글자 괄호)을 담음. 문맥 줄 ^␠ 은 --summary 출력(` rename …`)을 볼 수
# 있을 때(입력이 --summary 이거나 모름)는 자르지 않는다.
REJ_BODY_LEAD_RE = re.compile(r'^\^(?:\\{0,2}\+|\[\+\]|\\?-|\[-\])')
REJ_CTX_LEAD_RE = re.compile(r'^\^(?: |\\s|\[ \]|\[\[:space:\]\]|\[\[:blank:\]\])')
REJ_CALL_ESC_RE = re.compile(r'(?:rename|copy)(?:\\s|\s|\[\[:space:\]\]|[*+?])*(?:\\\(|\[\(\])', re.I)
REJ_CALL_LIT_RE = re.compile(r'(?:rename|copy)(?:\\s|\s|\[\[:space:\]\]|[*+?])*(?:\(|\[\(\])', re.I)


class _ReTimeout(Exception):
    pass


def _quant_at(py, i):
    """py[i] 에서 시작하는 반복 기호의 길이 — 겹치면 위험한 반복(* · + · {n,} · {n,m}(m>10))이 아니면 0."""
    if i >= len(py):
        return 0
    if py[i] in '*+':
        return 1
    m = re.match(r'\{(\d*)(,(\d*))?\}', py[i:])
    if m and (m.group(2) and (m.group(3) == '' or int(m.group(3)) > 10)):
        return m.end()
    return 0


def risky_regex(py):
    """돌리지 않을 패턴인가(ReDoS 거르개 1겹) — 반복 안에 갈래·반복이 겹친 모양((…|…)* · (x+)+ 류)이 있거나, 끝없는 반복이
    REJ_QUANT_MAX 개보다 많음(`.*` 여럿 이은 것 — 다항 되돌이가 부하에 따라 시간 상한을 넘나들지 않게)."""
    stack = []          # 여는 괄호마다 [갈래 있음, 안에 반복 있음]
    nq = 0
    i = 0
    while i < len(py):
        ch = py[i]
        if ch == '\\':
            i += 2
            continue
        if ch == '[':
            j = i + 1
            if j < len(py) and py[j] == '^':
                j += 1
            if j < len(py) and py[j] == ']':
                j += 1
            while j < len(py) and py[j] != ']':
                j += 2 if py[j] == '\\' else 1
            i = j + 1
            continue
        if ch == '(':
            stack.append([False, False])
            i += 1
            if py.startswith('?', i):
                m = re.match(r'\?(?:[:=!>]|<[=!]|P<[^>]*>|P=[^)]*|[a-zA-Z]+[:)])', py[i:])
                i += m.end() if m else 1
            continue
        if ch == '|':
            if stack:
                stack[-1][0] = True
            i += 1
            continue
        if ch == ')':
            g = stack.pop() if stack else [False, False]
            i += 1
            q = _quant_at(py, i)
            if q and (g[0] or g[1]):
                return True
            nq += 1 if q else 0
            if stack and (g[1] or q):
                stack[-1][1] = True
            i += q
            continue
        q = _quant_at(py, i)
        if q:
            nq += 1
            if stack:
                stack[-1][1] = True
            i += q
            continue
        i += 1
    return nq > REJ_QUANT_MAX


def _search_any(rx, samples):
    """표본 가운데 하나에 맞나 — 시간 상한을 넘기면 None(모름). SIGALRM 은 메인 스레드에서만 건다. 앞 SIGALRM 핸들러는
    되돌리지만 앞 타이머(ITIMER_REAL)는 되살리지 않고 0 으로 둔다(이 규칙은 다른 타이머를 쓰지 않는다)."""
    use = hasattr(signal, 'SIGALRM') and hasattr(signal, 'setitimer') and \
        threading.current_thread() is threading.main_thread()
    if not use:
        return any(rx.search(x) for x in samples)

    def _on_alarm(_sig, _frm):
        raise _ReTimeout()
    old = signal.signal(signal.SIGALRM, _on_alarm)
    try:
        signal.setitimer(signal.ITIMER_REAL, REJ_SEARCH_SECS)
        try:
            return any(rx.search(x) for x in samples)
        finally:
            signal.setitimer(signal.ITIMER_REAL, 0)
    except _ReTimeout:
        return None
    finally:
        signal.signal(signal.SIGALRM, old)


def word_judge(one, icase):
    """「모름」(겹친 반복 · 반복 많음 · 300자 넘음 · 시간 상한 · 못 옮김)일 때의 글자 판정 — 패턴 글자에 rename·copy·similarity 가
    들어 있을 때만 거부로 인정(-i 면 대소문자 무시). 부하와 무관하게 결정적이다(v4 재검토 3)."""
    return bool(re.search(REJ_WORD_RE.pattern, one, re.I if icase else 0))


def not_header_reject(one, mode, samples):
    """패턴이 머리 줄 거부가 아님으로 확정되나(v4 재검토 4) — 본문 줄로 시작 · rename(·copy( 호출 모양."""
    if REJ_BODY_LEAD_RE.match(one):
        return True
    if REJ_CTX_LEAD_RE.match(one) and not any(x in REJ_SUM_SAMPLES for x in samples):
        return True
    call = REJ_CALL_LIT_RE if mode in ('G', 'F') else REJ_CALL_ESC_RE
    return bool(call.search(one))


def pattern_matches(c, samples):
    """거부 명령 c 의 패턴이 표본 줄 가운데 하나에 맞나 · 확장이 든 패턴은 False(모름) · 돌리지 않거나 못 돌린 패턴은
    글자 판정(word_judge)."""
    pats, _files = text_args(c)
    if not pats or any(w.lit() is None for w in pats):
        return False
    n = cname(c)
    rxs = []
    if n in ('grep', 'egrep', 'fgrep', 'rg'):
        mode, icase, word, line = grep_mode(c)
        for w in pats:
            for one in w.lit().split('\n'):
                rxs.append((mode, one, icase, word, line))
    else:
        sed = n == 'sed'
        ere = not sed or any(re.fullmatch(r'-[A-Za-z]*[Er][A-Za-z]*|--regexp-extended', w.lit() or '')
                             for w in sh_effective(c.words)[1:])
        for w in pats:
            found = _prog_regexes(w.lit(), sed)
            if not found and word_judge(w.lit(), False):
                return True             # 글자 비교(`$1 == "rename"`) — 옮기지 않고 글자 판정
            for one in found:
                rxs.append(('P' if n == 'perl' else ('E' if ere else 'G'), one, False, False, False))
    for mode, one, icase, word, line in rxs:
        if not_header_reject(one, mode, samples):
            continue                    # diff 본문 줄 · 함수 호출 모양 — 머리 줄 거부가 아님(확정)
        if len(one) > REJ_PAT_MAX:
            if word_judge(one, icase):
                return True             # 너무 긴 패턴 — 「모름」 → 글자 판정
            continue
        try:
            if mode == 'F':
                py = re.escape(one)
            elif mode == 'P':
                py = one
            else:
                py = posix_to_py(one, mode == 'E')
            if mode != 'F' and risky_regex(py):
                if word_judge(one, icase):
                    return True         # 겹친 반복 · 반복 많음(ReDoS 모양) — 돌리지 않고 「모름」 → 글자 판정
                continue
            if word:
                py = r'(?<!\w)(?:%s)(?!\w)' % py
            if line:
                py = r'^(?:%s)$' % py
            rx = re.compile(py, re.I if icase else 0)
        except (ValueError, re.error, RecursionError):
            if word_judge(one, icase):
                return True             # 못 옮김 — 「모름」 → 글자 판정
            continue
        got = _search_any(rx, samples)
        if got or (got is None and word_judge(one, icase)):
            return True                 # 맞음 · 시간 상한을 넘김(「모름」 → 글자 판정)
    return False


def _part_text(p):
    if p.k == 'lit':
        return p.v
    if p.k == 'pos':
        return '$%s' % p.v
    if p.k == 'var':
        return '${%s%s}' % (p.v, p.op or '')
    if p.k == 'sub':
        return '$(%s)' % ((p.sub.text if p.sub is not None else '') or '')
    return '$?'


class Canon(object):
    """입력·패치 단어를 견주는 꼴 — 단순 별칭(P="$1" · PATCH="${1:?}")은 푼다.
    'L:<글>' 글자 경로 · '$N' 자리 인자 · 'T:<글>' 그 밖(못 푼 변수 · 치환 · 섞인 것)."""

    def __init__(self, sf):
        self.alias = {}
        for c in sf.cmds:
            ws = list(c.assigns)
            if c.words and c.words[0].lit() in ('local', 'declare', 'typeset', 'readonly', 'export'):
                ws += [w for w in c.words[1:] if SH_ASSIGN_RE.match(w.raw)]
            for w in ws:
                name, parts = sh_assign_parts(w)
                if name:
                    self.alias.setdefault(name, []).append(parts)

    def of(self, parts, depth=0):
        ps = [p for p in parts if not (p.k == 'lit' and p.v == '')]
        if len(ps) == 1 and depth < 8:
            p = ps[0]
            op = p.op or ''
            plain = op == '' or op[:2] in (':?', ':-', ':=') or op[:1] in ('?', '-', '=')
            if p.k == 'pos' and isinstance(p.v, int) and plain:
                return '$%d' % p.v
            if p.k == 'var' and plain:
                vals = self.alias.get(p.v) or []
                if len(vals) == 1:
                    return self.of(vals[0], depth + 1)
                return 'T:$' + p.v
        if ps and all(p.k == 'lit' for p in ps):
            return 'L:' + ''.join(p.v for p in ps)
        return 'T:' + ''.join(_part_text(p) for p in ps)

    def word(self, w):
        return self.of(w.parts)


def surely_other(a, b):
    """두 꼴이 확실히 다른 파일인가 — 글자 경로끼리 다름 · 자리 인자끼리 다름 · 글자 경로와 이름이 다른 것."""
    if a == b:
        return False
    for x, y in ((a, b), (b, a)):
        if x.startswith('L:'):
            base = os.path.basename(x[2:])
            return not (y.startswith('T:') and base and y.endswith('/' + base))
    return bool(re.fullmatch(r'\$\d+', a) and re.fullmatch(r'\$\d+', b))


def text_args(c):
    """글 명령(grep·awk·sed·perl) → (패턴·프로그램 단어들, 입력 파일 단어들)."""
    n = cname(c)
    ws = sh_effective(c.words)[1:]
    pats, files, given, dd = [], [], False, False
    k = 0
    while k < len(ws):
        w = ws[k]
        lt = w.lit()
        if not dd and lt == '--':
            dd = True
            k += 1
            continue
        if not dd and lt is not None and lt.startswith('-') and len(lt) > 1:
            if n in ('grep', 'egrep', 'fgrep', 'rg'):
                if lt.startswith('--'):
                    nm, eq, val = lt.partition('=')
                    if nm in ('--regexp', '--file'):
                        given = True
                        if eq:
                            pats.append(w)
                        elif k + 1 < len(ws):
                            pats.append(ws[k + 1])
                    k += 2 if (nm in GREP_OPT_ARG and not eq) else 1
                    continue
                fl = lt[1:]
                for j, ch in enumerate(fl):
                    if ch in 'efmABCd':
                        if ch in 'ef':
                            given = True
                        if j == len(fl) - 1:
                            if ch in 'ef' and k + 1 < len(ws):
                                pats.append(ws[k + 1])
                            k += 1
                        elif ch in 'ef':
                            pats.append(w)
                        break
                k += 1
                continue
            if n in ('awk', 'gawk', 'mawk') and lt in ('-F', '-v', '-f'):
                given = given or lt == '-f'
                k += 2
                continue
            if n in ('sed', 'perl') and lt in ('-e', '-f', '--expression', '--file'):
                given = True
                if k + 1 < len(ws):
                    pats.append(ws[k + 1])
                k += 2
                continue
            k += 1
            continue
        if not given:
            pats.append(w)
            given = True
        else:
            files.append(w)
        k += 1
    files.extend(rw[1] for rw in c.redirs if rw[0] == '<')
    files.extend(c.herestr)
    return pats, files


def cmd_inputs(c, depth=0):
    """명령 c 가 읽는 파일 단어들 — 파일 인자 · < · <<< · 없으면 파이프 앞 명령(cat · git apply --summary · 글 명령)의 입력.
    (단어들 — 모르면 빈 목록, git apply --summary 를 거쳤나)."""
    if depth > 6:
        return [], False
    n = cname(c)
    if n in TEXT_CMDS:
        _pats, files = text_args(c)
        if files:
            return files, False
    elif n == 'cat':
        ws = [w for w in sh_effective(c.words)[1:] if not (w.lit() or '').startswith('-')]
        ws += [rw[1] for rw in c.redirs if rw[0] == '<']
        if ws:
            return ws, False
    else:
        pa = patch_args(c)
        if pa is not None:
            return pa, '--summary' in [w.lit() for w in c.words]
        return [], False
    if c.prev is not None and c.prev[1] in ('|', '|&'):
        return cmd_inputs(c.prev[0], depth + 1)
    return [], False


def patch_args(c):
    """git apply·git am·patch 명령이면 패치 인자 단어들(없으면 빈 목록), 아니면 None."""
    if apply_kind(c) is None:
        return None
    ws = c.words
    lits = [w.lit() for w in ws]
    out = []
    k = next((j for j, lt in enumerate(lits) if lt in ('apply', 'am')), None)
    if k is not None:
        j = k + 1
        while j < len(ws):
            lt = lits[j]
            if lt in ('--directory', '--exclude', '--include', '-p', '-C') and j + 1 < len(ws):
                j += 2
                continue
            if not (lt or '').startswith('-'):
                out.append(ws[j])
            j += 1
    out.extend(rw[1] for rw in c.redirs if rw[0] == '<')
    return out


def grep_context(c):
    """grep 에 문맥 선택지(-A·-B·-C·-<수>·--context·--after-context·--before-context)가 있나 — 맞은 줄 앞뒤 줄도 넘긴다."""
    for w in sh_effective(c.words)[1:]:
        lt = w.lit()
        if lt is None:
            continue
        if lt == '--':
            break
        if re.match(r'--(?:after-|before-)?context(?:=|$)', lt):
            return True
        if re.fullmatch(r'-\d+', lt):
            return True
        if lt.startswith('-') and not lt.startswith('--'):
            for ch in lt[1:]:
                if ch in 'ABC':
                    return True
                if ch in 'efmd':
                    break
    return False


def is_reject(c, patch, canon):
    """거부 줄인가(v4 검토 중간 3 · 재검토 중간 1) — ① 입력이 허용 목록을 뽑는·반영하는 패치와 확실히 다른 파일이면 아님
    (입력을 모르면 인정) ② 패턴이 표본 머리 줄(같은 패치면 패치 머리 · --summary 를 거치면 그 출력 · 모르면 둘 다)에 맞으면 거부.
    -v 로 뒤집은 grep 은 아니다 · 파이프 앞의 grep(뒤집지 않은 것)도 모두 표본에 맞아야 한다(`grep '^+++ b/' | grep -q .` 는 아님) —
    다만 앞 grep 에 -A·-B·-C 문맥 선택지가 있으면 그 grep 은 보지 않는다(v4 재검토 2 낮음)."""
    if cname(c) in ('grep', 'egrep', 'fgrep', 'rg') and is_inverse_filter(c):
        return False
    ws, summary = cmd_inputs(c)
    ins = set(canon.word(w) for w in ws)
    if ins and patch and all(any(surely_other(i, p) for p in patch) for i in ins):
        return False
    if summary:
        samples = REJ_SUM_SAMPLES
    elif ins & patch:
        samples = REJ_HDR_SAMPLES
    else:
        samples = REJ_HDR_SAMPLES + REJ_SUM_SAMPLES
    if not pattern_matches(c, samples):
        return False
    k, n = c, 0
    while k.prev is not None and k.prev[1] in ('|', '|&') and n < 50:
        k, n = k.prev[0], n + 1
        if cname(k) in ('grep', 'egrep', 'fgrep', 'rg') and not is_inverse_filter(k) and not grep_context(k) and \
                not pattern_matches(k, samples):
            return False                # 문맥 선택지가 있으면 맞은 줄 뒤의 rename 머리도 넘기므로 이 제외를 쓰지 않는다
    return True


def in_guard(c):
    k = c
    while k is not None:
        if k.cond is not None or k.neg or k.sep in ('&&', '||') or \
                (k.prev is not None and k.prev[1] in ('&&', '||')):
            return True
        k = k.parent
    return False


def check_file(sf):
    succ = succ_map(sf)
    efuncs = exiting_funcs(sf)
    applies = [c for c in sf.cmds if apply_kind(c) == 'apply']
    if not applies:
        return [], 0
    first_apply = min(applies, key=lambda c: c.seq)
    lists = []
    for c in sf.cmds:
        if cname(c) not in TEXT_CMDS:
            continue
        t = arg_text(c)
        m = HDR_RE.search(t)
        if not m:
            continue
        if REJ_RE.search(t) or LINK_RE.search(t):
            continue
        kind = allowlist_kind(sf, c, succ, efuncs)
        if kind:
            lists.append((c, 'diff --git' if 'diff' in m.group(0) else '+++/---'))
    if not lists:
        return [], 0
    # 허용 목록을 뽑은 패치 · 반영하는 패치 — 거부 줄은 이것을 읽어야 한다
    canon = Canon(sf)
    patch = set()
    for c, _h in lists:
        patch.update(canon.word(w) for w in cmd_inputs(c)[0])
    for c in applies:
        patch.update(canon.word(w) for w in patch_args(c) or [])
    in_lists = set()          # 허용 목록 검사 파이프 자체는 거부 줄이 아니다
    for c, _h in lists:
        in_lists.add(id(c))
        in_lists.update(id(k) for k in pipe_after(c, succ))
    rej_before = rej_after = link_before = False
    for c in sf.cmds:
        n = cname(c)
        if n not in TEXT_CMDS:
            continue
        t = arg_text(c)
        if not in_guard(c):
            continue
        if id(c) not in in_lists and is_reject(c, patch, canon):
            if c.seq < first_apply.seq:
                rej_before = True
            else:
                rej_after = True
        if LINK_RE.search(t) and c.seq < first_apply.seq:
            link_before = True
    if rej_before:
        return [], 0
    lists.sort(key=lambda x: x[0].seq)
    c0, hk = lists[0]
    if sf.allowed(RULE, c0.line) or sf.allowed(RULE, first_apply.line):
        return [], 1
    others = sorted(set(c.line for c, _h in lists[1:]) - {c0.line})
    more = (' · 같은 검사 %d곳 더(%s행)' % (len(others), ','.join(str(x) for x in others[:6]))) if others else ''
    link = '' if link_before else ' · 링크 만들기(mode 120000)도 거부하지 않는다'
    late = ' · 거부 줄이 반영 뒤에만 있다' if rej_after else ''
    msg = ('허용 목록을 패치 머리의 %s 줄에서만 뽑는데 rename/copy 머리(rename from/to·copy from/to·'
           'similarity index)를 거부하지 않는다%s%s — 이름 바꾸기·복사 패치로 목록 밖 파일을 만들 수 있다'
           '(반영 %d행%s · 거부 줄 `grep -qE \'^(rename|copy) (from|to) |^similarity index \'` 로 멈추기)'
           % (hk, link, late, first_apply.line, more))
    return [('중간', sf.rel, c0.line, msg)], 0


def main(argv=None):
    here = os.path.dirname(os.path.abspath(__file__))
    ap = argparse.ArgumentParser(description='R29 반영 스크립트 허용 목록이 rename/copy 머리를 보지 않음 — 읽기만')
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
        got, allowed = check_file(sf)
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
