#!/usr/bin/env python3
# tests/browser/qf3a_favorites.py — 개발 대기열 6 · Q:F-3a 라운드 A 화면을 진짜 브라우저로 눌러 본다 (지시서 QF3A-T9 의 라운드 A 몫)
#
# 부르는 쪽: tests/community/qf3a.sh (임시 인스턴스 · 임시 DB — 운영 8082 에는 요청하지 않는다).
# 쓰는 법: printf '%s\n%s\n' "$세션_직원1" "$세션_직원2" | python3 qf3a_favorites.py <SITE> <UA> <SHOTS 폴더> <IDS_JSON>
#   IDS_JSON = qf3a.sh 가 만든 {file_id} — 직원1 이 받을 수 있는 clean 파일(「최근」 보기 재료)
#   세션 둘은 **표준입력 한 줄씩**(R-62 — 명령 인자·환경 변수에 싣지 않는다).
# 보는 것
#   t-box   ① 파일 줄 이름 앞 ☆ — 누르면 찬 별(aria-pressed) · Space 로도 · 「즐겨찾기」 보기에 그 파일 · 보기 단추 aria-pressed ·
#           검색 줄 숨김 · 「폴더」 칸 ② 폴더를 열고 「즐겨찾기에 넣기」 → 「즐겨찾기」 보기의 폴더 묶음 → 누르면 그 폴더를 「파일」 보기로
#           ③ 받기 뒤 「최근」 보기 — 그 파일 · 시각 칸 머리 「마지막으로 연 때」 ④ 「즐겨찾기」 보기에서 ☆ 빼기 → 줄이 사라짐
#           ⑤ 표 마지막 줄 ☆ 에 마우스를 올려도 표·문서 크기 그대로(DESIGN 2.8 ★) ⑥ 라이트·다크 1440 · 390px(가로 넘침 0)
#   커뮤니티 ⑦ 페이지 「더보기」 → 「즐겨찾기에 넣기」 → 사이드바 「즐겨찾기」에 그 페이지 · 메뉴 글자 「빼기」 · 「최근」에 그 페이지
#           ⑧ Alt+↓ 로 순서 바꾸기 → 새로 고침 뒤에도 그 순서 ⑨ 「즐겨찾기」 제목을 눌러 접기 → 새로 고침 뒤에도 접힘 → 다시 펼침
#           ⑩ 「더 보기」 창 — 목록 · 「최근 기록 지우기」 두 번 → 비었음 · Esc ⑪ 라이트·다크 1440 · 390px(사이드바 서랍 · 가로 넘침 0)
#           ⑫ 빼기 → 사이드바에서 사라짐
#   전 과정 pageerror · console.error · 요청 실패 · HTTP≥400 이 0
# 나가는 값: 마지막 줄 `SMOKE_FAIL=<n>` · 종료 코드 0/1
import json
import os
import re
import sys
import time
import uuid

from playwright.sync_api import sync_playwright, TimeoutError as PlaywrightTimeout

SITE = sys.argv[1].rstrip("/")
UA = sys.argv[2]
SHOTS = sys.argv[3]
IDS = json.load(open(sys.argv[4], encoding="utf-8"))
E1 = sys.stdin.readline().rstrip("\n")
E2 = sys.stdin.readline().rstrip("\n")
if not E1 or not E2:
    print("세션 둘(직원1·직원2)을 표준입력으로 한 줄씩 주어야 한다 — 시작하지 않는다")
    sys.exit(2)
TBOX = SITE + "/tbox"
COMMUNITY = SITE + "/community"
fails = 0
pageerrors, console_errors, http_bad, req_failed = [], [], [], []
TAG = uuid.uuid4().hex[:6]


def step(name, ok, detail=""):
    global fails
    if not ok:
        fails += 1
    print("  %s %s%s" % ("✔" if ok else "✖", name, ("  — " + str(detail)[:240]) if (detail != "" and not ok) else ""))


def host():
    return re.match(r"https?://([^/:]+)", SITE).group(1)


def context(browser, session, scheme="light", width=1440, height=900, touch=False):
    ctx = browser.new_context(user_agent=UA, viewport={"width": width, "height": height}, color_scheme=scheme,
                              has_touch=touch, is_mobile=touch)
    ctx.add_cookies([{"name": "__Host-tbox_session", "value": session, "domain": host(), "path": "/",
                      "httpOnly": True, "secure": True, "sameSite": "Lax"},
                     {"name": "qaredi_sidebar", "value": "open", "domain": host(), "path": "/"}])  # 카페 C5 — 펼친 셸을 잰다(쿠키 qaredi_sidebar 없으면 접힘이 기본)
    page = ctx.new_page()
    page.on("pageerror", lambda e: pageerrors.append(str(e)))
    page.on("console", lambda m: console_errors.append(m.text)
            if m.type == "error" and "Failed to load resource" not in m.text else None)
    page.on("requestfailed", lambda r: req_failed.append(r.url))
    page.on("response", lambda r: http_bad.append("%d %s" % (r.status, r.url))
            if r.status >= 400 and host() in r.url else None)
    return ctx, page


def csrf(page):
    return (page.request.get(TBOX + "/api/me").json() or {}).get("csrf_token", "")


def wait_until(fn, timeout=8.0):
    end = time.time() + timeout
    while time.time() < end:
        try:
            if fn():
                return True
        except Exception:
            pass
        time.sleep(0.1)
    return False


TAB_STYLE_JS = ("() => [...document.querySelectorAll('#files-views button')].map(b => { const c = getComputedStyle(b);"
                " return [b.id, b.getAttribute('aria-pressed'), c.borderTopColor, c.backgroundColor]; })")


def tab_styles(page):
    return page.evaluate(TAB_STYLE_JS)


def tab_style_ok(page):
    rows = tab_styles(page)
    clear = "rgba(0, 0, 0, 0)"
    return len(rows) == 3 and all((r[2] != clear) if r[1] == "true" else (r[2] == clear and r[3] == clear) for r in rows)


def tab_clip(page):
    box = page.locator("#files-views").bounding_box()
    return {"x": max(0, box["x"] - 8), "y": max(0, box["y"] - 8), "width": min(420, box["width"] + 16), "height": box["height"] + 16}


# 목록 줄의 ☆ data-fav-file 을 **한 번의 페이지 안 평가로** 본다(디버깅 묶음 T, 2026-09-26 기능 점검 — 10번에 3번 실패).
#   옛 식은 `.all()` 로 그 순간의 줄 n 개를 nth 로케이터로 잡고 하나씩 get_attribute 를 불렀다. 그 사이 목록이 적은 줄로 다시
#   그려지면(「파일」 보기 7줄 → 「즐겨찾기」 2줄) 사라진 nth 줄의 get_attribute 가 Playwright 기본 30초를 기다려 wait_until 8초 창을 넘겼다.
#   목록은 drawPlainList 가 한 JS 작업 안에서 줄을 다 바꾸고 쪽 글(「<보기> n건」)을 적으므로, 쪽 글과 줄을 한 평가에서 함께 보면
#   반쯤 그려진 목록·앞 보기의 줄을 읽지 않는다. 기다림은 wait_for_function 한 번(8초) — 줄 수가 바뀌어도 멈추지 않는다.
ROWS_JS = ("(a) => { const info = (document.getElementById('page-info') || {}).textContent || '';"
           " if (!info.startsWith(a.label + ' ')) return false;"
           " const ids = [...document.querySelectorAll('#files-body button[data-fav-file]')].map(b => b.getAttribute('data-fav-file'));"
           " return a.first ? ids[0] === a.id : ids.includes(a.id); }")


def wait_rows(page, label, fid, first=False, timeout_ms=8000):
    """「<label>」 보기가 그려졌고 그 목록에 fid 줄이 있다(first=True 면 첫 줄이 fid) — 될 때까지 기다린다"""
    if not fid:
        return False
    try:
        page.wait_for_function(ROWS_JS, arg={"label": label, "id": fid, "first": first}, timeout=timeout_ms, polling=100)
        return True
    except PlaywrightTimeout:
        return False


def wait_view(page, label):
    """보기를 바꾼 뒤 **그 보기의 목록이 그려질 때까지** — 쪽 글이 「<label> n건」이 되면(앞 보기의 줄이 남아 있는 동안 재지 않게)"""
    return wait_until(lambda: (page.locator("#page-info").text_content() or "").startswith(label + " "))


def no_overflow(page):
    return page.evaluate("() => document.documentElement.scrollWidth - window.innerWidth") <= 0


with sync_playwright() as p:
    browser = p.chromium.launch()

    # ═══ t-box ═══════════════════════════════════════════════════
    print("── 1. t-box 파일 화면 — ☆ · 「즐겨찾기」 보기")
    ctx, page = context(browser, E1)
    page.goto(TBOX + "/", wait_until="networkidle")
    page.wait_for_function("() => document.querySelectorAll('#files-body tr').length > 0", timeout=10000)
    step("1 보기 단추 셋(파일·즐겨찾기·최근) · 처음은 「파일」 눌림",
         page.locator("#files-views button").count() == 3 and page.get_attribute("#files-view-files", "aria-pressed") == "true")
    star = page.locator("#files-body tr").first.locator("button[data-fav-file]")
    fid = star.get_attribute("data-fav-file")
    fname = (page.locator("#files-body tr").first.locator("td").first.text_content() or "").strip()
    step("1 첫 줄 이름 칸 맨 앞에 ☆ 단추(aria-label · data-fav-file)", star.count() == 1 and bool(fid) and star.get_attribute("aria-label") in ("즐겨찾기에 넣기", "즐겨찾기에서 빼기"))
    if star.get_attribute("aria-pressed") == "true":   # 앞 시험이 넣어 둔 것이면 먼저 뺀다
        star.click()
        wait_until(lambda: star.get_attribute("aria-pressed") == "false")
    star.click()
    step("1 ☆ 누르면 찬 별(aria-pressed=true · 「빼기」)", wait_until(lambda: star.get_attribute("aria-pressed") == "true") and star.get_attribute("aria-label") == "즐겨찾기에서 빼기")
    api_ids = [f["id"] for f in page.request.get(TBOX + "/api/files/favorites").json().get("files", [])]
    step("1 창구 목록에도 그 파일", fid in api_ids)
    # Space 로도 누른다(키보드) — 둘째 줄
    star2 = page.locator("#files-body tr").nth(1).locator("button[data-fav-file]")
    fid2 = star2.get_attribute("data-fav-file")
    before2 = star2.get_attribute("aria-pressed")
    star2.focus()
    page.keyboard.press("Space")
    step("1 둘째 줄 ☆ 에 초점을 두고 Space → 눌림 바뀜", wait_until(lambda: star2.get_attribute("aria-pressed") != before2))
    page.locator("#files-view-favorites").click()
    step("1 「즐겨찾기」 보기 — 그 파일 줄이 나온다", wait_rows(page, "즐겨찾기", fid))
    step("1 「즐겨찾기」 눌림 · 「파일」 풀림", page.get_attribute("#files-view-favorites", "aria-pressed") == "true" and page.get_attribute("#files-view-files", "aria-pressed") == "false")
    step("1 검색 줄은 숨는다 · 「폴더」 칸이 보인다 · 쪽 넘기기 숨음",
         page.locator("#search-form").is_hidden() and page.locator("#files-folder-head").is_visible() and page.locator("#page-next").is_hidden())
    step("1 쪽 글 = 「즐겨찾기 n건」", (page.locator("#page-info").text_content() or "").startswith("즐겨찾기 "))
    page.mouse.move(5, 5)
    page.wait_for_timeout(400)   # 단추 색 전환(0.12s)이 끝난 뒤에 잰다·찍는다
    step("1 고르지 않은 보기 단추는 테두리·바탕 없음 · 고른 것은 테두리 있음(새 클래스 0 — .btn--plain)", tab_style_ok(page), tab_styles(page))
    page.screenshot(path=os.path.join(SHOTS, "tbox-favorites-light-1440.png"), full_page=True)
    page.screenshot(path=os.path.join(SHOTS, "tbox-tabs-light-zoom.png"), clip=tab_clip(page))

    print("── 2. 폴더 즐겨찾기")
    page.locator("#files-view-files").click()
    page.wait_for_timeout(300)
    folder_items = page.locator("#folder-list .foldertree__item:not(.foldertree__item--up)")
    step("2 폴더 트리에 폴더가 있다", folder_items.count() > 0, folder_items.count())
    fold_name = (folder_items.first.locator(".foldertree__name").text_content() or "").strip()
    folder_items.first.click()
    page.wait_for_timeout(500)
    step("2 폴더를 열면 「즐겨찾기에 넣기」 단추가 보인다", page.locator("#folder-fav").is_visible())
    if page.get_attribute("#folder-fav", "aria-pressed") == "true":
        page.locator("#folder-fav").click()
        wait_until(lambda: page.get_attribute("#folder-fav", "aria-pressed") == "false")
    page.locator("#folder-fav").click()
    step("2 누르면 눌림 · 글자 「즐겨찾기에서 빼기」",
         wait_until(lambda: page.get_attribute("#folder-fav", "aria-pressed") == "true") and (page.locator("#folder-fav-label").text_content() or "") == "즐겨찾기에서 빼기")
    page.locator("#files-view-favorites").click()
    step("2 「즐겨찾기」 보기에 폴더 묶음 · 그 폴더", wait_until(lambda: page.locator("#fav-folders-wrap").is_visible() and fold_name in (page.locator("#fav-folders").text_content() or "")))
    page.screenshot(path=os.path.join(SHOTS, "tbox-favorites-folders-light-1440.png"), full_page=True)
    page.locator("#fav-folders .foldertree__item").filter(has_text=fold_name).first.click()
    step("2 폴더 묶음의 폴더를 누르면 「파일」 보기로 그 폴더가 열린다",
         wait_until(lambda: page.get_attribute("#files-view-files", "aria-pressed") == "true" and fold_name in (page.locator("#folder-crumbs").text_content() or "")))

    print("── 3. 「최근」 보기 — 받기 뒤")
    rid = IDS.get("file_id", "")
    got = page.request.get(TBOX + "/api/files/%s/download" % rid) if rid else None
    step("3 준비된 파일 받기 200", got is not None and got.status == 200, got.status if got else None)
    page.locator("#files-view-recent").click()
    step("3 「최근」 보기 첫 줄 = 방금 받은 파일", wait_rows(page, "최근", rid, first=True))
    step("3 시각 칸 머리 「마지막으로 연 때」 · 쪽 글 「최근 n건」",
         (page.locator("#files-when-head").text_content() or "") == "마지막으로 연 때" and (page.locator("#page-info").text_content() or "").startswith("최근 "))
    page.screenshot(path=os.path.join(SHOTS, "tbox-recent-light-1440.png"), full_page=True)

    print("── 4. 「즐겨찾기」 보기에서 빼기 · 툴팁이 칸을 밀지 않는다")
    page.locator("#files-view-favorites").click()
    wait_view(page, "즐겨찾기")
    wait_until(lambda: page.locator("#files-body tr").count() > 0)
    page.wait_for_timeout(300)
    last_star = page.locator("#files-body tr").last.locator("button[data-fav-file]")
    dims = "() => { const w = document.querySelector('.table-wrap'); return [w.scrollHeight, w.scrollWidth, document.documentElement.scrollHeight, document.documentElement.scrollWidth]; }"
    d0 = page.evaluate(dims)
    last_star.hover()
    page.wait_for_timeout(250)
    d1 = page.evaluate(dims)
    step("4 표 마지막 줄 ☆ 에 올려도 표·문서 크기 그대로(DESIGN 2.8 ★)", d0 == d1, (d0, d1))
    page.mouse.move(5, 5)
    row = page.locator("#files-body tr").filter(has=page.locator("button[data-fav-file='%s']" % fid))
    row.locator("button[data-fav-file]").click()
    step("4 ☆ 를 다시 누르면 그 줄이 목록에서 사라진다", wait_until(lambda: page.locator("button[data-fav-file='%s']" % fid).count() == 0))
    ctx.close()

    print("── 5. t-box 다크 · 390px")
    # 다시 넣어 두고 찍는다(빈 목록이 아닌 모양)
    ctx, page = context(browser, E1, scheme="dark")
    page.goto(TBOX + "/", wait_until="networkidle")
    page.request.put(TBOX + "/api/files/%s/favorite" % fid, headers={"X-CSRF-Token": csrf(page)})
    page.locator("#files-view-favorites").click()
    wait_view(page, "즐겨찾기")
    page.mouse.move(5, 5)
    page.wait_for_timeout(400)
    step("5 다크 — 고르지 않은 보기 단추는 테두리·바탕 없음", tab_style_ok(page), tab_styles(page))
    page.screenshot(path=os.path.join(SHOTS, "tbox-favorites-dark-1440.png"), full_page=True)
    page.screenshot(path=os.path.join(SHOTS, "tbox-tabs-dark-zoom.png"), clip=tab_clip(page))
    ctx.close()
    for scheme in ("light", "dark"):
        ctx, page = context(browser, E1, scheme=scheme, width=390, height=844)
        page.goto(TBOX + "/", wait_until="networkidle")
        page.locator("#files-view-favorites").click()
        wait_view(page, "즐겨찾기")
        views_y = page.evaluate("() => [...document.querySelectorAll('#files-views button')].map(b => Math.round(b.getBoundingClientRect().top))")
        step("5 390px %s — 가로 넘침 0 · 보기 단추 셋이 한 줄" % scheme, no_overflow(page) and len(set(views_y)) == 1, (views_y,))
        page.screenshot(path=os.path.join(SHOTS, "tbox-favorites-%s-390.png" % scheme), full_page=True)
        ctx.close()

    # ═══ 커뮤니티 ════════════════════════════════════════════════
    print("── 6. 커뮤니티 페이지 — 「더보기」 → 즐겨찾기 ☆ · 사이드바")
    ctx, page = context(browser, E1)
    page.goto(COMMUNITY + "/", wait_until="networkidle")
    tok = csrf(page)
    page.request.get(COMMUNITY + "/api/me/space")
    made = []
    for n in (1, 2):
        r = page.request.post(COMMUNITY + "/api/pages", data=json.dumps({"title": "qf3a 화면 %s %d" % (TAG, n)}),
                              headers={"X-CSRF-Token": tok, "Content-Type": "application/json"})
        made.append((r.json().get("page") or {}).get("id", ""))
    step("6 페이지 둘 만들기", all(made), made)
    P1, P2 = made
    page.goto(COMMUNITY + "/p/" + P1, wait_until="networkidle")
    page.wait_for_function("() => (document.getElementById('page-title').textContent || '').indexOf('qf3a 화면') >= 0", timeout=10000)
    page.locator("#page-more > summary").click()
    step("6 「더보기」 맨 위 항목 = 「즐겨찾기에 넣기」(☆)",
         page.locator("#page-fav").is_visible() and (page.locator("#page-fav-label").text_content() or "") == "즐겨찾기에 넣기"
         and page.locator("#page-more .menu__item:not([hidden])").first.get_attribute("id") == "page-fav")
    page.screenshot(path=os.path.join(SHOTS, "community-menu-star-light-1440.png"))
    page.locator("#page-fav").click()
    step("6 사이드바 「즐겨찾기」에 그 페이지", wait_until(lambda: page.locator("#nav-community-fav-box").is_visible() and page.locator("#nav-community-fav a[href$='/p/%s']" % P1).count() == 1))
    step("6 메뉴 항목이 「즐겨찾기에서 빼기」(aria-pressed=true)",
         wait_until(lambda: page.get_attribute("#page-fav", "aria-pressed") == "true" and (page.locator("#page-fav-label").text_content() or "") == "즐겨찾기에서 빼기"))
    step("6 사이드바 「최근」에 그 페이지(열었으니)", wait_until(lambda: page.locator("#nav-community-recent a[href$='/p/%s']" % P1).count() == 1))
    order_side = page.evaluate("() => [...document.querySelectorAll('.sidebar .nav-title')].map(e => e.textContent.trim()).filter(t => t)")
    # Q:F-12 C (DESIGN 2.19 — 「즐겨찾기 · 최근 묶음은 「프로젝트」 위에 그대로」) — 「내 공간」 묶음은 「문서」 로 바뀌었다(그 사이 프로젝트 · 포트폴리오 · 팀)
    step("6 사이드바 순서 즐겨찾기 → 최근 → … → 문서(결정 M-1 · DESIGN 2.19)", order_side[:2] == ["즐겨찾기", "최근"] and "문서" in order_side[2:], order_side[:8])

    print("── 7. 순서 바꾸기(Alt+↓) · 접기")
    page.request.put(COMMUNITY + "/api/pages/%s/favorite" % P2, headers={"X-CSRF-Token": tok})
    page.reload(wait_until="networkidle")
    wait_until(lambda: page.locator("#nav-community-fav li[data-fav-id='%s']" % P2).count() == 1)
    ids = page.evaluate("() => [...document.querySelectorAll('#nav-community-fav li[data-fav-id]')].map(li => li.getAttribute('data-fav-id'))")
    i1 = ids.index(P1) if P1 in ids else -1
    step("7 즐겨찾기에 둘 다 · P1 이 P2 보다 앞(넣은 순)", P1 in ids and P2 in ids and ids.index(P1) < ids.index(P2), ids)
    page.locator("#nav-community-fav li[data-fav-id='%s'] a" % P1).focus()
    page.keyboard.press("Alt+ArrowDown")
    ids2 = page.evaluate("() => [...document.querySelectorAll('#nav-community-fav li[data-fav-id]')].map(li => li.getAttribute('data-fav-id'))")
    step("7 Alt+↓ → P1 이 한 칸 아래로 · 초점은 그 링크에", ids2.index(P1) == i1 + 1 and page.evaluate("() => document.activeElement && document.activeElement.getAttribute('href')").endswith(P1), (ids, ids2))
    page.wait_for_timeout(600)
    page.reload(wait_until="networkidle")
    wait_until(lambda: page.locator("#nav-community-fav li[data-fav-id]").count() >= 2)
    ids3 = page.evaluate("() => [...document.querySelectorAll('#nav-community-fav li[data-fav-id]')].map(li => li.getAttribute('data-fav-id'))")
    step("7 새로 고침 뒤에도 그 순서(창구에 저장)", ids3 == ids2, (ids2, ids3))
    page.locator("#nav-community-fav-box > summary").click()
    step("7 「즐겨찾기」 제목을 누르면 접힌다", not page.evaluate("() => document.getElementById('nav-community-fav-box').open"))
    page.reload(wait_until="networkidle")
    step("7 새로 고침 뒤에도 접힘(이 브라우저에 기억)", wait_until(lambda: page.locator("#nav-community-fav-box").is_visible()) and not page.evaluate("() => document.getElementById('nav-community-fav-box').open"))
    page.locator("#nav-community-fav-box > summary").click()
    step("7 다시 누르면 펼친다", page.evaluate("() => document.getElementById('nav-community-fav-box').open"))
    page.screenshot(path=os.path.join(SHOTS, "community-page-light-1440.png"))

    print("── 8. 「최근」 더 보기 창 · 지우기")
    page.locator("#nav-community-recent-more").click()
    step("8 창이 열린다 · 목록에 P1", wait_until(lambda: page.locator("#recent-dialog[open]").count() == 1 and page.locator("#recent-list a[href$='/p/%s']" % P1).count() == 1))
    page.screenshot(path=os.path.join(SHOTS, "community-recent-dialog-light-1440.png"))
    page.locator("#recent-clear").click()
    step("8 한 번 누르면 「정말 지우기」(아직 요청 없음 — 목록 그대로)", (page.locator("#recent-clear").text_content() or "") == "정말 지우기" and page.locator("#recent-list li").count() > 0)
    page.locator("#recent-clear").click()
    step("8 두 번째에 비워진다 · 「최근 본 페이지가 없습니다」", wait_until(lambda: page.locator("#recent-list li").count() == 0 and page.locator("#recent-empty").is_visible()))
    step("8 사이드바 「최근」 묶음도 숨는다", wait_until(lambda: page.locator("#nav-community-recent").is_hidden()))
    page.keyboard.press("Escape")
    step("8 Esc 로 닫힌다", wait_until(lambda: page.locator("#recent-dialog[open]").count() == 0))
    ctx.close()

    print("── 9. 커뮤니티 다크 · 390px(사이드바 서랍)")
    ctx, page = context(browser, E1, scheme="dark")
    page.goto(COMMUNITY + "/p/" + P1, wait_until="networkidle")
    wait_until(lambda: page.locator("#nav-community-fav-box").is_visible() and page.locator("#nav-community-recent").is_visible())
    page.screenshot(path=os.path.join(SHOTS, "community-page-dark-1440.png"))
    ctx.close()
    for scheme in ("light", "dark"):
        ctx, page = context(browser, E1, scheme=scheme, width=390, height=844)
        page.goto(COMMUNITY + "/p/" + P1, wait_until="networkidle")
        page.locator("#nav-toggle").click()
        vis = wait_until(lambda: page.locator("#nav-community-fav-box").is_visible() and page.locator("#nav-community-recent").is_visible())
        step("9 390px %s — 서랍에 「즐겨찾기」「최근」 · 가로 넘침 0" % scheme, vis and no_overflow(page))
        page.screenshot(path=os.path.join(SHOTS, "community-drawer-%s-390.png" % scheme))
        ctx.close()

    print("── 10. 빼기 → 사이드바에서 사라짐")
    ctx, page = context(browser, E1)
    page.goto(COMMUNITY + "/p/" + P1, wait_until="networkidle")
    wait_until(lambda: page.get_attribute("#page-fav", "aria-pressed") == "true")
    page.locator("#page-more > summary").click()
    page.locator("#page-fav").click()
    step("10 「즐겨찾기에서 빼기」 → 사이드바에서 P1 없음 · 메뉴 글자 「넣기」",
         wait_until(lambda: page.locator("#nav-community-fav a[href$='/p/%s']" % P1).count() == 0 and (page.locator("#page-fav-label").text_content() or "") == "즐겨찾기에 넣기"))
    ctx.close()

    print("── 11. 좁은 폭 셋(DESIGN 6장 — 360 · 412 터치·마우스 · 820)")
    for width, touch in ((360, False), (412, True), (412, False), (820, False)):
        tag = "%d%s" % (width, "-touch" if touch else "")
        ctx, page = context(browser, E1, width=width, height=844, touch=touch)
        page.goto(TBOX + "/", wait_until="networkidle")
        page.locator("#files-view-favorites").click()
        wait_view(page, "즐겨찾기")
        ys = page.evaluate("() => [...document.querySelectorAll('#files-views button')].map(b => Math.round(b.getBoundingClientRect().top))")
        step("11 t-box %s — 보기 단추 한 줄 · 가로 넘침 0" % tag, len(set(ys)) == 1 and no_overflow(page), ys)
        page.screenshot(path=os.path.join(SHOTS, "tbox-favorites-light-%s.png" % tag), full_page=True)
        page.goto(COMMUNITY + "/p/" + P2, wait_until="networkidle")
        page.locator("#nav-toggle").click()
        ok_side = wait_until(lambda: page.locator("#nav-community-fav-box").is_visible())
        step("11 커뮤니티 %s — 서랍에 「즐겨찾기」 · 가로 넘침 0" % tag, ok_side and no_overflow(page))
        page.screenshot(path=os.path.join(SHOTS, "community-drawer-light-%s.png" % tag))
        ctx.close()

    browser.close()

step("오류 0 — pageerror", len(pageerrors) == 0, pageerrors[:3])
step("오류 0 — console.error", len(console_errors) == 0, console_errors[:3])
step("오류 0 — 요청 실패", len(req_failed) == 0, req_failed[:3])
step("오류 0 — HTTP≥400", len(http_bad) == 0, http_bad[:5])
print("SMOKE_FAIL=%d" % fails)
sys.exit(1 if fails else 0)
