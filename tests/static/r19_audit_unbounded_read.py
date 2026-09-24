#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
R19 감사 행을 실행 경계 없이 읽는 단언 (unbounded audit read in a test assertion) — 1-F 정적 규칙,
python3 stdlib 만, 파일은 읽기만.

answers: BF-0005

무엇을 부류로 잡나:
  감사·기록 표는 지우지 않는 표라서 앞선 실행이 남긴 행이 계속 쌓여 있다. 시험이 그 표를
  `ORDER BY … LIMIT n`(가장 최근 행)·`max(…)`·`min(…)`·`count(…)`·`exists(…)` 로 읽어 **단언에 쓰는데**
  「이번 실행에서 생긴 행」으로 좁히는 경계가 없으면, 이번 실행이 행을 남기지 않아도 옛 행으로 통과한다
  (계정·데이터를 바꾸는 순간 드러난다). 이 규칙은 셸 시험(tests/**)에서 그 모양을 잡는다.
    감사·기록 표 — db/** 의 CREATE TABLE 가운데 ① 앱 계정 권한이 SELECT·INSERT 뿐인 표(GRANT 로 판정, 지울 수 없는
      기록) 이거나 ② 표 이름 낱말이 audit·log·event·history·journal·trail 인 표. 이름을 손으로 적지 않는다.
    SQL 조각 — 셸 단어(따옴표 글자·heredoc·<<<) 가운데 SELECT … FROM/JOIN <기록 표> 가 있는 것. SQL 을 먼저 변수에
      담아 넘기는 것(`Q="SELECT…"; psqlq "$Q"`)도 한 단계 따라간다.
    단언에 쓰임 — 그 조각의 결과가 ⓐ 단언 도우미(몸에서 FAIL 류 셈을 올리는 함수, 여러 단계·source 한 파일 포함)의
      인자로 들어가거나 ⓑ `[ ]`·`[[ ]]`·`test` 비교에 들어가거나 ⓒ 변수에 담긴 뒤(`V=$(…)`·`db_read_var V …` 처럼
      첫 인자에 값을 넣는 도우미) 그 변수가 ⓐ·ⓑ 에 들어가는 것(한 단계). SQL 이 함수 몸에 있으면(`views_of() {…}`)
      그 함수를 부르는 자리의 쓰임을 본다. echo·printf·파이프는 값을 그대로 넘기는 것으로 본다. note·echo 로
      찍기만 하는 것은 단언이 아니다.
    경계 — 같은 SQL 안에 ① id·시각 열(기록 표의 BIGSERIAL·TIMESTAMP·DATE 열)을 `>`·`>=`·BETWEEN 으로 이번 실행의
      값($MARK·'$T0'·${M:-0}·:mark·now()-interval…)과 비교하거나 ② 이번 실행에서 만든 개체의 id 열(`…_id`·`id` —
      계정을 가리키는 열은 뺀다: users 를 REFERENCES 하는 열 · actor/user/owner/by 낱말)을 글자만 넣은 게 아닌
      변수·`$1`·`$(…)` 와 `=`·IN 으로 맞추거나 ③ 어떤 열이든 실행마다 새 값($$·$RANDOM·date +%s·uuidgen 에서 온
      변수)과 맞추는 것. 단언이 같은 표를 먼저 잰 변수와 견주는 전후 대조(`[ "$(count)" -ge "$m0" ]`·BEFORE=AFTER)도
      경계로 친다. 경계 변수를 먼저 재는 줄(`MARK=$(… max(id) …)`)은 단언이 아니라 보지 않는다.
  판단 보류(발견 아님, skip 으로 셈): 「0 건이어야」 같은 전역 불변식 — `count(…)` 결과를 0 과 견주거나
      SQL 이 `count(*) = 0`·NOT EXISTS 인 것. 옛 행은 거짓 통과가 아니라 거짓 실패만 만든다.
  심각도: 중간.

대상: --root 아래 --dirs(기본 tests)의 셸(*.sh·*.bash·셸 셔뱅). 기록 표 사전은 --root 의 db/**/*.sql.
      --root 가 git 작업 트리의 맨 위이면 `git ls-files --cached --others --exclude-standard`, 아니면 디렉터리 전체.
      기본으로 `tests/static/fixtures/*` 는 뺀다(--exclude 로 바꿈).
출력: FINDING R19 중간 <파일:줄(SQL 안에서 기록 표를 FROM/JOIN 으로 부르는 줄)> <설명> · 끝 줄 RESULT pass=<단언에 쓰인 기록 표 조각 - 발견>
      fail=<발견 수> skip=<전역 불변식 + 못 읽음·구문 오류>. --verbose 는 기록 표 사전과 SKIP 까닭을 NOTE·SKIP 줄로.
rc:   0 발견 없음 · 1 발견 있음 · 2 실행 오류(뿌리 없음·git 목록 실패·db/** 에 CREATE TABLE 없음 = 검사 못 함)
한계: PHP·파이썬 시험 안의 SQL, heredoc 으로 만드는 속 스크립트, 변수를 두 번 건너는 흐름, 이름 열(`target_name`)을
      이번 실행에서 만든 고유 이름과 맞추는 경계(값이 실행마다 새로운지 모르면)는 보지 않는다.
"""
import argparse
import fnmatch
import os
import re
import subprocess
import sys

RULE = 'R19'
DEFAULT_DIRS = ('tests',)
DEFAULT_EXCLUDES = ['tests/static/fixtures/*']
_TOK_RE = re.compile(r'[A-Z]?[a-z]+|[A-Z]+(?![a-z])|[0-9]+')


def name_tokens(name):
    return [t.lower() for t in _TOK_RE.findall(name or '')]


# ═══════════════════════════ 셸 구문 분석(R18 과 같은 부분 집합) ═══════════════════════════
SH_REDIR_RE = re.compile(r'(\d*|\{[A-Za-z_][A-Za-z0-9_]*\})(&>>|&>|<<<|<<-|<<|>>|>\||>&|<&|<>|>|<)')
SH_OPS = ('&&', '||', ';;&', ';;', ';&', '|&', '|', '&', ';', '(', ')')
ASSIGN_RE = re.compile(r'^([A-Za-z_][A-Za-z0-9_]*)(\[[^\]]*\])?\+?=')


class ShW(object):
    __slots__ = ('parts', 'line', 'raw')

    def __init__(self, parts, line, raw=''):
        self.parts, self.line, self.raw = parts, line, raw

    def lit(self):
        s = []
        for p in self.parts:
            if p[0] != 'lit':
                return None
            s.append(p[1])
        return ''.join(s)

    def bare(self):
        if len(self.parts) == 1 and self.parts[0][0] == 'lit' and self.parts[0][2] == 0:
            return self.parts[0][1]
        return None

    def text(self):
        out = []
        for p in self.parts:
            k = p[0]
            if k == 'lit':
                out.append(p[1])
            elif k == 'var':
                out.append('${%s}' % p[1])
            elif k == 'pos':
                out.append('$%s' % p[1])
            elif k == 'sub':
                out.append('$(%s)' % p[2])
            else:
                out.append('$((…))')
        return ''.join(out)

    def own_text(self):
        """명령 치환 속 글자는 `$(…)` 로만 그린다(속 명령의 SQL 을 바깥 단어에서 다시 세지 않게)."""
        out = []
        for p in self.parts:
            k = p[0]
            if k == 'lit':
                out.append(p[1])
            elif k == 'var':
                out.append('${%s}' % p[1])
            elif k == 'pos':
                out.append('$%s' % p[1])
            elif k == 'sub':
                out.append('$(…)')
            else:
                out.append('$((…))')
        return ''.join(out)

    def vars(self):
        return [p[1] for p in self.parts if p[0] == 'var']

    def subs(self):
        return [p[1] for p in self.parts if p[0] == 'sub']

    def single_var(self):
        """단어 전체가 변수 하나인가("$X" · ${X:-0} · $X) → 이름."""
        vs = [p for p in self.parts if not (p[0] == 'lit' and p[1] == '')]
        if len(vs) == 1 and vs[0][0] in ('var', 'pos'):
            return vs[0][1] if vs[0][0] == 'var' else '$%s' % vs[0][1]
        return None


class SN(object):
    def __init__(self, kind, line, **kw):
        self.kind, self.line = kind, line
        self.words, self.assigns, self.redirs, self.heredocs, self.herestr = [], [], [], [], []
        self.__dict__.update(kw)


class ShParser(object):
    def __init__(self, text, pos=0, line=1):
        self.t, self.n, self.i, self.line = text, len(text), pos, line
        self.pend = []
        self.peeked = None

    # ── 글자 단위 ──
    def _blank(self):
        while self.i < self.n:
            c = self.t[self.i]
            if c in ' \t\r':
                self.i += 1
            elif c == '\\' and self.t.startswith('\\\n', self.i):
                self.i += 2
                self.line += 1
            elif c == '#':
                k = self.t.find('\n', self.i)
                self.i = self.n if k < 0 else k
            else:
                break

    def _match(self, i, op, cl):
        depth, j = 0, i
        while j < self.n:
            c = self.t[j]
            if c == '\\':
                j += 2
                continue
            if c == "'" and op == '(':
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

    def _heredocs(self):
        for delim, strip, quoted, node in self.pend:
            start = self.line
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
            node.heredocs.append(('\n'.join(lines), start, quoted))
        self.pend = []

    def peek(self):
        if self.peeked is None:
            self.peeked = self._lex()
        return self.peeked

    def take(self):
        t = self.peek()
        self.peeked = None
        return t

    def _lex(self):
        self._blank()
        if self.i >= self.n:
            return ('eof', None, self.line)
        c = self.t[self.i]
        line = self.line
        if c == '\n':
            self.i += 1
            self.line += 1
            if self.pend:
                self._heredocs()
            return ('nl', None, line)
        m = SH_REDIR_RE.match(self.t, self.i)
        if m:
            self.i = m.end()
            return ('redir', m.group(2), line, m.group(1))
        for op in SH_OPS:
            if self.t.startswith(op, self.i):
                self.i += len(op)
                return ('op', op, line)
        w = self._word()
        return ('w', w, line)

    def _word(self):
        parts, buf = [], []
        start, line0 = self.i, self.line

        def flush():
            if buf:
                parts.append(('lit', ''.join(buf), 0))
                del buf[:]

        while self.i < self.n:
            c = self.t[self.i]
            if c in ' \t\n;&|()<>\r':
                if c == '(' and not parts and re.match(r'^[A-Za-z_][A-Za-z0-9_]*(\[[^\]]*\])?\+?=$', ''.join(buf)):
                    j = self._match(self.i, '(', ')')
                    inner = self.t[self.i + 1:j]
                    flush()
                    sp = ShParser(inner, 0, self.line)
                    while True:
                        tk = sp.take()
                        if tk[0] == 'eof':
                            break
                        if tk[0] == 'w':
                            parts.extend(tk[1].parts)
                            parts.append(('lit', ' ', 0))
                    self.line += inner.count('\n')
                    self.i = j + 1
                    continue
                if c in '<>' and self.t.startswith('(', self.i + 1):
                    # 프로세스 치환 <(…) >(…)
                    j = self._match(self.i + 1, '(', ')')
                    sp = ShParser(self.t, self.i + 2, self.line)
                    node = sp.parse_list(stop_ops={')'})
                    flush()
                    parts.append(('sub', node, 'proc'))
                    self.line = sp.line
                    self.i = max(sp.i, j + 1) if sp.peek()[0] != 'op' else sp.i
                    if self.i <= j:
                        self.i = j + 1
                    continue
                break
            if c == '\\':
                if self.t.startswith('\\\n', self.i):
                    self.i += 2
                    self.line += 1
                    continue
                buf.append(self.t[self.i + 1:self.i + 2])
                self.i += 2
                continue
            if c == "'":
                flush()
                k = self.t.find("'", self.i + 1)
                if k < 0:
                    k = self.n
                s = self.t[self.i + 1:k]
                parts.append(('lit', s, 1))
                self.line += s.count('\n')
                self.i = k + 1
                continue
            if c == '"':
                self.i += 1
                flush()
                parts.extend(self._dquote())
                continue
            if c == '$':
                flush()
                parts.extend(self._dollar(0))
                continue
            if c == '`':
                flush()
                parts.append(self._backtick())
                continue
            buf.append(c)
            self.i += 1
        flush()
        return ShW(parts, line0, self.t[start:self.i])

    def _dquote(self):
        parts, buf = [], []

        def flush():
            if buf:
                parts.append(('lit', ''.join(buf), 2))
                del buf[:]

        while self.i < self.n:
            c = self.t[self.i]
            if c == '"':
                self.i += 1
                break
            if c == '\\' and self.i + 1 < self.n and self.t[self.i + 1] in '$`"\\\n':
                if self.t[self.i + 1] == '\n':
                    self.line += 1
                else:
                    buf.append(self.t[self.i + 1])
                self.i += 2
                continue
            if c == '$':
                flush()
                parts.extend(self._dollar(2))
                continue
            if c == '`':
                flush()
                parts.append(self._backtick())
                continue
            if c == '\n':
                self.line += 1
            buf.append(c)
            self.i += 1
        flush()
        if not parts:
            parts.append(('lit', '', 2))
        return parts

    def _dollar(self, q):
        t, i = self.t, self.i
        nx = t[i + 1] if i + 1 < self.n else ''
        if nx == '(':
            if t.startswith('((', i + 1):
                j = self._match(i + 1, '(', ')')
                self.line += t[i:j].count('\n')
                self.i = j + 1
                return [('arith', t[i + 3:j - 1])]
            sp = ShParser(t, i + 2, self.line)
            start = i + 2
            node = sp.parse_list(stop_ops={')'})
            tk = sp.peek()
            if tk[0] == 'op' and tk[1] == ')':
                sp.take()
            self.i, self.line = sp.i, sp.line
            return [('sub', node, t[start:max(start, self.i - 1)])]
        if nx == '[':
            j = t.find(']', i)
            self.i = self.n if j < 0 else j + 1
            return [('arith', '')]
        if nx == '{':
            j = self._match(i + 1, '{', '}')
            inner = t[i + 2:j]
            self.line += inner.count('\n')
            self.i = j + 1
            m = re.match(r'(!|#)?([A-Za-z_][A-Za-z0-9_]*|[0-9]+|[@*#?$!-])', inner)
            if not m:
                return [('lit', '', q)]
            name = m.group(2)
            rest = inner[m.end():]
            out = []
            if name.isdigit():
                out.append(('pos', name, q))
            elif name in ('@', '*'):
                out.append(('pos', '@', q))
            elif re.match(r'[A-Za-z_]', name):
                out.append(('var', name, q))
            if '$' in rest or '`' in rest:
                sp = ShParser(rest + '"', 0, self.line)
                for p in sp._dquote():
                    if p[0] != 'lit':
                        out.append(p)
            return out
        if nx == "'":
            j = i + 2
            while j < self.n and t[j] != "'":
                j += 2 if t[j] == '\\' else 1
            self.i = j + 1
            return [('lit', t[i + 2:j], 1)]
        if nx == '"':
            self.i = i + 2
            return self._dquote()
        m = re.match(r'[A-Za-z_][A-Za-z0-9_]*', t[i + 1:i + 200])
        if m:
            self.i = i + 1 + m.end()
            return [('var', m.group(0), q)]
        if nx.isdigit():
            self.i = i + 2
            return [('pos', nx, q)]
        if nx in ('@', '*'):
            self.i = i + 2
            return [('pos', '@', q)]
        if nx and nx in '#?$!-':
            self.i = i + 2
            return [('lit', '$' + nx, q)]
        self.i = i + 1
        return [('lit', '$', q)]

    def _backtick(self):
        j = self.i + 1
        while j < self.n and self.t[j] != '`':
            j += 2 if self.t[j] == '\\' else 1
        raw = self.t[self.i + 1:j]
        inner = re.sub(r'\\([`$\\])', r'\1', raw)
        node = ShParser(inner, 0, self.line).parse_list()
        self.line += raw.count('\n')
        self.i = j + 1
        return ('sub', node, inner)

    # ── 문법 ──
    def _skip_nl(self):
        while self.peek()[0] == 'nl':
            self.take()

    def _kw(self, t):
        return t[1].bare() if t[0] == 'w' else None

    def parse_list(self, stops=frozenset(), stop_ops=frozenset()):
        items = []
        line = self.peek()[2]
        while True:
            t = self.peek()
            if t[0] == 'eof':
                break
            if t[0] == 'nl' or (t[0] == 'op' and t[1] in (';', '&')):
                self.take()
                continue
            if t[0] == 'op' and t[1] in stop_ops:
                break
            kw = self._kw(t)
            if kw is not None and kw in stops:
                break
            if t[0] == 'op' and t[1] in (')', ';;', ';&', ';;&'):
                if stop_ops:
                    break
                self.take()
                continue
            mark = (self.i, self.peeked)
            node = self.parse_andor(stops, stop_ops)
            if node is None:
                if (self.i, self.peeked) == mark:
                    self.take()
                continue
            items.append(node)
        return SN('list', line, items=items)

    def parse_andor(self, stops, stop_ops):
        first = self.parse_pipeline(stops, stop_ops)
        if first is None:
            return None
        items, ops = [first], []
        while True:
            t = self.peek()
            if t[0] == 'op' and t[1] in ('&&', '||'):
                self.take()
                self._skip_nl()
                nx = self.parse_pipeline(stops, stop_ops)
                if nx is None:
                    break
                ops.append(t[1])
                items.append(nx)
            else:
                break
        if not ops:
            return first
        return SN('andor', first.line, items=items, ops=ops)

    def parse_pipeline(self, stops, stop_ops):
        neg = False
        t = self.peek()
        while self._kw(t) in ('!', 'time'):
            if self._kw(t) == '!':
                neg = not neg
            self.take()
            t = self.peek()
        c = self.parse_command(stops, stop_ops)
        if c is None:
            return None
        items = [c]
        while self.peek()[0] == 'op' and self.peek()[1] in ('|', '|&'):
            self.take()
            self._skip_nl()
            c2 = self.parse_command(stops, stop_ops)
            if c2 is None:
                break
            items.append(c2)
        if len(items) == 1 and not neg:
            return c
        return SN('pipe', c.line, items=items, neg=neg)

    def _trailing_redirs(self, node):
        while self.peek()[0] == 'redir':
            op = self.take()
            tw = self.peek()
            if tw[0] != 'w':
                break
            self.take()
            self._add_redir(node, op, tw[1])
        return node

    def _add_redir(self, node, op, w):
        o = op[1]
        if o in ('<<', '<<-'):
            quoted = any(p[0] == 'lit' and p[2] for p in w.parts)
            delim = ''.join(p[1] for p in w.parts if p[0] == 'lit')
            self.pend.append((delim, o == '<<-', quoted, node))
        elif o == '<<<':
            node.herestr.append(w)
        else:
            node.redirs.append((o, op[3], w))

    def parse_command(self, stops, stop_ops):
        if self.peeked is None:
            self._blank()
            if self.t.startswith('((', self.i):
                line = self.line
                j = self._match(self.i, '(', ')')
                text = self.t[self.i + 2:max(self.i + 2, j - 1)]
                self.line += self.t[self.i:j].count('\n')
                self.i = j + 1
                return self._trailing_redirs(SN('arith', line, text=text))
        t = self.peek()
        if t[0] == 'op' and t[1] == '(':
            self.take()
            body = self.parse_list(stop_ops={')'})
            if self.peek()[0] == 'op' and self.peek()[1] == ')':
                self.take()
            return self._trailing_redirs(SN('group', t[2], body=body, sub=True))
        if t[0] == 'w':
            kw = t[1].bare()
            if kw == 'if':
                return self._trailing_redirs(self.parse_if())
            if kw in ('while', 'until'):
                self.take()
                cond = self.parse_list(stops={'do'})
                if self._kw(self.peek()) == 'do':
                    self.take()
                body = self.parse_list(stops={'done'})
                if self._kw(self.peek()) == 'done':
                    self.take()
                return self._trailing_redirs(SN('while', t[2], cond=cond, body=body, until=(kw == 'until')))
            if kw in ('for', 'select'):
                return self._trailing_redirs(self.parse_for())
            if kw == 'case':
                return self._trailing_redirs(self.parse_case())
            if kw == '{':
                self.take()
                body = self.parse_list(stops={'}'})
                if self._kw(self.peek()) == '}':
                    self.take()
                return self._trailing_redirs(SN('group', t[2], body=body, sub=False))
            if kw == 'function':
                self.take()
                nt = self.take()
                name = nt[1].text() if nt[0] == 'w' else '?'
                if self.peek()[0] == 'op' and self.peek()[1] == '(':
                    self.take()
                    if self.peek()[0] == 'op' and self.peek()[1] == ')':
                        self.take()
                self._skip_nl()
                body = self.parse_command(stops, stop_ops)
                return SN('func', t[2], name=name, body=body)
            if kw == '[[':
                self.take()
                words = [t[1]]
                while True:
                    x = self.take()
                    if x[0] in ('eof', 'nl'):
                        break
                    if x[0] == 'w':
                        words.append(x[1])
                        if x[1].bare() == ']]':
                            break
                    else:
                        words.append(ShW([('lit', x[1], 0)], x[2], x[1]))
                return self._trailing_redirs(SN('simple', t[2], words=words))
            if kw in ('then', 'do', 'done', 'fi', 'elif', 'else', 'esac', '}', ']]', 'in'):
                return None
        if t[0] == 'op':
            return None
        if t[0] in ('eof', 'nl'):
            return None
        return self.parse_simple()

    def parse_if(self):
        line = self.take()[2]
        node = SN('if', line, clauses=[], els=None)
        cond = self.parse_list(stops={'then'})
        if self._kw(self.peek()) == 'then':
            self.take()
        body = self.parse_list(stops={'elif', 'else', 'fi'})
        node.clauses.append((cond, body))
        while True:
            kw = self._kw(self.peek())
            if kw == 'elif':
                self.take()
                cond = self.parse_list(stops={'then'})
                if self._kw(self.peek()) == 'then':
                    self.take()
                body = self.parse_list(stops={'elif', 'else', 'fi'})
                node.clauses.append((cond, body))
            elif kw == 'else':
                self.take()
                node.els = self.parse_list(stops={'fi'})
            elif kw == 'fi':
                self.take()
                break
            else:
                break
        return node

    def parse_for(self):
        line = self.take()[2]
        self._blank()
        var, words = None, []
        if self.peeked is None and self.t.startswith('((', self.i):
            j = self._match(self.i, '(', ')')
            self.line += self.t[self.i:j].count('\n')
            self.i = j + 1
        else:
            vt = self.take()
            var = vt[1].text() if vt[0] == 'w' else None
            self._skip_nl()
            if self._kw(self.peek()) == 'in':
                self.take()
                while self.peek()[0] == 'w':
                    words.append(self.take()[1])
        while self.peek()[0] == 'nl' or (self.peek()[0] == 'op' and self.peek()[1] == ';'):
            self.take()
        if self._kw(self.peek()) == 'do':
            self.take()
        body = self.parse_list(stops={'done'})
        if self._kw(self.peek()) == 'done':
            self.take()
        return SN('for', line, var=var, iter=words, body=body)

    def parse_case(self):
        line = self.take()[2]
        wt = self.take()
        word = wt[1] if wt[0] == 'w' else ShW([], line)
        self._skip_nl()
        if self._kw(self.peek()) == 'in':
            self.take()
        arms = []
        while True:
            self._skip_nl()
            t = self.peek()
            if t[0] == 'eof':
                break
            if self._kw(t) == 'esac':
                self.take()
                break
            if t[0] == 'op' and t[1] == '(':
                self.take()
            pats = []
            while True:
                x = self.take()
                if x[0] == 'w':
                    pats.append(x[1])
                if self.peek()[0] == 'op' and self.peek()[1] == '|':
                    self.take()
                    continue
                if self.peek()[0] == 'op' and self.peek()[1] == ')':
                    self.take()
                break
            body = self.parse_list(stops={'esac'}, stop_ops={';;', ';&', ';;&'})
            if self.peek()[0] == 'op' and self.peek()[1] in (';;', ';&', ';;&'):
                self.take()
            arms.append((pats, body))
            if not pats and not body.items:
                if self.peek()[0] not in ('w', 'nl', 'op'):
                    break
        return SN('case', line, word=word, arms=arms)

    def parse_simple(self):
        node = SN('simple', self.peek()[2])
        while True:
            t = self.peek()
            if t[0] == 'redir':
                self.take()
                tw = self.peek()
                if tw[0] != 'w':
                    break
                self.take()
                self._add_redir(node, t, tw[1])
                continue
            if t[0] != 'w':
                break
            w = t[1]
            if not node.words:
                sp = split_assign(w)
                if sp is not None:
                    self.take()
                    node.assigns.append((sp[0], sp[1], w.line))
                    continue
            self.take()
            node.words.append(w)
            if len(node.words) == 1 and not node.assigns and self.peek()[0] == 'op' and self.peek()[1] == '(':
                self.take()
                if self.peek()[0] == 'op' and self.peek()[1] == ')':
                    self.take()
                    self._skip_nl()
                    body = self.parse_command(frozenset(), frozenset())
                    return SN('func', node.line, name=w.own_text(), body=body)
        return node


def split_assign(w):
    if not w.parts or w.parts[0][0] != 'lit' or w.parts[0][2] != 0:
        return None
    m = ASSIGN_RE.match(w.parts[0][1])
    if not m:
        return None
    rest = w.parts[0][1][m.end():]
    parts = ([('lit', rest, 0)] if rest else []) + list(w.parts[1:])
    return m.group(1), ShW(parts, w.line)


def sh_parse(text):
    p = ShParser(text)
    return p.parse_list()


def sh_iter(node):
    """구문 나무의 모든 마디(명령 치환 속 포함)."""
    if node is None:
        return
    yield node
    k = node.kind
    kids = []
    if k == 'list':
        kids = node.items
    elif k in ('andor', 'pipe'):
        kids = node.items
    elif k == 'if':
        for c, b in node.clauses:
            kids.extend([c, b])
        if node.els is not None:
            kids.append(node.els)
    elif k == 'while':
        kids = [node.cond, node.body]
    elif k == 'for':
        kids = [node.body]
    elif k == 'case':
        kids = [b for _, b in node.arms]
    elif k == 'group':
        kids = [node.body]
    elif k == 'func':
        kids = [node.body]
    for kid in kids:
        for x in sh_iter(kid):
            yield x
    if k == 'simple' or k in ('group', 'while', 'for', 'if', 'case', 'arith'):
        ws = list(getattr(node, 'words', []))
        ws.extend(v for _, v, _ in getattr(node, 'assigns', []))
        ws.extend(w for _, _, w in getattr(node, 'redirs', []))
        ws.extend(getattr(node, 'herestr', []))
        if k == 'for':
            ws.extend(node.iter)
        if k == 'case':
            ws.append(node.word)
        for w in ws:
            for s in w.subs():
                for x in sh_iter(s):
                    yield x




SH_WRAP = {'sudo', 'doas', 'env', 'nohup', 'nice', 'ionice', 'timeout', 'stdbuf', 'command', 'exec', 'builtin',
           'time', 'chronic', 'setsid', 'runuser'}
SH_WRAP_ARGOPT = {'-u', '-g', '-C', '-p', '-h', '-U', '-D', '-R', '-T', '-n', '-c', '-I', '-L', '-P', '-s', '-o',
                  '-i', '-E', '-k', '-w'}


def effective_words(words):
    k = 0
    while k < len(words):
        lit = words[k].lit()
        if lit is None:
            break
        base = os.path.basename(lit)
        if base not in SH_WRAP:
            break
        k += 1
        while k < len(words):
            l2 = words[k].lit()
            if l2 is None:
                break
            if base == 'flock' and not l2.startswith('-'):
                k += 1       # 잠금 파일
                break
            if l2.startswith('-') or ASSIGN_RE.match(l2) or re.fullmatch(r'[0-9.]+[smhd]?', l2):
                if l2 in SH_WRAP_ARGOPT and base in ('sudo', 'doas', 'env', 'nice', 'xargs', 'runuser', 'ionice'):
                    k += 1
                k += 1
                continue
            break
    return words[k:]




# ═══════════════════════════ 기록 표 사전 (db/**) ═══════════════════════════
RECORD_TOKENS = {'audit', 'audits', 'log', 'logs', 'event', 'events', 'history', 'histories', 'journal', 'journals',
                 'trail', 'trails'}
FAIL_TOKENS = {'fail', 'failed', 'failure', 'failures', 'fails', 'ng', 'bad', 'errors'}
ACCOUNT_TOKENS = {'actor', 'user', 'users', 'owner', 'by', 'uploader', 'author', 'account', 'accounts'}
CREATE_RE = re.compile(r'(?is)\bcreate\s+(?:unlogged\s+)?table\s+(?:if\s+not\s+exists\s+)?'
                       r'("?[a-z_][a-z0-9_]*"?(?:\."?[a-z_][a-z0-9_]*"?)?)\s*\(')
GRANT_RE = re.compile(r'(?is)\bgrant\s+([a-z ,]+?)\s+on\s+(?:table\s+)?(?!all\s|schema\s|sequence\s|database\s|function\s)'
                      r'([a-z0-9_.,"\s]+?)\s+to\s+')
COL_SKIP = {'primary', 'unique', 'constraint', 'check', 'foreign', 'exclude', 'like'}


def strip_sql_comments(text):
    text = re.sub(r'/\*.*?\*/', ' ', text, flags=re.S)
    return re.sub(r'--[^\n]*', '', text)


def split_top(body, sep=','):
    out, depth, cur, q = [], 0, [], False
    for ch in body:
        if ch == "'":
            q = not q
        elif not q and ch == '(':
            depth += 1
        elif not q and ch == ')':
            depth -= 1
        if ch == sep and depth == 0 and not q:
            out.append(''.join(cur))
            cur = []
            continue
        cur.append(ch)
    out.append(''.join(cur))
    return out


def load_schema(root):
    """db/**/*.sql → (기록 표 {이름: 정보}, 전체 표 수). 정보: cols(이름→형), acct(계정 열), why."""
    tables, privs = {}, {}
    dbdir = os.path.join(root, 'db')
    for d, dirs, files in os.walk(dbdir):
        dirs[:] = sorted(dirs)
        for f in sorted(files):
            if not f.endswith('.sql'):
                continue
            try:
                with open(os.path.join(d, f), 'rb') as fh:
                    text = strip_sql_comments(fh.read().decode('utf-8', 'replace'))
            except OSError:
                continue
            for m in CREATE_RE.finditer(text):
                name = m.group(1).replace('"', '').lower()
                i = m.end() - 1
                depth, j = 0, i
                while j < len(text):
                    if text[j] == '(':
                        depth += 1
                    elif text[j] == ')':
                        depth -= 1
                        if depth == 0:
                            break
                    j += 1
                cols, acct = {}, set()
                for item in split_top(text[i + 1:j]):
                    w = item.split()
                    if len(w) < 2 or w[0].lower() in COL_SKIP:
                        continue
                    col = w[0].replace('"', '').lower()
                    cols[col] = w[1].lower()
                    if re.search(r'(?i)\breferences\s+(?:public\.)?"?users"?\b', item):
                        acct.add(col)
                info = tables.setdefault(name, {'cols': {}, 'acct': set()})
                info['cols'].update(cols)
                info['acct'] |= acct
            for m in GRANT_RE.finditer(text):
                ps = {p.strip().upper() for p in m.group(1).split(',') if p.strip()}
                for t in m.group(2).split(','):
                    t = t.strip().replace('"', '').lower()
                    if t:
                        privs.setdefault(t, set()).update(ps)
    record = {}
    for name, info in tables.items():
        short = name.split('.')[-1]
        p = privs.get(name, set()) | privs.get(short, set())
        append_only = 'INSERT' in p and not (p & {'UPDATE', 'DELETE', 'TRUNCATE', 'ALL'})
        named = any(t in RECORD_TOKENS for t in name_tokens(short))
        if append_only or named:
            info['why'] = '추가 전용 권한' if append_only else '이름'
            record[name] = info
    return record, len(tables)


# ═══════════════════════════ SQL 조각 판정 ═══════════════════════════
FROM_RE = re.compile(r'(?is)\b(?:from|join)\s+(?:only\s+)?("?[a-z_][a-z0-9_]*"?(?:\."?[a-z_][a-z0-9_]*"?)?)')
SELECT_RE = re.compile(r'(?is)\bselect\b')
ORDER_LIMIT_RE = re.compile(r'(?is)\border\s+by\b[^;]*?\blimit\s+(?:\d+|\$|:)')
AGG_RE = re.compile(r'(?i)\b(count|max|min|exists)\s*\(')
ZERO_SQL_RE = re.compile(r'(?i)\bcount\s*\(\s*[^)]*\)\s*=\s*0\b|\bnot\s+exists\s*\(')
RUNVAL_RE = re.compile(r"(?i)\s*\(?\s*(?:'?\$\{?[A-Za-z_0-9@*]|'?\$\(|:['\"]?[a-z_]|now\s*\(\s*\)|current_timestamp|"
                       r"clock_timestamp|statement_timestamp|transaction_timestamp|localtimestamp|"
                       r"to_timestamp\s*\(\s*'?\$)")
EQ_RE = re.compile(r"(?i)(?:(?<![a-z0-9_<>!])(?:[a-z_][a-z0-9_]*\.)?([a-z_][a-z0-9_]*)|->>?\s*'([a-z_][a-z0-9_]*)'\s*\)?)"
                   r"\s*(=(?![=>])|\bin\s*\(|\blike\b|\bilike\b)\s*")
MARK_SQL_RE = re.compile(r"(?is)\s*select\s+(?:coalesce\s*\(\s*)?(?:max|min)\s*\(\s*(?:[a-z_][a-z0-9_]*\.)?"
                         r"([a-z_][a-z0-9_]*)\s*\)\s*(?:,\s*[^)]*\))?\s+from\b")
INTERP_RE = re.compile(r'^(?:php[0-9.]*|python[0-9.]*|node|nodejs|perl|ruby|bash|sh|dash|zsh|ksh|awk|gawk|jq)$')


def sql_strip_lits(sql):
    return re.sub(r"'(?:[^']|'')*'", "''", sql)


class SqlInfo(object):
    def __init__(self, text, record):
        self.text = text
        bare = sql_strip_lits(text)
        self.tables = []
        for m in FROM_RE.finditer(bare):
            n = m.group(1).replace('"', '').lower()
            for full in record:
                if n == full or n == full.split('.')[-1] or n.split('.')[-1] == full.split('.')[-1] and '.' not in n:
                    if full not in self.tables:
                        self.tables.append(full)
        self.shape = None
        if ORDER_LIMIT_RE.search(bare):
            self.shape = 'ORDER BY … LIMIT'
        else:
            m = AGG_RE.search(bare)
            if m:
                self.shape = m.group(1).lower() + '(…)'
        self.agg = bool(re.search(r'(?i)\b(count|max|min)\s*\(', bare))
        mk = MARK_SQL_RE.match(bare)
        self.mark_col = mk.group(1).lower() if mk else None
        self.count = bool(re.search(r'(?i)\bcount\s*\(', bare))
        self.zero = bool(ZERO_SQL_RE.search(bare))

    def from_offset(self, txt):
        """SQL 이 시작한 줄에서 기록 표를 부르는 FROM/JOIN 줄까지의 줄 수(보고 줄 = 그 줄)."""
        short = {t.split('.')[-1] for t in self.tables}
        for m in FROM_RE.finditer(txt):
            if m.group(1).replace('"', '').lower().split('.')[-1] in short:
                return txt[:m.start()].count('\n')
        return 0


def is_sql_word(txt):
    return bool(SELECT_RE.search(txt)) and bool(re.search(r'(?i)\bfrom\b', txt))


# ═══════════════════════════ 한 파일 분석 ═══════════════════════════
def node_words(nd):
    out = []
    for w in getattr(nd, 'words', []):
        out.append(('arg', w))
    for name, v, _ in getattr(nd, 'assigns', []):
        out.append(('assign', v, name))
    for _, _, w in getattr(nd, 'redirs', []):
        out.append(('redir', w))
    for w in getattr(nd, 'herestr', []):
        out.append(('herestr', w))
    if nd.kind == 'for':
        for w in nd.iter:
            out.append(('for', w))
    if nd.kind == 'case':
        out.append(('case', nd.word))
    return out


def kids(nd):
    k = nd.kind
    if k == 'list' or k in ('andor', 'pipe'):
        return list(nd.items)
    if k == 'if':
        out = []
        for c, b in nd.clauses:
            out.extend([c, b])
        if nd.els is not None:
            out.append(nd.els)
        return out
    if k == 'while':
        return [nd.cond, nd.body]
    if k == 'for':
        return [nd.body]
    if k == 'case':
        return [b for _, b in nd.arms]
    if k in ('group', 'func'):
        return [nd.body] if nd.body is not None else []
    return []


def link(nd, par):
    nd._par = par
    nd._sub = getattr(nd, '_sub', None)
    for kid in kids(nd):
        link(kid, nd)
    for item in node_words(nd):
        w = item[1]
        for s in w.subs():
            s._sub = (nd, w, item[0], item[2] if len(item) > 2 else None)
            link(s, None)


def all_nodes(nd):
    yield nd
    for kid in kids(nd):
        for x in all_nodes(kid):
            yield x
    for item in node_words(nd):
        for s in item[1].subs():
            for x in all_nodes(s):
                yield x


def cmd_name(nd):
    if nd is None or nd.kind != 'simple':
        return None
    ew = effective_words(nd.words)
    return ew[0].lit() if ew else None


class ShFile(object):
    def __init__(self, rel, text):
        self.rel = rel
        self.tree = ShParser(text).parse_list()
        link(self.tree, None)
        self.nodes = list(all_nodes(self.tree))
        self.funcs = {}
        for nd in self.nodes:
            if nd.kind == 'func':
                self.funcs[nd.name] = nd
        self.sourced = set()
        for nd in self.nodes:
            if nd.kind == 'simple' and nd.words and nd.words[0].lit() in ('.', 'source') and len(nd.words) > 1:
                self.sourced.add(os.path.basename(nd.words[1].text()))


def func_is_assert(fn):
    """몸에서 FAIL 류 셈을 올린다."""
    for nd in all_nodes(fn.body) if fn.body is not None else []:
        if nd.kind == 'simple':
            for name, v, _ in nd.assigns:
                if set(name_tokens(name)) & FAIL_TOKENS and (any(p[0] == 'arith' for p in v.parts) or name in v.vars()):
                    return True
            ew = effective_words(nd.words)
            if ew and ew[0].lit() == 'let' and any(set(name_tokens(re.split(r'[+=-]', w.own_text())[0])) & FAIL_TOKENS
                                                   for w in ew[1:]):
                return True
        if nd.kind == 'arith':
            m = re.match(r'\s*([A-Za-z_][A-Za-z0-9_]*)\s*(?:\+\+|\+=|=)', nd.text or '')
            m2 = re.match(r'\s*\+\+\s*([A-Za-z_][A-Za-z0-9_]*)', nd.text or '')
            nm = (m or m2).group(1) if (m or m2) else None
            if nm and set(name_tokens(nm)) & FAIL_TOKENS:
                return True
    return False


def func_calls(fn):
    out = set()
    for nd in all_nodes(fn.body) if fn.body is not None else []:
        c = cmd_name(nd)
        if c:
            out.add(c)
    return out


def func_is_setter(fn):
    """첫 인자에 이름을 받아 그 변수에 값을 넣는 도우미(printf -v · read -r "$1" · eval "$1=…" · declare -g)."""
    for nd in all_nodes(fn.body) if fn.body is not None else []:
        if nd.kind != 'simple':
            continue
        lits = [w.lit() for w in nd.words]
        if lits[:2] == ['printf', '-v'] or (lits and lits[0] in ('declare', 'typeset') and '-g' in lits):
            return True
        if lits and lits[0] == 'eval' and any(p[0] == 'pos' for w in nd.words[1:] for p in w.parts):
            return True
    return False


UNIQUE_SUB_RE = re.compile(r'\b(uuidgen|urandom|random/uuid|openssl\s+rand|mktemp)\b|\bdate\b[^)]*%[sSNT]')


class R19File(object):
    def __init__(self, sf, record, bcols, acct, helpers):
        self.sf = sf
        self.record = record
        self.bcols = bcols
        self.acct = acct
        self.asserts, self.setters, funcs = helpers
        self.funcs = dict(funcs)
        self.funcs.update(sf.funcs)
        # 변수 대입
        self.assigns = {}       # 이름 → [값 ShW|None(알 수 없는 값), …]
        self.assign_nodes = {}  # 이름 → [(마디, 값)]
        for nd in sf.nodes:
            if nd.kind == 'simple':
                pairs = [(n, v) for n, v, _ in nd.assigns] if not nd.words else []
                ew = effective_words(nd.words)
                c0 = ew[0].lit() if ew else None
                if c0 in ('local', 'declare', 'export', 'readonly', 'typeset'):
                    pairs.extend(sp for sp in (split_assign(w) for w in ew[1:]) if sp)
                    for w in ew[1:]:
                        lt = w.lit()
                        if lt and re.fullmatch(r'[A-Za-z_][A-Za-z0-9_]*', lt):
                            self.assigns.setdefault(lt, [])
                for n, v in pairs:
                    self.assigns.setdefault(n, []).append(v)
                    self.assign_nodes.setdefault(n, []).append((nd, v))
                if c0 == 'read':
                    for w in ew[1:]:
                        lt = w.lit()
                        if lt and re.fullmatch(r'[A-Za-z_][A-Za-z0-9_]*', lt):
                            self.assigns.setdefault(lt, []).append(None)
                if c0 in self.setters and len(ew) > 1 and ew[1].lit():
                    self.assigns.setdefault(ew[1].lit(), []).append(None)
                    self.assign_nodes.setdefault(ew[1].lit(), []).append((nd, None))
            if nd.kind == 'for' and nd.var:
                self.assigns.setdefault(nd.var, []).append(None)
        self._unique = {}

    # ── 변수 성질 ──
    def fresh(self, var):
        vals = self.assigns.get(var)
        if not vals:
            return False
        for v in vals:
            if v is None or v.lit() is None:
                return True
        return False

    def unique(self, var, depth=0):
        if var in ('RANDOM', 'SRANDOM', 'BASHPID'):
            return True
        if depth > 4 or var in self._unique:
            return self._unique.get(var, False)
        self._unique[var] = False
        res = False
        for v in self.assigns.get(var, []):
            if v is None:
                continue
            if any(p[0] == 'lit' and '$$' in p[1] for p in v.parts):
                res = True
            for p in v.parts:
                if p[0] == 'var' and p[1] != var and self.unique(p[1], depth + 1):
                    res = True
                if p[0] == 'sub' and UNIQUE_SUB_RE.search(p[2] or ''):
                    res = True
        self._unique[var] = res
        return res

    def bounded(self, sql):
        t = sql.text
        for m in re.finditer(r'(?i)(?<![a-z0-9_])(?:[a-z_][a-z0-9_]*\.)?([a-z_][a-z0-9_]*)\s*(>=|>)(?!=)', t):
            if m.group(1).lower() in self.bcols and RUNVAL_RE.match(t[m.end():m.end() + 80]):
                return True
        for m in re.finditer(r'(?i)(\S+)\s*(<=|<)\s*(?:[a-z_][a-z0-9_]*\.)?([a-z_][a-z0-9_]*)\b', t):
            if m.group(3).lower() in self.bcols and RUNVAL_RE.match(m.group(1)):
                return True
        for m in re.finditer(r'(?i)(?:[a-z_][a-z0-9_]*\.)?([a-z_][a-z0-9_]*)\s+between\s+', t):
            if m.group(1).lower() in self.bcols and RUNVAL_RE.match(t[m.end():m.end() + 80]):
                return True
        for m in EQ_RE.finditer(t):
            col = (m.group(1) or m.group(2) or '').lower()
            val = self._value_after(t, m.end(), m.group(3).lower().startswith('in'))
            vars_ = re.findall(r'\$\{([A-Za-z_][A-Za-z0-9_]*)\}', val)
            pos = re.search(r'\$[0-9@*]', val) is not None
            sub = '$(' in val
            toks = name_tokens(col)
            if toks and toks[-1] == 'id' and col not in self.acct and not (set(toks) & ACCOUNT_TOKENS):
                if pos or sub or any(self.fresh(v) for v in vars_):
                    return True
            if any(self.unique(v) for v in vars_):
                return True
        return False

    @staticmethod
    def _value_after(t, i, is_in):
        """비교 오른쪽 값 글자(따옴표 안 · IN 목록 · 낱말 하나)."""
        if is_in:
            depth, j = 1, i
            while j < len(t) and depth:
                if t[j] == '(':
                    depth += 1
                elif t[j] == ')':
                    depth -= 1
                j += 1
            return t[i:j]
        if i < len(t) and t[i] == "'":
            j = t.find("'", i + 1)
            return t[i:len(t) if j < 0 else j + 1]
        m = re.match(r"[^\s,)]+", t[i:])
        return m.group(0) if m else ""

    # ── 값의 흐름 ──
    def boundary_of(self, nd):
        """nd 의 표준 출력이 가는 곳: ('sub', 주인 마디, 단어, 역할, 대입 이름) · ('func', 함수) · ('top',)."""
        n = nd
        while n is not None:
            if n.kind == 'list' and n._sub is not None:
                return ('sub',) + tuple(n._sub)
            if n.kind == 'func':
                return ('func', n)
            n = n._par
        return ('top',)

    def callsites(self, fname):
        return [nd for nd in self.sf.nodes if nd.kind == 'simple' and cmd_name(nd) == fname]

    def uses(self, nd, depth=0, via=None):
        """nd 의 출력이 닿는 단언들 [(단언 마디, 값이 든 단어, 경유)]."""
        if depth > 4:
            return []
        c0 = cmd_name(nd)
        if c0 in self.setters:
            ew = effective_words(nd.words)
            name = ew[1].lit() if len(ew) > 1 else None
            if name and re.fullmatch(r'[A-Za-z_][A-Za-z0-9_]*', name):
                return self.var_uses(name, depth, (via or []) + [name], nd.line)
        b = self.boundary_of(nd)
        if b[0] == 'func':
            out = []
            for cs in self.callsites(b[1].name):
                out.extend(self.uses(cs, depth + 1, (via or []) + ['%s()' % b[1].name]))
            return out
        if b[0] == 'top':
            return []
        _, owner, word, role, aname = b
        if role == 'assign':
            if owner.words:
                ew = effective_words(owner.words)
                if not (ew and ew[0].lit() in ('local', 'declare', 'export', 'readonly', 'typeset')):
                    return []
            if via and any(not v.endswith('()') for v in via):
                return []       # 변수 → 변수 는 한 단계까지
            return self.var_uses(aname, depth, (via or []) + [aname], owner.line)
        if role != 'arg':
            return []
        c = cmd_name(owner)
        if c in ('local', 'declare', 'export', 'readonly', 'typeset'):
            sp = split_assign(word)
            if not sp or (via and any(not v.endswith('()') for v in via)):
                return []
            return self.var_uses(sp[0], depth, (via or []) + [sp[0]], owner.line)
        if c in ('[', 'test', '[[') or c in self.asserts:
            return [(owner, word, via or [])]
        if c in ('echo', 'printf'):
            return self.uses(owner, depth + 1, via)
        return []

    def var_uses(self, var, depth, via, from_line=0):
        out = []
        later = sorted(n.line for n, _ in self.assign_nodes.get(var, []) if n.line > from_line)
        until = later[0] if later else 10 ** 9
        for nd in self.sf.nodes:
            if nd.kind != 'simple':
                continue
            if not (from_line <= nd.line < until) and from_line:
                continue
            c = cmd_name(nd)
            if not (c in ('[', 'test', '[[') or c in self.asserts or c in ('echo', 'printf')):
                continue
            for w in nd.words:
                if var in w.vars():
                    if c in ('echo', 'printf'):
                        if self.boundary_of(nd)[0] in ('sub', 'func'):
                            out.extend(self.uses(nd, depth + 1, via))
                    else:
                        out.append((nd, w, via))
                    break
        return out

    def is_delta_partner(self, w, tables):
        """단어가 같은 기록 표를 먼저(또는 같이) 잰 값인가 — 전후 대조."""
        names = set(w.vars())
        for p in w.parts:
            if p[0] == 'arith':
                names |= set(re.findall(r'[A-Za-z_][A-Za-z0-9_]*', p[1] or ''))
        for n in names:
            for nd, v in self.assign_nodes.get(n, []):
                if self._reads_table(nd, v, tables):
                    return True
        for s in w.subs():
            for nd in all_nodes(s):
                for item in node_words(nd):
                    txt = item[1].own_text()
                    if is_sql_word(txt):
                        si = SqlInfo(txt, self.record)
                        if set(si.tables) & set(tables) and si.agg:
                            return True
                c = cmd_name(nd)
                if c in self.funcs and self._func_reads(self.funcs[c], tables):
                    return True
        return False

    def _reads_table(self, nd, v, tables):
        words = [v] if v is not None else list(nd.words)
        for w in words:
            if is_sql_word(w.own_text()):
                si = SqlInfo(w.own_text(), self.record)
                if set(si.tables) & set(tables) and si.agg:
                    return True
            for s in w.subs():
                for x in all_nodes(s):
                    for item in node_words(x):
                        if is_sql_word(item[1].own_text()):
                            si = SqlInfo(item[1].own_text(), self.record)
                            if set(si.tables) & set(tables) and si.agg:
                                return True
                    c = cmd_name(x)
                    if c in self.funcs and self._func_reads(self.funcs[c], tables):
                        return True
        return False

    def _func_reads(self, fn, tables):
        for x in all_nodes(fn.body) if fn.body is not None else []:
            for item in node_words(x):
                if is_sql_word(item[1].own_text()):
                    si = SqlInfo(item[1].own_text(), self.record)
                    if set(si.tables) & set(tables) and si.agg:
                        return True
        return False

    def emptiness_only(self, anode, word, sql):
        ws = effective_words(anode.words)
        c = ws[0].lit() if ws else None
        if c not in ('[', 'test', '[['):
            return False
        lits = [w.lit() for w in ws[1:]]
        return sql.agg and not ORDER_LIMIT_RE.search(sql_strip_lits(sql.text)) and \
            any(x in ('-z', '-n') for x in lits) and len([x for x in lits if x not in (']', ']]', '!')]) <= 2

    def zero_expect(self, anode, word, sql):
        """전역 불변식: count 를 0 과 견준다."""
        if sql.zero:
            return True
        if not sql.count:
            return False
        ws = effective_words(anode.words)
        c = ws[0].lit() if ws else None
        others = [w for w in ws[1:] if w is not word]
        if c in ('[', 'test', '[['):
            lits = [w.lit() for w in others]
            return '0' in lits and any(x in lits for x in ('-eq', '=', '==', '-le', '-lt'))
        return any(w.lit() == '0' for w in others)

    # ── 조각 모으기 ──
    def occurrences(self):
        """[(줄, SqlInfo, 결과가 흘러나가는 마디)]"""
        occ = []
        sqlvars = {}
        for nd in self.sf.nodes:
            if nd.kind != 'simple':
                continue
            c = cmd_name(nd)
            if c is not None and INTERP_RE.match(os.path.basename(c)):
                continue
            items = [(w.line, w.own_text(), w) for _, w in [(i[0], i[1]) for i in node_words(nd)] if w is not None]
            for body, line0, _q in nd.heredocs:
                items.append((line0, body, None))
            for line, txt, w in items:
                if not is_sql_word(txt):
                    continue
                si = SqlInfo(txt, self.record)
                if not si.tables:
                    continue
                line = line + si.from_offset(txt)
                if not nd.words and w is not None and any(v is w for _, v, _ in nd.assigns) and \
                        not w.subs():
                    # Q="SELECT …" — SQL 을 변수에 담는다: 그 변수를 인자로 넘기는 명령에서 따라간다
                    for n, v, _ in nd.assigns:
                        if v is w:
                            sqlvars.setdefault(n, []).append((line, si))
                    continue
                occ.append((line, si, nd))
        if sqlvars:
            for nd in self.sf.nodes:
                if nd.kind != 'simple' or not nd.words:
                    continue
                for w in nd.words[1:]:
                    v = w.single_var()
                    if v in sqlvars:
                        for line, si in sqlvars[v]:
                            occ.append((line, si, nd))
        return occ

    def run(self):
        found, checked, skipped, notes = [], 0, 0, []
        seen = set()
        for line, si, nd in self.occurrences():
            if si.shape is None:
                continue
            if si.mark_col is not None and si.mark_col in self.bcols:
                continue        # 경계 변수를 재는 줄(MARK=$(… max(id) …)) — 단언이 아니다
            key = (line, id(nd))
            if key in seen:
                continue
            seen.add(key)
            uses = self.uses(nd)
            if not uses:
                continue
            checked += 1
            if self.bounded(si):
                continue
            real = []
            zero = 0
            for anode, word, via in uses:
                ws = effective_words(anode.words)
                others = [w for w in ws[1:] if w is not word]
                if any(self.is_delta_partner(w, si.tables) for w in others):
                    continue
                if self.emptiness_only(anode, word, si):
                    continue
                if self.zero_expect(anode, word, si):
                    zero += 1
                    continue
                real.append((anode, via))
            if not real:
                if zero:
                    skipped += 1
                    notes.append('SKIP R19 %s:%d 전역 불변식(0 과 견줌) — 판단 보류' % (self.sf.rel, line))
                continue
            found.append((line, si, real))
        return found, checked, skipped, notes


def build_helpers(files):
    """단언 도우미 · 변수 넣기 도우미 · 함수 사전 — 파일 + source 한 파일(끝 조각 이름으로)."""
    by_base = {}
    for rel, sf in files.items():
        by_base.setdefault(os.path.basename(rel), []).append(sf)
    out = {}
    for rel, sf in files.items():
        funcs = {}
        for b in sf.sourced:
            for o in by_base.get(b, []):
                if o is not sf:
                    for n, f in o.funcs.items():
                        funcs.setdefault(n, f)
        funcs.update(sf.funcs)
        asserts = {n for n, f in funcs.items() if func_is_assert(f)}
        changed = True
        while changed:
            changed = False
            for n, f in funcs.items():
                if n not in asserts and func_calls(f) & asserts:
                    asserts.add(n)
                    changed = True
        setters = {n for n, f in funcs.items() if func_is_setter(f)}
        out[rel] = (asserts, setters, funcs)
    return out


def file_lang(rel, head):
    low = rel.lower()
    if low.endswith(('.sh', '.bash')):
        return 'sh'
    first = head.split('\n', 1)[0]
    if first.startswith('#!') and re.search(r'\b(ba|da|k|z)?sh\b', first) and 'python' not in first and \
            'php' not in first:
        return 'sh'
    return None


def list_files(root, use_git):
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


def repo_top(root):
    try:
        r = subprocess.run(['git', '--no-optional-locks', '-C', root, 'rev-parse', '--show-toplevel'], stdout=subprocess.PIPE,
                           stderr=subprocess.DEVNULL, timeout=60)
    except (OSError, subprocess.SubprocessError):
        return None
    if r.returncode != 0:
        return None
    return os.path.realpath(r.stdout.decode('utf-8', 'replace').strip())


def main(argv=None):
    here = os.path.dirname(os.path.abspath(__file__))
    ap = argparse.ArgumentParser(description='R19 감사 행을 실행 경계 없이 읽는 단언(읽기만)')
    ap.add_argument('--root', default=os.path.normpath(os.path.join(here, '..', '..')),
                    help='검사할 트리(기본: 이 파일 기준 저장소 뿌리)')
    ap.add_argument('--dirs', default=','.join(DEFAULT_DIRS), help='뿌리 아래 볼 디렉터리(쉼표, 기본 tests)')
    ap.add_argument('--exclude', action='append', default=None,
                    help='뺄 경로 glob(여러 번). 주면 기본값(tests/static/fixtures/*)을 대신한다')
    ap.add_argument('--walk', action='store_true', help='git 목록 대신 디렉터리 전체를 본다')
    ap.add_argument('--verbose', action='store_true', help='기록 표 사전·판단 보류를 NOTE·SKIP 줄로')
    args = ap.parse_args(argv)

    root = os.path.realpath(args.root)
    if not os.path.isdir(root):
        sys.stderr.write('R19: 뿌리가 디렉터리가 아님: %s\n' % args.root)
        print('RESULT pass=0 fail=0 skip=1')
        return 2
    record, ntables = load_schema(root)
    if ntables == 0:
        sys.stderr.write('R19: db/** 에 CREATE TABLE 이 없다 — 기록 표 사전을 만들 수 없음(검사 못 함)\n')
        print('RESULT pass=0 fail=0 skip=1')
        return 2
    bcols = {'id'}
    acct = set()
    for name, info in record.items():
        for col, typ in info['cols'].items():
            if re.match(r'(big|small)?serial|timestamp|timestamptz|date$|time', typ) or 'identity' in typ:
                bcols.add(col)
        acct |= info['acct']
    if args.verbose:
        print('NOTE R19 기록 표 %d개(전체 %d): %s · 경계 열: %s' % (
            len(record), ntables, ' '.join('%s(%s)' % (n, i['why']) for n, i in sorted(record.items())),
            ' '.join(sorted(bcols))))
    excludes = DEFAULT_EXCLUDES if args.exclude is None else args.exclude
    dirs = [d.strip('/ ') for d in args.dirs.split(',') if d.strip('/ ')]
    try:
        use_git = (not args.walk) and repo_top(root) == root
        rels = list_files(root, use_git)
    except (RuntimeError, OSError, subprocess.SubprocessError) as e:
        sys.stderr.write('R19: 대상 목록을 만들지 못함: %s\n' % e)
        print('RESULT pass=0 fail=0 skip=1')
        return 2

    skipped = 0
    files = {}
    for rel in rels:
        if not any(rel == d or rel.startswith(d + '/') for d in dirs):
            continue
        if any(fnmatch.fnmatch(rel, g) for g in excludes):
            continue
        full = os.path.join(root, rel)
        if os.path.islink(full) or not os.path.isfile(full):
            continue
        try:
            with open(full, 'rb') as fh:
                text = fh.read().decode('utf-8', 'replace').lstrip(chr(0xFEFF))
        except OSError as e:
            sys.stderr.write('R19: 읽지 못함: %s (%s)\n' % (rel, e.strerror))
            skipped += 1
            continue
        if file_lang(rel, text[:200]) != 'sh':
            continue
        try:
            files[rel] = ShFile(rel, text)
        except (RecursionError, IndexError, ValueError, KeyError, AttributeError) as e:
            sys.stderr.write('R19: 구문 분석 실패로 건너뜀: %s (%s)\n' % (rel, e.__class__.__name__))
            skipped += 1
    helpers = build_helpers(files)

    findings, checked = [], 0
    for rel in sorted(files):
        try:
            rf = R19File(files[rel], record, bcols, acct, helpers[rel])
            found, n, sk, notes = rf.run()
        except (RecursionError, IndexError, ValueError, KeyError, AttributeError, TypeError) as e:
            sys.stderr.write('R19: 분석 실패로 건너뜀: %s (%s)\n' % (rel, e.__class__.__name__))
            skipped += 1
            continue
        checked += n
        skipped += sk
        if args.verbose:
            for x in notes:
                print(x)
        for line, si, real in found:
            anode, via = real[0]
            lines = sorted(set(a.line for a, _ in real))
            how = '%s 단언 %s행' % (cmd_name(anode) or '?', '·'.join(str(x) for x in lines[:4]))
            if via:
                how = '%s → %s' % (' → '.join(via), how)
            tbl = ', '.join(t.split('.')[-1] for t in si.tables[:2])
            findings.append((rel, line, '시험 단언이 기록 표 %s 를 %s 로 읽는데 이번 실행의 경계(id > 표시 · 시각 > 시작 · '
                                        '이번에 만든 개체)가 없다 — 옛 행으로 통과할 수 있다 (%s)' % (tbl, si.shape, how)))
    uniq = {}
    for f in findings:
        uniq.setdefault((f[0], f[1]), f)
    out = sorted(uniq.values())
    for rel, line, msg in out:
        print('FINDING %s 중간 %s:%d %s' % (RULE, rel, line, msg))
    print('RESULT pass=%d fail=%d skip=%d' % (max(0, checked - len(out)), len(out), skipped))
    return 1 if out else 0


if __name__ == '__main__':
    try:
        sys.exit(main())
    except KeyboardInterrupt:
        sys.exit(2)
    except Exception as ex:     # 실행 오류 = 검사 못 함(H-1)
        sys.stderr.write('R19: 실행 오류: %s: %s\n' % (ex.__class__.__name__, ex))
        print('RESULT pass=0 fail=0 skip=1')
        sys.exit(2)
