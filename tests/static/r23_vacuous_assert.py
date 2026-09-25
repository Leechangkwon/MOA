#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
R23 늘 참인 단언 (vacuous assertion) — 정적 규칙, python3 stdlib 만, 파일은 읽기만.

answers: 1l 봇 검토 낮음(tests/upgrade_smoke.sh — 결함 판 317168e 는 머리 확인 curl 이 자산 기록에 줄을 남겨 「N-1 자산
         1건 이상」이 저절로 참 · 고친 판 7e63a0c 는 확인 뒤 기록을 비움 → 조용해야) · HO-U2B-001(stage26 T-53(d) —
         「내 휴지통」 글자가 공통 셸 내비에 있어 늘 참 · 결함 판 없음 → 견본 · 고친 판 75f57a9 는 조용해야) ·
         HO-7D1-009(stage7d1 준비 성공 미확인 → 빈 번호로 「목록에 없음」이 공짜 통과 · 팀 공간 단언이 말없이 빠짐 —
         결함 판 fa5dc78(= 72344d0 같은 blob) · 고친 판 68dbdfd) · HO-C-005(반영 스크립트 보안 헤더 해시 비교가 빈 값끼리
         같음 — 결함 판 git 밖 → 견본 · 고친 판 qaredi 0dd2240 은 조용해야) · HO-C-004(판단 — ⑤ 는 규칙으로 만들지 않음) ·
         v4: FP-0007(tests/qf4_p7_timeouts.sh 9a6d08c:380 · 31c785d:400 — 탐침 응답에서 뽑은 P0, 그 응답을 긍정 단언으로
         확인 → 조용해야) · FP-0008(숫자 정규식으로 거른 준비 값의 같음 비교 — 개발 판은 저장소 밖 → 견본으로 조용 ·
         31c785d qf4b1.sh:618 = BF-0012 는 확인이 없어 여전히 울려야)

무엇을 부류로 잡나:
  단언(expect·say 같은 같음 비교 도우미 · `[ … ]`)의 한쪽이 같은 셸의 앞선 동작 때문에 이미 정해진 값이 되어
  시험 대상이 어떻든 통과하는 모양. 같음 비교 도우미는 코드에서 찾는다(몸에 `[ "$i" = "$j" ]` 처럼 위치 인자 둘을
  같음 비교하는 함수 — 같은 파일과 . · source 로 읽는 파일).
    ① 기록 오염 — 뒤로 돌린 프로세스(`… &`)에 환경 대입·인자로 기록 파일 F 를 넘기고(요청 기록기), F 를 비운
      뒤(`: > F`) 이 셸이 스스로 요청(curl·wget — 함수 안이면 그 함수를 부른 차례로 펼쳐 본다)을 보낸 다음
      F 의 줄 수(`wc -l`·`grep -c`)를 「1건 이상」(`-ge 1`·`-gt 0`·`-ne 0`)으로 단언한다. 요청 뒤에 F 를 다시
      비우면 조용.
    ② 공통 셸 글자 — 화면 응답(GET · 경로에 /api/·/assets/·파일 확장자 없음 — curl·wget 이나 그것을 부르는 도우미의
      결과)에서 찾는 고정 글자(grep 의 바늘 · `[[ $R == *글자* ]]`)가 **모든 화면에 실리는 공통 셸**에 있다.
      공통 셸 = src 의 PHP 중 세 파일 이상이 require·include 하고 HTML 을 가진 파일(코드에서 찾음 — 이름을 적지 않음).
      없어야 한다는 단언(grep -v · `!` · 기대 "0"/"no")은 뺀다.
    ③ 빈 준비 값 — `V=$(…)`(만들기·조회) 로 받은 값을 확인하지 않고(`[ -n "$V" ] || …`·`[ -z "$V" ] && …`·`${V:?}`·
      이름 목록을 돌며 `${!v}` 를 보는 확인·V 를 쓰는 긍정 단언이 없음) 부정 단언(기대 "no"·"0"·""·"false"·"none")의
      대상 인자로 넘긴다 — 만들기가 실패해 V 가 비면 「없음」이 공짜로 통과. ③′ `[ -n "$V" ] && 부정 단언`(또는 else 없는
      `if [ -n "$V" ]`)으로 V 가 비면 「없음」 단언이 **말없이 빠지는** 모양도 같은 뿌리로 잡는다(긍정 확인이 빠지는 것은 뺀다).
      출처가 확인된 값(v4): V=$(도우미 "$SRC" …) 처럼 V 를 SRC 에서 **뽑았고**(치환 안이 전부 요청·sudo 류 감싸기·쓰는 말·
      파일 리다이렉트 없는 글 다루기 도구 또는 그런 도우미) SRC(또는 같은 SRC 에서 뽑은 다른 값)가 긍정 단언으로 확인되면
      V 도 확인된 것으로 친다(한 단계). 확인된 SRC 로 **만든** 값(curl POST 도우미)은 뽑기가 아니다.
    ④ 빈 값끼리 같음 — 같음 비교 도우미(또는 그 안의 `[ A = B ]`·`||` 로 실패하는 `[ A = B ]`)의 두 쪽이 모두 명령
      치환에서 오고(직접 `$(…)` 이거나 `$(…)` 만 대입받은 변수 — 해시·줄 수는 입력이 비어도 같은 값) 어느 쪽에도
      빈 값 확인이 없다. 한쪽이라도 확인(위 ③ 의 확인 · `${V:-대체}` · 대체 값을 내는 도우미 `printf '%s' "${v:-(none)}"` ·
      확인된 값을 해시한 값)이 있으면 조용.
      모양 확인(v4 — ③·④ 공통): `[[ "$V" =~ 정규식 ]]` 의 정규식이 빈 글자에 맞지 않고 갈래를 끝내면(`|| exit`·단언 안·
      else · `!` 로 뒤집음) 확인이다. case "$V" 에서 빈 값이 걸리는 첫 갈래(''·*)가 exit·return·continue·break 하면 확인이다
      (무늬는 풀이기가 버리므로 파일 글에서 읽는다 — 따옴표가 줄을 넘거나 case 가 겹치면 보지 않음).
    ⑤ 판정에 쓸모없는 탐침(짧은 401 응답으로 「압축 안 됨」 확인 · HO-C-004)은 판단이 필요해 규칙으로 만들지 않았다.
  심각도: ① 중간 · ② 중간 · ③ 낮음 · ④ 낮음.

대상: --root 아래 --dirs(기본 tests·scripts·review-scripts)의 셸 + --outer 를 주면 <outer>/scripts/*.sh
      (출력 경로 `../scripts/<이름>`). 기본으로 tests/static/fixtures/* 는 뺀다. 줄(또는 바로 윗줄)에
      `qcheck-allow: R23` 이면 뺀다.
출력: FINDING R23 <심각도> <파일:줄> <설명> · 끝 줄 RESULT pass= fail= skip=
rc:   0 발견 없음 · 1 발견 있음 · 2 실행 오류
한계: 실행 차례는 글 차례 + 부른 함수 펼치기(깊이 4)로만 본다(반복·갈래는 무시). ② 는 공통 셸 PHP 의 **글자**와
      대조한다(PHP 가 만드는 글은 모른다). ④ 의 「확인」은 같은 파일 안 이름으로만 본다.
"""
import argparse
import fnmatch
import os
import re
import subprocess
import sys

RULE = 'R23'


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

# ─── R23 ─────────────────────────────────────────────────────────────────────────────────────
NEG_LIT = {'no', '0', '', 'false', 'none', '(none)', 'absent', 'missing', 'n', '없음'}
REQ_CMDS = {'curl', 'wget'}
TEST_CMDS = ('[', '[[', 'test')
CURL_VAL = set('oDbcHAweuXdFTmrKEyCtzQ')
CURL_NOVAL = {'--silent', '--insecure', '--compressed', '--location', '--fail', '--head', '--show-error', '--globoff',
              '--http1.1', '--http2', '--include', '--verbose', '--no-buffer', '--ipv4', '--ipv6', '--fail-with-body',
              '--no-progress-meter', '--quiet'}
COUNT_OPS = {('-ge', '1'), ('-gt', '0'), ('-ne', '0'), ('!=', '0')}


def eff_name(c):
    ws = sh_effective(c.words)
    return os.path.basename(ws[0].lit() or '') if ws else None


def nonempty_parts(w):
    """빈 글자와 ${X…} 나머지 안에서 나온 조각을 뺀 조각들."""
    return [p for p in w.parts if not (p.k == 'lit' and p.v == '') and p.nest is None]


def whole_var(w):
    ps = nonempty_parts(w)
    if len(ps) == 1 and ps[0].k == 'var' and (not ps[0].op or ps[0].op.startswith('[')):
        return ps[0].v
    return None


def has_default(w):
    for p in w.parts:
        if p.k in ('var', 'pos') and p.nest is None and p.op[:2] in (':-', ':=') and len(p.op) > 2:
            return True
    return False


def word_subs(w):
    return [p.sub for p in w.parts if p.k == 'sub' and p.sub is not None and p.nest is None]


# ── 모양 확인(v4 — FP-0008): 정규식 · case 로 빈 값을 거르는 확인 ──
POSIX_CLASS = {'digit': '0-9', 'alpha': 'A-Za-z', 'alnum': 'A-Za-z0-9', 'xdigit': '0-9A-Fa-f', 'upper': 'A-Z',
               'lower': 'a-z', 'space': r'\s', 'blank': r' \t', 'word': r'\w', 'print': r' -~', 'graph': r'!-~',
               'punct': r'!-/:-@\[-`{-~'}
CASE_TERM_RE = re.compile(r'(?:^|[\s;&|{(])(?:exit|return|continue|break)(?![A-Za-z0-9_-])')


def regex_needs_char(ctx, ws):
    """`[[ V =~ … ]]` 오른쪽 단어들 → 빈 글자에 맞지 않는 정규식이면 True(모르면 False). 따옴표 안은 글자 그대로
    (bash 규칙), 따옴표 없는 변수는 그 파일에서 한 번 글자로 대입된 값이면 정규식으로 읽는다."""
    out = []
    for w in ws:
        if w.lit() in (']]', '&&', '||'):
            break
        for p in w.parts:
            if p.k == 'lit':
                out.append(re.escape(p.v) if p.q in ('s', 'd', 'e') else p.v)
            elif p.k == 'var' and not p.op:
                vals = ctx.assigns.get(p.v, [])
                if len(vals) != 1 or not all(x.k == 'lit' for x in vals[0][0]):
                    return False
                txt = ''.join(x.v for x in vals[0][0])
                out.append(re.escape(txt) if p.q == 'd' else txt)
            else:
                return False
    pat = ''.join(out)
    if not pat:
        return False
    pat = re.sub(r'\[:([a-z]+):\]', lambda m: POSIX_CLASS.get(m.group(1), '\x00'), pat)
    if '\x00' in pat:
        return False
    try:
        return re.search(pat, '') is None
    except (re.error, RecursionError, OverflowError):
        return False


def pattern_matches_empty(pat):
    """case 무늬 하나가 빈 글자에 맞나 — 빈 따옴표('' · "")를 뺀 나머지가 따옴표 없는 * 뿐이면."""
    return re.fullmatch(r'\**', re.sub(r"''|\"\"", '', pat.strip())) is not None


def case_arms(text, line):
    """줄 line 의 `case … in` 부터 esac 까지 갈래들 [(무늬 목록, 몸 글)] — 풀이기는 무늬를 버리므로 글에서 읽는다.
    따옴표가 줄을 넘거나 몸 안에 case 가 또 있으면 None(풀지 않음)."""
    t = '\n'.join(text.split('\n')[line - 1:])
    m = re.search(r'\bcase\s+("[^"\n]*"|\'[^\'\n]*\'|\S+)\s+in\b', t)
    if not m:
        return None
    n = len(t)

    def skip_quoted(j):
        k = t.find(t[j], j + 1)
        return -1 if k < 0 else k + 1

    def word_at(j, w):
        return t.startswith(w, j) and (j == 0 or not (t[j - 1].isalnum() or t[j - 1] == '_')) and \
            not (t[j + len(w):j + len(w) + 1].isalnum() or t[j + len(w):j + len(w) + 1] == '_')

    i = m.end()
    arms = []
    while i < n:
        i += re.match(r'(?:\s+|#[^\n]*)*', t[i:]).end()
        if i >= n:
            return None
        if word_at(i, 'esac'):
            return arms
        if t[i] == '(':
            i += 1
        j = i
        while j < n and t[j] != ')':
            if t[j] == '\n':
                return None
            if t[j] == '\\':
                j += 2
                continue
            if t[j] in '\'"':
                j = skip_quoted(j)
                if j < 0:
                    return None
                continue
            j += 1
        if j >= n:
            return None
        pats, cur, q = [], '', None
        for ch in t[i:j]:
            if q:
                cur += ch
                if ch == q:
                    q = None
            elif ch in '\'"':
                q = ch
                cur += ch
            elif ch == '|':
                pats.append(cur)
                cur = ''
            else:
                cur += ch
        pats.append(cur)
        i = j + 1
        j = i
        while j < n:
            if t[j] == '\\':
                j += 2
                continue
            if t[j] in '\'"':
                j = skip_quoted(j)
                if j < 0:
                    return None
                continue
            if t[j] == '#' and (j == 0 or t[j - 1] in ' \t\n;'):
                k = t.find('\n', j)
                j = n if k < 0 else k
                continue
            if t.startswith(';;', j) or t.startswith(';&', j) or word_at(j, 'esac'):
                break
            if word_at(j, 'case'):
                return None
            j += 1
        arms.append((pats, t[i:j]))
        if t.startswith(';;&', j):
            i = j + 3
        elif t.startswith(';;', j) or t.startswith(';&', j):
            i = j + 2
        else:
            i = j
    return None


class FileCtx(object):
    def __init__(self, sf, libs):
        self.sf = sf
        self.funcs = {}
        for lib in reversed(libs):
            for n, cs in lib.funcs.items():
                self.funcs[n] = cs
        for n, cs in sf.funcs.items():
            self.funcs[n] = cs
        self.children = {}
        for c in sf.cmds:
            if c.parent is not None:
                self.children.setdefault(id(c.parent), []).append(c)
        self.nxt = {}
        for c in sf.cmds:
            if c.prev is not None:
                self.nxt[id(c.prev[0])] = c
        self.eq = self._eq_helpers()
        self.req = self._req_funcs()
        self.nonempty = self._nonempty_funcs()
        self.assigns = {}
        for c in sf.cmds:
            ws = []
            if not c.words:
                ws = c.assigns
            elif c.words[0].lit() in ('local', 'declare', 'typeset', 'readonly', 'export'):
                ws = [w for w in c.words[1:] if SH_ASSIGN_RE.match(w.raw)]
            for w in ws:
                if re.match(r'^[A-Za-z_][A-Za-z0-9_]*(\[[^\]]*\])?\+?=\(', w.raw):
                    continue     # 배열 글자 대입 X=( … ) — 값 판정에서 뺀다
                name, parts = sh_assign_parts(w)
                if name:
                    self.assigns.setdefault(name, []).append((parts, c))
        self._guard_cache = {}
        # for 반복 변수 별칭: for p in … "$V" … → p 의 확인·긍정 단언은 V 의 것으로도 본다
        self.loop_alias = {}     # V → {p}
        for c in sf.cmds:
            if c.kind == 'header' and len(c.words) >= 3 and c.words[0].lit() == 'for' and c.words[2].lit() == 'in':
                lv = c.words[1].lit()
                if not lv:
                    continue
                for w in c.words[3:]:
                    for p in nonempty_parts(w):
                        if p.k == 'var' and not p.op[:1] in ('#', '%', '/'):
                            self.loop_alias.setdefault(p.v, set()).add(lv)

    # ── 도우미 찾기 ──
    def _eq_helpers(self):
        out = {}
        for fname, cmds in self.funcs.items():
            alias = {}
            for c in cmds:
                ws = c.assigns if not c.words else (c.words[1:] if c.words[0].lit() in ('local', 'declare') else [])
                for w in ws:
                    name, parts = sh_assign_parts(w)
                    ps = [p for p in parts if not (p.k == 'lit' and p.v == '')]
                    if name and len(ps) == 1 and ps[0].k == 'pos' and isinstance(ps[0].v, int):
                        alias[name] = ps[0].v
            for c in cmds:
                if c.name() not in TEST_CMDS:
                    continue
                ws = c.words
                for k in range(1, len(ws) - 1):
                    if ws[k].lit() in ('=', '=='):
                        a, b = self._posof(ws[k - 1], alias), self._posof(ws[k + 1], alias)
                        if a and b and a != b:
                            out.setdefault(fname, (a, b))
        return out

    @staticmethod
    def _posof(w, alias):
        ps = nonempty_parts(w)
        if len(ps) != 1:
            return None
        p = ps[0]
        if p.k == 'pos' and isinstance(p.v, int) and p.v > 0:
            return p.v
        if p.k == 'var' and p.v in alias:
            return alias[p.v]
        return None

    def _req_funcs(self):
        req = set()
        changed = True
        while changed:
            changed = False
            for fname, cmds in self.funcs.items():
                if fname in req:
                    continue
                if any(eff_name(c) in REQ_CMDS or c.name() in req for c in cmds):
                    req.add(fname)
                    changed = True
        return req

    def _nonempty_funcs(self):
        out = set()
        for fname, cmds in self.funcs.items():
            for c in cmds:
                if c.name() in ('printf', 'echo'):
                    for w in c.words[1:]:
                        if has_default(w):
                            out.add(fname)
        return out

    def is_request(self, c):
        return eff_name(c) in REQ_CMDS or c.name() in self.req

    def descendants(self, c):
        out, stack = [], [c]
        while stack:
            x = stack.pop()
            for ch in self.children.get(id(x), []):
                out.append(ch)
                stack.append(ch)
        return out

    def eq_sides(self, c):
        """같음 비교 도우미를 부르는 명령이면 (비교되는 두 단어)."""
        n0 = c.name()
        if n0 not in self.eq:
            return None
        i, j = self.eq[n0]
        if len(c.words) <= max(i, j):
            return None
        return c.words[i], c.words[j]

    def assertion_ancestor(self, c):
        """c 가 같음 비교 도우미 호출의 인자 치환 안이면 그 호출 명령과 (비교 두 단어)."""
        x = c.parent
        while x is not None:
            s = self.eq_sides(x)
            if s:
                return x, s
            x = x.parent
        return None, None

    def chain_has_fail(self, c):
        """c 뒤로 && · || 로 이어진 목록에 || 가 있나(실패 길)."""
        x, n = c, 0
        while x is not None and n < 8:
            if x.sep == '||':
                return True
            if x.sep != '&&':
                return False
            x = self.nxt.get(id(x))
            n += 1
        return False

    # ── 변수 판정 ──
    def dynamic_var(self, v):
        lst = self.assigns.get(v)
        if not lst:
            return False
        for parts, _c in lst:
            ps = [p for p in parts if not (p.k == 'lit' and p.v == '')]
            if not ps:
                return False
            if not any(p.k == 'sub' for p in ps):
                if not (len(ps) == 1 and ps[0].k == 'var' and ps[0].v != v and self.dynamic_var(ps[0].v)):
                    return False
        return True

    def guarded(self, v, depth=0):
        key = (v, depth)
        if key in self._guard_cache:
            return self._guard_cache[key]
        self._guard_cache[key] = False
        r = self._guarded(v, depth)
        self._guard_cache[key] = r
        return r

    def _guarded(self, v, depth):
        if self._guarded1(v, depth):
            return True
        return any(self._guarded1(a, depth) for a in self.loop_alias.get(v, ()))

    def _positive_check(self, v):
        """[ "$(도우미 "$V")" -ge 1 ] || … 처럼 V 를 넘긴 값이 긍정이어야 하고 실패 길이 있는 확인."""
        for c in self.sf.cmds:
            if c.name() not in TEST_CMDS:
                continue
            in_assert = self.assertion_ancestor(c)[0] is not None
            if not (self.chain_has_fail(c) or in_assert):
                continue
            ws = c.words
            ops = [w.lit() for w in ws]
            if any(o in ('-z', '!') for o in ops):
                continue
            for k in range(1, len(ws) - 1):
                if ops[k] in ('-ge', '-gt', '-ne', '=', '==', '!=', '-eq'):
                    a, b = ws[k - 1], ws[k + 1]
                    lits = [a.lit(), b.lit()]
                    if ops[k] in ('=', '==', '-eq') and any(lt is not None and lt.strip() in NEG_LIT for lt in lits):
                        continue
                    if in_assert and not self.chain_has_fail(c) and ops[k] not in ('-ge', '-gt'):
                        continue
                    # 「1건 이상」 모양이면 값 안에 끼운 V(… WHERE id='$V' …)도 본다
                    embedded = ops[k] in ('-ge', '-gt')
                    for w in (a, b):
                        for sb in word_subs(w):
                            for x in sb.cmds:
                                for y in [x] + self.descendants(x):
                                    for u in y.words[1:]:
                                        if whole_var(u) == v:
                                            return True
                                        if embedded and any(p.k == 'var' and p.v == v for p in u.parts):
                                            return True
        return False

    def _guarded1(self, v, depth):
        sf = self.sf
        if self._positive_check(v):
            return True
        for c in sf.cmds:
            n0 = c.name()
            # 모든 조각: ${V:?} · ${#V}
            for w in c.words + c.assigns + c.herestr:
                for p in w.parts:
                    if p.v == v and p.k == 'var' and p.op[:2] in (':?',) or (p.k == 'len' and p.v == v):
                        if p.k == 'len' and n0 not in TEST_CMDS and not (c.parent and c.parent.name() in TEST_CMDS):
                            continue
                        return True
            if n0 in TEST_CMDS:
                ws = c.words
                for k in range(1, len(ws) - 1):
                    op = ws[k].lit()
                    if op in ('-z', '-n') and whole_var(ws[k + 1]) == v:
                        if op == '-z':
                            return True
                        if self.chain_has_fail(c) or self.assertion_ancestor(c)[0] is not None:
                            return True
                        if c.cond is not None and c.cond.get('has_else'):
                            return True
                    if op in ('-z', '-n'):
                        ps = nonempty_parts(ws[k + 1])
                        if len(ps) == 1 and ps[0].k == 'indirect' and self._loop_names(ps[0].v, c, v):
                            return True
                    # 모양 확인(v4 — FP-0008): [[ "$V" =~ ^[0-9]+$ ]] — 빈 글자에 맞지 않는 정규식으로 거르고 갈래를 끝냄
                    #   (`!` 로 뒤집은 것은 -z 처럼 · 아니면 -n 처럼 실패 길 · 단언 안 · else 가 있어야)
                    if op == '=~' and k >= 2 and whole_var(ws[k - 1]) == v and regex_needs_char(self, ws[k + 1:]):
                        if c.neg or ws[k - 2].lit() == '!':
                            return True
                        if self.chain_has_fail(c) or self.assertion_ancestor(c)[0] is not None:
                            return True
                        if c.cond is not None and c.cond.get('has_else'):
                            return True
            # 내용 확인: printf "$V" | grep -q … || … · grep -q … <<< "$V" || …
            if eff_name(c) in ('grep', 'egrep') and self.chain_has_fail(c):
                src = False
                if c.prev is not None and c.prev[1] == '|':
                    pipe_word = c.prev[0]
                    if pipe_word.name() in ('printf', 'echo') and any(whole_var(w) == v for w in pipe_word.words[1:]):
                        src = True
                if any(whole_var(w) == v for w in c.herestr):
                    src = True
                if src:
                    return True
        # case "$V" in ''|*[!0-9]*) …; exit ;; esac — 빈 값이 걸리는 첫 갈래가 끝내면 확인한 것(v4 — FP-0008)
        if self._case_checked(v):
            return True
        # 대체 값으로 다시 대입: V=${V:-missing}
        for parts, _c in self.assigns.get(v, []):
            for p in parts:
                if p.k == 'var' and p.v == v and p.op[:2] in (':-', ':=') and len(p.op) > 2:
                    return True
            subs = [p.sub for p in parts if p.k == 'sub' and p.sub is not None]
            for sb in subs:
                if sb.cmds and sb.cmds[0].name() in self.nonempty:
                    return True
                # 확인된 값을 해시·가공한 값(한 단계)
                if depth == 0:
                    for sc in sb.cmds:
                        for w in sc.words[1:] + sc.herestr:
                            u = whole_var(w)
                            if u and u != v and self.guarded(u, 1):
                                return True
        return False

    def _case_checked(self, v):
        for c in self.sf.cmds:
            if c.kind != 'header' or len(c.words) < 3 or c.words[0].lit() != 'case' or whole_var(c.words[1]) != v:
                continue
            arms = case_arms(self.sf.text, c.line)
            if not arms:
                continue
            for pats, body in arms:
                if any(pattern_matches_empty(p) for p in pats):
                    if CASE_TERM_RE.search(body):
                        return True
                    break
        return False

    def _loop_names(self, loopvar, c, v):
        """for loopvar in <이름 목록> 이 V 를 담나(목록은 글자 또는 글자만 대입된 변수)."""
        for hc in self.sf.cmds:
            if hc.kind != 'header' or not hc.words or hc.words[0].lit() != 'for':
                continue
            if len(hc.words) < 3 or hc.words[1].lit() != loopvar or hc.words[2].lit() != 'in':
                continue
            names = []
            for w in hc.words[3:]:
                lt = w.lit()
                if lt is not None:
                    names.extend(lt.split())
                    continue
                u = whole_var(w)
                if u:
                    for parts, _ac in self.assigns.get(u, []):
                        if all(p.k == 'lit' for p in parts):
                            names.extend(''.join(p.v for p in parts).split())
            if v in names:
                return True
        return False

    def word_guarded(self, w):
        if has_default(w):
            return True
        v = whole_var(w)
        if v is not None:
            return self.guarded(v)
        for sb in word_subs(w):
            if sb.cmds and sb.cmds[0].name() in self.nonempty:
                return True
        return False

    def sub_has_request(self, sb, depth=0):
        for x in sb.cmds:
            for y in [x] + self.descendants(x):
                if self.is_request(y):
                    return True
        return False

    def word_from_request(self, w, depth=0):
        for sb in word_subs(w):
            if self.sub_has_request(sb):
                return True
        v = whole_var(w)
        if v is None or depth > 2:
            return False
        for parts, _c in self.assigns.get(v, []):
            for p in parts:
                if p.k == 'sub' and p.sub is not None and self.sub_has_request(p.sub):
                    return True
                if p.k == 'var' and p.v != v and p.nest is None and \
                        self.word_from_request(ShWord([p], 0, ''), depth + 1):
                    return True
        return False

    def word_dynamic(self, w):
        if w.lit() is not None:
            return False
        if word_subs(w):
            return True
        v = whole_var(w)
        return v is not None and self.dynamic_var(v)


# ── ① 기록 오염 ──
def norm_pattern(w):
    """경로 단어 → 정규식(변수는 이름 그대로, 위치 인자·치환은 아무 글자)."""
    out = []
    for p in w.parts:
        if p.k == 'lit':
            out.append(re.escape(p.v))
        elif p.k == 'var' and not p.op:
            out.append(re.escape('${%s}' % p.v))
        else:
            out.append('.+?')
    return ''.join(out)


def norm_text(w):
    out = []
    for p in w.parts:
        if p.k == 'lit':
            out.append(p.v)
        elif p.k == 'var' and not p.op:
            out.append('${%s}' % p.v)
        else:
            out.append('\x00')
    return ''.join(out)


def is_bg(c):
    if c.sep == '&':
        return True
    for b, _part in c.blocks:
        if b.get('kind') == 'subshell' and b.get('sep') == '&':
            return True
    return False


def trace(ctx, stmts, depth=0, seen=()):
    """글 차례 + 부른 함수 펼치기 — (명령) 목록."""
    out = []
    for s in stmts:
        group = [s] + ctx.descendants(s)
        group.sort(key=lambda x: -x.insub)
        for c in group:
            out.append(c)
            n0 = c.name()
            if n0 in ctx.funcs and n0 not in seen and depth < 4:
                body = sorted((x for x in ctx.funcs[n0] if x.parent is None), key=lambda x: x.seq)
                out.extend(trace(ctx, body, depth + 1, seen + (n0,)))
    return out


def check_record(ctx):
    sf = ctx.sf
    out = []
    recorders = []       # (정규식, 명령)
    for c in sf.cmds:
        if not is_bg(c):
            continue
        for w in c.assigns + c.words[1:]:
            name, parts = sh_assign_parts(w) if SH_ASSIGN_RE.match(w.raw) else (None, w.parts)
            vw = ShWord(parts, w.line, '')
            txt = vw.lit_text()
            if '/' in txt and re.search(r'\.(?:log|txt|out|jsonl)$', txt):
                recorders.append((norm_pattern(vw), c))
    if not recorders:
        return out
    # 줄 수 변수: N=$(… F …) 에 wc -l · grep -c · awk
    counters = {}        # 변수 → (정규식, 대입 명령)
    for v, lst in ctx.assigns.items():
        for parts, ac in lst:
            for sb in [p.sub for p in parts if p.k == 'sub' and p.sub is not None]:
                cmds = sb.cmds
                names = [eff_name(x) for x in cmds]
                counting = any(n == 'wc' for n in names) or any(
                    n in ('grep', 'egrep') and any((w.lit() or '').startswith('-') and 'c' in (w.lit() or '')
                                                   for w in x.words[1:]) for n, x in zip(names, cmds))
                if not counting:
                    continue
                for x in cmds:
                    for w in x.words[1:] + [rw[1] for rw in x.redirs if rw[0] == '<']:
                        t = norm_text(w)
                        for rx, rc in recorders:
                            if t and re.fullmatch(rx, t):
                                counters[v] = (rx, ac)
    if not counters:
        return out
    # 「1건 이상」 단언
    asserts = {}
    for c in sf.cmds:
        if c.name() not in TEST_CMDS:
            continue
        ws = c.words
        for k in range(1, len(ws) - 1):
            v = whole_var(ws[k])
            if v in counters and (ws[k + 1].lit(), (ws[k + 2].lit() if k + 2 < len(ws) else None)) in COUNT_OPS:
                asserts.setdefault(v, []).append(c)
    if not asserts:
        return out
    stmts = [c for c in sf.cmds if c.insub == 0 and c.func is None]
    tr = trace(ctx, stmts)
    state = {}           # 정규식 → 더럽힌 요청 줄(None = 깨끗)
    started = set()
    for c in tr:
        for rx, rc in recorders:
            if c is rc:
                started.add(rx)
        # 비우기
        if not c.words or c.name() in (':', 'true'):
            for op, w in c.redirs:
                if op in ('>', '>|'):
                    t = norm_text(w)
                    for rx, _rc in recorders:
                        if re.fullmatch(rx, t):
                            state[rx] = None
        if eff_name(c) in REQ_CMDS and not any(
                re.fullmatch(rx, norm_text(w)) for w in c.words for rx, _rc in recorders):
            for rx in started:
                lst = state.get(rx) or []
                if c.line not in lst:
                    lst.append(c.line)
                state[rx] = lst
        # 세기
        for v, (rx, ac) in counters.items():
            if c is ac and state.get(rx) and v in asserts:
                for a in asserts[v][:1]:
                    aline = a.line
                    top = a
                    while top.parent is not None:
                        top = top.parent
                    reqs = sorted(state[rx])
                    out.append(('중간', sf.rel, top.line,
                                '「1건 이상」 단언이 세는 기록 파일은 기록기를 띄운 뒤 이 셸이 스스로 보낸 요청(%s행 curl·wget)이 '
                                '먼저 줄을 남길 수 있어 늘 참 — 그 요청 뒤에 기록을 비우거나(`: > 파일`) 기록에서 뺀다'
                                % '·'.join(str(x) for x in reqs[:4])))
    return out


# ── ② 공통 셸 글자 ──
_REQ_PHP_RE = re.compile(r'''\b(?:require|include)(?:_once)?\s*\(?\s*(?:__DIR__\s*\.\s*)?(['"])([^'"]+\.php)\1''')


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


def common_shell(root):
    """src 의 PHP 중 세 파일 이상이 require·include 하고 HTML 을 가진 파일 → ({경로: 글}, 설명 이름)."""
    srcd = os.path.join(root, 'src')
    if not os.path.isdir(srcd):
        return {}
    texts = {}
    for d, dirs, files in os.walk(srcd):
        dirs[:] = sorted(x for x in dirs if x != '.git')
        for f in files:
            if f.endswith('.php'):
                full = os.path.join(d, f)
                if not safe_file(full, root):
                    continue
                try:
                    with open(full, 'rb') as fh:
                        texts[os.path.realpath(full)] = fh.read().decode('utf-8', 'replace')
                except OSError:
                    continue
    users = {}
    for full, txt in texts.items():
        for m in _REQ_PHP_RE.finditer(txt):
            tgt = os.path.realpath(os.path.join(os.path.dirname(full), m.group(2).lstrip('/')))
            if tgt in texts and tgt != full:
                users.setdefault(tgt, set()).add(full)
    out = {}
    for tgt, us in users.items():
        if len(us) >= 3 and re.search(r'<(?:a|nav|div|span|header|footer|button|h[1-6]|li|ul|main|body|html)\b',
                                      texts[tgt], re.I):
            out[os.path.relpath(tgt, root)] = texts[tgt]
    return out


def page_path(ctx, sub):
    """치환이 화면(GET) 요청이면 경로 글자, 아니면 None."""
    if not sub.cmds:
        return None
    c0 = sub.cmds[0]
    if not ctx.is_request(c0):
        return None
    words = sh_effective(c0.words)[1:] if eff_name(c0) in REQ_CMDS else c0.words[1:]
    lits = [w.lit() for w in words]
    for k, lt in enumerate(lits):
        if lt in ('POST', 'PUT', 'PATCH', 'DELETE') or (lt in ('-X', '--request') and k + 1 < len(lits) and
                                                        (lits[k + 1] or 'GET') != 'GET'):
            return None
        if lt in ('-d', '--data', '-F', '--form', '--data-binary', '-T', '-I', '--head'):
            return None
    skip_next = False
    for k, w in enumerate(words):
        lt = w.lit()
        if skip_next:
            skip_next = False
            continue
        if lt is not None and re.fullmatch(r'-[A-Za-z]', lt) and lt[1] in CURL_VAL:
            skip_next = True
            continue
        if lt is not None and lt.startswith('--') and lt not in CURL_NOVAL and '=' not in lt:
            skip_next = True
            continue
        t = w.lit_text()
        if not t:
            continue
        m = re.search(r'(?:^|://[^/]*|^)(/[^\s?#]*)', t) if (t.startswith('/') or '://' in t or
                                                           (w.parts and w.parts[0].k == 'var' and
                                                            t.startswith('/'))) else None
        if not m:
            continue
        path = m.group(1)
        if '/api/' in path or '/assets/' in path or re.search(r'\.[A-Za-z0-9]{1,5}$', path):
            return None
        return path
    return None


def grep_needle(c):
    """grep 명령의 (바늘, 고정 글자인가, 없어야 하는가, -c 인가) — 모르면 None."""
    if eff_name(c) not in ('grep', 'egrep'):
        return None
    ws = sh_effective(c.words)[1:]
    fixed = eff_name(c) == 'fgrep'
    neg = c.neg
    count = False
    pat = None
    k = 0
    rest = []
    while k < len(ws):
        lt = ws[k].lit()
        if lt is None:
            rest.append(ws[k])
            k += 1
            continue
        if lt in ('-e', '--regexp') and k + 1 < len(ws):
            pat = ws[k + 1].lit()
            k += 2
            continue
        if lt.startswith('--'):
            if lt == '--fixed-strings':
                fixed = True
            if lt == '--invert-match':
                neg = True
            if lt == '--count':
                count = True
            k += 1
            continue
        if lt.startswith('-') and len(lt) > 1:
            fl = lt[1:]
            if 'F' in fl:
                fixed = True
            if 'v' in fl:
                neg = True
            if 'c' in fl:
                count = True
            if fl[-1:] in ('e', 'f', 'm', 'A', 'B', 'C') and fl[-1:] != 'c':
                if fl[-1:] == 'e' and k + 1 < len(ws):
                    pat = ws[k + 1].lit()
                k += 2
                continue
            k += 1
            continue
        rest.append(ws[k])
        k += 1
    if pat is None:
        if not rest:
            return None
        pat = rest[0].lit()
    if not pat:
        return None
    return pat, fixed, neg, count


def needle_in(pat, fixed, text):
    if fixed or not re.search(r'[.\[\]*^$\\+?(){}|]', pat):
        return pat in text
    try:
        rx = re.compile(pat.replace('\\|', '|').replace('\\(', '(').replace('\\)', ')'))
    except re.error:
        return False
    return rx.search(text) is not None


def check_shell_text(ctx, shell):
    out = []
    if not shell:
        return out
    sf = ctx.sf
    joined = '\n'.join(shell.values())
    shell_name = sorted(shell)[0] if len(shell) == 1 else '·'.join(sorted(os.path.basename(k) for k in shell))
    hist = {}            # 변수 → [(대입 차례, 화면 경로 또는 None)]
    for v, lst in ctx.assigns.items():
        for parts, ac in lst:
            pth = None
            for p in parts:
                if p.k == 'sub' and p.sub is not None:
                    pth = page_path(ctx, p.sub) or pth
            hist.setdefault(v, []).append((ac.seq, pth))

    def page_of(v, seq):
        """grep 바로 앞 대입이 화면 요청이면 그 경로."""
        best = None
        for sq, pth in hist.get(v, []):
            if sq < seq and (best is None or sq > best[0]):
                best = (sq, pth)
        return best[1] if best else None
    for c in sf.cmds:
        g = grep_needle(c)
        if not g:
            continue
        pat, fixed, neg, count = g
        if neg or len(pat.strip()) < 2:
            continue
        src_page = None
        if c.prev is not None and c.prev[1] == '|':
            pipe_word = c.prev[0]
            if pipe_word.name() in ('printf', 'echo'):
                for w in pipe_word.words[1:]:
                    v = whole_var(w)
                    if v:
                        src_page = page_of(v, c.seq) or src_page
            elif ctx.is_request(pipe_word):
                sub = ShSub([pipe_word], '', pipe_word.line)
                src_page = page_path(ctx, sub)
        for w in c.herestr:
            v = whole_var(w)
            if v:
                src_page = page_of(v, c.seq) or src_page
        if not src_page:
            continue
        # 없어야 한다는 단언(기대 0·no)은 뺀다
        call, sides = ctx.assertion_ancestor(c)
        if call is not None:
            lits = [w.lit() for w in sides]
            if any(lt is not None and lt.strip() in NEG_LIT for lt in lits):
                continue
        if c.parent is not None and c.parent.neg:
            continue
        if not needle_in(pat, fixed, joined):
            continue
        top = c
        while top.parent is not None:
            top = top.parent
        short = pat if len(pat) <= 24 else pat[:24] + '…'
        out.append(('중간', sf.rel, top.line,
                    '화면 응답(GET %s)에서 찾는 글자 「%s」는 모든 화면에 실리는 공통 셸(%s)에도 있어 어느 화면이 와도 참 — '
                    '그 화면에만 있는 표식(제목 요소·data- 속성·열 구성)으로' % (src_page, short, shell_name)))
    return out


# ── ③ 빈 준비 값 · ③′ 말없이 빠지는 단언 ──
def prep_vars(ctx):
    out = set()
    for v, lst in ctx.assigns.items():
        ok = True
        for parts, _c in lst:
            ps = [p for p in parts if not (p.k == 'lit' and p.v == '')]
            if not (len(ps) == 1 and ps[0].k == 'sub'):
                ok = False
        if ok and lst:
            out.add(v)
    return out


def positive_use(ctx, v):
    """V(또는 그 반복 별칭)를 인자로 — 다른 글자와 합친 것도(`"/api/pages/$V"` · `"$W0|$V"`) — 넘긴 긍정 단언이 있나.
    V 가 비면 그 단언의 기대·실제가 달라져 실패하므로 준비 실패가 드러난다(합친 단어 — FP-0007 뒤 `/` 만이 아니라 글자가 있으면)."""
    names = {v} | ctx.loop_alias.get(v, set())
    for c in ctx.sf.cmds:
        call, sides = ctx.assertion_ancestor(c)
        if call is None:
            continue
        if not any(whole_var(w) in names or
                   (w.lit() is None and any(p.k == 'var' and p.v in names and p.nest is None for p in w.parts) and
                    w.lit_text().strip() != '')
                   for w in c.words[1:]):
            continue
        lits = [w.lit() for w in sides]
        if any(lt is not None and lt.strip() not in NEG_LIT for lt in lits):
            return True
    return False


# ── 출처가 확인된 값(v4 — FP-0007) ──
# V=$(도우미 "$SRC" …) 처럼 V 가 다른 변수 SRC 에서 **뽑은** 값이고, SRC(또는 같은 SRC 에서 뽑은 다른 값)가 긍정 단언으로
# 확인되면 V 도 확인된 것으로 본다(한 단계). 「뽑기」 = 치환 안의 명령이 전부 요청(curl·wget·그 도우미)·특권 감싸기(sudo …)·
# 쓰는 말(INSERT·POST …)·파일로 가는 리다이렉트가 없는 글 다루기 도구이거나 그런 도구만 쓰는 도우미 — 만들기·조회는 뽑기가 아니다.
EXTRACT_TOOLS = {'jq', 'python3', 'python', 'sed', 'awk', 'gawk', 'mawk', 'grep', 'egrep', 'fgrep', 'cut', 'head', 'tail',
                 'tr', 'printf', 'echo', 'sort', 'uniq', 'wc', 'basename', 'dirname', 'base64', 'sha256sum', 'sha1sum',
                 'md5sum', 'local', 'return', 'true', 'false', ':', '[', '[[', 'test', 'cat'}
WRITE_WORD_RE = re.compile(r'(?i)\b(?:insert|update|delete|create|drop|alter|truncate|post|put|patch)\b')
PY_IO_RE = re.compile(r'\b(?:urllib|requests|http\.client|socket|subprocess|os\.system)\b|\bopen\s*\(')


def extract_cmd(ctx, c, seen=()):
    """명령 하나가 「뽑기」인가(위 설명)."""
    if not c.words:
        return True
    n0 = c.name()
    if n0 is None or eff_name(c) != os.path.basename(n0):
        return False         # 동적 명령 이름 · sudo·env 같은 감싸기
    if ctx.is_request(c):
        return False
    for op, w in c.redirs:
        if op in ('>', '>>', '>|', '&>', '&>>') and (w.lit() or '') not in ('/dev/null', '/dev/stderr', '/dev/stdout'):
            return False
    for w in c.words[1:] + c.herestr:
        if WRITE_WORD_RE.search(w.lit_text()):
            return False
    if n0 in ctx.funcs:
        if n0 in seen:
            return True
        return all(extract_cmd(ctx, x, seen + (n0,)) for x in ctx.funcs[n0])
    base = os.path.basename(n0)
    if base not in EXTRACT_TOOLS:
        return False
    if base in ('python3', 'python') and any(PY_IO_RE.search(w.lit_text()) for w in c.words[1:]):
        return False
    if base in ('sed', 'awk', 'gawk') and any((w.lit() or '').startswith(('-i', '--in-place')) for w in c.words[1:]):
        return False
    return True


def extract_sources(ctx, v):
    """V 의 대입이 전부 뽑기 치환이면 그 치환이 통째로 받는 변수들(SRC) — 아니면 빈 집합."""
    lst = ctx.assigns.get(v)
    if not lst:
        return set()
    out = set()
    for parts, _c in lst:
        ps = [p for p in parts if not (p.k == 'lit' and p.v == '')]
        if not (len(ps) == 1 and ps[0].k == 'sub' and ps[0].sub is not None and ps[0].sub.cmds):
            return set()
        cmds = [y for x in ps[0].sub.cmds for y in [x] + ctx.descendants(x)]
        if not all(extract_cmd(ctx, y) for y in cmds):
            return set()
        got = set()
        for y in cmds:
            for w in y.words[1:] + y.herestr:
                u = whole_var(w)
                if u and u != v:
                    got.add(u)
        if not got:
            return set()
        out |= got
    return out


def source_confirmed(ctx, v):
    """V 를 뽑은 SRC, 또는 같은 SRC 에서 뽑은 다른 값이 긍정 단언으로 확인되나(흐름 한 단계)."""
    srcs = extract_sources(ctx, v)
    if not srcs:
        return False
    for s in srcs:
        if positive_use(ctx, s):
            return True
    for u in ctx.assigns:
        if u == v or u in srcs:
            continue
        if extract_sources(ctx, u) & srcs and positive_use(ctx, u):
            return True
    return False


def arith_only(cmds):
    """함수 몸이 위치 인자를 산술 $(( )) 안에서만 쓰나."""
    seen_arith = False
    for c in cmds:
        for w in c.words + c.assigns:
            for p in w.parts:
                if p.k == 'pos':
                    return False
                if p.k == 'arith' and re.search(r'\$\{?[1-9]', p.v):
                    seen_arith = True
        if c.kind == 'arith' and re.search(r'\$\{?[1-9]', c.text):
            seen_arith = True
    return seen_arith


def check_prep(ctx):
    sf = ctx.sf
    out = []
    pv = prep_vars(ctx)
    if not pv:
        return out
    seen = set()
    skips = {}
    for c in sf.cmds:
        sides = ctx.eq_sides(c)
        if not sides:
            continue
        lits = [w.lit() for w in sides]
        negs = [k for k, lt in enumerate(lits) if lt is not None and lt.strip() in NEG_LIT]
        # ③′ 말없이 빠짐 — [ -n "$V" ] && 단언 · { … } · else 없는 if
        guard = None
        if c.prev is not None and c.prev[1] == '&&':
            guard = c.prev[0]
        else:
            for b, part in reversed(c.blocks):
                if b.get('kind') == 'brace' and b.get('guard') and b['guard'][1] == '&&':
                    guard = b['guard'][0]
                    break
                if b.get('kind') == 'if' and part == 'then' and not b.get('has_else') and \
                        len(b.get('conds', [])) == 1 and not b.get('elif'):
                    guard = b['conds'][0]
                    break
                if b.get('kind') in ('func', 'loop'):
                    break
        if guard is not None and negs and guard.name() in TEST_CMDS and len(guard.words) >= 3 and \
                guard.words[1].lit() == '-n' and not guard.neg:
            gv = None
            ps = nonempty_parts(guard.words[2])
            if len(ps) == 1 and ps[0].k == 'var':
                gv = ps[0].v
            if gv in pv and not ctx.guarded(gv) and ('skip', id(guard)) not in seen:
                seen.add(('skip', id(guard)))
                skips.setdefault(gv, []).append(guard.line)
                continue
        if not negs:
            continue
        other = sides[1 - negs[0]]
        if len(negs) == 2:
            continue
        for sb in word_subs(other):
            for sc in [x for x in sb.cmds] + [d for x in sb.cmds for d in ctx.descendants(x)]:
                # 준비 값을 **도우미·요청**의 대상 인자로 넘길 때만(grep 바늘·printf 글감은 빈 값의 뜻이 모드마다 달라 뺀다)
                if not (sc.name() in ctx.funcs or ctx.is_request(sc)):
                    continue
                # 위치 인자를 산술에만 쓰는 도우미(delta 류)는 빈 값이 「없음」이 되지 않는다
                if sc.name() in ctx.funcs and arith_only(ctx.funcs[sc.name()]):
                    continue
                for w in sc.words[1:]:
                    v = whole_var(w)
                    if v not in pv or ('neg', v) in seen:
                        continue
                    if ctx.guarded(v) or positive_use(ctx, v) or source_confirmed(ctx, v):
                        continue
                    seen.add(('neg', v))
                    out.append(('낮음', sf.rel, c.line,
                                '부정 단언(기대 「%s」)의 대상 %s 는 $(…) 준비 값인데 비었는지 확인이 없다 — 만들기가 실패해 '
                                '비면 「없음」이 공짜로 통과' % (lits[negs[0]].strip() or '빈 값', v)))
    for gv, lines in skips.items():
        lines = sorted(set(lines))
        more = (' · 같은 확인 %d곳 더(%s행)' % (len(lines) - 1, ','.join(str(x) for x in lines[1:6]))) if len(lines) > 1 else ''
        out.append(('낮음', sf.rel, lines[0],
                    '준비 값 %s($(…))가 비면 `[ -n ]` 로 막은 단언이 말없이 빠진다%s — 비면 실패로 세거나 건너뜀을 알린다'
                    % (gv, more)))
    return out


# ── ④ 빈 값끼리 같음 ──
def check_empty_eq(ctx):
    sf = ctx.sf
    out = []
    sites = []
    for c in sf.cmds:
        s = ctx.eq_sides(c)
        if s:
            sites.append((c, s[0], s[1]))
            continue
        if c.name() in TEST_CMDS:
            ws = c.words
            for k in range(1, len(ws) - 1):
                if ws[k].lit() in ('=', '=='):
                    if ctx.chain_has_fail(c) or ctx.assertion_ancestor(c)[0] is not None:
                        sites.append((c, ws[k - 1], ws[k + 1]))
    for c, a, b in sites:
        if not (ctx.word_dynamic(a) and ctx.word_dynamic(b)):
            continue
        # 두 쪽 모두 요청(curl·wget·그 도우미)에서 온 값 — 같은 서버가 답하지 않으면 두 쪽이 함께 빈다
        if not (ctx.word_from_request(a) and ctx.word_from_request(b)):
            continue
        if ctx.word_guarded(a) or ctx.word_guarded(b):
            continue
        top = c
        while top.parent is not None:
            top = top.parent
        out.append(('낮음', sf.rel, top.line,
                    '같음 비교의 두 쪽이 모두 명령 치환 값인데 어느 쪽도 빈 값 확인이 없다 — 명령·서버가 답하지 않으면 빈 값'
                    '(또는 빈 입력의 해시·줄 수)끼리 같다고 통과'))
    return out


def main(argv=None):
    here = os.path.dirname(os.path.abspath(__file__))
    ap = argparse.ArgumentParser(description='R23 늘 참인 단언 — 앞선 동작이 단언의 한쪽을 정해 버리는 모양(읽기만)')
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
    shell = common_shell(root)
    findings = []
    for rel, sf in sfs.items():
        ctx = FileCtx(sf, [sfs[r] for r in srcs.get(rel, [])])
        for part in (check_record, check_prep, check_empty_eq):
            findings.extend(part(ctx))
        findings.extend(check_shell_text(ctx, shell))
    kept = []
    for f in findings:
        sf = sfs.get(f[1])
        if sf is not None and sf.allowed(RULE, f[2]):
            skipped += 1
            continue
        kept.append(f)
    return sh_emit(RULE, kept, len(sfs), skipped)


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
