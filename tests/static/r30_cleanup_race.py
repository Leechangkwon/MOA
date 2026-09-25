#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
R30 옛 임시 파일 청소가 동시 실행의 임시 파일을 지움 (stale-temp cleanup races a concurrent run) — 정적 규칙,
python3 stdlib 만, 파일은 읽기만.

answers: 대기열 11 검토 [중간](scripts/api_token_rotate.sh:59-60·84 — 결함 판은 저장소에 없다 → 견본 · 고친 판 운영
         b8a4a33 은 `find … -name '.env.rotate.*' -mmin +10 -delete` 로 조용해야) · 1l upgrade_smoke(tests/upgrade_smoke.sh —
         owner.pid 의 프로세스가 죽은 폴더만 지움 → 조용해야)

무엇을 부류로 잡나:
  kill -9 잔재를 치우려고 「다음 실행이 옛 임시 파일을 지우는」 청소 — `find <폴더> -name '<접두>*' … -delete`
  (· `-exec rm` · `| xargs rm` · 그 목록을 도는 루프의 rm) 나 `rm -f <폴더>/<접두>*` 글롭(· `for f in <글롭>; do rm …`) —
  가 **나이·주인 확인 없이** 돌고, 같은 스크립트가 같은 폴더에 **그 접두로** 임시 파일을 만들어(mktemp) 뒤에서
  읽거나(`wc -l < "$TMP"` · `cat "$TMP"`) 원본에 덮어쓰면(`cat "$TMP" > "$ENV_FILE"` · `mv "$TMP" 원본`), 거의 같은
  때 돈 두 실행 가운데 늦게 시작한 쪽이 먼저 시작한 쪽의 임시 파일을 지운다 — 읽기가 실패하거나, `>` 로 먼저
  비운 원본이 빈 채 남는다.
    나이·주인 확인으로 보는 것(조용) — find 의 `-mmin +N`·`-mtime +N`·`-amin`·`-atime`·`-cmin`·`-ctime`(+ 값)·
      `-newer…`/`! -newer` · 루프 몸의 owner.pid·*.pid·*.lock 읽기 · `kill -0` · `ps -p` · pgrep · fuser · lsof ·
      `stat -c %Y`·`%Z`·`%X` · `[ -ot ]`·`[ -nt ]` · 청소보다 앞선 `flock`(동시 실행을 막음).
      `-user` 만으로는 부족하다(같은 사람이 두 번 돌린다).
    빼는 것 — 청소 폴더가 이번 실행의 `mktemp -d` 아래(남과 나누지 않는 폴더) · 임시 파일을 만들기만 하고(쓰기 대상)
      읽지 않음 · 글롭이 mktemp 틀과 맞지 않음 · 청소 폴더와 mktemp 폴더가 다름(글자로 비교 — 한 번만 대입된
      변수는 그 값으로 풀고, `${TMPDIR:-/tmp}`·`$TMPDIR`·`/tmp`·`mktemp -t` 는 같은 자리로 본다).
  표식: 청소 줄·mktemp 줄(또는 바로 윗줄)의 `qcheck-allow: R30`.
  심각도: 중간(동시 실행에서 원본을 비우거나 시험이 거짓으로 실패).

대상: --root 아래 --dirs(기본 tests·scripts·review-scripts)의 셸 + --outer 를 주면 <outer>/scripts/*.sh
      (출력 경로 `../scripts/<이름>`). 기본으로 tests/static/fixtures/* 는 뺀다. 셸 풀이기는 r20·r23·r25·r26·r28 과
      같은 사본(class ShPart … sh_emit — selftest 가 바이트 같음을 본다).
출력: FINDING R30 중간 <파일:줄> <설명> · 끝 줄 RESULT pass= fail= skip=
rc:   0 발견 없음 · 1 발견 있음 · 2 실행 오류
한계: 폴더 비교는 글자 수준이다(다른 이름의 같은 폴더는 모른다). 청소와 mktemp 가 다른 파일(같은 접두를 쓰는 두
      스크립트)에 있으면 보지 않는다. 나이 확인이 정말 충분한지(N 분이 한 실행보다 긴지)는 보지 않는다.
"""
import argparse
import fnmatch
import os
import re
import subprocess
import sys

RULE = 'R30'


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

# ─── R30 ─────────────────────────────────────────────────────────────────────────────────────
AGE_OPTS = ('-mmin', '-mtime', '-amin', '-atime', '-cmin', '-ctime')
NONREAD = {'rm', 'trap', 'chmod', 'chown', 'chgrp', 'mkdir', 'touch', 'echo', 'printf', 'test', '[', '[[', 'local',
           'export', 'unset', 'rmdir', 'shred', 'readonly', 'declare', 'typeset', 'mktemp', 'true', ':'}
COPYLIKE = {'cp', 'mv', 'install', 'rsync', 'ln'}
GUARD_CMDS = {'ps', 'pgrep', 'pidof', 'fuser', 'lsof', 'flock'}


def cname(c):
    ws = sh_effective(c.words)
    return os.path.basename(ws[0].lit() or '') if ws else ''


def raw_name(c):
    """감싸는 명령(flock·timeout 류)을 벗기지 않은 첫 단어."""
    return os.path.basename(c.words[0].lit() or '') if c.words else ''


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


def block_cmds(sf, b):
    return [c for c in sf.cmds if any(bb is b for bb, _p in c.blocks)]


def loop_closed_before(sf, p):
    """`done < <(…)` 처럼 루프를 닫은 바로 뒤 명령 p 가 받는 루프 블록."""
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


def assign_words(c):
    if not c.words:
        return list(c.assigns)
    if c.words[0].lit() in ('local', 'declare', 'typeset', 'export', 'readonly'):
        return [w for w in c.words[1:] if SH_ASSIGN_RE.match(w.raw)]
    return []


def assign_map(sf):
    """이름 → [값 글자(따옴표 뗌)]."""
    out = {}
    for c in sf.cmds:
        for w in assign_words(c):
            m = SH_ASSIGN_RE.match(w.raw)
            if m:
                out.setdefault(m.group(1), []).append(re.sub(r'["\']', '', w.raw[m.end():]))
    return out


_TMP_HEAD = re.compile(r'^(?:\$\{TMPDIR(?::?[-=][^}]*)?\}|\$TMPDIR(?![A-Za-z0-9_])|/tmp(?=/|$))')


def norm_dir(t, amap, depth=0):
    t = (t or '').strip()
    t = re.sub(r'\$\{([A-Za-z_][A-Za-z0-9_]*)\}', r'$\1', t)
    t = _TMP_HEAD.sub('<TMP>', t)
    if len(t) > 1:
        t = t.rstrip('/')
    if t in ('', '.', './'):
        return '.'
    m = re.fullmatch(r'\$([A-Za-z_][A-Za-z0-9_]*)(/.*)?', t)
    if m and depth < 4:
        vals = amap.get(m.group(1))
        if vals and len(set(vals)) == 1 and vals[0] and '$(' not in vals[0] and '`' not in vals[0]:
            return norm_dir(vals[0].rstrip('/') + (m.group(2) or ''), amap, depth + 1)
    return t


def lead_var(t):
    m = re.match(r'\$\{?([A-Za-z_][A-Za-z0-9_]*)', (t or '').strip())
    return m.group(1) if m else None


def mktemp_of(sub):
    """치환 안 첫 mktemp → (폴더 글, 틀 바탕 이름, 뒤붙이, 폴더 여부) 또는 None."""
    for c in sub.cmds:
        ws = sh_effective(c.words)
        if not ws or os.path.basename(ws[0].lit() or '') != 'mktemp':
            continue
        is_dir, tdir, use_tmp, tmpl, suffix = False, None, False, None, ''
        k = 1
        while k < len(ws):
            t = word_text(ws[k])
            if t.startswith('-') and len(t) > 1:
                if t == '-p' and k + 1 < len(ws):
                    tdir = word_text(ws[k + 1])
                    k += 2
                    continue
                if t.startswith('--tmpdir='):
                    tdir = t[len('--tmpdir='):]
                elif t == '--tmpdir':
                    use_tmp = True
                elif t.startswith('--suffix='):
                    suffix = t[len('--suffix='):]
                elif t == '--directory':
                    is_dir = True
                elif not t.startswith('--'):
                    fl = t[1:]
                    if 'd' in fl:
                        is_dir = True
                    if 't' in fl:
                        use_tmp = True
                    if fl.endswith('p') and k + 1 < len(ws):
                        tdir = word_text(ws[k + 1])
                        k += 2
                        continue
                k += 1
                continue
            tmpl = t
            k += 1
        if tmpl is None:
            return ((tdir if tdir is not None else '<TMP>'), 'tmp.XXXXXXXXXX', suffix, is_dir)
        if '/' in tmpl:
            d, base = tmpl.rsplit('/', 1)
            return (d or '/', base, suffix, is_dir)
        if tdir is not None:
            return (tdir, tmpl, suffix, is_dir)
        return (('<TMP>' if use_tmp else '.'), tmpl, suffix, is_dir)
    return None


def filled_name(base, suffix):
    m = re.search(r'X{3,}$', base)
    if m:
        base = base[:m.start()] + 'a' * len(m.group(0))
    return base + suffix


def collect_mktemps(sf):
    out = []
    for c in sf.cmds:
        for w in assign_words(c):
            name, parts = sh_assign_parts(w)
            if not name:
                continue
            subs = [p for p in parts if p.k == 'sub' and p.sub is not None]
            if len(subs) != 1:
                continue
            info = mktemp_of(subs[0].sub)
            if info is None:
                continue
            out.append({'var': name, 'cmd': c, 'dir': info[0], 'base': info[1], 'suffix': info[2],
                        'is_dir': info[3], 'line': c.line})
    return out


def derived_names(sf, name):
    """name 과 그 값을 뿌리로 받은 변수(한 단계: X="$name/…" · X="$name")."""
    out = {name}
    for c in sf.cmds:
        for w in assign_words(c):
            nm, parts = sh_assign_parts(w)
            ps = [p for p in parts if not (p.k == 'lit' and p.v == '')]
            if nm and ps and ps[0].k == 'var' and ps[0].v == name and nm != name:
                out.add(nm)
    return out


def rooted_at(w, names):
    ps = [p for p in w.parts if not (p.k == 'lit' and p.v == '')]
    return bool(ps) and ps[0].k == 'var' and ps[0].v in names


def read_use(c, names, temp_names):
    """명령 c 가 names 아래를 읽으면 'read' · 읽어서 임시 밖에 쓰면 'overwrite' · 아니면 None."""
    ws = sh_effective(c.words)
    n = os.path.basename(ws[0].lit() or '') if ws else ''
    reads = False
    dest_out = False
    for op, w in c.redirs:
        if op == '<' and rooted_at(w, names):
            reads = True
        if op in ('>', '>>', '>|') and not rooted_at(w, temp_names):
            dest_out = True
    if ws and n not in NONREAD:
        args = [w for w in ws[1:] if not (w.lit() or '').startswith('-')]
        if n in COPYLIKE and len(args) >= 2:
            if any(rooted_at(w, names) for w in args[:-1]):
                reads = True
                if not rooted_at(args[-1], temp_names):
                    dest_out = True
        else:
            if any(rooted_at(w, names) for w in ws[1:]):
                reads = True
    if not reads:
        return None
    return 'overwrite' if dest_out else 'read'


def body_guard(body):
    for c in body:
        n = cname(c) or (c.name() or '')
        texts = [word_text(w) for w in c.words]
        if n == 'kill' and '-0' in texts:
            return True
        if n in GUARD_CMDS or raw_name(c) in GUARD_CMDS:
            return True
        if n == 'stat' and any(re.search(r'%[YZX]', t) for t in texts):
            return True
        if n == 'find' and any(t in AGE_OPTS or t.startswith('-newer') for t in texts):
            return True
        if (c.name() or '') in ('[', '[[', 'test') and any(t in ('-ot', '-nt') for t in texts):
            return True
        allt = ' '.join(texts + [word_text(w) for _op, w in c.redirs])
        if re.search(r'owner\.pid|\.pid(?![A-Za-z0-9_])|\.lock(?![A-Za-z0-9_])', allt):
            return True
    return False


def find_parts(ws):
    """find 단어들 → (폴더 글들, 이름 무늬들, 나이 확인, -user 있음, 지우기 있음)."""
    texts = [word_text(w) for w in ws]
    k = 1
    while k < len(texts) and texts[k] in ('-L', '-H', '-P'):
        k += 1
    dirs = []
    while k < len(texts) and not (texts[k].startswith('-') or texts[k] in ('(', '!', '\\(')):
        dirs.append(texts[k])
        k += 1
    pats, age, user, dele = [], False, False, False
    for j in range(k, len(texts)):
        t = texts[j]
        nx = texts[j + 1] if j + 1 < len(texts) else ''
        if t in ('-name', '-iname') and nx:
            pats.append(nx)
        elif t in ('-path', '-ipath', '-wholename', '-iwholename') and nx:
            pats.append(nx.rsplit('/', 1)[-1])
        elif t in AGE_OPTS and nx.startswith('+'):
            age = True
        elif t.startswith('-newer'):
            age = True
        elif t in ('-user', '-uid'):
            user = True
        elif t == '-delete':
            dele = True
        elif t in ('-exec', '-execdir', '-ok', '-okdir') and os.path.basename(nx) == 'rm':
            dele = True
    return dirs or ['.'], pats, age, user, dele


def collect_cleanups(sf, succ):
    """[{cmd, line, dirs, pats, guard, user}] — 지우는 청소만."""
    out = []
    for c in sf.cmds:
        ws = sh_effective(c.words)
        n = os.path.basename(ws[0].lit() or '') if ws else ''
        if n == 'find':
            dirs, pats, age, user, dele = find_parts(ws)
            if not pats:
                continue
            guard = age
            after = pipe_after(c, succ)
            if not dele:
                for k in after:
                    kws = sh_effective(k.words)
                    kn = os.path.basename(kws[0].lit() or '') if kws else ''
                    if kn == 'xargs' and any(os.path.basename(w.lit() or '') == 'rm' for w in kws[1:]):
                        dele = True
                        break
            loop = None
            for k in after:
                if k.cond is not None and k.cond.get('kind') == 'loop':
                    loop = k.cond
                    break
            if loop is None and c.insub and c.parent is not None and c.parent.words and \
                    c.parent.words[0].raw == '<(…)':
                loop = loop_closed_before(sf, c.parent)
            if loop is not None and not dele:
                body = block_cmds(sf, loop)
                if any(cname(b) == 'rm' for b in body):
                    dele = True
                    guard = guard or body_guard(body)
            if dele:
                out.append({'cmd': c, 'line': c.line, 'dirs': dirs, 'pats': pats, 'guard': guard, 'user': user,
                            'what': 'find'})
        elif n == 'rm':
            for w in ws[1:]:
                if (w.lit() or '').startswith('-'):
                    continue
                if not any(p.k == 'lit' and p.q == 'u' and re.search(r'[*?\[]', p.v) for p in w.parts):
                    continue
                t = word_text(w)
                d, pat = (t.rsplit('/', 1) if '/' in t else ('.', t))
                if not re.search(r'[*?\[]', pat):
                    continue
                out.append({'cmd': c, 'line': c.line, 'dirs': [d or '/'], 'pats': [pat], 'guard': False,
                            'user': False, 'what': 'rm'})
        elif c.kind == 'header' and c.words and c.words[0].lit() == 'for' and c.blocks:
            b = c.blocks[-1][0]
            body = [x for x in block_cmds(sf, b) if x is not c]
            if not any(cname(x) == 'rm' for x in body):
                continue
            for w in c.words[3:]:
                if not any(p.k == 'lit' and p.q == 'u' and re.search(r'[*?\[]', p.v) for p in w.parts):
                    continue
                t = word_text(w)
                d, pat = (t.rsplit('/', 1) if '/' in t else ('.', t))
                if not re.search(r'[*?\[]', pat):
                    continue
                out.append({'cmd': c, 'line': c.line, 'dirs': [d or '/'], 'pats': [pat], 'guard': body_guard(body),
                            'user': False, 'what': 'for'})
    return out


def check_file(sf):
    mks = collect_mktemps(sf)
    if not mks:
        return [], 0
    succ = succ_map(sf)
    cleanups = collect_cleanups(sf, succ)
    if not cleanups:
        return [], 0
    amap = assign_map(sf)
    private = set()
    for mk in mks:
        if mk['is_dir']:
            private |= derived_names(sf, mk['var'])
    temp_names = set()
    for mk in mks:
        temp_names |= derived_names(sf, mk['var'])
    flocks = [c.seq for c in sf.cmds if raw_name(c) == 'flock' or cname(c) == 'flock']
    out, allowed = [], 0
    for cl in cleanups:
        if cl['guard']:
            continue
        if any(s < cl['cmd'].seq for s in flocks):
            continue
        cdirs = [d for d in cl['dirs'] if lead_var(d) not in private]
        if not cdirs:
            continue
        ckeys = set(norm_dir(d, amap) for d in cdirs)
        hit = None
        for mk in mks:
            if norm_dir(mk['dir'], amap) not in ckeys:
                continue
            fn = filled_name(mk['base'], mk['suffix'])
            if not any(fnmatch.fnmatchcase(fn, p) for p in cl['pats']):
                continue
            names = derived_names(sf, mk['var'])
            rd = ow = None
            for c in sf.cmds:
                if c.seq <= mk['cmd'].seq:
                    continue
                u = read_use(c, names, temp_names)
                if u == 'read' and rd is None:
                    rd = c.line
                if u == 'overwrite' and ow is None:
                    ow = c.line
            if rd is None and ow is None:
                continue
            hit = (mk, rd, ow)
            break
        if hit is None:
            continue
        mk, rd, ow = hit
        if sf.allowed(RULE, cl['line']) or sf.allowed(RULE, mk['line']):
            allowed += 1
            continue
        if mk['is_dir']:
            act = '%d행에서 그 안의 파일을 읽는다' % min(x for x in (rd, ow) if x is not None)
        elif rd is not None and ow is not None:
            act = '%d행에서 읽고 %d행에서 원본에 덮어쓴다' % (rd, ow)
        elif ow is not None:
            act = '%d행에서 원본에 덮어쓴다' % ow
        else:
            act = '%d행에서 읽는다' % rd
        user = ' (-user 만 있음 — 같은 사람이 두 번 돌리면 못 막는다)' if cl['user'] else ''
        out.append(('중간', sf.rel, cl['line'],
                    '옛 임시 %s 청소(`%s` — %s)가 나이·주인 확인 없이 돈다%s — 같은 스크립트가 같은 자리에 그 접두로 '
                    '임시 %s 만들어(mktemp %d행) %s — 동시에 돈 다른 실행이 이 임시 파일을 지운다'
                    '(find `-mmin +N` 같은 나이 조건이나 owner.pid 로 주인이 죽은 것만 지우기)'
                    % ('폴더' if mk['is_dir'] else '파일', cl['pats'][0], cl['what'], user,
                       '폴더를' if mk['is_dir'] else '파일을', mk['line'], act)))
    return out, allowed


def main(argv=None):
    here = os.path.dirname(os.path.abspath(__file__))
    ap = argparse.ArgumentParser(description='R30 옛 임시 파일 청소가 동시 실행의 임시 파일을 지움 — 읽기만')
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
