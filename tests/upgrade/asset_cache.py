#!/usr/bin/env python3
# tests/upgrade/asset_cache.py — 업그레이드 스모크가 흉내 낼 운영 nginx 자산 캐시 기간을 deploy/sites 에서 읽는다
#
# 부르는 쪽: tests/upgrade_smoke.sh (단독으로 불러도 된다 — 읽기만 한다. 네트워크·DB 0)
# 쓰는 법:
#   asset_cache.py parse <tbox-v2.conf> <tbox-common.conf> [<자산 폴더>]
#       → 표준출력 한 줄 JSON {"default": <초>, "rules": [{"re": <정규식>, "ci": <bool>, "age": <초>}, …],
#                              "exact": {"/tbox/assets/<파일>": {"default": …, "rules": […]}, …}, "from": <출처 설명>}
#       자산 폴더(public/assets)를 주면 정규식 location 이 실제 자산 주소에 걸리는지도 본다(걸리면 흉내 밖)
#   asset_cache.py age <JSON> <v 값> [<경로>]
#       → 그 `?v=` 값(과 경로)에 nginx 가 낼 max-age(초) — 부르는 셸이 라우터 머리와 대조하는 기대값(라우터와 따로 짠 판정)
#   asset_cache.py same <JSON> <JSON>
#       → 두 판정(기본값·정규식 → 값, 차례·정확 일치 경로까지)이 같으면 rc 0, 다르면 1 — 공개용(tbox-v2.conf)과 로컬용(tbox-v2-local.conf, 8082) 대조
#
# 무엇을 읽나: 사이트 파일의 server 마다 `location /tbox/assets/ { … }`(또는 `^~`) 블록 바로 안 `expires <값>;`
#   (server 가 여럿이면 판정이 같아야 한다) · 같은 server 의 `location = /tbox/assets/<파일>` 은 그 파일만의 판정(nginx 는 정확 일치가 이긴다)
#   - 값이 글자(`7d` · `+1y` · `1d12h` · `3600` · `max`)면 그 값 하나(rules 빈 목록)
#   - 값이 변수(`$tbox_asset_expires` — Q:F-4 U-4′)면 tbox-common.conf 의 `map $arg_v $그변수 { … }` 에서
#     `default` 와 정규식 줄(`"~…"` 대소문자 구분 · `"~*…"` 무시)을 적힌 차례대로. nginx 는 정규식을 적힌 차례로 보고
#     처음 맞는 것의 값을, 아무것도 안 맞으면 default 를 쓴다(map 문서의 우선순위 4·5). **값이 비면(`?v=` 없음·빈 값)
#     nginx 는 정규식을 보지 않고 곧바로 default**(ngx_http_map_find 의 `len &&` — 독립 검토 낮음 1)
# 흉내 밖이면 멈춘다(rc 3 · 이유는 표준오류):
#   map — 원본이 $arg_v 가 아님 · hostnames · include · 같은 글자 줄(정확 일치) · default 없음 · 정규식에 제어 문자·POSIX 묶음(`[[:`)
#   값 — 또 변수 · `off`/`epoch`/`@시각`/`modified`/음수 · 끝 줄바꿈 같은 군더더기(nginx -t 가 거부)
#   location 구조(독립 검토 낮음 2) — 자산 블록·정확 일치 블록 안의 중첩 블록(location·if) · `add_header Cache-Control`/`Expires` ·
#     `/tbox/assets/` 보다 긴 접두 location · 자산 블록이 `^~` 가 아닐 때 실제 자산 주소에 걸리는 정규식 location(nginx 는 정규식이 접두를 이긴다)
# nginx 설정 낱말 규칙(ngx_conf_read_token): `#` 은 낱말 첫머리에서만 주석, 따옴표(" ')로 묶으면 한 낱말, 역슬래시는
#   \" \' \\ 만 풀고 \t \r \n 은 제어 문자, 그 밖(\d 등)은 그대로 둔다.
# 나가는 값: 0 · 2 쓰는 법 틀림 · 3 흉내 밖·파일 없음
import json
import os
import re
import sys

SEC = {"y": 365 * 86400, "M": 30 * 86400, "w": 7 * 86400, "d": 86400, "h": 3600, "m": 60, "s": 1}
TIME_RE = re.compile(r"\+?(?:(\d+)y)?(?:(\d+)M)?(?:(\d+)w)?(?:(\d+)d)?(?:(\d+)h)?(?:(\d+)m(?!s))?(?:(\d+)s?)?")   # fullmatch 로만 쓴다
EXPIRES_MAX = 10 * 365 * 86400   # nginx `expires max` → Cache-Control: max-age=315360000


def stop(msg):
    print(msg, file=sys.stderr)
    sys.exit(3)


def unescape(raw):
    out, i = [], 0
    while i < len(raw):
        c = raw[i]
        if c == "\\" and i + 1 < len(raw):
            n = raw[i + 1]
            if n in "\"'\\":
                out.append(n); i += 2; continue
            if n in "trn":
                out.append({"t": "\t", "r": "\r", "n": "\n"}[n]); i += 2; continue
        out.append(c); i += 1
    return "".join(out)


def tokens(path):
    try:
        with open(path, encoding="utf-8") as fh:
            s = fh.read()
    except OSError as e:
        stop("설정 파일을 읽지 못했다: %s (%s)" % (path, e.strerror))
    out, i, n = [], 0, len(s)
    while i < n:
        c = s[i]
        if c.isspace():
            i += 1
        elif c == "#":
            j = s.find("\n", i)
            i = n if j < 0 else j
        elif c in "{};":
            out.append((c, False)); i += 1
        elif c in "\"'":
            j = i + 1
            while j < n and s[j] != c:
                j += 2 if s[j] == "\\" else 1
            if j >= n:
                stop("닫히지 않은 따옴표: %s" % path)
            out.append((unescape(s[i + 1:j]), True)); i = j + 1
        else:
            j = i
            while j < n and not s[j].isspace() and s[j] not in "{};":
                j += 2 if s[j] == "\\" else 1
            out.append((unescape(s[i:j]), False)); i = j
    return out


def directives(toks):
    """(이름, 인자들, 블록 낱말 또는 None) 을 한 층씩 — 블록은 같은 모양으로 다시 풀 수 있다"""
    i = 0
    while i < len(toks):
        words = []
        while i < len(toks) and toks[i][0] not in (";", "{", "}") or (i < len(toks) and toks[i][1]):
            words.append(toks[i][0]); i += 1
        if i >= len(toks):
            if words:
                stop("끝나지 않은 지시어: %s" % " ".join(words))
            return
        t = toks[i][0]
        if t == ";":
            i += 1
            if words:
                yield words[0], words[1:], None
        elif t == "{":
            depth, j = 1, i + 1
            while j < len(toks) and depth:
                if not toks[j][1]:
                    depth += {"{": 1, "}": -1}.get(toks[j][0], 0)
                j += 1
            if depth:
                stop("닫히지 않은 블록: %s" % " ".join(words))
            yield (words[0] if words else ""), words[1:], toks[i + 1:j - 1]
            i = j
        else:
            stop("짝이 없는 } 앞: %s" % " ".join(words))


def walk(toks):
    for name, args, block in directives(toks):
        yield name, args, block
        if block is not None:
            yield from walk(block)


def to_sec(val, where):
    if val == "max":
        return EXPIRES_MAX
    if val.startswith("$"):
        stop("%s: 값이 또 변수다(%s) — 흉내 밖" % (where, val))
    m = TIME_RE.fullmatch(val)
    if not m or not any(m.groups()):
        stop("%s: 캐시 기간 `%s` 를 흉내 낼 수 없다(off·epoch·@시각·modified·음수 등)" % (where, val))
    units = "yMwdhms"
    total = sum(int(g) * SEC[u] for g, u in zip(m.groups(), units) if g)
    if total <= 0:
        stop("%s: 캐시 기간이 0 이다(`%s`)" % (where, val))
    return total


CACHE_HEADERS = ("cache-control", "expires")


def check_pattern(pat, where):
    if not pat or re.search(r"[\x00-\x1f]", pat):
        stop(where + " — 빈 정규식이거나 제어 문자")
    if "[[:" in pat:
        stop(where + " — POSIX 문자 묶음(`[[:…:]]`)은 Python 과 PCRE 가 다르게 읽는다 — 흉내 밖")
    try:
        re.compile(pat)
    except re.error as e:
        stop(where + " — 정규식을 읽지 못했다(%s)" % e)


def block_expires(block, where):
    """자산 블록·정확 일치 블록 — 중첩 블록·캐시 머리 add_header 가 없고 인자 하나짜리 expires 가 꼭 하나"""
    exp = []
    for name, args, sub in directives(block):
        if sub is not None and name in ("location", "if"):   # types { … } 같은 블록은 캐시와 무관
            stop("%s: 블록 안에 중첩 %s(%s)이 있다 — 흉내 밖" % (where, name, " ".join(args)))
        if name == "add_header" and args and args[0].lower() in CACHE_HEADERS:
            stop("%s: add_header %s 가 있다 — 흉내 밖(nginx 는 머리가 두 줄이 된다)" % (where, args[0]))
        if name == "expires":
            exp.append(args)
    if len(exp) != 1 or len(exp[0]) != 1:
        stop("%s: 인자 하나짜리 expires 가 꼭 하나 있어야 한다" % where)
    return exp[0][0]


def resolve(val, where, common_conf):
    """expires 값 하나 → {"default", "rules"}(글자면 rules 빈 목록 · 변수면 common 의 map $arg_v)"""
    if not val.startswith("$"):
        return {"default": to_sec(val, where), "rules": []}
    maps = [(a, b) for n, a, b in walk(tokens(common_conf)) if n == "map" and len(a) == 2 and a[1] == val and b is not None]
    if len(maps) != 1:
        stop("%s: `map … %s { … }` 가 꼭 하나 있어야 한다(%d개)" % (common_conf, val, len(maps)))
    (src, _), block = maps[0]
    if src != "$arg_v":
        stop("%s: map %s 의 원본이 $arg_v 가 아니다(%s) — 흉내 밖" % (common_conf, val, src))
    default, rules = None, []
    for name, args, sub in directives(block):
        w = "%s: map %s `%s`" % (common_conf, val, name)
        if sub is not None or name in ("hostnames", "include"):
            stop(w + " — 흉내 밖")
        if name == "volatile" and not args:
            continue            # 값 캐시만 끈다 — 판정은 같다
        if len(args) != 1:
            stop(w + " — 줄 모양이 `<열쇠> <값>;` 이 아니다")
        if name == "default":
            default = to_sec(args[0], w)
        elif name.startswith("~"):
            ci = name.startswith("~*")
            pat = name[2:] if ci else name[1:]
            check_pattern(pat, w)
            rules.append({"re": pat, "ci": ci, "age": to_sec(args[0], w)})
        else:
            stop(w + " — 같은 글자 줄(정확 일치)은 흉내 밖")
    if default is None:
        stop("%s: map %s 에 default 가 없다" % (common_conf, val))
    return {"default": default, "rules": rules}


def asset_paths(assets_dir):
    out = []
    for dp, dn, fn in os.walk(assets_dir):
        dn.sort()
        for f in sorted(fn):
            out.append("/tbox/assets/" + os.path.relpath(os.path.join(dp, f), assets_dir).replace(os.sep, "/"))
    return out


def parse(site_conf, common_conf, assets_dir=None):
    site = os.path.basename(site_conf)
    found = []
    for sname, _, sblock in walk(tokens(site_conf)):
        if sname != "server" or sblock is None:
            continue
        locs = [(a, b) for n, a, b in directives(sblock) if n == "location" and b is not None]
        main = [(a, b) for a, b in locs if a in (["/tbox/assets/"], ["^~", "/tbox/assets/"])]
        if not main:
            continue
        if len(main) != 1:
            stop("%s: 한 server 에 location /tbox/assets/ 가 둘 이상이다" % site_conf)
        margs, mblock = main[0]
        val = block_expires(mblock, "%s: location %s" % (site_conf, " ".join(margs)))
        cfg = resolve(val, site_conf, common_conf)
        cfg["exact"], regex = {}, []
        for a, b in locs:
            if a == margs:
                continue
            if len(a) == 2 and a[0] == "=" and a[1].startswith("/tbox/assets/"):
                where = "%s: location = %s" % (site_conf, a[1])
                cfg["exact"][a[1]] = resolve(block_expires(b, where), where, common_conf)
            elif (len(a) == 1 and a[0].startswith("/tbox/assets/")) or (len(a) == 2 and a[0] == "^~" and a[1].startswith("/tbox/assets/")):
                stop("%s: /tbox/assets/ 보다 긴 접두 location(%s)이 있다 — 흉내 밖" % (site_conf, " ".join(a)))
            elif len(a) == 2 and a[0] in ("~", "~*"):
                regex.append((a[0] == "~*", a[1]))
        if assets_dir and margs[0] != "^~":
            for ci, pat in regex:
                check_pattern(pat, "%s: location %s %s" % (site_conf, "~*" if ci else "~", pat))
                hit = [p for p in asset_paths(assets_dir) if p not in cfg["exact"] and re.search(pat, p, re.I if ci else 0)]
                if hit:
                    stop("%s: 정규식 location `%s` 이 자산 주소 %s 에 걸린다 — nginx 는 정규식이 접두를 이긴다(흉내 밖)" % (site_conf, pat, hit[0]))
        cfg["from"] = ("expires %s (%s)" % (val, site) if not val.startswith("$") else
                       "expires %s (%s) → map $arg_v (%s)" % (val, site, os.path.basename(common_conf)))
        if cfg["exact"]:
            cfg["from"] += " · 정확 일치 %d곳" % len(cfg["exact"])
        found.append(cfg)
    if not found:
        stop("%s: location /tbox/assets/ 블록이 없다" % site_conf)
    key = [(c["default"], c["rules"], c["exact"]) for c in found]
    if any(k != key[0] for k in key[1:]):
        stop("%s: server 마다 자산 캐시 판정이 다르다" % site_conf)
    return found[0]


def age(cfg, v, path=None):
    c = cfg.get("exact", {}).get(path, cfg) if path else cfg
    if v:                       # 빈 값이면 nginx 는 정규식을 보지 않는다(위 머리 주석)
        for r in c["rules"]:
            if re.search(r["re"], v, re.I if r["ci"] else 0):
                return r["age"]
    return c["default"]


if __name__ == "__main__":
    if len(sys.argv) in (4, 5) and sys.argv[1] == "parse":
        print(json.dumps(parse(sys.argv[2], sys.argv[3], sys.argv[4] if len(sys.argv) == 5 else None), ensure_ascii=False))
    elif len(sys.argv) in (4, 5) and sys.argv[1] == "age":
        print(age(json.loads(sys.argv[2]), sys.argv[3], sys.argv[4] if len(sys.argv) == 5 else None))
    elif len(sys.argv) == 4 and sys.argv[1] == "same":
        a, b = (json.loads(x) for x in sys.argv[2:4])
        k = lambda c: (c["default"], c["rules"], c.get("exact", {}))
        sys.exit(0 if k(a) == k(b) else 1)
    else:
        print("쓰는 법: asset_cache.py parse <tbox-v2.conf> <tbox-common.conf> [<자산 폴더>] | age <JSON> <v 값> [<경로>] | same <JSON> <JSON>",
              file=sys.stderr)
        sys.exit(2)
