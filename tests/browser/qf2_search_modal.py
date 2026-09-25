#!/usr/bin/env python3
# tests/browser/qf2_search_modal.py — Q:F-2 통합 검색 창을 진짜 브라우저로 눌러 본다 (지시서 QF2-T6)
#
# 부르는 쪽: tests/community/qf2_search.sh (임시 인스턴스 · 임시 DB — 운영 8082 에는 요청하지 않는다).
# 쓰는 법: printf '%s\n%s\n' "$세션_직원1" "$세션_Guest" | python3 qf2_search_modal.py <SITE> <UA> <IDS_JSON> <SHOTS 폴더> <파일 이름>
#   세션 둘은 **표준입력 한 줄씩**(R-62 — 명령 인자·환경 변수에 싣지 않는다). IDS_JSON 은 qf2_search.sh 의 T1 이 만든 말뭉치 id 표.
#   파일 이름 = 시험이 직원1 로 올려 둔 파일(이름에 「휴가」가 든다).
# 보는 것 (두 앱 — t-box 홈 · 커뮤니티 홈)
#   ① Ctrl+K 로 창이 열린다(사이드바 「검색」 단추도) ② 「휴가」 → 묶음 셋(페이지·댓글·파일) 순서 · 묶음마다 처음 5개 + 「더 보기」
#   ③ ↑↓ 로 고른 줄이 바뀐다(aria-selected) · Enter 로 그 주소로 간다 · Esc 로 닫힌다
#   ④ 「더 보기」 → 그 묶음이 창구가 준 만큼 펼쳐진다 ⑤ 파일 결과 → t-box 목록이 그 검색어로(검색 칸 값 · 그 파일 행)
#   ⑥ 댓글 결과 → 그 페이지의 그 댓글(#comment-<id> 요소가 있다) ⑦ 페이지 결과 Enter → 그 페이지
#   ⑧ 라이트·다크·390px 스크린샷(SHOTS 폴더 — 사람이 직접 열어 본다) · 390px 에서 가로 넘침 0
#   ⑨ Guest — 창이 열리고 오류 0(한쪽 창구가 막혀도 조용히 뺀다)
#   ⑩ 전 과정 pageerror · console.error · 요청 실패 · HTTP≥400 이 0
# 나가는 값: 마지막 줄 `SMOKE_FAIL=<n>` · 종료 코드 0/1
import json
import os
import re
import sys
import time

from playwright.sync_api import sync_playwright

SITE = sys.argv[1].rstrip("/")
UA = sys.argv[2]
IDS = json.load(open(sys.argv[3], encoding="utf-8"))
SHOTS = sys.argv[4]
FILE_NAME = sys.argv[5]
E1 = sys.stdin.readline().rstrip("\n")
G1 = sys.stdin.readline().rstrip("\n")
if not E1 or not G1:
    print("세션 둘(직원1·Guest)을 표준입력으로 한 줄씩 주어야 한다 — 시작하지 않는다")
    sys.exit(2)
TBOX = SITE + "/tbox"
COMMUNITY = SITE + "/community"
fails = 0
pageerrors, console_errors, http_bad, req_failed = [], [], [], []


def step(name, ok, detail=""):
    global fails
    if not ok:
        fails += 1
    print("  %s %s%s" % ("✔" if ok else "✖", name, ("  — " + str(detail)[:200]) if (detail != "" and not ok) else ""))


def host():
    return re.match(r"https?://([^/:]+)", SITE).group(1)


def context(browser, session, scheme="light", width=1440, height=900, denied_ok=False):
    """denied_ok — Guest 처럼 한쪽 창구가 401·403 일 수 있는 계정: 검색 두 창구의 401·403 은 설계대로(묶음을 조용히 뺀다)라 세지 않는다"""
    ctx = browser.new_context(user_agent=UA, viewport={"width": width, "height": height}, color_scheme=scheme)
    ctx.add_cookies([{"name": "tbox_session", "value": session, "domain": host(), "path": "/",
                      "httpOnly": True, "secure": False, "sameSite": "Lax"}])
    page = ctx.new_page()
    page.on("pageerror", lambda e: pageerrors.append(str(e)))
    page.on("console", lambda m: console_errors.append(m.text)
            if m.type == "error" and "Failed to load resource" not in m.text else None)
    page.on("requestfailed", lambda r: req_failed.append(r.url))
    def on_resp(r):
        if r.status < 400 or host() not in r.url:
            return
        if denied_ok and r.status in (401, 403) and ("/community/api/search" in r.url or "/tbox/api/files" in r.url):
            print("    · (Guest) %d %s — 그 묶음만 조용히 뺀다" % (r.status, r.url.split("?")[0]))
            return
        http_bad.append("%d %s" % (r.status, r.url))
    page.on("response", on_resp)
    return ctx, page


def wait_results(page, timeout=10):
    """창의 안내 줄이 「찾는 중…」에서 결과 글로 바뀔 때까지(파이썬에서 읽는다 — 페이지 안 eval 은 CSP 가 막는다)"""
    note = page.locator("#search-note")
    end = time.time() + timeout
    while time.time() < end:
        t = note.text_content() or ""
        if t and "찾는 중" not in t and "__wait__" not in t:
            return t
        time.sleep(0.05)
    return note.text_content() or ""


def search(page, q):
    page.fill("#search-input", "")
    page.evaluate("document.getElementById('search-note').textContent = '__wait__'")
    page.type("#search-input", q, delay=30)
    return wait_results(page)


def groups(page):
    return [t.strip() for t in page.locator("#search-results > li.nav-title").all_text_contents()]


def options(page):
    return page.locator("#search-results > li[role=option]")


def open_with_keys(page):
    page.keyboard.press("Control+k")
    page.wait_for_selector("#search-dialog[open]", timeout=5000)


with sync_playwright() as p:
    browser = p.chromium.launch()

    # ─── t-box 홈 ──────────────────────────────────────────────
    print("── 1. t-box 홈 — Ctrl+K · 세 묶음 · ↑↓ · 더 보기 · Esc")
    ctx, page = context(browser, E1)
    page.goto(TBOX + "/", wait_until="networkidle")
    step("1 t-box 사이드바에 「검색」 단추가 있다", page.locator("#nav-search").count() == 1)
    open_with_keys(page)
    step("1 Ctrl+K 로 창이 열린다(입력 칸에 초점)", page.evaluate("() => document.activeElement && document.activeElement.id") == "search-input")
    note = search(page, "휴가")
    g = groups(page)
    step("1 「휴가」 → 묶음 셋이 페이지·댓글·파일 순서", [x.split(" ")[0] for x in g] == ["페이지", "댓글", "파일"], (g, note))
    pages_shown = page.locator("#search-results a.search-hit[href*='/community/p/']:not([href*='#comment-'])").count()
    step("1 페이지 묶음은 처음 5개", pages_shown == 5, pages_shown)
    step("1 「페이지 더 보기」 단추가 있다", page.locator("#search-results button", has_text="페이지 더 보기").count() == 1)
    step("1 파일 결과에 올린 파일 이름", FILE_NAME in (page.locator("#search-results").text_content() or ""))
    step("1 찾은 글자가 <mark> 로 강조된다", page.locator("#search-results mark").count() > 0)
    step("1 결과에 전체 개수 글이 없다(「전체」 0)", "전체" not in note, note)
    page.screenshot(path=os.path.join(SHOTS, "tbox-light-1440.png"))
    # 결과 칸은 창 안에서 스크롤한다 — 아래의 댓글·파일 묶음이 보이게 내려서 한 장 더
    page.evaluate("document.getElementById('search-results').scrollTop = 100000")
    page.screenshot(path=os.path.join(SHOTS, "tbox-light-1440-아래.png"))
    page.evaluate("document.getElementById('search-results').scrollTop = 0")
    first = options(page).nth(0).get_attribute("aria-selected")
    page.keyboard.press("ArrowDown")
    page.keyboard.press("ArrowDown")
    sel = [options(page).nth(i).get_attribute("aria-selected") for i in range(3)]
    step("1 ↑↓ — 처음엔 첫 줄, ↓ 두 번이면 셋째 줄이 골라진다", first == "true" and sel == ["false", "false", "true"], (first, sel))
    page.keyboard.press("ArrowUp")
    step("1 ↑ 한 번이면 둘째 줄", options(page).nth(1).get_attribute("aria-selected") == "true")
    page.locator("#search-results button", has_text="페이지 더 보기").click()
    pages_all = page.locator("#search-results a.search-hit[href*='/community/p/']:not([href*='#comment-'])").count()
    api_n = len(page.request.get(COMMUNITY + "/api/search?q=%ED%9C%B4%EA%B0%80").json().get("results", []))
    step("1 「더 보기」 → 페이지 묶음이 창구가 준 만큼(%d) 펼쳐진다" % api_n, pages_all == api_n and api_n > 5, (pages_all, api_n))
    step("1 「더 보기」 뒤에도 입력 칸에 초점(키보드로 이어 간다)", page.evaluate("() => document.activeElement && document.activeElement.id") == "search-input")
    page.keyboard.press("Escape")
    page.wait_for_timeout(200)
    step("1 Esc 로 닫힌다", page.locator("#search-dialog[open]").count() == 0)

    print("── 2. 파일 결과 → t-box 목록이 그 검색어로")
    page.locator("#nav-search").click()
    page.wait_for_selector("#search-dialog[open]", timeout=5000)
    search(page, "휴가")
    page.locator("#search-results a.search-hit", has_text=FILE_NAME).first.click()
    page.wait_for_url(re.compile(r".*/tbox/\?q=.*"), timeout=8000)
    page.wait_for_load_state("networkidle")
    page.wait_for_function("() => document.querySelectorAll('#files-body tr').length > 0", timeout=8000)
    step("2 주소가 /tbox/?q=휴가", "/tbox/?q=" in page.url and "%ED%9C%B4%EA%B0%80" in page.url, page.url)
    step("2 검색 칸에 검색어가 들어갔다", page.input_value("#search-q") == "휴가")
    step("2 목록에 그 파일 행이 있다(전역 검색)", FILE_NAME in (page.locator("#files-body").text_content() or ""))
    page.screenshot(path=os.path.join(SHOTS, "tbox-files-q-1440.png"))
    ctx.close()

    # ─── 커뮤니티 ──────────────────────────────────────────────
    print("── 3. 커뮤니티 홈 — 댓글 결과 · 페이지 Enter")
    ctx, page = context(browser, E1)
    page.goto(COMMUNITY + "/", wait_until="networkidle")
    open_with_keys(page)
    search(page, "휴가")
    g = groups(page)
    step("3 커뮤니티에서도 세 묶음", [x.split(" ")[0] for x in g] == ["페이지", "댓글", "파일"], g)
    page.screenshot(path=os.path.join(SHOTS, "community-light-1440.png"))
    search(page, "결재")
    hits = options(page)
    idx = -1
    for i in range(hits.count()):
        href = hits.nth(i).locator("a").get_attribute("href") or ""
        if "#comment-" in href:
            idx = i
            break
    step("3 「결재」 → 댓글 결과가 있다", idx >= 0)
    ctext = hits.nth(idx).text_content() if idx >= 0 else ""
    step("3 댓글 줄에 쓴 사람(「이름 (아이디)」 모양 — app.js personLabel 한 벌)", "댓글 · " in ctext and "claude_employee01" in ctext, ctext[:120])
    for _ in range(idx):
        page.keyboard.press("ArrowDown")
    cid = IDS["cids"]["c01"]
    with page.expect_navigation(timeout=8000):
        page.keyboard.press("Enter")
    page.wait_for_load_state("networkidle")
    step("3 Enter → 그 페이지의 그 댓글 주소", page.url.endswith("#comment-" + cid) and IDS["ids"]["d01"] in page.url, page.url)
    try:
        page.wait_for_selector("#comment-" + cid, timeout=8000)
        found = True
    except Exception:
        found = False
    step("3 댓글 창에 그 댓글 요소가 있다", found)
    page.goto(COMMUNITY + "/", wait_until="networkidle")
    open_with_keys(page)
    search(page, "휴가신청")
    with page.expect_navigation(timeout=8000):
        page.keyboard.press("Enter")
    step("3 「휴가신청」 첫 결과 Enter → 그 페이지(d04)", page.url.rstrip("/").endswith("/p/" + IDS["ids"]["d04"]), page.url)
    ctx.close()

    # ─── 다크 · 390px ──────────────────────────────────────────
    print("── 4. 다크 · 390px 스크린샷")
    for scheme in ("dark",):
        for app, url in (("tbox", TBOX + "/"), ("community", COMMUNITY + "/")):
            ctx, page = context(browser, E1, scheme=scheme)
            page.goto(url, wait_until="networkidle")
            open_with_keys(page)
            search(page, "휴가")
            page.screenshot(path=os.path.join(SHOTS, "%s-%s-1440.png" % (app, scheme)))
            ctx.close()
    for app, url in (("tbox", TBOX + "/"), ("community", COMMUNITY + "/")):
        for scheme in ("light", "dark"):
            ctx, page = context(browser, E1, scheme=scheme, width=390, height=844)
            page.goto(url, wait_until="networkidle")
            # 좁은 화면 — 사이드바를 메뉴 단추로 열고 「검색」 단추를 누른다(키보드 없는 휴대폰의 길)
            page.locator("#nav-toggle").click()
            page.locator("#nav-search").click()
            page.wait_for_selector("#search-dialog[open]", timeout=5000)
            search(page, "휴가")
            over = page.evaluate("() => document.documentElement.scrollWidth - window.innerWidth")
            dlg = page.evaluate("() => { const r = document.getElementById('search-dialog').getBoundingClientRect(); return [Math.round(r.left), Math.round(r.right)]; }")
            step("4 %s 390px %s — 가로 넘침 0 · 창이 화면 안(%s)" % (app, scheme, dlg), over <= 0 and dlg[0] >= 0 and dlg[1] <= 390, (over, dlg))
            page.screenshot(path=os.path.join(SHOTS, "%s-%s-390.png" % (app, scheme)))
            ctx.close()

    # ─── Guest ────────────────────────────────────────────────
    print("── 5. Guest — 창이 열리고 오류 없이 볼 수 있는 것만")
    ctx, page = context(browser, G1, denied_ok=True)
    page.goto(TBOX + "/", wait_until="networkidle")
    open_with_keys(page)
    note = search(page, "휴가")
    txt = page.locator("#search-results").text_content() or ""
    step("5 Guest — 직원1의 비공개 말뭉치 제목이 없다", "휴가신청서 양식" not in txt and "휴가 신청서" not in txt, txt[:120])
    step("5 Guest — 안내 줄이 결과 또는 「없습니다」", ("없습니다" in note) or ("파일" in note) or ("페이지" in note), note)
    ctx.close()

    browser.close()

step("오류 0 — pageerror", len(pageerrors) == 0, pageerrors[:3])
step("오류 0 — console.error", len(console_errors) == 0, console_errors[:3])
step("오류 0 — 요청 실패", len(req_failed) == 0, req_failed[:3])
step("오류 0 — HTTP≥400", len(http_bad) == 0, http_bad[:5])
print("SMOKE_FAIL=%d" % fails)
sys.exit(1 if fails else 0)
