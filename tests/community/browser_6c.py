#!/usr/bin/env python3
# tests/community/browser_6c.py — 6-C 화면을 **진짜 브라우저로 눌러 본다** (C:T-155) + 렌더러 JS 쪽 동등성 (C:T-153)
#
# 요구사항: 6-C 지시서 「시험」 — 블록 추가 「단 나누기」 창 · 표 칸 합치기 · 읽기 화면 · 390px 세로로 쌓임.
# 부르는 쪽: tests/community/stage5e.sh (임시 인스턴스 · 임시 DB — 운영에는 닿지 않는다)
#
# 쓰는 법: SMOKE_E1=… SMOKE_PE=<빈 페이지> SMOKE_SAMPLES=… SMOKE_PHP=… [SMOKE_SHOTS=폴더] python3 browser_6c.py <SITE> <UA>
import json
import os
import re
import sys

from playwright.sync_api import sync_playwright

SITE = sys.argv[1].rstrip("/")
UA = sys.argv[2]
E1 = os.environ["SMOKE_E1"]
PE = os.environ["SMOKE_PE"]
PRATIO = os.environ.get("SMOKE_PRATIO", "")
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


def add_block(page, label):
    """「블록 추가」 창에서 그 종류를 고르고 「넣기」 → 「블록 고치기」 창이 열린다"""
    page.click("#page-add-block")
    page.wait_for_selector("#block-dialog[open]", timeout=5000)
    page.locator("#block-list .block-pick", has_text=label).first.click()
    page.click("#block-insert")
    page.wait_for_selector("#atom-dialog[open]", timeout=5000)



with sync_playwright() as pw:
    browser = pw.chromium.launch()
    ctx, page = context(browser, E1)
    page.goto(COMMUNITY + "/p/" + PE, wait_until="networkidle")
    page.click("#page-edit")
    page.wait_for_selector("#page-editor .ProseMirror", timeout=8000)

    print("── 1. 단 나누기")
    add_block(page, "단 나누기")
    step("1 창 — 「단 나누기 넣기」 · 단 수 기본 2", page.locator("#atom-dialog-title").inner_text().strip() == "단 나누기 넣기"
         and page.locator("#atom-columns-n").input_value() == "2" and page.locator("#atom-col-2-field").is_hidden())
    page.select_option("#atom-columns-n", "3")
    step("1 3단을 고르면 셋째 칸이 보인다", page.locator("#atom-col-2-field").is_visible())
    page.select_option("#atom-columns-n", "2")
    page.fill("#atom-col-0", "C6C-왼쪽 단")
    page.fill("#atom-col-1", "C6C-오른쪽 단\n둘째 문단")
    page.click("#atom-commit")
    page.wait_for_selector('#page-editor [data-atom="columns"]', timeout=5000)
    step("1 편집기에 단 카드", "2단" in page.locator('#page-editor [data-atom="columns"]').inner_text())

    print("── 2. 표 칸 합치기")
    add_block(page, "표")
    cells = page.locator("#atom-table-grid input")
    cells.nth(0).fill("C6C-합친 머리")
    cells.nth(0).click()
    page.fill("#atom-merge-cols", "2")
    page.fill("#atom-merge-rows", "1")
    page.click("#atom-merge")
    step("2 합치면 덮인 칸이 막힌다", page.locator("#atom-table-grid input").nth(1).is_disabled())
    page.locator("#atom-table-grid input").nth(2).fill("a")
    page.locator("#atom-table-grid input").nth(3).fill("b")
    page.locator("#atom-table-grid input").nth(2).click()
    page.fill("#atom-merge-cols", "2")
    page.fill("#atom-merge-rows", "1")
    page.click("#atom-merge")
    step("2 글자 든 칸(b)을 덮는 합치기는 거절(알림)", "비어 있어야" in page.locator("#atom-msg").inner_text()
         and not page.locator("#atom-table-grid input").nth(3).is_disabled())
    page.locator("#atom-table-grid input").nth(3).click()
    page.fill("#atom-merge-rows", "2")
    page.fill("#atom-merge-cols", "1")
    page.click("#atom-merge")
    step("2 표 밖으로 나가는 합치기도 거절", "표 밖" in page.locator("#atom-msg").inner_text())
    # 독립 검토 6-C L5 — 이미 합친 칸(0,0)의 크기를 바로 바꾼다(2칸 → 1칸 = 풀림과 같음 → 다시 2칸)
    page.locator("#atom-table-grid input").nth(0).click()
    page.fill("#atom-merge-cols", "1")
    page.fill("#atom-merge-rows", "1")
    page.click("#atom-merge")
    step("2 합친 칸 크기 바꾸기(1×1 로) → 덮였던 칸이 풀린다", not page.locator("#atom-table-grid input").nth(1).is_disabled())
    page.locator("#atom-table-grid input").nth(0).click()
    page.fill("#atom-merge-cols", "2")
    page.click("#atom-merge")
    step("2 다시 2칸으로", page.locator("#atom-table-grid input").nth(1).is_disabled())
    shots(page, "i1-merge-dialog")
    page.click("#atom-commit")
    page.wait_for_selector('#page-editor [data-atom="table"]', timeout=5000)
    page.locator('#page-editor [data-atom="table"]').dblclick()
    page.wait_for_selector("#atom-dialog[open]", timeout=5000)
    step("2 다시 열어도 합침이 남아 있다", page.locator("#atom-table-grid input").nth(1).is_disabled())
    page.locator("#atom-dialog [data-atom-close]").first.click()

    print("── 3. 읽기 화면")
    page.click("#page-done")
    page.wait_for_selector("#page-doc:not([hidden]) .doc-columns", timeout=10000)
    step("3 단 둘", page.locator("#page-doc .doc-columns--2 > .doc-column").count() == 2)
    step("3 오른쪽 단에 문단 둘", page.locator("#page-doc .doc-column").nth(1).locator("p").count() == 2)
    th = page.locator("#page-doc table.doc-table th").first
    step("3 합친 머리 칸 colspan=2", th.get_attribute("colspan") == "2" and "C6C-합친 머리" in th.inner_text())
    step("3 첫 행 칸은 하나(덮인 칸 없음)", page.locator("#page-doc table.doc-table tr").first.locator("th, td").count() == 1)
    shots(page, "i2-read")
    page.set_viewport_size({"width": 390, "height": 844})
    page.wait_for_timeout(300)
    tracks = page.evaluate("() => getComputedStyle(document.querySelector('#page-doc .doc-columns')).gridTemplateColumns.split(' ').length")
    step("3 390px — 단이 세로로 쌓인다(한 칸)", tracks == 1, str(tracks))
    page.set_viewport_size({"width": 1440, "height": 900})

    if PRATIO:
        page.goto(COMMUNITY + "/p/" + PRATIO, wait_until="networkidle")
        page.click("#page-edit")
        page.wait_for_function("() => (document.getElementById('msg') || {}).textContent.includes('비율')", timeout=8000)
        step("3 비율이 든 단 — 편집기를 열지 않고 알린다(말없이 지우지 않는다 · M1)", page.locator("#page-editor .ProseMirror").count() == 0)

    print("── 4. 렌더러 JS 쪽 동등성 (C:T-153)")
    samples = json.load(open(os.environ["SMOKE_SAMPLES"], encoding="utf-8"))
    php = json.load(open(os.environ["SMOKE_PHP"], encoding="utf-8"))
    js = page.evaluate("""
    async (docs) => {
      const m = await import('/tbox/assets/community/doc-render.js');
      const out = [];
      for (const doc of docs) {
        const host = document.createElement('div');
        host.appendChild(m.renderDoc(doc, {}));
        const texts = []; const classes = new Set();
        const walk = (n) => { for (const c of n.childNodes) {
          if (c.nodeType === 3) { const t = c.nodeValue.trim(); if (t) texts.push(t); }
          else if (c.nodeType === 1) { for (const k of c.classList) classes.add(k); walk(c); } } };
        walk(host);
        const spans = [];
        for (const el of host.querySelectorAll('[rowspan],[colspan]')) {
          if (el.hasAttribute('rowspan')) spans.push('rowspan=' + el.getAttribute('rowspan'));
          if (el.hasAttribute('colspan')) spans.push('colspan=' + el.getAttribute('colspan'));
        }
        out.push({texts: texts, classes: Array.from(classes).sort(), spans: spans,
                  cells: host.querySelectorAll('.doc-table__cell').length, img: host.querySelectorAll('img, script').length});
      }
      return out;
    }""", samples)
    diff = 0
    for i, (a, b) in enumerate(zip(php, js)):
        if a["texts"] != b["texts"] or sorted(a["classes"]) != sorted(b["classes"]) or sorted(a["spans"]) != sorted(b["spans"]) or a["cells"] != b["cells"] or b["img"] != 0:
            diff += 1
            print("    다름 #%d\n      PHP %s %s %s %d\n      JS  %s %s %s %d img=%d" % (
                i, a["texts"], sorted(a["classes"]), a["spans"], a["cells"], b["texts"], sorted(b["classes"]), b["spans"], b["cells"], b["img"]))
    step("4 두 렌더러가 같다(글자·클래스·병합 속성·칸 수 · img/script 0) — 표본 %d" % len(js), diff == 0 and len(js) == len(php), "다른 표본 %d" % diff)
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
