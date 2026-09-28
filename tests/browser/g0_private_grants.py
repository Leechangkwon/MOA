#!/usr/bin/env python3
# tests/browser/g0_private_grants.py — G-0 「개인」 등급 · 사람 지정 권한 · 권한 요청 화면을 진짜 브라우저로 눌러 본다 (T-G0-12)
#
# 부르는 쪽: tests/g0_private_grants.sh (임시 인스턴스 · 임시 DB — 운영 8082 에는 요청하지 않는다).
# 쓰는 법: printf '%s\n%s\n%s\n' "$세션_직원1" "$세션_직원2" "$세션_손님" | python3 g0_private_grants.py <SITE> <UA> <SHOTS 폴더> <IDS_JSON>
#   IDS_JSON = g0_private_grants.sh 가 만든 {A, B, P, R, secret, folderA, tag} — 직원1 의 개인 폴더 A ⊃ B, 개인 파일 P(B 안) · R(맨 위).
#   그 시점 권한: 직원2 = A 보기 · 손님 = P 보기(파일에만) · 손님은 A·B·R 을 못 본다.
#   세션 셋은 **표준입력 한 줄씩**(R-62 — 명령 인자·환경 변수에 싣지 않는다).
# 보는 것
#   ① 직원1(주인) — 폴더 A 를 주소(?folder=)로 열면 「권한」 단추 · 트리의 B 배지 「개인」+자물쇠 · 파일 줄 배지 「개인 · n명」 ·
#      「권한」 창: 받은 사람(직원2 보기) · 「사람 추가」 한 글자부터 후보 → 손님 추가 → 줄 생김 → 빼기 → 줄 사라짐 · Esc 로 닫힘
#   ② 손님 — 주소로 연 폴더 B(못 봄)·파일 R(못 봄) = 요청 화면(폴더·파일 이름 0 · 편집 고르기 없음) → 한 줄 메모 → 보내기 → 「보냈습니다」
#   ③ 직원1 — `?requests=in` 받은 권한 요청(손님 · 기다리는 중) → 허락(보기) → 허락됨 · 사이드바 「권한 요청」 이 사라짐
#   ④ 직원2 — 사이드바 「공유받음」(?view=shared) → 공유받은 폴더 A → 누르면 그 폴더(경로 A)
#   ⑤ 360 · 412(터치) · 820(터치) · 1280 × 라이트 · 다크 — 세 화면(주인 폴더+권한 창 · 손님 요청 화면 · 직원2 공유받음) 스크린샷 ·
#      가로 넘침 0 · 새 단추 누름 자리 44(터치 · U2 방식 — 보이는 상자 ∪ ::before) · innerHTML 0(정적은 셸 시험)
#   전 과정 pageerror 0 · console.error 0(권한 없는 폴더를 여는 403 「Failed to load resource」는 뜻한 것이라 뺀다)
# 나가는 값: 마지막 줄 `SMOKE_FAIL=<n>` · 종료 코드 0/1
import json
import os
import re
import sys
import time

from playwright.sync_api import sync_playwright

SITE = sys.argv[1].rstrip("/")
UA = sys.argv[2]
SHOTS = sys.argv[3]
IDS = json.load(open(sys.argv[4], encoding="utf-8"))
E1 = sys.stdin.readline().rstrip("\n")
E2 = sys.stdin.readline().rstrip("\n")
G1 = sys.stdin.readline().rstrip("\n")
if not E1 or not E2 or not G1:
    print("세션 셋(직원1·직원2·손님)을 표준입력으로 한 줄씩 주어야 한다 — 시작하지 않는다")
    sys.exit(2)
TBOX = SITE + "/tbox"
fails = 0
pageerrors, console_errors = [], []
os.makedirs(SHOTS, exist_ok=True)


def step(name, ok, detail=""):
    global fails
    if not ok:
        fails += 1
    print("  %s %s%s" % ("✔" if ok else "✖", name, ("  — " + str(detail)[:260]) if (detail != "" and not ok) else ""))


def host():
    return re.match(r"https?://([^/:]+)", SITE).group(1)


def context(browser, session, scheme="light", width=1280, height=900, touch=False):
    ctx = browser.new_context(user_agent=UA, viewport={"width": width, "height": height}, color_scheme=scheme,
                              has_touch=touch, is_mobile=touch)
    ctx.add_cookies([{"name": "__Host-tbox_session", "value": session, "domain": host(), "path": "/",
                      "httpOnly": True, "secure": True, "sameSite": "Lax"}])
    page = ctx.new_page()
    page.on("pageerror", lambda e: pageerrors.append(str(e)))
    page.on("console", lambda m: console_errors.append(m.text)
            if m.type == "error" and "Failed to load resource" not in m.text else None)
    return ctx, page


def wait_until(fn, timeout=8.0):
    end = time.time() + timeout
    while time.time() < end:
        try:
            if fn():
                return True
        except Exception:
            pass
        time.sleep(0.15)
    return False


def visible(page, sel):
    return page.evaluate("(s) => { const e = document.querySelector(s); if (!e) return false; "
                         "const r = e.getBoundingClientRect(); return !e.closest('[hidden]') && r.width > 0 && r.height > 0; }", sel)


def text(page, sel):
    return page.evaluate("(s) => { const e = document.querySelector(s); return e ? e.textContent : ''; }", sel)


def no_overflow(page, what):
    w = page.evaluate("() => [document.scrollingElement.scrollWidth, window.innerWidth]")
    step("가로 넘침 0 — %s (%d ≤ %d)" % (what, w[0], w[1]), w[0] <= w[1] + 1, w)


# 누름 자리 44 — dbg_u2_ui.py 의 TOUCH 와 같은 셈(보이는 상자 ∪ ::before), 고른 요소만
TOUCH = r"""(sels) => {
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
  for (const sel of sels) {
    for (const e of document.querySelectorAll(sel)) {
      if (e.closest('[hidden]')) continue;
      const dlg = e.closest('dialog'); if (dlg && !dlg.open) continue;
      const r = e.getBoundingClientRect();
      if (r.width < 2 || r.height < 2) continue;
      n++;
      let [w, h] = box(e);
      const lab = e.closest('label');
      if (lab && e.matches('input')) { const lr = lab.getBoundingClientRect(); h = Math.max(h, lr.height); w = Math.max(w, lr.width); }
      if (w < 43.5 || h < 43.5) out.push([(e.getAttribute('aria-label') || e.textContent || e.tagName).trim().replace(/\s+/g, ' ').slice(0, 16), Math.round(w), Math.round(h)]);
    }
  }
  return {n: n, bad: out};
}"""


def touch(page, what, sels):
    r = page.evaluate(TOUCH, sels)
    step("누름 자리 44 — %s (%d개)" % (what, r["n"]), r["n"] > 0 and not r["bad"], r["bad"][:6])


A, B, P, R = IDS["A"], IDS["B"], IDS["P"], IDS["R"]
SECRET, FOLDER_A = IDS["secret"], IDS["folderA"]

with sync_playwright() as pw:
    browser = pw.chromium.launch()

    # ── ① 주인 — 폴더 A · 권한 창 ──────────────────────────────
    print("① 직원1(주인) — 폴더 A · 권한 창")
    ctx, pg = context(browser, E1)
    pg.goto(TBOX + "/?folder=%d" % A)
    step("주소 ?folder= 로 폴더 A 가 열린다(경로 줄에 A)", wait_until(lambda: FOLDER_A in text(pg, "#folder-crumbs")))
    step("「권한」 단추 보임(개인 폴더)", wait_until(lambda: visible(pg, "#folder-access")))
    step("주소 꼬리표는 읽은 뒤 지운다", "folder=" not in pg.url, pg.url)
    step("트리의 하위 폴더 B 배지 「개인」 + 자물쇠", wait_until(lambda: pg.evaluate(
        "() => [...document.querySelectorAll('#folder-list .badge--private')].some(b => b.textContent.includes('개인') && b.querySelector('.icon svg'))")))
    pg.click("#folder-access")
    step("권한 창 열림 · 받은 사람에 직원2(보기)", wait_until(lambda: "claude_employee02" in text(pg, "#grants-list") or "검토지공이" in text(pg, "#grants-list")))
    step("주인에게 「사람 추가」", visible(pg, "#grants-add-wrap"))
    step("검색어 없으면 안내만(후보 목록 숨김)", visible(pg, "#grants-hint") and not visible(pg, "#grants-candidates"))
    pg.fill("#grants-search", "claude_g")
    step("한 글자부터 후보 — 손님 계정", wait_until(lambda: "claude_guest01" in text(pg, "#grants-candidates")))
    pg.select_option("#grants-level", "view")
    pg.click("#grants-candidates button:has-text('claude_guest01')")
    step("추가 → 줄 생김(손님 · 보기)", wait_until(lambda: "claude_guest01" in text(pg, "#grants-list")))
    rows = pg.evaluate("() => [...document.querySelectorAll('#grants-list .list__item')].map(li => li.textContent)")
    guest_row = [i for i, t in enumerate(rows) if "claude_guest01" in t]
    if guest_row:
        pg.locator("#grants-list .list__item").nth(guest_row[0]).locator("button:has-text('빼기')").click()
    step("빼기 → 줄 사라짐", wait_until(lambda: "claude_guest01" not in text(pg, "#grants-list")))
    hs = pg.evaluate("() => [document.querySelector('#grants-level'), document.querySelector('#grants-list .toolbar select')].map(e => e ? Math.round(e.getBoundingClientRect().height) : 0)")
    step("줄의 보기/편집 고르개가 「사람 추가」 고르개와 같은 높이(도구줄 모양)", hs[0] > 0 and hs[0] == hs[1], hs)
    pg.screenshot(path=os.path.join(SHOTS, "g0-owner-grants-1280-light.png"))
    pg.keyboard.press("Escape")
    step("Esc 로 권한 창 닫힘", wait_until(lambda: not pg.evaluate("() => document.getElementById('grants-dialog').open")))
    pg.goto(TBOX + "/?folder=%d" % B)
    step("B 를 열면 개인 파일 P 줄 배지 「개인」+ 자물쇠 · 줄에 「권한」 단추", wait_until(lambda: pg.evaluate(
        "() => [...document.querySelectorAll('#files-body tr')].some(tr => tr.querySelector('.badge--private .icon svg') && tr.querySelector('button[aria-label^=\"권한\"]'))")))
    ctx.close()

    # ── ② 손님 — 요청 화면(폴더 · 파일) ─────────────────────────
    print("② 손님 — 못 보는 폴더·파일을 주소로 열면 요청 화면")
    ctx, pg = context(browser, G1, width=412, height=915, touch=True)
    pg.goto(TBOX + "/?folder=%d" % B)
    step("요청 화면 보임(폴더 B)", wait_until(lambda: visible(pg, "#access-request")))
    body = pg.evaluate("() => document.body.innerText")
    step("화면 글자에 폴더 이름·개인 파일 이름 0", FOLDER_A not in body and SECRET not in body)
    step("손님에게는 「편집」 고르기 없음", not visible(pg, "#access-want-write-label"))
    # 누름 자리는 보내기 **전에** 잰다(보낸 뒤에는 고르기·단추가 숨고 「보냈습니다」 한 줄만 남는다)
    touch(pg, "요청 화면(412 터치)", ["#access-request button", "#access-request input[type=radio]"])
    step("요청 화면이 떠 있는 동안 아래 파일 목록 틀(검색 줄·빈 표) 숨김", not visible(pg, "#search-form") and not visible(pg, "#files-body"))
    pg.fill("#access-request-message", "현장 자료 확인이 필요합니다")
    pg.click("#access-request-send")
    step("보내기 → 「보냈습니다」", wait_until(lambda: "보냈습니다" in text(pg, "#access-request-done") and visible(pg, "#access-request-done")))
    pg.goto(TBOX + "/?file=%s" % R)
    step("주소 ?file= (못 보는 파일 R) → 요청 화면 · 미리보기 창 안 열림", wait_until(lambda: visible(pg, "#access-request"))
         and not pg.evaluate("() => document.getElementById('preview-dialog').open"))
    step("  화면 글자에 R 이름 0", SECRET not in pg.evaluate("() => document.body.innerText"))
    pg.goto(TBOX + "/?file=%s" % P)
    step("주소 ?file= (권한 받은 파일 P) → 미리보기 창 · 요청 화면 없음", wait_until(lambda: pg.evaluate("() => document.getElementById('preview-dialog').open"))
         and not visible(pg, "#access-request"))
    ctx.close()

    # ── ③ 주인 — 받은 권한 요청 → 허락 ──────────────────────────
    print("③ 직원1 — 받은 권한 요청 → 허락(보기)")
    ctx, pg = context(browser, E1, width=412, height=915, touch=True)
    pg.goto(TBOX + "/?requests=in")
    step("받은 권한 요청 묶음 보임 · 손님의 기다리는 요청", wait_until(lambda: "claude_guest01" in text(pg, "#access-in-list") and "기다리는 중" in text(pg, "#access-in-list")))
    step("사이드바 「권한 요청」 항목이 열려 있다(숫자 1 이상)", pg.evaluate(
        "() => { const a = document.getElementById('nav-access-requests'); return !!a && !a.hidden && parseInt(document.getElementById('nav-access-requests-count').textContent, 10) >= 1; }"))
    touch(pg, "받은 요청 단추(412 터치)", ["#access-in-list button"])
    pg.screenshot(path=os.path.join(SHOTS, "g0-owner-requests-412-light.png"), full_page=True)
    first = pg.locator("#access-in-list .list__item", has_text="claude_guest01").first
    first.locator("button:has-text('허락(보기)')").click()
    step("허락 → 줄 상태 「허락됨」", wait_until(lambda: "허락됨" in pg.evaluate(
        "() => [...document.querySelectorAll('#access-in-list .list__item')].filter(li => li.textContent.includes('claude_guest01')).map(li => li.textContent).join(' ')")))
    step("기다리는 요청이 없으면 사이드바 「권한 요청」 숨음", wait_until(lambda: pg.evaluate("() => document.getElementById('nav-access-requests').hidden")))
    ctx.close()

    # ── ④ 직원2 — 공유받음 ─────────────────────────────────────
    print("④ 직원2 — 사이드바 「공유받음」 → 공유받은 폴더 A")
    ctx, pg = context(browser, E2, scheme="dark")
    pg.goto(TBOX + "/?view=shared")
    step("공유받은 폴더 묶음에 A", wait_until(lambda: FOLDER_A in text(pg, "#shared-folders")))
    pg.click("#shared-folders button:has-text('%s')" % FOLDER_A)
    step("누르면 그 폴더(경로 줄 A)", wait_until(lambda: FOLDER_A in text(pg, "#folder-crumbs")))
    ctx.close()

    # ── ⑤ 폭 넷 × 라이트·다크 — 세 화면 ─────────────────────────
    print("⑤ 360 · 412(터치) · 820(터치) · 1280 × 라이트 · 다크")
    for (w, h, t) in [(360, 800, False), (412, 915, True), (820, 1180, True), (1280, 900, False)]:
        for scheme in ("light", "dark"):
            tag = "%d-%s%s" % (w, scheme, "-touch" if t else "")
            ctx, pg = context(browser, E1, scheme=scheme, width=w, height=h, touch=t)
            pg.goto(TBOX + "/?folder=%d" % A)
            wait_until(lambda: visible(pg, "#folder-access"))
            no_overflow(pg, "주인 폴더 A %s" % tag)
            if t or w < 720:
                touch(pg, "폴더 「권한」 단추 %s" % tag, ["#folder-access", "#files-body button[aria-label^='권한']"])
            pg.screenshot(path=os.path.join(SHOTS, "g0-owner-folder-%s.png" % tag), full_page=True)
            pg.click("#folder-access")
            wait_until(lambda: "claude_employee02" in text(pg, "#grants-list") or "검토지공이" in text(pg, "#grants-list"))
            no_overflow(pg, "권한 창 %s" % tag)
            if t or w < 720:
                # DESIGN 4장 「터치 대상」 — 입력 칸·고르개(폭이 넓다)는 규칙 밖(셸 규칙과 같다). 단추만 잰다
                touch(pg, "권한 창 %s" % tag, ["#grants-dialog button"])
            pg.screenshot(path=os.path.join(SHOTS, "g0-grants-%s.png" % tag))
            ctx.close()
            ctx, pg = context(browser, G1, scheme=scheme, width=w, height=h, touch=t)
            pg.goto(TBOX + "/?folder=%d" % A)
            wait_until(lambda: visible(pg, "#access-request"))
            no_overflow(pg, "손님 요청 화면 %s" % tag)
            pg.screenshot(path=os.path.join(SHOTS, "g0-denied-%s.png" % tag), full_page=True)
            ctx.close()
            ctx, pg = context(browser, E2, scheme=scheme, width=w, height=h, touch=t)
            pg.goto(TBOX + "/?view=shared")
            wait_until(lambda: FOLDER_A in text(pg, "#shared-folders"))
            no_overflow(pg, "직원2 공유받음 %s" % tag)
            pg.screenshot(path=os.path.join(SHOTS, "g0-shared-%s.png" % tag), full_page=True)
            ctx.close()

    browser.close()

step("pageerror 0", not pageerrors, pageerrors[:4])
step("console.error 0(403 자원 줄 제외)", not console_errors, console_errors[:4])
print("SMOKE_FAIL=%d" % fails)
sys.exit(0 if fails == 0 else 1)
