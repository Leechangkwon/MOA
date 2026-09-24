#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
R6 커밋 범위 (commit scope / tracking hygiene) — 1-F 정적 규칙, python3 stdlib 만, 파일은 읽기만.

answers: AK-0069 · AK-0093 · AK-0043 · AK-0081

무엇을 부류로 잡나:
  커밋(범위)이 「이번 일」의 모양과 맞지 않는 결함이다 — 전체 추가·전체 체크아웃으로 남의 파일이 섞이거나
  빠지고, 지시서가 허락한 파일 밖을 고치고, 코드·문서가 기대는 파일이 git 추적 목록에 없고, 중단된 작업의
  미완성 파일이 커밋된다. 트리 한 판의 문법 검사로는 보이지 않고 **커밋 범위와 추적 목록**을 봐야 보인다.
    K1 범위 밖 변경   --directive 로 받은 지시서의 「수정 파일」 절(마크다운 제목 또는 `수정 파일 :` 칸)에서       중간
                      경로·글롭을 모아, 범위의 바뀐 파일(더함·고침·지움·이름 바꿈) 가운데 어디에도 맞지 않는 것.   (높음)
                      같은 절에서 「수정 0·만들지 않는·고치지 않는·금지·이외」 줄의 경로에 걸리면 높음.
    K2 추적 누락      범위 끝 판의 추적 텍스트 파일(코드·시험·문서)이 저장소 안 경로를 가리키는데 그 경로가 추적
                      목록에 없다.
                      K2a 작업 트리에는 있는데 추적되지 않음(git add 누락 — .gitignore 에 걸리면 뺌)            중간
                      K2c 범위가 **더한 줄**이 트리에도 없는 파일을 가리킴(미완성 작업·짝 파일 누락)            낮음
                      K2b 예전에 추적되던 경로(지금은 지워짐·옮겨짐)를 기록 문서 밖에서 여전히 가리킴            낮음
                      셋 다 아니면(한 번도 없던 경로, 예시·계획) 판정하지 않고 skip 으로 센다.
    K3 미완성 표시    범위가 더하거나 고친 파일이 비었음 · `.sql` 이 주석뿐(빈 마이그레이션)이거나 BEGIN 이          중간
                      닫히지 않음 · 더한 줄에 병합 충돌 표시 · 더한 줄의 주석에 TODO·FIXME·WIP·미완성 류 표시     낮음
  경로 모양: `/` 를 하나 이상 품고 알려진 확장자로 끝나는 낱말(URL·절대 경로의 조각·글롭·자리표시 `<…>`·변수는
  빼고), 절대 경로는 뿌리·바깥 배치·--alias 아래일 때만 저장소 경로로 바꾼다. 첫 조각이 저장소의 윗 폴더이거나
  가리키는 파일의 폴더 기준으로 풀릴 때만 판정한다. 시점이 박힌 기록 문서(STAGES*·CHANGELOG*·이름에 directive·
  review·날짜가 든 문서)와 날짜로 시작하는 표 행·옛/이동/삭제를 말하는 줄은 K2b·K2c 에서 뺀다.
  같은 줄에 `qcheck-allow: R6` 가 있으면 뺀다. K2·K3 기본 제외 `tests/static/fixtures/*`(K1 은 제외 없이 전부 본다).
  시험 파일(tests/·test_*·*_test.*) 속 경로는 가짜 견본 자료가 많아 K2b·K2c 에서 뺀다(K2a 는 본다).
  맨 경로(`a/b.md`)는 뿌리 기준으로만 풀고, 파일 기준 풀이는 `./`·`../` 와 마크다운 링크 `](…)` 에만 쓴다.
  `../` 경로는 바깥 배치(--outer)에서만 판정한다 — 바깥 배치에 그 폴더가 없으면 skip.

입력: --root <저장소>(기본: 이 파일 기준 저장소 뿌리) · --range A..B | A..(작업 트리) | C(=C^..C), 기본 HEAD..
      --directive <md>(여러 번 가능, 없으면 K1 은 건너뜀) · --outer <바깥 배치>(기본 <root>/..; none)
      --alias <절대 경로 접두>(그 접두를 뿌리로 본다, 여러 번) · --exclude GLOB
출력: FINDING R6 <심각도> <파일:줄> <설명> · 끝 줄 RESULT pass=<판정한 대상 - 발견 대상> fail=<발견 수> skip=<판정 못 한 참조>
rc:   0 발견 없음 · 1 발견 있음 · 2 실행 오류(git 저장소 아님·범위 풀기 실패·지시서 없음/「수정 파일」 절 없음 = 검사 못 함)
"""
import argparse
import fnmatch
import os
import posixpath
import re
import subprocess
import sys

RULE = 'R6'
DEFAULT_EXCLUDES = ['tests/static/fixtures/*']
MAX_BYTES = 2 * 1024 * 1024
ALLOW_RE = re.compile(r'qcheck-allow\s*[:=]?\s*(?:[A-Za-z0-9_-]+\s*,\s*)*R6\b')
WORKTREE = object()
EXTS = ('md|php|phtml|js|mjs|css|json|sql|sh|py|html|htm|txt|conf|yml|yaml|ps1|psm1|service|timer|ini|csv|'
        'xml|svg|png|jpg|jpeg|webp|ico|gif|pdf|toml|env|lock|bash|tsv|jsonl|example|sample|dist')
PATH_RE = re.compile(r'(?<![\w./~$@*<{%:-])((?:\.\./|\./)*(?:[A-Za-z0-9_][\w.-]*/)+[\w.-]*[A-Za-z0-9_]\.(?:' + EXTS +
                     r'))(?![\w/*{<$-]|\.\w)')
ABS_RE = re.compile(r'(?<![\w.~$@*<{%:-])(/(?:[\w.-]+/)+[\w.-]*[A-Za-z0-9_]\.(?:' + EXTS + r'))(?![\w/*{<$-]|\.\w)')
PLACEHOLDER_RE = re.compile(r'([A-Z])\1|\b[A-Z]{1,2}\b(?=\.)|YYYY|MMDD|\bN\b|\bn\b(?=\.)')
DOC_EXT = ('.md', '.txt', '.rst', '.html', '.htm')
TEXT_EXT = ('.md', '.php', '.phtml', '.js', '.mjs', '.py', '.sh', '.bash', '.sql', '.json', '.conf', '.txt',
            '.yml', '.yaml', '.html', '.htm', '.ini', '.service', '.timer', '.toml', '.ps1', '.css')
CODE_EXT = ('.php', '.phtml', '.js', '.mjs', '.py', '.sh', '.bash', '.sql', '.css', '.ps1', '.conf', '.service',
            '.timer', '.ini', '.yml', '.yaml', '.toml')
RECORD_DOC_RE = re.compile(r'(^|/)(STAGES[^/]*|CHANGELOG[^/]*|[^/]*(directive|review)[^/]*|stage[^/]*)$|\d{4}-\d{2}-\d{2}', re.I)
TEST_PATH_RE = re.compile(r'(^|/)(tests?|spec|__tests__)/|(^|/)test_[^/]*$|_test\.[^/]+$')
EXAMPLE_RE = re.compile(r'예시|예:|예\)|e\.g\.|for example|example|sample|견본|가짜|fake|dummy', re.I)
HIST_LINE_RE = re.compile(r'^\s*\|\s*\d{4}-\d{2}-\d{2}|→|->|=>|옛|이전|예전|과거|삭제|제거|지웠|지운|지움|옮겼|옮긴|옮김|이동|'
                          r'바뀌기\s*전|바꿨|대체|폐기|없앴|되돌|renamed|moved|removed|deleted|formerly|\bwas\b|\bold\b', re.I)
WRITE_CTX_RE = re.compile(r'(>>?|\btee\s+(-a\s+)?|\bmkdir\s+(-p\s+)?|\btouch\s+|\brm\s+(-[rf]+\s+)?|-o\s+|--out(?:put)?[= ]|'
                          r'\bln\s+-s\s+\S+\s+)["\']?$')
NEG_LINE_RE = re.compile(r'수정\s*0|변경\s*0|만들지\s*않|고치지\s*않|손대지\s*않|허용하지\s*않|건드리지\s*않|바꾸지\s*않|'
                         r'금지|이외|말고|빼고|제외')
TODO_RE = re.compile(r'(?:^|\s)(?:#+|//+|/\*+|\*|--|<!--|;)\s*[★※!\[(]*\s*(?:(?:TODO|FIXME|XXX|WIP|TBD)\b|미완성|미구현|구현\s*예정|작성\s*중|나중에\s*채)')


def note(msg):
    sys.stderr.write('R6: %s\n' % msg)


class GitError(RuntimeError):
    pass


def git(root, *args, check=True, input_bytes=None):
    try:
        p = subprocess.run(['git', '-C', root] + list(args), input=input_bytes, stdout=subprocess.PIPE,
                           stderr=subprocess.PIPE, timeout=300)
    except (OSError, subprocess.SubprocessError) as e:
        raise GitError(str(e))
    if check and p.returncode != 0:
        raise GitError('git %s: %s' % (' '.join(args[:3]), p.stderr.decode(errors='replace').strip()))
    return p


def zsplit(b):
    return [x for x in b.decode('utf-8', errors='surrogateescape').split('\0') if x]


# ─── 범위 ────────────────────────────────────────────────────────────────────
def resolve_range(root, spec):
    spec = spec or 'HEAD..'
    if '..' in spec:
        a, b = spec.split('..', 1)
        if b.startswith('.'):
            raise GitError('세 점 범위(A...B)는 받지 않는다')
        a = a or 'HEAD'
        base = git(root, 'rev-parse', '--verify', '-q', a + '^{commit}').stdout.decode().strip()
        tip = WORKTREE if b == '' else git(root, 'rev-parse', '--verify', '-q', b + '^{commit}').stdout.decode().strip()
        return base, tip
    tip = git(root, 'rev-parse', '--verify', '-q', spec + '^{commit}').stdout.decode().strip()
    p = git(root, 'rev-parse', '--verify', '-q', tip + '^', check=False)
    base = p.stdout.decode().strip() if p.returncode == 0 else '4b825dc642cb6eb9a060e54bf8d69288fbee4904'
    return base, tip


def changed_files(root, base, tip):
    """[(status, path, oldpath)] — status: A M D R T ?"""
    args = ['diff', '--name-status', '-z', '-M']
    args += [base] if tip is WORKTREE else [base, tip]
    toks = zsplit(git(root, *args).stdout)
    out = []
    i = 0
    while i < len(toks):
        st = toks[i]
        if st[0] in 'RC':
            out.append((st[0], toks[i + 2], toks[i + 1]))
            i += 3
        else:
            out.append((st[0], toks[i + 1], None))
            i += 2
    if tip is WORKTREE:
        for rel in zsplit(git(root, 'ls-files', '-z', '--others', '--exclude-standard').stdout):
            out.append(('?', rel, None))
    return out


def added_lines(root, base, tip):
    args = ['diff', '-U0', '--no-renames', '--no-color', '--no-ext-diff']
    args += [base] if tip is WORKTREE else [base, tip]
    raw = git(root, *args).stdout.decode('utf-8', errors='replace')
    out = {}
    cur = None
    for l in raw.split('\n'):
        if l.startswith('+++ '):
            p = l[4:]
            cur = p[2:] if p.startswith('b/') else None
            if cur is not None:
                out.setdefault(cur, set())
        elif l.startswith('@@') and cur is not None:
            m = re.match(r'@@ -\S+ \+(\d+)(?:,(\d+))? @@', l)
            if m:
                st = int(m.group(1))
                cnt = int(m.group(2)) if m.group(2) is not None else 1
                out[cur].update(range(st, st + cnt))
    return out


def tracked_at(root, tip):
    if tip is WORKTREE:
        return set(zsplit(git(root, 'ls-files', '-z', '--cached').stdout))
    return set(zsplit(git(root, 'ls-tree', '-r', '--name-only', '-z', tip).stdout))


def ever_added(root, tip):
    rev = 'HEAD' if tip is WORKTREE else tip
    p = git(root, 'log', '--format=', '--name-only', '-z', '--no-renames', '--diff-filter=A', rev, check=False)
    return set(zsplit(p.stdout)) if p.returncode == 0 else set()


class Reader:
    """범위 끝 판의 파일 내용. 작업 트리면 디스크, 커밋이면 cat-file --batch."""

    def __init__(self, root, tip):
        self.root, self.tip = root, tip
        self.proc = None

    def get(self, rel):
        if self.tip is WORKTREE:
            full = os.path.join(self.root, rel)
            try:
                if os.path.islink(full) or os.path.getsize(full) > MAX_BYTES:
                    return None
                with open(full, 'rb') as fh:
                    data = fh.read()
            except OSError:
                return None
        else:
            if self.proc is None:
                self.proc = subprocess.Popen(['git', '-C', self.root, 'cat-file', '--batch'], stdin=subprocess.PIPE,
                                             stdout=subprocess.PIPE, stderr=subprocess.DEVNULL)
            self.proc.stdin.write(('%s:%s\n' % (self.tip, rel)).encode('utf-8', errors='surrogateescape'))
            self.proc.stdin.flush()
            head = self.proc.stdout.readline().decode(errors='replace').split()
            if len(head) < 3 or head[1] == 'missing':
                return None
            size = int(head[2])
            data = self.proc.stdout.read(size)
            self.proc.stdout.read(1)
            if head[1] != 'blob' or size > MAX_BYTES:
                return None
        if b'\0' in data[:8000]:
            return None
        return data.decode('utf-8', errors='replace')

    def close(self):
        if self.proc:
            self.proc.stdin.close()
            self.proc.wait(timeout=30)


# ─── 지시서 「수정 파일」 절 ───────────────────────────────────────────────────
HEAD_RE = re.compile(r'^(#{1,6})\s+(.*)$')
FIELD_RE = re.compile(r'^(\s*)([^\s:：|`#>*-][^:：|`]{0,14}?)\s*[:：]\s')
TICK_RE = re.compile(r'`([^`\n]+)`')
BARE_RE = re.compile(r'(?<![\w`/.-])((?:\.\./|\./|/)?(?:[\w.<>*{}…·,-]+/)*[\w<>*{}…·,.-]*(?:\.(?:' + EXTS +
                     r')|\*\*?|/))(?![\w`])')


def directive_sections(text):
    lines = text.split('\n')
    out = []
    in_code = False
    i = 0
    while i < len(lines):
        l = lines[i]
        if l.lstrip().startswith('```'):
            in_code = not in_code
            i += 1
            continue
        if not in_code:
            m = HEAD_RE.match(l)
            if m and re.search(r'수정\s*파일|허용\s*목록', m.group(2)):
                lv = len(m.group(1))
                j = i + 1
                code = False
                while j < len(lines):
                    if lines[j].lstrip().startswith('```'):
                        code = not code
                    elif not code:
                        m2 = HEAD_RE.match(lines[j])
                        if m2 and len(m2.group(1)) <= lv:
                            break
                    j += 1
                out.append((i + 1, lines[i + 1:j]))
                i = j
                continue
        fm = FIELD_RE.match(l)
        if fm and re.search(r'수정\s*파일', fm.group(2)):
            ind = len(fm.group(1))
            body = [l[fm.end():]]
            j = i + 1
            while j < len(lines):
                lj = lines[j]
                if lj.lstrip().startswith('```'):
                    break
                f2 = FIELD_RE.match(lj)
                if f2 and len(f2.group(1)) <= ind and not re.search(r'수정\s*파일', f2.group(2)):
                    break
                body.append(lj)
                j += 1
            out.append((i + 1, body))
            i = j
            continue
        i += 1
    return out


def clean_token(t):
    t = t.strip().strip('*').strip()
    t = re.sub(r'^[(\[]+|[)\],.;:]+$', '', t)
    t = t.replace('…', '*')
    t = re.sub(r'<[^>]*>', '*', t)
    if t.startswith('./'):
        t = t[2:]
    return t


def expand_braces(p):
    m = re.search(r'\{([^{}]*)\}', p)
    if not m:
        return [p]
    out = []
    for alt in m.group(1).split(','):
        out.extend(expand_braces(p[:m.start()] + alt + p[m.end():]))
    return out


def expand_dots(t):
    """`tests/c/stage4d·5a·7a.sh` 처럼 가운뎃점으로 줄인 목록 → 느슨한 글롭."""
    if '·' not in t:
        return [t]
    parts = [x for x in t.split('·') if x]
    if len(parts) < 2 or '/' in ''.join(parts[1:]):
        return parts
    first = parts[0]
    d, base = posixpath.split(first)
    prefix = re.match(r'^[^\d]*', base).group(0)
    ext = posixpath.splitext(parts[-1])[1]
    return [posixpath.join(d, prefix + '*' + ext)]


def looks_path(t):
    if not t or ' ' in t or '://' in t or t.startswith(('-', '$', '|', '@', '=')):
        return False
    if re.fullmatch(r'[\d.]+', t):
        return False
    return ('/' in t or re.search(r'\.(?:' + EXTS + r')$', t) is not None) and re.fullmatch(r'[\w./*{},\-~·]+', t)


def tokens_in_line(line):
    body = re.sub(r'\([^()]*\)|（[^（）]*）', ' ', line)
    raw = TICK_RE.findall(body)
    rest = TICK_RE.sub(' ', body)
    for m in BARE_RE.finditer(rest):
        raw.append(m.group(1))
    out = []
    for r in raw:
        for piece in re.split(r'\s+', r.strip()):
            c = clean_token(piece)
            if not c:
                continue
            for e in expand_dots(c):
                for e2 in expand_braces(e):
                    e2 = clean_token(e2)
                    if looks_path(e2):
                        out.append(e2)
    # 확장자 없이 적은 문서 이름(「PRD S-07 문구」·「README 설치 7절」) — 뿌리의 같은 이름 파일로 느슨하게 받는다
    for w in re.findall(r'(?<![\w./`-])([A-Z][A-Z0-9_]{2,})(?![\w./`-])', rest):
        out.append(w + '.*')
    neg = NEG_LINE_RE.search(body) is not None
    return out, neg


def top_dirs(tracked):
    return {p.split('/', 1)[0] for p in tracked if '/' in p}


def to_repo_patterns(tok, roots_abs, tdirs):
    """지시서 경로 한 개 → 저장소 기준 글롭 후보들(느슨하게)."""
    pats = set()
    t = tok
    if t.startswith('/'):
        for ra in roots_abs:
            if t.startswith(ra.rstrip('/') + '/'):
                pats.add(t[len(ra.rstrip('/')) + 1:])
        comps = t.strip('/').split('/')
        for k, c in enumerate(comps[:-1]):
            if c in tdirs:
                pats.add('/'.join(comps[k:]))
                break
        return pats
    norm = posixpath.normpath(t) if not t.endswith('/') else posixpath.normpath(t) + '/'
    pats.add(norm)
    stripped = re.sub(r'^(?:\.\./)+', '', t)
    if stripped != t:
        pats.add(stripped)
        comps = stripped.split('/')
        for k, c in enumerate(comps[:-1]):
            if c in tdirs:
                pats.add('/'.join(comps[k:]))
                break
    return pats


def glob_match(pat, rel):
    if pat.endswith('/'):
        return rel.startswith(pat) or rel + '/' == pat
    if '*' in pat or '?' in pat:
        rx = '^' + re.escape(pat).replace(r'\*\*/', '(?:.*/)?').replace(r'\*\*', '.*').replace(r'\*', '[^/]*') \
            .replace(r'\?', '[^/]') + '$'
        return re.match(rx, rel) is not None
    return rel == pat or rel.startswith(pat.rstrip('/') + '/')


def load_directives(paths, roots_abs, tdirs):
    allow, deny, names = [], [], []
    for p in paths:
        try:
            with open(p, 'rb') as fh:
                text = fh.read().decode('utf-8', errors='replace')
        except OSError as e:
            raise GitError('지시서를 읽지 못함: %s (%s)' % (p, e))
        secs = directive_sections(text)
        if not secs:
            raise GitError('지시서에 「수정 파일」 절이 없음: %s' % p)
        names.append(os.path.basename(p))
        for _, body in secs:
            for line in body:
                toks, neg = tokens_in_line(line)
                dirs = [posixpath.dirname(t) for t in toks if '/' in t]
                for t in toks:
                    cands = [t]
                    if '/' not in t:
                        cands += [posixpath.join(d, t) for d in dirs if d]
                    for c in cands:
                        for pat in to_repo_patterns(c, roots_abs, tdirs):
                            (deny if neg else allow).append((pat, os.path.basename(p)))
    return allow, deny, names


# ─── 참조 뽑기 ────────────────────────────────────────────────────────────────
def refs_in(text):
    """(줄 번호, 경로, 줄 글, 앞 글) — 절대 경로는 앞에 '/' 를 단 채로."""
    out = []
    for i, line in enumerate(text.split('\n'), 1):
        if len(line) > 4000 or ALLOW_RE.search(line):
            continue
        for m in PATH_RE.finditer(line):
            out.append((i, m.group(1), line, line[max(0, m.start() - 16):m.start()]))
        for m in ABS_RE.finditer(line):
            out.append((i, m.group(1), line, line[max(0, m.start() - 16):m.start()]))
    return out


def is_ignored(root, rels):
    if not rels:
        return set()
    p = git(root, 'check-ignore', '-z', '--stdin', '--no-index', check=False,
            input_bytes='\0'.join(rels).encode('utf-8', errors='surrogateescape'))
    return set(zsplit(p.stdout))


# ─── 본체 ────────────────────────────────────────────────────────────────────
def main(argv=None):
    here = os.path.dirname(os.path.abspath(__file__))
    ap = argparse.ArgumentParser(description='R6 커밋 범위')
    ap.add_argument('--root', default=os.path.normpath(os.path.join(here, '..', '..')))
    ap.add_argument('--range', dest='range_', default=None)
    ap.add_argument('--directive', action='append', default=[])
    ap.add_argument('--outer', default=None)
    ap.add_argument('--alias', action='append', default=[])
    ap.add_argument('--exclude', action='append', default=None)
    a = ap.parse_args(argv)
    root = os.path.abspath(a.root)
    excludes = DEFAULT_EXCLUDES if a.exclude is None else a.exclude

    def broken(msg):
        print('RESULT pass=0 fail=0 skip=0')
        note(msg)
        return 2

    if not os.path.isdir(root):
        return broken('뿌리가 없음: %s' % root)
    try:
        top = git(root, 'rev-parse', '--show-toplevel').stdout.decode().strip()
    except GitError as e:
        return broken('git 저장소가 아님: %s' % e)
    if os.path.realpath(top) != os.path.realpath(root):
        return broken('--root 는 저장소 맨 위여야 한다(%s 의 맨 위는 %s)' % (root, top))
    outer = None if a.outer == 'none' else os.path.abspath(a.outer or os.path.join(root, '..'))
    try:
        base, tip = resolve_range(root, a.range_)
        changes = changed_files(root, base, tip)
        added = added_lines(root, base, tip)
        tracked = tracked_at(root, tip)
        history = ever_added(root, tip)
    except GitError as e:
        return broken('범위를 풀지 못함: %s' % e)
    tdirs = top_dirs(tracked | {c[1] for c in changes})
    dirset = set()
    for pth in tracked | {c[1] for c in changes}:
        parts = pth.split('/')[:-1]
        for k in range(1, len(parts) + 1):
            dirset.add('/'.join(parts[:k]))
    roots_abs = [os.path.realpath(root), root] + [x.rstrip('/') for x in a.alias]
    outer_abs = [os.path.realpath(outer)] if outer and os.path.isdir(outer) else []
    live = tip is WORKTREE
    if not live:
        try:
            live = git(root, 'rev-parse', 'HEAD').stdout.decode().strip() == tip
        except GitError:
            live = False
    try:
        allow, deny, dnames = load_directives(a.directive, roots_abs + outer_abs, tdirs)
    except GitError as e:
        return broken(str(e))

    findings = []
    stats = {'targets': 0, 'skip': 0}
    reader = Reader(root, tip)
    all_changes = changes
    changes = [c for c in changes if not any(fnmatch.fnmatch(c[1], g) for g in excludes)]

    # K1 — 지시서 범위(제외 글롭과 상관없이 바뀐 파일 전부)
    if a.directive:
        for st, rel, old in all_changes:
            for path, what in ((rel, st), (old, 'R-옛')) if old else ((rel, st),):
                stats['targets'] += 1
                if any(glob_match(p, path) for p, _ in allow):
                    continue
                dn = [n for p, n in deny if glob_match(p, path)]
                label = {'A': '새 파일', 'M': '고침', 'D': '지움', 'R': '이름 바꿈', 'R-옛': '이름 바꾸기 전 경로',
                         'T': '종류 바뀜', '?': '추적 안 된 새 파일', 'C': '복사'}.get(what, what)
                if dn:
                    findings.append(('높음', path, 1, '지시서 %s 가 수정 0·금지로 둔 경로를 범위가 바꿈(%s)' % (dn[0], label)))
                else:
                    findings.append(('중간', path, 1, '지시서 %s 「수정 파일」 목록 밖 변경(%s)' % ('·'.join(dnames), label)))

    # K3 — 미완성 표시
    for st, rel, old in changes:
        if st == 'D' or not rel.lower().endswith(TEXT_EXT + ('.gitkeep',)) and '.' in posixpath.basename(rel):
            continue
        text = reader.get(rel)
        if text is None:
            continue
        stats['targets'] += 1
        adds = added.get(rel)
        if st == '?':
            adds = set(range(1, text.count('\n') + 2))
        adds = adds or set()
        if not text.strip() and not rel.endswith('.gitkeep') and posixpath.basename(rel) not in ('__init__.py',):
            findings.append(('중간', rel, 1, '범위가 더하거나 고친 파일이 비었음(미완성)'))
            continue
        lines = text.split('\n')
        if rel.lower().endswith('.sql'):
            body = strip_sql(text)
            if not body.strip():
                findings.append(('중간', rel, 1, '마이그레이션(.sql)에 실행할 문장이 없음(주석뿐 — 빈 마이그레이션)'))
            else:
                nb = len(re.findall(r'^\s*(?:BEGIN|START\s+TRANSACTION)\s*;', body, re.I | re.M))
                ne = len(re.findall(r'^\s*(?:COMMIT|ROLLBACK|END)\s*;', body, re.I | re.M))
                if nb > ne:
                    ln = next((k for k, l in enumerate(lines, 1) if re.match(r'^\s*(BEGIN|START\s+TRANSACTION)\s*;', l, re.I)), 1)
                    findings.append(('중간', rel, ln, '마이그레이션의 BEGIN 이 COMMIT/ROLLBACK 으로 닫히지 않음(중단된 파일?)'))
        for k in sorted(adds):
            if k > len(lines):
                continue
            l = lines[k - 1]
            if ALLOW_RE.search(l):
                continue
            if re.match(r'^(<{7}|>{7})(\s|$)|^={7}$', l):
                findings.append(('중간', rel, k, '더한 줄에 병합 충돌 표시가 남음'))
            elif rel.lower().endswith(CODE_EXT) and TODO_RE.search(l):
                findings.append(('낮음', rel, k, '더한 줄의 주석에 미완성 표시(TODO·FIXME·WIP·미완성 류)'))

    # K2 — 참조 ↔ 추적 목록
    scan = sorted(x for x in (tracked | ({c[1] for c in changes if c[0] == '?'} if tip is WORKTREE else set()))
                  if x.lower().endswith(TEXT_EXT) and not any(fnmatch.fnmatch(x, g) for g in excludes)
                  and '/vendor/' not in '/' + x and '/node_modules/' not in '/' + x)
    pending = []   # (sev, file, line, msg, target-for-ignore-check)
    for rel in scan:
        text = reader.get(rel)
        if text is None:
            continue
        record = RECORD_DOC_RE.search(rel) is not None and rel.lower().endswith(DOC_EXT + ('.json',))
        weak_off = TEST_PATH_RE.search(rel) is not None
        adds = added.get(rel, set())
        if tip is WORKTREE and rel not in tracked:
            adds = set(range(1, text.count('\n') + 2))
        fdir = posixpath.dirname(rel)
        for ln, tok, line, before in refs_in(text):
            if PLACEHOLDER_RE.search(posixpath.basename(tok)):
                stats['skip'] += 1
                continue
            cands = []
            outer_c = []
            anchored = set()
            if tok.startswith('/'):
                for ra in roots_abs:
                    if tok.startswith(ra.rstrip('/') + '/'):
                        cands.append(tok[len(ra.rstrip('/')) + 1:])
                        anchored.add(cands[-1])
                for oa in outer_abs:
                    if tok.startswith(oa + '/'):
                        outer_c.append(os.path.join(oa, tok[len(oa) + 1:]))
                if not cands and not outer_c:
                    continue
            else:
                t = tok[2:] if tok.startswith('./') else tok
                ups = len(re.match(r'^(?:\.\./)*', t).group(0)) // 3
                rest = t[3 * ups:]
                first = rest.split('/', 1)[0]
                fparts = fdir.split('/') if fdir else []
                interp = [('root', posixpath.normpath(t), ups == 0 and first in tdirs)]
                # 파일 기준 풀이는 명시적 상대 경로(./·../)와 마크다운 링크 `](…)` 에만 쓴다 — 맨 경로는 뿌리 기준
                file_rel = tok.startswith(('./', '../')) or before.endswith('](')
                if not file_rel:
                    pass
                elif ups <= len(fparts):
                    fbase = '/'.join(fparts[:len(fparts) - ups])
                    interp.append(('file', posixpath.normpath(posixpath.join(fdir, t)),
                                   (posixpath.join(fbase, first) in dirset) if fbase else first in tdirs))
                else:
                    interp.append(('file', posixpath.normpath(posixpath.join(fdir, t)), False))
                for kind, c, anch in interp:
                    if c.startswith('../'):
                        # 뿌리 밖 한 칸 = 바깥 배치(둥지 저장소). 복제본이 다른 곳에 있어도 --outer 기준으로 푼다
                        if outer_abs:
                            outer_c.append(os.path.normpath(os.path.join(outer_abs[0], c[3:])))
                    elif c not in cands:
                        cands.append(c)
                        if anch:
                            anchored.add(c)
            if any(c in tracked for c in cands):
                stats['targets'] += 1
                continue
            # 둥지 배치: 뿌리 기준 경로가 바깥 배치에 있으면(공통 문서) 통과
            if outer_abs and not tok.startswith(('/', '../')):
                outer_c.append(os.path.join(outer_abs[0], posixpath.normpath(tok[2:] if tok.startswith('./') else tok)))
            if any(os.path.exists(o) for o in outer_c):
                stats['targets'] += 1
                continue
            first_ok = [c for c in cands if c in anchored]
            # K2a — 작업 트리에는 있는데 추적 안 됨. 가리키는 쪽이 추적 파일이고 자기 자신이 아닐 때만
            on_disk = [c for c in first_ok if live and c != rel and os.path.isfile(os.path.join(root, c))]
            if on_disk:
                if rel in tracked:
                    stats['targets'] += 1
                    pending.append(('중간', rel, ln, '`%s` → %s 가 작업 트리에는 있으나 git 추적 목록에 없음(git add 누락?)'
                                    % (tok, on_disk[0]), on_disk[0]))
                else:
                    stats['skip'] += 1      # 둘 다 새 파일 — 같은 변경 안의 관계
                continue
            # K2b·K2c 는 약한 갈래 — 기록 문서·시험 파일(가짜 견본 자료)·옛/이동/예시 줄·쓰기 대상은 보지 않는다
            if record or weak_off or HIST_LINE_RE.search(line) or EXAMPLE_RE.search(line) or WRITE_CTX_RE.search(before):
                stats['skip'] += 1
                continue
            if tok.startswith('../'):
                # 바깥 배치 쪽으로만 판정한다: 그 첫 폴더가 바깥 배치에 있을 때, 더한 줄이면 낮음
                ok_dir = [o for o in outer_c if os.path.isdir(os.path.dirname(o))]
                if ok_dir and ln in adds:
                    stats['targets'] += 1
                    pending.append(('낮음', rel, ln, '범위가 더한 줄의 `%s` 가 바깥 배치에 없음' % tok, None))
                else:
                    stats['skip'] += 1
                continue
            # 부모 폴더가 저장소에 있는 경로만(다른 배치를 가리키는 줄은 판정하지 않는다)
            first_ok = [c for c in first_ok if posixpath.dirname(c) in dirset]
            if not first_ok:
                stats['skip'] += 1
                continue
            if ln in adds:
                stats['targets'] += 1
                pending.append(('낮음', rel, ln, '범위가 더한 줄의 `%s` → %s 가 추적 목록에도 트리에도 없음' % (tok, first_ok[0]), None))
                continue
            gone = [c for c in first_ok if c in history]
            if gone:
                stats['targets'] += 1
                pending.append(('낮음', rel, ln, '`%s` → 예전에 추적되던 %s 를 여전히 가리킴(지금은 지워짐·옮겨짐)' % (tok, gone[0]), None))
                continue
            stats['skip'] += 1
    reader.close()
    ign = is_ignored(root, sorted({p[4] for p in pending if p[4]}))
    for sev, rel, ln, msg, tgt in pending:
        if tgt and tgt in ign:
            stats['targets'] -= 1
            stats['skip'] += 1
            continue
        findings.append((sev, rel, ln, msg))

    seen = set()
    uniq = []
    for f in findings:
        k = (f[1], f[2], f[3])
        if k not in seen:
            seen.add(k)
            uniq.append(f)
    for sev, rel, ln, msg in uniq:
        print('FINDING %s %s %s:%d %s' % (RULE, sev, rel, ln, msg))
    print('RESULT pass=%d fail=%d skip=%d' % (max(0, stats['targets'] - len(uniq)), len(uniq), stats['skip']))
    note('범위 %s..%s · 바뀐 파일 %d · 지시서 %d · 추적 %d' % (base[:7], 'WORKTREE' if tip is WORKTREE else tip[:7],
                                                         len(changes), len(a.directive), len(tracked)))
    return 1 if uniq else 0


def strip_sql(text):
    t = re.sub(r'\$([A-Za-z_]*)\$.*?\$\1\$', ' ', text, flags=re.S)
    t = re.sub(r'/\*.*?\*/', ' ', t, flags=re.S)
    t = re.sub(r"'(?:''|[^'])*'", "''", t)
    t = re.sub(r'--[^\n]*', '', t)
    t = re.sub(r'^\s*\\[^\n]*', '', t, flags=re.M)
    return t


if __name__ == '__main__':
    sys.exit(main())
