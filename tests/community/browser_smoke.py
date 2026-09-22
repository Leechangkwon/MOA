#!/usr/bin/env python3
# tests/community/browser_smoke.py — 커뮤니티 편집기를 **진짜 브라우저로 눌러 본다** (C:T-21)
#
# 요구사항: 커뮤니티 C:F-16 · C:F-17, ../../../community/ARCHITECTURE.md 14장,
#           ../../../community/stage3b-editor-rework-directive.md 「완료 기준」 20항목.
#
# 이 시험의 규칙 (2026-09-23 전면 재작성)
#   **「보인다」로 끝나는 단언을 하나도 두지 않는다.** 항목마다 「행동 → 결과」다. 결과는
#   ① 편집기 DOM(블록 종류) 또는 ② **서버가 돌려준 저장 JSON**(`GET /community/api/pages/{id}`)
#   으로 확인한다. 관문 ③ 에서 사용자가 만난 것들(목록 Enter·링크 Enter·빠른 타자 유실)은
#   전부 「눌렀더니 되는가」로만 잡힌다 — 요소가 있는지 보는 것으로는 잡히지 않았다.
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
# ★ 「브라우저가 스스로 막은 인라인 style」에 대하여 (2026-09-23 실측)
#   Chromium 의 contenteditable 엔진은 블록이 합쳐지는 native `deleteContentBackward` 뒤에
#   가끔 스스로 `style` 속성을 쓰려 한다. 우리 CSP(`style-src 'self'`)가 그것을 **막고**
#   콘솔에 「Applying inline style violates …」를 남긴다. 우리 코드가 style 을 쓰는 것이
#   아님을 확인했다 — `CSSStyleDeclaration.cssText`·`setProperty`·`Element.setAttribute('style')`·
#   `innerHTML` 을 전부 후킹해도 한 번도 불리지 않았고, `securitypolicyviolation` 의
#   `sourceFile` 은 비어 있다(스크립트가 아니라 브라우저 내부 동작이라는 뜻). 막혔으므로
#   화면에도 문서에도 남는 것이 없다. 그래서 이 메시지만 `CSPSTYLE=` 로 **따로 세어 알리고**,
#   그 밖의 콘솔 오류는 `CONSOLEERR=` 로 0 을 요구한다. 숨기지 않고 줄 수를 찍는다.
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

# 브라우저가 스스로 막은 인라인 style — 우리 코드가 아니다(머리글 ★).
CSP_STYLE_RE = re.compile(r"Applying inline style violates")

fails = 0
pageerrors = []
console_errors = []
csp_style = []
req_failed = []
http_bad = []
saves = []
patch_started = []
delay_patch = {"on": False}


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


# 편집기 DOM 의 블록 한 줄 한 줄을 **우리 블록 이름**으로 옮긴다.
KINDS_JS = """
() => Array.from(document.querySelectorAll('.ProseMirror > *')).map((el) => {
  if (el.dataset && el.dataset.kind) { return el.dataset.kind; }
  if (el.classList.contains('doc-todo')) { return 'to_do'; }
  if (el.classList.contains('doc-callout')) { return 'callout'; }
  if (el.classList.contains('doc-toc')) { return 'toc'; }
  const t = el.tagName.toLowerCase();
  if (t === 'blockquote') { return 'quote'; }
  if (t === 'hr') { return 'divider'; }
  if (t === 'pre') { return 'code'; }
  return t;
});
"""


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

    def on_console(m):
        if m.type != "error":
            return
        (csp_style if CSP_STYLE_RE.search(m.text) else console_errors).append(m.text)

    page.on("console", on_console)
    page.on("requestfailed", lambda r: req_failed.append("%s %s (%s)" % (
        r.method, r.url, (r.failure or ""))))

    def on_request(r):
        if r.method == "PATCH" and "/community/api/pages/" in r.url:
            patch_started.append(time.time())

    def on_response(r):
        if r.status >= 400:
            http_bad.append("%d %s %s" % (r.status, r.request.method, r.url))
        if r.request.method == "PATCH" and "/community/api/pages/" in r.url and r.status == 200:
            saves.append(r.url)

    page.on("request", on_request)
    page.on("response", on_response)

    # 18번(저장 중 이어치기) 을 **확실하게** 재현하려고 저장 요청을 붙잡아 둔다.
    def route_patch(route, request):
        if delay_patch["on"] and request.method == "PATCH":
            time.sleep(1.2)
        route.continue_()

    page.route("**/community/api/pages/**", route_patch)

    def kinds():
        return page.evaluate(KINDS_JS)

    def badge_text():
        return page.locator("#page-save-state").inner_text().strip()

    def wait_saved(limit=40):
        """배지가 「저장됨」이 될 때까지 기다린다(변경이 남아 있으면 「저장됨」이 되지 않는다)."""
        for _ in range(limit):
            page.wait_for_timeout(500)
            if badge_text() == "저장됨":
                return True
        return False

    def saved_doc(page_id):
        r = page.request.get(COMMUNITY + "/api/pages/" + page_id)
        return r.json()["page"]["doc"]

    def block_text(block):
        return "".join(seg.get("t", "") for seg in (block.get("text") or []))

    def reset_doc():
        """편집기 안을 비운다 — 항목마다 깨끗한 문서에서 시작한다."""
        page.click(".ProseMirror")
        page.keyboard.press("Control+a")
        page.keyboard.press("Backspace")
        page.wait_for_timeout(200)

    # ── 1. 새 페이지 → 편집 ───────────────────────────────────────
    print("── 1. 새 페이지를 만들고 편집을 연다")
    page.goto(COMMUNITY + "/", wait_until="networkidle")
    page.click("#community-new")
    try:
        page.wait_for_url(PAGE_RE, timeout=15000)
        moved = True
    except Exception:
        moved = False
    step("「새 페이지」를 누르면 새 페이지로 간다", moved, page.url)
    if not moved:
        print("PAGEERROR=%d" % len(pageerrors))
        print("CONSOLEERR=%d" % len(console_errors))
        print("REQFAIL=%d" % len(req_failed))
        print("HTTP4XX=%d" % len(http_bad))
        print("CSPSTYLE=%d" % len(csp_style))
        print("SMOKE_FAIL=%d" % (fails + 1))
        browser.close()
        sys.exit(1)

    page_url = page.url
    page_id = page_url.rsplit("/", 1)[1]

    page.wait_for_selector("#page-edit:not([hidden])", timeout=10000)
    page.click("#page-edit")
    page.wait_for_selector(".ProseMirror", timeout=10000)
    step("편집을 누르면 문단 하나짜리 편집기가 뜬다", kinds() == ["p"], "블록=%r" % kinds())
    step("여기까지 콘솔 오류 0건", len(console_errors) == 0, " | ".join(console_errors[:2]))

    # ── 2. 글머리 목록 Enter ──────────────────────────────────────
    print("── 2. `- 항목` → Enter → `둘째`  (목록이 이어지는가)")
    reset_doc()
    page.keyboard.type("- 항목")
    page.wait_for_timeout(120)
    page.keyboard.press("Enter")
    page.keyboard.type("둘째")
    page.wait_for_timeout(150)
    step("DOM 의 두 블록이 모두 bulleted", kinds() == ["bulleted", "bulleted"], "블록=%r" % kinds())
    wait_saved()
    doc = saved_doc(page_id)
    types = [b["type"] for b in doc["blocks"]]
    texts = [block_text(b) for b in doc["blocks"]]
    step("저장 JSON 의 두 블록이 모두 bulleted", types == ["bulleted", "bulleted"], "types=%r" % types)
    step("저장 JSON 의 글자가 「항목」·「둘째」", texts == ["항목", "둘째"], "texts=%r" % texts)

    # ── 3. 번호 목록 Enter ────────────────────────────────────────
    print("── 3. `1. 하나` → Enter → `둘`")
    reset_doc()
    page.keyboard.type("1. 하나")
    page.wait_for_timeout(120)
    page.keyboard.press("Enter")
    page.keyboard.type("둘")
    page.wait_for_timeout(150)
    step("DOM 의 두 블록이 모두 numbered", kinds() == ["numbered", "numbered"], "블록=%r" % kinds())
    wait_saved()
    types = [b["type"] for b in saved_doc(page_id)["blocks"]]
    step("저장 JSON 의 두 블록이 모두 numbered", types == ["numbered", "numbered"], "types=%r" % types)

    # ── 4. 할 일 Enter ────────────────────────────────────────────
    print("── 4. `[] 할 일` → Enter → `다음 할 일`")
    reset_doc()
    page.keyboard.type("[] 할 일")
    page.wait_for_timeout(120)
    page.keyboard.press("Enter")
    page.keyboard.type("다음 할 일")
    page.wait_for_timeout(150)
    step("DOM 의 두 블록이 모두 to_do", kinds() == ["to_do", "to_do"], "블록=%r" % kinds())
    wait_saved()
    blocks = saved_doc(page_id)["blocks"]
    types = [b["type"] for b in blocks]
    step("저장 JSON 의 두 블록이 모두 to_do", types == ["to_do", "to_do"], "types=%r" % types)
    step("두 번째 할 일의 checked 가 false",
         len(blocks) == 2 and blocks[1].get("checked") is False,
         "checked=%r" % (blocks[1].get("checked") if len(blocks) > 1 else None))

    # ── 5. 빈 목록 항목에서 Enter → 문단 ──────────────────────────
    print("── 5. 빈 목록 항목에서 Enter  (목록 빠져나오기)")
    reset_doc()
    page.keyboard.type("- 항목")
    page.wait_for_timeout(120)
    page.keyboard.press("Enter")          # 빈 목록 항목이 하나 생긴다
    page.wait_for_timeout(120)
    before_kinds = kinds()
    page.keyboard.press("Enter")          # 그 빈 항목에서 한 번 더
    page.wait_for_timeout(150)
    step("빈 항목에서 Enter 를 누르면 그 블록이 paragraph 가 된다",
         before_kinds == ["bulleted", "bulleted"] and kinds() == ["bulleted", "p"],
         "%r → %r" % (before_kinds, kinds()))
    wait_saved()
    types = [b["type"] for b in saved_doc(page_id)["blocks"]]
    step("저장 JSON 도 bulleted + paragraph", types == ["bulleted", "paragraph"], "types=%r" % types)

    # ── 6. 제목에서 Enter → 다음은 문단 ───────────────────────────
    print("── 6. `# 제목` → Enter → `본문`")
    reset_doc()
    page.keyboard.type("# 제목")
    page.wait_for_timeout(120)
    page.keyboard.press("Enter")
    page.keyboard.type("본문")
    page.wait_for_timeout(150)
    step("제목 다음 블록이 paragraph", kinds() == ["h1", "p"], "블록=%r" % kinds())
    wait_saved()
    types = [b["type"] for b in saved_doc(page_id)["blocks"]]
    step("저장 JSON 이 heading + paragraph", types == ["heading", "paragraph"], "types=%r" % types)

    # ── 7. 블록 맨 앞에서 Backspace → 문단 ────────────────────────
    print("── 7. 목록 맨 앞에서 Backspace  (지우지 않고 문단으로 되돌린다)")
    reset_doc()
    page.keyboard.type("- 되돌릴 항목")
    page.wait_for_timeout(200)
    step("먼저 bulleted 가 되었다", kinds() == ["bulleted"], "블록=%r" % kinds())
    page.keyboard.press("Home")
    page.wait_for_timeout(120)
    page.keyboard.press("Backspace")
    page.wait_for_timeout(200)
    step("Backspace 뒤 블록이 paragraph 다", kinds() == ["p"], "블록=%r" % kinds())
    wait_saved()
    blocks = saved_doc(page_id)["blocks"]
    step("저장 JSON 이 paragraph 이고 글자가 남아 있다",
         [b["type"] for b in blocks] == ["paragraph"] and block_text(blocks[0]) == "되돌릴 항목",
         "types=%r texts=%r" % ([b["type"] for b in blocks], [block_text(b) for b in blocks]))

    # ── 8. 「블록 추가」 창 ───────────────────────────────────────
    print("── 8. 「블록 추가」 창을 연다")
    reset_doc()
    page.click("#page-add-block")
    page.wait_for_selector("#block-dialog[open]", timeout=5000)
    n_items = page.locator("#block-list .list__item").count()
    step("창이 열리고 종류가 10종 이상", n_items >= 10, "%d종" % n_items)
    first_name = page.locator("#block-preview-name").inner_text().strip()
    first_desc = page.locator("#block-desc").inner_text().strip()
    sample_nodes = page.locator("#block-sample .doc > *").count()
    step("첫 항목이 골라져 있고 오른쪽에 이름·설명·미리보기가 그려졌다",
         first_name != "" and first_desc != "" and sample_nodes >= 1,
         "이름=%r 설명=%d자 미리보기 블록=%d" % (first_name, len(first_desc), sample_nodes))
    page.keyboard.press("ArrowDown")
    page.wait_for_timeout(120)
    second_name = page.locator("#block-preview-name").inner_text().strip()
    step("↓ 를 누르면 오른쪽 미리보기가 따라 바뀐다", second_name != first_name,
         "%r → %r" % (first_name, second_name))
    page.keyboard.press("Escape")
    page.wait_for_timeout(200)
    step("Esc 로 창이 닫힌다", page.locator("#block-dialog[open]").count() == 0)

    # ── 9. 창에서 종류를 하나씩 전부 넣기 ─────────────────────────
    print("── 9. 창에서 종류를 **하나씩 전부** 골라 넣는다")
    reset_doc()
    labels = []
    for i in range(n_items):
        page.click("#page-add-block")
        page.wait_for_selector("#block-dialog[open]", timeout=5000)
        page.locator('#block-list .block-pick[data-block-index="%d"]' % i).click()
        page.wait_for_timeout(80)
        labels.append(page.locator("#block-preview-name").inner_text().strip())
        page.click("#block-insert")
        page.wait_for_timeout(150)
        page.keyboard.type("블록%02d" % i)
        page.wait_for_timeout(60)
    wait_saved()
    blocks = saved_doc(page_id)["blocks"]
    types = [b["type"] for b in blocks]
    want = ["paragraph", "heading", "bulleted", "numbered", "to_do",
            "quote", "callout", "divider", "code", "toc"]
    missing = [t for t in want if t not in types]
    step("넣은 종류가 저장 JSON 에 **전부** 있다 (3단계 10종)", missing == [],
         "없는 것=%r / 저장된 것=%r" % (missing, types))
    levels = sorted({b.get("level") for b in blocks if b["type"] == "heading"})
    step("제목 1·2·3 이 각각 들어갔다", levels == [1, 2, 3], "levels=%r (넣은 이름=%r)" % (levels, labels))

    # ── 10. 블록 손잡이 → 바꾸기 ──────────────────────────────────
    print("── 10. 블록 손잡이(⋮⋮) → 「블록 바꾸기」")
    reset_doc()
    page.keyboard.type("바꿀 글자")
    page.wait_for_timeout(200)
    first_block = page.locator(".ProseMirror > *").nth(0)
    first_block.hover()
    page.wait_for_timeout(120)
    first_block.locator(".block-handle").click()
    page.wait_for_selector("#block-dialog[open]", timeout=5000)
    step("창의 제목이 「블록 바꾸기」", page.locator("#block-dialog-title").inner_text().strip() == "블록 바꾸기",
         page.locator("#block-dialog-title").inner_text().strip())
    page.locator('#block-list .block-pick[data-block-index="2"]').click()   # 제목 2
    page.wait_for_timeout(80)
    page.click("#block-insert")
    page.wait_for_timeout(250)
    step("DOM 의 블록 종류가 h2 로 바뀌었다", kinds() == ["h2"], "블록=%r" % kinds())
    wait_saved()
    blocks = saved_doc(page_id)["blocks"]
    step("저장 JSON 은 heading(level 2) 이고 **글자가 그대로다**",
         len(blocks) == 1 and blocks[0]["type"] == "heading" and blocks[0].get("level") == 2
         and block_text(blocks[0]) == "바꿀 글자",
         "type=%r level=%r text=%r" % (blocks[0]["type"], blocks[0].get("level"), block_text(blocks[0])))

    # ── 11·12. 글자색 · 배경색 ────────────────────────────────────
    print("── 11·12. 글자를 골라 「색」·「배경」")
    reset_doc()
    page.keyboard.type("빨강글자")
    page.keyboard.press("Enter")
    page.keyboard.type("노랑배경")
    page.wait_for_timeout(150)

    page.keyboard.press("Shift+Home")
    page.wait_for_timeout(200)
    page.click("#tool-bg")
    page.wait_for_timeout(120)
    page.click('#inline-toolbar button[data-color="yellow_bg"]')
    page.wait_for_timeout(200)

    page.click(".ProseMirror > *:nth-child(1)")
    page.keyboard.press("End")
    page.keyboard.press("Shift+Home")
    page.wait_for_timeout(200)
    page.click("#tool-color")
    page.wait_for_timeout(120)
    page.click('#inline-toolbar button[data-color="red"]')
    page.wait_for_timeout(200)

    wait_saved()
    blocks = saved_doc(page_id)["blocks"]
    marks = {block_text(b): [s.get("m", {}) for s in (b.get("text") or [])] for b in blocks}
    red_ok = marks.get("빨강글자", [{}])[0].get("color") == "red"
    bg_ok = str(marks.get("노랑배경", [{}])[0].get("color", "")).endswith("_bg")
    step("11 저장 JSON 세그먼트에 m.color = red", red_ok, "marks=%r" % marks.get("빨강글자"))
    step("12 저장 JSON 세그먼트에 배경 색 이름(_bg)", bg_ok, "marks=%r" % marks.get("노랑배경"))

    # ── 13·14. 링크 (입력칸에서 Enter) ────────────────────────────
    print("── 13·14. 「링크」 입력칸에서 Enter")
    reset_doc()
    page.keyboard.type("링크글자")
    page.wait_for_timeout(150)
    page.keyboard.press("Shift+Home")
    page.wait_for_timeout(200)
    page.click("#tool-link")
    page.wait_for_selector("#link-input", timeout=3000)

    before_html = page.locator(".ProseMirror").inner_html()
    page.fill("#link-input", "javascript:alert(1)")
    page.press("#link-input", "Enter")
    page.wait_for_timeout(300)
    note = page.locator("#link-note").inner_text().strip()
    step("14 허용 밖 스킴은 걸리지 않는다", page.locator(".ProseMirror a").count() == 0,
         "a=%d" % page.locator(".ProseMirror a").count())
    step("14 창 안(툴바)에 한 줄로 알린다", note != "", "알림=%r" % note)
    step("14 문서는 그대로다", page.locator(".ProseMirror").inner_html() == before_html)
    step("14 화면 위 #msg 로 보내지 않는다", page.locator("#msg").inner_text().strip() == "",
         "#msg=%r" % page.locator("#msg").inner_text().strip())

    page.fill("#link-input", "https://example.com/")
    page.press("#link-input", "Enter")
    page.wait_for_timeout(300)
    n_a = page.locator(".ProseMirror a").count()
    href = page.locator(".ProseMirror a").first.get_attribute("href") if n_a else ""
    step("13 Enter 만으로 <a href> 가 생긴다", n_a == 1 and href == "https://example.com/",
         "a=%d href=%r" % (n_a, href))
    wait_saved()
    blocks = saved_doc(page_id)["blocks"]
    link_href = ""
    for b in blocks:
        for s in (b.get("text") or []):
            if isinstance(s.get("m"), dict) and isinstance(s["m"].get("link"), dict):
                link_href = s["m"]["link"].get("href", "")
    step("13 저장 JSON 에 m.link.href", link_href == "https://example.com/", "href=%r" % link_href)

    # ── 15. `/` 는 그냥 글자 ──────────────────────────────────────
    print("── 15. `/` 를 친다")
    reset_doc()
    page.keyboard.type("/블록")
    page.wait_for_timeout(200)
    step("슬래시 메뉴는 없다 (#slash-menu 자체가 사라졌다)",
         page.locator("#slash-menu").count() == 0)
    wait_saved()
    blocks = saved_doc(page_id)["blocks"]
    step("`/` 가 글자 그대로 들어간다",
         len(blocks) == 1 and block_text(blocks[0]) == "/블록",
         "저장된 글자=%r" % (block_text(blocks[0]) if blocks else None))

    # ── 16. 툴바 ──────────────────────────────────────────────────
    print("── 16. 툴바 — 한 줄 · 단추 셋 · 닫힌 폭")
    reset_doc()
    page.keyboard.type("툴바시험")
    page.keyboard.press("Shift+Home")
    page.wait_for_timeout(250)
    rows = page.locator("#inline-toolbar .editor-toolbar__row").count()
    n_btn = page.locator("#inline-toolbar button:visible").count()
    box = page.locator("#inline-toolbar").bounding_box() or {"width": 9999}
    n_title = page.locator(".btn--icon[title]").count()
    step("`.editor-toolbar__row` 가 1개", rows == 1, "%d개" % rows)
    step("닫힌 툴바의 단추가 6개 이하", n_btn <= 6, "%d개" % n_btn)
    step("닫힌 툴바의 폭이 320px 이하", box["width"] <= 320, "%.0fpx" % box["width"])
    step("화면에 `.btn--icon[title]` 0개", n_title == 0, "%d개" % n_title)
    step("굵게·기울임·밑줄·취소선·인라인 코드 단추가 없다",
         page.locator("#inline-toolbar button", has_text=re.compile(r"^(B|I|U|S|</>)$")).count() == 0)
    page.keyboard.press("End")
    page.wait_for_timeout(150)

    # ── 17. 빠른 타자 (delay=0) ───────────────────────────────────
    print("── 17. 빠른 타자 — delay=0 으로 200자 + 줄바꿈 10회")
    reset_doc()
    chunks = ["FAST%02dabcdefghijklmn" % i for i in range(10)]
    for c in chunks:
        page.keyboard.type(c, delay=0)
        page.keyboard.press("Enter")
    typed_chars = sum(len(c) for c in chunks)
    ok_badge = wait_saved(60)
    step("저장 배지가 「저장됨」", ok_badge, "배지=%r" % badge_text())
    page.goto(page_url, wait_until="networkidle")
    page.wait_for_selector("#page-doc .doc", timeout=10000)
    blocks = saved_doc(page_id)["blocks"]
    got = [block_text(b) for b in blocks if block_text(b) != ""]
    step("친 글자 200자가 **한 글자도 빠지지 않는다**",
         got == chunks and sum(len(g) for g in got) == typed_chars,
         "친 것 %d자 / 남은 것 %d자 — %r" % (typed_chars, sum(len(g) for g in got), got))
    step("줄바꿈 10회가 블록 10개로 남았다", len(got) == 10, "%d개" % len(got))

    # ── 18. 저장 중에 이어서 치기 ─────────────────────────────────
    print("── 18. 저장 요청이 날아간 직후 20자 더 치기")
    page.wait_for_selector("#page-edit:not([hidden])", timeout=10000)
    page.click("#page-edit")
    page.wait_for_selector(".ProseMirror", timeout=10000)
    page.click(".ProseMirror > *:nth-child(10)")
    page.keyboard.press("End")
    page.keyboard.press("Enter")

    n_before = len(patch_started)
    delay_patch["on"] = True
    page.keyboard.type("INFLIGHT", delay=0)      # 여기서 1.5초 뒤 저장이 나간다
    waited = 0
    while len(patch_started) == n_before and waited < 8000:
        page.wait_for_timeout(100)
        waited += 100
    step("저장 요청이 실제로 날아갔다", len(patch_started) > n_before,
         "PATCH %d건" % (len(patch_started) - n_before))
    tail = "TAIL0123456789abcdef"                 # 20자
    page.keyboard.type(tail, delay=0)             # ★ 요청이 아직 돌아오지 않은 사이
    delay_patch["on"] = False
    step("이어치는 동안 배지가 「저장됨」이 아니다", badge_text() != "저장됨", "배지=%r" % badge_text())
    ok_badge = wait_saved(60)
    step("끝난 뒤 배지가 「저장됨」", ok_badge, "배지=%r" % badge_text())

    page.goto(page_url, wait_until="networkidle")
    page.wait_for_selector("#page-doc .doc", timeout=10000)
    blocks = saved_doc(page_id)["blocks"]
    all_text = "".join(block_text(b) for b in blocks)
    step("저장 중에 친 20자가 새로 고침 뒤에도 남아 있다", tail in all_text,
         "본문 %d자, 끝 40자=%r" % (len(all_text), all_text[-40:]))
    step("그 앞의 8자도 함께 남아 있다", "INFLIGHT" in all_text)

    # ── 19(중간 집계) 렌더러 동등성 (C:T-19 의 JS 쪽) ─────────────
    diff = None
    if SAMPLES and PHP_RENDER:
        print("── (덤) 렌더러 동등성 — 표본 20개, doc-render.js")
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

    # ── 20. 정리 — 만든 페이지를 삭제 단추로 지운다 ───────────────
    print("── 20. 정리 (삭제 단추)")
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
    gone = page.request.get(COMMUNITY + "/api/pages/" + page_id)
    step("지운 페이지는 창구가 더 이상 주지 않는다 (404)", gone.status == 404, "HTTP %d" % gone.status)

    # ── 19. 전 과정의 오류 ────────────────────────────────────────
    print("── 19. 브라우저가 본 오류 (처음부터 끝까지)")
    for name, items in (("pageerror", pageerrors), ("console error", console_errors),
                        ("requestfailed", req_failed), ("HTTP>=400", http_bad),
                        ("CSP inline style(브라우저 내부)", csp_style)):
        print("   %-28s %d건%s" % (name, len(items), (" — " + " | ".join(items[:3])) if items else ""))
    step("끝까지 pageerror 0건", len(pageerrors) == 0, " | ".join(pageerrors[:2]))
    step("끝까지 콘솔 오류 0건 (브라우저 내부 CSP 차단 제외 — 머리글 ★)",
         len(console_errors) == 0, " | ".join(console_errors[:2]))
    step("끝까지 실패한 요청 0건", len(req_failed) == 0, " | ".join(req_failed[:2]))
    step("끝까지 HTTP 400 이상 0건", len(http_bad) == 0, " | ".join(http_bad[:2]))

    browser.close()

print("PAGEERROR=%d" % len(pageerrors))
print("CONSOLEERR=%d" % len(console_errors))
print("REQFAIL=%d" % len(req_failed))
print("HTTP4XX=%d" % len(http_bad))
print("CSPSTYLE=%d" % len(csp_style))
if diff is not None:
    print("DIFF=%d" % diff)
print("SMOKE_FAIL=%d" % fails)
sys.exit(1 if fails else 0)
