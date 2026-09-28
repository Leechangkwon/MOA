#!/usr/bin/env python3
# tests/community/browser_5c.py — 5-C 화면을 **진짜 브라우저로 눌러 본다** (C:T-90)
#
# 요구사항: ../../../community/stage5c-board-publish-directive.md 「완료 기준」 브라우저 줄
#           (게시판 화면 · 새 글 · 게시 · 태그 입력 · 숨기기 + 스크린샷 라이트·다크·390px).
# 부르는 쪽: tests/community/stage5c.sh (임시 인스턴스 · 임시 DB — 운영에는 닿지 않는다)
#
# 쓰는 법
#   SMOKE_ADMIN=<claude_admin01 세션(재인증 뒤)> SMOKE_E1=<claude_employee01> SMOKE_E2=<claude_employee02> SMOKE_M1=<claude_manager01>
#   SMOKE_FREE=<자유게시판 번호> SMOKE_TEAM=<팀 게시판 번호> SMOKE_QA=<질문과 답변 번호>
#   [SMOKE_SHOTS=<스크린샷 폴더>] python3 browser_5c.py <SITE> <UA>
#
# 규칙 — 「보인다」로 끝내지 않는다. 행동 → 결과(화면 글자·창구 JSON)로 본다.
#   만드는 것은 전부 `C5-` 접두. 제목에 `<img onerror>` 를 넣어 글자 그대로 보이고 실행되지 않는지도 본다.
import json
import os
import re
import sys

from playwright.sync_api import sync_playwright

SITE = sys.argv[1].rstrip("/")
UA = sys.argv[2]
ADMIN = os.environ["SMOKE_ADMIN"]
E1 = os.environ["SMOKE_E1"]
E2 = os.environ["SMOKE_E2"]
M1 = os.environ["SMOKE_M1"]
FREE = os.environ["SMOKE_FREE"]
TEAM = os.environ["SMOKE_TEAM"]
QA = os.environ["SMOKE_QA"]
SHOTS = os.environ.get("SMOKE_SHOTS", "")
COMMUNITY = SITE + "/community"
XSS_TITLE = 'C5-브라우저 새 글 "><img src=x onerror=alert(1)>'

fails = 0
pageerrors = []
console_errors = []
dialogs = []
http_bad = []
expected_bad = []          # 일부러 부른 403 (못 보는 게시판) — 「HTTP 400 이상 0」 에서 뺀다
CSP_STYLE_RE = re.compile(r"Applying inline style violates")



def open_more(page):
    """화면 머리의 「더보기」 메뉴(DESIGN 2.15, 2026-09-25)를 연다 — 안의 단추는 메뉴가 열려야 보이고 눌린다"""
    # 사람이 누르는 길 그대로 — 「더보기」 단추를 누른다(열려 있으면 그대로 둔다 · 검토 L-5)
    if not page.evaluate("() => { const m = document.getElementById('page-more'); return !!m && m.open; }"):
        page.click("#page-more > summary")


def shown(page, sel):
    """메뉴 안 단추가 「있어야 할 때 있는가」 — 메뉴가 닫혀 있어도 창구의 can_* 로 정해진 hidden 만 본다"""
    return page.evaluate("s => { const e = document.querySelector(s); return !!e && !e.hidden; }", sel)

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

    def on_console(m):
        # 일부러 부른 403 은 브라우저가 콘솔에 「Failed to load resource」 로 남긴다 — 그것만 뺀다.
        if m.type == "error" and not CSP_STYLE_RE.search(m.text) and "403" not in m.text:
            console_errors.append(m.text)
    page.on("console", on_console)

    def on_dialog(d):
        dialogs.append(d.message)
        d.dismiss()
    page.on("dialog", on_dialog)

    def on_response(r):
        if r.status >= 400 and host() in r.url:
            http_bad.append("%d %s" % (r.status, r.url))
    page.on("response", on_response)
    return ctx, page


def set_theme(page, theme):
    page.evaluate("t => { document.documentElement.setAttribute('data-theme', t); }", theme)
    page.wait_for_timeout(150)


def shots(page, name):
    """라이트 · 다크 · 390px 세 장. 폴더를 주지 않으면 찍지 않는다."""
    if not SHOTS:
        return
    os.makedirs(SHOTS, exist_ok=True)
    set_theme(page, "light")
    page.screenshot(path=os.path.join(SHOTS, name + "-light.png"))
    set_theme(page, "dark")
    page.screenshot(path=os.path.join(SHOTS, name + "-dark.png"))
    set_theme(page, "light")
    page.set_viewport_size({"width": 390, "height": 844})
    page.wait_for_timeout(300)
    page.screenshot(path=os.path.join(SHOTS, name + "-390.png"))
    page.set_viewport_size({"width": 1440, "height": 900})
    page.wait_for_timeout(200)


def no_hscroll(page, what):
    """390px 에서 가로 스크롤이 생기지 않는다 (DESIGN 6장)."""
    page.set_viewport_size({"width": 390, "height": 844})
    page.wait_for_timeout(300)
    w = page.evaluate("() => [document.documentElement.scrollWidth, document.documentElement.clientWidth]")
    page.set_viewport_size({"width": 1440, "height": 900})
    page.wait_for_timeout(200)
    step("%s — 390px 가로 넘침 없음" % what, w[0] <= w[1] + 1, "%d/%d" % (w[0], w[1]))


def api(page, method, path, body=None):
    """화면 안에서 창구를 부른다(같은 세션·CSRF) — 준비·확인용."""
    return page.evaluate(
        """async ([m, p, b]) => {
             const me = await (await fetch(document.body.dataset.base + '/api/me', {credentials:'same-origin'})).json();
             const o = {method: m, credentials: 'same-origin', headers: {'X-CSRF-Token': me.csrf_token}};
             if (b !== null) { o.headers['Content-Type'] = 'application/json'; o.body = JSON.stringify(b); }
             const r = await fetch(document.body.dataset.communityBase + p, o);
             let d = {}; try { d = await r.json(); } catch (e) {}
             return {status: r.status, data: d};
           }""", [method, path, body])


def modal_fits(page, sel, what):
    """모달이 화면 안에 있고 바닥 단추 줄이 보인다(폼 모달은 본문만 스크롤 — 5-A″ 사고)."""
    box = page.evaluate("s => { const r = document.querySelector(s).getBoundingClientRect(); "
                        "return [r.top, r.bottom, window.innerHeight]; }", sel)
    step("%s — 창이 화면 안(%d~%d / %d)" % (what, box[0], box[1], box[2]), box[0] >= -1 and box[1] <= box[2] + 1)


with sync_playwright() as pw:
    browser = pw.chromium.launch()

    # ── 1. 게시판 화면 (employee01) ─────────────────────────────────
    print("── 1. 게시판 화면 — 사이드바 「게시판」 묶음 · 머리 · 목록 · 태그 · 정렬")
    ctx, page = context(browser, E1)
    page.goto(COMMUNITY + "/b/" + FREE, wait_until="networkidle")
    page.wait_for_selector("#board-body tr", timeout=10000)
    step("1 제목 「자유게시판」", page.locator("#board-title").inner_text().strip() == "자유게시판")
    step("1 등급 배지 「사내」", page.locator("#board-level").inner_text().strip().startswith("사내"))
    nav = page.locator("#nav-community-boards a")
    step("1 사이드바 게시판 묶음 — 3개 이상 · 지금 게시판 강조", nav.count() >= 3
         and page.locator('#nav-community-boards a[aria-current="page"]').inner_text().strip() == "자유게시판")
    step("1 사이드바에 못 보는 게시판(관리자) 없음", "C5-관리자게시판" not in page.locator("#nav-community-boards").inner_text())
    step("1 「새 글」 단추", page.locator("#board-new").is_visible())
    step("1 「게시판 관리」 단추 없음 (Employee)", not page.locator("#board-manage").is_visible())
    rows = page.locator("#board-body tr").count()
    step("1 목록에 글이 있다", rows > 0, "%d줄" % rows)
    step("1 태그 단추 줄", page.locator("#board-tags button").count() > 0)
    page.select_option("#board-sort", "views")
    page.wait_for_timeout(600)
    step("1 조회수 순 — 첫 줄이 「C5-조회 글」", "C5-조회 글" in page.locator("#board-body tr").first.inner_text())
    page.locator("#board-tags button").first.click()
    page.wait_for_timeout(600)
    step("1 태그 단추로 거르기 — 태그 칸이 채워지고 목록이 줄었다",
         page.locator("#board-tag").input_value() != "" and page.locator("#board-body tr").count() <= rows)
    page.fill("#board-tag", "")
    page.fill("#board-search", "C5-없는말없는말")
    page.wait_for_timeout(700)
    step("1 찾기 — 없으면 「조건에 맞는 글이 없습니다.」",
         page.locator("#board-empty").is_visible() and "조건에 맞는" in page.locator("#board-empty").inner_text())
    page.fill("#board-search", "")
    page.select_option("#board-sort", "new")
    page.wait_for_timeout(600)
    shots(page, "1-board")
    no_hscroll(page, "1 게시판 화면")

    # ── 2. 새 글 → 편집 모드 → 태그 ─────────────────────────────────
    print("── 2. 「새 글」 — 창 · 만들고 쓰기 → 편집 모드 · 태그 칩 · 쓴 사람")
    page.click("#board-new")
    page.wait_for_selector("#board-new-dialog[open]", timeout=5000)
    modal_fits(page, "#board-new-dialog", "2 새 글 창")
    shots(page, "2-new-dialog")
    page.fill("#board-new-input", XSS_TITLE)
    page.fill("#board-new-tags", "브라우저, 태그Q")
    page.click("#board-new-save")
    page.wait_for_url(re.compile(r"/community/p/[0-9a-f-]{36}"), timeout=10000)
    page.wait_for_selector("#page-editor:not([hidden])", timeout=10000)
    post_id = re.search(r"/p/([0-9a-f-]{36})", page.url).group(1)
    step("2 만든 글이 편집 모드로 열렸다 · 주소에서 ?edit=1 이 지워졌다", "edit=1" not in page.url)
    page.locator("#page-editor .ProseMirror").click()
    page.keyboard.type("C5-브라우저로 쓴 본문")
    page.wait_for_function("() => (document.getElementById('page-save-state')||{}).textContent === '저장됨'", timeout=10000)
    page.click("#page-done")
    page.wait_for_timeout(500)
    step("2 제목이 글자 그대로 (img 실행 없음)", page.locator("#page-title").inner_text().strip() == XSS_TITLE)
    step("2 속성 줄 「쓴 사람」", page.locator("#page-owner-label").inner_text().strip() == "쓴 사람")
    step("2 게시판 칸 「자유게시판」 링크", page.locator("#page-board-link").inner_text().strip() == "자유게시판")
    step("2 경로 줄이 게시판에서 시작", page.locator("#page-crumb a").first.inner_text().strip() == "자유게시판")
    step("2 공개 범위 자리 「게시됨 · 사내 모두 봄」(사용감 라운드 1 UX-6 — 전 「게시판 등급 · 사내」)", page.locator("#page-visibility").inner_text().strip() == "게시됨 · 사내 모두 봄")
    step("2 하위 페이지 만들기 없음 (게시판 글)", not page.locator("#page-new-child").is_visible())
    step("2 태그 칩 둘", page.locator("#page-tags button").count() == 2)
    page.fill("#page-tag-input", "추가태그")
    page.keyboard.press("Enter")
    page.wait_for_function("() => document.querySelectorAll('#page-tags button').length === 3", timeout=5000)
    step("2 태그 입력 Enter → 칩 셋", True)
    page.locator('#page-tags button[aria-label="태그 브라우저 빼기"]').click()
    page.wait_for_function("() => document.querySelectorAll('#page-tags button').length === 2", timeout=5000)
    tags = api(page, "GET", "/api/pages/" + post_id)["data"].get("tags", [])
    step("2 칩 ✕ → 창구에도 빠졌다", sorted(tags) == ["추가태그", "태그Q"], json.dumps(tags, ensure_ascii=False))
    step("2 조회 표시 「조회 1」", page.locator("#page-views").inner_text().strip() == "조회 1")
    shots(page, "2-post")
    no_hscroll(page, "2 게시판 글")

    # ── 3. 개인 페이지 게시 ────────────────────────────────────────
    print("── 3. 개인 페이지 「게시」 — 창 · 게시판 고르기 · 게시됨 · 해제")
    made = api(page, "POST", "/api/pages", {"title": "C5-브라우저 게시 페이지"})
    pid = made["data"]["page"]["id"]
    page.goto(COMMUNITY + "/p/" + pid, wait_until="networkidle")
    page.wait_for_selector("#page-props:not([hidden])", timeout=10000)
    step("3 「게시」 단추 · 「게시 해제」 없음", shown(page, "#page-publish") and not shown(page, "#page-unpublish"))
    open_more(page)
    page.click("#page-publish")
    page.wait_for_selector("#publish-dialog[open]", timeout=5000)
    page.wait_for_selector('#publish-choices input[name="publish-board"]', timeout=5000)
    labels = page.locator("#publish-choices").inner_text()
    step("3 고를 수 있는 게시판 — 자유게시판 있음 · 공지사항(쓸 수 없음) 없음", "자유게시판" in labels and "공지사항" not in labels)
    step("3 고르기 전 「게시」 단추 잠김", page.locator("#publish-save").is_disabled())
    modal_fits(page, "#publish-dialog", "3 게시 창")
    shots(page, "3-publish-dialog")
    page.locator('#publish-choices label', has_text="자유게시판").locator("input").check()
    page.click("#publish-save")
    page.wait_for_function("() => document.getElementById('page-visibility').textContent.trim() === '게시됨'", timeout=8000)
    step("3 게시 뒤 배지 「게시됨」 · 게시판 칸 · 「게시 해제」",
         page.locator("#page-board-group").is_visible() and shown(page, "#page-unpublish")
         and not shown(page, "#page-publish"))
    step("3 게시 중 「공개 범위 바꾸기」 숨김", not page.locator("#page-visibility-edit").is_visible())
    shots(page, "3-published")
    ctx.close()

    # ── 4. 다른 사원 — 게시된 페이지가 게시판에 · 못 보는 게시판 ─────
    print("── 4. employee02 — 게시판 목록의 「게시됨」 · 못 보는 게시판은 403 안내")
    ctx, page = context(browser, E2)
    page.goto(COMMUNITY + "/b/" + FREE, wait_until="networkidle")
    page.wait_for_selector("#board-body tr", timeout=10000)
    row = page.locator("#board-body tr", has_text="C5-브라우저 게시 페이지")
    step("4 게시된 페이지가 목록에 · 「게시됨」 배지", row.count() == 1 and "게시됨" in row.inner_text())
    step("4 「게시판 관리」 없음", not page.locator("#board-manage").is_visible())
    before = len(http_bad)
    page.goto(COMMUNITY + "/b/" + TEAM, wait_until="networkidle")
    page.wait_for_timeout(500)
    step("4 다른 팀 게시판 — 「권한이 없습니다」 · 목록 0 · 이름 없음",
         page.locator("#msg").inner_text().strip() == "권한이 없습니다" and page.locator("#board-body tr").count() == 0
         and "C5-팀게시판" not in page.content())
    expected_bad.extend(http_bad[before:])
    del http_bad[before:]
    ctx.close()

    # ── 5. 관리자 — 숨기기 · 게시판 관리 ────────────────────────────
    print("── 5. admin — 게시판 글 숨기기·풀기(편집 없음) · 「게시판 관리」 창")
    ctx, page = context(browser, ADMIN)
    page.goto(COMMUNITY + "/p/" + post_id, wait_until="networkidle")
    page.wait_for_selector("#page-props:not([hidden])", timeout=10000)
    step("5 남의 게시판 글 — 「편집」 없음 · 「숨기기」 있음",
         not page.locator("#page-edit").is_visible() and shown(page, "#page-hide"))
    open_more(page)
    page.click("#page-hide")
    page.wait_for_function("() => !document.getElementById('page-board-hidden').hidden", timeout=8000)
    step("5 숨긴 뒤 「숨김」 배지 · 단추가 「숨김 풀기」", (page.locator("#page-hide").text_content() or "").strip() == "숨김 풀기")
    shots(page, "5-hidden")
    open_more(page)
    page.click("#page-hide")
    page.wait_for_function("() => document.getElementById('page-board-hidden').hidden", timeout=8000)
    step("5 숨김 풀기 → 배지 사라짐", True)
    page.goto(COMMUNITY + "/b/" + FREE, wait_until="networkidle")
    page.wait_for_selector("#board-manage:not([hidden])", timeout=10000)
    page.click("#board-manage")
    page.wait_for_selector("#board-manage-dialog[open]", timeout=5000)
    step("5 관리 창 — 게시판 목록", page.locator("#board-manage-list li").count() >= 3)
    modal_fits(page, "#board-manage-dialog", "5 관리 창")
    shots(page, "5-manage")
    page.fill("#board-manage-name", "C5-브라우저게시판")
    page.select_option("#board-manage-level", "internal")
    page.click("#board-manage-save")
    page.wait_for_function("() => document.getElementById('board-manage-list').textContent.includes('C5-브라우저게시판')", timeout=8000)
    step("5 만들기 → 목록·사이드바에 생김", "C5-브라우저게시판" in page.locator("#nav-community-boards").inner_text())
    page.locator('#board-manage-list button[aria-label="게시판 C5-브라우저게시판 고치기"]').click()
    step("5 고치기 칸 — 등급·종류 칸은 숨고 「보관」 칸이 보인다",
         not page.locator("#board-manage-level-field").is_visible() and page.locator("#board-manage-active-field").is_visible())
    page.uncheck("#board-manage-active")
    page.click("#board-manage-save")
    page.wait_for_function("() => document.getElementById('nav-community-boards').textContent.includes('C5-브라우저게시판 (보관)')", timeout=8000)
    step("5 보관으로 → 사이드바 「(보관)」", True)
    page.locator("#board-manage-dialog [data-board-manage-close]").first.click()
    ctx.close()

    # ── 6. 질문과 답변 — 해결됨 ────────────────────────────────────
    print("── 6. employee01 — 질문답변 새 글 · 「해결됨으로 표시」")
    ctx, page = context(browser, E1)
    page.goto(COMMUNITY + "/b/" + QA, wait_until="networkidle")
    page.wait_for_selector("#board-new:not([hidden])", timeout=10000)
    page.click("#board-new")
    page.fill("#board-new-input", "C5-브라우저 질문")
    page.click("#board-new-save")
    page.wait_for_url(re.compile(r"/community/p/"), timeout=10000)
    page.wait_for_selector("#page-done:not([hidden])", timeout=10000)
    page.click("#page-done")
    page.wait_for_selector("#page-resolve:not([hidden])", state="attached", timeout=8000)
    step("6 「미해결」 배지", page.locator("#page-resolved-badge").inner_text().strip() == "미해결")
    open_more(page)
    page.click("#page-resolve")
    page.wait_for_function("() => document.getElementById('page-resolved-badge').textContent.trim() === '해결됨'", timeout=8000)
    step("6 누르면 「해결됨」", True)
    page.goto(COMMUNITY + "/b/" + QA, wait_until="networkidle")
    page.wait_for_selector("#board-body tr", timeout=10000)
    step("6 목록에 「해결됨」 배지", "해결됨" in page.locator("#board-body tr", has_text="C5-브라우저 질문").inner_text())
    shots(page, "6-qa")
    ctx.close()

    print("── 끝. 브라우저가 본 오류")
    for name, items in (("pageerror", pageerrors), ("console error", console_errors),
                        ("alert/confirm", dialogs), ("HTTP>=400", http_bad), ("(일부러 부른 403)", expected_bad)):
        print("   %-16s %d건%s" % (name, len(items), (" — " + " | ".join(items[:3])) if items else ""))
    step("pageerror 0", len(pageerrors) == 0)
    step("콘솔 오류 0", len(console_errors) == 0)
    step("alert 0 (제목의 <img onerror> 가 실행되지 않았다)", len(dialogs) == 0)
    step("HTTP 400 이상 0 (일부러 부른 403 제외)", len(http_bad) == 0)
    browser.close()

print("SMOKE_FAIL=%d" % fails)
sys.exit(1 if fails else 0)
