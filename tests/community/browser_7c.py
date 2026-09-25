#!/usr/bin/env python3
# tests/community/browser_7c.py — 7-C 임베드를 **진짜 브라우저로 눌러 본다** (C:T-185)
#
# 요구사항: 7-C 지시서 「시험」 — 블록 추가 「임베드」 창(허용된 사이트 안내 · 주소 · 설명) · 저장 · 읽기 화면의 「허용 대기」 카드 ·
#           관리자 「임베드 허용 목록」 창(더하기 · 빼기) · 직원에게는 단추가 없다 · 렌더러 JS 쪽 세 갈래(서버와 같은 문구) · 390px.
# 부르는 쪽: tests/community/stage7c.sh (임시 인스턴스 · 임시 DB — 운영에는 닿지 않는다)
#
# 쓰는 법: SMOKE_E1=<직원 쿠키> SMOKE_A1=<관리자 쿠키(재인증 뒤)> SMOKE_PE=<빈 페이지> SMOKE_RENDER=<서버 렌더 결과 JSON>
#          [SMOKE_SHOTS=폴더] python3 browser_7c.py <SITE> <UA>
import json
import os
import re
import sys

from playwright.sync_api import sync_playwright

SITE = sys.argv[1].rstrip("/")
UA = sys.argv[2]
E1 = os.environ["SMOKE_E1"]
A1 = os.environ["SMOKE_A1"]
PE = os.environ["SMOKE_PE"]
RENDER = os.environ.get("SMOKE_RENDER", "")
SHOTS = os.environ.get("SMOKE_SHOTS", "")
COMMUNITY = SITE + "/community"

fails = 0
pageerrors, console_errors, dialogs, http_bad = [], [], [], []
CSP_STYLE_RE = re.compile(r"Applying inline style violates")


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
    return ok


def host():
    return re.match(r"https?://([^/:]+)", SITE).group(1)


def context(browser, session, expect_bad=()):
    ctx = browser.new_context(user_agent=UA, viewport={"width": 1440, "height": 900})
    ctx.add_cookies([{"name": "tbox_session", "value": session, "domain": host(), "path": "/",
                      "httpOnly": True, "secure": False, "sameSite": "Lax"}])
    page = ctx.new_page()
    page.on("pageerror", lambda e: pageerrors.append(str(e)))
    page.on("console", lambda m: console_errors.append(m.text)
            if m.type == "error" and not CSP_STYLE_RE.search(m.text) and "Failed to load resource" not in m.text else None)

    def on_dialog(d):
        dialogs.append(d.message)
        d.dismiss()
    page.on("dialog", on_dialog)

    def on_response(r):
        if r.status >= 400 and host() in r.url and not any(x in r.url for x in expect_bad):
            http_bad.append("%d %s" % (r.status, r.url))
    page.on("response", on_response)
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


with sync_playwright() as p:
    browser = p.chromium.launch()

    print("── 1. 직원 — 블록 추가 「임베드」 창 → 저장 → 읽기 카드")
    ctx, page = context(browser, E1)
    page.goto(COMMUNITY + "/", wait_until="networkidle")
    page.wait_for_timeout(300)
    step("1 직원 홈에는 「임베드 허용 목록」 단추가 없다", not home_btn_shown(page, "#community-embed-admin"))
    page.goto(COMMUNITY + "/p/" + PE, wait_until="networkidle")
    page.click("#page-edit")
    page.wait_for_selector("#page-editor .ProseMirror", timeout=8000)
    page.click("#page-add-block")
    page.wait_for_selector("#block-dialog[open]", timeout=5000)
    page.locator("#block-list .block-pick", has_text="임베드").first.click()
    page.locator("#block-dialog button", has_text="넣기").last.click()
    page.wait_for_selector("#atom-dialog[open] #atom-embed:not([hidden])", timeout=5000)
    page.wait_for_function("() => document.getElementById('atom-embed-hosts').textContent.includes('www.youtube.com')", timeout=8000)
    hint = page.inner_text("#atom-embed-hosts")
    step("1 임베드 창 — 허용된 사이트 안내(창구 목록 그대로 · 틀 닫힘 안내)", "www.youtube.com" in hint and "허용 대기" in hint, hint)
    page.fill("#atom-embed-url", "http://www.youtube.com/x")
    page.click("#atom-commit")
    step("1 http 주소는 창에서 먼저 막는다(서버도 막는다)", "https://" in page.inner_text("#atom-msg"))
    page.fill("#atom-embed-url", "https://evil.example.org/x")
    page.click("#atom-commit")
    step("1 목록 밖 주소는 넣기 전에 알린다(자동 저장이 400 으로 막히지 않게 — 검토 L-5)", "허용 목록에 없습니다" in page.inner_text("#atom-msg"))
    page.fill("#atom-embed-url", "https://WWW.YouTube.com/embed/C7C-browser")
    page.fill("#atom-embed-caption", "브라우저 설명")
    shots(page, "k1-embed-dialog")
    page.click("#atom-commit")
    page.wait_for_function("() => !document.getElementById('atom-dialog').open", timeout=5000)
    step("1 편집기 안의 임베드 카드(주소는 글자로만 — 틀 없음)",
         page.locator("#page-editor .doc-atom[data-atom=embed]").count() == 1
         and page.locator("#page-editor iframe").count() == 0)
    page.click("#page-done")
    page.wait_for_selector("#page-doc .doc-embed", timeout=10000)
    card = page.locator("#page-doc .doc-embed").first
    step("1 읽기 화면 — 「임베드 — www.youtube.com」 카드", "임베드 — www.youtube.com" in card.inner_text())
    step("1 틀 닫힘 → 「허용 대기」 문구 · iframe 없음", "허용 대기" in card.inner_text() and page.locator("#page-doc iframe").count() == 0)
    link = card.locator("a").first
    step("1 새 탭 링크 — target=_blank · rel=noopener noreferrer",
         link.get_attribute("target") == "_blank" and link.get_attribute("rel") == "noopener noreferrer")
    step("1 설명이 보인다", "브라우저 설명" in card.inner_text())
    shots(page, "k1-read")
    no_overflow_390(page, "k1 읽기 화면")
    page.click("#page-edit")
    page.wait_for_selector("#page-editor .ProseMirror", timeout=8000)
    step("1 임베드가 든 페이지를 편집기로 다시 연다(덩어리 카드 하나 · 오류 없음 — 검토 L-8)",
         page.locator("#page-editor .doc-atom[data-atom=embed]").count() == 1 and "편집할 수 없" not in page.inner_text("#msg"))
    page.click("#page-done")
    page.wait_for_selector("#page-doc .doc-embed", timeout=10000)

    print("── 2. 렌더러 JS 쪽 — 세 갈래가 서버와 같다(C:T-19)")
    js = page.evaluate("""
    async () => {
      const m = await import('/tbox/assets/community/doc-render.js');
      const ok = {id: '22222222-2222-4222-8222-222222222222', type: 'embed', url: 'https://www.youtube.com/embed/x?a=1&b=2', caption: [{t: '설명', m: {}}]};
      const evil = {id: '11111111-1111-4111-8111-111111111111', type: 'embed', url: 'https://www.youtube.com/embed/x"><script>', caption: [{t: '설명', m: {}}]};
      const one = (b, ctx) => { const h = document.createElement('div'); h.appendChild(m.renderDoc({schema: 1, blocks: [b]}, ctx)); return h; };
      const f = one(ok, {embeds: {hosts: ['www.youtube.com'], frames: true}});
      const w = one(ok, {embeds: {hosts: ['www.youtube.com'], frames: false}});
      const b = one(ok, {embeds: {hosts: [], frames: true}});
      const e = one(evil, {embeds: {hosts: ['www.youtube.com'], frames: true}});
      const fr = f.querySelector('iframe');
      return {
        frame: fr ? {sandbox: fr.getAttribute('sandbox'), ref: fr.getAttribute('referrerpolicy'), src: fr.getAttribute('src'), cap: (f.querySelector('figcaption') || {}).textContent} : null,
        wait: w.textContent, waitFrames: w.querySelectorAll('iframe').length, waitRel: (w.querySelector('a') || {getAttribute: () => ''}).getAttribute('rel'),
        block: b.textContent, blockLinks: b.querySelectorAll('a, iframe').length,
        evil: e.querySelectorAll('iframe, script').length
      };
    }""")
    fr = js.get("frame") or {}
    step("2 목록 안 + 틀 열림 → iframe · sandbox 토큰(사용자 결정 2026-09-24) · strict-origin",
         fr.get("sandbox") == "allow-scripts allow-same-origin allow-presentation allow-popups" and fr.get("ref") == "strict-origin"
         and fr.get("src") == "https://www.youtube.com/embed/x?a=1&b=2", json.dumps(fr, ensure_ascii=False))
    step("2 목록 안 + 틀 닫힘 → 「허용 대기」 카드 · iframe 0 · noopener",
         "허용 대기 · 새 탭에서 열기" in js["wait"] and js["waitFrames"] == 0 and js["waitRel"] == "noopener noreferrer")
    step("2 목록 밖 → 「허용 목록 밖의 임베드」 · 링크·틀 0", "허용 목록 밖의 임베드" in js["block"] and js["blockLinks"] == 0)
    step("2 모양이 아닌 주소 → 틀·스크립트 0", js["evil"] == 0)
    if RENDER:
        php = json.load(open(RENDER, encoding="utf-8"))
        step("2 서버와 같은 문구(허용 대기 · 허용 목록 밖)", "허용 대기 · 새 탭에서 열기" in php["wait"] and "허용 목록 밖의 임베드" in php["block"])
    ctx.close()

    print("── 3. 관리자 — 「임베드 허용 목록」 창에서 더하고 빼기")
    ctx, page = context(browser, A1)
    page.goto(COMMUNITY + "/", wait_until="networkidle")
    page.wait_for_selector("#community-embed-admin:not([hidden])", state="attached", timeout=8000)
    open_home_more(page)
    page.click("#community-embed-admin")
    page.wait_for_selector("#embed-dialog[open]", timeout=5000)
    page.wait_for_function("() => document.querySelectorAll('#embed-body tr').length > 0", timeout=8000)
    step("3 목록에 www.youtube.com · 한도 표시", page.locator("#embed-body tr", has_text="www.youtube.com").count() == 1 and "/ 20" in page.inner_text("#embed-meta"))
    step("3 틀 닫힘 안내", "틀 닫힘" in page.inner_text("#embed-meta"))
    page.fill("#embed-host", "player.vimeo.com")
    page.click("#embed-add")
    page.wait_for_function("() => document.getElementById('embed-msg').textContent.includes('더했습니다')", timeout=8000)
    page.wait_for_timeout(300)
    step("3 더하기 → 목록에 player.vimeo.com", page.locator("#embed-body tr", has_text="player.vimeo.com").count() == 1)
    page.fill("#embed-host", "https://bad.example.com")
    page.click("#embed-add")
    page.wait_for_function("() => document.getElementById('embed-msg').textContent.includes('호스트 이름만')", timeout=8000)
    step("3 모양이 아니면 서버 문구(400)", "호스트 이름만" in page.inner_text("#embed-msg"))
    shots(page, "k3-admin")
    no_overflow_390(page, "k3 관리자 창")
    row = page.locator("#embed-body tr", has_text="player.vimeo.com").first
    btn = row.locator("button", has_text="빼기")
    btn.click()
    step("3 첫 누름은 확인 문구(아직 안 뺌)", "정말" in btn.inner_text())
    btn.click()
    page.wait_for_function("() => document.getElementById('embed-msg').textContent.includes('뺐습니다')", timeout=8000)
    page.wait_for_timeout(300)
    step("3 빼기 → 목록에서 사라졌다", page.locator("#embed-body tr", has_text="player.vimeo.com").count() == 0)
    ctx.close()

    print("── 끝. 브라우저가 본 오류")
    # 3 의 모양 오류 400 은 의도한 것이다 — 그 한 건은 빼고 센다
    bad = [x for x in http_bad if not (x.startswith("400 ") and x.endswith("/community/api/embed-domains"))]
    for name, items in (("pageerror", pageerrors), ("console error", console_errors),
                        ("alert/confirm", dialogs), ("HTTP>=400", bad)):
        print("   %-16s %d건%s" % (name, len(items), (" — " + " | ".join(items[:3])) if items else ""))
    step("pageerror 0", len(pageerrors) == 0)
    step("콘솔 오류 0", len(console_errors) == 0)
    step("alert·prompt·confirm 0", len(dialogs) == 0)
    step("HTTP 400 이상 0(의도한 400 한 건 빼고)", len(bad) == 0)
    browser.close()

print("SMOKE_FAIL=%d" % fails)
sys.exit(1 if fails else 0)
