#!/usr/bin/env python3
# tests/community/browser_6b.py — 6-B 화면을 **진짜 브라우저로 눌러 본다** (C:T-145)
#
# 요구사항: 6-B 지시서 「시험」 브라우저 줄 — 보드 끌기·키보드 · 리스트 · 뷰 저장 · 계산 줄 · 관계 고르기.
# 부르는 쪽: tests/community/stage5e.sh (임시 인스턴스 · 임시 DB — 운영에는 닿지 않는다)
#
# 쓰는 법: SMOKE_E1=… SMOKE_DBP=<사내 DB 페이지(행 셋·관계 속성 있음)> [SMOKE_SHOTS=폴더] python3 browser_6b.py <SITE> <UA>
import json
import os
import re
import sys

from playwright.sync_api import sync_playwright

SITE = sys.argv[1].rstrip("/")
UA = sys.argv[2]
E1 = os.environ["SMOKE_E1"]
DBP = os.environ["SMOKE_DBP"]
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
    ctx, page = context(browser, E1)
    page.goto(COMMUNITY + "/p/" + DBP, wait_until="networkidle")
    page.wait_for_selector("#db-table tbody tr", timeout=10000)

    print("── 1. 표 — 계산 줄")
    page.wait_for_selector("#db-table tfoot .db-sum", timeout=8000)
    foot = page.locator("#db-table tfoot").inner_text()
    step("1 계산 줄 — 행 수 · 완료 · 금액 합", "행 3" in foot and "완료 1/3" in foot and "합 1050" in foot, foot.replace("\n", " | "))
    shots(page, "h1-table-summary")

    print("── 2. 보드 — 열 여섯 · 키보드 · 끌기")
    page.click('[data-db-type="board"]')
    page.wait_for_selector("#db-board:not([hidden]) .db-board__col", timeout=8000)
    step("2 상태 열 여섯", page.locator("#db-board .db-board__col").count() == 6)
    col = lambda st: page.locator('#db-board .db-board__col[data-status="%s"]' % st)
    step("2 공개 행1 은 「진행」 열", col("doing").locator(".db-card", has_text="C6B-공개 행1").count() == 1)
    col("doing").locator(".db-card", has_text="C6B-공개 행1").focus()
    page.keyboard.press("ArrowRight")
    page.wait_for_function("() => document.querySelector('#db-board [data-status=\"review\"]').textContent.includes('C6B-공개 행1')", timeout=8000)
    step("2 카드 골라 → 누르면 「검토」 열로(상태 저장)", True)
    step("2 옮긴 뒤 초점이 그 카드에 남는다",
         page.evaluate("() => document.activeElement && document.activeElement.textContent.includes('C6B-공개 행1')"))
    page.drag_and_drop('#db-board .db-card:has-text("C6B-공개 행2")', '#db-board .db-board__col[data-status="todo"]')
    page.wait_for_function("() => document.querySelector('#db-board [data-status=\"todo\"]').textContent.includes('C6B-공개 행2')", timeout=8000)
    step("2 끌어서 「할 일」 열로", True)
    shots(page, "h2-board")
    page.reload(wait_until="networkidle")
    page.wait_for_selector("#db-table tbody tr", timeout=8000)
    st = page.locator("#db-table tbody tr", has_text="C6B-공개 행1").locator("select.db-status").input_value()
    step("2 새로 고친 뒤 표에서도 상태 = 검토", st == "review", st)

    print("── 3. 리스트")
    page.click('[data-db-type="list"]')
    page.wait_for_selector("#db-list:not([hidden]) li", timeout=8000)
    step("3 리스트 줄 셋 · 칩", page.locator("#db-list li").count() == 3 and "금액" in page.locator("#db-list").inner_text())
    shots(page, "h3-list")

    print("── 4. 뷰 저장")
    page.click('[data-db-type="table"]')
    page.select_option("#db-f-prop", label="분류")
    page.select_option("#db-f-op", "eq")
    page.select_option("#db-f-val", "A")
    page.click("#db-f-apply")
    page.wait_for_function("() => document.getElementById('db-count').textContent.includes('거른')", timeout=8000)
    page.fill("#db-view-name", "브라우저 A 뷰")
    page.click("#db-view-form button[type=submit]")
    page.wait_for_selector('#db-saved button:has-text("브라우저 A 뷰")', timeout=8000)
    step("4 저장한 뷰 단추가 생기고 켜져 있다",
         page.locator('#db-saved button:has-text("브라우저 A 뷰")').get_attribute("aria-pressed") == "true")
    step("4 뷰의 거르기 결과(A 만)", "C6B-공개 행2" not in page.locator("#db-table tbody").inner_text())
    page.locator('#db-saved button:has-text("브라우저 A 뷰")').click()
    page.wait_for_function("() => document.querySelectorAll('#db-table tbody tr').length === 3", timeout=8000)
    step("4 다시 누르면 뷰를 풀고 전부", True)

    print("── 5. 관계 고르기")
    rel_col = page.locator("#db-table thead th").all_inner_texts().index("분류 행")
    cell = page.locator("#db-table tbody tr", has_text="C6B-공개 행2").locator("td").nth(rel_col)
    cell.locator("button.db-cell__show").click()
    page.wait_for_selector(".db-picker input[type=search]", timeout=5000)
    page.fill(".db-picker input[type=search]", "분류 가")
    page.wait_for_selector('.db-picker__list button:has-text("C6B-분류 가")', timeout=8000)
    page.locator('.db-picker__list button:has-text("C6B-분류 가")').click()
    page.locator(".db-picker button", has_text="저장").click()
    try:
        cell.locator("a.badge", has_text="C6B-분류 가").wait_for(timeout=8000)
        ok = True
    except Exception:
        ok = False
    step("5 고른 행이 칩(링크)으로", ok and cell.locator("a.badge", has_text="C6B-분류 가").count() == 1)
    shots(page, "h4-relation")
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
