#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
R21 낡은 기본 판 (stale default ref) — 점검 봇 v3 정적 규칙, python3 stdlib 만, 파일은 읽기만.

answers: HO-B1-007(수락 시험의 비교 기준 기본값 BEFORE_REF 가 후보 부모보다 앞선 판 — 결함 7a3df9a · 고친 2dfb26f)

무엇을 부류로 잡나:
  시험·스크립트가 「비교 기준」·「옛 판」으로 쓰는 커밋을 기본값으로 박아 두면(`REF="${REF:-<7~40 hex>}"` ·
  `os.environ.get('REF', '<hex>')` · `getenv('REF') ?: '<hex>'` · 태그 이름), 작업을 새 판 위로 옮긴 뒤(rebase)에도
  그 값은 그대로 남아 비교가 한 판 앞의 코드와 이뤄진다. 특정 해시가 아니라 「해시·태그 모양 기본값 ↔ 범위 시작 판
  (후보의 부모)」을 git 으로 대조한다:
    · 범위가 더하거나 바꾼 **줄**에 있는 시험·스크립트(tests/·scripts/·review-scripts/, --outer 면 바깥 scripts/)의
      기본값만 본다(후보가 들고 온 기준 — 이미 합쳐진 옛 시험의 「그 라운드 직전」 기준이나, 다른 까닭으로 같은 파일을
      고친 범위에서 그대로 남은 줄은 대상이 아니다).
    · 값이 이 저장소의 커밋으로 풀리고 범위 시작 판의 조상이며, 그 값 **뒤로** 범위 시작 판까지 사이에 「그 변수가
      가리키는 대상」을 바꾼 커밋이 있으면 낡음. 대상 = 같은 파일에서 그 변수를 쓰는 git 명령의 경로
      (`git show "$REF:public/assets/x"` → public/assets/ · `git archive "$REF" -- src` → src · 경로가 없으면 트리 전체
      — 문서(*.md·docs/·wiki/)와 그 스크립트 자신은 뺀다).
    · 같은 줄 주석에 일부러 고정한 까닭(고정·일부러·의도·재현·발행·release·pin)이 있거나 `qcheck-allow: R21` 이면 뺀다.
  주석 속 해시는 보지 않는다(코드의 기본값만).
  심각도: 낮음.

입력: --root <저장소>(기본: 이 파일 기준 저장소 뿌리) · --range A..B | A..(작업 트리) | C(=C^..C), 기본 HEAD..
      --outer <바깥 배치>(있으면 그 scripts/ 의 스크립트도 — 값은 이 저장소에서 푼다, 범위 안에서 바뀐 파일 여부는
      보지 않고 바깥 작업 트리 전체의 기본값을 본다. 없거나 none 이면 끔)
출력: FINDING R21 낮음 <파일:줄> <설명> · 끝 줄 RESULT pass=<판정한 기본값 - 발견> fail=<발견 수> skip=<못 푼 값·뺀 줄>
rc:   0 발견 없음 · 1 발견 있음 · 2 실행 오류(git 저장소 아님·범위 풀기 실패 = 검사 못 함)
한계: 기본값이 다른 변수를 거쳐 들어오는 것(`B=${A:-…}` 의 A)·함수 인자로 넘기는 판은 보지 않는다.
"""
import argparse
import os
import re
import subprocess
import sys

RULE = 'R21'
TARGET_DIRS = ('tests', 'scripts', 'review-scripts')
DEFAULT_EXCLUDES = ('tests/static/fixtures/',)
ALLOW_RE = re.compile(r'qcheck-allow\s*[:=]?\s*(?:[A-Za-z0-9_-]+\s*,\s*)*R21\b')
PIN_RE = re.compile(r'고정|일부러|의도|재현|발행|겪은|release|pinned|\bpin\b', re.I)
REFVAL = r'(?:[0-9a-f]{7,40}|v?\d+(?:\.\d+){1,3}(?:-[0-9A-Za-z.-]+)?)'
SH_DEFAULT_RE = re.compile(r'\$\{([A-Za-z_][A-Za-z0-9_]*):?[-=](' + REFVAL + r')\}')
SH_ASSIGN_RE = re.compile(r'^\s*(?:export\s+|readonly\s+|local\s+|declare\s+(?:-[a-z]+\s+)?)?([A-Za-z_][A-Za-z0-9_]*)='
                          r'(["\']?)([0-9a-f]{7,40})\2\s*(?:#.*)?$')
PY_DEFAULT_RE = re.compile(r'(?:os\.environ\.get|os\.getenv)\(\s*[\'"]([A-Za-z_][A-Za-z0-9_]*)[\'"]\s*,\s*[\'"](' +
                           REFVAL + r')[\'"]\s*\)')
PHP_DEFAULT_RE = re.compile(r'getenv\(\s*[\'"]([A-Za-z_][A-Za-z0-9_]*)[\'"]\s*\)\s*\?:\s*[\'"](' + REFVAL + r')[\'"]')
DOC_EXCL = (':(exclude)*.md', ':(exclude)docs/', ':(exclude)wiki/')


def note(msg):
    sys.stderr.write('R21: %s\n' % msg)


class GitError(RuntimeError):
    pass


def git(root, *args, check=True):
    try:
        p = subprocess.run(['git', '--no-optional-locks', '-C', root] + list(args), stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                           timeout=300)
    except (OSError, subprocess.SubprocessError) as e:
        raise GitError(str(e))
    if check and p.returncode != 0:
        raise GitError('git %s: %s' % (' '.join(args[:3]), p.stderr.decode(errors='replace').strip()))
    return p


def resolve_range(root, spec):
    spec = spec or 'HEAD..'
    if '..' in spec:
        a, b = spec.split('..', 1)
        if b.startswith('.'):
            raise GitError('세 점 범위(A...B)는 받지 않는다')
        base = git(root, 'rev-parse', '--verify', '-q', '--end-of-options', (a or 'HEAD') + '^{commit}').stdout.decode().strip()
        tip = None if b == '' else git(root, 'rev-parse', '--verify', '-q', '--end-of-options',
                                       b + '^{commit}').stdout.decode().strip()
        return base, tip
    tip = git(root, 'rev-parse', '--verify', '-q', '--end-of-options', spec + '^{commit}').stdout.decode().strip()
    base = git(root, 'rev-parse', '--verify', '-q', tip + '^').stdout.decode().strip()
    return base, tip


def changed_scripts(root, base, tip):
    args = ['diff', '--name-status', '-z', '--no-renames', '--diff-filter=AMT', base] + ([tip] if tip else [])
    toks = [x for x in git(root, *args).stdout.decode('utf-8', 'surrogateescape').split('\0') if x]
    out = [toks[i + 1] for i in range(0, len(toks) - 1, 2)]
    if tip is None:
        out += [x for x in git(root, 'ls-files', '-z', '--others', '--exclude-standard').stdout.decode(
            'utf-8', 'surrogateescape').split('\0') if x]
    return sorted(set(out))


def added_lines(root, base, tip):
    args = ['diff', '-U0', '--no-renames', '--no-color', '--no-ext-diff', base] + ([tip] if tip else [])
    raw = git(root, *args).stdout.decode('utf-8', 'replace')
    out, cur = {}, None
    for l in raw.split('\n'):
        if l.startswith('+++ '):
            cur = l[6:] if l.startswith('+++ b/') else None
            if cur is not None:
                out.setdefault(cur, set())
        elif l.startswith('@@') and cur is not None:
            m = re.match(r'@@ -\S+ \+(\d+)(?:,(\d+))? @@', l)
            if m:
                st, cnt = int(m.group(1)), int(m.group(2)) if m.group(2) is not None else 1
                out[cur].update(range(st, st + cnt))
    return out


def read_at(root, tip, rel):
    if tip is None:
        try:
            with open(os.path.join(root, rel), 'rb') as fh:
                return fh.read().decode('utf-8', 'replace')
        except OSError:
            return None
    p = git(root, 'show', '%s:%s' % (tip, rel), check=False)
    return p.stdout.decode('utf-8', 'replace') if p.returncode == 0 else None


def is_script(rel, text):
    if rel.endswith(('.sh', '.bash', '.py', '.php')):
        return True
    return text.startswith('#!') and re.search(r'\b(ba|da)?sh\b|python|php', text.split('\n', 1)[0]) is not None


def code_part(line, lang):
    """주석을 뗀 코드 부분(따옴표 안의 # 는 남긴다 — 줄 단위 근사)."""
    q = None
    for i, c in enumerate(line):
        if q:
            if c == q and line[i - 1:i] != '\\':
                q = None
            continue
        if c in '\'"':
            q = c
        elif c == '#' and lang in ('sh', 'py') and (i == 0 or line[i - 1] in ' \t;'):
            return line[:i], line[i:]
        elif c == '/' and lang == 'php' and line[i:i + 2] == '//':
            return line[:i], line[i:]
    return line, ''


def targets_of(text, var):
    """그 변수를 쓰는 git 명령이 가리키는 경로들. None = 트리 전체."""
    paths, seen_git = [], False
    vre = r'\$\{?' + re.escape(var) + r'\}?'
    for line in text.split('\n'):
        if not re.search(vre, line) or not re.search(r'\bgit\b', line):
            continue
        seen_git = True
        for m in re.finditer(vre + r'(?:\^\{commit\})?:([A-Za-z0-9_./-]+)', line):
            p = m.group(1)
            paths.append(p if p.endswith('/') or '.' in p.rsplit('/', 1)[-1] else p.rstrip('/') + '/')
        m = re.search(r'\s--\s+(.+)$', line)
        if m:
            for w in re.findall(r'[A-Za-z0-9_][A-Za-z0-9_./-]*', m.group(1).split('|')[0]):
                if '/' in w or '.' in w:
                    paths.append(w)
        if not re.search(vre + r':', line) and not m:
            return None      # git archive "$REF" · git checkout "$REF" — 트리 전체
    if not seen_git:
        return None
    return sorted(set(paths)) or None


def main(argv=None):
    here = os.path.dirname(os.path.abspath(__file__))
    ap = argparse.ArgumentParser(description='R21 낡은 기본 판 — 시험·스크립트의 기본 비교 판이 범위 시작 판보다 앞선가(읽기만)')
    ap.add_argument('--root', default=os.path.normpath(os.path.join(here, '..', '..')))
    ap.add_argument('--range', dest='range_', default=None)
    ap.add_argument('--outer', default=None)
    a = ap.parse_args(argv)
    root = os.path.abspath(a.root)

    def broken(msg):
        print('RESULT pass=0 fail=0 skip=1')
        note(msg)
        return 2

    if not os.path.isdir(root):
        return broken('뿌리가 없음: %s' % root)
    try:
        top = git(root, 'rev-parse', '--show-toplevel').stdout.decode().strip()
        if os.path.realpath(top) != os.path.realpath(root):
            return broken('--root 는 저장소 맨 위여야 한다(%s)' % top)
        base, tip = resolve_range(root, a.range_)
        rels = [r for r in changed_scripts(root, base, tip)
                if r.split('/', 1)[0] in TARGET_DIRS and not r.startswith(DEFAULT_EXCLUDES)]
        added = added_lines(root, base, tip)
    except GitError as e:
        return broken('범위를 풀지 못함: %s' % e)

    files = []
    for rel in rels:
        text = read_at(root, tip, rel)
        if text is not None and is_script(rel, text):
            files.append((rel, rel, text))
    if a.outer and a.outer.lower() != 'none':
        od = os.path.join(os.path.abspath(a.outer), 'scripts')
        if os.path.isdir(od):
            for f in sorted(os.listdir(od)):
                full = os.path.join(od, f)
                if os.path.isfile(full) and not os.path.islink(full):
                    try:
                        with open(full, 'rb') as fh:
                            text = fh.read().decode('utf-8', 'replace')
                    except OSError:
                        continue
                    if is_script(f, text):
                        files.append(('../scripts/' + f, None, text))

    findings, checked, skip = [], 0, 0
    for label, rel, text in files:
        lang = 'py' if label.endswith('.py') else ('php' if label.endswith('.php') else 'sh')
        mine = None if rel is None else added.get(rel)
        if rel is not None and mine is None:
            mine = set(range(1, text.count('\n') + 2))     # 추적 안 된 새 파일(작업 트리 범위)
        for ln, line in enumerate(text.split('\n'), 1):
            if mine is not None and ln not in mine:
                continue        # 범위가 더하거나 바꾼 줄의 기본값만 — 후보가 들고 온 기준
            code, comment = code_part(line, lang)
            hits = []
            if lang == 'sh':
                hits = [(m.group(1), m.group(2)) for m in SH_DEFAULT_RE.finditer(code)]
                m = SH_ASSIGN_RE.match(line)
                if m and targets_of(text, m.group(1)) is not None or (m and re.search(
                        r'\bgit\b[^\n]*\$\{?' + re.escape(m.group(1)) + r'\b', text)):
                    hits.append((m.group(1), m.group(3)))
            elif lang == 'py':
                hits = [(m.group(1), m.group(2)) for m in PY_DEFAULT_RE.finditer(code)]
            else:
                hits = [(m.group(1), m.group(2)) for m in PHP_DEFAULT_RE.finditer(code)]
            for var, val in hits:
                if ALLOW_RE.search(line) or PIN_RE.search(comment):
                    skip += 1
                    continue
                full = git(root, 'rev-parse', '--verify', '-q', '--end-of-options', val + '^{commit}', check=False)
                if full.returncode != 0:
                    skip += 1        # 이 저장소의 판이 아님(숫자 기본값 · 다른 저장소)
                    continue
                if not re.fullmatch(r'[0-9a-f]{7,40}', val) and \
                        git(root, 'rev-parse', '--verify', '-q', 'refs/tags/' + val, check=False).returncode != 0:
                    skip += 1        # 해시도 태그도 아닌 이름
                    continue
                ref = full.stdout.decode().strip()
                if git(root, 'merge-base', '--is-ancestor', ref, base, check=False).returncode != 0:
                    skip += 1        # 범위 시작 판의 조상이 아님(범위 안 판 · 다른 가지)
                    continue
                checked += 1
                if ref == base:
                    continue
                tg = targets_of(text, var)
                spec = list(tg) if tg else ['.'] + list(DOC_EXCL)
                if rel:
                    spec.append(':(exclude)' + rel)
                revs = [x for x in git(root, 'rev-list', '%s..%s' % (ref, base), '--', *spec).stdout.decode().split() if x]
                if not revs:
                    continue
                allrevs = len([x for x in git(root, 'rev-list', '%s..%s' % (ref, base)).stdout.decode().split() if x])
                findings.append((label, ln, '기본값으로 박힌 판 %s=%s 이(가) 범위 시작 판 %s 보다 %d 커밋 앞이고, 그 사이 %d 커밋이 '
                                 '%s 을(를) 바꿨다 — 비교 기준이 낡았다(기본값을 범위 시작 판으로 · 또는 고정 까닭을 주석으로)'
                                 % (var, val, base[:7], allrevs, len(revs),
                                    '·'.join(tg[:3]) + (' 외' if len(tg) > 3 else '') if tg else '트리(문서 밖)')))
    for label, ln, msg in findings:
        print('FINDING %s 낮음 %s:%d %s' % (RULE, label, ln, msg))
    print('RESULT pass=%d fail=%d skip=%d' % (max(0, checked - len(findings)), len(findings), skip))
    note('범위 %s..%s · 본 스크립트 %d · 판정한 기본값 %d' % (base[:7], tip[:7] if tip else 'WORKTREE', len(files), checked))
    return 1 if findings else 0


if __name__ == '__main__':
    try:
        sys.exit(main())
    except KeyboardInterrupt:
        sys.exit(2)
    except Exception as ex:     # 실행 오류 = 검사 못 함(H-1)
        sys.stderr.write('R21: 실행 오류: %s: %s\n' % (ex.__class__.__name__, ex))
        print('RESULT pass=0 fail=0 skip=1')
        sys.exit(2)
