#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
R2 감사 대조 (audit cross-check) — 1-F 정적 규칙, python3 stdlib 만, 파일은 읽기만.

answers: AK-0074 · AK-0042 · AK-0086 · AK-0143

무엇을 부류로 잡나:
  감사 기록은 세 곳이 서로 맞아야 한다 — ① 코드의 감사 호출 `Audit::allow|deny|error(action, …)` 가
  만드는 (action · 결과 · 사유) 키, ② `Audit` 클래스의 요약 문장표(키 → 문장 틀, 표에 없는 키는 기록
  실패 = 요청 500), ③ 설계 문서(ARCHITECTURE.md)의 감사 문장 표(「글자 그대로 옮긴」 원본).
  또 권한 거부로 끝나는 갈래에는 거부 감사가 있어야 한다. 이 규칙은 네 가지 어긋남을 부류로 센다.
    C1 호출 → 문장표   : 정적으로 모을 수 있는 호출 키가 문장표에 없다(첫 요청에서 500).
    C2 문장표 → 문서   : 문장표 키가 문서의 감사 표에 없다(문서 표를 쓰는 영역의 action 만 — 아래).
    C3 문서 → 문장표   : 문서 표의 행이 문장표에 없거나, 문장 틀 글자가 다르다.
    C4 거부 갈래       : `Http::error(401|403…)`·`Http::json(…, 401|403)` 응답, 그리고 몸에 `'reason' => 'x'` 를
                         실은 4xx JSON 응답 가운데 「그 파일의 감사 action + deny + x」 가 문장표에 등록된 것
                         (= 이 창구가 감사하기로 한 거부) 에 이르는 길에 감사 호출이 없다. 서버 로그(error_log)로만
                         남기고 거부하는 모양이 대표적이다. 「길에 있다」는 다음 가운데 하나다.
                           · 가장 안쪽 블록 안, 응답 앞에 감사 호출(직접 · 감사하는 함수 · 감사하는 클로저 변수)
                             — 안쪽 갈래에서 알려진 사유를 감사하고 나머지를 로그로 넘기는 모양은 받아 준다
                           · 바깥 블록들의 **그 층** 문장에 `Audit::deny|error`(지배하는 거부 기록, 함수 몸에서 멈춤)
                           · 갈래를 연 조건의 변수가 감사하는 함수의 결과(판정 함수가 스스로 기록), 또는 catch 갈래의
                             try 몸이 감사하는 함수를 부른다
                         감사하는 함수는 몸에 감사 호출이 있는 함수를 클래스까지 붙여 푼 것이다(이름만으로 풀면
                         같은 이름의 다른 함수로 번진다). 사유 없는 400·404 는 모양 오류·없음이라 보지 않는다.
  사유·action 이 변수라 정적으로 값을 모을 수 없는 호출은 검사하지 않고 skip 으로 센다. 다만 둘러싼 `if` 조건이
  그 변수를 상수·문자열과 `===` 로만 견주면(`||` 로 이은 것 포함) 그 값들로 좁히고, 같은 함수 안에서 그 변수에
  값을 넣는 곳이 딱 한 번(`$v = <문자열·상수·삼항>;`)이면 그 값으로 검사한다.

  C2 의 범위: 문서 감사 표에 한 행이라도 있는 action 만 본다. 문서 표에 action 자체가 한 번도 나오지
  않으면(다른 문서가 등록부인 영역) 그 키는 skip 으로 센다 — 문서 위치를 파일 이름으로 가정하지 않기 위해서다.
  C4 가 모르는 것: 사유 코드 없이 400 으로 끝나는 권한성 거부, 권한 사유 404, 「감사하지 않기로 한」 창구의
  결정(주석으로만 적힌 것) — 이런 건은 판단이 필요해 advisory 로 둔다.

입력: --root <트리>(기본: 이 파일 기준 저장소 뿌리) · --checks all|table|deny(기본 all. table = C1·C2·C3,
      deny = C4 — 정밀도가 달라 카탈로그에 둘로 나눠 올릴 수 있게). 트리의 `*.php`(src/·public/·scripts/,
      vendor·tests 제외)와 뿌리의 ARCHITECTURE.md. 문장표는 `class Audit` 안의 상수 배열 가운데 키가
      `a.b|allow…` 모양인 것(상수 이름에 기대지 않는다).
출력: FINDING R2 <심각도> <파일:줄> <설명> · 끝 줄 RESULT pass= fail= skip=
      (대상 = 감사 호출 자리 · 문서 표 행 · 문장표 키 · 거부 응답 자리. --verbose 는 skip 까닭을 SKIP 줄로)
rc:   0 발견 없음 · 1 발견 있음 · 2 실행 오류(문장표를 못 찾음 등 = 검사 못 함)
"""
import argparse
import os
import re
import sys

RULE = 'R2'
KEY_RE = re.compile(r'^[a-z][a-z0-9_]*(?:\.[a-z0-9_]+)*\|(?:allow|deny|error)(?:\|[a-z0-9_]+)?$')
ROW_RE = re.compile(r'^\|\s*`([a-z][a-z0-9_]*(?:\.[a-z0-9_]+)*\|(?:allow|deny|error)(?:\|[a-z0-9_]+)?)`\s*\|(.*)$')
SKIP_DIRS = {'.git', 'vendor', 'node_modules', 'tests'}
CODE_DIRS = ('src', 'public', 'scripts')

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


# ─────────────────────────── 값 모으기(정적) ───────────────────────────
class Ctx:
    def __init__(self):
        self.consts = {}        # (클래스 이름 끝, 상수) → 값
        self.global_consts = {}  # 상수 → 값


def short_class(name):
    return name.rsplit('\\', 1)[-1]


def collect_consts(ctx, toks, pairs):
    """`class X { … const NAME = '값'; … }` 와 최상위 `const NAME = '값'`·`define('NAME','값')` 을 모은다."""
    k = 0
    n = len(toks)
    class_stack = []  # (닫는 괄호 위치, 이름)
    while k < n:
        while class_stack and k > class_stack[-1][0]:
            class_stack.pop()
        t = toks[k]
        if t.kind == 'ident' and t.val.lower() in ('class', 'interface', 'trait', 'enum') and k + 1 < n \
                and toks[k + 1].kind == 'ident' and not (k > 0 and toks[k - 1].kind == 'op' and toks[k - 1].val == '::'):
            name = toks[k + 1].val
            j = k + 2
            while j < n and not (toks[j].kind == 'op' and toks[j].val == '{'):
                j += 1
            if j < n and j in pairs:
                class_stack.append((pairs[j], name))
            k = j + 1
            continue
        if t.kind == 'ident' and t.val.lower() == 'const' and k + 3 < n:
            j = k + 1
            # 형 선언(PHP 8.3 typed const) 건너뛰기
            if toks[j].kind == 'ident' and k + 4 < n and toks[j + 1].kind == 'ident':
                j += 1
            if toks[j].kind == 'ident' and toks[j + 1].kind == 'op' and toks[j + 1].val == '=' \
                    and toks[j + 2].kind == 'str' and toks[j + 3].kind == 'op' and toks[j + 3].val in (';', ','):
                if class_stack:
                    ctx.consts[(class_stack[-1][1], toks[j].val)] = toks[j + 2].val
                else:
                    ctx.global_consts[toks[j].val] = toks[j + 2].val
        if t.kind == 'ident' and t.val.lower() == 'define' and k + 5 < n and toks[k + 1].val == '(' \
                and toks[k + 2].kind == 'str' and toks[k + 3].val == ',' and toks[k + 4].kind == 'str' \
                and toks[k + 5].val == ')':
            ctx.global_consts[toks[k + 2].val] = toks[k + 4].val
        k += 1


def enclosing_class(toks, pairs, pos):
    best = None
    for o, c in pairs.items():
        if toks[o].val != '{' or not (o < pos < c):
            continue
        j = o - 1
        # 머리: … class NAME [extends …] [implements …] {
        while j >= 0 and not (toks[j].kind == 'op' and toks[j].val in (';', '{', '}')):
            if toks[j].kind == 'ident' and toks[j].val.lower() == 'class' and j + 1 < len(toks) \
                    and toks[j + 1].kind == 'ident':
                if best is None or o > best[0]:
                    best = (o, toks[j + 1].val)
                break
            j -= 1
    return best[1] if best else None


def class_ranges(toks, pairs):
    """[(여는 괄호, 닫는 괄호, 클래스 이름)] — class/trait/interface/enum 몸."""
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
    best = ''
    bo = -1
    for o, c, name in ranges:
        if o < pos < c and o > bo:
            best, bo = name, o
    return best


def resolve(ctx, toks, lo, hi, file_class=None):
    """toks[lo:hi] 표현식이 가질 수 있는 문자열 값 집합. 모를 때 None. null 은 None 값을 원소로."""
    # 바깥 괄호 벗기기
    while hi - lo >= 2 and toks[lo].kind == 'op' and toks[lo].val == '(' and toks[hi - 1].val == ')':
        depth = 0
        whole = True
        for k in range(lo, hi):
            v = toks[k].val if toks[k].kind == 'op' else ''
            if v in ('(', '[', '{'):
                depth += 1
            elif v in (')', ']', '}'):
                depth -= 1
                if depth == 0 and k != hi - 1:
                    whole = False
                    break
        if not whole:
            break
        lo, hi = lo + 1, hi - 1
    if hi <= lo:
        return None
    # 최상위 삼항  cond ? A : B
    depth = 0
    q = None
    for k in range(lo, hi):
        t = toks[k]
        if t.kind != 'op':
            continue
        if t.val in '([{':
            depth += 1
        elif t.val in ')]}':
            depth -= 1
        elif depth == 0 and t.val == '?' and q is None:
            q = k
        elif depth == 0 and t.val == ':' and q is not None:
            if q + 1 == k:  # ?: 줄임
                return None
            a = resolve(ctx, toks, q + 1, k, file_class)
            b = resolve(ctx, toks, k + 1, hi, file_class)
            if a is None or b is None:
                return None
            return a | b
    # (string) 형 변환
    if hi - lo >= 4 and toks[lo].val == '(' and toks[lo + 1].kind == 'ident' \
            and toks[lo + 1].val.lower() in ('string',) and toks[lo + 2].val == ')':
        return resolve(ctx, toks, lo + 3, hi, file_class)
    if hi - lo == 1:
        t = toks[lo]
        if t.kind == 'str':
            return {t.val}
        if t.kind == 'ident' and t.val.lower() == 'null':
            return {None}
        if t.kind == 'ident' and t.val in ctx.global_consts:
            return {ctx.global_consts[t.val]}
        return None
    if hi - lo == 3 and toks[lo].kind == 'ident' and toks[lo + 1].val == '::' and toks[lo + 2].kind == 'ident':
        cls = short_class(toks[lo].val)
        if cls.lower() in ('self', 'static'):
            cls = file_class
        v = ctx.consts.get((cls, toks[lo + 2].val))
        if v is not None:
            return {v}
        return None
    return None


def guard_values(ctx, toks, pairs, blocks, pos, var, file_class):
    """변수 사유를 둘러싼 if 조건의 `$var === X (|| $var === Y …)` 로 좁힌다. 못 좁히면 None."""
    for (o, c) in blocks:
        if not (o < pos < c):
            continue
        # 가장 안쪽부터 — blocks 는 여는 위치가 큰 순
        # 머리가 if/elseif ( … ) {
        if o >= 1 and toks[o - 1].val == ')':
            p_close = o - 1
            p_open = None
            for po, pc in pairs.items():
                if pc == p_close and toks[po].val == '(':
                    p_open = po
                    break
            if p_open is not None and p_open >= 1 and toks[p_open - 1].kind == 'ident' \
                    and toks[p_open - 1].val.lower() in ('if', 'elseif'):
                vals = parse_eq_disjunction(ctx, toks, p_open + 1, p_close, var, file_class)
                if vals is not None:
                    return vals
    return None


ASSIGN_OPS = {'=', '.=', '??=', '+=', '-=', '*=', '/=', '%=', '|=', '&=', '^=', '<<=', '>>=', '**='}


def single_assignment(ctx, toks, pairs, scope, var, file_class):
    """scope=(lo, hi) 안에서 var 에 값을 넣는 곳이 **딱 한 번**이고 그것이 `$var = <정적 값>;` 이면 그 값 집합.
    분해 대입·foreach·참조·복합 대입·두 번 이상이면 None(모름)."""
    lo, hi = scope
    targets = []
    for j in range(lo, hi):
        t = toks[j]
        if t.kind != 'var' or t.val != var:
            continue
        nxt = toks[j + 1] if j + 1 < len(toks) else None
        prev = toks[j - 1] if j > 0 else None
        if nxt is not None and nxt.kind == 'op' and nxt.val in ASSIGN_OPS:
            targets.append((j, nxt.val))
            continue
        if prev is not None and ((prev.kind == 'ident' and prev.val.lower() == 'as') or prev.val in ('&', '=>')):
            return None
        # [ …, $var, … ] = …  /  list(…) = …
        for o, c in pairs.items():
            if o < j < c and toks[o].val in ('[', '(') and c + 1 < len(toks) and toks[c + 1].val == '=':
                return None
    if len(targets) != 1 or targets[0][1] != '=':
        return None
    j = targets[0][0]
    a = j + 2
    b = a
    depth = 0
    while b < hi:
        v = toks[b].val if toks[b].kind == 'op' else None
        if v in ('(', '[', '{'):
            depth += 1
        elif v in (')', ']', '}'):
            if depth == 0:
                break
            depth -= 1
        elif v == ';' and depth == 0:
            break
        b += 1
    return resolve(ctx, toks, a, b, file_class)


def parse_eq_disjunction(ctx, toks, lo, hi, var, file_class):
    terms = []
    depth = 0
    start = lo
    for k in range(lo, hi):
        t = toks[k]
        if t.kind == 'op':
            if t.val in '([{':
                depth += 1
            elif t.val in ')]}':
                depth -= 1
            elif depth == 0 and t.val == '||':
                terms.append((start, k))
                start = k + 1
        elif t.kind == 'ident' and depth == 0 and t.val.lower() == 'or':
            terms.append((start, k))
            start = k + 1
    terms.append((start, hi))
    out = set()
    for a, b in terms:
        # $var === X  |  X === $var
        eq = None
        for k in range(a, b):
            if toks[k].kind == 'op' and toks[k].val in ('===', '=='):
                eq = k
                break
        if eq is None:
            return None
        left, right = (a, eq), (eq + 1, b)
        if right[1] - right[0] == 1 and toks[right[0]].kind == 'var' and toks[right[0]].val == var:
            left, right = right, left
        if not (left[1] - left[0] == 1 and toks[left[0]].kind == 'var' and toks[left[0]].val == var):
            return None
        v = resolve(ctx, toks, right[0], right[1], file_class)
        if v is None:
            return None
        out |= v
    return out


# ─────────────────────────── 트리 읽기 ───────────────────────────
def php_files(root):
    for top in CODE_DIRS:
        base = os.path.join(root, top)
        if not os.path.isdir(base):
            continue
        for dp, dns, fns in os.walk(base):
            dns[:] = sorted(d for d in dns if d not in SKIP_DIRS)
            for fn in sorted(fns):
                if fn.endswith(('.php', '.phtml')):
                    yield os.path.join(dp, fn)


def read_text(path):
    with open(path, 'rb') as f:
        return f.read().decode('utf-8', errors='replace')


def find_templates(files_toks):
    """class Audit 안에서 키가 감사 키 모양인 상수 배열 → {키: (문장, 파일, 줄)}."""
    for path, toks, pairs in files_toks:
        cls_pos = None
        for k, t in enumerate(toks):
            if t.kind == 'ident' and t.val.lower() == 'class' and k + 1 < len(toks) and toks[k + 1].val == 'Audit':
                cls_pos = k
                break
        if cls_pos is None:
            continue
        best = None
        for k in range(cls_pos, len(toks) - 3):
            t = toks[k]
            if t.kind == 'ident' and t.val.lower() == 'const' and toks[k + 1].kind == 'ident' \
                    and toks[k + 2].val == '=' and toks[k + 3].val == '[' and (k + 3) in pairs:
                lo, hi = k + 4, pairs[k + 3]
                table = {}
                for a, b in split_args(toks, lo, hi):
                    if b - a == 3 and toks[a].kind == 'str' and toks[a + 1].val == '=>' and toks[a + 2].kind == 'str':
                        table.setdefault(toks[a].val, (toks[a + 2].val, path, toks[a].line))
                keyish = [x for x in table if KEY_RE.match(x)]
                if keyish and (best is None or len(keyish) > len(best)):
                    best = {x: table[x] for x in keyish}
        if best:
            return best
    return None


def find_doc_rows(root):
    """ARCHITECTURE.md 의 감사 표 행 → [(키, 문장 또는 None, 줄)]."""
    path = os.path.join(root, 'ARCHITECTURE.md')
    rows = []
    if not os.path.isfile(path):
        return path, None
    for no, line in enumerate(read_text(path).split('\n'), 1):
        m = ROW_RE.match(line)
        if not m:
            continue
        rest = m.group(2)
        cm = re.match(r'\s*`((?:[^`]|``)*)`\s*\|', rest)
        text = cm.group(1) if cm else None
        rows.append((m.group(1), text, no))
    return path, rows


def is_response_deny(toks, pairs, k):
    """toks[k] 가 Http 의 4xx 응답 호출이면 (상태 코드, 몸의 최상위 'reason' 값 또는 None) 을 돌려준다.
    `Http::error(4xx, …)` · `Http::json(몸, 4xx)` 만 본다(상태 코드가 변수면 모른다 → 보지 않음)."""
    if not (toks[k].kind == 'ident' and short_class(toks[k].val) == 'Http' and k + 3 < len(toks)
            and toks[k + 1].val == '::' and toks[k + 2].kind == 'ident' and toks[k + 3].val == '('):
        return None
    meth = toks[k + 2].val
    po = k + 3
    pc = pairs.get(po)
    if pc is None:
        return None
    args = split_args(toks, po + 1, pc)
    if meth == 'error' and args:
        a, b = args[0]
        if b - a == 1 and toks[a].kind == 'num' and re.match(r'^4\d\d$', toks[a].val):
            return toks[a].val, None
        return None
    if meth == 'json' and len(args) >= 2:
        a, b = args[1]
        if not (b - a == 1 and toks[a].kind == 'num' and re.match(r'^4\d\d$', toks[a].val)):
            return None
        code = toks[a].val
        reason = None
        a0, b0 = args[0]
        if toks[a0].val == '[' and pairs.get(a0) == b0 - 1:
            for x, y in split_args(toks, a0 + 1, b0 - 1):
                if y - x == 3 and toks[x].kind == 'str' and toks[x].val == 'reason' and toks[x + 1].val == '=>' \
                        and toks[x + 2].kind == 'str':
                    reason = toks[x + 2].val
        return code, reason
    return None


def header_start(toks, pairs_rev, o):
    """블록 여는 괄호 o 의 머리(if (…) · else · function f(…) 등) 첫 토막 위치."""
    j = o - 1
    while j >= 0:
        t = toks[j]
        if t.kind == 'op' and t.val == ')' and j in pairs_rev:
            j = pairs_rev[j] - 1
            continue
        if t.kind == 'op' and t.val in (';', '{', '}'):
            return j + 1
        j -= 1
    return 0


def is_function_block(toks, pairs_rev, o):
    h = header_start(toks, pairs_rev, o)
    for j in range(h, o):
        if toks[j].kind == 'ident' and toks[j].val.lower() in ('function', 'fn'):
            return True
    return False


def main():
    ap = argparse.ArgumentParser(description='R2 감사 대조')
    ap.add_argument('--root', default=os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..')))
    ap.add_argument('--checks', default='all', choices=('all', 'table', 'deny'),
                    help='table = C1·C2·C3(호출·문장표·문서 대조) · deny = C4(거부 갈래) · all = 둘 다')
    ap.add_argument('--verbose', action='store_true')
    args = ap.parse_args()
    do_table = args.checks in ('all', 'table')
    do_deny = args.checks in ('all', 'deny')
    root = os.path.abspath(args.root)
    if not os.path.isdir(root):
        print('ERROR R2 트리가 없음: %s' % root)
        print('RESULT pass=0 fail=0 skip=1')
        return 2

    findings = []   # (심각도, 경로, 줄, 설명)
    checked = 0
    skipped = 0
    skip_notes = []

    files_toks = []
    for path in php_files(root):
        try:
            toks = lex_php(read_text(path))
        except Exception as e:  # 토막 나누기 실패 = 그 파일은 못 봄
            skipped += 1
            skip_notes.append('%s: 읽기 실패 %s' % (rel(root, path), e))
            continue
        files_toks.append((path, toks, match_brackets(toks)))

    ctx = Ctx()
    for path, toks, pairs in files_toks:
        collect_consts(ctx, toks, pairs)

    templates = find_templates(files_toks)
    if templates is None:
        print('ERROR R2 Audit 문장표(class Audit 안의 `a.b|allow…` 키 상수 배열)를 찾지 못함 — 검사 못 함')
        print('RESULT pass=0 fail=0 skip=1')
        return 2

    audit_path = os.path.abspath(next(iter(templates.values()))[1])

    # ── 감사하는 함수 모으기 (몸에 감사 호출이 있는 함수 — 클래스까지 붙여 푼다) ──
    #    이름만으로 풀면 `Audit::error` → `Http::error` 처럼 이름이 같은 다른 함수로 번진다.
    cls_ranges = [class_ranges(toks, pairs) for _, toks, pairs in files_toks]
    func_bodies = {}  # (클래스 또는 '', 소문자 이름) → [(파일 idx, 여는 괄호, 닫는 괄호)]
    for fi, (path, toks, pairs) in enumerate(files_toks):
        if os.path.abspath(path) == audit_path:
            continue   # Audit 자신의 내부 함수는 셈에서 뺀다(직접 호출로 이미 센다)
        for k in range(len(toks) - 2):
            if toks[k].kind == 'ident' and toks[k].val.lower() == 'function' and toks[k + 1].kind == 'ident':
                name = toks[k + 1].val
                j = k + 2
                while j < len(toks) and not (toks[j].kind == 'op' and toks[j].val in ('{', ';')):
                    j += 1
                if j < len(toks) and toks[j].val == '{' and j in pairs:
                    key = (class_at(cls_ranges[fi], k), name.lower())
                    func_bodies.setdefault(key, []).append((fi, j, pairs[j]))

    def call_key(fi, toks, j):
        """toks[j] 에서 시작하는 호출의 (클래스, 이름) — 모르면 None."""
        t = toks[j]
        if t.kind != 'ident' or j + 1 >= len(toks):
            return None
        prev = toks[j - 1].val if j > 0 else ''
        if toks[j + 1].val == '(' and prev not in ('::', '->', '?->', 'function', 'new') \
                and t.val.lower() not in ('if', 'elseif', 'while', 'for', 'foreach', 'switch', 'match', 'array',
                                          'isset', 'empty', 'unset', 'list', 'fn', 'function', 'return', 'echo'):
            return ('', short_class(t.val).lower())
        if j + 3 < len(toks) and toks[j + 1].val == '::' and toks[j + 2].kind == 'ident' and toks[j + 3].val == '(':
            cls = short_class(t.val)
            if cls.lower() in ('self', 'static'):
                cls = class_at(cls_ranges[fi], j)
            return (cls, toks[j + 2].val.lower())
        return None

    def this_call_key(fi, toks, j):
        # $this->m(  → (그 클래스, m)
        if toks[j].kind == 'var' and toks[j].val == 'this' and j + 3 < len(toks) and toks[j + 1].val == '->' \
                and toks[j + 2].kind == 'ident' and toks[j + 3].val == '(':
            return (class_at(cls_ranges[fi], j), toks[j + 2].val.lower())
        return None

    def direct_audit(toks, j):
        t = toks[j]
        return (t.kind == 'ident' and j + 3 < len(toks) and short_class(t.val) == 'Audit' and toks[j + 1].val == '::'
                and toks[j + 2].val in ('allow', 'deny', 'error') and toks[j + 3].val == '(')

    def body_audits(fi, o, c, known):
        toks = files_toks[fi][1]
        for k in range(o, c):
            if direct_audit(toks, k):
                return True
            ck = call_key(fi, toks, k) or this_call_key(fi, toks, k)
            if ck is not None and ck in known:
                return True
        return False

    auditing = set()
    for _ in range(6):
        changed = False
        for key, bodies in func_bodies.items():
            if key in auditing:
                continue
            if any(body_audits(fi, o, c, auditing) for fi, o, c in bodies):
                auditing.add(key)
                changed = True
        if not changed:
            break

    # ── C1 호출 → 문장표 ──
    for fi, (path, toks, pairs) in enumerate(files_toks if do_table else []):
        audit_file = os.path.abspath(path) == audit_path
        blocks = sorted(((o, c) for o, c in pairs.items() if toks[o].val == '{'), key=lambda x: -x[0])
        fcls = None
        prev_rev = {c: o for o, c in pairs.items()}
        fblocks = [(o, c) for o, c in blocks if is_function_block(toks, prev_rev, o)]

        def scope_of(pos, fblocks=fblocks, n=len(toks)):
            # 가장 안쪽 함수 몸(머리의 매개변수 포함 — 매개변수면 대입이 0번이라 모름이 된다), 없으면 파일 전체
            for o, c in fblocks:
                if o < pos < c:
                    return (header_start(toks, prev_rev, o), c)
            return (0, n)
        for k in range(len(toks) - 3):
            t = toks[k]
            if not (t.kind == 'ident' and short_class(t.val) == 'Audit' and toks[k + 1].val == '::'
                    and toks[k + 2].val in ('allow', 'deny', 'error') and toks[k + 3].val == '('):
                continue
            if audit_file:
                continue
            po = k + 3
            pc = pairs.get(po)
            if pc is None:
                continue
            result = toks[k + 2].val
            argv = split_args(toks, po + 1, pc)
            if not argv:
                continue
            if fcls is None:
                fcls = enclosing_class(toks, pairs, k) or ''
            acts = resolve(ctx, toks, argv[0][0], argv[0][1], fcls)
            if result == 'allow':
                reasons = resolve(ctx, toks, argv[2][0], argv[2][1], fcls) if len(argv) >= 3 else {None}
            else:
                reasons = resolve(ctx, toks, argv[1][0], argv[1][1], fcls) if len(argv) >= 2 else None
                if reasons is None and len(argv) >= 2 and argv[1][1] - argv[1][0] == 1 \
                        and toks[argv[1][0]].kind == 'var':
                    vname = toks[argv[1][0]].val
                    reasons = guard_values(ctx, toks, pairs, blocks, k, vname, fcls)
                    if reasons is None:
                        reasons = single_assignment(ctx, toks, pairs, scope_of(k), vname, fcls)
            if acts is None and argv[0][1] - argv[0][0] == 1 and toks[argv[0][0]].kind == 'var':
                vname = toks[argv[0][0]].val
                acts = guard_values(ctx, toks, pairs, blocks, k, vname, fcls)
                if acts is None:
                    acts = single_assignment(ctx, toks, pairs, scope_of(k), vname, fcls)
            if acts is None or reasons is None or None in acts:
                skipped += 1
                skip_notes.append('%s:%d 변수 action/사유 — 정적으로 모을 수 없음' % (rel(root, path), t.line))
                continue
            checked += 1
            missing = []
            for a in sorted(acts):
                for r in sorted(reasons, key=lambda x: '' if x is None else x):
                    key = a + '|' + result + ('' if r is None else '|' + r)
                    if key not in templates:
                        missing.append(key)
            if missing:
                findings.append(('중간', path, t.line,
                                 '감사 호출 키 %s 가 Audit 문장표에 없음 (기록 실패 → 요청 500)' % ', '.join(missing)))

    # ── C2·C3 문장표 ↔ 문서 표 ──
    doc_path, rows = find_doc_rows(root) if do_table else (None, [None])
    if not do_table:
        pass
    elif rows is None or not rows:
        skipped += 1
        skip_notes.append('ARCHITECTURE.md 감사 표 없음 — 문장표 ↔ 문서 대조 못 함')
    else:
        doc_keys = {}
        for key, text, no in rows:
            doc_keys.setdefault(key, (text, no))
        doc_actions = {k.split('|', 1)[0] for k in doc_keys}
        for key, (text, no) in sorted(doc_keys.items(), key=lambda x: x[1][1]):
            checked += 1
            if key not in templates:
                findings.append(('낮음', doc_path, no, '문서 감사 표의 키 %s 가 Audit 문장표에 없음' % key))
                continue
            ttext = templates[key][0]
            if text is not None and norm(text) != norm(ttext):
                findings.append(('낮음', templates[key][1], templates[key][2],
                                 '문장표 %s 의 문장이 문서 감사 표(ARCHITECTURE.md:%d)와 글자가 다름' % (key, no)))
        for key, (ttext, tpath, tline) in sorted(templates.items(), key=lambda x: x[1][2]):
            if key in doc_keys:
                continue
            if key.split('|', 1)[0] not in doc_actions:
                skipped += 1
                continue
            checked += 1
            findings.append(('낮음', tpath, tline, '문장표 키 %s 가 문서 감사 표(ARCHITECTURE.md)에 없음' % key))

    # ── C4 거부 갈래에 감사 없음 ──
    def is_audit_at(fi, toks, j, hi, helpers=True):
        """toks[j] 가 감사 호출인가. helpers=False 면 `Audit::deny|error` 직접 호출만(거부 기록)."""
        if not helpers:
            return direct_audit(toks, j) and toks[j + 2].val in ('deny', 'error')
        if direct_audit(toks, j):
            return True
        ck = call_key(fi, toks, j) or this_call_key(fi, toks, j)
        return ck is not None and ck in auditing

    # 파일마다 그 파일의 감사 action(직접 호출에서 모은 값) — 4xx 사유 응답을 「이 창구의 등록된 거부」로 좁힌다
    def file_actions(toks, pairs, fcls):
        acts = set()
        for k in range(len(toks) - 3):
            if direct_audit(toks, k) and (k + 3) in pairs:
                argv = split_args(toks, k + 4, pairs[k + 3])
                if argv:
                    v = resolve(ctx, toks, argv[0][0], argv[0][1], fcls)
                    if v:
                        acts |= {x for x in v if x}
        return acts

    for fi, (path, toks, pairs) in enumerate(files_toks if do_deny else []):
        if os.path.abspath(path) == audit_path:
            continue
        pairs_rev = {c: o for o, c in pairs.items()}
        blocks = sorted(((o, c) for o, c in pairs.items() if toks[o].val == '{'), key=lambda x: -x[0])
        # 감사하는 클로저 변수: $name = [static] function (…) [use (…)] { … 감사 … };
        closure_vars = set()
        for k in range(len(toks) - 3):
            if toks[k].kind == 'var' and toks[k + 1].val == '=':
                j = k + 2
                if toks[j].kind == 'ident' and toks[j].val.lower() == 'static':
                    j += 1
                if toks[j].kind == 'ident' and toks[j].val.lower() in ('function', 'fn'):
                    b = j
                    while b < len(toks) and not (toks[b].kind == 'op' and toks[b].val in ('{', ';')):
                        b += 1
                    if b < len(toks) and toks[b].val == '{' and b in pairs and body_audits(fi, b, pairs[b], auditing):
                        closure_vars.add(toks[k].val)
        fcls_all = ''
        acts_here = None
        func_blocks = [(o, c) for o, c in blocks if is_function_block(toks, pairs_rev, o)]

        def audits_at(j, hi, helpers=True):
            if is_audit_at(fi, toks, j, hi, helpers):
                return True
            return helpers and toks[j].kind == 'var' and toks[j].val in closure_vars and j + 1 < hi \
                and toks[j + 1].val == '(' and not (j + 2 < len(toks) and toks[j + 1].val == '=')

        def assigned_from_audit(var, lo, hi):
            """[lo, hi) 안에서 `… $var … = <감사하는 호출 포함> ;` 문장이 있나."""
            j = lo
            while j < hi:
                t = toks[j]
                if t.kind == 'op' and t.val == '=':
                    # 왼쪽: 문장 머리까지
                    a = j - 1
                    lhs_vars = set()
                    while a >= lo and not (toks[a].kind == 'op' and toks[a].val in (';', '{', '}')):
                        if toks[a].kind == 'var':
                            lhs_vars.add(toks[a].val)
                        a -= 1
                    if var in lhs_vars:
                        b = j + 1
                        depth = 0
                        while b < hi:
                            v = toks[b].val if toks[b].kind == 'op' else None
                            if v in ('(', '[', '{'):
                                depth += 1
                            elif v in (')', ']', '}'):
                                depth -= 1
                                if depth < 0:
                                    break
                            elif v == ';' and depth == 0:
                                break
                            if audits_at(b, hi):
                                return True
                            b += 1
                j += 1
            return False

        for k in range(len(toks) - 3):
            resp = is_response_deny(toks, pairs, k)
            if resp is None:
                continue
            code, reason = resp
            if code not in ('401', '403'):
                if reason is None:
                    continue
                if acts_here is None:
                    acts_here = file_actions(toks, pairs, fcls_all)
                if not any((a + '|deny|' + reason) in templates for a in acts_here):
                    continue
            checked += 1
            anc = [(o, c) for o, c in blocks if o < k < c]   # 안쪽부터
            covered = False
            # ① 가장 안쪽 블록: 응답 앞의 모든 것(안쪽 갈래 포함)
            lo = anc[0][0] + 1 if anc else 0
            for j in range(lo, k):
                if audits_at(j, k):
                    covered = True
                    break
            # ② 바깥 블록들: 그 블록의 **바로 그 층** 문장의 거부 감사만 — 그 층의 앞 문장은 이 응답까지 가는
            #    모든 길에 있다(지배). 형제 갈래 **속**은 다른 길이라 보지 않는다. 함수 몸에서 멈춘다
            if not covered and anc:
                child = anc[0]
                level = 1
                while True:
                    if is_function_block(toks, pairs_rev, child[0]):
                        break
                    parent = anc[level] if level < len(anc) else None
                    plo = parent[0] + 1 if parent else 0
                    phi = header_start(toks, pairs_rev, child[0])
                    depth = 0
                    for j in range(plo, phi):
                        t = toks[j]
                        if t.kind == 'op' and t.val == '{':
                            depth += 1
                        elif t.kind == 'op' and t.val == '}':
                            depth -= 1
                        elif depth == 0 and audits_at(j, phi, helpers=False):
                            covered = True
                            break
                    if covered or parent is None:
                        break
                    child = parent
                    level += 1
            # ③ 갈래를 연 조건의 변수가 감사하는 함수의 결과이거나(판정 함수가 스스로 거부를 기록),
            #    catch 갈래인데 try 몸이 감사하는 함수를 부른다(던지기 전에 기록)
            if not covered and anc:
                scope_lo = 0
                for o, c in func_blocks:
                    if o < k < c:
                        scope_lo = o + 1
                        break
                for o, c in anc:
                    if is_function_block(toks, pairs_rev, o):
                        break
                    h = header_start(toks, pairs_rev, o)
                    head = [toks[j] for j in range(h, o)]
                    if head and head[0].kind == 'ident' and head[0].val.lower() == 'catch':
                        # 바로 앞 try { … } 몸
                        tj = h - 1
                        while tj >= 0 and toks[tj].val == '}' and tj in pairs_rev:
                            to = pairs_rev[tj]
                            th = header_start(toks, pairs_rev, to)
                            if toks[th].kind == 'ident' and toks[th].val.lower() == 'try':
                                if any(audits_at(x, tj) for x in range(to + 1, tj)):
                                    covered = True
                                break
                            tj = th - 1
                    else:
                        for v in {t.val for t in head if t.kind == 'var'}:
                            if assigned_from_audit(v, scope_lo, h):
                                covered = True
                                break
                    if covered:
                        break
            if not covered:
                how = '서버 로그로만 남기고 ' if any(toks[j].kind == 'ident' and toks[j].val == 'error_log'
                                              for j in range(lo, k)) else ''
                what = code + (' reason=' + reason if reason else '')
                findings.append(('낮음', path, toks[k].line,
                                 '%s 거부 응답으로 끝나는 갈래에 감사 호출이 없음 (%s거부)' % (what, how)))

    fail_targets = set()
    for sev, path, line, msg in findings:
        print('FINDING %s %s %s:%d %s' % (RULE, sev, rel(root, path), line, msg))
        fail_targets.add((path, line))
    if args.verbose:
        print('INFO R2 감사하는 함수: ' + ', '.join(sorted((c + '::' if c else '') + f for c, f in auditing)))
        for s in skip_notes:
            print('SKIP R2 ' + s)
    npass = max(checked - len(fail_targets), 0)
    print('RESULT pass=%d fail=%d skip=%d' % (npass, len(findings), skipped))
    return 1 if findings else 0


def norm(s):
    return re.sub(r'\s+', ' ', s.replace('\\|', '|')).strip()


def rel(root, path):
    try:
        r = os.path.relpath(path, root)
    except ValueError:
        return path
    return path if r.startswith('..') else r


if __name__ == '__main__':
    try:
        sys.exit(main())
    except SystemExit:
        raise
    except Exception as e:  # 실행 오류 = 검사 못 함(H-1)
        print('ERROR R2 실행 오류: %s: %s' % (type(e).__name__, e))
        print('RESULT pass=0 fail=0 skip=1')
        sys.exit(2)
