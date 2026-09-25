#!/usr/bin/env python3
# tests/community/browser_7c_frames.py — 틀 스위치를 켠 읽기 화면을 진짜 브라우저로 본다 (C:T-185 둘째 부분 · 사용자 결정 2026-09-24)
# 부르는 쪽: tests/community/stage7c.sh (임시 인스턴스 · 임시 DB — 복사본의 COMMUNITY_EMBED_FRAMES 를 켠 뒤).
# 쓰는 법: SMOKE_E1=<직원 쿠키> SMOKE_PE=<YouTube 임베드가 든 페이지> [SMOKE_SHOTS=폴더] python3 browser_7c_frames.py <SITE> <UA>
# ★ 바깥 사이트(시험 서버가 아닌 곳)로 가는 요청은 전부 브라우저가 끊는다 — 시험이 YouTube 에 닿지 않는다.
import os
import re
import sys

from playwright.sync_api import sync_playwright

SITE = sys.argv[1].rstrip("/")
UA = sys.argv[2]
E1 = os.environ["SMOKE_E1"]
PE = os.environ["SMOKE_PE"]
SHOTS = os.environ.get("SMOKE_SHOTS", "")
COMMUNITY = SITE + "/community"
SANDBOX = "allow-scripts allow-same-origin allow-presentation allow-popups"
fails = 0
pageerrors, console_errors, blocked = [], [], []


def step(name, ok, detail=""):
    global fails
    if not ok:
        fails += 1
    print("  %s %s%s" % ("✔" if ok else "✖", name, ("  — " + detail) if detail else ""))


def host():
    return re.match(r"https?://([^/:]+)", SITE).group(1)


def outside(route):
    blocked.append(route.request.url)
    route.abort()


with sync_playwright() as p:
    browser = p.chromium.launch()
    ctx = browser.new_context(user_agent=UA, viewport={"width": 1440, "height": 900})
    ctx.add_cookies([{"name": "tbox_session", "value": E1, "domain": host(), "path": "/",
                      "httpOnly": True, "secure": False, "sameSite": "Lax"}])
    ctx.route(re.compile(r"^https?://(?!" + re.escape(host()) + r"[:/]).*"), outside)
    page = ctx.new_page()
    page.on("pageerror", lambda e: pageerrors.append(str(e)))
    page.on("console", lambda m: console_errors.append(m.text)
            if m.type == "error" and "Failed to load resource" not in m.text and "ERR_FAILED" not in m.text else None)

    print("── 1. 읽기 화면 — 목록 안 임베드는 틀로")
    resp = page.goto(COMMUNITY + "/p/" + PE, wait_until="domcontentloaded")
    csp = (resp.headers.get("content-security-policy") or "") if resp else ""
    step("1 페이지 CSP 에 frame-src https://www.youtube.com", "frame-src" in csp and "https://www.youtube.com" in csp, csp[-120:])
    page.wait_for_selector("#page-doc iframe.doc-embed__frame", timeout=10000)
    fr = page.locator("#page-doc iframe.doc-embed__frame").first
    step("1 sandbox 토큰 — 사용자 결정 그대로", fr.get_attribute("sandbox") == SANDBOX, fr.get_attribute("sandbox") or "")
    step("1 referrerpolicy strict-origin", fr.get_attribute("referrerpolicy") == "strict-origin")
    step("1 allow — 전체 화면·PIP·암호화 미디어만", fr.get_attribute("allow") == "fullscreen; picture-in-picture; encrypted-media")
    step("1 src 는 저장한 주소 그대로", fr.get_attribute("src") == "https://www.youtube.com/embed/C7C-browser")
    step("1 설명(figcaption)", "브라우저 설명" in page.inner_text("#page-doc figure.doc-embed"))
    step("1 「허용 대기」 카드는 없다", "허용 대기" not in page.inner_text("#page-doc"))
    page.wait_for_timeout(500)
    step("1 바깥 요청은 끊겼다(시험이 YouTube 에 닿지 않는다)", any("youtube.com" in u for u in blocked), "%d건" % len(blocked))
    if SHOTS:
        os.makedirs(SHOTS, exist_ok=True)
        page.screenshot(path=os.path.join(SHOTS, "k5-frame-on.png"), full_page=True)
    page.set_viewport_size({"width": 390, "height": 844})
    page.wait_for_timeout(300)
    w = page.evaluate("() => [document.documentElement.scrollWidth, document.documentElement.clientWidth]")
    step("1 390px 가로 넘침 없음", w[0] <= w[1] + 1, "%d/%d" % (w[0], w[1]))
    page.set_viewport_size({"width": 1440, "height": 900})

    print("── 2. 편집기 안에서는 틀을 만들지 않는다")
    page.click("#page-edit")
    page.wait_for_selector("#page-editor .ProseMirror", timeout=8000)
    step("2 편집기 — 임베드 카드 하나 · iframe 0",
         page.locator("#page-editor .doc-atom[data-atom=embed]").count() == 1 and page.locator("#page-editor iframe").count() == 0)
    page.click("#page-done")
    page.wait_for_selector("#page-doc iframe.doc-embed__frame", timeout=10000)
    step("2 편집을 마치면 다시 틀(JS 렌더러도 같은 토큰)",
         page.locator("#page-doc iframe.doc-embed__frame").first.get_attribute("sandbox") == SANDBOX)
    ctx.close()
    browser.close()

print("── 끝. 브라우저가 본 오류")
step("페이지 오류 0", not pageerrors, "; ".join(pageerrors[:3]))
step("콘솔 오류 0(끊은 바깥 요청 줄은 뺀다)", not console_errors, "; ".join(console_errors[:3]))
print("SMOKE_FAIL=%d" % fails)
sys.exit(1 if fails else 0)
