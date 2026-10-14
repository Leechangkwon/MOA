#!/usr/bin/env python3
# tests/community/browser_7d1.py — 7-D1 「많이 읽힌 문서」 창을 진짜 브라우저로 눌러 본다 (C:T-193)
# 부르는 쪽: tests/community/stage7d1.sh (임시 인스턴스 · 임시 DB). 쓰는 법: SMOKE_A1=<관리자 쿠키(재인증 뒤)> SMOKE_E1=<직원 쿠키> SMOKE_TITLE=<목록에 있어야 할 제목> python3 browser_7d1.py <SITE> <UA>
import os
import re
import sys

from playwright.sync_api import sync_playwright

SITE = sys.argv[1].rstrip("/")
UA = sys.argv[2]
A1 = os.environ["SMOKE_A1"]
E1 = os.environ["SMOKE_E1"]
M1 = os.environ.get("SMOKE_M1", "")
STALE = os.environ.get("SMOKE_A1_STALE", "")
PW = os.environ.get("SMOKE_A1_PW", "")
TITLE = os.environ["SMOKE_TITLE"]
SHOTS = os.environ.get("SMOKE_SHOTS", "")
COMMUNITY = SITE + "/community"
fails = 0
pageerrors, console_errors, http_bad = [], [], []


def open_home_more(page):
    """커뮤니티 홈 머리의 「더보기」(DESIGN 2.9 · 2.15, 2026-09-25)를 연다 — 안의 단추는 메뉴가 열려야 보이고 눌린다"""
    if not page.evaluate("() => { const m = document.getElementById('community-more'); return !!m && m.open; }"):
        page.click("#community-more > summary")


def home_btn_shown(page, sel):
    """홈 메뉴 안 단추가 「있어야 할 때 있는가」 — 메뉴가 닫혀 있어도 창구의 can_manage 로 정해진 hidden 만 본다"""
    return page.evaluate("s => { const e = document.querySelector(s); return !!e && !e.hidden; }", sel)


def step(name, ok, detail=""):
    global fails
    if not ok:
        fails += 1
    print("  %s %s%s" % ("✔" if ok else "✖", name, ("  — " + detail) if detail else ""))


def host():
    return re.match(r"https?://([^/:]+)", SITE).group(1)


def context(browser, session):
    ctx = browser.new_context(user_agent=UA, viewport={"width": 1440, "height": 900})
    ctx.add_cookies([{"name": "__Host-tbox_session", "value": session, "domain": host(), "path": "/",
                      "httpOnly": True, "secure": True, "sameSite": "Lax"},
                     {"name": "qaredi_sidebar", "value": "open", "domain": host(), "path": "/"}])  # 카페 C5 — 펼친 셸을 잰다(쿠키 qaredi_sidebar 없으면 접힘이 기본)
    page = ctx.new_page()
    page.on("pageerror", lambda e: pageerrors.append(str(e)))
    # 「Failed to load resource」 는 응답 코드마다 브라우저가 찍는 줄 — HTTP 실패는 아래 http_bad 가 따로 센다(의도한 재인증 403 만 뺀다)
    page.on("console", lambda m: console_errors.append(m.text)
            if m.type == "error" and "inline style" not in m.text and "Failed to load resource" not in m.text else None)
    page.on("response", lambda r: http_bad.append("%d %s" % (r.status, r.url)) if r.status >= 400 and host() in r.url else None)
    return ctx, page


with sync_playwright() as p:
    browser = p.chromium.launch()
    print("── 1. 직원 — 단추 없음")
    ctx, page = context(browser, E1)
    page.goto(COMMUNITY + "/", wait_until="networkidle")
    page.wait_for_timeout(300)
    page.wait_for_function("() => document.querySelectorAll('#nav-community-boards a').length > 0", timeout=8000)  # 게시판 창구 응답(단추 판단과 같은 창구)이 온 뒤
    step("1 직원 홈에는 「많이 읽힌 문서」 단추가 없다(창구 응답 뒤)", not home_btn_shown(page, "#community-popular"))
    ctx.close()
    if M1:
        ctx, page = context(browser, M1)
        page.goto(COMMUNITY + "/", wait_until="networkidle")
        page.wait_for_function("() => document.querySelectorAll('#nav-community-boards a').length > 0", timeout=8000)
        step("1 매니저 홈에도 단추가 없다", not home_btn_shown(page, "#community-popular"))
        ctx.close()

    print("── 2. 관리자 — 창 · 목록")
    ctx, page = context(browser, A1)
    page.goto(COMMUNITY + "/", wait_until="networkidle")
    page.wait_for_selector("#community-popular:not([hidden])", state="attached", timeout=8000)
    open_home_more(page)
    page.click("#community-popular")
    page.wait_for_selector("#popular-dialog[open]", timeout=5000)
    page.wait_for_function("() => document.querySelectorAll('#popular-body tr').length > 0", timeout=8000)
    txt = page.inner_text("#popular-body")
    step("2 목록에 사내 공개 페이지", TITLE in txt, txt[:120])
    step("2 설명 — 30일 · 사내 공개만 · 검토 계정 제외", "30일" in page.inner_text("#popular-meta") and "검토 계정" in page.inner_text("#popular-meta"))
    body_html_imgs = page.locator("#popular-body img").count()
    step("2 제목의 태그는 글자로만(img 요소 0 · 글자 「<img」 가 보인다)", body_html_imgs == 0 and "<img src=x" in txt)
    link = page.locator("#popular-body a", has_text=TITLE).first
    step("2 제목은 페이지 링크", (link.get_attribute("href") or "").startswith("/community/p/"))
    if SHOTS:
        page.screenshot(path=os.path.join(SHOTS, "l1-popular.png"))
    page.set_viewport_size({"width": 390, "height": 844})
    page.wait_for_timeout(300)
    w = page.evaluate("() => [document.documentElement.scrollWidth, document.documentElement.clientWidth]")
    step("2 390px 가로 넘침 없음", w[0] <= w[1] + 1, "%d/%d" % tuple(w))
    ctx.close()

    if STALE and PW:
        print("── 3. 재인증 안 한 관리자 — 셸 재인증 창 → 비밀번호 → 목록")
        ctx, page = context(browser, STALE)
        page.goto(COMMUNITY + "/", wait_until="networkidle")
        page.wait_for_selector("#community-popular:not([hidden])", state="attached", timeout=8000)
        open_home_more(page)
        page.click("#community-popular")
        page.wait_for_selector("#reauth-dialog-form input[type=password]", timeout=8000)
        step("3 403 next=reauth → 셸 재인증 창이 뜬다", page.locator("#reauth-dialog-form").count() == 1)
        page.fill("#reauth-dialog-form input[type=password]", PW)
        page.press("#reauth-dialog-form input[type=password]", "Enter")
        page.wait_for_selector("#popular-dialog[open]", timeout=8000)
        page.wait_for_function("() => document.querySelectorAll('#popular-body tr').length > 0", timeout=8000)
        step("3 재인증 뒤 창이 다시 열리고 목록이 그려진다", TITLE in page.inner_text("#popular-body"))
        ctx.close()

    print("── 끝. 브라우저가 본 오류")
    # 재인증 흐름의 첫 403(의도한 것)은 빼고 센다
    http_bad[:] = [x for x in http_bad if not (x.startswith("403 ") and x.endswith("/community/api/admin/popular"))]
    for name, items in (("pageerror", pageerrors), ("console error", console_errors), ("HTTP>=400", http_bad)):
        print("   %-16s %d건%s" % (name, len(items), (" — " + " | ".join(items[:3])) if items else ""))
    step("pageerror 0", len(pageerrors) == 0)
    step("콘솔 오류 0", len(console_errors) == 0)
    step("HTTP 400 이상 0", len(http_bad) == 0)
    browser.close()

print("SMOKE_FAIL=%d" % fails)
sys.exit(1 if fails else 0)
