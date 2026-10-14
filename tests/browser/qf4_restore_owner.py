#!/usr/bin/env python3
# tests/browser/qf4_restore_owner.py — Q:F-4 U-2b 「내 휴지통」 헤드리스 수락 시험
#
# 부르는 쪽: tests/qf4_restore_owner.sh (임시 인스턴스 · 임시 DB · 포트 8101). 운영 8082 에는 요청하지 않는다.
# 쓰는 법:   SMOKE_E1=<claude_employee01 세션> SMOKE_A1=<claude_admin01 세션(재인증 뒤)> \
#            OWN_ID=… OWN_NAME=… OTHER_NAME=… SHOTS=<스크린샷 폴더> python3 qf4_restore_owner.py <SITE> <UA>
#   세션은 **환경 변수**로만 받는다(명령 인자에 싣지 않는다 — R-62).
#
# 보는 것
#   ⓐ 비관리자(claude_employee01) — 사이드바 「파일」 묶음에 「내 휴지통」이 보이고, 관리 묶음의 「휴지통」은 닫혀 있다
#   ⓑ 「내 휴지통」을 누르면 재인증 창 없이 /tbox/trash 로 간다 · 제목 「내 휴지통」 · 열 5개(올린 사람·지운 사람 없음)
#   ⓒ 내 파일(OWN_NAME)은 있고 남이 지운 내 파일(OTHER_NAME)은 없다
#   ⓓ 라이트·다크 1440 · 라이트·다크 390px 스크린샷 · 390px 에서 문서 가로 넘침 0
#   ⓔ 복구 단추 → POST …/restore 1건 200 · 결과 한 줄(.ok-note) · 행 사라짐
#   ⓕ 관리자(claude_admin01, 재인증 뒤) — 「내 휴지통」 닫힘 · 관리 묶음 「휴지통」 열림 · /tbox/trash 제목 「휴지통」 · 열 7개
# 공통: pageerror 0 · console.error 0 · HTTP ≥ 400 0 · 브라우저 기본 대화상자 0
# 나가는 값: 0 통과 · 1 실패 (마지막 줄 `SMOKE_FAIL=<개수>`)
import os
import re
import sys
from urllib.parse import urlsplit

from playwright.sync_api import sync_playwright

SITE = sys.argv[1].rstrip("/")
UA = sys.argv[2]
E1 = os.environ["SMOKE_E1"]
A1 = os.environ["SMOKE_A1"]
OWN_ID = os.environ["OWN_ID"]
OWN_NAME = os.environ["OWN_NAME"]
OTHER_NAME = os.environ["OTHER_NAME"]
SHOTS = os.environ.get("SHOTS", ".")
HOST = re.match(r"https?://([^/:]+)", SITE).group(1)
TBOX = SITE + "/tbox"

fails = 0


def step(name, ok, detail=""):
    global fails
    if not ok:
        fails += 1
    print("  %s %s%s" % ("✔" if ok else "✖", name, ("  — " + detail) if detail else ""))


def new_context(browser, session, theme="light", width=1440, height=900):
    ctx = browser.new_context(user_agent=UA, viewport={"width": width, "height": height})
    ctx.add_cookies([
        {"name": "__Host-tbox_session", "value": session, "domain": HOST, "path": "/", "httpOnly": True, "secure": True, "sameSite": "Lax"},
        {"name": "tbox_theme", "value": theme, "domain": HOST, "path": "/", "secure": False, "sameSite": "Lax"},
        {"name": "qaredi_sidebar", "value": "open", "domain": HOST, "path": "/", "secure": False, "sameSite": "Lax"},  # 카페 C5 — 펼친 셸을 잰다(쿠키 qaredi_sidebar 없으면 접힘이 기본)
    ])
    return ctx


def fresh_seen():
    return {"pageerrors": [], "console": [], "requests": [], "http_bad": [], "native_dialogs": []}


def watch(page, seen):
    page.on("pageerror", lambda e: seen["pageerrors"].append(str(e)))
    page.on("console", lambda m: seen["console"].append(m.text) if m.type == "error" else None)
    page.on("request", lambda r: seen["requests"].append((r.method, urlsplit(r.url).path)) if HOST in r.url else None)
    page.on("response", lambda r: seen["http_bad"].append("%d %s" % (r.status, urlsplit(r.url).path))
            if r.status >= 400 and HOST in r.url else None)

    def on_dialog(d):
        seen["native_dialogs"].append(d.type + ":" + d.message[:80])
        d.dismiss()
    page.on("dialog", on_dialog)


def clean(seen, label):
    step("%s — pageerror·console.error·HTTP≥400·기본 대화상자 0" % label,
         not seen["pageerrors"] and not seen["console"] and not seen["http_bad"] and not seen["native_dialogs"],
         "%s %s %s %s" % (seen["pageerrors"][:2], seen["console"][:2], seen["http_bad"][:2], seen["native_dialogs"][:2]))


def visible(page, sel):
    loc = page.locator(sel)
    return loc.count() == 1 and loc.is_visible()


def reauth_open(page):
    return page.locator("dialog[open] #reauth-dialog-form").count() > 0


def header_cells(page):
    return [t.strip() for t in page.locator("table.files thead th").all_inner_texts()]


def row(page, name):
    return page.locator("#trash-body tr", has_text=name)


with sync_playwright() as p:
    browser = p.chromium.launch()

    # ─── ⓐⓑⓒ 비관리자 — 사이드바 · 링크 · 표 (1440 라이트) ─────────────────
    print("── ⓐ 비관리자 사이드바 — 「내 휴지통」 열림 · 관리 묶음 닫힘")
    seen = fresh_seen()
    ctx = new_context(browser, E1, "light")
    page = ctx.new_page()
    watch(page, seen)
    page.goto(TBOX + "/", wait_until="networkidle")
    page.wait_for_timeout(500)
    step("ⓐ #nav-my-trash 가 보인다", visible(page, "#nav-my-trash"))
    step("ⓐ 「내 휴지통」 글자", page.locator("#nav-my-trash").inner_text().strip() == "내 휴지통",
         page.locator("#nav-my-trash").inner_text().strip())
    step("ⓐ 관리 묶음(#nav-admin-group)·관리 「휴지통」(#nav-trash)은 닫혀 있다",
         page.locator("#nav-admin-group").is_hidden() and page.locator("#nav-trash").is_hidden())
    page.screenshot(path=os.path.join(SHOTS, "qf4u2b-files-nav-light-1440.png"))

    print("── ⓑ 「내 휴지통」을 누르면 재인증 창 없이 화면으로")
    page.locator("#nav-my-trash").click()
    page.wait_for_load_state("networkidle")
    page.wait_for_timeout(500)
    path = urlsplit(page.url).path
    step("ⓑ 주소가 /tbox/trash", path == "/tbox/trash", page.url)
    step("ⓑ 재인증 창이 뜨지 않았다", not reauth_open(page))
    step("ⓑ 제목 「내 휴지통」", page.locator("h1.page-head__title").inner_text().strip() == "내 휴지통",
         page.locator("h1.page-head__title").inner_text().strip())
    step("ⓑ 「내 휴지통」 항목이 켜져 있다(aria-current 또는 활성 클래스)",
         page.evaluate("() => { const a = document.getElementById('nav-my-trash');"
                       " return !!a && (a.getAttribute('aria-current') === 'page' || /is-active|nav__item--active/.test(a.className)); }"))
    heads = header_cells(page)
    step("ⓑ 표의 열 5개 — 이름·크기·등급·지운 시각·작업(디버깅 묶음 U2 낱말 표 — 레벨 → 등급)", heads == ["이름", "크기", "등급", "지운 시각", "작업"], str(heads))

    print("── ⓒ 표의 행 — 내가 지운 내 파일만")
    step("ⓒ 내 파일 행이 있다", row(page, OWN_NAME).count() == 1, OWN_NAME)
    step("ⓒ 남(관리자)이 지운 내 파일은 없다", row(page, OTHER_NAME).count() == 0, OTHER_NAME)
    cells = row(page, OWN_NAME).locator("td").count() if row(page, OWN_NAME).count() == 1 else -1
    step("ⓒ 행의 칸 5개(머리와 같다)", cells == 5, str(cells))
    page.screenshot(path=os.path.join(SHOTS, "qf4u2b-my-trash-light-1440.png"), full_page=True)

    # ─── ⓓ 다크 1440 · 390px 라이트/다크 ─────────────────────────────
    print("── ⓓ 다크 1440 · 390px 라이트/다크 스크린샷 · 가로 넘침")
    for theme, width, height in (("dark", 1440, 900), ("light", 390, 844), ("dark", 390, 844)):
        s2 = fresh_seen()
        c2 = new_context(browser, E1, theme, width, height)
        p2 = c2.new_page()
        watch(p2, s2)
        p2.goto(TBOX + "/trash", wait_until="networkidle")
        p2.wait_for_timeout(500)
        g = p2.evaluate("() => ({vw: innerWidth, sw: document.documentElement.scrollWidth,"
                        " theme: document.documentElement.getAttribute('data-theme') || ''})")
        step("ⓓ %s %dpx — 제목 「내 휴지통」 · 내 파일 행" % (theme, width),
             p2.locator("h1.page-head__title").inner_text().strip() == "내 휴지통" and row(p2, OWN_NAME).count() == 1)
        step("ⓓ %s %dpx — 문서 가로 넘침 0" % (theme, width), g["sw"] <= g["vw"] + 1, "scrollWidth %d / 화면 %d" % (g["sw"], g["vw"]))
        p2.screenshot(path=os.path.join(SHOTS, "qf4u2b-my-trash-%s-%d.png" % (theme, width)), full_page=True)
        if width < 960:
            # 좁은 화면 — 접이식 내비를 열어 「내 휴지통」 항목을 찍는다
            toggle = p2.locator("#nav-toggle")
            if toggle.count() == 1 and toggle.is_visible():
                toggle.click()
                p2.wait_for_timeout(300)
                step("ⓓ %s %dpx — 접이식 내비에 「내 휴지통」" % (theme, width), visible(p2, "#nav-my-trash"))
                p2.screenshot(path=os.path.join(SHOTS, "qf4u2b-nav-open-%s-%d.png" % (theme, width)))
        clean(s2, "ⓓ %s %dpx" % (theme, width))
        c2.close()

    # ─── ⓔ 복구 단추 ─────────────────────────────────────────────
    print("── ⓔ 복구 단추 → POST 1건 200 · 결과 한 줄 · 행 사라짐")
    mark = len(seen["requests"])
    target = row(page, OWN_NAME).locator('button[aria-label="되살리기"]')   # 디버깅 묶음 U2 낱말 표 — 복구 → 되살리기
    with page.expect_response(lambda r: r.request.method == "POST" and r.url.endswith("/tbox/api/files/%s/restore" % OWN_ID)) as ri:
        target.click()
    resp = ri.value
    page.wait_for_load_state("networkidle")
    page.wait_for_timeout(500)
    posts = [r for r in seen["requests"][mark:] if r[0] == "POST"]
    step("ⓔ POST 정확히 1건 · 200", len(posts) == 1 and resp.status == 200, "%s · %d" % (posts, resp.status))
    ok_vis = page.locator("#trash-ok").is_visible()
    ok_text = page.locator("#trash-ok").inner_text() if ok_vis else ""
    step("ⓔ 결과 한 줄(.ok-note)이 보인다 — 파일 이름 · 「되살렸습니다」", ok_vis and OWN_NAME in ok_text and "되살렸습니다" in ok_text, ok_text)
    step("ⓔ 행이 사라졌다", row(page, OWN_NAME).count() == 0)
    step("ⓔ 재인증 창은 끝까지 뜨지 않았다", not reauth_open(page))
    page.screenshot(path=os.path.join(SHOTS, "qf4u2b-my-trash-restored-light-1440.png"), full_page=True)
    clean(seen, "비관리자 1440 라이트 흐름")
    ctx.close()

    # ─── ⓕ 관리자 — 전체 휴지통 ─────────────────────────────────────
    print("── ⓕ 관리자(재인증 뒤) — 「내 휴지통」 닫힘 · 관리 「휴지통」 · 전체 표")
    s3 = fresh_seen()
    c3 = new_context(browser, A1, "light")
    p3 = c3.new_page()
    watch(p3, s3)
    p3.goto(TBOX + "/", wait_until="networkidle")
    p3.wait_for_timeout(500)
    step("ⓕ 관리자에게 #nav-my-trash 는 닫혀 있다", p3.locator("#nav-my-trash").is_hidden())
    step("ⓕ 관리자에게 관리 「휴지통」(#nav-trash)은 열려 있다", visible(p3, "#nav-trash"))
    p3.goto(TBOX + "/trash", wait_until="networkidle")
    p3.wait_for_timeout(500)
    step("ⓕ 관리자 /tbox/trash — 주소 그대로(재인증 창 아님)", urlsplit(p3.url).path == "/tbox/trash", p3.url)
    step("ⓕ 제목 「휴지통」", p3.locator("h1.page-head__title").inner_text().strip() == "휴지통",
         p3.locator("h1.page-head__title").inner_text().strip())
    heads = header_cells(p3)
    step("ⓕ 열 7개 — 올린 사람·지운 사람 포함", heads == ["이름", "크기", "등급", "올린 사람", "지운 사람", "지운 시각", "작업"], str(heads))
    step("ⓕ 관리자 표에는 남이 지운 그 파일도 있다", row(p3, OTHER_NAME).count() == 1, OTHER_NAME)
    p3.screenshot(path=os.path.join(SHOTS, "qf4u2b-admin-trash-light-1440.png"), full_page=True)
    clean(s3, "관리자 1440 라이트")
    c3.close()

    browser.close()

print("SMOKE_FAIL=%d" % fails)
sys.exit(0 if fails == 0 else 1)
