#!/usr/bin/env python3
# tests/browser/qf4_delete_confirm.py — Q:F-4 U-2a 「t-box 파일 삭제 전 확인 + 결과 알림」 헤드리스 수락 시험
#
# 부르는 쪽: tests/qf4a.sh (임시 인스턴스 · 임시 DB · 포트 8092). 운영 8082 에는 요청하지 않는다.
# 쓰는 법:   SMOKE_E1=<claude_employee01 세션> SMOKE_CSRF=<그 CSRF> FILE_ID=… FILE_NAME=… GONE_ID=… GONE_NAME=… \
#            FOLDER_ID=… FOLDER_NAME=… SHOTS=<스크린샷 폴더> python3 qf4_delete_confirm.py <SITE> <UA>
#
# 완료 기준(지시서 U-2a)
#   ① 삭제 단추 → 확인 창이 뜨고 요청 0건(네트워크 기록)
#   ② 「닫기」·Esc(·✕) → 요청 0, 목록 그대로
#   ③ 「휴지통으로 옮기기」 → DELETE 1건 · 성공 알림(.ok-note) · 행 사라짐
#   ④ 서버 거부(여기서는 404 — 이미 지워진 파일)는 서버 문구 그대로(#msg)
#   ⑤ 폴더 삭제도 같은 창(window.confirm 0)
#   ⑥ 390px 에서 창 단추가 잘리지 않음 · 라이트·다크·390px 스크린샷
# 공통: pageerror 0 · console.error 0 · HTTP ≥ 400 은 ④ 의 의도한 404 말고 0 · 브라우저 기본 대화상자(confirm/alert) 0
# 나가는 값: 0 통과 · 1 실패 (마지막 줄 `SMOKE_FAIL=<개수>`)
import os
import re
import sys
from urllib.parse import urlsplit

from playwright.sync_api import sync_playwright

SITE = sys.argv[1].rstrip("/")
UA = sys.argv[2]
E1 = os.environ["SMOKE_E1"]
CSRF = os.environ["SMOKE_CSRF"]
FILE_ID = os.environ["FILE_ID"]
FILE_NAME = os.environ["FILE_NAME"]
GONE_ID = os.environ["GONE_ID"]
GONE_NAME = os.environ["GONE_NAME"]
FOLDER_ID = os.environ["FOLDER_ID"]
FOLDER_NAME = os.environ["FOLDER_NAME"]
SHOTS = os.environ.get("SHOTS", ".")
HOST = re.match(r"https?://([^/:]+)", SITE).group(1)
TBOX = SITE + "/tbox"

fails = 0


def step(name, ok, detail=""):
    global fails
    if not ok:
        fails += 1
    print("  %s %s%s" % ("✔" if ok else "✖", name, ("  — " + detail) if detail else ""))


def measure(key, value):
    print("MEASURE %s=%s" % (key, value))


def new_context(browser, theme="light", width=1440, height=900):
    ctx = browser.new_context(user_agent=UA, viewport={"width": width, "height": height})
    ctx.add_cookies([
        {"name": "tbox_session", "value": E1, "domain": HOST, "path": "/", "httpOnly": True, "secure": False, "sameSite": "Lax"},
        {"name": "tbox_theme", "value": theme, "domain": HOST, "path": "/", "secure": False, "sameSite": "Lax"},
    ])
    return ctx


def watch(page, seen):
    page.on("pageerror", lambda e: seen["pageerrors"].append(str(e)))
    page.on("console", lambda m: seen["console"].append(m.text) if m.type == "error" and "Failed to load resource" not in m.text else None)
    page.on("request", lambda r: seen["requests"].append((r.method, urlsplit(r.url).path)) if HOST in r.url else None)

    def on_response(r):
        if r.status >= 400 and HOST in r.url:
            if GONE_ID in r.url:
                seen["expected_bad"].append("%d %s" % (r.status, urlsplit(r.url).path))
            else:
                seen["http_bad"].append("%d %s" % (r.status, urlsplit(r.url).path))
    page.on("response", on_response)

    def on_dialog(d):
        seen["native_dialogs"].append(d.type + ":" + d.message[:80])
        d.dismiss()
    page.on("dialog", on_dialog)


def fresh_seen():
    return {"pageerrors": [], "console": [], "requests": [], "http_bad": [], "expected_bad": [], "native_dialogs": []}


def dialog_open(page):
    return page.locator("#confirm-dialog[open]").count() == 1


def file_row(page, name):
    return page.locator("#files-body tr", has_text=name)


def open_file_confirm(page, name):
    file_row(page, name).locator('button[aria-label="삭제"]').click()
    page.wait_for_timeout(400)


def modal_geometry(page):
    return page.evaluate("""() => {
      const d = document.querySelector('#confirm-dialog');
      const r = d.getBoundingClientRect();
      const btns = [...d.querySelectorAll('.modal__foot button')].map(b => {
        const q = b.getBoundingClientRect();
        return {text: b.textContent.trim(), left: q.left, right: q.right, top: q.top, bottom: q.bottom, w: q.width};
      });
      return {vw: innerWidth, vh: innerHeight, left: r.left, right: r.right, top: r.top, bottom: r.bottom,
              dlgScrollW: d.scrollWidth, dlgClientW: d.clientWidth,
              docScrollW: document.documentElement.scrollWidth, btns: btns};
    }""")


with sync_playwright() as p:
    browser = p.chromium.launch()

    # ─── 1440px 라이트 — ①② ─────────────────────────────────────
    print("── ①② 삭제 단추 → 확인 창 · 요청 0 · 닫기/Esc/✕ 도 요청 0 (1440px 라이트)")
    seen = fresh_seen()
    ctx = new_context(browser, "light")
    page = ctx.new_page()
    watch(page, seen)
    page.goto(TBOX + "/", wait_until="networkidle")
    page.wait_for_timeout(500)
    step("시험 파일 행이 목록에 있다", file_row(page, FILE_NAME).count() == 1, FILE_NAME)

    mark = len(seen["requests"])
    open_file_confirm(page, FILE_NAME)
    after_click = seen["requests"][mark:]
    measure("u2a_requests_after_trash_click", len(after_click))
    step("① 휴지통 단추 → 확인 창이 떴다(#confirm-dialog[open])", dialog_open(page))
    step("① 창이 떠 있는 동안 요청 0건", len(after_click) == 0, str(after_click))
    title = page.locator("#confirm-dialog .modal__title").inner_text()
    body_text = page.locator("#confirm-dialog .modal__body").inner_text()
    ok_label = page.locator("#confirm-dialog .modal__foot .btn--danger").inner_text()
    step("① 창 문구 — 파일 이름 · 휴지통 · 30일", FILE_NAME in body_text and "휴지통으로 옮깁니다" in body_text and "30일" in body_text,
         "%s / %s" % (title, body_text.replace("\n", " ")[:120]))
    step("① 확인 단추는 빨간 글자 단추 「휴지통으로 옮기기」", ok_label.strip() == "휴지통으로 옮기기", ok_label)
    step("① 처음 초점은 「닫기」(Enter 로 지워지지 않게)",
         page.evaluate("() => document.activeElement && document.activeElement.textContent.trim()") == "닫기")
    # DESIGN 2.8 ★ — 모달 머리 ✕ 에 올려도 문서 스크롤 크기가 그대로
    before = page.evaluate("() => [document.documentElement.scrollHeight, document.documentElement.scrollWidth]")
    page.hover('#confirm-dialog .modal__head button[aria-label="닫기"]')
    page.wait_for_timeout(200)
    after = page.evaluate("() => [document.documentElement.scrollHeight, document.documentElement.scrollWidth]")
    step("✕ 에 올려도 문서 scrollHeight·scrollWidth 그대로(툴팁이 칸을 밀지 않음)", before == after, "%s → %s" % (before, after))
    page.screenshot(path=os.path.join(SHOTS, "qf4a-u2a-confirm-light-1440.png"))

    mark = len(seen["requests"])
    page.locator("#confirm-dialog .modal__foot .btn--quiet").click()
    page.wait_for_timeout(400)
    step("② 「닫기」 → 창이 닫히고 요청 0 · 행 그대로",
         not dialog_open(page) and len(seen["requests"][mark:]) == 0 and file_row(page, FILE_NAME).count() == 1,
         str(seen["requests"][mark:]))

    open_file_confirm(page, FILE_NAME)
    mark = len(seen["requests"])
    page.keyboard.press("Escape")
    page.wait_for_timeout(400)
    step("② Esc → 창이 닫히고 요청 0 · 행 그대로",
         not dialog_open(page) and len(seen["requests"][mark:]) == 0 and file_row(page, FILE_NAME).count() == 1,
         str(seen["requests"][mark:]))

    open_file_confirm(page, FILE_NAME)
    mark = len(seen["requests"])
    page.locator('#confirm-dialog .modal__head button[aria-label="닫기"]').click()
    page.wait_for_timeout(400)
    step("② ✕ → 창이 닫히고 요청 0 · 행 그대로",
         not dialog_open(page) and len(seen["requests"][mark:]) == 0 and file_row(page, FILE_NAME).count() == 1,
         str(seen["requests"][mark:]))

    # ─── 다크 · 390px — ⑥ (지우기 전에, 같은 창을 열어 보고 닫기만 한다) ─────────
    print("── ⑥ 다크 1440 · 390px 라이트/다크 — 창 단추가 잘리지 않는다")
    for theme, width, height in (("dark", 1440, 900), ("light", 390, 844), ("dark", 390, 844)):
        s2 = fresh_seen()
        c2 = new_context(browser, theme, width, height)
        p2 = c2.new_page()
        watch(p2, s2)
        p2.goto(TBOX + "/", wait_until="networkidle")
        p2.wait_for_timeout(400)
        if width < 960:
            # 좁은 화면 — 표가 가로로 밀리므로 행의 휴지통 단추를 화면 안으로 끌어온 뒤 누른다
            file_row(p2, FILE_NAME).locator('button[aria-label="삭제"]').scroll_into_view_if_needed()
        mark2 = len(s2["requests"])
        open_file_confirm(p2, FILE_NAME)
        g = modal_geometry(p2)
        inside = all(b["left"] >= g["left"] - 0.5 and b["right"] <= g["right"] + 0.5 and b["right"] <= g["vw"] + 0.5
                     and b["bottom"] <= g["bottom"] + 0.5 and b["bottom"] <= g["vh"] + 0.5 and b["w"] > 0 for b in g["btns"])
        step("⑥ %s %dpx — 창이 뜨고 요청 0" % (theme, width), dialog_open(p2) and len(s2["requests"][mark2:]) == 0)
        step("⑥ %s %dpx — 바닥 단추 둘이 창·화면 안에 다 보인다" % (theme, width), inside and len(g["btns"]) == 2,
             "창 %.0f~%.0f / 화면 %d · 단추 %s" % (g["left"], g["right"], g["vw"],
                                               ", ".join("%s %.0f~%.0f" % (b["text"], b["left"], b["right"]) for b in g["btns"])))
        step("⑥ %s %dpx — 창 안 가로 넘침 0" % (theme, width), g["dlgScrollW"] <= g["dlgClientW"] + 1,
             "scrollWidth %d / clientWidth %d" % (g["dlgScrollW"], g["dlgClientW"]))
        p2.screenshot(path=os.path.join(SHOTS, "qf4a-u2a-confirm-%s-%d.png" % (theme, width)))
        p2.keyboard.press("Escape")
        p2.wait_for_timeout(300)
        step("⑥ %s %dpx — Esc 로 닫힘 · 요청 0" % (theme, width), not dialog_open(p2) and len(s2["requests"][mark2:]) == 0)
        step("⑥ %s %dpx — pageerror·console.error·HTTP≥400 0" % (theme, width),
             not s2["pageerrors"] and not s2["console"] and not s2["http_bad"],
             "%s %s %s" % (s2["pageerrors"][:2], s2["console"][:2], s2["http_bad"][:2]))
        c2.close()

    # ─── ③ 확인 → DELETE 1건 · 성공 알림 · 행 사라짐 ─────────────────
    print("── ③ 「휴지통으로 옮기기」 → DELETE 1건 · 성공 알림 · 행이 사라진다")
    open_file_confirm(page, FILE_NAME)
    mark = len(seen["requests"])
    with page.expect_response(lambda r: r.request.method == "DELETE" and r.url.endswith("/tbox/api/files/" + FILE_ID)) as resp_info:
        page.locator("#confirm-dialog .modal__foot .btn--danger").click()
    resp = resp_info.value
    page.wait_for_load_state("networkidle")
    page.wait_for_timeout(500)
    deletes = [r for r in seen["requests"][mark:] if r[0] == "DELETE"]
    step("③ DELETE 정확히 1건 · 200", len(deletes) == 1 and resp.status == 200, "%s · %d" % (deletes, resp.status))
    ok_visible = page.locator("#files-ok").is_visible()
    ok_text = page.locator("#files-ok").inner_text() if ok_visible else ""
    step("③ 성공 알림(.ok-note)이 보인다", ok_visible and FILE_NAME in ok_text and "휴지통으로 옮겼습니다" in ok_text, ok_text)
    step("③ 성공 알림은 .ok-note 이고 role=status", page.evaluate(
        "() => { const e = document.getElementById('files-ok'); return e.className === 'ok-note' && e.getAttribute('role') === 'status'; }"))
    step("③ 행이 목록에서 사라졌다", file_row(page, FILE_NAME).count() == 0)
    page.screenshot(path=os.path.join(SHOTS, "qf4a-u2a-done-light-1440.png"))

    # ─── ④ 서버 거부 — 이미 지워진 파일(404)은 서버 문구 그대로 ───────────
    print("── ④ 서버 거부(404)는 기존 문구 — 목록을 연 뒤 다른 곳에서 먼저 지워진 파일")
    page.reload(wait_until="networkidle")
    page.wait_for_timeout(400)
    step("거부 흐름용 파일 행이 목록에 있다", file_row(page, GONE_NAME).count() == 1, GONE_NAME)
    api_headers = {"X-CSRF-Token": CSRF, "User-Agent": UA}
    r1 = ctx.request.delete(TBOX + "/api/files/" + GONE_ID, headers=api_headers)
    r2 = ctx.request.delete(TBOX + "/api/files/" + GONE_ID, headers=api_headers)
    try:
        server_text = r2.json().get("error", "")
    except Exception:
        server_text = ""
    step("(준비) 다른 곳에서 먼저 지웠다 200 · 두 번째는 %d" % r2.status, r1.status == 200 and r2.status in (403, 404),
         "첫 %d · 둘째 %d 「%s」" % (r1.status, r2.status, server_text))
    open_file_confirm(page, GONE_NAME)
    mark = len(seen["requests"])
    with page.expect_response(lambda r: r.request.method == "DELETE" and r.url.endswith("/tbox/api/files/" + GONE_ID)) as resp_info:
        page.locator("#confirm-dialog .modal__foot .btn--danger").click()
    resp = resp_info.value
    page.wait_for_timeout(500)
    shown = page.locator("#msg").inner_text().strip()
    step("④ 창을 거친 DELETE 1건이 %d 를 받았다" % resp.status,
         len([r for r in seen["requests"][mark:] if r[0] == "DELETE"]) == 1 and resp.status == r2.status)
    step("④ 화면 문구 = 서버 문구 그대로(#msg)", shown != "" and shown == server_text, "화면 「%s」 · 서버 「%s」" % (shown, server_text))
    step("④ 거부일 때 성공 알림은 보이지 않는다", not page.locator("#files-ok").is_visible())

    # ─── ⑤ 폴더 삭제도 같은 창 ───────────────────────────────────
    print("── ⑤ 폴더 삭제 — window.confirm 대신 같은 확인 창")
    page.goto(TBOX + "/", wait_until="networkidle")
    page.wait_for_timeout(400)
    page.locator("#folder-list .foldertree__item", has_text=FOLDER_NAME).first.click()
    page.wait_for_load_state("networkidle")
    page.wait_for_timeout(400)
    step("시험 폴더로 들어왔다(삭제 단추가 보인다)", page.locator("#folder-delete").is_visible())
    natives_before = len(seen["native_dialogs"])
    mark = len(seen["requests"])
    page.locator("#folder-delete").click()
    page.wait_for_timeout(400)
    step("⑤ 폴더 「삭제」 → 같은 확인 창(#confirm-dialog)", dialog_open(page))
    step("⑤ 브라우저 기본 대화상자(window.confirm) 0", len(seen["native_dialogs"]) == natives_before, str(seen["native_dialogs"]))
    measure("u2a_window_confirm_on_folder_delete", len(seen["native_dialogs"]) - natives_before)
    ftitle = page.locator("#confirm-dialog .modal__title").inner_text()
    fbody = page.locator("#confirm-dialog .modal__body").inner_text()
    step("⑤ 창 문구 — 폴더 이름", "폴더" in ftitle and FOLDER_NAME in fbody, "%s / %s" % (ftitle, fbody.replace("\n", " ")[:100]))
    step("⑤ 창이 떠 있는 동안 요청 0", len(seen["requests"][mark:]) == 0, str(seen["requests"][mark:]))
    page.screenshot(path=os.path.join(SHOTS, "qf4a-u2a-folder-confirm-light-1440.png"))
    page.keyboard.press("Escape")
    page.wait_for_timeout(300)
    step("⑤ Esc → 닫힘 · 요청 0 · 폴더 그대로", not dialog_open(page) and len(seen["requests"][mark:]) == 0
         and page.locator("#folder-delete").is_visible())
    page.locator("#folder-delete").click()
    page.wait_for_timeout(300)
    mark = len(seen["requests"])
    with page.expect_response(lambda r: r.request.method == "DELETE" and "/tbox/api/folders/" + FOLDER_ID in r.url) as resp_info:
        page.locator("#confirm-dialog .modal__foot .btn--danger").click()
    resp = resp_info.value
    page.wait_for_load_state("networkidle")
    page.wait_for_timeout(500)
    fdel = [r for r in seen["requests"][mark:] if r[0] == "DELETE"]
    step("⑤ 「폴더 지우기」 → DELETE /api/folders/{id} 1건 · 200", len(fdel) == 1 and resp.status == 200, "%s · %d" % (fdel, resp.status))
    fok = page.locator("#files-ok").inner_text() if page.locator("#files-ok").is_visible() else ""
    step("⑤ 결과 한 줄 — 폴더를 지웠습니다", FOLDER_NAME in fok and "지웠습니다" in fok, fok)
    step("⑤ 상위(루트)로 돌아왔다 — 시험 폴더가 목록에 없다",
         page.locator("#folder-list .foldertree__item", has_text=FOLDER_NAME).count() == 0)

    print("── 공통")
    step("pageerror 0", not seen["pageerrors"], " | ".join(seen["pageerrors"][:3]))
    step("console.error 0", not seen["console"], " | ".join(seen["console"][:3]))
    step("HTTP ≥ 400 0 (④ 의 의도한 %d건 제외)" % len(seen["expected_bad"]), not seen["http_bad"], " | ".join(seen["http_bad"][:5]))
    step("브라우저 기본 대화상자(confirm/alert) 0", not seen["native_dialogs"], str(seen["native_dialogs"]))
    ctx.close()
    browser.close()

print("SMOKE_FAIL=%d" % fails)
sys.exit(1 if fails else 0)
