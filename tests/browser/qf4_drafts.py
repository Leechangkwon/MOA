#!/usr/bin/env python3
# tests/browser/qf4_drafts.py — Q:F-4 B2 「쓰던 글 임시저장 · 불러오기」(B1-2) + U-3 화면 쪽 헤드리스 수락 시험
#
# 부르는 쪽: tests/community/qf4_draft_key.sh (임시 인스턴스 · 임시 DB · 포트 8102 · SESSION_IDLE_MIN=2). 운영 8082 에는 요청하지 않는다.
# 쓰는 법:   py_secret E1_PW E1_SEC E2_PW E2_SEC -- python3 qf4_drafts.py <SITE> <UA> <SHOTS> <E1 마지막 TOTP 칸> <E2 마지막 TOTP 칸>
#   비밀(claude_employee01·02 의 비밀번호·OTP 비밀)은 **표준입력 네 줄**로만 받는다(명령 인자·환경 변수에 싣지 않는다 — R-62·R15).
#   로그인은 이 스크립트가 창구(POST /tbox/api/login)로 하고 쿠키를 브라우저 문맥에 넣는다. 같은 계정을 다시 넣을 때는
#   TOTP 한 칸(30초)을 넘긴다(같은 칸 코드는 code_reused — 실패 횟수가 오른다).
#
# 지시서 B1-2 완료 기준 (claude_employee01·02, U-3 이 켜진 상태)
#   ① 편집 중 3분 방치 → 한 줄 더 → 401 → 로그인 → 그 페이지 → 알림 줄 → 「불러오기」 → 마지막 줄까지 저장(doc_version +1, 본문 대조)
#   ② 같은 흐름의 댓글(새 댓글)·답글 → 쓰기 칸에 되돌아옴, 자동 전송 0
#   ③ 그사이 employee02 가 같은 페이지를 고쳤으면 → 409 충돌 창
#   ④ 같은 브라우저에서 employee02 로 로그인 → employee01 의 알림 줄·「n개」 0 · employee01 항목을 employee02 열쇠로 풀기 실패
#   ⑤ 저장소 값에 본문·제목 평문 0 · 다른 페이지 열쇠로 옮겨 붙이면 풀기 실패(AAD) — 옮겨 붙인 항목은 화면이 조용히 지운다
#   ⑥ 200 저장 뒤 그 열쇠 0 · 「버리기」 → 열쇠 0·서버 요청 0 · saved_at 8일 전 → 화면을 열면 지워짐 · 로그아웃 → 그 사용자 열쇠 0
#      (다른 사용자 항목은 그대로)
#   ⑦ 저장소 쓰기 예외(setItem 을 막은 브라우저)·draft-key 503 에서도 편집·저장 정상, 항목 0, 배지 「임시저장 못 함」
#   ⑨ 창 닫기(탭 숨김) 저장 요청에 keepalive · 저장 안 된 변경이 있을 때 닫기 경고(없으면 경고 없음)
#   ⑩ 알림 줄 · 첫 화면 「n개」 — 라이트·다크 1440 · 390px 스크린샷(가로 넘침 0)
#   + U-3 화면: 방치하는 3분 동안 브라우저가 보낸 요청은 **전부** X-Qaredi-Background: 1 을 달았다(사람 요청 0)
# 공통: pageerror 0 · console.error 0(예상한 401·409·503 의 「Failed to load resource」 줄은 뺀다) · 예상 밖 HTTP ≥ 400 0
# 나가는 값: 0 통과 · 1 실패 (마지막 줄 `SMOKE_FAIL=<개수>`). 열쇠·비밀·본문은 찍지 않는다(참/거짓·개수만).
import base64
import hashlib
import hmac
import json
import os
import re
import secrets
import struct
import sys
import time
import urllib.error
import urllib.request
from urllib.parse import urlsplit

from playwright.sync_api import sync_playwright

SITE = sys.argv[1].rstrip("/")
UA = sys.argv[2]
SHOTS = sys.argv[3]
HOST = re.match(r"https?://([^/:]+)", SITE).group(1)
TBOX = SITE + "/tbox"
COMM = SITE + "/community"
E1, E2 = "claude_employee01", "claude_employee02"
LAST_STEP = {E1: int(sys.argv[4] or 0), E2: int(sys.argv[5] or 0)}
CRED = {}
for who in (E1, E2):
    CRED[who] = (sys.stdin.readline().rstrip("\n"), sys.stdin.readline().rstrip("\n"))
IDLE_WAIT = 185            # 무활동 창 2분 + 여유 — 지시서 「3분 방치」
PFX = {E1: "qaredi-draft:v1:%s:" % E1, E2: "qaredi-draft:v1:%s:" % E2}
MK = "qf4b2x" + secrets.token_hex(4)      # 이 실행에만 있는 표식 — 저장소 평문 검사에 쓴다(찍지 않는다)
L1, L2 = MK + " 첫 줄", MK + " 마지막 줄"
M1, M2 = MK + " 둘째 페이지", MK + " 둘째 페이지 더"
R1 = MK + " 답글 쓰던 것"
C1 = MK + " 새 댓글 쓰던 것"
EXPECTED_BAD = re.compile(r"Failed to load resource: the server responded with a status of (401|409|503)")

fails = 0


def step(name, ok, detail=""):
    global fails
    if not ok:
        fails += 1
    print("  %s %s%s" % ("✔" if ok else "✖", name, ("  — " + detail) if (detail and not ok) else ""), flush=True)


def note(text):
    print("  · " + text, flush=True)


# ─── 창구 (파이썬 쪽 — 준비·대조) ─────────────────────────────────────
def totp(secret, t):
    key = base64.b32decode(secret.upper() + "=" * (-len(secret) % 8))
    mac = hmac.new(key, struct.pack(">Q", int(t) // 30), hashlib.sha1).digest()
    o = mac[-1] & 15
    return "%06d" % ((struct.unpack(">I", mac[o:o + 4])[0] & 0x7FFFFFFF) % 1000000)


def login(who):
    pw, sec = CRED[who]
    while int(time.time()) // 30 <= LAST_STEP.get(who, 0):
        time.sleep(1)
    for _ in range(3):
        st = int(time.time()) // 30
        body = {"username": who, "password": pw, "otp": totp(sec, time.time())}
        req = urllib.request.Request(TBOX + "/api/login", data=json.dumps(body).encode(), method="POST",
                                     headers={"Content-Type": "application/json", "User-Agent": UA})
        cookie, data = "", {}
        try:
            resp = urllib.request.urlopen(req, timeout=15)
            data = json.loads(resp.read().decode())
            for h in resp.headers.get_all("Set-Cookie") or []:
                m = re.match(r"tbox_session=([^;]+)", h)
                if m:
                    cookie = m.group(1)
        except Exception:
            pass
        if data.get("ok") is True and cookie:
            LAST_STEP[who] = st
            code, me = api(cookie, "GET", "/tbox/api/me")
            return cookie, (me.get("csrf_token") if isinstance(me, dict) else "")
        time.sleep(31 - int(time.time()) % 30)
    print("로그인 실패: " + who, flush=True)
    print("SMOKE_FAIL=99")
    sys.exit(1)


def api(sess, method, path, body=None, csrf=None):
    headers = {"User-Agent": UA, "Cookie": "tbox_session=" + sess}
    data = None
    if method != "GET":
        headers["Content-Type"] = "application/json"
        headers["X-CSRF-Token"] = csrf or ""
        data = json.dumps(body if body is not None else {}, ensure_ascii=False).encode()
    req = urllib.request.Request(SITE + path, data=data, method=method, headers=headers)
    try:
        resp = urllib.request.urlopen(req, timeout=20)
        code, raw = resp.status, resp.read()
    except urllib.error.HTTPError as e:
        code, raw = e.code, e.read()
    try:
        j = json.loads(raw.decode() or "{}")
    except Exception:
        j = {}
    return code, j


# ─── 브라우저 도우미 ──────────────────────────────────────────────────
seen = {"pageerrors": [], "console": [], "http_bad": [], "requests": [], "dialogs": []}


def watch(page):
    page.on("pageerror", lambda e: seen["pageerrors"].append(str(e)[:160]))
    page.on("console", lambda m: seen["console"].append(m.text[:160])
            if m.type == "error" and not EXPECTED_BAD.search(m.text) else None)

    def on_req(r):
        if HOST in r.url:
            seen["requests"].append({"t": time.time(), "m": r.method, "p": urlsplit(r.url).path,
                                     "q": urlsplit(r.url).query, "bg": r.headers.get("x-qaredi-background"),
                                     "body": r.post_data or ""})
    page.on("request", on_req)
    page.on("response", lambda r: seen["http_bad"].append("%d %s %s" % (r.status, r.request.method, urlsplit(r.url).path))
            if r.status >= 400 and HOST in r.url else None)

    def on_dialog(d):
        seen["dialogs"].append((time.time(), d.type))
        d.accept()
    page.on("dialog", on_dialog)


def set_session(ctx, sess, theme="light"):
    ctx.add_cookies([
        {"name": "tbox_session", "value": sess, "domain": HOST, "path": "/", "httpOnly": True, "secure": False, "sameSite": "Lax"},
        {"name": "tbox_theme", "value": theme, "domain": HOST, "path": "/", "secure": False, "sameSite": "Lax"},
    ])


def set_theme(ctx, theme):
    ctx.add_cookies([{"name": "tbox_theme", "value": theme, "domain": HOST, "path": "/", "secure": False, "sameSite": "Lax"}])


def goto(page, url):
    page.goto(url, wait_until="domcontentloaded")
    page.wait_for_load_state("networkidle")
    page.wait_for_timeout(700)


def store(page, prefix):
    return page.evaluate("""(p) => { const o = {};
        for (let i = 0; i < localStorage.length; i++) { const k = localStorage.key(i); if (k.indexOf(p) === 0) { o[k] = localStorage.getItem(k); } }
        return o; }""", prefix)


def start_edit(page):
    page.locator("#page-edit").click()
    page.wait_for_selector("#page-editor .ProseMirror", state="visible", timeout=8000)
    page.wait_for_timeout(400)


def type_in_editor(page, text):
    ed = page.locator("#page-editor .ProseMirror")
    ed.click()
    page.keyboard.press("Control+End")
    page.keyboard.type(text, delay=5)


def wait_saved(page, timeout=15000):
    page.wait_for_timeout(400)
    page.wait_for_function("""() => { const b = document.getElementById('page-save-state');
        return !!b && !b.hidden && b.textContent.trim() === '저장됨'; }""", timeout=timeout)


def overflow_ok(page):
    return page.evaluate("() => document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1")


def shot(page, name, full=False):
    page.screenshot(path=os.path.join(SHOTS, name), full_page=full)


def shots4(ctx, page, url, base, sel):
    """라이트 1440(지금) · 다크 1440 · 다크 390 · 라이트 390 — 같은 문맥이라 저장소(임시저장)가 그대로다."""
    shot(page, base + "-light-1440.png")
    set_theme(ctx, "dark")
    goto(page, url)
    step("⑩ %s 다크 1440 — 줄이 보인다" % base, page.locator(sel).count() == 1 and page.locator(sel).is_visible())
    shot(page, base + "-dark-1440.png")
    page.set_viewport_size({"width": 390, "height": 844})
    goto(page, url)
    step("⑩ %s 다크 390px — 줄이 보인다 · 가로 넘침 0" % base,
         page.locator(sel).count() == 1 and page.locator(sel).is_visible() and overflow_ok(page))
    shot(page, base + "-dark-390.png", full=True)
    set_theme(ctx, "light")
    goto(page, url)
    step("⑩ %s 라이트 390px — 줄이 보인다 · 가로 넘침 0" % base,
         page.locator(sel).count() == 1 and page.locator(sel).is_visible() and overflow_ok(page))
    shot(page, base + "-light-390.png", full=True)
    page.set_viewport_size({"width": 1440, "height": 900})
    goto(page, url)


DECRYPT_JS = """async ([name, as]) => {
  const r = await fetch('/community/api/me/draft-key', { credentials: 'same-origin', cache: 'no-store' });
  if (r.status !== 200) { return 'nokey'; }
  const j = await r.json();
  const s = j.key.replace(/-/g, '+').replace(/_/g, '/');
  const raw = Uint8Array.from(atob(s + '='.repeat((4 - s.length % 4) % 4)), (c) => c.charCodeAt(0));
  const key = await crypto.subtle.importKey('raw', raw, { name: 'AES-GCM' }, false, ['decrypt']);
  const rec = JSON.parse(localStorage.getItem(name));
  if (!rec) { return 'noitem'; }
  const iv = Uint8Array.from(atob(rec.iv), (c) => c.charCodeAt(0));
  const ct = Uint8Array.from(atob(rec.ct), (c) => c.charCodeAt(0));
  try {
    const plain = await crypto.subtle.decrypt({ name: 'AES-GCM', iv: iv, additionalData: new TextEncoder().encode(as || name) }, key, ct);
    return 'ok:' + new TextDecoder().decode(plain).length;
  } catch (e) { return 'fail'; }
}"""
CONTAINS_JS = """async ([name, needle]) => {
  const r = await fetch('/community/api/me/draft-key', { credentials: 'same-origin', cache: 'no-store' });
  const j = await r.json();
  const s = j.key.replace(/-/g, '+').replace(/_/g, '/');
  const raw = Uint8Array.from(atob(s + '='.repeat((4 - s.length % 4) % 4)), (c) => c.charCodeAt(0));
  const key = await crypto.subtle.importKey('raw', raw, { name: 'AES-GCM' }, false, ['decrypt']);
  const rec = JSON.parse(localStorage.getItem(name));
  const iv = Uint8Array.from(atob(rec.iv), (c) => c.charCodeAt(0));
  const ct = Uint8Array.from(atob(rec.ct), (c) => c.charCodeAt(0));
  const plain = new TextDecoder().decode(await crypto.subtle.decrypt({ name: 'AES-GCM', iv: iv, additionalData: new TextEncoder().encode(name) }, key, ct));
  return plain.indexOf(needle) >= 0;
}"""


def plain_free(values):
    """저장소 값에 이 실행의 표식(본문·제목·댓글에 다 들어 있다)이 한 번도 없고, 값 모양이 {v,iv,ct,saved_at} 뿐인가"""
    for v in values:
        if MK in v:
            return False
        try:
            rec = json.loads(v)
        except Exception:
            return False
        if sorted(rec.keys()) != ["ct", "iv", "saved_at", "v"] or rec["v"] != 1:
            return False
    return True


def doc_text(page_json):
    return json.dumps(page_json.get("page", {}).get("doc", {}), ensure_ascii=False)


# ════════════════════════════════════════════════════════════════
with sync_playwright() as p:
    browser = p.chromium.launch()

    print("── 준비 — employee01 로그인 · 페이지 셋 · 둘째 페이지는 employee02 에게 편집 공유 · 첫 페이지에 댓글 하나", flush=True)
    s1, c1 = login(E1)
    api(s1, "GET", "/community/api/me/space")
    pages = {}
    for tag in ("P1", "P2", "P3"):
        code, j = api(s1, "POST", "/community/api/pages", {"title": "qf4b2 %s %s" % (tag, MK)}, c1)
        pages[tag] = j.get("page", {}).get("id", "")
        step("준비 %s 만들기 201" % tag, code == 201 and pages[tag] != "", str(code))
    P1, P2, P3 = pages["P1"], pages["P2"], pages["P3"]
    code, _ = api(s1, "PUT", "/community/api/pages/%s/shares/%s" % (P2, E2), {"level": "edit"}, c1)
    step("준비 P2 를 employee02 에게 편집 공유 200", code == 200, str(code))
    code, j = api(s1, "POST", "/community/api/pages/%s/comments" % P1, {"body": "qf4b2 답글 받을 댓글"}, c1)
    X = j.get("comment", {}).get("id", "")
    step("준비 P1 에 댓글 하나 201", code == 201 and X != "", str(code))

    ctx = browser.new_context(user_agent=UA, viewport={"width": 1440, "height": 900})
    set_session(ctx, s1)

    print("── 방치 전 — 세 탭: P1 편집+답글 쓰기 · P2 편집 · P3 새 댓글 쓰기", flush=True)
    ta = ctx.new_page(); watch(ta)
    goto(ta, COMM + "/p/" + P1)
    start_edit(ta)
    type_in_editor(ta, L1)
    wait_saved(ta)
    step("① 준비 — P1 첫 줄 저장됨 → 그 판의 임시저장 열쇠 0(200 저장 뒤 지움)",
         (PFX[E1] + "page:" + P1) not in store(ta, PFX[E1]))
    ta.locator("#comment-%s button" % X, has_text="답글").click()
    ta.locator("#comment-input").fill("")
    ta.locator("#comment-input").type(R1, delay=5)
    ta.wait_for_timeout(1800)
    step("② 준비 — 답글 쓰기 칸 → 1초 묶음 뒤 comment:P1:<대상> 열쇠가 생겼다",
         (PFX[E1] + "comment:%s:%s" % (P1, X)) in store(ta, PFX[E1]))

    tb = ctx.new_page(); watch(tb)
    goto(tb, COMM + "/p/" + P2)
    start_edit(tb)
    type_in_editor(tb, M1)
    wait_saved(tb)

    tc = ctx.new_page(); watch(tc)
    goto(tc, COMM + "/p/" + P3)
    tc.locator("#comment-input").type(C1, delay=5)
    tc.wait_for_timeout(1800)
    st = store(tc, PFX[E1])
    step("② 준비 — 새 댓글 쓰기 칸 → comment:P3:new 열쇠", (PFX[E1] + "comment:%s:new" % P3) in st)
    step("⑤ 방치 전 저장소 값 — 평문 표식 0 · 모양 {v,iv,ct,saved_at}", plain_free(st.values()))
    code, j = api(s1, "GET", "/community/api/pages/" + P1)
    V1 = j.get("page", {}).get("doc_version", -1)
    code, j = api(s1, "GET", "/community/api/pages/" + P2)
    V2 = j.get("page", {}).get("doc_version", -1)
    code, j = api(s1, "GET", "/community/api/pages/%s/comments" % P1)
    N_COMMENTS_P1 = len(j.get("comments", []))
    code, j = api(s1, "GET", "/community/api/pages/%s/comments" % P3)
    N_COMMENTS_P3 = len(j.get("comments", []))
    note("P1 판 %s · P2 판 %s — 이제 %d초 동안 employee01 은 아무것도 누르지 않는다(탭 셋이 폴링·heartbeat 만)" % (V1, V2, IDLE_WAIT))

    # ─── 방치 3분 ───────────────────────────────────────────────
    # ★ 기다림은 time.sleep 이 아니라 wait_for_timeout 으로 — 동기 Playwright 는 자기 호출 안에서만 이벤트(요청 기록)를 돌린다.
    #   time.sleep 이면 방치 중 요청이 모두 깨어난 뒤에 기록돼 시각이 틀린다(첫 실행에서 겪음).
    T_IDLE = time.time()
    ta.wait_for_timeout(60000)
    print("── 방치 중 — employee02 가 P2 를 고친다(③ 준비)", flush=True)
    s2, c2 = login(E2)
    code, j = api(s2, "GET", "/community/api/pages/" + P2)
    pg = j.get("page", {})
    code, _ = api(s2, "PATCH", "/community/api/pages/" + P2,
                  {"doc_version": pg.get("doc_version"), "title": pg.get("title", "") + " (employee02)", "doc": pg.get("doc")}, c2)
    step("③ 준비 — employee02 가 P2 저장 200", code == 200, str(code))
    rest = IDLE_WAIT - (time.time() - T_IDLE)
    if rest > 0:
        ta.wait_for_timeout(int(rest * 1000))
    T_WAKE = time.time()
    idle_reqs = [r for r in seen["requests"] if T_IDLE <= r["t"] < T_WAKE]
    human = [r for r in idle_reqs if r["bg"] != "1"]
    step("U-3 화면 — 방치하는 동안 브라우저 요청은 전부 자동 표식(X-Qaredi-Background: 1) · 사람 요청 0",
         len(idle_reqs) > 0 and len(human) == 0,
         "요청 %d · 표식 없음 %s" % (len(idle_reqs), [(r["m"], r["p"]) for r in human][:4]))
    note("방치 중 자동 요청 %d건 (폴링·heartbeat)" % len(idle_reqs))

    print("── ① ② 방치 뒤 — 한 줄 더·보내기 → 401 → 임시저장 → 로그인 화면", flush=True)
    d0 = len(seen["dialogs"])
    type_in_editor(ta, " " + L2)
    ta.wait_for_url(re.compile(r".*/tbox/login.*"), timeout=20000)
    step("① P1 한 줄 더 → 저장 401 → 로그인 화면으로 갔다", "/tbox/login" in ta.url, ta.url)
    type_in_editor(tb, " " + M2)
    tb.wait_for_url(re.compile(r".*/tbox/login.*"), timeout=20000)
    step("③ 준비 — P2 한 줄 더 → 401 → 로그인 화면", "/tbox/login" in tb.url, tb.url)
    tc.locator("#comment-submit").click()
    tc.wait_for_url(re.compile(r".*/tbox/login.*"), timeout=20000)
    step("② P3 「댓글 달기」 → 401 → 로그인 화면(쓴 댓글은 잃지 않는다)", "/tbox/login" in tc.url, tc.url)
    step("  401 로 떠날 때 닫기 경고 0(일부러 떠나는 중 — 사본이 이미 남았다)", len(seen["dialogs"]) == d0)
    wake = [r for r in seen["requests"] if r["t"] >= T_WAKE]
    got401 = [b for b in seen["http_bad"] if b.startswith("401 PATCH /community/api/pages/") or b.startswith("401 POST /community/api/pages/")]
    step("  세 요청이 401 을 받았다(PATCH P1·P2 · POST 댓글)", len(got401) >= 3, str(got401[:4]))
    st = store(ta, PFX[E1])
    keys_now = sorted(k[len(PFX[E1]):] for k in st)
    want_keys = sorted(["page:" + P1, "page:" + P2, "comment:%s:%s" % (P1, X), "comment:%s:new" % P3])
    step("①②③ 저장소 — employee01 항목 넷(P1 본문 · P2 본문 · P1 답글 · P3 새 댓글)", keys_now == want_keys, str(len(keys_now)))
    step("⑤ 저장소 값 — 본문·제목·댓글 평문 0(이 실행 표식 grep 0) · 모양 {v,iv,ct,saved_at}", plain_free(st.values()))
    step("⑤ 쿠키에 표식 0", all(MK not in c["value"] for c in ctx.cookies()))
    step("⑤ 요청 주소(경로·질의)에 표식 0", all(MK not in (r["p"] + r["q"]) for r in seen["requests"]))

    print("── ④ 같은 브라우저에서 employee02 — employee01 의 알림 줄·「n개」 0 · 풀기 실패", flush=True)
    s2, c2 = login(E2)
    set_session(ctx, s2)
    td = ctx.new_page(); watch(td)
    goto(td, COMM + "/")
    step("④ employee02 첫 화면 — 「저장 안 된 글」 줄 0", td.locator("[data-drafts-home]").count() == 0)
    goto(td, COMM + "/p/" + P2)
    step("④ employee02 가 P2(공유받은 페이지)를 열어도 알림 줄 0", td.locator("[data-draft-notice]").count() == 0)
    r = td.evaluate(DECRYPT_JS, [PFX[E1] + "page:" + P1, None])
    step("④ employee01 항목을 employee02 열쇠로 풀기 → 실패", r == "fail", r)
    r = td.evaluate(DECRYPT_JS, [PFX[E1] + "page:" + P1, PFX[E2] + "page:" + P1])
    step("④ employee02 접두의 AAD 로 바꿔 풀어도 → 실패", r == "fail", r)
    step("④ employee02 화면이 employee01 항목을 건드리지 않았다(넷 그대로)", len(store(td, PFX[E1])) == 4)

    print("── ⑤ employee01 다시 로그인 — AAD(다른 페이지 칸으로 옮겨 붙이기)", flush=True)
    s1, c1 = login(E1)
    set_session(ctx, s1)
    goto(td, TBOX + "/")
    r = td.evaluate(DECRYPT_JS, [PFX[E1] + "page:" + P1, None])
    step("⑤ 대조 — 제 열쇠·제 칸이면 풀린다", r.startswith("ok:"), r)
    step("① 사본에 마지막 줄까지 들어 있다", td.evaluate(CONTAINS_JS, [PFX[E1] + "page:" + P1, L2]) is True)
    moved = PFX[E1] + "page:" + P3
    td.evaluate("([a, b]) => localStorage.setItem(b, localStorage.getItem(a))", [PFX[E1] + "page:" + P1, moved])
    r = td.evaluate(DECRYPT_JS, [moved, None])
    step("⑤ P1 사본을 P3 칸으로 옮겨 붙이면 → 풀기 실패(AAD = 보관 열쇠)", r == "fail", r)

    print("── 첫 화면 「저장 안 된 글 n개」 (⑩ 스크린샷)", flush=True)
    goto(td, COMM + "/")
    home = td.locator("[data-drafts-home]")
    step("첫 화면 — 「저장 안 된 글 4개」 줄이 보인다(옮겨 붙인 것은 풀리지 않아 빠진다)",
         home.count() == 1 and home.is_visible() and "저장 안 된 글 4개" in home.inner_text(),
         home.inner_text()[:60] if home.count() == 1 else "없음")
    step("  줄마다 그 페이지 링크(P1 둘 · P2 · P3)",
         sorted(a.get_attribute("href").rsplit("/", 1)[-1] for a in home.locator("a").all()) == sorted([P1, P1, P2, P3]))
    step("  제목은 풀어서 보인다(P1 제목)", ("qf4b2 P1 " + MK) in home.inner_text())
    step("⑤ 옮겨 붙인 항목은 풀리지 않아 화면이 조용히 지웠다", moved not in store(td, PFX[E1]))
    shots4(ctx, td, COMM + "/", "qf4b2-home-drafts", "[data-drafts-home]")

    print("── ① ② P1 — 알림 줄 → 불러오기 → 마지막 줄까지 저장 · 답글은 쓰기 칸에만 (⑩ 스크린샷)", flush=True)
    goto(td, COMM + "/p/" + P1)
    nt = td.locator("[data-draft-notice]")
    step("① P1 알림 줄 「저장 안 된 내용이 있습니다 … 본문 · 답글」",
         nt.count() == 1 and nt.is_visible() and "저장 안 된 내용이 있습니다" in nt.inner_text() and "본문" in nt.inner_text() and "답글" in nt.inner_text(),
         nt.inner_text()[:80] if nt.count() == 1 else "없음")
    step("  불러오기는 누를 때만 — 아직 편집 모드 아님 · 쓰기 칸 비어 있음",
         td.locator("#page-editor").is_hidden() and td.locator("#comment-input").input_value() == "")
    shots4(ctx, td, COMM + "/p/" + P1, "qf4b2-page-notice", "[data-draft-notice]")
    t_click = time.time()
    td.locator("[data-draft-load]").click()
    wait_saved(td)
    td.wait_for_timeout(800)
    code, j = api(s1, "GET", "/community/api/pages/" + P1)
    step("① 불러오기 → 저장 200 → doc_version +1", j.get("page", {}).get("doc_version") == V1 + 1,
         "%s → %s" % (V1, j.get("page", {}).get("doc_version")))
    txt = doc_text(j)
    step("① 서버 본문에 첫 줄·마지막 줄 둘 다", L1 in txt and L2 in txt)
    step("⑥ 200 저장 뒤 P1 본문 열쇠 0", (PFX[E1] + "page:" + P1) not in store(td, PFX[E1]))
    step("② 답글이 쓰기 칸에 돌아왔다 · 「… 님 댓글에 답글」 표시",
         td.locator("#comment-input").input_value() == R1 and td.locator("#comment-reply-to").is_visible()
         and "댓글에 답글" in td.locator("#comment-reply-to").inner_text())
    sent = [r for r in seen["requests"] if r["t"] >= t_click and r["m"] == "POST" and r["p"].endswith("/comments")]
    code, j = api(s1, "GET", "/community/api/pages/%s/comments" % P1)
    step("② 자동 전송 0 — POST 댓글 요청 0 · 댓글 수 그대로", len(sent) == 0 and len(j.get("comments", [])) == N_COMMENTS_P1,
         "요청 %d · 댓글 %d→%d" % (len(sent), N_COMMENTS_P1, len(j.get("comments", []))))

    print("── ③ P2 — 그사이 employee02 가 고쳤다 → 불러오기 → 409 충돌 창", flush=True)
    goto(td, COMM + "/p/" + P2)
    nt = td.locator("[data-draft-notice]")
    step("③ P2 알림 줄", nt.count() == 1 and nt.is_visible())
    td.locator("[data-draft-load]").click()
    td.wait_for_selector("dialog#conflict-dialog[open]", timeout=12000)
    step("③ 기존 409 충돌 창이 열렸다(두 판 — 새로 고침 / 내 것으로 덮어쓰기)",
         td.locator("dialog#conflict-dialog[open]").count() == 1 and td.locator("#conflict-overwrite").is_visible())
    step("  그 저장 요청은 409", any(b.startswith("409 PATCH /community/api/pages/" + P2) for b in seen["http_bad"]))
    shot(td, "qf4b2-conflict-light-1440.png")
    td.locator("dialog#conflict-dialog [data-conflict-close]").click()
    step("  창을 닫아도 P2 사본은 남아 있다(저장되지 않았다)", (PFX[E1] + "page:" + P2) in store(td, PFX[E1]))

    print("── ⑥ 버리기 — 열쇠 0 · 서버 요청 0", flush=True)
    goto(td, COMM + "/p/" + P2)
    step("⑥ 다시 연 P2 에 알림 줄", td.locator("[data-draft-notice]").count() == 1)
    t_drop = time.time()
    td.locator("[data-draft-drop]").click()
    td.wait_for_timeout(1200)
    after = [r for r in seen["requests"] if r["t"] >= t_drop and r["bg"] != "1"]
    step("⑥ 「버리기」 → P2 본문 열쇠 0 · 알림 줄 사라짐",
         (PFX[E1] + "page:" + P2) not in store(td, PFX[E1]) and td.locator("[data-draft-notice]").count() == 0)
    step("⑥ 「버리기」 동안 서버 요청 0(자동 폴링 빼고)", len(after) == 0, str([(r["m"], r["p"]) for r in after][:3]))

    print("── ② P3 — 새 댓글이 쓰기 칸으로 · 자동 전송 0", flush=True)
    goto(td, COMM + "/p/" + P3)
    nt = td.locator("[data-draft-notice]")
    step("② P3 알림 줄 「… 댓글」", nt.count() == 1 and "댓글" in nt.inner_text())
    t_click = time.time()
    td.locator("[data-draft-load]").click()
    td.wait_for_timeout(1000)
    step("② 쓰기 칸에 쓰던 댓글이 돌아왔다(답글 표시 없음)",
         td.locator("#comment-input").input_value() == C1 and td.locator("#comment-reply-to").is_hidden())
    sent = [r for r in seen["requests"] if r["t"] >= t_click and r["m"] == "POST" and r["p"].endswith("/comments")]
    code, j = api(s1, "GET", "/community/api/pages/%s/comments" % P3)
    step("② 자동 전송 0 — POST 댓글 요청 0 · 댓글 수 그대로", len(sent) == 0 and len(j.get("comments", [])) == N_COMMENTS_P3)

    print("── ⑥ saved_at 8일 전 → 화면을 열면 지워짐", flush=True)
    old_key = PFX[E1] + "comment:%s:%s" % (P1, X)
    step("  준비 — P1 답글 사본은 아직 있다(보내지 않았다)", old_key in store(td, PFX[E1]))
    goto(td, TBOX + "/")
    td.evaluate("""(k) => { const r = JSON.parse(localStorage.getItem(k)); r.saved_at = Date.now() - 8 * 24 * 60 * 60 * 1000;
                     localStorage.setItem(k, JSON.stringify(r)); }""", old_key)
    goto(td, COMM + "/")
    step("⑥ 8일 지난 항목은 커뮤니티 화면을 열자 지워졌다", old_key not in store(td, PFX[E1]))
    step("  7일 안의 항목(P3 댓글)은 그대로", (PFX[E1] + "comment:%s:new" % P3) in store(td, PFX[E1]))

    print("── ⑥ 로그아웃 단추 → 그 사용자 열쇠 0 · 다른 사용자 항목은 그대로", flush=True)
    other = PFX[E2] + "page:" + P2
    td.evaluate("""(k) => localStorage.setItem(k, JSON.stringify({ v: 1, iv: 'AAAAAAAAAAAAAAAA', ct: 'AAAAAAAAAAAAAAAAAAAAAAAA', saved_at: Date.now() }))""", other)
    step("  준비 — employee01 항목 있음 · employee02 항목 하나", len(store(td, PFX[E1])) >= 1 and other in store(td, PFX[E2]))
    # 독립 검토 M-1(2026-09-24) — 쓰던 중에 로그아웃: 1초 묶음 타이머·떠나는 길(beforeunload·401)이
    # 지운 사본을 다시 쓰면 안 된다. 댓글 칸에 쓰고(사본 생김) 한 번 더 쓴 직후(타이머 도는 중) 로그아웃한다.
    goto(td, COMM + "/p/" + P3)
    td.locator("#comment-input").click()
    td.locator("#comment-input").type(" 로그아웃 직전 " + MK)
    td.wait_for_timeout(1800)
    step("  준비 — 쓰던 댓글 사본이 생겼다", (PFX[E1] + "comment:%s:new" % P3) in store(td, PFX[E1]))
    td.locator("#comment-input").type(" 한 줄 더")
    td.locator("summary.brand__switch").first.click()
    td.wait_for_timeout(300)
    td.locator("#logout").click()
    td.wait_for_url(re.compile(r".*/tbox/login.*"), timeout=15000)
    td.wait_for_timeout(1500)
    step("⑥ 로그아웃 → employee01 열쇠 0 (쓰던 중이어도 떠나는 길이 다시 쓰지 않는다)", len(store(td, PFX[E1])) == 0)
    step("⑥ employee02 항목은 그대로", other in store(td, PFX[E2]))
    td.evaluate("(k) => localStorage.removeItem(k)", other)
    for pg_ in (ta, tb, tc, td):
        pg_.close()
    ctx.close()

    print("── ⑦ 저장소 쓰기가 막힌 브라우저 — 편집·저장 정상 · 항목 0 · 배지", flush=True)
    s1, c1 = login(E1)
    ctx2 = browser.new_context(user_agent=UA, viewport={"width": 1440, "height": 900})
    set_session(ctx2, s1)
    ctx2.add_init_script("Storage.prototype.setItem = function () { throw new DOMException('blocked', 'QuotaExceededError'); };")
    pg7 = ctx2.new_page(); watch(pg7)
    goto(pg7, COMM + "/p/" + P1)
    code, j = api(s1, "GET", "/community/api/pages/" + P1)
    v_before = j.get("page", {}).get("doc_version", -1)
    start_edit(pg7)
    type_in_editor(pg7, " " + MK + " 막힌 저장소")
    wait_saved(pg7)
    pg7.wait_for_timeout(600)
    code, j = api(s1, "GET", "/community/api/pages/" + P1)
    step("⑦ 막힌 저장소 — 저장 200(doc_version +1)", j.get("page", {}).get("doc_version") == v_before + 1)
    step("⑦ 배지 「임시저장 못 함」", pg7.locator("[data-draft-off]").count() == 1 and pg7.locator("[data-draft-off]").is_visible())
    step("⑦ 저장소 항목 0(평문 대체 0)", pg7.evaluate("() => localStorage.length") == 0)
    shot(pg7, "qf4b2-draft-off-light-1440.png")
    ctx2.close()

    print("── ⑦ draft-key 503 — 편집·저장 정상 · 항목 0 · 배지", flush=True)
    ctx3 = browser.new_context(user_agent=UA, viewport={"width": 1440, "height": 900})
    set_session(ctx3, s1)
    ctx3.route("**/community/api/me/draft-key", lambda route: route.fulfill(
        status=503, content_type="application/json", body='{"error":"지금은 쓰던 글을 이 브라우저에 남길 수 없습니다"}'))
    pg8 = ctx3.new_page(); watch(pg8)
    goto(pg8, COMM + "/p/" + P1)
    code, j = api(s1, "GET", "/community/api/pages/" + P1)
    v_before = j.get("page", {}).get("doc_version", -1)
    start_edit(pg8)
    type_in_editor(pg8, " " + MK + " 열쇠 없음")
    wait_saved(pg8)
    pg8.wait_for_timeout(1500)
    code, j = api(s1, "GET", "/community/api/pages/" + P1)
    step("⑦ 503 — 저장 200(doc_version +1)", j.get("page", {}).get("doc_version") == v_before + 1)
    step("⑦ 503 — 배지 「임시저장 못 함」", pg8.locator("[data-draft-off]").count() == 1 and pg8.locator("[data-draft-off]").is_visible())
    step("⑦ 503 — employee01 항목 0(평문 대체 0)", len(store(pg8, PFX[E1])) == 0)
    ctx3.close()

    print("── ⑨ 탭이 숨을 때의 저장 요청에 keepalive · 저장 안 된 변경이 있을 때만 닫기 경고", flush=True)
    ctx4 = browser.new_context(user_agent=UA, viewport={"width": 1440, "height": 900})
    set_session(ctx4, s1)
    ctx4.add_init_script("""(() => { const orig = window.fetch; window.__qf4b2 = [];
      window.fetch = function (input, init) {
        try { window.__qf4b2.push({ m: (init && init.method) || 'GET', u: String(input), k: !!(init && init.keepalive) }); } catch (e) { }
        return orig.apply(this, arguments); }; })();""")
    aux = ctx4.new_page()          # 닫힌 탭의 대화상자 이벤트를 돌릴 곁 탭(기다림은 이 탭으로)
    pg9 = ctx4.new_page(); watch(pg9)
    goto(pg9, COMM + "/p/" + P1)
    start_edit(pg9)
    type_in_editor(pg9, " " + MK + " 숨김")
    pg9.evaluate("""() => { Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => 'hidden' });
        Object.defineProperty(document, 'hidden', { configurable: true, get: () => true });
        document.dispatchEvent(new Event('visibilitychange')); }""")
    pg9.wait_for_timeout(2500)
    calls = pg9.evaluate("() => window.__qf4b2")
    ka = [c for c in calls if c["m"] == "PATCH" and ("/api/pages/" + P1) in c["u"] and c["k"]]
    step("⑨ 탭 숨김 → 저장 PATCH 에 keepalive: true", len(ka) >= 1, str([(c["m"], c["k"]) for c in calls if c["m"] == "PATCH"][:3]))
    normal = [c for c in calls if c["m"] == "PATCH" and not c["k"]]
    note("평소 저장(keepalive 없음) %d건 · 닫는 중 저장(keepalive) %d건" % (len(normal), len(ka)))
    pg9.evaluate("() => { delete document.visibilityState; delete document.hidden; document.dispatchEvent(new Event('visibilitychange')); }")
    wait_saved(pg9)
    d0 = len(seen["dialogs"])
    pg9.close(run_before_unload=True)
    aux.wait_for_timeout(1500)
    step("⑨ 저장이 끝난 뒤 닫기 → 경고 없음", len(seen["dialogs"]) == d0, str(seen["dialogs"][d0:]))
    pg10 = ctx4.new_page(); watch(pg10)
    goto(pg10, COMM + "/p/" + P1)
    start_edit(pg10)
    type_in_editor(pg10, " " + MK + " 닫기 경고")
    d0 = len(seen["dialogs"])
    pg10.close(run_before_unload=True)
    aux.wait_for_timeout(2000)
    step("⑨ 저장 안 된 변경이 있을 때 닫기 → 브라우저 기본 경고(beforeunload)",
         any(t == "beforeunload" for _, t in seen["dialogs"][d0:]), str(seen["dialogs"][d0:]))
    ctx4.close()
    browser.close()

# ─── 공통 ───────────────────────────────────────────────────────────
print("── 공통", flush=True)
unexpected = [b for b in seen["http_bad"] if not re.match(r"^(401|409|503) ", b)]
step("pageerror 0", not seen["pageerrors"], str(seen["pageerrors"][:3]))
step("console.error 0 (예상한 401·409·503 자원 줄 빼고)", not seen["console"], str(seen["console"][:3]))
step("예상 밖 HTTP ≥ 400 0 (401 만료 · 409 충돌 · 503 가로챔 빼고)", not unexpected, str(unexpected[:4]))
print("SMOKE_FAIL=%d" % fails)
sys.exit(0 if fails == 0 else 1)
