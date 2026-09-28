#!/usr/bin/env python3
# tests/community/browser_stale_modules.py — 「옛 부품 캐시」 회귀 (2026-09-24 핫픽스, 사용자가 폰에서 겪은 먹통)
#
# 부르는 쪽: tests/community/stale_modules.sh (임시 인스턴스 · 임시 DB).
# 쓰는 법:   SMOKE_E1=<검토 계정 쿠키> OLD_DIR=<옛 부품 폴더> APP_VER=<부품 버전> \
#            python3 browser_stale_modules.py <SITE> <UA>
#
# 무엇을 흉내 내나
#   브라우저가 v2.18.0 때 받은 부품(doc-render.js 등)을 7일 캐시에 들고 있다. 그 주소로 다시 부르면
#   서버에 묻지 않고 옛 파일을 쓴다. playwright route 로 그 캐시를 흉내 낸다 —
#   「지금 부품 버전(APP_VER)이 붙지 않은 부품 주소」 는 서버 대신 OLD_DIR 의 옛 파일로 답한다.
#
#   A (고친 코드)  : 부품 주소마다 지금 버전이 붙어 있으므로 옛 파일이 한 번도 쓰이지 않아야 하고,
#                    pageerror 0 · 「새 페이지」 → POST /community/api/pages · 「휴지통」 → GET /api/me/trash + 창.
#   B (대조군)     : 지금 버전이 붙은 주소까지 옛 파일로 답한다(= 버전이 없던 때의 캐시와 같다).
#                    사용자가 겪은 그대로 모듈 연결 오류가 나고 단추를 눌러도 요청이 0 이어야 한다.
#                    B 가 실패로 나오지 않으면 이 시험이 결함을 못 잡는다는 뜻이므로 **시험 실패**로 센다.
#
# 규칙: 만든 페이지 id 는 `CREATED=<id>` 로 찍고, 부르는 쪽이 앱 창구(DELETE)로 지운다.
# 나가는 값: 0 통과 · 1 실패 (마지막 줄 `SMOKE_FAIL=<개수>`)
import os
import re
import sys
from urllib.parse import urlsplit, parse_qs

from playwright.sync_api import sync_playwright

SITE = sys.argv[1].rstrip("/")
UA = sys.argv[2]
E1 = os.environ["SMOKE_E1"]
OLD_DIR = os.environ["OLD_DIR"]
APP_VER = os.environ["APP_VER"]
COMMUNITY = SITE + "/community"
ASSET_PREFIX = "/tbox/assets/community/"
PAGE_RE = re.compile(r"/community/p/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})")

OLD = {}
for name in sorted(os.listdir(OLD_DIR)):
    with open(os.path.join(OLD_DIR, name), encoding="utf-8") as fh:
        OLD[name] = fh.read()

fails = 0


def open_home_more(page):
    """커뮤니티 홈 머리의 「더보기」(DESIGN 2.9 · 2.15, 2026-09-25)를 연다 — 안의 단추는 메뉴가 열려야 보이고 눌린다"""
    if not page.evaluate("() => { const m = document.getElementById('community-more'); return !!m && m.open; }"):
        page.click("#community-more > summary")



def step(name, ok, detail=""):
    global fails
    if not ok:
        fails += 1
    print("  %s %s%s" % ("✔" if ok else "✖", name, ("  — " + detail) if detail else ""))


def host():
    return re.match(r"https?://([^/:]+)", SITE).group(1)


def run(browser, mode):
    """mode: 'fixed' | 'control'. 돌려주는 값: 관찰한 것들의 dict"""
    seen = {"pageerrors": [], "console": [], "served_old": [], "js": [], "posts": [], "trash_gets": [], "http_bad": []}
    ctx = browser.new_context(user_agent=UA, viewport={"width": 390, "height": 844})
    ctx.add_cookies([{"name": "__Host-tbox_session", "value": E1, "domain": host(), "path": "/",
                      "httpOnly": True, "secure": True, "sameSite": "Lax"}])

    def on_asset(route, request):
        parts = urlsplit(request.url)
        rel = parts.path[len(ASSET_PREFIX):] if parts.path.startswith(ASSET_PREFIX) else ""
        seen["js"].append(parts.path[len(ASSET_PREFIX):] + ("?" + parts.query if parts.query else ""))
        ver = (parse_qs(parts.query).get("v") or [""])[0]
        stale = rel in OLD and (mode == "control" or ver != APP_VER)
        if stale:
            seen["served_old"].append(rel + ("?v=" + ver if ver else ""))
            route.fulfill(status=200, content_type="text/javascript; charset=utf-8", body=OLD[rel])
        else:
            route.continue_()

    ctx.route(re.compile(r".*/tbox/assets/community/.*\.js(\?.*)?$"), on_asset)
    page = ctx.new_page()
    page.on("pageerror", lambda e: seen["pageerrors"].append(str(e)))
    page.on("console", lambda m: seen["console"].append(m.text)
            if m.type == "error" and "inline style" not in m.text and "Failed to load resource" not in m.text else None)
    page.on("request", lambda r: seen["posts"].append(r.url) if r.method == "POST" and r.url.endswith("/community/api/pages") else None)
    page.on("request", lambda r: seen["trash_gets"].append(r.url) if r.method == "GET" and "/community/api/me/trash" in r.url else None)
    page.on("response", lambda r: seen["http_bad"].append("%d %s" % (r.status, r.url)) if r.status >= 400 and host() in r.url else None)

    page.goto(COMMUNITY + "/", wait_until="networkidle")
    page.wait_for_timeout(500)

    # 「휴지통」 먼저(페이지를 떠나지 않는다) → 「새 페이지」(새 페이지로 옮겨 간다)
    open_home_more(page)
    page.click("#community-trash")
    page.wait_for_timeout(1500)
    seen["trash_open"] = page.locator("#trash-dialog[open]").count() == 1
    if seen["trash_open"]:
        page.locator("[data-trash-close]").first.click()
        page.wait_for_timeout(300)

    page.click("#community-new")
    try:
        page.wait_for_url(PAGE_RE, timeout=4000 if mode == "fixed" else 2500)
    except Exception:
        pass
    m = PAGE_RE.search(page.url)
    seen["created"] = m.group(1) if m else ""
    if seen["created"]:
        page.wait_for_load_state("networkidle")
        page.wait_for_timeout(500)
    ctx.close()
    return seen


def show(seen):
    for key in ("pageerrors", "console", "http_bad"):
        items = seen[key]
        print("   %-12s %d건%s" % (key, len(items), (" — " + " | ".join(i[:160] for i in items[:3])) if items else ""))
    print("   옛 파일로 답한 부품 %d건%s" % (len(seen["served_old"]), (" — " + ", ".join(seen["served_old"][:6])) if seen["served_old"] else ""))


with sync_playwright() as p:
    browser = p.chromium.launch()

    print("── A. 고친 코드 — v2.18.0 부품이 캐시에 있어도 새 부품을 받아 단추가 돈다 (390px)")
    a = run(browser, "fixed")
    show(a)
    # 셸이 직접 부르는 진입 파일(editor.js 모듈 · search.js 일반 스크립트)은 `?v=<파일 시각>` 이라 뺀다
    comm_js = [u for u in a["js"] if not u.startswith("vendor/") and u.split("?")[0] not in ("editor.js", "search.js")]
    step("A 부품 요청이 있었다(editor.js 가 부른 우리 부품)", len(comm_js) >= 5, ", ".join(comm_js[:8]))
    step("A 부품 요청은 전부 지금 부품 버전", all(("v=" + APP_VER) in u for u in comm_js),
         ", ".join(u for u in comm_js if ("v=" + APP_VER) not in u)[:200])
    vendor_js = [u for u in a["js"] if u.startswith("vendor/")]
    step("A vendor/pm 요청도 전부 버전이 붙었다", len(vendor_js) > 0 and all("?v=" in u for u in vendor_js),
         "%d건" % len(vendor_js))
    step("A 옛 파일이 한 번도 쓰이지 않았다", len(a["served_old"]) == 0)
    step("A pageerror 0", len(a["pageerrors"]) == 0)
    step("A 콘솔 오류 0", len(a["console"]) == 0)
    step("A HTTP 400 이상 0", len(a["http_bad"]) == 0)
    step("A 「휴지통」 → GET /api/me/trash · 창이 열린다", len(a["trash_gets"]) >= 1 and a["trash_open"])
    step("A 「새 페이지」 → POST /community/api/pages → 새 페이지로 옮겨 간다", len(a["posts"]) == 1 and a["created"] != "",
         "POST %d건 · id=%s" % (len(a["posts"]), a["created"] or "-"))
    if a["created"]:
        print("CREATED=%s" % a["created"])

    print("── B. 대조군 — 버전이 없던 때처럼 옛 부품을 그대로 쓰면 (사용자가 겪은 먹통이 재현되어야 한다)")
    b = run(browser, "control")
    show(b)
    step("B 옛 파일로 답한 부품이 있다(흉내가 실제로 걸렸다)", len(b["served_old"]) >= 1)
    # 빠진 이름은 옛 부품에 없는 새 export 집합 중 하나(7-C embedHost · 7-D4 editor-schema.js 셋) — 크롬이 어느 것을 먼저 알리는지에 묶지 않는다
    link_err = [e for e in b["pageerrors"] if "does not provide an export" in e and re.search(r"\b(embedHost|mentionAllowed|editorDomParser|clipboardDomParser)\b", e)]
    step("B 사고와 같은 모듈 연결 오류가 난다(옛 부품에 없는 새 export — embedHost · 7-D4 셋 중 하나)", len(link_err) >= 1,
         (b["pageerrors"] or ["-"])[0][:160])
    step("B 「새 페이지」·「휴지통」을 눌러도 요청 0", len(b["posts"]) == 0 and len(b["trash_gets"]) == 0,
         "POST %d · trash GET %d" % (len(b["posts"]), len(b["trash_gets"])))
    if b["created"]:
        print("CREATED=%s" % b["created"])
    browser.close()

print("SMOKE_FAIL=%d" % fails)
sys.exit(1 if fails else 0)
