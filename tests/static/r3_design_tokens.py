#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
R3 디자인 토큰 (design token · dead CSS · design-doc cross-check) — 1-F 정적 규칙, python3 stdlib 만, 파일은 읽기만.

answers: AK-0049 · AK-0051 · AK-0052 · AK-0096 (일부: AK-0050 · AK-0082 — 아래 「모르는 것」)

무엇을 부류로 잡나:
  화면 모양의 규칙은 세 곳이 서로 맞아야 한다 — ① 설계 문서(DESIGN.md)의 토큰·구성 요소 표, ② CSS 의
  `:root` 토큰(사용자 정의 속성), ③ CSS 규칙과 그 규칙을 쓰는 마크업·JS. 이 규칙은 파일 이름이나 특정 클래스
  이름이 아니라 「값이 토큰 밖인가」「선택자가 쓰이는가」「문서와 코드가 같은 것을 말하나」로 어긋남을 센다.
    T1 글자 크기 밖   : `font-size`·`font` 의 리터럴 크기(px·rem·pt 는 px 로, em·%·낱말 크기는 그대로)가 문서의
                        크기 사다리 밖이다. 사다리는 제목에 글자·서체·타이포·font·type 이 든 절의 몸에서 읽는다 —
                        금지 문장(「쓰지 않는다」 등)이 아닌 문장의 `Npx`·`a · b · c` px 목록과, 그 절 표의 크기 열
                        (머리 「칸」·「크기」·size). 금지 문장이 이름을 든 값은 언제나 밖이다. `:root` 의 글자 크기
                        토큰 값(그 토큰이 font-size 에 var() 로 쓰일 때)은 사다리에 더한다(금지 값은 빼고).
                        문서에 사다리가 없으면 전체 CSS 의 고유 크기 수가 SCALE_CAP 을 넘을 때 요약 한 줄로
                        알린다(자리는 가장 드문 값 — 파일마다 상한을 맞추지 않는다).
    T2 굵기 밖        : `font-weight`·`font` 의 굵기(`bold`=700 · `normal`=400 · bolder/lighter 는 밖)가 「글자」
                        절의 굵기 문장·굵기 열이 허용한 값 밖이다. 문서에 굵기 규칙이 없으면 보지 않는다.
    T3 색 리터럴      : 토큰 블록(`:root`·`html`·속성 선택자·`:not()` 만으로 된 선택자 — 다크 모드 블록 포함)의
                        사용자 정의 속성 밖에서 색 값을 직접 쓴다(`#hex`·`rgb()`·`hsl()` 류, 색 속성의 색 이름).
                        빼는 것: 투명도가 있는 순수 검정·흰색(그림자·덮개 — 색상이 없다), `rgb(var(--x) / .5)`
                        처럼 토큰에 투명도만 얹은 것, 규칙 바로 위(빈 줄 없이)·규칙 안·같은 줄 주석이 「토큰을
                        쓰지 않는」 이유를 적었거나 `qcheck-allow: R3` 표시가 있는 것(skip 으로 센다).
    T4 정의 없는 토큰 : CSS 의 `var(--x)`(대체값 없음)가 어느 CSS 에도 정의되지 않았고 코드도 그 이름을 쓰지 않는다
                        (선언이 무효가 된다). 또 문서가 백틱으로 적은 토큰 `--x`(이력 절 제외)가 CSS 에 없다 —
                        이름 그대로도, `--x-…` 계열로도, `-*`·`<…>` 틀로도 없고, BEM 변형(`.a--x`)의 줄임말도 아니다.
    U1 쓰이지 않는 클래스 : CSS 선택자가 반드시 요구하는 클래스(`:not()`·`:is()`·`:where()`·`:has()`·속성 선택자
                        안은 빼고)가 마크업·JS·PHP·JSON 어디에도 낱말로 나오지 않는다. 문자열을 이어 붙여 만드는
                        클래스는 앞 조각이 코드에 있으면 쓰이는 것으로 보고 skip 으로 센다 — 앞 조각은 `+`(JS)·
                        `.`(PHP) 바로 앞 따옴표 문자열의 끝 낱말(`'doc-h' + n`), 보간 바로 앞 낱말(`` `nt-${c}` ``·
                        `"sz-{$s}"`·`badge--<?= … ?>`), 세 글자 이상. 클래스마다 CSS 파일별 한 줄. 모든 선택자가
                        죽은 규칙의 값(T1~T3)은 다시 알리지 않는다(U1 한 줄로 — unused 를 함께 돌릴 때).
    D1 문서에 없는 변형 : 문서 제목이 백틱으로 이름을 든 구성 요소(`### 2.2 배지 \`.badge\`` 류)의 BEM 변형
                        클래스(`.badge--x`)가 CSS 에 있는데 문서 어디에도 없다(배지·단추 표 누락). 문서의 틀 언급
                        (`.badge--status-*`·`.nt-<color>`)과 같은 줄의 줄임말(`.x` … `` `--m` ``)은 적힌 것으로 본다.
    D2 문서의 옛 클래스 : 문서가 백틱으로 적은 클래스가 CSS 에도 마크업·JS 에도 없다(이름을 바꾼 뒤 문서 표가 남음).
                        이력 절, 금지 문장이 든 줄(「만들지 않는다」 예시), 지운 것을 말하는 줄(✅·삭제·제거·예전 …),
                        파일 이름 모양(`app.css`)은 빼고, 틀 언급(`.x-*`·`.x__…`)은 앞 조각으로 대조한다.
  모르는 것(판단·시각이라 보지 않음): 색·크기가 뜻에 맞는지(「이 배지는 초록이어야」), 뜻이 다른 클래스를 빌려
  쓰는지(AK-0082), 화면 전용 마크업·클래스인지(AK-0050 — 쓰이고 있으면 U1 도 모른다), 문서의 산문 예외
  (「로그인 좌측 판은 값 고정」 처럼 선택자 없이 적은 것 — T3 오탐이 된다). 여백(padding·margin·gap)은 문서·토큰에
  여백 사다리가 없어 리터럴 검사를 하지 않는다(값 분포가 결함 전후로 같아 소음만 낸다). 코드 낱말은 주석까지
  세므로 주석에만 남은 클래스 이름은 「쓰임」으로 보인다(놓침 쪽 — 오탐을 만들지 않는다).

입력: --root <트리>(기본: 이 파일 기준 저장소 뿌리) · --design <문서>(기본 <root>/DESIGN.md — 없으면 문서 없이
      T1 상한 방식, 명시했는데 없으면 rc 2) · --checks all|tokens|colors|unused|doc (쉼표로 여럿. tokens = T1·T2·T4,
      colors = T3, unused = U1, doc = D1·D2 — 정밀도가 달라 카탈로그에 나눠 올릴 수 있게) · --verbose(SKIP·INFO 줄).
      CSS 는 트리의 `*.css`(.git·tests·docs·vendor·node_modules 제외 — 인라인 <style> 은 보지 않는다), 마크업·코드는
      `*.php·*.phtml·*.html·*.htm·*.js·*.mjs·*.json·*.svg`(.git·tests·docs·node_modules 제외, vendor 포함 — 라이브러리가
      붙이는 클래스도 「쓰임」이다).
출력: FINDING R3 <심각도> <파일:줄> <설명> · 끝 줄 RESULT pass=<대상 - 발견 대상> fail=<발견 수> skip=<못 본 대상>
      대상 = 크기·굵기 선언, 색을 쓰는 선언, var() 참조, 문서 토큰·클래스 언급, CSS 클래스(파일별), 구성 요소 변형.
      skip = 읽지 못한 CSS · 이어 붙인 클래스 · 예외 주석이 붙은 색 · 죽은 규칙(값 검사 생략).
rc:   0 발견 없음 · 1 발견 있음 · 2 실행 오류(뿌리 없음·CSS 를 하나도 못 읽음·--design 없음 = 검사 못 함)
"""
import argparse
import os
import re
import sys

RULE = 'R3'
SCALE_CAP = 8          # 문서에 사다리가 없을 때 한 앱의 고유 글자 크기 수 상한(흔한 타이포 사다리 6~8칸)
CSS_SKIP_DIRS = {'.git', 'tests', 'docs', 'vendor', 'node_modules'}
CODE_SKIP_DIRS = {'.git', 'tests', 'docs', 'node_modules'}
CODE_EXT = {'.php', '.phtml', '.html', '.htm', '.js', '.mjs', '.json', '.svg'}
ALLOW_MARK = re.compile(r'qcheck-allow:\s*R3\b')
# 주석이 「토큰을 쓰지 않는다」는 이유를 적었는가 — 토큰 낱말 + 부정·예외 낱말
REASON_RE = re.compile(r'(토큰|token)[^\n.。]{0,6}(쓰지 않|안 쓴|안 쓰|쓰지 말|대신|예외|밖)|값\s*고정|'
                       r'(not|never)\s+(use\s+)?(the\s+)?(colou?r\s+)?tokens?|without\s+(colou?r\s+)?tokens?', re.I)
SEL_SPAN = re.compile(r'^(?:[a-z][a-z0-9]*)?(?:\.[-_a-zA-Z][-_a-zA-Z0-9]*)+$')

PROHIBIT = re.compile(r'쓰지 않|쓰지 말|금지|않는다|말 것|말고|피한다|없앤다|don\'?t|do not|never|avoid|forbid', re.I)
HEAD_TYPO = re.compile(r'글자|서체|타이포|typograph|font|type', re.I)
HEAD_HISTORY = re.compile(r'이력|history|changelog|변경 기록', re.I)
REMOVAL = re.compile(r'✅|삭제|지운|지웠|지워|지운다|제거|없앤|없앴|바꿨|바뀜|옛|예전|removed|deleted|renamed|deprecated|legacy', re.I)
FILE_SPAN = re.compile(r'\.(css|js|mjs|php|md|json|html?|sh|py|sql|txt|ya?ml|xml|svg|png|webmanifest)\b', re.I)
WEIGHT_WORD = re.compile(r'굵기|weight', re.I)
SIZE_COL_WORD = re.compile(r'^(칸|크기|size|글자 크기|px)$', re.I)

NEUTRAL_SIZE = {'inherit', 'initial', 'unset', 'revert', 'revert-layer', '1em', '100%'}
ABS_SIZE_KW = {'xx-small', 'x-small', 'small', 'medium', 'large', 'x-large', 'xx-large', 'xxx-large', 'smaller', 'larger'}
NEUTRAL_WEIGHT = {'inherit', 'initial', 'unset', 'revert', 'revert-layer'}
COLOR_PROPS = re.compile(r'^(color|background(-color)?|border(-(top|right|bottom|left|block|inline)(-(start|end))?)?(-color)?|'
                         r'outline(-color)?|box-shadow|text-shadow|fill|stroke|caret-color|accent-color|'
                         r'text-decoration(-color)?|text-emphasis(-color)?|column-rule(-color)?|scrollbar-color|'
                         r'-webkit-tap-highlight-color|-webkit-text-fill-color|-webkit-text-stroke(-color)?|'
                         r'flood-color|lighting-color|stop-color|filter)$')
NAMED_COLORS = set('''aliceblue antiquewhite aqua aquamarine azure beige bisque black blanchedalmond blue blueviolet brown
burlywood cadetblue chartreuse chocolate coral cornflowerblue cornsilk crimson cyan darkblue darkcyan darkgoldenrod
darkgray darkgreen darkgrey darkkhaki darkmagenta darkolivegreen darkorange darkorchid darkred darksalmon darkseagreen
darkslateblue darkslategray darkslategrey darkturquoise darkviolet deeppink deepskyblue dimgray dimgrey dodgerblue
firebrick floralwhite forestgreen fuchsia gainsboro ghostwhite gold goldenrod gray green greenyellow grey honeydew
hotpink indianred indigo ivory khaki lavender lavenderblush lawngreen lemonchiffon lightblue lightcoral lightcyan
lightgoldenrodyellow lightgray lightgreen lightgrey lightpink lightsalmon lightseagreen lightskyblue lightslategray
lightslategrey lightsteelblue lightyellow lime limegreen linen magenta maroon mediumaquamarine mediumblue mediumorchid
mediumpurple mediumseagreen mediumslateblue mediumspringgreen mediumturquoise mediumvioletred midnightblue mintcream
mistyrose moccasin navajowhite navy oldlace olive olivedrab orange orangered orchid palegoldenrod palegreen
paleturquoise palevioletred papayawhip peru pink plum powderblue purple rebeccapurple red rosybrown royalblue
saddlebrown salmon sandybrown seagreen seashell sienna silver skyblue slateblue slategray slategrey snow springgreen
steelblue tan teal thistle tomato turquoise violet wheat white whitesmoke yellow yellowgreen'''.split())
HEX_RE = re.compile(r'(?<![\w-])#([0-9a-fA-F]{3,8})\b')
COLOR_FN_RE = re.compile(r'\b(rgba?|hsla?|hwb|lab|lch|oklab|oklch|color)\(([^()]*(?:\([^()]*\)[^()]*)*)\)', re.I)
VAR_RE = re.compile(r'var\(\s*(--[A-Za-z0-9_-]+)\s*(,)?')
CLASS_IN_SEL = re.compile(r'\.(-?[_a-zA-Z][_a-zA-Z0-9-]*)')
WORD_RE = re.compile(r'-?[A-Za-z_][A-Za-z0-9_-]*')
DOC_CLASS_RE = re.compile(r'\.(-?[_a-zA-Z][_a-zA-Z0-9-]*)')
FUNC_PSEUDO = re.compile(r':(not|is|where|has|matches|-webkit-any|-moz-any|host|host-context|nth-child|nth-last-child|'
                         r'nth-of-type|nth-last-of-type|lang|dir|state)\(', re.I)


def die(msg):
    print('ERROR %s %s' % (RULE, msg), file=sys.stderr)
    print('RESULT pass=0 fail=0 skip=1')
    sys.exit(2)


# ─────────────────────────── CSS 읽기 ───────────────────────────
def strip_css_comments(text):
    """주석을 같은 길이의 공백(줄바꿈은 유지)으로 바꾼 글과, 주석 목록 [(시작 줄, 끝 줄, 끝 오프셋, 글)]."""
    out, comments = [], []
    i, n, line = 0, len(text), 1
    while i < n:
        c = text[i]
        if c == '/' and text.startswith('/*', i):
            j = text.find('*/', i + 2)
            j = n if j < 0 else j + 2
            body = text[i:j]
            comments.append((line, line + body.count('\n'), j, body))
            out.append(''.join('\n' if ch == '\n' else ' ' for ch in body))
            line += body.count('\n')
            i = j
            continue
        if c in '"\'':
            j = i + 1
            while j < n and text[j] != c and text[j] != '\n':
                if text[j] == '\\':
                    j += 1
                j += 1
            s = text[i:j + 1]
            out.append(s)
            line += s.count('\n')
            i = j + 1
            continue
        out.append(c)
        if c == '\n':
            line += 1
        i += 1
    return ''.join(out), comments


def css_segments(clean):
    """주석 뺀 CSS → [(글, 첫 글자 줄, 끝 오프셋, 구분자)] 구분자는 '{' '}' ';' 또는 '' (끝)."""
    segs = []
    i, n, line = 0, len(clean), 1
    start, start_line, depth = 0, None, 0
    while i < n:
        c = clean[i]
        if c in '"\'':
            j = i + 1
            while j < n and clean[j] != c and clean[j] != '\n':
                if clean[j] == '\\':
                    j += 1
                j += 1
            if start_line is None:
                start_line = line
            line += clean[i:j + 1].count('\n')
            i = j + 1
            continue
        if c == '(':
            depth += 1
        elif c == ')':
            depth = max(0, depth - 1)
        if depth == 0 and c in '{};':
            segs.append((clean[start:i], start_line if start_line is not None else line, i, c))
            start, start_line = i + 1, None
        elif not c.isspace() and start_line is None:
            start_line = line
        if c == '\n':
            line += 1
        i += 1
    if clean[start:].strip():
        segs.append((clean[start:], start_line or line, n, ''))
    return segs


class Rule:
    __slots__ = ('sel', 'line', 'start', 'end', 'at', 'decls', 'keyframe')

    def __init__(self, sel, line, start, at, keyframe=False):
        self.sel, self.line, self.start, self.at, self.keyframe = sel, line, start, at, keyframe
        self.end = start
        self.decls = []


NEST_AT = {'media', 'supports', 'container', 'layer', 'document', '-moz-document', 'scope', 'starting-style'}


def parse_css(text):
    """CSS → (rules, comments). rules 는 선언 블록(보통 규칙·키프레임 칸·@font-face 류) 목록."""
    clean, comments = strip_css_comments(text)
    rules = []
    stack = [('top', None)]
    for seg, line, off, delim in css_segments(clean):
        kind, cur = stack[-1]
        s = seg.strip()
        if delim == '{':
            if s.startswith('@'):
                name = re.match(r'@([-\w]+)', s)
                name = name.group(1).lower() if name else ''
                if name in NEST_AT:
                    at = (cur.at if isinstance(cur, Rule) else (cur or ())) + (s,)
                    stack.append(('at', at))
                elif name.endswith('keyframes'):
                    stack.append(('keyframes', (cur or ()) if not isinstance(cur, Rule) else cur.at))
                else:
                    r = Rule(s, line, off, (cur or ()) if not isinstance(cur, Rule) else cur.at)
                    r.keyframe = True       # @font-face·@page 류 — 선택자가 아니다
                    stack.append(('rule', r))
            elif kind == 'rule':
                # CSS 중첩 — 부모 선택자에 붙인다
                parent = cur
                if '&' in s:
                    sel = ', '.join(s.replace('&', p.strip()) for p in split_top(parent.sel))
                else:
                    sel = ', '.join('%s %s' % (p.strip(), x.strip()) for p in split_top(parent.sel) for x in split_top(s))
                stack.append(('rule', Rule(sel, line, off, parent.at)))
            elif kind == 'keyframes':
                stack.append(('rule', Rule(s, line, off, cur or (), keyframe=True)))
            else:
                stack.append(('rule', Rule(s, line, off, cur or ())))
        elif delim == ';' or (delim == '}' and s):
            if kind == 'rule' and s:
                k = s.find(':')
                if k > 0:
                    prop = s[:k].strip()
                    val = s[k + 1:].strip()
                    cur.decls.append((prop if prop.startswith('--') else prop.lower(), val, line))
        if delim == '}':
            if len(stack) == 1:
                raise ValueError('닫는 } 가 남는다(%d줄)' % line)
            kind, cur = stack.pop()
            if kind == 'rule':
                cur.end = off
                rules.append(cur)
    if len(stack) != 1:
        raise ValueError('닫히지 않은 블록 %d개' % (len(stack) - 1))
    return rules, comments


def split_top(sel):
    """선택자 목록을 괄호 밖 쉼표로 나눈다."""
    parts, depth, buf = [], 0, []
    for ch in sel:
        if ch in '([':
            depth += 1
        elif ch in ')]':
            depth = max(0, depth - 1)
        if ch == ',' and depth == 0:
            parts.append(''.join(buf))
            buf = []
        else:
            buf.append(ch)
    parts.append(''.join(buf))
    return [p for p in parts if p.strip()]


def remove_func_pseudo(sel):
    """`:not(…)`·`:is(…)` 류와 속성 선택자 `[…]` 를 지운 선택자(요구 클래스만 남는다)."""
    out, i, n = [], 0, len(sel)
    while i < n:
        if sel[i] == '[':
            j = sel.find(']', i)
            i = n if j < 0 else j + 1
            continue
        m = FUNC_PSEUDO.match(sel, i)
        if m:
            depth, j = 1, m.end()
            while j < n and depth:
                if sel[j] == '(':
                    depth += 1
                elif sel[j] == ')':
                    depth -= 1
                j += 1
            i = j
            continue
        out.append(sel[i])
        i += 1
    return ''.join(out)


def is_token_block(sel):
    """`:root`·`html`·속성 선택자·`:not(...)` 만으로 된 선택자 목록인가(토큰·다크 모드 블록)."""
    for part in split_top(sel):
        s = re.sub(r'\[[^\]]*\]', '', part)
        s = re.sub(r':not\([^)]*\)', '', s)
        s = re.sub(r':root|\bhtml\b|:host', '', s)
        if s.strip():
            return False
    return True


def required_classes(sel):
    out = []
    for part in split_top(sel):
        out.extend(CLASS_IN_SEL.findall(remove_func_pseudo(part)))
    return out


# ─────────────────────────── 값 읽기 ───────────────────────────
def strip_strings_urls(v):
    v = re.sub(r'"(?:[^"\\]|\\.)*"|\'(?:[^\'\\]|\\.)*\'', '""', v)
    v = re.sub(r'url\([^)]*\)', 'url()', v, flags=re.I)
    return v


def top_tokens(v):
    toks, depth, buf = [], 0, []
    for ch in v:
        if ch == '(':
            depth += 1
        elif ch == ')':
            depth = max(0, depth - 1)
        if ch.isspace() and depth == 0:
            if buf:
                toks.append(''.join(buf))
                buf = []
        else:
            buf.append(ch)
    if buf:
        toks.append(''.join(buf))
    return toks


SIZE_TOKEN = re.compile(r'^(\d*\.?\d+)(px|rem|em|%|pt)$', re.I)


def norm_size(tok):
    """크기 토막 → ('px', 숫자) · ('rel', 글) · ('kw', 글) · ('neutral', 글) · ('skip', 글) · None."""
    t = tok.strip().lower().replace('!important', '').strip()
    if not t:
        return None
    if t in NEUTRAL_SIZE:
        return ('neutral', t)
    if 'var(' in t or 'calc(' in t or 'clamp(' in t or 'min(' in t or 'max(' in t or 'env(' in t:
        return ('skip', t)
    if t in ABS_SIZE_KW:
        return ('kw', t)
    m = SIZE_TOKEN.match(t)
    if not m:
        return None
    num, unit = float(m.group(1)), m.group(2)
    if unit == 'px':
        return ('px', num)
    if unit == 'rem':
        return ('px', num * 16)
    if unit == 'pt':
        return ('px', round(num * 4 / 3, 2))
    return ('rel', t)


def fmt_px(x):
    return ('%g' % x) + 'px'


def weight_value(tok):
    t = tok.strip().lower().replace('!important', '').strip()
    if t in NEUTRAL_WEIGHT:
        return ('neutral', t)
    if 'var(' in t or 'calc(' in t:
        return ('skip', t)
    if t == 'normal':
        return ('num', 400)
    if t == 'bold':
        return ('num', 700)
    if t in ('bolder', 'lighter'):
        return ('rel', t)
    if re.match(r'^\d{1,4}$', t) and 1 <= int(t) <= 1000:
        return ('num', int(t))
    return None


def font_shorthand(v):
    """`font` 값 → (굵기 토막 또는 None, 크기 토막 또는 None). `font: inherit` 류는 (None, None).
    문법: [style] [variant] [weight] [stretch] size[/line-height] family — 크기 앞의 숫자·굵기 낱말이 굵기다."""
    toks = top_tokens(strip_strings_urls(v).replace('!important', ''))
    if len(toks) <= 1:
        return None, None
    weight = None
    for t in toks:
        base = t.split('/')[0]
        ns = norm_size(base)
        if ns and ns[0] in ('px', 'rel', 'kw', 'skip') and ('/' in t or ns[0] != 'kw' or t is not toks[-1]):
            return weight, (base if ns[0] != 'skip' else None)
        wv = weight_value(t)
        if wv and wv[0] in ('num', 'rel'):
            weight = t
    return weight, None


def neutral_shade(fn, args):
    """투명도가 있는 순수 검정·흰색(그림자·뒷배경의 덮개)은 색상이 없어 테마 색이 아니다."""
    nums = re.findall(r'\d*\.?\d+%?', args)
    if fn.lower() not in ('rgb', 'rgba') or len(nums) != 4:
        return False
    rgb, alpha = nums[:3], nums[3]
    a = float(alpha[:-1]) / 100 if alpha.endswith('%') else float(alpha)
    vals = set(x.rstrip('%') for x in rgb)
    return a < 1 and (vals == {'0'} or vals == {'255'} or vals == {'100'} and all(x.endswith('%') for x in rgb))


def color_literals(prop, v):
    """선언 값 안의 색 리터럴 목록(투명 검정·흰색 덮개는 뺀다)."""
    v2 = strip_strings_urls(v)
    found = []
    for m in HEX_RE.finditer(v2):
        h = m.group(1)
        if len(h) not in (3, 4, 6, 8):
            continue
        if len(h) in (4, 8):
            full = ''.join(c * 2 for c in h) if len(h) == 4 else h
            if full[:6].lower() in ('000000', 'ffffff') and full[6:].lower() != 'ff':
                continue
        found.append('#' + h)
    for m in COLOR_FN_RE.finditer(v2):
        fn, args = m.group(1), m.group(2)
        rest = re.sub(r'var\([^()]*(\([^()]*\))?[^()]*\)', '', args)
        if 'var(' in args and not re.search(r'\d', re.sub(r'/\s*[\d.]+%?', '', rest)):
            continue          # rgb(var(--x) / .5) 처럼 토큰에 투명도만 얹은 것
        if neutral_shade(fn, args):
            continue
        found.append(m.group(0))
    if COLOR_PROPS.match(prop) or prop.startswith('--'):
        for t in re.findall(r'(?<![\w#(-])([a-zA-Z]+)(?![\w(-])', COLOR_FN_RE.sub(' ', v2)):
            if t.lower() in NAMED_COLORS:
                found.append(t)
    return found


# ─────────────────────────── 문서 읽기 ───────────────────────────
def md_sections(lines):
    """[(제목 줄 번호, 단계, 제목 글, 시작 idx, 끝 idx)]"""
    heads = []
    in_code = False
    for i, l in enumerate(lines):
        if l.lstrip().startswith('```'):
            in_code = not in_code
            continue
        if in_code:
            continue
        m = re.match(r'^(#{1,6})\s+(.*)$', l)
        if m:
            heads.append((i, len(m.group(1)), m.group(2)))
    out = []
    for k, (i, lv, t) in enumerate(heads):
        end = len(lines)
        for j, lv2, _ in heads[k + 1:]:
            if lv2 <= lv:
                end = j
                break
        out.append((i + 1, lv, t, i, end))
    return out


NUM_RE = re.compile(r'(?<![\d.\-])(\d+(?:\.\d+)?)(?![\d.])')
DATE_RE = re.compile(r'\d{4}-\d{2}-\d{2}')


def sentences(text):
    return [s for s in re.split(r'(?<=[.!?。])\s+|(?<=다\.)|\n', text) if s.strip()]


def px_mentions(sent):
    """문장 속 글자 크기 언급: `Npx` 와 `a · b · c` px 목록."""
    s = DATE_RE.sub(' ', sent).replace('`', '').replace('*', '')
    vals = set()
    for m in re.finditer(r'((?:\d+(?:\.\d+)?\s*[·,/]\s*)*\d+(?:\.\d+)?)\s*px\b', s):
        for x in re.findall(r'\d+(?:\.\d+)?', m.group(1)):
            vals.add(float(x))
    return vals


def read_design(path):
    """문서 → dict(sizes, banned, weights, typo_line, components, tokens[(줄, 이름)], text)."""
    info = {'sizes': set(), 'banned': set(), 'weights': set(), 'typo_line': None,
            'components': {}, 'tokens': [], 'text': '', 'path': path}
    if not path or not os.path.isfile(path):
        return None
    with open(path, encoding='utf-8', errors='replace') as f:
        text = f.read()
    info['text'] = text
    lines = text.split('\n')
    secs = md_sections(lines)
    history_ranges = [(a, b) for (_, _, t, a, b) in secs if HEAD_HISTORY.search(t)]

    for (hline, lv, title, a, b) in secs:
        # 구성 요소: 제목의 백틱 속 클래스
        for span in re.findall(r'`([^`]+)`', title):
            if SEL_SPAN.match(span.strip()):
                for c in DOC_CLASS_RE.findall(span):
                    info['components'].setdefault(c, hline)
        if not HEAD_TYPO.search(re.sub(r'`[^`]*`', '', title)):
            continue
        # 「글자」 절 — 이 제목 바로 아래 몸(하위 절 전까지)
        body_end = b
        for (h2, lv2, _, a2, _) in secs:
            if a < a2 < b:
                body_end = a2
                break
        body = lines[a + 1:body_end]
        before = (len(info['sizes']), len(info['weights']), len(info['banned']))
        table_cols = None
        for l in body:
            st = l.strip()
            if st.startswith('|'):
                cells = [c.strip() for c in st.strip('|').split('|')]
                if all(re.match(r'^:?-{2,}:?$', c) for c in cells if c):
                    continue
                if table_cols is None:
                    table_cols = cells
                    continue
                for ci, c in enumerate(cells):
                    head = table_cols[ci] if ci < len(table_cols) else ''
                    if SIZE_COL_WORD.match(head.replace('*', '').strip()):
                        for x in NUM_RE.findall(DATE_RE.sub(' ', c)):
                            info['sizes'].add(float(x))
                    if WEIGHT_WORD.search(head):
                        for x in NUM_RE.findall(c):
                            if float(x).is_integer() and 100 <= int(float(x)) <= 900 and int(float(x)) % 100 == 0:
                                info['weights'].add(int(float(x)))
                continue
            table_cols = None
            for sent in sentences(st):
                if PROHIBIT.search(sent):
                    s2 = DATE_RE.sub(' ', sent).replace('`', '')
                    for x in NUM_RE.findall(s2):
                        fx = float(x)
                        if 4 <= fx <= 96:
                            info['banned'].add(fx)
                    continue
                info['sizes'] |= px_mentions(sent)
                if WEIGHT_WORD.search(sent):
                    for x in NUM_RE.findall(sent.replace('`', '')):
                        fx = float(x)
                        if fx.is_integer() and 100 <= fx <= 900 and int(fx) % 100 == 0:
                            info['weights'].add(int(fx))
        if info['typo_line'] is None and before != (len(info['sizes']), len(info['weights']), len(info['banned'])):
            info['typo_line'] = hline
    info['sizes'] -= info['banned']

    # 문서가 적은 클래스 — 백틱 안의 선택자 모양(이력 절·금지 문장·지운 것을 말하는 줄 제외)
    info['classes'] = []
    in_code = False
    for i, l in enumerate(lines):
        if l.lstrip().startswith('```'):
            in_code = not in_code
            continue
        if in_code or any(a <= i < b for a, b in history_ranges):
            continue
        if REMOVAL.search(l) or PROHIBIT.search(l):
            continue
        for span in re.findall(r'`([^`]+)`', l):
            if '/' in span.replace('th/td', '') or FILE_SPAN.search(span) or '(' in span:
                continue
            for m in re.finditer(r'(?<![\w-])\.(-?[_a-zA-Z][_a-zA-Z0-9-]*)(\*|<[^>]*>|…|\.\.\.)?', span):
                name, tail = m.group(1), m.group(2)
                wild = bool(tail) or name.endswith('-') or name.endswith('_')
                info['classes'].append((i + 1, name, wild, name + (tail or '')))

    # 문서가 적은 토큰 이름 — 백틱 안의 `--x`(이력 절 제외)
    in_code = False
    for i, l in enumerate(lines):
        if l.lstrip().startswith('```'):
            in_code = not in_code
            continue
        if in_code or any(a <= i < b for a, b in history_ranges):
            continue
        for span in re.findall(r'`([^`]+)`', l):
            for m in re.finditer(r'(?<![\w.-])(--[A-Za-z0-9_-]*(?:\*|<[^>]*>[A-Za-z0-9_-]*)?)', span):
                name = m.group(1)
                if len(name) > 2:
                    info['tokens'].append((i + 1, name, l))
    return info


# ─────────────────────────── 파일 모으기 ───────────────────────────
def walk(root, skip_dirs, exts):
    out = []
    for dp, dns, fns in os.walk(root):
        rel_dp = os.path.relpath(dp, root)
        dns[:] = sorted(d for d in dns if d not in skip_dirs and not os.path.islink(os.path.join(dp, d)))
        for fn in sorted(fns):
            p = os.path.join(dp, fn)
            if os.path.islink(p):
                continue
            if os.path.splitext(fn)[1].lower() in exts:
                out.append(os.path.normpath(os.path.join(rel_dp, fn)) if rel_dp != '.' else fn)
    return out


def read_text(p):
    with open(p, 'rb') as f:
        raw = f.read()
    return raw.decode('utf-8', errors='replace')


FRAG_TAIL = re.compile(r'(-?[A-Za-z_][A-Za-z0-9_-]*)$')
QUOTED_CONCAT = re.compile(r"""(['"])((?:\\.|(?!\1)[^\\\n])*)\1\s*(?:\+|\.(?![\d.]))""")
INTERP_BEFORE = re.compile(r'(-?[A-Za-z_][A-Za-z0-9_-]*)(?=\$\{|\{\$|\$[A-Za-z_]|<\?)')


def concat_prefixes(t):
    """문자열을 이어 붙여 클래스를 만드는 곳의 앞 조각.
    · 따옴표 문자열 바로 뒤에 `+`(JS) · `.`(PHP) 가 오면 그 문자열의 끝 낱말(`'doc-h' + n` → doc-h)
    · 보간 바로 앞의 낱말(`` `nt-${c}` `` · `"sz-{$s}"` · `"a-$x"` · `badge--<?= … ?>`)
    앞 조각이 세 글자 미만이면 버린다(아무 클래스나 덮지 않게)."""
    out = set()
    for m in QUOTED_CONCAT.finditer(t):
        body = m.group(2)
        mt = FRAG_TAIL.search(body)
        if mt and len(mt.group(1)) >= 3:
            out.add(mt.group(1))
    for m in INTERP_BEFORE.finditer(t):
        if len(m.group(1)) >= 3:
            out.add(m.group(1))
    return out


# ─────────────────────────── 본체 ───────────────────────────
def main():
    ap = argparse.ArgumentParser(description='R3 디자인 토큰 · 쓰이지 않는 CSS · DESIGN 대조')
    ap.add_argument('--root', default=os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..')))
    ap.add_argument('--design', default=None, help='설계 문서(기본 <root>/DESIGN.md)')
    ap.add_argument('--checks', default='all')
    ap.add_argument('--verbose', action='store_true')
    a = ap.parse_args()
    root = os.path.abspath(a.root)
    if not os.path.isdir(root):
        die('뿌리가 없다: %s' % root)
    checks = set()
    for c in a.checks.split(','):
        c = c.strip()
        if c == 'all':
            checks |= {'tokens', 'colors', 'unused', 'doc'}
        elif c in ('tokens', 'colors', 'unused', 'doc'):
            checks.add(c)
        elif c:
            die('모르는 --checks 값: %s' % c)

    design_path = os.path.abspath(a.design or os.path.join(root, 'DESIGN.md'))
    if a.design and not os.path.isfile(design_path):
        die('--design 문서가 없다: %s' % design_path)
    design = read_design(design_path)
    drel = os.path.relpath(design_path, root) if design_path.startswith(root + os.sep) else design_path

    css_files = walk(root, CSS_SKIP_DIRS, {'.css'})
    parsed = {}
    skipped = 0
    skip_notes = []
    for rel in css_files:
        try:
            parsed[rel] = parse_css(read_text(os.path.join(root, rel)))
        except (OSError, ValueError) as e:
            skipped += 1
            skip_notes.append('%s 읽기 실패: %s' % (rel, e))
    if css_files and not parsed:
        for s in skip_notes:
            print('SKIP %s %s' % (RULE, s), file=sys.stderr)
        die('CSS 를 하나도 읽지 못했다')

    code_files = walk(root, CODE_SKIP_DIRS, CODE_EXT)
    words, prefixes, code_text = set(), set(), {}
    for rel in code_files:
        try:
            t = read_text(os.path.join(root, rel))
        except OSError:
            continue
        code_text[rel] = t
        words.update(WORD_RE.findall(t))
        prefixes.update(concat_prefixes(t))

    findings = []   # (sev, file, line, desc)
    targets = 0
    failed_targets = 0
    skip = skipped

    def finding(sev, f, line, desc):
        findings.append((sev, f, line, desc))

    # 토큰 정의·사용
    defined_vars = {}
    font_token_vars = set()
    root_var_values = {}
    for rel, (rules, _) in parsed.items():
        for r in rules:
            tb = (not r.keyframe) and is_token_block(r.sel)
            for prop, val, line in r.decls:
                if prop.startswith('--'):
                    defined_vars.setdefault(prop, (rel, line))
                    if tb:
                        root_var_values.setdefault(prop, val.strip())
                if prop in ('font-size', 'font'):
                    for m in VAR_RE.finditer(val):
                        font_token_vars.add(m.group(1))
    token_sizes = set()
    for v in font_token_vars:
        if v in root_var_values:
            ns = norm_size(root_var_values[v])
            if ns and ns[0] == 'px':
                token_sizes.add(ns[1])

    ladder = set(design['sizes']) if design else set()
    banned = set(design['banned']) if design else set()
    weights_ok = set(design['weights']) if design else set()
    use_ladder = bool(ladder)
    allowed_sizes = (ladder | token_sizes) - banned

    def comment_exempt(rel, r, line):
        """규칙 바로 위(빈 줄 없이)·규칙 안·그 줄의 주석이 예외를 적었나."""
        comments = parsed[rel][1]
        for (l1, l2, endoff, body) in comments:
            inside = r.start <= endoff <= r.end
            above = False
            if not inside and endoff <= r.start:
                gap = RAW[rel][endoff:r.start]
                # 주석 끝과 규칙 선택자 사이에 빈 줄·다른 규칙이 없어야 한다
                sel_start = RAW[rel].rfind('}', 0, r.start)
                above = ('\n\n' not in gap.replace('\r', '')) and (sel_start < endoff) and \
                    (gap.count('\n') <= r.sel.count('\n') + 2)
            if inside or above or l1 <= line <= l2:
                if ALLOW_MARK.search(body):
                    return 'qcheck-allow 표시'
                if REASON_RE.search(body):
                    return '주석이 토큰 예외 이유를 적음'
        return None

    RAW = {}
    for rel in parsed:
        RAW[rel] = read_text(os.path.join(root, rel))

    # CSS 클래스 목록과 「쓰이지 않음」 판정(값 검사보다 먼저 — 죽은 규칙의 값은 U1 한 줄로만 알린다)
    css_classes = {}   # class -> {rel: [lines]}
    for rel in sorted(parsed):
        for r in parsed[rel][0]:
            if r.keyframe:
                continue
            for c in set(required_classes(r.sel)):
                css_classes.setdefault(c, {}).setdefault(rel, []).append(r.line)
    dynamic_of = {}
    unused = set()
    for c in css_classes:
        if c in words:
            continue
        dyn = sorted(p for p in prefixes if c.startswith(p) and len(c) > len(p))
        if dyn:
            dynamic_of[c] = dyn
        else:
            unused.add(c)

    def dead_rule(r):
        if 'unused' not in checks or r.keyframe:
            return False
        for part in split_top(r.sel):
            req = CLASS_IN_SEL.findall(remove_func_pseudo(part))
            if not any(c in unused for c in req):
                return False
        return True

    # T1·T2 글자 크기·굵기 + T3 색
    size_hits = []   # (값 키, rel, line, 설명용 원문) — 사다리 없는 경우 상한 셈에 쓴다
    for rel in sorted(parsed):
        rules = parsed[rel][0]
        for r in rules:
            tb = (not r.keyframe) and is_token_block(r.sel)
            if dead_rule(r):
                if r.decls:
                    skip += 1
                    if a.verbose:
                        print('SKIP %s %s:%d 값 검사 안 함 — 죽은 규칙(U1): %s' % (RULE, rel, r.line, first_sel(r.sel)))
                continue
            for prop, val, line in r.decls:
                if 'tokens' in checks and prop in ('font-size', 'font', 'font-weight'):
                    if prop == 'font-size':
                        wtok, stok = None, val
                    elif prop == 'font-weight':
                        wtok, stok = val, None
                    else:
                        wtok, stok = font_shorthand(val)
                    ns = norm_size(stok) if stok is not None else None
                    if ns and ns[0] in ('px', 'rel', 'kw'):     # neutral·skip(var·calc) 은 대상 아님 — var 는 T4 가 본다
                        targets += 1
                        key = fmt_px(ns[1]) if ns[0] == 'px' else ns[1]
                        if not use_ladder:
                            size_hits.append((key, rel, line))
                        elif not (ns[0] == 'px' and ns[1] in allowed_sizes):
                            failed_targets += 1
                            why = '문서가 금지한 값' if (ns[0] == 'px' and ns[1] in banned) else '사다리 밖'
                            finding('낮음', rel, line, 'T1 글자 크기 %s — %s(허용 %s · 문서 %s:%s)' % (
                                key, why, ' · '.join(fmt_px(x) for x in sorted(allowed_sizes)), drel, design['typo_line']))
                    wv = weight_value(wtok) if wtok is not None else None
                    if wv and wv[0] in ('num', 'rel') and weights_ok:
                        targets += 1
                        if not (wv[0] == 'num' and wv[1] in weights_ok):
                            failed_targets += 1
                            finding('낮음', rel, line, 'T2 굵기 %s — 문서가 허용한 굵기 밖(허용 %s · 문서 %s:%s)' % (
                                wtok.strip(), ' · '.join(str(x) for x in sorted(weights_ok)), drel, design['typo_line']))
                if 'colors' in checks:
                    if tb and prop.startswith('--'):
                        continue
                    lits = color_literals(prop, val)
                    if not lits:
                        if COLOR_PROPS.match(prop) or prop.startswith('--'):
                            targets += 1          # 색을 쓰는 선언 — 토큰만 썼다
                        continue
                    ex = comment_exempt(rel, r, line)
                    if ex:
                        skip += 1
                        if a.verbose:
                            print('SKIP %s %s:%d 색 리터럴 %s — %s' % (RULE, rel, line, ' '.join(lits), ex))
                        continue
                    targets += 1
                    failed_targets += 1
                    where = '토큰 블록 안의 일반 속성' if tb else '토큰 블록 밖'
                    finding('낮음', rel, line, 'T3 색 리터럴 %s (%s: %s) — %s, 토큰 var(--…) 를 쓴다' % (
                        ' '.join(lits), first_sel(r.sel), prop, where))

    if 'tokens' in checks and not use_ladder and size_hits:
        counts = {}
        first = {}
        for key, rel, line in size_hits:
            counts[key] = counts.get(key, 0) + 1
            first.setdefault(key, (rel, line))
        scale = set(fmt_px(x) for x in token_sizes)
        order = sorted(counts, key=lambda k: (-counts[k], k))
        distinct = len(counts)
        if distinct > SCALE_CAP:
            # 결함은 「사다리가 부풀었다」 하나 — 요약 한 줄. 자리는 가장 드문 값이 처음 나온 곳.
            keep = set(order[:SCALE_CAP]) | scale
            extra = [k for k in reversed(order) if k not in keep]
            rel, line = first[extra[0]] if extra else first[order[-1]]
            failed_targets += sum(counts[k] for k in extra)
            listing = ' · '.join('%s×%d' % (k, counts[k]) for k in order)
            finding('낮음', rel, line, 'T1 글자 크기 %d종 > 상한 %d — 문서에 크기 사다리가 없다(값: %s)' % (
                distinct, SCALE_CAP, listing))

    # T4 정의 없는 토큰
    if 'tokens' in checks:
        for rel in sorted(parsed):
            for r in parsed[rel][0]:
                for prop, val, line in r.decls:
                    for m in VAR_RE.finditer(val):
                        name, has_fb = m.group(1), m.group(2)
                        targets += 1
                        if name in defined_vars or has_fb:
                            continue
                        if name in words:
                            continue     # 코드가 이름을 쓴다(style.setProperty 등)
                        failed_targets += 1
                        finding('중간', rel, line, 'T4 정의되지 않은 토큰 var(%s) — 어느 CSS 에도 정의가 없다(선언이 무효가 된다)' % name)
        if design:
            modifiers = set()
            for rel in parsed:
                for r in parsed[rel][0]:
                    for c in CLASS_IN_SEL.findall(r.sel):
                        k = c.find('--')
                        if k > 0:
                            modifiers.add(c[k:])
            seen = set()
            for (ln, name, l) in design['tokens']:
                if name in seen:
                    continue
                seen.add(name)
                targets += 1
                base = re.sub(r'(\*|<[^>]*>.*)$', '', name)
                wild = base != name
                if not wild and name in defined_vars:
                    continue
                if any(v.startswith(base if wild else name + '-') for v in defined_vars):
                    continue
                if not wild and name in modifiers:
                    continue       # `.a--x` 의 줄임말(`--x`)
                if not wild and name in words:
                    continue
                failed_targets += 1
                finding('낮음', drel, ln, 'T4 문서가 적은 토큰 %s 가 CSS 에 없다(정의·계열·BEM 변형 어디에도)' % name)

    # U1 쓰이지 않는 클래스
    if 'unused' in checks:
        for c in sorted(css_classes):
            for rel, lines in sorted(css_classes[c].items()):
                dyn = dynamic_of.get(c)
                if dyn:
                    skip += 1
                    if a.verbose:
                        print('SKIP %s %s:%d .%s — 이어 붙인 클래스로 보임(앞 조각 %s)' % (
                            RULE, rel, lines[0], c, ' '.join(sorted(dyn)[:3])))
                    continue
                targets += 1
                if c not in unused:
                    continue
                failed_targets += 1
                more = '' if len(lines) == 1 else ' (규칙 %d곳: %s)' % (len(lines), ', '.join(str(x) for x in lines[:8]))
                finding('낮음', rel, lines[0], 'U1 쓰이지 않는 클래스 .%s — 마크업·JS·PHP 어디에도 없다%s' % (c, more))

    # D1 문서에 없는 구성 요소 변형
    if 'doc' in checks and design:
        dtext = design['text']
        dlines = dtext.split('\n')
        # 문서의 틀 언급 `.badge--status-*` · `.nt-<color>` → 앞 조각
        wild_classes = set()
        for span in re.findall(r'`([^`]+)`', dtext):
            for m in re.finditer(r'\.(-?[_a-zA-Z][_a-zA-Z0-9-]*)(?:\*|<[^>]*>)', span):
                wild_classes.add(m.group(1))
        for comp, hline in sorted(design['components'].items()):
            for c in sorted(css_classes):
                if not c.startswith(comp + '--'):
                    continue
                targets += 1
                if re.search(r'(?<![\w-])' + re.escape(c) + r'(?![\w-])', dtext):
                    continue
                mod = c[len(comp):]
                short = any(('`' + mod + '`') in l and ('.' + comp) in l for l in dlines)
                if short or any(c.startswith(w) for w in wild_classes):
                    continue
                failed_targets += 1
                rel, lines = sorted(css_classes[c].items())[0]
                finding('낮음', rel, lines[0], 'D1 구성 요소 .%s 의 변형 .%s 가 문서(%s:%d 절)에 없다' % (comp, c, drel, hline))

    # D2 문서가 적은 클래스가 CSS·코드 어디에도 없음
    if 'doc' in checks and design:
        all_css_classes = set()
        for rel in parsed:
            for r in parsed[rel][0]:
                all_css_classes.update(CLASS_IN_SEL.findall(r.sel))
        seen = set()
        for (ln, name, wild, shown) in design['classes']:
            if name in seen:
                continue
            seen.add(name)
            targets += 1
            if wild:
                if any(c.startswith(name) for c in all_css_classes) or any(w.startswith(name) for w in words):
                    continue
            elif name in all_css_classes or name in words:
                continue
            failed_targets += 1
            finding('낮음', drel, ln, 'D2 문서가 적은 클래스 .%s 가 CSS 에도 마크업·JS 에도 없다(옛 이름이 문서에 남음)' % shown)

    for sev, f, line, desc in findings:
        print('FINDING %s %s %s:%d %s' % (RULE, sev, f, line, desc))
    if a.verbose:
        for s in skip_notes:
            print('SKIP %s %s' % (RULE, s))
        print('INFO %s 문서=%s 사다리=%s 금지=%s 굵기=%s 글자 토큰=%s 구성 요소=%s CSS=%d 코드=%d' % (
            RULE, design['path'] if design else '없음',
            ','.join(fmt_px(x) for x in sorted(ladder)) or '-', ','.join('%g' % x for x in sorted(banned)) or '-',
            ','.join(str(x) for x in sorted(weights_ok)) or '-', ','.join(fmt_px(x) for x in sorted(token_sizes)) or '-',
            ','.join(sorted(design['components'])) if design else '-', len(parsed), len(code_text)))
    npass = max(0, targets - failed_targets)
    print('RESULT pass=%d fail=%d skip=%d' % (npass, len(findings), skip))
    return 1 if findings else 0


def first_sel(sel):
    s = ' '.join(sel.split())
    return s if len(s) <= 60 else s[:57] + '…'


if __name__ == '__main__':
    try:
        sys.exit(main())
    except SystemExit:
        raise
    except Exception as e:  # 실행 오류 = 검사 못 함
        print('ERROR %s %s: %s' % (RULE, type(e).__name__, e), file=sys.stderr)
        print('RESULT pass=0 fail=0 skip=1')
        sys.exit(2)
