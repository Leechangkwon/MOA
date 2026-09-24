#!/usr/bin/env python3
# tests/browser/qf4_round_a.py — Q:F-4 라운드 A 의 P-4a(숨은 탭 폴링·heartbeat) · P-5a(/api/me 한 번) 헤드리스 시험
#
# 부르는 쪽: tests/qf4a.sh (임시 인스턴스 · 임시 DB · 포트 8092). 운영 8082 에는 요청하지 않는다.
# 쓰는 법:
#   measure — 고치기 전 코드(HEAD)에서도 같은 조작으로 **숫자만** 잰다(전후 측정표의 「전」). 판정 없음.
#     SMOKE_E1=… TEST_PAGE=… BOARD=… [BASE_FILE=… BASE_FILE_NAME=…] python3 qf4_round_a.py <SITE> <UA> measure
#   check   — 지금 코드의 수락 기준을 판정한다(숫자도 같은 이름으로 찍는다 — 「후」).
#     SMOKE_E1=… SMOKE_A1=… ADMIN_PW=… TEST_PAGE=… BOARD=… BOARD_NAME=… SHOTS=… python3 qf4_round_a.py <SITE> <UA> check
#
# 숨김 흉내: `document.visibilityState`·`document.hidden` 을 그 문서에서만 덮어쓰고 `visibilitychange` 를 쏜다.
# 5분 기다리기: playwright `page.clock`(1.45+) — 설치한 뒤 `run_for(300000)` 으로 가짜 시계를 5분 돌린다
#   (그 사이의 setInterval 이 전부 불린다). **제품 코드에 시험용 분기는 없다** — 주기 상수도 그대로다.
# 비밀: 관리자 비밀번호(재인증 창에 넣는다)는 환경 변수 ADMIN_PW 로만 받는다 — 명령 인자·출력에 싣지 않는다.
#
# 완료 기준
#   P-4a ① 숨김 5분 → 알림·heartbeat 요청 0 ② 다시 보임 → 1초 안에 알림 1 + heartbeat 1 (그 뒤 30초 주기로 돌아감)
#        ③ 숨김 순간의 편집 저장 flush 는 지금대로(PATCH 1건, 내용이 서버에 있다) ④ 「편집 중」 만료는 기존 규칙(보고서)
#   P-5a ① t-box 화면·커뮤니티 홈·페이지·게시판 첫 로드에 GET /tbox/api/me 정확히 1건
#        ② 재인증 창 뒤 CSRF 가 맞아 다음 쓰기 성공(403 → 재인증 → 같은 토큰으로 다시 보낸 POST 201)
#        ③ 로그아웃 뒤 캐시가 남지 않음 — 새 화면에서 /api/me 가 새로 나가 401, 커뮤니티 주소는 로그인으로
# 나가는 값: 0 통과 · 1 실패 (마지막 줄 `SMOKE_FAIL=<개수>`)
import os
import re
import sys
from urllib.parse import urlsplit, parse_qs

from playwright.sync_api import sync_playwright

SITE = sys.argv[1].rstrip("/")
UA = sys.argv[2]
MODE = sys.argv[3] if len(sys.argv) > 3 else "check"
CHECK = MODE == "check"
E1 = os.environ["SMOKE_E1"]
A1 = os.environ.get("SMOKE_A1", "")
TEST_PAGE = os.environ.get("TEST_PAGE", "")
BOARD = os.environ.get("BOARD", "")
BOARD_NAME = os.environ.get("BOARD_NAME", "QF4A 시험 게시판")
BASE_FILE = os.environ.get("BASE_FILE", "")
BASE_FILE_NAME = os.environ.get("BASE_FILE_NAME", "")
SHOTS = os.environ.get("SHOTS", "")
HOST = re.match(r"https?://([^/:]+)", SITE).group(1)
TBOX = SITE + "/tbox"
COMMUNITY = SITE + "/community"

fails = 0


def step(name, ok, detail=""):
    """check 모드에서만 판정한다. measure 모드는 관찰만 찍는다."""
    global fails
    if not CHECK:
        print("  · %s%s" % (name, ("  — " + detail) if detail else ""))
        return
    if not ok:
        fails += 1
    print("  %s %s%s" % ("✔" if ok else "✖", name, ("  — " + detail) if detail else ""))


def skip(name, why):
    print("  ○ SKIP %s  (%s)" % (name, why))


def measure(key, value):
    print("MEASURE %s=%s" % (key, value))


def new_context(browser, session, theme="light", width=1440, height=900):
    ctx = browser.new_context(user_agent=UA, viewport={"width": width, "height": height})
    ctx.add_cookies([
        {"name": "tbox_session", "value": session, "domain": HOST, "path": "/", "httpOnly": True, "secure": False, "sameSite": "Lax"},
        {"name": "tbox_theme", "value": theme, "domain": HOST, "path": "/", "secure": False, "sameSite": "Lax"},
    ])
    return ctx


def fresh_seen():
    return {"pageerrors": [], "console": [], "reqs": [], "http_bad": [], "native_dialogs": []}


def watch(page, seen, expected_bad=()):
    page.on("pageerror", lambda e: seen["pageerrors"].append(str(e)))
    page.on("console", lambda m: seen["console"].append(m.text) if m.type == "error" and "Failed to load resource" not in m.text else None)

    def on_request(r):
        if HOST not in r.url:
            return
        parts = urlsplit(r.url)
        seen["reqs"].append({"m": r.method, "path": parts.path, "q": parts.query,
                             "csrf": r.headers.get("x-csrf-token", "")})
    page.on("request", on_request)

    def on_response(r):
        if r.status >= 400 and HOST in r.url:
            path = urlsplit(r.url).path
            key = "%d %s %s" % (r.status, r.request.method, path)
            if any(key.startswith(e) for e in expected_bad):
                seen.setdefault("expected", []).append(key)
            else:
                seen["http_bad"].append(key)
    page.on("response", on_response)

    def on_dialog(d):
        seen["native_dialogs"].append(d.type)
        d.dismiss()
    page.on("dialog", on_dialog)


def count_me(reqs):
    return len([r for r in reqs if r["m"] == "GET" and r["path"] == "/tbox/api/me"])


def count_poll(reqs):
    return len([r for r in reqs if r["m"] == "GET" and r["path"] == "/community/api/notifications" and "unread=1" in r["q"]])


def count_beat(reqs):
    return len([r for r in reqs if r["m"] == "POST" and r["path"] == "/community/api/pages/%s/heartbeat" % TEST_PAGE])


def count_save(reqs):
    return len([r for r in reqs if r["m"] == "PATCH" and r["path"] == "/community/api/pages/%s" % TEST_PAGE])


def set_visibility(page, state):
    page.evaluate("""(state) => {
      Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => state });
      Object.defineProperty(document, 'hidden', { configurable: true, get: () => state === 'hidden' });
      document.dispatchEvent(new Event('visibilitychange'));
    }""", state)


def common_clean(label, seen):
    step("%s — pageerror·console.error·HTTP≥400 0" % label,
         not seen["pageerrors"] and not seen["console"] and not seen["http_bad"],
         "%s %s %s" % (seen["pageerrors"][:2], seen["console"][:2], seen["http_bad"][:3]))


with sync_playwright() as p:
    browser = p.chromium.launch()

    # ─── P-5a ① 첫 로드에 /api/me 몇 건 ───────────────────────────
    print("── P-5a ① 화면 첫 로드의 GET /tbox/api/me 건수 (claude_employee01)")
    screens = [("tbox_files", TBOX + "/", "t-box 파일 화면"),
               ("community_home", COMMUNITY + "/", "커뮤니티 홈")]
    if TEST_PAGE:
        screens.append(("community_page", COMMUNITY + "/p/" + TEST_PAGE, "커뮤니티 페이지"))
    if BOARD:
        screens.append(("community_board", COMMUNITY + "/b/" + BOARD, "게시판"))
    else:
        skip("P-5a ① 게시판", "사내 게시판이 임시 DB 에 없다")
    for key, url, label in screens:
        seen = fresh_seen()
        ctx = new_context(browser, E1)
        page = ctx.new_page()
        watch(page, seen)
        page.goto(url, wait_until="networkidle")
        page.wait_for_timeout(1000)
        n = count_me(seen["reqs"])
        measure("p5a_api_me_%s" % key, n)
        step("P-5a ① %s 첫 로드 — /api/me 정확히 1건" % label, n == 1, "%d건" % n)
        if CHECK:
            common_clean("P-5a ① " + label, seen)
        ctx.close()

    # ─── U-2a 「전」 측정 — 휴지통 단추를 누르자마자 나가는 요청 ────────────
    if not CHECK and BASE_FILE:
        print("── (전) U-2a 휴지통 단추를 누른 직후 요청")
        seen = fresh_seen()
        ctx = new_context(browser, E1)
        page = ctx.new_page()
        watch(page, seen)
        page.goto(TBOX + "/", wait_until="networkidle")
        page.wait_for_timeout(400)
        row = page.locator("#files-body tr", has_text=BASE_FILE_NAME)
        if row.count() == 1:
            mark = len(seen["reqs"])
            row.locator('button[aria-label="삭제"]').click()
            page.wait_for_timeout(1500)
            after = seen["reqs"][mark:]
            measure("u2a_requests_after_trash_click", len([r for r in after if r["m"] == "DELETE"]))
            print("  · 휴지통 단추 직후 요청: %s" % [(r["m"], r["path"]) for r in after])
        else:
            print("  · 「전」 측정용 파일 행을 찾지 못했다")
        folder_name = os.environ.get("BASE_FOLDER_NAME", "")
        if folder_name:
            page.goto(TBOX + "/", wait_until="networkidle")
            page.wait_for_timeout(400)
            page.locator("#folder-list .foldertree__item", has_text=folder_name).first.click()
            page.wait_for_load_state("networkidle")
            page.wait_for_timeout(400)
            n0 = len(seen["native_dialogs"])
            mark = len(seen["reqs"])
            page.locator("#folder-delete").click()
            page.wait_for_timeout(800)
            measure("u2a_window_confirm_on_folder_delete", len(seen["native_dialogs"]) - n0)
            print("  · 폴더 「삭제」 직후 브라우저 기본 대화상자 %d개(닫기로 답함) · 요청 %s"
                  % (len(seen["native_dialogs"]) - n0, [(r["m"], r["path"]) for r in seen["reqs"][mark:]]))
        ctx.close()

    # ─── P-4a 숨은 탭 ───────────────────────────────────────────
    print("── P-4a 숨은 탭 — 알림 폴링·편집 heartbeat (가짜 시계로 5분)")
    if not TEST_PAGE:
        skip("P-4a", "시험 페이지가 없다")
    else:
        seen = fresh_seen()
        ctx = new_context(browser, E1)
        page = ctx.new_page()
        watch(page, seen)
        page.clock.install()
        page.goto(COMMUNITY + "/p/" + TEST_PAGE, wait_until="networkidle")
        page.wait_for_timeout(800)
        step("페이지를 열자 알림 폴링 1건(보이는 탭)", count_poll(seen["reqs"]) == 1, "%d건" % count_poll(seen["reqs"]))
        page.locator("#page-edit").click()
        page.wait_for_selector(".editor", state="visible")
        page.wait_for_timeout(800)
        step("편집을 시작하자 heartbeat 1건", count_beat(seen["reqs"]) == 1, "%d건" % count_beat(seen["reqs"]))

        # ③ 저장 flush — 친 뒤 곧바로(1.5초 멈춤 전에) 숨긴다
        typed = "QF4A 숨김 저장 확인"
        page.locator(".editor").click()
        page.keyboard.type(typed)
        mark = len(seen["reqs"])
        set_visibility(page, "hidden")
        page.wait_for_timeout(1500)
        saves = count_save(seen["reqs"][mark:])
        measure("p4a_flush_save_on_hide", saves)
        step("P-4a ③ 숨기는 순간 남은 편집을 보낸다(PATCH 1건 — 지금대로)", saves == 1, "%d건" % saves)

        mark = len(seen["reqs"])
        page.clock.run_for(300000)
        page.wait_for_timeout(800)
        hidden = seen["reqs"][mark:]
        measure("p4a_hidden_5min_notification_polls", count_poll(hidden))
        measure("p4a_hidden_5min_heartbeats", count_beat(hidden))
        step("P-4a ① 숨김 5분 — 알림 폴링 0건", count_poll(hidden) == 0, "%d건" % count_poll(hidden))
        step("P-4a ① 숨김 5분 — heartbeat 0건", count_beat(hidden) == 0, "%d건" % count_beat(hidden))

        mark = len(seen["reqs"])
        set_visibility(page, "visible")
        page.wait_for_timeout(1000)
        back = seen["reqs"][mark:]
        measure("p4a_visible_within_1s_polls", count_poll(back))
        measure("p4a_visible_within_1s_heartbeats", count_beat(back))
        step("P-4a ② 다시 보임 — 1초 안에 알림 1건", count_poll(back) == 1, "%d건" % count_poll(back))
        step("P-4a ② 다시 보임 — 1초 안에 heartbeat 1건", count_beat(back) == 1, "%d건" % count_beat(back))

        mark = len(seen["reqs"])
        page.clock.run_for(30000)
        page.wait_for_timeout(800)
        again = seen["reqs"][mark:]
        step("P-4a ② 그 뒤 원래 주기(30초)로 — 알림 1 · heartbeat 1", count_poll(again) == 1 and count_beat(again) == 1,
             "알림 %d · heartbeat %d" % (count_poll(again), count_beat(again)))

        if CHECK:
            r = ctx.request.get(COMMUNITY + "/api/pages/" + TEST_PAGE, headers={"User-Agent": UA})
            body = r.text()
            step("P-4a ③ 숨길 때 보낸 내용이 서버에 있다(GET 본문에 친 글자)", r.status == 200 and typed in body, "HTTP %d" % r.status)
            common_clean("P-4a", seen)
            # 편집을 끝내 둔다(남은 저장 flush)
            page.locator("#page-done").click()
            page.wait_for_timeout(800)
        ctx.close()

    if not CHECK:
        browser.close()
        print("SMOKE_FAIL=0")
        sys.exit(0)

    # ─── P-5a ② 재인증 창 뒤 다음 쓰기 ─────────────────────────────
    print("── P-5a ② 재인증 창 뒤 CSRF 가 맞아 다음 쓰기가 성공한다 (claude_admin01 · 게시판 관리)")
    admin_pw = os.environ.get("ADMIN_PW", "")
    if not (A1 and BOARD and admin_pw):
        skip("P-5a ②", "관리자 세션·게시판·비밀번호 중 없는 것이 있다")
    else:
        seen = fresh_seen()
        ctx = new_context(browser, A1)
        page = ctx.new_page()
        watch(page, seen, expected_bad=("403 POST /community/api/boards",))
        page.goto(COMMUNITY + "/b/" + BOARD, wait_until="networkidle")
        page.wait_for_timeout(600)
        step("관리자 게시판 첫 로드 — /api/me 1건", count_me(seen["reqs"]) == 1, "%d건" % count_me(seen["reqs"]))
        page.locator("#board-manage").click()
        page.wait_for_selector("#board-manage-dialog[open]")
        page.fill("#board-manage-name", BOARD_NAME)
        page.select_option("#board-manage-level", "admin-only")
        mark = len(seen["reqs"])
        page.locator("#board-manage-save").click()
        page.wait_for_timeout(1000)
        reauth = page.locator("dialog.modal--form[open]", has_text="비밀번호 다시 확인")
        step("403(재인증 필요) → 셸의 같은 재인증 창이 떴다", reauth.count() == 1)
        reauth.locator('input[type="password"]').fill(admin_pw)
        admin_pw = ""
        with page.expect_response(lambda r: r.request.method == "POST" and r.url.endswith("/community/api/boards")
                                  and r.status != 403, timeout=10000) as resp_info:
            reauth.locator('button[type="submit"]').click()
        resp = resp_info.value
        page.wait_for_timeout(800)
        posts = [r for r in seen["reqs"][mark:] if r["m"] == "POST" and r["path"] == "/community/api/boards"]
        reauths = [r for r in seen["reqs"][mark:] if r["m"] == "POST" and r["path"] == "/tbox/api/me/reauth"]
        step("재인증 POST /tbox/api/me/reauth 1건", len(reauths) == 1, "%d건" % len(reauths))
        step("다시 보낸 쓰기 POST /community/api/boards → %d(성공)" % resp.status, resp.status in (200, 201) and len(posts) == 2,
             "POST %d건 · 마지막 %d" % (len(posts), resp.status))
        step("두 번의 쓰기가 같은 CSRF 토큰(나눠 쓴 /api/me 의 것)을 실었다",
             len(posts) == 2 and posts[0]["csrf"] != "" and posts[0]["csrf"] == posts[1]["csrf"])
        step("재인증 뒤에도 /api/me 는 처음 1건 그대로(토큰이 세션에 묶여 바뀌지 않는다)", count_me(seen["reqs"]) == 1,
             "%d건" % count_me(seen["reqs"]))
        # 재인증 뒤 버린 캐시 — 다음에 묻는 쪽은 새로 받는다
        n_before = count_me(seen["reqs"])
        fresh = page.evaluate("() => window.qarediMe().then(r => ({status: r.status, fresh: r.me && r.me.reauth_fresh === true}))")
        page.wait_for_timeout(300)
        step("재인증 뒤 qarediMe() 는 새로 묻는다(/api/me +1 · reauth_fresh=true)",
             count_me(seen["reqs"]) == n_before + 1 and fresh.get("status") == 200 and fresh.get("fresh") is True, str(fresh))
        common_clean("P-5a ②", seen)

        # ─── P-5a ③ 로그아웃 뒤 캐시가 남지 않는다 (관리자 세션을 끝낸다 — 이 시험의 맨 끝) ─────
        print("── P-5a ③ 로그아웃 뒤 캐시가 남지 않는다")
        page.goto(COMMUNITY + "/", wait_until="networkidle")
        page.wait_for_timeout(600)
        n0 = count_me(seen["reqs"])
        cached = page.evaluate("() => window.qarediMe().then(r => r.status)")
        page.wait_for_timeout(300)
        step("로그아웃 전 — 같은 화면의 qarediMe() 는 나눠 쓴 답(요청 +0)", cached == 200 and count_me(seen["reqs"]) == n0,
             "status %s · /api/me %d→%d" % (cached, n0, count_me(seen["reqs"])))
        page.locator("summary.brand__switch").first.click()
        page.wait_for_timeout(300)
        with page.expect_navigation(timeout=10000):
            page.locator("#logout").click()
        page.wait_for_load_state("networkidle")
        step("로그아웃 → 로그인 화면", "/tbox/login" in page.url, page.url)
        n1 = count_me(seen["reqs"])
        after = page.evaluate("() => (typeof window.qarediMe === 'function') ? window.qarediMe().then(r => r.status) : -1")
        page.wait_for_timeout(300)
        step("로그아웃 뒤 새 화면 — qarediMe() 가 새로 물어 401(남은 캐시 0)", after == 401 and count_me(seen["reqs"]) == n1 + 1,
             "status %s · /api/me %d→%d" % (after, n1, count_me(seen["reqs"])))
        page.goto(COMMUNITY + "/", wait_until="networkidle")
        step("로그아웃 뒤 커뮤니티 주소 → 로그인 화면(401 흐름 그대로)", "/tbox/login" in page.url, page.url)
        # 로그아웃 뒤의 401 은 기대한 것이다 — 그 밖의 400 이상만 본다
        bad = [b for b in seen["http_bad"] if not b.startswith("401 GET /tbox/api/me")]
        step("P-5a ③ — pageerror·console.error 0 · HTTP≥400 은 로그아웃 뒤 /api/me 401 말고 0",
             not seen["pageerrors"] and not [c for c in seen["console"] if "401" not in c] and not bad,
             "%s %s %s" % (seen["pageerrors"][:2], seen["console"][:2], bad[:3]))
        ctx.close()

    browser.close()

print("SMOKE_FAIL=%d" % fails)
sys.exit(1 if fails else 0)
