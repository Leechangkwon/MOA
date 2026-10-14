#!/usr/bin/env python3
"""tests/wiki_books_browser.py — 위키 책 셋을 헤드리스 Chromium 으로 **눌러 보고** 찍는다 (2026-09-23).

tests/wiki_books.sh 의 (g) 가 부른다. 인자: BASE UA OUT · 표준입력 첫 줄: COOKIES
  BASE     http://127.0.0.1:8082/tbox
  COOKIES  "이름=값;이름=값;" (재인증을 마친 claude_admin01 세션) — 인자가 아니라 **표준입력**으로 받는다
           (명령 인자는 ps·/proc 로 다른 계정도 읽는다 — SECURITY R-62 · tests/lib/secrets.sh py_secret)
  OUT      스크린샷 폴더

무엇을 하나 — 「보이는가」가 아니라 「눌렀더니 어디로 갔나」를 단언한다
  ① /wiki 를 열어 타일이 셋인지, 타일 이름이 Qaredi · t-box · 커뮤니티 순인지
  ② 타일마다 **눌러서** 그 책 차례(/wiki/<책>)에 도착하는지 · 차례의 첫 단계 카드를 **눌러서** 글에 도착하는지
  ③ 글의 경로 줄 「위키 › <책> 차례」의 두 링크를 눌러 되돌아가는지
  ④ 커뮤니티 4-D 글의 「다음 →」 을 눌러 4단계 뒤 보정 글로 가는지(책별 단계 번호 정렬)
  ⑤ 라이트 · 다크(prefers-color-scheme) · 390px 폭에서 책 고르기·목차·글을 찍는다
  ⑥ 어느 화면에서도 pageerror · console error · 가로 넘침 0
  ⑦ 자바스크립트를 끈 컨텍스트에서도 타일 링크로 차례에 도착한다(스크립트 0 으로 동작)
출력: 줄마다 "PASS …" / "FAIL …", 끝에 "BROWSER_FAIL=<n>"
"""
import sys
from playwright.sync_api import sync_playwright

BASE, UA, OUT = sys.argv[1], sys.argv[2], sys.argv[3]
COOKIES = sys.stdin.readline().rstrip('\n')
HOST = BASE.split("/")[2].split(":")[0]
ORIGIN = "/".join(BASE.split("/")[:3])   # http://127.0.0.1:8082 — href 는 /tbox/… 로 시작한다
FAIL = 0
ERRORS = []


def ok(cond, what):
    global FAIL
    print(("PASS " if cond else "FAIL ") + what)
    if not cond:
        FAIL += 1


def cookies():
    out = []
    for pair in COOKIES.split(";"):
        if "=" in pair:
            k, v = pair.split("=", 1)
            out.append({"name": k.strip(), "value": v.strip(), "domain": HOST, "path": "/"})
    out.append({"name": "qaredi_sidebar", "value": "open", "domain": HOST, "path": "/"})  # 카페 C5 — 펼친 셸을 잰다(쿠키 qaredi_sidebar 없으면 접힘이 기본)
    return out


def context(p, browser, width=1440, scheme="light", js=True):
    ctx = browser.new_context(viewport={"width": width, "height": 900}, user_agent=UA,
                              color_scheme=scheme, java_script_enabled=js)
    ctx.add_cookies(cookies())
    page = ctx.new_page()
    page.on("pageerror", lambda e: ERRORS.append("pageerror " + str(e)))
    page.on("console", lambda m: ERRORS.append("console " + m.text) if m.type == "error" else None)
    return ctx, page


def no_overflow(page, what):
    over = page.evaluate("document.documentElement.scrollWidth > window.innerWidth + 1")
    ok(not over, what + " — 가로 넘침 0")


with sync_playwright() as p:
    browser = p.chromium.launch()

    # ── ①~④ 라이트 1440 에서 눌러 본다 ───────────────────────────
    ctx, page = context(p, browser)
    page.goto(BASE + "/wiki")
    ok(page.url.endswith("/tbox/wiki"), "①  /wiki 에 도착(재인증으로 튀지 않음) — " + page.url)
    tiles = page.locator("main a.card.card--link")
    ok(tiles.count() == 3, "①  타일 3개 — %d" % tiles.count())
    names = [tiles.nth(i).locator(".card__title").inner_text() for i in range(tiles.count())]
    ok(names == ["Qaredi", "t-box", "커뮤니티"], "①  타일 순서 " + " · ".join(names))
    page.screenshot(path=OUT + "/books-light-1440.png", full_page=True)
    no_overflow(page, "①  책 고르기")

    for i, book in enumerate(["qaredi", "tbox", "community"]):
        page.goto(BASE + "/wiki")
        page.locator("main a.card.card--link").nth(i).click()
        page.wait_for_load_state("load")
        ok(page.url.endswith("/tbox/wiki/" + book), "②  타일 %s 를 눌러 차례 도착 — %s" % (book, page.url))
        page.screenshot(path=OUT + "/index-%s-light-1440.png" % book, full_page=True)
        no_overflow(page, "②  %s 차례" % book)
        first = page.locator("main .card-grid a.card.card--link").first
        want = first.get_attribute("href")
        first.click()
        page.wait_for_load_state("load")
        ok(page.url.endswith(want), "②  %s 첫 단계 카드를 눌러 글 도착 — %s" % (book, page.url))
        ok(page.locator("article.wiki-article h1.wiki-title").count() == 1, "②  %s 글에 제목 1개" % book)
        # ③ 경로 줄 두 링크
        crumbs = page.locator("article .wiki-crumb a")
        ok(crumbs.count() == 2, "③  %s 경로 줄 링크 2개" % book)
        crumbs.nth(1).click()
        page.wait_for_load_state("load")
        ok(page.url.endswith("/tbox/wiki/" + book), "③  「%s 차례」 링크로 되돌아감" % book)
        page.goto(ORIGIN + want)
        page.locator("article .wiki-crumb a").nth(0).click()
        page.wait_for_load_state("load")
        ok(page.url.endswith("/tbox/wiki"), "③  「위키」 링크로 책 고르기로 되돌아감")

    # ④ 책별 단계 번호 정렬 — 4-D 다음은 4+(4단계 뒤 보정), 이전은 4-C
    page.goto(BASE + "/wiki/community/stage-4-d")
    nxt = page.locator("nav.wiki-nav a.wiki-nav__item--next")
    ok(nxt.count() == 1 and (nxt.get_attribute("href") or "").endswith("/wiki/community/stage-4-after"),
       "④  4-D 의 「다음」 = 4단계 뒤 보정")
    prv = page.locator("nav.wiki-nav a.wiki-nav__item:not(.wiki-nav__item--next)")
    ok(prv.count() == 1 and (prv.get_attribute("href") or "").endswith("/wiki/community/stage-4-c"),
       "④  4-D 의 「이전」 = 4-C")
    nxt.click()
    page.wait_for_load_state("load")
    ok(page.url.endswith("/wiki/community/stage-4-after"), "④  「다음」을 눌러 도착")
    page.screenshot(path=OUT + "/page-community-4after-light-1440.png", full_page=True)
    # 다른 책으로 가는 링크(qaredi 안내 → t-box 안내)
    page.goto(BASE + "/wiki/qaredi/guide-site")
    cross = page.locator("article p.wiki-link a[href$='/wiki/tbox/guide-what']")
    ok(cross.count() == 1, "④  Qaredi 안내의 t-box 책 링크가 링크로 그려짐")
    cross.click()
    page.wait_for_load_state("load")
    ok(page.url.endswith("/wiki/tbox/guide-what"), "④  다른 책 링크를 눌러 도착")
    page.goto(BASE + "/wiki/tbox/stage-28")
    page.screenshot(path=OUT + "/page-tbox-28-light-1440.png", full_page=True)
    no_overflow(page, "④  t-box 28단계 글")
    ctx.close()

    # ── ⑤ 다크 1440 · 라이트/다크 390 ───────────────────────────────
    for scheme, width in (("dark", 1440), ("light", 390), ("dark", 390)):
        ctx, page = context(p, browser, width=width, scheme=scheme)
        for name, path in (("books", "/wiki"), ("index-community", "/wiki/community"),
                           ("page-community-guide-visibility", "/wiki/community/guide-visibility"),
                           ("page-qaredi-q1", "/wiki/qaredi/stage-q1")):
            page.goto(BASE + path)
            page.screenshot(path=OUT + "/%s-%s-%d.png" % (name, scheme, width), full_page=True)
            no_overflow(page, "⑤  %s %s %dpx" % (path, scheme, width))
        if scheme == "dark":
            bg = page.evaluate("getComputedStyle(document.body).backgroundColor")
            ok(bg not in ("rgb(255, 255, 255)", "rgba(0, 0, 0, 0)"), "⑤  다크 배경 적용 — " + bg)
        ctx.close()

    # ── ⑦ 스크립트를 끈 채로도 타일 → 차례 ──────────────────────────
    ctx, page = context(p, browser, js=False)
    page.goto(BASE + "/wiki")
    page.locator("main a.card.card--link").nth(2).click()
    page.wait_for_load_state("load")
    ok(page.url.endswith("/tbox/wiki/community"), "⑦  JS 끔 — 타일을 눌러 커뮤니티 차례 도착")
    ok(page.locator("main .card-grid a.card.card--link").count() >= 7, "⑦  JS 끔 — 단계 카드가 서버 HTML 에 있다")
    ctx.close()

    browser.close()

ok(len(ERRORS) == 0, "⑥  pageerror · console error 0 — " + (" | ".join(ERRORS[:5]) or "없음"))
print("BROWSER_FAIL=%d" % FAIL)
sys.exit(1 if FAIL else 0)
