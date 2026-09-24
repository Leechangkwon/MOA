#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
R27 nginx 설정 (deploy/sites/*.conf · php-fpm 풀) — 점검 봇 v3 정적 규칙(봇 입력 넓히기 ①), python3 stdlib 만, 파일은 읽기만.

answers: HO-P7-003(N8 — php-fpm × nginx 시간 짝) · train-backlog A 표 N1~N8 — 라운드 C(순변화가 전부 .conf 인데 봇 입력
         밖) · U-4′ 설계 문장 「add_header 를 넣지 않는다 → 상속 그대로」(N1) · 1l(시험 파서 exit 3 이 될 뻔함, N2) ·
         라운드 C 검토 「JSON 제외 = BREACH 대책」(N3) · U-4 원안 검토(정규식 alias 경로 탈출, N4) ·
         관문 ② 실측 주석(상대 리다이렉트, N5) · 1l 로컬 conf 대조(N6) · R-06·R-48 주석(끝 슬래시 속도 제한, N7)

무엇을 부류로 잡나:
  nginx 설정은 「조용히 틀리는」 곳이 많다 — 문법이 맞아 `nginx -t` 는 통과하는데 상속·기본값·짝 때문에 보안 헤더가
  빠지거나, 속도 제한이 슬래시 하나로 우회되거나, 캐시 값이 요청마다 오류가 된다. 이 규칙은 설정 파일을 nginx 와 같은
  낱말 규칙(ngx_conf_read_token — 주석·따옴표·역슬래시·블록, tests/upgrade/asset_cache.py 의 tokens/directives/walk 를
  줄 번호를 달아 복사 · import 하지 않음)으로 풀고, 단(http → server → location → if)마다 **상속**을 따라가며 아래 부류를
  본다. 복사본과 다른 점 하나: 낱말 안의 `${이름}`(`$` 바로 뒤 `{`)과 낱말 가운데 `}` 는 nginx 처럼 낱말의 일부로 읽는다
  (asset_cache.py 는 끊는다 — 저장소의 설정 파일에서는 두 풀이의 결과가 같다).
  특정 파일·경로 이름이 아니라 지시어의 뜻으로 판정한다.
    N1 add_header 상속 끊김 (높음) — location·if 안에 add_header 가 **하나라도** 있으면 위 단의 add_header 를 **모두**
       물려받지 않는다. 위 단(가장 가까운 add_header 가 있는 단)의 보안 헤더(CSP·X-Content-Type-Options·X-Frame-Options·
       Referrer-Policy·HSTS·Permissions-Policy·Cross-Origin-*)가 그 블록에 전부 다시 적혀 있어야 한다(값은 달라도 된다 —
       일부러 넓히거나 좁힌 것). 위 단에서 `always` 였던 헤더를 `always` 없이 다시 적으면 4xx·5xx 에 빠진다(낮음).
       블록이 응답을 뒤로 넘기면(fastcgi_pass·proxy_pass 류) 빠진 헤더는 뒤(앱)가 붙일 수 있어 판정하지 않는다(skip) —
       다만 그 헤더를 `*_hide_header` 로 숨기면 붙일 곳이 없으므로 울린다.
    N2 변수 × 정의 (중간) — 지시어 값의 `$x` 는 nginx 내장 변수(고정 사전·접두)이거나, 그 사이트가 읽히는 http 단
       (server 없는 공통 파일 = conf.d · 그 사이트 파일 맨 위)의 map·geo·split_clients 또는 set·정규식 이름 캡처로
       정의돼 있어야 한다(없으면 unknown variable). http 단 정의는 **꼭 하나**(둘이면 뒤의 것이 덮는다).
       `expires $x` 의 map 값은 expires 가 읽는 모양(epoch·max·off·@시각·[+-]시간)이어야 하고 default 가 있어야 한다
       (어긋나면 그 요청은 오류 응답 — nginx -t 로는 안 보인다).
       (선택 --parser) 저장소 시험 셸이 `python3 …/<파서>.py <동사> …/deploy/sites/<파일>` 로 설정을 읽으면 그 파서를
       같은 인자로 돌려 rc 0 인지 본다(대상 트리 코드를 실행하므로 기본 끔).
    N3 gzip × BREACH (중간) — `gzip_types` 에 창구 JSON 형식(application/json·text/json·`*`·application/*+json —
       정적 파일 형식 manifest+json·geo+json 은 뺌)이 **들어갔는가**. JSON 응답의 CSRF·세션 값이 압축 길이로 샌다.
       같은 블록에 `gzip off` 면 조용. (정적 자산 형식이 빠졌는가는 보지 않는다.)
    N4 alias 경로 탈출 (높음) — ① 정규식 location 의 alias 가 캡처($1·$이름)를 잇는데 그 캡처 묶음이 `..` 를 막지
       않는 모양(`(.*)`·`(.+)`·`([^/]*)`…) ② 접두 location 의 주소는 `/` 로 끝나지 않는데 alias 는 `/` 로 끝남(`/x../`).
    N5 상대 리다이렉트 (중간) — `return 30x /…`(또는 `$request_uri`·`$uri` 로 시작) · `rewrite … redirect|permanent`
       의 상대 대상이 있는 블록에 (상속 포함) `absolute_redirect off` 가 없다 — TLS 프록시 뒤 평문 server 는 `http://`
       절대 주소를 낸다. server 의 listen 이 전부 ssl 이면 보지 않는다.
    N6 공개용·로컬용 어긋남 (낮음) — `<이름>.conf` 와 `<이름>-local.conf` 의 같은 자리(server 차례 · location 머리)에서
       보안·캐시·속도 제한 지시어(add_header·expires·limit_req·limit_req_status·client_max_body_size·absolute_redirect·
       internal·allow·deny·auth_basic·limit_rate·limit_conn)가 다르거나, 그런 지시어를 가진 location 이 한쪽에만 있다.
       HSTS 는 한쪽 server 가 루프백만 듣는 평문이면 비교하지 않는다(평문 localhost 에 HSTS 는 뜻이 없거나 해롭다).
       의도한 차이는 그 줄(또는 바로 위 주석 줄)에 `qcheck-allow: R27`.
    N7 속도 제한 짝 (중간) — `limit_req zone=z`·`limit_conn z` 의 z 가 http 단 `limit_req_zone`·`limit_conn_zone` 에
       정의돼 있는가 · limit_req 가 붙은 정확 일치 블록 `location = /p`(비밀을 받는 창구 — 코드에서 판정)마다 같은 zone 의
       끝 슬래시 변형 `location = /p/` 가 있는가(없으면 슬래시 하나로 제한 우회 — 앱이 끝 `/` 를 떼고 매칭).
    N8 php-fpm × nginx 시간 짝 (낮음) — deploy/ 아래 php-fpm 풀 파일(`[풀]` + `listen =`)의 `request_terminate_timeout`
       (0·없음이면 짝이 없어 보지 않음)과, 그 listen 으로 `fastcgi_pass` 하는 location 의 `fastcgi_read_timeout`(상속,
       기본 60s)을 견준다. 풀 쪽이 길면 nginx 가 504 로 끊은 뒤에도 워커를 쥐고, 짧으면 nginx 보다 먼저 요청을 죽인다(502).
    N0 (`nginx -t` 사본 검사) 는 하지 않는다 — nginx 1.18 은 설정을 읽기 전에 컴파일된 기본 오류 기록(/var/log/nginx)을
       열고 /etc/nginx 의 fastcgi_params·mime.types 를 읽어야 해서 「운영 nginx·/etc 접촉 0」을 지킬 수 없다.

  include 는 deploy/sites 안에 같은 이름의 파일이 있을 때만 펼친다(글롭 include·fastcgi_params 같은 배포판 조각은 펼치지
  않는다). 설치 위치 conf.d/<이름>.conf 는 deploy/sites/<이름>.conf 로 본다 — server 가 없고 아무도 include 하지 않는
  파일은 모든 사이트의 http 단(conf.d)으로 친다.

대상: --root 아래 deploy/sites/*.conf 와 deploy/**/*.conf 중 php-fpm 풀 모양 파일 · --outer 가 있으면 <outer>/deploy/sites 도
      (없으면 바깥은 보지 않음). --range·--directive 는 실행 틀과 모양을 맞추려고 받기만 한다.
출력: FINDING R27 <심각도> <파일:줄> N<번호> <설명> · 끝 줄 RESULT pass=<읽은 설정 파일 - 발견 파일> fail=<발견 수>
      skip=<못 읽은 파일 + 판정 못 한 곳(뒤로 넘기는 블록의 헤더 · 모르는 대상의 리다이렉트 · 읽지 못한 정규식)>
      비밀 값은 적지 않는다(설정 값·헤더 이름만).
rc:   0 발견 없음 · 1 발견 있음 · 2 실행 오류(뿌리·deploy/sites 없음 · 설정 파일을 하나도 못 읽음 = 검사 못 함)
한계: 파일 하나를 사이트 하나로 보고 sites-enabled 조합(어느 파일이 함께 켜지나)은 모른다. nginx.conf(배포판 파일)는 저장소에
      없어 http 단 기본값(gzip on 등)은 짐작한다. 정규식은 Python re 로 읽는다(PCRE 와 다른 모양은 skip). `if` 안 지시어의
      실행 순서(rewrite 단계)는 따지지 않는다. N8 은 php.ini max_execution_time 과 앱 쪽 제한은 보지 않는다.
"""
import argparse
import fnmatch
import os
import re
import subprocess
import sys

RULE = 'R27'
ALLOW_RE = re.compile(r'qcheck-allow\s*[:=]?\s*(?:[A-Za-z0-9_-]+\s*,\s*)*R27\b')


def note(msg):
    sys.stderr.write('R27: %s\n' % msg)


# ─── 낱말·블록 풀이 (asset_cache.py tokens/directives 복사 + 줄 번호) ─────────────────────────────
class ConfError(Exception):
    pass


def unescape(raw):
    out, i = [], 0
    while i < len(raw):
        c = raw[i]
        if c == '\\' and i + 1 < len(raw):
            n = raw[i + 1]
            if n in '"\'\\':
                out.append(n); i += 2; continue
            if n in 'trn':
                out.append({'t': '\t', 'r': '\r', 'n': '\n'}[n]); i += 2; continue
        out.append(c); i += 1
    return ''.join(out)


def tokenize(s):
    """(낱말, 따옴표 여부, 줄) 목록과 {줄: 주석} — `#` 은 낱말 첫머리에서만 주석, 따옴표로 묶으면 한 낱말"""
    out, comments = [], {}
    i, n, line = 0, len(s), 1
    while i < n:
        c = s[i]
        if c == '\n':
            line += 1; i += 1
        elif c.isspace():
            i += 1
        elif c == '#':
            j = s.find('\n', i)
            j = n if j < 0 else j
            comments[line] = s[i:j]
            i = j
        elif c in '{};':
            out.append((c, False, line)); i += 1
        elif c in '"\'':
            j = i + 1
            while j < n and s[j] != c:
                j += 2 if s[j] == '\\' else 1
            if j >= n:
                raise ConfError('닫히지 않은 따옴표(%d행)' % line)
            raw = s[i + 1:j]
            out.append((unescape(raw), True, line))
            line += raw.count('\n')
            i = j + 1
        else:
            # 낱말 안에서는 빈칸·`;`·`{` 만 끊는다. `$` 바로 뒤 `{` 는 `${이름}` 의 일부이고, `}` 는 낱말 첫머리에서만
            # 블록 끝이다(ngx_conf_read_token 의 variable 깃발 — asset_cache.py 는 이 둘을 끊는 쪽으로 읽는다)
            j, variable = i, False
            while j < n:
                ch = s[j]
                if ch == '{' and variable:
                    variable = False
                    j += 1
                    continue
                variable = False
                if ch == '\\':
                    j += 2
                    continue
                if ch.isspace() or ch in ';{':
                    break
                if ch == '$':
                    variable = True
                j += 1
            raw = s[i:j]
            out.append((unescape(raw), False, line))
            line += raw.count('\n')
            i = j
    return out, comments


class Node(object):
    __slots__ = ('name', 'args', 'line', 'file', 'block')

    def __init__(self, name, args, line, file, block=None):
        self.name, self.args, self.line, self.file, self.block = name, args, line, file, block

    def head(self):
        return ' '.join([self.name] + self.args)


def parse_tokens(toks, rel):
    pos = [0]

    def block(depth):
        nodes, words = [], []
        while pos[0] < len(toks):
            t, q, ln = toks[pos[0]]
            if q or t not in (';', '{', '}'):
                words.append((t, ln)); pos[0] += 1
                continue
            pos[0] += 1
            if t == ';':
                if words:
                    nodes.append(Node(words[0][0], [w for w, _ in words[1:]], words[0][1], rel))
                words = []
            elif t == '{':
                head, words = words, []
                sub = block(depth + 1)
                nodes.append(Node(head[0][0] if head else '', [w for w, _ in head[1:]],
                                  head[0][1] if head else ln, rel, sub))
            else:
                if words:
                    raise ConfError('끝나지 않은 지시어 %s(%d행)' % (words[0][0], words[0][1]))
                if depth == 0:
                    raise ConfError('짝이 없는 }(%d행)' % ln)
                return nodes
        if words:
            raise ConfError('끝나지 않은 지시어 %s(%d행)' % (words[0][0], words[0][1]))
        if depth:
            raise ConfError('닫히지 않은 블록')
        return nodes
    return block(0)


def walk_all(nodes):
    for nd in nodes:
        yield nd
        if nd.block is not None:
            for x in walk_all(nd.block):
                yield x


# ─── 안전한 읽기(독립 검토 낮음 4) — 심볼릭 링크 · 뿌리 밖 · 너무 큰 파일은 읽지 않는다 ──────────────
READ_LIMIT = 4 * 1024 * 1024


def safe_file(path, root):
    """링크가 아니고, 실제 경로가 뿌리 안이며, 4MB 이하인 보통 파일만 참"""
    try:
        if os.path.islink(path) or not os.path.isfile(path):
            return False
        rp, rr = os.path.realpath(path), os.path.realpath(root)
        if rp != rr and not rp.startswith(rr + os.sep):
            return False
        return os.path.getsize(path) <= READ_LIMIT
    except OSError:
        return False


class ConfFile(object):
    def __init__(self, path, rel):
        self.path, self.rel = path, rel
        with open(path, encoding='utf-8', errors='replace') as fh:
            text = fh.read()
        self.lines = text.split('\n')
        toks, self.comments = tokenize(text)
        self.nodes = parse_tokens(toks, rel)

    def allowed(self, line):
        c = self.comments.get(line)
        if c and ALLOW_RE.search(c):
            return True
        k = line - 1
        while 1 <= k <= len(self.lines) and self.lines[k - 1].lstrip().startswith('#'):
            if ALLOW_RE.search(self.lines[k - 1]):
                return True
            k -= 1
        return False


# ─── 단·상속 ──────────────────────────────────────────────────────────────────────────────────
LEVELS = ('server', 'location', 'if', 'limit_except')


def direct(block, name):
    return [c for c in block.block if c.name == name and c.block is None]


def inherited(chain, name):
    """배열·단일 지시어 모두: 가장 안쪽 단에서 그 이름이 하나라도 있으면 그 단의 것만(nginx merge 규칙)"""
    for b in reversed(chain):
        d = direct(b, name)
        if d:
            return d, b
    return [], None


def levels(block, chain):
    """(단 블록, 그 위 단들) — http 가상 블록 아래 server·location·if"""
    for c in block.block:
        if c.block is not None and c.name in LEVELS:
            yield c, chain
            for x in levels(c, chain + [c]):
                yield x


def where(nd):
    return '%s:%d' % (nd.file, nd.line)


def label(nd):
    if nd is None:
        return 'http'
    if nd.name == 'http':
        return 'http 단'
    return nd.head()


# ─── 사전 ─────────────────────────────────────────────────────────────────────────────────────
SEC_HEADERS = ('content-security-policy', 'x-content-type-options', 'x-frame-options', 'referrer-policy',
               'strict-transport-security', 'permissions-policy', 'cross-origin-opener-policy',
               'cross-origin-embedder-policy', 'cross-origin-resource-policy')
PASS_HIDE = {'fastcgi_pass': 'fastcgi_hide_header', 'proxy_pass': 'proxy_hide_header', 'uwsgi_pass': 'uwsgi_hide_header',
             'scgi_pass': 'scgi_hide_header', 'grpc_pass': 'grpc_hide_header', 'memcached_pass': None}

NGX_VARS = set('''
args binary_remote_addr body_bytes_sent bytes_sent connection connection_requests connection_time content_length
content_type document_root document_uri host hostname https is_args limit_rate msec nginx_version pid pipe
proxy_protocol_addr proxy_protocol_port proxy_protocol_server_addr proxy_protocol_server_port query_string realpath_root
remote_addr remote_port remote_user request request_body request_body_file request_completion request_filename
request_id request_length request_method request_time request_uri scheme server_addr server_name server_port
server_protocol status tcpinfo_rtt tcpinfo_rttvar tcpinfo_snd_cwnd tcpinfo_rcv_space time_iso8601 time_local uri
realip_remote_addr realip_remote_port fastcgi_script_name fastcgi_path_info gzip_ratio limit_req_status
limit_conn_status invalid_referer secure_link secure_link_expires date_local date_gmt http2 http3 spdy
msie modern_browser ancient_browser uid_got uid_set uid_reset proxy_host proxy_port proxy_add_x_forwarded_for
proxy_internal_body_length proxy_internal_chunked binary_remote_addr embedded_variable sent_http_content_type
'''.split())
NGX_VAR_PREFIX = ('arg_', 'http_', 'sent_http_', 'sent_trailer_', 'cookie_', 'upstream_', 'ssl_', 'geoip_', 'geoip2_',
                  'jwt_', 'proxy_protocol_tlv_', 'memcached_', 'slice_', 'mirror_', 'realip_')
VAR_RE = re.compile(r'\$(?:\{([A-Za-z_][A-Za-z0-9_]*)\}|([A-Za-z_][A-Za-z0-9_]*))')
NAMED_CAP_RE = re.compile(r'\(\?P?<([A-Za-z_][A-Za-z0-9_]*)>|\(\?\'([A-Za-z_][A-Za-z0-9_]*)\'')
# 정규식·이름을 받는 칸 — 변수 참조로 읽지 않는다(`\.php$` 의 `$` 는 끝 표시)
REGEX_ARG0 = {'rewrite', 'fastcgi_split_path_info', 'proxy_redirect'}
NO_VAR_SCAN = {'location', 'server_name', 'types', 'valid_referers', 'log_not_found', 'ssi_types', 'gzip_types',
               'charset_types', 'sub_filter_types'}
VAR_DEF_BLOCKS = {'map': 1, 'geo': -1, 'split_clients': 1}    # 정의되는 변수의 인자 자리(-1 = 마지막)
TIME_PART_RE = re.compile(r'(\d+)(ms|[yMwdhms]?)')


def builtin_var(name):
    return name in NGX_VARS or name.startswith(NGX_VAR_PREFIX)


def var_refs(text):
    return [a or b for a, b in VAR_RE.findall(text)]


def ngx_seconds(val, allow_ms=True):
    """nginx 시간(ngx_parse_time) → 초. 모양이 틀리면 None. 단위는 큰 것에서 작은 것으로, 단위 없으면 초"""
    if not val:
        return None
    pos, total, last = 0, 0.0, -1
    order = ['y', 'M', 'w', 'd', 'h', 'm', 's', 'ms']
    mult = {'y': 365 * 86400, 'M': 30 * 86400, 'w': 7 * 86400, 'd': 86400, 'h': 3600, 'm': 60, 's': 1, 'ms': 0.001, '': 1}
    while pos < len(val):
        m = TIME_PART_RE.match(val, pos)
        if not m:
            return None
        unit = m.group(2)
        if unit == 'ms' and not allow_ms:
            return None
        idx = order.index(unit) if unit else len(order)
        if idx <= last:
            return None
        last = idx
        total += int(m.group(1)) * mult[unit]
        pos = m.end()
        if unit == '' and pos < len(val):
            return None
    return total


def expires_ok(val):
    """ngx_http_parse_expires 가 읽는 값인가(epoch·max·off·@시각·[+-]시간)"""
    if val in ('epoch', 'max', 'off'):
        return True
    if val.startswith('@'):
        return ngx_seconds(val[1:], allow_ms=False) is not None
    v = val[1:] if val[:1] in '+-' else val
    return ngx_seconds(v, allow_ms=False) is not None


def fmt_sec(s):
    if s is None:
        return '?'
    s = float(s)
    return ('%gs' % s)


# ─── 입력 모으기 ───────────────────────────────────────────────────────────────────────────────
class SiteSet(object):
    """deploy/sites 한 폴더 — 파일 풀이, include 펼치기, 사이트·공통·조각 가르기"""

    def __init__(self, sites_dir, rel_prefix, stats):
        self.files = {}          # 파일 이름 → ConfFile
        self.bad = []
        for fn in sorted(os.listdir(sites_dir)):
            p = os.path.join(sites_dir, fn)
            if not (fn.endswith('.conf') and safe_file(p, os.path.dirname(os.path.dirname(sites_dir)))):
                continue
            rel = rel_prefix + fn
            try:
                self.files[fn] = ConfFile(p, rel)
            except (ConfError, OSError, RecursionError) as e:
                self.bad.append((rel, str(e)))
                stats['skip'] += 1
                note('%s: 풀지 못함 — %s' % (rel, e))
        self.included = set()
        for fn, cf in self.files.items():
            for nd in walk_all(cf.nodes):
                tgt = self.include_target(nd)
                if tgt and tgt != fn:
                    self.included.add(tgt)
        self.expanded = {}
        for fn, cf in self.files.items():
            top = []
            for nd in self.expand(cf.nodes, [fn]):     # 맨 위 `http { … }` 로 감싼 파일은 속을 맨 위로 편다
                top.extend(nd.block if nd.name == 'http' and nd.block is not None else [nd])
            self.expanded[fn] = top
        self.sites, self.common = [], []
        for fn in sorted(self.files):
            if fn in self.included:
                continue
            if any(nd.name == 'server' and nd.block is not None for nd in self.expanded[fn]):
                self.sites.append(fn)
            else:
                self.common.append(fn)

    def include_target(self, nd):
        if nd.name != 'include' or nd.block is not None or len(nd.args) != 1:
            return None
        base = os.path.basename(nd.args[0])
        if any(ch in base for ch in '*?['):
            return None           # 글롭 include(conf.d/*.conf 류)는 펼치지 않는다
        for cand in (base, base + '.conf'):
            if cand in self.files:
                return cand
        return None

    def expand(self, nodes, stack):
        out = []
        for nd in nodes:
            tgt = self.include_target(nd)
            if tgt and tgt not in stack and len(stack) < 8:
                out.extend(self.expand(self.files[tgt].nodes, stack + [tgt]))
                continue
            if nd.block is not None:
                out.append(Node(nd.name, nd.args, nd.line, nd.file, self.expand(nd.block, stack)))
            else:
                out.append(nd)
        return out

    def http_for(self, fn):
        """그 사이트가 읽히는 http 단 — 공통 파일(conf.d) 전부 + 그 파일 맨 위"""
        kids = []
        for c in self.common:
            kids.extend(self.expanded[c])
        kids.extend(self.expanded[fn])
        return Node('http', [], 0, self.files[fn].rel, kids)


def find_pools(root):
    """deploy/ 아래 php-fpm 풀 파일(`[이름]` + `listen =`) — deploy/sites 는 뺀다"""
    out = []
    d = os.path.join(root, 'deploy')
    if not os.path.isdir(d):
        return out
    for dp, dn, fn in os.walk(d):
        dn[:] = sorted(x for x in dn if not (dp == d and x == 'sites'))
        for f in sorted(fn):
            if not f.endswith('.conf'):
                continue
            p = os.path.join(dp, f)
            if not safe_file(p, root):
                continue
            try:
                with open(p, encoding='utf-8', errors='replace') as fh:
                    text = fh.read()
            except OSError:
                continue
            if re.search(r'(?m)^\s*\[[^\]\s]+\]\s*$', text) and re.search(r'(?m)^\s*listen\s*=', text):
                out.append((os.path.relpath(p, root), text))
    return out


def parse_pool(text):
    """풀 이름 → {'listen': (값, 줄), 'request_terminate_timeout': (값, 줄)} — `;` 뒤는 주석"""
    pools, cur = {}, None
    for i, raw in enumerate(text.split('\n'), 1):
        line = raw.split(';', 1)[0].strip()
        if not line:
            continue
        m = re.match(r'^\[([^\]]+)\]$', line)
        if m:
            cur = m.group(1).strip()
            pools.setdefault(cur, {'_line': i})
            continue
        m = re.match(r'^([A-Za-z0-9_.\[\]]+)\s*=\s*(.*)$', line)
        if m and cur is not None and cur != 'global':
            pools[cur][m.group(1)] = (m.group(2).strip().strip('"\''), i, raw)
    return pools


def fpm_seconds(v):
    m = re.fullmatch(r'(\d+)([smhd]?)', v.strip())
    if not m:
        return None
    return int(m.group(1)) * {'': 1, 's': 1, 'm': 60, 'h': 3600, 'd': 86400}[m.group(2)]


# ─── 판정 ─────────────────────────────────────────────────────────────────────────────────────
class Checker(object):
    def __init__(self, sset, stats, verbose):
        self.s, self.stats, self.verbose = sset, stats, verbose
        self.findings = {}
        self.maps_seen = set()      # expires 로 읽는 map 은 한 번만 본다(여러 사이트가 같은 map 을 씀)

    def emit(self, cls, sev, nd, msg, extra_allow=()):
        cf = self.s.files.get(os.path.basename(nd.file))
        if cf is not None and cf.rel == nd.file and cf.allowed(nd.line):
            return
        for x in extra_allow:
            xf = self.s.files.get(os.path.basename(x.file))
            if xf is not None and xf.allowed(x.line):
                return
        self.emit_raw(cls, sev, nd.file, nd.line, msg)

    def emit_raw(self, cls, sev, rel, line, msg):
        self.findings[(rel, line, cls, msg)] = (sev, rel, line, '%s %s' % (cls, msg))

    def skip(self, nd, why):
        self.stats['skip'] += 1
        if self.verbose:
            note('SKIP %s %s' % (where(nd), why))

    # N1 ─────────────────────────────────────────────────────────────────
    def n1(self, http):
        for lv, chain in levels(http, [http]):
            own = direct(lv, 'add_header')
            if not own:
                continue
            parent, src = inherited(chain, 'add_header')
            req = {}
            for h in parent:
                if h.args and h.args[0].lower() in SEC_HEADERS:
                    req.setdefault(h.args[0].lower(), h)
            if not req:
                continue
            mine = {}
            for h in own:
                if h.args:
                    mine.setdefault(h.args[0].lower(), h)
            # 응답을 뒤로 넘기는가(if·limit_except 는 둘러싼 location 의 *_pass 를 물려받는다)
            scope = [lv]
            if lv.name in ('if', 'limit_except'):
                for b in reversed(chain):
                    scope.append(b)
                    if b.name == 'location':
                        break
            pass_kind = None
            for b in scope:
                for k in PASS_HIDE:
                    if direct(b, k):
                        pass_kind = k
                        break
                if pass_kind:
                    break
            hidden = set()
            if pass_kind and PASS_HIDE[pass_kind]:
                hs, _ = inherited(chain + [lv], PASS_HIDE[pass_kind])
                hidden = {h.args[0].lower() for h in hs if h.args}
            missing = [k for k in req if k not in mine]
            hard = [k for k in missing if not pass_kind or k in hidden]
            soft = [k for k in missing if pass_kind and k not in hidden]
            if soft:
                self.skip(lv, 'N1 뒤(%s)로 넘기는 블록 — 빠진 헤더 %s 는 앱이 붙일 수 있음' % (pass_kind, ', '.join(soft)))
            if hard:
                names = ', '.join(req[k].args[0] for k in hard)
                why = ' (%s 로 숨김)' % PASS_HIDE[pass_kind] if pass_kind else ''
                self.emit('N1', '높음', lv,
                          'add_header 상속 끊김 — `%s` 안에 add_header 가 있어 위 단(%s %s)의 보안 헤더 %s 를 '
                          '물려받지 않는데 다시 적지 않았다%s' % (lv.head(), label(src), where(src_line(src, parent)),
                                                   names, why))
            weak = [k for k in req if k in mine and 'always' in [a.lower() for a in req[k].args[2:]]
                    and 'always' not in [a.lower() for a in mine[k].args[2:]]]
            if weak:
                self.emit('N1', '낮음', lv,
                          'add_header 다시 적기에 `always` 빠짐 — `%s` 의 %s 는 위 단과 달리 4xx·5xx 응답에 붙지 않는다'
                          % (lv.head(), ', '.join(req[k].args[0] for k in weak)))

    # N2 ─────────────────────────────────────────────────────────────────
    def n2(self, http, site_nodes):
        defs = {}          # 변수 → [정의 Node] (http 단 map·geo·split_clients·perl_set·js_set)
        local = set()      # set·이름 캡처
        for nd in http.block:
            if nd.block is not None and nd.name in VAR_DEF_BLOCKS and nd.args:
                v = nd.args[VAR_DEF_BLOCKS[nd.name]]
                if v.startswith('$'):
                    defs.setdefault(v[1:].strip('{}'), []).append(nd)
            elif nd.block is None and nd.name in ('perl_set', 'js_set') and nd.args and nd.args[0].startswith('$'):
                defs.setdefault(nd.args[0][1:], []).append(nd)
        for nd in walk_all(http.block):
            if nd.name == 'set' and nd.block is None and nd.args and nd.args[0].startswith('$'):
                local.add(nd.args[0][1:].strip('{}'))
            texts = []
            if nd.name == 'location' or nd.name == 'server_name' or nd.name in REGEX_ARG0:
                texts = nd.args if nd.name != 'rewrite' else nd.args[:1]
            elif nd.name == 'if':
                texts = nd.args
            for t in texts:
                for a, b in NAMED_CAP_RE.findall(t):
                    local.add(a or b)
            if nd.block is not None and nd.name == 'map':
                for e in nd.block:
                    for a, b in NAMED_CAP_RE.findall(e.name):
                        local.add(a or b)
        for v, nds in sorted(defs.items()):
            if len(nds) > 1:
                for extra in nds[1:]:
                    self.emit('N2', '중간', extra,
                              '변수 $%s 가 http 단에 %d번 정의(첫 정의 %s) — 뒤의 것이 앞의 것을 덮는다(꼭 하나)'
                              % (v, len(nds), where(nds[0])))
        known = lambda name: builtin_var(name) or name in defs or name in local
        for nd, argv in self.var_sites(http.block):
            for t in argv:
                for name in var_refs(t):
                    if not known(name):
                        self.emit('N2', '중간', nd,
                                  '변수 $%s 를 정의한 곳이 없다(nginx 내장 아님 · http 단 map·geo·set 없음) — '
                                  '`%s` 에서 nginx -t 가 unknown variable 로 멈춘다' % (name, nd.name))
        # expires $x — map 값 모양·default
        for nd in walk_all(site_nodes):
            if nd.name != 'expires' or nd.block is not None or len(nd.args) != 1:
                continue
            refs = var_refs(nd.args[0])
            if len(refs) != 1 or nd.args[0] not in ('$' + refs[0], '${%s}' % refs[0]):
                continue
            for m in defs.get(refs[0], []):
                if m.name != 'map' or (m.file, m.line) in self.maps_seen:
                    continue
                self.maps_seen.add((m.file, m.line))
                has_default = False
                for e in m.block:
                    if e.name in ('hostnames', 'volatile', 'include'):
                        continue
                    if e.name == 'default':
                        has_default = True
                    if len(e.args) != 1:
                        self.emit('N2', '중간', e, 'map $%s 의 줄 모양이 `<열쇠> <값>;` 이 아니다' % refs[0])
                        continue
                    val = e.args[0]
                    if val.startswith('$'):
                        self.skip(e, 'N2 map 값이 또 변수')
                        continue
                    if not expires_ok(val):
                        self.emit('N2', '중간', e,
                                  'map $%s 의 값 `%s` 는 expires 가 읽지 못하는 모양(epoch·max·off·@시각·[+-]시간) — '
                                  '그 값이 걸린 요청은 오류 응답이 된다(%s)' % (refs[0], val[:40], where(nd)))
                    if e.name.startswith('~'):
                        pat = e.name[2:] if e.name.startswith('~*') else e.name[1:]
                        if '[[:' in pat:
                            self.skip(e, 'N2 POSIX 묶음 정규식')
                        else:
                            try:
                                re.compile(py_regex(pat))
                            except re.error:
                                self.skip(e, 'N2 Python 이 못 읽는 정규식')
                if not has_default:
                    self.emit('N2', '중간', m,
                              'map $%s 에 default 가 없다 — 어느 줄에도 안 맞는 요청은 빈 값이 되어 `expires %s` 가 '
                              '읽지 못한다(%s)' % (refs[0], nd.args[0], where(nd)))

    def var_sites(self, nodes):
        """(Node, 변수를 읽는 인자들) — location·server_name 머리, 정규식 칸, map 열쇠, 정의되는 변수는 뺀다"""
        for nd in nodes:
            if nd.block is not None:
                if nd.name in VAR_DEF_BLOCKS:
                    idx = VAR_DEF_BLOCKS[nd.name] % len(nd.args) if nd.args else None
                    yield nd, [a for i, a in enumerate(nd.args) if i != idx]
                    for e in nd.block:
                        if e.block is None:
                            yield e, e.args[:1]          # 값만(열쇠는 글자·정규식)
                    continue
                if nd.name in ('types', 'upstream'):
                    continue
                if nd.name == 'if':
                    yield nd, if_var_args(nd.args)
                for x in self.var_sites(nd.block):
                    yield x
                continue
            if nd.name in NO_VAR_SCAN or nd.name in ('perl_set', 'js_set'):
                continue
            if nd.name in REGEX_ARG0 or nd.name == 'set':
                yield nd, nd.args[1:]
            else:
                yield nd, nd.args

    # N3 ─────────────────────────────────────────────────────────────────
    def n3(self, http):
        for nd, block in iter_with_parent(http):
            if nd.name != 'gzip_types' or nd.block is not None:
                continue
            bad = [t for t in nd.args if json_type(t)]
            if not bad:
                continue
            g = direct(block, 'gzip') if block is not None else []
            if g and g[-1].args and g[-1].args[0].lower() == 'off':
                continue
            self.emit('N3', '중간', nd,
                      'gzip_types 에 창구 JSON 형식 %s 이 들어갔다 — 응답의 CSRF·세션 값이 압축 길이로 샌다(BREACH)'
                      % ', '.join(bad))

    # N4 ─────────────────────────────────────────────────────────────────
    def n4(self, http):
        for lv, chain in levels(http, [http]):
            if lv.name != 'location' or not lv.args:
                continue
            aliases = direct(lv, 'alias')
            if not aliases:
                continue
            mod = lv.args[0] if len(lv.args) > 1 else ''
            uri = lv.args[-1]
            al = aliases[-1]
            if not al.args:
                continue
            target = al.args[0]
            if mod in ('~', '~*'):
                groups = capture_groups(uri)
                for ref in var_refs(target) + re.findall(r'\$([1-9])', target):
                    g = None
                    for num, name, body in groups:
                        if ref == str(num) or ref == name:
                            g = body
                            break
                    if g is None:
                        continue
                    esc = group_escapes(g)
                    if esc is None:
                        self.skip(al, 'N4 Python 이 못 읽는 캡처')
                    elif esc:
                        self.emit('N4', '높음', al,
                                  '정규식 location `%s` 의 alias 가 캡처 $%s 를 잇는데 그 캡처 `(%s)` 가 `..` 를 막지 않는다 — '
                                  'alias 밖 파일로 나갈 수 있다' % (uri[:60], ref, g[:30]))
            elif mod in ('', '^~') and not uri.startswith('@'):
                if not uri.endswith('/') and target.endswith('/') and not var_refs(target):
                    self.emit('N4', '높음', al,
                              '접두 location `%s` 는 `/` 로 끝나지 않는데 alias `%s` 는 `/` 로 끝난다 — `%s../` 로 '
                              'alias 한 단계 위를 읽는다' % (uri, target, uri))

    # N5 ─────────────────────────────────────────────────────────────────
    def n5(self, http):
        for lv, chain in levels(http, [http]):
            for nd in lv.block:
                if nd.block is not None:
                    continue
                tgt = None
                if nd.name == 'return' and len(nd.args) >= 2 and nd.args[0] in ('301', '302', '303', '307', '308'):
                    tgt = nd.args[1]
                elif nd.name == 'rewrite' and len(nd.args) >= 3 and nd.args[2] in ('redirect', 'permanent'):
                    tgt = nd.args[1]
                if tgt is None:
                    continue
                rel = relative_url(tgt)
                if rel is None:
                    self.skip(nd, 'N5 대상 모양을 모름')
                    continue
                if not rel:
                    continue
                ar, _ = inherited(chain + [lv], 'absolute_redirect')
                if ar and ar[-1].args and ar[-1].args[0].lower() == 'off':
                    continue
                server = lv if lv.name == 'server' else next((b for b in chain if b.name == 'server'), None)
                if server is not None and server_ssl_only(server):
                    continue
                self.emit('N5', '중간', nd,
                          '상대 리다이렉트 `%s %s` 인데 이 블록(상속 포함)에 `absolute_redirect off` 가 없다 — TLS 프록시 '
                          '뒤 평문 server 는 Location 에 `http://` 절대 주소를 낸다' % (nd.name, ' '.join(nd.args[:2])))

    # N7 ─────────────────────────────────────────────────────────────────
    def n7(self, http):
        zones = {'limit_req_zone': set(), 'limit_conn_zone': set()}
        for nd in http.block:
            if nd.block is None and nd.name in zones:
                for a in nd.args:
                    if a.startswith('zone='):
                        zones[nd.name].add(a[5:].split(':', 1)[0])
        for lv, chain in levels(http, [http]):
            for nd in direct(lv, 'limit_req'):
                z = req_zone(nd)
                if z is not None and z not in zones['limit_req_zone']:
                    self.emit('N7', '중간', nd, 'limit_req zone=%s 를 정의한 limit_req_zone 이 http 단에 없다 — '
                                                'nginx -t 가 unknown zone 으로 멈춘다' % z)
            for nd in direct(lv, 'limit_conn'):
                if nd.args and nd.args[0] not in zones['limit_conn_zone']:
                    self.emit('N7', '중간', nd, 'limit_conn %s 를 정의한 limit_conn_zone 이 http 단에 없다' % nd.args[0])
            if lv.name not in ('server', 'location'):
                continue
            exact = {}
            for c in lv.block:
                if c.block is not None and c.name == 'location' and len(c.args) == 2 and c.args[0] == '=':
                    exact.setdefault(c.args[1], c)
            for path, loc in sorted(exact.items()):
                lr = direct(loc, 'limit_req')
                if not lr or path.endswith('/'):
                    continue
                zs = {req_zone(x) for x in lr}
                var = exact.get(path + '/')
                if var is None:
                    self.emit('N7', '중간', loc,
                              '속도 제한이 붙은 정확 일치 `location = %s` 에 끝 슬래시 변형 `location = %s/` 가 없다 — '
                              '앱이 끝 `/` 를 떼고 매칭하면 슬래시 하나로 제한을 우회한다' % (path, path))
                    continue
                vz = {req_zone(x) for x in direct(var, 'limit_req')}
                if not zs <= vz:
                    self.emit('N7', '중간', var,
                              '끝 슬래시 변형 `location = %s/` 에 `location = %s`(%s) 와 같은 limit_req zone(%s)이 없다'
                              % (path, path, where(loc), ', '.join(sorted(z for z in zs if z))))

    # N8 ─────────────────────────────────────────────────────────────────
    def fastcgi_targets(self, http):
        """(location, fastcgi_pass 값, 유효 fastcgi_read_timeout 초, 그 지시어 Node 또는 None)"""
        ups = {}
        for nd in http.block:
            if nd.block is not None and nd.name == 'upstream' and nd.args:
                ups[nd.args[0]] = [s.args[0] for s in nd.block if s.name == 'server' and s.args]
        for lv, chain in levels(http, [http]):
            fp = direct(lv, 'fastcgi_pass')
            if not fp or not fp[-1].args:
                continue
            tgt = fp[-1].args[0]
            addrs = ups.get(tgt, [tgt])
            rt, _ = inherited(chain + [lv], 'fastcgi_read_timeout')
            sec = ngx_seconds(rt[-1].args[0]) if rt and rt[-1].args else 60.0
            for a in addrs:
                yield lv, a, sec, (rt[-1] if rt else None)


def src_line(src, parent):
    return parent[0] if parent else src


def iter_with_parent(block, parent=None):
    for c in block.block:
        yield c, block
        if c.block is not None and c.name not in ('types', 'map', 'geo', 'split_clients', 'upstream'):
            for x in iter_with_parent(c, block):
                yield x


def if_var_args(args):
    """`if ($x ~ re)` — 정규식 칸은 빼고 변수 칸만"""
    out, skip_next = [], False
    for a in args:
        if skip_next:
            skip_next = False
            continue
        if a in ('~', '~*', '!~', '!~*'):
            skip_next = True
            continue
        out.append(a.strip('()'))
    return out


JSON_STATIC = ('application/manifest+json', 'application/geo+json')


def json_type(t):
    t = t.lower().strip()
    if t in ('*', 'application/json', 'text/json', 'application/x-json', 'application/json-seq'):
        return True
    return bool(re.fullmatch(r'application/[a-z0-9.!#$&^_-]+\+json', t)) and t not in JSON_STATIC


def py_regex(pat):
    return re.sub(r'\(\?<([A-Za-z_])', r'(?P<\1', pat)


def capture_groups(pat):
    """정규식의 캡처 묶음 → [(번호, 이름 또는 None, 속 글자)] — 문자 묶음 [...]·역슬래시는 건너뛴다"""
    groups, stack, i, n, num = [], [], 0, len(pat), 0
    while i < n:
        c = pat[i]
        if c == '\\':
            i += 2
            continue
        if c == '[':
            j = i + 1
            if j < n and pat[j] == '^':
                j += 1
            if j < n and pat[j] == ']':
                j += 1
            while j < n and pat[j] != ']':
                j += 2 if pat[j] == '\\' else 1
            i = j + 1
            continue
        if c == '(':
            if pat.startswith('(?', i):
                m = NAMED_CAP_RE.match(pat, i)
                if m:
                    num += 1
                    stack.append((num, m.group(1) or m.group(2), m.end()))
                    i = m.end()
                else:
                    stack.append(None)
                    i += 2
                continue
            num += 1
            stack.append((num, None, i + 1))
            i += 1
            continue
        if c == ')':
            if stack:
                g = stack.pop()
                if g:
                    groups.append((g[0], g[1], pat[g[2]:i]))
            i += 1
            continue
        i += 1
    return sorted(groups)


def group_escapes(body):
    """캡처 속 글자가 `..`(와 `../x`)에 맞으면 True · 못 읽으면 None"""
    try:
        rx = re.compile(r'(?:%s)\Z' % py_regex(body), re.S)
    except re.error:
        return None
    return any(rx.match(p) for p in ('..', '../x', 'x/../y', '../'))


def relative_url(u):
    """True 상대 · False 절대 · None 모름"""
    if re.match(r'(?i)^[a-z][a-z0-9+.-]*://', u) or re.match(r'^\$\{?scheme\b', u):
        return False
    if u.startswith('/'):
        return True
    if re.match(r'^\$\{?(request_uri|uri|document_uri)\b', u):
        return True
    return None


def server_ssl_only(server):
    ls = direct(server, 'listen')
    return bool(ls) and all(any(a in ('ssl', 'quic') for a in l.args[1:]) for l in ls)


LOOP_RE = re.compile(r'^(?:127\.\d+\.\d+\.\d+|localhost|\[::1\])(?::\d+)?$|^unix:')


def server_loopback_plain(server):
    ls = direct(server, 'listen')
    return bool(ls) and all(l.args and LOOP_RE.match(l.args[0]) and 'ssl' not in l.args[1:] for l in ls)


def req_zone(nd):
    for a in nd.args:
        if a.startswith('zone='):
            return a[5:]
    return None


# ─── N6 공개용·로컬용 ─────────────────────────────────────────────────────────────────────────
CMP_NAMES = ('add_header', 'expires', 'limit_req', 'limit_req_status', 'client_max_body_size', 'absolute_redirect',
             'internal', 'allow', 'deny', 'auth_basic', 'limit_rate', 'limit_conn')


def cmp_sig(block, drop_hsts):
    d = {}
    for c in block.block:
        if c.block is not None or c.name not in CMP_NAMES:
            continue
        if c.name == 'add_header' and c.args:
            key = 'add_header ' + c.args[0].lower()
            if drop_hsts and c.args[0].lower() == 'strict-transport-security':
                continue
            d.setdefault(key, []).append((' '.join(c.args[1:]), c))
        else:
            d.setdefault(c.name, []).append((' '.join(c.args), c))
    return d


def loc_key(nd):
    return (nd.name,) + tuple(nd.args)


def child_levels(block):
    out = {}
    for c in block.block:
        if c.block is not None and c.name in ('location', 'if', 'limit_except'):
            out.setdefault(loc_key(c), c)
    return out


def compare_pair(ck, pub, loc, drop_hsts, path):
    sa, sb = cmp_sig(pub, drop_hsts), cmp_sig(loc, drop_hsts)
    for key in sorted(set(sa) | set(sb)):
        va = [v for v, _ in sa.get(key, [])]
        vb = [v for v, _ in sb.get(key, [])]
        if va == vb:
            continue
        na = sa[key][0][1] if key in sa else pub
        nb = sb[key][0][1] if key in sb else loc
        if key not in sb:
            what = '로컬용에 없다'
        elif key not in sa:
            what = '공개용에 없다'
        else:
            what = '값이 다르다'
        ck.emit('N6', '낮음', nb,
                '공개용·로컬용 어긋남 — `%s` 의 %s 가 %s(공개용 %s)' % (path, key, what, where(na)), extra_allow=(na,))
    la, lb = child_levels(pub), child_levels(loc)
    for key in sorted(set(la) | set(lb), key=lambda k: ' '.join(k)):
        a, b = la.get(key), lb.get(key)
        if a is not None and b is not None:
            compare_pair(ck, a, b, drop_hsts, ' '.join(key))
            continue
        one = a if a is not None else b
        if not cmp_sig(one, drop_hsts) and not any(cmp_sig(x, drop_hsts) for x in walk_levels(one)):
            continue
        ck.emit('N6', '낮음', one,
                '공개용·로컬용 어긋남 — 보안·캐시·속도 제한 지시어가 있는 `%s` 가 %s에만 있다'
                % (' '.join(key), '공개용' if a is not None else '로컬용'))


def walk_levels(block):
    for c in block.block:
        if c.block is not None and c.name in ('location', 'if', 'limit_except'):
            yield c
            for x in walk_levels(c):
                yield x


def run_n6(ck, sset):
    for fn in sset.sites:
        if not fn.endswith('-local.conf'):
            continue
        pub = fn[:-len('-local.conf')] + '.conf'
        if pub not in sset.sites:
            continue
        sa = [n for n in sset.expanded[pub] if n.name == 'server' and n.block is not None]
        sb = [n for n in sset.expanded[fn] if n.name == 'server' and n.block is not None]
        for a, b in zip(sa, sb):
            drop = server_loopback_plain(a) or server_loopback_plain(b)
            compare_pair(ck, a, b, drop, 'server')


# ─── N8 ───────────────────────────────────────────────────────────────────────────────────────
def norm_addr(a):
    a = a.strip()
    if a.startswith('unix:'):
        return ('unix', os.path.normpath(a[5:]))
    if a.startswith('/'):
        return ('unix', os.path.normpath(a))
    m = re.fullmatch(r'(?:(\[[^\]]+\]|[^:]+):)?(\d+)', a)
    if m:
        host = m.group(1) or '*'
        return ('tcp', host.lower().replace('localhost', '127.0.0.1'), m.group(2))
    return ('name', a)


def addr_match(pool_listen, fcgi):
    a, b = norm_addr(pool_listen), norm_addr(fcgi)
    if a[0] != b[0]:
        return False
    if a[0] == 'tcp':
        return a[2] == b[2] and (a[1] in ('*', '0.0.0.0', b[1]) or b[1] == a[1])
    return a == b


def run_n8(ck, sset, pools, root, stats):
    targets = []
    for fn in sset.sites:
        http = sset.http_for(fn)
        targets.extend(ck.fastcgi_targets(http))
    for rel, text in pools:
        for name, kv in sorted(parse_pool(text).items()):
            if 'listen' not in kv:
                continue
            rtt = kv.get('request_terminate_timeout')
            if rtt is None:
                continue
            sec = fpm_seconds(rtt[0])
            if sec is None:
                ck.emit_raw('N8', '낮음', rel, rtt[1], 'php-fpm 풀 [%s] request_terminate_timeout 값 모양을 읽지 못했다' % name)
                continue
            if sec == 0:
                continue
            if ALLOW_RE.search(rtt[2]):
                continue
            mine = [(lv, s, rn) for lv, a, s, rn in targets if addr_match(kv['listen'][0], a)]
            longer = [(lv, s) for lv, s, _ in mine if s is not None and sec > s]
            shorter = [(lv, s) for lv, s, _ in mine if s is not None and sec < s]
            for lst, word, why in ((longer, '길다', 'nginx 가 504 로 끊은 뒤에도 워커가 그 요청을 쥔다'),
                                   (shorter, '짧다', 'php-fpm 이 nginx 보다 먼저 요청을 죽여 502 가 난다(긴 정상 요청이 잘림)')):
                if not lst:
                    continue
                vals = sorted({s for _, s in lst})
                ck.emit_raw('N8', '낮음', rel, rtt[1],
                            'php-fpm 풀 [%s] request_terminate_timeout %s 이 이 풀(listen %s)로 가는 location %d곳의 '
                            'fastcgi_read_timeout(%s)보다 %s — %s (예: %s `%s`)'
                            % (name, fmt_sec(sec), kv['listen'][0], len(lst), '·'.join(fmt_sec(v) for v in vals),
                               word, why, where(lst[0][0]), lst[0][0].head()))


# ─── N2 선택: 시험 파서 돌리기 ──────────────────────────────────────────────────────────────────
PARSER_CALL_RE = re.compile(
    r'python3?\s+(?:-[A-Za-z]+\s+)*"?(?:\$ROOT|\$\{ROOT\})/([\w./-]+\.py)"?\s+([a-z_]+)\s+'
    r'((?:"?(?:\$ROOT|\$\{ROOT\})/deploy/sites/[\w.-]+"?\s*)+)')


def run_parsers(ck, root, stats):
    tdir = os.path.join(root, 'tests')
    if not os.path.isdir(tdir):
        return
    seen = set()
    for dp, dn, fn in os.walk(tdir):
        dn[:] = sorted(x for x in dn if x not in ('fixtures', 'static'))
        for f in sorted(fn):
            if not f.endswith('.sh'):
                continue
            p = os.path.join(dp, f)
            if not safe_file(p, root):
                continue
            rel = os.path.relpath(p, root)
            try:
                with open(p, encoding='utf-8', errors='replace') as fh:
                    lines = fh.read().split('\n')
            except OSError:
                continue
            for i, ln in enumerate(lines, 1):
                m = PARSER_CALL_RE.search(ln)
                if not m:
                    continue
                py = os.path.join(root, m.group(1))
                confs = [os.path.join(root, 'deploy', 'sites', os.path.basename(x.strip('"')))
                         for x in m.group(3).split()]
                key = (py, m.group(2), tuple(confs))
                # 실행할 파서와 넘길 설정은 뿌리 안의 실제 파일만(`../` · 링크로 뿌리 밖 코드를 돌리지 않는다 — 독립 검토 낮음 8)
                if key in seen or not safe_file(py, root) or not all(safe_file(c, root) for c in confs):
                    continue
                seen.add(key)
                try:
                    r = subprocess.run([sys.executable, '-B', '-I', py, m.group(2)] + confs, cwd=root,
                                       stdout=subprocess.DEVNULL, stderr=subprocess.PIPE, timeout=30)
                    rc = r.returncode
                except (OSError, subprocess.SubprocessError):
                    ck.skip(Node('parser', [], i, rel), 'N2 파서를 돌리지 못함')
                    continue
                if rc != 0:
                    ck.emit_raw('N2', '중간', rel, i,
                                '설정을 읽는 시험 파서 `%s %s` 가 지금 설정을 못 읽는다(rc %d) — 이 줄의 시험이 설정 모양을 '
                                '따라가지 못한다' % (m.group(1), m.group(2), rc))


# ─── 실행 ─────────────────────────────────────────────────────────────────────────────────────
def check_set(sset, pools, root, stats, verbose, parser):
    ck = Checker(sset, stats, verbose)
    for fn in sset.sites:
        http = sset.http_for(fn)
        site_nodes = sset.expanded[fn]
        ck.n1(http)
        ck.n2(http, http.block)
        ck.n3(http)
        ck.n4(http)
        ck.n5(http)
        ck.n7(http)
    if not sset.sites:
        # 사이트가 없어도 공통 파일의 N3 는 본다
        for c in sset.common:
            ck.n3(Node('http', [], 0, sset.files[c].rel, sset.expanded[c]))
    run_n6(ck, sset)
    run_n8(ck, sset, pools, root, stats)
    if parser:
        run_parsers(ck, root, stats)
    return ck.findings


def main(argv=None):
    here = os.path.dirname(os.path.abspath(__file__))
    ap = argparse.ArgumentParser(description='R27 nginx 설정 — 상속·변수·압축·alias·리다이렉트·짝·속도 제한·시간 짝(읽기만)')
    ap.add_argument('--root', default=os.path.normpath(os.path.join(here, '..', '..')))
    ap.add_argument('--outer', default=None, help='바깥 저장소 배치 — <outer>/deploy/sites 가 있으면 함께 본다(없으면 안 봄)')
    ap.add_argument('--range', default=None, help='실행 틀과 모양 맞추기용(이 규칙은 쓰지 않음)')
    ap.add_argument('--directive', default=None, help='실행 틀과 모양 맞추기용(이 규칙은 쓰지 않음)')
    ap.add_argument('--parser', action='store_true', help='N2 선택 — 시험 셸이 부르는 설정 파서를 돌려 rc 0 인지(대상 트리 코드 실행)')
    ap.add_argument('--verbose', action='store_true', help='판정 못 한 곳(SKIP)을 stderr 에')
    args = ap.parse_args(argv)

    root = os.path.realpath(args.root)
    sites_dir = os.path.join(root, 'deploy', 'sites')
    if not os.path.isdir(sites_dir):
        note('deploy/sites 가 없다 — 검사 못 함: %s' % sites_dir)
        print('RESULT pass=0 fail=0 skip=1')
        return 2
    stats = {'skip': 0}
    sets = [(SiteSet(sites_dir, 'deploy/sites/', stats), find_pools(root), root)]
    if args.outer and args.outer.lower() != 'none':
        od = os.path.join(os.path.realpath(args.outer), 'deploy', 'sites')
        if os.path.isdir(od):
            prefix = os.path.basename(os.path.realpath(args.outer)) + ':deploy/sites/'
            sets.append((SiteSet(od, prefix, stats), [], None))
    inputs = {cf.rel for s, _, _ in sets for cf in s.files.values()} | {rel for rel, _ in sets[0][1]}
    nfiles = len(inputs)
    if not any(s.files for s, _, _ in sets):
        note('읽은 설정 파일이 없다 — 검사 못 함')
        print('RESULT pass=0 fail=0 skip=%d' % max(1, stats['skip']))
        return 2
    findings = {}
    for sset, pools, r in sets:
        findings.update(check_set(sset, pools, r, stats, args.verbose, args.parser and r is not None))
    out = sorted(findings.values(), key=lambda f: (f[1], f[2], f[3]))
    for sev, rel, line, msg in out:
        print('FINDING %s %s %s:%d %s' % (RULE, sev, rel, line, msg))
    bad_files = len({rel for _, rel, _, _ in out} & inputs)
    print('RESULT pass=%d fail=%d skip=%d' % (max(0, nfiles - bad_files), len(out), stats['skip']))
    return 1 if out else 0


if __name__ == '__main__':
    try:
        sys.exit(main())
    except KeyboardInterrupt:
        raise
    except Exception as e:     # 규칙 자체 오류는 「검사 못 함」
        note('실행 오류: %s: %s' % (type(e).__name__, e))
        print('RESULT pass=0 fail=0 skip=1')
        sys.exit(2)
