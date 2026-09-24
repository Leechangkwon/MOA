#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
R15 비밀 인자 (secrets on the command line) — 1-F 정적 규칙, python3 stdlib 만, 파일은 읽기만.

answers: (없음 — F-71 채점 뒤 train 번호를 단다)

무엇을 부류로 잡나:
  명령 인자(argv)는 같은 호스트의 다른 사용자에게 `ps`·/proc/<pid>/cmdline 으로 보이고, sudo 를 거치면
  auth.log·journal 에 남는다. 그래서 비밀(TOTP 비밀·비밀번호·토큰·키)은 표준입력·파일·환경 변수로 넘겨야
  한다(R-62 자격 취급). 이 규칙은 셸·PHP·파이썬에서 「비밀로 보이는 값」이 「외부 명령의 인자」로 흘러
  들어가는 모양을 잡는다. 특정 명령·파일 이름이 아니라 값의 흐름으로 본다.
    비밀로 보이는 값 — ① 이름 토큰에 SECRET·TOTP·PASSWORD·PASSWD·PASSPHRASE·CREDENTIAL·TOKEN 이 있거나,
      PASS 가 있거나(카운터처럼 숫자만 대입되는 변수는 뺀다), KEY 앞에 API·APP·PRIVATE·SECRET·SIGNING…
      같은 말이 붙은 변수·속성·배열 키(`mfa_secret` 열 값 포함). 이름에 FILE·PATH·ID·NAME·HASH·COUNT·
      CODE·LEN·URL·BAD·FAKE… 같은 「비밀 자체가 아님」 토큰이 붙으면 뺀다. 파일 안에서 글자 그대로의
      값만 대입되는 변수(시험용 가짜 값)도 뺀다.
      ② 그런 값을 대입받은 변수(파일 안 흐름, 순서 무시), 이름이 비밀을 가리키는 함수·명령의 출력
      `$(qa_secret …)`, 비밀 이름이 적힌 파일·열을 읽는 명령 치환 `$(… totp … )`.
      ③ 셸 함수의 위치 인자(`$1`)는 호출하는 쪽에서 비밀을 넘길 때만 비밀로 친다(함수 사이 전파 포함).
    외부 명령의 인자 — 셸: 빌트인(echo·printf·read·test·local·export…)과 셸 함수가 아닌 명령의 인자
      단어, sudo·env 류 뒤의 `VAR=값` 도 인자다. 표준입력(`<<<`·heredoc·파이프)·리다이렉트 대상·
      명령 앞 `VAR=값`(환경)은 통과. PHP: exec·shell_exec·system·passthru·popen·proc_open·pcntl_exec 의
      명령 인자와 백틱. 파이썬: subprocess.*·os.system·os.popen·os.exec*·os.spawn*·asyncio 하위 프로세스의
      명령 인자(`input=`·`env=`·`stdin=` 은 통과). strlen·hash 류로 감싼 값은 비밀이 아니다.
    heredoc 으로 만드는 스크립트(셔뱅·대상 확장자·`python3 -` 로 판단)도 같은 방법으로 본다.
  심각도: sudo·doas 를 거치면 높음(기록에 남음), 아니면 중간.

대상: --root 아래 --dirs(기본 tests·scripts·src)의 셸(*.sh·*.bash·셸 셔뱅)·PHP(*.php·*.phtml·php 셔뱅)·
      파이썬(*.py·python 셔뱅). --root 가 git 작업 트리의 맨 위이면 `git ls-files --cached --others
      --exclude-standard`, 아니면 디렉터리 전체. 기본으로 `tests/static/fixtures/*` 는 뺀다(--exclude 로 바꿈).
출력: FINDING R15 <심각도> <파일:줄> <설명> · 끝 줄 RESULT pass=<대상 - 발견 파일> fail=<발견 수> skip=<못 읽음·구문 오류>
      설명에는 변수 이름만 적고 값은 적지 않는다.
rc:   0 발견 없음 · 1 발견 있음 · 2 실행 오류(뿌리 없음·git 목록 실패 = 검사 못 함)
한계: 셸 분석은 bash 문법의 부분 집합이다(case 안의 `$(…)`·eval·xargs·동적 명령 이름·배열 원소별 추적은
      못 본다). 세션 쿠키처럼 이름에 위 토큰이 없는 비밀은 흐름으로만 잡는다.
"""
import argparse
import ast
import fnmatch
import os
import re
import subprocess
import sys

RULE = 'R15'
DEFAULT_DIRS = ('tests', 'scripts', 'src')
DEFAULT_EXCLUDES = ['tests/static/fixtures/*']

# ─── 이름 판정 ────────────────────────────────────────────────────────────
STRONG = {'secret', 'secrets', 'totp', 'password', 'passwords', 'passwd', 'passphrase', 'credential',
          'credentials', 'apikey', 'privkey', 'secretkey', 'accesskey', 'appkey', 'token', 'tokens', 'pw'}
KEY_QUAL = {'api', 'app', 'private', 'priv', 'secret', 'secure', 'signing', 'sign', 'hmac', 'master', 'access',
            'auth', 'session', 'jwt', 'totp', 'mfa', 'otp', 'enc', 'encryption', 'crypt', 'crypto', 'cipher',
            'aes', 'license', 'client', 'service', 'db'}
# 비밀 토큰 **뒤**에 오면 「비밀 자체가 아니라 그 비밀에 딸린 것」(TOKEN_FILE · TOKEN_ID · API_TOKEN_USER)
POST_EXEMPT = {'file', 'files', 'path', 'paths', 'dir', 'dirs', 'name', 'names', 'id', 'ids', 'len', 'length',
               'count', 'cnt', 'num', 'hash', 'hashes', 'hashed', 'sha', 'sha1', 'sha256', 'digest', 'url', 'uri',
               'header', 'type', 'kind', 'prefix', 'hint', 'label', 'expires', 'expiry', 'exp', 'ttl', 'age', 'at',
               'time', 'ts', 'date', 'ok', 'set', 're', 'regex', 'pattern', 'fmt', 'format', 'mode', 'field', 'col',
               'column', 'status', 'step', 'window', 'period', 'digits', 'drift', 'skew', 'wait', 'sec', 'secs',
               'seconds', 'ms', 'code', 'codes', 'enabled', 'required', 'flag', 'used', 'min', 'max', 'limit',
               'cost', 'algo', 'policy', 'rule', 'rules', 'msg', 'message', 'err', 'error', 'errors', 'desc',
               'table', 'scope', 'scopes', 'role', 'input', 'selector', 'sel', 'button', 'btn', 'box', 'help',
               'prompt', 'attempts', 'tries', 'lock', 'locked', 'strength', 'reset', 'env', 'var', 'vars', 'user',
               'users', 'owner', 'account', 'accounts', 'email', 'who', 'host', 'port', 'group', 'state', 'mask',
               'masked', 'redact', 'redacted', 'placeholder', 'example', 'sample'}
# 비밀 토큰 **앞**에 오면 「가짜·개수·여부」(BAD_TOKEN · N_TOKENS · HAS_PASSWORD)
PRE_EXEMPT = {'bad', 'fake', 'wrong', 'invalid', 'bogus', 'dummy', 'garbage', 'junk', 'revoked', 'expired',
              'example', 'placeholder', 'sample', 'mock', 'stub', 'n', 'num', 'count', 'cnt', 'len', 'length',
              'max', 'min', 'is', 'has', 'no', 'need', 'needs', 'use', 'show', 'hide', 'mask', 'masked', 'redact',
              'redacted'}
STRONG_TEXT = {'secret', 'secrets', 'totp', 'password', 'passwords', 'passwd', 'passphrase', 'credential',
               'credentials'}
NONREVEAL = {'strlen', 'mb_strlen', 'len', 'hash', 'hash_hmac', 'hash_equals', 'md5', 'sha1', 'crc32',
             'password_hash', 'password_verify', 'password_needs_rehash', 'isset', 'empty', 'is_null', 'is_string',
             'count', 'preg_match', 'str_starts_with', 'str_ends_with', 'str_contains', 'strcmp', 'in_array',
             'array_key_exists', 'sha256', 'sha512', 'hexdigest', 'digest', 'compare_digest', 'new', 'bool',
             'int', 'isinstance', 'hasattr', 'type', 'id', 'hmac', 'hashlib', 'mask', 'redact'}

_TOK_RE = re.compile(r'[A-Z]?[a-z]+|[A-Z]+(?![a-z])|[0-9]+')


def name_tokens(name):
    return [t.lower() for t in _TOK_RE.findall(name)]


def secret_index(toks):
    """마지막 비밀 토큰의 자리(없으면 -1). KEY 는 앞에 비밀 수식어(API·SECRET…)가 있을 때만."""
    idx = -1
    for i, t in enumerate(toks):
        if t == 'pw' and i != len(toks) - 1:
            continue    # PW 는 끝에 올 때만 비밀번호(PW_CHROME 의 PW 는 다른 뜻)
        if t in STRONG or t == 'pass' or (t == 'key' and any(q in KEY_QUAL for q in toks[:i])):
            idx = i
    return idx


def is_secret_name(name):
    toks = name_tokens(name)
    idx = secret_index(toks)
    if idx < 0:
        return False
    if any(t in POST_EXEMPT for t in toks[idx + 1:]):
        return False
    if any(t in PRE_EXEMPT for t in toks[:idx]):
        return False
    return True


HASHERS = {'sha256sum', 'sha1sum', 'sha512sum', 'sha224sum', 'sha384sum', 'md5sum', 'b2sum', 'cksum', 'sum', 'wc'}
READERS = {'cat', 'awk', 'gawk', 'mawk', 'sed', 'grep', 'head', 'tail', 'cut', 'jq', 'tr', 'sort', 'base64',
           'xxd', 'openssl', 'perl', 'tac', 'strings', 'dd', 'psql', 'sqlite3', 'mysql'}
_SQL_RE = re.compile(r'(?is)\bselect\b.*\bfrom\b|\breturning\b')


def lit_mentions_secret(lit):
    """글자 그대로의 단어가 비밀을 담은 파일 경로나 SQL 이고, 그 안에 비밀 낱말(열·파일 이름 조각)이 있나."""
    pathish = '/' in lit or re.search(r'\.[A-Za-z0-9]{1,5}$', lit)
    if not (pathish or _SQL_RE.search(lit)):
        return False
    for chunk in re.split(r'[^A-Za-z0-9_]+', lit):
        if chunk and any(t in STRONG_TEXT for t in name_tokens(chunk)) and is_secret_name(chunk):
            return True
    return False


_FIELD_READ_RE = re.compile(r"""\[\s*(['"])([A-Za-z_][A-Za-z0-9_]*)\1\s*\]|\.get\(\s*(['"])([A-Za-z_][A-Za-z0-9_]*)\3""")
_JQ_PATH_RE = re.compile(r'(?:^|[\s|(,])\.([A-Za-z_][A-Za-z0-9_]*)')


def lit_reads_secret_field(lit, jq=False):
    """글자 안에서 비밀 이름의 칸을 **읽는** 모양: x["secret"] · d.get('token') · (jq) .password"""
    for m in _FIELD_READ_RE.finditer(lit):
        key = m.group(2) or m.group(4)
        if key and is_secret_name(key):
            return True
    if jq:
        for m in _JQ_PATH_RE.finditer(lit):
            if is_secret_name(m.group(1)):
                return True
    return False


def file_var_secret(name):
    """TOTP_FILE · SECRET_PATH 처럼 비밀 + 파일·경로 토큰 — 그 파일을 읽는 명령의 출력은 비밀이다."""
    toks = name_tokens(name)
    idx = secret_index(toks)
    if idx < 0 or toks[idx] not in STRONG_TEXT:
        return False
    rest = toks[idx + 1:]
    return bool(rest) and rest[0] in ('file', 'files', 'path', 'paths') and \
        not any(t in PRE_EXEMPT for t in toks[:idx])


class Finding(object):
    __slots__ = ('sev', 'path', 'line', 'msg')

    def __init__(self, sev, path, line, msg):
        self.sev, self.path, self.line, self.msg = sev, path, line, msg


# ═══ 셸 ═════════════════════════════════════════════════════════════════════
SH_BUILTINS = {'echo', 'printf', 'read', 'local', 'declare', 'typeset', 'export', 'readonly', 'test', '[', '[[',
               ':', 'true', 'false', 'set', 'unset', 'shift', 'return', 'exit', 'let', 'cd', 'pushd', 'popd',
               'source', '.', 'alias', 'unalias', 'trap', 'wait', 'mapfile', 'readarray', 'getopts', 'hash', 'type',
               'ulimit', 'umask', 'shopt', 'caller', 'eval', 'kill', 'jobs', 'disown', 'fg', 'bg', 'break',
               'continue', 'times', 'dirs', 'enable', 'help', 'logout', 'suspend', 'compgen', 'complete', 'bind'}
SH_DECL = {'local', 'declare', 'typeset', 'export', 'readonly'}
SH_WRAP_LOGGED = {'sudo', 'doas', 'pkexec', 'runuser', 'su'}
SH_WRAP = {'env', 'nohup', 'nice', 'ionice', 'timeout', 'stdbuf', 'setsid', 'chrt', 'taskset', 'unbuffer', 'time',
           'flock'}
SH_RESERVED_SKIP = {'if', 'then', 'elif', 'else', 'do', 'while', 'until', '!', 'fi', 'done', 'esac'}
ASSIGN_RE = re.compile(r'^([A-Za-z_][A-Za-z0-9_]*)(\[[^\]]*\])?\+?=')
REDIR_RE = re.compile(r'(\d+|\{[A-Za-z_][A-Za-z0-9_]*\})?(<<<|<<-|<<|<>|<&|<|>>|>&|>\||>)')


class Word(object):
    __slots__ = ('parts', 'line', 'raw')

    def __init__(self, parts, line, raw):
        self.parts, self.line, self.raw = parts, line, raw

    def lit(self):
        """확장이 없으면 글자, 있으면 None."""
        if all(p[0] == 'lit' for p in self.parts):
            return ''.join(p[1] for p in self.parts)
        return None


class Sub(object):
    __slots__ = ('cmds', 'text', 'line')

    def __init__(self, cmds, text, line):
        self.cmds, self.text, self.line = cmds, text, line


class Cmd(object):
    __slots__ = ('words', 'assigns', 'redirs', 'herestr', 'heredocs', 'line', 'func', 'kind')

    def __init__(self, line, func):
        self.words, self.assigns, self.redirs, self.herestr, self.heredocs = [], [], [], [], []
        self.line, self.func, self.kind = line, func, 'simple'


def effective_words(words):
    """sudo·env·nohup·timeout 같은 감싸는 명령과 그 선택지·VAR=값 을 벗긴 실제 명령 단어들."""
    k = 0
    while k < len(words):
        lit = words[k].lit()
        if lit is None:
            break
        base = os.path.basename(lit)
        if base not in SH_WRAP_LOGGED and base not in SH_WRAP and base not in ('exec', 'command'):
            break
        k += 1
        while k < len(words):
            l2 = words[k].lit()
            if l2 is not None and (l2.startswith('-') or ASSIGN_RE.match(words[k].raw) or
                                   re.fullmatch(r'[0-9.]+[smhd]?', l2)):
                if l2 in ('-u', '-g', '-C', '-p', '-h', '-U', '-D', '-R', '-T') and base in SH_WRAP_LOGGED:
                    k += 1
                k += 1
                continue
            break
    return words[k:]


class ShellParser(object):
    def __init__(self, text, line0=1, shared=None):
        self.t = text
        self.n = len(text)
        self.i = 0
        self.line = line0
        if shared is None:
            shared = {'cmds': [], 'funcs': {}, 'fstack': [], 'brace': [0], 'pending_func': [None]}
        self.sh = shared
        self.pending_heredocs = []

    # ── 도우미 ──
    def cur_func(self):
        fs = self.sh['fstack']
        return fs[-1][0] if fs else None

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
        """t[i] == op 인 곳에서 짝이 맞는 cl 의 위치(따옴표 무시)."""
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
            cmd.heredocs.append(('\n'.join(lines), start_line, quoted))
        self.pending_heredocs = []

    # ── 단어 ──
    def read_word(self, dq_mode=False):
        parts, buf = [], []
        start, start_line = self.i, self.line

        def flush():
            if buf:
                parts.append(('lit', ''.join(buf)))
                del buf[:]

        while self.i < self.n:
            c = self.t[self.i]
            if c in ' \t\n;&|()<>\r':
                if c == '(' and not parts and re.match(r'^[A-Za-z_][A-Za-z0-9_]*(\[[^\]]*\])?\+?=$', ''.join(buf)):
                    # 배열 대입 X=( … )
                    j = self._match_close(self.i, '(', ')')
                    inner = self.t[self.i + 1:j]
                    flush()
                    sub = ShellParser(inner, self.line, self.sh)
                    while sub.i < sub.n:
                        sub._skip_blank()
                        if sub.i < sub.n and sub.t[sub.i] in '\n':
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
                        parts.extend(w.parts)
                        if sub.i == before:
                            sub.i += 1
                    self.line += inner.count('\n')
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
                k = self.t.find("'", self.i + 1)
                if k < 0:
                    k = self.n
                s = self.t[self.i + 1:k]
                buf.append(s)
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
                parts.extend(self.read_dollar())
                continue
            if c == '`':
                flush()
                parts.append(self.read_backtick())
                continue
            buf.append(c)
            self.i += 1
        flush()
        return Word(parts, start_line, self.t[start:self.i])

    def read_dquote(self):
        parts, buf = [], []

        def flush():
            if buf:
                parts.append(('lit', ''.join(buf)))
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
                parts.extend(self.read_dollar())
                continue
            if c == '`':
                flush()
                parts.append(self.read_backtick())
                continue
            if c == '\n':
                self.line += 1
            buf.append(c)
            self.i += 1
        flush()
        return parts

    def read_dollar(self):
        t, i = self.t, self.i
        nx = t[i + 1] if i + 1 < self.n else ''
        if nx == '(':
            if t.startswith('((', i + 1):
                j = self._match_close(i + 1, '(', ')')
                self.line += t[i:j].count('\n')
                self.i = j + 1
                return [('arith',)]
            self.i = i + 2
            line0 = self.line
            start = self.i
            cmds = self.parse_list(term=')')
            return [('sub', Sub(cmds, t[start:self.i - 1], line0))]
        if nx == '[':
            j = t.find(']', i)
            self.i = self.n if j < 0 else j + 1
            return [('arith',)]
        if nx == '{':
            j = self._match_close(i + 1, '{', '}')
            inner = t[i + 2:j]
            self.line += inner.count('\n')
            self.i = j + 1
            m = re.match(r'(!|#)?([A-Za-z_][A-Za-z0-9_]*|[0-9]+|[@*#?$!-])', inner)
            if not m:
                return [('lit', '')]
            flag, name = m.group(1), m.group(2)
            rest = inner[m.end():]
            if flag == '#' and not rest:
                return [('len', name)]
            if flag == '!':
                return [('lit', '')]
            out = []
            if name.isdigit():
                out.append(('pos', int(name)))
            elif name in ('@', '*'):
                out.append(('pos', '@'))
            elif re.match(r'[A-Za-z_]', name):
                # ${X:+대체} 는 값이 대체 글자뿐이다. 나머지(기본값·자르기·바꾸기)는 X 의 값에서 나온다
                if not (rest.startswith(':+') or rest.startswith('+')):
                    out.append(('var', name))
            if '$' in rest or '`' in rest:
                sp = ShellParser(rest, self.line, self.sh)
                out.extend(sp.read_dquote_body())
            return out
        if nx == "'":
            j = i + 2
            while j < self.n and t[j] != "'":
                j += 2 if t[j] == '\\' else 1
            self.i = j + 1
            return [('lit', t[i + 2:j])]
        if nx == '"':
            self.i = i + 2
            return self.read_dquote()
        m = re.match(r'[A-Za-z_][A-Za-z0-9_]*', t[i + 1:i + 200])
        if m:
            self.i = i + 1 + m.end()
            return [('var', m.group(0))]
        if nx.isdigit():
            self.i = i + 2
            return [('pos', int(nx))]
        if nx in ('@', '*'):
            self.i = i + 2
            return [('pos', '@')]
        if nx and nx in '#?$!-':
            self.i = i + 2
            return [('lit', '')]
        self.i = i + 1
        return [('lit', '$')]

    def read_dquote_body(self):
        """글자 전체를 큰따옴표 안처럼 읽는다(${X:-…} 의 나머지)."""
        self.t = self.t + '"'
        self.n = len(self.t)
        return self.read_dquote()

    def read_backtick(self):
        j = self.i + 1
        while j < self.n and self.t[j] != '`':
            j += 2 if self.t[j] == '\\' else 1
        raw = self.t[self.i + 1:j]
        inner = re.sub(r'\\([`$\\])', r'\1', raw)
        line0 = self.line
        sp = ShellParser(inner, self.line, self.sh)
        cmds = sp.parse_list()
        self.line += raw.count('\n')
        self.i = j + 1
        return ('sub', Sub(cmds, inner, line0))

    # ── 명령 목록 ──
    def parse_list(self, term=None):
        out = []
        cmd = Cmd(self.line, self.cur_func())
        paren = 0
        dbr = False
        expect_fname = False

        def finish():
            nonlocal cmd
            if cmd.words or cmd.assigns or cmd.redirs or cmd.herestr or cmd.heredocs:
                self.sh['cmds'].append(cmd)
                out.append(cmd)
            cmd = Cmd(self.line, self.cur_func())

        while True:
            self._skip_blank()
            if self.i >= self.n:
                finish()
                break
            c = self.t[self.i]
            if c == '#' and (self.i == 0 or self.t[self.i - 1] in ' \t\n;&|()'):
                k = self.t.find('\n', self.i)
                self.i = self.n if k < 0 else k
                continue
            if c == '\n':
                finish()
                self.i += 1
                self.line += 1
                if self.pending_heredocs:
                    self._read_heredoc_bodies()
                continue
            if dbr and c in '&|<>()!':
                j = self.i
                while j < self.n and self.t[j] in '&|<>()!=':
                    j += 1
                cmd.words.append(Word([('lit', self.t[self.i:j])], self.line, self.t[self.i:j]))
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
                while j < self.n and self.t[j] in ';&|' and j - self.i < 3:
                    j += 1
                self.i = j
                finish()
                continue
            if c == '(':
                if len(cmd.words) == 1 and not cmd.assigns and re.match(r'\(\s*\)', self.t[self.i:self.i + 20]):
                    name = cmd.words[0].raw
                    cmd = Cmd(self.line, self.cur_func())
                    self.sh['pending_func'][0] = name
                    self.i = self.t.index(')', self.i) + 1
                    continue
                if not cmd.words and self.t.startswith('((', self.i):
                    j = self._match_close(self.i, '(', ')')
                    self.line += self.t[self.i:j].count('\n')
                    self.i = j + 1
                    continue
                if cmd.words and cmd.kind == 'header':
                    j = self._match_close(self.i, '(', ')')     # for (( … ))
                    self.line += self.t[self.i:j].count('\n')
                    self.i = j + 1
                    continue
                finish()
                paren += 1
                self.i += 1
                continue
            if c == ')':
                self.i += 1
                if paren > 0:
                    paren -= 1
                    finish()
                    continue
                if term == ')':
                    finish()
                    return out
                cmd = Cmd(self.line, self.cur_func())   # case 갈래의 무늬 — 명령이 아니다
                continue
            if c in '<>' or (c.isdigit() or c == '{') and REDIR_RE.match(self.t, self.i) and \
                    REDIR_RE.match(self.t, self.i).group(1):
                if c in '<>' and self.t.startswith('(', self.i + 1):
                    # 프로세스 치환 <( … ) / >( … ) — 단어로 친다
                    self.i += 2
                    line0 = self.line
                    start = self.i
                    cmds = self.parse_list(term=')')
                    cmd.words.append(Word([('sub', Sub(cmds, self.t[start:self.i - 1], line0))], line0, '<(…)'))
                    continue
                m = REDIR_RE.match(self.t, self.i)
                if m and (c in '<>' or m.group(1)):
                    op = m.group(2)
                    self.i = m.end()
                    self._skip_blank()
                    if op in ('<<', '<<-'):
                        w = self.read_word()
                        delim = w.lit() if w.lit() is not None else re.sub(r'["\'\\]', '', w.raw)
                        quoted = any(q in w.raw for q in ('"', "'", '\\'))
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
                self.sh['pending_func'][0] = raw
                expect_fname = False
                continue
            if not cmd.words and not cmd.assigns:
                if raw == '{':
                    self.sh['brace'][0] += 1
                    pf = self.sh['pending_func'][0]
                    if pf:
                        self.sh['fstack'].append((pf, self.sh['brace'][0]))
                        self.sh['funcs'].setdefault(pf, [])
                        self.sh['pending_func'][0] = None
                    cmd.func = self.cur_func()
                    continue
                if raw == '}':
                    fs = self.sh['fstack']
                    if fs and fs[-1][1] == self.sh['brace'][0]:
                        fs.pop()
                    self.sh['brace'][0] = max(0, self.sh['brace'][0] - 1)
                    cmd = Cmd(self.line, self.cur_func())
                    continue
                if raw in SH_RESERVED_SKIP:
                    continue
                if raw == 'function':
                    expect_fname = True
                    continue
                if raw in ('for', 'select', 'case', 'in'):
                    cmd.kind = 'header'
                if raw == '[[':
                    dbr = True
                if ASSIGN_RE.match(raw) and cmd.kind == 'simple':
                    cmd.assigns.append(w)
                    continue
            elif dbr and raw == ']]':
                dbr = False
            cmd.words.append(w)
        return out


def assign_parts(w):
    """대입 단어 NAME=값 에서 (이름, 값 조각들)."""
    m = ASSIGN_RE.match(w.raw)
    if not m:
        return None, []
    name = m.group(1)
    # 첫 lit 조각에서 'NAME=' 을 떼어낸다
    parts = list(w.parts)
    if parts and parts[0][0] == 'lit':
        s = parts[0][1]
        k = s.find('=')
        parts[0] = ('lit', s[k + 1:]) if k >= 0 else parts[0]
    return name, parts


def parts_literal(parts):
    return all(p[0] in ('lit', 'arith', 'len') for p in parts)


class ShellFile(object):
    def __init__(self, rel, text, line0=1):
        self.rel = rel
        self.parser = ShellParser(text, line0)
        self.parser.parse_list()
        self.cmds = self.parser.sh['cmds']
        self.funcs = set(self.parser.sh['funcs'])
        self.assign_kinds = {}     # 이름 → {'lit','num','dyn'}
        self.assign_vals = {}      # 이름 → [값 조각들]
        self.func_alias = {}       # (함수, 지역 이름) → 위치 번호
        for cmd in self.cmds:
            decl_words = []
            if cmd.words and not cmd.assigns:
                n0 = cmd.words[0].lit()
                if n0 in SH_DECL:
                    decl_words = [w for w in cmd.words[1:] if ASSIGN_RE.match(w.raw)]
            pure = cmd.assigns if not cmd.words else []
            for w in list(pure) + decl_words:
                name, parts = assign_parts(w)
                if not name:
                    continue
                kinds = self.assign_kinds.setdefault(name, set())
                if parts_literal(parts):
                    val = ''.join(p[1] for p in parts if p[0] == 'lit')
                    kinds.add('num' if re.fullmatch(r'-?[0-9]+', val) or any(p[0] == 'arith' for p in parts)
                              else 'lit')
                else:
                    kinds.add('dyn')
                self.assign_vals.setdefault(name, []).append(parts)
                if cmd.func:
                    for p in parts:
                        if p[0] == 'pos' and p[1] != '@' and len(parts) <= 2:
                            self.func_alias[(cmd.func, name)] = p[1]
            # (( X++ )) · let X++ 는 파서가 버리므로 여기서 따로 본다
        for m in re.finditer(r'\(\(\s*([A-Za-z_][A-Za-z0-9_]*)\s*(\+\+|--|\+=|-=)|'
                             r'\blet\s+"?([A-Za-z_][A-Za-z0-9_]*)', text):
            nm = m.group(1) or m.group(3)
            self.assign_kinds.setdefault(nm, set()).add('num')


class ShellAnalyzer(object):
    def __init__(self, files, globals_num):
        self.files = files                  # rel → ShellFile (주 파일들)
        self.globals_num = globals_num      # 어딘가에서 숫자로 대입되는 이름
        self.gfuncs = {}                    # 함수 이름 → [(ShellFile)]
        for sf in files.values():
            for f in sf.funcs:
                self.gfuncs.setdefault(f, []).append(sf)
        self.findings = {}                  # (rel, line) → Finding

    # ── 값 판정 ──
    def var_secret(self, sf, name, tainted):
        if name in tainted:
            return True
        if not is_secret_name(name):
            return False
        kinds = sf.assign_kinds.get(name, set())
        if kinds and 'dyn' not in kinds:
            return False            # 글자·숫자만 대입(가짜 값·카운터)
        if not kinds and name in self.globals_num:
            return False
        return True

    def sub_secret(self, sf, sub, tainted):
        """명령 치환의 **출력**이 비밀인가: (i) 비밀 이름의 함수·명령 (ii) 비밀 파일·열을 읽음
        (iii) 비밀 변수를 그대로 내보냄(echo·printf·cat). 비밀을 **입력으로 받아** 다른 값을 내는 치환
        (예: 비밀로 코드를 계산)은 비밀로 치지 않는다 — 그 안의 명령은 따로 검사된다."""
        eff = []
        for c in sub.cmds:
            ws = effective_words(c.words)
            if ws:
                eff.append((c, os.path.basename(ws[0].lit() or ''), ws))
        names = [n for _, n, _ in eff if n]
        if any(n in HASHERS for n in names):
            return False
        if re.search(r'\bcount\s*\(|\bgrep\s+(-[a-zA-Z]*c|--count)\b', sub.text):
            return False
        for n0 in names:
            if is_secret_name(n0) and any(t in STRONG and t != 'totp' for t in name_tokens(n0)):
                return True     # qa_secret · get_token (totp_* 만 있는 이름은 코드를 계산하는 쪽이라 뺀다)
        reads = any(n in READERS for n in names)
        for c, n0, ws in eff:
            for w in ws[1:] + [x for op, x in c.redirs if op == '<']:
                for p in w.parts:
                    if p[0] == 'lit' and reads and lit_mentions_secret(p[1]):
                        return True
                    if p[0] == 'lit' and lit_reads_secret_field(p[1], jq=(n0 == 'jq')):
                        return True
                    if p[0] == 'var' and reads and file_var_secret(p[1]):
                        return True
                    if p[0] == 'var' and n0 in ('echo', 'printf', 'cat') and self.var_secret(sf, p[1], tainted):
                        return True
        return False

    def word_refs(self, sf, w, tainted, func):
        """단어 안의 비밀 참조 이름들과 위치 인자 번호들."""
        refs, poss = [], []
        for p in w.parts:
            if p[0] == 'var':
                if func and (func, p[1]) in sf.func_alias and p[1] not in tainted:
                    poss.append(sf.func_alias[(func, p[1])])
                elif self.var_secret(sf, p[1], tainted):
                    refs.append('$' + p[1])
            elif p[0] == 'pos':
                poss.append(p[1])
            elif p[0] == 'sub':
                if self.sub_secret(sf, p[1], tainted):
                    first = p[1].text.strip().split()
                    refs.append('$(%s …)' % (first[0] if first else ''))
        return refs, poss

    def taint(self, sf):
        tainted = set()
        changed = True
        while changed:
            changed = False
            for name, vals in sf.assign_vals.items():
                if name in tainted:
                    continue
                for parts in vals:
                    w = Word(parts, 0, '')
                    refs, _ = self.word_refs(sf, w, tainted, None)
                    if refs:
                        tainted.add(name)
                        changed = True
                        break
        return tainted

    # ── 명령 해석 ──
    def resolve_cmd(self, sf, cmd):
        """(종류, 인자 단어들, 기록되는가, 명령 이름). 종류: ext · func · builtin · none"""
        words = list(cmd.words)
        logged = False
        extra_args = []
        while words:
            n0 = words[0].lit()
            if n0 is None:
                if any(p[0] == 'pos' and p[1] == '@' for p in words[0].parts):
                    return 'wrap', words[1:], logged, '"$@"'
                return 'ext', extra_args + words[1:], logged, words[0].raw
            base = os.path.basename(n0)
            if base in SH_WRAP_LOGGED or base in SH_WRAP:
                if base in SH_WRAP_LOGGED:
                    logged = True
                k = 1
                # 감싸는 명령의 선택지·VAR=값 은 그 명령의 인자다
                while k < len(words):
                    r = words[k].raw
                    lit = words[k].lit()
                    if lit is not None and lit.startswith('-'):
                        extra_args.append(words[k])
                        # 값을 받는 선택지(-u 사용자 등)
                        if lit in ('-u', '-g', '-C', '-p', '-h', '-U', '-D', '-R', '-T', '-s', '-k', '-n') and \
                                base not in ('env',) and lit not in ('-n', '-k', '-s'):
                            k += 1
                            if k < len(words):
                                extra_args.append(words[k])
                        k += 1
                        continue
                    if ASSIGN_RE.match(r):
                        extra_args.append(words[k])
                        k += 1
                        continue
                    if base in ('timeout',) and re.fullmatch(r'[0-9.]+[smhd]?', lit or ''):
                        k += 1
                        continue
                    if base in ('nice', 'ionice', 'chrt', 'taskset', 'flock') and lit and re.fullmatch(r'[0-9a-fx,.-]+', lit):
                        k += 1
                        continue
                    break
                words = words[k:]
                continue
            if base in ('exec', 'command', 'builtin'):
                if base == 'builtin':
                    return 'builtin', words[1:], logged, n0
                words = words[1:]
                while words and (words[0].lit() or '').startswith('-'):
                    words = words[1:]
                continue
            if base in SH_BUILTINS and not extra_args:
                return 'builtin', words[1:], logged, n0
            if n0 in sf.funcs or (n0 in self.gfuncs and '/' not in n0):
                return 'func', words[1:], logged, n0
            return 'ext', extra_args + words[1:], logged, n0
        if extra_args:
            return 'ext', extra_args, logged, 'env'
        return 'none', [], logged, ''

    def func_file(self, sf, name):
        if name in sf.funcs:
            return sf
        lst = self.gfuncs.get(name, [])
        return lst[0] if len(lst) == 1 else (lst[0] if lst else None)

    def add(self, sev, rel, line, msg):
        key = (rel, line)
        old = self.findings.get(key)
        if old is None or (old.sev != '높음' and sev == '높음'):
            self.findings[key] = Finding(sev, rel, line, msg)

    def run(self):
        # 1) 함수별: 위치 인자가 외부 명령 인자로 가는 곳(싱크)과 다른 함수로 넘기는 곳(간선)
        sinks = {}    # (파일, 함수, 번호) → [(줄, 심각도, 명령)]
        edges = {}    # (파일, 함수, 번호) → [(파일, 함수, 번호)]
        taints = {rel: self.taint(sf) for rel, sf in self.files.items()}
        calls = []    # (sf, cmd, 함수 파일, 함수 이름, 인자 단어들)
        for rel, sf in self.files.items():
            tainted = taints[rel]
            for cmd in sf.cmds:
                if cmd.kind != 'simple':
                    continue
                kind, args, logged, cname = self.resolve_cmd(sf, cmd)
                sev = '높음' if logged else '중간'
                if kind in ('ext', 'wrap'):
                    names = []
                    for w in args:
                        refs, poss = self.word_refs(sf, w, tainted, cmd.func)
                        names.extend(refs)
                        if cmd.func:
                            for k in poss:
                                sinks.setdefault((rel, cmd.func, k), []).append((w.line, sev, cname))
                    if kind == 'wrap' and cmd.func:
                        for k in list(range(1, 10)) + ['@']:
                            sinks.setdefault((rel, cmd.func, k), []).append((cmd.line, sev, '"$@"'))
                    if names:
                        line = min(w.line for w in args if self.word_refs(sf, w, tainted, cmd.func)[0])
                        self.add(sev, rel, line, '비밀로 보이는 값(%s)이 %s 명령 인자로 들어감%s — 표준입력·파일·'
                                 '환경 변수로 넘길 것' % (', '.join(dict.fromkeys(names)), os.path.basename(cname),
                                                   ' (sudo 기록에 남음)' if logged else ''))
                elif kind == 'func':
                    ff = self.func_file(sf, cname)
                    if ff is None:
                        continue
                    calls.append((sf, cmd, ff, cname, args))
                    if cmd.func:
                        for idx, w in enumerate(args, 1):
                            _, poss = self.word_refs(sf, w, tainted, cmd.func)
                            for k in poss:
                                edges.setdefault((rel, cmd.func, k), []).append((ff.rel, cname, idx))
                                if k == '@':
                                    pass
                            if any(p[0] == 'pos' and p[1] == '@' for p in w.parts):
                                for j in range(1, 10):
                                    edges.setdefault((rel, cmd.func, j), []).append((ff.rel, cname, idx + j - 1))
        # 2) 도달 싱크(고정점)
        reach_cache = {}

        def reach(key, seen):
            if key in reach_cache:
                return reach_cache[key]
            if key in seen:
                return []
            seen = seen | {key}
            rel, fn, k = key
            out = [(rel,) + s for s in sinks.get(key, [])] + [(rel,) + s for s in sinks.get((rel, fn, '@'), [])]
            for nk in edges.get(key, []):
                out.extend(reach(nk, seen))
            reach_cache[key] = out
            return out

        # 3) 호출하는 쪽에서 비밀을 넘기는 곳
        for sf, cmd, ff, fname, args in calls:
            tainted = taints[sf.rel]
            for idx, w in enumerate(args, 1):
                refs, _ = self.word_refs(sf, w, tainted, cmd.func)
                if not refs:
                    continue
                for (srel, sline, sev, scname) in reach((ff.rel, fname, idx), frozenset()):
                    self.add(sev, srel, sline, '비밀로 보이는 값(함수 %s 의 $%d ← %s:%d 의 %s)이 %s 명령 인자로 들어감%s '
                             '— 표준입력·파일·환경 변수로 넘길 것'
                             % (fname, idx, sf.rel, w.line, ', '.join(dict.fromkeys(refs)),
                                os.path.basename(scname), ' (sudo 기록에 남음)' if sev == '높음' else ''))
        return list(self.findings.values())


# ═══ PHP ════════════════════════════════════════════════════════════════════
PHP_SINKS = {'exec', 'shell_exec', 'system', 'passthru', 'popen', 'proc_open', 'pcntl_exec'}
_PHP_TOKEN_RE = re.compile(r'''
    (?P<ws>\s+)
  | (?P<close>\?>)
  | (?P<lc>(?://|\#(?!\[))[^\n]*?(?=\?>|\n|$))
  | (?P<bc>/\*.*?\*/)
  | (?P<heredoc><<<[ \t]*(?P<hq>["']?)(?P<hid>[A-Za-z_][A-Za-z0-9_]*)(?P=hq)\r?\n)
  | (?P<sq>'(?:[^'\\]|\\.)*')
  | (?P<dq>"(?:[^"\\]|\\.)*")
  | (?P<bt>`(?:[^`\\]|\\.)*`)
  | (?P<var>\$[A-Za-z_][A-Za-z0-9_]*)
  | (?P<id>[A-Za-z_\\][A-Za-z0-9_\\]*)
  | (?P<num>[0-9][0-9A-Za-z_.]*)
  | (?P<op>->|::|\?->|=>|===|!==|==|!=|<=|>=|\.=|\+=|-=|\?\?=|\?\?|&&|\|\||[-+*/%=<>!.,;:?()\[\]{}&|^~@])
  | (?P<other>.)
''', re.S | re.X)
_PHP_INTERP_RE = re.compile(r'\{\$([A-Za-z_][A-Za-z0-9_]*)((?:->[A-Za-z_][A-Za-z0-9_]*|\[[^\]]*\])*)\}|'
                            r'\$\{([A-Za-z_][A-Za-z0-9_]*)\}|'
                            r'\$([A-Za-z_][A-Za-z0-9_]*)((?:->[A-Za-z_][A-Za-z0-9_]*|\[[^\]\s]*\])?)')


def php_tokens(text):
    """(종류, 값, 줄). PHP 태그 밖은 버린다."""
    toks = []
    pos = 0
    line = 1
    n = len(text)
    while pos < n:
        k = text.find('<?php', pos)
        k2 = text.find('<?=', pos)
        if k < 0 or (0 <= k2 < k):
            k = k2
        if k < 0:
            break
        line += text.count('\n', pos, k)
        pos = k + (5 if text.startswith('<?php', k) else 3)
        while pos < n:
            m = _PHP_TOKEN_RE.match(text, pos)
            if not m:
                break
            kind = m.lastgroup
            val = m.group(0)
            if kind == 'close':
                pos = m.end()
                break
            if kind == 'heredoc':
                hid = m.group('hid')
                endm = re.compile(r'^[ \t]*' + re.escape(hid) + r'\b', re.M).search(text, m.end())
                end = endm.start() if endm else n
                body = text[m.end():end]
                toks.append(('dq' if m.group('hq') != "'" else 'sq', body, line))
                line += text.count('\n', pos, endm.end() if endm else n)
                pos = endm.end() if endm else n
                continue
            if kind not in ('ws', 'lc', 'bc'):
                toks.append((kind, val, line))
            line += val.count('\n')
            pos = m.end()
    return toks


def php_str_refs(s):
    """큰따옴표·heredoc 글자 안의 변수 참조: [(변수 이름, 뒤 접근자 글자)]"""
    out = []
    for m in _PHP_INTERP_RE.finditer(s):
        if m.group(1):
            out.append((m.group(1), m.group(2) or ''))
        elif m.group(3):
            out.append((m.group(3), ''))
        else:
            out.append((m.group(4), m.group(5) or ''))
    return out


def php_accessor_secret(acc):
    for m in re.finditer(r"->([A-Za-z_][A-Za-z0-9_]*)|\[\s*['\"]?([A-Za-z_][A-Za-z0-9_]*)['\"]?\s*\]", acc):
        nm = m.group(1) or m.group(2)
        if nm and is_secret_name(nm):
            return nm
    return None


class PhpFile(object):
    def __init__(self, rel, text, line0=1):
        self.rel = rel
        self.line0 = line0
        self.toks = php_tokens(text if '<?' in text else '<?php\n' + text)
        if '<?' not in text:
            self.toks = [(k, v, ln - 1) for (k, v, ln) in self.toks]

    def refs_in(self, toks, tainted):
        """토큰 조각 안의 비밀 참조(감싸는 호출이 NONREVEAL 이면 뺌)."""
        refs = []
        stack = []     # 감싸는 호출 이름
        for i, (k, v, ln) in enumerate(toks):
            if k == 'op' and v == '(':
                prev = toks[i - 1] if i > 0 else None
                stack.append(prev[1].lower().lstrip('\\') if prev and prev[0] == 'id' else '')
                continue
            if k == 'op' and v == ')':
                if stack:
                    stack.pop()
                continue
            if any(s in NONREVEAL for s in stack):
                continue
            if k == 'var':
                nm = v[1:]
                nxt = toks[i + 1] if i + 1 < len(toks) else None
                acc = ''
                if nxt and nxt[0] == 'op' and nxt[1] in ('->', '?->') and i + 2 < len(toks) and toks[i + 2][0] == 'id':
                    acc = '->' + toks[i + 2][1]
                elif nxt and nxt[1] == '[' and i + 2 < len(toks) and toks[i + 2][0] in ('sq', 'dq'):
                    acc = '[' + toks[i + 2][1] + ']'
                if nm in tainted or (nm != 'this' and is_secret_name(nm)):
                    refs.append('$' + nm)
                else:
                    a = php_accessor_secret(acc)
                    if a:
                        refs.append('$%s…%s' % (nm, a))
            elif k in ('dq', 'bt'):
                for nm, acc in php_str_refs(v[1:-1] if k != 'dq' or v.startswith('"') else v):
                    if nm in tainted or is_secret_name(nm):
                        refs.append('$' + nm)
                    else:
                        a = php_accessor_secret(acc)
                        if a:
                            refs.append('$%s…%s' % (nm, a))
            elif k == 'id':
                nxt = toks[i + 1] if i + 1 < len(toks) else None
                prev = toks[i - 1] if i > 0 else None
                if nxt and nxt[1] == '(' and is_secret_name(v.split('\\')[-1]) and \
                        not (prev and prev[1] == 'function'):
                    refs.append(v + '()')
        return refs

    def taint(self):
        tainted = set()
        toks = self.toks
        changed = True
        while changed:
            changed = False
            for i, (k, v, ln) in enumerate(toks):
                if k != 'var' or i + 1 >= len(toks):
                    continue
                j = i + 1
                if toks[j][1] == '[' and j + 1 < len(toks) and toks[j + 1][1] == ']':
                    j += 2
                if j >= len(toks) or toks[j][0] != 'op' or toks[j][1] not in ('=', '.=', '??='):
                    continue
                nm = v[1:]
                if nm in tainted:
                    continue
                expr = []
                depth = 0
                for t in toks[j + 1:]:
                    if t[1] in ('(', '[', '{'):
                        depth += 1
                    elif t[1] in (')', ']', '}'):
                        depth -= 1
                        if depth < 0:
                            break
                    elif t[1] == ';' and depth <= 0:
                        break
                    expr.append(t)
                if self.refs_in(expr, tainted):
                    tainted.add(nm)
                    changed = True
        return tainted

    def run(self):
        tainted = self.taint()
        out = []
        toks = self.toks
        for i, (k, v, ln) in enumerate(toks):
            if k == 'bt':
                refs = self.refs_in([(k, v, ln)], tainted)
                if refs:
                    out.append(Finding('높음' if re.search(r'\bsudo\b', v) else '중간', self.rel, ln + self.line0 - 1,
                                       '비밀로 보이는 값(%s)이 백틱 셸 명령 인자로 들어감 — 표준입력·파일·환경 변수로 넘길 것'
                                       % ', '.join(dict.fromkeys(refs))))
                continue
            if k != 'id' or v.lower().lstrip('\\') not in PHP_SINKS:
                continue
            prev = toks[i - 1] if i > 0 else None
            if prev and prev[1] in ('->', '::', '?->', 'function', 'new', 'const'):
                continue
            if i + 1 >= len(toks) or toks[i + 1][1] != '(':
                continue
            arg, depth = [], 0
            for t in toks[i + 2:]:
                if t[1] in ('(', '[', '{'):
                    depth += 1
                elif t[1] in (')', ']', '}'):
                    if depth == 0:
                        break
                    depth -= 1
                elif t[1] == ',' and depth == 0:
                    break
                arg.append(t)
            refs = self.refs_in(arg, tainted)
            if refs:
                logged = any(t[0] in ('sq', 'dq') and re.search(r'(^|[\s\'"])sudo\b', t[1]) for t in arg)
                out.append(Finding('높음' if logged else '중간', self.rel, ln + self.line0 - 1,
                                   '비밀로 보이는 값(%s)이 %s() 명령 인자로 들어감%s — 표준입력(proc_open 파이프)·파일·'
                                   '환경 변수로 넘길 것' % (', '.join(dict.fromkeys(refs)), v.lstrip('\\'),
                                                     ' (sudo 기록에 남음)' if logged else '')))
        return out


# ═══ 파이썬 ═════════════════════════════════════════════════════════════════
PY_SINKS = {
    'subprocess.run': 'first', 'subprocess.call': 'first', 'subprocess.check_call': 'first',
    'subprocess.check_output': 'first', 'subprocess.Popen': 'first', 'subprocess.getoutput': 'first',
    'subprocess.getstatusoutput': 'first', 'os.system': 'first', 'os.popen': 'first',
    'asyncio.create_subprocess_exec': 'all', 'asyncio.create_subprocess_shell': 'first', 'pty.spawn': 'first',
    'os.posix_spawn': 'second', 'os.posix_spawnp': 'second',
}
for _n in ('execv', 'execvp', 'execl', 'execlp', 'spawnv', 'spawnvp', 'spawnl', 'spawnlp'):
    PY_SINKS['os.' + _n] = 'all'
for _n in ('execve', 'execvpe', 'execle', 'execlpe', 'spawnve', 'spawnvpe', 'spawnle', 'spawnlpe'):
    PY_SINKS['os.' + _n] = 'all_but_last'


def _py_dotted(node):
    parts = []
    while isinstance(node, ast.Attribute):
        parts.append(node.attr)
        node = node.value
    if isinstance(node, ast.Name):
        parts.append(node.id)
        return '.'.join(reversed(parts))
    return None


class PyFile(object):
    def __init__(self, rel, text, line0=1):
        self.rel = rel
        self.line0 = line0
        self.tree = ast.parse(text)
        self.alias = {}
        for node in ast.walk(self.tree):
            if isinstance(node, ast.Import):
                for a in node.names:
                    self.alias[a.asname or a.name] = a.name
            elif isinstance(node, ast.ImportFrom) and node.module:
                for a in node.names:
                    self.alias[a.asname or a.name] = node.module + '.' + a.name

    def resolve(self, func):
        d = _py_dotted(func)
        if not d:
            return None
        head, _, rest = d.partition('.')
        full = self.alias.get(head, head)
        return full + ('.' + rest if rest else '')

    def refs(self, node, tainted):
        out = []

        def visit(n):
            if isinstance(n, ast.Call):
                fname = _py_dotted(n.func) or ''
                last = fname.split('.')[-1].lower()
                if last in NONREVEAL or fname.split('.')[0] in ('hashlib', 'hmac'):
                    return
                if last and is_secret_name(fname.split('.')[-1]):
                    out.append(fname + '()')
            if isinstance(n, ast.Name) and (n.id in tainted or is_secret_name(n.id)):
                out.append(n.id)
                return
            if isinstance(n, ast.Attribute) and is_secret_name(n.attr):
                out.append((_py_dotted(n) or n.attr))
                return
            if isinstance(n, ast.Subscript):
                sl = n.slice
                if isinstance(sl, ast.Index):      # 3.8 이하
                    sl = sl.value
                if isinstance(sl, ast.Constant) and isinstance(sl.value, str) and is_secret_name(sl.value):
                    out.append('[%r]' % sl.value)
                    return
            for c in ast.iter_child_nodes(n):
                visit(c)

        visit(node)
        return out

    def taint(self):
        tainted = set()
        changed = True
        while changed:
            changed = False
            for node in ast.walk(self.tree):
                if isinstance(node, (ast.Assign, ast.AugAssign, ast.AnnAssign)) and node.value is not None:
                    targets = node.targets if isinstance(node, ast.Assign) else [node.target]
                    names = [t.id for t in targets if isinstance(t, ast.Name)]
                    for t in targets:
                        if isinstance(t, (ast.Tuple, ast.List)):
                            names.extend(e.id for e in t.elts if isinstance(e, ast.Name))
                    if not names or all(nm in tainted for nm in names):
                        continue
                    if self.refs(node.value, tainted):
                        for nm in names:
                            if nm not in tainted:
                                tainted.add(nm)
                                changed = True
        return tainted

    def run(self):
        tainted = self.taint()
        out = []
        for node in ast.walk(self.tree):
            if not isinstance(node, ast.Call):
                continue
            full = self.resolve(node.func)
            how = PY_SINKS.get(full or '')
            if not how:
                continue
            if how == 'first':
                args = node.args[:1] or [kw.value for kw in node.keywords if kw.arg in ('args', 'cmd', 'command')]
            elif how == 'second':
                args = node.args[1:2]
            elif how == 'all':
                args = node.args
            else:
                args = node.args[:-1]
            refs = []
            for a in args:
                refs.extend(self.refs(a, tainted))
            if refs:
                logged = any(isinstance(n, ast.Constant) and isinstance(n.value, str) and
                             re.match(r'\s*(sudo|doas)\b', n.value) for a in args for n in ast.walk(a))
                out.append(Finding('높음' if logged else '중간', self.rel, node.lineno + self.line0 - 1,
                                   '비밀로 보이는 값(%s)이 %s 명령 인자로 들어감%s — input=·파일·env= 로 넘길 것'
                                   % (', '.join(dict.fromkeys(refs)), full, ' (sudo 기록에 남음)' if logged else '')))
        return out


# ═══ 파일 고르기 · 끼운 스크립트 ═════════════════════════════════════════════
def file_lang(rel, head):
    ext = os.path.splitext(rel)[1].lower()
    if ext in ('.sh', '.bash'):
        return 'sh'
    if ext in ('.php', '.phtml'):
        return 'php'
    if ext == '.py':
        return 'py'
    if ext == '' and head.startswith('#!'):
        first = head.split('\n', 1)[0]
        if re.search(r'\b(ba|da|k|z)?sh\b', first):
            return 'sh'
        if 'php' in first:
            return 'php'
        if 'python' in first:
            return 'py'
    return None


def heredoc_lang(cmd, body):
    first = body.lstrip('\n').split('\n', 1)[0]
    if first.startswith('#!'):
        if 'python' in first:
            return 'py'
        if 'php' in first:
            return 'php'
        if re.search(r'\b(ba|da)?sh\b', first):
            return 'sh'
    for op, w in cmd.redirs:
        tgt = (w.lit() or w.raw).strip('"\'')
        ext = os.path.splitext(tgt)[1].lower()
        if ext == '.py':
            return 'py'
        if ext in ('.php', '.phtml'):
            return 'php'
        if ext in ('.sh', '.bash'):
            return 'sh'
    if cmd.words:
        n0 = os.path.basename(cmd.words[0].lit() or '')
        rest = [w.lit() for w in cmd.words[1:]]
        if re.match(r'python[0-9.]*$', n0) and ('-' in rest or not rest):
            return 'py'
        if n0 == 'php' and not [r for r in rest if r and not r.startswith('-')]:
            return 'php'
        if n0 in ('bash', 'sh', 'dash') and (not rest or '-s' in rest):
            return 'sh'
    if body.lstrip().startswith('<?php'):
        return 'php'
    return None


def list_files(root, use_git):
    if use_git:
        r = subprocess.run(['git', '-C', root, '-c', 'core.quotepath=off', 'ls-files', '-z', '--cached', '--others',
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
        r = subprocess.run(['git', '-C', root, 'rev-parse', '--show-toplevel'], stdout=subprocess.PIPE,
                           stderr=subprocess.DEVNULL, timeout=60)
    except (OSError, subprocess.SubprocessError):
        return None
    if r.returncode != 0:
        return None
    return os.path.realpath(r.stdout.decode('utf-8', 'replace').strip())


def main(argv=None):
    here = os.path.dirname(os.path.abspath(__file__))
    ap = argparse.ArgumentParser(description='R15 비밀 인자 — 비밀이 명령 인자로 들어가는 모양(읽기만)')
    ap.add_argument('--root', default=os.path.normpath(os.path.join(here, '..', '..')),
                    help='검사할 트리(기본: 이 파일 기준 저장소 뿌리)')
    ap.add_argument('--dirs', default=','.join(DEFAULT_DIRS), help='뿌리 아래 볼 디렉터리(쉼표, 기본 tests,scripts,src)')
    ap.add_argument('--exclude', action='append', default=None,
                    help='뺄 경로 glob(여러 번). 주면 기본값(tests/static/fixtures/*)을 대신한다')
    ap.add_argument('--walk', action='store_true', help='git 목록 대신 디렉터리 전체를 본다')
    args = ap.parse_args(argv)

    root = os.path.realpath(args.root)
    excludes = DEFAULT_EXCLUDES if args.exclude is None else args.exclude
    dirs = [d.strip('/ ') for d in args.dirs.split(',') if d.strip('/ ')]
    if not os.path.isdir(root):
        sys.stderr.write('R15: 뿌리가 디렉터리가 아님: %s\n' % args.root)
        print('RESULT pass=0 fail=0 skip=1')
        return 2
    try:
        use_git = (not args.walk) and repo_top(root) == root
        rels = list_files(root, use_git)
    except (RuntimeError, OSError, subprocess.SubprocessError) as e:
        sys.stderr.write('R15: 대상 목록을 만들지 못함: %s\n' % e)
        print('RESULT pass=0 fail=0 skip=1')
        return 2

    targets, skipped = [], 0
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
                data = fh.read()
        except OSError as e:
            sys.stderr.write('R15: 읽지 못함: %s (%s)\n' % (rel, e.strerror))
            skipped += 1
            continue
        text = data.decode('utf-8', 'replace').lstrip(chr(0xFEFF))
        lang = file_lang(rel, text[:200])
        if lang:
            targets.append((rel, lang, text))

    findings = []
    sh_files = {}
    globals_num = set()
    parsed = 0
    for rel, lang, text in targets:
        try:
            if lang == 'sh':
                sf = ShellFile(rel, text)
                sh_files[rel] = sf
                for nm, kinds in sf.assign_kinds.items():
                    if 'num' in kinds:
                        globals_num.add(nm)
            elif lang == 'php':
                findings.extend(PhpFile(rel, text).run())
            else:
                findings.extend(PyFile(rel, text).run())
            parsed += 1
        except (SyntaxError, ValueError, RecursionError, IndexError) as e:
            sys.stderr.write('R15: 구문 분석 실패로 건너뜀: %s (%s)\n' % (rel, e.__class__.__name__))
            skipped += 1

    # 셸 안 heredoc 으로 만드는 스크립트
    embedded_sh = {}
    for rel, sf in list(sh_files.items()):
        for cmd in sf.cmds:
            for body, line0, quoted in cmd.heredocs:
                lang = heredoc_lang(cmd, body)
                if not lang:
                    continue
                src = body if quoted else re.sub(r'\\([$`\\])', r'\1', body)
                try:
                    if lang == 'sh':
                        emb = ShellFile(rel, src, line0)
                        embedded_sh['%s@%d' % (rel, line0)] = emb
                    elif lang == 'php':
                        findings.extend(PhpFile(rel, src, line0).run())
                    else:
                        findings.extend(PyFile(rel, src, line0).run())
                except (SyntaxError, ValueError, RecursionError, IndexError):
                    pass
    sa = ShellAnalyzer(sh_files, globals_num)
    findings.extend(sa.run())
    for key, emb in embedded_sh.items():
        ea = ShellAnalyzer({key: emb}, globals_num)
        for f in ea.run():
            f.path = emb.rel
            findings.append(f)

    uniq = {}
    for f in findings:
        k = (f.path, f.line)
        if k not in uniq or (uniq[k].sev != '높음' and f.sev == '높음'):
            uniq[k] = f
    findings = sorted(uniq.values(), key=lambda f: (f.path, f.line))
    for f in findings:
        print('FINDING %s %s %s:%d %s' % (RULE, f.sev, f.path, f.line, f.msg))
    bad = len(set(f.path for f in findings))
    print('RESULT pass=%d fail=%d skip=%d' % (max(0, parsed - bad), len(findings), skipped))
    return 1 if findings else 0


if __name__ == '__main__':
    sys.exit(main())
