#!/usr/bin/env python3
# tests/community/browser_7d2.py — 7-D2 「팔레트 관리」 창 · 편집기 색 줄 · 읽기 화면 색을 진짜 브라우저로 눌러 본다 (C:T-208 · C:T-206 JS 쪽)
# 부르는 쪽: tests/community/stage7d2.sh (임시 인스턴스 · 임시 DB). 단독 실행 불가.
#   쓰는 법: python3 browser_7d2.py <SITE> <UA> <WORK> <편집기 페이지 id> <손님이 읽는 글 id> <스크린샷 폴더>
#            표준입력 세 줄 = 세션 쿠키(관리자(재인증 뒤) · 직원 · 손님) — 명령 인자·환경 변수에 싣지 않는다(R-62 · py_secret)
#   WORK 에 stage7d2.sh 가 둔 samples206.json · php206.json(C:T-206 PHP 쪽)이 있어야 한다. 결과 줄: DIFF=n · SMOKE_FAIL=n
#
# 무엇을 보나(지시서 1.8 C:T-208)
#   ① 관리자: 더보기 → 「팔레트 관리」 → 더하기 → 줄의 견본 색이 **바로**(`#palette-css` 의 ?v= 가 바뀌고 CSS 요청 1) · 낮은 대비 값에 경고 글
#   ② 직원 편집기: 「색」 줄에 이름 견본(기본 9 뒤 · 「없앰」 앞) → 글자에 적용 → 저장 → 읽기 화면 getComputedStyle 색 = 등록한 라이트값 ·
#      다크(data-theme) → 다크값 · OS 다크(prefers-color-scheme · 속성 없음) → 다크값
#   ③ 관리자가 빼기(두 번 누르기) → 읽기 화면에서 클래스는 남되 계산된 색 = 부모 색
#   ④ 직원: 메뉴 항목 없음(hidden) · 손님: 메뉴 항목 없음 · 편집기 없음(공개 게시판 글)
#   ⑤ 360 · 412(터치·마우스) · 820 · 1280 × 라이트·다크 — 가로 넘침 0 · 창 바닥 단추 규칙(DESIGN 2.7) · 폰 폭 누름 자리 44px(DESIGN 4장)
#   공통: 콘솔 오류 0 · pageerror 0 · 브라우저 기본 창(alert·confirm·prompt) 0 · HTTP 400 이상 0
import json
import os
import re
import sys

from playwright.sync_api import sync_playwright

SITE, UA, WORK, PE, GP, SHOTS = sys.argv[1].rstrip("/"), sys.argv[2], sys.argv[3], sys.argv[4], sys.argv[5], sys.argv[6]
A1 = sys.stdin.readline().rstrip("\n")
E1 = sys.stdin.readline().rstrip("\n")
G1 = sys.stdin.readline().rstrip("\n")
COMMUNITY = SITE + "/community"
fails = 0
pageerrors, console_errors, http_bad, native_dialogs = [], [], [], []

WEB = "c7d2-web"
WEB_LIGHT, WEB_DARK = "#aa3300", "#ff8866"
YEL = "c7d2-yellow"


def rgb(hexv):
    return "rgb(%d, %d, %d)" % (int(hexv[1:3], 16), int(hexv[3:5], 16), int(hexv[5:7], 16))


def tint(c, b, w):
    return "#" + "".join("%02x" % ((int(c[i:i + 2], 16) * w + int(b[i:i + 2], 16) * (100 - w) + 50) // 100) for i in (1, 3, 5))


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
    # 「Failed to load resource」 는 응답 코드마다 브라우저가 찍는 줄 — HTTP 실패는 아래 http_bad 가 따로 센다
    page.on("console", lambda m: console_errors.append(m.text)
            if m.type == "error" and "Failed to load resource" not in m.text else None)
    page.on("response", lambda r: http_bad.append("%d %s" % (r.status, r.url)) if r.status >= 400 and host() in r.url else None)

    def on_dialog(d):
        native_dialogs.append(d.type + ": " + d.message)
        d.dismiss()
    page.on("dialog", on_dialog)
    return ctx, page


def open_home_more(page):
    if not page.evaluate("() => { const m = document.getElementById('community-more'); return !!m && m.open; }"):
        page.click("#community-more > summary")


def shown(page, sel):
    return page.evaluate("s => { const e = document.querySelector(s); return !!e && !e.hidden; }", sel)


def set_color(page, sel, value):
    page.eval_on_selector(sel, """(e, v) => { e.value = v; e.dispatchEvent(new Event('input', {bubbles: true}));
                                              e.dispatchEvent(new Event('change', {bubbles: true})); }""", value)


def computed(page, sel, prop="color"):
    return page.eval_on_selector(sel, "(e, p) => getComputedStyle(e)[p]", prop)


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


# 창 바닥 단추 규칙(DESIGN 2.7 — tests/browser/dbg_u2_ui.py MODAL_RULE 과 같은 판정, 창 id 하나만)
MODAL_RULE = r"""(id) => {
  const bad = [];
  const black = b => b.classList.contains('btn') && !b.matches('.btn--quiet, .btn--danger, .btn--plain, .btn--icon');
  const d = document.getElementById(id);
  if (!d) return ['창 없음'];
  const shownIn = b => { if (b.hidden) return false; const h = b.closest('[hidden]'); return !(h && d.contains(h)); };
  const head = d.querySelector(':scope > .modal__head');
  const x = head && [...head.querySelectorAll('button')].find(b => b.matches('.btn--icon') && b.getAttribute('aria-label') === '닫기');
  if (!x) bad.push('머리에 ✕ 없음');
  const foots = d.querySelectorAll(':scope > .modal__foot, :scope > .form-actions');
  if (foots.length !== 1) return bad.concat(['바닥 ' + foots.length]);
  const foot = foots[0];
  const btns = [...foot.querySelectorAll(':scope > button, :scope > a.btn')].filter(shownIn);
  const closes = btns.filter(b => b.textContent.trim() === '닫기');
  if (closes.length !== 1) return bad.concat(['「닫기」 ' + closes.length]);
  const close = closes[0];
  if (!close.matches('.btn--quiet')) bad.push('「닫기」가 테두리 단추가 아님');
  for (const b of btns) if (/그만두기|취소/.test(b.textContent)) bad.push('낱말 ' + b.textContent.trim());
  const i = btns.indexOf(close);
  const after = btns.slice(i + 1), before = btns.slice(0, i);
  if (after.length > 1) bad.push('「닫기」 오른쪽 ' + after.length);
  if (after.length === 1 && !(black(after[0]) || after[0].matches('.btn--danger:not(.btn--quiet)'))) bad.push('오른쪽 끝이 주요 단추가 아님');
  if (before.some(black)) bad.push('「닫기」 왼쪽 검정');
  const bodyBlack = [...d.querySelectorAll('button, a.btn')].filter(b => !foot.contains(b) && shownIn(b) && black(b) && !b.closest('.modal__head'));
  if (bodyBlack.length) bad.push('본문 검정 ' + bodyBlack.map(b => b.textContent.trim().slice(0, 12)).join('/'));
  const bodySubmit = [...d.querySelectorAll('button[type=submit]')].filter(b => !foot.contains(b) && shownIn(b));
  if (bodySubmit.length) bad.push('본문 제출 ' + bodySubmit.length);
  if (d.open) {
    const W = document.documentElement.clientWidth;
    const rs = btns.map(b => b.getBoundingClientRect()).filter(r => r.width > 0);
    for (let k = 1; k < rs.length; k++) { if (Math.abs(rs[k].top - rs[k - 1].top) < 4 && rs[k].left < rs[k - 1].left) bad.push('보이는 순서 ≠ DOM 순서'); }
    for (const r of rs) if (r.right > W + 1 || r.left < -1) bad.push('바닥 단추가 화면 밖');
  }
  return bad;
}"""

# 폰 폭 누름 자리(DESIGN 4장) — 보이는 단추의 누름 자리(자기 상자 ∪ ::before 상자) 44×44 이상 (dbg_u2_ui.py TOUCH 의 크기 판정)
TOUCH = r"""(root) => {
  const W = document.documentElement.clientWidth, H = window.innerHeight;
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

DOCRENDER_JS = """
async (docs) => {
  const m = await import('/tbox/assets/community/doc-render.js');
  const out = [];
  for (const doc of docs) {
    const host = document.createElement('div');
    host.appendChild(m.renderDoc(doc));
    // 이웃한 글자 노드를 하나로 — PHP 쪽은 HTML 문자열을 태그로 잘라 보므로 태그 없는 이웃 조각이 한 덩어리다(같은 것을 견준다)
    host.normalize();
    const texts = [];
    const classes = new Set();
    let style = false;
    const walk = (n) => {
      for (const c of n.childNodes) {
        if (c.nodeType === 3) { const t = c.nodeValue.trim(); if (t) texts.push(t); }
        else if (c.nodeType === 1) {
          for (const k of c.classList) classes.add(k);
          if (c.hasAttribute('style')) style = true;
          walk(c);
        }
      }
    };
    walk(host);
    out.push({texts: texts, classes: Array.from(classes).sort(), style: style});
  }
  return out;
}
"""

os.makedirs(SHOTS, exist_ok=True)
diff = None

with sync_playwright() as p:
    browser = p.chromium.launch()

    # ══ ① 관리자 — 창 · 더하기 · 견본이 바로 · 대비 경고 ══════════════════════════════
    print("── ① 관리자(1280 · 라이트) — 더보기 → 「팔레트 관리」 → 더하기")
    actx, ap = context(browser, A1)
    css_reqs = []
    ap.on("request", lambda r: css_reqs.append(r.url) if "/community/palette.css" in r.url else None)
    ap.goto(COMMUNITY + "/", wait_until="networkidle")
    ap.wait_for_function("() => { const b = document.getElementById('community-palettes'); return b && !b.hidden; }", timeout=10000)
    open_home_more(ap)
    step("관리자 — 더보기에 「팔레트 관리」 항목", shown(ap, "#community-palettes"))
    href0 = ap.get_attribute("#palette-css", "href") or ""
    step("  셸의 #palette-css 링크(같은 출처 · ?v=12자리)", bool(re.fullmatch(r"/community/palette\.css\?v=[0-9a-f]{12}", href0)), href0)
    ap.click("#community-palettes")
    ap.wait_for_selector("#palette-dialog[open]", timeout=5000)
    ap.wait_for_function("() => /\\//.test(document.getElementById('palette-count').textContent)", timeout=10000)
    ap.wait_for_load_state("networkidle")
    step("  창이 열렸다 · 한도 「n / 12」", "/ 12" in ap.inner_text("#palette-count"), ap.inner_text("#palette-count"))
    n0 = len(css_reqs)
    ap.fill("#palette-name", WEB)
    set_color(ap, "#palette-light", WEB_LIGHT)
    set_color(ap, "#palette-dark", WEB_DARK)
    ap.click("#palette-add")
    ap.wait_for_selector('#palette-list li[data-palette="%s"]' % WEB, timeout=10000)
    ap.wait_for_load_state("networkidle")
    ap.wait_for_timeout(300)
    href1 = ap.get_attribute("#palette-css", "href") or ""
    step("더하기 → #palette-css 의 ?v= 가 새 버전", href1 != href0 and bool(re.fullmatch(r"/community/palette\.css\?v=[0-9a-f]{12}", href1)), "%s → %s" % (href0, href1))
    step("  CSS 요청 1(새 주소 한 번 — 인라인 style 0)", len(css_reqs) - n0 == 1, "요청 %d: %s" % (len(css_reqs) - n0, css_reqs[n0:]))
    fg = computed(ap, '#palette-list li[data-palette="%s"] .pal-%s' % (WEB, WEB))
    bg = computed(ap, '#palette-list li[data-palette="%s"] .pal-%s-bg' % (WEB, WEB), "backgroundColor")
    step("  줄의 글자 견본 색 = 등록한 라이트값(바로)", fg == rgb(WEB_LIGHT), fg)
    step("  줄의 배경 견본 = 라이트 12 : 흰 88 공식", bg == rgb(tint(WEB_LIGHT, "#ffffff", 12)), "%s (기대 %s)" % (bg, rgb(tint(WEB_LIGHT, "#ffffff", 12))))
    step("  줄에 경고 없음(대비 충분 — 6.6:1 · 8.5:1)", ap.locator('#palette-list li[data-palette="%s"] .badge--exec' % WEB).count() == 0)
    step("  인라인 style 속성 0(창 전체)", ap.evaluate("() => document.querySelectorAll('#palette-dialog [style]').length") == 0)
    # 낮은 대비 — 노랑(라이트) · 어두운 회색(다크)
    ap.fill("#palette-name", YEL)
    set_color(ap, "#palette-light", "#ffff00")
    set_color(ap, "#palette-dark", "#333333")
    step("  더하기 전 칸 아래에도 대비 경고(색을 고른 뒤 · 알림일 뿐)", ap.locator("#palette-form-warn .badge--exec").count() == 2, ap.inner_text("#palette-form-warn"))
    ap.click("#palette-add")
    ap.wait_for_selector('#palette-list li[data-palette="%s"]' % YEL, timeout=10000)
    ap.wait_for_load_state("networkidle")
    row_y = ap.locator('#palette-list li[data-palette="%s"]' % YEL)
    wtxt = row_y.inner_text()
    step("낮은 값(#ffff00 · #333333)에 경고 — 주황 배지 둘 · 대비 글", row_y.locator(".badge--exec").count() == 2 and "밝은 화면 대비 1.0:1" in wtxt and "어두운 화면 대비 1.5:1" in wtxt, wtxt.replace("\n", " ")[:160])
    step("  경고여도 저장은 됐다(줄이 있다)", row_y.count() == 1)
    bad = ap.evaluate(MODAL_RULE, "palette-dialog")
    step("창 바닥 단추 규칙(DESIGN 2.7) — 닫기 하나·테두리 · 오른쪽 끝 주요 하나 · 본문 검정·제출 0 · 머리 ✕", bad == [], bad)
    step("  브라우저 기본 창 0", native_dialogs == [], native_dialogs)
    shot(ap, "d2-admin-dialog-1280-light.png")

    # ══ ② 직원 편집기 — 색 줄 · 적용 · 저장 · 읽기 화면 계산 색 ═════════════════════════
    print("── ② 직원 편집기(1280 · 라이트) — 「색」 줄의 이름 견본 → 글자에 → 저장 → 읽기 화면")
    ectx, ep = context(browser, E1)
    ep.goto(COMMUNITY + "/p/" + PE, wait_until="networkidle")
    ep.wait_for_selector("#page-edit:not([hidden])", timeout=10000)
    ep.click("#page-edit")
    ep.wait_for_selector(".ProseMirror", timeout=10000)
    ep.wait_for_load_state("networkidle")
    ep.click(".ProseMirror > *:nth-child(1)")
    ep.keyboard.press("End")
    ep.keyboard.press("Shift+Home")
    ep.wait_for_timeout(200)
    ep.click("#tool-color")
    ep.wait_for_timeout(200)
    order = ep.evaluate("() => [...document.querySelectorAll('#inline-toolbar .editor-palette__row:not([hidden]) button[data-color]')].map(b => b.getAttribute('data-color'))")
    base9 = ["gray", "brown", "orange", "yellow", "green", "blue", "purple", "pink", "red"]
    step("색 줄 — 기본 9 → 팔레트 이름 → 「없앰」 순서", order[:9] == base9 and order[-1] == "none" and ("pal:" + WEB) in order[9:-1] and ("pal:" + YEL) in order[9:-1], order)
    sw = ep.locator('#inline-toolbar button[data-color="pal:%s"]' % WEB)
    step("  견본 단추 aria-label 「글자색 %s」 · 클래스 pal-%s · 색 = 라이트값" % (WEB, WEB),
         sw.count() == 1 and sw.get_attribute("aria-label") == "글자색 " + WEB and "pal-" + WEB in (sw.get_attribute("class") or "")
         and computed(ep, '#inline-toolbar button[data-color="pal:%s"]' % WEB) == rgb(WEB_LIGHT), sw.get_attribute("class") if sw.count() else "없음")
    sw.click()
    ep.wait_for_timeout(200)
    step("  저장 배지 「저장됨」", wait_saved(ep), ep.locator("#page-save-state").inner_text())
    ep.click(".ProseMirror > *:nth-child(2)")
    ep.keyboard.press("End")
    ep.keyboard.press("Shift+Home")
    ep.click("#tool-bg")
    ep.wait_for_timeout(200)
    bsw = ep.locator('#inline-toolbar button[data-color="pal:%s_bg"]' % WEB)
    step("  「배경」 줄에도 같은 이름(pal:%s_bg · 「배경색 %s」)" % (WEB, WEB), bsw.count() == 1 and bsw.get_attribute("aria-label") == "배경색 " + WEB)
    ep.keyboard.press("Escape")
    ep.click("#page-done")
    ep.wait_for_selector("#page-edit:not([hidden])", timeout=10000)
    ep.wait_for_timeout(300)
    sel_read = "#page-doc .pal-%s" % WEB
    step("읽기 화면 — span.pal-%s 가 그려졌다" % WEB, ep.locator(sel_read).count() >= 1)
    step("  getComputedStyle 색 = 등록한 라이트값", computed(ep, sel_read) == rgb(WEB_LIGHT), computed(ep, sel_read))
    ep.evaluate("() => document.documentElement.setAttribute('data-theme', 'dark')")
    ep.wait_for_timeout(100)
    step("  다크로 바꾸면(data-theme) 다크값", computed(ep, sel_read) == rgb(WEB_DARK), computed(ep, sel_read))
    ep.evaluate("() => document.documentElement.setAttribute('data-theme', 'light')")
    step("  밝게 되돌리면 라이트값", computed(ep, sel_read) == rgb(WEB_LIGHT), computed(ep, sel_read))
    shot(ep, "d2-editor-read-1280-light.png")
    octx, op = context(browser, E1, scheme="dark")
    op.goto(COMMUNITY + "/p/" + PE, wait_until="networkidle")
    op.wait_for_selector(sel_read, timeout=10000)
    step("  OS 다크(prefers-color-scheme · 속성 없음) → 다크값", computed(op, sel_read) == rgb(WEB_DARK), computed(op, sel_read))
    octx.close()

    # ══ ③ 빼기(두 번) → 읽기 화면 색 = 부모 색 ═════════════════════════════════════
    print("── ③ 관리자 빼기(두 번 누르기) → 직원 읽기 화면은 색 없이")
    href2 = ap.get_attribute("#palette-css", "href") or ""
    del_btn = ap.locator('#palette-list li[data-palette="%s"] button[aria-label="%s 빼기"]' % (WEB, WEB))
    del_btn.click()
    step("  첫 번째 누름 — 「정말 빼기」 로 바뀌고 줄은 그대로", del_btn.inner_text().strip() == "정말 빼기" and ap.locator('#palette-list li[data-palette="%s"]' % WEB).count() == 1)
    del_btn.click()
    ap.wait_for_selector('#palette-list li[data-palette="%s"]' % WEB, state="detached", timeout=10000)
    ap.wait_for_load_state("networkidle")
    step("  두 번째 누름 — 줄이 빠지고 ?v= 가 또 바뀐다", (ap.get_attribute("#palette-css", "href") or "") != href2)
    step("  기본 창 0(확인 창 없이 두 번 누르기)", native_dialogs == [], native_dialogs)
    ep.reload(wait_until="networkidle")
    ep.wait_for_selector("#page-doc", timeout=10000)
    ep.wait_for_timeout(300)
    step("읽기 화면 — 뺀 이름의 클래스는 남는다(글은 그대로)", ep.locator(sel_read).count() >= 1)
    col, parent = ep.eval_on_selector(sel_read, "e => [getComputedStyle(e).color, getComputedStyle(e.parentElement).color]")
    step("  계산된 색 = 부모 색(색 없이 그려진다)", col == parent, "%s / 부모 %s" % (col, parent))
    step("  CSS 에 그 이름 규칙 0", not ep.evaluate("n => [...document.styleSheets].some(s => { try { return [...s.cssRules].some(r => (r.cssText || '').indexOf('pal-' + n) !== -1); } catch (e) { return false; } })", WEB))

    # ══ ④ 직원 · 손님 ═══════════════════════════════════════════════════════════
    print("── ④ 직원 · 손님 — 메뉴 항목 없음 · 손님은 편집기 없음")
    ep.goto(COMMUNITY + "/", wait_until="networkidle")
    ep.wait_for_timeout(500)
    step("직원 — 「팔레트 관리」 항목 hidden", ep.evaluate("() => { const b = document.getElementById('community-palettes'); return !!b && b.hidden; }"))
    gctx, gp = context(browser, G1)
    gp.goto(COMMUNITY + "/", wait_until="networkidle")
    gp.wait_for_timeout(500)
    step("손님 — 「팔레트 관리」 항목 hidden · 「새 페이지」 hidden", gp.evaluate("() => { const b = document.getElementById('community-palettes'); const n = document.getElementById('community-new'); return !!b && b.hidden && !!n && n.hidden; }"))
    gp.goto(COMMUNITY + "/p/" + GP, wait_until="networkidle")
    gp.wait_for_timeout(500)
    gvis = gp.evaluate("() => [...document.querySelectorAll('#inline-toolbar button, #page-edit, #page-add-block')].filter(b => b.getClientRects().length > 0 && !b.closest('[hidden]')).map(b => b.id || b.textContent.trim())")
    step("손님 — 공개 게시판 글에 편집 단추·편집기 없음", not shown(gp, "#page-edit") and gp.locator(".ProseMirror").count() == 0 and gvis == [],
         "보이는 편집 단추 %s · 편집기 %d" % (gvis, gp.locator(".ProseMirror").count()))
    step("  손님도 회사 색으로 읽는다(pal-brand-1 = #0055aa)", gp.locator("#page-doc .pal-brand-1").count() >= 1 and computed(gp, "#page-doc .pal-brand-1") == rgb("#0055aa"),
         computed(gp, "#page-doc .pal-brand-1") if gp.locator("#page-doc .pal-brand-1").count() else "없음")
    gctx.close()

    # ══ C:T-206 JS 쪽 — doc-render.js 의 텍스트 노드 열·클래스 집합을 PHP 쪽과 ═══════════════
    print("── C:T-206 렌더러 동등 — doc-render.js (표본 %s)" % WORK)
    try:
        samples = json.load(open(os.path.join(WORK, "samples206.json"), encoding="utf-8"))
        php = json.load(open(os.path.join(WORK, "php206.json"), encoding="utf-8"))
        js = ap.evaluate(DOCRENDER_JS, samples)
        diff = 0
        for i, (a, b) in enumerate(zip(php, js)):
            if a["texts"] != b["texts"] or sorted(a["classes"]) != sorted(b["classes"]):
                diff += 1
                print("    다름 #%d  PHP %s %s · JS %s %s" % (i, a["texts"], sorted(a["classes"]), b["texts"], sorted(b["classes"])))
        diff += abs(len(php) - len(js))
        step("두 렌더러의 텍스트 노드·클래스 집합이 같다", diff == 0, "다른 표본 %d개" % diff)
        step("  JS 렌더러 인라인 style 0", not any(x["style"] for x in js))
    except Exception as e:
        diff = 99
        step("렌더러 동등 — 표본을 읽거나 그리지 못했다", False, str(e)[:160])
    actx.close()
    ectx.close()

    # ══ ⑤ 폭 × 테마 ═════════════════════════════════════════════════════════════
    print("── ⑤ 360 · 412(터치·마우스) · 820 · 1280 × 라이트·다크 — 넘침 · 창 바닥 · 누름 자리")
    combos = [(360, True), (412, True), (412, False), (820, False), (1280, False)]
    for (w, touch) in combos:
        for theme in ("light", "dark"):
            tag = "%d%s-%s" % (w, "-touch" if touch else "", theme)
            c1, pa = context(browser, A1, width=w, height=820, touch=touch, theme=theme, scheme=theme)
            pa.goto(COMMUNITY + "/", wait_until="networkidle")
            pa.wait_for_function("() => { const b = document.getElementById('community-palettes'); return b && !b.hidden; }", timeout=10000)
            open_home_more(pa)
            pa.click("#community-palettes")
            pa.wait_for_selector("#palette-dialog[open]", timeout=5000)
            pa.wait_for_function("() => /\\//.test(document.getElementById('palette-count').textContent)", timeout=10000)
            pa.wait_for_timeout(300)
            ov = overflow_x(pa)
            dov = pa.evaluate("() => { const d = document.getElementById('palette-dialog'); return Math.max(d.scrollWidth - d.clientWidth, ...[...d.querySelectorAll('.modal__body, .list, .list__item')].map(e => e.scrollWidth - e.clientWidth)); }")
            mb = pa.evaluate(MODAL_RULE, "palette-dialog")
            msg = ["넘침 %d" % ov, "창 안 넘침 %d" % dov, "바닥 %s" % (mb or "ok")]
            ok = ov <= 0 and dov <= 1 and mb == []
            if w <= 412:
                t = pa.evaluate(TOUCH, "#palette-dialog")
                msg.append("누름 자리 %d개 중 모자람 %s" % (t["n"], t["bad"][:4]))
                ok = ok and t["n"] > 0 and t["bad"] == []
            step("관리 창 %s" % tag, ok, " · ".join(msg))
            shot(pa, "d2-admin-%s.png" % tag)
            c1.close()
            c2, pe = context(browser, E1, width=w, height=820, touch=touch, theme=theme, scheme=theme)
            pe.goto(COMMUNITY + "/p/" + PE, wait_until="networkidle")
            pe.wait_for_timeout(300)
            ov2 = overflow_x(pe)
            step("  글 화면 %s — 가로 넘침 0" % tag, ov2 <= 0, "넘침 %d" % ov2)
            shot(pe, "d2-page-%s.png" % tag)
            c2.close()

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
