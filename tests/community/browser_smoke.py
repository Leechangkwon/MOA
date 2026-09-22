#!/usr/bin/env python3
# tests/community/browser_smoke.py — 커뮤니티 화면을 **진짜 브라우저로** 눌러 본다 (C:T-21)
#
# 요구사항: 커뮤니티 C:F-16 · C:F-17, ../../../community/ARCHITECTURE.md 14장,
#           관문 ③ 사용자 보고 2026-09-22 「새 페이지 단추가 작동 안 함」.
#
# 왜 이 시험이 필요한가
#   커뮤니티 화면의 동작은 전부 브라우저 안에서 일어난다(모듈 스크립트 + ProseMirror).
#   `curl` 시험은 **서버에 요청이 오면** 무엇이 오는지를 본다 — JS 가 첫 줄에서 죽어 요청이
#   아예 오지 않는 상태는 그대로 통과한다. 실제로 그 일이 있었다: ProseMirror 스키마가
#   「code 가 노드이자 마크」라며 예외를 던져 모듈 평가가 멈췄고, 화면 전체가 죽어 있었는데
#   curl 시험은 82 통과였다. 그래서 「콘솔에 오류 0 · 단추가 실제로 눌린다」를 시험에 넣는다.
#
# 쓰는 법 (tests/community/stage3.sh 가 부른다)
#   python3 tests/community/browser_smoke.py <SITE> <UA> <tbox_session 값> [<samples.json> <php-render.json>]
#     · SITE            http://127.0.0.1:8082 (커뮤니티는 SITE + /community)
#     · tbox_session    qa 계정으로 로그인한 쿠키 값 (scripts/ui_snapshot.sh 와 같은 방식)
#     · 뒤 두 인자를 주면 C:T-19 의 **JS 쪽 렌더러 동등성**까지 같은 브라우저에서 잰다.
#
# 규칙
#   · 만든 페이지는 **앱 창구(삭제 단추)로** 되돌린다. DB 를 직접 만지지 않는다.
#   · 계정은 부르는 쪽이 넘긴 검토 세션 하나뿐이고, UA 도 그대로 쓴다.
#
# 나가는 값: 0 통과 · 1 실패 (마지막 줄 `SMOKE_FAIL=<개수>` 를 stage3.sh 가 읽는다)
import json
import re
import sys
import time

from playwright.sync_api import sync_playwright

if len(sys.argv) < 4:
    print("쓰는 법: browser_smoke.py <SITE> <UA> <SESSION> [<samples.json> <php-render.json>]")
    sys.exit(2)

SITE = sys.argv[1].rstrip("/")
UA = sys.argv[2]
SESSION = sys.argv[3]
SAMPLES = sys.argv[4] if len(sys.argv) > 4 else None
PHP_RENDER = sys.argv[5] if len(sys.argv) > 5 else None

COMMUNITY = SITE + "/community"
PAGE_RE = re.compile(r"/community/p/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$")
MARK = "SMOKE-%d" % int(time.time())

fails = 0
pageerrors = []
console_errors = []
req_failed = []
http_bad = []
saves = []


def step(name, ok, detail=""):
    global fails
    if ok:
        print("  ✔ %s%s" % (name, ("  — " + detail) if detail else ""))
    else:
        fails += 1
        print("  ✖ %s%s" % (name, ("  — " + detail) if detail else ""))
    return ok


def host_of(url):
    m = re.match(r"https?://([^/]+)", url)
    return m.group(1) if m else ""


with sync_playwright() as p:
    browser = p.chromium.launch()
    ctx = browser.new_context(user_agent=UA, viewport={"width": 1440, "height": 900})
    # 쿠키는 부르는 쪽이 qa 계정으로 받아 넘겨 준 것이다. 로컬은 http 라 secure=False 로 넣는다
    # (운영에서는 서버가 Secure 로 발급한다 — 이 시험은 127.0.0.1 전용이다).
    ctx.add_cookies([{
        "name": "tbox_session",
        "value": SESSION,
        "domain": host_of(SITE).split(":")[0],
        "path": "/",
        "httpOnly": True,
        "secure": False,
        "sameSite": "Lax",
    }])
    page = ctx.new_page()

    page.on("pageerror", lambda e: pageerrors.append(str(e)))
    page.on("console", lambda m: console_errors.append(m.text) if m.type == "error" else None)
    page.on("requestfailed", lambda r: req_failed.append("%s %s (%s)" % (
        r.method, r.url, (r.failure or ""))))

    def on_response(r):
        if r.status >= 400:
            http_bad.append("%d %s %s" % (r.status, r.request.method, r.url))
        if r.request.method == "PATCH" and "/community/api/pages/" in r.url and r.status == 200:
            saves.append(r.url)

    page.on("response", on_response)

    # ── 1. 내 공간 ────────────────────────────────────────────────
    print("── 1. 내 공간 열기")
    page.goto(COMMUNITY + "/", wait_until="networkidle")
    step("GET /community/ 가 열린다", page.title() != "", "title=%r" % page.title())
    step("JS 오류(pageerror) 0건", len(pageerrors) == 0, " | ".join(pageerrors[:3]))
    step("콘솔 오류 0건", len(console_errors) == 0, " | ".join(console_errors[:3]))
    step("실패한 요청 0건", len(req_failed) == 0, " | ".join(req_failed[:3]))
    step("HTTP 400 이상 0건", len(http_bad) == 0, " | ".join(http_bad[:3]))
    step("목록이 그려졌다", page.locator("#community-list .card").count() >= 1
         or page.locator("#community-empty").is_visible(),
         "카드 %d개" % page.locator("#community-list .card").count())

    # ── 2. 새 페이지 단추 ─────────────────────────────────────────
    print("── 2. 「새 페이지」 단추")
    before = page.url
    page.click("#community-new")
    try:
        page.wait_for_url(PAGE_RE, timeout=10000)
        moved = True
    except Exception:
        moved = False
    step("단추를 누르면 새 페이지로 간다", moved, "%s → %s" % (before, page.url))
    page_url = page.url

    if not moved:
        print("PAGEERROR=%d" % len(pageerrors))
        print("SMOKE_FAIL=%d" % fails)
        browser.close()
        sys.exit(1)

    # ── 3. 편집 모드 ──────────────────────────────────────────────
    print("── 3. 편집 모드 (ProseMirror)")
    page.wait_for_selector("#page-edit:not([hidden])", timeout=10000)
    page.click("#page-edit")
    has_pm = page.wait_for_selector(".ProseMirror", timeout=10000) is not None
    step(".ProseMirror 가 생긴다", has_pm)

    page.click(".ProseMirror")
    page.keyboard.type("# 스모크 제목")
    page.keyboard.press("Enter")
    page.keyboard.type("- 스모크 항목")
    page.keyboard.press("Enter")
    page.keyboard.type("[] 스모크 할 일")
    page.keyboard.press("Enter")
    page.keyboard.type(MARK)

    step("마크다운 `# ` → 제목", page.locator(".ProseMirror h1").count() >= 1,
         "h1 %d개" % page.locator(".ProseMirror h1").count())
    step("마크다운 `- ` → 글머리 목록",
         page.locator('.ProseMirror [data-kind="bulleted"]').count() >= 1)
    step("마크다운 `[] ` → 할 일",
         page.locator(".ProseMirror .doc-todo").count() >= 1)

    # ── 4. 인라인 툴바 ────────────────────────────────────────────
    print("── 4. 글자를 고르면 툴바")
    page.keyboard.press("Shift+Home")
    page.wait_for_timeout(300)
    step("인라인 툴바가 보인다", page.locator("#inline-toolbar").is_visible())
    page.keyboard.press("End")

    # ── 5. 슬래시 메뉴 ────────────────────────────────────────────
    print("── 5. `/` 슬래시 메뉴")
    page.keyboard.press("Enter")
    page.keyboard.type("/")
    try:
        page.wait_for_selector("#slash-menu:not([hidden])", timeout=4000)
        menu_open = page.locator("#slash-menu").is_visible()
    except Exception:
        menu_open = False
    step("메뉴가 열린다", menu_open,
         "항목 %d개" % page.locator("#slash-list .list__item").count())
    page.keyboard.press("Escape")
    page.keyboard.press("Backspace")

    # ── 6. 자동 저장 ──────────────────────────────────────────────
    print("── 6. 자동 저장 (1.5초 멈춤, 최대 12초 기다림)")
    saved = False
    for _ in range(24):
        page.wait_for_timeout(500)
        if saves and page.locator("#page-save-state").inner_text().strip() == "저장됨":
            saved = True
            break
    step("저장 요청이 200 으로 돌아왔다", len(saves) >= 1, "%d건" % len(saves))
    step("저장 배지가 「저장됨」", saved,
         "배지=%r" % page.locator("#page-save-state").inner_text().strip())

    # ── 7. 새로 고침 뒤에도 남아 있나 ─────────────────────────────
    print("── 7. 새로 고침")
    page.goto(page_url, wait_until="networkidle")
    page.wait_for_selector("#page-doc .doc", timeout=10000)
    doc_text = page.locator("#page-doc .doc").inner_text()
    step("적은 글자가 그대로 있다", MARK in doc_text, "본문 %d자" % len(doc_text))
    step("제목 블록이 읽기 모드에도 있다", page.locator("#page-doc .doc-h1").count() >= 1)
    step("할 일 블록이 읽기 모드에도 있다", page.locator("#page-doc .doc-todo").count() >= 1)

    # ── 8. C:T-19 — 두 렌더러의 동등성 (같은 브라우저에서) ────────
    diff = None
    if SAMPLES and PHP_RENDER:
        print("── 8. 렌더러 동등성 (표본 20개, doc-render.js)")
        samples = json.load(open(SAMPLES, encoding="utf-8"))
        php = json.load(open(PHP_RENDER, encoding="utf-8"))
        script = """
        async (docs) => {
          const m = await import('/tbox/assets/community/doc-render.js');
          const out = [];
          for (const doc of docs) {
            const host = document.createElement('div');
            host.appendChild(m.renderDoc(doc));
            const texts = [];
            const classes = new Set();
            const walk = (n) => {
              for (const c of n.childNodes) {
                if (c.nodeType === 3) { const t = c.nodeValue.trim(); if (t) texts.push(t); }
                else if (c.nodeType === 1) {
                  for (const k of c.classList) classes.add(k);
                  walk(c);
                }
              }
            };
            walk(host);
            out.push({texts: texts, classes: Array.from(classes).sort()});
          }
          return out;
        }
        """
        js = page.evaluate(script, samples)
        diff = 0
        for i, (a, b) in enumerate(zip(php, js)):
            if a["texts"] != b["texts"] or sorted(a["classes"]) != sorted(b["classes"]):
                diff += 1
                print("    다름 #%d" % i)
                print("      PHP texts=%s" % a["texts"])
                print("      JS  texts=%s" % b["texts"])
                print("      PHP classes=%s" % sorted(a["classes"]))
                print("      JS  classes=%s" % sorted(b["classes"]))
        step("두 렌더러의 텍스트 노드·클래스 집합이 같다", diff == 0, "다른 표본 %d개" % diff)

    # ── 9. 정리 — 만든 페이지를 앱 창구로 지운다 ──────────────────
    print("── 9. 정리 (삭제 단추)")
    page.wait_for_selector("#page-delete:not([hidden])", timeout=10000)
    page.click("#page-delete")
    page.wait_for_selector("#delete-dialog[open]", timeout=5000)
    page.click("#delete-confirm")
    try:
        page.wait_for_url(re.compile(r"/community/$"), timeout=10000)
        removed = True
    except Exception:
        removed = False
    step("삭제 뒤 내 공간으로 돌아온다", removed, page.url)

    # ── 마무리 ────────────────────────────────────────────────────
    print("── 브라우저가 본 오류 (처음부터 끝까지)")
    for name, items in (("pageerror", pageerrors), ("console error", console_errors),
                        ("requestfailed", req_failed), ("HTTP>=400", http_bad)):
        print("   %-14s %d건%s" % (name, len(items), (" — " + " | ".join(items[:5])) if items else ""))
    step("끝까지 pageerror 0건", len(pageerrors) == 0)
    step("끝까지 콘솔 오류 0건", len(console_errors) == 0)
    step("끝까지 실패한 요청 0건", len(req_failed) == 0)
    step("끝까지 HTTP 400 이상 0건", len(http_bad) == 0)

    browser.close()

print("PAGEERROR=%d" % len(pageerrors))
print("CONSOLEERR=%d" % len(console_errors))
print("REQFAIL=%d" % len(req_failed))
print("HTTP4XX=%d" % len(http_bad))
if diff is not None:
    print("DIFF=%d" % diff)
print("SMOKE_FAIL=%d" % fails)
sys.exit(1 if fails else 0)
