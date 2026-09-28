#!/usr/bin/env python3
# -*- coding: utf-8 -*-
# tests/browser/hc_screens.py — 커뮤니티 7-D3 「고대비 화면」을 진짜 브라우저로 켜고 끈다 (C:T-213 · C:T-214 토큰 변화 0 · DESIGN.md 2.16)
#
# 부르는 쪽: tests/community/stage7d3.sh (임시 인스턴스 · 임시 DB `tbox_7d3_*`. 운영 80·8082·8081 에는 요청하지 않는다).
# 쓰는 법:   py_secret S_E1 -- python3 hc_screens.py <SITE> <UA> <SHOTS> <APP_CSS> <COMMUNITY_CSS>
#   세션(claude_employee01)은 **표준입력 첫 줄**로만 받는다(R-62 — 명령 인자·환경 변수에 비밀 0).
#   환경 변수: HC_PAGE=<페이지 id — 본문에 `nt-red` 글자> · HC_BOARD=<게시판 번호> · HC_BASE_SITE=<기준 판 인스턴스>(있으면 C:T-214 토큰 대조) ·
#             HC_COMBOS(조합 줄이기, 예 `360t,1280`) · HC_TOKENS_ONLY=1(C:T-214 토큰 대조만)
#   APP_CSS·COMMUNITY_CSS 는 고대비 기대값을 읽을 **저장소 파일**(tests/lib/contrast.py 와 같은 해석기) — 화면이 그린 값과 견준다.
#
# 보는 것
#   C:T-213 ① 내 계정 「화면」 체크 → 요청 정확히 하나(PATCH /tbox/api/me/settings · 본문 {"high_contrast":true}) → 곧바로
#             documentElement.dataset.contrast === 'high' · #msg 비어 있음 · 성공 알림(.ok-note 새로 뜸) 0
#           ② 새로 연 화면 일곱(런처 · t-box 파일 목록 · 내 계정 · 커뮤니티 홈 · 페이지 · 게시판 · 재인증 창)에서 유지 ·
#             주요 단추(.btn 기본) 배경 = 고대비 `--primary` · 페이지 본문 `.nt-red` 글자 = 고대비 `--nt-red` ·
#             360(터치) · 412(터치 · 마우스) · 820(터치) · 1280 × 라이트 · 다크 — 가로 넘침 0 · 콘솔 오류 0 · pageerror 0 · 기본 창 0 · HTTP ≥ 400 0
#           ③ 끄면 되돌아감(요청 하나 · 속성 없음 · 새로 연 화면도 없음)
#           ④ 서버 실패 흉내(route 로 500) — 체크가 되돌아가고 속성이 바뀌지 않고 #msg 에 서버 문구 · 서버 값 그대로(끔)
#   C:T-214 (HC_BASE_SITE 가 있을 때) 고대비 **끈** 상태에서 토큰 계산값(getComputedStyle — 두 CSS 파일 기본 :root 의 이름 전부, 그중 지시서의 20 은 따로 적음)이
#           기준 판 인스턴스와 글자 그대로 같다 — 라이트 · 다크 · 커뮤니티 페이지(두 파일이 다 실린 화면)
# 나가는 값: 마지막 줄 `SMOKE_FAIL=<n>` · 종료 코드 0/1
import json
import os
import re
import sys
import urllib.error
import urllib.request
from urllib.parse import urlsplit

from playwright.sync_api import sync_playwright

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.join(HERE, "..", "lib"))
import contrast  # noqa: E402  (tests/lib/contrast.py — 같은 해석기로 기대값을 읽는다)

SITE = sys.argv[1].rstrip("/")
UA = sys.argv[2]
SHOTS = sys.argv[3]
APP_CSS = sys.argv[4]
COM_CSS = sys.argv[5]
E1 = sys.stdin.readline().rstrip("\n")
if not E1:
    print("세션을 표준입력으로 주어야 한다 — 시작하지 않는다")
    print("SMOKE_FAIL=1")
    sys.exit(2)
HOST = re.match(r"https?://([^/:]+)", SITE).group(1)
PAGE = os.environ.get("HC_PAGE", "")
BOARD = os.environ.get("HC_BOARD", "")
BASE_SITE = (os.environ.get("HC_BASE_SITE") or "").rstrip("/")
TOKENS_ONLY = os.environ.get("HC_TOKENS_ONLY") == "1"
ALL_COMBOS = ["360t", "412t", "412m", "820t", "1280"]
COMBOS = [c for c in (os.environ.get("HC_COMBOS") or ",".join(ALL_COMBOS)).split(",") if c in ALL_COMBOS]
os.makedirs(SHOTS, exist_ok=True)
fails = 0
passes = 0
FAKE_ERR = "요청을 처리할 수 없습니다 (고대비 시험 — 서버 실패 흉내)"


def step(name, ok, detail=""):
    global fails, passes
    if ok:
        passes += 1
    else:
        fails += 1
    print("  %s %s%s" % ("✔" if ok else "✖", name, ("  — " + str(detail)[:400]) if (detail != "" and not ok) else ""), flush=True)


def note(msg):
    print("  · " + msg, flush=True)


# ─── 기대값 — 저장소 CSS 의 고대비 블록(캐스케이드 그대로 — contrast.py) ─────────────────────
FILES = {"app.css": contrast.read_blocks(APP_CSS), "community.css": contrast.read_blocks(COM_CSS)}


def effective(mode, hc=True):
    v = {}
    for tag in ("app.css", "community.css"):
        got = FILES[tag][0]
        order = ["base"] + (["base_dark"] if mode == "dark" else []) + ((["hc"] + (["hc_dark"] if mode == "dark" else [])) if hc else [])
        for key in order:
            if got.get(key):
                v.update(got[key][0])
    return v


def rgb_text(value):
    r, g, b = contrast.parse_color(value)
    return "rgb(%d, %d, %d)" % (round(r), round(g), round(b))


HC = {m: effective(m) for m in ("light", "dark")}
EXPECT_PRIMARY = {m: rgb_text(HC[m]["--primary"]) for m in HC}
EXPECT_NT_RED = {m: rgb_text(HC[m]["--nt-red"]) for m in HC}
BASE_NAMES = sorted(set(FILES["app.css"][0]["base"][0]) | set(FILES["community.css"][0]["base"][0]))
TOKENS_20 = ["--bg", "--surface", "--surface-2", "--muted-bg", "--fg", "--fg-2", "--muted", "--muted-2", "--line", "--primary",
             "--primary-fg", "--ring", "--accent", "--accent-dark", "--danger", "--warn-fg", "--ok-fg", "--violet-fg", "--nt-red", "--nt-yellow-bg"]
note("기대값(저장소 CSS) — 주요 단추 %s · 문서 빨강 %s" % (EXPECT_PRIMARY, EXPECT_NT_RED))


def api(method, path, body=None, site=None, csrf=None):
    h = {"User-Agent": UA, "Cookie": "__Host-tbox_session=" + E1}
    data = None
    if body is not None:
        data = json.dumps(body).encode()
        h["Content-Type"] = "application/json"
    if csrf:
        h["X-CSRF-Token"] = csrf
    try:
        with urllib.request.urlopen(urllib.request.Request((site or SITE) + path, data=data, method=method, headers=h), timeout=60) as r:
            code, txt = r.status, r.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as e:
        code, txt = e.code, e.read().decode("utf-8", "replace")
    try:
        return code, json.loads(txt or "{}")
    except ValueError:
        return code, {"_raw": txt[:200]}


def me():
    return api("GET", "/tbox/api/me")[1]


def set_contrast(on):
    m = me()
    return api("PATCH", "/tbox/api/me/settings", {"high_contrast": on}, csrf=m.get("csrf_token", ""))


def ctx(br, combo, theme):
    w = int(combo.rstrip("tm"))
    touch = combo.endswith("t")
    phone = w < 720
    c = br.new_context(user_agent=UA, viewport={"width": w, "height": 780 if phone else 900}, device_scale_factor=1,
                       has_touch=touch, is_mobile=phone and touch, color_scheme="light")
    c.add_cookies([
        {"name": "tbox_theme", "value": theme, "domain": HOST, "path": "/"},
        {"name": "__Host-tbox_session", "value": E1, "domain": HOST, "path": "/", "httpOnly": True, "secure": True},
    ])
    return c


def page_of(c, seen):
    pg = c.new_page()
    pg.on("dialog", lambda d: (seen["dialogs"].append(d.type + ": " + d.message[:60]), d.dismiss()))
    pg.on("pageerror", lambda e: seen["errors"].append("pageerror " + str(e)[:160]))
    pg.on("console", lambda m: seen["errors"].append("console " + m.text[:160]) if m.type == "error" else None)
    pg.on("response", lambda r: seen["http"].append("%d %s" % (r.status, urlsplit(r.url).path))
          if r.status >= 400 and HOST in r.url else None)
    return pg


def fresh():
    return {"dialogs": [], "errors": [], "http": []}


def go(pg, url):
    r = pg.goto(url, wait_until="networkidle", timeout=30000)
    pg.wait_for_timeout(300)
    return r


OVER = "() => document.documentElement.scrollWidth - document.documentElement.clientWidth"
PRIMARY_BG = """() => {
  const els = Array.from(document.querySelectorAll('.btn')).filter(e =>
    !/(^|\\s)btn--(quiet|plain|danger|icon|small)(\\s|$)/.test(e.className) && e.offsetParent !== null && !e.disabled);
  return els.length ? getComputedStyle(els[0]).backgroundColor + ' ' + (els[0].textContent || '').trim().slice(0, 16) : '';
}"""


with sync_playwright() as pw:
    br = pw.chromium.launch()

    if not TOKENS_ONLY:
        # ═══ C:T-213 ① 켜기 ═══════════════════════════════════════════════════
        print("── C:T-213 ① 내 계정 「화면」 체크 → 요청 하나 → 곧바로 고대비")
        c0, _ = set_contrast(False)
        step("준비 — 끈 상태에서 시작(창구 200)", c0 == 200, c0)
        seen = fresh()
        c = ctx(br, "1280", "light")
        pg = page_of(c, seen)
        go(pg, SITE + "/tbox/settings")
        box = pg.locator("#contrast-toggle")
        step("「화면」 패널 제목 · 설명 · 체크 이름표 · 도움말 한 줄",
             pg.locator(".panel__title", has_text="화면").count() == 1
             and pg.locator(".panel__sub", has_text="이 화면에서만이 아니라 내 계정 전체에 적용됩니다.").count() == 1
             and pg.locator("label.field", has_text="고대비 화면").count() == 1
             and pg.locator(".help", has_text="글자와 배경의 차이를 더 크게 합니다. 밝은 화면·어두운 화면 모두에 적용됩니다.").count() == 1)
        step("체크 — 켤 수 있고(disabled 풀림) 처음 상태 끔 · <html> 속성 없음",
             box.is_enabled() and not box.is_checked() and pg.evaluate("() => document.documentElement.hasAttribute('data-contrast')") is False)
        reqs = []
        pg.on("request", lambda r: reqs.append((r.method, urlsplit(r.url).path, r.post_data or "")) if "/api/me/settings" in r.url else None)
        oknotes0 = pg.locator(".ok-note:not([hidden])").count()
        with pg.expect_response(lambda r: "/tbox/api/me/settings" in r.url) as resp_info:
            box.check()
        resp = resp_info.value
        pg.wait_for_function("() => document.documentElement.dataset.contrast === 'high'", timeout=5000)
        pg.wait_for_timeout(500)   # 단추 배경은 0.12s 전환 — 전환이 끝난 뒤 잰다
        step("요청 정확히 하나 — PATCH /tbox/api/me/settings {\"high_contrast\":true} → 200",
             len(reqs) == 1 and reqs[0][0] == "PATCH" and reqs[0][1] == "/tbox/api/me/settings"
             and json.loads(reqs[0][2] or "{}") == {"high_contrast": True} and resp.status == 200, (reqs, resp.status))
        step("곧바로 documentElement.dataset.contrast === 'high' · 체크 켜짐 · #msg 비어 있음 · 성공 알림 0",
             pg.evaluate("() => document.documentElement.dataset.contrast") == "high" and box.is_checked()
             and (pg.text_content("#msg") or "").strip() == "" and pg.locator(".ok-note:not([hidden])").count() == oknotes0)
        step("주요 단추 배경 = 고대비 --primary(밝게 %s)" % EXPECT_PRIMARY["light"],
             pg.evaluate(PRIMARY_BG).startswith(EXPECT_PRIMARY["light"] + " "), pg.evaluate(PRIMARY_BG))
        m = me()
        step("서버 값도 켜짐(/api/me.high_contrast === true)", m.get("high_contrast") is True, m.get("high_contrast"))
        pg.screenshot(path=os.path.join(SHOTS, "settings-toggle-on-light-1280.png"))
        step("① 콘솔·pageerror·기본 창·HTTP≥400 0", not seen["errors"] and not seen["dialogs"] and not seen["http"], seen)
        c.close()

        # ═══ C:T-213 ② 새로 연 화면 일곱 × 폭 × 색 ═══════════════════════════════
        print("── C:T-213 ② 새로 연 화면에서 유지 · 넘침 0 · 콘솔 0 · 기본 창 0 (%s × 라이트·다크)" % ",".join(COMBOS))
        screens = [("런처", "/"), ("t-box 파일 목록", "/tbox/"), ("내 계정", "/tbox/settings"), ("커뮤니티 홈", "/community/")]
        if PAGE:
            screens.append(("페이지", "/community/p/" + PAGE))
        if BOARD:
            screens.append(("게시판", "/community/b/" + BOARD))
        screens.append(("재인증 창", "/tbox/reauth?to=admin"))
        step("화면 일곱이 다 있다(페이지 · 게시판 id 를 받음)", len(screens) == 7, [s[0] for s in screens])
        for theme in ("light", "dark"):
            for combo in COMBOS:
                seen = fresh()
                c = ctx(br, combo, theme)
                pg = page_of(c, seen)
                bad = []
                for name, path in screens:
                    r = go(pg, SITE + path)
                    st = r.status if r else 0
                    attr = pg.evaluate("() => [document.documentElement.getAttribute('data-contrast'), document.documentElement.getAttribute('data-theme')]")
                    over = pg.evaluate(OVER)
                    if st != 200 or attr[0] != "high" or attr[1] != theme or over > 0:
                        bad.append("%s 상태 %s · data-contrast %s · data-theme %s · 넘침 %s" % (name, st, attr[0], attr[1], over))
                    pb = pg.evaluate(PRIMARY_BG)
                    if pb and not pb.startswith(EXPECT_PRIMARY[theme] + " "):
                        bad.append("%s 주요 단추 배경 %s (기대 %s)" % (name, pb, EXPECT_PRIMARY[theme]))
                    if name == "페이지":
                        red = pg.evaluate("() => { const e = document.querySelector('.doc .nt-red'); return e ? getComputedStyle(e).color : ''; }")
                        if red != EXPECT_NT_RED[theme]:
                            bad.append("페이지 문서 빨강 %r (기대 %s)" % (red, EXPECT_NT_RED[theme]))
                        elif combo == COMBOS[-1]:
                            note("%s·%s 페이지 문서 .nt-red 계산값 %s = 고대비 --nt-red" % (theme, combo, red))
                    if combo in ("360t", "1280"):
                        pg.screenshot(path=os.path.join(SHOTS, "hc-%s-%s-%s.png" % (theme, combo, re.sub(r"[^a-z0-9]+", "-", path.strip("/").split("?")[0] or "launcher"))))
                step("%s·%s 화면 일곱 — 고대비 유지 · 테마 그대로 · 주요 단추 · 넘침 0" % (theme, combo), bad == [], bad)
                step("%s·%s 콘솔·pageerror 0 · 기본 창 0 · HTTP≥400 0" % (theme, combo),
                     not seen["errors"] and not seen["dialogs"] and not seen["http"], seen)
                c.close()

        # ═══ C:T-213 ③ 끄기 ═════════════════════════════════════════════════════
        print("── C:T-213 ③ 끄면 되돌아감")
        seen = fresh()
        c = ctx(br, "1280", "dark")
        pg = page_of(c, seen)
        go(pg, SITE + "/tbox/settings")
        box = pg.locator("#contrast-toggle")
        step("켠 상태로 열림(체크 켜짐 · 속성 high · 다크)", box.is_checked()
             and pg.evaluate("() => [document.documentElement.dataset.contrast, document.documentElement.dataset.theme].join(',')") == "high,dark")
        reqs = []
        pg.on("request", lambda r: reqs.append((r.method, r.post_data or "")) if "/api/me/settings" in r.url else None)
        with pg.expect_response(lambda r: "/tbox/api/me/settings" in r.url) as resp_info:
            box.uncheck()
        pg.wait_for_function("() => !document.documentElement.hasAttribute('data-contrast')", timeout=5000)
        pg.wait_for_timeout(500)   # 단추 배경은 0.12s 전환(DESIGN 4장 hover) — 전환이 끝난 뒤 잰다
        step("요청 하나 {\"high_contrast\":false} → 200 · 속성 없어짐 · 다크는 그대로",
             len(reqs) == 1 and json.loads(reqs[0][1] or "{}") == {"high_contrast": False} and resp_info.value.status == 200
             and pg.evaluate("() => document.documentElement.dataset.theme") == "dark", reqs)
        step("끈 뒤 주요 단추 배경 = 기본 다크 --primary", pg.evaluate(PRIMARY_BG).startswith(rgb_text(effective("dark", hc=False)["--primary"]) + " "),
             pg.evaluate(PRIMARY_BG))
        for name, path in (("런처", "/"), ("커뮤니티 홈", "/community/")):
            go(pg, SITE + path)
            step("끈 뒤 새로 연 %s — 속성 없음" % name, pg.evaluate("() => document.documentElement.hasAttribute('data-contrast')") is False)
        step("③ 콘솔·pageerror·기본 창·HTTP≥400 0", not seen["errors"] and not seen["dialogs"] and not seen["http"], seen)
        c.close()

        # ═══ C:T-213 ④ 서버 실패 흉내 ════════════════════════════════════════════
        print("── C:T-213 ④ 서버 실패 흉내(route 로 500) — 체크 되돌림 · #msg 서버 문구")
        seen = fresh()
        c = ctx(br, "412t", "light")
        pg = page_of(c, seen)
        go(pg, SITE + "/tbox/settings")
        hits = []

        def fake(route):
            hits.append(route.request.method)
            route.fulfill(status=500, content_type="application/json; charset=utf-8", body=json.dumps({"error": FAKE_ERR}, ensure_ascii=False))
        pg.route("**/tbox/api/me/settings", fake)
        box = pg.locator("#contrast-toggle")
        box.click()
        pg.wait_for_function("() => (document.getElementById('msg') || {}).textContent", timeout=5000)
        pg.wait_for_timeout(200)
        step("요청 하나(흉내 500) · 체크 되돌아감(끔) · 속성 그대로 없음 · #msg = 서버 문구",
             hits == ["PATCH"] and not box.is_checked() and pg.evaluate("() => document.documentElement.hasAttribute('data-contrast')") is False
             and (pg.text_content("#msg") or "").strip() == FAKE_ERR, (hits, box.is_checked(), pg.text_content("#msg")))
        step("체크를 다시 누를 수 있다(disabled 풀림)", box.is_enabled())
        pg.screenshot(path=os.path.join(SHOTS, "settings-fail-light-412t.png"))
        pg.unroute("**/tbox/api/me/settings")
        # 흉내 500 은 브라우저가 콘솔에 「Failed to load resource … 500」 한 줄을 남긴다 — 그 한 줄만 뺀다
        errs = [e for e in seen["errors"] if not ("500" in e and "Failed to load resource" in e)]
        http = [h for h in seen["http"] if h != "500 /tbox/api/me/settings"]
        step("④ 그 밖의 콘솔·pageerror 0 · 기본 창 0 · HTTP≥400 0", not errs and not seen["dialogs"] and not http, (errs, seen["dialogs"], http))
        m = me()
        step("서버 값은 그대로 끔(/api/me.high_contrast === false)", m.get("high_contrast") is False, m.get("high_contrast"))
        c.close()

    # ═══ C:T-214 끈 상태 — 토큰 계산값 = 기준 판 ═══════════════════════════════════
    if BASE_SITE:
        print("── C:T-214 끈 상태 — 토큰 계산값(두 CSS 파일 기본 :root 이름 %d개 · 지시서 20 포함)이 기준 판(%s)과 글자 그대로 같다" % (len(BASE_NAMES), BASE_SITE))
        m = me()
        step("끈 상태(/api/me.high_contrast === false)", m.get("high_contrast") is False, m.get("high_contrast"))
        target = "/community/p/" + PAGE if PAGE else "/community/"
        READ = "(names) => { const s = getComputedStyle(document.documentElement); const o = {}; for (const n of names) o[n] = s.getPropertyValue(n).trim(); return [o, document.documentElement.hasAttribute('data-contrast')]; }"
        for theme in ("light", "dark"):
            vals = {}
            for label, site in (("새 판", SITE), ("기준 판", BASE_SITE)):
                seen = fresh()
                c = ctx(br, "1280", theme)
                pg = page_of(c, seen)
                r = go(pg, site + target)
                o, has_attr = pg.evaluate(READ, BASE_NAMES)
                vals[label] = o
                step("%s %s %s — 200 · data-contrast 없음 · 콘솔 0" % (theme, label, target), (r.status if r else 0) == 200 and has_attr is False
                     and not seen["errors"] and not seen["http"], (r.status if r else 0, has_attr, seen))
                c.close()
            diff = [n for n in BASE_NAMES if vals["새 판"].get(n) != vals["기준 판"].get(n)]
            empty = [n for n in TOKENS_20 if not vals["새 판"].get(n)]
            step("%s 토큰 %d개 계산값 기준 판과 같음(다른 것 0)" % (theme, len(BASE_NAMES)), diff == [], [(n, vals["새 판"].get(n), vals["기준 판"].get(n)) for n in diff[:6]])
            step("%s 지시서의 20 이 모두 값이 있고 같다" % theme, empty == [] and all(vals["새 판"][n] == vals["기준 판"][n] for n in TOKENS_20), empty)
            note("%s 20: %s" % (theme, " · ".join("%s=%s" % (n, vals["새 판"].get(n)) for n in TOKENS_20)))

    br.close()

print("통과 %d · 실패 %d" % (passes, fails))
print("SMOKE_FAIL=%d" % fails)
sys.exit(1 if fails else 0)
