#!/usr/bin/env python3
# tests/community/browser_5a2.py — 5-A″ 화면을 **진짜 브라우저로 눌러 본다** (C:T-66)
#
# 요구사항: ../../../community/stage5a2-names-teamshare-directive.md 「완료 기준」 브라우저 줄.
# 부르는 쪽: tests/community/stage5a2.sh (임시 인스턴스 · 임시 DB — 운영에는 닿지 않는다)
#
# 쓰는 법
#   SMOKE_ADMIN=<claude_admin01 세션(재인증 뒤)> SMOKE_E1=<claude_employee01> SMOKE_E2=<claude_employee02>
#   SMOKE_P=<개인 페이지 id> SMOKE_TP=<팀 공간 페이지 id> SMOKE_N1=<employee01 이름> SMOKE_N2=<employee02 이름>
#   [SMOKE_SHOTS=<스크린샷 폴더>] python3 browser_5a2.py <SITE> <UA>
#
# 규칙 — 「보인다」로 끝내지 않는다. 행동 → 결과(화면 글자·창구 JSON)로 본다.
#   · 이름 칸의 글자는 **textContent 로만** 들어가야 한다 — `<script>` 이름이 글자 그대로 보이고,
#     그 사이 `alert` 가 한 번도 뜨지 않는다(dialog 이벤트 0).
#   · 계정 편집은 guest01 에게만 한다(이름을 넣고 → 지운다 — 시험 뒤 원래대로).
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
P = os.environ["SMOKE_P"]
TP = os.environ["SMOKE_TP"]
N1 = os.environ["SMOKE_N1"]
N2 = os.environ["SMOKE_N2"]
SHOTS = os.environ.get("SMOKE_SHOTS", "")
TBOX = SITE + "/tbox"
COMMUNITY = SITE + "/community"

fails = 0
pageerrors = []
console_errors = []
dialogs = []
http_bad = []
CSP_STYLE_RE = re.compile(r"Applying inline style violates")


def step(name, ok, detail=""):
    global fails
    if not ok:
        fails += 1
    print("  %s %s%s" % ("✔" if ok else "✖", name, ("  — " + detail) if detail else ""))
    return ok


def label(display, username):
    d = (display or "").strip()
    return username if d == "" else "%s (%s)" % (d, username)


def host():
    return re.match(r"https?://([^/:]+)", SITE).group(1)


def context(browser, session):
    ctx = browser.new_context(user_agent=UA, viewport={"width": 1440, "height": 900})
    ctx.add_cookies([{"name": "__Host-tbox_session", "value": session, "domain": host(), "path": "/",
                      "httpOnly": True, "secure": True, "sameSite": "Lax"},
                     {"name": "qaredi_sidebar", "value": "open", "domain": host(), "path": "/"}])  # 카페 C5 — 펼친 셸을 잰다(쿠키 qaredi_sidebar 없으면 접힘이 기본)
    page = ctx.new_page()
    page.on("pageerror", lambda e: pageerrors.append(str(e)))

    def on_console(m):
        if m.type == "error" and not CSP_STYLE_RE.search(m.text):
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


with sync_playwright() as pw:
    browser = pw.chromium.launch()

    # ── 1. 계정 관리 (Admin, 재인증 뒤) ─────────────────────────────
    print("── 1. t-box 계정 관리 — 목록 「이름 (아이디)」 · <script> 글자 그대로 · 편집 창의 이름 칸")
    ctx, page = context(browser, ADMIN)
    page.goto(TBOX + "/users", wait_until="networkidle")
    page.wait_for_selector("#users-body tr", timeout=10000)
    body_text = page.locator("#users-body").inner_text()
    step("1 목록에 「%s」" % label(N2, "claude_employee02"), label(N2, "claude_employee02") in body_text)
    step("1 <script> 이름이 글자 그대로 「%s」" % label(N1, "claude_employee01"), label(N1, "claude_employee01") in body_text)
    step("1 <script> 이 DOM 요소가 되지 않았다 (목록 안 script 요소 0)",
         page.locator("#users-body script").count() == 0)
    step("1 이름 없는 계정은 아이디만 (claude_guest01 칸 = 「claude_guest01」)",
         page.locator("#users-body td", has_text=re.compile(r"^claude_guest01$")).count() == 1)
    step("1 머리 칸 「이름 (아이디)」", page.locator("th", has_text="이름 (아이디)").count() == 1)
    page.click("#user-create-open")
    page.wait_for_selector("#user-create-dialog[open]", timeout=5000)
    step("1 만들기 창에 이름 칸(maxlength 40)", page.locator("#u-name").is_visible()
         and page.get_attribute("#u-name", "maxlength") == "40")
    shots(page, "1-users-create")
    page.click("#user-create-dialog-close")
    row = page.locator("#users-body tr", has=page.locator("td", has_text=re.compile(r"^claude_guest01$")))
    # 디버깅 묶음 U2 M8 — 줄 동작은 「관리」 하나 → 상세 창의 「이름·역할·팀·만료 바꾸기」
    row.locator("td.actions button", has_text="관리").click()
    page.wait_for_selector("#user-manage-dialog[open]", timeout=5000)
    page.click("#user-manage-dialog [data-user-action='edit']")
    page.wait_for_selector("#user-edit[open]", timeout=5000)
    step("1 편집 창의 이름 칸이 비어 있다 (guest01 은 이름 없음)", page.input_value("#ue-name") == "")
    page.fill("#ue-name", "손님<b>굵게</b>")
    page.fill("#ue-reason", "5-A″ 브라우저 시험")
    shots(page, "1-users-edit")
    page.click("#ue-submit")
    page.wait_for_function("() => document.getElementById('users-body').innerText.includes('(claude_guest01)')", timeout=8000)
    step("1 저장 뒤 목록 「손님<b>굵게</b> (claude_guest01)」 글자 그대로",
         "손님<b>굵게</b> (claude_guest01)" in page.locator("#users-body").inner_text()
         and page.locator("#users-body b").count() == 0)
    shots(page, "1-users-list")
    no_hscroll(page, "1 계정 관리")
    row = page.locator("#users-body tr", has=page.locator("td", has_text="(claude_guest01)"))
    # 디버깅 묶음 U2 M8 — 줄 동작은 「관리」 하나 → 상세 창의 「이름·역할·팀·만료 바꾸기」
    row.locator("td.actions button", has_text="관리").click()
    page.wait_for_selector("#user-manage-dialog[open]", timeout=5000)
    page.click("#user-manage-dialog [data-user-action='edit']")
    page.wait_for_selector("#user-edit[open]", timeout=5000)
    step("1 다시 열면 이름 칸에 지금 값", page.input_value("#ue-name") == "손님<b>굵게</b>")
    page.fill("#ue-name", "")
    page.fill("#ue-reason", "5-A″ 되돌림")
    page.click("#ue-submit")
    page.wait_for_function(
        "() => Array.from(document.querySelectorAll('#users-body td')).some((td) => td.textContent === 'claude_guest01')",
        timeout=8000)
    step("1 이름을 비우고 저장하면 아이디만", True)
    ctx.close()

    # ── 2. 셸 사용자 칸 · 앱 메뉴 (employee02) ────────────────────────
    print("── 2. 셸 — 사용자 칸 · 앱 메뉴 「이름 (아이디)」")
    ctx, page = context(browser, E2)
    page.goto(TBOX + "/", wait_until="networkidle")
    chip = page.locator(".userchip__name").inner_text().strip()
    step("2 사이드바 사용자 칸 = 「%s」" % label(N2, "claude_employee02"), chip == label(N2, "claude_employee02"), chip)
    page.click(".appbar__apps > summary")
    page.wait_for_timeout(300)
    menu_name = page.locator(".app-menu__me .app-menu__name").first.inner_text().strip()
    step("2 앱 메뉴(눌러 연 뒤) 계정 줄 = 「%s」" % label(N2, "claude_employee02"),
         menu_name == label(N2, "claude_employee02"), menu_name)
    shots(page, "2-shell-menu")
    page.click(".appbar__apps > summary")
    page.wait_for_timeout(200)
    page.goto(TBOX + "/settings", wait_until="networkidle")
    step("2 「내 계정」 이름 칸(보기만)", N2 in page.locator(".info-grid").inner_text()
         and page.locator(".info-grid input").count() == 0)
    shots(page, "2-settings")
    ctx.close()

    # ── 3. 커뮤니티 개인 페이지 — 소유자 · 사람 추가 · 한 글자부터 · 인계 창 · 이력 ──
    print("── 3. 커뮤니티 — 소유자 칸 · 「사람 추가」 한 글자부터 · 인계 창 · 이력의 바꾼 사람")
    ctx, page = context(browser, E1)
    page.goto(COMMUNITY + "/p/" + P, wait_until="networkidle")
    page.wait_for_selector("#page-props:not([hidden])", timeout=10000)
    owner = page.locator("#page-owner-name").inner_text().strip()
    step("3 소유자 칸 = 「%s」 (글자 그대로)" % label(N1, "claude_employee01"), owner == label(N1, "claude_employee01"), owner)
    step("3 소유자 칸 안에 요소가 생기지 않았다 (script 0)", page.locator("#page-owner-name *").count() == 0)
    page.click("#page-visibility-edit")
    page.wait_for_selector("#visibility-dialog[open]", timeout=5000)
    page.wait_for_timeout(600)
    step("3 치기 전 안내 「아이디나 이름을 입력하세요」 보임 · 후보 목록 숨김",
         page.locator("#share-hint").is_visible() and not page.locator("#share-candidates").is_visible())
    # 디버깅 묶음 U M7(2026-09-26) — 역할은 한글로 보인다(값은 그대로)
    step("3 공유한 사람 줄 = 「%s · 직원」" % label(N2, "claude_employee02"),
         (label(N2, "claude_employee02") + " · 직원") in page.locator("#share-list").inner_text(),
         page.locator("#share-list").inner_text()[:80])
    page.fill("#share-search", "관리")
    page.wait_for_selector('#share-candidates [data-candidate="claude_manager01"]', timeout=5000)
    cand = page.locator('#share-candidates [data-candidate="claude_manager01"]').inner_text()
    step("3 **이름**으로 찾은 후보 「박관리 (claude_manager01) · 팀장 — 추가」", cand.startswith("박관리 (claude_manager01) · 팀장"), cand)
    step("3 치면 안내가 사라진다", not page.locator("#share-hint").is_visible())
    shots(page, "3-share-dialog")
    page.fill("#share-search", " ")
    page.wait_for_timeout(700)
    step("3 공백만 치면 후보가 비고 안내가 다시 보인다",
         page.locator("#share-hint").is_visible() and page.locator("#share-candidates li").count() == 0)
    page.locator("#visibility-dialog [data-visibility-close]").first.click()

    page.click("#page-handover")
    page.wait_for_selector("#handover-dialog[open]", timeout=5000)
    page.wait_for_timeout(600)
    step("3 인계 창을 열면 후보 0 · 안내 보임 (한 글자부터)",
         page.locator("#handover-candidates li").count() == 0 and page.locator("#handover-hint").is_visible())
    page.fill("#handover-search", "둘")
    page.wait_for_selector('#handover-candidates [data-candidate="claude_employee02"]', timeout=5000)
    page.click('#handover-candidates [data-candidate="claude_employee02"]')
    page.wait_for_timeout(200)
    picked = page.locator("#handover-picked").inner_text()
    step("3 고른 받는 사람 「받는 사람: %s · 직원」" % label(N2, "claude_employee02"),
         picked == "받는 사람: %s · 직원" % label(N2, "claude_employee02"), picked)
    shots(page, "3-handover")
    page.locator("#handover-dialog [data-handover-close]").first.click()
    page.wait_for_timeout(300)

    page.click("#page-history")
    page.wait_for_selector("#history-dialog[open]", timeout=5000)
    page.wait_for_timeout(800)
    hv = page.locator("#history-dialog").inner_text()
    step("3 이력(판) 표의 바꾼 사람 「%s」" % label(N2, "claude_employee02"), label(N2, "claude_employee02") in hv)
    step("3 이력(판) 표에 「%s」 글자 그대로" % label(N1, "claude_employee01"), label(N1, "claude_employee01") in hv)
    shots(page, "3-history")
    page.locator("#history-dialog [data-history-close]").first.click()
    page.wait_for_timeout(300)
    no_hscroll(page, "3 커뮤니티 페이지")

    # ── 4. 팀 공간 페이지 — 「사람 추가」만 (공개 범위 없음) ──────────────
    print("── 4. 팀 공간 페이지 — 팀원에게 「사람 추가」 · 창에는 공유 부분만")
    page.goto(COMMUNITY + "/p/" + TP, wait_until="networkidle")
    page.wait_for_selector("#page-props:not([hidden])", timeout=10000)
    step("4 공개 범위 「바꾸기」 없음", not page.locator("#page-visibility-edit").is_visible())
    step("4 「사람 추가」 단추가 보인다", page.locator("#page-share-open").is_visible()
         and page.locator("#page-share-open-label").inner_text().strip() == "사람 추가",
         page.locator("#page-share-open-label").inner_text())
    step("4 소유자 칸 「팀 공간」", page.locator("#page-owner-name").inner_text().strip() == "팀 공간")
    shots(page, "4-team-head")
    page.click("#page-share-open")
    page.wait_for_selector("#visibility-dialog[open]", timeout=5000)
    page.wait_for_timeout(700)
    step("4 창 제목 「공유」", page.locator("#visibility-title").inner_text().strip() == "공유")
    step("4 공개 범위 라디오·「바꾸기」 숨김",
         not page.locator("#visibility-choices").is_visible() and not page.locator("#visibility-save").is_visible())
    step("4 「사람 추가」 입력이 보인다", page.locator("#share-add").is_visible())
    step("4 공유한 사람 줄에 「%s」" % label(N2, "claude_employee02"), label(N2, "claude_employee02") in page.locator("#share-list").inner_text())
    page.fill("#share-search", "manager")
    page.wait_for_selector('#share-candidates [data-candidate="claude_manager01"]', timeout=5000)
    shots(page, "4-team-share")
    page.locator("#visibility-dialog [data-visibility-close]").first.click()
    page.wait_for_timeout(300)
    ctx.close()

    # ── 5. 팀 밖 공유받은 사람 (employee02) ─────────────────────────
    print("── 5. 팀 공간 페이지를 공유받은 팀 밖 사람 — 받은 권한 배지 · 「공유 보기」 · 편집 없음")
    ctx, page = context(browser, E2)
    page.goto(COMMUNITY + "/p/" + TP, wait_until="networkidle")
    page.wait_for_selector("#page-props:not([hidden])", timeout=10000)
    step("5 받은 권한 배지 「받은 권한: 보기」", page.locator("#page-share-mine").inner_text().strip() == "받은 권한: 보기")
    step("5 「공유 보기」 단추 (사람 추가 아님)", page.locator("#page-share-open-label").inner_text().strip() == "공유 보기")
    step("5 「편집」 단추 없음", not page.locator("#page-edit").is_visible())
    page.click("#page-share-open")
    page.wait_for_selector("#visibility-dialog[open]", timeout=5000)
    page.wait_for_timeout(600)
    step("5 사람 추가 입력 없음 · 안내 없음 (관리 자격 없음)",
         not page.locator("#share-add").is_visible() and not page.locator("#share-hint").is_visible())
    shots(page, "5-team-shared-viewer")
    page.locator("#visibility-dialog [data-visibility-close]").first.click()
    ctx.close()

    print("── 끝. 브라우저가 본 오류")
    for name, items in (("pageerror", pageerrors), ("console error", console_errors),
                        ("alert/confirm", dialogs), ("HTTP>=400", http_bad)):
        print("   %-16s %d건%s" % (name, len(items), (" — " + " | ".join(items[:3])) if items else ""))
    step("pageerror 0", len(pageerrors) == 0)
    step("콘솔 오류 0", len(console_errors) == 0)
    step("alert 0 (<script> 이름이 실행되지 않았다)", len(dialogs) == 0)
    step("HTTP 400 이상 0", len(http_bad) == 0)
    browser.close()

print("SMOKE_FAIL=%d" % fails)
sys.exit(1 if fails else 0)
