#!/usr/bin/env python3
# tests/community/browser_7c_nginx.py — **nginx 를 거친** 커뮤니티 페이지 화면에서 임베드 틀이 실제로 열리는가 (C:T-188 둘째 부분)
# 부르는 쪽: tests/community/stage7c.sh C:T-188 (임시 인스턴스 앞에 이 사용자로 띄운 임시 nginx + 임시 php-fpm — 운영 nginx·FPM 접촉 0).
# 쓰는 법: SMOKE_E1=<직원 쿠키> SMOKE_PX=<틀·목록 밖·자기 주소 임베드가 든 페이지> SMOKE_SELF=<사이트 호스트>
#          SMOKE_TPL=<임베드가 든 템플릿 이름> [SMOKE_SHOTS=폴더] python3 browser_7c_nginx.py <SITE_AFTER> <SITE_BEFORE> <UA>
#   SITE_AFTER  = 임베드 nginx 판(페이지 화면 location — CSP 는 앱 것 하나)
#   SITE_BEFORE = 같은 파일에서 그 location 만 뺀 판(= 보안 넷 판 — nginx CSP + 앱 CSP 둘)
# ★ 바깥 사이트로 가는 요청은 전부 브라우저가 가로챈다 — YouTube 틀 주소는 **이 스크립트가 만든 시험용 글**로 답하고(바깥에 닿지 않음),
#   그 밖의 바깥 주소는 끊는다. 「틀이 열렸다」 = 브라우저가 CSP 검사를 지나 그 주소를 요청했고 시험용 글이 틀 안에 그려졌다.
# 보는 것: 응답 CSP 머리글 수 · 틀 속성(sandbox 토큰·referrerpolicy·allow·loading) · CSP 위반 이벤트 · 목록 밖/자기 주소 → 「허용 목록 밖의 임베드」 ·
#          홈 「템플릿에서」 미리보기는 틀 없이 카드 · 편집기 안은 틀 0 · 412·1280 × 라이트·다크 화면 사진
import os
import re
import sys

from playwright.sync_api import sync_playwright

AFTER = sys.argv[1].rstrip("/")
BEFORE = sys.argv[2].rstrip("/")
UA = sys.argv[3]
E1 = os.environ["SMOKE_E1"]
PX = os.environ["SMOKE_PX"]
SELF = os.environ.get("SMOKE_SELF", "")
TPL = os.environ.get("SMOKE_TPL", "")
SHOTS = os.environ.get("SMOKE_SHOTS", "")
SANDBOX = "allow-scripts allow-same-origin allow-presentation allow-popups"
YT = "https://www.youtube.com/embed/C7C-nginx"
STUB_MARK = "C7C-시험용-바깥-틀"
STUB = ("<!doctype html><html lang=ko><head><meta charset=utf-8><title>stub</title><style>"
        "html,body{margin:0;height:100%;background:#2f6f4e;color:#fff;font:600 18px system-ui,sans-serif}"
        "body{display:flex;align-items:center;justify-content:center;text-align:center}</style></head>"
        "<body><p>" + STUB_MARK + "<br>(바깥 요청 대신 시험 브라우저가 돌려준 글)</p></body></html>")
CSPV_INIT = ("window.__cspv = [];"
             "document.addEventListener('securitypolicyviolation', function (e) {"
             " window.__cspv.push([e.violatedDirective || e.effectiveDirective || '', e.blockedURI || '']); });")
fails = 0
pageerrors, console_errors = [], []
PHASE = {"now": "after"}   # 콘솔 줄을 단계별로 — 대조군(before)에서는 틀 CSP 위반 줄이 **나와야** 한다


def step(name, ok, detail=""):
    global fails
    if not ok:
        fails += 1
    print("  %s %s%s" % ("✔" if ok else "✖", name, ("  — " + detail) if detail else ""))
    return ok


def host(site):
    return re.match(r"https?://([^/:]+)", site).group(1)


def make_ctx(browser, site, fulfilled, aborted, width=1280):
    ctx = browser.new_context(user_agent=UA, viewport={"width": width, "height": 900})
    ctx.add_cookies([{"name": "__Host-tbox_session", "value": E1, "domain": host(site), "path": "/",
                      "httpOnly": True, "secure": True, "sameSite": "Lax"}])

    def outside(route):
        u = route.request.url
        if u.startswith("https://www.youtube.com/embed/"):
            fulfilled.append(u)
            route.fulfill(status=200, content_type="text/html; charset=utf-8", body=STUB)
        else:
            aborted.append(u)
            route.abort()
    ctx.route(re.compile(r"^https?://(?!" + re.escape(host(site)) + r"[:/]).*"), outside)
    ctx.add_init_script(CSPV_INIT)
    page = ctx.new_page()
    page.on("pageerror", lambda e: pageerrors.append(str(e)))
    page.on("console", lambda m: console_errors.append((PHASE["now"], m.text))
            if m.type == "error" and "Failed to load resource" not in m.text and "ERR_FAILED" not in m.text else None)
    return ctx, page


FRAME_CSP_RE = re.compile(r"(Refused to frame|Framing) '?https://www\.youtube\.com/?'? .*Content Security Policy")


def frame_src_of(csp):
    """CSP 문자열의 frame-src 호스트 목록(없으면 None)"""
    for part in csp.split(";"):
        w = part.strip().split()
        if w and w[0] == "frame-src":
            return w[1:]
    return None


def csp_values(resp):
    return [h["value"] for h in resp.headers_array() if h["name"].lower() == "content-security-policy"] if resp else []


def frame_violations(page):
    return [v for v in page.evaluate("() => window.__cspv || []") if v[0].startswith("frame-src") or v[0].startswith("child-src")]


def stub_frame(page):
    for f in page.frames:
        if f.url.startswith("https://www.youtube.com/embed/"):
            try:
                return f.inner_text("body")
            except Exception as e:  # noqa: BLE001 — 틀 문서를 못 읽으면 빈 값으로 판정한다
                return "(읽지 못함: %s)" % e
    return ""


def shot(page, name, theme):
    if not SHOTS:
        return
    os.makedirs(SHOTS, exist_ok=True)
    page.evaluate("t => document.documentElement.setAttribute('data-theme', t)", theme)
    page.wait_for_timeout(250)
    page.screenshot(path=os.path.join(SHOTS, name + ".png"), full_page=True)


with sync_playwright() as p:
    browser = p.chromium.launch()

    print("── 1. 임베드 nginx 판(%s) — 페이지 화면의 CSP 는 앱 것 하나 · 틀이 실제로 열린다" % AFTER)
    fulfilled, aborted = [], []
    ctx, page = make_ctx(browser, AFTER, fulfilled, aborted)
    resp = page.goto(AFTER + "/community/p/" + PX, wait_until="domcontentloaded")
    csps = csp_values(resp)
    step("1 응답 CSP 머리글 하나", len(csps) == 1, "%d개" % len(csps))
    fs = frame_src_of(csps[0]) if csps else None
    step("1 그 CSP 의 frame-src 에 https://www.youtube.com (앱이 더한 것)", fs is not None and "https://www.youtube.com" in fs, str(fs))
    step("1 기본 조항은 그대로(frame-ancestors 'none' · script-src 'self')",
         bool(csps) and "frame-ancestors 'none'" in csps[0] and "script-src 'self'" in csps[0])
    if SELF:
        step("1 자기 주소는 frame-src 에 없다", fs is not None and ("https://" + SELF) not in fs, str(fs))
    page.wait_for_selector("#page-doc iframe.doc-embed__frame", timeout=10000)
    frames = page.locator("#page-doc iframe.doc-embed__frame")
    step("1 틀은 하나(목록 안 YouTube 만 — 목록 밖·자기 주소는 틀이 아니다)", frames.count() == 1, str(frames.count()))
    fr = frames.first
    step("1 sandbox 토큰 — 사용자 결정 그대로", fr.get_attribute("sandbox") == SANDBOX, fr.get_attribute("sandbox") or "")
    step("1 referrerpolicy strict-origin", fr.get_attribute("referrerpolicy") == "strict-origin")
    step("1 allow — 전체 화면·PIP·암호화 미디어만", fr.get_attribute("allow") == "fullscreen; picture-in-picture; encrypted-media")
    step("1 loading lazy · src 는 저장한 주소", fr.get_attribute("loading") == "lazy" and fr.get_attribute("src") == YT, fr.get_attribute("src") or "")
    try:
        page.wait_for_function("() => [...document.querySelectorAll('iframe.doc-embed__frame')].length > 0", timeout=3000)
        page.wait_for_timeout(1200)
    except Exception:  # noqa: BLE001
        pass
    step("1 브라우저가 틀 주소를 요청했다(CSP 를 지났다 — 시험용 글로 답함, 바깥 요청 0)", any(u.startswith(YT) for u in fulfilled), "%d건" % len(fulfilled))
    body = stub_frame(page)
    step("1 틀 안에 시험용 글이 그려졌다(빈 틀이 아니다)", STUB_MARK in body, body[:60])
    step("1 CSP 위반(frame-src) 0", not frame_violations(page), str(frame_violations(page)))
    doc_text = page.inner_text("#page-doc")
    step("1 목록 밖 주소 → 「허용 목록 밖의 임베드」", doc_text.count("허용 목록 밖의 임베드") >= (2 if SELF else 1), "%d번" % doc_text.count("허용 목록 밖의 임베드"))
    step("1 목록 밖 카드에는 호스트만(링크 0)", "evil.example" in doc_text
         and page.locator("#page-doc .doc-embed a[href*='evil.example']").count() == 0)
    if SELF:
        step("1 자기 주소(표에 있어도) → 「허용 목록 밖」 카드 · 링크·틀 0",
             SELF in doc_text and page.locator("#page-doc .doc-embed a[href*='%s']" % SELF).count() == 0
             and page.locator("#page-doc iframe[src*='%s']" % SELF).count() == 0)
    step("1 바깥 주소 중 끊은 것(YouTube 틀 말고) 0 — 페이지가 다른 바깥 자원을 부르지 않는다", not aborted, "; ".join(aborted[:3]))
    shot(page, "k8-nginx-1280-light", "light")
    shot(page, "k8-nginx-1280-dark", "dark")
    page.set_viewport_size({"width": 412, "height": 915})
    page.wait_for_timeout(400)
    w = page.evaluate("() => [document.documentElement.scrollWidth, document.documentElement.clientWidth]")
    step("1 412px 가로 넘침 없음", w[0] <= w[1] + 1, "%d/%d" % (w[0], w[1]))
    fw = page.evaluate("() => { const f = document.querySelector('iframe.doc-embed__frame'); if (!f) return [0, 0]; const r = f.getBoundingClientRect(); return [Math.round(r.width), Math.round(r.right)]; }")
    step("1 412px — 틀이 화면 안(폭 > 0 · 오른쪽 끝 ≤ 화면)", fw[0] > 0 and fw[1] <= w[1] + 1, "폭 %d · 오른쪽 %d · 화면 %d" % (fw[0], fw[1], w[1]))
    shot(page, "k8-nginx-412-light", "light")
    shot(page, "k8-nginx-412-dark", "dark")
    page.set_viewport_size({"width": 1280, "height": 900})
    page.evaluate("() => document.documentElement.setAttribute('data-theme', 'light')")

    print("── 2. 편집기 안에서는 틀을 만들지 않는다(nginx 경유) · 임베드 창의 「허용된 사이트」는 서버가 거른 hosts")
    page.click("#page-edit")
    page.wait_for_selector("#page-editor .ProseMirror", timeout=8000)
    step("2 편집기 — iframe 0", page.locator("#page-editor iframe").count() == 0)
    page.click("#page-add-block")
    page.wait_for_selector("#block-dialog[open]", timeout=5000)
    page.locator("#block-list .block-pick", has_text="임베드").first.click()
    page.locator("#block-dialog button", has_text="넣기").last.click()
    page.wait_for_selector("#atom-dialog[open] #atom-embed:not([hidden])", timeout=5000)
    page.wait_for_function("() => document.getElementById('atom-embed-hosts').textContent.includes('www.youtube.com')", timeout=8000)
    hint = page.inner_text("#atom-embed-hosts")
    step("2 임베드 창 안내 — 목록 안 사이트는 보인다(www.youtube.com)", "www.youtube.com" in hint, hint)
    if SELF:
        step("2 임베드 창 안내 — 자기 주소는 표에 있어도 「허용됨」으로 보이지 않는다(hosts)", SELF not in hint, hint)
    page.keyboard.press("Escape")
    page.wait_for_function("() => !document.getElementById('atom-dialog').open", timeout=5000)
    step("2 창을 닫아도 블록이 늘지 않았다(임베드 카드 셋 그대로)",
         page.locator("#page-editor .doc-atom[data-atom=embed]").count() == (3 if SELF else 2),
         str(page.locator("#page-editor .doc-atom[data-atom=embed]").count()))
    page.click("#page-done")
    page.wait_for_selector("#page-doc iframe.doc-embed__frame", timeout=10000)
    step("2 편집을 마치면 다시 틀 하나", page.locator("#page-doc iframe.doc-embed__frame").count() == 1)

    if TPL:
        print("── 3. 홈 「템플릿에서」 미리보기 — 홈 CSP 에는 frame-src 가 없으므로 틀 없이 카드")
        resp = page.goto(AFTER + "/community/", wait_until="networkidle")
        hc = csp_values(resp)
        step("3 홈 응답 CSP 는 둘(nginx + 앱) · frame-src 없음", len(hc) == 2 and not any("frame-src" in c for c in hc), "%d개" % len(hc))
        if not page.evaluate("() => { const m = document.getElementById('community-more'); return !!m && m.open; }"):
            page.click("#community-more > summary")
        page.click("#community-template-new")
        page.wait_for_selector("#template-dialog[open]", timeout=5000)
        page.wait_for_function("() => document.querySelectorAll('#template-body tr').length > 0", timeout=8000)
        row = page.locator("#template-body tr", has_text=TPL).first
        step("3 임베드가 든 템플릿이 목록에 있다", row.count() == 1, TPL)
        row.locator("button", has_text="보기").click()
        page.wait_for_selector("#template-preview:not([hidden])", timeout=5000)
        page.wait_for_timeout(500)
        prev = page.inner_text("#template-preview-doc")
        step("3 미리보기 — iframe 0", page.locator("#template-preview-doc iframe").count() == 0)
        step("3 미리보기 — 「임베드 — www.youtube.com」 카드 · 「허용 대기 · 새 탭에서 열기」", "임베드 — www.youtube.com" in prev and "허용 대기" in prev, prev[:120])
        step("3 미리보기 — CSP 위반(frame-src) 0", not frame_violations(page), str(frame_violations(page)))
        shot(page, "k8-gallery-preview-1280-light", "light")
        page.evaluate("() => document.documentElement.setAttribute('data-theme', 'light')")
    ctx.close()

    print("── 4. 대조군 — 같은 파일에서 페이지 화면 location 만 뺀 판(%s): nginx CSP 가 함께 와 틀이 막힌다" % BEFORE)
    PHASE["now"] = "before"
    fulfilled_b, aborted_b = [], []
    ctx, page = make_ctx(browser, BEFORE, fulfilled_b, aborted_b)
    resp = page.goto(BEFORE + "/community/p/" + PX, wait_until="domcontentloaded")
    csps = csp_values(resp)
    step("4 응답 CSP 머리글 둘(nginx 것 + 앱 것)", len(csps) == 2, "%d개" % len(csps))
    step("4 그중 하나만 frame-src 를 가진다", sum(1 for c in csps if "frame-src" in c) == 1)
    page.wait_for_selector("#page-doc iframe.doc-embed__frame", timeout=10000)
    page.wait_for_timeout(1500)
    fv = frame_violations(page)
    step("4 CSP 위반 frame-src(바깥 틀 주소) ≥ 1 — nginx CSP 가 막았다", any("youtube.com" in v[1] for v in fv), str(fv[:2]))
    step("4 틀 주소 요청 0(브라우저가 CSP 에서 멈춤)", not fulfilled_b, "%d건" % len(fulfilled_b))
    step("4 틀 안 시험용 글 없음(빈 틀)", STUB_MARK not in stub_frame(page))
    shot(page, "k8-before-1280-light", "light")
    ctx.close()
    browser.close()

print("── 끝. 브라우저가 본 오류")
step("페이지 오류 0", not pageerrors, "; ".join(pageerrors[:3]))
after_err = [t for ph, t in console_errors if ph == "after"]
before_frame = [t for ph, t in console_errors if ph == "before" and FRAME_CSP_RE.search(t)]
before_other = [t for ph, t in console_errors if ph == "before" and not FRAME_CSP_RE.search(t)]
step("콘솔 오류 0 — 임베드 판(끊은 바깥 요청 줄은 뺀다)", not after_err, "; ".join(after_err[:3]))
step("대조군 콘솔 — 틀 CSP 위반 줄이 있다(브라우저가 막았다는 두 번째 증거)", bool(before_frame), (before_frame[:1] or [""])[0][:160])
step("대조군 콘솔 — 그 밖의 오류 0", not before_other, "; ".join(before_other[:3]))
print("SMOKE_FAIL=%d" % fails)
sys.exit(1 if fails else 0)
