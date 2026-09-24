#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
R15 비밀 인자 (secrets on the command line) — 1-F 정적 규칙, python3 stdlib 만, 파일은 읽기만.

answers: BF-0002 · BF-0003(처음 부류 — TOTP 비밀·비밀번호) · BF-0006 · HO-HF0-005(세션 쿠키 — 묶음 X 보강)
         · FP-0001(오탐 정정 — 줄 이어짐으로 나뉜 환경 변수 접두)
         · v3 묶음 K: HO-B2-011(세션 해시 키 · 한 싱크의 출처 전부) · FP-0006(sed 로 뽑은 설정 값) · v2 한계(*_id 예외 ·
           secrets.sh `len(` 오탐 — bot-v2-static.md) · 바깥 저장소 반영 스크립트(--outer)

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
      ④ 세션 쿠키(묶음 X) — 로그인 세션 값은 비밀번호와 같은 자격이다(가진 사람이 그 계정으로 요청한다).
        (a) 이름: 토큰 COOKIE(S)·SESSION·SESS·SID(…SESSID) 가 든 변수·함수·속성. 「자리」(…_FILE·_PATH·
            _DIR·JAR)·이름·개수·여부(HAVE_SESSION)는 ① 과 같은 방식으로 뺀다. PHP `$_SESSION`(서버 쪽
            저장소)·파이썬 `Session()` 같은 그릇 만들기는 값이 아니라서 뺀다.
        (b) 모양(이름과 상관없이): `curl -b "이름=$변수"`·`--cookie "…=$…"`(-b 뒤 변수 하나면 그 변수에
            `이름=$값` 모양이 대입될 때), `Cookie:` 머리 글자 뒤에 확장이 붙은 단어(`-H "Cookie: …$…"`·
            대입 `H="Cookie: …$…"` 도 흐름으로). `Set-Cookie:`(응답 머리 — 찾는 무늬)는 뺀다.
        (c) 쿠키 jar·세션 파일에서 값을 뽑는 명령 치환(`$(awk … "$JAR")`·`$(cat x.session)` — 글 뽑는
            명령이 jar 변수(이름 끝 JAR)·`.jar` 경로·쿠키/세션 이름의 파일을 읽음)은 ② 의 흐름으로 친다.
        CSRF 토큰(X-CSRF-Token)은 세션 없이는 못 쓰므로 이 규칙 범위가 아니다.
      이름으로 비밀인 셸 변수라도 대입되는 값이 전부 id 칸 읽기(`SELECT id FROM …`·`d.get('id')`·jq `.id`)면
      식별자로 보고 뺀다(이름에 ID 가 붙으면 빼는 것과 같은 뜻 — 값 쪽에서 본 것). 대입되는 값이 전부 경로 모양
      (`TOTP="$CONF/qa-totp.txt"` — 비밀 파일의 자리)이어도 뺀다.
      ⑤ 세션 키(v3 ③): 세션 토큰 뒤의 ID(session_id · SESSION_ID)·세션 표의 id 칸(`SELECT id FROM sessions`)·
        `session_id` 칸 읽기는 식별자 예외에 넣지 않는다 — 이 저장소의 sessions.id 는 sha256(세션 토큰)인 세션 키다.
      ⑥ 값을 드러내지 않는 모양은 뺀다(v3 ④): len(…)·any(…)·sum(…) 안의 칸 읽기, 끝이 `| length` 인 jq 식,
        sed `s/…\(<숫자 틀>\)…/\1/`·`grep -o '<숫자 틀>'` 로 숫자(+단위)만 뽑는 치환(설정 값 「60s」 — FP-0006),
        이름 끝의 시간 설정 말(timeout·idle·lifetime·interval … — idle_in_transaction_session_timeout).
    외부 명령의 인자 — 셸: 빌트인(echo·printf·read·test·local·export…)과 셸 함수가 아닌 명령의 인자
      단어, sudo·env 류 뒤의 `VAR=값` 도 인자다. 표준입력(`<<<`·heredoc·파이프)·리다이렉트 대상·
      명령 앞 `VAR=값`(환경 — 여러 개가 줄 이어짐 `\` 로 나뉘어도 첫 대입 아닌 단어가 명령)은 통과.
      PHP: exec·shell_exec·system·passthru·popen·proc_open·pcntl_exec 의
      명령 인자와 백틱. 파이썬: subprocess.*·os.system·os.popen·os.exec*·os.spawn*·asyncio 하위 프로세스의
      명령 인자(`input=`·`env=`·`stdin=` 은 통과). strlen·hash 류로 감싼 값은 비밀이 아니다.
    heredoc 으로 만드는 스크립트(셔뱅·대상 확장자·`python3 -` 로 판단)도 같은 방법으로 본다.
  심각도: sudo·doas 를 거치면 높음(기록에 남음), 아니면 중간. 싱크에 닿는 값이 **전부** 세션 해시 키(sha256(토큰) 류 —
    이름 sid·session_id, 해시기 출력을 받아 쓴 파일(`… | sha256sum > "$W/x.sid"`)을 읽은 값, 세션 표의 id 칸)이면
    한 단계 낮춘다(v3 ② — 토큰 자체가 아니라 되돌릴 수 없는 키, HO-B2-011 판정).
  한 싱크(함수 안의 명령 인자)에 여러 호출 자리가 비밀을 넘기면 출처를 **모두** 한 줄에 적는다(v3 ① — 같은 이름을 넘기는
    줄은 `파일:190·191·478` 으로 묶고, 8곳이 넘으면 「외 n곳」).
  같은 줄에 `qcheck-allow: R15` 가 있으면 뺀다(skip 으로 센다).

대상: --root 아래 --dirs(기본 tests·scripts·src)의 셸(*.sh·*.bash·셸 셔뱅)·PHP(*.php·*.phtml·php 셔뱅)·
      파이썬(*.py·python 셔뱅). --root 가 git 작업 트리의 맨 위이면 `git ls-files --cached --others
      --exclude-standard`, 아니면 디렉터리 전체. 기본으로 `tests/static/fixtures/*` 는 뺀다(--exclude 로 바꿈).
      --outer <바깥 배치>: 그 아래 scripts/ 의 셸·PHP·파이썬도 본다(보고 경로 ../scripts/…, 셸 함수는 뿌리 안
      파일과 섞지 않고 따로 푼다). 없거나 none 이면 바깥은 보지 않는다(v3 ⑤).
출력: FINDING R15 <심각도> <파일:줄> <설명> · 끝 줄 RESULT pass=<대상 - 발견 파일> fail=<발견 수> skip=<못 읽음·구문 오류>
      설명에는 변수 이름만 적고 값은 적지 않는다.
rc:   0 발견 없음 · 1 발견 있음 · 2 실행 오류(뿌리 없음·git 목록 실패 = 검사 못 함)
한계: 셸 분석은 bash 문법의 부분 집합이다(case 안의 `$(…)`·eval·xargs·동적 명령 이름·배열 원소별 추적은
      못 본다). `printf -v 변수` 로 만든 값은 흐름에 넣지 않는다. 이름에도 모양에도 표시가 없는 쿠키 값
      (예: 응답 머리를 다른 이름으로 받아 그대로 넘김)은 못 잡는다. 이름 SID 가 세션이 아닌 것(공유 id 등)을
      뜻하면 값이 id 칸 읽기일 때만 빠진다.
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
# 세션 쿠키(묶음 X ④-a): 로그인 세션 값. 복수 SESSIONS 는 표·목록 이름으로 흔해서 넣지 않는다
COOKIE_TOK = {'cookie', 'cookies', 'session', 'sess', 'sid', 'sessid', 'sessionid', 'phpsessid', 'jsessionid'}
STRONG = {'secret', 'secrets', 'totp', 'password', 'passwords', 'passwd', 'passphrase', 'credential',
          'credentials', 'apikey', 'privkey', 'secretkey', 'accesskey', 'appkey', 'token', 'tokens', 'pw'} | COOKIE_TOK
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
               'masked', 'redact', 'redacted', 'placeholder', 'example', 'sample', 'jar', 'jars',
               # 시간 설정 이름(idle_in_transaction_session_timeout · SESSION_IDLE_MIN) — 값은 길이·시간이다(FP-0006)
               'timeout', 'timeouts', 'idle', 'lifetime', 'duration', 'interval', 'delay', 'timer'}
# 세션 토큰 뒤의 ID 는 「자리」가 아니라 세션 행의 키다(session_id · SESSION_ID — v3 ③). 비밀로 치되 심각도를 낮춘다(v3 ②)
SESSION_KEY_TOK = {'session', 'sess'}
# 비밀 토큰 **앞**에 오면 「가짜·개수·여부」(BAD_TOKEN · N_TOKENS · HAS_PASSWORD)
PRE_EXEMPT = {'bad', 'fake', 'wrong', 'invalid', 'bogus', 'dummy', 'garbage', 'junk', 'revoked', 'expired',
              'example', 'placeholder', 'sample', 'mock', 'stub', 'n', 'num', 'count', 'cnt', 'len', 'length',
              'max', 'min', 'is', 'has', 'have', 'no', 'need', 'needs', 'use', 'show', 'hide', 'mask', 'masked',
              'redact', 'redacted'}
# 글자(파일 경로·SQL 조각) 안의 낱말로 「비밀을 담은 곳」을 알아보는 토큰. 쿠키·세션 파일(x.session · cookie.hdr)도
STRONG_TEXT = {'secret', 'secrets', 'totp', 'password', 'passwords', 'passwd', 'passphrase', 'credential',
               'credentials', 'cookie', 'cookies', 'session'}
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
    post = toks[idx + 1:]
    if toks[idx] in SESSION_KEY_TOK and post[:1] in (['id'], ['ids']):
        post = post[1:]      # session_id — 세션 행 키(해시 키)는 식별자가 아니다(v3 ③)
    if any(t in POST_EXEMPT for t in post):
        return False
    if any(t in PRE_EXEMPT for t in toks[:idx]):
        return False
    return True


def is_hashkey_name(name):
    """세션 행의 키를 뜻하는 이름(sid · session_id · SESSION_ID) — 토큰 자체가 아니라 그 sha256 해시 키(v3 ②).
    이 저장소의 sessions.id 는 sha256(세션 토큰)이다 — 되돌려 쿠키로 쓸 수 없다(HO-B2-011 판정)."""
    toks = name_tokens(name)
    idx = secret_index(toks)
    if idx < 0:
        return False
    if toks[idx] == 'sid':
        return True
    return toks[idx] in SESSION_KEY_TOK and toks[idx + 1:idx + 2] in (['id'], ['ids'])


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


# 값을 드러내지 않는 감싸기(개수·여부·길이) — len(d.get('tokens')) 는 토큰이 아니라 개수다(v3 ④ secrets.sh `len(` 오탐)
_NONREVEAL_CALL_RE = re.compile(r'\b(?:len|sum|any|all|bool|int|float|isinstance|hasattr|type|count|max|min)\s*\(')
_JQ_NONREVEAL_TAIL_RE = re.compile(r'\|\s*(?:length|any|all|type|keys|has\([^)]*\)|not)\s*[)\]"\']*\s*$')


def _strip_nonreveal(lit):
    """len(…)·sum(…)·any(…) 같은 감싸기 안쪽을 지운 글자."""
    out, i = [], 0
    while True:
        m = _NONREVEAL_CALL_RE.search(lit, i)
        if not m:
            out.append(lit[i:])
            break
        out.append(lit[i:m.start()])
        depth, j = 0, m.end() - 1
        while j < len(lit):
            if lit[j] == '(':
                depth += 1
            elif lit[j] == ')':
                depth -= 1
                if depth == 0:
                    break
            j += 1
        out.append(' ')
        i = j + 1
    return ''.join(out)


def lit_reads_secret_field(lit, jq=False):
    """글자 안에서 비밀 이름의 칸을 **읽는** 모양: x["secret"] · d.get('token') · (jq) .password
    (len(…)·any(…) 같은 개수·여부 감싸기 안쪽과, 끝이 `| length` 인 jq 식은 값을 드러내지 않으므로 뺀다)"""
    if jq and _JQ_NONREVEAL_TAIL_RE.search(lit):
        return False
    lit = _strip_nonreveal(lit)
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


# ─── 세션 쿠키 모양(묶음 X ④-b·c) ───────────────────────────────────────────
# 요청 머리 `Cookie:` — 앞이 글자·`-` 면 아니다(`Set-Cookie:` 는 응답 머리 · 찾는 무늬)
_COOKIE_HDR_RE = re.compile(r'(?i)(?<![A-Za-z0-9_-])cookie[ \t]*:')
# 글자 끝이 「-b / --cookie 뒤 이름=」 인 조각(PHP·파이썬 문자열 잇기에서 값이 바로 뒤에 붙는 자리)
_COOKIE_OPT_OPEN_RE = re.compile(r'''(?:^|\s)(?:-b|--cookie)(?:\s+|=)['"]?[A-Za-z0-9_.!#%&*+^|~-]+=[^\s'"]*$''')
_COOKIE_HDR_OPEN_RE = re.compile(r'''(?i)(?<![A-Za-z0-9_-])cookie[ \t]*:[^'"\n]*$''')
# 글자 안(PHP 큰따옴표·백틱)에서 -b/--cookie 의 이름=값 이나 Cookie: 머리 뒤에 변수가 끼는 모양
_COOKIE_INTERP_RE = re.compile(r'''(?:(?:^|\s)(?:-b|--cookie)(?:\s+|=)['"]?[A-Za-z0-9_.!#%&*+^|~-]+=[^\s'"$]*|'''
                               r'''(?i:(?<![A-Za-z0-9_-])cookie[ \t]*:[^'"\n$]*))\{?\$\{?([A-Za-z_][A-Za-z0-9_]*)''')


_NUM_CLASS_RE = re.compile(r'^(?:\[(?:0-9|\\d|0-9\.|\.0-9|0-9,|0-9_)\]|\\d|[0-9])(?:[*+?]|\{\d*,?\d*\})?'
                           r'(?:(?:\\?\.)?(?:\[(?:0-9|\\d)\]|\\d)(?:[*+?]|\{\d*,?\d*\})?)*'
                           r'(?:ms|s|m|h|d|min|sec|kb|mb|gb|k|%)?$', re.I)


NUM_FILTERS = {'head', 'tail', 'sort', 'uniq', 'tr', 'cut', 'tac'}


def _numeric_stage(ws):
    n0 = os.path.basename(ws[0].lit() or '')
    lits = [w.lit() for w in ws[1:]]
    if n0 == 'sed':
        for lt in lits:
            if not lt:
                continue
            m = re.match(r'^s(.)(.*)\1(\\[0-9])\1[gpI0-9]*$', lt, re.S)
            if m:
                groups = re.findall(r'\\\((.*?)\\\)', m.group(2))
                k = int(m.group(3)[1:])
                if 0 < k <= len(groups) and _NUM_CLASS_RE.match(groups[k - 1]):
                    return True
        return False
    if n0 == 'grep' and any(lt and re.fullmatch(r'-[A-Za-z]*o[A-Za-z]*', lt) for lt in lits):
        pats = [lt for lt in lits if lt and not lt.startswith('-')]
        return bool(pats) and _NUM_CLASS_RE.match(pats[0]) is not None
    return False


def numeric_extract(sub):
    """명령 치환이 글자에서 숫자(+단위)만 뽑는가 — `sed -n 's/…\\([0-9]*s\\)…/\\1/p'` · `grep -oE '[0-9]+'`
    (뒤에 head·tail·sort·uniq·tr·cut 만 이어져도 같다). 그 출력은 길이·시간 같은 설정 값이라 비밀이 아니다.
    뽑는 틀이 숫자뿐일 때만 뺀다."""
    stages = [effective_words(c.words) for c in sub.cmds]
    stages = [ws for ws in stages if ws]
    for k, ws in enumerate(stages):
        if _numeric_stage(ws) and all(os.path.basename(w[0].lit() or '') in NUM_FILTERS for w in stages[k + 1:]):
            return True
    return False


def jar_var(name):
    """쿠키 jar 를 가리키는 변수 — 이름 끝이 JAR(JAR · ADM_JAR · EJAR · cookie_jar). JAR_DIR 같은 폴더는 아니다."""
    return name.lower().endswith('jar')


def lit_jar_path(lit):
    """글자가 쿠키 jar 파일 경로 조각인가(… .jar)."""
    return bool(re.search(r'\.jar(?![A-Za-z0-9_])', lit))


_ID_READ_RES = (
    re.compile(r'(?is)\bselect\s+(?:distinct\s+)?(?:[a-z_][a-z0-9_]*\.)?(?P<c>id|[a-z_][a-z0-9_]*_id)\s+from\b'),
    re.compile(r'''\.get\(\s*(['"])(?P<c>id|[A-Za-z_][A-Za-z0-9_]*_id)\1'''),
    re.compile(r'''\[\s*(['"])(?P<c>id|[A-Za-z_][A-Za-z0-9_]*_id)\1\s*\]'''),
    re.compile(r'''(?:^|[\s|'"(,])\.(?P<c>id|[A-Za-z_][A-Za-z0-9_]*_id)(?![A-Za-z0-9_.(\[])'''),
)

# 세션 표(sessions · user_sessions)의 id — 이 저장소에서 sessions.id 는 sha256(세션 토큰)인 세션 키다(v3 ③)
_SESSION_TABLE_ID_RE = re.compile(r'(?is)\bselect\s+(?:distinct\s+)?(?:[a-z_][a-z0-9_]*\.)?id\s+from\s+'
                                  r'(?:[a-z_][a-z0-9_]*\.)?"?([a-z_][a-z0-9_]*)')


def _id_col_is_session_key(col):
    toks = name_tokens(col)
    return bool(toks) and toks[-1] in ('id', 'ids') and any(t in SESSION_KEY_TOK or t == 'sid' for t in toks[:-1])


def text_reads_id_only(text):
    """명령 치환 글이 id 칸(식별자)만 읽나 — SELECT id FROM · d.get('id') · x["id"] · jq .id (비밀 칸 읽기는 아님).
    세션 키(session_id 칸 · 세션 표의 id)는 식별자로 보지 않는다(v3 ③ — 비밀로 치고 심각도만 낮춘다)."""
    if lit_reads_secret_field(text, jq=True):
        return False
    for m in _SESSION_TABLE_ID_RE.finditer(text):
        tbl = m.group(1)
        if any(t in ('session', 'sessions', 'sess') for t in name_tokens(tbl)):
            return False
    for r in _ID_READ_RES:
        for m in r.finditer(text):
            if _id_col_is_session_key(m.group('c')):
                return False
    return any(r.search(text) for r in _ID_READ_RES)


SEV_DOWN = {'높음': '중간', '중간': '낮음', '낮음': '낮음'}
SEV_RANK = {'낮음': 1, '중간': 2, '높음': 3}
ALLOW_RE = re.compile(r'qcheck-allow\s*[:=]?\s*(?:[A-Za-z0-9_-]+\s*,\s*)*R15\b')
HASH_NOTE = ' — 값이 세션 해시 키(sha256(토큰) 류)라 한 단계 낮춤'
MAX_SOURCES = 8


def lower(sev):
    return SEV_DOWN.get(sev, sev)


def refs_all_hashkey(refs):
    """PHP·파이썬 보고용 이름들이 전부 세션 해시 키 이름인가($sessionId · session_id · ['session_id'])."""
    if not refs:
        return False
    for r in refs:
        if r.startswith('Cookie 값'):
            return False
        nm = re.sub(r'^\$|\(\)$', '', r)
        nm = re.sub(r"^\[['\"]?|['\"]?\]$", '', nm)
        nm = re.split(r'[.…]', nm)[-1]
        if not is_hashkey_name(nm):
            return False
    return True


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
            elif dbr and raw == ']]':
                dbr = False
            if not cmd.words and cmd.kind == 'simple' and ASSIGN_RE.match(raw):
                # 명령 앞 VAR=값 은 몇 개든(줄 이어짐 `\` 로 나뉘어도) 환경 대입이다 — 첫 대입 아닌 단어가 명령
                # (FP-0001: 두 번째 대입부터 명령 이름·인자로 잘못 나누던 것)
                cmd.assigns.append(w)
                continue
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


_HASHED_WRITE_RE = re.compile(r'\b(?:sha(?:1|224|256|384|512)sum|md5sum|b2sum|shasum|openssl\s+dgst)\b[^\n;&]*?>\s*'
                              r'("[^"\n]+"|\S+)')


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
        self.loop_vals = {}        # for 반복 변수 → [값 조각들] (id 출처 판정용)
        # 해시기(sha256sum …) 출력을 받아 쓴 파일의 이름 끝(`… | sha256sum … > "$W/$t.sid"` → .sid) — 그 파일을 읽은
        # 값은 토큰이 아니라 해시 키다(v3 ② — 심각도 한 단계 낮춤)
        self.hashed_suffixes = set()
        for m in _HASHED_WRITE_RE.finditer(text):
            tail = re.split(r'\$\{?[A-Za-z_0-9@*#?]+\}?', m.group(1).strip('"\''))[-1]
            if len(tail) >= 2 and '.' in tail and '/' not in tail:
                self.hashed_suffixes.add(tail)
        for cmd in self.cmds:
            if cmd.kind == 'header' and len(cmd.words) >= 3 and cmd.words[0].lit() in ('for', 'select') and \
                    cmd.words[2].lit() == 'in' and re.fullmatch(r'[A-Za-z_][A-Za-z0-9_]*', cmd.words[1].raw):
                # for 이름 in 단어… — 반복 변수의 값 출처. id 출처 판정(id_only)에만 쓴다(비밀 흐름에는 넣지 않음)
                for w in cmd.words[3:]:
                    self.loop_vals.setdefault(cmd.words[1].raw, []).append(list(w.parts))
                continue
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

    def id_only(self, name, _stack=()):
        """대입되는 확장 값이 전부 id 칸 읽기인가(SID=$(… SELECT id FROM …) — 식별자). 그런 변수만 모아 둔
        변수(IDS="$IDS $SID")와 그 반복 변수(for sid in $IDS)도 같다."""
        if name in _stack:
            return None     # 자기 자신을 잇는 대입(IDS="$IDS …")은 판단에 넣지 않는다
        seen = False
        for parts in self.assign_vals.get(name, []) + self.loop_vals.get(name, []):
            dyn = [p for p in parts if p[0] not in ('lit', 'arith', 'len')]
            if not dyn:
                continue
            for p in dyn:
                if p[0] == 'sub' and text_reads_id_only(p[1].text):
                    seen = True
                    continue
                if p[0] == 'var':
                    r = self.id_only(p[1], _stack + (name,))
                    if r is None:
                        continue
                    if r:
                        seen = True
                        continue
                return False
        return seen

    def path_only(self, name):
        """대입되는 값이 전부 경로 모양인가(`TOTP="$CONF/qa-totp.txt"` — 비밀 파일의 **자리**, 값이 아니다).
        변수·글자만으로 된 값에서 글자에 `/` 가 있고 끝이 경로 조각이며 `=`·`:`·`?`·빈칸이 없을 때(v3 ⑤ 바깥 입력)."""
        vals = self.assign_vals.get(name, [])
        if not vals:
            return False
        for parts in vals:
            if any(p[0] not in ('lit', 'var') for p in parts):
                return False
            lit = ''.join(p[1] for p in parts if p[0] == 'lit')
            if '/' not in lit or re.search(r'[=:?\s&;|<>]', lit):
                return False
            if parts[-1][0] != 'lit' or not re.search(r'/[\w.@+-]+$', parts[-1][1]):
                return False
        return True

    def cookie_pair_var(self, name):
        """변수에 `이름=$값` 모양(쿠키 한 쌍)이 대입되나 — `curl -b "$변수"` 가 쿠키 글로 쓰는 경우."""
        for parts in self.assign_vals.get(name, []):
            if cookie_dyn_parts(parts, pair=True):
                return True
        return False


def cookie_dyn_parts(parts, pair=False, hdr=True):
    """단어 조각에서 쿠키 값 자리의 확장 조각들. hdr: `Cookie:` 머리 글자 뒤 · pair: `이름=` 글자 뒤."""
    out, open_ = [], False
    for p in parts:
        if p[0] == 'lit':
            if (hdr and _COOKIE_HDR_RE.search(p[1])) or (pair and '=' in p[1]):
                open_ = True
            continue
        if open_ and p[0] in ('var', 'pos', 'sub'):
            out.append(p)
    return out


class ShellAnalyzer(object):
    def __init__(self, files, globals_num):
        self.files = files                  # rel → ShellFile (주 파일들)
        self.globals_num = globals_num      # 어딘가에서 숫자로 대입되는 이름
        self.gfuncs = {}                    # 함수 이름 → [(ShellFile)]
        for sf in files.values():
            for f in sf.funcs:
                self.gfuncs.setdefault(f, []).append(sf)
        self.findings = {}                  # (rel, line) → Finding
        self.hash_refs = set()              # (파일, 보고용 이름) — 세션 해시 키로 판정된 참조(v3 ②)
        self.hashvars = {}                  # 파일 → 해시 키 값만 받는 변수들

    # ── 값 판정 ──
    def sub_hash(self, sf, sub):
        """명령 치환의 출력이 세션 해시 키인가 — 이름(sid · session_id) · 해시기 출력을 쓴 파일(.sid) 읽기 ·
        세션 표의 id 칸 읽기(v3 ②)."""
        for c in sub.cmds:
            ws = effective_words(c.words)
            if ws:
                n0 = os.path.basename(ws[0].lit() or '')
                if n0 and is_secret_name(n0) and is_hashkey_name(n0):
                    return True
            for w in list(c.words) + [x for _, x in c.redirs]:
                txt = w.raw.rstrip('"\'')
                if any(txt.endswith(suf) for suf in sf.hashed_suffixes):
                    return True
        for m in _SESSION_TABLE_ID_RE.finditer(sub.text):
            if any(t in ('session', 'sessions', 'sess') for t in name_tokens(m.group(1))):
                return True
        for r in _ID_READ_RES:
            for m in r.finditer(sub.text):
                if _id_col_is_session_key(m.group('c')):
                    return True
        return False

    def compute_hashvars(self, sf, tainted):
        hv = set()
        changed = True
        while changed:
            changed = False
            for name in sorted(set(tainted) | set(sf.assign_vals)):
                if name in hv:
                    continue
                if is_hashkey_name(name) and (name in tainted or is_secret_name(name)):
                    hv.add(name)
                    changed = True
                    continue
                if name not in tainted:
                    continue
                ok = None
                for parts in sf.assign_vals.get(name, []):
                    for p in parts:
                        if p[0] == 'var' and p[1] != name and self.var_secret(sf, p[1], tainted):
                            ok = (ok is not False) and (is_hashkey_name(p[1]) or p[1] in hv)
                        elif p[0] == 'sub' and self.sub_secret(sf, p[1], tainted):
                            ok = (ok is not False) and self.sub_hash(sf, p[1])
                if ok:
                    hv.add(name)
                    changed = True
        return hv

    def is_hash_ref(self, sf, ref):
        return (sf.rel, ref) in self.hash_refs
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
        if sf.id_only(name):
            return False            # 값이 id 칸 읽기뿐(공유 id) — 식별자
        if sf.path_only(name):
            return False            # 값이 전부 경로 — 비밀 파일의 자리(…_FILE 과 같은 뜻)
        return True

    def dyn_label(self, sf, p, tainted):
        """쿠키 모양 자리의 확장 조각 → 보고용 이름(글자만 대입되는 변수 = 가짜 값이면 None)."""
        if p[0] == 'var':
            kinds = sf.assign_kinds.get(p[1], set())
            if p[1] not in tainted and kinds and 'dyn' not in kinds:
                return None
            return '$' + p[1]
        if p[0] == 'pos':
            return '$' + str(p[1])
        if p[0] == 'sub':
            first = p[1].text.strip().split()
            return '$(%s …)' % (first[0] if first else '')
        return None

    def cookie_refs(self, sf, parts, tainted, pair=False):
        labels = [self.dyn_label(sf, p, tainted) for p in cookie_dyn_parts(parts, pair=pair)]
        return ['Cookie 값 %s' % x for x in labels if x]

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
        if numeric_extract(sub):
            return False    # sed·grep 로 숫자(+단위)만 뽑는 치환 — 설정 값이다(v3 ④ FP-0006)
        for n0 in names:
            if is_secret_name(n0) and any(t in STRONG and t != 'totp' for t in name_tokens(n0)):
                return True     # qa_secret · get_token (totp_* 만 있는 이름은 코드를 계산하는 쪽이라 뺀다)
        reads = any(n in READERS for n in names)
        for c, n0, ws in eff:
            for w in ws[1:] + [x for op, x in c.redirs if op == '<']:
                for p in w.parts:
                    if reads and n0 in READERS and ((p[0] == 'var' and jar_var(p[1])) or
                                                    (p[0] == 'lit' and lit_jar_path(p[1]))):
                        return True     # 쿠키 jar 에서 값을 뽑음(묶음 X ④-c)
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
                    if is_hashkey_name(p[1]) or p[1] in self.hashvars.get(sf.rel, ()):
                        self.hash_refs.add((sf.rel, refs[-1]))
            elif p[0] == 'pos':
                poss.append(p[1])
            elif p[0] == 'sub':
                if self.sub_secret(sf, p[1], tainted):
                    first = p[1].text.strip().split()
                    refs.append('$(%s …)' % (first[0] if first else ''))
                    if self.sub_hash(sf, p[1]):
                        self.hash_refs.add((sf.rel, refs[-1]))
        # `Cookie:` 머리 글자 뒤의 확장은 이름과 상관없이 쿠키 값(묶음 X ④-b)
        for x in self.cookie_refs(sf, w.parts, tainted):
            if not any(x.endswith(' ' + r) for r in refs):
                refs.append(x)
        return refs, poss

    def arg_refs(self, sf, args, tainted, func):
        """명령 인자 단어마다 (단어, 비밀 참조, 위치 인자). `-b`·`--cookie` 뒤 단어는 쿠키 쌍 모양도 본다."""
        out = []
        after_b = False
        for w in args:
            refs, poss = self.word_refs(sf, w, tainted, func)
            lit = w.lit()
            parts = w.parts
            opt = False
            if after_b:
                opt = True
            elif lit is None and parts and parts[0][0] == 'lit':
                s0 = parts[0][1]
                m = re.match(r'--cookie=|-b(?!-)', s0)
                if m:
                    opt = True       # --cookie=이름=$값 · -b이름=$값 · -b"이름=$값"(붙여 씀)
                    parts = [('lit', s0[m.end():])] + list(parts[1:])
            if opt:
                extra = self.cookie_refs(sf, parts, tainted, pair=True)
                if not extra and len(parts) == 1 and parts[0][0] == 'var' and \
                        sf.cookie_pair_var(parts[0][1]):
                    extra = ['Cookie 값 $' + parts[0][1]]     # -b "$CK" (CK="이름=$값")
                for x in extra:
                    if x not in refs and not any(x.endswith(' ' + r) for r in refs):
                        refs.append(x)
            after_b = lit in ('-b', '--cookie')
            out.append((w, refs, poss))
        return out

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
        if old is None or SEV_RANK.get(sev, 0) > SEV_RANK.get(old.sev, 0):
            self.findings[key] = Finding(sev, rel, line, msg)

    def run(self):
        # 1) 함수별: 위치 인자가 외부 명령 인자로 가는 곳(싱크)과 다른 함수로 넘기는 곳(간선)
        sinks = {}    # (파일, 함수, 번호) → [(줄, 심각도, 명령)]
        edges = {}    # (파일, 함수, 번호) → [(파일, 함수, 번호)]
        taints = {rel: self.taint(sf) for rel, sf in self.files.items()}
        for rel, sf in self.files.items():
            self.hashvars[rel] = self.compute_hashvars(sf, taints[rel])
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
                    ar = self.arg_refs(sf, args, tainted, cmd.func)
                    for w, refs, poss in ar:
                        names.extend(refs)
                        if cmd.func:
                            for k in poss:
                                sinks.setdefault((rel, cmd.func, k), []).append((w.line, sev, cname))
                    if kind == 'wrap' and cmd.func:
                        for k in list(range(1, 10)) + ['@']:
                            sinks.setdefault((rel, cmd.func, k), []).append((cmd.line, sev, '"$@"'))
                    if names:
                        line = min(w.line for w, refs, _ in ar if refs)
                        hk = all(self.is_hash_ref(sf, n) for n in names)
                        self.add(lower(sev) if hk else sev, rel, line,
                                 '비밀로 보이는 값(%s)이 %s 명령 인자로 들어감%s%s — 표준입력·파일·'
                                 '환경 변수로 넘길 것' % (', '.join(dict.fromkeys(names)), os.path.basename(cname),
                                                   ' (sudo 기록에 남음)' if logged else '', HASH_NOTE if hk else ''))
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

        # 3) 호출하는 쪽에서 비밀을 넘기는 곳 — 한 싱크에 닿는 출처를 모두 모아 한 줄에 적는다(v3 ①)
        agg = {}      # (싱크 파일, 줄) → {'sev', 'cname', 'src': [(함수, 번호, 파일, 줄, 이름들, 해시 키?)]}
        for sf, cmd, ff, fname, args in calls:
            tainted = taints[sf.rel]
            for idx, (w, refs, _) in enumerate(self.arg_refs(sf, args, tainted, cmd.func), 1):
                if not refs:
                    continue
                hk = all(self.is_hash_ref(sf, r) for r in refs)
                for (srel, sline, sev, scname) in reach((ff.rel, fname, idx), frozenset()):
                    g = agg.setdefault((srel, sline), {'sev': sev, 'cname': scname, 'src': []})
                    if sev == '높음':
                        g['sev'] = '높음'
                    src = (fname, idx, sf.rel, w.line, tuple(dict.fromkeys(refs)), hk)
                    if src not in g['src']:
                        g['src'].append(src)
        for (srel, sline), g in sorted(agg.items()):
            srcs = sorted(g['src'], key=lambda x: (x[2], x[3], x[1]))
            hk = all(x[5] for x in srcs)
            groups = {}      # (함수, 번호, 파일, 이름들) → [줄] — 같은 이름을 넘기는 줄은 한 묶음으로
            for fname, idx, rel, line, refs, _ in srcs:
                groups.setdefault((fname, idx, rel, refs), []).append(line)
            shown, n = [], 0
            for (fname, idx, rel, refs), lines in groups.items():
                if n >= MAX_SOURCES:
                    break
                ls = lines[:MAX_SOURCES - n]
                n += len(ls)
                shown.append('함수 %s 의 $%d ← %s:%s 의 %s' % (fname, idx, rel, '·'.join(str(x) for x in ls),
                                                        ', '.join(refs)))
            more = '' if len(srcs) <= n else ' 외 %d곳' % (len(srcs) - n)
            self.add(lower(g['sev']) if hk else g['sev'], srel, sline,
                     '비밀로 보이는 값(%s%s)이 %s 명령 인자로 들어감%s%s — 표준입력·파일·환경 변수로 넘길 것'
                     % (' · '.join(shown), more, os.path.basename(g['cname']),
                        ' (sudo 기록에 남음)' if g['sev'] == '높음' else '', HASH_NOTE if hk else ''))
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
            if k in ('sq', 'dq') and i + 2 < len(toks) and toks[i + 1][1] == '.' and \
                    toks[i + 2][0] in ('var', 'id'):
                body = v[1:-1] if v[:1] in ('"', "'") else v
                if _COOKIE_HDR_OPEN_RE.search(body) or _COOKIE_OPT_OPEN_RE.search(body):
                    refs.append('Cookie 값 ' + toks[i + 2][1])     # 'Cookie: sid=' . $x (묶음 X ④-b)
            if k == 'var':
                nm = v[1:]
                nxt = toks[i + 1] if i + 1 < len(toks) else None
                acc = ''
                if nxt and nxt[0] == 'op' and nxt[1] in ('->', '?->') and i + 2 < len(toks) and toks[i + 2][0] == 'id':
                    acc = '->' + toks[i + 2][1]
                elif nxt and nxt[1] == '[' and i + 2 < len(toks) and toks[i + 2][0] in ('sq', 'dq'):
                    acc = '[' + toks[i + 2][1] + ']'
                if nm in tainted or (nm not in ('this', '_SESSION') and is_secret_name(nm)):
                    refs.append('$' + nm)     # $_SESSION 은 서버 쪽 저장소(쿠키 값 아님) — 칸 이름으로만 본다
                else:
                    a = php_accessor_secret(acc)
                    if a:
                        refs.append('$%s…%s' % (nm, a))
            elif k in ('dq', 'bt'):
                body = v[1:-1] if k != 'dq' or v.startswith('"') else v
                for nm, acc in php_str_refs(body):
                    if nm in tainted or (nm != '_SESSION' and is_secret_name(nm)):
                        refs.append('$' + nm)
                    else:
                        a = php_accessor_secret(acc)
                        if a:
                            refs.append('$%s…%s' % (nm, a))
                for m in _COOKIE_INTERP_RE.finditer(body):
                    if '$' + m.group(1) not in refs:
                        refs.append('Cookie 값 $' + m.group(1))
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
                    hk = refs_all_hashkey(refs)
                    sev = '높음' if re.search(r'\bsudo\b', v) else '중간'
                    out.append(Finding(lower(sev) if hk else sev, self.rel, ln + self.line0 - 1,
                                       '비밀로 보이는 값(%s)이 백틱 셸 명령 인자로 들어감%s — 표준입력·파일·환경 변수로 넘길 것'
                                       % (', '.join(dict.fromkeys(refs)), HASH_NOTE if hk else '')))
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
                hk = refs_all_hashkey(refs)
                sev = '높음' if logged else '중간'
                out.append(Finding(lower(sev) if hk else sev, self.rel, ln + self.line0 - 1,
                                   '비밀로 보이는 값(%s)이 %s() 명령 인자로 들어감%s%s — 표준입력(proc_open 파이프)·파일·'
                                   '환경 변수로 넘길 것' % (', '.join(dict.fromkeys(refs)), v.lstrip('\\'),
                                                     ' (sudo 기록에 남음)' if logged else '', HASH_NOTE if hk else '')))
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


def _py_str(node):
    return node.value if isinstance(node, ast.Constant) and isinstance(node.value, str) else None


def _py_dyn_name(node):
    if _py_str(node) is not None:
        return None
    return _py_dotted(node) or node.__class__.__name__


def py_cookie_shape(n):
    """파이썬 식이 쿠키 값을 끼워 만드는 모양인가 — 'Cookie: sid=' + x · f'Cookie: {x}' · 'Cookie: %s' % x ·
    'Cookie: {}'.format(x) · ['-b', 'sid=' + x] (묶음 X ④-b). 보고용 이름이나 None."""
    if isinstance(n, ast.JoinedStr):
        opened = None
        for v in n.values:
            s = _py_str(v)
            if s is not None:
                if _COOKIE_HDR_RE.search(s):
                    opened = True
            elif opened and isinstance(v, ast.FormattedValue):
                return 'Cookie 값 {%s}' % _py_dyn_name(v.value)
    if isinstance(n, ast.BinOp):
        s = _py_str(n.left)
        if s is not None and isinstance(n.op, ast.Add) and _py_dyn_name(n.right) and \
                (_COOKIE_HDR_OPEN_RE.search(s) or _COOKIE_OPT_OPEN_RE.search(s)):
            return 'Cookie 값 ' + _py_dyn_name(n.right)
        if s is not None and isinstance(n.op, ast.Mod) and re.search(r'(?i)(?<![A-Za-z0-9_-])cookie[ \t]*:[^\n]*%', s):
            return 'Cookie 값 ' + (_py_dyn_name(n.right) or '')
    if isinstance(n, ast.Call) and isinstance(n.func, ast.Attribute) and n.func.attr == 'format':
        s = _py_str(n.func.value)
        if s is not None and re.search(r'(?i)(?<![A-Za-z0-9_-])cookie[ \t]*:[^\n]*\{', s) and (n.args or n.keywords):
            return 'Cookie 값 .format()'
    if isinstance(n, (ast.List, ast.Tuple)):
        for a, b in zip(n.elts, n.elts[1:]):
            if _py_str(a) in ('-b', '--cookie') and _py_str(b) is None:
                if isinstance(b, ast.BinOp) and '=' in (_py_str(b.left) or ''):
                    return 'Cookie 값 ' + (_py_dyn_name(b.right) or '')
                if isinstance(b, ast.JoinedStr) and any('=' in (_py_str(v) or '') for v in b.values):
                    return 'Cookie 값 f-글'
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
            shape = py_cookie_shape(n)
            if shape:
                out.append(shape)
            if isinstance(n, ast.Call):
                fname = _py_dotted(n.func) or ''
                last0 = fname.split('.')[-1]
                last = last0.lower()
                if last in NONREVEAL or fname.split('.')[0] in ('hashlib', 'hmac'):
                    return
                if last0[:1].isupper() and secret_index(name_tokens(last0)) >= 0 and \
                        name_tokens(last0)[secret_index(name_tokens(last0))] in COOKIE_TOK:
                    return      # Session() · SimpleCookie() — 세션·쿠키 그릇을 만드는 것(값이 아님)
                if last and is_secret_name(last0):
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
                hk = refs_all_hashkey(refs)
                sev = '높음' if logged else '중간'
                out.append(Finding(lower(sev) if hk else sev, self.rel, node.lineno + self.line0 - 1,
                                   '비밀로 보이는 값(%s)이 %s 명령 인자로 들어감%s%s — input=·파일·env= 로 넘길 것'
                                   % (', '.join(dict.fromkeys(refs)), full, ' (sudo 기록에 남음)' if logged else '',
                                      HASH_NOTE if hk else '')))
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


def repo_top(root):
    try:
        r = subprocess.run(['git', '--no-optional-locks', '-C', root, 'rev-parse', '--show-toplevel'], stdout=subprocess.PIPE,
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
    ap.add_argument('--outer', default=None,
                    help='바깥 배치(qaredi) — 그 아래 scripts/ 의 셸·PHP·파이썬도 본다(경로는 ../scripts/…). 없거나 none 이면 끔')
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

    outer = None
    if args.outer and args.outer.lower() != 'none':
        outer = os.path.realpath(args.outer)
        if not os.path.isdir(outer):
            sys.stderr.write('R15: 바깥 배치가 디렉터리가 아님: %s\n' % args.outer)
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
    # 바깥 저장소 반영 스크립트(v3 ⑤) — 뿌리 안 파일과 함수 이름이 섞이지 않게 따로 분석한다
    outer_rels = set()
    if outer is not None:
        od = os.path.join(outer, 'scripts')
        if os.path.isdir(od) and os.path.realpath(od) != os.path.realpath(os.path.join(root, 'scripts')):
            for d, dd, files in os.walk(od):
                dd[:] = sorted(x for x in dd if x != '.git')
                for f in sorted(files):
                    full = os.path.join(d, f)
                    if os.path.islink(full) or not os.path.isfile(full):
                        continue
                    orel = '../' + os.path.relpath(full, outer).replace(os.sep, '/')
                    try:
                        with open(full, 'rb') as fh:
                            text = fh.read().decode('utf-8', 'replace').lstrip(chr(0xFEFF))
                    except OSError as e:
                        sys.stderr.write('R15: 읽지 못함: %s (%s)\n' % (orel, e.strerror))
                        skipped += 1
                        continue
                    lang = file_lang(orel, text[:200])
                    if lang:
                        targets.append((orel, lang, text))
                        outer_rels.add(orel)
    texts = {rel: text for rel, _, text in targets}

    findings = []
    sh_files = {}
    outer_sh = {}
    globals_num = set()
    parsed = 0
    for rel, lang, text in targets:
        try:
            if lang == 'sh':
                sf = ShellFile(rel, text)
                (outer_sh if rel in outer_rels else sh_files)[rel] = sf
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
    for rel, sf in list(sh_files.items()) + list(outer_sh.items()):
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
    if outer_sh:
        findings.extend(ShellAnalyzer(outer_sh, globals_num).run())
    for key, emb in embedded_sh.items():
        ea = ShellAnalyzer({key: emb}, globals_num)
        for f in ea.run():
            f.path = emb.rel
            findings.append(f)

    uniq = {}
    for f in findings:
        k = (f.path, f.line)
        if k not in uniq or SEV_RANK.get(f.sev, 0) > SEV_RANK.get(uniq[k].sev, 0):
            uniq[k] = f
    kept = []
    for f in sorted(uniq.values(), key=lambda f: (f.path, f.line)):
        lines = texts.get(f.path, '').split('\n')
        if 0 < f.line <= len(lines) and ALLOW_RE.search(lines[f.line - 1]):
            skipped += 1     # 줄 표식 `qcheck-allow: R15` — 받아들인 위험
            continue
        kept.append(f)
    findings = kept
    for f in findings:
        print('FINDING %s %s %s:%d %s' % (RULE, f.sev, f.path, f.line, f.msg))
    bad = len(set(f.path for f in findings))
    print('RESULT pass=%d fail=%d skip=%d' % (max(0, parsed - bad), len(findings), skipped))
    return 1 if findings else 0


if __name__ == '__main__':
    sys.exit(main())
