#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
R1 인코딩 (text encoding lint) — 1-F 정적 규칙, python3 stdlib 만, 파일은 읽기만.

answers: AK-0063 · AK-0004

무엇을 부류로 잡나:
  저장소의 텍스트 파일은 전부 UTF-8 이어야 하고, 글자가 한 번 깨진 흔적(디코딩 실패 뒤 대체 문자,
  다른 인코딩으로 잘못 읽었다가 다시 저장한 글자)이 남아 있으면 안 된다. 또 BOM 은 도구마다 뜻이 달라서
  「있어야 하는 파일」과 「있으면 안 되는 파일」이 갈린다. 이 규칙은 파일 이름·내용 문자열이 아니라 바이트
  모양만 보고 아래 여섯 갈래를 센다.
    E1 잘못된 UTF-8        : 줄에 UTF-8 로 풀 수 없는 바이트가 있다(다른 인코딩으로 저장, 잘린 글자).   중간
    E2 UTF-16·UTF-32       : 파일이 UTF-16/32 BOM 으로 시작한다(UTF-8 이 아님).                         중간
    E3 대체 문자 U+FFFD    : 글자 그대로의 U+FFFD — 디코딩에 한 번 실패한 흔적이다.                     낮음
                             괄호·따옴표 안에 홀로 선 한 글자면 기호를 「언급」한 것일 가능성이 커서       정보
                             심각도를 낮춰 알린다(그래도 발견이다 — 코드는 \\uFFFD·&#xFFFD; 로 적는다).
    E4 C1 제어 문자        : U+0080~U+009F 가 글자로 들어 있다 — Latin-1 로 잘못 읽은 흔적.              낮음
    E5 깨진 한글 조합      : 한글 UTF-8 바이트를 CP1252/Latin-1 이나 CP949 로 잘못 읽은 뒤 다시 저장한     낮음
                             모양(되돌리면 한글 음절이 두 자 이상 나오는 글자 줄)이다.
    E6 BOM 규약            : Windows PowerShell 스크립트(.ps1·.psm1·.psd1)에 ASCII 밖 글자가 있는데      중간
                             UTF-8 BOM 이 없다(Windows PowerShell 5.1 은 BOM 없는 파일을 ANSI 로 읽는다).
                             반대로 BOM 이 도구를 깨는 파일(셔뱅 줄로 시작, .php·.phtml·.sh·.bash·.json·.sql)
                             에 BOM 이 있다(셔뱅 무시·출력 앞 바이트·JSON/SQL 구문 오류).
  E3·E4·E5 는 같은 줄에 `qcheck-allow: R1` 표시가 있으면 뺀다(E1·E2·E6 은 뺄 수 없다). 한 파일에서 같은
  갈래가 20줄을 넘으면 20줄만 적고 마지막 줄 설명에 나머지 줄 수를 붙인다.

대상: --root 가 git 작업 트리의 맨 위이면 `git ls-files --cached --others --exclude-standard`(추적 +
      추가될 새 파일), 아니면 디렉터리 전체(.git 제외). 심볼릭 링크·알려진 이진 확장자·git 속성
      `-text`/`binary`·앞 8000바이트에 NUL 이 있는 파일은 텍스트가 아니라서 대상이 아니다.
      `--exclude GLOB` 로 뺄 수 있고, 기본으로 `tests/static/fixtures/*`(규칙 자체 시험의 일부러 깨진 견본)를 뺀다.
출력: FINDING R1 <심각도> <파일:줄> <설명> · 끝 줄 RESULT pass=<대상 - 발견 파일> fail=<발견 수> skip=<못 읽은 파일>
rc:   0 발견 없음 · 1 발견 있음 · 2 실행 오류(뿌리 없음·git 목록 실패 = 검사 못 함)
"""
import argparse
import codecs
import fnmatch
import os
import re
import subprocess
import sys

RULE = 'R1'
REPL = chr(0xFFFD)   # 대체 문자 — 소스에는 글자 그대로 적지 않는다
LINE_CAP = 20
MAX_BYTES = 32 * 1024 * 1024
NUL_WINDOW = 8000
DEFAULT_EXCLUDES = ['tests/static/fixtures/*']

BINARY_EXT = {
    '.png', '.jpg', '.jpeg', '.gif', '.webp', '.ico', '.bmp', '.tif', '.tiff', '.avif', '.heic',
    '.pdf', '.zip', '.gz', '.tgz', '.bz2', '.xz', '.zst', '.7z', '.rar', '.tar',
    '.woff', '.woff2', '.ttf', '.otf', '.eot',
    '.mp3', '.mp4', '.m4a', '.webm', '.ogg', '.oga', '.wav', '.flac', '.mov', '.avi',
    '.sqlite', '.sqlite3', '.db', '.bin', '.exe', '.dll', '.so', '.dylib', '.o', '.a',
    '.class', '.jar', '.pyc', '.pyo', '.wasm', '.der', '.p12', '.pfx', '.gpg', '.kdbx',
    '.doc', '.docx', '.xls', '.xlsx', '.ppt', '.pptx', '.hwp', '.hwpx', '.odt', '.ods',
}
PS_EXT = {'.ps1', '.psm1', '.psd1'}
BOM_HARMFUL_EXT = {'.php', '.phtml', '.sh', '.bash', '.json', '.sql'}

ALLOW_RE = re.compile(r'qcheck-allow\s*[:=]?\s*(?:[A-Za-z0-9_-]+\s*,\s*)*R1\b')
C1_RE = re.compile('[\u0080-\u009f]')
QUOTE_PAIRS = {'(': ')', '[': ']', '{': '}', '<': '>', "'": "'", '"': '"', '`': '`',
               '“': '”', '‘': '’', '«': '»', '「': '」',
               '『': '』', '〈': '〉', '《': '》', '（': '）'}

# ── E5 준비: 한 바이트 인코딩(CP1252, 빈 자리는 Latin-1) 역표 ────────────────────
_BYTE_OF = {}
for _b in range(0x80, 0x100):
    try:
        _ch = bytes([_b]).decode('cp1252')
    except UnicodeDecodeError:
        _ch = bytes([_b]).decode('latin-1')
    _BYTE_OF[_ch] = _b
    _BYTE_OF.setdefault(bytes([_b]).decode('latin-1'), _b)
_LEAD = ''.join(re.escape(c) for c, b in _BYTE_OF.items() if 0xEA <= b <= 0xED)
_CONT = ''.join(re.escape(c) for c, b in _BYTE_OF.items() if 0x80 <= b <= 0xBF)
MOJI_1BYTE_RE = re.compile('(?:[%s][%s][%s]){2,}' % (_LEAD, _CONT, _CONT))
NONASCII_RUN_RE = re.compile(r'[^\x00-\x7f]{2,}')
UTF8_HANGUL_BYTES_RE = re.compile(rb'(?:[\xea-\xed][\x80-\xbf]{2}|\xef\xbf\xbd){2,}')


def is_hangul(ch):
    return '가' <= ch <= '힣'


def mojibake_1byte(s):
    """UTF-8 한글을 CP1252/Latin-1 로 읽은 모양. 되돌려 한글 음절 둘 이상이면 True."""
    for m in MOJI_1BYTE_RE.finditer(s):
        raw = bytes(_BYTE_OF[c] for c in m.group(0))
        try:
            back = raw.decode('utf-8')
        except UnicodeDecodeError:
            continue
        if sum(1 for c in back if is_hangul(c)) >= 2 and all(is_hangul(c) for c in back):
            return m.group(0)
    return None


def mojibake_cp949(s):
    """UTF-8 한글(또는 U+FFFD)을 CP949 로 읽은 모양. 글자 경계에 맞춰 되돌려 한글·U+FFFD 가 둘 이상이면 True."""
    for run in NONASCII_RUN_RE.finditer(s):
        # CP949 로 적을 수 있는 글자끼리만 한 조각으로 본다(적을 수 없는 글자에서 끊는다)
        segs, cur = [], []
        for c in run.group(0):
            try:
                cur.append((c, c.encode('cp949')))
            except UnicodeEncodeError:
                if len(cur) >= 2:
                    segs.append(cur)
                cur = []
        if len(cur) >= 2:
            segs.append(cur)
        for seg in segs:
            hit = _cp949_segment(seg)
            if hit:
                return hit
    return None


def _cp949_segment(seg):
    """(글자, CP949 바이트) 조각 하나에서 경계가 맞는 UTF-8 한글 바이트 줄을 찾는다."""
    text = ''.join(c for c, _ in seg)
    bounds = [0]
    buf = bytearray()
    for _, b in seg:
        buf += b
        bounds.append(len(buf))
    bset = set(bounds)
    for m in UTF8_HANGUL_BYTES_RE.finditer(bytes(buf)):
        # 3바이트 단위 안에서, CP949 글자 경계에서 시작하고 끝나는 가장 긴 조각을 찾는다
        best = None
        for st in range(m.start(), m.end() - 5, 3):
            if st not in bset:
                continue
            for en in range(m.end(), st + 5, -3):
                if en in bset:
                    best = (st, en)
                    break
            if best:
                break
        if not best:
            continue
        st, en = best
        back = bytes(buf[st:en]).decode('utf-8', 'strict')
        if all(is_hangul(c) or c == REPL for c in back):
            return text[bounds.index(st):bounds.index(en)]
    return None


def fffd_is_mention(s, idx):
    """U+FFFD 가 괄호·따옴표 안에 홀로 서 있으면 기호를 언급한 것으로 본다."""
    if idx == 0 or idx + 1 >= len(s):
        return False
    op, cl = s[idx - 1], s[idx + 1]
    return QUOTE_PAIRS.get(op) == cl


class Finding(object):
    __slots__ = ('sev', 'path', 'line', 'msg', 'kind')

    def __init__(self, kind, sev, path, line, msg):
        self.kind, self.sev, self.path, self.line, self.msg = kind, sev, path, line, msg


def repo_top(root):
    try:
        r = subprocess.run(['git', '--no-optional-locks', '-C', root, 'rev-parse', '--show-toplevel'],
                           stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, timeout=60)
    except (OSError, subprocess.SubprocessError):
        return None
    if r.returncode != 0:
        return None
    return os.path.realpath(r.stdout.decode('utf-8', 'replace').strip())


def list_git(root):
    r = subprocess.run(['git', '--no-optional-locks', '-C', root, '-c', 'core.quotepath=off', 'ls-files', '-z',
                        '--cached', '--others', '--exclude-standard'],
                       stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=300)
    if r.returncode != 0:
        raise RuntimeError('git ls-files 실패: ' + r.stderr.decode('utf-8', 'replace').strip())
    seen, out = set(), []
    for p in r.stdout.split(b'\0'):
        if not p:
            continue
        rel = os.fsdecode(p)
        if rel not in seen:
            seen.add(rel)
            out.append(rel)
    return out


def git_binary_attr(root, rels):
    """git 속성으로 텍스트가 아니라고 표시된 경로 집합(text 가 unset). 실패하면 빈 집합."""
    if not rels:
        return set()
    try:
        r = subprocess.run(['git', '--no-optional-locks', '-C', root, 'check-attr', '-z', '--stdin', 'text'],
                           input=b'\0'.join(os.fsencode(x) for x in rels) + b'\0',
                           stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, timeout=300)
    except (OSError, subprocess.SubprocessError):
        return set()
    if r.returncode != 0:
        return set()
    parts = r.stdout.split(b'\0')
    out = set()
    for i in range(0, len(parts) - 2, 3):
        if parts[i + 2] == b'unset':
            out.add(os.fsdecode(parts[i]))
    return out


def list_walk(root):
    out = []
    for d, dirs, files in os.walk(root):
        dirs[:] = sorted(x for x in dirs if x != '.git')
        for f in sorted(files):
            out.append(os.path.relpath(os.path.join(d, f), root).replace(os.sep, '/'))
    return out


def excluded(rel, globs):
    return any(fnmatch.fnmatch(rel, g) for g in globs)


def check_bytes(rel, data):
    """텍스트 대상이면 발견 목록, 대상이 아니면 None."""
    ext = os.path.splitext(rel)[1].lower()
    found = []
    for bom, name in ((codecs.BOM_UTF32_LE, 'UTF-32LE'), (codecs.BOM_UTF32_BE, 'UTF-32BE'),
                      (codecs.BOM_UTF16_LE, 'UTF-16LE'), (codecs.BOM_UTF16_BE, 'UTF-16BE')):
        if data.startswith(bom) and ext not in BINARY_EXT:
            found.append(Finding('E2', '중간', rel, 1, '%s BOM 으로 시작 — UTF-8 이 아닌 텍스트 파일' % name))
            return found
    if ext in BINARY_EXT or b'\0' in data[:NUL_WINDOW]:
        return None
    has_bom = data.startswith(codecs.BOM_UTF8)
    body = data[len(codecs.BOM_UTF8):] if has_bom else data

    if has_bom and (ext in BOM_HARMFUL_EXT or body.startswith(b'#!')):
        why = '셔뱅 줄 앞 BOM 은 셔뱅을 무효로 만든다' if body.startswith(b'#!') else \
            '이 형식(%s)은 BOM 을 글자로 읽는다(출력 앞 바이트·구문 오류)' % ext
        found.append(Finding('E6', '중간', rel, 1, 'UTF-8 BOM 이 있으면 안 되는 파일 — ' + why))

    if ext in PS_EXT and not has_bom:
        for i, lb in enumerate(body.split(b'\n'), 1):
            if any(b >= 0x80 for b in lb):
                found.append(Finding('E6', '중간', rel, i,
                                     'PowerShell 스크립트에 ASCII 밖 글자가 있는데 UTF-8 BOM 이 없다 '
                                     '— Windows PowerShell 5.1 은 ANSI 로 읽어 글자가 깨진다'))
                break

    per = {}

    def add(kind, sev, line, msg):
        lst = per.setdefault(kind, [])
        lst.append(Finding(kind, sev, rel, line, msg))

    for i, lb in enumerate(body.split(b'\n'), 1):
        try:
            s = lb.decode('utf-8')
        except UnicodeDecodeError as e:
            bad = lb[e.start:e.start + 4]
            add('E1', '중간', i, '잘못된 UTF-8 바이트(%d열, %s) — UTF-8 이 아닌 인코딩이거나 잘린 글자'
                % (e.start + 1, ' '.join('%02X' % b for b in bad)))
            continue
        if not s:
            continue
        allowed = bool(ALLOW_RE.search(s))
        if REPL in s and not allowed:
            idxs = [k for k, c in enumerate(s) if c == REPL]
            if all(fffd_is_mention(s, k) for k in idxs):
                add('E3', '정보', i, '대체 문자 U+FFFD 를 글자 그대로 적음(괄호·따옴표 안 한 글자 — 기호 언급으로 '
                    '보임) — 코드는 \\uFFFD·&#xFFFD; 로 적거나 `qcheck-allow: R1` 표시')
            else:
                add('E3', '낮음', i, '대체 문자 U+FFFD %d자 — 디코딩에 실패해 글자가 깨진 흔적' % len(idxs))
        if not allowed:
            m = C1_RE.search(s)
            if m:
                add('E4', '낮음', i, 'C1 제어 문자 U+%04X(%d열) — Latin-1 로 잘못 읽은 흔적' % (ord(m.group(0)), m.start() + 1))
            else:
                mj = mojibake_1byte(s)
                if mj:
                    add('E5', '낮음', i, '깨진 한글 조합 %d자 — 한글 UTF-8 을 CP1252/Latin-1 로 읽고 다시 저장한 모양' % len(mj))
                else:
                    mj = mojibake_cp949(s)
                    if mj:
                        add('E5', '낮음', i, '깨진 한글 조합 %d자 — 한글 UTF-8 을 CP949 로 읽고 다시 저장한 모양' % len(mj))

    for kind in ('E1', 'E3', 'E4', 'E5'):
        lst = per.get(kind, [])
        if len(lst) > LINE_CAP:
            rest = len(lst) - LINE_CAP
            lst = lst[:LINE_CAP]
            lst[-1].msg += ' (같은 갈래 %d줄 더 — 생략)' % rest
        found.extend(lst)
    return found


def main(argv=None):
    here = os.path.dirname(os.path.abspath(__file__))
    ap = argparse.ArgumentParser(description='R1 인코딩 — 텍스트 파일 인코딩 린트(읽기만)')
    ap.add_argument('--root', default=os.path.normpath(os.path.join(here, '..', '..')),
                    help='검사할 트리(기본: 이 파일 기준 저장소 뿌리)')
    ap.add_argument('--exclude', action='append', default=None,
                    help='뺄 경로 glob(뿌리 기준, 여러 번). 주면 기본값(tests/static/fixtures/*)을 대신한다')
    ap.add_argument('--walk', action='store_true', help='git 목록 대신 디렉터리 전체를 본다')
    args = ap.parse_args(argv)

    root = os.path.realpath(args.root)
    excludes = DEFAULT_EXCLUDES if args.exclude is None else args.exclude
    if not os.path.isdir(root):
        sys.stderr.write('R1: 뿌리가 디렉터리가 아님: %s\n' % args.root)
        print('RESULT pass=0 fail=0 skip=1')
        return 2
    try:
        use_git = (not args.walk) and repo_top(root) == root
        rels = list_git(root) if use_git else list_walk(root)
    except (RuntimeError, OSError, subprocess.SubprocessError) as e:
        sys.stderr.write('R1: 대상 목록을 만들지 못함: %s\n' % e)
        print('RESULT pass=0 fail=0 skip=1')
        return 2
    rels = [r for r in rels if not excluded(r, excludes)]
    nontext = git_binary_attr(root, rels) if use_git else set()

    targets = skipped = 0
    bad_files = 0
    findings = []
    for rel in sorted(rels):
        if rel in nontext:
            continue
        full = os.path.join(root, rel)
        if os.path.islink(full) or not os.path.isfile(full):
            continue
        try:
            if os.path.getsize(full) > MAX_BYTES:
                sys.stderr.write('R1: 너무 커서 건너뜀: %s\n' % rel)
                skipped += 1
                continue
            with open(full, 'rb') as fh:
                data = fh.read()
        except OSError as e:
            sys.stderr.write('R1: 읽지 못함: %s (%s)\n' % (rel, e.strerror))
            skipped += 1
            continue
        res = check_bytes(rel, data)
        if res is None:
            continue
        targets += 1
        if res:
            bad_files += 1
            findings.extend(res)

    findings.sort(key=lambda f: (f.path, f.line, f.kind))
    for f in findings:
        print('FINDING %s %s %s:%d %s' % (RULE, f.sev, f.path, f.line, f.msg))
    print('RESULT pass=%d fail=%d skip=%d' % (targets - bad_files, len(findings), skipped))
    return 1 if findings else 0


if __name__ == '__main__':
    sys.exit(main())
