#!/usr/bin/env python3
# tests/community/browser_5e.py — 5-E 화면을 **진짜 브라우저로 눌러 본다** (C:T-115) + 렌더러 JS 쪽 동등성 (C:T-114)
#
# 요구사항: 5-E 지시서 「완료 기준」 브라우저 줄 — 블록 추가 다섯 · 표 창 · 파일 고르기 · 토글 펼침 · 역참조.
# 부르는 쪽: tests/community/stage5e.sh (임시 인스턴스 · 임시 DB — 운영에는 닿지 않는다)
#
# 쓰는 법: SMOKE_E1=… SMOKE_E2=… SMOKE_PE=<빈 사내 페이지> SMOKE_PT=<참조받는 페이지>
#          SMOKE_FILE_NAME=… SMOKE_SAMPLES=… SMOKE_CTX=… SMOKE_PHP=… [SMOKE_SHOTS=폴더]
#          python3 browser_5e.py <SITE> <UA>
import json
import os
import re
import sys

from playwright.sync_api import sync_playwright

SITE = sys.argv[1].rstrip("/")
UA = sys.argv[2]
E1 = os.environ["SMOKE_E1"]
E2 = os.environ["SMOKE_E2"]
PE = os.environ["SMOKE_PE"]
PT = os.environ["SMOKE_PT"]
PI = os.environ.get("SMOKE_PI", "")
PCOL = os.environ.get("SMOKE_PCOL", "")
FILE_NAME = os.environ["SMOKE_FILE_NAME"]
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

    print("── 1. employee01 — 빈 사내 페이지에 토글 · 표 · 파일 · 페이지 링크 넣기")
    ctx, page = context(browser, E1)
    page.goto(COMMUNITY + "/p/" + PE, wait_until="networkidle")
    page.click("#page-edit")
    page.wait_for_selector("#page-editor .ProseMirror", timeout=8000)

    names = page.evaluate("() => [...document.querySelectorAll('#block-list .block-pick strong')].map(s => s.textContent)")
    step("1 「블록 추가」 목록에 새 다섯", all(n in names for n in ("토글", "표", "페이지 링크", "그림", "파일")), repr(names[-6:]))

    add_block(page, "토글")
    step("1 토글 — 창 제목 「토글 넣기」", page.locator("#atom-dialog-title").inner_text().strip() == "토글 넣기")
    page.fill("#atom-toggle-summary", "C5E-펼치는 제목")
    page.fill("#atom-toggle-body", "첫 문단\n둘째 문단")
    page.click("#atom-commit")
    page.wait_for_selector('#page-editor [data-atom="toggle"]', timeout=5000)
    step("1 편집기에 토글 카드", "C5E-펼치는 제목" in page.locator('#page-editor [data-atom="toggle"]').inner_text())

    add_block(page, "표")
    page.click("#atom-row-add")
    page.click("#atom-col-add")
    cells = page.locator("#atom-table-grid button.table-cell-preview")
    step("1 표 창 — 행·열 더하기 → 3×3 칸", cells.count() == 9, str(cells.count()))
    for i, t in enumerate(["이름", "역할", "메모", "하나", "둘", "셋"]):
        cells.nth(i).click()
        page.locator('.table-cell-input .ProseMirror').fill(t)
    page.click("#atom-col-del")
    page.locator(".table-delete-confirm button", has_text="삭제하기").click()
    step("1 표 창 — 마지막 열 빼기 → 3×2, 글자는 그대로", cells.count() == 6 and cells.nth(0).inner_text() == "이름")
    shots(page, "e1-table-dialog")
    page.click("#atom-commit")
    page.wait_for_selector('#page-editor [data-atom="table"]', timeout=5000)

    add_block(page, "파일")
    # Q:F-12 U3 (DESIGN 2.24 ③) — 그림 · 파일 창의 첫 탭이 「이 문서에 올리기」가 됐다. 이 시험은 자료실 파일을 고르는 길을 재므로 둘째 탭을 누른다
    if page.locator('#atom-file [data-atom-tab="library"]').count():
        page.click('#atom-file [data-atom-tab="library"]')
    page.wait_for_selector("#atom-file-list .block-pick", timeout=8000)
    page.fill("#atom-file-q", "C5E-사내")
    page.wait_for_function("n => [...document.querySelectorAll('#atom-file-list .block-pick')].some(b => b.textContent.includes(n))",
                           arg=FILE_NAME, timeout=8000)
    lst = page.locator("#atom-file-list").inner_text()
    step("1 파일 고르기 — t-box 내 파일 목록(내 권한)", FILE_NAME in lst)
    page.locator("#atom-file-list .block-pick", has_text=FILE_NAME).first.click()
    step("1 「고른 파일」 줄", FILE_NAME in page.locator("#atom-file-chosen").inner_text())
    step("1 「t-box 에 올리기」 는 t-box 주소", (page.get_attribute("#atom-file-upload", "href") or "").endswith("/tbox/"))
    page.fill("#atom-file-caption", "C5E-파일 설명")
    shots(page, "e2-file-dialog")
    page.click("#atom-commit")
    page.wait_for_selector('#page-editor [data-atom="file"]', timeout=5000)

    add_block(page, "페이지 링크")
    page.fill("#atom-link-q", "C5E-참조받는")
    page.wait_for_selector("#atom-link-list .block-pick", timeout=8000)
    page.locator("#atom-link-list .block-pick", has_text="C5E-참조받는 페이지").first.click()
    page.click("#atom-commit")
    page.wait_for_selector('#page-editor [data-atom="link_to_page"]', timeout=5000)

    # 두 번 누르면 다시 고친다
    page.locator('#page-editor [data-atom="table"]').dblclick()
    page.wait_for_selector("#atom-dialog[open]", timeout=5000)
    step("1 표 카드 두 번 누르기 → 「표 고치기」 · 칸 글자 그대로",
         page.locator("#atom-dialog-title").inner_text().strip() == "표 고치기"
         and page.locator("#atom-table-grid button.table-cell-preview").nth(2).inner_text() == "하나")
    page.locator('#atom-table-grid button.table-cell-preview').nth(2).click()
    page.locator('.table-cell-input .ProseMirror').fill('하나(고침)')
    page.click("#atom-commit")
    shots(page, "e3-editor")

    page.click("#page-done")
    page.wait_for_selector("#page-doc:not([hidden]) details.doc-toggle", timeout=10000)
    step("1 편집 끝 → 읽기 화면에 토글(접힘)", page.evaluate("() => !document.querySelector('#page-doc details.doc-toggle').open"))
    page.click("#page-doc details.doc-toggle > summary")
    step("1 토글 제목을 누르면 펼쳐진다", page.evaluate("() => document.querySelector('#page-doc details.doc-toggle').open")
         and "둘째 문단" in page.locator("#page-doc details.doc-toggle").inner_text())
    step("1 표 — 머리 행 th 2 · 고친 칸", page.locator("#page-doc table.doc-table th").count() == 2
         and "하나(고침)" in page.locator("#page-doc table.doc-table").inner_text())
    step("1 파일 카드에 이름 · 설명", FILE_NAME in page.locator("#page-doc .doc-file").inner_text()
         and "C5E-파일 설명" in page.locator("#page-doc .doc-file").inner_text())
    step("1 페이지 링크 카드", page.locator("#page-doc a.doc-link-page", has_text="C5E-참조받는 페이지").count() == 1)

    page.reload(wait_until="networkidle")
    page.wait_for_selector("#page-doc .doc-file", timeout=8000)
    step("1 새로 고친 뒤에도(서버 지도) 파일 이름", FILE_NAME in page.locator("#page-doc .doc-file").inner_text())
    shots(page, "e4-read")

    if PCOL:
        page.goto(COMMUNITY + "/p/" + PCOL, wait_until="networkidle")
        page.click("#page-edit")
        page.wait_for_selector('#page-editor .ProseMirror', timeout=8000)
        step("1 꾸민 표가 든 페이지 — 서식을 보존하며 편집기를 연다",
             page.locator('#page-editor [data-atom="table"]').count() > 0)
        page.locator('#page-editor [data-atom="table"]').first.dblclick()
        page.wait_for_selector('#atom-dialog[open]')
        step("1 꾸민 표 — 셀을 실제 rich 입력으로 고친다",
             page.locator('.table-cell-input .ProseMirror').count() == 1)
        page.locator('[data-atom-close]').first.click()

    print("── 2. 렌더러 JS 쪽 동등성 (C:T-114) — 새 블록 표본")
    samples = json.load(open(os.environ["SMOKE_SAMPLES"], encoding="utf-8"))
    rctx = json.load(open(os.environ["SMOKE_CTX"], encoding="utf-8"))
    php = json.load(open(os.environ["SMOKE_PHP"], encoding="utf-8"))
    js = page.evaluate("""
    async ([docs, ctx]) => {
      const m = await import('/tbox/assets/community/doc-render.js');
      const out = [];
      for (const doc of docs) {
        const host = document.createElement('div');
        host.appendChild(m.renderDoc(doc, ctx));
        const texts = [];
        const classes = new Set();
        const walk = (n) => {
          for (const c of n.childNodes) {
            if (c.nodeType === 3) { const t = c.nodeValue.trim(); if (t) texts.push(t); }
            else if (c.nodeType === 1) { for (const k of c.classList) classes.add(k); walk(c); }
          }
        };
        walk(host);
        out.push({texts: texts, classes: Array.from(classes).sort(), img: host.querySelectorAll('img').length});
      }
      return out;
    }""", [samples, rctx])
    diff = 0
    for i, (a, b) in enumerate(zip(php, js)):
        if a["texts"] != b["texts"] or sorted(a["classes"]) != sorted(b["classes"]) or a["img"] != b["img"]:
            diff += 1
            print("    다름 #%d\n      PHP %s %s img=%d\n      JS  %s %s img=%d" % (
                i, a["texts"], sorted(a["classes"]), a["img"], b["texts"], sorted(b["classes"]), b["img"]))
    step("2 두 렌더러의 글자·클래스·그림 수가 같다 (표본 %d)" % len(js), diff == 0 and len(js) == len(php), "다른 표본 %d" % diff)
    ctx.close()

    print("── 3. employee02 — 역참조 · 그림")
    ctx, page = context(browser, E2)
    page.goto(COMMUNITY + "/p/" + PT, wait_until="networkidle")
    page.wait_for_selector("#page-backlinks-wrap:not([hidden])", timeout=8000)
    bl = page.locator("#page-backlinks").inner_text()
    step("3 「이 페이지를 참조한 곳」 — 사내 참조 페이지 둘", "C5E-공개 참조 페이지" in bl and "C5E-브라우저 페이지" in bl, bl.replace("\n", " | "))
    step("3 비공개 참조 페이지는 없다", "C5E-비밀 참조 페이지" not in bl)
    page.locator("#page-backlinks a", has_text="C5E-브라우저 페이지").click()
    page.wait_for_selector("#page-doc .doc-file", timeout=8000)
    step("3 역참조 링크를 누르면 그 페이지로", PE in page.url)
    shots(page, "e5-backlinks-target")
    if PI:
        page.goto(COMMUNITY + "/p/" + PI, wait_until="networkidle")
        page.wait_for_selector("#page-doc img.doc-image__img", timeout=8000)
        page.wait_for_function("() => document.querySelector('#page-doc img.doc-image__img').complete", timeout=8000)
        step("3 다른 팀 사원 — 사내 그림이 실제로 그려진다(t-box 창구)",
             page.evaluate("() => document.querySelector('#page-doc img.doc-image__img').naturalWidth") > 0)
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
