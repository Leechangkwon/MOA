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

# **일부러 거부당하는 요청** (4-B 28번: 하위가 더 넓은데 cascade 없이 좁히기).
# 그 400 은 이 시험이 **요구한 결과**이므로 「끝까지 오류 0건」에 넣지 않는다.
# 숨기는 것이 아니라 따로 세어 마지막에 줄 수를 찍는다(브라우저 내부 CSP 차단과 같은 방식).
expected_bad = []
expected_rules = []          # (메서드, URL 조각) — 이 짝에 맞는 400 만 옮겨 담는다


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
        if CSP_STYLE_RE.search(m.text):
            csp_style.append(m.text)
            return
        # 일부러 거부당하기로 한 요청이 날아가 있는 동안의 「400 (Bad Request)」 한 줄.
        # 브라우저가 붙이는 이 메시지에는 URL 이 없으므로, 규칙이 걸려 있는 동안만 옮긴다.
        if expected_rules and "400 (Bad Request)" in m.text:
            expected_bad.append(m.text)
            return
        console_errors.append(m.text)

    page.on("console", on_console)
    page.on("requestfailed", lambda r: req_failed.append("%s %s (%s)" % (
        r.method, r.url, (r.failure or ""))))

    def on_request(r):
        if r.method == "PATCH" and "/community/api/pages/" in r.url:
            patch_started.append(time.time())

    def on_response(r):
        if r.status >= 400:
            line = "%d %s %s" % (r.status, r.request.method, r.url)
            if any(r.request.method == m and frag in r.url for m, frag in expected_rules):
                expected_bad.append(line)
            else:
                http_bad.append(line)
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

    def wait_closed(selector, limit=40):
        """`<dialog>` 가 닫히기를 기다린다.

        `wait_for_selector("#x:not([open])")` 를 쓰면 안 된다 — 닫힌 `<dialog>` 는 **보이지
        않는 요소**라 「보일 때까지」 기다리는 그 함수가 끝내 실패한다(2026-09-23 실측).
        그래서 「`[open]` 인 것이 0개가 될 때까지」로 센다.
        """
        for _ in range(limit):
            if page.locator(selector + "[open]").count() == 0:
                return True
            page.wait_for_timeout(250)
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
        print("EXPECTEDBAD=%d" % len(expected_bad))
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
    # 번호는 CSS 카운터가 그린다(2026-09-23 사용자 지적: 블록 종류는 맞았는데 전부 「1.」이었다).
    # Chromium 은 카운터가 만든 글자를 DOM·접근성 트리 어디에도 내놓지 않아서, 블록마다
    # **계산된 카운터 규칙**을 읽는다 — 번호 목록은 늘리고, 이어진 항목의 첫 줄에서 다시 1 부터.
    # ★ 규칙이 걸려 있어도 번호가 틀릴 수 있다: 끊는 블록 쪽에 reset 을 두었을 때 이 단언은
    #   통과했는데 화면은 11·12 로 이어졌다(스크린샷으로 확인). 규칙을 바꾸면 화면을 한 번 찍어 본다.
    page.keyboard.press("Enter")
    page.keyboard.type("셋")
    page.keyboard.press("Enter")
    page.keyboard.press("Enter")
    page.keyboard.type("끊김")
    page.keyboard.press("Enter")
    page.keyboard.type("1. 새 목록")
    page.wait_for_timeout(150)
    counters = page.evaluate("""() => Array.from(document.querySelectorAll('.ProseMirror > *')).map((el) => {
      const s = getComputedStyle(el);
      return [el.dataset.kind || el.tagName.toLowerCase(), s.counterIncrement, s.counterReset,
              getComputedStyle(el, '::before').content];
    })""")
    first = ["numbered", "doc-num 1", "doc-num 0", 'counter(doc-num) "."']
    rest = ["numbered", "doc-num 1", "none", 'counter(doc-num) "."']
    want = [first, rest, rest, ["p", "none", "none", "none"], first]
    step("번호 목록은 카운터를 늘리고, 끊긴 뒤 첫 항목은 다시 1 부터 센다",
         counters == want, "카운터=%r" % counters)

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
    # ── 16-2. 툴바가 본문을 밀지 않는다 ───────────────────────────
    # 2026-09-23 사용자 지적: 글자를 고를 때 툴바가 나타나고, 색·링크를 누르면 줄이 펼쳐지며
    # 본문이 아래로 밀렸다. 본문 첫 블록의 화면 위치를 재어 「그대로인가」로만 본다.
    print("── 16-2. 글자 고르기 · 팔레트 · 링크 칸 — 본문이 밀리지 않는가")
    reset_doc()
    page.keyboard.type("밀림시험")
    page.wait_for_timeout(200)

    def body_top():
        return round(page.locator(".ProseMirror > *").first.bounding_box()["y"], 1)

    def tools_disabled():
        return page.evaluate("""() => ['tool-color', 'tool-bg', 'tool-link']
          .map((id) => document.getElementById(id).disabled)""")

    top0 = body_top()
    step("글자를 고르지 않았을 때 툴바는 보이고 단추 셋은 쓸 수 없다",
         page.locator("#inline-toolbar").is_visible() and tools_disabled() == [True, True, True],
         "보임=%r disabled=%r" % (page.locator("#inline-toolbar").is_visible(), tools_disabled()))
    page.keyboard.press("Shift+Home")
    page.wait_for_timeout(200)
    top1 = body_top()
    step("글자를 고르면 단추 셋을 쓸 수 있다", tools_disabled() == [False, False, False],
         "disabled=%r" % tools_disabled())
    page.click("#tool-color")
    page.wait_for_timeout(150)
    top2 = body_top()
    page.click("#tool-link")
    page.wait_for_selector("#link-input", timeout=3000)
    top3 = body_top()
    step("글자 고르기·「색」·「링크」를 눌러도 본문 위치가 그대로다",
         top0 == top1 == top2 == top3, "y=%r → %r → %r → %r" % (top0, top1, top2, top3))
    page.keyboard.press("Escape")
    page.click(".ProseMirror > *:nth-child(1)")
    page.keyboard.press("End")
    page.wait_for_timeout(150)
    step("선택을 풀면 열린 줄이 닫힌다",
         page.locator("#link-input").is_visible() is False and tools_disabled() == [True, True, True],
         "링크칸 보임=%r disabled=%r" % (page.locator("#link-input").is_visible(), tools_disabled()))

    # ── 16-3. 긴 글 맨 아래에서 「블록 추가」 ──────────────────────
    # 2026-09-23 사용자 지적: 「블록 추가」가 머리글에 있어 긴 글에서는 위로 올라갔다 내려와야
    # 했다. 지금은 sticky 툴바 안에 있다 — 맨 아래까지 내려간 채로 화면 안에 있고, 누르면
    # 커서 자리(맨 아래)에 들어가야 한다.
    print("── 16-3. 60줄 글의 맨 아래에서 「블록 추가」")
    reset_doc()
    for i in range(60):
        page.keyboard.type("긴글%02d" % i, delay=0)
        page.keyboard.press("Enter")
    page.keyboard.type("마지막 줄")
    page.wait_for_timeout(300)
    page.locator(".ProseMirror > *").last.scroll_into_view_if_needed()
    page.wait_for_timeout(200)
    in_view = page.evaluate("""() => {
      const r = document.getElementById('page-add-block').getBoundingClientRect();
      return r.width > 0 && r.top >= 0 && r.bottom <= window.innerHeight;
    }""")
    scrolled = page.evaluate("() => window.scrollY")
    step("맨 아래로 내려가도 「블록 추가」가 화면 안에 있다", in_view and scrolled > 400,
         "화면 안=%r scrollY=%r" % (in_view, scrolled))
    page.click("#page-add-block")
    page.wait_for_selector("#block-dialog[open]", timeout=5000)
    page.locator("#block-list .block-pick", has_text="구분선").first.click()
    page.click("#block-insert")
    page.wait_for_timeout(300)
    scrolled_after = page.evaluate("() => window.scrollY")
    k = kinds()
    step("누르면 맨 아래(커서 자리)에 들어가고 화면이 위로 튀지 않는다",
         "divider" in k[-3:] and scrolled_after > 400,
         "끝 블록=%r scrollY=%r" % (k[-3:], scrolled_after))
    # 좁은 화면은 셸 상단 바(sticky)가 위에 있다 — 툴바가 그 뒤에 숨으면 「보이는」 것이 아니다.
    # 단추 한가운데 점을 **실제로 덮고 있는 요소**가 단추 자신인지 본다.
    page.set_viewport_size({"width": 390, "height": 800})
    page.wait_for_timeout(300)
    page.locator(".ProseMirror > *").last.scroll_into_view_if_needed()
    page.wait_for_timeout(200)
    on_top = page.evaluate("""() => {
      const b = document.getElementById('page-add-block');
      const r = b.getBoundingClientRect();
      const hit = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);
      return !!hit && b.contains(hit);
    }""")
    step("390px 폭 맨 아래에서도 「블록 추가」가 상단 바에 가리지 않는다", on_top, "맨 위 요소가 단추=%r" % on_top)
    page.set_viewport_size({"width": 1440, "height": 900})
    page.wait_for_timeout(300)

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

    # ══════════════════════════════════════════════════════════════
    # 4-A — 페이지 트리 (stage4a-tree-directive.md 「브라우저」 줄)
    #   창에서 하위 페이지 만들기 → 카드 클릭 → 이동 → 경로 → 뒤로 ·
    #   트리 접기·펼치기 · 옮기기 창
    # 여기서도 단언은 전부 「행동 → 결과」다 — 「요소가 보인다」로 끝나는 줄을 두지 않는다.
    # ══════════════════════════════════════════════════════════════

    # ── 21. 「블록 추가」 창의 「하위 페이지」 ─────────────────────
    print("── 21. 「블록 추가」 → 「하위 페이지」 → 넣기")
    if page.locator("#page-edit:not([hidden])").count() > 0:
        page.click("#page-edit")
    page.wait_for_selector(".ProseMirror", timeout=10000)
    reset_doc()

    before_pages = page.request.get(COMMUNITY + "/api/pages?tree=1").json()["total"]
    page.click("#page-add-block")
    page.wait_for_selector("#block-dialog[open]", timeout=5000)
    picks = page.locator('#block-list .block-pick[data-block-type="child_page"]')
    step("창의 종류 목록에 「하위 페이지」가 있다", picks.count() == 1, "%d개" % picks.count())
    picks.first.click()
    page.wait_for_timeout(150)
    step("고르면 오른쪽 미리보기가 하위 페이지 카드로 바뀐다",
         page.locator("#block-sample .doc-child-page").count() == 1,
         "미리보기 카드 %d개" % page.locator("#block-sample .doc-child-page").count())
    page.click("#block-insert")
    step("「넣기」 뒤 창이 닫힌다", wait_closed("#block-dialog"))
    page.wait_for_timeout(500)

    after_pages = page.request.get(COMMUNITY + "/api/pages?tree=1").json()["total"]
    step("「넣기」를 누르면 페이지가 **한 장 새로 생긴다**", after_pages == before_pages + 1,
         "%d → %d" % (before_pages, after_pages))
    step("편집기 안에 하위 페이지 카드가 들어갔다",
         page.locator(".ProseMirror .doc-child-page").count() == 1,
         "카드 %d개" % page.locator(".ProseMirror .doc-child-page").count())

    wait_saved()
    blocks = saved_doc(page_id)["blocks"]
    child_blocks = [b for b in blocks if b["type"] == "child_page"]
    step("저장 JSON 에 child_page 블록이 있다", len(child_blocks) == 1,
         "types=%r" % [b["type"] for b in blocks])
    child_id = child_blocks[0].get("page_id") if child_blocks else ""
    step("그 블록의 page_id 가 UUID 다",
         isinstance(child_id, str) and re.match(r"^[0-9a-f-]{36}$", child_id) is not None,
         "page_id=%r" % child_id)

    # ── 22. 읽기 모드의 카드를 눌러 그 페이지로 간다 ──────────────
    print("── 22. 「편집 끝」 → 카드 클릭 → 그 페이지로 이동")
    page.click("#page-done")
    page.wait_for_selector("#page-doc .doc", timeout=10000)
    page.wait_for_timeout(300)
    card = page.locator("#page-doc a.doc-child-page")
    step("읽기 모드의 카드가 **링크**다 (제목을 찾았다는 뜻)", card.count() == 1,
         "링크 카드 %d개 / 볼 수 없는 카드 %d개"
         % (card.count(), page.locator("#page-doc .doc-child-page.card--soon").count()))
    card_text = card.first.inner_text().strip() if card.count() else ""
    card.first.click()
    try:
        page.wait_for_url(re.compile(r"/community/p/" + re.escape(child_id) + r"$"), timeout=10000)
        moved_in = True
    except Exception:
        moved_in = False
    step("카드를 누르면 그 하위 페이지로 간다", moved_in, "%s (카드 글자=%r)" % (page.url, card_text))

    # ── 23. 경로(breadcrumb) 와 뒤로 ──────────────────────────────
    print("── 23. 경로가 보이고, 경로를 눌러 부모로 돌아온다")
    page.wait_for_selector("#page-crumb:not([hidden])", timeout=10000)
    crumbs = page.locator("#page-crumb .crumb__item")
    labels = [crumbs.nth(i).inner_text().strip() for i in range(crumbs.count())]
    step("경로가 「내 공간 › 부모 › 이 페이지」 세 칸이다", len(labels) == 3, "경로=%r" % labels)
    step("마지막 칸이 현재 페이지로 표시된다",
         page.locator("#page-crumb .crumb__item.is-current").count() == 1
         and page.locator('#page-crumb [aria-current="page"]').count() == 1)
    page.locator("#page-crumb a.crumb__item").nth(1).click()
    try:
        page.wait_for_url(re.compile(r"/community/p/" + re.escape(page_id) + r"$"), timeout=10000)
        back_ok = True
    except Exception:
        back_ok = False
    step("경로의 부모를 누르면 부모 페이지로 돌아온다", back_ok, page.url)

    # ── 24. 「하위 페이지 만들기」 단추 ───────────────────────────
    print("── 24. 「하위 페이지 만들기」 단추")
    page.wait_for_selector("#page-new-child:not([hidden])", timeout=10000)
    before_cards = page.locator("#page-children .card--link").count()
    page.click("#page-new-child")
    page.wait_for_timeout(1500)
    after_cards = page.locator("#page-children .card--link").count()
    step("누르면 하위 목록이 한 장 늘어난다", after_cards == before_cards + 1,
         "%d → %d" % (before_cards, after_cards))

    # ── 25. 내 공간 트리 — 접기·펼치기 ───────────────────────────
    print("── 25. 내 공간 트리에서 접고 편다")
    page.goto(COMMUNITY + "/", wait_until="networkidle")
    page.wait_for_selector("#community-tree .page-tree__row", timeout=10000)
    node = 'details.page-tree__node:has(> summary a.page-tree__link[href$="/p/%s"])' % page_id
    step("트리에 하위를 가진 마디(<details>)로 그려졌다", page.locator(node).count() == 1,
         "마디 %d개" % page.locator(node).count())
    kid_link = page.locator('%s > .page-tree__kids a.page-tree__link[href$="/p/%s"]' % (node, child_id))
    step("펼친 상태에서 하위 페이지 줄이 보인다",
         kid_link.count() == 1 and kid_link.first.is_visible(), "줄 %d개" % kid_link.count())
    page.click("%s > summary .page-tree__caret" % node)
    page.wait_for_timeout(300)
    step("삼각형을 누르면 접히고 하위 줄이 **사라진다**",
         page.locator("%s[open]" % node).count() == 0 and not kid_link.first.is_visible(),
         "open 마디 %d개" % page.locator("%s[open]" % node).count())
    page.click("%s > summary .page-tree__caret" % node)
    page.wait_for_timeout(300)
    step("다시 누르면 펴지고 하위 줄이 **되돌아온다**",
         page.locator("%s[open]" % node).count() == 1 and kid_link.first.is_visible())

    # ── 26. 옮기기 창 ────────────────────────────────────────────
    print("── 26. 옮기기 창으로 부모를 바꾼다")
    page.goto(COMMUNITY + "/p/" + child_id, wait_until="networkidle")
    page.wait_for_selector("#page-move:not([hidden])", timeout=10000)
    page.click("#page-move")
    page.wait_for_selector("#move-dialog[open]", timeout=5000)
    page.wait_for_selector("#move-tree .move-tree__pick", timeout=10000)
    self_pick = page.locator('#move-tree .move-tree__pick[data-parent-id="%s"]' % child_id)
    step("옮기기 창에 **자기 자신은 고를 수 없다**", self_pick.count() == 0,
         "자기 자신 항목 %d개" % self_pick.count())
    page.locator('#move-tree .move-tree__pick[data-parent-id=""]').first.click()
    page.wait_for_timeout(150)
    step("고른 항목에 표시가 붙는다",
         page.locator("#move-tree .move-tree__pick.is-selected").count() == 1)
    page.click("#move-confirm")
    step("「여기로 옮기기」 뒤 창이 닫힌다", wait_closed("#move-dialog"))
    page.wait_for_timeout(800)
    crumbs = page.locator("#page-crumb .crumb__item")
    labels = [crumbs.nth(i).inner_text().strip() for i in range(crumbs.count())]
    step("맨 위로 옮기면 경로가 「내 공간 › 이 페이지」 두 칸으로 줄어든다",
         len(labels) == 2, "경로=%r" % labels)
    parent_now = page.request.get(COMMUNITY + "/api/pages/" + page_id).json()
    step("옛 부모의 하위 목록에서도 빠졌다",
         all(c["id"] != child_id for c in parent_now["children"]),
         "하위=%r" % [c["title"] for c in parent_now["children"]])

    print("── 26-b. 다시 원래 부모 아래로 되돌린다 (정리가 함께 지우도록)")
    page.click("#page-move")
    page.wait_for_selector("#move-dialog[open]", timeout=5000)
    page.wait_for_selector("#move-tree .move-tree__pick", timeout=10000)
    page.locator('#move-tree .move-tree__pick[data-parent-id="%s"]' % page_id).first.click()
    page.click("#move-confirm")
    step("되돌리기 뒤 창이 닫힌다", wait_closed("#move-dialog"))
    page.wait_for_timeout(800)
    step("되돌리면 경로가 다시 세 칸이 된다",
         page.locator("#page-crumb .crumb__item").count() == 3,
         "칸 %d개" % page.locator("#page-crumb .crumb__item").count())

    page.goto(page_url, wait_until="networkidle")
    page.wait_for_selector("#page-doc .doc", timeout=10000)

    # ══════════════════════════════════════════════════════════════
    # 4-B — 공개 범위 · 팀 공간 (stage4b-visibility-directive.md 「브라우저」 줄)
    #   범위를 바꾸면 배지가 바뀌고 새로 고침 뒤에도 유지 · 하위까지 적용 체크가 동작 ·
    #   팀 공간으로 옮겨 다닌다 · 콘솔 오류 0
    # 여기서도 단언은 전부 「행동 → 결과」다.
    # ══════════════════════════════════════════════════════════════

    def vis_badge():
        """머리의 공개 범위 배지 — 글자와 `title`(원래 값) 둘 다 본다 (DESIGN 2.2)."""
        el = page.locator("#page-visibility")
        if el.count() == 0 or el.first.is_hidden():
            return ("", "")
        return (el.first.inner_text().strip(), el.first.get_attribute("title") or "")

    def set_visibility(value, cascade=False):
        """창을 열어 라디오를 고르고 「바꾸기」를 누른다. 창이 닫혔는지를 돌려준다."""
        page.wait_for_selector("#page-visibility-edit:not([hidden])", timeout=10000)
        page.click("#page-visibility-edit")
        page.wait_for_selector("#visibility-dialog[open]", timeout=5000)
        page.locator('#visibility-dialog input[name="page-visibility"][value="%s"]' % value).check()
        box = page.locator("#visibility-cascade")
        if cascade:
            box.check()
        else:
            box.uncheck()
        page.click("#visibility-save")
        page.wait_for_timeout(700)
        return page.locator("#visibility-dialog[open]").count() == 0

    # ── 27. 공개 범위 창 — 바꾸면 배지가 바뀌고 새로 고침 뒤에도 유지 ──
    print("── 27. 「공개 범위」 창에서 범위를 바꾼다")
    step("처음 배지는 「비공개」다", vis_badge() == ("비공개", "private"), "배지=%r" % (vis_badge(),))

    step("「팀」으로 바꾸면 창이 닫힌다", set_visibility("team"))
    step("배지가 「팀」으로 바뀐다", vis_badge() == ("팀", "team"), "배지=%r" % (vis_badge(),))
    page.reload(wait_until="networkidle")
    page.wait_for_selector("#page-doc .doc", timeout=10000)
    page.wait_for_timeout(300)
    step("새로 고침 뒤에도 「팀」이 유지된다", vis_badge() == ("팀", "team"), "배지=%r" % (vis_badge(),))
    step("창구가 준 값도 team 이다",
         page.request.get(COMMUNITY + "/api/pages/" + page_id).json()["page"]["visibility"] == "team")

    step("「사내」로 바꾸면 창이 닫힌다", set_visibility("internal"))
    step("배지가 「사내」로 바뀐다", vis_badge() == ("사내", "internal"), "배지=%r" % (vis_badge(),))

    # ── 28. 하위까지 적용 체크 ────────────────────────────────────
    print("── 28. 하위를 더 넓게 열어 두고, 「하위 페이지까지 함께」로 한 번에 좁힌다")
    page.goto(COMMUNITY + "/p/" + child_id, wait_until="networkidle")
    page.wait_for_selector("#page-doc", timeout=10000)
    step("하위 페이지를 「사내」로 연다", set_visibility("internal"))
    step("하위 배지가 「사내」다", vis_badge() == ("사내", "internal"), "배지=%r" % (vis_badge(),))

    page.goto(page_url, wait_until="networkidle")
    page.wait_for_selector("#page-doc .doc", timeout=10000)
    page.wait_for_selector("#page-visibility-edit:not([hidden])", timeout=10000)
    page.click("#page-visibility-edit")
    page.wait_for_selector("#visibility-dialog[open]", timeout=5000)
    page.locator('#visibility-dialog input[name="page-visibility"][value="private"]').check()
    page.locator("#visibility-cascade").uncheck()
    # 이 한 번은 **일부러 400 을 받는다**(children_wider). 규칙을 켜 두었다가 바로 끈다.
    expected_rules.append(("PATCH", "/visibility"))
    page.click("#visibility-save")
    page.wait_for_timeout(700)
    expected_rules.clear()
    step("체크 없이 좁히면 창이 **열린 채로** 남는다",
         page.locator("#visibility-dialog[open]").count() == 1)
    step("창 안에 사람 문장이 뜬다 (「하위 페이지가 더 넓게…」)",
         "하위 페이지가 더 넓게" in page.locator("#visibility-msg").inner_text(),
         "문구=%r" % page.locator("#visibility-msg").inner_text().strip())
    step("거부된 사이 배지는 그대로 「사내」다", vis_badge() == ("사내", "internal"),
         "배지=%r" % (vis_badge(),))

    page.locator("#visibility-cascade").check()
    page.click("#visibility-save")
    page.wait_for_timeout(900)
    step("체크를 켜고 다시 누르면 창이 닫힌다",
         page.locator("#visibility-dialog[open]").count() == 0)
    step("배지가 「비공개」로 바뀐다", vis_badge() == ("비공개", "private"), "배지=%r" % (vis_badge(),))
    kid = page.request.get(COMMUNITY + "/api/pages/" + child_id).json()["page"]
    step("하위 페이지도 함께 「비공개」가 됐다 (창구 원문으로 확인)",
         kid["visibility"] == "private", "하위 visibility=%r" % kid["visibility"])

    # ── 29. 공간 고르개 · 팀 공간 ─────────────────────────────────
    print("── 29. 공간 고르개로 팀 공간에 다녀온다")
    page.goto(COMMUNITY + "/", wait_until="networkidle")
    page.wait_for_selector("#community-tree .page-tree__row", timeout=10000)

    spaces = page.request.get(COMMUNITY + "/api/spaces").json()["spaces"]
    team_spaces = [sp for sp in spaces if sp["kind"] == "team"]
    if not team_spaces:
        step("팀 공간이 있어야 이 항목을 잰다 (검토 계정에 팀이 없다)", False,
             "공간=%r" % [sp["kind"] for sp in spaces])
    else:
        team_space = team_spaces[0]
        options = page.locator("#community-space option")
        values = [options.nth(i).get_attribute("value") for i in range(options.count())]
        step("고르개에 내 개인 공간과 팀 공간이 모두 들어 있다",
             str(team_space["id"]) in values and len(values) == len(spaces),
             "항목=%r" % values)
        step("셸 왼쪽에 팀 공간 링크가 생겼다",
             page.locator('#nav-community-teams a[href*="space=%d"]' % team_space["id"]).count() == 1)

        page.select_option("#community-space", str(team_space["id"]))
        try:
            page.wait_for_url(re.compile(r"/community/\?space=%d$" % team_space["id"]), timeout=10000)
            switched = True
        except Exception:
            switched = False
        step("고르면 그 공간으로 **주소가 바뀌며** 이동한다", switched, page.url)
        page.wait_for_timeout(500)
        step("화면 머리가 팀 이름으로 바뀐다",
             page.locator("#community-space-title").inner_text().strip() == team_space["name"],
             "머리=%r" % page.locator("#community-space-title").inner_text().strip())

        before = page.request.get(COMMUNITY + "/api/pages?tree=1&space=%d" % team_space["id"]).json()["total"]
        page.click("#community-new")
        try:
            page.wait_for_url(PAGE_RE, timeout=10000)
            made = True
        except Exception:
            made = False
        step("「새 페이지」를 누르면 **그 팀 공간에** 만들어진다", made, page.url)
        team_page_id = page.url.rsplit("/", 1)[-1]
        page.wait_for_selector("#page-doc", timeout=10000)
        page.wait_for_timeout(400)
        after = page.request.get(COMMUNITY + "/api/pages?tree=1&space=%d" % team_space["id"]).json()["total"]
        step("팀 공간의 페이지가 한 장 늘었다", after == before + 1, "%d → %d" % (before, after))
        made_row = page.request.get(COMMUNITY + "/api/pages/" + team_page_id).json()
        step("그 페이지의 공간이 팀 공간이다", made_row["space"]["id"] == team_space["id"],
             "space=%r" % made_row["space"])
        step("팀 공간 페이지에는 「공개 범위」 단추가 없다 (공간 등급을 따른다)",
             page.locator("#page-visibility-edit:not([hidden])").count() == 0)
        step("그 자리에 「팀 공간」 배지가 보인다", vis_badge() == ("팀 공간", "team"),
             "배지=%r" % (vis_badge(),))

        print("── 29-b. 팀 공간에 만든 페이지를 지운다 (정리)")
        page.click("#page-delete")
        page.wait_for_selector("#delete-dialog[open]", timeout=5000)
        page.click("#delete-confirm")
        page.wait_for_timeout(1200)
        gone_team = page.request.get(COMMUNITY + "/api/pages/" + team_page_id)
        step("팀 공간의 그 페이지는 창구가 더 이상 주지 않는다 (404)",
             gone_team.status == 404, "HTTP %d" % gone_team.status)

    page.goto(page_url, wait_until="networkidle")
    page.wait_for_selector("#page-doc .doc", timeout=10000)

    # ── 30. 이력 창 — 탭 둘 · 보기 · 되돌리기 (4-C) ───────────────
    print("── 30. 이력 창 (버전 · 처리 이력 · 보기 · 되돌리기)")
    page.goto(page_url, wait_until="networkidle")
    page.wait_for_selector("#page-doc .doc", timeout=10000)

    page.click("#page-history")
    page.wait_for_selector("#history-dialog[open]", timeout=5000)
    page.wait_for_timeout(600)
    n_rows = page.locator("#history-versions-body tr").count()
    step("30 「이력」을 누르면 창이 열리고 판 목록이 채워진다", n_rows >= 2, "%d줄" % n_rows)
    step("30 맨 윗줄이 「지금」 판이다",
         "(지금)" in page.locator("#history-versions-body tr").first.inner_text(),
         page.locator("#history-versions-body tr").first.inner_text().replace("\n", " "))

    # 목록에 본문 글자가 새지 않는다 (C:T-44) — 본문에만 있던 글자로 확인한다.
    versions_text = page.locator("#history-versions").inner_text()
    step("30 판 목록에 본문 글자가 없다", "INFLIGHT" not in versions_text and "빨강글자" not in versions_text,
         versions_text[:80].replace("\n", " "))

    page.click("#history-tab-events")
    page.wait_for_timeout(400)
    ev_shown = page.locator("#history-events").is_visible()
    ver_hidden = not page.locator("#history-versions").is_visible()
    n_ev = page.locator("#history-events-list li").count()
    step("30 「처리 이력」 탭을 누르면 그쪽이 보이고 버전 탭이 숨는다", ev_shown and ver_hidden,
         "보임=%s 숨김=%s" % (ev_shown, ver_hidden))
    step("30 처리 이력에 줄이 있다", n_ev >= 1, "%d줄" % n_ev)
    events_text = page.locator("#history-events").inner_text()
    step("30 처리 이력에도 본문 글자가 없다",
         "INFLIGHT" not in events_text and "빨강글자" not in events_text,
         events_text[:80].replace("\n", " "))

    page.click("#history-tab-versions")
    page.wait_for_timeout(300)
    step("30 「버전」 탭으로 돌아온다", page.locator("#history-versions").is_visible())

    # 「보기」 — 가장 오래된 판을 이 창 안에서 읽기 렌더러로 그린다
    oldest = page.locator("#history-versions-body tr").last
    old_no = oldest.inner_text().split("\n")[0].strip()
    oldest.locator("td.actions button").first.click()
    page.wait_for_timeout(700)
    prev_shown = page.locator("#history-preview").is_visible()
    prev_title = page.locator("#history-preview-title").inner_text()
    n_prev_blocks = page.locator("#history-preview-doc .doc > *").count()
    step("30 「보기」를 누르면 그 판이 창 안에 그려진다",
         prev_shown and n_prev_blocks >= 1, "제목=%r 블록=%d" % (prev_title, n_prev_blocks))
    prev_text = page.locator("#history-preview-doc").inner_text().strip()

    page.click("#history-preview-back")
    page.wait_for_timeout(300)
    step("30 「목록으로」를 누르면 목록이 돌아온다",
         page.locator("#history-preview").is_visible() is False
         and page.locator("#history-versions").is_visible())

    # 「되돌리기」 — 확인 한 번 더
    before_text = page.locator("#page-doc").inner_text().strip()
    before_ver = page.request.get(COMMUNITY + "/api/pages/" + page_id).json()["page"]["doc_version"]
    n_snap_before = len(page.request.get(
        COMMUNITY + "/api/pages/" + page_id + "/versions").json()["versions"])

    page.locator("#history-versions-body tr").last.locator("td.actions button").last.click()
    page.wait_for_timeout(300)
    step("30 「되돌리기」는 한 번 더 묻는다 (창 안에서)",
         page.locator("#history-confirm").is_visible(),
         page.locator("#history-confirm-text").inner_text())
    step("30 물어보는 동안 본문은 그대로다",
         page.locator("#page-doc").inner_text().strip() == before_text)

    page.click("#history-confirm-ok")
    page.wait_for_timeout(1500)
    after = page.request.get(COMMUNITY + "/api/pages/" + page_id).json()["page"]
    step("30 되돌린 뒤 판 번호가 오른다", after["doc_version"] > before_ver,
         "%d → %d" % (before_ver, after["doc_version"]))
    now_text = page.locator("#page-doc").inner_text().strip()
    step("30 본문이 그 판의 내용으로 바뀐다", now_text == prev_text,
         "화면=%r 판=%r" % (now_text[:40], prev_text[:40]))
    step("30 되돌리기 전 본문과는 달라졌다", now_text != before_text,
         "전=%r 후=%r" % (before_text[:40], now_text[:40]))
    step("30 창은 열린 채로 남고 결과를 알린다",
         page.locator("#history-dialog[open]").count() == 1
         and "되돌렸습니다" in page.locator("#history-msg").inner_text(),
         page.locator("#history-msg").inner_text())

    n_snap_after = len(page.request.get(
        COMMUNITY + "/api/pages/" + page_id + "/versions").json()["versions"])
    step("30 지난 판은 지워지지 않고 늘어난다 (기록 불변)", n_snap_after > n_snap_before,
         "%d → %d" % (n_snap_before, n_snap_after))

    page.keyboard.press("Escape")
    page.wait_for_timeout(300)
    page.reload(wait_until="networkidle")
    page.wait_for_selector("#page-doc .doc", timeout=10000)
    step("30 새로 고쳐도 되돌린 내용이 그대로다 (판 %s)" % old_no,
         page.locator("#page-doc").inner_text().strip() == prev_text,
         page.locator("#page-doc").inner_text().strip()[:40])

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

    # ── 31. 휴지통 — 지운 페이지를 되살린다 (4-C) ─────────────────
    print("── 31. 휴지통 (되살리기)")
    page.goto(COMMUNITY + "/", wait_until="networkidle")
    page.click("#community-trash")
    page.wait_for_selector("#trash-dialog[open]", timeout=5000)
    page.wait_for_timeout(700)
    n_trash = page.locator("#trash-body tr").count()
    step("31 「휴지통」을 누르면 창이 열리고 지운 페이지가 보인다", n_trash >= 1, "%d줄" % n_trash)

    # 맨 윗줄이 방금 지운 그 페이지다(창구가 지운 때 내림차순으로 준다).
    page.locator("#trash-body tr").first.locator("td.actions button").click()
    page.wait_for_timeout(1500)
    back = page.request.get(COMMUNITY + "/api/pages/" + page_id)
    step("31 「되살리기」를 누르면 그 페이지가 돌아온다 (창구 200)",
         back.status == 200, "HTTP %d" % back.status)
    step("31 창이 결과를 알린다", "되살렸습니다" in page.locator("#trash-msg").inner_text(),
         page.locator("#trash-msg").inner_text())

    page.keyboard.press("Escape")
    page.wait_for_timeout(300)
    page.goto(COMMUNITY + "/", wait_until="networkidle")
    page.wait_for_timeout(700)
    tree_text = page.locator("#community-tree").inner_text()
    step("31 되살린 페이지가 트리에 다시 보인다", back.json()["page"]["title"] in tree_text,
         "제목=%r" % back.json()["page"]["title"])

    # 정리 — 다시 지운다(이 시험이 만든 것은 이 시험이 되돌린다).
    page.goto(page_url, wait_until="networkidle")
    page.wait_for_selector("#page-delete:not([hidden])", timeout=10000)
    page.click("#page-delete")
    page.wait_for_selector("#delete-dialog[open]", timeout=5000)
    page.click("#delete-confirm")
    page.wait_for_timeout(1500)
    gone2 = page.request.get(COMMUNITY + "/api/pages/" + page_id)
    step("31 정리 — 다시 지웠다 (404)", gone2.status == 404, "HTTP %d" % gone2.status)

    # ══════════════════════════════════════════════════════════════
    # 32. 상태 · 담당자 칸 (4-D, stage4d-status-assignee-directive.md 「브라우저」 줄)
    #   상태를 바꾸면 배지 색이 바뀌고 새로 고침 뒤에도 유지 · 담당자 찾기의 「나에게」 ·
    #   목록 배지 · 「내가 담당인 페이지」 묶음
    # 단언은 전부 「행동 → 결과」다. 결과는 화면의 배지/묶음과 **창구가 돌려준 JSON** 둘 다로 본다.
    # ══════════════════════════════════════════════════════════════
    print("── 32. 상태 · 담당자 칸 (4-D)")
    page.goto(COMMUNITY + "/", wait_until="networkidle")
    page.wait_for_selector("#community-tree .page-tree__row", timeout=10000)
    page.click("#community-new")
    page.wait_for_url(PAGE_RE, timeout=10000)
    prop_id = page.url.rsplit("/", 1)[-1]
    prop_url = page.url
    page.wait_for_selector("#page-doc", timeout=10000)
    page.wait_for_timeout(400)

    def status_badge():
        """상태 배지 — 글자와 색 클래스(그리고 `title` 의 원래 값)를 함께 본다."""
        el = page.locator("#page-status-badge")
        if el.count() == 0:
            return ("", "", "")
        return (el.first.inner_text().strip(),
                el.first.get_attribute("class") or "",
                el.first.get_attribute("title") or "")

    def set_status(value):
        """창을 열어 라디오를 고르고 「바꾸기」를 누른다. 창이 닫혔는지를 돌려준다."""
        page.wait_for_selector("#page-status:not([disabled])", timeout=10000)
        page.click("#page-status")
        page.wait_for_selector("#status-dialog[open]", timeout=5000)
        page.locator('#status-dialog input[name="page-status"][value="%s"]' % value).check()
        page.click("#status-save")
        page.wait_for_timeout(700)
        return page.locator("#status-dialog[open]").count() == 0

    step("32 속성 줄이 보이고 처음 상태는 「없음」이다",
         page.locator("#page-props").is_visible() and status_badge()[0] == "없음",
         "배지=%r" % (status_badge(),))
    step("32 처음 담당자는 「없음」이다",
         page.locator("#page-assignee-name").inner_text().strip() == "없음",
         page.locator("#page-assignee-name").inner_text().strip())

    step("32 「진행」으로 바꾸면 창이 닫힌다", set_status("doing"))
    doing = status_badge()
    step("32 배지 글자가 「진행」이고 색 클래스가 파랑(doing)으로 바뀐다",
         doing[0] == "진행" and "badge--status-doing" in doing[1] and doing[2] == "doing",
         "배지=%r" % (doing,))

    step("32 「보류」로 바꾸면 색 클래스가 노랑(hold)으로 바뀐다",
         set_status("hold") and "badge--status-hold" in status_badge()[1],
         "배지=%r" % (status_badge(),))

    page.reload(wait_until="networkidle")
    page.wait_for_selector("#page-doc", timeout=10000)
    page.wait_for_timeout(400)
    step("32 새로 고침 뒤에도 「보류」가 유지된다",
         status_badge()[0] == "보류", "배지=%r" % (status_badge(),))
    step("32 창구가 준 값도 hold 다",
         page.request.get(COMMUNITY + "/api/pages/" + prop_id).json()["page"]["status"] == "hold")

    # ── 담당자 찾기 창 · 「나에게」 ────────────────────────────────
    page.wait_for_selector("#page-assignee:not([disabled])", timeout=10000)
    page.click("#page-assignee")
    page.wait_for_selector("#assignee-dialog[open]", timeout=5000)
    page.wait_for_timeout(700)
    n_cand = page.locator("#assignee-list li").count()
    step("32 담당자 창을 열면 후보가 채워진다", n_cand >= 1, "%d명" % n_cand)

    who = page.request.get(COMMUNITY + "/api/assignees?page=" + prop_id).json()
    me_name = who["me"]
    step("32 후보는 창구가 준 명단과 같은 수다",
         n_cand == len(who["assignees"]), "화면 %d · 창구 %d" % (n_cand, len(who["assignees"])))

    page.click("#assignee-me")
    page.wait_for_timeout(900)
    step("32 「나에게」를 누르면 창이 닫힌다",
         page.locator("#assignee-dialog[open]").count() == 0)
    step("32 담당자 칸이 내 계정명으로 바뀐다",
         page.locator("#page-assignee-name").inner_text().strip() == me_name,
         page.locator("#page-assignee-name").inner_text().strip())
    step("32 창구가 준 값도 나다",
         page.request.get(COMMUNITY + "/api/pages/" + prop_id).json()["page"]["assignee"]["username"] == me_name)

    # ── 목록 배지 · 「내가 담당인 페이지」 묶음 ────────────────────
    page.goto(COMMUNITY + "/", wait_until="networkidle")
    page.wait_for_selector("#community-tree .page-tree__row", timeout=10000)
    page.wait_for_timeout(700)
    step("32 「내가 담당인 페이지」 묶음이 보인다",
         page.locator("#community-mine-wrap").is_visible(),
         "숨김=%s" % (not page.locator("#community-mine-wrap").is_visible()))
    mine_hrefs = page.locator("#community-mine a").evaluate_all(
        "els => els.map(e => e.getAttribute('href'))")
    step("32 그 묶음에 방금 담당이 된 페이지가 있다",
         any(prop_id in (h or "") for h in mine_hrefs), "%r" % (mine_hrefs[:3],))
    mine_total = page.request.get(COMMUNITY + "/api/pages?assignee=me").json()["total"]
    step("32 묶음의 줄 수 = 창구가 센 수",
         len(mine_hrefs) == mine_total, "화면 %d · 창구 %d" % (len(mine_hrefs), mine_total))
    step("32 트리 줄에 상태 배지가 붙는다",
         page.locator("#community-tree .badge--status-hold").count() >= 1,
         "%d개" % page.locator("#community-tree .badge--status-hold").count())

    # ── 상태 거르개 ───────────────────────────────────────────────
    page.select_option("#community-status", "done")
    page.wait_for_timeout(900)
    n_after = page.locator("#community-tree .page-tree__row").count()
    api_done = page.request.get(COMMUNITY + "/api/pages?tree=1&status=done").json()["total"]
    step("32 상태 거르개로 「완료」를 고르면 창구가 센 수와 화면 줄 수가 같다",
         n_after == api_done, "화면 %d · 창구 %d" % (n_after, api_done))
    page.select_option("#community-status", "")
    page.wait_for_timeout(700)

    # ── 이력에 상태·담당자 줄이 섞여 나온다 (C:T-52) ──────────────
    page.goto(prop_url, wait_until="networkidle")
    page.wait_for_selector("#page-doc", timeout=10000)
    page.click("#page-history")
    page.wait_for_selector("#history-dialog[open]", timeout=5000)
    page.click("#history-tab-events")
    page.wait_for_timeout(700)
    ev_text = page.locator("#history-events").inner_text()
    step("32 「처리 이력」에 상태 줄이 있다", "상태:" in ev_text, ev_text[:120].replace("\n", " "))
    step("32 「처리 이력」에 담당자 줄이 있다", "담당자:" in ev_text, ev_text[:120].replace("\n", " "))
    page.keyboard.press("Escape")
    page.wait_for_timeout(300)

    # ── 정리 ──────────────────────────────────────────────────────
    page.wait_for_selector("#page-delete:not([hidden])", timeout=10000)
    page.click("#page-delete")
    page.wait_for_selector("#delete-dialog[open]", timeout=5000)
    page.click("#delete-confirm")
    page.wait_for_timeout(1500)
    gone3 = page.request.get(COMMUNITY + "/api/pages/" + prop_id)
    step("32 정리 — 만든 페이지를 지웠다 (404)", gone3.status == 404, "HTTP %d" % gone3.status)

    # ── 19. 전 과정의 오류 ────────────────────────────────────────
    print("── 19. 브라우저가 본 오류 (처음부터 끝까지)")
    for name, items in (("pageerror", pageerrors), ("console error", console_errors),
                        ("requestfailed", req_failed), ("HTTP>=400", http_bad),
                        ("CSP inline style(브라우저 내부)", csp_style),
                        ("일부러 거부당한 요청(4-B 28번)", expected_bad)):
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
print("EXPECTEDBAD=%d" % len(expected_bad))
if diff is not None:
    print("DIFF=%d" % diff)
print("SMOKE_FAIL=%d" % fails)
sys.exit(1 if fails else 0)
