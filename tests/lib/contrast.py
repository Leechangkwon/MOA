#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""tests/lib/contrast.py — 고대비 토큰 한 벌의 대비를 **계산**한다 (커뮤니티 7-D3 · C:T-211 · DESIGN.md 2.16)

외부 라이브러리 0 — WCAG 2.1 상대 휘도(sRGB → 선형 → 0.2126 R + 0.7152 G + 0.0722 B)와 대비 (L1 + 0.05) / (L2 + 0.05).
「눈으로 봤다」로 판정하지 않는다(지시서 2.6).

쓰는 법:  python3 tests/lib/contrast.py <app.css> <community.css>
          (인자를 주지 않으면 이 파일 자리에서 저장소의 두 파일을 찾는다)
출력:     쌍마다 한 줄(방식 · 글자 토큰=값 / 배경 토큰=값 · 비율 · 기준 · ✔/✖) + 모양 확인 몇 줄 + 끝 줄 `CONTRAST_FAIL=n`
종료 코드: 0 = 실패 0 · 1 = 실패 있음 · 2 = 파일·블록을 찾지 못함

읽는 블록(파일마다, 글자 그대로 이 선택자 — 지시서 H-4):
  기본 밝게     `:root {`                                       (파일의 첫 :root 블록)
  기본 어둡게   `:root[data-theme="dark"] {`
  고대비 밝게   `:root[data-contrast="high"] {`
  고대비 어둡게 `:root[data-contrast="high"][data-theme="dark"] {`
  고대비 어둡게(OS) `@media (prefers-color-scheme: dark) {` 안의 `:root[data-contrast="high"]:not([data-theme="light"]) {`

실제로 쓰이는 값은 CSS 캐스케이드 그대로 모은다(두 파일은 app.css → community.css 순서로 실린다):
  밝게+고대비   = 기본 밝게 ← 고대비 밝게
  어둡게+고대비 = 기본 밝게 ← 기본 어둡게 ← 고대비 밝게 ← 고대비 어둡게
    (고대비 밝게 `:root[data-contrast]` 와 기본 어둡게 `:root[data-theme]` 는 무게가 같고 고대비 쪽이 뒤라 이긴다 —
     그래서 고대비 어둡게 블록이 고대비 밝게 블록의 이름을 **빠짐없이** 다시 적었는지도 본다 · DESIGN 2.16)
반투명(rgba) 값은 그 방식의 `--bg` 위에 겹친 색으로 잰다.
"""
import os
import re
import sys

TEXT = ['--fg', '--fg-2', '--muted', '--muted-2']
SURF = ['--bg', '--surface', '--surface-2', '--muted-bg']
# 의미색 글자 → 각자의 연한 바탕(지시서 H-4 「각자의 -soft/-bg」 — app.css 가 실제로 짝지어 쓰는 것: 배지·위키 표식)
SEMANTIC = [
    ('--accent', '--accent-soft'),
    ('--accent-dark', '--accent-soft'),
    ('--danger', '--danger-soft'),
    ('--warn-fg', '--warn-bg'),
    ('--ok-fg', '--ok-bg'),
    ('--violet-fg', '--violet-bg'),
]
NT = ['gray', 'brown', 'orange', 'yellow', 'green', 'blue', 'purple', 'pink', 'red']
TEXT_MIN = 4.5
LINE_MIN = 3.0

SEL_BASE = ':root'
SEL_BASE_DARK = ':root[data-theme="dark"]'
SEL_HC = ':root[data-contrast="high"]'
SEL_HC_DARK = ':root[data-contrast="high"][data-theme="dark"]'
SEL_HC_OS = ':root[data-contrast="high"]:not([data-theme="light"])'
MEDIA_DARK = '@media (prefers-color-scheme: dark)'


def strip_comments(css):
    return re.sub(r'/\*.*?\*/', '', css, flags=re.S)


def top_blocks(css):
    """맨 위 층의 (선택자, 본문) 목록. @media 블록은 (머리, 본문) 그대로 — 안쪽은 다시 이 함수로 읽는다."""
    out = []
    i = 0
    n = len(css)
    while i < n:
        j = css.find('{', i)
        if j < 0:
            break
        head = css[i:j].strip()
        depth = 1
        k = j + 1
        while k < n and depth:
            if css[k] == '{':
                depth += 1
            elif css[k] == '}':
                depth -= 1
            k += 1
        out.append((re.sub(r'\s+', ' ', head), css[j + 1:k - 1]))
        i = k
    return out


def decls(body):
    """`--이름: 값;` 만 모은다(순서 유지 · 같은 이름이 두 번이면 뒤의 값). 사용자 정의 속성이 아닌 줄은 따로 센다."""
    vals = {}
    others = []
    for part in body.split(';'):
        part = part.strip()
        if not part:
            continue
        if ':' not in part:
            others.append(part)
            continue
        name, value = part.split(':', 1)
        name = name.strip()
        value = value.strip()
        if name.startswith('--'):
            vals[name] = value
        else:
            others.append(name)
    return vals, others


def read_blocks(path):
    css = strip_comments(open(path, encoding='utf-8').read())
    got = {'base': None, 'base_dark': None, 'hc': None, 'hc_dark': None, 'hc_os': None}
    raw = {}
    extra = []
    for head, body in top_blocks(css):
        if head == SEL_BASE and got['base'] is None:
            got['base'] = decls(body)
        elif head == SEL_BASE_DARK and got['base_dark'] is None:
            got['base_dark'] = decls(body)
        elif head == SEL_HC:
            if got['hc'] is not None:
                extra.append(head)
            got['hc'] = decls(body)
            raw['hc'] = body
        elif head == SEL_HC_DARK:
            if got['hc_dark'] is not None:
                extra.append(head)
            got['hc_dark'] = decls(body)
            raw['hc_dark'] = body
        elif head == MEDIA_DARK:
            for h2, b2 in top_blocks(body):
                if h2 == SEL_HC_OS:
                    if got['hc_os'] is not None:
                        extra.append(h2)
                    got['hc_os'] = decls(b2)
                    raw['hc_os'] = b2
    # 「화면 CSS 에 [data-contrast] 갈래 0」 — 주석을 뺀 글자에서 data-contrast 가 나오는 자리는 세 선택자뿐이어야 한다
    n_attr = len(re.findall(r'data-contrast', css))
    return got, raw, extra, n_attr


LINEAR = {}


def parse_color(v, under=None):
    """#rgb · #rrggbb · rgb()/rgba() → (r, g, b) 0~255 실수. 반투명은 under(불투명 색) 위에 겹친다."""
    v = v.strip().lower()
    m = re.fullmatch(r'#([0-9a-f]{3})', v)
    if m:
        h = m.group(1)
        return tuple(int(c * 2, 16) for c in h)
    m = re.fullmatch(r'#([0-9a-f]{6})', v)
    if m:
        h = m.group(1)
        return (int(h[0:2], 16), int(h[2:4], 16), int(h[4:6], 16))
    m = re.fullmatch(r'rgba?\(\s*([0-9.]+)\s*[, ]\s*([0-9.]+)\s*[, ]\s*([0-9.]+)\s*(?:[,/]\s*([0-9.]+%?)\s*)?\)', v)
    if m:
        r, g, b = (float(m.group(i)) for i in (1, 2, 3))
        a = m.group(4)
        if a is None:
            alpha = 1.0
        elif a.endswith('%'):
            alpha = float(a[:-1]) / 100.0
        else:
            alpha = float(a)
        if alpha >= 1.0:
            return (r, g, b)
        if under is None:
            raise ValueError('반투명 값인데 겹칠 바탕이 없다: ' + v)
        return tuple(alpha * c + (1 - alpha) * u for c, u in zip((r, g, b), under))
    raise ValueError('색 모양이 아니다: ' + v)


def lum(rgb):
    def ch(c):
        c = c / 255.0
        return c / 12.92 if c <= 0.04045 else ((c + 0.055) / 1.055) ** 2.4
    r, g, b = (ch(c) for c in rgb)
    return 0.2126 * r + 0.7152 * g + 0.0722 * b


def ratio(a, b):
    la, lb = lum(a), lum(b)
    hi, lo = max(la, lb), min(la, lb)
    return (hi + 0.05) / (lo + 0.05)


def result_line(lines, fail):
    """요약기(tests/lib/results.py)가 세는 합계 한 줄 — 통과 = ✔ 줄 수(검사한 쌍·모양 확인), 실패 = CONTRAST_FAIL 과 같은 수"""
    passed = sum(1 for ln in lines if ln.startswith('  ✔ '))
    return 'RESULT pass=%d fail=%d skip=0' % (passed, fail)


def main(argv):
    here = os.path.dirname(os.path.abspath(__file__))
    root = os.path.normpath(os.path.join(here, '..', '..'))
    app = argv[1] if len(argv) > 1 else os.path.join(root, 'public/assets/app.css')
    com = argv[2] if len(argv) > 2 else os.path.join(root, 'public/assets/community/community.css')
    fail = 0
    lines = []

    def ok(label, cond, info=''):
        nonlocal fail
        if not cond:
            fail += 1
        lines.append('  %s %s%s' % ('✔' if cond else '✖', label, ('  ' + info) if info else ''))

    files = {}
    for tag, path in (('app.css', app), ('community.css', com)):
        if not os.path.isfile(path):
            print('파일이 없다: %s' % path)
            print('CONTRAST_FAIL=1')
            print('RESULT pass=0 fail=1 skip=0')
            return 2
        files[tag] = read_blocks(path)

    # ─── 모양 ─────────────────────────────────────────────────
    lines.append('── 모양 (파일마다 블록 셋 · 새 이름 0 · 어두운 두 곳 글자 그대로 같음 · 어두운 블록이 밝은 고대비 이름을 다 적음)')
    for tag, (got, raw, extra, n_attr) in files.items():
        for key, sel in (('base', SEL_BASE), ('base_dark', SEL_BASE_DARK), ('hc', SEL_HC), ('hc_dark', SEL_HC_DARK), ('hc_os', MEDIA_DARK + ' ' + SEL_HC_OS)):
            ok('%s 블록 있음 — %s' % (tag, sel), got[key] is not None)
        if any(got[k] is None for k in ('base', 'base_dark', 'hc', 'hc_dark', 'hc_os')):
            print('\n'.join(lines))
            print('CONTRAST_FAIL=%d' % max(fail, 1))
            print(result_line(lines, max(fail, 1)))
            return 2
        ok('%s 고대비 블록이 두 번 나오지 않는다' % tag, extra == [], ' '.join(extra))
        ok('%s `data-contrast` 는 세 선택자에만(화면 CSS 갈래 0)' % tag, n_attr == 3, '실제 %d' % n_attr)
        base_names = set(got['base'][0])
        for key in ('hc', 'hc_dark', 'hc_os'):
            names = set(got[key][0])
            new = sorted(names - base_names)
            ok('%s %s — 새 토큰 이름 0(기본 :root 에 있는 이름만)' % (tag, key), new == [], ' '.join(new))
            ok('%s %s — 사용자 정의 속성 밖의 선언 0' % (tag, key), got[key][1] == [], ' '.join(got[key][1]))
        ok('%s 어두운 두 블록 값이 글자 그대로 같다' % tag, got['hc_dark'][0] == got['hc_os'][0])
        miss = sorted(set(got['hc'][0]) - set(got['hc_dark'][0]))
        ok('%s 어두운 고대비 블록이 밝은 고대비 블록의 이름을 빠짐없이 적었다(밝은 값이 새지 않게)' % tag, miss == [], ' '.join(miss))

    # ─── 실제로 쓰이는 값(캐스케이드) ─────────────────────────────
    def eff(mode):
        v = {}
        for tag in ('app.css', 'community.css'):
            got = files[tag][0]
            order = ['base', 'hc'] if mode == 'light' else ['base', 'base_dark', 'hc', 'hc_dark']
            for key in order:
                v.update(got[key][0])
        return v

    for mode, label in (('light', '밝게+고대비'), ('dark', '어둡게+고대비')):
        v = eff(mode)
        bg = parse_color(v['--bg'])

        def col(name):
            return parse_color(v[name], bg)

        lines.append('── %s (--bg=%s)' % (label, v['--bg']))

        def pair(fg, back, minimum, kind):
            try:
                a, b = col(fg), col(back)
            except (KeyError, ValueError) as e:
                ok('%s %s / %s' % (label, fg, back), False, '값을 읽지 못함: %s' % e)
                return
            r = ratio(a, b)
            ok('%-13s %-15s %-26s / %-15s %-26s %5.2f:1  (≥ %.1f · %s)' % (label, fg, v[fg], back, v[back], r, minimum, kind), r >= minimum)

        for t in TEXT:
            for s in SURF:
                pair(t, s, TEXT_MIN, '글자')
        for f, soft in SEMANTIC:
            pair(f, '--bg', TEXT_MIN, '의미색 글자')
            pair(f, soft, TEXT_MIN, '의미색 글자 · 연한 바탕')
        pair('--primary-fg', '--primary', TEXT_MIN, '주요 단추')
        for c in NT:
            pair('--nt-' + c, '--bg', TEXT_MIN, '문서 글자색')
        for c in NT:
            pair('--fg', '--nt-' + c + '-bg', TEXT_MIN, '문서 배경색')
        for c in NT:
            pair('--nt-' + c, '--nt-' + c + '-bg', TEXT_MIN, '같은 색 글자·배경(더 본 것)')
        for t in ('--line', '--ring'):
            pair(t, '--bg', LINE_MIN, '선·고리')

    print('\n'.join(lines))
    print('CONTRAST_FAIL=%d' % fail)
    print(result_line(lines, fail))
    return 0 if fail == 0 else 1


if __name__ == '__main__':
    sys.exit(main(sys.argv))
