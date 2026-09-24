#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
R11 위키 단계 범위 (wiki stage coverage) — 1-F 정적 규칙, python3 stdlib 만, 파일은 읽기만.

answers: AK-0108

무엇을 부류로 잡나:
  「단계가 끝나면 그 책의 위키에 단계 글이 있어야 한다」는 의무가 몇몇 책·몇몇 번호 범위에만 걸려서,
  끝난 단계의 글이 조용히 빠지는 결함이다. 빠짐을 잡아야 할 검사가 앱의 렌더러(대상 트리의 PHP
  클래스)에 기대면, 렌더러의 번호 범위가 좁을 때 검사도 같이 눈이 멀고, 렌더러 API 가 다른 과거 트리에서는
  실행 오류로 끝난다. 이 규칙은 렌더러를 부르지 않고 **데이터만** 읽는다.
    · 단계 장부 : STAGES.md 의 「## 0.」 장 첫 표. 머리줄의 「단계」·「상태」 칸을 이름으로 찾고,
                  상태 칸에 ✅ 가 있는 줄을 「끝난 단계」로 본다.
    · 위키 글   : wiki/*.json(책이 나뉘기 전 평평한 모양)과 wiki/<책>/*.json 의 `stage` 필드
                  (`group` 이 없거나 "stage" 인 글). index.json 은 글이 아니다.
  장부와 책은 이름으로 짝짓는다(같은 이름 · 장부 폴더 이름이 「책-…」 · 남은 하나끼리). 평평한 위키는
  대상 트리 자신의 장부와 짝이다. 장부마다 끝난 단계인데 짝 책에 같은 단계 번호의 글이 없으면 발견이다.
  짝 책이 아예 없으면(그 장부의 단계에 위키 의무가 걸리지 않은 모양) 한 단계마다 중간으로 올린다.
  단계 번호 모양: `12` · `4-A`(숫자 계열) · `Q-0`(글자 머리 계열, 머리 글자마다 다른 계열). 짝 책에 단계
  글이 있으면 그 글들의 계열만, 없으면 장부 표에서 가장 많은 계열만 단계로 본다(같은 표의 `F-47` 같은
  기능 번호 줄·`3+`·`Q-2~` 같은 묶음 줄은 단계가 아니다).
  예외(빠져도 되는 단계)는 이유와 함께 선언된 것만 받는다:
    ① 장부 줄 안의 `qcheck-allow: R11` 표시
    ② 대상 트리 scripts/*.php 에 **글자로** 적힌 위키 예외 상수(이름에 WIKI 와 EXCEPT 가 든 const 배열
       `'책' => ['단계' => '이유', …]`, 이름에 WIKI 와 COVER 가 든 const 배열 `'단계' => ['다른 책', ['단계', …]]`).
       PHP 를 실행하지 않고 글자만 읽는다 — 없거나 모양이 다르면 예외 없이 엄격하게 본다.
       (`--no-declared` 로 끈다.)

입력: --root <트리>(기본: 이 파일 기준 저장소 뿌리) · --outer <바깥 배치>(기본: <root>/.. 에 STAGES.md 가 있을
      때만 그 폴더, `none` 이면 끔) — 바깥의 STAGES.md 와 바깥/<폴더>/STAGES.md 가 장부가 된다.
      과거 트리를 볼 때는 바깥 배치를 그 시점 판으로 준다(채점 H-2).
      --stages <책>=<STAGES 경로> 를 주면(여러 번) 찾기 대신 그 짝만 본다(책 `.` = 평평한 위키).
출력: FINDING R11 <심각도> <장부 파일:줄> <설명> · 끝 줄 RESULT pass=<대조한 끝난 단계 - 발견> fail=<발견 수>
      skip=<읽지 못한 장부·글 수>
rc:   0 발견 없음 · 1 발견 있음 · 2 실행 오류(뿌리·wiki 폴더 없음, 읽을 수 있는 장부 0 = 검사 못 함)
"""
import argparse
import glob
import json
import os
import re
import sys

RULE = 'R11'
ALLOW_RE = re.compile(r'qcheck-allow\s*[:=]?\s*(?:[A-Za-z0-9_-]+\s*,\s*)*R11\b')
ID_NUM_RE = re.compile(r'^(\d{1,3})(?:-([A-Za-z0-9]{1,3}))?$')
ID_PFX_RE = re.compile(r'^([A-Za-z]{1,3})-(\d{1,3})$')
SEP_RE = re.compile(r'^:?-{3,}:?$')
DONE_MARK = '✅'


def note(msg):
    sys.stderr.write('R11: %s\n' % msg)


# ─── 단계 번호 ────────────────────────────────────────────────────────────────
def norm_stage(value):
    """글의 stage 값 또는 장부 칸 첫 낱말 → (정규형, 계열) 또는 None."""
    if value is None or isinstance(value, bool):
        return None
    if isinstance(value, int):
        return (str(value), 'num') if 0 <= value < 1000 else None
    if not isinstance(value, str):
        return None
    s = value.strip()
    m = ID_NUM_RE.match(s)
    if m:
        n = str(int(m.group(1)))
        return (n + ('-' + m.group(2).upper() if m.group(2) else ''), 'num')
    m = ID_PFX_RE.match(s)
    if m:
        p = m.group(1).upper()
        return ('%s-%d' % (p, int(m.group(2))), 'pfx:' + p)
    return None


def cell_stage(cell):
    """장부 「단계」 칸 → 첫 낱말의 단계 번호(정규형, 계열) 또는 None."""
    c = cell.replace('**', '').replace('`', '').replace('~~', '').strip()
    if not c:
        return None
    first = c.split()[0]
    return norm_stage(first)


# ─── 장부(STAGES 0장) ─────────────────────────────────────────────────────────
def split_row(line):
    t = line.strip()
    if t.startswith('|'):
        t = t[1:]
    if t.endswith('|') and not t.endswith('\\|'):
        t = t[:-1]
    t = t.replace('\\|', '\x00')
    return [c.replace('\x00', '|').strip() for c in t.split('|')]


def read_ledger(path):
    """→ [(단계 칸, 상태 칸, 줄 원문, 줄 번호)]. 표를 못 찾으면 ValueError."""
    with open(path, 'r', encoding='utf-8', errors='replace') as fh:
        lines = fh.read().split('\n')
    in_zero = False
    header = None
    rows = []
    for n, line in enumerate(lines, 1):
        if re.match(r'^##\s+0\.', line):
            in_zero = True
            continue
        if not in_zero:
            continue
        if re.match(r'^##\s', line):
            break
        t = line.strip()
        if not t.startswith('|'):
            if header is not None:
                break
            continue
        cells = split_row(t)
        if header is None:
            header = cells
            continue
        if cells and SEP_RE.match(cells[0] or ''):
            continue
        rows.append((cells, line, n))
    if header is None:
        raise ValueError('「## 0.」 장에서 표를 찾지 못했다')
    if '단계' not in header or '상태' not in header:
        raise ValueError('0장 표 머리줄에 「단계」·「상태」 칸이 없다 (%s)' % ' | '.join(header))
    si, ti = header.index('단계'), header.index('상태')
    out = []
    for cells, raw, n in rows:
        stage = cells[si] if si < len(cells) else ''
        status = cells[ti] if ti < len(cells) else ''
        out.append((stage, status, raw, n))
    return out


# ─── 위키 글 ──────────────────────────────────────────────────────────────────
def read_book(dirpath, counters):
    """→ {정규 단계: 파일 이름} · 계열 집합. 못 읽은 글은 counters['skip'] 에 더한다."""
    stages, fams = {}, set()
    for f in sorted(glob.glob(os.path.join(dirpath, '*.json'))):
        if os.path.basename(f) == 'index.json' or not os.path.isfile(f):
            continue
        try:
            with open(f, 'r', encoding='utf-8') as fh:
                doc = json.load(fh)
        except (OSError, ValueError) as e:
            note('글을 읽지 못함(건너뜀): %s (%s)' % (f, e.__class__.__name__))
            counters['skip'] += 1
            continue
        if not isinstance(doc, dict):
            continue
        group = doc.get('group')
        if group not in (None, 'stage'):
            continue
        ns = norm_stage(doc.get('stage'))
        if ns is None:
            continue
        stages.setdefault(ns[0], os.path.basename(f))
        fams.add(ns[1])
    return stages, fams


def discover_books(wiki):
    """→ {책 이름: 폴더}. 평평한 위키(wiki/*.json 에 글이 있음)는 책 이름 '.'."""
    books = {}
    flat = [f for f in glob.glob(os.path.join(wiki, '*.json')) if os.path.basename(f) != 'index.json']
    if flat:
        books['.'] = wiki
    for d in sorted(glob.glob(os.path.join(wiki, '*'))):
        if os.path.isdir(d) and not os.path.basename(d).startswith('.') and glob.glob(os.path.join(d, '*.json')):
            books[os.path.basename(d)] = d
    return books


# ─── 선언된 예외(글자로만 읽음) ───────────────────────────────────────────────
PHP_STR = r"'((?:[^'\\]|\\.)*)'|\"((?:[^\"\\]|\\.)*)\""


def php_const_block(text, pred):
    """top-level `const NAME = [ … ];` 가운데 pred(NAME) 인 것들의 [NAME, 본문]."""
    out = []
    for m in re.finditer(r'(?m)^const\s+([A-Z0-9_]+)\s*=\s*\[', text):
        name = m.group(1)
        if not pred(name):
            continue
        i, depth, q = m.end() - 1, 0, None
        j = i
        while j < len(text):
            ch = text[j]
            if q:
                if ch == '\\':
                    j += 2
                    continue
                if ch == q:
                    q = None
            elif ch in '\'"':
                q = ch
            elif ch == '[':
                depth += 1
            elif ch == ']':
                depth -= 1
                if depth == 0:
                    out.append((name, text[i + 1:j]))
                    break
            j += 1
    return out


def strip_php_comments(s):
    s = re.sub(r'/\*.*?\*/', ' ', s, flags=re.S)
    return re.sub(r'(?m)(^|\s)//[^\n]*', ' ', s)


def sval(m):
    return m.group(1) if m.group(1) is not None else m.group(2)


def declared_exceptions(root):
    """→ (예외 {책: {단계: 이유}}, 맡김 {단계: (다른 책, [단계…])}, 출처 목록)."""
    exc, cov, srcs = {}, {}, []
    for f in sorted(glob.glob(os.path.join(root, 'scripts', '*.php'))):
        try:
            with open(f, 'r', encoding='utf-8', errors='replace') as fh:
                text = fh.read()
        except OSError:
            continue
        for name, body in php_const_block(text, lambda n: 'WIKI' in n and 'EXCEPT' in n):
            body = strip_php_comments(body)
            for bm in re.finditer(r"(?:%s)\s*=>\s*\[([^\[\]]*)\]" % PHP_STR, body):
                book = sval(bm)
                for em in re.finditer(r"(?:%s)\s*=>\s*(?:%s)" % (PHP_STR, PHP_STR), bm.group(3)):
                    st = em.group(1) if em.group(1) is not None else em.group(2)
                    why = em.group(3) if em.group(3) is not None else em.group(4)
                    ns = norm_stage(st)
                    if ns and why.strip():
                        exc.setdefault(book, {})[ns[0]] = why.strip()
            srcs.append('%s:%s' % (os.path.relpath(f, root), name))
        for name, body in php_const_block(text, lambda n: 'WIKI' in n and 'COVER' in n):
            body = strip_php_comments(body)
            for cm in re.finditer(r"(?:%s)\s*=>\s*\[\s*(?:%s)\s*,\s*\[([^\[\]]*)\]\s*\]" % (PHP_STR, PHP_STR), body):
                st = cm.group(1) if cm.group(1) is not None else cm.group(2)
                other = cm.group(3) if cm.group(3) is not None else cm.group(4)
                lst = [sval(x) for x in re.finditer(PHP_STR, cm.group(5))]
                ns = norm_stage(st)
                norm_lst = [norm_stage(x) for x in lst]
                if ns and lst and all(norm_lst):
                    cov[ns[0]] = (other, [x[0] for x in norm_lst])
            srcs.append('%s:%s' % (os.path.relpath(f, root), name))
    return exc, cov, srcs


# ─── 장부 찾기 · 짝짓기 ──────────────────────────────────────────────────────
def hint_matches(hint, book):
    if book == '.':
        return False
    h, b = hint.lower(), book.lower()
    return h == b or any(h.startswith(b + s) for s in '-_.')


def discover_ledgers(root, outer):
    """→ [(이름 힌트, 경로, 자기 장부인가)]."""
    out = []
    own = os.path.join(root, 'STAGES.md')
    if os.path.isfile(own):
        out.append((os.path.basename(root), own, True))
    if outer:
        top = os.path.join(outer, 'STAGES.md')
        if os.path.isfile(top):
            out.append((os.path.basename(os.path.realpath(outer)), top, False))
        for d in sorted(glob.glob(os.path.join(outer, '*'))):
            if not os.path.isdir(d) or os.path.basename(d).startswith('.'):
                continue
            if os.path.realpath(d) == root:
                continue
            p = os.path.join(d, 'STAGES.md')
            if os.path.isfile(p):
                out.append((os.path.basename(d), p, False))
    return out


def pair(ledgers, books):
    """→ [(장부 경로, 책 이름 또는 None, 힌트)]."""
    left_books = set(books)
    res = {}
    for hint, path, own in ledgers:
        if own and '.' in left_books:
            res[path] = '.'
            left_books.discard('.')
    for hint, path, own in ledgers:
        if path in res:
            continue
        for b in sorted(left_books, key=len, reverse=True):
            if hint_matches(hint, b):
                res[path] = b
                left_books.discard(b)
                break
    rest = [p for _, p, _ in ledgers if p not in res]
    real = [b for b in left_books if b != '.']
    if len(rest) == 1 and len(real) == 1:
        res[rest[0]] = real[0]
    return [(path, res.get(path), hint) for hint, path, own in ledgers]


# ─── 본체 ─────────────────────────────────────────────────────────────────────
def main(argv=None):
    here = os.path.dirname(os.path.abspath(__file__))
    ap = argparse.ArgumentParser(description='R11 위키 단계 범위 — STAGES 0장 ✅ 단계 × 위키 단계 글(읽기만)')
    ap.add_argument('--root', default=os.path.normpath(os.path.join(here, '..', '..')))
    ap.add_argument('--outer', default=None, help='바깥 배치 폴더(기본: <root>/.. 에 STAGES.md 가 있을 때만), none 이면 끔')
    ap.add_argument('--stages', action='append', default=None, metavar='책=경로',
                    help='장부 짝을 직접 준다(여러 번). 책 "." = 평평한 위키')
    ap.add_argument('--no-declared', action='store_true', help='scripts/*.php 의 선언된 위키 예외를 읽지 않는다')
    args = ap.parse_args(argv)

    root = os.path.realpath(args.root)
    wiki = os.path.join(root, 'wiki')
    if not os.path.isdir(root) or not os.path.isdir(wiki):
        note('뿌리 또는 wiki 폴더가 없음 — 검사 못 함: %s' % args.root)
        print('RESULT pass=0 fail=0 skip=1')
        return 2

    if args.outer is None:
        cand = os.path.dirname(root)
        outer = cand if os.path.isfile(os.path.join(cand, 'STAGES.md')) else None
    elif args.outer.lower() == 'none':
        outer = None
    else:
        outer = os.path.realpath(args.outer)
        if not os.path.isdir(outer):
            note('바깥 배치 폴더가 없음 — 검사 못 함: %s' % args.outer)
            print('RESULT pass=0 fail=0 skip=1')
            return 2

    counters = {'skip': 0}
    books = discover_books(wiki)
    if args.stages:
        pairs = []
        for spec in args.stages:
            if '=' not in spec:
                note('--stages 형식은 책=경로: %s' % spec)
                print('RESULT pass=0 fail=0 skip=1')
                return 2
            b, p = spec.split('=', 1)
            pairs.append((os.path.realpath(p), b if b in books else None, b))
    else:
        pairs = pair(discover_ledgers(root, outer), books)
    if not pairs:
        note('읽을 STAGES.md 가 없음 — 검사 못 함')
        print('RESULT pass=0 fail=0 skip=1')
        return 2

    exc, cov, srcs = ({}, {}, []) if args.no_declared else declared_exceptions(root)
    if srcs:
        note('선언된 예외 표를 읽음: %s' % ', '.join(srcs))

    book_data = {b: read_book(d, counters) for b, d in books.items()}

    findings, checked, readable = [], 0, 0
    for path, book, hint in pairs:
        shown = os.path.relpath(path, root)
        try:
            rows = read_ledger(path)
        except (OSError, ValueError) as e:
            note('장부를 읽지 못함(건너뜀): %s — %s' % (shown, e))
            counters['skip'] += 1
            continue
        readable += 1
        stages, fams = book_data.get(book, ({}, set())) if book is not None else ({}, set())
        ids = [(cell_stage(st), status, raw, n) for st, status, raw, n in rows]
        if not fams:
            tally = {}
            for ns, _, _, _ in ids:
                if ns:
                    tally[ns[1]] = tally.get(ns[1], 0) + 1
            if tally:
                top = max(tally.values())
                fams = {k for k, v in tally.items() if v == top}
        seen = set()
        bname = book if book not in (None, '.') else ('wiki/' if book == '.' else None)
        for ns, status, raw, n in ids:
            if ns is None or ns[1] not in fams or DONE_MARK not in status:
                continue
            key = ns[0]
            if key in seen:
                continue
            seen.add(key)
            checked += 1
            if ALLOW_RE.search(raw):
                continue
            if book is not None and key in exc.get(book, {}):
                continue
            if book is not None and key in cov:
                other, need = cov[key]
                ost = book_data.get(other, ({}, set()))[0]
                lack = [s for s in need if s not in ost]
                if not lack:
                    continue
                findings.append(('낮음', shown, n, '단계 %s 는 완료(✅)이고 %s 책 글이 맡기로 선언됐는데 그 책에 %s 단계 글이 없다'
                                 % (key, other, '·'.join(lack))))
                continue
            if book is None:
                findings.append(('중간', shown, n, '단계 %s 가 완료(✅)인데 이 장부(%s)의 위키 책이 없다 — 위키 의무가 이 장부에 걸리지 않음'
                                 % (key, hint)))
            elif key not in stages:
                findings.append(('낮음', shown, n, '단계 %s 가 완료(✅)인데 %s 책에 stage=%s 인 글이 없다'
                                 % (key, bname, key)))

    if readable == 0:
        note('읽을 수 있는 장부가 없음 — 검사 못 함')
        print('RESULT pass=0 fail=0 skip=%d' % max(1, counters['skip']))
        return 2

    findings.sort(key=lambda f: (f[1], f[2]))
    for sev, p, n, msg in findings:
        print('FINDING %s %s %s:%d %s' % (RULE, sev, p, n, msg))
    print('RESULT pass=%d fail=%d skip=%d' % (checked - len(findings), len(findings), counters['skip']))
    return 1 if findings else 0


if __name__ == '__main__':
    sys.exit(main())
