#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
R5 경로 대조 (path cross-check) — 1-F 정적 규칙, python3 stdlib 만, 파일은 읽기만.

answers: AK-0067 · AK-0068

무엇을 부류로 잡나:
  앱의 주소 구조(모듈 접두 base-path, 라우트 표)나 저장소의 파일 배치가 바뀌었는데, 스크립트·시험·문서에
  적힌 경로가 옛 모양 그대로 남는 결함이다. 문법 검사는 통과하고, 그 줄이 실제로 돌 때(설치 뒤 점검,
  문서를 따라 친 명령, 시험의 대조 단계)에야 308·404·「파일 없음」으로 드러난다. 두 갈래를 본다.
    U  앱 URL 경로 ↔ 라우트 표 + base-path
       · 앱 모양은 대상 트리에서 글자로 읽는다: PHP 최상위 `const <모듈>_BASE = '/…'` · `const <모듈>_ROUTES = [
         ['메서드', '/경로', …], … ]`(접두가 없는 표는 사이트 층) · public/ 아래 파일(정적 파일) · 추적된 nginx
         `*.conf` 중 `root …/public` 인 server 블록의 listen·server_name(= 앱 원점).
       · 원점이 앱인 URL 만 본다: 앱 server 블록이 듣는 루프백 포트, 같은 파일이 `php -S <주소> -t public` 으로
         띄우는 포트, 앱 server_name 호스트. 바깥 주소·예시 호스트·다른 앱 포트는 보지 않는다.
       · 파일 안의 단순 대입(`BASE="http://…/tbox"`, `SITE = "…"`)을 풀어 `$BASE/api/…`·`BASE + "/…"`·
         f"{BASE}/…" 도 본다. 값이 인자·환경에서 와서 풀리지 않으면 skip 으로 센다.
       · 발견: (a) 접두 없는 사이트 층 경로가 어떤 모듈의 라우트와 맞는다 = base-path 빠짐(옛 주소는 308)
               (b) 모듈 접두 아래 경로가 그 모듈 라우트 표·정적 파일 어디에도 없다 = 없는 경로
         경로 조각이 변수·자리표시자(`$ID`·`{id}`·`<…>`·`…`)면 무엇이든 맞는 것으로 본다(느슨하게 → 정밀도 먼저).
       · 일부러 옛 주소·없는 주소를 치는 줄은 뺀다: 같은 논리 줄이나 바로 위 주석 줄에 30x·404·410·
         「옛 주소」·redirect 류 표시가 있음, curl `-L`(따라가기), 같은 줄에 접두 붙은 새 경로가 함께 있음(대응표).
         405 는 뺄 표시가 아니다 — 「있는 경로에 틀린 메서드」 시험이라 경로 자체는 맞아야 한다.
       · 대상: 스크립트·시험(scripts/·tests/·deploy/ 의 .sh·.py·.php·셔뱅) 과 문서(.md, 문서 안 코드 블록
         포함). 시점이 박힌 기록 문서(STAGES*·CHANGELOG*·이름에 날짜가 든 문서)와 wiki/ 글은 그때의 기록이라
         보지 않는다. 앱 코드(src/·public/)의 주소 조립은 R5 대상이 아니다.
    F  저장소 안 파일 경로 참조 ↔ 실제 파일
       · 파일 자신의 위치에서 뿌리를 구하는 모양(셸 `$(cd "$(dirname "$0")/.." && pwd)`, 파이썬
         `os.path.dirname(…__file__…)`·`Path(__file__)…parent(s)`, PHP `__DIR__`·`dirname(__DIR__, n)`)을 풀고,
         그 뒤에 글자 그대로 붙은 경로(`"$ROOT/docs/x.md"`, `os.path.join(ROOT, 'a', 'b')`, `__DIR__ . '/../x'`)가
         트리에 있는지 본다. 뿌리 밖(`../`)은 바깥 배치(--outer)에서 본다 — 그 첫 폴더가 없으면 skip.
       · 뺀다: 쓰기·지우기 대상(`>`·`tee`·`mkdir`·`touch`·`rm`·`ln`·cp/mv 의 목적지), 있는지 먼저 묻는 줄
         (`[ -f … ]`·`is_file`·`os.path.exists` …), .gitignore 에 걸리는 경로, glob·변수가 섞인 경로.
       · PHP 의 require/include 가 없는 파일을 가리키면 중간(실행 중 치명 오류), 그 밖은 낮음.
  `qcheck-allow: R5` 가 같은 줄에 있으면 뺀다.

입력: --root <트리>(기본: 이 파일 기준 저장소 뿌리) · --outer <바깥 배치>(기본 <root>/..; none 이면 뿌리 밖 참조는 skip)
      --exclude GLOB(여러 번, 기본 tests/static/fixtures/*)
출력: FINDING R5 <심각도> <파일:줄> <설명> · 끝 줄 RESULT pass=<판정한 참조 - 발견> fail=<발견 수> skip=<풀지 못한 참조 수>
rc:   0 발견 없음 · 1 발견 있음 · 2 실행 오류(뿌리 없음·목록 실패, 또는 라우트 표를 하나도 못 읽고 파일 참조도 0 = 검사 못 함)
"""
import argparse
import fnmatch
import os
import re
import subprocess
import sys

RULE = 'R5'
DEFAULT_EXCLUDES = ['tests/static/fixtures/*']
ALLOW_RE = re.compile(r'qcheck-allow\s*[:=]?\s*(?:[A-Za-z0-9_-]+\s*,\s*)*R5\b')
LOOPBACK = {'127.0.0.1', 'localhost', '::1', '[::1]', '0.0.0.0'}
METHODS = 'GET|POST|PUT|PATCH|DELETE|HEAD|OPTIONS'
MAX_BYTES = 4 * 1024 * 1024
HISTORY_DOC_RE = re.compile(r'(^|/)(STAGES[^/]*|CHANGELOG[^/]*|[^/]*(directive|review)[^/]*)$|\d{4}-\d{2}-\d{2}', re.I)
URLISH_RE = re.compile(r'URL|BASE|SITE|HOST|ORIGIN|HTTP|ENDPOINT|SERVER', re.I)
SCRIPT_DIRS = ('scripts/', 'tests/', 'deploy/')
NEG_CTX_RE = re.compile(
    r'(?<![\d.:])(30[1278]|404|410)(?![\d])|옛\s*(주소|경로)|이전\s*주소|legacy|redirect|리다이렉트'
    r'|없는\s*(주소|경로|라우트)|존재하지\s*않는|not[ _-]?found', re.I)
FOLLOW_RE = re.compile(r'(^|\s)(-[A-Za-z]*L[A-Za-z]*|--location)(\s|$)')
WILD_CH = set('$`{}<>*%…')


def note(msg):
    sys.stderr.write('R5: %s\n' % msg)


# ─── 파일 목록 ────────────────────────────────────────────────────────────────
def git(root, *args):
    return subprocess.run(['git', '-C', root] + list(args), stdout=subprocess.PIPE,
                          stderr=subprocess.PIPE, timeout=120)


def repo_top(root):
    try:
        p = git(root, 'rev-parse', '--show-toplevel')
    except (OSError, subprocess.SubprocessError):
        return None
    return os.path.realpath(p.stdout.decode('utf-8', 'replace').strip()) if p.returncode == 0 else None


def list_files(root, use_git):
    if use_git:
        p = git(root, 'ls-files', '-z', '--cached', '--others', '--exclude-standard')
        if p.returncode != 0:
            raise RuntimeError('git ls-files 실패')
        return sorted(set(x for x in p.stdout.decode('utf-8', 'surrogateescape').split('\0') if x))
    out = []
    for d, dirs, files in os.walk(root):
        dirs[:] = [x for x in dirs if x != '.git']
        for f in files:
            out.append(os.path.relpath(os.path.join(d, f), root).replace(os.sep, '/'))
    return sorted(out)


def read_text(path):
    try:
        if os.path.getsize(path) > MAX_BYTES:
            return None
        with open(path, 'rb') as fh:
            data = fh.read()
    except OSError:
        return None
    if b'\0' in data[:8000]:
        return None
    return data.decode('utf-8', 'replace')


class Ignore:
    """.gitignore 판정 — git 트리면 git check-ignore, 아니면 뿌리 .gitignore 의 단순 패턴."""

    def __init__(self, root, use_git):
        self.root, self.use_git, self.cache, self.pats = root, use_git, {}, []
        if not use_git:
            t = read_text(os.path.join(root, '.gitignore')) or ''
            for ln in t.split('\n'):
                ln = ln.strip()
                if ln and not ln.startswith('#') and not ln.startswith('!'):
                    self.pats.append(ln)

    def ignored(self, rel):
        if rel in self.cache:
            return self.cache[rel]
        res = False
        if self.use_git:
            try:
                res = git(self.root, 'check-ignore', '-q', '--no-index', '--', rel).returncode == 0
            except (OSError, subprocess.SubprocessError):
                res = False
        else:
            parts = rel.split('/')
            for pat in self.pats:
                p = pat.strip('/')
                anchored = pat.startswith('/') or '/' in p
                for i in range(len(parts)):
                    sub = '/'.join(parts[:i + 1])
                    if fnmatch.fnmatch(sub, p) or (not anchored and fnmatch.fnmatch(parts[i], p)):
                        res = True
                        break
                if res:
                    break
        self.cache[rel] = res
        return res


# ─── PHP 글자 읽기(실행하지 않음) ─────────────────────────────────────────────
def php_strip(text):
    """주석을 공백으로 바꾼 PHP 원문(문자열은 그대로). 줄 수는 유지한다."""
    out, i, n, q = [], 0, len(text), None
    while i < n:
        c = text[i]
        if q:
            out.append(c)
            if c == '\\' and i + 1 < n:
                out.append(text[i + 1])
                i += 2
                continue
            if c == q:
                q = None
            i += 1
            continue
        if c in '\'"':
            q = c
            out.append(c)
            i += 1
            continue
        if c == '/' and text.startswith('//', i) or c == '#' and not text.startswith('#[', i):
            j = text.find('\n', i)
            j = n if j < 0 else j
            out.append(' ' * (j - i))
            i = j
            continue
        if text.startswith('/*', i):
            j = text.find('*/', i + 2)
            j = n if j < 0 else j + 2
            out.append(''.join(ch if ch == '\n' else ' ' for ch in text[i:j]))
            i = j
            continue
        out.append(c)
        i += 1
    return ''.join(out)


def bracket_body(text, start):
    """text[start] == '[' 에서 짝 ']' 까지의 본문."""
    depth, q, j = 0, None, start
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
                return text[start + 1:j]
        j += 1
    return None


def load_app(root, files):
    """→ bases {모듈: '/접두'}, tables {모듈 또는 None: [(메서드, 조각 목록, 원문)]}, 출처."""
    bases, tables, src = {}, {}, []
    phps = [f for f in files if f.endswith('.php') and (f.startswith('src/') or f.startswith('public/'))
            and '/vendor/' not in f]
    texts = {}
    for f in phps:
        t = read_text(os.path.join(root, f))
        if t is not None:
            texts[f] = php_strip(t)
    for f, t in texts.items():
        for m in re.finditer(r"(?m)^const\s+([A-Z][A-Z0-9_]*)_BASE\s*=\s*'(/[^']*)'\s*;", t):
            bases[m.group(1)] = m.group(2).rstrip('/') or '/'
        for m in re.finditer(r"(?m)^\s*define\(\s*'([A-Z][A-Z0-9_]*)_BASE'\s*,\s*'(/[^']*)'\s*\)", t):
            bases[m.group(1)] = m.group(2).rstrip('/') or '/'
    for f, t in texts.items():
        for m in re.finditer(r'(?m)^const\s+([A-Z][A-Z0-9_]*)_ROUTES\s*=\s*\[', t):
            body = bracket_body(t, m.end() - 1)
            if body is None:
                continue
            ents = []
            for em in re.finditer(r"\[\s*'(%s)'\s*,\s*'(/[^']*)'" % METHODS, body):
                ents.append((em.group(1), segs(em.group(2)), em.group(2)))
            if not ents:
                continue
            mod = m.group(1) if m.group(1) in bases else None
            tables.setdefault(mod, []).extend(ents)
            src.append('%s:%s_ROUTES(%d)' % (f, m.group(1), len(ents)))
    return bases, tables, src


def load_origins(root, files):
    """추적 nginx *.conf 의 server 블록 → (앱 루프백 포트, 앱 호스트, 다른 앱 포트)."""
    app_ports, app_hosts, other_ports = set(), set(), set()
    seen_conf = False
    for f in files:
        if not f.endswith('.conf'):
            continue
        t = read_text(os.path.join(root, f))
        if t is None or 'server' not in t:
            continue
        t = re.sub(r'#[^\n]*', '', t)
        for m in re.finditer(r'\bserver\s*\{', t):
            depth, j = 0, m.end() - 1
            while j < len(t):
                if t[j] == '{':
                    depth += 1
                elif t[j] == '}':
                    depth -= 1
                    if depth == 0:
                        break
                j += 1
            blk = t[m.end():j]
            # 안쪽 location 블록을 빼고 server 층 지시어만 본다
            flat, d = [], 0
            for ch in blk:
                if ch == '{':
                    d += 1
                elif ch == '}':
                    d -= 1
                elif d == 0:
                    flat.append(ch)
            flat = ''.join(flat)
            roots = re.findall(r'(?m)^\s*root\s+(\S+?)\s*;', flat)
            if not roots and not re.search(r'(?m)^\s*listen\s', flat):
                continue
            seen_conf = True
            is_app = any(r.rstrip('/').endswith('/public') for r in roots)
            ports, hosts = set(), set()
            for lm in re.finditer(r'(?m)^\s*listen\s+(\S+?)(?:\s[^;]*)?;', flat):
                v = lm.group(1)
                mm = re.match(r'^(?:(\[[^\]]+\]|[^:\[\]]+):)?(\d+)$', v)
                if mm:
                    h = mm.group(1)
                    if h is None or h in LOOPBACK or h in ('*', '[::]'):
                        ports.add(int(mm.group(2)))
            for sm in re.finditer(r'(?m)^\s*server_name\s+([^;]+);', flat):
                hosts |= {h for h in sm.group(1).split() if h not in ('_', '""', "''") and '*' not in h}
            if is_app:
                app_ports |= ports
                app_hosts |= {h.lower() for h in hosts}
            else:
                other_ports |= ports
    return app_ports, app_hosts, other_ports - app_ports, seen_conf


# ─── 경로 조각 · 대조 ─────────────────────────────────────────────────────────
def segs(path):
    return [s for s in path.split('/') if s != '']


def seg_kind(s):
    """'lit' 글자 조각 · 'one' 한 조각 자리표시 · 'many' 통째 변수(빗금이 들어 있을 수 있음)."""
    if re.fullmatch(r'\$\{?[A-Za-z_][A-Za-z0-9_]*\}?|\$\([^)]*\)|\{[A-Za-z_][A-Za-z0-9_.\[\]\'"]*\}|\.\.\.|…', s):
        return 'many'
    if any(c in WILD_CH for c in s):
        return 'one'
    return 'lit'


def match_route(ref, pat):
    """ref: 참조 조각 목록, pat: 라우트 조각 목록 → 맞을 수 있나(느슨하게)."""
    def rec(i, j):
        if i == len(ref):
            return j == len(pat)
        k = seg_kind(ref[i])
        if k == 'many':
            return any(rec(i + 1, jj) for jj in range(j + 1, len(pat) + 1))
        if j == len(pat):
            return False
        p = pat[j]
        if k == 'one' or (p.startswith('{') and p.endswith('}')) or p == ref[i]:
            return rec(i + 1, j + 1)
        return False
    return rec(0, 0)


def any_route(ref, table):
    return any(match_route(ref, pat) for _, pat, _ in table)


def static_exists(root, rel_segs):
    if not rel_segs or any(seg_kind(s) != 'lit' for s in rel_segs):
        return False
    return os.path.exists(os.path.join(root, 'public', *rel_segs))


class App:
    def __init__(self, root, bases, tables, origins):
        self.root, self.bases, self.tables = root, bases, tables
        self.app_ports, self.app_hosts, self.other_ports, self.seen_conf = origins

    def is_app_origin(self, scheme, host, port, served):
        host = host.lower()
        if port is None:
            port = 443 if scheme == 'https' else 80
        if host in self.app_hosts:
            return True
        if host in LOOPBACK:
            if (host, port) in served or ('*', port) in served:
                return True
            if not self.seen_conf:
                return port not in self.other_ports
            return port in self.app_ports
        return False

    def judge(self, path):
        """→ ('ok'|'unknown'|'skip', None) 또는 ('base_missing'|'no_route', 설명)."""
        path = re.split(r'[?#]', path, 1)[0]
        ref = segs(path)
        if not ref:
            return ('ok', None)
        if seg_kind(ref[0]) != 'lit':
            return ('skip', None)
        for mod, base in sorted(self.bases.items(), key=lambda kv: -len(kv[1])):
            bs = segs(base)
            if bs and ref[:len(bs)] == bs:
                rest = ref[len(bs):]
                table = self.tables.get(mod)
                if table is None:
                    return ('skip', None)
                if not rest or any_route(rest, table) or static_exists(self.root, rest):
                    return ('ok', None)
                if seg_kind(rest[0]) != 'lit':
                    return ('skip', None)
                other = [m for m, tb in self.tables.items() if m != mod and m is not None and any_route(rest, tb)]
                extra = ''
                if other:
                    extra = ' — %s 모듈(%s)의 경로와는 맞는다' % (other[0], self.bases.get(other[0], '?'))
                return ('no_route', '앱 경로 %s 가 %s_ROUTES(접두 %s) 에도 정적 파일에도 없다%s'
                        % ('/' + '/'.join(ref), mod, base, extra))
        site = self.tables.get(None)
        if site and any_route(ref, site):
            return ('ok', None)
        if static_exists(self.root, ref):
            return ('ok', None)
        for mod, base in sorted(self.bases.items()):
            table = self.tables.get(mod)
            if table and any_route(ref, table):
                return ('base_missing', '앱 경로 %s 에 base-path %s 가 빠졌다 — %s_ROUTES 의 모듈 경로다(%s%s 가 맞는 주소, 옛 주소는 사이트 층에서 404·308)'
                        % ('/' + '/'.join(ref), base, mod, base, '/' + '/'.join(ref)))
        return ('unknown', None)


# ─── 파일 안 대입 풀기 ────────────────────────────────────────────────────────
SH_ASSIGN_RE = re.compile(
    r'(?:^|[;&|(]\s*|\s)(?:export\s+|readonly\s+|local\s+|declare\s+(?:-\w+\s+)*)?([A-Za-z_][A-Za-z0-9_]*)='
    r'("(?:[^"\\]|\\.)*"|\'[^\']*\'|[^\s;&|()]*)')
SH_DEFAULT_RE = re.compile(r':\s+"?\$\{([A-Za-z_][A-Za-z0-9_]*):?=([^}]*)\}"?')
PY_ASSIGN_RE = re.compile(r'^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.+?)\s*(?:#.*)?$')
PHP_ASSIGN_RE = re.compile(r'\$([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(\'[^\']*\'|"[^"]*")\s*;')
SH_VAR_RE = re.compile(r'\$\{([A-Za-z_][A-Za-z0-9_]*)(?:(:?[-=])([^}]*))?\}|\$([A-Za-z_][A-Za-z0-9_]*)')


def unquote_sh(v):
    v = v.strip()
    if len(v) >= 2 and v[0] == v[-1] and v[0] in '"\'':
        return v[1:-1]
    return v


def py_value(rhs):
    """파이썬 대입 오른쪽 → 셸 모양 틀('$NAME' 자리) 또는 None."""
    rhs = rhs.strip()
    m = re.fullmatch(r'os\.environ\.get\(\s*["\'][^"\']*["\']\s*,\s*(.+)\)', rhs)
    if m:
        rhs = m.group(1).strip()
    parts = re.split(r'\s*\+\s*', rhs)
    out = []
    for p in parts:
        sm = re.fullmatch(r'([rbuf]*)(["\'])(.*)\2', p, re.I)
        if sm:
            s = sm.group(3)
            if 'f' in sm.group(1).lower():
                s = re.sub(r'\{([A-Za-z_][A-Za-z0-9_]*)\}', r'${\1}', s)
            out.append(s)
        elif re.fullmatch(r'[A-Za-z_][A-Za-z0-9_]*', p):
            out.append('${%s}' % p)
        elif re.fullmatch(r'str\(([A-Za-z_][A-Za-z0-9_]*)\)', p):
            out.append('${%s}' % re.fullmatch(r'str\(([A-Za-z_][A-Za-z0-9_]*)\)', p).group(1))
        else:
            return None
    return ''.join(out)


def collect_vars(kind, lines):
    vs = {}
    for ln in lines:
        s = ln.strip()
        if kind in ('sh', 'md'):
            if s.startswith('#'):
                continue
            for m in SH_ASSIGN_RE.finditer(ln):
                vs.setdefault(m.group(1), []).append(unquote_sh(m.group(2)))
            for m in SH_DEFAULT_RE.finditer(ln):
                vs.setdefault(m.group(1), []).append(m.group(2))
        elif kind == 'py':
            if s.startswith('#'):
                continue
            m = PY_ASSIGN_RE.match(ln)
            if m:
                v = py_value(m.group(2))
                vs.setdefault(m.group(1), []).append(v if v is not None else '\x00')
        elif kind == 'php':
            for m in PHP_ASSIGN_RE.finditer(ln):
                vs.setdefault(m.group(1), []).append(unquote_sh(m.group(2)))
    return vs


def expand(s, vs, depth=0):
    """→ 풀린 문자열 후보 목록(최대 4). 못 푼 변수는 그대로 남긴다. '\\x00' 은 풀 수 없음."""
    if depth > 6:
        return [s]
    m = SH_VAR_RE.search(s)
    if not m:
        return [s]
    name = m.group(1) or m.group(4)
    op, dflt = m.group(2), m.group(3)
    vals = [v for v in vs.get(name, []) if v is not None]
    self_ref = [v for v in vals if SH_VAR_RE.fullmatch(v.strip()) and (SH_VAR_RE.fullmatch(v.strip()).group(1) or SH_VAR_RE.fullmatch(v.strip()).group(4)) == name]
    vals = [v for v in vals if v not in self_ref]
    cands = []
    if vals and '\x00' not in vals:
        for v in dict.fromkeys(vals):
            cands.append(v)
    elif op and dflt is not None and not vals:
        cands.append(dflt)
    elif self_ref and not vals:
        for v in self_ref:
            mm = SH_VAR_RE.fullmatch(v.strip())
            if mm.group(3) is not None:
                cands.append(mm.group(3))
    if not cands:
        tail = expand(s[m.end():], vs, depth)
        return [s[:m.end()] + t for t in tail][:4]
    out = []
    for c in cands[:4]:
        for x in expand(c, vs, depth + 1):
            for t in expand(s[m.end():], vs, depth):
                out.append(s[:m.start()] + x + t)
                if len(out) >= 4:
                    return out
    return out


URL_RE = re.compile(r'(https?)://([A-Za-z0-9._~\-]+|\[[0-9a-fA-F:]+\]|\$\{?[A-Za-z_][A-Za-z0-9_]*\}?)'
                    r'(?::([0-9]+|\$\{?[A-Za-z_][A-Za-z0-9_]*\}?))?(/[^\s"\'`()|;\\,]*)?')


def split_url(u):
    m = URL_RE.match(u)
    if not m:
        return None
    port = m.group(3)
    if port is not None and not port.isdigit():
        return None
    host = m.group(2)
    if '$' in host:
        return None
    return m.group(1), host, int(port) if port else None, m.group(4) or '/'


def clean_path(p):
    p = p.rstrip('.,:;!')
    return p


def served_ports(lines, vs):
    out = set()
    for ln in lines:
        for m in re.finditer(r'php\s+-S\s+["\']?([^\s"\']+)', ln):
            for v in expand(m.group(1), vs):
                mm = re.match(r'^(\[[^\]]+\]|[^:]+):(\d+)$', v)
                if mm:
                    h = mm.group(1)
                    out.add(('*' if h in LOOPBACK else h, int(mm.group(2))))
    return out


# ─── 참조 뽑기 ────────────────────────────────────────────────────────────────
def file_kind(rel, text):
    if rel.endswith('.md'):
        return 'md'
    if rel.endswith('.py'):
        return 'py'
    if rel.endswith('.php'):
        return 'php'
    if rel.endswith('.sh') or rel.endswith('.bash'):
        return 'sh'
    first = text.split('\n', 1)[0]
    if first.startswith('#!'):
        if 'python' in first:
            return 'py'
        if 'php' in first:
            return 'php'
        if re.search(r'\b(ba|da|k|z)?sh\b', first):
            return 'sh'
    return None


def logical_groups(lines):
    """셸 줄 잇기(끝 백슬래시) → 물리 줄 번호마다 (논리 줄 원문)."""
    out = [None] * len(lines)
    i = 0
    while i < len(lines):
        j = i
        buf = [lines[i]]
        while buf[-1].rstrip().endswith('\\') and j + 1 < len(lines):
            j += 1
            buf.append(lines[j])
        joined = ' '.join(x.rstrip().rstrip('\\') for x in buf)
        for k in range(i, j + 1):
            out[k] = joined
        i = j + 1
    return out


def prev_comments(lines, i, kind):
    out = []
    k = i - 1
    while k >= 0 and len(out) < 2:
        s = lines[k].strip()
        if kind in ('sh', 'py', 'md') and s.startswith('#'):
            out.append(s)
            k -= 1
            continue
        if kind == 'php' and (s.startswith('//') or s.startswith('*') or s.startswith('#')):
            out.append(s)
            k -= 1
            continue
        break
    return ' '.join(out)


def url_refs(kind, lines, vs, code_mask):
    """→ [(줄 번호 0-기준, 원문 조각, 풀린 URL 후보 목록)]."""
    refs = []
    for i, ln in enumerate(lines):
        in_code = code_mask[i]
        for m in URL_RE.finditer(ln):
            refs.append((i, m.group(0), expand(m.group(0), vs) if (kind != 'md' or in_code) else [m.group(0)]))
        if kind == 'md' and not in_code:
            continue
        if kind in ('sh', 'md', 'php'):
            pat = r'\$\{?([A-Za-z_][A-Za-z0-9_]*)(?::[-=][^}]*)?\}?(/[^\s"\'`()|;\\,]*)' if kind != 'php' else \
                  r'\$([A-Za-z_][A-Za-z0-9_]*)\s*\.\s*[\'"](/[^\'"]*)[\'"]'
            for m in re.finditer(pat, ln):
                if ln[max(0, m.start() - 3):m.start()].endswith('://'):
                    continue
                add_var_ref(refs, i, m.group(0), m.group(1), m.group(2), vs)
        if kind == 'py':
            for m in re.finditer(r'\b([A-Za-z_][A-Za-z0-9_]*)\s*\+\s*[rbu]*(["\'])(/[^"\']*)\2', ln):
                add_var_ref(refs, i, m.group(0), m.group(1), m.group(3), vs)
            for m in re.finditer(r'\{([A-Za-z_][A-Za-z0-9_]*)\}(/[^"\'{}\s]*)', ln):
                if not re.search(r'\bf["\']', ln[:m.start()]):
                    continue
                add_var_ref(refs, i, m.group(0), m.group(1), m.group(2), vs)
    return refs


def add_var_ref(refs, i, frag, name, suffix, vs):
    """변수 + '/경로' 참조. 값이 URL 로 풀리면 판정 대상, 풀리지 않는데 이름이 URL 모양이면 skip 대상,
    URL 이 아닌 값(파일 경로 등)이면 URL 참조가 아니다."""
    if name not in vs:
        if URLISH_RE.search(name):
            refs.append((i, frag, None))
        return
    exp = expand('${%s}' % name, vs)
    if any('://' in c for c in exp):
        refs.append((i, frag, ['%s%s' % (c, suffix) for c in exp]))
    elif URLISH_RE.search(name) and any('$' in c or '\x00' in c for c in exp):
        refs.append((i, frag, None))


# ─── F: 파일 경로 참조 ───────────────────────────────────────────────────────
SH_ROOTVAR_RE = re.compile(
    r'([A-Za-z_][A-Za-z0-9_]*)=["\']?\$\(\s*cd\s+["\']?\$\(\s*dirname\s+["\']?\$\{?(?:0|BASH_SOURCE(?:\[0\])?)\}?["\']?\s*\)'
    r'(/[^"\'\s)&]*)?["\']?\s*(?:&&|;)\s*pwd(?:\s+-[PL])?\s*\)')
SH_DIRVAR_RE = re.compile(
    r'([A-Za-z_][A-Za-z0-9_]*)=["\']?\$\(\s*dirname\s+["\']?\$\{?(?:0|BASH_SOURCE(?:\[0\])?)\}?["\']?\s*\)["\']?\s*$')
SH_CDVAR_RE = re.compile(
    r'([A-Za-z_][A-Za-z0-9_]*)=["\']?\$\(\s*cd\s+["\']?\$\{?([A-Za-z_][A-Za-z0-9_]*)\}?(/[^"\'\s)&]*)?["\']?\s*(?:&&|;)\s*pwd(?:\s+-[PL])?\s*\)')
PY_ROOTVAR_RE = re.compile(r'^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.+)$')
LIT_PATH = r'[A-Za-z0-9_.\-/@+]+'
GUARD_RE = re.compile(r'(\[\[?|\btest)\s+!?\s*-[efdrsxwL]\s|is_file\(|file_exists\(|is_dir\(|is_readable\(|os\.path\.(exists|isfile|isdir)\('
                      r'|\.exists\(\)|\.is_file\(\)|\.is_dir\(\)|glob\(')
WRITE_BEFORE_RE = re.compile(r'(>>?|\btee(\s+-a)?|\bmkdir(\s+-\w+)*|\btouch|\brm(\s+-\w+)*|\bln(\s+-\w+)*|\brmdir|\bchmod(\s+\S+)?|\bchown(\s+\S+)?)\s*["\']?$')
COPY_RE = re.compile(r'\b(cp|mv|install|rsync)\b')


def py_root_expr(expr, filedir):
    """파이썬 식이 이 파일 위치에서 구한 폴더면 그 경로."""
    e = expr.replace(' ', '')
    m = re.fullmatch(r'((?:os\.path\.dirname\()+)(?:os\.path\.(?:abspath|realpath)\()?__file__\)?(\)*)', e)
    if m:
        n = m.group(1).count('dirname(')
        return os.path.normpath(os.path.join(filedir, *(['..'] * (n - 1)))) if n >= 1 else None
    m = re.fullmatch(r'(?:pathlib\.)?Path\(__file__\)(?:\.resolve\(\)|\.absolute\(\))?((?:\.parent)+|\.parents\[(\d)\])', e)
    if m:
        n = m.group(1).count('.parent') if m.group(2) is None else int(m.group(2)) + 1
        return os.path.normpath(os.path.join(filedir, *(['..'] * (n - 1))))
    return None


def file_refs(kind, rel, lines, root):
    """→ [(줄 0-기준, 원문 조각, 절대 경로, require 인가)]."""
    filedir = os.path.dirname(os.path.join(root, rel))
    refs = []
    if kind == 'sh':
        rootvars = {}
        for ln in lines:
            m = SH_ROOTVAR_RE.search(ln)
            if m:
                rootvars.setdefault(m.group(1), set()).add(os.path.normpath(filedir + (m.group(2) or '')))
                continue
            m = SH_DIRVAR_RE.search(ln)
            if m:
                rootvars.setdefault(m.group(1), set()).add(os.path.normpath(filedir))
        for ln in lines:
            m = SH_CDVAR_RE.search(ln)
            if m and m.group(2) in rootvars and len(rootvars[m.group(2)]) == 1:
                base = next(iter(rootvars[m.group(2)]))
                rootvars.setdefault(m.group(1), set()).add(os.path.normpath(base + (m.group(3) or '')))
        good = {k: next(iter(v)) for k, v in rootvars.items() if len(v) == 1}
        for i, ln in enumerate(lines):
            if ln.strip().startswith('#'):
                continue
            for m in re.finditer(r'\$\{?([A-Za-z_][A-Za-z0-9_]*)\}?/(%s)(?=["\'\s;)|&>`]|$)' % LIT_PATH, ln):
                if m.group(1) in good:
                    refs.append((i, m.group(0), os.path.normpath(os.path.join(good[m.group(1)], m.group(2))), False, m.start()))
    elif kind == 'py':
        rootvars = {}
        for ln in lines:
            m = PY_ROOTVAR_RE.match(ln)
            if m:
                d = py_root_expr(m.group(2).split('#')[0].strip(), filedir)
                if d:
                    rootvars.setdefault(m.group(1), set()).add(d)
        good = {k: next(iter(v)) for k, v in rootvars.items() if len(v) == 1}
        for i, ln in enumerate(lines):
            if ln.strip().startswith('#'):
                continue
            for m in re.finditer(r'os\.path\.join\(\s*([A-Za-z_][A-Za-z0-9_]*)\s*,((?:\s*["\']%s["\']\s*,?)+)\s*\)' % LIT_PATH, ln):
                if m.group(1) in good:
                    parts = re.findall(r'["\'](%s)["\']' % LIT_PATH, m.group(2))
                    refs.append((i, m.group(0), os.path.normpath(os.path.join(good[m.group(1)], *parts)), False, m.start()))
            for m in re.finditer(r'\b([A-Za-z_][A-Za-z0-9_]*)((?:\s*/\s*["\']%s["\'])+)' % LIT_PATH, ln):
                if m.group(1) in good:
                    parts = re.findall(r'["\'](%s)["\']' % LIT_PATH, m.group(2))
                    refs.append((i, m.group(0), os.path.normpath(os.path.join(good[m.group(1)], *parts)), False, m.start()))
            for m in re.finditer(r'\b([A-Za-z_][A-Za-z0-9_]*)\s*\+\s*["\'](/%s)["\']' % LIT_PATH, ln):
                if m.group(1) in good:
                    refs.append((i, m.group(0), os.path.normpath(good[m.group(1)] + m.group(2)), False, m.start()))
    elif kind == 'php':
        stripped = php_strip('\n'.join(lines)).split('\n')
        for i, ln in enumerate(stripped):
            for m in re.finditer(r"(dirname\(\s*__DIR__\s*(?:,\s*(\d)\s*)?\)|__DIR__)\s*\.\s*(['\"])(/%s)\3(?!\s*\.)" % LIT_PATH, ln):
                up = 0
                if m.group(1).startswith('dirname'):
                    up = int(m.group(2) or 1)
                base = os.path.normpath(os.path.join(filedir, *(['..'] * up))) if up else filedir
                is_req = bool(re.search(r'\b(require|include)(_once)?\b[\s(]*$', ln[:m.start()]))
                refs.append((i, m.group(0), os.path.normpath(base + m.group(4)), is_req, m.start()))
    return refs


# ─── 본체 ─────────────────────────────────────────────────────────────────────
def excluded(rel, pats):
    return any(fnmatch.fnmatch(rel, p) for p in pats)


def main(argv=None):
    here = os.path.dirname(os.path.abspath(__file__))
    ap = argparse.ArgumentParser(description='R5 경로 대조 — 앱 URL 경로·저장소 파일 경로(읽기만)')
    ap.add_argument('--root', default=os.path.normpath(os.path.join(here, '..', '..')))
    ap.add_argument('--outer', default=None, help='바깥 배치(기본 <root>/..), none 이면 뿌리 밖 참조는 skip')
    ap.add_argument('--exclude', action='append', default=None)
    ap.add_argument('--verbose', action='store_true', help='skip 한 참조를 stderr 에 적는다')
    args = ap.parse_args(argv)
    verbose = args.verbose

    root = os.path.realpath(args.root)
    if not os.path.isdir(root):
        note('뿌리가 디렉터리가 아님: %s' % args.root)
        print('RESULT pass=0 fail=0 skip=1')
        return 2
    if args.outer is None:
        outer = os.path.dirname(root)
    elif args.outer.lower() == 'none':
        outer = None
    else:
        outer = os.path.realpath(args.outer)
    excludes = DEFAULT_EXCLUDES if args.exclude is None else args.exclude
    try:
        use_git = repo_top(root) == root
        files = [f for f in list_files(root, use_git) if not excluded(f, excludes)]
    except (RuntimeError, OSError, subprocess.SubprocessError) as e:
        note('대상 목록을 만들지 못함: %s' % e)
        print('RESULT pass=0 fail=0 skip=1')
        return 2
    ign = Ignore(root, use_git)

    bases, tables, src = load_app(root, files)
    app = App(root, bases, tables, load_origins(root, files))
    if src:
        note('라우트 표: %s · 접두: %s' % (', '.join(src), ', '.join('%s=%s' % kv for kv in sorted(bases.items())) or '없음'))
    else:
        note('라우트 표를 찾지 못함 — URL 갈래는 전부 skip')

    findings, judged, skipped = {}, 0, 0

    for rel in files:
        full = os.path.join(root, rel)
        if os.path.islink(full) or not os.path.isfile(full):
            continue
        is_script_dir = rel.startswith(SCRIPT_DIRS)
        if not (is_script_dir or rel.endswith('.md') or rel.endswith('.php')):
            continue
        text = read_text(full)
        if text is None:
            continue
        kind = file_kind(rel, text)
        if kind is None:
            continue
        lines = text.split('\n')

        # ── F 갈래 (모든 .sh·.py·.php — 앱 코드의 require 포함, .md 제외) ──
        if kind != 'md' and (is_script_dir or kind == 'php'):
            logical = logical_groups(lines) if kind == 'sh' else lines
            for i, frag, absp, is_req, col in file_refs(kind, rel, lines, root):
                ln = lines[i]
                if ALLOW_RE.search(ln):
                    continue
                ctx = logical[i] or ln
                before = ln[:col]
                if GUARD_RE.search(ctx) or WRITE_BEFORE_RE.search(before.rstrip()):
                    continue
                if COPY_RE.search(before) and re.match(r'["\']?\s*(;|&&|\|\||$|\)|#)', ln[col + len(frag):].lstrip('"\'')):
                    continue
                relp = os.path.relpath(absp, root)
                if relp.startswith('..'):
                    if outer is None:
                        skipped += 1
                        continue
                    outp = os.path.normpath(os.path.join(outer, os.path.relpath(absp, os.path.dirname(root))))
                    top = os.path.relpath(outp, outer).split(os.sep)[0]
                    if top.startswith('..') or not os.path.isdir(os.path.join(outer, top)):
                        skipped += 1
                        continue
                    judged += 1
                    if not os.path.exists(outp):
                        findings[(rel, i + 1, relp)] = ('중간' if is_req else '낮음',
                                                        '파일 경로 %s 가 가리키는 바깥 배치 파일이 없다(%s)' % (frag, relp))
                    continue
                if ign.ignored(relp.replace(os.sep, '/')):
                    continue
                judged += 1
                if not os.path.exists(absp):
                    findings[(rel, i + 1, relp)] = ('중간' if is_req else '낮음',
                                                    '파일 경로 %s → %s 가 트리에 없다%s' % (frag, relp.replace(os.sep, '/'),
                                                                                        ' (require/include — 실행 중 치명 오류)' if is_req else ''))

        # ── U 갈래 (스크립트·시험 + 문서) ──
        if not tables:
            continue
        if kind == 'md':
            if HISTORY_DOC_RE.search(rel) or rel.startswith('wiki/'):
                continue
        elif not is_script_dir:
            continue
        code_mask, fence = [], False
        for ln in lines:
            if kind == 'md' and re.match(r'^\s*(```|~~~)', ln):
                fence = not fence
                code_mask.append(False)
                continue
            code_mask.append(fence if kind == 'md' else True)
        vs = collect_vars(kind, [ln for ln, c in zip(lines, code_mask) if c])
        served = served_ports(lines, vs)
        logical = logical_groups(lines) if kind in ('sh', 'md') else lines
        for i, frag, cands in url_refs(kind, lines, vs, code_mask):
            ln = lines[i]
            if ALLOW_RE.search(ln):
                continue
            if cands is None:
                skipped += 1
                if verbose:
                    note('skip(값을 풀 수 없음) %s:%d %s' % (rel, i + 1, frag))
                continue
            results = set()
            descs = {}
            unresolved = False
            for c in cands:
                sp = split_url(c)
                if sp is None:
                    unresolved = True
                    continue
                scheme, host, port, path = sp
                if not app.is_app_origin(scheme, host, port, served):
                    results.add('foreign')
                    continue
                verdict, desc = app.judge(clean_path(path))
                results.add(verdict)
                if desc:
                    descs[verdict] = desc
            if unresolved and not results:
                skipped += 1
                if verbose:
                    note('skip(원점을 풀 수 없음) %s:%d %s' % (rel, i + 1, frag))
                continue
            if results == {'foreign'}:
                continue
            if 'skip' in results:
                skipped += 1
                if verbose:
                    note('skip(경로 머리가 변수이거나 표 없는 접두) %s:%d %s' % (rel, i + 1, frag))
                continue
            judged += 1
            bad = results & {'base_missing', 'no_route'}
            if not bad or results - bad - {'foreign'}:
                continue
            verdict = sorted(bad)[0]
            ctx = (logical[i] or ln) + ' ' + prev_comments(lines, i, kind)
            if NEG_CTX_RE.search(ctx):
                continue
            if verdict == 'base_missing':
                if FOLLOW_RE.search(logical[i] or ln):
                    continue
                m = re.search(r'앱 경로 (\S+) 에 base-path (\S+) ', descs[verdict])
                if m and (m.group(2) + m.group(1)) in (logical[i] or ln):
                    continue
            findings[(rel, i + 1, frag)] = ('낮음', descs[verdict])

    out = sorted(findings.items(), key=lambda kv: (kv[0][0], kv[0][1], kv[0][2]))
    for (rel, line, _), (sev, msg) in out:
        print('FINDING %s %s %s:%d %s' % (RULE, sev, rel, line, msg))
    if not tables and judged == 0:
        print('RESULT pass=0 fail=%d skip=%d' % (len(out), max(1, skipped)))
        return 2 if not out else 1
    print('RESULT pass=%d fail=%d skip=%d' % (max(0, judged - len(out)), len(out), skipped))
    return 1 if out else 0


if __name__ == '__main__':
    sys.exit(main())
