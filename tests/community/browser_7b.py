#!/usr/bin/env python3
# tests/community/browser_7b.py — 7-B 템플릿 화면을 **진짜 브라우저로 눌러 본다** (C:T-177)
#
# 요구사항: 7-B 지시서 「시험」 — 홈 「템플릿에서」 창(목록 · 보기 · 만들기 · 지우기) · 페이지 「템플릿으로 저장」 창 ·
#           DB 새 행 고르개(행 템플릿으로 새 행) · 390px 가로 넘침 없음 · 브라우저 오류 0.
# 부르는 쪽: tests/community/stage7b.sh (임시 인스턴스 · 임시 DB — 운영에는 닿지 않는다)
#
# 쓰는 법: SMOKE_E1=<쿠키> SMOKE_PSRC=<저장할 페이지> SMOKE_DBP=<행 템플릿이 있는 DB 페이지> SMOKE_ROWTPL=<행 템플릿 이름>
#          [SMOKE_SHOTS=폴더] python3 browser_7b.py <SITE> <UA>
import os
import re
import sys

from playwright.sync_api import sync_playwright

SITE = sys.argv[1].rstrip("/")
UA = sys.argv[2]
E1 = os.environ["SMOKE_E1"]
PSRC = os.environ["SMOKE_PSRC"]
DBP = os.environ["SMOKE_DBP"]
ROWTPL = os.environ["SMOKE_ROWTPL"]
SHOTS = os.environ.get("SMOKE_SHOTS", "")
COMMUNITY = SITE + "/community"
UUID_RE = re.compile(r"/p/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})")

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


def no_overflow_390(page, name):
    page.set_viewport_size({"width": 390, "height": 844})
    page.wait_for_timeout(300)
    if SHOTS:
        page.screenshot(path=os.path.join(SHOTS, name + "-390.png"), full_page=True)
    w = page.evaluate("() => [document.documentElement.scrollWidth, document.documentElement.clientWidth]")
    step("%s — 390px 가로 넘침 없음" % name, w[0] <= w[1] + 1, "%d/%d" % (w[0], w[1]))
    page.set_viewport_size({"width": 1440, "height": 900})
    page.wait_for_timeout(200)


def open_gallery(page):
    page.goto(COMMUNITY + "/", wait_until="networkidle")
    page.click("#community-template-new")
    page.wait_for_selector("#template-dialog[open]", timeout=5000)
    page.wait_for_function("() => document.querySelectorAll('#template-body tr').length > 0", timeout=8000)


with sync_playwright() as p:
    browser = p.chromium.launch()
    ctx, page = context(browser, E1)

    print("── 1. 홈 「템플릿에서」 — 목록 · 보기 · 만들기")
    open_gallery(page)
    names = page.locator("#template-body tr td:first-child").all_inner_texts()
    step("1 목록에 기본 셋(회의록 · 인수인계서)", any("회의록" in n for n in names) and any("인수인계서" in n for n in names), " | ".join(names[:6]))
    meet = page.locator("#template-body tr", has_text="회의록").first
    step("1 기본 셋 줄에는 「지우기」가 없다(can_delete 거짓)", meet.locator("button", has_text="지우기").count() == 0)
    meet.locator("button", has_text="보기").click()
    page.wait_for_selector("#template-preview:not([hidden])", timeout=5000)
    step("1 보기 — 읽기 렌더러가 본문을 그렸다(「회의 정보」 제목)", "회의 정보" in page.inner_text("#template-preview-doc"))
    step("1 보기 — 목록은 숨었다", page.locator("#template-list-wrap").is_hidden())
    shots(page, "j1-gallery-preview")
    no_overflow_390(page, "j1 템플릿 창")
    page.click("#template-preview-use")
    page.wait_for_url(re.compile(r".*/community/p/[0-9a-f-]{36}$"), timeout=8000)
    m = UUID_RE.search(page.url)
    step("1 만들기 — 새 페이지로 갔다", m is not None, page.url)
    page.wait_for_function("() => (document.getElementById('page-title') || {}).textContent === '회의록'", timeout=8000)
    step("1 새 페이지 제목 = 회의록", page.inner_text("#page-title") == "회의록")
    step("1 새 페이지 본문에 템플릿 글(「안건」)", "안건" in page.inner_text("#page-doc"))
    step("1 새 페이지의 공개 범위 배지 = 비공개", "비공개" in page.inner_text("#page-visibility"))

    print("── 2. 페이지 「템플릿으로 저장」")
    page.goto(COMMUNITY + "/p/" + PSRC, wait_until="networkidle")
    page.wait_for_selector("#page-save-template:not([hidden])", timeout=8000)
    page.click("#page-save-template")
    page.wait_for_selector("#template-save-dialog[open]", timeout=5000)
    title = page.inner_text("#page-title")
    step("2 이름 칸에 페이지 제목이 들어 있다", page.input_value("#template-save-name") == title[:60], title)
    radios = page.locator("#template-save-scopes input[type=radio]")
    step("2 범위는 창구가 준 것만(내 비공개 페이지 → 「내 템플릿」 하나)", radios.count() == 1 and radios.first.get_attribute("value") == "personal")
    shots(page, "j2-save")
    page.fill("#template-save-name", "C7B-브라우저 틀")
    page.click("#template-save-confirm")
    page.wait_for_function("() => document.getElementById('template-save-msg').textContent.includes('저장했습니다')", timeout=8000)
    step("2 저장했다 — 알림 문장", "저장했습니다" in page.inner_text("#template-save-msg"))
    page.click("#template-save-dialog [data-template-save-close] >> nth=0")

    print("── 3. 홈에서 내 템플릿 지우기(두 번 눌러 확인)")
    open_gallery(page)
    mine = page.locator("#template-body tr", has_text="C7B-브라우저 틀").first
    step("3 목록에 방금 저장한 템플릿", mine.count() == 1)
    delete = mine.locator("button", has_text="지우기")
    step("3 내 템플릿에는 「지우기」가 있다", delete.count() == 1)
    delete.click()
    step("3 첫 누름은 확인 문구로 바뀐다(아직 안 지움)", "정말" in delete.inner_text())
    delete.click()
    page.wait_for_function("() => document.getElementById('template-msg').textContent.includes('지웠습니다')", timeout=8000)
    page.wait_for_timeout(300)
    step("3 지운 뒤 목록에서 사라졌다", page.locator("#template-body tr", has_text="C7B-브라우저 틀").count() == 0)

    print("── 4. DB 새 행 — 행 템플릿 고르개")
    page.goto(COMMUNITY + "/p/" + DBP, wait_until="networkidle")
    page.wait_for_selector("#db-add-row-template:not([hidden])", timeout=8000)
    labels = page.locator("#db-add-row-template option").all_inner_texts()
    step("4 고르개 — 「빈 행」 + 행 템플릿", labels[:1] == ["빈 행"] and any(ROWTPL in x for x in labels), " | ".join(labels))
    before = page.locator("#db-table tbody tr").count()
    page.select_option("#db-add-row-template", label=[x for x in labels if ROWTPL in x][0])
    page.click("#db-add-row button[type=submit]")
    page.wait_for_function("n => document.querySelectorAll('#db-table tbody tr').length > n", arg=before, timeout=8000)
    step("4 제목을 비우고 행 템플릿으로 새 행이 생겼다", page.locator("#db-table tbody tr").count() == before + 1)
    step("4 새 행 뒤 고르개는 「빈 행」으로 돌아왔다(다음 행이 모르고 같은 틀로 만들어지지 않게)", page.input_value("#db-add-row-template") == "")
    step("4 「빈 행」일 때는 「템플릿 지우기」가 숨어 있다", page.locator("#db-add-row-template-delete").is_hidden())
    page.select_option("#db-add-row-template", label=[x for x in labels if ROWTPL in x][0])
    step("4 행 템플릿을 고르면 「템플릿 지우기」가 보인다(can_delete)", page.locator("#db-add-row-template-delete").is_visible())
    page.click("#db-add-row-template-delete")
    step("4 첫 누름은 확인 문구(아직 안 지움)", "정말" in page.inner_text("#db-add-row-template-delete"))
    page.click("#db-add-row-template-delete")
    page.wait_for_function("() => document.getElementById('db-msg').textContent.includes('지웠습니다')", timeout=8000)
    page.wait_for_timeout(300)
    left = page.locator("#db-add-row-template option").all_inner_texts()
    step("4 지운 행 템플릿은 고르개에서 사라졌다", not any(ROWTPL in x for x in left), " | ".join(left))
    shots(page, "j4-db")
    no_overflow_390(page, "j4 DB 새 행")
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
