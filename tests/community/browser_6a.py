#!/usr/bin/env python3
# tests/community/browser_6a.py — 6-A 화면을 **진짜 브라우저로 눌러 본다** (C:T-137)
#
# 요구사항: 6-A 지시서 「완료 기준」 브라우저 줄 — DB 만들기 · 속성 더하기 · 칸 편집 · 새 행 · 거르기·정렬 · 행 페이지 속성 줄.
# 부르는 쪽: tests/community/stage5e.sh (임시 인스턴스 · 임시 DB — 운영에는 닿지 않는다)
#
# 쓰는 법: SMOKE_E1=… SMOKE_PARENT=<employee01 의 보통 페이지> [SMOKE_SHOTS=폴더] python3 browser_6a.py <SITE> <UA>
import json
import os
import re
import sys

from playwright.sync_api import sync_playwright

SITE = sys.argv[1].rstrip("/")
UA = sys.argv[2]
E1 = os.environ["SMOKE_E1"]
PARENT = os.environ["SMOKE_PARENT"]
SHOTS = os.environ.get("SMOKE_SHOTS", "")
COMMUNITY = SITE + "/community"

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
    ctx.add_cookies([{"name": "tbox_session", "value": session, "domain": host(), "path": "/",
                      "httpOnly": True, "secure": False, "sameSite": "Lax"}])
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




XSS = '<img src=x onerror=alert(1)>'

with sync_playwright() as pw:
    browser = pw.chromium.launch()
    ctx, page = context(browser, E1)

    print("── 1. 하위 데이터베이스 만들기")
    page.goto(COMMUNITY + "/p/" + PARENT, wait_until="networkidle")
    page.wait_for_selector("#page-new-db:not([hidden])", timeout=8000)
    page.click("#page-new-db")
    page.wait_for_selector("#db-wrap:not([hidden]) #db-table thead th", timeout=10000)
    heads = page.locator("#db-table thead th").all_inner_texts()
    step("1 DB 페이지로 옮겨 가 표가 보인다 — 기본 열 제목·상태·소유자", heads == ["제목", "상태", "소유자"], repr(heads))
    step("1 DB 페이지에는 「하위 페이지 만들기」가 없다", page.locator("#page-new-child").is_hidden())
    db_url = page.url

    print("── 2. 속성 더하기(숫자 · 선택 · XSS 이름)")
    def add_prop(name, typ, options=None):
        page.click("#db-add-prop")
        page.wait_for_selector("#db-prop-dialog[open]", timeout=5000)
        page.fill("#db-prop-name", name)
        page.select_option("#db-prop-type", typ)
        if options:
            page.fill("#db-prop-options", "\n".join(options))
        page.click("#db-prop-save")
        page.wait_for_function("n => [...document.querySelectorAll('#db-table thead th')].some(t => t.textContent === n)", arg=name, timeout=8000)
    add_prop("금액", "number")
    add_prop("분류", "select", ["가", "나"])
    step("2 선택 속성은 선택지 칸이 보였다", True)
    add_prop(XSS, "text")
    step("2 이름에 태그 — 글자 그대로(열 머리 img 0)", page.locator("#db-table thead img").count() == 0)
    shots(page, "g1-db-empty")

    print("── 3. 새 행 · 칸 편집 · 상태")
    for t in ("브라우저 행 A", "브라우저 행 B"):
        page.fill("#db-add-row-title", t)
        page.click("#db-add-row button[type=submit]")
        page.wait_for_function("n => document.getElementById('db-table').textContent.includes(n)", arg=t, timeout=8000)
    step("3 새 행 둘", page.locator("#db-table tbody tr").count() == 2)
    def cell(row_title, col_index):
        return page.locator("#db-table tbody tr", has_text=row_title).locator("td").nth(col_index)
    cell("브라우저 행 A", 2).locator("button").click()
    cell("브라우저 행 A", 2).locator("input").fill("1234")
    cell("브라우저 행 A", 2).locator("input").press("Enter")
    page.wait_for_function("() => [...document.querySelectorAll('#db-table tbody tr')].some(r => r.textContent.includes('1234'))", timeout=8000)
    step("3 금액 칸 — 누르고 치고 Enter → 1234", "1234" in cell("브라우저 행 A", 2).inner_text())
    cell("브라우저 행 B", 2).locator("button").click()
    cell("브라우저 행 B", 2).locator("input").fill("50")
    cell("브라우저 행 B", 2).locator("input").press("Enter")
    page.wait_for_function("() => [...document.querySelectorAll('#db-table tbody tr')].some(r => r.textContent.includes('50'))", timeout=8000)
    cell("브라우저 행 A", 3).locator("button").click()
    cell("브라우저 행 A", 3).locator("select").select_option("나")
    page.wait_for_function("() => [...document.querySelectorAll('#db-table tbody tr')][0].textContent.includes('나')", timeout=8000)
    step("3 분류 칸 — 고르면 곧바로 저장", "나" in cell("브라우저 행 A", 3).inner_text())
    cell("브라우저 행 A", 1).locator("select").select_option("doing")
    page.wait_for_timeout(600)
    page.reload(wait_until="networkidle")
    page.wait_for_selector("#db-table tbody tr", timeout=8000)
    step("3 새로 고친 뒤에도 값 그대로(금액·분류·상태)",
         "1234" in cell("브라우저 행 A", 2).inner_text() and "나" in cell("브라우저 행 A", 3).inner_text()
         and cell("브라우저 행 A", 1).locator("select").input_value() == "doing")
    shots(page, "g2-db-rows")

    print("── 4. 거르기 · 정렬")
    page.select_option("#db-f-prop", label="금액")
    page.select_option("#db-f-op", "gt")
    page.fill("#db-f-val", "1000")
    page.click("#db-f-apply")
    page.wait_for_function("() => document.getElementById('db-count').textContent.includes('거른')", timeout=8000)
    step("4 금액 > 1000 → 1행", page.locator("#db-table tbody tr").count() == 1 and "행 1개" in page.locator("#db-count").inner_text())
    page.click("#db-f-clear")
    page.wait_for_function("() => document.querySelectorAll('#db-table tbody tr').length === 2", timeout=8000)
    page.select_option("#db-sort", label="금액")
    page.select_option("#db-sort-dir", "desc")
    page.wait_for_timeout(800)
    first = page.locator("#db-table tbody tr").first.inner_text()
    step("4 금액 내림차순 → 첫 줄이 행 A", "브라우저 행 A" in first)
    shots(page, "g3-db-filter")

    print("── 5. 행 페이지의 속성 줄")
    page.locator("#db-table tbody tr", has_text="브라우저 행 B").locator("td").first.locator("a").click()
    page.wait_for_selector("#row-props-wrap:not([hidden])", timeout=8000)
    names = page.locator("#row-props dt").all_inner_texts()
    step("5 속성 줄에 금액·분류·이름", names[:2] == ["금액", "분류"], repr(names))
    v = page.locator('#row-props dd[data-prop]').first
    v.locator("button").click()
    v.locator("input").fill("77")
    v.locator("input").press("Enter")
    page.wait_for_function("() => document.getElementById('row-props').textContent.includes('77')", timeout=8000)
    step("5 속성 줄에서 고치기 → 77", True)
    shots(page, "g4-row-page")
    page.goto(db_url, wait_until="networkidle")
    page.wait_for_selector("#db-table tbody tr", timeout=8000)
    step("5 DB 표에도 77", "77" in page.locator("#db-table").inner_text())

    print("── 6. 트리에 행이 없다")
    page.goto(COMMUNITY + "/", wait_until="networkidle")
    page.wait_for_timeout(800)
    step("6 내 공간 트리에 행 제목 없음", "브라우저 행 A" not in page.locator("body").inner_text())
    ctx.close()

    print("── 끝. 브라우저가 본 오류")
    for name, items in (("pageerror", pageerrors), ("console error", console_errors),
                        ("alert/confirm", dialogs), ("HTTP>=400", http_bad)):
        print("   %-16s %d건%s" % (name, len(items), (" — " + " | ".join(items[:3])) if items else ""))
    step("pageerror 0", len(pageerrors) == 0)
    step("콘솔 오류 0", len(console_errors) == 0)
    step("alert·prompt·confirm 0", len(dialogs) == 0)
    step("HTTP 400 이상 0", len(http_bad) == 0)
    browser.close()

print("SMOKE_FAIL=%d" % fails)
sys.exit(1 if fails else 0)
