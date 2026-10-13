#!/usr/bin/env python3
# tests/community/browser_5d.py — 5-D 화면을 **진짜 브라우저로 눌러 본다** (C:T-105)
#
# 요구사항: 5-D 지시서 「완료 기준」 브라우저 줄 — 댓글 쓰기·@ 후보·답글·인용·고치기·지우기 확인 · 알림 종 · 공지 읽음.
# 부르는 쪽: tests/community/stage5d.sh (임시 인스턴스 · 임시 DB — 운영에는 닿지 않는다)
#
# 쓰는 법: SMOKE_E1=… SMOKE_E2=… SMOKE_ADMIN=… SMOKE_PI=<사내 페이지> SMOKE_NP=<공지 글> [SMOKE_SHOTS=폴더]
#          python3 browser_5d.py <SITE> <UA>
import os
import re
import sys

from playwright.sync_api import sync_playwright

SITE = sys.argv[1].rstrip("/")
UA = sys.argv[2]
E1 = os.environ["SMOKE_E1"]
E2 = os.environ["SMOKE_E2"]
ADMIN = os.environ["SMOKE_ADMIN"]
PI = os.environ["SMOKE_PI"]
NP = os.environ["SMOKE_NP"]
SHOTS = os.environ.get("SMOKE_SHOTS", "")
COMMUNITY = SITE + "/community"
XSS = 'C5D-브라우저 <img src=x onerror=alert(1)> 댓글'

fails = 0
pageerrors, console_errors, dialogs, http_bad = [], [], [], []
CSP_STYLE_RE = re.compile(r"Applying inline style violates")


def step(name, ok, detail=""):
    global fails
    if not ok:
        fails += 1
    print("  %s %s%s" % ("✔" if ok else "✖", name, ("  — " + detail) if detail else ""))
    return ok


def host():
    return re.match(r"https?://([^/:]+)", SITE).group(1)


def context(browser, session):
    ctx = browser.new_context(user_agent=UA, viewport={"width": 1440, "height": 900})
    ctx.add_cookies([{"name": "__Host-tbox_session", "value": session, "domain": host(), "path": "/",
                      "httpOnly": True, "secure": True, "sameSite": "Lax"}])
    page = ctx.new_page()
    page.on("pageerror", lambda e: pageerrors.append(str(e)))
    page.on("console", lambda m: console_errors.append(m.text)
            if m.type == "error" and not CSP_STYLE_RE.search(m.text) else None)

    def on_dialog(d):
        dialogs.append(d.message)
        d.dismiss()
    page.on("dialog", on_dialog)
    page.on("response", lambda r: http_bad.append("%d %s" % (r.status, r.url))
            if r.status >= 400 and host() in r.url else None)
    return ctx, page


def shots(page, name):
    if not SHOTS:
        return
    os.makedirs(SHOTS, exist_ok=True)
    for theme in ("light", "dark"):
        page.evaluate("t => document.documentElement.setAttribute('data-theme', t)", theme)
        page.wait_for_timeout(150)
        page.screenshot(path=os.path.join(SHOTS, "%s-%s.png" % (name, theme)), full_page=True)
    page.evaluate("() => document.documentElement.setAttribute('data-theme', 'light')")
    page.set_viewport_size({"width": 390, "height": 844})
    page.wait_for_timeout(300)
    page.screenshot(path=os.path.join(SHOTS, name + "-390.png"), full_page=True)
    w = page.evaluate("() => [document.documentElement.scrollWidth, document.documentElement.clientWidth]")
    step("%s — 390px 가로 넘침 없음" % name, w[0] <= w[1] + 1, "%d/%d" % (w[0], w[1]))
    page.set_viewport_size({"width": 1440, "height": 900})
    page.wait_for_timeout(200)


with sync_playwright() as pw:
    browser = pw.chromium.launch()

    print("── 1. employee02 — 사내 페이지에 댓글 · @ 후보 · 인용 · 답글 · 고치기 · 지우기")
    ctx, page = context(browser, E2)
    page.goto(COMMUNITY + "/p/" + PI, wait_until="networkidle")
    page.wait_for_selector("#page-comments-wrap:not([hidden])", timeout=10000)
    step("1 댓글 절 · 입력 칸", page.locator("#comment-form").is_visible())
    before = page.locator("#page-comments > li").count()
    page.fill("#comment-input", XSS + " @claude_employee0")
    page.locator("#comment-input").press("End")
    page.keyboard.type("1")
    page.wait_for_selector('#comment-mentions [data-mention="claude_employee01"]', timeout=5000)
    # Q:F-12 U4-4(DESIGN 2.27) — 후보 목록은 입력 칸 바로 아래 **떠 있는** 목록(멘션 입력 부품 · id 는 그대로)으로 바뀌었다(옛 단언: 칸 안 static)
    step("1 @ 후보에 페이지 주인 · 입력 칸 아래 떠 있는 목록(U4-4)",
         page.evaluate("() => getComputedStyle(document.getElementById('comment-mentions')).position") in ("fixed", "absolute"))
    page.click('#comment-mentions [data-mention="claude_employee01"]')
    step("1 후보를 누르면 @아이디 가 들어간다", "@claude_employee01 " in page.input_value("#comment-input"))
    # 인용 — 본문 첫 블록의 글자를 고른다
    has_text = page.evaluate("""() => { const el = document.querySelector('#page-doc [id^="block-"]');
        if (!el || !el.firstChild) return false; const r = document.createRange(); r.selectNodeContents(el);
        const s = getSelection(); s.removeAllRanges(); s.addRange(r); return String(s).trim() !== ''; }""")
    if has_text:
        page.click("#comment-quote-pick")
        step("1 「고른 문구 인용」 → 인용 줄", page.locator("#comment-quote").is_visible())
    shots(page, "d1-compose")
    page.click("#comment-submit")
    page.wait_for_function("n => document.querySelectorAll('#page-comments > li').length > n", arg=before, timeout=8000)
    last = page.locator("#page-comments > li").last
    step("1 새 댓글 — 본문이 글자 그대로(img 요소 0)", XSS in last.inner_text()
         and page.locator("#page-comments img").count() == 0)
    last.get_by_role("button", name="고치기").click()
    page.locator("#page-comments textarea").fill("C5D-브라우저 고친 댓글")
    # 정확 일치 — 7-B 의 「템플릿으로 저장」 단추와 겹치지 않게
    page.get_by_role("button", name="저장", exact=True).click()
    page.wait_for_function("() => document.getElementById('page-comments').textContent.includes('C5D-브라우저 고친 댓글')", timeout=8000)
    step("1 그 자리 고치기 → 저장", True)
    first = page.locator("#page-comments > li:not(.is-reply)").filter(
        has=page.get_by_role("button", name="답글")).first
    first.get_by_role("button", name="답글").click()
    step("1 「답글」 → 답글 안내 줄", page.locator("#comment-reply-to").is_visible())
    page.fill("#comment-input", "C5D-브라우저 답글")
    page.click("#comment-submit")
    page.wait_for_function("() => [...document.querySelectorAll('#page-comments li.is-reply')]"
                           ".some(li => li.textContent.includes('C5D-브라우저 답글'))", timeout=8000)
    step("1 답글은 한 단 들여 보인다", page.locator("#page-comments li.is-reply", has_text="C5D-브라우저 답글").count() == 1)
    mine = page.locator("#page-comments > li", has_text="C5D-브라우저 고친 댓글")
    mine.get_by_role("button", name="지우기").click()
    step("1 지우기 한 번 → 「정말 지우기」 (바로 지우지 않는다)", mine.get_by_role("button", name="정말 지우기").count() == 1)
    mine.get_by_role("button", name="정말 지우기").click()
    page.wait_for_function("() => !document.getElementById('page-comments').textContent.includes('C5D-브라우저 고친 댓글')", timeout=8000)
    step("1 두 번째에 지워진다", True)
    shots(page, "d1-comments")
    ctx.close()

    print("── 2. employee01 — 알림 종 · 알림 창")
    ctx, page = context(browser, E1)
    page.goto(COMMUNITY + "/", wait_until="networkidle")
    page.wait_for_selector("#nav-community-bell-count:not([hidden])", timeout=10000)
    step("2 종에 안 읽은 수", int(page.locator("#nav-community-bell-count").inner_text().strip() or "0") > 0)
    page.click("#nav-community-bell")
    page.wait_for_selector("#notify-dialog[open] #notify-list li", timeout=8000)
    step("2 알림 창 — 「나를 불렀습니다」 줄", "나를 불렀습니다" in page.locator("#notify-list").inner_text())
    shots(page, "d2-notify")
    page.click("#notify-all-read")
    page.wait_for_function("() => document.getElementById('nav-community-bell-count').hidden", timeout=8000)
    step("2 모두 읽음 → 수가 사라진다", True)
    page.locator("#notify-dialog [data-notify-close]").first.click()
    ctx.close()

    print("── 3. admin — 공지 「읽은 사람 n/N」")
    ctx, page = context(browser, ADMIN)
    page.goto(COMMUNITY + "/p/" + NP, wait_until="networkidle")
    page.wait_for_selector("#page-reads:not([hidden])", timeout=10000)
    step("3 「읽은 사람 n / N」", re.match(r"읽은 사람 \d+ / \d+", page.locator("#page-reads").inner_text().strip()) is not None)
    ctx.close()

    print("── 끝. 브라우저가 본 오류")
    for name, items in (("pageerror", pageerrors), ("console error", console_errors),
                        ("alert/confirm", dialogs), ("HTTP>=400", http_bad)):
        print("   %-16s %d건%s" % (name, len(items), (" — " + " | ".join(items[:3])) if items else ""))
    step("pageerror 0", len(pageerrors) == 0)
    step("콘솔 오류 0", len(console_errors) == 0)
    step("alert·prompt·confirm 0 (창을 띄우지 않고, <img onerror> 가 실행되지 않았다)", len(dialogs) == 0)
    step("HTTP 400 이상 0", len(http_bad) == 0)
    browser.close()

print("SMOKE_FAIL=%d" % fails)
sys.exit(1 if fails else 0)
