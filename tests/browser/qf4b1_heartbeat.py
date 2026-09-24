#!/usr/bin/env python3
# tests/browser/qf4b1_heartbeat.py — Q:F-4 P-4b: 편집 중 신호를 **진짜 편집기로** 눌러 본다 (tests/qf4b1.sh 가 부른다)
#
# P-4b 는 heartbeat 창구가 판정용 행을 본문 없이 읽게 바꿨다. 응답 모양은 그대로여야 하고(편집기 JS 는 바뀌지 않았다),
# 편집기가 그 응답으로 「○○ 님도 보는 중」을 그려야 한다. 두 검토 계정이 같은 페이지에서 차례로 「편집」을 누른다.
#   B(두 번째 계정) 편집 시작 → heartbeat 200
#   A(첫 번째 계정) 편집 시작 → heartbeat 200 · 응답 editors 에 B · 화면 #page-editors 에 B 의 아이디
#   두 화면 모두 페이지 오류·콘솔 오류 0 (브라우저가 스스로 막은 인라인 style 알림은 뺀다 — browser_smoke.py 주석)
# 본문은 고치지 않는다(편집 모드에 들어갔다 「완료」만 누른다 — 바뀐 것이 없으면 저장 요청도 없다).
#
# 쓰는 법: SMOKE_E1=<A 쿠키> SMOKE_E2=<B 쿠키> python3 tests/browser/qf4b1_heartbeat.py <SITE> <UA> <페이지 UUID> <B 아이디>
#   쿠키는 환경 변수로만 받는다(명령 인자에 싣지 않는다). 계정은 부르는 쪽이 넘긴 검토 계정(claude_) 둘뿐.
# 나가는 값: 0 통과 · 1 실패 (마지막 줄 SMOKE_FAIL=<개수>)
import json
import os
import re
import sys

from playwright.sync_api import sync_playwright

SITE = sys.argv[1].rstrip("/")
UA = sys.argv[2]
PAGE_ID = sys.argv[3]
B_NAME = sys.argv[4]
E1 = os.environ["SMOKE_E1"]
E2 = os.environ["SMOKE_E2"]
URL = SITE + "/community/p/" + PAGE_ID

fails = 0


def step(name, ok, detail=""):
    global fails
    if not ok:
        fails += 1
    print("  %s %s%s" % ("✔" if ok else "✖", name, ("  — " + detail) if detail else ""))


def host():
    return re.match(r"https?://([^/:]+)", SITE).group(1)


def open_editor(browser, cookie, label):
    seen = {"pageerrors": [], "console": []}
    ctx = browser.new_context(user_agent=UA, viewport={"width": 1280, "height": 900})
    ctx.add_cookies([{"name": "tbox_session", "value": cookie, "domain": host(), "path": "/",
                      "httpOnly": True, "secure": False, "sameSite": "Lax"}])
    page = ctx.new_page()
    page.on("pageerror", lambda e: seen["pageerrors"].append(str(e)))
    page.on("console", lambda m: seen["console"].append(m.text)
            if m.type == "error" and "inline style" not in m.text else None)
    page.goto(URL, wait_until="networkidle")
    step(label + " 페이지 열림 · 「편집」 단추", page.locator("#page-edit").count() == 1)
    with page.expect_response(lambda r: r.request.method == "POST" and r.url.endswith("/heartbeat"), timeout=8000) as info:
        page.click("#page-edit")
    resp = info.value
    try:
        body = resp.json()
    except Exception:
        body = None
    step(label + " 편집 시작 → heartbeat 200", resp.status == 200, "상태 %d" % resp.status)
    step(label + " 응답 모양 {editors:[…]}", isinstance(body, dict) and list(body.keys()) == ["editors"]
         and isinstance(body.get("editors"), list), json.dumps(body, ensure_ascii=False)[:200] if body is not None else "본문 없음")
    return ctx, page, seen, body


with sync_playwright() as p:
    browser = p.chromium.launch()
    print("── B(두 번째 계정)가 먼저 편집을 시작한다")
    ctx_b, page_b, seen_b, _ = open_editor(browser, E2, "B")
    print("── A(첫 번째 계정)가 편집을 시작하면 B 가 보인다")
    ctx_a, page_a, seen_a, body_a = open_editor(browser, E1, "A")
    names = [e.get("username") for e in (body_a or {}).get("editors", []) if isinstance(e, dict)]
    step("A 의 heartbeat 응답 editors 에 B", B_NAME in names, "editors=%s" % names)
    page_a.wait_for_timeout(500)
    badge = page_a.locator("#page-editors")
    visible = badge.is_visible()
    text = badge.text_content() or ""
    step("A 화면 #page-editors 가 보이고 B 의 아이디가 있다", visible and B_NAME in text, "보임=%s 글=%s" % (visible, text.strip()[:120]))
    for label, page in (("A", page_a), ("B", page_b)):
        if page.locator("#page-done").is_visible():
            page.click("#page-done")
        page.wait_for_timeout(800)
    for label, seen in (("A", seen_a), ("B", seen_b)):
        step(label + " 페이지 오류 0 · 콘솔 오류 0", not seen["pageerrors"] and not seen["console"],
             " | ".join((seen["pageerrors"] + seen["console"])[:3])[:300])
    ctx_a.close()
    ctx_b.close()
    browser.close()

print("SMOKE_FAIL=%d" % fails)
sys.exit(1 if fails else 0)
