#!/usr/bin/env python3
# -*- coding: utf-8 -*-
# tests/community/embed_nginx_check.py — 임베드 틀 nginx 단계(C:T-188 첫 부분)를 **설정 글자로** 본다. 읽기만 한다.
#
# 부르는 쪽: tests/community/stage7c.sh (C:T-188). 혼자 돌려도 된다(파일만 읽는다).
# 쓰는 법: python3 embed_nginx_check.py <사이트 폴더(tbox-v2.conf·tbox-v2-local.conf 가 든)> <앱 뿌리(src/·public/ 이 든)>
#
# 무엇을 보나 — 두 파일(공개용·로컬용) 각각
#   ① 커뮤니티 페이지 화면 location `~ "^/community/p/<{id} 모양>$"` 이 **꼭 하나** — 모양은 public/index.php 의
#      TBOX_PATH_PATTERNS['{id}'] 에서 읽는다(라우터와 어긋나면 틀이 빈 채로 남는다)
#   ② 그 블록에 CSP 가 없다 · 나머지 보안 헤더는 server 단 add_header 와 **이름·값·always 가 모두 같다**
#      (nginx 는 location 안에 add_header 가 하나라도 있으면 위 단의 것을 모두 끊는다 — 하나라도 빠지면 그 헤더가 사라진다)
#   ③ FastCGI 줄이 단일 입구(`location = /index.php`)와 같은 곳으로 간다(소켓 · HTTPS · 읽기 시간 · X-Powered-By 숨김) ·
#      SCRIPT_FILENAME 은 `$document_root/index.php` 고정(다른 .php 를 실행할 길 0)
#   ④ `location = /index.php` 에는 여전히 add_header 가 없다 — 이 화면 말고는 nginx CSP 가 그대로 붙는다
#   ⑤ 공개용은 server 단에 HSTS 가 있고, 로컬용(평문 127.0.0.1)은 없다
#   ⑥ 두 파일의 이 블록은 HSTS 한 줄 말고 같다
#   그리고 앱 쪽
#   ⑦ 앱 기본 CSP(src/bootstrap.php 4절)가 nginx server 단 CSP 와 **같은 글자**다 — 이 화면에서 nginx CSP 를 빼도 잃는 조항이 없다
# 출력: 줄마다 ✔/✖ · 끝 줄 CHECK_FAIL=<수>
import io
import os
import re
import sys

fails = 0


def step(name, ok, detail=""):
    global fails
    if not ok:
        fails += 1
    print("  %s %s%s" % ("✔" if ok else "✖", name, ("  — " + detail) if detail else ""))
    return ok


def tokens(text):
    """nginx 낱말 규칙의 줄임판 — 주석(# 줄 끝까지, 따옴표 밖) · 따옴표 낱말 · { } ; · 나머지 낱말. (낱말, 줄 번호, 따옴표였나)"""
    out, i, n, line = [], 0, len(text), 1
    while i < n:
        c = text[i]
        if c == "\n":
            line += 1
            i += 1
        elif c.isspace():
            i += 1
        elif c == "#":
            while i < n and text[i] != "\n":
                i += 1
        elif c in "{};":
            out.append((c, line, False))
            i += 1
        elif c in "\"'":
            q, j, buf = c, i + 1, []
            while j < n and text[j] != q:
                if text[j] == "\\" and j + 1 < n:
                    buf.append(text[j + 1])
                    j += 2
                    continue
                if text[j] == "\n":
                    line += 1
                buf.append(text[j])
                j += 1
            out.append(("".join(buf), line, True))
            i = j + 1
        else:
            j = i
            while j < n and not text[j].isspace() and text[j] not in "{};" and text[j] != "#":
                j += 1
            out.append((text[i:j], line, False))
            i = j
    return out


def parse(toks):
    """[(이름, 인자들, 줄, 자식|None)] — 블록은 자식 목록을 가진다"""
    pos = 0

    def block():
        nonlocal pos
        items = []
        while pos < len(toks):
            t, ln, q = toks[pos]
            if t == "}" and not q:
                pos += 1
                return items
            words = []
            start = ln
            while pos < len(toks):
                t, ln, q = toks[pos]
                if not q and t == ";":
                    pos += 1
                    items.append((words[0], words[1:], start, None))
                    break
                if not q and t == "{":
                    pos += 1
                    items.append((words[0], words[1:], start, block()))
                    break
                words.append(t)
                pos += 1
        return items

    return block()


def headers(items):
    """이 단의 add_header → {소문자 이름: (값, always)}"""
    out = {}
    for name, args, _ln, kids in items:
        if name == "add_header" and kids is None and len(args) >= 2:
            out[args[0].lower()] = (args[1], len(args) >= 3 and args[2] == "always")
    return out


def directives(items, name):
    return [(args, ln) for n, args, ln, kids in items if n == name and kids is None]


def main():
    if len(sys.argv) != 3:
        print("쓰는 법: python3 embed_nginx_check.py <사이트 폴더> <앱 뿌리>")
        return 2
    sites, root = sys.argv[1], sys.argv[2]
    idx = io.open(os.path.join(root, "public", "index.php"), encoding="utf-8").read()
    m = re.search(r"'\{id\}'\s*=>\s*'([^']+)'", idx)
    step("라우터 {id} 모양을 읽었다(public/index.php)", m is not None)
    id_pat = m.group(1) if m else "(none)"
    want_pat = "^/community/p/" + id_pat + "$"
    boot = io.open(os.path.join(root, "src", "bootstrap.php"), encoding="utf-8").read()
    bm = re.search(r'header\("Content-Security-Policy: ([^"]+)"\);', boot)
    app_csp = bm.group(1) if bm else "(none)"
    step("앱 기본 CSP 를 읽었다(src/bootstrap.php 4절)", bm is not None)
    blocks = {}
    for fname in ("tbox-v2.conf", "tbox-v2-local.conf"):
        path = os.path.join(sites, fname)
        print("── %s" % fname)
        if not step("파일이 있다", os.path.isfile(path), path):
            continue
        tree = parse(tokens(io.open(path, encoding="utf-8").read()))
        servers = [kids for n, _a, _l, kids in tree if n == "server" and kids is not None]
        if not step("server 블록 하나", len(servers) == 1, str(len(servers))):
            continue
        srv = servers[0]
        sh = headers(srv)
        locs = [(args, ln, kids) for n, args, ln, kids in srv if n == "location" and kids is not None]
        pv = [(args, ln, kids) for args, ln, kids in locs if args[:1] == ["~"] and len(args) == 2 and args[1].startswith("^/community/p/")]
        if not step("① 커뮤니티 페이지 화면 location 이 꼭 하나", len(pv) == 1, str(len(pv))):
            continue
        args, ln, kids = pv[0]
        step("① 정규식 = 라우터 {id} 모양 그대로(%s)" % want_pat, args[1] == want_pat, "줄 %d: %s" % (ln, args[1]))
        bh = headers(kids)
        step("② CSP 를 적지 않았다(앱 CSP 하나만 남게)", "content-security-policy" not in bh)
        want = {k: v for k, v in sh.items() if k != "content-security-policy"}
        step("② server 단 CSP 가 있다(다른 경로는 그대로 받는다)", "content-security-policy" in sh)
        step("② 보안 헤더 이름이 server 단 목록(CSP 뺌)과 같다", sorted(bh) == sorted(want), "블록 %s · server %s" % (sorted(bh), sorted(want)))
        bad = [k for k in want if bh.get(k) != want[k]]
        step("② 값·always 도 같다", not bad and bool(want), ", ".join(bad))
        step("② 전부 always(4xx·5xx 에도)", all(a for _v, a in bh.values()) and bool(bh))
        idxloc = [kids2 for args2, _ln2, kids2 in locs if args2 == ["=", "/index.php"]]
        if step("③ 단일 입구 location = /index.php 가 있다", len(idxloc) == 1):
            il = idxloc[0]
            step("④ 단일 입구에는 add_header 가 없다(다른 화면은 nginx CSP 그대로)", not headers(il))
            for d in ("fastcgi_pass", "fastcgi_read_timeout", "fastcgi_hide_header", "include"):
                a1 = [a for a, _ in directives(kids, d)]
                a2 = [a for a, _ in directives(il, d)]
                step("③ %s 가 단일 입구와 같다" % d, a1 == a2 and bool(a1), "%s / %s" % (a1, a2))
            fp = {tuple(a[:1]): a[1:] for a, _ in directives(kids, "fastcgi_param")}
            step("③ SCRIPT_FILENAME = $document_root/index.php 고정", fp.get(("SCRIPT_FILENAME",)) == ["$document_root/index.php"], str(fp.get(("SCRIPT_FILENAME",))))
            fpi = {tuple(a[:1]): a[1:] for a, _ in directives(il, "fastcgi_param")}
            step("③ HTTPS 값이 단일 입구와 같다", fp.get(("HTTPS",)) == fpi.get(("HTTPS",)) and fp.get(("HTTPS",)) is not None, str(fp.get(("HTTPS",))))
        step("③ 블록 안에 다른 location·if·return·alias·root 없음",
             not [n for n, _a, _l, k in kids if k is not None or n in ("return", "alias", "root", "rewrite", "try_files")])
        if fname == "tbox-v2.conf":
            step("⑤ 공개용 server 단에 HSTS", "strict-transport-security" in sh)
            srv_csp = sh.get("content-security-policy", ("(none)", False))[0]
            step("⑦ 앱 기본 CSP = nginx server 단 CSP(같은 글자)", srv_csp == app_csp and app_csp != "(none)", "앱 %s | nginx %s" % (app_csp, srv_csp))
        else:
            step("⑤ 로컬용 server 단에 HSTS 없음(평문 127.0.0.1)", "strict-transport-security" not in sh)
        blocks[fname] = {k: v for k, v in bh.items() if k != "strict-transport-security"}, \
            [(n, a) for n, a, _l, k in kids if n != "add_header"]
    if len(blocks) == 2:
        a, b = blocks["tbox-v2.conf"], blocks["tbox-v2-local.conf"]
        step("⑥ 두 파일의 블록이 HSTS 말고 같다", a == b)
    print("CHECK_FAIL=%d" % fails)
    return 1 if fails else 0


if __name__ == "__main__":
    sys.exit(main())
