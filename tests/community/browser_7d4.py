#!/usr/bin/env python3
# tests/community/browser_7d4.py — 7-D4 「글 안 멘션」을 진짜 브라우저로 눌러 본다 (C:T-227 · C:T-225 JS 쪽)
# 부르는 쪽: tests/community/stage7d4.sh (임시 인스턴스 · 임시 DB). 단독 실행 불가.
#   쓰는 법: python3 browser_7d4.py <SITE> <UA> <WORK> <편집기 페이지 PE> <공유받은 글 PS> <멘션 모음 글 H> <사내 페이지 T3> <손님 공유 페이지 T6> <스크린샷 폴더>
#            표준입력 세 줄 = 세션 쿠키(employee01 · employee02 · guest01) — 명령 인자·환경 변수에 싣지 않는다(R-62 · py_secret)
#   WORK 에 stage7d4.sh 가 둔 samples225.json · php225.json(C:T-225 PHP 쪽)이 있어야 한다. 결과 줄: DIFF=n · SMOKE_FAIL=n
#
# 무엇을 보나(지시서 3.7 C:T-227)
#   ① 직원 편집기: 툴바 한 줄 · 닫힌 폭 320 이하 · 「멘션」은 글자를 고르지 않아도 쓸 수 있다(색·배경·링크는 흐림) ·
#      「멘션」 → 사람 한 글자 찾기 → 고르기 → 편집기에 「@이름 (아이디)」(글자 「@」 + data-label · ::after) · 뒤에 친 글자는 멘션에 붙지 않는다 ·
#      페이지 멘션 · 날짜 멘션 · Backspace 로 지움 · HTML 붙여넣기에 멘션 0 · 저장 JSON 에 이름·제목 0
#   ② 읽기 화면 라벨 · 페이지 링크(href=/community/p/<uuid> · target 없음) 눌러 이동
#   ③ 다른 계정(그 페이지를 못 보는 사람이 공유받은 글) 「@볼 수 없음」 · 손님은 사람 「@볼 수 없음」
#   ④ 360 · 412(터치·마우스) · 820 · 1280 × 라이트·다크 + 고대비 한 조합(360 터치 · data-contrast="high") — 멘션 줄을 연 편집기·읽기 화면 가로 넘침 0 ·
#      툴바 한 줄 · 320 이하 · 폰 폭 누름 자리 44px(DESIGN 4장 — 툴바 단추 · 후보 항목) · 이웃 단추가 누름 자리를 가져가지 않는다
#   ⑤ C:T-225 — doc-render.js 의 텍스트 노드 열·클래스 집합·링크(class·href·target)를 PHP 쪽과
#   공통: 콘솔 오류 0 · pageerror 0 · 브라우저 기본 창(alert·confirm·prompt) 0 · HTTP 400 이상 0
import json
import os
import re
import sys

from playwright.sync_api import sync_playwright

SITE, UA, WORK, PE, PS, H, T3, T6, SHOTS = (sys.argv[1].rstrip("/"), sys.argv[2], sys.argv[3], sys.argv[4], sys.argv[5],
                                            sys.argv[6], sys.argv[7], sys.argv[8], sys.argv[9])
E1 = sys.stdin.readline().rstrip("\n")
E2 = sys.stdin.readline().rstrip("\n")
G1 = sys.stdin.readline().rstrip("\n")
COMMUNITY = SITE + "/community"
NAME_E2 = "검토이칠디사"
LABEL_E2 = NAME_E2 + " (claude_employee02)"
T3_TITLE = "C7D4짝-사내"
T6_TITLE = "C7D4짝-공유"
fails = 0
pageerrors, console_errors, http_bad, native_dialogs = [], [], [], []


def step(name, ok, detail=""):
    global fails
    if not ok:
        fails += 1
    print("  %s %s%s" % ("✔" if ok else "✖", name, ("  — " + str(detail)) if detail else ""))
    sys.stdout.flush()


def host():
    return re.match(r"https?://([^/:]+)", SITE).group(1)


def context(browser, session, width=1280, height=900, touch=False, theme=None, scheme="light"):
    ctx = browser.new_context(user_agent=UA, viewport={"width": width, "height": height}, has_touch=touch, is_mobile=touch,
                              color_scheme=scheme, locale="ko-KR")
    cookies = [{"name": "__Host-tbox_session", "value": session, "domain": host(), "path": "/",
                "httpOnly": True, "secure": True, "sameSite": "Lax"}]
    if theme:
        cookies.append({"name": "tbox_theme", "value": theme, "domain": host(), "path": "/", "sameSite": "Lax"})
    ctx.add_cookies(cookies)
    page = ctx.new_page()
    page.on("pageerror", lambda e: pageerrors.append(str(e)))
    page.on("console", lambda m: console_errors.append(m.text)
            if m.type == "error" and "Failed to load resource" not in m.text else None)
    page.on("response", lambda r: http_bad.append("%d %s" % (r.status, r.url)) if r.status >= 400 and host() in r.url else None)

    def on_dialog(d):
        native_dialogs.append(d.type + ": " + d.message)
        d.dismiss()
    page.on("dialog", on_dialog)
    return ctx, page


def overflow_x(page):
    return page.evaluate("() => document.documentElement.scrollWidth - document.documentElement.clientWidth")


def wait_saved(page, limit=40):
    for _ in range(limit):
        page.wait_for_timeout(500)
        if page.locator("#page-save-state").inner_text().strip() == "저장됨":
            return True
    return False


def shot(page, name):
    try:
        page.screenshot(path=os.path.join(SHOTS, name), full_page=False)
    except Exception as e:   # 스크린샷 실패는 시험 실패가 아니다 — 알리기만
        print("    · 스크린샷 %s 못 찍음: %s" % (name, str(e)[:80]))


def editor_mentions(page):
    return page.eval_on_selector_all(".ProseMirror .doc-mention",
                                     "es => es.map(e => [e.textContent, e.getAttribute('data-label'), getComputedStyle(e, '::after').content])")


def read_mentions(page):
    return page.eval_on_selector_all("#page-doc .doc-mention",
                                     "es => es.map(e => [e.tagName, e.className, e.textContent, e.getAttribute('href'), e.getAttribute('target')])")


def toolbar_state(page):
    return page.evaluate("""() => {
      const w = document.getElementById('inline-toolbar');
      const r = w.getBoundingClientRect();
      const rows = w.querySelectorAll('.editor-toolbar__row').length;
      const tools = {};
      for (const id of ['tool-color', 'tool-bg', 'tool-link', 'tool-mention']) { const b = document.getElementById(id); tools[id] = b ? b.disabled : null; }
      const btns = [...w.querySelectorAll('.editor-toolbar__row > button')].filter(b => b.getClientRects().length > 0);
      const tops = new Set(btns.map(b => Math.round(b.getBoundingClientRect().top)));
      const row = w.querySelector('.editor-toolbar__row').getBoundingClientRect();
      return {width: Math.round(r.width * 10) / 10, rows: rows, tools: tools, lines: tops.size, rowRight: row.right, boxRight: r.right,
              label: document.getElementById('tool-mention') ? document.getElementById('tool-mention').textContent.trim() : null,
              cls: document.getElementById('tool-mention') ? document.getElementById('tool-mention').className : null};
    }""")


def open_mention(page, kind):
    page.click("#tool-mention")
    page.wait_for_selector("#mention-row:not([hidden])", timeout=5000)
    page.select_option("#mention-kind", kind)


def wait_pick(page, text, timeout=8000):
    loc = page.locator("#mention-list [data-mention-pick]", has_text=text)
    loc.first.wait_for(timeout=timeout)
    return loc.first


# 폰 폭 누름 자리(DESIGN 4장) — 보이는 단추의 누름 자리(자기 상자 ∪ ::before 상자) 44×44 이상 (browser_7d2.py TOUCH 와 같은 판정)
TOUCH = r"""(root) => {
  const H = window.innerHeight;
  const out = []; let n = 0;
  const box = (e) => {
    const r = e.getBoundingClientRect();
    let L = r.left, T = r.top, R = r.right, B = r.bottom;
    const s = getComputedStyle(e, '::before');
    if (s.content && s.content !== 'none' && s.position === 'absolute') {
      const t = parseFloat(s.top), l = parseFloat(s.left), rr = parseFloat(s.right), b = parseFloat(s.bottom);
      if (isFinite(t)) T = Math.min(T, r.top + t);
      if (isFinite(b)) B = Math.max(B, r.bottom - b);
      if (isFinite(l)) L = Math.min(L, r.left + l);
      if (isFinite(rr)) R = Math.max(R, r.right - rr);
    }
    return [R - L, B - T];
  };
  const scope = document.querySelector(root);
  if (!scope) return {n: 0, bad: [['범위 없음', 0, 0]]};
  for (const e of scope.querySelectorAll('button, a[href], summary')) {
    if (e.disabled) continue;
    const cs = getComputedStyle(e);
    if (cs.visibility === 'hidden' || cs.display === 'none') continue;
    const r = e.getBoundingClientRect();
    if (r.width < 2 || r.height < 2 || r.bottom < 0 || r.top > H) continue;
    if (e.checkVisibility && !e.checkVisibility({visibilityProperty: true})) continue;
    n++;
    const [w, h] = box(e);
    if (w < 43.5 || h < 43.5) out.push([(e.getAttribute('aria-label') || e.textContent || '').trim().slice(0, 18), Math.round(w), Math.round(h)]);
  }
  return {n: n, bad: out};
}"""

# 이웃이 누름 자리를 가져가지 않는가 — 툴바 단추마다 보이는 상자의 네 변 가운데(2px 안)·한가운데를 눌렀을 때 그 단추 자신이 받는다
#   (모서리는 쓰지 않는다 — 둥근 모서리 밖은 브라우저가 단추로 치지 않는다: 2026-09-27 첫 실행에서 부모 줄이 잡힌 까닭)
OWN_HIT = r"""() => {
  const bad = [];
  for (const b of document.querySelectorAll('#inline-toolbar .editor-toolbar__row > button')) {
    if (b.getClientRects().length === 0 || b.disabled) continue;   // 흐린 단추는 누름 대상이 아니다(pointer-events: none)
    const r = b.getBoundingClientRect();
    const cx = (r.left + r.right) / 2, cy = (r.top + r.bottom) / 2;
    const pts = [[r.left + 2, cy], [r.right - 2, cy], [cx, r.top + 2], [cx, r.bottom - 2], [cx, cy]];
    for (const [x, y] of pts) {
      const hit = document.elementFromPoint(x, y);
      if (!hit || (hit !== b && !b.contains(hit))) { bad.push((b.id || b.textContent.trim()) + '@' + Math.round(x) + ',' + Math.round(y) + '→' + (hit ? (hit.id || hit.className || hit.tagName) : '없음')); }
    }
  }
  return bad;
}"""

DOCRENDER_JS = """
async (samples) => {
  const m = await import('/tbox/assets/community/doc-render.js');
  const out = [];
  for (const s of samples) {
    const host = document.createElement('div');
    host.appendChild(m.renderDoc(s.doc, s.ctx));
    host.normalize();
    const texts = [];
    const classes = new Set();
    const links = [];
    let style = false;
    const walk = (n) => {
      for (const c of n.childNodes) {
        if (c.nodeType === 3) { const t = c.nodeValue.trim(); if (t) texts.push(t); }
        else if (c.nodeType === 1) {
          for (const k of c.classList) classes.add(k);
          if (c.hasAttribute('style')) style = true;
          if (c.tagName === 'A') links.push([c.getAttribute('class'), c.getAttribute('href'), c.getAttribute('target')]);
          walk(c);
        }
      }
    };
    walk(host);
    out.push({texts: texts, classes: Array.from(classes).sort(), links: links, style: style});
  }
  return out;
}
"""

PASTE_JS = """(html) => {
  const pm = document.querySelector('.ProseMirror');
  const dt = new DataTransfer();
  dt.setData('text/html', html);
  dt.setData('text/plain', '붙임 @ @');
  pm.dispatchEvent(new ClipboardEvent('paste', {clipboardData: dt, bubbles: true, cancelable: true}));
}"""

os.makedirs(SHOTS, exist_ok=True)
diff = None

with sync_playwright() as p:
    browser = p.chromium.launch()

    # ══ ① 직원 편집기 ═════════════════════════════════════════════════════════
    print("── ① 직원(1280 · 라이트) — 편집기 「멘션」: 사람 · 페이지 · 날짜 · 뒤에 친 글자 · Backspace · 붙여넣기 · 저장")
    ectx, ep = context(browser, E1)
    ep.goto(COMMUNITY + "/p/" + PE, wait_until="networkidle")
    ep.wait_for_selector("#page-edit:not([hidden])", timeout=10000)
    ep.click("#page-edit")
    ep.wait_for_selector(".ProseMirror", timeout=10000)
    ep.wait_for_load_state("networkidle")
    ep.click(".ProseMirror > *:nth-child(1)")
    ep.keyboard.press("End")
    ep.wait_for_timeout(150)
    tb = toolbar_state(ep)
    step("툴바 한 줄(.editor-toolbar__row 1 · 단추가 한 줄) · 닫힌 폭 320 이하", tb["rows"] == 1 and tb["lines"] == 1 and tb["width"] <= 320 and tb["rowRight"] <= tb["boxRight"] + 0.5, tb)
    step("  넷째 단추 「멘션」(.btn.btn--small.btn--quiet) — 글자를 고르지 않아도 쓸 수 있다 · 색·배경·링크는 흐림",
         tb["label"] == "멘션" and tb["cls"] == "btn btn--small btn--quiet" and tb["tools"] == {"tool-color": True, "tool-bg": True, "tool-link": True, "tool-mention": False}, tb["tools"])
    open_mention(ep, "user")
    step("멘션 줄 — 종류 고르개 · 찾기 칸 · 후보 목록은 줄 안(.editor-palette__row > ul.list.list--scroll)",
         ep.evaluate("() => { const r = document.getElementById('mention-row'); const l = document.getElementById('mention-list'); return r.classList.contains('editor-palette__row') && l.parentElement === r && l.matches('ul.list.list--scroll') && !!document.getElementById('mention-kind') && document.getElementById('mention-q').type === 'search'; }"))
    ep.fill("#mention-q", "검")
    pick = wait_pick(ep, LABEL_E2)
    cands = ep.eval_on_selector_all("#mention-list [data-mention-pick]", "es => es.map(e => e.textContent.trim())")
    step("  사람 — 한 글자(「검」)로 찾으면 후보에 「%s」" % LABEL_E2, any(LABEL_E2 in c for c in cands), cands[:5])
    pick.click()
    ep.wait_for_timeout(200)
    em = editor_mentions(ep)
    step("  고르면 편집기에 「@%s」 — 글자는 「@」 하나 · data-label · ::after 로 이름" % LABEL_E2,
         len(em) == 1 and em[0][0] == "@" and em[0][1] == LABEL_E2 and em[0][2] == '"%s"' % LABEL_E2, em)
    step("  줄이 닫혔다", ep.evaluate("() => document.getElementById('mention-row').hidden"))
    ep.keyboard.type(" 뒤글자")
    ep.wait_for_timeout(150)
    em = editor_mentions(ep)
    step("멘션 뒤에 친 글자는 멘션에 붙지 않는다(inclusive: false)", len(em) == 1 and em[0][0] == "@", em)
    open_mention(ep, "page")
    ep.fill("#mention-q", "C7D4짝")
    wait_pick(ep, T3_TITLE).click()
    ep.wait_for_timeout(200)
    open_mention(ep, "date")
    step("  날짜 — input[type=date] 과 「넣기」(찾기 칸은 숨김)", ep.evaluate("() => document.getElementById('mention-date').type === 'date' && !document.getElementById('mention-date').hidden && document.getElementById('mention-q').hidden"))
    ep.fill("#mention-date", "2026-09-27")
    ep.click("#mention-date-apply")
    ep.wait_for_timeout(200)
    em = editor_mentions(ep)
    step("페이지·날짜 멘션 — 라벨 「%s」 · 「2026-09-27」" % T3_TITLE, [x[1] for x in em] == [LABEL_E2, T3_TITLE, "2026-09-27"], em)
    # Backspace — 둘째 줄 끝에 날짜 멘션을 넣고 곧바로 지운다
    ep.click(".ProseMirror > *:nth-child(2)")
    ep.keyboard.press("End")
    open_mention(ep, "date")
    ep.fill("#mention-date", "2026-10-01")
    ep.click("#mention-date-apply")
    ep.wait_for_timeout(200)
    n2 = ep.evaluate("() => document.querySelectorAll('.ProseMirror > *:nth-child(2) .doc-mention').length")
    ep.keyboard.press("Backspace")
    ep.wait_for_timeout(200)
    n2b = ep.evaluate("() => document.querySelectorAll('.ProseMirror > *:nth-child(2) .doc-mention').length")
    step("Backspace 한 번으로 멘션을 지운다(둘째 줄 1 → 0)", n2 == 1 and n2b == 0, "%d → %d" % (n2, n2b))
    before = len(editor_mentions(ep))
    ep.evaluate(PASTE_JS, '<p>붙임 <span class="doc-mention" data-label="가짜 이름">@</span> <a class="doc-mention" href="/community/p/%s">@가짜 제목</a></p>' % T3)
    ep.wait_for_timeout(300)
    after = len(editor_mentions(ep))
    pasted = ep.evaluate("() => document.querySelector('.ProseMirror').textContent")
    step("HTML 붙여넣기에 멘션 0(parseDOM 없음 — 글자만 들어온다)", after == before and "붙임" in pasted and "가짜 이름" not in pasted, "멘션 %d → %d" % (before, after))
    step("  저장 배지 「저장됨」", wait_saved(ep), ep.locator("#page-save-state").inner_text())
    saved = ep.evaluate("(id) => fetch('/community/api/pages/' + id, {cache: 'no-store'}).then(r => r.json())", PE)
    doc = saved["page"]["doc"]
    raw = json.dumps(doc, ensure_ascii=False)
    segs = [s for b in doc["blocks"] for s in b.get("text", [])]
    ments = [s for s in segs if isinstance(s.get("m"), dict) and "mention" in s["m"]]
    step("저장 JSON — 멘션 셋 · 글자 「@」 · 마크 칸 kind·id 뿐 · 이름·제목 글자 0",
         len(ments) == 3 and all(s["t"] == "@" and sorted(s["m"]["mention"].keys()) == ["id", "kind"] for s in ments)
         and NAME_E2 not in raw and T3_TITLE not in raw and "가짜 이름" not in raw, raw[:300])
    # 붙여넣은 링크의 **글자**(「@가짜 제목」)는 평문으로 들어온다(멘션도 링크도 아니다) — 크롬이 친 빈칸을 nbsp 로 넣는 것은 편집기 공통 동작
    step("  뒤에 친 「 뒤글자」 는 멘션 없는 조각 · 붙여넣은 글자는 평문", any(s["t"].replace("\xa0", " ").startswith(" 뒤글자") and "mention" not in (s.get("m") or {}) for s in segs)
         and any("가짜 제목" in s["t"] and not (s.get("m") or {}) for s in segs), [s["t"] for s in segs])
    step("  창구 지도(mention_labels) — 사람·페이지 둘(날짜는 지도에 없다)", sorted(saved.get("mention_labels", {}).keys()) == sorted(["user:" + str(ments[0]["m"]["mention"]["id"]), "page:" + T3]) if ments else False, saved.get("mention_labels"))
    shot(ep, "d4-editor-1280-light.png")

    # ══ ② 읽기 화면 · 링크 이동 ══════════════════════════════════════════════════
    print("── ② 읽기 화면 — 라벨 · 페이지 링크(사이트 안) 눌러 이동")
    ep.click("#page-done")
    ep.wait_for_selector("#page-edit:not([hidden])", timeout=10000)
    ep.wait_for_timeout(300)
    rm = read_mentions(ep)
    step("읽기 화면 멘션 셋 — 「@이름 (아이디)」 · 「@제목」(a) · 「@2026-09-27」", [x[2] for x in rm] == ["@" + LABEL_E2, "@" + T3_TITLE, "@2026-09-27"] and [x[0] for x in rm] == ["SPAN", "A", "SPAN"], rm)
    step("  페이지 링크 href = /community/p/<uuid> · target 없음", len(rm) == 3 and rm[1][3] == "/community/p/" + T3 and rm[1][4] is None, rm[1] if len(rm) > 1 else None)
    step("  인라인 style 0(읽기 화면 본문)", ep.evaluate("() => document.querySelectorAll('#page-doc [style]').length") == 0)
    shot(ep, "d4-read-1280-light.png")
    ep.click("#page-doc a.doc-mention")
    ep.wait_for_url(re.compile(r".*/community/p/" + T3 + r"$"), timeout=10000)
    ep.wait_for_load_state("networkidle")
    step("페이지 링크를 누르면 그 페이지로(같은 탭)", ep.url.endswith("/community/p/" + T3) and T3_TITLE in ep.inner_text("#page-title"), ep.url)
    ectx.close()

    # ══ ③ 못 보는 사람 · 손님 ═══════════════════════════════════════════════════
    print("── ③ employee02(못 보는 페이지를 멘션한 글을 공유받음) · 손님(사람 멘션)")
    c2, p2 = context(browser, E2)
    p2.goto(COMMUNITY + "/p/" + PS, wait_until="networkidle")
    p2.wait_for_selector("#page-doc .doc-mention", timeout=10000)
    rm2 = read_mentions(p2)
    step("employee02 — 못 보는 비공개 페이지 멘션은 「@볼 수 없음」(글자만 · 링크 0) · 사람은 이름",
         [x[2] for x in rm2] == ["@볼 수 없음", "@" + LABEL_E2] and rm2[0][0] == "SPAN" and "doc-mention--unknown" in rm2[0][1], rm2)
    step("  본문 어디에도 그 페이지 제목 0", "C7D4짝-비공개" not in p2.inner_text("body"))
    shot(p2, "d4-other-1280-light.png")
    c2.close()
    cg, pg = context(browser, G1)
    pg.goto(COMMUNITY + "/p/" + H, wait_until="networkidle")
    pg.wait_for_selector("#page-doc .doc-mention", timeout=10000)
    rmg = read_mentions(pg)
    users = rmg[:4]
    step("손님 — 사람 멘션 넷 모두 「@볼 수 없음」 · 날짜는 그대로", [x[2] for x in users] == ["@볼 수 없음"] * 4 and rmg[4][2] == "@2026-09-27", [x[2] for x in rmg])
    step("  손님이 볼 수 있는 페이지(보기 공유 T6)는 「@%s」 링크" % T6_TITLE, any(x[0] == "A" and x[2] == "@" + T6_TITLE and x[3] == "/community/p/" + T6 for x in rmg), [x[2] for x in rmg])
    step("  손님 화면에 이름·아이디 글자 0 · 편집 단추 없음", NAME_E2 not in pg.inner_text("body") and "claude_employee02" not in pg.inner_text("body")
         and pg.evaluate("() => { const b = document.getElementById('page-edit'); return !b || b.hidden; }"))
    shot(pg, "d4-guest-1280-light.png")
    cg.close()

    # ══ ⑤ C:T-225 JS 쪽 ═══════════════════════════════════════════════════════
    print("── ⑤ C:T-225 렌더러 동등 — doc-render.js (텍스트 노드 · 클래스 · 링크 class·href·target)")
    cj, pj = context(browser, E1)
    pj.goto(COMMUNITY + "/", wait_until="networkidle")
    try:
        samples = json.load(open(os.path.join(WORK, "samples225.json"), encoding="utf-8"))
        php = json.load(open(os.path.join(WORK, "php225.json"), encoding="utf-8"))
        js = pj.evaluate(DOCRENDER_JS, samples)
        diff = 0
        for i, (a, b) in enumerate(zip(php, js)):
            al = [list(x) for x in a["links"]]
            if a["texts"] != b["texts"] or sorted(a["classes"]) != sorted(b["classes"]) or al != b["links"]:
                diff += 1
                print("    다름 #%d  PHP %s %s %s · JS %s %s %s" % (i, a["texts"], sorted(a["classes"]), al, b["texts"], sorted(b["classes"]), b["links"]))
        diff += abs(len(php) - len(js))
        step("두 렌더러의 텍스트 노드·클래스 집합·링크가 같다(표본 %d — 멘션 10 + stage3 C:T-19 24)" % len(samples), diff == 0, "다른 표본 %d개" % diff)
        step("  JS 렌더러 인라인 style 0", not any(x["style"] for x in js))
    except Exception as e:
        diff = 99
        step("렌더러 동등 — 표본을 읽거나 그리지 못했다", False, str(e)[:160])
    cj.close()

    # ══ ④ 폭 × 테마 + 고대비 한 조합 ═════════════════════════════════════════════
    print("── ④ 360 · 412(터치·마우스) · 820 · 1280 × 라이트·다크 + 고대비 한 조합 — 멘션 줄을 연 편집기 · 읽기 화면")
    combos = [(360, True, "light", False), (360, True, "dark", False), (412, True, "light", False), (412, True, "dark", False),
              (412, False, "light", False), (412, False, "dark", False), (820, False, "light", False), (820, False, "dark", False),
              (1280, False, "light", False), (1280, False, "dark", False), (360, True, "light", True)]
    for (w, touch, theme, hc) in combos:
        tag = "%d%s-%s%s" % (w, "-touch" if touch else "", theme, "-hc" if hc else "")
        cx, px = context(browser, E1, width=w, height=820, touch=touch, theme=theme, scheme=theme)
        px.goto(COMMUNITY + "/p/" + PE, wait_until="networkidle")
        if hc:
            # 7-D3 의 속성(<html data-contrast="high">) — 토큰 블록은 7-D3 판이 준다(이 판에는 없다). 속성을 켠 채 넘침·콘솔만 본다
            px.evaluate("() => document.documentElement.setAttribute('data-contrast', 'high')")
        rov = overflow_x(px)
        px.wait_for_selector("#page-edit:not([hidden])", timeout=10000)
        px.click("#page-edit")
        px.wait_for_selector(".ProseMirror", timeout=10000)
        px.click(".ProseMirror > *:nth-child(1)")
        px.keyboard.press("End")
        px.keyboard.press("Shift+Home")   # 글자를 골라 다섯 단추를 모두 살린 채로 폭·누름 자리를 잰다
        px.wait_for_timeout(150)
        tb = toolbar_state(px)
        own = px.evaluate(OWN_HIT)
        t1 = px.evaluate(TOUCH, "#inline-toolbar") if w <= 412 else None
        px.keyboard.press("End")
        px.wait_for_timeout(100)
        open_mention(px, "user")
        px.fill("#mention-q", "검")
        try:
            wait_pick(px, LABEL_E2)
        except Exception:
            pass
        px.wait_for_timeout(200)
        eov = overflow_x(px)
        rowr = px.evaluate("() => { const r = document.getElementById('mention-row').getBoundingClientRect(); return [Math.round(r.left), Math.round(r.right), document.documentElement.clientWidth]; }")
        msg = ["읽기 넘침 %d" % rov, "편집 넘침 %d" % eov, "툴바 %s · %d줄" % (tb["width"], tb["lines"]), "멘션 줄 %s" % rowr]
        ok = rov <= 0 and eov <= 0 and tb["width"] <= 320 and tb["lines"] == 1 and rowr[0] >= 0 and rowr[1] <= rowr[2] + 1 and own == []
        if own:
            msg.append("누름 자리 가로챔 %s" % own[:3])
        if w <= 412:
            t2 = px.evaluate(TOUCH, "#mention-row")
            msg.append("툴바 누름 자리 %d개 중 모자람 %s · 멘션 줄 %d개 중 모자람 %s" % (t1["n"], t1["bad"][:4], t2["n"], t2["bad"][:4]))
            ok = ok and t1["n"] >= 5 and t1["bad"] == [] and t2["n"] > 0 and t2["bad"] == []
            hs = px.eval_on_selector_all("#mention-list .list__item", "es => es.map(e => Math.round(e.getBoundingClientRect().height))")
            msg.append("후보 높이 %s" % hs[:3])
            ok = ok and len(hs) > 0 and min(hs) >= 44
        step("멘션 %s" % tag, ok, " · ".join(msg))
        shot(px, "d4-editor-%s.png" % tag)
        px.keyboard.press("Escape")
        cx.close()

    browser.close()

print("── 브라우저가 본 오류")
for e in pageerrors:
    print("   pageerror", e[:200])
for e in console_errors:
    print("   console error", e[:200])
for e in http_bad:
    print("   HTTP>=400", e[:200])
for e in native_dialogs:
    print("   기본 창", e[:200])
step("pageerror 0", pageerrors == [], len(pageerrors))
step("콘솔 오류 0", console_errors == [], len(console_errors))
step("HTTP 400 이상 0", http_bad == [], len(http_bad))
step("브라우저 기본 창 0", native_dialogs == [], len(native_dialogs))
print("DIFF=%d" % (diff if diff is not None else 99))
print("SMOKE_FAIL=%d" % fails)
